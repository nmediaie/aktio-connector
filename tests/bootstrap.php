<?php
/**
 * Bootstrap testów: biblioteka testowa WordPressa z wp-env (tests-cli).
 */

$aktio_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $aktio_tests_dir ) {
	$aktio_tests_dir = '/wordpress-phpunit';
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $aktio_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		// WooCommerce ładujemy przed wtyczką (jak w testach rozszerzeń WC).
		$woo = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( file_exists( $woo ) ) {
			require $woo;
		}
		require dirname( __DIR__ ) . '/aktio-connector.php';
	}
);

// Instalacja tabel WooCommerce w bazie testowej (po załadowaniu WP).
tests_add_filter(
	'setup_theme',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
			WC_Install::install();
			$GLOBALS['wp_roles'] = null;
			wp_roles();
		}
	}
);

require $aktio_tests_dir . '/includes/bootstrap.php';
