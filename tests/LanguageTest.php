<?php
use AktioConnector\Crawlers;
use AktioConnector\Language;
use AktioConnector\Rest;
use AktioConnector\Schema\Graph;
use AktioConnector\Seo\Head;
use AktioConnector\Settings;

class LanguageTest extends WP_UnitTestCase {

	private $active = array();

	public function set_up() {
		parent::set_up();
		$this->active = array();
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		add_filter( 'locale', array( $this, 'polish_locale' ) );
		Crawlers::clear_cache();
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		remove_filter( 'locale', array( $this, 'polish_locale' ) );
		delete_option( Settings::OPTION );
		delete_option( 'GTranslate' );
		set_current_screen( 'front' );
		Crawlers::clear_cache();
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active;
	}

	public function polish_locale() {
		return 'pl_PL';
	}

	public function test_current_follows_setting_and_defaults_to_site_locale() {
		$this->assertSame( 'pl-PL', Language::current() );
		Settings::update( array( 'content_language' => 'en-IE' ) );
		$this->assertSame( 'en-IE', Language::current() );
	}

	public function test_language_attributes_on_front_use_content_language_not_panel_locale() {
		Settings::update( array( 'content_language' => 'en-IE' ) );
		$this->assertStringContainsString( 'lang="en-IE"', get_language_attributes() );
		$this->assertStringNotContainsString( 'pl-PL', get_language_attributes() );
	}

	public function test_language_attributes_unchanged_in_admin() {
		Settings::update( array( 'content_language' => 'en-IE' ) );
		set_current_screen( 'dashboard' );
		$this->assertStringContainsString( 'lang="pl-PL"', get_language_attributes() );
	}

	public function test_og_locale_uses_content_language() {
		Settings::update( array( 'content_language' => 'en-IE' ) );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'en_IE', Head::og()['og:locale'] );
	}

	public function test_in_language_in_website_and_webpage() {
		Settings::update( array( 'content_language' => 'en-IE' ) );
		$this->go_to( home_url( '/' ) );
		preg_match( '#^<script type="application/ld\+json">(.*)</script>\s*$#s', Graph::render(), $m );
		$graph = json_decode( $m[1], true )['@graph'];
		$found = array();
		foreach ( $graph as $node ) {
			if ( in_array( $node['@type'], array( 'WebSite', 'WebPage' ), true ) ) {
				$found[ $node['@type'] ] = $node['inLanguage'] ?? null;
			}
		}
		$this->assertSame(
			array(
				'WebSite' => 'en-IE',
				'WebPage' => 'en-IE',
			),
			$found
		);
	}

	public function test_llms_headings_follow_content_language() {
		Settings::update( array( 'content_language' => 'en-IE' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'About' ) );
		$this->assertStringContainsString( "## Pages\n", Crawlers::llms() );
		Settings::update( array( 'content_language' => 'pl' ) );
		$this->assertStringContainsString( "## Strony\n", Crawlers::llms() );
	}

	private function posts( string $text ) {
		for ( $i = 0; $i < 5; $i++ ) {
			self::factory()->post->create( array( 'post_title' => "T$i", 'post_content' => $text ) );
		}
	}

	private function polish_text(): string {
		return 'To jest bardzo dobry sklep, który oferuje wszystko dla was i nie tylko. Jest to nasza strona, na której można kupić produkty od nas.';
	}

	public function test_warning_detects_polish_content_with_english_setting() {
		$this->posts( $this->polish_text() );
		Settings::update( array( 'content_language' => 'en' ) );
		$this->assertSame(
			array(
				'expected' => 'en',
				'detected' => 'pl',
			),
			Language::warning()
		);
	}

	public function test_warning_null_when_matching_or_no_content() {
		$this->assertNull( Language::warning() );
		$this->posts( $this->polish_text() );
		Settings::update( array( 'content_language' => 'pl' ) );
		$this->assertNull( Language::warning() );
	}

	public function test_warning_detects_english_with_polish_setting() {
		$this->posts( 'This is the best shop for you and all of the people that want to buy with us, and it is where we have what you need.' );
		$this->assertSame(
			array(
				'expected' => 'pl-PL',
				'detected' => 'en',
			),
			Language::warning()
		);
	}

	public function test_multilingual_plugin_keeps_lang_and_is_reported() {
		$this->active = array( 'polylang/polylang.php' );
		Settings::update( array( 'content_language' => 'en-IE' ) );
		$this->assertSame( 'Polylang', Language::multilingual_plugin() );
		$this->assertStringContainsString( 'lang="pl-PL"', get_language_attributes() );
		$this->assertNull( Language::warning() );
	}

	public function test_no_multilingual_plugin_by_default() {
		$this->assertNull( Language::multilingual_plugin() );
	}

	public function test_gtranslate_js_status_warning() {
		$this->active = array( 'gtranslate/gtranslate.php' );
		update_option( 'GTranslate', array( 'url_structure' => 'none' ) );
		$this->assertNull( Language::multilingual_plugin() );
		$this->assertSame( 'gtranslate_js', Rest::language_warning() );

		update_option( 'GTranslate', array( 'url_structure' => 'sub_directory' ) );
		$this->assertSame( 'GTranslate', Language::multilingual_plugin() );
		$this->assertNull( Rest::language_warning() );
	}

	public function test_status_contains_language_keys() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/status' ) )->get_data();
		$this->assertSame( 'pl-PL', $data['content_language'] );
		$this->assertArrayHasKey( 'language_warning', $data );
		$this->assertNull( $data['language_warning'] );
	}
}
