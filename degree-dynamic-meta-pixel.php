<?php
/**
 * Plugin Name: Degree Dynamic Meta Pixel
 * Plugin URI: https://github.com/propafinder/degree-dynamic-meta-pixel
 * Description: Dynamic Meta Pixel/CAPI routing by UTM source with a clean WooCommerce funnel and paid/unpaid Telegram alerts.
 * Version: 1.2.0
 * Author: Degree Team
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * WC requires at least: 8.2
 * WC tested up to: 10.7
 * Update URI: https://github.com/propafinder/degree-dynamic-meta-pixel
 * Text Domain: degree-dynamic-meta-pixel
 */

defined( 'ABSPATH' ) || exit;

define( 'DMUF_VERSION', '1.2.0' );
define( 'DMUF_FILE', __FILE__ );
define( 'DMUF_DIR', plugin_dir_path( __FILE__ ) );
define( 'DMUF_URL', plugin_dir_url( __FILE__ ) );

require_once DMUF_DIR . 'includes/class-dmuf-activator.php';
require_once DMUF_DIR . 'includes/class-dmuf-settings.php';
require_once DMUF_DIR . 'includes/class-dmuf-meta-client.php';
require_once DMUF_DIR . 'includes/class-dmuf-attribution.php';
require_once DMUF_DIR . 'includes/class-dmuf-woocommerce.php';
require_once DMUF_DIR . 'includes/class-dmuf-telegram.php';
require_once DMUF_DIR . 'includes/class-dmuf-admin.php';
require_once DMUF_DIR . 'includes/class-dmuf-github-updater.php';

register_activation_hook( __FILE__, array( 'DMUF_Activator', 'activate' ) );
add_action( 'plugins_loaded', array( 'DMUF_Activator', 'maybe_upgrade' ), 5 );

/**
 * Declare compatibility before WooCommerce initializes its features.
 */
function dmuf_declare_woocommerce_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
}
add_action( 'before_woocommerce_init', 'dmuf_declare_woocommerce_compatibility' );

/**
 * Boot admin screens even when tracking is paused, so conflicts remain visible.
 */
function dmuf_bootstrap() {
	$settings = new DMUF_Settings();
	$telegram = new DMUF_Telegram( $settings );
	$admin    = new DMUF_Admin( $settings, $telegram );
	$updater  = new DMUF_GitHub_Updater();
	$admin->register();
	$updater->register();

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', array( $admin, 'woocommerce_missing_notice' ) );
		return;
	}

	$conflicts = $settings->active_conflicts();
	if ( ! empty( $conflicts ) ) {
		$admin->set_conflicts( $conflicts );
		add_action( 'admin_notices', array( $admin, 'parallel_tracking_notice' ) );
	}

	if ( ! $settings->is_enabled() ) {
		return;
	}

	$meta        = new DMUF_Meta_Client( $settings );
	$attribution = new DMUF_Attribution( $settings, $meta );
	$woocommerce = new DMUF_WooCommerce( $settings, $attribution, $meta );

	$attribution->register();
	$woocommerce->register();
	$telegram->register();
}
add_action( 'plugins_loaded', 'dmuf_bootstrap', 20 );
