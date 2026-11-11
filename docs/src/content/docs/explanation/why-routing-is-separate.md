---
title: Why routing is a separate plugin
description: "Why RSS Chat Routing moves the decision about which posts go to rss.chat out of the chat post format and into its own setting."
---

RSS Chat uses one signal to decide what goes to rss.chat: the post has the **chat** post format. That's a clean opt-in for a site that only posts to rss.chat. It gets crowded on a site that also publishes to other networks.

## One switch doing two jobs

A post format describes what a post is: a status update, an image, a quote. Themes use it to pick a layout, and feeds and other plugins read it as metadata. When the format is also the "send to rss.chat" switch, you can't have a status post that stays home or an image post that goes out without changing how the post looks and is described everywhere else.

It gets less predictable when formats are assigned for you. Post Formats for Block Themes, for example, picks a format from the blocks in a post when you don't choose one. A site that relied on the chat format alone could send posts nobody explicitly sent.

## What the plugin separates

RSS Chat Routing keeps the format as a description and moves the sending decision into its own place:

- A sitewide **send mode**, so "never unless I say so" and "always unless I say so" are one choice each.
- Optional format and kind defaults, for sites where a kind of post really does mean "send this."
- A per-post Include or Exclude that always wins, available in the editor, the REST API, and Micropub clients.

One function makes the decision for every path that can publish a post, so the editor, a phone app, and a script can't disagree about whether a post goes out.

## Why not change RSS Chat itself

Part of it did change. RSS Chat added the `rss_chat_should_syndicate` filter (merged 2026-09-11), so another plugin can answer "should this post go?" without RSS Chat having to know about send modes, Post Kinds, or Micropub. RSS Chat stays small and owns the connection to rss.chat; RSS Chat Routing owns the decision. Either can change without the other.

Related: [Routing decision](/rss-chat-routing/reference/routing-decision/) · [How replies come home](/rss-chat-routing/explanation/how-replies-come-home/)
