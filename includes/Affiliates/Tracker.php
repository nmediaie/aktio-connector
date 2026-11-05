<?php
/**
 * Moduł partnerski: śledzenie linku. Skrypt na froncie wysyła `POST aktio/v1/ref`, trasa zlicza kliknięcie
 * (bez IP i identyfikatorów w bazie) i ustawia podpisane cookie `aktio_ref` nagłówkiem serwera.
 * Bez wyjścia sieciowego.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Affiliates;

use AktioConnector\Metrics;
use AktioConnector\Rest;
use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class Tracker {

	const COOKIE     = 'aktio_ref';
	const RATE_LIMIT = 300;
	const DEDUP_TTL  = 10 * MINUTE_IN_SECONDS;

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_route(): void {
		register_rest_route(
			Rest::NAMESPACE,
			'/ref',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/** Moduł włączony, a filtr (własny baner zgody) nie wyłącza śledzenia. */
	private static function can_track(): bool {
		return Store::enabled() && false !== apply_filters( 'aktio_connector_affiliate_can_track', true );
	}

	/**
	 * Skrypt linku: tylko front, defer, w stopce, gdy właściciel wybrał tryb zgody i filtr nie blokuje.
	 */
	public static function enqueue(): void {
		if ( is_admin() || ! self::can_track() ) {
			return;
		}
		$cfg  = Settings::get()['affiliates'];
		$mode = (string) ( $cfg['consent_mode'] ?? '' );
		if ( ! in_array( $mode, array( 'consent_api', 'self_managed' ), true ) ) {
			return;
		}
		wp_enqueue_script(
			'aktio-affiliate',
			plugins_url( 'assets/aktio-affiliate.js', AKTIO_CONNECTOR_FILE ),
			array(),
			AKTIO_CONNECTOR_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_localize_script(
			'aktio-affiliate',
			'aktioAffiliate',
			array(
				'url'   => rest_url( Rest::NAMESPACE . '/ref' ),
				'param' => (string) $cfg['param'],
				'mode'  => $mode,
			)
		);
	}

	/**
	 * POST aktio/v1/ref: `code`. Zawsze 204 (także dla nieznanego kodu), poza limitem 300/min (429).
	 * Cookie dostają tylko znany, aktywny kod i nie-bot; licznik rośnie raz na IP i kod w 10 min.
	 */
	public static function handle( \WP_REST_Request $request ) {
		$response = new \WP_REST_Response( null, 204 );
		if ( ! self::can_track() || self::is_bot() ) {
			return $response;
		}

		// Limit globalny (za Cloudflare IP bywa wspólne), liczony przed sprawdzeniem kodu, by ograniczyć zgadywanie.
		$bucket = Store::TRANSIENT . 'rl' . (int) floor( time() / MINUTE_IN_SECONDS );
		$hits   = (int) get_transient( $bucket ) + 1;
		set_transient( $bucket, $hits, 2 * MINUTE_IN_SECONDS );
		if ( $hits > self::RATE_LIMIT ) {
			return new \WP_Error( 'aktio_rate_limited', __( 'Too many requests.', 'aktio-connector' ), array( 'status' => 429 ) );
		}

		$code = $request->get_param( 'code' );
		if ( ! is_string( $code ) || ! preg_match( '/^[a-z0-9-]{3,32}$/', strtolower( trim( $code ) ) ) ) {
			return $response;
		}
		$code      = strtolower( trim( $code ) );
		$affiliate = Store::get_by_code( $code );
		if ( null === $affiliate || 'active' !== ( $affiliate['status'] ?? '' ) ) {
			return $response;
		}

		$visit = self::visit_key( $code );
		if ( false === get_transient( $visit ) ) {
			set_transient( $visit, 1, self::DEDUP_TTL );
			Store::add_click( (int) $affiliate['id'] );
		}

		$response->header( 'Set-Cookie', self::cookie_header( $code ) );
		return $response;
	}

	private static function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? substr( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tylko dopasowanie do listy.
		foreach ( Metrics::BOTS as $bot ) {
			if ( '' !== $ua && false !== stripos( $ua, $bot ) ) {
				return true;
			}
		}
		return false;
	}

	/** Ulotny HMAC(IP + kod) w transiencie na 10 min; sam IP nigdzie nie jest zapisywany. */
	private static function visit_key( string $code ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tylko wejście HMAC.
		return Store::TRANSIENT . substr( hash_hmac( 'sha256', $ip . '|' . $code, self::key() ), 0, 32 );
	}

	private static function key(): string {
		return wp_salt( 'auth' ) . 'aktio-ref';
	}

	private static function window_seconds(): int {
		$days = (int) ( Settings::get()['affiliates']['window_days'] ?? 30 );
		return max( 1, min( 365, $days ) ) * DAY_IN_SECONDS;
	}

	public static function sign( string $code, int $ts ): string {
		return hash_hmac( 'sha256', $code . '.' . $ts, self::key() );
	}

	/**
	 * Kod z wartości cookie albo null: zły format, podpis lub wiek ponad bieżące okno atrybucji.
	 */
	public static function verify( string $value ): ?string {
		$parts = explode( '.', $value );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		list( $code, $ts, $mac ) = $parts;
		if ( ! preg_match( '/^[a-z0-9-]{3,32}$/', $code ) || ! ctype_digit( $ts ) || strlen( $ts ) > 12 ) {
			return null;
		}
		$ts = (int) $ts;
		if ( ! hash_equals( self::sign( $code, $ts ), $mac ) ) {
			return null;
		}
		$age = time() - $ts;
		if ( $age < -HOUR_IN_SECONDS || $age > self::window_seconds() ) {
			return null;
		}
		return $code;
	}

	/** Kod z cookie bieżącego żądania (zweryfikowany) albo null. */
	public static function current_code(): ?string {
		if ( ! isset( $_COOKIE[ self::COOKIE ] ) || ! is_string( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		return self::verify( wp_unslash( $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- weryfikowane podpisem.
	}

	/** Ścieżka cookie: COOKIEPATH (instalacja w podkatalogu) albo `/`; filtr pozwala ją zawęzić. */
	public static function cookie_path(): string {
		$path = defined( 'COOKIEPATH' ) && is_string( COOKIEPATH ) && '' !== COOKIEPATH ? COOKIEPATH : '/';
		$path = apply_filters( 'aktio_connector_affiliate_cookie_path', $path );
		return is_string( $path ) && '' !== $path ? $path : '/';
	}

	private static function cookie_header( string $code ): string {
		$ts     = time();
		$max    = self::window_seconds();
		$value  = $code . '.' . $ts . '.' . self::sign( $code, $ts );
		$header = self::COOKIE . '=' . $value
			. '; Expires=' . gmdate( 'D, d M Y H:i:s', $ts + $max ) . ' GMT'
			. '; Max-Age=' . $max
			. '; Path=' . self::cookie_path();
		if ( defined( 'COOKIE_DOMAIN' ) && is_string( COOKIE_DOMAIN ) && '' !== COOKIE_DOMAIN ) {
			$header .= '; Domain=' . COOKIE_DOMAIN;
		}
		if ( is_ssl() ) {
			$header .= '; Secure';
		}
		return $header . '; HttpOnly; SameSite=Lax';
	}

	/**
	 * Link partnera do podanej strony sklepu: ten adres z parametrem partnerskim (istniejący parametr jest
	 * zastępowany, inne argumenty i kotwica zostają). Adres musi być http(s), bez danych logowania i ukośników
	 * odwrotnych, na hoście sklepu (bez uwzględniania wiodącego `www.`) i pod ścieżką `home_url`.
	 *
	 * @return string|\WP_Error aktio_aff_url_outside_shop albo aktio_aff_invalid (zły kod).
	 */
	public static function link_for( string $url, string $code ) {
		if ( 1 !== preg_match( '/^[a-z0-9-]{3,32}\z/', $code ) ) {
			return new \WP_Error( 'aktio_aff_invalid', __( 'The affiliate code is not valid.', 'aktio-connector' ) );
		}
		$outside = new \WP_Error( 'aktio_aff_url_outside_shop', __( 'The address must point to a page of this shop.', 'aktio-connector' ), array( 'status' => 400 ) );

		$url = trim( $url );
		if ( '' === $url || 1 === preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) {
			return $outside;
		}
		$parts = wp_parse_url( $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return $outside;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) || empty( $home['host'] ) ) {
			return $outside;
		}
		if ( self::bare_host( (string) $parts['host'] ) !== self::bare_host( (string) $home['host'] ) ) {
			return $outside;
		}
		// Port wynika z adresu tylko wtedy, gdy ktoś go podał (adres sklepu lub sprawdzany); wtedy muszą się zgadzać.
		if ( isset( $parts['port'] ) || isset( $home['port'] ) ) {
			$default = 'https' === $scheme ? 443 : 80;
			if ( (int) ( $parts['port'] ?? $default ) !== (int) ( $home['port'] ?? $default ) ) {
				return $outside;
			}
		}

		$path      = (string) ( $parts['path'] ?? '' );
		$home_path = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		if ( '' !== $home_path ) {
			if ( $path !== $home_path && 0 !== strpos( $path, $home_path . '/' ) ) {
				return $outside;
			}
			// Przeglądarka zdejmuje zakodowane kropki (%2e) i podwójnie zakodowane znaki przed wysłaniem żądania.
			$decoded = rawurldecode( $path );
			if ( 1 === preg_match( '/%[0-9a-f]{2}/i', $decoded ) || in_array( '..', explode( '/', $decoded ), true ) ) {
				return $outside;
			}
		}
		if ( 1 === preg_match( '/%(2f|5c)/i', $path ) ) {
			return $outside; // Zakodowany ukośnik lub ukośnik odwrotny zmienia strukturę ścieżki.
		}

		// Adres składamy ze zweryfikowanych części; zapytanie zostaje bajt w bajt poza zastępowanym parametrem.
		$param = (string) Settings::get()['affiliates']['param'];
		$args  = array();
		foreach ( explode( '&', (string) ( $parts['query'] ?? '' ) ) as $pair ) {
			if ( '' !== $pair && urldecode( explode( '=', $pair, 2 )[0] ) !== $param ) {
				$args[] = $pair;
			}
		}
		$args[] = rawurlencode( $param ) . '=' . $code;

		return $scheme . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
			. ( '' === $path ? '/' : $path )
			. '?' . implode( '&', $args )
			. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
	}

	private static function bare_host( string $host ): string {
		$host = strtolower( rtrim( $host, '.' ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** Usuwa wygasłe transienty tej trasy (bez zewnętrznego cache obiektów zostają w wp_options). */
	public static function purge_transients(): void {
		global $wpdb;
		$prefix  = '_transient_timeout_' . Store::TRANSIENT;
		$expired = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT 1000", $wpdb->esc_like( $prefix ) . '%', time() ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $expired as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_timeout_' ) ) );
		}
	}
}
