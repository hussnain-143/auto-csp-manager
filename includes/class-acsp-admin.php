<?php
/**
 * Admin screens.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

require_once ACSP_DIR . 'includes/class-acsp-reports-table.php';

/**
 * Admin pages, form handlers and the step-by-step scan endpoint.
 */
class ACSP_Admin {

	const CAP = 'manage_options';

	/**
	 * Reports table instance (created on the reports screen only).
	 *
	 * @var ACSP_Reports_Table|null
	 */
	protected $table = null;

	/**
	 * Hook up.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_post_acsp_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_acsp_save_policy', array( $this, 'save_policy' ) );
		add_action( 'admin_post_acsp_apply_scan', array( $this, 'apply_scan' ) );
		add_action( 'admin_post_acsp_rebuild_policy', array( $this, 'rebuild_policy' ) );
		add_action( 'admin_post_acsp_narrow_block', array( $this, 'narrow_block' ) );
		add_action( 'admin_post_acsp_block_advisory', array( $this, 'block_advisory' ) );
		add_action( 'wp_ajax_acsp_scan_step', array( $this, 'ajax_scan_step' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ACSP_FILE ), array( $this, 'action_links' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Plumbing
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Screens.
	 *
	 * @return array slug => label
	 */
	protected function pages() {
		return array(
			'acsp'          => __( 'Dashboard', 'auto-csp-manager' ),
			'acsp-scanner'  => __( 'Scanner', 'auto-csp-manager' ),
			'acsp-policy'   => __( 'Policy', 'auto-csp-manager' ),
			'acsp-reports'  => __( 'Reports', 'auto-csp-manager' ),
			'acsp-settings' => __( 'Settings', 'auto-csp-manager' ),
		);
	}

