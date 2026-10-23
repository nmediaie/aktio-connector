<?php
use AktioConnector\Settings;

class SettingsTest extends WP_UnitTestCase {

	public function test_defaults_allow_all_bots_and_use_site_name() {
		$s = Settings::get();
		$this->assertSame( array_fill_keys( Settings::BOTS, 'allow' ), $s['bots'] );
		$this->assertSame( get_bloginfo( 'name' ), $s['company']['name'] );
		$this->assertSame( 'Organization', $s['company']['type'] );
	}

	public function test_update_rejects_unknown_bot_and_bad_values() {
		$s = Settings::update( array( 'bots' => array( 'GPTBot' => 'block', 'EvilBot' => 'block', 'ClaudeBot' => 'maybe' ) ) );
		$this->assertSame( 'block', $s['bots']['GPTBot'] );
		$this->assertArrayNotHasKey( 'EvilBot', $s['bots'] );
		$this->assertSame( 'allow', $s['bots']['ClaudeBot'] );
	}

	public function test_update_sanitizes_company_and_persists() {
		$long = str_repeat( 'a', 300 );
		$s    = Settings::update(
			array(
				'company' => array(
					'name'    => '<b>Firma</b> ' . $long,
					'type'    => 'Nonsense',
					'logo'    => 'javascript:alert(1)',
					'email'   => 'zly mail',
					'same_as' => array_map( function ( $i ) { return "https://example.com/$i"; }, range( 1, 15 ) ),
				),
			)
		);
		$this->assertSame( 200, strlen( $s['company']['name'] ) );
		$this->assertStringNotContainsString( '<b>', $s['company']['name'] );
		$this->assertSame( 'Organization', $s['company']['type'] );
		$this->assertSame( '', $s['company']['logo'] );
		$this->assertSame( '', $s['company']['email'] );
		$this->assertCount( 10, $s['company']['same_as'] );
		$this->assertSame( $s, Settings::get() );
	}

	public function test_update_accepts_local_business_and_valid_url() {
		$s = Settings::update( array( 'company' => array( 'type' => 'LocalBusiness', 'logo' => 'https://example.com/logo.png' ) ) );
		$this->assertSame( 'LocalBusiness', $s['company']['type'] );
		$this->assertSame( 'https://example.com/logo.png', $s['company']['logo'] );
	}

	public function test_company_name_not_frozen_to_site_name() {
		update_option( 'blogname', 'Pierwsza' );
		Settings::update( array( 'company' => array( 'phone' => '123' ) ) );
		$this->assertSame( 'Pierwsza', Settings::get()['company']['name'] );
		update_option( 'blogname', 'Druga' );
		$this->assertSame( 'Druga', Settings::get()['company']['name'] );
		$this->assertSame( '', get_option( Settings::OPTION )['company']['name'] );
	}

	public function test_explicit_company_name_is_kept_and_empty_falls_back() {
		update_option( 'blogname', 'Witryna' );
		$s = Settings::update( array( 'company' => array( 'name' => 'Moja Firma' ) ) );
		$this->assertSame( 'Moja Firma', $s['company']['name'] );
		update_option( 'blogname', 'Inna' );
		$this->assertSame( 'Moja Firma', Settings::get()['company']['name'] );
		$s = Settings::update( array( 'company' => array( 'name' => '' ) ) );
		$this->assertSame( 'Inna', $s['company']['name'] );
		$this->assertSame( 'Inna', Settings::get()['company']['name'] );
	}

	// --- Pola sprzedawcy (merchant), wersja 0.1.2 ---

