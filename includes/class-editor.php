<?php
/**
 * The per-post control in the editor sidebar.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the document setting panel.
 */
class Editor {

	/**
	 * Hook the editor assets.
	 *
	 * @return void
	 */
	public static function init() {
		\add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue the panel script.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;

		if ( $screen && 'post' !== $screen->post_type ) {
			return;
		}

		$handle = 'rss-chat-routing-editor';
		$path   = \plugin_dir_path( FILE ) . 'assets/js/editor.js';

		\wp_enqueue_script(
			$handle,
			\plugins_url( 'assets/js/editor.js', FILE ),
			array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
			(string) ( \file_exists( $path ) ? \filemtime( $path ) : VERSION ),
			true
		);

		\wp_set_script_translations( $handle, 'rss-chat-routing' );

		\wp_add_inline_script(
			$handle,
			'window.rssChatRouting = ' . \wp_json_encode( self::panel_data() ) . ';',
			'before'
		);
	}

	/**
	 * What the panel needs to describe the current default.
	 *
	 * @return array
	 */
	public static function panel_data() {
		$settings = Rules::settings();
		$choices  = Settings::mode_choices();
		$post     = \get_post();

		return array(
			'metaKey'     => Rules::META_KEY,
			'mode'        => $settings['mode'],
			'modeLabel'   => $choices[ $settings['mode'] ]['title'],
			'siteDefault' => ( $post instanceof \WP_Post ) ? Rules::site_rules_send( $post ) : false,
			'settingsUrl' => \admin_url( 'options-general.php?page=' . Settings::SLUG ),
		);
	}
}
