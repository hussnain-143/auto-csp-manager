<?php
/**
 * Report storage against a real database: de-duplication, decisions, the size
 * cap and what an unauthenticated flood can and cannot do.
 *
 * Requires the WordPress test framework.
 *
 * @package AutoCSPManager
 */

/**
 * ACSP_DB.
 */
class ReportStorageTest extends WP_UnitTestCase {

	/**
	 * Install the table and reset state.
	 */
	public function set_up() {
		parent::set_up();
		ACSP_DB::install();
		ACSP_DB::clear_full_flag();
		delete_option( ACSP_DB::ADVISORY_OPTION );
		delete_transient( ACSP_DB::COUNT_TRANSIENT );
		ACSP_Policy::save( ACSP_Policy::baseline() );
	}

	/**
	 * A normalised report row.
	 *
	 * @param string $source    Source expression.
	 * @param string $directive Managed directive.
	 * @return array
	 */
	protected function report( $source = 'https://evil.test', $directive = 'script-src' ) {
		return array(
			'directive'           => $directive,
			'effective_directive' => $directive,
			'source'              => $source,
			'blocked_uri'         => $source . '/x.js',
			'document_uri'        => home_url( '/page/' ),
			'source_file'         => home_url( '/page/' ),
			'line_number'         => 1,
			'script_sample'       => '',
			'disposition'         => 'report',
			'user_agent'          => 'Test/1.0',
		);
	}

	/**
	 * One row per source, with a hit counter.
	 */
	public function test_reports_are_deduplicated() {
		$this->assertSame( 'inserted', ACSP_DB::record( $this->report() ) );
		$this->assertSame( 'updated', ACSP_DB::record( $this->report() ) );
		$this->assertSame( 'updated', ACSP_DB::record( $this->report() ) );

		$counts = ACSP_DB::counts();
		$this->assertSame( 1, $counts['all'] );

		$rows = ACSP_DB::query( array() );
		$this->assertSame( 3, (int) $rows['items'][0]->hits );
	}

	/**
	 * An ignored source is a rule: it stops being counted and stops being
	 * updated, but the row stays as the rule itself.
	 */
	public function test_ignored_rows_are_left_alone() {
		ACSP_DB::record( $this->report() );
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;
		$this->assertTrue( ACSP_DB::apply_action( $id, 'ignore' ) );

		$this->assertSame( 'ignored', ACSP_DB::record( $this->report() ) );

		$row = ACSP_DB::get( $id );
		$this->assertSame( 'ignored', $row->status );
		$this->assertSame( 1, (int) $row->hits, 'An ignored rule does not accumulate hits.' );
	}

	/**
	 * Allowing a report puts the source in the policy.
	 */
	public function test_allow_adds_to_the_policy() {
		ACSP_DB::record( $this->report() );
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;

		$this->assertTrue( ACSP_DB::apply_action( $id, 'allow' ) );
		$this->assertContains( 'https://evil.test', ACSP_Policy::get()['directives']['script-src'] );
		$this->assertSame( 'allowed', ACSP_DB::get( $id )->status );
	}

	/**
	 * Blocking a source with nothing broader in the way works as before.
	 */
	public function test_block_removes_from_the_policy() {
		ACSP_Policy::add_source( 'script-src', 'https://evil.test' );
		ACSP_DB::record( $this->report() );
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;

		$conflict = array();
		$this->assertTrue( ACSP_DB::apply_action( $id, 'block', $conflict ) );
		$this->assertSame( array(), $conflict );
		$this->assertNotContains( 'https://evil.test', ACSP_Policy::get()['directives']['script-src'] );
		$this->assertSame( 'blocked', ACSP_DB::get( $id )->status );
		$this->assertSame( array(), ACSP_DB::advisory_for( 'script-src', 'https://evil.test' ) );
	}

	/**
	 * Regression for the block that was not a block.
	 *
	 * With a wildcard in the policy the request is refused and the row is left
	 * undecided, instead of being labelled "blocked" while the browser carries
	 * on allowing it.
	 */
	public function test_block_is_refused_when_a_wildcard_still_allows_it() {
		ACSP_Policy::save(
			array(
				'directives' => array( 'script-src' => array( "'self'", 'https://*.example.test' ) ),
			)
		);
		ACSP_DB::record( $this->report( 'https://malicious.example.test' ) );
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;

		$conflict = array();
		$this->assertFalse( ACSP_DB::apply_action( $id, 'block', $conflict ) );
		$this->assertSame( array( 'https://*.example.test' ), $conflict['covered_by'] );
		$this->assertSame( 'new', ACSP_DB::get( $id )->status, 'The row must not claim to be blocked.' );
	}

