<?php
use AktioConnector\IndexNow;
use AktioConnector\Multilang;
use AktioConnector\Settings;

/**
 * Wielojęzyczność: status, skan katalogu per język i błąd bez wtyczki językowej.
 * WPML jest imitowany filtrami wpml_* (bez definiowania klasy SitePress, żeby nie psuć innych testów).
 */
class MultilangTest extends WP_UnitTestCase {

	/** @var array<int,string> ID produktu => język (imitacja tabeli tłumaczeń WPML). */
	private $lang_of = array();
	private $current = 'all';

	public function set_up() {
		parent::set_up();
		$this->lang_of = array();
		$this->current = 'all';
		add_filter( 'locale', array( $this, 'polish_locale' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		$this->deactivate_wpml();
		remove_filter( 'locale', array( $this, 'polish_locale' ) );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function polish_locale() {
		return 'pl_PL';
	}

	public function wpml_plugin() {
		return 'wpml';
	}

	public function wpml_languages() {
		return array(
			'pl' => array( 'language_code' => 'pl', 'native_name' => 'Polski' ),
			'en' => array( 'language_code' => 'en', 'native_name' => 'English' ),
		);
	}

	public function wpml_default() {
		return 'pl';
	}

	public function wpml_post_language( $details, $post_id ) {
		return isset( $this->lang_of[ $post_id ] ) ? array( 'language_code' => $this->lang_of[ $post_id ] ) : $details;
	}

	public function wpml_switch( $code ) {
		$this->current = null === $code ? 'all' : $code;
	}

	public function wpml_filter_where( $where ) {
		if ( 'all' === $this->current ) {
			return $where;
		}
		$ids = array_keys( array_filter( $this->lang_of, fn( $l ) => $l === $this->current ) );
		return $where . ' AND wp_posts.ID IN (' . ( $ids ? implode( ',', array_map( 'intval', $ids ) ) : '0' ) . ')';
	}

	private function activate_wpml(): void {
		add_filter( 'aktio_connector_multilang_plugin', array( $this, 'wpml_plugin' ) );
		add_filter( 'wpml_active_languages', array( $this, 'wpml_languages' ) );
		add_filter( 'wpml_default_language', array( $this, 'wpml_default' ) );
		add_filter( 'wpml_post_language_details', array( $this, 'wpml_post_language' ), 10, 2 );
		add_action( 'wpml_switch_language', array( $this, 'wpml_switch' ) );
		add_filter( 'posts_where', array( $this, 'wpml_filter_where' ) );
	}

	private function deactivate_wpml(): void {
		remove_filter( 'aktio_connector_multilang_plugin', array( $this, 'wpml_plugin' ) );
		remove_filter( 'wpml_active_languages', array( $this, 'wpml_languages' ) );
		remove_filter( 'wpml_default_language', array( $this, 'wpml_default' ) );
		remove_filter( 'wpml_post_language_details', array( $this, 'wpml_post_language' ), 10 );
		remove_action( 'wpml_switch_language', array( $this, 'wpml_switch' ) );
		remove_filter( 'posts_where', array( $this, 'wpml_filter_where' ) );
	}

	private function product( string $name, string $lang ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$id               = $product->save();
		$this->lang_of[ $id ] = $lang;
		return $id;
	}

	private function get( string $route, array $params = array() ) {
		$req = new WP_REST_Request( 'GET', $route );
		$req->set_query_params( $params );
		return rest_do_request( $req );
	}

	public function test_status_without_multilingual_plugin() {
		$data = $this->get( '/aktio/v1/status' )->get_data();
		$this->assertNull( $data['multilingual'] );
		$this->assertCount( 1, $data['languages'] );
		$this->assertSame( 'pl', $data['languages'][0]['code'] );
		$this->assertArrayHasKey( 'label', $data['languages'][0] );
		// Stare pola kontraktu zostają.
		$this->assertArrayHasKey( 'content_language', $data );
		$this->assertArrayHasKey( 'language_warning', $data );
	}

	public function test_status_with_wpml_lists_languages() {
		$this->activate_wpml();
		$data = $this->get( '/aktio/v1/status' )->get_data();
		$this->assertSame(
			array(
				array( 'code' => 'pl', 'label' => 'Polski' ),
				array( 'code' => 'en', 'label' => 'English' ),
			),
			$data['languages']
		);
		$this->assertSame(
			array( 'plugin' => 'wpml', 'langs_count' => 2, 'default_lang' => 'pl' ),
			$data['multilingual']
		);
	}

	public function test_scan_with_wpml_marks_product_language_and_totals_per_language() {
		$this->activate_wpml();
		$pl1 = $this->product( 'Kubek', 'pl' );
		$pl2 = $this->product( 'Talerz', 'pl' );
		$en1 = $this->product( 'Mug', 'en' );

		$data = $this->get( '/aktio/v1/products/scan', array( 'per_page' => 100 ) )->get_data();
		$this->assertSame( 3, $data['total'] );
		$by_id = array_column( $data['items'], 'lang', 'id' );
		$this->assertSame( array( $pl1 => 'pl', $pl2 => 'pl', $en1 => 'en' ), $by_id );
		$this->assertSame( 2, $data['languages']['pl']['total'] );
		$this->assertSame( 1, $data['languages']['en']['total'] );
		$this->assertSame( 50, $data['languages']['pl']['limit'] );

		$en = $this->get( '/aktio/v1/products/scan', array( 'lang' => 'en' ) )->get_data();
		$this->assertSame( array( 'Mug' ), array_column( $en['items'], 'name' ) );
		$this->assertSame( array( 'en' ), array_keys( $en['languages'] ) );
	}

	public function test_scan_splits_global_limit_between_languages() {
		$this->activate_wpml();
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->product( "PL $i", 'pl' );
			$this->product( "EN $i", 'en' );
		}
		$data = $this->get( '/aktio/v1/products/scan', array( 'per_page' => 2 ) )->get_data();
		$this->assertCount( 2, $data['items'] );
		$this->assertSame( 1, $data['languages']['pl']['limit'] );
		$this->assertSame( 3, $data['languages']['pl']['total_pages'] );
		$this->assertSame( 6, $data['total'] );
		$this->assertSame( 3, $data['total_pages'] );
	}

	public function test_scan_with_default_language_works_without_plugin() {
		$id = $this->product( 'Kubek', 'pl' );
		foreach ( array( 'pl', 'PL', 'pl_PL' ) as $lang ) {
			$res = $this->get( '/aktio/v1/products/scan', array( 'lang' => $lang ) );
			$this->assertSame( 200, $res->get_status(), $lang );
			$item = $res->get_data()['items'][0];
			$this->assertSame( $id, $item['id'] );
			$this->assertSame( 'pl', $item['lang'] );
		}
		// Bez parametru lang wszystko po staremu, plus pole lang.
		$this->assertSame( 1, $this->get( '/aktio/v1/products/scan' )->get_data()['total'] );
	}

	public function test_scan_with_foreign_language_without_plugin_is_not_supported() {
		$res = $this->get( '/aktio/v1/products/scan', array( 'lang' => 'en' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'multilang_not_supported', $res->get_data()['code'] );
	}

	public function test_scan_with_unknown_language_under_wpml_lists_available() {
		$this->activate_wpml();
		$res = $this->get( '/aktio/v1/products/scan', array( 'lang' => 'de' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'multilang_unknown_lang', $res->get_data()['code'] );
		$this->assertSame( array( 'pl', 'en' ), $res->get_data()['data']['available'] );
	}

	public function test_missing_alt_gets_lang_only_with_multilingual_plugin() {
		$id = self::factory()->attachment->create_object(
			array( 'file' => 'a.jpg', 'post_mime_type' => 'image/jpeg', 'post_title' => 'A' )
		);
		$item = $this->get( '/aktio/v1/media/missing-alt' )->get_data()['items'][0];
		$this->assertArrayNotHasKey( 'lang', $item );

		$this->activate_wpml();
		$this->lang_of[ $id ] = 'en';
		$item = $this->get( '/aktio/v1/media/missing-alt' )->get_data()['items'][0];
		$this->assertSame( 'en', $item['lang'] );
	}

	public function test_sitemap_reports_per_language_under_wpml() {
		Settings::update( array( 'indexnow' => array( 'enabled' => true ) ) );
		update_option( 'blog_public', 1 );
		delete_option( IndexNow::SITEMAP_LAST );
		$this->activate_wpml();
		add_filter( 'wpml_permalink', fn( $url, $code ) => home_url( '/' . $code . '/' ), 10, 2 );
		add_filter( 'pre_http_request', fn() => array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null ) );

		$result = IndexNow::submit_sitemap();
		$this->assertIsArray( $result );
		$langs = array_column( $result['languages'], 'queued', 'lang' );
		$this->assertSame( 1, $langs['pl'] );
		$this->assertSame( 1, $langs['en'] );
		$this->assertSame( 'per_language', $result['note'] );
		$this->assertCount( 2, $result['urls'] );
	}
}
