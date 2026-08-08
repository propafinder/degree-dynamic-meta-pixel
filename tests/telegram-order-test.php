<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$dmuf_tg_orders = array();
$dmuf_tg_actions = array();

function add_action() {}
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_date( $format ) { return '2026-08-08 12:00:00'; }
function wc_get_order( $order_id ) { global $dmuf_tg_orders; return isset( $dmuf_tg_orders[ $order_id ] ) ? $dmuf_tg_orders[ $order_id ] : null; }
function as_enqueue_async_action( $hook, $args, $group, $unique ) {
	global $dmuf_tg_actions;
	$dmuf_tg_actions[] = array( 'kind' => 'async', 'hook' => $hook, 'args' => $args, 'group' => $group, 'unique' => $unique );
	return count( $dmuf_tg_actions );
}
function as_schedule_single_action( $when, $hook, $args, $group, $unique ) {
	global $dmuf_tg_actions;
	$dmuf_tg_actions[] = array( 'kind' => 'single', 'when' => $when, 'hook' => $hook, 'args' => $args, 'group' => $group, 'unique' => $unique );
	return count( $dmuf_tg_actions );
}

class DMUF_Test_Date {
	private $value;
	public function __construct( $value ) { $this->value = $value; }
	public function date_i18n( $format ) { return $this->value; }
}

class WC_Order {
	private $id;
	private $paid;
	private $meta = array();

	public function __construct( $id, $paid ) {
		$this->id = $id;
		$this->paid = $paid;
		$this->meta['_dmuf_utm_source'] = 'GrowFlex';
	}

	public function get_id() { return $this->id; }
	public function is_paid() { return $this->paid; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
	public function save_meta_data() {}
	public function get_order_number() { return (string) $this->id; }
	public function get_total() { return '45.99'; }
	public function get_currency() { return 'GBP'; }
	public function get_date_paid() { return new DMUF_Test_Date( '2026-08-08 11:59:00' ); }
	public function get_date_created() { return new DMUF_Test_Date( '2026-08-08 11:30:00' ); }
}

class DMUF_Settings {
	public function telegram_ready() { return true; }
	public function telegram_config() {
		return array( 'enabled' => true, 'bot_token' => 'token', 'chat_id' => '123', 'store_label' => 'GreenRoute', 'unpaid_minutes' => 30 );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-dmuf-telegram.php';

class DMUF_Test_Telegram extends DMUF_Telegram {
	public $send_calls = 0;
	public $last_text = '';

	public function send_text( $text ) {
		$this->send_calls++;
		$this->last_text = $text;
		return array( 'ok' => true, 'message' => '' );
	}
}

function dmuf_tg_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$settings = new DMUF_Settings();
$telegram = new DMUF_Test_Telegram( $settings );
$paid = new WC_Order( 70001, true );
$unpaid = new WC_Order( 70002, false );
$dmuf_tg_orders[70001] = $paid;
$dmuf_tg_orders[70002] = $unpaid;

$telegram->queue_paid( 70001 );
$telegram->queue_paid( 70001 );
dmuf_tg_assert( 1 === count( $dmuf_tg_actions ), 'paid order should be queued once' );
dmuf_tg_assert( array( 'paid', 70001, 0 ) === $dmuf_tg_actions[0]['args'], 'paid queue should contain the correct order and type' );
$telegram->send_queued_order( 'paid', 70001, 0 );
$telegram->send_queued_order( 'paid', 70001, 0 );
dmuf_tg_assert( 1 === $telegram->send_calls, 'paid Telegram message should be delivered once' );

$telegram->schedule_unpaid_check( $unpaid );
$telegram->schedule_unpaid_check( $unpaid );
dmuf_tg_assert( 2 === count( $dmuf_tg_actions ), 'unpaid check should be scheduled once' );
dmuf_tg_assert( DMUF_Telegram::UNPAID_CHECK_HOOK === $dmuf_tg_actions[1]['hook'], 'unpaid check should use its dedicated hook' );
$telegram->queue_unpaid( 70002 );
$telegram->queue_unpaid( 70002 );
dmuf_tg_assert( 3 === count( $dmuf_tg_actions ), 'unpaid order should be queued once' );
$telegram->send_queued_order( 'unpaid', 70002, 0 );
$telegram->send_queued_order( 'unpaid', 70002, 0 );
dmuf_tg_assert( 2 === $telegram->send_calls, 'unpaid Telegram message should be delivered once' );

$message = new ReflectionMethod( 'DMUF_Telegram', 'order_message' );
$message->setAccessible( true );
$paid_text = $message->invoke( $telegram, $paid, 'paid' );
$unpaid_text = $message->invoke( $telegram, $unpaid, 'unpaid' );

dmuf_tg_assert( false !== strpos( $paid_text, 'ОПЛАЧЕНО' ), 'paid message should have one clear outcome' );
dmuf_tg_assert( false !== strpos( $paid_text, 'GreenRoute: ОПЛАЧЕНО' ), 'paid message should identify the store' );
dmuf_tg_assert( false !== strpos( $paid_text, '45.99 GBP' ), 'paid message should include amount and currency' );
dmuf_tg_assert( false !== strpos( $paid_text, '2026-08-08 11:59:00' ), 'paid message should include payment time' );
dmuf_tg_assert( false !== strpos( $unpaid_text, 'НЕ ОПЛАЧЕНО' ), 'unpaid message should have one clear outcome' );
dmuf_tg_assert( false !== strpos( $unpaid_text, 'GreenRoute: НЕ ОПЛАЧЕНО' ), 'unpaid message should identify the store' );
dmuf_tg_assert( false !== strpos( $unpaid_text, '2026-08-08 11:30:00' ), 'unpaid message should include order time' );
dmuf_tg_assert( false === strpos( $paid_text, 'email' ) && false === strpos( $paid_text, 'phone' ), 'message should not contain customer data' );

$test_result = $telegram->send_test_message();
dmuf_tg_assert( ! empty( $test_result['ok'] ), 'test Telegram message should be sent' );
dmuf_tg_assert( false !== strpos( $telegram->last_text, 'GreenRoute: ТЕСТ TELEGRAM' ), 'test message should identify the store' );

fwrite( STDOUT, "PASS: paid/unpaid Telegram queue, store labels, deduplication, amount and timestamps\n" );
