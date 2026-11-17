<?php

namespace ZahlsPaymentGateway\Util;

class CartUtil
{
    const ORDER_ONE_TIME = 'one-time';
    const ORDER_SUBSCRIPTION_AUTO = 'subscription-auto';
    const ORDER_SUBSCRIPTION_MANUAL = 'subscription-manual';
    const ORDER_SUBSCRIPTION_METHOD_CHANGE = 'subscription-method-change';

    /**
     * @param \WC_Cart|null $cart
     * @param mixed          $changePaymentMethod  subscription id of a payment method change
     * @param mixed          $allowRecurring       the customer's choice for automatic renewals
     * @param bool           $orderHasSubscription whether WooCommerce Subscriptions created subscriptions for the order
     */
    public static function getOrderType($cart, $changePaymentMethod, $allowRecurring, $orderHasSubscription = false) {
        if (self::isAutomaticSubscription($cart, $changePaymentMethod, $allowRecurring)) return self::ORDER_SUBSCRIPTION_AUTO;
        if (self::isManualSubscription($cart, $changePaymentMethod, $allowRecurring)) return self::ORDER_SUBSCRIPTION_MANUAL;
        if (self::isPaymentMethodChange($changePaymentMethod)) return self::ORDER_SUBSCRIPTION_METHOD_CHANGE;

        // A subscription the cart does not show as subscription product (e.g. a subscription option on a normal
        // product, added by another plugin): automatic renewals need the token, so the payment must tokenize
        if ($orderHasSubscription) return self::ORDER_SUBSCRIPTION_AUTO;

        return self::ORDER_ONE_TIME;
    }

    public static function isSubscription($cart, $changePaymentMethod) {
        if (empty($cart->cart_contents)) return false;
        if ($changePaymentMethod) return false;

        // Check if cart contains subscriptions
        foreach ($cart->cart_contents as $cart_item) {
            $type = $cart_item['data']->get_type();
            if ($type !== 'subscription' && $type !== 'subscription_variation') {
                continue;
            }
            return true;
        }

        return false;
    }

    public static function isPaymentMethodChange($changePaymentMethod):bool {
        if (!$changePaymentMethod) return false;
        return true;
    }


    private static function isManualSubscription($cart, $changePaymentMethod, $allowRecurring) {
        if (!self::isSubscription($cart, $changePaymentMethod)) return false;
        if (!empty($allowRecurring)) return false;

        return true;
    }

    private static function isAutomaticSubscription($cart, $changePaymentMethod, $allowRecurring) {
        if (!self::isSubscription($cart, $changePaymentMethod)) return false;
        if (empty($allowRecurring)) return false;

        return true;
    }
}