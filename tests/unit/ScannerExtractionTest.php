<?php
/**
 * What the scanner finds in markup, CSS and JavaScript — and, just as
 * important, what it refuses to turn into a policy source.
 *
 * @package AutoCSPManager
 */

use PHPUnit\Framework\TestCase;

/**
 * ACSP_Scanner extraction.
 */
class ScannerExtractionTest extends TestCase {

	/**
	 * Scanner under test.
	 *
	 * @var ACSP_Scanner
	 */
	protected $scanner;

	/**
	 * Fresh scanner per test.
	 */
	protected function setUp(): void {
		ACSP_Test_State::reset( 'https://example.com' );
		$this->scanner = new ACSP_Scanner();
	}

	/**
	 * Findings for one directive.
	 *
	 * @param string $directive Directive.
	 * @return array Sources.
	 */
	protected function sources( $directive ) {
		$state = $this->scanner->export_state();
		return isset( $state['findings'][ $directive ] ) ? array_keys( $state['findings'][ $directive ] ) : array();
	}

	/**
	 * Tags that load something.
	 */
	public function test_tags_map_to_directives() {
		$this->scanner->extract_html(
			'<script src="https://cdn.test/a.js"></script>
			<link rel="stylesheet" href="https://styles.test/a.css" />
			<link rel="preload" as="font" href="https://fonts.test/a.woff2" />
			<link rel="modulepreload" href="https://mod.test/a.js" />
			<link rel="manifest" href="https://app.test/manifest.json" />
			<link rel="icon" href="https://icons.test/a.png" />
			<img src="https://img.test/a.png" />
			<iframe src="https://frame.test/embed"></iframe>
			<video src="https://media.test/a.mp4" poster="https://poster.test/a.jpg"></video>
			<audio src="https://audio.test/a.mp3"></audio>
			<object data="https://obj.test/a.swf"></object>
			<form action="https://forms.test/submit"></form>
			<base href="https://base.test/" />',
			'test'
		);

		$this->assertSame( array( 'https://cdn.test', 'https://mod.test' ), $this->sources( 'script-src' ) );
		$this->assertSame( array( 'https://styles.test' ), $this->sources( 'style-src' ) );
		$this->assertSame( array( 'https://fonts.test' ), $this->sources( 'font-src' ) );
		$this->assertSame( array( 'https://app.test' ), $this->sources( 'manifest-src' ) );
		$this->assertSame( array( 'https://frame.test' ), $this->sources( 'frame-src' ) );
		$media = $this->sources( 'media-src' );
		sort( $media );
		$this->assertSame( array( 'https://audio.test', 'https://media.test' ), $media );
		$this->assertSame( array( 'https://obj.test' ), $this->sources( 'object-src' ) );
		$this->assertSame( array( 'https://forms.test' ), $this->sources( 'form-action' ) );
		$this->assertSame( array( 'https://base.test' ), $this->sources( 'base-uri' ) );

		$images = $this->sources( 'img-src' );
		$this->assertContains( 'https://img.test', $images );
		$this->assertContains( 'https://icons.test', $images );
		$this->assertContains( 'https://poster.test', $images );
	}

	/**
	 * Lazy-loading plugins hide the real URL in data attributes.
	 */
	public function test_lazy_loaded_and_srcset_images() {
		$this->scanner->extract_html(
			'<img src="data:image/gif;base64,R0lGOD" data-lazy-src="https://lazy.test/a.jpg"
				srcset="https://srcset.test/a-300.jpg 300w, https://srcset2.test/a-600.jpg 600w" />
			<script data-rocket-src="https://deferred.test/a.js"></script>',
			'test'
		);

		$images = $this->sources( 'img-src' );
		$this->assertContains( 'https://lazy.test', $images );
		$this->assertContains( 'https://srcset.test', $images );
		$this->assertContains( 'https://srcset2.test', $images );
		$this->assertContains( 'data:', $images );
		$this->assertSame( array( 'https://deferred.test' ), $this->sources( 'script-src' ) );
	}

