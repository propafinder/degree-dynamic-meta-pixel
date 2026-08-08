<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function is_ssl() { return false; }

class WC_Order {}

require_once dirname( __DIR__ ) . '/includes/class-dmuf-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-dmuf-meta-client.php';
require_once dirname( __DIR__ ) . '/includes/class-dmuf-attribution.php';

class DMUF_Reset_Settings extends DMUF_Settings {
	public function find_rule( $source ) { return null; }
}

$settings = new DMUF_Reset_Settings();
$meta = new DMUF_Meta_Client( $settings );
$attribution = new DMUF_Attribution( $settings, $meta );
$_COOKIE[ DMUF_Attribution::COOKIE_NAME ] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

$capture = new ReflectionMethod( 'DMUF_Attribution', 'capture' );
$capture->setAccessible( true );
$capture->invoke(
	$attribution,
	array(
		'utm_source' => 'SomeOtherSource',
		'utm_medium' => '',
		'utm_campaign' => '',
		'utm_content' => '',
		'utm_term' => '',
	),
	'https://example.test/?utm_source=SomeOtherSource'
);

if ( isset( $_COOKIE[ DMUF_Attribution::COOKIE_NAME ] ) ) {
	fwrite( STDERR, "FAIL: unknown UTM did not clear previous attribution\n" );
	exit( 1 );
}

fwrite( STDOUT, "PASS: unknown UTM clears previous attribution\n" );
