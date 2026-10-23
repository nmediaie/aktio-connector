<?php
/**
 * IndexNow: kolejka zmienionych adresów wysyłana cronem do api.indexnow.org, plik klucza
 * /<klucz>.txt (rewrite jak llms.txt) i ręczne zgłoszenie całej mapy (raz na 24 h).
 * Moduł jest domyślnie wyłączony; nic nie wychodzi przy blog_public = 0 ani dla adresów noindex.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

use AktioConnector\Seo\Head;

defined( 'ABSPATH' ) || exit;

class IndexNow {

	const ENDPOINT     = 'https://api.indexnow.org/indexnow';
	const CRON         = 'aktio_connector_indexnow';
	const SCHEDULE     = 'aktio_five_minutes';
	const QUEUE        = 'aktio_connector_indexnow_queue';
	const LOG          = 'aktio_connector_indexnow_log';
	const LAST         = 'aktio_connector_indexnow_last';
	const SITEMAP_LAST = 'aktio_connector_indexnow_sitemap';
	const QUERY_VAR    = 'aktio_indexnow_key';
	const BATCH        = 10000;
	const LOG_MAX      = 50;

	public static function register(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
		add_action( self::CRON, array( __CLASS__, 'flush' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_remove' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_remove' ) );
		add_action( 'created_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'pre_delete_term', array( __CLASS__, 'on_term_delete' ), 10, 2 );
	}

	/**
	 * Hook aktywacji: reguła klucza i cron (flush reguł robi Crawlers::activate, rejestrowany później).
	 */
	public static function activate(): void {
		self::add_rewrite();
		self::schedule();
	}

	public static function deactivate(): void {
		self::unschedule();
	}

	public static function cron_schedules( $schedules ) {
		$schedules = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'aktio-connector' ),
		);
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE, self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	public static function add_rewrite(): void {
		add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * template_redirect: wypisuje plik klucza albo oddaje 404 motywowi.
	 */
	public static function serve(): void {
		$requested = get_query_var( self::QUERY_VAR );
		if ( ! is_string( $requested ) || '' === $requested ) {
			return;
		}
		$response = self::key_response( $requested );
		if ( 200 !== $response['status'] ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		status_header( 200 );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- zwykły tekst, nie HTML.
		exit;
	}

	/**
	 * Odpowiedź dla /<klucz>.txt: {status, headers, body}. 404 dla obcego klucza i wyłączonego modułu.
	 */
	public static function key_response( string $requested ): array {
		$settings = Settings::get()['indexnow'];
		if ( ! $settings['enabled'] || ! hash_equals( $settings['key'], $requested ) ) {
			return array(
				'status'  => 404,
				'headers' => array(),
				'body'    => '',
			);
		}
		return array(
			'status'  => 200,
			'headers' => array( 'Content-Type' => 'text/plain; charset=utf-8' ),
			'body'    => $settings['key'],
		);
	}

	/**
	 * Moduł włączony i witryna publiczna.
	 */
	public static function active(): bool {
		return Settings::get()['indexnow']['enabled'] && '0' !== (string) get_option( 'blog_public', '1' );
	}

	/**
	 * Czas ostatniego udanego zgłoszenia (ISO 8601) albo null.
	 */
	public static function last(): ?string {
		$value = get_option( self::LAST, '' );
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Dodaje adres do kolejki (tylko własny host, bez duplikatów, tylko gdy moduł aktywny).
	 */
	public static function queue( string $url ): void {
		if ( '' === $url || ! self::active() ) {
			return;
		}
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return;
		}
		$queue = self::stored_queue();
		if ( in_array( $url, $queue, true ) ) {
			return;
		}
		$queue[] = $url;
		update_option( self::QUEUE, $queue, false );
	}

	/**
	 * @param string   $new  Nowy status.
	 * @param string   $old  Poprzedni status.
	 * @param \WP_Post $post Wpis.
	 */
	public static function on_transition( $new, $old, $post ): void {
		unset( $old );
		if ( 'publish' === $new && $post instanceof \WP_Post ) {
			self::queue_post( $post );
		}
	}

	/**
	 * Kosz lub trwałe usunięcie: adres opublikowanego wpisu zgłaszamy, póki permalink jest jeszcze właściwy.
	 *
	 * @param int $post_id ID wpisu.
	 */
	public static function on_remove( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( $post instanceof \WP_Post ) {
			self::queue_post( $post );
		}
	}

	/**
	 * @param int    $term_id  ID terminu.
	 * @param int    $tt_id    ID taksonomii terminu.
	 * @param string $taxonomy Taksonomia.
	 */
	public static function on_term( $term_id, $tt_id, $taxonomy = '' ): void {
		unset( $tt_id );
		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( ! $term instanceof \WP_Term || ! self::public_taxonomy( $term->taxonomy ) ) {
			return;
		}
		// Puste archiwum tagu ma noindex (Head::noindex).
		if ( 'post_tag' === $term->taxonomy && 0 === (int) $term->count ) {
			return;
		}
		self::queue_term( $term );
	}

	/**
	 * @param int    $term_id  ID terminu.
	 * @param string $taxonomy Taksonomia.
	 */
	public static function on_term_delete( $term_id, $taxonomy ): void {
		$term = get_term( (int) $term_id, (string) $taxonomy );
		if ( $term instanceof \WP_Term && self::public_taxonomy( $term->taxonomy ) ) {
			self::queue_term( $term );
		}
	}

	/**
	 * Wysyła kolejkę (do 10 000 adresów w jednym POST). 2xx i błędy klienta (poza 429) usuwają
	 * wysłaną partię z kolejki; 429, 5xx i błąd sieci zostawiają ją do następnego przebiegu.
	 *
	 * @return array{sent:int, code:int|null}
	 */
	public static function flush(): array {
		$queue = self::stored_queue();
		if ( ! self::active() ) {
			delete_option( self::QUEUE );
			return array( 'sent' => 0, 'code' => null );
		}
		if ( ! $queue ) {
			return array( 'sent' => 0, 'code' => null );
		}
		$batch = array_slice( $queue, 0, self::BATCH );
		$code  = self::send( $batch );
		if ( null !== $code && 429 !== $code && $code < 500 ) {
			$rest = array_slice( $queue, count( $batch ) );
			if ( $rest ) {
				update_option( self::QUEUE, $rest, false );
			} else {
				delete_option( self::QUEUE );
			}
		}
		$ok = null !== $code && $code >= 200 && $code < 300;
		return array(
			'sent' => $ok ? count( $batch ) : 0,
			'code' => $code,
		);
	}

	/**
	 * Zgłasza wszystkie publiczne adresy (strona główna i opublikowane treści bez noindex) — raz na 24 h.
	 *
	 * @return array{queued:int, sent:int, code:int|null}|\WP_Error
	 */
	public static function submit_sitemap() {
		if ( ! self::active() ) {
			return new \WP_Error(
				'aktio_indexnow_disabled',
				__( 'IndexNow is disabled or the site is not public.', 'aktio-connector' ),
				array( 'status' => 400 )
			);
		}
		$last = (int) get_option( self::SITEMAP_LAST, 0 );
		if ( $last > 0 && time() - $last < DAY_IN_SECONDS ) {
			return new \WP_Error(
				'aktio_rate_limited',
				__( 'The sitemap can be submitted once every 24 hours.', 'aktio-connector' ),
				array( 'status' => 429 )
			);
		}
		update_option( self::SITEMAP_LAST, time(), false );

		$urls = array( home_url( '/' ) );
		$ids  = get_posts(
			array(
				'post_type'      => array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) ),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => self::BATCH - 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && ! Head::noindex_for_post( $post ) ) {
				$urls[] = (string) get_permalink( $post );
			}
		}
		$urls   = array_values( array_unique( $urls ) );
		$code   = self::send( $urls );
		$ok     = null !== $code && $code >= 200 && $code < 300;
		return array(
			'queued' => count( $urls ),
			'sent'   => $ok ? count( $urls ) : 0,
			'code'   => $code,
		);
	}

	/**
	 * Ostatnie $n wpisów dziennika, najnowsze pierwsze: {time, code, count}.
	 */
	public static function log( int $n = 10 ): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? array_slice( $log, 0, max( 0, $n ) ) : array();
	}

	/**
	 * Jeden POST do IndexNow; zwraca kod HTTP albo null przy błędzie sieci. Zapisuje dziennik.
	 *
	 * @param string[] $urls Adresy.
	 */
	private static function send( array $urls ): ?int {
		$key      = Settings::get()['indexnow']['key'];
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
						'key'         => $key,
						'keyLocation' => home_url( '/' . $key . '.txt' ),
						'urlList'     => array_values( $urls ),
					)
				),
			)
		);
		$code     = is_wp_error( $response ) ? null : (int) wp_remote_retrieve_response_code( $response );

		$log = self::log( self::LOG_MAX );
		array_unshift(
			$log,
			array(
				'time'  => gmdate( 'c' ),
				'code'  => $code,
				'count' => count( $urls ),
			)
		);
		update_option( self::LOG, array_slice( $log, 0, self::LOG_MAX ), false );
		if ( null !== $code && $code >= 200 && $code < 300 ) {
			update_option( self::LAST, gmdate( 'c' ), false );
		}
		return $code;
	}

	private static function queue_post( \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public || Head::noindex_for_post( $post ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( is_string( $url ) ) {
			self::queue( $url );
		}
	}

	private static function queue_term( \WP_Term $term ): void {
		$url = get_term_link( $term );
		if ( is_string( $url ) ) {
			self::queue( $url );
		}
	}

	private static function public_taxonomy( string $taxonomy ): bool {
		$object = get_taxonomy( $taxonomy );
		return $object && $object->public;
	}

	private static function stored_queue(): array {
		$queue = get_option( self::QUEUE, array() );
		return is_array( $queue ) ? array_values( array_filter( $queue, 'is_string' ) ) : array();
	}
}
