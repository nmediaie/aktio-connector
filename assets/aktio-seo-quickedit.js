/**
 * Szybka edycja: wstawia „Tytuł SEO” i „Opis SEO” wpisu do pól panelu (dane z ukrytej kolumny SEO).
 */
( function ( $ ) {
	if ( ! $ || ! window.inlineEditPost ) {
		return;
	}
	var edit = window.inlineEditPost.edit;
	window.inlineEditPost.edit = function ( id ) {
		edit.apply( this, arguments );
		var postId = typeof id === 'object' ? window.inlineEditPost.getId( id ) : id;
		var data = $( '#post-' + postId + ' .aktio-seo-data' );
		var row = $( '#edit-' + postId );
		row.find( 'input[name="aktio_seo_title"]' ).val( data.attr( 'data-title' ) || '' );
		row.find( 'input[name="aktio_seo_description"]' ).val( data.attr( 'data-description' ) || '' );
	};
} )( window.jQuery );
