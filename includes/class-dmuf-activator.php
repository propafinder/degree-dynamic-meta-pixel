<?php

defined( 'ABSPATH' ) || exit;

class DMUF_Activator {
	public static function maybe_upgrade() {
		if ( get_option( 'dmuf_db_version', '' ) !== DMUF_VERSION ) {
			self::activate();
		}
	}

	/**
	 * Create only the compact UTM-session table. Orders remain in WooCommerce.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = $wpdb->prefix . 'dmuf_sessions';
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_key char(36) NOT NULL,
			utm_source varchar(100) NOT NULL,
			utm_medium varchar(100) NOT NULL DEFAULT '',
			utm_campaign varchar(191) NOT NULL DEFAULT '',
			utm_content varchar(191) NOT NULL DEFAULT '',
			utm_term varchar(191) NOT NULL DEFAULT '',
			landing_url text NOT NULL,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			expires_at datetime NOT NULL,
			checkout_started_at datetime NULL,
			checkout_event_id varchar(100) NOT NULL DEFAULT '',
			checkout_capi_status varchar(20) NOT NULL DEFAULT '',
			checkout_value decimal(20,6) NOT NULL DEFAULT 0,
			checkout_currency char(3) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_key (session_key),
			KEY source_date (utm_source,first_seen),
			KEY checkout_started_at (checkout_started_at),
			KEY order_id (order_id)
		) {$charset_collate};";

		dbDelta( $sql );

		if ( false === get_option( DMUF_Settings::OPTION_NAME, false ) ) {
			add_option( DMUF_Settings::OPTION_NAME, DMUF_Settings::defaults(), '', false );
		}

		update_option( 'dmuf_db_version', DMUF_VERSION, false );
	}
}
