---
title: Routing decision
description: "The order RSS Chat Routing checks rules in to decide whether a post goes to rss.chat, and every reason code it reports."
---

Every path that can send a post (the block editor, the classic editor, the REST API, Micropub clients such as Outpost, and programmatic `wp_insert_post` calls) asks one function, `RSS_Chat_Routing\Rules::decision( $post )`. It returns `send` (true or false) and a `reason`.

## Precedence

The first rule that applies wins.

1. **Ineligible content is never sent.** The post must be a published `post` with no password, and not a revision or autosave. Pages and custom post types are never sent while this plugin is active, even if they're ticked in RSS Chat's own settings.
2. **A per-post Exclude holds the post.**
3. **A per-post Include sends the post.**
4. **Otherwise the sitewide send mode decides:**
   - `opt_out` sends.
   - `opt_in` holds.
   - `per_post` holds.
   - `rules` sends when the post's format equals the default format, or the post has the default kind.
5. **Otherwise the post is held.**

## Reason codes

| `reason` | `send` | Meaning |
|---|---|---|
| `ineligible` | false | Not a published, unprotected `post`, or a revision or autosave |
| `override-exclude` | false | The post's own choice is Exclude |
| `override-include` | true | The post's own choice is Include |
| `opt-out-default` | true | Send mode is `opt_out` and the post has no choice |
| `legacy-all` | true | Same as `opt-out-default`, on a site still storing the 0.1.x `all` option |
| `opt-in-default` | false | Send mode is `opt_in` and the post has no choice |
| `needs-choice` | false | Send mode is `per_post` and the post has no choice |
| `format-default` | true | Send mode is `rules` and the post format matches the default |
| `kind-default` | true | Send mode is `rules` and the post kind matches the default |
| `no-match` | false | Send mode is `rules` and neither default matches |

To see the reason for a post, see [Find out why a post didn't send](/rss-chat-routing/how-to/find-out-why-a-post-did-not-send/).

## When the decision runs

RSS Chat sends a post the first time it's saved while published, and then remembers the rss.chat item id in post meta. After that:

- Later edits stay local. rss.chat keeps the first version.
- Changing a setting or a post's choice doesn't re-send, delete, or update an item that's already on rss.chat.
- A post that was held can still be sent later: update it after changing its choice or the setting.

Posts from Micropub clients are decided after the Micropub request finishes, so post format and kind set by Outpost or Post Kinds count.

Related: [Settings](/rss-chat-routing/reference/settings/) · [Per-post field](/rss-chat-routing/reference/per-post-field/)
