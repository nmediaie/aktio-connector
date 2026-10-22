<?php
use AktioConnector\Crawlers;
use AktioConnector\Settings;

class CrawlersTest extends WP_UnitTestCase {

	private $active = array();
	private $dirs   = array();

	public function set_up() {
		parent::set_up();
		$this->active = array();
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		$this->cleanup_files();
		Crawlers::clear_cache();
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		delete_option( Settings::OPTION );
		delete_option( 'wpseo' );
		update_option( 'blog_public', '1' );
		Crawlers::clear_cache();
		$this->cleanup_files();
		foreach ( $this->dirs as $dir ) {
			foreach ( glob( $dir . '/*' ) as $f ) {
				unlink( $f );
			}
			rmdir( $dir );
		}
		$this->dirs = array();
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active;
	}

	private function cleanup_files() {
		foreach ( array( 'robots.txt', 'llms.txt' ) as $f ) {
			if ( file_exists( ABSPATH . $f ) ) {
				unlink( ABSPATH . $f );
			}
		}
	}

	private function plugin( string $file, string $name ) {
		$dir = WP_PLUGIN_DIR . '/' . dirname( $file );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
			$this->dirs[] = $dir;
		}
		file_put_contents( WP_PLUGIN_DIR . '/' . $file, "<?php\n/*\nPlugin Name: $name\n*/\n" );
		$this->active[] = $file;
	}

	public function test_robots_appends_one_block_per_bot() {
		Settings::update( array( 'bots' => array( 'ClaudeBot' => 'block' ) ) );
		$out = Crawlers::robots( "User-agent: *\nDisallow: /wp-admin/\n", true );

		$this->assertStringStartsWith( "User-agent: *\nDisallow: /wp-admin/\n", $out );
		$this->assertStringContainsString( "# BEGIN Aktio Connector\n", $out );
		$this->assertStringContainsString( "# END Aktio Connector\n", $out );
		$this->assertStringContainsString( "User-agent: GPTBot\nAllow: /", $out );
		$this->assertStringContainsString( "User-agent: ClaudeBot\nDisallow: /", $out );
		foreach ( Settings::BOTS as $bot ) {
			$this->assertSame( 1, substr_count( $out, 'User-agent: ' . $bot . "\n" ), $bot );
		}
		$begin = strpos( $out, '# BEGIN Aktio Connector' );
		$this->assertGreaterThan( $begin, strpos( $out, 'User-agent: GPTBot' ) );
		$this->assertLessThan( strpos( $out, '# END Aktio Connector' ), strpos( $out, 'User-agent: Applebot-Extended' ) );
	}

	public function test_robots_is_idempotent() {
		$once = Crawlers::robots( "User-agent: *\n", true );
		$this->assertSame( $once, Crawlers::robots( $once, true ) );
	}

	public function test_robots_untouched_when_site_not_public() {
		$this->assertSame( "User-agent: *\nDisallow: /\n", Crawlers::robots( "User-agent: *\nDisallow: /\n", false ) );
	}

	public function test_robots_appends_when_seo_plugin_provides_robots_via_filter() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		$this->assertFalse( \AktioConnector\Detector::owns( 'robots' ) );

		$base = "User-agent: *\nDisallow: /private/\n";
		$out  = apply_filters( 'robots_txt', $base, true );
		// Reszta wyjścia (też dopiski innych wtyczek, np. WooCommerce) zostaje przed blokiem Aktio.
		$this->assertStringContainsString( 'Disallow: /private/', $out );
		$this->assertLessThan( strpos( $out, '# BEGIN Aktio Connector' ), strpos( $out, 'Disallow: /private/' ) );
		$this->assertStringContainsString( 'User-agent: GPTBot', $out );
		$this->assertStringNotContainsString( 'Aktio Connector', apply_filters( 'robots_txt', $base, false ) );
	}

	public function test_robots_nothing_when_physical_file() {
		file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\n" );
		$this->assertSame( "User-agent: *\n", Crawlers::robots( "User-agent: *\n", true ) );
	}

	public function test_llms_lists_site_description_pages_and_products() {
		Settings::update( array( 'company' => array( 'name' => 'Sklep Testowy' ) ) );
		update_option( 'blogdescription', 'Najlepszy <b>sklep</b>' );
		$about = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'O nas [PL]' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Szkic strony', 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Tajna', 'post_password' => 'x' ) );
		for ( $i = 1; $i <= 12; $i++ ) {
			self::factory()->post->create( array( 'post_title' => "Wpis $i", 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 1000 + $i ) ) );
		}
		$this->simple( 'Kubek', '19.9' );
		$this->simple( 'Ukryty', '5', 'hidden' );
		$this->simple( 'Szkic', '5', 'visible', 'draft' );
		$this->simple( 'Bez ceny', '', 'visible' );

		$out = Crawlers::llms();

		$this->assertStringStartsWith( "# Sklep Testowy\n", $out );
		$this->assertStringContainsString( "> Najlepszy sklep\n", $out );
		$this->assertStringContainsString( "## Strony\n", $out );
		$this->assertStringContainsString( '- [O nas (PL)](' . get_permalink( $about ) . ')', $out );
		$this->assertStringNotContainsString( 'Szkic strony', $out );
		$this->assertStringNotContainsString( 'Tajna', $out );
		$this->assertStringContainsString( "## Wpisy\n", $out );
		$this->assertSame( 10, preg_match_all( '/^- \[Wpis \d+\]/m', $out ) );
		$this->assertStringContainsString( '- [Wpis 12](', $out );
		$this->assertStringNotContainsString( '[Wpis 1](', $out );
		$this->assertStringContainsString( "## Produkty\n", $out );
		$this->assertMatchesRegularExpression( '/^- \[Kubek\]\(http[^)]+\): 19\.90 [A-Z]{3}$/m', $out );
		$this->assertMatchesRegularExpression( '/^- \[Bez ceny\]\([^)]+\)$/m', $out );
		$this->assertStringNotContainsString( 'Ukryty', $out );
		$this->assertStringNotContainsString( '[Szkic]', $out );
		$this->assertStringNotContainsString( '<', $out );
	}

	public function test_llms_caches_and_clears_on_save_post_and_settings_update() {
		$id = self::factory()->post->create( array( 'post_title' => 'Pierwszy' ) );
		$this->assertStringContainsString( 'Pierwszy', Crawlers::llms() );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => 'Zmieniony' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$this->assertStringContainsString( 'Pierwszy', Crawlers::llms(), 'z cache' );

		wp_update_post( array( 'ID' => $id, 'post_title' => 'Drugi' ) );
		$this->assertStringContainsString( 'Drugi', Crawlers::llms() );

		Settings::update( array( 'company' => array( 'name' => 'Nowa Firma' ) ) );
		$this->assertStringStartsWith( '# Nowa Firma', Crawlers::llms() );
	}

	public function test_llms_served_as_text_plain_at_root() {
		$this->assertContains( '^llms\.txt$', array_keys( $this->rules() ) );
		$res = Crawlers::llms_response();
		$this->assertSame( 200, $res['status'] );
		$this->assertSame( 'text/plain; charset=utf-8', $res['headers']['Content-Type'] );
		$this->assertStringStartsWith( '# ', $res['body'] );
		$this->assertContains( Crawlers::QUERY_VAR, apply_filters( 'query_vars', array() ) );
	}

	public function test_llms_404_when_provided_elsewhere() {
		$this->plugin( 'website-llms-txt/anything.php', 'Website LLMs.txt' );
		$this->assertSame( 404, Crawlers::llms_response()['status'] );
	}

	public function test_llms_404_when_site_not_public() {
		update_option( 'blog_public', '0' );
		$this->assertSame( 404, Crawlers::llms_response()['status'] );
	}

	private function rules(): array {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		Crawlers::add_rewrite();
		flush_rewrite_rules();
		return (array) $wp_rewrite->wp_rewrite_rules();
	}

	private function simple( string $name, string $price, string $visibility = 'visible', string $status = 'publish' ) {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( $status );
		$p->set_regular_price( $price );
		$p->set_catalog_visibility( $visibility );
		$p->save();
		return $p;
	}

	public function test_llms_cache_cleared_on_delete_and_site_title_changes() {
		$id = self::factory()->post->create();
		set_transient( Crawlers::TRANSIENT, 'x', HOUR_IN_SECONDS );
		wp_delete_post( $id, true );
		$this->assertFalse( get_transient( Crawlers::TRANSIENT ), 'deleted_post' );
		set_transient( Crawlers::TRANSIENT, 'x', HOUR_IN_SECONDS );
		update_option( 'blogdescription', 'Opis ' . wp_generate_password( 6, false ) );
		$this->assertFalse( get_transient( Crawlers::TRANSIENT ), 'blogdescription' );
		set_transient( Crawlers::TRANSIENT, 'x', HOUR_IN_SECONDS );
		update_option( 'blogname', 'Nowa nazwa ' . wp_generate_password( 6, false ) );
		$this->assertFalse( get_transient( Crawlers::TRANSIENT ) );
	}
}
