/**
 * Bildungsfahrplan – Bildungswege im Browser.
 *
 *  1. Auswahl des Weges (Knöpfe „Für …“), merkt sich den Weg im Anker (#bildungsweg-…).
 *  2. „Wo steigst du ein?“
 *     - „Ich fange neu an“: markiert die erste Spalte.
 *     - „Ich habe schon Vorwissen“: Ja/Nein-Fragen Haltestelle für Haltestelle
 *       in Fahrtrichtung (Halt für Halt). Die erste Antwort „nein“ in einem
 *       Kasten macht ihn zum Einstieg. Nach der Spalte mit einem solchen
 *       Kasten ist Schluss – weiter rechts wird nicht mehr gefragt.
 *     - Übersprungene Kästen mit formaler Voraussetzung („pflicht“) werden
 *       nie freigegeben, sondern als „Quereinstieg klären“ markiert.
 *
 *  Nichts wird gespeichert oder an den Server geschickt.
 *
 *  Die Logik steckt in „Weg“ (rein, ohne DOM) und wird in
 *  tests/test-fahrplan-weg.js mit Node geprüft.
 */
( function ( global ) {
	'use strict';

	var OHNE_FRAGEN = { referent: true, optional: true };

	function fragbar( k ) {
		return ! OHNE_FRAGEN[ k.art ] && k.fragen && k.fragen.length > 0;
	}

	function nachId( kaesten ) {
		var m = {};
		kaesten.forEach( function ( k ) {
			m[ k.id ] = k;
		} );
		return m;
	}

	function kopie( s ) {
		return JSON.parse( JSON.stringify( s ) );
	}

	var Weg = {
		/** Spalten mit abfragbaren Kästen: [[ids], …] von links nach rechts. */
		plan: function ( kaesten ) {
			var spalten = {};
			kaesten.forEach( function ( k ) {
				if ( fragbar( k ) ) {
					( spalten[ k.stufe ] = spalten[ k.stufe ] || [] ).push( k.id );
				}
			} );
			return Object.keys( spalten ).map( Number ).sort( function ( a, b ) {
				return a - b;
			} ).map( function ( s ) {
				return spalten[ s ];
			} );
		},

		start: function ( kaesten ) {
			var plan = Weg.plan( kaesten );
			return { plan: plan, si: 0, ki: 0, fi: 0, status: {}, fertig: plan.length === 0, verlauf: [] };
		},

		/** Aktuelle Frage oder null. */
		frage: function ( s, kaesten ) {
			if ( s.fertig ) {
				return null;
			}
			var k = nachId( kaesten )[ s.plan[ s.si ][ s.ki ] ];
			var gefragt = 0;
			var gesamt = 0;
			s.plan.forEach( function ( sp ) {
				gesamt += sp.length;
			} );
			for ( var i = 0; i < s.si; i++ ) {
				gefragt += s.plan[ i ].length;
			}
			return {
				kasten: k,
				text: k.fragen[ s.fi ],
				nr: s.fi + 1,
				von: k.fragen.length,
				kastenNr: gefragt + s.ki + 1,
				kastenVon: gesamt
			};
		},

		/** Antwort geben; liefert einen neuen Zustand. */
		antwort: function ( s, kaesten, ja ) {
			if ( s.fertig ) {
				return s;
			}
			var n = kopie( s );
			n.verlauf.push( { si: s.si, ki: s.ki, fi: s.fi, status: kopie( s.status ) } );
			var spalte = n.plan[ n.si ];
			var k = nachId( kaesten )[ spalte[ n.ki ] ];

			var kastenFertig = false;
			if ( ! ja ) {
				n.status[ k.id ] = 'start';
				kastenFertig = true;
			} else if ( n.fi + 1 >= k.fragen.length ) {
				n.status[ k.id ] = 'kann';
				kastenFertig = true;
			} else {
				n.fi++;
			}
			if ( ! kastenFertig ) {
				return n;
			}
			n.fi = 0;
			n.ki++;
			if ( n.ki < spalte.length ) {
				return n;
			}
			// Spalte fertig: Gab es einen Einstieg, ist hier Schluss.
			var stopp = spalte.some( function ( id ) {
				return n.status[ id ] === 'start';
			} );
			n.ki = 0;
			n.si++;
			if ( stopp || n.si >= n.plan.length ) {
				n.fertig = true;
			}
			return n;
		},

		/** Eine Frage zurück. */
		zurueck: function ( s ) {
			if ( ! s.verlauf.length ) {
				return s;
			}
			var n = kopie( s );
			var v = n.verlauf.pop();
			n.si = v.si;
			n.ki = v.ki;
			n.fi = v.fi;
			n.status = v.status;
			n.fertig = false;
			return n;
		},

		/**
		 * Ergebnis.
		 *  start   – hier einsteigen
		 *  kann    – kennst du schon
		 *  klaeren – kennst du schon, ist aber formale Voraussetzung: Quereinstieg klären
		 *  weiter  – alles gekonnt: was rechts davon kommt (z. B. Referent*in)
		 */
		ergebnis: function ( s, kaesten ) {
			var r = { start: [], kann: [], klaeren: [], weiter: [] };
			var maxStufe = 0;
			kaesten.forEach( function ( k ) {
				var st = s.status[ k.id ];
				if ( st === 'start' ) {
					r.start.push( k.id );
				} else if ( st === 'kann' ) {
					r.kann.push( k.id );
					if ( k.pflicht ) {
						r.klaeren.push( k.id );
					}
				}
				if ( st ) {
					maxStufe = Math.max( maxStufe, k.stufe );
				}
			} );
			if ( ! r.start.length ) {
				kaesten.forEach( function ( k ) {
					if ( k.stufe > maxStufe && k.art !== 'optional' ) {
						r.weiter.push( k.id );
					}
				} );
			}
			// „Klären“ nur, wenn danach wirklich etwas kommt.
			if ( ! r.start.length && ! r.weiter.length ) {
				r.klaeren = [];
			}
			return r;
		},

		/** „Ich fange neu an“: die erste Spalte mit abfragbaren Kästen. */
		neu: function ( kaesten ) {
			var plan = Weg.plan( kaesten );
			return { start: plan.length ? plan[ 0 ].slice() : [], kann: [], klaeren: [], weiter: [] };
		}
	};

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = Weg;
	}
	if ( typeof document === 'undefined' ) {
		return;
	}

	/* ------------------------------------------------------------------
	 *  Oberfläche
	 * ------------------------------------------------------------------ */

	function esc( s ) {
		return String( s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function namen( ids, m ) {
		var n = ids.map( function ( id ) {
			return '„' + esc( m[ id ].name ) + '“';
		} );
		if ( n.length < 2 ) {
			return n.join( '' );
		}
		return n.slice( 0, -1 ).join( ', ' ) + ' und ' + n[ n.length - 1 ];
	}

	function initWeg( sektion ) {
		var datenEl = sektion.querySelector( '[data-bil-daten]' );
		var panel = sektion.querySelector( '[data-bil-einstieg]' );
		if ( ! datenEl || ! panel ) {
			return;
		}
		var daten;
		try {
			daten = JSON.parse( datenEl.textContent );
		} catch ( e ) {
			return;
		}
		var kaesten = daten.kaesten || [];
		var m = nachId( kaesten );
		var wahl = panel.querySelector( '[data-bil-start]' );
		var frageEl = panel.querySelector( '[data-bil-frage]' );
		var ergebnisEl = panel.querySelector( '[data-bil-ergebnis]' );
		var zustand = null;

		if ( ! Weg.plan( kaesten ).length ) {
			panel.hidden = true;
			return;
		}

		function kastenEl( id ) {
			return sektion.querySelector( '[data-bil-kasten="' + id + '"]' );
		}

		function markieren( r, aktiv, zwischenstand ) {
			sektion.querySelectorAll( '[data-bil-kasten]' ).forEach( function ( el ) {
				el.classList.remove( 'is-start', 'is-kann', 'is-klaeren', 'is-aktiv', 'is-leise' );
				var marke = el.querySelector( '[data-bil-marke]' );
				if ( marke ) {
					marke.hidden = true;
					marke.textContent = '';
				}
			} );
			if ( aktiv ) {
				var a = kastenEl( aktiv );
				if ( a ) {
					a.classList.add( 'is-aktiv' );
				}
			}
			if ( ! r ) {
				return;
			}
			function setze( ids, klasse, text ) {
				ids.forEach( function ( id ) {
					var el = kastenEl( id );
					if ( ! el ) {
						return;
					}
					el.classList.add( klasse );
					var marke = el.querySelector( '[data-bil-marke]' );
					if ( marke ) {
						marke.textContent = text;
						marke.hidden = false;
					}
				} );
			}
			setze( r.kann, 'is-kann', 'Kennst du schon' );
			setze( r.klaeren, 'is-klaeren', 'Kennst du schon – Quereinstieg klären' );
			setze( r.start, 'is-start', zwischenstand ? 'Hier steigst du ein (vorläufig)' : 'Hier steigst du ein' );
			setze( r.weiter, 'is-start', 'Als Nächstes' );
			// Alles, was weder Einstieg noch Ziel ist, tritt zurück – erst im Ergebnis.
			if ( ! zwischenstand && ( r.start.length || r.weiter.length ) ) {
				kaesten.forEach( function ( k ) {
					if ( r.start.indexOf( k.id ) < 0 && r.weiter.indexOf( k.id ) < 0 ) {
						var el = kastenEl( k.id );
						if ( el ) {
							el.classList.add( 'is-leise' );
						}
					}
				} );
			}
		}

		function zeigeFrage() {
			var f = Weg.frage( zustand, kaesten );
			if ( ! f ) {
				zeigeErgebnis( Weg.ergebnis( zustand, kaesten ), false );
				return;
			}
			frageEl.innerHTML =
				'<p class="bil-frage__wo"><span class="bil-frage__kasten bil-kasten--' + esc( f.kasten.art ) + '">' + esc( f.kasten.name ) + '</span>' +
				'<span class="bil-frage__zaehler">Halt ' + f.kastenNr + ' von ' + f.kastenVon +
				( f.von > 1 ? ' · Frage ' + f.nr + ' von ' + f.von : '' ) + '</span></p>' +
				'<p class="bil-frage__text">' + esc( f.text ) + '</p>' +
				'<div class="bil-frage__knoepfe">' +
				'<button type="button" class="bil-knopf bil-knopf--ja" data-bil-antwort="ja">Ja</button>' +
				'<button type="button" class="bil-knopf bil-knopf--nein" data-bil-antwort="nein">Nein / nicht sicher</button>' +
				'</div>' +
				'<p class="bil-frage__nav">' +
				( zustand.verlauf.length ? '<button type="button" class="bil-link" data-bil-zurueck>← Zurück</button>' : '' ) +
				'<button type="button" class="bil-link" data-bil-abbruch>Abbrechen</button></p>';
			frageEl.hidden = false;
			ergebnisEl.hidden = true;
			wahl.hidden = true;
			// Zwischenstand: schon beantwortete Haltestellen markieren, nichts abdunkeln.
			var zwischen = { start: [], kann: [], klaeren: [], weiter: [] };
			Object.keys( zustand.status ).forEach( function ( id ) {
				zwischen[ zustand.status[ id ] === 'start' ? 'start' : 'kann' ].push( id );
			} );
			markieren( zwischen, f.kasten.id, true );
			var ja = frageEl.querySelector( '[data-bil-antwort="ja"]' );
			if ( ja ) {
				ja.focus( { preventScroll: true } );
			}
		}

		function zeigeErgebnis( r, neu ) {
			var html = '';
			if ( r.start.length ) {
				html += '<h4 class="bil-ergebnis__titel">' + ( neu ? 'Dein Weg beginnt hier' : 'Hier steigst du ein' ) + '</h4>' +
					'<p>' + namen( r.start, m ) + '</p>';
			} else if ( r.weiter.length ) {
				html += '<h4 class="bil-ergebnis__titel">Du kennst dich schon gut aus</h4>' +
					'<p>Als Nächstes passt ' + namen( r.weiter, m ) + '.</p>';
			} else {
				html += '<h4 class="bil-ergebnis__titel">Du kennst dich schon gut aus</h4>' +
					'<p>Alle Kästen dieses Weges kennst du schon. Sprich mit deiner Geschäftsstelle, was als Nächstes passt.</p>';
			}
			if ( r.klaeren.length ) {
				html += '<p class="bil-ergebnis__klaeren"><strong>Quereinstieg klären:</strong> Du überspringst ' + namen( r.klaeren, m ) +
					'. Die Teilnahme dort ist für die nächsten Seminare formal Voraussetzung. ' +
					'Kläre den Quereinstieg vorher mit deiner Geschäftsstelle oder dem Bildungszentrum.</p>';
			}
			if ( r.kann.length > r.klaeren.length ) {
				var nurKann = r.kann.filter( function ( id ) {
					return r.klaeren.indexOf( id ) < 0;
				} );
				html += '<p class="bil-ergebnis__kann">Kennst du schon: ' + namen( nurKann, m ) + '.</p>';
			}
			html += '<p class="bil-ergebnis__knoepfe">';
			if ( r.start.length || r.weiter.length ) {
				html += '<button type="button" class="bil-knopf bil-knopf--ja" data-bil-hin>Zu den Seminaren</button>';
			}
			html += '<button type="button" class="bil-link" data-bil-neu>Noch einmal</button></p>';
			ergebnisEl.innerHTML = html;
			ergebnisEl.hidden = false;
			frageEl.hidden = true;
			wahl.hidden = true;
			markieren( r, null );
			ergebnisEl._ziel = ( r.start.length ? r.start : r.weiter )[ 0 ] || null;
		}

		function zuruecksetzen() {
			zustand = null;
			frageEl.hidden = true;
			ergebnisEl.hidden = true;
			wahl.hidden = false;
			markieren( null, null );
		}

		panel.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( 'button' );
			if ( ! b ) {
				return;
			}
			var modus = b.getAttribute( 'data-bil-modus' );
			if ( modus === 'neu' ) {
				zeigeErgebnis( Weg.neu( kaesten ), true );
			} else if ( modus === 'vorwissen' ) {
				zustand = Weg.start( kaesten );
				zeigeFrage();
			} else if ( b.hasAttribute( 'data-bil-antwort' ) ) {
				zustand = Weg.antwort( zustand, kaesten, b.getAttribute( 'data-bil-antwort' ) === 'ja' );
				zeigeFrage();
			} else if ( b.hasAttribute( 'data-bil-zurueck' ) ) {
				zustand = Weg.zurueck( zustand );
				zeigeFrage();
			} else if ( b.hasAttribute( 'data-bil-abbruch' ) || b.hasAttribute( 'data-bil-neu' ) ) {
				zuruecksetzen();
				var erster = wahl.querySelector( 'button' );
				if ( erster ) {
					erster.focus( { preventScroll: true } );
				}
			} else if ( b.hasAttribute( 'data-bil-hin' ) && ergebnisEl._ziel ) {
				var el = kastenEl( ergebnisEl._ziel );
				if ( el ) {
					var d = el.querySelector( 'details' );
					if ( d ) {
						d.open = true;
					}
					el.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				}
			}
		} );
	}

	function initAuswahl( wurzel ) {
		var knoepfe = Array.prototype.slice.call( wurzel.querySelectorAll( '[data-bil-tab]' ) );
		if ( ! knoepfe.length ) {
			return;
		}
		function waehle( id, anker ) {
			knoepfe.forEach( function ( k ) {
				var an = k.getAttribute( 'data-bil-tab' ) === id;
				k.setAttribute( 'aria-selected', an ? 'true' : 'false' );
				k.tabIndex = an ? 0 : -1;
			} );
			wurzel.querySelectorAll( '[data-bil-weg]' ).forEach( function ( s ) {
				s.hidden = s.id !== id;
			} );
			if ( anker && global.history && global.history.replaceState ) {
				global.history.replaceState( null, '', '#' + id );
			}
		}
		knoepfe.forEach( function ( k, i ) {
			k.tabIndex = k.getAttribute( 'aria-selected' ) === 'true' ? 0 : -1;
			k.addEventListener( 'click', function () {
				waehle( k.getAttribute( 'data-bil-tab' ), true );
			} );
			k.addEventListener( 'keydown', function ( e ) {
				var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
				if ( d ) {
					var n = knoepfe[ ( i + d + knoepfe.length ) % knoepfe.length ];
					n.focus();
					waehle( n.getAttribute( 'data-bil-tab' ), true );
					e.preventDefault();
				}
			} );
		} );
		var hash = ( global.location.hash || '' ).replace( /^#/, '' );
		if ( hash && knoepfe.some( function ( k ) {
			return k.getAttribute( 'data-bil-tab' ) === hash;
		} ) ) {
			waehle( hash, false );
		}
	}

	function init( wurzel ) {
		( wurzel || document ).querySelectorAll( '[data-bil]' ).forEach( function ( w ) {
			if ( w.getAttribute( 'data-bil-bereit' ) ) {
				return;
			}
			w.setAttribute( 'data-bil-bereit', '1' );
			initAuswahl( w );
			w.querySelectorAll( '[data-bil-weg]' ).forEach( initWeg );
		} );
	}

	// Für die Live-Vorschau im Editor (fahrplan-admin.js).
	global.BIFahrplan = { init: init };

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}
}( typeof window !== 'undefined' ? window : this ) );
