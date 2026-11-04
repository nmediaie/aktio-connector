<?php
use AktioConnector\Affiliates\Attribution;
use AktioConnector\Affiliates\Commissions;
use AktioConnector\Affiliates\Store;
use AktioConnector\Affiliates\Tracker;
use AktioConnector\Settings;

class AffiliatesCommissionsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Store::install();
		global $wpdb;
		foreach ( array( Store::affiliates_table(), Store::commissions_table(), Store::clicks_table() ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( Settings::OPTION );
		$this->settings( array( 'enabled' => true, 'consent_mode' => 'self_managed' ) );
		unset( $_COOKIE[ Tracker::COOKIE ] );
	}

	public function tear_down() {
		unset( $_COOKIE[ Tracker::COOKIE ] );
		remove_all_filters( 'aktio_connector_affiliate_resolve' );
		remove_all_filters( 'woocommerce_order_get_billing_email' );
		remove_all_filters( 'woocommerce_logger_log_message' );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	// ------------------------------------------------------------------ Pomocnicze

	private function settings( array $affiliates ): void {
		$result = Settings::update( array( 'affiliates' => $affiliates ) );
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	private function affiliate( array $extra = array() ): array {
		static $n = 0;
		$n++;
		$id = Store::save_affiliate( array_merge( array( 'name' => 'Partner ' . $n ), $extra ) );
		$this->assertIsInt( $id );
		return Store::get_affiliate( $id );
	}

	/** Zamówienie z jedną pozycją bez podatku: suma = $amount. */
	private function order( float $amount = 80.0, string $email = 'buyer@example.com' ): WC_Order {
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
		$order->set_currency( 'EUR' );
		$order->set_billing_email( $email );
		$order->calculate_totals( false );
		$order->save();
		return $order;
	}

	private function coupon( string $code, string $type = 'fixed_cart', float $amount = 20.0 ): string {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $type );
		$coupon->set_amount( (string) $amount );
		$coupon->save();
		return $code;
	}

	private function set_cookie( string $code, ?int $ts = null ): void {
		$ts                         = $ts ?? time();
		$_COOKIE[ Tracker::COOKIE ] = $code . '.' . $ts . '.' . Tracker::sign( $code, $ts );
	}

	/** Zamówienie 80 z cookie partnera i wiersz `pending` (10 %: 8). */
	private function attributed( float $amount = 80.0, array $affiliate = array() ): array {
		$partner = $this->affiliate( $affiliate );
		$this->set_cookie( $partner['code'] );
		$order = $this->order( $amount );
		Attribution::on_order( $order );
		unset( $_COOKIE[ Tracker::COOKIE ] );
		$row = Store::commission_for_order( $order->get_id() );
		$this->assertNotNull( $row );
		return array( $order, $row );
	}

	private function row( WC_Order $order ): array {
		$row = Store::commission_for_order( $order->get_id() );
		$this->assertNotNull( $row );
		return $row;
	}

	private function fresh( WC_Order $order ): WC_Order {
		return wc_get_order( $order->get_id() );
	}

	private function make_paid( array $row, string $paid = '8.0000' ): void {
		Store::update_commission(
			$row['id'],
			array(
				'status'          => 'paid',
				'commission_paid' => $paid,
				'paid_at'         => current_time( 'mysql', true ),
				'payout_ref'      => 'TR-1',
			)
		);
	}

	private function past_due( array $row ): void {
		Store::update_commission( $row['id'], array( 'approve_after' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
	}

	private function refund( WC_Order $order, float $amount ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => $amount,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		return $refund;
	}

	/** Zmiana stanu zamówienia przy odpiętych hookach modułu (WooCommerce albo wtyczka wyłączone). */
	private function without_hooks( callable $change ): void {
		remove_action( 'woocommerce_order_status_changed', array( Commissions::class, 'on_status_change' ) );
		remove_action( 'woocommerce_order_refunded', array( Commissions::class, 'on_refund' ) );
		$change();
		add_action( 'woocommerce_order_status_changed', array( Commissions::class, 'on_status_change' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( Commissions::class, 'on_refund' ) );
	}

	// ---------------------------------------------------------------- Przypisanie

	public function test_commission_base_excludes_tax_shipping_discount() {
		$partner = $this->affiliate();
		$this->set_cookie( $partner['code'] );

		$order = $this->order( 100.0 );
		$order->apply_coupon( $this->coupon( 'plain20' ) );
		$order->set_shipping_total( '15' );
		$order->set_shipping_tax( '3.45' );
		$order->set_cart_tax( '18.40' );
		$order->set_total( '116.85' );
		$order->save();
		$this->assertSame( 100.0, (float) $order->get_subtotal() );
		$this->assertSame( 20.0, (float) $order->get_discount_total() );

		Attribution::on_order( $order );

		$row = $this->row( $order );
		$this->assertSame( 80.0, (float) $row['base_amount'] );
		$this->assertSame( 80.0, (float) $row['base_original'] );
		$this->assertSame( 8.0, (float) $row['commission'] );
		$this->assertSame( 1000, $row['rate_bp'] );
		$this->assertSame( 'EUR', $row['currency'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 'link', $row['source'] );
		$this->assertSame( $partner['id'], $row['affiliate_id'] );
	}

	public function test_both_checkout_hooks_create_one_row() {
		$partner = $this->affiliate();
		$this->set_cookie( $partner['code'] );
		$order = $this->order();

		do_action( 'woocommerce_checkout_order_created', $order );
		do_action( 'woocommerce_store_api_checkout_order_processed', $order );

		$this->assertSame( 1, Store::query_commissions( array() )['total'] );
		$this->assertSame( $order->get_id(), $this->row( $order )['order_id'] );
	}

	public function test_coupon_beats_cookie() {
		$a = $this->affiliate();
		$b = $this->affiliate();
		$this->set_cookie( $a['code'] );
		$code = $this->coupon( 'partner-b' );
		$this->assertTrue( Store::assign_coupon( $b['id'], $code ) );

		$order = $this->order( 100.0 );
		$order->apply_coupon( $code );
		Attribution::on_order( $order );

		$row = $this->row( $order );
		$this->assertSame( $b['id'], $row['affiliate_id'] );
		$this->assertSame( 'coupon', $row['source'] );
	}

	public function test_first_partner_coupon_on_the_order_wins() {
		$a = $this->affiliate();
		$b = $this->affiliate();
		$this->assertTrue( Store::assign_coupon( $a['id'], $this->coupon( 'first-a', 'fixed_cart', 5.0 ) ) );
		$this->assertTrue( Store::assign_coupon( $b['id'], $this->coupon( 'second-b', 'fixed_cart', 5.0 ) ) );
		$this->coupon( 'plain-one', 'fixed_cart', 5.0 );

		$order = $this->order( 100.0 );
		$order->apply_coupon( 'plain-one' );
		$order->apply_coupon( 'first-a' );
		$order->apply_coupon( 'second-b' );
		Attribution::on_order( $order );

		$this->assertSame( $a['id'], $this->row( $order )['affiliate_id'] );
	}

	public function test_paused_partner_coupon_no_commission() {
		$partner = $this->affiliate();
		$code    = $this->coupon( 'paused-one' );
		$this->assertTrue( Store::assign_coupon( $partner['id'], $code ) );
		$this->assertIsInt( Store::save_affiliate( array( 'id' => $partner['id'], 'status' => 'paused' ) ) );

		$order = $this->order( 100.0 );
		$order->apply_coupon( $code );
		Attribution::on_order( $order );

		$this->assertNull( Store::commission_for_order( $order->get_id() ) );
		$this->assertSame( 20.0, (float) $order->get_discount_total(), 'The coupon still gives the discount.' );
	}

	public function test_paused_partner_coupon_does_not_fall_back_to_cookie() {
		$coupon_partner = $this->affiliate();
		$cookie_partner = $this->affiliate();
		$code           = $this->coupon( 'paused-two' );
		$this->assertTrue( Store::assign_coupon( $coupon_partner['id'], $code ) );
		$this->assertIsInt( Store::save_affiliate( array( 'id' => $coupon_partner['id'], 'status' => 'paused' ) ) );
		$this->set_cookie( $cookie_partner['code'] );

		$order = $this->order( 100.0 );
		$order->apply_coupon( $code );
		Attribution::on_order( $order );

		$this->assertNull( Store::commission_for_order( $order->get_id() ) );
	}

	public function test_invalid_cookie_no_commission() {
		$partner = $this->affiliate();
		$ts      = time();

		$_COOKIE[ Tracker::COOKIE ] = $partner['code'] . '.' . $ts . '.' . str_repeat( 'a', 64 );
		$forged                     = $this->order();
		Attribution::on_order( $forged );
		$this->assertNull( Store::commission_for_order( $forged->get_id() ) );

		$this->set_cookie( $partner['code'], time() - 31 * DAY_IN_SECONDS );
		$expired = $this->order();
		Attribution::on_order( $expired );
		$this->assertNull( Store::commission_for_order( $expired->get_id() ) );
	}

	public function test_paused_partner_cookie_no_commission() {
		$partner = $this->affiliate();
		$this->assertIsInt( Store::save_affiliate( array( 'id' => $partner['id'], 'status' => 'paused' ) ) );
		$this->set_cookie( $partner['code'] );

		$order = $this->order();
		Attribution::on_order( $order );

		$this->assertNull( Store::commission_for_order( $order->get_id() ) );
	}

	public function test_zero_base_no_commission() {
		$partner = $this->affiliate();
		$code    = $this->coupon( 'free-order', 'percent', 100.0 );
		$this->assertTrue( Store::assign_coupon( $partner['id'], $code ) );

		$order = $this->order( 50.0 );
		$order->apply_coupon( $code );
		Attribution::on_order( $order );

		$this->assertNull( Store::commission_for_order( $order->get_id() ) );
	}

	public function test_self_referral_rejected_row() {
		$partner = $this->affiliate( array( 'email' => 'jan@x.pl' ) );
		$this->set_cookie( $partner['code'] );
		$order = $this->order( 80.0, 'JAN@x.pl' );
		// set_billing_email odrzuca spacje, więc adres ze spacjami podajemy na odczycie.
		add_filter(
			'woocommerce_order_get_billing_email',
			static function () {
				return ' JAN@x.pl ';
			}
		);

		Attribution::on_order( $order );

		$row = $this->row( $order );
		$this->assertSame( 'rejected', $row['status'] );
		$this->assertSame( 'self_referral', $row['reason'] );
	}

	public function test_partner_without_email_skips_self_referral_check() {
		list( $order, $row ) = $this->attributed();
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( '', $row['reason'] );
		$this->assertNotEmpty( $order->get_billing_email() );
	}

	public function test_exception_never_breaks_order() {
		$partner = $this->affiliate();
		$this->set_cookie( $partner['code'] );
		$order = $this->order();
		add_filter(
			'aktio_connector_affiliate_resolve',
			static function () {
				throw new RuntimeException( 'secret jan@x.pl' );
			}
		);
		$logged = array();
		add_filter(
			'woocommerce_logger_log_message',
			static function ( $message ) use ( &$logged ) {
				$logged[] = $message;
				return $message;
			}
		);

		Attribution::on_order( $order );

		$this->assertInstanceOf( WC_Order::class, $this->fresh( $order ) );
		$this->assertNull( Store::commission_for_order( $order->get_id() ) );
		$this->assertNotEmpty( $logged, 'The error goes to the WooCommerce log.' );
		$this->assertStringNotContainsString( 'jan@x.pl', implode( ' ', $logged ), 'No personal data in the log.' );
	}

	public function test_resolve_filter_can_change_the_result() {
		$partner = $this->affiliate();
		$order   = $this->order();
		add_filter(
			'aktio_connector_affiliate_resolve',
			static function () use ( $partner ) {
				return array(
					'affiliate' => $partner,
					'source'    => 'link',
				);
			}
		);
		$this->assertSame( $partner['id'], Attribution::resolve( $order )['affiliate']['id'] );

		remove_all_filters( 'aktio_connector_affiliate_resolve' );
		add_filter( 'aktio_connector_affiliate_resolve', '__return_empty_string' );
		$this->assertNull( Attribution::resolve( $order ), 'A malformed filter value counts as no attribution.' );
	}

	public function test_affiliate_own_rate_beats_settings() {
		list( , $own ) = $this->attributed( 80.0, array( 'rate_bp' => 2500 ) );
		$this->assertSame( 2500, $own['rate_bp'] );
		$this->assertSame( 20.0, (float) $own['commission'] );

		list( , $zero ) = $this->attributed( 80.0, array( 'rate_bp' => 0 ) );
		$this->assertSame( 0, $zero['rate_bp'], 'Rate 0 is an explicit rate, not a missing one.' );
		$this->assertSame( 0.0, (float) $zero['commission'] );
	}

	public function test_module_off_no_new_row_but_refund_still_rejects() {
		list( $existing, $row ) = $this->attributed();
		$partner                = Store::get_affiliate( $row['affiliate_id'] );
		$this->settings( array( 'enabled' => false ) );

		$this->set_cookie( $partner['code'] );
		$new = $this->order();
		do_action( 'woocommerce_checkout_order_created', $new );
		$this->assertNull( Store::commission_for_order( $new->get_id() ) );

		$this->fresh( $existing )->update_status( 'refunded' );

		$after = $this->row( $existing );
		$this->assertSame( 'rejected', $after['status'] );
		$this->assertSame( 'order_status', $after['reason'] );
	}

	// -------------------------------------------------------------- Karencja i cron

	public function test_completed_sets_approve_after_and_cron_approves() {
		$this->settings( array( 'hold_days' => 30 ) );
		list( $order, $row ) = $this->attributed();
		$this->assertNull( $row['approve_after'] );

		$before = time();
		$this->fresh( $order )->update_status( 'completed' );
		$after = $this->row( $order );
		$this->assertEqualsWithDelta( $before + 30 * DAY_IN_SECONDS, strtotime( $after['approve_after'] . ' UTC' ), 5 );

		$this->assertSame( 0, Commissions::approve_due() );
		$this->assertSame( 'pending', $this->row( $order )['status'] );

		$this->past_due( $after );
		$this->assertSame( 1, Commissions::approve_due() );
		$this->assertSame( 'approved', $this->row( $order )['status'] );
		$this->assertSame( 0, Commissions::approve_due(), 'A second run approves nothing.' );
	}

	public function test_hold_zero_is_due_on_the_next_cron() {
		$this->settings( array( 'hold_days' => 0 ) );
		list( $order ) = $this->attributed();
		$this->fresh( $order )->update_status( 'completed' );
		$this->assertSame( 1, Commissions::approve_due() );
		$this->assertSame( 'approved', $this->row( $order )['status'] );
	}

	public function test_cron_does_not_approve_order_no_longer_completed() {
		$this->settings( array( 'hold_days' => 0 ) );
		list( $order ) = $this->attributed();
		$this->fresh( $order )->update_status( 'completed' );
		$this->fresh( $order )->update_status( 'processing' );

		$this->assertSame( 0, Commissions::approve_due() );
		$this->assertSame( 'pending', $this->row( $order )['status'] );
	}

	public function test_rate_change_does_not_touch_existing() {
		list( $order, $row ) = $this->attributed();
		$this->settings( array( 'rate_bp' => 5000 ) );
		$this->assertIsInt( Store::save_affiliate( array( 'id' => $row['affiliate_id'], 'rate_bp' => 3000 ) ) );

		$this->fresh( $order )->update_status( 'completed' );
		$this->refund( $this->fresh( $order ), 20.0 );

		$after = $this->row( $order );
		$this->assertSame( 1000, $after['rate_bp'] );
		$this->assertSame( 6.0, (float) $after['commission'], '10 % of 60, not the new rate.' );
	}

	public function test_refund_cancel_fail_rejects_and_return_restores() {
		$this->settings( array( 'hold_days' => 30 ) );
		foreach ( array( 'cancelled', 'failed' ) as $status ) {
			list( $order ) = $this->attributed();
			$this->fresh( $order )->update_status( $status );
			$rejected = $this->row( $order );
			$this->assertSame( 'rejected', $rejected['status'], $status );
			$this->assertSame( 'order_status', $rejected['reason'], $status );

			$this->fresh( $order )->update_status( 'processing' );
			$restored = $this->row( $order );
			$this->assertSame( 'pending', $restored['status'], $status );
			$this->assertSame( '', $restored['reason'], $status );
			$this->assertNull( $restored['approve_after'], 'Only completed starts the hold.' );
			$this->assertSame( 8.0, (float) $restored['commission'] );
		}

		list( $refunded ) = $this->attributed();
		$this->fresh( $refunded )->update_status( 'refunded' );
		$this->assertSame( 'rejected', $this->row( $refunded )['status'] );
		$this->assertSame( 0.0, (float) $this->row( $refunded )['commission'], 'The refund exists, nothing to pay.' );
		$this->fresh( $refunded )->update_status( 'completed' );
		$back = $this->row( $refunded );
		$this->assertSame( 'pending', $back['status'] );
		$this->assertNotNull( $back['approve_after'], 'A return to completed starts the hold again.' );
	}

	public function test_approved_row_is_rejected_by_cancel() {
		list( $order, $row ) = $this->attributed();
		Store::update_commission( $row['id'], array( 'status' => 'approved' ) );
		$this->fresh( $order )->update_status( 'cancelled' );
		$this->assertSame( 'rejected', $this->row( $order )['status'] );
	}

	public function test_return_after_old_hold_does_not_approve_early() {
		$this->settings( array( 'hold_days' => 30 ) );
		list( $order ) = $this->attributed();
		$this->fresh( $order )->update_status( 'completed' );
		$this->past_due( $this->row( $order ) );
		$this->fresh( $order )->update_status( 'cancelled' );
		$this->fresh( $order )->update_status( 'completed' );

		$this->assertSame( 0, Commissions::approve_due(), 'The old term does not survive the rejection.' );
		$this->assertSame( 'pending', $this->row( $order )['status'] );
	}

	public function test_manual_reject_not_restored() {
		list( $order, $row ) = $this->attributed();
		$this->assertTrue( Commissions::transition( $row['id'], 'rejected', 'manual' ) );

		$this->fresh( $order )->update_status( 'cancelled' );
		$this->assertSame( 'manual', $this->row( $order )['reason'], 'A later cancel does not overwrite the reason.' );

		$this->fresh( $order )->update_status( 'completed' );
		$after = $this->row( $order );
		$this->assertSame( 'rejected', $after['status'] );
		$this->assertSame( 'manual', $after['reason'] );
		$this->assertNull( $after['approve_after'] );
	}

	public function test_self_referral_not_restored() {
		$partner = $this->affiliate( array( 'email' => 'jan@x.pl' ) );
		$this->set_cookie( $partner['code'] );
		$order = $this->order( 80.0, 'jan@x.pl' );
		Attribution::on_order( $order );

		$this->fresh( $order )->update_status( 'cancelled' );
		$this->fresh( $order )->update_status( 'processing' );
		$this->fresh( $order )->update_status( 'completed' );

		$after = $this->row( $order );
		$this->assertSame( 'rejected', $after['status'] );
		$this->assertSame( 'self_referral', $after['reason'] );
	}

	// ----------------------------------------------------------------------- Zwroty

	public function test_two_partial_refunds_are_cumulative() {
		list( $order ) = $this->attributed();

		$this->refund( $this->fresh( $order ), 20.0 );
		$first = $this->row( $order );
		$this->assertSame( 60.0, (float) $first['base_amount'] );
		$this->assertSame( 6.0, (float) $first['commission'] );

		$this->refund( $this->fresh( $order ), 20.0 );
		$second = $this->row( $order );
		$this->assertSame( 40.0, (float) $second['base_amount'] );
		$this->assertSame( 4.0, (float) $second['commission'] );
		$this->assertSame( 80.0, (float) $second['base_original'], 'The original base never changes.' );
		$this->assertSame( 'pending', $second['status'] );
	}

	public function test_refund_share_is_measured_against_the_whole_order_total() {
		list( $order ) = $this->attributed();
		$order         = $this->fresh( $order );
		$order->set_shipping_total( '20' );
		$order->set_total( '100' );
		$order->save();

		$this->refund( $this->fresh( $order ), 25.0 );

		$row = $this->row( $order );
		$this->assertSame( 60.0, (float) $row['base_amount'], '25 of 100 is a quarter of the base.' );
		$this->assertSame( 6.0, (float) $row['commission'] );
	}

	public function test_commission_is_rounded_to_price_decimals() {
		list( $order ) = $this->attributed( 33.33 );
		$row           = $this->row( $order );
		$this->assertSame( 33.33, (float) $row['base_original'] );
		$this->assertSame( 3.33, (float) $row['commission'] );

		$this->refund( $this->fresh( $order ), 11.11 );
		$this->assertSame( 2.22, (float) $this->row( $order )['commission'] );
	}

	public function test_refund_after_paid_sets_clawback_keeps_paid() {
		list( $order, $row ) = $this->attributed();
		$this->make_paid( $row );

		$this->refund( $this->fresh( $order ), 20.0 );

		$after = $this->row( $order );
		$this->assertSame( 'paid', $after['status'] );
		$this->assertSame( 2.0, (float) $after['clawback'] );
		$this->assertSame( 8.0, (float) $after['commission'], 'The commission of a paid row is not recalculated.' );
		$this->assertSame( 80.0, (float) $after['base_amount'] );
		$this->assertSame( 8.0, (float) $after['commission_paid'] );
		$this->assertSame( 'TR-1', $after['payout_ref'] );
	}

	public function test_full_refund_status_after_paid_keeps_paid_with_full_clawback() {
		list( $order, $row ) = $this->attributed();
		$this->make_paid( $row );

		$this->fresh( $order )->update_status( 'refunded' );

		$after = $this->row( $order );
		$this->assertSame( 'paid', $after['status'] );
		$this->assertSame( '', $after['reason'] );
		$this->assertSame( 8.0, (float) $after['clawback'] );
	}

	public function test_clawback_growth_clears_settlement_and_same_amount_keeps_it() {
		list( $order, $row ) = $this->attributed();
		$this->make_paid( $row );
		$this->refund( $this->fresh( $order ), 20.0 );
		Store::update_commission(
			$row['id'],
			array(
				'clawback_settled_at' => '2026-10-01 10:00:00',
				'clawback_ref'        => 'NOTE-1',
			)
		);

		Commissions::on_refund( $order->get_id() );
		$same = $this->row( $order );
		$this->assertSame( '2026-10-01 10:00:00', $same['clawback_settled_at'], 'The same clawback stays settled.' );
		$this->assertSame( 'NOTE-1', $same['clawback_ref'] );

		$this->refund( $this->fresh( $order ), 20.0 );
		$grown = $this->row( $order );
		$this->assertSame( 4.0, (float) $grown['clawback'] );
		$this->assertNull( $grown['clawback_settled_at'] );
		$this->assertSame( '', $grown['clawback_ref'] );
	}

	public function test_clawback_counts_only_what_was_paid() {
		list( $order, $row ) = $this->attributed();
		$this->make_paid( $row, '5.0000' );

		$this->refund( $this->fresh( $order ), 20.0 );
		$this->assertSame( 0.0, (float) $this->row( $order )['clawback'], 'Paid 5, still owed 6: nothing to take back.' );

		$this->refund( $this->fresh( $order ), 40.0 );
		$this->assertSame( 3.0, (float) $this->row( $order )['clawback'], 'Paid 5, owed 2.' );
	}

	public function test_refund_deleted_recalculates() {
		list( $order ) = $this->attributed();
		$refund        = $this->refund( $this->fresh( $order ), 20.0 );
		$this->assertSame( 6.0, (float) $this->row( $order )['commission'] );
		$refund_id = $refund->get_id();

		// Jak WC_AJAX::delete_refund: pierwszy argument hooka to id zwrotu, drugi id zamówienia.
		$this->assertSame( 20.0, (float) $this->fresh( $order )->get_total_refunded() );
		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );

		$after = $this->row( $order );
		$this->assertSame( 80.0, (float) $after['base_amount'] );
		$this->assertSame( 8.0, (float) $after['commission'] );
	}

	public function test_refund_deleted_after_paid_lowers_clawback() {
		list( $order, $row ) = $this->attributed();
		$this->make_paid( $row );
		$refund    = $this->refund( $this->fresh( $order ), 20.0 );
		$refund_id = $refund->get_id();
		$this->assertSame( 2.0, (float) $this->row( $order )['clawback'] );

		$refund->delete( true );
		do_action( 'woocommerce_refund_deleted', $refund_id, $order->get_id() );

		$after = $this->row( $order );
		$this->assertSame( 'paid', $after['status'] );
		$this->assertSame( 0.0, (float) $after['clawback'] );
	}

	// ------------------------------------------------------------------- Uzgadnianie

	public function test_cron_rejects_order_refunded_while_hooks_off() {
		$this->settings( array( 'hold_days' => 0 ) );
		list( $order ) = $this->attributed();
		$this->fresh( $order )->update_status( 'completed' );
		$this->past_due( $this->row( $order ) );

		$this->without_hooks(
			function () use ( $order ) {
				$this->fresh( $order )->update_status( 'refunded' );
			}
		);
		$this->assertSame( 'pending', $this->row( $order )['status'], 'The hooks really were off.' );

		$this->assertSame( 0, Commissions::approve_due() );

		$after = $this->row( $order );
		$this->assertSame( 'rejected', $after['status'] );
		$this->assertSame( 'order_status', $after['reason'] );
	}

	public function test_cron_recalculates_partial_refund_missed_by_hooks() {
		$this->settings( array( 'hold_days' => 0 ) );
		list( $order ) = $this->attributed();
		$this->fresh( $order )->update_status( 'completed' );
		$this->without_hooks(
			function () use ( $order ) {
				$this->refund( $this->fresh( $order ), 20.0 );
			}
		);
		$this->assertSame( 8.0, (float) $this->row( $order )['commission'], 'The hooks really were off.' );

		$this->assertSame( 1, Commissions::approve_due() );

		$after = $this->row( $order );
		$this->assertSame( 'approved', $after['status'] );
		$this->assertSame( 6.0, (float) $after['commission'] );
	}

	public function test_cron_skips_deleted_order() {
		$this->settings( array( 'hold_days' => 0 ) );
		list( $gone ) = $this->attributed();
		$this->fresh( $gone )->update_status( 'completed' );
		$gone_id = $gone->get_id();
		list( $live ) = $this->attributed();
		$this->fresh( $live )->update_status( 'completed' );
		$this->fresh( $gone )->delete( true );

		$this->assertSame( 1, Commissions::approve_due(), 'A missing order does not stop the next rows.' );

		$kept = Store::commission_for_order( $gone_id );
		$this->assertNotNull( $kept, 'The row stays after the order is deleted.' );
		$this->assertSame( 'pending', $kept['status'] );
		$this->assertSame( 'approved', $this->row( $live )['status'] );
	}

	public function test_reconcile_results() {
		list( $order, $row ) = $this->attributed();
		$this->assertSame( 'order_missing', Commissions::reconcile( $row, null ) );
		$this->assertSame( 'pending', $this->row( $order )['status'] );

		$this->assertSame( 'ok', Commissions::reconcile( $row, $this->fresh( $order ) ) );
		$this->assertSame( 'pending', $this->row( $order )['status'] );

		$this->without_hooks(
			function () use ( $order ) {
				$this->fresh( $order )->update_status( 'failed' );
			}
		);
		$this->assertSame( 'rejected', Commissions::reconcile( $row, $this->fresh( $order ) ) );
		$after = $this->row( $order );
		$this->assertSame( 'rejected', $after['status'] );
		$this->assertSame( 'order_status', $after['reason'] );
	}

	public function test_reconcile_keeps_manual_reason_and_paid_state() {
		list( $manual, $row ) = $this->attributed();
		Commissions::transition( $row['id'], 'rejected', 'manual' );
		$this->without_hooks(
			function () use ( $manual ) {
				$this->fresh( $manual )->update_status( 'cancelled' );
			}
		);
		$this->assertSame( 'rejected', Commissions::reconcile( $this->row( $manual ), $this->fresh( $manual ) ) );
		$this->assertSame( 'manual', $this->row( $manual )['reason'] );

		list( $paid, $paid_row ) = $this->attributed();
		$this->make_paid( $paid_row );
		$this->without_hooks(
			function () use ( $paid ) {
				$this->fresh( $paid )->update_status( 'refunded' );
			}
		);
		$this->assertSame( 'ok', Commissions::reconcile( $this->row( $paid ), $this->fresh( $paid ) ) );
		$after = $this->row( $paid );
		$this->assertSame( 'paid', $after['status'] );
		$this->assertSame( 8.0, (float) $after['clawback'] );
	}

	// ------------------------------------------------------------- Stany i podpięcia

	public function test_transition_refuses_unknown_status_and_reason() {
		list( $order, $row ) = $this->attributed();

		$this->assertFalse( Commissions::transition( $row['id'], 'cancelled' ) );
		$this->assertFalse( Commissions::transition( $row['id'], 'rejected', 'because' ) );
		$this->assertSame( 'pending', $this->row( $order )['status'] );
		$this->assertSame( '', $this->row( $order )['reason'] );

		$this->assertTrue( Commissions::transition( $row['id'], 'approved' ) );
		$this->assertSame( 'approved', $this->row( $order )['status'] );
	}

	public function test_create_refuses_unknown_status_and_reason() {
		$partner = $this->affiliate();
		$this->assertSame(
			0,
			Commissions::create(
				array(
					'order_id'     => 999001,
					'affiliate_id' => $partner['id'],
					'status'       => 'weird',
				)
			)
		);
		$this->assertSame(
			0,
			Commissions::create(
				array(
					'order_id'     => 999002,
					'affiliate_id' => $partner['id'],
					'status'       => 'rejected',
					'reason'       => 'because',
				)
			)
		);
		$this->assertSame( 0, Store::query_commissions( array() )['total'] );
	}

	public function test_hooks_are_registered_with_the_right_arguments() {
		$this->assertSame( 'aktio_connector_affiliates_daily', Commissions::CRON );
		$this->assertNotFalse( has_action( 'woocommerce_checkout_order_created', array( Attribution::class, 'on_order' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_store_api_checkout_order_processed', array( Attribution::class, 'on_order' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_status_changed', array( Commissions::class, 'on_status_change' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_order_refunded', array( Commissions::class, 'on_refund' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_refund_deleted', array( Commissions::class, 'on_refund_deleted' ) ) );
		$this->assertNotFalse( has_action( 'init', array( Commissions::class, 'schedule' ) ) );
		$this->assertNotFalse( has_action( Commissions::CRON, array( Commissions::class, 'run_daily' ) ) );
	}

	public function test_cron_is_scheduled_on_activation_and_cleared_on_deactivation() {
		wp_clear_scheduled_hook( Commissions::CRON );
		Commissions::activate();
		$this->assertNotFalse( wp_next_scheduled( Commissions::CRON ) );
		Store::deactivate();
		$this->assertFalse( wp_next_scheduled( Commissions::CRON ) );
		Commissions::schedule();
		$this->assertNotFalse( wp_next_scheduled( Commissions::CRON ) );
		$first = wp_next_scheduled( Commissions::CRON );
		Commissions::schedule();
		$this->assertSame( $first, wp_next_scheduled( Commissions::CRON ), 'One event only.' );
	}

	public function test_daily_cron_approves_and_cleans_up() {
		global $wpdb;
		$this->settings( array( 'hold_days' => 0 ) );
		list( $order, $row ) = $this->attributed();
		$this->fresh( $order )->update_status( 'completed' );
		$this->settings( array( 'enabled' => false ) );

		$table = Store::clicks_table();
		foreach ( array( 401, 400, 0 ) as $age ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				$table,
				array(
					'day'          => gmdate( 'Y-m-d', time() - $age * DAY_IN_SECONDS ),
					'affiliate_id' => $row['affiliate_id'],
					'count'        => 1,
				)
			);
		}
		add_option( '_transient_timeout_' . Store::TRANSIENT . 'stale', time() - 100, '', false );
		add_option( '_transient_' . Store::TRANSIENT . 'stale', 1, '', false );

		do_action( Commissions::CRON );

		$this->assertSame( 'approved', $this->row( $order )['status'], 'The cron works with the module off.' );
		$days = $wpdb->get_col( "SELECT day FROM {$table} ORDER BY day ASC" ); // phpcs:ignore WordPress.DB
		$this->assertSame(
			array( gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) ),
			$days,
			'Clicks older than 400 days are deleted.'
		);
		$this->assertFalse( get_option( '_transient_timeout_' . Store::TRANSIENT . 'stale' ) );
		$this->assertFalse( get_option( '_transient_' . Store::TRANSIENT . 'stale' ) );
	}
}
