<?php
/**
 * Moduł partnerski: przypisanie zamówienia do partnera przy jego tworzeniu (kupon partnera przed cookie).
 * Nowe prowizje tylko przy włączonym module. Wyjątek nigdy nie przerywa zamówienia. Bez wyjścia sieciowego.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Affiliates;

use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class Attribution {

	const SOURCES = array( 'link', 'coupon' );

	/** Bez warunku na WooCommerce: te hooki bez niego się nie odpalają, a ta wtyczka ładuje się przed nim. */
	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'on_order' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order' ) );
	}

	public static function on_order( \WC_Order $order ): void {
		try {
			if ( ! Store::enabled() || null !== Store::commission_for_order( $order->get_id() ) ) {
				return;
			}
			$resolved = self::resolve( $order );
			if ( null === $resolved ) {
				return;
			}
			$base = round( (float) $order->get_subtotal() - (float) $order->get_discount_total(), 4 );
			if ( $base <= 0.0 ) {
				return;
			}
			$affiliate = $resolved['affiliate'];
			$rate_bp   = null !== ( $affiliate['rate_bp'] ?? null ) ? (int) $affiliate['rate_bp'] : (int) Settings::get()['affiliates']['rate_bp'];
			$self      = self::is_self_referral( $order, $affiliate );

			$created = Commissions::create(
				array(
					'order_id'      => $order->get_id(),
					'affiliate_id'  => (int) $affiliate['id'],
					'source'        => $resolved['source'],
					'status'        => $self ? 'rejected' : 'pending',
					'reason'        => $self ? 'self_referral' : '',
					'base_amount'   => Commissions::money( $base ),
					'base_original' => Commissions::money( $base ),
					'rate_bp'       => $rate_bp,
					'commission'    => Commissions::money( Commissions::commission_amount( $base, $rate_bp ) ),
					'currency'      => $order->get_currency(),
				)
			);
			// 0 z istniejącym wierszem to przegrany wyścig dwóch hooków, nie błąd.
			if ( 0 === $created && null === Store::commission_for_order( $order->get_id() ) ) {
				Commissions::log_failure( 'commission was not saved for order ' . $order->get_id() );
			}
		} catch ( \Throwable $e ) {
			Commissions::log_error( 'attribution', (int) $order->get_id(), $e );
		}
	}

	/**
	 * Partner zamówienia: pierwszy kupon partnera z listy zamówienia, a bez takiego kuponu cookie.
	 * Kupon wstrzymanego partnera rozstrzyga sprawę: rabat zostaje, prowizji nie ma, cookie nie przejmuje zamówienia.
	 *
	 * @return array{affiliate:array,source:string}|null
	 */
	public static function resolve( \WC_Order $order ): ?array {
		$affiliate = self::coupon_affiliate( $order );
		$source    = 'coupon';
		if ( null === $affiliate ) {
			$code      = Tracker::current_code();
			$affiliate = null !== $code ? Store::get_by_code( $code ) : null;
			$source    = 'link';
		}
		$found = null !== $affiliate && 'active' === $affiliate['status']
			? array(
				'affiliate' => $affiliate,
				'source'    => $source,
			)
			: null;

		$found = apply_filters( 'aktio_connector_affiliate_resolve', $found, $order );
		return self::is_valid_result( $found ) ? $found : null;
	}

	private static function is_valid_result( $found ): bool {
		return is_array( $found )
			&& is_array( $found['affiliate'] ?? null )
			&& (int) ( $found['affiliate']['id'] ?? 0 ) > 0
			&& in_array( $found['source'] ?? null, self::SOURCES, true );
	}

	private static function coupon_affiliate( \WC_Order $order ): ?array {
		foreach ( $order->get_coupon_codes() as $code ) {
			$coupon_id = wc_get_coupon_id_by_code( $code );
			if ( ! $coupon_id ) {
				continue;
			}
			$affiliate_id = (int) ( new \WC_Coupon( $coupon_id ) )->get_meta( Store::COUPON_META );
			$affiliate    = $affiliate_id > 0 ? Store::get_affiliate( $affiliate_id ) : null;
			if ( null !== $affiliate ) {
				return $affiliate;
			}
		}
		return null;
	}

	/** E-mail billingowy po normalizacji równy e-mailowi partnera. Bez e-maila partnera sprawdzenia nie ma. */
	private static function is_self_referral( \WC_Order $order, array $affiliate ): bool {
		$partner = self::normalize_email( (string) ( $affiliate['email'] ?? '' ) );
		return '' !== $partner && self::normalize_email( (string) $order->get_billing_email() ) === $partner;
	}

	private static function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}
}
