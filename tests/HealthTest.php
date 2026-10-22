<?php
use AktioConnector\Health;

class HealthTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Żaden test nie wychodzi do sieci.
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_blocked', 'HTTP zablokowany w testach.' );
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_report_counts_match_tests() {
		$r      = Health::report();
		$counts = array( 'good' => 0, 'recommended' => 0, 'critical' => 0 );
		foreach ( $r['tests'] as $t ) {
			if ( isset( $counts[ $t['status'] ] ) ) {
				++$counts[ $t['status'] ];
			}
		}
		$this->assertSame( array( 'good', 'recommended', 'critical' ), array_keys( $r['counts'] ) );
		$this->assertSame( $counts, $r['counts'] );
		$this->assertNotEmpty( $r['tests'] );
		$this->assertLessThanOrEqual( 100, count( $r['tests'] ) );
		$this->assertSame( array( 'test', 'label', 'status', 'badge', 'description' ), array_keys( $r['tests'][0] ) );
		foreach ( $r['tests'] as $t ) {
			$this->assertContains( $t['status'], array( 'good', 'recommended', 'critical', 'skipped' ) );
		}
	}

	public function test_direct_tests_run_and_async_use_async_direct_test_or_skipped() {
		$filter = static function ( $tests ) {
			$tests['direct']['aktio_ok']    = array(
				'label' => 'Test bezpośredni',
				'test'  => static function () {
					return array(
						'label'       => 'Wszystko gra',
						'status'      => 'good',
						'badge'       => array( 'label' => 'Wydajność', 'color' => 'blue' ),
						'description' => '<p>Opis</p>',
						'test'        => 'aktio_ok',
					);
				},
			);
			$tests['async']['aktio_with']   = array(
				'label'             => 'Async z direct',
				'test'              => 'aktio_with',
				'async_direct_test' => static function () {
					return array(
						'label'       => 'Async uruchomiony',
						'status'      => 'recommended',
						'badge'       => array( 'label' => 'Bezpieczeństwo', 'color' => 'red' ),
						'description' => '<p>x</p>',
						'test'        => 'aktio_with',
					);
				},
			);
			$tests['async']['aktio_without'] = array(
				'label' => 'Async bez direct',
				'test'  => 'aktio_without',
			);
			return $tests;
		};
		add_filter( 'site_status_tests', $filter );
		$by = array();
		foreach ( Health::report()['tests'] as $t ) {
			$by[ $t['test'] ] = $t;
		}
		$this->assertSame( 'good', $by['aktio_ok']['status'] );
		$this->assertSame( 'Wydajność', $by['aktio_ok']['badge'] );
		$this->assertSame( 'recommended', $by['aktio_with']['status'] );
		$this->assertSame( 'Async uruchomiony', $by['aktio_with']['label'] );
		$this->assertSame( 'skipped', $by['aktio_without']['status'] );
		$this->assertSame( 'Async bez direct', $by['aktio_without']['label'] );
	}

	public function test_failing_test_is_skipped() {
		add_filter(
			'site_status_tests',
			static function ( $tests ) {
				$tests['direct']['aktio_boom'] = array(
					'label' => 'Wybucha',
					'test'  => static function () {
						throw new \Exception( 'boom' );
					},
				);
				return $tests;
			}
		);
		$by = array();
		foreach ( Health::report()['tests'] as $t ) {
			$by[ $t['test'] ] = $t;
		}
		$this->assertSame( 'skipped', $by['aktio_boom']['status'] );
	}

	public function test_description_is_plain_text_max_1000() {
		add_filter(
			'site_status_tests',
			static function ( $tests ) {
				$tests['direct']['aktio_long'] = array(
					'label' => 'Długi',
					'test'  => static function () {
						return array(
							'label'       => 'Długi',
							'status'      => 'good',
							'badge'       => array( 'label' => 'X', 'color' => 'blue' ),
							'description' => '<p>Ala   <strong>ma</strong>' . "\n" . 'kota</p><script>alert(1)</script>' . str_repeat( '<p>słowo </p>', 400 ),
							'test'        => 'aktio_long',
						);
					},
				);
				return $tests;
			}
		);
		foreach ( Health::report()['tests'] as $t ) {
			$this->assertLessThanOrEqual( 1000, mb_strlen( $t['description'] ) );
			$this->assertSame( $t['description'], wp_strip_all_tags( $t['description'] ) );
			$this->assertDoesNotMatchRegularExpression( '/\s{2,}/', $t['description'] );
			if ( 'aktio_long' === $t['test'] ) {
				$this->assertStringStartsWith( 'Ala ma kota', $t['description'] );
				$this->assertStringNotContainsString( 'alert', $t['description'] );
			}
		}
	}

	public function test_redact_removes_abspath_and_emails() {
		$out = Health::redact( 'Błąd w ' . ABSPATH . 'wp-content/x.php dla jan@example.com i ' . WP_CONTENT_DIR . '/uploads' );
		$this->assertStringNotContainsString( ABSPATH, $out );
		$this->assertStringNotContainsString( WP_CONTENT_DIR, $out );
		$this->assertStringNotContainsString( 'jan@example.com', $out );
		$this->assertStringContainsString( '[path]', $out );
		$this->assertStringContainsString( '[email]', $out );
	}

	public function test_redact_home_directory() {
		$old = getenv( 'HOME' );
		putenv( 'HOME=/home/tajny' );
		$out = Health::redact( 'plik /home/tajny/.cache/a' );
		putenv( false === $old ? 'HOME' : 'HOME=' . $old );
		$this->assertStringNotContainsString( '/home/tajny', $out );
		$this->assertStringContainsString( '[path]', $out );
	}

	public function test_info_has_contract_keys() {
		$info = Health::report()['info'];
		$this->assertSame( array( 'wp', 'php', 'mysql', 'theme', 'plugins_active', 'plugins_total', 'memory_limit', 'https', 'wp_debug', 'cron_ok' ), array_keys( $info ) );
		$this->assertSame( get_bloginfo( 'version' ), $info['wp'] );
		$this->assertSame( PHP_VERSION, $info['php'] );
		$this->assertIsString( $info['mysql'] );
		$this->assertIsInt( $info['plugins_active'] );
		$this->assertGreaterThanOrEqual( $info['plugins_active'], $info['plugins_total'] );
		$this->assertIsBool( $info['https'] );
		$this->assertIsBool( $info['wp_debug'] );
		$this->assertIsBool( $info['cron_ok'] );
	}

	public function test_cron_not_ok_when_overdue_event() {
		_set_cron_array( array( time() - 2 * HOUR_IN_SECONDS => array( 'aktio_test_hook' => array( 'x' => array( 'schedule' => false, 'args' => array() ) ) ) ) );
		$this->assertFalse( Health::report()['info']['cron_ok'] );
		_set_cron_array( array() );
	}

	public function test_health_needs_view_site_health_checks() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/health' ) )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/health' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'counts', 'tests', 'info' ), array_keys( $res->get_data() ) );
	}
}
