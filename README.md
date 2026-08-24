# RSS Chat Routing

Chooses which posts go to [rss.chat](https://rss.chat) — by default post format, by default Post Kind, or per post — and brings replies home as real, verified Webmentions.

## Why

[RSS Chat](https://github.com/pfefferle/wordpress-rss-chat) syndicates a post when it carries the core `chat` post format:

```php
if ( 'chat' !== \get_post_format( $post ) ) {
	return;
}
```

The handbook defines that format as "A chat transcript", which describes what a post *contains*. Sending a post to rss.chat is about where it *goes*. Using one for the other means a note has to claim it's a transcript to be syndicated, and Post Formats for Block Themes will badge it as one.

Upstream has been asked whether the check could be filterable: [pfefferle/wordpress-rss-chat#6](https://github.com/pfefferle/wordpress-rss-chat/issues/6).

## What it does

Settings → RSS Chat Routing, plus an **rss.chat** panel in the post sidebar.

**Routing.** Pick up to one default post format and up to one default post kind; either can be empty. A post matching either one is sent by default. Every post keeps a three-state override in the editor — *Use the site default*, *Include in RSS Chat*, *Exclude from RSS Chat* — and an explicit choice always wins. The panel shows the effective result and why ("Included because the post format is Status."). A fresh install defaults to the chat format, which is exactly what RSS Chat does alone, so activating changes nothing.

The decision, in precedence order:

1. Content rss.chat must never syndicate (drafts, other post types, password-protected posts, revisions, autosaves) is never sent, whatever else says.
2. A per-post **exclude** wins.
3. A per-post **include** wins.
4. Otherwise the post is sent when it matches the default format **or** the default kind.
5. Otherwise it stays home.

Micropub clients (Outpost among them) can send the override as `mp-rss-chat-routing: inherit | include | exclude`; omitted means inherit and the server-side decision stays authoritative. The same three states ride the REST API (`meta._rss_chat_routing`), where invalid values are rejected with a 400 rather than coerced.

**Replies.** Settings → *Replies from rss.chat* picks one of three modes:

- **Imported comments (legacy)** — RSS Chat's own importer, unchanged. The default; existing installs keep their behavior until you choose otherwise.
- **Verified Webmentions** — replies arrive as real Webmentions sent by the rss.chat server and verified by the [Webmention plugin](https://wordpress.org/plugins/webmention/), which owns moderation, display, and updates. The legacy importer is switched off, and a Webmention whose source matches a reply the legacy importer already stored is rejected, so one remote reply can never become two comments. Needs an rss.chat server that sends Webmentions (see the companion server patch); the settings screen says so plainly until one exists.
- **No reply import** — replies stay on rss.chat.

**Isolation.** Comments that arrived from another network — Webmentions, ActivityPub replies, ATmosphere/Bluesky reactions, pingbacks — are never pushed back out to rss.chat, and rss.chat replies imported as comments are never federated onward (ActivityPub and ATmosphere already refuse foreign comments on their own; this plugin closes the rss.chat direction).

**Permalinks.** Every pushed item carries the post's canonical WordPress permalink in the rss.chat item's `link` field, which is what lets the server point a reply's Webmention back at the right post.

## Migrating from 0.1.x

Nothing to do. The old `format` / `all` / `none` option shape is read in place: `format` becomes the chat default format, `none` becomes no defaults, and the first ticked kind becomes the default kind. An install still on `all` keeps sending everything until the settings screen is saved once — the screen says so. Stored per-post choices (`1`/`0`) keep their meaning forever. Settings changes only affect future publishes; nothing is bulk-syndicated, and excluding an already-synced post neither deletes nor re-sends the remote item.

## How it works

Where the parent plugin exposes filters (`rss_chat_should_syndicate`, `rss_chat_post_item`, `rss_chat_should_push_comment`, `rss_chat_backfeed_enabled`), this plugin answers them directly. Until those land upstream, stand-ins produce the same result against the stock parent:

- `Router` sits either side of the actions RSS Chat syndicates on and answers `get_post_format()` for that one post, for the length of that one call, via the `get_the_terms` filter. Nothing is stored and nothing is cached wrongly.
- `Link_Shim` re-issues the parent's own `/newpost` request with the permalink added, only for requests to the configured server, only while a post push is in flight.
- `Comment_Gate` answers the parent's "already synced?" question with a sentinel for foreign comments, so inbound interactions are never re-broadcast.
- `Micropub` re-evaluates at `after_micropub` priority 40 — after Outpost's bridges (20) and Post Kinds (30) have set format and kind, which the parent's own hooks fire too early to see — and asks the parent to push. The parent's already-synced guard keeps it idempotent.

The test suite runs green against both the stock and the patched parent.

## Tests

The PHPUnit suite loads the real RSS Chat plugin and asserts on whether it actually pushed:

```bash
composer install
WP_TESTS_DIR=~/.wp-tests/wordpress-tests-lib \
RSS_CHAT_DIR=/path/to/wordpress-rss-chat \
vendor/bin/phpunit
```

The editor panel has a headless harness that drives the real script through a stubbed `wp` global:

```bash
node tests/js/panel-harness.js assets/js/editor.js
```

`tests/e2e-env/` holds two wp-env harnesses: `run-webmention-e2e.sh` proves the full round trip (status post → rss.chat item with permalink → reply → verified Webmention comment, idempotent, no echo, no legacy duplicate) against a local patched rss.chat server, and `combo/run-combo-e2e.sh` runs the routing decision with RSS Chat, Webmention, ActivityPub, IndieWeb, ATmosphere, IndieAuth, Micropub, Post Formats for Block Themes, and Post Kinds for IndieWeb all active at once.

## Requires

WordPress 6.6, PHP 7.4, and the RSS Chat plugin. Post Kinds for IndieWeb is optional; without it the kind default is hidden and a stored kind default fails safe. The Webmention plugin is required only for the Verified Webmentions reply mode.
