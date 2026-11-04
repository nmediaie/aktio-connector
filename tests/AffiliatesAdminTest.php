<?php
use AktioConnector\Admin\AffiliatesTab;
use AktioConnector\Admin\SettingsPage;
use AktioConnector\Affiliates\Commissions;
use AktioConnector\Affiliates\Csv;
use AktioConnector\Affiliates\Privacy;
use AktioConnector\Affiliates\Store;
use AktioConnector\Affiliates\Tracker;
use AktioConnector\Settings;

/**
 * Zakładka „Affiliates” w ustawieniach: widoczność, formularze admin-post, generator linku, CSV,
 * pole partnera w kuponie oraz eksporter i eraser danych osobowych.
 */
class AffiliatesAdminTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Store::install();
		global $wpdb;
		foreach ( array( Store::affiliates_table(), Store::commissions_table(), Store::clicks_table() ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( Settings::OPTION );
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
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		SettingsPage::$assume_no_woocommerce = false;
		remove_filter( 'option_active_plugins', '__return_empty_array' );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		remove_all_filters( 'wp_redirect' );
		delete_option( Settings::OPTION );
		delete_transient( 'aktio_aff_flash_' . get_current_user_id() );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	// ------------------------------------------------------------------ Pomocnicze

	private function html( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		SettingsPage::render();
		return (string) ob_get_clean();
	}

	private function submit( string $action, array $data ): void {
		$data['_aktio_aff_nonce'] = wp_create_nonce( $action );
		$_POST                    = $data;
		$_REQUEST                 = $data;
	}

	/** Wywołuje handler admin-post; zwraca adres przekierowania. */
	private function call_handler( string $handler ): string {
		$location = '';
		$capture  = static function ( $url ) use ( &$location ) {
			$location = (string) $url;
			return $url;
		};
		add_filter( 'wp_redirect', $capture, 1 );
		try {
			AffiliatesTab::$handler();
			$this->fail( 'Oczekiwane przekierowanie.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $capture, 1 );
		}
		return $location;
	}

	private function affiliate( array $extra = array() ): array {
		static $n = 0;
		$n++;
		$id = Store::save_affiliate( array_merge( array( 'name' => 'Partner ' . $n ), $extra ) );
		$this->assertIsInt( $id );
		return Store::get_affiliate( $id );
	}

	private function commission( int $affiliate_id, array $extra = array() ): int {
		static $order = 9000;
		$order++;
		return Store::insert_commission(
			array_merge(
				array(
					'order_id'     => $order,
					'affiliate_id' => $affiliate_id,
					'currency'     => 'EUR',
					'base_amount'  => '100',
					'commission'   => '10',
				),
				$extra
			)
		);
	}

	// ---------------------------------------------------------------------- Zakładka

	public function test_tab_hidden_without_woocommerce() {
		SettingsPage::$assume_no_woocommerce = true;
		$html = $this->html();
		$this->assertStringNotContainsString( 'id="aktio-tab-affiliates"', $html );
		$this->assertStringNotContainsString( 'id="aktio-panel-affiliates"', $html );

		SettingsPage::$assume_no_woocommerce = false;
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$this->assertStringContainsString( 'id="aktio-tab-affiliates"', $html );
		$this->assertMatchesRegularExpression( '#<section[^>]*id="aktio-panel-affiliates"(?![^>]*hidden)[^>]*>#', $html );
	}

	public function test_tab_shows_saved_settings_when_module_is_off() {
		Settings::update( array( 'affiliates' => array( 'param' => 'via', 'window_days' => 45, 'rate_bp' => 750, 'hold_days' => 14 ) ) );
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$this->assertStringContainsString( 'value="via"', $html );
		$this->assertStringContainsString( 'value="45"', $html );
		$this->assertStringContainsString( 'value="7.5"', $html );
		$this->assertStringContainsString( 'value="14"', $html );
	}

	public function test_forms_are_not_nested_in_main_settings_form() {
		$this->affiliate();
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$main = substr( $html, (int) strpos( $html, 'id="aktio-settings-form"' ) );
		$main = substr( $main, 0, (int) strpos( $main, '</form>' ) );
		$this->assertStringNotContainsString( 'aktio_aff_', $main );
		$panel = substr( $html, (int) strpos( $html, 'id="aktio-panel-affiliates"' ) );
		$panel = substr( $panel, 0, (int) strpos( $panel, '</section>' ) );
		$this->assertSame( substr_count( $panel, '<form' ), substr_count( $panel, '</form>' ) );
		$this->assertDoesNotMatchRegularExpression( '#<form[^>]*>(?:(?!</form>).)*<form#s', $panel );
	}

	public function test_tab_uses_manage_woocommerce_and_nonce() {
		$a = $this->affiliate();
		$this->commission( $a['id'] );
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		foreach ( array( AffiliatesTab::ACTION_SETTINGS, AffiliatesTab::ACTION_ADD, AffiliatesTab::ACTION_LINK, AffiliatesTab::ACTION_BULK, AffiliatesTab::ACTION_EXPORT ) as $action ) {
			$this->assertMatchesRegularExpression( '#name="action" value="' . $action . '"#', $html, $action );
		}
		$this->assertStringContainsString( 'name="_aktio_aff_nonce"', $html );

		// Bez nonce: odmowa.
		$_POST    = array( 'aff_name' => 'X' );
		$_REQUEST = $_POST;
		$this->expectException( WPDieException::class );
		AffiliatesTab::handle_add();
	}

	public function test_handlers_require_manage_woocommerce() {
		$subscriber = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $subscriber );
		$this->submit( AffiliatesTab::ACTION_ADD, array( 'aff_name' => 'Intruder' ) );
		try {
			AffiliatesTab::handle_add();
			$this->fail( 'Oczekiwana odmowa.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), Store::list_affiliates() );
		}

		// Administrator bez manage_woocommerce też nie przechodzi.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter(
			'user_has_cap',
			$deny = static function ( $caps ) {
				$caps['manage_woocommerce'] = false;
				return $caps;
			}
		);
		$this->submit( AffiliatesTab::ACTION_ADD, array( 'aff_name' => 'Intruder' ) );
		try {
			AffiliatesTab::handle_add();
			$this->fail( 'Oczekiwana odmowa.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), Store::list_affiliates() );
		} finally {
			remove_filter( 'user_has_cap', $deny );
		}
	}

	// ---------------------------------------------------------------------- Ustawienia

	public function test_enable_form_requires_consent_choice() {
		$this->submit( AffiliatesTab::ACTION_SETTINGS, array( 'aff_enabled' => '1', 'aff_param' => 'ref', 'aff_window_days' => '30', 'aff_rate_percent' => '10', 'aff_hold_days' => '30' ) );
		$this->call_handler( 'handle_settings' );
		$this->assertFalse( Settings::get()['affiliates']['enabled'] );
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'consent_api or self_managed', $html );
		// Błąd pokazuje się raz.
		$this->assertStringNotContainsString( 'consent_api or self_managed before', $this->html( array( 'tab' => 'affiliates' ) ) );
	}

	public function test_enable_with_consent_mode_saves() {
		$this->submit( AffiliatesTab::ACTION_SETTINGS, array( 'aff_enabled' => '1', 'aff_consent_mode' => 'self_managed', 'aff_param' => 'go', 'aff_window_days' => '20', 'aff_rate_percent' => '12.5', 'aff_hold_days' => '7', 'aff_delete_on_uninstall' => '1' ) );
		$this->call_handler( 'handle_settings' );
		$saved = Settings::get()['affiliates'];
		$this->assertTrue( $saved['enabled'] );
		$this->assertSame( 'self_managed', $saved['consent_mode'] );
		$this->assertSame( 'go', $saved['param'] );
		$this->assertSame( 20, $saved['window_days'] );
		$this->assertSame( 1250, $saved['rate_bp'] );
		$this->assertSame( 7, $saved['hold_days'] );
		$this->assertTrue( $saved['delete_on_uninstall'] );

		// Odznaczone pola przy zapisie wyłączają moduł i opcję odinstalowania.
		$this->submit( AffiliatesTab::ACTION_SETTINGS, array( 'aff_consent_mode' => 'self_managed', 'aff_param' => 'go', 'aff_window_days' => '20', 'aff_rate_percent' => '12.5', 'aff_hold_days' => '7' ) );
		$this->call_handler( 'handle_settings' );
		$saved = Settings::get()['affiliates'];
		$this->assertFalse( $saved['enabled'] );
		$this->assertFalse( $saved['delete_on_uninstall'] );
	}

	public function test_invalid_rate_is_rejected_and_nothing_is_saved() {
		$this->submit( AffiliatesTab::ACTION_SETTINGS, array( 'aff_consent_mode' => 'self_managed', 'aff_param' => 'go', 'aff_window_days' => '20', 'aff_rate_percent' => 'abc', 'aff_hold_days' => '7' ) );
		$this->call_handler( 'handle_settings' );
		$this->assertSame( 'ref', Settings::get()['affiliates']['param'] );
		$this->assertStringContainsString( 'notice-error', $this->html( array( 'tab' => 'affiliates' ) ) );
	}

	public function test_consent_api_missing_shows_warning() {
		$this->assertFalse( function_exists( 'wp_has_consent' ) );
		Settings::update( array( 'affiliates' => array( 'consent_mode' => 'consent_api' ) ) );
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$this->assertStringContainsString( 'WP Consent API', $html );
		$this->assertStringContainsString( 'notice-warning', $html );

		Settings::update( array( 'affiliates' => array( 'consent_mode' => 'self_managed' ) ) );
		$this->assertStringNotContainsString( 'WP Consent API is not active', $this->html( array( 'tab' => 'affiliates' ) ) );
	}

	// ---------------------------------------------------------------------- Partnerzy

	public function test_add_and_edit_affiliate() {
		$this->submit( AffiliatesTab::ACTION_ADD, array( 'aff_name' => 'Jan Kowalski', 'aff_email' => 'Jan@Example.com', 'aff_code' => '', 'aff_rate_percent' => '5' ) );
		$this->call_handler( 'handle_add' );
		$rows = Store::list_affiliates();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'jan-kowalski', $rows[0]['code'] );
		$this->assertSame( 'jan@example.com', $rows[0]['email'] );
		$this->assertSame( 500, $rows[0]['rate_bp'] );

		$this->submit( AffiliatesTab::ACTION_EDIT, array( 'aff_id' => (string) $rows[0]['id'], 'aff_name' => 'Jan K.', 'aff_email' => '', 'aff_rate_percent' => '', 'aff_status' => 'paused' ) );
		$this->call_handler( 'handle_edit' );
		$row = Store::get_affiliate( $rows[0]['id'] );
		$this->assertSame( 'Jan K.', $row['name'] );
		$this->assertSame( 'paused', $row['status'] );
		$this->assertNull( $row['rate_bp'] );
		$this->assertSame( 'jan-kowalski', $row['code'] );
	}

	public function test_duplicate_code_shows_error() {
		$this->affiliate( array( 'code' => 'taken' ) );
		$this->submit( AffiliatesTab::ACTION_ADD, array( 'aff_name' => 'Other', 'aff_code' => 'taken' ) );
		$this->call_handler( 'handle_add' );
		$this->assertCount( 1, Store::list_affiliates() );
		$this->assertStringContainsString( 'already used', $this->html( array( 'tab' => 'affiliates' ) ) );
	}

	// ---------------------------------------------------------------------- Generator linku

	public function test_link_generator_form() {
		$a = $this->affiliate( array( 'code' => 'abc-123' ) );
		$this->submit( AffiliatesTab::ACTION_LINK, array( 'aff_affiliate' => (string) $a['id'], 'aff_url' => home_url( '/product/hat/?color=red&size=m#reviews' ) ) );
		$this->call_handler( 'handle_link' );
		$expected = Tracker::link_for( home_url( '/product/hat/?color=red&size=m#reviews' ), 'abc-123' );
		$this->assertIsString( $expected );
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$this->assertStringContainsString( esc_attr( $expected ), $html );
		$this->assertStringNotContainsString( 'color=red&size=m', $html, 'Link musi być zakodowany w HTML.' );
	}

	public function test_link_generator_rejects_foreign_address() {
		$a = $this->affiliate( array( 'code' => 'abc-123' ) );
		$this->submit( AffiliatesTab::ACTION_LINK, array( 'aff_affiliate' => (string) $a['id'], 'aff_url' => 'https://evil.example/product' ) );
		$this->call_handler( 'handle_link' );
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$this->assertStringContainsString( 'must point to a page of this shop', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
	}

	// ---------------------------------------------------------------------- Prowizje

	public function test_bulk_action_marks_paid_with_reference() {
		$a  = $this->affiliate();
		$id = $this->commission( $a['id'], array( 'status' => 'approved' ) );
		$this->submit( AffiliatesTab::ACTION_BULK, array( 'bulk_action' => 'paid', 'ids' => array( (string) $id ), 'bulk_ref' => 'TR-1' ) );
		$this->call_handler( 'handle_bulk' );
		$rows = Store::get_commissions_by_ids( array( $id ) );
		// Zamówienia 9xxx nie istnieją: wiersz zostaje bez zmian, ale wynik jest raportowany.
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'notice', $this->html( array( 'tab' => 'affiliates' ) ) );
	}

	public function test_bulk_action_without_ids_is_an_error() {
		$this->submit( AffiliatesTab::ACTION_BULK, array( 'bulk_action' => 'approve' ) );
		$this->call_handler( 'handle_bulk' );
		$this->assertStringContainsString( 'notice-error', $this->html( array( 'tab' => 'affiliates' ) ) );
	}

	public function test_commissions_table_shows_clawback_balance_separately() {
		$a = $this->affiliate();
		$this->commission( $a['id'], array( 'status' => 'paid', 'commission_paid' => '10', 'clawback' => '4', 'clawback_settled_amount' => '1' ) );
		$html = $this->html( array( 'tab' => 'affiliates' ) );
		$this->assertStringContainsString( '3.00 EUR', $html );
		$this->assertStringContainsString( 'aktio-aff-clawback', $html );
	}

	// ---------------------------------------------------------------------- CSV

	public function test_csv_neutralises_formula_cells() {
		foreach ( array( '=HYPERLINK(1)', '+1', '-1', '@a', "\tcmd", "\rcmd" ) as $value ) {
			$this->assertSame( "'" . $value, Csv::cell( $value ), bin2hex( $value ) );
		}
		$this->assertSame( 'plain', Csv::cell( 'plain' ) );
		$this->assertSame( '', Csv::cell( '' ) );
		$this->assertSame( 'a=1', Csv::cell( 'a=1' ) );

		$a   = $this->affiliate( array( 'name' => '=cmd|calc' ) );
		$id  = $this->commission( $a['id'], array( 'payout_ref' => '@SUM(1)', 'clawback' => '2', 'clawback_settled_amount' => '5', 'status' => 'paid' ) );
		$rows = Store::get_commissions_by_ids( array( $id ) );
		$csv = Csv::commissions( $rows, array( $a['id'] => $a ) );
		$this->assertStringContainsString( "'=cmd|calc", $csv );
		$this->assertStringContainsString( "'@SUM(1)", $csv );
		// Kwoty liczbowe (także ujemne saldo) zostają surowe.
		$this->assertStringContainsString( ',-3.0000', $csv );
		$this->assertStringNotContainsString( "'-3.0000", $csv );
		$lines = array_filter( explode( "\r\n", $csv ) );
		$this->assertCount( 2, $lines );
	}

	public function test_csv_export_covers_all_pages_of_filtered_commissions() {
		$a = $this->affiliate();
		$b = $this->affiliate();
		for ( $i = 0; $i < 205; $i++ ) {
			$this->commission( $a['id'], array( 'status' => 'approved' ) );
		}
		$this->commission( $b['id'], array( 'status' => 'approved' ) );
		$this->commission( $a['id'], array( 'status' => 'pending' ) );

		$csv = implode( '', iterator_to_array( AffiliatesTab::csv_chunks( array( 'status' => 'approved', 'affiliate_id' => $a['id'] ) ), false ) );
		$this->assertCount( 206, array_filter( explode( "\r\n", $csv ) ), 'Nagłówek + 205 wierszy.' );

		$all = implode( '', iterator_to_array( AffiliatesTab::csv_chunks( array() ), false ) );
		$this->assertCount( 208, array_filter( explode( "\r\n", $all ) ) );
	}

	public function test_export_requires_nonce() {
		$_POST    = array( 'c_status' => 'approved' );
		$_REQUEST = $_POST;
		$this->expectException( WPDieException::class );
		AffiliatesTab::handle_export();
	}

	// ---------------------------------------------------------------------- Kupon

	private function coupon( string $code ): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->save();
		return $coupon;
	}

	private function save_coupon_field( WC_Coupon $coupon, $value ): void {
		$data     = array( 'aktio_affiliate_id' => (string) $value, 'woocommerce_meta_nonce' => 'x' );
		$_POST    = $data;
		$_REQUEST = $data;
		AffiliatesTab::save_coupon_field( $coupon->get_id(), $coupon );
	}

	public function test_coupon_field_saves_affiliate() {
		$a      = $this->affiliate();
		$coupon = $this->coupon( 'partner10' );
		$this->save_coupon_field( $coupon, $a['id'] );
		$this->assertSame( $a['id'], Store::coupon_owner( 'partner10' ) );
		$this->assertSame( (string) $a['id'], (string) ( new WC_Coupon( $coupon->get_id() ) )->get_meta( Store::COUPON_META ) );

		$this->save_coupon_field( $coupon, '0' );
		$this->assertSame( 0, Store::coupon_owner( 'partner10' ) );
	}

	public function test_coupon_field_cannot_take_another_partners_coupon() {
		$a      = $this->affiliate();
		$b      = $this->affiliate();
		$coupon = $this->coupon( 'shared10' );
		$this->save_coupon_field( $coupon, $a['id'] );
		$this->save_coupon_field( $coupon, $b['id'] );
		$this->assertSame( $a['id'], Store::coupon_owner( 'shared10' ) );
	}

	public function test_coupon_field_cannot_assign_paused_partner() {
		$a      = $this->affiliate( array( 'status' => 'paused' ) );
		$coupon = $this->coupon( 'paused10' );
		$this->save_coupon_field( $coupon, $a['id'] );
		$this->assertSame( 0, Store::coupon_owner( 'paused10' ) );
	}

	public function test_coupon_field_requires_capability() {
		$a      = $this->affiliate();
		$coupon = $this->coupon( 'cap10' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->save_coupon_field( $coupon, $a['id'] );
		$this->assertSame( 0, Store::coupon_owner( 'cap10' ) );
	}

	public function test_coupon_field_renders_select() {
		$a = $this->affiliate( array( 'name' => 'Zed & Co' ) );
		ob_start();
		AffiliatesTab::render_coupon_field();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="aktio_affiliate_id"', $html );
		$this->assertStringContainsString( 'Zed &amp; Co', $html );
		$this->assertStringContainsString( 'value="' . $a['id'] . '"', $html );
	}

	// ---------------------------------------------------------------------- Prywatność

	public function test_privacy_export_and_erase_by_email() {
		$a  = $this->affiliate( array( 'name' => 'Ewa Nowak', 'email' => 'ewa@example.com', 'external_id' => 'contact-9' ) );
		$id = $this->commission( $a['id'], array( 'commission' => '12.5', 'status' => 'approved' ) );

		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		$this->assertArrayHasKey( 'aktio-connector-affiliates', $exporters );
		$this->assertArrayHasKey( 'aktio-connector-affiliates', $erasers );

		$export = Privacy::export( 'Ewa@Example.com' );
		$this->assertTrue( $export['done'] );
		$flat = wp_json_encode( $export['data'] );
		$this->assertStringContainsString( 'Ewa Nowak', $flat );
		$this->assertStringContainsString( 'ewa@example.com', $flat );
		$this->assertStringContainsString( $a['code'], $flat );
		$this->assertSame( array(), Privacy::export( 'nobody@example.com' )['data'] );

		$result = Privacy::erase( 'ewa@example.com' );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, $result['items_removed'] );
		$row = Store::get_affiliate( $a['id'] );
		$this->assertSame( '', $row['email'] );
		$this->assertSame( '', $row['external_id'] );
		$this->assertNotSame( 'Ewa Nowak', $row['name'] );
		$this->assertSame( 'Deleted partner', $row['name'] );
		$this->assertSame( 'paused', $row['status'] );
		$commission = Store::get_commissions_by_ids( array( $id ) )[0];
		$this->assertSame( '12.5000', $commission['commission'] );
		$this->assertSame( $a['id'], $commission['affiliate_id'] );

		$this->assertSame( array(), Privacy::export( 'ewa@example.com' )['data'] );
		$again = Privacy::erase( 'ewa@example.com' );
		$this->assertSame( 0, $again['items_removed'] );
	}

	public function test_privacy_ignores_empty_email() {
		$this->affiliate( array( 'email' => '' ) );
		$this->assertSame( array(), Privacy::export( '' )['data'] );
		$this->assertSame( 0, Privacy::erase( '' )['items_removed'] );
	}

	// ---------------------------------------------------------------------- readme

	public function test_plain_metrics_privacy_text_unchanged() {
		$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
		$this->assertStringContainsString( '**Privacy of AI visit measurement**', $readme );
		$this->assertStringContainsString( 'No cookies and no visitor identifiers are used.', $readme );
		$this->assertStringContainsString( '**Privacy of the affiliate programme**', $readme );
		$this->assertStringContainsString( 'aktio_ref', $readme );
		$this->assertStringContainsString( '= 0.3.0 =', $readme );
		$this->assertStringContainsString( 'Stable tag: 0.3.0', $readme );
		$this->assertSame( 1, preg_match( '/Version:\s+0\.3\.0/', (string) file_get_contents( dirname( __DIR__ ) . '/aktio-connector.php' ) ) );
		$this->assertSame( '0.3.0', AKTIO_CONNECTOR_VERSION );
	}
}
