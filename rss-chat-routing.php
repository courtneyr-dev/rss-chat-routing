<?php
/**
 * Plugin Name:       RSS Chat Routing
 * Plugin URI:        https://github.com/courtneyr-dev/rss-chat-routing
 * Description:       Choose which posts go to rss.chat by default post format, default Post Kind, or per post — and bring replies home as verified Webmentions.
 * Version:           0.2.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Courtney Robertson
 * Author URI:        https://courtneyr.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rss-chat-routing
 *
 * @package RSS_Chat_Routing
 */

namespace RSS_Chat_Routing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.2.0';
const FILE    = __FILE__;

require_once __DIR__ . '/includes/class-rules.php';
require_once __DIR__ . '/includes/class-router.php';
require_once __DIR__ . '/includes/class-link-shim.php';
require_once __DIR__ . '/includes/class-comment-gate.php';
require_once __DIR__ . '/includes/class-reply-import.php';
require_once __DIR__ . '/includes/class-micropub.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-editor.php';

/**
 * Boot the plugin once the parent is known to be loaded.
 *
 * @return void
 */
function bootstrap() {
	Rules::init();
	Settings::init();
	Micropub::init();

	if ( ! is_parent_active() ) {
		\add_action( 'admin_notices', __NAMESPACE__ . '\\parent_missing_notice' );
		return;
	}

	Router::init();
	Link_Shim::init();
	Comment_Gate::init();
	Reply_Import::init();
	Micropub::init_push();
	Editor::init();
}
\add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20 );

/**
 * Whether the RSS Chat plugin is loaded.
 *
 * @return bool
 */
function is_parent_active() {
	return \class_exists( '\\RSS_Chat\\Plugin' );
}

/**
 * Tell the admin the parent plugin is missing.
 *
 * @return void
 */
function parent_missing_notice() {
	if ( ! \current_user_can( 'activate_plugins' ) ) {
		return;
	}

	\wp_admin_notice(
		\esc_html__( 'RSS Chat Routing needs the RSS Chat plugin to be active. Nothing is being routed until it is.', 'rss-chat-routing' ),
		array( 'type' => 'warning' )
	);
}
