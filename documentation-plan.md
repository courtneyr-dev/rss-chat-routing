# Documentation plan: RSS Chat Routing

Persistent plan for the docs site. Lives at the repo root, outside the published `docs/` tree.

## Header

Run date: 2026-11-11 · Output: Astro Starlight site in `docs/`, published to GitHub Pages at `https://courtneyr-dev.github.io/rss-chat-routing/` by a `docs.yml` Actions workflow, matching Link Extension for XFN, Post Kinds, Post Formats and Outpost · Reference generator: none (no PHP docblock generator; the plugin defines no public functions or hooks of its own), so reference is hand-written from source.

## Proposed documents

### Reference: approved scope **the plugin's own surface only**

Settings, the per-post field, routing reasons, and the four RSS Chat filters it answers. RSS Chat's and rss.chat's own APIs are linked, not documented here.

| Document (path) | Need served | Source material |
|---|---|---|
| `reference/settings.md` | Look up every setting, its stored key, values and default | `includes/class-rules.php`, `includes/class-settings.php` |
| `reference/routing-decision.md` | Look up the precedence order and every `reason` value | `Rules::decision()`, `tests/phpunit/tests/class-test-decision.php`, `class-test-send-mode.php` |
| `reference/per-post-field.md` | Look up the per-post choice as post meta, REST field and Micropub property | `Rules::register_meta()`, `includes/class-micropub.php` |
| `reference/compatibility.md` | Look up requirements, optional plugins, and the RSS Chat filters this plugin answers | `rss-chat-routing.php` header, README "Requires" and "How it works" |
| `reference/rss-chat-resources.md` | Find the official rss.chat and RSS Chat plugin docs | links below under Standing decisions |

### How-to guides

| Document (path) | Need served | Source material |
|---|---|---|
| `how-to/choose-when-posts-send.md` | Pick opt in, opt out, format and kind, or ask on every post | settings screen copy, `send_mode_choices()` |
| `how-to/send-or-hold-one-post.md` | Include or exclude one post from the editor or a Micropub client | `assets/js/editor.js`, `class-micropub.php` |
| `how-to/route-by-format-or-kind.md` | Send posts by default post format or Post Kind | `matches_format()`, `matches_kind()`, settings copy |
| `how-to/get-replies-as-webmentions.md` | Choose how rss.chat replies come back | `class-reply-import.php`, README "Replies" |
| `how-to/upgrade-from-0-1-or-chat-format.md` | Move a site from the chat post format or 0.1.x settings without changing what sends | README "Migrating from 0.1.x", `upgrade_legacy_shape()` |
| `how-to/find-out-why-a-post-did-not-send.md` | Diagnose a post that did or didn't reach rss.chat | decision reasons, WP-CLI `eval` pattern used on courtneyr.dev 2026-11-11 |

### Explanation

| Document (path) | Need served | Source material |
|---|---|---|
| `explanation/why-routing-is-separate.md` | Understand why routing lives outside the chat post format | README "Why", vault note "Repository responsibility boundaries" |
| `explanation/how-replies-come-home.md` | Understand the Webmention reply path and cross-network isolation | README "Replies", "Isolation", "Permalinks"; vault notes "Webmention reply architecture", "Cross-network isolation rules" |

### Tutorials

| Document (path) | Need served | Source material |
|---|---|---|
| `tutorial/first-post.md` | A new site owner sends one post to rss.chat end to end | parent plugin README (connect step), settings screen, editor panel |

## Not-created entries

| Type / diagram level | Reason | Remedy |
|---|---|---|
| Architecture diagram (C4) | Two plugins and one server; the reply-path explanation covers it in prose | Add if the Webmention path grows another hop |
| Developer API reference for this plugin's hooks | The plugin defines no actions or filters of its own | Add when it exposes one |
| Screenshots | Not captured yet; the settings screen and panel need a running site with RSS Chat connected | Capture with `wp-screenshots` on a local site, then add a screenshots reference page |

## README

Kept as the contributor-facing front door: "Why", "What it does", "Migrating", "How it works", "Tests" and "Requires" stay, and a "Documentation" section (added 2026-11-11) links the site's four sections and the official rss.chat sources. The site's pages are rewrites for site owners, not copies of README sections.

## Batched questions and answers

- Audiences (2026-11-11): site owners and developers. How-tos lead with plain steps; REST, Micropub and filter detail lives in reference.
- Tutorial (2026-11-11): include it; run the local WordPress steps and mark the rss.chat connect and publish steps unverified.
- Approval (2026-11-11): Courtney approved the plan as written, all 14 pages, reference scope "the plugin's own surface only".

## Standing decisions

- Official rss.chat docs to link (checked 2026-11-11, all return 200):
  - rss.chat network: https://rss.chat/
  - Server and client source and docs: https://github.com/scripting/rss.chat (`server/docs/api.md`, `server/docs/config.md`, `client/docs/`, `network/readme.md`, `examples/wordpress/`)
  - RSS as a social network: https://source.scripting.com/social.opml
  - `source` namespace (`source:inReplyTo`, `source:comments`): https://source.scripting.com/
  - Launch post: http://scripting.com/2026/07/10/161133.html
  - Questions thread: https://github.com/scripting/Scripting-News/issues/358
  - RSS Chat WordPress plugin (the parent): https://github.com/pfefferle/wordpress-rss-chat
- Name rules: "rss.chat" is the network, "RSS Chat" is the parent WordPress plugin, "RSS Chat Routing" is this plugin.
- No WordPress.org listing link: the plugin isn't on WordPress.org.
- Every claim about behavior traces to source or a test; anything not run is marked unverified.

## Tutorial verification status

| Tutorial | Status |
|---|---|
| `tutorial/first-post.md` | unverified in a browser (2026-11-11). Steps 2 and 6 need a real rss.chat account and were not run. Panel text in steps 4 and 5 is asserted by `tests/js/panel-harness.js`; the settings screen's four modes by `test_screen_renders_four_modes_with_the_current_one_checked`. A Playground run was blocked by the session's permission mode. Remedy: click through on a local site with both plugins active. |
