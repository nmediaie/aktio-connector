<?php
class RestVisibilityTest extends WP_UnitTestCase {

	private function post( $body ) {
		$req = new WP_REST_Request( 'POST', '/aktio/v1/visibility' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	public function test_post_visibility_needs_manage_options_and_returns_settings() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->post( array( 'bots' => array( 'GPTBot' => 'block' ) ) )->get_status() );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/visibility' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$res = $this->post( array( 'bots' => array( 'GPTBot' => 'block' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( array( 'features', 'seo_plugins', 'settings' ), array_keys( $data ) );
		$this->assertSame( array( 'company', 'bots', 'merchant' ), array_keys( $data['settings'] ) );
		$this->assertSame( 'block', $data['settings']['bots']['GPTBot'] );
		$this->assertSame( 'allow', $data['settings']['bots']['ClaudeBot'] );

		$get = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/visibility' ) )->get_data();
		$this->assertSame( 'block', $get['settings']['bots']['GPTBot'] );
	}

	public function test_post_visibility_updates_company_partially() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data = $this->post( array( 'company' => array( 'name' => 'Acme' ) ) )->get_data();
		$this->assertSame( 'Acme', $data['settings']['company']['name'] );
		$this->assertSame( 'allow', $data['settings']['bots']['GPTBot'] );
	}

	public function test_post_visibility_rejects_non_object_body() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( array( 1, 2 ), 'tekst', 5, array( 'bots' => 'x' ) ) as $body ) {
			$res = $this->post( $body );
			$this->assertSame( 400, $res->get_status() );
			$this->assertSame( 'aktio_invalid_params', $res->get_data()['code'] );
		}
	}

	public function test_get_visibility_returns_full_merchant_with_defaults() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$m = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/visibility' ) )->get_data()['settings']['merchant'];
		$this->assertSame( array( 'default_brand', 'return_policy', 'shipping' ), array_keys( $m ) );
		$this->assertFalse( $m['return_policy']['enabled'] );
		$this->assertSame( 14, $m['return_policy']['days'] );
		$this->assertSame( array( 0, 1 ), $m['shipping']['handling_days'] );
		$this->assertSame( array( 1, 3 ), $m['shipping']['transit_days'] );
	}

	public function test_post_visibility_updates_merchant_partially() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post( array( 'merchant' => array( 'default_brand' => 'Marka', 'return_policy' => array( 'enabled' => true, 'country' => 'PL' ) ) ) );
		$res = $this->post( array( 'merchant' => array( 'return_policy' => array( 'days' => 30 ), 'shipping' => array( 'cost' => '5.00' ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$m = $res->get_data()['settings']['merchant'];
		$this->assertSame( 'Marka', $m['default_brand'] );
		$this->assertTrue( $m['return_policy']['enabled'] );
		$this->assertSame( 30, $m['return_policy']['days'] );
		$this->assertSame( 'PL', $m['return_policy']['country'] );
		$this->assertSame( '5.00', $m['shipping']['cost'] );
	}

	public function merchant_bad_body_provider(): array {
		return array(
			'merchant scalar'    => array( array( 'merchant' => 'x' ), 'merchant' ),
			'merchant list'      => array( array( 'merchant' => array( 1, 2 ) ), 'merchant' ),
			'bad enum'           => array( array( 'merchant' => array( 'return_policy' => array( 'fees' => 'maybe' ) ) ), 'merchant.return_policy.fees' ),
			'days out of range'  => array( array( 'merchant' => array( 'return_policy' => array( 'days' => 400 ) ) ), 'merchant.return_policy.days' ),
			'min>max'            => array( array( 'merchant' => array( 'shipping' => array( 'transit_days' => array( 5, 2 ) ) ) ), 'merchant.shipping.transit_days' ),
			'bad country'        => array( array( 'merchant' => array( 'shipping' => array( 'country' => 'Polska' ) ) ), 'merchant.shipping.country' ),
			'bad cost'           => array( array( 'merchant' => array( 'shipping' => array( 'cost' => '12,5 zl' ) ) ), 'merchant.shipping.cost' ),
			'unknown top key'    => array( array( 'merchant' => array( 'retrun_policy' => array( 'days' => 5 ) ) ), 'merchant.retrun_policy' ),
			'unknown nested key' => array( array( 'merchant' => array( 'shipping' => array( 'costt' => '5.00' ) ) ), 'merchant.shipping.costt' ),
			'bad type'           => array( array( 'merchant' => array( 'return_policy' => array( 'enabled' => 'true' ) ) ), 'merchant.return_policy.enabled' ),
		);
	}

	/**
	 * @dataProvider merchant_bad_body_provider
	 */
	public function test_post_visibility_rejects_bad_merchant_and_changes_nothing( array $body, string $field ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->post( array( 'merchant' => array( 'default_brand' => 'Stara' ) ) );
		$before = get_option( \AktioConnector\Settings::OPTION );
		// Ważna zmiana firmy w tym samym żądaniu też nie może przejść (całość atomowo).
		$body['company'] = array( 'name' => 'Nie zapisuj' );
		$res             = $this->post( $body );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'aktio_invalid_params', $res->get_data()['code'] );
		$this->assertStringContainsString( $field, $res->get_data()['message'] );
		$this->assertSame( $before, get_option( \AktioConnector\Settings::OPTION ) );
	}

	public function test_post_visibility_unknown_company_key_still_ignored() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$res = $this->post( array( 'company' => array( 'nonsense' => 'x', 'name' => 'Acme' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Acme', $res->get_data()['settings']['company']['name'] );
	}

	public function test_get_visibility_shows_effective_country_following_store() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'woocommerce_default_country', 'PL:DS' );
		$this->post( array( 'bots' => array( 'GPTBot' => 'block' ) ) );
		update_option( 'woocommerce_default_country', 'DE' );
		$m = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/visibility' ) )->get_data()['settings']['merchant'];
		$this->assertSame( 'DE', $m['return_policy']['country'] );
		$this->assertSame( 'DE', $m['shipping']['country'] );
	}
}
