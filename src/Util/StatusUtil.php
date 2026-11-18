<?php

namespace ZahlsPaymentGateway\Util;

use Zahls\Models\Response\Gateway;
use Zahls\Models\Response\Transaction;

class StatusUtil
{

    /**
     * @param Gateway $gateway
     * @param array $gateway
     * @return int
     */
    public static function getAmountByStatusAndGateway(Gateway $gateway, array $status): int
    {
        $amount = 0;
        foreach ($gateway->getInvoices() as $invoice) {
            foreach($invoice['transactions'] as $transaction) {
                if (!in_array($transaction['status'], $status)) continue;
                $amount += $transaction['amount'];
            }
        }
        return $amount;
    }

    /**
     * Whether the transaction is one of the gateway's (payments and refunds are listed in its invoices).
     */
    public static function gatewayHasTransaction(Gateway $gateway, int $transactionId): bool
    {
        foreach ((array) $gateway->getInvoices() as $invoice) {
            foreach ((array) ($invoice['transactions'] ?? []) as $transaction) {
                if ((int) ($transaction['id'] ?? 0) === $transactionId) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Confirmed and refunded amounts of the invoice that holds the transaction. A gateway of a subscription's first
     * order also lists every renewal charged on its token, each as an invoice of its own.
     *
     * @return int[] [confirmed, refunded]
     */
    public static function getAmountsOfInvoice(Gateway $gateway, int $transactionId): array
    {
        foreach ((array) $gateway->getInvoices() as $invoice) {
            $transactions = (array) ($invoice['transactions'] ?? []);
            if (!in_array($transactionId, array_map(function ($t) { return (int) ($t['id'] ?? 0); }, $transactions), true)) {
                continue;
            }
            $confirmed = 0;
            $refunded = 0;
            foreach ($transactions as $transaction) {
                if (($transaction['status'] ?? '') === Transaction::CONFIRMED) {
                    $confirmed += (int) $transaction['amount'];
                } elseif (in_array($transaction['status'] ?? '', [Transaction::REFUNDED, Transaction::PARTIALLY_REFUNDED], true)) {
                    $refunded += (int) $transaction['amount'];
                }
            }
            return [$confirmed, $refunded];
        }
        return [
            self::getAmountByStatusAndGateway($gateway, [Transaction::CONFIRMED]),
            self::getAmountByStatusAndGateway($gateway, [Transaction::PARTIALLY_REFUNDED, Transaction::REFUNDED]),
        ];
    }

    /**
     * The payment a refund transaction refunds: the confirmed payment in the refund's invoice.
     */
    public static function getRefundedPaymentId(Gateway $gateway, int $refundId): int
    {
        foreach ((array) $gateway->getInvoices() as $invoice) {
            $transactions = (array) ($invoice['transactions'] ?? []);
            if (!in_array($refundId, array_map(function ($t) { return (int) ($t['id'] ?? 0); }, $transactions), true)) {
                continue;
            }
            foreach ($transactions as $transaction) {
                if (($transaction['status'] ?? '') === Transaction::CONFIRMED && (int) ($transaction['amount'] ?? 0) > 0) {
                    return (int) $transaction['id'];
                }
            }
        }
        return 0;
    }

    /**
     * @param int $orderTotal
     * @param int $confirmedAmount
     * @param int $refundedAmount
     * @return string
     */
    public static function determineNewOrderStatus($orderTotal_input, $confirmedAmount, $refundedAmount)
    {
      	$orderTotal = $orderTotal_input * 100;
        $paidAmount = $confirmedAmount + $refundedAmount;

        if (strval($paidAmount) == strval($orderTotal)){ return Transaction::CONFIRMED; }
        if ($confirmedAmount == abs($refundedAmount) && $confirmedAmount > 0){ return Transaction::REFUNDED; }
        if ($confirmedAmount > abs($refundedAmount) && abs($refundedAmount) > 0) { return Transaction::PARTIALLY_REFUNDED; }
        if ($confirmedAmount < $orderTotal){ return Transaction::WAITING; } // Partially paid
    }
}