	/**
	 * Register the menu.
	 *
	 * The tab order follows the job rather than the alphabet: discover what
	 * the site loads, decide the policy, review what the policy caught, and
	 * only then change how the plugin behaves.
	 */
	public function menu() {
		$counts = ACSP_DB::counts();
		$bubble = $counts['new'] ? ' <span class="awaiting-mod">' . esc_html( number_format_i18n( $counts['new'] ) ) . '</span>' : '';

		// The sidebar icon is drawn by menu-icon.css as a mask, so it takes
		// the colour of whichever admin colour scheme is in use instead of
		// being a fixed black or white asset that disappears in half of them.
		add_menu_page( __( 'Auto CSP Manager', 'auto-csp-manager' ), __( 'CSP Manager', 'auto-csp-manager' ) . $bubble, self::CAP, 'acsp', array( $this, 'page_dashboard' ), 'none', 81 );
		add_submenu_page( 'acsp', __( 'CSP Dashboard', 'auto-csp-manager' ), __( 'Dashboard', 'auto-csp-manager' ), self::CAP, 'acsp', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'acsp', __( 'CSP Scanner', 'auto-csp-manager' ), __( 'Scanner', 'auto-csp-manager' ), self::CAP, 'acsp-scanner', array( $this, 'page_scanner' ) );
		add_submenu_page( 'acsp', __( 'CSP Policy', 'auto-csp-manager' ), __( 'Policy', 'auto-csp-manager' ), self::CAP, 'acsp-policy', array( $this, 'page_policy' ) );
		$reports = add_submenu_page( 'acsp', __( 'CSP Reports', 'auto-csp-manager' ), __( 'Reports', 'auto-csp-manager' ) . $bubble, self::CAP, 'acsp-reports', array( $this, 'page_reports' ) );
		add_submenu_page( 'acsp', __( 'CSP Settings', 'auto-csp-manager' ), __( 'Settings', 'auto-csp-manager' ), self::CAP, 'acsp-settings', array( $this, 'page_settings' ) );

		add_action( 'load-' . $reports, array( $this, 'handle_report_actions' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=acsp-settings' ) ) . '">' . esc_html__( 'Settings', 'auto-csp-manager' ) . '</a>' );
		return $links;
	}

	/**
	 * After activation, go straight to the scanner so the live crawl can
	 * finish the first policy.
	 */
	public function activation_redirect() {
		if ( ! get_transient( 'acsp_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'acsp_activation_redirect' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) || wp_doing_ajax() || is_network_admin() || ! current_user_can( self::CAP ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=acsp-scanner&acsp_welcome=1' ) );
		exit;
	}

	/**
	 * Scripts and styles for the plugin's screens.
	 *
	 * @param string $hook Screen hook.
	 */
	public function assets( $hook ) {
		// The sidebar icon is on every admin screen, so its rule is the one
		// stylesheet loaded globally. It is a handful of declarations, all
		// scoped to the plugin's own menu id.
		wp_enqueue_style( 'acsp-menu-icon', ACSP_URL . 'assets/css/menu-icon.css', array(), ACSP_VERSION );
		wp_add_inline_style(
			'acsp-menu-icon',
			'#adminmenu{--acsp-menu-icon:url("' . esc_url( ACSP_URL . 'assets/brand/menu-icon.svg' ) . '");'
				. '--acsp-menu-icon-fallback:url("' . esc_url( ACSP_URL . 'assets/brand/shield-swoosh-gold.svg' ) . '");}'
		);

		if ( false === strpos( $hook, 'acsp' ) ) {
			return;
		}

		// Three layers, loaded in order: tokens, then the components built
		// from them, then the per-screen layout. The original 'acsp-admin'
		// handle is kept as the last layer so anything that enqueued against
		// it still resolves.
		wp_enqueue_style( 'acsp-tokens', ACSP_URL . 'assets/css/tokens.css', array(), ACSP_VERSION );
		wp_enqueue_style( 'acsp-components', ACSP_URL . 'assets/css/components.css', array( 'acsp-tokens' ), ACSP_VERSION );
		wp_enqueue_style( 'acsp-admin', ACSP_URL . 'assets/css/screens.css', array( 'acsp-components' ), ACSP_VERSION );

		wp_enqueue_script( 'acsp-admin', ACSP_URL . 'assets/js/admin.js', array( 'jquery' ), ACSP_VERSION, true );
		wp_localize_script(
			'acsp-admin',
			'ACSP',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'acsp_scan' ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'autoStart' => isset( $_GET['acsp_welcome'] ) && get_option( 'acsp_needs_full_scan' ),
				'i18n'      => array(
					'starting'    => __( 'Starting scan…', 'auto-csp-manager' ),
					'failed'      => __( 'The scan stopped unexpectedly. Please try again.', 'auto-csp-manager' ),
					'remove'      => __( 'Remove', 'auto-csp-manager' ),
					'placeholder' => __( 'Add a source and press Enter', 'auto-csp-manager' ),
					'scanning'    => __( 'Scanning', 'auto-csp-manager' ),
					'complete'    => __( 'Scan complete', 'auto-csp-manager' ),
					'stopped'     => __( 'Scan stopped', 'auto-csp-manager' ),
					'confirm'     => __( 'Are you sure?', 'auto-csp-manager' ),
					'cancel'      => __( 'Cancel', 'auto-csp-manager' ),
					'proceed'     => __( 'Continue', 'auto-csp-manager' ),
				),
			)
		);
	}

	/**
	 * Mark the body while one of the plugin's own screens is open, so the
	 * soft background can be applied without touching any other screen.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'acsp' ) ) {
			$classes .= ' acsp-screen';
		}
		return $classes;
	}

	/**
	 * URL of one of the approved brand assets.
	 *
	 * @param string $file File name inside assets/brand.
	 * @return string
	 */
	protected function brand_url( $file ) {
		return ACSP_URL . 'assets/brand/' . $file;
	}

	/**
	 * Check capability and nonce for a form handler.
	 *
	 * @param string $action Nonce action.
	 */
	protected function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'auto-csp-manager' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to one of the plugin's screens.
	 *
	 * @param string $page Page slug.
	 * @param array  $args Extra query args.
	 */
	protected function redirect( $page, array $args = array() ) {
		$args = array_filter(
			$args,
			static function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Human label for a mode.
	 *
	 * @param string $mode Mode.
	 * @return string
	 */
	protected function mode_label( $mode ) {
		$labels = array(
			'off'     => __( 'Disabled', 'auto-csp-manager' ),
			'report'  => __( 'Report only', 'auto-csp-manager' ),
			'enforce' => __( 'Enforcing', 'auto-csp-manager' ),
		);
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	/**
	 * The tone a mode is shown in.
	 *
	 * "Enforcing" is the only healthy end state, so it is the only one shown
	 * as a success. "Report only" is a safe but unfinished state, and nothing
	 * being sent at all is a warning, not a neutral fact.
	 *
	 * @param string $mode Mode.
	 * @return string Tone key understood by badge() and alert().
	 */
	protected function mode_tone( $mode ) {
		$tones = array(
			'enforce' => 'ok',
			'report'  => 'warn',
			'off'     => 'neutral',
		);
		return isset( $tones[ $mode ] ) ? $tones[ $mode ] : 'neutral';
	}

	/**
	 * A status badge.
	 *
	 * The dot is shaped as well as coloured and the label is always a word,
	 * so the status is never carried by colour alone.
	 *
	 * @param string $label Visible label.
	 * @param string $tone  ok | warn | danger | info | neutral.
	 * @param string $meaning Optional text read out before the label.
	 * @return string HTML.
	 */
	protected function badge( $label, $tone = 'neutral', $meaning = '' ) {
		return '<span class="acsp-badge is-' . esc_attr( $tone ) . '">'
			. '<span class="acsp-badge-dot" aria-hidden="true"></span>'
			. ( '' !== $meaning ? '<span class="acsp-sr-only">' . esc_html( $meaning ) . '</span>' : '' )
			. esc_html( $label )
			. '</span>';
	}

	/**
	 * An inline alert.
	 *
	 * The glyph is decorative; the kind of alert is also stated in words for
	 * assistive technology, and anything that needs the user's attention is
	 * announced rather than silently appearing.
	 *
	 * @param string $tone    ok | warn | danger | info.
	 * @param string $body    Already-escaped HTML body.
	 * @param string $heading Optional plain-text heading.
	 */
	protected function alert( $tone, $body, $heading = '' ) {
		$glyphs = array(
			'ok'     => '&#10003;',
			'warn'   => '&#9888;',
			'danger' => '&#9888;',
			'info'   => '&#9432;',
		);
		$names  = array(
			'ok'     => __( 'Success:', 'auto-csp-manager' ),
			'warn'   => __( 'Warning:', 'auto-csp-manager' ),
			'danger' => __( 'Error:', 'auto-csp-manager' ),
			'info'   => __( 'Note:', 'auto-csp-manager' ),
		);
		$tone   = isset( $glyphs[ $tone ] ) ? $tone : 'info';
		$live   = in_array( $tone, array( 'danger', 'ok' ), true ) ? ' role="alert"' : ' role="note"';

		echo '<div class="acsp-alert is-' . esc_attr( $tone ) . '"' . $live . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $live is one of two literals chosen above.
		echo '<span class="acsp-alert-icon" aria-hidden="true">' . $glyphs[ $tone ] . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal entity from the map above.
		echo '<div>';
		echo '<span class="acsp-sr-only">' . esc_html( $names[ $tone ] ) . '</span>';
		if ( '' !== $heading ) {
			echo '<p><strong>' . esc_html( $heading ) . '</strong></p>';
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- callers pass escaped HTML.
		echo '</div></div>';
	}

	/**
	 * Page header: branding, tabs and notices.
	 *
	 * @param string $current Current page slug.
	 */
	protected function header( $current ) {
		$mode   = ACSP_Settings::get( 'mode' );
		$counts = ACSP_DB::counts();

		echo '<div class="wrap acsp-wrap">';
		?>
		<div class="acsp-masthead">
			<img class="acsp-masthead-logo" src="<?php echo esc_url( $this->brand_url( 'logo-horizontal.svg' ) ); ?>" alt="" width="850" height="210" />
			<h1 class="acsp-sr-only"><?php esc_html_e( 'Auto CSP Manager', 'auto-csp-manager' ); ?></h1>
			<div class="acsp-masthead-meta">
				<?php
				$mode_badge = $this->badge( $this->mode_label( $mode ), $this->mode_tone( $mode ), __( 'Policy mode:', 'auto-csp-manager' ) );
				echo $mode_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes every part it renders.
				?>
			</div>
		</div>

		<nav class="acsp-tabs" aria-label="<?php esc_attr_e( 'Auto CSP Manager screens', 'auto-csp-manager' ); ?>">
			<?php foreach ( $this->pages() as $slug => $label ) : ?>
				<a
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
					class="acsp-tab"
					<?php echo $slug === $current ? 'aria-current="page"' : ''; ?>
				><?php echo esc_html( $label ); ?><?php if ( 'acsp-reports' === $slug && $counts['new'] ) : ?>
					<span class="acsp-count"><?php echo esc_html( number_format_i18n( $counts['new'] ) ); ?><span class="acsp-sr-only"> <?php esc_html_e( 'reports need review', 'auto-csp-manager' ); ?></span></span>
				<?php endif; ?></a>
			<?php endforeach; ?>
		</nav>
		<?php

		$this->notices();
	}

	/**
	 * Show the result of the last action.
	 */
	protected function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['acsp_msg'] ) ? sanitize_key( wp_unslash( $_GET['acsp_msg'] ) ) : '';
		$n    = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		// phpcs:enable

		$messages = array(
			'settings_saved'  => array( 'success', __( 'Settings saved.', 'auto-csp-manager' ) ),
			'policy_saved'    => array( 'success', __( 'Policy saved.', 'auto-csp-manager' ) ),
			'policy_rebuilt'  => array( 'success', __( 'Policy rebuilt from the last scan.', 'auto-csp-manager' ) ),
			/* translators: %d: number of changes */
			'scan_applied'    => array( 'success', sprintf( _n( '%d change applied to the policy.', '%d changes applied to the policy.', $n, 'auto-csp-manager' ), $n ) ),
			'scan_auto'       => array( 'success', sprintf( /* translators: %d: number of sources */ _n( 'Scan finished. %d new source was added to the policy.', 'Scan finished. %d new sources were added to the policy.', $n, 'auto-csp-manager' ), $n ) ),
			'scan_done'       => array( 'success', __( 'Scan finished. Review the results below.', 'auto-csp-manager' ) ),
			'scan_incomplete' => array( 'warning', __( 'The scan could not cover the whole site, so nothing was applied automatically. Review the findings below and apply what you recognise.', 'auto-csp-manager' ) ),
			'no_scan'         => array( 'error', __( 'Run a scan first.', 'auto-csp-manager' ) ),
			'allow'           => array( 'success', sprintf( /* translators: %d: number of reports */ _n( '%d source allowed and added to the policy.', '%d sources allowed and added to the policy.', $n, 'auto-csp-manager' ), $n ) ),
			'block'           => array( 'success', sprintf( /* translators: %d: number of reports */ _n( '%d source blocked.', '%d sources blocked.', $n, 'auto-csp-manager' ), $n ) ),
			'ignore'          => array( 'success', sprintf( /* translators: %d: number of reports */ _n( '%d source ignored. It will not be logged again.', '%d sources ignored. They will not be logged again.', $n, 'auto-csp-manager' ), $n ) ),
			'reset'           => array( 'success', sprintf( /* translators: %d: number of reports */ _n( '%d report marked as needing review.', '%d reports marked as needing review.', $n, 'auto-csp-manager' ), $n ) ),
			'delete'          => array( 'success', sprintf( /* translators: %d: number of reports */ _n( '%d report deleted.', '%d reports deleted.', $n, 'auto-csp-manager' ), $n ) ),
			'failed'          => array( 'error', __( 'That could not be done. The report may no longer exist.', 'auto-csp-manager' ) ),
			'narrowed'        => array( 'success', sprintf( /* translators: %d: number of sources */ _n( 'Rule narrowed and %d source blocked.', 'Rule narrowed and %d sources blocked.', $n, 'auto-csp-manager' ), $n ) ),
			'advisory'        => array( 'warning', __( 'Marked as blocked for your records. The broader rule is still in the policy, so the browser still allows this source.', 'auto-csp-manager' ) ),
			'block_conflict'  => array( 'warning', sprintf( /* translators: %d: number of sources */ _n( '%d source could not be blocked because a broader rule still allows it. Choose what to do below.', '%d sources could not be blocked because a broader rule still allows them. Choose what to do below.', $n, 'auto-csp-manager' ), $n ) ),
		);

		// The result of what the user just did comes first: it is the thing
		// they are looking for. Standing conditions follow, worst first.
		if ( isset( $messages[ $code ] ) ) {
			$tones = array(
				'success' => 'ok',
				'warning' => 'warn',
				'error'   => 'danger',
			);
			$tone  = isset( $tones[ $messages[ $code ][0] ] ) ? $tones[ $messages[ $code ][0] ] : 'info';
			$this->alert( $tone, '<p>' . esc_html( $messages[ $code ][1] ) . '</p>' );
		}

		$key      = 'acsp_rejected_' . get_current_user_id();
		$rejected = get_transient( $key );
		if ( $rejected ) {
			delete_transient( $key );
			$this->alert(
				'warn',
				'<p>' . esc_html__( 'These entries are not valid sources and were left out of the policy:', 'auto-csp-manager' )
					. ' <code>' . esc_html( implode( ' ', (array) $rejected ) ) . '</code></p>'
			);
		}

		if ( ACSP_DB::full_since() ) {
			$this->alert(
				'warn',
				'<p>' . esc_html__( 'New reports are being turned away. Review or clear the reports already waiting, or raise the log size limit in Settings.', 'auto-csp-manager' ) . '</p>'
					. '<p><a href="' . esc_url( admin_url( 'admin.php?page=acsp-settings' ) ) . '">' . esc_html__( 'Open settings', 'auto-csp-manager' ) . '</a></p>',
				__( 'The violation log is full.', 'auto-csp-manager' )
			);
		}

		$advisory = ACSP_DB::advisory_count();
		if ( $advisory ) {
			$this->alert(
				'warn',
				'<p>' . esc_html(
					sprintf(
						/* translators: %d: number of sources */
						_n(
							'%d source is marked blocked but is still allowed by a broader rule in your policy. Browsers do not support blocking an exception to a wider rule, so the block is a note to you rather than something the browser enforces.',
							'%d sources are marked blocked but are still allowed by broader rules in your policy. Browsers do not support blocking an exception to a wider rule, so those blocks are notes to you rather than something the browser enforces.',
							$advisory,
							'auto-csp-manager'
						),
						$advisory
					)
				) . '</p>'
					. '<p><a href="' . esc_url( admin_url( 'admin.php?page=acsp-reports&status=blocked' ) ) . '">' . esc_html__( 'Review them', 'auto-csp-manager' ) . '</a></p>',
				__( 'Some blocks are not being enforced.', 'auto-csp-manager' )
			);
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Dashboard
	 * ---------------------------------------------------------------------
	 */

	/**
	 * How many distinct sources the policy currently allows.
	 *
	 * Counted across directives rather than summed, so a CDN listed under
	 * both scripts and styles is one source, which is what an owner means
	 * when they ask how many places their site loads from.
	 *
	 * @param array $policy Policy array.
	 * @return int
	 */
	protected function source_count( array $policy ) {
		$all = array();
		foreach ( $policy['directives'] as $sources ) {
			foreach ( (array) $sources as $source ) {
				// Keywords such as 'self' and 'none' are policy syntax, not
				// places content comes from, so they are not counted.
				if ( "'" !== substr( (string) $source, 0, 1 ) ) {
					$all[ $source ] = true;
				}
			}
		}
		return count( $all );
	}

	/**
	 * Dashboard screen.
	 */
	public function page_dashboard() {
		$settings = ACSP_Settings::get();
		$counts   = ACSP_DB::counts();
		$last     = get_option( ACSP_Scanner::LAST_OPTION );
		$policy   = ACSP_Policy::get();
		$headers  = ACSP_Policy::build_headers();
		$foreign  = (array) get_option( 'acsp_foreign_csp', array() );
		$mode     = $settings['mode'];
		$scanned  = is_array( $last ) && ! empty( $last['time'] );

		$this->header( 'acsp' );

		if ( $foreign ) {
			$this->alert(
				'warn',
				'<p>' . esc_html__( 'Your hosting, a reverse proxy, your theme or another plugin is sending its own policy. Browsers apply every policy they receive, so a resource has to be allowed by both: the stricter one always wins, and loosening the policy here cannot widen the other one.', 'auto-csp-manager' ) . '</p>'
					. '<p>' . esc_html__( 'Find and remove the other policy, or keep this plugin in "Report only" mode.', 'auto-csp-manager' ) . '</p>'
					. '<p><code>' . esc_html( implode( ', ', $foreign ) ) . '</code></p>',
				__( 'Another Content-Security-Policy is being sent as well.', 'auto-csp-manager' )
			);
		}
		?>
		<div class="acsp-columns">
			<div class="acsp-stack">

				<section class="acsp-card is-hero acsp-status-panel" aria-labelledby="acsp-status-heading">
					<h2 id="acsp-status-heading" class="acsp-sr-only"><?php esc_html_e( 'Current protection status', 'auto-csp-manager' ); ?></h2>
					<div class="acsp-status-mode">
						<?php
						// The mode badge already sits in the masthead on every
						// screen. Repeating it here would say the same thing
						// twice a few centimetres apart, so this names what the
						// panel reports and the headline says the state.
						?>
						<span class="acsp-status-eyebrow"><?php esc_html_e( 'Protection status', 'auto-csp-manager' ); ?></span>
						<p class="acsp-status-headline">
							<?php
							if ( 'enforce' === $mode ) {
								esc_html_e( 'Your policy is being enforced', 'auto-csp-manager' );
							} elseif ( 'report' === $mode ) {
								esc_html_e( 'Watching, not blocking', 'auto-csp-manager' );
							} else {
								esc_html_e( 'No policy is being sent', 'auto-csp-manager' );
							}
							?>
						</p>
					</div>
					<p class="acsp-status-detail">
						<?php
						if ( 'enforce' === $mode ) {
							esc_html_e( 'Browsers are blocking anything your policy does not allow, and still logging what they blocked.', 'auto-csp-manager' );
						} elseif ( 'report' === $mode ) {
							esc_html_e( 'Nothing on your site is being blocked. Everything that would have been blocked is logged instead, so you can build the policy without risk.', 'auto-csp-manager' );
						} else {
							esc_html_e( 'No Content-Security-Policy header is going out, so your visitors have none of this protection and nothing is being logged.', 'auto-csp-manager' );
						}
						?>
					</p>

					<dl class="acsp-definition acsp-inset">
						<dt><?php esc_html_e( 'Applies to', 'auto-csp-manager' ); ?></dt>
						<dd>
							<?php
							$targets = array( __( 'the public website', 'auto-csp-manager' ) );
							if ( $settings['apply_login'] ) {
								$targets[] = __( 'the login page', 'auto-csp-manager' );
							}
							if ( $settings['apply_admin'] ) {
								$targets[] = __( 'the dashboard', 'auto-csp-manager' );
							}
							echo esc_html( ucfirst( implode( ', ', $targets ) ) );
							?>
						</dd>

						<dt><?php esc_html_e( 'Reports go to', 'auto-csp-manager' ); ?></dt>
						<dd>
							<?php
							if ( 'internal' === $settings['report_dest'] ) {
								esc_html_e( 'This site, shown on the Reports screen', 'auto-csp-manager' );
							} elseif ( 'external' === $settings['report_dest'] ) {
								echo '<span class="acsp-break">' . esc_html( $settings['external_url'] ) . '</span>';
							} else {
								esc_html_e( 'Nowhere — reports are not collected', 'auto-csp-manager' );
							}
							?>
						</dd>

						<dt><?php esc_html_e( 'Insecure content', 'auto-csp-manager' ); ?></dt>
						<dd>
							<?php
							if ( $policy['upgrade_insecure'] ) {
								esc_html_e( 'Loaded over https:// automatically', 'auto-csp-manager' );
							} else {
								esc_html_e( 'Left as the page requested it', 'auto-csp-manager' );
							}
							?>
						</dd>
					</dl>
				</section>

				<ul class="acsp-stats">
					<li class="acsp-stat<?php echo $counts['new'] ? ' is-attention' : ''; ?>">
						<span class="acsp-stat-label"><?php esc_html_e( 'Needing review', 'auto-csp-manager' ); ?></span>
						<span class="acsp-stat-value"><?php echo esc_html( number_format_i18n( $counts['new'] ) ); ?></span>
						<span class="acsp-stat-foot">
							<?php if ( $counts['new'] ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-reports&status=new' ) ); ?>"><?php esc_html_e( 'Review them', 'auto-csp-manager' ); ?></a>
							<?php else : ?>
								<?php esc_html_e( 'Nothing waiting on you', 'auto-csp-manager' ); ?>
							<?php endif; ?>
						</span>
					</li>
					<li class="acsp-stat">
						<span class="acsp-stat-label"><?php esc_html_e( 'Sources allowed', 'auto-csp-manager' ); ?></span>
						<span class="acsp-stat-value"><?php echo esc_html( number_format_i18n( $this->source_count( $policy ) ) ); ?></span>
						<span class="acsp-stat-foot">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-policy' ) ); ?>"><?php esc_html_e( 'Edit the policy', 'auto-csp-manager' ); ?></a>
						</span>
					</li>
					<li class="acsp-stat">
						<span class="acsp-stat-label"><?php esc_html_e( 'Violations logged', 'auto-csp-manager' ); ?></span>
						<span class="acsp-stat-value"><?php echo esc_html( number_format_i18n( $counts['all'] ) ); ?></span>
						<span class="acsp-stat-foot">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: allowed count, 2: blocked count */
									__( '%1$s allowed, %2$s blocked', 'auto-csp-manager' ),
									number_format_i18n( $counts['allowed'] ),
									number_format_i18n( $counts['blocked'] )
								)
							);
							?>
						</span>
					</li>
					<li class="acsp-stat">
						<span class="acsp-stat-label"><?php esc_html_e( 'Last scan', 'auto-csp-manager' ); ?></span>
						<span class="acsp-stat-value is-compact">
							<?php
							if ( $scanned ) {
								/* translators: %s: human-readable time difference */
								echo esc_html( sprintf( __( '%s ago', 'auto-csp-manager' ), human_time_diff( (int) $last['time'] ) ) );
							} else {
								esc_html_e( 'Never', 'auto-csp-manager' );
							}
							?>
						</span>
						<span class="acsp-stat-foot">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-scanner' ) ); ?>">
								<?php echo $scanned ? esc_html__( 'Scan again', 'auto-csp-manager' ) : esc_html__( 'Run the first scan', 'auto-csp-manager' ); ?>
							</a>
						</span>
					</li>
				</ul>

				<details class="acsp-disclosure">
					<summary><?php esc_html_e( 'Headers currently being sent', 'auto-csp-manager' ); ?></summary>
					<div class="acsp-disclosure-body">
						<?php if ( $headers ) : ?>
							<p class="acsp-help-text"><?php esc_html_e( 'For reference. If a full-page cache or CDN serves your pages without running WordPress, it may need to be purged after you change the policy.', 'auto-csp-manager' ); ?></p>
							<label class="acsp-sr-only" for="acsp-header-preview"><?php esc_html_e( 'Response headers', 'auto-csp-manager' ); ?></label>
							<?php
							// Built up first so the markup can stay on one line:
							// whitespace inside a textarea is part of its value.
							$header_preview = '';
							foreach ( $headers as $name => $value ) {
								$header_preview .= $name . ': ' . $value . "\n\n";
							}
							?>
							<textarea id="acsp-header-preview" class="acsp-code" rows="8" readonly><?php echo esc_textarea( trim( $header_preview ) ); ?></textarea>
						<?php else : ?>
							<p><?php esc_html_e( 'None. The policy is disabled, so no header is being added to your pages.', 'auto-csp-manager' ); ?></p>
						<?php endif; ?>
					</div>
				</details>
			</div>

			<aside class="acsp-side">
			<section class="acsp-card" aria-labelledby="acsp-quick-heading">
				<div class="acsp-card-head">
					<div>
						<h2 id="acsp-quick-heading"><?php esc_html_e( 'Quick actions', 'auto-csp-manager' ); ?></h2>
					</div>
				</div>
				<ul class="acsp-quick-actions">
					<li>
						<a class="acsp-btn acsp-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-scanner' ) ); ?>">
							<?php echo $scanned ? esc_html__( 'Run a new scan', 'auto-csp-manager' ) : esc_html__( 'Run the first scan', 'auto-csp-manager' ); ?>
						</a>
					</li>
					<li>
						<a class="acsp-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-reports&status=new' ) ); ?>">
							<?php esc_html_e( 'Review reports', 'auto-csp-manager' ); ?>
						</a>
					</li>
					<li>
						<a class="acsp-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-policy' ) ); ?>">
							<?php esc_html_e( 'Edit the policy', 'auto-csp-manager' ); ?>
						</a>
					</li>
					<li>
						<a class="acsp-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-settings' ) ); ?>">
							<?php esc_html_e( 'Change settings', 'auto-csp-manager' ); ?>
						</a>
					</li>
				</ul>
			</section>

			<?php
			// The walkthrough is guidance, not status, so it sits beside the
			// dashboard rather than under it, where it used to take the most
			// space on the screen while mattering least. It is still only
			// shown while the policy is not yet being enforced.
			if ( 'report' === $mode ) :
				?>
				<section class="acsp-card" aria-labelledby="acsp-howto-heading">
					<div class="acsp-card-head">
						<div>
							<h2 id="acsp-howto-heading"><?php esc_html_e( 'Getting to a working policy', 'auto-csp-manager' ); ?></h2>
							<p><?php esc_html_e( 'Three steps, at your own pace. Nothing is blocked until the last one.', 'auto-csp-manager' ); ?></p>
						</div>
					</div>
					<ol class="acsp-steps">
						<li><?php esc_html_e( 'Leave the mode on "Report only" and use your site normally for a few days. Visit your important pages, forms and checkout.', 'auto-csp-manager' ); ?></li>
						<li><?php esc_html_e( 'Open Reports and decide for each entry: Allow it, Block it, or Ignore it.', 'auto-csp-manager' ); ?></li>
						<li><?php esc_html_e( 'When no new reports are coming in, switch the mode to "Enforce".', 'auto-csp-manager' ); ?></li>
					</ol>
				</section>
			<?php endif; ?>
			</aside>
		</div>
		</div>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Settings screen.
	 */
	public function page_settings() {
		$settings = ACSP_Settings::get();
		$counts   = ACSP_DB::counts();

		$this->header( 'acsp-settings' );
		?>
		<form class="acsp-settings-form acsp-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acsp_save_settings" />
			<?php wp_nonce_field( 'acsp_save_settings' ); ?>

			<section class="acsp-card" aria-labelledby="acsp-mode-heading">
				<div class="acsp-card-head">
					<div>
						<h2 id="acsp-mode-heading"><?php esc_html_e( 'Policy mode', 'auto-csp-manager' ); ?></h2>
						<p><?php esc_html_e( 'How strictly browsers should apply the policy you have built.', 'auto-csp-manager' ); ?></p>
					</div>
				</div>
				<div class="acsp-card-body">
					<fieldset class="acsp-choices">
						<legend class="acsp-sr-only"><?php esc_html_e( 'Policy mode', 'auto-csp-manager' ); ?></legend>
						<label class="acsp-choice">
							<input type="radio" name="mode" value="report" <?php checked( $settings['mode'], 'report' ); ?> />
							<span class="acsp-choice-body">
								<span class="acsp-choice-title"><?php esc_html_e( 'Report only', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><?php esc_html_e( 'Safe for testing. Nothing is blocked; anything that would be blocked is logged.', 'auto-csp-manager' ); ?></span>
							</span>
						</label>
						<label class="acsp-choice">
							<input type="radio" name="mode" value="enforce" <?php checked( $settings['mode'], 'enforce' ); ?> />
							<span class="acsp-choice-body">
								<span class="acsp-choice-title"><?php esc_html_e( 'Enforce', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><?php esc_html_e( 'Browsers block everything that is not in the policy. Blocked items are still logged.', 'auto-csp-manager' ); ?></span>
							</span>
						</label>
						<label class="acsp-choice">
							<input type="radio" name="mode" value="off" <?php checked( $settings['mode'], 'off' ); ?> />
							<span class="acsp-choice-body">
								<span class="acsp-choice-title"><?php esc_html_e( 'Disabled', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><?php esc_html_e( 'No policy is sent, and nothing is logged.', 'auto-csp-manager' ); ?></span>
							</span>
						</label>
					</fieldset>

					<?php
					if ( 'enforce' !== $settings['mode'] && $counts['new'] ) {
						$this->alert(
							'warn',
							'<p>' . esc_html(
								sprintf(
									/* translators: %s: number of reports */
									_n(
										'%s report is still waiting for review. If you enforce now, that item will be blocked for your visitors.',
										'%s reports are still waiting for review. If you enforce now, those items will be blocked for your visitors.',
										$counts['new'],
										'auto-csp-manager'
									),
									number_format_i18n( $counts['new'] )
								)
							) . '</p>'
						);
					}
					?>

					<div class="acsp-field is-sensitive">
						<span class="acsp-label"><?php esc_html_e( 'Apply the policy to', 'auto-csp-manager' ); ?></span>
						<fieldset class="acsp-choices">
							<legend class="acsp-sr-only"><?php esc_html_e( 'Where the policy applies', 'auto-csp-manager' ); ?></legend>
							<?php
							// Not a disabled checkbox: a browser draws one as an
							// empty box, which reads as "off" next to the words
							// "always on". A plain row states the fact instead.
							?>
							<div class="acsp-choice is-fixed">
								<span class="acsp-choice-mark" aria-hidden="true">&#10003;</span>
								<span class="acsp-choice-body">
									<span class="acsp-choice-title"><?php esc_html_e( 'The public website', 'auto-csp-manager' ); ?></span>
									<span class="acsp-choice-desc"><?php esc_html_e( 'Always on. This is what the policy is for.', 'auto-csp-manager' ); ?></span>
								</span>
							</div>
							<label class="acsp-choice">
								<input type="checkbox" name="apply_login" value="1" <?php checked( $settings['apply_login'] ); ?> />
								<span class="acsp-choice-body">
									<span class="acsp-choice-title"><?php esc_html_e( 'The login page', 'auto-csp-manager' ); ?></span>
								</span>
							</label>
							<label class="acsp-choice">
								<input type="checkbox" name="apply_admin" value="1" <?php checked( $settings['apply_admin'] ); ?> />
								<span class="acsp-choice-body">
									<span class="acsp-choice-title"><?php esc_html_e( 'The WordPress dashboard (wp-admin)', 'auto-csp-manager' ); ?></span>
									<span class="acsp-choice-desc"><?php esc_html_e( 'Risky. The dashboard and its editors use a lot of inline code, so test in "Report only" before enforcing here.', 'auto-csp-manager' ); ?></span>
								</span>
							</label>
						</fieldset>
						<p class="acsp-help-text"><?php esc_html_e( 'These CSP Manager screens are always exempt, so you can never lock yourself out of them.', 'auto-csp-manager' ); ?></p>
					</div>
				</div>
			</section>

			<section class="acsp-card" aria-labelledby="acsp-reporting-heading">
				<div class="acsp-card-head">
					<div>
						<h2 id="acsp-reporting-heading"><?php esc_html_e( 'Violation reports', 'auto-csp-manager' ); ?></h2>
						<p><?php esc_html_e( 'Where browsers should send a note when something is blocked, or would have been.', 'auto-csp-manager' ); ?></p>
					</div>
				</div>
				<div class="acsp-card-body">
					<fieldset class="acsp-choices">
						<legend class="acsp-sr-only"><?php esc_html_e( 'Send reports to', 'auto-csp-manager' ); ?></legend>
						<label class="acsp-choice">
							<input type="radio" name="report_dest" value="internal" <?php checked( $settings['report_dest'], 'internal' ); ?> />
							<span class="acsp-choice-body">
								<span class="acsp-choice-title"><?php esc_html_e( 'This site (built-in)', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><?php esc_html_e( 'Reports are logged here and shown on the Reports screen.', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><code class="acsp-break"><?php echo esc_html( ACSP_Settings::internal_report_url() ); ?></code></span>
							</span>
						</label>
						<label class="acsp-choice">
							<input type="radio" name="report_dest" value="external" <?php checked( $settings['report_dest'], 'external' ); ?> />
							<span class="acsp-choice-body">
								<span class="acsp-choice-title"><?php esc_html_e( 'An external reporting service', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><?php esc_html_e( 'Reports go there instead, so the Reports screen will stay empty.', 'auto-csp-manager' ); ?></span>
								<label class="acsp-sr-only" for="acsp-external-url"><?php esc_html_e( 'Reporting service URL', 'auto-csp-manager' ); ?></label>
								<input type="url" id="acsp-external-url" class="regular-text" name="external_url" value="<?php echo esc_attr( $settings['external_url'] ); ?>" placeholder="https://example.report-uri.com/r/d/csp/enforce" />
							</span>
						</label>
						<label class="acsp-choice">
							<input type="radio" name="report_dest" value="none" <?php checked( $settings['report_dest'], 'none' ); ?> />
							<span class="acsp-choice-body">
								<span class="acsp-choice-title"><?php esc_html_e( 'Nowhere', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><?php esc_html_e( 'Do not collect reports at all.', 'auto-csp-manager' ); ?></span>
							</span>
						</label>
					</fieldset>

					<div class="acsp-field">
						<label class="acsp-choice">
							<input type="checkbox" name="drop_extension_noise" value="1" <?php checked( $settings['drop_extension_noise'] ); ?> />
							<span class="acsp-choice-body">
								<span class="acsp-choice-title"><?php esc_html_e( 'Discard reports caused by visitors\' browser extensions', 'auto-csp-manager' ); ?></span>
								<span class="acsp-choice-desc"><?php esc_html_e( 'Ad blockers, password managers and similar add-ons inject their own code into pages. That is not part of your site and should not be in your policy.', 'auto-csp-manager' ); ?></span>
							</span>
						</label>
					</div>
				</div>
			</section>

			<section class="acsp-card" aria-labelledby="acsp-retention-heading">
				<div class="acsp-card-head">
					<div>
						<h2 id="acsp-retention-heading"><?php esc_html_e( 'Log size and retention', 'auto-csp-manager' ); ?></h2>
						<p><?php esc_html_e( 'How much of the violation log is kept, and for how long.', 'auto-csp-manager' ); ?></p>
					</div>
				</div>
				<div class="acsp-card-body">
					<div class="acsp-field is-sensitive">
						<label class="acsp-label" for="acsp-retention"><?php esc_html_e( 'Keep unreviewed reports for', 'auto-csp-manager' ); ?></label>
						<input type="number" id="acsp-retention" name="retention_days" min="1" max="365" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" />
						<?php esc_html_e( 'days since they were last seen', 'auto-csp-manager' ); ?>
						<p class="acsp-help-text"><?php esc_html_e( 'Older unreviewed reports are deleted daily. Reports you have allowed, blocked or ignored are your decisions and are kept until you delete them.', 'auto-csp-manager' ); ?></p>
					</div>

					<div class="acsp-field">
						<label class="acsp-label" for="acsp-max-rows"><?php esc_html_e( 'Log size limit', 'auto-csp-manager' ); ?></label>
						<input type="number" id="acsp-max-rows" name="max_rows" min="100" max="50000" step="100" value="<?php echo esc_attr( $settings['max_rows'] ); ?>" />
						<?php esc_html_e( 'different sources', 'auto-csp-manager' ); ?>
						<p class="acsp-help-text"><?php esc_html_e( 'Repeats of the same source are counted, not stored again, so this protects your database from being flooded.', 'auto-csp-manager' ); ?></p>
					</div>
				</div>
			</section>

			<div class="acsp-form-foot">
				<button type="submit" class="acsp-btn acsp-btn-primary"><?php esc_html_e( 'Save settings', 'auto-csp-manager' ); ?></button>
				<p><?php esc_html_e( 'Changes take effect on the next page your site serves.', 'auto-csp-manager' ); ?></p>
			</div>
		</form>
		</div>
		<?php
	}

	/**
	 * Save settings.
	 */
	public function save_settings() {
		$this->guard( 'acsp_save_settings' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		ACSP_Settings::update(
			array(
				'mode'                 => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '',
				'report_dest'          => isset( $_POST['report_dest'] ) ? sanitize_key( wp_unslash( $_POST['report_dest'] ) ) : '',
				'external_url'         => isset( $_POST['external_url'] ) ? esc_url_raw( wp_unslash( $_POST['external_url'] ) ) : '',
				'apply_admin'          => ! empty( $_POST['apply_admin'] ),
				'apply_login'          => ! empty( $_POST['apply_login'] ),
				'drop_extension_noise' => ! empty( $_POST['drop_extension_noise'] ),
				'retention_days'       => isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30,
				'max_rows'             => isset( $_POST['max_rows'] ) ? absint( $_POST['max_rows'] ) : 2000,
			)
		);
		// phpcs:enable

		$this->redirect( 'acsp-settings', array( 'acsp_msg' => 'settings_saved' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Policy editor
	 * ---------------------------------------------------------------------
	 */

	/**
	 * The directives, arranged by how much damage getting them wrong does.
	 *
	 * Fourteen identical rows ask the reader to know which ones matter. These
	 * groups say so. The grouping is presentation only: every directive in
	 * ACSP_Policy::directive_definitions() appears exactly once, and anything
	 * added there later falls into the last group rather than disappearing.
	 *
	 * @return array Group key => { title, desc, directives[] }.
	 */
	protected function directive_groups() {
		$groups = array(
			'critical' => array(
				'title'      => __( 'Highest impact', 'auto-csp-manager' ),
				'desc'       => __( 'These decide what code may run on your pages. An injected script is stopped here or not at all — get these right first.', 'auto-csp-manager' ),
				'directives' => array( 'script-src', 'object-src', 'base-uri', 'form-action' ),
			),
			'content'  => array(
				'title'      => __( 'Where content may come from', 'auto-csp-manager' ),
				'desc'       => __( 'The everyday rules. Too strict and part of your site stops showing; too loose and they add little.', 'auto-csp-manager' ),
				'directives' => array( 'style-src', 'img-src', 'font-src', 'media-src', 'frame-src', 'connect-src' ),
			),
			'advanced' => array(
				'title'      => __( 'Everything else', 'auto-csp-manager' ),
				'desc'       => __( 'The fallback rule and the less common ones. Most sites never need to touch these.', 'auto-csp-manager' ),
				'directives' => array( 'default-src', 'worker-src', 'manifest-src', 'frame-ancestors' ),
			),
		);

		// Anything defined but not placed above still gets an editor.
		$placed  = array_merge( ...array_column( $groups, 'directives' ) );
		$missing = array_diff( array_keys( ACSP_Policy::directive_definitions() ), $placed );
		if ( $missing ) {
			$groups['advanced']['directives'] = array_merge( $groups['advanced']['directives'], array_values( $missing ) );
		}
		return $groups;
	}

	/**
	 * Policy screen.
	 */
	public function page_policy() {
		$policy = ACSP_Policy::get();
		$defs   = ACSP_Policy::directive_definitions();
		$last   = get_option( ACSP_Scanner::LAST_OPTION );

		$this->header( 'acsp-policy' );
		?>
		<form class="acsp-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="acsp_save_policy" />
			<?php wp_nonce_field( 'acsp_save_policy' ); ?>

			<section class="acsp-card">
				<p><?php esc_html_e( 'Each rule below is one kind of content and the places it is allowed to come from. Anything you do not list is blocked — or, in "Report only" mode, logged instead.', 'auto-csp-manager' ); ?></p>
			</section>

			<?php foreach ( $this->directive_groups() as $key => $group ) : ?>
				<section class="acsp-card acsp-directive-group<?php echo 'critical' === $key ? ' is-accent' : ''; ?>" aria-labelledby="acsp-group-<?php echo esc_attr( $key ); ?>">
					<div class="acsp-directive-group-head">
						<h2 id="acsp-group-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $group['title'] ); ?></h2>
						<p><?php echo esc_html( $group['desc'] ); ?></p>
					</div>

					<?php
					foreach ( $group['directives'] as $name ) :
						if ( ! isset( $defs[ $name ], $policy['directives'][ $name ] ) ) {
							continue;
						}
						$sources = (array) $policy['directives'][ $name ];
						$empty   = ! $sources || array( "'none'" ) === $sources;
						?>
						<div class="acsp-directive">
							<div>
								<label class="acsp-directive-name" for="acsp-dir-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $defs[ $name ]['label'] ); ?></label>
								<code class="acsp-directive-key"><?php echo esc_html( $name ); ?></code>
								<p class="acsp-directive-desc"><?php echo esc_html( $defs[ $name ]['desc'] ); ?></p>
							</div>
							<div>
								<input
									type="text"
									class="acsp-sources"
									id="acsp-dir-<?php echo esc_attr( $name ); ?>"
									name="directives[<?php echo esc_attr( $name ); ?>]"
									value="<?php echo esc_attr( implode( ' ', $sources ) ); ?>"
									autocomplete="off"
									spellcheck="false"
									aria-describedby="acsp-dir-<?php echo esc_attr( $name ); ?>-notes"
								/>
								<ul class="acsp-directive-notes" id="acsp-dir-<?php echo esc_attr( $name ); ?>-notes">
									<?php if ( $empty ) : ?>
										<li class="acsp-note-empty"><?php esc_html_e( 'Nothing is allowed. This kind of content is blocked completely.', 'auto-csp-manager' ); ?></li>
									<?php endif; ?>
									<?php
									// Some entries are far weaker than they look. Say
									// so beside the row rather than letting the policy
									// read as stronger than it is.
									foreach ( $sources as $source ) :
										$described = ACSP_Policy::describe_source( $source );
										if ( ! $described['risky'] ) {
											continue;
										}
										?>
										<li class="acsp-note-risky">
											<span aria-hidden="true">&#9888;</span>
											<span class="acsp-sr-only"><?php esc_html_e( 'Warning:', 'auto-csp-manager' ); ?></span>
											<code><?php echo esc_html( $source ); ?></code>
											&mdash; <?php echo esc_html( $described['label'] ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						</div>
					<?php endforeach; ?>
				</section>
			<?php endforeach; ?>

			<section class="acsp-card">
				<div class="acsp-field">
					<label class="acsp-choice">
						<input type="checkbox" name="upgrade_insecure" value="1" <?php checked( $policy['upgrade_insecure'] ); ?> />
						<span class="acsp-choice-body">
							<span class="acsp-choice-title"><?php esc_html_e( 'Load http:// content over https:// automatically', 'auto-csp-manager' ); ?></span>
							<span class="acsp-choice-desc"><?php esc_html_e( 'Recommended for sites served over HTTPS. Only applies in "Enforce" mode.', 'auto-csp-manager' ); ?></span>
						</span>
					</label>
				</div>
			</section>

			<details class="acsp-disclosure">
				<summary><?php esc_html_e( 'What you can enter in a rule', 'auto-csp-manager' ); ?></summary>
				<div class="acsp-disclosure-body">
					<dl class="acsp-syntax">
						<div>
							<dt><code>https://cdn.example.com</code></dt>
							<dd><?php esc_html_e( 'one specific website', 'auto-csp-manager' ); ?></dd>
						</div>
						<div>
							<dt><code>https://*.example.com</code></dt>
							<dd><?php esc_html_e( 'every subdomain of a website', 'auto-csp-manager' ); ?></dd>
						</div>
						<div>
							<dt><code>'self'</code></dt>
							<dd><?php esc_html_e( 'your own website', 'auto-csp-manager' ); ?></dd>
						</div>
						<div>
							<dt><code>data:</code> <code>blob:</code></dt>
							<dd><?php esc_html_e( 'content embedded in or generated by the page itself', 'auto-csp-manager' ); ?></dd>
						</div>
						<div>
							<dt><code>'unsafe-inline'</code> <code>'unsafe-eval'</code></dt>
							<dd><?php esc_html_e( 'inline code and eval. Many themes and plugins need the first; both reduce protection against injected scripts.', 'auto-csp-manager' ); ?></dd>
						</div>
						<div>
							<dt><code>'none'</code></dt>
							<dd><?php esc_html_e( 'block this kind of content completely (same as leaving the rule empty)', 'auto-csp-manager' ); ?></dd>
						</div>
					</dl>
				</div>
			</details>

			<div class="acsp-form-foot">
				<button type="submit" class="acsp-btn acsp-btn-primary"><?php esc_html_e( 'Save policy', 'auto-csp-manager' ); ?></button>
				<p><?php esc_html_e( 'Entries that are not valid sources are reported back to you rather than saved.', 'auto-csp-manager' ); ?></p>
			</div>
		</form>

		<section class="acsp-card" aria-labelledby="acsp-rebuild-heading">
			<div class="acsp-card-head">
				<div>
					<h2 id="acsp-rebuild-heading"><?php esc_html_e( 'Start over', 'auto-csp-manager' ); ?></h2>
					<p><?php esc_html_e( 'Rebuild the policy from the most recent scan. Sources you allowed on the Reports screen are kept and blocked ones stay out; changes typed in by hand above are discarded.', 'auto-csp-manager' ); ?></p>
				</div>
			</div>
			<?php if ( ! is_array( $last ) || empty( $last['findings'] ) ) : ?>
				<p class="acsp-help-text"><?php esc_html_e( 'There is no scan to rebuild from yet. Run one from the Scanner screen first.', 'auto-csp-manager' ); ?></p>
				<p><a class="acsp-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=acsp-scanner' ) ); ?>"><?php esc_html_e( 'Open the scanner', 'auto-csp-manager' ); ?></a></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="acsp_rebuild_policy" />
					<?php wp_nonce_field( 'acsp_rebuild_policy' ); ?>
					<button
						type="submit"
						class="acsp-btn acsp-btn-danger acsp-confirm"
						data-confirm-title="<?php esc_attr_e( 'Rebuild the policy?', 'auto-csp-manager' ); ?>"
						data-confirm="<?php esc_attr_e( 'The current policy will be replaced with one built from the last scan. Anything you typed in by hand is discarded.', 'auto-csp-manager' ); ?>"
						data-confirm-action="<?php esc_attr_e( 'Rebuild policy', 'auto-csp-manager' ); ?>"
					><?php esc_html_e( 'Rebuild policy from last scan', 'auto-csp-manager' ); ?></button>
				</form>
			<?php endif; ?>
		</section>
		</div>
		<?php
	}

	/**
	 * Save the policy editor.
	 */
	public function save_policy() {
		$this->guard( 'acsp_save_policy' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); every token is validated by ACSP_Policy::sanitize_source().
		$raw        = isset( $_POST['directives'] ) && is_array( $_POST['directives'] ) ? wp_unslash( $_POST['directives'] ) : array();
		$directives = array();
		foreach ( array_keys( ACSP_Policy::directive_definitions() ) as $name ) {
			$directives[ $name ] = isset( $raw[ $name ] ) && is_string( $raw[ $name ] ) ? $raw[ $name ] : '';
		}
		$rejected = array();
		ACSP_Policy::save(
			array(
				'directives'       => $directives,
				'upgrade_insecure' => ! empty( $_POST['upgrade_insecure'] ),
			),
			$rejected
		);
		// phpcs:enable

		if ( $rejected ) {
			set_transient( 'acsp_rejected_' . get_current_user_id(), array_slice( array_map( 'sanitize_text_field', $rejected ), 0, 20 ), MINUTE_IN_SECONDS );
		}
		$this->redirect( 'acsp-policy', array( 'acsp_msg' => 'policy_saved' ) );
	}

	/**
	 * Rebuild the policy: baseline + last scan + allowed reports - blocked.
	 */
	public function rebuild_policy() {
		$this->guard( 'acsp_rebuild_policy' );

		$last = get_option( ACSP_Scanner::LAST_OPTION );
		if ( ! is_array( $last ) || ! isset( $last['findings'] ) ) {
			$this->redirect( 'acsp-scanner', array( 'acsp_msg' => 'no_scan' ) );
		}

		$policy = ACSP_Policy::from_findings( $last['findings'], ACSP_DB::blocked_map() );
		foreach ( ACSP_DB::status_map( 'allowed' ) as $directive => $sources ) {
			if ( isset( $policy['directives'][ $directive ] ) ) {
				$policy['directives'][ $directive ] = array_merge( $policy['directives'][ $directive ], array_keys( $sources ) );
			}
		}
		ACSP_Policy::save( $policy );

		$this->redirect( 'acsp-policy', array( 'acsp_msg' => 'policy_rebuilt' ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Reports
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Runs before the reports screen renders: process row and bulk actions.
	 */
	public function handle_report_actions() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$this->table = new ACSP_Reports_Table();
		$status      = $this->table->current_status();
		$valid       = array( 'allow', 'block', 'ignore', 'reset', 'delete' );

		// Single row action.
		if ( isset( $_GET['acsp_action'], $_GET['report'] ) ) {
			$id     = absint( $_GET['report'] );
			$action = sanitize_key( wp_unslash( $_GET['acsp_action'] ) );
			check_admin_referer( 'acsp_report_' . $id );
			$conflict = array();
			$ok       = in_array( $action, $valid, true ) && ACSP_DB::apply_action( $id, $action, $conflict );
			if ( ! $ok && $conflict ) {
				$this->remember_conflicts( array( $conflict ) );
				$this->redirect(
					'acsp-reports',
					array(
						'status'   => $status,
						'acsp_msg' => 'block_conflict',
						'n'        => 1,
					)
				);
			}
			$this->redirect(
				'acsp-reports',
				array(
					'status'   => $status,
					'acsp_msg' => $ok ? $action : 'failed',
					'n'        => 1,
				)
			);
		}

		// Clear everything undecided.
		if ( isset( $_POST['acsp_clear_new'] ) ) {
			check_admin_referer( 'bulk-acsp-reports' );
			$n = ACSP_DB::clear_new();
			$this->redirect(
				'acsp-reports',
				array(
					'acsp_msg' => 'delete',
					'n'        => $n,
				)
			);
		}

		// Bulk action.
		$bulk = $this->table->current_action();
		if ( $bulk && 0 === strpos( $bulk, 'acsp_' ) && ! empty( $_POST['report'] ) && is_array( $_POST['report'] ) ) {
			check_admin_referer( 'bulk-acsp-reports' );
			$action    = substr( $bulk, 5 );
			$n         = 0;
			$conflicts = array();
			if ( in_array( $action, $valid, true ) ) {
				foreach ( array_map( 'absint', $_POST['report'] ) as $id ) {
					$conflict = array();
					if ( ACSP_DB::apply_action( $id, $action, $conflict ) ) {
						++$n;
					} elseif ( $conflict ) {
						$conflicts[] = $conflict;
					}
				}
			}
			if ( $conflicts ) {
				// Some blocks cannot take effect as asked. Those rows are left
				// undecided and the owner is given the choice, rather than
				// being told they are blocked when they are not.
				$this->remember_conflicts( $conflicts );
				$this->redirect(
					'acsp-reports',
					array(
						'status'   => $status,
						'acsp_msg' => 'block_conflict',
						'n'        => count( $conflicts ),
					)
				);
			}
			$this->redirect(
				'acsp-reports',
				array(
					'status'   => $status,
					'acsp_msg' => $n ? $action : 'failed',
					'n'        => $n,
				)
			);
		}
	}

	/**
	 * Keep the blocks that could not take effect, for the next page load.
	 *
	 * @param array $conflicts Conflicts from ACSP_DB::apply_action().
	 */
	protected function remember_conflicts( array $conflicts ) {
		set_transient( 'acsp_conflicts_' . get_current_user_id(), array_slice( $conflicts, 0, 20 ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * What narrowing a covering rule would actually do.
	 *
	 * A wildcard is replaced by the specific origins that are known to be in
	 * use and that it covers: everything the last scan found, plus everything
	 * the owner has already allowed from a report. The blocked source is left
	 * out. The result is shown before anything is changed, because narrowing
	 * "*" or "https:" can be a large change and the owner has to see it.
	 *
	 * @param string $directive Managed directive.
	 * @param string $source    Source being blocked.
	 * @param array  $covering  Entries that permit it.
	 * @return array List of { entry, replacements[] }.
	 */
	public static function narrowing_plan( $directive, $source, array $covering ) {
		$known = array();

		$last = get_option( ACSP_Scanner::LAST_OPTION );
		if ( is_array( $last ) && isset( $last['findings'][ $directive ] ) ) {
			$known = array_keys( (array) $last['findings'][ $directive ] );
		}
		$allowed = ACSP_DB::status_map( 'allowed' );
		if ( isset( $allowed[ $directive ] ) ) {
			$known = array_merge( $known, array_keys( $allowed[ $directive ] ) );
		}
		$known = array_values( array_unique( array_diff( $known, array( $source ) ) ) );

		$plan = array();
		foreach ( $covering as $entry ) {
			$replacements = array();
			foreach ( $known as $candidate ) {
				// Only origins the entry itself permits replace it; anything
				// else is already covered by some other entry in the policy.
				if ( ACSP_Policy::covers( array( $entry ), $candidate ) ) {
					$replacements[] = $candidate;
				}
			}
			$plan[] = array(
				'entry'        => $entry,
				'replacements' => $replacements,
			);
		}
		return $plan;
	}

	/**
	 * Replace the covering rules with the specific origins in use, then block.
	 */
	public function narrow_block() {
		$this->guard( 'acsp_narrow_block' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id  = isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0;
		$row = ACSP_DB::get( $id );
		if ( ! $row ) {
			$this->redirect( 'acsp-reports', array( 'acsp_msg' => 'failed' ) );
		}

		$policy   = ACSP_Policy::get();
		$list     = isset( $policy['directives'][ $row->directive ] ) ? $policy['directives'][ $row->directive ] : array();
		$covering = ACSP_Policy::covering_sources( $list, $row->source, false );

		foreach ( self::narrowing_plan( $row->directive, $row->source, $covering ) as $step ) {
			ACSP_Policy::replace_source( $row->directive, $step['entry'], $step['replacements'] );
		}

		// With the wildcards gone, the ordinary block can now take effect.
		$conflict = array();
		$ok       = ACSP_DB::apply_action( $id, 'block', $conflict );

		$this->redirect(
			'acsp-reports',
			array(
				'acsp_msg' => $ok ? 'narrowed' : 'failed',
				'n'        => 1,
			)
		);
	}

	/**
	 * Keep the broader rule and record the block as advisory only.
	 */
	public function block_advisory() {
		$this->guard( 'acsp_block_advisory' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$id = isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0;
		$ok = ACSP_DB::apply_action( $id, 'block_advisory' );

		$this->redirect(
			'acsp-reports',
			array(
				'acsp_msg' => $ok ? 'advisory' : 'failed',
				'n'        => 1,
			)
		);
	}

	/**
	 * The panel that explains why a block could not take effect.
	 */
	protected function block_conflict_panel() {
		$key       = 'acsp_conflicts_' . get_current_user_id();
		$conflicts = get_transient( $key );
		if ( ! is_array( $conflicts ) || ! $conflicts ) {
			return;
		}
		delete_transient( $key );
		$defs = ACSP_Policy::directive_definitions();
		?>
		<section class="acsp-card is-accent acsp-conflict" aria-labelledby="acsp-conflict-heading">
			<div class="acsp-card-head">
				<div>
					<h2 id="acsp-conflict-heading"><?php esc_html_e( 'These sources cannot simply be blocked', 'auto-csp-manager' ); ?></h2>
					<p><?php esc_html_e( 'A Content-Security-Policy can only list what is allowed. It has no way to say "allow this whole group, except one". A broader rule in your policy already allows these sources, so removing them from the list changes nothing on its own.', 'auto-csp-manager' ); ?></p>
				</div>
			</div>

			<?php foreach ( $conflicts as $conflict ) : ?>
				<?php
				$directive   = isset( $conflict['directive'] ) ? $conflict['directive'] : '';
				$source      = isset( $conflict['source'] ) ? $conflict['source'] : '';
				$covering    = isset( $conflict['covered_by'] ) ? (array) $conflict['covered_by'] : array();
				$conflict_id = isset( $conflict['id'] ) ? (int) $conflict['id'] : 0;
				if ( ! isset( $defs[ $directive ] ) || '' === $source || ! $conflict_id ) {
					continue;
				}
				$plan = self::narrowing_plan( $directive, $source, $covering );
				?>
				<div class="acsp-conflict-item">
					<p class="acsp-conflict-head">
						<code><?php echo esc_html( $source ); ?></code>
						&mdash; <?php echo esc_html( $defs[ $directive ]['label'] ); ?>
					</p>
					<p class="acsp-sub">
						<?php esc_html_e( 'Still allowed by:', 'auto-csp-manager' ); ?>
						<?php foreach ( $covering as $entry ) : ?>
							<code><?php echo esc_html( $entry ); ?></code>
						<?php endforeach; ?>
					</p>

					<div class="acsp-table-wrap">
						<table class="acsp-table is-stacking">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Rule to replace', 'auto-csp-manager' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Replaced with the origins known to be in use', 'auto-csp-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach ( $plan as $step ) : ?>
								<tr>
									<td data-label="<?php esc_attr_e( 'Rule to replace', 'auto-csp-manager' ); ?>"><code><?php echo esc_html( $step['entry'] ); ?></code></td>
									<td data-label="<?php esc_attr_e( 'Replaced with', 'auto-csp-manager' ); ?>">
										<?php if ( $step['replacements'] ) : ?>
											<?php foreach ( $step['replacements'] as $replacement ) : ?>
												<code><?php echo esc_html( $replacement ); ?></code>
											<?php endforeach; ?>
										<?php else : ?>
											<em><?php esc_html_e( 'nothing — no other origin covered by this rule is known to be in use', 'auto-csp-manager' ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<p class="acsp-help-text"><?php esc_html_e( 'Narrowing is the only way to make the block real. A scan cannot see everything, so run in "Report only" mode afterwards and watch for new violations before enforcing.', 'auto-csp-manager' ); ?></p>

					<div class="acsp-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="acsp-inline-form">
							<input type="hidden" name="action" value="acsp_narrow_block" />
							<input type="hidden" name="report" value="<?php echo esc_attr( $conflict_id ); ?>" />
							<?php wp_nonce_field( 'acsp_narrow_block' ); ?>
							<button type="submit" class="acsp-btn acsp-btn-primary"><?php esc_html_e( 'Narrow the rule and block', 'auto-csp-manager' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="acsp-inline-form">
							<input type="hidden" name="action" value="acsp_block_advisory" />
							<input type="hidden" name="report" value="<?php echo esc_attr( $conflict_id ); ?>" />
							<?php wp_nonce_field( 'acsp_block_advisory' ); ?>
							<button type="submit" class="acsp-btn"><?php esc_html_e( 'Keep the rule, note it as blocked', 'auto-csp-manager' ); ?></button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Reports screen.
	 */
	public function page_reports() {
		if ( null === $this->table ) {
			$this->table = new ACSP_Reports_Table();
		}
		$settings = ACSP_Settings::get();
		$counts   = ACSP_DB::counts();
		$this->table->prepare_items();

		$this->header( 'acsp-reports' );
		$this->block_conflict_panel();

		// Why the list may be empty or stale, said before the user wonders.
		if ( 'off' === $settings['mode'] ) {
			$this->alert(
				'warn',
				'<p>' . esc_html__( 'The policy is disabled, so no new reports are being collected.', 'auto-csp-manager' ) . '</p>'
					. '<p><a href="' . esc_url( admin_url( 'admin.php?page=acsp-settings' ) ) . '">' . esc_html__( 'Change the mode', 'auto-csp-manager' ) . '</a></p>'
			);
		} elseif ( 'internal' !== $settings['report_dest'] ) {
			$this->alert(
				'warn',
				'<p>' . esc_html__( 'Reports are not being sent to this site, so nothing new will appear here.', 'auto-csp-manager' ) . '</p>'
					. '<p><a href="' . esc_url( admin_url( 'admin.php?page=acsp-settings' ) ) . '">' . esc_html__( 'Use the built-in log instead', 'auto-csp-manager' ) . '</a></p>'
			);
		}

		if ( $counts['all'] >= (int) $settings['max_rows'] || ACSP_DB::full_since() ) {
			$this->alert(
				'warn',
				'<p>' . esc_html__( 'Undecided entries nobody has looked at for a day are recycled automatically, but anything newer is being turned away until you delete some entries or raise the limit in Settings.', 'auto-csp-manager' ) . '</p>',
				__( 'The report log is full.', 'auto-csp-manager' )
			);
		}
		?>
		<details class="acsp-disclosure">
			<summary><?php esc_html_e( 'What these decisions mean', 'auto-csp-manager' ); ?></summary>
			<div class="acsp-disclosure-body">
				<p><?php esc_html_e( 'Every row is something a visitor\'s browser tried to load that your policy does not allow.', 'auto-csp-manager' ); ?></p>
				<ul class="acsp-alert-list">
					<li><strong><?php esc_html_e( 'Allow', 'auto-csp-manager' ); ?></strong> &mdash; <?php esc_html_e( 'you recognise it and your site needs it. It is added to the policy.', 'auto-csp-manager' ); ?></li>
					<li><strong><?php esc_html_e( 'Block', 'auto-csp-manager' ); ?></strong> &mdash; <?php esc_html_e( 'it should not load. It is kept out of the policy, scans will never add it, and you keep seeing how often it is attempted. If a broader rule in your policy still allows it, you are asked what to do: a policy cannot allow a group and block one member of it.', 'auto-csp-manager' ); ?></li>
					<li><strong><?php esc_html_e( 'Ignore', 'auto-csp-manager' ); ?></strong> &mdash; <?php esc_html_e( 'it is noise. The policy is not changed and it is never logged again.', 'auto-csp-manager' ); ?></li>
				</ul>
			</div>
		</details>

		<div class="acsp-reports-toolbar">
			<?php $this->table->views(); ?>

			<form method="get">
				<input type="hidden" name="page" value="acsp-reports" />
				<?php if ( $this->table->current_status() ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $this->table->current_status() ); ?>" />
				<?php endif; ?>
				<?php $this->table->search_box( __( 'Search reports', 'auto-csp-manager' ), 'acsp-report' ); ?>
			</form>
		</div>

		<form method="post">
			<?php $this->table->display(); ?>
		</form>
		</div>
		<?php
	}

	/*
	 * ---------------------------------------------------------------------
	 * Scanner
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Scanner screen.
	 */
	public function page_scanner() {
		$last = get_option( ACSP_Scanner::LAST_OPTION );
		$defs = ACSP_Policy::directive_definitions();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$welcome = isset( $_GET['acsp_welcome'] );
		$scanned = is_array( $last ) && ! empty( $last['time'] );

		// A scan that was interrupted leaves its progress behind, so the
		// screen can say so instead of pretending nothing happened.
		$resumable = ! $scanned && false !== get_transient( ACSP_Scanner::PROGRESS_TRANSIENT );

		$this->header( 'acsp-scanner' );

		if ( $welcome ) {
			$this->alert(
				'info',
				'<p>' . esc_html__( 'A first policy was built from your database and theme, and the plugin is in "Report only" mode, so nothing on your site is being blocked. A scan of your live pages is running now to complete the policy.', 'auto-csp-manager' ) . '</p>',
				__( 'Auto CSP Manager is active.', 'auto-csp-manager' )
			);
		}

		if ( $resumable ) {
			$this->alert(
				'warn',
				'<p>' . esc_html__( 'A previous scan did not finish. Starting a new scan picks up from the beginning; nothing it found so far has been applied to your policy.', 'auto-csp-manager' ) . '</p>'
			);
		}
		?>
		<section class="acsp-card acsp-scan-panel" aria-labelledby="acsp-scan-heading">
			<div class="acsp-card-head">
				<div>
					<h2 id="acsp-scan-heading"><?php esc_html_e( 'Scan this site', 'auto-csp-manager' ); ?></h2>
					<p><?php esc_html_e( 'Looks through your content, widgets and settings in the database, the files of your active theme, and a sample of your live pages, and lists every outside source they load.', 'auto-csp-manager' ); ?></p>
				</div>
			</div>

			<div class="acsp-actions">
				<button type="button" class="acsp-btn acsp-btn-primary acsp-scan-btn" data-auto="0">
					<?php esc_html_e( 'Scan and let me review', 'auto-csp-manager' ); ?>
				</button>
				<button
					type="button"
					class="acsp-btn acsp-scan-btn acsp-confirm"
					data-auto="1"
					data-confirm-title="<?php esc_attr_e( 'Update the policy automatically?', 'auto-csp-manager' ); ?>"
					data-confirm="<?php esc_attr_e( 'Every new source the scan finds will be added to your policy without review, except ones you have blocked. If your site has been tampered with, this would allow the attacker\'s source too.', 'auto-csp-manager' ); ?>"
					data-confirm-action="<?php esc_attr_e( 'Scan and update', 'auto-csp-manager' ); ?>"
				>
					<?php esc_html_e( 'Scan and update the policy automatically', 'auto-csp-manager' ); ?>
				</button>
			</div>
			<p class="acsp-help-text"><?php esc_html_e( 'The automatic option never removes anything. If you suspect your site has been tampered with, review the results yourself instead.', 'auto-csp-manager' ); ?></p>

			<div class="acsp-scan-progress" id="acsp-scan-progress" hidden>
				<div class="acsp-scan-progress-head">
					<span class="acsp-scan-phase" id="acsp-scan-phase"></span>
					<span class="acsp-scan-percent" id="acsp-scan-percent"></span>
				</div>
				<div
					class="acsp-progress"
					id="acsp-scan-bar-track"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
					aria-labelledby="acsp-scan-heading"
				>
					<div class="acsp-progress-bar" id="acsp-scan-bar"></div>
				</div>
				<?php
				// The log is the running commentary for everyone, and the only
				// progress report a screen reader gets, so it is a polite live
				// region rather than a silent list.
				?>
				<ul id="acsp-scan-log" class="acsp-scan-log" aria-live="polite" aria-atomic="false"></ul>
			</div>
		</section>

		<?php
		if ( ! $scanned ) {
			?>
			<section class="acsp-card">
				<div class="acsp-empty">
					<img class="acsp-empty-mark" src="<?php echo esc_url( $this->brand_url( 'shield-swoosh-black.svg' ) ); ?>" alt="" width="240" height="225" />
					<p class="acsp-empty-title"><?php esc_html_e( 'No scan has run yet', 'auto-csp-manager' ); ?></p>
					<p><?php esc_html_e( 'Run a scan and the sources your site loads will be listed here, so you can decide which of them belong in your policy.', 'auto-csp-manager' ); ?></p>
				</div>
			</section>
			</div>
			<?php
			return;
		}

		$stats = wp_parse_args(
			isset( $last['stats'] ) ? (array) $last['stats'] : array(),
			array(
				'posts'    => 0,
				'meta'     => 0,
				'options'  => 0,
				'files'    => 0,
				'pages'    => 0,
				'crawled'  => false,
				'warnings' => array(),
			)
		);
		$diff  = ACSP_Scanner::diff( $last );
		?>

		<section class="acsp-card" aria-labelledby="acsp-last-scan-heading">
			<div class="acsp-card-head">
				<div>
					<h2 id="acsp-last-scan-heading">
						<?php
						/* translators: %s: human-readable time difference */
						echo esc_html( sprintf( __( 'Last scan: %s ago', 'auto-csp-manager' ), human_time_diff( (int) $last['time'] ) ) );
						?>
					</h2>
					<p><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $last['time'] ), get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?></p>
				</div>
			</div>

			<dl class="acsp-scan-summary">
				<div>
					<dt><?php esc_html_e( 'Content items', 'auto-csp-manager' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $stats['posts'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Custom fields', 'auto-csp-manager' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $stats['meta'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Settings', 'auto-csp-manager' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $stats['options'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Theme files', 'auto-csp-manager' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $stats['files'] ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Live pages', 'auto-csp-manager' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( $stats['pages'] ) ); ?></dd>
				</div>
			</dl>

			<?php if ( ! $stats['crawled'] ) : ?>
				<p class="acsp-help-text"><?php esc_html_e( 'Live pages have not been scanned yet. Run a scan from this screen to include them.', 'auto-csp-manager' ); ?></p>
			<?php endif; ?>
		</section>

		<?php
		foreach ( (array) $stats['warnings'] as $warning ) {
			$this->alert( 'warn', '<p>' . esc_html( $warning ) . '</p>' );
		}

		if ( ! empty( $stats['incomplete'] ) ) {
			$body = '';
			if ( ! empty( $stats['skipped'] ) ) {
				$body .= '<p>' . esc_html(
					sprintf(
						/* translators: %s: comma-separated list of things that were skipped */
						__( 'Not scanned: %s.', 'auto-csp-manager' ),
						implode( ', ', array_map( 'strval', (array) $stats['skipped'] ) )
					)
				) . '</p>';
			}
			$body .= '<p>' . esc_html__( 'Removing sources on the strength of an incomplete scan can break the site, so check the list below rather than applying it wholesale, and run the scan again.', 'auto-csp-manager' ) . '</p>';
			$this->alert( 'warn', $body, __( 'This scan did not cover the whole site.', 'auto-csp-manager' ) );
		}

		if ( ! $diff['add'] && ! $diff['remove'] ) {
			$this->alert( 'ok', '<p>' . esc_html__( 'Your policy already matches everything the scan found. There is nothing to change.', 'auto-csp-manager' ) . '</p>' );
		} else {
			?>
			<form class="acsp-stack" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="acsp_apply_scan" />
				<?php wp_nonce_field( 'acsp_apply_scan' ); ?>

				<?php if ( $diff['add'] ) : ?>
					<section class="acsp-card" aria-labelledby="acsp-add-heading">
						<div class="acsp-card-head">
							<div>
								<h2 id="acsp-add-heading"><?php esc_html_e( 'Found on your site, not yet in the policy', 'auto-csp-manager' ); ?></h2>
								<p><?php esc_html_e( 'Ticked items will be added. Untick anything you do not recognise.', 'auto-csp-manager' ); ?></p>
							</div>
						</div>
						<div class="acsp-table-wrap">
							<table class="acsp-table acsp-diff is-stacking">
								<thead>
									<tr>
										<td class="check-column">
											<input type="checkbox" checked id="acsp-check-add" />
											<label class="acsp-sr-only" for="acsp-check-add"><?php esc_html_e( 'Select all sources to add', 'auto-csp-manager' ); ?></label>
										</td>
										<th scope="col"><?php esc_html_e( 'Source', 'auto-csp-manager' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Type', 'auto-csp-manager' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Where it was found', 'auto-csp-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $diff['add'] as $directive => $sources ) : ?>
									<?php foreach ( $sources as $source => $where ) : ?>
										<?php $described = ACSP_Policy::describe_source( $source ); ?>
										<tr>
											<td class="check-column">
												<input type="checkbox" name="add[<?php echo esc_attr( $directive ); ?>][]" value="<?php echo esc_attr( $source ); ?>" checked id="acsp-add-<?php echo esc_attr( $directive . '-' . md5( $source ) ); ?>" />
												<label class="acsp-sr-only" for="acsp-add-<?php echo esc_attr( $directive . '-' . md5( $source ) ); ?>">
													<?php
													echo esc_html(
														sprintf(
															/* translators: %s: a source such as https://cdn.example.com */
															__( 'Add %s to the policy', 'auto-csp-manager' ),
															$source
														)
													);
													?>
												</label>
											</td>
											<td data-label="<?php esc_attr_e( 'Source', 'auto-csp-manager' ); ?>">
												<span class="acsp-diff-source"><?php echo esc_html( $source ); ?></span>
												<?php if ( '' !== $described['label'] ) : ?>
													<div class="acsp-sub<?php echo $described['risky'] ? ' acsp-note-risky' : ''; ?>">
														<?php if ( $described['risky'] ) : ?>
															<span aria-hidden="true">&#9888;</span>
															<span class="acsp-sr-only"><?php esc_html_e( 'Warning:', 'auto-csp-manager' ); ?></span>
														<?php endif; ?>
														<?php echo esc_html( $described['label'] ); ?>
													</div>
												<?php endif; ?>
											</td>
											<td data-label="<?php esc_attr_e( 'Type', 'auto-csp-manager' ); ?>">
												<?php echo esc_html( $defs[ $directive ]['label'] ); ?>
												<div class="acsp-sub"><code><?php echo esc_html( $directive ); ?></code></div>
											</td>
											<td class="acsp-break acsp-where" data-label="<?php esc_attr_e( 'Where it was found', 'auto-csp-manager' ); ?>">
												<?php echo esc_html( implode( '; ', (array) $where ) ); ?>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( $diff['remove'] ) : ?>
					<section class="acsp-card" aria-labelledby="acsp-remove-heading">
						<div class="acsp-card-head">
							<div>
								<h2 id="acsp-remove-heading"><?php esc_html_e( 'In the policy, but not found by this scan', 'auto-csp-manager' ); ?></h2>
								<p><?php esc_html_e( 'Ticked items will be removed. A scan cannot see everything — for example sources loaded by scripts after the page opens, or ones you allowed from a report — so only remove what you know is no longer used.', 'auto-csp-manager' ); ?></p>
							</div>
						</div>
						<div class="acsp-table-wrap">
							<table class="acsp-table acsp-diff is-stacking">
								<thead>
									<tr>
										<td class="check-column">
											<input type="checkbox" id="acsp-check-remove" />
											<label class="acsp-sr-only" for="acsp-check-remove"><?php esc_html_e( 'Select all sources to remove', 'auto-csp-manager' ); ?></label>
										</td>
										<th scope="col"><?php esc_html_e( 'Source', 'auto-csp-manager' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Type', 'auto-csp-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
								<?php foreach ( $diff['remove'] as $directive => $sources ) : ?>
									<?php foreach ( $sources as $source ) : ?>
										<tr>
											<td class="check-column">
												<input type="checkbox" name="remove[<?php echo esc_attr( $directive ); ?>][]" value="<?php echo esc_attr( $source ); ?>" id="acsp-remove-<?php echo esc_attr( $directive . '-' . md5( $source ) ); ?>" />
												<label class="acsp-sr-only" for="acsp-remove-<?php echo esc_attr( $directive . '-' . md5( $source ) ); ?>">
													<?php
													echo esc_html(
														sprintf(
															/* translators: %s: a source such as https://cdn.example.com */
															__( 'Remove %s from the policy', 'auto-csp-manager' ),
															$source
														)
													);
													?>
												</label>
											</td>
											<td data-label="<?php esc_attr_e( 'Source', 'auto-csp-manager' ); ?>">
												<span class="acsp-diff-source"><?php echo esc_html( $source ); ?></span>
											</td>
											<td data-label="<?php esc_attr_e( 'Type', 'auto-csp-manager' ); ?>">
												<?php echo esc_html( $defs[ $directive ]['label'] ); ?>
												<div class="acsp-sub"><code><?php echo esc_html( $directive ); ?></code></div>
											</td>
										</tr>
									<?php endforeach; ?>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>
				<?php endif; ?>

				<div class="acsp-form-foot">
					<button type="submit" class="acsp-btn acsp-btn-primary"><?php esc_html_e( 'Apply selected changes', 'auto-csp-manager' ); ?></button>
					<p><?php esc_html_e( 'Only the ticked items are changed. Nothing else in your policy is touched.', 'auto-csp-manager' ); ?></p>
				</div>
			</form>
			<?php
		}
		?>

		<?php if ( $diff['blocked'] ) : ?>
			<section class="acsp-card" aria-labelledby="acsp-blocked-heading">
				<div class="acsp-card-head">
					<div>
						<h2 id="acsp-blocked-heading"><?php esc_html_e( 'Found, but blocked by you', 'auto-csp-manager' ); ?></h2>
						<p><?php esc_html_e( 'These are on your site but stay out of the policy because you blocked them on the Reports screen.', 'auto-csp-manager' ); ?></p>
					</div>
				</div>
				<ul class="acsp-directive-notes">
					<?php foreach ( $diff['blocked'] as $directive => $sources ) : ?>
						<?php foreach ( $sources as $source ) : ?>
							<li>
								<code><?php echo esc_html( $source ); ?></code>
								&mdash; <?php echo esc_html( isset( $defs[ $directive ] ) ? $defs[ $directive ]['label'] : $directive ); ?>
							</li>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Apply the changes ticked on the scanner screen.
	 */
	public function apply_scan() {
		$this->guard( 'acsp_apply_scan' );

		$last = get_option( ACSP_Scanner::LAST_OPTION );
		if ( ! is_array( $last ) ) {
			$this->redirect( 'acsp-scanner', array( 'acsp_msg' => 'no_scan' ) );
		}
		$diff = ACSP_Scanner::diff( $last );

		// Only accept what the scan actually offered.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); values are matched against the stored scan result.
		$add_in    = isset( $_POST['add'] ) && is_array( $_POST['add'] ) ? wp_unslash( $_POST['add'] ) : array();
		$remove_in = isset( $_POST['remove'] ) && is_array( $_POST['remove'] ) ? wp_unslash( $_POST['remove'] ) : array();
		// phpcs:enable

		$add    = array();
		$remove = array();
		foreach ( $diff['add'] as $directive => $sources ) {
			if ( isset( $add_in[ $directive ] ) && is_array( $add_in[ $directive ] ) ) {
				$add[ $directive ] = array_values( array_intersect( array_keys( $sources ), array_map( 'strval', $add_in[ $directive ] ) ) );
			}
		}
		foreach ( $diff['remove'] as $directive => $sources ) {
			if ( isset( $remove_in[ $directive ] ) && is_array( $remove_in[ $directive ] ) ) {
				$remove[ $directive ] = array_values( array_intersect( $sources, array_map( 'strval', $remove_in[ $directive ] ) ) );
			}
		}

		$n = ACSP_Policy::add_sources( $add ) + ACSP_Policy::remove_sources( $remove );

		$this->redirect(
			'acsp-scanner',
			array(
				'acsp_msg' => 'scan_applied',
				'n'        => $n,
			)
		);
	}

	/**
	 * One step of a scan. The browser calls this repeatedly, passing back the
	 * step and cursor it was given, until the scan reports that it is done.
	 */
	public function ajax_scan_step() {
		check_ajax_referer( 'acsp_scan', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'auto-csp-manager' ) ), 403 );
		}

		$step   = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'start';
		$cursor = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$auto   = ! empty( $_POST['auto'] );

		$state = 'start' === $step ? null : get_transient( ACSP_Scanner::PROGRESS_TRANSIENT );
		if ( 'start' !== $step && ! is_array( $state ) ) {
			wp_send_json_error( array( 'message' => __( 'The scan was interrupted. Please start it again.', 'auto-csp-manager' ) ) );
		}

		$scanner  = new ACSP_Scanner( $state );
		$deadline = microtime( true ) + 8;
		$next     = array( 'finish', 0 );
		$message  = '';

		switch ( $step ) {
			case 'start':
				delete_option( 'acsp_needs_full_scan' );
				$next    = array( 'theme', 0 );
				$message = __( 'Scan started.', 'auto-csp-manager' );
				break;

			case 'theme':
				$scanner->scan_theme();
				$next = array( 'options', 0 );
				/* translators: %s: number of files */
				$message = sprintf( __( 'Theme scanned (%s files).', 'auto-csp-manager' ), number_format_i18n( $scanner->stats()['files'] ) );
				break;

			case 'options':
				$resume = $scanner->scan_options( $cursor, $deadline );
				$next   = $resume ? array( 'options', $resume ) : array( 'posts', 0 );
				/* translators: %s: number of settings */
				$message = sprintf( $resume ? __( 'Scanning widgets and settings… %s so far.', 'auto-csp-manager' ) : __( 'Widgets and settings scanned (%s entries).', 'auto-csp-manager' ), number_format_i18n( $scanner->stats()['options'] ) );
				break;

			case 'posts':
				$resume = $scanner->scan_posts( $cursor, $deadline );
				$next   = $resume ? array( 'posts', $resume ) : array( 'meta', 0 );
				/* translators: %s: number of content items */
				$message = sprintf( $resume ? __( 'Scanning content… %s items so far.', 'auto-csp-manager' ) : __( 'Content scanned (%s items).', 'auto-csp-manager' ), number_format_i18n( $scanner->stats()['posts'] ) );
				break;

			case 'meta':
				$resume = $scanner->scan_meta( $cursor, $deadline );
				$next   = $resume ? array( 'meta', $resume ) : array( 'crawl', 0 );
				/* translators: %s: number of custom fields */
				$message = sprintf( $resume ? __( 'Scanning custom fields… %s so far.', 'auto-csp-manager' ) : __( 'Custom fields and embeds scanned (%s entries).', 'auto-csp-manager' ), number_format_i18n( $scanner->stats()['meta'] ) );
				break;

			case 'crawl':
				$targets = $scanner->crawl_targets();
				if ( ! isset( $targets[ $cursor ] ) ) {
					$next    = array( 'finish', 0 );
					$message = __( 'Live pages scanned.', 'auto-csp-manager' );
					break;
				}
				$ok = $scanner->crawl_url( $targets[ $cursor ] );
				if ( ! $ok && 0 === $cursor ) {
					// The site cannot load its own pages; skip the rest rather than wait for every timeout.
					$next    = array( 'finish', 0 );
					$message = __( 'Your site could not load its own pages, so the live page scan was skipped. "Report only" mode will catch what it would have found.', 'auto-csp-manager' );
					break;
				}
				$next = isset( $targets[ $cursor + 1 ] ) ? array( 'crawl', $cursor + 1 ) : array( 'finish', 0 );
				/* translators: 1: current page number, 2: total pages, 3: URL */
				$message = sprintf( __( 'Live page %1$d of %2$d: %3$s', 'auto-csp-manager' ), $cursor + 1, count( $targets ), $targets[ $cursor ] );
				break;

			case 'finish':
				$result = $scanner->finish();
				$args   = array( 'acsp_msg' => 'scan_done' );
				if ( $auto && ! ACSP_Scanner::is_complete( $result ) ) {
					// Applying the findings of a scan that skipped part of the
					// site would also remove sources it never got to look at.
					$args = array( 'acsp_msg' => 'scan_incomplete' );
				} elseif ( $auto ) {
					$args = array(
						'acsp_msg' => 'scan_auto',
						'n'        => ACSP_Policy::add_sources( ACSP_Scanner::additions( $result ) ),
					);
				}
				wp_send_json_success(
					array(
						'done'     => true,
						'message'  => __( 'Scan complete.', 'auto-csp-manager' ),
						'redirect' => add_query_arg( array_merge( array( 'page' => 'acsp-scanner' ), $args ), admin_url( 'admin.php' ) ),
					)
				);
				break; // Unreachable; wp_send_json_success() exits.

			default:
				wp_send_json_error( array( 'message' => __( 'Unknown scan step.', 'auto-csp-manager' ) ) );
		}

		set_transient( ACSP_Scanner::PROGRESS_TRANSIENT, $scanner->export_state(), HOUR_IN_SECONDS );

		wp_send_json_success(
			array(
				'done'    => false,
				'step'    => $next[0],
				'cursor'  => $next[1],
				'replace' => $step === $next[0], // Same step continuing: update the line instead of adding one.
				'message' => $message,
			)
		);
	}
}
