/**
 * Ausbildungsreihe – Akkordeon, Ortsfilter und Terminauswahl.
 *
 * Drei voneinander unabhängige Zustände, alle rein im Browser:
 *
 *   Akkordeon    offen/zu je Teil. Ohne JavaScript sind alle Teile offen –
 *                die Inhalte der Reihe sind der Kern der Seite und dürfen
 *                nicht hinter einem Skript verschwinden. Erst dieses Skript
 *                klappt zu und macht Teil 1 zum offenen.
 *   Ortsfilter   je Gruppe ein aktives Bildungszentrum. Er blendet nur
 *                Terminzeilen aus, nie Teile: Läuft ein Teil am gewählten Ort
 *                nicht, erscheint ein Hinweis und die Alternativen bleiben
 *                stehen – eine Lücke im Zeitstrahl sähe aus wie ein Fehler,
 *                obwohl der Teil ganz normal stattfindet.
 *   Auswahl      je Teil höchstens ein Termin (Radio-Verhalten), aber
 *                abwählbar: Ein zweiter Klick auf dieselbe Zeile hebt sie auf.
 *                Radios können das von sich aus nicht, deshalb der Umweg über
 *                den Zustand vor dem Klick.
 *
 * Aus der Auswahl ergeben sich Fortschrittszähler, Statuszeile und der
 * Buchen-Button der Gruppe. Der Button wird erst rot, wenn für jeden
 * ausgeschriebenen Teil ein Termin gewählt ist.
 *
 * ---------------------------------------------------------------------------
 *  VORAUSWAHL BEI „NUR KOMPLETT BUCHBAR"
 * ---------------------------------------------------------------------------
 *  Trägt die Reihe den Haken (data-komplett am Gruppen-Element), setzt das
 *  Skript jeden Teil, für den es genau EINE buchbare Möglichkeit gibt, gleich
 *  selbst. Der Normalfall im Programm ist nämlich der Zeitstrahl ohne
 *  Alternativen: vier Punkte, je ein Termin, vier Klicks ohne Entscheidung –
 *  und erst danach wird der Button rot. Bei einer Reihe, die ohnehin nur am
 *  Stück zu haben ist, ist das eine Hürde und kein Angebot.
 *
 *  ES WIRD NIE GERATEN. Ein Teil mit zwei Terminen bleibt offen; ein Termin,
 *  der ausgebucht ist, wird nicht gesetzt. Und die Statuszeile sagt
 *  „vorausgewählt" statt „gewählt", solange niemand die Auswahl angefasst hat –
 *  eine fertige Auswahl, von der man nicht weiß, wer sie getroffen hat, ist
 *  keine Erleichterung, sondern eine Zumutung.
 *
 *  Der Ortsfilter arbeitet der Vorauswahl zu: Er setzt die Auswahl zurück,
 *  danach greift sie erneut – und was vorher zu dritt zur Wahl stand, ist nach
 *  „Lohr" oft eindeutig und rastet ein.
 */
