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
                $subscription->payment_failed();
                continue;
            }

            // Both must be given to do a valid recurring transaction
            if ($this->zahlsApiService->chargeTransaction($tokenizationId, $amount)) {
                continue;
            }

            // Recurring payment failed if we reach this point
            $subscription->payment_failed();
        }
    }
}