<?php
/**
 * Pomiar widoczności w AI: dzienne liczniki wizyt botów AI (po User-Agent, w PHP) i ruchu z
 * wyszukiwarek AI (beacon z przeglądarki). Bez cookies i identyfikatorów; IP istnieje tylko jako
 * ulotny HMAC w transiencie (10 min) do odrzucania powtórzeń — nigdy w tabeli ani w opcjach.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Metrics {

	const CRON          = 'aktio_connector_metrics_purge';
	const SCHEMA_OPTION = 'aktio_connector_metrics_schema';
	const SCHEMA        = 1;
	const RETENTION     = 400;
	const MAX_PATHS     = 200;
	const RATE_LIMIT    = 60;
	const DEDUP_TTL     = 10 * MINUTE_IN_SECONDS;
	const TRANSIENT     = 'aktio_bc_';

	const BOTS    = array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'bingbot', 'Applebot', 'Amazonbot', 'meta-externalagent', 'CCBot', 'Bytespider' );
	const SOURCES = array( 'chatgpt.com', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com', 'claude.ai' );

	public static function register(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'purge' ) );
		// Przed mapą strony (priorytet 0) i llms.txt (10), które kończą żądanie; robots.txt też przechodzi przez template_redirect.
		add_action( 'template_redirect', array( __CLASS__, 'on_request' ), -1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function activate(): void {
		self::install();
		self::schedule();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aktio_metrics';
	}

	/**
	 * Tworzy tabelę (dbDelta) i zapisuje wersję schematu.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			day date NOT NULL,
			kind varchar(16) NOT NULL,
			name varchar(64) NOT NULL,
			path varchar(191) NOT NULL DEFAULT '',
			`count` int(10) unsigned NOT NULL DEFAULT 0,
			UNIQUE KEY day_kind_name_path (day,kind,name,path)
		) {$collate};"
		);
		update_option( self::SCHEMA_OPTION, (string) self::SCHEMA, false );
	}

	public static function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) < self::SCHEMA ) {
			self::install();
		}
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Usuwa wiersze starsze niż 400 dni.
	 */
	public static function purge(): void {
		global $wpdb;
		$limit = gmdate( 'Y-m-d', time() - self::RETENTION * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE day < %s', $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * template_redirect: liczy wizytę bota na froncie (także llms.txt, robots.txt, mapy strony).
	 */
	public static function on_request(): void {
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ) {
			self::record_bot( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tylko dopasowanie do listy.
		}
	}

	/**
	 * Zwiększa dzienny licznik bota rozpoznanego po User-Agent; inne UA są ignorowane.
	 */
	public static function record_bot( string $ua ): void {
		$ua = substr( $ua, 0, 500 );
		foreach ( self::BOTS as $bot ) {
			if ( false !== stripos( $ua, $bot ) ) {
				self::increment( 'bot', $bot, '' );
				return;
			}
		}
	}

	/**
	 * Zwiększa dzienny licznik źródła AI ze ścieżką lądowania; po 200 ścieżkach dziennie nowe liczone bez ścieżki.
	 */
	public static function record_referral( string $source, string $path ): void {
		global $wpdb;
		$table = self::table();
		$day   = gmdate( 'Y-m-d' );
		if ( '' !== $path ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE day = %s AND kind = 'referral' AND name = %s AND path = %s", $day, $source, $path ) ); // phpcs:ignore WordPress.DB
			if ( ! $exists ) {
				$paths = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE day = %s AND kind = 'referral' AND path <> ''", $day ) ); // phpcs:ignore WordPress.DB
				if ( $paths >= self::MAX_PATHS ) {
					$path = '';
				}
			}
		}
		self::increment( 'referral', $source, $path );
	}

	private static function increment( string $kind, string $key, string $path ): void {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (day, kind, name, path, `count`) VALUES (%s, %s, %s, %s, 1) ON DUPLICATE KEY UPDATE `count` = `count` + 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d' ),
				$kind,
				$key,
				$path
			)
		);
	}

	/**
	 * Skrypt beaconu: tylko front, defer, w stopce, bez adresów zewnętrznych.
	 */
	public static function enqueue(): void {
		if ( is_admin() ) {
			return;
		}
		wp_enqueue_script(
			'aktio-beacon',
			plugins_url( 'assets/aktio-beacon.js', AKTIO_CONNECTOR_FILE ),
			array(),
			AKTIO_CONNECTOR_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		// Lista hostów AI: skrypt wysyła beacon tylko dla tych źródeł (reszta ruchu nie zużywa budżetu).
		wp_localize_script(
			'aktio-beacon',
			'aktioBeacon',
			array(
				'url'   => rest_url( Rest::NAMESPACE . '/beacon' ),
				'hosts' => self::SOURCES,
			)
		);
	}

	/**
	 * POST aktio/v1/beacon (publiczny): `r` = document.referrer, `u` = utm_source, `p` = ścieżka.
	 * Globalny limit 60/min (429) zużywają tylko wejścia ze źródeł AI, które nie są powtórzeniem;
	 * obce źródła i powtórzenia z tego samego IP i ścieżki w 10 min kończą się 204 bez liczenia.
	 */
	public static function beacon( \WP_REST_Request $request ) {
		$source = self::source( $request->get_param( 'r' ), $request->get_param( 'u' ) );
		$path   = self::clean_path( $request->get_param( 'p' ) );
		if ( null === $source || '' === $path ) {
			return new \WP_REST_Response( null, 204 );
		}
		$visit = self::visit_key( $path );
		if ( false !== get_transient( $visit ) ) {
			return new \WP_REST_Response( null, 204 );
		}

		$bucket = self::TRANSIENT . 'rl' . (int) floor( time() / MINUTE_IN_SECONDS );
		$hits   = (int) get_transient( $bucket ) + 1;
		set_transient( $bucket, $hits, 2 * MINUTE_IN_SECONDS );
		if ( $hits > self::RATE_LIMIT ) {
			return new \WP_Error( 'aktio_rate_limited', __( 'Too many requests.', 'aktio-connector' ), array( 'status' => 429 ) );
		}

		set_transient( $visit, 1, self::DEDUP_TTL );
		self::record_referral( $source, $path );
		return new \WP_REST_Response( null, 204 );
	}

	/**
	 * Źródło AI z hosta Referera albo z utm_source (host dokładnie lub z prefiksem www.); null poza listą.
	 */
	private static function source( $referrer, $utm ): ?string {
		$candidates = array();
		if ( is_string( $referrer ) && '' !== $referrer ) {
			$candidates[] = (string) wp_parse_url( substr( $referrer, 0, 500 ), PHP_URL_HOST );
		}
		if ( is_string( $utm ) && '' !== $utm ) {
			$candidates[] = substr( $utm, 0, 100 );
		}
		foreach ( $candidates as $host ) {
			$host = strtolower( $host );
			if ( 0 === strpos( $host, 'www.' ) ) {
				$host = substr( $host, 4 );
			}
			if ( in_array( $host, self::SOURCES, true ) ) {
				return $host;
			}
		}
		return null;
	}

	private static function clean_path( $path ): string {
		if ( ! is_string( $path ) ) {
			return '';
		}
		$path = (string) wp_parse_url( substr( $path, 0, 500 ), PHP_URL_PATH );
		if ( '' === $path || '/' !== $path[0] ) {
			return '';
		}
		return substr( preg_replace( '/[\x00-\x1F\x7F]/', '', $path ), 0, 191 );
	}

	/**
	 * Ulotny HMAC(IP + ścieżka) w transiencie na 10 min; sam IP nigdzie nie jest zapisywany.
	 */
	private static function visit_key( string $path ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tylko wejście HMAC.
		return self::TRANSIENT . substr( hash_hmac( 'sha256', $ip . '|' . $path, wp_salt( 'auth' ) . 'aktio-beacon' ), 0, 32 );
	}

	/**
	 * Dane do GET aktio/v1/metrics: serie dzienne botów i źródeł, top 10 ścieżek lądowania.
	 *
	 * @return array{bots:array, referrals:array, top_paths:array, note:string}
	 */
	public static function report( int $days = 30 ): array {
		global $wpdb;
		$days  = max( 1, min( self::RETENTION, $days ) );
		$table = self::table();
		$from  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, kind, name, SUM(`count`) AS total FROM {$table} WHERE day >= %s GROUP BY day, kind, name ORDER BY day ASC", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array(
			'bots'      => array(),
			'referrals' => array(),
		);
		foreach ( (array) $rows as $row ) {
			$group                          = 'bot' === $row['kind'] ? 'bots' : 'referrals';
			$out[ $group ][ $row['name'] ][] = array( $row['day'], (int) $row['total'] );
		}

		$paths = $wpdb->get_results( $wpdb->prepare( "SELECT path, SUM(`count`) AS total FROM {$table} WHERE day >= %s AND kind = 'referral' AND path <> '' GROUP BY path ORDER BY total DESC, path ASC LIMIT 10", $from ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out['top_paths'] = array();
		foreach ( (array) $paths as $row ) {
			$out['top_paths'][] = array(
				'path'  => $row['path'],
				'count' => (int) $row['total'],
			);
		}
		$out['note'] = 'cache_undercount';
		return $out;
	}
}
