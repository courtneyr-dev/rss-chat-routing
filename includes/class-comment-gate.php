<?php
/**
 * Keep inbound interactions from echoing out to rss.chat.
 *
 * RSS Chat pushes any approved comment on a synced post as an rss.chat
 * reply. Comments that arrived FROM another network — a Webmention, an
 * ActivityPub reply, an ATmosphere/Bluesky reaction, a pingback — must not
 * be re-broadcast: their author never chose rss.chat, and two bridged
 * networks would otherwise ping-pong the same event forever.
 *
 * Provenance is read from the ecosystem's shared vocabulary rather than
 * class checks on the other plugins:
 *   - comment meta `protocol` (webmention / activitypub / rss.chat — written
 *     into commentdata before wp_insert_comment fires, verified in core),
 *   - the comment type (only plain 'comment' rows are locally-authored
 *     replies; webmention/like/repost/pingback types are transport records),
 *   - the ATmosphere agent prefix (its protocol meta lands after insert, but
 *     the agent string is stamped before — the same belt-and-braces check
 *     ATmosphere itself uses).
 *
 * Mechanism mirrors the Router: RSS Chat's push skips comments that already
 * carry an rss.chat guid, so for the duration of its wp_insert_comment
 * callback the gate answers that one meta question with a sentinel. Nothing
 * is stored, and the stand-in is removed on the same action at priority 11.
 * Removal path: replace with upstream's `rss_chat_should_push_comment`
 * filter once it exists.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ownership gate for outbound comment pushes.
 */
class Comment_Gate {

	/**
	 * Comment currently being blocked, or 0.
	 *
	 * @var int
	 */
	private static $comment_id = 0;

	/**
	 * Sit either side of RSS Chat's wp_insert_comment callback (priority 10).
	 *
	 * @return void
	 */
	public static function init() {
		\add_action( 'wp_insert_comment', array( __CLASS__, 'open' ), 9, 2 );
		\add_action( 'wp_insert_comment', array( __CLASS__, 'close' ), 11 );

		// Patched parents ask this filter directly; stock parents never
		// apply it and rely on the stand-in above.
		\add_filter( 'rss_chat_should_push_comment', array( __CLASS__, 'filter_should_push' ), 10, 2 );
	}

	/**
	 * The ownership answer, for parents that ask via the filter.
	 *
	 * @param bool        $push    The parent's own answer.
	 * @param \WP_Comment $comment The comment.
	 * @return bool
	 */
	public static function filter_should_push( $push, $comment ) {
		if ( ! $push ) {
			return false;
		}
		return ! ( $comment instanceof \WP_Comment && self::is_foreign( $comment ) );
	}

	/**
	 * Install the stand-in for foreign comments.
	 *
	 * @param int         $comment_id Comment id.
	 * @param \WP_Comment $comment    The comment.
	 * @return void
	 */
	public static function open( $comment_id, $comment ) {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( ! self::is_foreign( $comment ) ) {
			return;
		}

		self::$comment_id = (int) $comment_id;
		\add_filter( 'get_comment_metadata', array( __CLASS__, 'answer' ), 10, 3 );
	}

	/**
	 * Remove the stand-in.
	 *
	 * @return void
	 */
	public static function close() {
		\remove_filter( 'get_comment_metadata', array( __CLASS__, 'answer' ), 10 );
		self::$comment_id = 0;
	}

	/**
	 * Whether this comment arrived from another network rather than being
	 * written locally.
	 *
	 * @param \WP_Comment $comment The comment.
	 * @return bool
	 */
	public static function is_foreign( $comment ) {
		if ( 'comment' !== $comment->comment_type && '' !== $comment->comment_type ) {
			return true;
		}
		if ( '' !== (string) \get_comment_meta( $comment->comment_ID, 'protocol', true ) ) {
			return true;
		}
		if ( 0 === \strpos( (string) $comment->comment_agent, 'ATmosphere/' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Answer RSS Chat's "already synced?" question with a sentinel guid for
	 * the one comment being blocked. The sentinel is never stored anywhere.
	 *
	 * @param mixed  $value      Existing short-circuit value.
	 * @param int    $comment_id Comment id.
	 * @param string $meta_key   Meta key being read.
	 * @return mixed
	 */
	public static function answer( $value, $comment_id, $meta_key ) {
		if ( \RSS_Chat\Plugin::META_GUID !== $meta_key || (int) $comment_id !== self::$comment_id ) {
			return $value;
		}

		return 'rss-chat-routing:blocked';
	}
}
