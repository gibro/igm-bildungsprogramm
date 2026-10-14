/**
 * Regeln – Bearbeiten-Maske (Bildungsprogramm → Regeln → Regel bearbeiten).
 *
 * Baut die Bedingungszeilen aus den Felddefinitionen, die der Server als JSON
 * in #bi-rg-daten mitgibt (BI_Regeln::js_daten). Eine Quelle für Erstaufbau
 * und Feldwechsel – sonst gäbe es zwei Stellen, die wissen müssen, welche
 * Vergleiche zu welchem Feld passen.
 *
 * Kein Build, kein jQuery: läuft so, wie die Datei ist.
 */
( function () {
	'use strict';

	var datenEl = document.getElementById( 'bi-rg-daten' );
	var form    = document.getElementById( 'bi-rg-form' );
	if ( ! datenEl || ! form ) {
		return;
	}
	var D        = JSON.parse( datenEl.textContent || '{}' );
	var liste    = document.getElementById( 'bi-rg-bedingungen' );
	var plus     = document.getElementById( 'bi-rg-plus' );
	var immer    = document.getElementById( 'bi-rg-immer' );
	var aktionEl = document.getElementById( 'bi-rg-aktion' );
	var zaehler  = 0;

	function el( tag, attrs, text ) {
		var e = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			e.setAttribute( k, attrs[ k ] );
		} );
		if ( text !== undefined ) {
			e.textContent = text;
		}
		return e;
	}

	/** Vergleichsform wie BI_Settings::norm, nur grob – für die Vorauswahl. */
	function norm( s ) {
		return String( s ).toLowerCase().replace( /[^0-9a-zäöüß]/g, '' );
	}

	function nurSeminar() {
		return aktionEl && ( aktionEl.value === 'anmeldeweg' || aktionEl.value === 'formular' );
	}

	function feldSelect( name, aktuell ) {
		var sel    = el( 'select', { name: name, 'class': 'bi-rg-feld' } );
		var leer   = el( 'option', { value: '' }, '— Feld wählen —' );
		sel.appendChild( leer );
		var gruppen = { seminar: el( 'optgroup', { label: 'Am Seminar' } ), anmeldung: el( 'optgroup', { label: 'In der Anmeldung' } ) };
		Object.keys( D.felder ).forEach( function ( k ) {
			var f = D.felder[ k ];
			var o = el( 'option', { value: k }, f.label );
			if ( k === aktuell ) {
				o.selected = true;
			}
			gruppen[ f.quelle ].appendChild( o );
		} );
		sel.appendChild( gruppen.seminar );
		sel.appendChild( gruppen.anmeldung );
		return sel;
	}

	function opSelect( name, feld, aktuell ) {
		var sel = el( 'select', { name: name, 'class': 'bi-rg-op' } );
		var f   = D.felder[ feld ];
		( f ? f.ops : [] ).forEach( function ( op ) {
			var o = el( 'option', { value: op }, D.ops[ op ] || op );
			if ( op === aktuell ) {
				o.selected = true;
			}
			sel.appendChild( o );
		} );
		return sel;
	}

	function werteBox( i, feld, werte ) {
		var box = el( 'div', { 'class': 'bi-rg-werte' } );
		var f   = D.felder[ feld ];
		if ( ! f ) {
			return box;
		}
		werte = werte || [];
		var rest = werte.slice();

		if ( f.optionen && f.optionen.length ) {
			var opts = el( 'div', { 'class': 'bi-rg-optionen' } );
			f.optionen.forEach( function ( o ) {
				var lab = el( 'label' );
				var cb  = el( 'input', { type: 'checkbox', name: 'bed[' + i + '][werte][]', value: o[ 0 ] } );
				werte.forEach( function ( w ) {
					if ( norm( w ) === norm( o[ 0 ] ) || norm( w ) === norm( o[ 1 ] ) ) {
						cb.checked = true;
						rest = rest.filter( function ( r ) { return r !== w; } );
					}
				} );
				lab.appendChild( cb );
				lab.appendChild( document.createTextNode( ' ' + o[ 1 ] ) );
				opts.appendChild( lab );
			} );
			box.appendChild( opts );
		}

		if ( f.art === 'haken' ) {
			return box; // ja / nein / leer – mehr gibt es nicht
		}
		var frei;
		if ( f.art === 'frist' ) {
			frei = el( 'input', { type: 'number', min: '0', step: '1', name: 'bed[' + i + '][frei]', 'class': 'small-text', placeholder: '14' } );
			frei.value = werte[ 0 ] || '';
			box.appendChild( frei );
			box.appendChild( document.createTextNode( ' Tage vor Beginn' ) );
			return box;
		}
		frei = el( 'input', {
			type: 'text',
			name: 'bed[' + i + '][frei]',
			'class': 'regular-text',
			placeholder: ( f.optionen && f.optionen.length ) ? 'oder freier Wert, z. B. 37,6 (mehrere mit | trennen)' : 'Wert (mehrere mit | trennen)'
		} );
		frei.value = rest.join( ' | ' );
		box.appendChild( frei );
		return box;
	}

	function opAnpassen( zeile ) {
		var op    = zeile.querySelector( '.bi-rg-op' );
		var werte = zeile.querySelector( '.bi-rg-werte' );
		if ( op && werte ) {
			werte.style.display = ( D.ohneWerte.indexOf( op.value ) >= 0 ) ? 'none' : '';
		}
	}

	function gueltigkeit( zeile ) {
		var feld = zeile.querySelector( '.bi-rg-feld' ).value;
		var f    = D.felder[ feld ];
		var bad  = f && f.quelle === 'anmeldung' && nurSeminar();
		zeile.classList.toggle( 'is-ungueltig', !! bad );
		var msg = zeile.querySelector( '.bi-rg-fehler' );
		if ( msg ) {
			msg.style.display = bad ? '' : 'none';
		}
	}

	function zeile( b ) {
		var i   = zaehler++;
		var row = el( 'div', { 'class': 'bi-rg-bed' } );
		var und = el( 'span', { 'class': 'bi-rg-und' }, 'und' );
		var fs  = feldSelect( 'bed[' + i + '][feld]', b.feld );
		var ops = opSelect( 'bed[' + i + '][op]', b.feld, b.op );
		var wb  = werteBox( i, b.feld, b.werte );
		var weg = el( 'button', { type: 'button', 'class': 'button-link bi-rg-weg', 'aria-label': 'Bedingung entfernen' }, '✕ entfernen' );
		var err = el( 'div', { 'class': 'bi-rg-fehler' }, 'Für Anmeldeweg und Formular zählt nur, was am Seminar steht – diese Bedingung wird beim Speichern verworfen.' );

		row.appendChild( und );
		row.appendChild( fs );
		row.appendChild( ops );
		row.appendChild( wb );
		row.appendChild( weg );
		row.appendChild( err );

		fs.addEventListener( 'change', function () {
			var neuOp = opSelect( 'bed[' + i + '][op]', fs.value, '' );
			row.replaceChild( neuOp, row.querySelector( '.bi-rg-op' ) );
			neuOp.addEventListener( 'change', function () { opAnpassen( row ); } );
			row.replaceChild( werteBox( i, fs.value, [] ), row.querySelector( '.bi-rg-werte' ) );
			opAnpassen( row );
			gueltigkeit( row );
		} );
		ops.addEventListener( 'change', function () { opAnpassen( row ); } );
		weg.addEventListener( 'click', function () {
			row.parentNode.removeChild( row );
			leerHinweis();
		} );

		liste.appendChild( row );
		opAnpassen( row );
		gueltigkeit( row );
		leerHinweis();
	}

	function leerHinweis() {
		if ( immer ) {
			immer.style.display = liste.children.length ? 'none' : '';
		}
	}

	function aktionAnpassen() {
		var a = aktionEl.value;
		document.querySelectorAll( '.bi-rg-ziel' ).forEach( function ( z ) {
			var passt = ( z.getAttribute( 'data-aktion' ) || '' ).split( ' ' ).indexOf( a ) >= 0;
			z.style.display = passt ? '' : 'none';
			z.querySelectorAll( 'select, input, button' ).forEach( function ( x ) {
				x.disabled = ! passt;
			} );
		} );
		var hinweis = document.querySelector( '.bi-rg-nur-seminar' );
		if ( hinweis ) {
			hinweis.style.display = nurSeminar() ? '' : 'none';
		}
		liste.querySelectorAll( '.bi-rg-bed' ).forEach( gueltigkeit );
		anhangAnpassen();
	}

	var anhangEl = document.getElementById( 'bi-rg-anhang' );
	function anhangAnpassen() {
		var datei = document.querySelector( '.bi-rg-datei' );
		if ( datei && anhangEl ) {
			datei.style.display = ( anhangEl.value === 'datei' && ! anhangEl.disabled ) ? '' : 'none';
		}
	}

	( D.bedingungen || [] ).forEach( zeile );
	leerHinweis();
	plus.addEventListener( 'click', function () {
		zeile( { feld: '', op: '', werte: [] } );
	} );
	if ( aktionEl ) {
		aktionEl.addEventListener( 'change', aktionAnpassen );
		aktionAnpassen();
	}
	if ( anhangEl ) {
		anhangEl.addEventListener( 'change', anhangAnpassen );
	}

	// Datei aus der Mediathek (wp.media wird nur in dieser Maske geladen).
	var waehlen = document.getElementById( 'bi-rg-datei-waehlen' );
	if ( waehlen ) {
		var rahmen = null;
		waehlen.addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				window.alert( 'Die Mediathek steht hier gerade nicht zur Verfügung.' );
				return;
			}
			if ( ! rahmen ) {
				rahmen = window.wp.media( { title: 'Datei für den Anhang wählen', multiple: false, button: { text: 'Diese Datei anhängen' } } );
				rahmen.on( 'select', function () {
					var a = rahmen.state().get( 'selection' ).first().toJSON();
					document.getElementById( 'bi-rg-datei' ).value         = a.id;
					document.getElementById( 'bi-rg-datei-name' ).textContent = a.title || a.filename || ( 'Datei ' + a.id );
				} );
			}
			rahmen.open();
		} );
	}
} )();
