<?php
/**
 * Dane katalogu dla agenta Aktio: skan produktów, media bez ALT i masowy zapis ALT.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Catalog {

	const MAX_PER_PAGE = 100;
	const MAX_BATCH    = 50;
	const MAX_ALT      = 300;

	/**
	 * GET products/scan: produkty ze zliczonymi zdjęciami i brakami ALT (tylko odczyt).
	 */
	public static function scan_products( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! post_type_exists( 'product' ) ) {
			return rest_ensure_response(
				array(
					'items'            => array(),
					'total'            => 0,
					'total_pages'      => 0,
					'brands_supported' => false,
				)
			);
		}
		list( $page, $per_page ) = self::paging( $request );
		$query                   = new \WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( $product ) {
				$items[] = self::product_row( $product );
			}
		}

		return rest_ensure_response(
			array(
				'items'            => $items,
				'total'            => (int) $query->found_posts,
				'total_pages'      => (int) $query->max_num_pages,
				'brands_supported' => taxonomy_exists( 'product_brand' ),
			)
		);
	}

	private static function product_row( \WC_Product $product ): array {
		$image_ids = array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) );
		$missing   = 0;
		foreach ( $image_ids as $image_id ) {
			if ( '' === trim( (string) get_post_meta( (int) $image_id, '_wp_attachment_image_alt', true ) ) ) {
				++$missing;
			}
		}

		// GTIN: pole globalne WooCommerce 9.2+ (global_unique_id); starsze wersje go nie mają.
		$gtin = method_exists( $product, 'get_global_unique_id' ) ? (string) $product->get_global_unique_id() : '';

		return array(
			'id'                 => $product->get_id(),
			'name'               => $product->get_name(),
			'brands'             => self::term_names( $product->get_id(), 'product_brand' ),
			'images'             => count( $image_ids ),
			'images_missing_alt' => $missing,
			'short_len'          => self::text_length( $product->get_short_description() ),
			'desc_len'           => self::text_length( $product->get_description() ),
			'sku'                => (string) $product->get_sku(),
			'gtin'               => $gtin,
			'weight'             => (string) $product->get_weight(),
			'categories'         => self::term_names( $product->get_id(), 'product_cat' ),
		);
	}

	private static function text_length( string $html ): int {
		return mb_strlen( trim( wp_strip_all_tags( $html ) ) );
	}

	/** @return string[] */
	private static function term_names( int $post_id, string $taxonomy ): array {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}
		$names = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		return is_wp_error( $names ) ? array() : array_values( $names );
	}

	/**
	 * GET media/missing-alt: obrazy z biblioteki mediów bez tekstu alternatywnego.
	 */
	public static function missing_alt( \WP_REST_Request $request ): \WP_REST_Response {
		list( $page, $per_page ) = self::paging( $request );
		$query                   = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);

		$items = array();
		foreach ( $query->posts as $attachment ) {
			$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
			$thumb  = wp_get_attachment_image_url( $attachment->ID, 'thumbnail' );
			$items[] = array(
				'id'          => $attachment->ID,
				'url_thumb'   => $thumb ? $thumb : (string) wp_get_attachment_url( $attachment->ID ),
				'title'       => get_the_title( $attachment ),
				'filename'    => wp_basename( (string) get_attached_file( $attachment->ID ) ),
				'attached_to' => $parent ? array(
					'id'    => $parent->ID,
					'type'  => $parent->post_type,
					'title' => get_the_title( $parent ),
				) : null,
			);
		}

		return rest_ensure_response(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
			)
		);
	}

	/**
	 * POST media/alt: {items: [{id, alt}] ≤ 50} → {results: [{id, ok, previous?, error?}]}.
	 * Błąd pojedynczego elementu nie przerywa paczki.
	 */
	public static function update_alt( \WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$items = is_array( $body ) ? ( $body['items'] ?? null ) : null;
		if ( ! is_array( $items ) || array() === $items || array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) {
			return new \WP_Error( 'aktio_invalid_params', __( 'The items field (non-empty list) is required.', 'aktio-connector' ), array( 'status' => 400 ) );
		}
		if ( count( $items ) > self::MAX_BATCH ) {
			/* translators: %d: maximum number of items per call */
			return new \WP_Error( 'aktio_invalid_params', sprintf( __( 'At most %d items per call.', 'aktio-connector' ), self::MAX_BATCH ), array( 'status' => 400 ) );
		}

		$results = array();
		foreach ( $items as $item ) {
			$results[] = self::update_one_alt( $item );
		}
		return rest_ensure_response( array( 'results' => $results ) );
	}

	private static function update_one_alt( $item ): array {
		$id = ( is_array( $item ) && isset( $item['id'] ) && is_int( $item['id'] ) ) ? $item['id'] : 0;
		if ( $id <= 0 || ! isset( $item['alt'] ) || ! is_string( $item['alt'] ) ) {
			return array(
				'id'    => $id,
				'ok'    => false,
				'error' => 'aktio_invalid_params',
			);
		}
		$post = get_post( $id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return array(
				'id'    => $id,
				'ok'    => false,
				'error' => 'aktio_not_found',
			);
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return array(
				'id'    => $id,
				'ok'    => false,
				'error' => 'rest_forbidden',
			);
		}
		$alt = sanitize_text_field( wp_strip_all_tags( $item['alt'] ) );
		if ( mb_strlen( $alt ) > self::MAX_ALT ) {
			return array(
				'id'    => $id,
				'ok'    => false,
				'error' => 'aktio_invalid_params',
			);
		}
		$previous = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		return array(
			'id'       => $id,
			'ok'       => true,
			'previous' => $previous,
		);
	}

	/** @return int[] [strona, na stronę] */
	private static function paging( \WP_REST_Request $request ): array {
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?? 20 );
		$per_page = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
		return array( $page, $per_page );
	}
}
