<?php
/**
 * Głowa strony bez wtyczki SEO: opis meta, Open Graph, karta X, własny tytuł i noindex.
 * Każda funkcja działa tylko, gdy Aktio ją „posiada” (Detector::owns) — przy wtyczce SEO nic nie dublujemy.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Seo;

use AktioConnector\Detector;
use AktioConnector\Schema\Graph;
use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class Head {

	const DESCRIPTION_MAX = 155;

	const OG_FILTER = 'aktio_connector_og_tags';

	public static function register(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_title' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ), 20 );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'sitemap_posts_args' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'sitemap_taxonomies_args' ) );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'sitemap_post_types' ) );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sitemap_provider' ), 10, 2 );
	}

	/**
	 * Opis meta dla bieżącego zapytania ('' gdy brak).
	 */
	public static function description(): string {
		$object = self::queried_object();

		if ( is_front_page() ) {
			$own = $object ? self::own_description( $object ) : '';
			if ( '' !== $own ) {
				return $own;
			}
			$home = Settings::get()['seo']['home_description'];
			if ( '' !== trim( $home ) ) {
				return self::trim_text( $home );
			}
			return self::trim_text( (string) get_bloginfo( 'description' ) );
		}

		if ( $object instanceof \WP_Post ) {
			$own = self::own_description( $object );
			if ( '' !== $own ) {
				return $own;
			}
			// Treści chronionej hasłem lub niepublikowanej nie wypisujemy do <head>.
			if ( 'publish' !== $object->post_status || post_password_required( $object ) ) {
				return '';
			}
			if ( 'product' === $object->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $object->ID );
				$short   = $product ? self::trim_text( $product->get_short_description() ) : '';
				if ( '' !== $short ) {
					return $short;
				}
			}
			$text = self::trim_text( $object->post_excerpt );
			if ( '' !== $text ) {
				return $text;
			}
			return self::trim_text( $object->post_content );
		}

		if ( $object instanceof \WP_Term ) {
			$own = self::own_description( $object );
			if ( '' !== $own ) {
				return $own;
			}
			return self::trim_text( $object->description );
		}

		return '';
	}

	/**
	 * Tagi Open Graph (i product:*) bieżącego zapytania: właściwość => wartość. Puste wartości są pomijane.
	 *
	 * @return array<string,string>
	 */
	public static function og(): array {
		$object = self::queried_object();
		$post   = $object instanceof \WP_Post ? $object : null;
		$is_product = $post && 'product' === $post->post_type && function_exists( 'wc_get_product' );

		$tags = array(
			'og:locale'    => self::locale(),
			'og:type'      => $is_product ? 'product' : ( $post && 'post' === $post->post_type ? 'article' : 'website' ),
			'og:title'     => self::og_title( $object ),
			'og:site_name' => Graph::plain( (string) get_bloginfo( 'name' ), 200 ),
			'og:url'       => self::current_url( $object ),
		);

		$description = self::description();
		if ( '' !== $description ) {
			$tags['og:description'] = $description;
		}

		$image = self::image( $post );
		if ( $image ) {
			$tags['og:image'] = $image['url'];
			if ( ! empty( $image['width'] ) && ! empty( $image['height'] ) ) {
				$tags['og:image:width']  = (string) $image['width'];
				$tags['og:image:height'] = (string) $image['height'];
			}
		}

		if ( $is_product ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				if ( '' !== (string) $product->get_price() ) {
					$tags['product:price:amount']   = number_format( (float) wc_get_price_to_display( $product ), wc_get_price_decimals(), '.', '' );
					$tags['product:price:currency'] = get_woocommerce_currency();
				}
				$tags['product:availability'] = self::availability( $product );
			}
		}

		/**
		 * Filtr tagów Open Graph.
		 *
		 * @param array<string,string> $tags Właściwość => wartość.
		 */
		$filtered = apply_filters( self::OG_FILTER, $tags );
		if ( ! is_array( $filtered ) ) {
			return array();
		}
		$out = array();
		foreach ( $filtered as $property => $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$out[ (string) $property ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Czy bieżące zapytanie ma dostać noindex,follow.
	 */
	public static function noindex(): bool {
		if ( is_search() || is_attachment() ) {
			return true;
		}
		if ( ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() ) ) {
			return true;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			return $post instanceof \WP_Post && self::noindex_for_post( $post );
		}
		if ( is_tag() ) {
			$term = get_queried_object();
			return $term instanceof \WP_Term && 0 === (int) $term->count;
		}
		if ( is_author() ) {
			return self::single_author();
		}
		return false;
	}

	/**
	 * Czy wpis ma noindex z reguł wtyczki (załączniki, koszyk, zamówienie, konto WooCommerce).
	 */
	public static function noindex_for_post( \WP_Post $p ): bool {
		if ( 'attachment' === $p->post_type ) {
			return true;
		}
		if ( 'page' === $p->post_type && function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				if ( (int) wc_get_page_id( $page ) === (int) $p->ID ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function print_head(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}
		if ( Detector::owns( 'meta_description' ) ) {
			$description = self::description();
			if ( '' !== $description ) {
				echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
			}
		}

		$need_og = Detector::owns( 'open_graph' );
		$need_x  = Detector::owns( 'twitter_card' );
		if ( ! $need_og && ! $need_x ) {
			return;
		}
		$og = self::og();

		if ( $need_og ) {
			foreach ( $og as $property => $content ) {
				echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
			}
		}
		if ( $need_x ) {
			$x = array( 'twitter:card' => isset( $og['og:image'] ) ? 'summary_large_image' : 'summary' );
			foreach ( array(
				'twitter:title'       => 'og:title',
				'twitter:description' => 'og:description',
				'twitter:image'       => 'og:image',
			) as $name => $source ) {
				if ( isset( $og[ $source ] ) ) {
					$x[ $name ] = $og[ $source ];
				}
			}
			foreach ( $x as $name => $content ) {
				echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $content ) . '" />' . "\n";
			}
		}
	}

	/**
	 * Własny tytuł (pole „Tytuł SEO”) przez pre_get_document_title.
	 *
	 * @param mixed $title Dotychczasowy tytuł.
	 */
	public static function filter_title( $title ) {
		if ( ! Detector::owns( 'title' ) || ! class_exists( '\AktioConnector\Seo\Fields' ) ) {
			return $title;
		}
		$object = self::queried_object();
		if ( ! $object ) {
			return $title;
		}
		$own = Graph::plain( (string) Fields::title_for( $object ), 200 );
		return '' !== $own ? $own : $title;
	}

	/**
	 * @param array $robots Dyrektywy meta robots.
	 */
	public static function filter_robots( $robots ) {
		if ( ! is_array( $robots ) || ! Detector::owns( 'robots_meta' ) || ! self::noindex() ) {
			return $robots;
		}
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['nofollow'] );
		return $robots;
	}

	public static function sitemap_posts_args( $args ) {
		if ( ! is_array( $args ) || ! Detector::owns( 'robots_meta' ) || ! function_exists( 'wc_get_page_id' ) ) {
			return $args;
		}
		$exclude = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id > 0 ) {
				$exclude[] = $id;
			}
		}
		if ( $exclude ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $exclude );
		}
		return $args;
	}

	public static function sitemap_taxonomies_args( $args ) {
		if ( is_array( $args ) && Detector::owns( 'robots_meta' ) ) {
			$args['hide_empty'] = true;
		}
		return $args;
	}

	public static function sitemap_post_types( $types ) {
		if ( is_array( $types ) && Detector::owns( 'robots_meta' ) ) {
			unset( $types['attachment'] );
		}
		return $types;
	}

	/**
	 * Mapa bez użytkowników, gdy jest jeden autor (jego archiwum ma noindex).
	 *
	 * @param mixed  $provider Dostawca mapy.
	 * @param string $name     Nazwa dostawcy.
	 */
	public static function sitemap_provider( $provider, $name ) {
		if ( 'users' === $name && Detector::owns( 'robots_meta' ) && self::single_author() ) {
			return false;
		}
		return $provider;
	}

	private static function single_author(): bool {
		$ids = get_users(
			array(
				'has_published_posts' => true,
				'fields'              => 'ID',
				'number'              => 2,
			)
		);
		return count( $ids ) <= 1;
	}

	/**
	 * Wpis albo termin bieżącego zapytania (null dla pozostałych widoków).
	 *
	 * @return \WP_Post|\WP_Term|null
	 */
	private static function queried_object() {
		if ( ! is_singular() && ! is_category() && ! is_tag() && ! is_tax() && ! is_front_page() ) {
			return null;
		}
		$object = get_queried_object();
		return ( $object instanceof \WP_Post || $object instanceof \WP_Term ) ? $object : null;
	}

	/**
	 * Własny opis z pola „Opis SEO” (gdy istnieje klasa Fields).
	 *
	 * @param \WP_Post|\WP_Term $object Obiekt.
	 */
	private static function own_description( $object ): string {
		if ( ! class_exists( '\AktioConnector\Seo\Fields' ) ) {
			return '';
		}
		return self::trim_text( (string) Fields::description_for( $object ), 160 );
	}

	/**
	 * Tekst bez HTML i shortcode'ów, ucięty do $max znaków na granicy słowa.
	 */
	private static function trim_text( string $text, int $max = self::DESCRIPTION_MAX ): string {
		$text = Graph::plain( $text, 5000 );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max );
		// Cięcie w środku słowa: cofamy do ostatniej spacji.
		if ( ! preg_match( '/\s/u', mb_substr( $text, $max, 1 ) ) ) {
			$pos = mb_strrpos( $cut, ' ' );
			if ( false !== $pos && $pos > 0 ) {
				$cut = mb_substr( $cut, 0, $pos );
			}
		}
		return rtrim( $cut, " \t\n\r,;:-" );
	}

	private static function locale(): string {
		$lang = Settings::get()['content_language'];
		if ( class_exists( '\AktioConnector\Language' ) ) {
			$lang = \AktioConnector\Language::current();
		}
		return str_replace( '-', '_', (string) $lang );
	}

	/**
	 * @param \WP_Post|\WP_Term|null $object Obiekt zapytania.
	 */
	private static function og_title( $object ): string {
		if ( $object && class_exists( '\AktioConnector\Seo\Fields' ) ) {
			$own = Graph::plain( (string) Fields::title_for( $object ), 200 );
			if ( '' !== $own ) {
				return $own;
			}
		}
		if ( $object instanceof \WP_Post && ! is_front_page() ) {
			return Graph::plain( $object->post_title, 200 );
		}
		if ( $object instanceof \WP_Term ) {
			return Graph::plain( $object->name, 200 );
		}
		return Graph::plain( (string) get_bloginfo( 'name' ), 200 );
	}

	/**
	 * @param \WP_Post|\WP_Term|null $object Obiekt zapytania.
	 */
	private static function current_url( $object ): string {
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		if ( $object instanceof \WP_Post ) {
			return (string) get_permalink( $object );
		}
		if ( $object instanceof \WP_Term ) {
			$link = get_term_link( $object );
			return is_string( $link ) ? $link : '';
		}
		return home_url( add_query_arg( array() ) );
	}

	/**
	 * Obrazek: wyróżniony/główny → logo firmy → obrazek domyślny z ustawień → null.
	 *
	 * @return array{url:string,width?:int,height?:int}|null
	 */
	private static function image( ?\WP_Post $post ): ?array {
		if ( $post ) {
			$found = self::attachment_image( (int) get_post_thumbnail_id( $post ) );
			if ( $found ) {
				return $found;
			}
		}
		$settings = Settings::get();
		$logo     = $settings['company']['logo'];
		if ( '' !== $logo ) {
			return array( 'url' => $logo );
		}
		$found = self::attachment_image( (int) get_theme_mod( 'custom_logo' ) );
		if ( $found ) {
			return $found;
		}
		$default = $settings['seo']['default_og_image'];
		return '' !== $default ? array( 'url' => $default ) : null;
	}

	private static function attachment_image( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'full' );
		if ( ! $src || empty( $src[0] ) ) {
			return null;
		}
		return array(
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
		);
	}

	private static function availability( \WC_Product $p ): string {
		switch ( $p->get_stock_status() ) {
			case 'instock':
				return 'in stock';
			case 'onbackorder':
				return 'preorder';
			default:
				return 'out of stock';
		}
	}
}
