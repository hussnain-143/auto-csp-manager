<?php
/**
 * Sends the policy headers.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Header output for the front end and, optionally, wp-admin and the login page.
 */
class ACSP_Headers {

	/**
	 * Hook up.
	 */
	public function init() {
		add_action( 'send_headers', array( $this, 'front' ) );
		add_action( 'admin_init', array( $this, 'admin' ) );
		add_action( 'login_init', array( $this, 'login' ) );
	}

	/**
	 * Front-end requests.
	 */
	public function front() {
		$this->send( 'front' );
	}

	/**
	 * Dashboard requests, when enabled.
	 */
	public function admin() {
		if ( ! ACSP_Settings::get( 'apply_admin' ) || wp_doing_ajax() ) {
			return;
		}
		// Never apply the policy to this plugin's own screens, so a policy that
		// is too strict can always be corrected.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 === strpos( $page, 'acsp' ) ) {
			return;
		}
		$this->send( 'admin' );
	}

	/**
	 * Login page requests, when enabled.
	 */
	public function login() {
		if ( ACSP_Settings::get( 'apply_login' ) ) {
			$this->send( 'login' );
		}
	}

	/**
	 * Whether a policy belongs on this request.
	 *
	 * Kept apart from send() so the decision can be examined on its own: the
	 * emission itself depends on a real HTTP response, which makes it awkward
	 * to exercise, and the decision is the part a site can change.
	 *
	 * @param string $context front | admin | login.
	 * @return bool
	 */
	protected function should_send( $context ) {
		if ( is_customize_preview() ) {
			return false;
		}

		/**
		 * Filter whether the policy is sent for this request.
		 *
		 * @param bool   $send    Whether to send.
		 * @param string $context front | admin | login.
		 */
		return (bool) apply_filters( 'acsp_send_header', true, $context );
	}

	/**
	 * Note any Content-Security-Policy this request already carries.
	 *
	 * Browsers enforce every policy they receive, so a second policy from the
	 * host, a reverse proxy, a security plugin or the theme does not replace
	 * this one - the two intersect, and a resource has to be allowed by both.
	 * That breaks sites in a way the owner cannot fix from this plugin's
	 * Policy screen, so it has to be detected and said out loud. Only headers
	 * PHP knows about are visible here; one added by the web server after PHP
	 * returns is caught by the crawl instead.
	 *
	 * @return array Foreign header names found on this response.
	 */
	protected function foreign_policies() {
		$found = array();
		if ( ! function_exists( 'headers_list' ) ) {
			return $found;
		}
		foreach ( headers_list() as $header ) {
			$name = strtolower( trim( strtok( $header, ':' ) ) );
			if ( in_array( $name, array( 'content-security-policy', 'content-security-policy-report-only' ), true ) ) {
				$found[] = $name;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Send the headers.
	 *
	 * @param string $context front | admin | login.
	 */
	protected function send( $context ) {
		// Nothing can be added to a response that has already started.
		if ( headers_sent() ) {
			return;
		}
		if ( ! $this->should_send( $context ) ) {
			return;
		}

		$foreign = $this->foreign_policies();
		if ( $foreign ) {
			// Recorded, not acted on: silently dropping someone else's policy
			// would be worse than the collision, and the owner needs to know
			// which one to go and change. The admin screens read this.
			$known = (array) get_option( 'acsp_foreign_csp', array() );
			if ( array_diff( $foreign, $known ) ) {
				update_option( 'acsp_foreign_csp', array_values( array_unique( array_merge( $known, $foreign ) ) ), false );
			}
		}

		// Replace is deliberate for the plugin's own header names: if this
		// request already carries a policy sent by this plugin (two hooks on
		// one request, for instance), one policy is correct and two is not.
		// A policy from somewhere else has a different source and is left in
		// place; it is reported to the owner instead.
		foreach ( ACSP_Policy::build_headers() as $name => $value ) {
			// Belt and braces: a header value can never contain a line break.
			$value = str_replace( array( "\r", "\n" ), '', $value );
			header( $name . ': ' . $value, ! in_array( strtolower( $name ), $foreign, true ) );
		}
	}
}
