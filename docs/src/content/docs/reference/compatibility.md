---
title: Compatibility
description: "What RSS Chat Routing 0.3.0 requires, which plugins it works with, and which RSS Chat filters it answers."
---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 6.6 or later |
| PHP | 7.4 or later |
| [RSS Chat](https://github.com/pfefferle/wordpress-rss-chat) | required; the test suite runs against its `main` branch |

Without RSS Chat active, the settings screen shows a warning and nothing is sent.

## Optional plugins

| Plugin | What it adds |
|---|---|
| [Post Kinds for IndieWeb](https://github.com/courtneyr-dev/post-kinds-for-indieweb) | The **Default post kind** setting |
| [Webmention](https://wordpress.org/plugins/webmention/) | The **Verified Webmentions** reply mode |
| [Micropub](https://wordpress.org/plugins/micropub/) and [Outpost](https://courtneyr-dev.github.io/outpost/) | The `mp-rss-chat-routing` property, decided after Outpost and Post Kinds set format and kind |
| [Post Formats for Block Themes](https://github.com/courtneyr-dev/post-formats-for-block-themes) | Not required; core post formats work alone. Note that its format detector can assign a format when you pick none, which affects **Decide by post format and kind**. |

Tested together with ActivityPub, ATmosphere, IndieAuth, and IndieWeb active at the same time (`tests/e2e-env/combo/run-combo-e2e.sh`).

## RSS Chat filters this plugin answers

This plugin defines no actions or filters of its own. It answers four of RSS Chat's:

| RSS Chat filter | What RSS Chat Routing returns |
|---|---|
| `rss_chat_should_syndicate` | The [routing decision](/rss-chat-routing/reference/routing-decision/) for the post |
| `rss_chat_post_item` | The item with `link` set to the post's permalink |
| `rss_chat_should_push_comment` | False for comments that arrived from another network |
| `rss_chat_backfeed_enabled` | False unless the reply mode is **Imported comments (legacy)** |

These filters are in RSS Chat's `main` branch since 2026-09-11. For an older copy of RSS Chat without them, the plugin includes stand-ins that produce the same results; the [README](https://github.com/courtneyr-dev/rss-chat-routing#how-it-works) describes how they work.

## Removing the plugin

Deactivating RSS Chat Routing returns RSS Chat to its own rule: only chat-format posts are sent. The `rss_chat_routing` option and the `_rss_chat_routing` post meta stay in the database.
