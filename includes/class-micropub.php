<?php
/**
 * Micropub intake: the override property, and the late routing pass.
 *
 * Micropub-created posts (Outpost among them) get their post format and kind
 * after wp_after_insert_post has already fired — Outpost's bridges run at
 * after_micropub 20 and Post Kinds for IndieWeb at 30. RSS Chat's push
 * therefore ran too early to see either signal. This class re-evaluates at
 * after_micropub 40 and, when the decision says send, asks the parent to
 * push now; the parent's own already-synced guard keeps that idempotent.
 *
 * Clients don't duplicate the routing algorithm: they may send one optional
 * property and the server-side decision stays authoritative.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The after_micropub handlers.
 */
class Micropub {

	/**
	 * The Micropub property carrying the per-post override.
	 */
	const PROPERTY = 'mp-rss-chat-routing';

	/**
	 * Hook the property bridge. Runs whether or not the parent is active, so
	 * a stored choice is waiting when RSS Chat is switched on.
	 *
	 * @return void
	 */
	public static function init() {
		\add_action( 'after_micropub', array( __CLASS__, 'apply_override' ), 20, 2 );
	}

	/**
	 * Hook the late routing pass. Only wired when the parent is active.
	 *
	 * @return void
	 */
	public static function init_push() {
		\add_action( 'after_micropub', array( __CLASS__, 'route_late' ), 40, 2 );
	}

	/**
	 * Persist the override property, when present and valid.
	 *
	 * Absent means "no opinion": on create the post inherits, on update an
	 * earlier explicit choice survives. Invalid values are dropped, never
	 * coerced into anything truthy.
	 *
	 * @param array $input Original Micropub request body.
	 * @param array $args  wp_insert_post args, including ID.
	 * @return void
	 */
	public static function apply_override( $input, $args ) {
		$post_id = self::post_id( $args );
		if ( 0 === $post_id ) {
			return;
		}

		$value = self::property( $input );
		if ( null === $value ) {
			return;
		}

		if ( 'inherit' === $value ) {
			\delete_post_meta( $post_id, Rules::META_KEY );
			return;
		}

		if ( ! \in_array( $value, Rules::OVERRIDES, true ) ) {
			return;
		}

		\update_post_meta( $post_id, Rules::META_KEY, $value );
	}

	/**
	 * Evaluate the routing decision now that bridges have set format, kind,
	 * and override, and push through the parent when it says send.
	 *
	 * @param array $input Original Micropub request body.
	 * @param array $args  wp_insert_post args, including ID.
	 * @return void
	 */
	public static function route_late( $input, $args ) {
		$post_id = self::post_id( $args );
		if ( 0 === $post_id ) {
			return;
		}

		$post = \get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! Rules::should_send( $post ) ) {
			return;
		}

		Router::push_now( $post );
	}

	/**
	 * Post id from the after_micropub args.
	 *
	 * @param mixed $args wp_insert_post args.
	 * @return int
	 */
	private static function post_id( $args ) {
		return ( \is_array( $args ) && ! empty( $args['ID'] ) ) ? (int) $args['ID'] : 0;
	}

	/**
	 * The override property as a single string, or null when absent.
	 *
	 * Accepts both Micropub shapes: JSON requests nest under 'properties'
	 * and wrap values in arrays; form-encoded requests are flat.
	 *
	 * @param mixed $input Micropub request body.
	 * @return string|null
	 */
	private static function property( $input ) {
		if ( ! \is_array( $input ) ) {
			return null;
		}

		$properties = ( isset( $input['properties'] ) && \is_array( $input['properties'] ) )
			? $input['properties']
			: $input;

		if ( ! \array_key_exists( self::PROPERTY, $properties ) ) {
			return null;
		}

		$value = $properties[ self::PROPERTY ];
		if ( \is_array( $value ) ) {
			$value = $value[0] ?? null;
		}

		return \is_string( $value ) ? \sanitize_key( $value ) : null;
	}
}
