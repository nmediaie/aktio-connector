<?php
/**
 * Trasy REST aktio/v1: status, visibility.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Rest {

	const NAMESPACE = 'aktio/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => self::permission( 'edit_posts' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/visibility',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'visibility' ),
					'permission_callback' => self::permission( 'manage_options' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'visibility_update' ),
					'permission_callback' => self::permission( 'manage_options' ),
				),
			)
		);
	}

	/**
	 * permission_callback: prawda, gdy użytkownik ma KTÓRĄKOLWIEK z capability.
	 */
	public static function permission( string ...$caps ): callable {
		return static function () use ( $caps ) {
			foreach ( $caps as $cap ) {
				if ( current_user_can( $cap ) ) {
					return true;
				}
			}
			return new \WP_Error(
				'rest_forbidden',
				__( 'Brak uprawnień do tej operacji.', 'aktio-connector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		};
	}

	public static function status(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'version'     => AKTIO_CONNECTOR_VERSION,
				'woocommerce' => class_exists( 'WooCommerce' ),
				'features'    => Detector::features(),
				'seo_plugins' => Detector::seo_plugins(),
				'company'     => Settings::get()['company'],
				'update_log'  => Updates::log( 5 ),
			)
		);
	}

	public static function visibility(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'features'    => Detector::features(),
				'seo_plugins' => Detector::seo_plugins(),
				'settings'    => Settings::get(),
			)
		);
	}

	/**
	 * POST visibility: body musi być obiektem JSON z opcjonalnymi company/bots.
	 */
	public static function visibility_update( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		// Obiekt JSON = tablica asocjacyjna (pusta `{}` też przechodzi; lista `[1,2]` nie).
		$is_object = is_array( $body ) && ( array() === $body || array_keys( $body ) !== range( 0, count( $body ) - 1 ) );
		if ( ! $is_object ) {
			return self::invalid_params( __( 'Body musi być obiektem JSON.', 'aktio-connector' ) );
		}
		foreach ( array( 'company', 'bots' ) as $key ) {
			if ( array_key_exists( $key, $body ) && ! is_array( $body[ $key ] ) ) {
				/* translators: %s: nazwa pola */
				return self::invalid_params( sprintf( __( 'Pole %s musi być obiektem.', 'aktio-connector' ), $key ) );
			}
		}
		Settings::update( array_intersect_key( $body, array_flip( array( 'company', 'bots' ) ) ) );
		return self::visibility();
	}

	private static function invalid_params( string $message ): \WP_Error {
		return new \WP_Error( 'aktio_invalid_params', $message, array( 'status' => 400 ) );
	}
}
