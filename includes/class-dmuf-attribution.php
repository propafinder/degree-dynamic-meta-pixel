<?php

defined( 'ABSPATH' ) || exit;

class DMUF_Attribution {
	const COOKIE_NAME = 'dmuf_sid';
	const REST_NS     = 'dmuf/v1';

	private $settings;
	private $meta;

	public function __construct( DMUF_Settings $settings, DMUF_Meta_Client $meta ) {
		$this->settings = $settings;
		$this->meta     = $meta;
	}

	public function register() {
		add_action( 'init', array( $this, 'capture_server_side' ), 2 );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracker' ) );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'dmuf_sessions';
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NS,
			'/capture',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_capture' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NS,
			'/checkout-start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_checkout_start' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function capture_server_side() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || empty( $_GET['utm_source'] ) ) {
			return;
		}

		$values = $this->values_from_array( wp_unslash( $_GET ) );
		$url    = isset( $_SERVER['REQUEST_URI'] ) ? home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
		$this->capture( $values, $url );
	}

	public function enqueue_tracker() {
		if ( empty( $this->settings->rules() ) ) {
			return;
		}

		wp_enqueue_script(
			'dmuf-tracker',
			DMUF_URL . 'assets/js/tracker.js',
			array(),
			DMUF_VERSION,
			true
		);

		wp_localize_script(
			'dmuf-tracker',
			'dmufTracker',
			array(
				'captureUrl' => esc_url_raw( rest_url( self::REST_NS . '/capture' ) ),
				'checkoutUrl' => esc_url_raw( rest_url( self::REST_NS . '/checkout-start' ) ),
				'isCheckout' => function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page(),
			)
		);
	}

	public function rest_capture( WP_REST_Request $request ) {
		$values = $this->values_from_array( $request->get_json_params() );
		$url    = esc_url_raw( (string) $request->get_param( 'landing_url' ) );

		if ( ! $this->is_local_url( $url ) ) {
			$url = home_url( '/' );
		}

		$session = $this->capture( $values, $url );
		$response = rest_ensure_response(
			array(
				'attributed' => (bool) $session,
				'source'     => $session ? $session->utm_source : '',
			)
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	public function rest_checkout_start( WP_REST_Request $request ) {
		$page_url = esc_url_raw( (string) $request->get_param( 'page_url' ) );
		if ( ! $this->is_checkout_url( $page_url ) ) {
			return new WP_Error( 'dmuf_not_checkout', 'Checkout event rejected outside the checkout URL.', array( 'status' => 400 ) );
		}

		$session = $this->current_session();
		if ( ! $session ) {
			return rest_ensure_response( array( 'attributed' => false, 'track' => false ) );
		}

		global $wpdb;
		$event_id = $session->checkout_event_id ? $session->checkout_event_id : 'dmuf_checkout_' . str_replace( '-', '', wp_generate_uuid4() );
		$now      = gmdate( 'Y-m-d H:i:s' );
		$is_first = false;
		$value    = function_exists( 'WC' ) && WC()->cart ? (float) WC()->cart->get_total( 'edit' ) : 0.0;
		$currency = get_woocommerce_currency();

		if ( empty( $session->checkout_started_at ) ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . self::table_name() . ' SET checkout_started_at = %s, checkout_event_id = %s, checkout_value = %f, checkout_currency = %s, last_seen = %s WHERE id = %d AND checkout_started_at IS NULL',
					$now,
					$event_id,
					$value,
					$currency,
					$now,
					$session->id
				)
			);
			$is_first = 1 === (int) $updated;
		}

		$session = $this->get_session( $session->session_key );
		$rule    = $session ? $this->settings->find_rule( $session->utm_source ) : null;
		if ( ! $session || ! $rule ) {
			return rest_ensure_response( array( 'attributed' => false, 'track' => false ) );
		}

		if ( $is_first ) {
			$result = $this->meta->send_checkout(
				$session,
				array(
					'value'      => $value,
					'currency'   => $currency,
					'ip'         => class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '',
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '',
					'fbp'        => $request->get_param( 'fbp' ),
					'fbc'        => $request->get_param( 'fbc' ),
				)
			);

			$wpdb->update(
				self::table_name(),
				array( 'checkout_capi_status' => substr( $result['code'], 0, 20 ) ),
				array( 'id' => $session->id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		$response = rest_ensure_response(
			array(
				'attributed' => true,
				'track'      => $is_first,
				'pixelId'    => (string) $rule['pixel_id'],
				'eventId'    => (string) $session->checkout_event_id,
				'value'      => $value,
				'currency'   => $currency,
			)
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}

	public function current_session() {
		$key = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? $this->clean_session_key( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ) : '';
		if ( '' === $key ) {
			return null;
		}

		$session = $this->get_session( $key );
		if ( ! $session || strtotime( $session->expires_at . ' UTC' ) < time() ) {
			return null;
		}

		return $session;
	}

	public function link_order( $session_key, $order_id ) {
		global $wpdb;
		$session_key = $this->clean_session_key( $session_key );
		$order_id    = absint( $order_id );
		if ( '' === $session_key || ! $order_id ) {
			return;
		}

		$wpdb->update(
			self::table_name(),
			array( 'order_id' => $order_id ),
			array( 'session_key' => $session_key ),
			array( '%d' ),
			array( '%s' )
		);
	}

	private function capture( $values, $landing_url ) {
		if ( empty( $values['utm_source'] ) ) {
			return null;
		}

		$rule = $this->settings->find_rule( $values['utm_source'] );
		if ( ! $rule ) {
			$this->clear_cookie();
			return null;
		}

		$values['utm_source'] = (string) $rule['source'];
		$current              = $this->current_session();
		$now                  = gmdate( 'Y-m-d H:i:s' );
		$expires              = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * $this->settings->attribution_days() );

		if ( $current && $this->same_touch( $current, $values ) && strtotime( $current->last_seen . ' UTC' ) >= time() - 10 * MINUTE_IN_SECONDS ) {
			global $wpdb;
			$wpdb->update(
				self::table_name(),
				array( 'last_seen' => $now, 'expires_at' => $expires ),
				array( 'id' => $current->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$this->set_cookie( $current->session_key );
			return $this->get_session( $current->session_key );
		}

		global $wpdb;
		$key = wp_generate_uuid4();
		$wpdb->insert(
			self::table_name(),
			array(
				'session_key' => $key,
				'utm_source'  => $values['utm_source'],
				'utm_medium'  => $values['utm_medium'],
				'utm_campaign'=> $values['utm_campaign'],
				'utm_content' => $values['utm_content'],
				'utm_term'    => $values['utm_term'],
				'landing_url' => substr( esc_url_raw( $landing_url ), 0, 2000 ),
				'first_seen'  => $now,
				'last_seen'   => $now,
				'expires_at'  => $expires,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $wpdb->insert_id ) {
			return null;
		}

		$this->set_cookie( $key );
		return $this->get_session( $key );
	}

	private function get_session( $key ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE session_key = %s LIMIT 1', $key )
		);
	}

	private function values_from_array( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'utm_source'   => $this->clean_utm( isset( $input['utm_source'] ) ? $input['utm_source'] : '', 100 ),
			'utm_medium'   => $this->clean_utm( isset( $input['utm_medium'] ) ? $input['utm_medium'] : '', 100 ),
			'utm_campaign' => $this->clean_utm( isset( $input['utm_campaign'] ) ? $input['utm_campaign'] : '', 191 ),
			'utm_content'  => $this->clean_utm( isset( $input['utm_content'] ) ? $input['utm_content'] : '', 191 ),
			'utm_term'     => $this->clean_utm( isset( $input['utm_term'] ) ? $input['utm_term'] : '', 191 ),
		);
	}

	private function clean_utm( $value, $length ) {
		return substr( sanitize_text_field( (string) $value ), 0, $length );
	}

	private function clean_session_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9-]{36}$/', $value ) ? $value : '';
	}

	private function same_touch( $session, $values ) {
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ) as $field ) {
			$left  = isset( $session->{$field} ) ? (string) $session->{$field} : '';
			$right = isset( $values[ $field ] ) ? (string) $values[ $field ] : '';
			if ( strtolower( $left ) !== strtolower( $right ) ) {
				return false;
			}
		}
		return true;
	}

	private function set_cookie( $key ) {
		$expires = time() + DAY_IN_SECONDS * $this->settings->attribution_days();
		$path    = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain  = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

		setcookie(
			self::COOKIE_NAME,
			$key,
			array(
				'expires'  => $expires,
				'path'     => $path,
				'domain'   => $domain,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE_NAME ] = $key;
	}

	private function clear_cookie() {
		$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

		setcookie(
			self::COOKIE_NAME,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => $path,
				'domain'   => $domain,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	private function is_local_url( $url ) {
		if ( '' === $url ) {
			return false;
		}
		return wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	private function is_checkout_url( $url ) {
		if ( ! $this->is_local_url( $url ) ) {
			return false;
		}

		$actual_path   = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$checkout_path = untrailingslashit( (string) wp_parse_url( wc_get_checkout_url(), PHP_URL_PATH ) );
		return '' !== $checkout_path && ( $actual_path === $checkout_path || 0 === strpos( $actual_path, $checkout_path . '/' ) );
	}
}
