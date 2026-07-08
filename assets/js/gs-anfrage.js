/**
 * Geschäftsstellen-Anfrage auf der Seminar-Detailseite (GS-Variante).
 *
 * PLZ eingeben -> AJAX-Lookup (bi_plz_lookup) -> zuständige Geschäftsstelle
 * anzeigen und „Anfrage senden" als mailto:-Link mit vorausgefüllten
 * Seminardaten (data-bi-subject / data-bi-body) anbieten. Zusätzlich werden
 * alle GS-Buttons der Seite (.igm-js-gs-btn, z. B. in der Termin-Tabelle)
 * auf mailto umgeschrieben. Die letzte erfolgreiche PLZ wird in
 * localStorage gemerkt, damit das Ergebnis beim nächsten Besuch direkt steht.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'biGsLookup';

	function stored() {
		try {
			return JSON.parse( window.localStorage.getItem( STORAGE_KEY ) || 'null' );
		} catch ( e ) {
			return null;
		}
	}

	function store( data ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( data ) );
		} catch ( e ) { /* Storage voll/blockiert – dann eben ohne Merken */ }
	}

	function mailtoFor( el, email ) {
		return 'mailto:' + email
			+ '?subject=' + encodeURIComponent( el.getAttribute( 'data-bi-subject' ) || '' )
			+ '&body=' + encodeURIComponent( el.getAttribute( 'data-bi-body' ) || '' );
	}

	/* Alle GS-Buttons der Seite (Sidebar-Fallback, Termin-Tabelle) auf mailto umschreiben */
	function rewriteButtons( email ) {
		document.querySelectorAll( 'a.igm-js-gs-btn' ).forEach( function ( btn ) {
			btn.href = mailtoFor( btn, email );
			btn.textContent = 'Anfrage senden';
			btn.removeAttribute( 'target' );
			btn.removeAttribute( 'rel' );
		} );
	}

	function showResult( widget, data ) {
		var result = widget.querySelector( '.igm-gs-anfrage__result' );
		if ( ! result ) {
			return;
		}
		result.hidden = false;
		result.textContent = '';

		if ( data.error ) {
			var err = document.createElement( 'p' );
			err.className = 'igm-gs-anfrage__error';
			err.textContent = data.error;
			result.appendChild( err );
			return;
		}

		var p = document.createElement( 'p' );
		p.className = 'igm-gs-anfrage__gs';
		p.appendChild( document.createTextNode( 'Deine Geschäftsstelle: ' ) );
		var strong = document.createElement( 'strong' );
		strong.textContent = data.geschaeftsstelle;
		p.appendChild( strong );
		result.appendChild( p );

		if ( data.email ) {
			var a = document.createElement( 'a' );
			a.className = 'igm-btn-buchen';
			a.href = mailtoFor( widget, data.email );
			a.textContent = 'Anfrage senden';
			result.appendChild( a );
			rewriteButtons( data.email );
		} else {
			var note = document.createElement( 'p' );
			note.className = 'igm-gs-anfrage__error';
			note.textContent = 'Für diese Geschäftsstelle ist keine E-Mail-Adresse hinterlegt – bitte nutze die Geschäftsstellensuche.';
			result.appendChild( note );
		}
	}

	function lookup( widget, plz ) {
		var url = ( window.biGsAnfrage && biGsAnfrage.ajaxUrl ) || '/wp-admin/admin-ajax.php';
		fetch( url + '?action=bi_plz_lookup&plz=' + encodeURIComponent( plz ) )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success ) {
					store( { plz: plz, geschaeftsstelle: res.data.geschaeftsstelle, email: res.data.email } );
					showResult( widget, res.data );
				} else {
					showResult( widget, { error: ( res && res.data && res.data.message ) || 'Keine Geschäftsstelle gefunden.' } );
				}
			} )
			.catch( function () {
				showResult( widget, { error: 'Die Suche ist gerade nicht erreichbar – bitte versuche es später erneut.' } );
			} );
	}

	function initWidget( widget ) {
		var input = widget.querySelector( '.igm-gs-anfrage__plz' );
		var btn   = widget.querySelector( '.igm-gs-anfrage__find' );
		if ( ! input || ! btn ) {
			return;
		}

		var submit = function () {
			var plz = ( input.value || '' ).replace( /\D/g, '' );
			if ( 5 !== plz.length ) {
				showResult( widget, { error: 'Bitte gib eine fünfstellige Postleitzahl ein.' } );
				return;
			}
			lookup( widget, plz );
		};

		btn.addEventListener( 'click', submit );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				submit();
			}
		} );

		// Gemerkte PLZ aus einem früheren Besuch direkt anzeigen
		var last = stored();
		if ( last && last.plz ) {
			input.value = last.plz;
			showResult( widget, last );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.igm-gs-anfrage' ).forEach( initWidget );
	} );
})();
