<?php
/**
 * The canonical routing decision table.
 *
 * Every precedence branch of Rules::decision(), the three-state meta, the
 * legacy value/shape normalization, and the REST validation contract.
 *
 * @package RSS_Chat_Routing
 * @group rss-chat-routing
 */

namespace RSS_Chat_Routing\Tests;

use WP_UnitTestCase;
use RSS_Chat_Routing\Rules;

/**
 * Decision-table tests. No HTTP: these exercise the decision alone.
 */
class Test_Decision extends WP_UnitTestCase {

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
	 * Store settings in the new shape.
	 *
	 * @param array $settings Partial settings.
	 */
	private function set_settings( array $settings ) {
		\update_option( Rules::OPTION, $settings );
	}

	/**
	 * A published post with optional format, kind, and override.
	 *
	 * @param array $args format, kind, override, plus wp_insert_post fields.
	 * @return \WP_Post
	 */
	private function make_post( $args = array() ) {
		$fields  = array(
			'post_status'  => 'publish',
			'post_title'   => 'Hello',
			'post_content' => 'Body.',
		);
		$post_id = self::factory()->post->create( \array_merge( $fields, $args['fields'] ?? array() ) );

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

	// ---------------------------------------------------------------
	// Inherit: default format.
	// ---------------------------------------------------------------

	/**
	 * Status default sends a status post.
	 */
	public function test_status_default_sends_a_status_post() {
		$this->set_settings( array( 'default_format' => 'status' ) );

		$decision = Rules::decision( $this->make_post( array( 'format' => 'status' ) ) );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'format-default', $decision['reason'] );
	}

	/**
	 * Status default ignores a plain post.
	 */
	public function test_status_default_ignores_a_plain_post() {
		$this->set_settings( array( 'default_format' => 'status' ) );

		$decision = Rules::decision( $this->make_post() );

		$this->assertFalse( $decision['send'] );
		$this->assertSame( 'no-match', $decision['reason'] );
	}

	/**
	 * Status default does not send a chat post.
	 */
	public function test_status_default_does_not_send_a_chat_post() {
		$this->set_settings( array( 'default_format' => 'status' ) );

		$decision = Rules::decision( $this->make_post( array( 'format' => 'chat' ) ) );

		$this->assertFalse( $decision['send'] );
	}

	/**
	 * Standard default matches a format less post.
	 */
	public function test_standard_default_matches_a_format_less_post() {
		$this->set_settings( array( 'default_format' => 'standard' ) );

		$decision = Rules::decision( $this->make_post() );

		$this->assertTrue( $decision['send'] );
	}

	// ---------------------------------------------------------------
	// Inherit: default kind.
	// ---------------------------------------------------------------

