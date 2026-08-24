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

	if ( ! PluginDocumentSettingPanel ) {
		return;
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
	 * @return {Object} { send, message }
	 */
	function effective( override, format, kindIds ) {
		if ( 'exclude' === override ) {
			return {
				send: false,
				message: __( 'Excluded by this post’s override.', 'rss-chat-routing' ),
			};
		}
		if ( 'include' === override ) {
			return {
				send: true,
				message: __( 'Included by this post’s override.', 'rss-chat-routing' ),
			};
		}

		if ( config.legacyAll ) {
			return {
				send: true,
				message: __( 'Included: the site currently sends every post.', 'rss-chat-routing' ),
			};
		}

		var currentFormat = format || 'standard';
		if ( config.defaultFormat && currentFormat === config.defaultFormat ) {
			return {
				send: true,
				message: sprintf(
					/* translators: %s: post format name. */
					__( 'Included because the post format is %s.', 'rss-chat-routing' ),
					config.defaultFormatLabel || config.defaultFormat
				),
			};
		}

		if ( config.defaultKind && Array.isArray( kindIds ) ) {
			for ( var i = 0; i < kindIds.length; i++ ) {
				var kind = config.kindsById && config.kindsById[ kindIds[ i ] ];
				if ( kind && kind.slug === config.defaultKind ) {
					return {
						send: true,
						message: sprintf(
							/* translators: %s: post kind name. */
							__( 'Included because the post kind is %s.', 'rss-chat-routing' ),
							kind.name
						),
					};
				}
			}
		}

		return {
			send: false,
			message: __( 'Not included by the site default.', 'rss-chat-routing' ),
		};
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

		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		var current = normalize( meta[ config.metaKey ] || '' );
		var result = effective( current, format, kindIds );

		// Announce the effective outcome when it changes, so screen reader
		// users hear the consequence of a control they just used.
		wp.element.useEffect( function () {
			if ( wp.a11y && wp.a11y.speak ) {
				wp.a11y.speak( result.message );
			}
		}, [ result.message ] );

		if ( 'post' !== postType ) {
			return null;
		}

		function setValue( value ) {
			var next = {};
			next[ config.metaKey ] = value;
			editPost( { meta: next } );
		}

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'rss-chat-routing',
				title: __( 'rss.chat', 'rss-chat-routing' ),
				className: 'rss-chat-routing-panel',
			},
			el( wp.components.RadioControl, {
				label: __( 'Send this post to rss.chat', 'rss-chat-routing' ),
				help: __( '“Use the site default” follows the format and kind rules in Settings; the other two pin this one post.', 'rss-chat-routing' ),
				selected: current,
				options: [
					{ label: __( 'Use the site default', 'rss-chat-routing' ), value: '' },
					{ label: __( 'Include in RSS Chat', 'rss-chat-routing' ), value: 'include' },
					{ label: __( 'Exclude from RSS Chat', 'rss-chat-routing' ), value: 'exclude' },
				],
				onChange: setValue,
			} ),
			el(
				'p',
				{
					className: 'components-base-control__help',
					// The visible line doubles as the live status; aria-live
					// is handled by wp.a11y.speak above, so plain text here.
				},
				( result.send ? '✓ ' : '— ' ) + result.message
			),
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
	}

	wp.plugins.registerPlugin( 'rss-chat-routing', { render: Panel } );
} )( window.wp, window.rssChatRouting );
