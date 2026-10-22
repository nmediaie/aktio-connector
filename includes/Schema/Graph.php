<?php
/**
 * Składanie jednego @graph JSON-LD i wypisanie go w wp_head.
 *
 * Węzły dokładają moduły przez filtr `aktio_connector_graph_nodes`
 * (ContentSchema — treść, ProductSchema — produkt WooCommerce). Graph nie zależy
 * od Detector::owns('content_schema'): produkt musi działać także wtedy, gdy
 * treść zapewnia inna wtyczka SEO.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Schema;

defined( 'ABSPATH' ) || exit;

class Graph {

	const FILTER = 'aktio_connector_graph_nodes';

	public static function register(): void {
		add_action( 'wp_head', array( __CLASS__, 'print_graph' ), 20 );
	}

	/**
	 * Lista węzłów dla bieżącego zapytania.
	 */
	public static function nodes(): array {
		$nodes = apply_filters( self::FILTER, array() );
		if ( ! is_array( $nodes ) ) {
			return array();
		}
		return array_values( array_filter( $nodes, 'is_array' ) );
	}

	/**
	 * Znacznik <script type="application/ld+json"> albo '' bez węzłów.
	 */
	public static function render(): string {
		$nodes = self::nodes();
		if ( empty( $nodes ) ) {
			return '';
		}
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			),
			JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( false === $json ) {
			return '';
		}
		return '<script type="application/ld+json">' . $json . '</script>' . "\n";
	}

	public static function print_graph(): void {
		// JSON zakodowany z JSON_HEX_TAG — nie da się zamknąć znacznika; escapowanie HTML zepsułoby JSON.
		echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Tekst bez HTML i shortcode'ów, skrócony do $max znaków.
	 */
	public static function plain( string $text, int $max = 300 ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
