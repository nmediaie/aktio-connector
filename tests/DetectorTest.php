<?php
use AktioConnector\Detector;

// Atrapa klasy Jetpack (wtyczki w testach nie ma): aktywność modułów ustawia się w teście.
if ( ! class_exists( 'Jetpack' ) ) {
	class Jetpack {
		public static $modules = array();

		public static function is_module_active( $module ) {
			return in_array( $module, self::$modules, true );
		}
	}
}

class DetectorTest extends WP_UnitTestCase {

	private $active = array();
	private $dirs   = array();

	public function set_up() {
		parent::set_up();
		$this->active = array();
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		foreach ( $this->dirs as $dir ) {
			foreach ( glob( $dir . '/*' ) as $f ) {
				unlink( $f );
			}
			rmdir( $dir );
		}
		$this->dirs = array();
		foreach ( array( 'robots.txt', 'llms.txt' ) as $f ) {
			if ( file_exists( ABSPATH . $f ) ) {
				unlink( ABSPATH . $f );
			}
		}
		delete_option( 'wpseo' );
		delete_option( 'rank_math_modules' );
		Jetpack::$modules = array();
		remove_all_filters( 'jetpack_enable_open_graph' );
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active;
	}

	/** Atrapa pliku wtyczki w WP_PLUGIN_DIR; $activate = czy ma być aktywna. */
	private function plugin( string $file, string $name, bool $activate = true ) {
		$dir = WP_PLUGIN_DIR . '/' . dirname( $file );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
			$this->dirs[] = $dir;
		}
		file_put_contents( WP_PLUGIN_DIR . '/' . $file, "<?php\n/*\nPlugin Name: $name\n*/\n" );
		if ( $activate ) {
			$this->active[] = $file;
		}
	}

	public function test_inactive_seo_plugin_does_not_block() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO', false );
		$f = Detector::features();
		$this->assertSame( array( 'state' => 'active', 'provided_by' => null ), $f['content_schema'] );
		$this->assertSame( array(), Detector::seo_plugins() );
		$this->assertTrue( Detector::owns( 'content_schema' ) );
	}

	public function test_active_yoast_provides_content_and_robots() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		$f = Detector::features();
		$this->assertSame( array( 'state' => 'provided', 'provided_by' => 'Yoast SEO' ), $f['content_schema'] );
		// R14: robots.txt generuje Yoast, Aktio dopisuje tylko reguły blokujące.
		$this->assertSame( array( 'state' => 'active', 'provided_by' => 'Yoast SEO' ), $f['robots'] );
		$this->assertTrue( Detector::owns( 'robots' ) );
		$this->assertSame( 'active', $f['llms']['state'] );
		$this->assertSame( 'active', $f['product_schema']['state'] );
		$this->assertFalse( Detector::owns( 'content_schema' ) );
		$this->assertSame( array( array( 'slug' => 'wordpress-seo', 'name' => 'Yoast SEO' ) ), Detector::seo_plugins() );
	}

	public function test_yoast_llms_only_when_enabled() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		update_option( 'wpseo', array( 'enable_llms_txt' => true ) );
		$this->assertSame( 'provided', Detector::features()['llms']['state'] );
	}

	public function test_rank_math_modules_decide_llms_and_product() {
		$this->plugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO' );
		update_option( 'rank_math_modules', array( 'llms-txt' ) );
		$f = Detector::features();
		$this->assertSame( 'provided', $f['llms']['state'] );
		$this->assertNotSame( 'provided', $f['product_schema']['state'] );
	}

	public function test_rank_math_woocommerce_module_provides_product_schema() {
		$this->plugin( 'seo-by-rank-math/rank-math.php', 'Rank Math SEO' );
		update_option( 'rank_math_modules', array( 'woocommerce' ) );
		$this->assertSame( 'provided', Detector::features()['product_schema']['state'] );
	}

	public function test_yoast_woocommerce_plugin_provides_product_schema() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		$this->plugin( 'wpseo-woocommerce/wpseo-woocommerce.php', 'Yoast SEO: WooCommerce' );
		$this->assertSame( 'provided', Detector::features()['product_schema']['state'] );
	}

	public function test_llms_plugin_matches_by_directory() {
		$this->plugin( 'website-llms-txt/anything.php', 'Website LLMs.txt' );
		$f = Detector::features();
		$this->assertSame( 'Website LLMs.txt', $f['llms']['provided_by'] );
		$this->assertSame( 'active', $f['content_schema']['state'] );
	}

	public function test_physical_robots_file_wins() {
		$this->plugin( 'wordpress-seo/wp-seo.php', 'Yoast SEO' );
		file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\n" );
		$f = Detector::features();
		$this->assertSame( array( 'state' => 'provided', 'provided_by' => 'file' ), $f['robots'] );
		$this->assertSame( 'active', $f['llms']['state'] );
		file_put_contents( ABSPATH . 'llms.txt', "# x\n" );
		$this->assertSame( 'file', Detector::features()['llms']['provided_by'] );
	}

	public function test_product_schema_off_without_woocommerce() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce jest załadowane w tym środowisku.' );
		}
		$this->assertSame( array( 'state' => 'off', 'provided_by' => null ), Detector::features()['product_schema'] );
	}

	public function test_every_seo_plugin_robots_is_active_with_provided_by() {
		$plugins = array(
			'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
			'seo-by-rank-math/rank-math.php'              => 'Rank Math SEO',
			'wp-seopress/seopress.php'                    => 'SEOPress',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'autodescription/autodescription.php'         => 'The SEO Framework',
		);
		foreach ( $plugins as $file => $name ) {
			$this->active = array();
			$this->plugin( $file, $name );
			$this->assertSame( array( 'state' => 'active', 'provided_by' => $name ), Detector::features()['robots'], $name );
		}
	}

	public function test_seo_features_active_without_seo_plugin() {
		$f = Detector::features();
		foreach ( array( 'title', 'meta_description', 'open_graph', 'twitter_card', 'robots_meta' ) as $feature ) {
			$this->assertSame( array( 'state' => 'active', 'provided_by' => null ), $f[ $feature ], $feature );
			$this->assertTrue( Detector::owns( $feature ), $feature );
		}
	}

	public function test_yoast_provides_all_seo_features() {
		$plugins = array(
			'wordpress-seo/wp-seo.php'                    => 'Yoast SEO',
			'seo-by-rank-math/rank-math.php'              => 'Rank Math SEO',
			'wp-seopress/seopress.php'                    => 'SEOPress',
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'autodescription/autodescription.php'         => 'The SEO Framework',
		);
		foreach ( $plugins as $file => $name ) {
			$this->active = array();
			$this->plugin( $file, $name );
			$f = Detector::features();
			foreach ( array( 'title', 'meta_description', 'open_graph', 'twitter_card', 'robots_meta' ) as $feature ) {
				$this->assertSame( array( 'state' => 'provided', 'provided_by' => $name ), $f[ $feature ], $name . ' ' . $feature );
				$this->assertFalse( Detector::owns( $feature ), $name . ' ' . $feature );
			}
		}
	}

	public function test_jetpack_og_provides_open_graph_and_twitter_only() {
		$this->plugin( 'jetpack/jetpack.php', 'Jetpack' );
		Jetpack::$modules = array( 'publicize' );
		add_filter( 'jetpack_enable_open_graph', '__return_true' );
		$f = Detector::features();
		$this->assertSame( array( 'state' => 'provided', 'provided_by' => 'Jetpack' ), $f['open_graph'] );
		$this->assertSame( array( 'state' => 'provided', 'provided_by' => 'Jetpack' ), $f['twitter_card'] );
		$this->assertSame( 'active', $f['meta_description']['state'] );
		$this->assertSame( 'active', $f['title']['state'] );
		$this->assertSame( 'active', $f['robots_meta']['state'] );
	}

	public function test_jetpack_without_og_module_provides_nothing() {
		$this->plugin( 'jetpack/jetpack.php', 'Jetpack' );
		$f = Detector::features();
		foreach ( array( 'title', 'meta_description', 'open_graph', 'twitter_card', 'robots_meta' ) as $feature ) {
			$this->assertSame( 'active', $f[ $feature ]['state'], $feature );
		}
	}

	public function test_map_is_filterable() {
		add_filter(
			'aktio_connector_seo_map',
			static function ( $map ) {
				$map[] = array(
					'name'     => 'Moja SEO',
					'files'    => array( 'moja-seo/moja.php' ),
					'provides' => array( 'robots' => true ),
				);
				return $map;
			}
		);
		$this->plugin( 'moja-seo/moja.php', 'Moja SEO' );
		$this->assertSame( array( 'state' => 'active', 'provided_by' => 'Moja SEO' ), Detector::features()['robots'] );
		remove_all_filters( 'aktio_connector_seo_map' );
	}
}
