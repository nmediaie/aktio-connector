<?php
use AktioConnector\Updates;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

/**
 * Atrapa upgradera: zapamiętuje wywołania, nic nie pobiera. Dziedziczy po WP_Upgrader,
 * bo inne wtyczki (WooCommerce) typują argument hooka upgrader_process_complete.
 */
class Aktio_Fake_Upgrader extends WP_Upgrader {
	public $calls = array();
	public $result;

	public function __construct( $result ) {
		parent::__construct();
		$this->result = $result;
	}

	public function bulk_upgrade( $items, $args = array() ) {
		$this->calls[] = $items;
		$out           = array();
		foreach ( $items as $item ) {
			$out[ $item ] = $this->result;
		}
		return $out;
	}

	public function upgrade( $current, $args = array() ) {
		$this->calls[] = $current;
		return $this->result;
	}
}

class UpdatesTest extends WP_UnitTestCase {

	/** Ścieżka atrapy wtyczki zakładanej w katalogu wtyczek (sprzątana w tear_down). */
	private $dummy_dir;

	public function set_up() {
		parent::set_up();
		// Żaden test nie wychodzi do sieci (poza atrapą loopbacku dodawaną w teście).
		add_filter(
			'pre_http_request',
			static function ( $pre ) {
				return false !== $pre ? $pre : new WP_Error( 'http_blocked', 'HTTP zablokowany w testach.' );
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'aktio_connector_update_log' );
		delete_option( 'aktio_connector_core_auto' );
		delete_site_option( 'auto_update_plugins' );
		$this->dummy_dir = WP_PLUGIN_DIR . '/aktio-dummy';
		$this->write_dummy( '1.0.0' );
	}

	public function tear_down() {
		if ( is_dir( $this->dummy_dir ) ) {
			@unlink( $this->dummy_dir . '/aktio-dummy.php' );
			@rmdir( $this->dummy_dir );
		}
		wp_clean_plugins_cache( false );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_core' );
		parent::tear_down();
	}

	private function write_dummy( string $version ): void {
		if ( ! is_dir( $this->dummy_dir ) ) {
			mkdir( $this->dummy_dir );
		}
		file_put_contents(
			$this->dummy_dir . '/aktio-dummy.php',
			"<?php\n/**\n * Plugin Name: Aktio Dummy\n * Version: {$version}\n */\n"
		);
		wp_clean_plugins_cache( false );
	}

	private function inject_plugin_update( string $file, string $version ): void {
		$t               = new stdClass();
		$t->last_checked = time();
		$t->response     = array(
			$file => (object) array(
				'plugin'      => $file,
				'new_version' => $version,
				'package'     => 'https://example.invalid/x.zip',
			),
		);
		$t->no_update    = array();
		$t->checked      = array();
		set_site_transient( 'update_plugins', $t );
	}

	private function fake_upgrader( $result ): Aktio_Fake_Upgrader {
		$fake = new Aktio_Fake_Upgrader( $result );
		add_filter(
			'aktio_connector_upgrader',
			static function () use ( $fake ) {
				return $fake;
			}
		);
		return $fake;
	}

	private function loopback( int $code, string $body ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $code, $body ) {
				if ( 0 === strpos( $url, home_url( '/' ) ) ) {
					return array(
						'headers'  => array(),
						'body'     => $body,
						'response' => array( 'code' => $code, 'message' => 'X' ),
						'cookies'  => array(),
						'filename' => null,
					);
				}
				return $pre;
			},
			5,
			3
		);
	}

	private function request( string $method, string $route, array $body = array() ) {
		$req = new WP_REST_Request( $method, '/aktio/v1' . $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $req );
	}

	public function test_available_lists_plugins_without_php_suffix() {
		$this->inject_plugin_update( 'aktio-dummy/aktio-dummy.php', '2.0.0' );
		$a = Updates::available();
		$this->assertSame( array( 'core', 'plugins', 'themes', 'auto_updates' ), array_keys( $a ) );
		$by = array();
		foreach ( $a['plugins'] as $p ) {
			$this->assertStringEndsNotWith( '.php', $p['plugin'] );
			$this->assertSame( array( 'plugin', 'name', 'current', 'available', 'auto_update' ), array_keys( $p ) );
			$by[ $p['plugin'] ] = $p;
		}
		$this->assertSame( 'Aktio Dummy', $by['aktio-dummy/aktio-dummy']['name'] );
		$this->assertSame( '1.0.0', $by['aktio-dummy/aktio-dummy']['current'] );
		$this->assertSame( '2.0.0', $by['aktio-dummy/aktio-dummy']['available'] );
		$this->assertFalse( $by['aktio-dummy/aktio-dummy']['auto_update'] );
		// Wtyczka bez aktualizacji też jest na liście, z available: null.
		$this->assertArrayHasKey( 'hello', $by );
		$this->assertNull( $by['hello']['available'] );
		$this->assertSame( array( 'current', 'available' ), array_keys( $a['core'] ) );
		$this->assertSame( get_bloginfo( 'version' ), $a['core']['current'] );
		$this->assertSame( 'minor', $a['auto_updates']['core'] );
		$this->assertNotEmpty( $a['themes'] );
		$this->assertSame( array( 'stylesheet', 'name', 'current', 'available', 'auto_update' ), array_keys( $a['themes'][0] ) );
	}

	public function test_get_updates_route_includes_log() {
		$res = $this->request( 'GET', '/updates' );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'core', 'plugins', 'themes', 'auto_updates', 'log' ), array_keys( $res->get_data() ) );
	}

	public function test_apply_unknown_item_is_404() {
		$fake = $this->fake_upgrader( array() );
		$r    = Updates::apply( 'plugin', 'nie-ma-takiej/wtyczki' );
		$this->assertWPError( $r );
		$this->assertSame( 'aktio_not_found', $r->get_error_code() );
		$this->assertSame( 404, $r->get_error_data()['status'] );
		$this->assertSame( array(), $fake->calls );

		$res = $this->request( 'POST', '/updates', array( 'type' => 'theme', 'item' => 'nie-ma-motywu' ) );
		$this->assertSame( 404, $res->get_status() );
	}

	public function test_apply_invalid_params_is_400() {
		foreach ( array( array( 'plugin', '../wp-config' ), array( 'plugin', 'hello.php' ), array( 'theme', 'a/b' ), array( 'widget', 'x' ) ) as $case ) {
			$r = Updates::apply( $case[0], $case[1] );
			$this->assertWPError( $r );
			$this->assertSame( 'aktio_invalid_params', $r->get_error_code(), implode( ' ', $case ) );
			$this->assertSame( 400, $r->get_error_data()['status'] );
		}
		$res = $this->request( 'POST', '/updates', array( 'type' => 'plugin' ) );
		$this->assertSame( 400, $res->get_status() );
	}

	public function test_apply_up_to_date_returns_up_to_date_and_logs_nothing() {
		$fake = $this->fake_upgrader( array() );
		$this->inject_plugin_update( 'hello.php', '0.0.1' ); // Transient niepusty, bez aktualizacji dla dummy.
		$r = Updates::apply( 'plugin', 'aktio-dummy/aktio-dummy' );
		$this->assertSame( 'up_to_date', $r['result'] );
		$this->assertSame( '1.0.0', $r['from'] );
		$this->assertSame( '1.0.0', $r['to'] );
		$this->assertNull( $r['entry'] );
		$this->assertSame( array(), Updates::log() );
		$this->assertSame( array(), $fake->calls );
	}

	public function test_apply_without_filesystem_is_aktio_fs_unavailable() {
		$fake = $this->fake_upgrader( array() );
		$this->inject_plugin_update( 'aktio-dummy/aktio-dummy.php', '2.0.0' );
		add_filter(
			'filesystem_method',
			static function () {
				return 'ftpext';
			}
		);
		$r = Updates::apply( 'plugin', 'aktio-dummy/aktio-dummy' );
		$this->assertWPError( $r );
		$this->assertSame( 'aktio_fs_unavailable', $r->get_error_code() );
		$this->assertSame( 500, $r->get_error_data()['status'] );
		$this->assertStringContainsString( 'FTP', $r->get_error_message() );
		$this->assertSame( array(), $fake->calls );
		$this->assertSame( array(), Updates::log() );
	}

	public function test_apply_success_logs_entry_with_from_to_and_site_ok() {
		$fake = $this->fake_upgrader( array( 'destination_name' => 'aktio-dummy' ) );
		$this->inject_plugin_update( 'aktio-dummy/aktio-dummy.php', '2.0.0' );
		$this->loopback( 200, '<html><body>Strona działa</body></html>' );

		$res = $this->request( 'POST', '/updates', array( 'type' => 'plugin', 'item' => 'aktio-dummy/aktio-dummy' ) );
		$this->assertSame( 200, $res->get_status() );
		$r = $res->get_data();
		$this->assertSame( array( array( 'aktio-dummy/aktio-dummy.php' ) ), $fake->calls );
		$this->assertSame( 'updated', $r['result'] );
		$this->assertSame( 'plugin', $r['type'] );
		$this->assertSame( 'aktio-dummy/aktio-dummy', $r['item'] );
		$this->assertSame( '1.0.0', $r['from'] );
		$this->assertSame( '2.0.0', $r['to'] );
		$this->assertTrue( $r['site_ok'] );

		$log = Updates::log();
		$this->assertCount( 1, $log );
		$e = $log[0];
		$this->assertSame( array( 'at', 'type', 'item', 'name', 'from', 'to', 'result', 'by', 'site_ok' ), array_keys( $e ) );
		$this->assertSame( $e, $r['entry'] );
		$this->assertSame( 'Aktio Dummy', $e['name'] );
		$this->assertSame( 'aktio', $e['by'] );
		$this->assertTrue( $e['site_ok'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $e['at'] );
		// Dziennik bez autoload.
		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'aktio_connector_update_log' ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_apply_site_broken_and_failed_are_logged() {
		$this->inject_plugin_update( 'aktio-dummy/aktio-dummy.php', '2.0.0' );
		$this->loopback( 500, '<div class="wp-die-message">There has been a critical error on this website.</div>' );
		$this->fake_upgrader( false );
		$r = Updates::apply( 'plugin', 'aktio-dummy/aktio-dummy' );
		$this->assertSame( 'failed', $r['result'] );
		$this->assertSame( '2.0.0', $r['to'] );
		$this->assertFalse( $r['site_ok'] );
		$this->assertSame( 'failed', Updates::log()[0]['result'] );
		$this->assertSame( '2.0.0', Updates::log()[0]['to'] );
	}

	public function test_site_ok_false_on_critical_error_text_with_200() {
		$this->inject_plugin_update( 'aktio-dummy/aktio-dummy.php', '2.0.0' );
		$this->loopback( 200, 'There has been a critical error on this website.' );
		$this->fake_upgrader( array( 'destination_name' => 'aktio-dummy' ) );
		$r = Updates::apply( 'plugin', 'aktio-dummy/aktio-dummy' );
		$this->assertSame( 'updated', $r['result'] );
		$this->assertFalse( $r['site_ok'] );
	}

	public function test_apply_core_uses_core_update_offer() {
		$t                  = new stdClass();
		$t->last_checked    = time();
		$t->version_checked = get_bloginfo( 'version' );
		$t->updates         = array(
			(object) array(
				'response' => 'upgrade',
				'current'  => '99.0',
				'version'  => '99.0',
				'locale'   => get_locale(),
				'packages' => (object) array( 'full' => 'https://example.invalid/wp.zip' ),
			),
		);
		set_site_transient( 'update_core', $t );
		$this->assertSame( '99.0', Updates::available()['core']['available'] );
		$fake = $this->fake_upgrader( '99.0' );
		$this->loopback( 200, 'ok' );
		$r = Updates::apply( 'core', 'cokolwiek' );
		$this->assertSame( 'updated', $r['result'] );
		$this->assertSame( 'core', $r['item'] );
		$this->assertSame( '99.0', $r['to'] );
		$this->assertSame( '99.0', $fake->calls[0]->current );
		$this->assertSame( 'WordPress', Updates::log()[0]['name'] );
	}

	public function test_log_keeps_50_newest() {
		$entries = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$entries[] = array( 'at' => 'x', 'type' => 'plugin', 'item' => 'old-' . $i, 'name' => 'n', 'from' => '1', 'to' => '2', 'result' => 'updated', 'by' => 'user', 'site_ok' => true );
		}
		update_option( 'aktio_connector_update_log', $entries, false );
		$this->inject_plugin_update( 'aktio-dummy/aktio-dummy.php', '2.0.0' );
		$this->fake_upgrader( array( 'destination_name' => 'aktio-dummy' ) );
		Updates::apply( 'plugin', 'aktio-dummy/aktio-dummy' );
		$log = Updates::log();
		$this->assertCount( 50, $log );
		$this->assertSame( 'aktio-dummy/aktio-dummy', $log[0]['item'] );
		$this->assertSame( 'old-1', $log[1]['item'] );
		$this->assertSame( 'old-49', $log[49]['item'] );
		$this->assertCount( 5, Updates::log( 5 ) );
	}

	public function test_updates_by_user_in_wp_admin_logged_with_by_user() {
		// Symulacja zwykłej aktualizacji z wp-admin: pre_install (stara wersja), podmiana pliku, hook zakończenia.
		apply_filters( 'upgrader_pre_install', true, array( 'plugin' => 'aktio-dummy/aktio-dummy.php' ) );
		$this->write_dummy( '1.1.0' );
		do_action(
			'upgrader_process_complete',
			new Plugin_Upgrader( new WP_Ajax_Upgrader_Skin() ),
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( 'aktio-dummy/aktio-dummy.php', 'hello.php' ),
			)
		);
		$log = Updates::log();
		// hello.php nie przeszedł instalacji (brak pre_install) — bez wpisu.
		$this->assertCount( 1, $log );
		$this->assertSame( 'user', $log[0]['by'] );
		$this->assertSame( 'aktio-dummy/aktio-dummy', $log[0]['item'] );
		$this->assertSame( '1.0.0', $log[0]['from'] );
		$this->assertSame( '1.1.0', $log[0]['to'] );
		$this->assertSame( 'updated', $log[0]['result'] );
		$this->assertFalse( $log[0]['site_ok'] ); // HTTP zablokowany = loopback nieudany.
	}

	public function test_aktio_apply_does_not_double_log_via_hook() {
		$this->inject_plugin_update( 'aktio-dummy/aktio-dummy.php', '2.0.0' );
		$fake = new class() extends Plugin_Upgrader {
			public function bulk_upgrade( $items, $args = array() ) {
				apply_filters( 'upgrader_pre_install', true, array( 'plugin' => $items[0] ) );
				do_action( 'upgrader_process_complete', $this, array( 'action' => 'update', 'type' => 'plugin', 'bulk' => true, 'plugins' => $items ) );
				return array( $items[0] => array( 'destination_name' => 'aktio-dummy' ) );
			}
		};
		add_filter(
			'aktio_connector_upgrader',
			static function () use ( $fake ) {
				return $fake;
			}
		);
		Updates::apply( 'plugin', 'aktio-dummy/aktio-dummy' );
		$log = Updates::log();
		$this->assertCount( 1, $log );
		$this->assertSame( 'aktio', $log[0]['by'] );
		$this->assertSame( 'updated', $log[0]['result'] );
	}

	public function test_post_updates_requires_cap_matching_type() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user )->add_cap( 'update_plugins' );
		wp_set_current_user( $user );
		$fake = $this->fake_upgrader( '99.0' );

		$res = $this->request( 'POST', '/updates', array( 'type' => 'core', 'item' => 'core' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'rest_forbidden', $res->get_data()['code'] );
		$res = $this->request( 'POST', '/auto-updates', array( 'type' => 'core', 'core_level' => 'all' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( array(), $fake->calls );

		// Z update_plugins przechodzi przez permission_callback i dochodzi do walidacji/elementu.
		$res = $this->request( 'POST', '/updates', array( 'type' => 'plugin', 'item' => 'nie-ma/takiej' ) );
		$this->assertSame( 404, $res->get_status() );
		$this->assertSame( 200, $this->request( 'GET', '/updates' )->get_status() );

		// Bez żadnej z capability: 403 już w permission_callback.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/updates' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/updates', array( 'type' => 'plugin', 'item' => 'hello' ) )->get_status() );
	}

	public function test_set_auto_plugin_toggles_auto_update_plugins_option() {
		$r = Updates::set_auto( 'plugin', 'hello', true, null );
		$this->assertSame( array( 'type' => 'plugin', 'item' => 'hello', 'enabled' => true, 'core_level' => null ), $r );
		$this->assertContains( 'hello.php', get_site_option( 'auto_update_plugins' ) );
		$by = array();
		foreach ( Updates::available()['plugins'] as $p ) {
			$by[ $p['plugin'] ] = $p;
		}
		$this->assertTrue( $by['hello']['auto_update'] );

		$res = $this->request( 'POST', '/auto-updates', array( 'type' => 'plugin', 'item' => 'hello', 'enabled' => false ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['enabled'] );
		$this->assertNotContains( 'hello.php', get_site_option( 'auto_update_plugins' ) );

		$this->assertSame( 400, $this->request( 'POST', '/auto-updates', array( 'type' => 'plugin', 'item' => 'hello' ) )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/auto-updates', array( 'type' => 'plugin', 'item' => 'brak', 'enabled' => true ) )->get_status() );
	}

	public function test_set_auto_theme_toggles_auto_update_themes_option() {
		$stylesheet = get_stylesheet();
		$r          = Updates::set_auto( 'theme', $stylesheet, true, null );
		$this->assertTrue( $r['enabled'] );
		$this->assertContains( $stylesheet, get_site_option( 'auto_update_themes' ) );
		Updates::set_auto( 'theme', $stylesheet, false, null );
		$this->assertNotContains( $stylesheet, get_site_option( 'auto_update_themes' ) );
	}

	public function test_set_auto_core_level_validated() {
		$r = Updates::set_auto( 'core', null, null, 'weird' );
		$this->assertWPError( $r );
		$this->assertSame( 'aktio_invalid_params', $r->get_error_code() );
		$this->assertSame( 400, $this->request( 'POST', '/auto-updates', array( 'type' => 'core', 'core_level' => 'weird' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/auto-updates', array( 'type' => 'core' ) )->get_status() );

		$res = $this->request( 'POST', '/auto-updates', array( 'type' => 'core', 'core_level' => 'off' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'type' => 'core', 'item' => 'core', 'enabled' => false, 'core_level' => 'off' ), $res->get_data() );
		$this->assertSame( 'off', Updates::available()['auto_updates']['core'] );
	}

	public function test_core_level_filters() {
		// Domyślnie (opcja nieustawiona) wtyczka nie zmienia decyzji WordPressa.
		$this->assertSame( 'wp', apply_filters( 'allow_major_auto_core_updates', 'wp' ) );
		$levels = array(
			'off'   => array( false, false ),
			'minor' => array( false, true ),
			'all'   => array( true, true ),
		);
		foreach ( $levels as $level => $expect ) {
			Updates::set_auto( 'core', null, null, $level );
			$this->assertSame( $expect[0], apply_filters( 'allow_major_auto_core_updates', null ), $level );
			$this->assertSame( $expect[1], apply_filters( 'allow_minor_auto_core_updates', null ), $level );
		}
	}
}
