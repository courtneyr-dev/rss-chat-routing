---
title: rss.chat resources
description: "Official rss.chat documentation and source: the network, the server and client docs, the RSS social-network spec, and the RSS Chat WordPress plugin."
---

RSS Chat Routing only decides which posts go to rss.chat. The network, its protocols, and the plugin that talks to it are documented by their own projects. Links checked 2026-11-11.

## rss.chat, the network

- [rss.chat](https://rss.chat/): the network itself, by Dave Winer.
- [Introducing rss.chat](http://scripting.com/2026/07/10/161133.html): the launch post (July 10, 2026), which explains how rss.chat uses RSS 2.0, OPML, Markdown, SQL, and WebSocket.
- [RSS as a social network](https://source.scripting.com/social.opml): how the pieces fit together.
- [The `source` namespace](https://source.scripting.com/): the RSS elements rss.chat uses, including `source:inReplyTo` and `source:comments`.
- [Questions thread](https://github.com/scripting/Scripting-News/issues/358): where to ask the rss.chat developers.

## rss.chat server and client

Source and docs live in [scripting/rss.chat](https://github.com/scripting/rss.chat) on GitHub:

- [Server API](https://github.com/scripting/rss.chat/blob/main/server/docs/api.md): the HTTP endpoints, including the `/newpost` call RSS Chat uses.
- [Server configuration](https://github.com/scripting/rss.chat/blob/main/server/docs/config.md): for running your own server.
- [Client docs](https://github.com/scripting/rss.chat/tree/main/client/docs): the JavaScript client.
- [Network readme](https://github.com/scripting/rss.chat/blob/main/network/readme.md): how servers form a network.
- [WordPress example](https://github.com/scripting/rss.chat/tree/main/examples/wordpress): the project's own WordPress example.

## RSS Chat, the WordPress plugin

[RSS Chat](https://github.com/pfefferle/wordpress-rss-chat) by Matthias Pfefferle connects WordPress to rss.chat: it signs your site in, sends posts, imports replies, and adds the `source:` elements to your feed. Its README covers signing in, choosing a server (the default is `https://demo.rss.chat`), and its filters.

## Related standards

- [Webmention](https://www.w3.org/TR/webmention/): the W3C recommendation the **Verified Webmentions** reply mode uses.
- [Micropub](https://www.w3.org/TR/micropub/): the W3C recommendation behind the `mp-rss-chat-routing` property.
