<?php
/**
 * Wykrywanie aktywnych wtyczek SEO i plików fizycznych (robots.txt, llms.txt).
 * Aktio nie nadpisuje tego, co już dostarcza inna wtyczka lub plik (C5).
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Detector {

	const FEATURES = array( 'content_schema', 'product_schema', 'robots', 'llms' );

	/**
	 * Stan czterech funkcji: active (dostarcza Aktio), provided (dostarcza
	 * wtyczka lub plik), off (niedostępne — product_schema bez WooCommerce).
	 */
	public static function features(): array {
		$active = self::active_plugins();
		$result = array();

		foreach ( self::FEATURES as $feature ) {
			$result[ $feature ] = array(
				'state'       => 'active',
				'provided_by' => null,
			);
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			$result['product_schema']['state'] = 'off';
		}

		// Wtyczki: pierwsza z mapy, która dostarcza daną funkcję.
		foreach ( self::map() as $entry ) {
			$file = self::find_active_file( $entry['files'], $active );
			if ( null === $file ) {
				continue;
			}
			foreach ( self::FEATURES as $feature ) {
				if ( 'off' === $result[ $feature ]['state'] || 'provided' === $result[ $feature ]['state'] ) {
					continue;
				}
				if ( self::provides( $entry, $feature ) ) {
					$result[ $feature ] = array(
						'state'       => 'provided',
						'provided_by' => self::plugin_name( $file, $entry['name'] ),
					);
				}
			}
		}

		// Pliki fizyczne wygrywają z wtyczkami.
		foreach ( array(
			'robots' => 'robots.txt',
			'llms'   => 'llms.txt',
		) as $feature => $filename ) {
			if ( file_exists( ABSPATH . $filename ) ) {
				$result[ $feature ] = array(
					'state'       => 'provided',
					'provided_by' => 'file',
				);
			}
		}

		return $result;
	}

	/**
	 * Aktywne wtyczki SEO z mapy: [{slug, name}].
	 */
	public static function seo_plugins(): array {
		$active = self::active_plugins();
		$list   = array();
		foreach ( self::map() as $entry ) {
			$file = self::find_active_file( $entry['files'], $active );
			if ( null === $file ) {
				continue;
			}
			$list[] = array(
				'slug' => dirname( $file ),
				'name' => self::plugin_name( $file, $entry['name'] ),
			);
		}
		return $list;
	}

	/**
	 * Prawda, gdy funkcję dostarcza Aktio (state === 'active').
	 */
	public static function owns( string $feature ): bool {
		$features = self::features();
		return isset( $features[ $feature ] ) && 'active' === $features[ $feature ]['state'];
	}

	/**
	 * Mapa wykrywania. `files` — pliki główne wtyczki (z `*` jako końcówką =
	 * dowolny plik w katalogu), `provides` — funkcje: true albo callable
	 * warunku. Filtrowalna: aktio_connector_seo_map.
	 */
	public static function map(): array {
		$map = array(
			array(
				'name'     => 'Yoast SEO',
				'files'    => array( 'wordpress-seo/wp-seo.php' ),
				'provides' => array(
					'content_schema' => true,
					'robots'         => true,
					// Opcja `enable_llms_txt` w opcji `wpseo` (zweryfikowane w kodzie Yoast SEO).
					'llms'           => static function () {
						$opts = get_option( 'wpseo' );
						return is_array( $opts ) && ! empty( $opts['enable_llms_txt'] );
					},
				),
			),
			array(
				'name'     => 'Yoast WooCommerce SEO',
				'files'    => array( 'wpseo-woocommerce/wpseo-woocommerce.php' ),
				'provides' => array( 'product_schema' => true ),
			),
			array(
				'name'     => 'Rank Math SEO',
				'files'    => array( 'seo-by-rank-math/rank-math.php' ),
				'provides' => array(
					'content_schema' => true,
					'robots'         => true,
					// Moduły w opcji `rank_math_modules` (zweryfikowane w kodzie Rank Math).
					'product_schema' => static function () {
						return self::rank_math_module( 'woocommerce' );
					},
					'llms'           => static function () {
						return self::rank_math_module( 'llms-txt' );
					},
				),
			),
			array(
				'name'     => 'SEOPress',
				'files'    => array( 'wp-seopress/seopress.php' ),
				'provides' => array(
					'content_schema' => true,
					'robots'         => true,
				),
			),
			array(
				'name'     => 'All in One SEO',
				'files'    => array(
					'all-in-one-seo-pack/all_in_one_seo_pack.php',
					'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
				),
				'provides' => array(
					'content_schema' => true,
					'robots'         => true,
					'llms'           => true,
				),
			),
			array(
				'name'     => 'The SEO Framework',
				'files'    => array( 'autodescription/autodescription.php' ),
				'provides' => array(
					'content_schema' => true,
					'robots'         => true,
				),
			),
			array(
				'name'     => 'Schema Pro',
				'files'    => array( 'wp-schema-pro/wp-schema-pro.php' ),
				'provides' => array(
					'content_schema' => true,
					'product_schema' => true,
				),
			),
			array(
				'name'     => 'Website LLMs.txt',
				'files'    => array( 'website-llms-txt/*' ),
				'provides' => array( 'llms' => true ),
			),
			array(
				'name'     => 'LLMs.txt Generator',
				'files'    => array( 'llms-txt-generator/*' ),
				'provides' => array( 'llms' => true ),
			),
		);

		/**
		 * Filtr mapy wykrywania wtyczek SEO.
		 *
		 * @param array $map Lista wpisów {name, files, provides}.
		 */
		$filtered = apply_filters( 'aktio_connector_seo_map', $map );
		return is_array( $filtered ) ? $filtered : $map;
	}

	private static function provides( array $entry, string $feature ): bool {
		if ( empty( $entry['provides'][ $feature ] ) ) {
			return false;
		}
		$cond = $entry['provides'][ $feature ];
		return is_callable( $cond ) ? (bool) call_user_func( $cond ) : true;
	}

	private static function rank_math_module( string $module ): bool {
		$modules = get_option( 'rank_math_modules', array() );
		return is_array( $modules ) && in_array( $module, $modules, true );
	}

	/**
	 * Pliki aktywnych wtyczek (zwykłych i sieciowych).
	 *
	 * @return string[]
	 */
	private static function active_plugins(): array {
		$plugins = array_merge(
			(array) get_option( 'active_plugins', array() ),
			array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
		);
		return array_values( array_unique( array_filter( $plugins, 'is_string' ) ) );
	}

	/**
	 * Zwraca pierwszy aktywny plik pasujący do wzorców albo null.
	 */
	private static function find_active_file( array $patterns, array $active ): ?string {
		foreach ( $patterns as $pattern ) {
			foreach ( $active as $file ) {
				if ( substr( $pattern, -1 ) === '*' ) {
					if ( 0 === strpos( $file, substr( $pattern, 0, -1 ) ) ) {
						return $file;
					}
				} elseif ( $file === $pattern ) {
					return $file;
				}
			}
		}
		return null;
	}

	/**
	 * Nazwa z nagłówka wtyczki; gdy pliku brak — nazwa z mapy.
	 */
	private static function plugin_name( string $file, string $fallback ): string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( is_readable( $path ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				return $data['Name'];
			}
		}
		return $fallback;
	}
}
