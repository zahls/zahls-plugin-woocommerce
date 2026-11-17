<?php

namespace ZahlsPaymentGateway\Service;

use Zahls\Models\Response\Transaction;

class OrderService
{
    /**
     * @param Transaction $zahlsTransaction
     */
    public function handleTransactionStatus($order, $subscriptions, $zahlsTransaction, $newTransactionStatus, $preAuthId) {
        switch ($newTransactionStatus) {
            case \Zahls\Models\Response\Transaction::WAITING:
                $newTransactionsStatus = 'on-hold';
                $newTransactionsStatus = $this->getCustomTransactionStatus($newTransactionsStatus);

                if ($order->get_status() === $newTransactionsStatus || $order->is_paid()) {
                    break;
                }

                $order->update_status($newTransactionsStatus, __('Awaiting payment', 'wc-zahls-gateway'));
                break;
            case \Zahls\Models\Response\Transaction::CONFIRMED:
                $this->setOrderPaid($order, $zahlsTransaction->getPayment()['brand'], (string) $zahlsTransaction->getId());
                break;
            case \Zahls\Models\Response\Transaction::AUTHORIZED:
                foreach ($subscriptions as $subscription) {
                    // An unverified pre-authorization arrives empty and must not overwrite a stored token
                    if (empty($preAuthId)) {
                        continue;
                    }
                    $subscription->update_meta_data('zahls_auth_transaction_id', $preAuthId);
                    $subscription->update_meta_data('zahls_payment_method', $zahlsTransaction->getPayment()['brand']);
                    $subscription->save();
                }

                // An order with amount 0 is considered as paid if the authorization is successful
				if ((float) $order->get_total('edit') <= 0) {
                    $this->setOrderPaid($order, $zahlsTransaction->getPayment()['brand']);
                }
                break;
            case \Zahls\Models\Response\Transaction::REFUNDED:
				if ($zahlsTransaction->getAmount())
                $newTransactionsStatus = 'refunded';				
                $newTransactionsStatus = $this->getCustomTransactionStatus($newTransactionsStatus);
                if ($order->get_status() === $newTransactionsStatus) {
                    break;
                }
                $order->update_status($newTransactionsStatus, __('Payment was fully refunded', 'wc-zahls-gateway'));
                break;
//                    case \Zahls\Models\Response\Transaction::PARTIALLY_REFUNDED:
//                        if ($order->get_status() === 'refunded') {
//                            break;
//                        }
//                        $order->update_status('refunded', __('Payment was partially refunded', 'wc-zahls-gateway'));
//                        break;
            case \Zahls\Models\Response\Transaction::CANCELLED:
            case \Zahls\Models\Response\Transaction::EXPIRED:
            case \Zahls\Models\Response\Transaction::DECLINED:
                $newTransactionsStatus = 'cancelled';
                $newTransactionsStatus = $this->getCustomTransactionStatus($newTransactionsStatus);

                // Never for a paid order (also refunded or in a custom status): e.g. a token that expires later
                if ($order->get_date_paid() || $order->has_status(['processing', 'completed', $newTransactionsStatus])) {
                    break;
                }

                $order->update_status($newTransactionsStatus, __('Payment was cancelled by the customer', 'wc-zahls-gateway'));
                break;
            case \Zahls\Models\Response\Transaction::ERROR:
                $newTransactionsStatus = 'failed';
                $newTransactionsStatus = $this->getCustomTransactionStatus($newTransactionsStatus);
                if ($order->get_date_paid() || $order->has_status(['processing', 'completed', $newTransactionsStatus])) {
                    break;
                }
                $order->update_status($newTransactionsStatus, __('An error occured while processing this payment', 'wc-zahls-gateway'));
                break;
        }
    }

    private function setOrderPaid($order, $paymentBrand, $transactionId = '') {
        if ($order->has_status(['processing', 'completed'])) {
            return;
        }
        $order->update_meta_data('zahls_payment_method', $paymentBrand);
        $order->payment_complete($transactionId);
        $order->save();
        $this->warnAboutSubscriptionsWithoutToken($order);
        // Remove cart (there is none when called from the webhook)
        if (WC()->cart) {
            WC()->cart->empty_cart();
        }
    }

    /**
     * An automatic subscription without zahls.ch token cannot be renewed: say so when its first order is paid.
     */
    private function warnAboutSubscriptionsWithoutToken($order) {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return;
        }
        foreach (wcs_get_subscriptions_for_order($order, ['order_type' => 'parent']) as $subscription) {
            if ($subscription->is_manual() || $subscription->get_payment_method() !== 'zahls' || $subscription->get_meta('zahls_auth_transaction_id')) {
                continue;
            }
            $message = sprintf('Subscription %d renews automatically with zahls.ch but has no zahls.ch token: the payment of order %d was not a tokenization, renewals will fail.', $subscription->get_id(), $order->get_id());
            wc_get_logger()->warning($message, ['source' => 'zahls']);
            $subscription->add_order_note($message);
        }
    }

    private function getCustomTransactionStatus($status) {
        return apply_filters('woo_zahls_custom_transaction_status_' . $status, $status);
    }
}