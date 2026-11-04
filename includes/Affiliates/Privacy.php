<?php
/**
 * Moduł partnerski: eksporter i eraser danych osobowych WordPressa (partner znajdowany po e-mailu).
 * Eraser czyści imię/nazwę, e-mail i `external_id`, wstrzymuje partnera i zostawia kwoty prowizji (historia rozliczeń).
 *
 * @package AktioConnector
 */

namespace AktioConnector\Affiliates;

defined( 'ABSPATH' ) || exit;

class Privacy {

	const ID = 'aktio-connector-affiliates';

	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function exporters( $exporters ): array {
		$exporters          = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Aktio Connector affiliate', 'aktio-connector' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function erasers( $erasers ): array {
		$erasers          = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Aktio Connector affiliate', 'aktio-connector' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @return array{data:array,done:bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		$data = array();
		foreach ( Store::affiliates_by_email( $email ) as $affiliate ) {
			$items = array(
				array( 'name' => __( 'Name', 'aktio-connector' ), 'value' => $affiliate['name'] ),
				array( 'name' => __( 'Email', 'aktio-connector' ), 'value' => $affiliate['email'] ),
				array( 'name' => __( 'Affiliate code', 'aktio-connector' ), 'value' => $affiliate['code'] ),
				array( 'name' => __( 'Status', 'aktio-connector' ), 'value' => $affiliate['status'] ),
				array( 'name' => __( 'Contact ID', 'aktio-connector' ), 'value' => $affiliate['external_id'] ),
				array( 'name' => __( 'Created', 'aktio-connector' ), 'value' => $affiliate['created_at'] ),
			);
			$data[] = array(
				'group_id'    => 'aktio-affiliate',
				'group_label' => __( 'Affiliate', 'aktio-connector' ),
				'item_id'     => 'aktio-affiliate-' . $affiliate['id'],
				'data'        => $items,
			);
			$rows = array();
			for ( $n = 1; ; $n++ ) {
				$chunk = Store::query_commissions( array( 'affiliate_id' => $affiliate['id'], 'page' => $n, 'per_page' => Store::MAX_PER_PAGE ) );
				$rows  = array_merge( $rows, $chunk['items'] );
				if ( array() === $chunk['items'] || count( $rows ) >= $chunk['total'] ) {
					break;
				}
			}
			foreach ( $rows as $row ) {
				$data[] = array(
					'group_id'    => 'aktio-affiliate-commissions',
					'group_label' => __( 'Affiliate commissions', 'aktio-connector' ),
					'item_id'     => 'aktio-commission-' . $row['id'],
					'data'        => array(
						array( 'name' => __( 'Order', 'aktio-connector' ), 'value' => (string) $row['order_id'] ),
						array( 'name' => __( 'Status', 'aktio-connector' ), 'value' => $row['status'] ),
						array( 'name' => __( 'Commission', 'aktio-connector' ), 'value' => $row['commission'] . ' ' . $row['currency'] ),
						array( 'name' => __( 'Created', 'aktio-connector' ), 'value' => $row['created_at'] ),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @return array{items_removed:int,items_retained:bool,messages:array,done:bool}
	 */
	public static function erase( string $email, int $page = 1 ): array {
		$removed = 0;
		$failed  = 0;
		foreach ( Store::affiliates_by_email( $email ) as $affiliate ) {
			$saved = Store::save_affiliate(
				array(
					'id'          => $affiliate['id'],
					'name'        => __( 'Deleted partner', 'aktio-connector' ),
					'email'       => '',
					'external_id' => '',
					'status'      => 'paused',
				)
			);
			is_wp_error( $saved ) ? $failed++ : $removed++;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $failed > 0,
			'messages'       => $removed > 0 ? array( __( 'Affiliate name, email and contact ID were erased. Commission amounts are kept as a settlement record.', 'aktio-connector' ) ) : array(),
			'done'           => true,
		);
	}
}
