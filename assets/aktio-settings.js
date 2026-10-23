/**
 * Ekran ustawień Aktio Connector: wybór domyślnego obrazka OG z biblioteki mediów (wp.media).
 * Bez bundlera. Przycisk .aktio-choose-image ma data-target (id pola), data-title i data-button.
 */
( function ( wp ) {
	'use strict';
	if ( ! wp || ! wp.media ) {
		return;
	}
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.aktio-choose-image' ) : null;
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var input = document.getElementById( button.getAttribute( 'data-target' ) );
		if ( ! input ) {
			return;
		}
		var frame = wp.media( {
			title: button.getAttribute( 'data-title' ) || '',
			button: { text: button.getAttribute( 'data-button' ) || '' },
			library: { type: 'image' },
			multiple: false
		} );
		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			if ( attachment && attachment.url ) {
				input.value = attachment.url;
			}
		} );
		frame.open();
	} );
}( window.wp ) );
