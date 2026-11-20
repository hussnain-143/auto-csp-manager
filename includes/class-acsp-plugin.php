<?php
/**
 * Plugin bootstrap.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the pieces together and handles activation.
 */
class ACSP_Plugin {

	const CRON_HOOK = 'acsp_daily_cleanup';

	/**
	 * Singleton.
	 *
	 * @var ACSP_Plugin|null
	 */
	protected static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return ACSP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
		return self::$instance;
	}

	/**
	 * Hook up.
	 */
	protected function init() {
		add_action( 'plugins_loaded', array( 'ACSP_DB', 'maybe_install' ) );
		add_action( self::CRON_HOOK, array( $this, 'cleanup' ) );
		add_action( 'admin_init', array( $this, 'ensure_setup' ), 5 );

		( new ACSP_Reporter() )->init();
		( new ACSP_Headers() )->init();

		if ( is_admin() ) {
			require_once ACSP_DIR . 'includes/class-acsp-admin.php';
			( new ACSP_Admin() )->init();
		}
	}

	/**
	 * Activation: create the table, scan the database and theme, and build
	 * the first policy. The plugin starts in report-only mode, so nothing on
	 * the site can break until the owner chooses to enforce.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide ) {
		ACSP_DB::install();

		if ( ! ACSP_Policy::exists() ) {
			self::initial_setup();
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! $network_wide ) {
			set_transient( 'acsp_activation_redirect', 1, MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Build the first policy.
	 *
	 * The policy that is saved here is the baseline only: 'self' plus the
	 * schemes WordPress itself needs. Third-party origins the scan finds are
	 * *not* added. A CSP plugin is very often installed because a site is
	 * already misbehaving, and a scan cannot tell a legitimate CDN from an
	 * attacker's host - it only sees what the site currently loads. Allowing
	 * all of it automatically would write the attacker's origin into the
	 * policy and then present it as hardened. The findings are stored for the
	 * Scanner screen to show, and the owner decides.
	 *
	 * The live page crawl is left for the admin screen, where it runs in
	 * small steps and cannot hold up or break plugin activation.
	 *
	 * @param bool $with_scan Run a database and theme scan to fill the review queue.
	 */
	public static function initial_setup( $with_scan = true ) {
		if ( $with_scan ) {
			try {
				$scanner = new ACSP_Scanner();
				$scanner->run_sync( false, 20 ); // Stores the result for review.
			} catch ( Throwable $e ) {
				// A failed scan must not stop the plugin from having a policy.
				$e = null;
			}
		}
		ACSP_Policy::save( ACSP_Policy::baseline() );
		update_option( 'acsp_needs_full_scan', 1, false );
	}

	/**
	 * Covers sites that never ran the activation hook (network activation,
	 * sites added to a network later).
	 */
	public function ensure_setup() {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! ACSP_Policy::exists() ) {
			// No scan here: this runs on an ordinary admin request, and a
			// database and theme scan can take tens of seconds. Saving the
			// baseline is instant, and the Scanner screen does the rest.
			self::initial_setup( false );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Daily clean-up of old, undecided reports.
	 */
	public function cleanup() {
		ACSP_DB::purge( (int) ACSP_Settings::get( 'retention_days' ) );
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		delete_transient( 'acsp_activation_redirect' );
		delete_transient( ACSP_Scanner::PROGRESS_TRANSIENT );
	}
}
