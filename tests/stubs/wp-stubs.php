<?php
/**
 * The slice of WordPress the pure-logic classes touch.
 *
 * The CSP engine, report normalisation and markup extraction are the most
 * security-critical code in the plugin and they are all pure functions of
 * their input. Stubbing the handful of WordPress helpers they call means those
 * tests run anywhere, in milliseconds, with no database — so there is no
 * excuse for not running them. Anything that needs real WordPress behaviour
 * (wpdb, REST dispatch, capabilities) lives in tests/integration instead.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/**
 * In-memory option store.
 */
class ACSP_Test_State {

	/**
	 * Options.
	 *
	 * @var array
	 */
	public static $options = array();

	/**
	 * Transients.
	 *
	 * @var array
	 */
	public static $transients = array();

	/**
	 * Site address used by home_url()/site_url().
	 *
	 * @var string
	 */
	public static $home = 'https://example.com';

	/**
	 * Reset everything between tests.
	 *
	 * @param string $home Site address.
	 */
	public static function reset( $home = 'https://example.com' ) {
		self::$options    = array();
		self::$transients = array();
		self::$home       = $home;
	}
}

// --- options -----------------------------------------------------------

function get_option( $name, $default = false ) {
	return array_key_exists( $name, ACSP_Test_State::$options ) ? ACSP_Test_State::$options[ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	ACSP_Test_State::$options[ $name ] = $value;
	return true;
}

function add_option( $name, $value, $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, ACSP_Test_State::$options ) ) {
		return false;
	}
	ACSP_Test_State::$options[ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( ACSP_Test_State::$options[ $name ] );
	return true;
}

function get_transient( $key ) {
	return array_key_exists( $key, ACSP_Test_State::$transients ) ? ACSP_Test_State::$transients[ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	ACSP_Test_State::$transients[ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( ACSP_Test_State::$transients[ $key ] );
	return true;
}

// --- hooks (no-ops: the plugin's filters all have scalar/array defaults) ---

function apply_filters( $hook, $value ) {
	return $value;
}

function add_action() {
	return true;
}

function add_filter() {
	return true;
}

// --- i18n ---------------------------------------------------------------

function __( $text, $domain = null ) {
	return $text;
}

function esc_html__( $text, $domain = null ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

function number_format_i18n( $number ) {
	return (string) $number;
}

// --- URLs and sanitising -------------------------------------------------

function home_url( $path = '' ) {
	return ACSP_Test_State::$home . $path;
}

function site_url( $path = '' ) {
	return ACSP_Test_State::$home . $path;
}

function get_rest_url( $blog_id = null, $path = '' ) {
	return ACSP_Test_State::$home . '/wp-json/' . ltrim( $path, '/' );
}

function wp_parse_url( $url, $component = -1 ) {
	return -1 === $component ? wp_parse_url_all( $url ) : wp_parse_url_part( $url, $component );
}

function wp_parse_url_all( $url ) {
	$parts = parse_url( $url );
	return false === $parts ? false : $parts;
}

function wp_parse_url_part( $url, $component ) {
	$parts = parse_url( $url );
	if ( false === $parts ) {
		return false;
	}
	$map = array(
		PHP_URL_SCHEME   => 'scheme',
		PHP_URL_HOST     => 'host',
		PHP_URL_PORT     => 'port',
		PHP_URL_PATH     => 'path',
		PHP_URL_QUERY    => 'query',
		PHP_URL_FRAGMENT => 'fragment',
	);
	$key = isset( $map[ $component ] ) ? $map[ $component ] : '';
	return ( '' !== $key && isset( $parts[ $key ] ) ) ? $parts[ $key ] : null;
}

function esc_url_raw( $url, $protocols = null ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$url = str_replace( array( ' ', "\t", "\n", "\r" ), '', $url );
	if ( null !== $protocols ) {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' !== $scheme && ! in_array( $scheme, (array) $protocols, true ) ) {
			return '';
		}
	}
	return $url;
}

function sanitize_text_field( $value ) {
	$value = (string) $value;
	$value = strip_tags( $value );
	$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
	$value = preg_replace( '/[\x00-\x1f\x7f]/', '', $value );
	return trim( preg_replace( '/\s+/', ' ', $value ) );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_check_invalid_utf8( $value, $strip = false ) {
	$value = (string) $value;
	return '' === $value || preg_match( '//u', $value ) ? $value : '';
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( (array) $defaults, (array) $args );
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

function wp_hash( $value, $scheme = 'auth' ) {
	return md5( 'test-salt' . $value );
}

function wp_rand( $min = 0, $max = 0 ) {
	return $min;
}

function wp_normalize_path( $path ) {
	return str_replace( '\\', '/', (string) $path );
}

function current_time( $type = 'mysql', $gmt = 0 ) {
	return gmdate( 'Y-m-d H:i:s' );
}

function wp_using_ext_object_cache() {
	return false;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Minimal WP_Error.
 */
class WP_Error {

	/**
	 * Message.
	 *
	 * @var string
	 */
	protected $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 */
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}

	/**
	 * Message.
	 *
	 * @return string
	 */
	public function get_error_message() {
		return $this->message;
	}
}
