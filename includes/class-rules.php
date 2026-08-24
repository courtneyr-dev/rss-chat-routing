<?php
/**
 * Which posts should reach rss.chat, and why.
 *
 * One canonical decision function; every caller — editor, REST, Micropub,
 * syndication — asks it and nothing else.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage and the send/don't-send decision.
 */
class Rules {

	const OPTION   = 'rss_chat_routing';
	const META_KEY = '_rss_chat_routing';

	/**
	 * Per-post override states. Inherit is stored as '' (or no row at all);
	 * the legacy 0.1.0 spellings '1'/'0' are read as include/exclude forever.
	 */
	const OVERRIDES = array( 'include', 'exclude' );

	/**
	 * Reply-import modes. "legacy" is the parent plugin's direct-comment
	 * importer; "webmention" hands replies to the Webmention plugin and turns
	 * the importer off; "disabled" imports nothing.
	 */
	const REPLY_IMPORT_MODES = array( 'legacy', 'webmention', 'disabled' );

	/**
	 * Register the option and the per-post meta.
	 *
	 * @return void
	 */
	public static function init() {
		\register_setting(
			'rss_chat_routing',
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::defaults(),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);

		\add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	/**
	 * Register the per-post override so the block editor and REST clients can
	 * read and write it. The enum schema makes REST reject unknown values
	 * with a 400 instead of quietly coercing them.
	 *
	 * @return void
	 */
	public static function register_meta() {
		\register_post_meta(
			'post',
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => array(
					'schema' => array(
						'type' => 'string',
						'enum' => array( '', 'inherit', 'include', 'exclude' ),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_override' ),
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return \current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Default settings. The chat default format IS the parent plugin's stock
	 * behavior, so a fresh install changes nothing.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'default_format' => 'chat',
			'default_kind'   => '',
			'reply_import'   => 'legacy',
			'legacy_all'     => false,
		);
	}

	/**
	 * Current settings, with the 0.1.0 {mode, kinds[]} shape normalized on
	 * read so an un-resaved install keeps behaving exactly as configured.
	 *
	 * Legacy mapping: mode "format" → default_format "chat" (stock);
	 * mode "none" → no defaults; mode "all" → legacy_all, honored until the
	 * settings screen is saved once — never silently stop syndicating an
	 * existing install. kinds[] contributes its first slug as default_kind.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = \get_option( self::OPTION, array() );
		if ( ! \is_array( $stored ) ) {
			$stored = array();
		}

		if ( isset( $stored['mode'] ) && ! isset( $stored['default_format'] ) ) {
			$stored = self::upgrade_legacy_shape( $stored );
		}

		$settings = \wp_parse_args( $stored, self::defaults() );

		$settings['default_format'] = \sanitize_key( $settings['default_format'] );
		$settings['default_kind']   = \sanitize_key( $settings['default_kind'] );
		$settings['legacy_all']     = ! empty( $settings['legacy_all'] );
		if ( ! \in_array( $settings['reply_import'], self::REPLY_IMPORT_MODES, true ) ) {
			$settings['reply_import'] = 'legacy';
		}

		return $settings;
	}

	/**
	 * Map a stored 0.1.0 option to the current shape.
	 *
	 * @param array $stored Stored legacy option.
	 * @return array
	 */
	private static function upgrade_legacy_shape( array $stored ) {
		$mode  = isset( $stored['mode'] ) ? (string) $stored['mode'] : 'format';
		$kinds = ( isset( $stored['kinds'] ) && \is_array( $stored['kinds'] ) ) ? \array_values( $stored['kinds'] ) : array();

		return array(
			'default_format' => 'format' === $mode ? 'chat' : '',
			'default_kind'   => isset( $kinds[0] ) ? (string) $kinds[0] : '',
			'legacy_all'     => 'all' === $mode,
		);
	}

	/**
	 * Sanitize the settings array on save.
	 *
	 * Saving through here always produces the current shape, which retires
	 * legacy_all: the admin has now seen and confirmed the new model.
	 *
	 * @param mixed $value Submitted value.
	 * @return array
	 */
	public static function sanitize( $value ) {
		$value = \is_array( $value ) ? $value : array();

		// A 0.1.0 shape arriving through update_option() keeps its meaning
		// (including legacy_all) instead of being blanked.
		if ( isset( $value['mode'] ) && ! isset( $value['default_format'] ) ) {
			$value = self::upgrade_legacy_shape( $value );
		}

		$format = isset( $value['default_format'] ) ? \sanitize_key( $value['default_format'] ) : '';
		if ( '' !== $format && ! \in_array( $format, \array_keys( \get_post_format_slugs() ), true ) ) {
			$format = '';
		}

		$kind = isset( $value['default_kind'] ) ? \sanitize_key( $value['default_kind'] ) : '';

		$reply_import = isset( $value['reply_import'] ) ? \sanitize_key( $value['reply_import'] ) : 'legacy';
		if ( ! \in_array( $reply_import, self::REPLY_IMPORT_MODES, true ) ) {
			$reply_import = 'legacy';
		}

		// Idempotent on purpose: update_option() can sanitize twice (it
		// funnels a first write through add_option()). legacy_all survives
		// any programmatic round-trip; the settings screen retires it simply
		// by never submitting the field.
		return array(
			'default_format' => $format,
			'default_kind'   => $kind,
			'reply_import'   => $reply_import,
			'legacy_all'     => ! empty( $value['legacy_all'] ),
		);
	}

	/**
	 * Sanitize a per-post override into its canonical spelling.
	 *
	 * '' and 'inherit' mean "follow the site rules"; the legacy '1'/'0' keep
	 * their 0.1.0 meaning; anything else falls back to inherit. (REST input
	 * never reaches this fallback — the enum schema rejects it first.)
	 *
	 * @param mixed $value Submitted or stored value.
	 * @return string '', 'include', or 'exclude'.
	 */
	public static function sanitize_override( $value ) {
		$value = (string) $value;

		if ( \in_array( $value, self::OVERRIDES, true ) ) {
			return $value;
		}
		if ( '1' === $value ) {
			return 'include';
		}
		if ( '0' === $value ) {
			return 'exclude';
		}

		return '';
	}

	/**
	 * The per-post override, if the author set one.
	 *
	 * @param int $post_id Post id.
	 * @return string '', 'include', or 'exclude'.
	 */
	public static function override( $post_id ) {
		return self::sanitize_override( \get_post_meta( $post_id, self::META_KEY, true ) );
	}

	/**
	 * The canonical routing decision for one post.
	 *
	 * Precedence:
	 *   1. Content rss.chat must never syndicate → false, whatever else says.
	 *   2. Per-post exclude → false.
	 *   3. Per-post include → true.
	 *   4. Inherit → true when the post matches the default format OR the
	 *      default kind (independent signals, either sends), or the install
	 *      still runs the un-resaved 0.1.0 "all" mode.
	 *   5. Otherwise false.
	 *
	 * @param \WP_Post|null $post The post.
	 * @return array{send: bool, reason: string}
	 */
	public static function decision( $post ) {
		if ( ! self::is_eligible( $post ) ) {
			return array(
				'send'   => false,
				'reason' => 'ineligible',
			);
		}

		$override = self::override( $post->ID );
		if ( 'exclude' === $override ) {
			return array(
				'send'   => false,
				'reason' => 'override-exclude',
			);
		}
		if ( 'include' === $override ) {
			return array(
				'send'   => true,
				'reason' => 'override-include',
			);
		}

		$settings = self::settings();

		if ( $settings['legacy_all'] ) {
			return array(
				'send'   => true,
				'reason' => 'legacy-all',
			);
		}

		if ( self::matches_format( $post, $settings['default_format'] ) ) {
			return array(
				'send'   => true,
				'reason' => 'format-default',
			);
		}

		if ( self::matches_kind( $post, $settings['default_kind'] ) ) {
			return array(
				'send'   => true,
				'reason' => 'kind-default',
			);
		}

		return array(
			'send'   => false,
			'reason' => 'no-match',
		);
	}

	/**
	 * Content rss.chat must never syndicate, even on an explicit include:
	 * wrong type, unpublished, password-protected, revision, autosave.
	 *
	 * @param mixed $post Candidate post.
	 * @return bool
	 */
	public static function is_eligible( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		if ( '' !== $post->post_password ) {
			return false;
		}
		if ( \wp_is_post_revision( $post->ID ) || \wp_is_post_autosave( $post->ID ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the post carries the default format. 'standard' matches posts
	 * with no format at all, which is how core spells that.
	 *
	 * @param \WP_Post $post   The post.
	 * @param string   $format Default format slug, or ''.
	 * @return bool
	 */
	public static function matches_format( $post, $format ) {
		if ( '' === $format ) {
			return false;
		}

		$actual = \get_post_format( $post );
		if ( false === $actual ) {
			$actual = 'standard';
		}

		return $actual === $format;
	}

	/**
	 * Whether the post carries the default kind. Fails safe when Post Kinds
	 * is not around: no taxonomy, no match, no error.
	 *
	 * @param \WP_Post $post The post.
	 * @param string   $kind Default kind slug, or ''.
	 * @return bool
	 */
	public static function matches_kind( $post, $kind ) {
		if ( '' === $kind || ! \taxonomy_exists( 'kind' ) ) {
			return false;
		}

		return (bool) \has_term( $kind, 'kind', $post );
	}

	/**
	 * The boolean the Router acts on. Kept as the compact spelling of
	 * decision() for callers that don't need the reason.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	public static function should_send( $post ) {
		$decision = self::decision( $post );
		return $decision['send'];
	}
}
