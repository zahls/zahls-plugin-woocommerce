<?php
/**
 * Plugin Name: zahls.ch Credit Cards, PostFinance and TWINT for WooCommerce
 * Description: Integration of payment options from zahls.ch like MasterCard, Visa, TWINT and PostFinance in WooCommerce
 * Author: siebenberge gmbh
 * Author URI: https://www.siebenberge.com
 * Text Domain: zahls-ch-payment-gateway
 * Version: 2.0.12
 * Requires at least: 4.6
 * Tested up to: 7.1
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.2
 */

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

define('ZAHLS_VERSION', '2.0.12');
define('ZAHLS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZAHLS_PLUGIN_PATH', plugin_dir_path(__FILE__));

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );



add_action('plugins_loaded', 'WC_Zahls_offline_gateway_init', 11);


use ZahlsPaymentGateway\Service\ZahlsApiService;
use ZahlsPaymentGateway\Service\SubscriptionService;
use ZahlsPaymentGateway\Service\OrderService;
use ZahlsPaymentGateway\Controller\PaymentController;
use ZahlsPaymentGateway\Webhook\Dispatcher;
use ZahlsPaymentGateway\Util\CartUtil;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;



function WC_Zahls_offline_gateway_init()
{
    class WC_Zahls_Gateway extends WC_Payment_Gateway
    {
		
		const LANG = ['en', 'de', 'it', 'fr', 'nl', 'pt', 'tr'];

        protected $zahlsApiService;
        protected $subscriptionService;
        protected $orderService;
        protected $paymentController;
        protected $webhookDispatcher;
		
        public $enabled;
        public $title;
        public $description;
        public $instance;
        public $sid;
		public $platform;
        public $apiKey;
        public $prefix;
        public $logos;
		public $subscriptionLogos;
        public $lookAndFeelId;
        public $subscriptionsEnabled;
        public $subscriptionTitle;
        public $subscriptionUserDesc;

        public function __construct()
        {
			if(defined('ZAHLS_PLUGIN_DIR') == false){
			define( 'ZAHLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
			}
          //  $this->lang = ['en', 'de', 'it', 'fr', 'nl', 'pt', 'tr'];
            $this->id = 'zahls';
            $this->method_title = __('zahls.ch', 'zahls-ch-payment-gateway');
            $this->method_description = __('Accept payments with zahls.ch.', 'zahls-ch-payment-gateway');
			
			$this->load_dependencies();
            $this->init_components();
            $this->register_hooks();

            if (isset($_GET['zahls_error'])) {
                $this->handleError();
			}
		}
		
		protected function load_dependencies() {
            require_once ZAHLS_PLUGIN_DIR . '/src/Service/ZahlsApiService.php';
            require_once ZAHLS_PLUGIN_DIR . '/src/Service/SubscriptionService.php';
            require_once ZAHLS_PLUGIN_DIR . '/src/Service/OrderService.php';
            require_once ZAHLS_PLUGIN_DIR . '/src/Controller/PaymentController.php';
            require_once ZAHLS_PLUGIN_DIR . '/src/Util/CartUtil.php';
			require_once ZAHLS_PLUGIN_DIR . '/src/Util/StatusUtil.php';
            require_once ZAHLS_PLUGIN_DIR . '/src/Webhook/Dispatcher.php';
        }

        protected function init_components() {  
            $this->init_form_fields();
            $this->init_settings();
			
			$this->zahlsApiService = new ZahlsApiService($this->instance, $this->apiKey, $this->platform, $this->prefix, $this->lookAndFeelId);
            $this->subscriptionService = new SubscriptionService($this->zahlsApiService);
            $this->orderService = new OrderService();
            $this->paymentController = new PaymentController();
            $this->webhookDispatcher = new Dispatcher($this->zahlsApiService, $this->orderService, $this->prefix);
		}
		
		
	 /**
	 * Initialize Gateway Settings Form Fields
	 */
        public function init_form_fields()
        {
            $this->form_fields = include('includes/settings-zahls.php');
        }

        public function init_settings() {
            parent::init_settings();

            // Never leave the settings empty: WC_Settings_API::get_option() calls init_settings() again while
            // they are empty, which recursed endlessly ("Maximum call stack size reached").
            if (empty($this->settings)) {
                $this->settings = ['enabled' => 'no'];
            }

            $this->enabled = $this->setting('enabled');
            $this->title = $this->setting('title');
            $this->description = $this->setting('description');

            $this->instance = $this->setting('instance');
            $this->platform = "zahls.ch";

            $this->instance = str_replace(".zahls.ch", "", $this->instance);
            $this->instance = str_replace(".ch", "", $this->instance);

            $this->sid = $this->setting('sid');
            $this->apiKey = !empty($this->setting('apiKey')) ? $this->setting('apiKey') : $this->setting('sid');
            $this->prefix = $this->setting('prefix');
            $this->logos = $this->setting('logos');
            $this->subscriptionsEnabled = $this->setting('subscriptions_enabled');
            $this->subscriptionTitle = $this->setting('subscriptions_title');
            $this->subscriptionUserDesc = $this->setting('subscriptions_user_desc');
            $this->subscriptionLogos = $this->setting('subscription_logos');
            $this->lookAndFeelId = $this->setting('lookAndFeelId');
        }

        /**
         * Reads a setting like get_option(), but from the settings loaded above, so it never calls init_settings().
         *
         * @param string $key setting key.
         * @return mixed
         */
        private function setting($key) {
            if (isset($this->settings[$key])) {
                return $this->settings[$key];
            }
            $fields = $this->get_form_fields();
            return isset($fields[$key]['default']) ? $fields[$key]['default'] : '';
        }

		/**
		 * Hooks are registered by the first instance only, so another instance (other plugins may create one)
		 * does not register them again.
		 *
		 * @var bool
		 */
		private static $hooksRegistered = false;

		protected function register_hooks() {
            // "Refund via zahls.ch" on the order in WooCommerce
            $this->supports = array_values(array_unique(array_merge($this->supports, ['refunds'])));

            // Get config data
            // Add subscription support if activated
            if ($this->subscriptionsEnabled === 'yes') {
                $this->supports = array_merge($this->supports, array(
                    'subscriptions',
                    'subscription_cancellation',
                    'subscription_suspension',
                    'subscription_reactivation',
                    'subscription_amount_changes',
                    'subscription_date_changes',
                    'subscription_payment_method_change',
                    'subscription_payment_method_change_customer',
                    'multiple_subscriptions',
                ));
            }

            if (self::$hooksRegistered) {
                return;
            }
            self::$hooksRegistered = true;

            if ($this->subscriptionsEnabled === 'yes') {
                // Filter to extend description and title in case of recurring payment
                add_filter('woocommerce_gateway_title', array($this, 'mutateTitle'), 10, 2);
                add_filter('woocommerce_gateway_description', array($this, 'mutateDescription'), 10, 2);
            }





            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            // The webhook and the renewal handler are registered once at plugin level (see below the class).
        }

        /**
         * Handles the zahls.ch webhook (woocommerce_api_wc_zahls_gateway).
         */
        public function handle_webhook() {
            $this->webhookDispatcher->check_webhook_response();
        }

        /**
         * Charges a renewal (woocommerce_scheduled_subscription_payment_zahls).
         *
         * @param float     $amount  renewal amount.
         * @param \WC_Order $renewal renewal order.
         */
        public function process_scheduled_renewal($amount, $renewal) {
            $this->subscriptionService->process_recurring_payment($amount, $renewal);
        }
        
		
		
		public function process_payment($orderId)
        {
            $cart = WC()->cart;
            $order = new WC_Order($orderId);

            $paymentMethodChangeGET = !empty($_GET['change_payment_method']) ? $_GET['change_payment_method'] : null;

            // $orderId is actually the subscriptionId in case of payment method change
            if (CartUtil::isPaymentMethodChange($paymentMethodChangeGET)) {
                $subscription = new \WC_Subscription($orderId);
                $order = new \WC_Order($subscription->get_last_order());
            }

            $totalAmount = floatval($order->get_total());
            $reference = ($this->prefix ? $this->prefix . '_' :  '') . $orderId;
            $currency = get_woocommerce_currency();
            $basket = $this->paymentController->createBasketByCart($cart);
            $basketAmount = $this->paymentController->getBasketAmount($basket);

            $successRedirectUrl = $this->get_return_url($order);

            $purpose = '';
            // If the basket amount does not match the totalAmount, the basket is not correct
            if (!$totalAmount || $totalAmount !== floatval($basketAmount)) {
                // Basket backup as purpose
                // ToDo: Remove this as soon as "createBasketByCart" works flawless
                $purpose = $this->paymentController->createPurposeByBasket($basket);

                // Clear basket because the data is not correct
                $basket = [];
            }

            // The order key proves that the visitor returning from zahls.ch owns the order (see handleError)
            $cancelRedirectUrl = add_query_arg([
                'zahls_error' => '1',
                'order_id' => $order->get_id(),
                'key' => $order->get_order_key(),
            ], wc_get_cart_url());
            $preAuthorization = false;
            $chargeOnAuth = false;
            $customButtonText = null;

            // Classic checkout posts the form field, the block checkout passes it as payment method data
            $allowRecurring = isset($_POST['zahls-allow-recurring']) ? wc_clean(wp_unslash($_POST['zahls-allow-recurring'])) : null;

            // Subscriptions of this order, also when the cart does not show them as subscription products
            $orderHasSubscription = !CartUtil::isPaymentMethodChange($paymentMethodChangeGET)
                && function_exists('wcs_order_contains_subscription')
                && wcs_order_contains_subscription($order, ['parent', 'resubscribe', 'switch']);

            switch (CartUtil::getOrderType($cart, $paymentMethodChangeGET, $allowRecurring, $orderHasSubscription) ) {
                case CartUtil::ORDER_SUBSCRIPTION_AUTO:
                    $preAuthorization = true;

                    // No immmediate charge should happen for payment method changes
                    // Also not if totalAmount is 0 and must be artificially elevated for tokenizations to work
                    if ($totalAmount) {
                        $chargeOnAuth = true;
                    } else {
                        // The amount is artificially elevated because the Gateway creation always needs an amount
                        $totalAmount = 0.50;
						$customButtonText = [
                            1 => 'Autorisieren',
                            2 => 'Authorize',
                            3 => 'Autoriser',
                            4 => 'Autorizzare',
                            7 => 'Autoriseer',
                        ];
                    }

                    break;
                case CartUtil::ORDER_SUBSCRIPTION_MANUAL:

                    // Set all subscriptions connected to this order to manual
                    $subscriptions = wcs_get_subscriptions_for_order($order->get_id(), array( 'order_type' => 'parent' ));
                    foreach ($subscriptions as $subscription) {
                        $subscription->set_requires_manual_renewal(true);
                        $subscription->save();
                    }

                    // In case of manual subscription and no amount, the order is completed
                    if (!$totalAmount) {
                        $order->payment_complete();
                        WC()->cart->empty_cart();
                        return [
                            'result' => 'success',
                            'redirect' => $this->get_return_url($order)
                        ];
                    }

                    break;
                case CartUtil::ORDER_SUBSCRIPTION_METHOD_CHANGE:
                    $preAuthorization = true;
                    $cancelRedirectUrl = wp_nonce_url(add_query_arg(['change_payment_method' => $subscription->get_id()], $subscription->get_checkout_payment_url()));

                    // The amount is artificially elevated because the Gateway creation always needs an amount
                    $totalAmount = 0.50;
					$customButtonText = [
                            1 => 'Autorisieren',
                            2 => 'Authorize',
                            3 => 'Autoriser',
                            4 => 'Autorizzare',
                            7 => 'Autoriseer',
                        ];
                    break;
            }

            $gateway = $this->zahlsApiService->createZahlsGateway($order, $totalAmount, $basket, $purpose, $reference, $successRedirectUrl, $cancelRedirectUrl, $preAuthorization, $chargeOnAuth, $currency, $customButtonText);

            // zahls.ch rejected the payment request, e.g. no active plan, wrong instance name or API key
            if (!$gateway) {
                wc_add_notice(__('The payment could not be started. Please try again or choose another payment method.', 'zahls-ch-payment-gateway'), 'error');
                return [
                    'result' => 'failure',
                ];
            }

            // WooCommerce Subscriptions sets the renewal mode it chose for the change right after this method returns,
            // although the payment method only changes once zahls.ch has the new token (webhook). Keep the current
            // mode until then, so that a change the customer abandons on zahls.ch changes nothing; the webhook
            // applies the chosen mode (see Dispatcher).
            if (CartUtil::isPaymentMethodChange($paymentMethodChangeGET)) {
                $subscriptionId = $subscription->get_id();
                $manualBefore = (bool) $subscription->get_requires_manual_renewal();
                $subscription->delete_meta_data('_zahls_change_requires_manual');
                $keepRenewalMode = null;
                $keepRenewalMode = function ($sub) use ($subscriptionId, $manualBefore, &$keepRenewalMode) {
                    if ($sub->get_id() !== $subscriptionId || (bool) $sub->get_requires_manual_renewal() === $manualBefore) {
                        return;
                    }
                    $sub->update_meta_data('_zahls_change_requires_manual', $sub->get_requires_manual_renewal() ? 'yes' : 'no');
                    $sub->set_requires_manual_renewal($manualBefore);
                    remove_action('woocommerce_before_subscription_object_save', $keepRenewalMode);
                };
                add_action('woocommerce_before_subscription_object_save', $keepRenewalMode);
            }

            // A payment method change belongs to the subscription, not to its last order
            $gatewayHolder = CartUtil::isPaymentMethodChange($paymentMethodChangeGET) ? $subscription : $order;
            $gatewayHolder->update_meta_data('zahls_gateway_id', $gateway->getId());
            // Every payment page of the order (the customer may pay an earlier one, e.g. in another tab): the webhook
            // accepts a payment only from one of these
            $gatewayIds = array_map('intval', (array) $gatewayHolder->get_meta('zahls_gateway_ids'));
            $gatewayIds[] = (int) $gateway->getId();
            $gatewayHolder->update_meta_data('zahls_gateway_ids', array_slice(array_values(array_unique(array_filter($gatewayIds))), -20));
            $gatewayHolder->save();

            $language = substr(get_locale(), 0, 2);
            !in_array($language, self::LANG) ? $language = self::LANG[0] : null;
            $redirect = str_replace('?', $language . '/?', $gateway->getLink());

            // WooCommerce Subscriptions sends the customer on with wp_safe_redirect() after a payment method change,
            // which only allows the shop's own host
            $zahlsHost = CartUtil::isPaymentMethodChange($paymentMethodChangeGET) ? wp_parse_url($redirect, PHP_URL_HOST) : null;
            if ($zahlsHost) {
                add_filter('allowed_redirect_hosts', function ($hosts) use ($zahlsHost) {
                    $hosts[] = $zahlsHost;
                    return $hosts;
                });
            }

            // Return redirect
            return [
                'result' => 'success',
                'redirect' => $redirect
            ];
        }
        
		
		
		 public function mutateTitle($title, $id) {
            // Ignore if this is not a Zahls based transaction/subscription
            if ($id !== 'zahls') {
                return $title;
            }

            if (empty($_GET['change_payment_method']) || !CartUtil::isPaymentMethodChange($_GET['change_payment_method'])) return $title;
			return $this->subscriptionTitle;
        }

        /**
         * @param $description
         * @param $id
         * @return mixed
         */
        public function mutateDescription($description, $id)
        {
            $paymentMethodChangeGET = !empty($_GET['change_payment_method']) ? $_GET['change_payment_method'] : null;

            // Ignore if this is not a Zahls based transaction/subscription
            if ($id !== 'zahls') {
                return $description;
            }

            $html = '';
            if (strlen($description) > 0) {
                $html .= wpautop($description);
            }

            // Handle change payment method action
            if (CartUtil::isPaymentMethodChange($paymentMethodChangeGET)) {
                $html .= '<input type="hidden" name="zahls-allow-recurring" id="zahls-allow-recurring" value="1" />';
                return $html;
            }

            if (!CartUtil::isSubscription(WC()->cart, $paymentMethodChangeGET)) return false;

            // do it the messy way as WCS is using wpautop on this string
            $html .= '<label for="zahls-allow-recurring">';		
	
			 if (get_option(\WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no') === 'yes') {
                $html .= '<input type="checkbox" checked name="zahls-allow-recurring" id="zahls-allow-recurring" value="1" />';
            } else {
                $html .= '<input type="hidden" checked name="zahls-allow-recurring" id="zahls-allow-recurring" value="1" />';
            }

            $html .= $this->subscriptionUserDesc . '</label>';
			
            // Add logic to show cards depending on checkbox
            $html .= '<script type="text/javascript">
                  jQuery(function() {
                    jQuery("#zahls-allow-recurring").on("change", function() {
                      var checked = jQuery(this).is(":checked");
                        jQuery(".onetime").each(function() {
                            checked ? jQuery(this).hide() : jQuery(this).show();
                        });
                        jQuery(".tokenization").each(function() {
                            checked ? jQuery(this).show() : jQuery(this).hide();
                        });
                    });
                  });
                </script>';

            return $html;
        }
        
        
        
    /**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function admin_options() {
        
            $urlparts = parse_url(home_url());
            $domain = $urlparts['host'];
        
		?>
		<h2><?php _e( 'zahls.ch Settings', 'zahls-ch-payment-gateway' ); wc_back_link( __( 'Return to payments', 'woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ); ?></h2>
			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<table class="form-table">
							<?php $this->generate_settings_html();?>
	
						</table><!--/.form-table-->
						
						
							
						<div style="display: block; margin: 20px 0 0 0; padding: 10px; background-color: #3f245c; color: #fff;">
						
						<div style="font-size: 20px; padding: 10px 0 10px 0;"><i class="dashicons dashicons-arrow-right-alt"></i> <b><?php _e('Order status sync with zahls.ch', 'zahls-ch-payment-gateway'); ?></b></div>
							<p><?php _e('Please copy this Webhook URL to the section "Webhooks" in your zahls.ch backend to sync the payment status of your orders.', 'zahls-ch-payment-gateway'); ?> </p>
							<p><input style="background-color:  #fff; border: 1px solid #000; width: 100%; padding: 5px; font-size: 20px;" type="input" value="<?php echo get_site_url()."/?wc-api=wc_zahls_gateway"; ?>" readonly></p>
							<p><a href="https://login.zahls.ch" target="_blank" style="color: #fff;"><?php _e('Open your zahls.ch backend.', 'zahls-ch-payment-gateway'); ?></a></p>
					</div>
					</div>
					<div id="postbox-container-1" class="postbox-container">
	                        <div id="side-sortables" class="meta-box-sortables ui-sortable"> 
                                
                                
                                
                                        <div class="postbox">
	                                <h3 class="hndle"><span><i class="dashicons dashicons-superhero-alt"></i>&nbsp;&nbsp;<?php _e('zahls.ch for credit cards and TWINT', 'zahls-ch-payment-gateway'); ?></span></h3>
                                    <hr>
	                                <div class="inside">
	                                    <div class="support-widget">
	                                        <p><?php _e('Thank you for using zahls.ch. You need to have an account from zahls.ch to use this payment gateway for your transcations. You can get a free account on', 'zahls-ch-payment-gateway'); ?> <a href="https://www.zahls.ch" target="_blank">www.zahls.ch</a>.</p>                                        

	                                    </div>
	                                </div>
	                            </div>
								
							                          

	                            <div class="postbox">
	                                <h3 class="hndle"><span><i class="dashicons dashicons-editor-help"></i>&nbsp;&nbsp;<?php _e('Support', 'zahls-ch-payment-gateway'); ?></span></h3>
                                    <hr>
	                                <div class="inside">
	                                    <div class="support-widget">
	                                        <p>
                                            <?php _e('Do you have a question, idea, problem or praise? Please feel free to contact us.', 'zahls-ch-payment-gateway'); ?>    
	                                        </p>
                                            <p><a href="mailto:support@zahls.ch" target="_blank">support@zahls.ch</a><br>
<a href="https://www.zahls.ch" target="_blank">www.zahls.ch</a></p>
                                            
                                            
	                                        <p style="margin-bottom: 0;"><?php _e('Please leave us a ', 'zahls-ch-payment-gateway'); ?>  <a target="_blank" href="https://wordpress.org/support/view/plugin-reviews/zahls-ch-payment-gateway?filter=5#postform">★★★★★</a> <?php _e('rating', 'zahls-ch-payment-gateway'); ?>.</p>

	                                    </div>
	                                </div>
	                            </div>
	                       
	    <?php /*   coming soon                      <div class="postbox rss-postbox">
	    								<h3 class="hndle"><span><i class="fa fa-wordpress"></i>&nbsp;&nbsp;sb_zahls Blog</span></h3>
                                        <hr>
	    								<div class="inside">
											<div class="rss-widget">
												<?php
	    											wp_widget_rss_output(array(
	    													'url' => 'https://www.zahls.ch/feed/',
	    													'title' => 'zahls.ch Blog',
	    													'items' => 3,
	    													'show_summary' => 0,
	    													'show_author' => 0,
	    													'show_date' => 1,
	    											));
	    										?>
	    									</div>
	    								</div>
	    						</div> */?>

	                        </div>
	                    </div>
                    </div>
				</div>
				<div class="clear"></div>
				<style type="text/css">
				.sb_zahls_button{
					background-color:#4CAF50 !important;
					border-color:#4CAF50 !important;
					color:#ffffff !important;
					width:100%;
					text-align:center;
					height:35px !important;
					font-size:12pt !important;
				}
                .sb_zahls_button .dashicons {
                    padding-top: 5px;
                }
				</style>
				<?php
	}
        
        
        
        
        
        

     /*    public function register_autoloader()
        {
            spl_autoload_register(function ($class) {
                $root = __DIR__ . '/zahls-php-master';
                $classFile = $root . '/lib/' . str_replace('\\', '/', $class) . '.php';
                if (file_exists($classFile)) {
                    require_once $classFile;
                }
            });
        } */

        /**
         * Refunds (part of) the order's payment at zahls.ch ("Refund via zahls.ch" in WooCommerce).
         *
         * @param int        $order_id order.
         * @param float|null $amount   amount to refund.
         * @param string     $reason   reason entered in WooCommerce.
         * @return bool|WP_Error
         */
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            $order = wc_get_order($order_id);
            if (!$order) {
                return new WP_Error('zahls_refund', __('Order not found.', 'zahls-ch-payment-gateway'));
            }
            if ($amount === null || (float) $amount <= 0) {
                return new WP_Error('zahls_refund', __('Enter the amount to refund.', 'zahls-ch-payment-gateway'));
            }
            try {
                $transactionId = $this->refundableTransactionId($order);
                if (!$transactionId) {
                    return new WP_Error('zahls_refund', __('No zahls.ch payment found for this order. Refund it in the zahls.ch backend.', 'zahls-ch-payment-gateway'));
                }
                $refund = $this->zahlsApiService->refundTransaction($transactionId, $amount);
            } catch (\RuntimeException $e) {
                wc_get_logger()->error(sprintf('Refund of order %d: %s', $order->get_id(), $e->getMessage()), ['source' => 'zahls']);
                return new WP_Error('zahls_refund', __('zahls.ch did not answer. Check in the zahls.ch backend whether the refund was made before you try again; if it was, record it with "Refund manually".', 'zahls-ch-payment-gateway'));
            } catch (\Throwable $e) {
                wc_get_logger()->error(sprintf('Refund of order %d failed: %s', $order->get_id(), $e->getMessage()), ['source' => 'zahls']);
                return new WP_Error('zahls_refund', sprintf(__('zahls.ch did not refund the payment: %s', 'zahls-ch-payment-gateway'), $e->getMessage()));
            }
            $order->add_order_note(sprintf(
                /* translators: 1: amount, 2: zahls.ch transaction id, 3: reason */
                __('Refunded %1$s via zahls.ch (payment %2$s, refund %4$s).%3$s', 'zahls-ch-payment-gateway'),
                wc_price($amount, ['currency' => $order->get_currency()]),
                $transactionId,
                $reason !== '' ? ' ' . sprintf(__('Reason: %s', 'zahls-ch-payment-gateway'), $reason) : '',
                $refund && method_exists($refund, 'getId') ? $refund->getId() : '?'
            ));
            return true;
        }

        /**
         * The zahls.ch payment of the order: the transaction id WooCommerce recorded. Otherwise (orders paid with
         * older versions) the one payment of the order total on a payment page with exactly this order's reference
         * that is no other order's payment; 0 if there is not exactly one.
         */
        private function refundableTransactionId($order)
        {
            if ($order->get_transaction_id() !== '') {
                return (int) $order->get_transaction_id();
            }
            $reference = ($this->prefix ? $this->prefix . '_' : '') . $order->get_id();
            $total = (int) round((float) $order->get_total() * 100);
            $gatewayIds = array_map('intval', (array) $order->get_meta('zahls_gateway_ids'));
            $gatewayIds[] = (int) $order->get_meta('zahls_gateway_id');
            $candidates = [];
            foreach (array_unique(array_filter($gatewayIds)) as $gatewayId) {
                try {
                    $gateway = $this->zahlsApiService->getZahlsGateway($gatewayId);
                } catch (\RuntimeException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    continue;
                }
                if ((string) $gateway->getReferenceId() !== $reference) {
                    continue;
                }
                foreach ((array) $gateway->getInvoices() as $invoice) {
                    foreach ((array) ($invoice['transactions'] ?? []) as $transaction) {
                        $id = (int) ($transaction['id'] ?? 0);
                        if (($transaction['status'] ?? '') === 'confirmed' && (int) ($transaction['amount'] ?? 0) === $total
                            && !wc_get_orders(['transaction_id' => (string) $id, 'limit' => 1, 'return' => 'ids'])) {
                            $candidates[$id] = true;
                        }
                    }
                }
            }
            return count($candidates) === 1 ? (int) key($candidates) : 0;
        }

        /**
         * zahls.ch payment method id => the plugin's icon for it.
         */
        const PAYMENT_METHOD_ICONS = [
            'post-finance-pay' => 'postfinance_card',
            'postfinance-card' => 'postfinance_card',
            'postfinance-efinance' => 'postfinance_efinance',
        ];

        /**
         * Payment methods that can be stored for recurring payments (shown on a tokenization payment page).
         */
        const RECURRING_ICONS = ['twint', 'visa', 'mastercard', 'american_express', 'diners_club', 'discover', 'jcb', 'maestro', 'postfinance_card', 'apple_pay', 'google_pay'];

        /**
         * Sets the logos to the payment methods active on the zahls.ch instance.
         *
         * @return array{logos: string[], subscription_logos: string[], unknown: string[]}
         * @throws \Exception when zahls.ch cannot be asked
         */
        public function syncPaymentMethods()
        {
            $logos = [];
            $unknown = [];
            $options = isset($this->form_fields['logos']['options']) ? $this->form_fields['logos']['options'] : [];
            $ids = $this->zahlsApiService->getActivePaymentMethodIds(get_woocommerce_currency());
            if (!$ids) {
                throw new \Exception(__('zahls.ch lists no active payment method for the shop currency. The logos were not changed.', 'zahls-ch-payment-gateway'));
            }
            foreach ($ids as $id) {
                $icon = self::PAYMENT_METHOD_ICONS[$id] ?? str_replace('-', '_', $id);
                // Only logos the settings offer, so that saving the settings keeps them
                if (isset($options[$icon]) && self::cardLogos([$icon])) {
                    $logos[] = $icon;
                } else {
                    $unknown[] = $id;
                }
            }
            $logos = array_values(array_unique($logos));
            $subscriptionLogos = array_values(array_intersect($logos, self::RECURRING_ICONS));
            $this->update_option('logos', $logos);
            $this->update_option('subscription_logos', $subscriptionLogos);
            $this->logos = $logos;
            $this->subscriptionLogos = $subscriptionLogos;
            return ['logos' => $logos, 'subscription_logos' => $subscriptionLogos, 'unknown' => $unknown];
        }

        /**
         * Settings field: button that takes the payment methods from zahls.ch.
         */
        public function generate_zahls_sync_html($key, $data)
        {
            $url = wp_nonce_url(admin_url('admin-post.php?action=zahls_sync_payment_methods'), 'zahls_sync_payment_methods');
            ob_start();
            ?>
            <tr valign="top">
                <th scope="row" class="titledesc"><?php echo esc_html($data['title']); ?></th>
                <td class="forminp">
                    <a class="button" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Take active payment methods from zahls.ch', 'zahls-ch-payment-gateway'); ?></a>
                    <p class="description"><?php echo esc_html($data['description']); ?></p>
                </td>
            </tr>
            <?php
            return ob_get_clean();
        }

        /**
         * The card logos of a logo setting that the plugin has an icon for.
         *
         * @param mixed $setting saved multiselect value.
         * @return string[]
         */
        public static function cardLogos($setting)
        {
            return array_values(array_filter(is_array($setting) ? $setting : [], function ($logo) {
                return is_string($logo) && preg_match('/^[a-z0-9_-]+$/', $logo) && file_exists(__DIR__ . '/cardicons/card_' . $logo . '.svg');
            }));
        }

         public function get_icon()
        {
            $style = version_compare(WC()->version, '2.6', '>=') ? 'margin-left: 0.3em;' : '';
            $paymentMethodChangeGET = !empty($_GET['change_payment_method']) ? $_GET['change_payment_method'] : null;

            $subscriptionPayment = CartUtil::isSubscription(WC()->cart, $paymentMethodChangeGET);
            $paymentMethodChange = CartUtil::isPaymentMethodChange($paymentMethodChangeGET);

            $hideStyle = $subscriptionPayment ? 'display: none' : '';
            // A multiselect saved empty is an empty string, and one never saved has no array either
            $subscriptionLogos = self::cardLogos($this->subscriptionLogos);
            $logos = self::cardLogos($paymentMethodChange ? $this->subscriptionLogos : $this->logos);

            $icon = '';
            //if $logos is an array¨
            if(is_array($logos)){
           
            foreach ($logos as $logo) {
                $src = esc_url(WC_HTTPS::force_https_url(plugins_url('cardicons/card_' . $logo . '.svg', __FILE__)));
                if ($subscriptionPayment && !$paymentMethodChange && in_array($logo, $subscriptionLogos, true)) {
                    $icon .= '<img class="tokenization" src="' . $src . '" alt="' . esc_attr($logo) . '" id="' . esc_attr($logo) . '" width="32" style="' . esc_attr($style) . '" />';
                }
                $icon .= '<img class="onetime" src="' . $src . '" alt="' . esc_attr($logo) . '" id="' . esc_attr($logo) . '" width="32" style="' . esc_attr($style . $hideStyle) . '" />';
            }}

            // Add a wrapper around the images to allow styling
            return apply_filters('woocommerce_gateway_icon', '<span class="icon-wrapper">' . $icon . '</span>', $this->id);
        }
		
		private function handleError() {
            // Delete the unused zahls.ch gateway of the order. Only for the customer who owns the order:
            // without the order key anyone could delete the payment link of any order.
            $orderId = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
            $orderKey = isset($_GET['key']) ? wc_clean(wp_unslash($_GET['key'])) : '';
            $order = $orderId ? wc_get_order($orderId) : false;

            if ($order && $orderKey !== '' && hash_equals((string) $order->get_order_key(), $orderKey) && !$order->is_paid()) {
                $gatewayId = intval($order->get_meta('zahls_gateway_id'));
                if ($gatewayId) {
                    $this->zahlsApiService->deleteGatewayById($gatewayId);
                }
            }
            wc_print_notice(__('Payment failed. Please choose another method.', 'zahls-ch-payment-gateway'), 'error');
        }

    }
}

