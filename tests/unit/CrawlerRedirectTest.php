<?php
/**
 * The crawler fetches this site's own pages. These tests pin the one thing
 * that could turn that into a request somewhere else: a redirect.
 *
 * Note what is deliberately *not* blocked. A WordPress site legitimately lives
 * on 127.0.0.1, a .local name or a private address — Docker, staging, LAN and
 * intranet installs are normal — so a blanket private-IP deny-list would break
 * the plugin for a large number of real sites while adding nothing: the target
 * list is built from home_url() and get_permalink(), never from a request.
 * The control that matters is that a hop may not leave the site's own origin.
 *
 * @package AutoCSPManager
 */

use PHPUnit\Framework\TestCase;

/**
 * Redirect handling.
 */
class CrawlerRedirectTest extends TestCase {

	/**
	 * Scanner under test.
	 *
	 * @var ACSP_Scanner
	 */
	protected $scanner;

	/**
	 * resolve_hop(), which is internal.
	 *
	 * @var ReflectionMethod
	 */
	protected $resolve;

	/**
	 * Fresh scanner per test.
	 */
	protected function setUp(): void {
		ACSP_Test_State::reset( 'https://example.com' );
		$this->scanner = new ACSP_Scanner();
		$this->resolve = new ReflectionMethod( ACSP_Scanner::class, 'resolve_hop' );
		$this->resolve->setAccessible( true );
	}

	/**
	 * Resolve a Location header.
	 *
	 * @param string $from     Requested URL.
	 * @param string $location Location header.
	 * @return string
	 */
	protected function hop( $from, $location ) {
		return $this->resolve->invoke( $this->scanner, $from, $location );
	}

	/**
	 * Redirects that stay on the site are followed.
	 */
	public function test_same_origin_redirects_are_followed() {
		$this->assertSame( 'https://example.com/moved/', $this->hop( 'https://example.com/old/', 'https://example.com/moved/' ) );
		$this->assertSame( 'https://example.com/moved/', $this->hop( 'https://example.com/old/', '/moved/' ) );
		$this->assertSame( 'https://example.com/moved/', $this->hop( 'https://example.com/old/', '//example.com/moved/' ) );
		$this->assertSame( 'https://example.com/old/sibling', $this->hop( 'https://example.com/old/page', 'sibling' ) );
	}

	/**
	 * Anything that leaves the site is refused.
	 *
	 * @return array
	 */
	public function foreign_locations() {
		return array(
			'another site'       => array( 'https://attacker.test/collect' ),
			'cloud metadata'     => array( 'http://169.254.169.254/latest/meta-data/' ),
			'metadata by name'   => array( 'http://metadata.google.internal/computeMetadata/v1/' ),
			'loopback'           => array( 'http://127.0.0.1:6379/' ),
			'private address'    => array( 'http://10.0.0.5/admin' ),
			'ipv6 loopback'      => array( 'http://[::1]:9200/' ),
			'another port'       => array( 'https://example.com:8443/internal' ),
			'another scheme'     => array( 'http://example.com/downgraded' ),
			'subdomain'          => array( 'https://internal.example.com/' ),
			'suffix lookalike'   => array( 'https://example.com.attacker.test/' ),
			'protocol relative'  => array( '//attacker.test/collect' ),
			'not a url'          => array( 'mailto:someone@example.com' ),
		);
	}

	/**
	 * @dataProvider foreign_locations
	 *
	 * @param string $location Location header.
	 */
	public function test_foreign_redirects_are_refused( $location ) {
		$this->assertSame( '', $this->hop( 'https://example.com/page/', $location ), 'A redirect off the site must not be followed.' );
	}

	/**
	 * A site that really is on localhost can still be crawled: identity is
	 * decided by the site's own address, not by a block list.
	 */
	public function test_a_localhost_site_can_crawl_itself() {
		ACSP_Test_State::reset( 'http://localhost:8080' );
		$this->scanner = new ACSP_Scanner();

		$this->assertSame( 'http://localhost:8080/page/', $this->hop( 'http://localhost:8080/old/', '/page/' ) );
		$this->assertSame( '', $this->hop( 'http://localhost:8080/old/', 'http://localhost:9200/' ), 'Another port is another service.' );
	}

	/**
	 * A TLS failure has to be recognised, or the retry-and-warn path never
	 * runs and the scan just fails on a self-signed certificate.
	 */
	public function test_tls_errors_are_recognised() {
		$method = new ReflectionMethod( ACSP_Scanner::class, 'is_tls_error' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->scanner, new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: self signed certificate' ) ) );
		$this->assertTrue( $method->invoke( $this->scanner, new WP_Error( 'http_request_failed', 'error setting certificate verify locations: CAfile missing' ) ) );
		$this->assertFalse( $method->invoke( $this->scanner, new WP_Error( 'http_request_failed', 'Operation timed out after 10001 milliseconds' ) ) );
		$this->assertFalse( $method->invoke( $this->scanner, new WP_Error( 'http_request_failed', 'Could not resolve host' ) ) );
	}
}
