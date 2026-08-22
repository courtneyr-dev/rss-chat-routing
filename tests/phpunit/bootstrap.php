<?php
/**
 * Bootstrap: load WordPress, then RSS Chat, then this plugin.
 *
 * The point of these tests is that the two behave correctly together, so the
 * real parent plugin is loaded rather than a stand-in for it.
 *
 * @package RSS_Chat_Routing
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, set WP_TESTS_DIR.\n";
	exit( 1 );
}

$_parent_dir = getenv( 'RSS_CHAT_DIR' );
if ( ! $_parent_dir || ! file_exists( $_parent_dir . '/rss-chat.php' ) ) {
	echo "Could not find rss-chat.php, set RSS_CHAT_DIR to a checkout of pfefferle/wordpress-rss-chat.\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $_parent_dir ) {
		require $_parent_dir . '/rss-chat.php';
		require dirname( __DIR__, 2 ) . '/rss-chat-routing.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
