<?php
/**
 * Grundgerüst der PDF-Anhänge (siehe class-bi-pdf.php).
 *
 * Eigene Datei, weil die Klasse FPDF voraussetzt: Beide werden erst geladen,
 * wenn wirklich ein PDF entsteht – nicht bei jedem Seitenaufruf.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gemeinsames Grundgerüst beider Dokumente: Ränder, Schrift, Kopf mit Logo,
 * Fußzeile mit Seitenzahl. Ohne Logo-Einstellung bleibt der Kopf leer.
 */
class BI_PDF_Doc extends FPDF {

	/** Akzentfarbe (IG-Metall-Rot) als RGB */
	const ACCENT = array( 226, 0, 26 );

	/** Pfad zur Logodatei ('' = kein Logo) */
	private $logo = '';

	/** Fußzeilen-Text (eine Zeile) */
	private $footer_text = '';

	/** Kopfzeile ganz weglassen (Beschlussvorlage: Briefkopf des Betriebsrats) */
	private $plain_header = false;

	public function __construct( $logo = '', $footer_text = '', $plain_header = false ) {
		parent::__construct( 'P', 'mm', 'A4' );
		$this->logo         = $logo;
		$this->footer_text  = $footer_text;
		$this->plain_header = (bool) $plain_header;

		$this->SetMargins( 20, 18, 20 );
		$this->SetAutoPageBreak( true, 22 );
		$this->AliasNbPages();
	}

