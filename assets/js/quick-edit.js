/* global inlineEditPost */
( function () {
	const edit = inlineEditPost.edit;

	inlineEditPost.edit = function ( id ) {
		edit.apply( this, arguments );

		const postId = typeof id === 'object' ? this.getId( id ) : id;
		const row = document.getElementById( 'post-' + postId );
		const editor = document.getElementById( 'edit-' + postId );
		const value = row?.querySelector( '.rss-chat-route-value' );
		const select = editor?.querySelector( '[name="rss_chat_routing_route"]' );
		const reason = editor?.querySelector( '.rss-chat-route-reason' );
		if ( value && select && reason ) {
			select.value = value.dataset.route;
			reason.textContent = value.textContent;
			reason.id = 'rss-chat-route-reason-' + postId;
			select.setAttribute( 'aria-describedby', reason.id );
		}
	};
} )();
