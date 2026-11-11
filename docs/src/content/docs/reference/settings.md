---
title: Settings
description: "Every RSS Chat Routing setting: the screen label, the stored key and values, the default, and what each one changes."
---

RSS Chat Routing has one settings screen at **Settings → RSS Chat Routing**, separate from RSS Chat's own **Settings → RSS Chat** screen. It needs the `manage_options` capability (administrators). Changes affect posts published after you save; nothing already sent is re-sent or removed.

All settings are stored in one option, `rss_chat_routing`, as an array.

## When to send posts

Decides what happens to a post that has no Include or Exclude choice of its own.

| Screen label | Stored `send_mode` | An unmarked post is |
|---|---|---|
| Only posts I opt in | `opt_in` | held |
| Every post unless I opt it out | `opt_out` | sent |
| Decide by post format and kind | `rules` | sent when it matches the default format or the default kind |
| Ask me on every post | `per_post` | held until you choose Include or Exclude |

Default: `rules`. An unknown value is saved as `rules`. A site still on the 0.1.x `all` option reads as `opt_out`; see [Upgrade from 0.1.x or the chat format](/rss-chat-routing/how-to/upgrade-from-0-1-or-chat-format/).

## Default post format

Used only with **Decide by post format and kind**. Posts with this format are sent.

| Value | Matches |
|---|---|
| `''` (No format default) | nothing |
| `standard` | posts with no post format |
| `aside`, `chat`, `gallery`, `link`, `image`, `quote`, `status`, `video`, `audio` | posts with that core post format |

Stored key: `default_format`. Default: `chat`, which is what RSS Chat does without this plugin. An unknown format slug is saved as `''`.

With a default other than `chat`, a chat-format post is no longer sent just because of its format.

## Default post kind

Used only with **Decide by post format and kind**, and shown only when [Post Kinds for IndieWeb](https://github.com/courtneyr-dev/post-kinds-for-indieweb) is active. Posts with this kind are sent, whatever their post format.

Stored key: `default_kind` (a `kind` term slug, or `''` for no kind default). Default: `''`.

If Post Kinds is deactivated or the kind is deleted, the kind default never matches. Nothing errors.

## Replies from rss.chat

How replies on rss.chat come back to your site.

| Screen label | Stored `reply_import` | Behavior |
|---|---|---|
| Imported comments (legacy) | `legacy` | RSS Chat's own importer checks the server on a schedule and stores replies as comments, held for moderation. |
| Verified Webmentions | `webmention` | The legacy importer is off. Replies arrive as Webmentions, verified by the Webmention plugin. |
| No reply import | `disabled` | Nothing is imported. Replies stay on rss.chat. |

Default: `legacy`. Before choosing `webmention`, read [Get replies as Webmentions](/rss-chat-routing/how-to/get-replies-as-webmentions/): rss.chat servers don't send Webmentions yet.

## Stored option example

```json
{
  "send_mode": "opt_in",
  "default_format": "status",
  "default_kind": "",
  "reply_import": "webmention",
  "legacy_all": false
}
```

`legacy_all` is a migration flag for 0.1.x sites. You don't set it. Saving the settings screen clears it.

To read the option with WP-CLI:

```sh
wp option get rss_chat_routing --format=json
```

Related: [Routing decision](/rss-chat-routing/reference/routing-decision/) · [Per-post field](/rss-chat-routing/reference/per-post-field/)
