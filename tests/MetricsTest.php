<?php
use AktioConnector\Metrics;

class MetricsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Metrics::install();
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . Metrics::table() ); // phpcs:ignore WordPress.DB
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->flush_transients();
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_USER_AGENT'] );
		$this->flush_transients();
		wp_clear_scheduled_hook( Metrics::CRON );
		parent::tear_down();
	}

	private function flush_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_aktio\_bc\_%' OR option_name LIKE '\_transient\_timeout\_aktio\_bc\_%'" ); // phpcs:ignore WordPress.DB
	}

	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . Metrics::table() . ' ORDER BY `kind`, name, `path`', ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private function count_of( string $kind, string $key, string $path = '' ): int {
		foreach ( $this->rows() as $row ) {
			if ( $row['kind'] === $kind && $row['name'] === $key && $row['path'] === $path ) {
				return (int) $row['count'];
			}
		}
		return 0;
	}

	private function beacon( array $params = array(), string $ip = '203.0.113.7' ): WP_REST_Response {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$request                = new WP_REST_Request( 'POST', '/aktio/v1/beacon' );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_do_request( $request );
	}

	public function test_bot_user_agent_counts_for_each_listed_bot() {
		$bots = array( 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'bingbot', 'Applebot', 'Amazonbot', 'meta-externalagent', 'CCBot', 'Bytespider' );
		foreach ( $bots as $bot ) {
			Metrics::record_bot( 'Mozilla/5.0 (compatible; ' . strtoupper( $bot ) . '/1.2; +https://example.org/bot)' );
			$this->assertSame( 1, $this->count_of( 'bot', $bot ), $bot );
		}
		Metrics::record_bot( 'Mozilla/5.0 (compatible; GPTBot/1.0)' );
		$this->assertSame( 2, $this->count_of( 'bot', 'GPTBot' ) );
	}

	public function test_regular_browser_user_agent_counts_nothing() {
		Metrics::record_bot( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' );
		Metrics::record_bot( '' );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_template_redirect_counts_bot_on_front_page_robots_and_sitemap() {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0; ClaudeBot/1.0';
		$this->go_to( home_url( '/' ) );
		do_action( 'template_redirect' );
		$this->assertSame( 1, $this->count_of( 'bot', 'ClaudeBot' ) );

		$this->go_to( home_url( '/?robots=1' ) );
		do_action( 'template_redirect' );
		$this->assertSame( 2, $this->count_of( 'bot', 'ClaudeBot' ) );

		$this->go_to( home_url( '/?sitemap=index' ) );
		do_action( 'template_redirect' );
		$this->assertSame( 3, $this->count_of( 'bot', 'ClaudeBot' ) );

		// Hook musi biec przed obsługą mapy strony (priorytet 0) i llms.txt (10), które kończą żądanie.
		$this->assertLessThan( 0, has_action( 'template_redirect', array( Metrics::class, 'on_request' ) ) );
	}

	public function test_beacon_from_chatgpt_referrer_counts_referral_with_path() {
		$response = $this->beacon( array( 'r' => 'https://chatgpt.com/', 'p' => '/produkt/kubek/?x=1' ) );
		$this->assertSame( 204, $response->get_status() );
		$this->assertSame( 1, $this->count_of( 'referral', 'chatgpt.com', '/produkt/kubek/' ) );
	}

	public function test_beacon_counts_utm_source_and_www_subdomain() {
		$this->beacon( array( 'u' => 'perplexity.ai', 'p' => '/a/' ), '203.0.113.8' );
		$this->beacon( array( 'r' => 'https://www.perplexity.ai/search?q=x', 'p' => '/b/' ), '203.0.113.9' );
		$this->assertSame( 1, $this->count_of( 'referral', 'perplexity.ai', '/a/' ) );
		$this->assertSame( 1, $this->count_of( 'referral', 'perplexity.ai', '/b/' ) );
	}

	public function test_beacon_from_source_outside_the_list_counts_nothing() {
		$this->beacon( array( 'r' => 'https://www.google.com/', 'p' => '/a/' ) );
		$this->beacon( array( 'r' => 'https://evilchatgpt.com/', 'p' => '/a/' ), '203.0.113.9' );
		$this->beacon( array( 'u' => 'newsletter', 'p' => '/a/' ), '203.0.113.10' );
		$this->beacon( array( 'p' => '/a/' ), '203.0.113.11' );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_twenty_identical_beacons_from_one_ip_count_once() {
		for ( $i = 0; $i < 20; $i++ ) {
			$response = $this->beacon( array( 'r' => 'https://claude.ai/', 'p' => '/a/' ) );
			$this->assertSame( 204, $response->get_status() );
		}
		$this->assertSame( 1, $this->count_of( 'referral', 'claude.ai', '/a/' ) );

		// Inna ścieżka z tego samego IP albo ta sama z innego IP to osobne wizyty.
		$this->beacon( array( 'r' => 'https://claude.ai/', 'p' => '/b/' ) );
		$this->beacon( array( 'r' => 'https://claude.ai/', 'p' => '/a/' ), '203.0.113.50' );
		$this->assertSame( 2, $this->count_of( 'referral', 'claude.ai', '/a/' ) );
		$this->assertSame( 1, $this->count_of( 'referral', 'claude.ai', '/b/' ) );
	}

	public function test_global_limit_of_60_beacons_per_minute_returns_429() {
		for ( $i = 1; $i <= 60; $i++ ) {
			$response = $this->beacon( array( 'r' => 'https://chatgpt.com/', 'p' => '/p' . $i . '/' ), '198.51.100.' . $i );
			$this->assertSame( 204, $response->get_status(), (string) $i );
		}
		$response = $this->beacon( array( 'r' => 'https://chatgpt.com/', 'p' => '/inna/' ), '198.51.100.200' );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 0, $this->count_of( 'referral', 'chatgpt.com', '/inna/' ) );
	}

	public function test_foreign_sources_and_repeats_do_not_spend_the_rate_budget() {
		for ( $i = 1; $i <= 100; $i++ ) {
			$response = $this->beacon( array( 'r' => 'https://www.google.com/', 'p' => '/a/' ), '192.0.2.' . $i );
			$this->assertSame( 204, $response->get_status() );
		}
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->beacon( array( 'r' => 'https://claude.ai/', 'p' => '/powtorka/' ), '192.0.2.250' );
		}
		// Powtórzenia i obce źródła nie zużyły limitu: 59 kolejnych realnych wejść (razem 60) przechodzi.
		for ( $i = 1; $i <= 59; $i++ ) {
			$response = $this->beacon( array( 'r' => 'https://chatgpt.com/', 'p' => '/p' . $i . '/' ), '198.51.100.' . $i );
			$this->assertSame( 204, $response->get_status(), (string) $i );
		}
		$this->assertSame( 1, $this->count_of( 'referral', 'claude.ai', '/powtorka/' ) );
		$this->assertSame( 1, $this->count_of( 'referral', 'chatgpt.com', '/p59/' ) );
	}

	public function test_rate_limited_visit_is_not_marked_as_seen() {
		$bucket = Metrics::TRANSIENT . 'rl' . (int) floor( time() / MINUTE_IN_SECONDS );
		set_transient( $bucket, Metrics::RATE_LIMIT, 120 );
		$this->assertSame( 429, $this->beacon( array( 'r' => 'https://claude.ai/', 'p' => '/a/' ) )->get_status() );
		delete_transient( $bucket );
		$this->beacon( array( 'r' => 'https://claude.ai/', 'p' => '/a/' ) );
		$this->assertSame( 1, $this->count_of( 'referral', 'claude.ai', '/a/' ) );
	}

	public function test_beacon_script_gets_ai_host_list_and_filters_in_js() {
		set_current_screen( 'front' );
		do_action( 'wp_enqueue_scripts' );
		$data = (string) wp_scripts()->get_data( 'aktio-beacon', 'data' );
		$this->assertStringContainsString( '"hosts":["chatgpt.com","perplexity.ai","gemini.google.com","copilot.microsoft.com","claude.ai"]', $data );
		$js = file_get_contents( dirname( __DIR__ ) . '/assets/aktio-beacon.js' );
		$this->assertStringContainsString( 'c.hosts', $js );
		$this->assertLessThanOrEqual( 1024, strlen( $js ) );
	}

	public function test_more_than_200_paths_a_day_are_counted_without_path() {
		for ( $i = 1; $i <= 202; $i++ ) {
			Metrics::record_referral( 'chatgpt.com', '/s' . $i . '/' );
		}
		global $wpdb;
		$with_path = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Metrics::table() . " WHERE kind = 'referral' AND path <> ''" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 200, $with_path );
		$this->assertSame( 2, $this->count_of( 'referral', 'chatgpt.com', '' ) );

		// Ścieżka, która już ma wiersz tego dnia, nadal rośnie.
		Metrics::record_referral( 'chatgpt.com', '/s1/' );
		$this->assertSame( 2, $this->count_of( 'referral', 'chatgpt.com', '/s1/' ) );
	}

	public function test_no_ip_is_stored_in_table_or_aktio_options() {
		$this->beacon( array( 'r' => 'https://chatgpt.com/', 'p' => '/a/' ), '203.0.113.77' );
		Metrics::record_bot( 'GPTBot/1.0' );
		global $wpdb;
		$this->assertNotSame( array(), $this->rows() );
		foreach ( $this->rows() as $row ) {
			$this->assertStringNotContainsString( '203.0.113.77', implode( '|', $row ) );
		}
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%aktio%'", ARRAY_A ); // phpcs:ignore WordPress.DB
		$this->assertNotSame( array(), $options );
		foreach ( $options as $option ) {
			$this->assertStringNotContainsString( '203.0.113.77', $option['option_name'] . $option['option_value'] );
		}
	}

	public function test_metrics_endpoint_requires_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/metrics' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_metrics_endpoint_returns_series_top_paths_and_note() {
		Metrics::record_bot( 'GPTBot/1.0' );
		Metrics::record_bot( 'GPTBot/1.0' );
		Metrics::record_referral( 'chatgpt.com', '/a/' );
		Metrics::record_referral( 'chatgpt.com', '/a/' );
		Metrics::record_referral( 'claude.ai', '/b/' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$request = new WP_REST_Request( 'GET', '/aktio/v1/metrics' );
		$request->set_param( 'days', 30 );
		$data  = rest_do_request( $request )->get_data();
		$today = gmdate( 'Y-m-d' );
		$this->assertSame( array( array( $today, 2 ) ), $data['bots']['GPTBot'] );
		$this->assertSame( array( array( $today, 2 ) ), $data['referrals']['chatgpt.com'] );
		$this->assertSame( array( array( $today, 1 ) ), $data['referrals']['claude.ai'] );
		$this->assertSame(
			array(
				array( 'path' => '/a/', 'count' => 2 ),
				array( 'path' => '/b/', 'count' => 1 ),
			),
			$data['top_paths']
		);
		$this->assertSame( 'cache_undercount', $data['note'] );
	}

	public function test_metrics_range_excludes_older_days() {
		global $wpdb;
		$old = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
		$wpdb->insert( Metrics::table(), array( 'day' => $old, 'kind' => 'bot', 'name' => 'GPTBot', 'path' => '', 'count' => 5 ) ); // phpcs:ignore WordPress.DB
		$this->assertArrayNotHasKey( 'GPTBot', Metrics::report( 30 )['bots'] );
		$this->assertArrayHasKey( 'GPTBot', Metrics::report( 90 )['bots'] );
	}

	public function test_purge_removes_rows_older_than_400_days() {
		global $wpdb;
		$wpdb->insert( Metrics::table(), array( 'day' => gmdate( 'Y-m-d', time() - 401 * DAY_IN_SECONDS ), 'kind' => 'bot', 'name' => 'GPTBot', 'path' => '', 'count' => 1 ) ); // phpcs:ignore WordPress.DB
		$wpdb->insert( Metrics::table(), array( 'day' => gmdate( 'Y-m-d', time() - 399 * DAY_IN_SECONDS ), 'kind' => 'bot', 'name' => 'ClaudeBot', 'path' => '', 'count' => 1 ) ); // phpcs:ignore WordPress.DB
		Metrics::purge();
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ClaudeBot', $rows[0]['name'] );
	}

	public function test_daily_purge_cron_is_scheduled() {
		wp_clear_scheduled_hook( Metrics::CRON );
		Metrics::schedule();
		$this->assertNotFalse( wp_next_scheduled( Metrics::CRON ) );
		$this->assertSame( 'daily', wp_get_schedule( Metrics::CRON ) );
	}

	public function test_schema_version_is_stored_after_install() {
		delete_option( Metrics::SCHEMA_OPTION );
		Metrics::install();
		$this->assertSame( '1', (string) get_option( Metrics::SCHEMA_OPTION ) );
	}

	public function test_uninstall_drops_table_and_clears_crons() {
		Metrics::schedule();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}
		// Testy WP zamieniają DROP TABLE na tymczasowy, więc sprawdzamy samo polecenie.
		$dropped = false;
		$catch   = function ( $query ) use ( &$dropped ) {
			if ( preg_match( '/DROP\s+(TEMPORARY\s+)?TABLE\s+IF EXISTS\s+`?' . preg_quote( Metrics::table(), '/' ) . '`?/i', $query ) ) {
				$dropped = true;
			}
			return $query;
		};
		add_filter( 'query', $catch );
		include dirname( __DIR__ ) . '/uninstall.php';
		remove_filter( 'query', $catch );
		$this->assertFalse( wp_next_scheduled( Metrics::CRON ) );
		$this->assertFalse( get_option( Metrics::SCHEMA_OPTION ) );
		$this->assertTrue( $dropped );
	}

	public function test_beacon_script_is_enqueued_on_front_only_deferred_small_and_local() {
		set_current_screen( 'front' );
		do_action( 'wp_enqueue_scripts' );
		$script = wp_scripts()->registered['aktio-beacon'] ?? null;
		$this->assertNotNull( $script );
		$this->assertTrue( wp_script_is( 'aktio-beacon', 'enqueued' ) );
		$this->assertSame( 'defer', $script->extra['strategy'] ?? null );

		$file = dirname( __DIR__ ) . '/assets/aktio-beacon.js';
		$js   = file_get_contents( $file );
		$this->assertLessThanOrEqual( 1024, strlen( $js ) );
		$this->assertDoesNotMatchRegularExpression( '#https?://|//[a-z0-9.-]+\.[a-z]{2,}#i', $js );
		$this->assertStringContainsString( 'sendBeacon', $js );
		$this->assertStringNotContainsString( 'cookie', $js );
		$this->assertStringNotContainsString( 'localStorage', $js );
	}

	public function test_beacon_script_is_not_enqueued_in_admin() {
		wp_dequeue_script( 'aktio-beacon' );
		set_current_screen( 'dashboard' );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( 'aktio-beacon', 'enqueued' ) );
	}
}
