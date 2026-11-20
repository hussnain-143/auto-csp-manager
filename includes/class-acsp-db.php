<?php
/**
 * Violation report storage.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports are stored de-duplicated: one row per (directive, source) pair with
 * a hit counter, so a busy site cannot grow the table without bound.
 *
 * A row's status doubles as the site owner's decision about that source:
 * new (undecided), allowed, blocked or ignored.
 */
class ACSP_DB {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'acsp_db_version';
	const COUNT_TRANSIENT   = 'acsp_row_count';
	const FULL_OPTION       = 'acsp_log_full';
	const ADVISORY_OPTION   = 'acsp_advisory_blocks';

	/**
	 * Valid statuses.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'new'     => __( 'Needs review', 'auto-csp-manager' ),
			'allowed' => __( 'Allowed', 'auto-csp-manager' ),
			'blocked' => __( 'Blocked', 'auto-csp-manager' ),
			'ignored' => __( 'Ignored', 'auto-csp-manager' ),
		);
	}

	/**
	 * Table name for the current site.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'acsp_reports';
	}

	/**
	 * Create or upgrade the table when needed.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hash char(32) NOT NULL,
				directive varchar(40) NOT NULL DEFAULT '',
				effective_directive varchar(40) NOT NULL DEFAULT '',
				source varchar(255) NOT NULL DEFAULT '',
				blocked_uri text NOT NULL,
				document_uri text NOT NULL,
				source_file text NOT NULL,
				line_number int(10) unsigned NOT NULL DEFAULT 0,
				script_sample varchar(255) NOT NULL DEFAULT '',
				disposition varchar(10) NOT NULL DEFAULT '',
				user_agent varchar(255) NOT NULL DEFAULT '',
				hits bigint(20) unsigned NOT NULL DEFAULT 1,
				status varchar(10) NOT NULL DEFAULT 'new',
				first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash),
				KEY status (status),
				KEY last_seen (last_seen)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Hash identifying a (directive, source) pair.
	 *
	 * @param string $directive Managed directive.
	 * @param string $source    Normalised source.
	 * @return string
	 */
	public static function hash( $directive, $source ) {
		return md5( $directive . '|' . $source );
	}

	/**
	 * Record one violation.
	 *
	 * @param array $report Normalised report fields.
	 * @return string inserted | updated | ignored | full | error
	 */
	public static function record( array $report ) {
		global $wpdb;

		$table = self::table();
		$hash  = self::hash( $report['directive'], $report['source'] );
		$now   = current_time( 'mysql', true );

		$row = array(
			'hash'                => $hash,
			'directive'           => self::cut( $report['directive'], 40 ),
			'effective_directive' => self::cut( $report['effective_directive'], 40 ),
			'source'              => self::cut( $report['source'], 255 ),
			'blocked_uri'         => self::cut( $report['blocked_uri'], 2000 ),
			'document_uri'        => self::cut( $report['document_uri'], 2000 ),
			'source_file'         => self::cut( $report['source_file'], 2000 ),
			'line_number'         => absint( $report['line_number'] ),
			'script_sample'       => self::cut( $report['script_sample'], 255 ),
			'disposition'         => self::cut( $report['disposition'], 10 ),
			'user_agent'          => self::cut( $report['user_agent'], 255 ),
			'hits'                => 1,
			'status'              => 'new',
			'first_seen'          => $now,
			'last_seen'           => $now,
		);

		// The endpoint is public and reports arrive concurrently, so the whole
		// write is one atomic upsert keyed on the unique hash. A SELECT followed
		// by an INSERT loses a report whenever two requests race for the same
		// new (directive, source) pair. An ignored row is left untouched: the
		// owner's decision is a rule, not a counter.
		$columns      = array_keys( $row );
		$placeholders = array();
		foreach ( $columns as $column ) {
			$placeholders[] = 'line_number' === $column || 'hits' === $column ? '%d' : '%s';
		}
		$updates = array(
			'hits = hits + IF( status = \'ignored\', 0, 1 )',
			'last_seen = IF( status = \'ignored\', last_seen, VALUES( last_seen ) )',
		);
		foreach ( array( 'effective_directive', 'blocked_uri', 'document_uri', 'source_file', 'line_number', 'script_sample', 'disposition', 'user_agent' ) as $column ) {
			$updates[] = $column . ' = IF( status = \'ignored\', ' . $column . ', VALUES( ' . $column . ' ) )';
		}

		$sql = "INSERT INTO {$table} (" . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $placeholders ) . ') '
			. 'ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );

		// Only a brand new pair can grow the table. The cached count keeps this
		// to one transient read per report until the log is actually near its
		// limit; the extra lookups only happen at the point they matter.
		if ( self::row_count() >= (int) ACSP_Settings::get( 'max_rows' )
			&& ! self::row_exists( $hash )
			&& ! self::make_room()
		) {
			return 'full';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column list and table name are internal; every value is a placeholder.
		$affected = $wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) );

		if ( false === $affected ) {
			return 'error';
		}
		// MySQL reports 1 row affected for an insert, 2 for an update that
		// changed something, 0 when the row was left alone (an ignored rule).
		if ( 1 === (int) $affected ) {
			self::bump_count( 1 );
			return 'inserted';
		}
		return 0 === (int) $affected ? 'ignored' : 'updated';
	}

	/**
	 * Whether a row for this hash already exists.
	 *
	 * @param string $hash Row hash.
	 * @return bool
	 */
	protected static function row_exists( $hash ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE hash = %s", $hash ) );
	}

	/**
	 * Cached row count, so a public endpoint does not run COUNT(*) per report.
	 *
	 * @param bool $fresh Force a re-count.
	 * @return int
	 */
	public static function row_count( $fresh = false ) {
		global $wpdb;

		$count = get_transient( self::COUNT_TRANSIENT );
		if ( $fresh || false === $count ) {
			$table = self::table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			set_transient( self::COUNT_TRANSIENT, $count, 5 * MINUTE_IN_SECONDS );
		}
		return (int) $count;
	}

	/**
	 * Adjust the cached row count.
	 *
	 * @param int $delta Change.
	 */
	protected static function bump_count( $delta ) {
		$count = get_transient( self::COUNT_TRANSIENT );
		if ( false !== $count ) {
			set_transient( self::COUNT_TRANSIENT, max( 0, (int) $count + (int) $delta ), 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Make sure one more row can be stored.
	 *
	 * The endpoint is public, so anyone can post reports naming any number of
	 * distinct sources. Without this the table simply freezes at the cap and
	 * every genuine violation after that is dropped in silence. Instead the log
	 * behaves as a rolling window: a single one-hit undecided row that nobody
	 * has looked at for a day is evicted to make space. Rows the owner decided
	 * on, rows seen more than once, and recent rows are never evicted, so a
	 * flood cannot push real evidence out of the log.
	 *
	 * @return bool Whether there is room now.
	 */
	protected static function make_room() {
		global $wpdb;

		$max = (int) ACSP_Settings::get( 'max_rows' );
		if ( self::row_count() < $max ) {
			return true;
		}
		// The cached count can be stale after a purge or a manual clear.
		if ( self::row_count( true ) < $max ) {
			return true;
		}

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; the value is a placeholder.
		$evicted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE status = 'new' AND hits = 1 AND last_seen < %s ORDER BY last_seen ASC LIMIT 1",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $evicted > 0 ) {
			self::bump_count( -$evicted );
			return true;
		}

		// Nothing safe to evict: record that the log is full so the admin
		// screens can say so instead of quietly losing reports.
		update_option( self::FULL_OPTION, time(), false );
		return false;
	}

	/**
	 * When the report log last had to turn a report away, or 0.
	 *
	 * @return int Unix timestamp.
	 */
	public static function full_since() {
		return (int) get_option( self::FULL_OPTION, 0 );
	}

	/**
	 * Clear the "log is full" flag.
	 */
	public static function clear_full_flag() {
		delete_option( self::FULL_OPTION );
	}

	/**
	 * Truncate a string safely.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max length.
	 * @return string
	 */
	protected static function cut( $value, $max ) {
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/**
	 * Query reports for the admin table.
	 *
	 * @param array $args status, search, orderby, order, per_page, paged.
	 * @return array { items, total }
	 */
	public static function query( array $args ) {
		global $wpdb;

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && isset( self::statuses()[ $args['status'] ] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(source LIKE %s OR blocked_uri LIKE %s OR document_uri LIKE %s OR directive LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$orderby  = in_array( isset( $args['orderby'] ) ? $args['orderby'] : '', array( 'hits', 'last_seen', 'first_seen', 'source', 'directive' ), true ) ? $args['orderby'] : 'last_seen';
		$order    = ( isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, absint( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) );
		$offset   = ( max( 1, absint( isset( $args['paged'] ) ? $args['paged'] : 1 ) ) - 1 ) * $per_page;
		$where    = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$items_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- built from whitelisted fragments and placeholders.
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, array_merge( $params, array( $per_page, $offset ) ) ) );
		// phpcs:enable

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Row counts per status.
	 *
	 * @return array status => count, plus 'all'.
	 */
	public static function counts() {
		global $wpdb;
		$table  = self::table();
		$counts = array_fill_keys( array_keys( self::statuses() ), 0 );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" );
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}
		$counts['all'] = array_sum( $counts );
		return $counts;
	}

	/**
	 * Get one report.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Sources the site owner has blocked, so scans never re-add them.
	 *
	 * @return array directive => source => true
	 */
	public static function blocked_map() {
		return self::status_map( 'blocked' );
	}

	/**
	 * Sources with a given decision.
	 *
	 * @param string $status allowed | blocked | ignored | new.
	 * @return array directive => source => true
	 */
	public static function status_map( $status ) {
		global $wpdb;
		$table = self::table();
		$map   = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT directive, source FROM {$table} WHERE status = %s", $status ) );
		foreach ( (array) $rows as $row ) {
			$map[ $row->directive ][ $row->source ] = true;
		}
		return $map;
	}

	/**
	 * Apply a decision to a report.
	 *
	 * - allow:  add the source to the policy.
	 * - block:  remove it from the policy and stop scans from re-adding it.
	 * - ignore: keep the row as a rule; matching reports are dropped on arrival.
	 * - reset:  back to "needs review".
	 * - delete: remove the row (the source will be logged again if it recurs).
	 *
	 * Blocking is the one decision the policy cannot always express. CSP has no
	 * deny-exception mechanism, so when a broader entry (a wildcard host, a
	 * scheme source, "*") still permits the source, this refuses the block and
	 * reports the covering entries through $conflict. The caller decides what
	 * to offer the owner; the row is left undecided rather than being labelled
	 * "blocked" while the browser still allows it.
	 *
	 * @param int    $id       Row ID.
	 * @param string $action   Action name.
	 * @param array  $conflict Filled with { directive, source, covered_by[] }
	 *                         when a block cannot take effect.
	 * @return bool
	 */
	public static function apply_action( $id, $action, &$conflict = array() ) {
		global $wpdb;

		$row = self::get( $id );
		if ( ! $row ) {
			return false;
		}
		$table = self::table();

		switch ( $action ) {
			case 'allow':
				ACSP_Policy::add_source( $row->directive, $row->source );
				$policy = ACSP_Policy::get();
				if ( ! isset( $policy['directives'][ $row->directive ] ) || ! in_array( $row->source, $policy['directives'][ $row->directive ], true ) ) {
					return false; // Not a source the policy can express.
				}
				self::set_advisory( $row->directive, $row->source, array() );
				return false !== $wpdb->update( $table, array( 'status' => 'allowed' ), array( 'id' => $row->id ) );

			case 'block':
				$policy  = ACSP_Policy::get();
				$list    = isset( $policy['directives'][ $row->directive ] ) ? $policy['directives'][ $row->directive ] : array();
				$broader = ACSP_Policy::covering_sources( $list, $row->source, false );
				if ( $broader ) {
					$conflict = array(
						'id'         => (int) $row->id,
						'directive'  => $row->directive,
						'source'     => $row->source,
						'covered_by' => $broader,
					);
					return false;
				}
				ACSP_Policy::remove_sources( array( $row->directive => array( $row->source ) ) );
				self::set_advisory( $row->directive, $row->source, array() );
				return false !== $wpdb->update( $table, array( 'status' => 'blocked' ), array( 'id' => $row->id ) );

			case 'block_advisory':
				// The owner chose to keep a broader rule in place and accept
				// that the block is a note to self, not an enforced boundary.
				$policy  = ACSP_Policy::get();
				$list    = isset( $policy['directives'][ $row->directive ] ) ? $policy['directives'][ $row->directive ] : array();
				$broader = ACSP_Policy::covering_sources( $list, $row->source, false );
				ACSP_Policy::remove_sources( array( $row->directive => array( $row->source ) ) );
				self::set_advisory( $row->directive, $row->source, $broader );
				return false !== $wpdb->update( $table, array( 'status' => 'blocked' ), array( 'id' => $row->id ) );

			case 'ignore':
				self::set_advisory( $row->directive, $row->source, array() );
				return false !== $wpdb->update( $table, array( 'status' => 'ignored' ), array( 'id' => $row->id ) );

			case 'reset':
				self::set_advisory( $row->directive, $row->source, array() );
				return false !== $wpdb->update( $table, array( 'status' => 'new' ), array( 'id' => $row->id ) );

			case 'delete':
				$deleted = (bool) $wpdb->delete( $table, array( 'id' => $row->id ) );
				if ( $deleted ) {
					self::bump_count( -1 );
					self::clear_full_flag();
				}
				return $deleted;
		}
		return false;
	}

	/**
	 * Delete every undecided report.
	 *
	 * @return int Rows deleted.
	 */
	public static function clear_new() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query( "DELETE FROM {$table} WHERE status = 'new'" );
		if ( $deleted > 0 ) {
			self::bump_count( -$deleted );
			self::clear_full_flag();
		}
		return $deleted;
	}

	/**
	 * Remove undecided reports not seen for a while. Decisions (allowed,
	 * blocked, ignored) are rules and are never purged automatically.
	 *
	 * @param int $days Retention in days.
	 */
	public static function purge( $days ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE status = 'new' AND last_seen < %s", $cutoff ) );
		if ( $deleted > 0 ) {
			self::bump_count( -$deleted );
			self::clear_full_flag();
		}
	}

	/**
	 * Record (or clear) the fact that a block is advisory only, because a
	 * broader policy entry still permits the source.
	 *
	 * Kept in an option rather than a new column so that existing installs need
	 * no schema migration. Keyed by the same hash as the report row.
	 *
	 * @param string $directive  Managed directive.
	 * @param string $source     Source expression.
	 * @param array  $covered_by Covering entries, or an empty array to clear.
	 */
	public static function set_advisory( $directive, $source, array $covered_by ) {
		$map  = (array) get_option( self::ADVISORY_OPTION, array() );
		$hash = self::hash( $directive, $source );

		if ( ! $covered_by ) {
			if ( ! isset( $map[ $hash ] ) ) {
				return;
			}
			unset( $map[ $hash ] );
		} else {
			$map[ $hash ] = array_values( array_map( 'strval', $covered_by ) );
		}
		update_option( self::ADVISORY_OPTION, $map, false );
	}

	/**
	 * The covering entries that make a block advisory, or an empty array.
	 *
	 * @param string $directive Managed directive.
	 * @param string $source    Source expression.
	 * @return array
	 */
	public static function advisory_for( $directive, $source ) {
		$map  = (array) get_option( self::ADVISORY_OPTION, array() );
		$hash = self::hash( $directive, $source );
		return isset( $map[ $hash ] ) ? (array) $map[ $hash ] : array();
	}

	/**
	 * How many blocks are advisory only.
	 *
	 * @return int
	 */
	public static function advisory_count() {
		return count( (array) get_option( self::ADVISORY_OPTION, array() ) );
	}

	/**
	 * Drop the table (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::FULL_OPTION );
		delete_option( self::ADVISORY_OPTION );
		delete_transient( self::COUNT_TRANSIENT );
	}
}
