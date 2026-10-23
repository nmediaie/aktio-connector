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

	public function test_merchant_can_clear_brand_and_country() {
		Settings::update( array( 'merchant' => array( 'default_brand' => 'X', 'return_policy' => array( 'country' => 'PL' ) ) ) );
		$s = Settings::update( array( 'merchant' => array( 'default_brand' => '', 'return_policy' => array( 'country' => '' ) ) ) );
		$this->assertSame( '', $s['merchant']['default_brand'] );
		$this->assertSame( '', $s['merchant']['return_policy']['country'] );
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

	public function test_merchant_errors_without_merchant_key_is_empty() {
		$this->assertSame( array(), Settings::merchant_errors( array( 'company' => array( 'name' => 'x' ) ) ) );
	}

	public function test_merchant_invalid_part_does_not_block_valid_siblings_in_update() {
		$s = Settings::update( array( 'merchant' => array( 'return_policy' => array( 'days' => 999, 'fees' => 'free' ) ) ) );
		$this->assertSame( 14, $s['merchant']['return_policy']['days'] );
		$this->assertSame( 'free', $s['merchant']['return_policy']['fees'] );
	}

	public function test_corrupted_stored_merchant_falls_back_to_defaults() {
		update_option( Settings::OPTION, array( 'merchant' => array( 'return_policy' => array( 'days' => 'abc', 'fees' => 'x' ), 'shipping' => 'zly' ) ) );
		$m = Settings::get()['merchant'];
		$this->assertSame( 14, $m['return_policy']['days'] );
		$this->assertSame( 'customer', $m['return_policy']['fees'] );
		$this->assertSame( '0.00', $m['shipping']['cost'] );
	}
}
