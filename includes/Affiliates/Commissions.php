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

	/** Akcje właściciela dla apply(). */
	const ACTIONS = array( 'approve', 'reject', 'paid', 'settle_clawback' );

	/** Najwięcej wierszy w jednej akcji. */
	const MAX_IDS = 200;

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
	 * Jedyna bramka stanu: zmienia stan z `$row['status']` na podany, opcjonalnie z innymi polami. Warunek na bieżący
	 * status w bazie chroni przed nadpisaniem równoległej zmiany (np. ręcznego odrzucenia w trakcie crona).
	 * False, gdy stan jest niedozwolony, wiersz ma już inny status albo zapis się nie udał.
	 */
	public static function transition( array $row, string $status, string $reason = '', array $fields = array() ): bool {
		if ( ! self::valid_state( $status, $reason ) ) {
			return false;
		}
		$fields['status'] = $status;
		$fields['reason'] = $reason;
		return Store::update_commission_if_status( $row['id'], $row['status'], $fields );
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
					self::transition( $row, 'rejected', 'order_status' );
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
				Store::update_commission_if_status( $row['id'], 'pending', array( 'approve_after' => self::approve_after() ) );
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
		self::transition( $row, 'pending', '', array( 'approve_after' => 'completed' === $to ? self::approve_after() : null ) );
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
	 * Wiersz `paid` zachowuje podstawę i prowizję, zmienia się tylko `clawback`; rozliczenie potrącenia zostaje
	 * w swoich polach, a różnicę pokazuje clawback_balance().
	 *
	 * @throws \RuntimeException Gdy wyliczonych kwot nie udało się zapisać (błąd bazy albo wiersz zmienił już status):
	 *                           wywołujący nie może iść dalej ze starą kwotą.
	 */
	public static function recalculate( array $row, \WC_Order $order ): void {
		$base       = (float) $row['base_original'] * self::kept_share( $order );
		$commission = self::commission_amount( $base, (int) $row['rate_bp'] );

		if ( 'paid' === $row['status'] ) {
			$clawback = self::money( max( 0.0, (float) $row['commission_paid'] - $commission ) );
			if ( $clawback !== self::money( (float) $row['clawback'] ) ) {
				self::save_amounts( $row, array( 'clawback' => $clawback ) );
			}
			return;
		}

		$fields = array(
			'base_amount' => self::money( $base ),
			'commission'  => self::money( $commission ),
		);
		if ( $fields['base_amount'] !== self::money( (float) $row['base_amount'] ) || $fields['commission'] !== self::money( (float) $row['commission'] ) ) {
			self::save_amounts( $row, $fields );
		}
	}

	private static function save_amounts( array $row, array $fields ): void {
		if ( ! Store::update_commission_if_status( $row['id'], $row['status'], $fields ) ) {
			throw new \RuntimeException( 'Commission amounts were not saved.' );
		}
	}

	/**
	 * Potrącenie, które zostało do rozliczenia: `clawback` − `clawback_settled_amount`.
	 * Ujemne saldo znaczy, że rozliczono więcej, niż ostatecznie należało (np. zwrot usunięty po rozliczeniu).
	 */
	public static function clawback_balance( array $row ): string {
		return self::money( (float) $row['clawback'] - (float) $row['clawback_settled_amount'] );
	}

	/**
	 * Część towarów, która została po zwrotach: 1 − min(1, zwroty towarów / towary), bez wysyłki i jej podatku po obu stronach.
	 * Licznik i mianownik są brutto: suma zwrotów i suma zamówienia zawierają podatek. Zwrot bez zaznaczonej pozycji
	 * wysyłki liczy się w całości jako towary. Bez wartości towarów: całość zostaje, chyba że cokolwiek zwrócono na towary.
	 */
	private static function kept_share( \WC_Order $order ): float {
		$goods    = round( (float) $order->get_total() - (float) $order->get_shipping_total() - (float) $order->get_shipping_tax(), 4 );
		$refunded = round( (float) $order->get_total_refunded() - abs( (float) $order->get_total_shipping_refunded() ) - self::shipping_tax_refunded( $order ), 4 );
		if ( $goods <= 0.0 ) {
			return $refunded > 0.0 ? 0.0 : 1.0;
		}
		return 1.0 - min( 1.0, max( 0.0, $refunded ) / $goods );
	}

	/** get_total_shipping_refunded() nie zawiera podatku, a get_total_shipping_tax_refunded() jest dopiero od WooCommerce 10.2. */
	private static function shipping_tax_refunded( \WC_Order $order ): float {
		$tax = 0.0;
		foreach ( $order->get_refunds() as $refund ) {
			foreach ( $refund->get_items( 'shipping' ) as $item ) {
				$tax += abs( (float) $item->get_total_tax() );
			}
		}
		return $tax;
	}

	public static function commission_amount( float $base, int $rate_bp ): float {
		return round( $base * $rate_bp / 10000, wc_get_price_decimals() );
	}

	/**
	 * Kwota do kolumny DECIMAL(19,4). Zawsze z kropką: na PHP 7.4 rzutowanie float na string zależy od locale.
	 */
	public static function money( float $amount ): string {
		return number_format( round( $amount, 4 ) + 0.0, 4, '.', '' ); // + 0.0: PHP 7.4 pisałby ujemne zero jako -0.0000.
	}

	// -------------------------------------------------------------------- Uzgadnianie

	/**
	 * Uzgadnia wiersz z aktualnym stanem zamówienia (hooki nie odpalają się przy wyłączonym WooCommerce lub wtyczce).
	 * Zwraca `order_missing` (bez zmian), `rejected` albo `ok`. Wiersz `paid` zostaje `paid`, zwrot idzie do `clawback`.
	 * Odrzucony wiersz zachowuje swój powód, np. `manual`.
	 *
	 * `ok` opisuje zamówienie, nie wiersz: wiersz `rejected` na żywym zamówieniu też dostaje `ok` i zostaje `rejected`.
	 * `ok` nie znaczy więc „można wypłacić”: wywołujący sprawdza też status wiersza i zapisuje przez transition().
	 *
	 * @throws \RuntimeException Z recalculate(), gdy kwot nie udało się zapisać.
	 */
	public static function reconcile( array $row, ?\WC_Order $order ): string {
		if ( null === $order ) {
			return 'order_missing';
		}
		if ( 'paid' !== $row['status'] && in_array( $order->get_status(), self::ORDER_REJECTING, true ) ) {
			if ( 'rejected' !== $row['status'] ) {
				self::transition( $row, 'rejected', 'order_status' );
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
					if ( 'ok' === self::reconcile( $row, $order ) && $order->has_status( 'completed' ) && self::transition( $row, 'approved' ) ) {
						++$approved;
					}
				} catch ( \Throwable $e ) {
					self::log_error( 'approval', $row['order_id'], $e );
				}
			}
		} while ( count( $rows ) === Store::MAX_PER_PAGE );
		return $approved;
	}

	/**
	 * Nadrabia zmiany zamówień, które minęły hooki (wyłączony WooCommerce lub wtyczka, zwrot usunięty przez REST WooCommerce):
	 * `pending` bez terminu dostaje termin albo odrzucenie, `rejected` z powodu `order_status` wraca, gdy zamówienie wróciło,
	 * `approved` i `paid` z ostatnich Store::SYNC_SETTLED_DAYS dni są uzgadniane z bieżącym stanem. Usunięte zamówienie: bez zmian.
	 * Sumy zwrotów WooCommerce trzyma w cache'u, którego usunięcie zwrotu bez hooka nie czyści: jedno unieważnienie grupy na przebieg.
	 */
	public static function sync_missed(): void {
		if ( ! Store::available() ) {
			return;
		}
		wc_delete_shop_order_transients();
		$after_id = 0;
		do {
			$rows = Store::commissions_to_sync( $after_id, Store::MAX_PER_PAGE );
			foreach ( $rows as $row ) {
				$after_id = $row['id'];
				try {
					self::sync_row( $row, self::order( $row['order_id'] ) );
				} catch ( \Throwable $e ) {
					self::log_error( 'sync', $row['order_id'], $e );
				}
			}
		} while ( count( $rows ) === Store::MAX_PER_PAGE );
	}

	private static function sync_row( array $row, ?\WC_Order $order ): void {
		if ( null === $order ) {
			return;
		}
		if ( 'rejected' === $row['status'] ) {
			if ( in_array( $order->get_status(), self::ORDER_RESTORING, true ) ) {
				self::restore( $row, $order->get_status() );
			}
			return;
		}
		$result = self::reconcile( $row, $order );
		if ( 'ok' === $result && 'pending' === $row['status'] && $order->has_status( 'completed' ) ) {
			Store::update_commission_if_status( $row['id'], 'pending', array( 'approve_after' => self::approve_after() ) );
		}
	}

	/**
	 * Dzienny cron: nadrabianie, zatwierdzanie i sprzątanie. Każdy krok osobno, by błąd jednego nie zatrzymał następnych.
	 * Nadrabianie jest pierwsze, więc przy karencji 0 wiersz zatwierdza się w tym samym przebiegu.
	 */
	public static function run_daily(): void {
		try {
			self::sync_missed();
		} catch ( \Throwable $e ) {
			self::log_error( 'daily sync', 0, $e );
		}
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

	// ------------------------------------------------------------------ Akcje właściciela

	/** Numer przelewu albo dokumentu: przycięty tekst, 1–100 znaków; null, gdy pusty lub za długi. */
	public static function clean_ref( string $ref ): ?string {
		$ref = trim( sanitize_text_field( $ref ) );
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $ref ) : strlen( $ref );
		return '' === $ref || $len > 100 ? null : $ref;
	}

	/**
	 * Akcja właściciela na wierszach. Wynik wiersza: ok | skipped | invalid_state | order_refunded | order_missing.
	 * `skipped`: wiersza nie ma, zmienił się w międzyczasie albo przeliczenie się nie udało (reszta paczki idzie dalej).
	 * Zmiany stanu tylko przez transition(), więc powtórne `paid` niczego nie zmienia.
	 * Wejście jest sprawdzane w całości przed jakąkolwiek zmianą i nigdy nie jest rzutowane na inny wiersz:
	 * id to dodatnie liczby całkowite (int albo tekst z samych cyfr), najwyżej MAX_IDS; wynik ma klucz na każde id.
	 *
	 * @return array{results:array<int,string>}|\WP_Error aktio_aff_invalid albo aktio_aff_too_many.
	 */
	public static function apply( string $action, array $ids, string $ref = '' ) {
		$clean = self::clean_ref( $ref );
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new \WP_Error( 'aktio_aff_invalid', __( 'The action must be approve, reject, paid or settle_clawback.', 'aktio-connector' ) );
		}
		if ( in_array( $action, array( 'paid', 'settle_clawback' ), true ) && null === $clean ) {
			return new \WP_Error( 'aktio_aff_invalid', __( 'The reference is required and can have at most 100 characters.', 'aktio-connector' ) );
		}
		if ( array() === $ids ) {
			return new \WP_Error( 'aktio_aff_invalid', __( 'Provide a list of commission IDs.', 'aktio-connector' ) );
		}
		if ( count( $ids ) > self::MAX_IDS ) {
			return new \WP_Error( 'aktio_aff_too_many', __( 'At most 200 commissions can be changed at once.', 'aktio-connector' ) );
		}
		$clean_ids = array();
		foreach ( $ids as $id ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[1-9][0-9]{0,17}\z/', $id ) ) {
				$id = (int) $id;
			}
			if ( ! is_int( $id ) || $id <= 0 ) {
				return new \WP_Error( 'aktio_aff_invalid', __( 'Commission IDs must be positive whole numbers.', 'aktio-connector' ) );
			}
			$clean_ids[ $id ] = $id;
		}
		$ids     = array_values( $clean_ids );
		$results = array();
		$rows = array();
		foreach ( Store::get_commissions_by_ids( $ids ) as $row ) {
			$rows[ $row['id'] ] = $row;
		}
		foreach ( $ids as $id ) {
			if ( ! isset( $rows[ $id ] ) ) {
				$results[ $id ] = 'skipped';
				continue;
			}
			try {
				$results[ $id ] = self::apply_one( $action, $rows[ $id ], (string) $clean );
			} catch ( \Throwable $e ) {
				self::log_error( 'action ' . $action, $rows[ $id ]['order_id'], $e );
				$results[ $id ] = 'skipped';
			}
		}
		return array( 'results' => $results );
	}

	private static function apply_one( string $action, array $row, string $ref ): string {
		switch ( $action ) {
			case 'reject':
				if ( ! in_array( $row['status'], array( 'pending', 'approved' ), true ) ) {
					return 'invalid_state';
				}
				return self::transition( $row, 'rejected', 'manual' ) ? 'ok' : 'skipped';

			case 'approve':
				if ( 'pending' !== $row['status'] ) {
					return 'invalid_state';
				}
				// Termin karencji znaczy, że zamówienie minęło `completed`: sklep z własnym statusem końcowym też może zatwierdzić.
				// Statusy odrzucające wygrywają wcześniej, w reconcile(). Cron zostaje ostrożny i wymaga `completed`.
				return self::apply_checked( $row, 'pending', 'approved', static fn( array $fresh, \WC_Order $order ) => $order->has_status( 'completed' ) || null !== $fresh['approve_after'] ? array() : null );

			case 'paid':
				if ( 'paid' === $row['status'] ) {
					return 'skipped'; // Powtórzenie niczego nie zmienia: numer przelewu i data zostają z pierwszej wypłaty.
				}
				if ( 'approved' !== $row['status'] ) {
					return 'invalid_state';
				}
				return self::apply_checked(
					$row,
					'approved',
					'paid',
					static fn( array $fresh ) => (float) $fresh['commission'] <= 0.0 ? null : array(
						'commission_paid' => $fresh['commission'],
						'paid_at'         => current_time( 'mysql', true ),
						'payout_ref'      => $ref,
					)
				);

			default: // settle_clawback.
				return self::settle( $row, $ref );
		}
	}

	/**
	 * Wspólny przebieg `approve` i `paid`: aktualne zamówienie decyduje pierwsze, potem wiersz jest czytany ponownie
	 * (przeliczenie mogło zmienić kwoty) i zapisywany bramką stanu. `$fields` zwraca pola zapisu albo null, gdy
	 * zamówienie lub kwota nie spełnia warunku (invalid_state).
	 */
	private static function apply_checked( array $row, string $from, string $to, callable $fields ): string {
		$order  = self::order( $row['order_id'] );
		$result = self::reconcile( $row, $order );
		if ( 'ok' !== $result ) {
			return 'rejected' === $result ? 'order_refunded' : $result;
		}
		$fresh = self::fresh( $row['id'] );
		if ( null === $fresh || $from !== $fresh['status'] ) {
			return 'skipped';
		}
		$extra = $fields( $fresh, $order );
		if ( null === $extra ) {
			return 'invalid_state';
		}
		return self::transition( $fresh, $to, '', $extra ) ? 'ok' : 'skipped';
	}

	private static function settle( array $row, string $ref ): string {
		if ( 'paid' !== $row['status'] ) {
			return 'invalid_state';
		}
		$order = self::order( $row['order_id'] );
		if ( null !== $order ) {
			self::reconcile( $row, $order ); // Zwrot, który minął hooki, trafia do `clawback` przed rozliczeniem.
		}
		$fresh = self::fresh( $row['id'] );
		if ( null === $fresh || 'paid' !== $fresh['status'] ) {
			return 'skipped';
		}
		if ( 0.0 === (float) self::clawback_balance( $fresh ) ) {
			return 'invalid_state';
		}
		$fields = array(
			'clawback_settled_amount' => $fresh['clawback'],
			'clawback_settled_at'     => current_time( 'mysql', true ),
			'clawback_ref'            => $ref,
		);
		return self::transition( $fresh, 'paid', $fresh['reason'], $fields ) ? 'ok' : 'skipped';
	}

	private static function fresh( int $id ): ?array {
		$rows = Store::get_commissions_by_ids( array( $id ) );
		return $rows[0] ?? null;
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
		self::log_failure( sprintf( '%s failed for order %d: %s at %s:%d', $context, $order_id, get_class( $e ), basename( $e->getFile() ), $e->getLine() ) );
	}

	/** Jak log_error, dla błędu bez wyjątku. Treść bez danych osobowych. */
	public static function log_failure( string $message ): void {
		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Affiliates: ' . $message, array( 'source' => 'aktio-connector-affiliates' ) );
			}
		} catch ( \Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
