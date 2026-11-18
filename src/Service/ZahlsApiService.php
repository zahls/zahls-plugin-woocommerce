<?php

namespace ZahlsPaymentGateway\Service;

use Zahls\Communicator;
use Zahls\Models\Response\Gateway;
use Zahls\Models\Response\Transaction;

class ZahlsApiService
{
    private $instance;
    private $apiKey;
    private $platform;
    private $prefix;
    private $lookAndFeelId;

    /**
     * Constructor
     *
     * @param EntityRepository $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct($instance, $apiKey, $platform, $prefix, $lookAndFeelId)
    {
        $this->instance = $instance;
        $this->apiKey = $apiKey;
        $this->platform = $platform;
        $this->prefix = $prefix;
        $this->lookAndFeelId = $lookAndFeelId;
    }

    public function createZahlsGateway($order, $totalAmount, $basket, $purpose, $reference, $successRedirectUrl, $cancelRedirectUrl, $preAuthorization, $chargeOnAuth, $currency = 'USD', $customButtonText = null) {
        $zahls = $this->getInterface();
        $gateway = new \Zahls\Models\Request\Gateway();

        $gateway->setValidity(15);
        $gateway->setPsp([]);
        $gateway->setSkipResultPage(true);
		
		if ($customButtonText) {
            $gateway->setButtonText($customButtonText);
        }

        $gateway->setAmount(round($totalAmount * 100));
        $gateway->setCurrency($currency);
        $gateway->setPreAuthorization($preAuthorization);
        $gateway->setChargeOnAuthorization($chargeOnAuth);
        $gateway->setBasket($basket);
        $gateway->setPurpose($purpose);
        $gateway->setReferenceId($reference);

        $gateway->setLookAndFeelProfile($this->lookAndFeelId ?: null);

        $gateway->setSuccessRedirectUrl($successRedirectUrl);
        $gateway->setCancelRedirectUrl($cancelRedirectUrl);
        $gateway->setFailedRedirectUrl($cancelRedirectUrl);

        $billingAddress = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2();
        $gateway->addField($type = 'title', $value = '');
        $gateway->addField($type = 'forename', $value = $order->get_billing_first_name());
        $gateway->addField($type = 'surname', $value = $order->get_billing_last_name());
        $gateway->addField($type = 'company', $value = $order->get_billing_company());
        $gateway->addField($type = 'street', $value = $billingAddress);
        $gateway->addField($type = 'postcode', $value = $order->get_billing_postcode());
        $gateway->addField($type = 'place', $value = $order->get_billing_city());
        $gateway->addField($type = 'country', $value = $order->get_billing_country());
        $gateway->addField($type = 'phone', $value = $order->get_billing_phone());
        $gateway->addField($type = 'email', $value = $order->get_billing_email());
        $gateway->addField($type = 'custom_field_1', $value = $order->get_id(), $name = 'WooCommerce Order ID');
		$gateway->addField($type = 'custom_field_2', $value = "WooCommerce", $name = 'Integration');

        try {
            $response = $zahls->create($gateway);

            return $response;
        } catch (\Zahls\ZahlsException $e) {
            // Printing the message here would break the JSON response of the checkout
            wc_get_logger()->error(
                sprintf('Creating the zahls.ch gateway for order %d failed: %s', $order->get_id(), $e->getMessage()),
                ['source' => 'zahls']
            );
            return null;
        }
    }

    /**
     * @return \Zahls\Zahls
     */
    public function getInterface(): \Zahls\Zahls
    {
        $this->registerAutoloader();
        $platform = !empty($this->platform) ? $this->platform : \Zahls\Communicator::API_URL_BASE_DOMAIN;
        return new \Zahls\Zahls($this->instance, $this->apiKey, '', $platform);
    }

    public function deleteGatewayById($gatewayId):bool {
        $zahls = $this->getInterface();

        $gateway = new \Zahls\Models\Request\Gateway();
        $gateway->setId($gatewayId);

        try {
            $zahls->delete($gateway);
        } catch (\Zahls\ZahlsException $e) {
            return false;
        }
        return true;
    }

