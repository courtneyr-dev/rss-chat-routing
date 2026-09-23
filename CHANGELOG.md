# Changelog

All notable changes to RSS Chat Routing will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

No git tags exist for these releases yet, so the links below point at commit ranges
rather than tag comparisons.

[0.2.2]: https://github.com/courtneyr-dev/rss-chat-routing/compare/9deab4c9a66ffaf22b16c79625dd6333d0b9e04b...9eaf1599797d0fe56d79f03ae70cca94312a8217
[0.2.1]: https://github.com/courtneyr-dev/rss-chat-routing/compare/58d12b9bba04dcda8c40183a98f65d07a817ba68...9deab4c9a66ffaf22b16c79625dd6333d0b9e04b
[0.2.0]: https://github.com/courtneyr-dev/rss-chat-routing/compare/c4dd26b2b260bfaa0ff392c6418cce0953aebd57...58d12b9bba04dcda8c40183a98f65d07a817ba68
[0.1.0]: https://github.com/courtneyr-dev/rss-chat-routing/commit/c4dd26b2b260bfaa0ff392c6418cce0953aebd57
