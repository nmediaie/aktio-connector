<?php
/**
 * Ekran Ustawienia → Aktio Connector: dane firmy i boty AI (edycja), stan funkcji
 * i dziennik aktualizacji (tylko do odczytu). Działa bez Aktio.
 *
 * @package AktioConnector
 */

namespace AktioConnector\Admin;

use AktioConnector\Detector;
use AktioConnector\Settings;
use AktioConnector\Updates;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	const SLUG   = 'aktio-connector';
	const ACTION = 'aktio_connector_save';
	const NONCE  = 'aktio_connector_save';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AKTIO_CONNECTOR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function add_menu(): void {
		add_options_page(
			__( 'Aktio Connector', 'aktio-connector' ),
			__( 'Aktio Connector', 'aktio-connector' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function action_links( $links ): array {
		$links   = is_array( $links ) ? $links : array();
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Settings', 'aktio-connector' ) . '</a>';
		return $links;
	}

	/**
	 * admin-post: nonce i uprawnienie, zapis, przekierowanie z komunikatem.
	 */
	public static function handle_save(): void {
		check_admin_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'aktio-connector' ), '', array( 'response' => 403 ) );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdzony wyżej, wartości sanityzuje Settings::update().
		self::save( is_array( $post ) ? $post : array() );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Zapis pól z formularza (sanityzacja w Settings::update).
	 */
	public static function save( array $post ): void {
		$partial = array();
		if ( isset( $post['company'] ) && is_array( $post['company'] ) ) {
			$company = $post['company'];
			if ( isset( $company['same_as'] ) && is_string( $company['same_as'] ) ) {
				$company['same_as'] = preg_split( '/\R/', $company['same_as'] ) ?: array();
			}
			$partial['company'] = $company;
		}
		if ( isset( $post['bots'] ) && is_array( $post['bots'] ) ) {
			$partial['bots'] = $post['bots'];
		}
		Settings::update( $partial );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = Settings::get();
		$company  = $settings['company'];
		$bots     = $settings['bots'];
		$address  = $company['address'];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Aktio Connector', 'aktio-connector' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko komunikat.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'aktio-connector' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Connection to Aktio: this plugin works on its own; Aktio uses it through an application password.', 'aktio-connector' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::NONCE );

		echo '<h2>' . esc_html__( 'Company', 'aktio-connector' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This data goes into schema.org markup (Organization or LocalBusiness). An empty name means the site title.', 'aktio-connector' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::text_row( 'name', __( 'Company name', 'aktio-connector' ), (string) $company['name'] );
		echo '<tr><th scope="row"><label for="aktio-company-type">' . esc_html__( 'Type', 'aktio-connector' ) . '</label></th><td>';
		echo '<select id="aktio-company-type" name="company[type]">';
		foreach ( Settings::TYPES as $type ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $company['type'], $type, false ) . '>' . esc_html( $type ) . '</option>';
		}
		echo '</select></td></tr>';
		self::text_row( 'logo', __( 'Logo URL', 'aktio-connector' ), (string) $company['logo'], 'url' );
		self::text_row( 'phone', __( 'Phone', 'aktio-connector' ), (string) $company['phone'] );
		self::text_row( 'email', __( 'Email', 'aktio-connector' ), (string) $company['email'], 'email' );
		self::text_row( 'address][street', __( 'Street and number', 'aktio-connector' ), (string) $address['street'] );
		self::text_row( 'address][city', __( 'City', 'aktio-connector' ), (string) $address['city'] );
		self::text_row( 'address][postal_code', __( 'Postal code', 'aktio-connector' ), (string) $address['postal_code'] );
		self::text_row( 'address][country', __( 'Country', 'aktio-connector' ), (string) $address['country'] );
		echo '<tr><th scope="row"><label for="aktio-company-same-as">' . esc_html__( 'Social profiles (sameAs)', 'aktio-connector' ) . '</label></th><td>';
		echo '<textarea id="aktio-company-same-as" name="company[same_as]" rows="4" class="large-text code">' . esc_textarea( implode( "\n", $company['same_as'] ) ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One URL per line, up to 10.', 'aktio-connector' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'AI bots in robots.txt', 'aktio-connector' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( Settings::BOTS as $bot ) {
			$value = 'block' === ( $bots[ $bot ] ?? 'allow' ) ? 'block' : 'allow';
			$id    = 'aktio-bot-' . sanitize_html_class( $bot );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $bot ) . '</label></th><td>';
			echo '<select id="' . esc_attr( $id ) . '" name="bots[' . esc_attr( $bot ) . ']">';
			echo '<option value="allow"' . selected( $value, 'allow', false ) . '>' . esc_html__( 'Allow', 'aktio-connector' ) . '</option>';
			echo '<option value="block"' . selected( $value, 'block', false ) . '>' . esc_html__( 'Block', 'aktio-connector' ) . '</option>';
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'aktio-connector' ) );
		echo '</form>';

		self::render_features();
		self::render_log();
		echo '</div>';
	}

	private static function text_row( string $key, string $label, string $value, string $type = 'text' ): void {
		$id = 'aktio-company-' . sanitize_html_class( str_replace( '][', '-', $key ) );
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="company[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" />';
		echo '</td></tr>';
	}

	private static function render_features(): void {
		$labels   = array(
			'content_schema' => __( 'Content schema', 'aktio-connector' ),
			'product_schema' => __( 'Product schema', 'aktio-connector' ),
			'robots'         => __( 'robots.txt', 'aktio-connector' ),
			'llms'           => __( 'llms.txt', 'aktio-connector' ),
		);
		$features = Detector::features();

		echo '<h2>' . esc_html__( 'Feature status', 'aktio-connector' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( $labels as $key => $label ) {
			$feature = $features[ $key ] ?? array( 'state' => 'off', 'provided_by' => null );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			if ( 'provided' === $feature['state'] ) {
				if ( 'file' === $feature['provided_by'] ) {
					/* translators: %s: feature name, e.g. robots.txt */
					echo esc_html( sprintf( __( '%s comes from a physical file in the site directory — Aktio Connector does not duplicate it.', 'aktio-connector' ), $label ) );
				} else {
					/* translators: 1: feature name, 2: plugin name */
					echo esc_html( sprintf( __( '%1$s is provided by %2$s — Aktio Connector does not duplicate it.', 'aktio-connector' ), $label, (string) $feature['provided_by'] ) );
				}
			} elseif ( 'off' === $feature['state'] ) {
				echo esc_html__( 'Unavailable (requires WooCommerce).', 'aktio-connector' );
			} else {
				echo esc_html__( 'Active — provided by Aktio Connector.', 'aktio-connector' );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$seo = Detector::seo_plugins();
		if ( $seo ) {
			echo '<p class="description">' . esc_html(
				/* translators: %s: list of SEO plugin names */
				sprintf( __( 'Detected active SEO plugins: %s.', 'aktio-connector' ), implode( ', ', wp_list_pluck( $seo, 'name' ) ) )
			) . '</p>';
		}
	}

	private static function render_log(): void {
		$log = Updates::log( 10 );
		echo '<h2>' . esc_html__( 'Update log', 'aktio-connector' ) . '</h2>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No entries.', 'aktio-connector' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( __( 'Date (UTC)', 'aktio-connector' ), __( 'Item', 'aktio-connector' ), __( 'Version', 'aktio-connector' ), __( 'Result', 'aktio-connector' ), __( 'By', 'aktio-connector' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $log as $entry ) {
			$name = isset( $entry['name'] ) && '' !== (string) $entry['name'] ? (string) $entry['name'] : (string) ( $entry['item'] ?? '' );
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $entry['at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['from'] ?? '' ) . ' → ' . (string) ( $entry['to'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['result'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $entry['by'] ?? '' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
