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