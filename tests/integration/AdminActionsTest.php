<?php
/**
 * Authorisation on the privileged paths, and the review-first default.
 *
 * Requires the WordPress test framework.
 *
 * @package AutoCSPManager
 */

/**
 * ACSP_Admin and activation behaviour.
 */
class AdminActionsTest extends WP_UnitTestCase {

	/**
	 * Admin instance.
	 *
	 * @var ACSP_Admin
	 */
	protected $admin;

	/**
	 * Set up a policy, a table and an admin user.
	 */
	public function set_up() {
		parent::set_up();

		require_once ACSP_DIR . 'includes/class-acsp-admin.php';

		ACSP_DB::install();
		ACSP_Policy::save( ACSP_Policy::baseline() );
		$this->admin = new ACSP_Admin();

		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * Clean up superglobals.
	 */
	public function tear_down() {
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Put a nonce where WordPress looks for it.
	 *
	 * check_admin_referer() reads $_REQUEST, so setting only $_POST leaves the
	 * handler seeing no nonce at all - which looks like a passing test for the
	 * wrong reason.
	 *
	 * @param string $action Nonce action.
	 */
	protected function set_nonce( $action ) {
		$nonce                = wp_create_nonce( $action );
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	/**
	 * Run something that ends in a redirect, and return where it redirected to.
	 *
	 * The handlers finish with wp_safe_redirect() followed by exit. A bare exit
	 * would end the PHPUnit process itself, taking the rest of the suite with
	 * it, so the redirect is interrupted at the filter that runs just before.
	 *
	 * @param callable $run Handler invocation.
	 * @return string Redirect target.
	 */
	protected function catch_redirect( callable $run ) {
		$location = '';
		$filter   = static function ( $target ) {
			throw new ACSP_Test_Redirected( (string) $target );
		};

		add_filter( 'wp_redirect', $filter );
		try {
			$run();
			$this->fail( 'Expected the handler to redirect.' );
		} catch ( ACSP_Test_Redirected $e ) {
			$location = $e->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $filter );
		}

		return $location;
	}

	/**
	 * Every form handler must refuse a request without a valid nonce, and
	 * refuse a user without the capability — whichever comes first.
	 *
	 * @return array
	 */
	public function privileged_handlers() {
		return array(
			array( 'save_settings', 'acsp_save_settings' ),
			array( 'save_policy', 'acsp_save_policy' ),
			array( 'apply_scan', 'acsp_apply_scan' ),
			array( 'rebuild_policy', 'acsp_rebuild_policy' ),
			array( 'narrow_block', 'acsp_narrow_block' ),
			array( 'block_advisory', 'acsp_block_advisory' ),
		);
	}

	/**
	 * @dataProvider privileged_handlers
	 *
	 * @param string $method Handler method.
	 * @param string $action Nonce action.
	 */
	public function test_handler_requires_capability( $method, $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->set_nonce( $action );

		$this->expectException( WPDieException::class );
		$this->admin->$method();
	}

	/**
	 * @dataProvider privileged_handlers
	 *
	 * @param string $method Handler method.
	 * @param string $action Nonce action.
	 */
	public function test_handler_requires_a_nonce( $method, $action ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['_wpnonce']    = 'not-a-valid-nonce';
		$_REQUEST['_wpnonce'] = 'not-a-valid-nonce';

		$this->expectException( WPDieException::class );
		$this->admin->$method();
	}


	/**
	 * An administrator cannot apply a source the scan never found, even by
	 * posting it directly: the form is checked against the stored result.
	 */
	public function test_apply_scan_only_accepts_what_the_scan_offered() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option(
			ACSP_Scanner::LAST_OPTION,
			array(
				'time'     => time(),
				'findings' => array( 'script-src' => array( 'https://offered.test' => array( 'Page /' ) ) ),
				'stats'    => array(),
			),
			false
		);

		$this->set_nonce( 'acsp_apply_scan' );
		$_POST['add'] = array(
			'script-src' => array( 'https://offered.test', 'https://never-found.test' ),
		);

		$location = $this->catch_redirect(
			function () {
				$this->admin->apply_scan();
			}
		);
		$this->assertStringContainsString( 'page=acsp-scanner', $location );

		$list = ACSP_Policy::get()['directives']['script-src'];
		$this->assertContains( 'https://offered.test', $list );
		$this->assertNotContains( 'https://never-found.test', $list, 'A forged form must not inject a source.' );
	}

	/**
	 * Activation must not trust what it finds.
	 *
	 * The plugin is often installed *because* a site is misbehaving, and a
	 * scan cannot tell a CDN from an attacker's host. Activation therefore
	 * saves the baseline and leaves the findings for review.
	 */
	public function test_activation_does_not_auto_allow_discovered_sources() {
		delete_option( ACSP_Policy::OPTION );
		delete_option( ACSP_Scanner::LAST_OPTION );

		// A post that loads a third-party script, as a compromised site would.
		// The content is written directly to the row: wp_insert_post() runs it
		// through KSES, which strips <script> for a user without
		// unfiltered_html and would leave the test with nothing to find.
		global $wpdb;
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => '<script src="https://attacker.test/payload.js"></script>' ),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		ACSP_Plugin::activate( false );

		$list = ACSP_Policy::get()['directives']['script-src'];
		$this->assertNotContains( 'https://attacker.test', $list, 'Activation must not write a discovered origin into the policy.' );
		$this->assertSame( ACSP_Policy::baseline()['directives']['script-src'], $list );

		$last = get_option( ACSP_Scanner::LAST_OPTION );
		$this->assertArrayHasKey( 'https://attacker.test', $last['findings']['script-src'], 'It is recorded for review.' );
		$this->assertSame( 'report', ACSP_Settings::get( 'mode' ), 'And nothing is enforced yet.' );
	}

