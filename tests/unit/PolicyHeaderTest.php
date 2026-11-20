<?php
/**
 * Header generation: directive output, modes and reporting endpoints.
 *
 * @package AutoCSPManager
 */

use PHPUnit\Framework\TestCase;

/**
 * build_value() / build_headers().
 */
class PolicyHeaderTest extends TestCase {

	/**
	 * Fresh option store per test.
	 */
	protected function setUp(): void {
		ACSP_Test_State::reset();
		ACSP_Policy::save( ACSP_Policy::baseline() );
	}

	/**
	 * Every managed directive is written out explicitly.
	 */
	public function test_all_directives_are_present() {
		$value = ACSP_Policy::build_value( true );

		foreach ( array_keys( ACSP_Policy::directive_definitions() ) as $directive ) {
			$this->assertMatchesRegularExpression( '/(^|; )' . preg_quote( $directive, '/' ) . ' /', $value, $directive . ' must be in the header.' );
		}
	}

	/**
	 * Report-only and enforced modes use the right header names.
	 */
	public function test_mode_decides_the_header_name() {
		ACSP_Settings::update( array( 'mode' => 'report' ) );
		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', ACSP_Policy::build_headers() );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', ACSP_Policy::build_headers() );

		ACSP_Settings::update( array( 'mode' => 'enforce' ) );
		$this->assertArrayHasKey( 'Content-Security-Policy', ACSP_Policy::build_headers() );
		$this->assertArrayNotHasKey( 'Content-Security-Policy-Report-Only', ACSP_Policy::build_headers() );

		ACSP_Settings::update( array( 'mode' => 'off' ) );
		$this->assertSame( array(), ACSP_Policy::build_headers(), 'Disabled means no header at all.' );
	}

	/**
	 * upgrade-insecure-requests is meaningless in report-only mode.
	 */
	public function test_upgrade_insecure_only_when_enforcing() {
		$this->assertStringContainsString( 'upgrade-insecure-requests', ACSP_Policy::build_value( true ) );
		$this->assertStringNotContainsString( 'upgrade-insecure-requests', ACSP_Policy::build_value( false ) );
	}

	/**
	 * The Reporting API needs a secure endpoint; report-uri covers the rest.
	 */
	public function test_reporting_endpoints_require_https() {
		ACSP_Settings::update(
			array(
				'mode'         => 'report',
				'report_dest'  => 'external',
				'external_url' => 'http://insecure.example.com/csp',
			)
		);
		$headers = ACSP_Policy::build_headers();
		$this->assertArrayNotHasKey( 'Reporting-Endpoints', $headers );
		$this->assertStringContainsString( 'report-uri http://insecure.example.com/csp', $headers['Content-Security-Policy-Report-Only'] );

		ACSP_Settings::update( array( 'external_url' => 'https://secure.example.com/csp' ) );
		$headers = ACSP_Policy::build_headers();
		$this->assertArrayHasKey( 'Reporting-Endpoints', $headers );
		$this->assertArrayHasKey( 'Report-To', $headers );
		$this->assertStringContainsString( 'report-to acsp-endpoint', $headers['Content-Security-Policy-Report-Only'] );
	}

	/**
	 * No header value may contain anything that ends a header or a directive
	 * where it should not — however the policy was filled in.
	 */
	public function test_header_values_cannot_be_broken_out_of() {
		ACSP_Policy::save(
			array(
				'directives' => array(
					'script-src' => "'self' https://evil.com;object-src\r\nX-Injected:1 https://ok.example.com",
					'img-src'    => array( "https://a.test\n", 'https://b.test,https://c.test' ),
				),
			)
		);
		ACSP_Settings::update( array( 'mode' => 'enforce' ) );

		foreach ( ACSP_Policy::build_headers() as $name => $value ) {
			$this->assertSame( $value, str_replace( array( "\r", "\n" ), '', $value ), $name . ' must not contain a line break.' );
			$this->assertStringNotContainsString( 'X-Injected', $value );
		}
		$this->assertStringNotContainsString( 'evil.com', ACSP_Policy::build_value( true ) );
	}

	/**
	 * An external report URL that could break the header is refused outright,
	 * and reporting falls back to the built-in endpoint rather than silently
	 * emitting a broken directive.
	 */
	public function test_report_url_sanitising() {
		$this->assertSame( '', ACSP_Settings::sanitize_report_url( 'https://evil.com/csp;script-src *' ) );
		$this->assertSame( '', ACSP_Settings::sanitize_report_url( "https://evil.com/csp\r\nX: 1" ) );
		$this->assertSame( '', ACSP_Settings::sanitize_report_url( 'javascript:alert(1)' ) );
		$this->assertSame( 'https://ok.example.com/csp', ACSP_Settings::sanitize_report_url( '  https://ok.example.com/csp  ' ) );

		$settings = ACSP_Settings::update(
			array(
				'report_dest'  => 'external',
				'external_url' => 'https://evil.com/csp;x',
			)
		);
		$this->assertSame( 'internal', $settings['report_dest'] );
	}

	/**
	 * Script reports are more useful with a code sample, but it must not be
	 * bolted onto a directive that blocks everything.
	 */
	public function test_report_sample_added_only_where_it_makes_sense() {
		ACSP_Settings::update( array( 'mode' => 'report', 'report_dest' => 'internal' ) );
		$this->assertStringContainsString( "'report-sample'", ACSP_Policy::build_value( false, 'https://example.com/report' ) );

		ACSP_Policy::save( array( 'directives' => array( 'script-src' => array( "'none'" ) ) ) );
		$value = ACSP_Policy::build_value( false, 'https://example.com/report' );
		$this->assertStringContainsString( "script-src 'none'", $value );
		$this->assertStringNotContainsString( "'none' 'report-sample'", $value );
	}

	/**
	 * Entries that are weaker than they look have to be flagged as risky, so
	 * the UI can say so. A stored nonce is the subtle one: fixed per policy,
	 * therefore reusable by anyone who reads the page.
	 */
	public function test_weak_entries_are_described_as_risky() {
		foreach ( array( "'unsafe-inline'", "'unsafe-eval'", '*', "'strict-dynamic'", "'nonce-abc123'", 'https:' ) as $source ) {
			$described = ACSP_Policy::describe_source( $source );
			$this->assertTrue( $described['risky'], $source . ' must be marked risky.' );
			$this->assertNotSame( '', $described['label'], $source . ' must be explained.' );
		}

		$this->assertFalse( ACSP_Policy::describe_source( 'https://cdn.example.com' )['risky'] );
		$this->assertFalse( ACSP_Policy::describe_source( 'data:' )['risky'] );
	}
}
