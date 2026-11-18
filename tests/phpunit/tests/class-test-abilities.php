<?php
/**
 * RSS Chat Routing Abilities API tests.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

/**
 * The route abilities are registered, gated, schema-valid, and side-effect safe.
 */
class Test_Abilities extends \WP_UnitTestCase {

	/**
	 * Number of attempted HTTP requests in the current test.
	 *
	 * @var int
	 */
	private $http_requests = 0;

	/**
	 * Reset ability state and block live HTTP.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->http_requests = 0;
		\delete_option( Abilities::OPTION_SET_ROUTE );
		\delete_option( Rules::OPTION );
		\delete_option( 'rss_chat_routing_ability_test_option' );
		\delete_transient( 'rss_chat_routing_ability_test_transient' );
		\wp_set_current_user( 0 );
		\add_filter( 'pre_http_request', array( $this, 'block_http' ), 1, 3 );
	}

	/**
	 * Remove the HTTP guard and test state.
	 */
	public function tear_down(): void {
		\remove_filter( 'pre_http_request', array( $this, 'block_http' ), 1 );
		\delete_option( Abilities::OPTION_SET_ROUTE );
		\delete_option( Rules::OPTION );
		\delete_option( 'rss_chat_routing_ability_test_option' );
		\delete_transient( 'rss_chat_routing_ability_test_transient' );
		\wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Reject any HTTP request and record the attempt.
	 *
	 * @return \WP_Error
	 */
	public function block_http() {
		++$this->http_requests;

		return new \WP_Error( 'rss_chat_routing_test_http_blocked', 'HTTP is blocked in ability tests.' );
	}

	/**
	 * Both abilities and their category register with the frozen metadata.
	 */
	public function test_registers_category_and_two_abilities() {
		$category = \wp_get_ability_category( Abilities::CATEGORY );
		$this->assertInstanceOf( '\WP_Ability_Category', $category );
		$this->assertSame( 'RSS Chat Routing', $category->get_label() );

		$abilities = \array_values(
			\array_filter(
				\wp_get_abilities(),
				static function ( $ability ) {
					return 0 === \strpos( $ability->get_name(), 'rss-chat-routing/' );
				}
			)
		);

		$this->assertCount( 2, $abilities );
		$this->assertSame(
			array( 'rss-chat-routing/get-route', 'rss-chat-routing/set-route' ),
			\array_map(
				static function ( $ability ) {
					return $ability->get_name();
				},
				$abilities
			)
		);

		$get = \wp_get_ability( 'rss-chat-routing/get-route' );
		$set = \wp_get_ability( 'rss-chat-routing/set-route' );

		$this->assertSame( Abilities::CATEGORY, $get->get_category() );
		$this->assertSame( Abilities::CATEGORY, $set->get_category() );
		$expected_get = array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
		);
		$expected_set = array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => false ),
		);

		// WordPress 7.1 normalizes every ability's meta with a top-level
		// `public` flag (default false; it only seeds `show_in_rest`, which
		// these abilities set explicitly). Earlier versions don't add it.
		if ( \array_key_exists( 'public', $get->get_meta() ) ) {
			$expected_get['public'] = false;
			$expected_set['public'] = false;
		}

		$this->assertSame( $expected_get, $get->get_meta() );
		$this->assertSame( $expected_set, $set->get_meta() );
	}

	/**
	 * The switch uses the plugin settings group and remains private from REST.
	 */
	public function test_registers_set_route_switch_default_off() {
		$settings = \get_registered_settings();

		$this->assertArrayHasKey( Abilities::OPTION_SET_ROUTE, $settings );
		$this->assertSame( 'boolean', $settings[ Abilities::OPTION_SET_ROUTE ]['type'] );
		$this->assertSame( 'rest_sanitize_boolean', $settings[ Abilities::OPTION_SET_ROUTE ]['sanitize_callback'] );
		$this->assertFalse( $settings[ Abilities::OPTION_SET_ROUTE ]['show_in_rest'] );
		$this->assertFalse( $settings[ Abilities::OPTION_SET_ROUTE ]['default'] );
		$this->assertFalse( Abilities::set_route_enabled() );
	}

	/**
	 * Logged-out users and subscribers fail; contributors can edit their own
	 * draft, and administrators can edit it too.
	 */
	public function test_permission_matrix_uses_edit_post_on_an_existing_post() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$post_id     = self::factory()->post->create(
			array(
				'post_author' => $contributor,
				'post_status' => 'draft',
			)
		);
		$input       = array(
			'post_id' => $post_id,
			'route'   => 'include',
		);
		$get         = \wp_get_ability( 'rss-chat-routing/get-route' );
		$set         = \wp_get_ability( 'rss-chat-routing/set-route' );

		\update_option( Abilities::OPTION_SET_ROUTE, true );

		$this->assertFalse( $get->check_permissions( $input ) );
		$this->assertFalse( $set->check_permissions( $input ) );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\wp_set_current_user( $subscriber );
		$this->assertFalse( $get->check_permissions( $input ) );
		$this->assertFalse( $set->check_permissions( $input ) );

		\wp_set_current_user( $contributor );
		$this->assertTrue( $get->check_permissions( $input ) );
		$this->assertTrue( $set->check_permissions( $input ) );

		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $administrator );
		$this->assertTrue( $get->check_permissions( $input ) );
		$this->assertTrue( $set->check_permissions( $input ) );
		$this->assertFalse( $get->check_permissions( array( 'post_id' => 999999 ) ) );
		$this->assertFalse( $set->check_permissions( array( 'post_id' => 999999 ) ) );
	}

	/**
	 * The disabled switch returns the same 403 error from both callbacks before
	 * any state or HTTP side effect occurs.
	 */
	public function test_set_route_off_returns_error_without_side_effects() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id    = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$input         = array(
			'post_id' => $post_id,
			'route'   => 'include',
			'dry_run' => false,
		);

		\wp_set_current_user( $administrator );
		\update_post_meta( $post_id, Rules::META_KEY, 'exclude' );
		\update_option( 'rss_chat_routing_ability_test_option', 'unchanged' );
		\set_transient( 'rss_chat_routing_ability_test_transient', 'unchanged', HOUR_IN_SECONDS );

		$permission = Abilities::can_set_route( $input );
		$result     = Abilities::execute_set_route( $input );

		$this->assertWPError( $permission );
		$this->assertSame( 'rss_chat_routing_ability_disabled', $permission->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $permission->get_error_data() );
		$this->assertWPError( $result );
		$this->assertSame( 'rss_chat_routing_ability_disabled', $result->get_error_code() );
		$this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
		$this->assertSame( 'exclude', Rules::override( $post_id ) );
		$this->assertNotNull( \get_comment( $comment_id ) );
		$this->assertSame( 'unchanged', \get_option( 'rss_chat_routing_ability_test_option' ) );
		$this->assertSame( 'unchanged', \get_transient( 'rss_chat_routing_ability_test_transient' ) );
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * The read ability returns the canonical decision, override, and settings.
	 */
	public function test_get_route_executes_with_schema_valid_output() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\wp_set_current_user( $administrator );
		\update_option(
			Rules::OPTION,
			array(
				'send_mode'      => 'opt_in',
				'default_format' => 'status',
				'default_kind'   => 'note',
				'reply_import'   => 'legacy',
			)
		);
		\update_post_meta( $post_id, Rules::META_KEY, 'include' );

		$result = \wp_get_ability( 'rss-chat-routing/get-route' )->execute( array( 'post_id' => $post_id ) );

		$this->assertSame(
			array(
				'post_id'        => $post_id,
				'send'           => true,
				'reason'         => 'override-include',
				'override'       => 'include',
				'send_mode'      => 'opt_in',
				'default_format' => 'status',
				'default_kind'   => 'note',
			),
			$result
		);
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * The omitted dry_run input defaults to true and leaves the meta unchanged.
	 */
	public function test_set_route_dry_run_leaves_meta_unchanged() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\wp_set_current_user( $administrator );
		\update_option( Abilities::OPTION_SET_ROUTE, true );
		\update_post_meta( $post_id, Rules::META_KEY, 'exclude' );

		$result = \wp_get_ability( 'rss-chat-routing/set-route' )->execute(
			array(
				'post_id' => $post_id,
				'route'   => 'include',
			)
		);

		$this->assertSame( 'exclude', Rules::override( $post_id ) );
		$this->assertSame(
			array(
				'post_id'         => $post_id,
				'dry_run'         => true,
				'written'         => false,
				'override_before' => 'exclude',
				'override_after'  => 'include',
				'send_after'      => false,
				'reason_after'    => 'override-exclude',
			),
			$result
		);
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * An enabled inherit write deletes the stored override without publishing.
	 */
	public function test_set_route_inherit_removes_meta_without_http() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\wp_set_current_user( $administrator );
		\update_option( Abilities::OPTION_SET_ROUTE, true );
		\update_post_meta( $post_id, Rules::META_KEY, 'include' );

		$result = \wp_get_ability( 'rss-chat-routing/set-route' )->execute(
			array(
				'post_id' => $post_id,
				'route'   => 'inherit',
				'dry_run' => false,
			)
		);

		$this->assertSame( '', (string) \get_post_meta( $post_id, Rules::META_KEY, true ) );
		$this->assertSame(
			array(
				'post_id'         => $post_id,
				'dry_run'         => false,
				'written'         => true,
				'override_before' => 'include',
				'override_after'  => '',
				'send_after'      => false,
				'reason_after'    => 'no-match',
			),
			$result
		);
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * Core rejects values outside both input schemas before execution.
	 */
	public function test_input_validation_rejects_invalid_values() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\wp_set_current_user( $administrator );
		\update_option( Abilities::OPTION_SET_ROUTE, true );

		$get_result = \wp_get_ability( 'rss-chat-routing/get-route' )->execute( array( 'post_id' => 0 ) );
		$set_result = \wp_get_ability( 'rss-chat-routing/set-route' )->execute(
			array(
				'post_id' => $post_id,
				'route'   => 'later',
			)
		);

		$this->assertWPError( $get_result );
		$this->assertSame( 'ability_invalid_input', $get_result->get_error_code() );
		$this->assertWPError( $set_result );
		$this->assertSame( 'ability_invalid_input', $set_result->get_error_code() );
		$this->assertSame( '', Rules::override( $post_id ) );
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * An enabled include or exclude write stores the meta without publishing.
	 */
	public function test_set_route_exclude_writes_meta_without_http() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		\wp_set_current_user( $administrator );
		\update_option( Abilities::OPTION_SET_ROUTE, true );

		$result = \wp_get_ability( 'rss-chat-routing/set-route' )->execute(
			array(
				'post_id' => $post_id,
				'route'   => 'exclude',
				'dry_run' => false,
			)
		);

		$this->assertSame( 'exclude', \get_post_meta( $post_id, Rules::META_KEY, true ) );
		$this->assertSame(
			array(
				'post_id'         => $post_id,
				'dry_run'         => false,
				'written'         => true,
				'override_before' => '',
				'override_after'  => 'exclude',
				'send_after'      => false,
				'reason_after'    => 'override-exclude',
			),
			$result
		);
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * A string "false" dry_run is read as false, and "true" as a dry run.
	 */
	public function test_set_route_reads_string_dry_run_values() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$set           = \wp_get_ability( 'rss-chat-routing/set-route' );

		\wp_set_current_user( $administrator );
		\update_option( Abilities::OPTION_SET_ROUTE, true );

		$dry = $set->execute(
			array(
				'post_id' => $post_id,
				'route'   => 'include',
				'dry_run' => 'true',
			)
		);
		$this->assertTrue( $dry['dry_run'] );
		$this->assertSame( '', Rules::override( $post_id ) );

		$write = $set->execute(
			array(
				'post_id' => $post_id,
				'route'   => 'include',
				'dry_run' => 'false',
			)
		);
		$this->assertFalse( $write['dry_run'] );
		$this->assertSame( 'include', Rules::override( $post_id ) );
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * Only true, 1 and "1" turn the switch on; other stored values leave it off.
	 */
	public function test_set_route_switch_reads_only_true_values_as_on() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$input         = array(
			'post_id' => $post_id,
			'route'   => 'include',
		);

		\wp_set_current_user( $administrator );

		// Store raw values, as a direct database or WP-CLI write would.
		\remove_all_filters( 'sanitize_option_' . Abilities::OPTION_SET_ROUTE );

		foreach ( array( 'false', 'no', 'off', '0', '', 0, false, array( 'on' ) ) as $value ) {
			\update_option( Abilities::OPTION_SET_ROUTE, $value );

			$this->assertFalse( Abilities::set_route_enabled(), 'Stored value ' . \wp_json_encode( $value ) . ' must read as off.' );
			$error = Abilities::can_set_route( $input );
			$this->assertWPError( $error );
			$this->assertSame( 'rss_chat_routing_ability_disabled', $error->get_error_code() );
		}

		foreach ( array( true, 1, '1' ) as $value ) {
			// Delete first so each write reaches the database and the options cache.
			\delete_option( Abilities::OPTION_SET_ROUTE );
			\update_option( Abilities::OPTION_SET_ROUTE, $value );
			$this->assertTrue( Abilities::set_route_enabled(), 'Stored value ' . \wp_json_encode( $value ) . ' must read as on.' );
		}
	}

	/**
	 * Revisions and pages fail set-route permission but not the read, other
	 * users' posts fail for a contributor, and a denied execute writes nothing.
	 */
	public function test_permission_rejects_non_posts_and_denied_execute_writes_nothing() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$contributor   = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id       = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $administrator,
			)
		);
		$page_id       = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$revision_id   = \wp_save_post_revision( $post_id );
		if ( ! $revision_id ) {
			\wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => 'Changed for a revision.',
				)
			);
			$revision_id = \wp_save_post_revision( $post_id );
		}
		$get = \wp_get_ability( 'rss-chat-routing/get-route' );
		$set = \wp_get_ability( 'rss-chat-routing/set-route' );

		\update_option( Abilities::OPTION_SET_ROUTE, true );
		\wp_set_current_user( $administrator );

		$this->assertGreaterThan( 0, (int) $revision_id );
		foreach ( array( $page_id, (int) $revision_id ) as $id ) {
			$this->assertTrue( $get->check_permissions( array( 'post_id' => $id ) ) );
			$this->assertFalse(
				$set->check_permissions(
					array(
						'post_id' => $id,
						'route'   => 'exclude',
					)
				)
			);
		}

		\wp_set_current_user( $contributor );
		$this->assertFalse( $get->check_permissions( array( 'post_id' => $post_id ) ) );

		\wp_set_current_user( $subscriber );
		$result = $set->execute(
			array(
				'post_id' => $post_id,
				'route'   => 'exclude',
				'dry_run' => false,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( '', Rules::override( $post_id ) );
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * A set-route call missing a required property or with an extra one fails
	 * input validation.
	 */
	public function test_set_route_rejects_missing_and_extra_properties() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$set           = \wp_get_ability( 'rss-chat-routing/set-route' );

		\wp_set_current_user( $administrator );
		\update_option( Abilities::OPTION_SET_ROUTE, true );

		$inputs = array(
			array( 'post_id' => $post_id ),
			array( 'route' => 'include' ),
			array(
				'post_id' => 0,
				'route'   => 'include',
			),
			array(
				'post_id' => $post_id,
				'route'   => 'include',
				'push'    => true,
			),
		);

		foreach ( $inputs as $input ) {
			$result = $set->execute( $input );
			$this->assertWPError( $result );
			$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		}
		$this->assertSame( '', Rules::override( $post_id ) );
		$this->assertSame( 0, $this->http_requests );
	}

	/**
	 * The settings screen renders the named, off-by-default native checkbox.
	 */
	public function test_settings_screen_renders_agent_abilities_checkbox() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		\wp_set_current_user( $administrator );

		\ob_start();
		Settings::render();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( '<h2>Agent abilities</h2>', $html );
		$this->assertStringContainsString( 'name="rss_chat_routing_ability_set_route"', $html );
		$this->assertStringContainsString( 'Let agents change a post&#039;s rss.chat route (include, exclude or follow the site rules).', $html );
		$this->assertDoesNotMatchRegularExpression( '/type="checkbox"[^>]+checked=/', $html );

		\update_option( Abilities::OPTION_SET_ROUTE, true );
		\ob_start();
		Settings::render();
		$html = (string) \ob_get_clean();

		$this->assertMatchesRegularExpression( '/type="checkbox"[^>]+checked=\'checked\'/', $html );
	}
}
