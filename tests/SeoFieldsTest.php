<?php
use AktioConnector\Seo\Fields;

class SeoFieldsTest extends WP_UnitTestCase {

	private $active = false;

	public function set_up() {
		parent::set_up();
		$this->active = false;
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		add_filter( 'aktio_connector_seo_map', array( $this, 'filter_map' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Biblioteka testowa czyści zarejestrowane meta po każdym teście — rejestrujemy ponownie.
		Fields::register_meta();
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		remove_filter( 'aktio_connector_seo_map', array( $this, 'filter_map' ) );
		unset( $_POST['aktio_seo_title'], $_POST['aktio_seo_description'], $_POST['aktio_seo_nonce'] );
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active ? array( 'fake-seo/fake.php' ) : array();
	}

	public function filter_map( $map ) {
		if ( $this->active ) {
			$map[] = array(
				'name'     => 'Fake SEO',
				'files'    => array( 'fake-seo/fake.php' ),
				'provides' => array(
					'title'            => true,
					'meta_description' => true,
				),
			);
		}
		return $map;
	}

	private function rest( string $method, string $route, array $body ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_meta_registered_for_post_types_and_taxonomies() {
		foreach ( array( 'post', 'page', 'product' ) as $type ) {
			foreach ( array( '_aktio_seo_title', '_aktio_seo_description' ) as $key ) {
				$this->assertTrue( registered_meta_key_exists( 'post', $key, $type ), "$type $key" );
			}
		}
		foreach ( array( 'category', 'post_tag', 'product_cat', 'product_tag' ) as $tax ) {
			foreach ( array( '_aktio_seo_title', '_aktio_seo_description' ) as $key ) {
				$this->assertTrue( registered_meta_key_exists( 'term', $key, $tax ), "$tax $key" );
			}
		}
	}

	public function test_getters_for_post_and_term() {
		$id = self::factory()->post->create();
		$this->assertSame( '', Fields::title_for( get_post( $id ) ) );
		update_post_meta( $id, '_aktio_seo_title', 'Tytuł' );
		update_post_meta( $id, '_aktio_seo_description', 'Opis' );
		$this->assertSame( 'Tytuł', Fields::title_for( get_post( $id ) ) );
		$this->assertSame( 'Opis', Fields::description_for( get_post( $id ) ) );

		$term = self::factory()->category->create_and_get();
		update_term_meta( $term->term_id, '_aktio_seo_title', 'Tytuł kategorii' );
		update_term_meta( $term->term_id, '_aktio_seo_description', 'Opis kategorii' );
		$this->assertSame( 'Tytuł kategorii', Fields::title_for( $term ) );
		$this->assertSame( 'Opis kategorii', Fields::description_for( $term ) );
	}

	public function test_rest_post_saves_and_trims_to_70_and_160() {
		$id  = self::factory()->post->create();
		$res = $this->rest(
			'POST',
			'/wp/v2/posts/' . $id,
			array(
				'meta' => array(
					'_aktio_seo_title'       => str_repeat( 'a', 100 ),
					'_aktio_seo_description' => str_repeat( 'b', 300 ),
				),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( str_repeat( 'a', 70 ), get_post_meta( $id, '_aktio_seo_title', true ) );
		$this->assertSame( str_repeat( 'b', 160 ), get_post_meta( $id, '_aktio_seo_description', true ) );
	}

	public function test_rest_post_blocked_when_seo_plugin_active() {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_aktio_seo_title', 'Stary' );
		$this->active = true;
		$res          = $this->rest( 'POST', '/wp/v2/posts/' . $id, array( 'meta' => array( '_aktio_seo_title' => 'Nowy' ) ) );
		$this->assertTrue( $res->is_error() );
		$this->assertSame( 'aktio_seo_owned_elsewhere', $res->as_error()->get_error_code() );
		$this->assertSame( 'Stary', get_post_meta( $id, '_aktio_seo_title', true ) );

		// Zapis bez naszych pól nadal działa.
		$res = $this->rest( 'POST', '/wp/v2/posts/' . $id, array( 'title' => 'Zmieniony' ) );
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_rest_term_saves_and_is_blocked() {
		$term = self::factory()->category->create_and_get();
		$res  = $this->rest( 'POST', '/wp/v2/categories/' . $term->term_id, array( 'meta' => array( '_aktio_seo_description' => 'Opis' ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'Opis', get_term_meta( $term->term_id, '_aktio_seo_description', true ) );

		$this->active = true;
		$res          = $this->rest( 'POST', '/wp/v2/categories/' . $term->term_id, array( 'meta' => array( '_aktio_seo_description' => 'Inny' ) ) );
		$this->assertSame( 'aktio_seo_owned_elsewhere', $res->as_error()->get_error_code() );
		$this->assertSame( 'Opis', get_term_meta( $term->term_id, '_aktio_seo_description', true ) );
	}

	public function test_wc_product_meta_data_saves_or_is_blocked() {
		$p = new WC_Product_Simple();
		$p->set_name( 'Kubek' );
		$p->save();
		$route = '/wc/v3/products/' . $p->get_id();
		$body  = array( 'meta_data' => array( array( 'key' => '_aktio_seo_title', 'value' => str_repeat( 'x', 100 ) ) ) );

		$res = $this->rest( 'PUT', $route, $body );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( str_repeat( 'x', 70 ), get_post_meta( $p->get_id(), '_aktio_seo_title', true ) );

		$this->active                  = true;
		$body['meta_data'][0]['value'] = 'Nowy';
		$res                           = $this->rest( 'PUT', $route, $body );
		$this->assertTrue( $res->is_error() );
		$this->assertSame( 'aktio_seo_owned_elsewhere', $res->as_error()->get_error_code() );
		$this->assertSame( str_repeat( 'x', 70 ), get_post_meta( $p->get_id(), '_aktio_seo_title', true ) );
	}

	public function test_classic_meta_box_saves_with_nonce_only() {
		$id                             = self::factory()->post->create();
		$_POST['aktio_seo_title']       = 'Z boksu';
		$_POST['aktio_seo_description'] = 'Opis z boksu';

		// Bez nonce — bez zapisu.
		Fields::save_box( $id, get_post( $id ) );
		$this->assertSame( '', get_post_meta( $id, '_aktio_seo_title', true ) );

		$_POST['aktio_seo_nonce'] = wp_create_nonce( Fields::NONCE_ACTION );
		Fields::save_box( $id, get_post( $id ) );
		$this->assertSame( 'Z boksu', get_post_meta( $id, '_aktio_seo_title', true ) );
		$this->assertSame( 'Opis z boksu', get_post_meta( $id, '_aktio_seo_description', true ) );

		// Przy cudzej wtyczce SEO boks nic nie zapisuje.
		$this->active             = true;
		$_POST['aktio_seo_title'] = 'Inny';
		Fields::save_box( $id, get_post( $id ) );
		$this->assertSame( 'Z boksu', get_post_meta( $id, '_aktio_seo_title', true ) );
	}

	public function test_box_and_panel_only_when_aktio_owns() {
		set_current_screen( 'post' );
		get_current_screen()->is_block_editor = false;

		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		Fields::add_box( 'post' );
		$this->assertArrayHasKey( 'aktio_seo', $wp_meta_boxes['post']['normal']['default'] );

		$wp_meta_boxes = array();
		$this->active  = true;
		Fields::add_box( 'post' );
		$this->assertEmpty( $wp_meta_boxes );

		// W edytorze blokowym boksu klasycznego nie ma (jest panel).
		$this->active                         = false;
		get_current_screen()->is_block_editor = true;
		$wp_meta_boxes                        = array();
		Fields::add_box( 'post' );
		$this->assertEmpty( $wp_meta_boxes );

		$this->active = true;
		wp_dequeue_script( 'aktio-seo-panel' );
		Fields::enqueue_panel();
		$this->assertFalse( wp_script_is( 'aktio-seo-panel', 'enqueued' ) );
	}
}
