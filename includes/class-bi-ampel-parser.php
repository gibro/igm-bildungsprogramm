<?php
/**
 * Parser für die Termintabelle eines Seminar-Fragments von modules.igmetall.de.
 *
 * Bewusst OHNE WordPress-Abhängigkeit: die Klasse bekommt HTML herein und gibt
 * ein Array heraus. Damit lässt sie sich gegen gespeicherte Beispieldateien
 * testen (tests/test-ampel-parser.php), und ein Relaunch der Quelle bricht
 * genau dieses Modul – nicht die halbe Integration.
 *
 * ── Aufbau der Quelle (am Livesystem verifiziert, 11.08.2026) ────────────────
 *   <table class="dn-xs dn-md">
 *     <thead><tr><th>Standort</th><th>Zeitraum</th><th>Seminar-Nr.</th><th>Verfügbarkeit</th></tr></thead>
 *     <tbody>
 *       <tr>
 *         <td><b>Inzell</b></td>
 *         <td><i class="icon-igm-calendar-simple"></i>&nbsp;14.09.2026 - 18.09.2026</td>
 *         <td>K00026383</td>
 *         <td><span class="dot orange"></span><span>Fast ausgebucht</span></td>
 *       </tr>
 *     </tbody>
 *   </table>
 *
 * ── Zwei Fallen, die hier abgefangen werden ──────────────────────────────────
 *  1. Doppeltes Markup: dieselben Termine stehen ein zweites Mal als
 *     Mobil-Ansicht (<div class="dn-lg dn-xl"> mit .box). Wer über alle .dot im
 *     Dokument iteriert, zählt jeden Termin doppelt. Deshalb ausschließlich die
 *     Tabelle mit der Klasse "dn-xs".
 *  2. Spaltenreihenfolge: die Zuordnung läuft über den Text im <thead>, nicht
 *     über feste Indizes. Kommt eine Spalte hinzu, verschiebt sich nichts.
 *
 * ── Datenquelle ist die CSS-Klasse ───────────────────────────────────────────
 * Maßgeblich ist die Klasse am <span class="dot …">, nicht das Textlabel und
 * nicht der Farbwert. Klassennamen sind sprachunabhängig und überstehen
 * Formulierungsänderungen. Das Label wird nur mitgeschrieben und angezeigt.
 *
 * Bekannte Zustände (Labels am Livesystem erhoben):
 *   dot green   -> „Verfügbar"
 *   dot orange  -> „Fast ausgebucht"
 *   dot red     -> „Warteliste möglich"
 * Eine unbekannte Klasse ergibt ampel = '' — der Termin wird gespeichert, aber
 * auf der Website erscheint keine Ampel. Lieber keine als eine falsche.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Ampel_Parser {

	/** Klassen, die als Ampelzustand gelten. Alles andere gilt als unbekannt. */
	const ZUSTAENDE = array( 'green', 'orange', 'red' );

	/**
	 * Termine eines Fragments auslesen.
	 *
	 * @param string $html Vollständiges HTML des seminardetails-iframe.
	 * @return array {
	 *     @type array  $termine    Liste je Termin: seminarnummer, standort,
	 *                              datum_von, datum_bis (Y-m-d oder ''),
	 *                              ampel ('green'|'orange'|'red'|''), label.
	 *     @type string $titel      Seminartitel aus der <h1> (für Abgleich/Debugging).
	 *     @type array  $unbekannt  Aufgetretene, nicht zugeordnete dot-Klassen.
	 * }
	 */
	public static function parse( $html ) {
		$leer = array( 'termine' => array(), 'titel' => '', 'unbekannt' => array() );

		$html = (string) $html;
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return $leer;
		}

		$doc = new DOMDocument();
		$vorher = libxml_use_internal_errors( true );
		// Die XML-Deklaration zwingt libxml zu UTF-8; ohne sie werden Umlaute
		// als Latin-1 gelesen, sobald das <meta charset> zu spät im Dokument steht.
		$geladen = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $vorher );

		if ( ! $geladen ) {
			return $leer;
		}

		$xpath = new DOMXPath( $doc );

		$ergebnis = $leer;
		$ergebnis['titel'] = self::titel( $xpath );

		// Nur die Desktop-Tabelle. concat/space: trifft die Klasse als ganzes Wort.
		$tabellen = $xpath->query( "//table[contains(concat(' ', normalize-space(@class), ' '), ' dn-xs ')]" );
		if ( ! $tabellen || 0 === $tabellen->length ) {
			return $ergebnis;
		}
		$tabelle = $tabellen->item( 0 );

		$spalten = self::spaltenindex( $xpath, $tabelle );

		$zeilen = $xpath->query( './/tbody/tr', $tabelle );
		if ( ! $zeilen || 0 === $zeilen->length ) {
			// Manche Fragmente liefern <tr> ohne <tbody>-Element im Quelltext;
			// libxml ergänzt es zwar meist, aber verlassen wollen wir uns nicht darauf.
			$zeilen = $xpath->query( './/tr[td]', $tabelle );
		}
		if ( ! $zeilen ) {
			return $ergebnis;
		}

		foreach ( $zeilen as $zeile ) {
			$zellen = $xpath->query( './td', $zeile );
			if ( ! $zellen || 0 === $zellen->length ) {
				continue;
			}

			$nummer = self::zelle_text( $zellen, $spalten['nummer'] );
			$nummer = self::nummer_normalisieren( $nummer );
			if ( '' === $nummer ) {
				continue; // ohne Seminarnummer ist die Zeile für uns wertlos
			}

			list( $von, $bis ) = self::zeitraum( self::zelle_text( $zellen, $spalten['zeitraum'] ) );
			list( $ampel, $label, $roh ) = self::ampel( $xpath, $zellen, $spalten['ampel'] );

			if ( '' !== $roh && ! in_array( $roh, self::ZUSTAENDE, true ) ) {
				$ergebnis['unbekannt'][ $roh ] = $roh;
			}

			$ergebnis['termine'][] = array(
				'seminarnummer' => $nummer,
				'standort'      => self::zelle_text( $zellen, $spalten['standort'] ),
				'datum_von'     => $von,
				'datum_bis'     => $bis,
				'ampel'         => $ampel,
				'label'         => $label,
			);
		}

		$ergebnis['unbekannt'] = array_values( $ergebnis['unbekannt'] );
		return $ergebnis;
	}

	/** ---------- Einzelteile ---------- */

	/** Seminartitel aus der ersten <h1>. Nur für Abgleich und Fehlersuche. */
	private static function titel( DOMXPath $xpath ) {
		$h1 = $xpath->query( '//h1' );
		if ( ! $h1 || 0 === $h1->length ) {
			return '';
		}
		return self::text( $h1->item( 0 ) );
	}

	/**
	 * Spaltenzuordnung über die Kopfzeile.
	 *
	 * Fällt auf die beobachtete Reihenfolge zurück, wenn eine Überschrift fehlt –
	 * dann steht die Tabelle zwar anders da als erwartet, aber ein leerer Import
	 * wäre die schlechtere Antwort. Die Seminarnummer wird ohnehin gegen ihr
	 * Format geprüft, eine Fehlzuordnung fliegt also auf.
	 */
	private static function spaltenindex( DOMXPath $xpath, DOMNode $tabelle ) {
		$standard = array( 'standort' => 0, 'zeitraum' => 1, 'nummer' => 2, 'ampel' => 3 );
		$muster   = array(
			'standort' => array( 'standort', 'ort', 'bildungszentrum' ),
			'zeitraum' => array( 'zeitraum', 'termin', 'datum' ),
			'nummer'   => array( 'seminarnr', 'seminarnummer', 'nummer', 'nr' ),
			'ampel'    => array( 'verfugbarkeit', 'verfuegbarkeit', 'status', 'belegung' ),
		);

		$kopf = $xpath->query( './/thead//th', $tabelle );
		if ( ! $kopf || 0 === $kopf->length ) {
			return $standard;
		}

		$gefunden = array();
		for ( $i = 0; $i < $kopf->length; $i++ ) {
			$norm = self::normalisieren( self::text( $kopf->item( $i ) ) );
			if ( '' === $norm ) {
				continue;
			}
			foreach ( $muster as $ziel => $begriffe ) {
				if ( isset( $gefunden[ $ziel ] ) ) {
					continue;
				}
				foreach ( $begriffe as $begriff ) {
					if ( false !== strpos( $norm, $begriff ) ) {
						$gefunden[ $ziel ] = $i;
						break 2;
					}
				}
			}
		}

		return array_merge( $standard, $gefunden );
	}

	/**
	 * Ampel einer Zelle bestimmen.
	 *
	 * @return array [ ampel, label, rohe-klasse ]
	 */
	private static function ampel( DOMXPath $xpath, DOMNodeList $zellen, $index ) {
		$zelle = self::zelle( $zellen, $index );
		if ( ! $zelle ) {
			return array( '', '', '' );
		}

		$punkte = $xpath->query( ".//span[contains(concat(' ', normalize-space(@class), ' '), ' dot ')]", $zelle );
		if ( ! $punkte || 0 === $punkte->length ) {
			return array( '', trim( self::text( $zelle ) ), '' );
		}
		$punkt = $punkte->item( 0 );

		// Zweiter Klassenname neben "dot" ist die Farbe.
		$roh = '';
		foreach ( preg_split( '/\s+/', (string) $punkt->getAttribute( 'class' ) ) as $klasse ) {
			$klasse = strtolower( trim( $klasse ) );
			if ( '' !== $klasse && 'dot' !== $klasse ) {
				$roh = $klasse;
				break;
			}
		}

		// Label ist der Text der Zelle ohne den (leeren) Punkt selbst.
		$label = trim( self::text( $zelle ) );

		$ampel = in_array( $roh, self::ZUSTAENDE, true ) ? $roh : '';
		return array( $ampel, $label, $roh );
	}

	/**
	 * „26.10.2026 - 30.10.2026" -> ['2026-10-26','2026-10-30'].
	 * Ein einzelnes Datum füllt beide Werte. Ohne erkennbares Datum: zwei Leerstrings.
	 */
	private static function zeitraum( $text ) {
		if ( ! preg_match_all( '/(\d{1,2})\.(\d{1,2})\.(\d{4})/', (string) $text, $treffer, PREG_SET_ORDER ) ) {
			return array( '', '' );
		}
		$daten = array();
		foreach ( $treffer as $t ) {
			$daten[] = sprintf( '%04d-%02d-%02d', (int) $t[3], (int) $t[2], (int) $t[1] );
		}
		$von = $daten[0];
		$bis = isset( $daten[1] ) ? $daten[1] : $von;
		return array( $von, $bis );
	}

	/**
	 * Seminarnummer prüfen und vereinheitlichen.
	 *
	 * Der Regelfall ist ein Buchstabe (Bildungszentrum) plus acht Ziffern, etwa
	 * O00026442. Daneben kommen im Bestand vor:
	 *   B00026352Q, K00026271WEB, S0002647B   – Nummer mit Zusatz
	 *   SB00425, SE00825, SF01826             – zwei Buchstaben, kürzere Ziffernfolge
	 * Ein zu enges Muster würde diese Termine stillschweigend verlieren.
	 *
	 * Geprüft wird deshalb nur noch grob: Buchstaben vorn, danach Ziffern,
	 * optional ein Zusatz, insgesamt nur A–Z und 0–9. Das genügt, um eine falsch
	 * zugeordnete Spalte abzufangen – „Bad Orb", „Verfügbar" oder
	 * „26.10.2026 - 30.10.2026" fallen alle durch.
	 *
	 * Beide Seiten (Abgleich und Nachschlagen) laufen durch diese Funktion,
	 * damit Groß-/Kleinschreibung und Leerzeichen keine Rolle spielen.
	 */
	public static function nummer_normalisieren( $wert ) {
		$wert = strtoupper( preg_replace( '/\s+/', '', (string) $wert ) );
		if ( strlen( $wert ) > 24 ) {
			return '';
		}
		return preg_match( '/^[A-Z]{1,3}\d{3,12}[A-Z0-9]{0,6}$/', $wert ) ? $wert : '';
	}

	/** ---------- Kleinkram ---------- */

	private static function zelle( DOMNodeList $zellen, $index ) {
		$index = (int) $index;
		return ( $index >= 0 && $index < $zellen->length ) ? $zellen->item( $index ) : null;
	}

	private static function zelle_text( DOMNodeList $zellen, $index ) {
		$zelle = self::zelle( $zellen, $index );
		return $zelle ? self::text( $zelle ) : '';
	}

	/** Textinhalt eines Knotens, entitätsfrei und mit zusammengefassten Leerzeichen. */
	private static function text( DOMNode $node ) {
		$text = (string) $node->textContent;
		// &nbsp; kommt als U+00A0 an und würde sonst in trim() überleben.
		$text = str_replace( "\xC2\xA0", ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Kleinschreibung, Umlaute aufgelöst, nur a-z0-9 – für den Kopfzeilen-Vergleich. */
	private static function normalisieren( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
		$s = strtr( $s, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss' ) );
		return preg_replace( '/[^a-z0-9]/', '', $s );
	}
}