	/**
	 * Kind default sends a matching kind.
	 */
	public function test_kind_default_sends_a_matching_kind() {
		$this->set_settings( array( 'default_kind' => 'note' ) );

		$decision = Rules::decision( $this->make_post( array( 'kind' => 'note' ) ) );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'kind-default', $decision['reason'] );
	}

	/**
	 * Kind default ignores another kind.
	 */
	public function test_kind_default_ignores_another_kind() {
		$this->set_settings( array( 'default_kind' => 'note' ) );

		$decision = Rules::decision( $this->make_post( array( 'kind' => 'bookmark' ) ) );

		$this->assertFalse( $decision['send'] );
	}

	/**
	 * Kind default without the taxonomy fails safe.
	 */
	public function test_kind_default_without_the_taxonomy_fails_safe() {
		$this->set_settings( array( 'default_kind' => 'note' ) );
		\unregister_taxonomy( 'kind' );

		$decision = Rules::decision( $this->make_post() );

		$this->assertFalse( $decision['send'] );

		// Re-register so tear_down's unregister stays balanced.
		\register_taxonomy( 'kind', 'post', array( 'public' => true ) );
	}

	/**
	 * A vanished kind slug never matches.
	 */
	public function test_a_vanished_kind_slug_never_matches() {
		$this->set_settings( array( 'default_kind' => 'gone' ) );

		$decision = Rules::decision( $this->make_post( array( 'kind' => 'note' ) ) );

		$this->assertFalse( $decision['send'] );
	}

	// ---------------------------------------------------------------
	// Inherit: both defaults — either match sends.
	// ---------------------------------------------------------------

	/**
	 * Both defaults kind match alone sends.
	 */
	public function test_both_defaults_kind_match_alone_sends() {
		$this->set_settings(
			array(
				'default_format' => 'status',
				'default_kind'   => 'note',
			)
		);

		$decision = Rules::decision( $this->make_post( array( 'kind' => 'note' ) ) );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'kind-default', $decision['reason'] );
	}

	/**
	 * Both defaults format match alone sends.
	 */
	public function test_both_defaults_format_match_alone_sends() {
		$this->set_settings(
			array(
				'default_format' => 'status',
				'default_kind'   => 'note',
			)
		);

		$decision = Rules::decision( $this->make_post( array( 'format' => 'status' ) ) );

		$this->assertTrue( $decision['send'] );
	}

	/**
	 * Both defaults neither match stays home.
	 */
	public function test_both_defaults_neither_match_stays_home() {
		$this->set_settings(
			array(
				'default_format' => 'status',
				'default_kind'   => 'note',
			)
		);

		$decision = Rules::decision(
			$this->make_post(
				array(
					'format' => 'aside',
					'kind'   => 'bookmark',
				)
			)
		);

		$this->assertFalse( $decision['send'] );
	}

	// ---------------------------------------------------------------
	// Per-post override beats the defaults.
	// ---------------------------------------------------------------

	/**
	 * Exclude beats a matching default.
	 */
	public function test_exclude_beats_a_matching_default() {
		$this->set_settings( array( 'default_format' => 'status' ) );

		$decision = Rules::decision(
			$this->make_post(
				array(
					'format'   => 'status',
					'override' => 'exclude',
				)
			)
		);

		$this->assertFalse( $decision['send'] );
		$this->assertSame( 'override-exclude', $decision['reason'] );
	}

	/**
	 * Include sends a nonmatching post.
	 */
	public function test_include_sends_a_nonmatching_post() {
		$this->set_settings( array( 'default_format' => 'status' ) );

		$decision = Rules::decision( $this->make_post( array( 'override' => 'include' ) ) );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'override-include', $decision['reason'] );
	}

	/**
	 * Legacy meta values still mean include and exclude.
	 */
	public function test_legacy_meta_values_still_mean_include_and_exclude() {
		$this->set_settings( array( 'default_format' => 'status' ) );

		$yes = Rules::decision( $this->make_post( array( 'override' => '1' ) ) );
		$no  = Rules::decision(
			$this->make_post(
				array(
					'format'   => 'status',
					'override' => '0',
				)
			)
		);

		$this->assertTrue( $yes['send'] );
		$this->assertFalse( $no['send'] );
	}

	// ---------------------------------------------------------------
	// Hard exclusions outrank everything, include too.
	// ---------------------------------------------------------------

	/**
	 * Include cannot send a draft.
	 */
	public function test_include_cannot_send_a_draft() {
		$decision = Rules::decision(
			$this->make_post(
				array(
					'override' => 'include',
					'fields'   => array( 'post_status' => 'draft' ),
				)
			)
		);

		$this->assertFalse( $decision['send'] );
		$this->assertSame( 'ineligible', $decision['reason'] );
	}

	/**
	 * Include cannot send a password protected post.
	 */
	public function test_include_cannot_send_a_password_protected_post() {
		$decision = Rules::decision(
			$this->make_post(
				array(
					'override' => 'include',
					'fields'   => array( 'post_password' => 'hunter2' ),
				)
			)
		);

		$this->assertFalse( $decision['send'] );
	}

	/**
	 * Include cannot send a page.
	 */
	public function test_include_cannot_send_a_page() {
		$decision = Rules::decision(
			$this->make_post(
				array(
					'override' => 'include',
					'fields'   => array( 'post_type' => 'page' ),
				)
			)
		);

		$this->assertFalse( $decision['send'] );
	}

	/**
	 * A non post is never sent.
	 */
	public function test_a_non_post_is_never_sent() {
		$decision = Rules::decision( null );

		$this->assertFalse( $decision['send'] );
	}

	// ---------------------------------------------------------------
	// Legacy option shapes keep their meaning.
	// ---------------------------------------------------------------

	/**
	 * Legacy format mode reads as chat default.
	 */
	public function test_legacy_format_mode_reads_as_chat_default() {
		\update_option( Rules::OPTION, array( 'mode' => 'format' ) );

		$settings = Rules::settings();

		$this->assertSame( 'chat', $settings['default_format'] );
		$this->assertFalse( $settings['legacy_all'] );
	}

	/**
	 * Legacy all mode still sends everything.
	 */
	public function test_legacy_all_mode_still_sends_everything() {
		\update_option( Rules::OPTION, array( 'mode' => 'all' ) );

		$decision = Rules::decision( $this->make_post() );

		$this->assertTrue( $decision['send'] );
		$this->assertSame( 'legacy-all', $decision['reason'] );
	}

	/**
	 * Legacy none mode with kinds reads as kind default.
	 */
	public function test_legacy_none_mode_with_kinds_reads_as_kind_default() {
		\update_option(
			Rules::OPTION,
			array(
				'mode'  => 'none',
				'kinds' => array( 'note', 'bookmark' ),
			)
		);

		$settings = Rules::settings();

		$this->assertSame( '', $settings['default_format'] );
		$this->assertSame( 'note', $settings['default_kind'] );
	}

	/**
	 * Fresh install defaults preserve stock behavior.
	 */
	public function test_fresh_install_defaults_preserve_stock_behavior() {
		$settings = Rules::settings();

		$this->assertSame( 'chat', $settings['default_format'] );
		$this->assertSame( '', $settings['default_kind'] );
		$this->assertSame( 'legacy', $settings['reply_import'] );
	}

	// ---------------------------------------------------------------
	// Settings sanitization.
	// ---------------------------------------------------------------

	/**
	 * Sanitize rejects an unknown format.
	 */
	public function test_sanitize_rejects_an_unknown_format() {
		$clean = Rules::sanitize(
			array(
				'default_format' => 'not-a-format',
				'default_kind'   => 'note',
			)
		);

		$this->assertSame( '', $clean['default_format'] );
		$this->assertSame( 'note', $clean['default_kind'] );
	}

	/**
	 * Sanitize accepts the status format.
	 */
	public function test_sanitize_accepts_the_status_format() {
		$clean = Rules::sanitize( array( 'default_format' => 'status' ) );

		$this->assertSame( 'status', $clean['default_format'] );
	}

	/**
	 * Sanitize rejects an unknown reply import mode.
	 */
	public function test_sanitize_rejects_an_unknown_reply_import_mode() {
		$clean = Rules::sanitize( array( 'reply_import' => 'wormhole' ) );

		$this->assertSame( 'legacy', $clean['reply_import'] );
	}

	/**
	 * Saving the screen retires legacy all.
	 */
	public function test_saving_the_screen_retires_legacy_all() {
		\update_option( Rules::OPTION, array( 'mode' => 'all' ) );
		$this->assertTrue( Rules::settings()['legacy_all'], 'precondition' );

		\update_option( Rules::OPTION, Rules::sanitize( array( 'default_format' => 'status' ) ) );

		$this->assertFalse( Rules::settings()['legacy_all'] );
		$decision = Rules::decision( $this->make_post() );
		$this->assertFalse( $decision['send'] );
	}

	// ---------------------------------------------------------------
	// Override meta normalization.
	// ---------------------------------------------------------------

	/**
	 * Override reads normalize every spelling.
	 */
	public function test_override_reads_normalize_every_spelling() {
		$post = $this->make_post();

		foreach ( array(
			''        => '',
			'inherit' => '',
			'include' => 'include',
			'exclude' => 'exclude',
			'1'       => 'include',
			'0'       => 'exclude',
			'yes'     => '',
		) as $stored => $expected ) {
			\update_post_meta( $post->ID, Rules::META_KEY, $stored );
			$this->assertSame( $expected, Rules::override( $post->ID ), "stored '{$stored}'" );
		}
	}
}