	/**
	 * Inline code is what forces 'unsafe-inline', so it has to be detected.
	 */
	public function test_inline_code_is_detected() {
		$this->scanner->extract_html( '<script>var a = 1;</script>', 'test' );
		$this->assertContains( "'unsafe-inline'", $this->sources( 'script-src' ) );

		$this->scanner = new ACSP_Scanner();
		$this->scanner->extract_html( '<button onclick="go()">go</button>', 'test' );
		$this->assertContains( "'unsafe-inline'", $this->sources( 'script-src' ) );

		$this->scanner = new ACSP_Scanner();
		$this->scanner->extract_html( '<style>.a{color:red}</style>', 'test' );
		$this->assertContains( "'unsafe-inline'", $this->sources( 'style-src' ) );

		$this->scanner = new ACSP_Scanner();
		$this->scanner->extract_html( '<div style="color:red">x</div>', 'test' );
		$this->assertContains( "'unsafe-inline'", $this->sources( 'style-src' ) );
	}

	/**
	 * A JSON or template block is not executed, so it is not inline script.
	 */
	public function test_data_blocks_are_not_inline_script() {
		$this->scanner->extract_html( '<script type="application/json">{"a":1}</script>', 'test' );
		$this->assertNotContains( "'unsafe-inline'", $this->sources( 'script-src' ) );
	}

