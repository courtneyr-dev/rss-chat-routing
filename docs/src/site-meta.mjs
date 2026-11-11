// Single source of per-plugin parameters for the docs site.
// Everything else (astro.config.mjs, Head.astro, structured data) reads from here.
export default {
	name: 'RSS Chat Routing',
	slug: 'rss-chat-routing',
	site: 'https://courtneyr-dev.github.io',
	base: '/rss-chat-routing',
	description:
		'Documentation for RSS Chat Routing: choose which WordPress posts go to rss.chat and bring replies home as verified Webmentions.',
	github: 'https://github.com/courtneyr-dev/rss-chat-routing',
	wporg: '',
	version: '0.3.0',
	requiresWP: '6.6',
	requiresPHP: '7.4',
	author: 'Courtney Robertson',
	authorUrl: 'https://courtneyr.dev/',
};
