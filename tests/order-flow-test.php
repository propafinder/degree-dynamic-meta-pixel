<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );

$dmuf_orders = array();

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function wp_generate_uuid4() { return '11111111-2222-4333-8444-555555555555'; }
function wc_get_order( $order_id ) { global $dmuf_orders; return isset( $dmuf_orders[ $order_id ] ) ? $dmuf_orders[ $order_id ] : null; }
function wp_next_scheduled() { return false; }
function wp_schedule_single_event() { return true; }
function add_action() {}

class WC_Order {
	private $id;
	private $paid;
	private $meta = array();

	public function __construct( $id, $paid = false ) {
		$this->id = $id;
		$this->paid = $paid;
	}

	public function get_id() { return $this->id; }
	public function is_paid() { return $this->paid; }
	public function get_meta( $key, $single = true ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save_meta_data() {}
}

require_once dirname( __DIR__ ) . '/includes/class-dmuf-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-dmuf-meta-client.php';
require_once dirname( __DIR__ ) . '/includes/class-dmuf-attribution.php';
require_once dirname( __DIR__ ) . '/includes/class-dmuf-woocommerce.php';

class DMUF_Test_Settings extends DMUF_Settings {
	public function find_rule( $source ) {
		return 'CapPrice' === $source ? array( 'source' => 'CapPrice', 'pixel_id' => '123' ) : null;
	}
}

class DMUF_Test_Meta extends DMUF_Meta_Client {
	public $purchase_calls = 0;

	public function send_purchase( WC_Order $order ) {
		$this->purchase_calls++;
		return array( 'ok' => true, 'code' => 'sent', 'message' => 'trace' );
	}
}

class DMUF_Test_Attribution extends DMUF_Attribution {
	public $session;
	public $linked = array();

	public function current_session() { return $this->session; }
	public function link_order( $session_key, $order_id ) { $this->linked = array( $session_key, $order_id ); }
}

function dmuf_flow_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$settings = new DMUF_Test_Settings();
$meta = new DMUF_Test_Meta( $settings );
$attribution = new DMUF_Test_Attribution( $settings, $meta );
$woocommerce = new DMUF_WooCommerce( $settings, $attribution, $meta );

$direct = new WC_Order( 10, true );
$woocommerce->capture_order( $direct );
dmuf_flow_assert( '' === $direct->get_meta( '_dmuf_utm_source' ), 'direct order must not receive attribution' );

$attribution->session = (object) array(
	'session_key' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
	'utm_source' => 'CapPrice',
	'utm_medium' => 'paid_social',
	'utm_campaign' => 'summer',
	'utm_content' => 'creative-1',
	'utm_term' => '',
	'landing_url' => 'https://example.test/?utm_source=CapPrice',
	'first_seen' => '2026-08-08 08:00:00',
	'expires_at' => '2026-08-15 08:00:00',
);

$unpaid = new WC_Order( 11, false );
$woocommerce->capture_order( $unpaid );
$woocommerce->link_order( $unpaid );
dmuf_flow_assert( 'CapPrice' === $unpaid->get_meta( '_dmuf_utm_source' ), 'recognized UTM should be snapshotted to the order' );
dmuf_flow_assert( 'dmuf_purchase_11' === $unpaid->get_meta( '_dmuf_purchase_event_id' ), 'purchase event ID should be deterministic' );

$dmuf_orders[11] = $unpaid;
$woocommerce->send_purchase( 11 );
dmuf_flow_assert( 0 === $meta->purchase_calls, 'unpaid order must not send Purchase' );

$paid = new WC_Order( 12, true );
$woocommerce->capture_order( $paid );
$woocommerce->link_order( $paid );
$dmuf_orders[12] = $paid;
$woocommerce->send_purchase( 12 );
$woocommerce->send_purchase( 12 );
dmuf_flow_assert( 1 === $meta->purchase_calls, 'paid Purchase should be sent once locally' );
dmuf_flow_assert( 'sent' === $paid->get_meta( '_dmuf_purchase_capi_status' ), 'successful CAPI state should be stored' );
dmuf_flow_assert( '' !== $paid->get_meta( '_dmuf_purchase_capi_sent' ), 'successful CAPI timestamp should be stored' );

fwrite( STDOUT, "PASS: direct exclusion, UTM snapshot, paid-only Purchase and local deduplication\n" );
