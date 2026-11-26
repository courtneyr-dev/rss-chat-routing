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
		\add_filter( 'manage_post_posts_columns', array( __CLASS__, 'add_route_column' ) );
		\add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'render_route_column' ), 10, 2 );
		\add_action( 'quick_edit_custom_box', array( __CLASS__, 'render_quick_edit' ), 10, 2 );
		\add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_quick_edit' ) );
		\add_action( 'save_post_post', array( __CLASS__, 'save_quick_edit' ) );
	}

	/**
	 * Add the route column used by the Posts list and its Quick Edit form.
	 *
	 * @param array $columns List table columns.
	 * @return array
	 */
	public static function add_route_column( $columns ) {
		$columns['rss-chat-route'] = __( 'RSS Chat route', 'rss-chat-routing' );
		return $columns;
	}

	/**
	 * Show the saved routing decision using the server's rule evaluator.
	 *
	 * @param string $column  Column ID.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public static function render_route_column( $column, $post_id ) {
		if ( 'rss-chat-route' !== $column || ! \current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$decision = Rules::decision( \get_post( $post_id ) );
		$reasons  = array(
			'ineligible'       => __( 'Post is ineligible', 'rss-chat-routing' ),
			'override-include' => __( 'Included for this post', 'rss-chat-routing' ),
			'override-exclude' => __( 'Excluded for this post', 'rss-chat-routing' ),
			'opt-out-default'  => __( 'Site includes posts by default', 'rss-chat-routing' ),
			'legacy-all'       => __( 'Site includes posts by default', 'rss-chat-routing' ),
			'opt-in-default'   => __( 'Site requires explicit inclusion', 'rss-chat-routing' ),
			'needs-choice'     => __( 'Choose a route for this post', 'rss-chat-routing' ),
			'format-default'   => __( 'Matches the site’s post format rule', 'rss-chat-routing' ),
			'kind-default'     => __( 'Matches the site’s post kind rule', 'rss-chat-routing' ),
		);
		$label    = $reasons[ $decision['reason'] ] ?? __( 'No site rule matches', 'rss-chat-routing' );

		printf(
			'<span class="rss-chat-route-value" data-route="%s">%s</span>',
			\esc_attr( Rules::override( $post_id ) ),
			\esc_html( $label )
		);
	}

	/**
	 * Render the same routing choices as the block editor.
	 *
	 * @param string $column    Column ID.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public static function render_quick_edit( $column, $post_type ) {
		if ( 'rss-chat-route' !== $column || 'post' !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php \esc_html_e( 'RSS Chat route', 'rss-chat-routing' ); ?></span>
					<select name="rss_chat_routing_route">
						<option value=""><?php \esc_html_e( 'Site default', 'rss-chat-routing' ); ?></option>
						<option value="include"><?php \esc_html_e( 'Include', 'rss-chat-routing' ); ?></option>
						<option value="exclude"><?php \esc_html_e( 'Exclude', 'rss-chat-routing' ); ?></option>
					</select>
				</label>
				<p class="rss-chat-route-reason"></p>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Load the Quick Edit row setup on the Posts list.
	 *
	 * @param string $hook Page hook suffix.
	 * @return void
	 */
	public static function enqueue_quick_edit( $hook ) {
		$screen = \get_current_screen();
		if ( 'edit.php' !== $hook || ! $screen || 'post' !== $screen->post_type ) {
			return;
		}

		\wp_enqueue_script(
			'rss-chat-routing-quick-edit',
			\plugins_url( 'assets/js/quick-edit.js', FILE ),
			array( 'inline-edit-post' ),
			VERSION,
			true
		);
	}

	/**
	 * Accept the override only from an authorized, individual Quick Edit.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function save_quick_edit( $post_id ) {
		if ( ! \wp_doing_ajax()
			|| ! isset( $_POST['action'], $_POST['_inline_edit'], $_POST['rss_chat_routing_route'] )
			|| ! \is_string( $_POST['action'] ) || 'inline-save' !== \sanitize_key( \wp_unslash( $_POST['action'] ) )
			|| ! \is_string( $_POST['_inline_edit'] ) || ! \is_string( $_POST['rss_chat_routing_route'] )
			|| ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' )
			|| ! \current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$route = \sanitize_key( \wp_unslash( $_POST['rss_chat_routing_route'] ) );
		if ( '' === $route ) {
			\delete_post_meta( $post_id, Rules::META_KEY );
		} elseif ( \in_array( $route, array( 'include', 'exclude' ), true ) ) {
			\update_post_meta( $post_id, Rules::META_KEY, $route );
		}
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
			array( 'wp-plugins', 'wp-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n', 'wp-a11y' ),
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
	 * What the panel needs to name the defaults and compute the effective
	 * result as the author edits format, kind, and override.
	 *
	 * @return array
	 */
	public static function panel_data() {
		$settings = Rules::settings();

		$kinds_by_id = array();
		foreach ( Settings::kind_terms() as $term ) {
			$kinds_by_id[ (int) $term->term_id ] = array(
				'slug' => $term->slug,
				'name' => $term->name,
			);
		}

		$format_labels = Settings::format_choices();

		return array(
			'metaKey'            => Rules::META_KEY,
			'sendMode'           => $settings['send_mode'],
			'defaultFormat'      => $settings['default_format'],
			'defaultFormatLabel' => isset( $format_labels[ $settings['default_format'] ] ) ? $format_labels[ $settings['default_format'] ] : $settings['default_format'],
			'defaultKind'        => $settings['default_kind'],
			'legacyAll'          => $settings['legacy_all'],
			'kindsById'          => $kinds_by_id,
			'settingsUrl'        => \admin_url( 'options-general.php?page=' . Settings::SLUG ),
		);
	}
}
