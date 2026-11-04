<?php
/**
 * Plugin Name: zahls.ch Dev Dashboard
 * Description: Testwerkzeug für das zahls.ch-Plugin: Umgebung, API-Verbindung, Webhooks, Abos, Tokens und Verlängerungen. Nur auf Test- und Staging-Shops verwenden.
 * Version: 1.0.0
 * Author: siebenberge gmbh
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page under WooCommerce → zahls Dev.
 *
 * Records the webhook answers and renewal charges of the zahls.ch plugin (hooks zahls_webhook_response and
 * zahls_renewal_charge, available from 2.0.8) and offers checks and actions to run the test plan.
 */
final class Zahls_Dev_Dashboard {

	const CAP           = 'manage_woocommerce';
	const SLUG          = 'zahls-dev';
	const OPT_EVENTS    = 'zahls_dev_events';
	const OPT_CHECKLIST = 'zahls_dev_checklist';
	const OPT_SELFTEST  = 'zahls_dev_selftest';
	const MAX_EVENTS    = 100;

	public static function init() {
		$self = new self();
		add_action( 'admin_menu', [ $self, 'menu' ], 99 );
		add_action( 'zahls_webhook_response', [ $self, 'record_webhook' ], 10, 3 );
		add_action( 'zahls_renewal_charge', [ $self, 'record_renewal' ], 10, 4 );
		foreach ( [ 'api_check', 'token_check', 'renewal', 'clear_events', 'checklist', 'selftest_start', 'selftest_charge', 'selftest_refund', 'selftest_reset' ] as $action ) {
			add_action( 'admin_post_zahls_dev_' . $action, [ $self, 'handle_' . $action ] );
		}
	}

	/* ------------------------------------------------------------------ recording */

	/**
	 * @param string $message response message of the webhook.
	 * @param int    $code    HTTP status code.
	 * @param array  $request webhook request.
	 */
	public function record_webhook( $message, $code, $request ) {
		$t = isset( $request['transaction'] ) && is_array( $request['transaction'] ) ? $request['transaction'] : [];
		$this->add_event( [
			'type'        => 'webhook',
			'transaction' => isset( $t['id'] ) ? (string) $t['id'] : '',
			'status'      => isset( $t['status'] ) ? (string) $t['status'] : '',
			'reference'   => isset( $t['invoice']['referenceId'] ) ? (string) $t['invoice']['referenceId'] : '',
			'pre_auth'    => isset( $t['preAuthorizationId'] ) ? (string) $t['preAuthorizationId'] : '',
			'amount'      => isset( $t['amount'] ) ? (string) $t['amount'] : '',
			'code'        => (int) $code,
			'message'     => (string) $message,
		] );
	}

	/**
	 * @param WC_Subscription $subscription subscription.
	 * @param int             $token        token the renewal was charged on.
	 * @param float           $amount       amount.
	 * @param bool            $charged      whether zahls.ch accepted the charge.
	 */
	public function record_renewal( $subscription, $token, $amount, $charged ) {
		$this->add_event( [
			'type'         => 'renewal',
			'subscription' => is_object( $subscription ) ? (string) $subscription->get_id() : '',
			'token'        => (string) $token,
			'amount'       => (string) $amount,
			'code'         => $charged ? 200 : 0,
			'message'      => $charged ? 'Abbuchung von zahls.ch angenommen' : ( $token ? 'Abbuchung von zahls.ch abgelehnt' : 'Kein Token am Abo' ),
		] );
	}

	private function add_event( array $event ) {
		$event['time'] = time();
		$events        = get_option( self::OPT_EVENTS, [] );
		$events        = is_array( $events ) ? $events : [];
		array_unshift( $events, $event );
		update_option( self::OPT_EVENTS, array_slice( $events, 0, self::MAX_EVENTS ), false );
	}

	/* ------------------------------------------------------------------ helpers */

