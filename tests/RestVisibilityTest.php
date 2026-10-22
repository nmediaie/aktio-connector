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
		$this->assertSame( array( 'company', 'bots' ), array_keys( $data['settings'] ) );
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
}
