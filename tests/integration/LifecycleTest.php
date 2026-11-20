<?php
/**
 * Activation, deactivation, migration and uninstall.
 *
 * Requires the WordPress test framework.
 *
 * @package AutoCSPManager
 */

/**
 * Plugin lifecycle.
 */
class LifecycleTest extends WP_UnitTestCase {

	/**
	 * DROP TABLE is not rolled back with the per-test transaction, so the
	 * schema is restored for whatever test runs next.
	 */
	public function tear_down() {
		ACSP_DB::install();
		parent::tear_down();
	}

	/**
	 * Activation creates the table, a policy, and the cleanup schedule.
	 */
	public function test_activation() {
		global $wpdb;

		delete_option( ACSP_Policy::OPTION );
		delete_option( ACSP_DB::DB_VERSION_OPTION );
		wp_clear_scheduled_hook( ACSP_Plugin::CRON_HOOK );

		ACSP_Plugin::activate( false );

		$table = ACSP_DB::table();
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertTrue( ACSP_Policy::exists() );
		$this->assertSame( 'report', ACSP_Settings::get( 'mode' ), 'A new install never enforces.' );
		$this->assertNotFalse( wp_next_scheduled( ACSP_Plugin::CRON_HOOK ) );
		$this->assertSame( 1, (int) get_option( 'acsp_needs_full_scan' ) );
	}

	/**
	 * Activating twice must not disturb an existing policy.
	 */
	public function test_activation_is_idempotent() {
		ACSP_Plugin::activate( false );
		ACSP_Policy::add_source( 'script-src', 'https://chosen-by-the-owner.test' );

		ACSP_Plugin::activate( false );

		$this->assertContains( 'https://chosen-by-the-owner.test', ACSP_Policy::get()['directives']['script-src'] );
	}

	/**
	 * Deactivation leaves the data but stops the schedule.
	 */
	public function test_deactivation_clears_the_schedule_only() {
		ACSP_Plugin::activate( false );
		ACSP_Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( ACSP_Plugin::CRON_HOOK ) );
		$this->assertTrue( ACSP_Policy::exists(), 'Deactivation is not uninstallation.' );
	}

	/**
	 * The table is created on demand for a site that never ran activation,
	 * which is how network activation and later-added sites behave.
	 */
	public function test_table_is_installed_on_demand() {
		global $wpdb;

		$table = ACSP_DB::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( ACSP_DB::DB_VERSION_OPTION );

		ACSP_DB::maybe_install();

		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertSame( ACSP_DB::DB_VERSION, get_option( ACSP_DB::DB_VERSION_OPTION ) );
	}

	/**
	 * Uninstall removes everything the plugin stored, including the newer
	 * options added for advisory blocks and the rate limiter.
	 */
	public function test_uninstall_removes_everything() {
		global $wpdb;

		// The test suite rewrites CREATE/DROP TABLE into temporary-table
		// statements, which would make uninstall's DROP a no-op against the
		// real table and the assertion below meaningless. Real DDL for this
		// test only; the schema is restored in tear_down().
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		ACSP_Plugin::activate( false );
		ACSP_DB::set_advisory( 'script-src', 'https://x.test', array( 'https://*.test' ) );
		update_option( ACSP_DB::FULL_OPTION, time(), false );
		update_option( 'acsp_foreign_csp', array( 'content-security-policy' ), false );
		add_option( 'acsp_rl_testcounter_1', '5', '', false );

		define( 'WP_UNINSTALL_PLUGIN', 'auto-csp-manager/auto-csp-manager.php' );
		require ACSP_DIR . 'uninstall.php';

		$table = ACSP_DB::table();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		foreach ( array( 'acsp_settings', 'acsp_policy', 'acsp_last_scan', 'acsp_db_version', 'acsp_needs_full_scan', 'acsp_log_full', 'acsp_advisory_blocks', 'acsp_foreign_csp' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option . ' must be gone.' );
		}
		$this->assertSame( array(), $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'acsp_rl_%'" ) );
		$this->assertFalse( wp_next_scheduled( ACSP_Plugin::CRON_HOOK ) );
	}

	/**
	 * The daily job only removes undecided reports that have gone quiet.
	 */
	public function test_cron_purge_keeps_decisions() {
		global $wpdb;

		ACSP_DB::install();
		ACSP_Settings::update( array( 'retention_days' => 1 ) );

		$row = array(
			'directive'           => 'script-src',
			'effective_directive' => 'script-src',
			'source'              => 'https://old.test',
			'blocked_uri'         => 'https://old.test/x.js',
			'document_uri'        => home_url( '/' ),
			'source_file'         => home_url( '/' ),
			'line_number'         => 0,
			'script_sample'       => '',
			'disposition'         => 'report',
			'user_agent'          => 'Test/1.0',
		);
		ACSP_DB::record( $row );
		$row['source'] = 'https://old-but-allowed.test';
		ACSP_DB::record( $row );

		$allowed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . ACSP_DB::table() . ' WHERE source = %s', 'https://old-but-allowed.test' ) );
		ACSP_DB::apply_action( $allowed, 'allow' );

		$wpdb->query( $wpdb->prepare( 'UPDATE ' . ACSP_DB::table() . ' SET last_seen = %s', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ) );

		( new ACSP_Plugin_Cron_Proxy() )->run();

		$sources = wp_list_pluck( ACSP_DB::query( array( 'per_page' => 50 ) )['items'], 'source' );
		$this->assertNotContains( 'https://old.test', $sources );
		$this->assertContains( 'https://old-but-allowed.test', $sources );
	}
}

/**
 * The cleanup callback is an instance method on the singleton; this reaches it
 * without having to fire WP-Cron.
 */
class ACSP_Plugin_Cron_Proxy {

	/**
	 * Run the daily cleanup.
	 */
	public function run() {
		ACSP_Plugin::instance()->cleanup();
	}
}
