<?php
/**
 * Ekran Ustawienia → Aktio Connector w zakładkach: Przegląd (braki, stan funkcji, szybkie linki),
 * Firma, SEO, Sklep, Boty i indeksowanie (edycja w jednym formularzu), Widoczność w AI, Narzędzia
 * i O Aktio (tylko do odczytu). Zakładki działają też bez JS (linki ?tab=), JS przełącza je bez przeładowania.
 * Działa bez Aktio.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Admin;

use AktioConnector\CachePurge;
use AktioConnector\Detector;
use AktioConnector\IndexNow;
use AktioConnector\Language;
use AktioConnector\Metrics;
use AktioConnector\Settings;
use AktioConnector\Updates;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	const SLUG   = 'aktio-connector';
	const ACTION = 'aktio_connector_save';
	const NONCE  = 'aktio_connector_save';

	const SITEMAP_ACTION = 'aktio_connector_indexnow_sitemap';
	const SITEMAP_NONCE  = 'aktio_connector_indexnow_sitemap';
	const CACHE_ACTION   = 'aktio_connector_cache_purge';
	const CACHE_NONCE    = 'aktio_connector_cache_purge';

	/** Komunikaty po akcjach przycisków (parametr aktio_notice → napis). */
	const NOTICES = array( 'sitemap', 'cache', 'cache_none' );

	/** Zakładki w kolejności wyświetlania; „store” tylko z WooCommerce. */
	const TABS = array( 'overview', 'company', 'seo', 'store', 'bots', 'visibility', 'tools', 'about' );

	/** Zakładki z polami formularza (pasek zapisu); pozostałe są tylko do odczytu. */
	const EDITABLE_TABS = array( 'company', 'seo', 'store', 'bots' );

	/** Pierwszy człon ścieżki pola z błędu walidacji → zakładka z tym polem. */
	const ERROR_TABS = array(
		'company'          => 'company',
		'seo'              => 'seo',
		'content_language' => 'seo',
		'verification'     => 'seo',
		'merchant'         => 'store',
		'bots'             => 'bots',
		'indexnow'         => 'bots',
	);

	/** Języki treści do wyboru: kod BCP 47 → nazwa we własnym języku (nie tłumaczymy). */
	const LANGUAGES = array(
		'pl'    => 'Polski',
		'pl-PL' => 'Polski (Polska)',
		'en'    => 'English',
		'en-GB' => 'English (United Kingdom)',
		'en-IE' => 'English (Ireland)',
		'en-US' => 'English (United States)',
		'de'    => 'Deutsch',
		'de-DE' => 'Deutsch (Deutschland)',
		'de-AT' => 'Deutsch (Österreich)',
		'de-CH' => 'Deutsch (Schweiz)',
		'fr'    => 'Français',
		'fr-FR' => 'Français (France)',
		'es'    => 'Español',
		'es-ES' => 'Español (España)',
		'it'    => 'Italiano',
		'nl'    => 'Nederlands',
		'pt'    => 'Português',
		'pt-PT' => 'Português (Portugal)',
		'pt-BR' => 'Português (Brasil)',
		'cs'    => 'Čeština',
		'sk'    => 'Slovenčina',
		'uk'    => 'Українська',
		'lt'    => 'Lietuvių',
		'lv'    => 'Latviešu',
		'et'    => 'Eesti',
		'hu'    => 'Magyar',
		'ro'    => 'Română',
		'bg'    => 'Български',
		'hr'    => 'Hrvatski',
		'sl'    => 'Slovenščina',
		'sv'    => 'Svenska',
		'da'    => 'Dansk',
		'nb'    => 'Norsk bokmål',
		'fi'    => 'Suomi',
		'el'    => 'Ελληνικά',
		'tr'    => 'Türkçe',
	);

	/** Języki obsługi klienta (pola wyboru, do 5 — limit walidacji). */
	const SERVICE_LANGUAGES = array( 'pl', 'en', 'de', 'fr', 'es', 'it', 'uk', 'cs', 'sk', 'lt' );

	/** Domyślne hasła WordPressa — to nie jest opis witryny. */
	const DEFAULT_TAGLINES = array( 'Just another WordPress site', 'Kolejna witryna WordPress' );

	const AKTIO_URL = 'https://aktioapp.com/';

	/** Szew dla testów: ekran zachowuje się, jakby WooCommerce nie było. */
	public static $assume_no_woocommerce = false;

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::SITEMAP_ACTION, array( __CLASS__, 'handle_indexnow_sitemap' ) );
		add_action( 'admin_post_' . self::CACHE_ACTION, array( __CLASS__, 'handle_cache_purge' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AKTIO_CONNECTOR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'Aktio Connector', 'aktio-connector' ),
			__( 'Aktio Connector', 'aktio-connector' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links   = is_array( $links ) ? $links : array();
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'aktio-connector' ) . '</a>';
		return $links;
	}

	/**
	 * admin-post: nonce i uprawnienie, zapis, przekierowanie z komunikatem na tę samą zakładkę.
	 */
	public static function handle_save(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'aktio-connector' ), '', array( 'response' => 403 ) );
		}
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej, wartości sanityzuje Settings::update().
		$post   = is_array( $post ) ? $post : array();
		$errors = self::save( $post );
		$flag   = array( 'saved' => '1' );
		if ( $errors ) {
			// Komunikaty przechodzą przez krótkotrwały transient użytkownika (przekierowanie PRG); klucze = ścieżki pól.
			set_transient( self::errors_key(), $errors, 5 * MINUTE_IN_SECONDS );
			$flag = array( 'invalid' => '1' );
		}
		$tab = isset( $post['aktio_tab'] ) && is_string( $post['aktio_tab'] ) ? sanitize_key( $post['aktio_tab'] ) : '';
		if ( in_array( $tab, self::TABS, true ) ) {
			$flag['tab'] = $tab;
		}
		self::redirect( $flag );
	}

	/**
	 * admin-post: „Zgłoś całą mapę” do IndexNow (nonce, uprawnienie, limit 24 h w IndexNow).
	 */
	public static function handle_indexnow_sitemap(): void {
		check_admin_referer( self::SITEMAP_NONCE, '_aktio_sitemap_nonce' );
		self::require_capability();
		$result = IndexNow::submit_sitemap();
		if ( is_wp_error( $result ) ) {
			set_transient( self::errors_key(), array( $result->get_error_message() ), 5 * MINUTE_IN_SECONDS );
			$flag = array( 'invalid' => '1' );
		} else {
			$flag = array( 'aktio_notice' => 'sitemap' );
		}
		self::redirect( $flag + array( 'tab' => 'bots' ) );
	}

	/**
	 * admin-post: „Wyczyść teraz” — czyszczenie cache wykrytej wtyczki.
	 */
	public static function handle_cache_purge(): void {
		check_admin_referer( self::CACHE_NONCE, '_aktio_cache_nonce' );
		self::require_capability();
		self::redirect(
			array(
				'aktio_notice' => CachePurge::all() ? 'cache' : 'cache_none',
				'tab'          => 'tools',
			)
		);
	}

	private static function require_capability(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'aktio-connector' ), '', array( 'response' => 403 ) );
		}
	}

	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/** Style ekranu, zakładki i wybór obrazka z biblioteki mediów — tylko na ekranie ustawień. */
	public static function enqueue_assets( $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style(
			'aktio-settings',
			plugins_url( 'assets/aktio-settings.css', AKTIO_CONNECTOR_FILE ),
			array(),
			AKTIO_CONNECTOR_VERSION
		);
		wp_enqueue_script(
			'aktio-settings',
			plugins_url( 'assets/aktio-settings.js', AKTIO_CONNECTOR_FILE ),
			array( 'media-views' ),
			AKTIO_CONNECTOR_VERSION,
			true
		);
	}

	private static function errors_key(): string {
		return 'aktio_connector_errors_' . get_current_user_id();
	}

	/**
	 * Zapis pól z formularza (sanityzacja w Settings::update). Błędy walidacji
	 * blokują cały zapis (ustawienia bez zmian); zwraca ścieżka pola → komunikat.
	 */
	public static function save( array $post ): array {
		$partial = array();
		if ( isset( $post['company'] ) && is_array( $post['company'] ) ) {
			$partial['company'] = self::company_from_post( $post['company'] );
		}
		if ( isset( $post['bots'] ) && is_array( $post['bots'] ) ) {
			$partial['bots'] = $post['bots'];
		}
		if ( isset( $post['merchant'] ) && is_array( $post['merchant'] ) ) {
			$partial['merchant'] = self::merchant_from_post( $post['merchant'] );
		}
		foreach ( array( 'seo', 'verification' ) as $section ) {
			if ( isset( $post[ $section ] ) && is_array( $post[ $section ] ) ) {
				$partial[ $section ] = array_map( static fn( $value ) => is_string( $value ) ? trim( $value ) : $value, $post[ $section ] );
			}
		}
		if ( isset( $post['content_language'] ) && is_string( $post['content_language'] ) ) {
			$partial['content_language'] = self::language_from_post( trim( $post['content_language'] ) );
		}
		if ( isset( $post['indexnow'] ) && is_array( $post['indexnow'] ) && array_key_exists( 'enabled', $post['indexnow'] ) ) {
			// Klucz generuje wtyczka — formularz go nie przesyła.
			$partial['indexnow'] = array( 'enabled' => '1' === $post['indexnow']['enabled'] );
		}
		$errors = Settings::partial_errors( $partial );
		if ( $errors ) {
			return $errors;
		}
		Settings::update( $partial );
		return array();
	}

	/**
	 * Pola firmy z formularza → kształt Settings: profile społecznościowe (wiersze), języki
	 * obsługi (pola wyboru), godziny otwarcia (wiersze; puste pomijane) i współrzędne.
	 * Pusty string w miejscu listy to ukryte pole „nic nie zaznaczono”.
	 */
	private static function company_from_post( array $company ): array {
		if ( isset( $company['same_as'] ) && is_string( $company['same_as'] ) ) {
			$company['same_as'] = preg_split( '/\R/', $company['same_as'] ) ?: array();
		}
		if ( isset( $company['contact_point'] ) && is_array( $company['contact_point'] ) && array_key_exists( 'languages', $company['contact_point'] ) ) {
			$langs = $company['contact_point']['languages'];
			$langs = is_string( $langs ) ? explode( ',', $langs ) : $langs;
			if ( is_array( $langs ) ) {
				$company['contact_point']['languages'] = array_values( array_filter( array_map( static fn( $l ) => is_string( $l ) ? trim( $l ) : '', $langs ), 'strlen' ) );
			}
		}
		if ( array_key_exists( 'opening_hours', $company ) ) {
			$company['opening_hours'] = self::hours_from_post( $company['opening_hours'] );
		}
		if ( isset( $company['geo'] ) && is_array( $company['geo'] ) ) {
			$company['geo'] = self::geo_from_post( $company['geo'] );
		}
		return $company;
	}

	/**
	 * Wiersze godzin otwarcia: wiersz bez dni i godzin jest pominięty (pusty wiersz formularza),
	 * dni w kolejności tygodnia. Niepełny wiersz trafia do walidacji i daje błąd pola.
	 *
	 * @param mixed $rows Wartość z formularza.
	 * @return mixed
	 */
	private static function hours_from_post( $rows ) {
		if ( is_string( $rows ) ) {
			return array();
		}
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$days   = isset( $row['days'] ) && is_array( $row['days'] ) ? array_values( $row['days'] ) : array();
			$opens  = isset( $row['opens'] ) && is_string( $row['opens'] ) ? trim( $row['opens'] ) : '';
			$closes = isset( $row['closes'] ) && is_string( $row['closes'] ) ? trim( $row['closes'] ) : '';
			if ( ! $days && '' === $opens && '' === $closes ) {
				continue;
			}
			$known = array_values( array_intersect( Settings::WEEKDAYS, $days ) );
			$out[] = array(
				'days'   => array_merge( $known, array_values( array_diff( $days, Settings::WEEKDAYS ) ) ),
				'opens'  => $opens,
				'closes' => $closes,
			);
		}
		return $out;
	}

	/**
	 * Współrzędne: oba pola puste → null; liczby (także z przecinkiem) → float; reszta zostaje
	 * tekstem i walidacja odrzuca ją z błędem pola.
	 *
	 * @return array|null
	 */
	private static function geo_from_post( array $geo ) {
		$parse = static function ( $value ) {
			$value = is_string( $value ) ? str_replace( ',', '.', trim( $value ) ) : $value;
			return is_string( $value ) && is_numeric( $value ) ? (float) $value : $value;
		};
		$lat = $parse( $geo['lat'] ?? '' );
		$lng = $parse( $geo['lng'] ?? '' );
		if ( '' === $lat && '' === $lng ) {
			return null;
		}
		return array(
			'lat' => $lat,
			'lng' => $lng,
		);
	}

	/**
	 * Język równy językowi witryny, gdy w opcji nie ustawiono własnego, zostaje pusty
	 * („język witryny”) — zapis ekranu nie zamraża języka (jak kraj sklepu niżej).
	 */
	private static function language_from_post( string $value ): string {
		$stored = get_option( Settings::OPTION, array() );
		$own    = is_array( $stored ) && isset( $stored['content_language'] ) && is_string( $stored['content_language'] ) ? $stored['content_language'] : '';
		return '' === $own && str_replace( '_', '-', get_locale() ) === $value ? '' : $value;
	}

	/**
	 * Wartości formularza (same teksty) → typy wymagane przez Settings. Tu tylko zamiana
	 * typów i drobna normalizacja wpisu (przecinek dziesiętny, wielkość liter kraju);
	 * wartości, których nie da się zamienić, zostają tekstem i walidacja je odrzuci.
	 */
	private static function merchant_from_post( array $m ): array {
		$int  = static function ( $value ) {
			$value = is_string( $value ) ? trim( $value ) : $value;
			return is_string( $value ) && 1 === preg_match( '/^-?\d+$/', $value ) ? (int) $value : $value;
		};
		$pair = static function ( $value ) use ( $int ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			return array_map( $int, $value );
		};
		// Kraj równy krajowi sklepu, gdy w opcji nie ustawiono własnego, zostaje pusty
		// („kraj sklepu”) — zapis ekranu nie zamraża kraju, więc zmiana kraju sklepu działa dalej.
		$stored  = get_option( Settings::OPTION, array() );
		$country = static function ( $value, string $section ) use ( $stored ) {
			if ( ! is_string( $value ) ) {
				return $value;
			}
			$value  = strtoupper( trim( $value ) );
			$own    = is_array( $stored ) && isset( $stored['merchant'][ $section ]['country'] ) ? $stored['merchant'][ $section ]['country'] : '';
			$follow = ( '' === $own || ! is_string( $own ) ) && '' !== $value && Settings::shop_country() === $value;
			return $follow ? '' : $value;
		};

		$out = array();
		if ( array_key_exists( 'default_brand', $m ) ) {
			$out['default_brand'] = is_string( $m['default_brand'] ) ? trim( $m['default_brand'] ) : $m['default_brand'];
		}
		if ( isset( $m['return_policy'] ) && is_array( $m['return_policy'] ) ) {
			$rp  = $m['return_policy'];
			$new = array();
			if ( array_key_exists( 'enabled', $rp ) ) {
				$new['enabled'] = '1' === $rp['enabled'];
			}
			if ( array_key_exists( 'days', $rp ) ) {
				$new['days'] = $int( $rp['days'] );
			}
			foreach ( array( 'fees', 'method' ) as $key ) {
				if ( array_key_exists( $key, $rp ) ) {
					$new[ $key ] = $rp[ $key ];
				}
			}
			if ( array_key_exists( 'country', $rp ) ) {
				$new['country'] = $country( $rp['country'], 'return_policy' );
			}
			$out['return_policy'] = $new;
		}
		if ( isset( $m['shipping'] ) && is_array( $m['shipping'] ) ) {
			$sh  = $m['shipping'];
			$new = array();
			if ( array_key_exists( 'enabled', $sh ) ) {
				$new['enabled'] = '1' === $sh['enabled'];
			}
			if ( array_key_exists( 'country', $sh ) ) {
				$new['country'] = $country( $sh['country'], 'shipping' );
			}
			if ( array_key_exists( 'cost', $sh ) ) {
				$new['cost'] = is_string( $sh['cost'] ) ? str_replace( ',', '.', trim( $sh['cost'] ) ) : $sh['cost'];
			}
			foreach ( array( 'handling_days', 'transit_days' ) as $key ) {
				if ( array_key_exists( $key, $sh ) ) {
					$new[ $key ] = $pair( $sh[ $key ] );
				}
			}
			$out['shipping'] = $new;
		}
		return $out;
	}

	// ------------------------------------------------------------------ Ekran

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::get();
		$errors   = isset( $_GET['invalid'] ) ? self::take_errors() : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko komunikat.
		$tabs     = self::tabs();
		$active   = self::active_tab( $tabs, $errors );

		echo '<div class="wrap aktio-wrap">';
		self::render_header();
		// Znacznik końca nagłówka: tu WordPress przenosi komunikaty innych wtyczek (zamiast do środka nagłówka marki).
		echo '<hr class="wp-header-end" />';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko komunikat.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'aktio-connector' ) . '</p></div>';
		}
		self::render_errors( $errors, $tabs );
		self::render_notice();

		self::render_tab_nav( $tabs, $active );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aktio-form" id="aktio-settings-form">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="aktio_tab" value="' . esc_attr( $active ) . '" />';
		wp_nonce_field( self::NONCE );

		self::panel_open( 'company', $active, __( 'This is how search engines and AI assistants learn who you are. The data goes into schema.org structured data (JSON-LD) on every page and into llms.txt.', 'aktio-connector' ) );
		self::render_company( $settings['company'] );
		self::panel_close();

		self::panel_open( 'seo', $active, __( 'Basic SEO for sites without an SEO plugin. If Yoast SEO, Rank Math or another SEO plugin is active, it takes over the features it provides and the matching fields here are not used.', 'aktio-connector' ) );
		self::render_seo( $settings['seo'] );
		self::render_language( (string) $settings['content_language'] );
		self::render_verification( $settings['verification'] );
		self::panel_close();

		if ( isset( $tabs['store'] ) ) {
			self::panel_open( 'store', $active, __( 'Data Google needs to show your products with shipping and return details in Search and Shopping. Fill it in only when WooCommerce does not provide it.', 'aktio-connector' ) );
			self::render_merchant( $settings['merchant'], (string) $settings['company']['name'] );
			self::panel_close();
		}

		self::panel_open( 'bots', $active, __( 'Decide which AI bots may read your site and let search engines learn about changes right away.', 'aktio-connector' ) );
		self::render_bots( $settings['bots'] );
		self::render_indexnow( $settings['indexnow'] );
		self::panel_close();

		self::render_savebar( in_array( $active, self::EDITABLE_TABS, true ) );
		echo '</form>';

		// Formularze przycisków akcji (przyciski w sekcjach wskazują je atrybutem form).
		self::action_form( 'aktio-sitemap-form', self::SITEMAP_ACTION, self::SITEMAP_NONCE, '_aktio_sitemap_nonce' );
		self::action_form( 'aktio-cache-form', self::CACHE_ACTION, self::CACHE_NONCE, '_aktio_cache_nonce' );

		self::panel_open( 'overview', $active, __( 'What Aktio Connector does on this site and what is still worth filling in.', 'aktio-connector' ) );
		self::render_checklist( $settings );
		self::render_features();
		self::render_quick_links();
		self::panel_close();

		self::panel_open( 'visibility', $active, __( 'How often AI bots read your site and how many people come to it from AI assistants and AI search.', 'aktio-connector' ) );
		self::render_metrics();
		self::panel_close();

		self::panel_open( 'tools', $active, '' );
		self::render_cache();
		self::render_log();
		self::panel_close();

		self::panel_open( 'about', $active, '' );
		self::render_about();
		self::panel_close();

		echo '</div>';
	}

	/** Zakładki dostępne na tej witrynie: slug → etykieta. */
	private static function tabs(): array {
		$labels = array(
			'overview'   => __( 'Overview', 'aktio-connector' ),
			'company'    => __( 'Business', 'aktio-connector' ),
			'seo'        => __( 'SEO', 'aktio-connector' ),
			'store'      => __( 'Store', 'aktio-connector' ),
			'bots'       => __( 'Bots & indexing', 'aktio-connector' ),
			'visibility' => __( 'AI visibility', 'aktio-connector' ),
			'tools'      => __( 'Tools', 'aktio-connector' ),
			'about'      => __( 'About Aktio', 'aktio-connector' ),
		);
		if ( ! self::woocommerce_available() ) {
			unset( $labels['store'] );
		}
		return $labels;
	}

	/**
	 * Aktywna zakładka: ta z pierwszym błędem walidacji, potem ?tab=, na końcu Przegląd.
	 *
	 * @param array<string,string> $tabs   Dostępne zakładki.
	 * @param array                $errors Błędy z ostatniego zapisu.
	 */
	private static function active_tab( array $tabs, array $errors ): string {
		foreach ( $errors as $path => $message ) {
			$tab = self::error_tab( $path, $message );
			if ( null !== $tab && isset( $tabs[ $tab ] ) ) {
				return $tab;
			}
		}
		$requested = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wybór widoku.
		return isset( $tabs[ $requested ] ) ? $requested : 'overview';
	}

	/**
	 * Zakładka pola z błędem: klucz to ścieżka pola; przy liście bez kluczy (starszy zapis)
	 * ścieżką jest pierwsze słowo komunikatu.
	 *
	 * @param int|string $path    Klucz błędu.
	 * @param mixed      $message Komunikat.
	 */
	private static function error_tab( $path, $message ): ?string {
		$path = is_string( $path ) ? $path : (string) strtok( (string) $message, ' ' );
		$root = (string) strtok( $path, '.' );
		return self::ERROR_TABS[ $root ] ?? null;
	}

	private static function render_header(): void {
		echo '<header class="aktio-header">';
		echo '<img class="aktio-header__logo" src="' . esc_url( self::logo_url() ) . '" width="48" height="48" alt="" />';
		echo '<div class="aktio-header__text">';
		echo '<h1 class="aktio-header__title">' . esc_html__( 'Aktio Connector', 'aktio-connector' ) . ' <span class="aktio-badge">' . esc_html( 'v' . AKTIO_CONNECTOR_VERSION ) . '</span></h1>';
		echo '<p class="aktio-header__lead">' . esc_html__( 'Makes your site easy to read for search engines and AI assistants: structured data, basic SEO, llms.txt and AI bot rules.', 'aktio-connector' ) . '</p>';
		echo '</div>';
		echo '<a class="aktio-header__link" href="' . esc_url( self::AKTIO_URL ) . '" target="_blank" rel="noopener">aktioapp.com<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'aktio-connector' ) . '</span></a>';
		echo '</header>';
	}

	private static function logo_url(): string {
		return plugins_url( 'assets/img/aktio-logo.png', AKTIO_CONNECTOR_FILE );
	}

	private static function tab_url( string $slug ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $slug,
			),
			admin_url( 'options-general.php' )
		);
	}

	/** Linki z ?tab= (działają bez JS); JS przełącza panele bez przeładowania i obsługuje strzałki. */
	private static function render_tab_nav( array $tabs, string $active ): void {
		echo '<nav class="aktio-tabs" role="tablist" aria-label="' . esc_attr__( 'Aktio Connector settings', 'aktio-connector' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			$current = $slug === $active;
			echo '<a id="aktio-tab-' . esc_attr( $slug ) . '" class="aktio-tab' . ( $current ? ' is-active' : '' ) . '" role="tab"'
				. ' href="' . esc_url( self::tab_url( $slug ) ) . '"'
				. ' aria-controls="aktio-panel-' . esc_attr( $slug ) . '" aria-selected="' . ( $current ? 'true' : 'false' ) . '"'
				. ' data-tab="' . esc_attr( $slug ) . '" data-editable="' . ( in_array( $slug, self::EDITABLE_TABS, true ) ? '1' : '0' ) . '">'
				. esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	private static function panel_open( string $slug, string $active, string $intro ): void {
		echo '<section id="aktio-panel-' . esc_attr( $slug ) . '" class="aktio-panel" role="tabpanel" aria-labelledby="aktio-tab-' . esc_attr( $slug ) . '"' . ( $slug === $active ? '' : ' hidden' ) . '>';
		if ( '' !== $intro ) {
			echo '<p class="aktio-panel__intro">' . esc_html( $intro ) . '</p>';
		}
	}

	private static function panel_close(): void {
		echo '</section>';
	}

	/**
	 * Karta z nagłówkiem h2 i opisem.
	 *
	 * @param string $attrs Stałe atrybuty z kodu (nie z danych).
	 */
	private static function card_open( string $title, string $description = '', string $attrs = '' ): void {
		echo '<div class="aktio-card"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- stałe atrybuty z kodu.
		echo '<h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $description ) {
			echo '<p class="description aktio-card__description">' . esc_html( $description ) . '</p>';
		}
	}

	private static function card_close(): void {
		echo '</div>';
	}

	/** Pasek zapisu przyklejony do dołu; na zakładkach tylko do odczytu jest ukryty. */
	private static function render_savebar( bool $visible ): void {
		echo '<div class="aktio-savebar"' . ( $visible ? '' : ' hidden' ) . '>';
		echo '<span class="aktio-savebar__status" aria-live="polite" data-unsaved="' . esc_attr__( 'You have unsaved changes.', 'aktio-connector' ) . '" data-leave="' . esc_attr__( 'You have unsaved changes. Leave the page anyway?', 'aktio-connector' ) . '"></span>';
		submit_button( __( 'Save changes', 'aktio-connector' ), 'primary', 'submit', false );
		echo '</div>';
	}

	/** Pusty formularz admin-post z nonce; przycisk w innym miejscu strony dołącza się przez form="id". */
	private static function action_form( string $id, string $action, string $nonce_action, string $nonce_name ): void {
		echo '<form id="' . esc_attr( $id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $nonce_action, $nonce_name );
		echo '</form>';
	}

	/** Komunikat po akcji przycisku (tylko wartości z białej listy). */
	private static function render_notice(): void {
		$notice = isset( $_GET['aktio_notice'] ) ? sanitize_key( wp_unslash( $_GET['aktio_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko komunikat.
		if ( ! in_array( $notice, self::NOTICES, true ) ) {
			return;
		}
		$messages = array(
			'sitemap'    => __( 'The sitemap was submitted to IndexNow.', 'aktio-connector' ),
			'cache'      => __( 'Cache cleared.', 'aktio-connector' ),
			'cache_none' => __( 'No supported cache plugin was found, nothing to clear.', 'aktio-connector' ),
		);
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
	}

	/** Błędy z ostatniego zapisu (transient czytany raz — odświeżenie strony nie pokazuje ich ponownie). */
	private static function take_errors(): array {
		$errors = get_transient( self::errors_key() );
		delete_transient( self::errors_key() );
		return is_array( $errors ) ? $errors : array();
	}

	/** Komunikat błędu walidacji (ustawienia nie zostały zapisane) z linkiem do zakładki pola. */
	private static function render_errors( array $errors, array $tabs ): void {
		if ( ! $errors ) {
			return; // Odświeżenie strony po wyświetleniu błędów — bez pustej ramki.
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Settings were not saved. Fix the following fields:', 'aktio-connector' ) . '</strong></p><ul>';
		foreach ( $errors as $path => $message ) {
			$tab = self::error_tab( $path, $message );
			echo '<li>' . esc_html( (string) $message );
			if ( null !== $tab && isset( $tabs[ $tab ] ) ) {
				/* translators: %s: settings tab name */
				echo ' <a href="' . esc_url( self::tab_url( $tab ) ) . '" data-tab-link="' . esc_attr( $tab ) . '">' . esc_html( sprintf( __( 'Go to %s', 'aktio-connector' ), $tabs[ $tab ] ) ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	// ------------------------------------------------------------------ Przegląd

	/**
	 * Lista braków, które psują wygląd witryny w wyszukiwarce i w AI. Każdy punkt ma
	 * data-check (klucz) i link do miejsca, gdzie się to poprawia.
	 */
	private static function render_checklist( array $settings ): void {
		$company = $settings['company'];
		$general = admin_url( 'options-general.php' );
		$checks  = array();

		if ( '' === trim( (string) get_option( 'blogname', '' ) ) ) {
			$checks['site-title'] = array(
				__( 'The site title is empty.', 'aktio-connector' ),
				__( 'Search results, browser tabs and link previews show the site title. Enter your business name in Settings → General.', 'aktio-connector' ),
				$general,
				__( 'Open General settings', 'aktio-connector' ),
			);
		}
		$tagline = trim( (string) get_option( 'blogdescription', '' ) );
		if ( in_array( $tagline, self::DEFAULT_TAGLINES, true ) || __( 'Just another WordPress site' ) === $tagline ) { // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- tłumaczenie rdzenia.
			$checks['site-tagline'] = array(
				__( 'The tagline is the WordPress default.', 'aktio-connector' ),
				__( 'It can end up as the description of your home page. Replace it with one sentence about what you do, or clear it.', 'aktio-connector' ),
				$general,
				__( 'Open General settings', 'aktio-connector' ),
			);
		}
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$checks['search-visibility'] = array(
				__( 'Search engines are asked not to index this site.', 'aktio-connector' ),
				__( 'While this is on, the site stays out of search results and AI answers, llms.txt is not served and IndexNow sends nothing. Change it in Settings → Reading.', 'aktio-connector' ),
				admin_url( 'options-reading.php' ),
				__( 'Open Reading settings', 'aktio-connector' ),
			);
		}
		if ( '' === (string) $company['logo'] && ! (int) get_theme_mod( 'custom_logo' ) ) {
			$checks['logo'] = array(
				__( 'There is no logo.', 'aktio-connector' ),
				__( 'Google and AI assistants show the logo next to your business name. Add it on the Business tab or as the site logo in your theme.', 'aktio-connector' ),
				self::tab_url( 'company' ),
				__( 'Add a logo', 'aktio-connector' ),
				'company',
			);
		}
		if ( '' === trim( (string) $company['description'] ) ) {
			$checks['company-description'] = array(
				__( 'There is no business description.', 'aktio-connector' ),
				__( 'Two or three sentences about what you do go into llms.txt and schema.org. AI assistants read them first.', 'aktio-connector' ),
				self::tab_url( 'company' ),
				__( 'Write a description', 'aktio-connector' ),
				'company',
			);
		}
		$address = $company['address'];
		if ( 'LocalBusiness' === $company['type'] && ( '' === trim( (string) $address['street'] ) || '' === trim( (string) $address['city'] ) ) ) {
			$checks['address'] = array(
				__( 'The local business has no address.', 'aktio-connector' ),
				__( 'Google requires an address for a local business. Enter it on the Business tab, or change the type to "Company or organization" if customers do not visit you.', 'aktio-connector' ),
				self::tab_url( 'company' ),
				__( 'Enter the address', 'aktio-connector' ),
				'company',
			);
		}

		self::card_open( __( 'To do', 'aktio-connector' ) );
		if ( ! $checks ) {
			echo '<p class="aktio-all-set">' . esc_html__( 'Everything important is filled in. Search engines and AI assistants have what they need.', 'aktio-connector' ) . '</p>';
			self::card_close();
			return;
		}
		echo '<ul class="aktio-checklist">';
		foreach ( $checks as $key => $check ) {
			$tab = $check[4] ?? '';
			echo '<li class="aktio-check" data-check="' . esc_attr( $key ) . '">';
			echo '<span class="aktio-check__icon" aria-hidden="true"></span>';
			echo '<div class="aktio-check__body"><strong>' . esc_html( $check[0] ) . '</strong><p>' . esc_html( $check[1] ) . '</p></div>';
			echo '<a class="button" href="' . esc_url( $check[2] ) . '"' . ( '' !== $tab ? ' data-tab-link="' . esc_attr( $tab ) . '"' : '' ) . '>' . esc_html( $check[3] ) . '</a>';
			echo '</li>';
		}
		echo '</ul>';
		self::card_close();
	}

	private static function render_features(): void {
		$labels   = array(
			'content_schema'   => __( 'Content schema', 'aktio-connector' ),
			'product_schema'   => __( 'Product schema', 'aktio-connector' ),
			'robots'           => __( 'robots.txt', 'aktio-connector' ),
			'llms'             => __( 'llms.txt', 'aktio-connector' ),
			'title'            => __( 'Page title', 'aktio-connector' ),
			'meta_description' => __( 'Meta description', 'aktio-connector' ),
			'open_graph'       => __( 'Open Graph', 'aktio-connector' ),
			'twitter_card'     => __( 'X (Twitter) cards', 'aktio-connector' ),
			'robots_meta'      => __( 'Robots meta', 'aktio-connector' ),
		);
		$badges   = array(
			'active'   => __( 'Active', 'aktio-connector' ),
			'provided' => __( 'Elsewhere', 'aktio-connector' ),
			'off'      => __( 'Off', 'aktio-connector' ),
		);
		$features = Detector::features();

		self::card_open( __( 'Feature status', 'aktio-connector' ), __( 'Aktio Connector never duplicates what another SEO plugin or a file on the server already provides.', 'aktio-connector' ) );
		echo '<table class="widefat striped aktio-status"><tbody>';
		foreach ( $labels as $key => $label ) {
			$feature = $features[ $key ] ?? array( 'state' => 'off', 'provided_by' => null );
			$state   = in_array( $feature['state'], array( 'provided', 'off' ), true ) ? $feature['state'] : 'active';
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td class="aktio-status__state"><span class="aktio-state aktio-state--' . esc_attr( $state ) . '">' . esc_html( $badges[ $state ] ) . '</span></td><td>';
			if ( 'provided' === $feature['state'] ) {
				if ( 'file' === $feature['provided_by'] ) {
					/* translators: %s: feature name, e.g. robots.txt */
					echo esc_html( sprintf( __( '%s comes from a physical file in the site directory — Aktio Connector does not duplicate it.', 'aktio-connector' ), $label ) );
				} else {
					/* translators: 1: feature name, 2: plugin name */
					echo esc_html( sprintf( __( '%1$s is provided by %2$s — Aktio Connector does not duplicate it.', 'aktio-connector' ), $label, (string) $feature['provided_by'] ) );
				}
			} elseif ( 'off' === $feature['state'] ) {
				echo esc_html__( 'Unavailable (requires WooCommerce).', 'aktio-connector' );
			} elseif ( null !== $feature['provided_by'] ) {
				/* translators: %s: SEO plugin name */
				echo esc_html( sprintf( __( 'Aktio Connector adds AI-bot rules to the robots.txt generated by %s.', 'aktio-connector' ), (string) $feature['provided_by'] ) );
			} else {
				echo esc_html__( 'Active — provided by Aktio Connector.', 'aktio-connector' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$seo = Detector::seo_plugins();
		if ( $seo ) {
			echo '<p class="description">' . esc_html(
				/* translators: %s: list of SEO plugin names */
				sprintf( __( 'Detected active SEO plugins: %s.', 'aktio-connector' ), implode( ', ', wp_list_pluck( $seo, 'name' ) ) )
			) . '</p>';
		}
		self::card_close();
	}

	/** Pliki, które czytają wyszukiwarki i boty AI — do szybkiego podglądu. */
	private static function render_quick_links(): void {
		$links = array(
			array( home_url( '/robots.txt' ), 'robots.txt', __( 'Rules for search engine and AI bots.', 'aktio-connector' ) ),
		);
		if ( 'off' !== ( Detector::features()['llms']['state'] ?? 'off' ) ) {
			$links[] = array( home_url( '/llms.txt' ), 'llms.txt', __( 'A short summary of the site written for AI assistants.', 'aktio-connector' ) );
		}
		$sitemap = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : false;
		if ( $sitemap ) {
			$links[] = array( $sitemap, __( 'XML sitemap', 'aktio-connector' ), __( 'The list of addresses search engines should visit.', 'aktio-connector' ) );
		}

		self::card_open( __( 'Check what bots see', 'aktio-connector' ) );
		echo '<ul class="aktio-links">';
		foreach ( $links as $link ) {
			echo '<li><a href="' . esc_url( $link[0] ) . '" target="_blank" rel="noopener">' . esc_html( $link[1] ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'aktio-connector' ) . '</span></a> <span class="description">' . esc_html( $link[2] ) . '</span></li>';
		}
		echo '</ul>';
		self::card_close();
	}

	// ------------------------------------------------------------------ Firma

	private static function render_company( array $company ): void {
		$address = $company['address'];
		$local   = 'LocalBusiness' === $company['type'];

		self::card_open( __( 'Basic details', 'aktio-connector' ), __( 'The basics shown with your name in search results and AI answers.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			'aktio-company-name',
			__( 'Company name', 'aktio-connector' ),
			self::input( 'aktio-company-name', 'company[name]', (string) $company['name'], 'text', 'regular-text', ' maxlength="100"' ),
			__( 'Your business or organization name. Empty means the site title.', 'aktio-connector' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'Type', 'aktio-connector' ) . '</th><td>';
		echo '<fieldset class="aktio-choice"><legend class="screen-reader-text">' . esc_html__( 'Type', 'aktio-connector' ) . '</legend>';
		$types = array(
			'Organization'  => array( __( 'Company or organization', 'aktio-connector' ), __( 'An online shop, a service company, an association. Customers do not visit you at a fixed place.', 'aktio-connector' ) ),
			'LocalBusiness' => array( __( 'Local business', 'aktio-connector' ), __( 'A place customers visit: a shop, farm, office or workshop. Adds the address, opening hours and location.', 'aktio-connector' ) ),
		);
		foreach ( $types as $value => $text ) {
			echo '<label class="aktio-choice__option"><input type="radio" id="aktio-company-type-' . esc_attr( strtolower( $value ) ) . '" name="company[type]" value="' . esc_attr( $value ) . '"' . checked( $company['type'], $value, false ) . ' />'
				. '<span class="aktio-choice__text"><strong>' . esc_html( $text[0] ) . '</strong><span class="description">' . esc_html( $text[1] ) . '</span></span></label>';
		}
		echo '</fieldset></td></tr>';
		self::row(
			'aktio-company-logo',
			__( 'Logo', 'aktio-connector' ),
			self::input( 'aktio-company-logo', 'company[logo]', (string) $company['logo'], 'url', 'regular-text' ) . ' '
				. self::image_button( 'aktio-company-logo', __( 'Choose the logo', 'aktio-connector' ) ),
			__( 'A square image, at least 112 × 112 px. Empty means the site logo from your theme.', 'aktio-connector' )
		);
		self::row(
			'aktio-company-description',
			__( 'Description', 'aktio-connector' ),
			'<textarea id="aktio-company-description" name="company[description]" rows="3" maxlength="300" class="large-text" data-counter="300">' . esc_textarea( (string) $company['description'] ) . '</textarea>',
			__( 'Two or three sentences about what you do and for whom, up to 300 characters. AI assistants read it first.', 'aktio-connector' )
		);
		self::row( 'aktio-company-phone', __( 'Phone', 'aktio-connector' ), self::input( 'aktio-company-phone', 'company[phone]', (string) $company['phone'], 'tel', 'regular-text' ), __( 'With the country code, for example +48 600 000 000.', 'aktio-connector' ) );
		self::row( 'aktio-company-email', __( 'Email', 'aktio-connector' ), self::input( 'aktio-company-email', 'company[email]', (string) $company['email'], 'email', 'regular-text' ) );
		echo '</tbody></table>';
		self::card_close();

		self::card_open( __( 'Address', 'aktio-connector' ), __( 'Required for a local business. For an online-only company, it is optional.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( 'aktio-company-address-street', __( 'Street and number', 'aktio-connector' ), self::input( 'aktio-company-address-street', 'company[address][street]', (string) $address['street'], 'text', 'regular-text' ) );
		self::row( 'aktio-company-address-postal_code', __( 'Postal code', 'aktio-connector' ), self::input( 'aktio-company-address-postal_code', 'company[address][postal_code]', (string) $address['postal_code'], 'text', 'small-text aktio-postal' ) );
		self::row( 'aktio-company-address-city', __( 'City', 'aktio-connector' ), self::input( 'aktio-company-address-city', 'company[address][city]', (string) $address['city'], 'text', 'regular-text' ) );
		if ( self::woocommerce_available() ) {
			self::row( 'aktio-company-address-country', __( 'Country', 'aktio-connector' ), self::country_select( 'aktio-company-address-country', 'company[address][country]', (string) $address['country'], __( '— Choose a country —', 'aktio-connector' ) ) );
		} else {
			self::row( 'aktio-company-address-country', __( 'Country', 'aktio-connector' ), self::input( 'aktio-company-address-country', 'company[address][country]', (string) $address['country'], 'text', 'small-text', ' maxlength="56" placeholder="PL"' ), __( 'A two-letter country code, for example PL or DE.', 'aktio-connector' ) );
		}
		echo '</tbody></table>';
		self::card_close();

		self::card_open( __( 'Opening hours and location', 'aktio-connector' ), __( 'Shown only for a local business. Google uses them in Maps and in answers like "is it open now".', 'aktio-connector' ), ' data-show-when-type="LocalBusiness"' . ( $local ? '' : ' hidden' ) );
		self::render_hours( $company['opening_hours'] );
		$geo = is_array( $company['geo'] ) ? $company['geo'] : array( 'lat' => '', 'lng' => '' );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			'aktio-company-geo-lat',
			__( 'Latitude', 'aktio-connector' ),
			self::input( 'aktio-company-geo-lat', 'company[geo][lat]', (string) $geo['lat'], 'text', 'regular-text aktio-coord', ' inputmode="decimal"' )
		);
		self::row(
			'aktio-company-geo-lng',
			__( 'Longitude', 'aktio-connector' ),
			self::input( 'aktio-company-geo-lng', 'company[geo][lng]', (string) $geo['lng'], 'text', 'regular-text aktio-coord', ' inputmode="decimal"' ),
			__( 'In Google Maps, right-click your place: the two numbers at the top of the menu are the latitude and longitude, for example 54.4386 and 16.9876. Leave both empty to skip.', 'aktio-connector' )
		);
		echo '</tbody></table>';
		self::card_close();

		$cp = $company['contact_point'];
		self::card_open( __( 'Extended company data', 'aktio-connector' ), __( 'Optional details that help AI assistants answer questions about your business.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( 'aktio-company-founding_date', __( 'Founding date', 'aktio-connector' ), self::input( 'aktio-company-founding_date', 'company[founding_date]', (string) $company['founding_date'], 'date', '' ) );
		self::row( 'aktio-company-area_served', __( 'Area served', 'aktio-connector' ), self::input( 'aktio-company-area_served', 'company[area_served]', (string) $company['area_served'], 'text', 'regular-text', ' maxlength="100"' ), __( 'Where you work or deliver, for example "Poland" or "Pomerania".', 'aktio-connector' ) );
		self::row( 'aktio-company-contact_point-telephone', __( 'Customer service phone', 'aktio-connector' ), self::input( 'aktio-company-contact_point-telephone', 'company[contact_point][telephone]', (string) $cp['telephone'], 'tel', 'regular-text' ) );
		self::row( 'aktio-company-contact_point-email', __( 'Customer service email', 'aktio-connector' ), self::input( 'aktio-company-contact_point-email', 'company[contact_point][email]', (string) $cp['email'], 'email', 'regular-text' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Customer service languages', 'aktio-connector' ) . '</th><td>';
		echo '<fieldset class="aktio-chips" data-max="5"><legend class="screen-reader-text">' . esc_html__( 'Customer service languages', 'aktio-connector' ) . '</legend>';
		// Ukryte puste pole: gdy nic nie jest zaznaczone, zapis czyści listę.
		echo '<input type="hidden" name="company[contact_point][languages]" value="" />';
		$chosen  = array_map( 'strval', array_values( (array) $cp['languages'] ) );
		$options = array();
		foreach ( self::SERVICE_LANGUAGES as $code ) {
			$options[ $code ] = self::LANGUAGES[ $code ];
		}
		foreach ( $chosen as $value ) {
			if ( ! isset( $options[ $value ] ) ) {
				$options[ $value ] = $value;
			}
		}
		foreach ( $options as $code => $label ) {
			echo '<label class="aktio-chip"><input type="checkbox" name="company[contact_point][languages][]" value="' . esc_attr( (string) $code ) . '"' . checked( in_array( (string) $code, $chosen, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</fieldset><p class="description">' . esc_html__( 'Up to 5 languages in which customers can contact you.', 'aktio-connector' ) . '</p></td></tr>';
		self::row(
			'aktio-company-same-as',
			__( 'Social profiles', 'aktio-connector' ),
			'<textarea id="aktio-company-same-as" name="company[same_as]" rows="4" class="large-text code" placeholder="https://www.facebook.com/…">' . esc_textarea( implode( "\n", $company['same_as'] ) ) . '</textarea>',
			__( 'Addresses of your profiles (Facebook, Instagram, LinkedIn, Google Business Profile), one per line, up to 10. They confirm that the profiles belong to you.', 'aktio-connector' )
		);
		echo '</tbody></table>';
		self::card_close();
	}

	/**
	 * Wiersze godzin otwarcia: zapisane + jeden pusty; szablon <template> dla przycisku „Dodaj”.
	 * Ukryte puste pole przed wierszami: usunięcie wszystkich wierszy czyści godziny.
	 */
	private static function render_hours( array $hours ): void {
		echo '<div class="aktio-hours" data-max="7">';
		echo '<input type="hidden" name="company[opening_hours]" value="" />';
		echo '<h3 class="aktio-hours__label">' . esc_html__( 'Opening hours', 'aktio-connector' ) . '</h3>';
		echo '<div class="aktio-hours__rows">';
		$rows   = array_values( $hours );
		$rows[] = array( 'days' => array(), 'opens' => '', 'closes' => '' );
		foreach ( array_slice( $rows, 0, 7 ) as $i => $row ) {
			echo self::hours_row( (string) $i, $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapowane w hours_row().
		}
		echo '</div>';
		echo '<template class="aktio-hours__template">' . self::hours_row( '__i__', array( 'days' => array(), 'opens' => '', 'closes' => '' ) ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapowane w hours_row().
		echo '<p><button type="button" class="button aktio-hours__add">' . esc_html__( 'Add opening hours', 'aktio-connector' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'One row per group of days with the same hours, for example Monday–Friday 9:00–17:00 and Saturday 10:00–14:00. Days without a row are treated as closed.', 'aktio-connector' ) . '</p>';
		echo '</div>';
	}

	private static function hours_row( string $index, array $row ): string {
		global $wp_locale;
		$name = 'company[opening_hours][' . $index . ']';
		$days = (array) ( $row['days'] ?? array() );
		$map  = array( 'Mo' => 1, 'Tu' => 2, 'We' => 3, 'Th' => 4, 'Fr' => 5, 'Sa' => 6, 'Su' => 0 );
		$html = '<fieldset class="aktio-hours__row"><legend class="screen-reader-text">' . esc_html__( 'Days and hours', 'aktio-connector' ) . '</legend><span class="aktio-hours__days">';
		foreach ( $map as $code => $number ) {
			$full  = $wp_locale ? $wp_locale->get_weekday( $number ) : $code;
			$short = $wp_locale ? $wp_locale->get_weekday_abbrev( $full ) : $code;
			$html .= '<label class="aktio-day" title="' . esc_attr( $full ) . '"><input type="checkbox" name="' . esc_attr( $name . '[days][]' ) . '" value="' . esc_attr( $code ) . '"' . checked( in_array( $code, $days, true ), true, false ) . ' /><span aria-hidden="true">' . esc_html( $short ) . '</span><span class="screen-reader-text">' . esc_html( $full ) . '</span></label>';
		}
		$html .= '</span><span class="aktio-hours__times">'
			. '<label><span class="screen-reader-text">' . esc_html__( 'Opens', 'aktio-connector' ) . '</span><input type="time" name="' . esc_attr( $name . '[opens]' ) . '" value="' . esc_attr( (string) ( $row['opens'] ?? '' ) ) . '" /></label>'
			. '<span aria-hidden="true">–</span>'
			. '<label><span class="screen-reader-text">' . esc_html__( 'Closes', 'aktio-connector' ) . '</span><input type="time" name="' . esc_attr( $name . '[closes]' ) . '" value="' . esc_attr( (string) ( $row['closes'] ?? '' ) ) . '" /></label>'
			. '</span><button type="button" class="button-link aktio-hours__remove">' . esc_html__( 'Remove', 'aktio-connector' ) . '</button></fieldset>';
		return $html;
	}

	// ------------------------------------------------------------------ SEO

	/** Sekcja SEO: opis strony głównej i domyślny obrazek OG (biblioteka mediów). */
	private static function render_seo( array $seo ): void {
		self::card_open( __( 'SEO', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			'aktio-seo-home-description',
			__( 'Home page description', 'aktio-connector' ),
			'<textarea id="aktio-seo-home-description" name="seo[home_description]" rows="3" maxlength="160" class="large-text" data-counter="160">' . esc_textarea( (string) $seo['home_description'] ) . '</textarea>',
			__( 'Meta description of the home page, up to 160 characters. Used only when no SEO plugin provides it.', 'aktio-connector' )
		);
		self::row(
			'aktio-seo-default-og-image',
			__( 'Default social image', 'aktio-connector' ),
			self::input( 'aktio-seo-default-og-image', 'seo[default_og_image]', (string) $seo['default_og_image'], 'url', 'regular-text' ) . ' '
				. self::image_button( 'aktio-seo-default-og-image', __( 'Choose the default social image', 'aktio-connector' ) ),
			__( 'Shown when a page or product has no image of its own. Must be an https:// address; leave empty for none.', 'aktio-connector' )
		);
		echo '</tbody></table>';
		self::card_close();
	}

	/** Język treści strony (lista) + ostrzeżenie z heurystyki Language::warning(). */
	private static function render_language( string $language ): void {
		self::card_open( __( 'Content language', 'aktio-connector' ) );
		$warning = Language::warning();
		if ( $warning ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				/* translators: 1: detected language code, 2: configured content language */
				sprintf( __( 'Your content looks like it is written in "%1$s", but the content language is set to "%2$s". Check the setting below.', 'aktio-connector' ), (string) $warning['detected'], (string) $warning['expected'] )
			) . '</p></div>';
		}
		$stored  = get_option( Settings::OPTION, array() );
		$own     = is_array( $stored ) && isset( $stored['content_language'] ) && is_string( $stored['content_language'] ) ? $stored['content_language'] : '';
		$current = '' === $own ? '' : $language;
		$options = array(
			/* translators: %s: site language code, e.g. pl-PL */
			'' => sprintf( __( 'Same as the site language (%s)', 'aktio-connector' ), str_replace( '_', '-', get_locale() ) ),
		);
		foreach ( self::LANGUAGES as $code => $label ) {
			$options[ $code ] = $label . ' — ' . $code;
		}
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			'aktio-content-language',
			__( 'Language of the site content', 'aktio-connector' ),
			self::select( 'aktio-content-language', 'content_language', $current, $options ),
			__( 'The language your pages are written in. It sets the lang attribute, og:locale, schema inLanguage and llms.txt; the admin panel stays in your language. Change it only when the content is in a different language than the admin panel.', 'aktio-connector' )
		);
		echo '</tbody></table>';
		self::card_close();
	}

	/** Kody weryfikacyjne Bing Webmaster Tools i Google Search Console. */
	private static function render_verification( array $verification ): void {
		self::card_open( __( 'Search engine verification', 'aktio-connector' ), __( 'Confirms you own the site, so you can see its statistics in Bing Webmaster Tools and Google Search Console.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			'aktio-verification-bing',
			__( 'Bing Webmaster Tools code', 'aktio-connector' ),
			self::input( 'aktio-verification-bing', 'verification[bing]', (string) $verification['bing'], 'text', 'regular-text code', ' maxlength="32"' ),
			__( 'The msvalidate.01 content value: 32 uppercase hexadecimal characters.', 'aktio-connector' )
		);
		self::row(
			'aktio-verification-google',
			__( 'Google Search Console code', 'aktio-connector' ),
			self::input( 'aktio-verification-google', 'verification[google]', (string) $verification['google'], 'text', 'regular-text code', ' maxlength="100"' ),
			__( 'The google-site-verification content value. Added only when no SEO plugin or Site Kit provides it.', 'aktio-connector' )
		);
		echo '</tbody></table>';
		self::card_close();
	}

	// ------------------------------------------------------------------ Sklep

	/** Pola sprzedawcy mają sens tylko ze sklepem; żadnej funkcji WC nie wołamy bez tej bramki. */
	private static function woocommerce_available(): bool {
		return ! self::$assume_no_woocommerce && class_exists( 'WooCommerce' ) && function_exists( 'get_woocommerce_currency' );
	}

	/**
	 * Sekcja „Merchant”: marka domyślna, polityka zwrotów i wysyłka zastępcza.
	 */
	private static function render_merchant( array $merchant, string $company_name ): void {
		$rp    = $merchant['return_policy'];
		$sh    = $merchant['shipping'];
		$store = self::store_country_label();

		self::card_open( __( 'Merchant', 'aktio-connector' ), __( 'Used when they cannot be read from WooCommerce. You declare this data to Google — enter it in line with your store terms.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			'aktio-merchant-brand',
			__( 'Default brand', 'aktio-connector' ),
			'<input type="text" id="aktio-merchant-brand" name="merchant[default_brand]" value="' . esc_attr( (string) $merchant['default_brand'] ) . '" placeholder="' . esc_attr( $company_name ) . '" maxlength="100" class="regular-text" />',
			__( 'Used for products without their own brand. Empty means no default brand.', 'aktio-connector' )
		);
		echo '</tbody></table>';
		self::card_close();

		self::card_open( __( 'Return policy', 'aktio-connector' ), __( 'Google shows the return window and costs next to your products.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( 'aktio-merchant-rp-enabled', __( 'Declare a return policy', 'aktio-connector' ), self::checkbox( 'aktio-merchant-rp-enabled', 'merchant[return_policy][enabled]', (bool) $rp['enabled'], __( 'Add the return policy to product offers', 'aktio-connector' ) ) );
		self::row( 'aktio-merchant-rp-days', __( 'Return window (days)', 'aktio-connector' ), self::number( 'aktio-merchant-rp-days', 'merchant[return_policy][days]', (int) $rp['days'], 0, 365 ), __( '0 means returns are not accepted. Consumers in the EU have at least 14 days.', 'aktio-connector' ) );
		self::row(
			'aktio-merchant-rp-fees',
			__( 'Return costs', 'aktio-connector' ),
			self::select(
				'aktio-merchant-rp-fees',
				'merchant[return_policy][fees]',
				(string) $rp['fees'],
				array(
					'customer' => __( 'Paid by the customer', 'aktio-connector' ),
					'free'     => __( 'Free returns', 'aktio-connector' ),
				)
			)
		);
		self::row( 'aktio-merchant-rp-country', __( 'Country', 'aktio-connector' ), self::country_select( 'aktio-merchant-rp-country', 'merchant[return_policy][country]', self::own_country( 'return_policy', (string) $rp['country'] ), $store ), __( 'The country where returns are accepted. To stop declaring this data, clear the checkbox above.', 'aktio-connector' ) );
		self::row(
			'aktio-merchant-rp-method',
			__( 'Return method', 'aktio-connector' ),
			self::select(
				'aktio-merchant-rp-method',
				'merchant[return_policy][method]',
				(string) $rp['method'],
				array(
					'mail'          => __( 'By mail', 'aktio-connector' ),
					'store'         => __( 'In store', 'aktio-connector' ),
					'mail_or_store' => __( 'By mail or in store', 'aktio-connector' ),
				)
			)
		);
		echo '</tbody></table>';
		self::card_close();

		self::card_open( __( 'Fallback shipping', 'aktio-connector' ), __( 'Used for products when WooCommerce shipping zones do not give a cost Google can read.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row( 'aktio-merchant-sh-enabled', __( 'Declare fallback shipping', 'aktio-connector' ), self::checkbox( 'aktio-merchant-sh-enabled', 'merchant[shipping][enabled]', (bool) $sh['enabled'], __( 'Add shipping details when WooCommerce zones do not provide them', 'aktio-connector' ) ) );
		self::row( 'aktio-merchant-sh-country', __( 'Destination country', 'aktio-connector' ), self::country_select( 'aktio-merchant-sh-country', 'merchant[shipping][country]', self::own_country( 'shipping', (string) $sh['country'] ), $store ), __( 'The country you ship to. To stop declaring this data, clear the checkbox above.', 'aktio-connector' ) );
		self::row(
			'aktio-merchant-sh-cost',
			/* translators: %s: shop currency code, e.g. PLN */
			sprintf( __( 'Shipping cost (%s)', 'aktio-connector' ), get_woocommerce_currency() ),
			'<input type="text" id="aktio-merchant-sh-cost" name="merchant[shipping][cost]" value="' . esc_attr( (string) $sh['cost'] ) . '" maxlength="10" inputmode="decimal" class="small-text" />',
			__( 'Amount in the shop currency, up to 2 decimal places, for example 12.50. 0.00 means free shipping.', 'aktio-connector' )
		);
		self::row( 'aktio-merchant-sh-handling-0', __( 'Handling time (days)', 'aktio-connector' ), self::range( 'aktio-merchant-sh-handling', 'merchant[shipping][handling_days]', $sh['handling_days'], 30 ), __( 'From – to, 0 to 30 days: how long you need to pack and hand over an order.', 'aktio-connector' ) );
		self::row( 'aktio-merchant-sh-transit-0', __( 'Delivery time (days)', 'aktio-connector' ), self::range( 'aktio-merchant-sh-transit', 'merchant[shipping][transit_days]', $sh['transit_days'], 60 ), __( 'From – to, 0 to 60 days: how long the carrier takes.', 'aktio-connector' ) );
		echo '</tbody></table>';
		self::card_close();
	}

	/** Kraj zapisany przez użytkownika ('' = kraj sklepu — nie zamrażamy go przy zapisie ekranu). */
	private static function own_country( string $section, string $resolved ): string {
		$stored = get_option( Settings::OPTION, array() );
		$own    = is_array( $stored ) && isset( $stored['merchant'][ $section ]['country'] ) && is_string( $stored['merchant'][ $section ]['country'] ) ? $stored['merchant'][ $section ]['country'] : '';
		return '' === $own ? '' : $resolved;
	}

	private static function store_country_label(): string {
		$code  = Settings::shop_country();
		$names = self::countries();
		$name  = isset( $names[ $code ] ) ? html_entity_decode( (string) $names[ $code ], ENT_QUOTES, 'UTF-8' ) : $code;
		// Część nazw WooCommerce ma już kod w nawiasie („United States (US)”).
		if ( isset( $names[ $code ] ) && false === strpos( $name, '(' . $code . ')' ) ) {
			$name .= ' (' . $code . ')';
		}
		/* translators: %s: store country name and code, e.g. Poland (PL) */
		return sprintf( __( 'Store country: %s', 'aktio-connector' ), $name );
	}

	/** Kraje z WooCommerce (przetłumaczone): kod ISO → nazwa. */
	private static function countries(): array {
		if ( ! self::woocommerce_available() || ! function_exists( 'WC' ) || ! WC()->countries ) {
			return array();
		}
		return (array) WC()->countries->get_countries();
	}

	/** Lista krajów z pustą opcją; wartość spoza listy (dawny wpis tekstem) zostaje jako wybrana. */
	private static function country_select( string $id, string $name, string $value, string $empty_label ): string {
		$options = array( '' => $empty_label );
		foreach ( self::countries() as $code => $label ) {
			$options[ (string) $code ] = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
		}
		if ( '' !== $value && ! isset( $options[ $value ] ) ) {
			$options[ $value ] = $value;
		}
		return self::select( $id, $name, $value, $options );
	}

	// ------------------------------------------------------------------ Boty i indeksowanie

	/** Boty AI: przełącznik „dopuszczony” + kto za nim stoi i co robi. */
	private static function render_bots( array $bots ): void {
		$about = array(
			'GPTBot'            => array( 'OpenAI', __( 'Collects content to train ChatGPT models. Blocking it does not remove you from ChatGPT search.', 'aktio-connector' ) ),
			'OAI-SearchBot'     => array( 'OpenAI', __( 'Indexes pages for ChatGPT search, so ChatGPT can cite and link to you. Blocking it removes you from those answers.', 'aktio-connector' ) ),
			'ClaudeBot'         => array( 'Anthropic', __( 'Collects content to train Claude models.', 'aktio-connector' ) ),
			'PerplexityBot'     => array( 'Perplexity', __( 'Indexes pages for Perplexity answers, which link back to the sources.', 'aktio-connector' ) ),
			'Google-Extended'   => array( 'Google', __( 'Lets Gemini and Google AI training use your content. It does not affect Google Search or AI Overviews.', 'aktio-connector' ) ),
			'Applebot-Extended' => array( 'Apple', __( 'Lets Apple Intelligence training use your content. It does not affect Siri or Spotlight.', 'aktio-connector' ) ),
		);
		self::card_open( __( 'AI bots in robots.txt', 'aktio-connector' ), __( 'Allowed bots can read your site; blocked ones get a Disallow rule in robots.txt. Well-behaved bots respect it. Keep search bots allowed if you want AI assistants to recommend you.', 'aktio-connector' ) );
		echo '<ul class="aktio-bots">';
		foreach ( Settings::BOTS as $bot ) {
			$allowed = 'block' !== ( $bots[ $bot ] ?? 'allow' );
			$id      = 'aktio-bot-' . sanitize_html_class( $bot );
			$info    = $about[ $bot ] ?? array( '', '' );
			echo '<li class="aktio-bot">';
			echo '<div class="aktio-bot__text"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $bot ) . '</strong></label>';
			if ( '' !== $info[0] ) {
				echo ' <span class="aktio-badge aktio-badge--muted">' . esc_html( $info[0] ) . '</span>';
			}
			echo '<p class="description" id="' . esc_attr( $id ) . '-desc">' . esc_html( $info[1] ) . '</p></div>';
			// Ukryta wartość „block” jest wysyłana, gdy przełącznik jest wyłączony.
			echo '<input type="hidden" name="bots[' . esc_attr( $bot ) . ']" value="block" />';
			echo '<span class="aktio-switch"><input type="checkbox" role="switch" id="' . esc_attr( $id ) . '" name="bots[' . esc_attr( $bot ) . ']" value="allow" aria-describedby="' . esc_attr( $id ) . '-desc"' . checked( $allowed, true, false ) . ' />'
				. '<span class="aktio-switch__track" aria-hidden="true"></span>'
				. '<span class="aktio-switch__on" aria-hidden="true">' . esc_html__( 'Allowed', 'aktio-connector' ) . '</span>'
				. '<span class="aktio-switch__off" aria-hidden="true">' . esc_html__( 'Blocked', 'aktio-connector' ) . '</span></span>';
			echo '</li>';
		}
		echo '</ul>';
		self::card_close();
	}

	/** IndexNow: przełącznik z opisem usługi, dziennik i przycisk zgłoszenia całej mapy. */
	private static function render_indexnow( array $indexnow ): void {
		self::card_open( __( 'IndexNow', 'aktio-connector' ), __( 'Tells Bing and other IndexNow search engines about new and changed pages within minutes instead of days. Bing results also feed Copilot and other AI assistants.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::row(
			'aktio-indexnow-enabled',
			__( 'Report changes', 'aktio-connector' ),
			self::checkbox( 'aktio-indexnow-enabled', 'indexnow[enabled]', (bool) $indexnow['enabled'], __( 'Tell search engines about new and changed pages', 'aktio-connector' ) ),
			__( 'IndexNow reports the addresses of new and changed pages to Bing and other IndexNow search engines. It does not send page content or visitor data. Nothing is sent while the site is hidden from search engines or for pages marked noindex.', 'aktio-connector' )
		);
		$last = IndexNow::last();
		$log  = IndexNow::log( 10 );
		$html = '<p>' . esc_html(
			/* translators: %s: date and time of the last successful submission, or a dash */
			sprintf( __( 'Last successful submission: %s', 'aktio-connector' ), null !== $last ? $last : '—' )
		) . '</p>';
		if ( $log ) {
			$html .= '<table class="widefat striped aktio-table"><thead><tr>';
			foreach ( array( __( 'Date (UTC)', 'aktio-connector' ), __( 'Addresses', 'aktio-connector' ), __( 'Response code', 'aktio-connector' ) ) as $head ) {
				$html .= '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			$html .= '</tr></thead><tbody>';
			foreach ( $log as $entry ) {
				$code  = is_array( $entry ) && isset( $entry['code'] ) && null !== $entry['code'] ? (string) $entry['code'] : '—';
				$html .= '<tr><td>' . esc_html( (string) ( $entry['time'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $entry['count'] ?? '' ) ) . '</td><td>' . esc_html( $code ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		} else {
			$html .= '<p>' . esc_html__( 'No submissions yet.', 'aktio-connector' ) . '</p>';
		}
		$html .= '<p><button type="submit" form="aktio-sitemap-form" class="button">' . esc_html__( 'Submit the whole sitemap', 'aktio-connector' ) . '</button></p>'
			. '<p class="description">' . esc_html__( 'One-off submission of all public pages. Available once every 24 hours and only when IndexNow is enabled.', 'aktio-connector' ) . '</p>';
		self::row( 'aktio-indexnow-log', __( 'Submissions', 'aktio-connector' ), $html );
		echo '</tbody></table>';
		self::card_close();
	}

	// ------------------------------------------------------------------ Widoczność, narzędzia, O Aktio

	/** Pomiar widoczności w AI: wizyty botów i wejścia z wyszukiwarek AI (30 dni), top ścieżki. */
	private static function render_metrics(): void {
		$report = Metrics::report( 30 );
		self::card_open( __( 'AI visibility', 'aktio-connector' ), __( 'Last 30 days. Bot visits are counted by PHP, so pages served from a page cache or CDN are not counted: the numbers are a lower bound when caching is on.', 'aktio-connector' ) );
		echo '<div class="aktio-grid"><div>';
		self::count_table( __( 'AI bot visits', 'aktio-connector' ), __( 'Bot', 'aktio-connector' ), $report['bots'] );
		echo '</div><div>';
		self::count_table( __( 'Visits from AI search', 'aktio-connector' ), __( 'Source', 'aktio-connector' ), $report['referrals'] );
		echo '</div></div>';

		echo '<h3>' . esc_html__( 'Top landing pages from AI', 'aktio-connector' ) . '</h3>';
		if ( ! $report['top_paths'] ) {
			echo '<p class="aktio-empty">' . esc_html__( 'No data yet.', 'aktio-connector' ) . '</p>';
			self::card_close();
			return;
		}
		echo '<table class="widefat striped aktio-table"><thead><tr><th scope="col">' . esc_html__( 'Page', 'aktio-connector' ) . '</th><th scope="col" class="num">' . esc_html__( 'Visits', 'aktio-connector' ) . '</th></tr></thead><tbody>';
		foreach ( $report['top_paths'] as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['path'] ) . '</td><td class="num">' . esc_html( (string) $row['count'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::card_close();
	}

	/** Tabela nazwa → suma wizyt z serii dziennych [[dzień, liczba], ...]. */
	private static function count_table( string $title, string $column, array $series ): void {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $series ) {
			echo '<p class="aktio-empty">' . esc_html__( 'No data yet.', 'aktio-connector' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped aktio-table"><thead><tr><th scope="col">' . esc_html( $column ) . '</th><th scope="col" class="num">' . esc_html__( 'Visits', 'aktio-connector' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $name => $days ) {
			$total = array_sum( array_column( $days, 1 ) );
			echo '<tr><td>' . esc_html( (string) $name ) . '</td><td class="num">' . esc_html( (string) $total ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Cache: wykryta wtyczka, ostatnie czyszczenie, przycisk „Wyczyść teraz”. */
	private static function render_cache(): void {
		$detected = CachePurge::detected();
		$last     = CachePurge::last();
		self::card_open( __( 'Cache', 'aktio-connector' ), __( 'A caching plugin keeps ready copies of pages. After changes here they have to be refreshed, otherwise visitors and bots see the old version.', 'aktio-connector' ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		if ( null === $detected ) {
			self::row( 'aktio-cache-plugin', __( 'Detected cache plugin', 'aktio-connector' ), esc_html__( 'No supported cache plugin detected.', 'aktio-connector' ) );
		} else {
			self::row( 'aktio-cache-plugin', __( 'Detected cache plugin', 'aktio-connector' ), esc_html( $detected ) );
		}
		self::row( 'aktio-cache-last', __( 'Last cache clearing', 'aktio-connector' ), esc_html( null !== $last ? $last : '—' ) );
		self::row(
			'aktio-cache-clear',
			__( 'Clear the cache', 'aktio-connector' ),
			'<button type="submit" form="aktio-cache-form" id="aktio-cache-clear" class="button">' . esc_html__( 'Clear now', 'aktio-connector' ) . '</button>',
			__( 'The cache is cleared automatically after saving these settings.', 'aktio-connector' )
		);
		echo '</tbody></table>';
		self::card_close();
	}

	private static function render_log(): void {
		$log = Updates::log( 10 );
		self::card_open( __( 'Update log', 'aktio-connector' ), __( 'Updates of WordPress, plugins and themes applied through Aktio Connector (for example by the Aktio agent).', 'aktio-connector' ) );
		if ( ! $log ) {
			echo '<p class="aktio-empty">' . esc_html__( 'No entries.', 'aktio-connector' ) . '</p>';
			self::card_close();
			return;
		}
		echo '<table class="widefat striped aktio-table"><thead><tr>';
		foreach ( array( __( 'Date (UTC)', 'aktio-connector' ), __( 'Item', 'aktio-connector' ), __( 'Version', 'aktio-connector' ), __( 'Result', 'aktio-connector' ), __( 'By', 'aktio-connector' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $log as $entry ) {
			$name = isset( $entry['name'] ) && '' !== (string) $entry['name'] ? (string) $entry['name'] : (string) ( $entry['item'] ?? '' );
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $entry['at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['from'] ?? '' ) . ' → ' . (string) ( $entry['to'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['result'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['by'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		self::card_close();
	}

	/** Link do aktioapp.com w nowej karcie (z opisem dla czytników ekranu). */
	private static function aktio_link( string $path, string $text, string $class = '' ): string {
		return '<a' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( self::AKTIO_URL . $path ) . '" target="_blank" rel="noopener">'
			. esc_html( $text ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'aktio-connector' ) . '</span></a>';
	}

	/** Zakładka „O Aktio”: czym jest Aktio, co daje razem z wtyczką, jak się łączy. */
	private static function render_about(): void {
		echo '<div class="aktio-card aktio-about">';
		echo '<div class="aktio-about__hero">';
		echo '<img src="' . esc_url( self::logo_url() ) . '" width="72" height="72" alt="Aktio" />';
		echo '<div><h2>' . esc_html__( 'About Aktio', 'aktio-connector' ) . '</h2>';
		echo '<p class="aktio-about__lead">' . esc_html__( 'Aktio is a platform where an AI agent takes routine work off your hands: it looks after your website and online store, publishes content, creates tasks and reminds you of what matters. In Polish, also by voice.', 'aktio-connector' ) . '</p></div>';
		echo '</div>';

		echo '<div class="aktio-grid">';
		echo '<div class="aktio-feature"><h3>' . esc_html__( 'AI agent for WordPress', 'aktio-connector' ) . '</h3><p>' . esc_html__( 'Knows the numbers of your WooCommerce store, writes content, fills in missing image ALT texts, checks product data, applies updates and reports changes to IndexNow. It saves nothing until you say yes.', 'aktio-connector' ) . '</p>'
			. self::aktio_link( 'agent-wordpress/', __( 'How the agent works', 'aktio-connector' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link escapowany w aktio_link().
		echo '<div class="aktio-feature"><h3>' . esc_html__( 'Visibility in AI', 'aktio-connector' ) . '</h3><p>' . esc_html__( 'Checks whether ChatGPT, Gemini, Perplexity and Google AI Overviews mention your brand and cite your pages, with history and comparisons over time.', 'aktio-connector' ) . '</p>'
			. self::aktio_link( 'widocznosc-w-ai/', __( 'See AI visibility monitoring', 'aktio-connector' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link escapowany w aktio_link().
		echo '</div>';

		echo '<p class="aktio-about__cta">' . self::aktio_link( '', __( 'Visit aktioapp.com', 'aktio-connector' ), 'button button-primary' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link escapowany w aktio_link().
		echo '</div>';

		self::card_open( __( 'How the plugin works with Aktio', 'aktio-connector' ) );
		echo '<p>' . esc_html__( 'Connection to Aktio: this plugin works on its own; Aktio uses it through an application password.', 'aktio-connector' ) . '</p>';
		echo '<p>' . esc_html__( 'The plugin never connects to Aktio by itself and sends it nothing. Aktio reaches your site only after you create an application password for it (Users → Profile → Application passwords), and you can revoke that password at any time.', 'aktio-connector' ) . '</p>';
		echo '<p class="description">' . esc_html(
			/* translators: %s: plugin version */
			sprintf( __( 'Aktio Connector %s · free, GPLv2 or later', 'aktio-connector' ), AKTIO_CONNECTOR_VERSION )
		) . '</p>';
		self::card_close();
	}

	// ------------------------------------------------------------------ Pomocniki pól

	/** Wiersz tabeli; $control to gotowy, już escapowany HTML pola. */
	private static function row( string $id, string $label, string $control, string $description = '' ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML zbudowany z esc_attr()/esc_html() w pomocnikach pól.
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Pole tekstowe.
	 *
	 * @param string $extra Stałe atrybuty z kodu (nie z danych).
	 */
	private static function input( string $id, string $name, string $value, string $type, string $class, string $extra = '' ): string {
		return '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . $extra . ' />';
	}

	private static function image_button( string $target, string $title ): string {
		return '<button type="button" class="button aktio-choose-image" data-target="' . esc_attr( $target ) . '" data-title="' . esc_attr( $title ) . '" data-button="' . esc_attr__( 'Use this image', 'aktio-connector' ) . '">' . esc_html__( 'Choose image', 'aktio-connector' ) . '</button>';
	}

	/** Checkbox z ukrytym zerem, żeby odznaczenie dotarło do serwera. */
	private static function checkbox( string $id, string $name, bool $checked, string $text ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />'
			. '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . ' /> ' . esc_html( $text ) . '</label>';
	}

	private static function number( string $id, string $name, int $value, int $min, int $max ): string {
		return '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="1" class="small-text" />';
	}

	private static function select( string $id, string $name, string $value, array $options ): string {
		$html = '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $key => $label ) {
			$html .= '<option value="' . esc_attr( (string) $key ) . '"' . selected( $value, (string) $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/** Dwa pola liczbowe „od – do”; id pierwszego to $id . '-0'. */
	private static function range( string $id, string $name, array $pair, int $max ): string {
		return self::number( $id . '-0', $name . '[0]', (int) $pair[0], 0, $max ) . ' – ' . self::number( $id . '-1', $name . '[1]', (int) $pair[1], 0, $max );
	}
}
