<?php
/**
 * Plugin settings.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin settings option.
 */
class ACSP_Settings {

	const OPTION = 'acsp_settings';

	/**
	 * Default settings. The plugin always starts in report-only mode so that
	 * activating it can never break a site.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'                 => 'report',   // off | report | enforce.
			'report_dest'          => 'internal', // internal | external | none.
			'external_url'         => '',
			'apply_admin'          => false,
			'apply_login'          => false,
			'drop_extension_noise' => true,
			'retention_days'       => 30,
			'max_rows'             => 2000,
		);
	}

	/**
	 * Get all settings, or one key.
	 *
	 * @param string|null $key Optional key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $settings;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Sanitize and store settings.
	 *
	 * @param array $input Raw input.
	 * @return array Stored settings.
	 */
	public static function update( array $input ) {
		$clean = self::get();

		if ( isset( $input['mode'] ) && in_array( $input['mode'], array( 'off', 'report', 'enforce' ), true ) ) {
			$clean['mode'] = $input['mode'];
		}
		if ( isset( $input['report_dest'] ) && in_array( $input['report_dest'], array( 'internal', 'external', 'none' ), true ) ) {
			$clean['report_dest'] = $input['report_dest'];
		}
		if ( isset( $input['external_url'] ) ) {
			$clean['external_url'] = self::sanitize_report_url( $input['external_url'] );
		}
		foreach ( array( 'apply_admin', 'apply_login', 'drop_extension_noise' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$clean[ $flag ] = ! empty( $input[ $flag ] );
			}
		}
		if ( isset( $input['retention_days'] ) ) {
			$clean['retention_days'] = max( 1, min( 365, absint( $input['retention_days'] ) ) );
		}
		if ( isset( $input['max_rows'] ) ) {
			$clean['max_rows'] = max( 100, min( 50000, absint( $input['max_rows'] ) ) );
		}

		// An external destination without a usable URL falls back to the built-in logger.
		if ( 'external' === $clean['report_dest'] && '' === $clean['external_url'] ) {
			$clean['report_dest'] = 'internal';
		}

		update_option( self::OPTION, $clean );
		return $clean;
	}

	/**
	 * A report URL ends up inside a response header, so it must not contain
	 * anything that could terminate the directive or the header.
	 *
	 * @param string $url Raw URL.
	 * @return string Clean URL or empty string.
	 */
	public static function sanitize_report_url( $url ) {
		$raw = trim( (string) $url );

		// Check the value as it was typed. esc_url_raw() strips control
		// characters and whitespace, so testing only its output would accept
		// "https://host/csp\r\nX: 1" as the mangled "https://host/cspX:1"
		// instead of refusing it. Nothing is salvaged from a URL that contained
		// a character which has no business being in one.
		if ( '' === $raw || preg_match( '/[\s;,"\x00-\x1f\x7f]/', $raw ) ) {
			return '';
		}

		$url = esc_url_raw( $raw, array( 'https', 'http' ) );
		if ( '' === $url || preg_match( '/[\s;,"]/', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * URL of the built-in report endpoint.
	 *
	 * @return string
	 */
	public static function internal_report_url() {
		return get_rest_url( null, ACSP_REST_NAMESPACE . '/report' );
	}

	/**
	 * The URL browsers should send reports to, or '' when reporting is off.
	 *
	 * @return string
	 */
	public static function active_report_url() {
		$settings = self::get();
		if ( 'internal' === $settings['report_dest'] ) {
			$url = self::internal_report_url();
			return preg_match( '/[\s;,"]/', $url ) ? '' : $url;
		}
		if ( 'external' === $settings['report_dest'] ) {
			return $settings['external_url'];
		}
		return '';
	}
}
