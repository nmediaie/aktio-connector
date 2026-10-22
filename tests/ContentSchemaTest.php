<?php
use AktioConnector\Schema\Graph;
use AktioConnector\Settings;

class ContentSchemaTest extends WP_UnitTestCase {

	private $seo_active = false;

	public function set_up() {
		parent::set_up();
		$this->seo_active = false;
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		delete_option( Settings::OPTION );
		$dir = WP_PLUGIN_DIR . '/wordpress-seo';
		if ( is_dir( $dir ) ) {
			unlink( $dir . '/wp-seo.php' );
			rmdir( $dir );
		}
		parent::tear_down();
	}

	public function filter_active() {
		return $this->seo_active ? array( 'wordpress-seo/wp-seo.php' ) : array();
	}

	private function graph(): array {
		$json = Graph::render();
		$this->assertMatchesRegularExpression( '#^<script type="application/ld\+json">(.*)</script>\s*$#s', $json );
		preg_match( '#^<script type="application/ld\+json">(.*)</script>\s*$#s', $json, $m );
		return json_decode( $m[1], true )['@graph'];
	}

	private function by_type( array $graph, string $type ): ?array {
		foreach ( $graph as $node ) {
			if ( $type === $node['@type'] ) {
				return $node;
			}
		}
		return null;
	}

	public function test_front_page_has_organization_and_website_with_search_action() {
		$this->go_to( home_url( '/' ) );
		$g   = $this->graph();
		$org = $this->by_type( $g, 'Organization' );
		$web = $this->by_type( $g, 'WebSite' );
		$this->assertSame( home_url( '/#organization' ), $org['@id'] );
		$this->assertSame( home_url( '/#website' ), $web['@id'] );
		$this->assertSame( 'SearchAction', $web['potentialAction']['@type'] );
		$this->assertStringContainsString( '{search_term_string}', $web['potentialAction']['target']['urlTemplate'] );
		$this->assertSame( home_url( '/#organization' ), $web['publisher']['@id'] );
	}

	public function test_single_post_has_article_with_author_dates_and_publisher_ref() {
		$author = self::factory()->user->create( array( 'display_name' => 'Anna Autor' ) );
		$id     = self::factory()->post->create(
			array(
				'post_title'   => 'Wpis testowy',
				'post_author'  => $author,
				'post_content' => '<p>Treść <b>wpisu</b>.</p>',
				'post_excerpt' => '',
			)
		);
		$this->go_to( get_permalink( $id ) );
		$g       = $this->graph();
		$article = $this->by_type( $g, 'Article' );
		$this->assertSame( get_permalink( $id ) . '#article', $article['@id'] );
		$this->assertSame( home_url( '/#organization' ), $article['publisher']['@id'] );
		$this->assertSame( 'Anna Autor', $article['author']['name'] );
		$this->assertNotEmpty( $article['datePublished'] );
		$this->assertNotEmpty( $article['dateModified'] );
		$this->assertSame( 'Treść wpisu.', $article['description'] );
		$this->assertNotNull( $this->by_type( $g, 'WebPage' ) );
		$this->assertNotNull( $this->by_type( $g, 'Organization' ) );
	}

	public function test_page_has_webpage_and_breadcrumb_with_parent_pages() {
		$parent = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Rodzic',
			)
		);
		$child  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Dziecko',
				'post_parent' => $parent,
			)
		);
		$this->go_to( get_permalink( $child ) );
		$g = $this->graph();
		$this->assertNull( $this->by_type( $g, 'Article' ) );
		$page = $this->by_type( $g, 'WebPage' );
		$this->assertSame( get_permalink( $child ) . '#webpage', $page['@id'] );
		$this->assertSame( get_permalink( $child ) . '#breadcrumb', $page['breadcrumb']['@id'] );
		$crumbs = $this->by_type( $g, 'BreadcrumbList' );
		$this->assertSame( get_permalink( $child ) . '#breadcrumb', $crumbs['@id'] );
		$this->assertSame(
			array( get_bloginfo( 'name' ), 'Rodzic', 'Dziecko' ),
			array_column( $crumbs['itemListElement'], 'name' )
		);
		$this->assertSame( array( 1, 2, 3 ), array_column( $crumbs['itemListElement'], 'position' ) );
	}

	public function test_local_business_type_emits_address_and_phone() {
		Settings::update(
			array(
				'company' => array(
					'name'    => 'Kawiarnia',
					'type'    => 'LocalBusiness',
					'phone'   => '+48 123 456 789',
					'address' => array(
						'street'      => 'Rynek 1',
						'city'        => 'Wałbrzych',
						'postal_code' => '58-300',
						'country'     => 'PL',
					),
				),
			)
		);
		$this->go_to( home_url( '/' ) );
		$biz = $this->by_type( $this->graph(), 'LocalBusiness' );
		$this->assertSame( 'Kawiarnia', $biz['name'] );
		$this->assertSame( '+48 123 456 789', $biz['telephone'] );
		$this->assertSame( 'PostalAddress', $biz['address']['@type'] );
		$this->assertSame( 'Wałbrzych', $biz['address']['addressLocality'] );
		$this->assertSame( '58-300', $biz['address']['postalCode'] );
	}

	public function test_nothing_rendered_when_seo_plugin_owns_content_schema() {
		$dir = WP_PLUGIN_DIR . '/wordpress-seo';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/wp-seo.php', "<?php\n/*\nPlugin Name: Yoast SEO\n*/\n" );
		$this->seo_active = true;
		$this->go_to( home_url( '/' ) );
		$this->assertSame( '', Graph::render() );
	}

	public function test_other_modules_still_render_when_seo_plugin_owns_content_schema() {
		$dir = WP_PLUGIN_DIR . '/wordpress-seo';
		mkdir( $dir, 0777, true );
		file_put_contents( $dir . '/wp-seo.php', "<?php\n/*\nPlugin Name: Yoast SEO\n*/\n" );
		$this->seo_active = true;
		$cb = static function ( $nodes ) {
			$nodes[] = array( '@type' => 'Product', '@id' => 'x#product' );
			return $nodes;
		};
		add_filter( Graph::FILTER, $cb );
		$this->go_to( home_url( '/' ) );
		$g = $this->graph();
		remove_filter( Graph::FILTER, $cb );
		$this->assertCount( 1, $g );
		$this->assertSame( 'Product', $g[0]['@type'] );
	}

	public function test_script_tag_cannot_be_closed_by_title() {
		$title = 'Hello </script><script>alert(1)</script> „cytat” 🌿';
		// Administrator ma unfiltered_html, więc tytuł zapisze się bez wycięcia znaczników przez kses.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = self::factory()->post->create( array( 'post_title' => $title ) );
		$this->assertSame( $title, get_post( $id )->post_title );
		$this->go_to( get_permalink( $id ) );
		$out = Graph::render();
		$this->assertSame( 1, substr_count( $out, '</script>' ) );
		$this->assertSame( 1, substr_count( $out, '<script' ) );
		preg_match( '#^<script type="application/ld\+json">(.*)</script>\s*$#s', $out, $m );
		$data    = json_decode( $m[1], true );
		$article = $this->by_type( $data['@graph'], 'Article' );
		$this->assertSame( $title, $article['headline'] );
		$this->assertStringContainsString( '🌿', $out );
	}
}
