<?php

define( 'ABSPATH', __DIR__ . '/' );
define( 'DMUF_FILE', '/plugin/degree-dynamic-meta-pixel/degree-dynamic-meta-pixel.php' );
define( 'DMUF_VERSION', '1.2.0' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$dmuf_update_cache = array();
$dmuf_remote_calls = 0;
$dmuf_release_data = array(
	'tag_name'     => 'v1.3.0',
	'draft'        => false,
	'prerelease'   => false,
	'html_url'     => 'https://github.com/propafinder/degree-dynamic-meta-pixel/releases/tag/v1.3.0',
	'published_at' => '2026-08-08T12:00:00Z',
	'body'         => "Release notes\nNo secrets.",
	'assets'       => array(
		array(
			'name'                 => 'degree-dynamic-meta-pixel-1.3.0.zip',
			'browser_download_url' => 'https://github.com/propafinder/degree-dynamic-meta-pixel/releases/download/v1.3.0/degree-dynamic-meta-pixel-1.3.0.zip',
		),
	),
);

function add_filter() {}
function add_action() {}
function plugin_basename( $file ) { return basename( dirname( $file ) ) . '/' . basename( $file ); }
function get_site_transient( $key ) { global $dmuf_update_cache; return isset( $dmuf_update_cache[ $key ] ) ? $dmuf_update_cache[ $key ] : false; }
function set_site_transient( $key, $value, $expiration ) { global $dmuf_update_cache; $dmuf_update_cache[ $key ] = $value; return true; }
function delete_site_transient( $key ) { global $dmuf_update_cache; unset( $dmuf_update_cache[ $key ] ); return true; }
function wp_remote_get( $url, $args ) { global $dmuf_remote_calls, $dmuf_release_data; $dmuf_remote_calls++; return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $dmuf_release_data ) ); }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function is_wp_error( $value ) { return false; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value ) { return filter_var( $value, FILTER_SANITIZE_URL ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function untrailingslashit( $value ) { return rtrim( $value, '/\\' ); }

require_once dirname( __DIR__ ) . '/includes/class-dmuf-github-updater.php';

function dmuf_update_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$updater = new DMUF_GitHub_Updater();
$update  = $updater->filter_update( false, array(), 'degree-dynamic-meta-pixel/degree-dynamic-meta-pixel.php', array() );

dmuf_update_assert( is_array( $update ), 'own plugin should receive update data' );
dmuf_update_assert( '1.3.0' === $update['version'], 'tag version should be normalized' );
dmuf_update_assert( false !== strpos( $update['package'], '/releases/download/v1.3.0/' ), 'release asset should be used as package' );
dmuf_update_assert( false === $update['autoupdate'], 'automatic updates should not be forced' );

$cached = $updater->filter_update( false, array(), 'degree-dynamic-meta-pixel/degree-dynamic-meta-pixel.php', array() );
dmuf_update_assert( 1 === $dmuf_remote_calls, 'release response should be cached' );
dmuf_update_assert( $cached === $update, 'cached update should stay identical' );

$other = $updater->filter_update( false, array(), 'another/plugin.php', array() );
dmuf_update_assert( false === $other, 'other plugins should be ignored' );

$dmuf_update_cache = array();
$dmuf_release_data['assets'][0]['browser_download_url'] = 'https://evil.example/plugin.zip';
$invalid = $updater->filter_update( false, array(), 'degree-dynamic-meta-pixel/degree-dynamic-meta-pixel.php', array() );
dmuf_update_assert( false === $invalid, 'package outside the expected GitHub repository should be rejected' );

fwrite( STDOUT, "PASS: native GitHub release updater, cache and package URL validation\n" );
