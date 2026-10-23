<?php
use AktioConnector\Schema\Graph;

class FaqTest extends WP_UnitTestCase {

	private $seo_active = false;

	public function set_up() {
		parent::set_up();
		$this->seo_active = false;
		add_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		add_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
	}

	public function tear_down() {
		remove_filter( 'option_active_plugins', array( $this, 'filter_active' ) );
		remove_filter( 'site_option_active_sitewide_plugins', '__return_empty_array' );
		$dir = WP_PLUGIN_DIR . '/wordpress-seo';
		if ( is_dir( $dir ) ) {
			unlink( $dir . '/wp-seo.php' );
			rmdir( $dir );
		}
		parent::tear_down();
	}

	public function filter_active() {
		return $this->seo_active ? array( 'wordpress-seo/wp-seo.php' ) : array();
	}

	private function faq_nodes(): array {
		$nodes = array();
		foreach ( Graph::nodes() as $node ) {
			if ( 'FAQPage' === $node['@type'] ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	private function block( string $q, string $a ): string {
		$attrs = wp_json_encode(
			array(
				'items' => array(
					array(
						'q' => $q,
						'a' => $a,
					),
				),
			)
		);
		return '<!-- wp:aktio/faq ' . $attrs . ' /-->';
	}

	public function test_block_renders_escaped_question_and_kses_answer() {
		$html = render_block(
			array(
				'blockName'    => 'aktio/faq',
				'attrs'        => array(
					'items' => array(
						array(
							'q' => 'A <script>x</script>?',
							'a' => '<b>Tak</b><script>evil()</script>',
						),
					),
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( '<div class="aktio-faq"><details><summary>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '<b>Tak</b>', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_shortcode_parses_q_a_lines() {
		$html = do_shortcode( "[aktio_faq]\nQ: Ile?\nA: Dużo.\nQ: Kiedy?\nA: <i>Dziś</i>\n[/aktio_faq]" );
		$this->assertSame( 2, substr_count( $html, '<details>' ) );
		$this->assertStringContainsString( '<summary>Ile?</summary><div>Dużo.</div>', $html );
		$this->assertStringContainsString( '<i>Dziś</i>', $html );
	}

	public function test_empty_list_renders_nothing() {
		$this->assertSame( '', do_shortcode( '[aktio_faq][/aktio_faq]' ) );
		$this->assertSame( '', do_blocks( '<!-- wp:aktio/faq {"items":[]} /-->' ) );
	}

	public function test_two_blocks_yield_single_faqpage_node() {
		$id = self::factory()->post->create(
			array( 'post_content' => $this->block( 'Pierwsze?', 'Tak.' ) . $this->block( 'Drugie?', 'Nie.' ) )
		);
		$this->go_to( get_permalink( $id ) );
		$nodes = $this->faq_nodes();
		$this->assertCount( 1, $nodes );
		$this->assertCount( 2, $nodes[0]['mainEntity'] );
		$this->assertSame( 'Question', $nodes[0]['mainEntity'][0]['@type'] );
		$this->assertSame( 'Pierwsze?', $nodes[0]['mainEntity'][0]['name'] );
		$this->assertSame( 'Nie.', $nodes[0]['mainEntity'][1]['acceptedAnswer']['text'] );
	}

	public function test_shortcode_questions_land_in_graph() {
		$id = self::factory()->post->create( array( 'post_content' => "[aktio_faq]\nQ: Ile?\nA: Dużo.\n[/aktio_faq]" ) );
		$this->go_to( get_permalink( $id ) );
		$nodes = $this->faq_nodes();
		$this->assertCount( 1, $nodes );
		$this->assertSame( 'Ile?', $nodes[0]['mainEntity'][0]['name'] );
	}

	public function test_password_protected_post_faq_not_in_graph_after_password_entered() {
		$id = self::factory()->post->create(
			array( 'post_password' => 'haslo', 'post_content' => $this->block( 'Sekret?', 'Tak.' ) )
		);
		add_filter( 'post_password_required', '__return_false' );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( array(), $this->faq_nodes() );
		remove_filter( 'post_password_required', '__return_false' );
	}

	public function test_no_faq_no_node() {
		$id = self::factory()->post->create( array( 'post_content' => 'Zwykły tekst.' ) );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( array(), $this->faq_nodes() );
	}

	public function test_with_yoast_content_present_but_no_schema() {
		$dir = WP_PLUGIN_DIR . '/wordpress-seo';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/wp-seo.php', "<?php\n/* Plugin Name: Yoast SEO */\n" );
		$this->seo_active = true;
		$id               = self::factory()->post->create( array( 'post_content' => $this->block( 'Q?', 'A.' ) ) );
		$this->go_to( get_permalink( $id ) );
		$this->assertSame( array(), $this->faq_nodes() );
		$this->assertStringContainsString( '<summary>Q?</summary>', do_blocks( get_post( $id )->post_content ) );
	}
}
