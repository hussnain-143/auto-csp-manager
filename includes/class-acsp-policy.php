<?php
/**
 * The Content-Security-Policy itself: storage, validation and header output.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Policy model.
 *
 * Every managed directive is always written out explicitly, so there are no
 * hidden fallbacks to default-src for the site owner to reason about.
 */
class ACSP_Policy {

	const OPTION = 'acsp_policy';

	/**
	 * Managed directives, in output order, with plain-language labels.
	 *
	 * @return array
	 */
	public static function directive_definitions() {
		return array(
			'default-src'     => array(
				'label' => __( 'Everything else (fallback)', 'auto-csp-manager' ),
				'desc'  => __( 'Used for any kind of content that has no rule of its own below.', 'auto-csp-manager' ),
			),
			'script-src'      => array(
				'label' => __( 'Scripts', 'auto-csp-manager' ),
				'desc'  => __( 'Where JavaScript may load from. The most important rule for security.', 'auto-csp-manager' ),
			),
			'style-src'       => array(
				'label' => __( 'Stylesheets', 'auto-csp-manager' ),
				'desc'  => __( 'Where CSS may load from.', 'auto-csp-manager' ),
			),
			'img-src'         => array(
				'label' => __( 'Images', 'auto-csp-manager' ),
				'desc'  => __( 'Where images and icons may load from.', 'auto-csp-manager' ),
			),
			'font-src'        => array(
				'label' => __( 'Fonts', 'auto-csp-manager' ),
				'desc'  => __( 'Where web fonts may load from.', 'auto-csp-manager' ),
			),
			'connect-src'     => array(
				'label' => __( 'Background connections', 'auto-csp-manager' ),
				'desc'  => __( 'Where scripts may send or fetch data (AJAX, analytics, WebSockets).', 'auto-csp-manager' ),
			),
			'media-src'       => array(
				'label' => __( 'Audio and video', 'auto-csp-manager' ),
				'desc'  => __( 'Where audio and video files may load from.', 'auto-csp-manager' ),
			),
			'frame-src'       => array(
				'label' => __( 'Embedded frames', 'auto-csp-manager' ),
				'desc'  => __( 'Which sites may be embedded in your pages (YouTube, maps, payment forms).', 'auto-csp-manager' ),
			),
			'worker-src'      => array(
				'label' => __( 'Background workers', 'auto-csp-manager' ),
				'desc'  => __( 'Where web workers and service workers may load from.', 'auto-csp-manager' ),
			),
			'manifest-src'    => array(
				'label' => __( 'App manifest', 'auto-csp-manager' ),
				'desc'  => __( 'Where the web app manifest may load from.', 'auto-csp-manager' ),
			),
			'object-src'      => array(
				'label' => __( 'Legacy plugins', 'auto-csp-manager' ),
				'desc'  => __( 'Old-style embedded objects (Flash, Java). Best left blocked.', 'auto-csp-manager' ),
			),
			'base-uri'        => array(
				'label' => __( 'Base URL', 'auto-csp-manager' ),
				'desc'  => __( 'Stops injected code from changing where relative links point.', 'auto-csp-manager' ),
			),
			'form-action'     => array(
				'label' => __( 'Form submissions', 'auto-csp-manager' ),
				'desc'  => __( 'Where forms on your site may send their data.', 'auto-csp-manager' ),
			),
			'frame-ancestors' => array(
				'label' => __( 'Who may embed this site', 'auto-csp-manager' ),
				'desc'  => __( 'Which sites may show your pages inside a frame (clickjacking protection).', 'auto-csp-manager' ),
			),
		);
	}

	/**
	 * Keyword sources (stored with their quotes).
	 *
	 * @return array
	 */
	public static function keywords() {
		return array( 'self', 'none', 'unsafe-inline', 'unsafe-eval', 'unsafe-hashes', 'strict-dynamic', 'report-sample', 'wasm-unsafe-eval', 'inline-speculation-rules' );
	}

	/**
	 * A safe starting policy for a WordPress site before anything is scanned.
	 *
	 * WordPress core prints inline scripts and styles on almost every page, so
	 * 'unsafe-inline' is part of the baseline. It can be removed in the editor.
	 *
	 * @return array
	 */
	public static function baseline() {
		return array(
			'directives'       => array(
				'default-src'     => array( "'self'" ),
				'script-src'      => array( "'self'", "'unsafe-inline'" ),
				'style-src'       => array( "'self'", "'unsafe-inline'" ),
				'img-src'         => array( "'self'", 'data:' ),
				'font-src'        => array( "'self'", 'data:' ),
				'connect-src'     => array( "'self'" ),
				'media-src'       => array( "'self'" ),
				'frame-src'       => array( "'self'" ),
				'worker-src'      => array( "'self'", 'blob:' ),
				'manifest-src'    => array( "'self'" ),
				'object-src'      => array( "'none'" ),
				'base-uri'        => array( "'self'" ),
				'form-action'     => array( "'self'" ),
				'frame-ancestors' => array( "'self'" ),
			),
			'upgrade_insecure' => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
		);
	}

