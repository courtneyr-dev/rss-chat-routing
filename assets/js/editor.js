/**
 * Per-post routing control for rss.chat.
 *
 * Three states — use the site default, include, exclude — plus a live line
 * explaining what will actually happen to this post and why. Written
 * against the wp globals rather than JSX so the plugin needs no build step:
 * it is a companion for one site, not a distributed package.
 */
( function ( wp, config ) {
	if ( ! wp || ! wp.plugins || ! config ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	var PluginPrePublishPanel =
		( wp.editor && wp.editor.PluginPrePublishPanel ) ||
		( wp.editPost && wp.editPost.PluginPrePublishPanel );

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	var sendMode = config.sendMode || 'rules';

	/**
	 * Label and help for the "no choice" option, per sitewide mode.
	 *
	 * @return {Object} { label, help }
	 */
	function inheritCopy() {
		if ( 'opt_in' === sendMode ) {
			return {
				label: __( 'Use the site default (don’t send)', 'rss-chat-routing' ),
				help: __( 'This site sends only posts you include.', 'rss-chat-routing' ),
			};
		}
		if ( 'opt_out' === sendMode ) {
			return {
				label: __( 'Use the site default (send)', 'rss-chat-routing' ),
				help: __( 'This site sends every post you don’t exclude.', 'rss-chat-routing' ),
			};
		}
		if ( 'per_post' === sendMode ) {
			return {
				label: __( 'Not chosen yet (won’t send)', 'rss-chat-routing' ),
				help: __( 'This site asks on every post. Choose Include or Exclude.', 'rss-chat-routing' ),
			};
		}
		return {
			label: __( 'Use the site default', 'rss-chat-routing' ),
			help: __( '“Use the site default” follows the format and kind rules in Settings; the other two pin this one post.', 'rss-chat-routing' ),
		};
	}

	/**
	 * The legacy stored spellings still mean include/exclude.
	 *
	 * @param {string} stored Raw meta value.
	 * @return {string} '', 'include', or 'exclude'.
	 */
	function normalize( stored ) {
		if ( 'include' === stored || '1' === stored ) {
			return 'include';
		}
		if ( 'exclude' === stored || '0' === stored ) {
			return 'exclude';
		}
		return '';
	}

	/**
	 * What will actually happen to this post, and why.
	 *
	 * Mirrors the server-side decision (Rules::decision) for display only —
	 * the server stays authoritative.
	 *
	 * @param {string} override    '', 'include', or 'exclude'.
	 * @param {string} format      Current format slug ('' = standard).
	 * @param {Array}  kindIds     Selected kind term ids.
	 * @return {string} Route forecast.
	 */
	function effective( override, format, kindIds ) {
		if ( 'exclude' === override ) {
			return __( 'Excluded by this post’s override.', 'rss-chat-routing' );
		}
		if ( 'include' === override ) {
			return __( 'Included by this post’s override.', 'rss-chat-routing' );
		}

		if ( 'opt_out' === sendMode ) {
			return __( 'Included: the site sends every post you don’t exclude.', 'rss-chat-routing' );
		}

		if ( 'opt_in' === sendMode ) {
			return __( 'Not sent: the site sends only posts you include.', 'rss-chat-routing' );
		}

		if ( 'per_post' === sendMode ) {
			return __( 'Not sent yet: choose Include or Exclude for this post.', 'rss-chat-routing' );
		}

		var currentFormat = format || 'standard';
		if ( config.defaultFormat && currentFormat === config.defaultFormat ) {
			return sprintf(
				/* translators: %s: post format name. */
				__( 'Included because the post format is %s.', 'rss-chat-routing' ),
				config.defaultFormatLabel || config.defaultFormat
			);
		}

		if ( config.defaultKind && Array.isArray( kindIds ) ) {
			for ( var i = 0; i < kindIds.length; i++ ) {
				var kind = config.kindsById && config.kindsById[ kindIds[ i ] ];
				if ( kind && kind.slug === config.defaultKind ) {
					return sprintf(
						/* translators: %s: post kind name. */
						__( 'Included because the post kind is %s.', 'rss-chat-routing' ),
						kind.name
					);
				}
			}
		}

		return __( 'Not included by the site default.', 'rss-chat-routing' );
	}

	function deliveryDetails( delivery ) {
		if ( ! delivery ) {
			return null;
		}

		var feed = delivery.feed;
		return el(
			wp.element.Fragment,
			null,
			el(
				'p',
				null,
				delivery.remote_id
					? sprintf(
						/* translators: %s: recorded remote item ID. */
						__( 'Recorded RSS Chat delivery ID: %s', 'rss-chat-routing' ),
						delivery.remote_id
					)
					: __( 'No RSS Chat delivery ID recorded.', 'rss-chat-routing' )
			),
			el(
				'details',
				null,
				el( 'summary', null, __( 'RSS2 feed provenance', 'rss-chat-routing' ) ),
				feed
					? el(
						wp.element.Fragment,
						null,
						el(
							'p',
							null,
							feed.origin === 'delivery'
								? __( 'The saved delivery ID enables source fields for this post in RSS2.', 'rss-chat-routing' )
								: __( 'The saved Chat post format enables source fields for this post in RSS2.', 'rss-chat-routing' )
						),
						el(
							'dl',
							null,
							el( 'dt', null, el( 'code', null, 'source:markdown' ) ),
							el( 'dd', null, __( 'RSS2 uses the rendered post body when it contains text.', 'rss-chat-routing' ) ),
							el( 'dt', null, el( 'code', null, 'source:comments' ) ),
							el(
								'dd',
								null,
								sprintf(
									/* translators: %d: number of approved replies. */
									__( 'Approved replies: %d.', 'rss-chat-routing' ),
									feed.comments_count
								),
								' ',
								el( 'a', { href: feed.comments_url }, __( 'RSS2 comments feed', 'rss-chat-routing' ) )
							)
						)
					)
					: el( 'p', null, __( 'The saved post has no public RSS2 source fields.', 'rss-chat-routing' ) )
			)
		);
	}

	function Panel() {
		var postType = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		var meta = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var format = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'format' ) || '';
		}, [] );

		var kindIds = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'kind' ) || [];
		}, [] );

		var delivery = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostAttribute( 'rss_chat_routing_delivery' );
		}, [] );

		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		var current = normalize( meta[ config.metaKey ] || '' );
		var forecast = effective( current, format, kindIds );

		// Announce the effective outcome when it changes, so screen reader
		// users hear the consequence of a control they just used.
		wp.element.useEffect( function () {
			if ( wp.a11y && wp.a11y.speak ) {
				wp.a11y.speak( forecast );
			}
		}, [ forecast ] );

		if ( 'post' !== postType ) {
			return null;
		}

		function setValue( value ) {
			var next = {};
			next[ config.metaKey ] = value;
			editPost( { meta: next } );
		}

		var copy = inheritCopy();

		function control() {
			return el( wp.components.RadioControl, {
				label: __( 'Send this post to rss.chat', 'rss-chat-routing' ),
				help: copy.help,
				selected: current,
				options: [
					{ label: copy.label, value: '' },
					{ label: __( 'Include in RSS Chat', 'rss-chat-routing' ), value: 'include' },
					{ label: __( 'Exclude from RSS Chat', 'rss-chat-routing' ), value: 'exclude' },
				],
				onChange: setValue,
			} );
		}

		var sidebar = el(
			PluginDocumentSettingPanel,
			{
				name: 'rss-chat-routing',
				title: __( 'rss.chat', 'rss-chat-routing' ),
				className: 'rss-chat-routing-panel',
			},
			control(),
			el(
				'p',
				{
					className: 'components-base-control__help',
					// The visible line doubles as the live status; aria-live
					// is handled by wp.a11y.speak above, so plain text here.
				},
				sprintf(
					/* translators: %s: the reason for the forecast. */
					__( 'Route forecast when eligible: %s', 'rss-chat-routing' ),
					forecast
				)
			),
			deliveryDetails( delivery ),
			el(
				'p',
				{ className: 'components-base-control__help' },
				el(
					'a',
					{ href: config.settingsUrl },
					__( 'Site setting', 'rss-chat-routing' )
				)
			)
		);

		// "Ask me on every post": repeat the choice in the pre-publish
		// checks, open while it's still unmade, so it can't be skipped by
		// accident. Publishing stays possible; an unchosen post isn't sent.
		if ( 'per_post' !== sendMode || ! PluginPrePublishPanel ) {
			return sidebar;
		}

		return el(
			wp.element.Fragment,
			null,
			sidebar,
			el(
				PluginPrePublishPanel,
				{
					name: 'rss-chat-routing-prepublish',
					title: __( 'rss.chat: include or exclude?', 'rss-chat-routing' ),
					initialOpen: '' === current,
				},
				control()
			)
		);
	}

	wp.plugins.registerPlugin( 'rss-chat-routing', { render: Panel } );
} )( window.wp, window.rssChatRouting );
