<?php
/**
 * Ustawienia wtyczki: opcja aktio_connector_settings (company, bots).
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'aktio_connector_settings';

	const BOTS = array( 'GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'Applebot-Extended' );

	const TYPES = array( 'Organization', 'LocalBusiness' );

	/**
	 * Domyślne ustawienia (nazwa firmy z nazwy witryny, wszystkie boty dopuszczone).
	 */
	public static function defaults(): array {
		return array(
			'company' => array(
				'name'    => (string) get_bloginfo( 'name' ),
				'type'    => 'Organization',
				'logo'    => '',
				'address' => array(
					'street'      => '',
					'city'        => '',
					'postal_code' => '',
					'country'     => '',
				),
				'phone'   => '',
				'email'   => '',
				'same_as' => array(),
			),
			'bots'    => array_fill_keys( self::BOTS, 'allow' ),
		);
	}

	/**
	 * Zapisane ustawienia scalone z domyślnymi.
	 */
	public static function get(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::merge( $defaults, $stored );
	}

	/**
	 * Częściowa aktualizacja: sanityzuje wejście, zapisuje i zwraca całość.
	 */
	public static function update( array $partial ): array {
		$current = self::get();
		$merged  = self::merge( $current, $partial );
		update_option( self::OPTION, $merged, true );
		do_action( 'aktio_connector_settings_updated', $merged );
		return $merged;
	}

	/**
	 * Scala bazę z wejściem i sanityzuje wynik (wejście nigdy nie jest zaufane).
	 */
	private static function merge( array $base, array $input ): array {
		$company = $base['company'];
		if ( isset( $input['company'] ) && is_array( $input['company'] ) ) {
			$company = self::sanitize_company( $input['company'], $company );
		}
		$bots = $base['bots'];
		if ( isset( $input['bots'] ) && is_array( $input['bots'] ) ) {
			foreach ( self::BOTS as $bot ) {
				if ( isset( $input['bots'][ $bot ] ) ) {
					$bots[ $bot ] = 'block' === $input['bots'][ $bot ] ? 'block' : 'allow';
				}
			}
		}
		return array(
			'company' => $company,
			'bots'    => $bots,
		);
	}

	private static function text( $value ): string {
		$value = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 200 ) : substr( $value, 0, 200 );
	}

	private static function sanitize_company( array $in, array $cur ): array {
		$out = $cur;
		if ( isset( $in['name'] ) ) {
			$out['name'] = self::text( $in['name'] );
		}
		if ( isset( $in['type'] ) ) {
			$out['type'] = in_array( $in['type'], self::TYPES, true ) ? $in['type'] : $cur['type'];
		}
		if ( isset( $in['logo'] ) ) {
			$out['logo'] = is_string( $in['logo'] ) ? esc_url_raw( $in['logo'] ) : '';
		}
		if ( isset( $in['address'] ) && is_array( $in['address'] ) ) {
			foreach ( array( 'street', 'city', 'postal_code', 'country' ) as $key ) {
				if ( isset( $in['address'][ $key ] ) ) {
					$out['address'][ $key ] = self::text( $in['address'][ $key ] );
				}
			}
		}
		if ( isset( $in['phone'] ) ) {
			$out['phone'] = self::text( $in['phone'] );
		}
		if ( isset( $in['email'] ) ) {
			$out['email'] = is_string( $in['email'] ) ? sanitize_email( $in['email'] ) : '';
		}
		if ( isset( $in['same_as'] ) ) {
			$urls = array();
			foreach ( is_array( $in['same_as'] ) ? $in['same_as'] : array() as $url ) {
				$url = is_string( $url ) ? esc_url_raw( $url ) : '';
				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}
			$out['same_as'] = array_slice( array_values( array_unique( $urls ) ), 0, 10 );
		}
		return $out;
	}
}
