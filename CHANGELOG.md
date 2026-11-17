# Changelog

All notable changes to RSS Chat Routing will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.4.0] - 2026-11-16

### Added

- Two WordPress Abilities API abilities, registered only when the Abilities API is available
  (WordPress 6.9 or later):
  - `rss-chat-routing/get-route` reads a post's routing decision, its stored override, and the
    site defaults. It needs `edit_post` on that post and is public to MCP.
  - `rss-chat-routing/set-route` changes a post's stored route (include, exclude, or follow the
    site rules) for its next publish or update. It never sends anything now. It dry-runs unless
    `dry_run` is false, needs `edit_post` on that post, isn't public to MCP, and stays off until
    you tick its box.
- An **Agent abilities** section on Settings → RSS Chat Routing with the set-route switch
  (`rss_chat_routing_ability_set_route`, off by default).

### Fixed

- `set-route` accepts only posts of the `post` type. A revision id used to pass the parent's
  `edit_post` check, write the parent's route, and report the revision's.
- The set-route switch reads only `true`, `1` and `"1"` as on, so a stored `"no"` or `"off"`
  no longer turns it on, and a `dry_run` of `"false"` is no longer read as true, so it writes.

## [0.3.0] - 2026-11-11

### Added

- A sitewide *When to send posts* setting with four modes: only posts you opt in, every post
  unless you opt it out, decide by post format and kind (the default, unchanged behavior), and
  ask on every post. The last one repeats the include/exclude choice in the pre-publish checks.

### Changed

- An install still on the 0.1.x `all` option now reads as *Every post unless I opt it out*.
  It keeps sending everything, and saving the settings screen no longer silently switches it
  to the format default.

## [0.2.2] - 2026-09-22

### Fixed

- Replies and likes imported by RSS Chat's backfeed are now sanitized and held for
  moderation. The `reply_import` default (`legacy`) is unchanged.

## [0.2.1] - 2026-09-13

### Fixed

- A legacy `protocol` comment meta value of `rss.chat` is read as `rsschat` when the Webmention
  plugin's `sanitize_key` callback is registered on the same meta key, and the gate now
  recognizes both spellings as an rss.chat-originated reply.

## [0.2.0] - 2026-08-24

### Added

- Route posts to rss.chat by a configurable default post format and default Post Kind, with a
  per-post override in the editor.
- Replies from rss.chat can now come home as verified Webmentions instead of the parent's own
  comment importer.

## [0.1.0] - 2026-08-22

### Added

- Route posts to rss.chat without requiring the core `chat` post format.

The 0.4.0 links use the `v0.4.0` tag. No git tags exist for the earlier releases yet, so
their links point at commit ranges rather than tag comparisons. [0.3.0] and [0.2.2] both end
at a branch tip (neither is tagged yet) rather than a fixed commit, so they keep resolving
correctly as work continues here.

[Unreleased]: https://github.com/courtneyr-dev/rss-chat-routing/compare/v0.4.0...main
[0.4.0]: https://github.com/courtneyr-dev/rss-chat-routing/compare/6d2caf5...v0.4.0
[0.3.0]: https://github.com/courtneyr-dev/rss-chat-routing/compare/462ff8186c48252a48f704add62accbabf5edc57...feat/send-mode
[0.2.2]: https://github.com/courtneyr-dev/rss-chat-routing/compare/9deab4c9a66ffaf22b16c79625dd6333d0b9e04b...fix/0.2.2-sanitize-backfeed
[0.2.1]: https://github.com/courtneyr-dev/rss-chat-routing/compare/58d12b9bba04dcda8c40183a98f65d07a817ba68...9deab4c9a66ffaf22b16c79625dd6333d0b9e04b
[0.2.0]: https://github.com/courtneyr-dev/rss-chat-routing/compare/c4dd26b2b260bfaa0ff392c6418cce0953aebd57...58d12b9bba04dcda8c40183a98f65d07a817ba68
[0.1.0]: https://github.com/courtneyr-dev/rss-chat-routing/commit/c4dd26b2b260bfaa0ff392c6418cce0953aebd57