	public function tear_down() {
		delete_option( 'woocommerce_default_country' );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_merchant_defaults() {
		update_option( 'woocommerce_default_country', 'PL:DS' );
		$m = Settings::get()['merchant'];
		$this->assertSame(
			array(
				'default_brand' => '',
				'return_policy' => array(
					'enabled' => false,
					'days'    => 14,
					'fees'    => 'customer',
					'country' => 'PL',
					'method'  => 'mail',
				),
				'shipping'      => array(
					'enabled'       => false,
					'country'       => 'PL',
					'cost'          => '0.00',
					'handling_days' => array( 0, 1 ),
					'transit_days'  => array( 1, 3 ),
				),
			),
			$m
		);
	}

	public function test_merchant_default_country_without_region_or_invalid_or_missing() {
		update_option( 'woocommerce_default_country', 'IE' );
		$this->assertSame( 'IE', Settings::get()['merchant']['return_policy']['country'] );
		update_option( 'woocommerce_default_country', '' );
		$this->assertSame( '', Settings::get()['merchant']['shipping']['country'] );
		update_option( 'woocommerce_default_country', 'xx1' );
		$this->assertSame( '', Settings::get()['merchant']['return_policy']['country'] );
	}

	public function test_merchant_full_valid_update_persists() {
		$input = array(
			'default_brand' => '  <b>Moja</b> Marka ',
			'return_policy' => array( 'enabled' => true, 'days' => 30, 'fees' => 'free', 'country' => 'DE', 'method' => 'mail_or_store' ),
			'shipping'      => array( 'enabled' => true, 'country' => 'PL', 'cost' => '12.50', 'handling_days' => array( 1, 2 ), 'transit_days' => array( 2, 5 ) ),
		);
		$this->assertSame( array(), Settings::merchant_errors( array( 'merchant' => $input ) ) );
		$s = Settings::update( array( 'merchant' => $input ) );
		$this->assertSame( 'Moja Marka', $s['merchant']['default_brand'] );
		$this->assertSame( $input['return_policy'], $s['merchant']['return_policy'] );
		$this->assertSame( $input['shipping'], $s['merchant']['shipping'] );
		$this->assertSame( $s, Settings::get() );
	}

	public function test_merchant_partial_update_keeps_other_keys() {
		Settings::update(
			array(
				'merchant' => array(
					'default_brand' => 'Marka',
					'return_policy' => array( 'enabled' => true, 'days' => 30, 'country' => 'PL' ),
					'shipping'      => array( 'enabled' => true, 'cost' => '9.99', 'handling_days' => array( 1, 2 ) ),
				),
			)
		);
		$s = Settings::update( array( 'merchant' => array( 'return_policy' => array( 'days' => 7 ) ) ) );
		$this->assertSame( 7, $s['merchant']['return_policy']['days'] );
		$this->assertTrue( $s['merchant']['return_policy']['enabled'] );
		$this->assertSame( 'PL', $s['merchant']['return_policy']['country'] );
		$this->assertSame( 'Marka', $s['merchant']['default_brand'] );
		$this->assertSame( '9.99', $s['merchant']['shipping']['cost'] );
		$this->assertSame( array( 1, 2 ), $s['merchant']['shipping']['handling_days'] );
		$this->assertSame( array( 1, 3 ), $s['merchant']['shipping']['transit_days'] );

		$s = Settings::update( array( 'company' => array( 'phone' => '1' ) ) );
		$this->assertSame( 7, $s['merchant']['return_policy']['days'] );
		$this->assertSame( 'Marka', $s['merchant']['default_brand'] );
	}

	public function test_merchant_can_clear_brand_and_country_back_to_store_country() {
		update_option( 'woocommerce_default_country', 'IE' );
		Settings::update( array( 'merchant' => array( 'default_brand' => 'X', 'return_policy' => array( 'country' => 'PL' ) ) ) );
		$this->assertSame( 'PL', Settings::get()['merchant']['return_policy']['country'] );
		$s = Settings::update( array( 'merchant' => array( 'default_brand' => '', 'return_policy' => array( 'country' => '' ) ) ) );
		$this->assertSame( '', $s['merchant']['default_brand'] );
		$this->assertSame( 'IE', $s['merchant']['return_policy']['country'] );
		$this->assertSame( '', get_option( Settings::OPTION )['merchant']['return_policy']['country'] );
	}

	public function test_unset_country_is_not_frozen_and_follows_store_country() {
		update_option( 'woocommerce_default_country', 'PL:DS' );
		Settings::update( array( 'bots' => array( 'GPTBot' => 'block' ) ) );
		$stored = get_option( Settings::OPTION );
		$this->assertSame( '', $stored['merchant']['return_policy']['country'] );
		$this->assertSame( '', $stored['merchant']['shipping']['country'] );
		$this->assertSame( 'PL', Settings::get()['merchant']['shipping']['country'] );

		update_option( 'woocommerce_default_country', 'DE' );
		$m = Settings::get()['merchant'];
		$this->assertSame( 'DE', $m['return_policy']['country'] );
		$this->assertSame( 'DE', $m['shipping']['country'] );

		// Własny kraj się nie zmienia; drugi dalej podąża za sklepem.
		Settings::update( array( 'merchant' => array( 'shipping' => array( 'country' => 'FR' ) ) ) );
		update_option( 'woocommerce_default_country', 'IT' );
		$m = Settings::get()['merchant'];
		$this->assertSame( 'FR', $m['shipping']['country'] );
		$this->assertSame( 'IT', $m['return_policy']['country'] );
	}

	public function merchant_invalid_provider(): array {
		return array(
			'fees enum'            => array( array( 'return_policy' => array( 'fees' => 'maybe' ) ), 'merchant.return_policy.fees' ),
			'method enum'          => array( array( 'return_policy' => array( 'method' => 'post' ) ), 'merchant.return_policy.method' ),
			'days too big'         => array( array( 'return_policy' => array( 'days' => 366 ) ), 'merchant.return_policy.days' ),
			'days negative'        => array( array( 'return_policy' => array( 'days' => -1 ) ), 'merchant.return_policy.days' ),
			'days string'          => array( array( 'return_policy' => array( 'days' => '14' ) ), 'merchant.return_policy.days' ),
			'days float'           => array( array( 'return_policy' => array( 'days' => 14.5 ) ), 'merchant.return_policy.days' ),
			'enabled not bool'     => array( array( 'return_policy' => array( 'enabled' => 'yes' ) ), 'merchant.return_policy.enabled' ),
			'shipping enabled int' => array( array( 'shipping' => array( 'enabled' => 1 ) ), 'merchant.shipping.enabled' ),
			'country lowercase'    => array( array( 'return_policy' => array( 'country' => 'pl' ) ), 'merchant.return_policy.country' ),
			'country long'         => array( array( 'shipping' => array( 'country' => 'POL' ) ), 'merchant.shipping.country' ),
			'country digits'       => array( array( 'shipping' => array( 'country' => 'P1' ) ), 'merchant.shipping.country' ),
			'country not string'   => array( array( 'shipping' => array( 'country' => 5 ) ), 'merchant.shipping.country' ),
			'cost three decimals'  => array( array( 'shipping' => array( 'cost' => '1.234' ) ), 'merchant.shipping.cost' ),
			'cost negative'        => array( array( 'shipping' => array( 'cost' => '-1' ) ), 'merchant.shipping.cost' ),
			'cost comma'           => array( array( 'shipping' => array( 'cost' => '1,5' ) ), 'merchant.shipping.cost' ),
			'cost letters'         => array( array( 'shipping' => array( 'cost' => 'abc' ) ), 'merchant.shipping.cost' ),
			'cost number type'     => array( array( 'shipping' => array( 'cost' => 12.5 ) ), 'merchant.shipping.cost' ),
			'cost too long'        => array( array( 'shipping' => array( 'cost' => '12345678.99' ) ), 'merchant.shipping.cost' ),
			'handling min>max'     => array( array( 'shipping' => array( 'handling_days' => array( 3, 1 ) ) ), 'merchant.shipping.handling_days' ),
			'handling too big'     => array( array( 'shipping' => array( 'handling_days' => array( 0, 31 ) ) ), 'merchant.shipping.handling_days' ),
			'handling negative'    => array( array( 'shipping' => array( 'handling_days' => array( -1, 2 ) ) ), 'merchant.shipping.handling_days' ),
			'handling wrong size'  => array( array( 'shipping' => array( 'handling_days' => array( 1 ) ) ), 'merchant.shipping.handling_days' ),
			'handling not list'    => array( array( 'shipping' => array( 'handling_days' => 2 ) ), 'merchant.shipping.handling_days' ),
			'handling string ints' => array( array( 'shipping' => array( 'handling_days' => array( '1', '2' ) ) ), 'merchant.shipping.handling_days' ),
			'transit min>max'      => array( array( 'shipping' => array( 'transit_days' => array( 5, 4 ) ) ), 'merchant.shipping.transit_days' ),
			'transit too big'      => array( array( 'shipping' => array( 'transit_days' => array( 1, 61 ) ) ), 'merchant.shipping.transit_days' ),
			'brand too long'       => array( array( 'default_brand' => str_repeat( 'a', 101 ) ), 'merchant.default_brand' ),
			'brand not string'     => array( array( 'default_brand' => array( 'x' ) ), 'merchant.default_brand' ),
			'return_policy scalar' => array( array( 'return_policy' => 'tak' ), 'merchant.return_policy' ),
			'shipping scalar'      => array( array( 'shipping' => 5 ), 'merchant.shipping' ),
			'unknown top key'      => array( array( 'retrun_policy' => array( 'days' => 5 ) ), 'merchant.retrun_policy' ),
			'unknown rp key'       => array( array( 'return_policy' => array( 'day' => 5 ) ), 'merchant.return_policy.day' ),
			'unknown shipping key' => array( array( 'shipping' => array( 'price' => '5.00' ) ), 'merchant.shipping.price' ),
		);
	}

	/**
	 * @dataProvider merchant_invalid_provider
	 */
	public function test_merchant_invalid_values_are_reported_and_not_stored( array $merchant, string $field ) {
		$before = Settings::get();
		$errors = Settings::merchant_errors( array( 'merchant' => $merchant ) );
		$this->assertArrayHasKey( $field, $errors );
		$this->assertStringContainsString( $field, $errors[ $field ] );
		// Nawet bez walidacji wstępnej zły kawałek nie trafia do ustawień.
		$after = Settings::update( array( 'merchant' => $merchant ) );
		$this->assertSame( $before['merchant'], $after['merchant'] );
	}

	public function test_merchant_boundary_values_are_valid() {
		$m = array(
			'default_brand' => str_repeat( 'a', 100 ),
			'return_policy' => array( 'days' => 0 ),
			'shipping'      => array( 'cost' => '0', 'handling_days' => array( 30, 30 ), 'transit_days' => array( 0, 60 ) ),
		);
		$this->assertSame( array(), Settings::merchant_errors( array( 'merchant' => $m ) ) );
		$m['return_policy']['days'] = 365;
		$m['shipping']['cost']      = '1234567.89';
		$this->assertSame( array(), Settings::merchant_errors( array( 'merchant' => $m ) ) );
	}

	public function test_unknown_key_next_to_valid_ones_blocks_via_errors_and_valid_siblings_listed_only_once() {
		$errors = Settings::merchant_errors( array( 'merchant' => array( 'default_brand' => 'Ok', 'shipping' => array( 'cost' => '5.00', 'extra' => 1 ) ) ) );
		$this->assertSame( array( 'merchant.shipping.extra' ), array_keys( $errors ) );
	}

	public function test_unknown_company_and_bots_keys_still_ignored() {
		$s = Settings::update( array( 'company' => array( 'nonsense' => 'x', 'phone' => '1' ), 'bots' => array( 'EvilBot' => 'block' ) ) );
		$this->assertSame( '1', $s['company']['phone'] );
		$this->assertArrayNotHasKey( 'EvilBot', $s['bots'] );
		$this->assertSame( array(), Settings::merchant_errors( array( 'company' => array( 'nonsense' => 'x' ) ) ) );
	}

	public function test_merchant_errors_without_merchant_key_is_empty() {
		$this->assertSame( array(), Settings::merchant_errors( array( 'company' => array( 'name' => 'x' ) ) ) );
	}

	public function test_merchant_invalid_part_does_not_block_valid_siblings_in_update() {
		$s = Settings::update( array( 'merchant' => array( 'return_policy' => array( 'days' => 999, 'fees' => 'free' ) ) ) );
		$this->assertSame( 14, $s['merchant']['return_policy']['days'] );
		$this->assertSame( 'free', $s['merchant']['return_policy']['fees'] );
	}

	public function test_02_defaults() {
		$s = Settings::get();
		$this->assertSame( array( 'home_description' => '', 'default_og_image' => '' ), $s['seo'] );
		$this->assertSame( str_replace( '_', '-', get_locale() ), $s['content_language'] );
		$this->assertFalse( $s['indexnow']['enabled'] );
		$this->assertSame( array( 'bing' => '', 'google' => '' ), $s['verification'] );
		$c = $s['company'];
		$this->assertSame( '', $c['description'] );
		$this->assertSame( '', $c['founding_date'] );
		$this->assertSame( array( 'telephone' => '', 'email' => '', 'contact_type' => 'customer service', 'languages' => array() ), $c['contact_point'] );
		$this->assertSame( array(), $c['opening_hours'] );
		$this->assertNull( $c['geo'] );
		$this->assertSame( '', $c['area_served'] );
	}

	public function test_content_language_stored_empty_follows_site_locale() {
		Settings::update( array( 'content_language' => 'de-DE' ) );
		$this->assertSame( 'de-DE', Settings::get()['content_language'] );
		$this->assertSame( 'en', Settings::update( array( 'content_language' => 'en' ) )['content_language'] );
		$this->assertSame( str_replace( '_', '-', get_locale() ), Settings::update( array( 'content_language' => '' ) )['content_language'] );
		$this->assertSame( '', get_option( Settings::OPTION )['content_language'] );
	}

	public function test_indexnow_key_generated_once_and_read_only() {
		delete_option( Settings::OPTION );
		$key = Settings::get()['indexnow']['key'];
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, Settings::get()['indexnow']['key'] );
		$this->assertSame( $key, get_option( Settings::OPTION )['indexnow']['key'] );

		$errors = Settings::partial_errors( array( 'indexnow' => array( 'key' => str_repeat( 'a', 32 ) ) ) );
		$this->assertArrayHasKey( 'indexnow.key', $errors );
		$s = Settings::update( array( 'indexnow' => array( 'key' => str_repeat( 'a', 32 ), 'enabled' => true ) ) );
		$this->assertSame( $key, $s['indexnow']['key'] );
		$this->assertSame( array(), Settings::partial_errors( array( 'indexnow' => array( 'key' => $key ) ) ) );
	}

