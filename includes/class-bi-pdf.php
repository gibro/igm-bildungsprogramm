<?php
/**
 * Seminardetails als PDF – Anhang der Anmeldemails.
 *
 * Alle Angaben zum Seminar auf einem Blatt, Logo oben rechts (Bild aus der
 * Einstellung „Logo für PDF-Anhänge").
 *
 * Der zweite Anhang, die **Beschlussvorlage**, steckt in class-bi-beschluss.php –
 * die ist bewusst eine Word-Datei, weil der Betriebsrat sie noch ergänzen muss.
 * Sie greift auf die Datenaufbereitung hier zurück (seminar_data, datetime,
 * veranstalter_inline, temp_datei), damit beide Dokumente dieselben Werte zeigen.
 *
 * Technik: FPDF 1.86 (vendor/fpdf, freie Lizenz, keine weiteren Abhängigkeiten).
 * FPDF erwartet Text in CP1252, deshalb läuft jeder Text durch BI_PDF_Doc::t().
 *
 * Die Dateien landen als temporäre Dateien im Temp-Verzeichnis und werden nach
 * dem Versand sofort wieder gelöscht (siehe BI_Mailer::dispatch()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_PDF {

	/** Punktelinie für Felder, die von Hand ausgefüllt werden */
	const BLANK = '…………………………';

	public static function init() {
		// Vorschau aus dem Admin (Einstellungen → PDF-Anhänge)
		add_action( 'admin_post_bi_pdf_preview', array( __CLASS__, 'preview' ) );
	}

	/**
	 * FPDF und das Dokument-Grundgerüst nachladen – erst wenn wirklich ein PDF
	 * entsteht. Bei jedem Seitenaufruf wären das rund 50 KB PHP umsonst.
	 *
	 * @return bool true, wenn die PDF-Erzeugung bereitsteht.
	 */
	public static function available() {
		if ( ! class_exists( 'FPDF' ) ) {
			$fpdf = BI_PATH . 'vendor/fpdf/fpdf.php';
			if ( ! file_exists( $fpdf ) ) {
				error_log( '[BI-PDF] vendor/fpdf/fpdf.php fehlt – keine PDF-Anhänge möglich.' );
				return false;
			}
			require_once $fpdf;
		}
		if ( ! class_exists( 'BI_PDF_Doc' ) ) {
			require_once BI_PATH . 'includes/class-bi-pdf-doc.php';
		}
		return class_exists( 'FPDF' ) && class_exists( 'BI_PDF_Doc' );
	}

	/** ---------- Einstellungen ---------- */

	/** Serverpfad der Logodatei aus den Einstellungen; '' wenn nicht gesetzt/vorhanden. */
	public static function logo_path() {
		$id = (int) BI_Settings::get( 'pdf_logo_id' );
		if ( ! $id ) {
			return '';
		}
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return '';
		}
		// FPDF kann nur JPEG, PNG und GIF einbetten – SVG & Co. sauber abweisen
		$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif' ), true ) ) {
			return '';
		}
		return $file;
	}

	/** Vorgabe, solange in den Einstellungen nichts hinterlegt ist. */
	const VERANSTALTER_STANDARD = 'Industriegewerkschaft Metall';

	/**
	 * Name und Anschrift des Veranstalters (mehrzeilig).
	 *
	 * Ohne Einstellung greift die Vorgabe „Industriegewerkschaft Metall" – NICHT
	 * der Seitenname: In der Beschlussvorlage stünde sonst je nach Installation
	 * etwas wie „Das Seminar wird von Test-Wordpress veranstaltet".
	 */
	public static function veranstalter() {
		$v = trim( (string) BI_Settings::get( 'pdf_veranstalter' ) );
		return ( '' !== $v ) ? $v : self::VERANSTALTER_STANDARD;
	}

	/** Veranstalter als eine Zeile (Fußzeile, Fließtext) */
	public static function veranstalter_inline() {
		$parts = preg_split( '/\R+/', self::veranstalter() );
		$parts = array_filter( array_map( 'trim', (array) $parts ), 'strlen' );
		return implode( ', ', $parts );
	}

	/** ---------- Datenaufbereitung ---------- */

	/**
	 * Alle Angaben eines Seminars gebündelt (leere Felder bleiben leere Strings).
	 * Öffentlich, weil die Beschlussvorlage dieselben Werte braucht.
	 */
	public static function seminar_data( $seminar_id ) {
		$seminar_id = (int) $seminar_id;
		$meta       = function ( $key ) use ( $seminar_id ) {
			return trim( (string) get_post_meta( $seminar_id, $key, true ) );
		};

		$start = $meta( '_bi_startdatum' );
		$end   = $meta( '_bi_enddatum' );

		$terms = function ( $tax ) use ( $seminar_id ) {
			$names = wp_get_object_terms( $seminar_id, $tax, array( 'fields' => 'names' ) );
			return ( is_array( $names ) && $names ) ? implode( ', ', $names ) : '';
		};

		return array(
			'id'          => $seminar_id,
			'post_type'   => get_post_type( $seminar_id ),
			'online'      => bi_is_online( $seminar_id ),
			'titel'       => get_the_title( $seminar_id ),
			'untertitel'  => $meta( '_bi_untertitel' ),
			'referenten'  => $meta( '_bi_referenten' ),
			'plattform'   => bi_is_online( $seminar_id ) ? BI_Online::tool_label( $seminar_id ) : '',
			'online_link' => $meta( '_bi_online_link' ),
			'nummer'      => $meta( '_bi_seminarnummer' ),
			'start'       => $start,
			'ende'        => $end,
			'startzeit'   => $meta( '_bi_startuhrzeit' ),
			'endzeit'     => $meta( '_bi_enduhrzeit' ),
			'anreise'     => $meta( '_bi_anreisedatum' ),
			'anreisezeit' => $meta( '_bi_anreiseuhrzeit' ),
			'kosten'      => $meta( '_bi_kosten' ),
			'ansprech'    => $meta( '_bi_ansprechpartner' ),
			'ansprech_m'  => $meta( '_bi_ansprechpartner_email' ),
			'ort'         => $terms( BI_TAX_ORT ),
			'thema'       => $terms( BI_TAX_THEMA ),
			'ziel'        => $terms( BI_TAX_ZIEL ),
			'frei'        => $terms( BI_TAX_FREI ),
			'programm'    => $terms( BI_TAX_PROGRAMM ),
			'themen'      => self::html_to_lines( $meta( '_bi_themen' ) ),
			'beschreibung' => self::html_to_text( get_post_field( 'post_content', $seminar_id ) ),
			'permalink'   => get_permalink( $seminar_id ),
			'dauer'       => self::dauer( $start, $end ),
		);
	}

	/** „5 Tage" aus Start und Ende; '' wenn nicht berechenbar. */
	private static function dauer( $start, $end ) {
		if ( ! $start || ! $end ) {
			return '';
		}
		$days = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;
		if ( $days < 1 ) {
			return '';
		}
		return $days . ( 1 === $days ? ' Tag' : ' Tage' );
	}

	/** „12.05.2026, 10:00 Uhr" – Uhrzeit nur wenn vorhanden. */
	public static function datetime( $date, $time = '' ) {
		if ( ! $date ) {
			return '';
		}
		$ts  = strtotime( $date );
		$out = $ts ? date_i18n( 'd.m.Y', $ts ) : $date;
		if ( '' !== trim( (string) $time ) ) {
			$out .= ', ' . trim( $time ) . ' Uhr';
		}
		return $out;
	}

	/** HTML -> Klartext mit erhaltenen Absätzen */
	private static function html_to_text( $html ) {
		$html = (string) $html;
		// Block-Enden trennen Absätze (Leerzeile), Zeilenumbrüche und Listenpunkte nur die Zeile
		$text = preg_replace( '#</(p|div|h[1-6])\s*>#i', "\n\n", $html );
		$text = preg_replace( '#<(br|/li)[^>]*>#i', "\n", $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/** HTML-Liste -> Array von Zeilen (für die Aufzählung im PDF) */
	private static function html_to_lines( $html ) {
		$text  = self::html_to_text( $html );
		$lines = preg_split( '/\R+/', $text );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			// führende Aufzählungszeichen entfernen, die Liste setzt eigene
			$line = trim( preg_replace( '/^[\-\x{2013}\x{2022}\*\s]+/u', '', trim( $line ) ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** ---------- Dokument 1: Seminardetails ---------- */

	/**
	 * @param int $seminar_id
	 * @return string Rohdaten des PDF ('' bei Fehler).
	 */
	public static function seminar_bytes( $seminar_id ) {
		if ( ! self::available() || ! get_post( $seminar_id ) ) {
			return '';
		}
		try {
			$d   = self::seminar_data( $seminar_id );
			// Fußzeile nur, wenn ein Veranstalter hinterlegt ist – ohne Einstellung
			// bleibt sie leer statt einen Seitennamen abzudrucken.
			$fuss = trim( (string) BI_Settings::get( 'pdf_veranstalter' ) ) !== '' ? self::veranstalter_inline() : '';

			$pdf = new BI_PDF_Doc( self::logo_path(), $fuss );
			$pdf->SetTitle( $d['titel'] );
			$pdf->SetAuthor( self::veranstalter_inline() );
			$pdf->SetCreator( 'Bildungsprogramm' );
			$pdf->AddPage();

			// Titelblock
			$pdf->SetFont( 'Helvetica', 'B', 17 );
			$pdf->MultiCell( 0, 7.5, BI_PDF_Doc::t( $d['titel'] ), 0, 'L' );
			if ( '' !== $d['nummer'] ) {
				$pdf->Ln( 1 );
				$pdf->SetFont( 'Helvetica', '', 10 );
				$pdf->SetTextColor( 110, 110, 110 );
				$pdf->Cell( 0, 5, BI_PDF_Doc::t( 'Seminarnummer ' . $d['nummer'] ), 0, 1 );
				$pdf->SetTextColor( 0, 0, 0 );
			}
			$pdf->Ln( 4 );

			// Eckdaten
			$pdf->heading( 'Auf einen Blick' );
			$pdf->row( 'Beginn', self::datetime( $d['start'], $d['startzeit'] ) );
			$pdf->row( 'Ende', self::datetime( $d['ende'], $d['endzeit'] ) );
			$pdf->row( 'Anreise', self::datetime( $d['anreise'], $d['anreisezeit'] ) );
			$pdf->row( 'Dauer', $d['dauer'] );
			if ( ! empty( $d['online'] ) ) {
				// Online-Seminare: kein Bildungszentrum, dafür Plattform und Referent*innen
				$pdf->row( 'Format', 'Online-Seminar' );
				$pdf->row( 'Veranstalter*in', $d['ort'] );
				$pdf->row( 'Plattform', $d['plattform'] );
				$pdf->row( 'Referent*innen', $d['referenten'] );
			} else {
				$pdf->row( 'Bildungszentrum', $d['ort'] );
			}
			$pdf->row( 'Themenfeld', $d['thema'] );
			$pdf->row( 'Zielgruppe', $d['ziel'] );
			$pdf->row( 'Freistellung', $d['frei'] );
			$pdf->row( 'Kosten / Hinweis', $d['kosten'] );
			$pdf->row( 'Programmjahr', $d['programm'] );
			$pdf->row( 'Veranstalter', self::veranstalter_inline() );

			// Ansprechpartner
			$ansprech = trim( $d['ansprech'] . ( '' !== $d['ansprech_m'] ? ' (' . $d['ansprech_m'] . ')' : '' ) );
			$pdf->row( 'Ansprechpartner', $ansprech );

			if ( '' !== $d['beschreibung'] ) {
				$pdf->heading( 'Beschreibung' );
				$pdf->para( $d['beschreibung'] );
			}
			if ( $d['themen'] ) {
				$pdf->heading( 'Themen im Seminar' );
				$pdf->bullets( $d['themen'] );
			}
			// Bewusst ohne Link auf die Webseite: Das PDF soll für sich stehen und
			// nicht die (bei lokalen Installationen unpassende) Adresse mitschleppen.

			return $pdf->Output( 'S' );
		} catch ( Exception $e ) {
			error_log( '[BI-PDF] Seminar-PDF fehlgeschlagen: ' . $e->getMessage() );
			return '';
		}
	}

	/** ---------- Dateien für den Mailversand ---------- */

	/** Dateiname ohne Sonderzeichen, z. B. „Seminar_SL01234.pdf". */
	private static function filename( $prefix, $seminar_id ) {
		$nr   = trim( (string) get_post_meta( $seminar_id, '_bi_seminarnummer', true ) );
		$slug = $nr !== '' ? $nr : (string) (int) $seminar_id;
		$slug = sanitize_file_name( $slug );
		return $prefix . '_' . $slug . '.pdf';
	}

	/**
	 * Rohdaten als temporäre Datei ablegen (für wp_mail-Anhänge).
	 *
	 * wp_mail() nimmt Dateipfade, nicht Rohdaten – deshalb der Umweg über das
	 * Temp-Verzeichnis. Der Aufrufer löscht die Datei nach dem Versand wieder.
	 * Öffentlich, weil die Beschlussvorlage ihre Word-Datei genauso ablegt.
	 *
	 * @return string Pfad oder '' bei Fehler.
	 */
	public static function temp_datei( $bytes, $filename ) {
		if ( '' === $bytes ) {
			return '';
		}
		$dir = get_temp_dir();
		if ( ! $dir || ! is_writable( $dir ) ) {
			$up  = wp_upload_dir();
			$dir = ( ! empty( $up['basedir'] ) ) ? trailingslashit( $up['basedir'] ) . 'bi-tmp' : '';
			if ( '' === $dir ) {
				return '';
			}
			wp_mkdir_p( $dir );
		}
		// Eigener Unterordner je Versand, damit der Anhang seinen sprechenden Namen behält
		$sub = trailingslashit( $dir ) . 'bi-pdf-' . wp_generate_password( 8, false );
		if ( ! wp_mkdir_p( $sub ) ) {
			return '';
		}
		$path = trailingslashit( $sub ) . $filename;
		if ( false === file_put_contents( $path, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}
		return $path;
	}

	/** Seminardetails als temporäre Datei; '' bei Fehler. */
	public static function seminar_file( $seminar_id ) {
		return self::temp_datei( self::seminar_bytes( $seminar_id ), self::filename( 'Seminar', $seminar_id ) );
	}

	/** Temporäre Anhänge samt Ordner wieder entfernen. */
	public static function cleanup( $paths ) {
		foreach ( (array) $paths as $path ) {
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}
			$dir = dirname( $path );
			wp_delete_file( $path );
			// nur den eigens angelegten Unterordner entfernen, nie etwas darüber
			if ( 0 === strpos( basename( $dir ), 'bi-pdf-' ) ) {
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}

	/** ---------- Vorschau im Admin ---------- */

	/** URL der Vorschau (Einstellungen → PDF-Anhänge) */
	public static function preview_url( $doc, $seminar_id ) {
		return wp_nonce_url(
			add_query_arg(
				array( 'action' => 'bi_pdf_preview', 'doc' => $doc, 'seminar' => (int) $seminar_id ),
				admin_url( 'admin-post.php' )
			),
			'bi_pdf_preview'
		);
	}

	/**
	 * Gibt das gewünschte Dokument direkt im Browser aus – nur für Admins.
	 * Seminardetails als PDF (inline), Beschlussvorlage als Word-Datei (Download).
	 */
	public static function preview() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_pdf_preview' );

		$doc        = isset( $_GET['doc'] ) ? sanitize_key( wp_unslash( $_GET['doc'] ) ) : 'seminar';
		$seminar_id = isset( $_GET['seminar'] ) ? (int) $_GET['seminar'] : 0;

		if ( ! $seminar_id ) {
			$seminar_id = self::sample_seminar_id();
		}
		if ( ! $seminar_id ) {
			wp_die( 'Für die Vorschau wird mindestens ein Seminar benötigt.' );
		}

		if ( 'beschluss' === $doc ) {
			// Beispieldaten, damit die Vorschau zeigt, wie das ausgefüllte Schreiben aussieht
			$bytes    = BI_Beschluss::bytes( $seminar_id, BI_Beschluss::beispiel_anmeldung() );
			$name     = 'Beschlussvorlage-Vorschau.docx';
			$mime     = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			$disposition = 'attachment'; // Word zeigt der Browser nicht an
		} else {
			$bytes    = self::seminar_bytes( $seminar_id );
			$name     = 'Seminardetails-Vorschau.pdf';
			$mime     = 'application/pdf';
			$disposition = 'inline';
		}

		if ( '' === $bytes ) {
			wp_die( 'Das Dokument konnte nicht erzeugt werden. Details stehen im Error-Log.' );
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $name . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput – Binärdaten
		exit;
	}

	/** Nächstes anstehendes Seminar (für die Vorschau); 0 wenn keins da ist. */
	public static function sample_seminar_id() {
		$posts = get_posts( array(
			'post_type'      => bi_seminar_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_bi_startdatum',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_type'      => 'DATE',
			'meta_query'     => array(
				array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
			),
		) );
		return $posts ? (int) $posts[0] : 0;
	}
}
