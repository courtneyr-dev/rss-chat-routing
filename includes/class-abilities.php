<?php
/**
 * WordPress Abilities API registrations.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent-readable and agent-writable routing abilities.
 */
class Abilities {

	const CATEGORY         = 'rss-chat-routing';
	const OPTION_SET_ROUTE = 'rss_chat_routing_ability_set_route';

	/**
	 * Register settings and defer Abilities API registration to its hooks.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::is_available() ) {
			return;
		}

		\register_setting(
			'rss_chat_routing',
			self::OPTION_SET_ROUTE,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => false,
			)
		);

		\add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		\add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Whether this WordPress installation provides the Abilities API.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return \function_exists( 'wp_register_ability' ) && \function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Register the plugin's ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( ! \function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		\wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => \__( 'RSS Chat Routing', 'rss-chat-routing' ),
				'description' => \__( 'Read and change the rss.chat route for a post.', 'rss-chat-routing' ),
			)
		);
	}

	/**
	 * Register the route abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! \function_exists( 'wp_register_ability' ) ) {
			return;
		}

		\wp_register_ability(
			'rss-chat-routing/get-route',
			array(
				'label'               => \__( 'Get a post route', 'rss-chat-routing' ),
				'description'         => \__( 'Get the rss.chat routing decision, override, and site defaults for a post.', 'rss-chat-routing' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::get_route_input_schema(),
				'output_schema'       => self::get_route_output_schema(),
				'permission_callback' => array( __CLASS__, 'can_get_route' ),
				'execute_callback'    => array( __CLASS__, 'execute_get_route' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array( 'public' => true ),
				),
			)
		);

		\wp_register_ability(
			'rss-chat-routing/set-route',
			array(
				'label'               => \__( 'Set a post route', 'rss-chat-routing' ),
				'description'         => \__( 'Change whether a post is included in rss.chat, excluded, or follows the site rules.', 'rss-chat-routing' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::set_route_input_schema(),
				'output_schema'       => self::set_route_output_schema(),
				'permission_callback' => array( __CLASS__, 'can_set_route' ),
				'execute_callback'    => array( __CLASS__, 'execute_set_route' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'mcp'          => array( 'public' => false ),
				),
			)
		);
	}

	/**
	 * Check permission to read a post route.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	public static function can_get_route( $input ) {
		return self::can_edit_input_post( $input );
	}

	/**
	 * Check the switch before permission to change a post route.
	 *
	 * @param mixed $input Ability input.
	 * @return bool|\WP_Error
	 */
	public static function can_set_route( $input ) {
		if ( ! self::set_route_enabled() ) {
			return self::disabled_error();
		}

		return self::can_edit_input_post( $input );
	}

	/**
	 * Execute the route read.
	 *
	 * @param array $input Validated ability input.
	 * @return array
	 */
	public static function execute_get_route( $input ) {
		$post_id  = (int) $input['post_id'];
		$decision = Rules::decision( \get_post( $post_id ) );
		$settings = Rules::settings();

		return array(
			'post_id'        => $post_id,
			'send'           => $decision['send'],
			'reason'         => $decision['reason'],
			'override'       => Rules::override( $post_id ),
			'send_mode'      => $settings['send_mode'],
			'default_format' => $settings['default_format'],
			'default_kind'   => $settings['default_kind'],
		);
	}

	/**
	 * Execute a route change or dry run.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public static function execute_set_route( $input ) {
		if ( ! self::set_route_enabled() ) {
			return self::disabled_error();
		}

		$post_id         = (int) $input['post_id'];
		$override_before = Rules::override( $post_id );
		$override_after  = Rules::sanitize_override( $input['route'] );
		$dry_run         = ! \array_key_exists( 'dry_run', $input ) || (bool) $input['dry_run'];

		if ( ! $dry_run ) {
			if ( '' === $override_after ) {
				\delete_post_meta( $post_id, Rules::META_KEY );
			} else {
				\update_post_meta( $post_id, Rules::META_KEY, $override_after );
			}
		}

		$decision = Rules::decision( \get_post( $post_id ) );

		return array(
			'post_id'         => $post_id,
			'dry_run'         => $dry_run,
			'written'         => ! $dry_run,
			'override_before' => $override_before,
			'override_after'  => $override_after,
			'send_after'      => $decision['send'],
			'reason_after'    => $decision['reason'],
		);
	}

	/**
	 * Whether agents may change stored routes.
	 *
	 * @return bool
	 */
	public static function set_route_enabled() {
		return (bool) \rest_sanitize_boolean( \get_option( self::OPTION_SET_ROUTE, false ) );
	}

	/**
	 * Check edit_post for the existing post named by the ability input.
	 *
	 * @param mixed $input Ability input.
	 * @return bool
	 */
	private static function can_edit_input_post( $input ) {
		$post_id = \is_array( $input ) && isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;

		return $post_id > 0 && null !== \get_post( $post_id ) && \current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Error returned while the set-route switch is off.
	 *
	 * @return \WP_Error
	 */
	private static function disabled_error() {
		return new \WP_Error(
			'rss_chat_routing_ability_disabled',
			\__( 'The set-route ability is disabled.', 'rss-chat-routing' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Shared routing reason values.
	 *
	 * @return string[]
	 */
	private static function reason_values() {
		return array(
			'ineligible',
			'override-exclude',
			'override-include',
			'legacy-all',
			'opt-out-default',
			'opt-in-default',
			'needs-choice',
			'format-default',
			'kind-default',
			'no-match',
		);
	}

	/**
	 * Schema for get-route input.
	 *
	 * @return array
	 */
	private static function get_route_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema for get-route output.
	 *
	 * @return array
	 */
	private static function get_route_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'        => array( 'type' => 'integer' ),
				'send'           => array( 'type' => 'boolean' ),
				'reason'         => array(
					'type' => 'string',
					'enum' => self::reason_values(),
				),
				'override'       => array(
					'type' => 'string',
					'enum' => array( '', 'include', 'exclude' ),
				),
				'send_mode'      => array( 'type' => 'string' ),
				'default_format' => array( 'type' => 'string' ),
				'default_kind'   => array( 'type' => 'string' ),
			),
			'required'             => array( 'post_id', 'send', 'reason', 'override', 'send_mode', 'default_format', 'default_kind' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema for set-route input.
	 *
	 * @return array
	 */
	private static function set_route_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'route'   => array(
					'type' => 'string',
					'enum' => array( 'inherit', 'include', 'exclude' ),
				),
				'dry_run' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
			'required'             => array( 'post_id', 'route' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Schema for set-route output.
	 *
	 * @return array
	 */
	private static function set_route_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'         => array( 'type' => 'integer' ),
				'dry_run'         => array( 'type' => 'boolean' ),
				'written'         => array( 'type' => 'boolean' ),
				'override_before' => array(
					'type' => 'string',
					'enum' => array( '', 'include', 'exclude' ),
				),
				'override_after'  => array(
					'type' => 'string',
					'enum' => array( '', 'include', 'exclude' ),
				),
				'send_after'      => array( 'type' => 'boolean' ),
				'reason_after'    => array(
					'type' => 'string',
					'enum' => self::reason_values(),
				),
			),
			'required'             => array( 'post_id', 'dry_run', 'written', 'override_before', 'override_after', 'send_after', 'reason_after' ),
			'additionalProperties' => false,
		);
	}
}
