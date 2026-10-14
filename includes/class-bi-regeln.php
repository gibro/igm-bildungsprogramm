<?php
/**
 * Regeln – ein Regelwerk für Anmeldeweg, Anmeldeformular, Benachrichtigungen
 * und Anhänge (seit 1.147.0).
 *
 * ============================================================================
 *  WARUM EIN REGELWERK STATT DREIER
 * ============================================================================
 *  Bis 1.146.0 gab es drei Regelwerke mit drei Logiken: die Anmeldevarianten
 *  (Einstellungen), die Formular-Zuordnung (Anmeldeformulare) und die
 *  Bedingung je Benachrichtigung. Die ersten beiden prüften „von oben nach
 *  unten, die erste zutreffende gewinnt" – die Reihenfolge ersetzte fehlende
 *  UND/NICHT-Bedingungen. Das hat einen Warntext gebraucht („Die Fristregel
 *  gehört nach oben"), eine eigene Verdeckt-Rechnung und es hatte einen
 *  bekannten Fehlerfall: „37,6 → Direktanmeldung" über „Haken nein → keine
 *  Anmeldung" machte gesperrte Seminare buchbar, und beide Zeilen sahen
 *  richtig aus.
 *
 * ============================================================================
 *  DAS MODELL
 * ============================================================================
 *  Regel = Bedingungen (alle müssen zutreffen, UND) + genau eine Aktion.
 *  Bedingung = Feld · Vergleich · Werte (mehrere Werte = ODER).
 *  Keine Bedingung = immer.
 *
 *  DIE POSITION IN DER LISTE BEDEUTET NICHTS. Treffen mehrere Regeln zu:
 *
 *    Anmeldeweg   feste Rangfolge  Keine > Geschäftsstelle > Direkt.
 *                 Eine Sperre schlägt alles – so versteht es jeder, und
 *                 keine Regel kann sie versehentlich aufheben.
 *    Formular     die Regel mit den meisten Bedingungen (die genauere);
 *                 Gleichstand wird auf der Regelseite gemeldet und nach
 *                 der kleineren ID entschieden (stabil, aber sichtbar).
 *    Mail/Anhang  sammelnd – alles, was zutrifft, gilt.
 *
 *  Felder kommen aus zwei Quellen: dem SEMINAR (Begriffe, Seminarform,
 *  Frist, Haken) und der ANMELDUNG (jedes Feld aus BI_Anmeldefelder).
 *  Anmeldeweg und Formular stehen fest, BEVOR jemand das Formular
 *  ausfüllt – Regeln für sie dürfen deshalb nur Seminarfelder lesen.
 *
 * ============================================================================
 *  AUSBILDUNGSREIHEN
 * ============================================================================
 *  Eine Reihe beantwortet nur Freistellung (ihr eigenes Textfeld) und
 *  Anzeigen. Eine Regel gilt für eine Reihe nur, wenn sie mindestens eine
 *  Bedingung auf eines dieser Felder hat – sonst träfe „Haken ‚Anmeldung
 *  möglich‘ ist leer" jede Reihe, weil das Meta an ihr nie gesetzt wird.
 *  Eine Bedingung auf ein Feld, das die Reihe nicht kennt, trifft nicht zu –
 *  außer sie ist verneint („ist nicht", „enthält nicht"): „Bildungszentrum
 *  ist nicht Sprockhövel" ist für eine Reihe ohne Bildungszentrum wahr. Genau
 *  das hält die übernommenen Regeln verhaltensgleich (siehe migrieren()).
 *
 * ============================================================================
 *  RÜCKWEG UND FEHLENDE DATEI
 * ============================================================================
 *  Die alten Optionen (bi_settings['rules'], bi_formular_regeln, die
 *  cond_*- und attach_*-Felder der Benachrichtigungen) bleiben unverändert
 *  liegen. Fehlt diese Datei nach einem Handdeploy oder wird 1.146.0
 *  zurückgespielt, rechnen die alten Stellen mit ihnen weiter wie bisher.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Regeln {

	const OPTION         = 'bi_regeln';
	const SEQ_OPTION     = 'bi_regeln_seq';
	const VERSION_OPTION = 'bi_regeln_version';
	const BERICHT_OPTION = 'bi_regeln_bericht';
	const VERSION        = '1';
	const PAGE           = 'bi-regeln';

	/** Verneinte Vergleiche – an einem Feld ohne Antwort gelten sie als erfüllt. */
	const NEG_OPS = array( 'ist_nicht', 'enthaelt_nicht', 'nicht_abgelaufen' );

	/** Felder, die eine Ausbildungsreihe beantworten kann. */
	const REIHEN_FELDER = array( 'seminar:freistellung', 'seminar:anzeigen' );

	/** Rang beim Anmeldeweg: höher gewinnt. */
	const RANG = array( 'direct' => 1, 'gs' => 2, 'keine' => 3 );

	private static $regeln_cache = null;
	private static $memo_weg     = array();
	private static $felder_cache = null;

	public static function init() {
		self::migrieren();

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_bi_regel_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bi_regel_aktion', array( __CLASS__, 'handle_aktion' ) );
		add_action( 'admin_post_bi_regeln_bericht_ok', array( __CLASS__, 'handle_bericht_ok' ) );
		add_action( 'admin_post_bi_pdf_formular_vorschau', array( __CLASS__, 'handle_pdf_vorschau' ) );
	}

	/** Läuft das neue Regelwerk? (Erst nach der Übernahme der alten Regeln.) */
	public static function aktiv() {
		return self::VERSION === (string) get_option( self::VERSION_OPTION, '' );
	}

	public static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/* ===================================================================
	 *  Speichern und Lesen
	 * =================================================================== */

	/** Alle Regeln, bereinigt. */
	public static function alle() {
		if ( null !== self::$regeln_cache ) {
			return self::$regeln_cache;
		}
		$roh = get_option( self::OPTION, array() );
		$out = array();
		foreach ( is_array( $roh ) ? $roh : array() as $r ) {
			if ( ! is_array( $r ) || empty( $r['id'] ) ) {
				continue;
			}
			$out[] = self::normalisieren( $r );
		}
		self::$regeln_cache = $out;
		return $out;
	}

	private static function normalisieren( $r ) {
		$bed = array();
		foreach ( (array) ( $r['bedingungen'] ?? array() ) as $b ) {
			if ( ! is_array( $b ) || empty( $b['feld'] ) || empty( $b['op'] ) ) {
				continue;
			}
			$bed[] = array(
				'feld'  => (string) $b['feld'],
				'op'    => (string) $b['op'],
				'werte' => array_values( array_filter( array_map( 'strval', (array) ( $b['werte'] ?? array() ) ), 'strlen' ) ),
			);
		}
		return array(
			'id'          => (int) $r['id'],
			'aktiv'       => ! empty( $r['aktiv'] ) ? 1 : 0,
			'notiz'       => (string) ( $r['notiz'] ?? '' ),
			'bedingungen' => $bed,
			'aktion'      => (string) ( $r['aktion'] ?? '' ),
			'ziel'        => (string) ( $r['ziel'] ?? '' ),
			'anhang'      => (string) ( $r['anhang'] ?? '' ),
			'datei'       => (int) ( $r['datei'] ?? 0 ),
		);
	}

	public static function get( $id ) {
		foreach ( self::alle() as $r ) {
			if ( (int) $r['id'] === (int) $id ) {
				return $r;
			}
		}
		return null;
	}

	/** Liste speichern – IDs bleiben, neue bekommen die nächste freie. */
	private static function schreiben( $liste ) {
		$seq = (int) get_option( self::SEQ_OPTION, 0 );
		foreach ( $liste as $r ) {
			$seq = max( $seq, (int) ( $r['id'] ?? 0 ) );
		}
		foreach ( $liste as $i => $r ) {
			if ( empty( $r['id'] ) ) {
				$liste[ $i ]['id'] = ++$seq;
			}
		}
		update_option( self::SEQ_OPTION, $seq );
		update_option( self::OPTION, array_values( $liste ), false );
		self::cache_leeren();
		return $liste;
	}

	/** Eine Regel anlegen (id 0) oder ersetzen. Gibt die ID zurück. */
	public static function put( $regel ) {
		$alle = self::alle();
		$id   = (int) ( $regel['id'] ?? 0 );
		$hit  = false;
		foreach ( $alle as $i => $r ) {
			if ( $id && (int) $r['id'] === $id ) {
				$alle[ $i ] = $regel;
				$hit        = true;
			}
		}
		if ( ! $hit ) {
			$regel['id'] = 0;
			$alle[]      = $regel;
		}
		$alle = self::schreiben( $alle );
		if ( $hit ) {
			return $id;
		}
		$letzte = end( $alle );
		return (int) $letzte['id'];
	}

	public static function loeschen( $id ) {
		$rest = array();
		$hit  = false;
		foreach ( self::alle() as $r ) {
			if ( (int) $r['id'] === (int) $id ) {
				$hit = true;
				continue;
			}
			$rest[] = $r;
		}
		if ( $hit ) {
			self::schreiben( $rest );
		}
		return $hit;
	}

	public static function cache_leeren() {
		self::$regeln_cache = null;
		self::$memo_weg     = array();
		self::$felder_cache = null;
	}

	/* ===================================================================
	 *  Felder und Vergleiche
	 * =================================================================== */

	/** Taxonomie-Felder des Seminars: Regelfeld => Taxonomie. */
	public static function tax_felder() {
		return array(
			'seminar:freistellung'  => BI_TAX_FREI,
			'seminar:handlungsfeld' => BI_TAX_THEMA,
			'seminar:zielgruppe'    => BI_TAX_ZIEL,
			'seminar:ort'           => BI_TAX_ORT,
			'seminar:programm'      => BI_TAX_PROGRAMM,
		);
	}

	/** Haken am Seminar: Regelfeld => Meta-Schlüssel. */
	public static function haken_felder() {
		return array(
			'seminar:flag'       => '_bi_anmeldung_moeglich',
			'seminar:ausgebucht' => '_bi_ausgebucht',
			'seminar:anzeigen'   => '_bi_anzeigen',
		);
	}

	/**
	 * Alle Felder, auf die sich eine Bedingung beziehen kann.
	 *
	 * @return array feld => [label, quelle (seminar|anmeldung), art (begriff|haken|frist|wert), typ]
	 */
	public static function felder() {
		if ( null !== self::$felder_cache ) {
			return self::$felder_cache;
		}
		$f = array(
			'seminar:freistellung'  => array( 'label' => 'Freistellung', 'art' => 'begriff' ),
			'seminar:handlungsfeld' => array( 'label' => 'Themenfeld', 'art' => 'begriff' ),
			'seminar:zielgruppe'    => array( 'label' => 'Zielgruppe', 'art' => 'begriff' ),
			'seminar:ort'           => array( 'label' => 'Bildungszentrum / Veranstalter*in', 'art' => 'begriff' ),
			'seminar:programm'      => array( 'label' => 'Programm', 'art' => 'begriff' ),
			'seminar:seminarform'   => array( 'label' => 'Seminarform', 'art' => 'begriff' ),
			'seminar:frist'         => array( 'label' => 'Anmeldefrist (Tage vor Beginn)', 'art' => 'frist' ),
			'seminar:flag'          => array( 'label' => 'Haken „Anmeldung möglich"', 'art' => 'haken' ),
			'seminar:ausgebucht'    => array( 'label' => 'Haken „Ausgebucht"', 'art' => 'haken' ),
			'seminar:anzeigen'      => array( 'label' => 'Haken „Auf der Website anzeigen"', 'art' => 'haken' ),
		);
		foreach ( $f as $k => $v ) {
			$f[ $k ]['quelle'] = 'seminar';
			$f[ $k ]['typ']    = '';
		}
		if ( class_exists( 'BI_Anmeldefelder' ) ) {
			foreach ( BI_Anmeldefelder::alle() as $key => $cfg ) {
				$f[ 'anmeldung:' . $key ] = array(
					'label'  => (string) ( $cfg['label'] ?? $key ),
					'quelle' => 'anmeldung',
					'art'    => 'wert',
					'typ'    => (string) ( $cfg['type'] ?? 'text' ),
				);
			}
		}
		self::$felder_cache = $f;
		return $f;
	}

	public static function feld( $key ) {
		$f = self::felder();
		return $f[ $key ] ?? null;
	}

	public static function op_labels() {
		return array(
			'ist'              => 'ist',
			'ist_nicht'        => 'ist nicht',
			'enthaelt'         => 'enthält',
			'enthaelt_nicht'   => 'enthält nicht',
			'leer'             => 'ist leer',
			'nicht_leer'       => 'ist ausgefüllt',
			'abgelaufen'       => 'ist abgelaufen',
			'nicht_abgelaufen' => 'ist nicht abgelaufen',
		);
	}

	/** Welche Vergleiche passen zu welcher Feldart? */
	public static function ops_fuer( $art ) {
		switch ( $art ) {
			case 'haken':
				return array( 'ist', 'ist_nicht' );
			case 'frist':
				return array( 'abgelaufen', 'nicht_abgelaufen' );
			default:
				return array( 'ist', 'ist_nicht', 'enthaelt', 'enthaelt_nicht', 'leer', 'nicht_leer' );
		}
	}

	/** Braucht dieser Vergleich Werte? */
	public static function braucht_werte( $op ) {
		return ! in_array( $op, array( 'leer', 'nicht_leer' ), true );
	}

	/**
	 * Werte, die ein Feld annehmen kann – für die Auswahl in der Maske.
	 *
	 * @return array Liste von [wert, beschriftung]
	 */
	public static function optionen( $feld ) {
		$def = self::feld( $feld );
		if ( ! $def ) {
			return array();
		}
		$paare = function ( $liste ) {
			$out = array();
			foreach ( $liste as $v ) {
				$out[] = array( (string) $v, (string) $v );
			}
			return $out;
		};
		if ( 'haken' === $def['art'] ) {
			return array( array( 'ja', 'ja' ), array( 'nein', 'nein' ), array( 'leer', 'leer (nie gesetzt)' ) );
		}
		if ( 'frist' === $def['art'] ) {
			return array();
		}
		if ( 'seminar:seminarform' === $feld ) {
			return $paare( array( 'Präsenz', 'Online' ) );
		}
		$tax = self::tax_felder();
		if ( isset( $tax[ $feld ] ) ) {
			return $paare( BI_Mailer::term_names( $tax[ $feld ] ) );
		}
		// Anmeldefelder
		$key = substr( $feld, strlen( 'anmeldung:' ) );
		if ( 'freistellung' === $def['typ'] ) {
			return $paare( BI_Mailer::cond_options( BI_Mailer::COND_ANMELDUNG_FREI ) );
		}
		if ( 'checkbox' === $def['typ'] ) {
			return array( array( 'ja', 'angekreuzt' ), array( 'nein', 'nicht angekreuzt' ) );
		}
		$cfg  = class_exists( 'BI_Anmeldefelder' ) ? BI_Anmeldefelder::feld( $key ) : null;
		$opts = (array) ( $cfg['options'] ?? array() );
		if ( ! $opts ) {
			return array();
		}
		$out   = array();
		$liste = BI_Anmeldefelder::ist_liste( $opts );
		foreach ( $opts as $k => $l ) {
			if ( '' === trim( (string) $l ) ) {
				continue;
			}
			$out[] = array( $liste ? (string) $l : (string) $k, (string) $l );
		}
		return $out;
	}

	/* ===================================================================
	 *  Auswertung
	 * =================================================================== */

	/** Kontext für ein Seminar (oder eine Reihe), optional mit Anmeldung. */
	public static function kontext( $post_id, $submission = null, $vorab = null ) {
		$post_id = (int) $post_id;
		$pt      = $post_id ? (string) get_post_type( $post_id ) : '';
		if ( '' === $pt && is_array( $submission ) && ! empty( $submission['post_type'] ) ) {
			$pt = (string) $submission['post_type']; // Seminar inzwischen gelöscht
		}
		return array(
			'post_id'    => $post_id,
			'post_type'  => $pt,
			'reihe'      => class_exists( 'BI_Reihen' ) && BI_Reihen::CPT === $pt,
			'submission' => $submission,
			'vorab'      => $vorab,
		);
	}

	/**
	 * Was hat das Seminar bzw. die Anmeldung in diesem Feld?
	 *
	 * @return mixed null = Feld hier nicht beantwortbar; sonst je Art:
	 *               begriff/wert: string[] · haken: Rohwert · frist: Startdatum
	 */
	public static function feldwert( $ctx, $feld ) {
		if ( is_array( $ctx['vorab'] ) && array_key_exists( $feld, $ctx['vorab'] ) ) {
			return $ctx['vorab'][ $feld ];
		}

		if ( 0 === strpos( $feld, 'anmeldung:' ) ) {
			return self::anmeldewert( $ctx['submission'], substr( $feld, strlen( 'anmeldung:' ) ) );
		}

		$pid = (int) $ctx['post_id'];
		if ( $ctx['reihe'] ) {
			if ( ! in_array( $feld, self::REIHEN_FELDER, true ) ) {
				return null;
			}
			if ( 'seminar:anzeigen' === $feld ) {
				return (string) get_post_meta( $pid, '_bi_anzeigen', true );
			}
			$roh = (string) get_post_meta( $pid, '_bir_freistellung', true );
			return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', $roh ) ), 'strlen' ) );
		}

		// Bewusst ohne eigenen Merker: Ein Import ändert Begriffe und Haken im
		// selben Aufruf, in dem danach gerechnet wird. Gemerkt wird erst das
		// Ergebnis (anmeldeweg), wie bisher.
		$wert = null;
		$tax  = self::tax_felder();
		$hak  = self::haken_felder();
		if ( isset( $tax[ $feld ] ) ) {
			$terms = $pid ? wp_get_object_terms( $pid, $tax[ $feld ], array( 'fields' => 'names' ) ) : array();
			$wert  = ( is_array( $terms ) && ! is_wp_error( $terms ) ) ? array_values( $terms ) : array();
		} elseif ( isset( $hak[ $feld ] ) ) {
			$wert = $pid ? (string) get_post_meta( $pid, $hak[ $feld ], true ) : '';
		} elseif ( 'seminar:seminarform' === $feld ) {
			$wert = '' !== $ctx['post_type'] ? BI_Settings::form_names( $ctx['post_type'] ) : array();
		} elseif ( 'seminar:frist' === $feld ) {
			$wert = $pid ? (string) get_post_meta( $pid, '_bi_startdatum', true ) : '';
		}

		return $wert;
	}

	/**
	 * Wert eines Anmeldefeldes als Liste der Schreibweisen, unter denen er
	 * getroffen werden soll: gespeicherter Schlüssel UND sichtbare Beschriftung.
	 * „mitglied ist nein" und „mitglied ist Nein" meinen dasselbe.
	 *
	 * @return string[]|null null = keine Anmeldung im Kontext.
	 */
	private static function anmeldewert( $submission, $key ) {
		if ( ! is_array( $submission ) ) {
			return null;
		}
		$d = $submission['data'] ?? array();
		if ( is_string( $d ) ) {
			$d = json_decode( $d, true );
		}
		$d   = is_array( $d ) ? $d : array();
		$roh = $d[ $key ] ?? ( $submission[ $key ] ?? '' );
		if ( is_array( $roh ) ) {
			$roh = implode( ', ', $roh );
		}
		$roh = trim( (string) $roh );

		$cfg = class_exists( 'BI_Anmeldefelder' ) ? BI_Anmeldefelder::feld( $key ) : null;
		if ( $cfg && 'checkbox' === ( $cfg['type'] ?? '' ) ) {
			return array( '1' === $roh ? 'ja' : 'nein' );
		}
		if ( '' === $roh ) {
			return array();
		}
		$out  = array( $roh );
		$opts = (array) ( $cfg['options'] ?? array() );
		if ( $opts && isset( $opts[ $roh ] ) && ! BI_Anmeldefelder::ist_liste( $opts ) ) {
			$out[] = (string) $opts[ $roh ];
		}
		return $out;
	}

	/** Trifft eine Bedingung im Kontext zu? */
	public static function bedingung_trifft( $b, $ctx ) {
		$def = self::feld( $b['feld'] );
		$op  = (string) $b['op'];
		$neg = in_array( $op, self::NEG_OPS, true );
		if ( ! $def ) {
			return false; // Feld gibt es nicht (mehr) – z. B. gelöschtes Anmeldefeld
		}

		$wert = self::feldwert( $ctx, $b['feld'] );
		if ( null === $wert ) {
			return $neg;
		}
		$werte = (array) $b['werte'];

		if ( 'haken' === $def['art'] ) {
			$hit = false;
			foreach ( $werte as $w ) {
				$hit = $hit || BI_Settings::flag_matches( $w, $wert );
			}
			return $neg ? ! $hit : $hit;
		}

		if ( 'frist' === $def['art'] ) {
			$hit = false;
			foreach ( $werte as $w ) {
				$hit = $hit || BI_Settings::frist_abgelaufen( $wert, $w );
			}
			return $neg ? ! $hit : $hit;
		}

		$namen = array_values( array_filter( array_map( 'trim', (array) $wert ), 'strlen' ) );
		if ( 'leer' === $op ) {
			return ! $namen;
		}
		if ( 'nicht_leer' === $op ) {
			return (bool) $namen;
		}

		$enthaelt = in_array( $op, array( 'enthaelt', 'enthaelt_nicht' ), true );
		$hit      = false;
		foreach ( $werte as $w ) {
			$nadel = BI_Settings::norm( $w );
			if ( '' === $nadel ) {
				continue;
			}
			foreach ( $namen as $n ) {
				$heu = BI_Settings::norm( $n );
				if ( $enthaelt ? ( false !== strpos( $heu, $nadel ) ) : ( $heu === $nadel ) ) {
					$hit = true;
					break 2;
				}
			}
		}
		return $neg ? ! $hit : $hit;
	}

	/** Trifft eine Regel im Kontext zu? (Aktiv-Status wird hier nicht geprüft.) */
	public static function regel_trifft( $r, $ctx ) {
		if ( $ctx['reihe'] ) {
			$beantwortbar = false;
			foreach ( $r['bedingungen'] as $b ) {
				if ( in_array( $b['feld'], self::REIHEN_FELDER, true ) ) {
					$beantwortbar = true;
					break;
				}
			}
			if ( ! $beantwortbar ) {
				return false;
			}
		}
		foreach ( $r['bedingungen'] as $b ) {
			if ( ! self::bedingung_trifft( $b, $ctx ) ) {
				return false;
			}
		}
		return true;
	}

	/** Aktive Regeln einer Aktion (optional nur für ein Ziel). */
	public static function regeln_fuer( $aktion, $ziel = null ) {
		$out = array();
		foreach ( self::alle() as $r ) {
			if ( empty( $r['aktiv'] ) || $r['aktion'] !== $aktion ) {
				continue;
			}
			if ( null !== $ziel && (string) $r['ziel'] !== (string) $ziel ) {
				continue;
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Anmeldeweg eines Seminars oder einer Reihe.
	 *
	 * @return array{weg:string,frist:bool,ids:int[]} weg '' = keine Regel trifft zu.
	 */
	public static function anmeldeweg( $post_id, $vorab = null ) {
		$post_id = (int) $post_id;
		if ( null === $vorab && isset( self::$memo_weg[ $post_id ] ) ) {
			return self::$memo_weg[ $post_id ];
		}
		$ctx = self::kontext( $post_id, null, $vorab );
		if ( is_array( $vorab ) && isset( $vorab['_post_type'] ) ) {
			$ctx['post_type'] = $vorab['_post_type'];
			$ctx['reihe']     = false;
		}
		$erg = self::weg_aus( self::regeln_fuer( 'anmeldeweg' ), $ctx );

		if ( null === $vorab ) {
			if ( count( self::$memo_weg ) > 500 ) {
				self::$memo_weg = array();
			}
			self::$memo_weg[ $post_id ] = $erg;
		}
		return $erg;
	}

	/** Rangfolge über eine Regelliste anwenden. */
	private static function weg_aus( $regeln, $ctx ) {
		$weg    = '';
		$ids    = array();
		$keine  = array();
		foreach ( $regeln as $r ) {
			if ( ! self::regel_trifft( $r, $ctx ) ) {
				continue;
			}
			$ziel = isset( self::RANG[ $r['ziel'] ] ) ? $r['ziel'] : 'direct';
			$ids[] = (int) $r['id'];
			if ( '' === $weg || self::RANG[ $ziel ] > self::RANG[ $weg ] ) {
				$weg = $ziel;
			}
			if ( 'keine' === $ziel ) {
				$keine[] = $r;
			}
		}
		// Störertext: Fristtexte nur, wenn ausschließlich Fristregeln sperren.
		// Sperrt auch eine Regel aus Prinzip (Haken nein), ist „Anmeldefrist
		// abgelaufen" die falsche Begründung.
		$frist = false;
		if ( 'keine' === $weg ) {
			$frist = true;
			foreach ( $keine as $r ) {
				if ( ! self::ist_fristregel( $r ) ) {
					$frist = false;
					break;
				}
			}
		}
		return array( 'weg' => $weg, 'frist' => $frist, 'ids' => $ids );
	}

	private static function ist_fristregel( $r ) {
		foreach ( $r['bedingungen'] as $b ) {
			if ( 'seminar:frist' === $b['feld'] && 'abgelaufen' === $b['op'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Formular aus den Regeln – '' wenn keine zutrifft.
	 *
	 * @return string Formularschlüssel
	 */
	public static function formular( $post_id, $vorab = null ) {
		$erg = self::formular_detail( $post_id, $vorab );
		return $erg['key'];
	}

	/** @return array{key:string,id:int,gleichstand:int[]} */
	public static function formular_detail( $post_id, $vorab = null ) {
		$alle = class_exists( 'BI_Formulare' ) ? BI_Formulare::alle() : array();
		$ctx  = self::kontext( $post_id, null, $vorab );
		if ( is_array( $vorab ) && isset( $vorab['_post_type'] ) ) {
			$ctx['post_type'] = $vorab['_post_type'];
			$ctx['reihe']     = false;
		}
		// Die genaueste Gruppe (meiste Bedingungen) gewinnt; darin die kleinste
		// ID. Zeigen Regeln dieser Gruppe auf verschiedene Formulare, ist das
		// ein Gleichstand – gemeldet werden dann alle Regeln der Gruppe.
		$gruppe = array();
		$max    = -1;
		foreach ( self::regeln_fuer( 'formular' ) as $r ) {
			if ( ! isset( $alle[ $r['ziel'] ] ) || ! self::regel_trifft( $r, $ctx ) ) {
				continue;
			}
			$n = count( $r['bedingungen'] );
			if ( $n > $max ) {
				$max    = $n;
				$gruppe = array();
			}
			if ( $n === $max ) {
				$gruppe[] = $r;
			}
		}
		$best  = null;
		$ziele = array();
		foreach ( $gruppe as $r ) {
			$ziele[ $r['ziel'] ] = true;
			if ( null === $best || (int) $r['id'] < (int) $best['id'] ) {
				$best = $r;
			}
		}
		$tie = array();
		if ( count( $ziele ) > 1 ) {
			foreach ( $gruppe as $r ) {
				$tie[] = (int) $r['id'];
			}
		}
		return array(
			'key'         => $best ? $best['ziel'] : '',
			'id'          => $best ? (int) $best['id'] : 0,
			'gleichstand' => $tie,
		);
	}

	/** Soll diese Benachrichtigung zu dieser Anmeldung verschickt werden? */
	public static function sendet( $trigger_id, $submission ) {
		$ctx = self::kontext( (int) ( $submission['seminar_id'] ?? 0 ), $submission );
		foreach ( self::regeln_fuer( 'mail', (string) (int) $trigger_id ) as $r ) {
			if ( self::regel_trifft( $r, $ctx ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Anhänge einer Benachrichtigung zu einer Anmeldung.
	 *
	 * @param bool $alle true = alle Anhang-Regeln ohne Prüfung (Soforttest).
	 * @return array Liste von ['anhang' => typ, 'datei' => id], ohne Doppelte.
	 */
	public static function anhaenge( $trigger_id, $submission, $alle = false ) {
		$ctx = self::kontext( (int) ( $submission['seminar_id'] ?? 0 ), $submission );
		$out = array();
		foreach ( self::regeln_fuer( 'anhang', (string) (int) $trigger_id ) as $r ) {
			if ( ! $alle && ! self::regel_trifft( $r, $ctx ) ) {
				continue;
			}
			$key         = $r['anhang'] . ':' . ( 'datei' === $r['anhang'] ? (int) $r['datei'] : 0 );
			$out[ $key ] = array( 'anhang' => $r['anhang'], 'datei' => (int) $r['datei'] );
		}
		return array_values( $out );
	}

	/**
	 * Eine Datei aus der Mediathek als temporäre Kopie für den Versand.
	 *
	 * Eine KOPIE, weil der Versand seine Anhänge danach löscht
	 * (BI_PDF::cleanup) – das Original in der Mediathek muss bleiben.
	 */
	public static function mediathek_datei( $attachment_id ) {
		$pfad = function_exists( 'get_attached_file' ) ? (string) get_attached_file( (int) $attachment_id ) : '';
		if ( '' === $pfad || ! is_readable( $pfad ) || ! class_exists( 'BI_PDF' ) ) {
			error_log( '[BI-Regeln] Anhang aus der Mediathek nicht lesbar (ID ' . (int) $attachment_id . ').' );
			return '';
		}
		$bytes = (string) file_get_contents( $pfad ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return BI_PDF::temp_datei( $bytes, sanitize_file_name( basename( $pfad ) ) );
	}

	/* ===================================================================
	 *  Pflege von außen: Benachrichtigung / Formular angelegt, kopiert, gelöscht
	 * =================================================================== */

	/** Neue Benachrichtigung: „bei jeder Anmeldung" – wie bisher ohne Bedingung. */
	public static function immer_regel( $trigger_id ) {
		return self::put( array(
			'aktiv'       => 1,
			'notiz'       => '',
			'bedingungen' => array(),
			'aktion'      => 'mail',
			'ziel'        => (string) (int) $trigger_id,
		) );
	}

	/** Regeln, die auf ein gelöschtes Ziel zeigen, mitnehmen. Gibt die Anzahl zurück. */
	public static function ziel_entfernt( $art, $ziel ) {
		$aktionen = ( 'mail' === $art ) ? array( 'mail', 'anhang' ) : array( $art );
		$rest     = array();
		$weg      = 0;
		foreach ( self::alle() as $r ) {
			if ( in_array( $r['aktion'], $aktionen, true ) && (string) $r['ziel'] === (string) $ziel ) {
				$weg++;
				continue;
			}
			$rest[] = $r;
		}
		if ( $weg ) {
			self::schreiben( $rest );
		}
		return $weg;
	}

	/** Kopie einer Benachrichtigung bekommt Kopien ihrer Regeln. */
	public static function mail_kopieren( $alt_id, $neu_id ) {
		$alle = self::alle();
		foreach ( self::alle() as $r ) {
			if ( in_array( $r['aktion'], array( 'mail', 'anhang' ), true ) && (string) $r['ziel'] === (string) (int) $alt_id ) {
				$r['id']   = 0;
				$r['ziel'] = (string) (int) $neu_id;
				$alle[]    = $r;
			}
		}
		self::schreiben( $alle );
	}

	/* ===================================================================
	 *  Übernahme der alten Regeln (einmalig je Installation)
	 * =================================================================== */

	/** Altes Regelfeld -> neue Bedingung. null = nicht übertragbar. */
	public static function alt_bedingung( $field, $value ) {
		$field = (string) $field;
		$value = trim( (string) $value );
		if ( '' === $field || '' === $value ) {
			return null;
		}
		$map = array(
			'freistellung'  => 'seminar:freistellung',
			'handlungsfeld' => 'seminar:handlungsfeld',
			'zielgruppe'    => 'seminar:zielgruppe',
			'ort'           => 'seminar:ort',
			'seminarform'   => 'seminar:seminarform',
			'frist'         => 'seminar:frist',
			'flag'          => 'seminar:flag',
			'ausgebucht'    => 'seminar:ausgebucht',
			'anzeigen'      => 'seminar:anzeigen',
		);
		if ( ! isset( $map[ $field ] ) ) {
			return null;
		}
		$feld = $map[ $field ];
		if ( 'seminar:frist' === $feld ) {
			$op = 'abgelaufen';
		} elseif ( array_key_exists( $feld, self::haken_felder() ) ) {
			$op = 'ist';
		} else {
			$op = 'enthaelt'; // die alten Regeln verglichen immer mit „enthält"
		}
		return array( 'feld' => $feld, 'op' => $op, 'werte' => array( $value ) );
	}

	/** Dieselbe Bedingung verneint. */
	public static function verneint( $b ) {
		$map = array(
			'ist'              => 'ist_nicht',
			'ist_nicht'        => 'ist',
			'enthaelt'         => 'enthaelt_nicht',
			'enthaelt_nicht'   => 'enthaelt',
			'abgelaufen'       => 'nicht_abgelaufen',
			'nicht_abgelaufen' => 'abgelaufen',
			'leer'             => 'nicht_leer',
			'nicht_leer'       => 'leer',
		);
		$b['op'] = $map[ $b['op'] ] ?? $b['op'];
		return $b;
	}

	/**
	 * Alte Anmeldewege -> neue Regeln.
	 *
	 * Bisher gewann die erste zutreffende Regel, jetzt der höhere Rang. Beides
	 * weicht nur ab, wo eine SCHWÄCHERE Regel weiter oben eine stärkere schlug:
	 *
	 *   Direkt über Geschäftsstelle  -> die GS-Regel bekommt „und nicht …" der
	 *                                   Direkt-Regel. Verhalten bleibt gleich.
	 *   Direkt/GS über Keine         -> wird NICHT übernommen: Eine Sperre soll
	 *                                   keine Regel aufheben können (so mit der
	 *                                   Redaktion entschieden, Oktober 2026).
	 *                                   Der Bericht nennt die betroffenen Seminare.
	 *
	 * @param array $alt Liste ['field','value','variant'] in alter Reihenfolge.
	 */
	public static function aus_anmeldewegen( $alt ) {
		$liste = array();
		foreach ( (array) $alt as $r ) {
			$b = self::alt_bedingung( $r['field'] ?? '', $r['value'] ?? '' );
			if ( ! $b ) {
				continue;
			}
			$v       = (string) ( $r['variant'] ?? '' );
			$liste[] = array( 'b' => $b, 'v' => isset( self::RANG[ $v ] ) ? $v : 'direct' );
		}
		$out = array();
		foreach ( $liste as $i => $e ) {
			$bed = array( $e['b'] );
			if ( 'gs' === $e['v'] ) {
				for ( $j = 0; $j < $i; $j++ ) {
					if ( 'direct' === $liste[ $j ]['v'] ) {
						$bed[] = self::verneint( $liste[ $j ]['b'] );
					}
				}
			}
			$out[] = array(
				'aktiv'       => 1,
				'notiz'       => sprintf( 'Übernommen aus den Anmelderegeln (bisher Nr. %d).', $i + 1 ),
				'bedingungen' => $bed,
				'aktion'      => 'anmeldeweg',
				'ziel'        => $e['v'],
			);
		}
		return $out;
	}

	/**
	 * Alte Formular-Zuordnung -> neue Regeln. Jede Regel bekommt „und nicht …"
	 * für jede FRÜHERE mit anderem Formular – damit können sich zwei Regeln
	 * nicht mehr überschneiden, und das Ergebnis bleibt dasselbe.
	 */
	public static function aus_formularregeln( $alt, $formulare ) {
		$liste = array();
		foreach ( (array) $alt as $r ) {
			$ziel = (string) ( $r['formular'] ?? '' );
			$b    = self::alt_bedingung( $r['field'] ?? '', $r['value'] ?? '' );
			if ( ! $b || ! isset( $formulare[ $ziel ] ) ) {
				continue; // so hat es die alte Auswertung auch übersprungen
			}
			$liste[] = array( 'b' => $b, 'f' => $ziel );
		}
		$out = array();
		foreach ( $liste as $i => $e ) {
			$bed = array( $e['b'] );
			for ( $j = 0; $j < $i; $j++ ) {
				if ( $liste[ $j ]['f'] !== $e['f'] ) {
					$bed[] = self::verneint( $liste[ $j ]['b'] );
				}
			}
			$out[] = array(
				'aktiv'       => 1,
				'notiz'       => sprintf( 'Übernommen aus der Formular-Zuordnung (bisher Nr. %d).', $i + 1 ),
				'bedingungen' => $bed,
				'aktion'      => 'formular',
				'ziel'        => $e['f'],
			);
		}
		return $out;
	}

	/** Bedingung und Anhänge einer Benachrichtigung -> neue Regeln. */
	public static function aus_trigger( $t ) {
		$id  = (int) ( $t['id'] ?? 0 );
		$out = array();
		if ( ! $id ) {
			return $out;
		}
		$bed   = array();
		$aktiv = 1;
		$notiz = 'Übernommen aus der Bedingung der Benachrichtigung.';

		$form = (string) ( $t['cond_form'] ?? '' );
		if ( in_array( $form, array( BI_CPT, BI_ONLINE ), true ) ) {
			$bed[] = array( 'feld' => 'seminar:seminarform', 'op' => 'ist', 'werte' => array( BI_ONLINE === $form ? 'Online' : 'Präsenz' ) );
		}

		$werte = BI_Mailer::cond_values( $t );
		$quell = (string) ( $t['cond_tax'] ?? '' );
		if ( '' !== $quell && $werte ) {
			$op = ( 'not' === ( $t['cond_op'] ?? 'is' ) ) ? 'ist_nicht' : 'ist';
			if ( BI_Mailer::COND_ANMELDUNG_FREI === $quell ) {
				$bed[] = array( 'feld' => 'anmeldung:freistellung', 'op' => $op, 'werte' => $werte );
			} else {
				$feld = array_search( $quell, self::tax_felder(), true );
				if ( false !== $feld ) {
					$bed[] = array( 'feld' => $feld, 'op' => $op, 'werte' => $werte );
				} else {
					// Lieber still als zu oft: eine Bedingung, die sich nicht
					// übertragen lässt, darf nicht zu „bei jeder Anmeldung" werden.
					$aktiv = 0;
					$notiz = sprintf( 'Bedingung „%s" ließ sich nicht übernehmen – Regel bitte prüfen und dann einschalten.', $quell );
				}
			}
		}
		$out[] = array(
			'aktiv'       => $aktiv,
			'notiz'       => $bed || ! $aktiv ? $notiz : '',
			'bedingungen' => $bed,
			'aktion'      => 'mail',
			'ziel'        => (string) $id,
		);

		foreach ( array( 'attach_seminar' => 'seminar', 'attach_beschluss' => 'beschluss', 'attach_themenplan' => 'themenplan' ) as $k => $typ ) {
			if ( ! empty( $t[ $k ] ) ) {
				$out[] = array(
					'aktiv'       => 1,
					'notiz'       => '',
					'bedingungen' => array(),
					'aktion'      => 'anhang',
					'ziel'        => (string) $id,
					'anhang'      => $typ,
				);
			}
		}
		return $out;
	}

	/** Einmalige Übernahme – läuft, solange VERSION_OPTION nicht gesetzt ist. */
	public static function migrieren() {
		if ( self::aktiv() ) {
			return;
		}
		$alt_wege = BI_Settings::rules();
		$formul   = class_exists( 'BI_Formulare' ) ? BI_Formulare::alle() : array();
		$alt_form = class_exists( 'BI_Formulare' ) ? BI_Formulare::regeln() : array();

		$neu = array_merge(
			self::aus_anmeldewegen( $alt_wege ),
			self::aus_formularregeln( $alt_form, $formul )
		);
		foreach ( BI_Mailer::get_triggers() as $t ) {
			$neu = array_merge( $neu, self::aus_trigger( $t ) );
		}

		// Bestehende neue Regeln (etwa nach einem Zurückspielen und erneuten
		// Update) nicht verdoppeln: Was schon da ist, bleibt; übernommen wird
		// nur in eine leere Liste.
		if ( ! get_option( self::OPTION, array() ) ) {
			self::schreiben( $neu );
		}

		update_option( self::BERICHT_OPTION, array(
			'zeit'     => current_time( 'mysql' ),
			'offen'    => 1,
			'alt_wege' => array_values( (array) $alt_wege ),
			'alt_form' => array_values( (array) $alt_form ),
			'fertig'   => 0,
		), false );
		update_option( self::VERSION_OPTION, self::VERSION );
		self::cache_leeren();
	}

	/* ===================================================================
	 *  Auswertung über alle Seminare: Trefferzahlen und Übernahmebericht
	 * =================================================================== */

	/**
	 * Seminarwerte aller Seminare in wenigen Abfragen – statt je Seminar und
	 * Regel eine. Ergebnis: post_id => [feld => Wert wie feldwert()].
	 */
	private static function vorab_laden( $limit = 4000 ) {
		global $wpdb;
		$ids = get_posts( array(
			'post_type'        => bi_seminar_post_types(),
			'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page'   => $limit + 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		) );
		$gekappt = count( $ids ) > $limit;
		$ids     = array_slice( array_map( 'intval', $ids ), 0, $limit );
		$vorab   = array();
		if ( ! $ids ) {
			return array( array(), false );
		}
		$in = implode( ',', $ids ); // nur Ganzzahlen

		foreach ( $ids as $id ) {
			$vorab[ $id ] = array();
			foreach ( array_keys( self::tax_felder() ) as $f ) {
				$vorab[ $id ][ $f ] = array();
			}
			foreach ( array_keys( self::haken_felder() ) as $f ) {
				$vorab[ $id ][ $f ] = '';
			}
			$vorab[ $id ]['seminar:frist'] = '';
		}

		$rows = $wpdb->get_results( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$vorab[ (int) $row->ID ]['_post_type']          = (string) $row->post_type;
			$vorab[ (int) $row->ID ]['seminar:seminarform'] = BI_Settings::form_names( (string) $row->post_type );
		}

		foreach ( self::tax_felder() as $feld => $tax ) {
			$objs = wp_get_object_terms( $ids, $tax, array( 'fields' => 'all_with_object_id' ) );
			if ( is_wp_error( $objs ) ) {
				continue;
			}
			foreach ( $objs as $t ) {
				$vorab[ (int) $t->object_id ][ $feld ][] = $t->name;
			}
		}

		$keys = array_flip( self::haken_felder() );
		$keys['_bi_startdatum'] = 'seminar:frist';
		$liste = "'" . implode( "','", array_map( 'esc_sql', array_keys( $keys ) ) ) . "'";
		$rows  = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($liste) AND post_id IN ($in)" ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $row ) {
			$vorab[ (int) $row->post_id ][ $keys[ $row->meta_key ] ] = (string) $row->meta_value;
		}
		return array( $vorab, $gekappt );
	}

	/** Nur Seminarfelder? (Dann lässt sich die Regel an Seminaren zählen.) */
	private static function nur_seminar( $r ) {
		foreach ( $r['bedingungen'] as $b ) {
			if ( 0 !== strpos( $b['feld'], 'seminar:' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Trefferzahlen der Seminar-Regeln.
	 *
	 * @return array id => ['trifft'=>int,'anders'=>int,'gleichstand'=>int] plus 'meta'
	 */
	public static function statistik() {
		list( $vorab, $gekappt ) = self::vorab_laden();
		$stats = array( 'meta' => array( 'seminare' => count( $vorab ), 'gekappt' => $gekappt ) );
		$wege  = self::regeln_fuer( 'anmeldeweg' );
		$forms = self::regeln_fuer( 'formular' );
		foreach ( array_merge( $wege, $forms ) as $r ) {
			$stats[ $r['id'] ] = array( 'trifft' => 0, 'anders' => 0, 'gleichstand' => 0 );
		}
		foreach ( $vorab as $pid => $v ) {
			$ctx = array( 'post_id' => $pid, 'post_type' => $v['_post_type'] ?? '', 'reihe' => false, 'submission' => null, 'vorab' => $v );
			$weg = $wege ? self::weg_aus( $wege, $ctx ) : null;
			foreach ( $wege as $r ) {
				if ( self::regel_trifft( $r, $ctx ) ) {
					$stats[ $r['id'] ]['trifft']++;
					if ( $weg['weg'] !== $r['ziel'] ) {
						$stats[ $r['id'] ]['anders']++;
					}
				}
			}
			if ( $forms ) {
				$f = self::formular_detail( $pid, $v );
				foreach ( $forms as $r ) {
					if ( self::regel_trifft( $r, $ctx ) ) {
						$stats[ $r['id'] ]['trifft']++;
						if ( $f['key'] !== $r['ziel'] ) {
							$stats[ $r['id'] ]['anders']++;
						}
						if ( in_array( (int) $r['id'], $f['gleichstand'], true ) ) {
							$stats[ $r['id'] ]['gleichstand']++;
						}
					}
				}
			}
		}
		return $stats;
	}

	/**
	 * Übernahmebericht: Wo entscheidet das neue Regelwerk anders als das alte?
	 *
	 * Gerechnet einmal beim ersten Öffnen der Regelseite (dann sind die
	 * Taxonomien registriert) und gespeichert. Das alte Ergebnis wird aus
	 * denselben vorab geladenen Daten berechnet – mit der alten Logik „erste
	 * zutreffende gewinnt" über die alten Einzelbedingungen.
	 */
	public static function bericht() {
		$b = get_option( self::BERICHT_OPTION, array() );
		if ( ! is_array( $b ) || empty( $b['offen'] ) ) {
			return null;
		}
		if ( ! empty( $b['fertig'] ) ) {
			return $b;
		}

		list( $vorab ) = self::vorab_laden();
		$alt_wege = array();
		foreach ( (array) ( $b['alt_wege'] ?? array() ) as $r ) {
			$c = self::alt_bedingung( $r['field'] ?? '', $r['value'] ?? '' );
			if ( $c ) {
				$v          = (string) ( $r['variant'] ?? '' );
				$alt_wege[] = array( 'b' => $c, 'v' => isset( self::RANG[ $v ] ) ? $v : 'direct' );
			}
		}
		$formulare = class_exists( 'BI_Formulare' ) ? BI_Formulare::alle() : array();
		$alt_form  = array();
		foreach ( (array) ( $b['alt_form'] ?? array() ) as $r ) {
			$c = self::alt_bedingung( $r['field'] ?? '', $r['value'] ?? '' );
			if ( $c && isset( $formulare[ (string) ( $r['formular'] ?? '' ) ] ) ) {
				$alt_form[] = array( 'b' => $c, 'f' => (string) $r['formular'] );
			}
		}

		$aenderungen = array();
		foreach ( $vorab as $pid => $v ) {
			$ctx = array( 'post_id' => $pid, 'post_type' => $v['_post_type'] ?? '', 'reihe' => false, 'submission' => null, 'vorab' => $v );

			$alt = 'direct';
			foreach ( $alt_wege as $e ) {
				if ( self::bedingung_trifft( $e['b'], $ctx ) ) {
					$alt = $e['v'];
					break;
				}
			}
			$neu = self::weg_aus( self::regeln_fuer( 'anmeldeweg' ), $ctx );
			$neu = '' === $neu['weg'] ? 'direct' : $neu['weg'];
			if ( $alt !== $neu ) {
				$aenderungen[] = array( 'id' => $pid, 'art' => 'weg', 'alt' => $alt, 'neu' => $neu );
			}

			if ( $alt_form ) {
				$af = '';
				foreach ( $alt_form as $e ) {
					if ( self::bedingung_trifft( $e['b'], $ctx ) ) {
						$af = $e['f'];
						break;
					}
				}
				$nf = self::formular( $pid, $v );
				if ( $af !== $nf ) {
					$aenderungen[] = array( 'id' => $pid, 'art' => 'formular', 'alt' => $af, 'neu' => $nf );
				}
			}
		}

		$b['aenderungen'] = array_slice( $aenderungen, 0, 300 );
		$b['anzahl']      = count( $aenderungen );
		$b['seminare']    = count( $vorab );
		$b['fertig']      = 1;
		unset( $b['alt_wege'], $b['alt_form'] );
		update_option( self::BERICHT_OPTION, $b, false );
		return $b;
	}

	/* ===================================================================
	 *  Darstellung als Satz
	 * =================================================================== */

	public static function ziel_labels() {
		return array(
			'keine'  => 'Keine Anmeldung möglich',
			'gs'     => 'Anmeldung über die Geschäftsstelle',
			'direct' => 'Direktanmeldung',
		);
	}

	public static function anhang_labels() {
		return array(
			'seminar'    => 'Seminardetails (PDF)',
			'beschluss'  => 'Beschlussvorlage (Word)',
			'themenplan' => 'Themenplan',
			'datei'      => 'Datei aus der Mediathek',
		);
	}

	/** „Bildungszentrum ist nicht „Sprockhövel"" */
	public static function bedingung_text( $b ) {
		$def   = self::feld( $b['feld'] );
		$ops   = self::op_labels();
		$label = $def ? $def['label'] : $b['feld'] . ' (Feld gibt es nicht mehr!)';
		if ( $def && 'anmeldung' === $def['quelle'] ) {
			$label = 'Anmeldung: ' . $label;
		}
		if ( 'seminar:frist' === $b['feld'] ) {
			$tage = $b['werte'][0] ?? '?';
			return sprintf( 'Anmeldefrist (%s Tage vor Beginn) %s', $tage, $ops[ $b['op'] ] ?? $b['op'] );
		}
		$text = $label . ' ' . ( $ops[ $b['op'] ] ?? $b['op'] );
		if ( self::braucht_werte( $b['op'] ) ) {
			$q = array();
			foreach ( $b['werte'] as $w ) {
				$q[] = '„' . $w . '“';
			}
			$text .= ' ' . implode( ' oder ', $q );
		}
		return $text;
	}

	public static function bedingungen_text( $r ) {
		if ( ! $r['bedingungen'] ) {
			return in_array( $r['aktion'], array( 'mail', 'anhang' ), true ) ? 'bei jeder Anmeldung' : 'für jedes Seminar';
		}
		$teile = array();
		foreach ( $r['bedingungen'] as $b ) {
			$teile[] = self::bedingung_text( $b );
		}
		return 'wenn ' . implode( ' und ', $teile );
	}

	public static function aktion_text( $r ) {
		switch ( $r['aktion'] ) {
			case 'anmeldeweg':
				return self::ziel_labels()[ $r['ziel'] ] ?? $r['ziel'];
			case 'formular':
				$ch = class_exists( 'BI_Formulare' ) ? BI_Formulare::choices() : array();
				return 'Formular „' . ( $ch[ $r['ziel'] ] ?? $r['ziel'] . ' (gelöscht)' ) . '“';
			case 'mail':
				return 'Benachrichtigung „' . self::trigger_name( $r['ziel'] ) . '“ verschicken';
			case 'anhang':
				$was = self::anhang_labels()[ $r['anhang'] ] ?? $r['anhang'];
				if ( 'datei' === $r['anhang'] ) {
					$titel = $r['datei'] && function_exists( 'get_the_title' ) ? get_the_title( $r['datei'] ) : '';
					$was   = '' !== (string) $titel ? $titel : 'Datei fehlt!';
				}
				return '„' . $was . '“ an „' . self::trigger_name( $r['ziel'] ) . '“ anhängen';
		}
		return $r['aktion'];
	}

	private static function trigger_name( $id ) {
		$t = BI_Mailer::get_trigger( (int) $id );
		if ( ! $t ) {
			return 'gelöschte Benachrichtigung';
		}
		return '' !== (string) ( $t['name'] ?? '' ) ? $t['name'] : 'ohne Bezeichnung';
	}

	/**
	 * Kompakte Liste der Regeln einer Benachrichtigung – für deren
	 * Bearbeiten-Maske (Wann geht sie raus? Was hängt dran?).
	 */
	public static function kurzliste_html( $aktion, $trigger_id ) {
		$html = '';
		foreach ( self::nach_ziel( $aktion, (string) (int) $trigger_id ) as $r ) {
			$text  = ucfirst( 'anhang' === $aktion ? self::aktion_text( $r ) . ' – ' . self::bedingungen_text( $r ) : self::bedingungen_text( $r ) );
			$html .= '<li>' . esc_html( $text )
				. ( $r['aktiv'] ? '' : ' <span class="bi-badge bi-badge--off">ausgeschaltet</span>' )
				. ' · <a href="' . esc_url( self::page_url( array( 'view' => 'edit', 'id' => $r['id'] ) ) ) . '">bearbeiten</a></li>';
		}
		return $html ? '<ul style="margin:0 0 8px;list-style:disc;padding-left:20px">' . $html . '</ul>' : '';
	}

	/** Adresse „neue Regel" für eine Benachrichtigung. */
	public static function neu_fuer_mail( $aktion, $trigger_id ) {
		return self::neu_url( $aktion, (string) (int) $trigger_id );
	}

	/** Kurzfassung für die Listentabelle der Benachrichtigungen. */
	public static function mail_kurz( $trigger_id ) {
		$teile = array();
		foreach ( self::regeln_fuer( 'mail', (string) (int) $trigger_id ) as $r ) {
			$teile[] = self::bedingungen_text( $r );
		}
		return $teile ? implode( ' – oder – ', $teile ) : '';
	}

	/* ===================================================================
	 *  Admin
	 * =================================================================== */

	public static function menu() {
		add_submenu_page( 'bi-seminarsuche', 'Regeln', 'Regeln', BI_CAP, self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::PAGE !== $page ) {
			return;
		}
		wp_enqueue_style( 'bi-regeln', BI_URL . 'assets/css/regeln.css', array(), BI_VERSION );
		wp_enqueue_script( 'bi-regeln', BI_URL . 'assets/js/regeln.js', array(), BI_VERSION, true );
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $view && function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
	}

	public static function render_page() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap bi-rg">';
		if ( 'edit' === $view ) {
			self::render_edit();
		} elseif ( 'probe' === $view ) {
			self::render_probe();
		} else {
			self::render_liste();
		}
		echo '</div>';
	}

	private static function notices() {
		$msg  = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$warn = isset( $_GET['bi_warn'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_warn'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( '' !== $warn ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $warn ) . '</p></div>';
		}
	}

	private static function aktion_url( $do, $id ) {
		return wp_nonce_url(
			add_query_arg( array( 'action' => 'bi_regel_aktion', 'do' => $do, 'id' => (int) $id ), admin_url( 'admin-post.php' ) ),
			'bi_regel_' . $do . '_' . (int) $id
		);
	}

	private static function neu_url( $aktion, $ziel = '', $extra = array() ) {
		return self::page_url( array_merge( array( 'view' => 'edit', 'aktion' => $aktion, 'ziel' => $ziel ), $extra ) );
	}

	/** Eine Regel als Zeile der Übersicht. */
	private static function regel_zeile( $r, $stats = null, $mit_aktion = false ) {
		$klasse = 'bi-rg-regel' . ( $r['aktiv'] ? '' : ' is-off' );
		$hinweis = '';
		if ( $stats && isset( $stats[ $r['id'] ] ) && self::nur_seminar( $r ) ) {
			$s = $stats[ $r['id'] ];
			if ( 0 === $s['trifft'] ) {
				$hinweis = '<span class="bi-rg-rot">Trifft auf kein Seminar zu – Werte prüfen.</span>';
			} else {
				$hinweis = sprintf( 'Trifft auf %s Seminare zu.', number_format_i18n( $s['trifft'] ) );
				if ( $s['anders'] && 'anmeldeweg' === $r['aktion'] ) {
					$hinweis .= sprintf( ' Bei %s davon gilt ein stärkerer Weg (siehe Rangfolge).', number_format_i18n( $s['anders'] ) );
				}
				if ( $s['gleichstand'] ) {
					$hinweis .= sprintf( ' <span class="bi-rg-gelb">Bei %s Seminaren trifft eine gleich genaue Regel mit anderem Formular zu – eine der beiden genauer fassen.</span>', number_format_i18n( $s['gleichstand'] ) );
				}
			}
		}
		$unbekannt = self::unbekannte_werte( $r );
		if ( $unbekannt ) {
			$hinweis .= ' <span class="bi-rg-gelb">Kein solcher Wert: „' . esc_html( implode( '“, „', $unbekannt ) ) . '“.</span>';
		}
		ob_start();
		?>
		<li class="<?php echo esc_attr( $klasse ); ?>">
			<div class="bi-rg-satz">
				<?php echo esc_html( ucfirst( self::bedingungen_text( $r ) ) ); ?>
				<?php if ( $mit_aktion ) : ?>
					<span class="bi-rg-pfeil">→</span> <strong><?php echo esc_html( self::aktion_text( $r ) ); ?></strong>
				<?php endif; ?>
				<?php if ( ! $r['aktiv'] ) : ?><span class="bi-rg-badge">ausgeschaltet</span><?php endif; ?>
			</div>
			<?php if ( '' !== $hinweis ) : ?><div class="bi-rg-info"><?php echo wp_kses_post( $hinweis ); ?></div><?php endif; ?>
			<?php if ( '' !== $r['notiz'] ) : ?><div class="bi-rg-notiz"><?php echo esc_html( $r['notiz'] ); ?></div><?php endif; ?>
			<div class="bi-rg-links">
				<a href="<?php echo esc_url( self::page_url( array( 'view' => 'edit', 'id' => $r['id'] ) ) ); ?>">Bearbeiten</a> ·
				<a href="<?php echo esc_url( self::aktion_url( $r['aktiv'] ? 'aus' : 'an', $r['id'] ) ); ?>"><?php echo $r['aktiv'] ? 'Ausschalten' : 'Einschalten'; ?></a> ·
				<a href="<?php echo esc_url( self::aktion_url( 'loeschen', $r['id'] ) ); ?>" class="bi-rg-loeschen" onclick="return confirm('Diese Regel löschen?');">Löschen</a>
			</div>
		</li>
		<?php
		return ob_get_clean();
	}

	/** Werte in ist/ist-nicht-Bedingungen, die es als Begriff/Option nicht gibt. */
	public static function unbekannte_werte( $r ) {
		$bad = array();
		foreach ( $r['bedingungen'] as $b ) {
			if ( ! in_array( $b['op'], array( 'ist', 'ist_nicht' ), true ) ) {
				continue;
			}
			$def = self::feld( $b['feld'] );
			if ( ! $def || in_array( $def['art'], array( 'haken', 'frist' ), true ) ) {
				continue;
			}
			$opts = self::optionen( $b['feld'] );
			if ( ! $opts ) {
				continue; // freies Feld – jeder Wert ist denkbar
			}
			$known = array();
			foreach ( $opts as $o ) {
				$known[ BI_Settings::norm( $o[0] ) ] = true;
				$known[ BI_Settings::norm( $o[1] ) ] = true;
			}
			foreach ( $b['werte'] as $w ) {
				if ( ! isset( $known[ BI_Settings::norm( $w ) ] ) ) {
					$bad[] = $w;
				}
			}
		}
		return $bad;
	}

	private static function liste_box( $titel, $regeln, $neu_url, $stats, $beschreibung = '', $mit_aktion = false, $neu_text = '+ Regel' ) {
		?>
		<div class="bi-rg-box">
			<div class="bi-rg-kopf">
				<h3><?php echo esc_html( $titel ); ?></h3>
				<a class="button button-small" href="<?php echo esc_url( $neu_url ); ?>"><?php echo esc_html( $neu_text ); ?></a>
			</div>
			<?php if ( '' !== $beschreibung ) : ?><p class="bi-rg-beschr"><?php echo wp_kses_post( $beschreibung ); ?></p><?php endif; ?>
			<?php if ( $regeln ) : ?>
				<ul class="bi-rg-liste">
					<?php foreach ( $regeln as $r ) { echo self::regel_zeile( $r, $stats, $mit_aktion ); } // phpcs:ignore – intern escaped ?>
				</ul>
			<?php else : ?>
				<p class="bi-rg-leer">Keine Regel.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function nach_ziel( $aktion, $ziel ) {
		$out = array();
		foreach ( self::alle() as $r ) {
			if ( $r['aktion'] === $aktion && (string) $r['ziel'] === (string) $ziel ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	private static function render_liste() {
		$stats     = self::statistik();
		$formulare = class_exists( 'BI_Formulare' ) ? BI_Formulare::choices() : array();
		$standard  = class_exists( 'BI_Formulare' ) ? BI_Formulare::standard_key() : '';
		$triggers  = BI_Mailer::get_triggers();
		$types     = BI_Mailer::type_labels();
		?>
		<h1 class="wp-heading-inline">Regeln</h1>
		<a href="<?php echo esc_url( self::page_url( array( 'view' => 'probe' ) ) ); ?>" class="page-title-action">Regel-Probe an einem Seminar</a>
		<hr class="wp-header-end">
		<?php self::notices(); ?>
		<?php self::render_bericht(); ?>

		<p class="bi-rg-intro">Jede Regel lautet <strong>„Wenn … dann …"</strong>. Hat eine Regel mehrere Bedingungen,
		   müssen <strong>alle</strong> zutreffen; mehrere Werte in einer Bedingung bedeuten <strong>oder</strong>.
		   <strong>Die Reihenfolge spielt keine Rolle</strong> – was bei mehreren zutreffenden Regeln gilt, steht
		   jeweils über dem Abschnitt.</p>

		<nav class="bi-rg-nav">
			<a href="#bi-rg-weg">Anmeldeweg</a>
			<a href="#bi-rg-formular">Anmeldeformular</a>
			<a href="#bi-rg-mail">Benachrichtigungen &amp; Anhänge</a>
		</nav>

		<h2 id="bi-rg-weg">1. Anmeldeweg</h2>
		<p>Treffen mehrere Regeln zu, gilt die strengere: <strong>Keine Anmeldung</strong> vor
		   <strong>Geschäftsstelle</strong> vor <strong>Direktanmeldung</strong>. Trifft keine zu, gilt die
		   Direktanmeldung. Eine Sperre kann also keine andere Regel aufheben.</p>
		<p class="description">Ausbildungsreihen: Für eine Reihe gilt eine Regel nur, wenn sie eine Bedingung auf
		   <em>Freistellung</em> oder <em>Anzeigen</em> hat – mehr Felder hat eine Reihe nicht. Trifft keine Regel
		   zu, entscheiden die Freistellungen ihrer Termine
		   (<a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-einstellungen' ) ); ?>">Einstellungen → Anmeldung</a>).
		   Für Reihen ist deshalb auch eine Regel <em>„… → Direktanmeldung"</em> eine echte Aussage.</p>
		<div class="bi-rg-spalten">
			<?php
			$wege = array(
				'keine'  => 'Hier ist keine Anmeldung möglich, wenn …',
				'gs'     => 'Anmeldung über die Geschäftsstelle, wenn …',
				'direct' => 'Direktanmeldung, wenn …',
			);
			foreach ( $wege as $ziel => $titel ) {
				$beschr = '';
				if ( 'direct' === $ziel ) {
					$beschr = 'Für Seminare der Normalfall – hier nur nötig für Ausbildungsreihen.';
				}
				self::liste_box( $titel, self::nach_ziel( 'anmeldeweg', $ziel ), self::neu_url( 'anmeldeweg', $ziel ), $stats, $beschr );
			}
			?>
		</div>
		<?php
		$hat_flag = false;
		foreach ( self::regeln_fuer( 'anmeldeweg', 'keine' ) as $r ) {
			foreach ( $r['bedingungen'] as $b ) {
				if ( 'seminar:flag' === $b['feld'] ) {
					$hat_flag = true;
				}
			}
		}
		if ( ! $hat_flag ) :
			?>
			<div class="notice notice-warning inline"><p>Keine Regel bezieht sich auf den Haken <em>„Anmeldung möglich"</em>.
				Seminare, an denen er nicht gesetzt ist, sind damit <strong>direkt buchbar</strong>. Ist das nicht gewollt:
				<a href="<?php echo esc_url( self::neu_url( 'anmeldeweg', 'keine', array( 'vorlage' => 'flag' ) ) ); ?>">Regel „Haken Anmeldung möglich ist nein → Keine Anmeldung" anlegen</a>.</p></div>
		<?php endif; ?>
		<p class="description">Texte für Knöpfe und Störer stehen unter
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-einstellungen' ) ); ?>">Einstellungen → Anmeldung</a>.
			Sperrt ausschließlich eine Fristregel, erscheinen dort die Fristtexte („Anmeldefrist abgelaufen").</p>

		<h2 id="bi-rg-formular">2. Anmeldeformular</h2>
		<p>Welches Formular ein Seminar bekommt: zuerst das Feld <em>„Anmeldeformular"</em> am Seminar (die Ausnahme
		   von Hand), sonst die Regeln, sonst das Standardformular. Treffen mehrere Regeln zu, gewinnt die
		   <strong>genauere</strong> – die mit mehr Bedingungen.</p>
		<div class="bi-rg-spalten">
			<?php
			foreach ( $formulare as $key => $name ) {
				$titel = 'Formular „' . $name . '“' . ( $key === $standard ? ' (Standard)' : '' ) . ', wenn …';
				self::liste_box( $titel, self::nach_ziel( 'formular', $key ), self::neu_url( 'formular', $key ), $stats );
			}
			?>
		</div>

		<h2 id="bi-rg-mail">3. Benachrichtigungen &amp; Anhänge</h2>
		<p>Eine Benachrichtigung geht raus, wenn sie <strong>aktiv</strong> ist und <strong>mindestens eine</strong>
		   ihrer Regeln zutrifft. Anhänge kommen dazu, wenn ihre Regel zutrifft; derselbe Anhang kommt nur einmal mit.
		   Hier dürfen Bedingungen auch Felder der <strong>Anmeldung</strong> lesen (z. B. Mitglied ja/nein).
		   Text, Empfänger und Versandart stehen unter
		   <a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-mail-trigger' ) ); ?>">Benachrichtigungen</a>.</p>
		<?php self::mail_hinweise( $triggers ); ?>
		<div class="bi-rg-mails">
			<?php foreach ( $triggers as $t ) : ?>
				<?php
				$tid   = (int) $t['id'];
				$name  = '' !== (string) ( $t['name'] ?? '' ) ? $t['name'] : 'ohne Bezeichnung';
				$an    = $types[ $t['type'] ?? '' ] ?? '';
				$weekly = 'weekly' === BI_Mailer::schedule_of( $t );
				?>
				<div class="bi-rg-mail<?php echo empty( $t['active'] ) ? ' is-off' : ''; ?>" id="bi-rg-mail-<?php echo $tid; ?>">
					<div class="bi-rg-mailkopf">
						<h3><?php echo esc_html( $name ); ?>
							<?php if ( empty( $t['active'] ) ) : ?><span class="bi-rg-badge">inaktiv</span><?php endif; ?>
							<?php if ( $weekly ) : ?><span class="bi-rg-badge">wöchentlich</span><?php endif; ?>
						</h3>
						<span class="bi-rg-muted">an: <?php echo esc_html( $an ); ?> ·
							<a href="<?php echo esc_url( BI_Mailer::page_url( array( 'action' => 'edit', 'id' => $tid ) ) ); ?>">Text bearbeiten</a></span>
					</div>
					<div class="bi-rg-spalten">
						<?php
						self::liste_box( 'Wird verschickt …', self::nach_ziel( 'mail', $tid ), self::neu_url( 'mail', $tid ), $stats, '', false, '+ Bedingung' );
						$anh_beschr = $weekly ? 'Bei der wöchentlichen Zusammenfassung gehen keine Anhänge mit.' : '';
						self::liste_box( 'Anhänge', self::nach_ziel( 'anhang', $tid ), self::neu_url( 'anhang', $tid ), $stats, $anh_beschr, true, '+ Anhang' );
						?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( ! empty( $stats['meta']['seminare'] ) ) : ?>
			<p class="description">Trefferzahlen gemessen an <?php echo esc_html( number_format_i18n( $stats['meta']['seminare'] ) ); ?>
				Seminaren<?php echo ! empty( $stats['meta']['gekappt'] ) ? ' (mehr wurden nicht geprüft)' : ''; ?> – ein Stand von jetzt.
				Regeln mit Feldern der Anmeldung lassen sich nicht vorab zählen; dafür gibt es die
				<a href="<?php echo esc_url( self::page_url( array( 'view' => 'probe' ) ) ); ?>">Regel-Probe</a>.</p>
		<?php endif; ?>
		<?php
	}

	/** Hinweise zu den Benachrichtigungen: nie verschickt, Mehrfachversand. */
	private static function mail_hinweise( $triggers ) {
		$hinweise = array();
		$immer    = array(); // Empfänger => [Namen]
		foreach ( $triggers as $t ) {
			if ( empty( $t['active'] ) ) {
				continue;
			}
			$name   = '„' . ( '' !== (string) ( $t['name'] ?? '' ) ? $t['name'] : 'ohne Bezeichnung' ) . '“';
			$regeln = self::regeln_fuer( 'mail', (string) (int) $t['id'] );
			if ( ! $regeln ) {
				$hinweise[] = $name . ' ist aktiv, hat aber keine eingeschaltete Regel – sie wird nie verschickt.';
				continue;
			}
			foreach ( $regeln as $r ) {
				if ( ! $r['bedingungen'] ) {
					$key = ( $t['type'] ?? 'custom' ) . ( 'custom' === ( $t['type'] ?? '' ) ? ':' . strtolower( trim( (string) ( $t['recipient'] ?? '' ) ) ) : '' );
					$immer[ $key ][] = $name;
					break;
				}
			}
		}
		$types = BI_Mailer::type_labels();
		foreach ( $immer as $key => $namen ) {
			if ( count( $namen ) > 1 ) {
				$typ        = strtok( $key, ':' );
				$hinweise[] = sprintf( 'Mehrfachversand an %s: %s gehen bei jeder Anmeldung raus.', $types[ $typ ] ?? $typ, implode( ', ', $namen ) );
			}
		}
		if ( $hinweise ) {
			echo '<div class="notice notice-warning inline"><ul style="list-style:disc;padding-left:20px">';
			foreach ( $hinweise as $h ) {
				echo '<li>' . esc_html( $h ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	private static function render_bericht() {
		$b = self::bericht();
		if ( ! $b ) {
			return;
		}
		$ziel = self::ziel_labels();
		$form = class_exists( 'BI_Formulare' ) ? BI_Formulare::choices() : array();
		?>
		<div class="notice notice-info bi-rg-bericht">
			<h2>Die Regeln wurden ins neue Regelwerk übernommen</h2>
			<p>Seit Version 1.147.0 stehen Anmeldewege, Formular-Zuordnung, die Bedingungen der Benachrichtigungen
			   und die Anhänge hier auf einer Seite. Die Reihenfolge spielt keine Rolle mehr; wo eine Regel bisher nur
			   durch ihre Position eine andere übertroffen hat, steht das jetzt als <em>„und … ist nicht …"</em> in ihr.</p>
			<?php if ( empty( $b['anzahl'] ) ) : ?>
				<p><strong>Geprüft an <?php echo esc_html( number_format_i18n( (int) $b['seminare'] ) ); ?> Seminaren: Kein Seminar
				   bekommt einen anderen Anmeldeweg oder ein anderes Formular als vorher.</strong></p>
			<?php else : ?>
				<p><strong>Bei <?php echo esc_html( number_format_i18n( (int) $b['anzahl'] ) ); ?> von
				   <?php echo esc_html( number_format_i18n( (int) $b['seminare'] ) ); ?> Seminaren entscheidet das neue Regelwerk anders.</strong>
				   Erwartet ist das nur dort, wo bisher eine Regel weiter oben eine Sperre aufgehoben hat – jetzt gilt die Sperre.</p>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>Seminar</th><th>Was</th><th>Bisher</th><th>Jetzt</th></tr></thead>
					<tbody>
					<?php foreach ( (array) $b['aenderungen'] as $a ) : ?>
						<?php
						$nr = (string) get_post_meta( (int) $a['id'], '_bi_seminarnummer', true );
						if ( 'weg' === $a['art'] ) {
							$alt = $ziel[ $a['alt'] ] ?? $a['alt'];
							$neu = $ziel[ $a['neu'] ] ?? $a['neu'];
						} else {
							$alt = '' === $a['alt'] ? 'Standardformular' : ( $form[ $a['alt'] ] ?? $a['alt'] );
							$neu = '' === $a['neu'] ? 'Standardformular' : ( $form[ $a['neu'] ] ?? $a['neu'] );
						}
						?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( (int) $a['id'] ) ); ?>"><?php echo esc_html( ( '' !== $nr ? $nr . ' – ' : '' ) . get_the_title( (int) $a['id'] ) ); ?></a></td>
							<td><?php echo 'weg' === $a['art'] ? 'Anmeldeweg' : 'Formular'; ?></td>
							<td><?php echo esc_html( $alt ); ?></td>
							<td><strong><?php echo esc_html( $neu ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( (int) $b['anzahl'] > count( (array) $b['aenderungen'] ) ) : ?>
					<p>… und weitere. Die Regel-Probe zeigt jedes Seminar einzeln.</p>
				<?php endif; ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:10px 0">
				<input type="hidden" name="action" value="bi_regeln_bericht_ok">
				<?php wp_nonce_field( 'bi_regeln_bericht_ok' ); ?>
				<button class="button">Gelesen – Hinweis ausblenden</button>
			</form>
		</div>
		<?php
	}

	/** Daten für die Bearbeiten-Maske (regeln.js). */
	private static function js_daten( $regel ) {
		$felder = array();
		foreach ( self::felder() as $k => $def ) {
			$felder[ $k ] = array(
				'label'    => $def['label'],
				'quelle'   => $def['quelle'],
				'art'      => $def['art'],
				'ops'      => self::ops_fuer( $def['art'] ),
				'optionen' => self::optionen( $k ),
			);
		}
		return array(
			'felder'      => $felder,
			'ops'         => self::op_labels(),
			'ohneWerte'   => array( 'leer', 'nicht_leer' ),
			'bedingungen' => $regel['bedingungen'],
		);
	}

	private static function render_edit() {
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$regel = $id ? self::get( $id ) : null;
		if ( $id && ! $regel ) {
			echo '<h1>Regel</h1><div class="notice notice-error"><p>Diese Regel gibt es nicht (mehr).</p></div>';
			echo '<p><a href="' . esc_url( self::page_url() ) . '">&larr; Zurück zu den Regeln</a></p>';
			return;
		}
		if ( ! $regel ) {
			$aktion = isset( $_GET['aktion'] ) ? sanitize_key( wp_unslash( $_GET['aktion'] ) ) : 'anmeldeweg'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ziel   = isset( $_GET['ziel'] ) ? sanitize_text_field( wp_unslash( $_GET['ziel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$regel  = self::normalisieren( array(
				'id'          => -1,
				'aktiv'       => 1,
				'aktion'      => $aktion,
				'ziel'        => $ziel,
				'anhang'      => 'anhang' === $aktion ? 'datei' : '',
				'bedingungen' => array(),
			) );
			$vorlage = isset( $_GET['vorlage'] ) ? sanitize_key( wp_unslash( $_GET['vorlage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'flag' === $vorlage ) {
				$regel['bedingungen'] = array( array( 'feld' => 'seminar:flag', 'op' => 'ist', 'werte' => array( 'nein' ) ) );
			}
			$regel['id'] = 0;
		}

		$formulare = class_exists( 'BI_Formulare' ) ? BI_Formulare::choices() : array();
		$triggers  = BI_Mailer::get_triggers();
		$datei_t   = $regel['datei'] ? get_the_title( $regel['datei'] ) : '';
		$zurueck   = self::page_url() . ( in_array( $regel['aktion'], array( 'mail', 'anhang' ), true ) ? '#bi-rg-mail-' . (int) $regel['ziel'] : '#bi-rg-' . ( 'formular' === $regel['aktion'] ? 'formular' : 'weg' ) );
		?>
		<h1 class="wp-heading-inline"><?php echo $regel['id'] ? 'Regel bearbeiten' : 'Neue Regel'; ?></h1>
		<a href="<?php echo esc_url( $zurueck ); ?>" class="page-title-action">Zurück zu den Regeln</a>
		<hr class="wp-header-end">
		<?php self::notices(); ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bi-rg-form" id="bi-rg-form">
			<input type="hidden" name="action" value="bi_regel_save">
			<input type="hidden" name="id" value="<?php echo (int) $regel['id']; ?>">
			<?php wp_nonce_field( 'bi_regel_save_' . (int) $regel['id'] ); ?>
			<script type="application/json" id="bi-rg-daten"><?php echo wp_json_encode( self::js_daten( $regel ) ); ?></script>

			<div class="bi-rg-schritt">
				<h2><span class="bi-rg-nr">1</span> Wenn …</h2>
				<div id="bi-rg-bedingungen" class="bi-rg-bedingungen"></div>
				<p><button type="button" class="button" id="bi-rg-plus">+ Bedingung</button>
					<span class="bi-rg-muted" id="bi-rg-immer">Ohne Bedingung gilt die Regel immer.</span></p>
				<p class="description">Alle Bedingungen müssen zutreffen (<strong>und</strong>). Mehrere Werte in einer Bedingung
					bedeuten <strong>oder</strong>. Verglichen wird nachsichtig: Groß-/Kleinschreibung, Leerzeichen und die
					Schreibweise von Paragrafen (<code>§ 37,6</code> = <code>§ 37 Abs. 6</code>) spielen keine Rolle.
					<em>„ist"</em> verlangt den ganzen Wert, <em>„enthält"</em> genügt ein Teil (<code>37,6</code> findet
					<code>§ 37,6 BetrVG</code>).</p>
				<p class="description bi-rg-nur-seminar">Für Anmeldeweg und Formular stehen nur Felder des <strong>Seminars</strong>
					zur Wahl: Beides steht fest, bevor jemand das Formular ausfüllt.</p>
			</div>

			<div class="bi-rg-schritt">
				<h2><span class="bi-rg-nr">2</span> … dann</h2>
				<p>
					<select name="aktion" id="bi-rg-aktion">
						<option value="anmeldeweg" <?php selected( $regel['aktion'], 'anmeldeweg' ); ?>>Anmeldeweg festlegen</option>
						<option value="formular" <?php selected( $regel['aktion'], 'formular' ); ?>>Anmeldeformular zuweisen</option>
						<option value="mail" <?php selected( $regel['aktion'], 'mail' ); ?>>Benachrichtigung verschicken</option>
						<option value="anhang" <?php selected( $regel['aktion'], 'anhang' ); ?>>Anhang an eine Benachrichtigung</option>
					</select>
				</p>
				<div class="bi-rg-ziel" data-aktion="anmeldeweg">
					<select name="ziel_anmeldeweg">
						<?php foreach ( self::ziel_labels() as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $regel['ziel'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="bi-rg-ziel" data-aktion="formular">
					<select name="ziel_formular">
						<?php foreach ( $formulare as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $regel['ziel'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="bi-rg-ziel" data-aktion="anhang">
					<p>
						<select name="anhang" id="bi-rg-anhang">
							<?php foreach ( self::anhang_labels() as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $regel['anhang'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="bi-rg-datei">
							<input type="hidden" name="datei" id="bi-rg-datei" value="<?php echo (int) $regel['datei']; ?>">
							<button type="button" class="button" id="bi-rg-datei-waehlen">Datei wählen …</button>
							<span id="bi-rg-datei-name"><?php echo esc_html( (string) $datei_t ); ?></span>
						</span>
					</p>
					<p class="description">Seminardetails und Beschlussvorlage werden beim Versand erzeugt; der Themenplan kommt aus
						Datenpflege → Themenpläne. Eine Datei aus der Mediathek (z. B. Datenschutzhinweise als PDF) geht als Kopie mit –
						das Original bleibt, wo es ist.</p>
					<p class="description"><strong>PDF-Formulare werden ausgefüllt:</strong> Heißt ein Formularfeld im PDF wie ein
						Platzhalter der Benachrichtigungen – <code>{name}</code>, <code>{strasse}</code>, auch zusammengesetzt wie
						<code>{plz}/{betrieb_ort}</code> –, steht dort der Wert aus der Anmeldung. Ein Feldname ohne Klammern zählt als
						Platzhalter seines Namens: <code>Datum</code> = <code>{datum}</code>, <code>Einwilligung</code> =
						<code>{einwilligung}</code>. Kästchen werden angekreuzt, wenn der Wert nicht leer und nicht „nein" ist.</p>
					<?php echo self::pdf_felder_hinweis( $regel ); // phpcs:ignore – intern escaped ?>
					<p>an die Benachrichtigung</p>
				</div>
				<div class="bi-rg-ziel" data-aktion="mail anhang">
					<select name="ziel_mail">
						<?php foreach ( $triggers as $t ) : ?>
							<option value="<?php echo (int) $t['id']; ?>" <?php selected( $regel['ziel'], (string) (int) $t['id'] ); ?>>
								<?php echo esc_html( ( '' !== (string) ( $t['name'] ?? '' ) ? $t['name'] : 'ohne Bezeichnung' ) . ( empty( $t['active'] ) ? ' (inaktiv)' : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="bi-rg-schritt">
				<h2><span class="bi-rg-nr">3</span> Notiz und Status</h2>
				<p><input type="text" name="notiz" class="large-text" value="<?php echo esc_attr( $regel['notiz'] ); ?>" placeholder="Wozu gibt es diese Regel? (nur intern)"></p>
				<p><label><input type="checkbox" name="aktiv" value="1" <?php checked( $regel['aktiv'] ); ?>> Regel ist eingeschaltet</label></p>
			</div>

			<noscript><div class="notice notice-error"><p>Die Bedingungen lassen sich nur mit eingeschaltetem JavaScript bearbeiten.</p></div></noscript>
			<?php submit_button( 'Regel speichern' ); ?>
		</form>
		<?php
	}

	/**
	 * Unter einer gespeicherten Anhang-Regel: Welche Formularfelder hat das
	 * PDF, und welche davon füllt der Versand? Fängt Tippfehler im Feldnamen
	 * ab, bevor die erste Geschäftsstelle ein halb leeres Formular bekommt.
	 */
	private static function pdf_felder_hinweis( $regel ) {
		if ( 'datei' !== $regel['anhang'] || ! $regel['datei'] || ! BI_Mailer::pdf_formular_laden() ) {
			return '';
		}
		$pfad = function_exists( 'get_attached_file' ) ? (string) get_attached_file( (int) $regel['datei'] ) : '';
		if ( '' === $pfad || 'pdf' !== strtolower( pathinfo( $pfad, PATHINFO_EXTENSION ) ) ) {
			return '';
		}
		$felder = BI_PDF_Formular::felder_der_datei( $pfad );
		if ( ! $felder ) {
			return '<p class="description">Die gewählte PDF-Datei hat keine Formularfelder – sie geht unverändert mit.</p>';
		}
		$bekannt = BI_Mailer::placeholders();
		$zeilen  = '';
		$treffer = 0;
		foreach ( $felder as $f ) {
			$fehlt = array();
			foreach ( $f['platzhalter'] as $ph ) {
				if ( ! isset( $bekannt[ $ph ] ) ) {
					$fehlt[] = $ph;
				}
			}
			$art = array( 'text' => 'Text', 'haken' => 'Kästchen', 'auswahl' => 'Auswahl' )[ $f['art'] ] ?? 'wird nicht unterstützt';
			if ( ! $f['platzhalter'] || 'wird nicht unterstützt' === $art ) {
				$status = '<span style="color:#646970">bleibt unverändert</span>';
			} elseif ( count( $fehlt ) === count( $f['platzhalter'] ) ) {
				$status = '<span style="color:#b32d2e">kein bekannter Platzhalter – bleibt unverändert</span>';
			} else {
				$treffer++;
				$status = '<span style="color:#00a32a">wird ausgefüllt</span>'
					. ( $fehlt ? ' <span style="color:#996800">(unbekannt: ' . esc_html( implode( ', ', $fehlt ) ) . ')</span>' : '' );
			}
			$zeilen .= '<tr><td><code>' . esc_html( $f['name'] ) . '</code></td><td>' . esc_html( $art ) . '</td><td>' . $status . '</td></tr>';
		}
		$vorschau = wp_nonce_url(
			add_query_arg( array( 'action' => 'bi_pdf_formular_vorschau', 'datei' => (int) $regel['datei'] ), admin_url( 'admin-post.php' ) ),
			'bi_pdf_formular_vorschau'
		);
		return '<div class="bi-rg-pdf-felder" style="margin:8px 0 14px">'
			. '<p><strong>Formularfelder in „' . esc_html( basename( $pfad ) ) . '":</strong> ' . (int) $treffer . ' von ' . count( $felder ) . ' werden ausgefüllt.'
			. ( $treffer ? ' <a href="' . esc_url( $vorschau ) . '" target="_blank" rel="noopener">Vorschau mit Beispieldaten (Max Mustermann)</a>' : '' ) . '</p>'
			. '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Feld im PDF</th><th>Art</th><th>Beim Versand</th></tr></thead><tbody>'
			. $zeilen . '</tbody></table></div>';
	}

	/** Das PDF mit Beispieldaten ausgefüllt anzeigen – nichts wird gespeichert oder verschickt. */
	public static function handle_pdf_vorschau() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( 'bi_pdf_formular_vorschau' );
		$id   = isset( $_GET['datei'] ) ? (int) $_GET['datei'] : 0;
		$pfad = $id ? (string) get_attached_file( $id ) : '';
		if ( '' === $pfad || ! is_readable( $pfad ) || 'pdf' !== strtolower( pathinfo( $pfad, PATHINFO_EXTENSION ) ) || ! BI_Mailer::pdf_formular_laden() ) {
			wp_die( 'Die PDF-Datei ist nicht lesbar.' );
		}
		$ctx   = BI_Mailer::kontext_fuer( BI_Mailer::beispiel_anmeldung() );
		$anz   = 0;
		$bytes = BI_PDF_Formular::fuellen( (string) file_get_contents( $pfad ), $ctx, $anz ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="Vorschau-' . sanitize_file_name( basename( $pfad ) ) . '"' );
		header( 'Content-Length: ' . strlen( $bytes ) );
		echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'bi_regel_save_' . $id );

		$aktion = sanitize_key( wp_unslash( $_POST['aktion'] ?? '' ) );
		if ( ! in_array( $aktion, array( 'anmeldeweg', 'formular', 'mail', 'anhang' ), true ) ) {
			wp_die( 'Unbekannte Aktion.' );
		}
		$warn = array();

		$ziel = '';
		$anh  = '';
		$dat  = 0;
		if ( 'anmeldeweg' === $aktion ) {
			$ziel = sanitize_key( wp_unslash( $_POST['ziel_anmeldeweg'] ?? '' ) );
			$ziel = isset( self::RANG[ $ziel ] ) ? $ziel : 'keine';
		} elseif ( 'formular' === $aktion ) {
			$ziel = sanitize_key( wp_unslash( $_POST['ziel_formular'] ?? '' ) );
			if ( ! array_key_exists( $ziel, BI_Formulare::choices() ) ) {
				wp_die( 'Unbekanntes Formular.' );
			}
		} else {
			$ziel = (string) (int) ( $_POST['ziel_mail'] ?? 0 );
			if ( ! BI_Mailer::get_trigger( (int) $ziel ) ) {
				wp_die( 'Unbekannte Benachrichtigung.' );
			}
			if ( 'anhang' === $aktion ) {
				$anh = sanitize_key( wp_unslash( $_POST['anhang'] ?? '' ) );
				if ( ! array_key_exists( $anh, self::anhang_labels() ) ) {
					$anh = 'seminar';
				}
				$dat = 'datei' === $anh ? (int) ( $_POST['datei'] ?? 0 ) : 0;
				if ( 'datei' === $anh && ( ! $dat || 'attachment' !== get_post_type( $dat ) ) ) {
					$warn[] = 'Es ist keine Datei aus der Mediathek gewählt – die Regel ist deshalb ausgeschaltet.';
					$dat    = 0;
				}
			}
		}

		$felder = self::felder();
		$bed    = array();
		$roh    = isset( $_POST['bed'] ) && is_array( $_POST['bed'] ) ? wp_unslash( $_POST['bed'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $roh as $b ) {
			$feld = sanitize_text_field( (string) ( $b['feld'] ?? '' ) );
			$op   = sanitize_key( (string) ( $b['op'] ?? '' ) );
			if ( '' === $feld ) {
				continue;
			}
			if ( ! isset( $felder[ $feld ] ) ) {
				$warn[] = 'Eine Bedingung auf ein unbekanntes Feld wurde verworfen.';
				continue;
			}
			$def = $felder[ $feld ];
			if ( 'anmeldung' === $def['quelle'] && in_array( $aktion, array( 'anmeldeweg', 'formular' ), true ) ) {
				$warn[] = sprintf( 'Die Bedingung auf „%s" wurde verworfen: Anmeldeweg und Formular stehen fest, bevor jemand das Formular ausfüllt.', $def['label'] );
				continue;
			}
			if ( ! in_array( $op, self::ops_fuer( $def['art'] ), true ) ) {
				$op = self::ops_fuer( $def['art'] )[0];
			}
			$werte = array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $b['werte'] ?? array() ) ) );
			foreach ( explode( '|', (string) ( $b['frei'] ?? '' ) ) as $w ) {
				$werte[] = sanitize_text_field( $w );
			}
			$werte = array_values( array_unique( array_filter( array_map( 'trim', $werte ), 'strlen' ) ) );
			if ( 'frist' === $def['art'] ) {
				$tage  = BI_Settings::frist_tage( $werte[0] ?? '' );
				$werte = null === $tage ? array() : array( (string) $tage );
			}
			if ( ! self::braucht_werte( $op ) ) {
				$werte = array();
			} elseif ( ! $werte ) {
				$warn[] = sprintf( 'Die Bedingung auf „%s" hatte keinen Wert und wurde verworfen.', $def['label'] );
				continue;
			}
			$bed[] = array( 'feld' => $feld, 'op' => $op, 'werte' => $werte );
		}

		$regel = array(
			'id'          => $id,
			'aktiv'       => ! empty( $_POST['aktiv'] ) && ! ( 'datei' === $anh && ! $dat ) ? 1 : 0,
			'notiz'       => sanitize_text_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
			'bedingungen' => $bed,
			'aktion'      => $aktion,
			'ziel'        => $ziel,
			'anhang'      => $anh,
			'datei'       => $dat,
		);
		self::put( $regel );

		if ( class_exists( 'BI_Cache' ) && in_array( $aktion, array( 'anmeldeweg', 'formular' ), true ) ) {
			BI_Cache::leeren( true ); // Knöpfe und Störer stehen als HTML im Seiten-Cache
		}

		$anker = in_array( $aktion, array( 'mail', 'anhang' ), true ) ? '#bi-rg-mail-' . (int) $ziel : '#bi-rg-' . ( 'formular' === $aktion ? 'formular' : 'weg' );
		$norm  = self::normalisieren( $regel );
		$args  = array( 'bi_msg' => rawurlencode( 'Regel gespeichert: ' . ucfirst( self::bedingungen_text( $norm ) ) . ' → ' . self::aktion_text( $norm ) . '.' ) );
		if ( $warn ) {
			$args['bi_warn'] = rawurlencode( implode( ' ', array_unique( $warn ) ) );
		}
		wp_safe_redirect( self::page_url( $args ) . $anker );
		exit;
	}

	public static function handle_aktion() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$do = sanitize_key( wp_unslash( $_GET['do'] ?? '' ) );
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'bi_regel_' . $do . '_' . $id );
		$r = self::get( $id );
		if ( ! $r ) {
			wp_safe_redirect( self::page_url( array( 'bi_warn' => rawurlencode( 'Diese Regel gibt es nicht (mehr).' ) ) ) );
			exit;
		}
		if ( 'loeschen' === $do ) {
			self::loeschen( $id );
			$msg = 'Regel gelöscht.';
		} else {
			$r['aktiv'] = ( 'an' === $do ) ? 1 : 0;
			self::put( $r );
			$msg = $r['aktiv'] ? 'Regel eingeschaltet.' : 'Regel ausgeschaltet.';
		}
		if ( class_exists( 'BI_Cache' ) && in_array( $r['aktion'], array( 'anmeldeweg', 'formular' ), true ) ) {
			BI_Cache::leeren( true );
		}
		$anker = in_array( $r['aktion'], array( 'mail', 'anhang' ), true ) ? '#bi-rg-mail-' . (int) $r['ziel'] : '#bi-rg-' . ( 'formular' === $r['aktion'] ? 'formular' : 'weg' );
		wp_safe_redirect( self::page_url( array( 'bi_msg' => rawurlencode( $msg ) ) ) . $anker );
		exit;
	}

	public static function handle_bericht_ok() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_regeln_bericht_ok' );
		$b = get_option( self::BERICHT_OPTION, array() );
		if ( is_array( $b ) ) {
			$b['offen'] = 0;
			update_option( self::BERICHT_OPTION, $b, false );
		}
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/* ===================================================================
	 *  Regel-Probe
	 * =================================================================== */

	/** Seminare zu einer Nummer (oder einer Beitrags-ID). */
	private static function probe_seminare( $eingabe ) {
		$eingabe = trim( (string) $eingabe );
		if ( '' === $eingabe ) {
			return array();
		}
		$typen = array_merge( bi_seminar_post_types(), class_exists( 'BI_Reihen' ) ? array( BI_Reihen::CPT ) : array() );
		$ids   = get_posts( array(
			'post_type'      => $typen,
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page' => 10,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => '_bi_seminarnummer', 'value' => $eingabe ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		if ( ! $ids && ctype_digit( $eingabe ) && in_array( get_post_type( (int) $eingabe ), $typen, true ) ) {
			$ids = array( (int) $eingabe );
		}
		return array_map( 'intval', $ids );
	}

	/** Anmeldefelder, die in irgendeiner Regel vorkommen. */
	private static function probe_felder() {
		$out = array();
		foreach ( self::alle() as $r ) {
			foreach ( $r['bedingungen'] as $b ) {
				if ( 0 === strpos( $b['feld'], 'anmeldung:' ) ) {
					$out[ $b['feld'] ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	private static function render_probe() {
		$nr    = isset( $_GET['nr'] ) ? sanitize_text_field( wp_unslash( $_GET['nr'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$werte = isset( $_GET['a'] ) && is_array( $_GET['a'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['a'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pf    = self::probe_felder();
		$ids   = self::probe_seminare( $nr );
		?>
		<h1 class="wp-heading-inline">Regel-Probe</h1>
		<a href="<?php echo esc_url( self::page_url() ); ?>" class="page-title-action">Zurück zu den Regeln</a>
		<hr class="wp-header-end">
		<p>Zeigt, welche Regeln für ein Seminar zutreffen und was dabei herauskommt – mit einer gedachten Anmeldung,
		   deren Angaben du unten einstellst.</p>
		<form method="get" class="bi-rg-probe">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="view" value="probe">
			<p><label>Seminarnummer <input type="text" name="nr" value="<?php echo esc_attr( $nr ); ?>" placeholder="z. B. 26-123"></label></p>
			<?php if ( $pf ) : ?>
				<fieldset><legend>Angaben der gedachten Anmeldung</legend>
				<?php foreach ( $pf as $feld ) : ?>
					<?php
					$def  = self::feld( $feld );
					$key  = substr( $feld, strlen( 'anmeldung:' ) );
					$opts = self::optionen( $feld );
					$cur  = (string) ( $werte[ $key ] ?? '' );
					if ( ! $def ) {
						continue;
					}
					?>
					<p><label><?php echo esc_html( $def['label'] ); ?>
						<?php if ( 'checkbox' === $def['typ'] ) : ?>
							<select name="a[<?php echo esc_attr( $key ); ?>]">
								<option value="">nicht angekreuzt</option>
								<option value="1" <?php selected( $cur, '1' ); ?>>angekreuzt</option>
							</select>
						<?php elseif ( $opts ) : ?>
							<select name="a[<?php echo esc_attr( $key ); ?>]">
								<option value="">— leer —</option>
								<?php foreach ( $opts as $o ) : ?>
									<option value="<?php echo esc_attr( $o[0] ); ?>" <?php selected( $cur, $o[0] ); ?>><?php echo esc_html( $o[1] ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="text" name="a[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $cur ); ?>">
						<?php endif; ?>
					</label></p>
				<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>
			<?php submit_button( 'Prüfen', 'primary', '', false ); ?>
		</form>
		<?php
		if ( '' !== $nr && ! $ids ) {
			echo '<div class="notice notice-warning inline"><p>Kein Seminar mit dieser Nummer gefunden.</p></div>';
		}
		foreach ( $ids as $pid ) {
			self::probe_ergebnis( $pid, $werte );
		}
	}

	private static function probe_ergebnis( $pid, $werte ) {
		$weg  = self::anmeldeweg( $pid );
		$z    = self::ziel_labels();
		$sub  = array( 'seminar_id' => $pid, 'post_type' => get_post_type( $pid ), 'data' => $werte );
		$nr   = (string) get_post_meta( $pid, '_bi_seminarnummer', true );
		$form = '';
		if ( class_exists( 'BI_Formulare' ) && ( ! class_exists( 'BI_Reihen' ) || BI_Reihen::CPT !== get_post_type( $pid ) ) ) {
			$form = BI_Formulare::choices()[ BI_Formulare::formular_for( $pid ) ] ?? '';
		}
		$ist_reihe = class_exists( 'BI_Reihen' ) && BI_Reihen::CPT === get_post_type( $pid );
		$regeln_ids = function ( $ids ) {
			$t = array();
			foreach ( $ids as $id ) {
				$r = self::get( $id );
				if ( $r ) {
					$t[] = ucfirst( self::bedingungen_text( $r ) ) . ' → ' . self::aktion_text( $r );
				}
			}
			return $t;
		};
		?>
		<div class="bi-rg-box bi-rg-probe-erg">
			<h3><?php echo esc_html( ( '' !== $nr ? $nr . ' – ' : '' ) . get_the_title( $pid ) ); ?>
				<span class="bi-rg-muted">(<?php echo esc_html( $ist_reihe ? 'Ausbildungsreihe' : ( BI_ONLINE === get_post_type( $pid ) ? 'Online' : 'Präsenz' ) ); ?>)</span></h3>
			<table class="widefat striped">
				<tr><th style="width:200px">Anmeldeweg</th><td>
					<?php if ( '' === $weg['weg'] ) : ?>
						<?php echo $ist_reihe ? 'Keine Regel trifft zu – es entscheiden die Freistellungen der Termine.' : '<strong>Direktanmeldung</strong> (Normalfall, keine Regel trifft zu)'; ?>
					<?php else : ?>
						<strong><?php echo esc_html( $z[ $weg['weg'] ] ?? $weg['weg'] ); ?></strong>
						<?php echo $weg['frist'] ? ' (Fristtexte)' : ''; ?>
						<ul class="bi-rg-probe-liste"><?php foreach ( $regeln_ids( $weg['ids'] ) as $t ) { echo '<li>' . esc_html( $t ) . '</li>'; } ?></ul>
					<?php endif; ?>
				</td></tr>
				<?php if ( '' !== $form ) : ?>
					<tr><th>Formular</th><td><strong><?php echo esc_html( $form ); ?></strong>
						<?php
						$fd = self::formular_detail( $pid );
						if ( '' !== (string) get_post_meta( $pid, BI_Formulare::META, true ) ) {
							echo ' (von Hand am Seminar gewählt)';
						} elseif ( $fd['id'] ) {
							echo '<ul class="bi-rg-probe-liste"><li>' . esc_html( implode( '', $regeln_ids( array( $fd['id'] ) ) ) ) . '</li></ul>';
						} else {
							echo ' (Standardformular)';
						}
						?>
					</td></tr>
				<?php endif; ?>
				<?php if ( ! $ist_reihe ) : ?>
					<?php foreach ( BI_Mailer::get_triggers() as $t ) : ?>
						<?php
						$geht = ! empty( $t['active'] ) && self::sendet( $t['id'], $sub );
						$anh  = $geht ? self::anhaenge( $t['id'], $sub ) : array();
						$al   = self::anhang_labels();
						$an_t = array();
						foreach ( $anh as $a ) {
							$an_t[] = 'datei' === $a['anhang'] ? get_the_title( $a['datei'] ) : $al[ $a['anhang'] ];
						}
						?>
						<tr><th><?php echo esc_html( '' !== (string) ( $t['name'] ?? '' ) ? $t['name'] : 'ohne Bezeichnung' ); ?></th><td>
							<?php if ( empty( $t['active'] ) ) : ?>
								<span class="bi-rg-muted">inaktiv</span>
							<?php elseif ( $geht ) : ?>
								<strong>wird verschickt</strong><?php echo $an_t ? ' – mit ' . esc_html( implode( ', ', $an_t ) ) : ' – ohne Anhang'; ?>
							<?php else : ?>
								wird nicht verschickt
							<?php endif; ?>
						</td></tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}
}
