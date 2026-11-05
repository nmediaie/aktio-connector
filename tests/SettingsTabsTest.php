<?php
use AktioConnector\Admin\SettingsPage;
use AktioConnector\Settings;

/**
 * Ekran ustawień w zakładkach: nawigacja, pola wyboru zamiast kodów wpisywanych ręcznie,
 * godziny otwarcia i współrzędne, przegląd z listą braków, zakładka „O Aktio”.
 */
class SettingsTabsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter( 'option_active_plugins', '__return_empty_array' );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		add_filter(
			'wp_redirect',
			static function () {
				throw new RuntimeException( 'redirect' );
			}
		);
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		SettingsPage::$assume_no_woocommerce = false;
	}

	public function tear_down() {
		SettingsPage::$assume_no_woocommerce = false;
		remove_filter( 'option_active_plugins', '__return_empty_array' );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		remove_all_filters( 'wp_redirect' );
		delete_option( Settings::OPTION );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function html( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		SettingsPage::render();
		return (string) ob_get_clean();
	}

	private function post( array $data ): void {
		$data['_wpnonce'] = wp_create_nonce( SettingsPage::NONCE );
		$_POST            = $data;
		$_REQUEST         = $data;
	}

	private function redirect_location(): string {
		$location = '';
		$capture  = static function ( $url ) use ( &$location ) {
			$location = (string) $url;
			return $url;
		};
		add_filter( 'wp_redirect', $capture, 1 );
		try {
			SettingsPage::handle_save();
			$this->fail( 'Oczekiwane przekierowanie.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
		}
		return $location;
	}

	/** Atrybuty zakładki o danym slugu (pusty string, gdy jej nie ma). */
	private function tab( string $html, string $slug ): string {
		return preg_match( '#<a[^>]*id="aktio-tab-' . $slug . '"[^>]*>#', $html, $m ) ? $m[0] : '';
	}

	/** Otwierający znacznik panelu zakładki. */
	private function panel( string $html, string $slug ): string {
		return preg_match( '#<section[^>]*id="aktio-panel-' . $slug . '"[^>]*>#', $html, $m ) ? $m[0] : '';
	}

	public function test_tabs_are_accessible_and_overview_is_default() {
		$html = $this->html();
		$this->assertStringContainsString( 'role="tablist"', $html );
		foreach ( array( 'overview', 'company', 'seo', 'store', 'bots', 'visibility', 'tools', 'about' ) as $slug ) {
			$tab = $this->tab( $html, $slug );
			$this->assertNotSame( '', $tab, $slug );
			$this->assertStringContainsString( 'role="tab"', $tab, $slug );
			$this->assertStringContainsString( 'aria-controls="aktio-panel-' . $slug . '"', $tab, $slug );
			$this->assertStringContainsString( 'tab=' . $slug, $tab, $slug );
			$this->assertStringContainsString( 'role="tabpanel"', $this->panel( $html, $slug ), $slug );
		}
		$this->assertStringContainsString( 'aria-selected="true"', $this->tab( $html, 'overview' ) );
		$this->assertStringContainsString( 'aria-selected="false"', $this->tab( $html, 'seo' ) );
		$this->assertStringNotContainsString( 'hidden', $this->panel( $html, 'overview' ) );
		$this->assertStringContainsString( 'hidden', $this->panel( $html, 'seo' ) );
	}

	public function test_tab_from_query_and_unknown_tab_falls_back() {
		$html = $this->html( array( 'tab' => 'seo' ) );
		$this->assertStringContainsString( 'aria-selected="true"', $this->tab( $html, 'seo' ) );
		$this->assertStringContainsString( 'hidden', $this->panel( $html, 'overview' ) );
		$this->assertStringContainsString( 'name="aktio_tab" value="seo"', $html );

		$html = $this->html( array( 'tab' => '"><script>' ) );
		$this->assertStringContainsString( 'aria-selected="true"', $this->tab( $html, 'overview' ) );
		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_store_tab_only_with_woocommerce() {
		SettingsPage::$assume_no_woocommerce = true;
		$html = $this->html( array( 'tab' => 'store' ) );
		$this->assertSame( '', $this->tab( $html, 'store' ) );
		$this->assertStringContainsString( 'aria-selected="true"', $this->tab( $html, 'overview' ) );
	}

	public function test_save_redirects_back_to_the_same_tab() {
		$this->post( array( 'aktio_tab' => 'bots', 'bots' => array( 'GPTBot' => 'block' ) ) );
		$this->assertStringContainsString( 'tab=bots', $this->redirect_location() );

		$this->post( array( 'aktio_tab' => 'nie-ma-takiej' ) );
		$this->assertStringNotContainsString( 'tab=', $this->redirect_location() );
	}

	public function test_validation_error_opens_tab_of_the_field() {
		set_transient( 'aktio_connector_errors_' . get_current_user_id(), array( 'verification.bing' => 'verification.bing must be x' ), MINUTE_IN_SECONDS );
		$html = $this->html( array( 'invalid' => '1' ) );
		$this->assertStringContainsString( 'aria-selected="true"', $this->tab( $html, 'seo' ) );
		$this->assertStringContainsString( 'verification.bing must be x', $html );
	}

	public function test_content_language_is_a_select_with_site_language_first() {
		$html = $this->html();
		$this->assertMatchesRegularExpression( '#<select[^>]*id="aktio-content-language"[^>]*name="content_language"#', $html );
		$this->assertMatchesRegularExpression( '#<option value=""[^>]*>[^<]*' . preg_quote( str_replace( '_', '-', get_locale() ), '#' ) . '#', $html );
		$this->assertStringContainsString( '<option value="de-DE"', $html );
		$this->assertStringContainsString( '<option value="pl"', $html );

		Settings::update( array( 'content_language' => 'en-IE' ) );
		$this->assertMatchesRegularExpression( '#<option value="en-IE"[^>]*selected#', $this->html() );

		// Kod spoza listy nie znika po wejściu na ekran.
		Settings::update( array( 'content_language' => 'xh-ZA' ) );
		$this->assertMatchesRegularExpression( '#<option value="xh-ZA"[^>]*selected#', $this->html() );
	}

	public function test_countries_are_selects_with_woocommerce() {
		$html = $this->html();
		$this->assertMatchesRegularExpression( '#<select[^>]*name="company\[address\]\[country\]"#', $html );
		$this->assertMatchesRegularExpression( '#<select[^>]*name="merchant\[return_policy\]\[country\]"#', $html );
		$this->assertMatchesRegularExpression( '#<select[^>]*name="merchant\[shipping\]\[country\]"#', $html );
		$this->assertStringContainsString( '<option value="PL"', $html );

		// Dawny wpis tekstem (np. „Polska”) zostaje jako wybrana opcja.
		Settings::update( array( 'company' => array( 'address' => array( 'country' => 'Polska' ) ) ) );
		$this->assertMatchesRegularExpression( '#<option value="Polska"[^>]*selected#', $this->html() );
	}

	public function test_address_country_is_text_without_woocommerce() {
		SettingsPage::$assume_no_woocommerce = true;
		$this->assertMatchesRegularExpression( '#<input[^>]*name="company\[address\]\[country\]"#', $this->html() );
	}

	public function test_company_type_radio_cards() {
		$html = $this->html();
		$this->assertMatchesRegularExpression( '#<input type="radio"[^>]*name="company\[type\]" value="Organization"[^>]*checked#', $html );
		$this->assertMatchesRegularExpression( '#<input type="radio"[^>]*name="company\[type\]" value="LocalBusiness"#', $html );
	}

	public function test_customer_service_languages_are_checkboxes() {
		Settings::update( array( 'company' => array( 'contact_point' => array( 'languages' => array( 'pl', 'Klingon' ) ) ) ) );
		$html = $this->html();
		$this->assertMatchesRegularExpression( '#<input type="checkbox"[^>]*name="company\[contact_point\]\[languages\]\[\]" value="pl"[^>]*checked#', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox"[^>]*name="company\[contact_point\]\[languages\]\[\]" value="en"#', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox"[^>]*value="Klingon"[^>]*checked#', $html );

		$this->post( array( 'company' => array( 'contact_point' => array( 'languages' => array( 'pl', 'de' ) ) ) ) );
		SettingsPage::save( $_POST );
		$this->assertSame( array( 'pl', 'de' ), Settings::get()['company']['contact_point']['languages'] );

		// Wszystko odznaczone: przychodzi tylko ukryte puste pole.
		SettingsPage::save( array( 'company' => array( 'contact_point' => array( 'languages' => '' ) ) ) );
		$this->assertSame( array(), Settings::get()['company']['contact_point']['languages'] );
	}

	public function test_opening_hours_and_geo_are_saved_from_form() {
		SettingsPage::save(
			array(
				'company' => array(
					'type'          => 'LocalBusiness',
					'opening_hours' => array(
						array( 'days' => array( 'Mo', 'Tu', 'We', 'Th', 'Fr' ), 'opens' => '09:00', 'closes' => '17:00' ),
						array( 'opens' => '', 'closes' => '' ),
						array( 'days' => array( 'Sa' ), 'opens' => '10:00', 'closes' => '14:00' ),
					),
					'geo'           => array( 'lat' => '54,4386', 'lng' => '16.9876' ),
				),
			)
		);
		$company = Settings::get()['company'];
		$this->assertSame(
			array(
				array( 'days' => array( 'Mo', 'Tu', 'We', 'Th', 'Fr' ), 'opens' => '09:00', 'closes' => '17:00' ),
				array( 'days' => array( 'Sa' ), 'opens' => '10:00', 'closes' => '14:00' ),
			),
			$company['opening_hours']
		);
		$this->assertSame( array( 'lat' => 54.4386, 'lng' => 16.9876 ), $company['geo'] );

		SettingsPage::save( array( 'company' => array( 'opening_hours' => '', 'geo' => array( 'lat' => '', 'lng' => '' ) ) ) );
		$company = Settings::get()['company'];
		$this->assertSame( array(), $company['opening_hours'] );
		$this->assertNull( $company['geo'] );

		// Niepełne współrzędne blokują zapis z błędem pola.
		$errors = SettingsPage::save( array( 'company' => array( 'geo' => array( 'lat' => '54.4', 'lng' => '' ) ) ) );
		$this->assertArrayHasKey( 'company.geo', $errors );
	}

	public function test_opening_hours_fields_rendered_with_existing_rows() {
		Settings::update(
			array(
				'company' => array(
					'type'          => 'LocalBusiness',
					'opening_hours' => array( array( 'days' => array( 'Sa' ), 'opens' => '10:00', 'closes' => '14:00' ) ),
				),
			)
		);
		$html = $this->html( array( 'tab' => 'company' ) );
		$this->assertMatchesRegularExpression( '#name="company\[opening_hours\]\[0\]\[days\]\[\]" value="Sa"[^>]*checked#', $html );
		$this->assertStringContainsString( 'name="company[opening_hours][0][opens]" value="10:00"', $html );
		$this->assertStringContainsString( 'name="company[opening_hours][1][opens]" value=""', $html );
		$this->assertStringContainsString( 'name="company[geo][lat]"', $html );
	}

	public function test_bots_are_switches_with_descriptions() {
		Settings::update( array( 'bots' => array( 'GPTBot' => 'block' ) ) );
		$html = $this->html( array( 'tab' => 'bots' ) );
		$this->assertStringContainsString( '<input type="hidden" name="bots[GPTBot]" value="block" />', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox"[^>]*name="bots\[GPTBot\]" value="allow"(?![^>]*checked)[^>]*>#', $html );
		$this->assertMatchesRegularExpression( '#<input type="checkbox"[^>]*name="bots\[ClaudeBot\]" value="allow"[^>]*checked#', $html );
		$this->assertStringContainsString( 'OpenAI', $html );
		$this->assertStringContainsString( 'Anthropic', $html );
	}

	public function test_overview_lists_missing_site_data() {
		update_option( 'blogname', '' );
		update_option( 'blogdescription', 'Just another WordPress site' );
		Settings::update( array( 'company' => array( 'type' => 'LocalBusiness' ) ) );
		$html = $this->html();
		foreach ( array( 'site-title', 'site-tagline', 'address', 'logo', 'company-description' ) as $check ) {
			$this->assertStringContainsString( 'data-check="' . $check . '"', $html, $check );
		}
		$this->assertStringContainsString( esc_url( admin_url( 'options-general.php' ) ), $html );

		update_option( 'blogname', 'Plantacja' );
		update_option( 'blogdescription', 'Lawenda z Kodrąbka' );
		Settings::update(
			array(
				'company' => array(
					'type'        => 'Organization',
					'logo'        => 'https://example.org/logo.png',
					'description' => 'Plantacja lawendy',
				),
			)
		);
		$html = $this->html();
		$this->assertStringNotContainsString( 'data-check=', $html );
		$this->assertStringContainsString( 'aktio-all-set', $html );
	}

	public function test_overview_has_quick_links() {
		$html = $this->html();
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/robots.txt' ) ) . '"', $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/llms.txt' ) ) . '"', $html );
	}

	public function test_about_tab_has_logo_and_link_to_aktio() {
		$html = $this->html( array( 'tab' => 'about' ) );
		$this->assertMatchesRegularExpression( '#<img[^>]*src="[^"]*assets/img/aktio-logo\.png[^"]*"[^>]*alt="Aktio"#', $html );
		$this->assertStringContainsString( 'href="https://aktioapp.com/', $html );
		$this->assertStringContainsString( 'rel="noopener"', $html );
	}

	public function test_styles_enqueued_only_on_own_screen() {
		wp_dequeue_style( 'aktio-settings' );
		wp_deregister_style( 'aktio-settings' );
		SettingsPage::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_style_is( 'aktio-settings', 'enqueued' ) );
		SettingsPage::enqueue_assets( 'settings_page_' . SettingsPage::SLUG );
		$this->assertTrue( wp_style_is( 'aktio-settings', 'enqueued' ) );
	}
}
