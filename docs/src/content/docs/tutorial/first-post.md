---
title: Send your first post to rss.chat
description: "Set up RSS Chat and RSS Chat Routing on a WordPress site, choose to send only posts you opt in, and send one post to rss.chat."
---

In this tutorial you connect a WordPress site to rss.chat, set it to send only the posts you choose, and send one post. It takes about 15 minutes.

You'll need:

- A WordPress 6.6 or later site where you're an administrator and can install plugins from a ZIP file.
- An account on the rss.chat server you'll use, registered with your site's admin email address, and access to that inbox. RSS Chat signs in with that address and can't create the account for you.

:::note
Steps 2 and 6 talk to a live rss.chat server. They follow RSS Chat's own instructions and haven't been run as part of testing these docs. The screen text quoted in steps 3 to 5 comes from the plugin's code and is checked by its automated tests; the steps haven't been clicked through in a browser.
:::

## 1. Install both plugins

1. Download the RSS Chat plugin from [github.com/pfefferle/wordpress-rss-chat](https://github.com/pfefferle/wordpress-rss-chat): choose **Code → Download ZIP**.
2. Download RSS Chat Routing from [github.com/courtneyr-dev/rss-chat-routing](https://github.com/courtneyr-dev/rss-chat-routing) the same way.
3. In wp-admin, go to **Plugins → Add New Plugin → Upload Plugin**, upload each ZIP, and activate both.

Go to **Plugins** and check that **RSS Chat** and **RSS Chat Routing** both show as active.

## 2. Sign in to rss.chat

1. Go to **Settings → RSS Chat**.
2. Click **Send login link**.
3. Open the email rss.chat sends to your admin address and follow the link.

Your site is now one identity on rss.chat. By default it uses the public server at `https://demo.rss.chat`.

If the screen says "No account was found," create an account with your admin email on the server it links to, then click **Send login link** again.

## 3. Send only posts you choose

1. Go to **Settings → RSS Chat Routing**.
2. Under **When to send posts**, pick **Only posts I opt in**.
3. Click **Save Changes**.

From now on, nothing goes to rss.chat unless you say so.

## 4. Write a post and look at the rss.chat panel

1. Go to **Posts → Add New Post**.
2. Give it a title and a sentence of text.
3. In the post sidebar, open the **rss.chat** panel.

The panel shows three options. The first reads **Use the site default (don't send)**, and the line underneath says "Not sent: the site sends only posts you include." That's the setting from step 3 at work.

## 5. Include the post

In the **rss.chat** panel, pick **Include in RSS Chat**. The line underneath changes to "Included by this post's override."

## 6. Publish and find it on rss.chat

1. Click **Publish**.
2. Open [rss.chat](https://rss.chat/) (or your server) and find your post in the timeline.

When someone replies, the reply comes back to your site as a comment held for moderation, under **Comments → Pending**.

## What you did

You connected your site to rss.chat, set it to send nothing by default, and sent one post by including it. Every later post stays on your site unless you pick **Include in RSS Chat**.

## Next steps

- [Route posts by format or kind](/rss-chat-routing/how-to/route-by-format-or-kind/) if one kind of post should always go.
- [Get replies as Webmentions](/rss-chat-routing/how-to/get-replies-as-webmentions/) to choose how replies arrive.
- [rss.chat resources](/rss-chat-routing/reference/rss-chat-resources/) to learn more about the network.
