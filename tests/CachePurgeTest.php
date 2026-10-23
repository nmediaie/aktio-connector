<?php
use AktioConnector\CachePurge;

// Atrapy funkcji wtyczek cache (wtyczek w testach nie ma). Globalne i nieszkodliwe:
// CachePurge sięga po nie tylko, gdy plik wtyczki jest aktywny.
class AktioFakeCache {
	public static $calls = array();
	public static $throw = false;

	public static function hit( string $name, array $args = array() ) {
		self::$calls[] = array_merge( array( $name ), $args );
		if ( self::$throw ) {
			throw new RuntimeException( 'boom' );
		}
	}
}

if ( ! function_exists( 'rocket_clean_domain' ) ) {
	function rocket_clean_domain() {
		AktioFakeCache::hit( 'rocket_clean_domain' );
	}
	function rocket_clean_files( $urls ) {
		AktioFakeCache::hit( 'rocket_clean_files', array( $urls ) );
	}
	function w3tc_flush_all() {
		AktioFakeCache::hit( 'w3tc_flush_all' );
	}
	function w3tc_flush_url( $url ) {
		AktioFakeCache::hit( 'w3tc_flush_url', array( $url ) );
	}
	function wp_cache_clear_cache() {
		AktioFakeCache::hit( 'wp_cache_clear_cache' );
	}
	function wpsc_delete_url_cache( $url ) {
		AktioFakeCache::hit( 'wpsc_delete_url_cache', array( $url ) );
	}
	function wpfc_clear_all_cache() {
		AktioFakeCache::hit( 'wpfc_clear_all_cache' );
	}
	function sg_cachepress_purge_cache() {
		AktioFakeCache::hit( 'sg_cachepress_purge_cache' );
	}
}

class CachePurgeTest extends WP_UnitTestCase {

	private $active = array();

	public function set_up() {
		parent::set_up();
		$this->active          = array();
		AktioFakeCache::$calls = array();
		AktioFakeCache::$throw = false;
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		delete_option( CachePurge::OPTION );
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		foreach ( array( 'cache_enabler_clear_complete_cache', 'cache_enabler_clear_page_cache_by_url', 'litespeed_purge_all', 'litespeed_purge_url', 'breeze_clear_all_cache', 'cloudflare_purge_everything' ) as $hook ) {
			remove_all_actions( $hook );
		}
		delete_option( CachePurge::OPTION );
		delete_option( \AktioConnector\Settings::OPTION );
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active;
	}

	/** Rejestruje akcję-atrapę, która zapisuje wywołanie. */
	private function listen( string $hook ) {
		add_action(
			$hook,
			static function ( $arg = null ) use ( $hook ) {
				// do_action() bez argumentów przekazuje wywołaniu pusty string.
				AktioFakeCache::hit( $hook, in_array( $arg, array( null, '' ), true ) ? array() : array( $arg ) );
			}
		);
	}

	public function test_nothing_without_cache_plugin() {
		$this->assertNull( CachePurge::detected() );
		$this->assertFalse( CachePurge::all() );
		$this->assertFalse( CachePurge::url( 'https://example.org/a/' ) );
		$this->assertNull( CachePurge::last() );
		$this->assertSame( array(), AktioFakeCache::$calls );
	}

	public function test_cache_enabler() {
		$this->active = array( 'cache-enabler/cache-enabler.php' );
		$this->listen( 'cache_enabler_clear_complete_cache' );
		$this->listen( 'cache_enabler_clear_page_cache_by_url' );
		$this->assertSame( 'cache-enabler', CachePurge::detected() );
		$this->assertTrue( CachePurge::all() );
		$this->assertTrue( CachePurge::url( 'https://example.org/a/' ) );
		$this->assertSame(
			array( array( 'cache_enabler_clear_complete_cache' ), array( 'cache_enabler_clear_page_cache_by_url', 'https://example.org/a/' ) ),
			AktioFakeCache::$calls
		);
	}

	public function test_wp_rocket() {
		$this->active = array( 'wp-rocket/wp-rocket.php' );
		CachePurge::all();
		CachePurge::url( 'https://example.org/a/' );
		$this->assertSame(
			array( array( 'rocket_clean_domain' ), array( 'rocket_clean_files', array( 'https://example.org/a/' ) ) ),
			AktioFakeCache::$calls
		);
	}

	public function test_litespeed() {
		$this->active = array( 'litespeed-cache/litespeed-cache.php' );
		$this->listen( 'litespeed_purge_all' );
		$this->listen( 'litespeed_purge_url' );
		CachePurge::all();
		CachePurge::url( 'https://example.org/a/' );
		$this->assertSame(
			array( array( 'litespeed_purge_all' ), array( 'litespeed_purge_url', 'https://example.org/a/' ) ),
			AktioFakeCache::$calls
		);
	}

