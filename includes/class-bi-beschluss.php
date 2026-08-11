<?php
/**
 * Beschlussvorlage: „Mitteilung über Seminarteilnahme nach § 37 Abs. 6 BetrVG".
 *
 * Ausgabeformat ist **Word (.docx)**, nicht PDF: Der Betriebsrat muss das Schreiben
 * noch ergänzen (Sitzungsdatum, Ort und Datum) und auf seinen eigenen Briefbogen
 * bringen – das geht nur in einem bearbeitbaren Dokument.
 *
 * ============================================================================
 *  ACHTUNG – WORTLAUT
 *  Die Sätze in render() stammen unverändert aus der vorgegebenen Muster-Vorlage.
 *  Sie sind die Grundlage für die Kostenübernahme nach § 37 Abs. 6 BetrVG.
 *  Bitte nur ändern, wenn eine neue Fassung des Musters vorliegt.
 * ============================================================================
 *
 * Eingesetzt wird nur, was aus der Anmeldung sicher bekannt ist:
 *   Betrieb + Anschrift, Name der angemeldeten Person, Seminartitel,
 *   Veranstalter, Beginn und Ende.
 * Alles andere – Sitzungsdatum, Ort/Datum – bleibt als Punktelinie stehen,
 * bei fehlenden Angaben zusätzlich mit dem Klammerhinweis der Vorlage.
 *
 * Das Schreiben bekommt bewusst KEIN Logo: Absender ist der Betriebsrat, nicht
 * der Veranstalter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Beschluss {

	/** Punktelinie für Felder, die von Hand ausgefüllt werden */
	const BLANK = '…………………………';

	/** Steht die Word-Erzeugung bereit? */
	public static function available() {
		if ( ! class_exists( 'BI_Docx' ) ) {
			require_once BI_PATH . 'includes/class-bi-docx.php';
		}
		return class_exists( 'BI_Docx' ) && BI_Docx::available();
	}

	/**
	 * Das Schreiben als Word-Datei.
	 *
	 * @param int   $seminar_id
	 * @param array $submission Anmeldedatensatz (optional; ohne ihn bleibt alles offen).
	 * @return string Rohdaten der .docx-Datei ('' bei Fehler).
	 */
	public static function bytes( $seminar_id, $submission = array() ) {
		if ( ! self::available() || ! get_post( $seminar_id ) ) {
			return '';
		}
		try {
			$doc = new BI_Docx( 'Arial', 11 );
			self::render( $doc, (int) $seminar_id, is_array( $submission ) ? $submission : array() );
			return $doc->bytes();
		} catch ( Exception $e ) {
			error_log( '[BI-Beschluss] Word-Datei fehlgeschlagen: ' . $e->getMessage() );
			return '';
		}
	}

	/** Der Textkörper – Wortlaut siehe Warnhinweis oben. */
	private static function render( $doc, $seminar_id, $submission ) {
		$blank = self::BLANK;
		$d     = BI_PDF::seminar_data( $seminar_id );
		$data  = ( isset( $submission['data'] ) && is_array( $submission['data'] ) ) ? $submission['data'] : array();

		/** Wert einsetzen, sonst Punktelinie mit dem Klammerhinweis der Vorlage. */
		$fill = function ( $value, $hinweis ) use ( $blank ) {
			$value = trim( (string) $value );
			return ( '' !== $value ) ? $value : $blank . ' (' . $hinweis . ')';
		};

		// Name der angemeldeten Person – steht im ersten Absatz als Betriebsratsmitglied
		$name = trim( ( $submission['vorname'] ?? '' ) . ' ' . ( $submission['nachname'] ?? '' ) );

		/* Anrede aus der Anmeldung (Auswahlfeld: Frau | Herr | Divers | Keine Angabe).
		 * Damit steht im Schlussabsatz „von Herrn Max Muster" statt der geratenen
		 * Doppelform. Nur wenn nichts Eindeutiges vorliegt, bleibt „Herrn/Frau" aus
		 * der Vorlage stehen – das ist dann korrekt und nicht falsch geraten. */
		$anrede = trim( (string) ( $data['anrede'] ?? '' ) );
		if ( 'Herr' === $anrede ) {
			$anrede_form = 'Herrn'; // Dativ
		} elseif ( 'Frau' === $anrede ) {
			$anrede_form = 'Frau';
		} else {
			$anrede_form = 'Herrn/Frau';
		}

		/* ---------- Briefkopf: Absender ist der Betriebsrat des Betriebs ----------
		 * Die betriebliche Anschrift kommt aus der Anmeldung (Felder betrieb,
		 * betrieb_strasse, betrieb_plz/plz, betrieb_ort) – nicht die Privatadresse.
		 */
		$betrieb = trim( (string) ( $submission['betrieb'] ?? '' ) );
		$strasse = trim( (string) ( $data['betrieb_strasse'] ?? '' ) );
		$b_plz   = trim( (string) ( $data['betrieb_plz'] ?? ( $submission['plz'] ?? '' ) ) );
		$b_ort   = trim( (string) ( $data['betrieb_ort'] ?? '' ) );
		$ortzeile = trim( $b_plz . ' ' . $b_ort );

		$doc->p( array( array( 'text' => 'Betriebsrat', 'b' => true ) ) );
		$doc->p( 'der ' . ( '' !== $betrieb ? $betrieb : $blank ) );
		$doc->p( '' !== $strasse ? $strasse : $blank );
		$doc->p( '' !== $ortzeile ? $ortzeile : $blank, array( 'after' => 24 ) );

		/* ---------- Empfänger ---------- */
		$doc->p( 'An die' );
		$doc->p( 'Geschäftsleitung' );
		$doc->p( 'im Hause', array( 'after' => 28 ) );

		/* ---------- Ort, Datum: kennen wir nicht, bleibt offen ---------- */
		$doc->p( $blank . ' (Ort, Datum)', array( 'align' => 'right', 'after' => 24 ) );

		/* ---------- Betreff ---------- */
		$doc->p(
			array( array( 'text' => 'Mitteilung über Seminarteilnahme nach § 37 Abs. 6 BetrVG', 'b' => true ) ),
			array( 'after' => 18 )
		);

		/* ---------- Anrede ---------- */
		$doc->p( 'Sehr geehrte Damen und Herren,', array( 'after' => 12 ) );

		/* ---------- Hauptteil (Wortlaut der Vorlage) ---------- */
		$doc->p(
			'der Betriebsrat hat in seiner Sitzung am ' . $blank . ' beschlossen, '
			. 'das Betriebsratsmitglied ' . $fill( $name, 'Name' ) . ' '
			. 'zu dem Seminar ' . $fill( $d['titel'], 'Titel des Seminars' ) . ' zu entsenden. '
			. 'Das Seminar wird von ' . $fill( BI_PDF::veranstalter_inline(), 'Name und Anschrift des Veranstalters' ) . ' '
			. 'veranstaltet.',
			array( 'after' => 12 )
		);

		$doc->p(
			'Es beginnt am ' . $fill( BI_PDF::datetime( $d['start'], $d['startzeit'] ), 'Tag und Uhrzeit' )
			. ' und endet am ' . $fill( BI_PDF::datetime( $d['ende'], $d['endzeit'] ), 'Tag und Uhrzeit' ) . '.',
			array( 'after' => 12 )
		);

		$doc->p(
			'Die Seminarteilnahme ist i. S. d. § 37 Abs. 6 BetrVG erforderlich. '
			. 'Das Seminarprogramm fügen wir als Anlage bei.',
			array( 'after' => 12 )
		);

		$doc->p(
			'Wir gehen davon aus, dass Ihrerseits keine Einwände gegen die Seminarteilnahme '
			. 'von ' . $anrede_form . ' ' . $fill( $name, 'Name' ) . ' bestehen.',
			array( 'after' => 20 )
		);

		/* ---------- Gruß und Unterschrift ---------- */
		$doc->p( 'Mit freundlichen Grüßen' );
		$doc->leer( 30 ); // Platz für die Unterschrift
		$doc->p( 'Betriebsratsvorsitzende/r', array( 'rule' => true, 'after' => 30 ) );

		/* ---------- Hinweis zum Ausfüllen (kein Bestandteil des Musters) ---------- */
		$doc->p(
			array( array(
				'text' => 'Hinweis (nicht Teil des Schreibens): Bitte Sitzungsdatum sowie Ort und Datum ergänzen, '
					. 'die Angaben prüfen und das Schreiben von der/dem Betriebsratsvorsitzenden unterschreiben lassen. '
					. 'Die Seminarausschreibung ist als Anlage beizufügen. Diese Zeile vor dem Versand löschen.',
				'i' => true,
			) ),
			array( 'size' => 8, 'color' => '787878' )
		);
	}

	/** Dateiname des Anhangs, z. B. „Beschlussvorlage_S00026331.docx". */
	public static function filename( $seminar_id ) {
		$nr   = trim( (string) get_post_meta( $seminar_id, '_bi_seminarnummer', true ) );
		$slug = sanitize_file_name( '' !== $nr ? $nr : (string) (int) $seminar_id );
		return 'Beschlussvorlage_' . $slug . '.docx';
	}

	/** Als temporäre Datei für den Mailversand; '' bei Fehler. */
	public static function datei( $seminar_id, $submission = array() ) {
		return BI_PDF::temp_datei( self::bytes( $seminar_id, $submission ), self::filename( $seminar_id ) );
	}

	/** Beispieldaten für die Vorschau im Admin (aus der Muster-Vorlage). */
	public static function beispiel_anmeldung() {
		return array(
			'vorname'  => 'Erika',
			'nachname' => 'Mustermann',
			'betrieb'  => 'H.H. Normal GmbH',
			'plz'      => '33333',
			'data'     => array(
				'betrieb_strasse' => 'Normalstr. 13',
				'betrieb_plz'     => '33333',
				'betrieb_ort'     => 'Normalstadt',
			),
		);
	}
}
