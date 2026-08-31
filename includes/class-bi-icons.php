<?php
/**
 * Piktogramme für die Seminar- und Reihenseiten.
 *
 * WARUM INLINE UND NICHT ALS DATEI: Die Icons stehen im Kennzahlenband und in
 * den Kontaktzeilen direkt neben Text und sollen dessen Farbe annehmen – rot im
 * Band, rot im Link, grau im Hinweis. Ein <img> kann das nicht; eine CSS-Maske
 * kann es, kostet aber je Icon einen HTTP-Abruf und scheitert still, wenn der
 * Pfad beim Deployment nicht mitkommt. Inline-SVG mit `currentColor` löst beides
 * und hält die Icons an derselben Stelle wie den Code, der sie setzt.
 *
 * Die Formen sind bewusst schlicht (Strichzeichnung, 1.6px, quadratische Enden
 * wo möglich) und folgen der Linienführung der Original-Piktogramme aus dem
 * IG-Metall-Design-System, ohne deren Dateien zu kopieren – die tragen ihre
 * Farben in CSS-Klassen, die außerhalb des Systems nicht existieren, und
 * rendern einzeln eingebunden deshalb falsch.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Icons {

	/** Die Pfade je Name. Alle im Koordinatenraum 24 × 24. */
	private static function pfade() {
		return array(
			// Kalenderblatt mit Ring
			'termin' => '<rect x="3" y="5" width="18" height="16"/><path d="M3 10h18"/><path d="M8 3v4M16 3v4"/>',
			// Ortsmarke
			'ort' => '<path d="M12 22s7-7.6 7-13a7 7 0 1 0-14 0c0 5.4 7 13 7 13Z"/><circle cx="12" cy="9" r="2.8"/>',
			// Zifferblatt
			'uhr' => '<circle cx="12" cy="12" r="9"/><path d="M12 6.5V12l3.8 2.3"/>',
			// Zwei Personen
			'personen' => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-5.4 6-5.4s6 2.1 6 5.4"/><path d="M16 5.4A3.2 3.2 0 0 1 16 11"/><path d="M17.4 14.9c2.1.6 3.6 2.3 3.6 5.1"/>',
			// Eine Person
			'person' => '<circle cx="12" cy="8" r="3.4"/><path d="M5 20c0-3.6 3.1-5.8 7-5.8s7 2.2 7 5.8"/>',
			// Unterlagen (zwei Blätter)
			'unterlagen' => '<path d="M8 3h7l4 4v11H8z"/><path d="M15 3v4h4"/><path d="M5 6v15h11"/>',
			// Pfeil in die Ablage
			'download' => '<path d="M12 3v11"/><path d="m7.5 10 4.5 4.5L16.5 10"/><path d="M4 20h16"/>',
			// Umschlag
			'mail' => '<rect x="3" y="5" width="18" height="14"/><path d="m3 6 9 6.5L21 6"/>',
			// Paragraf
			'paragraf' => '<path d="M14.8 6.6C14.4 5 13.2 4 11.5 4 9.6 4 8.3 5.2 8.3 6.9c0 3.6 7 3.7 7 7.6 0 2-1.5 3.4-3.6 3.4-1.9 0-3.2-1-3.6-2.7"/><path d="M5.5 9.5h13M5.5 14.5h13"/>',
			// Bildschirm
			'laptop' => '<rect x="3" y="5" width="18" height="11"/><path d="M2 20h20"/>',
			// Geldbörse
			'geld' => '<path d="M3 7.5h14a3 3 0 0 1 3 3v7.5H3z"/><path d="M3 7.5V5.2L15.5 3v4.5"/><circle cx="16.2" cy="14.2" r="1.2"/>',
			// Haken
			'haken' => '<path d="m4 12.5 5.2 5.3L20 6.6"/>',
			// Information
			'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 10.8V17"/><path d="M12 7.2v.9"/>',
			// Ziel
			'ziel' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>',
			// Telefon
			'telefon' => '<path d="M6.4 3.5h3l1.6 4-2 1.5c.9 2 2.5 3.6 4.5 4.5l1.5-2 4 1.6v3c0 .8-.7 1.4-1.5 1.4C11.3 17.5 6.5 12.7 5 5c0-.8.6-1.5 1.4-1.5Z"/>',
			// Pfeil nach links (Rückweg)
			'pfeil-links' => '<path d="M20 12H4"/><path d="m10 6-6 6 6 6"/>',
			// Pfeil nach rechts (weiter) – sitzt im Kreis am Ende einer Trefferzeile
			'pfeil-rechts' => '<path d="M4 12h16"/><path d="m14 6 6 6-6 6"/>',
		);
	}

	/**
	 * Ein Piktogramm als Inline-SVG.
	 *
	 * Dekorativ per Voreinstellung: Neben jedem Icon steht die Beschriftung im
	 * Klartext, also bekäme eine Vorlesehilfe die Angabe sonst doppelt.
	 *
	 * @param string $name  Schlüssel aus pfade().
	 * @param int    $size  Kantenlänge in Pixeln.
	 * @param string $klasse Zusätzliche CSS-Klasse.
	 */
	public static function get( $name, $size = 24, $klasse = '' ) {
		$pfade = self::pfade();
		$name  = strtolower( (string) $name );
		if ( ! isset( $pfade[ $name ] ) ) {
			return '';
		}
		$size   = max( 8, (int) $size );
		$klasse = trim( 'igm-icon igm-icon--' . preg_replace( '/[^a-z0-9_-]/', '', $name ) . ' ' . $klasse );

		return '<svg class="' . esc_attr( $klasse ) . '" width="' . $size . '" height="' . $size . '"'
			. ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"'
			. ' stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true" focusable="false">'
			. $pfade[ $name ]
			. '</svg>';
	}

	/**
	 * Icon zu einer Angabe im Kennzahlenband. Eine Stelle für die Zuordnung
	 * „Label -> Piktogramm", damit Detail- und Reihenseite nicht auseinanderlaufen.
	 */
	public static function fuer_label( $label ) {
		$karte = array(
			'zeitraum'      => 'termin',
			'nächster start'=> 'termin',
			'termin'        => 'termin',
			'ort'           => 'ort',
			'orte'          => 'ort',
			'veranstalter*in' => 'laptop',
			'format'        => 'laptop',
			'uhrzeit'       => 'uhr',
			'anreise'       => 'uhr',
			'zielgruppe'    => 'personen',
			'buchung'       => 'personen',
			'freistellung'  => 'paragraf',
			'aufbau'        => 'unterlagen',
			'kosten'        => 'geld',
		);
		$key = mb_strtolower( trim( (string) $label ) );
		return isset( $karte[ $key ] ) ? $karte[ $key ] : 'info';
	}
}
