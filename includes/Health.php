<?php
/**
 * Site Health → tablica kontraktu aktio/v1/health, z redakcją ścieżek i e-maili.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Health {

	const MAX_TESTS       = 100;
	const MAX_DESCRIPTION = 1000;

	/**
	 * @return array{counts: array<string,int>, tests: array<int,array>, info: array}
	 */
	public static function report(): array {
		self::load_dependencies();

		$counts = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);
		$tests  = array();

		$all = \WP_Site_Health::get_instance()->get_tests();
		foreach ( array( 'direct', 'async' ) as $kind ) {
			foreach ( (array) ( $all[ $kind ] ?? array() ) as $key => $def ) {
				if ( count( $tests ) >= self::MAX_TESTS ) {
					break 2;
				}
				$row = self::run_one( (string) $key, (array) $def, 'async' === $kind );
				if ( isset( $counts[ $row['status'] ] ) ) {
					++$counts[ $row['status'] ];
				}
				$tests[] = $row;
			}
		}

		return array(
			'counts' => $counts,
			'tests'  => $tests,
			'info'   => self::info(),
		);
	}

	/**
	 * Zamienia ścieżki serwera i adresy e-mail na znaczniki. Najdłuższe ścieżki najpierw.
	 */
	public static function redact( string $s ): string {
		$paths = array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', (string) getenv( 'HOME' ) );
		$paths = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $p ) {
							return rtrim( (string) $p, '/\\' );
						},
						$paths
					),
					static function ( $p ) {
						return strlen( $p ) > 1;
					}
				)
			)
		);
		usort(
			$paths,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		foreach ( $paths as $p ) {
			$s = str_replace( $p, '[ścieżka]', $s );
		}
		$s = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[e-mail]', $s );
		return (string) $s;
	}

	private static function load_dependencies(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	private static function run_one( string $key, array $def, bool $async ): array {
		$label  = (string) ( $def['label'] ?? $key );
		$result = null;

		try {
			if ( ! $async ) {
				$test = $def['test'] ?? null;
				if ( is_string( $test ) && is_callable( array( \WP_Site_Health::get_instance(), 'get_test_' . $test ) ) ) {
					$result = call_user_func( array( \WP_Site_Health::get_instance(), 'get_test_' . $test ) );
				} elseif ( is_callable( $test ) ) {
					$result = call_user_func( $test );
				}
			} elseif ( isset( $def['async_direct_test'] ) && is_callable( $def['async_direct_test'] ) ) {
				$result = call_user_func( $def['async_direct_test'] );
			}
		} catch ( \Throwable $e ) {
			$result = null;
		}

		if ( ! is_array( $result ) || ! in_array( $result['status'] ?? '', array( 'good', 'recommended', 'critical' ), true ) ) {
			return array(
				'test'        => $key,
				'label'       => self::redact( wp_strip_all_tags( $label ) ),
				'status'      => 'skipped',
				'badge'       => '',
				'description' => '',
			);
		}

		$badge = $result['badge'] ?? '';
		if ( is_array( $badge ) ) {
			$badge = $badge['label'] ?? '';
		}

		return array(
			'test'        => $key,
			'label'       => self::redact( wp_strip_all_tags( (string) ( $result['label'] ?? $label ) ) ),
			'status'      => $result['status'],
			'badge'       => wp_strip_all_tags( (string) $badge ),
			'description' => self::plain( (string) ( $result['description'] ?? '' ) ),
		);
	}

	/**
	 * Tekst bez HTML, zredagowany, jedna linia, do 1000 znaków.
	 */
	private static function plain( string $html ): string {
		// Treść <script>/<style> wycinamy razem z tagami.
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		$text = self::redact( $text );
		if ( mb_strlen( $text ) > self::MAX_DESCRIPTION ) {
			$text = rtrim( mb_substr( $text, 0, self::MAX_DESCRIPTION - 1 ) ) . '…';
		}
		return $text;
	}

	private static function info(): array {
		global $wpdb;

		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active  = array_unique( array_merge( (array) get_option( 'active_plugins', array() ), is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array() ) );

		return array(
			'wp'             => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'mysql'          => (string) $wpdb->db_version(),
			'theme'          => wp_get_theme()->get( 'Name' ),
			'plugins_active' => count( $active ),
			'plugins_total'  => count( $plugins ),
			'memory_limit'   => (string) ini_get( 'memory_limit' ),
			'https'          => (bool) wp_is_using_https(),
			'wp_debug'       => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'cron_ok'        => self::cron_ok(),
		);
	}

	private static function cron_ok(): bool {
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return false;
		}
		$cron = _get_cron_array();
		if ( ! is_array( $cron ) ) {
			return true;
		}
		$limit = time() - HOUR_IN_SECONDS;
		foreach ( array_keys( $cron ) as $ts ) {
			if ( is_numeric( $ts ) && (int) $ts < $limit ) {
				return false;
			}
		}
		return true;
	}
}
