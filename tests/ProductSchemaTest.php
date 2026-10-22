<?php
use AktioConnector\Schema\Graph;
use AktioConnector\Schema\ProductSchema;
use AktioConnector\Settings;

class ProductSchemaTest extends WP_UnitTestCase {

	private $active = array();
	private $dirs   = array();

	public function set_up() {
		parent::set_up();
		$this->active = array();
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		delete_option( Settings::OPTION );
		delete_option( 'rank_math_modules' );
		foreach ( $this->dirs as $dir ) {
			foreach ( glob( $dir . '/*' ) as $f ) {
				unlink( $f );
			}
			rmdir( $dir );
		}
		$this->dirs = array();
		foreach ( \WC_Shipping_Zones::get_zones() as $z ) {
			\WC_Shipping_Zones::get_zone( $z['id'] )->delete();
		}
		unset( $GLOBALS['product'] );
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active;
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

	private function simple( array $props = array() ): \WC_Product_Simple {
		$p = new \WC_Product_Simple();
		$p->set_name( $props['name'] ?? 'Kubek' );
		$p->set_status( $props['status'] ?? 'publish' );
		$p->set_regular_price( $props['price'] ?? '19.9' );
		$p->set_stock_status( $props['stock'] ?? 'instock' );
		foreach ( array( 'sale_price', 'sku', 'description', 'short_description', 'global_unique_id' ) as $k ) {
			if ( isset( $props[ $k ] ) ) {
				$p->{"set_$k"}( $props[ $k ] );
			}
		}
		if ( isset( $props['sale_to'] ) ) {
			$p->set_date_on_sale_to( $props['sale_to'] );
		}
		if ( isset( $props['password'] ) ) {
			wp_update_post( array( 'ID' => $p->save(), 'post_password' => $props['password'] ) );
		}
		$p->save();
		return $p;
	}

	private function variable( array $prices ): \WC_Product_Variable {
		$attr = new \WC_Product_Attribute();
		$attr->set_name( 'Size' );
		$attr->set_options( array_keys( $prices ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$p = new \WC_Product_Variable();
		$p->set_name( 'Koszulka' );
		$p->set_status( 'publish' );
		$p->set_attributes( array( $attr ) );
		$p->save();
		foreach ( $prices as $size => $price ) {
			$v = new \WC_Product_Variation();
			$v->set_parent_id( $p->get_id() );
			$v->set_attributes( array( 'size' => $size ) );
			if ( null !== $price ) {
				$v->set_regular_price( $price );
			}
			$v->set_status( 'publish' );
			$v->save();
		}
		\WC_Product_Variable::sync( $p->get_id() );
		return wc_get_product( $p->get_id() );
	}

	private function product_node( \WC_Product $p ): ?array {
		$this->go_to( get_permalink( $p->get_id() ) );
		foreach ( $this->graph() as $node ) {
			if ( in_array( $node['@type'], array( 'Product', 'ProductGroup' ), true ) ) {
				return $node;
			}
		}
		return null;
	}

	private function graph(): array {
		$json = Graph::render();
		if ( '' === $json ) {
			return array();
		}
		preg_match( '#^<script type="application/ld\+json">(.*)</script>\s*$#s', $json, $m );
		return json_decode( $m[1], true )['@graph'];
	}

	public function test_simple_product_has_offer_price_currency_availability_and_url() {
		$p    = $this->simple();
		$node = $this->product_node( $p );
		$this->assertSame( 'Product', $node['@type'] );
		$this->assertSame( get_permalink( $p->get_id() ) . '#product', $node['@id'] );
		$this->assertSame( 'Kubek', $node['name'] );
		$o = $node['offers'];
		$this->assertSame( 'Offer', $o['@type'] );
		$this->assertSame( '19.90', $o['price'] );
		$this->assertSame( get_woocommerce_currency(), $o['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $o['availability'] );
		$this->assertSame( get_permalink( $p->get_id() ), $o['url'] );
		$this->assertSame( home_url( '/#organization' ), $o['seller']['@id'] );
		$this->assertArrayNotHasKey( 'priceValidUntil', $o );
	}

	public function test_product_is_main_entity_of_webpage_when_webpage_exists() {
		$p    = $this->simple();
		$g    = null;
		$this->go_to( get_permalink( $p->get_id() ) );
		foreach ( $this->graph() as $n ) {
			if ( 'WebPage' === $n['@type'] ) {
				$g = $n;
			}
		}
		$this->assertSame( get_permalink( $p->get_id() ) . '#product', $g['mainEntity']['@id'] );
	}

	public function test_no_webpage_dependency_when_seo_plugin_owns_content_schema() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		$p    = $this->simple();
		$node = $this->product_node( $p );
		$this->assertSame( 'Product', $node['@type'] );
		foreach ( $this->graph() as $n ) {
			$this->assertNotSame( 'WebPage', $n['@type'] );
		}
	}

	public function test_sale_price_sets_price_valid_until() {
		$p    = $this->simple( array( 'sale_price' => '9.5', 'sale_to' => '2099-03-04' ) );
		$node = $this->product_node( $p );
		$this->assertSame( '9.50', $node['offers']['price'] );
		$this->assertSame( '2099-03-04', $node['offers']['priceValidUntil'] );
	}

	public function test_variable_product_is_product_group_with_has_variant_and_variesBy() {
		$p    = $this->variable( array( 'S' => '10', 'M' => '12' ) );
		$node = $this->product_node( $p );
		$this->assertSame( 'ProductGroup', $node['@type'] );
		$this->assertArrayNotHasKey( 'offers', $node );
		$this->assertContains( 'https://schema.org/size', $node['variesBy'] );
		$this->assertCount( 2, $node['hasVariant'] );
		$prices = array_map(
			function ( $v ) {
				return $v['offers']['price'];
			},
			$node['hasVariant']
		);
		sort( $prices );
		$this->assertSame( array( '10.00', '12.00' ), $prices );
		$this->assertSame( 'Product', $node['hasVariant'][0]['@type'] );
	}

	public function test_variable_product_without_any_price_has_no_offer() {
		$p    = $this->variable( array( 'S' => null, 'M' => null ) );
		$node = $this->product_node( $p );
		$this->assertSame( 'ProductGroup', $node['@type'] );
		$this->assertArrayNotHasKey( 'offers', $node );
		$this->assertArrayNotHasKey( 'hasVariant', $node );
		$this->assertStringNotContainsString( '"Offer"', Graph::render() );
	}

	public function test_simple_product_without_price_has_no_offer() {
		$p = $this->simple();
		$p->set_regular_price( '' );
		$p->save();
		$this->assertArrayNotHasKey( 'offers', $this->product_node( $p ) );
	}

	public function test_out_of_stock_maps_to_OutOfStock_and_backorder_to_BackOrder() {
		$out = $this->product_node( $this->simple( array( 'stock' => 'outofstock' ) ) );
		$this->assertSame( 'https://schema.org/OutOfStock', $out['offers']['availability'] );
		$back = $this->product_node( $this->simple( array( 'stock' => 'onbackorder' ) ) );
		$this->assertSame( 'https://schema.org/BackOrder', $back['offers']['availability'] );
	}

	private function review( int $post_id, int $stars, string $text, string $author = 'Ola' ) {
		$id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_type'     => 'review',
				'comment_content'  => $text,
				'comment_author'   => $author,
				'comment_approved' => 1,
			)
		);
		add_comment_meta( $id, 'rating', $stars );
		\WC_Comments::clear_transients( $post_id );
		return $id;
	}

	public function test_no_reviews_means_no_aggregate_rating() {
		$node = $this->product_node( $this->simple() );
		$this->assertArrayNotHasKey( 'aggregateRating', $node );
		$this->assertArrayNotHasKey( 'review', $node );
	}

	public function test_reviews_give_aggregate_rating_and_up_to_5_reviews() {
		$p = $this->simple();
		for ( $i = 0; $i < 7; $i++ ) {
			$this->review( $p->get_id(), 4 + ( $i % 2 ), "Opinia <b>$i</b>" );
		}
		$node = $this->product_node( wc_get_product( $p->get_id() ) );
		$this->assertSame( 7, $node['aggregateRating']['reviewCount'] );
		$this->assertEqualsWithDelta( 4.43, (float) $node['aggregateRating']['ratingValue'], 0.01 );
		$this->assertCount( 5, $node['review'] );
		$this->assertSame( 'Review', $node['review'][0]['@type'] );
		$this->assertSame( 'Ola', $node['review'][0]['author']['name'] );
		$this->assertStringNotContainsString( '<b>', $node['review'][0]['reviewBody'] );
		$this->assertContains( $node['review'][0]['reviewRating']['ratingValue'], array( 4, 5 ) );
	}

	public function test_sku_gtin_brand_included_when_present() {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			register_taxonomy( 'product_brand', 'product' );
		}
		$p = $this->simple( array( 'sku' => 'KUB-1', 'global_unique_id' => '5901234123457' ) );
		wp_set_object_terms( $p->get_id(), array( 'Acme' ), 'product_brand' );
		$node = $this->product_node( $p );
		$this->assertSame( 'KUB-1', $node['sku'] );
		$this->assertSame( '5901234123457', $node['gtin'] );
		$this->assertSame( 'Brand', $node['brand']['@type'] );
		$this->assertSame( 'Acme', $node['brand']['name'] );
		$bare = $this->product_node( $this->simple() );
		$this->assertArrayNotHasKey( 'sku', $bare );
		$this->assertArrayNotHasKey( 'gtin', $bare );
		$this->assertArrayNotHasKey( 'brand', $bare );
	}

	public function test_title_and_description_are_plain_text_and_json_safe() {
		$p = $this->simple(
			array(
				'name'        => 'Kubek „A” 🌿 </script><b>x</b>',
				'description' => '<p>Opis <em>ładny</em> [gallery]</p>',
			)
		);
		$this->go_to( get_permalink( $p->get_id() ) );
		$this->assertSame( 1, substr_count( Graph::render(), '</script>' ) );
		$node = $this->product_node( $p );
		$this->assertStringNotContainsString( '<', $node['name'] );
		$this->assertStringContainsString( '🌿', $node['name'] );
		$this->assertSame( 'Opis ładny', $node['description'] );
	}

	public function test_password_protected_or_unpublished_product_has_no_node() {
		$this->assertNull( $this->product_node( $this->simple( array( 'password' => 'tajne' ) ) ) );
		$draft = $this->simple( array( 'status' => 'draft' ) );
		$this->go_to( '/?p=' . $draft->get_id() . '&post_type=product' );
		foreach ( Graph::nodes() as $n ) {
			$this->assertNotContains( $n['@type'], array( 'Product', 'ProductGroup' ) );
		}
	}

	public function test_no_node_outside_product_pages() {
		$this->simple();
		$this->go_to( home_url( '/' ) );
		foreach ( Graph::nodes() as $n ) {
			$this->assertNotContains( $n['@type'], array( 'Product', 'ProductGroup' ) );
		}
	}

	public function test_nothing_when_seo_plugin_owns_product_schema() {
		$this->plugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO' );
		update_option( 'rank_math_modules', array( 'woocommerce' ) );
		$this->assertNull( $this->product_node( $this->simple() ) );
		$this->assertSame( array( 'a' => 1 ), ProductSchema::suppress_core_product( array( 'a' => 1 ) ) );
	}

	public function test_single_shipping_zone_with_flat_rate_gives_shipping_details() {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Polska' );
		$zone->add_location( 'PL', 'country' );
		$zone->save();
		$iid = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $iid . '_settings', array( 'cost' => '12', 'tax_status' => 'taxable' ) );
		$node = $this->product_node( $this->simple() );
		$sd   = $node['offers']['shippingDetails'];
		$this->assertSame( '12.00', $sd['shippingRate']['value'] );
		$this->assertSame( 'PL', $sd['shippingDestination'][0]['addressCountry'] );
	}

