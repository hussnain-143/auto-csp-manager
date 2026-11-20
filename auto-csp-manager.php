<?php
/**
 * Plugin Name:       Auto CSP Manager
 * Description:       Builds a Content-Security-Policy automatically by scanning your database, theme and live pages, collects violation reports on a built-in endpoint, and lets you allow, block or ignore each reported source from wp-admin.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       auto-csp-manager
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

define( 'ACSP_VERSION', '1.2.0' );
define( 'ACSP_FILE', __FILE__ );
define( 'ACSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACSP_URL', plugin_dir_url( __FILE__ ) );
define( 'ACSP_REST_NAMESPACE', 'auto-csp-manager/v1' );

require_once ACSP_DIR . 'includes/class-acsp-settings.php';
require_once ACSP_DIR . 'includes/class-acsp-policy.php';
require_once ACSP_DIR . 'includes/class-acsp-db.php';
require_once ACSP_DIR . 'includes/class-acsp-scanner.php';
require_once ACSP_DIR . 'includes/class-acsp-reporter.php';
require_once ACSP_DIR . 'includes/class-acsp-headers.php';
require_once ACSP_DIR . 'includes/class-acsp-plugin.php';

/**
 * Load translations. WordPress.org-hosted plugins get this automatically from
 * WP 4.6, but a plugin installed by hand with bundled languages does not.
 */
function acsp_load_textdomain() {
	load_plugin_textdomain( 'auto-csp-manager', false, dirname( plugin_basename( ACSP_FILE ) ) . '/languages' );
}
add_action( 'init', 'acsp_load_textdomain' );

register_activation_hook( __FILE__, array( 'ACSP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ACSP_Plugin', 'deactivate' ) );

ACSP_Plugin::instance();
