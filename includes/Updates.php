<?php
/**
 * Aktualizacje rdzenia, wtyczek i motywów, auto-aktualizacje i dziennik.
 *
 * Bez automatycznego cofania (C9): każda próba (udana lub nie) trafia do dziennika
 * razem z wynikiem sprawdzenia strony po aktualizacji (`site_ok`: true/false,
 * null = nie sprawdzano — up_to_date albo aktualizacja z crona WP).
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Updates {

	const LOG_OPTION       = 'aktio_connector_update_log';
	const CORE_AUTO_OPTION = 'aktio_connector_core_auto';
	const LOG_MAX          = 50;
	const LIST_MAX         = 200;
	const CORE_LEVELS      = array( 'minor', 'all', 'off' );

	const PLUGIN_RE = '#^[A-Za-z0-9_-]{1,100}(/[A-Za-z0-9_-]{1,100})?$#';
	const THEME_RE  = '#^[A-Za-z0-9_-]{1,100}$#';

	/** Prawda w trakcie apply() — hook upgrader_process_complete nie dubluje wtedy wpisu. */
	private static $applying = false;

	/** Wersje sprzed instalacji (upgrader_pre_install) dla aktualizacji spoza Aktio: 'typ:klucz' => wersja. */
	private static $pending_from = array();

	public static function register(): void {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'remember_from' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'log_user_update' ), 10, 2 );
		add_filter( 'allow_major_auto_core_updates', array( __CLASS__, 'filter_major_auto' ) );
		add_filter( 'allow_minor_auto_core_updates', array( __CLASS__, 'filter_minor_auto' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Lista                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Stan aktualizacji: {core, plugins, themes, auto_updates: {core}}.
	 */
	public static function available(): array {
		self::load_admin_includes();
		foreach ( array( 'core', 'plugin', 'theme' ) as $type ) {
			self::refresh_if_empty( $type );
		}

		$core_offer = self::core_offer();
		$plugins    = array();
		foreach ( self::plugin_ids() as $id => $file ) {
			if ( count( $plugins ) >= self::LIST_MAX ) {
				break;
			}
			$plugins[] = self::plugin_row( $id, $file );
		}
		$themes = array();
		foreach ( array_keys( wp_get_themes() ) as $stylesheet ) {
			if ( count( $themes ) >= self::LIST_MAX ) {
				break;
			}
			$themes[] = self::theme_row( (string) $stylesheet );
		}

		return array(
			'core'         => array(
				'current'   => self::core_version(),
				'available' => $core_offer ? (string) $core_offer->current : null,
			),
			'plugins'      => $plugins,
			'themes'       => $themes,
			'auto_updates' => array( 'core' => self::core_level() ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Wykonanie                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Aktualizuje jeden element. Zwraca
	 * {result: updated|failed|up_to_date, type, item, from, to, site_ok, entry, error}
	 * albo WP_Error (aktio_invalid_params 400, aktio_not_found 404, aktio_fs_unavailable 500).
	 *
	 * @return array|\WP_Error
	 */
	public static function apply( string $type, string $item, string $by = 'aktio' ) {
		self::load_admin_includes();
		$target = self::resolve( $type, $item );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		// Świeże dane o aktualizacjach — tylko gdy transient pusty (bez wymuszania sieci).
		self::refresh_if_empty( $type );
		$target = self::resolve( $type, $item );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$response = array(
			'result'  => 'up_to_date',
			'type'    => $type,
			'item'    => $target['item'],
			'from'    => $target['current'],
			'to'      => $target['current'],
			'site_ok' => null,
			'entry'   => null,
			'error'   => null,
		);
		if ( null === $target['available'] || version_compare( $target['available'], $target['current'], '<=' ) ) {
			return $response;
		}

		if ( ! self::filesystem_ready() ) {
			return new \WP_Error(
				'aktio_fs_unavailable',
				__( 'WordPress nie może zapisywać plików na serwerze. Aktualizacja wymaga zapisu bezpośredniego albo danych FTP/SSH w wp-config.php (FS_METHOD, FTP_HOST, FTP_USER, FTP_PASS).', 'aktio-connector' ),
				array( 'status' => 500 )
			);
		}

		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 && $max < 300 && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- bywa wyłączone na hostingu.
		}

		$upgrader       = self::upgrader( $type );
		self::$applying = true;
		try {
			if ( 'core' === $type ) {
				$raw = $upgrader->upgrade( $target['offer'] );
				$ok  = is_string( $raw ) && '' !== $raw;
			} else {
				$raw = $upgrader->bulk_upgrade( array( $target['key'] ) );
				$ok  = is_array( $raw ) && isset( $raw[ $target['key'] ] ) && is_array( $raw[ $target['key'] ] );
			}
		} catch ( \Throwable $e ) {
			$raw = new \WP_Error( 'aktio_upgrade_exception', $e->getMessage() );
			$ok  = false;
		} finally {
			self::$applying = false;
		}

		$error      = null;
		$skin_error = self::skin_error( $upgrader );
		if ( null !== $skin_error ) {
			$ok    = false;
			$error = $skin_error;
		} elseif ( is_wp_error( $raw ) ) {
			$error = $raw->get_error_message();
		} elseif ( ! $ok ) {
			$error = __( 'Aktualizacja nie powiodła się.', 'aktio-connector' );
		}

		// Odpowiedź liczona z danych zebranych przed aktualizacją (także dla samej wtyczki Aktio).
		$site_ok = self::site_ok();
		$entry   = self::add_log(
			array(
				'type'    => $type,
				'item'    => $target['item'],
				'name'    => $target['name'],
				'from'    => $target['current'],
				'to'      => $target['available'],
				'result'  => $ok ? 'updated' : 'failed',
				'by'      => 'user' === $by ? 'user' : 'aktio',
				'site_ok' => $site_ok,
			)
		);

		return array_merge(
			$response,
			array(
				'result'  => $entry['result'],
				'to'      => $target['available'],
				'site_ok' => $site_ok,
				'entry'   => $entry,
				'error'   => $error,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Auto-aktualizacje                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Wtyczka/motyw: włącza/wyłącza auto-aktualizację. Rdzeń: poziom minor|all|off.
	 * Zwraca {type, item, enabled, core_level} albo WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	public static function set_auto( string $type, ?string $item, ?bool $enabled, ?string $core_level ) {
		self::load_admin_includes();
		if ( 'core' === $type ) {
			if ( null === $core_level || ! in_array( $core_level, self::CORE_LEVELS, true ) ) {
				return self::invalid( __( 'core_level musi być jednym z: minor, all, off.', 'aktio-connector' ) );
			}
			update_option( self::CORE_AUTO_OPTION, $core_level, false );
			$effective = self::core_level(); // Np. 'off', gdy updater WP jest wyłączony.
			return array(
				'type'       => 'core',
				'item'       => 'core',
				'enabled'    => 'off' !== $effective,
				'core_level' => $effective,
			);
		}

		$target = self::resolve( $type, (string) $item );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		if ( null === $enabled ) {
			return self::invalid( __( 'Pole enabled (true/false) jest wymagane.', 'aktio-connector' ) );
		}

		if ( ! wp_is_auto_update_enabled_for_type( $type ) ) {
			return self::invalid(
				'plugin' === $type
					? __( 'Automatyczne aktualizacje wtyczek są na tej stronie wyłączone (filtr lub stała w konfiguracji).', 'aktio-connector' )
					: __( 'Automatyczne aktualizacje motywów są na tej stronie wyłączone (filtr lub stała w konfiguracji).', 'aktio-connector' )
			);
		}

		$option  = 'plugin' === $type ? 'auto_update_plugins' : 'auto_update_themes';
		$current = array_values( array_diff( (array) get_site_option( $option, array() ), array( $target['key'] ) ) );
		if ( $enabled ) {
			$current[] = $target['key'];
		}
		update_site_option( $option, $current );

		return array(
			'type'       => $type,
			'item'       => $target['item'],
			'enabled'    => $enabled,
			'core_level' => null,
		);
	}

	/**
	 * Faktyczny poziom auto-aktualizacji rdzenia: zapisany przez Aktio albo wyliczony
	 * z ustawień WordPressa; wyłączony automatyczny updater = 'off'.
	 */
	public static function core_level(): string {
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		$updater = new \WP_Automatic_Updater();
		return self::derive_core_level(
			get_option( self::CORE_AUTO_OPTION ),
			defined( 'WP_AUTO_UPDATE_CORE' ),
			defined( 'WP_AUTO_UPDATE_CORE' ) ? WP_AUTO_UPDATE_CORE : null,
			get_site_option( 'auto_update_core_major' ),
			$updater->is_disabled()
		);
	}

	/**
	 * Czysta funkcja wyliczająca poziom (seam do testów stałych z wp-config.php).
	 *
	 * @param mixed $stored        Opcja aktio_connector_core_auto (false, gdy brak).
	 * @param bool  $const_defined Czy zdefiniowano WP_AUTO_UPDATE_CORE.
	 * @param mixed $const         Wartość WP_AUTO_UPDATE_CORE.
	 * @param mixed $major_option  Site option auto_update_core_major.
	 * @param bool  $disabled      WP_Automatic_Updater::is_disabled() (AUTOMATIC_UPDATER_DISABLED, filtr automatic_updater_disabled).
	 */
	public static function derive_core_level( $stored, bool $const_defined, $const, $major_option, bool $disabled ): string {
		if ( $disabled ) {
			return 'off';
		}
		if ( in_array( $stored, self::CORE_LEVELS, true ) ) {
			return $stored;
		}
		if ( $const_defined ) {
			if ( false === $const ) {
				return 'off';
			}
			if ( 'minor' === $const ) {
				return 'minor';
			}
			if ( true === $const || in_array( $const, array( 'beta', 'rc', 'development', 'branch-development' ), true ) ) {
				return 'all';
			}
		}
		return 'enabled' === $major_option ? 'all' : 'minor';
	}

	/**
	 * Filtry działają dopiero, gdy poziom zapisano przez Aktio — sama instalacja
	 * wtyczki nie zmienia decyzji właściciela (np. major włączone w wp-admin).
	 */
	public static function filter_major_auto( $allow ) {
		$level = get_option( self::CORE_AUTO_OPTION );
		return in_array( $level, self::CORE_LEVELS, true ) ? 'all' === $level : $allow;
	}

	public static function filter_minor_auto( $allow ) {
		$level = get_option( self::CORE_AUTO_OPTION );
		return in_array( $level, self::CORE_LEVELS, true ) ? 'off' !== $level : $allow;
	}

	/* ------------------------------------------------------------------ */
	/* Dziennik                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Ostatnie $limit wpisów dziennika, najnowsze pierwsze.
	 */
	public static function log( int $limit = 50 ): array {
		$log = get_option( self::LOG_OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		return array_slice( array_values( $log ), 0, max( 0, min( $limit, self::LOG_MAX ) ) );
	}

	private static function add_log( array $fields ): array {
		$entry = array_merge( array( 'at' => gmdate( 'c' ) ), $fields );
		$log   = self::log( self::LOG_MAX );
		array_unshift( $log, $entry );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
		return $entry;
	}

	/**
	 * upgrader_pre_install: zapamiętuje wersję sprzed aktualizacji spoza Aktio.
	 */
	public static function remember_from( $response, $hook_extra ) {
		if ( self::$applying || ! is_array( $hook_extra ) ) {
			return $response;
		}
		self::load_admin_includes();
		if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			$info = self::plugin_info( $hook_extra['plugin'] );
			if ( null !== $info ) {
				self::$pending_from[ 'plugin:' . $hook_extra['plugin'] ] = $info['version'];
			}
		} elseif ( ! empty( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
			$info = self::theme_info( $hook_extra['theme'] );
			if ( null !== $info ) {
				self::$pending_from[ 'theme:' . $hook_extra['theme'] ] = $info['version'];
			}
		}
		return $response;
	}

	/**
	 * upgrader_process_complete poza żądaniem Aktio (wp-admin, WP-CLI, auto-aktualizacje WP) → wpis `by: 'user'`.
	 */
	public static function log_user_update( $upgrader, $hook_extra ): void {
		if ( self::$applying || ! is_array( $hook_extra ) || 'update' !== ( $hook_extra['action'] ?? '' ) ) {
			return;
		}
		self::load_admin_includes();
		$type    = $hook_extra['type'] ?? '';
		$entries = array();

		if ( 'core' === $type ) {
			$from      = self::core_version();
			$to        = self::core_version_on_disk();
			$entries[] = array(
				'type'   => 'core',
				'item'   => 'core',
				'name'   => 'WordPress',
				'from'   => $from,
				'to'     => $to,
				'result' => version_compare( $to, $from, '>' ) ? 'updated' : 'failed',
			);
		} elseif ( 'plugin' === $type || 'theme' === $type ) {
			$plural = $type . 's';
			$keys   = isset( $hook_extra[ $plural ] ) ? (array) $hook_extra[ $plural ] : (array) ( $hook_extra[ $type ] ?? array() );
			if ( 'plugin' === $type ) {
				wp_clean_plugins_cache( false );
			} else {
				wp_clean_themes_cache( false );
			}
			foreach ( $keys as $key ) {
				$pending = $type . ':' . $key;
				// Bez pre_install pakiet nie był instalowany (np. już aktualny) — bez wpisu.
				if ( ! is_string( $key ) || ! isset( self::$pending_from[ $pending ] ) ) {
					continue;
				}
				$from = self::$pending_from[ $pending ];
				unset( self::$pending_from[ $pending ] );
				$info = 'plugin' === $type ? self::plugin_info( $key ) : self::theme_info( $key );
				if ( null === $info ) {
					continue;
				}
				$entries[] = array(
					'type'   => $type,
					'item'   => 'plugin' === $type ? self::plugin_id( $key ) : $key,
					'name'   => $info['name'],
					'from'   => $from,
					'to'     => $info['version'],
					'result' => version_compare( $info['version'], $from, '>' ) ? 'updated' : 'failed',
				);
			}
		}

		if ( ! $entries ) {
			return;
		}
		// W cronie (auto-aktualizacje WP) bez loopbacku: site_ok = null znaczy „nie sprawdzano”.
		$site_ok = wp_doing_cron() ? null : self::site_ok();
		foreach ( $entries as $e ) {
			self::add_log( array_merge( $e, array( 'by' => 'user', 'site_ok' => $site_ok ) ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Pomocnicze                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Walidacja i dopasowanie elementu do listy zainstalowanych.
	 * Zwraca {item, key, name, current, available, offer?} albo WP_Error.
	 *
	 * @return array|\WP_Error
	 */
	private static function resolve( string $type, string $item ) {
		if ( 'core' === $type ) {
			$offer = self::core_offer();
			return array(
				'item'      => 'core',
				'key'       => 'core',
				'name'      => 'WordPress',
				'current'   => self::core_version(),
				'available' => $offer ? (string) $offer->current : null,
				'offer'     => $offer,
			);
		}
		if ( 'plugin' === $type ) {
			if ( ! preg_match( self::PLUGIN_RE, $item ) ) {
				return self::invalid( __( 'Nieprawidłowy identyfikator wtyczki.', 'aktio-connector' ) );
			}
			$ids = self::plugin_ids();
			if ( ! isset( $ids[ $item ] ) ) {
				return self::not_found();
			}
			$row = self::plugin_row( $item, $ids[ $item ] );
			return array(
				'item'      => $item,
				'key'       => $ids[ $item ],
				'name'      => $row['name'],
				'current'   => $row['current'],
				'available' => $row['available'],
			);
		}
		if ( 'theme' === $type ) {
			if ( ! preg_match( self::THEME_RE, $item ) ) {
				return self::invalid( __( 'Nieprawidłowy identyfikator motywu.', 'aktio-connector' ) );
			}
			if ( ! array_key_exists( $item, wp_get_themes() ) ) {
				return self::not_found();
			}
			$row = self::theme_row( $item );
			return array(
				'item'      => $item,
				'key'       => $item,
				'name'      => $row['name'],
				'current'   => $row['current'],
				'available' => $row['available'],
			);
		}
		return self::invalid( __( 'type musi być jednym z: core, plugin, theme.', 'aktio-connector' ) );
	}

	/** Mapa identyfikator (plik bez .php) => plik wtyczki, wyłącznie z get_plugins(). */
	private static function plugin_ids(): array {
		$ids = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$ids[ self::plugin_id( (string) $file ) ] = (string) $file;
		}
		return $ids;
	}

	private static function plugin_id( string $file ): string {
		return '.php' === substr( $file, -4 ) ? substr( $file, 0, -4 ) : $file;
	}

	private static function plugin_info( string $file ): ?array {
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			return null;
		}
		return array(
			'name'    => wp_strip_all_tags( (string) $plugins[ $file ]['Name'] ),
			'version' => (string) $plugins[ $file ]['Version'],
		);
	}

	private static function theme_info( string $stylesheet ): ?array {
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return null;
		}
		return array(
			'name'    => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
			'version' => (string) $theme->get( 'Version' ),
		);
	}

	private static function plugin_row( string $id, string $file ): array {
		$info      = self::plugin_info( $file );
		$transient = get_site_transient( 'update_plugins' );
		$available = null;
		if ( is_object( $transient ) && isset( $transient->response[ $file ]->new_version ) ) {
			$available = (string) $transient->response[ $file ]->new_version;
		}
		return array(
			'plugin'      => $id,
			'name'        => $info['name'] ?? $id,
			'current'     => $info['version'] ?? '',
			'available'   => $available,
			'auto_update' => in_array( $file, (array) get_site_option( 'auto_update_plugins', array() ), true ),
		);
	}

	private static function theme_row( string $stylesheet ): array {
		$info      = self::theme_info( $stylesheet );
		$transient = get_site_transient( 'update_themes' );
		$available = null;
		if ( is_object( $transient ) && isset( $transient->response[ $stylesheet ]['new_version'] ) ) {
			$available = (string) $transient->response[ $stylesheet ]['new_version'];
		}
		return array(
			'stylesheet'  => $stylesheet,
			'name'        => $info['name'] ?? $stylesheet,
			'current'     => $info['version'] ?? '',
			'available'   => $available,
			'auto_update' => in_array( $stylesheet, (array) get_site_option( 'auto_update_themes', array() ), true ),
		);
	}

	/** Pierwsza oferta aktualizacji rdzenia (response = upgrade, wersja nowsza) albo null. */
	private static function core_offer() {
		$updates = get_core_updates( array( 'dismissed' => false ) );
		if ( ! is_array( $updates ) ) {
			return null;
		}
		foreach ( $updates as $u ) {
			if ( is_object( $u ) && 'upgrade' === ( $u->response ?? '' ) && ! empty( $u->current )
				&& version_compare( (string) $u->current, self::core_version(), '>' ) ) {
				return $u;
			}
		}
		return null;
	}

	private static function core_version(): string {
		return (string) $GLOBALS['wp_version'];
	}

	/** Wersja rdzenia z pliku na dysku (po aktualizacji global $wp_version jest jeszcze stary). */
	private static function core_version_on_disk(): string {
		$wp_version = null;
		include ABSPATH . WPINC . '/version.php';
		return is_string( $wp_version ) ? $wp_version : self::core_version();
	}

	private static function refresh_if_empty( string $type ): void {
		$map = array(
			'core'   => array( 'update_core', 'wp_version_check' ),
			'plugin' => array( 'update_plugins', 'wp_update_plugins' ),
			'theme'  => array( 'update_themes', 'wp_update_themes' ),
		);
		if ( ! isset( $map[ $type ] ) || false !== get_site_transient( $map[ $type ][0] ) ) {
			return;
		}
		if ( function_exists( $map[ $type ][1] ) ) {
			call_user_func( $map[ $type ][1] );
		}
	}

	/** Czy WordPress może zapisywać pliki bez pytania o dane FTP. */
	private static function filesystem_ready(): bool {
		ob_start();
		$creds = request_filesystem_credentials( '', '', false, false, null );
		ob_end_clean();
		if ( false === $creds ) {
			return false;
		}
		return true === WP_Filesystem( $creds );
	}

	/** Upgrader dla typu; filtr `aktio_connector_upgrader` pozwala go podmienić. */
	private static function upgrader( string $type ) {
		$upgrader = apply_filters( 'aktio_connector_upgrader', null, $type );
		if ( is_object( $upgrader ) ) {
			return $upgrader;
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$skin = new \WP_Ajax_Upgrader_Skin();
		if ( 'core' === $type ) {
			return new \Core_Upgrader( $skin );
		}
		return 'plugin' === $type ? new \Plugin_Upgrader( $skin ) : new \Theme_Upgrader( $skin );
	}

	private static function skin_error( $upgrader ): ?string {
		if ( ! isset( $upgrader->skin ) || ! $upgrader->skin instanceof \WP_Ajax_Upgrader_Skin ) {
			return null;
		}
		if ( is_wp_error( $upgrader->skin->result ) ) {
			return $upgrader->skin->result->get_error_message();
		}
		if ( $upgrader->skin->get_errors()->has_errors() ) {
			return (string) $upgrader->skin->get_error_messages();
		}
		return null;
	}

	/** Loopback na stronę główną (z pominięciem cache): 2xx/3xx i bez komunikatu o błędzie krytycznym. */
	private static function site_ok(): bool {
		// Parametr i nagłówek omijają cache stron — inaczej zepsuty PHP mógłby dać zielone site_ok.
		$response = wp_remote_get(
			add_query_arg( 'aktio_check', (string) time(), home_url( '/' ) ),
			array(
				'timeout'   => 15,
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
				/** Filtr opisany w wp-includes/class-wp-http-streams.php (jak loopback Site Health). */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		return $code >= 200 && $code < 400
			&& false === strpos( $body, 'There has been a critical error' )
			&& false === strpos( $body, 'wp-die-message' );
	}

	private static function load_admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}

	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'aktio_invalid_params', $message, array( 'status' => 400 ) );
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'aktio_not_found', __( 'Nie znaleziono takiego elementu na stronie.', 'aktio-connector' ), array( 'status' => 404 ) );
	}
}
