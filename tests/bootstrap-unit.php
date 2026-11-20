<?php
/**
 * Bootstrap for the unit suite: stubs only, no WordPress, no database.
 *
 * This deliberately does not look for a WordPress test library. An earlier
 * version chose its mode by checking whether one happened to be installed,
 * which meant the unit suite silently changed behaviour - and broke - on any
 * machine that had also set up the integration environment. The suite a run
 * belongs to is a property of the run, not of the machine.
 *
 * @package AutoCSPManager
 */

define( 'ACSP_TESTS_ROOT', dirname( __DIR__ ) );

require_once ACSP_TESTS_ROOT . '/tests/stubs/wp-stubs.php';

define( 'ACSP_VERSION', '1.2.0' );
define( 'ACSP_FILE', ACSP_TESTS_ROOT . '/auto-csp-manager.php' );
define( 'ACSP_DIR', ACSP_TESTS_ROOT . '/' );
define( 'ACSP_URL', 'https://example.com/wp-content/plugins/auto-csp-manager/' );
define( 'ACSP_REST_NAMESPACE', 'auto-csp-manager/v1' );

// Dependency order, as the plugin file loads them.
require_once ACSP_TESTS_ROOT . '/includes/class-acsp-settings.php';
require_once ACSP_TESTS_ROOT . '/includes/class-acsp-policy.php';
require_once ACSP_TESTS_ROOT . '/includes/class-acsp-scanner.php';
require_once ACSP_TESTS_ROOT . '/includes/class-acsp-reporter.php';
