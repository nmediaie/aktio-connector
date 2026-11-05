<?php
use AktioConnector\IndexNow;
use AktioConnector\Seo\Head;
use AktioConnector\Settings;

class IndexNowTest extends WP_UnitTestCase {

	private $requests = array();
	private $status   = 200;
	private $active   = array();

	public function set_up() {
		parent::set_up();
		$this->requests = array();
		$this->status   = 200;
		$this->active   = array();
		update_option( 'blog_public', 1 );
		delete_option( Settings::OPTION );
		delete_option( IndexNow::QUEUE );
		delete_option( IndexNow::LOG );
		delete_option( IndexNow::LAST );
		delete_option( IndexNow::SITEMAP_LAST );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type( 'product', array( 'public' => true ) );
		}
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		delete_option( Settings::OPTION );
		delete_option( IndexNow::QUEUE );
		delete_option( IndexNow::LOG );
		delete_option( IndexNow::LAST );
		delete_option( IndexNow::SITEMAP_LAST );
		wp_clear_scheduled_hook( IndexNow::CRON );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array( 'url' => $url, 'args' => $args );
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => $this->status, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function filter_active() {
		return $this->active;
	}

	private function enable( bool $on = true ): void {
		Settings::update( array( 'indexnow' => array( 'enabled' => $on ) ) );
	}

	private function queue(): array {
		$queue = get_option( IndexNow::QUEUE, array() );
		return is_array( $queue ) ? $queue : array();
	}

	public function test_publish_update_and_delete_of_post_queue_the_url() {
		$this->enable();
		$id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$url = get_permalink( $id );
		$this->assertSame( array( $url ), $this->queue() );

		delete_option( IndexNow::QUEUE );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Zmieniony' ) );
		$this->assertSame( array( $url ), $this->queue() );

		delete_option( IndexNow::QUEUE );
		wp_delete_post( $id, true );
		$this->assertSame( array( $url ), $this->queue() );
	}

	public function test_page_product_and_term_are_queued() {
		$this->enable();
		$page    = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$product = self::factory()->post->create( array( 'post_type' => 'product', 'post_status' => 'publish' ) );
		$term    = self::factory()->term->create( array( 'taxonomy' => 'category', 'name' => 'Nowa' ) );
		$queue   = $this->queue();
		$this->assertContains( get_permalink( $page ), $queue );
		$this->assertContains( get_permalink( $product ), $queue );
		$this->assertContains( get_term_link( (int) $term, 'category' ), $queue );

		$link = get_term_link( (int) $term, 'category' );
		delete_option( IndexNow::QUEUE );
		wp_delete_term( $term, 'category' );
		$this->assertSame( array( $link ), $this->queue() );
	}

	public function test_queue_deduplicates() {
		$this->enable();
		IndexNow::queue( home_url( '/a/' ) );
		IndexNow::queue( home_url( '/a/' ) );
		IndexNow::queue( 'https://obcy.example.org/a/' );
		$this->assertSame( array( home_url( '/a/' ) ), $this->queue() );
	}

