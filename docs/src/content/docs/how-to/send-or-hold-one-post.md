---
title: Send or hold one post
description: "Include or exclude a single WordPress post from rss.chat in the block editor or from a Micropub client such as Outpost."
---

Override the sitewide setting for one post. An Include or Exclude choice wins in every send mode.

## In the block editor

1. Open the post.
2. In the post sidebar, open the **rss.chat** panel.
3. Under **Send this post to rss.chat**, pick:
   - **Include in RSS Chat** to send it.
   - **Exclude from RSS Chat** to keep it on your site.
   - The first option to follow the site default again.
4. Publish or update the post.

The line under the options shows the result, for example "Included by this post's override."

With **Ask me on every post** turned on, the same choice also appears in the pre-publish checks, opened while it's still unmade.

## From a Micropub client

Send the `mp-rss-chat-routing` property with `include`, `exclude`, or `inherit`. Leave it out to keep the post's current choice. Field details: [Per-post field](/rss-chat-routing/reference/per-post-field/#micropub-property).

## Things to know

- Choose before the first publish. RSS Chat sends a post the first time it's saved while published, and doesn't update or delete it on rss.chat afterward.
- Excluding a post that's already on rss.chat doesn't remove it there.
- Include can't send a draft, a password-protected post, or a page.

Related: [Choose when posts are sent](/rss-chat-routing/how-to/choose-when-posts-send/)