function wc_zahls_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Zahls_Gateway';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_zahls_add_to_gateways');

/**
 * The gateway WooCommerce uses (loads the payment gateways if needed), or null.
 *
 * @return WC_Zahls_Gateway|null
 */
function zahls_get_gateway() {
    if (!function_exists('WC') || !WC()->payment_gateways()) {
        return null;
    }
    $gateways = WC()->payment_gateways()->payment_gateways();
    return isset($gateways['zahls']) ? $gateways['zahls'] : null;
}

/*
 * Settings: "Take active payment methods from zahls.ch" (button of the zahls_sync settings field).
 */
add_action('admin_post_zahls_sync_payment_methods', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You are not allowed to change the payment settings.', 'zahls-ch-payment-gateway'), 403);
    }
    check_admin_referer('zahls_sync_payment_methods');
    $gateway = zahls_get_gateway();
    try {
        if (!$gateway) {
            throw new \Exception(__('The zahls.ch payment method is not loaded.', 'zahls-ch-payment-gateway'));
        }
        $result = $gateway->syncPaymentMethods();
        $notice = ['type' => 'success', 'message' => sprintf(
            /* translators: 1: logos, 2: logos for recurring payments */
            __('Payment methods taken from zahls.ch: %1$s. For recurring payments: %2$s.', 'zahls-ch-payment-gateway'),
            $result['logos'] ? implode(', ', $result['logos']) : '–',
            $result['subscription_logos'] ? implode(', ', $result['subscription_logos']) : '–'
        )];
        if ($result['unknown']) {
            $notice['message'] .= ' ' . sprintf(__('Without logo in the plugin: %s.', 'zahls-ch-payment-gateway'), implode(', ', $result['unknown']));
        }
    } catch (\Throwable $e) {
        $notice = ['type' => 'error', 'message' => sprintf(__('The payment methods could not be taken from zahls.ch: %s', 'zahls-ch-payment-gateway'), $e->getMessage())];
    }
    set_transient('zahls_sync_notice_' . get_current_user_id(), $notice, 60);
    wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=checkout&section=zahls'));
    exit;
});

