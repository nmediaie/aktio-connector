<?php
use AktioConnector\Admin\SettingsPage;
use AktioConnector\Settings;

class SettingsPageTest extends WP_UnitTestCase {

	private $dirs   = array();
	private $active = array();

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->active = array();
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		add_filter(
			'wp_redirect',
			static function () {
				throw new RuntimeException( 'redirect' );
			}
		);
		$_POST = array();
		$_REQUEST = array();
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		remove_all_filters( 'wp_redirect' );
		foreach ( $this->dirs as $dir ) {
			foreach ( glob( $dir . '/*' ) as $f ) {
				unlink( $f );
			}
			rmdir( $dir );
		}
		$this->dirs = array();
		$_POST      = array();
		$_REQUEST   = array();
		parent::tear_down();
	}

	public function filter_active() {
		return $this->active;
	}

	private function html(): string {
		ob_start();
		SettingsPage::render();
		return (string) ob_get_clean();
	}

	private function post( array $data, bool $valid_nonce = true ): void {
		$data['_wpnonce'] = $valid_nonce ? wp_create_nonce( SettingsPage::NONCE ) : 'zly';
		$_POST            = $data;
		$_REQUEST         = $data;
	}

	public function test_page_registered_under_options_for_manage_options() {
		global $submenu;
		$submenu = array();
		SettingsPage::add_menu();
		$slugs = wp_list_pluck( $submenu['options-general.php'] ?? array(), 2 );
		$this->assertContains( SettingsPage::SLUG, $slugs );
		$entry = array_values( wp_list_filter( $submenu['options-general.php'], array( 2 => SettingsPage::SLUG ) ) )[0];
		$this->assertSame( 'manage_options', $entry[1] );
		$this->assertSame( 'Aktio Connector', $entry[0] );
	}

	public function test_save_requires_valid_nonce() {
		$before = Settings::get();
		$this->post( array( 'company' => array( 'name' => 'Hakerska Firma' ) ), false );
		try {
			SettingsPage::handle_save();
			$this->fail( 'Zły nonce powinien przerwać żądanie.' );
		} catch ( WPDieException $e ) {
			$this->assertTrue( true );
		}
		$this->assertSame( $before, Settings::get() );
	}

	public function test_save_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->post( array( 'company' => array( 'name' => 'Redaktor' ) ) );
		$this->expectException( WPDieException::class );
		SettingsPage::handle_save();
	}

	public function test_save_updates_company_and_bots() {
		$this->post(
			array(
				'company' => array(
					'name'    => 'Moja Firma',
					'type'    => 'LocalBusiness',
					'phone'   => '+353 1 234',
					'email'   => 'biuro@example.com',
					'logo'    => 'https://example.com/logo.png',
					'address' => array( 'street' => 'Main 1', 'city' => 'Dublin', 'postal_code' => 'D01', 'country' => 'IE' ),
					'same_as' => "https://facebook.com/x\n\nhttps://instagram.com/x\r\njavascript:alert(1)",
				),
				'bots'    => array( 'GPTBot' => 'block', 'ClaudeBot' => 'allow' ),
			)
		);
		try {
			SettingsPage::handle_save();
			$this->fail( 'Po zapisie oczekiwane przekierowanie.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}
		$s = Settings::get();
		$this->assertSame( 'Moja Firma', $s['company']['name'] );
		$this->assertSame( 'LocalBusiness', $s['company']['type'] );
		$this->assertSame( 'Dublin', $s['company']['address']['city'] );
		$this->assertSame( array( 'https://facebook.com/x', 'https://instagram.com/x' ), $s['company']['same_as'] );
		$this->assertSame( 'block', $s['bots']['GPTBot'] );
		$this->assertSame( 'allow', $s['bots']['ClaudeBot'] );
	}

	public function test_save_without_bots_in_post_keeps_bots() {
		Settings::update( array( 'bots' => array( 'GPTBot' => 'block' ) ) );
		$this->post( array( 'company' => array( 'name' => 'X' ) ) );
		try {
			SettingsPage::handle_save();
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		$this->assertSame( 'block', Settings::get()['bots']['GPTBot'] );
	}

	public function test_page_shows_provided_by_alert() {
		$dir = WP_PLUGIN_DIR . '/wordpress-seo';
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/wp-seo.php', "<?php\n/*\nPlugin Name: Yoast SEO\n*/\n" );
		$this->active[] = 'wordpress-seo/wp-seo.php';
		$this->assertStringContainsString( 'Content schema is provided by Yoast SEO — Aktio Connector does not duplicate it', $this->html() );
	}

	public function test_polish_translation_restores_original_alert_text() {
		$dir = WP_PLUGIN_DIR . '/wordpress-seo';
		mkdir( $dir, 0777, true );
		$this->dirs[] = $dir;
		file_put_contents( $dir . '/wp-seo.php', "<?php\n/*\nPlugin Name: Yoast SEO\n*/\n" );
		$this->active[] = 'wordpress-seo/wp-seo.php';

		$this->assertTrue( load_textdomain( 'aktio-connector', dirname( __DIR__ ) . '/languages/aktio-connector-pl_PL.mo' ) );
		try {
			$html = $this->html();
		} finally {
			unload_textdomain( 'aktio-connector' );
		}
		$this->assertStringContainsString( 'Schema treści dostarcza Yoast SEO — Aktio Connector go nie dubluje', $html );
		$this->assertStringContainsString( 'Aktio korzysta z niej przez hasło aplikacji', $html );
	}

	public function test_page_shows_update_log_read_only() {
		update_option(
			'aktio_connector_update_log',
			array(
				array(
					'at'      => '2026-10-20T10:00:00+00:00',
					'type'    => 'plugin',
					'item'    => 'akismet/akismet',
					'name'    => 'Akismet <i>Anti-spam</i>',
					'from'    => '5.0',
					'to'      => '5.1',
					'result'  => 'updated',
					'by'      => 'aktio',
					'site_ok' => true,
				),
			)
		);
		$html = $this->html();
		$this->assertStringContainsString( 'Akismet &lt;i&gt;Anti-spam&lt;/i&gt;', $html );
		$this->assertStringContainsString( '5.1', $html );
		$this->assertStringContainsString( 'Aktio uses it through an application password', $html );
	}

	public function test_all_output_escaped() {
		// Surowa opcja (np. zaimportowana albo zmieniona poza wtyczką) — ekran nie może jej ufać.
		update_option(
			Settings::OPTION,
			array(
				'company' => array(
					'name'    => '<b>x</b>" onfocus="alert(1)',
					'phone'   => '<script>alert(1)</script>',
					'address' => array( 'city' => '<u>Miasto</u>' ),
					'same_as' => array( 'https://example.com/"><i>' ),
				),
			)
		);
		// Dziennik nie przechodzi przez Settings, więc musi być escapowany na wyjściu.
		update_option( 'aktio_connector_update_log', array( array( 'name' => '<b>x</b>', 'at' => '"><script>', 'result' => 'updated', 'by' => 'aktio' ) ) );
		$html = $this->html();
		$this->assertStringContainsString( '&lt;b&gt;x&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '"><b>', $html );
		$this->assertStringNotContainsString( '<u>Miasto', $html );
		$this->assertStringNotContainsString( 'onfocus="alert', $html );
	}

	public function test_form_has_nonce_and_action() {
		$html = $this->html();
		$this->assertStringContainsString( 'name="action" value="aktio_connector_save"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( admin_url( 'admin-post.php' ), $html );
	}
}
