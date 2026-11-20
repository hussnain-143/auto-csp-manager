<?php
/**
 * Violation reports are attacker-controlled input. These tests pin down what
 * the endpoint accepts, what it rewrites, and what it throws away.
 *
 * @package AutoCSPManager
 */

use PHPUnit\Framework\TestCase;

/**
 * ACSP_Reporter normalisation.
 */
class ReporterNormaliseTest extends TestCase {

	/**
	 * Fresh option store per test.
	 */
	protected function setUp(): void {
		ACSP_Test_State::reset( 'https://example.com' );
	}

	/**
	 * A report-uri payload for a blocked script.
	 *
	 * @param array $overrides Fields to change.
	 * @return array
	 */
	protected function csp_report( array $overrides = array() ) {
		return array(
			'csp-report' => array_merge(
				array(
					'document-uri'        => 'https://example.com/page/',
					'effective-directive' => 'script-src-elem',
					'blocked-uri'         => 'https://evil.test/x.js',
					'source-file'         => 'https://example.com/page/',
					'line-number'         => 12,
					'script-sample'       => '',
					'disposition'         => 'report',
				),
				$overrides
			),
		);
	}

	/**
	 * The happy path, including directive folding.
	 */
	public function test_valid_report_is_normalised() {
		$reports = ACSP_Reporter::extract_reports( $this->csp_report() );
		$this->assertCount( 1, $reports );

		$row = ACSP_Reporter::normalise( $reports[0], 'TestAgent/1.0' );

		$this->assertSame( 'script-src', $row['directive'], 'script-src-elem folds onto the managed directive.' );
		$this->assertSame( 'script-src-elem', $row['effective_directive'] );
		$this->assertSame( 'https://evil.test', $row['source'] );
		$this->assertSame( 12, $row['line_number'] );
		$this->assertSame( 'report', $row['disposition'] );
		$this->assertSame( 'TestAgent/1.0', $row['user_agent'] );
	}

	/**
	 * The Reporting API batch format must give the same result.
	 */
	public function test_reporting_api_format() {
		$reports = ACSP_Reporter::extract_reports(
			array(
				array(
					'type'       => 'csp-violation',
					'user_agent' => 'Batch/1.0',
					'body'       => array(
						'documentURL'        => 'https://example.com/page/',
						'effectiveDirective' => 'style-src-elem',
						'blockedURL'         => 'https://fonts.test/x.css',
						'disposition'        => 'enforce',
					),
				),
				array(
					'type' => 'deprecation',
					'body' => array( 'id' => 'something-else' ),
				),
			)
		);

		$this->assertCount( 1, $reports, 'Only CSP violations are taken from a mixed batch.' );

		$row = ACSP_Reporter::normalise( $reports[0] );
		$this->assertSame( 'style-src', $row['directive'] );
		$this->assertSame( 'https://fonts.test', $row['source'] );
		$this->assertSame( 'enforce', $row['disposition'] );
		$this->assertSame( 'Batch/1.0', $row['user_agent'] );
	}

	/**
	 * A report about somebody else's page is not evidence about this site.
	 * Without this check anyone could fill the log with whatever they liked
	 * and make it look like it came from here.
	 */
	public function test_report_about_another_site_is_dropped() {
		$reports = ACSP_Reporter::extract_reports( $this->csp_report( array( 'document-uri' => 'https://attacker.test/page/' ) ) );
		$this->assertNull( ACSP_Reporter::normalise( $reports[0] ) );
	}

	/**
	 * Reports the plugin cannot act on are dropped rather than stored.
	 *
	 * @return array
	 */
	public function unusable_reports() {
		return array(
			'no document'        => array( array( 'document-uri' => '' ) ),
			'unmanaged directive'=> array( array( 'effective-directive' => 'trusted-types' ) ),
			'empty directive'    => array( array( 'effective-directive' => '' ) ),
			'extension noise'    => array( array( 'blocked-uri' => 'chrome-extension://abcdef/inject.js' ) ),
			'extension source'   => array( array( 'source-file' => 'moz-extension://abcdef/inject.js' ) ),
			'unusable blocked'   => array( array( 'blocked-uri' => 'about' ) ),
		);
	}

	/**
	 * @dataProvider unusable_reports
	 *
	 * @param array $overrides Fields to change.
	 */
	public function test_unusable_reports_are_dropped( $overrides ) {
		$reports = ACSP_Reporter::extract_reports( $this->csp_report( $overrides ) );
		$this->assertNull( ACSP_Reporter::normalise( $reports[0] ) );
	}

	/**
	 * Noise filtering can be turned off without the rest of the checks going.
	 */
	public function test_noise_can_be_kept() {
		$reports = ACSP_Reporter::extract_reports( $this->csp_report( array( 'blocked-uri' => 'chrome-extension://abcdef/inject.js' ) ) );
		$row     = ACSP_Reporter::normalise( $reports[0], '', false );
		$this->assertSame( 'chrome-extension:', $row['source'] );
	}

