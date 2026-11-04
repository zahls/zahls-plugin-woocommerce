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
                $subscription->payment_failed();
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
            do_action('zahls_renewal_charge', $subscription, $tokenizationId, $amount, $charged);

            if ($charged) {
                continue;
            }

            // Recurring payment failed if we reach this point
            wc_get_logger()->error(
                sprintf('Renewal of subscription %d: zahls.ch rejected the charge on tokenization %d', $subscription->get_id(), $tokenizationId),
                ['source' => 'zahls']
            );
            $subscription->payment_failed();
        }
    }
}