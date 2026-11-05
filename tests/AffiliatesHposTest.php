<?php
use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use AktioConnector\Affiliates\Attribution;
use AktioConnector\Affiliates\Commissions;
use AktioConnector\Affiliates\Store;
use AktioConnector\Affiliates\Tracker;
use AktioConnector\Settings;

/**
 * Krytyczna ścieżka prowizji na własnych tabelach zamówień WooCommerce (HPOS), kryterium akceptacji 2.
 */
class AffiliatesHposTest extends WP_UnitTestCase {

	/** @var array<string,mixed> */
	private $previous = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();
		// Poza transakcją testu: prawdziwe tabele, nie tymczasowe.
		wc_get_container()->get( DataSynchronizer::class )->create_database_tables();
	}

	public function set_up() {
		parent::set_up();
		Store::install();
		global $wpdb;
		foreach ( array( Store::affiliates_table(), Store::commissions_table(), Store::clicks_table() ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( Settings::OPTION );
		Settings::update( array( 'affiliates' => array( 'enabled' => true, 'consent_mode' => 'self_managed', 'hold_days' => 0 ) ) );

		foreach ( array( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION ) as $option ) {
			$this->previous[ $option ] = get_option( $option, null );
		}
		update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, 'yes' );
		update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );
	}

	public function tear_down() {
		// Nie update_option: WooCommerce odmawia zmiany, gdy są niezsynchronizowane zamówienia, a testowe właśnie takie są.
		foreach ( $this->previous as $option => $value ) {
			delete_option( $option );
			if ( null !== $value ) {
				add_option( $option, $value );
			}
		}
		unset( $_COOKIE[ Tracker::COOKIE ] );
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	public function test_order_creates_one_commission_refund_recalculates_and_cron_approves() {
		$order = $this->order_with_shipping();
		$this->assertSame( OrdersTableDataStore::class, wc_get_order( $order->get_id() )->get_data_store()->get_current_class_name(), 'The orders really are in the HPOS tables.' );
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );

		$partner = Store::get_affiliate( Store::save_affiliate( array( 'name' => 'HPOS partner' ) ) );
		$ts      = time();

		$_COOKIE[ Tracker::COOKIE ] = $partner['code'] . '.' . $ts . '.' . Tracker::sign( $partner['code'], $ts );
		Attribution::on_order( wc_get_order( $order->get_id() ) );
		Attribution::on_order( wc_get_order( $order->get_id() ) );
		unset( $_COOKIE[ Tracker::COOKIE ] );

		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Store::commissions_table() . ' WHERE order_id = %d', $order->get_id() ) ) ); // phpcs:ignore WordPress.DB
		$row = Store::commission_for_order( $order->get_id() );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( 10.0, (float) $row['commission'] );

		$lines = $this->line_ids( $order );
		$this->refund( $order, 20.0, array( $lines['shipping'] => array( 'refund_total' => 20 ) ) );
		$this->assertSame( 10.0, (float) Store::commission_for_order( $order->get_id() )['commission'], 'Shipping refund leaves the commission.' );

		$this->refund( $order, 25.0, array( $lines['goods'] => array( 'refund_total' => 25 ) ) );
		$row = Store::commission_for_order( $order->get_id() );
		$this->assertSame( 75.0, (float) $row['base_amount'] );
		$this->assertSame( 7.5, (float) $row['commission'] );

		wc_get_order( $order->get_id() )->update_status( 'completed' );
		$this->assertNotNull( Store::commission_for_order( $order->get_id() )['approve_after'] );
		$this->assertSame( 1, Commissions::approve_due() );

		$after = Store::commission_for_order( $order->get_id() );
		$this->assertSame( 'approved', $after['status'] );
		$this->assertSame( 7.5, (float) $after['commission'] );
	}

	private function order_with_shipping(): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Goods' );
		$product->set_regular_price( '100' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat' );
		$shipping->set_total( '20' );
		$order->add_item( $shipping );
		$order->set_currency( 'EUR' );
		$order->set_billing_email( 'buyer@example.com' );
		$order->calculate_totals( false );
		$order->save();
		return $order;
	}

	private function line_ids( WC_Order $order ): array {
		$fresh = wc_get_order( $order->get_id() );
		return array(
			'goods'    => array_key_first( $fresh->get_items() ),
			'shipping' => array_key_first( $fresh->get_items( 'shipping' ) ),
		);
	}

	private function refund( WC_Order $order, float $amount, array $lines ): void {
		$refund = wc_create_refund(
			array(
				'order_id'   => $order->get_id(),
				'amount'     => $amount,
				'line_items' => $lines,
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
	}
}
