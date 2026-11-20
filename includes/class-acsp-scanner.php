<?php
/**
 * Site scanner.
 *
 * @package AutoCSPManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds the external sources a site loads, from three places:
 *
 * 1. The database: post content, reusable blocks, templates, custom CSS,
 *    cached embeds, post meta and options (widgets, theme mods, snippets).
 * 2. The active theme (and parent theme) files.
 * 3. A crawl of a sample of rendered front-end pages, which also picks up
 *    everything plugins add at runtime.
 *
 * The scanner can run in one go (activation) or in small resumable steps
 * driven from the admin screen, so large sites never hit a PHP time limit.
 */
class ACSP_Scanner {

	const PROGRESS_TRANSIENT = 'acsp_scan_progress';
	const LAST_OPTION        = 'acsp_last_scan';

	/**
	 * Findings: directive => source => list of places it was found.
	 *
	 * @var array
	 */
	protected $findings = array();

	/**
	 * Counters and warnings.
	 *
	 * @var array
	 */
	protected $stats = array(
		'posts'      => 0,
		'meta'       => 0,
		'options'    => 0,
		'files'      => 0,
		'pages'      => 0,
		'crawled'    => false,
		'warnings'   => array(),
		// A scan that skipped work must never look like a complete one.
		'incomplete' => false,
		'skipped'    => array(),
	);

	/**
	 * Crawl targets, computed once per scan.
	 *
	 * @var array|null
	 */
	protected $targets = null;

	/**
	 * Hostnames that count as this site.
	 *
	 * @var array
	 */
	protected $self_hosts = array();

	/**
	 * Full origins (scheme, host and port) that count as this site.
	 *
	 * @var array
	 */
	protected $self_origins = array();

	/**
	 * Whether the site itself is served over HTTPS.
	 *
	 * @var bool
	 */
	protected $site_https = false;

	/**
	 * Constructor.
	 *
	 * @param array|null $state State from export_state() to resume a scan.
	 */
	public function __construct( $state = null ) {
		$this->self_hosts   = ACSP_Reporter::site_hosts();
		$this->self_origins = ACSP_Reporter::site_origins();
		$this->site_https   = 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );

		if ( is_array( $state ) ) {
			$this->findings = isset( $state['findings'] ) ? (array) $state['findings'] : array();
			$this->stats    = isset( $state['stats'] ) ? array_merge( $this->stats, (array) $state['stats'] ) : $this->stats;
			$this->targets  = isset( $state['targets'] ) && is_array( $state['targets'] ) ? $state['targets'] : null;
		}
	}

	/**
	 * State needed to resume the scan in a later request.
	 *
	 * @return array
	 */
	public function export_state() {
		return array(
			'findings' => $this->findings,
			'stats'    => $this->stats,
			'targets'  => $this->targets,
		);
	}

	/**
	 * Current counters.
	 *
	 * @return array
	 */
	public function stats() {
		return $this->stats;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Running a scan
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Run a whole scan in this request, within a time budget.
	 *
	 * @param bool $with_crawl Also crawl rendered pages.
	 * @param int  $budget     Seconds available.
	 * @return array Scan result.
	 */
	public function run_sync( $with_crawl, $budget = 20 ) {
		$deadline = microtime( true ) + $budget;

		$this->scan_theme();

		$cursor = 0;
		do {
			$cursor = $this->scan_options( $cursor, $deadline );
		} while ( $cursor && microtime( true ) < $deadline );
		if ( $cursor ) {
			$this->skip( __( 'settings and widgets', 'auto-csp-manager' ) );
		}

		$cursor = 0;
		do {
			$cursor = $this->scan_posts( $cursor, $deadline );
		} while ( $cursor && microtime( true ) < $deadline );
		if ( $cursor ) {
			$this->skip( __( 'page and post content', 'auto-csp-manager' ) );
		}

		$cursor = 0;
		do {
			$cursor = $this->scan_meta( $cursor, $deadline );
		} while ( $cursor && microtime( true ) < $deadline );
		if ( $cursor ) {
			$this->skip( __( 'custom fields', 'auto-csp-manager' ) );
		}

		if ( $with_crawl ) {
			foreach ( $this->crawl_targets() as $i => $url ) {
				if ( microtime( true ) >= $deadline ) {
					$this->skip( __( 'some live pages', 'auto-csp-manager' ) );
					break;
				}
				if ( ! $this->crawl_url( $url ) && 0 === $i ) {
					$this->skip( __( 'live pages', 'auto-csp-manager' ) );
					break; // The site cannot reach itself; do not wait for every timeout.
				}
			}
		} else {
			$this->skip( __( 'live pages', 'auto-csp-manager' ) );
		}

		return $this->finish();
	}

	/**
	 * Finalise: add companion sources, store and return the result.
	 *
	 * @return array
	 */
	public function finish() {
		if ( get_option( 'show_avatars' ) ) {
			$this->note( 'img-src', 'https://secure.gravatar.com', __( 'WordPress avatars (Gravatar)', 'auto-csp-manager' ) );
		}
		$this->apply_companions();

		foreach ( $this->findings as &$sources ) {
			ksort( $sources );
		}
		unset( $sources );

		$result = array(
			'time'     => time(),
			'findings' => $this->findings,
			'stats'    => $this->stats,
		);
		update_option( self::LAST_OPTION, $result, false );
		delete_transient( self::PROGRESS_TRANSIENT );

		return $result;
	}

	/**
	 * Compare a scan result with the current policy.
	 *
	 * @param array $result Scan result.
	 * @return array {
	 *     add:     directive => source => where[]  (found, not yet allowed)
	 *     remove:  directive => source[]           (allowed web addresses the scan did not find)
	 *     blocked: directive => source[]           (found, but blocked by the site owner)
	 * }
	 */
	public static function diff( array $result ) {
		$policy   = ACSP_Policy::get();
		$blocked  = ACSP_DB::blocked_map();
		$findings = isset( $result['findings'] ) ? (array) $result['findings'] : array();
		$diff     = array(
			'add'     => array(),
			'remove'  => array(),
			'blocked' => array(),
		);

		foreach ( $findings as $directive => $sources ) {
			if ( ! isset( $policy['directives'][ $directive ] ) ) {
				continue;
			}
			foreach ( $sources as $source => $where ) {
				if ( isset( $blocked[ $directive ][ $source ] ) ) {
					$diff['blocked'][ $directive ][] = $source;
				} elseif ( ! ACSP_Policy::covers( $policy['directives'][ $directive ], $source ) ) {
					$diff['add'][ $directive ][ $source ] = (array) $where;
				}
			}
		}

		foreach ( $policy['directives'] as $directive => $sources ) {
			foreach ( $sources as $source ) {
				// Only web addresses are removal candidates, never keywords or schemes.
				if ( ! preg_match( '#^([a-z][a-z0-9+.-]*://)?(\*\.)?[a-z0-9-]+\.[a-z0-9.-]+#', $source ) ) {
					continue;
				}
				$still_used = isset( $findings[ $directive ][ $source ] );
				if ( ! $still_used && isset( $findings[ $directive ] ) ) {
					// A wildcard entry is still in use if it covers anything that was found.
					foreach ( array_keys( $findings[ $directive ] ) as $found ) {
						if ( ACSP_Policy::covers( array( $source ), $found ) ) {
							$still_used = true;
							break;
						}
					}
				}
				if ( ! $still_used ) {
					$diff['remove'][ $directive ][] = $source;
				}
			}
		}

		return $diff;
	}

	/**
	 * Everything in a scan result that may be added automatically.
	 *
	 * @param array $result Scan result.
	 * @return array directive => source[]
	 */
	public static function additions( array $result ) {
		$diff = self::diff( $result );
		$map  = array();
		foreach ( $diff['add'] as $directive => $sources ) {
			$map[ $directive ] = array_keys( $sources );
		}
		return $map;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Database
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Scan post content in ID order.
	 *
	 * @param int   $after_id Resume after this post ID.
	 * @param float $deadline Stop when microtime() passes this.
	 * @return int Cursor to resume from, or 0 when finished.
	 */
	public function scan_posts( $after_id, $deadline ) {
		global $wpdb;

		$skip_types   = array( 'revision', 'attachment', 'nav_menu_item', 'customize_changeset' );
		$placeholders = implode( ',', array_fill( 0, count( $skip_types ), '%s' ) );

		while ( microtime( true ) < $deadline ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_type, post_content FROM {$wpdb->posts}
					WHERE ID > %d AND post_status IN ('publish','private') AND post_content <> ''
					AND post_type NOT IN ({$placeholders})
					ORDER BY ID ASC LIMIT 100",
					array_merge( array( $after_id ), $skip_types )
				)
			);
			// phpcs:enable

			if ( ! $rows ) {
				return 0;
			}

			foreach ( $rows as $row ) {
				$after_id = (int) $row->ID;
				$content  = substr( $row->post_content, 0, 1048576 );
				/* translators: 1: post ID, 2: post type */
				$where = sprintf( __( 'Content #%1$d (%2$s)', 'auto-csp-manager' ), $row->ID, $row->post_type );

				if ( 'custom_css' === $row->post_type ) {
					$this->extract_css( $content, $where );
				} else {
					$this->extract_html( $content, $where );
					if ( '{' === substr( ltrim( $content ), 0, 1 ) ) {
						$this->extract_loose_urls( $content, $where );
					}
				}
				++$this->stats['posts'];
			}

			if ( count( $rows ) < 100 ) {
				return 0;
			}
		}

		return $after_id;
	}

	/**
	 * Scan post meta that holds markup: cached embeds and page-builder data.
	 *
	 * @param int   $after_id Resume after this meta ID.
	 * @param float $deadline Stop when microtime() passes this.
	 * @return int Cursor to resume from, or 0 when finished.
	 */
	public function scan_meta( $after_id, $deadline ) {
		global $wpdb;

		while ( microtime( true ) < $deadline ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}
					WHERE meta_id > %d AND (
						( meta_key LIKE %s AND meta_key NOT LIKE %s )
						OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s
					)
					ORDER BY meta_id ASC LIMIT 100",
					$after_id,
					$wpdb->esc_like( '_oembed_' ) . '%',
					$wpdb->esc_like( '_oembed_time_' ) . '%',
					'%<script%',
					'%<iframe%',
					'%<link %'
				)
			);

			if ( ! $rows ) {
				return 0;
			}

			foreach ( $rows as $row ) {
				$after_id = (int) $row->meta_id;
				/* translators: 1: meta key, 2: post ID */
				$where = sprintf( __( 'Custom field "%1$s" on #%2$d', 'auto-csp-manager' ), $row->meta_key, $row->post_id );
				$this->extract_html( substr( $row->meta_value, 0, 1048576 ), $where );
				++$this->stats['meta'];
			}

			if ( count( $rows ) < 100 ) {
				return 0;
			}
		}

		return $after_id;
	}

	/**
	 * Scan options that hold markup or URLs: widgets, theme mods, and any
	 * option containing script, iframe, stylesheet or image tags.
	 *
	 * Paginated on option_id. The previous version took a single LIMIT 1000
	 * with no ORDER BY, so on a site with more matching options than that it
	 * silently skipped the rest - and skipped a different, arbitrary set each
	 * run - while still reporting a clean scan.
	 *
	 * @param int        $after_id Resume after this option ID.
	 * @param float|null $deadline Stop when microtime() passes this.
	 * @return int Cursor to resume from, or 0 when finished.
	 */
	public function scan_options( $after_id = 0, $deadline = null ) {
		global $wpdb;

		$after_id = (int) $after_id;
		$deadline = null === $deadline ? microtime( true ) + 10 : $deadline;

		while ( microtime( true ) < $deadline ) {
			// Filtering stays in the database: the option_id range plus
			// ORDER BY option_id LIMIT lets the optimiser walk forward from
			// the cursor rather than re-reading from the start, and matching
			// on the value server-side avoids pulling every option - some of
			// which are megabytes - into PHP just to discard it.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value FROM {$wpdb->options}
					WHERE option_id > %d
					AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s
					AND (
						option_name LIKE %s OR option_name = %s
						OR option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s OR option_value LIKE %s
					)
					ORDER BY option_id ASC LIMIT 100",
					$after_id,
					$wpdb->esc_like( '_transient_' ) . '%',
					$wpdb->esc_like( '_site_transient_' ) . '%',
					$wpdb->esc_like( 'acsp_' ) . '%',
					$wpdb->esc_like( 'widget_' ) . '%',
					'theme_mods_' . get_stylesheet(),
					'%<script%',
					'%<iframe%',
					'%<link %',
					'%<img %'
				)
			);

			if ( ! $rows ) {
				return 0;
			}

			foreach ( $rows as $row ) {
				$after_id = (int) $row->option_id;
				$value    = substr( (string) $row->option_value, 0, 1048576 );
				/* translators: %s: option name */
				$where = sprintf( __( 'Setting "%s"', 'auto-csp-manager' ), $row->option_name );
				$this->extract_html( $value, $where );
				$this->extract_loose_urls( $value, $where );
				++$this->stats['options'];
			}

			if ( count( $rows ) < 100 ) {
				return 0;
			}
		}

		return $after_id;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Theme files
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Scan the active theme and its parent.
	 */
	public function scan_theme() {
		$dirs      = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$max_files = 5000;
		$deadline  = microtime( true ) + 15;
		$skip_dirs = array( 'node_modules', 'vendor', 'bower_components', '.git', '.svn' );

		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
				continue;
			}
			$theme = basename( $dir );

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveCallbackFilterIterator(
						new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
						static function ( $current ) use ( $skip_dirs ) {
							if ( $current->isDir() && ( $current->isLink() || in_array( $current->getFilename(), $skip_dirs, true ) ) ) {
								return false;
							}
							return true;
						}
					)
				);

				$root = wp_normalize_path( realpath( $dir ) );

				foreach ( $iterator as $file ) {
					if ( $this->stats['files'] >= $max_files || microtime( true ) > $deadline ) {
						$this->warn( __( 'The theme is very large, so only part of it was scanned. Report-only mode will reveal anything that was missed.', 'auto-csp-manager' ) );
						$this->skip( __( 'part of the theme', 'auto-csp-manager' ) );
						break 2;
					}
					if ( ! $file->isFile() || ! $file->isReadable() || $file->getSize() > 2097152 || 0 === $file->getSize() ) {
						continue;
					}
					// Recursive iteration follows symlinked directories, so a
					// link inside the theme would walk the scanner out of it.
					// Only files that really live under the theme are read.
					if ( $file->isLink() ) {
						continue;
					}
					$real = wp_normalize_path( (string) realpath( $file->getPathname() ) );
					if ( '' === $real || '' === $root || 0 !== strpos( $real, $root . '/' ) ) {
						continue;
					}
					$ext = strtolower( $file->getExtension() );
					if ( ! in_array( $ext, array( 'php', 'phtml', 'html', 'htm', 'twig', 'css', 'js', 'mjs', 'json' ), true ) ) {
						continue;
					}
					$path = $file->getPathname();
					if ( 'json' === $ext && 'theme.json' !== $file->getFilename() && false === strpos( wp_normalize_path( $path ), '/styles/' ) ) {
						continue;
					}

					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
					$contents = file_get_contents( $path );
					if ( false === $contents || '' === $contents ) {
						continue;
					}

					$relative = $theme . '/' . ltrim( str_replace( wp_normalize_path( $dir ), '', wp_normalize_path( $path ) ), '/' );
					/* translators: %s: file path inside the theme */
					$where = sprintf( __( 'Theme file %s', 'auto-csp-manager' ), $relative );

					$this->scan_file_contents( $contents, $ext, $where );
					++$this->stats['files'];
				}
			} catch ( Exception $e ) {
				/* translators: %s: theme folder name */
				$this->warn( sprintf( __( 'Some files in the theme "%s" could not be read.', 'auto-csp-manager' ), $theme ) );
				$this->skip( __( 'part of the theme', 'auto-csp-manager' ) );
			}
		}
	}

	/**
	 * Scan one theme file.
	 *
	 * @param string $contents File contents.
	 * @param string $ext      Lower-case extension.
	 * @param string $where    Label.
	 */
	protected function scan_file_contents( $contents, $ext, $where ) {
		switch ( $ext ) {
			case 'css':
				$this->extract_css( self::strip_block_comments( $contents ), $where );
				break;

			case 'js':
			case 'mjs':
			case 'json':
				$this->extract_loose_urls( self::strip_block_comments( $contents ), $where );
				break;

			default: // Templates.
				$this->extract_html( $contents, $where );

				// wp_enqueue_script( 'handle', 'https://cdn.example.com/lib.js' ).
				if ( preg_match_all( '/wp_(?:enqueue|register)_(script|style)\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]((?:https?:)?\/\/[^\'"]+)[\'"]/i', $contents, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $m ) {
						$this->add( 'script' === strtolower( $m[1] ) ? 'script-src' : 'style-src', $m[2], $where );
					}
				}
				$this->extract_loose_urls( self::strip_block_comments( $contents ), $where );
		}
	}

	/**
	 * Remove block comments, which are full of licence and documentation
	 * links that are never loaded.
	 *
	 * @param string $text Source text.
	 * @return string
	 */
	protected static function strip_block_comments( $text ) {
		$stripped = preg_replace( '#/\*.*?\*/#s', '', $text );
		return null === $stripped ? $text : $stripped;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Live page crawl
	 * ---------------------------------------------------------------------
	 */

	/**
	 * A sample of front-end URLs covering each kind of page.
	 *
	 * @return array
	 */
	public function crawl_targets() {
		if ( null !== $this->targets ) {
			return $this->targets;
		}

		$urls = array( home_url( '/' ) );

		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			$posts = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => 2,
					'orderby'          => 'modified',
					'suppress_filters' => true,
				)
			);
			foreach ( $posts as $post ) {
				$urls[] = get_permalink( $post );
			}
		}

		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page ) {
			$urls[] = get_permalink( $posts_page );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'number'     => 1,
				'hide_empty' => true,
			)
		);
		if ( ! is_wp_error( $terms ) && $terms ) {
			$link = get_term_link( $terms[0] );
			if ( ! is_wp_error( $link ) ) {
				$urls[] = $link;
			}
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
				$urls[] = wc_get_page_permalink( $page );
			}
		}

		$urls[] = home_url( '/?s=test' );

		/**
		 * Filter the URLs crawled during a scan.
		 *
		 * @param array $urls URLs.
		 */
		$urls = (array) apply_filters( 'acsp_crawl_urls', array_values( array_unique( array_filter( $urls ) ) ) );

		$this->targets = array_slice( array_values( $urls ), 0, 20 );
		return $this->targets;
	}

	/**
	 * Fetch a URL, following redirects one hop at a time.
	 *
	 * WP_Http follows redirects itself, but it does not let the caller see or
	 * veto the destinations. Since this crawl exists to read *this site's*
	 * pages, every hop is resolved and checked against the site's own origins:
	 * a redirect that leaves the site is refused rather than followed, so a
	 * redirect inside the site cannot be used to reach anything else.
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args wp_remote_get() arguments.
	 * @return array|WP_Error
	 */
	protected function fetch( $url, array $args ) {
		$args['redirection'] = 0;
		$hops                = 0;

		while ( true ) {
			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			if ( ! in_array( (int) wp_remote_retrieve_response_code( $response ), array( 301, 302, 303, 307, 308 ), true ) ) {
				return $response;
			}
			$location = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
			++$hops;
			if ( '' === $location || $hops > 3 ) {
				return $response;
			}
			$next = $this->resolve_hop( $url, $location );
			if ( '' === $next ) {
				/* translators: %s: URL */
				$this->warn( sprintf( __( 'While scanning, %s redirected somewhere outside your site. That page was skipped.', 'auto-csp-manager' ), $url ) );
				$this->stats['incomplete'] = true;
				return $response;
			}
			$url = $next;
		}
	}

	/**
	 * Turn a Location header into an absolute URL on this site, or '' when it
	 * points anywhere else.
	 *
	 * @param string $from     URL that produced the redirect.
	 * @param string $location Location header value.
	 * @return string
	 */
	protected function resolve_hop( $from, $location ) {
		$base = wp_parse_url( $from );
		if ( empty( $base['scheme'] ) || empty( $base['host'] ) ) {
			return '';
		}
		$origin = $base['scheme'] . '://' . $base['host'] . ( empty( $base['port'] ) ? '' : ':' . (int) $base['port'] );

		// An absolute URL in any other scheme - mailto:, javascript:, data: -
		// is not a page to fetch. Resolving it as a relative path, which is
		// what happened before, turned "mailto:x@y" into a same-origin URL
		// with a nonsense path and then followed it.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) && ! preg_match( '#^https?://#i', $location ) ) {
			return '';
		}

		if ( 0 === strpos( $location, '//' ) ) {
			$location = $base['scheme'] . ':' . $location;
		} elseif ( 0 === strpos( $location, '/' ) ) {
			$location = $origin . $location;
		} elseif ( ! preg_match( '#^https?://#i', $location ) ) {
			$path     = isset( $base['path'] ) ? $base['path'] : '/';
			$location = $origin . rtrim( dirname( $path ), '/' ) . '/' . $location;
		}

		$parts = wp_parse_url( $location );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$target = $parts['scheme'] . '://' . strtolower( $parts['host'] ) . ( empty( $parts['port'] ) ? '' : ':' . (int) $parts['port'] );
		if ( ! in_array( ACSP_Reporter::normalise_origin( $target ), $this->self_origins, true ) ) {
			return '';
		}
		return $location;
	}

	/**
	 * Whether a request failed because of TLS verification.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	protected function is_tls_error( $error ) {
		$message = strtolower( $error->get_error_message() );
		foreach ( array( 'ssl', 'certificate', 'tls', 'cafile', 'self signed', 'self-signed' ) as $needle ) {
			if ( false !== strpos( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fetch one front-end page as a logged-out visitor and scan its HTML.
	 *
	 * @param string $url URL on this site.
	 * @return bool False when the page could not be fetched.
	 */
	public function crawl_url( $url ) {
		$this->stats['crawled'] = true;

		$args = array(
			'timeout'             => 10,
			'redirection'         => 0, // Redirects are followed by hand, so every hop can be checked.
			'cookies'             => array(),
			'limit_response_size' => 3145728,
			'sslverify'           => true,
			'user-agent'          => 'Mozilla/5.0 (compatible; AutoCSPManager/' . ACSP_VERSION . '; site scan)',
			'headers'             => array( 'Cache-Control' => 'no-cache' ),
		);

		$response = $this->fetch( $url, $args );

		if ( is_wp_error( $response ) && $this->is_tls_error( $response ) ) {
			// What this crawl finds becomes policy, so an unverified response is
			// not something to accept quietly: retry once so the scan still
			// works on a site with a self-signed or mismatched certificate, and
			// tell the owner, because a forged response could add a source.
			$args['sslverify'] = false;
			$retry             = $this->fetch( $url, $args );
			if ( ! is_wp_error( $retry ) ) {
				$this->warn( __( 'Your site\'s HTTPS certificate could not be verified, so the page was read without checking it. Fix the certificate and scan again: until then a scan result cannot be fully trusted.', 'auto-csp-manager' ) );
				$response = $retry;
			}
		}

		if ( is_wp_error( $response ) ) {
			/* translators: 1: URL, 2: error message */
			$this->warn( sprintf( __( 'Could not load %1$s: %2$s', 'auto-csp-manager' ), $url, $response->get_error_message() ) );
			$this->stats['incomplete'] = true;
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body || false === stripos( $body, '<html' ) ) {
			return true;
		}

		// Spot a policy that is being sent by something other than this plugin.
		$ours = array( ACSP_Policy::build_value( true, ACSP_Settings::active_report_url() ), ACSP_Policy::build_value( false, ACSP_Settings::active_report_url() ) );
		foreach ( array( 'content-security-policy', 'content-security-policy-report-only' ) as $name ) {
			foreach ( (array) wp_remote_retrieve_header( $response, $name ) as $value ) {
				if ( '' !== $value && ! in_array( $value, $ours, true ) ) {
					$this->warn( __( 'Your site already sends a Content-Security-Policy header from somewhere else (server configuration or another plugin). Browsers apply every policy they receive, so the stricter one always wins.', 'auto-csp-manager' ) );
				}
			}
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$qs   = wp_parse_url( $url, PHP_URL_QUERY );
		/* translators: %s: page path */
		$where = sprintf( __( 'Page %s', 'auto-csp-manager' ), ( $path ? $path : '/' ) . ( $qs ? '?' . $qs : '' ) );

		$this->extract_html( $body, $where );
		++$this->stats['pages'];
		return true;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Extraction
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Find sources in a piece of HTML (a full page, a fragment, a template
	 * file, or markup stored inside JSON).
	 *
	 * @param string $html  Markup.
	 * @param string $where Label describing where the markup came from.
	 */
	public function extract_html( $html, $where ) {
		if ( '' === $html || false === strpos( $html, '<' ) ) {
			return;
		}

		// Markup stored in JSON (page builders, block attributes) has escaped slashes and quotes.
		if ( false !== strpos( $html, '\\/' ) || false !== strpos( $html, '\\"' ) ) {
			$html = str_replace( array( '\\/', '\\"', "\\'" ), array( '/', '"', "'" ), $html );
		}

		$tags     = 'script|link|img|iframe|frame|source|video|audio|track|embed|object|form|base|input';
		$patterns = array(
			'/<(' . $tags . ')\b((?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+)>/i', // Quote-aware.
			'/<(' . $tags . ')\b([^>]*)>/i',                                 // Tolerant fallback for templates.
		);
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$this->handle_tag( strtolower( $m[1] ), self::parse_attrs( $m[2] ), $where );
				}
			}
		}

		// Inline scripts.
		if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script\s*>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				/*
				 * In a theme template the tag may contain a PHP fragment, as in
				 * a src attribute built by an echo. The attribute match stops at
				 * the closing PHP tag and the rest of the line then looks like a
				 * script body, which used to be recorded as inline script - and
				 * would have the next scan propose re-adding 'unsafe-inline' to
				 * a policy the owner had deliberately tightened.
				 */
				if ( false !== strpos( $m[1], '<?' ) || false !== strpos( $m[2], '<?' ) ) {
					continue;
				}
				$attrs = self::parse_attrs( $m[1] );
				$body  = trim( $m[2] );
				if ( isset( $attrs['src'] ) || '' === $body ) {
					continue;
				}
				$type = isset( $attrs['type'] ) ? strtolower( $attrs['type'] ) : '';
				if ( '' !== $type && false === strpos( $type, 'javascript' ) && 'module' !== $type ) {
					continue; // JSON, templates and other data blocks are not executed.
				}
				$this->note( 'script-src', "'unsafe-inline'", $where );
				$this->extract_loose_urls( $body, $where );
				if ( false !== strpos( $body, '_wpemojiSettings' ) ) {
					$this->note( 'img-src', 'https://s.w.org', __( 'WordPress emoji images', 'auto-csp-manager' ) );
				}
			}
		}

		// Inline event handlers such as onclick="...".
		if ( preg_match( '/<[a-z][a-z0-9]*\b[^>]*\son[a-z]{3,}\s*=\s*["\'][^>]*>/i', $html ) ) {
			$this->note( 'script-src', "'unsafe-inline'", $where );
		}

		// Inline styles.
		if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style\s*>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $css ) {
				if ( '' !== trim( $css ) ) {
					$this->note( 'style-src', "'unsafe-inline'", $where );
					$this->extract_css( $css, $where );
				}
			}
		}
		if ( preg_match_all( '/\sstyle\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $html, $matches, PREG_SET_ORDER ) ) {
			$this->note( 'style-src', "'unsafe-inline'", $where );
			foreach ( $matches as $m ) {
				$css = isset( $m[2] ) && '' !== $m[2] ? $m[2] : $m[1];
				if ( false !== stripos( $css, 'url(' ) ) {
					$this->extract_css( html_entity_decode( $css, ENT_QUOTES ), $where );
				}
			}
		}
	}

	/**
	 * Parse an attribute string into name => value.
	 *
	 * @param string $string Attribute part of a tag.
	 * @return array
	 */
	protected static function parse_attrs( $string ) {
		$attrs = array();
		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/', $string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$name = strtolower( $m[1] );
				if ( isset( $m[4] ) && '' !== $m[4] ) {
					$value = $m[4];
				} elseif ( isset( $m[3] ) && '' !== $m[3] ) {
					$value = $m[3];
				} else {
					$value = $m[2];
				}
				if ( ! isset( $attrs[ $name ] ) ) {
					$attrs[ $name ] = html_entity_decode( $value, ENT_QUOTES );
				}
			}
		}
		return $attrs;
	}

	/**
	 * Record the sources one tag loads.
	 *
	 * @param string $tag   Lower-case tag name.
	 * @param array  $attrs Attributes.
	 * @param string $where Label.
	 */
	protected function handle_tag( $tag, array $attrs, $where ) {
		$get = static function ( array $names ) use ( $attrs ) {
			$values = array();
			foreach ( $names as $name ) {
				if ( ! empty( $attrs[ $name ] ) ) {
					$values[] = $attrs[ $name ];
				}
			}
			return $values;
		};
		// Lazy-loading plugins move the real URL into data attributes.
		$src_names    = array( 'src', 'data-src', 'data-lazy-src', 'data-original', 'data-rocket-src', 'data-litespeed-src' );
		$srcset_names = array( 'srcset', 'data-srcset', 'data-lazy-srcset' );

		switch ( $tag ) {
			case 'script':
				foreach ( $get( $src_names ) as $url ) {
					$this->add( 'script-src', $url, $where );
				}
				break;

			case 'link':
				if ( empty( $attrs['href'] ) ) {
					break;
				}
				$rel = isset( $attrs['rel'] ) ? preg_split( '/\s+/', strtolower( $attrs['rel'] ) ) : array();
				$as  = isset( $attrs['as'] ) ? strtolower( $attrs['as'] ) : '';
				if ( in_array( 'stylesheet', $rel, true ) ) {
					$this->add( 'style-src', $attrs['href'], $where );
				} elseif ( in_array( 'modulepreload', $rel, true ) ) {
					$this->add( 'script-src', $attrs['href'], $where );
				} elseif ( array_intersect( array( 'preload', 'prefetch' ), $rel ) ) {
					$by_as     = array(
						'script' => 'script-src',
						'style'  => 'style-src',
						'font'   => 'font-src',
						'image'  => 'img-src',
						'fetch'  => 'connect-src',
						'video'  => 'media-src',
						'audio'  => 'media-src',
						'track'  => 'media-src',
					);
					$directive = isset( $by_as[ $as ] ) ? $by_as[ $as ] : self::classify_url( $attrs['href'] );
					if ( $directive ) {
						$this->add( $directive, $attrs['href'], $where );
					}
				} elseif ( array_intersect( array( 'icon', 'apple-touch-icon', 'apple-touch-icon-precomposed', 'mask-icon' ), $rel ) ) {
					$this->add( 'img-src', $attrs['href'], $where );
				} elseif ( in_array( 'manifest', $rel, true ) ) {
					$this->add( 'manifest-src', $attrs['href'], $where );
				}
				break;

			case 'img':
			case 'input':
				if ( 'input' === $tag && ( ! isset( $attrs['type'] ) || 'image' !== strtolower( $attrs['type'] ) ) ) {
					break;
				}
				foreach ( $get( $src_names ) as $url ) {
					$this->add( 'img-src', $url, $where );
				}
				foreach ( $get( $srcset_names ) as $srcset ) {
					$this->add_srcset( $srcset, $where );
				}
				break;

			case 'source':
				foreach ( $get( $src_names ) as $url ) {
					$this->add( 'media-src' === self::classify_url( $url ) ? 'media-src' : 'img-src', $url, $where );
				}
				foreach ( $get( $srcset_names ) as $srcset ) {
					$this->add_srcset( $srcset, $where );
				}
				break;

			case 'video':
				foreach ( $get( $src_names ) as $url ) {
					$this->add( 'media-src', $url, $where );
				}
				if ( ! empty( $attrs['poster'] ) ) {
					$this->add( 'img-src', $attrs['poster'], $where );
				}
				break;

			case 'audio':
			case 'track':
				foreach ( $get( $src_names ) as $url ) {
					$this->add( 'media-src', $url, $where );
				}
				break;

			case 'iframe':
			case 'frame':
				foreach ( $get( $src_names ) as $url ) {
					$this->add( 'frame-src', $url, $where );
				}
				break;

			case 'embed':
				foreach ( $get( array( 'src' ) ) as $url ) {
					$this->add( 'object-src', $url, $where );
				}
				break;

			case 'object':
				foreach ( $get( array( 'data' ) ) as $url ) {
					$this->add( 'object-src', $url, $where );
				}
				break;

			case 'form':
				foreach ( $get( array( 'action' ) ) as $url ) {
					$this->add( 'form-action', $url, $where );
				}
				break;

			case 'base':
				foreach ( $get( array( 'href' ) ) as $url ) {
					$this->add( 'base-uri', $url, $where );
				}
				break;
		}
	}

	/**
	 * Record every absolute URL in a srcset attribute.
	 *
	 * @param string $srcset Attribute value.
	 * @param string $where  Label.
	 */
	protected function add_srcset( $srcset, $where ) {
		if ( preg_match_all( '#(?:https?:)?//[^\s,]+#i', $srcset, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$this->add( 'img-src', $url, $where );
			}
		}
	}

	/**
	 * Find sources in CSS: @import and url().
	 *
	 * @param string $css   CSS text.
	 * @param string $where Label.
	 */
	public function extract_css( $css, $where ) {
		if ( false === stripos( $css, 'url(' ) && false === stripos( $css, '@import' ) ) {
			return;
		}

		if ( preg_match_all( '/@import\s+(?:url\(\s*)?["\']?([^"\')\s;]+)["\']?\s*\)?[^;]*;?/i', $css, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$this->add( 'style-src', $url, $where );
			}
			$css = preg_replace( '/@import\s+[^;]+;?/i', '', $css );
		}

		if ( preg_match_all( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', (string) $css, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = trim( $url );
				if ( 0 === stripos( $url, 'data:' ) ) {
					$is_font = (bool) preg_match( '#^data:(?:font/|application/(?:x-)?font|application/vnd\.ms-fontobject)#i', $url );
					$this->note( $is_font ? 'font-src' : 'img-src', 'data:', $where );
					continue;
				}
				$this->add( 'font-src' === self::classify_url( $url ) ? 'font-src' : 'img-src', $url, $where );
			}
		}
	}

	/**
	 * Find absolute URLs in free text (JavaScript, JSON, serialized options)
	 * and record the ones whose type can be told from the address.
	 *
	 * @param string $text  Text.
	 * @param string $where Label.
	 */
	public function extract_loose_urls( $text, $where ) {
		if ( false === strpos( $text, '//' ) ) {
			return;
		}
		if ( false !== strpos( $text, '\\/' ) ) {
			$text = str_replace( '\\/', '/', $text );
		}

		$host     = '[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?::\d+)?';
		$tail     = '(?:/[^\s"\'<>()\\\\`]*)?';
		$patterns = array(
			'#https?://' . $host . $tail . '#i',
			'#(?<=["\'(])//' . $host . $tail . '#i',
		);
		$skip     = array( 'github.com', 'w3.org', 'www.w3.org', 'wordpress.org', 'gnu.org', 'www.gnu.org', 'opensource.org', 'schema.org', 'npmjs.com', 'www.npmjs.com', 'developer.mozilla.org', 'stackoverflow.com', 'php.net', 'example.com', 'www.example.com', 'example.org' );

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $text, $matches ) ) {
				continue;
			}
			foreach ( array_unique( $matches[0] ) as $url ) {
				$directive = self::classify_url( $url );
				if ( ! $directive ) {
					continue;
				}
				$url_host = strtolower( (string) wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST ) );
				if ( in_array( $url_host, $skip, true ) || '.wordpress.org' === substr( $url_host, -14 ) ) {
					continue;
				}
				$this->add( $directive, $url, $where );
			}
		}
	}

	/**
	 * Guess what kind of resource a URL is from its address alone.
	 *
	 * @param string $url URL.
	 * @return string Directive, or '' when it cannot be told.
	 */
	public static function classify_url( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( 'fonts.googleapis.com' === $host && preg_match( '#^/(css|icon)#', $path ) ) {
			return 'style-src';
		}
		if ( preg_match( '#/js/?$#', $path ) ) {
			return 'script-src'; // e.g. googletagmanager.com/gtag/js, maps.googleapis.com/maps/api/js.
		}
		if ( ! preg_match( '/\.([a-z0-9]{2,5})$/', $path, $m ) ) {
			return '';
		}
		$types = array(
			'script-src' => array( 'js', 'mjs' ),
			'style-src'  => array( 'css' ),
			'font-src'   => array( 'woff', 'woff2', 'ttf', 'otf', 'eot' ),
			'img-src'    => array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp' ),
			'media-src'  => array( 'mp4', 'webm', 'ogg', 'ogv', 'mp3', 'wav', 'm4a', 'm4v', 'mov', 'flac', 'aac' ),
		);
		foreach ( $types as $directive => $extensions ) {
			if ( in_array( $m[1], $extensions, true ) ) {
				return $directive;
			}
		}
		return '';
	}

	/**
	 * Convert a URL to the origin that would be allowed in the policy.
	 *
	 * @param string $url URL as found.
	 * @return string Source expression, or '' for same-site, relative or unusable URLs.
	 */
	protected function url_to_source( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === stripos( $url, 'data:' ) ) {
			return 'data:';
		}
		if ( 0 === stripos( $url, 'blob:' ) ) {
			return 'blob:';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( ! preg_match( '#^(https?|wss?)://#i', $url ) ) {
			return ''; // Relative URL: same site.
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		$host = strtolower( $parts['host'] );
		$ok   = 'localhost' === $host
			|| preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z][a-z0-9-]*$/', $host )
			|| filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
		if ( ! $ok ) {
			return ''; // Template placeholders, PHP fragments and other non-addresses.
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( $this->site_https && 'http' === $scheme ) {
			$scheme = 'https'; // Plain HTTP would be blocked as mixed content anyway.
		}
		$origin = $scheme . '://' . $host . ( ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );

		// Only a full origin match is same-site. CSP 'self' matches the
		// document's scheme, host AND port, so a resource on another port of
		// the same hostname needs its own entry: treating it as same-site here
		// left it out of the policy and broke it under enforcement.
		if ( in_array( ACSP_Reporter::normalise_origin( $origin ), $this->self_origins, true ) ) {
			return '';
		}

		return ACSP_Policy::sanitize_source( $origin );
	}

	/**
	 * Record a URL under a directive.
	 *
	 * @param string $directive Directive.
	 * @param string $url       URL as found.
	 * @param string $where     Label.
	 */
	protected function add( $directive, $url, $where ) {
		$source = $this->url_to_source( $url );
		if ( '' === $source ) {
			return;
		}
		// Never widen script or frame rules with data: or blob: automatically.
		if ( in_array( $source, array( 'data:', 'blob:' ), true ) && ! in_array( $directive, array( 'img-src', 'font-src', 'media-src' ), true ) ) {
			return;
		}
		$this->note( $directive, $source, $where );
	}

	/**
	 * Record a ready-made source expression under a directive.
	 *
	 * @param string $directive Directive.
	 * @param string $source    Source expression.
	 * @param string $where     Label.
	 */
	protected function note( $directive, $source, $where ) {
		if ( ! isset( $this->findings[ $directive ][ $source ] ) ) {
			$this->findings[ $directive ][ $source ] = array();
		}
		if ( count( $this->findings[ $directive ][ $source ] ) < 3 && ! in_array( $where, $this->findings[ $directive ][ $source ], true ) ) {
			$this->findings[ $directive ][ $source ][] = $where;
		}
	}

	/**
	 * Record a warning for the scan summary.
	 *
	 * @param string $message Message.
	 */
	protected function warn( $message ) {
		if ( count( $this->stats['warnings'] ) < 10 && ! in_array( $message, $this->stats['warnings'], true ) ) {
			$this->stats['warnings'][] = $message;
		}
	}

	/**
	 * Record that part of the site was not scanned.
	 *
	 * The result of an incomplete scan must never be mistaken for a complete
	 * one: the difference decides whether it is safe to enforce a policy, and
	 * whether "apply everything automatically" should be offered at all.
	 *
	 * @param string $what Human description of what was skipped.
	 */
	protected function skip( $what ) {
		$this->stats['incomplete'] = true;
		if ( ! in_array( $what, $this->stats['skipped'], true ) && count( $this->stats['skipped'] ) < 10 ) {
			$this->stats['skipped'][] = $what;
		}
	}

	/**
	 * Whether a stored scan result covered the whole site.
	 *
	 * @param array $result Scan result.
	 * @return bool
	 */
	public static function is_complete( array $result ) {
		return empty( $result['stats']['incomplete'] );
	}

	/**
	 * Well-known services load more than the one file that is visible in the
	 * page. When their entry point is found, add what they are documented to
	 * need, so the first policy works without a round of violation reports.
	 */
	protected function apply_companions() {
		$rules = array(
			array( 'style-src', 'https://fonts.googleapis.com', array( 'font-src' => array( 'https://fonts.gstatic.com' ) ) ),
			array(
				'script-src',
				'https://www.googletagmanager.com',
				array(
					'img-src'     => array( 'https://www.googletagmanager.com', 'https://*.google-analytics.com' ),
					'connect-src' => array( 'https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com' ),
				),
			),
			array(
				'script-src',
				'https://www.google-analytics.com',
				array(
					'img-src'     => array( 'https://www.google-analytics.com' ),
					'connect-src' => array( 'https://www.google-analytics.com' ),
				),
			),
			array(
				'script-src',
				'https://www.google.com',
				array(
					'script-src' => array( 'https://www.gstatic.com' ),
					'frame-src'  => array( 'https://www.google.com' ),
				),
			),
			array(
				'script-src',
				'https://js.stripe.com',
				array(
					'frame-src'   => array( 'https://js.stripe.com', 'https://hooks.stripe.com' ),
					'connect-src' => array( 'https://api.stripe.com' ),
				),
			),
			array(
				'script-src',
				'https://connect.facebook.net',
				array(
					'img-src'     => array( 'https://www.facebook.com' ),
					'connect-src' => array( 'https://www.facebook.com' ),
					'frame-src'   => array( 'https://www.facebook.com' ),
				),
			),
			array(
				'script-src',
				'https://platform.twitter.com',
				array(
					'frame-src' => array( 'https://platform.twitter.com', 'https://syndication.twitter.com' ),
					'img-src'   => array( 'https://syndication.twitter.com', 'https://pbs.twimg.com' ),
				),
			),
			array( 'script-src', 'https://www.youtube.com', array( 'frame-src' => array( 'https://www.youtube.com', 'https://www.youtube-nocookie.com' ) ) ),
			array( 'script-src', 'https://challenges.cloudflare.com', array( 'frame-src' => array( 'https://challenges.cloudflare.com' ) ) ),
			array(
				'script-src',
				'https://js.hcaptcha.com',
				array(
					'frame-src'   => array( 'https://*.hcaptcha.com' ),
					'style-src'   => array( 'https://*.hcaptcha.com' ),
					'connect-src' => array( 'https://*.hcaptcha.com' ),
				),
			),
			array(
				'script-src',
				'https://use.typekit.net',
				array(
					'style-src' => array( 'https://use.typekit.net' ),
					'font-src'  => array( 'https://use.typekit.net' ),
					'img-src'   => array( 'https://p.typekit.net' ),
				),
			),
			array(
				'style-src',
				'https://use.typekit.net',
				array(
					'font-src' => array( 'https://use.typekit.net' ),
					'img-src'  => array( 'https://p.typekit.net' ),
				),
			),
		);

		/**
		 * Filter the companion rules: [ directive, source, [ directive => sources[] ] ].
		 *
		 * @param array $rules Rules.
		 */
		$rules = (array) apply_filters( 'acsp_companion_sources', $rules );

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || 3 !== count( $rule ) || ! isset( $this->findings[ $rule[0] ][ $rule[1] ] ) ) {
				continue;
			}
			$host = (string) wp_parse_url( $rule[1], PHP_URL_HOST );
			foreach ( (array) $rule[2] as $directive => $sources ) {
				foreach ( (array) $sources as $source ) {
					$source = ACSP_Policy::sanitize_source( $source );
					if ( '' !== $source ) {
						/* translators: %s: host name of a third-party service */
						$this->note( $directive, $source, sprintf( __( 'Needed by %s', 'auto-csp-manager' ), $host ) );
					}
				}
			}
		}
	}
}