	/**
	 * Settings are clamped, whatever is posted.
	 */
	public function test_settings_are_sanitised() {
		$settings = ACSP_Settings::update(
			array(
				'mode'           => 'something-else',
				'report_dest'    => 'nonsense',
				'retention_days' => 99999,
				'max_rows'       => 1,
			)
		);

		$this->assertContains( $settings['mode'], array( 'off', 'report', 'enforce' ) );
		$this->assertContains( $settings['report_dest'], array( 'internal', 'external', 'none' ) );
		$this->assertSame( 365, $settings['retention_days'] );
		$this->assertSame( 100, $settings['max_rows'] );
	}

	/**
	 * Narrowing from the conflict panel produces a policy that really blocks.
	 */
	public function test_narrow_block_makes_the_block_real() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ACSP_Policy::save(
			array( 'directives' => array( 'script-src' => array( "'self'", 'https://*.example.test' ) ) )
		);
		update_option(
			ACSP_Scanner::LAST_OPTION,
			array(
				'time'     => time(),
				'findings' => array(
					'script-src' => array(
						'https://good.example.test'      => array( 'Page /' ),
						'https://malicious.example.test' => array( 'Page /' ),
					),
				),
				'stats'    => array(),
			),
			false
		);

		ACSP_DB::record(
			array(
				'directive'           => 'script-src',
				'effective_directive' => 'script-src',
				'source'              => 'https://malicious.example.test',
				'blocked_uri'         => 'https://malicious.example.test/x.js',
				'document_uri'        => home_url( '/' ),
				'source_file'         => home_url( '/' ),
				'line_number'         => 0,
				'script_sample'       => '',
				'disposition'         => 'report',
				'user_agent'          => 'Test/1.0',
			)
		);
		$id = (int) ACSP_DB::query( array() )['items'][0]->id;

		$this->set_nonce( 'acsp_narrow_block' );
		$_POST['report'] = $id;

		$location = $this->catch_redirect(
			function () {
				$this->admin->narrow_block();
			}
		);
		$this->assertStringContainsString( 'acsp_msg=narrowed', $location );

		$list = ACSP_Policy::get()['directives']['script-src'];
		$this->assertNotContains( 'https://*.example.test', $list, 'The wildcard is gone.' );
		$this->assertContains( 'https://good.example.test', $list, 'The origin in use is kept.' );
		$this->assertFalse( ACSP_Policy::covers( $list, 'https://malicious.example.test' ), 'The blocked origin is genuinely blocked.' );
		$this->assertSame( 'blocked', ACSP_DB::get( $id )->status );
		$this->assertSame( array(), ACSP_DB::advisory_for( 'script-src', 'https://malicious.example.test' ) );
	}
}

/**
 * Raised in place of a redirect, so a handler can be tested without the exit
 * that follows it ending the test process.
 */
class ACSP_Test_Redirected extends Exception {}

/**
 * The scan step runs over admin-ajax, which ends the request by writing JSON
 * and dying. WP_Ajax_UnitTestCase is the harness built for that.
 */
class AdminAjaxTest extends WP_Ajax_UnitTestCase {

	/**
	 * Load the admin class.
	 */
	public function set_up() {
		parent::set_up();
		require_once ACSP_DIR . 'includes/class-acsp-admin.php';
		ACSP_DB::install();
		ACSP_Policy::save( ACSP_Policy::baseline() );
	}

	/**
	 * A valid nonce is not enough: the capability is checked as well.
	 */
	public function test_scan_step_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST['nonce']    = wp_create_nonce( 'acsp_scan' );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$_POST['step']     = 'start';

		try {
			( new ACSP_Admin() )->ajax_scan_step();
			$this->fail( 'Expected the request to be refused.' );
		} catch ( WPAjaxDieContinueException $e ) {
			$response = json_decode( $this->_last_response, true );
			$this->assertFalse( $response['success'] );
		}
	}

	/**
	 * And a valid capability is not enough without the nonce.
	 */
	public function test_scan_step_requires_a_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['nonce']    = 'not-a-valid-nonce';
		$_REQUEST['nonce'] = 'not-a-valid-nonce';
		$_POST['step']     = 'start';

		$this->expectException( WPAjaxDieStopException::class );
		( new ACSP_Admin() )->ajax_scan_step();
	}

	/**
	 * An administrator can drive a scan step, and gets the next step back.
	 */
	public function test_scan_step_runs_for_an_administrator() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['nonce']    = wp_create_nonce( 'acsp_scan' );
		$_REQUEST['nonce'] = $_POST['nonce'];
		$_POST['step']     = 'start';

		try {
			( new ACSP_Admin() )->ajax_scan_step();
			$this->fail( 'Expected the response to end the request.' );
		} catch ( WPAjaxDieContinueException $e ) {
			$response = json_decode( $this->_last_response, true );
			$this->assertTrue( $response['success'] );
			$this->assertSame( 'theme', $response['data']['step'] );
		}
	}
}
