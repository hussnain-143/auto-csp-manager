<?php
/**
 * Bootstrap for the integration suite: the real WordPress test library.
 *
 * @package AutoCSPManager
 */

define( 'ACSP_TESTS_ROOT', dirname( __DIR__ ) );

$acsp_wp_tests = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : '/tmp/wordpress-tests-lib';

if ( ! file_exists( $acsp_wp_tests . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"The WordPress test library was not found at {$acsp_wp_tests}.\n"
		. "Install it with:  bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n"
		. "or set WP_TESTS_DIR to where it already lives.\n"
	);
	exit( 1 );
}

// The WordPress test suite needs the PHPUnit Polyfills; point it at our copy.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && file_exists( ACSP_TESTS_ROOT . '/vendor/yoast/phpunit-polyfills' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', ACSP_TESTS_ROOT . '/vendor/yoast/phpunit-polyfills' );
}

require_once $acsp_wp_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require ACSP_TESTS_ROOT . '/auto-csp-manager.php';
	}
);

require $acsp_wp_tests . '/includes/bootstrap.php';