	public function test_02_valid_partial_update_persists() {
		$partial = array(
			'seo'          => array( 'home_description' => str_repeat( 'a', 160 ), 'default_og_image' => 'https://example.com/og.jpg' ),
			'verification' => array( 'bing' => str_repeat( 'AB12', 8 ), 'google' => str_repeat( 'a', 20 ) ),
			'company'      => array(
				'description'   => str_repeat( 'a', 300 ),
				'founding_date' => '2020-02-29',
				'area_served'   => 'Polska',
				'contact_point' => array( 'telephone' => '+48 111', 'email' => 'a@example.com', 'contact_type' => 'customer service', 'languages' => array( 'pl', 'en' ) ),
				'opening_hours' => array( array( 'days' => array( 'Mo', 'Tu' ), 'opens' => '09:00', 'closes' => '17:30' ) ),
				'geo'           => array( 'lat' => 50.8, 'lng' => 16 ),
			),
		);
		$this->assertSame( array(), Settings::partial_errors( $partial ) );
		$s = Settings::update( $partial );
		$this->assertSame( 'https://example.com/og.jpg', $s['seo']['default_og_image'] );
		$this->assertSame( str_repeat( 'a', 20 ), $s['verification']['google'] );
		$this->assertSame( '2020-02-29', $s['company']['founding_date'] );
		$this->assertSame( array( 'pl', 'en' ), $s['company']['contact_point']['languages'] );
		$this->assertSame( array( 'lat' => 50.8, 'lng' => 16.0 ), $s['company']['geo'] );
		$this->assertSame( 'Polska', Settings::get()['company']['area_served'] );
		// Częściowy zapis nie rusza reszty; geo można wyczyścić.
		$s = Settings::update( array( 'company' => array( 'geo' => null ), 'seo' => array( 'home_description' => '' ) ) );
		$this->assertNull( $s['company']['geo'] );
		$this->assertSame( 'https://example.com/og.jpg', $s['seo']['default_og_image'] );
		$this->assertSame( 'a@example.com', $s['company']['contact_point']['email'] );
	}

