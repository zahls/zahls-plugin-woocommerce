<?php

namespace ZahlsPaymentGateway\Service;

class SubscriptionService
{
    /**
     * @var ZahlsApiService
     */
    protected $zahlsApiService;

    public function __construct($zahlsApiService)
    {
        $this->zahlsApiService = $zahlsApiService;
    }

    /**
     * @param float $amount
     * @param \WC_Order $renewal
     */
    public function process_recurring_payment($amount, $renewal)
    {
        // Never charge a renewal twice (e.g. a scheduled action that runs again): it is paid or has a charge already
        if ($renewal->is_paid() || $renewal->get_transaction_id() !== '') {
            wc_get_logger()->warning(
                sprintf('Renewal order %d not charged again: it is already paid or charged (%s)', $renewal->get_id(), $renewal->get_transaction_id()),
                ['source' => 'zahls']
            );
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order($renewal, array('order_type' => 'any'));

        foreach ($subscriptions as $subscription) {
            // Get tokenization from the subscription (new way). get_meta() works with and without HPOS.
            $tokenizationId = intval($subscription->get_meta('zahls_auth_transaction_id', true));

            if (!$tokenizationId) {
                // Get tokenization id from the last order except this renewal (old way)
                $last_order_id = 0;
                foreach ($subscription->get_related_orders('ids') as $order_id) {
                    if ((int) $order_id === (int) $renewal->get_id()) continue;
                    $last_order_id = $order_id;
                    break;
                }

                $last_order = $last_order_id ? wc_get_order($last_order_id) : false;
                $tokenizationId = $last_order ? intval($last_order->get_meta('zahls_auth_transaction_id', true)) : 0;

                if ($tokenizationId) {
                    $subscription->update_meta_data('zahls_auth_transaction_id', $tokenizationId);
                    $subscription->save();
                }
            }

            if (!$tokenizationId) {
                wc_get_logger()->error(
                    sprintf('Renewal of subscription %d failed: no zahls.ch tokenization found', $subscription->get_id()),
                    ['source' => 'zahls']
                );
                do_action('zahls_renewal_charge', $subscription, 0, $amount, false);
                $this->renewalFailed($subscription, $renewal);
                continue;
            }

            // Both must be given to do a valid recurring transaction
            $charged = $this->zahlsApiService->chargeTransaction($tokenizationId, $amount);

            /**
             * Fires after a renewal was charged at zahls.ch, e.g. for logging in test tools.
             *
             * @param \WC_Subscription $subscription   subscription.
             * @param int              $tokenizationId token the renewal was charged on.
             * @param float            $amount         renewal amount.
             * @param bool             $charged        whether zahls.ch accepted the charge.
             */
            do_action('zahls_renewal_charge', $subscription, $tokenizationId, $amount, (bool) $charged);

            if ($charged) {
                $status = $charged->getStatus();
                $failed = in_array($status, [\Zahls\Models\Response\Transaction::DECLINED, \Zahls\Models\Response\Transaction::ERROR, \Zahls\Models\Response\Transaction::CANCELLED, \Zahls\Models\Response\Transaction::EXPIRED], true);
                if (!$failed) {
                    // The webhook finds this renewal by the transaction id (all subscriptions of an order share the
                    // token). Not for a failed charge, so that retries of the renewal still charge.
                    $renewal->set_transaction_id((string) $charged->getId());
                    $renewal->save();
                }
                if ($status === \Zahls\Models\Response\Transaction::CONFIRMED) {
                    if (!$renewal->is_paid()) {
                        $renewal->update_meta_data('zahls_payment_method', $subscription->get_meta('zahls_payment_method'));
                        $renewal->payment_complete((string) $charged->getId());
                    }
                    continue;
                }
                if ($failed) {
                    wc_get_logger()->error(
                        sprintf('Renewal of subscription %d: zahls.ch answered the charge %s with status %s', $subscription->get_id(), $charged->getId(), $status),
                        ['source' => 'zahls']
                    );
                    $this->renewalFailed($subscription, $renewal);
                    continue;
                }
                // Any other status (e.g. waiting): the webhook of this transaction completes the renewal. On hold, not
                // pending: WooCommerce Subscriptions deletes an early renewal ("Renew now") that still needs payment.
                wc_get_logger()->info(
                    sprintf('Renewal order %d: charge %s is %s, waiting for zahls.ch', $renewal->get_id(), $charged->getId(), $status),
                    ['source' => 'zahls']
                );
                $renewal->update_status('on-hold', sprintf(__('zahls.ch has not completed the charge %s yet (%s).', 'wc-zahls-gateway'), $charged->getId(), $status));
                continue;
            }

            // Recurring payment failed if we reach this point
            wc_get_logger()->error(
                sprintf('Renewal of subscription %d: zahls.ch rejected the charge on tokenization %d', $subscription->get_id(), $tokenizationId),
                ['source' => 'zahls']
            );
            $this->renewalFailed($subscription, $renewal);
        }
    }

    /**
     * A failed renewal puts the subscription on hold, except an early renewal ("Renew now"): WooCommerce
     * Subscriptions then deletes the unpaid renewal order and asks the customer to pay at the checkout.
     */
    private function renewalFailed($subscription, $renewal)
    {
        if (function_exists('wcs_order_contains_early_renewal') && wcs_order_contains_early_renewal($renewal)) {
            return;
        }
        $subscription->payment_failed();
    }
}