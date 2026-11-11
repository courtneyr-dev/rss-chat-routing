---
title: Route posts by format or kind
description: "Send WordPress posts to rss.chat automatically when they have a chosen post format, such as Status, or a chosen Post Kind, such as Note."
---

Send one kind of post to rss.chat without marking each one, for example every Status post or every Note.

## Before you start

- For a kind default, [Post Kinds for IndieWeb](https://github.com/courtneyr-dev/post-kinds-for-indieweb) is active. Format defaults work with WordPress alone.

## Steps

1. Go to **Settings → RSS Chat Routing**.
2. Under **When to send posts**, pick **Decide by post format and kind**.
3. Under **Default post format**, pick a format, or **No format default**. Pick **Standard** to match posts with no format.
4. Under **Default post kind**, pick a kind, or **No kind default**.
5. Click **Save Changes**.

A post is sent when it matches the format **or** the kind. You don't need both. A post's own Include or Exclude still wins.

## Example

With **Status** as the default format and **Note** as the default kind:

| Post | Sent? |
|---|---|
| Status format, any kind | yes |
| Standard format, Note kind | yes |
| Image format, Photo kind | no |
| Status format, marked Exclude | no |

## Things to know

- Picking a default other than **Chat** means chat-format posts are no longer sent just for being chat posts.
- If your theme or a plugin assigns post formats automatically (Post Formats for Block Themes can, when you pick none), a format default can match posts you didn't mean to send. A kind default avoids that.

Related: [Settings reference](/rss-chat-routing/reference/settings/#default-post-format) · [Routing decision](/rss-chat-routing/reference/routing-decision/)