	/**
	 * Choosing to keep the wildcard records the block as advisory, and that
	 * is visible afterwards rather than looking like a real block.
	 */
	public function test_advisory_block_is_recorded_as_such() {
		ACSP_Policy::save(
			array(
				'directives' => array( 'script-src' => array( "'self'", 'https://*.example.test' ) ),
			)
		);
		ACSP_DB::record( $this->report( 'https://malicious.example.test' ) );
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;

		$this->assertTrue( ACSP_DB::apply_action( $id, 'block_advisory' ) );
		$this->assertSame( 'blocked', ACSP_DB::get( $id )->status );
		$this->assertSame( array( 'https://*.example.test' ), ACSP_DB::advisory_for( 'script-src', 'https://malicious.example.test' ) );
		$this->assertSame( 1, ACSP_DB::advisory_count() );

		// Changing the decision clears the marker.
		ACSP_DB::apply_action( $id, 'reset' );
		$this->assertSame( array(), ACSP_DB::advisory_for( 'script-src', 'https://malicious.example.test' ) );
	}

	/**
	 * A blocked source is never re-added by a scan.
	 */
	public function test_blocked_sources_are_not_reintroduced_by_a_scan() {
		ACSP_DB::record( $this->report() );
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;
		ACSP_DB::apply_action( $id, 'block' );

		$policy = ACSP_Policy::from_findings(
			array( 'script-src' => array( 'https://evil.test' => array( 'Page /' ) ) ),
			ACSP_DB::blocked_map()
		);

		$this->assertNotContains( 'https://evil.test', $policy['directives']['script-src'] );
	}

	/**
	 * The log is a rolling window, not a bucket that freezes when full.
	 *
	 * An unauthenticated flood used to fill the table and then every genuine
	 * violation was dropped in silence. Stale undecided rows are now recycled,
	 * and when nothing can safely be recycled the condition is recorded so the
	 * admin screens can say so.
	 */
	public function test_full_log_recycles_stale_rows_and_reports_itself() {
		global $wpdb;

		ACSP_Settings::update( array( 'max_rows' => 100 ) );

		for ( $i = 0; $i < 100; $i++ ) {
			ACSP_DB::record( $this->report( 'https://flood' . $i . '.test' ) );
		}
		$this->assertSame( 100, ACSP_DB::counts()['all'] );

		// Nothing is stale yet, so the newest report is turned away - and said so.
		$this->assertSame( 'full', ACSP_DB::record( $this->report( 'https://genuine.test' ) ) );
		$this->assertGreaterThan( 0, ACSP_DB::full_since() );

		// Age one undecided row past the recycling threshold.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . ACSP_DB::table() . ' SET last_seen = %s WHERE source = %s',
				gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
				'https://flood0.test'
			)
		);
		delete_transient( ACSP_DB::COUNT_TRANSIENT );

		$this->assertSame( 'inserted', ACSP_DB::record( $this->report( 'https://genuine.test' ) ) );
		$this->assertSame( 100, ACSP_DB::counts()['all'], 'The cap still holds.' );

		$sources = wp_list_pluck( ACSP_DB::query( array( 'per_page' => 200 ) )['items'], 'source' );
		$this->assertContains( 'https://genuine.test', $sources );
		$this->assertNotContains( 'https://flood0.test', $sources, 'The stale row was the one recycled.' );
	}

	/**
	 * A decision is never recycled or purged, however old it is.
	 */
	public function test_decisions_survive_purge_and_eviction() {
		global $wpdb;

		ACSP_DB::record( $this->report( 'https://decided.test' ) );
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;
		ACSP_DB::apply_action( $id, 'allow' );

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . ACSP_DB::table() . ' SET last_seen = %s',
				gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS )
			)
		);

		ACSP_DB::purge( 1 );
		$this->assertNotNull( ACSP_DB::get( $id ) );

		ACSP_DB::clear_new();
		$this->assertNotNull( ACSP_DB::get( $id ) );
	}

	/**
	 * Searching and sorting cannot be used to reach outside the whitelist.
	 */
	public function test_query_arguments_are_constrained() {
		ACSP_DB::record( $this->report() );

		$result = ACSP_DB::query(
			array(
				'orderby'  => 'id; DROP TABLE wp_posts',
				'order'    => 'nonsense',
				'status'   => 'not-a-status',
				'search'   => "%' OR 1=1 --",
				'per_page' => 0,
				'paged'    => -5,
			)
		);

		$this->assertIsArray( $result['items'] );
		$this->assertSame( 0, $result['total'], 'A LIKE search for an injection string matches nothing.' );
	}
}
