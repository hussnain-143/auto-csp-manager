<?php
/**
 * Violation reports list table.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Lists logged violations with allow / block / ignore actions.
 */
class ACSP_Reports_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'acsp-report',
				'plural'   => 'acsp-reports',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Current status filter.
	 *
	 * @return string
	 */
	public function current_status() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		return isset( ACSP_DB::statuses()[ $status ] ) ? $status : '';
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'source'    => __( 'What was blocked', 'auto-csp-manager' ),
			'directive' => __( 'Type', 'auto-csp-manager' ),
			'page'      => __( 'Seen on', 'auto-csp-manager' ),
			'hits'      => __( 'Times', 'auto-csp-manager' ),
			'last_seen' => __( 'Last seen', 'auto-csp-manager' ),
			'status'    => __( 'Status', 'auto-csp-manager' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'source'    => array( 'source', false ),
			'directive' => array( 'directive', false ),
			'hits'      => array( 'hits', true ),
			'last_seen' => array( 'last_seen', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'acsp_allow'  => __( 'Allow (add to policy)', 'auto-csp-manager' ),
			'acsp_block'  => __( 'Block', 'auto-csp-manager' ),
			'acsp_ignore' => __( 'Ignore', 'auto-csp-manager' ),
			'acsp_reset'  => __( 'Mark as needs review', 'auto-csp-manager' ),
			'acsp_delete' => __( 'Delete', 'auto-csp-manager' ),
		);
	}

	/**
	 * Status views.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = ACSP_DB::counts();
		$current = $this->current_status();
		$base    = admin_url( 'admin.php?page=acsp-reports' );
		$views   = array(
			'all' => sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $base ),
				'' === $current ? ' class="current"' : '',
				esc_html__( 'All', 'auto-csp-manager' ),
				esc_html( number_format_i18n( $counts['all'] ) )
			),
		);
		foreach ( ACSP_DB::statuses() as $status => $label ) {
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( add_query_arg( 'status', $status, $base ) ),
				$current === $status ? ' class="current"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $counts[ $status ] ) )
			);
		}
		return $views;
	}

	/**
	 * Load items.
	 */
	public function prepare_items() {
		$per_page = 25;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result = ACSP_DB::query(
			array(
				'status'   => $this->current_status(),
				'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'last_seen',
				'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
				'per_page' => $per_page,
				'paged'    => $this->get_pagenum(),
			)
		);
		// phpcs:enable

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'source' );
		$this->items           = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Empty state.
	 */
	public function no_items() {
		$status = $this->current_status();
		$titles = array(
			''        => __( 'No violations have been reported', 'auto-csp-manager' ),
			'new'     => __( 'Nothing is waiting for review', 'auto-csp-manager' ),
			'allowed' => __( 'You have not allowed anything from a report yet', 'auto-csp-manager' ),
			'blocked' => __( 'You have not blocked anything yet', 'auto-csp-manager' ),
			'ignored' => __( 'You have not ignored anything yet', 'auto-csp-manager' ),
		);
		$bodies = array(
			''        => __( 'Browse your site in another tab. Anything the policy would block shows up here, usually within a few seconds.', 'auto-csp-manager' ),
			'new'     => __( 'Every report has been decided. New ones will appear here as visitors browse your site.', 'auto-csp-manager' ),
			'allowed' => __( 'Sources you allow from a report are added to your policy and listed here.', 'auto-csp-manager' ),
			'blocked' => __( 'Sources you block stay out of the policy, and scans will never add them back.', 'auto-csp-manager' ),
			'ignored' => __( 'Sources you ignore are never logged again and your policy is left alone.', 'auto-csp-manager' ),
		);
		$title  = isset( $titles[ $status ] ) ? $titles[ $status ] : $titles[''];
		$body   = isset( $bodies[ $status ] ) ? $bodies[ $status ] : $bodies[''];
		?>
		<div class="acsp-empty">
			<img class="acsp-empty-mark" src="<?php echo esc_url( ACSP_URL . 'assets/brand/shield-swoosh-black.svg' ); ?>" alt="" width="240" height="225" />
			<p class="acsp-empty-title"><?php echo esc_html( $title ); ?></p>
			<p><?php echo esc_html( $body ); ?></p>
			<?php if ( '' === $status ) : ?>
				<div class="acsp-actions">
					<a class="acsp-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Open your site in a new tab', 'auto-csp-manager' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Checkbox column.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="report[]" value="%d" />', (int) $item->id );
	}

	/**
	 * Main column: the source and the available decisions.
	 *
	 * @param object $item Row.
	 * @return string
	 */
	protected function column_source( $item ) {
		$described = ACSP_Policy::describe_source( $item->source );
		$out       = '<span class="acsp-source">' . esc_html( $item->source ) . '</span>';

		if ( '' !== $described['label'] ) {
			$out .= '<div class="acsp-sub">' . esc_html( $described['label'] ) . '</div>';
		}
		if ( $described['risky'] ) {
			// The glyph is decorative; the warning is spelled out for anyone
			// who cannot see it, so the risk is never carried by an icon alone.
			$out .= '<div class="acsp-sub acsp-note-risky">'
				. '<span aria-hidden="true">&#9888;</span> '
				. '<span class="screen-reader-text">' . esc_html__( 'Warning:', 'auto-csp-manager' ) . '</span> '
				. esc_html__( 'Allowing this weakens the protection the policy gives you. Only allow it if your site does not work without it.', 'auto-csp-manager' )
				. '</div>';
		}
		if ( '' !== $item->blocked_uri && $item->blocked_uri !== $item->source && false !== strpos( $item->blocked_uri, '/' ) ) {
			$out .= '<div class="acsp-sub acsp-break">' . esc_html( $item->blocked_uri ) . '</div>';
		}
		if ( '' !== $item->script_sample ) {
			$out .= '<div class="acsp-sub">' . esc_html__( 'Code sample:', 'auto-csp-manager' ) . ' <code>' . esc_html( $item->script_sample ) . '</code></div>';
		}

		$labels  = array(
			'allow'  => __( 'Allow', 'auto-csp-manager' ),
			'block'  => __( 'Block', 'auto-csp-manager' ),
			'ignore' => __( 'Ignore', 'auto-csp-manager' ),
			'reset'  => __( 'Needs review', 'auto-csp-manager' ),
			'delete' => __( 'Delete', 'auto-csp-manager' ),
		);
		$hide    = array(
			'new'     => 'reset',
			'allowed' => 'allow',
			'blocked' => 'block',
			'ignored' => 'ignore',
		);
		$actions = array();
		foreach ( $labels as $action => $label ) {
			if ( isset( $hide[ $item->status ] ) && $hide[ $item->status ] === $action ) {
				continue;
			}
			$url                          = wp_nonce_url(
				add_query_arg(
					array_filter(
						array(
							'page'        => 'acsp-reports',
							'status'      => $this->current_status(),
							'acsp_action' => $action,
							'report'      => (int) $item->id,
						)
					),
					admin_url( 'admin.php' )
				),
				'acsp_report_' . (int) $item->id
			);
			$actions[ 'acsp-' . $action ] = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) );
		}

		return $out . $this->row_actions( $actions, true ); // Always visible, not only on hover.
	}

	/**
	 * Other columns.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'directive':
				$defs  = ACSP_Policy::directive_definitions();
				$label = isset( $defs[ $item->directive ] ) ? $defs[ $item->directive ]['label'] : $item->directive;
				return esc_html( $label ) . '<div class="acsp-sub"><code>' . esc_html( $item->effective_directive ? $item->effective_directive : $item->directive ) . '</code></div>';

			case 'page':
				if ( '' === $item->document_uri ) {
					return '&mdash;';
				}
				$path = (string) wp_parse_url( $item->document_uri, PHP_URL_PATH );
				return sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer" class="acsp-break">%s</a>', esc_url( $item->document_uri ), esc_html( '' !== $path ? $path : '/' ) );

			case 'hits':
				return esc_html( number_format_i18n( (int) $item->hits ) );

			case 'last_seen':
				$time = strtotime( $item->last_seen . ' UTC' );
				/* translators: %s: human-readable time difference */
				return '<span title="' . esc_attr( get_date_from_gmt( $item->last_seen, 'Y-m-d H:i' ) ) . '">' . esc_html( sprintf( __( '%s ago', 'auto-csp-manager' ), human_time_diff( $time ) ) ) . '</span>';

			case 'status':
				$statuses = ACSP_DB::statuses();
				$label    = isset( $statuses[ $item->status ] ) ? $statuses[ $item->status ] : $item->status;

				// Each status gets its own tone *and* its own dot shape, so it
				// is distinguishable without colour. The word is always there.
				$tones = array(
					'new'     => 'warn',
					'allowed' => 'ok',
					'blocked' => 'danger',
					'ignored' => 'neutral',
				);
				$tone  = isset( $tones[ $item->status ] ) ? $tones[ $item->status ] : 'neutral';
				$out   = '<span class="acsp-badge is-' . esc_attr( $tone ) . '">'
					. '<span class="acsp-badge-dot" aria-hidden="true"></span>'
					. esc_html( $label )
					. '</span>';

				// A block that a broader rule still overrides must say so here,
				// not just look like every other block.
				if ( 'blocked' === $item->status ) {
					$covered_by = ACSP_DB::advisory_for( $item->directive, $item->source );
					if ( $covered_by ) {
						$out .= '<div class="acsp-sub acsp-note-risky">'
							. '<span aria-hidden="true">&#9888;</span> '
							. '<span class="screen-reader-text">' . esc_html__( 'Warning:', 'auto-csp-manager' ) . '</span> '
							. esc_html__( 'Not enforced: still allowed by', 'auto-csp-manager' )
							. ' <code>' . esc_html( implode( ' ', $covered_by ) ) . '</code></div>';
					}
				}
				return $out;
		}
		return '';
	}

	/**
	 * Extra controls above the table.
	 *
	 * @param string $which top | bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which || ! $this->has_items() ) {
			return;
		}
		echo '<div class="alignleft actions">';
		submit_button(
			__( 'Clear all "needs review"', 'auto-csp-manager' ),
			'acsp-btn acsp-btn-danger acsp-btn-sm acsp-confirm',
			'acsp_clear_new',
			false,
			array(
				'data-confirm-title'  => __( 'Delete every unreviewed report?', 'auto-csp-manager' ),
				'data-confirm'        => __( 'Every report still waiting for review will be deleted. Your allowed, blocked and ignored decisions are not touched. This cannot be undone.', 'auto-csp-manager' ),
				'data-confirm-action' => __( 'Delete reports', 'auto-csp-manager' ),
			)
		);
		echo '</div>';
	}
}