add_action('admin_notices', function () {
    $key = 'zahls_sync_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if (!$notice) {
        return;
    }
    delete_transient($key);
    printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
});

/*
 * Webhook and renewal handler are registered exactly once, independent of how many gateway instances exist and
 * whether WooCommerce has loaded the payment gateways yet. Registered per instance, every instance added another
 * renewal handler, and each renewal was charged once per instance.
 */
add_action('woocommerce_api_wc_zahls_gateway', function () {
    $gateway = zahls_get_gateway();
    if ($gateway) {
        $gateway->handle_webhook();
    }
});

/*
 * A payment method change to zahls.ch only takes effect when the customer has authorized the new card or TWINT
 * on zahls.ch (webhook "authorized"), not already when the form is submitted.
 */
/*
 * A renewal or resubscribe order gets its own payment pages: the subscription's (from a payment method change, where
 * every later charge is listed) must not be copied to it, they belong to no order.
 */
foreach (['renewal_order', 'resubscribe_order'] as $zahlsCopyType) {
    add_filter('wc_subscriptions_' . $zahlsCopyType . '_data', function ($data) {
        unset($data['zahls_gateway_id'], $data['zahls_gateway_ids']);
        return $data;
    });
}

add_filter('woocommerce_subscriptions_update_payment_via_pay_shortcode', function ($update, $new_payment_method) {
    return $new_payment_method === 'zahls' ? false : $update;
}, 10, 2);

