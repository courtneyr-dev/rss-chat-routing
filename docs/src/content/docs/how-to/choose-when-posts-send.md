---
title: Choose when posts are sent
description: "Pick whether WordPress posts go to rss.chat only when you opt in, unless you opt out, by post format and kind, or by asking on every post."
---

Use the **When to send posts** setting to decide what happens to a post you haven't marked Include or Exclude.

## Before you start

- You're an administrator on the site.
- RSS Chat and RSS Chat Routing are active, and RSS Chat is signed in to rss.chat.

## Steps

1. In wp-admin, go to **Settings → RSS Chat Routing**.
2. Under **When to send posts**, pick one:
   - **Only posts I opt in** if most posts should stay on your site.
   - **Every post unless I opt it out** if most posts should go to rss.chat.
   - **Decide by post format and kind** if one kind of post (for example, status updates) should go. Then set the default format or kind further down the page; see [Route posts by format or kind](/rss-chat-routing/how-to/route-by-format-or-kind/).
   - **Ask me on every post** if you want to decide each time. The editor shows the choice in the pre-publish checks until you pick.
3. Click **Save Changes**.

The new mode applies to posts you publish from now on. Posts already on rss.chat stay there, and held posts aren't sent until you update them.

## Check the result

Open any post in the editor and look at the **rss.chat** panel in the post sidebar. The first option names what the site default does, for example "Use the site default (don't send)," and the line under the options says whether this post will be sent and why.

## With WP-CLI

To set the mode without the settings screen, add the key on a site that has never saved it:

```sh
wp option patch insert rss_chat_routing send_mode opt_in
```

Or change it on a site that already has it:

```sh
wp option patch update rss_chat_routing send_mode opt_in
```

Values: `opt_in`, `opt_out`, `rules`, `per_post`. Both commands keep your other settings.

Related: [Settings reference](/rss-chat-routing/reference/settings/) · [Send or hold one post](/rss-chat-routing/how-to/send-or-hold-one-post/)
