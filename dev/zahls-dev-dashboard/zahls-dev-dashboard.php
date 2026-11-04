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
	const MAX_EVENTS    = 100;

	public static function init() {
		$self = new self();
		add_action( 'admin_menu', [ $self, 'menu' ], 99 );
		add_action( 'zahls_webhook_response', [ $self, 'record_webhook' ], 10, 3 );
		add_action( 'zahls_renewal_charge', [ $self, 'record_renewal' ], 10, 4 );
		foreach ( [ 'api_check', 'token_check', 'renewal', 'clear_events', 'checklist' ] as $action ) {
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
		return new \ZahlsPaymentGateway\Service\ZahlsApiService( $gateway->instance, $gateway->apiKey, $gateway->platform, $gateway->prefix, $gateway->lookAndFeelId );
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
