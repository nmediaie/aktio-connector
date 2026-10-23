<?php
/**
 * Ekran Ustawienia → Aktio Connector: SEO, język treści, dane firmy, IndexNow, weryfikacja, boty AI
 * i pola sprzedawcy (edycja); cache, pomiar AI, stan funkcji i dziennik aktualizacji (tylko do odczytu).
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
	 * admin-post: nonce i uprawnienie, zapis, przekierowanie z komunikatem.
	 */
	public static function handle_save(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'aktio-connector' ), '', array( 'response' => 403 ) );
		}
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej, wartości sanityzuje Settings::update().
		$errors = self::save( is_array( $post ) ? $post : array() );
		$flag   = array( 'saved' => '1' );
		if ( $errors ) {
			// Komunikaty przechodzą przez krótkotrwały transient użytkownika (przekierowanie PRG).
			set_transient( self::errors_key(), array_values( $errors ), 5 * MINUTE_IN_SECONDS );
			$flag = array( 'invalid' => '1' );
		}
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG ), $flag ), admin_url( 'options-general.php' ) ) );
		exit;
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
		self::redirect( $flag );
	}

	/**
	 * admin-post: „Wyczyść teraz” — czyszczenie cache wykrytej wtyczki.
	 */
	public static function handle_cache_purge(): void {
		check_admin_referer( self::CACHE_NONCE, '_aktio_cache_nonce' );
		self::require_capability();
		self::redirect( array( 'aktio_notice' => CachePurge::all() ? 'cache' : 'cache_none' ) );
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

	/** Mały skrypt wp.media (wybór domyślnego obrazka OG) — tylko na ekranie ustawień. */
	public static function enqueue_assets( $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
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
	 * Zapis pól z formularza (sanityzacja w Settings::update). Błędy walidacji pól
	 * sprzedawcy blokują cały zapis (ustawienia bez zmian); zwraca ścieżka pola → komunikat.
	 */
	public static function save( array $post ): array {
		$partial = array();
		if ( isset( $post['company'] ) && is_array( $post['company'] ) ) {
			$company = $post['company'];
			if ( isset( $company['same_as'] ) && is_string( $company['same_as'] ) ) {
				$company['same_as'] = preg_split( '/\R/', $company['same_as'] ) ?: array();
			}
			if ( isset( $company['contact_point']['languages'] ) && is_string( $company['contact_point']['languages'] ) ) {
				$company['contact_point']['languages'] = array_values( array_filter( array_map( 'trim', explode( ',', $company['contact_point']['languages'] ) ), 'strlen' ) );
			}
			$partial['company'] = $company;
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

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::get();
		$company  = $settings['company'];
		$bots     = $settings['bots'];
		$address  = $company['address'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Aktio Connector', 'aktio-connector' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko komunikat.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'aktio-connector' ) . '</p></div>';
		}
		if ( isset( $_GET['invalid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko komunikat.
			self::render_errors();
		}
		self::render_notice();
		echo '<p>' . esc_html__( 'Connection to Aktio: this plugin works on its own; Aktio uses it through an application password.', 'aktio-connector' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::NONCE );

		self::render_seo( $settings['seo'] );
		self::render_language( (string) $settings['content_language'] );

		echo '<h2>' . esc_html__( 'Company', 'aktio-connector' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This data goes into schema.org markup (Organization or LocalBusiness). An empty name means the site title.', 'aktio-connector' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'name', __( 'Company name', 'aktio-connector' ), (string) $company['name'] );
		echo '<tr><th scope="row"><label for="aktio-company-type">' . esc_html__( 'Type', 'aktio-connector' ) . '</label></th><td>';
		echo '<select id="aktio-company-type" name="company[type]">';
		foreach ( Settings::TYPES as $type ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $company['type'], $type, false ) . '>' . esc_html( $type ) . '</option>';
		}
		echo '</select></td></tr>';
		self::text_row( 'logo', __( 'Logo URL', 'aktio-connector' ), (string) $company['logo'], 'url' );
		self::text_row( 'phone', __( 'Phone', 'aktio-connector' ), (string) $company['phone'] );
		self::text_row( 'email', __( 'Email', 'aktio-connector' ), (string) $company['email'], 'email' );
		self::text_row( 'address][street', __( 'Street and number', 'aktio-connector' ), (string) $address['street'] );
		self::text_row( 'address][city', __( 'City', 'aktio-connector' ), (string) $address['city'] );
		self::text_row( 'address][postal_code', __( 'Postal code', 'aktio-connector' ), (string) $address['postal_code'] );
		self::text_row( 'address][country', __( 'Country', 'aktio-connector' ), (string) $address['country'] );
		echo '<tr><th scope="row"><label for="aktio-company-same-as">' . esc_html__( 'Social profiles (sameAs)', 'aktio-connector' ) . '</label></th><td>';
		echo '<textarea id="aktio-company-same-as" name="company[same_as]" rows="4" class="large-text code">' . esc_textarea( implode( "\n", $company['same_as'] ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One URL per line, up to 10.', 'aktio-connector' ) . '</p></td></tr>';
		echo '</tbody></table>';

		self::render_company_extra( $company );

		echo '<h2>' . esc_html__( 'AI bots in robots.txt', 'aktio-connector' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Settings::BOTS as $bot ) {
			$value = 'block' === ( $bots[ $bot ] ?? 'allow' ) ? 'block' : 'allow';
			$id    = 'aktio-bot-' . sanitize_html_class( $bot );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $bot ) . '</label></th><td>';
			echo '<select id="' . esc_attr( $id ) . '" name="bots[' . esc_attr( $bot ) . ']">';
			echo '<option value="allow"' . selected( $value, 'allow', false ) . '>' . esc_html__( 'Allow', 'aktio-connector' ) . '</option>';
			echo '<option value="block"' . selected( $value, 'block', false ) . '>' . esc_html__( 'Block', 'aktio-connector' ) . '</option>';
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		self::render_merchant( $settings['merchant'], (string) $company['name'] );

		self::render_indexnow( $settings['indexnow'] );
		self::render_verification( $settings['verification'] );

		submit_button( __( 'Save changes', 'aktio-connector' ) );
		echo '</form>';

		// Formularze przycisków akcji (przyciski w sekcjach wskazują je atrybutem form).
		self::action_form( 'aktio-sitemap-form', self::SITEMAP_ACTION, self::SITEMAP_NONCE, '_aktio_sitemap_nonce' );
		self::action_form( 'aktio-cache-form', self::CACHE_ACTION, self::CACHE_NONCE, '_aktio_cache_nonce' );

		self::render_cache();
		self::render_metrics();
		self::render_features();
		self::render_log();
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

	/** Sekcja SEO: opis strony głównej i domyślny obrazek OG (biblioteka mediów). */
	private static function render_seo( array $seo ): void {
		echo '<h2>' . esc_html__( 'SEO', 'aktio-connector' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row(
			'aktio-seo-home-description',
			__( 'Home page description', 'aktio-connector' ),
			'<textarea id="aktio-seo-home-description" name="seo[home_description]" rows="3" maxlength="160" class="large-text">' . esc_textarea( (string) $seo['home_description'] ) . '</textarea>',
			__( 'Meta description of the home page, up to 160 characters. Used only when no SEO plugin provides it.', 'aktio-connector' )
		);
		self::merchant_row(
			'aktio-seo-default-og-image',
			__( 'Default social image', 'aktio-connector' ),
			'<input type="url" id="aktio-seo-default-og-image" name="seo[default_og_image]" value="' . esc_attr( (string) $seo['default_og_image'] ) . '" class="regular-text" /> '
				. '<button type="button" class="button aktio-choose-image" data-target="aktio-seo-default-og-image" data-title="' . esc_attr__( 'Choose the default social image', 'aktio-connector' ) . '" data-button="' . esc_attr__( 'Use this image', 'aktio-connector' ) . '">' . esc_html__( 'Choose image', 'aktio-connector' ) . '</button>',
			__( 'Shown when a page or product has no image of its own. Must be an https:// address; leave empty for none.', 'aktio-connector' )
		);
		echo '</tbody></table>';
	}

	/** Język treści strony + ostrzeżenie z heurystyki Language::warning(). */
	private static function render_language( string $language ): void {
		echo '<h2>' . esc_html__( 'Content language', 'aktio-connector' ) . '</h2>';
		$warning = Language::warning();
		if ( $warning ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html(
				/* translators: 1: detected language code, 2: configured content language */
				sprintf( __( 'Your content looks like it is written in "%1$s", but the content language is set to "%2$s". Check the setting below.', 'aktio-connector' ), (string) $warning['detected'], (string) $warning['expected'] )
			) . '</p></div>';
		}
		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row(
			'aktio-content-language',
			__( 'Language of the site content', 'aktio-connector' ),
			'<input type="text" id="aktio-content-language" name="content_language" value="' . esc_attr( $language ) . '" maxlength="5" placeholder="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '" class="small-text" />',
			__( 'A code such as pl, en-IE or de-DE. It sets the lang attribute, og:locale, schema inLanguage and llms.txt; the admin panel stays in your language. Leave empty to use the site language.', 'aktio-connector' )
		);
		echo '</tbody></table>';
	}

	/** Dodatkowe dane firmy (opis, data założenia, obszar, kontakt). */
	private static function render_company_extra( array $company ): void {
		$cp = $company['contact_point'];
		echo '<h2>' . esc_html__( 'Extended company data', 'aktio-connector' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row(
			'aktio-company-description',
			__( 'Description', 'aktio-connector' ),
			'<textarea id="aktio-company-description" name="company[description]" rows="3" maxlength="300" class="large-text">' . esc_textarea( (string) $company['description'] ) . '</textarea>',
			__( 'A short description of the company, up to 300 characters.', 'aktio-connector' )
		);
		self::text_row( 'founding_date', __( 'Founding date', 'aktio-connector' ), (string) $company['founding_date'], 'date' );
		self::text_row( 'area_served', __( 'Area served', 'aktio-connector' ), (string) $company['area_served'] );
		self::text_row( 'contact_point][telephone', __( 'Customer service phone', 'aktio-connector' ), (string) $cp['telephone'] );
		self::text_row( 'contact_point][email', __( 'Customer service email', 'aktio-connector' ), (string) $cp['email'], 'email' );
		self::text_row( 'contact_point][languages', __( 'Customer service languages', 'aktio-connector' ), implode( ', ', $cp['languages'] ) );
		echo '</tbody></table>';
	}

	/** IndexNow: przełącznik z opisem usługi, dziennik i przycisk zgłoszenia całej mapy. */
	private static function render_indexnow( array $indexnow ): void {
		echo '<h2>' . esc_html__( 'IndexNow', 'aktio-connector' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row(
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
			$html .= '<table class="widefat striped" style="max-width:600px"><thead><tr>';
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
		self::merchant_row( 'aktio-indexnow-log', __( 'Submissions', 'aktio-connector' ), $html );
		echo '</tbody></table>';
	}

	/** Kody weryfikacyjne Bing Webmaster Tools i Google Search Console. */
	private static function render_verification( array $verification ): void {
		echo '<h2>' . esc_html__( 'Search engine verification', 'aktio-connector' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row(
			'aktio-verification-bing',
			__( 'Bing Webmaster Tools code', 'aktio-connector' ),
			'<input type="text" id="aktio-verification-bing" name="verification[bing]" value="' . esc_attr( (string) $verification['bing'] ) . '" maxlength="32" class="regular-text code" />',
			__( 'The msvalidate.01 content value: 32 uppercase hexadecimal characters.', 'aktio-connector' )
		);
		self::merchant_row(
			'aktio-verification-google',
			__( 'Google Search Console code', 'aktio-connector' ),
			'<input type="text" id="aktio-verification-google" name="verification[google]" value="' . esc_attr( (string) $verification['google'] ) . '" maxlength="100" class="regular-text code" />',
			__( 'The google-site-verification content value. Added only when no SEO plugin or Site Kit provides it.', 'aktio-connector' )
		);
		echo '</tbody></table>';
	}

	/** Cache: wykryta wtyczka, ostatnie czyszczenie, przycisk „Wyczyść teraz”. */
	private static function render_cache(): void {
		$detected = CachePurge::detected();
		$last     = CachePurge::last();
		echo '<h2>' . esc_html__( 'Cache', 'aktio-connector' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		if ( null === $detected ) {
			self::merchant_row( 'aktio-cache-plugin', __( 'Detected cache plugin', 'aktio-connector' ), esc_html__( 'No supported cache plugin detected.', 'aktio-connector' ) );
		} else {
			self::merchant_row( 'aktio-cache-plugin', __( 'Detected cache plugin', 'aktio-connector' ), esc_html( $detected ) );
		}
		self::merchant_row( 'aktio-cache-last', __( 'Last cache clearing', 'aktio-connector' ), esc_html( null !== $last ? $last : '—' ) );
		self::merchant_row(
			'aktio-cache-clear',
			__( 'Clear the cache', 'aktio-connector' ),
			'<button type="submit" form="aktio-cache-form" id="aktio-cache-clear" class="button">' . esc_html__( 'Clear now', 'aktio-connector' ) . '</button>',
			__( 'The cache is cleared automatically after saving these settings.', 'aktio-connector' )
		);
		echo '</tbody></table>';
	}

	/** Pomiar widoczności w AI: wizyty botów i wejścia z wyszukiwarek AI (30 dni), top ścieżki. */
	private static function render_metrics(): void {
		$report = Metrics::report( 30 );
		echo '<h2>' . esc_html__( 'AI visibility', 'aktio-connector' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Last 30 days. Bot visits are counted by PHP, so pages served from a page cache or CDN are not counted: the numbers are a lower bound when caching is on.', 'aktio-connector' ) . '</p>';
		self::count_table( __( 'AI bot visits', 'aktio-connector' ), __( 'Bot', 'aktio-connector' ), $report['bots'] );
		self::count_table( __( 'Visits from AI search', 'aktio-connector' ), __( 'Source', 'aktio-connector' ), $report['referrals'] );

		echo '<h3>' . esc_html__( 'Top landing pages from AI', 'aktio-connector' ) . '</h3>';
		if ( ! $report['top_paths'] ) {
			echo '<p>' . esc_html__( 'No data yet.', 'aktio-connector' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:600px"><thead><tr><th scope="col">' . esc_html__( 'Page', 'aktio-connector' ) . '</th><th scope="col">' . esc_html__( 'Visits', 'aktio-connector' ) . '</th></tr></thead><tbody>';
		foreach ( $report['top_paths'] as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['path'] ) . '</td><td>' . esc_html( (string) $row['count'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Tabela nazwa → suma wizyt z serii dziennych [[dzień, liczba], ...]. */
	private static function count_table( string $title, string $column, array $series ): void {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $series ) {
			echo '<p>' . esc_html__( 'No data yet.', 'aktio-connector' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:600px"><thead><tr><th scope="col">' . esc_html( $column ) . '</th><th scope="col">' . esc_html__( 'Visits', 'aktio-connector' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $name => $days ) {
			$total = array_sum( array_column( $days, 1 ) );
			echo '<tr><td>' . esc_html( (string) $name ) . '</td><td>' . esc_html( (string) $total ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Pola sprzedawcy mają sens tylko ze sklepem; żadnej funkcji WC nie wołamy bez tej bramki. */
	private static function woocommerce_available(): bool {
		return ! self::$assume_no_woocommerce && class_exists( 'WooCommerce' ) && function_exists( 'get_woocommerce_currency' );
	}

	/** Komunikat błędu walidacji (ustawienia nie zostały zapisane). */
	private static function render_errors(): void {
		$errors = get_transient( self::errors_key() );
		delete_transient( self::errors_key() );
		if ( ! is_array( $errors ) || ! $errors ) {
			return; // Odświeżenie strony po wyświetleniu błędów — bez pustej ramki.
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Settings were not saved. Fix the following fields:', 'aktio-connector' ) . '</strong></p><ul>';
		foreach ( $errors as $message ) {
			echo '<li>' . esc_html( (string) $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Sekcja „Merchant”: marka domyślna, polityka zwrotów i wysyłka zastępcza.
	 */
	private static function render_merchant( array $merchant, string $company_name ): void {
		$rp = $merchant['return_policy'];
		$sh = $merchant['shipping'];

		echo '<h2>' . esc_html__( 'Merchant', 'aktio-connector' ) . '</h2>';
		if ( ! self::woocommerce_available() ) {
			echo '<p class="description">' . esc_html__( 'Merchant fields work with a WooCommerce store.', 'aktio-connector' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Used when they cannot be read from WooCommerce. You declare this data to Google — enter it in line with your store terms.', 'aktio-connector' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row(
			'aktio-merchant-brand',
			__( 'Default brand', 'aktio-connector' ),
			'<input type="text" id="aktio-merchant-brand" name="merchant[default_brand]" value="' . esc_attr( (string) $merchant['default_brand'] ) . '" placeholder="' . esc_attr( $company_name ) . '" maxlength="100" class="regular-text" />',
			__( 'Used for products without their own brand. Empty means no default brand.', 'aktio-connector' )
		);
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Return policy', 'aktio-connector' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row( 'aktio-merchant-rp-enabled', __( 'Declare a return policy', 'aktio-connector' ), self::checkbox( 'aktio-merchant-rp-enabled', 'merchant[return_policy][enabled]', (bool) $rp['enabled'], __( 'Add the return policy to product offers', 'aktio-connector' ) ) );
		self::merchant_row( 'aktio-merchant-rp-days', __( 'Return window (days)', 'aktio-connector' ), self::number( 'aktio-merchant-rp-days', 'merchant[return_policy][days]', (int) $rp['days'], 0, 365 ), __( '0 means returns are not accepted.', 'aktio-connector' ) );
		self::merchant_row(
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
		self::merchant_row( 'aktio-merchant-rp-country', __( 'Country', 'aktio-connector' ), self::country( 'aktio-merchant-rp-country', 'merchant[return_policy][country]', (string) $rp['country'] ), __( 'Two-letter ISO country code, for example PL. Leave empty to use the store country. Turn the checkbox off to stop declaring this data.', 'aktio-connector' ) );
		self::merchant_row(
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

		echo '<h3>' . esc_html__( 'Fallback shipping', 'aktio-connector' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::merchant_row( 'aktio-merchant-sh-enabled', __( 'Declare fallback shipping', 'aktio-connector' ), self::checkbox( 'aktio-merchant-sh-enabled', 'merchant[shipping][enabled]', (bool) $sh['enabled'], __( 'Add shipping details when WooCommerce zones do not provide them', 'aktio-connector' ) ) );
		self::merchant_row( 'aktio-merchant-sh-country', __( 'Destination country', 'aktio-connector' ), self::country( 'aktio-merchant-sh-country', 'merchant[shipping][country]', (string) $sh['country'] ), __( 'Two-letter ISO country code, for example PL. Leave empty to use the store country. Turn the checkbox off to stop declaring this data.', 'aktio-connector' ) );
		self::merchant_row(
			'aktio-merchant-sh-cost',
			/* translators: %s: shop currency code, e.g. PLN */
			sprintf( __( 'Shipping cost (%s)', 'aktio-connector' ), get_woocommerce_currency() ),
			'<input type="text" id="aktio-merchant-sh-cost" name="merchant[shipping][cost]" value="' . esc_attr( (string) $sh['cost'] ) . '" maxlength="10" inputmode="decimal" class="small-text" />',
			__( 'Amount in the shop currency, up to 2 decimal places, for example 12.50. 0.00 means free shipping.', 'aktio-connector' )
		);
		self::merchant_row( 'aktio-merchant-sh-handling-0', __( 'Handling time (days)', 'aktio-connector' ), self::range( 'aktio-merchant-sh-handling', 'merchant[shipping][handling_days]', $sh['handling_days'], 30 ), __( 'From – to, 0 to 30 days.', 'aktio-connector' ) );
		self::merchant_row( 'aktio-merchant-sh-transit-0', __( 'Delivery time (days)', 'aktio-connector' ), self::range( 'aktio-merchant-sh-transit', 'merchant[shipping][transit_days]', $sh['transit_days'], 60 ), __( 'From – to, 0 to 60 days.', 'aktio-connector' ) );
		echo '</tbody></table>';
	}

	/** Wiersz tabeli; $control to gotowy, już escapowany HTML pola. */
	private static function merchant_row( string $id, string $label, string $control, string $description = '' ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML zbudowany z esc_attr()/esc_html() w pomocnikach pól.
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Checkbox z ukrytym zerem, żeby odznaczenie dotarło do serwera. */
	private static function checkbox( string $id, string $name, bool $checked, string $text ): string {
		return '<input type="hidden" name="' . esc_attr( $name ) . '" value="0" />'
			. '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . ' /> ' . esc_html( $text ) . '</label>';
	}

	private static function number( string $id, string $name, int $value, int $min, int $max ): string {
		return '<input type="number" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" step="1" class="small-text" />';
	}

	private static function country( string $id, string $name, string $value ): string {
		return '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" maxlength="2" placeholder="PL" class="small-text" />';
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

	private static function text_row( string $key, string $label, string $value, string $type = 'text' ): void {
		$id = 'aktio-company-' . sanitize_html_class( str_replace( '][', '-', $key ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="company[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '</td></tr>';
	}

	private static function render_features(): void {
		$labels   = array(
			'content_schema'   => __( 'Content schema', 'aktio-connector' ),
			'product_schema'   => __( 'Product schema', 'aktio-connector' ),
			'robots'           => __( 'robots.txt', 'aktio-connector' ),
			'llms'             => __( 'llms.txt', 'aktio-connector' ),
			'title'           => __( 'Page title', 'aktio-connector' ),
			'meta_description' => __( 'Meta description', 'aktio-connector' ),
			'open_graph'       => __( 'Open Graph', 'aktio-connector' ),
			'twitter_card'     => __( 'X (Twitter) cards', 'aktio-connector' ),
			'robots_meta'      => __( 'Robots meta', 'aktio-connector' ),
		);
		$features = Detector::features();

		echo '<h2>' . esc_html__( 'Feature status', 'aktio-connector' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $labels as $key => $label ) {
			$feature = $features[ $key ] ?? array( 'state' => 'off', 'provided_by' => null );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
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
	}

	private static function render_log(): void {
		$log = Updates::log( 10 );
		echo '<h2>' . esc_html__( 'Update log', 'aktio-connector' ) . '</h2>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No entries.', 'aktio-connector' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
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
	}
}
