<?php
/**
 * Put the canonical WordPress permalink on every pushed rss.chat item.
 *
 * The rss.chat item vocabulary has a `link` field (stored, fed out in RSS),
 * but the parent plugin's payload doesn't send it — and without it the
 * rss.chat server can never point a reply's Webmention back at the
 * WordPress post. Until the parent grows a payload filter, this class
 * intercepts the parent's own /newpost request via pre_http_request and
 * re-issues it with `link` added.
 *
 * Scope is deliberately tight: only requests to the configured server's
 * /newpost, only while a post push is in flight (comment replies pass
 * through untouched), only when the payload has no link already, and with a
 * recursion guard around the re-issued request.
 *
 * Removal path: delete this class once the parent ships
 * `link => get_permalink()` (or a payload filter) upstream.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pre_http_request payload shim.
 */
class Link_Shim {

	/**
	 * Post whose push is currently in flight, or 0.
	 *
	 * @var int
	 */
	private static $candidate = 0;

	/**
	 * True while the re-issued request is running.
	 *
	 * @var bool
	 */
	private static $reissuing = false;

	/**
	 * Wrap the parent's publish entry points so the candidate post is known
	 * whenever the parent might call /newpost.
	 *
	 * @return void
	 */
	public static function init() {
		// Core saves revisions ON wp_after_insert_post (priority 9 since WP
		// 6.4), so a nested run for the revision fires inside ours. The
		// revision guard in open_for_insert and the id match in
		// clear_candidate keep that nested run from clobbering the outer one.
		\add_action( 'wp_after_insert_post', array( __CLASS__, 'open_for_insert' ), 8, 2 );
		\add_action( 'wp_after_insert_post', array( __CLASS__, 'clear_candidate' ), 12, 1 );

		\add_action( 'rest_after_insert_post', array( __CLASS__, 'open_for_rest' ), 8, 1 );
		\add_action( 'rest_after_insert_post', array( __CLASS__, 'clear_candidate' ), 12, 1 );

		\add_filter( 'pre_http_request', array( __CLASS__, 'intercept' ), 10, 3 );
	}

	/**
	 * Classic/programmatic path.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    The post.
	 * @return void
	 */
	public static function open_for_insert( $post_id, $post ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- action signature.
		if ( \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::set_candidate( (int) $post_id );
	}

	/**
	 * Block editor path.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	public static function open_for_rest( $post ) {
		if ( $post instanceof \WP_Post ) {
			self::open_for_insert( $post->ID, $post );
		}
	}

	/**
	 * Record the post whose push may be in flight.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function set_candidate( $post_id ) {
		self::$candidate = (int) $post_id;
	}

	/**
	 * Forget the candidate. When called with a post id (the action callback
	 * path), only the run that set the candidate clears it — a nested run
	 * for another post leaves the outer one intact.
	 *
	 * @param int|\WP_Post $post Post (or id) the closing run belongs to, or
	 *                           0 for unconditional clearing.
	 * @return void
	 */
	public static function clear_candidate( $post = 0 ) {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		if ( 0 !== $post_id && $post_id !== self::$candidate ) {
			return;
		}
		self::$candidate = 0;
	}

	/**
	 * Rewrite a candidate /newpost request to carry the permalink.
	 *
	 * @param mixed  $response Short-circuit value from earlier filters.
	 * @param array  $args     Request args.
	 * @param string $url      Request URL.
	 * @return mixed The re-issued response, or $response untouched.
	 */
	public static function intercept( $response, $args, $url ) {
		if ( false !== $response || self::$reissuing || 0 === self::$candidate ) {
			return $response;
		}
		if ( ! \class_exists( '\\RSS_Chat\\Plugin' ) ) {
			return $response;
		}
		if ( 0 !== \strpos( $url, \RSS_Chat\Plugin::server_url() . '/newpost' ) ) {
			return $response;
		}

		$query = array();
		\parse_str( (string) \wp_parse_url( $url, PHP_URL_QUERY ), $query );
		if ( ! isset( $query['jsontext'] ) ) {
			return $response;
		}

		$item = \json_decode( $query['jsontext'], true );
		if ( ! \is_array( $item ) || isset( $item['link'] ) || isset( $item['inReplyTo'] ) ) {
			// Comment replies and already-linked items pass through as-is.
			return $response;
		}

		$permalink = \get_permalink( self::$candidate );
		if ( ! $permalink ) {
			return $response;
		}

		$item['link']      = $permalink;
		$query['jsontext'] = (string) \wp_json_encode( $item );

		$base    = \strtok( $url, '?' );
		$new_url = \add_query_arg( \array_map( 'rawurlencode', $query ), $base );

		self::$reissuing = true;
		$result          = \wp_remote_request( $new_url, $args );
		self::$reissuing = false;

		return $result;
	}
}
