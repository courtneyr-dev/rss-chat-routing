<?php
/**
 * The sitewide send mode: opt in, opt out, rules, or ask on every post.
 *
 * @package RSS_Chat_Routing
 * @group rss-chat-routing
 */

namespace RSS_Chat_Routing\Tests;

use WP_UnitTestCase;
use RSS_Chat_Routing\Rules;
use RSS_Chat_Routing\Editor;
use RSS_Chat_Routing\Settings;

/**
 * Send-mode tests. No HTTP: these exercise the decision and settings alone.
 */
class Test_Send_Mode extends WP_UnitTestCase {

	/**
	 * Register a stand-in kind taxonomy.
	 */
	public function set_up(): void {
		parent::set_up();

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
		\delete_option( Rules::OPTION );
		\unregister_taxonomy( 'kind' );
		parent::tear_down();
	}

	/**
	 * A published post with optional format, kind, and override.
	 *
	 * @param array $args format, kind, override.
	 * @return \WP_Post
	 */
	private function make_post( $args = array() ) {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Hello',
				'post_content' => 'Body.',
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

		return \get_post( $post_id );
	}

	/**
	 * Store settings through the same sanitizer the screen uses.
	 *
	 * @param array $settings Submitted settings.
	 */
	private function save_screen( array $settings ) {
		\update_option( Rules::OPTION, Rules::sanitize( $settings ) );
	}

	// ---------------------------------------------------------------
	// Defaults and storage.
	// ---------------------------------------------------------------

	/**
	 * A fresh install decides by rules, which is stock RSS Chat behavior.
	 */
	public function test_fresh_install_decides_by_rules() {
		$this->assertSame( 'rules', Rules::settings()['send_mode'] );
	}

	/**
	 * Each mode survives a save.
	 */
	public function test_each_mode_round_trips_through_the_screen() {
		foreach ( Rules::SEND_MODES as $mode ) {
			$this->save_screen( array( 'send_mode' => $mode ) );
			$this->assertSame( $mode, Rules::settings()['send_mode'], $mode );
		}
	}

	/**
	 * An unknown mode falls back to rules.
	 */
	public function test_sanitize_rejects_an_unknown_mode() {
		$clean = Rules::sanitize( array( 'send_mode' => 'everything' ) );

		$this->assertSame( 'rules', $clean['send_mode'] );
	}

	/**
	 * A stored 0.2.x option with no send_mode decides by rules, unchanged.
	 */
	public function test_a_020_option_without_send_mode_reads_as_rules() {
		\update_option(
			Rules::OPTION,
			array(
				'default_format' => 'status',
				'default_kind'   => '',
				'reply_import'   => 'legacy',
				'legacy_all'     => false,
			)
		);

		$this->assertSame( 'rules', Rules::settings()['send_mode'] );
	}

	/**
	 * The retired "send every post" mode reads as opt out.
	 */
	public function test_legacy_all_reads_as_opt_out() {
		\update_option( Rules::OPTION, array( 'mode' => 'all' ) );

		$settings = Rules::settings();

		$this->assertSame( 'opt_out', $settings['send_mode'] );
		$this->assertTrue( $settings['legacy_all'] );
	}

