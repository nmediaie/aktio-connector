<?php
/**
 * Plugin Name:       Aktio Connector
 * Description:       Schema.org, robots.txt and llms.txt for AI bots, Site Health and updates over REST — standalone or with Aktio.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            NM Media
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       aktio-connector
 * Domain Path:       /languages
 *
 * @package AktioConnector
 */

defined( 'ABSPATH' ) || exit;

define( 'AKTIO_CONNECTOR_VERSION', '0.2.0' );
define( 'AKTIO_CONNECTOR_FILE', __FILE__ );

// Prosty autoload przez require — wtyczka działa po rozpakowaniu zipa bez vendor/.
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Detector.php';
require_once __DIR__ . '/includes/Language.php';
require_once __DIR__ . '/includes/Updates.php';
require_once __DIR__ . '/includes/Rest.php';
require_once __DIR__ . '/includes/Schema/Graph.php';
require_once __DIR__ . '/includes/Schema/ContentSchema.php';
require_once __DIR__ . '/includes/Schema/ProductSchema.php';
require_once __DIR__ . '/includes/Seo/Fields.php';
require_once __DIR__ . '/includes/Seo/Head.php';
require_once __DIR__ . '/includes/CachePurge.php';
require_once __DIR__ . '/includes/IndexNow.php';
require_once __DIR__ . '/includes/Metrics.php';
require_once __DIR__ . '/includes/Faq.php';
require_once __DIR__ . '/includes/Catalog.php';
require_once __DIR__ . '/includes/Crawlers.php';
require_once __DIR__ . '/includes/Health.php';
require_once __DIR__ . '/includes/Admin/SettingsPage.php';

\AktioConnector\Language::register();
\AktioConnector\Rest::register();
\AktioConnector\Schema\Graph::register();
\AktioConnector\Schema\ContentSchema::register();
\AktioConnector\Schema\ProductSchema::register();
\AktioConnector\Seo\Fields::register();
\AktioConnector\Seo\Head::register();
\AktioConnector\CachePurge::register();
\AktioConnector\IndexNow::register();
\AktioConnector\Metrics::register();
\AktioConnector\Faq::register();
\AktioConnector\Crawlers::register();
\AktioConnector\Updates::register();
if ( is_admin() ) {
	\AktioConnector\Admin\SettingsPage::register();
}

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'aktio-connector', false, dirname( plugin_basename( AKTIO_CONNECTOR_FILE ) ) . '/languages' );
	}
);

// IndexNow przed Crawlers: flush reguł rewrite (Crawlers::activate) ma już znać regułę klucza.
register_activation_hook( __FILE__, array( '\\AktioConnector\\IndexNow', 'activate' ) );
register_activation_hook( __FILE__, array( '\\AktioConnector\\Metrics', 'activate' ) );
register_activation_hook( __FILE__, array( '\\AktioConnector\\Crawlers', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\AktioConnector\\IndexNow', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( '\\AktioConnector\\Metrics', 'deactivate' ) );
register_deactivation_hook( __FILE__, array( '\\AktioConnector\\Crawlers', 'deactivate' ) );
