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

	private function brand_company( string $type ): array {
		return array(
			'name'          => 'Kawiarnia',
			'type'          => $type,
			'description'   => 'Kawa palona na miejscu',
			'founding_date' => '2015-05-01',
			'area_served'   => 'Dolny Śląsk',
			'contact_point' => array(
				'telephone'    => '+48 111 222 333',
				'email'        => 'biuro@example.com',
				'contact_type' => 'customer service',
				'languages'    => array( 'pl', 'en' ),
			),
			'opening_hours' => array(
				array( 'days' => array( 'Mo', 'Tu', 'Fr' ), 'opens' => '09:00', 'closes' => '17:00' ),
				array( 'days' => array( 'Su' ), 'opens' => '10:00', 'closes' => '14:00' ),
			),
			'geo'           => array( 'lat' => 50.8, 'lng' => 16.28 ),
		);
	}

	public function test_local_business_emits_extended_company_fields() {
		Settings::update( array( 'company' => $this->brand_company( 'LocalBusiness' ) ) );
		$this->go_to( home_url( '/' ) );
		$biz = $this->by_type( $this->graph(), 'LocalBusiness' );
		$this->assertSame( 'Kawa palona na miejscu', $biz['description'] );
		$this->assertSame( '2015-05-01', $biz['foundingDate'] );
		$this->assertSame( 'Dolny Śląsk', $biz['areaServed'] );
		$this->assertSame(
			array(
				'@type'             => 'ContactPoint',
				'contactType'       => 'customer service',
				'telephone'         => '+48 111 222 333',
				'email'             => 'biuro@example.com',
				'availableLanguage' => array( 'pl', 'en' ),
			),
			$biz['contactPoint']
		);
		$this->assertSame(
			array(
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Monday', 'Tuesday', 'Friday' ),
					'opens'     => '09:00',
					'closes'    => '17:00',
				),
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Sunday' ),
					'opens'     => '10:00',
					'closes'    => '14:00',
				),
			),
			$biz['openingHoursSpecification']
		);
		$this->assertSame( 'GeoCoordinates', $biz['geo']['@type'] );
		$this->assertSame( 50.8, $biz['geo']['latitude'] );
		$this->assertSame( 16.28, $biz['geo']['longitude'] );
	}

	public function test_organization_skips_local_only_fields_but_keeps_common_ones() {
		Settings::update( array( 'company' => $this->brand_company( 'Organization' ) ) );
		$this->go_to( home_url( '/' ) );
		$org = $this->by_type( $this->graph(), 'Organization' );
		$this->assertSame( 'Kawa palona na miejscu', $org['description'] );
		$this->assertSame( '2015-05-01', $org['foundingDate'] );
		$this->assertSame( 'ContactPoint', $org['contactPoint']['@type'] );
		$this->assertArrayNotHasKey( 'openingHoursSpecification', $org );
		$this->assertArrayNotHasKey( 'geo', $org );
	}

	public function test_empty_extended_fields_are_omitted() {
		Settings::update( array( 'company' => array( 'type' => 'LocalBusiness', 'name' => 'Puste' ) ) );
		$this->go_to( home_url( '/' ) );
		$biz = $this->by_type( $this->graph(), 'LocalBusiness' );
		foreach ( array( 'description', 'foundingDate', 'contactPoint', 'openingHoursSpecification', 'geo', 'areaServed' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $biz, $key );
		}
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

	public function test_title_with_quotes_and_emoji_round_trips() {
		$title = 'Wpis „cytat” 🌿';
		$id    = self::factory()->post->create( array( 'post_title' => $title ) );
		$this->go_to( get_permalink( $id ) );
		$article = $this->by_type( $this->graph(), 'Article' );
		$this->assertSame( $title, $article['headline'] );
		$this->assertStringContainsString( '🌿', Graph::render() );
	}

	public function test_script_tag_cannot_be_closed_by_node_value() {
		$raw = 'Hello </script><script>alert(1)</script>';
		$cb  = static function ( $nodes ) use ( $raw ) {
			$nodes[] = array( '@type' => 'Thing', 'name' => $raw );
			return $nodes;
		};
		add_filter( Graph::FILTER, $cb );
		$this->go_to( home_url( '/' ) );
		$out = Graph::render();
		remove_filter( Graph::FILTER, $cb );
		$this->assertSame( 1, substr_count( $out, '</script>' ) );
		$this->assertSame( 1, substr_count( $out, '<script' ) );
		preg_match( '#^<script type="application/ld\+json">(.*)</script>\s*$#s', $out, $m );
		$thing = $this->by_type( json_decode( $m[1], true )['@graph'], 'Thing' );
		$this->assertSame( $raw, $thing['name'] );
	}

	public function test_password_protected_post_content_not_leaked() {
		$id = self::factory()->post->create(
			array(
				'post_title'    => 'Chronione',
				'post_content'  => 'secret',
				'post_excerpt'  => 'secret',
				'post_password' => 'haslo',
			)
		);
		$this->go_to( get_permalink( $id ) );
		$this->assertStringNotContainsString( 'secret', Graph::render() );
	}
}