	/**
	 * Saving opt out from a legacy-all install keeps sending everything and
	 * retires the legacy flag.
	 */
	public function test_saving_opt_out_from_legacy_all_keeps_sending() {
		\update_option( Rules::OPTION, array( 'mode' => 'all' ) );
		$this->save_screen( array( 'send_mode' => 'opt_out' ) );

		$settings = Rules::settings();
		$decision = Rules::decision( $this->make_post() );

		$this->assertFalse( $settings['legacy_all'] );
		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'opt-out-default', $decision['reason'] );
	}

	/**
	 * A programmatic legacy_all round-trip with no mode lands on opt out.
	 */
	public function test_sanitize_maps_legacy_all_without_mode_to_opt_out() {
		$clean = Rules::sanitize( array( 'legacy_all' => true ) );

		$this->assertSame( 'opt_out', $clean['send_mode'] );
	}

	// ---------------------------------------------------------------
	// Opt in.
	// ---------------------------------------------------------------

	/**
	 * Opt in sends nothing by default, even a post matching the rules.
	 */
	public function test_opt_in_ignores_a_matching_format() {
		$this->save_screen(
			array(
				'send_mode'      => 'opt_in',
				'default_format' => 'status',
			)
		);

		$decision = Rules::decision( $this->make_post( array( 'format' => 'status' ) ) );

		$this->assertFalse( $decision['send'] );
		$this->assertSame( 'opt-in-default', $decision['reason'] );
	}

	/**
	 * Opt in sends a post the author included.
	 */
	public function test_opt_in_sends_an_included_post() {
		$this->save_screen( array( 'send_mode' => 'opt_in' ) );

		$decision = Rules::decision( $this->make_post( array( 'override' => 'include' ) ) );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'override-include', $decision['reason'] );
	}

	// ---------------------------------------------------------------
	// Opt out.
	// ---------------------------------------------------------------

	/**
	 * Opt out sends a post that matches no rule.
	 */
	public function test_opt_out_sends_a_plain_post() {
		$this->save_screen( array( 'send_mode' => 'opt_out' ) );

		$decision = Rules::decision( $this->make_post( array( 'format' => 'aside' ) ) );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'opt-out-default', $decision['reason'] );
	}

	/**
	 * Opt out holds back an excluded post.
	 */
	public function test_opt_out_respects_exclude() {
		$this->save_screen( array( 'send_mode' => 'opt_out' ) );

		$decision = Rules::decision( $this->make_post( array( 'override' => 'exclude' ) ) );

		$this->assertFalse( $decision['send'] );
		$this->assertSame( 'override-exclude', $decision['reason'] );
	}

	/**
	 * Opt out still never sends ineligible content.
	 */
	public function test_opt_out_never_sends_a_password_protected_post() {
		$this->save_screen( array( 'send_mode' => 'opt_out' ) );

		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$decision = Rules::decision( \get_post( $post_id ) );

		$this->assertFalse( $decision['send'] );
		$this->assertSame( 'ineligible', $decision['reason'] );
	}

	// ---------------------------------------------------------------
	// Rules.
	// ---------------------------------------------------------------

	/**
	 * Rules mode keeps the format and kind defaults.
	 */
	public function test_rules_mode_sends_a_matching_kind() {
		\wp_insert_term( 'Note', 'kind', array( 'slug' => 'note' ) );
		$this->save_screen(
			array(
				'send_mode'    => 'rules',
				'default_kind' => 'note',
			)
		);

		$decision = Rules::decision( $this->make_post( array( 'kind' => 'note' ) ) );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'kind-default', $decision['reason'] );
	}

	// ---------------------------------------------------------------
	// Ask on every post.
	// ---------------------------------------------------------------

	/**
	 * An unset post is held until the author chooses, even if it matches.
	 */
	public function test_per_post_holds_an_unset_post() {
		$this->save_screen(
			array(
				'send_mode'      => 'per_post',
				'default_format' => 'status',
			)
		);

		$decision = Rules::decision( $this->make_post( array( 'format' => 'status' ) ) );

		$this->assertFalse( $decision['send'] );
		$this->assertSame( 'needs-choice', $decision['reason'] );
	}

	/**
	 * A chosen post follows the choice.
	 */
	public function test_per_post_follows_the_choice() {
		$this->save_screen( array( 'send_mode' => 'per_post' ) );

		$this->assertTrue( Rules::should_send( $this->make_post( array( 'override' => 'include' ) ) ) );
		$this->assertFalse( Rules::should_send( $this->make_post( array( 'override' => 'exclude' ) ) ) );
	}

	// ---------------------------------------------------------------
	// Settings screen.
	// ---------------------------------------------------------------

	/**
	 * The screen offers all four modes and checks the stored one; a
	 * legacy-all install shows opt out selected so saving keeps sending.
	 */
	public function test_screen_renders_four_modes_with_the_current_one_checked() {
		\wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		\update_option( Rules::OPTION, array( 'mode' => 'all' ) );

		\ob_start();
		Settings::render();
		$html = (string) \ob_get_clean();

		foreach ( Rules::SEND_MODES as $mode ) {
			$this->assertStringContainsString( 'value="' . $mode . '"', $html, $mode );
		}
		$this->assertMatchesRegularExpression( '/value="opt_out"\s+checked=\'checked\'/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="rules"\s+checked=\'checked\'/', $html );
	}

	// ---------------------------------------------------------------
	// Editor data.
	// ---------------------------------------------------------------

	/**
	 * The editor panel learns the mode so it can explain the outcome.
	 */
	public function test_panel_data_carries_the_send_mode() {
		$this->save_screen( array( 'send_mode' => 'per_post' ) );

		$this->assertSame( 'per_post', Editor::panel_data()['sendMode'] );
	}
}
