<?php
use AktioConnector\Settings;

class SettingsTest extends WP_UnitTestCase {

	public function test_defaults_allow_all_bots_and_use_site_name() {
		$s = Settings::get();
		$this->assertSame( array_fill_keys( Settings::BOTS, 'allow' ), $s['bots'] );
		$this->assertSame( get_bloginfo( 'name' ), $s['company']['name'] );
		$this->assertSame( 'Organization', $s['company']['type'] );
	}

	public function test_update_rejects_unknown_bot_and_bad_values() {
		$s = Settings::update( array( 'bots' => array( 'GPTBot' => 'block', 'EvilBot' => 'block', 'ClaudeBot' => 'maybe' ) ) );
		$this->assertSame( 'block', $s['bots']['GPTBot'] );
		$this->assertArrayNotHasKey( 'EvilBot', $s['bots'] );
		$this->assertSame( 'allow', $s['bots']['ClaudeBot'] );
	}

	public function test_update_sanitizes_company_and_persists() {
		$long = str_repeat( 'a', 300 );
		$s    = Settings::update(
			array(
				'company' => array(
					'name'    => '<b>Firma</b> ' . $long,
					'type'    => 'Nonsense',
					'logo'    => 'javascript:alert(1)',
					'email'   => 'zly mail',
					'same_as' => array_map( function ( $i ) { return "https://example.com/$i"; }, range( 1, 15 ) ),
				),
			)
		);
		$this->assertSame( 200, strlen( $s['company']['name'] ) );
		$this->assertStringNotContainsString( '<b>', $s['company']['name'] );
		$this->assertSame( 'Organization', $s['company']['type'] );
		$this->assertSame( '', $s['company']['logo'] );
		$this->assertSame( '', $s['company']['email'] );
		$this->assertCount( 10, $s['company']['same_as'] );
		$this->assertSame( $s, Settings::get() );
	}

	public function test_update_accepts_local_business_and_valid_url() {
		$s = Settings::update( array( 'company' => array( 'type' => 'LocalBusiness', 'logo' => 'https://example.com/logo.png' ) ) );
		$this->assertSame( 'LocalBusiness', $s['company']['type'] );
		$this->assertSame( 'https://example.com/logo.png', $s['company']['logo'] );
	}

	public function test_company_name_not_frozen_to_site_name() {
		update_option( 'blogname', 'Pierwsza' );
		Settings::update( array( 'company' => array( 'phone' => '123' ) ) );
		$this->assertSame( 'Pierwsza', Settings::get()['company']['name'] );
		update_option( 'blogname', 'Druga' );
		$this->assertSame( 'Druga', Settings::get()['company']['name'] );
		$this->assertSame( '', get_option( Settings::OPTION )['company']['name'] );
	}

	public function test_explicit_company_name_is_kept_and_empty_falls_back() {
		update_option( 'blogname', 'Witryna' );
		$s = Settings::update( array( 'company' => array( 'name' => 'Moja Firma' ) ) );
		$this->assertSame( 'Moja Firma', $s['company']['name'] );
		update_option( 'blogname', 'Inna' );
		$this->assertSame( 'Moja Firma', Settings::get()['company']['name'] );
		$s = Settings::update( array( 'company' => array( 'name' => '' ) ) );
		$this->assertSame( 'Inna', $s['company']['name'] );
		$this->assertSame( 'Inna', Settings::get()['company']['name'] );
	}
}
