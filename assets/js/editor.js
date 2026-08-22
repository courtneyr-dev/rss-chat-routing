/**
 * Per-post routing control for rss.chat.
 *
 * Written against the wp globals rather than JSX so the plugin needs no build
 * step: it is a companion for one site, not a distributed package.
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

	function Panel() {
		var postType = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		var meta = wp.data.useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );

		var editPost = wp.data.useDispatch( 'core/editor' ).editPost;

		if ( 'post' !== postType ) {
			return null;
		}

		var current = meta[ config.metaKey ] || '';

		function setValue( value ) {
			var next = {};
			next[ config.metaKey ] = value;
			editPost( { meta: next } );
		}

		var defaultLabel = config.siteDefault
			? __( 'Send it', 'rss-chat-routing' )
			: __( 'Leave it here', 'rss-chat-routing' );

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'rss-chat-routing',
				title: __( 'rss.chat', 'rss-chat-routing' ),
				className: 'rss-chat-routing-panel',
			},
			el( wp.components.RadioControl, {
				label: __( 'Send this post to rss.chat', 'rss-chat-routing' ),
				selected: current,
				options: [
					{
						/* translators: %s: what the site settings would do with this post. */
						label: sprintf( __( 'Follow the site setting (%s)', 'rss-chat-routing' ), defaultLabel ),
						value: '',
					},
					{ label: __( 'Always send this one', 'rss-chat-routing' ), value: '1' },
					{ label: __( 'Never send this one', 'rss-chat-routing' ), value: '0' },
				],
				onChange: setValue,
			} ),
			el(
				'p',
				{ className: 'components-base-control__help' },
				el(
					'a',
					{ href: config.settingsUrl },
					__( 'Site setting', 'rss-chat-routing' )
				),
				': ',
				config.modeLabel
			)
		);
	}

	wp.plugins.registerPlugin( 'rss-chat-routing', { render: Panel } );
} )( window.wp, window.rssChatRouting );
