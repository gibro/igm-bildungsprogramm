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
 *     - Weiche (seit 1.156.0): Kommt die Fahrt an eine Weiche, lautet die
 *       nächste Frage „In welche Richtung willst du?“. Danach wird nur der
 *       gewählte Ast gefragt, die anderen treten zurück. Kästen tragen dafür
 *       „weiche“ (Nr., 0 = Hauptstrecke) und „ast“ (Name) – beides rechnet
 *       der Server aus (BI_Fahrplan::lage).
 *  3. Schmal: Richtungsschilder an der Weiche schalten zwischen den Ästen.
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

	/** Stufen aufsteigend, je Stufe die Kästen in gepflegter Reihenfolge. */
	function stufen( kaesten ) {
		var m = {};
		kaesten.forEach( function ( k ) {
			( m[ k.stufe ] = m[ k.stufe ] || [] ).push( k );
		} );
		return Object.keys( m ).map( Number ).sort( function ( a, b ) {
			return a - b;
		} ).map( function ( st ) {
			return m[ st ];
		} );
	}

	/** Liegt der Kasten auf dem Weg, den die Wahl an den Weichen ergibt? */
	function aufWeg( k, wahl ) {
		return ! k.weiche || ! wahl || wahl[ k.weiche ] === undefined || wahl[ k.weiche ] === k.ast;
	}

	var Weg = {
		/**
		 * Plan der Fragen von links nach rechts: [[ids], …]. Eine Weiche ohne
		 * gewählte Richtung steht als { weiche: Nr } im Plan; dahinter wird
		 * erst nach der Wahl weitergeplant.
		 */
		plan: function ( kaesten, wahl ) {
			wahl = wahl || {};
			var out = [];
			var gefragtWeiche = {};
			stufen( kaesten ).forEach( function ( liste ) {
				var w = liste[ 0 ].weiche || 0;
				if ( w && wahl[ w ] === undefined ) {
					if ( ! gefragtWeiche[ w ] ) {
						gefragtWeiche[ w ] = true;
						out.push( { weiche: w } );
					}
					return;
				}
				var ids = [];
				liste.forEach( function ( k ) {
					if ( fragbar( k ) && aufWeg( k, wahl ) ) {
						ids.push( k.id );
					}
				} );
				if ( ids.length ) {
					out.push( ids );
				}
			} );
			// Eine Weiche ganz am Ende ohne einen einzigen abfragbaren Kasten
			// dahinter bleibt trotzdem im Plan: Die Richtung bestimmt „als Nächstes“.
			return out;
		},

		/** Äste einer Weiche: [{ name, kaesten: [k] }] in der Reihenfolge der Strecke. */
		aeste: function ( kaesten, w ) {
			var out = [];
			var idx = {};
			stufen( kaesten ).forEach( function ( liste ) {
				liste.forEach( function ( k ) {
					if ( k.weiche !== w ) {
						return;
					}
					if ( idx[ k.ast ] === undefined ) {
						idx[ k.ast ] = out.length;
						out.push( { name: k.ast, kaesten: [] } );
					}
					out[ idx[ k.ast ] ].kaesten.push( k );
				} );
			} );
			return out;
		},

		start: function ( kaesten ) {
			var plan = Weg.plan( kaesten, {} );
			return { plan: plan, wahl: {}, si: 0, ki: 0, fi: 0, status: {}, fertig: plan.length === 0, verlauf: [] };
		},

		/**
		 * Aktuelle Frage oder null. An einer Weiche:
		 * { weiche: Nr, aeste: [{ name, kaesten }] } statt eines Kastens.
		 */
		frage: function ( s, kaesten ) {
			if ( s.fertig ) {
				return null;
			}
			var schritt = s.plan[ s.si ];
			if ( ! Array.isArray( schritt ) ) {
				return { weiche: schritt.weiche, aeste: Weg.aeste( kaesten, schritt.weiche ) };
			}
			var k = nachId( kaesten )[ schritt[ s.ki ] ];
			var gefragt = 0;
			var gesamt = 0;
			// Noch nicht gewählte Weichen zählen mit ihrem längsten Ast.
			s.plan.forEach( function ( sp, i ) {
				var n = 0;
				if ( Array.isArray( sp ) ) {
					n = sp.length;
				} else {
					Weg.aeste( kaesten, sp.weiche ).forEach( function ( a ) {
						n = Math.max( n, a.kaesten.filter( fragbar ).length );
					} );
				}
				gesamt += n;
				if ( i < s.si ) {
					gefragt += n;
				}
			} );
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
			if ( ! Array.isArray( s.plan[ s.si ] ) ) {
				return s; // an der Weiche zählt nur die Richtung
			}
			var n = kopie( s );
			n.verlauf.push( { si: s.si, ki: s.ki, fi: s.fi, status: kopie( s.status ), wahl: kopie( s.wahl ), plan: kopie( s.plan ) } );
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

		/** Richtung an der Weiche wählen (Name des Astes); liefert einen neuen Zustand. */
		richtung: function ( s, kaesten, ast ) {
			var schritt = s.fertig ? null : s.plan[ s.si ];
			if ( ! schritt || Array.isArray( schritt ) ) {
				return s;
			}
			var n = kopie( s );
			n.verlauf.push( { si: s.si, ki: s.ki, fi: s.fi, status: kopie( s.status ), wahl: kopie( s.wahl ), plan: kopie( s.plan ) } );
			n.wahl[ schritt.weiche ] = ast;
			n.plan = Weg.plan( kaesten, n.wahl );
			n.ki = 0;
			n.fi = 0;
			if ( n.si >= n.plan.length ) {
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
			if ( v.plan ) {
				n.plan = v.plan;
				n.wahl = v.wahl;
			}
			n.fertig = false;
			return n;
		},

		/**
		 * Ergebnis.
		 *  start   – hier einsteigen
		 *  kann    – kennst du schon
		 *  klaeren – kennst du schon, ist aber formale Voraussetzung: Quereinstieg klären
		 *  weiter  – alles gekonnt: was rechts davon kommt (z. B. Referent*in)
		 *  andere  – Kästen auf Ästen, die nicht gewählt wurden
		 *  wahl    – gewählte Richtung je Weiche
		 */
		ergebnis: function ( s, kaesten ) {
			var wahl = s.wahl || {};
			var r = { start: [], kann: [], klaeren: [], weiter: [], andere: [], wahl: kopie( wahl ) };
			kaesten.forEach( function ( k ) {
				if ( ! aufWeg( k, wahl ) ) {
					r.andere.push( k.id );
				}
			} );
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
					if ( k.stufe > maxStufe && k.art !== 'optional' && aufWeg( k, wahl ) ) {
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

		/**
		 * „Ich fange neu an“: die erste Spalte mit abfragbaren Kästen. Beginnt
		 * der Weg mit einer Weiche, der Anfang jedes Astes.
		 */
		neu: function ( kaesten ) {
			var plan = Weg.plan( kaesten, {} );
			var start = [];
			if ( plan.length && Array.isArray( plan[ 0 ] ) ) {
				start = plan[ 0 ].slice();
			} else if ( plan.length ) {
				Weg.aeste( kaesten, plan[ 0 ].weiche ).forEach( function ( a ) {
					var w = {};
					w[ plan[ 0 ].weiche ] = a.name;
					var p = Weg.plan( kaesten, w );
					if ( p.length && Array.isArray( p[ 0 ] ) ) {
						start = start.concat( p[ 0 ] );
					}
				} );
			}
			return { start: start, kann: [], klaeren: [], weiter: [], andere: [], wahl: {} };
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

	/** Schmal: einen Ast zeigen, Schilder umschalten. */
	function zeigeAst( wEl, i ) {
		wEl.querySelectorAll( '[data-bil-ast]' ).forEach( function ( a ) {
			a.classList.toggle( 'is-sichtbar', a.getAttribute( 'data-bil-ast' ) === String( i ) );
		} );
		wEl.querySelectorAll( '[data-bil-richtung]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', b.getAttribute( 'data-bil-richtung' ) === String( i ) ? 'true' : 'false' );
		} );
	}

	function initWeichen( sektion ) {
		sektion.querySelectorAll( '[data-bil-weiche]' ).forEach( function ( wEl ) {
			wEl.setAttribute( 'data-bil-umschalter', '1' );
			zeigeAst( wEl, 0 );
			wEl.addEventListener( 'click', function ( e ) {
				var b = e.target.closest( '[data-bil-richtung]' );
				if ( b ) {
					zeigeAst( wEl, b.getAttribute( 'data-bil-richtung' ) );
				}
			} );
		} );
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
		initWeichen( sektion );
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

		function weicheEl( w ) {
			return sektion.querySelector( '[data-bil-weiche="' + w + '"]' );
		}

		/** Äste nach der Wahl hervorheben; schmal den gewählten zeigen. */
		function aesteMarkieren( wahl, aktiveWeiche ) {
			sektion.querySelectorAll( '[data-bil-weiche]' ).forEach( function ( wEl ) {
				var w = wEl.getAttribute( 'data-bil-weiche' );
				var gewaehlt = wahl && wahl[ w ] !== undefined ? wahl[ w ] : null;
				wEl.classList.toggle( 'is-aktiv', String( aktiveWeiche ) === w );
				wEl.querySelectorAll( '[data-bil-ast]' ).forEach( function ( a ) {
					var name = a.getAttribute( 'data-bil-ast-name' );
					a.classList.toggle( 'is-gewaehlt', gewaehlt !== null && name === gewaehlt );
					a.classList.toggle( 'is-leise', gewaehlt !== null && name !== gewaehlt );
					if ( gewaehlt !== null && name === gewaehlt ) {
						zeigeAst( wEl, a.getAttribute( 'data-bil-ast' ) );
					}
				} );
			} );
		}

		function markieren( r, aktiv, zwischenstand, wahl, aktiveWeiche ) {
			aesteMarkieren( wahl || ( r && r.wahl ) || null, aktiveWeiche );
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
					// Kästen auf nicht gewählten Ästen tritt schon der ganze Ast zurück.
					if ( r.start.indexOf( k.id ) < 0 && r.weiter.indexOf( k.id ) < 0 && ( ! r.andere || r.andere.indexOf( k.id ) < 0 ) ) {
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
			var zwischen = { start: [], kann: [], klaeren: [], weiter: [] };
			Object.keys( zustand.status ).forEach( function ( id ) {
				zwischen[ zustand.status[ id ] === 'start' ? 'start' : 'kann' ].push( id );
			} );
			if ( f.weiche ) {
				zeigeWeiche( f, zwischen );
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
			markieren( zwischen, f.kasten.id, true, zustand.wahl );
			var ja = frageEl.querySelector( '[data-bil-antwort="ja"]' );
			if ( ja ) {
				ja.focus( { preventScroll: true } );
			}
		}

		/** Frage an der Weiche: Richtung wählen. */
		function zeigeWeiche( f, zwischen ) {
			var knoepfe = f.aeste.map( function ( a ) {
				var halte = a.kaesten.map( function ( k ) {
					return k.name;
				} );
				return '<button type="button" class="bil-knopf" data-bil-ast-wahl="' + esc( a.name ) + '">' +
					'<strong>Richtung ' + esc( a.name ) + '</strong>' +
					'<span>' + esc( halte.join( ' · ' ) ) + '</span></button>';
			} ).join( '' );
			frageEl.innerHTML =
				'<p class="bil-frage__wo"><span class="bil-frage__kasten bil-frage__weiche">Weiche</span>' +
				'<span class="bil-frage__zaehler">Hier teilt sich der Weg</span></p>' +
				'<p class="bil-frage__text">In welche Richtung willst du weiter?</p>' +
				'<div class="bil-einstieg__wahl">' + knoepfe + '</div>' +
				'<p class="bil-frage__nav">' +
				( zustand.verlauf.length ? '<button type="button" class="bil-link" data-bil-zurueck>← Zurück</button>' : '' ) +
				'<button type="button" class="bil-link" data-bil-abbruch>Abbrechen</button></p>';
			frageEl.hidden = false;
			ergebnisEl.hidden = true;
			wahl.hidden = true;
			markieren( zwischen, null, true, zustand.wahl, f.weiche );
			var erster = frageEl.querySelector( '[data-bil-ast-wahl]' );
			if ( erster ) {
				erster.focus( { preventScroll: true } );
			}
		}

		function zeigeErgebnis( r, neu ) {
			var html = '';
			var richtungen = Object.keys( r.wahl || {} ).map( function ( w ) {
				return '„' + esc( r.wahl[ w ] ) + '“';
			} );
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
			if ( richtungen.length ) {
				html += '<p>Deine Richtung: ' + richtungen.join( ', dann ' ) + '. Die anderen Äste bleiben sichtbar – du kannst später umsteigen.</p>';
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
			} else if ( b.hasAttribute( 'data-bil-ast-wahl' ) ) {
				zustand = Weg.richtung( zustand, kaesten, b.getAttribute( 'data-bil-ast-wahl' ) );
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