	/**
	 * Whether a policy has been stored yet.
	 *
	 * @return bool
	 */
	public static function exists() {
		return false !== get_option( self::OPTION );
	}

	/**
	 * Get the stored policy, with every managed directive present.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['directives'] ) ) {
			return self::baseline();
		}
		$policy = array(
			'directives'       => array(),
			'upgrade_insecure' => ! empty( $stored['upgrade_insecure'] ),
		);
		foreach ( array_keys( self::directive_definitions() ) as $name ) {
			$policy['directives'][ $name ] = isset( $stored['directives'][ $name ] ) ? array_values( (array) $stored['directives'][ $name ] ) : array();
		}
		return $policy;
	}

	/**
	 * Sanitize and store a policy.
	 *
	 * @param array $policy   Policy array.
	 * @param array $rejected Filled with tokens that were not valid sources.
	 * @return array The stored policy.
	 */
	public static function save( array $policy, &$rejected = array() ) {
		$clean = array(
			'directives'       => array(),
			'upgrade_insecure' => ! empty( $policy['upgrade_insecure'] ),
		);
		foreach ( array_keys( self::directive_definitions() ) as $name ) {
			$raw                          = isset( $policy['directives'][ $name ] ) ? $policy['directives'][ $name ] : array();
			$clean['directives'][ $name ] = self::sanitize_sources( $raw, $rejected );
		}
		update_option( self::OPTION, $clean, true );
		return $clean;
	}

	/**
	 * Sanitize a list of sources (array, or a string separated by spaces,
	 * commas or new lines).
	 *
	 * @param array|string $sources  Raw sources.
	 * @param array        $rejected Filled with invalid tokens.
	 * @return array
	 */
	public static function sanitize_sources( $sources, &$rejected = array() ) {
		if ( ! is_array( $sources ) ) {
			$sources = preg_split( '/[\s,]+/', (string) $sources, -1, PREG_SPLIT_NO_EMPTY );
		}
		$clean = array();
		foreach ( $sources as $token ) {
			if ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) {
				continue;
			}
			$source = self::sanitize_source( $token );
			if ( '' === $source ) {
				$rejected[] = (string) $token;
				continue;
			}
			$clean[ $source ] = true;
		}
		$clean = array_keys( $clean );

		// A rejected token is usually half of a mangled paste - a whole policy
		// dropped into one row, for instance "'self' https://cdn.test;object-src *".
		// Splitting that on whitespace leaves "*" standing on its own as a
		// perfectly valid source, so the row would be saved allowing
		// *everything* while the admin's attention is on the token that was
		// reported as rejected. In that company a network-wide source is not a
		// considered decision, so it is refused and reported too. Only
		// widening sources are dropped; data: and blob: are left alone, and the
		// row is never emptied by this rule.
		if ( $rejected ) {
			foreach ( $clean as $index => $source ) {
				if ( '*' === $source || in_array( $source, array( 'http:', 'https:', 'ws:', 'wss:' ), true ) ) {
					$rejected[] = $source;
					unset( $clean[ $index ] );
				}
			}
			$clean = array_values( $clean );
		}

