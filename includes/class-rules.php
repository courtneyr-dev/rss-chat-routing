<?php
/**
 * Which posts should reach rss.chat, and why.
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
	 * Site-wide defaults.
	 *
	 * Mode "format" sends only chat-format posts, which is what RSS Chat does on
	 * its own. Mode "all" sends every published post unless the post opts out.
	 * Mode "none" sends nothing unless a kind rule or the post opts in.
	 */
	const MODES = array( 'format', 'all', 'none' );

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
	 * Register the per-post override so the block editor can read and write it.
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
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_override' ),
				'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
					return \current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'mode'  => 'format',
			'kinds' => array(),
		);
	}

	/**
	 * Current settings, with unknown values replaced by defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$stored = \get_option( self::OPTION, array() );
		if ( ! \is_array( $stored ) ) {
			$stored = array();
		}

		$settings = \wp_parse_args( $stored, self::defaults() );

		if ( ! \in_array( $settings['mode'], self::MODES, true ) ) {
			$settings['mode'] = 'format';
		}
		$settings['kinds'] = \is_array( $settings['kinds'] ) ? \array_map( 'strval', $settings['kinds'] ) : array();

		return $settings;
	}

	/**
	 * Sanitize the settings array on save.
	 *
	 * @param mixed $value Submitted value.
	 * @return array
	 */
	public static function sanitize( $value ) {
		$value = \is_array( $value ) ? $value : array();

		$mode = isset( $value['mode'] ) ? \sanitize_key( $value['mode'] ) : 'format';
		if ( ! \in_array( $mode, self::MODES, true ) ) {
			$mode = 'format';
		}

		$kinds = array();
		if ( isset( $value['kinds'] ) && \is_array( $value['kinds'] ) ) {
			foreach ( $value['kinds'] as $kind ) {
				$slug = \sanitize_key( $kind );
				if ( '' !== $slug ) {
					$kinds[] = $slug;
				}
			}
		}

		return array(
			'mode'  => $mode,
			'kinds' => \array_values( \array_unique( $kinds ) ),
		);
	}

	/**
	 * Sanitize a per-post override. Only '', '1' and '0' are meaningful.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public static function sanitize_override( $value ) {
		$value = (string) $value;

		return \in_array( $value, array( '1', '0' ), true ) ? $value : '';
	}

	/**
	 * The per-post override, if the author set one.
	 *
	 * @param int $post_id Post id.
	 * @return string '1', '0', or '' for "follow the site rules".
	 */
	public static function override( $post_id ) {
		return self::sanitize_override( \get_post_meta( $post_id, self::META_KEY, true ) );
	}

	/**
	 * Whether the site rules alone would send this post.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	public static function site_rules_send( $post ) {
		$settings = self::settings();

		switch ( $settings['mode'] ) {
			case 'all':
				return true;
			case 'none':
				$send = false;
				break;
			case 'format':
			default:
				$send = ( 'chat' === \get_post_format( $post ) );
				break;
		}

		return $send || self::matches_kind( $post, $settings['kinds'] );
	}

	/**
	 * Whether the post carries one of the kinds chosen in settings.
	 *
	 * @param \WP_Post $post  The post.
	 * @param string[] $kinds Chosen kind slugs.
	 * @return bool
	 */
	public static function matches_kind( $post, $kinds ) {
		if ( empty( $kinds ) || ! \taxonomy_exists( 'kind' ) ) {
			return false;
		}

		return (bool) \has_term( $kinds, 'kind', $post );
	}

	/**
	 * The decision for one post: does it go to rss.chat?
	 *
	 * A per-post choice always wins. Otherwise the site rules decide.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	public static function should_send( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		$override = self::override( $post->ID );
		if ( '1' === $override ) {
			return true;
		}
		if ( '0' === $override ) {
			return false;
		}

		return self::site_rules_send( $post );
	}
}
