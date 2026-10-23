/**
 * Panel „SEO (Aktio)” w edytorze blokowym: tytuł SEO i opis SEO w ustawieniach dokumentu.
 * Bez bundlera — korzysta z globalnych obiektów wp.*. Konfiguracja w window.aktioSeoPanel.
 */
( function ( wp, config ) {
	if ( ! wp || ! config ) {
		return;
	}
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel || ! wp.plugins ) {
		return;
	}
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;

	var TITLE_KEY = '_aktio_seo_title';
	var DESC_KEY = '_aktio_seo_description';

	function SeoPanel() {
		var meta = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;

		function set( key, max ) {
			return function ( value ) {
				var patch = {};
				patch[ key ] = String( value ).slice( 0, max );
				editPost( { meta: patch } );
			};
		}

		var fields = [];
		if ( config.title ) {
			fields.push(
				el( TextControl, {
					key: 'title',
					label: __( 'SEO title', 'aktio-connector' ),
					value: meta[ TITLE_KEY ] || '',
					maxLength: config.titleMax,
					help: ( meta[ TITLE_KEY ] || '' ).length + ' / ' + config.titleMax,
					onChange: set( TITLE_KEY, config.titleMax ),
				} )
			);
		}
		if ( config.description ) {
			fields.push(
				el( TextareaControl, {
					key: 'description',
					label: __( 'SEO description', 'aktio-connector' ),
					value: meta[ DESC_KEY ] || '',
					maxLength: config.descMax,
					help: ( meta[ DESC_KEY ] || '' ).length + ' / ' + config.descMax,
					onChange: set( DESC_KEY, config.descMax ),
				} )
			);
		}
		return el( Panel, { name: 'aktio-seo', title: __( 'SEO (Aktio)', 'aktio-connector' ) }, fields );
	}

	wp.plugins.registerPlugin( 'aktio-seo', { render: SeoPanel } );
}( window.wp, window.aktioSeoPanel ) );
