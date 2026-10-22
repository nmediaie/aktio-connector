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
		require dirname( __DIR__ ) . '/aktio-connector.php';
	}
);

require $aktio_tests_dir . '/includes/bootstrap.php';
