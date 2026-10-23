<?php
/**
 * Pola „Tytuł SEO” i „Opis SEO” per wpis, strona, produkt i termin (meta + boks edytora).
 * Zapis przez REST rdzenia i WooCommerce działa tylko, gdy Aktio jest właścicielem funkcji
 * (Detector::owns); przy wtyczce SEO zapis kończy się błędem aktio_seo_owned_elsewhere.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Seo;

use AktioConnector\Detector;

defined( 'ABSPATH' ) || exit;

class Fields {

	const TITLE_KEY = '_aktio_seo_title';
	const DESC_KEY  = '_aktio_seo_description';

	const TITLE_MAX = 70;
	const DESC_MAX  = 160;

	const NONCE_ACTION = 'aktio_seo_box';
	const NONCE_FIELD  = 'aktio_seo_nonce';

	const POST_TYPES = array( 'post', 'page', 'product' );
	const TAXONOMIES = array( 'category', 'post_tag', 'product_cat', 'product_tag' );

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_box' ), 10, 2 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_panel' ) );
		foreach ( self::POST_TYPES as $type ) {
			add_filter( 'rest_pre_insert_' . $type, array( __CLASS__, 'guard_rest' ), 10, 2 );
		}
		// Kontroler termów rdzenia ignoruje WP_Error z rest_pre_insert_*, więc termy blokujemy przed callbackiem trasy.
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_terms' ), 10, 3 );
		add_filter( 'woocommerce_rest_pre_insert_product_object', array( __CLASS__, 'guard_wc' ), 10, 2 );
	}

	/**
	 * Własny tytuł SEO wpisu albo terminu ('' gdy brak).
	 *
	 * @param \WP_Post|\WP_Term $object Obiekt.
	 */
	public static function title_for( $object ): string {
		return self::read( $object, self::TITLE_KEY );
	}

	/**
	 * Własny opis SEO wpisu albo terminu ('' gdy brak).
	 *
	 * @param \WP_Post|\WP_Term $object Obiekt.
	 */
	public static function description_for( $object ): string {
		return self::read( $object, self::DESC_KEY );
	}

	public static function register_meta(): void {
		foreach ( array( self::TITLE_KEY, self::DESC_KEY ) as $key ) {
			$args = array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'default'           => '',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			);
			foreach ( self::POST_TYPES as $type ) {
				register_post_meta(
					$type,
					$key,
					$args + array(
						'auth_callback' => static function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', (int) $post_id ) && self::owns_key( (string) $meta_key );
						},
					)
				);
			}
			foreach ( self::TAXONOMIES as $taxonomy ) {
				register_term_meta(
					$taxonomy,
					$key,
					$args + array(
						'auth_callback' => static function ( $allowed, $meta_key, $term_id ) {
							return current_user_can( 'edit_term', (int) $term_id ) && self::owns_key( (string) $meta_key );
						},
					)
				);
			}
		}
	}

	/**
	 * Czyści wartość i przycina do limitu pola (tytuł 70, opis 160 znaków).
	 *
	 * @param mixed  $value    Wartość.
	 * @param string $meta_key Klucz meta.
	 */
	public static function sanitize( $value, $meta_key = '' ): string {
		$max = self::TITLE_KEY === $meta_key ? self::TITLE_MAX : self::DESC_MAX;
		return mb_substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $max );
	}

	/**
	 * REST rdzenia (wpisy, strony, termy): blokada zapisu naszych pól, gdy funkcję ma inna wtyczka.
	 *
	 * @param mixed            $prepared Przygotowany obiekt.
	 * @param \WP_REST_Request $request  Żądanie.
	 */
	public static function guard_rest( $prepared, $request ) {
		$meta = $request instanceof \WP_REST_Request ? $request->get_param( 'meta' ) : null;
		if ( is_array( $meta ) ) {
			foreach ( array( self::TITLE_KEY, self::DESC_KEY ) as $key ) {
				if ( array_key_exists( $key, $meta ) && ! self::owns_key( $key ) ) {
					return self::owned_elsewhere();
				}
			}
		}
		return $prepared;
	}

	/**
	 * REST termów (kategorie, tagi, kategorie i tagi produktów): blokada zapisu naszych pól.
	 *
	 * @param mixed            $response Dotychczasowa odpowiedź.
	 * @param array            $handler  Handler trasy.
	 * @param \WP_REST_Request $request  Żądanie.
	 */
	public static function guard_terms( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request || ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			return $response;
		}
		foreach ( self::TAXONOMIES as $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$base   = $object && ! empty( $object->show_in_rest ) ? ( $object->rest_base ? $object->rest_base : $taxonomy ) : '';
			$ns     = $object && ! empty( $object->rest_namespace ) ? $object->rest_namespace : 'wp/v2';
			if ( '' !== $base && 0 === strpos( $request->get_route(), '/' . $ns . '/' . $base ) ) {
				$guard = self::guard_rest( null, $request );
				return is_wp_error( $guard ) ? $guard : $response;
			}
		}
		return $response;
	}

	/**
	 * REST WooCommerce (meta_data produktu): ta sama blokada.
	 *
	 * @param mixed            $product Produkt.
	 * @param \WP_REST_Request $request Żądanie.
	 */
	public static function guard_wc( $product, $request ) {
		$meta = $request instanceof \WP_REST_Request ? $request->get_param( 'meta_data' ) : null;
		if ( is_array( $meta ) ) {
			foreach ( $meta as $item ) {
				$key = is_array( $item ) && isset( $item['key'] ) ? (string) $item['key'] : '';
				if ( in_array( $key, array( self::TITLE_KEY, self::DESC_KEY ), true ) && ! self::owns_key( $key ) ) {
					return self::owned_elsewhere();
				}
			}
		}
		return $product;
	}

	/**
	 * Boks klasycznego edytora (w edytorze blokowym jest panel).
	 *
	 * @param string $post_type Typ wpisu.
	 */
	public static function add_box( $post_type ): void {
		if ( ! in_array( $post_type, self::POST_TYPES, true ) || ! self::owns_any() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! empty( $screen->is_block_editor ) ) {
			return;
		}
		add_meta_box( 'aktio_seo', __( 'SEO (Aktio)', 'aktio-connector' ), array( __CLASS__, 'render_box' ), $post_type, 'normal', 'default' );
	}

	public static function render_box( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		if ( Detector::owns( 'title' ) ) {
			echo '<p><label for="aktio_seo_title"><strong>' . esc_html__( 'SEO title', 'aktio-connector' ) . '</strong></label><br />';
			echo '<input type="text" class="widefat" id="aktio_seo_title" name="aktio_seo_title" maxlength="' . (int) self::TITLE_MAX . '" value="' . esc_attr( self::title_for( $post ) ) . '" /></p>';
		}
		if ( Detector::owns( 'meta_description' ) ) {
			echo '<p><label for="aktio_seo_description"><strong>' . esc_html__( 'SEO description', 'aktio-connector' ) . '</strong></label><br />';
			echo '<textarea class="widefat" rows="3" id="aktio_seo_description" name="aktio_seo_description" maxlength="' . (int) self::DESC_MAX . '">' . esc_textarea( self::description_for( $post ) ) . '</textarea></p>';
		}
	}

	/**
	 * Zapis boksu klasycznego: tylko z nonce, uprawnieniem i gdy Aktio jest właścicielem pola.
	 *
	 * @param int      $post_id ID wpisu.
	 * @param \WP_Post $post    Wpis.
	 */
	public static function save_box( $post_id, $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, self::POST_TYPES, true )
			|| wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array(
			'aktio_seo_title'       => self::TITLE_KEY,
			'aktio_seo_description' => self::DESC_KEY,
		) as $field => $key ) {
			if ( ! isset( $_POST[ $field ] ) || ! self::owns_key( $key ) ) {
				continue;
			}
			$value = self::sanitize( wp_unslash( $_POST[ $field ] ), $key );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/**
	 * Panel w edytorze blokowym — tylko gdy Aktio jest właścicielem i dla naszych typów wpisów.
	 */
	public static function enqueue_panel(): void {
		if ( ! self::owns_any() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		wp_enqueue_script(
			'aktio-seo-panel',
			plugins_url( 'assets/aktio-seo-panel.js', AKTIO_CONNECTOR_FILE ),
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n' ),
			AKTIO_CONNECTOR_VERSION,
			true
		);
		wp_set_script_translations( 'aktio-seo-panel', 'aktio-connector' );
		wp_add_inline_script(
			'aktio-seo-panel',
			'window.aktioSeoPanel = ' . wp_json_encode(
				array(
					'title'       => Detector::owns( 'title' ),
					'description' => Detector::owns( 'meta_description' ),
					'titleMax'    => self::TITLE_MAX,
					'descMax'     => self::DESC_MAX,
				)
			) . ';',
			'before'
		);
	}

	/**
	 * @param \WP_Post|\WP_Term $object Obiekt.
	 */
	private static function read( $object, string $key ): string {
		if ( $object instanceof \WP_Post ) {
			$value = get_post_meta( $object->ID, $key, true );
		} elseif ( $object instanceof \WP_Term ) {
			$value = get_term_meta( $object->term_id, $key, true );
		} else {
			return '';
		}
		return is_string( $value ) ? $value : '';
	}

	private static function owns_key( string $key ): bool {
		return Detector::owns( self::TITLE_KEY === $key ? 'title' : 'meta_description' );
	}

	private static function owns_any(): bool {
		return Detector::owns( 'title' ) || Detector::owns( 'meta_description' );
	}

	private static function owned_elsewhere(): \WP_Error {
		return new \WP_Error(
			'aktio_seo_owned_elsewhere',
			__( 'SEO titles and descriptions are managed by another SEO plugin.', 'aktio-connector' ),
			array( 'status' => 409 )
		);
	}
}
