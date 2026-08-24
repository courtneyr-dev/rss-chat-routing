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
		$status   = \class_exists( __NAMESPACE__ . '\\Reply_Import' ) ? Reply_Import::status() : null;
		?>
		<div class="wrap">
			<h1><?php echo \esc_html__( 'RSS Chat Routing', 'rss-chat-routing' ); ?></h1>

			<p>
				<?php echo \esc_html__( 'Posts matching the default post format or the default post kind are sent to rss.chat. Either match is enough, and any individual post can opt in or out from the editor sidebar — a per-post choice always wins.', 'rss-chat-routing' ); ?>
			</p>

			<?php if ( ! is_parent_active() ) : ?>
				<div class="notice notice-warning inline">
					<p><?php echo \esc_html__( 'The RSS Chat plugin is not active, so nothing is being sent.', 'rss-chat-routing' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $settings['legacy_all'] ) : ?>
				<div class="notice notice-info inline">
					<p><?php echo \esc_html__( 'This site still uses the retired "send every post" setting. It keeps working until you save this screen; saving switches to the defaults chosen below.', 'rss-chat-routing' ); ?></p>
				</div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php \settings_fields( 'rss_chat_routing' ); ?>

				<h2><?php echo \esc_html__( 'Default post format', 'rss-chat-routing' ); ?></h2>
				<p class="description">
					<?php echo \esc_html__( 'Posts with this format are sent by default. Note: with a default other than Chat, the chat post format no longer sends posts on its own.', 'rss-chat-routing' ); ?>
				</p>
				<fieldset>
					<legend class="screen-reader-text"><?php echo \esc_html__( 'Default post format', 'rss-chat-routing' ); ?></legend>
					<p>
						<label>
							<input
								type="radio"
								name="<?php echo \esc_attr( Rules::OPTION ); ?>[default_format]"
								value=""
								<?php \checked( $settings['default_format'], '' ); ?>
							/>
							<?php echo \esc_html__( 'No format default', 'rss-chat-routing' ); ?>
						</label>
					</p>
					<?php foreach ( self::format_choices() as $slug => $label ) : ?>
						<p>
							<label>
								<input
									type="radio"
									name="<?php echo \esc_attr( Rules::OPTION ); ?>[default_format]"
									value="<?php echo \esc_attr( $slug ); ?>"
									<?php \checked( $settings['default_format'], $slug ); ?>
								/>
								<?php echo \esc_html( $label ); ?>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php echo \esc_html__( 'Default post kind', 'rss-chat-routing' ); ?></h2>
				<?php if ( empty( $kinds ) ) : ?>
					<p class="description">
						<?php echo \esc_html__( 'No Post Kinds found. Activate Post Kinds for IndieWeb to add a kind default; the format default above works without it.', 'rss-chat-routing' ); ?>
					</p>
				<?php else : ?>
					<p class="description">
						<?php echo \esc_html__( 'Posts with this kind are sent by default, whatever their post format.', 'rss-chat-routing' ); ?>
					</p>
					<p>
						<label for="rss-chat-routing-default-kind">
							<?php echo \esc_html__( 'Default kind', 'rss-chat-routing' ); ?>
						</label>
						<select
							id="rss-chat-routing-default-kind"
							name="<?php echo \esc_attr( Rules::OPTION ); ?>[default_kind]"
						>
							<option value=""><?php echo \esc_html__( 'No kind default', 'rss-chat-routing' ); ?></option>
							<?php foreach ( $kinds as $kind ) : ?>
								<option
									value="<?php echo \esc_attr( $kind->slug ); ?>"
									<?php \selected( $settings['default_kind'], $kind->slug ); ?>
								>
									<?php echo \esc_html( $kind->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>
				<?php endif; ?>

				<h2><?php echo \esc_html__( 'Replies from rss.chat', 'rss-chat-routing' ); ?></h2>
				<fieldset>
					<legend class="screen-reader-text"><?php echo \esc_html__( 'How replies come back from rss.chat', 'rss-chat-routing' ); ?></legend>
					<?php foreach ( self::reply_import_choices() as $mode => $choice ) : ?>
						<p>
							<label>
								<input
									type="radio"
									name="<?php echo \esc_attr( Rules::OPTION ); ?>[reply_import]"
									value="<?php echo \esc_attr( $mode ); ?>"
									<?php \checked( $settings['reply_import'], $mode ); ?>
								/>
								<?php echo \esc_html( $choice['title'] ); ?>
								<span class="description"><br /><?php echo \esc_html( $choice['help'] ); ?></span>
							</label>
						</p>
					<?php endforeach; ?>
				</fieldset>

				<?php if ( $status && 'webmention' === $status['mode'] ) : ?>
					<?php if ( ! $status['webmention_plugin_active'] ) : ?>
						<div class="notice notice-error inline">
							<p><?php echo \esc_html__( 'Webmention mode is selected but the Webmention plugin is not active — no replies are arriving.', 'rss-chat-routing' ); ?></p>
						</div>
					<?php endif; ?>
					<?php if ( ! $status['server_support_confirmed'] ) : ?>
						<div class="notice notice-warning inline">
							<p><?php echo \esc_html__( 'The configured rss.chat server has not confirmed Webmention support. Until a server version ships it, replies will not arrive in this mode — the legacy importer stays available.', 'rss-chat-routing' ); ?></p>
						</div>
					<?php endif; ?>
				<?php endif; ?>

				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Format slugs and labels, standard first as "no format".
	 *
	 * @return array<string, string>
	 */
	public static function format_choices() {
		$choices = array(
			'standard' => \__( 'Standard (posts with no format)', 'rss-chat-routing' ),
		);

		foreach ( \get_post_format_slugs() as $slug ) {
			if ( 'standard' === $slug ) {
				continue;
			}
			$choices[ $slug ] = \get_post_format_string( $slug );
		}

		return $choices;
	}

	/**
	 * The three reply-import modes, with plain descriptions.
	 *
	 * @return array
	 */
	public static function reply_import_choices() {
		return array(
			'legacy'     => array(
				'title' => \__( 'Imported comments (legacy)', 'rss-chat-routing' ),
				'help'  => \__( 'RSS Chat polls the server and stores replies as ordinary comments. What existing installs already do.', 'rss-chat-routing' ),
			),
			'webmention' => array(
				'title' => \__( 'Verified Webmentions', 'rss-chat-routing' ),
				'help'  => \__( 'Replies arrive as real Webmentions, verified and moderated by the Webmention plugin. Turns the legacy importer off. Needs the Webmention plugin and an rss.chat server that sends Webmentions.', 'rss-chat-routing' ),
			),
			'disabled'   => array(
				'title' => \__( 'No reply import', 'rss-chat-routing' ),
				'help'  => \__( 'Replies stay on rss.chat. Nothing is imported.', 'rss-chat-routing' ),
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
