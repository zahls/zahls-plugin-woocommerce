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
			// Webhook type "JSON" in the zahls.ch backend: the data is the request body, not form fields
			if (empty($resp['transaction'])) {
				$json = json_decode((string) file_get_contents('php://input'), true);
				if (is_array($json)) {
					$resp = $json;
				}
			}
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

			// Check if subscription to handle accordingly. A tokenization arrives as transaction with status
			// "authorized" (https://docs.payrexx.com/developer/guides/gateway/payment-types/tokenization),
			// renewals charged on it carry a preAuthorizationId.
			$subscriptions = [];
			$pre_auth_id = '';
			$is_authorization = $transaction->getStatus() === Transaction::AUTHORIZED;
			$has_pre_auth = !empty($resp['transaction']['preAuthorizationId']);
			if (($has_pre_auth || $is_authorization) && function_exists('wcs_get_subscriptions_for_order')) {
				$subscriptions = wcs_get_subscriptions_for_order($order_id, array( 'order_type' => 'any' ));

				// $order_id is the subscription id in case of payment method change. In this case $subscriptions will be empty
				if (!$subscriptions) {
					$subscription = wcs_get_subscription($order_id);
					if ($subscription) {
						$subscriptions[$subscription->get_id()] = $subscription;
					} elseif ($has_pre_auth) {
						$this->log('warning', sprintf('Transaction %s: no subscription found for %s', $resp['transaction']['id'], $order_id));
						$this->send_response('Subscription not found, nothing to process');
					}
				}
			}

			// A renewal charged on the token: the renewal order recorded the id of the charge (all subscriptions of
			// one order share the token and the reference, so they cannot tell which subscription was renewed)
			// The transaction id comes from zahls.ch (not from the request), so this also covers refunds of renewals.
			$charged_order = null;
			if (!$is_authorization && function_exists('wcs_get_subscriptions_for_order')) {
				$found = wc_get_orders(['transaction_id' => (string) $transaction->getId(), 'limit' => 1]);
				$charged_order = $found ? reset($found) : null;
				// Only a renewal of a subscription of the referenced order
				if ($charged_order) {
					$renewed = wcs_get_subscriptions_for_order($charged_order, ['order_type' => 'renewal']);
					$referenced = $subscriptions;
					if (!$referenced) {
						$referencedSubscription = function_exists('wcs_is_subscription') && wcs_is_subscription($order_id) ? wcs_get_subscription($order_id) : null;
						$referenced = $referencedSubscription ? [$referencedSubscription->get_id() => $referencedSubscription] : wcs_get_subscriptions_for_order($order_id, ['order_type' => 'any']);
					}
					if (!$renewed || !array_intersect(array_keys($renewed), array_keys($referenced))) {
						$charged_order = null;
					}
				}
			}

			$payment_method_change = null;
			if ($charged_order) {
				// A renewal charged on the token
				$order_id = $charged_order->get_id();
				$subscriptions = wcs_get_subscriptions_for_order($charged_order, ['order_type' => 'any']);
			} elseif ($subscriptions && function_exists('wcs_is_subscription') && wcs_is_subscription($order_id)) {
				// A payment method change: the reference is the subscription itself and only its new token matters
				if (!$is_authorization) {
					$this->send_response('Payment method change: nothing to process for status ' . $transaction->getStatus());
				}
				$payment_method_change = reset($subscriptions);
			}
			// Otherwise the order is the one the transaction was created for (its reference): the first order, or a
			// renewal the customer paid at the checkout. Never another order of the subscription, so that a webhook
			// sent again (or forged) for an earlier payment cannot mark a later, unpaid renewal as paid.

			// Renewals are charged on "the ID of the transaction with status authorized" (Payrexx docs above).
			// Taken from the transaction fetched from zahls.ch, not from the unauthenticated request.
			if ($is_authorization && $subscriptions) {
				$pre_auth_id = (string) $transaction->getId();
			}

			// The new payment method is only set once zahls.ch has the new token (see woocommerce_subscriptions_update_payment_via_pay_shortcode)
			if ($payment_method_change) {
				$payment = $transaction->getPayment();
				$payment_method_change->update_meta_data('zahls_auth_transaction_id', $pre_auth_id);
				$payment_method_change->update_meta_data('zahls_payment_method', is_array($payment) && isset($payment['brand']) ? $payment['brand'] : '');
				$payment_method_change->save();
				// The renewal mode WooCommerce Subscriptions chose when the customer submitted the change, kept back
				// until zahls.ch has the token (see process_payment); without one, the current mode stays
				$pending_manual = (string) $payment_method_change->get_meta('_zahls_change_requires_manual');
				$manual = $pending_manual === '' ? (bool) $payment_method_change->get_requires_manual_renewal() : $pending_manual === 'yes';
				if ($payment_method_change->get_payment_method() !== 'zahls' && class_exists('WC_Subscriptions_Change_Payment_Gateway')) {
					\WC_Subscriptions_Change_Payment_Gateway::update_payment_method($payment_method_change, 'zahls');
				}
				$payment_method_change->set_requires_manual_renewal($manual);
				$payment_method_change->delete_meta_data('_zahls_change_requires_manual');
				$payment_method_change->save();
				$this->send_response('Success: Payment method updated');
			}

			$order = $order_id ? wc_get_order($order_id) : false;

			if (!$order) {
				$this->log('warning', sprintf('Transaction %s: order %s not found', $resp['transaction']['id'], $order_id));
				$this->send_response('Order not found, nothing to process');
			}

			$newTransactionStatus = $transaction->getStatus();
			if ($charged_order) {
				// A charge zahls.ch accepted and failed later: the renewal failed as if the charge had been declined
				// right away (WooCommerce Subscriptions puts the subscription on hold and retries; an early renewal
				// leaves it active), and a retry charges again
				if (in_array($newTransactionStatus, [Transaction::DECLINED, Transaction::ERROR, Transaction::CANCELLED, Transaction::EXPIRED], true)) {
					if (!$order->is_paid() && !$order->has_status(['failed', 'cancelled'])) {
						$early = function_exists('wcs_order_contains_early_renewal') && wcs_order_contains_early_renewal($order);
						$order->set_transaction_id('');
						$order->update_status($early ? 'cancelled' : 'failed', sprintf(__('zahls.ch did not complete the charge %s (%s).', 'wc-zahls-gateway'), $transaction->getId(), $newTransactionStatus));
					}
					$this->send_response('Success: Renewal charge failed');
				}
				// A charge on the token is its own payment: its gateway (the first order's) holds all charges
				if ($newTransactionStatus === Transaction::CONFIRMED) {
					$newTransactionStatus = StatusUtil::determineNewOrderStatus($order->get_total('edit'), (int) $transaction->getAmount(), 0);
				}
			} elseif (in_array($newTransactionStatus, [Transaction::CONFIRMED, Transaction::REFUNDED, Transaction::PARTIALLY_REFUNDED])) {
				// A confirmed transaction can also be a partial payment (with bank transfer). Therefore the new correct status must be determined by
				if (!empty($gateway_id)) {
					$gateway = $this->zahls_api_service->getZahlsGateway($gateway_id);
					$confirmedAmount = StatusUtil::getAmountByStatusAndGateway($gateway, [Transaction::CONFIRMED]);
					$refundedAmount = StatusUtil::getAmountByStatusAndGateway($gateway, [Transaction::PARTIALLY_REFUNDED, Transaction::REFUNDED]);
					$newTransactionStatus = StatusUtil::determineNewOrderStatus($order->get_total('edit'), $confirmedAmount, $refundedAmount);
				} elseif ($newTransactionStatus === Transaction::CONFIRMED) {
					$newTransactionStatus = StatusUtil::determineNewOrderStatus($order->get_total('edit'), (int) $transaction->getAmount(), 0);
				}
			}

			$this->order_service->handleTransactionStatus($order, $subscriptions, $transaction, $newTransactionStatus, $pre_auth_id);
			$this->send_response( 'Success: Processed webhook response' );

		} catch (\Throwable $e) {
			// Logged under WooCommerce > Status > Logs (source "zahls") instead of ending as an uncaught fatal error
			$this->log('error', 'Webhook failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $this->short_trace($e));
			$this->send_response('Error: ' . $e->getMessage(), [], 500);
		}
	}

	/**
	 * Innermost and outermost frames of an error, enough to see a recursion and where it started.
	 *
	 * @param \Throwable $e error.
	 * @return string
	 */
	private function short_trace( $e ) {
		$frames = $e->getTrace();
		$count  = count( $frames );
		$lines  = [];
		foreach ( $frames as $i => $frame ) {
			if ( $i >= 15 && $i < $count - 25 ) {
				if ( $i === 15 ) {
					$lines[] = sprintf( '... %d frames ...', $count - 40 );
				}
				continue;
			}
			$lines[] = sprintf(
				'#%d %s%s%s() %s:%s',
				$i,
				isset( $frame['class'] ) ? $frame['class'] : '',
				isset( $frame['type'] ) ? $frame['type'] : '',
				isset( $frame['function'] ) ? $frame['function'] : '',
				isset( $frame['file'] ) ? $frame['file'] : '',
				isset( $frame['line'] ) ? $frame['line'] : ''
			);
		}
		return implode( "\n", $lines );
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
		/**
		 * Fires before the webhook answers zahls.ch, e.g. for logging in test tools.
		 *
		 * @param string $message       response message.
		 * @param int    $response_code HTTP status code.
		 * @param array  $request       webhook request data.
		 */
		do_action( 'zahls_webhook_response', $message, (int) $response_code, $_REQUEST );

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