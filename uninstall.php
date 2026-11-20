<?php
/**
 * Uninstall: remove everything the plugin stored.
 *
 * @package AutoCSPManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clean up one site.
 */
function acsp_uninstall_site() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}acsp_reports" );

	foreach ( array( 'acsp_settings', 'acsp_policy', 'acsp_last_scan', 'acsp_db_version', 'acsp_needs_full_scan', 'acsp_log_full', 'acsp_advisory_blocks', 'acsp_foreign_csp' ) as $option ) {
		delete_option( $option );
	}
	// Short-lived rows: per-user notices, cached counts and rate-limit counters.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_acsp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_acsp_' ) . '%',
			$wpdb->esc_like( 'acsp_rl_' ) . '%'
		)
	);
	delete_transient( 'acsp_scan_progress' );
	delete_transient( 'acsp_activation_redirect' );
	wp_clear_scheduled_hook( 'acsp_daily_cleanup' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $acsp_site_id ) {
		switch_to_blog( $acsp_site_id );
		acsp_uninstall_site();
		restore_current_blog();
	}
} else {
	acsp_uninstall_site();
}
