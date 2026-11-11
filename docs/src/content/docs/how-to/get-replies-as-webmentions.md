---
title: Get replies as Webmentions
description: "Choose how replies on rss.chat come back to WordPress: imported comments, verified Webmentions, or no import, and what each mode needs."
---

Choose how replies to your posts on rss.chat reach your site.

## Pick a mode

| Mode | Use it when |
|---|---|
| **Imported comments (legacy)** | You want replies on your site today. RSS Chat checks for replies on a schedule and stores them as comments held for moderation. |
| **Verified Webmentions** | Your rss.chat server sends Webmentions and you run the Webmention plugin. |
| **No reply import** | Replies should stay on rss.chat. |

:::caution[rss.chat servers don't send Webmentions yet]
Webmention support for the rss.chat server is proposed in [scripting/rss.chat pull request #23](https://github.com/scripting/rss.chat/pull/23), which was still open on 2026-11-11. Until a server you use ships it, **Verified Webmentions** mode receives no replies. The settings screen shows a warning for this.
:::

## Switch to Verified Webmentions

1. Install and activate the [Webmention](https://wordpress.org/plugins/webmention/) plugin.
2. Go to **Settings → RSS Chat Routing**.
3. Under **Replies from rss.chat**, pick **Verified Webmentions**.
4. Click **Save Changes**.

Saving turns RSS Chat's own reply importer off. A Webmention for a reply that the importer already stored as a comment is rejected, so one reply never becomes two comments.

If the Webmention plugin isn't active, the settings screen shows an error and no replies arrive.

## Moderate imported replies

In **Imported comments (legacy)** mode, imported replies and likes are held for moderation. Approve them under **Comments → Pending**. No moderation email is sent for them.

Rejecting an imported reply doesn't stick in this mode: the next import run can't see spam, trashed, or deleted comments and imports the reply again.

Related: [How replies come home](/rss-chat-routing/explanation/how-replies-come-home/) · [Settings reference](/rss-chat-routing/reference/settings/#replies-from-rsschat)
