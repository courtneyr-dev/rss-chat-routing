---
title: RSS Chat Routing
description: "Documentation for RSS Chat Routing: choose which WordPress posts go to rss.chat and bring replies home as verified Webmentions."
---

RSS Chat Routing is a WordPress plugin that decides which of your posts go to [rss.chat](https://rss.chat/), the chat network built on RSS 2.0 feeds. It works alongside the [RSS Chat](https://github.com/pfefferle/wordpress-rss-chat) plugin, which does the actual sending, and adds a sitewide rule, a per-post choice, and a way to receive replies as Webmentions.

## What it adds to RSS Chat

On its own, RSS Chat sends a post when it has the **chat** post format. RSS Chat Routing replaces that one switch with a setting you choose under **Settings → RSS Chat Routing**:

- **Only posts I opt in:** nothing is sent until you choose Include on a post.
- **Every post unless I opt it out:** every published post is sent unless you choose Exclude.
- **Decide by post format and kind:** posts with a default format or [Post Kind](https://github.com/courtneyr-dev/post-kinds-for-indieweb) are sent. This is the default.
- **Ask me on every post:** the editor asks you to choose before you publish.

Each post also gets an **rss.chat** panel in the editor sidebar, where an Include or Exclude choice overrides the sitewide setting.

## Start here

- [Send your first post to rss.chat](/rss-chat-routing/tutorial/first-post/) walks through setup end to end.
- [Choose when posts are sent](/rss-chat-routing/how-to/choose-when-posts-send/) covers the setting most sites change first.
- [Find out why a post didn't send](/rss-chat-routing/how-to/find-out-why-a-post-did-not-send/) helps when a post went somewhere you didn't expect.

## The rest of the docs

- **How-to guides** cover one task each: holding a single post, routing by format or kind, receiving replies as Webmentions, and upgrading from the chat format.
- **Reference** lists every setting, the order the plugin checks rules in, the per-post field for REST and Micropub clients, and requirements. [rss.chat resources](/rss-chat-routing/reference/rss-chat-resources/) links the official rss.chat documentation.
- **Explanation** covers [why routing is a separate plugin](/rss-chat-routing/explanation/why-routing-is-separate/) and [how replies come home](/rss-chat-routing/explanation/how-replies-come-home/).

## Requirements

Version 0.3.0 needs WordPress 6.6 or later, PHP 7.4 or later, and the RSS Chat plugin. Post Kinds for IndieWeb and the Webmention plugin are optional. Details: [Compatibility](/rss-chat-routing/reference/compatibility/).

## Source code and help

The plugin is developed at [github.com/courtneyr-dev/rss-chat-routing](https://github.com/courtneyr-dev/rss-chat-routing). It isn't in the WordPress.org plugin directory. [Report an issue](https://github.com/courtneyr-dev/rss-chat-routing/issues) on GitHub.
