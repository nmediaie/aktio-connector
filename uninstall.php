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