	/** UTF-8 -> CP1252 (FPDF-Kernschriften nutzen WinAnsiEncoding). */
	public static function t( $s ) {
		$s = (string) $s;
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'CP1252//TRANSLIT', $s );
			if ( false !== $out ) {
				return $out;
			}
		}
		return utf8_decode( $s );
	}

	public function Header() {
		if ( $this->plain_header ) {
			return;
		}
		$top = 12;

		// Logo oben rechts, rechtsbündig am Satzspiegel.
		//
		// Eingepasst wird in ein Feld von 32 × 15 mm: Ein Schriftzug schöpft die
		// Breite aus, ein quadratisches Signet – wie das IG-Metall-Zeichen – die
		// Höhe. Früher galten stur 32 mm Breite; damit wäre ein Quadrat 32 mm hoch
		// geworden und mitten durch die rote Linie bei y = 32 gelaufen.
		if ( $this->logo && file_exists( $this->logo ) ) {
			$max_w = 32;
			$max_h = 15;
			$w     = $max_w;

			$masse = @getimagesize( $this->logo ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_array( $masse ) && ! empty( $masse[0] ) && ! empty( $masse[1] ) ) {
				$w = min( $max_w, $max_h * ( $masse[0] / $masse[1] ) );
			}

			try {
				$this->Image( $this->logo, $this->GetPageWidth() - 20 - $w, $top, $w );
			} catch ( Exception $e ) {
				// Defektes/unbekanntes Bildformat darf das Dokument nicht verhindern
				error_log( '[BI-PDF] Logo konnte nicht eingebettet werden: ' . $e->getMessage() );
			}
		}

		// Rote Linie unter dem Kopf
		$this->SetDrawColor( self::ACCENT[0], self::ACCENT[1], self::ACCENT[2] );
		$this->SetLineWidth( 0.8 );
		$this->Line( 20, 32, $this->GetPageWidth() - 20, 32 );
		$this->SetLineWidth( 0.2 );
		$this->SetDrawColor( 200, 200, 200 );

		$this->SetY( 38 );
	}

	public function Footer() {
		// Brief (Beschlussvorlage): Seite 1 bleibt ohne Seitenzahl – so ist es im
		// Geschäftsbrief üblich. Erst ab Seite 2 wird gezählt.
		if ( $this->plain_header && $this->PageNo() < 2 ) {
			return;
		}

		$this->SetY( -15 );
		$this->SetFont( 'Helvetica', '', 8 );
		$this->SetTextColor( 120, 120, 120 );

		if ( '' !== $this->footer_text ) {
			$this->Cell( 0, 4, self::t( $this->footer_text ), 0, 1, 'L' );
		}
		$this->Cell( 0, 4, self::t( 'Seite ' . $this->PageNo() . ' von {nb}' ), 0, 0, 'R' );

		$this->SetTextColor( 0, 0, 0 );
	}

	/** Y-Position, an der der Inhalt auf einer frischen Seite beginnt (siehe Header). */
	const CONTENT_TOP = 38;

	/**
	 * Wie viele Zeilen braucht $txt in einer Zelle der Breite $w?
	 *
	 * Gleiche Umbruchlogik wie FPDF::MultiCell, nur ohne zu zeichnen – damit lässt
	 * sich die Höhe eines Blocks VOR dem Setzen bestimmen. Gemessen wird auf der
	 * CP1252-Fassung, weil die Zeichenbreiten byteweise vorliegen.
	 */
	public function nb_lines( $w, $txt ) {
		if ( ! isset( $this->CurrentFont ) ) {
			return 1;
		}
		$cw   = $this->CurrentFont['cw'];
		$wmax = ( $w - 2 * $this->cMargin ) * 1000 / $this->FontSize;
		$s    = str_replace( "\r", '', self::t( $txt ) );
		$nb   = strlen( $s );
		if ( $nb > 0 && "\n" === $s[ $nb - 1 ] ) {
			$nb--;
		}
		$sep = -1;
		$i   = 0;
		$j   = 0;
		$l   = 0;
		$nl  = 1;
		while ( $i < $nb ) {
			$c = $s[ $i ];
			if ( "\n" === $c ) {
				$i++;
				$sep = -1;
				$j   = $i;
				$l   = 0;
				$nl++;
				continue;
			}
			if ( ' ' === $c ) {
				$sep = $i;
			}
			$l += isset( $cw[ $c ] ) ? $cw[ $c ] : 0;
			if ( $l > $wmax ) {
				if ( -1 === $sep ) {
					if ( $i === $j ) {
						$i++;
					}
				} else {
					$i = $sep + 1;
				}
				$sep = -1;
				$j   = $i;
				$l   = 0;
				$nl++;
			} else {
				$i++;
			}
		}
		return $nl;
	}

	/**
	 * Seitenumbruch VOR einem Block, wenn er sonst zerrissen würde.
	 *
	 * Ohne das lief die Aufzählung auseinander: FPDF brach mitten im Block um, der
	 * Spiegelstrich blieb allein auf der alten Seite zurück und der Text landete –
	 * wegen des danach wieder gesetzten alten Y – erst auf der übernächsten.
	 *
	 * @param float $h Benötigte Höhe in mm.
	 */
	public function keep_together( $h ) {
		if ( $this->GetY() + $h <= $this->PageBreakTrigger ) {
			return; // passt noch auf diese Seite
		}
		if ( $h > $this->PageBreakTrigger - self::CONTENT_TOP ) {
			return; // passt auf keine Seite – dann normal umbrechen lassen statt eine leere zu erzeugen
		}
		$this->AddPage();
	}

	/** Überschrift in Akzentfarbe mit dünner Linie darunter */
	public function heading( $text, $size = 13 ) {
		// Überschrift nie allein am Seitenende stehen lassen – mindestens zwei
		// Zeilen des folgenden Abschnitts müssen mit auf die Seite passen.
		$this->keep_together( 13 + 2 * 5.5 );
		$this->Ln( 3 );
		$this->SetFont( 'Helvetica', 'B', $size );
		$this->SetTextColor( self::ACCENT[0], self::ACCENT[1], self::ACCENT[2] );
		$this->Cell( 0, 7, self::t( $text ), 0, 1 );
		$this->SetTextColor( 0, 0, 0 );
		$this->SetDrawColor( 220, 220, 220 );
		$this->Line( 20, $this->GetY(), $this->GetPageWidth() - 20, $this->GetY() );
		$this->Ln( 3 );
	}

	/** Zeile „Bezeichnung | Wert" – Wert bricht bei Bedarf um, die Zeile bleibt zusammen */
	public function row( $label, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return; // leere Angaben gar nicht erst zeigen
		}
		$label_w = 44;
		$value_w = $this->GetPageWidth() - 40 - $label_w;

		// Höhe vorab bestimmen: Bezeichnung und Wert gehören zusammen auf eine Seite.
		$this->SetFont( 'Helvetica', 'B', 10 );
		$zeilen = $this->nb_lines( $label_w, $label );
		$this->SetFont( 'Helvetica', '', 10 );
		$zeilen = max( $zeilen, $this->nb_lines( $value_w, $value ) );
		$this->keep_together( $zeilen * 5.5 + 1.5 );

		$y = $this->GetY();
		$this->SetFont( 'Helvetica', 'B', 10 );
		$this->SetXY( 20, $y );
		$this->MultiCell( $label_w, 5.5, self::t( $label ), 0, 'L' );
		$y_label = $this->GetY();

		$this->SetFont( 'Helvetica', '', 10 );
		$this->SetXY( 20 + $label_w, $y );
		$this->MultiCell( $value_w, 5.5, self::t( $value ), 0, 'L' );

		$this->SetY( max( $y_label, $this->GetY() ) + 1.5 );
	}

	/**
	 * Fließtext. Absätze werden einzeln gesetzt: MultiCell zieht Leerzeilen nicht
	 * zuverlässig durch, dadurch klebten sonst zwei Absätze aneinander.
	 */
	public function para( $text, $size = 10, $style = '', $line = 5.5 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return;
		}
		$this->SetFont( 'Helvetica', $style, $size );
		foreach ( preg_split( "/\n\s*\n/", $text ) as $absatz ) {
			$absatz = trim( $absatz );
			if ( '' === $absatz ) {
				continue;
			}
			$this->MultiCell( 0, $line, self::t( $absatz ), 0, 'L' );
			$this->Ln( 2.5 );
		}
	}

	/**
	 * Aufzählung mit Spiegelstrichen.
	 *
	 * Jeder Punkt wird als Ganzes gesetzt: Passt er nicht mehr auf die Seite, kommt
	 * der Umbruch VOR dem Punkt. Sonst bliebe der Spiegelstrich allein zurück und
	 * sein Text landete – weil danach wieder das alte Y gesetzt wird – erst auf der
	 * übernächsten Seite.
	 */
	public function bullets( $items ) {
		$w = $this->GetPageWidth() - 46;
		foreach ( $items as $item ) {
			$item = trim( (string) $item );
			if ( '' === $item ) {
				continue;
			}
			$this->SetFont( 'Helvetica', '', 10 );
			$this->keep_together( $this->nb_lines( $w, $item ) * 5.5 + 0.5 );

			$y = $this->GetY(); // erst NACH einem möglichen Umbruch lesen
			$this->SetXY( 22, $y );
			$this->Cell( 4, 5.5, self::t( '–' ), 0, 0 );
			$this->SetXY( 26, $y );
			$this->MultiCell( $w, 5.5, self::t( $item ), 0, 'L' );
			$this->Ln( 0.5 );
		}
		$this->Ln( 2 );
	}
}