	/**
	 * CSS imports, url() and embedded fonts.
	 */
	public function test_css_extraction() {
		$this->scanner->extract_css(
			'@import url("https://imported.test/a.css");
			.a { background: url(https://bg.test/a.png); }
			@font-face { src: url(https://webfont.test/a.woff2) format("woff2"); }
			.b { background: url(data:image/png;base64,iVBOR); }
			@font-face { src: url(data:font/woff2;base64,d09GMg); }',
			'test'
		);

		$this->assertSame( array( 'https://imported.test' ), $this->sources( 'style-src' ) );
		$this->assertContains( 'https://webfont.test', $this->sources( 'font-src' ) );
		$this->assertContains( 'data:', $this->sources( 'font-src' ) );
		$this->assertContains( 'https://bg.test', $this->sources( 'img-src' ) );
		$this->assertContains( 'data:', $this->sources( 'img-src' ) );
	}

	/**
	 * URLs in free text are only taken when the address says what they are.
	 */
	public function test_loose_url_extraction() {
		$this->scanner->extract_loose_urls(
			'var s = "https://loose.test/widget.js";
			var css = "https://loose.test/widget.css";
			var img = "//protocol-relative.test/a.png";
			var doc = "https://github.com/some/repo";
			var api = "https://api.test/v1/items";',
			'test'
		);

		$this->assertSame( array( 'https://loose.test' ), $this->sources( 'script-src' ) );
		$this->assertSame( array( 'https://loose.test' ), $this->sources( 'style-src' ) );
		$this->assertSame( array( 'https://protocol-relative.test' ), $this->sources( 'img-src' ) );
		$this->assertSame( array(), $this->sources( 'connect-src' ), 'An address with no file type says nothing about what it is.' );

		foreach ( $this->sources( 'script-src' ) as $source ) {
			$this->assertStringNotContainsString( 'github.com', $source, 'Licence and documentation links are not loaded by the site.' );
		}
	}

	/**
	 * Markup stored inside JSON (page builders, block attributes).
	 */
	public function test_escaped_markup_in_json() {
		$this->scanner->extract_html( '{"html":"<script src=\\"https:\\/\\/builder.test\\/a.js\\"><\\/script>"}', 'test' );
		$this->assertSame( array( 'https://builder.test' ), $this->sources( 'script-src' ) );
	}

	/**
	 * Same-site resources need no entry; a different port does.
	 */
	public function test_same_origin_handling() {
		$this->scanner->extract_html(
			'<script src="https://example.com/wp-includes/js/a.js"></script>
			<script src="/wp-content/themes/x/a.js"></script>
			<script src="https://example.com:8443/app.js"></script>
			<script src="http://example.com/insecure.js"></script>',
			'test'
		);

		$this->assertSame(
			array( 'https://example.com:8443' ),
			$this->sources( 'script-src' ),
			"Only the other port needs listing: 'self' covers the rest, and plain http on an https site is upgraded."
		);
	}

	/**
	 * Template placeholders and PHP fragments are not addresses.
	 */
	public function test_unusable_urls_are_ignored() {
		$this->scanner->extract_html(
			'<script src="https://<?php echo $cdn; ?>/a.js"></script>
			<script src="https://{{host}}/a.js"></script>
			<script src="https://%s/a.js"></script>
			<img src="https://img.test/a.png" />',
			'test'
		);

		$this->assertSame( array(), $this->sources( 'script-src' ) );
		$this->assertSame( array( 'https://img.test' ), $this->sources( 'img-src' ) );
	}

	/**
	 * data: and blob: must never be added to script-src or frame-src by a
	 * scan: they are a script-injection sink, and a scan is not consent.
	 */
	public function test_data_and_blob_never_widen_script_rules() {
		$this->scanner->extract_html(
			'<script src="data:text/javascript,alert(1)"></script>
			<iframe src="data:text/html,<h1>x</h1>"></iframe>
			<img src="blob:https://example.com/abc" />',
			'test'
		);

		$this->assertSame( array(), $this->sources( 'script-src' ) );
		$this->assertSame( array(), $this->sources( 'frame-src' ) );
		$this->assertContains( 'blob:', $this->sources( 'img-src' ) );
	}

	/**
	 * Directive guessing from an address.
	 */
	public function test_classify_url() {
		$this->assertSame( 'script-src', ACSP_Scanner::classify_url( 'https://x.test/a.js' ) );
		$this->assertSame( 'script-src', ACSP_Scanner::classify_url( 'https://www.googletagmanager.com/gtag/js?id=X' ) );
		$this->assertSame( 'style-src', ACSP_Scanner::classify_url( 'https://fonts.googleapis.com/css2?family=X' ) );
		$this->assertSame( 'style-src', ACSP_Scanner::classify_url( 'https://x.test/a.css' ) );
		$this->assertSame( 'font-src', ACSP_Scanner::classify_url( 'https://x.test/a.woff2' ) );
		$this->assertSame( 'img-src', ACSP_Scanner::classify_url( 'https://x.test/a.svg' ) );
		$this->assertSame( 'media-src', ACSP_Scanner::classify_url( 'https://x.test/a.mp4' ) );
		$this->assertSame( '', ACSP_Scanner::classify_url( 'https://x.test/api/items' ) );
	}

	/**
	 * Known services need more than the file that appears in the page, and
	 * those extras only appear when the service itself was found.
	 */
	public function test_companion_sources() {
		ACSP_Test_State::$options['show_avatars'] = 0;
		$this->scanner->extract_html( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=X" />', 'test' );
		$this->scanner->finish();

		$this->assertContains( 'https://fonts.gstatic.com', $this->sources( 'font-src' ) );
		$this->assertNotContains( 'https://api.stripe.com', $this->sources( 'connect-src' ) );
	}

	/**
	 * A scan that skipped part of the site must say so, because that decides
	 * whether its findings can be applied without review.
	 */
	public function test_completeness_is_reported() {
		$complete = array( 'stats' => array( 'incomplete' => false ) );
		$partial  = array( 'stats' => array( 'incomplete' => true ) );

		$this->assertTrue( ACSP_Scanner::is_complete( $complete ) );
		$this->assertFalse( ACSP_Scanner::is_complete( $partial ) );
		$this->assertTrue( ACSP_Scanner::is_complete( array() ), 'An old stored result has no flag and is treated as complete.' );
	}

	/**
	 * A crawl was not run, so the result cannot claim to be complete.
	 */
	public function test_skipping_the_crawl_marks_the_scan_incomplete() {
		$method = new ReflectionMethod( ACSP_Scanner::class, 'skip' );
		$method->setAccessible( true );
		$method->invoke( $this->scanner, 'live pages' );

		$stats = $this->scanner->stats();
		$this->assertTrue( $stats['incomplete'] );
		$this->assertSame( array( 'live pages' ), $stats['skipped'] );
	}
}