	public function test_flush_sends_one_post_with_expected_body() {
		$this->enable();
		IndexNow::queue( home_url( '/a/' ) );
		IndexNow::queue( home_url( '/b/' ) );
		$result = IndexNow::flush();
		$this->assertSame( array( 'sent' => 2, 'code' => 200 ), $result );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.indexnow.org/indexnow', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$key  = Settings::get()['indexnow']['key'];
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $body['host'] );
		$this->assertSame( $key, $body['key'] );
		$this->assertSame( home_url( '/' . $key . '.txt' ), $body['keyLocation'] );
		$this->assertSame( array( home_url( '/a/' ), home_url( '/b/' ) ), $body['urlList'] );
		$this->assertSame( array(), $this->queue() );
		$this->assertNotNull( IndexNow::last() );
	}

	public function test_flush_sends_at_most_10000_urls() {
		$this->enable();
		$urls = array();
		for ( $i = 0; $i < 10005; $i++ ) {
			$urls[] = home_url( '/p' . $i . '/' );
		}
		update_option( IndexNow::QUEUE, $urls, false );
		IndexNow::flush();
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertCount( 10000, $body['urlList'] );
		$this->assertCount( 5, $this->queue() );
	}

	public function test_failed_flush_keeps_queue_on_server_error() {
		$this->enable();
		$this->status = 500;
		IndexNow::queue( home_url( '/a/' ) );
		$result = IndexNow::flush();
		$this->assertSame( array( 'sent' => 0, 'code' => 500 ), $result );
		$this->assertSame( array( home_url( '/a/' ) ), $this->queue() );
		$this->assertNull( IndexNow::last() );
	}

	public function test_disabled_module_does_nothing() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		IndexNow::queue( get_permalink( $id ) );
		$this->assertSame( array(), $this->queue() );
		$this->assertSame( array( 'sent' => 0, 'code' => null ), IndexNow::flush() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_private_site_sends_nothing_even_for_sitemap() {
		$this->enable();
		IndexNow::queue( home_url( '/a/' ) );
		update_option( 'blog_public', 0 );
		$this->assertSame( array( 'sent' => 0, 'code' => null ), IndexNow::flush() );
		$this->assertInstanceOf( WP_Error::class, IndexNow::submit_sitemap() );
		IndexNow::queue( home_url( '/b/' ) );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( array(), $this->queue() );
	}

	public function test_draft_password_and_noindex_addresses_are_skipped() {
		$this->enable();
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_password' => 'tajne' ) );
		$attachment = wp_insert_post(
			array(
				'post_type'   => 'attachment',
				'post_status' => 'publish',
				'post_title'  => 'Plik',
			)
		);
		$this->assertTrue( Head::noindex_for_post( get_post( $attachment ) ) );
		$this->assertSame( array(), $this->queue() );
	}

	public function test_key_file_served_as_plain_text() {
		$this->enable();
		$key = Settings::get()['indexnow']['key'];
		$res = IndexNow::key_response( $key );
		$this->assertSame( 200, $res['status'] );
		$this->assertSame( $key, $res['body'] );
		$this->assertSame( 'text/plain; charset=utf-8', $res['headers']['Content-Type'] );
		$this->assertSame( 404, IndexNow::key_response( str_repeat( 'a', 32 ) )['status'] );
		$this->enable( false );
		$this->assertSame( 404, IndexNow::key_response( $key )['status'] );
	}

	public function test_key_file_rewrite_rule() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		IndexNow::add_rewrite();
		flush_rewrite_rules();
		$this->assertContains( '^([a-f0-9]{32})\.txt$', array_keys( (array) $wp_rewrite->wp_rewrite_rules() ) );
	}

	public function test_submit_sitemap_once_per_24h() {
		$this->enable();
		$id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$result = IndexNow::submit_sitemap();
		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['code'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertContains( get_permalink( $id ), $body['urlList'] );
		$this->assertContains( home_url( '/' ), $body['urlList'] );

		$again = IndexNow::submit_sitemap();
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'aktio_rate_limited', $again->get_error_code() );
		$this->assertCount( 1, $this->requests );

		update_option( IndexNow::SITEMAP_LAST, time() - DAY_IN_SECONDS - 5, false );
		$this->assertIsArray( IndexNow::submit_sitemap() );
	}

	public function test_submit_sitemap_accepts_string_url_and_reports_per_url() {
		$this->enable();
		$url    = home_url( '/jeden/' );
		$result = IndexNow::submit_sitemap( $url );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['queued'] );
		$this->assertSame( 200, $result['code'] );
		$this->assertSame( array( $url ), json_decode( $this->requests[0]['args']['body'], true )['urlList'] );
		$this->assertSame( array( array( 'url' => $url, 'lang' => 'en', 'sent' => true ) ), $result['urls'] );
		$this->assertSame( 'single_sitemap', $result['note'] );
		// Jawna lista adresów nie uruchamia limitu 24 h i go nie ustawia.
		$this->assertIsArray( IndexNow::submit_sitemap( array( $url, home_url( '/dwa/' ) ) ) );
		$this->assertSame( 0, (int) get_option( IndexNow::SITEMAP_LAST, 0 ) );
	}

	public function test_submit_sitemap_array_skips_foreign_host() {
		$this->enable();
		$own    = home_url( '/moja/' );
		$result = IndexNow::submit_sitemap( array( $own, 'https://obcy.example/x' ) );
		$this->assertSame( 1, $result['queued'] );
		$this->assertSame( array( true, false ), array_column( $result['urls'], 'sent' ) );
		$this->assertSame( 'foreign_host', $result['urls'][1]['error'] );
	}

	public function test_rest_sitemap_accepts_urls_string_or_array_and_rejects_other_types() {
		$this->enable();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$req = new WP_REST_Request( 'POST', '/aktio/v1/indexnow/sitemap' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'urls' => home_url( '/a/' ) ) ) );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, $res->get_data()['queued'] );

		$req->set_body( wp_json_encode( array( 'urls' => 5 ) ) );
		$this->assertSame( 400, rest_do_request( $req )->get_status() );
	}

	public function test_rules_flushed_once_when_rules_version_changes() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		IndexNow::add_rewrite();
		delete_option( 'rewrite_rules' );
		delete_option( IndexNow::RULES_OPTION );

		IndexNow::maybe_flush_rules();
		$this->assertContains( '^([a-f0-9]{32})\.txt$', array_keys( (array) get_option( 'rewrite_rules' ) ) );
		$this->assertSame( IndexNow::RULES_VERSION, (int) get_option( IndexNow::RULES_OPTION ) );

		// Drugi raz nic nie robi: skasowane reguły nie są odtwarzane przez maybe_flush_rules().
		delete_option( 'rewrite_rules' );
		IndexNow::maybe_flush_rules();
		$this->assertFalse( get_option( 'rewrite_rules' ) );
	}

	public function test_rules_flushed_when_indexnow_gets_enabled() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		IndexNow::add_rewrite();
		delete_option( 'rewrite_rules' );

		$this->enable( false );
		$this->assertFalse( get_option( 'rewrite_rules' ) );

		$this->enable();
		$this->assertContains( '^([a-f0-9]{32})\.txt$', array_keys( (array) get_option( 'rewrite_rules' ) ) );

		// Ponowny zapis przy już włączonym module nie flushuje.
		delete_option( 'rewrite_rules' );
		$this->enable();
		$this->assertFalse( get_option( 'rewrite_rules' ) );
	}

	public function test_rules_flushed_when_settings_option_is_created_enabled() {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		IndexNow::add_rewrite();
		delete_option( Settings::OPTION );
		delete_option( 'rewrite_rules' );
		$this->enable();
		$this->assertContains( '^([a-f0-9]{32})\.txt$', array_keys( (array) get_option( 'rewrite_rules' ) ) );
	}

	public function test_flush_keeps_queue_on_auth_errors_and_drops_it_on_other_client_errors() {
		$this->enable();
		foreach ( array( 401, 403, 429, 500 ) as $code ) {
			update_option( IndexNow::QUEUE, array( home_url( '/a/' ) ), false );
			$this->status = $code;
			$this->assertSame( array( 'sent' => 0, 'code' => $code ), IndexNow::flush() );
			$this->assertSame( array( home_url( '/a/' ) ), $this->queue(), (string) $code );
		}
		update_option( IndexNow::QUEUE, array( home_url( '/a/' ) ), false );
		$this->status = 422;
		IndexNow::flush();
		$this->assertSame( array(), $this->queue() );
	}

	public function test_queue_is_capped_and_newest_urls_win() {
		$this->enable();
		$old = array();
		for ( $i = 0; $i < IndexNow::QUEUE_MAX; $i++ ) {
			$old[] = home_url( '/p' . $i . '/' );
		}
		update_option( IndexNow::QUEUE, $old, false );
		IndexNow::queue( home_url( '/nowy/' ) );
		$queue = $this->queue();
		$this->assertCount( IndexNow::QUEUE_MAX, $queue );
		$this->assertSame( home_url( '/nowy/' ), end( $queue ) );
		$this->assertNotContains( home_url( '/p0/' ), $queue );
		$this->assertContains( home_url( '/p1/' ), $queue );
	}

	public function test_sitemap_marker_set_only_after_success() {
		$this->enable();
		$this->status = 500;
		$result       = IndexNow::submit_sitemap();
		$this->assertSame( 0, $result['sent'] );
		$this->assertSame( 0, (int) get_option( IndexNow::SITEMAP_LAST, 0 ) );

		$this->status = 200;
		$this->assertIsArray( IndexNow::submit_sitemap() );
		$this->assertGreaterThan( 0, (int) get_option( IndexNow::SITEMAP_LAST, 0 ) );
		$this->assertInstanceOf( WP_Error::class, IndexNow::submit_sitemap() );
	}

	public function test_submit_sitemap_skips_woocommerce_cart_checkout_account_pages() {
		$this->enable();
		$ids = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$ids[ $page ] = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => $page ) );
			update_option( 'woocommerce_' . $page . '_page_id', $ids[ $page ] );
		}
		$normal = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Kontakt' ) );
		IndexNow::submit_sitemap();
		$list = json_decode( $this->requests[0]['args']['body'], true )['urlList'];
		$this->assertContains( get_permalink( $normal ), $list );
		foreach ( $ids as $page => $id ) {
			$this->assertNotContains( get_permalink( $id ), $list, $page );
		}
	}

	public function test_log_keeps_last_50_newest_first() {
		$this->enable();
		for ( $i = 1; $i <= 55; $i++ ) {
			IndexNow::queue( home_url( '/p' . $i . '/' ) );
			IndexNow::flush();
		}
		$this->assertCount( 10, IndexNow::log() );
		$this->assertCount( 50, IndexNow::log( 100 ) );
		$this->assertSame( 200, IndexNow::log( 1 )[0]['code'] );
		$this->assertSame( 1, IndexNow::log( 1 )[0]['count'] );
	}

	public function test_verification_meta_tags_on_front_page() {
		Settings::update( array( 'verification' => array( 'bing' => str_repeat( 'AB', 16 ), 'google' => 'abcdefghij0123456789_-XY' ) ) );
		$this->go_to( home_url( '/' ) );
		ob_start();
		Head::print_head();
		$out = ob_get_clean();
		$this->assertStringContainsString( '<meta name="msvalidate.01" content="' . str_repeat( 'AB', 16 ) . '" />', $out );
		$this->assertStringContainsString( '<meta name="google-site-verification" content="abcdefghij0123456789_-XY" />', $out );

		$this->active = array( 'google-site-kit/google-site-kit.php' );
		ob_start();
		Head::print_head();
		$out = ob_get_clean();
		$this->assertStringContainsString( 'msvalidate.01', $out );
		$this->assertStringNotContainsString( 'google-site-verification', $out );
	}

	public function test_no_verification_meta_when_not_set() {
		$this->go_to( home_url( '/' ) );
		ob_start();
		Head::print_head();
		$out = ob_get_clean();
		$this->assertStringNotContainsString( 'verification', $out );
		$this->assertStringNotContainsString( 'msvalidate', $out );
	}

	public function test_cron_schedule_every_5_minutes() {
		$schedules = wp_get_schedules();
		$this->assertSame( 300, $schedules['aktio_five_minutes']['interval'] );
		IndexNow::schedule();
		$this->assertNotFalse( wp_next_scheduled( IndexNow::CRON ) );
		IndexNow::unschedule();
		$this->assertFalse( wp_next_scheduled( IndexNow::CRON ) );
	}

	public function test_rest_sitemap_endpoint_requires_manage_options_and_rate_limits() {
		$this->enable();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', '/aktio/v1/indexnow/sitemap' ) )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$first = rest_do_request( new WP_REST_Request( 'POST', '/aktio/v1/indexnow/sitemap' ) );
		$this->assertSame( 200, $first->get_status() );
		$second = rest_do_request( new WP_REST_Request( 'POST', '/aktio/v1/indexnow/sitemap' ) );
		$this->assertSame( 429, $second->get_status() );
		$this->assertSame( 'aktio_rate_limited', $second->get_data()['code'] );
	}

	public function test_status_exposes_indexnow() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = rest_do_request( new WP_REST_Request( 'GET', '/aktio/v1/status' ) )->get_data();
		$this->assertSame( array( 'enabled' => false, 'last' => null ), $data['indexnow'] );
	}

	public function test_key_file_not_redirected_to_trailing_slash() {
		global $wp_rewrite;
		$this->enable();
		$key = Settings::get()['indexnow']['key'];
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		IndexNow::add_rewrite();
		flush_rewrite_rules();
		$this->go_to( home_url( '/' . $key . '.txt' ) );
		$this->assertSame( $key, get_query_var( IndexNow::QUERY_VAR ) );
		$this->assertNull( redirect_canonical( home_url( '/' . $key . '.txt' ), false ) );
	}
}
