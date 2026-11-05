<?php
/**
 * Moduł partnerski: trasy REST aktio/v1/affiliates (uprawnienie manage_woocommerce, bez parametrów w ścieżce).
 * Działają także przy wyłączonym module, bo rozliczanie istniejących prowizji trwa. Trasa publiczna `ref` jest w Tracker.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Affiliates;

use AktioConnector\Rest;
use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class RestApi {

	const MAX_PER_PAGE = 100;

	/** Status HTTP kodów błędów modułu; reszta aktio_aff_* to 400. */
	private const STATUS = array(
		'aktio_aff_not_found'   => 404,
		'aktio_aff_code_taken'  => 409,
		'aktio_aff_code_locked' => 409,
		'aktio_aff_save_failed' => 500,
	);

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		$permission = Rest::permission( 'manage_woocommerce' );
		$routes     = array(
			'/affiliates'             => array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_affiliates' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_affiliate' ),
					'permission_callback' => $permission,
				),
			),
			'/affiliates/commissions' => array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_commissions' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'act' ),
					'permission_callback' => $permission,
				),
			),
			'/affiliates/link'        => array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'link' ),
					'permission_callback' => $permission,
				),
			),
			'/affiliates/report'      => array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'report' ),
					'permission_callback' => $permission,
				),
			),
			'/affiliates/settings'    => array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'settings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'settings_update' ),
					'permission_callback' => $permission,
				),
			),
		);
		foreach ( $routes as $path => $handlers ) {
			register_rest_route( Rest::NAMESPACE, $path, $handlers );
		}
	}

	// ----------------------------------------------------------------------- Partnerzy

	/** GET affiliates: partnerzy z linkiem, sumami wg waluty, kliknięciami i liczbą zamówień; `totals` = wszyscy razem. */
	public static function list_affiliates(): \WP_REST_Response {
		$sums   = Store::commission_totals();
		$clicks = Store::click_totals();
		$items  = array();
		foreach ( Store::list_affiliates() as $row ) {
			$items[] = self::present( $row ) + array(
				'totals' => $sums['totals'][ $row['id'] ] ?? array(),
				'clicks' => $clicks[ $row['id'] ] ?? 0,
				'orders' => $sums['orders'][ $row['id'] ] ?? 0,
			);
		}
		return rest_ensure_response(
			array(
				'items'   => $items,
				'totals'  => $sums['totals'][0] ?? array(),
				'enabled' => Store::enabled(),
			)
		);
	}

	/**
	 * POST affiliates: dodanie lub zmiana (`id`), plus `coupon_add` / `coupon_remove` (kod albo lista kodów).
	 * Kupony są sprawdzane przed zapisem partnera, żeby błędny kod nie zostawił częściowej zmiany.
	 */
	public static function save_affiliate( \WP_REST_Request $request ) {
		$body = self::body( $request );
		$id   = 0;
		if ( array_key_exists( 'id', $body ) ) {
			$id = self::positive_int( $body['id'] );
			if ( null === $id ) {
				return self::invalid( __( 'The affiliate ID must be a positive whole number.', 'aktio-connector' ) );
			}
			$body['id'] = $id;
		}
		$existing = $id > 0 ? Store::get_affiliate( $id ) : null;
		if ( $id > 0 && null === $existing ) {
			return self::fail( new \WP_Error( 'aktio_aff_not_found', __( 'Affiliate not found.', 'aktio-connector' ) ) );
		}
		$add = self::codes( $body['coupon_add'] ?? array() );
		$rem = self::codes( $body['coupon_remove'] ?? array() );
		if ( null === $add || null === $rem ) {
			return self::invalid( __( 'Coupon codes must be text or a list of text.', 'aktio-connector' ) );
		}
		$error = self::check_coupons( $id, $existing, $body, $add, $rem );
		if ( null !== $error ) {
			return self::fail( $error );
		}

		$saved = Store::save_affiliate( array_intersect_key( $body, array_flip( array( 'id', 'name', 'email', 'code', 'rate_bp', 'status', 'external_id' ) ) ) );
		if ( is_wp_error( $saved ) ) {
			return self::fail( $saved );
		}
		foreach ( $rem as $code ) {
			$done = Store::unassign_coupon( $code );
			if ( is_wp_error( $done ) ) {
				return self::fail( $done );
			}
		}
		foreach ( $add as $code ) {
			$done = Store::assign_coupon( $saved, $code );
			if ( is_wp_error( $done ) ) {
				return self::fail( $done );
			}
		}
		return rest_ensure_response( array( 'affiliate' => self::present( (array) Store::get_affiliate( $saved ) ) ) );
	}

	/**
	 * Kupony sprawdzane przed zapisem partnera, żeby błąd nie zostawił częściowej zmiany: kupon musi istnieć,
	 * zdjąć wolno tylko kupon tego partnera, przypisać wolno kupon wolny albo już jego, a wstrzymany partner
	 * (także po tej zmianie) nie dostaje kuponów.
	 */
	private static function check_coupons( int $id, ?array $existing, array $body, array $add, array $rem ): ?\WP_Error {
		foreach ( array_merge( $add, $rem ) as $code ) {
			if ( null === Store::coupon_owner( $code ) ) {
				return new \WP_Error( 'aktio_aff_coupon_not_found', __( 'Coupon not found.', 'aktio-connector' ) );
			}
		}
		foreach ( $rem as $code ) {
			if ( $id <= 0 || Store::coupon_owner( $code ) !== $id ) {
				return new \WP_Error( 'aktio_aff_invalid', __( 'Only a coupon assigned to this affiliate can be removed.', 'aktio-connector' ) );
			}
		}
		foreach ( $add as $code ) {
			$taken = Store::coupon_taken_error( $code, $id );
			if ( null !== $taken ) {
				return $taken;
			}
		}
		$status = is_string( $body['status'] ?? null ) ? $body['status'] : ( $existing['status'] ?? 'active' );
		if ( array() !== $add && 'active' !== $status ) {
			return new \WP_Error( 'aktio_aff_paused', __( 'A paused affiliate cannot be assigned new coupons.', 'aktio-connector' ) );
		}
		return null;
	}

	/** GET affiliates/link: `affiliate_id`, `url`. */
	public static function link( \WP_REST_Request $request ) {
		$id = self::positive_int( $request->get_param( 'affiliate_id' ) );
		if ( null === $id ) {
			return self::invalid( __( 'The affiliate ID must be a positive whole number.', 'aktio-connector' ) );
		}
		$affiliate = Store::get_affiliate( $id );
		if ( null === $affiliate ) {
			return self::fail( new \WP_Error( 'aktio_aff_not_found', __( 'Affiliate not found.', 'aktio-connector' ) ) );
		}
		$url  = $request->get_param( 'url' );
		$link = Tracker::link_for( is_string( $url ) ? $url : '', $affiliate['code'] );
		return is_wp_error( $link ) ? self::fail( $link ) : rest_ensure_response( array( 'link' => $link ) );
	}

	// ----------------------------------------------------------------------- Prowizje

	/** GET affiliates/commissions: status, affiliate_id, from, to (Y-m-d), page, per_page (do 100). Zły filtr to błąd, nie szerszy wynik. */
	public static function list_commissions( \WP_REST_Request $request ) {
		$filters = array();
		$status  = $request->get_param( 'status' );
		if ( null !== $status && '' !== $status ) {
			if ( ! is_string( $status ) || ! in_array( $status, Commissions::STATUSES, true ) ) {
				return self::invalid( __( 'The status filter is not valid.', 'aktio-connector' ) );
			}
			$filters['status'] = $status;
		}
		$affiliate = $request->get_param( 'affiliate_id' );
		if ( null !== $affiliate && '' !== $affiliate ) {
			$filters['affiliate_id'] = self::positive_int( $affiliate );
			if ( null === $filters['affiliate_id'] ) {
				return self::invalid( __( 'The affiliate ID must be a positive whole number.', 'aktio-connector' ) );
			}
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			$date = $request->get_param( $key );
			if ( null !== $date && '' !== $date ) {
				if ( ! self::is_date( $date ) ) {
					return self::invalid( __( 'Dates must be real dates in the YYYY-MM-DD format.', 'aktio-connector' ) );
				}
				$filters[ $key ] = $date;
			}
		}
		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 50 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$result   = Store::query_commissions( $filters + array( 'page' => $page, 'per_page' => $per_page ) );
		foreach ( $result['items'] as &$item ) {
			$item['clawback_balance'] = Commissions::clawback_balance( $item );
		}
		unset( $item );
		return rest_ensure_response(
			array(
				'items'    => $result['items'],
				'total'    => $result['total'],
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/** POST affiliates/commissions: `action`, `ids` (do 200), `payout_ref` (paid) albo `clawback_ref` (settle_clawback). */
	public static function act( \WP_REST_Request $request ) {
		$body   = self::body( $request );
		$action = $body['action'] ?? null;
		if ( ! is_string( $action ) || ! in_array( $action, Commissions::ACTIONS, true ) ) {
			return self::invalid( __( 'The action must be approve, reject, paid or settle_clawback.', 'aktio-connector' ) );
		}
		$ids = $body['ids'] ?? null;
		if ( ! is_array( $ids ) || array() === $ids ) {
			return self::invalid( __( 'Provide a list of commission IDs.', 'aktio-connector' ) );
		}
		$ref = '';
		if ( 'paid' === $action || 'settle_clawback' === $action ) {
			$key = 'paid' === $action ? 'payout_ref' : 'clawback_ref';
			$ref = is_string( $body[ $key ] ?? null ) ? Commissions::clean_ref( $body[ $key ] ) : null;
			if ( null === $ref ) {
				return self::invalid( __( 'The reference is required and can have at most 100 characters.', 'aktio-connector' ) );
			}
		}
		$result = Commissions::apply( $action, $ids, $ref );
		return is_wp_error( $result ) ? self::fail( $result ) : rest_ensure_response( $result );
	}

	// ------------------------------------------------------------------ Raport, ustawienia

	/** GET affiliates/report?days=30: `days` jest ograniczone do 1–400. */
	public static function report( \WP_REST_Request $request ): \WP_REST_Response {
		$days = max( 1, min( 400, (int) ( $request->get_param( 'days' ) ?? 30 ) ) );
		return rest_ensure_response(
			array(
				'days'   => $days,
				'series' => Store::report_series( $days ),
			)
		);
	}

	public static function settings(): \WP_REST_Response {
		return rest_ensure_response( Settings::get()['affiliates'] );
	}

	/** POST affiliates/settings: pola sekcji `affiliates` w ciele; walidacja Settings, nic nie jest poprawiane po cichu. */
	public static function settings_update( \WP_REST_Request $request ) {
		$body   = self::body( $request );
		$errors = Settings::partial_errors( array( 'affiliates' => $body ) );
		if ( array() !== $errors ) {
			$mode    = is_string( $body['consent_mode'] ?? null ) ? $body['consent_mode'] : (string) Settings::get()['affiliates']['consent_mode'];
			$consent = isset( $errors['affiliates.consent_mode'] ) && true === ( $body['enabled'] ?? null ) && '' === $mode;
			return new \WP_Error(
				$consent ? 'aktio_aff_consent_required' : 'aktio_aff_invalid',
				(string) reset( $errors ),
				array(
					'status' => 400,
					'field'  => (string) key( $errors ),
					'errors' => $errors,
				)
			);
		}
		return rest_ensure_response( Settings::update( array( 'affiliates' => $body ) )['affiliates'] );
	}

	// ------------------------------------------------------------------ Pomocnicze

	/** Wiersz partnera z gotowym linkiem do strony głównej (z bieżącym parametrem). */
	private static function present( array $row ): array {
		$link = Tracker::link_for( home_url( '/' ), (string) ( $row['code'] ?? '' ) );
		return $row + array( 'link' => is_wp_error( $link ) ? '' : $link );
	}

	/** Dodatnia liczba całkowita z int albo tekstu z samych cyfr; null dla wszystkiego innego (bez rzutowania). */
	private static function positive_int( $value ): ?int {
		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]{0,17}\z/', $value ) ) {
			return (int) $value;
		}
		return is_int( $value ) && $value > 0 ? $value : null;
	}

	/** Prawdziwa data Y-m-d (checkdate), nie tylko ciąg w tym kształcie. */
	private static function is_date( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	private static function body( \WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}

	/** Lista kodów z tekstu albo listy tekstów; null przy innym typie. */
	private static function codes( $value ): ?array {
		$value = is_string( $value ) ? array( $value ) : $value;
		if ( ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( $value as $code ) {
			if ( ! is_string( $code ) ) {
				return null;
			}
			if ( '' !== trim( $code ) ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'aktio_aff_invalid', $message, array( 'status' => 400 ) );
	}

	/** Dodaje status HTTP do błędu modułu. */
	private static function fail( \WP_Error $error ): \WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
			$error->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => self::STATUS[ $error->get_error_code() ] ?? 400 ) ) );
		}
		return $error;
	}
}
