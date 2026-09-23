<?php
/**
 * Cross-network isolation: inbound interactions never echo out to rss.chat.
 *
 * @package RSS_Chat_Routing
 * @group rss-chat-routing
 */

namespace RSS_Chat_Routing\Tests;

use WP_UnitTestCase;
use RSS_Chat\Backfeed;
use RSS_Chat\Plugin;
use RSS_Chat_Routing\Rules;

/**
 * Which comments the parent actually pushed as replies.
 */
class Test_Comment_Gate extends WP_UnitTestCase {

	/**
	 * Decoded jsontext payloads of captured /newpost calls.
	 *
	 * @var array[]
	 */
	private $payloads = array();

	/**
	 * A post that is already synced to rss.chat.
	 *
	 * @var int
	 */
	private $synced_post;

	/**
	 * Replies queued for the next stubbed Backfeed::run().
	 *
	 * @var array[]
	 */
	private $backfeed_items = array();

	/**
	 * Like screennames queued for the next stubbed Backfeed::run().
	 *
	 * @var string[]
	 */
	private $backfeed_likers = array();

	/**
	 * The rss.chat user record queued for the next stubbed Backfeed::run().
	 *
	 * @var array
	 */
	private $backfeed_user = array();

	/**
	 * Connect, stub the network, prepare a synced post.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->payloads = array();
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
	 * Reset.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 100 );
		\remove_filter( 'pre_http_request', array( $this, 'stub_backfeed_reads' ), 90 );
		\remove_all_filters( 'rss_chat_import_likes' );
		\unregister_meta_key( 'comment', 'protocol' );
		\delete_option( Rules::OPTION );
		\delete_option( Backfeed::OPTION_LOCK );
		Plugin::clear_account();
		Backfeed::$importing = false;
		parent::tear_down();
	}

	/**
	 * Stub the rss.chat read endpoints `Backfeed::run()` calls, for the two
	 * end-to-end tests below. Kept separate from stub_http() above so the
	 * existing /newpost-only stub used by every other test is untouched.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_backfeed_reads( $response, $args, $url ) {
		if ( false !== $response ) {
			return $response;
		}

		if ( false !== \strpos( $url, '/getitemandreplies' ) ) {
			return $this->json_response(
				\array_merge(
					array(
						array(
							'id'      => 777,
							'ctLikes' => \count( $this->backfeed_likers ),
						),
					),
					$this->backfeed_items
				)
			);
		}

		if ( false !== \strpos( $url, '/getlikerslist' ) ) {
			return $this->json_response( $this->backfeed_likers );
		}

		if ( false !== \strpos( $url, '/getuserdata' ) ) {
			return $this->json_response( $this->backfeed_user );
		}

		return $response;
	}

	/**
	 * A fake successful JSON response, shaped like pre_http_request expects.
	 *
	 * @param mixed $data Data to encode as the body.
	 * @return array
	 */
	private function json_response( $data ) {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => (string) \wp_json_encode( $data ),
		);
	}

	/**
	 * Run the parent's cron job directly, past its own lease lock.
	 *
	 * @return void
	 */
	private function run_backfeed() {
		\delete_option( Backfeed::OPTION_LOCK );
		\wp_cache_delete( Backfeed::OPTION_LOCK, 'options' );
		( new Backfeed() )->run();
	}

	/**
	 * Capture /newpost payloads.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== $response || false === \strpos( $url, '/newpost' ) ) {
			return $response;
		}

		$query = array();
		\parse_str( (string) \wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$this->payloads[] = \json_decode( $query['jsontext'] ?? 'null', true );

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array() ),
			'body'     => (string) \wp_json_encode(
				array(
					'id'   => 9001,
					'guid' => 'https://rss.chat/?id=9001',
				)
			),
		);
	}

	/**
	 * Insert an approved comment on the synced post.
	 *
	 * @param array $overrides wp_insert_comment fields.
	 * @return int Comment id.
	 */
	private function insert_comment( $overrides = array() ) {
		return (int) \wp_insert_comment(
			\array_merge(
				array(
					'comment_post_ID'  => $this->synced_post,
					'comment_content'  => 'A reply.',
					'comment_approved' => 1,
					'comment_type'     => 'comment',
					// Upstream pushes only comments written by a user of the site.
					'user_id'          => self::factory()->user->create(),
				),
				$overrides
			)
		);
	}

	/**
	 * A local comment is still pushed.
	 */
	public function test_a_local_comment_is_still_pushed() {
		$this->insert_comment();

		$this->assertCount( 1, $this->payloads );
		$this->assertSame( 777, (int) ( $this->payloads[0]['inReplyTo'] ?? 0 ) );
	}

	/**
	 * An inbound webmention is not pushed back.
	 */
	public function test_an_inbound_webmention_is_not_pushed_back() {
		$this->insert_comment(
			array(
				'comment_meta' => array(
					'protocol'              => 'webmention',
					'webmention_source_url' => 'https://elsewhere.example/reply/1',
				),
			)
		);

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * An inbound activitypub comment is not pushed back.
	 */
	public function test_an_inbound_activitypub_comment_is_not_pushed_back() {
		$this->insert_comment(
			array(
				'comment_meta' => array(
					'protocol'   => 'activitypub',
					'source_url' => 'https://fedi.example/@who/1',
				),
			)
		);

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * An atmosphere reaction is not pushed back.
	 */
	public function test_an_atmosphere_reaction_is_not_pushed_back() {
		// ATmosphere writes its protocol meta only after the insert, but
		// stamps its agent string before it — the gate must catch the agent.
		$this->insert_comment( array( 'comment_agent' => 'ATmosphere/2.1.0; reaction-sync' ) );

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * A webmention typed comment is not pushed back.
	 */
	public function test_a_webmention_typed_comment_is_not_pushed_back() {
		$this->insert_comment( array( 'comment_type' => 'webmention' ) );

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * A pingback is not pushed back.
	 */
	public function test_a_pingback_is_not_pushed_back() {
		$this->insert_comment( array( 'comment_type' => 'pingback' ) );

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * An imported rss chat reply is not pushed back.
	 */
	public function test_an_imported_rss_chat_reply_is_not_pushed_back() {
		$this->insert_comment(
			array(
				'comment_meta' => array(
					'protocol'        => 'rss.chat',
					Plugin::META_GUID => 'https://rss.chat/?id=555',
				),
			)
		);

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * An imported rss chat reply stored with the sanitized protocol is not pushed back.
	 *
	 * With the Webmention plugin active, `protocol` meta runs through
	 * sanitize_key and 'rss.chat' lands as 'rsschat'; the gate must still
	 * treat it as foreign.
	 */
	public function test_an_imported_reply_with_the_sanitized_protocol_is_not_pushed_back() {
		\register_meta(
			'comment',
			'protocol',
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_key',
			)
		);

		$comment_id = $this->insert_comment(
			array(
				'comment_meta' => array(
					'protocol'        => Plugin::PROTOCOL,
					Plugin::META_GUID => 'https://rss.chat/?id=556',
				),
			)
		);

		$this->assertSame( 'rsschat', \get_comment_meta( $comment_id, 'protocol', true ), 'precondition: sanitized on write' );
		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * The gate leaves no filter behind.
	 */
	public function test_the_gate_leaves_no_filter_behind() {
		$this->insert_comment(
			array(
				'comment_meta' => array( 'protocol' => 'webmention' ),
			)
		);

		// A later, ordinary comment still pushes: the stand-in was removed.
		$this->insert_comment();

		$this->assertCount( 1, $this->payloads );
	}

	/**
	 * A row inserted while Backfeed::$importing is set is untrusted remote
	 * input: the gate must sanitize it and hold it for moderation.
	 */
	public function test_a_backfeed_import_is_sanitized_and_held_for_moderation() {
		Backfeed::$importing = true;

		$comment_id = (int) \wp_insert_comment(
			array(
				'comment_post_ID'    => $this->synced_post,
				'comment_content'    => 'hi <script>x</script>',
				'comment_author'     => '<b>a</b>',
				'comment_author_url' => 'javascript:1',
				'comment_approved'   => 1,
				'comment_type'       => 'comment',
			)
		);

		Backfeed::$importing = false;

		$comment = \get_comment( $comment_id );

		$this->assertStringNotContainsString( '<script', $comment->comment_content );
		$this->assertSame( 'a', $comment->comment_author );
		$this->assertSame( '', $comment->comment_author_url );
		$this->assertSame( '0', $comment->comment_approved );
	}

	/**
	 * With Backfeed::$importing off, an ordinary comment is left exactly as
	 * inserted: this gate only touches rows flagged as backfeed imports.
	 */
	public function test_an_ordinary_insert_is_left_unchanged_when_the_flag_is_off() {
		$comment_id = (int) \wp_insert_comment(
			array(
				'comment_post_ID'    => $this->synced_post,
				'comment_content'    => 'hi <script>x</script>',
				'comment_author'     => '<b>a</b>',
				'comment_author_url' => 'javascript:1',
				'comment_approved'   => 1,
				'comment_type'       => 'comment',
			)
		);

		$comment = \get_comment( $comment_id );

		$this->assertSame( 'hi <script>x</script>', $comment->comment_content );
		$this->assertSame( '<b>a</b>', $comment->comment_author );
		$this->assertSame( 'javascript:1', $comment->comment_author_url );
		$this->assertSame( '1', $comment->comment_approved );
	}

	/**
	 * A valid http(s) author URL is not blanked: only the scheme and host
	 * are checked, nothing is fetched.
	 */
	public function test_a_backfeed_import_keeps_a_valid_author_url() {
		Backfeed::$importing = true;

		$comment_id = (int) \wp_insert_comment(
			array(
				'comment_post_ID'    => $this->synced_post,
				'comment_content'    => 'hi',
				'comment_author'     => 'a',
				'comment_author_url' => 'https://example.org/u',
				'comment_approved'   => 1,
				'comment_type'       => 'comment',
			)
		);

		Backfeed::$importing = false;

		$this->assertSame( 'https://example.org/u', \get_comment( $comment_id )->comment_author_url );
	}

	/**
	 * An author name long enough that WordPress's own comment filters grow
	 * it past the column limit on the sanitizing update must not leave the
	 * unsanitized `<script>` content on the post either way: whether the
	 * update fails over to the empty-value fallback or succeeds outright,
	 * no comment stored against the post may carry the raw script tag.
	 */
	public function test_an_overlong_author_import_leaves_no_script_on_the_post() {
		Backfeed::$importing = true;

		\wp_insert_comment(
			array(
				'comment_post_ID'  => $this->synced_post,
				'comment_content'  => 'hi <script>x</script>',
				'comment_author'   => \str_repeat( '&', 60 ),
				'comment_approved' => 1,
				'comment_type'     => 'comment',
			)
		);

		Backfeed::$importing = false;

		$stored_comments = \get_comments(
			array(
				'post_id' => $this->synced_post,
				'status'  => 'any',
			)
		);

		foreach ( $stored_comments as $stored ) {
			$this->assertStringNotContainsString( '<script', $stored->comment_content );
		}
	}

	/**
	 * A wp_update_comment_data veto fails both the sanitizing update and the
	 * empty-value fallback update (the veto does not look at content), so
	 * the row must be deleted outright rather than left holding the raw
	 * import.
	 */
	public function test_a_wp_update_comment_data_veto_deletes_the_row() {
		$veto = function () {
			return new \WP_Error( 'veto', 'no' );
		};
		\add_filter( 'wp_update_comment_data', $veto );

		Backfeed::$importing = true;

		$comment_id = (int) \wp_insert_comment(
			array(
				'comment_post_ID'  => $this->synced_post,
				'comment_content'  => 'hi <script>x</script>',
				'comment_author'   => 'a',
				'comment_approved' => 1,
				'comment_type'     => 'comment',
			)
		);

		Backfeed::$importing = false;

		\remove_filter( 'wp_update_comment_data', $veto );

		$this->assertNull( \get_comment( $comment_id ) );
	}

	/**
	 * Driving the parent's real Backfeed::run() end to end (not a direct
	 * wp_insert_comment call) still lands a sanitized, held reply.
	 */
	public function test_backfeed_run_sanitizes_and_holds_an_imported_reply() {
		\add_filter( 'pre_http_request', array( $this, 'stub_backfeed_reads' ), 90, 3 );

		$this->backfeed_items = array(
			array(
				'id'           => 1001,
				'guid'         => 'https://rss.chat/?id=1001',
				'markdowntext' => 'hi <script>x</script>',
				'author'       => '<b>a</b>',
				'feedLink'     => 'javascript:1',
			),
		);

		$this->run_backfeed();

		$ids = \get_comments(
			array(
				'post_id'    => $this->synced_post,
				'status'     => 'any',
				'meta_key'   => Plugin::META_GUID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'https://rss.chat/?id=1001', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ids',
			)
		);

		$this->assertCount( 1, $ids );

		$comment = \get_comment( $ids[0] );
		$this->assertStringNotContainsString( '<script', $comment->comment_content );
		$this->assertSame( 'a', $comment->comment_author );
		$this->assertSame( '', $comment->comment_author_url );
		$this->assertSame( '0', $comment->comment_approved );
	}

	/**
	 * The like path is sanitized and held the same way as a reply, and a
	 * second run does not store a duplicate.
	 */
	public function test_backfeed_run_sanitizes_and_holds_a_like_without_duplicating_it() {
		\add_filter( 'rss_chat_import_likes', '__return_true' );
		\add_filter( 'pre_http_request', array( $this, 'stub_backfeed_reads' ), 90, 3 );

		$this->backfeed_likers = array( 'bob' );
		$this->backfeed_user   = array( 'feedUrl' => 'javascript:alert(1)' );

		$this->run_backfeed();
		$this->run_backfeed();

		$likes = \get_comments(
			array(
				'post_id' => $this->synced_post,
				'status'  => 'any',
				'type'    => 'like',
			)
		);

		$this->assertCount( 1, $likes );
		$this->assertSame( '0', $likes[0]->comment_approved );
		$this->assertSame( '', $likes[0]->comment_author_url );
	}
}
