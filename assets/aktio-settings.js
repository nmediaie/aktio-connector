/**
 * Ekran ustawień Aktio Connector (bez bundlera):
 * - zakładki: przełączanie bez przeładowania, strzałki/Home/End, adres z ?tab= (odświeżenie i zapis wracają na tę samą),
 * - pasek zapisu: widoczny na zakładkach z polami, ostrzeżenie o niezapisanych zmianach,
 * - karty widoczne tylko dla wybranego typu firmy (data-show-when-type),
 * - godziny otwarcia: dodawanie i usuwanie wierszy (szablon <template>, najwyżej data-max),
 * - limit zaznaczeń (data-max) i licznik znaków (data-counter),
 * - wybór obrazka z biblioteki mediów (.aktio-choose-image z data-target, data-title, data-button).
 * Bez JS ekran działa: zakładki są zwykłymi linkami, wszystkie pola są w jednym formularzu.
 */
( function ( wp ) {
	'use strict';

	var wrap = document.querySelector( '.aktio-wrap' );
	if ( ! wrap ) {
		return;
	}
	var form = document.getElementById( 'aktio-settings-form' );
	var tabs = Array.prototype.slice.call( wrap.querySelectorAll( '[role="tab"]' ) );
	var savebar = wrap.querySelector( '.aktio-savebar' );
	var status = wrap.querySelector( '.aktio-savebar__status' );
	var dirty = false;

	// --- Zakładki

	function activate( tab, focus ) {
		tabs.forEach( function ( other ) {
			var selected = other === tab;
			other.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
			other.setAttribute( 'tabindex', selected ? '0' : '-1' );
			other.classList.toggle( 'is-active', selected );
			var panel = document.getElementById( other.getAttribute( 'aria-controls' ) );
			if ( panel ) {
				panel.hidden = ! selected;
			}
		} );
		var slug = tab.getAttribute( 'data-tab' );
		if ( savebar ) {
			savebar.hidden = '1' !== tab.getAttribute( 'data-editable' );
		}
		if ( form && form.elements.aktio_tab ) {
			form.elements.aktio_tab.value = slug;
		}
		try {
			var url = new URL( window.location.href );
			url.searchParams.set( 'tab', slug );
			// Komunikaty po zapisie dotyczą poprzedniego widoku — nie wracają po odświeżeniu.
			[ 'saved', 'invalid', 'aktio_notice' ].forEach( function ( key ) {
				url.searchParams.delete( key );
			} );
			window.history.replaceState( null, '', url.toString() );
		} catch ( e ) {}
		if ( focus ) {
			tab.focus();
		}
	}

	function tabBySlug( slug ) {
		for ( var i = 0; i < tabs.length; i++ ) {
			if ( tabs[ i ].getAttribute( 'data-tab' ) === slug ) {
				return tabs[ i ];
			}
		}
		return null;
	}

	tabs.forEach( function ( tab, index ) {
		tab.setAttribute( 'tabindex', 'true' === tab.getAttribute( 'aria-selected' ) ? '0' : '-1' );
		tab.addEventListener( 'click', function ( event ) {
			if ( event.metaKey || event.ctrlKey || event.shiftKey || 1 === event.button ) {
				return; // Otwarcie w nowej karcie działa jak zwykły link.
			}
			event.preventDefault();
			activate( tab, false );
		} );
		tab.addEventListener( 'keydown', function ( event ) {
			var next = null;
			if ( 'ArrowRight' === event.key ) {
				next = tabs[ ( index + 1 ) % tabs.length ];
			} else if ( 'ArrowLeft' === event.key ) {
				next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
			} else if ( 'Home' === event.key ) {
				next = tabs[ 0 ];
			} else if ( 'End' === event.key ) {
				next = tabs[ tabs.length - 1 ];
			}
			if ( next ) {
				event.preventDefault();
				activate( next, true );
			}
		} );
	} );

	// Linki „Przejdź do …” w komunikatach i na liście braków przełączają zakładkę w miejscu.
	wrap.addEventListener( 'click', function ( event ) {
		var link = event.target.closest ? event.target.closest( '[data-tab-link]' ) : null;
		if ( ! link ) {
			return;
		}
		var tab = tabBySlug( link.getAttribute( 'data-tab-link' ) );
		if ( tab ) {
			event.preventDefault();
			activate( tab, false );
			tab.scrollIntoView( { block: 'nearest' } );
		}
	} );

	// --- Niezapisane zmiany

	function markDirty() {
		if ( dirty ) {
			return;
		}
		dirty = true;
		if ( status ) {
			status.textContent = status.getAttribute( 'data-unsaved' ) || '';
		}
	}

	if ( form ) {
		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );
	}
	window.addEventListener( 'beforeunload', function ( event ) {
		if ( ! dirty ) {
			return;
		}
		event.preventDefault();
		event.returnValue = status ? status.getAttribute( 'data-leave' ) || '' : '';
	} );

	// --- Karty zależne od typu firmy

	function syncCompanyType() {
		var checked = wrap.querySelector( 'input[name="company[type]"]:checked' );
		var type = checked ? checked.value : '';
		wrap.querySelectorAll( '[data-show-when-type]' ).forEach( function ( card ) {
			card.hidden = card.getAttribute( 'data-show-when-type' ) !== type;
		} );
	}
	wrap.querySelectorAll( 'input[name="company[type]"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', syncCompanyType );
	} );

	// --- Godziny otwarcia

	wrap.querySelectorAll( '.aktio-hours' ).forEach( function ( box ) {
		var rows = box.querySelector( '.aktio-hours__rows' );
		var template = box.querySelector( '.aktio-hours__template' );
		var add = box.querySelector( '.aktio-hours__add' );
		var max = parseInt( box.getAttribute( 'data-max' ), 10 ) || 7;
		var counter = rows.children.length;

		function refresh() {
			if ( add ) {
				add.disabled = rows.children.length >= max;
			}
		}

		if ( add && template ) {
			add.addEventListener( 'click', function () {
				if ( rows.children.length >= max ) {
					return;
				}
				// Nowy, unikalny indeks (usunięte wiersze zostawiają luki — serwer i tak numeruje od nowa).
				var html = template.innerHTML.split( '__i__' ).join( String( counter++ ) );
				var holder = document.createElement( 'div' );
				holder.innerHTML = html;
				var row = holder.firstElementChild;
				rows.appendChild( row );
				var first = row.querySelector( 'input' );
				if ( first ) {
					first.focus();
				}
				refresh();
				markDirty();
			} );
		}
		box.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest ? event.target.closest( '.aktio-hours__remove' ) : null;
			if ( ! remove ) {
				return;
			}
			var row = remove.closest( '.aktio-hours__row' );
			if ( row ) {
				row.parentNode.removeChild( row );
				refresh();
				markDirty();
				if ( add ) {
					add.focus();
				}
			}
		} );
		refresh();
	} );

	// --- Limit zaznaczeń (np. 5 języków obsługi)

	wrap.querySelectorAll( 'fieldset[data-max]' ).forEach( function ( set ) {
		var max = parseInt( set.getAttribute( 'data-max' ), 10 );
		var boxes = set.querySelectorAll( 'input[type="checkbox"]' );
		function sync() {
			var count = set.querySelectorAll( 'input[type="checkbox"]:checked' ).length;
			boxes.forEach( function ( box ) {
				box.disabled = ! box.checked && count >= max;
			} );
		}
		set.addEventListener( 'change', sync );
		sync();
	} );

	// --- Licznik znaków

	wrap.querySelectorAll( '[data-counter]' ).forEach( function ( field ) {
		var max = parseInt( field.getAttribute( 'data-counter' ), 10 );
		var out = document.createElement( 'span' );
		out.className = 'aktio-counter';
		out.setAttribute( 'aria-live', 'polite' );
		field.insertAdjacentElement( 'afterend', out );
		function update() {
			var length = field.value.length;
			out.textContent = length + ' / ' + max;
			out.classList.toggle( 'is-near', length > max * 0.9 );
		}
		field.addEventListener( 'input', update );
		update();
	} );

	// --- Obrazek z biblioteki mediów

	if ( wp && wp.media ) {
		wrap.addEventListener( 'click', function ( event ) {
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
					markDirty();
				}
			} );
			frame.open();
		} );
	}
}( window.wp ) );
