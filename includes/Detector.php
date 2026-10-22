<?php
/**
 * ZAŚLEPKA na Task A1 — pełna wersja (wykrywanie aktywnych wtyczek SEO i plików
 * fizycznych) przychodzi w Task A2.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Detector {

	/**
	 * Stan czterech funkcji; w zaślepce wszystkie wyłączone.
	 */
	public static function features(): array {
		$off = array(
			'state'       => 'off',
			'provided_by' => null,
		);
		return array(
			'content_schema' => $off,
			'product_schema' => $off,
			'robots'         => $off,
			'llms'           => $off,
		);
	}

	/**
	 * Aktywne wtyczki SEO: [{slug, name}]; w zaślepce pusto.
	 */
	public static function seo_plugins(): array {
		return array();
	}
}
