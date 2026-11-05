<?php
/**
 * Moduł partnerski: eksport prowizji do CSV (zmiana wartości w komórkach tekstowych, żeby arkusz nie wykonał formuły).
 *
 * @package AktioConnector
 */

namespace AktioConnector\Affiliates;

defined( 'ABSPATH' ) || exit;

class Csv {

	const COLUMNS = array(
		'id',
		'order_id',
		'affiliate_id',
		'affiliate_code',
		'affiliate_name',
		'source',
		'status',
		'reason',
		'created_at',
		'currency',
		'base_amount',
		'rate_bp',
		'commission',
		'commission_paid',
		'clawback',
		'clawback_settled_amount',
		'clawback_balance',
		'clawback_settled_at',
		'clawback_ref',
		'approve_after',
		'paid_at',
		'payout_ref',
	);

	/** Kolumny tekstowe: wartości idą przez cell(). Reszta to liczby i daty z bazy, zapisywane bez zmian. */
	private const TEXT = array( 'affiliate_code', 'affiliate_name', 'source', 'status', 'reason', 'currency', 'clawback_ref', 'payout_ref' );

	/**
	 * Komórka tekstowa: wartość zaczynająca się od `=`, `+`, `-`, `@` (także po wiodących białych znakach), tabulatora lub CR dostaje na początku apostrof,
	 * więc Excel i LibreOffice traktują ją jako tekst, a nie formułę. Liczby (kwoty) nie przechodzą przez tę funkcję.
	 */
	public static function cell( string $value ): string {
		return 1 === preg_match( '/^(?:[\t\r]|\s*[=+\-@])/', $value ) ? "'" . $value : $value;
	}

	/** Wiersz nagłówka (CRLF na końcu). */
	public static function header(): string {
		return self::line( self::COLUMNS );
	}

	/**
	 * Wiersze prowizji (CRLF po każdym). $affiliates: id partnera => wiersz partnera (kod i nazwa).
	 *
	 * @param array<int,array> $rows
	 * @param array<int,array> $affiliates
	 */
	public static function lines( array $rows, array $affiliates = array() ): string {
		$out = '';
		foreach ( $rows as $row ) {
			$aff                     = $affiliates[ (int) $row['affiliate_id'] ] ?? array();
			$row['affiliate_code']   = (string) ( $aff['code'] ?? '' );
			$row['affiliate_name']   = (string) ( $aff['name'] ?? '' );
			$row['clawback_balance'] = Commissions::clawback_balance( $row );
			$cells                   = array();
			foreach ( self::COLUMNS as $column ) {
				$value   = isset( $row[ $column ] ) ? (string) $row[ $column ] : '';
				$cells[] = in_array( $column, self::TEXT, true ) ? self::cell( $value ) : $value;
			}
			$out .= self::line( $cells );
		}
		return $out;
	}

	/** Nagłówek i wiersze w jednym tekście. */
	public static function commissions( array $rows, array $affiliates = array() ): string {
		return self::header() . self::lines( $rows, $affiliates );
	}

	private static function line( array $cells ): string {
		$out = array();
		foreach ( $cells as $cell ) {
			$cell  = (string) $cell;
			$out[] = 1 === preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
		}
		return implode( ',', $out ) . "\r\n";
	}
}
