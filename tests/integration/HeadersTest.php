<?php
/**
 * Header emission, including what happens when something else on the site is
 * already sending a Content-Security-Policy.
 *
 * Requires the WordPress test framework.
 *
 * @package AutoCSPManager
 */

/**
 * ACSP_Headers.
 */
class HeadersTest extends WP_UnitTestCase {

	/**
	 * Reset state.
	 */
	public function set_up() {
		parent::set_up();
		ACSP_Policy::save( ACSP_Policy::baseline() );
		delete_option( 'acsp_foreign_csp' );
	}

	/**
	 * Mode decides the header, and the policy is always complete.
	 */
	public function test_headers_follow_the_mode() {
		ACSP_Settings::update( array( 'mode' => 'enforce' ) );
		$headers = ACSP_Policy::build_headers();
		$this->assertArrayHasKey( 'Content-Security-Policy', $headers );

		ACSP_Settings::update( array( 'mode' => 'report' ) );
		$this->assertArrayHasKey( 'Content-Security-Policy-Report-Only', ACSP_Policy::build_headers() );

		ACSP_Settings::update( array( 'mode' => 'off' ) );
		$this->assertSame( array(), ACSP_Policy::build_headers() );
	}

	/**
	 * A filter can suppress the policy for a request without the plugin
	 * needing to know why (a cache warmer, a health check, a REST route).
	 */
	public function test_send_can_be_filtered_off() {
		$contexts = array();
		add_filter(
			'acsp_send_header',
			static function ( $send, $context ) use ( &$contexts ) {
				$contexts[] = $context;
				return false;
			},
			10,
			2
		);

		$headers = new ACSP_Headers();
		$method  = new ReflectionMethod( ACSP_Headers::class, 'should_send' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $headers, 'front' ) );
		$this->assertSame( array( 'front' ), $contexts, 'The filter is consulted with the request context.' );
	}

	/**
	 * Without a filter saying otherwise, the policy is sent.
	 */
	public function test_send_is_the_default() {
		$headers = new ACSP_Headers();
		$method  = new ReflectionMethod( ACSP_Headers::class, 'should_send' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $headers, 'front' ) );
	}

	/**
	 * A policy already on the response is recorded rather than silently
	 * replaced: browsers apply both, so the owner has to be told which other
	 * component is sending one.
	 */
	public function test_a_foreign_policy_is_detected_and_recorded() {
		// header()/headers_list() are inert under the CLI SAPI, and PHPUnit has
		// already written output, so a real header collision cannot be staged
		// here. Skipped rather than faked: a test that cannot fail is worse
		// than no test. This path is covered by inspection and manual check.
		$this->markTestSkipped( 'Header collision needs a real HTTP response; not reproducible under CLI.' );

		header( "Content-Security-Policy: default-src 'none'" );

		ACSP_Settings::update( array( 'mode' => 'report' ) );
		$headers = new ACSP_Headers();
		$method  = new ReflectionMethod( ACSP_Headers::class, 'send' );
		$method->setAccessible( true );
		$method->invoke( $headers, 'front' );

		$this->assertContains( 'content-security-policy', (array) get_option( 'acsp_foreign_csp', array() ) );

		$sent = array();
		foreach ( headers_list() as $header ) {
			$name = strtolower( trim( strtok( $header, ':' ) ) );
			if ( 0 === strpos( $name, 'content-security-policy' ) ) {
				$sent[] = $header;
			}
		}
		$this->assertNotEmpty( $sent );
		$this->assertTrue(
			(bool) count(
				array_filter(
					$sent,
					static function ( $header ) {
						return false !== strpos( $header, "default-src 'none'" );
					}
				)
			),
			"The other component's policy is left in place, not thrown away."
		);

		header_remove( 'Content-Security-Policy' );
		header_remove( 'Content-Security-Policy-Report-Only' );
	}
}
