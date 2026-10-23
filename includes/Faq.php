<?php
/**
 * Blok aktio/faq i shortcode [aktio_faq]: treść zawsze, schema FAQPage tylko
 * gdy Aktio „posiada” content_schema.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

use AktioConnector\Schema\Graph;

defined( 'ABSPATH' ) || exit;

class Faq {

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_shortcode( 'aktio_faq', array( __CLASS__, 'render_shortcode' ) );
		add_filter( Graph::FILTER, array( __CLASS__, 'add_nodes' ) );
	}

	public static function register_block(): void {
		$dir = dirname( AKTIO_CONNECTOR_FILE ) . '/blocks/faq';
		// Bez bundlera i pliku .asset.php — zależności podajemy ręcznie.
		wp_register_script(
			'aktio-faq-editor',
			plugins_url( 'blocks/faq/index.js', AKTIO_CONNECTOR_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
			AKTIO_CONNECTOR_VERSION,
			true
		);
		register_block_type(
			$dir,
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	public static function render_block( $attributes ): string {
		$items = isset( $attributes['items'] ) && is_array( $attributes['items'] ) ? $attributes['items'] : array();
		return self::render( self::clean( $items ) );
	}

	public static function render_shortcode( $atts, $content = '' ): string {
		return self::render( self::parse( (string) $content ) );
	}

	/**
	 * Parsuje „Q: …” / „A: …” linia po linii; linie bez prefiksu dopisuje do bieżącego pola.
	 */
	public static function parse( string $content ): array {
		$content = (string) preg_replace( '#<br\s*/?>|</p>#i', "\n", $content );
		$items   = array();
		$current = null;
		$field   = 'q';
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $line ) {
			$line = trim( (string) preg_replace( '#^<p[^>]*>#i', '', trim( $line ) ) );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^Q:\s*(.*)$/i', $line, $m ) ) {
				if ( null !== $current ) {
					$items[] = $current;
				}
				$current = array(
					'q' => $m[1],
					'a' => '',
				);
				$field   = 'q';
			} elseif ( null !== $current && preg_match( '/^A:\s*(.*)$/i', $line, $m ) ) {
				$current['a'] = $m[1];
				$field        = 'a';
			} elseif ( null !== $current ) {
				$current[ $field ] = trim( $current[ $field ] . ' ' . $line );
			}
		}
		if ( null !== $current ) {
			$items[] = $current;
		}
		return self::clean( $items );
	}

	/**
	 * Zostawia tylko pozycje z pytaniem i odpowiedzią.
	 */
	private static function clean( array $items ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$q = isset( $item['q'] ) ? trim( (string) $item['q'] ) : '';
			$a = isset( $item['a'] ) ? trim( (string) $item['a'] ) : '';
			if ( '' !== $q && '' !== $a ) {
				$out[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}
		return $out;
	}

	private static function render( array $items ): string {
		if ( ! $items ) {
			return '';
		}
		$html = '<div class="aktio-faq">';
		foreach ( $items as $item ) {
			$html .= '<details><summary>' . esc_html( $item['q'] ) . '</summary><div>' . wp_kses_post( $item['a'] ) . '</div></details>';
		}
		return $html . '</div>';
	}

	/**
	 * Wszystkie pozycje FAQ z treści wpisu (bloki + shortcody).
	 */
	private static function items_from_post( \WP_Post $post ): array {
		$items = array();
		if ( 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return $items;
		}
		self::collect_blocks( parse_blocks( $post->post_content ), $items );
		if ( has_shortcode( $post->post_content, 'aktio_faq' ) ) {
			preg_match_all( '/' . get_shortcode_regex( array( 'aktio_faq' ) ) . '/', $post->post_content, $m, PREG_SET_ORDER );
			foreach ( $m as $match ) {
				$items = array_merge( $items, self::parse( (string) $match[5] ) );
			}
		}
		return $items;
	}

	private static function collect_blocks( array $blocks, array &$items ): void {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'aktio/faq' === $block['blockName'] ) {
				$attrs = isset( $block['attrs']['items'] ) && is_array( $block['attrs']['items'] ) ? $block['attrs']['items'] : array();
				$items = array_merge( $items, self::clean( $attrs ) );
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				self::collect_blocks( $block['innerBlocks'], $items );
			}
		}
	}

	public static function add_nodes( $nodes ) {
		if ( ! is_array( $nodes ) || ! Detector::owns( 'content_schema' ) || ! is_singular() ) {
			return $nodes;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return $nodes;
		}
		$items = self::items_from_post( $post );
		if ( ! $items ) {
			return $nodes;
		}
		$entities = array();
		foreach ( $items as $item ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => Graph::plain( $item['q'], 300 ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => Graph::plain( $item['a'], 5000 ),
				),
			);
		}
		// Jedna strona = jeden węzeł FAQPage, nawet przy kilku blokach.
		$nodes[] = array(
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $post ) . '#faq',
			'mainEntity' => $entities,
		);
		return $nodes;
	}
}
