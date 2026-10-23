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

	const WEEKDAYS = array( 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su' );

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
				'description'   => '',
				'founding_date' => '',
				'contact_point' => array(
					'telephone'    => '',
					'email'        => '',
					'contact_type' => 'customer service',
					'languages'    => array(),
				),
				'opening_hours' => array(),
				'geo'           => null,
				'area_served'   => '',
			),
			'bots'     => array_fill_keys( self::BOTS, 'allow' ),
			'merchant' => self::merchant_defaults(),
			'seo'      => array(
				'home_description' => '',
				'default_og_image' => '',
			),
			// Pusty język = język witryny (rozwiązywany przy odczycie, nie zamrażany).
			'content_language' => '',
			'indexnow'         => array(
				'enabled' => false,
				'key'     => '',
			),
			'verification'     => array(
				'bing'   => '',
				'google' => '',
			),
		);
	}

	/**
	 * Domyślne pola sprzedawcy: deklaracje wobec Google są domyślnie wyłączone.
	 * Kraj '' = „kraj sklepu WooCommerce” — rozwiązywany przy odczycie (get()), nie zamrażany w opcji.
	 */
	private static function merchant_defaults(): array {
		$country = '';
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
	public static function shop_country(): string {
		$raw  = get_option( 'woocommerce_default_country', '' );
		$code = is_string( $raw ) ? strtoupper( trim( explode( ':', $raw )[0] ) ) : '';
		return 1 === preg_match( '/^[A-Z]{2}\z/', $code ) ? $code : '';
	}

	/**
	 * Zapisane ustawienia scalone z domyślnymi; pusty kraj w polach sprzedawcy
	 * oznacza aktualny kraj sklepu WooCommerce (rozwiązywany przy każdym odczycie).
	 */
	public static function get(): array {
		return self::resolve( self::get_raw() );
	}

	/**
	 * Pusty kraj zwrotów/wysyłki → kraj sklepu (albo '' gdy sklep go nie ma);
	 * pusty język treści → język witryny w formacie BCP-47 (np. pl-PL).
	 */
	private static function resolve( array $settings ): array {
		if ( '' === $settings['content_language'] ) {
			$settings['content_language'] = str_replace( '_', '-', get_locale() );
		}
		$shop = self::shop_country();
		foreach ( array( 'return_policy', 'shipping' ) as $section ) {
			if ( '' === $settings['merchant'][ $section ]['country'] ) {
				$settings['merchant'][ $section ]['country'] = $shop;
			}
		}
		return $settings;
	}

	/**
	 * Surowe ustawienia (bez rozwiązania pustego kraju) — baza dla update().
	 */
	private static function get_raw(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = self::merge( $defaults, $stored );

		$settings['indexnow']['key'] = self::indexnow_key( $stored );
		if ( '' === $settings['company']['name'] ) {
			$settings['company']['name'] = $defaults['company']['name'];
		}
		return $settings;
	}

	/**
	 * Klucz IndexNow: zapisany (32 znaki hex) albo wygenerowany przy pierwszym odczycie i utrwalony.
	 * Tylko do odczytu z zewnątrz — nie przechodzi przez update().
	 */
	private static function indexnow_key( array $stored ): string {
		$key = $stored['indexnow']['key'] ?? '';
		if ( is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{32}\z/', $key ) ) {
			return $key;
		}
		$key = bin2hex( random_bytes( 16 ) );
		if ( ! isset( $stored['indexnow'] ) || ! is_array( $stored['indexnow'] ) ) {
			$stored['indexnow'] = array();
		}
		$stored['indexnow']['key'] = $key;
		update_option( self::OPTION, $stored, true );
		return $key;
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
		$current = self::get_raw();
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
		$merged = self::resolve( $merged );
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
			$company = self::company_extra_apply( $input['company'], $company )[0];
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
		$extra = self::extra_apply( $input, $base )[0];
		return array(
			'company'          => $company,
			'bots'             => $bots,
			'merchant'         => $merchant,
			'seo'              => $extra['seo'],
			'content_language' => $extra['content_language'],
			'indexnow'         => $extra['indexnow'],
			'verification'     => $extra['verification'],
		);
	}

	/**
	 * Błędy walidacji całej częściowej aktualizacji (sprzedawca, SEO, język, IndexNow,
	 * weryfikacja, nowe pola firmy): ścieżka pola → komunikat. Pusta tablica = wejście poprawne.
	 */
	public static function partial_errors( array $partial ): array {
		$errors = self::merchant_errors( $partial );
		$base   = self::get_raw();
		if ( isset( $partial['company'] ) ) {
			if ( ! self::is_object( $partial['company'] ) ) {
				$errors['company'] = self::field_message( 'object', 'company' );
			} else {
				$errors = array_merge( $errors, self::company_extra_apply( $partial['company'], $base['company'] )[1] );
			}
		}
		return array_merge( $errors, self::extra_apply( $partial, $base )[1] );
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
			case 'unknown':
				/* translators: %s: field path, e.g. merchant.retrun_policy */
				return sprintf( __( '%s is not a known field.', 'aktio-connector' ), $field );
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
			case 'readonly':
				/* translators: %s: field path */
				return sprintf( __( '%s is generated by the plugin and cannot be changed.', 'aktio-connector' ), $field );
			case 'format':
				/* translators: 1: field path, 2: description of the expected format */
				return sprintf( __( '%1$s must be %2$s.', 'aktio-connector' ), $field, $args[0] );
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
	 * niepoprawne pola i nieznane klucze (na każdym poziomie) są pomijane i zgłaszane
	 * w błędach — literówka w danych deklarowanych wobec Google nie może zniknąć po cichu.
	 * Marka domyślna przechodzi przez sanitize_text_field() (tagi usuwane, białe znaki przycinane).
	 */
	private static function merchant_apply( array $in, array $cur ): array {
		$out    = $cur;
		$errors = array();

		self::unknown_keys( $in, array( 'default_brand', 'return_policy', 'shipping' ), 'merchant', $errors );

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
				self::unknown_keys( $rp, array( 'enabled', 'days', 'fees', 'country', 'method' ), 'merchant.return_policy', $errors );
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
				self::unknown_keys( $sh, array( 'enabled', 'country', 'cost', 'handling_days', 'transit_days' ), 'merchant.shipping', $errors );
				self::apply_bool( $sh, 'enabled', 'merchant.shipping', $out['shipping'], $errors );
				self::apply_country( $sh, 'merchant.shipping', $out['shipping'], $errors );
				if ( array_key_exists( 'cost', $sh ) ) {
					$cost = $sh['cost'];
					if ( is_string( $cost ) && strlen( $cost ) <= 10 && 1 === preg_match( '/^\d+(\.\d{1,2})?\z/', $cost ) ) {
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

	/**
	 * Nakłada poprawne sekcje seo / content_language / indexnow / verification z wejścia na $base.
	 * Zwraca [ustawienia, błędy] — tak samo ściśle jak merchant_apply().
	 */
	private static function extra_apply( array $in, array $base ): array {
		$out    = $base;
		$errors = array();

		if ( array_key_exists( 'seo', $in ) ) {
			if ( ! self::is_object( $in['seo'] ) ) {
				$errors['seo'] = self::field_message( 'object', 'seo' );
			} else {
				$seo = $in['seo'];
				self::unknown_keys( $seo, array( 'home_description', 'default_og_image' ), 'seo', $errors );
				self::apply_string( $seo, 'home_description', 'seo', 160, $out['seo'], $errors );
				if ( array_key_exists( 'default_og_image', $seo ) ) {
					$url = $seo['default_og_image'];
					if ( is_string( $url ) && ( '' === $url || ( strlen( $url ) <= 2000 && 0 === strpos( $url, 'https://' ) && false !== filter_var( $url, FILTER_VALIDATE_URL ) ) ) ) {
						$out['seo']['default_og_image'] = '' === $url ? '' : esc_url_raw( $url );
					} else {
						$errors['seo.default_og_image'] = self::field_message( 'format', 'seo.default_og_image', __( 'an https:// URL or an empty string', 'aktio-connector' ) );
					}
				}
			}
		}

		if ( array_key_exists( 'content_language', $in ) ) {
			$lang = $in['content_language'];
			if ( is_string( $lang ) && ( '' === $lang || 1 === preg_match( '/^[a-z]{2}(-[A-Z]{2})?\z/', $lang ) ) ) {
				$out['content_language'] = $lang;
			} else {
				$errors['content_language'] = self::field_message( 'format', 'content_language', __( 'a language code such as pl or pl-PL, or an empty string for the site language', 'aktio-connector' ) );
			}
		}

		if ( array_key_exists( 'indexnow', $in ) ) {
			if ( ! self::is_object( $in['indexnow'] ) ) {
				$errors['indexnow'] = self::field_message( 'object', 'indexnow' );
			} else {
				$nw = $in['indexnow'];
				self::unknown_keys( $nw, array( 'enabled', 'key' ), 'indexnow', $errors );
				self::apply_bool( $nw, 'enabled', 'indexnow', $out['indexnow'], $errors );
				// Klucz generuje wtyczka: odesłanie bieżącej wartości jest dozwolone, zmiana nie.
				if ( array_key_exists( 'key', $nw ) && $nw['key'] !== $base['indexnow']['key'] ) {
					$errors['indexnow.key'] = self::field_message( 'readonly', 'indexnow.key' );
				}
			}
		}

		if ( array_key_exists( 'verification', $in ) ) {
			if ( ! self::is_object( $in['verification'] ) ) {
				$errors['verification'] = self::field_message( 'object', 'verification' );
			} else {
				$ve = $in['verification'];
				self::unknown_keys( $ve, array( 'bing', 'google' ), 'verification', $errors );
				self::apply_pattern( $ve, 'bing', 'verification', '/^[A-F0-9]{32}\z/', __( '32 uppercase hexadecimal characters or an empty string', 'aktio-connector' ), $out['verification'], $errors );
				self::apply_pattern( $ve, 'google', 'verification', '/^[A-Za-z0-9_-]{20,100}\z/', __( '20 to 100 letters, digits, hyphens or underscores, or an empty string', 'aktio-connector' ), $out['verification'], $errors );
			}
		}
		return array( $out, $errors );
	}

	/**
	 * Nakłada nowe (0.2) pola firmy: opis, data założenia, punkt kontaktowy, godziny, geo, obszar.
	 * Starsze pola firmy zostają łagodne (sanitize_company); te są walidowane ściśle.
	 * Godziny otwarcia zapisujemy zawsze; do danych strukturalnych trafiają tylko dla LocalBusiness.
	 */
	private static function company_extra_apply( array $in, array $cur ): array {
		$out    = $cur;
		$errors = array();

		self::apply_string( $in, 'description', 'company', 300, $out, $errors );
		self::apply_string( $in, 'area_served', 'company', 100, $out, $errors );
		if ( array_key_exists( 'founding_date', $in ) ) {
			$date = $in['founding_date'];
			$ok   = is_string( $date ) && ( '' === $date || ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) );
			if ( $ok ) {
				$out['founding_date'] = $date;
			} else {
				$errors['company.founding_date'] = self::field_message( 'format', 'company.founding_date', __( 'a valid date in YYYY-MM-DD format or an empty string', 'aktio-connector' ) );
			}
		}

		if ( array_key_exists( 'contact_point', $in ) ) {
			if ( ! self::is_object( $in['contact_point'] ) ) {
				$errors['company.contact_point'] = self::field_message( 'object', 'company.contact_point' );
			} else {
				$cp = $in['contact_point'];
				self::unknown_keys( $cp, array( 'telephone', 'email', 'contact_type', 'languages' ), 'company.contact_point', $errors );
				self::apply_string( $cp, 'telephone', 'company.contact_point', 50, $out['contact_point'], $errors );
				if ( array_key_exists( 'email', $cp ) ) {
					if ( is_string( $cp['email'] ) && ( '' === $cp['email'] || ( strlen( $cp['email'] ) <= 100 && is_email( $cp['email'] ) ) ) ) {
						$out['contact_point']['email'] = $cp['email'];
					} else {
						$errors['company.contact_point.email'] = self::field_message( 'format', 'company.contact_point.email', __( 'a valid email address of at most 100 characters or an empty string', 'aktio-connector' ) );
					}
				}
				self::apply_enum( $cp, 'contact_type', 'company.contact_point', array( 'customer service' ), $out['contact_point'], $errors );
				if ( array_key_exists( 'languages', $cp ) ) {
					$langs = $cp['languages'];
					$ok    = is_array( $langs ) && count( $langs ) <= 5 && ( array() === $langs || array_keys( $langs ) === range( 0, count( $langs ) - 1 ) );
					foreach ( $ok ? $langs : array() as $lang ) {
						$ok = $ok && is_string( $lang ) && '' !== sanitize_text_field( $lang ) && self::length( sanitize_text_field( $lang ) ) <= 35;
					}
					if ( $ok ) {
						$out['contact_point']['languages'] = array_map( 'sanitize_text_field', $langs );
					} else {
						$errors['company.contact_point.languages'] = self::field_message( 'format', 'company.contact_point.languages', __( 'a list of at most 5 non-empty strings (up to 35 characters each)', 'aktio-connector' ) );
					}
				}
			}
		}

		if ( array_key_exists( 'opening_hours', $in ) ) {
			$hours = self::opening_hours( $in['opening_hours'] );
			if ( null === $hours ) {
				$errors['company.opening_hours'] = self::field_message( 'format', 'company.opening_hours', __( 'a list of at most 7 entries {days, opens, closes}, with days from Mo, Tu, We, Th, Fr, Sa, Su and times in HH:MM format', 'aktio-connector' ) );
			} else {
				$out['opening_hours'] = $hours;
			}
		}

		if ( array_key_exists( 'geo', $in ) ) {
			$geo = $in['geo'];
			if ( null === $geo ) {
				$out['geo'] = null;
			} elseif ( self::is_object( $geo ) && array( 'lat', 'lng' ) === self::sorted_keys( $geo ) && self::in_range( $geo['lat'], 90 ) && self::in_range( $geo['lng'], 180 ) ) {
				$out['geo'] = array(
					'lat' => (float) $geo['lat'],
					'lng' => (float) $geo['lng'],
				);
			} else {
				$errors['company.geo'] = self::field_message( 'format', 'company.geo', __( 'null or an object {lat, lng} with lat from -90 to 90 and lng from -180 to 180', 'aktio-connector' ) );
			}
		}
		return array( $out, $errors );
	}

	/** Liczba (int/float) z przedziału [-$limit, $limit]. */
	private static function in_range( $value, int $limit ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && abs( $value ) <= $limit;
	}

	/**
	 * Godziny otwarcia: lista ≤7 wpisów {days: podzbiór Mo..Su, opens: HH:MM, closes: HH:MM}.
	 * Zwraca znormalizowaną listę albo null, gdy cokolwiek jest niepoprawne.
	 */
	private static function opening_hours( $value ): ?array {
		if ( ! is_array( $value ) || count( $value ) > 7 || ( array() !== $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
			return null;
		}
		$time = '/^([01]\d|2[0-3]):[0-5]\d\z/';
		$out  = array();
		foreach ( $value as $entry ) {
			if ( ! self::is_object( $entry ) || array( 'closes', 'days', 'opens' ) !== self::sorted_keys( $entry ) ) {
				return null;
			}
			$days = $entry['days'];
			if ( ! is_array( $days ) || array() === $days || array_keys( $days ) !== range( 0, count( $days ) - 1 ) ) {
				return null;
			}
			foreach ( $days as $day ) {
				if ( ! is_string( $day ) || ! in_array( $day, self::WEEKDAYS, true ) ) {
					return null;
				}
			}
			if ( count( array_unique( $days ) ) !== count( $days ) ) {
				return null;
			}
			if ( ! is_string( $entry['opens'] ) || ! is_string( $entry['closes'] ) || 1 !== preg_match( $time, $entry['opens'] ) || 1 !== preg_match( $time, $entry['closes'] ) ) {
				return null;
			}
			$out[] = array(
				'days'   => $days,
				'opens'  => $entry['opens'],
				'closes' => $entry['closes'],
			);
		}
		return $out;
	}

	private static function sorted_keys( array $arr ): array {
		$keys = array_keys( $arr );
		sort( $keys );
		return $keys;
	}

	/** Tekst do $max znaków (po sanitize_text_field); inny typ albo za długi → błąd. */
	private static function apply_string( array $in, string $key, string $path, int $max, array &$out, array &$errors ): void {
		if ( ! array_key_exists( $key, $in ) ) {
			return;
		}
		if ( is_string( $in[ $key ] ) && self::length( sanitize_text_field( $in[ $key ] ) ) <= $max ) {
			$out[ $key ] = sanitize_text_field( $in[ $key ] );
		} else {
			$errors[ "$path.$key" ] = self::field_message( 'string', "$path.$key", $max );
		}
	}

	/** Wartość pasująca do wzorca albo ''. */
	private static function apply_pattern( array $in, string $key, string $path, string $pattern, string $description, array &$out, array &$errors ): void {
		if ( ! array_key_exists( $key, $in ) ) {
			return;
		}
		if ( is_string( $in[ $key ] ) && ( '' === $in[ $key ] || 1 === preg_match( $pattern, $in[ $key ] ) ) ) {
			$out[ $key ] = $in[ $key ];
		} else {
			$errors[ "$path.$key" ] = self::field_message( 'format', "$path.$key", $description );
		}
	}

	private static function unknown_keys( array $in, array $allowed, string $path, array &$errors ): void {
		foreach ( array_keys( $in ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$field            = $path . '.' . $key;
				$errors[ $field ] = self::field_message( 'unknown', $field );
			}
		}
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
		if ( is_string( $in['country'] ) && ( '' === $in['country'] || 1 === preg_match( '/^[A-Z]{2}\z/', $in['country'] ) ) ) {
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
