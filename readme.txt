=== zahls.ch Credit Cards, PostFinance and TWINT for WooCommerce  ===
Contributors: ivanlouis
Donate link: https://billing.zahls.ch/de/vpos
Tags: twint, kreditkarten, postfinance, payrexx, stripe
Requires at least: 4.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

With zahls.ch you accept various payment methods such as credit cards and TWINT with a single plugin.

== Description ==
Website: [www.zahls.ch](https://www.zahls.ch)

With this plugin for [zahls.ch](https://www.zahls.ch) you can easily integrate different payment methods into WooCommerce. Accept credit cards, TWINT and PostFinance Card with just one plugin. The plugin is and remains free of charge.

zahls.ch has attractive conditions. For testing and for beginners with low volumes, we recommend the starter package, which has no fixed costs. For credit card and TWINT transactions you pay 2.9% plus CHF 0.30. Our support team offers you a free installation service at support@zahls.ch.

More about the conditions of zahls.ch at [www.zahls.ch](https://www.zahls.ch).

= Payment options =
* TWINT
* PostFinance Card
* PostFinance E-Finance
* Mastercard
* VISA
* AMEX
* Apple Pay
* Google Pay
* Masterpass
* PayPal
* Stripe
* Maestro
* Billpay
* myOne
* V Pay
* Bitcoin
* REKA
* Swissbilling
* Klarna
* Rechnung (Invoice)
* Swiss-QR Invoice
* WIRpay
* Viacash/Barzahlen
* Bancontact
* GiroPay
* EPS
* AntePay
* Paysafecash
* bob invoice

= Supported payment providers =
* TWINT
* Ogone / Ingenico
* PostFinance
* Concardis
* VIVEUM
* Coinbase
* Paymill
* Datatrans
* SIX Saferpay
* PayPal
* Paymill
* Stripe
* Braintree
* Swissbilling
* Billpay
* WIRpay
* PayOne
* Masterpass
* Viacash/Barzahlen
* Bancontact
* GiroPay
* EPS
* AntePay
* Paysafecash
* bob invoice

== Installation ==

https://www.youtube.com/watch?v=Kw9ne-kT2Jk

* Download plugin
* Upload and install the ZIP archive.
* Activate the plugin in WordPress
* Configure your payment methods in the backend of zahls.ch. We recommend that you activate TWINT, MasterCard, Visa, Apple Pay, Google Pay, PostFinance Card and PostFinance E-Finance. All those payment methods can be set up directly in the backend of zahls.ch. For this, the payment provider Payrexx Direct and Payrexx Swiss Collecting should be activated.
* Enter the details of your zahls.ch user in WooCommerce. The instance name is derived from your zahls.ch address (e.g. example.zahls.ch => example). You can find the API key in the backend of zahls.ch under "API & Integrations".
* Important: To ensure that payments in WooCommerce receive the correct status, please enter the following URL in the backend of zahls.ch ([login.zahls.ch](https://login.zahls.ch)) under "Webhooks". Replace ihredomain.ch with your domain: https://www.ihredomain.ch/?wc-api=wc_zahls_gateway.

The zahls.ch support team will be happy to assist you with the installation.

== Screenshots ==
1. Settings WooCommerce backend
2. View checkout frontend
3. Screenshot zahls.ch backend for webhook

== Upgrade Notice ==

= 1.0.0 =
* First version of zahls.ch plugin

= 1.0.1 =
* Minor changes, no upgrade necessary

= 1.0.2 =
* Minor changes, no backup necessary

= 1.0.3 =
* Minor changes, no backup necessary

= 1.0.4 =
* Minor changes, no backup necessary

= 1.0.5 =
* Minor changes, no backup necessary

= 1.0.6 =
* Minor changes, no backup necessary

= 1.0.7 =
* Minor changes, no backup necessary

= 1.0.8 =
* Minor changes, no backup necessary

= 1.0.9 =
* Minor changes, no backup necessary

= 1.1.0 =
* Major changes, please backup

= 1.1.1 =
* Minor changes, no backup necessary

= 1.1.2 =
* Minor changes, no backup necessary

= 1.1.3 =
* Minor changes, no backup necessary

= 1.1.4 =
* Minor changes, no backup necessary

= 1.2.0 =
* Major update, make sure to create a backup

= 1.2.1 =
* Minor changes, no backup necessary

= 1.2.2 =
* Minor changes, no backup necessary

= 1.2.3 =
* Minor changes, no backup necessary

= 1.2. =
* Minor changes, no backup necessary

= 2.0.0 =
* Major update, make sure to create a backup

= 2.0.1 =
* Major update, make sure to create a backup

= 2.0.2 =
* Major update, make sure to create a backup

= 2.0.3 =
* Major update, make sure to create a backup

= 2.0.4 =
* Major update, make sure to create a backup

= 2.0.5 =
* Major update, make sure to create a backup

= 2.0.6 =
* Major update, make sure to create a backup


== Changelog ==

= 2.0.9 =
* Fix: Automatic subscription renewals could be charged twice, because the renewal handler was registered twice. Please update immediately if you use subscriptions.
* Fix: The webhook could fail with "Maximum call stack size reached" (endless recursion when reading the gateway settings)
* The gateways are loaded before scheduled renewals, so the renewal handler is always registered
* Webhook errors are logged with a stack trace
* Tested up to WordPress 7.1 and WooCommerce 11.2

= 2.0.8 =
* Fix: Automatic subscription renewals stopped with a fatal error (OrderUtil not found)
* Fix: Renewals did not find the stored payment token with HPOS enabled
* Fix: Block checkout did not request a payment token, subscriptions silently fell back to manual renewal
* Fix: Fatal error at checkout when zahls.ch rejects the payment request (e.g. wrong instance name or API key); the customer now sees an error message
* Fix: Webhook answered with an error when a status update arrived after the transaction had already moved on, which triggered a webhook error email from zahls.ch
* Webhook errors are logged under WooCommerce > Status > Logs (source "zahls")
* Security: the webhook only stores a subscription token that zahls.ch confirms for the same order, and only updates the order the transaction belongs to
* Security: the cancel link only deletes the payment link of an order together with its order key
* Removed the PHP 8.5 deprecation notice of curl_close()
* WC tested up to 11.1
* Requires PHP 7.4

= 2.0.6 =
* Fix Missing SDK

= 2.0.5 =
* Improved API Settings

= 2.0.4 =
* Improved information sent to zahls.ch

= 2.0.3 =
* Bugfix Compatibility WooCommerce Blocks

= 2.0.2 =
* Bugfix Webhook

= 2.0.1 =
* Bugfix PHP SDK

= 2.0.0 =
* Compatibility WooCommerce Blocks, Compatibility WooCommerce High-Performance Order Storage (HPOS), Update PHP SDK

= 1.2.4 =
* Versions

= 1.2.3 =
* Fix Webhook error if no prefix is set

= 1.2.2 =
* Fix Webhook error

= 1.2.1 =
* More Subscription Features

= 1.2.0 =
* Subscription Features, Update PHP SDK

= 1.1.4 =
* Changed for Compatibility with Elementor E-Commerce Features

= 1.1.3 =
* Improved Error Handling Webhook

= 1.1.2 =
* Improved Error Handling Webhook

= 1.1.1 =
* Updated Logos TWINT and Apple Pay

= 1.1.0 =
* Removed translations from plugin, changed all texts to english

= 1.0.9 =
* Added function to check instance

= 1.0.8 =
* Update PHP SDK

= 1.0.7 =
* Added correct textdomain, thank you stooni

= 1.0.6 =
* Improved payment icons

= 1.0.5 =
* Improved settings, new php library, added translations

= 1.0.4 =
* Improved instructions

= 1.0.3 =
* Improved translations, changed urls, no backup necessary

= 1.0.2 =
* Multisite issue, added icons

= 1.0.1 =
* Minor changes, sanitizing inputs, change namespace

= 1.0.0 =
* First version of zahls.ch plugin
