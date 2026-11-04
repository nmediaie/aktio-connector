<?php
use AktioConnector\Affiliates\Attribution;
use AktioConnector\Affiliates\Commissions;
use AktioConnector\Affiliates\Store;
use AktioConnector\Affiliates\Tracker;
use AktioConnector\Settings;

class AffiliatesRestTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Store::install();
		global $wpdb;
		foreach ( array( Store::affiliates_table(), Store::commissions_table(), Store::clicks_table() ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( Settings::OPTION );
		Settings::update( array( 'affiliates' => array( 'enabled' => true, 'consent_mode' => 'self_managed' ) ) );
		unset( $_COOKIE[ Tracker::COOKIE ] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
	}

	public function tear_down() {
		unset( $_COOKIE[ Tracker::COOKIE ] );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	// ------------------------------------------------------------------ Pomocnicze

	private function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/aktio/v1/' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $request );
	}

	private function affiliate( array $extra = array() ): array {
		static $n = 0;
		$n++;
		$id = Store::save_affiliate( array_merge( array( 'name' => 'Partner ' . $n ), $extra ) );
		$this->assertIsInt( $id );
		return Store::get_affiliate( $id );
	}

	private function order( float $amount = 80.0, string $currency = 'EUR' ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test product' );
		$product->set_regular_price( (string) $amount );
		$product->save();
		$item = new WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_quantity( 1 );
		$item->set_subtotal( (string) $amount );
		$item->set_total( (string) $amount );
		$order = wc_create_order();
		$order->add_item( $item );
		$order->set_currency( $currency );
		$order->set_billing_email( 'buyer@example.com' );
		$order->calculate_totals( false );
		$order->save();
		return $order;
	}

	/** Zamówienie 80 z cookie partnera i wiersz `pending` (10 %: 8). */
	private function attributed( ?array $partner = null, float $amount = 80.0, string $currency = 'EUR' ): array {
		$partner = $partner ?? $this->affiliate();
		$ts      = time();
		$_COOKIE[ Tracker::COOKIE ] = $partner['code'] . '.' . $ts . '.' . Tracker::sign( $partner['code'], $ts );
		$order = $this->order( $amount, $currency );
		Attribution::on_order( $order );
		unset( $_COOKIE[ Tracker::COOKIE ] );
		$row = Store::commission_for_order( $order->get_id() );
		$this->assertNotNull( $row );
		return array( $order, $row );
	}

	private function row_in_state( string $status, ?array $partner = null, string $currency = 'EUR' ): array {
		list( $order, $row ) = $this->attributed( $partner, 80.0, $currency );
		if ( 'pending' !== $status ) {
			Store::update_commission( $row['id'], array( 'status' => $status ) );
		}
		return array( $order, Store::commission_for_order( $order->get_id() ) );
	}

	private function act( string $action, array $ids, array $extra = array() ): WP_REST_Response {
		return $this->request( 'POST', 'affiliates/commissions', array_merge( array( 'action' => $action, 'ids' => $ids ), $extra ) );
	}

	private function without_hooks( callable $change ): void {
		remove_action( 'woocommerce_order_status_changed', array( Commissions::class, 'on_status_change' ) );
		remove_action( 'woocommerce_order_refunded', array( Commissions::class, 'on_refund' ) );
		$change();
		add_action( 'woocommerce_order_status_changed', array( Commissions::class, 'on_status_change' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( Commissions::class, 'on_refund' ) );
	}

	// ------------------------------------------------------------------ Uprawnienia

	public function test_all_routes_require_manage_woocommerce() {
		$partner = $this->affiliate();
		$routes  = array(
			array( 'GET', 'affiliates', array() ),
			array( 'POST', 'affiliates', array( 'name' => 'X' ) ),
			array( 'GET', 'affiliates/commissions', array() ),
			array( 'POST', 'affiliates/commissions', array( 'action' => 'approve', 'ids' => array( 1 ) ) ),
			array( 'GET', 'affiliates/link', array( 'affiliate_id' => $partner['id'], 'url' => home_url( '/' ) ) ),
			array( 'GET', 'affiliates/report', array() ),
			array( 'GET', 'affiliates/settings', array() ),
			array( 'POST', 'affiliates/settings', array( 'hold_days' => 5 ) ),
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $routes as $r ) {
			$this->assertSame( 403, $this->request( $r[0], $r[1], $r[2] )->get_status(), $r[0] . ' ' . $r[1] );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'GET', 'affiliates' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		foreach ( $routes as $r ) {
			$this->assertSame( 200, $this->request( $r[0], $r[1], $r[2] )->get_status(), $r[0] . ' ' . $r[1] );
		}
		wp_set_current_user( 0 );
		$this->assertSame( 204, $this->request( 'POST', 'ref', array( 'code' => 'nobody' ) )->get_status() );
	}

	public function test_routes_work_when_module_off() {
		list( , $row ) = $this->row_in_state( 'approved' );
		Settings::update( array( 'affiliates' => array( 'enabled' => false ) ) );
		$this->assertFalse( Store::enabled() );

		$this->assertSame( 200, $this->request( 'GET', 'affiliates/commissions' )->get_status() );
		$res = $this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => 'TR-9' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'ok', $res->get_data()['results'][ $row['id'] ] );
		$created = $this->request( 'POST', 'affiliates', array( 'name' => 'New partner' ) );
		$this->assertSame( 200, $created->get_status() );
		$this->assertSame( 'New partner', $created->get_data()['affiliate']['name'] );
	}

	// ------------------------------------------------------------------ Lista

	public function test_list_totals_grouped_by_currency_and_includes_link() {
		$partner = $this->affiliate();
		$this->row_in_state( 'pending', $partner, 'PLN' );
		$this->row_in_state( 'approved', $partner, 'EUR' );
		list( , $paid ) = $this->row_in_state( 'approved', $partner, 'EUR' );
		Store::update_commission( $paid['id'], array( 'status' => 'paid', 'commission_paid' => '8.0000', 'clawback' => '3.0000', 'clawback_settled_amount' => '1.0000' ) );
		Store::add_click( $partner['id'] );
		$this->row_in_state( 'rejected', $partner, 'EUR' );

		$data = $this->request( 'GET', 'affiliates' )->get_data();
		$this->assertSame( add_query_arg( 'ref', $partner['code'], home_url( '/' ) ), $data['items'][0]['link'] );
		$totals = $data['items'][0]['totals'];
		$this->assertEqualsCanonicalizing( array( 'EUR', 'PLN' ), array_keys( $totals ) );
		$this->assertSame( '8.0000', $totals['PLN']['pending'] );
		$this->assertSame( '0.0000', $totals['PLN']['rejected'] );
		$this->assertSame( '0.0000', $totals['PLN']['approved'] );
		$this->assertSame( '8.0000', $totals['EUR']['approved'] );
		$this->assertSame( '8.0000', $totals['EUR']['paid'] );
		$this->assertSame( '2.0000', $totals['EUR']['clawback_balance'] );
		$this->assertSame( 1, $data['items'][0]['clicks'] );
		$this->assertSame( '8.0000', $totals['EUR']['rejected'] );
		$this->assertSame( 4, $data['items'][0]['orders'] );
	}

	public function test_commissions_list_filters_and_clamps_page_size() {
		$partner = $this->affiliate();
		$this->row_in_state( 'pending', $partner );
		$this->row_in_state( 'approved', $partner );
		$data = $this->request( 'GET', 'affiliates/commissions', array( 'status' => 'approved', 'per_page' => 500 ) )->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 100, $data['per_page'] );
		$this->assertSame( 'approved', $data['items'][0]['status'] );
		$this->assertArrayHasKey( 'clawback_balance', $data['items'][0] );
	}

	// ------------------------------------------------------------------ Akcje

	public function test_paid_requires_approved() {
		list( , $row ) = $this->row_in_state( 'pending' );
		$res = $this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => 'TR-1' ) );
		$this->assertSame( 'invalid_state', $res->get_data()['results'][ $row['id'] ] );
		$this->assertSame( 'pending', Store::commission_for_order( $row['order_id'] )['status'] );
	}

	public function test_paid_sets_amount_and_requires_reference() {
		list( , $row ) = $this->row_in_state( 'approved' );
		foreach ( array( '', '   ', str_repeat( 'a', 101 ) ) as $bad ) {
			$res = $this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => $bad ) );
			$this->assertSame( 400, $res->get_status() );
			$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'] );
		}
		$this->assertSame( 'approved', Store::commission_for_order( $row['order_id'] )['status'] );

		$res = $this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => '  TR-1  ' ) );
		$this->assertSame( 'ok', $res->get_data()['results'][ $row['id'] ] );
		$paid = Store::commission_for_order( $row['order_id'] );
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertSame( 'TR-1', $paid['payout_ref'] );
		$this->assertSame( $paid['commission'], $paid['commission_paid'] );
		$this->assertNotEmpty( $paid['paid_at'] );
	}

	public function test_paid_retry_keeps_first_reference() {
		list( , $row ) = $this->row_in_state( 'approved' );
		$this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => 'FIRST' ) );
		$first = Store::commission_for_order( $row['order_id'] );

		$res = $this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => 'SECOND' ) );
		$this->assertSame( 'skipped', $res->get_data()['results'][ $row['id'] ] );
		$after = Store::commission_for_order( $row['order_id'] );
		$this->assertSame( 'FIRST', $after['payout_ref'] );
		$this->assertSame( $first['paid_at'], $after['paid_at'] );
		$this->assertSame( $first['commission_paid'], $after['commission_paid'] );
	}

	public function test_paid_checks_order_first() {
		list( $order, $row ) = $this->row_in_state( 'approved' );
		$this->without_hooks(
			function () use ( $order ) {
				$order->set_status( 'refunded' );
				$order->save();
			}
		);
		$res = $this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => 'TR-1' ) );
		$this->assertSame( 'order_refunded', $res->get_data()['results'][ $row['id'] ] );
		$after = Store::commission_for_order( $row['order_id'] );
		$this->assertSame( 'rejected', $after['status'] );
		$this->assertSame( '', $after['payout_ref'] );

		list( $order2, $row2 ) = $this->row_in_state( 'approved' );
		$order2->delete( true );
		$res = $this->act( 'paid', array( $row2['id'] ), array( 'payout_ref' => 'TR-2' ) );
		$this->assertSame( 'order_missing', $res->get_data()['results'][ $row2['id'] ] );
		$this->assertSame( 'approved', Store::commission_for_order( $row2['order_id'] )['status'] );
	}

	public function test_approve_and_reject() {
		list( $o1, $pending )  = $this->row_in_state( 'pending' );
		list( , $approved )    = $this->row_in_state( 'approved' );
		list( $o3, $other )    = $this->row_in_state( 'pending' );

		// Zamówienie nie jest zakończone: zatwierdzenie odrzucone mimo upływu karencji.
		$res = $this->act( 'approve', array( $pending['id'] ) );
		$this->assertSame( 'invalid_state', $res->get_data()['results'][ $pending['id'] ] );

		$o1->set_status( 'completed' );
		$o1->save();
		$res = $this->act( 'approve', array( $pending['id'], $approved['id'], 99999 ) );
		$results = $res->get_data()['results'];
		$this->assertSame( 'ok', $results[ $pending['id'] ] );
		$this->assertSame( 'invalid_state', $results[ $approved['id'] ] );
		$this->assertSame( 'skipped', $results[99999] );
		$this->assertSame( 'approved', Store::commission_for_order( $pending['order_id'] )['status'] );

		$res = $this->act( 'reject', array( $pending['id'], $other['id'] ) );
		$this->assertSame( array( 'ok', 'ok' ), array_values( $res->get_data()['results'] ) );
		$rejected = Store::commission_for_order( $other['order_id'] );
		$this->assertSame( 'rejected', $rejected['status'] );
		$this->assertSame( 'manual', $rejected['reason'] );
		$res = $this->act( 'reject', array( $other['id'] ) );
		$this->assertSame( 'invalid_state', $res->get_data()['results'][ $other['id'] ] );
	}

	public function test_settle_clawback_records_ref() {
		list( $order, $row ) = $this->row_in_state( 'approved' );
		$this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => 'TR-1' ) );
		$this->without_hooks(
			function () use ( $order ) {
				wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 40 ) );
			}
		);
		// Ręczne przeliczenie nie nastąpiło (hook odpięty): settle odczytuje aktualny zwrot.
		$res = $this->act( 'settle_clawback', array( $row['id'] ), array( 'clawback_ref' => 'CN-1' ) );
		$this->assertSame( 'ok', $res->get_data()['results'][ $row['id'] ] );
		$after = Store::commission_for_order( $row['order_id'] );
		$this->assertSame( '4.0000', $after['clawback'] );
		$this->assertSame( '4.0000', $after['clawback_settled_amount'] );
		$this->assertSame( 'CN-1', $after['clawback_ref'] );
		$this->assertNotEmpty( $after['clawback_settled_at'] );
		$this->assertSame( '0.0000', Commissions::clawback_balance( $after ) );

		wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 20 ) );
		$later = Store::commission_for_order( $row['order_id'] );
		$this->assertSame( '6.0000', $later['clawback'] );
		$this->assertSame( '2.0000', Commissions::clawback_balance( $later ) );
	}

	public function test_settle_without_clawback_invalid() {
		list( , $approved ) = $this->row_in_state( 'approved' );
		list( , $paid )     = $this->row_in_state( 'approved' );
		$this->act( 'paid', array( $paid['id'] ), array( 'payout_ref' => 'TR-1' ) );
		$res = $this->act( 'settle_clawback', array( $approved['id'], $paid['id'] ), array( 'clawback_ref' => 'CN-1' ) );
		$this->assertSame( 'invalid_state', $res->get_data()['results'][ $approved['id'] ] );
		$this->assertSame( 'invalid_state', $res->get_data()['results'][ $paid['id'] ] );
		$this->assertSame( '', Store::commission_for_order( $paid['order_id'] )['clawback_ref'] );
		$res = $this->act( 'settle_clawback', array( $paid['id'] ), array() );
		$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'] );
	}

	public function test_batch_continues_after_failed_recalculation() {
		list( $stale, $row1 ) = $this->row_in_state( 'approved' );
		list( , $row2 )       = $this->row_in_state( 'approved' );
		$this->without_hooks(
			function () use ( $stale ) {
				wc_create_refund( array( 'order_id' => $stale->get_id(), 'amount' => 40 ) );
			}
		);
		$break = static function ( $q ) {
			return 0 === strpos( $q, 'UPDATE `' . Store::commissions_table() ) && false !== strpos( $q, 'base_amount' ) ? 'UPDATE aktio_no_such_table SET x = 1' : $q;
		};
		add_filter( 'query', $break );
		$log = tempnam( sys_get_temp_dir(), 'aktio-log' );
		$old = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		$res = $this->act( 'paid', array( $row1['id'], $row2['id'] ), array( 'payout_ref' => 'TR-1' ) );
		ini_set( 'error_log', (string) $old ); // phpcs:ignore WordPress.PHP.IniSet
		unlink( $log );
		remove_filter( 'query', $break );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'skipped', $res->get_data()['results'][ $row1['id'] ] );
		$this->assertSame( 'ok', $res->get_data()['results'][ $row2['id'] ] );
		$this->assertSame( 'approved', Store::commission_for_order( $row1['order_id'] )['status'] );
		$this->assertSame( 'paid', Store::commission_for_order( $row2['order_id'] )['status'] );
	}

	public function test_ids_must_be_positive_integers() {
		list( , $row ) = $this->row_in_state( 'approved' );
		$id            = $row['id'];
		$bad           = array( array( array( $id ) ), array( true ), array( (float) $id + 0.9 ), array( $id . 'abc' ), array( 0 ), array( -1 ), array( 'abc' ), array( null ), array( $id, 'abc' ), array( ' ' . $id ), array( (string) $id . '.0' ) );
		foreach ( $bad as $ids ) {
			$res = $this->act( 'paid', $ids, array( 'payout_ref' => 'X' ) );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $ids ) );
			$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'], wp_json_encode( $ids ) );
		}
		$this->assertSame( 'approved', Store::commission_for_order( $row['order_id'] )['status'] );
		$ok = $this->act( 'paid', array( (string) $id ), array( 'payout_ref' => 'X' ) );
		$this->assertSame( 'ok', $ok->get_data()['results'][ $id ] );
	}

	public function test_apply_does_not_coerce_or_truncate() {
		list( , $row ) = $this->row_in_state( 'approved' );
		$this->assertInstanceOf( WP_Error::class, Commissions::apply( 'paid', array( array( $row['id'] ) ), 'X' ) );
		$this->assertInstanceOf( WP_Error::class, Commissions::apply( 'paid', array( $row['id'] . 'abc' ), 'X' ) );
		$this->assertInstanceOf( WP_Error::class, Commissions::apply( 'paid', array( 0 ), 'X' ) );
		$this->assertSame( 'aktio_aff_too_many', Commissions::apply( 'approve', range( 1, 201 ) )->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, Commissions::apply( 'explode', array( $row['id'] ) ) );
		$this->assertInstanceOf( WP_Error::class, Commissions::apply( 'paid', array( $row['id'] ), '' ) );
		$this->assertSame( 'approved', Store::commission_for_order( $row['order_id'] )['status'] );
		$res = Commissions::apply( 'approve', range( 1, Commissions::MAX_IDS ) );
		$this->assertCount( Commissions::MAX_IDS, $res['results'] );
	}

	public function test_read_filters_reject_invalid_values() {
		$partner = $this->affiliate();
		$this->row_in_state( 'pending', $partner );
		$bad = array(
			array( 'status' => 'aproved' ),
			array( 'affiliate_id' => 'abc' ),
			array( 'affiliate_id' => array( 5 ) ),
			array( 'affiliate_id' => '0' ),
			array( 'affiliate_id' => '-3' ),
			array( 'from' => 'garbage' ),
			array( 'from' => '2026-13-45' ),
			array( 'to' => '2026-02-30' ),
			array( 'to' => array( '2026-01-01' ) ),
		);
		foreach ( $bad as $params ) {
			$res = $this->request( 'GET', 'affiliates/commissions', $params );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $params ) );
			$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'], wp_json_encode( $params ) );
		}
		$day = gmdate( 'Y-m-d' );
		$this->assertSame( 1, $this->request( 'GET', 'affiliates/commissions', array( 'status' => 'pending', 'affiliate_id' => (string) $partner['id'], 'from' => $day, 'to' => $day ) )->get_data()['total'] );
		$this->assertSame( 0, $this->request( 'GET', 'affiliates/commissions', array( 'from' => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) )->get_data()['total'] );
		$this->assertSame( 0, $this->request( 'GET', 'affiliates/commissions', array( 'affiliate_id' => $partner['id'] + 1000 ) )->get_data()['total'] );
		$this->assertSame( array(), $this->request( 'GET', 'affiliates/commissions', array( 'page' => 2, 'per_page' => 1 ) )->get_data()['items'] );
		$this->assertSame( 2, $this->request( 'GET', 'affiliates/commissions', array( 'page' => 2 ) )->get_data()['page'] );
	}

	public function test_partner_ids_must_be_positive_integers() {
		$partner = $this->affiliate();
		foreach ( array( array( $partner['id'] ), 'abc', 0, -1, '7x', 1.5, true, null ) as $bad ) {
			$res = $this->request( 'POST', 'affiliates', array( 'id' => $bad, 'name' => 'Hijack' ) );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $bad ) );
			$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'] );
			$res = $this->request( 'GET', 'affiliates/link', array( 'affiliate_id' => $bad, 'url' => home_url( '/' ) ) );
			$this->assertSame( 400, $res->get_status(), wp_json_encode( $bad ) );
		}
		$this->assertSame( $partner['name'], Store::get_affiliate( $partner['id'] )['name'] );
		$this->assertSame( 200, $this->request( 'POST', 'affiliates', array( 'id' => (string) $partner['id'], 'name' => 'Fine' ) )->get_status() );
	}

	public function test_paid_with_zero_commission_is_invalid_state() {
		list( $order, $row ) = $this->row_in_state( 'approved' );
		$this->without_hooks(
			function () use ( $order ) {
				wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => 80 ) );
				$order->set_status( 'completed' );
				$order->save();
			}
		);
		$res = $this->act( 'paid', array( $row['id'] ), array( 'payout_ref' => 'TR-1' ) );
		$this->assertSame( 'invalid_state', $res->get_data()['results'][ $row['id'] ] );
		$after = Store::commission_for_order( $row['order_id'] );
		$this->assertSame( 'approved', $after['status'] );
		$this->assertSame( '', $after['payout_ref'] );
	}

	public function test_coupon_scoped_to_partner_and_validated_before_saving() {
		$a = $this->affiliate();
		$b = $this->affiliate();
		foreach ( array( 'ca10', 'cb10', 'free10' ) as $code ) {
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( '10' );
			$coupon->save();
		}
		$this->assertSame( 200, $this->request( 'POST', 'affiliates', array( 'id' => $a['id'], 'coupon_add' => 'ca10' ) )->get_status() );
		$this->assertSame( 200, $this->request( 'POST', 'affiliates', array( 'id' => $b['id'], 'coupon_add' => 'cb10' ) )->get_status() );

		// Cudzy kupon: nie do zdjęcia i nie do przejęcia; nic się nie zapisuje.
		$res = $this->request( 'POST', 'affiliates', array( 'id' => $a['id'], 'name' => 'Changed', 'coupon_remove' => 'cb10' ) );
		$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'] );
		$res = $this->request( 'POST', 'affiliates', array( 'id' => $a['id'], 'name' => 'Changed', 'coupon_add' => 'cb10' ) );
		$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'] );
		$res = $this->request( 'POST', 'affiliates', array( 'name' => 'New', 'coupon_add' => 'cb10' ) );
		$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'] );
		$res = $this->request( 'POST', 'affiliates', array( 'name' => 'New', 'coupon_remove' => 'free10' ) );
		$this->assertSame( 'aktio_aff_invalid', $res->get_data()['code'] );
		$this->assertSame( $a['name'], Store::get_affiliate( $a['id'] )['name'] );
		$this->assertSame( (string) $b['id'], (string) ( new WC_Coupon( 'cb10' ) )->get_meta( Store::COUPON_META ) );

		// Wstrzymany partner: kupon nie jest przypisywany, a zmiana nie zapisuje się częściowo.
		$res = $this->request( 'POST', 'affiliates', array( 'id' => $a['id'], 'status' => 'paused', 'coupon_add' => 'free10' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'aktio_aff_paused', $res->get_data()['code'] );
		$this->assertSame( 'active', Store::get_affiliate( $a['id'] )['status'] );

		// Własny kupon wolno zdjąć.
		$this->assertSame( 200, $this->request( 'POST', 'affiliates', array( 'id' => $a['id'], 'coupon_remove' => 'ca10' ) )->get_status() );
		$this->assertSame( '', (string) ( new WC_Coupon( 'ca10' ) )->get_meta( Store::COUPON_META ) );
	}

	public function test_ids_limit_200() {
		$res = $this->act( 'approve', range( 1, 201 ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'aktio_aff_too_many', $res->get_data()['code'] );
		$this->assertSame( 400, $this->act( 'approve', array() )->get_status() );
		$this->assertSame( 400, $this->act( 'explode', array( 1 ) )->get_status() );
		$this->assertSame( 200, $this->act( 'approve', range( 1, 200 ) )->get_status() );
	}

	// ------------------------------------------------------------------ Raport, ustawienia, status

	public function test_report_days_clamped_1_400() {
		$partner = $this->affiliate();
		Store::add_click( $partner['id'] );
		$this->attributed( $partner );
		$low = $this->request( 'GET', 'affiliates/report', array( 'days' => 0 ) )->get_data();
		$this->assertSame( 1, $low['days'] );
		$this->assertCount( 1, $low['series'] );
		$this->assertSame( 1, $low['series'][0]['clicks'] );
		$this->assertSame( 1, $low['series'][0]['orders'] );
		$this->assertSame( '8.0000', $low['series'][0]['commissions']['EUR'] );
		$high = $this->request( 'GET', 'affiliates/report', array( 'days' => 9999 ) )->get_data();
		$this->assertSame( 400, $high['days'] );
		$this->assertCount( 400, $high['series'] );
	}

	public function test_status_exposes_affiliates() {
		$data = $this->request( 'GET', 'status' )->get_data();
		$this->assertSame( array( 'available' => true, 'enabled' => true ), $data['affiliates'] );
		Settings::update( array( 'affiliates' => array( 'enabled' => false ) ) );
		$this->assertFalse( $this->request( 'GET', 'status' )->get_data()['affiliates']['enabled'] );
	}

	public function test_settings_get_and_validation() {
		$data = $this->request( 'GET', 'affiliates/settings' )->get_data();
		$this->assertSame( array_keys( Settings::get()['affiliates'] ), array_keys( $data ) );

		$ok = $this->request( 'POST', 'affiliates/settings', array( 'hold_days' => 7 ) );
		$this->assertSame( 7, $ok->get_data()['hold_days'] );
		$this->assertSame( 7, Settings::get()['affiliates']['hold_days'] );

		$bad = $this->request( 'POST', 'affiliates/settings', array( 'hold_days' => 999 ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'aktio_aff_invalid', $bad->get_data()['code'] );
		$this->assertSame( 7, Settings::get()['affiliates']['hold_days'] );

		Settings::update( array( 'affiliates' => array( 'enabled' => false, 'consent_mode' => '' ) ) );
		$need = $this->request( 'POST', 'affiliates/settings', array( 'enabled' => true ) );
		$this->assertSame( 400, $need->get_status() );
		$this->assertSame( 'aktio_aff_consent_required', $need->get_data()['code'] );
		$this->assertFalse( Settings::get()['affiliates']['enabled'] );
	}

	// ------------------------------------------------------------------ Partner i kupony

	public function test_coupon_assignment_unknown_code() {
		$partner = $this->affiliate();
		$res     = $this->request( 'POST', 'affiliates', array( 'id' => $partner['id'], 'name' => 'Renamed', 'coupon_add' => 'nosuchcoupon' ) );
		$this->assertSame( 'aktio_aff_coupon_not_found', $res->get_data()['code'] );
		$this->assertSame( $partner['name'], Store::get_affiliate( $partner['id'] )['name'] );
	}

	public function test_coupon_assign_and_remove() {
		$partner = $this->affiliate();
		$coupon  = new WC_Coupon();
		$coupon->set_code( 'partner10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$coupon->save();
		$res = $this->request( 'POST', 'affiliates', array( 'id' => $partner['id'], 'coupon_add' => 'partner10' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( (string) $partner['id'], (string) ( new WC_Coupon( 'partner10' ) )->get_meta( Store::COUPON_META ) );
		$res = $this->request( 'POST', 'affiliates', array( 'id' => $partner['id'], 'coupon_remove' => 'partner10' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '', (string) ( new WC_Coupon( 'partner10' ) )->get_meta( Store::COUPON_META ) );
	}

	public function test_partner_errors_have_http_status() {
		$partner = $this->affiliate();
		$this->assertSame( 404, $this->request( 'POST', 'affiliates', array( 'id' => 99999, 'name' => 'Z' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', 'affiliates', array( 'name' => '' ) )->get_status() );
		$this->assertSame( 409, $this->request( 'POST', 'affiliates', array( 'name' => 'Dup', 'code' => $partner['code'] ) )->get_status() );
		Store::add_click( $partner['id'] );
		$res = $this->request( 'POST', 'affiliates', array( 'id' => $partner['id'], 'code' => 'other-code' ) );
		$this->assertSame( 'aktio_aff_code_locked', $res->get_data()['code'] );
		$this->assertSame( 409, $res->get_status() );
	}

	// ------------------------------------------------------------------ Link

	public function test_link_for_product_url() {
		$product = home_url( '/product/hat/' );
		$this->assertSame( $product . '?ref=abc', Tracker::link_for( $product, 'abc' ) );
		$this->assertSame( $product . '?a=1&ref=abc#x', Tracker::link_for( $product . '?a=1#x', 'abc' ) );
		$this->assertSame( $product . '?a=1&ref=abc', Tracker::link_for( $product . '?ref=old&a=1', 'abc' ) );
		$this->assertSame( $product . '?ref=abc', Tracker::link_for( $product . '?ref=old&ref=older', 'abc' ) );

		$home = wp_parse_url( home_url() );
		$www  = 'http://www.' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . '/product/hat/';
		$this->assertSame( $www . '?ref=abc', Tracker::link_for( $www, 'abc' ) );
	}

	public function test_link_rejects_outside_shop() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$bad  = array(
			'https://evil.example/',
			'https://' . $host . '.evil.example/',
			'https://evil.example/' . $host,
			'javascript:alert(1)',
			'',
			'   ',
			'https://user:pw@' . $host . '/',
			'https://' . $host . '@evil.example/',
			'https://evil.example\\@' . $host . '/',
			'https://' . $host . '\\.evil.example/',
			'//' . $host . '/',
			'/relative',
		);
		foreach ( $bad as $url ) {
			$result = Tracker::link_for( $url, 'abc' );
			$this->assertInstanceOf( WP_Error::class, $result, $url );
			$this->assertSame( 'aktio_aff_url_outside_shop', $result->get_error_code(), $url );
		}
		$this->assertInstanceOf( WP_Error::class, Tracker::link_for( home_url( '/' ), 'BAD CODE' ) );
	}

	public function test_link_rejects_url_outside_subdirectory() {
		$filter = static function () {
			return 'http://shop.example.com/blog';
		};
		add_filter( 'home_url', $filter );
		$this->assertSame( 'http://shop.example.com/blog/p/?ref=abc', Tracker::link_for( 'http://shop.example.com/blog/p/', 'abc' ) );
		$this->assertSame( 'http://shop.example.com/blog?ref=abc', Tracker::link_for( 'http://shop.example.com/blog', 'abc' ) );
		foreach ( array( 'http://shop.example.com/', 'http://shop.example.com/blogger/', 'http://shop.example.com/blog/../x', 'http://shop.example.com/other/blog/', 'http://shop.example.com/blog/%2e%2e/x', 'http://shop.example.com/blog/.%2E/admin', 'http://shop.example.com/blog/%2E%2e/admin', 'http://shop.example.com/blog/%252e%252e/x', 'http://shop.example.com/blog/a%2Fb', 'http://shop.example.com/blog/a%5cb', 'http://shop.example.com/blog/a%5Cb' ) as $url ) {
			$result = Tracker::link_for( $url, 'abc' );
			$this->assertInstanceOf( WP_Error::class, $result, $url );
		}
		remove_filter( 'home_url', $filter );
	}

	public function test_link_uses_current_param() {
		$partner = $this->affiliate();
		Settings::update( array( 'affiliates' => array( 'param' => 'via' ) ) );
		$res = $this->request( 'GET', 'affiliates/link', array( 'affiliate_id' => $partner['id'], 'url' => home_url( '/shop/' ) ) );
		$this->assertSame( home_url( '/shop/' ) . '?via=' . $partner['code'], $res->get_data()['link'] );

		$bad = $this->request( 'GET', 'affiliates/link', array( 'affiliate_id' => $partner['id'], 'url' => 'https://evil.example/' ) );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'aktio_aff_url_outside_shop', $bad->get_data()['code'] );
		$this->assertSame( 404, $this->request( 'GET', 'affiliates/link', array( 'affiliate_id' => 99999, 'url' => home_url( '/' ) ) )->get_status() );
	}
}
