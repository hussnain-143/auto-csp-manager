<?php
/**
 * Built-in violation report endpoint.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Receives reports from browsers at /wp-json/auto-csp-manager/v1/report.
 *
 * The endpoint has to be public (browsers post to it without credentials), so
 * everything it receives is treated as untrusted: size-limited, rate-limited,
 * checked against this site's own hostname, normalised and de-duplicated.
 */
class ACSP_Reporter {

	const MAX_BODY    = 65536;
	const MAX_REPORTS = 20;

	/**
	 * Hook up.
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	/**
	 * Register the REST route.
	 */
	public function register_route() {
		register_rest_route(
			ACSP_REST_NAMESPACE,
			'/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming report.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		nocache_headers();

		$settings = ACSP_Settings::get();
		if ( 'off' === $settings['mode'] || 'internal' !== $settings['report_dest'] ) {
			return new WP_REST_Response( null, 204 );
		}

		$body = (string) $request->get_body();
		if ( '' === $body ) {
			return new WP_REST_Response( null, 204 );
		}
		if ( strlen( $body ) > self::MAX_BODY ) {
			return new WP_REST_Response( null, 413 );
		}
		if ( $this->rate_limited() ) {
			return new WP_REST_Response( null, 429 );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$fallback_ua = (string) $request->get_header( 'user_agent' );
		foreach ( array_slice( self::extract_reports( $data ), 0, self::MAX_REPORTS ) as $raw ) {
			$report = self::normalise( $raw, $fallback_ua, ! empty( $settings['drop_extension_noise'] ) );
			if ( $report ) {
				ACSP_DB::record( $report );
			}
		}

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Simple per-client rate limit.
	 *
	 * @return bool True when the client should be turned away.
	 */
	protected function rate_limited() {
		/**
		 * Reports accepted per client per minute. 0 disables the limit, which
		 * can be useful when every visitor arrives from one proxy address.
		 */
		$limit = (int) apply_filters( 'acsp_rate_limit', 120 );
		if ( $limit <= 0 ) {
			return false;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * The address used to bucket the rate limit. REMOTE_ADDR is the only
		 * value a client cannot forge, so it is the default even behind a
		 * proxy, where every visitor shares one bucket. Sites that terminate
		 * at a trusted proxy can return the real client address here; never
		 * read X-Forwarded-For without knowing the proxy is trusted, or the
		 * limit becomes a single spoofable header away from useless.
		 *
		 * @param string $ip Client address.
		 */
		$ip = (string) apply_filters( 'acsp_client_ip', $ip );

		// The window number is part of the key, so each minute gets its own
		// counter and simply disappears when it expires. The previous
		// implementation re-set one key's TTL on every request, which meant a
		// client reporting at least once a minute kept its counter alive for
		// ever and stayed limited permanently.
		$window = (int) floor( time() / MINUTE_IN_SECONDS );
		$key    = 'acsp_rl_' . substr( wp_hash( $ip ), 0, 20 ) . '_' . $window; // The address itself is never stored.

		return self::count_request( $key, $window ) > $limit;
	}

	/**
	 * Atomically count one request in a window and return the new total.
	 *
	 * Read-then-write loses increments whenever requests overlap, and a public
	 * endpoint gets overlapping requests by definition. Both branches here are
	 * single atomic operations: an object-cache increment, or one SQL UPDATE
	 * that computes the new value in the database.
	 *
	 * @param string $key    Counter key.
	 * @param int    $window Current window number.
	 * @return int New count for this window.
	 */
	protected static function count_request( $key, $window ) {
		global $wpdb;

		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'acsp', 2 * MINUTE_IN_SECONDS );
			$count = wp_cache_incr( $key, 1, 'acsp' );
			if ( false !== $count ) {
				return (int) $count;
			}
			// Fall through to the database when the cache refuses the increment.
		}

		// Claim the window with a plain INSERT: the unique index on
		// option_name means exactly one concurrent request can create the row
		// and everyone else gets a duplicate-key error and increments instead.
		//
		// add_option() deliberately is not used here. Core implements it as
		// INSERT ... ON DUPLICATE KEY UPDATE option_value = VALUES(option_value),
		// guarded by a cache-based existence check. When that check loses a
		// race - which is the exact situation this code exists to handle - the
		// counter is overwritten with 1 and the limit stops working. Errors are
		// suppressed around the INSERT because a duplicate key is the expected
		// outcome, not a fault.
		$suppressed = $wpdb->suppress_errors();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- atomic claim; no API for this.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')", $key ) );
		$wpdb->suppress_errors( $suppressed );

		if ( $inserted ) {
			self::sweep_counters( $window );
			return 1;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- atomic increment; no API for this.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s", $key ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );

		// The row was swept or expired between the INSERT and the UPDATE.
		return $count > 0 ? $count : 1;
	}

	/**
	 * Delete counters from windows that have passed.
	 *
	 * Called on the rare request that opens a new window, and only some of the
	 * time, so a busy site does not run a DELETE per report but also never
	 * accumulates stale rows.
	 *
	 * @param int $window Current window number.
	 */
	protected static function sweep_counters( $window ) {
		global $wpdb;

		if ( 1 !== wp_rand( 1, 25 ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				$wpdb->esc_like( 'acsp_rl_' ) . '%',
				'%' . $wpdb->esc_like( '_' . $window )
			)
		);
	}

	/**
	 * Pull individual reports out of either wire format and map them onto
	 * one set of field names.
	 *
	 * - report-uri sends   {"csp-report": {...}}             (application/csp-report)
	 * - report-to sends    [{"type":"csp-violation","body":{...}}, ...] (application/reports+json)
	 *
	 * @param array $data Decoded JSON.
	 * @return array[]
	 */
	public static function extract_reports( array $data ) {
		$reports = array();

		if ( isset( $data['csp-report'] ) && is_array( $data['csp-report'] ) ) {
			$r         = $data['csp-report'];
			$reports[] = array(
				'directive'    => self::first( $r, array( 'effective-directive', 'violated-directive' ) ),
				'blocked_uri'  => self::first( $r, array( 'blocked-uri' ) ),
				'document_uri' => self::first( $r, array( 'document-uri' ) ),
				'source_file'  => self::first( $r, array( 'source-file' ) ),
				'line_number'  => self::first( $r, array( 'line-number' ) ),
				'sample'       => self::first( $r, array( 'script-sample' ) ),
				'disposition'  => self::first( $r, array( 'disposition' ) ),
				'user_agent'   => '',
			);
			return $reports;
		}

		// A single Reporting API object rather than a batch.
		if ( isset( $data['body'] ) ) {
			$data = array( $data );
		}

		foreach ( $data as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['body'] ) || ! is_array( $item['body'] ) ) {
				continue;
			}
			if ( isset( $item['type'] ) && 'csp-violation' !== $item['type'] ) {
				continue;
			}
			$b         = $item['body'];
			$reports[] = array(
				'directive'    => self::first( $b, array( 'effectiveDirective', 'violatedDirective' ) ),
				'blocked_uri'  => self::first( $b, array( 'blockedURL', 'blockedURI' ) ),
				'document_uri' => self::first( $b, array( 'documentURL', 'documentURI' ) ),
				'source_file'  => self::first( $b, array( 'sourceFile' ) ),
				'line_number'  => self::first( $b, array( 'lineNumber' ) ),
				'sample'       => self::first( $b, array( 'sample' ) ),
				'disposition'  => self::first( $b, array( 'disposition' ) ),
				'user_agent'   => self::first( $item, array( 'user_agent' ) ),
			);
		}
		return $reports;
	}

	/**
	 * First scalar value found under any of the given keys.
	 *
	 * @param array $data Array.
	 * @param array $keys Keys to try.
	 * @return string
	 */
	protected static function first( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return (string) $data[ $key ];
			}
		}
		return '';
	}

	/**
	 * Turn a raw report into a row for the log, or null if it should be dropped.
	 *
	 * @param array  $raw         Fields from extract_reports().
	 * @param string $fallback_ua User agent of the request.
	 * @param bool   $drop_noise  Drop reports caused by browser extensions.
	 * @return array|null
	 */
	public static function normalise( array $raw, $fallback_ua = '', $drop_noise = true ) {
		$directive = ACSP_Policy::map_directive( $raw['directive'] );
		if ( '' === $directive ) {
			return null;
		}

		// Only accept reports about pages on this site.
		$doc_host = wp_parse_url( $raw['document_uri'], PHP_URL_HOST );
		if ( ! $doc_host || ! in_array( strtolower( $doc_host ), self::site_hosts(), true ) ) {
			return null;
		}

		if ( $drop_noise && ( self::is_noise( $raw['blocked_uri'] ) || self::is_noise( $raw['source_file'] ) ) ) {
			return null;
		}

		$source = self::blocked_to_source( $raw['blocked_uri'], $directive );
		if ( '' === $source ) {
			return null;
		}

		$parts = preg_split( '/\s+/', strtolower( trim( $raw['directive'] ) ) );

		return array(
			'directive'           => $directive,
			'effective_directive' => sanitize_text_field( $parts[0] ),
			'source'              => $source,
			'blocked_uri'         => false !== strpos( $raw['blocked_uri'], '://' ) ? esc_url_raw( $raw['blocked_uri'] ) : sanitize_text_field( $raw['blocked_uri'] ),
			'document_uri'        => esc_url_raw( $raw['document_uri'] ),
			'source_file'         => esc_url_raw( $raw['source_file'] ),
			'line_number'         => absint( $raw['line_number'] ),
			'script_sample'       => sanitize_text_field( $raw['sample'] ),
			'disposition'         => 'enforce' === strtolower( $raw['disposition'] ) ? 'enforce' : 'report',
			'user_agent'          => sanitize_text_field( '' !== $raw['user_agent'] ? $raw['user_agent'] : $fallback_ua ),
		);
	}

	/**
	 * Hostnames this site answers on.
	 *
	 * @return array
	 */
	public static function site_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		return array_unique( $hosts );
	}

	/**
	 * Full origins (scheme, host and port) this site answers on.
	 *
	 * Host alone is not identity: CSP 'self' matches scheme, host AND port, so
	 * anything deciding "is this us?" has to compare the whole origin.
	 *
	 * @return array
	 */
	public static function site_origins() {
		$origins = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$origin = self::normalise_origin( $url );
			if ( '' !== $origin ) {
				$origins[] = $origin;
			}
		}
		return array_values( array_unique( $origins ) );
	}

	/**
	 * Reduce a URL to a comparable origin, with the default port dropped.
	 *
	 * @param string $url URL or origin.
	 * @return string scheme://host[:port], or '' when there is no host.
	 */
	public static function normalise_origin( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme  = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'http';
		$port    = empty( $parts['port'] ) ? 0 : (int) $parts['port'];
		$default = array(
			'http'  => 80,
			'https' => 443,
			'ws'    => 80,
			'wss'   => 443,
		);
		if ( $port && isset( $default[ $scheme ] ) && $default[ $scheme ] === $port ) {
			$port = 0;
		}
		return $scheme . '://' . strtolower( $parts['host'] ) . ( $port ? ':' . $port : '' );
	}

	/**
	 * Whether a URL comes from a browser extension or browser internals.
	 * These violations are caused by the visitor's browser, not by the site.
	 *
	 * @param string $uri URI from a report.
	 * @return bool
	 */
	public static function is_noise( $uri ) {
		$uri = strtolower( trim( (string) $uri ) );
		if ( 'about' === $uri ) {
			return true;
		}
		if ( ! preg_match( '/^([a-z][a-z0-9+.-]*):/', $uri, $m ) ) {
			return false;
		}
		$noise = array(
			'chrome-extension',
			'moz-extension',
			'safari-extension',
			'safari-web-extension',
			'ms-browser-extension',
			'webkit-masked-url',
			'chrome',
			'edge',
			'resource',
			'about',
			'gap',
			'capacitor',
			'ms-appx',
			'ms-appx-web',
			'android-webview-video-poster',
			'webviewprogressproxy',
		);
		return in_array( $m[1], (array) apply_filters( 'acsp_noise_schemes', $noise ), true );
	}

	/**
	 * Convert the "blocked-uri" of a report into a source expression that
	 * could be added to the policy.
	 *
	 * @param string $blocked   Blocked URI from the report.
	 * @param string $directive Managed directive.
	 * @return string Source expression or ''.
	 */
	public static function blocked_to_source( $blocked, $directive ) {
		$blocked = trim( (string) $blocked );
		$lower   = strtolower( $blocked );
		$inline  = in_array( $directive, array( 'script-src', 'style-src', 'default-src' ), true );

		switch ( $lower ) {
			case 'inline':
				return "'unsafe-inline'";
			case 'eval':
				return "'unsafe-eval'";
			case 'wasm-eval':
				return "'wasm-unsafe-eval'";
			case '':
			case 'self':
				// Older browsers report inline code this way.
				return $inline ? "'unsafe-inline'" : '';
			case 'data':
			case 'blob':
			case 'filesystem':
			case 'mediastream':
				return $lower . ':';
		}

		if ( ! preg_match( '/^([a-z][a-z0-9+.-]*):/', $lower, $m ) ) {
			return '';
		}
		$scheme = $m[1];

		if ( in_array( $scheme, array( 'http', 'https', 'ws', 'wss' ), true ) ) {
			$host = wp_parse_url( $blocked, PHP_URL_HOST );
			$port = wp_parse_url( $blocked, PHP_URL_PORT );
			if ( ! $host ) {
				return '';
			}
			$origin = $scheme . '://' . strtolower( $host ) . ( $port ? ':' . (int) $port : '' );
			if ( in_array( self::normalise_origin( $origin ), self::site_origins(), true ) ) {
				return "'self'";
			}
			return ACSP_Policy::sanitize_source( $origin );
		}

		return ACSP_Policy::sanitize_source( $scheme . ':' );
	}
}
