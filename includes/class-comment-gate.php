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
 * The same wp_insert_comment hook also covers the other direction: it
 * treats rows inserted while `Backfeed::$importing` is set as untrusted
 * remote input, sanitizing them and holding them for moderation.
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

		self::sanitize_backfeed_import( $comment_id, $comment );

		if ( ! self::is_foreign( $comment ) ) {
			return;
		}

		self::$comment_id = (int) $comment_id;
		\add_filter( 'get_comment_metadata', array( __CLASS__, 'answer' ), 10, 3 );
	}

	/**
	 * Treat a row inserted while `\RSS_Chat\Backfeed::$importing` is set as
	 * untrusted remote input: sanitize it and hold it for moderation. The
	 * flag is the only signal available that a row came from there rather
	 * than a local commenter.
	 *
	 * Calling wp_update_comment() with its $wp_error argument turns a
	 * failure into a WP_Error instead of silently leaving the row as
	 * inserted; on that, or on a false return, the row is at least held via
	 * wp_set_comment_status(). Neither call re-fires wp_insert_comment, so
	 * this cannot recurse. On success the sanitized values are mirrored onto
	 * $comment so a wp_insert_comment subscriber running after this one sees
	 * the moderated row, not the original.
	 *
	 * @param int         $comment_id Comment id.
	 * @param \WP_Comment $comment    The comment as inserted.
	 * @return void
	 */
	private static function sanitize_backfeed_import( $comment_id, $comment ) {
		if ( ! \class_exists( '\\RSS_Chat\\Backfeed' ) || empty( \RSS_Chat\Backfeed::$importing ) ) {
			return;
		}

		$content = \wp_kses( $comment->comment_content, \wp_kses_allowed_html( 'comment' ) );
		$author  = \sanitize_text_field( $comment->comment_author );
		$url     = \wp_http_validate_url( $comment->comment_author_url )
			? \esc_url_raw( $comment->comment_author_url, array( 'http', 'https' ) )
			: '';

		$updated = \wp_update_comment(
			\wp_slash(
				array(
					'comment_ID'         => $comment_id,
					'comment_content'    => $content,
					'comment_author'     => $author,
					'comment_author_url' => $url,
					'comment_approved'   => 0,
				)
			),
			true
		);

		if ( \is_wp_error( $updated ) || false === $updated ) {
			\wp_set_comment_status( $comment_id, 'hold' );
			return;
		}

		$comment->comment_content    = $content;
		$comment->comment_author     = $author;
		$comment->comment_author_url = $url;
		$comment->comment_approved   = '0';
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
