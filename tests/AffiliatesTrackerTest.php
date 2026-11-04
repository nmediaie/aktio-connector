<?php
use AktioConnector\Affiliates\Store;
use AktioConnector\Affiliates\Tracker;
use AktioConnector\Metrics;
use AktioConnector\Settings;

class AffiliatesTrackerTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Store::install();
		Metrics::install();
		global $wpdb;
		foreach ( array( Store::affiliates_table(), Store::commissions_table(), Store::clicks_table(), Metrics::table() ) as $table ) {
			$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		}
		delete_option( Settings::OPTION );
		Settings::update( array( 'affiliates' => array( 'enabled' => true, 'consent_mode' => 'self_managed' ) ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		unset( $_SERVER['HTTP_USER_AGENT'], $_COOKIE[ Tracker::COOKIE ] );
		$this->flush_transients();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_USER_AGENT'], $_COOKIE[ Tracker::COOKIE ] );
		remove_all_filters( 'aktio_connector_affiliate_can_track' );
		unset( $_SERVER['HTTPS'] );
		$this->flush_transients();
		delete_option( Settings::OPTION );
		wp_dequeue_script( 'aktio-affiliate' );
		wp_deregister_script( 'aktio-affiliate' );
		parent::tear_down();
	}

	private function flush_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_aktio\_ar\_%' OR option_name LIKE '\_transient\_timeout\_aktio\_ar\_%'" ); // phpcs:ignore WordPress.DB
	}

	private function affiliate( array $extra = array() ): array {
		$id = Store::save_affiliate( array_merge( array( 'name' => 'Jan Kowalski' ), $extra ) );
		$this->assertIsInt( $id );
		return Store::get_affiliate( $id );
	}

	private function clicks( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(`count`),0) FROM ' . Store::clicks_table() . ' WHERE affiliate_id = %d', $id ) ); // phpcs:ignore WordPress.DB
	}

	private function post( string $code, string $ip = '203.0.113.7' ) {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$request                = new WP_REST_Request( 'POST', '/aktio/v1/ref' );
		$request->set_param( 'code', $code );
		return rest_do_request( $request );
	}

	private function cookie( $response ): string {
		$headers = $response->get_headers();
		return (string) ( $headers['Set-Cookie'] ?? '' );
	}

	private function cookie_value( string $header ): string {
		preg_match( '/^aktio_ref=([^;]+)/', $header, $m );
		return $m[1] ?? '';
	}

	public function test_valid_code_sets_signed_cookie() {
		$a        = $this->affiliate();
		$response = $this->post( $a['code'] );
		$this->assertSame( 204, $response->get_status() );
		$header = $this->cookie( $response );
		$this->assertStringContainsString( 'aktio_ref=', $header );
		$this->assertStringContainsString( 'HttpOnly', $header );
		$this->assertStringContainsString( 'SameSite=Lax', $header );
		$this->assertStringContainsString( 'Max-Age=2592000', $header );
		$this->assertSame( $a['code'], Tracker::verify( $this->cookie_value( $header ) ) );
		$this->assertSame( 1, $this->clicks( (int) $a['id'] ) );
	}

	public function test_unknown_and_paused_codes_set_no_cookie() {
		$a = $this->affiliate();
		Store::save_affiliate( array( 'id' => $a['id'], 'status' => 'paused' ) );
		foreach ( array( $a['code'], 'nope-code', 'X', '' ) as $code ) {
			$response = $this->post( $code );
			$this->assertSame( 204, $response->get_status() );
			$this->assertSame( '', $this->cookie( $response ), $code );
		}
		$this->assertSame( 0, $this->clicks( (int) $a['id'] ) );
	}

	public function test_module_off_is_inert() {
		$a = $this->affiliate();
		Settings::update( array( 'affiliates' => array( 'enabled' => false ) ) );
		$response = $this->post( $a['code'] );
		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( '', $this->cookie( $response ) );
		$this->assertSame( 0, $this->clicks( (int) $a['id'] ) );
		Tracker::enqueue();
		$this->assertFalse( wp_script_is( 'aktio-affiliate', 'enqueued' ) );
	}

	public function test_script_enqueued_with_data_and_blocked_by_filter() {
		Tracker::enqueue();
		$this->assertTrue( wp_script_is( 'aktio-affiliate', 'enqueued' ) );
		$data = wp_scripts()->get_data( 'aktio-affiliate', 'data' );
		$this->assertStringContainsString( 'aktioAffiliate', $data );
		$this->assertStringContainsString( '"param":"ref"', $data );
		$this->assertStringContainsString( '"mode":"self_managed"', $data );

		wp_dequeue_script( 'aktio-affiliate' );
		wp_deregister_script( 'aktio-affiliate' );
		add_filter( 'aktio_connector_affiliate_can_track', '__return_false' );
		Tracker::enqueue();
		$this->assertFalse( wp_script_is( 'aktio-affiliate', 'enqueued' ) );
		$a = $this->affiliate();
		$this->assertSame( '', $this->cookie( $this->post( $a['code'] ) ) );
	}

	public function test_tampered_and_expired_cookie_rejected() {
		$ts    = time();
		$mac   = Tracker::sign( 'abc-123', $ts );
		$valid = 'abc-123.' . $ts . '.' . $mac;
		$this->assertSame( 'abc-123', Tracker::verify( $valid ) );
		$this->assertNull( Tracker::verify( 'abc-124.' . $ts . '.' . $mac ) );
		$this->assertNull( Tracker::verify( 'abc-123.' . $ts . '.' . strrev( $mac ) ) );
		$this->assertNull( Tracker::verify( 'abc-123.' . ( $ts + 5 ) . '.' . $mac ) );
		$this->assertNull( Tracker::verify( 'garbage' ) );
		$old = $ts - 31 * DAY_IN_SECONDS;
		$this->assertNull( Tracker::verify( 'abc-123.' . $old . '.' . Tracker::sign( 'abc-123', $old ) ) );

		// Okno liczone od bieżącego ustawienia.
		Settings::update( array( 'affiliates' => array( 'window_days' => 60 ) ) );
		$this->assertSame( 'abc-123', Tracker::verify( 'abc-123.' . $old . '.' . Tracker::sign( 'abc-123', $old ) ) );

		$_COOKIE[ Tracker::COOKIE ] = $valid;
		$this->assertSame( 'abc-123', Tracker::current_code() );
		$_COOKIE[ Tracker::COOKIE ] = 'abc-123.' . $ts . '.bad';
		$this->assertNull( Tracker::current_code() );
	}

	public function test_cookie_path_and_secure_follow_site() {
		$a      = $this->affiliate();
		$header = $this->cookie( $this->post( $a['code'] ) );
		$this->assertStringContainsString( 'Path=' . COOKIEPATH, $header );
		$this->assertStringNotContainsString( 'Secure', $header );

		$_SERVER['HTTPS'] = 'on';
		$this->flush_transients();
		$header = $this->cookie( $this->post( $a['code'] ) );
		$this->assertStringContainsString( '; Secure', $header );
	}

	public function test_duplicate_click_counted_once_but_cookie_refreshed() {
		$a  = $this->affiliate();
		$r1 = $this->post( $a['code'] );
		$r2 = $this->post( $a['code'] );
		$this->assertNotSame( '', $this->cookie( $r1 ) );
		$this->assertNotSame( '', $this->cookie( $r2 ) );
		$this->assertSame( 1, $this->clicks( (int) $a['id'] ) );
		$this->post( $a['code'], '198.51.100.9' );
		$this->assertSame( 2, $this->clicks( (int) $a['id'] ) );
	}

	public function test_known_bot_not_counted() {
		$a                          = $this->affiliate();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';
		$response                   = $this->post( $a['code'] );
		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( '', $this->cookie( $response ) );
		$this->assertSame( 0, $this->clicks( (int) $a['id'] ) );
	}

	public function test_metrics_report_unchanged() {
		$a = $this->affiliate();
		$this->post( $a['code'] );
		$this->assertSame( array(), Metrics::report()['referrals'] );
		$this->assertSame( array(), Metrics::report()['bots'] );
	}

	public function test_rate_limit_returns_429() {
		$a      = $this->affiliate();
		$minute = (int) floor( time() / MINUTE_IN_SECONDS );
		set_transient( Store::TRANSIENT . 'rl' . $minute, Tracker::RATE_LIMIT, 120 );
		set_transient( Store::TRANSIENT . 'rl' . ( $minute + 1 ), Tracker::RATE_LIMIT, 120 );
		$response = $this->post( $a['code'] );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( '', $this->cookie( $response ) );
		$this->assertSame( 0, $this->clicks( (int) $a['id'] ) );
	}

	public function test_purge_removes_only_expired_transients() {
		set_transient( Store::TRANSIENT . 'old', 1, 600 );
		set_transient( Store::TRANSIENT . 'new', 1, 600 );
		update_option( '_transient_timeout_' . Store::TRANSIENT . 'old', time() - 10 );
		Tracker::purge_transients();
		$this->assertFalse( get_transient( Store::TRANSIENT . 'old' ) );
		$this->assertSame( 1, (int) get_transient( Store::TRANSIENT . 'new' ) );
	}
}