	/** @return WC_Zahls_Gateway|null */
	private function gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['zahls'] ) ? $gateways['zahls'] : null;
	}

	/** @return \ZahlsPaymentGateway\Service\ZahlsApiService|null */
	private function api() {
		$gateway = $this->gateway();
		if ( ! $gateway || ! class_exists( '\ZahlsPaymentGateway\Service\ZahlsApiService' ) ) {
			return null;
		}
		$service = new \ZahlsPaymentGateway\Service\ZahlsApiService( $gateway->instance, $gateway->apiKey, $gateway->platform, $gateway->prefix, $gateway->lookAndFeelId );

		/**
		 * Lets a test setup replace the API service, e.g. with a fake one without a zahls.ch account.
		 *
		 * @param \ZahlsPaymentGateway\Service\ZahlsApiService $service API service of the plugin.
		 */
		return apply_filters( 'zahls_dev_api_service', $service );
	}

	private function has_subscriptions() {
		return class_exists( 'WC_Subscriptions' ) && function_exists( 'wcs_get_subscriptions' );
	}

	private function page_url( array $args = [] ) {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'admin.php' ) );
	}

	private function action_url( $action, array $args = [] ) {
		return wp_nonce_url( add_query_arg( array_merge( [ 'action' => 'zahls_dev_' . $action ], $args ), admin_url( 'admin-post.php' ) ), 'zahls_dev_' . $action );
	}

	private function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.' ), 403 );
		}
		check_admin_referer( 'zahls_dev_' . $action );
	}

	private function redirect_with_notice( $type, $text ) {
		set_transient( 'zahls_dev_notice_' . get_current_user_id(), [ $type, $text ], 120 );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	private function badge( $state, $text = '' ) {
		$colors = [ 'ok' => '#00a32a', 'warn' => '#dba617', 'err' => '#d63638', 'info' => '#72aee6' ];
		$labels = [ 'ok' => 'OK', 'warn' => 'Prüfen', 'err' => 'Fehler', 'info' => 'Info' ];
		return sprintf(
			'<span style="display:inline-block;min-width:52px;text-align:center;padding:1px 8px;border-radius:10px;color:#fff;font-size:12px;background:%s">%s</span>',
			esc_attr( $colors[ $state ] ),
			esc_html( $text !== '' ? $text : $labels[ $state ] )
		);
	}

	/** Reference of a fetched transaction, the same field the plugin compares. */
	private function transaction_reference( $transaction ) {
		$invoice = $transaction->getInvoice();
		if ( is_array( $invoice ) && isset( $invoice['referenceId'] ) ) {
			return (string) $invoice['referenceId'];
		}
		return (string) $transaction->getReferenceId();
	}

	/* ------------------------------------------------------------------ actions */

	public function handle_api_check() {
		$this->guard( 'api_check' );
		$api = $this->api();
		if ( ! $api ) {
			$this->redirect_with_notice( 'error', 'Das zahls.ch-Gateway ist nicht geladen.' );
		}
		try {
			$api->getInterface()->getOne( new \Zahls\Models\Request\SignatureCheck() );
			update_option( 'zahls_dev_api_ok', time(), false );
			$this->redirect_with_notice( 'success', 'Verbindung zu zahls.ch OK: Instanz und API-Schlüssel stimmen.' );
		} catch ( \Throwable $e ) {
			delete_option( 'zahls_dev_api_ok' );
			$this->redirect_with_notice( 'error', 'Verbindung zu zahls.ch fehlgeschlagen: ' . $e->getMessage() );
		}
	}

	public function handle_token_check() {
		$this->guard( 'token_check' );
		$subscription = $this->has_subscriptions() && isset( $_GET['subscription'] ) ? wcs_get_subscription( absint( $_GET['subscription'] ) ) : false;
		if ( ! $subscription ) {
			$this->redirect_with_notice( 'error', 'Abo nicht gefunden.' );
		}
		$token = (int) $subscription->get_meta( 'zahls_auth_transaction_id' );
		if ( ! $token ) {
			$this->save_token_result( $subscription->get_id(), 'err', 'Kein Token gespeichert' );
			$this->redirect_with_notice( 'error', sprintf( 'Abo #%d hat keinen Token. Verlängerungen schlagen fehl, bis die Zahlungsmethode neu bestätigt wird.', $subscription->get_id() ) );
		}
		$api         = $this->api();
		$transaction = $api ? $api->getZahlsTransaction( $token ) : null;
		if ( ! $transaction ) {
			$this->save_token_result( $subscription->get_id(), 'err', 'Transaktion ' . $token . ' bei zahls.ch nicht gefunden' );
			$this->redirect_with_notice( 'error', sprintf( 'Abo #%d: Token %d ist bei zahls.ch keine Transaktion.', $subscription->get_id(), $token ) );
		}
		$status    = (string) $transaction->getStatus();
		$reference = $this->transaction_reference( $transaction );
		$state     = $status === 'authorized' ? 'ok' : 'warn';
		$text      = sprintf( 'Status %s, Referenz %s, Betrag %s', $status, $reference !== '' ? $reference : '–', number_format( ( (int) $transaction->getAmount() ) / 100, 2, '.', '' ) );
		$this->save_token_result( $subscription->get_id(), $state, $text );
		$this->redirect_with_notice(
			$state === 'ok' ? 'success' : 'warning',
			sprintf( 'Abo #%d, Token %d: %s.', $subscription->get_id(), $token, $text ) . ( $state === 'ok' ? '' : ' Laut Payrexx-Doku wird auf eine Transaktion mit Status «authorized» abgebucht.' )
		);
	}

	private function save_token_result( $subscription_id, $state, $text ) {
		$results                     = get_option( 'zahls_dev_token_results', [] );
		$results                     = is_array( $results ) ? $results : [];
		$results[ $subscription_id ] = [ 'state' => $state, 'text' => $text, 'time' => time() ];
		update_option( 'zahls_dev_token_results', $results, false );
	}

	public function handle_renewal() {
		$this->guard( 'renewal' );
		$subscription = $this->has_subscriptions() && isset( $_GET['subscription'] ) ? wcs_get_subscription( absint( $_GET['subscription'] ) ) : false;
		if ( ! $subscription ) {
			$this->redirect_with_notice( 'error', 'Abo nicht gefunden.' );
		}
		if ( $subscription->get_payment_method() !== 'zahls' ) {
			$this->redirect_with_notice( 'error', sprintf( 'Abo #%d wird nicht mit zahls.ch bezahlt.', $subscription->get_id() ) );
		}
		// Same as "Process renewal" on the edit subscription screen: creates the renewal order and fires the gateway hook.
		do_action( 'woocommerce_scheduled_subscription_payment', $subscription->get_id() );
		$this->redirect_with_notice( 'info', sprintf( 'Verlängerung für Abo #%d ausgelöst. Ergebnis siehe Ereignisse, den bezahlten Status setzt der Webhook.', $subscription->get_id() ) );
	}

	public function handle_clear_events() {
		$this->guard( 'clear_events' );
		delete_option( self::OPT_EVENTS );
		$this->redirect_with_notice( 'success', 'Ereignisse gelöscht.' );
	}

	public function handle_checklist() {
		$this->guard( 'checklist' );
		$done = isset( $_POST['done'] ) && is_array( $_POST['done'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['done'] ) ) : [];
		update_option( self::OPT_CHECKLIST, array_values( $done ), false );
		$this->redirect_with_notice( 'success', 'Testplan gespeichert.' );
	}

	/* ------------------------------------------------------------------ zahls self-test */

	private function selftest() {
		$test = get_option( self::OPT_SELFTEST, [] );
		return is_array( $test ) ? $test : [];
	}

	private function save_selftest( array $test ) {
		update_option( self::OPT_SELFTEST, $test, false );
	}

	private function posted_amount( $field, $default ) {
		$amount = isset( $_POST[ $field ] ) ? (float) str_replace( ',', '.', wp_unslash( $_POST[ $field ] ) ) : $default;
		return max( 0.5, round( $amount, 2 ) );
	}

	/**
	 * Creates a test order and a tokenization gateway with the plugin's own ZahlsApiService, like the checkout of
	 * an automatic subscription (preAuthorization, chargeOnAuthorization for a first payment > 0).
	 */
	public function handle_selftest_start() {
		$this->guard( 'selftest_start' );
		$gateway = $this->gateway();
		$api     = $this->api();
		if ( ! $gateway || ! $api ) {
			$this->redirect_with_notice( 'error', 'Das zahls.ch-Gateway ist nicht geladen.' );
		}
		$amount          = $this->posted_amount( 'amount', 1.0 );
		$charge_on_auth  = ! empty( $_POST['charge_on_auth'] );

		$order = wc_create_order( [ 'created_via' => 'zahls-dev' ] );
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'zahls Dev-Test (Tokenisierung)' );
		$fee->set_amount( $amount );
		$fee->set_total( $amount );
		$order->add_item( $fee );
		$user = wp_get_current_user();
		$order->set_billing_first_name( 'zahls' );
		$order->set_billing_last_name( 'Dev-Test' );
		$order->set_billing_email( $user->user_email );
		$order->set_billing_country( WC()->countries->get_base_country() );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_payment_method( 'zahls' );
		$order->set_payment_method_title( 'zahls.ch' );
		$order->update_meta_data( '_zahls_dev_test', 1 );
		$order->calculate_totals( false );
		$order->save();

		// Same reference format as the plugin's checkout, so the webhook processes this order like any other.
		$reference = ( $gateway->prefix ? $gateway->prefix . '_' : '' ) . $order->get_id();
		$back      = $this->page_url();
		$zahls_gw  = $api->createZahlsGateway(
			$order,
			$amount,
			[],
			'zahls Dev-Test',
			$reference,
			$back,
			$back,
			true,
			$charge_on_auth,
			get_woocommerce_currency()
		);
		if ( ! $zahls_gw ) {
			$order->update_status( 'cancelled', 'zahls Dev-Test: Gateway nicht erstellt' );
			$this->redirect_with_notice( 'error', 'zahls.ch hat den Zahlungslink abgelehnt. Details unter WooCommerce → Status → Logs (Quelle «zahls»).' );
		}
		$order->update_meta_data( 'zahls_gateway_id', $zahls_gw->getId() );
		$order->save();

		$this->save_selftest( [
			'started'        => time(),
			'order'          => $order->get_id(),
			'gateway'        => (int) $zahls_gw->getId(),
			'link'           => (string) $zahls_gw->getLink(),
			'amount'         => $amount,
			'charge_on_auth' => $charge_on_auth,
			'charges'        => [],
		] );
		$this->redirect_with_notice( 'success', sprintf( 'Testbestellung #%d und Zahlungslink erstellt. Jetzt die Zahlungsseite öffnen und bestätigen.', $order->get_id() ) );
	}

	/** Charges the token with the plugin's chargeTransaction(), exactly as a renewal does. */
	public function handle_selftest_charge() {
		$this->guard( 'selftest_charge' );
		$test  = $this->selftest();
		$token = isset( $_POST['token'] ) ? absint( $_POST['token'] ) : 0;
		$api   = $this->api();
		if ( ! $test || ! $token || ! $api ) {
			$this->redirect_with_notice( 'error', 'Kein Token zum Abbuchen.' );
		}
		$amount            = $this->posted_amount( 'amount', 1.0 );
		$ok                = $api->chargeTransaction( $token, $amount );
		$test['charges'][] = [ 'time' => time(), 'token' => $token, 'amount' => $amount, 'ok' => $ok ];
		$this->save_selftest( $test );
		$this->redirect_with_notice(
			$ok ? 'success' : 'error',
			$ok ? sprintf( 'Abbuchung von %.2f auf Token %d von zahls.ch angenommen. Der Webhook «confirmed» sollte gleich in den Ereignissen erscheinen.', $amount, $token )
				: sprintf( 'zahls.ch hat die Abbuchung auf Token %d abgelehnt.', $token )
		);
	}

	public function handle_selftest_refund() {
		$this->guard( 'selftest_refund' );
		$id     = isset( $_POST['transaction'] ) ? absint( $_POST['transaction'] ) : 0;
		$amount = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
		$api    = $this->api();
		if ( ! $id || $amount <= 0 || ! $api ) {
			$this->redirect_with_notice( 'error', 'Keine Transaktion zum Rückerstatten.' );
		}
		$transaction = new \Zahls\Models\Request\Transaction();
		$transaction->setId( $id );
		$transaction->setAmount( (int) round( $amount * 100 ) );
		try {
			$api->getInterface()->refund( $transaction );
			$this->redirect_with_notice( 'success', sprintf( 'Transaktion %d mit %.2f rückerstattet.', $id, $amount ) );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( 'error', sprintf( 'Rückerstattung von Transaktion %d fehlgeschlagen: %s', $id, $e->getMessage() ) );
		}
	}

	public function handle_selftest_reset() {
		$this->guard( 'selftest_reset' );
		delete_option( self::OPT_SELFTEST );
		$this->redirect_with_notice( 'success', 'Selbsttest zurückgesetzt. Die Testbestellungen bleiben unter Bestellungen sichtbar.' );
	}

	/** Webhook events that belong to the running self-test. */
	private function selftest_events( array $test ) {
		$events = get_option( self::OPT_EVENTS, [] );
		$events = is_array( $events ) ? $events : [];
		return array_values( array_filter( $events, function ( $event ) use ( $test ) {
			return $event['type'] === 'webhook' && (int) $event['time'] >= (int) $test['started'];
		} ) );
	}

	private function render_selftest() {
		$test = $this->selftest();
		echo '<h2>zahls-Selbsttest (Tokenisierung und Verlängerung)</h2>';
		echo '<p style="max-width:1100px">Prüft die zahls.ch-Seite ohne WooCommerce Subscriptions, mit dem Code des Plugins: Testbestellung und Tokenisierungs-Link (wie der Checkout eines automatischen Abos), Webhook «authorized», Abbuchungen auf den Token (wie eine Verlängerung) und die Webhooks dazu. '
			. 'Den einzigen manuellen Schritt, die Bestätigung auf der zahls.ch-Zahlungsseite, machst du. <strong>Voraussetzung:</strong> Die Webhook-URL dieses Shops ist bei der zahls.ch-Instanz eingetragen, und nicht die des Live-Shops. Ohne Testmodus bei zahls.ch fliesst echtes Geld; Abbuchungen lassen sich unten rückerstatten.</p>';

		if ( ! $test ) {
			printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
			echo '<input type="hidden" name="action" value="zahls_dev_selftest_start">';
			wp_nonce_field( 'zahls_dev_selftest_start' );
			printf( '<p>Betrag der Erstzahlung: <input type="text" name="amount" value="1.00" size="6"> %s &nbsp; ', esc_html( get_woocommerce_currency() ) );
			echo '<label><input type="checkbox" name="charge_on_auth" value="1" checked> sofort abbuchen (chargeOnAuthorization, wie ein Abo mit Erstzahlung &gt; 0)</label></p>';
			submit_button( 'Selbsttest starten', 'primary', 'submit', false );
			echo '</form>';
			return;
		}

		$api           = $this->api();
		$order         = wc_get_order( $test['order'] );
		$transactions  = [];
		$gateway_error = '';
		if ( $api ) {
			try {
				$zahls_gw = $api->getZahlsGateway( $test['gateway'] );
				foreach ( (array) $zahls_gw->getInvoices() as $invoice ) {
					foreach ( isset( $invoice['transactions'] ) ? (array) $invoice['transactions'] : [] as $transaction ) {
						$transactions[] = $transaction;
					}
				}
			} catch ( \Throwable $e ) {
				$gateway_error = $e->getMessage();
			}
		}
		$authorized = null;
		foreach ( $transactions as $transaction ) {
			if ( isset( $transaction['status'] ) && $transaction['status'] === 'authorized' ) {
				$authorized = $transaction;
			}
		}
		$token  = $authorized ? (int) $authorized['id'] : 0;
		$events = $this->selftest_events( $test );

		$auth_event = null;
		$errors_500 = 0;
		$confirmed  = [];
		foreach ( $events as $event ) {
			if ( $event['code'] >= 500 ) {
				$errors_500++;
			}
			if ( $token && (int) $event['transaction'] === $token && $event['status'] === 'authorized' ) {
				$auth_event = $event;
			}
			if ( $event['status'] === 'confirmed' ) {
				$confirmed[ $event['transaction'] ] = $event;
			}
		}
		$charges     = isset( $test['charges'] ) ? $test['charges'] : [];
		$charges_ok  = count( array_filter( $charges, function ( $c ) { return $c['ok']; } ) );
		$pre_auth_id = $auth_event ? (string) $auth_event['pre_auth'] : '';

		$checks   = [];
		$checks[] = [ 'Zahlungslink über createZahlsGateway() erstellt', 'ok', sprintf( 'Gateway %d, Bestellung #%d', $test['gateway'], $test['order'] ) ];
		if ( $gateway_error ) {
			$checks[] = [ 'Gateway bei zahls.ch abrufen', 'err', $gateway_error ];
		}
		$checks[] = $token
			? [ 'Zahlung bestätigt: Transaktion mit Status «authorized»', 'ok', 'Transaktion ' . $token . ' = Token für Verlängerungen (laut Payrexx-Doku)' ]
			: [ 'Zahlung bestätigt: Transaktion mit Status «authorized»', 'warn', 'Noch keine. Zahlungsseite öffnen und bestätigen, dann diese Seite neu laden.' ];
		if ( $token ) {
			$checks[] = $auth_event
				? [ 'Webhook «authorized» empfangen und verarbeitet', $auth_event['code'] === 200 && strpos( $auth_event['message'], 'Success' ) === 0 ? 'ok' : 'err', 'Antwort ' . $auth_event['code'] . ': ' . $auth_event['message'] ]
				: [ 'Webhook «authorized» empfangen und verarbeitet', 'err', 'Nicht angekommen. Kommt er bei sofortiger Abbuchung nie, speichert das Plugin bei Abos mit Erstzahlung keinen Token. Webhook-URL bei zahls.ch prüfen.' ];
		}
		if ( $auth_event ) {
			if ( $pre_auth_id === '' ) {
				$checks[] = [ 'preAuthorizationId im Webhook', 'info', 'Nicht mitgeschickt. 2.0.8 sucht das Abo trotzdem, weil der Status «authorized» ist.' ];
			} else {
				$same     = (int) $pre_auth_id === $token;
				$checks[] = [ 'preAuthorizationId im Webhook = Transaktions-ID', $same ? 'ok' : 'warn', $same ? 'Gleich (' . $pre_auth_id . '): Tokens aus 2.0.7 sind dieselbe Nummer.' : 'Verschieden (' . $pre_auth_id . ' ≠ ' . $token . '): Tokens aus 2.0.7 mit der Abbuchung unten prüfen.' ];
			}
		}
		if ( ! empty( $test['charge_on_auth'] ) && $token ) {
			$paid     = $order && $order->is_paid();
			$checks[] = [ 'Erstzahlung sofort abgebucht, Bestellung bezahlt', $paid ? 'ok' : 'warn', $order ? 'Bestellung #' . $order->get_id() . ': ' . wc_get_order_status_name( $order->get_status() ) : '–' ];
		}
		if ( $charges ) {
			$checks[] = [ 'Abbuchung auf den Token (wie Verlängerung)', $charges_ok ? 'ok' : 'err', sprintf( '%d von %d angenommen', $charges_ok, count( $charges ) ) ];
			$checks[] = [ 'Mehrfach abbuchbar', $charges_ok >= 2 ? 'ok' : 'warn', $charges_ok >= 2 ? 'Ja' : 'Für den Test zweimal abbuchen' ];
		}
		$checks[] = [ 'Keine Webhook-Antwort 500 seit Teststart', $errors_500 ? 'err' : 'ok', $errors_500 ? $errors_500 . '× 500 (zahls.ch verschickt Fehlermails), Details im Log' : count( $events ) . ' Webhooks, alle ohne 500' ];

		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		foreach ( $checks as $check ) {
			printf( '<tr><td style="width:380px"><strong>%s</strong></td><td style="width:70px">%s</td><td>%s</td></tr>', esc_html( $check[0] ), $this->badge( $check[1] ), esc_html( $check[2] ) );
		}
		echo '</tbody></table>';

		echo '<p>';
		if ( ! $token ) {
			printf( '<a class="button button-primary" href="%s" target="_blank" rel="noopener">Zahlungsseite öffnen</a> ', esc_url( $test['link'] ) );
		}
		printf( '<a class="button" href="%s">Neu laden</a> ', esc_url( $this->page_url() ) );
		printf( '<a class="button" href="%s" onclick="return confirm(\'Selbsttest zurücksetzen?\')">Zurücksetzen</a></p>', esc_url( $this->action_url( 'selftest_reset' ) ) );

		if ( $token ) {
			$this->render_charge_form( $token, 'Abbuchen auf Token ' . $token . ' (wie Verlängerung)' );
			if ( $pre_auth_id !== '' && (int) $pre_auth_id !== $token ) {
				$this->render_charge_form( (int) $pre_auth_id, 'Abbuchen auf preAuthorizationId ' . $pre_auth_id . ' (Token-Art von 2.0.7)' );
			}
		}

		if ( $transactions ) {
			echo '<h3>Transaktionen dieses Tests bei zahls.ch</h3><table class="widefat striped" style="max-width:1100px"><thead><tr><th>ID</th><th>Status</th><th>Betrag</th><th>Webhook</th><th></th></tr></thead><tbody>';
			$seen = [];
			foreach ( $transactions as $transaction ) {
				$seen[ (string) $transaction['id'] ] = true;
				$this->render_transaction_row( $transaction['id'], $transaction['status'], $transaction['amount'], $events );
			}
			// Charges on a token carry no gateway; they only show up as webhooks.
			foreach ( $confirmed as $id => $event ) {
				if ( empty( $seen[ (string) $id ] ) ) {
					$this->render_transaction_row( $id, $event['status'], $event['amount'], $events );
				}
			}
			echo '</tbody></table>';
		}
	}

	private function render_charge_form( $token, $label ) {
		printf( '<form method="post" action="%s" style="margin:8px 0">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="zahls_dev_selftest_charge">';
		printf( '<input type="hidden" name="token" value="%d">', (int) $token );
		wp_nonce_field( 'zahls_dev_selftest_charge' );
		printf( 'Betrag <input type="text" name="amount" value="1.00" size="6"> %s ', esc_html( get_woocommerce_currency() ) );
		printf( '<button class="button" onclick="return confirm(\'Jetzt bei zahls.ch abbuchen?\')">%s</button></form>', esc_html( $label ) );
	}

	private function render_transaction_row( $id, $status, $amount, array $events ) {
		$webhook = '–';
		foreach ( $events as $event ) {
			if ( (string) $event['transaction'] === (string) $id ) {
				$webhook = $event['status'] . ' → ' . $event['code'];
				break;
			}
		}
		$refund = '';
		if ( $status === 'confirmed' ) {
			$refund = sprintf(
				'<form method="post" action="%s" style="margin:0"><input type="hidden" name="action" value="zahls_dev_selftest_refund"><input type="hidden" name="transaction" value="%d"><input type="hidden" name="amount" value="%s">%s<button class="button button-small" onclick="return confirm(\'Transaktion rückerstatten?\')">Rückerstatten</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				(int) $id,
				esc_attr( number_format( ( (int) $amount ) / 100, 2, '.', '' ) ),
				wp_nonce_field( 'zahls_dev_selftest_refund', '_wpnonce', true, false )
			);
		}
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $id ),
			esc_html( $status ),
			esc_html( number_format( ( (int) $amount ) / 100, 2, '.', '' ) ),
			esc_html( $webhook ),
			$refund
		);
	}

	/* ------------------------------------------------------------------ page */

	public function menu() {
		add_submenu_page( 'woocommerce', 'zahls Dev Dashboard', 'zahls Dev', self::CAP, self::SLUG, [ $this, 'render' ] );
	}

	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		echo '<div class="wrap"><h1>zahls.ch Dev Dashboard</h1>';
		echo '<p>Nur auf Test- und Staging-Shops verwenden. Aktionen lösen echte Anfragen an zahls.ch aus; Verlängerungen buchen echt ab, solange das zahls.ch-Konto nicht im Testmodus ist.</p>';

		$notice = get_transient( 'zahls_dev_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'zahls_dev_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}

		$this->render_environment();
		$this->render_selftest();
		$this->render_subscriptions();
		$this->render_events();
		$this->render_checklist();
		echo '</div>';
	}

	private function render_environment() {
		$gateway  = $this->gateway();
		$settings = get_option( 'woocommerce_zahls_settings', [] );
		$rows     = [];

		$version = defined( 'ZAHLS_VERSION' ) ? ZAHLS_VERSION : '';
		$rows[]  = [ 'zahls.ch-Plugin', $version ?: 'nicht aktiv', $version === '' ? 'err' : ( version_compare( $version, '2.0.8', '>=' ) ? 'ok' : 'err' ), 'Ab 2.0.8 zeichnet das Dashboard Webhooks und Verlängerungen auf.' ];
		$rows[]  = [ 'WordPress / PHP', get_bloginfo( 'version' ) . ' / ' . PHP_VERSION, version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'err', '' ];
		$rows[]  = [ 'WooCommerce', defined( 'WC_VERSION' ) ? WC_VERSION : '–', defined( 'WC_VERSION' ) ? 'ok' : 'err', '' ];

		if ( $this->has_subscriptions() ) {
			$wcs_version = isset( WC_Subscriptions::$version ) ? WC_Subscriptions::$version : 'aktiv';
			$rows[]      = [ 'WooCommerce Subscriptions', $wcs_version, 'ok', '' ];
			$manual      = get_option( 'woocommerce_subscriptions_accept_manual_renewals', 'no' ) === 'yes';
			$rows[]      = [ 'Manuelle Verlängerungen erlaubt', $manual ? 'ja (Checkbox im Checkout)' : 'nein (immer automatisch)', 'info', '' ];
			$off         = get_option( 'woocommerce_subscriptions_turn_off_automatic_payments', 'no' ) === 'yes';
			$rows[]      = [ 'Automatische Zahlungen', $off ? 'ausgeschaltet' : 'eingeschaltet', $off ? 'err' : 'ok', $off ? 'WooCommerce → Einstellungen → Abonnements' : '' ];
		} else {
			$rows[] = [ 'WooCommerce Subscriptions', 'nicht aktiv', 'warn', 'Ohne Subscriptions lassen sich nur Einmalzahlungen testen.' ];
		}

		$hpos   = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$rows[] = [ 'HPOS (Bestelltabellen)', $hpos ? 'aktiv' : 'aus (Beiträge)', 'info', 'Beide Varianten testen, wenn möglich.' ];

		$checkout_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
		$is_block    = $checkout_id > 0 && has_block( 'woocommerce/checkout', $checkout_id );
		$rows[]      = [ 'Kassenseite', $checkout_id > 0 ? ( $is_block ? 'Block-Checkout' : 'Klassischer Checkout (Shortcode)' ) : 'keine', $checkout_id > 0 ? 'info' : 'err', 'Zum Wechseln die Kassenseite bearbeiten.' ];

		if ( $gateway ) {
			$rows[] = [ 'Gateway aktiviert', $gateway->enabled === 'yes' ? 'ja' : 'nein', $gateway->enabled === 'yes' ? 'ok' : 'err', '' ];
			$rows[] = [ 'Instanz', $gateway->instance ?: '–', $gateway->instance ? 'ok' : 'err', '' ];
			$key    = (string) $gateway->apiKey;
			$rows[] = [ 'API-Schlüssel', $key !== '' ? '••••' . substr( $key, -4 ) : '–', $key !== '' ? 'ok' : 'err', '' ];
			$rows[] = [ 'Präfix', $gateway->prefix ?: '–', 'info', '' ];
			$subs   = isset( $settings['subscriptions_enabled'] ) && $settings['subscriptions_enabled'] === 'yes';
			$rows[] = [ 'Abos im Gateway', $subs ? 'aktiviert' : 'deaktiviert', $subs ? 'ok' : ( $this->has_subscriptions() ? 'err' : 'info' ), '' ];
		} else {
			$rows[] = [ 'Gateway', 'nicht geladen', 'err', '' ];
		}

		$api_ok = get_option( 'zahls_dev_api_ok' );
		$rows[] = [
			'API-Verbindung',
			$api_ok ? 'OK, geprüft ' . wp_date( 'd.m.Y H:i', (int) $api_ok ) : 'nicht geprüft',
			$api_ok ? 'ok' : 'warn',
			'<a class="button button-small" href="' . esc_url( $this->action_url( 'api_check' ) ) . '">Jetzt prüfen</a>',
		];
		$rows[] = [ 'Webhook-URL', '<code>' . esc_html( get_site_url() . '/?wc-api=wc_zahls_gateway' ) . '</code>', 'info', 'Im zahls.ch-Backend genau einmal eintragen (zwei Webhooks erzeugen doppelte Ereignisse).' ];
		$rows[] = [ 'WP-Cron', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'deaktiviert' : 'aktiv', defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'warn' : 'ok', 'Geplante Verlängerungen laufen über den Action Scheduler.' ];

		echo '<h2>Umgebung</h2><table class="widefat striped" style="max-width:1100px"><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td style="width:220px"><strong>%s</strong></td><td style="width:70px">%s</td><td>%s</td><td style="color:#646970">%s</td></tr>',
				esc_html( $row[0] ),
				$this->badge( $row[2] ),
				wp_kses_post( $row[1] ),
				wp_kses_post( $row[3] )
			);
		}
		echo '</tbody></table>';
		printf(
			'<p><a href="%s">WooCommerce-Logs (Quelle «zahls»)</a> · <a href="%s">Geplante Aktionen: Verlängerungen</a></p>',
			esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=zahls' ) ),
			esc_url( admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=woocommerce_scheduled_subscription_payment' ) )
		);
	}

	private function render_subscriptions() {
		echo '<h2>Abos mit zahls.ch</h2>';
		if ( ! $this->has_subscriptions() ) {
			echo '<p>WooCommerce Subscriptions ist nicht aktiv.</p>';
			return;
		}
		$subscriptions = wcs_get_subscriptions( [
			'subscriptions_per_page' => 50,
			'subscription_status'    => 'any',
			'payment_method'         => 'zahls',
			'orderby'                => 'start_date',
			'order'                  => 'DESC',
		] );
		$subscriptions = array_filter( $subscriptions, function ( $subscription ) {
			return $subscription->get_payment_method() === 'zahls';
		} );
		if ( ! $subscriptions ) {
			echo '<p>Noch keine Abos mit zahls.ch. Ein Abo-Produkt im Shop kaufen (Testplan Schritt 4).</p>';
			return;
		}
		$results = get_option( 'zahls_dev_token_results', [] );
		echo '<table class="widefat striped" style="max-width:1400px"><thead><tr><th>Abo</th><th>Status</th><th>Verlängerung</th><th>Token</th><th>Token bei zahls.ch</th><th>Nächste Zahlung</th><th>Letzte Bestellung</th><th>Aktionen</th></tr></thead><tbody>';
		foreach ( $subscriptions as $subscription ) {
			$id         = $subscription->get_id();
			$token      = (string) $subscription->get_meta( 'zahls_auth_transaction_id' );
			$manual     = $subscription->is_manual();
			$last       = $subscription->get_last_order( 'all', 'any' );
			$next       = $subscription->get_date( 'next_payment' );
			$result     = isset( $results[ $id ] ) ? $results[ $id ] : null;
			$renewal    = $this->action_url( 'renewal', [ 'subscription' => $id ] );
			$token_link = $this->action_url( 'token_check', [ 'subscription' => $id ] );
			printf(
				'<tr><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Token prüfen</a> <a class="button button-small" href="%s" onclick="return confirm(\'Verlängerung für Abo #%d jetzt auslösen? Das bucht bei zahls.ch ab.\')">Verlängerung auslösen</a></td></tr>',
				esc_url( $subscription->get_edit_order_url() ),
				$id,
				esc_html( wcs_get_subscription_status_name( $subscription->get_status() ) ),
				$manual ? $this->badge( 'warn', 'manuell' ) : $this->badge( 'ok', 'automatisch' ),
				$token !== '' ? '<code>' . esc_html( $token ) . '</code>' : $this->badge( 'err', 'fehlt' ),
				$result ? $this->badge( $result['state'] ) . ' ' . esc_html( $result['text'] ) : '<span style="color:#646970">nicht geprüft</span>',
				esc_html( $next ? wp_date( 'd.m.Y H:i', strtotime( $next ) ) : '–' ),
				$last ? sprintf( '<a href="%s">#%d</a> %s', esc_url( $last->get_edit_order_url() ), $last->get_id(), esc_html( wc_get_order_status_name( $last->get_status() ) ) ) : '–',
				esc_url( $token_link ),
				esc_url( $renewal ),
				$id
			);
		}
		echo '</tbody></table>';
		echo '<p style="color:#646970">«Verlängerung auslösen» entspricht «Process renewal» im Abo: WooCommerce legt eine Verlängerungsbestellung an und das Plugin bucht auf den Token ab. Bezahlt wird die Bestellung erst durch den Webhook.</p>';
	}

	private function render_events() {
		$events = get_option( self::OPT_EVENTS, [] );
		$events = is_array( $events ) ? $events : [];
		printf(
			'<h2>Ereignisse <a class="button button-small" href="%s">Aktualisieren</a> <a class="button button-small" href="%s">Leeren</a></h2>',
			esc_url( $this->page_url() ),
			esc_url( $this->action_url( 'clear_events' ) )
		);
		if ( ! $events ) {
			echo '<p>Noch keine Webhooks oder Verlängerungen aufgezeichnet.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:1400px"><thead><tr><th>Zeit</th><th>Art</th><th>Ergebnis</th><th>Transaktion / Abo</th><th>Status</th><th>Referenz</th><th>preAuthorizationId / Token</th><th>Betrag</th><th>Meldung</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$is_webhook = $event['type'] === 'webhook';
			$code       = (int) $event['code'];
			$state      = $is_webhook ? ( $code >= 500 ? 'err' : ( $code >= 400 ? 'warn' : 'ok' ) ) : ( $code === 200 ? 'ok' : 'err' );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( wp_date( 'd.m.Y H:i:s', (int) $event['time'] ) ),
				$is_webhook ? 'Webhook' : 'Verlängerung',
				$this->badge( $state, $is_webhook ? (string) $code : ( $code === 200 ? 'OK' : 'Fehler' ) ),
				esc_html( $is_webhook ? $event['transaction'] : '#' . $event['subscription'] ),
				esc_html( $is_webhook ? $event['status'] : '' ),
				esc_html( $is_webhook ? $event['reference'] : '' ),
				esc_html( $is_webhook ? $event['pre_auth'] : $event['token'] ),
				esc_html( $event['amount'] ),
				esc_html( $event['message'] )
			);
		}
		echo '</tbody></table>';
		echo '<p style="color:#646970">Webhook-Beträge kommen in Rappen von zahls.ch. 500 bedeutet: zahls.ch verschickt eine Fehlermail, Details im Log.</p>';
	}

	private function render_checklist() {
		$steps = [
			'env'          => [ 'Umgebung und API-Verbindung grün', 'Abschnitt «Umgebung» oben, API-Verbindung prüfen.' ],
			'selftest'     => [ 'zahls-Selbsttest', 'Abschnitt oben starten, auf der Zahlungsseite bestätigen, zweimal abbuchen. Erwartet: alle Prüfungen grün. Danach rückerstatten.' ],
			'once_block'   => [ 'Einmalzahlung im Block-Checkout', 'Normales Produkt kaufen. Erwartet: Bestellung «In Bearbeitung», Webhooks 200, keine Fehlermail von zahls.ch.' ],
			'once_cancel'  => [ 'Zahlung abbrechen, dann bezahlen', 'Auf der zahls.ch-Seite abbrechen. Erwartet: Warenkorb mit Meldung. Danach erneut bezahlen; kein «Reference mismatch» im Log.' ],
			'once_classic' => [ 'Einmalzahlung im klassischen Checkout', 'Kassenseite auf Shortcode [woocommerce_checkout] umstellen und wiederholen.' ],
			'sub_block'    => [ 'Abo im Block-Checkout, Erstzahlung > 0', 'Erwartet: Webhook «authorized» in den Ereignissen, Abo «automatisch», Token gesetzt, «Token prüfen» zeigt Status authorized und die Referenz der Bestellung.' ],
			'sub_classic'  => [ 'Abo im klassischen Checkout', 'Wie oben im klassischen Checkout.' ],
			'sub_trial'    => [ 'Abo mit Gratis-Testphase (0.–)', 'zahls.ch autorisiert 0.50. Erwartet: Bestellung bezahlt, Token gesetzt.' ],
			'sub_manual'   => [ 'Checkbox abwählen (nur wenn manuelle Verlängerungen erlaubt)', 'Erwartet: Abo «manuell», kein Token.' ],
			'renewal'      => [ 'Verlängerung auslösen', 'In der Abo-Tabelle. Erwartet: Ereignis «Verlängerung OK», danach Webhook «confirmed» 200 und Verlängerungsbestellung bezahlt.' ],
			'renewal_old'  => [ 'Altes Abo aus 2.0.7', 'Token prüfen und Verlängerung auslösen. Zeigt, ob die alten Tokens funktionieren.' ],
			'change_pm'    => [ 'Zahlungsmethode ändern', 'Als Kunde: Mein Konto → Abo → «Zahlungsmethode ändern» mit zahls.ch. Erwartet: neuer Token (authorized), Abo automatisch.' ],
			'failed_pay'   => [ 'Fehlgeschlagene Verlängerung bezahlen', 'Token am Abo löschen, Verlängerung auslösen (schlägt fehl), dann als Kunde bezahlen. Erwartet: Abo wieder aktiv, automatisch, neuer Token.' ],
			'gw_error'     => [ 'Falscher API-Schlüssel', 'Schlüssel kurz verfälschen und bestellen. Erwartet: Fehlermeldung im Checkout statt Fatal Error, Eintrag im Log. Danach zurücksetzen.' ],
			'hpos'         => [ 'HPOS umschalten', 'WooCommerce → Einstellungen → Erweitert → Funktionen. Abo-Kauf und Verlängerung mit der anderen Einstellung wiederholen.' ],
		];
		$done = get_option( self::OPT_CHECKLIST, [] );
		$done = is_array( $done ) ? $done : [];

		echo '<h2>Testplan</h2>';
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="zahls_dev_checklist">';
		wp_nonce_field( 'zahls_dev_checklist' );
		echo '<table class="widefat striped" style="max-width:1100px"><tbody>';
		$i = 0;
		foreach ( $steps as $key => $step ) {
			$i++;
			printf(
				'<tr><td style="width:30px"><input type="checkbox" name="done[]" value="%s" id="zahls-dev-%s" %s></td><td style="width:320px"><label for="zahls-dev-%s"><strong>%d. %s</strong></label></td><td>%s</td></tr>',
				esc_attr( $key ),
				esc_attr( $key ),
				checked( in_array( $key, $done, true ), true, false ),
				esc_attr( $key ),
				$i,
				esc_html( $step[0] ),
				esc_html( $step[1] )
			);
		}
		echo '</tbody></table>';
		printf( '<p>%d von %d erledigt. ', count( array_intersect( array_keys( $steps ), $done ) ), count( $steps ) );
		submit_button( 'Testplan speichern', 'secondary', 'submit', false );
		echo '</p></form>';
	}
}

add_action( 'plugins_loaded', [ 'Zahls_Dev_Dashboard', 'init' ] );
