<?php
/**
 * Moduł partnerski: tabele (partnerzy, prowizje, kliknięcia), dostęp do danych i sprzątanie po odinstalowaniu.
 * Bez wyjścia sieciowego — dane zostają w sklepie.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Affiliates;

use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class Store {

	const SCHEMA_OPTION = 'aktio_connector_affiliates_schema';
	const SCHEMA        = 3;
	const CRON          = 'aktio_connector_affiliates_daily';
	const TRANSIENT     = 'aktio_ar_';
	const COUPON_META   = '_aktio_affiliate_id';
	const MAX_PER_PAGE  = 200;

	const STATUSES = array( 'active', 'paused' );

	/** Kolumny wiersza prowizji, które wolno zapisać (poza id, order_id i created_at przy zmianie). */
	const COMMISSION_COLUMNS = array(
		'order_id',
		'affiliate_id',
		'source',
		'status',
		'reason',
		'base_amount',
		'base_original',
		'rate_bp',
		'commission',
		'commission_paid',
		'clawback',
		'clawback_settled_amount',
		'clawback_settled_at',
		'clawback_ref',
		'currency',
		'approve_after',
		'paid_at',
		'payout_ref',
	);

	private const COMMISSION_INT    = array( 'id', 'order_id', 'affiliate_id', 'rate_bp' );
	private const COMMISSION_FORMAT = array(
		'order_id'     => '%d',
		'affiliate_id' => '%d',
		'rate_bp'      => '%d',
	);

	public static function register(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
	}

	public static function activate(): void {
		self::install();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/** WooCommerce jest aktywny (moduł bez niego się nie pokazuje). */
	public static function available(): bool {
		return class_exists( 'WooCommerce' );
	}

	public static function enabled(): bool {
		return self::available() && true === ( Settings::get()['affiliates']['enabled'] ?? false );
	}

	public static function affiliates_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aktio_affiliates';
	}

	public static function commissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aktio_affiliate_commissions';
	}

	public static function clicks_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aktio_affiliate_clicks';
	}

	/**
	 * Tworzy tabele (dbDelta) i zapisuje wersję schematu.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$collate = $wpdb->get_charset_collate();
		$aff     = self::affiliates_table();
		$com     = self::commissions_table();
		$clk     = self::clicks_table();

		dbDelta(
			"CREATE TABLE {$aff} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(32) NOT NULL,
			name varchar(120) NOT NULL,
			email varchar(100) NOT NULL DEFAULT '',
			rate_bp int(10) unsigned DEFAULT NULL,
			status varchar(10) NOT NULL DEFAULT 'active',
			external_id varchar(64) NOT NULL DEFAULT '',
			code_locked_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code)
		) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$com} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			source varchar(10) NOT NULL DEFAULT 'link',
			status varchar(16) NOT NULL DEFAULT 'pending',
			reason varchar(32) NOT NULL DEFAULT '',
			base_amount decimal(19,4) NOT NULL DEFAULT 0,
			base_original decimal(19,4) NOT NULL DEFAULT 0,
			rate_bp int(10) unsigned NOT NULL DEFAULT 0,
			commission decimal(19,4) NOT NULL DEFAULT 0,
			commission_paid decimal(19,4) NOT NULL DEFAULT 0,
			clawback decimal(19,4) NOT NULL DEFAULT 0,
			clawback_settled_amount decimal(19,4) NOT NULL DEFAULT 0,
			clawback_settled_at datetime DEFAULT NULL,
			clawback_ref varchar(100) NOT NULL DEFAULT '',
			currency varchar(10) NOT NULL DEFAULT '',
			approve_after datetime DEFAULT NULL,
			paid_at datetime DEFAULT NULL,
			payout_ref varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY affiliate_status (affiliate_id,status),
			KEY status_approve (status,approve_after)
		) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$clk} (
			day date NOT NULL,
			affiliate_id bigint(20) unsigned NOT NULL,
			`count` int(10) unsigned NOT NULL DEFAULT 0,
			UNIQUE KEY day_affiliate (day,affiliate_id)
		) {$collate};"
		);

		update_option( self::SCHEMA_OPTION, (string) self::SCHEMA, false );
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) < self::SCHEMA ) {
			self::install();
		}
	}

	/**
	 * Sprzątanie z uninstall.php. Cron i transienty znikają zawsze; tabele (historia rozliczeń)
	 * oraz opcja schematu tylko po zaznaczeniu osobnej opcji `delete_on_uninstall`, odczytanej
	 * z ustawień ZANIM uninstall.php skasuje opcję ustawień.
	 */
	public static function uninstall(): void {
		global $wpdb;
		$stored = get_option( Settings::OPTION, array() );
		$opt_in = is_array( $stored ) && isset( $stored['affiliates'] ) && is_array( $stored['affiliates'] ) && true === ( $stored['affiliates']['delete_on_uninstall'] ?? false );

		wp_clear_scheduled_hook( self::CRON );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aktio\\_ar\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aktio\\_ar\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $opt_in ) {
			foreach ( array( self::affiliates_table(), self::commissions_table(), self::clicks_table() ) as $table ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			delete_option( self::SCHEMA_OPTION );
		}
	}

	// ---------------------------------------------------------------- Partnerzy

	/**
	 * Dodaje (bez `id`) albo zmienia (`id`) partnera; w zmianie ustawia tylko podane pola.
	 * Zwraca id albo WP_Error (aktio_aff_invalid, aktio_aff_code_taken, aktio_aff_code_locked, aktio_aff_not_found).
	 *
	 * @return int|\WP_Error
	 */
	public static function save_affiliate( array $data ) {
		global $wpdb;
		$id       = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$existing = $id > 0 ? self::get_affiliate( $id ) : null;
		if ( $id > 0 && null === $existing ) {
			return new \WP_Error( 'aktio_aff_not_found', __( 'Affiliate not found.', 'aktio-connector' ) );
		}

		$fields   = array();
		$generate = false;

		if ( array_key_exists( 'name', $data ) || null === $existing ) {
			$name = is_string( $data['name'] ?? null ) ? trim( sanitize_text_field( $data['name'] ) ) : '';
			if ( '' === $name || self::strlen( $name ) > 120 ) {
				return self::invalid( __( 'The name is required and can have at most 120 characters.', 'aktio-connector' ) );
			}
			$fields['name'] = $name;
		}

		if ( array_key_exists( 'email', $data ) ) {
			$email = is_string( $data['email'] ) ? strtolower( trim( $data['email'] ) ) : null;
			if ( null === $email || ( '' !== $email && ( strlen( $email ) > 100 || ! is_email( $email ) ) ) ) {
				return self::invalid( __( 'The email address is not valid.', 'aktio-connector' ) );
			}
			$fields['email'] = $email;
		}

		if ( array_key_exists( 'rate_bp', $data ) ) {
			$rate = $data['rate_bp'];
			if ( null !== $rate && '' !== $rate ) {
				if ( ! is_numeric( $rate ) || (int) $rate != $rate || (int) $rate < 0 || (int) $rate > 10000 ) { // phpcs:ignore Universal.Operators.StrictComparisons
					return self::invalid( __( 'The rate must be a whole number of basis points from 0 to 10000.', 'aktio-connector' ) );
				}
				$rate = (int) $rate;
			} else {
				$rate = null;
			}
			$fields['rate_bp'] = $rate;
		}

		if ( array_key_exists( 'status', $data ) ) {
			if ( ! is_string( $data['status'] ) || ! in_array( $data['status'], self::STATUSES, true ) ) {
				return self::invalid( __( 'The status must be active or paused.', 'aktio-connector' ) );
			}
			$fields['status'] = $data['status'];
		}

		if ( array_key_exists( 'external_id', $data ) ) {
			$ext = is_string( $data['external_id'] ) ? sanitize_text_field( $data['external_id'] ) : null;
			if ( null === $ext || strlen( $ext ) > 64 ) {
				return self::invalid( __( 'The external ID must be text of at most 64 characters.', 'aktio-connector' ) );
			}
			$fields['external_id'] = $ext;
		}

		if ( array_key_exists( 'code', $data ) && '' !== $data['code'] && null !== $data['code'] ) {
			$code = is_string( $data['code'] ) ? strtolower( trim( $data['code'] ) ) : '';
			if ( 1 !== preg_match( '/^[a-z0-9-]{3,32}\z/', $code ) ) {
				return self::invalid( __( 'The code must have 3 to 32 characters: lowercase letters, digits and hyphens.', 'aktio-connector' ) );
			}
			if ( null === $existing || $code !== $existing['code'] ) {
				if ( null !== $existing && self::code_locked( $id ) ) {
					return new \WP_Error( 'aktio_aff_code_locked', __( 'The code cannot be changed after the first click or commission.', 'aktio-connector' ) );
				}
				if ( self::code_exists( $code, $id ) ) {
					return new \WP_Error( 'aktio_aff_code_taken', __( 'This code is already used by another affiliate.', 'aktio-connector' ) );
				}
				$fields['code'] = $code;
			}
		} elseif ( null === $existing ) {
			$generate = true;
		}

		$now = current_time( 'mysql', true );
		if ( null === $existing ) {
			$fields += array(
				'email'       => '',
				'rate_bp'     => null,
				'status'      => 'active',
				'external_id' => '',
				'created_at'  => $now,
				'updated_at'  => $now,
			);
			// Kod generowany: przy wyścigu (duplikat między generate_code() a insertem) bierzemy kolejny sufiks.
			for ( $attempt = 0; $attempt < 10; $attempt++ ) {
				if ( $generate ) {
					$fields['code'] = self::generate_code( $fields['name'], $attempt );
				}
				$wpdb->suppress_errors( true );
				$ok = $wpdb->insert( self::affiliates_table(), $fields ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->suppress_errors( false );
				if ( false !== $ok ) {
					return (int) $wpdb->insert_id;
				}
				if ( ! self::is_duplicate_error() ) {
					return self::save_failed();
				}
				if ( ! $generate ) {
					return new \WP_Error( 'aktio_aff_code_taken', __( 'This code is already used by another affiliate.', 'aktio-connector' ) );
				}
			}
			return self::save_failed();
		}

		$fields['updated_at'] = $now;
		$wpdb->suppress_errors( true );
		$ok = $wpdb->update( self::affiliates_table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( false );
		if ( false === $ok ) {
			return self::is_duplicate_error()
				? new \WP_Error( 'aktio_aff_code_taken', __( 'This code is already used by another affiliate.', 'aktio-connector' ) )
				: self::save_failed();
		}
		return $id;
	}

	public static function get_affiliate( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::affiliates_table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? self::affiliate_row( $row ) : null;
	}

	public static function get_by_code( string $code ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::affiliates_table() . ' WHERE code = %s', strtolower( trim( $code ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? self::affiliate_row( $row ) : null;
	}

	/** Wiersze partnerów (sumy dochodzą w osobnym zadaniu). */
	public static function list_affiliates(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::affiliates_table() . ' ORDER BY name ASC, id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'affiliate_row' ), is_array( $rows ) ? $rows : array() );
	}

	private static function affiliate_row( array $row ): array {
		$row['id']      = (int) $row['id'];
		$row['rate_bp'] = null === $row['rate_bp'] ? null : (int) $row['rate_bp'];
		return $row;
	}

	private static function save_failed(): \WP_Error {
		return new \WP_Error( 'aktio_aff_save_failed', __( 'The affiliate could not be saved.', 'aktio-connector' ) );
	}

	/** Ostatni błąd $wpdb to naruszenie klucza unikalnego (MySQL 1062). */
	private static function is_duplicate_error(): bool {
		global $wpdb;
		return false !== stripos( (string) $wpdb->last_error, 'Duplicate entry' );
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'aktio_aff_invalid', $message );
	}

	private static function strlen( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private static function code_exists( string $code, int $except_id ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::affiliates_table() . ' WHERE code = %s AND id <> %d', $code, $except_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Kod jest niezmienny od pierwszego kliknięcia lub prowizji. Znacznik jest trwały w wierszu partnera,
	 * bo kliknięcia są usuwane po 400 dniach.
	 */
	private static function code_locked( int $id ): bool {
		global $wpdb;
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT code_locked_at FROM ' . self::affiliates_table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
		return null !== $locked;
	}

	private static function lock_code( int $affiliate_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::affiliates_table() . ' SET code_locked_at = %s WHERE id = %d AND code_locked_at IS NULL', current_time( 'mysql', true ), $affiliate_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Kod z nazwy ([a-z0-9-], 3–32 znaki), z numerem przy kolizji (sufiks skraca bazę, więc nigdy >32).
	 * $skip pomija tyle pierwszych kandydatów (ponowna próba po wyścigu).
	 */
	private static function generate_code( string $name, int $skip = 0 ): string {
		$base = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $name ) ) ), '-' );
		if ( strlen( $base ) < 3 ) {
			$base = 'partner';
		}
		for ( $n = 1; ; $n++ ) {
			$suffix    = 1 === $n ? '' : '-' . $n;
			$code      = trim( substr( $base, 0, 32 - strlen( $suffix ) ), '-' ) . $suffix;
			if ( $n > $skip && ! self::code_exists( $code, 0 ) ) {
				return $code;
			}
		}
	}

	// ------------------------------------------------------------------- Kupony

	/**
	 * Przypisuje kupon do partnera (meta `_aktio_affiliate_id`). Wstrzymany partner nie dostaje nowych przypisań.
	 *
	 * @return true|\WP_Error
	 */
	public static function assign_coupon( int $affiliate_id, string $coupon_code ) {
		$affiliate = self::get_affiliate( $affiliate_id );
		if ( null === $affiliate ) {
			return new \WP_Error( 'aktio_aff_not_found', __( 'Affiliate not found.', 'aktio-connector' ) );
		}
		if ( 'active' !== $affiliate['status'] ) {
			return new \WP_Error( 'aktio_aff_paused', __( 'A paused affiliate cannot be assigned new coupons.', 'aktio-connector' ) );
		}
		$coupon = self::coupon( $coupon_code );
		if ( null === $coupon ) {
			return new \WP_Error( 'aktio_aff_coupon_not_found', __( 'Coupon not found.', 'aktio-connector' ) );
		}
		$coupon->update_meta_data( self::COUPON_META, $affiliate_id );
		$coupon->save();
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function unassign_coupon( string $coupon_code ) {
		$coupon = self::coupon( $coupon_code );
		if ( null === $coupon ) {
			return new \WP_Error( 'aktio_aff_coupon_not_found', __( 'Coupon not found.', 'aktio-connector' ) );
		}
		$coupon->delete_meta_data( self::COUPON_META );
		$coupon->save();
		return true;
	}

	private static function coupon( string $code ): ?\WC_Coupon {
		if ( ! self::available() || '' === trim( $code ) ) {
			return null;
		}
		$id = wc_get_coupon_id_by_code( wc_format_coupon_code( $code ) );
		return $id > 0 ? new \WC_Coupon( $id ) : null;
	}

	// ---------------------------------------------------------------- Kliknięcia

	/** Zwiększa dzienny licznik kliknięć partnera (bez IP i identyfikatorów). */
	public static function add_click( int $affiliate_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::clicks_table() . ' (day, affiliate_id, `count`) VALUES (%s, %d, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1', gmdate( 'Y-m-d' ), $affiliate_id ) ); // phpcs:ignore WordPress.DB
		self::lock_code( $affiliate_id );
	}

	/** Usuwa dzienne liczniki starsze niż $before_day (Y-m-d). Blokada kodu jest w wierszu partnera, więc zostaje. */
	public static function purge_clicks( string $before_day ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::clicks_table() . ' WHERE day < %s', $before_day ) ); // phpcs:ignore WordPress.DB
	}

	// ------------------------------------------------------------------ Prowizje

	/**
	 * Wstawia wiersz prowizji; 0, gdy dla zamówienia już istnieje (unikalny `order_id`).
	 */
	public static function insert_commission( array $row ): int {
		global $wpdb;
		$order_id = (int) ( $row['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return 0;
		}
		$now  = current_time( 'mysql', true );
		$data = array_merge(
			array(
				'source'              => 'link',
				'status'              => 'pending',
				'reason'              => '',
				'base_amount'         => '0',
				'base_original'       => '0',
				'rate_bp'             => 0,
				'commission'          => '0',
				'commission_paid'     => '0',
				'clawback'            => '0',
				'clawback_settled_amount' => '0',
				'clawback_settled_at' => null,
				'clawback_ref'        => '',
				'currency'            => '',
				'approve_after'       => null,
				'paid_at'             => null,
				'payout_ref'          => '',
			),
			array_intersect_key( $row, array_flip( self::COMMISSION_COLUMNS ) )
		);
		$data['order_id']     = $order_id;
		$data['affiliate_id'] = (int) ( $row['affiliate_id'] ?? 0 );
		$data['created_at']   = $now;
		$data['updated_at']   = $now;

		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( self::commissions_table(), $data, self::formats( $data ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( false );
		if ( false === $ok ) {
			if ( ! self::is_duplicate_error() ) {
				// Bez danych osobowych: tylko id zamówienia i komunikat bazy.
				error_log( 'Aktio Connector affiliates: commission insert failed for order ' . $order_id . ': ' . substr( (string) $wpdb->last_error, 0, 200 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return 0;
		}
		self::lock_code( $data['affiliate_id'] );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Zmienia wskazane pola wiersza (id, order_id i created_at są niezmienne). False tylko przy błędzie zapisu;
	 * zapis bez zmiany wartości to sukces. Do zmiany stanu służy update_commission_if_status().
	 */
	public static function update_commission( int $id, array $fields ): bool {
		return false !== self::write_commission( $id, $fields, array() );
	}

	/**
	 * Bramka stanu: zapisuje tylko, gdy wiersz ma nadal oczekiwany status (UPDATE … WHERE id = ? AND status = ?),
	 * więc wartość odczytana wcześniej nie nadpisze równoległej zmiany. True, gdy wiersz został zmieniony.
	 */
	public static function update_commission_if_status( int $id, string $expected_status, array $fields ): bool {
		$changed = self::write_commission( $id, $fields, array( 'status' => $expected_status ) );
		return is_int( $changed ) && $changed > 0;
	}

	/**
	 * Liczba zmienionych wierszy albo false przy błędzie zapisu (log bez wartości pól ani komunikatu bazy).
	 *
	 * @return int|false
	 */
	private static function write_commission( int $id, array $fields, array $where ) {
		global $wpdb;
		unset( $fields['order_id'] );
		$data = array_intersect_key( $fields, array_flip( self::COMMISSION_COLUMNS ) );
		if ( array() === $data ) {
			return 0;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$where              = array( 'id' => $id ) + $where;
		$suppressed         = $wpdb->suppress_errors( true );
		$changed            = $wpdb->update( self::commissions_table(), $data, $where, self::formats( $data ), array_merge( array( '%d' ), array_fill( 0, count( $where ) - 1, '%s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $suppressed );
		if ( false === $changed ) {
			error_log( 'Aktio Connector affiliates: commission update failed for row ' . $id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $changed;
	}

	public static function commission_for_order( int $order_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::commissions_table() . ' WHERE order_id = %d', $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? self::commission_row( $row ) : null;
	}

	/** Wiersze po id (do 200), w kolejności rosnącego id; nieistniejące pomijane. */
	public static function get_commissions_by_ids( array $ids ): array {
		global $wpdb;
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $i ) => $i > 0 ) ) ), 0, self::MAX_PER_PAGE );
		if ( array() === $ids ) {
			return array();
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::commissions_table() . " WHERE id IN ({$in}) ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'commission_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Lista prowizji, najnowsze pierwsze. Filtry: status, affiliate_id, from / to (Y-m-d, po dacie utworzenia),
	 * page (od 1), per_page (1–200, domyślnie 50).
	 *
	 * @return array{items:array,total:int}
	 */
	public static function query_commissions( array $filters ): array {
		global $wpdb;
		$where = array();
		$args  = array();
		if ( isset( $filters['status'] ) && is_string( $filters['status'] ) && '' !== $filters['status'] ) {
			$where[] = 'status = %s';
			$args[]  = $filters['status'];
		}
		if ( ! empty( $filters['affiliate_id'] ) ) {
			$where[] = 'affiliate_id = %d';
			$args[]  = (int) $filters['affiliate_id'];
		}
		if ( isset( $filters['from'] ) && is_string( $filters['from'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $filters['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}
		if ( isset( $filters['to'] ) && is_string( $filters['to'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}\z/', $filters['to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}
		$sql_where = array() === $where ? '' : ' WHERE ' . implode( ' AND ', $where );
		$table     = self::commissions_table();

		$count_sql = "SELECT COUNT(*) FROM {$table}{$sql_where}";
		$total     = (int) ( array() === $args ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) ); // phpcs:ignore WordPress.DB

		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $filters['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$sql      = "SELECT * FROM {$table}{$sql_where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB

		return array(
			'items' => array_map( array( __CLASS__, 'commission_row' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	// -------------------------------------------------------------------- Sumy

	/**
	 * Sumy prowizji wg partnera i waluty (grupowanie w SQL, waluty nigdy nie są sumowane razem):
	 * partner => waluta => {pending, approved, paid, clawback_balance}, a pod kluczem 0 sumy wszystkich partnerów.
	 * `paid` to wypłacone kwoty, `clawback_balance` to saldo potrąceń wierszy `paid`. Do tego liczba zamówień partnera.
	 *
	 * @return array{totals:array<int,array<string,array<string,string>>>,orders:array<int,int>}
	 */
	public static function commission_totals(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT affiliate_id, currency, status, COUNT(*) AS n, SUM(commission) AS commission, SUM(commission_paid) AS paid, SUM(clawback - clawback_settled_amount) AS balance FROM ' . self::commissions_table() . ' GROUP BY affiliate_id, currency, status', ARRAY_A ); // phpcs:ignore WordPress.DB
		$sums   = array();
		$orders = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id  = (int) $row['affiliate_id'];
			$cur = (string) $row['currency'];
			foreach ( array( $id, 0 ) as $key ) {
				if ( ! isset( $sums[ $key ][ $cur ] ) ) {
					$sums[ $key ][ $cur ] = array( 'pending' => 0.0, 'approved' => 0.0, 'paid' => 0.0, 'clawback_balance' => 0.0 );
				}
				if ( 'pending' === $row['status'] || 'approved' === $row['status'] ) {
					$sums[ $key ][ $cur ][ $row['status'] ] += (float) $row['commission'];
				} elseif ( 'paid' === $row['status'] ) {
					$sums[ $key ][ $cur ]['paid']             += (float) $row['paid'];
					$sums[ $key ][ $cur ]['clawback_balance'] += (float) $row['balance'];
				}
			}
			$orders[ $id ] = ( $orders[ $id ] ?? 0 ) + (int) $row['n'];
		}
		foreach ( $sums as $key => $by_currency ) {
			foreach ( $by_currency as $cur => $values ) {
				$sums[ $key ][ $cur ] = array_map( array( Commissions::class, 'money' ), $values );
			}
		}
		return array(
			'totals' => $sums,
			'orders' => $orders,
		);
	}

	/** Kliknięcia z zachowanego okresu na partnera. */
	public static function click_totals(): array {
		global $wpdb;
		$rows   = $wpdb->get_results( 'SELECT affiliate_id, SUM(`count`) AS n FROM ' . self::clicks_table() . ' GROUP BY affiliate_id', ARRAY_A ); // phpcs:ignore WordPress.DB
		$totals = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$totals[ (int) $row['affiliate_id'] ] = (int) $row['n'];
		}
		return $totals;
	}

	/**
	 * Seria dzienna (UTC) z ostatnich $days dni (1–400), rosnąco: kliknięcia, zamówienia (wszystkie wiersze utworzone
	 * tego dnia) i prowizje bez odrzuconych, osobno dla każdej waluty.
	 *
	 * @return array<int,array{day:string,clicks:int,orders:int,commissions:array<string,string>}>
	 */
	public static function report_series( int $days ): array {
		global $wpdb;
		$days   = max( 1, min( 400, $days ) );
		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$series[ gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ) ] = array(
				'day'         => gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS ),
				'clicks'      => 0,
				'orders'      => 0,
				'commissions' => array(),
			);
		}
		$from   = (string) array_key_first( $series );
		$clicks = $wpdb->get_results( $wpdb->prepare( 'SELECT day, SUM(`count`) AS n FROM ' . self::clicks_table() . ' WHERE day >= %s GROUP BY day', $from ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $clicks ) ? $clicks : array() as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ]['clicks'] = (int) $row['n'];
			}
		}
		$orders = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS day, currency, COUNT(*) AS n, SUM(CASE WHEN status <> 'rejected' THEN commission ELSE 0 END) AS commission FROM " . self::commissions_table() . ' WHERE created_at >= %s GROUP BY DATE(created_at), currency', $from . ' 00:00:00' ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( is_array( $orders ) ? $orders : array() as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ]['orders']                              += (int) $row['n'];
				$series[ $row['day'] ]['commissions'][ (string) $row['currency'] ] = Commissions::money( (float) $row['commission'] );
			}
		}
		return array_values( $series );
	}

	/** Wiersze `pending` z terminem karencji do $now (GMT), po id większym niż $after_id, rosnąco. */
	public static function due_commissions( string $now, int $after_id, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::commissions_table() . " WHERE status = 'pending' AND approve_after IS NOT NULL AND approve_after <= %s AND id > %d ORDER BY id ASC LIMIT %d", $now, $after_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'commission_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Wiersze, które mogły minąć hooki statusów: `pending` bez terminu karencji i `rejected` z powodu `order_status`.
	 * Po id większym niż $after_id, rosnąco.
	 */
	public static function commissions_to_sync( int $after_id, int $limit ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::commissions_table() . " WHERE id > %d AND ( ( status = 'pending' AND approve_after IS NULL ) OR ( status = 'rejected' AND reason = 'order_status' ) ) ORDER BY id ASC LIMIT %d", $after_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( __CLASS__, 'commission_row' ), is_array( $rows ) ? $rows : array() );
	}

	private static function commission_row( array $row ): array {
		foreach ( self::COMMISSION_INT as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		return $row;
	}

	/** Formaty kolumn dla $wpdb: liczby całkowite jako %d, reszta (też kwoty DECIMAL) jako %s. */
	private static function formats( array $data ): array {
		$formats = array();
		foreach ( array_keys( $data ) as $key ) {
			$formats[] = self::COMMISSION_FORMAT[ $key ] ?? '%s';
		}
		return $formats;
	}
}
