---
title: Find out why a post didn't send
description: "Diagnose why a WordPress post did or didn't reach rss.chat, using the editor panel or WP-CLI to read RSS Chat Routing's decision."
---

When a post went to rss.chat and you didn't expect it, or didn't go when you did, read the decision the plugin made.

## In the editor

Open the post and look at the **rss.chat** panel in the post sidebar. The line under the options states the outcome and the reason, for example "Not sent: the site sends only posts you include."

## With WP-CLI

Print the decision for one post, replacing `123` with the post ID:

```sh
wp eval '$d = RSS_Chat_Routing\Rules::decision( get_post( 123 ) ); echo $d["reason"], " ", $d["send"] ? "send" : "hold", PHP_EOL;'
```

Look the reason up in [Routing decision](/rss-chat-routing/reference/routing-decision/#reason-codes).

## Common causes

| Symptom | Likely cause | Fix |
|---|---|---|
| Post held with `opt-in-default` | Mode is **Only posts I opt in** | Mark the post Include, then update it |
| Post held with `needs-choice` | Mode is **Ask me on every post** and no choice was made | Pick Include or Exclude, then update it |
| Post held with `no-match` | Its format and kind don't match the defaults | Change the defaults or mark it Include |
| Post held with `ineligible` | It's a draft, password-protected, a page, or a custom post type | Publish it as a `post` without a password |
| Decision says send but nothing is on rss.chat | RSS Chat isn't signed in, or RSS Chat isn't active | Check **Settings → RSS Chat** |
| Edits don't show on rss.chat | RSS Chat sends only the first version | Expected; rss.chat keeps the first version |
| Chat-format post no longer sends | Default format isn't **Chat**, or the mode isn't **Decide by post format and kind** | Pick **Chat** or mark the post Include |

## Check the stored setting

```sh
wp option get rss_chat_routing --format=json
```

A site that shows `{"mode":"all","kinds":[]}` is still on the 0.1.x format and sends every post; see [Upgrade from 0.1.x](/rss-chat-routing/how-to/upgrade-from-0-1-or-chat-format/).

Still stuck? [Open an issue](https://github.com/courtneyr-dev/rss-chat-routing/issues) with the reason code and the stored setting.
