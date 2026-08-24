<?php
/**
 * Answer RSS Chat's format question with the routing decision instead.
 *
 * RSS Chat gates syndication on `get_post_format( $post ) === 'chat'`, which is
 * hardcoded (see https://github.com/pfefferle/wordpress-rss-chat/issues/6). Until
 * that becomes filterable, this class stands in front of RSS Chat's own hooks and
 * answers that one question for that one post, for the length of that one call.
 *
 * Nothing is written: the post's real format is untouched, and core caches the
 * term list before `get_the_terms` runs its filter, so the object cache is never
 * poisoned either.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes posts to RSS Chat according to the rules.
 */
class Router {

	/**
	 * Post currently being decided for, or 0.
	 *
	 * @var int
	 */
	private static $post_id = 0;

	/**
	 * The answer to give for that post.
	 *
	 * @var bool
	 */
	private static $send = false;

	/**
	 * Wrap the actions RSS Chat syndicates on, and the feed it decorates.
	 *
	 * @return void
	 */
	public static function init() {
		// Patched parents ask these filters directly; answering them makes
		// the get_the_terms stand-in below redundant (it stays for stock
		// parents and is harmless on both — same decision either way).
		\add_filter( 'rss_chat_should_syndicate', array( __CLASS__, 'filter_should_syndicate' ), 10, 2 );
		\add_filter( 'rss_chat_post_item', array( __CLASS__, 'filter_post_item' ), 10, 2 );

		// RSS Chat listens on these at priority 10; sit either side of it.
		\add_action( 'wp_after_insert_post', array( __CLASS__, 'open_for_insert' ), 9, 2 );
		\add_action( 'wp_after_insert_post', array( __CLASS__, 'close' ), 11 );

		\add_action( 'rest_after_insert_post', array( __CLASS__, 'open_for_rest' ), 9, 1 );
		\add_action( 'rest_after_insert_post', array( __CLASS__, 'close' ), 11 );

		\add_action( 'rss2_item', array( __CLASS__, 'open_for_feed' ), 9 );
		\add_action( 'rss2_item', array( __CLASS__, 'close' ), 11 );
	}

	/**
	 * Classic/programmatic publish path.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    The post.
	 * @return void
	 */
	public static function open_for_insert( $post_id, $post ) {
		self::open( $post );
	}

	/**
	 * Block editor publish path.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	public static function open_for_rest( $post ) {
		self::open( $post );
	}

	/**
	 * Feed path: keep the source: elements consistent with what was actually sent.
	 *
	 * A post routed here by a rule never carries the chat format, so RSS Chat's
	 * feed decoration would skip it and its item would lose source:markdown and
	 * source:comments. Only posts it really did syndicate are covered.
	 *
	 * @return void
	 */
	public static function open_for_feed() {
		$post = \get_post();

		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( '' === (string) \get_post_meta( $post->ID, '_rss_chat_id', true ) ) {
			return;
		}

		self::engage( $post->ID, true );
	}

	/**
	 * The routing decision, for parents that ask via the filter.
	 *
	 * @param bool     $syndicate The parent's own answer.
	 * @param \WP_Post $post      The post.
	 * @return bool
	 */
	public static function filter_should_syndicate( $syndicate, $post ) {
		// While the stand-in is engaged it answers get_post_format() for this
		// post, which would poison a fresh decision — the engaged state IS
		// the decision, so report it directly.
		if ( $post instanceof \WP_Post && (int) $post->ID === self::$post_id ) {
			return self::$send;
		}

		return Rules::should_send( $post );
	}

	/**
	 * Put the canonical permalink on the item, for parents that ask.
	 *
	 * @param array    $item The item payload.
	 * @param \WP_Post $post The post being pushed.
	 * @return array
	 */
	public static function filter_post_item( $item, $post ) {
		if ( empty( $item['link'] ) && $post instanceof \WP_Post ) {
			$permalink = \get_permalink( $post );
			if ( $permalink ) {
				$item['link'] = $permalink;
			}
		}
		return $item;
	}

	/**
	 * Push one post through the parent right now, with the stand-in engaged.
	 *
	 * Used by the Micropub path, where the routing signals only exist after
	 * the parent's own publish hooks have already run. The parent's
	 * already-synced guard makes calling this twice harmless.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	public static function push_now( $post ) {
		if ( ! $post instanceof \WP_Post || ! \class_exists( '\\RSS_Chat\\Syndication' ) ) {
			return;
		}

		self::open( $post );
		Link_Shim::set_candidate( $post->ID );

		( new \RSS_Chat\Syndication() )->push_from_insert( $post->ID, $post );

		Link_Shim::clear_candidate();
		self::close();
	}

	/**
	 * Decide for a post about to be considered for syndication.
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	private static function open( $post ) {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		if ( \wp_is_post_revision( $post->ID ) || \wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		self::engage( $post->ID, Rules::should_send( $post ) );
	}

	/**
	 * Install the stand-in, but only when it would change the answer.
	 *
	 * @param int  $post_id Post id.
	 * @param bool $send    The decision.
	 * @return void
	 */
	private static function engage( $post_id, $send ) {
		$post = \get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// RSS Chat would reach the same conclusion unaided; stay out of the way.
		$unaided = ( 'chat' === \get_post_format( $post ) );
		if ( $unaided === (bool) $send ) {
			return;
		}

		self::$post_id = (int) $post_id;
		self::$send    = (bool) $send;

		\add_filter( 'get_the_terms', array( __CLASS__, 'answer' ), 10, 3 );
	}

	/**
	 * Remove the stand-in.
	 *
	 * @return void
	 */
	public static function close() {
		\remove_filter( 'get_the_terms', array( __CLASS__, 'answer' ), 10 );

		self::$post_id = 0;
		self::$send    = false;
	}

	/**
	 * Report the routing decision as a post format, for one post only.
	 *
	 * @param mixed  $terms    Terms attached to the post.
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy name.
	 * @return mixed
	 */
	public static function answer( $terms, $post_id, $taxonomy ) {
		if ( 'post_format' !== $taxonomy || (int) $post_id !== self::$post_id ) {
			return $terms;
		}

		return self::$send ? array( self::chat_term() ) : array();
	}

	/**
	 * A stand-in chat term. Only the slug is ever read, and building it here
	 * avoids creating a term row for a format the post does not have.
	 *
	 * @return \WP_Term
	 */
	private static function chat_term() {
		$term = \get_term_by( 'slug', 'post-format-chat', 'post_format' );

		if ( $term instanceof \WP_Term ) {
			return $term;
		}

		return new \WP_Term(
			(object) array(
				'term_id'          => 0,
				'name'             => 'chat',
				'slug'             => 'post-format-chat',
				'term_group'       => 0,
				'term_taxonomy_id' => 0,
				'taxonomy'         => 'post_format',
				'description'      => '',
				'parent'           => 0,
				'count'            => 0,
				'filter'           => 'raw',
			)
		);
	}
}
