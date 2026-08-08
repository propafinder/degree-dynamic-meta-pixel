<?php

defined( 'ABSPATH' ) || exit;

class DMUF_Meta_Client {
	const GRAPH_VERSION = 'v22.0';

	private $settings;

	public function __construct( DMUF_Settings $settings ) {
		$this->settings = $settings;
	}

	public function send_checkout( $session, $context ) {
		$rule = $this->settings->find_rule( $session->utm_source );
		if ( ! $rule ) {
			return $this->result( false, 'no_rule', 'UTM source has no active rule.' );
		}

		$event = array(
			'event_name'       => 'InitiateCheckout',
			'event_time'       => time(),
			'event_id'         => $session->checkout_event_id,
			'action_source'    => 'website',
			'event_source_url' => wc_get_checkout_url(),
			'user_data'        => $this->anonymous_user_data( $context ),
			'custom_data'      => array(
				'currency'   => isset( $context['currency'] ) ? (string) $context['currency'] : get_woocommerce_currency(),
				'value'      => isset( $context['value'] ) ? (float) $context['value'] : 0.0,
				'utm_source' => (string) $session->utm_source,
			),
		);

		return $this->send( $rule, $event );
	}

	public function send_purchase( WC_Order $order ) {
		$source = (string) $order->get_meta( '_dmuf_utm_source', true );
		$rule   = $this->settings->find_rule( $source );
		if ( ! $rule ) {
			return $this->result( false, 'no_rule', 'Order source has no active rule.' );
		}

		$event_id = (string) $order->get_meta( '_dmuf_purchase_event_id', true );
		if ( '' === $event_id ) {
			$event_id = 'dmuf_purchase_' . $order->get_id();
			$order->update_meta_data( '_dmuf_purchase_event_id', $event_id );
			$order->save_meta_data();
		}

		$event = array(
			'event_name'       => 'Purchase',
			'event_time'       => $this->purchase_time( $order ),
			'event_id'         => $event_id,
			'action_source'    => 'website',
			'event_source_url' => wc_get_checkout_url(),
			'user_data'        => $this->order_user_data( $order ),
			'custom_data'      => $this->purchase_data( $order, $source ),
		);

		return $this->send( $rule, $event );
	}

	private function send( $rule, $event ) {
		$token = isset( $rule['access_token'] ) ? trim( (string) $rule['access_token'] ) : '';
		if ( '' === $token ) {
			return $this->result( false, 'not_configured', 'Conversions API token is empty.' );
		}

		$body = array( 'data' => array( $event ) );
		if ( ! empty( $rule['test_event_code'] ) ) {
			$body['test_event_code'] = (string) $rule['test_event_code'];
		}

		$pixel_id = preg_replace( '/\D+/', '', (string) $rule['pixel_id'] );
		$url      = sprintf(
			'https://graph.facebook.com/%1$s/%2$s/events',
			apply_filters( 'dmuf_meta_graph_version', self::GRAPH_VERSION ),
			$pixel_id
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->result( false, 'transport_error', $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 200 && $code < 300 && ! isset( $decoded['error'] ) ) {
			$trace_id = isset( $decoded['fbtrace_id'] ) ? sanitize_text_field( $decoded['fbtrace_id'] ) : '';
			return $this->result( true, 'sent', $trace_id );
		}

		$message = isset( $decoded['error']['message'] ) ? sanitize_text_field( $decoded['error']['message'] ) : 'Meta returned HTTP ' . $code;
		return $this->result( false, 'api_error', $message );
	}

	private function anonymous_user_data( $context ) {
		$data = array(
			'client_ip_address' => isset( $context['ip'] ) ? $this->clean_string( $context['ip'], 100 ) : '',
			'client_user_agent' => isset( $context['user_agent'] ) ? $this->clean_string( $context['user_agent'], 500 ) : '',
			'fbp'               => isset( $context['fbp'] ) ? $this->clean_cookie( $context['fbp'] ) : '',
			'fbc'               => isset( $context['fbc'] ) ? $this->clean_cookie( $context['fbc'] ) : '',
		);

		return array_filter( $data );
	}

	private function order_user_data( WC_Order $order ) {
		$data = array(
			'em'                => $this->hash_value( $order->get_billing_email() ),
			'ph'                => $this->hash_phone( $order->get_billing_phone() ),
			'fn'                => $this->hash_value( $order->get_billing_first_name() ),
			'ln'                => $this->hash_value( $order->get_billing_last_name() ),
			'ct'                => $this->hash_value( $order->get_billing_city() ),
			'st'                => $this->hash_value( $order->get_billing_state() ),
			'zp'                => $this->hash_value( $order->get_billing_postcode() ),
			'country'           => $this->hash_value( $order->get_billing_country() ),
			'external_id'       => $this->hash_value( $order->get_customer_id() ? (string) $order->get_customer_id() : $order->get_billing_email() ),
			'client_ip_address' => $this->clean_string( $order->get_customer_ip_address(), 100 ),
			'client_user_agent' => $this->clean_string( $order->get_customer_user_agent(), 500 ),
			'fbp'               => $this->clean_cookie( $order->get_meta( '_dmuf_fbp', true ) ),
			'fbc'               => $this->clean_cookie( $order->get_meta( '_dmuf_fbc', true ) ),
		);

		return array_filter( $data );
	}

	private function purchase_data( WC_Order $order, $source ) {
		$contents    = array();
		$content_ids = array();

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product_id    = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$content_ids[] = (string) $product_id;
			$contents[]    = array(
				'id'         => (string) $product_id,
				'quantity'   => (int) $item->get_quantity(),
				'item_price' => (float) $order->get_item_total( $item, false, false ),
			);
		}

		return array(
			'currency'     => $order->get_currency(),
			'value'        => (float) $order->get_total(),
			'order_id'     => (string) $order->get_id(),
			'content_type' => 'product',
			'content_ids'  => array_values( array_unique( $content_ids ) ),
			'contents'     => $contents,
			'utm_source'   => (string) $source,
		);
	}

	private function purchase_time( WC_Order $order ) {
		$date = $order->get_date_paid();
		return $date ? $date->getTimestamp() : time();
	}

	private function hash_value( $value ) {
		$value = trim( strtolower( (string) $value ) );
		return '' === $value ? '' : hash( 'sha256', $value );
	}

	private function hash_phone( $value ) {
		$value = preg_replace( '/\D+/', '', (string) $value );
		return '' === $value ? '' : hash( 'sha256', $value );
	}

	private function clean_cookie( $value ) {
		$value = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value );
		return substr( $value, 0, 255 );
	}

	private function clean_string( $value, $length ) {
		return substr( sanitize_text_field( (string) $value ), 0, $length );
	}

	private function result( $ok, $code, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'code'    => (string) $code,
			'message' => (string) $message,
		);
	}
}
