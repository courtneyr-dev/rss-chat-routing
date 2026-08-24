<?php
/**
 * Routing enforced through the real parent plugin, on every publish path.
 *
 * @package RSS_Chat_Routing
 * @group rss-chat-routing
 */

namespace RSS_Chat_Routing\Tests;

use WP_UnitTestCase;
use RSS_Chat\Plugin;
use RSS_Chat_Routing\Rules;

/**
 * End-to-end: which posts the parent actually pushed, and with what payload.
 */
class Test_Syndication_Paths extends WP_UnitTestCase {

	/**
	 * Decoded jsontext payloads of captured /newpost calls.
	 *
	 * @var array[]
	 */
	private $payloads = array();

	/**
	 * Connect, register a kind taxonomy, stub the network.
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

		\register_taxonomy( 'kind', 'post', array( 'public' => true ) );

		// The WP test framework unregisters dynamically-registered meta
		// between tests; without this, REST meta writes silently no-op from
		// the second test in the process onward.
		Rules::register_meta();
	}

	/**
	 * Reset.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 100 );
		\delete_option( Rules::OPTION );
		Plugin::clear_account();
		\unregister_taxonomy( 'kind' );
		parent::tear_down();
	}

	/**
	 * Capture /newpost payloads, answer with a synthetic item.
	 *
	 * @param mixed  $response Short-circuit value.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return array|mixed
	 */
	public function stub_http( $response, $args, $url ) {
		if ( false !== $response ) {
			// Already answered earlier in the chain (the link shim re-issues
			// requests); don't double-count.
			return $response;
		}
		if ( false === \strpos( $url, '/newpost' ) ) {
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
					'id'   => 4242,
					'guid' => 'https://rss.chat/?id=4242',
				)
			),
		);
	}

	/**
	 * Publish through the classic/programmatic path.
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

	// ---------------------------------------------------------------
	// The primary example: statuses default on.
	// ---------------------------------------------------------------

	/**
	 * Status default sends a status post.
	 */
	public function test_status_default_sends_a_status_post() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$post_id = $this->publish( array( 'format' => 'status' ) );

		$this->assertCount( 1, $this->payloads );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
	}

	/**
	 * Status default lets one status post opt out.
	 */
	public function test_status_default_lets_one_status_post_opt_out() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$this->publish(
			array(
				'format'   => 'status',
				'override' => 'exclude',
			)
		);

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * Status default blocks a native chat push.
	 */
	public function test_status_default_blocks_a_native_chat_push() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$this->publish( array( 'format' => 'chat' ) );

		$this->assertCount( 0, $this->payloads );
	}

	/**
	 * Nonmatching post needs the explicit include.
	 */
	public function test_nonmatching_post_needs_the_explicit_include() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$this->publish( array( 'override' => 'include' ) );

		$this->assertCount( 1, $this->payloads );
	}

	// ---------------------------------------------------------------
	// The pushed item carries the canonical permalink.
	// ---------------------------------------------------------------

	/**
	 * Pushed item carries the permalink.
	 */
	public function test_pushed_item_carries_the_permalink() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$post_id = $this->publish( array( 'format' => 'status' ) );

		$this->assertCount( 1, $this->payloads );
		$this->assertSame( \get_permalink( $post_id ), $this->payloads[0]['link'] ?? null );
	}

	/**
	 * A stock chat push also carries the permalink.
	 */
	public function test_a_stock_chat_push_also_carries_the_permalink() {
		// Stock configuration: chat is the default format, nothing routed.
		$post_id = $this->publish( array( 'format' => 'chat' ) );

		$this->assertCount( 1, $this->payloads );
		$this->assertSame( \get_permalink( $post_id ), $this->payloads[0]['link'] ?? null );
	}

	// ---------------------------------------------------------------
	// XFN compatibility: routing never rewrites post content.
	// ---------------------------------------------------------------

	/**
	 * XFN rel attributes in content survive the payload untouched.
	 */
	public function test_xfn_rel_markup_survives_the_pushed_payload() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Congrats <a href="https://friend.example/" rel="friend met">Alex</a>!',
			)
		);
		\set_post_format( $post_id, 'status' );
		\wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertCount( 1, $this->payloads );
		$this->assertStringContainsString( 'rel="friend met"', $this->payloads[0]['description'] );
	}

	// ---------------------------------------------------------------
	// Micropub path: format and kind land after wp_after_insert_post.
	// ---------------------------------------------------------------

	/**
	 * Micropub created post is pushed once its format arrives.
	 */
	public function test_micropub_created_post_is_pushed_once_its_format_arrives() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		// The Micropub plugin inserts a plain published post first.
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => 'From a Micropub client.',
			)
		);
		$this->assertCount( 0, $this->payloads, 'precondition: nothing pushed at insert time' );

		// …then bridges set the format, then after_micropub fires.
		\set_post_format( $post_id, 'status' );
		\do_action( 'after_micropub', array( 'properties' => array() ), array( 'ID' => $post_id ) );

		$this->assertCount( 1, $this->payloads );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ) );
	}

	/**
	 * Micropub repush is idempotent.
	 */
	public function test_micropub_repush_is_idempotent() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\set_post_format( $post_id, 'status' );

		\do_action( 'after_micropub', array( 'properties' => array() ), array( 'ID' => $post_id ) );
		\do_action( 'after_micropub', array( 'properties' => array() ), array( 'ID' => $post_id ) );

		$this->assertCount( 1, $this->payloads );
	}

	/**
	 * Micropub nonmatching post stays home.
	 */
	public function test_micropub_nonmatching_post_stays_home() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\do_action( 'after_micropub', array( 'properties' => array() ), array( 'ID' => $post_id ) );

		$this->assertCount( 0, $this->payloads );
	}

	// ---------------------------------------------------------------
	// Micropub property → per-post override.
	// ---------------------------------------------------------------

	/**
	 * Micropub property sets the override.
	 */
	public function test_micropub_property_sets_the_override() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\do_action(
			'after_micropub',
			array( 'properties' => array( 'mp-rss-chat-routing' => array( 'exclude' ) ) ),
			array( 'ID' => $post_id )
		);

		$this->assertSame( 'exclude', Rules::override( $post_id ) );
	}

	/**
	 * Micropub property include pushes a plain post.
	 */
	public function test_micropub_property_include_pushes_a_plain_post() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\do_action(
			'after_micropub',
			array( 'properties' => array( 'mp-rss-chat-routing' => array( 'include' ) ) ),
			array( 'ID' => $post_id )
		);

		$this->assertCount( 1, $this->payloads );
	}

	/**
	 * Micropub invalid value writes nothing.
	 */
	public function test_micropub_invalid_value_writes_nothing() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\do_action(
			'after_micropub',
			array( 'properties' => array( 'mp-rss-chat-routing' => array( 'yes-please' ) ) ),
			array( 'ID' => $post_id )
		);

		$this->assertSame( '', (string) \get_post_meta( $post_id, Rules::META_KEY, true ) );
	}

	/**
	 * Micropub absent property preserves an earlier choice.
	 */
	public function test_micropub_absent_property_preserves_an_earlier_choice() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		\update_post_meta( $post_id, Rules::META_KEY, 'exclude' );

		// A later Micropub update without the property must not reset it.
		\do_action( 'after_micropub', array( 'properties' => array() ), array( 'ID' => $post_id ) );

		$this->assertSame( 'exclude', Rules::override( $post_id ) );
	}

	// ---------------------------------------------------------------
	// REST rejects invalid override values instead of coercing them.
	// ---------------------------------------------------------------

	/**
	 * Rest rejects an invalid override value.
	 */
	public function test_rest_rejects_an_invalid_override_value() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( Rules::META_KEY => 'yes-please' ) ) );

		$response = \rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( '', (string) \get_post_meta( $post_id, Rules::META_KEY, true ) );
	}

	/**
	 * Rest accepts the named override values.
	 */
	public function test_rest_accepts_the_named_override_values() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $user_id );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_body_params( array( 'meta' => array( Rules::META_KEY => 'exclude' ) ) );

		$response = \rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'exclude', Rules::override( $post_id ) );
	}

	// ---------------------------------------------------------------
	// Excluding after syndication: nothing new happens, nothing is deleted.
	// ---------------------------------------------------------------

	/**
	 * Excluding a synced post neither repushes nor deletes.
	 */
	public function test_excluding_a_synced_post_neither_repushes_nor_deletes() {
		\update_option( Rules::OPTION, array( 'default_format' => 'status' ) );

		$post_id = $this->publish( array( 'format' => 'status' ) );
		$this->assertCount( 1, $this->payloads, 'precondition: synced' );

		\update_post_meta( $post_id, Rules::META_KEY, 'exclude' );
		\wp_update_post( array( 'ID' => $post_id ) ); // Re-save.

		$this->assertCount( 1, $this->payloads, 'no second /newpost' );
		$this->assertSame( 4242, (int) \get_post_meta( $post_id, Plugin::META_ID, true ), 'mapping intact' );
	}
}