	public function settings_02_invalid_provider(): array {
		$hours = static function ( array $entry ) {
			return array( 'company' => array( 'opening_hours' => array( $entry ) ) );
		};
		$ok    = array( 'days' => array( 'Mo' ), 'opens' => '09:00', 'closes' => '17:00' );
		return array(
			'seo not object'         => array( array( 'seo' => array( 1 ) ), 'seo' ),
			'seo desc 161'           => array( array( 'seo' => array( 'home_description' => str_repeat( 'a', 161 ) ) ), 'seo.home_description' ),
			'seo desc int'           => array( array( 'seo' => array( 'home_description' => 5 ) ), 'seo.home_description' ),
			'og not url'             => array( array( 'seo' => array( 'default_og_image' => 'nie url' ) ), 'seo.default_og_image' ),
			'og http'                => array( array( 'seo' => array( 'default_og_image' => 'http://example.com/a.jpg' ) ), 'seo.default_og_image' ),
			'lang uppercase'         => array( array( 'content_language' => 'PL' ), 'content_language' ),
			'lang region lower'      => array( array( 'content_language' => 'pl-pl' ), 'content_language' ),
			'lang locale'            => array( array( 'content_language' => 'pl_PL' ), 'content_language' ),
			'lang trailing newline'  => array( array( 'content_language' => "pl\n" ), 'content_language' ),
			'indexnow not object'    => array( array( 'indexnow' => true ), 'indexnow' ),
			'indexnow bad bool'      => array( array( 'indexnow' => array( 'enabled' => 1 ) ), 'indexnow.enabled' ),
			'indexnow unknown'       => array( array( 'indexnow' => array( 'enable' => true ) ), 'indexnow.enable' ),
			'bing lowercase'         => array( array( 'verification' => array( 'bing' => str_repeat( 'a', 32 ) ) ), 'verification.bing' ),
			'bing 33'                => array( array( 'verification' => array( 'bing' => str_repeat( 'A', 33 ) ) ), 'verification.bing' ),
			'bing trailing newline'  => array( array( 'verification' => array( 'bing' => str_repeat( 'A', 32 ) . "\n" ) ), 'verification.bing' ),
			'google trailing newline' => array( array( 'verification' => array( 'google' => str_repeat( 'a', 20 ) . "\n" ) ), 'verification.google' ),
			'google 19'              => array( array( 'verification' => array( 'google' => str_repeat( 'a', 19 ) ) ), 'verification.google' ),
			'google 101'             => array( array( 'verification' => array( 'google' => str_repeat( 'a', 101 ) ) ), 'verification.google' ),
			'google chars'           => array( array( 'verification' => array( 'google' => str_repeat( 'a', 19 ) . '!' ) ), 'verification.google' ),
			'verification unknown'   => array( array( 'verification' => array( 'yandex' => 'x' ) ), 'verification.yandex' ),
			'company desc 301'       => array( array( 'company' => array( 'description' => str_repeat( 'a', 301 ) ) ), 'company.description' ),
			'company date format'    => array( array( 'company' => array( 'founding_date' => '2020-1-1' ) ), 'company.founding_date' ),
			'company date invalid'   => array( array( 'company' => array( 'founding_date' => '2021-02-29' ) ), 'company.founding_date' ),
			'company date newline'   => array( array( 'company' => array( 'founding_date' => "2020-01-01\n" ) ), 'company.founding_date' ),
			'area 101'               => array( array( 'company' => array( 'area_served' => str_repeat( 'a', 101 ) ) ), 'company.area_served' ),
			'contact not object'     => array( array( 'company' => array( 'contact_point' => 'x' ) ), 'company.contact_point' ),
			'contact unknown'        => array( array( 'company' => array( 'contact_point' => array( 'fax' => '1' ) ) ), 'company.contact_point.fax' ),
			'contact email'          => array( array( 'company' => array( 'contact_point' => array( 'email' => 'nie-email' ) ) ), 'company.contact_point.email' ),
			'contact type'           => array( array( 'company' => array( 'contact_point' => array( 'contact_type' => 'sales' ) ) ), 'company.contact_point.contact_type' ),
			'contact langs 6'        => array( array( 'company' => array( 'contact_point' => array( 'languages' => array( 'a', 'b', 'c', 'd', 'e', 'f' ) ) ) ), 'company.contact_point.languages' ),
			'contact langs type'     => array( array( 'company' => array( 'contact_point' => array( 'languages' => array( 5 ) ) ) ), 'company.contact_point.languages' ),
			'hours not list'         => array( array( 'company' => array( 'opening_hours' => 'x' ) ), 'company.opening_hours' ),
			'hours 8 entries'        => array( array( 'company' => array( 'opening_hours' => array_fill( 0, 8, $ok ) ) ), 'company.opening_hours' ),
			'hours bad day'          => array( $hours( array_merge( $ok, array( 'days' => array( 'Mon' ) ) ) ), 'company.opening_hours' ),
			'hours empty days'       => array( $hours( array_merge( $ok, array( 'days' => array() ) ) ), 'company.opening_hours' ),
			'hours bad time'         => array( $hours( array_merge( $ok, array( 'opens' => '24:00' ) ) ), 'company.opening_hours' ),
			'hours bad time 2'       => array( $hours( array_merge( $ok, array( 'closes' => '9:00' ) ) ), 'company.opening_hours' ),
			'hours time newline'     => array( $hours( array_merge( $ok, array( 'opens' => "09:00\n" ) ) ), 'company.opening_hours' ),
			'hours missing key'      => array( $hours( array( 'days' => array( 'Mo' ), 'opens' => '09:00' ) ), 'company.opening_hours' ),
			'hours extra key'        => array( $hours( array_merge( $ok, array( 'note' => 'x' ) ) ), 'company.opening_hours' ),
			'geo lat'                => array( array( 'company' => array( 'geo' => array( 'lat' => 90.5, 'lng' => 0 ) ) ), 'company.geo' ),
			'geo lng'                => array( array( 'company' => array( 'geo' => array( 'lat' => 0, 'lng' => -181 ) ) ), 'company.geo' ),
			'geo missing'            => array( array( 'company' => array( 'geo' => array( 'lat' => 0 ) ) ), 'company.geo' ),
			'geo string'             => array( array( 'company' => array( 'geo' => array( 'lat' => '1', 'lng' => '2' ) ) ), 'company.geo' ),
		);
	}

