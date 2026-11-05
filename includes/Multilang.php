<?php
/**
 * Metadane językowe dla narzędzi platformy: wykrywanie WPML / Polylang, lista języków,
 * język wpisu i adres strony głównej danego języka. Bez wtyczki: jeden język (z get_locale).
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Multilang {

	/**
	 * `wpml`, `polylang` albo null (brak wtyczki językowej).
	 */
	public static function plugin(): ?string {
		$plugin = null;
		if ( class_exists( 'SitePress' ) ) {
			$plugin = 'wpml';
		} elseif ( function_exists( 'PLL' ) ) {
			$plugin = 'polylang';
		}
		$plugin = apply_filters( 'aktio_connector_multilang_plugin', $plugin );
		return in_array( $plugin, array( 'wpml', 'polylang' ), true ) ? $plugin : null;
	}

	/**
	 * Aktywne języki: [{code, label}]. Bez wtyczki jeden język z ustawień witryny.
	 *
	 * @return array<int, array{code:string, label:string}>
	 */
	public static function languages(): array {
		$out = array();
		switch ( self::plugin() ) {
			case 'wpml':
				$active = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
				foreach ( is_array( $active ) ? $active : array() as $code => $lang ) {
					$out[] = array(
						'code'  => (string) ( $lang['language_code'] ?? $code ),
						'label' => (string) ( $lang['native_name'] ?? $lang['translated_name'] ?? $code ),
					);
				}
				break;
			case 'polylang':
				$codes  = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();
				$labels = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'name' ) ) : array();
				foreach ( array_values( $codes ) as $i => $code ) {
					$out[] = array(
						'code'  => (string) $code,
						'label' => (string) ( $labels[ $i ] ?? $code ),
					);
				}
				break;
		}
		if ( ! $out ) {
			$out[] = array(
				'code'  => self::site_code(),
				'label' => (string) get_locale(),
			);
		}
		return $out;
	}

	/** @return string[] */
	public static function codes(): array {
		return array_column( self::languages(), 'code' );
	}

	public static function default_lang(): string {
		$default = null;
		if ( 'wpml' === self::plugin() ) {
			$default = apply_filters( 'wpml_default_language', null );
		} elseif ( 'polylang' === self::plugin() && function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language();
		}
		return is_string( $default ) && '' !== $default ? $default : self::site_code();
	}

	/**
	 * Blok `multilingual` dla /status: {plugin, langs_count, default_lang}; bez wtyczki null.
	 */
	public static function summary(): ?array {
		$plugin = self::plugin();
		if ( null === $plugin ) {
			return null;
		}
		return array(
			'plugin'       => $plugin,
			'langs_count'  => count( self::languages() ),
			'default_lang' => self::default_lang(),
		);
	}

	/**
	 * Kod języka wpisu (produktu, załącznika); bez wtyczki język domyślny, nieprzypisany wpis null.
	 */
	public static function post_lang( int $post_id ): ?string {
		$lang = null;
		if ( 'wpml' === self::plugin() ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			$lang    = is_array( $details ) ? ( $details['language_code'] ?? null ) : null;
		} elseif ( 'polylang' === self::plugin() && function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id, 'slug' );
		} else {
			return self::default_lang();
		}
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	/**
	 * Dopasowuje kod z żądania (en, EN, en_GB, en-GB) do kodu aktywnego języka; null gdy nieznany.
	 */
	public static function resolve( string $requested ): ?string {
		$requested = strtolower( str_replace( '_', '-', trim( $requested ) ) );
		$codes     = self::codes();
		foreach ( $codes as $code ) {
			if ( strtolower( str_replace( '_', '-', $code ) ) === $requested ) {
				return $code;
			}
		}
		// Bez wtyczki domyślny język przyjmuje też pełną lokalizację (pl_PL).
		if ( null === self::plugin() && str_replace( '_', '-', strtolower( (string) get_locale() ) ) === $requested ) {
			return $codes[0];
		}
		return null;
	}

	/** Adres strony głównej danego języka. */
	public static function home_url( string $code ): string {
		$url = null;
		if ( 'wpml' === self::plugin() ) {
			$url = apply_filters( 'wpml_permalink', home_url( '/' ), $code );
		} elseif ( 'polylang' === self::plugin() && function_exists( 'pll_home_url' ) ) {
			$url = pll_home_url( $code );
		}
		return is_string( $url ) && '' !== $url ? $url : home_url( '/' );
	}

	/** Przełącza język zapytań WPML (Polylang filtrujemy parametrem `lang`); null = przywróć. */
	public static function switch_to( ?string $code ): void {
		if ( 'wpml' === self::plugin() ) {
			do_action( 'wpml_switch_language', $code );
		}
	}

	/** Parametry WP_Query ograniczające do języka (Polylang: `lang`; WPML przez switch_to). */
	public static function query_args( string $code ): array {
		return 'polylang' === self::plugin() ? array( 'lang' => $code ) : array();
	}

	/** Dwuliterowy kod z lokalizacji witryny (pl_PL → pl). */
	private static function site_code(): string {
		return strtolower( substr( (string) get_locale(), 0, 2 ) );
	}
}
