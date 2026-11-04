<?php
/**
 * Zakładka „Affiliates” w ustawieniach wtyczki: ustawienia modułu, partnerzy, generator linku, prowizje z akcjami
 * masowymi, eksport CSV oraz pole partnera w edycji kuponu. Każda akcja ma własny formularz admin-post
 * (nonce + manage_woocommerce); zakładka leży poza głównym formularzem ustawień (bez zagnieżdżonych formularzy).
 *
 * @package AktioConnector
 */

namespace AktioConnector\Admin;

use AktioConnector\Affiliates\Commissions;
use AktioConnector\Affiliates\Csv;
use AktioConnector\Affiliates\Store;
use AktioConnector\Affiliates\Tracker;
use AktioConnector\Settings;

defined( 'ABSPATH' ) || exit;

class AffiliatesTab {

	const ACTION_SETTINGS = 'aktio_aff_settings';
	const ACTION_ADD      = 'aktio_aff_add';
	const ACTION_EDIT     = 'aktio_aff_edit';
	const ACTION_LINK     = 'aktio_aff_link';
	const ACTION_BULK     = 'aktio_aff_bulk';
	const ACTION_EXPORT   = 'aktio_aff_export';

	const NONCE_NAME = '_aktio_aff_nonce';
	const PER_PAGE   = 50;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION_SETTINGS, array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_' . self::ACTION_ADD, array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_' . self::ACTION_EDIT, array( __CLASS__, 'handle_edit' ) );
		add_action( 'admin_post_' . self::ACTION_LINK, array( __CLASS__, 'handle_link' ) );
		add_action( 'admin_post_' . self::ACTION_BULK, array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
		add_action( 'woocommerce_coupon_options', array( __CLASS__, 'render_coupon_field' ), 20, 2 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save_coupon_field' ), 20, 2 );
	}

	/** Zakładka jest tylko ze sklepem (szew testowy z SettingsPage wyłącza ją tak samo jak zakładkę Store). */
	public static function available(): bool {
		return Store::available() && ! SettingsPage::$assume_no_woocommerce;
	}

	// ------------------------------------------------------------------ Obsługa formularzy

	private static function guard( string $action ): void {
		check_admin_referer( $action, self::NONCE_NAME );
		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::available() ) {
			wp_die( esc_html__( 'You are not allowed to manage the affiliate programme.', 'aktio-connector' ), '', array( 'response' => 403 ) );
		}
	}

	/** Pole z POST jako tekst (bez ukośników), '' gdy go nie ma lub nie jest tekstem. */
	private static function field( string $key ): string {
		$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce sprawdza guard(), wartość czyszczą Store i Settings.
		return is_string( $value ) ? trim( $value ) : '';
	}

	/** Procent z formularza → punkty bazowe; '' = brak (domyślna stawka); tekst niebędący liczbą zostaje do odrzucenia przez walidację. */
	private static function rate_bp( string $percent ) {
		if ( '' === $percent ) {
			return null;
		}
		return is_numeric( $percent ) ? (int) round( (float) $percent * 100 ) : $percent;
	}

	/** Zapis ustawień modułu: wszystko albo nic. Zwraca błędy walidacji (ścieżka pola => komunikat). */
	public static function save_settings( array $post ): array {
		$text = static function ( string $key ) use ( $post ) {
			return isset( $post[ $key ] ) && is_string( $post[ $key ] ) ? trim( $post[ $key ] ) : null;
		};
		$int = static function ( ?string $value ) {
			return null !== $value && 1 === preg_match( '/^[0-9]{1,6}\z/', $value ) ? (int) $value : $value;
		};
		$in = array(
			'enabled'             => isset( $post['aff_enabled'] ) && '1' === $post['aff_enabled'],
			'delete_on_uninstall' => isset( $post['aff_delete_on_uninstall'] ) && '1' === $post['aff_delete_on_uninstall'],
		);
		foreach ( array( 'consent_mode' => 'aff_consent_mode', 'param' => 'aff_param' ) as $key => $name ) {
			if ( null !== $text( $name ) ) {
				$in[ $key ] = $text( $name );
			}
		}
		foreach ( array( 'window_days' => 'aff_window_days', 'hold_days' => 'aff_hold_days' ) as $key => $name ) {
			if ( null !== $text( $name ) ) {
				$in[ $key ] = $int( $text( $name ) );
			}
		}
		$percent = $text( 'aff_rate_percent' );
		if ( null !== $percent && '' !== $percent ) {
			$in['rate_bp'] = self::rate_bp( $percent );
		}

		$errors = Settings::partial_errors( array( 'affiliates' => $in ) );
		if ( array() === $errors ) {
			Settings::update( array( 'affiliates' => $in ) );
		}
		return $errors;
	}

	public static function handle_settings(): void {
		self::guard( self::ACTION_SETTINGS );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdza guard().
		$errors = self::save_settings( is_array( $post ) ? $post : array() );
		if ( array() === $errors ) {
			self::done( 'success', array( __( 'Affiliate settings saved.', 'aktio-connector' ) ) );
		}
		array_unshift( $errors, __( 'Settings were not saved:', 'aktio-connector' ) );
		self::done( 'error', array_values( $errors ) );
	}

	public static function handle_add(): void {
		self::guard( self::ACTION_ADD );
		$saved = Store::save_affiliate(
			array(
				'name'    => self::field( 'aff_name' ),
				'email'   => self::field( 'aff_email' ),
				'code'    => self::field( 'aff_code' ),
				'rate_bp' => self::rate_bp( self::field( 'aff_rate_percent' ) ),
			)
		);
		if ( is_wp_error( $saved ) ) {
			self::done( 'error', array( $saved->get_error_message() ) );
		}
		self::done( 'success', array( __( 'Affiliate added.', 'aktio-connector' ) ) );
	}

	public static function handle_edit(): void {
		self::guard( self::ACTION_EDIT );
		$id = absint( self::field( 'aff_id' ) );
		if ( $id <= 0 ) {
			self::done( 'error', array( __( 'Affiliate not found.', 'aktio-connector' ) ) );
		}
		$saved = Store::save_affiliate(
			array(
				'id'      => $id,
				'name'    => self::field( 'aff_name' ),
				'email'   => self::field( 'aff_email' ),
				'rate_bp' => self::rate_bp( self::field( 'aff_rate_percent' ) ),
				'status'  => self::field( 'aff_status' ),
			)
		);
		if ( is_wp_error( $saved ) ) {
			self::done( 'error', array( $saved->get_error_message() ) );
		}
		self::done( 'success', array( __( 'Affiliate saved.', 'aktio-connector' ) ) );
	}

	/** Generator linku: ta sama reguła co GET affiliates/link (Tracker::link_for). Wynik wraca w komunikacie. */
	public static function handle_link(): void {
		self::guard( self::ACTION_LINK );
		$affiliate = Store::get_affiliate( absint( self::field( 'aff_affiliate' ) ) );
		if ( null === $affiliate ) {
			self::done( 'error', array( __( 'Choose an affiliate.', 'aktio-connector' ) ) );
		}
		$link = Tracker::link_for( self::field( 'aff_url' ), $affiliate['code'] );
		if ( is_wp_error( $link ) ) {
			self::done( 'error', array( $link->get_error_message() ) );
		}
		self::done( 'success', array( __( 'The link is ready. Copy it below.', 'aktio-connector' ) ), $link );
	}

	public static function handle_bulk(): void {
		self::guard( self::ACTION_BULK );
		$action = self::field( 'bulk_action' );
		$ids    = array();
		$posted = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- nonce sprawdza guard(); tylko cyfry przechodzą dalej.
		foreach ( is_array( $posted ) ? $posted : array() as $id ) {
			if ( is_string( $id ) && 1 === preg_match( '/^[1-9][0-9]{0,17}\z/', $id ) ) {
				$ids[] = $id;
			}
		}
		if ( array() === $ids ) {
			self::done( 'error', array( __( 'Select at least one commission.', 'aktio-connector' ) ) );
		}
		$result = Commissions::apply( $action, $ids, self::field( 'bulk_ref' ) );
		if ( is_wp_error( $result ) ) {
			self::done( 'error', array( $result->get_error_message() ) );
		}
		$ok = count( array_filter( $result['results'], static fn( $r ) => 'ok' === $r ) );
		$no = count( $result['results'] ) - $ok;
		/* translators: 1: number of commissions changed, 2: number left unchanged */
		$message = sprintf( __( '%1$d commissions updated, %2$d left unchanged (wrong state, refunded or missing order).', 'aktio-connector' ), $ok, $no );
		self::done( $ok > 0 && 0 === $no ? 'success' : 'warning', array( $message ) );
	}

	/** Eksport CSV: wszystkie strony prowizji z filtrów, strumieniowo. */
	public static function handle_export(): void {
		self::guard( self::ACTION_EXPORT );
		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce sprawdza guard().
		$filters = self::parse_filters( is_array( $post ) ? $post : array() );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="aktio-affiliate-commissions-' . gmdate( 'Y-m-d' ) . '.csv"' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- BOM UTF-8, żeby Excel czytał polskie znaki.
		foreach ( self::csv_chunks( $filters ) as $chunk ) {
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV, nie HTML; komórki tekstowe przechodzą przez Csv::cell().
			flush();
		}
		exit;
	}

	/**
	 * Kolejne kawałki pliku CSV: nagłówek i po jednej stronie (200 wierszy) prowizji z filtrów, aż do końca.
	 *
	 * @param array $filters status, affiliate_id, from, to (po parse_filters).
	 * @return \Generator<int,string>
	 */
	public static function csv_chunks( array $filters ): \Generator {
		$affiliates = array();
		foreach ( Store::list_affiliates() as $row ) {
			$affiliates[ $row['id'] ] = $row;
		}
		yield Csv::header();
		$seen = 0;
		for ( $page = 1; ; $page++ ) {
			$result = Store::query_commissions( $filters + array( 'page' => $page, 'per_page' => Store::MAX_PER_PAGE ) );
			if ( array() === $result['items'] ) {
				return;
			}
			yield Csv::lines( $result['items'], $affiliates );
			$seen += count( $result['items'] );
			if ( $seen >= $result['total'] ) {
				return;
			}
		}
	}

	/** Filtry listy prowizji z GET lub POST: tylko poprawne wartości (zły filtr jest pomijany). */
	private static function parse_filters( array $src ): array {
		$filters = array();
		if ( isset( $src['c_status'] ) && is_string( $src['c_status'] ) && in_array( $src['c_status'], Commissions::STATUSES, true ) ) {
			$filters['status'] = $src['c_status'];
		}
		if ( isset( $src['c_affiliate'] ) && is_string( $src['c_affiliate'] ) && 1 === preg_match( '/^[1-9][0-9]{0,17}\z/', $src['c_affiliate'] ) ) {
			$filters['affiliate_id'] = (int) $src['c_affiliate'];
		}
		foreach ( array( 'from' => 'c_from', 'to' => 'c_to' ) as $key => $name ) {
			if ( isset( $src[ $name ] ) && is_string( $src[ $name ] ) && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $src[ $name ], $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$filters[ $key ] = $src[ $name ];
			}
		}
		return $filters;
	}

	// ------------------------------------------------------------------ Komunikaty i przekierowanie

	private static function flash_key(): string {
		return 'aktio_aff_flash_' . get_current_user_id();
	}

	/** Zapisuje komunikat (transient użytkownika, czytany raz) i wraca na zakładkę. */
	private static function done( string $type, array $messages, string $link = '' ): void {
		set_transient(
			self::flash_key(),
			array(
				'type'     => $type,
				'messages' => $messages,
				'link'     => $link,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	private static function tab_url(): string {
		return add_query_arg(
			array(
				'page' => SettingsPage::SLUG,
				'tab'  => 'affiliates',
			),
			admin_url( 'options-general.php' )
		);
	}

	private static function render_flash(): void {
		$flash = get_transient( self::flash_key() );
		delete_transient( self::flash_key() );
		if ( ! is_array( $flash ) || empty( $flash['messages'] ) ) {
			return;
		}
		$type = in_array( $flash['type'] ?? '', array( 'success', 'error', 'warning' ), true ) ? $flash['type'] : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible">';
		$messages = array_values( (array) $flash['messages'] );
		echo '<p>' . esc_html( (string) $messages[0] ) . '</p>';
		if ( count( $messages ) > 1 ) {
			echo '<ul>';
			foreach ( array_slice( $messages, 1 ) as $message ) {
				echo '<li>' . esc_html( (string) $message ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $flash['link'] ) && is_string( $flash['link'] ) ) {
			echo '<p><input type="text" readonly class="large-text code" value="' . esc_attr( $flash['link'] ) . '" aria-label="' . esc_attr__( 'Affiliate link', 'aktio-connector' ) . '" /></p>';
		}
		echo '</div>';
	}

	// ------------------------------------------------------------------ Ekran

	public static function render(): void {
		echo '<p class="aktio-panel__intro">' . esc_html__( 'Your own affiliate programme: partners get a link or a coupon, orders are attributed automatically and commissions follow the order status. The plugin never contacts Aktio; the data stays in your shop.', 'aktio-connector' ) . '</p>';
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'You need the permission to manage WooCommerce (shop manager or administrator) to see the affiliate programme.', 'aktio-connector' ) . '</p></div>';
			return;
		}
		Store::maybe_install(); // Tabele powstają przy aktywacji i na plugins_loaded; to tylko pas bezpieczeństwa przy pierwszym wejściu.
		self::render_flash();

		$cfg = Settings::get()['affiliates'];
		if ( ! $cfg['enabled'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The programme is off: links are not tracked and no new commissions are created. Existing commissions are still settled (refunds reject them, payouts can be recorded).', 'aktio-connector' ) . '</p></div>';
		}
		if ( 'consent_api' === $cfg['consent_mode'] && ! function_exists( 'wp_has_consent' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WP Consent API is not active on this site, so affiliate links are not tracked until a plugin that provides it is installed. Coupons still work.', 'aktio-connector' ) . '</p></div>';
		}

		self::render_settings( $cfg );
		self::render_affiliates();
		self::render_link_generator();
		self::render_commissions();
	}

	private static function form_open( string $action, string $id = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action, self::NONCE_NAME );
	}

	private static function card_open( string $title, string $description = '' ): void {
		echo '<div class="aktio-card"><h2>' . esc_html( $title ) . '</h2>';
		if ( '' !== $description ) {
			echo '<p class="description aktio-card__description">' . esc_html( $description ) . '</p>';
		}
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'pending'  => __( 'Pending', 'aktio-connector' ),
			'approved' => __( 'Approved', 'aktio-connector' ),
			'rejected' => __( 'Rejected', 'aktio-connector' ),
			'paid'     => __( 'Paid', 'aktio-connector' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private static function reason_label( string $reason ): string {
		$labels = array(
			'order_status'  => __( 'order status', 'aktio-connector' ),
			'self_referral' => __( 'own purchase', 'aktio-connector' ),
			'manual'        => __( 'rejected manually', 'aktio-connector' ),
		);
		return $labels[ $reason ] ?? $reason;
	}

	private static function source_label( string $source ): string {
		return 'coupon' === $source ? __( 'Coupon', 'aktio-connector' ) : __( 'Link', 'aktio-connector' );
	}

	private static function percent( ?int $bp ): string {
		return null === $bp ? '' : rtrim( rtrim( number_format( $bp / 100, 2, '.', '' ), '0' ), '.' );
	}

	private static function money( string $amount ): string {
		return number_format( (float) $amount, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2, '.', '' );
	}

	private static function render_settings( array $cfg ): void {
		self::card_open( __( 'Programme settings', 'aktio-connector' ), __( 'Before you turn the programme on, choose how consent is handled. The plugin has no consent banner of its own.', 'aktio-connector' ) );
		self::form_open( self::ACTION_SETTINGS );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Programme', 'aktio-connector' ) . '</th><td><label><input type="checkbox" name="aff_enabled" value="1"' . checked( $cfg['enabled'], true, false ) . ' /> ' . esc_html__( 'Turn the affiliate programme on', 'aktio-connector' ) . '</label></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Consent', 'aktio-connector' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="aff_consent_mode" value="consent_api"' . checked( $cfg['consent_mode'], 'consent_api', false ) . ' /> ' . esc_html__( 'WP Consent API: track a link only after the visitor allows marketing cookies', 'aktio-connector' ) . '</label><br />';
		echo '<label><input type="radio" name="aff_consent_mode" value="self_managed"' . checked( $cfg['consent_mode'], 'self_managed', false ) . ' /> ' . esc_html__( 'Self-managed: I am responsible for consent on my site (the filter aktio_connector_affiliate_can_track connects your own banner)', 'aktio-connector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'There is no default: the programme cannot be turned on until you choose one.', 'aktio-connector' ) . '</p></fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="aff_param">' . esc_html__( 'Link parameter', 'aktio-connector' ) . '</label></th><td><input type="text" id="aff_param" name="aff_param" class="regular-text" value="' . esc_attr( (string) $cfg['param'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="aff_window_days">' . esc_html__( 'Attribution window (days)', 'aktio-connector' ) . '</label></th><td><input type="number" id="aff_window_days" name="aff_window_days" min="1" max="365" value="' . esc_attr( (string) $cfg['window_days'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="aff_rate_percent">' . esc_html__( 'Default rate (%)', 'aktio-connector' ) . '</label></th><td><input type="text" inputmode="decimal" id="aff_rate_percent" name="aff_rate_percent" class="small-text" value="' . esc_attr( self::percent( (int) $cfg['rate_bp'] ) ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="aff_hold_days">' . esc_html__( 'Hold period (days)', 'aktio-connector' ) . '</label></th><td><input type="number" id="aff_hold_days" name="aff_hold_days" min="0" max="365" value="' . esc_attr( (string) $cfg['hold_days'] ) . '" /><p class="description">' . esc_html__( 'A commission becomes approvable this many days after the order is completed.', 'aktio-connector' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Uninstall', 'aktio-connector' ) . '</th><td><label><input type="checkbox" name="aff_delete_on_uninstall" value="1"' . checked( $cfg['delete_on_uninstall'], true, false ) . ' /> ' . esc_html__( 'Delete all affiliate data when the plugin is deleted', 'aktio-connector' ) . '</label><p class="description">' . esc_html__( 'Off by default: partners, commissions and click counts are settlement history and are kept.', 'aktio-connector' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save affiliate settings', 'aktio-connector' ), 'primary', 'submit', false );
		echo '</form></div>';
	}

	private static function render_affiliates(): void {
		$sums   = Store::commission_totals();
		$clicks = Store::click_totals();
		$rows   = Store::list_affiliates();

		self::card_open( __( 'Affiliates', 'aktio-connector' ), __( 'An affiliate is never deleted, only paused. A paused affiliate gets no new commissions or coupons.', 'aktio-connector' ) );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No affiliates yet. Add the first one below.', 'aktio-connector' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			foreach ( array( __( 'Name', 'aktio-connector' ), __( 'Code', 'aktio-connector' ), __( 'Status', 'aktio-connector' ), __( 'Rate', 'aktio-connector' ), __( 'Clicks', 'aktio-connector' ), __( 'Orders', 'aktio-connector' ), __( 'Commissions', 'aktio-connector' ), __( 'Link', 'aktio-connector' ), '' ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				self::render_affiliate_row( $row, $sums, $clicks );
			}
			echo '</tbody></table>';
		}

		echo '<h3>' . esc_html__( 'Add an affiliate', 'aktio-connector' ) . '</h3>';
		self::form_open( self::ACTION_ADD );
		echo '<p><label>' . esc_html__( 'Name', 'aktio-connector' ) . ' <input type="text" name="aff_name" required maxlength="120" /></label> ';
		echo '<label>' . esc_html__( 'Email (optional)', 'aktio-connector' ) . ' <input type="email" name="aff_email" maxlength="100" /></label> ';
		echo '<label>' . esc_html__( 'Code (optional)', 'aktio-connector' ) . ' <input type="text" name="aff_code" maxlength="32" pattern="[a-z0-9\-]{3,32}" /></label> ';
		echo '<label>' . esc_html__( 'Own rate % (optional)', 'aktio-connector' ) . ' <input type="text" inputmode="decimal" name="aff_rate_percent" class="small-text" /></label> ';
		submit_button( __( 'Add affiliate', 'aktio-connector' ), 'secondary', 'submit', false );
		echo '</p><p class="description">' . esc_html__( 'The code is made from the name when left empty (3 to 32 characters: lowercase letters, digits, hyphens). It cannot be changed after the first click or commission, and it is visible in links, so avoid putting personal data in it. Assign a coupon in the coupon editor (field "Affiliate").', 'aktio-connector' ) . '</p>';
		echo '</form></div>';
	}

	private static function render_affiliate_row( array $row, array $sums, array $clicks ): void {
		$link = Tracker::link_for( home_url( '/' ), $row['code'] );
		echo '<tr><td>' . esc_html( $row['name'] ) . ( '' !== $row['email'] ? '<br /><small>' . esc_html( $row['email'] ) . '</small>' : '' ) . '</td>';
		echo '<td><code>' . esc_html( $row['code'] ) . '</code></td>';
		echo '<td>' . esc_html( 'active' === $row['status'] ? __( 'Active', 'aktio-connector' ) : __( 'Paused', 'aktio-connector' ) ) . '</td>';
		echo '<td>' . ( null === $row['rate_bp'] ? esc_html__( 'Default', 'aktio-connector' ) : esc_html( self::percent( $row['rate_bp'] ) . '%' ) ) . '</td>';
		echo '<td>' . esc_html( (string) ( $clicks[ $row['id'] ] ?? 0 ) ) . '</td>';
		echo '<td>' . esc_html( (string) ( $sums['orders'][ $row['id'] ] ?? 0 ) ) . '</td>';
		echo '<td>';
		foreach ( $sums['totals'][ $row['id'] ] ?? array() as $currency => $t ) {
			/* translators: 1: pending amount, 2: approved amount, 3: paid amount, 4: currency code */
			echo '<div>' . esc_html( sprintf( __( 'Pending %1$s · Approved %2$s · Paid %3$s %4$s', 'aktio-connector' ), self::money( $t['pending'] ), self::money( $t['approved'] ), self::money( $t['paid'] ), $currency ) ) . '</div>';
			if ( 0.0 !== (float) $t['clawback_balance'] ) {
				/* translators: 1: amount, 2: currency code */
				echo '<div class="aktio-aff-clawback"><strong>' . esc_html( sprintf( __( 'Clawback to settle: %1$s %2$s', 'aktio-connector' ), self::money( $t['clawback_balance'] ), $currency ) ) . '</strong></div>';
			}
		}
		echo '</td>';
		echo '<td>' . ( is_wp_error( $link ) ? '' : '<input type="text" readonly class="regular-text code" value="' . esc_attr( $link ) . '" aria-label="' . esc_attr__( 'Affiliate link', 'aktio-connector' ) . '" />' ) . '</td>';
		echo '<td><details><summary>' . esc_html__( 'Edit', 'aktio-connector' ) . '</summary>';
		self::form_open( self::ACTION_EDIT );
		echo '<input type="hidden" name="aff_id" value="' . esc_attr( (string) $row['id'] ) . '" />';
		echo '<p><label>' . esc_html__( 'Name', 'aktio-connector' ) . '<br /><input type="text" name="aff_name" required maxlength="120" value="' . esc_attr( $row['name'] ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Email', 'aktio-connector' ) . '<br /><input type="email" name="aff_email" maxlength="100" value="' . esc_attr( $row['email'] ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Own rate % (empty = default)', 'aktio-connector' ) . '<br /><input type="text" inputmode="decimal" name="aff_rate_percent" class="small-text" value="' . esc_attr( self::percent( $row['rate_bp'] ) ) . '" /></label></p>';
		echo '<p><label>' . esc_html__( 'Status', 'aktio-connector' ) . '<br /><select name="aff_status"><option value="active"' . selected( $row['status'], 'active', false ) . '>' . esc_html__( 'Active', 'aktio-connector' ) . '</option><option value="paused"' . selected( $row['status'], 'paused', false ) . '>' . esc_html__( 'Paused', 'aktio-connector' ) . '</option></select></label></p>';
		submit_button( __( 'Save', 'aktio-connector' ), 'secondary small', 'submit', false );
		echo '</form></details></td></tr>';
	}

	private static function render_link_generator(): void {
		self::card_open( __( 'Link generator', 'aktio-connector' ), __( 'Paste the address of any page of your shop and choose an affiliate to get a ready link.', 'aktio-connector' ) );
		$rows = Store::list_affiliates();
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Add an affiliate first.', 'aktio-connector' ) . '</p></div>';
			return;
		}
		self::form_open( self::ACTION_LINK );
		echo '<p><label>' . esc_html__( 'Affiliate', 'aktio-connector' ) . ' <select name="aff_affiliate">';
		foreach ( $rows as $row ) {
			echo '<option value="' . esc_attr( (string) $row['id'] ) . '">' . esc_html( $row['name'] . ' (' . $row['code'] . ')' ) . '</option>';
		}
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'Shop page address', 'aktio-connector' ) . ' <input type="url" name="aff_url" class="regular-text" required placeholder="' . esc_attr( home_url( '/' ) ) . '" /></label> ';
		submit_button( __( 'Create link', 'aktio-connector' ), 'secondary', 'submit', false );
		echo '</p></form></div>';
	}

	private static function render_commissions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtry widoku tylko do odczytu.
		$get     = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();
		$filters = self::parse_filters( $get );
		$page    = isset( $get['c_page'] ) && is_string( $get['c_page'] ) ? max( 1, absint( $get['c_page'] ) ) : 1;
		// phpcs:enable
		$result     = Store::query_commissions( $filters + array( 'page' => $page, 'per_page' => self::PER_PAGE ) );
		$affiliates = array();
		foreach ( Store::list_affiliates() as $row ) {
			$affiliates[ $row['id'] ] = $row;
		}

		self::card_open( __( 'Commissions', 'aktio-connector' ), __( 'Approving makes a commission payable; "paid" needs a payout reference. A refund after payout is not taken from the paid amount: it appears as a separate clawback balance that you settle by hand.', 'aktio-connector' ) );

		// Filtry (GET).
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '"><input type="hidden" name="page" value="' . esc_attr( SettingsPage::SLUG ) . '" /><input type="hidden" name="tab" value="affiliates" /><p>';
		echo '<label>' . esc_html__( 'Status', 'aktio-connector' ) . ' <select name="c_status"><option value="">' . esc_html__( 'All', 'aktio-connector' ) . '</option>';
		foreach ( Commissions::STATUSES as $status ) {
			echo '<option value="' . esc_attr( $status ) . '"' . selected( $filters['status'] ?? '', $status, false ) . '>' . esc_html( self::status_label( $status ) ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'Affiliate', 'aktio-connector' ) . ' <select name="c_affiliate"><option value="">' . esc_html__( 'All', 'aktio-connector' ) . '</option>';
		foreach ( $affiliates as $row ) {
			echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( $filters['affiliate_id'] ?? 0, $row['id'], false ) . '>' . esc_html( $row['name'] ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'From', 'aktio-connector' ) . ' <input type="date" name="c_from" value="' . esc_attr( $filters['from'] ?? '' ) . '" /></label> ';
		echo '<label>' . esc_html__( 'To', 'aktio-connector' ) . ' <input type="date" name="c_to" value="' . esc_attr( $filters['to'] ?? '' ) . '" /></label> ';
		submit_button( __( 'Filter', 'aktio-connector' ), 'secondary', '', false );
		echo '</p></form>';

		if ( array() === $result['items'] ) {
			echo '<p>' . esc_html__( 'No commissions match.', 'aktio-connector' ) . '</p>';
		} else {
			self::form_open( self::ACTION_BULK );
			echo '<table class="widefat striped"><thead><tr><td class="check-column"></td>';
			foreach ( array( __( 'Order', 'aktio-connector' ), __( 'Affiliate', 'aktio-connector' ), __( 'Source', 'aktio-connector' ), __( 'Status', 'aktio-connector' ), __( 'Base', 'aktio-connector' ), __( 'Commission', 'aktio-connector' ), __( 'Clawback to settle', 'aktio-connector' ), __( 'Payout', 'aktio-connector' ), __( 'Created', 'aktio-connector' ) ) as $head ) {
				echo '<th scope="col">' . esc_html( $head ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $result['items'] as $row ) {
				$balance = Commissions::clawback_balance( $row );
				$aff     = $affiliates[ $row['affiliate_id'] ] ?? array();
				echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( (string) $row['id'] ) . '" aria-label="' . esc_attr__( 'Select commission', 'aktio-connector' ) . '" /></th>';
				echo '<td>#' . esc_html( (string) $row['order_id'] ) . '</td>';
				echo '<td>' . esc_html( (string) ( $aff['name'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( self::source_label( $row['source'] ) ) . '</td>';
				echo '<td>' . esc_html( self::status_label( $row['status'] ) . ( '' !== $row['reason'] ? ' (' . self::reason_label( $row['reason'] ) . ')' : '' ) ) . '</td>';
				echo '<td>' . esc_html( self::money( $row['base_amount'] ) . ' ' . $row['currency'] ) . '</td>';
				echo '<td>' . esc_html( self::money( $row['commission'] ) . ' ' . $row['currency'] ) . '</td>';
				echo '<td>' . ( 0.0 !== (float) $balance ? '<strong class="aktio-aff-clawback">' . esc_html( self::money( $balance ) . ' ' . $row['currency'] ) . '</strong>' : '&ndash;' ) . '</td>';
				echo '<td>' . esc_html( trim( (string) $row['paid_at'] . ' ' . $row['payout_ref'] ) ) . '</td>';
				echo '<td>' . esc_html( (string) $row['created_at'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p><label>' . esc_html__( 'Action for the selected', 'aktio-connector' ) . ' <select name="bulk_action">';
			$labels = array(
				'approve'         => __( 'Approve', 'aktio-connector' ),
				'reject'          => __( 'Reject', 'aktio-connector' ),
				'paid'            => __( 'Mark as paid', 'aktio-connector' ),
				'settle_clawback' => __( 'Settle clawback', 'aktio-connector' ),
			);
			foreach ( $labels as $value => $label ) {
				echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></label> <label>' . esc_html__( 'Reference (payout or document number)', 'aktio-connector' ) . ' <input type="text" name="bulk_ref" maxlength="100" /></label> ';
			submit_button( __( 'Apply', 'aktio-connector' ), 'primary', 'submit', false );
			echo '</p></form>';
			self::render_pagination( $result['total'], $page, $filters );
		}

		// Eksport: osobny formularz POST z tymi samymi filtrami.
		self::form_open( self::ACTION_EXPORT );
		foreach ( array( 'c_status' => 'status', 'c_affiliate' => 'affiliate_id', 'c_from' => 'from', 'c_to' => 'to' ) as $name => $key ) {
			if ( isset( $filters[ $key ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $filters[ $key ] ) . '" />';
			}
		}
		submit_button( __( 'Export CSV (all pages of the current filter)', 'aktio-connector' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	private static function render_pagination( int $total, int $page, array $filters ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages <= 1 ) {
			return;
		}
		$args = array(
			'page'        => SettingsPage::SLUG,
			'tab'         => 'affiliates',
			'c_status'    => $filters['status'] ?? '',
			'c_affiliate' => $filters['affiliate_id'] ?? '',
			'c_from'      => $filters['from'] ?? '',
			'c_to'        => $filters['to'] ?? '',
		);
		/* translators: 1: page number, 2: number of pages, 3: total number of commissions */
		$summary = __( 'Page %1$d of %2$d (%3$d commissions)', 'aktio-connector' );
		echo '<p class="tablenav-pages">' . esc_html( sprintf( $summary, $page, $pages, $total ) ) . ' ';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $args + array( 'c_page' => $page - 1 ), admin_url( 'options-general.php' ) ) ) . '">' . esc_html__( 'Previous', 'aktio-connector' ) . '</a> ';
		}
		if ( $page < $pages ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $args + array( 'c_page' => $page + 1 ), admin_url( 'options-general.php' ) ) ) . '">' . esc_html__( 'Next', 'aktio-connector' ) . '</a>';
		}
		echo '</p>';
	}

	// ------------------------------------------------------------------ Pole partnera w edycji kuponu

	/**
	 * Lista „Affiliate” w zakładce General kuponu. Pokazuje aktywnych partnerów i aktualnego właściciela kuponu
	 * (także wstrzymanego), żeby zapis formularza go nie zdjął.
	 *
	 * @param mixed $coupon_id Id kuponu (akcja woocommerce_coupon_options).
	 * @param mixed $coupon    Obiekt kuponu.
	 */
	public static function render_coupon_field( $coupon_id = 0, $coupon = null ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! Store::available() ) {
			return;
		}
		$current = 0;
		if ( $coupon instanceof \WC_Coupon ) {
			$current = (int) $coupon->get_meta( Store::COUPON_META );
		} elseif ( (int) $coupon_id > 0 ) {
			$current = (int) ( new \WC_Coupon( (int) $coupon_id ) )->get_meta( Store::COUPON_META );
		}
		echo '<p class="form-field"><label for="aktio_affiliate_id">' . esc_html__( 'Affiliate', 'aktio-connector' ) . '</label>';
		echo '<select id="aktio_affiliate_id" name="aktio_affiliate_id" class="select short"><option value="0">' . esc_html__( 'None', 'aktio-connector' ) . '</option>';
		foreach ( Store::list_affiliates() as $row ) {
			if ( 'active' !== $row['status'] && $row['id'] !== $current ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $row['id'] ) . '"' . selected( $current, $row['id'], false ) . '>' . esc_html( $row['name'] . ' (' . $row['code'] . ')' . ( 'active' === $row['status'] ? '' : ' - ' . __( 'paused', 'aktio-connector' ) ) ) . '</option>';
		}
		echo '</select><span class="description">' . esc_html__( 'Orders that use this coupon earn this affiliate a commission. A coupon belongs to one affiliate: to move it, set it to None first.', 'aktio-connector' ) . '</span></p>';
	}

	/**
	 * Zapis pola kuponu (po nonce WooCommerce, który sprawdza WC przed tą akcją). Te same reguły co REST:
	 * nie przejmujemy kuponu innego partnera i nie przypisujemy wstrzymanego partnera.
	 *
	 * @param mixed $post_id Id kuponu.
	 * @param mixed $coupon  Obiekt kuponu.
	 */
	public static function save_coupon_field( $post_id, $coupon = null ): void {
		if ( ! Store::available() || ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['aktio_affiliate_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce `woocommerce_meta_nonce` sprawdza WooCommerce przed akcją.
			return;
		}
		$coupon = $coupon instanceof \WC_Coupon ? $coupon : new \WC_Coupon( (int) $post_id );
		$code   = (string) $coupon->get_code();
		$raw    = wp_unslash( $_POST['aktio_affiliate_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- jw.; wartość to tylko cyfry (absint).
		$target = is_string( $raw ) ? absint( $raw ) : 0;
		if ( '' === $code || $target === (int) Store::coupon_owner( $code ) ) {
			return;
		}
		if ( 0 === $target ) {
			$done = Store::unassign_coupon( $code );
		} else {
			$affiliate = Store::get_affiliate( $target );
			$done      = Store::coupon_taken_error( $code, $target );
			if ( null === $done && null === $affiliate ) {
				$done = new \WP_Error( 'aktio_aff_not_found', __( 'Affiliate not found.', 'aktio-connector' ) );
			}
			if ( null === $done ) {
				$done = Store::assign_coupon( $target, $code ); // Wstrzymany partner dostaje tu błąd aktio_aff_paused.
			}
		}
		if ( is_wp_error( $done ) && class_exists( '\WC_Admin_Meta_Boxes' ) ) {
			\WC_Admin_Meta_Boxes::add_error( $done->get_error_message() );
		}
	}
}
