<?php
/**
 * The public REST endpoint: what it accepts, what it refuses, and the fact
 * that it can never change the policy.
 *
 * Requires the WordPress test framework.
 *
 * @package AutoCSPManager
 */

/**
 * ACSP_Reporter over real REST dispatch.
 */
class ReportEndpointTest extends WP_UnitTestCase {

	/**
	 * REST server.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Boot the REST server with the plugin's route.
	 */
	public function set_up() {
		parent::set_up();

		ACSP_DB::install();
		ACSP_Policy::save( ACSP_Policy::baseline() );
		ACSP_Settings::update(
			array(
				'mode'        => 'report',
				'report_dest' => 'internal',
			)
		);

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	/**
	 * Post a body to the report endpoint.
	 *
	 * @param mixed  $body         Body; arrays are JSON encoded.
	 * @param string $content_type Content type.
	 * @return WP_REST_Response
	 */
	protected function post( $body, $content_type = 'application/csp-report' ) {
		$request = new WP_REST_Request( 'POST', '/' . ACSP_REST_NAMESPACE . '/report' );
		$request->set_header( 'content-type', $content_type );
		$request->set_header( 'user-agent', 'Test/1.0' );
		$request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
		return $this->server->dispatch( $request );
	}

	/**
	 * A valid report is stored, and the endpoint stays quiet about it.
	 */
	public function test_valid_report_is_stored() {
		$response = $this->post(
			array(
				'csp-report' => array(
					'document-uri'        => home_url( '/page/' ),
					'effective-directive' => 'script-src',
					'blocked-uri'         => 'https://evil.test/x.js',
				),
			)
		);

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( $response->get_data() );
		$this->assertSame( 1, ACSP_DB::counts()['all'] );
	}

	/**
	 * The endpoint is a log, not a way into the policy. This is the property
	 * that matters most about it: anyone on the internet can post to it.
	 */
	public function test_a_report_cannot_change_the_policy() {
		$before = ACSP_Policy::get();

		$this->post(
			array(
				'csp-report' => array(
					'document-uri'        => home_url( '/page/' ),
					'effective-directive' => 'script-src',
					'blocked-uri'         => 'https://attacker.test/payload.js',
				),
			)
		);

		$this->assertSame( $before, ACSP_Policy::get(), 'Reports must never widen the policy.' );
		$this->assertSame( 'new', ACSP_DB::query( array() )['items'][0]->status, 'It waits for a decision.' );
	}

	/**
	 * Reports about other sites are refused, so the log cannot be filled with
	 * invented evidence attributed to this site.
	 */
	public function test_reports_about_other_sites_are_refused() {
		$this->post(
			array(
				'csp-report' => array(
					'document-uri'        => 'https://someone-else.test/page/',
					'effective-directive' => 'script-src',
					'blocked-uri'         => 'https://evil.test/x.js',
				),
			)
		);

		$this->assertSame( 0, ACSP_DB::counts()['all'] );
	}

	/**
	 * Malformed and oversized bodies are handled without storing anything.
	 */
	public function test_bad_bodies() {
		$this->assertSame( 204, $this->post( '' )->get_status() );
		$this->assertSame( 400, $this->post( '{not json' )->get_status() );
		$this->assertSame( 400, $this->post( '"a string"' )->get_status() );
		$this->assertSame( 413, $this->post( str_repeat( 'a', 70000 ) )->get_status() );
		$this->assertSame( 0, ACSP_DB::counts()['all'] );
	}

	/**
	 * A single request cannot smuggle in an unbounded number of rows.
	 */
	public function test_report_count_per_request_is_capped() {
		$batch = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$batch[] = array(
				'type' => 'csp-violation',
				'body' => array(
					'documentURL'        => home_url( '/page/' ),
					'effectiveDirective' => 'script-src',
					'blockedURL'         => 'https://flood' . $i . '.test/x.js',
				),
			);
		}

		$this->post( $batch, 'application/reports+json' );
		$this->assertSame( 20, ACSP_DB::counts()['all'], 'At most 20 reports are taken from one request.' );
	}

	/**
	 * Nothing is collected when the plugin is off or reporting elsewhere.
	 */
	public function test_endpoint_is_inert_when_not_collecting() {
		ACSP_Settings::update( array( 'mode' => 'off' ) );
		$this->post(
			array(
				'csp-report' => array(
					'document-uri'        => home_url( '/page/' ),
					'effective-directive' => 'script-src',
					'blocked-uri'         => 'https://evil.test/x.js',
				),
			)
		);
		$this->assertSame( 0, ACSP_DB::counts()['all'] );

		ACSP_Settings::update(
			array(
				'mode'         => 'report',
				'report_dest'  => 'external',
				'external_url' => 'https://collector.test/csp',
			)
		);
		$this->post(
			array(
				'csp-report' => array(
					'document-uri'        => home_url( '/page/' ),
					'effective-directive' => 'script-src',
					'blocked-uri'         => 'https://evil.test/x.js',
				),
			)
		);
		$this->assertSame( 0, ACSP_DB::counts()['all'] );
	}

	/**
	 * Regression: the rate limit is a fixed window.
	 *
	 * The counter used to live under one key whose expiry was pushed forward
	 * on every request, so a client reporting at least once a minute never got
	 * its allowance back and was limited permanently. The window number is now
	 * part of the key, so a new minute is a new allowance.
	 */
	public function test_rate_limit_is_a_fixed_window() {
		add_filter( 'acsp_rate_limit', static function () {
			return 5;
		} );

		$report = array(
			'csp-report' => array(
				'document-uri'        => home_url( '/page/' ),
				'effective-directive' => 'script-src',
				'blocked-uri'         => 'https://evil.test/x.js',
			),
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 204, $this->post( $report )->get_status(), 'Request ' . ( $i + 1 ) . ' is within the limit.' );
		}
		$this->assertSame( 429, $this->post( $report )->get_status(), 'The sixth is over it.' );

		// Simulate the next minute by removing this window's counter.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'acsp_rl_%'" );

		$this->assertSame( 204, $this->post( $report )->get_status(), 'A new window gives a fresh allowance.' );
	}

	/**
	 * The limit is counted per client, and the address itself is not stored.
	 */
	public function test_rate_limit_is_per_client_and_stores_no_address() {
		global $wpdb;

		add_filter( 'acsp_rate_limit', static function () {
			return 2;
		} );

		$report = array(
			'csp-report' => array(
				'document-uri'        => home_url( '/page/' ),
				'effective-directive' => 'script-src',
				'blocked-uri'         => 'https://evil.test/x.js',
			),
		);

		$this->post( $report );
		$this->post( $report );
		$this->assertSame( 429, $this->post( $report )->get_status() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( 204, $this->post( $report )->get_status(), 'A different client has its own allowance.' );

		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'acsp_rl_%'" );
		$this->assertNotEmpty( $names );
		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( '203.0.113.10', $name );
			$this->assertStringNotContainsString( '198.51.100.7', $name );
		}
	}

	/**
	 * Turning the limit off is supported, for sites behind one proxy address.
	 */
	public function test_rate_limit_can_be_disabled() {
		add_filter( 'acsp_rate_limit', static function () {
			return 0;
		} );

		$report = array(
			'csp-report' => array(
				'document-uri'        => home_url( '/page/' ),
				'effective-directive' => 'script-src',
				'blocked-uri'         => 'https://evil.test/x.js',
			),
		);

		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 204, $this->post( $report )->get_status() );
		}
	}
}