	/**
	 * @dataProvider settings_02_invalid_provider
	 */
	public function test_02_invalid_values_are_reported_and_not_stored( array $partial, string $field ) {
		$before = Settings::get();
		$errors = Settings::partial_errors( $partial );
		$this->assertArrayHasKey( $field, $errors );
		$this->assertStringContainsString( $field, $errors[ $field ] );
		$after = Settings::update( $partial );
		$this->assertSame( $before, $after );
	}

	public function test_02_boundary_values_are_valid() {
		$partial = array(
			'seo'          => array( 'home_description' => str_repeat( 'ą', 160 ), 'default_og_image' => '' ),
			'verification' => array( 'bing' => '', 'google' => str_repeat( 'a', 100 ) ),
			'company'      => array(
				'description'   => str_repeat( 'a', 300 ),
				'opening_hours' => array_fill( 0, 7, array( 'days' => array( 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' ), 'opens' => '00:00', 'closes' => '23:59' ) ),
				'geo'           => array( 'lat' => -90, 'lng' => 180 ),
				'contact_point' => array( 'languages' => array() ),
				'founding_date' => '',
			),
		);
		$this->assertSame( array(), Settings::partial_errors( $partial ) );
	}

	public function test_corrupted_stored_02_settings_fall_back_to_defaults() {
		update_option( Settings::OPTION, array( 'seo' => 'x', 'indexnow' => 'zly', 'verification' => array( 'bing' => 'x' ), 'content_language' => 5, 'company' => array( 'geo' => 'x', 'opening_hours' => 'x' ) ) );
		$s = Settings::get();
		$this->assertSame( '', $s['seo']['home_description'] );
		$this->assertFalse( $s['indexnow']['enabled'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $s['indexnow']['key'] );
		$this->assertSame( '', $s['verification']['bing'] );
		$this->assertSame( str_replace( '_', '-', get_locale() ), $s['content_language'] );
		$this->assertNull( $s['company']['geo'] );
		$this->assertSame( array(), $s['company']['opening_hours'] );
	}

	public function test_corrupted_stored_merchant_falls_back_to_defaults() {
		update_option( Settings::OPTION, array( 'merchant' => array( 'return_policy' => array( 'days' => 'abc', 'fees' => 'x' ), 'shipping' => 'zly' ) ) );
		$m = Settings::get()['merchant'];
		$this->assertSame( 14, $m['return_policy']['days'] );
		$this->assertSame( 'customer', $m['return_policy']['fees'] );
		$this->assertSame( '0.00', $m['shipping']['cost'] );
	}
}
