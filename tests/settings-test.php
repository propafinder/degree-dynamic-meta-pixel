<?php

define( 'ABSPATH', __DIR__ . '/' );

$dmuf_test_options = array();

function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}

function wp_unslash( $value ) {
	return $value;
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

function get_option( $name, $default = false ) {
	global $dmuf_test_options;
	return array_key_exists( $name, $dmuf_test_options ) ? $dmuf_test_options[ $name ] : $default;
}

function is_multisite() {
	return false;
}

function get_bloginfo( $show ) {
	return 'Fallback Store';
}

function wp_specialchars_decode( $value, $quote_style = ENT_NOQUOTES ) {
	return html_entity_decode( $value, $quote_style, 'UTF-8' );
}

require_once dirname( __DIR__ ) . '/includes/class-dmuf-settings.php';

function dmuf_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$settings = new DMUF_Settings();
$clean = $settings->sanitize(
	array(
		'enabled'          => '1',
		'attribution_days' => 90,
		'abandon_minutes'  => 1,
		'telegram_enabled' => '1',
		'telegram_bot_token' => '123456:ABC_secret-token',
		'telegram_chat_id' => '-1001234567890',
		'telegram_store_label' => 'GreenRoute',
		'telegram_unpaid_minutes' => 1,
		'rules'            => array(
			array(
				'enabled'      => '1',
				'source'       => 'CapPrice',
				'pixel_id'     => 'px-123456',
				'access_token' => 'secret-token',
			),
			array(
				'enabled'  => '1',
				'source'   => 'capprice',
				'pixel_id' => '999',
			),
		),
	)
);

dmuf_assert( 'yes' === $clean['enabled'], 'enabled flag should be normalized' );
dmuf_assert( 30 === $clean['attribution_days'], 'attribution window should be capped at 30 days' );
dmuf_assert( 5 === $clean['abandon_minutes'], 'abandon delay should be at least five minutes' );
dmuf_assert( 1 === count( $clean['rules'] ), 'duplicate sources should be rejected case-insensitively' );
dmuf_assert( '123456' === $clean['rules'][0]['pixel_id'], 'pixel ID should contain digits only' );
dmuf_assert( 'yes' === $clean['telegram_enabled'], 'Telegram enabled flag should be normalized' );
dmuf_assert( '123456:ABC_secret-token' === $clean['telegram_bot_token'], 'Telegram token should be sanitized without breaking valid characters' );
dmuf_assert( '-1001234567890' === $clean['telegram_chat_id'], 'Telegram chat ID should be preserved' );
dmuf_assert( 'GreenRoute' === $clean['telegram_store_label'], 'Telegram store label should be preserved' );
dmuf_assert( 5 === $clean['telegram_unpaid_minutes'], 'unpaid delay should be at least five minutes' );

$dmuf_test_options[ DMUF_Settings::OPTION_NAME ] = $clean;
$rule = $settings->find_rule( 'cApPrIcE' );
dmuf_assert( is_array( $rule ) && 'CapPrice' === $rule['source'], 'source matching should be exact and case-insensitive' );
dmuf_assert( null === $settings->find_rule( 'facebook' ), 'unknown source should not match' );

$preserved = $settings->sanitize(
	array(
		'enabled' => '1',
		'telegram_enabled' => '1',
		'telegram_bot_token' => '',
		'telegram_chat_id' => '-1001234567890',
		'telegram_store_label' => 'GreenRoute',
		'rules'   => array(
			array( 'enabled' => '1', 'source' => 'CapPrice', 'pixel_id' => '123456', 'access_token' => '' ),
		),
	)
);
dmuf_assert( 'secret-token' === $preserved['rules'][0]['access_token'], 'blank token field should preserve the saved token' );
dmuf_assert( '123456:ABC_secret-token' === $preserved['telegram_bot_token'], 'blank Telegram token field should preserve the saved token' );

fwrite( STDOUT, "PASS: strict UTM settings and token preservation\n" );
