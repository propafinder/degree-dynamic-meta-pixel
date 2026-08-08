<?php

defined( 'ABSPATH' ) || exit;

class DMUF_WooCommerce {
	private $settings;
	private $attribution;
	private $meta;

	public function __construct( DMUF_Settings $settings, DMUF_Attribution $attribution, DMUF_Meta_Client $meta ) {
		$this->settings    = $settings;
		$this->attribution = $attribution;
		$this->meta        = $meta;
	}

	public function register() {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'capture_order' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'capture_store_api_order' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_created', array( $this, 'link_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'link_order' ), 20, 1 );

		add_action( 'woocommerce_payment_complete', array( $this, 'send_purchase' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'send_purchase' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'send_purchase' ), 20, 1 );
		add_action( 'dmuf_retry_purchase', array( $this, 'send_purchase' ), 10, 1 );

		add_action( 'woocommerce_thankyou', array( $this, 'render_browser_purchase' ), 30, 1 );
	}

	public function capture_order( $order, $data = array() ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( '_dmuf_utm_source', true ) ) {
			return;
		}

		$session = $this->attribution->current_session();
		if ( ! $session || ! $this->settings->find_rule( $session->utm_source ) ) {
			return;
		}

		$order->update_meta_data( '_dmuf_session_key', (string) $session->session_key );
		$order->update_meta_data( '_dmuf_utm_source', (string) $session->utm_source );
		$order->update_meta_data( '_dmuf_utm_medium', (string) $session->utm_medium );
		$order->update_meta_data( '_dmuf_utm_campaign', (string) $session->utm_campaign );
		$order->update_meta_data( '_dmuf_utm_content', (string) $session->utm_content );
		$order->update_meta_data( '_dmuf_utm_term', (string) $session->utm_term );
		$order->update_meta_data( '_dmuf_utm_landing_url', (string) $session->landing_url );
		$order->update_meta_data( '_dmuf_utm_first_seen', (string) $session->first_seen );
		$order->update_meta_data( '_dmuf_attribution_expires', (string) $session->expires_at );
		$order->update_meta_data( '_dmuf_fbp', $this->cookie_value( '_fbp' ) );
		$order->update_meta_data( '_dmuf_fbc', $this->cookie_value( '_fbc' ) );
	}

	public function capture_store_api_order( $order ) {
		$this->capture_order( $order, array() );
	}

	public function link_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$session_key = (string) $order->get_meta( '_dmuf_session_key', true );
		if ( '' === $session_key ) {
			return;
		}

		$event_id = (string) $order->get_meta( '_dmuf_purchase_event_id', true );
		if ( '' === $event_id ) {
			$order->update_meta_data( '_dmuf_purchase_event_id', 'dmuf_purchase_' . $order->get_id() );
			$order->save_meta_data();
		}

		$this->attribution->link_order( $session_key, $order->get_id() );
	}

	public function send_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() || ! $order->get_meta( '_dmuf_utm_source', true ) ) {
			return;
		}

		if ( $order->get_meta( '_dmuf_purchase_capi_sent', true ) ) {
			return;
		}

		$attempts = absint( $order->get_meta( '_dmuf_purchase_capi_attempts', true ) ) + 1;
		$result   = $this->meta->send_purchase( $order );

		$order->update_meta_data( '_dmuf_purchase_capi_attempts', $attempts );
		$order->update_meta_data( '_dmuf_purchase_capi_status', sanitize_key( $result['code'] ) );
		$order->update_meta_data( '_dmuf_purchase_capi_message', substr( sanitize_text_field( $result['message'] ), 0, 500 ) );

		if ( $result['ok'] ) {
			$order->update_meta_data( '_dmuf_purchase_capi_sent', gmdate( 'Y-m-d H:i:s' ) );
			$order->save_meta_data();
			return;
		}

		$order->save_meta_data();

		if ( $attempts < 5 && ! in_array( $result['code'], array( 'not_configured', 'no_rule' ), true ) ) {
			$delay = 5 * MINUTE_IN_SECONDS * ( 2 ** ( $attempts - 1 ) );
			$args  = array( $order->get_id() );
			if ( ! wp_next_scheduled( 'dmuf_retry_purchase', $args ) ) {
				wp_schedule_single_event( time() + $delay, 'dmuf_retry_purchase', $args );
			}
		}
	}

	public function render_browser_purchase( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->is_paid() ) {
			return;
		}

		$source = (string) $order->get_meta( '_dmuf_utm_source', true );
		$rule   = $this->settings->find_rule( $source );
		if ( ! $rule ) {
			return;
		}

		$event_id = (string) $order->get_meta( '_dmuf_purchase_event_id', true );
		if ( '' === $event_id ) {
			$event_id = 'dmuf_purchase_' . $order->get_id();
			$order->update_meta_data( '_dmuf_purchase_event_id', $event_id );
			$order->save_meta_data();
		}

		$payload = array(
			'pixelId'  => (string) $rule['pixel_id'],
			'eventId'  => $event_id,
			'value'    => (float) $order->get_total(),
			'currency' => $order->get_currency(),
		);
		?>
		<script id="dmuf-purchase-event">
		(function (d) {
			if (!window.fbq) {
				window.fbq = function () { window.fbq.callMethod ? window.fbq.callMethod.apply(window.fbq, arguments) : window.fbq.queue.push(arguments); };
				if (!window._fbq) { window._fbq = window.fbq; }
				window.fbq.push = window.fbq;
				window.fbq.loaded = true;
				window.fbq.version = '2.0';
				window.fbq.queue = [];
				var s = document.createElement('script');
				s.async = true;
				s.src = 'https://connect.facebook.net/en_US/fbevents.js';
				var first = document.getElementsByTagName('script')[0];
				first.parentNode.insertBefore(s, first);
			}
			window.fbq('init', String(d.pixelId));
			window.fbq('trackSingle', String(d.pixelId), 'Purchase', {value: Number(d.value), currency: String(d.currency)}, {eventID: String(d.eventId)});
		}(<?php echo wp_json_encode( $payload ); ?>));
		</script>
		<?php
	}

	private function cookie_value( $name ) {
		$value = isset( $_COOKIE[ $name ] ) ? wp_unslash( $_COOKIE[ $name ] ) : '';
		$value = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value );
		return substr( $value, 0, 255 );
	}
}
