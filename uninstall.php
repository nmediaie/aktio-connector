<?php
/**
 * Odinstalowanie: usuwa opcje i cache wtyczki.
 *
 * @package AktioConnector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'aktio_connector_settings' );
delete_option( 'aktio_connector_update_log' );
delete_option( 'aktio_connector_core_auto' );
delete_transient( 'aktio_connector_llms' );
delete_option( 'aktio_connector_indexnow_queue' );
delete_option( 'aktio_connector_indexnow_log' );
delete_option( 'aktio_connector_indexnow_last' );
delete_option( 'aktio_connector_indexnow_sitemap' );
delete_option( 'aktio_connector_rewrite_version' );
delete_option( 'aktio_connector_cache_last_purge' );
wp_clear_scheduled_hook( 'aktio_connector_indexnow' );

// Pomiar AI: tabela metryk, wersja schematu, cron i ulotne transienty beaconu.
global $wpdb;
$aktio_metrics_table = $wpdb->prefix . 'aktio_metrics';
$wpdb->query( "DROP TABLE IF EXISTS `{$aktio_metrics_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_option( 'aktio_connector_metrics_schema' );
wp_clear_scheduled_hook( 'aktio_connector_metrics_purge' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aktio\\_bc\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aktio\\_bc\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