	public function test_no_shipping_details_without_exactly_one_zone() {
		$this->assertArrayNotHasKey( 'shippingDetails', $this->product_node( $this->simple() )['offers'] );
	}

	public function test_core_wc_structured_data_removed_when_aktio_owns_product_schema() {
		$p = $this->simple();
		$this->assertSame( 1, $this->count_product_nodes_on_page( $p ) );
	}

	public function test_core_wc_structured_data_kept_when_seo_plugin_owns_product_schema() {
		$this->plugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO' );
		update_option( 'rank_math_modules', array( 'woocommerce' ) );
		// Aktio nic nie dokłada ani nie wycina — węzeł pochodzi od rdzenia WooCommerce.
		$this->assertSame( 1, $this->count_product_nodes_on_page( $this->simple() ) );
	}

	/** Węzły Product/ProductGroup najwyższego poziomu ze wszystkich bloków JSON-LD w wp_head + wp_footer. */
	private function count_product_nodes_on_page( \WC_Product $p ): int {
		$this->go_to( get_permalink( $p->get_id() ) );
		$GLOBALS['product'] = $p;
		ob_start();
		do_action( 'woocommerce_single_product_summary' );
		do_action( 'wp_head' );
		do_action( 'wp_footer' );
		$html = ob_get_clean();
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
		$count = 0;
		foreach ( $m[1] as $json ) {
			$data = json_decode( $json, true );
			// Warianty (hasVariant) są zagnieżdżone — liczymy tylko węzły najwyższego poziomu.
			foreach ( isset( $data['@graph'] ) ? $data['@graph'] : array( $data ) as $node ) {
				if ( isset( $node['@type'] ) && in_array( $node['@type'], array( 'Product', 'ProductGroup' ), true ) ) {
					$count++;
				}
			}
		}
		return $count;
	}
}
