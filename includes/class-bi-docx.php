<?php
/**
 * Minimaler Word-Schreiber (.docx) – ohne Fremdbibliothek.
 *
 * Eine .docx-Datei ist ein ZIP-Archiv mit XML-Teilen. Für ein Briefdokument
 * reichen vier davon, die diese Klasse erzeugt:
 *
 *   [Content_Types].xml          welcher Teil welchen Typ hat
 *   _rels/.rels                  Einstiegspunkt -> word/document.xml
 *   word/_rels/document.xml.rels Dokument -> styles.xml
 *   word/document.xml            der eigentliche Inhalt
 *   word/styles.xml              Grundschrift und -größe
 *
 * Bewusst klein gehalten: Absätze mit fetten/kursiven Teilstücken, Ausrichtung,
 * Abstand danach und eine Linie über dem Absatz (für die Unterschriftszeile).
 * Mehr braucht die Beschlussvorlage nicht – und alles, was hier nicht drin ist,
 * kann auch nicht kaputtgehen.
 *
 * Maßeinheiten in Word: Schriftgröße in halben Punkten (20 = 10 pt),
 * Abstände und Seitenränder in Twips (1440 = 1 Zoll, 567 = 1 cm).
 *
 * Benötigt die PHP-Erweiterung zip (ZipArchive) – siehe available().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Docx {

	/** Gesammelte Absätze als fertiges WordprocessingML */
	private $body = '';

	/** Grundschrift und -größe des Dokuments */
	private $font = 'Arial';
	private $size = 20; // halbe Punkte = 10 pt

	public function __construct( $font = 'Arial', $pt = 10 ) {
		$this->font = $font;
		$this->size = (int) round( $pt * 2 );
	}

	/** Steht die zip-Erweiterung bereit? */
	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/** XML-Escaping für Text und Attribute */
	private static function esc( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Einen Absatz anhängen.
	 *
	 * @param string|array $runs  Text, oder Liste von Teilstücken:
	 *                            [ ['text' => '…', 'b' => true, 'i' => false], … ]
	 * @param array        $opts  align  'left'|'right'|'center'
	 *                            size   Schriftgröße in Punkt (Standard: Grundgröße)
	 *                            after  Abstand nach dem Absatz in Punkt (Standard 0)
	 *                            color  Hexfarbe ohne #, z. B. '787878'
	 *                            rule   true = dünne Linie über dem Absatz (Unterschrift)
	 */
	public function p( $runs, $opts = array() ) {
		if ( ! is_array( $runs ) ) {
			$runs = array( array( 'text' => $runs ) );
		}

		$size  = isset( $opts['size'] ) ? (int) round( $opts['size'] * 2 ) : $this->size;
		$after = isset( $opts['after'] ) ? (int) round( $opts['after'] * 20 ) : 0; // pt -> Twips
		$color = isset( $opts['color'] ) ? $opts['color'] : '';

		$props = '<w:spacing w:after="' . $after . '" w:line="264" w:lineRule="auto"/>';
		if ( ! empty( $opts['align'] ) && 'left' !== $opts['align'] ) {
			$props .= '<w:jc w:val="' . self::esc( $opts['align'] ) . '"/>';
		}
		if ( ! empty( $opts['rule'] ) ) {
			// Linie ÜBER dem Absatz – so entsteht die Unterschriftslinie
			$props .= '<w:pBdr><w:top w:val="single" w:sz="6" w:space="2" w:color="808080"/></w:pBdr>';
		}

		$xml = '<w:p><w:pPr>' . $props . '</w:pPr>';
		foreach ( $runs as $run ) {
			$text = isset( $run['text'] ) ? (string) $run['text'] : '';
			$rpr  = '<w:rFonts w:ascii="' . self::esc( $this->font ) . '" w:hAnsi="' . self::esc( $this->font ) . '"/>'
				. '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/>';
			if ( ! empty( $run['b'] ) ) {
				$rpr .= '<w:b/>';
			}
			if ( ! empty( $run['i'] ) ) {
				$rpr .= '<w:i/>';
			}
			if ( '' !== $color ) {
				$rpr .= '<w:color w:val="' . self::esc( $color ) . '"/>';
			}
			// xml:space="preserve" hält führende/abschließende Leerzeichen
			$xml .= '<w:r><w:rPr>' . $rpr . '</w:rPr><w:t xml:space="preserve">'
				. self::esc( $text ) . '</w:t></w:r>';
		}
		$xml .= '</w:p>';

		$this->body .= $xml;
		return $this;
	}

	/** Leerzeile mit einstellbarer Höhe (in Punkt) */
	public function leer( $pt = 10 ) {
		return $this->p( '', array( 'after' => $pt ) );
	}

	/** word/document.xml zusammensetzen */
	private function document_xml() {
		// A4 (11906 x 16838 Twips), Ränder 2,5 cm oben/unten, 2,5 cm seitlich
		$sect = '<w:sectPr>'
			. '<w:pgSz w:w="11906" w:h="16838"/>'
			. '<w:pgMar w:top="1418" w:right="1418" w:bottom="1418" w:left="1418" '
			. 'w:header="708" w:footer="708" w:gutter="0"/>'
			. '</w:sectPr>';

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:body>' . $this->body . $sect . '</w:body></w:document>';
	}

	/** word/styles.xml – nur die Grundeinstellung für das ganze Dokument */
	private function styles_xml() {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
			. '<w:docDefaults><w:rPrDefault><w:rPr>'
			. '<w:rFonts w:ascii="' . self::esc( $this->font ) . '" w:hAnsi="' . self::esc( $this->font ) . '"/>'
			. '<w:sz w:val="' . $this->size . '"/><w:szCs w:val="' . $this->size . '"/>'
			. '<w:lang w:val="de-DE"/>'
			. '</w:rPr></w:rPrDefault></w:docDefaults>'
			. '</w:styles>';
	}

	/**
	 * Fertiges Dokument als Rohdaten.
	 *
	 * ZipArchive schreibt nur in echte Dateien, deshalb der Umweg über eine
	 * temporäre Datei, die anschließend wieder verschwindet.
	 *
	 * @return string Rohdaten der .docx-Datei ('' bei Fehler).
	 */
	public function bytes() {
		if ( ! self::available() ) {
			error_log( '[BI-Docx] PHP-Erweiterung zip fehlt – keine Word-Dateien möglich.' );
			return '';
		}

		// Bewusst tempnam() statt wp_tempnam(): Letzteres steckt in wp-admin/includes/file.php
		// und ist beim Versand aus dem Anmeldeformular heraus (Frontend) nicht geladen.
		$tmp = tempnam( get_temp_dir(), 'bi-docx' );
		if ( ! $tmp ) {
			return '';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			return '';
		}

		$zip->addFromString( '[Content_Types].xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
			. '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
			. '</Types>'
		);
		$zip->addFromString( '_rels/.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
			. '</Relationships>'
		);
		$zip->addFromString( 'word/_rels/document.xml.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
			. '</Relationships>'
		);
		$zip->addFromString( 'word/styles.xml', $this->styles_xml() );
		$zip->addFromString( 'word/document.xml', $this->document_xml() );
		$zip->close();

		$bytes = (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $tmp );
		return $bytes;
	}
}
