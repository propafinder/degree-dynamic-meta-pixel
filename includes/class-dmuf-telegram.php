<?php

defined( 'ABSPATH' ) || exit;

class DMUF_Telegram {
	const SEND_HOOK         = 'dmuf_telegram_send_order';
	const UNPAID_CHECK_HOOK = 'dmuf_telegram_unpaid_check';
	const GROUP             = 'dmuf-telegram';

	private $settings;

	public function __construct( DMUF_Settings $settings ) {
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'woocommerce_checkout_order_created', array( $this, 'schedule_unpaid_check' ), 40, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'schedule_unpaid_check' ), 40, 1 );

		add_action( 'woocommerce_payment_complete', array( $this, 'queue_paid' ), 40, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'queue_paid' ), 40, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'queue_paid' ), 40, 1 );

		add_action( 'woocommerce_order_status_failed', array( $this, 'queue_unpaid' ), 40, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'queue_unpaid' ), 40, 1 );

		add_action( self::SEND_HOOK, array( $this, 'send_queued_order' ), 10, 3 );
		add_action( self::UNPAID_CHECK_HOOK, array( $this, 'check_unpaid_order' ), 10, 1 );
	}

	public function schedule_unpaid_check( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $this->is_attributed_order( $order ) || ! $this->settings->telegram_ready() ) {
			return;
		}

		if ( $order->get_meta( '_dmuf_tg_unpaid_check_scheduled', true ) ) {
			return;
		}

		$config = $this->settings->telegram_config();
		$when   = time() + $config['unpaid_minutes'] * MINUTE_IN_SECONDS;
		$args   = array( $order->get_id() );
		$scheduled = $this->schedule_action( $when, self::UNPAID_CHECK_HOOK, $args );

		if ( $scheduled ) {
			$order->update_meta_data( '_dmuf_tg_unpaid_check_scheduled', gmdate( 'Y-m-d H:i:s', $when ) );
			$order->save_meta_data();
		}
	}

	public function check_unpaid_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $this->is_attributed_order( $order ) || $order->is_paid() ) {
			return;
		}

		$this->queue_order( 'unpaid', $order );
	}

	public function queue_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $this->is_attributed_order( $order ) || ! $order->is_paid() ) {
			return;
		}

		$this->queue_order( 'paid', $order );
	}

	public function queue_unpaid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $this->is_attributed_order( $order ) || $order->is_paid() ) {
			return;
		}

		$this->queue_order( 'unpaid', $order );
	}

	public function send_queued_order( $type, $order_id, $attempt = 0 ) {
		$type    = 'paid' === $type ? 'paid' : 'unpaid';
		$order   = wc_get_order( $order_id );
		$attempt = absint( $attempt );

		if ( ! $this->is_attributed_order( $order ) || ! $this->settings->telegram_ready() ) {
			return;
		}

		if ( 'paid' === $type && ! $order->is_paid() ) {
			$this->clear_queued_marker( $order, $type );
			return;
		}

		if ( 'unpaid' === $type && $order->is_paid() ) {
			$this->clear_queued_marker( $order, $type );
			return;
		}

		$sent_key = '_dmuf_tg_' . $type . '_sent';
		if ( $order->get_meta( $sent_key, true ) ) {
			return;
		}

		$result = $this->send_text( $this->order_message( $order, $type ) );
		if ( $result['ok'] ) {
			$order->update_meta_data( $sent_key, gmdate( 'Y-m-d H:i:s' ) );
			$order->delete_meta_data( '_dmuf_tg_' . $type . '_queued' );
			$order->delete_meta_data( '_dmuf_tg_' . $type . '_error' );
			$order->save_meta_data();
			return;
		}

		$order->update_meta_data( '_dmuf_tg_' . $type . '_error', substr( sanitize_text_field( $result['message'] ), 0, 300 ) );
		$order->save_meta_data();

		if ( $attempt < 4 ) {
			$scheduled = $this->schedule_action( time() + MINUTE_IN_SECONDS * ( 2 ** $attempt ), self::SEND_HOOK, array( $type, $order->get_id(), $attempt + 1 ) );
			if ( ! $scheduled ) {
				$this->clear_queued_marker( $order, $type );
			}
		} else {
			$this->clear_queued_marker( $order, $type );
		}
	}

	public function send_test_message() {
		if ( ! $this->settings->telegram_ready() ) {
			return array( 'ok' => false, 'message' => 'Telegram выключен или не заполнены Bot Token и Chat ID.' );
		}

		$config  = $this->settings->telegram_config();
		$store   = isset( $config['store_label'] ) ? trim( (string) $config['store_label'] ) : '';
		$heading = '' !== $store ? $store . ': ТЕСТ TELEGRAM' : 'ТЕСТ TELEGRAM';
		$text    = '<b>' . esc_html( $heading ) . "</b>\nDynamic Meta Pixel подключён корректно.";
		return $this->send_text( $text );
	}

	public function send_text( $text ) {
		$config = $this->settings->telegram_config();
		if ( ! $config['enabled'] || '' === $config['bot_token'] || '' === $config['chat_id'] ) {
			return array( 'ok' => false, 'message' => 'Telegram is not configured.' );
		}

		$body = array(
			'chat_id'                  => $config['chat_id'],
			'text'                     => substr( (string) $text, 0, 4096 ),
			'parse_mode'               => 'HTML',
		);
		$response = wp_remote_post(
			'https://api.telegram.org/bot' . $config['bot_token'] . '/sendMessage',
			array(
				'timeout' => 5,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => $response->get_error_message() );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) && ! empty( $decoded['ok'] ) ) {
			return array( 'ok' => true, 'message' => '' );
		}

		$message = isset( $decoded['description'] ) ? sanitize_text_field( $decoded['description'] ) : 'Telegram API rejected the message.';
		return array( 'ok' => false, 'message' => $message );
	}

	private function queue_order( $type, WC_Order $order ) {
		if ( ! $this->settings->telegram_ready() ) {
			return;
		}

		$sent_key   = '_dmuf_tg_' . $type . '_sent';
		$queued_key = '_dmuf_tg_' . $type . '_queued';
		if ( $order->get_meta( $sent_key, true ) || $order->get_meta( $queued_key, true ) ) {
			return;
		}

		$order->update_meta_data( $queued_key, gmdate( 'Y-m-d H:i:s' ) );
		$order->save_meta_data();
		$scheduled = $this->enqueue_action( self::SEND_HOOK, array( $type, $order->get_id(), 0 ) );
		if ( ! $scheduled ) {
			$this->clear_queued_marker( $order, $type );
		}
	}

	private function enqueue_action( $hook, $args ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$scheduled = as_enqueue_async_action( $hook, $args, self::GROUP, true );
			if ( $scheduled ) {
				return $scheduled;
			}
		}

		return wp_schedule_single_event( time() + 1, $hook, $args );
	}

	private function schedule_action( $when, $hook, $args ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$scheduled = as_schedule_single_action( $when, $hook, $args, self::GROUP, true );
			if ( $scheduled ) {
				return $scheduled;
			}
		}

		return wp_schedule_single_event( $when, $hook, $args );
	}

	private function is_attributed_order( $order ) {
		return $order instanceof WC_Order && '' !== (string) $order->get_meta( '_dmuf_utm_source', true );
	}

	private function clear_queued_marker( WC_Order $order, $type ) {
		$order->delete_meta_data( '_dmuf_tg_' . $type . '_queued' );
		$order->save_meta_data();
	}

	private function order_message( WC_Order $order, $type ) {
		$is_paid    = 'paid' === $type;
		$config     = $this->settings->telegram_config();
		$store      = isset( $config['store_label'] ) ? trim( (string) $config['store_label'] ) : '';
		$date       = $is_paid ? $order->get_date_paid() : $order->get_date_created();
		$label      = $is_paid ? 'ОПЛАЧЕНО' : 'НЕ ОПЛАЧЕНО';
		$time_label = $is_paid ? 'Время оплаты' : 'Время заказа';
		$time       = $date ? $date->date_i18n( 'Y-m-d H:i:s' ) : wp_date( 'Y-m-d H:i:s' );
		$amount     = number_format( (float) $order->get_total(), 2, '.', ' ' ) . ' ' . $order->get_currency();

		$heading = '' !== $store ? $store . ': ' . $label : $label;

		return '<b>' . esc_html( $heading ) . "</b>\n"
			. 'Заказ: <code>#' . esc_html( $order->get_order_number() ) . "</code>\n"
			. 'UTM: <code>' . esc_html( $order->get_meta( '_dmuf_utm_source', true ) ) . "</code>\n"
			. 'Сумма: <b>' . esc_html( $amount ) . "</b>\n"
			. $time_label . ': <code>' . esc_html( $time ) . '</code>';
	}
}
