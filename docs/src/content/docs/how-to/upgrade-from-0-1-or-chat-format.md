---
title: Upgrade from 0.1.x or the chat format
description: "Move a WordPress site from RSS Chat's chat-format rule or RSS Chat Routing 0.1.x settings to 0.3.0 without changing which posts are sent."
---

Activating or upgrading RSS Chat Routing keeps sending the same posts until you change a setting.

## From RSS Chat alone

1. Install and activate RSS Chat Routing.
2. Go to **Settings → RSS Chat Routing**. The mode is **Decide by post format and kind** with **Chat** as the default format, which is RSS Chat's own rule.
3. Change the mode if you want; see [Choose when posts are sent](/rss-chat-routing/how-to/choose-when-posts-send/).

## From RSS Chat Routing 0.1.x

Version 0.1.x stored a `mode` of `format`, `all`, or `none`, plus a list of kinds. Version 0.3.0 reads it in place:

| 0.1.x setting | Reads as in 0.3.0 |
|---|---|
| `format` | Decide by post format and kind, default format **Chat** |
| `none` | Decide by post format and kind, no defaults (nothing sent unless included) |
| `all` | Every post unless I opt it out |
| first kind in the list | the default kind |

Per-post choices stored as `1` and `0` still mean Include and Exclude.

## Steps

1. Update the plugin.
2. Go to **Settings → RSS Chat Routing** and check the selected mode matches what you expect.
3. Click **Save Changes** to store the setting in the 0.3.0 format. Saving keeps whichever mode is selected.

Nothing is sent or re-sent when you upgrade or save. Settings apply to posts published afterward.

Related: [Settings reference](/rss-chat-routing/reference/settings/)