add_action('woocommerce_scheduled_subscription_payment_zahls', function ($amount, $renewal) {
    $gateway = zahls_get_gateway();
    if ($gateway) {
        $gateway->process_scheduled_renewal($amount, $renewal);
    } elseif (function_exists('wc_get_logger')) {
        wc_get_logger()->error('Renewal not charged: the zahls.ch gateway is not available', ['source' => 'zahls']);
    }
}, 10, 2);



/* siebenberge plugin_action_links */

function sb_zahls_plugin_action_links( $links ) {

	$links = array_merge( array(
		'<a href="' . esc_url( admin_url( '/admin.php?page=wc-settings&tab=checkout&section=zahls' ) ) . '">' . __( 'Settings', 'zahls-ch-payment-gateway' ) . '</a>'
	), $links );

	return $links;

}
add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'sb_zahls_plugin_action_links' );







/**
 * Registers WooCommerce Blocks integration.
 */

add_action( 'woocommerce_blocks_loaded', 'zahls_woocommerce_blocks_support' );

function zahls_woocommerce_blocks_support() {
  if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	  
	  if(defined('ZAHLS_PLUGIN_DIR') == false){
			define( 'ZAHLS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
			}
	  
     require_once ZAHLS_PLUGIN_DIR .  '/includes/blocks-checkout.php';
	  
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WC_Zahls_Blocks_Support );
            }
        );
	  
	  
  }
}










?>