<?php

defined( 'ABSPATH' ) || exit;

class DMUF_Settings {
	const OPTION_NAME = 'dmuf_settings';

	public static function defaults() {
		return array(
			'enabled'                       => 'no',
			'attribution_days'              => 7,
			'abandon_minutes'               => 30,
			'rules'                         => array(),
			'telegram_enabled'              => 'no',
			'telegram_bot_token'            => '',
			'telegram_chat_id'              => '',
			'telegram_store_label'           => '',
			'telegram_unpaid_minutes'        => 30,
		);
	}

	public function register() {
		register_setting(
			'dmuf_settings_group',
			self::OPTION_NAME,
			array( $this, 'sanitize' )
		);
	}

	public function all() {
		$value = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public function is_enabled() {
		$settings = $this->all();
		return 'yes' === $settings['enabled'];
	}

	public function attribution_days() {
		$settings = $this->all();
		return max( 1, min( 30, absint( $settings['attribution_days'] ) ) );
	}

	public function abandon_minutes() {
		$settings = $this->all();
		return max( 5, min( 1440, absint( $settings['abandon_minutes'] ) ) );
	}

	public function rules() {
		$settings = $this->all();
		return isset( $settings['rules'] ) && is_array( $settings['rules'] ) ? $settings['rules'] : array();
	}

	public function telegram_config() {
		$settings = $this->all();
		$store_label = trim( (string) $settings['telegram_store_label'] );
		if ( '' === $store_label ) {
			$store_label = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		return array(
			'enabled'              => 'yes' === $settings['telegram_enabled'],
			'bot_token'            => (string) $settings['telegram_bot_token'],
			'chat_id'              => (string) $settings['telegram_chat_id'],
			'store_label'          => substr( sanitize_text_field( $store_label ), 0, 80 ),
			'unpaid_minutes'       => max( 5, min( 1440, absint( $settings['telegram_unpaid_minutes'] ) ) ),
		);
	}

	public function telegram_ready() {
		$config = $this->telegram_config();
		return $config['enabled'] && '' !== $config['bot_token'] && '' !== $config['chat_id'];
	}

	public function find_rule( $source ) {
		$source = $this->normalize_source( $source );
		if ( '' === $source ) {
			return null;
		}

		foreach ( $this->rules() as $rule ) {
			if ( empty( $rule['enabled'] ) || 'yes' !== $rule['enabled'] ) {
				continue;
			}

			if ( $source === $this->normalize_source( isset( $rule['source'] ) ? $rule['source'] : '' ) ) {
				return $rule;
			}
		}

		return null;
	}

	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current_settings = $this->all();
		$current  = $this->rules_by_source( $this->rules() );
		$clean    = self::defaults();
		$sources  = array();
		$raw_rules = isset( $input['rules'] ) && is_array( $input['rules'] ) ? $input['rules'] : array();

		$clean['enabled']          = ! empty( $input['enabled'] ) ? 'yes' : 'no';
		$clean['attribution_days'] = max( 1, min( 30, absint( isset( $input['attribution_days'] ) ? $input['attribution_days'] : 7 ) ) );
		$clean['abandon_minutes']  = max( 5, min( 1440, absint( isset( $input['abandon_minutes'] ) ? $input['abandon_minutes'] : 30 ) ) );
		$clean['rules']            = array();
		$telegram_token = isset( $input['telegram_bot_token'] ) ? preg_replace( '/[^A-Za-z0-9:_-]/', '', (string) wp_unslash( $input['telegram_bot_token'] ) ) : '';
		if ( '' === $telegram_token ) {
			$telegram_token = isset( $current_settings['telegram_bot_token'] ) ? (string) $current_settings['telegram_bot_token'] : '';
		}

		$chat_id = isset( $input['telegram_chat_id'] ) ? trim( sanitize_text_field( wp_unslash( $input['telegram_chat_id'] ) ) ) : '';
		if ( ! preg_match( '/^(?:-?\d+|@[A-Za-z0-9_]{5,})$/', $chat_id ) ) {
			$chat_id = '';
		}

		$clean['telegram_enabled']             = ! empty( $input['telegram_enabled'] ) ? 'yes' : 'no';
		$clean['telegram_bot_token']           = substr( $telegram_token, 0, 255 );
		$clean['telegram_chat_id']             = substr( $chat_id, 0, 100 );
		$clean['telegram_store_label']          = substr( sanitize_text_field( isset( $input['telegram_store_label'] ) ? wp_unslash( $input['telegram_store_label'] ) : '' ), 0, 80 );
		$clean['telegram_unpaid_minutes']       = max( 5, min( 1440, absint( isset( $input['telegram_unpaid_minutes'] ) ? $input['telegram_unpaid_minutes'] : 30 ) ) );

		foreach ( array_slice( $raw_rules, 0, 20 ) as $raw_rule ) {
			if ( ! is_array( $raw_rule ) ) {
				continue;
			}

			$source_key = $this->normalize_source( isset( $raw_rule['source'] ) ? $raw_rule['source'] : '' );
			$pixel_id   = preg_replace( '/\D+/', '', isset( $raw_rule['pixel_id'] ) ? (string) $raw_rule['pixel_id'] : '' );
			if ( '' === $source_key || '' === $pixel_id || isset( $sources[ $source_key ] ) ) {
				continue;
			}

			$token = isset( $raw_rule['access_token'] ) ? trim( sanitize_text_field( wp_unslash( $raw_rule['access_token'] ) ) ) : '';
			if ( '' === $token && isset( $current[ $source_key ]['access_token'] ) ) {
				$token = $current[ $source_key ]['access_token'];
			}

			$clean['rules'][] = array(
				'enabled'         => ! empty( $raw_rule['enabled'] ) ? 'yes' : 'no',
				'source'          => sanitize_text_field( wp_unslash( $raw_rule['source'] ) ),
				'pixel_id'        => substr( $pixel_id, 0, 32 ),
				'access_token'    => substr( $token, 0, 4096 ),
				'test_event_code' => substr( sanitize_text_field( isset( $raw_rule['test_event_code'] ) ? wp_unslash( $raw_rule['test_event_code'] ) : '' ), 0, 100 ),
			);
			$sources[ $source_key ] = true;
		}

		return $clean;
	}

	public function active_conflicts() {
		$known = array(
			'meta-dynamic-pixel/meta-dynamic-pixel.php' => 'Meta Dynamic Pixel',
			'pixelyoursite-pro/pixelyoursite-pro.php'   => 'PixelYourSite Pro',
			'pixelyoursite/pixelyoursite.php'           => 'PixelYourSite',
		);
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			$active  = array_merge( $active, $network );
		}

		$conflicts = array();
		foreach ( $known as $plugin => $label ) {
			if ( in_array( $plugin, $active, true ) ) {
				$conflicts[] = $label;
			}
		}

		return $conflicts;
	}

	public function normalize_source( $source ) {
		$source = sanitize_text_field( (string) $source );
		$source = trim( $source );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $source, 'UTF-8' ) : strtolower( $source );
	}

	private function rules_by_source( $rules ) {
		$indexed = array();
		foreach ( $rules as $rule ) {
			$key = $this->normalize_source( isset( $rule['source'] ) ? $rule['source'] : '' );
			if ( '' !== $key ) {
				$indexed[ $key ] = $rule;
			}
		}
		return $indexed;
	}
}