	public function test_w3tc() {
		$this->active = array( 'w3-total-cache/w3-total-cache.php' );
		CachePurge::all();
		CachePurge::url( 'https://example.org/a/' );
		$this->assertSame(
			array( array( 'w3tc_flush_all' ), array( 'w3tc_flush_url', 'https://example.org/a/' ) ),
			AktioFakeCache::$calls
		);
	}

	public function test_wp_super_cache() {
		$this->active = array( 'wp-super-cache/wp-cache.php' );
		CachePurge::all();
		CachePurge::url( 'https://example.org/a/' );
		$this->assertSame(
			array( array( 'wp_cache_clear_cache' ), array( 'wpsc_delete_url_cache', 'https://example.org/a/' ) ),
			AktioFakeCache::$calls
		);
	}

	public function test_plugins_without_url_api_clear_everything() {
		$cases = array(
			'wp-fastest-cache/wpFastestCache.php' => 'wpfc_clear_all_cache',
			'sg-cachepress/sg-cachepress.php'     => 'sg_cachepress_purge_cache',
		);
		foreach ( $cases as $file => $function ) {
			AktioFakeCache::$calls = array();
			$this->active          = array( $file );
			$this->assertTrue( CachePurge::url( 'https://example.org/a/' ) );
			$this->assertSame( array( array( $function ) ), AktioFakeCache::$calls );
		}
	}

	public function test_breeze_and_cloudflare_actions() {
		$this->listen( 'breeze_clear_all_cache' );
		$this->active = array( 'breeze/breeze.php' );
		$this->assertTrue( CachePurge::all() );
		$this->assertSame( array( array( 'breeze_clear_all_cache' ) ), AktioFakeCache::$calls );

		AktioFakeCache::$calls = array();
		$this->listen( 'cloudflare_purge_everything' );
		$this->active = array( 'cloudflare/cloudflare.php' );
		$this->assertTrue( CachePurge::all() );
		$this->assertSame( array( array( 'cloudflare_purge_everything' ) ), AktioFakeCache::$calls );
	}

	public function test_cloudflare_without_registered_action_does_nothing() {
		$this->active = array( 'cloudflare/cloudflare.php' );
		$this->assertFalse( CachePurge::all() );
		$this->assertNull( CachePurge::last() );
	}

	public function test_exception_is_swallowed() {
		$this->active          = array( 'w3-total-cache/w3-total-cache.php' );
		AktioFakeCache::$throw = true;
		$this->assertFalse( CachePurge::all() );
		$this->assertFalse( CachePurge::url( 'https://example.org/a/' ) );
		$this->assertNull( CachePurge::last() );
	}

	public function test_last_is_iso_time_after_purge() {
		$this->active = array( 'w3-total-cache/w3-total-cache.php' );
		CachePurge::all();
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', CachePurge::last() );
	}

	public function test_settings_update_purges_all_once() {
		$this->active = array( 'w3-total-cache/w3-total-cache.php' );
		\AktioConnector\Settings::update( array( 'seo' => array( 'home_description' => 'Opis' ) ) );
		$this->assertSame( array( array( 'w3tc_flush_all' ) ), AktioFakeCache::$calls );
	}

	public function test_stock_status_change_purges_product_url() {
		$this->active = array( 'w3-total-cache/w3-total-cache.php' );
		$id           = self::factory()->post->create();
		do_action( 'woocommerce_product_set_stock_status', $id, 'outofstock', null );
		$this->assertSame( array( array( 'w3tc_flush_url', get_permalink( $id ) ) ), AktioFakeCache::$calls );
	}

	public function test_variation_stock_status_change_purges_parent_url() {
		$this->active = array( 'w3-total-cache/w3-total-cache.php' );
		$parent       = self::factory()->post->create();
		$variation    = new class( $parent ) {
			private $parent;
			public function __construct( $parent ) {
				$this->parent = $parent;
			}
			public function get_parent_id() {
				return $this->parent;
			}
		};
		do_action( 'woocommerce_variation_set_stock_status', 999999, 'instock', $variation );
		$this->assertSame( array( array( 'w3tc_flush_url', get_permalink( $parent ) ) ), AktioFakeCache::$calls );
	}

	public function test_status_cache_key() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/status' ) )->get_data();
		$this->assertSame(
			array(
				'plugin'     => null,
				'last_purge' => null,
			),
			$data['cache']
		);
	}
}
