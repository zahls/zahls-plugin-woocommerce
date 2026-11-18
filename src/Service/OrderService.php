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
                // Refunded in WooCommerce ("Refund via zahls.ch"): WooCommerce records it and sets "refunded" itself,
                // only a custom status for refunded orders is still to be set
                if ((float) $order->get_total_refunded() >= (float) $order->get_total() - 0.001) {
                    if ($newTransactionsStatus !== 'refunded' && $order->has_status('refunded')) {
                        $order->update_status($newTransactionsStatus);
                    }
                    break;
                }
                $order->update_status($newTransactionsStatus, __('Payment was fully refunded', 'wc-zahls-gateway'));
                break;
            case \Zahls\Models\Response\Transaction::PARTIALLY_REFUNDED:
                // WooCommerce has no status for it; a refund made in the zahls.ch backend gets a note, so that the
                // shop sees it (one made in WooCommerce is recorded there already)
                $refunded = abs((int) $zahlsTransaction->getAmount()) / 100;
                foreach ($order->get_refunds() as $refund) {
                    if (abs((float) $refund->get_amount() - $refunded) < 0.005 && $refund->get_date_created() && $refund->get_date_created()->getTimestamp() > time() - 3600) {
                        break 2;
                    }
                }
                $order->add_order_note(sprintf(
                    __('zahls.ch reports a partial refund of %1$s (transaction %2$s). If it was not made in WooCommerce, record it there with "Refund manually".', 'zahls-ch-payment-gateway'),
                    wc_price($refunded, ['currency' => $order->get_currency()]),
                    $zahlsTransaction->getId()
                ));
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
        // This payment was applied before and the order since changed by the shop (e.g. cancelled, refunded or a
        // custom status): a webhook sent again must not reopen it, send its emails again or reduce the stock again
        if ($order->get_date_paid() && $order->get_transaction_id() === (string) $transactionId) {
            $order->add_order_note(sprintf(
                __('zahls.ch reported a confirmed payment (%1$s) again. The order was paid before, its status stays "%2$s".', 'zahls-ch-payment-gateway'),
                $transactionId,
                wc_get_order_status_name($order->get_status())
            ));
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