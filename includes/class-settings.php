<?php
/**
 * Settings screen.
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings > RSS Chat Routing.
 */
class Settings {

	const SLUG = 'rss-chat-routing';

	/**
	 * Hook the admin screen.
	 *
	 * @return void
	 */
	public static function init() {
		\add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
	}

	/**
	 * Add the options page.
	 *
	 * @return void
	 */
	public static function add_page() {
		\add_options_page(
			\__( 'RSS Chat Routing', 'rss-chat-routing' ),
			\__( 'RSS Chat Routing', 'rss-chat-routing' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Rules::settings();
		$kinds    = self::kind_terms();
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'RSS Chat Routing', 'rss-chat-routing' ); ?></h1>

			<p>
				<?php echo \esc_html__( 'Decide which posts are sent to rss.chat. Any individual post can override this from the editor sidebar.', 'rss-chat-routing' ); ?>
			</p>

			<?php if ( ! is_parent_active() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php echo \esc_html__( 'The RSS Chat plugin is not active, so nothing is being sent.', 'rss-chat-routing' ); ?></p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php \settings_fields( 'rss_chat_routing' ); ?>

				<h2><?php echo \esc_html__( 'Site default', 'rss-chat-routing' ); ?></h2>
				<fieldset>
					<legend class="screen-reader-text"><?php echo \esc_html__( 'Which posts are sent to rss.chat', 'rss-chat-routing' ); ?></legend>
					<?php foreach ( self::mode_choices() as $mode => $label ) : ?>
						<p>
							<label>
								<input
									type="radio"
									name="<?php echo \esc_attr( Rules::OPTION ); ?>[mode]"
									value="<?php echo \esc_attr( $mode ); ?>"
									<?php \checked( $settings['mode'], $mode ); ?>
								/>
								<?php echo \esc_html( $label['title'] ); ?>
								<span class="description"><br /><?php echo \esc_html( $label['help'] ); ?></span>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php echo \esc_html__( 'Always send these kinds', 'rss-chat-routing' ); ?></h2>
				<?php if ( empty( $kinds ) ) : ?>
					<p class="description">
						<?php echo \esc_html__( 'No Post Kinds found. Activate Post Kinds for IndieWeb to route by kind.', 'rss-chat-routing' ); ?>
					</p>
				<?php else : ?>
					<p class="description">
						<?php echo \esc_html__( 'Posts in a ticked kind are sent whatever their post format, on top of the site default above.', 'rss-chat-routing' ); ?>
					</p>
					<fieldset>
						<legend class="screen-reader-text"><?php echo \esc_html__( 'Kinds to send', 'rss-chat-routing' ); ?></legend>
						<?php foreach ( $kinds as $kind ) : ?>
							<label style="display:inline-block;min-width:12em;margin:0 1em .5em 0;">
								<input
									type="checkbox"
									name="<?php echo \esc_attr( Rules::OPTION ); ?>[kinds][]"
									value="<?php echo \esc_attr( $kind->slug ); ?>"
									<?php \checked( \in_array( $kind->slug, $settings['kinds'], true ) ); ?>
								/>
								<?php echo \esc_html( $kind->name ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>

				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * The three site-wide defaults, with plain descriptions.
	 *
	 * @return array
	 */
	public static function mode_choices() {
		return array(
			'format' => array(
				'title' => \__( 'Only posts with the chat post format', 'rss-chat-routing' ),
				'help'  => \__( 'What RSS Chat does on its own. Nothing changes until you pick something else.', 'rss-chat-routing' ),
			),
			'all'    => array(
				'title' => \__( 'Every published post, unless the post says otherwise', 'rss-chat-routing' ),
				'help'  => \__( 'Includes posts made by importers and other plugins, so check the editor toggle on anything you would rather keep back.', 'rss-chat-routing' ),
			),
			'none'   => array(
				'title' => \__( 'Nothing, unless a kind below or the post itself opts in', 'rss-chat-routing' ),
				'help'  => \__( 'The chat post format stops sending posts on its own in this mode.', 'rss-chat-routing' ),
			),
		);
	}

	/**
	 * Registered kinds, if Post Kinds is around.
	 *
	 * @return \WP_Term[]
	 */
	public static function kind_terms() {
		if ( ! \taxonomy_exists( 'kind' ) ) {
			return array();
		}

		$terms = \get_terms(
			array(
				'taxonomy'   => 'kind',
				'hide_empty' => false,
			)
		);

		return \is_wp_error( $terms ) ? array() : $terms;
	}
}
