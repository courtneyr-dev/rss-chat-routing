---
title: Per-post field
description: "The per-post rss.chat choice as post meta, a REST API field, and a Micropub property: names, accepted values, and what each value does."
---

Each post stores its own rss.chat choice in post meta. The editor panel, the REST API, and Micropub clients all write the same field.

## Values

| Value | Editor label | Effect |
|---|---|---|
| `''` or no meta | Use the site default | The sitewide send mode decides |
| `include` | Include in RSS Chat | Sent, in every send mode |
| `exclude` | Exclude from RSS Chat | Held, in every send mode |

Posts saved by version 0.1.x may hold `1` or `0`. They're still read as `include` and `exclude`.

An Include can't send a draft, a password-protected post, or a page. See [Routing decision](/rss-chat-routing/reference/routing-decision/).

## Post meta

| Item | Value |
|---|---|
| Meta key | `_rss_chat_routing` |
| Post type | `post` |
| Type | string, single |
| Who can write it | users who can `edit_post` that post |

```sh
wp post meta get 123 _rss_chat_routing
wp post meta update 123 _rss_chat_routing include
```

## REST API

The field appears under `meta` on `/wp/v2/posts`.

```http
POST /wp-json/wp/v2/posts/123
Content-Type: application/json

{ "meta": { "_rss_chat_routing": "exclude" } }
```

Accepted values: `''`, `inherit`, `include`, `exclude`. `inherit` is stored as `''`. Any other value is rejected with HTTP 400 instead of being changed to something else.

## Micropub property

Micropub clients, including [Outpost](https://courtneyr-dev.github.io/outpost/), send `mp-rss-chat-routing`.

| Sent value | Result |
|---|---|
| `include` or `exclude` | Stored on the post |
| `inherit` | Removes the post's choice, so the site default decides |
| Property left out | No change: a new post follows the site default, an updated post keeps its earlier choice |
| Any other value | Ignored |

JSON and form-encoded requests both work:

```json
{
  "type": ["h-entry"],
  "properties": {
    "content": ["Hello from my phone"],
    "mp-rss-chat-routing": ["include"]
  }
}
```

```text
h=entry&content=Hello+from+my+phone&mp-rss-chat-routing=include
```

Related: [Send or hold one post](/rss-chat-routing/how-to/send-or-hold-one-post/)