( function () {
	'use strict';

	function liste( wurzel, selektor ) {
		return Array.prototype.slice.call( wurzel.querySelectorAll( selektor ) );
	}

	/* ---------------- Akkordeon „Inhalte der Reihe" ---------------- */

	function initAkkordeon( kopf, index ) {
		var inhalt = document.getElementById( kopf.getAttribute( 'aria-controls' ) || '' );
		if ( ! inhalt ) {
			return;
		}
		var zeichen = kopf.querySelector( '.igm-teil__zeichen' );

		function setzen( offen ) {
			kopf.setAttribute( 'aria-expanded', offen ? 'true' : 'false' );
			inhalt.hidden = ! offen;
			if ( zeichen ) {
				zeichen.textContent = offen ? '–' : '+';
			}
		}

		setzen( 0 === index ); // Teil 1 offen, der Rest zu
		kopf.addEventListener( 'click', function () {
			setzen( 'true' !== kopf.getAttribute( 'aria-expanded' ) );
		} );
	}

	/* ---------------- Gruppe: Ortsfilter + Auswahl ---------------- */

	function initGruppe( gruppe ) {
		var chips    = liste( gruppe, '.igm-chip' );
		var schritte = liste( gruppe, '.igm-schritt' );
		var zaehler  = gruppe.querySelector( '.igm-fortschritt' );
		var status   = gruppe.querySelector( '.igm-gruppe__status' );
		var button   = gruppe.querySelector( '.igm-gruppe__btn' );
		// Nur vorhanden, wenn die Gruppe über die Geschäftsstelle läuft.
		var panel    = gruppe.querySelector( '.igm-gruppe__gs' );
		var gs       = panel ? panel.querySelector( '.igm-gs-anfrage' ) : null;
		var ort      = '';
		// Haken „Nur komplett buchbar" an der Reihe – schaltet die Vorauswahl.
		var nurKomplett = '1' === gruppe.getAttribute( 'data-komplett' );
		// Hat ein Mensch die Auswahl angefasst? Entscheidet nur darüber, ob die
		// Statuszeile „vorausgewählt" oder „gewählt" sagt.
		var beruehrt = false;

		/** Die Termine eines Teils, die gerade überhaupt wählbar sind. */
		function waehlbare( schritt ) {
			return liste( schritt, '.igm-termin--wahl' ).filter( function ( zeile ) {
				var radio = zeile.querySelector( '.igm-termin__radio' );
				// hidden = vom Ortsfilter ausgeblendet, disabled = ausgebucht.
				return ! zeile.hidden && radio && ! radio.disabled;
			} );
		}

		/**
		 * Eindeutige Teile setzen. Läuft beim Aufbau und nach jedem Ortswechsel,
		 * NICHT bei jedem Auffrischen: Sonst käme ein abgewählter Termin sofort
		 * zurück und die Zeile ließe sich nicht mehr abwählen.
		 */
		function vorbelegen() {
			if ( ! nurKomplett ) {
				return;
			}
			schritte.forEach( function ( schritt ) {
				var moeglich = waehlbare( schritt );
				if ( 1 !== moeglich.length ) {
					return; // keine oder mehrere Möglichkeiten – hier entscheidet ein Mensch
				}
				var radio = moeglich[0].querySelector( '.igm-termin__radio' );
				if ( radio && ! radio.checked ) {
					radio.checked = true;
				}
			} );
		}

		/**
		 * Gibt es einen Teil, der Termine hat, von denen aber keiner buchbar ist?
		 * Dann ist die Gruppe eine Sackgasse – bei „nur komplett" endgültig.
		 */
		function gesperrt() {
			if ( ! nurKomplett ) {
				return false;
			}
			return schritte.some( function ( schritt ) {
				var alle = liste( schritt, '.igm-termin--wahl' ).filter( function ( zeile ) {
					return ! zeile.hidden;
				} );
				// Gar keine Termine heißt „in Abstimmung" – das sagt die Zeile selbst.
				return alle.length > 0 && 0 === waehlbare( schritt ).length;
			} );
		}

		function filtern() {
			schritte.forEach( function ( schritt ) {
				var alle    = liste( schritt, '.igm-termin--wahl' );
				var treffer = alle.filter( function ( zeile ) {
					return ! ort || zeile.getAttribute( 'data-ort' ) === ort;
				} );
				// Kein Treffer am gewählten Ort: alles stehen lassen und sagen, warum.
				var leer    = !! ort && 0 === treffer.length && alle.length > 0;
				var hinweis = schritt.querySelector( '.igm-schritt__hinweis' );

				alle.forEach( function ( zeile ) {
					zeile.hidden = !! ort && ! leer && -1 === treffer.indexOf( zeile );
				} );
				if ( hinweis ) {
					hinweis.hidden = ! leer;
				}
			} );
		}

		/**
		 * Mailtext der Geschäftsstellen-Anfrage aus der aktuellen Auswahl bauen
		 * und in die Attribute schreiben, aus denen gs-anfrage.js den mailto-Link
		 * zusammensetzt.
		 *
		 * Die Attribute zu setzen genügt: Der Text wird erst beim Absenden im
		 * Dialog zusammengesetzt, also immer aus der Auswahl, die in diesem
		 * Moment gilt. Früher hing am Ergebnis ein fertiger mailto-Link, der
		 * hier nachgezogen werden musste – sonst verschickte jemand eine
		 * Auswahl, die er längst geändert hatte.
		 */
		function gsTextSetzen( zeilen ) {
			if ( ! gs ) {
				return;
			}
			var kopf = gs.getAttribute( 'data-gs-kopf' ) || '';
			var fuss = gs.getAttribute( 'data-gs-fuss' ) || '';
			var body = kopf + zeilen.join( '\r\n' ) + '\r\n' + fuss;

			gs.setAttribute( 'data-bi-subject', gs.getAttribute( 'data-gs-betreff' ) || '' );
			gs.setAttribute( 'data-bi-body', body );
		}

		function auffrischen() {
			var buchbar   = 0;
			var gewaehlt  = 0;
			var erste     = '';
			var ids       = [];
			var mailzeilen = [];

			schritte.forEach( function ( schritt ) {
				var radios = liste( schritt, '.igm-termin__radio' );
				if ( radios.length ) {
					buchbar++;
				}
				radios.forEach( function ( radio ) {
					var zeile = radio.closest( '.igm-termin' );
					if ( ! zeile ) {
						return;
					}
					zeile.classList.toggle( 'is-gewaehlt', radio.checked );
					if ( radio.checked ) {
						gewaehlt++;
						ids.push( zeile.getAttribute( 'data-id' ) || '' );
						mailzeilen.push( zeile.getAttribute( 'data-mail' ) || '' );
						if ( ! erste ) {
							erste = zeile.getAttribute( 'data-buchen' ) || '';
						}
					}
				} );
			} );

			gsTextSetzen( mailzeilen );

			var vollstaendig = buchbar > 0 && gewaehlt === buchbar;
			// Buchbar ist die Gruppe erst, wenn ALLE Teile ausgeschrieben und gewählt
			// sind – eine Gruppe mit drei von vier Terminen ist keine halbe Buchung.
			var komplett = vollstaendig && buchbar === schritte.length;

			if ( zaehler ) {
				zaehler.textContent = gewaehlt + ' / ' + buchbar;
				zaehler.classList.toggle( 'is-komplett', vollstaendig );
			}
			if ( status ) {
				var key = 'offen';
				if ( buchbar < schritte.length ) {
					key = 'teilweise';
				} else if ( gesperrt() ) {
					key = 'gesperrt';
				} else if ( vollstaendig ) {
					// Vorausgewählt heißt: Diese Auswahl stammt nicht von der
					// Person vor dem Bildschirm. Das gehört dazugesagt.
					key = ( nurKomplett && ! beruehrt ) ? 'vorbelegt' : 'komplett';
				}
				status.textContent = status.getAttribute( 'data-text-' + key )
					|| status.getAttribute( 'data-text-komplett' ) || '';
			}
			if ( button ) {
				button.classList.toggle( 'igm-btn--sek', ! komplett );
				button.classList.toggle( 'igm-btn--aus', ! komplett );
				button.textContent = button.getAttribute( komplett ? 'data-text-komplett' : 'data-text-offen' ) || '';

				if ( gs ) {
					// Weg über die Geschäftsstelle: Der Button öffnet die PLZ-Suche,
					// er führt nirgendwohin. Solange die Auswahl unvollständig ist,
					// gibt es nichts zu fragen.
					button.disabled = ! komplett;
					if ( ! komplett ) {
						gsPanelSetzen( false );
					}
				} else {
					// Sammelanmeldung: Alle gewählten Termine wandern als Liste ins
					// Anmeldeformular, das daraus eine Anmeldung je Teil macht.
					// Fehlt das Ziel (keine Anmeldeseite eingerichtet), bleibt der
					// Weg über den ersten Termin.
					var sammel = button.getAttribute( 'data-sammel' ) || '';
					var ziel   = '';
					if ( komplett ) {
						ziel = sammel
							? sammel + ( sammel.indexOf( '?' ) === -1 ? '?' : '&' ) + 'termine=' + ids.join( ',' )
							: erste;
					}
					if ( ziel ) {
						button.setAttribute( 'href', ziel );
						button.removeAttribute( 'aria-disabled' );
					} else {
						button.removeAttribute( 'href' );
						button.setAttribute( 'aria-disabled', 'true' );
					}
				}
			}
		}

		/** Die PLZ-Suche auf- oder zuklappen. */
		function gsPanelSetzen( offen ) {
			if ( ! panel || ! button ) {
				return;
			}
			panel.hidden = ! offen;
			button.setAttribute( 'aria-expanded', offen ? 'true' : 'false' );
			if ( offen ) {
				var feld = panel.querySelector( '.igm-gs-anfrage__plz' );
				if ( feld ) {
					feld.focus();
				}
			}
		}

		chips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				ort = chip.getAttribute( 'data-ort' ) || '';
				chips.forEach( function ( c ) {
					var aktiv = c === chip;
					c.classList.toggle( 'is-aktiv', aktiv );
					c.setAttribute( 'aria-pressed', aktiv ? 'true' : 'false' );
				} );
				// Eine Auswahl, die nach dem Filtern nicht mehr sichtbar wäre, ist
				// eine unsichtbare Zusage – deshalb zurücksetzen.
				liste( gruppe, '.igm-termin__radio' ).forEach( function ( r ) {
					r.checked = false;
				} );
				filtern();
				// Was der Filter eindeutig gemacht hat, rastet gleich wieder ein –
				// und die Auswahl stammt danach erneut vom Skript, nicht vom
				// Menschen, also sagt die Statuszeile wieder „vorausgewählt".
				beruehrt = false;
				vorbelegen();
				auffrischen();
			} );
		} );

		liste( gruppe, '.igm-termin--wahl' ).forEach( function ( zeile ) {
			var radio = zeile.querySelector( '.igm-termin__radio' );
			// Ausgebuchte Termine tragen ein deaktiviertes Radio. Ohne diese
			// Abfrage würde der Klick auf die Zeile es trotzdem setzen – über
			// die Eigenschaft lässt sich auch ein gesperrtes Feld ankreuzen.
			if ( ! radio || radio.disabled ) {
				return;
			}
			var warGewaehlt = false;

			// Der Zustand VOR dem Klick; beim click-Ereignis hat der Browser das
			// Radio längst gesetzt und die Frage „war das schon gewählt?" ist nicht
			// mehr zu beantworten.
			zeile.addEventListener( 'pointerdown', function () {
				warGewaehlt = radio.checked;
			} );
			zeile.addEventListener( 'click', function () {
				radio.checked = ! warGewaehlt;
				warGewaehlt   = false;
				beruehrt      = true;
				auffrischen();
			} );

			// Tastatur: Pfeiltasten und Leertaste wählen aus (Abwählen kennt das
			// Radio-Muster dort nicht).
			radio.addEventListener( 'change', function () {
				beruehrt = true;
				auffrischen();
			} );
		} );

		if ( gs && button ) {
			button.addEventListener( 'click', function () {
				gsPanelSetzen( 'true' !== button.getAttribute( 'aria-expanded' ) );
			} );
		}

		filtern();
		vorbelegen();
		auffrischen();
	}

	function init() {
		liste( document, '.igm-teil__kopf' ).forEach( function ( kopf, i ) {
			initAkkordeon( kopf, i );
		} );
		liste( document, '.igm-gruppe' ).forEach( initGruppe );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
