<?php
use AktioConnector\Affiliates\Store;
use AktioConnector\Settings;

class AffiliatesStoreTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Store::install();
		global $wpdb;
		foreach ( array( Store::affiliates_table(), Store::commissions_table(), Store::clicks_table() ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( Settings::OPTION );
	}

	public function tear_down() {
		delete_option( Settings::OPTION );
		// Test uninstall prawdziwie kasuje tabele (DROP TABLE niszczy transakcję testu) — odtwarzamy je.
		Store::install();
		parent::tear_down();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB
	}

	public function test_defaults_module_off() {
		$a = Settings::get()['affiliates'];
		$this->assertFalse( $a['enabled'] );
		$this->assertSame( 'ref', $a['param'] );
		$this->assertSame( 30, $a['window_days'] );
		$this->assertSame( 1000, $a['rate_bp'] );
		$this->assertSame( 30, $a['hold_days'] );
		$this->assertSame( '', $a['consent_mode'] );
		$this->assertFalse( $a['delete_on_uninstall'] );
		$this->assertFalse( Store::enabled() );
	}

	public function test_enable_requires_consent_mode() {
		$errors = Settings::partial_errors( array( 'affiliates' => array( 'enabled' => true ) ) );
		$this->assertArrayHasKey( 'affiliates.consent_mode', $errors );
		$s = Settings::update( array( 'affiliates' => array( 'enabled' => true ) ) );
		$this->assertFalse( $s['affiliates']['enabled'] );
		$this->assertFalse( Settings::get()['affiliates']['enabled'] );

		$this->assertSame( array(), Settings::partial_errors( array( 'affiliates' => array( 'enabled' => true, 'consent_mode' => 'self_managed' ) ) ) );
		$s = Settings::update( array( 'affiliates' => array( 'enabled' => true, 'consent_mode' => 'self_managed' ) ) );
		$this->assertTrue( $s['affiliates']['enabled'] );
		$this->assertSame( 'self_managed', $s['affiliates']['consent_mode'] );
	}

	public function test_param_rejects_public_query_vars() {
		$this->assertArrayHasKey( 'affiliates.param', Settings::partial_errors( array( 'affiliates' => array( 'param' => 'p' ) ) ) );
		$this->assertArrayHasKey( 'affiliates.param', Settings::partial_errors( array( 'affiliates' => array( 'param' => 'page' ) ) ) );
		$this->assertArrayHasKey( 'affiliates.param', Settings::partial_errors( array( 'affiliates' => array( 'param' => 'bad param' ) ) ) );
		$this->assertSame( array(), Settings::partial_errors( array( 'affiliates' => array( 'param' => 'ref' ) ) ) );
		$this->assertSame( 'ref', Settings::update( array( 'affiliates' => array( 'param' => 'p' ) ) )['affiliates']['param'] );
	}

	public function test_numeric_ranges() {
		$errors = Settings::partial_errors( array( 'affiliates' => array( 'window_days' => 0, 'hold_days' => 366, 'rate_bp' => 10001 ) ) );
		$this->assertArrayHasKey( 'affiliates.window_days', $errors );
		$this->assertArrayHasKey( 'affiliates.hold_days', $errors );
		$this->assertArrayHasKey( 'affiliates.rate_bp', $errors );
		$this->assertArrayHasKey( 'affiliates.nope', Settings::partial_errors( array( 'affiliates' => array( 'nope' => 1 ) ) ) );
	}

	public function test_save_affiliate_generates_unique_code() {
		$a = Store::save_affiliate( array( 'name' => 'Jan Kowalski' ) );
		$b = Store::save_affiliate( array( 'name' => 'Jan Kowalski' ) );
		$this->assertIsInt( $a );
		$this->assertIsInt( $b );
		$first  = Store::get_affiliate( $a );
		$second = Store::get_affiliate( $b );
		$this->assertSame( 'jan-kowalski', $first['code'] );
		$this->assertNotSame( $first['code'], $second['code'] );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]{3,32}\z/', $second['code'] );
		$this->assertSame( 'active', $first['status'] );
		$this->assertNull( $first['rate_bp'] );
		$this->assertSame( $a, Store::get_by_code( 'jan-kowalski' )['id'] );
		$this->assertNull( Store::get_by_code( 'nie-ma' ) );
		$this->assertCount( 2, Store::list_affiliates() );
	}

	public function test_save_affiliate_validates() {
		$this->assertWPError( Store::save_affiliate( array( 'name' => '' ) ) );
		$this->assertWPError( Store::save_affiliate( array( 'name' => 'X', 'email' => 'nie-email' ) ) );
		$this->assertWPError( Store::save_affiliate( array( 'name' => 'X', 'code' => 'A!' ) ) );
		$this->assertWPError( Store::save_affiliate( array( 'name' => 'X', 'rate_bp' => 10001 ) ) );
		$id = Store::save_affiliate( array( 'name' => 'Ola', 'code' => 'ola' ) );
		$this->assertWPError( Store::save_affiliate( array( 'name' => 'Ola 2', 'code' => 'ola' ) ) );
		$this->assertSame( $id, Store::save_affiliate( array( 'id' => $id, 'status' => 'paused', 'rate_bp' => 500, 'email' => 'Ola@Example.com' ) ) );
		$row = Store::get_affiliate( $id );
		$this->assertSame( 'paused', $row['status'] );
		$this->assertSame( 500, $row['rate_bp'] );
		$this->assertSame( 'ola@example.com', $row['email'] );
		$this->assertSame( 'Ola', $row['name'] );
	}

	public function test_code_locked_after_click() {
		$id = Store::save_affiliate( array( 'name' => 'Ola' ) );
		$this->assertSame( $id, Store::save_affiliate( array( 'id' => $id, 'code' => 'ola-nowa' ) ) );
		Store::add_click( $id );
		Store::add_click( $id );
		$error = Store::save_affiliate( array( 'id' => $id, 'code' => 'inny-kod' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'aktio_aff_code_locked', $error->get_error_code() );
		// Ten sam kod to nie zmiana.
		$this->assertSame( $id, Store::save_affiliate( array( 'id' => $id, 'code' => 'ola-nowa', 'name' => 'Ola K' ) ) );
		global $wpdb;
		$this->assertSame( '2', $wpdb->get_var( 'SELECT `count` FROM ' . Store::clicks_table() ) ); // phpcs:ignore WordPress.DB
	}

	public function test_insert_commission_is_idempotent_per_order() {
		$id  = Store::save_affiliate( array( 'name' => 'Ola' ) );
		$row = array(
			'order_id'     => 501,
			'affiliate_id' => $id,
			'source'       => 'link',
			'base_amount'  => '100.00',
			'base_original' => '100.00',
			'rate_bp'      => 1000,
			'commission'   => '10.00',
			'currency'     => 'PLN',
		);
		$first = Store::insert_commission( $row );
		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, Store::insert_commission( $row ) );

		$c = Store::commission_for_order( 501 );
		$this->assertSame( $first, $c['id'] );
		$this->assertSame( 'pending', $c['status'] );
		$this->assertSame( '10.0000', $c['commission'] );
		$this->assertNull( $c['paid_at'] );
		$this->assertNull( Store::commission_for_order( 999 ) );

		Store::update_commission( $first, array( 'status' => 'approved', 'order_id' => 777 ) );
		$c = Store::get_commissions_by_ids( array( $first, 123456 ) );
		$this->assertCount( 1, $c );
		$this->assertSame( 'approved', $c[0]['status'] );
		$this->assertSame( 501, $c[0]['order_id'] );
	}

	public function test_query_commissions_filters_and_totals() {
		$a = Store::save_affiliate( array( 'name' => 'A' ) );
		$b = Store::save_affiliate( array( 'name' => 'B' ) );
		foreach ( array( array( 1, $a, 'pending' ), array( 2, $a, 'approved' ), array( 3, $b, 'approved' ) ) as $r ) {
			Store::insert_commission( array( 'order_id' => $r[0], 'affiliate_id' => $r[1], 'status' => $r[2], 'currency' => 'PLN' ) );
		}
		$all = Store::query_commissions( array() );
		$this->assertSame( 3, $all['total'] );
		$this->assertSame( 3, $all['items'][0]['order_id'] );
		$this->assertSame( 2, Store::query_commissions( array( 'status' => 'approved' ) )['total'] );
		$this->assertSame( 1, Store::query_commissions( array( 'status' => 'approved', 'affiliate_id' => $b ) )['total'] );
		$page = Store::query_commissions( array( 'per_page' => 2, 'page' => 2 ) );
		$this->assertSame( 3, $page['total'] );
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( 0, Store::query_commissions( array( 'from' => '2999-01-01' ) )['total'] );
	}

	public function test_coupon_assignment() {
		$id     = Store::save_affiliate( array( 'name' => 'Ola' ) );
		$coupon = new WC_Coupon();
		$coupon->set_code( 'olarabat' );
		$coupon->save();

		$this->assertTrue( Store::assign_coupon( $id, 'olarabat' ) );
		$this->assertSame( (string) $id, (string) ( new WC_Coupon( 'olarabat' ) )->get_meta( '_aktio_affiliate_id' ) );
		$this->assertWPError( Store::assign_coupon( $id, 'brak-takiego' ) );
		$this->assertWPError( Store::assign_coupon( 99999, 'olarabat' ) );

		$this->assertTrue( Store::unassign_coupon( 'olarabat' ) );
		$this->assertSame( '', (string) ( new WC_Coupon( 'olarabat' ) )->get_meta( '_aktio_affiliate_id' ) );
		$this->assertWPError( Store::unassign_coupon( 'brak-takiego' ) );

		Store::save_affiliate( array( 'id' => $id, 'status' => 'paused' ) );
		$this->assertWPError( Store::assign_coupon( $id, 'olarabat' ) );
	}

	public function test_uninstall_keeps_tables_unless_opted_in() {
		Store::add_click( Store::save_affiliate( array( 'name' => 'Ola' ) ) );
		set_transient( 'aktio_ar_test', 1, 60 );
		wp_schedule_event( time() + 3600, 'daily', Store::CRON );

		Store::uninstall();
		$this->assertTrue( $this->table_exists( Store::affiliates_table() ) );
		$this->assertTrue( $this->table_exists( Store::commissions_table() ) );
		$this->assertTrue( $this->table_exists( Store::clicks_table() ) );
		wp_cache_flush(); // transienty kasowane SQL-em, a biblioteka testowa trzyma je w cache obiektów.
		$this->assertFalse( get_transient( 'aktio_ar_test' ) );
		$this->assertFalse( wp_next_scheduled( Store::CRON ) );
		$this->assertNotFalse( get_option( Store::SCHEMA_OPTION ) );

		Settings::update( array( 'affiliates' => array( 'delete_on_uninstall' => true ) ) );
		// DROP TABLE ma być prawdziwy, nie tymczasowy z biblioteki testowej.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Store::uninstall();
		$this->assertFalse( $this->table_exists( Store::affiliates_table() ) );
		$this->assertFalse( $this->table_exists( Store::commissions_table() ) );
		$this->assertFalse( $this->table_exists( Store::clicks_table() ) );
		$this->assertFalse( get_option( Store::SCHEMA_OPTION ) );
	}

	public function test_hpos_declared() {
		do_action( 'before_woocommerce_init' );
		$lists = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );
		$this->assertContains( plugin_basename( AKTIO_CONNECTOR_FILE ), $lists['compatible'] );
	}

	public function test_code_stays_locked_after_clicks_are_pruned() {
		global $wpdb;
		$id = Store::save_affiliate( array( 'name' => 'Ola' ) );
		Store::add_click( $id );
		$wpdb->query( 'DELETE FROM ' . Store::clicks_table() ); // phpcs:ignore WordPress.DB
		$error = Store::save_affiliate( array( 'id' => $id, 'code' => 'inny-kod' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'aktio_aff_code_locked', $error->get_error_code() );
	}

	public function test_code_locked_after_commission() {
		$id = Store::save_affiliate( array( 'name' => 'Ola' ) );
		$this->assertSame( $id, Store::save_affiliate( array( 'id' => $id, 'code' => 'ola-2' ) ) );
		Store::insert_commission( array( 'order_id' => 601, 'affiliate_id' => $id ) );
		$error = Store::save_affiliate( array( 'id' => $id, 'code' => 'inny-kod' ) );
		$this->assertWPError( $error );
		$this->assertSame( 'aktio_aff_code_locked', $error->get_error_code() );
	}

	public function test_insert_commission_logs_non_duplicate_failure_only() {
		$log = tempnam( sys_get_temp_dir(), 'aktio-log' );
		$old = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		$id  = Store::save_affiliate( array( 'name' => 'Ola' ) );
		$row = array( 'order_id' => 701, 'affiliate_id' => $id );

		$this->assertGreaterThan( 0, Store::insert_commission( $row ) );
		$this->assertSame( 0, Store::insert_commission( $row ) ); // duplikat: cicho.
		$this->assertSame( '', (string) file_get_contents( $log ) );

		$break = function ( $q ) {
			return 0 === strpos( $q, 'INSERT INTO `' . Store::commissions_table() ) ? 'INSERT INTO aktio_no_such_table (x) VALUES (1)' : $q;
		};
		add_filter( 'query', $break );
		$this->assertSame( 0, Store::insert_commission( array( 'order_id' => 702, 'affiliate_id' => $id ) ) );
		remove_filter( 'query', $break );
		$logged = (string) file_get_contents( $log );
		$this->assertStringContainsString( 'commission insert failed for order 702', $logged );
		ini_set( 'error_log', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet
		unlink( $log );
	}

	public function test_update_commission_reports_failure_and_logs_no_values() {
		$id  = Store::save_affiliate( array( 'name' => 'Ola' ) );
		$row = Store::insert_commission( array( 'order_id' => 711, 'affiliate_id' => $id ) );

		$this->assertTrue( Store::update_commission( $row, array( 'payout_ref' => 'A' ) ) );
		$this->assertTrue( Store::update_commission( $row, array( 'payout_ref' => 'A' ) ), 'No change is not a failure.' );

		$log = tempnam( sys_get_temp_dir(), 'aktio-log' );
		$old = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		$break = function ( $q ) {
			return 0 === strpos( $q, 'UPDATE `' . Store::commissions_table() ) ? 'UPDATE aktio_no_such_table SET x = 1' : $q;
		};
		add_filter( 'query', $break );
		$this->assertFalse( Store::update_commission( $row, array( 'payout_ref' => 'SECRET-REF' ) ) );
		$this->assertFalse( Store::update_commission_if_status( $row, 'pending', array( 'payout_ref' => 'SECRET-REF' ) ) );
		remove_filter( 'query', $break );
		ini_set( 'error_log', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet
		$logged = (string) file_get_contents( $log );
		unlink( $log );

		$this->assertStringContainsString( 'commission update failed for row ' . $row, $logged );
		$this->assertStringNotContainsString( 'SECRET-REF', $logged );
	}

	public function test_update_commission_if_status_writes_only_on_expected_status() {
		$id  = Store::save_affiliate( array( 'name' => 'Ola' ) );
		$row = Store::insert_commission( array( 'order_id' => 712, 'affiliate_id' => $id ) );

		$this->assertFalse( Store::update_commission_if_status( $row, 'approved', array( 'status' => 'paid' ) ) );
		$this->assertSame( 'pending', Store::commission_for_order( 712 )['status'] );

		$this->assertTrue( Store::update_commission_if_status( $row, 'pending', array( 'status' => 'approved', 'order_id' => 999 ) ) );
		$after = Store::commission_for_order( 712 );
		$this->assertSame( 'approved', $after['status'] );
		$this->assertSame( $row, $after['id'], 'order_id stays immutable.' );

		$this->assertFalse( Store::update_commission_if_status( $row, 'approved', array( 'unknown_column' => 'x' ) ), 'Nothing to write.' );
	}

	public function test_schema_upgrade_adds_clawback_settled_amount() {
		global $wpdb;
		$table = Store::commissions_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN clawback_settled_amount" ); // phpcs:ignore WordPress.DB
		update_option( Store::SCHEMA_OPTION, '2', false );

		Store::maybe_install();

		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'clawback_settled_amount'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertSame( 'decimal(19,4)', $column['Type'] );
		$this->assertSame( 'NO', $column['Null'] );
		$this->assertSame( (string) Store::SCHEMA, get_option( Store::SCHEMA_OPTION ) );
		$this->assertSame( 3, Store::SCHEMA );
	}

	public function test_save_affiliate_failure_is_not_reported_as_code_taken() {
		$break = function ( $q ) {
			return 0 === strpos( $q, 'INSERT INTO `' . Store::affiliates_table() ) ? 'INSERT INTO aktio_no_such_table (x) VALUES (1)' : $q;
		};
		add_filter( 'query', $break );
		$error = Store::save_affiliate( array( 'name' => 'Ola' ) );
		remove_filter( 'query', $break );
		$this->assertWPError( $error );
		$this->assertSame( 'aktio_aff_save_failed', $error->get_error_code() );
	}

	public function test_generated_code_retries_on_race_and_stays_within_32() {
		// Wyścig: pierwszy kandydat zajęty tuż przed insertem (symulacja przez wstawienie w filtrze).
		$raced = false;
		$race  = function ( $q ) use ( &$raced ) {
			global $wpdb;
			if ( ! $raced && 0 === strpos( $q, 'INSERT INTO `' . Store::affiliates_table() ) ) {
				$raced = true;
				$wpdb->insert( Store::affiliates_table(), array( 'code' => 'jan', 'name' => 'X', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );
			}
			return $q;
		};
		add_filter( 'query', $race );
		$id = Store::save_affiliate( array( 'name' => 'Jan' ) );
		remove_filter( 'query', $race );
		$this->assertIsInt( $id );
		$this->assertSame( 'jan-2', Store::get_affiliate( $id )['code'] );

		$long = str_repeat( 'a', 40 );
		$one  = Store::get_affiliate( Store::save_affiliate( array( 'name' => $long ) ) )['code'];
		$two  = Store::get_affiliate( Store::save_affiliate( array( 'name' => $long ) ) )['code'];
		$this->assertSame( 32, strlen( $one ) );
		$this->assertLessThanOrEqual( 32, strlen( $two ) );
		$this->assertNotSame( $one, $two );
		$this->assertMatchesRegularExpression( '/^[a-z0-9-]{3,32}\z/', $two );
	}
}
