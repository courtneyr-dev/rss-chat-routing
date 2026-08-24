<?php
/**
 * Reply-import modes, and the one-reply-one-comment guarantee.
 *
 * Three modes, chosen on the settings screen:
 *
 *   legacy      The parent plugin's importer runs as it always has: replies
 *               become ordinary comments marked protocol=rss.chat. The
 *               default — existing installs keep their behavior until the
 *               administrator chooses otherwise.
 *   webmention  Replies are expected to arrive as real, verified Webmentions
 *               (sent by the rss.chat server, received and verified by the
 *               Webmention plugin). The legacy importer is switched off so
 *               the same reply can't be imported twice.
 *   disabled    No replies are imported at all.
 *
 * The cross-mode dedup: an rss.chat reply's Webmention source URL IS its
 * guid (https://server/?id=N), and legacy-imported comments store that guid
 * in comment meta. When a Webmention arrives whose source matches a
 * legacy-imported comment on the same post, it is rejected as a duplicate —
 * the legacy comment stays exactly as it is. That mapping already exists on
 * every legacy comment, so no one-time migration table is needed.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mode switch and Webmention dedup bridge.
 */
class Reply_Import {

	/**
	 * Hook everything. Called from bootstrap when the parent is active.
	 *
	 * @return void
	 */
	public static function init() {
		self::apply_mode();

		// Re-apply when the settings are saved, so a mode change takes
		// effect in the same request instead of the next one.
		\add_action( 'update_option_' . Rules::OPTION, array( __CLASS__, 'apply_mode' ), 10, 0 );

		\add_filter( 'webmention_comment_data', array( __CLASS__, 'reject_legacy_duplicate' ), 13 );

		// Patched parents ask this filter before importing; stock parents
		// rely on apply_mode() unhooking the importer instead.
		\add_filter( 'rss_chat_backfeed_enabled', array( __CLASS__, 'filter_backfeed_enabled' ) );
	}

	/**
	 * The importer switch, for parents that ask via the filter.
	 *
	 * @param bool $enabled The parent's own answer.
	 * @return bool
	 */
	public static function filter_backfeed_enabled( $enabled ) {
		return $enabled && 'legacy' === Rules::settings()['reply_import'];
	}

	/**
	 * Turn the parent's legacy importer on or off to match the mode.
	 *
	 * The parent registers its cron callback on plugins_loaded 10; this runs
	 * later, so removing all callbacks from its private cron hook is a clean
	 * off-switch. Switching back re-adds a fresh (stateless) importer.
	 *
	 * @return void
	 */
	public static function apply_mode() {
		if ( ! \class_exists( '\\RSS_Chat\\Backfeed' ) ) {
			return;
		}

		$mode = Rules::settings()['reply_import'];

		if ( 'legacy' === $mode ) {
			if ( false === \has_action( \RSS_Chat\Backfeed::HOOK ) ) {
				\add_action( \RSS_Chat\Backfeed::HOOK, array( new \RSS_Chat\Backfeed(), 'run' ) );
			}
			return;
		}

		\remove_all_actions( \RSS_Chat\Backfeed::HOOK );
	}

	/**
	 * Reject a Webmention that duplicates a legacy-imported rss.chat reply.
	 *
	 * Runs after the Webmention plugin's own dedup (priority 12), which
	 * matches earlier *webmention* comments: when that matched, comment_ID
	 * is set and this is an update of a genuine Webmention — pass it
	 * through. Only a source colliding with a comment the legacy importer
	 * created (protocol=rss.chat, same post) is turned away, which keeps
	 * that comment intact instead of double-importing or rewriting it.
	 *
	 * @param array|\WP_Error $commentdata Webmention comment data.
	 * @return array|\WP_Error
	 */
	public static function reject_legacy_duplicate( $commentdata ) {
		if ( ! \is_array( $commentdata ) || \is_wp_error( $commentdata ) ) {
			return $commentdata;
		}
		if ( ! empty( $commentdata['comment_ID'] ) ) {
			return $commentdata;
		}

		$source = '';
		if ( isset( $commentdata['comment_meta']['webmention_source_url'] ) ) {
			$source = (string) $commentdata['comment_meta']['webmention_source_url'];
		} elseif ( isset( $commentdata['source'] ) ) {
			$source = (string) $commentdata['source'];
		}
		if ( '' === $source || empty( $commentdata['comment_post_ID'] ) ) {
			return $commentdata;
		}

		$legacy = \get_comments(
			array(
				'post_id'    => (int) $commentdata['comment_post_ID'],
				'status'     => 'any',
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => \RSS_Chat\Plugin::META_GUID,
						'value'   => self::source_spellings( $source ),
						'compare' => 'IN',
					),
					array(
						'key'   => \RSS_Chat\Plugin::META_PROTOCOL,
						'value' => \RSS_Chat\Plugin::PROTOCOL,
					),
				),
			)
		);

		if ( empty( $legacy ) ) {
			return $commentdata;
		}

		return new \WP_Error(
			'rss_chat_routing_duplicate',
			\__( 'This rss.chat reply was already imported as a comment.', 'rss-chat-routing' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Every guid spelling a webmention source could correspond to.
	 *
	 * The server's webmention source is its /item?id=N page, while the item
	 * guid — what legacy comments store — is /?id=N on the same host. Both
	 * name the same reply, so the dedup accepts either.
	 *
	 * @param string $source Webmention source URL.
	 * @return string[]
	 */
	private static function source_spellings( $source ) {
		$spellings = array( $source );

		$parts = \wp_parse_url( $source );
		if ( isset( $parts['path'], $parts['query'] ) && '/item' === $parts['path'] ) {
			$guid = \str_replace( '/item?', '/?', $source );
			if ( $guid !== $source ) {
				$spellings[] = $guid;
			}
		}

		return $spellings;
	}

	/**
	 * What webmention mode actually has to work with, reported honestly.
	 *
	 * @return array{mode: string, webmention_plugin_active: bool, server_support_confirmed: bool}
	 */
	public static function status() {
		return array(
			'mode'                     => Rules::settings()['reply_import'],
			'webmention_plugin_active' => \class_exists( '\\Webmention\\Receiver' ) || \function_exists( 'webmention_init' ),
			// No released rss.chat server sends Webmentions yet. Flip this
			// to a real capability probe once a server version ships it.
			'server_support_confirmed' => false,
		);
	}
}
