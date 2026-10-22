<?php
/**
 * Plugin Name:       Aktio Connector
 * Description:       Schema.org, robots.txt i llms.txt dla botów AI, Site Health i aktualizacje — do współpracy z Aktio.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            NM Media
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aktio-connector
 *
 * @package AktioConnector
 */

defined( 'ABSPATH' ) || exit;

define( 'AKTIO_CONNECTOR_VERSION', '0.1.0' );
define( 'AKTIO_CONNECTOR_FILE', __FILE__ );

// Prosty autoload przez require — wtyczka działa po rozpakowaniu zipa bez vendor/.
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Detector.php';
require_once __DIR__ . '/includes/Updates.php';
require_once __DIR__ . '/includes/Rest.php';

\AktioConnector\Rest::register();
