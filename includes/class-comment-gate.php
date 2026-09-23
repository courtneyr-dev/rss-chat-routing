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
 * A second, earlier wp_insert_comment callback covers the other
 * direction: it treats rows inserted while `Backfeed::$importing` is set
 * as untrusted remote input, sanitizing them and holding them for
 * moderation, before any other subscriber to the same action sees the
 * raw row.
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
		// Runs before every other wp_insert_comment subscriber, so as few of
		// them as possible ever see an unsanitized backfeed row.
		\add_action( 'wp_insert_comment', array( __CLASS__, 'sanitize_backfeed_import' ), \PHP_INT_MIN, 2 );

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
	 * Treat a row inserted while `\RSS_Chat\Backfeed::$importing` is set as
	 * untrusted remote input: sanitize it and hold it for moderation. The
	 * flag is the only signal available that a row came from there rather
	 * than a local commenter. Registered directly on wp_insert_comment at
	 * PHP_INT_MIN rather than called from open(), so it runs before that
	 * priority-9 gate and any other subscriber. The row is always re-read by
	 * $comment_id rather than trusted from the second argument, since that
	 * keeps working even if some future caller of the action does not pass
	 * a WP_Comment there.
	 *
	 * A comment already flagged spam or trashed is left at that status;
	 * only an approved (`1`) row is downgraded to held, so this cannot
	 * un-spam or un-trash a row another plugin has already judged.
	 *
	 * Calling wp_update_comment() with its $wp_error argument turns a
	 * failure into a WP_Error instead of silently leaving the row as
	 * inserted. If that update fails — a filter veto, or content that grows
	 * past a column limit once WordPress's own comment filters run again on
	 * an update — a second attempt clears every sanitized field to an empty
	 * string and forces the row held, since an empty value cannot fail the
	 * same way; if even that fails, the row is deleted outright rather than
	 * left holding unsanitized content that an admin's Comments screen would
	 * render unescaped. Neither wp_update_comment() nor wp_delete_comment()
	 * re-fires wp_insert_comment, so none of this can recurse. On success
	 * the freshly stored row is re-read and mirrored onto $comment, so a
	 * wp_insert_comment subscriber running after this one sees the
	 * moderated row, not the original.
	 *
	 * @param int         $comment_id Comment id.
	 * @param \WP_Comment $comment    The comment as inserted, when the
	 *                                caller supplied one; only used for the
	 *                                final mirror step, never to decide
	 *                                whether to act.
	 * @return void
	 */
	public static function sanitize_backfeed_import( $comment_id, $comment = null ) {
		if ( ! \class_exists( '\\RSS_Chat\\Backfeed' ) || empty( \RSS_Chat\Backfeed::$importing ) ) {
			return;
		}

		$original = \get_comment( $comment_id );
		if ( ! $original instanceof \WP_Comment ) {
			return;
		}

		$content = \wp_kses( $original->comment_content, \wp_kses_allowed_html( 'comment' ) );
		$author  = \sanitize_text_field( $original->comment_author );

		$parsed_url = \wp_parse_url( (string) $original->comment_author_url );
		$scheme     = isset( $parsed_url['scheme'] ) ? \strtolower( $parsed_url['scheme'] ) : '';
		$url        = ( \in_array( $scheme, array( 'http', 'https' ), true ) && ! empty( $parsed_url['host'] ) )
			? \esc_url_raw( $original->comment_author_url, array( 'http', 'https' ) )
			: '';

		$approved = ( '1' === (string) $original->comment_approved ) ? 0 : $original->comment_approved;

		$updated = \wp_update_comment(
			\wp_slash(
				array(
					'comment_ID'         => $comment_id,
					'comment_content'    => $content,
					'comment_author'     => $author,
					'comment_author_url' => $url,
					'comment_approved'   => $approved,
				)
			),
			true
		);

		if ( \is_wp_error( $updated ) || false === $updated ) {
			$cleared = \wp_update_comment(
				array(
					'comment_ID'         => $comment_id,
					'comment_content'    => '',
					'comment_author'     => '',
					'comment_author_url' => '',
					'comment_approved'   => 0,
				),
				true
			);

			if ( \is_wp_error( $cleared ) || false === $cleared ) {
				\wp_delete_comment( $comment_id, true );
			}

			return;
		}

		$fresh = \get_comment( $comment_id );
		if ( $fresh instanceof \WP_Comment && $comment instanceof \WP_Comment ) {
			$comment->comment_content    = $fresh->comment_content;
			$comment->comment_author     = $fresh->comment_author;
			$comment->comment_author_url = $fresh->comment_author_url;
			$comment->comment_approved   = $fresh->comment_approved;
		}
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
