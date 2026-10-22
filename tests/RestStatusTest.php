<?php
class RestStatusTest extends WP_UnitTestCase {

	public function test_status_requires_edit_posts_and_returns_contract_keys() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/status' ) )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/status' ) )->get_data();
		$this->assertSame( array( 'version', 'woocommerce', 'features', 'seo_plugins', 'company', 'update_log' ), array_keys( $data ) );
	}

	public function test_status_stub_values() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/status' ) )->get_data();
		$this->assertSame( AKTIO_CONNECTOR_VERSION, $data['version'] );
		$this->assertSame( array( 'content_schema', 'product_schema', 'robots', 'llms' ), array_keys( $data['features'] ) );
		$this->assertSame( array( 'state' => 'off', 'provided_by' => null ), $data['features']['robots'] );
		$this->assertSame( array(), $data['seo_plugins'] );
		$this->assertSame( array(), $data['update_log'] );
	}

	public function test_permission_accepts_any_of_caps() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertTrue( \AktioConnector\Rest::permission( 'manage_options', 'edit_posts' )() );
		$this->assertInstanceOf( WP_Error::class, \AktioConnector\Rest::permission( 'manage_options' )() );
	}
}
