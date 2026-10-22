<?php
/**
 * Odinstalowanie: usuwa opcje wtyczki.
 *
 * @package AktioConnector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'aktio_connector_settings' );
delete_option( 'aktio_connector_update_log' );
