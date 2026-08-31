/**
 * Geschäftsstellen-Anfrage auf der Seminar-Detailseite (GS-Variante).
 *
 * PLZ eingeben -> AJAX-Lookup (bi_plz_lookup) -> zuständige Geschäftsstelle
 * anzeigen und „Anfrage senden" anbieten. Der Knopf öffnet einen Dialog nach
 * den persönlichen Daten; „Absenden" setzt sie in den Text ein und öffnet wie
 * bisher das Mailprogramm. Die letzte erfolgreiche PLZ wird in localStorage
 * gemerkt, damit das Ergebnis beim nächsten Besuch direkt steht.
 *
 * DIE EINGABEN VERLASSEN DEN BROWSER NUR ALS MAIL. Das Formular im Dialog hat
 * kein Ziel auf dem Server, und gespeichert wird davon nichts – anders als die
 * PLZ, die für den nächsten Besuch liegen bleibt und niemandem gehört.
 *
 * Der Mailtext entsteht aus vier Angaben am Widget:
 *   data-bi-subject  Betreff
 *   data-bi-body     Anrede + Seminar/Termine, endet mit einer Leerzeile
 *   data-bi-daten    leerer Datenblock (Rückfall ohne Dialog)
 *   data-bi-gruss    Grußformel, an die der eingegebene Name gehängt wird
 *
 * ── Im Rahmen (iframe) ist beides anders ─────────────────────────────────
 *
 * Auf igmetall.de steht die Seite in einem <iframe>, dessen Höhe die
 * Fremdseite auf die volle Inhaltshöhe zieht (embed.js meldet sie). Damit ist
 * „das Fenster" aus Sicht dieser Seite über zehntausend Pixel hoch – und
 * genau daran sind bisher zwei Dinge gescheitert:
 *
 *   1. showModal() rückt einen Dialog in die MITTE DES FENSTERS. Im Rahmen ist
 *      das die Mitte der ganzen Seite, also mehrere tausend Pixel unterhalb des
 *      Knopfes, den jemand gerade gedrückt hat. Der Dialog war offen, nur eben
 *      nirgends zu sehen; manche Browser rollten die Fremdseite von sich aus
 *      hin, andere nicht – daher „geht bei einigen Browsern nicht".
 *      Deshalb setzen wir ihn im Rahmen selbst dorthin, wo das Widget steht.
 *
 *   2. Ein mailto: ist keine Seite, die in einen Ausschnitt gehört, sondern ein
 *      Auftrag ans Betriebssystem. Aus einem eingebetteten Rahmen heraus lehnen
 *      ihn mehrere Browser ab. Deshalb geht er über einen echten Link mit
 *      target="_top" – die Bewegung, die jeder Browser kennt. Die Seite dahinter
 *      wird dabei nicht verlassen: Adressen, die der Browser an ein anderes
 *      Programm weiterreicht, laden nichts.
 */
