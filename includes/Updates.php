<?php
/**
 * ZAŚLEPKA na Task A1 — pełna wersja (lista, wykonanie, auto-aktualizacje,
 * dziennik) przychodzi w Task A7.
 *
 * @package AktioConnector
 */

namespace AktioConnector;

defined( 'ABSPATH' ) || exit;

class Updates {

	/**
	 * Ostatnie $limit wpisów dziennika aktualizacji; w zaślepce pusto.
	 */
	public static function log( int $limit = 50 ): array {
		return array();
	}
}
