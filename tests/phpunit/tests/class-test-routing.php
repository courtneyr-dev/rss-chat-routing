<?php
/**
 * Routing decisions, checked against the real RSS Chat plugin.
 *
 * @package RSS_Chat_Routing
 * @group rss-chat-routing
 */

namespace RSS_Chat_Routing\Tests;

use WP_UnitTestCase;
use RSS_Chat\Plugin;
use RSS_Chat_Routing\Rules;

/**
 * End-to-end routing tests: did RSS Chat actually push, or not?
 */
class Test_Routing extends WP_UnitTestCase {

	/**
	 * Captured /newpost request URLs.
	 *
	 * @var string[]
	 */
	private $newposts = array();

	/**
	 * Connect an account, register a kind taxonomy, stub the network.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->newposts = array();
		\add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );

		Plugin::update_account(
			array(
				'email'      => 'me@example.com',
				'code'       => 'secret-code',
				'screenname' => 'me',
			)
		);

		// Stand in for Post Kinds, which is not installed in this suite.
		\register_taxonomy(
			'kind',
			'post',
			array(
				'public'       => true,
				'hierarchical' => false,
			)
		);
	}

	/**
	 * Reset.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		\delete_option( Rules::OPTION );
		Plugin::clear_account();
		\unregister_taxonomy( 'kind' );
		parent::tear_down();
	}

	/**
	 * Capture /newpost and answer with a synthetic item.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== \strpos( $url, '/newpost' ) ) {
			$this->newposts[] = $url;
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
				'body'     => (string) \wp_json_encode(
					array(
						'id'   => 4242,
						'guid' => 'https://rss.chat/?id=4242',
					)
				),
			);
		}
		return $response;
	}

	/**
	 * Set the site rules.
	 *
	 * @param string   $mode  One of format, all, none.
	 * @param string[] $kinds Kind slugs to always send.
	 * @return void
	 */
	private function set_rules( $mode, $kinds = array() ) {
		\update_option(
			Rules::OPTION,
			array(
				'mode'  => $mode,
				'kinds' => $kinds,
			)
		);
	}

	/**
	 * Publish a post, optionally with a format, kind, or per-post override.
	 *
	 * @param array $args format, kind, override.
	 * @return int Post id.
	 */
	private function publish( $args = array() ) {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Hello network',
				'post_content' => 'Body copy.',
			)
		);

		if ( ! empty( $args['format'] ) ) {
			\set_post_format( $post_id, $args['format'] );
		}
		if ( ! empty( $args['kind'] ) ) {
			\wp_set_object_terms( $post_id, $args['kind'], 'kind' );
		}
		if ( isset( $args['override'] ) ) {
			\update_post_meta( $post_id, Rules::META_KEY, $args['override'] );
		}

		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		return $post_id;
	}

	/**
	 * Out of the box nothing changes: chat posts still go.
	 */
	public function test_format_mode_still_sends_chat_posts() {
		$this->set_rules( 'format' );

		$this->publish( array( 'format' => 'chat' ) );

		$this->assertCount( 1, $this->newposts );
	}

	/**
	 * Out of the box nothing changes: other posts still stay put.
	 */
	public function test_format_mode_still_ignores_other_posts() {
		$this->set_rules( 'format' );

		$this->publish();

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * Send everything: a plain post goes without any format.
	 */
	public function test_all_mode_sends_a_plain_post() {
		$this->set_rules( 'all' );

		$post_id = $this->publish();

		$this->assertCount( 1, $this->newposts );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
	}

	/**
	 * Send everything, except the one the author held back.
	 */
	public function test_all_mode_respects_a_per_post_no() {
		$this->set_rules( 'all' );

		$this->publish( array( 'override' => '0' ) );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * Send nothing: even a chat-format post stays put.
	 */
	public function test_none_mode_holds_back_a_chat_post() {
		$this->set_rules( 'none' );

		$this->publish( array( 'format' => 'chat' ) );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * Send nothing, except the one the author opted in.
	 */
	public function test_none_mode_respects_a_per_post_yes() {
		$this->set_rules( 'none' );

		$this->publish( array( 'override' => '1' ) );

		$this->assertCount( 1, $this->newposts );
	}

	/**
	 * A chosen kind sends without borrowing the post format.
	 */
	public function test_kind_rule_sends_a_note() {
		$this->set_rules( 'none', array( 'note' ) );

		$post_id = $this->publish( array( 'kind' => 'note' ) );

		$this->assertCount( 1, $this->newposts );
		$this->assertFalse( \get_post_format( $post_id ), 'the post format must be left alone' );
	}

	/**
	 * A kind that was not chosen is left alone.
	 */
	public function test_kind_rule_ignores_other_kinds() {
		$this->set_rules( 'none', array( 'note' ) );

		$this->publish( array( 'kind' => 'bookmark' ) );

		$this->assertCount( 0, $this->newposts );
	}

	/**
	 * The stand-in is torn down: post formats read normally afterwards.
	 */
	public function test_format_reads_normally_after_a_routed_push() {
		$this->set_rules( 'all' );

		$post_id = $this->publish( array( 'format' => 'aside' ) );

		$this->assertCount( 1, $this->newposts );
		$this->assertSame( 'aside', \get_post_format( $post_id ) );
		$this->assertFalse( \has_filter( 'get_the_terms', array( '\\RSS_Chat_Routing\\Router', 'answer' ) ) );
	}

	/**
	 * The feed stays consistent: a routed post still carries the source: elements.
	 */
	public function test_feed_decorates_a_routed_post() {
		$this->set_rules( 'all' );

		$post_id = $this->publish();
		$this->assertCount( 1, $this->newposts, 'precondition: the post was syndicated' );

		$GLOBALS['post'] = \get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		\setup_postdata( $GLOBALS['post'] );

		\ob_start();
		\do_action( 'rss2_item' );
		$output = (string) \ob_get_clean();

		\wp_reset_postdata();

		$this->assertStringContainsString( 'source:markdown', $output );
		$this->assertStringContainsString( 'source:comments', $output );
	}
}
