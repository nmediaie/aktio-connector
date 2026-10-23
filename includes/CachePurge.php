<?php
/**
 * Czyszczenie cache stron po zmianach ustawień Aktio i stanu magazynowego.
 * Wywołujemy tylko publiczne API aktywnej wtyczki cache; brak wtyczki lub błąd
 * w jej kodzie nigdy nie przerywa zapisu.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class CachePurge {

	const OPTION = 'aktio_connector_cache_last_purge';

	public static function register(): void {
		add_action( 'aktio_connector_settings_updated', array( __CLASS__, 'on_settings_updated' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_stock_status' ), 10, 3 );
	}

	/**
	 * Obsługiwane wtyczki: plik główny i sposób czyszczenia. `all`/`url` zwracają
	 * true, gdy wywołały API wtyczki; brak `url` = czyścimy całość.
	 */
	private static function plugins(): array {
		return array(
			'cache-enabler'    => array(
				'file' => 'cache-enabler/cache-enabler.php',
				'all'  => static function () {
					return self::action( 'cache_enabler_clear_complete_cache' );
				},
				'url'  => static function ( $url ) {
					return self::action( 'cache_enabler_clear_page_cache_by_url', $url );
				},
			),
			'wp-rocket'        => array(
				'file' => 'wp-rocket/wp-rocket.php',
				'all'  => static function () {
					return self::call( 'rocket_clean_domain' );
				},
				'url'  => static function ( $url ) {
					return self::call( 'rocket_clean_files', array( array( $url ) ) );
				},
			),
			'litespeed-cache'  => array(
				'file' => 'litespeed-cache/litespeed-cache.php',
				'all'  => static function () {
					return self::action( 'litespeed_purge_all' );
				},
				'url'  => static function ( $url ) {
					return self::action( 'litespeed_purge_url', $url );
				},
			),
			'w3-total-cache'   => array(
				'file' => 'w3-total-cache/w3-total-cache.php',
				'all'  => static function () {
					return self::call( 'w3tc_flush_all' );
				},
				'url'  => static function ( $url ) {
					return self::call( 'w3tc_flush_url', array( $url ) );
				},
			),
			'wp-super-cache'   => array(
				'file' => 'wp-super-cache/wp-cache.php',
				'all'  => static function () {
					return self::call( 'wp_cache_clear_cache' );
				},
				'url'  => static function ( $url ) {
					return self::call( 'wpsc_delete_url_cache', array( $url ) );
				},
			),
			'wp-fastest-cache' => array(
				'file' => 'wp-fastest-cache/wpFastestCache.php',
				'all'  => static function () {
					return self::call( 'wpfc_clear_all_cache' );
				},
			),
			'sg-cachepress'    => array(
				'file' => 'sg-cachepress/sg-cachepress.php',
				'all'  => static function () {
					return self::call( 'sg_cachepress_purge_cache' );
				},
			),
			'breeze'           => array(
				'file' => 'breeze/breeze.php',
				'all'  => static function () {
					return self::action( 'breeze_clear_all_cache' );
				},
			),
			'cloudflare'       => array(
				'file' => 'cloudflare/cloudflare.php',
				'all'  => static function () {
					return self::action( 'cloudflare_purge_everything' );
				},
			),
		);
	}

	/**
	 * Slug pierwszej aktywnej wtyczki cache albo null.
	 */
	public static function detected(): ?string {
		$all = self::detected_all();
		return $all ? $all[0] : null;
	}

	/**
	 * Slugi wszystkich aktywnych wtyczek cache (np. cache stron + Cloudflare).
	 *
	 * @return string[]
	 */
	public static function detected_all(): array {
		$active = array_merge(
			(array) get_option( 'active_plugins', array() ),
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
		);
		$found = array();
		foreach ( self::plugins() as $slug => $plugin ) {
			if ( in_array( $plugin['file'], $active, true ) ) {
				$found[] = $slug;
			}
		}
		return $found;
	}

	/**
	 * Czyści całe cache; true, gdy wywołano API wtyczki.
	 */
	public static function all(): bool {
		return self::run( 'all' );
	}

	/**
	 * Czyści cache jednego adresu (wtyczki bez takiego API: całe cache).
	 */
	public static function url( string $url ): bool {
		return self::run( 'url', $url );
	}

	/**
	 * Czas ostatniego czyszczenia (ISO 8601) albo null.
	 */
	public static function last(): ?string {
		$value = get_option( self::OPTION, '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	public static function on_settings_updated(): void {
		self::all();
	}

	/**
	 * Zmiana dostępności produktu/wariantu: czyścimy stronę produktu (dla wariantu: rodzica).
	 * Wtyczki bez API „jeden adres” pomijamy — czyszczenie całego cache przy każdej zmianie
	 * stanu magazynowego (np. importy) byłoby kosztowne.
	 *
	 * @param int         $id      ID produktu lub wariantu.
	 * @param string      $status  Nowy stan magazynowy.
	 * @param object|null $product Obiekt produktu (WooCommerce).
	 */
	public static function on_stock_status( $id, $status = '', $product = null ): void {
		unset( $status );
		if ( is_object( $product ) && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 ) {
			$id = (int) $product->get_parent_id();
		}
		$link = get_permalink( (int) $id );
		if ( is_string( $link ) && '' !== $link ) {
			self::run( 'url', $link, true );
		}
	}

	/**
	 * Czyści cache we WSZYSTKICH aktywnych wtyczkach; błąd jednej nie przerywa pozostałych.
	 *
	 * @param bool $url_only Pomijaj wtyczki bez API pojedynczego adresu.
	 */
	private static function run( string $mode, string $url = '', bool $url_only = false ): bool {
		$plugins = self::plugins();
		$any     = false;
		foreach ( self::detected_all() as $slug ) {
			$plugin = $plugins[ $slug ];
			try {
				if ( 'url' === $mode && isset( $plugin['url'] ) ) {
					$done = $plugin['url']( $url );
				} elseif ( 'url' === $mode && $url_only ) {
					continue;
				} else {
					$done = $plugin['all']();
				}
			} catch ( \Throwable $e ) {
				continue;
			}
			$any = $any || $done;
		}
		if ( $any ) {
			update_option( self::OPTION, gmdate( 'c' ), false );
		}
		return $any;
	}

	/**
	 * Wywołuje funkcję wtyczki, jeśli istnieje.
	 */
	private static function call( string $function, array $args = array() ): bool {
		if ( ! function_exists( $function ) ) {
			return false;
		}
		$function( ...$args );
		return true;
	}

	/**
	 * Odpala akcję API wtyczki, jeśli ktoś jej nasłuchuje.
	 *
	 * @param mixed ...$args Argumenty akcji.
	 */
	private static function action( string $hook, ...$args ): bool {
		if ( ! has_action( $hook ) ) {
			return false;
		}
		do_action( $hook, ...$args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- API cudzej wtyczki cache.
		return true;
	}
}
