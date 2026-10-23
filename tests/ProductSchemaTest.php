<?php
use AktioConnector\Schema\Graph;
use AktioConnector\Schema\ProductSchema;
use AktioConnector\Settings;

class ProductSchemaTest extends WP_UnitTestCase {

	private $active = array();
	private $dirs   = array();
	private $standalone_reviews = 0;

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
		$locked = $this->simple( array( 'password' => 'tajne' ) );
		$this->assertNull( $this->product_node( $locked ) );
		$this->assertTrue( is_singular( 'product' ) ); // kontrola pozytywna: to strona produktu
		$draft = $this->simple( array( 'status' => 'draft' ) );
		wp_set_current_user( 0 );
		$this->go_to( '/?p=' . $draft->get_id() . '&post_type=product' );
		$this->assertTrue( is_singular( 'product' ) || is_404() );
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

	public function test_zone_lookup_happens_once_for_variable_product() {
		$p      = $this->variable( array( 'S' => '10', 'M' => '12', 'L' => '14' ) );
		$before = ProductSchema::$zone_lookups;
		$this->assertCount( 3, $this->product_node( $p )['hasVariant'] );
		$this->assertSame( 1, ProductSchema::$zone_lookups - $before );
	}

	public function test_seller_is_reference_when_aktio_emits_organization() {
		$node = $this->product_node( $this->simple() );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $node['offers']['seller'] );
		$types = array_column( $this->graph(), '@type' );
		$this->assertContains( 'Organization', $types );
	}

	public function test_seller_is_inline_organization_when_seo_plugin_owns_content_schema() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		update_option( Settings::OPTION, array( 'company' => array( 'name' => 'Sklep Testowy' ) ) );
		$node = $this->product_node( $this->simple() );
		$this->assertSame( array( '@type' => 'Organization', 'name' => 'Sklep Testowy' ), $node['offers']['seller'] );
	}

	public function test_no_shipping_details_without_exactly_one_zone() {
		$this->assertArrayNotHasKey( 'shippingDetails', $this->product_node( $this->simple() )['offers'] );
	}

	public function test_core_wc_structured_data_removed_when_aktio_owns_product_schema() {
		$p = $this->simple();
		$this->review( $p->get_id(), 5, 'Super' );
		$this->assertSame( 1, $this->count_product_nodes_on_page( wc_get_product( $p->get_id() ) ) );
		$this->assertSame( 0, $this->standalone_reviews );
	}

	public function test_core_wc_structured_data_kept_when_seo_plugin_owns_product_schema() {
		$this->plugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO' );
		update_option( 'rank_math_modules', array( 'woocommerce' ) );
		// Aktio nic nie dokłada ani nie wycina — węzeł pochodzi od rdzenia WooCommerce.
		$this->assertSame( 1, $this->count_product_nodes_on_page( $this->simple() ) );
	}

	public function test_single_breadcrumb_list_on_product_page_when_aktio_owns_content_schema() {
		$p = $this->simple();
		$this->assertSame( 1, $this->count_breadcrumb_lists_on_page( $p ) );
	}

	public function test_wc_breadcrumb_kept_when_seo_plugin_owns_content_schema() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		// Aktio nie dodaje własnych okruszków — zostaje lista WooCommerce.
		$this->assertSame( 1, $this->count_breadcrumb_lists_on_page( $this->simple() ) );
	}

	// --- Pola sprzedawcy (merchant), wersja 0.1.2 ---

	private function merchant( array $merchant ): void {
		Settings::update( array( 'merchant' => $merchant ) );
	}

	private function brand_term( \WC_Product $p, string $name ): void {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			register_taxonomy( 'product_brand', 'product' );
		}
		wp_set_object_terms( $p->get_id(), array( $name ), 'product_brand' );
	}

	public function test_default_brand_used_when_product_has_no_own_brand() {
		$this->merchant( array( 'default_brand' => 'Plantacja' ) );
		$node = $this->product_node( $this->simple() );
		$this->assertSame( array( '@type' => 'Brand', 'name' => 'Plantacja' ), $node['brand'] );
	}

	public function test_own_product_brand_wins_over_default_brand() {
		$this->merchant( array( 'default_brand' => 'Plantacja' ) );
		$p = $this->simple();
		$this->brand_term( $p, 'Cudza Marka' );
		$node = $this->product_node( $p );
		$this->assertSame( 'Cudza Marka', $node['brand']['name'] );
	}

	public function test_no_brand_when_default_brand_empty_and_no_own_brand() {
		$this->assertArrayNotHasKey( 'brand', $this->product_node( $this->simple() ) );
	}

	public function test_default_brand_applies_to_variable_product_group() {
		$this->merchant( array( 'default_brand' => 'Plantacja' ) );
		$node = $this->product_node( $this->variable( array( 'S' => '10', 'M' => '12' ) ) );
		$this->assertSame( 'ProductGroup', $node['@type'] );
		$this->assertSame( 'Plantacja', $node['brand']['name'] );
	}

	private function return_policy( array $over = array() ): array {
		return array_merge( array( 'enabled' => true, 'days' => 14, 'fees' => 'customer', 'country' => 'PL', 'method' => 'mail' ), $over );
	}

	public function test_return_policy_absent_by_default() {
		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $this->product_node( $this->simple() )['offers'] );
	}

	public function test_return_policy_finite_window_on_simple_offer() {
		$this->merchant( array( 'return_policy' => $this->return_policy() ) );
		$policy = $this->product_node( $this->simple() )['offers']['hasMerchantReturnPolicy'];
		$this->assertSame(
			array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => 'PL',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
				'merchantReturnDays'   => 14,
				'returnFees'           => 'https://schema.org/ReturnFeesCustomerResponsibility',
				'returnMethod'         => 'https://schema.org/ReturnByMail',
			),
			$policy
		);
	}

	public function test_return_policy_free_returns_and_methods() {
		$cases = array(
			'mail'          => 'https://schema.org/ReturnByMail',
			'store'         => 'https://schema.org/ReturnInStore',
			'mail_or_store' => array( 'https://schema.org/ReturnByMail', 'https://schema.org/ReturnInStore' ),
		);
		foreach ( $cases as $method => $expected ) {
			$this->merchant( array( 'return_policy' => $this->return_policy( array( 'fees' => 'free', 'method' => $method, 'days' => 30 ) ) ) );
			$policy = $this->product_node( $this->simple() )['offers']['hasMerchantReturnPolicy'];
			$this->assertSame( 'https://schema.org/FreeReturn', $policy['returnFees'] );
			$this->assertSame( 30, $policy['merchantReturnDays'] );
			$this->assertSame( $expected, $policy['returnMethod'], $method );
		}
	}

	public function test_return_policy_zero_days_means_not_permitted_without_details() {
		$this->merchant( array( 'return_policy' => $this->return_policy( array( 'days' => 0, 'fees' => 'free', 'method' => 'store' ) ) ) );
		$policy = $this->product_node( $this->simple() )['offers']['hasMerchantReturnPolicy'];
		$this->assertSame(
			array(
				'@type'                => 'MerchantReturnPolicy',
				'applicableCountry'    => 'PL',
				'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			),
			$policy
		);
	}

	public function test_return_policy_on_every_variant_offer() {
		$this->merchant( array( 'return_policy' => $this->return_policy( array( 'days' => 21 ) ) ) );
		$node = $this->product_node( $this->variable( array( 'S' => '10', 'M' => '12', 'L' => '14' ) ) );
		$this->assertCount( 3, $node['hasVariant'] );
		foreach ( $node['hasVariant'] as $variant ) {
			$this->assertSame( 21, $variant['offers']['hasMerchantReturnPolicy']['merchantReturnDays'] );
			$this->assertSame( 'PL', $variant['offers']['hasMerchantReturnPolicy']['applicableCountry'] );
		}
	}

	public function test_return_policy_absent_when_disabled_or_country_empty() {
		$this->merchant( array( 'return_policy' => $this->return_policy( array( 'enabled' => false ) ) ) );
		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $this->product_node( $this->simple() )['offers'] );
		// Pusty kraj = kraj sklepu; bez kraju sklepu nic nie jest emitowane.
		update_option( 'woocommerce_default_country', '' );
		$this->merchant( array( 'return_policy' => $this->return_policy( array( 'enabled' => true, 'country' => '' ) ) ) );
		$this->assertArrayNotHasKey( 'hasMerchantReturnPolicy', $this->product_node( $this->simple() )['offers'] );
	}

	public function test_return_policy_also_for_virtual_and_downloadable_products() {
		$this->merchant( array( 'return_policy' => $this->return_policy() ) );
		$p = $this->simple();
		$p->set_virtual( true );
		$p->set_downloadable( true );
		$p->save();
		$this->assertArrayHasKey( 'hasMerchantReturnPolicy', $this->product_node( $p )['offers'] );
	}

	private function shipping_fallback( array $over = array() ): array {
		return array_merge(
			array( 'enabled' => true, 'country' => 'PL', 'cost' => '12.50', 'handling_days' => array( 0, 2 ), 'transit_days' => array( 2, 4 ) ),
			$over
		);
	}

	public function test_shipping_fallback_emitted_when_no_zone_detected() {
		$this->merchant( array( 'shipping' => $this->shipping_fallback() ) );
		$sd = $this->product_node( $this->simple() )['offers']['shippingDetails'];
		$this->assertSame(
			array(
				'@type'               => 'OfferShippingDetails',
				'shippingRate'        => array(
					'@type'    => 'MonetaryAmount',
					'value'    => '12.50',
					'currency' => get_woocommerce_currency(),
				),
				'shippingDestination' => array(
					'@type'          => 'DefinedRegion',
					'addressCountry' => 'PL',
				),
				'deliveryTime'        => array(
					'@type'        => 'ShippingDeliveryTime',
					'handlingTime' => array( '@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 2, 'unitCode' => 'DAY' ),
					'transitTime'  => array( '@type' => 'QuantitativeValue', 'minValue' => 2, 'maxValue' => 4, 'unitCode' => 'DAY' ),
				),
			),
			$sd
		);
	}

	public function test_shipping_fallback_on_every_variant_with_single_zone_lookup() {
		$this->merchant( array( 'shipping' => $this->shipping_fallback() ) );
		$p      = $this->variable( array( 'S' => '10', 'M' => '12', 'L' => '14' ) );
		$before = ProductSchema::$zone_lookups;
		$node   = $this->product_node( $p );
		$this->assertCount( 3, $node['hasVariant'] );
		foreach ( $node['hasVariant'] as $variant ) {
			$this->assertSame( '12.50', $variant['offers']['shippingDetails']['shippingRate']['value'] );
		}
		$this->assertSame( 1, ProductSchema::$zone_lookups - $before );
	}

	public function test_detected_zone_shipping_wins_over_fallback() {
		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( 'Polska' );
		$zone->add_location( 'PL', 'country' );
		$zone->save();
		$iid = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $iid . '_settings', array( 'cost' => '12', 'tax_status' => 'taxable' ) );
		$this->merchant( array( 'shipping' => $this->shipping_fallback( array( 'cost' => '99.00', 'country' => 'DE' ) ) ) );
		$sd = $this->product_node( $this->simple() )['offers']['shippingDetails'];
		$this->assertSame( '12.00', $sd['shippingRate']['value'] );
		$this->assertArrayNotHasKey( 'deliveryTime', $sd );
	}

	public function test_shipping_fallback_absent_for_virtual_and_downloadable() {
		$this->merchant( array( 'shipping' => $this->shipping_fallback() ) );
		$virtual = $this->simple();
		$virtual->set_virtual( true );
		$virtual->save();
		$this->assertArrayNotHasKey( 'shippingDetails', $this->product_node( $virtual )['offers'] );

		$download = $this->simple();
		$download->set_downloadable( true );
		$download->save();
		$this->assertArrayNotHasKey( 'shippingDetails', $this->product_node( $download )['offers'] );
	}

	public function test_shipping_fallback_absent_when_disabled_or_country_empty() {
		$this->merchant( array( 'shipping' => $this->shipping_fallback( array( 'enabled' => false ) ) ) );
		$this->assertArrayNotHasKey( 'shippingDetails', $this->product_node( $this->simple() )['offers'] );
		update_option( 'woocommerce_default_country', '' );
		$this->merchant( array( 'shipping' => $this->shipping_fallback( array( 'enabled' => true, 'country' => '' ) ) ) );
		$this->assertArrayNotHasKey( 'shippingDetails', $this->product_node( $this->simple() )['offers'] );
	}

	public function test_emitted_country_follows_store_country_after_unrelated_save() {
		update_option( 'woocommerce_default_country', 'PL:DS' );
		Settings::update(
			array(
				'bots'     => array( 'GPTBot' => 'block' ),
				'merchant' => array(
					'return_policy' => array( 'enabled' => true ),
					'shipping'      => array( 'enabled' => true ),
				),
			)
		);
		$node = $this->product_node( $this->simple() );
		$this->assertSame( 'PL', $node['offers']['hasMerchantReturnPolicy']['applicableCountry'] );
		$this->assertSame( 'PL', $node['offers']['shippingDetails']['shippingDestination']['addressCountry'] );

		update_option( 'woocommerce_default_country', 'DE' );
		$node = $this->product_node( $this->simple() );
		$this->assertSame( 'DE', $node['offers']['hasMerchantReturnPolicy']['applicableCountry'] );
		$this->assertSame( 'DE', $node['offers']['shippingDetails']['shippingDestination']['addressCountry'] );
	}

	public function test_nothing_merchant_related_when_seo_plugin_owns_product_schema() {
		$this->plugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO' );
		update_option( 'rank_math_modules', array( 'woocommerce' ) );
		$this->merchant(
			array(
				'default_brand' => 'Plantacja',
				'return_policy' => $this->return_policy(),
				'shipping'      => $this->shipping_fallback(),
			)
		);
		$p = $this->simple();
		$this->go_to( get_permalink( $p->get_id() ) );
		$json = Graph::render();
		$this->assertStringNotContainsString( 'hasMerchantReturnPolicy', $json );
		$this->assertStringNotContainsString( 'OfferShippingDetails', $json );
		$this->assertStringNotContainsString( 'Plantacja', $json );
		$this->assertNull( $this->product_node( $p ) );
	}

	/** Węzły BreadcrumbList ze wszystkich bloków JSON-LD po wyrenderowaniu okruszków WC, wp_head i wp_footer. */
	private function count_breadcrumb_lists_on_page( \WC_Product $p ): int {
		$this->go_to( get_permalink( $p->get_id() ) );
		$GLOBALS['product'] = $p;
		// WC()->structured_data żyje przez cały proces testów — zerujemy dane z poprzednich testów.
		$data = new \ReflectionProperty( \WC_Structured_Data::class, '_data' );
		$data->setAccessible( true );
		$data->setValue( WC()->structured_data, array() );
		ob_start();
		woocommerce_breadcrumb();
		do_action( 'wp_head' );
		do_action( 'wp_footer' );
		$html = ob_get_clean();
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
		$count = 0;
		foreach ( $m[1] as $json ) {
			$data = json_decode( $json, true );
			foreach ( isset( $data['@graph'] ) ? $data['@graph'] : array( $data ) as $node ) {
				if ( isset( $node['@type'] ) && 'BreadcrumbList' === $node['@type'] ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/** Węzły Product/ProductGroup najwyższego poziomu ze wszystkich bloków JSON-LD w wp_head + wp_footer. */
	private function count_product_nodes_on_page( \WC_Product $p ): int {
		$this->go_to( get_permalink( $p->get_id() ) );
		$GLOBALS['product'] = $p;
		$this->standalone_reviews = 0;
		foreach ( get_comments( array( 'post_id' => $p->get_id(), 'type' => 'review' ) ) as $c ) {
			WC()->structured_data->generate_review_data( $c ); // jak przy renderze opinii
		}
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
				if ( isset( $node['@type'] ) && 'Review' === $node['@type'] ) {
					$this->standalone_reviews++;
				}
				if ( isset( $node['@type'] ) && in_array( $node['@type'], array( 'Product', 'ProductGroup' ), true ) ) {
					$count++;
				}
			}
		}
		return $count;
	}
}
