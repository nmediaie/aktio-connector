<?php
/**
 * Język treści strony niezależny od języka panelu: atrybut lang na froncie,
 * wykrywanie wtyczek wielojęzycznych i ostrzeżenie o niezgodności języka treści.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Language {

	/**
	 * Wtyczki wielojęzyczne (plik główny => nazwa). Przy nich nie ruszamy lang.
	 */
	const PLUGINS = array(
		'sitepress-multilingual-cms/sitepress.php' => 'WPML',
		'polylang/polylang.php'                    => 'Polylang',
		'polylang-pro/polylang.php'                => 'Polylang',
		'translatepress-multilingual/index.php'    => 'TranslatePress',
	);

	const GTRANSLATE = 'gtranslate/gtranslate.php';

	/**
	 * Słowa funkcyjne do heurystyki wykrywania języka treści.
	 */
	const WORDS = array(
		'pl' => array( 'jest', 'nie', 'się', 'jak', 'dla', 'oraz', 'przez', 'który', 'która', 'które', 'tylko', 'jako', 'było', 'nasza', 'nasze', 'czy', 'ale', 'też', 'możesz', 'można', 'bardzo', 'wszystko', 'nas', 'was' ),
		'en' => array( 'the', 'and', 'is', 'are', 'was', 'for', 'with', 'that', 'this', 'you', 'your', 'our', 'have', 'from', 'not', 'will', 'can', 'all', 'of', 'to', 'it' ),
		'de' => array( 'der', 'die', 'das', 'und', 'ist', 'nicht', 'ein', 'eine', 'mit', 'für', 'auf', 'von', 'zu', 'den', 'dem', 'sie', 'wir', 'auch', 'sich', 'bei' ),
		'fr' => array( 'le', 'la', 'les', 'des', 'est', 'une', 'pour', 'dans', 'qui', 'que', 'pas', 'sur', 'avec', 'vous', 'nous', 'sont', 'ce', 'du', 'au', 'et' ),
		'es' => array( 'el', 'los', 'las', 'una', 'por', 'con', 'para', 'del', 'que', 'es', 'se', 'su', 'más', 'pero', 'como', 'nuestro', 'está', 'sus', 'y', 'un' ),
	);

	public static function register(): void {
		add_filter( 'language_attributes', array( __CLASS__, 'language_attributes' ) );
	}

	/**
	 * Efektywny język treści (BCP-47, np. en-IE). Przy wtyczce wielojęzycznej
	 * język bieżącego widoku wskazuje ona (locale), nie ustawienie Aktio.
	 */
	public static function current(): string {
		if ( null !== self::multilingual_plugin() ) {
			return str_replace( '_', '-', get_locale() );
		}
		return (string) Settings::get()['content_language'];
	}

	/**
	 * Atrybut lang na froncie (nie w panelu); wtyczki wielojęzyczne zostają przy swoim.
	 */
	public static function language_attributes( $output ) {
		if ( is_admin() || null !== self::multilingual_plugin() ) {
			return $output;
		}
		$lang = esc_attr( self::current() );
		return (string) preg_replace( '/\b(xml:lang|lang)="[^"]*"/', '$1="' . $lang . '"', (string) $output );
	}

	/**
	 * Nazwa aktywnej wtyczki wielojęzycznej (z osobnymi adresami języków) albo null.
	 */
	public static function multilingual_plugin(): ?string {
		$active = self::active_plugins();
		foreach ( self::PLUGINS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				return $name;
			}
		}
		if ( in_array( self::GTRANSLATE, $active, true ) && ! self::gtranslate_js() ) {
			return 'GTranslate';
		}
		return null;
	}

	/**
	 * GTranslate aktywny bez osobnych adresów języków (tłumaczenie w JS, niewidoczne dla botów).
	 */
	public static function gtranslate_js(): bool {
		if ( ! in_array( self::GTRANSLATE, self::active_plugins(), true ) ) {
			return false;
		}
		$opts = get_option( 'GTranslate' );
		$mode = is_array( $opts ) && isset( $opts['url_structure'] ) ? (string) $opts['url_structure'] : '';
		return ! in_array( $mode, array( 'sub_domain', 'sub_directory' ), true );
	}

	/**
	 * Niezgodność języka treści z ustawionym: {expected, detected} albo null.
	 * Heurystyka: słowa funkcyjne pl/en/de/fr/es w 5 ostatnich publicznych
	 * wpisach i produktach; wykryty język musi mieć co najmniej 60% trafień.
	 */
	public static function warning(): ?array {
		if ( null !== self::multilingual_plugin() ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'product' ),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 5,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$text = '';
		foreach ( $posts as $post ) {
			$text .= ' ' . $post->post_title . ' ' . $post->post_content;
		}
		$detected = self::detect( $text );
		if ( null === $detected ) {
			return null;
		}
		$expected = self::current();
		if ( strtolower( substr( $expected, 0, 2 ) ) === $detected ) {
			return null;
		}
		return array(
			'expected' => $expected,
			'detected' => $detected,
		);
	}

	/**
	 * Kod języka z największą liczbą słów funkcyjnych (≥ 60% trafień, min. 10 trafień) albo null.
	 */
	private static function detect( string $text ): ?string {
		$text  = mb_strtolower( wp_strip_all_tags( strip_shortcodes( $text ) ) );
		$words = preg_split( '/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $words ) {
			return null;
		}
		$freq   = array_count_values( $words );
		$scores = array();
		foreach ( self::WORDS as $lang => $list ) {
			$scores[ $lang ] = 0;
			foreach ( $list as $word ) {
				$scores[ $lang ] += $freq[ $word ] ?? 0;
			}
		}
		$total = array_sum( $scores );
		if ( $total < 10 ) {
			return null;
		}
		arsort( $scores );
		$top = (string) key( $scores );
		return current( $scores ) / $total >= 0.6 ? $top : null;
	}

	/**
	 * @return string[]
	 */
	private static function active_plugins(): array {
		$plugins = array_merge(
			(array) get_option( 'active_plugins', array() ),
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
		);
		return array_values( array_unique( array_filter( $plugins, 'is_string' ) ) );
	}
}