    public function getZahlsTransaction(int $zahlsTransactionId): ?\Zahls\Models\Response\Transaction
    {
        $zahls = $this->getInterface();

        $zahlsTransaction = new \Zahls\Models\Request\Transaction();
        $zahlsTransaction->setId($zahlsTransactionId);

        try {
            $response = $zahls->getOne($zahlsTransaction);
            return $response;
        } catch (\Zahls\ZahlsException $e) {
            // Anything but "not found" (no answer, a server error, a rate limit, credentials refused): unknown, not
            // "no such transaction". The webhook then fails, so that zahls.ch can send it again.
            if (!self::isNotFound($e)) {
                throw new \RuntimeException(sprintf('zahls.ch did not answer for transaction %d: %s (HTTP %d)', $zahlsTransactionId, $e->getMessage(), (int) $e->getCode()));
            }
            return null;
        }
    }

    /**
     * Charges an amount on a tokenization.
     *
     * @return \Zahls\Models\Response\Transaction|false the transaction zahls.ch created, false if rejected
     */
    /**
     * zahls.ch answers an unknown id with an error message (HTTP 200 on a real instance, 404 elsewhere).
     */
    private static function isNotFound(\Zahls\ZahlsException $e) {
        return in_array((int) $e->getCode(), [200, 404], true);
    }

    public function chargeTransaction($transactionId, $amount) {
        $zahls = $this->getInterface();
        $transaction = new \Zahls\Models\Request\Transaction();
        $transaction->setId($transactionId);
        $transaction->setAmount(round(floatval($amount) * 100));
        try {
            $response = $zahls->charge($transaction);
            return $response ?: false;
        } catch (\Zahls\ZahlsException $e) {
            wc_get_logger()->error(
                sprintf('Charge on tokenization %s failed: %s (HTTP %d)', $transactionId, $e->getMessage(), (int) $e->getCode()),
                ['source' => 'zahls']
            );
            // No answer (e.g. timeout) or a server error: the charge may still have been made
            if ((int) $e->getCode() === 0 || (int) $e->getCode() >= 500) {
                throw new \RuntimeException(sprintf('zahls.ch did not answer the charge on tokenization %s (HTTP %d)', $transactionId, (int) $e->getCode()));
            }
        }
        return false;
    }
	

    /**
     * Refunds (part of) a payment at zahls.ch. zahls.ch creates a refund transaction of its own and sends its webhook.
     *
     * @throws \Zahls\ZahlsException when zahls.ch refuses (e.g. amount higher than what is left) or does not answer
     */
    public function refundTransaction($transactionId, $amount) {
        $zahls = $this->getInterface();
        $transaction = new \Zahls\Models\Request\Transaction();
        $transaction->setId((int) $transactionId);
        $transaction->setAmount((int) round(floatval($amount) * 100));
        try {
            return $zahls->refund($transaction);
        } catch (\Zahls\ZahlsException $e) {
            // No answer (e.g. timeout) or a server error: the refund may still have been made
            if ((int) $e->getCode() === 0 || (int) $e->getCode() >= 500) {
                throw new \RuntimeException(sprintf('zahls.ch did not answer (HTTP %d)', (int) $e->getCode()));
            }
            throw $e;
        }
    }

    /**
     * Ids of the payment methods active on the zahls.ch instance (e.g. "visa", "twint", "post-finance-pay").
     *
     * @return string[]
     * @throws \Zahls\ZahlsException
     */
    public function getActivePaymentMethodIds($currency = '') {
        $zahls = $this->getInterface();
        $filter = new \Zahls\Models\Request\PaymentMethod();
        $filter->setFilterPaymentType('one-time');
        if ($currency) {
            $filter->setFilterCurrency($currency);
        }
        $ids = [];
        foreach ((array) $zahls->getAll($filter) as $method) {
            $ids[] = (string) $method->getId();
        }
        return array_values(array_unique(array_filter($ids)));
    }

    public function getZahlsGateway($gatewayId) {
        $zahls = $this->getInterface();
        $gateway = new \Zahls\Models\Request\Gateway();
        $gateway->setId($gatewayId);
        try {
            $zahlsGateway = $zahls->getOne($gateway);
            return $zahlsGateway;
        } catch (\Zahls\ZahlsException $e) {
            if (!self::isNotFound($e)) {
                throw new \RuntimeException(sprintf('zahls.ch did not answer for gateway %d: %s (HTTP %d)', $gatewayId, $e->getMessage(), (int) $e->getCode()));
            }
            throw new \Exception('No gateway found by ID: '. $gatewayId);
        }
    }


    private function registerAutoloader()
    {
        spl_autoload_register(function ($class) {
            $root = ZAHLS_PLUGIN_DIR . '/zahls-php-master';
            $classFile = $root . '/lib/' . str_replace('\\', '/', $class) . '.php';
            if (file_exists($classFile)) {
                require_once $classFile;
            }
        });
    }
}