	/**
	 * blocked-uri to source expression.
	 *
	 * @return array
	 */
	public function blocked_uris() {
		return array(
			'inline'              => array( 'inline', 'script-src', "'unsafe-inline'" ),
			'eval'                => array( 'eval', 'script-src', "'unsafe-eval'" ),
			'wasm'                => array( 'wasm-eval', 'script-src', "'wasm-unsafe-eval'" ),
			'empty means inline'  => array( '', 'style-src', "'unsafe-inline'" ),
			'self means inline'   => array( 'self', 'script-src', "'unsafe-inline'" ),
			'empty for images'    => array( '', 'img-src', '' ),
			'data'                => array( 'data', 'img-src', 'data:' ),
			'third party host'    => array( 'https://cdn.test/x.js', 'script-src', 'https://cdn.test' ),
			'port is kept'        => array( 'https://cdn.test:8443/x.js', 'script-src', 'https://cdn.test:8443' ),
			'own site is self'    => array( 'https://example.com/x.js', 'script-src', "'self'" ),
			'websocket'           => array( 'wss://live.test/socket', 'connect-src', 'wss://live.test' ),
			'odd scheme'          => array( 'tel:+123456', 'default-src', 'tel:' ),
			'no host'             => array( 'https:///x.js', 'script-src', '' ),
		);
	}

	/**
	 * @dataProvider blocked_uris
	 *
	 * @param string $blocked   Blocked URI.
	 * @param string $directive Managed directive.
	 * @param string $expected  Source expression.
	 */
	public function test_blocked_to_source( $blocked, $directive, $expected ) {
		$this->assertSame( $expected, ACSP_Reporter::blocked_to_source( $blocked, $directive ) );
	}

	/**
	 * Regression: 'self' matches scheme, host AND port, so a resource on
	 * another port of the same hostname is not this origin and must keep its
	 * own entry.
	 */
	public function test_other_port_on_same_host_is_not_self() {
		$this->assertSame(
			'https://example.com:8443',
			ACSP_Reporter::blocked_to_source( 'https://example.com:8443/app.js', 'script-src' ),
			"A different port is a different origin; calling it 'self' would leave it out of the policy."
		);
		$this->assertSame( "'self'", ACSP_Reporter::blocked_to_source( 'https://example.com:443/app.js', 'script-src' ), 'The default port is the same origin.' );
	}

	/**
	 * Origin comparison, including default-port folding.
	 */
	public function test_normalise_origin() {
		$this->assertSame( 'https://example.com', ACSP_Reporter::normalise_origin( 'https://example.com:443/some/path?q=1' ) );
		$this->assertSame( 'http://example.com', ACSP_Reporter::normalise_origin( 'http://example.com:80/' ) );
		$this->assertSame( 'https://example.com:8443', ACSP_Reporter::normalise_origin( 'https://EXAMPLE.com:8443' ) );
		$this->assertSame( '', ACSP_Reporter::normalise_origin( 'not a url' ) );
		$this->assertSame( array( 'https://example.com' ), ACSP_Reporter::site_origins() );
	}

	/**
	 * Free text from a report is stored, so it must be stripped of anything
	 * that could be dangerous downstream.
	 */
	public function test_sample_and_uris_are_sanitised() {
		$reports = ACSP_Reporter::extract_reports(
			$this->csp_report(
				array(
					'script-sample' => "<script>alert(1)</script>\r\n second line",
					'line-number'   => '-99',
				)
			)
		);
		$row = ACSP_Reporter::normalise( $reports[0] );

		$this->assertStringNotContainsString( '<', $row['script_sample'] );
		$this->assertStringNotContainsString( "\n", $row['script_sample'] );
		$this->assertSame( 99, $row['line_number'] );
	}

	/**
	 * Malformed payloads produce no reports instead of warnings or rows.
	 */
	public function test_malformed_payloads_yield_nothing() {
		$this->assertSame( array(), ACSP_Reporter::extract_reports( array() ) );
		$this->assertSame( array(), ACSP_Reporter::extract_reports( array( 'csp-report' => 'not-an-array' ) ) );
		$this->assertSame( array(), ACSP_Reporter::extract_reports( array( 'nonsense' => true ) ) );
		$this->assertSame( array(), ACSP_Reporter::extract_reports( array( array( 'body' => 'not-an-array' ) ) ) );
	}

	/**
	 * Directive folding, including the ones with no managed equivalent.
	 */
	public function test_directive_mapping() {
		$this->assertSame( 'script-src', ACSP_Policy::map_directive( 'script-src-attr' ) );
		$this->assertSame( 'frame-src', ACSP_Policy::map_directive( 'child-src' ) );
		$this->assertSame( 'frame-src', ACSP_Policy::map_directive( 'fenced-frame-src' ) );
		$this->assertSame( 'default-src', ACSP_Policy::map_directive( 'prefetch-src' ) );
		$this->assertSame( 'img-src', ACSP_Policy::map_directive( "img-src 'self' https://x.test" ) );
		$this->assertSame( '', ACSP_Policy::map_directive( 'require-trusted-types-for' ) );
		$this->assertSame( '', ACSP_Policy::map_directive( '' ) );
	}
}
