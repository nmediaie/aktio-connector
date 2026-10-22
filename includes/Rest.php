<?php
/**
 * Trasy REST aktio/v1. Na razie: GET status.
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
}
