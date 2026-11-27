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
		\add_action( 'rest_api_init', array( __CLASS__, 'register_delivery_field' ) );
		\add_filter( 'rest_prepare_post', array( __CLASS__, 'add_delivery_field' ), 10, 3 );
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

		printf(
			'<span class="rss-chat-route-value" data-route="%s">%s</span>',
			\esc_attr( Rules::override( $post_id ) ),
			\esc_html( self::reason_label( $decision['reason'] ) )
		);
	}

	/**
	 * Name the saved decision for both Posts views.
	 *
	 * @param string $reason Reason from the routing evaluator.
	 * @return string
	 */
	private static function reason_label( $reason ) {
		$reasons = array(
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
		return $reasons[ $reason ] ?? __( 'No site rule matches', 'rss-chat-routing' );
	}

	/**
	 * Add the read-only preview under Posts, even when RSS Chat is inactive.
	 *
	 * @return void
	 */
	public static function add_preview_page() {
		\add_submenu_page(
			'edit.php',
			__( 'RSS Chat routing preview', 'rss-chat-routing' ),
			__( 'RSS Chat routing preview', 'rss-chat-routing' ),
			'edit_posts',
			'rss-chat-routing-preview',
			array( __CLASS__, 'render_preview_page' )
		);
	}

	/**
	 * Display a page of editable posts grouped by saved decision and reason.
	 *
	 * @return void
	 */
	public static function render_preview_page() {
		if ( ! \current_user_can( 'edit_posts' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagination only reads rows.
		$page = isset( $_GET['paged'] ) && \is_string( $_GET['paged'] ) ? max( 1, \absint( $_GET['paged'] ) ) : 1;
		$args = array(
			'post_type'           => 'post',
			'post_status'         => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page'      => 20,
			'paged'               => $page,
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'ignore_sticky_posts' => true,
		);

		if ( ! \current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = \get_current_user_id();
		}

		$query  = new \WP_Query( $args );
		$groups = array(
			'include' => array(),
			'hold'    => array(),
		);
		foreach ( $query->posts as $post ) {
			if ( ! \current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$decision = Rules::decision( $post );
			$groups[ $decision['send'] ? 'include' : 'hold' ][ $decision['reason'] ][] = $post;
		}

		$labels = array(
			'include' => __( 'Included by the saved rules', 'rss-chat-routing' ),
			'hold'    => __( 'Held by the saved rules', 'rss-chat-routing' ),
		);
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'RSS Chat routing preview', 'rss-chat-routing' ); ?></h1>
			<p><?php \esc_html_e( 'These rules use each post’s saved settings and current publication status.', 'rss-chat-routing' ); ?></p>
			<?php if ( ! is_parent_active() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php \esc_html_e( 'RSS Chat is inactive. Delivery is unavailable.', 'rss-chat-routing' ); ?></p>
				</div>
			<?php elseif ( ! \in_array( 'post', \RSS_Chat\Plugin::supported_post_types(), true ) || ! \RSS_Chat\Plugin::is_connected() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php \esc_html_e( 'Delivery requires a connected RSS Chat account and publishing enabled for Posts.', 'rss-chat-routing' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( empty( $groups['include'] ) && empty( $groups['hold'] ) ) : ?>
				<p><?php \esc_html_e( 'You have no editable posts on this page.', 'rss-chat-routing' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $labels as $group => $label ) : ?>
				<?php
				if ( empty( $groups[ $group ] ) ) {
					continue; }
				?>
				<h2><?php echo \esc_html( $label ); ?></h2>
				<?php foreach ( $groups[ $group ] as $reason => $posts ) : ?>
					<h3><?php echo \esc_html( self::reason_label( $reason ) ); ?></h3>
					<ul class="ul-disc">
						<?php foreach ( $posts as $post ) : ?>
							<?php $title = \get_the_title( $post ); ?>
							<li><a href="<?php echo \esc_url( \get_edit_post_link( $post ) ); ?>"><?php echo \esc_html( '' === $title ? __( '(no title)', 'rss-chat-routing' ) : $title ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>
			<?php endforeach; ?>
			<?php if ( $query->max_num_pages > 1 ) : ?>
				<nav class="tablenav" aria-label="<?php \esc_attr_e( 'RSS Chat preview pages', 'rss-chat-routing' ); ?>">
					<?php
					echo \wp_kses_post(
						\paginate_links(
							array(
								'base'    => \admin_url( 'edit.php?page=rss-chat-routing-preview&paged=%#%' ),
								'current' => $page,
								'total'   => $query->max_num_pages,
								'type'    => 'list',
							)
						)
					);
					?>
				</nav>
			<?php endif; ?>
		</div>
		<?php
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
	 * Expose saved delivery data in the editor’s existing post response.
	 *
	 * @return void
	 */
	public static function register_delivery_field() {
		\register_rest_field(
			'post',
			'rss_chat_routing_delivery',
			array(
				'schema' => array(
					'type'       => array( 'object', 'null' ),
					'context'    => array( 'edit' ),
					'readonly'   => true,
					'properties' => array(
						'remote_id' => array( 'type' => 'string' ),
						'feed'      => array(
							'type'       => array( 'object', 'null' ),
							'properties' => array(
								'origin'         => array(
									'type' => 'string',
									'enum' => array( 'delivery', 'chat' ),
								),
								'comments_count' => array( 'type' => 'integer' ),
								'comments_url'   => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Read delivery details for an editable post.
	 *
	 * @param \WP_REST_Response $response Prepared post response.
	 * @param \WP_Post          $post     The saved post, even when _fields omits its ID.
	 * @param \WP_REST_Request  $request  Original request.
	 * @return \WP_REST_Response
	 */
	public static function add_delivery_field( $response, $post, $request ) {
		$field = 'rss_chat_routing_delivery';
		if ( 'edit' !== $request['context']
			|| 'HEAD' === $request->get_method()
			|| ( isset( $request['_fields'] ) && ! \rest_is_field_included( $field, \wp_parse_list( $request['_fields'] ) ) )
			|| ! \current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}

		$data           = $response->get_data();
		$data[ $field ] = array(
			'remote_id' => Router::delivery_id( $post->ID ),
			'feed'      => Router::feed_provenance( $post ),
		);
		$response->set_data( $data );
		return $response;
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
