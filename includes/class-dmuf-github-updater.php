<?php

defined( 'ABSPATH' ) || exit;

class DMUF_GitHub_Updater {
	const REPOSITORY = 'propafinder/degree-dynamic-meta-pixel';
	const SLUG       = 'degree-dynamic-meta-pixel';
	const UPDATE_URI = 'https://github.com/propafinder/degree-dynamic-meta-pixel';
	const API_URL    = 'https://api.github.com/repos/propafinder/degree-dynamic-meta-pixel/releases/latest';
	const CACHE_KEY  = 'dmuf_github_release_v1';

	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache_after_upgrade' ), 10, 2 );
	}

	public function filter_update( $update, $plugin_data, $plugin_file, $locales ) {
		unset( $plugin_data, $locales );

		if ( plugin_basename( DMUF_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $update;
		}

		return array(
			'id'           => self::UPDATE_URI,
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'tested'       => '6.8',
			'requires_php' => '7.4',
			'autoupdate'   => false,
		);
	}

	public function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! $release ) {
			return $result;
		}

		$changelog = '' !== $release['notes']
			? nl2br( esc_html( $release['notes'] ) )
			: 'Изменения опубликованы в GitHub Release.';

		return (object) array(
			'name'          => 'Degree Dynamic Meta Pixel',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Degree Team',
			'homepage'      => self::UPDATE_URI,
			'requires'      => '6.2',
			'tested'        => '6.8',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published_at'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => 'Динамический Meta Pixel/CAPI по точному utm_source с отчётом WooCommerce и уведомлениями Telegram.',
				'changelog'   => $changelog,
			),
		);
	}

	public function clear_cache_after_upgrade( $upgrader, $options ) {
		unset( $upgrader );

		if ( 'update' !== ( isset( $options['action'] ) ? $options['action'] : '' ) || 'plugin' !== ( isset( $options['type'] ) ? $options['type'] : '' ) ) {
			return;
		}

		$plugins = isset( $options['plugins'] ) && is_array( $options['plugins'] ) ? $options['plugins'] : array();
		if ( in_array( plugin_basename( DMUF_FILE ), $plugins, true ) ) {
			delete_site_transient( self::CACHE_KEY );
		}
	}

	private function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) {
			return is_array( $cached['release'] ) ? $cached['release'] : null;
		}

		$response = wp_remote_get(
			self::API_URL,
			array(
				'timeout' => 8,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'Degree-Dynamic-Meta-Pixel/' . DMUF_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( self::CACHE_KEY, array( 'release' => null ), 15 * MINUTE_IN_SECONDS );
			return null;
		}

		$data    = json_decode( wp_remote_retrieve_body( $response ), true );
		$release = $this->normalize_release( $data );
		set_site_transient( self::CACHE_KEY, array( 'release' => $release ), $release ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS );

		return $release;
	}

	private function normalize_release( $data ) {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}

		$version = isset( $data['tag_name'] ) ? ltrim( sanitize_text_field( $data['tag_name'] ), 'vV' ) : '';
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}

		$expected_names = array( self::SLUG . '-' . $version . '.zip', self::SLUG . '.zip' );
		$package        = '';
		foreach ( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() as $asset ) {
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			$url  = isset( $asset['browser_download_url'] ) ? esc_url_raw( $asset['browser_download_url'] ) : '';
			if ( in_array( $name, $expected_names, true ) && $this->valid_package_url( $url ) ) {
				$package = $url;
				break;
			}
		}

		$url = isset( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : '';
		if ( '' === $package || ! $this->valid_release_url( $url ) ) {
			return null;
		}

		return array(
			'version'      => $version,
			'url'          => $url,
			'package'      => $package,
			'published_at' => isset( $data['published_at'] ) ? sanitize_text_field( $data['published_at'] ) : '',
			'notes'        => isset( $data['body'] ) ? sanitize_textarea_field( $data['body'] ) : '',
		);
	}

	private function valid_package_url( $url ) {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '';

		return is_array( $parts )
			&& 'https' === ( isset( $parts['scheme'] ) ? $parts['scheme'] : '' )
			&& 'github.com' === ( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '' )
			&& 0 === strpos( $path, '/' . self::REPOSITORY . '/releases/download/' );
	}

	private function valid_release_url( $url ) {
		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		return is_array( $parts )
			&& 'https' === ( isset( $parts['scheme'] ) ? $parts['scheme'] : '' )
			&& 'github.com' === ( isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '' )
			&& 0 === strpos( $path, '/' . self::REPOSITORY . '/releases/tag/' );
	}
}
