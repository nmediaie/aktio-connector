<?php
/**
 * Trasy REST aktio/v1: status, visibility, health, updates, auto-updates.
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

		register_rest_route(
			self::NAMESPACE,
			'/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => self::permission( 'view_site_health_checks' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/indexnow/sitemap',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'indexnow_sitemap' ),
				'permission_callback' => self::permission( 'manage_options' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/beacon',
			array(
				'methods'             => 'POST',
				'callback'            => array( Metrics::class, 'beacon' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/metrics',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'metrics' ),
				'permission_callback' => self::permission( 'manage_options' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/products/scan',
			array(
				'methods'             => 'GET',
				'callback'            => array( Catalog::class, 'scan_products' ),
				'permission_callback' => self::permission( 'edit_products' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/media/missing-alt',
			array(
				'methods'             => 'GET',
				'callback'            => array( Catalog::class, 'missing_alt' ),
				'permission_callback' => self::permission( 'upload_files' ),
			)
		);

		// Uprawnienie edit_post sprawdzane osobno dla każdego elementu w handlerze.
		register_rest_route(
			self::NAMESPACE,
			'/media/alt',
			array(
				'methods'             => 'POST',
				'callback'            => array( Catalog::class, 'update_alt' ),
				'permission_callback' => self::permission( 'upload_files' ),
			)
		);

		// Wejście (any-of); dokładna capability wg `type` sprawdzana w handlerze.
		$update_caps = array( 'update_core', 'update_plugins', 'update_themes' );
		register_rest_route(
			self::NAMESPACE,
			'/updates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'updates' ),
					'permission_callback' => self::permission( ...$update_caps ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'updates_apply' ),
					'permission_callback' => self::permission( ...$update_caps ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/auto-updates',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'auto_updates_set' ),
				'permission_callback' => self::permission( ...$update_caps ),
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
				__( 'You are not allowed to perform this operation.', 'aktio-connector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		};
	}

	public static function status(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'version'          => AKTIO_CONNECTOR_VERSION,
				'woocommerce'      => class_exists( 'WooCommerce' ),
				'features'         => Detector::features(),
				'seo'              => array_intersect_key( Detector::features(), array_flip( Detector::SEO_FEATURES ) ),
				'seo_plugins'      => Detector::seo_plugins(),
				'company'          => Settings::get()['company'],
				'content_language' => Language::current(),
				'language_warning' => self::language_warning(),
				'cache'            => array(
					'plugin'     => CachePurge::detected(),
					'last_purge' => CachePurge::last(),
				),
				'indexnow'         => array(
					'enabled' => Settings::get()['indexnow']['enabled'],
					'last'    => IndexNow::last(),
				),
				'update_log'       => Updates::log( 5 ),
			)
		);
	}

	/**
	 * GET metrics?days=30: liczniki botów AI i ruchu z wyszukiwarek AI.
	 */
	public static function metrics( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( Metrics::report( (int) ( $request->get_param( 'days' ) ?? 30 ) ) );
	}

	/**
	 * POST indexnow/sitemap: zgłasza całą mapę (raz na 24 h, potem `aktio_rate_limited`).
	 */
	public static function indexnow_sitemap() {
		$result = IndexNow::submit_sitemap();
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Ostrzeżenie o języku: `gtranslate_js` (tłumaczenie w JS), tablica {expected, detected} albo null.
	 *
	 * @return array|string|null
	 */
	public static function language_warning() {
		if ( Language::gtranslate_js() ) {
			return 'gtranslate_js';
		}
		return Language::warning();
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

	public static function health(): \WP_REST_Response {
		return rest_ensure_response( Health::report() );
	}

	/**
	 * POST visibility: body musi być obiektem JSON z opcjonalnymi company/bots/merchant/seo/content_language/indexnow/verification.
	 * Pola 0.2 (poza starymi polami company i bots) są walidowane przed zapisem (całość atomowo): błąd → 400 z nazwą pola.
	 */
	public static function visibility_update( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		// Obiekt JSON = tablica asocjacyjna (pusta `{}` też przechodzi; lista `[1,2]` nie).
		$is_object = is_array( $body ) && ( array() === $body || array_keys( $body ) !== range( 0, count( $body ) - 1 ) );
		if ( ! $is_object ) {
			return self::invalid_params( __( 'The request body must be a JSON object.', 'aktio-connector' ) );
		}
		foreach ( array( 'company', 'bots', 'merchant', 'seo', 'indexnow', 'verification' ) as $key ) {
			if ( array_key_exists( $key, $body ) && ! is_array( $body[ $key ] ) ) {
				/* translators: %s: field name */
				return self::invalid_params( sprintf( __( 'The %s field must be an object.', 'aktio-connector' ), $key ) );
			}
		}
		$partial = array_intersect_key( $body, array_flip( array( 'company', 'bots', 'merchant', 'seo', 'content_language', 'indexnow', 'verification' ) ) );
		$errors  = Settings::partial_errors( $partial );
		if ( $errors ) {
			$error = self::invalid_params( reset( $errors ) );
			$error->add_data(
				array(
					'status' => 400,
					'field'  => (string) key( $errors ),
					'errors' => $errors,
				)
			);
			return $error;
		}
		Settings::update( $partial );
		return self::visibility();
	}

	public static function updates(): \WP_REST_Response {
		$data        = Updates::available();
		$data['log'] = Updates::log( 50 );
		return rest_ensure_response( $data );
	}

	/**
	 * POST updates: {type, item}. Capability musi pasować do typu.
	 */
	public static function updates_apply( \WP_REST_Request $request ) {
		$body  = self::json_object( $request );
		$check = self::check_update_type( $body );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$item = $body['item'] ?? null;
		if ( 'core' === $body['type'] ) {
			$item = 'core';
		} elseif ( ! is_string( $item ) ) {
			return self::invalid_params( __( 'The item field (string) is required.', 'aktio-connector' ) );
		}
		$result = Updates::apply( $body['type'], $item, 'aktio' );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST auto-updates: {type, item?, enabled?, core_level?}.
	 */
	public static function auto_updates_set( \WP_REST_Request $request ) {
		$body  = self::json_object( $request );
		$check = self::check_update_type( $body );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$item       = $body['item'] ?? null;
		$enabled    = $body['enabled'] ?? null;
		$core_level = $body['core_level'] ?? null;
		if ( ( null !== $item && ! is_string( $item ) ) || ( null !== $enabled && ! is_bool( $enabled ) ) || ( null !== $core_level && ! is_string( $core_level ) ) ) {
			return self::invalid_params( __( 'Invalid types for the item/enabled/core_level fields.', 'aktio-connector' ) );
		}
		if ( 'core' === $body['type'] ) {
			$item    = null;
			$enabled = null;
		} elseif ( null === $item ) {
			return self::invalid_params( __( 'The item field (string) is required.', 'aktio-connector' ) );
		}
		$result = Updates::set_auto( $body['type'], $item, $enabled, 'core' === $body['type'] ? $core_level : null );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/** Body jako tablica asocjacyjna (inne kształty → pusta tablica, czyli brak `type`). */
	private static function json_object( \WP_REST_Request $request ): array {
		$body = $request->get_json_params();
		return is_array( $body ) ? $body : array();
	}

	/**
	 * `type` ∈ core|plugin|theme (400) i capability dokładnie dla typu (403).
	 *
	 * @return true|\WP_Error
	 */
	private static function check_update_type( array $body ) {
		$caps = array(
			'core'   => 'update_core',
			'plugin' => 'update_plugins',
			'theme'  => 'update_themes',
		);
		$type = $body['type'] ?? null;
		if ( ! is_string( $type ) || ! isset( $caps[ $type ] ) ) {
			return self::invalid_params( __( 'type must be one of: core, plugin, theme.', 'aktio-connector' ) );
		}
		if ( ! current_user_can( $caps[ $type ] ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to perform this operation.', 'aktio-connector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	private static function invalid_params( string $message ): \WP_Error {
		return new \WP_Error( 'aktio_invalid_params', $message, array( 'status' => 400 ) );
	}
}
