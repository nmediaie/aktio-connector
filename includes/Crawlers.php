<?php
/**
 * Boty AI w robots.txt (filtr robots_txt) i plik llms.txt serwowany z korzenia witryny.
 * Fizyczny plik robots.txt/llms.txt ma pierwszeństwo (C5): wtedy Aktio nic nie dopisuje.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

use AktioConnector\Schema\Graph;

defined( 'ABSPATH' ) || exit;

class Crawlers {

	const BEGIN     = '# BEGIN Aktio Connector';
	const END       = '# END Aktio Connector';
	const QUERY_VAR = 'aktio_llms';
	const TRANSIENT = 'aktio_connector_llms';

	public static function register(): void {
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots' ), 99, 2 );
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ) );
		add_action( 'save_post', array( __CLASS__, 'clear_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'clear_cache' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'clear_cache' ) );
		add_action( 'update_option_blogdescription', array( __CLASS__, 'clear_cache' ) );
		add_action( 'aktio_connector_settings_updated', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Hook aktywacji: reguła musi istnieć przed flushem.
	 */
	public static function activate(): void {
		self::add_rewrite();
		flush_rewrite_rules();
	}

	/**
	 * Hook dezaktywacji: reguła jest już załadowana w tym żądaniu, więc zamiast
	 * flushu kasujemy zapisane reguły — WordPress przebuduje je bez nas.
	 */
	public static function deactivate(): void {
		delete_option( 'rewrite_rules' );
	}

	public static function add_rewrite(): void {
		add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Adapter filtra robots_txt (sygnatura WP: $output, $public).
	 */
	public static function filter_robots( $output, $public ) {
		return self::robots( (string) $output, (bool) $public );
	}

	/**
	 * Dopisuje blok botów AI. Strona niepubliczna (blog_public = 0) i fizyczny
	 * robots.txt zostawiają wyjście bez zmian; blok nie jest dopisywany dwa razy.
	 */
	public static function robots( string $output, bool $public ): string {
		if ( ! $public || false !== strpos( $output, self::BEGIN ) ) {
			return $output;
		}
		$features = Detector::features();
		if ( 'file' === $features['robots']['provided_by'] ) {
			return $output;
		}

		$lines = array( self::BEGIN );
		$bots  = Settings::get()['bots'];
		foreach ( Settings::BOTS as $i => $bot ) {
			if ( $i > 0 ) {
				$lines[] = '';
			}
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = 'block' === ( $bots[ $bot ] ?? 'allow' ) ? 'Disallow: /' : 'Allow: /';
		}
		$lines[] = self::END;

		return rtrim( $output ) . "\n\n" . implode( "\n", $lines ) . "\n";
	}

	/**
	 * template_redirect: wypisuje llms.txt albo oddaje 404 motywowi.
	 */
	public static function serve(): void {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$response = self::llms_response();
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
	 * Odpowiedź dla /llms.txt: {status, headers, body}. 404, gdy plik dostarcza
	 * ktoś inny albo strona jest prywatna (blog_public = 0).
	 */
	public static function llms_response(): array {
		if ( ! Detector::owns( 'llms' ) || '0' === (string) get_option( 'blog_public', '1' ) ) {
			return array(
				'status'  => 404,
				'headers' => array(),
				'body'    => '',
			);
		}
		return array(
			'status'  => 200,
			'headers' => array( 'Content-Type' => 'text/plain; charset=utf-8' ),
			'body'    => self::llms(),
		);
	}

	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Treść llms.txt (cache 1 h): nazwa, opis, strony, 10 ostatnich wpisów i
	 * (z WooCommerce) do 100 produktów. Bez treści chronionych hasłem i nieopublikowanych.
	 */
	public static function llms(): string {
		$cached = get_transient( self::TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$name = Settings::get()['company']['name'];
		$name = self::line( '' !== $name ? $name : (string) get_bloginfo( 'name' ) );
		$out  = '# ' . $name . "\n";
		$desc = self::line( (string) get_bloginfo( 'description' ) );
		if ( '' !== $desc ) {
			$out .= "\n> " . $desc . "\n";
		}

		$out .= self::section( 'Strony', self::post_lines( 'page', 50, array( 'menu_order' => 'ASC', 'title' => 'ASC' ) ) );
		$out .= self::section( 'Wpisy', self::post_lines( 'post', 10, array( 'date' => 'DESC' ) ) );
		if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' ) ) {
			$out .= self::section( 'Produkty', self::product_lines() );
		}

		set_transient( self::TRANSIENT, $out, HOUR_IN_SECONDS );
		return $out;
	}

	private static function section( string $title, array $lines ): string {
		return $lines ? "\n## " . $title . "\n" . implode( "\n", $lines ) . "\n" : '';
	}

	private static function post_lines( string $type, int $limit, array $orderby ): array {
		$posts = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => $limit,
				'orderby'          => $orderby,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$lines = array();
		foreach ( $posts as $post ) {
			$lines[] = self::link( get_the_title( $post ), get_permalink( $post ), '' );
		}
		return $lines;
	}

	private static function product_lines(): array {
		$ids   = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => 300,
				'orderby'        => array( 'date' => 'DESC' ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$lines = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || 'hidden' === $product->get_catalog_visibility() ) {
				continue;
			}
			$price = $product->get_price();
			$text  = '';
			if ( '' !== (string) $price ) {
				$shown = (float) wc_get_price_to_display( $product );
				$text  = number_format( $shown, wc_get_price_decimals(), '.', '' ) . ' ' . get_woocommerce_currency();
			}
			$lines[] = self::link( $product->get_name(), get_permalink( $id ), $text );
			if ( count( $lines ) >= 100 ) {
				break;
			}
		}
		return $lines;
	}

	private static function link( string $title, string $url, string $note ): string {
		$title = str_replace( array( '[', ']' ), array( '(', ')' ), self::line( $title ) );
		$line  = '- [' . $title . '](' . $url . ')';
		return '' !== $note ? $line . ': ' . $note : $line;
	}

	/**
	 * Tekst jednoliniowy, bez HTML. Graph::plain dekoduje encje po usunięciu
	 * znaczników, więc `&lt;b&gt;` wraca jako `<b>` — drugie czyszczenie to wyłapuje.
	 */
	private static function line( string $text ): string {
		return trim( wp_strip_all_tags( Graph::plain( $text, 200 ) ) );
	}
}
