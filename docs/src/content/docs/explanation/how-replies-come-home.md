---
title: How replies come home
description: "How replies on rss.chat get back to a WordPress post, why Webmentions are the target design, and how the plugin stops replies from echoing between networks."
---

Sending a post to rss.chat is half of the loop. The other half is getting replies back to the post on your site, where your readers see them.

## Two ways back

**Polling (the legacy importer).** RSS Chat asks the rss.chat server for new replies to each of your sent posts every few minutes and stores each one as a comment. It works with any rss.chat server today. The trade-off: your site trusts what the server returns, and it has to keep asking.

**Webmentions.** [Webmention](https://www.w3.org/TR/webmention/) is a W3C recommendation for one site telling another "I linked to you." In this design, the rss.chat server sends your site a Webmention when someone replies. Your site's Webmention plugin fetches the reply's public page, checks it really links to your post, and stores it as a comment. Your site only accepts what it can verify, and nothing polls.

## What has to be in place for Webmentions

Each piece is owned by a different project:

1. **Your post has to link home.** RSS Chat Routing adds your post's permalink as the `link` on the rss.chat item, so a reply knows which WordPress post it answers. RSS Chat does this itself since 2026-09-11.
2. **The reply needs a public page.** The server has to publish each reply as an HTML page with microformats that point at your post.
3. **The server has to send the Webmention.** Items 2 and 3 are proposed in [scripting/rss.chat pull request #23](https://github.com/scripting/rss.chat/pull/23), still open on 2026-11-11.
4. **Your site has to receive it.** The [Webmention](https://wordpress.org/plugins/webmention/) plugin verifies, moderates, and stores the reply.

That's why **Imported comments (legacy)** stays the default: the Webmention path is ready on the WordPress side and waiting on the server side.

## One reply, one comment

Switching modes can't duplicate a reply. In Webmention mode the legacy importer is off, and a Webmention for a reply the importer already stored is rejected, because the plugin matches the reply's page address against the address the importer recorded.

## Keeping networks from echoing

A WordPress site that also runs ActivityPub, ATmosphere (Bluesky), or Webmention receives comments from several networks. Without care, a reply that arrived from one network could be pushed out to another, and come back again.

RSS Chat Routing stops comments that arrived from another network (a Webmention, an ActivityPub reply, a Bluesky reaction, a pingback) from being pushed to rss.chat. In the other direction, ActivityPub and ATmosphere already refuse to send comments that weren't written by a logged-in user of your site, which covers replies imported from rss.chat. Comments you write yourself on a sent post still go to rss.chat as replies.

Related: [Get replies as Webmentions](/rss-chat-routing/how-to/get-replies-as-webmentions/) · [Why routing is a separate plugin](/rss-chat-routing/explanation/why-routing-is-separate/)
