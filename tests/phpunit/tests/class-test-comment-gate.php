<?php
/**
 * Cross-network isolation: inbound interactions never echo out to rss.chat.
 *
 * @package RSS_Chat_Routing
 * @group rss-chat-routing
 */

namespace RSS_Chat_Routing\Tests;

use WP_UnitTestCase;
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
		\delete_option( Rules::OPTION );
		Plugin::clear_account();
		parent::tear_down();
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
}
