<?php
/**
 * Moduł partnerski: cykl życia prowizji. Hooki statusów i zwrotów oraz cron działają także przy
 * wyłączonym module, bo rozliczanie istniejących wierszy trwa; nowe wiersze tworzy tylko Attribution.
 * Wyjątek w tym kodzie nigdy nie przerywa zamówienia. Bez wyjścia sieciowego.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Affiliates;

use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class Commissions {

	const CRON = Store::CRON;

	const STATUSES = array( 'pending', 'approved', 'rejected', 'paid' );
	const REASONS  = array( '', 'order_status', 'self_referral', 'manual' );

	/** Statusy zamówienia, przy których prowizja nie może iść dalej. */
	private const ORDER_REJECTING = array( 'refunded', 'cancelled', 'failed' );

	/** Statusy zamówienia, przy których odrzucenie z powodu `order_status` się cofa. */
	private const ORDER_RESTORING = array( 'processing', 'completed' );

	private const CLICK_RETENTION_DAYS = 400;

	/**
	 * Hooki WooCommerce nie wymagają warunku: bez WooCommerce po prostu się nie odpalają. Warunek przy
	 * ładowaniu pliku byłby błędem, bo ta wtyczka ładuje się przed WooCommerce (kolejność alfabetyczna).
	 */
	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_change' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refund' ) );
		add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'on_refund_deleted' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'run_daily' ) );
	}

	public static function activate(): void {
		self::schedule();
	}

	public static function schedule(): void {
		if ( Store::available() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	// ------------------------------------------------------------------- Zapis stanu

	/**
	 * Tworzy wiersz (Store pilnuje unikalnego `order_id`). 0 przy duplikacie albo niedozwolonym stanie.
	 */
	public static function create( array $row ): int {
		if ( ! self::valid_state( (string) ( $row['status'] ?? 'pending' ), (string) ( $row['reason'] ?? '' ) ) ) {
			return 0;
		}
		return Store::insert_commission( $row );
	}

	/**
	 * Zmienia stan wiersza, opcjonalnie z innymi polami. False przy niedozwolonym stanie (nic nie zapisuje).
	 */
	public static function transition( int $id, string $status, string $reason = '', array $fields = array() ): bool {
		if ( ! self::valid_state( $status, $reason ) ) {
			return false;
		}
		$fields['status'] = $status;
		$fields['reason'] = $reason;
		Store::update_commission( $id, $fields );
		return true;
	}

	private static function valid_state( string $status, string $reason ): bool {
		return in_array( $status, self::STATUSES, true ) && in_array( $reason, self::REASONS, true );
	}

	// ------------------------------------------------------------------------- Hooki

	public static function on_status_change( int $order_id, string $from, string $to ): void {
		try {
			$row = Store::commission_for_order( $order_id );
			if ( null === $row || 'paid' === $row['status'] ) {
				return; // Wypłacony wiersz zostaje `paid`; zwrot rozlicza on_refund przez clawback.
			}

			if ( in_array( $to, self::ORDER_REJECTING, true ) ) {
				if ( 'rejected' !== $row['status'] ) {
					self::transition( $row['id'], 'rejected', 'order_status' );
				}
				return;
			}

			if ( 'rejected' === $row['status'] ) {
				// Odrzucenia ręczne i `self_referral` nigdy nie wracają.
				if ( 'order_status' === $row['reason'] && in_array( $to, self::ORDER_RESTORING, true ) ) {
					self::restore( $row, $to );
				}
				return;
			}

			if ( 'pending' === $row['status'] && 'completed' === $to ) {
				Store::update_commission( $row['id'], array( 'approve_after' => self::approve_after() ) );
			}
		} catch ( \Throwable $e ) {
			self::log_error( 'status change', $order_id, $e );
		}
	}

	/** Powrót do `pending`: kwoty według bieżących zwrotów, karencja liczona od nowa. */
	private static function restore( array $row, string $to ): void {
		$order = self::order( $row['order_id'] );
		if ( null !== $order ) {
			self::recalculate( $row, $order );
		}
		self::transition( $row['id'], 'pending', '', array( 'approve_after' => 'completed' === $to ? self::approve_after() : null ) );
	}

	public static function on_refund( int $order_id ): void {
		try {
			$row   = Store::commission_for_order( $order_id );
			$order = self::order( $order_id );
			if ( null !== $row && null !== $order ) {
				self::recalculate( $row, $order );
			}
		} catch ( \Throwable $e ) {
			self::log_error( 'refund', $order_id, $e );
		}
	}

	/**
	 * `woocommerce_refund_deleted` podaje (id zwrotu, id zamówienia), inaczej niż `woocommerce_order_refunded`.
	 * Suma zwrotów w cache'u WooCommerce jest wtedy jeszcze stara, więc ją unieważniamy przed przeliczeniem.
	 */
	public static function on_refund_deleted( int $refund_id, int $order_id ): void {
		try {
			wc_delete_shop_order_transients( $order_id );
		} catch ( \Throwable $e ) {
			self::log_error( 'refund cache', $order_id, $e );
		}
		self::on_refund( $order_id );
	}

	// -------------------------------------------------------------------- Przeliczanie

	/**
	 * Przelicza wiersz według bieżącej sumy zwrotów zamówienia (zwroty są skumulowane, liczone od `base_original`).
	 * Wiersz `paid` zachowuje podstawę i prowizję, zmienia się tylko `clawback`.
	 */
	public static function recalculate( array $row, \WC_Order $order ): void {
		$base       = (float) $row['base_original'] * self::kept_share( $order );
		$commission = self::commission_amount( $base, (int) $row['rate_bp'] );

		if ( 'paid' === $row['status'] ) {
			$old = (float) $row['clawback'];
			$new = max( 0.0, (float) $row['commission_paid'] - $commission );
			if ( self::money( $new ) === self::money( $old ) ) {
				return;
			}
			$fields = array( 'clawback' => self::money( $new ) );
			if ( $new > $old ) {
				$fields['clawback_settled_at'] = null;
				$fields['clawback_ref']        = '';
			}
			Store::update_commission( $row['id'], $fields );
			return;
		}

		$fields = array(
			'base_amount' => self::money( $base ),
			'commission'  => self::money( $commission ),
		);
		if ( $fields['base_amount'] !== self::money( (float) $row['base_amount'] ) || $fields['commission'] !== self::money( (float) $row['commission'] ) ) {
			Store::update_commission( $row['id'], $fields );
		}
	}

	/** Część zamówienia, która została po zwrotach: 1 − min(1, zwroty / suma). */
	private static function kept_share( \WC_Order $order ): float {
		$total = (float) $order->get_total();
		if ( $total <= 0.0 ) {
			return 1.0;
		}
		return 1.0 - min( 1.0, (float) $order->get_total_refunded() / $total );
	}

	public static function commission_amount( float $base, int $rate_bp ): float {
		return round( $base * $rate_bp / 10000, wc_get_price_decimals() );
	}

	/**
	 * Kwota do kolumny DECIMAL(19,4). Zawsze z kropką: na PHP 7.4 rzutowanie float na string zależy od locale.
	 */
	public static function money( float $amount ): string {
		return number_format( $amount, 4, '.', '' );
	}

	// -------------------------------------------------------------------- Uzgadnianie

	/**
	 * Uzgadnia wiersz z aktualnym stanem zamówienia (hooki nie odpalają się przy wyłączonym WooCommerce lub wtyczce).
	 * Zwraca `order_missing` (bez zmian), `rejected` albo `ok`. Wiersz `paid` zostaje `paid`, zwrot idzie do `clawback`.
	 * Odrzucony wiersz zachowuje swój powód, np. `manual`.
	 */
	public static function reconcile( array $row, ?\WC_Order $order ): string {
		if ( null === $order ) {
			return 'order_missing';
		}
		if ( 'paid' !== $row['status'] && in_array( $order->get_status(), self::ORDER_REJECTING, true ) ) {
			if ( 'rejected' !== $row['status'] ) {
				self::transition( $row['id'], 'rejected', 'order_status' );
			}
			return 'rejected';
		}
		self::recalculate( $row, $order );
		return 'ok';
	}

	/**
	 * Zatwierdza wiersze `pending` po terminie karencji. Zamówienie, które nie jest już `completed`
	 * (np. cofnięte do `processing`), czeka: karencja liczy się od kolejnego `completed`.
	 *
	 * @return int Liczba zatwierdzonych wierszy.
	 */
	public static function approve_due(): int {
		if ( ! Store::available() ) {
			return 0;
		}
		$now      = current_time( 'mysql', true );
		$approved = 0;
		$after_id = 0;
		do {
			// Stronicowanie po id: pominięte wiersze (usunięte zamówienie) zostają `pending` i nie blokują kolejnych.
			$rows = Store::due_commissions( $now, $after_id, Store::MAX_PER_PAGE );
			foreach ( $rows as $row ) {
				$after_id = $row['id'];
				try {
					$order = self::order( $row['order_id'] );
					if ( 'ok' === self::reconcile( $row, $order ) && $order->has_status( 'completed' ) ) {
						self::transition( $row['id'], 'approved' );
						++$approved;
					}
				} catch ( \Throwable $e ) {
					self::log_error( 'approval', $row['order_id'], $e );
				}
			}
		} while ( count( $rows ) === Store::MAX_PER_PAGE );
		return $approved;
	}

	/** Dzienny cron: zatwierdzanie i sprzątanie. Każdy krok osobno, by błąd jednego nie zatrzymał drugiego. */
	public static function run_daily(): void {
		try {
			self::approve_due();
		} catch ( \Throwable $e ) {
			self::log_error( 'daily approval', 0, $e );
		}
		try {
			Tracker::purge_transients();
			Store::purge_clicks( gmdate( 'Y-m-d', time() - self::CLICK_RETENTION_DAYS * DAY_IN_SECONDS ) );
		} catch ( \Throwable $e ) {
			self::log_error( 'daily cleanup', 0, $e );
		}
	}

	// --------------------------------------------------------------------- Pomocnicze

	private static function approve_after(): string {
		return gmdate( 'Y-m-d H:i:s', time() + (int) Settings::get()['affiliates']['hold_days'] * DAY_IN_SECONDS );
	}

	private static function order( int $order_id ): ?\WC_Order {
		$order = wc_get_order( $order_id );
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Zapis błędu do logu WooCommerce. Tylko klasa wyjątku i miejsce: treść wyjątku może zawierać dane osobowe.
	 * Sam log też nie może przerwać zamówienia.
	 */
	public static function log_error( string $context, int $order_id, \Throwable $e ): void {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf( 'Affiliates: %s failed for order %d: %s at %s:%d', $context, $order_id, get_class( $e ), basename( $e->getFile() ), $e->getLine() ),
					array( 'source' => 'aktio-connector-affiliates' )
				);
			}
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