(function () {
	'use strict';

	var STORAGE_KEY = 'biGsLookup';

	/**
	 * Stecken wir in einem fremden Rahmen?
	 *
	 * Der Zugriff auf window.top kann bei fremder Herkunft eine Ausnahme
	 * werfen – dann ist die Antwort erst recht „ja". (Dieselbe Prüfung wie in
	 * embed.js; sie hier zu wiederholen ist billiger, als eine Abhängigkeit
	 * zwischen zwei Dateien aufzubauen, die einzeln geladen werden.)
	 */
	function imRahmen() {
		try {
			return window.self !== window.top;
		} catch ( e ) {
			return true;
		}
	}

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

	function attr( el, name ) {
		return el.getAttribute( name ) || '';
	}

	/**
	 * Die fertige mailto-Adresse.
	 *
	 * @param {Element} el     Das Widget mit den data-bi-Angaben.
	 * @param {string}  email  Empfängerin.
	 * @param {string}  daten  Datenblock; leer = der vorbereitete leere Block.
	 * @param {string}  name   Name für die Grußformel; leer = ohne.
	 */
	function mailtoFor( el, email, daten, name ) {
		var body = attr( el, 'data-bi-body' )
			+ ( daten || attr( el, 'data-bi-daten' ) )
			+ '\r\n'
			+ attr( el, 'data-bi-gruss' )
			+ ( name ? name + '\r\n' : '' );

		return 'mailto:' + email
			+ '?subject=' + encodeURIComponent( attr( el, 'data-bi-subject' ) )
			+ '&body=' + encodeURIComponent( body );
	}

	/**
	 * Das Mailprogramm öffnen.
	 *
	 * Außerhalb eines Rahmens bleibt es beim bisherigen Weg. Im Rahmen wird ein
	 * echter Link angelegt und angeklickt: Ein programmgesteuertes Setzen von
	 * location.href gilt im eingebetteten Rahmen vielerorts als Navigation, die
	 * ein Ausschnitt nicht auslösen darf, und wird still verworfen. Ein Klick
	 * auf einen Link mit target="_top" ist dagegen die eine Bewegung, für die
	 * jeder Browser eine Regel hat – und weil mailto: an ein anderes Programm
	 * geht, bleibt die Seite dabei stehen.
	 */
	function mailOeffnen( url ) {
		if ( ! imRahmen() ) {
			window.location.href = url;
			return;
		}

		var a = document.createElement( 'a' );
		a.href = url;
		a.target = '_top';
		a.rel = 'noopener';
		a.style.display = 'none';
		document.body.appendChild( a );
		a.click();
		// Der Link hat seine Aufgabe erfüllt; im Dokument stehen bleiben muss er
		// nicht. Das Aufräumen erst im nächsten Durchlauf, damit der Klick in
		// jedem Fall abgearbeitet ist, bevor das Element verschwindet.
		window.setTimeout( function () {
			if ( a.parentNode ) {
				a.parentNode.removeChild( a );
			}
		}, 0 );
	}

	/* ---- Dialog mit den persönlichen Daten ---------------------------- */

	/**
	 * Aus den ausgefüllten Feldern den Datenblock der Mail bauen.
	 *
	 * Leere Felder fallen weg: Eine Zeile „Betrieb: " ohne Inhalt sagt nichts,
	 * was ihr Fehlen nicht auch sagt – und eine Mail voller leerer Doppelpunkte
	 * sieht aus, als sei das Formular abgestürzt.
	 */
	function datenBlock( form ) {
		var zeilen = [];
		form.querySelectorAll( '[data-bi-label]' ).forEach( function ( feld ) {
			var wert = ( feld.value || '' ).trim();
			if ( wert ) {
				zeilen.push( feld.getAttribute( 'data-bi-label' ) + ': ' + wert );
			}
		} );
		return zeilen.length ? 'Meine Daten:\r\n' + zeilen.join( '\r\n' ) + '\r\n' : '';
	}

	/** Vor- und Nachname aus dem Dialog – für die Grußformel. */
	function nameAus( form ) {
		var vor  = form.querySelector( '[name=vorname]' );
		var nach = form.querySelector( '[name=nachname]' );
		return [ vor && vor.value, nach && nach.value ]
			.map( function ( v ) { return ( v || '' ).trim(); } )
			.filter( Boolean )
			.join( ' ' );
	}

	/**
	 * Rollt in diesem Fenster gar nichts, weil eine Fremdseite es tut?
	 *
	 * Genau das ist die Lage im mitwachsenden Rahmen: embed.js meldet die
	 * Inhaltshöhe, die Fremdseite zieht den <iframe> auf diese Höhe, und damit
	 * ist das „Fenster" so hoch wie die ganze Seite – es gibt nichts mehr zu
	 * rollen, das übernimmt die Seite drumherum.
	 *
	 * Ein Rahmen mit FESTER Höhe verhält sich dagegen wie ein normales Fenster:
	 * Er ist kleiner als sein Inhalt, rollt selbst, und die Fenstermitte ist
	 * genau die richtige Stelle für einen Dialog. Deshalb wird hier nicht nach
	 * „im Rahmen" gefragt, sondern nach dem Unterschied, auf den es ankommt.
	 */
	function fensterOhneEigenenLauf() {
		if ( ! imRahmen() ) {
			return false;
		}
		// Gefragt ist das Wurzelelement, nicht der <body>: Sein scrollHeight ist
		// per Definition mindestens so groß wie das Fenster und darüber hinaus
		// so groß wie der Inhalt. Ist er nicht größer, gibt es nichts zu rollen.
		// Vier Pixel Spiel, weil die gemeldete Höhe gerundet ist und die
		// Fremdseite sie ebenfalls auf ganze Pixel setzt.
		return document.documentElement.scrollHeight <= window.innerHeight + 4;
	}

	/**
	 * Den Dialog dorthin setzen, wo das Widget steht.
	 *
	 * Ein modaler Dialog steht im Browser standardmäßig in der Mitte des
	 * Fensters (position: fixed, oben und unten auf 0, Rand automatisch). Im
	 * mitwachsenden Rahmen ist „das Fenster" die volle Inhaltshöhe – die Mitte
	 * davon liegt mehrere tausend Pixel unter dem Knopf, den jemand gerade
	 * gedrückt hat. Der Dialog war damit offen und trotzdem nicht zu sehen.
	 *
	 * Statt der Fenstermitte nehmen wir die Höhe des Widgets im Dokument:
	 * position: absolute rechnet auch in der obersten Ebene vom Seitenanfang,
	 * nicht vom Fenster. Das Widget ist zu sehen – der Klick kam ja gerade von
	 * dort –, also ist der Dialog es auch. Waagerecht bleibt alles, wie es war:
	 * Überschrieben werden nur die senkrechten Angaben, die Mitte kommt
	 * weiterhin aus den automatischen Seitenrändern.
	 *
	 * ZWEI DINGE MÜSSEN DABEI MITWANDERN.
	 *
	 * Erstens die Höhengrenze. Sie steht im Stylesheet als calc(100vh - 48px) –
	 * und 100vh ist hier die ganze Seite. Ohne eigene Grenze wüchse der Dialog
	 * auf seine volle Inhaltshöhe; in der einspaltigen Ansicht sind das über
	 * zweitausend Pixel. Er bekommt deshalb genau den Platz, der unter dem
	 * Widget noch im Rahmen ist, und rollt darin – so wie er es außerhalb eines
	 * Rahmens im Fenster auch täte.
	 *
	 * Zweitens der Anschlag nach unten. In der einspaltigen Ansicht rutscht die
	 * Seitenspalte ans Seitenende; stünde der Dialog dann dort, ragte er unten
	 * aus dem Rahmen heraus – und was aus dem Rahmen ragt, kann niemand
	 * erreichen: Der Rahmen rollt nicht, und die Fremdseite kann nur zeigen,
	 * was in ihm steht. Bleibt unter dem Widget zu wenig Platz, rückt der
	 * Dialog so weit hoch, dass ein Mindestmaß sichtbar bleibt.
	 */
	function dialogPlatzieren( dlg, widget ) {
		if ( ! fensterOhneEigenenLauf() ) {
			return;
		}

		var LUFT    = 16;    // Abstand zur Rahmenkante
		var MINDEST = 360;   // so viel Dialog muss in jedem Fall in den Rahmen passen

		var rahmen = window.innerHeight;
		var oben   = widget.getBoundingClientRect().top + ( window.pageYOffset || 0 );

		if ( rahmen - oben - LUFT < MINDEST ) {
			oben = Math.max( LUFT, rahmen - MINDEST - LUFT );
		}

		dlg.style.position     = 'absolute';
		dlg.style.top          = Math.round( oben ) + 'px';
		dlg.style.bottom       = 'auto';
		dlg.style.marginTop    = '0';
		dlg.style.marginBottom = '0';
		dlg.style.maxHeight    = Math.max( MINDEST, Math.round( rahmen - oben - LUFT ) ) + 'px';
	}

	/**
	 * Den Dialog öffnen. Kennt der Browser <dialog> nicht – oder verweigert er
	 * showModal() –, geht es den alten Weg weiter: Mail mit leeren Zeilen.
	 * Lieber die Anfrage von früher als ein Knopf, der nichts tut.
	 */
	function anfrageStarten( widget, email ) {
		var dlg = widget.querySelector( '.igm-gs-modal' );
		if ( ! dlg || 'function' !== typeof dlg.showModal ) {
			mailOeffnen( mailtoFor( widget, email, '', '' ) );
			return;
		}

		var form = dlg.querySelector( 'form' );

		// Bei jedem Öffnen frisch anhängen wäre ein zweiter Zuhörer je Klick –
		// und damit ein zweites Mailprogramm.
		if ( ! dlg.hasAttribute( 'data-bi-bereit' ) ) {
			dlg.setAttribute( 'data-bi-bereit', '1' );

			form.addEventListener( 'submit', function ( e ) {
				// Der Browser hat die Pflichtfelder schon geprüft; ohne sie
				// kommt dieses Ereignis gar nicht erst.
				e.preventDefault();
				var ziel = mailtoFor( widget, dlg.getAttribute( 'data-bi-email' ) || '', datenBlock( form ), nameAus( form ) );
				dlg.close();
				mailOeffnen( ziel );
			} );

			var abbrechen = dlg.querySelector( '.igm-gs-modal__abbrechen' );
			if ( abbrechen ) {
				abbrechen.addEventListener( 'click', function () { dlg.close(); } );
			}
		}

		// Die Empfängerin steht erst nach der PLZ-Suche fest und kann sich mit
		// einer zweiten Suche noch ändern.
		dlg.setAttribute( 'data-bi-email', email );

		// Erst setzen, dann öffnen: Ein Dialog, der kurz in der Fenstermitte
		// auftaucht und dann springt, ist ein Ruck ohne Anlass.
		dialogPlatzieren( dlg, widget );

		try {
			dlg.showModal();
		} catch ( e ) {
			// Kommt vor, wenn der Dialog schon offen ist oder eine
			// sandbox-Regel modale Dialoge verbietet. Beides ist kein Grund,
			// die Anfrage fallen zu lassen.
			mailOeffnen( mailtoFor( widget, email, '', '' ) );
		}
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
			// Ein Knopf, kein Link: Dazwischen liegt jetzt der Dialog, und ein
			// Link, dessen Ziel man erst nach dem Ausfüllen kennt, wäre eine
			// Ansage, die nicht stimmt.
			var b = document.createElement( 'button' );
			b.type = 'button';
			b.className = 'igm-btn-buchen igm-gs-anfrage__senden';
			b.textContent = 'Anfrage senden';
			b.addEventListener( 'click', function () {
				anfrageStarten( widget, data.email );
			} );
			result.appendChild( b );
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
