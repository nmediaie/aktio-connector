<?php
class CatalogTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function get( string $route, array $params = array() ) {
		$req = new WP_REST_Request( 'GET', $route );
		$req->set_query_params( $params );
		return rest_do_request( $req );
	}

	private function post_alt( $items ) {
		$req = new WP_REST_Request( 'POST', '/aktio/v1/media/alt' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'items' => $items ) ) );
		return rest_do_request( $req );
	}

	private function image( string $alt = '', int $parent = 0 ): int {
		$id = self::factory()->attachment->create_object(
			array(
				'file'           => 'obraz-' . wp_generate_password( 6, false ) . '.jpg',
				'post_title'     => 'Obraz testowy',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $parent,
			)
		);
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return $id;
	}

	public function test_scan_counts_images_and_missing_alt() {
		$main    = $this->image( 'Z ALT' );
		$gal1    = $this->image();
		$gal2    = $this->image( 'Galeria' );
		$product = new WC_Product_Simple();
		$product->set_name( 'Kubek' );
		$product->set_sku( 'KUB-1' );
		$product->set_weight( '0.5' );
		$product->set_short_description( 'abc' );
		$product->set_description( 'abcdef' );
		$product->set_image_id( $main );
		$product->set_gallery_image_ids( array( $gal1, $gal2 ) );
		$product->save();

		$data = $this->get( '/aktio/v1/products/scan', array( 'per_page' => 100 ) )->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 1, $data['total_pages'] );
		$this->assertArrayHasKey( 'brands_supported', $data );
		$item = $data['items'][0];
		$this->assertSame( array( 'id', 'name', 'brands', 'images', 'images_missing_alt', 'short_len', 'desc_len', 'sku', 'gtin', 'weight', 'categories' ), array_keys( $item ) );
		$this->assertSame( 'Kubek', $item['name'] );
		$this->assertSame( 3, $item['images'] );
		$this->assertSame( 1, $item['images_missing_alt'] );
		$this->assertSame( 3, $item['short_len'] );
		$this->assertSame( 6, $item['desc_len'] );
		$this->assertSame( 'KUB-1', $item['sku'] );
		$this->assertSame( '0.5', $item['weight'] );
		$this->assertSame( array(), $item['brands'] );
	}

	public function test_scan_brands_supported_follows_taxonomy() {
		$data = $this->get( '/aktio/v1/products/scan' )->get_data();
		$this->assertSame( taxonomy_exists( 'product_brand' ), $data['brands_supported'] );

		if ( taxonomy_exists( 'product_brand' ) ) {
			$taxonomy = get_taxonomy( 'product_brand' );
			unregister_taxonomy( 'product_brand' );
			$supported = $this->get( '/aktio/v1/products/scan' )->get_data()['brands_supported'];
			register_taxonomy( 'product_brand', $taxonomy->object_type, (array) $taxonomy ); // Przywraca taksonomię dla pozostałych testów.
			$this->assertFalse( $supported );
			$this->assertTrue( taxonomy_exists( 'product_brand' ) );
		}
	}

	public function test_scan_requires_edit_products() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->get( '/aktio/v1/products/scan' )->get_status() );
	}

	public function test_missing_alt_skips_images_with_alt_and_non_images() {
		$parent = self::factory()->post->create( array( 'post_title' => 'Wpis' ) );
		$bare   = $this->image( '', $parent );
		$this->image( 'Ma ALT' );
		self::factory()->attachment->create_object(
			array(
				'file'           => 'dokument.pdf',
				'post_mime_type' => 'application/pdf',
			)
		);

		$data = $this->get( '/aktio/v1/media/missing-alt', array( 'per_page' => 100 ) )->get_data();
		$this->assertSame( 1, $data['total'] );
		$item = $data['items'][0];
		$this->assertSame( $bare, $item['id'] );
		$this->assertSame( array( 'id', 'url_thumb', 'title', 'filename', 'attached_to' ), array_keys( $item ) );
		$this->assertSame( 'Wpis', $item['attached_to']['title'] );
		$this->assertSame( $parent, $item['attached_to']['id'] );
		$this->assertSame( 'post', $item['attached_to']['type'] );
	}

	public function test_missing_alt_requires_upload_files() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->get( '/aktio/v1/media/missing-alt' )->get_status() );
	}

	public function test_alt_batch_saves_with_previous_and_reports_missing_id() {
		$a = $this->image( 'Stary' );
		$b = $this->image();

		$res = $this->post_alt(
			array(
				array( 'id' => $a, 'alt' => '<b>Nowy</b> opis' ),
				array( 'id' => 999999, 'alt' => 'x' ),
				array( 'id' => $b, 'alt' => 'Drugi' ),
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$r = $res->get_data()['results'];
		$this->assertSame( array( 'id' => $a, 'ok' => true, 'previous' => 'Stary' ), $r[0] );
		$this->assertSame( 999999, $r[1]['id'] );
		$this->assertFalse( $r[1]['ok'] );
		$this->assertSame( 'aktio_not_found', $r[1]['error'] );
		$this->assertSame( array( 'id' => $b, 'ok' => true, 'previous' => '' ), $r[2] );
		$this->assertSame( 'Nowy opis', get_post_meta( $a, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Drugi', get_post_meta( $b, '_wp_attachment_image_alt', true ) );
	}

	public function test_alt_batch_rejects_over_50_and_bad_shape() {
		$items = array();
		for ( $i = 1; $i <= 51; $i++ ) {
			$items[] = array( 'id' => $i, 'alt' => 'x' );
		}
		$res = $this->post_alt( $items );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'aktio_invalid_params', $res->get_data()['code'] );

		$this->assertSame( 400, $this->post_alt( 'x' )->get_status() );
	}

	public function test_alt_batch_rejects_too_long_alt_per_item() {
		$a   = $this->image();
		$res = $this->post_alt( array( array( 'id' => $a, 'alt' => str_repeat( 'a', 301 ) ) ) );
		$r   = $res->get_data()['results'][0];
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'aktio_invalid_params', $r['error'] );
		$this->assertSame( '', get_post_meta( $a, '_wp_attachment_image_alt', true ) );
	}

	public function test_alt_batch_requires_upload_files_and_edit_post_per_item() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->post_alt( array( array( 'id' => 1, 'alt' => 'x' ) ) )->get_status() );

		// Autor może wgrywać, ale nie edytuje cudzych mediów.
		$other = $this->image();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$r = $this->post_alt( array( array( 'id' => $other, 'alt' => 'x' ) ) )->get_data()['results'][0];
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'rest_forbidden', $r['error'] );
		$this->assertSame( '', get_post_meta( $other, '_wp_attachment_image_alt', true ) );
	}
}
