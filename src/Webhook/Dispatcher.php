<?php

namespace ZahlsPaymentGateway\Webhook;

use Zahls\Models\Response\Transaction;
use ZahlsPaymentGateway\Service\OrderService;
use ZahlsPaymentGateway\Service\ZahlsApiService;
use ZahlsPaymentGateway\Util\StatusUtil;

class Dispatcher
{
	/**
	 * @var ZahlsApiService
	 */
	private $zahls_api_service;

	/**
	 * @var OrderService
	 */
	private $order_service;

	/**
	 * Settings prefix
	 *
	 * @var string
	 */
	private $prefix;

	public function __construct($zahls_api_service, $order_service, $prefix)
	{
		$this->zahls_api_service = $zahls_api_service;
		$this->order_service = $order_service;
		$this->prefix = $prefix;
	}

	public function check_webhook_response()
	{
		try {
			$resp = $_REQUEST;
			$reference = isset($resp['transaction']['invoice']['referenceId']) ? (string) $resp['transaction']['invoice']['referenceId'] : '';
			$order_id = $reference;
			$gateway_id = isset($resp['transaction']['invoice']['paymentRequestId']) ? $resp['transaction']['invoice']['paymentRequestId'] : '';

			if (empty($resp['transaction']['id']) || !isset($resp['transaction']['status'])) {
				$this->send_response('Missing transaction id or status');
			}

			if (!empty($this->prefix) && strpos($order_id, $this->prefix) === false) {
				$this->send_response('Prefix mismatch');
			}

			$arr = explode('_', $order_id);
			$order_id = end($arr);

			/**
			 * @var \Zahls\Models\Response\Transaction
			 */
			$transaction = $this->zahls_api_service->getZahlsTransaction((int) $resp['transaction']['id']);

			if (empty($transaction)) {
				$this->log('warning', sprintf('Transaction %s not found at zahls.ch', $resp['transaction']['id']));
				$this->send_response('Error: No such transaction found');
			}

			// zahls.ch sends a webhook for every status change of a transaction (e.g. waiting, authorized, confirmed).
			// When an earlier one is processed after the transaction has moved on, its status no longer matches.
			// It is answered with 200, the webhook for the current status updates the order.
			if ($transaction->getStatus() !== $resp['transaction']['status']) {
				$this->log('info', sprintf(
					'Transaction %s: webhook status %s is outdated, current status is %s',
					$resp['transaction']['id'],
					$resp['transaction']['status'],
					$transaction->getStatus()
				));
				$this->send_response('Outdated transaction status, nothing to process');
			}

			// The webhook is unauthenticated. The order it updates must be the one the transaction was created for,
			// as stored at zahls.ch, so a real transaction cannot be applied to another order.
			$transaction_reference = $this->get_transaction_reference($transaction);
			if ($transaction_reference !== '' && $transaction_reference !== $reference) {
				$this->log('error', sprintf(
					'Transaction %s: webhook reference %s does not match the transaction reference %s',
					$resp['transaction']['id'],
					$reference,
					$transaction_reference
				));
				$this->send_response('Error: Reference mismatch', [], 400);
			}

			// Check if subscription to handle accordingly
			$subscriptions = [];
			$pre_auth_id = '';
			if (!empty($resp['transaction']['preAuthorizationId']) && function_exists('wcs_get_subscriptions_for_order')) {
				$subscriptions = wcs_get_subscriptions_for_order($order_id, array( 'order_type' => 'any' ));

				// $order_id is the subscription id in case of payment method change. In this case $subscriptions will be empty
				if (!$subscriptions) {
					$subscription = wcs_get_subscription($order_id);
					if (!$subscription) {
						$this->log('warning', sprintf('Transaction %s: no subscription found for %s', $resp['transaction']['id'], $order_id));
						$this->send_response('Subscription not found, nothing to process');
					}
					$subscriptions[] = $subscription;
				}

				// Identify the correct order_id
				// Automatic subscription payment > order_id is already valid and matches the referenceId. It must not be overwritten
				// Payment method change > $order_id is a subscriptionId and must be overwritten
				// Subscription renewal > $order_id is from an old order and must be overwritten
				$firstSubscription = reset($subscriptions);
				$order_id = $firstSubscription->get_last_order();

				// Stored as the token that renewals are charged on. The webhook is unauthenticated, so the
				// pre-authorization is fetched from zahls.ch and must belong to the same order as this transaction.
				$pre_auth_id = $this->verified_pre_auth_id($resp['transaction']['preAuthorizationId'], $transaction);
			}

			$order = $order_id ? wc_get_order($order_id) : false;

			if (!$order) {
				$this->log('warning', sprintf('Transaction %s: order %s not found', $resp['transaction']['id'], $order_id));
				$this->send_response('Order not found, nothing to process');
			}

			$newTransactionStatus = $transaction->getStatus();
			// A confirmed transaction can also be a partial payment (with bank transfer). Therefore the new correct status must be determined by
			if (in_array($newTransactionStatus, [Transaction::CONFIRMED, Transaction::REFUNDED, Transaction::PARTIALLY_REFUNDED])) {
				if (!empty($gateway_id)) {
					$gateway = $this->zahls_api_service->getZahlsGateway($gateway_id);
					$confirmedAmount = StatusUtil::getAmountByStatusAndGateway($gateway, [Transaction::CONFIRMED]);
					$refundedAmount = StatusUtil::getAmountByStatusAndGateway($gateway, [Transaction::PARTIALLY_REFUNDED, Transaction::REFUNDED]);
					$newTransactionStatus = StatusUtil::determineNewOrderStatus($order->get_total('edit'), $confirmedAmount, $refundedAmount);
				} elseif ($newTransactionStatus === Transaction::CONFIRMED) {
					// A renewal charged on the tokenization has no gateway, its own amount (in cents) is the payment
					$newTransactionStatus = StatusUtil::determineNewOrderStatus($order->get_total('edit'), (int) $transaction->getAmount(), 0);
				}
			}

			$this->order_service->handleTransactionStatus($order, $subscriptions, $transaction, $newTransactionStatus, $pre_auth_id);
			$this->send_response( 'Success: Processed webhook response' );

		} catch (\Throwable $e) {
			// Logged under WooCommerce > Status > Logs (source "zahls") instead of ending as an uncaught fatal error
			$this->log('error', 'Webhook failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
			$this->send_response('Error: ' . $e->getMessage(), [], 500);
		}
	}

	/**
	 * Returns the pre-authorization id if zahls.ch knows it and it belongs to the same reference as the
	 * transaction of the webhook, otherwise an empty string (nothing is stored as token).
	 *
	 * @param mixed                                $pre_auth_id preAuthorizationId from the webhook request.
	 * @param \Zahls\Models\Response\Transaction $transaction fetched transaction of the webhook.
	 * @return string
	 */
	private function verified_pre_auth_id( $pre_auth_id, $transaction ) {
		$pre_auth_id = (int) $pre_auth_id;
		if ( $pre_auth_id <= 0 ) {
			return '';
		}

		$pre_auth = ( $pre_auth_id === (int) $transaction->getId() )
			? $transaction
			: $this->zahls_api_service->getZahlsTransaction( $pre_auth_id );

		if ( empty( $pre_auth ) ) {
			$this->log( 'error', sprintf( 'Transaction %s: pre-authorization %d not found at zahls.ch, token not stored', $transaction->getId(), $pre_auth_id ) );
			return '';
		}

		$reference = $this->get_transaction_reference( $transaction );
		$pre_auth_reference = $this->get_transaction_reference( $pre_auth );
		if ( $reference !== '' && $pre_auth_reference !== '' && $reference !== $pre_auth_reference ) {
			$this->log( 'error', sprintf(
				'Transaction %s: pre-authorization %d belongs to reference %s instead of %s, token not stored',
				$transaction->getId(),
				$pre_auth_id,
				$pre_auth_reference,
				$reference
			) );
			return '';
		}

		return (string) $pre_auth_id;
	}

	/**
	 * Reference id of the transaction as stored at zahls.ch, empty if the API does not return one.
	 *
	 * @param \Zahls\Models\Response\Transaction $transaction fetched transaction.
	 * @return string
	 */
	private function get_transaction_reference( $transaction ) {
		// Same field as in the webhook request, the top level reference only as fallback
		$invoice = $transaction->getInvoice();
		$reference = is_array( $invoice ) && isset( $invoice['referenceId'] ) ? (string) $invoice['referenceId'] : '';
		if ( $reference === '' ) {
			$reference = (string) $transaction->getReferenceId();
		}
		return $reference;
	}

	/**
	 * Writes to the WooCommerce log (source "zahls").
	 *
	 * @param string $level   log level.
	 * @param string $message log message.
	 */
	private function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, [ 'source' => 'zahls' ] );
		}
	}

	/**
	 * Returns webhook response.
	 *
	 * @param string     $message       success or error message.
	 * @param array      $data          response data.
	 * @param string|int $response_code response code.
	 */
	private function send_response( $message, $data = [], $response_code = 200 ) {
		$response['message'] = $message;
		if ( ! empty( $data ) ) {
			$response['data'] = $data;
		}
		status_header( $response_code );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $response );
		die;
	}
}