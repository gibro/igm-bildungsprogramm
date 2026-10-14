<?php
/**
 * PDF-Formulare mit den Angaben einer Anmeldung ausfüllen (seit 1.148.0).
 *
 * ============================================================================
 *  WOFÜR
 * ============================================================================
 *  Eine Datei aus der Mediathek, die per Regel an eine Benachrichtigung
 *  angehängt wird, darf ein PDF mit Formularfeldern sein. Heißt ein Feld wie
 *  ein Mail-Platzhalter – `{name}`, `{strasse}`, auch zusammengesetzt wie
 *  `{plz}/{betrieb_ort}` –, steht im Anhang an dieser Stelle der Wert aus der
 *  Anmeldung. Erster Anlass: die „Einwilligung zur Nutzung von Daten" für
 *  Nichtmitglieder, die ausgefüllt an die Geschäftsstelle geht.
 *
 *  Ein Feldname OHNE geschweifte Klammern wird als Platzhalter seines eigenen
 *  Namens gelesen: „Datum" ist {datum}, „Einwilligung" ist {einwilligung}.
 *  Felder, zu denen es keinen Platzhalter gibt, bleiben, wie sie sind. Ein PDF
 *  ohne ein einziges solches Feld geht unverändert mit – wie bisher.
 *
 *  Kästchen werden angekreuzt, wenn der Wert nicht leer und nicht „nein" ist.
 *  Ausgefüllte Felder werden schreibgeschützt: Das Dokument belegt, was bei
 *  der Anmeldung angegeben wurde, und soll beim Empfänger nicht versehentlich
 *  überschrieben werden.
 *
 * ============================================================================
 *  TECHNIK – WARUM EIN EIGENER KLEINER PARSER
 * ============================================================================
 *  FPDF kann PDFs nur schreiben, nicht öffnen. Bibliotheken, die Formulare
 *  füllen, brauchen entweder ein Programm auf dem Server (pdftk, qpdf) oder
 *  Composer – beides gibt es nicht auf allen vier Installationen. Formulare
 *  aus Acrobat und Word speichern ihre Objekte zudem komprimiert in
 *  Objekt-Streams, an die man mit Suchen-und-Ersetzen nicht herankommt.
 *
 *  Deshalb hier: Querverweise lesen (Tabelle oder Stream, mit Vorgängern),
 *  die Feldobjekte holen (auch aus Objekt-Streams), sie mit Wert und fertigem
 *  Erscheinungsbild neu schreiben und als INKREMENTELLE AKTUALISIERUNG hinten
 *  anhängen. Das Original bleibt Byte für Byte erhalten; jeder PDF-Betrachter
 *  liest die angehängten Fassungen als die gültigen.
 *
 *  Das Erscheinungsbild (/AP) wird selbst erzeugt, nicht dem Betrachter
 *  überlassen (NeedAppearances): Apple Mail, die Vorschau auf dem iPhone und
 *  viele Webmailer zeigen sonst leere Felder, obwohl der Wert drinsteht.
 *  Schrift ist Helvetica in WinAnsi – das deckt Umlaute, ß und € ab.
 *
 *  Verschlüsselte PDFs und kaputte Dateien werden nicht angefasst: Dann geht
 *  das Original mit, und im Fehlerprotokoll steht, warum.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_PDF_Formular {

	/** Die geladene Datei. */
	private $pdf = '';

	/** Objektnummer => ['typ' => 1, 'off' => int, 'gen' => int] | ['typ' => 2, 'stm' => int, 'idx' => int] */
	private $xref = array();

	/** Trailer der jüngsten Fassung (geparst). */
	private $trailer = array();

	/** Offset des jüngsten Querverweises. */
	private $startxref = 0;

	/** War der jüngste Querverweis ein Stream (PDF 1.5+)? */
	private $xref_ist_stream = false;

	/** Objekt-Streams, schon entpackt: Nummer => ['daten' => string, 'offs' => [idx => off]] */
	private $objstm = array();

	/** Bereits gelesene Objekte. */
	private $cache = array();

	/** Neu zu schreibende Objekte: Nummer => Quelltext (ohne „n 0 obj"). */
	private $neu = array();

	/** Nächste freie Objektnummer. */
	private $size = 0;

	/** Objektnummer der eingebetteten Helvetica-Referenz (einmal je Datei). */
	private $font_nr = 0;

	/** Zeichenbreiten der Helvetica (FPDF-Metrik), einmal geladen. */
	private static $cw = null;

	/* ===================================================================
	 *  Öffentliche Schnittstelle
	 * =================================================================== */

	/**
	 * Ein PDF auf der Platte mit Werten füllen – an Ort und Stelle.
	 *
	 * Gedacht für die temporäre Kopie aus BI_Regeln::mediathek_datei(): Die
	 * Datei wird überschrieben, das Original in der Mediathek nie.
	 *
	 * @param string $pfad Pfad zur (temporären) PDF-Datei.
	 * @param array  $ctx  Platzhalter => Wert, wie BI_Mailer ihn baut.
	 * @return int Zahl der ausgefüllten Felder (0 = nichts geändert).
	 */
	public static function datei_fuellen( $pfad, $ctx ) {
		if ( ! is_string( $pfad ) || '' === $pfad || ! is_readable( $pfad ) ) {
			return 0;
		}
		if ( 'pdf' !== strtolower( pathinfo( $pfad, PATHINFO_EXTENSION ) ) ) {
			return 0;
		}
		$bytes = (string) file_get_contents( $pfad ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$anz   = 0;
		$out   = self::fuellen( $bytes, $ctx, $anz );
		if ( $anz > 0 && '' !== $out ) {
			file_put_contents( $pfad, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $anz;
	}

	/**
	 * PDF-Bytes füllen.
	 *
	 * @param string $bytes Das PDF.
	 * @param array  $ctx   Platzhalter => Wert.
	 * @param int    $anz   Ausgabe: Zahl der ausgefüllten Felder.
	 * @return string Das gefüllte PDF – oder das unveränderte Original.
	 */
	public static function fuellen( $bytes, $ctx, &$anz = 0 ) {
		$anz = 0;
		try {
			$doc = new self( $bytes );
			$out = $doc->ausfuellen( is_array( $ctx ) ? $ctx : array(), $anz );
			return $anz > 0 ? $out : $bytes;
		} catch ( Exception $e ) {
			self::log( $e->getMessage() );
			$anz = 0;
			return $bytes;
		}
	}

	/**
	 * Die Formularfelder eines PDFs mit ihrem Platzhalter – für die Anzeige
	 * in der Regelmaske („diese Felder werden ausgefüllt").
	 *
	 * @return array Liste von ['name' => Feldname, 'art' => text|haken|auswahl, 'platzhalter' => string[]]
	 *               – leer, wenn die Datei kein lesbares Formular ist.
	 */
	public static function felder_der_datei( $pfad ) {
		if ( ! is_string( $pfad ) || ! is_readable( $pfad ) ) {
			return array();
		}
		try {
			$doc = new self( (string) file_get_contents( $pfad ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$out = array();
			foreach ( $doc->felder() as $f ) {
				$out[] = array(
					'name'        => $f['name'],
					'art'         => $f['art'],
					'platzhalter' => self::platzhalter_im_namen( $f['name'] ),
				);
			}
			return $out;
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * Welche Platzhalter stecken in einem Feldnamen?
	 * „{plz}/{betrieb_ort}" → {plz}, {betrieb_ort}; „Datum" → {datum}.
	 */
	public static function platzhalter_im_namen( $name ) {
		if ( preg_match_all( '/\{[a-z0-9_]+\}/i', (string) $name, $m ) ) {
			return array_values( array_unique( array_map( 'strtolower', $m[0] ) ) );
		}
		$schluessel = self::name_als_schluessel( $name );
		return '' === $schluessel ? array() : array( '{' . $schluessel . '}' );
	}

	/* ===================================================================
	 *  Werte aus dem Feldnamen
	 * =================================================================== */

	/** „Datum" → datum, „E-Mail privat" → e_mail_privat. */
	private static function name_als_schluessel( $name ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $name ), 'UTF-8' ) : strtolower( trim( (string) $name ) );
		$s = strtr( $s, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		$s = preg_replace( '/[^a-z0-9]+/', '_', $s );
		return trim( (string) $s, '_' );
	}

	/**
	 * Wert für ein Feld – oder null, wenn der Name keinen bekannten
	 * Platzhalter trägt (dann bleibt das Feld unberührt).
	 */
	private static function wert_fuer( $name, $ctx ) {
		$name = (string) $name;
		if ( preg_match_all( '/\{[a-z0-9_]+\}/i', $name, $m ) ) {
			$bekannt = false;
			$ersatz  = array();
			$alle_leer = true;
			foreach ( array_unique( $m[0] ) as $ph ) {
				$key = strtolower( $ph );
				if ( array_key_exists( $key, $ctx ) ) {
					$bekannt = true;
				}
				$wert          = trim( wp_strip_all_tags( (string) ( $ctx[ $key ] ?? '' ) ) );
				$ersatz[ $ph ] = $wert;
				if ( '' !== $wert ) {
					$alle_leer = false;
				}
			}
			if ( ! $bekannt ) {
				return null;
			}
			if ( $alle_leer ) {
				return '';
			}
			// Trennzeichen am Rand fallen weg, wenn ein Teil leer ist:
			// „{plz}/{betrieb_ort}" ohne PLZ wird „Bochum", nicht „/Bochum".
			$text = strtr( $name, $ersatz );
			return trim( (string) preg_replace( '/\s+/', ' ', $text ), " \t/,;:-|" );
		}
		$key = '{' . self::name_als_schluessel( $name ) . '}';
		if ( '{}' === $key || ! array_key_exists( $key, $ctx ) ) {
			return null;
		}
		return trim( wp_strip_all_tags( (string) $ctx[ $key ] ) );
	}

	/** Gilt ein Wert als „angekreuzt"? */
	private static function ist_an( $wert ) {
		$w = strtolower( trim( (string) $wert ) );
		return ! in_array( $w, array( '', '0', 'nein', 'no', 'off', 'false', 'aus' ), true );
	}

	private static function log( $text ) {
		if ( function_exists( 'error_log' ) ) {
			error_log( '[BI-PDF-Formular] ' . $text ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/* ===================================================================
	 *  Laden
	 * =================================================================== */

	private function __construct( $bytes ) {
		$this->pdf = (string) $bytes;
		if ( false === strpos( substr( $this->pdf, 0, 1024 ), '%PDF-' ) ) {
			throw new Exception( 'Keine PDF-Datei.' );
		}
		$pos = strrpos( $this->pdf, 'startxref' );
		if ( false === $pos ) {
			throw new Exception( 'Kein startxref gefunden.' );
		}
		if ( ! preg_match( '/startxref\s+(\d+)/', $this->pdf, $m, 0, $pos ) ) {
			throw new Exception( 'startxref unlesbar.' );
		}
		$this->startxref = (int) $m[1];
		$this->xref_lesen( $this->startxref, true );

		if ( isset( $this->trailer['/Encrypt'] ) ) {
			throw new Exception( 'Verschlüsselte PDF-Datei – wird nicht ausgefüllt.' );
		}
		if ( ! isset( $this->trailer['/Root'] ) ) {
			throw new Exception( 'Kein /Root im Trailer.' );
		}
		$this->size = (int) self::zahl( $this->trailer['/Size'] ?? array( 't' => 'num', 'v' => '0' ) );
		foreach ( array_keys( $this->xref ) as $n ) {
			$this->size = max( $this->size, $n + 1 );
		}
	}

	/** Querverweis an $off lesen, Vorgänger rekursiv. Jüngere Einträge gewinnen. */
	private function xref_lesen( $off, $juengster, $tiefe = 0 ) {
		if ( $tiefe > 64 || $off <= 0 || $off >= strlen( $this->pdf ) ) {
			return;
		}
		$p = $off;
		self::ws( $this->pdf, $p );

		if ( 'xref' === substr( $this->pdf, $p, 4 ) ) {
			$p += 4;
			while ( true ) {
				self::ws( $this->pdf, $p );
				if ( ! preg_match( '/\G(\d+)\s+(\d+)/', $this->pdf, $m, 0, $p ) ) {
					break;
				}
				$p    += strlen( $m[0] );
				$start = (int) $m[1];
				$cnt   = (int) $m[2];
				for ( $i = 0; $i < $cnt; $i++ ) {
					self::ws( $this->pdf, $p );
					if ( ! preg_match( '/\G(\d{1,10})\s+(\d{1,5})\s+([nf])/', $this->pdf, $e, 0, $p ) ) {
						throw new Exception( 'Querverweistabelle beschädigt.' );
					}
					$p += strlen( $e[0] );
					$n  = $start + $i;
					if ( ! isset( $this->xref[ $n ] ) ) {
						$this->xref[ $n ] = 'n' === $e[3]
							? array( 'typ' => 1, 'off' => (int) $e[1], 'gen' => (int) $e[2] )
							: array( 'typ' => 0 );
					}
				}
			}
			self::ws( $this->pdf, $p );
			if ( 'trailer' !== substr( $this->pdf, $p, 7 ) ) {
				throw new Exception( 'Trailer fehlt.' );
			}
			$p      += 7;
			$trailer = $this->wert( $this->pdf, $p );
			$trailer = $trailer['v'] ?? array();
			if ( $juengster ) {
				$this->trailer         = $trailer;
				$this->xref_ist_stream = false;
			}
			// Hybrid-Dateien: zusätzlich ein Querverweis-Stream.
			if ( isset( $trailer['/XRefStm'] ) ) {
				$this->xref_lesen( (int) self::zahl( $trailer['/XRefStm'] ), false, $tiefe + 1 );
			}
			if ( isset( $trailer['/Prev'] ) ) {
				$this->xref_lesen( (int) self::zahl( $trailer['/Prev'] ), false, $tiefe + 1 );
			}
			return;
		}

		// Querverweis-Stream: „n g obj << /Type /XRef … >> stream … endstream"
		$obj = $this->objekt_an( $p );
		$d   = $obj['wert']['v'] ?? array();
		if ( ( $d['/Type']['v'] ?? '' ) !== 'XRef' ) {
			throw new Exception( 'Querverweis weder Tabelle noch Stream.' );
		}
		$daten = $this->stream_dekodieren( $d, $obj['stream'] );
		$w     = array();
		foreach ( $d['/W']['v'] ?? array() as $x ) {
			$w[] = (int) self::zahl( $x );
		}
		if ( 3 !== count( $w ) ) {
			throw new Exception( 'Querverweis-Stream ohne /W.' );
		}
		$index = array();
		if ( isset( $d['/Index'] ) ) {
			foreach ( $d['/Index']['v'] as $x ) {
				$index[] = (int) self::zahl( $x );
			}
		} else {
			$index = array( 0, (int) self::zahl( $d['/Size'] ) );
		}
		$breite = $w[0] + $w[1] + $w[2];
		$q      = 0;
		for ( $k = 0; $k + 1 < count( $index ); $k += 2 ) {
			for ( $i = 0; $i < $index[ $k + 1 ]; $i++ ) {
				if ( $q + $breite > strlen( $daten ) ) {
					break 2;
				}
				$f = array();
				$r = $q;
				foreach ( $w as $j => $wj ) {
					$v = 0;
					for ( $b = 0; $b < $wj; $b++ ) {
						$v = ( $v << 8 ) | ord( $daten[ $r++ ] );
					}
					$f[ $j ] = ( 0 === $j && 0 === $wj ) ? 1 : $v;
				}
				$q += $breite;
				$n  = $index[ $k ] + $i;
				if ( isset( $this->xref[ $n ] ) ) {
					continue;
				}
				if ( 1 === $f[0] ) {
					$this->xref[ $n ] = array( 'typ' => 1, 'off' => $f[1], 'gen' => $f[2] );
				} elseif ( 2 === $f[0] ) {
					$this->xref[ $n ] = array( 'typ' => 2, 'stm' => $f[1], 'idx' => $f[2] );
				} else {
					$this->xref[ $n ] = array( 'typ' => 0 );
				}
			}
		}
		if ( $juengster ) {
			$this->trailer         = $d;
			$this->xref_ist_stream = true;
		}
		if ( isset( $d['/Prev'] ) ) {
			$this->xref_lesen( (int) self::zahl( $d['/Prev'] ), false, $tiefe + 1 );
		}
	}

	/** Objekt an einer Dateiposition: ['nr', 'gen', 'wert', 'stream'] */
	private function objekt_an( $p ) {
		self::ws( $this->pdf, $p );
		if ( ! preg_match( '/\G(\d+)\s+(\d+)\s+obj\b/', $this->pdf, $m, 0, $p ) ) {
			throw new Exception( 'Objekt erwartet an Position ' . $p . '.' );
		}
		$p   += strlen( $m[0] );
		$wert = $this->wert( $this->pdf, $p );
		self::ws( $this->pdf, $p );
		$stream = null;
		if ( 'stream' === substr( $this->pdf, $p, 6 ) ) {
			$p += 6;
			if ( "\r" === ( $this->pdf[ $p ] ?? '' ) ) {
				$p++;
			}
			if ( "\n" === ( $this->pdf[ $p ] ?? '' ) ) {
				$p++;
			}
			$len = isset( $wert['v']['/Length'] ) ? $this->aufloesen( $wert['v']['/Length'] ) : null;
			$len = $len ? (int) self::zahl( $len ) : -1;
			if ( $len < 0 || 'endstream' !== substr( ltrim( substr( $this->pdf, $p + $len, 20 ) ), 0, 9 ) ) {
				// /Length falsch oder unauflösbar: bis „endstream" suchen.
				$ende = strpos( $this->pdf, 'endstream', $p );
				if ( false === $ende ) {
					throw new Exception( 'endstream fehlt.' );
				}
				$len = $ende - $p;
				$roh = substr( $this->pdf, $p, $len );
				$stream = rtrim( $roh, "\r\n" );
			} else {
				$stream = substr( $this->pdf, $p, $len );
			}
		}
		return array( 'nr' => (int) $m[1], 'gen' => (int) $m[2], 'wert' => $wert, 'stream' => $stream );
	}

	/** Objekt Nummer $n: ['wert' => …, 'stream' => …|null, 'gen' => int] oder null. */
	private function objekt( $n ) {
		$n = (int) $n;
		if ( isset( $this->cache[ $n ] ) ) {
			return $this->cache[ $n ];
		}
		$e = $this->xref[ $n ] ?? null;
		if ( ! $e || 0 === $e['typ'] ) {
			return null;
		}
		if ( 1 === $e['typ'] ) {
			$o   = $this->objekt_an( $e['off'] );
			$res = array( 'wert' => $o['wert'], 'stream' => $o['stream'], 'gen' => $o['gen'] );
		} else {
			$res = array( 'wert' => $this->aus_objstm( $e['stm'], $e['idx'], $n ), 'stream' => null, 'gen' => 0 );
		}
		$this->cache[ $n ] = $res;
		return $res;
	}

	private function aus_objstm( $stm, $idx, $n ) {
		if ( ! isset( $this->objstm[ $stm ] ) ) {
			$o = $this->objekt( $stm );
			if ( ! $o || null === $o['stream'] ) {
				throw new Exception( 'Objekt-Stream ' . $stm . ' fehlt.' );
			}
			$d     = $o['wert']['v'];
			$daten = $this->stream_dekodieren( $d, $o['stream'] );
			$anz   = (int) self::zahl( $d['/N'] );
			$first = (int) self::zahl( $d['/First'] );
			$kopf  = substr( $daten, 0, $first );
			preg_match_all( '/(\d+)\s+(\d+)/', $kopf, $mm, PREG_SET_ORDER );
			$offs = array();
			foreach ( array_slice( $mm, 0, $anz ) as $i => $paar ) {
				$offs[ $i ] = array( 'nr' => (int) $paar[1], 'off' => $first + (int) $paar[2] );
			}
			$this->objstm[ $stm ] = array( 'daten' => $daten, 'offs' => $offs );
		}
		$s = $this->objstm[ $stm ];
		// Der Index aus dem Querverweis gilt; zur Sicherheit über die Nummer prüfen.
		$eintrag = $s['offs'][ $idx ] ?? null;
		if ( ! $eintrag || $eintrag['nr'] !== $n ) {
			$eintrag = null;
			foreach ( $s['offs'] as $x ) {
				if ( $x['nr'] === $n ) {
					$eintrag = $x;
					break;
				}
			}
		}
		if ( ! $eintrag ) {
			throw new Exception( 'Objekt ' . $n . ' nicht im Objekt-Stream ' . $stm . '.' );
		}
		$p = $eintrag['off'];
		return $this->wert( $s['daten'], $p );
	}

	/** Verweis auflösen; direkte Werte kommen unverändert zurück. */
	private function aufloesen( $v ) {
		$tiefe = 0;
		while ( is_array( $v ) && 'ref' === $v['t'] && $tiefe++ < 16 ) {
			$o = $this->objekt( $v['n'] );
			if ( ! $o ) {
				return null;
			}
			$v = $o['wert'];
		}
		return $v;
	}

	/** Stream-Inhalt entpacken (FlateDecode mit PNG-Prädiktor; sonst roh). */
	private function stream_dekodieren( $d, $roh ) {
		$filter = $d['/Filter'] ?? null;
		$liste  = array();
		if ( $filter ) {
			$filter = $this->aufloesen( $filter );
			if ( 'name' === $filter['t'] ) {
				$liste[] = $filter['v'];
			} elseif ( 'arr' === $filter['t'] ) {
				foreach ( $filter['v'] as $f ) {
					$liste[] = $f['v'] ?? '';
				}
			}
		}
		$daten = (string) $roh;
		foreach ( $liste as $f ) {
			if ( 'FlateDecode' !== $f && 'Fl' !== $f ) {
				throw new Exception( 'Nicht unterstützter Filter /' . $f . '.' );
			}
			$x = @gzuncompress( $daten ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $x ) {
				$x = @gzinflate( substr( $daten, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			if ( false === $x ) {
				throw new Exception( 'Stream nicht entpackbar.' );
			}
			$daten = $x;
		}
		$parm = isset( $d['/DecodeParms'] ) ? $this->aufloesen( $d['/DecodeParms'] ) : null;
		if ( $parm && 'arr' === $parm['t'] ) {
			$parm = $parm['v'][0] ?? null;
		}
		if ( $parm && 'dict' === $parm['t'] && isset( $parm['v']['/Predictor'] ) ) {
			$pred = (int) self::zahl( $parm['v']['/Predictor'] );
			$cols = isset( $parm['v']['/Columns'] ) ? (int) self::zahl( $parm['v']['/Columns'] ) : 1;
			if ( $pred >= 10 ) {
				$daten = self::png_rueckgaengig( $daten, $cols );
			} elseif ( $pred > 1 ) {
				throw new Exception( 'TIFF-Prädiktor wird nicht unterstützt.' );
			}
		}
		return $daten;
	}

	/** PNG-Prädiktor (je Zeile ein Filterbyte) rückgängig machen, 1 Byte je Pixel. */
	private static function png_rueckgaengig( $daten, $cols ) {
		$out  = '';
		$prev = str_repeat( "\0", $cols );
		$len  = strlen( $daten );
		for ( $p = 0; $p + $cols < $len + 1 && $p < $len; $p += $cols + 1 ) {
			$typ  = ord( $daten[ $p ] );
			$zeil = substr( $daten, $p + 1, $cols );
			if ( strlen( $zeil ) < $cols ) {
				break;
			}
			$neu = '';
			for ( $i = 0; $i < $cols; $i++ ) {
				$x = ord( $zeil[ $i ] );
				$a = $i > 0 ? ord( $neu[ $i - 1 ] ) : 0;
				$b = ord( $prev[ $i ] );
				$c = $i > 0 ? ord( $prev[ $i - 1 ] ) : 0;
				switch ( $typ ) {
					case 1:
						$x += $a;
						break;
					case 2:
						$x += $b;
						break;
					case 3:
						$x += intdiv( $a + $b, 2 );
						break;
					case 4:
						$pa = abs( $b - $c );
						$pb = abs( $a - $c );
						$pc = abs( $a + $b - 2 * $c );
						$x += ( $pa <= $pb && $pa <= $pc ) ? $a : ( $pb <= $pc ? $b : $c );
						break;
				}
				$neu .= chr( $x & 0xFF );
			}
			$out .= $neu;
			$prev = $neu;
		}
		return $out;
	}

	/* ===================================================================
	 *  Tokenizer und Werte
	 *
	 *  Werte als Arrays: num/kw/name tragen 'v' (roh), str/hex tragen 'raw'
	 *  (unverändert zurückschreibbar), arr/dict tragen 'v', ref trägt n/g.
	 * =================================================================== */

	const WS    = " \t\r\n\f\0";
	const DELIM = '()<>[]{}/%';

	private static function ws( $s, &$p ) {
		$len = strlen( $s );
		while ( $p < $len ) {
			$c = $s[ $p ];
			if ( false !== strpos( self::WS, $c ) ) {
				$p++;
			} elseif ( '%' === $c ) {
				while ( $p < $len && "\n" !== $s[ $p ] && "\r" !== $s[ $p ] ) {
					$p++;
				}
			} else {
				break;
			}
		}
	}

	private function wert( $s, &$p ) {
		self::ws( $s, $p );
		$len = strlen( $s );
		if ( $p >= $len ) {
			throw new Exception( 'Unerwartetes Dateiende.' );
		}
		$c = $s[ $p ];

		if ( '<' === $c && '<' === ( $s[ $p + 1 ] ?? '' ) ) {
			$p   += 2;
			$dict = array();
			while ( true ) {
				self::ws( $s, $p );
				if ( $p >= $len ) {
					throw new Exception( 'Dictionary nicht geschlossen.' );
				}
				if ( '>' === $s[ $p ] && '>' === ( $s[ $p + 1 ] ?? '' ) ) {
					$p += 2;
					break;
				}
				$k = $this->wert( $s, $p );
				if ( 'name' !== $k['t'] ) {
					throw new Exception( 'Schlüssel im Dictionary ist kein Name.' );
				}
				$dict[ '/' . $k['v'] ] = $this->wert( $s, $p );
			}
			return array( 't' => 'dict', 'v' => $dict );
		}
		if ( '[' === $c ) {
			$p++;
			$arr = array();
			while ( true ) {
				self::ws( $s, $p );
				if ( $p >= $len ) {
					throw new Exception( 'Array nicht geschlossen.' );
				}
				if ( ']' === $s[ $p ] ) {
					$p++;
					break;
				}
				$arr[] = $this->wert( $s, $p );
			}
			return array( 't' => 'arr', 'v' => $arr );
		}
		if ( '/' === $c ) {
			$q = $p + 1;
			while ( $q < $len && false === strpos( self::WS . self::DELIM, $s[ $q ] ) ) {
				$q++;
			}
			$v = substr( $s, $p + 1, $q - $p - 1 );
			$p = $q;
			return array( 't' => 'name', 'v' => $v );
		}
		if ( '(' === $c ) {
			$q     = $p + 1;
			$tiefe = 1;
			while ( $q < $len && $tiefe > 0 ) {
				$ch = $s[ $q ];
				if ( '\\' === $ch ) {
					$q += 2;
					continue;
				}
				if ( '(' === $ch ) {
					$tiefe++;
				} elseif ( ')' === $ch ) {
					$tiefe--;
				}
				$q++;
			}
			$raw = substr( $s, $p, $q - $p );
			$p   = $q;
			return array( 't' => 'str', 'raw' => $raw );
		}
		if ( '<' === $c ) {
			$q = strpos( $s, '>', $p );
			if ( false === $q ) {
				throw new Exception( 'Hex-String nicht geschlossen.' );
			}
			$raw = substr( $s, $p, $q - $p + 1 );
			$p   = $q + 1;
			return array( 't' => 'hex', 'raw' => $raw );
		}
		if ( preg_match( '/\G[+\-]?(\d+\.?\d*|\.\d+)/', $s, $m, 0, $p ) ) {
			$p += strlen( $m[0] );
			// „12 0 R" ist ein Verweis, keine zwei Zahlen.
			if ( ctype_digit( $m[0] ) && preg_match( '/\G\s+(\d+)\s+R(?![^\s\/\[\]<>()%{}])/', $s, $r, 0, $p ) ) {
				$p += strlen( $r[0] );
				return array( 't' => 'ref', 'n' => (int) $m[0], 'g' => (int) $r[1] );
			}
			return array( 't' => 'num', 'v' => $m[0] );
		}
		if ( preg_match( '/\G[A-Za-z]+/', $s, $m, 0, $p ) ) {
			$p += strlen( $m[0] );
			return array( 't' => 'kw', 'v' => $m[0] );
		}
		throw new Exception( 'Unbekanntes Zeichen „' . $c . '" an Position ' . $p . '.' );
	}

	/** Wert zurück in PDF-Quelltext. */
	private static function schreiben( $v ) {
		switch ( $v['t'] ) {
			case 'dict':
				$out = '<<';
				foreach ( $v['v'] as $k => $x ) {
					$out .= $k . ' ' . self::schreiben( $x ) . ' ';
				}
				return rtrim( $out ) . '>>';
			case 'arr':
				$teile = array();
				foreach ( $v['v'] as $x ) {
					$teile[] = self::schreiben( $x );
				}
				return '[' . implode( ' ', $teile ) . ']';
			case 'name':
				return '/' . $v['v'];
			case 'str':
			case 'hex':
				return $v['raw'];
			case 'ref':
				return $v['n'] . ' ' . $v['g'] . ' R';
			default:
				return (string) $v['v'];
		}
	}

	private static function zahl( $v ) {
		return is_array( $v ) && isset( $v['v'] ) && is_string( $v['v'] ) ? (float) $v['v'] : 0;
	}

	private static function name( $v ) {
		return array( 't' => 'name', 'v' => $v );
	}

	private static function num( $x ) {
		$s = is_int( $x ) ? (string) $x : rtrim( rtrim( number_format( (float) $x, 3, '.', '' ), '0' ), '.' );
		return array( 't' => 'num', 'v' => '' === $s ? '0' : $s );
	}

	/** Text als UTF-16BE-Hexstring (gültig für /V und /TU, unabhängig vom Zeichensatz). */
	private static function text_string( $utf8 ) {
		$u16 = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( (string) $utf8, 'UTF-16BE', 'UTF-8' ) : (string) iconv( 'UTF-8', 'UTF-16BE', (string) $utf8 );
		return array( 't' => 'hex', 'raw' => '<FEFF' . strtoupper( bin2hex( $u16 ) ) . '>' );
	}

	/** PDF-Textstring (literal oder hex, PDFDoc oder UTF-16) → UTF-8. */
	private static function text_lesen( $v ) {
		if ( ! is_array( $v ) ) {
			return '';
		}
		if ( 'hex' === $v['t'] ) {
			$h     = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $v['raw'], 1, -1 ) );
			$bytes = (string) hex2bin( strlen( $h ) % 2 ? $h . '0' : $h );
		} elseif ( 'str' === $v['t'] ) {
			$bytes = self::literal_dekodieren( substr( $v['raw'], 1, -1 ) );
		} else {
			return '';
		}
		if ( "\xFE\xFF" === substr( $bytes, 0, 2 ) ) {
			return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' ) : (string) iconv( 'UTF-16BE', 'UTF-8', substr( $bytes, 2 ) );
		}
		if ( "\xEF\xBB\xBF" === substr( $bytes, 0, 3 ) ) {
			return substr( $bytes, 3 );
		}
		// PDFDocEncoding entspricht für alles, was in Feldnamen vorkommt, Latin-1.
		return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $bytes, 'UTF-8', 'ISO-8859-1' ) : utf8_encode( $bytes ); // phpcs:ignore
	}

	private static function literal_dekodieren( $s ) {
		$out = '';
		$len = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $s[ $i ];
			if ( '\\' !== $c ) {
				$out .= $c;
				continue;
			}
			$i++;
			if ( $i >= $len ) {
				break;
			}
			$c   = $s[ $i ];
			$map = array( 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\' );
			if ( isset( $map[ $c ] ) ) {
				$out .= $map[ $c ];
			} elseif ( ctype_digit( $c ) && $c < '8' ) {
				$okt = $c;
				while ( strlen( $okt ) < 3 && $i + 1 < $len && ctype_digit( $s[ $i + 1 ] ) && $s[ $i + 1 ] < '8' ) {
					$okt .= $s[ ++$i ];
				}
				$out .= chr( octdec( $okt ) & 0xFF );
			} elseif ( "\r" === $c ) {
				if ( $i + 1 < $len && "\n" === $s[ $i + 1 ] ) {
					$i++;
				}
			} elseif ( "\n" !== $c ) {
				$out .= $c;
			}
		}
		return $out;
	}

	/* ===================================================================
	 *  Felder finden
	 * =================================================================== */

	/**
	 * Alle Endfelder des Formulars.
	 *
	 * @return array Liste von ['nr', 'name', 'art' (text|haken|auswahl|sonst), 'ff', 'da', 'q', 'widgets' => [nr …], 'opt']
	 */
	private function felder() {
		$root = $this->aufloesen( $this->trailer['/Root'] );
		if ( ! $root || 'dict' !== $root['t'] || ! isset( $root['v']['/AcroForm'] ) ) {
			return array();
		}
		$af = $this->aufloesen( $root['v']['/AcroForm'] );
		if ( ! $af || 'dict' !== $af['t'] || ! isset( $af['v']['/Fields'] ) ) {
			return array();
		}
		$liste = $this->aufloesen( $af['v']['/Fields'] );
		$out   = array();
		$besucht = array();
		foreach ( (array) ( $liste['v'] ?? array() ) as $ref ) {
			if ( 'ref' === $ref['t'] ) {
				$this->feld_sammeln( $ref['n'], '', array(), $out, $besucht, 0 );
			}
		}
		return $out;
	}

	private function feld_sammeln( $nr, $eltern_name, $erbe, &$out, &$besucht, $tiefe ) {
		if ( $tiefe > 20 || isset( $besucht[ $nr ] ) ) {
			return;
		}
		$besucht[ $nr ] = true;
		$o = $this->objekt( $nr );
		if ( ! $o || 'dict' !== $o['wert']['t'] ) {
			return;
		}
		$d = $o['wert']['v'];

		$teil = isset( $d['/T'] ) ? self::text_lesen( $this->aufloesen( $d['/T'] ) ) : null;
		$name = null === $teil ? $eltern_name : ( '' === $eltern_name ? $teil : $eltern_name . '.' . $teil );
		foreach ( array( '/FT', '/Ff', '/DA', '/Q', '/Opt' ) as $k ) {
			if ( isset( $d[ $k ] ) ) {
				$erbe[ $k ] = $this->aufloesen( $d[ $k ] );
			}
		}

		// Kinder mit eigenem Namen sind Unterfelder; Kinder ohne Namen sind
		// die Widgets (sichtbaren Kästen) dieses Feldes.
		$kinder   = isset( $d['/Kids'] ) ? (array) ( $this->aufloesen( $d['/Kids'] )['v'] ?? array() ) : array();
		$widgets  = array();
		$unterfeld = false;
		foreach ( $kinder as $k ) {
			if ( 'ref' !== $k['t'] ) {
				continue;
			}
			$ko = $this->objekt( $k['n'] );
			if ( $ko && 'dict' === $ko['wert']['t'] && isset( $ko['wert']['v']['/T'] ) ) {
				$unterfeld = true;
				$this->feld_sammeln( $k['n'], $name, $erbe, $out, $besucht, $tiefe + 1 );
			} else {
				$widgets[] = $k['n'];
			}
		}
		if ( $unterfeld && ! $widgets ) {
			return;
		}
		if ( ! $kinder && isset( $d['/Rect'] ) ) {
			$widgets[] = $nr; // Feld und Widget in einem Objekt
		}
		if ( null === $name || '' === $name ) {
			return;
		}

		$ft  = $erbe['/FT']['v'] ?? '';
		$ff  = isset( $erbe['/Ff'] ) ? (int) self::zahl( $erbe['/Ff'] ) : 0;
		$art = 'sonst';
		if ( 'Tx' === $ft ) {
			$art = 'text';
		} elseif ( 'Btn' === $ft && ! ( $ff & ( 1 << 15 ) ) && ! ( $ff & ( 1 << 16 ) ) ) {
			$art = 'haken'; // weder Optionsfeld (Bit 16) noch Schaltfläche (Bit 17)
		} elseif ( 'Ch' === $ft && ( $ff & ( 1 << 17 ) ) ) {
			$art = 'auswahl'; // Kombinationsfeld
		}

		$out[] = array(
			'nr'      => $nr,
			'name'    => $name,
			'art'     => $art,
			'ff'      => $ff,
			'da'      => isset( $erbe['/DA'] ) ? self::text_lesen( $erbe['/DA'] ) : '',
			'q'       => isset( $erbe['/Q'] ) ? (int) self::zahl( $erbe['/Q'] ) : 0,
			'widgets' => $widgets,
		);
	}

	/* ===================================================================
	 *  Ausfüllen
	 * =================================================================== */

	private function ausfuellen( $ctx, &$anz ) {
		$anz = 0;
		foreach ( $this->felder() as $f ) {
			if ( ! in_array( $f['art'], array( 'text', 'haken', 'auswahl' ), true ) ) {
				continue;
			}
			$wert = self::wert_fuer( $f['name'], $ctx );
			if ( null === $wert ) {
				continue;
			}
			if ( 'haken' === $f['art'] ) {
				$this->haken_setzen( $f, self::ist_an( $wert ) );
			} else {
				$this->text_setzen( $f, $wert );
			}
			$anz++;
		}
		if ( ! $anz ) {
			return $this->pdf;
		}
		$this->xfa_entfernen();
		return $this->anhaengen();
	}

	/** Objekt als veränderbares Dictionary holen (schon geändertes zuerst). */
	private function dict_fuer_aenderung( $nr ) {
		if ( isset( $this->neu[ $nr ] ) && is_array( $this->neu[ $nr ] ) ) {
			return $this->neu[ $nr ]['wert'];
		}
		$o = $this->objekt( $nr );
		return $o ? $o['wert'] : null;
	}

	private function aendern( $nr, $wert ) {
		$this->neu[ $nr ] = array( 'wert' => $wert, 'stream' => null );
	}

	private function neues_objekt( $wert, $stream = null ) {
		$nr               = $this->size++;
		$this->neu[ $nr ] = array( 'wert' => $wert, 'stream' => $stream );
		return array( 't' => 'ref', 'n' => $nr, 'g' => 0 );
	}

	private function haken_setzen( $f, $an ) {
		$zustand = 'Off';
		foreach ( $f['widgets'] as $w ) {
			$wd = $this->dict_fuer_aenderung( $w );
			if ( ! $wd || 'dict' !== $wd['t'] ) {
				continue;
			}
			$ein = 'Yes';
			$ap  = isset( $wd['v']['/AP'] ) ? $this->aufloesen( $wd['v']['/AP'] ) : null;
			$n   = $ap && isset( $ap['v']['/N'] ) ? $this->aufloesen( $ap['v']['/N'] ) : null;
			if ( $n && 'dict' === $n['t'] ) {
				foreach ( array_keys( $n['v'] ) as $k ) {
					if ( '/Off' !== $k ) {
						$ein = substr( $k, 1 );
						break;
					}
				}
			}
			$zustand           = $an ? $ein : 'Off';
			$wd['v']['/AS']    = self::name( $zustand );
			if ( (int) $w === (int) $f['nr'] ) {
				$wd['v']['/V']  = self::name( $zustand );
				$wd['v']['/Ff'] = self::num( $f['ff'] | 1 );
			}
			$this->aendern( $w, $wd );
		}
		if ( ! in_array( (int) $f['nr'], array_map( 'intval', $f['widgets'] ), true ) ) {
			$fd = $this->dict_fuer_aenderung( $f['nr'] );
			if ( $fd && 'dict' === $fd['t'] ) {
				$fd['v']['/V']  = self::name( $zustand );
				$fd['v']['/Ff'] = self::num( $f['ff'] | 1 );
				$this->aendern( $f['nr'], $fd );
			}
		}
	}

	private function text_setzen( $f, $text ) {
		$text = (string) $text;
		foreach ( $f['widgets'] as $w ) {
			$wd = $this->dict_fuer_aenderung( $w );
			if ( ! $wd || 'dict' !== $wd['t'] || ! isset( $wd['v']['/Rect'] ) ) {
				continue;
			}
			$rect = array();
			foreach ( $this->aufloesen( $wd['v']['/Rect'] )['v'] ?? array() as $x ) {
				$rect[] = self::zahl( $this->aufloesen( $x ) );
			}
			if ( 4 !== count( $rect ) ) {
				continue;
			}
			$breite = abs( $rect[2] - $rect[0] );
			$hoehe  = abs( $rect[3] - $rect[1] );

			// Gedrehte Felder (/MK /R 90|270) tauschen Breite und Höhe.
			$dreh = 0;
			if ( isset( $wd['v']['/MK'] ) ) {
				$mk = $this->aufloesen( $wd['v']['/MK'] );
				if ( $mk && isset( $mk['v']['/R'] ) ) {
					$dreh = ( (int) self::zahl( $mk['v']['/R'] ) % 360 + 360 ) % 360;
				}
			}
			if ( 90 === $dreh || 270 === $dreh ) {
				list( $breite, $hoehe ) = array( $hoehe, $breite );
			}

			$da = $f['da'];
			if ( isset( $wd['v']['/DA'] ) ) {
				$da = self::text_lesen( $this->aufloesen( $wd['v']['/DA'] ) );
			}
			$q = isset( $wd['v']['/Q'] ) ? (int) self::zahl( $wd['v']['/Q'] ) : $f['q'];

			$inhalt = $this->erscheinung( $text, $breite, $hoehe, $da, $q, (bool) ( $f['ff'] & ( 1 << 12 ) ) );
			$matrix = array( 1, 0, 0, 1, 0, 0 );
			if ( 90 === $dreh ) {
				$matrix = array( 0, 1, -1, 0, 0, 0 );
			} elseif ( 180 === $dreh ) {
				$matrix = array( -1, 0, 0, -1, 0, 0 );
			} elseif ( 270 === $dreh ) {
				$matrix = array( 0, -1, 1, 0, 0, 0 );
			}
			$xobj = array( 't' => 'dict', 'v' => array(
				'/Type'      => self::name( 'XObject' ),
				'/Subtype'   => self::name( 'Form' ),
				'/FormType'  => self::num( 1 ),
				'/BBox'      => array( 't' => 'arr', 'v' => array( self::num( 0 ), self::num( 0 ), self::num( $breite ), self::num( $hoehe ) ) ),
				'/Matrix'    => array( 't' => 'arr', 'v' => array_map( array( __CLASS__, 'num' ), $matrix ) ),
				'/Resources' => array( 't' => 'dict', 'v' => array(
					'/Font' => array( 't' => 'dict', 'v' => array( '/BIHelv' => $this->font_ref() ) ),
				) ),
			) );
			$ap_ref         = $this->neues_objekt( $xobj, $inhalt );
			$wd['v']['/AP'] = array( 't' => 'dict', 'v' => array( '/N' => $ap_ref ) );
			if ( (int) $w === (int) $f['nr'] ) {
				$wd['v']['/V']  = self::text_string( $text );
				$wd['v']['/Ff'] = self::num( $f['ff'] | 1 );
			}
			$this->aendern( $w, $wd );
		}
		if ( ! in_array( (int) $f['nr'], array_map( 'intval', $f['widgets'] ), true ) ) {
			$fd = $this->dict_fuer_aenderung( $f['nr'] );
			if ( $fd && 'dict' === $fd['t'] ) {
				$fd['v']['/V']  = self::text_string( $text );
				$fd['v']['/Ff'] = self::num( $f['ff'] | 1 );
				$this->aendern( $f['nr'], $fd );
			}
		}
	}

	/** Einmal je Datei: Helvetica mit WinAnsi-Kodierung als eigenes Objekt. */
	private function font_ref() {
		if ( ! $this->font_nr ) {
			$ref           = $this->neues_objekt( array( 't' => 'dict', 'v' => array(
				'/Type'     => self::name( 'Font' ),
				'/Subtype'  => self::name( 'Type1' ),
				'/BaseFont' => self::name( 'Helvetica' ),
				'/Encoding' => self::name( 'WinAnsiEncoding' ),
			) ) );
			$this->font_nr = $ref['n'];
		}
		return array( 't' => 'ref', 'n' => $this->font_nr, 'g' => 0 );
	}

	/** UTF-8 → WinAnsi (CP1252); Unbekanntes wird umschrieben oder zu „?". */
	private static function winansi( $utf8 ) {
		$s = @iconv( 'UTF-8', 'CP1252//TRANSLIT', (string) $utf8 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $s ) {
			$s = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( (string) $utf8, 'Windows-1252', 'UTF-8' ) : (string) $utf8;
		}
		return (string) $s;
	}

	private static function breite_von( $cp1252, $groesse ) {
		if ( null === self::$cw ) {
			self::$cw = array();
			$datei    = defined( 'BI_PATH' ) ? BI_PATH . 'vendor/fpdf/font/helvetica.php' : '';
			if ( $datei && file_exists( $datei ) ) {
				$cw = array();
				include $datei; // setzt $cw
				self::$cw = is_array( $cw ) ? $cw : array();
			}
		}
		$summe = 0;
		$len   = strlen( $cp1252 );
		for ( $i = 0; $i < $len; $i++ ) {
			$summe += self::$cw[ $cp1252[ $i ] ] ?? 556;
		}
		return $summe * $groesse / 1000;
	}

	private static function pdf_literal( $bytes ) {
		return '(' . strtr( $bytes, array( '\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r', "\n" => '\\n' ) ) . ')';
	}

	/**
	 * Inhaltsstream des Erscheinungsbilds.
	 *
	 * Schriftgröße aus /DA; 0 heißt „automatisch" – dann so groß, wie Höhe
	 * und Breite es erlauben, höchstens 11 pt. Mehrzeilige Felder brechen um.
	 */
	private function erscheinung( $text, $breite, $hoehe, $da, $q, $mehrzeilig ) {
		$groesse = 0.0;
		if ( preg_match( '/([\d.]+)\s+Tf/', $da, $m ) ) {
			$groesse = (float) $m[1];
		}
		$farbe = '0 g';
		if ( preg_match( '/([\d.]+\s+g|[\d.]+\s+[\d.]+\s+[\d.]+\s+rg|[\d.]+\s+[\d.]+\s+[\d.]+\s+[\d.]+\s+k)\s*$/', trim( preg_replace( '/\/\S+\s+[\d.]+\s+Tf/', '', $da ) ), $fm ) ) {
			$farbe = $fm[1];
		}
		$rand  = 2.0;
		$platz = max( 1, $breite - 2 * $rand );
		$cp    = self::winansi( $text );

		if ( $mehrzeilig ) {
			$fs     = $groesse > 0 ? $groesse : 10.0;
			$zeilen = self::umbrechen( $cp, $platz, $fs );
			if ( $groesse <= 0 ) {
				// Automatisch: verkleinern, bis alles hineinpasst.
				while ( $fs > 6 && count( $zeilen ) * $fs * 1.15 > $hoehe - 2 * $rand ) {
					$fs    -= 0.5;
					$zeilen = self::umbrechen( $cp, $platz, $fs );
				}
			}
			$ops = '';
			$y   = $hoehe - $rand - $fs;
			foreach ( $zeilen as $z ) {
				$x    = self::ausrichten( $z, $fs, $platz, $rand, $q );
				$ops .= sprintf( "1 0 0 1 %s %s Tm %s Tj\n", self::f( $x ), self::f( $y ), self::pdf_literal( $z ) );
				$y   -= $fs * 1.15;
			}
		} else {
			$cp = str_replace( array( "\r", "\n" ), ' ', $cp );
			$fs = $groesse > 0 ? $groesse : min( 11.0, max( 4.0, ( $hoehe - 2 * $rand ) * 0.75 ) );
			$w  = self::breite_von( $cp, $fs );
			if ( $w > $platz ) {
				// Zu lang: kleiner schreiben statt abschneiden, nicht unter 5 pt.
				$fs = max( 5.0, $fs * $platz / $w );
			}
			$x   = self::ausrichten( $cp, $fs, $platz, $rand, $q );
			$y   = ( $hoehe - $fs ) / 2 + 0.22 * $fs;
			$ops = sprintf( "1 0 0 1 %s %s Tm %s Tj\n", self::f( $x ), self::f( $y ), self::pdf_literal( $cp ) );
		}

		return "/Tx BMC\nq\n" . sprintf( "%s %s %s %s re W n\n", self::f( 1 ), self::f( 1 ), self::f( max( 0, $breite - 2 ) ), self::f( max( 0, $hoehe - 2 ) ) )
			. "BT\n/BIHelv " . self::f( $fs ) . " Tf\n" . $farbe . "\n" . $ops . "ET\nQ\nEMC\n";
	}

	private static function ausrichten( $zeile, $fs, $platz, $rand, $q ) {
		$w = self::breite_von( $zeile, $fs );
		if ( 1 === $q ) {
			return $rand + max( 0, ( $platz - $w ) / 2 );
		}
		if ( 2 === $q ) {
			return $rand + max( 0, $platz - $w );
		}
		return $rand;
	}

	private static function umbrechen( $cp, $platz, $fs ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $cp ) as $absatz ) {
			$zeile = '';
			foreach ( preg_split( '/ +/', $absatz ) as $wort ) {
				$versuch = '' === $zeile ? $wort : $zeile . ' ' . $wort;
				if ( '' !== $zeile && self::breite_von( $versuch, $fs ) > $platz ) {
					$out[] = $zeile;
					$zeile = $wort;
				} else {
					$zeile = $versuch;
				}
			}
			$out[] = $zeile;
		}
		return $out;
	}

	private static function f( $x ) {
		return self::num( $x )['v'];
	}

	/**
	 * XFA-Formulare (LiveCycle) zeigen ihre eigene Fassung und ignorierten
	 * die ausgefüllten Felder. Mit dem Ausfüllen fällt XFA deshalb weg; die
	 * normalen Formularfelder bleiben.
	 */
	private function xfa_entfernen() {
		$root_ref = $this->trailer['/Root'];
		$root     = $this->aufloesen( $root_ref );
		if ( ! $root || ! isset( $root['v']['/AcroForm'] ) ) {
			return;
		}
		$af_ref = $root['v']['/AcroForm'];
		$af     = $this->aufloesen( $af_ref );
		if ( ! $af || ! isset( $af['v']['/XFA'] ) ) {
			return;
		}
		unset( $af['v']['/XFA'] );
		if ( 'ref' === $af_ref['t'] ) {
			$this->aendern( $af_ref['n'], $af );
		} elseif ( 'ref' === $root_ref['t'] ) {
			$root['v']['/AcroForm'] = $af;
			$this->aendern( $root_ref['n'], $root );
		}
	}

	/* ===================================================================
	 *  Schreiben: inkrementelle Aktualisierung
	 * =================================================================== */

	private function anhaengen() {
		$out = $this->pdf;
		if ( "\n" !== substr( $out, -1 ) ) {
			$out .= "\n";
		}
		$offs = array();
		ksort( $this->neu );
		foreach ( $this->neu as $nr => $o ) {
			$gen         = ( isset( $this->xref[ $nr ] ) && 1 === $this->xref[ $nr ]['typ'] ) ? (int) $this->xref[ $nr ]['gen'] : 0;
			$offs[ $nr ] = array( strlen( $out ), $gen );
			$wert        = $o['wert'];
			if ( null !== $o['stream'] ) {
				$wert['v']['/Length'] = self::num( strlen( $o['stream'] ) );
				unset( $wert['v']['/Filter'], $wert['v']['/DecodeParms'] );
				$out .= $nr . ' ' . $gen . " obj\n" . self::schreiben( $wert ) . "\nstream\n" . $o['stream'] . "\nendstream\nendobj\n";
			} else {
				$out .= $nr . ' ' . $gen . " obj\n" . self::schreiben( $wert ) . "\nendobj\n";
			}
		}

		$trailer = array();
		foreach ( array( '/Root', '/Info', '/ID' ) as $k ) {
			if ( isset( $this->trailer[ $k ] ) ) {
				$trailer[ $k ] = $this->trailer[ $k ];
			}
		}
		$trailer['/Prev'] = self::num( $this->startxref );

		if ( $this->xref_ist_stream ) {
			// Auf einen Querverweis-Stream folgt wieder einer – das verlangt
			// die Norm für Dateien ohne klassische Tabelle.
			$xnr          = $this->size++;
			$offs[ $xnr ] = array( strlen( $out ), 0 );
			ksort( $offs );
			$index = array();
			$daten = '';
			foreach ( self::abschnitte( array_keys( $offs ) ) as $ab ) {
				$index[] = self::num( $ab[0] );
				$index[] = self::num( $ab[1] );
				for ( $n = $ab[0]; $n < $ab[0] + $ab[1]; $n++ ) {
					$daten .= chr( 1 ) . pack( 'N', $offs[ $n ][0] ) . pack( 'n', $offs[ $n ][1] );
				}
			}
			$d = array_merge( array(
				'/Type'   => self::name( 'XRef' ),
				'/Size'   => self::num( $this->size ),
				'/Index'  => array( 't' => 'arr', 'v' => $index ),
				'/W'      => array( 't' => 'arr', 'v' => array( self::num( 1 ), self::num( 4 ), self::num( 2 ) ) ),
				'/Length' => self::num( strlen( $daten ) ),
			), $trailer );
			$start = $offs[ $xnr ][0];
			$out  .= $xnr . " 0 obj\n" . self::schreiben( array( 't' => 'dict', 'v' => $d ) ) . "\nstream\n" . $daten . "\nendstream\nendobj\n";
		} else {
			ksort( $offs );
			$start = strlen( $out );
			// Wie Acrobat: Objekt 0 als freier Eintrag vorneweg. Manche Leser
			// halten eine Tabelle, die nicht bei 0 beginnt, sonst für verrutscht.
			$out  .= "xref\n0 1\n0000000000 65535 f\r\n";
			foreach ( self::abschnitte( array_keys( $offs ) ) as $ab ) {
				$out .= $ab[0] . ' ' . $ab[1] . "\n";
				for ( $n = $ab[0]; $n < $ab[0] + $ab[1]; $n++ ) {
					$out .= sprintf( "%010d %05d n\r\n", $offs[ $n ][0], $offs[ $n ][1] );
				}
			}
			$trailer['/Size'] = self::num( $this->size );
			$out             .= 'trailer' . "\n" . self::schreiben( array( 't' => 'dict', 'v' => $trailer ) ) . "\n";
		}
		$out .= "startxref\n" . $start . "\n%%EOF\n";
		return $out;
	}

	/** Fortlaufende Nummern zu Abschnitten [start, anzahl]. */
	private static function abschnitte( $nummern ) {
		sort( $nummern );
		$out = array();
		foreach ( $nummern as $n ) {
			$letzt = count( $out ) - 1;
			if ( $letzt >= 0 && $out[ $letzt ][0] + $out[ $letzt ][1] === $n ) {
				$out[ $letzt ][1]++;
			} else {
				$out[] = array( $n, 1 );
			}
		}
		return $out;
	}
}
