<?php
/**
 * Wildcard, scheme and port matching, and the block-coverage regression.
 *
 * @package AutoCSPManager
 */

use PHPUnit\Framework\TestCase;

/**
 * covers() / covering_sources() / replace_source().
 */
class PolicyCoversTest extends TestCase {

	/**
	 * Fresh option store per test.
	 */
	protected function setUp(): void {
		ACSP_Test_State::reset();
	}

	/**
	 * Matching cases, per CSP 3 source-expression semantics.
	 *
	 * @return array
	 */
	public function matching_cases() {
		return array(
			'exact'                        => array( array( 'https://a.example.com' ), 'https://a.example.com', true ),
			'wildcard matches subdomain'   => array( array( 'https://*.example.com' ), 'https://a.example.com', true ),
			'wildcard matches deeper'      => array( array( 'https://*.example.com' ), 'https://a.b.example.com', true ),
			'wildcard misses bare domain'  => array( array( 'https://*.example.com' ), 'https://example.com', false ),
			'wildcard misses other domain' => array( array( 'https://*.example.com' ), 'https://example.com.evil.net', false ),
			'host without scheme matches'  => array( array( 'a.example.com' ), 'https://a.example.com', true ),
			'scheme source matches'        => array( array( 'https:' ), 'https://anything.test', true ),
			'http scheme upgrades'         => array( array( 'http:' ), 'https://anything.test', true ),
			'https scheme does not downgrade' => array( array( 'https:' ), 'http://anything.test', false ),
			'star matches http'            => array( array( '*' ), 'https://anything.test', true ),
			'star does not match data'     => array( array( '*' ), 'data:', false ),
			'default port differs'         => array( array( 'https://example.com' ), 'https://example.com:8443', false ),
			'explicit port matches'        => array( array( 'https://example.com:8443' ), 'https://example.com:8443', true ),
			'wildcard port matches'        => array( array( 'https://example.com:*' ), 'https://example.com:8443', true ),
			'wrong scheme'                 => array( array( 'https://a.example.com' ), 'http://a.example.com', false ),
			'keyword covers nothing'       => array( array( "'self'" ), 'https://a.example.com', false ),
			'unsafe-inline covers nothing' => array( array( "'unsafe-inline'" ), 'https://a.example.com', false ),
			'entry with path not covering' => array( array( 'https://example.com/widgets/' ), 'https://example.com', false ),
			'empty list'                   => array( array(), 'https://a.example.com', false ),
		);
	}

	/**
	 * @dataProvider matching_cases
	 *
	 * @param array  $list     Policy entries.
	 * @param string $source   Source to test.
	 * @param bool   $expected Whether the list permits it.
	 */
	public function test_covers( $list, $source, $expected ) {
		$this->assertSame( $expected, ACSP_Policy::covers( $list, $source ) );
	}

	/**
	 * covering_sources() has to name the entries, not just say "yes".
	 */
	public function test_covering_sources_names_the_broader_entries() {
		$list = array( "'self'", 'https://*.example.com', 'https://a.example.com', 'https://other.test' );

		$this->assertSame(
			array( 'https://*.example.com' ),
			ACSP_Policy::covering_sources( $list, 'https://a.example.com', false ),
			'Only the wildcard is broader than the source.'
		);
		$this->assertSame(
			array( 'https://a.example.com', 'https://*.example.com' ),
			ACSP_Policy::covering_sources( $list, 'https://a.example.com' ),
			'With the exact match included, both are returned.'
		);
		$this->assertSame(
			array(),
			ACSP_Policy::covering_sources( $list, 'https://nothing.test', false )
		);
	}

	/**
	 * Regression for the block that only looked like a block.
	 *
	 * Blocking a host that a wildcard permits used to remove nothing from the
	 * policy and then mark the report "blocked" anyway, so the dashboard
	 * claimed a protection the browser was not applying. The policy layer now
	 * reports the conflict, which is what lets the caller refuse.
	 */
	public function test_wildcard_still_permits_a_removed_host() {
		ACSP_Policy::save(
			array(
				'directives' => array(
					'script-src' => array( "'self'", 'https://*.example.com' ),
				),
			)
		);

		ACSP_Policy::remove_sources( array( 'script-src' => array( 'https://malicious.example.com' ) ) );

		$policy = ACSP_Policy::get();
		$this->assertTrue(
			ACSP_Policy::covers( $policy['directives']['script-src'], 'https://malicious.example.com' ),
			'Removing an exact string cannot defeat a wildcard — the conflict must be detected instead.'
		);
		$this->assertSame(
			array( 'https://*.example.com' ),
			ACSP_Policy::covering_sources( $policy['directives']['script-src'], 'https://malicious.example.com', false )
		);
	}

	/**
	 * Narrowing is what actually makes such a block real.
	 */
	public function test_replace_source_narrows_a_wildcard() {
		ACSP_Policy::save(
			array(
				'directives' => array(
					'script-src' => array( "'self'", 'https://*.example.com' ),
				),
			)
		);

		$changed = ACSP_Policy::replace_source(
			'script-src',
			'https://*.example.com',
			array( 'https://good.example.com', 'https://also-good.example.com' )
		);

		$this->assertTrue( $changed );

		$list = ACSP_Policy::get()['directives']['script-src'];
		$this->assertNotContains( 'https://*.example.com', $list );
		$this->assertContains( 'https://good.example.com', $list );
		$this->assertContains( "'self'", $list, 'Narrowing one entry must not disturb the others.' );
		$this->assertFalse(
			ACSP_Policy::covers( $list, 'https://malicious.example.com' ),
			'After narrowing, the blocked host is genuinely not permitted.'
		);
	}

	/**
	 * Replacing with nothing is allowed, and reported honestly.
	 */
	public function test_replace_source_with_nothing_removes_the_entry() {
		ACSP_Policy::save( array( 'directives' => array( 'script-src' => array( 'https://*.example.com' ) ) ) );

		$this->assertTrue( ACSP_Policy::replace_source( 'script-src', 'https://*.example.com', array() ) );
		$this->assertSame( array(), ACSP_Policy::get()['directives']['script-src'] );
		$this->assertFalse( ACSP_Policy::replace_source( 'script-src', 'https://*.example.com', array() ), 'A no-op reports no change.' );
		$this->assertFalse( ACSP_Policy::replace_source( 'not-a-directive', 'x', array() ) );
	}

	/**
	 * An empty directive must be written as 'none', never left to fall back.
	 */
	public function test_empty_directive_builds_as_none() {
		ACSP_Policy::save( array( 'directives' => array( 'script-src' => array() ) ) );
		$this->assertStringContainsString( "script-src 'none'", ACSP_Policy::build_value( true ) );
	}
}