		// 'none' only has a meaning on its own.
		if ( count( $clean ) > 1 ) {
			$clean = array_values( array_diff( $clean, array( "'none'" ) ) );
		}
		return $clean;
	}

	/**
	 * Validate a single source expression.
	 *
	 * This is the security boundary for everything that ends up in the header:
	 * only keywords, nonces/hashes, schemes and host sources pass, and nothing
	 * containing whitespace, quotes, semicolons or commas can get through.
	 *
	 * @param string $token Raw token.
	 * @return string Normalised source, or '' if invalid.
	 */
	public static function sanitize_source( $token ) {
		$token = (string) $token;

		// trim()'s default character list includes "\0", so a trailing null
		// byte would be removed before the check below ever saw it and the
		// rest of the token accepted. Whatever produced a control character
		// here was not someone typing a host name.
		if ( preg_match( '/[\x00-\x1f\x7f]/', $token ) ) {
			return '';
		}

		$token = trim( $token );
		if ( '' === $token || strlen( $token ) > 500 || preg_match( '/[\s;,"<>\\\\]/', $token ) ) {
			return '';
		}

		$lower = strtolower( $token );
		$bare  = trim( $lower, "'" );

		if ( in_array( $bare, self::keywords(), true ) ) {
			return "'" . $bare . "'";
		}
		if ( preg_match( '/^\'?((?:nonce|sha256|sha384|sha512)-[A-Za-z0-9+\/_=-]+)\'?$/', $token, $m ) ) {
			return "'" . $m[1] . "'";
		}
		if ( '*' === $token ) {
			return '*';
		}
		// Friendly shortcuts: "data" means "data:", "https" means "https:".
		if ( in_array( $lower, array( 'data', 'blob', 'filesystem', 'mediastream', 'https', 'http', 'wss', 'ws' ), true ) ) {
			return $lower . ':';
		}
		if ( preg_match( '/^[a-z][a-z0-9+.-]*:$/', $lower ) ) {
			return $lower;
		}
		if ( preg_match( '#^(?:([a-z][a-z0-9+.-]*)://)?((?:\*\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*|\*)(:(?:\d{1,5}|\*))?(/[^\']*)?$#i', $token, $m ) ) {
			$scheme = ! empty( $m[1] ) ? strtolower( $m[1] ) . '://' : '';
			$port   = isset( $m[3] ) ? $m[3] : '';
			$path   = isset( $m[4] ) ? $m[4] : '';
			return $scheme . strtolower( $m[2] ) . $port . $path;
		}
		return '';
	}

	/**
	 * Add a source to a directive.
	 *
	 * @param string $directive Managed directive name.
	 * @param string $source    Source expression.
	 * @return bool
	 */
	public static function add_source( $directive, $source ) {
		return self::add_sources( array( $directive => array( $source ) ) ) > 0;
	}

	/**
	 * Add several sources in one write.
	 *
	 * @param array $map directive => sources[].
	 * @return int Number of sources added.
	 */
	public static function add_sources( array $map ) {
		$defs   = self::directive_definitions();
		$policy = self::get();
		$added  = 0;
		foreach ( $map as $directive => $sources ) {
			if ( ! isset( $defs[ $directive ] ) ) {
				continue;
			}
			foreach ( (array) $sources as $source ) {
				$source = self::sanitize_source( $source );
				if ( '' === $source || in_array( $source, $policy['directives'][ $directive ], true ) ) {
					continue;
				}
				$policy['directives'][ $directive ][] = $source;
				++$added;
			}
		}
		if ( $added ) {
			self::save( $policy );
		}
		return $added;
	}

	/**
	 * Remove several sources in one write.
	 *
	 * @param array $map directive => sources[].
	 * @return int Number of sources removed.
	 */
	public static function remove_sources( array $map ) {
		$policy  = self::get();
		$removed = 0;
		foreach ( $map as $directive => $sources ) {
			if ( ! isset( $policy['directives'][ $directive ] ) ) {
				continue;
			}
			$before                             = count( $policy['directives'][ $directive ] );
			$policy['directives'][ $directive ] = array_values( array_diff( $policy['directives'][ $directive ], (array) $sources ) );
			$removed                           += $before - count( $policy['directives'][ $directive ] );
		}
		if ( $removed ) {
			self::save( $policy );
		}
		return $removed;
	}

	/**
	 * Swap one source for a set of narrower ones, in a single write.
	 *
	 * Used when a site owner blocks a host that a wildcard permits: the
	 * wildcard is replaced by the specific origins that are actually in use,
	 * minus the one being blocked. Doing it in one save() means the policy is
	 * never persisted in a half-narrowed state.
	 *
	 * @param string $directive    Managed directive.
	 * @param string $old          Source to remove.
	 * @param array  $replacements Sources to put in its place.
	 * @return bool Whether the policy changed.
	 */
	public static function replace_source( $directive, $old, array $replacements ) {
		$policy = self::get();
		if ( ! isset( $policy['directives'][ $directive ] ) ) {
			return false;
		}
		$before = $policy['directives'][ $directive ];
		$list   = array_values( array_diff( $before, array( $old ) ) );

		foreach ( $replacements as $source ) {
			$source = self::sanitize_source( $source );
			if ( '' !== $source && ! in_array( $source, $list, true ) ) {
				$list[] = $source;
			}
		}
		if ( $list === $before ) {
			return false;
		}
		$policy['directives'][ $directive ] = $list;
		self::save( $policy );
		return true;
	}

	/**
	 * Whether a list of policy sources already allows a given origin source.
	 * Understands exact matches, "*", scheme sources and "*.example.com".
	 *
	 * @param array  $list   Sources of one directive.
	 * @param string $source Source to test (origin, scheme or keyword).
	 * @return bool
	 */
	public static function covers( array $list, $source ) {
		return array() !== self::covering_sources( $list, $source );
	}

	/**
	 * Every entry in a list that permits a given origin source.
	 *
	 * This is the matching engine behind covers(). It is kept separate because
	 * blocking a source needs to know *which* entries permit it, not merely
	 * that something does: CSP has no deny-exception mechanism, so an entry
	 * broader than the source (a wildcard host, a scheme source, "*") has to be
	 * narrowed or reported to the site owner. Returning the list is what lets
	 * the Reports screen tell the truth about a block.
	 *
	 * @param array  $list          Sources of one directive.
	 * @param string $source        Source to test (origin, scheme or keyword).
	 * @param bool   $include_exact Include an exact match in the result.
	 * @return array Entries from $list that permit $source.
	 */
	public static function covering_sources( array $list, $source, $include_exact = true ) {
		$found = array();

		if ( $include_exact && in_array( $source, $list, true ) ) {
			$found[] = $source;
		}
		if ( ! preg_match( '#^([a-z][a-z0-9+.-]*)://([^/:]+)(:\d+)?$#', $source, $s ) ) {
			return $found;
		}
		foreach ( $list as $entry ) {
			if ( $entry === $source ) {
				continue; // Already handled above; never report it as "broader".
			}
			if ( '*' === $entry && in_array( $s[1], array( 'http', 'https', 'ws', 'wss' ), true ) ) {
				$found[] = $entry;
				continue;
			}
			if ( $entry === $s[1] . ':' || ( 'http:' === $entry && 'https' === $s[1] ) ) {
				$found[] = $entry;
				continue;
			}
			if ( ! preg_match( '#^(?:([a-z][a-z0-9+.-]*)://)?(\*\.)?([a-z0-9.-]+)(:(?:\d+|\*))?$#', $entry, $e ) ) {
				continue; // Keywords and sources with a path are not treated as covering.
			}
			if ( ! empty( $e[1] ) && $e[1] !== $s[1] && ! ( 'http' === $e[1] && 'https' === $s[1] ) ) {
				continue;
			}
			$port_ok = empty( $e[4] ) ? empty( $s[3] ) : ( ':*' === $e[4] || ( isset( $s[3] ) && $e[4] === $s[3] ) );
			if ( ! $port_ok ) {
				continue;
			}
			if ( ! empty( $e[2] ) ) {
				$suffix = '.' . $e[3];
				if ( substr( $s[2], -strlen( $suffix ) ) === $suffix ) {
					$found[] = $entry;
				}
			} elseif ( $e[3] === $s[2] ) {
				$found[] = $entry;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Map the directive named in a violation report onto a managed directive.
	 *
	 * @param string $raw Effective or violated directive from a report.
	 * @return string Managed directive, or '' if the plugin does not manage it.
	 */
	public static function map_directive( $raw ) {
		$parts = preg_split( '/\s+/', strtolower( trim( (string) $raw ) ) );
		$name  = $parts ? $parts[0] : '';
		$map   = array(
			'script-src-elem'  => 'script-src',
			'script-src-attr'  => 'script-src',
			'style-src-elem'   => 'style-src',
			'style-src-attr'   => 'style-src',
			'child-src'        => 'frame-src',
			'fenced-frame-src' => 'frame-src',
			'prefetch-src'     => 'default-src',
		);
		if ( isset( $map[ $name ] ) ) {
			return $map[ $name ];
		}
		$defs = self::directive_definitions();
		return isset( $defs[ $name ] ) ? $name : '';
	}

	/**
	 * Build a policy from the baseline plus scan findings.
	 *
	 * @param array $findings directive => source => where[].
	 * @param array $blocked  directive => source => true (never add these).
	 * @return array Unsaved policy.
	 */
	public static function from_findings( array $findings, array $blocked = array() ) {
		$policy = self::baseline();
		foreach ( $findings as $directive => $sources ) {
			if ( ! isset( $policy['directives'][ $directive ] ) ) {
				continue;
			}
			foreach ( array_keys( $sources ) as $source ) {
				if ( isset( $blocked[ $directive ][ $source ] ) ) {
					continue;
				}
				$policy['directives'][ $directive ][] = $source;
			}
		}
		return $policy;
	}

	/**
	 * The policy as a header value.
	 *
	 * @param bool   $enforce    True for an enforced policy, false for report-only.
	 * @param string $report_url Report URL, or ''.
	 * @return string
	 */
	public static function build_value( $enforce, $report_url = '' ) {
		$policy = self::get();
		$parts  = array();

		foreach ( $policy['directives'] as $name => $sources ) {
			if ( empty( $sources ) ) {
				$sources = array( "'none'" );
			}
			// Ask browsers to include a short code sample with script reports.
			if ( 'script-src' === $name && '' !== $report_url && ! in_array( "'none'", $sources, true ) && ! in_array( "'report-sample'", $sources, true ) ) {
				$sources[] = "'report-sample'";
			}
			$parts[] = $name . ' ' . implode( ' ', $sources );
		}

		// Browsers ignore this directive (with a console warning) in report-only mode.
		if ( $enforce && $policy['upgrade_insecure'] ) {
			$parts[] = 'upgrade-insecure-requests';
		}

		if ( '' !== $report_url ) {
			$parts[] = 'report-uri ' . $report_url;
			if ( 0 === strpos( $report_url, 'https://' ) ) {
				$parts[] = 'report-to acsp-endpoint';
			}
		}

		return implode( '; ', $parts );
	}

	/**
	 * All response headers for the current settings.
	 *
	 * @return array Header name => value. Empty when the plugin is disabled.
	 */
	public static function build_headers() {
		$mode = ACSP_Settings::get( 'mode' );
		if ( 'off' === $mode ) {
			return array();
		}
		$enforce    = 'enforce' === $mode;
		$report_url = ACSP_Settings::active_report_url();
		$headers    = array();

		// The Reporting API only works with secure endpoints; report-uri covers the rest.
		if ( '' !== $report_url && 0 === strpos( $report_url, 'https://' ) ) {
			$headers['Reporting-Endpoints'] = 'acsp-endpoint="' . $report_url . '"';
			$headers['Report-To']           = wp_json_encode(
				array(
					'group'     => 'acsp-endpoint',
					'max_age'   => DAY_IN_SECONDS,
					'endpoints' => array( array( 'url' => $report_url ) ),
				),
				JSON_UNESCAPED_SLASHES
			);
		}

		$name             = $enforce ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
		$headers[ $name ] = self::build_value( $enforce, $report_url );

		return $headers;
	}

	/**
	 * A human description for sources that are not plain web addresses.
	 *
	 * @param string $source Source expression.
	 * @return array { label, risky }
	 */
	public static function describe_source( $source ) {
		$map = array(
			"'unsafe-inline'"    => array( __( 'Inline code written directly in the page', 'auto-csp-manager' ), true ),
			"'unsafe-eval'"      => array( __( 'Code built from text at runtime (eval)', 'auto-csp-manager' ), true ),
			"'wasm-unsafe-eval'" => array( __( 'WebAssembly', 'auto-csp-manager' ), false ),
			'data:'              => array( __( 'Content embedded in the page as data: URLs', 'auto-csp-manager' ), false ),
			'blob:'              => array( __( 'Content generated in the browser (blob: URLs)', 'auto-csp-manager' ), false ),
			'*'                  => array( __( 'Anywhere on the web — this rule allows everything', 'auto-csp-manager' ), true ),
			"'strict-dynamic'"   => array( __( 'Trust scripts loaded by already-trusted scripts. While this is present browsers ignore every web address listed here, so the list below stops having any effect.', 'auto-csp-manager' ), true ),
		);
		if ( isset( $map[ $source ] ) ) {
			return array(
				'label' => $map[ $source ][0],
				'risky' => $map[ $source ][1],
			);
		}
		// A nonce is only protection if it changes on every response. This
		// plugin stores a policy, so a nonce written here is a fixed string in
		// every page: anyone reading the page can reuse it, which makes it no
		// better than 'unsafe-inline' while looking considerably better.
		if ( preg_match( "/^'nonce-/", $source ) ) {
			return array(
				'label' => __( 'A fixed nonce. Nonces only protect anything when they are different on every page load; a stored one can be copied out of the page and reused, so this is no stronger than allowing inline code.', 'auto-csp-manager' ),
				'risky' => true,
			);
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:$#', $source ) && in_array( $source, array( 'https:', 'http:' ), true ) ) {
			return array(
				'label' => __( 'Any site reachable over this scheme — far wider than a list of addresses', 'auto-csp-manager' ),
				'risky' => true,
			);
		}
		return array(
			'label' => '',
			'risky' => false,
		);
	}
}
