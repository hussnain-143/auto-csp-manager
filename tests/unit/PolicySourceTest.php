<?php
/**
 * ACSP_Policy::sanitize_source() — the boundary everything in the header passes.
 *
 * @package AutoCSPManager
 */

use PHPUnit\Framework\TestCase;

/**
 * Source validation.
 */
class PolicySourceTest extends TestCase {

	/**
	 * Fresh option store per test.
	 */
	protected function setUp(): void {
		ACSP_Test_State::reset();
	}

	/**
	 * Sources that must be accepted, and the form they normalise to.
	 *
	 * @return array
	 */
	public function valid_sources() {
		return array(
			'keyword without quotes'   => array( 'self', "'self'" ),
			'keyword with quotes'      => array( "'self'", "'self'" ),
			'keyword uppercase'        => array( "'SELF'", "'self'" ),
			'none'                     => array( "'none'", "'none'" ),
			'unsafe-inline'            => array( "'unsafe-inline'", "'unsafe-inline'" ),
			'host'                     => array( 'https://cdn.example.com', 'https://cdn.example.com' ),
			'host uppercase'           => array( 'HTTPS://CDN.EXAMPLE.COM', 'https://cdn.example.com' ),
			'wildcard subdomain'       => array( 'https://*.example.com', 'https://*.example.com' ),
			'bare host'                => array( 'example.com', 'example.com' ),
			'host with port'           => array( 'https://example.com:8443', 'https://example.com:8443' ),
			'host with wildcard port'  => array( 'https://example.com:*', 'https://example.com:*' ),
			'host with path'           => array( 'https://example.com/widgets/', 'https://example.com/widgets/' ),
			'scheme source'            => array( 'data:', 'data:' ),
			'scheme shorthand'         => array( 'data', 'data:' ),
			'https shorthand'          => array( 'https', 'https:' ),
			'star'                     => array( '*', '*' ),
			'nonce'                    => array( "'nonce-AbC123+/='", "'nonce-AbC123+/='" ),
			'nonce without quotes'     => array( 'nonce-AbC123', "'nonce-AbC123'" ),
			'sha256 hash'              => array( "'sha256-AbC123+/='", "'sha256-AbC123+/='" ),
			'sha512 hash'              => array( "'sha512-AbC123+/='", "'sha512-AbC123+/='" ),
			'localhost'                => array( 'http://localhost:8080', 'http://localhost:8080' ),
		);
	}

	/**
	 * @dataProvider valid_sources
	 *
	 * @param string $input    Raw token.
	 * @param string $expected Normalised source.
	 */
	public function test_valid_sources_are_accepted( $input, $expected ) {
		$this->assertSame( $expected, ACSP_Policy::sanitize_source( $input ) );
	}

	/**
	 * Tokens that must never reach a header.
	 *
	 * Every one of these is a header- or directive-injection attempt: a
	 * semicolon ends a directive, a comma ends a policy, CR/LF ends the
	 * header, and a space introduces a second source the owner never typed.
	 *
	 * @return array
	 */
	public function invalid_sources() {
		return array(
			'directive injection'      => array( "https://evil.com;script-src" ),
			'directive injection space'=> array( 'https://evil.com; script-src *' ),
			'policy injection'         => array( 'https://evil.com,default-src *' ),
			'header injection crlf'    => array( "https://evil.com\r\nX-Injected: 1" ),
			'header injection lf'      => array( "https://evil.com\nX-Injected: 1" ),
			'null byte'                => array( "https://evil.com\0" ),
			'tab'                      => array( "https://evil.com\tfoo" ),
			'double quote'             => array( 'https://evil.com"' ),
			'angle brackets'           => array( 'https://evil.com/<script>' ),
			'backslash'                => array( 'https://evil.com\\foo' ),
			'two sources'              => array( 'https://a.com https://b.com' ),
			'empty'                    => array( '' ),
			'whitespace only'          => array( "   \t " ),
			'not a source'             => array( '<?php echo 1; ?>' ),
			'php fragment'             => array( 'https://<?php echo $host; ?>/x.js' ),
			'too long'                 => array( 'https://' . str_repeat( 'a', 500 ) . '.com' ),
		);
	}

	/**
	 * @dataProvider invalid_sources
	 *
	 * @param string $input Raw token.
	 */
	public function test_invalid_sources_are_rejected( $input ) {
		$this->assertSame( '', ACSP_Policy::sanitize_source( $input ), 'This token must not become a source.' );
	}

	/**
	 * A rejected token is reported back, not silently dropped.
	 */
	public function test_rejected_tokens_are_reported() {
		$rejected = array();
		$clean    = ACSP_Policy::sanitize_sources( "'self' https://good.example.com https://evil.com;x", $rejected );

		$this->assertSame( array( "'self'", 'https://good.example.com' ), $clean );
		$this->assertSame( array( 'https://evil.com;x' ), $rejected );
	}

	/**
	 * 'none' only means anything on its own.
	 */
	public function test_none_is_dropped_when_combined() {
		$this->assertSame( array( "'self'" ), ACSP_Policy::sanitize_sources( "'none' 'self'" ) );
		$this->assertSame( array( "'none'" ), ACSP_Policy::sanitize_sources( "'none'" ) );
	}

	/**
	 * Duplicates collapse, whatever case they were typed in.
	 */
	public function test_duplicates_collapse() {
		$this->assertSame( array( 'https://cdn.example.com' ), ACSP_Policy::sanitize_sources( 'https://cdn.example.com HTTPS://CDN.EXAMPLE.COM' ) );
	}

	/**
	 * A directive cannot be widened by saving a malformed policy.
	 */
	public function test_save_strips_invalid_sources() {
		$rejected = array();
		$saved    = ACSP_Policy::save(
			array(
				'directives' => array(
					'script-src' => "'self' https://evil.com;object-src *",
				),
			),
			$rejected
		);

		$this->assertSame( array( "'self'" ), $saved['directives']['script-src'] );
		$this->assertNotEmpty( $rejected );
	}

	/**
	 * add_source() goes through the same validation as the editor.
	 */
	public function test_add_source_validates() {
		ACSP_Policy::save( ACSP_Policy::baseline() );

		$this->assertFalse( ACSP_Policy::add_source( 'script-src', "https://evil.com;base-uri" ) );
		$this->assertTrue( ACSP_Policy::add_source( 'script-src', 'https://good.example.com' ) );
		$this->assertFalse( ACSP_Policy::add_source( 'not-a-directive', 'https://good.example.com' ) );

		$policy = ACSP_Policy::get();
		$this->assertContains( 'https://good.example.com', $policy['directives']['script-src'] );
	}
}
