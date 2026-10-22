<?php
/**
 * Schema produktów WooCommerce: Product / ProductGroup, Offer, AggregateRating, Review.
 * Dokłada węzeł tylko gdy Aktio „posiada” product_schema (żadna wtyczka SEO jej nie dostarcza)
 * i wyłącza własny znacznik produktu WooCommerce, żeby strona miała jeden węzeł produktu.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Schema;

use AktioConnector\Detector;
use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class ProductSchema {

	/** Maksymalna liczba opinii w znaczniku. */
	const MAX_REVIEWS = 5;

	/** Maksymalna liczba wariantów w ProductGroup (rozmiar strony). */
	const MAX_VARIANTS = 50;

	/** Dostawa wyliczona ze strefy raz na węzeł (null = jeszcze nie liczona). */
	private static $zone_shipping = null;

	/** Licznik odczytów stref wysyłki (szew dla testów). */
	public static $zone_lookups = 0;

	public static function register(): void {
		// Priorytet 20: po ContentSchema (10), żeby produkt mógł wskazać istniejący WebPage.
		add_filter( Graph::FILTER, array( __CLASS__, 'add_nodes' ), 20 );
		add_filter( 'woocommerce_structured_data_product', array( __CLASS__, 'suppress_core_product' ) );
		add_filter( 'woocommerce_structured_data_review', array( __CLASS__, 'suppress_core_product' ) );
	}

	/**
	 * Dotyczy też osobnych węzłów Review rdzenia (opinie są zagnieżdżone w produkcie).
	 * Pusta tablica sprawia, że WC_Structured_Data::set_data() odrzuca węzeł (brak @type).
	 */
	public static function suppress_core_product( $markup ) {
		return Detector::owns( 'product_schema' ) ? array() : $markup;
	}

	public static function add_nodes( $nodes ) {
		if ( ! is_array( $nodes ) || ! function_exists( 'wc_get_product' ) || ! Detector::owns( 'product_schema' ) ) {
			return $nodes;
		}
		if ( ! is_singular( 'product' ) ) {
			return $nodes;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return $nodes;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof \WC_Product ) {
			return $nodes;
		}

		$node    = self::node( $product );
		$nodes[] = $node;

		// Produkt jest głównym bytem strony tylko tam, gdzie Aktio samo dodało WebPage.
		foreach ( $nodes as $i => $existing ) {
			if ( is_array( $existing ) && isset( $existing['@type'] ) && 'WebPage' === $existing['@type'] ) {
				$nodes[ $i ]['mainEntity'] = array( '@id' => $node['@id'] );
				break;
			}
		}
		return $nodes;
	}

	/**
	 * Węzeł Product (albo ProductGroup dla produktu zmiennego).
	 */
	public static function node( \WC_Product $p ): array {
		self::$zone_shipping = null;
		$url                 = (string) get_permalink( $p->get_id() );
		$variable = $p->is_type( 'variable' );

		$node = array(
			'@type' => $variable ? 'ProductGroup' : 'Product',
			'@id'   => $url . '#product',
			'url'   => $url,
			'name'  => Graph::plain( $p->get_name() ),
		);

		$desc = Graph::plain( '' !== trim( (string) $p->get_short_description() ) ? $p->get_short_description() : $p->get_description() );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}
		$images = self::images( $p );
		if ( $images ) {
			$node['image'] = $images;
		}
		if ( '' !== (string) $p->get_sku() ) {
			$node['sku'] = (string) $p->get_sku();
		}
		$gtin = method_exists( $p, 'get_global_unique_id' ) ? (string) $p->get_global_unique_id() : '';
		if ( '' !== $gtin ) {
			$node['gtin'] = $gtin;
		}
		$brand = self::brand( $p );
		if ( '' !== $brand ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}

		if ( $variable ) {
			$node['productGroupID'] = '' !== (string) $p->get_sku() ? (string) $p->get_sku() : (string) $p->get_id();
			$varies                 = self::varies_by( $p );
			if ( $varies ) {
				$node['variesBy'] = $varies;
			}
			$variants = self::variants( $p, $url );
			if ( $variants ) {
				$node['hasVariant'] = $variants;
			}
		} else {
			$offer = self::offer( $p, $url );
			if ( $offer ) {
				$node['offers'] = $offer;
			}
		}

		$rating = self::rating( $p );
		if ( $rating ) {
			$node['aggregateRating'] = $rating;
			$reviews                 = self::reviews( $p );
			if ( $reviews ) {
				$node['review'] = $reviews;
			}
		}
		return $node;
	}

	private static function images( \WC_Product $p ): array {
		$ids = array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() );
		$out = array();
		foreach ( $ids as $id ) {
			$src = $id ? wp_get_attachment_image_url( (int) $id, 'full' ) : '';
			if ( $src ) {
				$out[] = $src;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function brand( \WC_Product $p ): string {
		if ( ! taxonomy_exists( 'product_brand' ) ) {
			return '';
		}
		$terms = wp_get_post_terms( $p->get_id(), 'product_brand', array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}
		return Graph::plain( (string) reset( $terms ), 100 );
	}

	/**
	 * Oś zmienności: właściwości schema.org dla koloru/rozmiaru/materiału/wzoru, inaczej etykieta atrybutu.
	 */
	private static function varies_by( \WC_Product $p ): array {
		$known = array( 'color', 'size', 'material', 'pattern' );
		$out   = array();
		foreach ( array_keys( $p->get_variation_attributes() ) as $name ) {
			$label = Graph::plain( wc_attribute_label( (string) $name, $p ), 100 );
			$slug  = strtolower( preg_replace( '/^pa_/', '', sanitize_title( (string) $name ) ) );
			$out[] = in_array( $slug, $known, true ) ? 'https://schema.org/' . $slug : $label;
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	private static function variants( \WC_Product $p, string $url ): array {
		$out = array();
		foreach ( array_slice( $p->get_children(), 0, self::MAX_VARIANTS ) as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== get_post_status( $child_id ) ) {
				continue;
			}
			$offer = self::offer( $variation, (string) $variation->get_permalink() );
			if ( ! $offer ) {
				continue; // Wariant bez ceny nie dostaje pustej oferty — pomijamy.
			}
			$v = array(
				'@type'  => 'Product',
				'@id'    => $url . '#variant-' . $variation->get_id(),
				'name'   => Graph::plain( $variation->get_name() ),
				'url'    => (string) $variation->get_permalink(),
				'offers' => $offer,
			);
			if ( '' !== (string) $variation->get_sku() ) {
				$v['sku'] = (string) $variation->get_sku();
			}
			$gtin = method_exists( $variation, 'get_global_unique_id' ) ? (string) $variation->get_global_unique_id() : '';
			if ( '' !== $gtin ) {
				$v['gtin'] = $gtin;
			}
			$img = $variation->get_image_id() ? wp_get_attachment_image_url( (int) $variation->get_image_id(), 'full' ) : '';
			if ( $img ) {
				$v['image'] = $img;
			}
			$out[] = $v;
		}
		return $out;
	}

	/**
	 * Offer albo [] gdy produkt nie ma ceny.
	 */
	private static function offer( \WC_Product $p, string $url ): array {
		if ( '' === (string) $p->get_price() ) {
			return array();
		}
		$price = (float) wc_get_price_to_display( $p );
		$offer = array(
			'@type'         => 'Offer',
			'url'           => $url,
			'price'         => number_format( $price, wc_get_price_decimals(), '.', '' ),
			'priceCurrency' => get_woocommerce_currency(),
			'availability'  => self::availability( $p ),
			'seller'        => self::seller(),
		);
		if ( $p->is_on_sale() && $p->get_date_on_sale_to() ) {
			$offer['priceValidUntil'] = $p->get_date_on_sale_to()->date( 'Y-m-d' );
		}
		$shipping = self::shipping_details( $p );
		if ( $shipping ) {
			$offer['shippingDetails'] = $shipping;
		}
		return $offer;
	}

	/**
	 * Referencja do węzła Organization Aktio albo, gdy węzła nie ma (treść zapewnia
	 * wtyczka SEO), sprzedawca inline — inaczej @id wisiałoby w próżni.
	 */
	private static function seller(): array {
		if ( Detector::owns( 'content_schema' ) ) {
			return array( '@id' => home_url( '/#organization' ) );
		}
		$name = Settings::get()['company']['name'];
		return array(
			'@type' => 'Organization',
			'name'  => Graph::plain( '' !== $name ? $name : get_bloginfo( 'name' ), 150 ),
		);
	}

	private static function availability( \WC_Product $p ): string {
		switch ( $p->get_stock_status() ) {
			case 'instock':
				return 'https://schema.org/InStock';
			case 'onbackorder':
				return 'https://schema.org/BackOrder';
			default:
				return 'https://schema.org/OutOfStock';
		}
	}

	/**
	 * Dostawa tylko przy jednej strefie wysyłki z jedną włączoną metodą „stała stawka”
	 * o liczbowym koszcie i lokalizacjami będącymi krajami. W pozostałych przypadkach brak.
	 */
	private static function shipping_details( \WC_Product $p ): array {
		if ( $p->is_virtual() || ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}
		if ( null === self::$zone_shipping ) {
			self::$zone_shipping = self::zone_shipping();
		}
		return self::$zone_shipping;
	}

	/**
	 * Część zależna tylko od stref wysyłki (liczona raz na węzeł produktu).
	 */
	private static function zone_shipping(): array {
		++self::$zone_lookups;
		$zones = \WC_Shipping_Zones::get_zones();
		if ( 1 !== count( $zones ) ) {
			return array();
		}
		$zone      = reset( $zones );
		$flat      = array();
		$countries = array();
		foreach ( (array) $zone['shipping_methods'] as $method ) {
			if ( 'yes' === $method->enabled ) {
				$flat[] = $method;
			}
		}
		if ( 1 !== count( $flat ) || 'flat_rate' !== $flat[0]->id ) {
			return array();
		}
		$cost = trim( (string) $flat[0]->get_option( 'cost' ) );
		if ( '' === $cost || ! is_numeric( $cost ) ) {
			return array();
		}
		foreach ( (array) $zone['zone_locations'] as $loc ) {
			if ( 'country' !== $loc->type ) {
				return array();
			}
			$countries[] = $loc->code;
		}
		if ( ! $countries ) {
			return array();
		}
		$destinations = array();
		foreach ( $countries as $code ) {
			$destinations[] = array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => $code,
			);
		}
		return array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array(
				'@type'    => 'MonetaryAmount',
				'value'    => number_format( (float) $cost, wc_get_price_decimals(), '.', '' ),
				'currency' => get_woocommerce_currency(),
			),
			'shippingDestination' => $destinations,
		);
	}

	private static function rating( \WC_Product $p ): array {
		$count   = (int) $p->get_review_count();
		$average = (float) $p->get_average_rating();
		if ( $count < 1 || $average <= 0 ) {
			return array();
		}
		return array(
			'@type'       => 'AggregateRating',
			'ratingValue' => number_format( $average, 2, '.', '' ),
			'reviewCount' => $count,
		);
	}

	private static function reviews( \WC_Product $p ): array {
		$comments = get_comments(
			array(
				'post_id' => $p->get_id(),
				'status'  => 'approve',
				'type'    => 'review',
				'number'  => self::MAX_REVIEWS,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);
		$out      = array();
		foreach ( $comments as $c ) {
			$stars = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			if ( $stars < 1 || $stars > 5 ) {
				continue;
			}
			$r = array(
				'@type'        => 'Review',
				'author'       => array(
					'@type' => 'Person',
					'name'  => Graph::plain( '' !== $c->comment_author ? $c->comment_author : __( 'Anonymous', 'aktio-connector' ), 100 ),
				),
				'datePublished' => get_comment_date( 'c', $c ),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => $stars,
					'bestRating'  => 5,
				),
			);
			$body = Graph::plain( $c->comment_content, 500 );
			if ( '' !== $body ) {
				$r['reviewBody'] = $body;
			}
			$out[] = $r;
		}
		return $out;
	}
}
