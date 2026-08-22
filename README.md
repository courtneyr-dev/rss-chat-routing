# RSS Chat Routing

Chooses which posts go to [rss.chat](https://rss.chat), so the `chat` post format doesn't have to.

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

Site default, one of:

- **Only posts with the chat post format** — what RSS Chat does alone. This is the default, so activating changes nothing.
- **Every published post, unless the post says otherwise**
- **Nothing, unless a kind or the post opts in**

On top of that, tick any Post Kinds that should always be sent whatever their format. A per-post choice (follow the site setting / always / never) beats everything else.

## How it works

Until upstream gains a filter, this plugin sits either side of the actions RSS Chat syndicates on (`wp_after_insert_post`, `rest_after_insert_post`, both at priority 10) and answers `get_post_format()` for that one post, for the length of that one call, via the `get_the_terms` filter.

It only engages when the answer would differ from RSS Chat's own conclusion, the stored post format is never modified, and core populates the term cache before running that filter, so nothing is cached wrongly. The same stand-in runs on `rss2_item`, for posts that really were syndicated, so their feed items keep the `source:markdown` and `source:comments` elements.

If the filter lands upstream, `Router` is the only class that needs replacing.

## Tests

The suite loads the real RSS Chat plugin and asserts on whether it actually pushed.

```bash
composer install
WP_TESTS_DIR=~/.wp-tests/wordpress-tests-lib \
RSS_CHAT_DIR=/path/to/wordpress-rss-chat \
vendor/bin/phpunit
```

## Requires

WordPress 6.6, PHP 7.4, and the RSS Chat plugin. Post Kinds for IndieWeb is optional; without it the kinds section is hidden.
