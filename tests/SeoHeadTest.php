<?php
use AktioConnector\Seo\Head;
use AktioConnector\Settings;

class SeoHeadTest extends WP_UnitTestCase {

	private $active  = array();
	private $feature = null;

	public function set_up() {
		parent::set_up();
		$this->active  = array();
		$this->feature = null;
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		add_filter( 'aktio_connector_seo_map', array( $this, 'filter_map' ) );
		update_option( 'blog_public', 1 );
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		remove_filter( 'aktio_connector_seo_map', array( $this, 'filter_map' ) );
		delete_option( Settings::OPTION );
		remove_theme_mod( 'custom_logo' );
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active;
	}

	public function filter_map( $map ) {
		if ( null !== $this->feature ) {
			$map[] = array(
				'name'     => 'Fake SEO',
				'files'    => array( 'fake-seo/fake.php' ),
				'provides' => array( $this->feature => true ),
			);
		}
		return $map;
	}

	private function provide( string $feature ): void {
		$this->feature  = $feature;
		$this->active[] = 'fake-seo/fake.php';
	}

	private function head(): string {
		ob_start();
		Head::print_head();
		return (string) ob_get_clean();
	}

	private function post( array $args = array() ): int {
		return self::factory()->post->create(
			$args + array(
				'post_title'   => 'Wpis testowy',
				'post_content' => 'Treść wpisu',
				'post_status'  => 'publish',
				'post_excerpt' => '',
				'post_author'  => $this->author(),
			)
		);
	}

