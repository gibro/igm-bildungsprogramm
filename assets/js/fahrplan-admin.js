/**
 * Bildungswege – Pflege eines Weges (Halte-Editor) mit Hilfen:
 *
 *  - Seminarsuche (Titel, Seminarnummer, Themenfeld) – oben „Neuer Halt aus
 *    einem Seminar“, in jedem Halt „Seminar suchen und hinzufügen“.
 *  - Analyse eines Halts: Fragen-Vorschläge aus den Themen der Seminare, Art,
 *    Punkte, Voraussetzungen. Ein neuer Halt aus der Suche wird damit gleich
 *    vorbelegt; sonst kommen die Vorschläge zum Anklicken.
 *  - Live-Vorschau mit Probelauf der Fragen (auch ungespeichert).
 *
 * Die Eingabefelder tragen keine name-Attribute. Beim Speichern sammelt dieses
 * Skript alle Halte in EIN verstecktes JSON-Feld (bil_haltestellen_json), damit
 * auch große Wege unter PHPs max_input_vars bleiben. Bleibt das Feld leer
 * (Skript nicht geladen), ändert der Server nichts an den Halten.
 */
( function () {
	'use strict';

	var liste = document.getElementById( 'bil-kaesten' );
	var feld = document.getElementById( 'bil-haltestellen-json' );
	var vorlage = document.getElementById( 'bil-kasten-vorlage' );
	var CFG = window.BIL_ADMIN || {};
	if ( ! liste || ! feld ) {
		return;
	}
	var MAX_FRAGEN = 3;

	/* ---------- Grundlagen ---------- */

	function el( wurzel, f ) {
		return wurzel.querySelector( '[data-f="' + f + '"]' );
	}

	function wert( wurzel, f ) {
		var e = el( wurzel, f );
		if ( ! e ) {
			return '';
		}
		return e.type === 'checkbox' ? e.checked : e.value;
	}

	function zeilen( text ) {
		return String( text || '' ).split( /\r?\n/ ).map( function ( z ) {
			return z.trim();
		} ).filter( Boolean );
	}

	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function sammeln() {
		var out = [];
		liste.querySelectorAll( '.bil-kasten-form' ).forEach( function ( k ) {
			out.push( {
				id: wert( k, 'id' ),
				name: wert( k, 'name' ),
				stufe: parseInt( wert( k, 'stufe' ), 10 ) || 1,
				art: wert( k, 'art' ),
				regional: wert( k, 'regional' ),
				pflicht: wert( k, 'pflicht' ),
				punkte: wert( k, 'punkte' ),
				titel: wert( k, 'titel' ),
				fragen: wert( k, 'fragen' )
			} );
		} );
		return out;
	}

	function ajax( action, daten ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', CFG.nonce || '' );
		Object.keys( daten || {} ).forEach( function ( k ) {
			fd.append( k, daten[ k ] );
		} );
		return fetch( CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( ( j && j.data ) || 'Fehler' );
				}
				return j.data;
			} );
	}

	/** Nächste freie ID: Buchstabe der vorhandenen IDs + höchste Nummer + 1. */
	function naechsteId() {
		var max = 0;
		var buchstabe = 'K';
		liste.querySelectorAll( '[data-f="id"]' ).forEach( function ( e ) {
			var m = /^([A-Z]+)(\d+)$/i.exec( e.value.trim() );
			if ( m ) {
				buchstabe = m[ 1 ].toUpperCase();
				max = Math.max( max, parseInt( m[ 2 ], 10 ) );
			}
		} );
		var n = String( max + 1 );
		return buchstabe + ( n.length < 2 ? '0' + n : n );
	}

	function hoechsteStufe() {
		var max = 0;
		liste.querySelectorAll( '[data-f="stufe"]' ).forEach( function ( e ) {
			max = Math.max( max, parseInt( e.value, 10 ) || 0 );
		} );
		return max;
	}

	function setzeArt( k, art ) {
		var s = el( k, 'art' );
		if ( s && art ) {
			s.value = art;
			k.className = k.className.replace( /\bbil-art--\S+/, '' ).trim() + ' bil-art--' + art;
		}
	}

	function neuerKasten() {
		var frag = vorlage.content.cloneNode( true );
		liste.appendChild( frag );
		var k = liste.lastElementChild;
		el( k, 'id' ).value = naechsteId();
		var stufe = Math.min( hoechsteStufe() + 1, el( k, 'stufe' ).options.length );
		el( k, 'stufe' ).value = String( Math.max( 1, stufe ) );
		return k;
	}

	function titelDazu( k, titel ) {
		var t = el( k, 'titel' );
		var da = zeilen( t.value );
		if ( da.indexOf( titel ) < 0 ) {
			da.push( titel );
		}
		t.value = da.join( '\n' );
	}

	/* ---------- Vorschläge aus den Themen ---------- */

	function zeigeVorschlaege( k, d, automatisch ) {
		var box = k.querySelector( '.bil-vorschlaege' );
		var fragenFeld = el( k, 'fragen' );
		var html = '';
		if ( ! d.seminare ) {
			box.innerHTML = '<p>Zu diesen Seminartiteln gibt es keine kommenden Termine – also auch keine Themen für Vorschläge.</p>';
			box.hidden = false;
			return;
		}
		html += '<p class="bil-vs-kopf">' + d.seminare + ( d.seminare === 1 ? ' Seminar' : ' Seminare' ) + ', ' + d.termine + ' Termine.';
		if ( d.art && CFG.arten && CFG.arten[ d.art ] ) {
			html += ' Vorschlag Art: <strong>' + esc( CFG.arten[ d.art ] ) + '</strong> <span class="description">(' + esc( d.art_grund ) + ')</span>' +
				' <button type="button" class="button-link" data-vs-art="' + esc( d.art ) + '">übernehmen</button>';
		}
		html += '</p>';
		if ( d.fragen.length ) {
			html += '<p><strong>Fragen-Vorschläge</strong> – Klick übernimmt (höchstens ' + MAX_FRAGEN + '), danach frei änderbar:</p><ul class="bil-vs-liste">';
			d.fragen.forEach( function ( f ) {
				var schon = zeilen( fragenFeld.value ).indexOf( f ) >= 0 || ( automatisch && ! zeilen( fragenFeld.value ).length && d.fragen.indexOf( f ) < MAX_FRAGEN );
				html += '<li><button type="button" class="button-link" data-vs-frage="' + esc( f ) + '"' + ( schon ? ' disabled' : '' ) + '>+ ' + esc( f ) + '</button></li>';
			} );
			html += '</ul>';
		} else {
			html += '<p>Die Seminare haben keine „Themen im Seminar“. Formuliere die Fragen bitte selbst.</p>';
		}
		if ( d.punkte.length ) {
			html += '<p><button type="button" class="button-link" data-vs-punkte>Punkte aus den Themen übernehmen</button>: ' +
				esc( d.punkte.join( ' · ' ) ) + '</p>';
		}
		if ( d.voraussetzungen.length ) {
			html += '<p><strong>Voraussetzungen laut Seminar:</strong></p><ul class="bil-vs-liste">';
			d.voraussetzungen.forEach( function ( v ) {
				html += '<li>„' + esc( v.titel ) + '“ ' + ( v.formal ? '<em>(formal – muss auf einem früheren Halt liegen)</em>' : '<em>(empfohlen)</em>' ) + '</li>';
			} );
			html += '</ul>';
		}
		box.innerHTML = html;
		box.hidden = false;
		box._daten = d;

		if ( automatisch ) {
			if ( ! zeilen( fragenFeld.value ).length && d.fragen.length ) {
				fragenFeld.value = d.fragen.slice( 0, MAX_FRAGEN ).join( '\n' );
			}
			if ( ! zeilen( el( k, 'punkte' ).value ).length && d.punkte.length ) {
				el( k, 'punkte' ).value = d.punkte.join( '\n' );
			}
		}
	}

	function analysieren( k, automatisch ) {
		var titel = wert( k, 'titel' );
		var box = k.querySelector( '.bil-vorschlaege' );
		if ( ! zeilen( titel ).length ) {
			box.innerHTML = '<p>Erst Seminartitel eintragen oder über die Suche hinzufügen.</p>';
			box.hidden = false;
			return Promise.resolve();
		}
		box.innerHTML = '<p>Lese die Themen der Seminare …</p>';
		box.hidden = false;
		return ajax( 'bi_fahrplan_analyse', { titel: titel } ).then( function ( d ) {
			zeigeVorschlaege( k, d, automatisch );
			return d;
		} ).catch( function ( e ) {
			box.innerHTML = '<p>Vorschläge konnten nicht geladen werden: ' + esc( e.message ) + '</p>';
		} );
	}

	/* ---------- Seminarsuche ---------- */

	var timer = null;

	function suchen( wrap ) {
		var input = wrap.querySelector( 'input' );
		var ul = wrap.querySelector( '.bil-suche__treffer' );
		var q = input.value.trim();
		if ( q.length < 2 ) {
			ul.hidden = true;
			return;
		}
		ajax( 'bi_fahrplan_suche', { q: q } ).then( function ( treffer ) {
			if ( input.value.trim() !== q ) {
				return; // inzwischen weitergetippt
			}
			if ( ! treffer.length ) {
				ul.innerHTML = '<li class="bil-suche__leer">Keine kommenden Seminare gefunden.</li>';
			} else {
				ul.innerHTML = treffer.map( function ( t ) {
					return '<li><button type="button" class="bil-suche__wahl" data-titel="' + esc( t.titel ) + '">' +
						'<strong>' + esc( t.titel ) + '</strong>' +
						'<span>' + t.anzahl + ( t.anzahl === 1 ? ' Termin' : ' Termine' ) + ' · ab ' + esc( t.naechster ) +
						( t.online ? ' · ' + t.online + ' online' : '' ) +
						( t.themenfeld ? ' · ' + esc( t.themenfeld ) : '' ) + '</span></button>' +
						'<button type="button" class="button-link bil-suche__stern" data-titel="' + esc( t.titel ) + '" data-stern="1" title="Alle Titel, die so beginnen (z. B. alle Teile)">alle, die so beginnen</button></li>';
				} ).join( '' );
			}
			ul.hidden = false;
		} ).catch( function () {
			ul.hidden = true;
		} );
	}

	function waehle( wrap, titel, stern ) {
		var muster = stern ? titel.replace( /\s*(-|–|:)?\s*(teil\s*\d+.*)?$/i, '' ) + '*' : titel;
		var input = wrap.querySelector( 'input' );
		wrap.querySelector( '.bil-suche__treffer' ).hidden = true;
		input.value = '';

		if ( wrap.getAttribute( 'data-bil-suche' ) === 'neu' ) {
			var k = neuerKasten();
			el( k, 'name' ).value = titel;
			titelDazu( k, muster );
			k.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			analysieren( k, true ).then( function ( d ) {
				if ( d && d.art ) {
					setzeArt( k, d.art );
				}
				vorschau();
			} );
			return;
		}
		var kasten = wrap.closest( '.bil-kasten-form' );
		titelDazu( kasten, muster );
		analysieren( kasten, true );
	}

	document.addEventListener( 'input', function ( e ) {
		var wrap = e.target.closest && e.target.closest( '[data-bil-suche]' );
		if ( wrap ) {
			clearTimeout( timer );
			timer = setTimeout( function () {
				suchen( wrap );
			}, 250 );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		var wrap = e.target.closest && e.target.closest( '[data-bil-suche]' );
		if ( ! wrap ) {
			return;
		}
		if ( e.key === 'Enter' ) {
			e.preventDefault(); // nicht den Beitrag absenden
			var erste = wrap.querySelector( '.bil-suche__wahl' );
			if ( erste && ! wrap.querySelector( '.bil-suche__treffer' ).hidden ) {
				waehle( wrap, erste.getAttribute( 'data-titel' ), false );
			}
		} else if ( e.key === 'Escape' ) {
			wrap.querySelector( '.bil-suche__treffer' ).hidden = true;
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest && e.target.closest( '.bil-suche__wahl, .bil-suche__stern' );
		if ( b ) {
			waehle( b.closest( '[data-bil-suche]' ), b.getAttribute( 'data-titel' ), !! b.getAttribute( 'data-stern' ) );
			return;
		}
		// Klick daneben schließt offene Trefferlisten
		document.querySelectorAll( '.bil-suche__treffer:not([hidden])' ).forEach( function ( ul ) {
			if ( ! ul.parentNode.contains( e.target ) ) {
				ul.hidden = true;
			}
		} );
	} );

	/* ---------- Halte bearbeiten ---------- */

	liste.addEventListener( 'click', function ( e ) {
		var knopf = e.target.closest( 'button' );
		var kasten = knopf ? knopf.closest( '.bil-kasten-form' ) : null;
		if ( ! kasten ) {
			return;
		}
		if ( knopf.classList.contains( 'bil-loeschen' ) ) {
			var name = wert( kasten, 'name' ) || wert( kasten, 'id' );
			if ( window.confirm( 'Halt „' + name + '“ entfernen? Wirksam erst beim Speichern.' ) ) {
				kasten.remove();
			}
		} else if ( knopf.classList.contains( 'bil-hoch' ) && kasten.previousElementSibling ) {
			liste.insertBefore( kasten, kasten.previousElementSibling );
		} else if ( knopf.classList.contains( 'bil-runter' ) && kasten.nextElementSibling ) {
			liste.insertBefore( kasten.nextElementSibling, kasten );
		} else if ( knopf.classList.contains( 'bil-vorschlagen' ) ) {
			analysieren( kasten, false );
		} else if ( knopf.hasAttribute( 'data-vs-frage' ) ) {
			var f = el( kasten, 'fragen' );
			var da = zeilen( f.value );
			var neu = knopf.getAttribute( 'data-vs-frage' );
			if ( da.indexOf( neu ) >= 0 ) {
				return;
			}
			if ( da.length >= MAX_FRAGEN ) {
				window.alert( 'Es sind schon ' + MAX_FRAGEN + ' Fragen eingetragen. Lösche erst eine, dann kannst du diese übernehmen.' );
				return;
			}
			da.push( neu );
			f.value = da.join( '\n' );
			knopf.disabled = true;
		} else if ( knopf.hasAttribute( 'data-vs-art' ) ) {
			setzeArt( kasten, knopf.getAttribute( 'data-vs-art' ) );
		} else if ( knopf.hasAttribute( 'data-vs-punkte' ) ) {
			var box = kasten.querySelector( '.bil-vorschlaege' );
			if ( box._daten ) {
				el( kasten, 'punkte' ).value = box._daten.punkte.join( '\n' );
			}
		}
	} );

	// Rahmenfarbe folgt der Art.
	liste.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( '[data-f="art"]' ) ) {
			setzeArt( e.target.closest( '.bil-kasten-form' ), e.target.value );
		}
	} );

	var neu = document.getElementById( 'bil-kasten-neu' );
	if ( neu && vorlage ) {
		neu.addEventListener( 'click', function () {
			var k = neuerKasten();
			el( k, 'name' ).focus();
		} );
	}

	/* ---------- Live-Vorschau ---------- */

	var vorschauEl = document.getElementById( 'bil-vorschau' );

	function vorschau() {
		if ( ! vorschauEl ) {
			return;
		}
		vorschauEl.classList.add( 'is-laedt' );
		var titel = document.getElementById( 'title' );
		var ziel = document.getElementById( 'bil-zielgruppe' );
		ajax( 'bi_fahrplan_vorschau', {
			kaesten: JSON.stringify( sammeln() ),
			titel: titel ? titel.value : '',
			ziel: ziel ? ziel.value : ''
		} ).then( function ( html ) {
			vorschauEl.innerHTML = html;
			if ( window.BIFahrplan ) {
				window.BIFahrplan.init( vorschauEl );
			}
		} ).catch( function ( e ) {
			vorschauEl.innerHTML = '<p>Vorschau nicht möglich: ' + esc( e.message ) + '</p>';
		} ).then( function () {
			vorschauEl.classList.remove( 'is-laedt' );
		} );
	}

	var vorschauKnopf = document.getElementById( 'bil-vorschau-neu' );
	if ( vorschauKnopf ) {
		vorschauKnopf.addEventListener( 'click', vorschau );
	}
	vorschau();

	/* ---------- Speichern ---------- */

	if ( feld.form ) {
		feld.form.addEventListener( 'submit', function () {
			feld.value = JSON.stringify( sammeln() );
		} );
	}
} )();
