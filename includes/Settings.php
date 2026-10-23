<?php
/**
 * Ustawienia wtyczki: opcja aktio_connector_settings (company, bots, merchant).
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'aktio_connector_settings';

	const BOTS = array( 'GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended' );

	const TYPES = array( 'Organization', 'LocalBusiness' );

	const RETURN_FEES    = array( 'free', 'customer' );
	const RETURN_METHODS = array( 'mail', 'store', 'mail_or_store' );

	const BRAND_MAX = 100;

	/**
	 * Domyślne ustawienia (nazwa firmy z nazwy witryny, wszystkie boty dopuszczone).
	 */
	public static function defaults(): array {
		return array(
			'company'  => array(
				'name'    => (string) get_bloginfo( 'name' ),
				'type'    => 'Organization',
				'logo'    => '',
				'address' => array(
					'street'      => '',
					'city'        => '',
					'postal_code' => '',
					'country'     => '',
				),
				'phone'   => '',
				'email'   => '',
				'same_as' => array(),
			),
			'bots'     => array_fill_keys( self::BOTS, 'allow' ),
			'merchant' => self::merchant_defaults(),
		);
	}

	/**
	 * Domyślne pola sprzedawcy: deklaracje wobec Google są domyślnie wyłączone,
	 * kraj podpowiadamy z ustawień sklepu WooCommerce.
	 */
	private static function merchant_defaults(): array {
		$country = self::shop_country();
		return array(
			'default_brand' => '',
			'return_policy' => array(
				'enabled' => false,
				'days'    => 14,
				'fees'    => 'customer',
				'country' => $country,
				'method'  => 'mail',
			),
			'shipping'      => array(
				'enabled'       => false,
				'country'       => $country,
				'cost'          => '0.00',
				'handling_days' => array( 0, 1 ),
				'transit_days'  => array( 1, 3 ),
			),
		);
	}

	/**
	 * Kraj sklepu WooCommerce (część przed ':' w woocommerce_default_country) albo ''.
	 */
	private static function shop_country(): string {
		$raw  = get_option( 'woocommerce_default_country', '' );
		$code = is_string( $raw ) ? strtoupper( trim( explode( ':', $raw )[0] ) ) : '';
		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : '';
	}

	/**
	 * Zapisane ustawienia scalone z domyślnymi.
	 */
	public static function get(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = self::merge( $defaults, $stored );
		if ( '' === $settings['company']['name'] ) {
			$settings['company']['name'] = $defaults['company']['name'];
		}
		return $settings;
	}

	/**
	 * Nazwa firmy zapisana przez użytkownika ('' = brak, obowiązuje nazwa witryny).
	 */
	private static function stored_name(): string {
		$stored = get_option( self::OPTION, array() );
		$name   = is_array( $stored ) && isset( $stored['company']['name'] ) ? $stored['company']['name'] : '';
		return is_string( $name ) ? $name : '';
	}

	/**
	 * Częściowa aktualizacja: sanityzuje wejście, zapisuje i zwraca całość.
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$merged  = self::merge( $current, $partial );

		// Zapisujemy tylko to, co ustawił użytkownik: nazwa z witryny nie jest zamrażana.
		$to_store = $merged;
		if ( isset( $partial['company'] ) && is_array( $partial['company'] ) && array_key_exists( 'name', $partial['company'] ) ) {
			$to_store['company']['name'] = $merged['company']['name'];
		} else {
			$to_store['company']['name'] = self::stored_name();
		}
		update_option( self::OPTION, $to_store, true );

		if ( '' === $merged['company']['name'] ) {
			$merged['company']['name'] = (string) get_bloginfo( 'name' );
		}
		do_action( 'aktio_connector_settings_updated', $merged );
		return $merged;
	}

	/**
	 * Scala bazę z wejściem i sanityzuje wynik (wejście nigdy nie jest zaufane).
	 */
	private static function merge( array $base, array $input ): array {
		$company = $base['company'];
		if ( isset( $input['company'] ) && is_array( $input['company'] ) ) {
			$company = self::sanitize_company( $input['company'], $company );
		}
		$bots = $base['bots'];
		if ( isset( $input['bots'] ) && is_array( $input['bots'] ) ) {
			foreach ( self::BOTS as $bot ) {
				if ( isset( $input['bots'][ $bot ] ) ) {
					$bots[ $bot ] = 'block' === $input['bots'][ $bot ] ? 'block' : 'allow';
				}
			}
		}
		$merchant = $base['merchant'];
		if ( isset( $input['merchant'] ) && is_array( $input['merchant'] ) ) {
			$merchant = self::merchant_apply( $input['merchant'], $merchant )[0];
		}
		return array(
			'company'  => $company,
			'bots'     => $bots,
			'merchant' => $merchant,
		);
	}

	/**
	 * Błędy walidacji pól sprzedawcy w częściowej aktualizacji: ścieżka pola → komunikat.
	 * Pusta tablica = wejście poprawne (albo brak klucza `merchant`). Nic nie jest poprawiane
	 * po cichu — to dane deklarowane wobec Google i klientów.
	 */
	public static function merchant_errors( array $partial ): array {
		if ( ! isset( $partial['merchant'] ) ) {
			return array();
		}
		if ( ! self::is_object( $partial['merchant'] ) ) {
			return array( 'merchant' => self::field_message( 'object', 'merchant' ) );
		}
		return self::merchant_apply( $partial['merchant'], self::merchant_defaults() )[1];
	}

	/** Obiekt JSON po zdekodowaniu: tablica asocjacyjna (pusta też). */
	private static function is_object( $value ): bool {
		return is_array( $value ) && ( array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 ) );
	}

	private static function field_message( string $kind, string $field, ...$args ): string {
		switch ( $kind ) {
			case 'object':
				/* translators: %s: field path, e.g. merchant.shipping */
				return sprintf( __( '%s must be an object.', 'aktio-connector' ), $field );
			case 'bool':
				/* translators: %s: field path */
				return sprintf( __( '%s must be true or false.', 'aktio-connector' ), $field );
			case 'int':
				/* translators: 1: field path, 2: minimum, 3: maximum */
				return sprintf( __( '%1$s must be an integer from %2$d to %3$d.', 'aktio-connector' ), $field, $args[0], $args[1] );
			case 'enum':
				/* translators: 1: field path, 2: allowed values */
				return sprintf( __( '%1$s must be one of: %2$s.', 'aktio-connector' ), $field, implode( ', ', $args[0] ) );
			case 'country':
				/* translators: %s: field path */
				return sprintf( __( '%s must be a two-letter uppercase ISO country code (for example PL) or an empty string.', 'aktio-connector' ), $field );
			case 'cost':
				/* translators: %s: field path */
				return sprintf( __( '%s must be a non-negative decimal amount with at most 2 decimal places and up to 10 characters (for example 12.50).', 'aktio-connector' ), $field );
			case 'range':
				/* translators: 1: field path, 2: minimum, 3: maximum */
				return sprintf( __( '%1$s must be a pair [min, max] of integers from %2$d to %3$d, with min not greater than max.', 'aktio-connector' ), $field, $args[0], $args[1] );
			default:
				/* translators: 1: field path, 2: maximum length */
				return sprintf( __( '%1$s must be a string of at most %2$d characters.', 'aktio-connector' ), $field, $args[0] );
		}
	}

	/**
	 * Nakłada poprawne pola `merchant` z wejścia na $cur. Zwraca [wynik, błędy]:
	 * niepoprawne pola są pomijane (zostaje poprzednia wartość) i zgłaszane w błędach.
	 * Nieznane klucze są ignorowane (jak w company i bots).
	 */
	private static function merchant_apply( array $in, array $cur ): array {
		$out    = $cur;
		$errors = array();

		if ( array_key_exists( 'default_brand', $in ) ) {
			$brand = $in['default_brand'];
			if ( is_string( $brand ) && self::length( sanitize_text_field( $brand ) ) <= self::BRAND_MAX ) {
				$out['default_brand'] = sanitize_text_field( $brand );
			} else {
				$errors['merchant.default_brand'] = self::field_message( 'string', 'merchant.default_brand', self::BRAND_MAX );
			}
		}

		if ( array_key_exists( 'return_policy', $in ) ) {
			if ( ! self::is_object( $in['return_policy'] ) ) {
				$errors['merchant.return_policy'] = self::field_message( 'object', 'merchant.return_policy' );
			} else {
				$rp = $in['return_policy'];
				self::apply_bool( $rp, 'enabled', 'merchant.return_policy', $out['return_policy'], $errors );
				self::apply_int( $rp, 'days', 'merchant.return_policy', 0, 365, $out['return_policy'], $errors );
				self::apply_enum( $rp, 'fees', 'merchant.return_policy', self::RETURN_FEES, $out['return_policy'], $errors );
				self::apply_country( $rp, 'merchant.return_policy', $out['return_policy'], $errors );
				self::apply_enum( $rp, 'method', 'merchant.return_policy', self::RETURN_METHODS, $out['return_policy'], $errors );
			}
		}

		if ( array_key_exists( 'shipping', $in ) ) {
			if ( ! self::is_object( $in['shipping'] ) ) {
				$errors['merchant.shipping'] = self::field_message( 'object', 'merchant.shipping' );
			} else {
				$sh = $in['shipping'];
				self::apply_bool( $sh, 'enabled', 'merchant.shipping', $out['shipping'], $errors );
				self::apply_country( $sh, 'merchant.shipping', $out['shipping'], $errors );
				if ( array_key_exists( 'cost', $sh ) ) {
					$cost = $sh['cost'];
					if ( is_string( $cost ) && strlen( $cost ) <= 10 && 1 === preg_match( '/^\d+(\.\d{1,2})?$/', $cost ) ) {
						$out['shipping']['cost'] = $cost;
					} else {
						$errors['merchant.shipping.cost'] = self::field_message( 'cost', 'merchant.shipping.cost' );
					}
				}
				self::apply_range( $sh, 'handling_days', 'merchant.shipping', 30, $out['shipping'], $errors );
				self::apply_range( $sh, 'transit_days', 'merchant.shipping', 60, $out['shipping'], $errors );
			}
		}
		return array( $out, $errors );
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private static function apply_bool( array $in, string $key, string $path, array &$out, array &$errors ): void {
		if ( ! array_key_exists( $key, $in ) ) {
			return;
		}
		if ( is_bool( $in[ $key ] ) ) {
			$out[ $key ] = $in[ $key ];
		} else {
			$errors[ "$path.$key" ] = self::field_message( 'bool', "$path.$key" );
		}
	}

	private static function apply_int( array $in, string $key, string $path, int $min, int $max, array &$out, array &$errors ): void {
		if ( ! array_key_exists( $key, $in ) ) {
			return;
		}
		if ( is_int( $in[ $key ] ) && $in[ $key ] >= $min && $in[ $key ] <= $max ) {
			$out[ $key ] = $in[ $key ];
		} else {
			$errors[ "$path.$key" ] = self::field_message( 'int', "$path.$key", $min, $max );
		}
	}

	private static function apply_enum( array $in, string $key, string $path, array $allowed, array &$out, array &$errors ): void {
		if ( ! array_key_exists( $key, $in ) ) {
			return;
		}
		if ( is_string( $in[ $key ] ) && in_array( $in[ $key ], $allowed, true ) ) {
			$out[ $key ] = $in[ $key ];
		} else {
			$errors[ "$path.$key" ] = self::field_message( 'enum', "$path.$key", $allowed );
		}
	}

	/** Kraj: ISO 3166-1 alpha-2 wielkimi literami albo '' (brak = nie emitujemy). */
	private static function apply_country( array $in, string $path, array &$out, array &$errors ): void {
		if ( ! array_key_exists( 'country', $in ) ) {
			return;
		}
		if ( is_string( $in['country'] ) && ( '' === $in['country'] || 1 === preg_match( '/^[A-Z]{2}$/', $in['country'] ) ) ) {
			$out['country'] = $in['country'];
		} else {
			$errors[ "$path.country" ] = self::field_message( 'country', "$path.country" );
		}
	}

	/** Para [min, max] liczb całkowitych z zakresu 0–$limit, min ≤ max. */
	private static function apply_range( array $in, string $key, string $path, int $limit, array &$out, array &$errors ): void {
		if ( ! array_key_exists( $key, $in ) ) {
			return;
		}
		$pair = $in[ $key ];
		$ok   = is_array( $pair ) && 2 === count( $pair ) && array_keys( $pair ) === array( 0, 1 )
			&& is_int( $pair[0] ) && is_int( $pair[1] )
			&& $pair[0] >= 0 && $pair[1] <= $limit && $pair[0] <= $pair[1];
		if ( $ok ) {
			$out[ $key ] = array( $pair[0], $pair[1] );
		} else {
			$errors[ "$path.$key" ] = self::field_message( 'range', "$path.$key", 0, $limit );
		}
	}

	private static function text( $value ): string {
		$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200 ) : substr( $value, 0, 200 );
	}

	private static function sanitize_company( array $in, array $cur ): array {
		$out = $cur;
		if ( isset( $in['name'] ) ) {
			$out['name'] = self::text( $in['name'] );
		}
		if ( isset( $in['type'] ) ) {
			$out['type'] = in_array( $in['type'], self::TYPES, true ) ? $in['type'] : $cur['type'];
		}
		if ( isset( $in['logo'] ) ) {
			$out['logo'] = is_string( $in['logo'] ) ? esc_url_raw( $in['logo'] ) : '';
		}
		if ( isset( $in['address'] ) && is_array( $in['address'] ) ) {
			foreach ( array( 'street', 'city', 'postal_code', 'country' ) as $key ) {
				if ( isset( $in['address'][ $key ] ) ) {
					$out['address'][ $key ] = self::text( $in['address'][ $key ] );
				}
			}
		}
		if ( isset( $in['phone'] ) ) {
			$out['phone'] = self::text( $in['phone'] );
		}
		if ( isset( $in['email'] ) ) {
			$out['email'] = is_string( $in['email'] ) ? sanitize_email( $in['email'] ) : '';
		}
		if ( isset( $in['same_as'] ) ) {
			$urls = array();
			foreach ( is_array( $in['same_as'] ) ? $in['same_as'] : array() as $url ) {
				$url = is_string( $url ) ? esc_url_raw( $url ) : '';
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
			$out['same_as'] = array_slice( array_values( array_unique( $urls ) ), 0, 10 );
		}
		return $out;
	}
}
