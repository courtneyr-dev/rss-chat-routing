<?php
/**
 * Test-environment helper only. Never deploy.
 *
 * Lets WordPress talk to the rss.chat server on the Docker host:
 * host.docker.internal resolves to a private address, which
 * wp_http_validate_url and wp_safe_remote_* reject by default.
 *
 * @package RSS_Chat_Routing
 */

add_filter(
	'http_request_host_is_external',
	function ( $external, $host ) {
		return 'host.docker.internal' === $host ? true : $external;
	},
	10,
	2
);

add_filter(
	'http_allowed_safe_ports',
	function ( $ports ) {
		$ports[] = 1420; // The local rss.chat server.
		return $ports;
	}
);
