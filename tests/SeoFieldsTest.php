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

	private function post_fields( string $title, string $description ): void {
		$_POST['aktio_seo_title']       = $title;
		$_POST['aktio_seo_description'] = $description;
		$_POST['aktio_seo_nonce']       = wp_create_nonce( Fields::NONCE_ACTION );
	}

	public function test_quick_edit_column_box_and_save() {
		$id = self::factory()->post->create();
		update_post_meta( $id, '_aktio_seo_title', 'Stary "tytuł"' );

		$columns = apply_filters( 'manage_post_posts_columns', array( 'title' => 'Tytuł' ) );
		$this->assertArrayHasKey( 'aktio_seo', $columns );

		ob_start();
		do_action( 'manage_post_posts_custom_column', 'aktio_seo', $id );
		$cell = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-title="Stary &quot;tytuł&quot;"', $cell );
		$this->assertStringContainsString( 'data-description=""', $cell );

		ob_start();
		do_action( 'quick_edit_custom_box', 'aktio_seo', 'post', '' );
		$box = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="aktio_seo_title"', $box );
		$this->assertStringContainsString( 'name="aktio_seo_description"', $box );
		$this->assertStringContainsString( 'name="aktio_seo_nonce"', $box );

		ob_start();
		do_action( 'quick_edit_custom_box', 'categories', 'post', '' );
		do_action( 'quick_edit_custom_box', 'aktio_seo', 'attachment', '' );
		$this->assertSame( '', (string) ob_get_clean() );

		// Zapis szybkiej edycji idzie przez save_post z nonce z panelu.
		$this->post_fields( 'Z szybkiej edycji', 'Opis z szybkiej edycji' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Nowy tytuł wpisu' ) );
		$this->assertSame( 'Z szybkiej edycji', get_post_meta( $id, '_aktio_seo_title', true ) );
		$this->assertSame( 'Opis z szybkiej edycji', get_post_meta( $id, '_aktio_seo_description', true ) );

		// Pusta wartość usuwa pole; brak pól w żądaniu (np. edycja zbiorcza) niczego nie rusza.
		$this->post_fields( '', 'Opis z szybkiej edycji' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Jeszcze inny' ) );
		$this->assertSame( '', get_post_meta( $id, '_aktio_seo_title', true ) );
		unset( $_POST['aktio_seo_title'], $_POST['aktio_seo_description'] );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Bez pól' ) );
		$this->assertSame( 'Opis z szybkiej edycji', get_post_meta( $id, '_aktio_seo_description', true ) );
	}

	public function test_quick_edit_hidden_by_default_and_absent_with_foreign_seo_plugin() {
		set_current_screen( 'edit-post' );
		$hidden = apply_filters( 'default_hidden_columns', array(), get_current_screen() );
		$this->assertContains( 'aktio_seo', $hidden );

		$this->active = true;
		$this->assertArrayNotHasKey( 'aktio_seo', apply_filters( 'manage_post_posts_columns', array() ) );
		ob_start();
		do_action( 'quick_edit_custom_box', 'aktio_seo', 'post', '' );
		$this->assertSame( '', (string) ob_get_clean() );

		$id = self::factory()->post->create();
		$this->post_fields( 'Obcy', 'Obcy opis' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'X' ) );
		$this->assertSame( '', get_post_meta( $id, '_aktio_seo_title', true ) );
	}

	public function test_quick_edit_script_enqueued_only_on_owned_post_list() {
		wp_dequeue_script( 'aktio-seo-quickedit' );
		set_current_screen( 'edit-post' );
		Fields::enqueue_quick_edit( 'post.php' );
		$this->assertFalse( wp_script_is( 'aktio-seo-quickedit', 'enqueued' ) );
		Fields::enqueue_quick_edit( 'edit.php' );
		$this->assertTrue( wp_script_is( 'aktio-seo-quickedit', 'enqueued' ) );

		wp_dequeue_script( 'aktio-seo-quickedit' );
		$this->active = true;
		Fields::enqueue_quick_edit( 'edit.php' );
		$this->assertFalse( wp_script_is( 'aktio-seo-quickedit', 'enqueued' ) );
		$this->assertFileExists( dirname( __DIR__ ) . '/assets/aktio-seo-quickedit.js' );
	}

	public function test_term_screens_render_and_save() {
		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$term_id = self::factory()->term->create( array( 'taxonomy' => $taxonomy, 'name' => 'Termin ' . $taxonomy ) );
			update_term_meta( $term_id, '_aktio_seo_title', 'Tytuł "terminu"' );

			ob_start();
			do_action( $taxonomy . '_edit_form_fields', get_term( $term_id ), $taxonomy );
			$edit = (string) ob_get_clean();
			$this->assertStringContainsString( 'value="Tytuł &quot;terminu&quot;"', $edit, $taxonomy );
			$this->assertStringContainsString( 'name="aktio_seo_description"', $edit, $taxonomy );
			$this->assertStringContainsString( 'name="aktio_seo_nonce"', $edit, $taxonomy );

			ob_start();
			do_action( $taxonomy . '_add_form_fields', $taxonomy );
			$add = (string) ob_get_clean();
			$this->assertStringContainsString( 'name="aktio_seo_title"', $add, $taxonomy );

			// Bez nonce — bez zapisu; z nonce — zapis, pusta wartość kasuje.
			unset( $_POST['aktio_seo_nonce'] );
			$_POST['aktio_seo_title']       = 'Bez nonce';
			$_POST['aktio_seo_description'] = 'Opis terminu';
			wp_update_term( $term_id, $taxonomy, array( 'name' => 'Zmieniony ' . $taxonomy ) );
			$this->assertSame( 'Tytuł "terminu"', get_term_meta( $term_id, '_aktio_seo_title', true ), $taxonomy );

			$this->post_fields( 'Nowy tytuł terminu', 'Opis terminu' );
			wp_update_term( $term_id, $taxonomy, array( 'name' => 'Zmieniony 2 ' . $taxonomy ) );
			$this->assertSame( 'Nowy tytuł terminu', get_term_meta( $term_id, '_aktio_seo_title', true ), $taxonomy );
			$this->assertSame( 'Opis terminu', get_term_meta( $term_id, '_aktio_seo_description', true ), $taxonomy );

			$this->post_fields( '', 'Opis terminu' );
			wp_update_term( $term_id, $taxonomy, array( 'name' => 'Zmieniony 3 ' . $taxonomy ) );
			$this->assertSame( '', get_term_meta( $term_id, '_aktio_seo_title', true ), $taxonomy );
		}
	}

	public function test_term_creation_saves_fields_from_add_form() {
		$this->post_fields( 'Tytuł nowej kategorii', 'Opis nowej kategorii' );
		$term = wp_insert_term( 'Nowa kategoria SEO', 'category' );
		$this->assertSame( 'Tytuł nowej kategorii', get_term_meta( $term['term_id'], '_aktio_seo_title', true ) );
		$this->assertSame( 'Opis nowej kategorii', get_term_meta( $term['term_id'], '_aktio_seo_description', true ) );
	}

	public function test_term_save_requires_capability_and_ownership() {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->post_fields( 'Subskrybent', 'Opis' );
		Fields::save_term( $term_id );
		$this->assertSame( '', get_term_meta( $term_id, '_aktio_seo_title', true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->active = true;
		$this->post_fields( 'Obca wtyczka', 'Opis' );
		Fields::save_term( $term_id );
		$this->assertSame( '', get_term_meta( $term_id, '_aktio_seo_title', true ) );

		ob_start();
		do_action( 'category_edit_form_fields', get_term( $term_id ), 'category' );
		do_action( 'category_add_form_fields', 'category' );
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