	private function wc_pages(): void {
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			update_option( 'woocommerce_' . $page . '_page_id', self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => $page ) ) );
		}
	}

	private function author(): int {
		static $id = 0;
		if ( ! $id || ! get_userdata( $id ) ) {
			$id = self::factory()->user->create( array( 'role' => 'author' ) );
		}
		return $id;
	}

	private function image( int $parent = 0 ): int {
		return self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent );
	}

	private function product( array $props = array() ): \WC_Product_Simple {
		$p = new \WC_Product_Simple();
		$p->set_name( 'Kubek' );
		$p->set_status( 'publish' );
		$p->set_regular_price( $props['price'] ?? '19.9' );
		$p->set_stock_status( $props['stock'] ?? 'instock' );
		if ( isset( $props['short'] ) ) {
			$p->set_short_description( $props['short'] );
		}
		if ( isset( $props['description'] ) ) {
			$p->set_description( $props['description'] );
		}
		$p->save();
		return $p;
	}

	public function test_description_order() {
		// Zajawka przed treścią.
		$id = $this->post( array( 'post_excerpt' => 'Zajawka wpisu', 'post_content' => 'Długa treść wpisu' ) );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( 'Zajawka wpisu', Head::description() );

		// Bez zajawki — początek treści.
		$id = $this->post( array( 'post_content' => 'Sama treść wpisu' ) );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( 'Sama treść wpisu', Head::description() );

		// Własny opis ma pierwszeństwo (gdy istnieje pole Opis SEO — P3b).
		if ( class_exists( '\AktioConnector\Seo\Fields' ) ) {
			update_post_meta( $id, '_aktio_seo_description', 'Własny opis' );
			$this->assertSame( 'Własny opis', Head::description() );
		}

		// Produkt: krótki opis przed zajawką i treścią.
		$p = $this->product( array( 'short' => 'Krótki opis kubka', 'description' => 'Pełny opis kubka' ) );
		$this->go_to( get_permalink( $p->get_id() ) );
		$this->assertSame( 'Krótki opis kubka', Head::description() );

		// Strona główna: opis z ustawień → opis witryny.
		update_option( 'blogdescription', 'Opis witryny' );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'Opis witryny', Head::description() );
		Settings::update( array( 'seo' => array( 'home_description' => 'Opis z ustawień' ) ) );
		$this->assertSame( 'Opis z ustawień', Head::description() );

		// Opis firmy: po opisie z ustawień, przed opisem witryny.
		Settings::update( array( 'seo' => array( 'home_description' => '' ), 'company' => array( 'description' => 'Opis firmy' ) ) );
		$this->assertSame( 'Opis firmy', Head::description() );
		Settings::update( array( 'seo' => array( 'home_description' => 'Opis z ustawień' ) ) );
		$this->assertSame( 'Opis z ustawień', Head::description() );
		Settings::update( array( 'seo' => array( 'home_description' => '' ), 'company' => array( 'description' => '' ) ) );
		$this->assertSame( 'Opis witryny', Head::description() );

		// Kategoria: opis terminu.
		$term = self::factory()->category->create_and_get( array( 'description' => 'Opis kategorii' ) );
		$this->go_to( get_term_link( $term ) );
		$this->assertSame( 'Opis kategorii', Head::description() );
	}

	public function test_description_trimmed_to_155_on_word_boundary_without_html_shortcodes() {
		$content = '<p>Pierwsze <strong>zdanie</strong> [gallery ids="1"] ' . str_repeat( 'słowo ', 60 ) . '</p>';
		$id      = $this->post( array( 'post_content' => $content ) );
		$this->go_to( get_permalink( $id ) );
		$d = Head::description();
		$this->assertLessThanOrEqual( 155, mb_strlen( $d ) );
		$this->assertStringStartsWith( 'Pierwsze zdanie słowo', $d );
		$this->assertStringNotContainsString( '<', $d );
		$this->assertStringNotContainsString( '[gallery', $d );
		$this->assertStringEndsWith( 'słowo', $d );
	}

	public function test_og_tags_for_post_product_home() {
		$id = $this->post();
		$this->go_to( get_permalink( $id ) );
		$og = Head::og();
		$this->assertSame( 'article', $og['og:type'] );
		$this->assertSame( 'Wpis testowy', $og['og:title'] );
		$this->assertSame( get_permalink( $id ), $og['og:url'] );
		$this->assertSame( get_bloginfo( 'name' ), $og['og:site_name'] );
		$this->assertArrayHasKey( 'og:locale', $og );
		$this->assertArrayNotHasKey( 'product:price:amount', $og );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'website', Head::og()['og:type'] );

		$p = $this->product();
		$this->go_to( get_permalink( $p->get_id() ) );
		$og = Head::og();
		$this->assertSame( 'product', $og['og:type'] );
		$this->assertSame( '19.90', $og['product:price:amount'] );
		$this->assertSame( get_woocommerce_currency(), $og['product:price:currency'] );
		$this->assertSame( 'in stock', $og['product:availability'] );

		$out = $this->product( array( 'stock' => 'outofstock' ) );
		$this->go_to( get_permalink( $out->get_id() ) );
		$this->assertSame( 'out of stock', Head::og()['product:availability'] );

		$pre = $this->product( array( 'stock' => 'onbackorder' ) );
		$this->go_to( get_permalink( $pre->get_id() ) );
		$this->assertSame( 'preorder', Head::og()['product:availability'] );
	}

	public function test_og_tags_filterable() {
		$filter = function ( $tags ) {
			$tags['og:title'] = 'Zmieniony';
			$tags['og:empty'] = '';
			return $tags;
		};
		add_filter( 'aktio_connector_og_tags', $filter );
		$this->go_to( home_url( '/' ) );
		$og = Head::og();
		remove_filter( 'aktio_connector_og_tags', $filter );
		$this->assertSame( 'Zmieniony', $og['og:title'] );
		$this->assertArrayNotHasKey( 'og:empty', $og );
	}

	public function test_og_image_fallback_chain() {
		// Brak wyróżnionego obrazu, logo i obrazka domyślnego — brak tagu (nigdy pusty content).
		$id = $this->post();
		$this->go_to( get_permalink( $id ) );
		$this->assertArrayNotHasKey( 'og:image', Head::og() );
		$this->assertStringNotContainsString( 'og:image', $this->head() );
		$this->assertStringNotContainsString( 'content=""', $this->head() );

		// Obrazek domyślny.
		Settings::update( array( 'seo' => array( 'default_og_image' => 'https://example.org/default.jpg' ) ) );
		$this->assertSame( 'https://example.org/default.jpg', Head::og()['og:image'] );

		// Logo firmy wygrywa z obrazkiem domyślnym.
		Settings::update( array( 'company' => array( 'logo' => 'https://example.org/logo.png' ) ) );
		$this->assertSame( 'https://example.org/logo.png', Head::og()['og:image'] );

		// Wyróżniony obraz wygrywa ze wszystkim.
		$att = $this->image( $id );
		set_post_thumbnail( $id, $att );
		$this->assertSame( wp_get_attachment_url( $att ), Head::og()['og:image'] );

		// Produkt bez zdjęcia — logo, ze zdjęciem — zdjęcie produktu.
		$p = $this->product();
		$this->go_to( get_permalink( $p->get_id() ) );
		$this->assertSame( 'https://example.org/logo.png', Head::og()['og:image'] );
		$p->set_image_id( $att );
		$p->save();
		$this->assertSame( wp_get_attachment_url( $att ), Head::og()['og:image'] );
	}

	public function test_og_image_has_width_height_when_attachment() {
		$id  = $this->post();
		$att = $this->image( $id );
		set_post_thumbnail( $id, $att );
		$this->go_to( get_permalink( $id ) );
		$og  = Head::og();
		$src = wp_get_attachment_image_src( $att, 'full' );
		$this->assertSame( (string) $src[1], $og['og:image:width'] );
		$this->assertSame( (string) $src[2], $og['og:image:height'] );
	}

	public function test_twitter_card_summary_large_image() {
		$id  = $this->post();
		$att = $this->image( $id );
		set_post_thumbnail( $id, $att );
		$this->go_to( get_permalink( $id ) );
		$head = $this->head();
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:title" content="Wpis testowy" />', $head );
		$this->assertStringContainsString( '<meta property="og:title" content="Wpis testowy" />', $head );
		$this->assertStringContainsString( '<meta name="description" content="Treść wpisu" />', $head );
	}

	public function test_custom_title_via_pre_get_document_title() {
		if ( ! class_exists( '\AktioConnector\Seo\Fields' ) ) {
			$this->markTestSkipped( 'Pole „Tytuł SEO” dochodzi z P3b.' );
		}
		$id = $this->post();
		update_post_meta( $id, '_aktio_seo_title', 'Własny tytuł' );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( 'Własny tytuł', wp_get_document_title() );

		$other = $this->post( array( 'post_title' => 'Bez własnego' ) );
		$this->go_to( get_permalink( $other ) );
		$this->assertStringContainsString( 'Bez własnego', wp_get_document_title() );
	}

	public function test_custom_title_does_not_decode_entities_into_markup() {
		$id = $this->post();
		update_post_meta( $id, '_aktio_seo_title', '&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;' );
		$this->go_to( get_permalink( $id ) );
		ob_start();
		_wp_render_title_tag();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '</title><script>', $html );
		$this->assertStringNotContainsString( '<script>', $html );

		// Wartość zapisana z pominięciem sanitize_callback (np. import) też nie wychodzi surowa.
		global $wpdb;
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => '</title><script>alert(1)</script>' ), array( 'post_id' => $id, 'meta_key' => '_aktio_seo_title' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $id, 'post_meta' );
		ob_start();
		_wp_render_title_tag();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_password_protected_description_not_leaked_after_password_entered() {
		$id = $this->post( array( 'post_password' => 'haslo', 'post_content' => 'tajna tresc' ) );
		// Symulacja wpisanego hasła (cookie): rdzeń uznaje, że hasło nie jest już wymagane.
		add_filter( 'post_password_required', '__return_false' );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( '', Head::description() );
		$this->assertStringNotContainsString( 'tajna tresc', $this->head() );
		remove_filter( 'post_password_required', '__return_false' );
	}

	public function test_robots_keep_core_nofollow_on_noindex_pages() {
		$this->go_to( home_url( '/?s=kubek' ) );
		$robots = Head::filter_robots( array( 'nofollow' => true ) );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['follow'] );
		$this->assertTrue( $robots['nofollow'] );
	}

	public function test_noindex_on_search_cart_checkout_account_attachments_empty_tags_single_author() {
		$id = $this->post();
		$this->go_to( get_permalink( $id ) );
		$this->assertFalse( Head::noindex() );

		$this->go_to( home_url( '/?s=kubek' ) );
		$this->assertTrue( Head::noindex() );

		$this->wc_pages();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$page_id = wc_get_page_id( $page );
			$this->assertTrue( Head::noindex_for_post( get_post( $page_id ) ), $page );
			$this->go_to( get_permalink( $page_id ) );
			$this->assertTrue( Head::noindex(), $page );
		}

		$att = $this->image( $id );
		$this->assertTrue( Head::noindex_for_post( get_post( $att ) ) );
		$this->go_to( get_attachment_link( $att ) );
		$this->assertTrue( Head::noindex() );

		$empty = self::factory()->tag->create_and_get();
		$this->go_to( get_term_link( $empty ) );
		$this->assertTrue( Head::noindex() );
		wp_set_post_tags( $id, array( $empty->term_id ) );
		clean_term_cache( $empty->term_id );
		$this->go_to( get_term_link( $empty ) );
		$this->assertFalse( Head::noindex() );

		// Jeden autor z wpisami — archiwum autora ma noindex.
		$author = (int) get_post_field( 'post_author', $id );
		$this->go_to( get_author_posts_url( $author ) );
		$this->assertTrue( Head::noindex() );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->post( array( 'post_author' => $other ) );
		$this->go_to( get_author_posts_url( $author ) );
		$this->assertFalse( Head::noindex() );

		// Zwykły wpis nie ma noindex_for_post.
		$this->assertFalse( Head::noindex_for_post( get_post( $id ) ) );
	}

	public function test_noindex_robots_meta_output() {
		$this->go_to( home_url( '/?s=kubek' ) );
		$filtered = apply_filters( 'wp_robots', array() );
		$this->assertTrue( $filtered['noindex'] );
		$this->assertTrue( $filtered['follow'] );
	}

	public function test_noindex_urls_removed_from_wp_sitemap() {
		$types = apply_filters( 'wp_sitemaps_post_types', array( 'post' => 1, 'attachment' => 1 ) );
		$this->assertArrayNotHasKey( 'attachment', $types );
		$this->assertArrayHasKey( 'post', $types );

		$this->wc_pages();
		$args = apply_filters( 'wp_sitemaps_posts_query_args', array(), 'page' );
		$this->assertContains( wc_get_page_id( 'cart' ), $args['post__not_in'] );
		$this->assertContains( wc_get_page_id( 'checkout' ), $args['post__not_in'] );
		$this->assertContains( wc_get_page_id( 'myaccount' ), $args['post__not_in'] );

		$tax = apply_filters( 'wp_sitemaps_taxonomies_query_args', array( 'hide_empty' => false ), 'post_tag' );
		$this->assertTrue( $tax['hide_empty'] );

		// Jeden autor — dostawca „users” znika z mapy; kilku autorów — zostaje.
		$this->post();
		$this->assertFalse( apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'users' ) );
		$this->assertInstanceOf( stdClass::class, apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'posts' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->post( array( 'post_author' => $other ) );
		$this->assertInstanceOf( stdClass::class, apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'users' ) );
	}

	/**
	 * @dataProvider features
	 */
	public function test_nothing_when_feature_provided_elsewhere( string $feature ) {
		$id  = $this->post( array( 'post_excerpt' => 'Zajawka' ) );
		$att = $this->image( $id );
		set_post_thumbnail( $id, $att );
		$this->go_to( get_permalink( $id ) );

		// Bez wtyczki SEO wszystko jest wypisywane.
		$head = $this->head();
		$this->assertStringContainsString( 'name="description"', $head );
		$this->assertStringContainsString( 'property="og:title"', $head );
		$this->assertStringContainsString( 'name="twitter:card"', $head );

		$this->provide( $feature );
		$head = $this->head();
		switch ( $feature ) {
			case 'meta_description':
				$this->assertStringNotContainsString( 'name="description"', $head );
				$this->assertStringContainsString( 'property="og:title"', $head );
				break;
			case 'open_graph':
				$this->assertStringNotContainsString( 'property="og:', $head );
				$this->assertStringContainsString( 'name="twitter:card"', $head );
				break;
			case 'twitter_card':
				$this->assertStringNotContainsString( 'name="twitter:', $head );
				$this->assertStringContainsString( 'property="og:title"', $head );
				break;
			case 'robots_meta':
				$this->go_to( get_attachment_link( $att ) );
				$this->assertArrayNotHasKey( 'noindex', apply_filters( 'wp_robots', array() ) );
				$types = apply_filters( 'wp_sitemaps_post_types', array( 'attachment' => 1 ) );
				$this->assertArrayHasKey( 'attachment', $types );
				break;
			case 'title':
				update_post_meta( $id, '_aktio_seo_title', 'Własny tytuł' );
				$this->go_to( get_permalink( $id ) );
				$this->assertSame( 'ORYGINAŁ', Head::filter_title( 'ORYGINAŁ' ) );
				break;
		}
	}

	public function features() {
		return array(
			'title'            => array( 'title' ),
			'meta_description' => array( 'meta_description' ),
			'open_graph'       => array( 'open_graph' ),
			'twitter_card'     => array( 'twitter_card' ),
			'robots_meta'      => array( 'robots_meta' ),
		);
	}

	public function test_jetpack_open_graph_only_description_is_printed() {
		// Open Graph dostarcza Jetpack (symulowany wpisem w mapie) — Aktio daje opis meta, ale nie og:*.
		$this->provide( 'open_graph' );
		$id = $this->post();
		$this->go_to( get_permalink( $id ) );
		$head = $this->head();
		$this->assertStringContainsString( 'name="description"', $head );
		$this->assertStringNotContainsString( 'og:', $head );
	}

	public function test_password_protected_has_no_description_from_content() {
		$id = $this->post(
			array(
				'post_password' => 'sekret',
				'post_content'  => 'Tajna treść',
				'post_excerpt'  => 'Tajna zajawka',
			)
		);
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( '', Head::description() );
		$this->assertStringNotContainsString( 'Tajna', $this->head() );
		$this->assertStringNotContainsString( 'name="description"', $this->head() );
	}

	public function test_empty_site_title_falls_back_to_company_name() {
		update_option( 'blogname', '' );
		Settings::update( array( 'company' => array( 'name' => 'Plantacja' ) ) );
		$this->go_to( home_url( '/' ) );
		$og = Head::og();
		$this->assertSame( 'Plantacja', $og['og:site_name'] );
		$this->assertSame( 'Plantacja', $og['og:title'] );
		$this->assertSame( 'Plantacja', wp_get_document_title() );

		// Wpis dostaje nazwę witryny po separatorze, jak przy wypełnionym tytule witryny.
		$id = $this->post( array( 'post_title' => 'Lawenda' ) );
		$this->go_to( get_permalink( $id ) );
		$this->assertStringEndsWith( 'Plantacja', wp_get_document_title() );
		$this->assertStringStartsWith( 'Lawenda', wp_get_document_title() );

		// Wypełniony tytuł witryny ma pierwszeństwo przed nazwą firmy.
		update_option( 'blogname', 'Tytuł witryny' );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'Tytuł witryny', Head::og()['og:site_name'] );
	}

	public function test_default_wordpress_tagline_is_ignored() {
		update_option( 'blogname', 'Plantacja' );
		foreach ( array( 'Just another WordPress site', 'Kolejna witryna WordPress' ) as $tagline ) {
			update_option( 'blogdescription', $tagline );
			$this->go_to( home_url( '/' ) );
			$this->assertSame( '', Head::description(), $tagline );
			$this->assertSame( 'Plantacja', wp_get_document_title(), $tagline );
			$this->assertStringNotContainsString( $tagline, $this->head(), $tagline );
		}
		update_option( 'blogdescription', 'Lawenda z Kodrąbka' );
		$this->go_to( home_url( '/' ) );
		$this->assertSame( 'Lawenda z Kodrąbka', Head::description() );
	}

	public function test_shop_page_has_description_og_and_canonical() {
		$shop = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Sklep',
				'post_content' => 'Produkty z naszej plantacji',
				'post_excerpt' => '',
				'post_status'  => 'publish',
			)
		);
		update_option( 'woocommerce_shop_page_id', $shop );
		// WooCommerce ustawia archiwum produktów przy rejestracji typu — w testach strona sklepu powstaje później.
		$product_type              = get_post_type_object( 'product' );
		$has_archive               = $product_type->has_archive;
		$product_type->has_archive = 'sklep';
		$this->go_to( get_post_type_archive_link( 'product' ) );
		$this->assertTrue( is_post_type_archive( 'product' ) );

		$this->assertSame( 'Produkty z naszej plantacji', Head::description() );
		$og = Head::og();
		$this->assertSame( 'Sklep', $og['og:title'] );
		$this->assertSame( get_permalink( $shop ), $og['og:url'] );
		$head = $this->head();

		// Kanoniczny adres przy uprawnieniach wtyczki SEO wypisuje ona.
		$this->provide( 'robots_meta' );
		$provided = $this->head();
		$product_type->has_archive = $has_archive;

		$this->assertStringContainsString( '<link rel="canonical" href="' . esc_url( get_permalink( $shop ) ) . '" />', $head );
		$this->assertStringNotContainsString( 'rel="canonical"', $provided );
	}

	public function test_canonical_on_term_archive_but_not_on_singular() {
		$id   = $this->post();
		$term = self::factory()->category->create_and_get();
		wp_set_post_categories( $id, array( $term->term_id ) );
		$this->go_to( get_term_link( $term ) );
		$this->assertStringContainsString( '<link rel="canonical" href="' . esc_url( get_term_link( $term ) ) . '" />', $this->head() );

		// Na wpisie kanoniczny adres wypisuje rdzeń (rel_canonical) — nie dublujemy.
		$this->go_to( get_permalink( $id ) );
		$this->assertStringNotContainsString( 'rel="canonical"', $this->head() );
	}

	public function test_users_sitemap_ignores_authors_of_pages_only() {
		// Mapa użytkowników rdzenia pomija strony — autor samej strony nie robi z witryny wieloautorskiej.
		$this->post();
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->post( array( 'post_type' => 'page', 'post_author' => $other ) );
		$this->assertFalse( apply_filters( 'wp_sitemaps_add_provider', new stdClass(), 'users' ) );
	}

	public function test_sitemap_skips_mailpoet_pages_and_filterable_types() {
		$types = apply_filters(
			'wp_sitemaps_post_types',
			array(
				'post'          => 1,
				'mailpoet_page' => 1,
				'landing'       => 1,
			)
		);
		$this->assertArrayNotHasKey( 'mailpoet_page', $types );
		$this->assertArrayHasKey( 'landing', $types );

		$extra = static function ( $excluded ) {
			$excluded[] = 'landing';
			return $excluded;
		};
		add_filter( 'aktio_connector_sitemap_excluded_post_types', $extra );
		$types = apply_filters( 'wp_sitemaps_post_types', array( 'post' => 1, 'landing' => 1 ) );
		remove_filter( 'aktio_connector_sitemap_excluded_post_types', $extra );
		$this->assertArrayNotHasKey( 'landing', $types );
		$this->assertArrayHasKey( 'post', $types );
	}
}
