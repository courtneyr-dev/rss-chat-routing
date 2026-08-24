<?php
/**
 * Reply-import modes: legacy backfeed, webmention, disabled — and the
 * guarantee that one remote reply never becomes two comments.
 *
 * @package RSS_Chat_Routing
 * @group rss-chat-routing
 */

namespace RSS_Chat_Routing\Tests;

use WP_UnitTestCase;
use RSS_Chat\Plugin;
use RSS_Chat\Backfeed;
use RSS_Chat_Routing\Rules;
use RSS_Chat_Routing\Reply_Import;

/**
 * Backfeed on/off per mode, and webmention-vs-legacy dedup.
 */
class Test_Reply_Import extends WP_UnitTestCase {

	/**
	 * A post that is already synced to rss.chat.
	 *
	 * @var int
	 */
	private $synced_post;

	/**
	 * Connect and prepare a synced post plus a reply on the wire.
	 */
	public function set_up(): void {
		parent::set_up();

		\add_filter( 'pre_http_request', array( $this, 'stub_http' ), 100, 3 );

		Plugin::update_account(
			array(
				'email'      => 'me@example.com',
				'code'       => 'secret-code',
				'screenname' => 'me',
			)
		);

		$this->synced_post = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $this->synced_post, Plugin::META_ID, 777 );
	}

	/**
	 * Reset, and restore the legacy importer for the next test.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 100 );
		\delete_option( Rules::OPTION );
		Plugin::clear_account();
		Reply_Import::apply_mode();
		parent::tear_down();
	}

	/**
	 * Serve one remote reply for the synced post.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== $response || false === \strpos( $url, '/getitemandreplies' ) ) {
			return $response;
		}

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => (string) \wp_json_encode(
				array(
					array(
						'id'   => 777,
						'guid' => 'https://rss.chat/?id=777',
					),
					array(
						'id'           => 778,
						'guid'         => 'https://rss.chat/?id=778',
						'markdowntext' => 'A reply from the network.',
						'author'       => 'someone',
						'inReplyToNum' => 777,
					),
				)
			),
		);
	}

	/**
	 * Set the reply-import mode and apply it.
	 *
	 * @param string $mode legacy, webmention, or disabled.
	 */
	private function set_mode( $mode ) {
		\update_option( Rules::OPTION, array( 'reply_import' => $mode ) );
		Reply_Import::apply_mode();
	}

	/**
	 * Run the parent's backfeed cron.
	 */
	private function run_backfeed() {
		\do_action( Backfeed::HOOK );
	}

	/**
	 * Comments on the synced post.
	 *
	 * @return array
	 */
	private function comments() {
		return \get_comments( array( 'post_id' => $this->synced_post ) );
	}

	// ---------------------------------------------------------------
	// Modes.
	// ---------------------------------------------------------------

	/**
	 * Legacy mode imports replies as before.
	 */
	public function test_legacy_mode_imports_replies_as_before() {
		$this->set_mode( 'legacy' );

		$this->run_backfeed();

		$this->assertCount( 1, $this->comments() );
	}

	/**
	 * Webmention mode disables the legacy importer.
	 */
	public function test_webmention_mode_disables_the_legacy_importer() {
		$this->set_mode( 'webmention' );

		$this->run_backfeed();

		$this->assertCount( 0, $this->comments() );
	}

	/**
	 * Disabled mode imports nothing.
	 */
	public function test_disabled_mode_imports_nothing() {
		$this->set_mode( 'disabled' );

		$this->run_backfeed();

		$this->assertCount( 0, $this->comments() );
	}

	/**
	 * Switching back to legacy restores the importer.
	 */
	public function test_switching_back_to_legacy_restores_the_importer() {
		$this->set_mode( 'webmention' );
		$this->run_backfeed();
		$this->assertCount( 0, $this->comments(), 'precondition' );

		$this->set_mode( 'legacy' );
		$this->run_backfeed();

		$this->assertCount( 1, $this->comments() );
	}

	/**
	 * Existing legacy comments survive a mode switch.
	 */
	public function test_existing_legacy_comments_survive_a_mode_switch() {
		$this->set_mode( 'legacy' );
		$this->run_backfeed();
		$comment_id = $this->comments()[0]->comment_ID;

		$this->set_mode( 'webmention' );

		$this->assertNotNull( \get_comment( $comment_id ) );
		$this->assertSame( 'rss.chat', \get_comment_meta( $comment_id, 'protocol', true ) );
	}

	// ---------------------------------------------------------------
	// One remote reply, one comment — across modes.
	// ---------------------------------------------------------------

	/**
	 * A webmention for a legacy imported reply is rejected.
	 */
	public function test_a_webmention_for_a_legacy_imported_reply_is_rejected() {
		$this->set_mode( 'legacy' );
		$this->run_backfeed();
		$this->assertCount( 1, $this->comments(), 'precondition: legacy import done' );

		$this->set_mode( 'webmention' );

		$commentdata = array(
			'comment_post_ID' => $this->synced_post,
			'source'          => 'https://rss.chat/?id=778',
			'target'          => \get_permalink( $this->synced_post ),
			'comment_meta'    => array(
				'protocol'              => 'webmention',
				'webmention_source_url' => 'https://rss.chat/?id=778',
			),
		);

		$result = \apply_filters( 'webmention_comment_data', $commentdata );

		$this->assertWPError( $result );
		$this->assertCount( 1, $this->comments(), 'still exactly one comment' );
	}

	/**
	 * The item-page form of an rss.chat source matches the stored guid form.
	 *
	 * The server's webmention source is /item?id=N while legacy comments
	 * store the guid /?id=N — a mode switch must not let the same reply in
	 * twice through the spelling difference.
	 */
	public function test_an_item_page_source_matches_a_legacy_guid() {
		$this->set_mode( 'legacy' );
		$this->run_backfeed();
		$this->assertCount( 1, $this->comments(), 'precondition: legacy import done' );

		$this->set_mode( 'webmention' );

		$commentdata = array(
			'comment_post_ID' => $this->synced_post,
			'source'          => 'https://rss.chat/item?id=778',
			'target'          => \get_permalink( $this->synced_post ),
			'comment_meta'    => array(
				'protocol'              => 'webmention',
				'webmention_source_url' => 'https://rss.chat/item?id=778',
			),
		);

		$result = \apply_filters( 'webmention_comment_data', $commentdata );

		$this->assertWPError( $result );
	}

	/**
	 * An unrelated webmention passes through.
	 */
	public function test_an_unrelated_webmention_passes_through() {
		$this->set_mode( 'webmention' );

		$commentdata = array(
			'comment_post_ID' => $this->synced_post,
			'source'          => 'https://elsewhere.example/reply/9',
			'target'          => \get_permalink( $this->synced_post ),
			'comment_meta'    => array(
				'protocol'              => 'webmention',
				'webmention_source_url' => 'https://elsewhere.example/reply/9',
			),
		);

		$result = \apply_filters( 'webmention_comment_data', $commentdata );

		$this->assertSame( $commentdata, $result );
	}

	/**
	 * A webmention update of a webmention comment passes through.
	 */
	public function test_a_webmention_update_of_a_webmention_comment_passes_through() {
		$this->set_mode( 'webmention' );

		// The Webmention plugin's own dedup already matched this comment.
		$commentdata = array(
			'comment_post_ID' => $this->synced_post,
			'comment_ID'      => 123,
			'source'          => 'https://rss.chat/?id=778',
			'comment_meta'    => array(
				'protocol'              => 'webmention',
				'webmention_source_url' => 'https://rss.chat/?id=778',
			),
		);

		$result = \apply_filters( 'webmention_comment_data', $commentdata );

		$this->assertSame( $commentdata, $result );
	}

	// ---------------------------------------------------------------
	// Honest status.
	// ---------------------------------------------------------------

	/**
	 * Webmention mode status reports the missing pieces.
	 */
	public function test_webmention_mode_status_reports_the_missing_pieces() {
		$this->set_mode( 'webmention' );

		$status = Reply_Import::status();

		$this->assertSame( 'webmention', $status['mode'] );
		// The Webmention plugin is not loaded in this suite, and no released
		// rss.chat server sends Webmentions yet — both must be reported, not
		// papered over.
		$this->assertFalse( $status['webmention_plugin_active'] );
		$this->assertFalse( $status['server_support_confirmed'] );
	}
}
