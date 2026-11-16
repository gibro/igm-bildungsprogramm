<?php
/**
 * Bildungsfahrplan: die exemplarischen Bildungswege aus dem Heft, mit Hilfe
 * zum Quereinstieg.
 *
 * ============================================================================
 *  WORUM ES GEHT (vereinfachte Fassung, 13.11.2026)
 * ============================================================================
 *  Das Bildungsprogramm zeigt „Exemplarische Bildungswege“: fünf Wege (JAV,
 *  Betriebsrat, SBV, junge Beschäftigte, Vertrauensleute) aus wenigen
 *  farbigen Kästen – Einstieg, Grundlagen, Spezialisierung, Politische
 *  Bildung, Referent*in. Genau das zeigt der Fahrplan auf der Website:
 *
 *    - einen Weg als Bahnlinie: senkrechte Strecke, jede Stufe ein Halt,
 *      die Kästen hängen als Haltestellen daran (Farbe wie im Heft),
 *    - je Kasten die passenden Seminare mit Terminanzahl und nächstem Termin,
 *    - „Wo steigst du ein?“: Wer neu ist, beginnt links. Wer Vorwissen hat,
 *      beantwortet je Kasten bis zu drei Ja/Nein-Fragen in Fahrtrichtung.
 *      Die erste Antwort „nein“ ist der Stopp und damit der Einstieg.
 *
 *  Die erste Fassung (Linie mit 94 Haltestellen, Fahrkarte, Zeitplan,
 *  Kalender, Beschlussvorlagen, Gremium) war zu komplex und ist ersetzt.
 *
 *  Das Modul ist ZUSÄTZLICH. Suche, Detailseiten und Reihen bleiben, wie sie
 *  sind; der Fahrplan liest nur.
 *
 * ============================================================================
 *  DATENMODELL
 * ============================================================================
 *  Weg    = Beitragstyp „bi_linie“ (Titel, Einleitung im Editor, Schlüssel,
 *           Zielgruppe). Der Name des Beitragstyps bleibt aus Phase 1.
 *  Kasten = Eintrag in der JSON-Liste _bil_haltestellen am Weg:
 *
 *      id       eindeutig über alle Wege (B01, J02 …)
 *      name     Überschrift wie im Heft
 *      stufe    Abschnitt der Strecke, 1–12 (1 = Einstieg). Mehrere Halte mit gleicher
 *               Stufe folgen nacheinander im selben Abschnitt (z. B. zwei Einstiegs-Halte).
 *      art      einstieg | grundlage | spezialisierung | politisch |
 *               referent | optional  (Farbe wie im Heft)
 *      punkte   Aufzählung im Kasten
 *      titel    Seminartitel, an denen die Termine hängen. Ein * am Ende
 *               heißt „beginnt mit“ (Titel tragen im Bestand teils Zusätze
 *               wie „Teil 1“). Titel statt Seminarnummer: Die Nummer gilt
 *               nur für einen Jahrgang.
 *      regional bei der Geschäftsstelle, keine zentralen Termine
 *      pflicht  formale Voraussetzung für den nächsten Schritt
 *               („Teilnahme am Seminar …“). Wird der Kasten per
 *               Quereinstieg übersprungen, heißt es „mit der
 *               Geschäftsstelle klären“ – nie eine Freigabe.
 *      fragen   bis zu drei Ja/Nein-Fragen. Fehlen sie, baut ersatzfragen()
 *               sie aus den Punkten – das Modul braucht keine KI.
 *      ast      Name des Astes nach einer Weiche („Betriebspolitik“), leer =
 *               Hauptstrecke. Siehe WEICHEN unten.
 *
 *  referent und optional werden nicht abgefragt.
 *
 * ============================================================================
 *  WEICHEN (seit 1.156.0)
 * ============================================================================
 *  Ein Weg darf sich teilen. Halte mit Ast-Namen bilden eine Weiche: Alle
 *  aufeinanderfolgenden Stufen, in denen mindestens ein Halt einen Ast hat,
 *  gehören zu einer Weiche. Gleicher Name = gleicher Ast (Groß/klein egal).
 *  Danach geht es entweder auf der Hauptstrecke weiter (die Äste laufen
 *  wieder zusammen) oder die Äste enden jeweils an ihrem eigenen Endhalt.
 *
 *  Ansicht: Auf breiten Bildschirmen laufen die Äste nebeneinander, wie im
 *  Netzplan. Die Linie läuft dann auf dem ganzen Weg in der Mitte über den
 *  Ästen und gabelt sich nach links und rechts – zwei gleichwertige
 *  Richtungen, keine Abzweigung von einer Hauptstrecke. Schmal gibt es an der Weiche Richtungsschilder; gezeigt wird
 *  ein Ast, die anderen per Klick. Die Einstiegshilfe fragt an der Weiche
 *  „In welche Richtung willst du?“ und danach nur den gewählten Ast.
 *
 *  Ein Halt ohne Ast mitten in einer Weiche fährt auf dem ersten Ast mit
 *  (Hinweis in der Prüfliste). Eine „Weiche“ mit nur einem Ast ist keine –
 *  die Halte laufen dann auf der Hauptstrecke.
 *
 * ============================================================================
 *  NUR AUF DER ZENTRALE
 * ============================================================================
 *  Wege pflegt nur die Zentrale (oder eine Einzelinstallation ohne Abgleich).
 *  Auf einer Quelle bleibt das Modul stumm; der Shortcode gibt dort nichts aus.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Fahrplan {

	/** Beitragstyp eines Weges */
	const CPT = 'bi_linie';

	/** Meta-Felder des Weges */
	const META_HALTESTELLEN = '_bil_haltestellen';
	const META_SCHLUESSEL   = '_bil_schluessel';
	const META_ZIELGRUPPE   = '_bil_zielgruppe';

	/** Mitgelieferte Startbelegung (erzeugt von handoff/bildungswege-startbelegung.py) */
	const STARTBELEGUNG = 'assets/data/fahrplan-startbelegung.json';

	/** Arten eines Kastens => Beschriftung (Legende wie im Heft) */
	const ARTEN = array(
		'einstieg'        => 'Einstieg',
		'grundlage'       => 'Grundlagenbildung',
		'spezialisierung' => 'Spezialisierung',
		'politisch'       => 'Politische Bildung',
		'referent'        => 'Ausbildung als Referent*in (optional)',
		'optional'        => 'optional, hilfreich',
	);

	/** Arten, die nicht abgefragt werden */
	const OHNE_FRAGEN = array( 'referent', 'optional' );

	const MAX_STUFE  = 12;
	const MAX_FRAGEN = 3;

	/** Mehr Äste je Weiche werden nebeneinander zu schmal (nur Hinweis). */
	const MAX_AESTE = 3;

	public static function init() {
		// Den Shortcode gibt es überall, damit er auf einer Quelle nicht als
		// roher Text „[bi_fahrplan]“ auf der Seite steht. Dort bleibt er leer.
		add_shortcode( 'bi_fahrplan', array( __CLASS__, 'shortcode' ) );
		if ( ! self::aktiv() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'hinweis_startbelegung' ) );
		add_action( 'admin_post_bi_fahrplan_start', array( __CLASS__, 'handle_startbelegung' ) );
		add_action( 'admin_post_bi_fahrplan_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_bi_fahrplan_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_austausch' ) );
		add_action( 'admin_post_bi_fahrplan_kopie', array( __CLASS__, 'handle_kopie' ) );
		add_action( 'wp_ajax_bi_fahrplan_suche', array( __CLASS__, 'ajax_suche' ) );
		add_action( 'wp_ajax_bi_fahrplan_analyse', array( __CLASS__, 'ajax_analyse' ) );
		add_action( 'wp_ajax_bi_fahrplan_vorschau', array( __CLASS__, 'ajax_vorschau' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'zeilen_aktionen' ), 10, 2 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
	}

	/** Auf einer Quelle des Abgleichs bleibt der Fahrplan aus. */
	public static function aktiv() {
		return ! ( class_exists( 'BI_Sync' ) && method_exists( 'BI_Sync', 'ist_quelle' ) && BI_Sync::ist_quelle() );
	}

	public static function register() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'Bildungswege',
				'singular_name' => 'Bildungsweg',
				'add_new'       => 'Neuer Weg',
				'add_new_item'  => 'Neuen Bildungsweg anlegen',
				'edit_item'     => 'Bildungsweg bearbeiten',
				'search_items'  => 'Wege durchsuchen',
				'not_found'     => 'Keine Bildungswege gefunden',
				'menu_name'     => 'Bildungswege',
				'all_items'     => 'Bildungswege',
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'bi-seminarsuche',
			'show_in_rest' => false,
			'supports'     => array( 'title', 'editor', 'page-attributes' ),
		) );
	}

	/* ===================================================================
	 *  Reine Hilfsfunktionen (ohne Datenbank, siehe tests/test-fahrplan.php)
	 * =================================================================== */

	/** Ungültiges UTF-8 reparieren – sonst liefern /u-Regex null. */
	public static function utf8( $s ) {
		$s = (string) $s;
		if ( '' === $s || preg_match( '//u', $s ) ) {
			return $s;
		}
		return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $s, 'UTF-8', 'UTF-8' ) : utf8_encode( $s );
	}

	/** Titel vergleichbar machen: Striche, Anführungszeichen, Leerraum, Groß/klein. */
	public static function norm( $s ) {
		$s = self::utf8( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) );
		$s = str_replace( "\xC2\xAD", '', $s );
		$s = str_replace( array( '–', '—', '‑', '‐' ), '-', $s );
		$s = str_replace( array( '„', '“', '”', '»', '«', '‚', '‘', '’' ), '"', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		$s = preg_replace( '/\s+-\s*|\s*-\s+/u', ' - ', $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ), 'UTF-8' ) : strtolower( trim( $s ) );
	}

	/**
	 * Passt ein Seminartitel zu einem Muster? Ein * am Ende des Musters heißt
	 * „beginnt mit“, sonst muss der Titel gleich sein (nach norm()).
	 */
	public static function passt( $muster, $titel ) {
		return self::passt_norm( self::norm( $muster ), self::norm( $titel ) );
	}

	/** Wie passt(), aber beide Seiten schon durch norm() – spart Arbeit in Schleifen. */
	private static function passt_norm( $m, $t ) {
		if ( '' === $m ) {
			return false;
		}
		if ( '*' === substr( $m, -1 ) ) {
			$anfang = substr( $m, 0, -1 );
			if ( '' === trim( $anfang ) || 0 !== strpos( $t, $anfang ) ) {
				return false;
			}
			// Nicht mitten im Wort enden: „Jugend II*“ darf nicht „Jugend III …“ treffen.
			return ! ( preg_match( '/[\p{L}\p{N}]$/u', $anfang ) && preg_match( '/^[\p{L}\p{N}]/u', (string) substr( $t, strlen( $anfang ) ) ) );
		}
		return $t === $m;
	}

	/** Text einer Zeile säubern. */
	private static function zeile( $s ) {
		$s = wp_strip_all_tags( self::utf8( $s ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Liste aus Array oder mehrzeiligem Text, gesäubert, ohne Leere und Doppelte. */
	private static function liste( $v ) {
		if ( is_string( $v ) ) {
			$v = preg_split( '/\r\n|\r|\n/', $v );
		}
		$out = array();
		foreach ( (array) $v as $x ) {
			$x = self::zeile( is_scalar( $x ) ? $x : '' );
			if ( '' !== $x && ! in_array( $x, $out, true ) ) {
				$out[] = $x;
			}
		}
		return $out;
	}

	/**
	 * Einen Kasten in die gespeicherte Form bringen. Unbekanntes fällt weg,
	 * Fehlendes bekommt einen leeren Wert.
	 *
	 * Versteht auch die Form der ersten Fassung (fragen = {einstieg, praxis}),
	 * damit ein früher angelegter Entwurf nicht kaputt geht.
	 */
	public static function sanitize_kasten( $k ) {
		$k   = is_array( $k ) ? $k : array();
		$art = (string) ( $k['art'] ?? '' );

		$fragen = $k['fragen'] ?? array();
		if ( is_array( $fragen ) && ( isset( $fragen['einstieg'] ) || isset( $fragen['praxis'] ) ) ) {
			$alt = array( $fragen['einstieg'] ?? '' );
			foreach ( (array) ( $fragen['praxis'] ?? array() ) as $p ) {
				$alt[] = is_array( $p ) ? ( $p['frage'] ?? '' ) : $p;
			}
			$fragen = $alt;
		}

		return array(
			'id'       => strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $k['id'] ?? '' ) ) ),
			'name'     => self::zeile( $k['name'] ?? '' ),
			'stufe'    => max( 1, min( self::MAX_STUFE, (int) ( $k['stufe'] ?? 1 ) ) ),
			'art'      => isset( self::ARTEN[ $art ] ) ? $art : 'grundlage',
			'punkte'   => self::liste( $k['punkte'] ?? array() ),
			'titel'    => self::liste( $k['titel'] ?? array() ),
			'regional' => ! empty( $k['regional'] ),
			'pflicht'  => ! empty( $k['pflicht'] ),
			'fragen'   => array_slice( self::liste( $fragen ), 0, self::MAX_FRAGEN ),
			'ast'      => mb_substr( self::zeile( $k['ast'] ?? '' ), 0, 40 ),
		);
	}

	/** Ganze Liste säubern; Kästen ohne ID oder Namen fallen weg, doppelte IDs auch. */
	public static function sanitize_kaesten( $liste ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $liste as $k ) {
			$s = self::sanitize_kasten( $k );
			if ( '' !== $s['id'] && '' !== $s['name'] && ! isset( $seen[ $s['id'] ] ) ) {
				$seen[ $s['id'] ] = true;
				$out[]            = $s;
			}
		}
		return $out;
	}

	/**
	 * Fragen eines Kastens. Ohne gespeicherte Fragen: eine Frage je Punkt
	 * (höchstens drei), sonst eine allgemeine zum Namen. Keine KI.
	 *
	 * @return string[] leer bei referent/optional
	 */
	public static function fragen( $k ) {
		if ( in_array( $k['art'], self::OHNE_FRAGEN, true ) ) {
			return array();
		}
		if ( $k['fragen'] ) {
			return $k['fragen'];
		}
		return self::ersatzfragen( $k['name'], $k['punkte'] );
	}

	/** Ersatzfragen ohne KI aus Name und Punkten. */
	public static function ersatzfragen( $name, $punkte ) {
		$out = array();
		foreach ( array_slice( (array) $punkte, 0, self::MAX_FRAGEN ) as $p ) {
			$out[] = 'Kennst du dich schon gut aus mit: ' . $p . '?';
		}
		return $out ? $out : array( 'Kennst du dich mit „' . $name . '“ schon gut aus?' );
	}

	/**
	 * Kästen je Halt (stufe): stufe => [kästen] in gepflegter Reihenfolge,
	 * Halte aufsteigend. Lücken (z. B. nur 1 und 3) bleiben weg.
	 */
	public static function spalten( $kaesten ) {
		$out = array();
		foreach ( $kaesten as $k ) {
			$out[ $k['stufe'] ][] = $k;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Die Strecke in Abschnitte teilen: Hauptstrecke und Weichen.
	 *
	 * @return array [
	 *   [ 'typ' => 'stamm', 'kaesten' => [ … ] ],
	 *   [ 'typ' => 'weiche', 'nr' => 1, 'aeste' => [ Name => [ kästen ], … ], 'ohne_ast' => [ ids ] ],
	 * ] – Kästen je Abschnitt/Ast nach Stufe, innen in gepflegter Reihenfolge.
	 */
	public static function abschnitte( $kaesten ) {
		// 1. Stufen zu Läufen zusammenfassen: mit Ast / ohne Ast.
		$laeufe = array();
		foreach ( self::spalten( $kaesten ) as $liste ) {
			$typ = 'stamm';
			foreach ( $liste as $k ) {
				if ( '' !== $k['ast'] ) {
					$typ = 'weiche';
				}
			}
			$n = count( $laeufe );
			if ( $n && $laeufe[ $n - 1 ]['typ'] === $typ ) {
				$laeufe[ $n - 1 ]['kaesten'] = array_merge( $laeufe[ $n - 1 ]['kaesten'], $liste );
			} else {
				$laeufe[] = array( 'typ' => $typ, 'kaesten' => $liste );
			}
		}

		// 2. Weichen in Äste teilen; eine Weiche mit nur einem Ast wird Hauptstrecke.
		$out = array();
		$nr  = 0;
		foreach ( $laeufe as $l ) {
			if ( 'weiche' === $l['typ'] ) {
				$namen = array(); // norm => Anzeigename (erste Schreibweise)
				foreach ( $l['kaesten'] as $k ) {
					if ( '' !== $k['ast'] && ! isset( $namen[ self::norm( $k['ast'] ) ] ) ) {
						$namen[ self::norm( $k['ast'] ) ] = $k['ast'];
					}
				}
				if ( count( $namen ) >= 2 ) {
					$erster = reset( $namen );
					$aeste  = array();
					foreach ( $namen as $name ) {
						$aeste[ $name ] = array();
					}
					$ohne = array();
					foreach ( $l['kaesten'] as $k ) {
						if ( '' === $k['ast'] ) {
							$ohne[]             = $k['id'];
							$aeste[ $erster ][] = $k;
						} else {
							$aeste[ $namen[ self::norm( $k['ast'] ) ] ][] = $k;
						}
					}
					$nr++;
					$out[] = array( 'typ' => 'weiche', 'nr' => $nr, 'aeste' => $aeste, 'ohne_ast' => $ohne );
					continue;
				}
			}
			$n = count( $out );
			if ( $n && 'stamm' === $out[ $n - 1 ]['typ'] ) {
				$out[ $n - 1 ]['kaesten'] = array_merge( $out[ $n - 1 ]['kaesten'], $l['kaesten'] );
			} else {
				$out[] = array( 'typ' => 'stamm', 'kaesten' => $l['kaesten'] );
			}
		}
		return $out;
	}

	/**
	 * Wo liegt jeder Halt? id => [ 'weiche' => Nr (0 = Hauptstrecke), 'ast' => Name ]
	 */
	public static function lage( $kaesten ) {
		$out = array();
		foreach ( self::abschnitte( $kaesten ) as $a ) {
			if ( 'stamm' === $a['typ'] ) {
				foreach ( $a['kaesten'] as $k ) {
					$out[ $k['id'] ] = array( 'weiche' => 0, 'ast' => '' );
				}
				continue;
			}
			foreach ( $a['aeste'] as $name => $liste ) {
				foreach ( $liste as $k ) {
					$out[ $k['id'] ] = array( 'weiche' => $a['nr'], 'ast' => (string) $name );
				}
			}
		}
		return $out;
	}

	/**
	 * Hinweise zu den Weichen für die Prüfliste.
	 *
	 * @return array id => [ [ 'stufe' => 'warn', 'text' => … ], … ]
	 */
	public static function weichen_befunde( $kaesten ) {
		$out    = array();
		$in_wei = array();
		foreach ( self::abschnitte( $kaesten ) as $a ) {
			if ( 'weiche' !== $a['typ'] ) {
				continue;
			}
			$namen = array_keys( $a['aeste'] );
			foreach ( $a['ohne_ast'] as $id ) {
				$out[ $id ][] = array( 'stufe' => 'warn', 'text' => 'steht mitten in einer Weiche ohne Ast – fährt auf „' . $namen[0] . '“ mit. Ast eintragen oder Stufe ändern' );
			}
			if ( count( $namen ) > self::MAX_AESTE ) {
				$erster = reset( $a['aeste'] );
				$out[ $erster[0]['id'] ][] = array( 'stufe' => 'warn', 'text' => count( $namen ) . ' Äste an einer Weiche – mehr als ' . self::MAX_AESTE . ' werden nebeneinander zu schmal' );
			}
			foreach ( $a['aeste'] as $liste ) {
				foreach ( $liste as $k ) {
					$in_wei[ $k['id'] ] = true;
				}
			}
		}
		// Ast eingetragen, aber kein zweiter Ast daneben → läuft auf der Hauptstrecke.
		foreach ( $kaesten as $k ) {
			if ( '' !== $k['ast'] && empty( $in_wei[ $k['id'] ] ) ) {
				$out[ $k['id'] ][] = array( 'stufe' => 'warn', 'text' => 'Ast „' . $k['ast'] . '“ hat keinen zweiten Ast daneben – keine Weiche, läuft auf der Hauptstrecke' );
			}
		}
		return $out;
	}

	/**
	 * Befund je Kasten für die Prüfliste in der Pflege.
	 *
	 * @param array $kaesten Kästen
	 * @param array $treffer id => Termine (seminare_je_kasten)
	 * @return array [ ['id','name','stufe'=>'ok'|'warn'|'fehler','befund'=>[]] ]
	 */
	public static function pruef_zeilen( $kaesten, $treffer, $voraus_befunde = array() ) {
		$out = array();
		foreach ( $kaesten as $k ) {
			$befund = array();
			$stufe  = 'ok';
			if ( ! $k['titel'] && ! $k['regional'] ) {
				$befund[] = 'kein Seminartitel und nicht regional';
				$stufe    = 'fehler';
			}
			foreach ( $k['titel'] as $t ) {
				if ( empty( $treffer[ $k['id'] ]['muster'][ $t ] ) ) {
					$befund[] = 'kein kommender Termin: ' . $t;
					$stufe    = 'fehler' === $stufe ? $stufe : 'warn';
				}
			}
			if ( ! in_array( $k['art'], self::OHNE_FRAGEN, true ) && ! $k['fragen'] ) {
				$befund[] = 'keine eigenen Fragen – Ersatzfragen aus den Punkten';
				$stufe    = 'fehler' === $stufe ? $stufe : 'warn';
			}
			foreach ( $voraus_befunde[ $k['id'] ] ?? array() as $v ) {
				$befund[] = $v['text'];
				$stufe    = 'fehler' === $stufe || 'fehler' === $v['stufe'] ? 'fehler' : 'warn';
			}
			$out[] = array( 'id' => $k['id'], 'name' => $k['name'], 'stufe' => $stufe, 'befund' => $befund );
		}
		return $out;
	}

	/* ===================================================================
	 *  Hilfen beim Anlegen (rein, ohne Datenbank)
	 * =================================================================== */

	/**
	 * Stichpunkte aus „Themen im Seminar“. Verstanden werden <li>-Listen (so
	 * speichert der Import) und Aufzählungszeichen • und ▸ (GBi, Online).
	 */
	public static function stichpunkte( $themen ) {
		$t = self::utf8( $themen );
		if ( preg_match_all( '#<li[^>]*>(.*?)</li>#is', $t, $m ) ) {
			$items = $m[1];
		} else {
			$items = preg_split( '/\s*[•▸]\s*|\r\n|\r|\n|<br\s*\/?>/u', $t );
		}
		$out = array();
		foreach ( (array) $items as $i ) {
			$i = (string) preg_replace( '/\s+/u', ' ', self::utf8( html_entity_decode( wp_strip_all_tags( $i ), ENT_QUOTES, 'UTF-8' ) ) );
			// Kein trim() mit „•▸“: trim arbeitet byteweise und zerstört Mehrbyte-Zeichen.
			$i = trim( (string) preg_replace( '/^[\s•▸\-–]+|[\s•▸\-–]+$/u', '', $i ) );
			if ( '' !== $i && ! in_array( $i, $out, true ) && 'themen im seminar' !== self::norm( $i ) ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * Kurzform eines Stichpunkts: „Bewerbung: zulässige Fragen …“ → „Bewerbung“.
	 * Ohne Doppelpunkt bleibt er, zu lange werden an einer Wortgrenze gekürzt.
	 */
	public static function kurzform( $sp, $max = 70 ) {
		$sp = trim( (string) preg_replace( '/[\s.:;,]+$/u', '', (string) $sp ) );
		if ( mb_strlen( $sp ) <= $max ) {
			return $sp;
		}
		$kopf = trim( (string) preg_replace( '/:.*$/us', '', $sp ) );
		if ( $kopf !== $sp && mb_strlen( $kopf ) >= 3 && mb_strlen( $kopf ) <= $max ) {
			return $kopf;
		}
		$kurz = mb_substr( $sp, 0, $max );
		$kurz = preg_replace( '/\s+\S*$/u', '', $kurz );
		return rtrim( (string) $kurz, ' ,;:-–' ) . ' …';
	}

	/**
	 * Fragen-Vorschläge aus Stichpunkten – ohne KI, mit wechselnden
	 * Satzanfängen, damit sie nicht wie eine Liste klingen. Ein Stichpunkt,
	 * der schon eine Frage ist, bleibt, wie er ist.
	 *
	 * @return string[]
	 */
	public static function fragen_vorschlaege( $stichpunkte, $max = 6 ) {
		$vorlagen = array(
			'Könntest du einer Kollegin oder einem Kollegen erklären, worum es bei „%s“ geht?',
			'Wüsstest du, was du im Betrieb konkret tun kannst, wenn es um „%s“ geht?',
			'Kennst du dich bei „%s“ so gut aus, dass du es im Gremium vertreten könntest?',
		);
		// Kurze Stichpunkte geben lesbarere Fragen – sie kommen zuerst (sonst Reihenfolge der Themen).
		$liste = array_values( (array) $stichpunkte );
		$rang  = array();
		foreach ( $liste as $i => $sp ) {
			$rang[] = array( mb_strlen( (string) $sp ) > 70 ? 1 : 0, $i, $sp );
		}
		sort( $rang );
		$out = array();
		foreach ( array_column( $rang, 2 ) as $i => $sp ) {
			$sp = trim( (string) preg_replace( '/[\s.:;,]+$/u', '', (string) $sp ) );
			if ( '' === $sp ) {
				continue;
			}
			$out[] = '?' === substr( $sp, -1 ) ? $sp : sprintf( $vorlagen[ $i % count( $vorlagen ) ], self::kurzform( $sp ) );
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Art eines Halts aus Themenfeld und Titel vorschlagen.
	 *
	 * @return array [ 'art' => …, 'grund' => Text für die Pflege ]
	 */
	public static function art_vorschlag( $themenfelder, $titel ) {
		$t = self::norm( $titel );
		if ( preg_match( '/^(einstieg|einführung in die betriebsratsarbeit|zentrale aufgaben der schwerbehindertenvertretung|jav-seminar|jugend i\b(?! ?i))/u', $t ) ) {
			return array( 'art' => 'einstieg', 'grund' => 'Titel klingt nach Einstieg' );
		}
		if ( preg_match( '/^jugend i{2,3}\b/u', $t ) ) {
			return array( 'art' => 'politisch', 'grund' => 'Jugend II/III gehören zur politischen Bildung' );
		}
		foreach ( (array) $themenfelder as $f ) {
			$n = self::norm( $f );
			$g = 'Themenfeld „' . $f . '“';
			if ( false !== strpos( $n, 'gesellschaftspolitisch' ) || false !== strpos( $n, 'politische jugendbildung' ) ) {
				return array( 'art' => 'politisch', 'grund' => $g );
			}
			if ( false !== strpos( $n, 'referent' ) ) {
				return array( 'art' => 'referent', 'grund' => $g );
			}
			if ( false !== strpos( $n, 'kompakt' ) ) {
				return array( 'art' => 'grundlage', 'grund' => $g );
			}
		}
		$f = array_values( (array) $themenfelder );
		return array( 'art' => 'spezialisierung', 'grund' => $f ? 'Themenfeld „' . $f[0] . '“' : 'kein Themenfeld' );
	}

	/**
	 * Voraussetzungen aus dem Feld „Voraussetzungen“ lesen.
	 *
	 * Erkannt werden Titel in »…«, „…“ oder "…", dazu ein Kurzname in
	 * Klammern direkt dahinter („… im Betrieb I« (Jugend I)“). Formal ist
	 * eine Voraussetzung, wenn von „Teilnahme am/an …“ die Rede ist – nicht,
	 * wenn sie nur empfohlen wird oder es Alternativen gibt („und/oder“).
	 *
	 * @return array [ [ 'titel' => …, 'kurz' => …, 'formal' => bool ], … ]
	 */
	public static function voraussetzungen_lesen( $text ) {
		$text = self::utf8( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === trim( $text ) ) {
			return array();
		}
		$n      = self::norm( $text );
		$formal = (bool) preg_match( '/teilnahme a[mn]\b/u', $n )
			&& ! preg_match( '/empf(ehl|ohl)/u', $n )
			&& ! preg_match( '/\boder\b/u', $n );
		$out = array();
		if ( preg_match_all( '/(?:»([^«]+)«|„([^“”"]+)[“”"]|"([^"]+)")(?:\s*\(([^)]{2,40})\))?/u', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$titel = trim( $x[1] . ( $x[2] ?? '' ) . ( $x[3] ?? '' ) );
				if ( '' === $titel ) {
					continue;
				}
				$kurz = isset( $x[4] ) ? trim( $x[4] ) : '';
				if ( '' !== $kurz && preg_match( '/^früher\b/ui', $kurz ) ) {
					$kurz = '';
				}
				$out[] = array( 'titel' => $titel, 'kurz' => $kurz, 'formal' => $formal );
			}
		}
		return $out;
	}

	/** Kern eines Namens: norm(), ohne Klammerzusätze. */
	private static function kern( $s ) {
		return trim( (string) preg_replace( '/\s*\([^)]*\)\s*/u', ' ', self::norm( $s ) ) );
	}

	/** Enthält $a das Wort/die Wortfolge $b – nicht mitten im Wort („… I“ ist nicht „… II“)? */
	private static function enthaelt_wort( $a, $b ) {
		if ( mb_strlen( $b ) < 4 ) {
			return $a === $b;
		}
		$pos = strpos( $a, $b );
		while ( false !== $pos ) {
			$vor  = $pos > 0 ? substr( $a, 0, $pos ) : '';
			$nach = (string) substr( $a, $pos + strlen( $b ) );
			if ( ! preg_match( '/[\p{L}\p{N}]$/u', $vor ) && ! preg_match( '/^[\p{L}\p{N}]/u', $nach ) ) {
				return true;
			}
			$pos = strpos( $a, $b, $pos + 1 );
		}
		return false;
	}

	/** Steht die Voraussetzung $v für diesen Halt? (Name, Punkte, Seminartitel) */
	public static function halt_erfuellt( $k, $v ) {
		$such = array_filter( array( self::kern( $v['titel'] ), self::kern( $v['kurz'] ?? '' ) ), 'strlen' );
		$hier = array( self::kern( $k['name'] ) );
		foreach ( array_merge( $k['punkte'], $k['titel'] ) as $x ) {
			$hier[] = self::kern( rtrim( $x, '*' ) );
		}
		foreach ( $such as $a ) {
			foreach ( array_filter( $hier, 'strlen' ) as $b ) {
				// Nur in eine Richtung: Der Halt nennt die Voraussetzung („Grundlagenbildung:
				// BR kompakt“ enthält „BR kompakt“). Umgekehrt träfe „Arbeits- und
				// Gesundheitsschutz“ fälschlich „Arbeits- und Gesundheitsschutz I“.
				if ( $a === $b || self::enthaelt_wort( $b, $a ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Voraussetzungen gegen die Strecke prüfen.
	 *
	 * @param array $kaesten Halte des Weges
	 * @param array $voraus  id => [ voraussetzungen_lesen()-Einträge ]
	 * @return array id => [ [ 'stufe' => 'warn'|'fehler', 'text' => …, 'pflicht_bei' => id|'' ], … ]
	 */
	public static function voraussetzung_befunde( $kaesten, $voraus ) {
		$out  = array();
		$lage = self::lage( $kaesten );
		foreach ( $kaesten as $k ) {
			$gesehen = array();
			foreach ( $voraus[ $k['id'] ] ?? array() as $v ) {
				$schl = self::kern( $v['titel'] );
				if ( isset( $gesehen[ $schl ] ) ) {
					continue;
				}
				$gesehen[ $schl ] = true;
				$ziel = null;
				foreach ( $kaesten as $t ) {
					// Referent*in ist nie Voraussetzung, nennt aber oft Seminartitel („Fit als Referent*in des Seminars …“).
					if ( $t['id'] !== $k['id'] && 'referent' !== $t['art'] && self::halt_erfuellt( $t, $v ) ) {
						$ziel = $t;
						break;
					}
				}
				if ( self::halt_erfuellt( $k, $v ) && ! $ziel ) {
					continue; // verweist auf sich selbst, z. B. Teil 2 setzt Teil 1 derselben Reihe voraus
				}
				$lz = $ziel ? ( $lage[ $ziel['id'] ] ?? null ) : null;
				$lk = $lage[ $k['id'] ] ?? null;
				if ( $ziel && $lz && $lk && $lz['weiche'] && $lz['weiche'] === $lk['weiche'] && $lz['ast'] !== $lk['ast'] ) {
					$out[ $k['id'] ][] = array( 'stufe' => $v['formal'] ? 'fehler' : 'warn', 'pflicht_bei' => '',
						'text' => '„' . $v['titel'] . '“ ' . ( $v['formal'] ? 'ist formal Voraussetzung' : 'wird vorher empfohlen' ) . ', liegt aber auf einem anderen Ast („' . $ziel['name'] . '“, Ast „' . $lz['ast'] . '“) – vor die Weiche legen' );
				} elseif ( $ziel && $ziel['stufe'] >= $k['stufe'] ) {
					$out[ $k['id'] ][] = $v['formal']
						? array( 'stufe' => 'fehler', 'pflicht_bei' => '',
							'text' => '„' . $v['titel'] . '“ ist formal Voraussetzung, liegt aber nicht vor diesem Halt („' . $ziel['name'] . '“) – Stufe erhöhen' )
						: array( 'stufe' => 'warn', 'pflicht_bei' => '',
							'text' => '„' . $v['titel'] . '“ wird vorher empfohlen, liegt aber nicht vor diesem Halt („' . $ziel['name'] . '“)' );
				} elseif ( $ziel && $v['formal'] && ! $ziel['pflicht'] ) {
					$out[ $k['id'] ][] = array( 'stufe' => 'warn', 'pflicht_bei' => $ziel['id'],
						'text' => 'setzt „' . $v['titel'] . '“ formal voraus – Haken „Voraussetzung“ bei „' . $ziel['name'] . '“ setzen' );
				} elseif ( ! $ziel && $v['formal'] ) {
					$out[ $k['id'] ][] = array( 'stufe' => 'warn', 'pflicht_bei' => '',
						'text' => 'setzt „' . $v['titel'] . '“ formal voraus – das steht auf keinem Halt dieses Weges' );
				}
			}
		}
		return $out;
	}

	/**
	 * Eine Startbelegung (JSON) lesen.
	 *
	 * @return array|null [linien => [...]] oder null, wenn die Datei fehlt/kaputt ist
	 */
	public static function lies_paket( $json ) {
		$d = json_decode( (string) $json, true );
		if ( ! is_array( $d ) || empty( $d['linien'] ) || ! is_array( $d['linien'] ) ) {
			return null;
		}
		$linien = array();
		foreach ( $d['linien'] as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$schl = sanitize_key( $l['schluessel'] ?? '' );
			if ( '' === $schl ) {
				continue;
			}
			$linien[] = array(
				'schluessel'   => $schl,
				'titel'        => self::zeile( $l['titel'] ?? $schl ),
				'zielgruppe'   => self::zeile( $l['zielgruppe'] ?? '' ),
				'einleitung'   => (string) ( $l['einleitung'] ?? '' ),
				'reihenfolge'  => isset( $l['reihenfolge'] ) ? (int) $l['reihenfolge'] : null,
				'haltestellen' => self::sanitize_kaesten( $l['haltestellen'] ?? array() ),
			);
		}
		return $linien ? array( 'linien' => $linien ) : null;
	}

	/**
	 * Was ein Import tun würde – rein, ohne Datenbank.
	 *
	 * Schlüssel ist der Abgleich: Gibt es ihn schon, wird der Weg nur mit
	 * $ueberschreiben ersetzt, sonst übersprungen. Neue Wege kommen als
	 * Entwurf. Doppelte Schlüssel in der Datei: der erste zählt.
	 *
	 * @param array $linien         aus lies_paket()
	 * @param array $da             Schlüssel => Beitrags-ID der vorhandenen Wege
	 * @param bool  $ueberschreiben vorhandene Wege ersetzen
	 * @return array [ [ 'aktion' => 'neu'|'ersetzen'|'behalten'|'doppelt', 'linie' => …, 'pid' => int ], … ]
	 */
	public static function import_plan( $linien, $da, $ueberschreiben ) {
		$out     = array();
		$gesehen = array();
		foreach ( $linien as $l ) {
			$schl = $l['schluessel'];
			if ( isset( $gesehen[ $schl ] ) ) {
				$out[] = array( 'aktion' => 'doppelt', 'linie' => $l, 'pid' => 0 );
				continue;
			}
			$gesehen[ $schl ] = true;
			$pid              = (int) ( $da[ $schl ] ?? 0 );
			if ( ! $pid ) {
				$out[] = array( 'aktion' => 'neu', 'linie' => $l, 'pid' => 0 );
			} else {
				$out[] = array( 'aktion' => $ueberschreiben ? 'ersetzen' : 'behalten', 'linie' => $l, 'pid' => $pid );
			}
		}
		return $out;
	}

	/* ===================================================================
	 *  Daten lesen
	 * =================================================================== */

	/** Kästen eines Weges. */
	public static function kaesten( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_HALTESTELLEN, true );
		$arr = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return self::sanitize_kaesten( is_array( $arr ) ? $arr : array() );
	}

	public static function speichere_kaesten( $post_id, $kaesten ) {
		update_post_meta( $post_id, self::META_HALTESTELLEN, wp_slash( wp_json_encode( array_values( $kaesten ), JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * Alle kommenden, sichtbaren Termine (Präsenz und Online) – eine Abfrage
	 * je Seitenaufruf. Titel in der Datenbank tragen teils doppelte
	 * Leerzeichen oder andere Striche; verglichen wird deshalb in PHP über
	 * norm(), nicht per SQL.
	 *
	 * @return array [ ['id'=>int,'titel'=>string,'start'=>'Y-m-d'], … ] nach Datum
	 */
	private static function kommende_termine() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		global $wpdb;
		$pts  = function_exists( 'bi_seminar_post_types' ) ? bi_seminar_post_types() : array( BI_CPT );
		$in   = implode( ',', array_fill( 0, count( $pts ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, m.meta_value AS start
			   FROM {$wpdb->posts} p
			  INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bi_startdatum'
			   LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_bi_anzeigen'
			  WHERE p.post_type IN ($in)
			    AND p.post_status = 'publish'
			    AND m.meta_value >= %s
			    AND ( a.meta_value IS NULL OR a.meta_value <> '0' )
			  ORDER BY m.meta_value ASC, p.ID ASC",
			array_merge( $pts, array( current_time( 'Y-m-d' ) ) )
		) );
		$cache = array();
		foreach ( (array) $rows as $r ) {
			$cache[] = array( 'id' => (int) $r->ID, 'titel' => (string) $r->post_title, 'start' => (string) $r->start, 'n' => self::norm( $r->post_title ) );
		}
		return $cache;
	}

	/**
	 * Seminare je Kasten aus einer Terminliste – rein, ohne Datenbank.
	 *
	 * @param array $kaesten Kästen
	 * @param array $termine [ ['id','titel','start'], … ] nach Datum
	 * @return array id => [ 'seminare' => [ ['titel','anzahl','naechster','id'] ], 'muster' => [muster => anzahl], 'anzahl' => n, 'naechster' => 'Y-m-d'|'' ]
	 */
	public static function seminare_je_kasten( $kaesten, $termine ) {
		$out = array();
		foreach ( $kaesten as $k ) {
			$gruppen = array(); // norm(titel) => seminar
			$muster  = array();
			$ids     = array();
			foreach ( $k['titel'] as $m ) {
				$muster[ $m ] = 0;
				$mn           = self::norm( $m );
				foreach ( $termine as $t ) {
					if ( ! self::passt_norm( $mn, $t['n'] ?? self::norm( $t['titel'] ) ) ) {
						continue;
					}
					$muster[ $m ]++;
					if ( isset( $ids[ $t['id'] ] ) ) {
						continue;
					}
					$ids[ $t['id'] ] = true;
					$g = $t['n'] ?? self::norm( $t['titel'] );
					if ( ! isset( $gruppen[ $g ] ) ) {
						// Erster Treffer = frühester Termin, weil die Liste nach Datum sortiert ist.
						$gruppen[ $g ] = array( 'titel' => self::zeile( $t['titel'] ), 'anzahl' => 0, 'naechster' => $t['start'], 'id' => $t['id'] );
					}
					$gruppen[ $g ]['anzahl']++;
				}
			}
			$naechster = '';
			foreach ( $gruppen as $g ) {
				if ( '' === $naechster || $g['naechster'] < $naechster ) {
					$naechster = $g['naechster'];
				}
			}
			$out[ $k['id'] ] = array(
				'seminare'  => array_values( $gruppen ),
				'muster'    => $muster,
				'anzahl'    => count( $ids ),
				'naechster' => $naechster,
			);
		}
		return $out;
	}

	/* ===================================================================
	 *  Pflege: Metaboxen
	 * =================================================================== */

	public static function admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'bi-fahrplan-admin', BI_URL . 'assets/css/fahrplan-admin.css', array(), self::ver( 'assets/css/fahrplan-admin.css' ) );
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			// Für die Live-Vorschau: dieselben Dateien wie auf der Website.
			wp_enqueue_style( 'bi-fahrplan', BI_URL . 'assets/css/fahrplan.css', array(), self::ver( 'assets/css/fahrplan.css' ) );
			wp_enqueue_script( 'bi-fahrplan', BI_URL . 'assets/js/fahrplan.js', array(), self::ver( 'assets/js/fahrplan.js' ), true );
			wp_enqueue_script( 'bi-fahrplan-admin', BI_URL . 'assets/js/fahrplan-admin.js', array( 'bi-fahrplan' ), self::ver( 'assets/js/fahrplan-admin.js' ), true );
			wp_localize_script( 'bi-fahrplan-admin', 'BIL_ADMIN', array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'bi_fahrplan_admin' ),
				'arten' => self::ARTEN,
			) );
		}
	}

	public static function add_meta_boxes() {
		add_meta_box( 'bi-linie-angaben', 'Angaben zum Weg', array( __CLASS__, 'render_angaben' ), self::CPT, 'side', 'high' );
		add_meta_box( 'bi-linie-pruefung', 'Prüfliste', array( __CLASS__, 'render_pruefung' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'bi-linie-haltestellen', 'Halte', array( __CLASS__, 'render_kaesten' ), self::CPT, 'normal', 'default' );
		add_meta_box( 'bi-linie-vorschau', 'Vorschau und Probelauf', array( __CLASS__, 'render_vorschau' ), self::CPT, 'normal', 'low' );
	}

	public static function render_angaben( $post ) {
		wp_nonce_field( 'bi_linie_speichern', 'bi_linie_nonce' );
		$schl = (string) get_post_meta( $post->ID, self::META_SCHLUESSEL, true );
		$ziel = (string) get_post_meta( $post->ID, self::META_ZIELGRUPPE, true );
		?>
		<p><label for="bil-schluessel"><strong>Schlüssel</strong></label><br>
			<input type="text" id="bil-schluessel" name="bil_schluessel" value="<?php echo esc_attr( $schl ); ?>" class="widefat" pattern="[a-z0-9-]+">
			<span class="description">Kurzname für Shortcode und Export, z. B. <code>betriebsrat</code>.</span></p>
		<p><label for="bil-zielgruppe"><strong>Für wen?</strong></label><br>
			<input type="text" id="bil-zielgruppe" name="bil_zielgruppe" value="<?php echo esc_attr( $ziel ); ?>" class="widefat">
			<span class="description">Erscheint als Knopf zur Auswahl, z. B. „Für Betriebsrät*innen“.</span></p>
		<p><strong>Auf einer Seite einbinden</strong><br>
			<code>[bi_fahrplan]</code> – alle veröffentlichten Wege zur Auswahl<br>
			<?php if ( '' !== $schl ) : ?>
				<code>[bi_fahrplan linie="<?php echo esc_html( $schl ); ?>"]</code> – nur dieser Weg<br>
			<?php endif; ?>
			<span class="description">Entwürfe sieht nur die Redaktion. Reihenfolge der Wege: Feld „Reihenfolge“.</span></p>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bi_fahrplan_export&linie=' . $post->ID ), 'bi_fahrplan_export' ) ); ?>">Weg als JSON exportieren</a></p>
		<?php
	}

	/** Prüfliste: was am Weg fehlt. Wird bei jedem Öffnen neu berechnet. */
	public static function render_pruefung( $post ) {
		$kaesten = self::kaesten( $post->ID );
		$treffer = self::seminare_je_kasten( $kaesten, self::kommende_termine() );
		$befunde = self::voraussetzung_befunde( $kaesten, self::voraussetzungen_je_kasten( $kaesten, $treffer ) );
		foreach ( self::weichen_befunde( $kaesten ) as $id => $liste ) {
			$befunde[ $id ] = array_merge( $befunde[ $id ] ?? array(), $liste );
		}
		$zeilen  = self::pruef_zeilen( $kaesten, $treffer, $befunde );
		$zaehler = array( 'ok' => 0, 'warn' => 0, 'fehler' => 0 );
		foreach ( $zeilen as $z ) {
			$zaehler[ $z['stufe'] ]++;
		}
		?>
		<div class="bil-pruefung">
			<p class="bil-summe">
				<span class="bil-chip bil-ok"><?php echo (int) $zaehler['ok']; ?> in Ordnung</span>
				<span class="bil-chip bil-warn"><?php echo (int) $zaehler['warn']; ?> mit Hinweis</span>
				<span class="bil-chip bil-fehler"><?php echo (int) $zaehler['fehler']; ?> mit Fehler</span>
			</p>
			<?php if ( $zaehler['warn'] + $zaehler['fehler'] ) : ?>
			<table class="widefat striped bil-pruef-tabelle">
				<thead><tr><th>ID</th><th>Kasten</th><th>Befund</th></tr></thead>
				<tbody>
				<?php foreach ( $zeilen as $z ) : ?>
					<?php
					if ( 'ok' === $z['stufe'] ) {
						continue;
					}
					?>
					<tr class="bil-<?php echo esc_attr( $z['stufe'] ); ?>">
						<td><?php echo esc_html( $z['id'] ); ?></td>
						<td><?php echo esc_html( $z['name'] ); ?></td>
						<td><?php echo esc_html( implode( ' · ', $z['befund'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Kasten-Editor. Die Felder tragen KEINE name-Attribute: Beim Absenden
	 * packt fahrplan-admin.js alles in ein einziges JSON-Feld – so bleibt
	 * auch ein großer Weg unter PHPs max_input_vars.
	 */
	public static function render_kaesten( $post ) {
		$kaesten = self::kaesten( $post->ID );
		?>
		<input type="hidden" name="bil_haltestellen_json" id="bil-haltestellen-json" value="">
		<div class="bil-neu-aus-seminar">
			<label for="bil-suche-neu"><strong>Neuer Halt aus einem Seminar</strong></label>
			<div class="bil-suche" data-bil-suche="neu">
				<input type="search" id="bil-suche-neu" placeholder="Titel, Seminarnummer oder Themenfeld eingeben …" autocomplete="off">
				<ul class="bil-suche__treffer" hidden></ul>
			</div>
			<p class="description">Legt einen Halt mit Titel, Art, Punkten und Fragen-Vorschlägen aus den Themen an. Alles lässt sich danach ändern.</p>
		</div>
		<details class="bil-hilfe"><summary>So funktioniert ein Halt</summary>
			<ul>
				<li><strong>Stufe</strong>: Abschnitt der Strecke (1 = Einstieg). Halte mit <em>gleicher</em> Stufe folgen nacheinander im selben Abschnitt, z. B. JAV-Seminar und danach Jugend I im Einstieg. Beim Quereinstieg wird ein Abschnitt ganz abgefragt.</li>
				<li><strong>Seminartitel</strong>: einer je Zeile, am besten über die Suche. Ein <code>*</code> am Ende heißt „beginnt mit“ (für alle Teile einer Reihe).</li>
				<li><strong>Fragen</strong>: bis zu drei Ja/Nein-Fragen in Du-Form. Die erste Antwort „nein“ ist der Einstieg. „Fragen vorschlagen“ holt Vorschläge aus den Themen der Seminare.</li>
				<li><strong>Voraussetzung</strong>: Haken setzen, wenn die Teilnahme formal Voraussetzung für spätere Halte ist. Die Prüfliste oben erkennt das aus dem Feld „Voraussetzungen“ der Seminare.</li>
				<li><strong>Ast (Weiche)</strong>: leer = Hauptstrecke. Teilt sich der Weg, bekommt jeder Halt hinter der Weiche den Namen seines Astes, z. B. „Betriebspolitik“ und „Politische Bildung“ – Halte mit gleichem Namen fahren auf demselben Ast. Danach geht es auf der Hauptstrecke weiter (Halte ohne Ast mit höherer Stufe), oder die Äste enden. Auf der Website laufen die Äste nebeneinander; die Einstiegshilfe fragt an der Weiche nach der Richtung.</li>
				<li>Referent*in und „optional, hilfreich“ werden nicht abgefragt.</li>
			</ul>
		</details>
		<datalist id="bil-aeste-liste">
			<?php foreach ( array_unique( array_filter( array_column( $kaesten, 'ast' ), 'strlen' ) ) as $a ) : ?>
				<option value="<?php echo esc_attr( $a ); ?>"></option>
			<?php endforeach; ?>
		</datalist>
		<div id="bil-kaesten">
			<?php
			foreach ( $kaesten as $k ) {
				self::render_kasten_form( $k );
			}
			?>
		</div>
		<template id="bil-kasten-vorlage"><?php self::render_kasten_form( self::sanitize_kasten( array( 'stufe' => 2 ) ) ); ?></template>
		<p><button type="button" class="button" id="bil-kasten-neu">Leeren Halt hinzufügen</button></p>
		<?php
	}

	/** Ein Kasten als Formularblock. */
	private static function render_kasten_form( $k ) {
		?>
		<fieldset class="bil-kasten-form bil-art--<?php echo esc_attr( $k['art'] ); ?>">
			<div class="bil-kf-kopf">
				<input type="text" data-f="id" value="<?php echo esc_attr( $k['id'] ); ?>" size="4" aria-label="ID">
				<input type="text" data-f="name" value="<?php echo esc_attr( $k['name'] ); ?>" class="bil-kf-name" placeholder="Überschrift wie im Heft" aria-label="Überschrift">
				<label title="Gleiche Stufe = selber Abschnitt, Halte folgen nacheinander">Stufe <select data-f="stufe">
					<?php for ( $i = 1; $i <= self::MAX_STUFE; $i++ ) : ?>
						<option value="<?php echo (int) $i; ?>" <?php selected( $k['stufe'], $i ); ?>><?php echo (int) $i; ?></option>
					<?php endfor; ?>
				</select></label>
				<label>Art <select data-f="art">
					<?php foreach ( self::ARTEN as $a => $l ) : ?>
						<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $k['art'], $a ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></label>
				<label title="Leer = Hauptstrecke. Halte mit verschiedenen Ästen in derselben Stufe bilden eine Weiche.">Ast <input type="text" data-f="ast" value="<?php echo esc_attr( $k['ast'] ); ?>" list="bil-aeste-liste" size="14" placeholder="Hauptstrecke"></label>
				<label><input type="checkbox" data-f="regional" <?php checked( $k['regional'] ); ?>> regional</label>
				<label title="Teilnahme ist formale Voraussetzung für den nächsten Schritt"><input type="checkbox" data-f="pflicht" <?php checked( $k['pflicht'] ); ?>> Voraussetzung</label>
				<span class="bil-kf-knoepfe">
					<button type="button" class="button-link bil-hoch" aria-label="nach oben">▲</button>
					<button type="button" class="button-link bil-runter" aria-label="nach unten">▼</button>
					<button type="button" class="button-link bil-loeschen" aria-label="Kasten entfernen">✕</button>
				</span>
			</div>
			<div class="bil-kf-raster">
				<label>Punkte im Kasten (eine je Zeile)<textarea data-f="punkte" rows="4"><?php echo esc_textarea( implode( "\n", $k['punkte'] ) ); ?></textarea></label>
				<div class="bil-kf-titel">
					<label>Seminartitel (einer je Zeile)<textarea data-f="titel" rows="4"><?php echo esc_textarea( implode( "\n", $k['titel'] ) ); ?></textarea></label>
					<div class="bil-suche" data-bil-suche="dazu">
						<input type="search" placeholder="Seminar suchen und hinzufügen …" autocomplete="off" aria-label="Seminar suchen und hinzufügen">
						<ul class="bil-suche__treffer" hidden></ul>
					</div>
				</div>
				<label class="bil-voll">Fragen zum Quereinstieg (bis zu drei, eine je Zeile)<textarea data-f="fragen" rows="3"><?php echo esc_textarea( implode( "\n", $k['fragen'] ) ); ?></textarea></label>
				<div class="bil-voll bil-kf-hilfen">
					<button type="button" class="button bil-vorschlagen">Fragen vorschlagen</button>
					<span class="description">aus den Themen der Seminare – übernehmen per Klick, eigene Fragen bleiben möglich</span>
					<div class="bil-vorschlaege" hidden></div>
				</div>
			</div>
		</fieldset>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['bi_linie_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bi_linie_nonce'] ) ), 'bi_linie_speichern' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( BI_CAP ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_SCHLUESSEL, sanitize_title( wp_unslash( $_POST['bil_schluessel'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_ZIELGRUPPE, sanitize_text_field( wp_unslash( $_POST['bil_zielgruppe'] ?? '' ) ) );

		// Ohne JavaScript kommt kein JSON an – dann bleiben die Kästen, wie sie sind.
		$json = isset( $_POST['bil_haltestellen_json'] ) ? (string) wp_unslash( $_POST['bil_haltestellen_json'] ) : '';
		$roh  = '' !== $json ? json_decode( $json, true ) : null;
		if ( is_array( $roh ) ) {
			self::speichere_kaesten( $post_id, self::sanitize_kaesten( $roh ) );
		}
	}

	/* ===================================================================
	 *  Startbelegung und Export
	 * =================================================================== */

	/** Schlüssel der schon angelegten Wege. */
	private static function vorhandene_schluessel() {
		$out = array();
		foreach ( get_posts( array(
			'post_type'      => self::CPT,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) ) as $pid ) {
			$out[ (string) get_post_meta( $pid, self::META_SCHLUESSEL, true ) ] = $pid;
		}
		return $out;
	}

	/** Startbelegung aus der mitgelieferten Datei, oder null. */
	public static function startbelegung() {
		$datei = BI_PATH . self::STARTBELEGUNG;
		if ( ! file_exists( $datei ) ) {
			return null;
		}
		return self::lies_paket( file_get_contents( $datei ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/** Hinweis mit Knopf in der Liste, solange Wege der Startbelegung fehlen. */
	public static function hinweis_startbelegung() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::CPT !== $screen->id || ! current_user_can( BI_CAP ) ) {
			return;
		}
		if ( isset( $_GET['bil_angelegt'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-success is-dismissible"><p>%d Bildungswege als Entwurf angelegt. Ansehen, dann veröffentlichen.</p></div>', (int) $_GET['bil_angelegt'] ); // phpcs:ignore
		}
		$paket = self::startbelegung();
		if ( ! $paket ) {
			return;
		}
		$da     = self::vorhandene_schluessel();
		$fehlen = array();
		foreach ( $paket['linien'] as $l ) {
			if ( empty( $da[ $l['schluessel'] ] ) ) {
				$fehlen[] = $l['titel'];
			}
		}
		if ( ! $fehlen ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=bi_fahrplan_start' ), 'bi_fahrplan_start' );
		printf(
			'<div class="notice notice-info"><p><strong>Bildungswege aus dem Heft 2027:</strong> %s</p><p><a class="button button-primary" href="%s">Als Entwurf anlegen</a></p></div>',
			esc_html( implode( ', ', $fehlen ) ),
			esc_url( $url )
		);
	}

	/** Fehlende Wege der Startbelegung als Entwurf anlegen. Vorhandene bleiben unberührt. */
	public static function handle_startbelegung() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_fahrplan_start' );
		$paket = self::startbelegung();
		$n     = 0;
		if ( $paket ) {
			$da = self::vorhandene_schluessel();
			foreach ( $paket['linien'] as $i => $l ) {
				if ( ! empty( $da[ $l['schluessel'] ] ) ) {
					continue;
				}
				$id = wp_insert_post( array(
					'post_type'    => self::CPT,
					'post_status'  => 'draft',
					'post_title'   => $l['titel'],
					'post_content' => wp_kses_post( $l['einleitung'] ),
					'menu_order'   => $i,
				), true );
				if ( is_wp_error( $id ) || ! $id ) {
					continue;
				}
				update_post_meta( $id, self::META_SCHLUESSEL, $l['schluessel'] );
				update_post_meta( $id, self::META_ZIELGRUPPE, $l['zielgruppe'] );
				self::speichere_kaesten( $id, $l['haltestellen'] );
				$n++;
			}
		}
		wp_safe_redirect( add_query_arg( 'bil_angelegt', $n, admin_url( 'edit.php?post_type=' . self::CPT ) ) );
		exit;
	}

	/** Einen Weg als JSON herunterladen (gleiche Form wie die Startbelegung). */
	public static function handle_export() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_fahrplan_export' );
		// linie=ID: ein Weg (Knopf im Editor), ohne: alle Wege (Knopf in der Liste).
		$pid = absint( $_GET['linie'] ?? 0 );
		if ( $pid ) {
			$post = get_post( $pid );
			if ( ! $post || self::CPT !== $post->post_type ) {
				wp_die( 'Weg nicht gefunden.' );
			}
			$posts = array( $post );
			$schl  = (string) get_post_meta( $pid, self::META_SCHLUESSEL, true );
			$datei = 'bildungsweg-' . sanitize_file_name( $schl ? $schl : 'weg-' . $pid );
		} else {
			$posts = get_posts( array(
				'post_type'      => self::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'no_found_rows'  => true,
			) );
			$datei = 'bildungswege-' . current_time( 'Y-m-d' );
		}
		$linien = array();
		foreach ( $posts as $p ) {
			$linien[] = array(
				'schluessel'   => (string) get_post_meta( $p->ID, self::META_SCHLUESSEL, true ),
				'titel'        => $p->post_title,
				'zielgruppe'   => (string) get_post_meta( $p->ID, self::META_ZIELGRUPPE, true ),
				'einleitung'   => $p->post_content,
				'reihenfolge'  => (int) $p->menu_order,
				'haltestellen' => self::kaesten( $p->ID ),
			);
		}
		$paket = array(
			'version' => 2,
			'stand'   => current_time( 'Y-m-d' ),
			'linien'  => $linien,
		);
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $datei . '.json"' );
		echo wp_json_encode( $paket, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/** Höchstens so groß darf eine Import-Datei sein (fünf Wege ≈ 40 KB). */
	const IMPORT_MAX_BYTES = 2097152;

	/**
	 * Kasten „Exportieren / Importieren“ über der Liste der Wege. Steht als
	 * Hinweis über dem Listenformular – ein Upload-Formular darf nicht in
	 * einem anderen Formular stecken.
	 */
	public static function render_austausch() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::CPT !== $screen->id || ! current_user_can( BI_CAP ) ) {
			return;
		}
		// Ergebnis des letzten Imports
		$r = get_transient( 'bil_import_' . get_current_user_id() );
		if ( is_array( $r ) ) {
			delete_transient( 'bil_import_' . get_current_user_id() );
			$ok = empty( $r['fehler'] );
			echo '<div class="notice notice-' . ( $ok ? 'success' : 'error' ) . ' is-dismissible"><p><strong>Import Bildungswege:</strong> ' . esc_html( $r['text'] ) . '</p>';
			if ( ! empty( $r['zeilen'] ) ) {
				echo '<ul style="margin:0 0 8px 18px;list-style:disc">';
				foreach ( $r['zeilen'] as $z ) {
					echo '<li>' . esc_html( $z ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}
		$n = (int) ( wp_count_posts( self::CPT )->publish ?? 0 ) + (int) ( wp_count_posts( self::CPT )->draft ?? 0 );
		?>
		<div class="notice notice-info bil-austausch" style="padding:10px 12px">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;margin:0">
				<strong>Bildungswege austauschen</strong>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bi_fahrplan_export' ), 'bi_fahrplan_export' ) ); ?>"<?php echo $n ? '' : ' aria-disabled="true" style="pointer-events:none;opacity:.5"'; ?>>Alle Wege exportieren (JSON)</a>
				<span style="border-left:1px solid #c3c4c7;height:24px"></span>
				<input type="hidden" name="action" value="bi_fahrplan_import">
				<?php wp_nonce_field( 'bi_fahrplan_import', 'bil_import_nonce' ); ?>
				<label>JSON-Datei <input type="file" name="bil_datei" accept=".json,application/json" required></label>
				<label title="Ohne Haken bleiben Wege mit gleichem Schlüssel unverändert"><input type="checkbox" name="bil_ueberschreiben" value="1"> Wege mit gleichem Schlüssel ersetzen</label>
				<button type="submit" class="button button-primary">Importieren</button>
			</form>
			<p class="description" style="margin:6px 0 0">Abgleich über den <em>Schlüssel</em>. Neue Wege kommen als Entwurf. Ersetzte Wege behalten ihren Status (veröffentlicht bleibt veröffentlicht), Titel, Einleitung, Zielgruppe und Halte kommen aus der Datei. Einzelne Wege exportiert der Knopf im Editor eines Weges.</p>
		</div>
		<?php
	}

	/** Import einer JSON-Datei (Form wie Export und Startbelegung). */
	public static function handle_import() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_fahrplan_import', 'bil_import_nonce' );
		$zurueck = admin_url( 'edit.php?post_type=' . self::CPT );
		$melde   = function ( $text, $zeilen = array(), $fehler = false ) use ( $zurueck ) {
			set_transient( 'bil_import_' . get_current_user_id(), array( 'text' => $text, 'zeilen' => $zeilen, 'fehler' => $fehler ), 300 );
			wp_safe_redirect( $zurueck );
			exit;
		};

		$f = $_FILES['bil_datei'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $f || UPLOAD_ERR_OK !== (int) ( $f['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $f['tmp_name'] ) ) {
			$melde( 'Keine Datei angekommen.', array(), true );
		}
		if ( (int) $f['size'] > self::IMPORT_MAX_BYTES ) {
			$melde( 'Die Datei ist zu groß (höchstens 2 MB).', array(), true );
		}
		$paket = self::lies_paket( file_get_contents( $f['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $paket ) {
			$melde( 'Die Datei ist keine Bildungswege-Datei (erwartet: JSON mit „linien“ und je Weg einem „schluessel“). Nichts geändert.', array(), true );
		}

		$ueber  = ! empty( $_POST['bil_ueberschreiben'] );
		$plan   = self::import_plan( $paket['linien'], self::vorhandene_schluessel(), $ueber );
		$zahl   = array( 'neu' => 0, 'ersetzen' => 0, 'behalten' => 0, 'doppelt' => 0 );
		$zeilen = array();
		foreach ( $plan as $schritt ) {
			$l = $schritt['linie'];
			if ( 'neu' === $schritt['aktion'] ) {
				$id = wp_insert_post( array(
					'post_type'    => self::CPT,
					'post_status'  => 'draft',
					'post_title'   => $l['titel'],
					'post_content' => wp_kses_post( $l['einleitung'] ),
					'menu_order'   => null !== $l['reihenfolge'] ? $l['reihenfolge'] : 0,
				), true );
				if ( is_wp_error( $id ) || ! $id ) {
					$zeilen[] = '„' . $l['titel'] . '“: konnte nicht angelegt werden.';
					continue;
				}
				update_post_meta( $id, self::META_SCHLUESSEL, $l['schluessel'] );
				update_post_meta( $id, self::META_ZIELGRUPPE, $l['zielgruppe'] );
				self::speichere_kaesten( $id, $l['haltestellen'] );
				$zeilen[] = '„' . $l['titel'] . '“ (' . $l['schluessel'] . '): neu als Entwurf, ' . count( $l['haltestellen'] ) . ' Halte.';
			} elseif ( 'ersetzen' === $schritt['aktion'] ) {
				$daten = array(
					'ID'           => $schritt['pid'],
					'post_title'   => $l['titel'],
					'post_content' => wp_kses_post( $l['einleitung'] ),
				);
				if ( null !== $l['reihenfolge'] ) {
					$daten['menu_order'] = $l['reihenfolge'];
				}
				// save() hängt an save_post – ohne Nonce tut es nichts, die Meta schreiben wir hier.
				$id = wp_update_post( $daten, true );
				if ( is_wp_error( $id ) ) {
					$zeilen[] = '„' . $l['titel'] . '“: konnte nicht ersetzt werden.';
					continue;
				}
				update_post_meta( $schritt['pid'], self::META_ZIELGRUPPE, $l['zielgruppe'] );
				self::speichere_kaesten( $schritt['pid'], $l['haltestellen'] );
				$zeilen[] = '„' . $l['titel'] . '“ (' . $l['schluessel'] . '): ersetzt, ' . count( $l['haltestellen'] ) . ' Halte.';
			} elseif ( 'behalten' === $schritt['aktion'] ) {
				$zeilen[] = '„' . $l['titel'] . '“ (' . $l['schluessel'] . '): gibt es schon – unverändert.';
			} else {
				$zeilen[] = '„' . $l['titel'] . '“ (' . $l['schluessel'] . '): Schlüssel steht doppelt in der Datei – übersprungen.';
			}
			$zahl[ $schritt['aktion'] ]++;
		}
		$teile = array();
		foreach ( array( 'neu' => 'neu angelegt', 'ersetzen' => 'ersetzt', 'behalten' => 'unverändert', 'doppelt' => 'doppelt' ) as $k => $wort ) {
			if ( $zahl[ $k ] ) {
				$teile[] = $zahl[ $k ] . ' ' . $wort;
			}
		}
		$melde( ( $teile ? implode( ', ', $teile ) : 'nichts geändert' ) . '.' . ( $zahl['behalten'] ? ' Zum Ersetzen den Haken „Wege mit gleichem Schlüssel ersetzen“ setzen.' : '' ), $zeilen );
	}

	/* ===================================================================
	 *  Hilfen beim Anlegen (Datenbank, Ajax)
	 * =================================================================== */

	/** Voraussetzungen je Halt aus dem Feld der Seminare (je Seminar der früheste Termin). */
	private static function voraussetzungen_je_kasten( $kaesten, $treffer ) {
		$out = array();
		foreach ( $kaesten as $k ) {
			$out[ $k['id'] ] = array();
			foreach ( $treffer[ $k['id'] ]['seminare'] ?? array() as $sem ) {
				$out[ $k['id'] ] = array_merge( $out[ $k['id'] ], self::voraussetzungen_lesen( get_post_meta( $sem['id'], '_bi_voraussetzungen', true ) ) );
			}
		}
		return $out;
	}

	/** Ajax-Aufrufe der Pflege absichern. */
	private static function ajax_pruefen() {
		if ( ! current_user_can( BI_CAP ) || ! check_ajax_referer( 'bi_fahrplan_admin', 'nonce', false ) ) {
			wp_send_json_error( 'Keine Berechtigung.', 403 );
		}
	}

	/** Themenfelder eines Termins als Namen. */
	private static function themenfelder( $post_id ) {
		$t = wp_get_object_terms( (int) $post_id, BI_TAX_THEMA, array( 'fields' => 'names' ) );
		return is_wp_error( $t ) ? array() : array_values( $t );
	}

	/**
	 * Seminarsuche: Titel (Teilwort), Seminarnummer oder Themenfeld. Liefert
	 * Seminare (gruppiert nach Titel) mit Terminzahl, nächstem Termin und
	 * Hinweis „online“.
	 */
	public static function ajax_suche() {
		self::ajax_pruefen();
		$q = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
		$n = self::norm( $q );
		if ( mb_strlen( $n ) < 2 ) {
			wp_send_json_success( array() );
		}
		$ids = array();
		// Seminarnummer
		if ( preg_match( '/\d{3,}/', $q ) ) {
			global $wpdb;
			foreach ( (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bi_seminarnummer' AND meta_value LIKE %s LIMIT 200",
				'%' . $wpdb->esc_like( $q ) . '%'
			) ) as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		// Themenfeld
		$terms = get_terms( array( 'taxonomy' => BI_TAX_THEMA, 'name__like' => $q, 'hide_empty' => true, 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && $terms ) {
			foreach ( (array) get_objects_in_term( $terms, BI_TAX_THEMA ) as $id ) {
				$ids[ (int) $id ] = true;
			}
		}
		$gruppen = array();
		foreach ( self::kommende_termine() as $t ) {
			if ( false === strpos( $t['n'], $n ) && ! isset( $ids[ $t['id'] ] ) ) {
				continue;
			}
			$g = $t['n'];
			if ( ! isset( $gruppen[ $g ] ) ) {
				if ( count( $gruppen ) >= 25 ) {
					continue;
				}
				$gruppen[ $g ] = array( 'titel' => self::zeile( $t['titel'] ), 'anzahl' => 0, 'naechster' => $t['start'], 'online' => 0, 'id' => $t['id'] );
			}
			$gruppen[ $g ]['anzahl']++;
			if ( defined( 'BI_ONLINE' ) && BI_ONLINE === get_post_type( $t['id'] ) ) {
				$gruppen[ $g ]['online']++;
			}
		}
		$out = array();
		foreach ( $gruppen as $g ) {
			$g['themenfeld'] = implode( ', ', self::themenfelder( $g['id'] ) );
			$g['naechster']  = date_i18n( 'd.m.Y', strtotime( $g['naechster'] ) );
			unset( $g['id'] );
			$out[] = $g;
		}
		// Treffer im Titelanfang zuerst
		usort( $out, function ( $a, $b ) use ( $n ) {
			$pa = 0 === strpos( self::norm( $a['titel'] ), $n ) ? 0 : 1;
			$pb = 0 === strpos( self::norm( $b['titel'] ), $n ) ? 0 : 1;
			return $pa - $pb ?: $b['anzahl'] - $a['anzahl'];
		} );
		wp_send_json_success( $out );
	}

	/**
	 * Analyse eines Halts aus seinen Seminartiteln: Stichpunkte der Themen,
	 * Fragen-Vorschläge, Punkte, Art, Voraussetzungen. Alles nur Vorschlag.
	 */
	public static function ajax_analyse() {
		self::ajax_pruefen();
		$titel = self::liste( wp_unslash( $_POST['titel'] ?? '' ) );
		$k     = self::sanitize_kasten( array( 'id' => 'X', 'name' => 'x', 'titel' => $titel ) );
		$t     = self::seminare_je_kasten( array( $k ), self::kommende_termine() )['X'];
		$sp    = array();
		$felder = array();
		$voraus = array();
		foreach ( array_slice( $t['seminare'], 0, 8 ) as $sem ) {
			foreach ( self::stichpunkte( get_post_meta( $sem['id'], '_bi_themen', true ) ) as $x ) {
				if ( ! in_array( $x, $sp, true ) ) {
					$sp[] = $x;
				}
			}
			$felder = array_values( array_unique( array_merge( $felder, self::themenfelder( $sem['id'] ) ) ) );
			foreach ( self::voraussetzungen_lesen( get_post_meta( $sem['id'], '_bi_voraussetzungen', true ) ) as $v ) {
				$voraus[ self::kern( $v['titel'] ) ] = $v;
			}
		}
		$art = self::art_vorschlag( $felder, $titel ? $titel[0] : '' );
		wp_send_json_success( array(
			'seminare'        => count( $t['seminare'] ),
			'termine'         => $t['anzahl'],
			'stichpunkte'     => array_slice( $sp, 0, 12 ),
			'fragen'          => self::fragen_vorschlaege( $sp, 6 ),
			'punkte'          => array_slice( array_values( array_unique( array_map( array( __CLASS__, 'kurzform' ), $sp ) ) ), 0, 4 ),
			'art'             => $art['art'],
			'art_grund'       => $art['grund'],
			'voraussetzungen' => array_values( $voraus ),
		) );
	}

	/** Live-Vorschau eines Weges mit den (ungespeicherten) Halten aus dem Editor. */
	public static function ajax_vorschau() {
		self::ajax_pruefen();
		$roh = json_decode( (string) wp_unslash( $_POST['kaesten'] ?? '' ), true );
		$html = self::render_weg_daten( array(
			'titel'      => sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) ),
			'ziel'       => sanitize_text_field( wp_unslash( $_POST['ziel'] ?? '' ) ),
			'einleitung' => '',
			'entwurf'    => false,
			'kaesten'    => self::sanitize_kaesten( is_array( $roh ) ? $roh : array() ),
		), 'bildungsweg-vorschau', self::kommende_termine() );
		wp_send_json_success( '<div class="bil" data-bil>' . $html . '</div>' );
	}

	/** Metabox „Vorschau und Probelauf“. Inhalt lädt fahrplan-admin.js. */
	public static function render_vorschau( $post ) {
		?>
		<p><button type="button" class="button" id="bil-vorschau-neu">Vorschau aktualisieren</button>
			<span class="description">So erscheint der Weg auf der Website – mit den Änderungen oben, auch ungespeichert. Die Fragen lassen sich hier durchklicken.</span></p>
		<div id="bil-vorschau" class="bil-vorschau"></div>
		<?php
	}

	/** „Duplizieren“ in der Liste der Wege. */
	public static function zeilen_aktionen( $aktionen, $post ) {
		if ( self::CPT === $post->post_type && current_user_can( BI_CAP ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=bi_fahrplan_kopie&linie=' . $post->ID ), 'bi_fahrplan_kopie_' . $post->ID );
			$aktionen['bil_kopie'] = '<a href="' . esc_url( $url ) . '">Duplizieren</a>';
		}
		return $aktionen;
	}

	/** Weg als Entwurf kopieren (ohne Schlüssel – den vergibt man neu). */
	public static function handle_kopie() {
		$pid = absint( $_GET['linie'] ?? 0 );
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_fahrplan_kopie_' . $pid );
		$post = $pid ? get_post( $pid ) : null;
		if ( ! $post || self::CPT !== $post->post_type ) {
			wp_die( 'Weg nicht gefunden.' );
		}
		$id = wp_insert_post( array(
			'post_type'    => self::CPT,
			'post_status'  => 'draft',
			'post_title'   => 'Kopie von ' . $post->post_title,
			'post_content' => $post->post_content,
			'menu_order'   => $post->menu_order + 1,
		), true );
		if ( is_wp_error( $id ) || ! $id ) {
			wp_die( 'Kopie fehlgeschlagen.' );
		}
		update_post_meta( $id, self::META_ZIELGRUPPE, (string) get_post_meta( $pid, self::META_ZIELGRUPPE, true ) );
		self::speichere_kaesten( $id, self::kaesten( $pid ) );
		wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $id ) );
		exit;
	}

	public static function admin_columns( $cols ) {
		$neu = array();
		foreach ( $cols as $k => $v ) {
			$neu[ $k ] = $v;
			if ( 'title' === $k ) {
				$neu['bil_schluessel'] = 'Schlüssel';
				$neu['bil_kaesten']    = 'Halte';
			}
		}
		return $neu;
	}

	public static function admin_column_content( $col, $post_id ) {
		if ( 'bil_schluessel' === $col ) {
			echo esc_html( (string) get_post_meta( $post_id, self::META_SCHLUESSEL, true ) );
		} elseif ( 'bil_kaesten' === $col ) {
			echo (int) count( self::kaesten( $post_id ) );
		}
	}

	/* ===================================================================
	 *  Frontend [bi_fahrplan]
	 * =================================================================== */

	public static function register_assets() {
		wp_register_style( 'bi-fahrplan', BI_URL . 'assets/css/fahrplan.css', array(), self::ver( 'assets/css/fahrplan.css' ) );
		wp_register_script( 'bi-fahrplan', BI_URL . 'assets/js/fahrplan.js', array(), self::ver( 'assets/js/fahrplan.js' ), true );
	}

	/**
	 * Version einer Datei für den Browser-Cache: Plugin-Version plus
	 * Änderungszeit. Beim Handdeploy ändert sich oft nur die Datei, nicht die
	 * Plugin-Version – ohne Zeitstempel behielte der Browser die alte Fassung.
	 */
	private static function ver( $datei ) {
		$t = @filemtime( BI_PATH . $datei ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return BI_VERSION . ( $t ? '.' . $t : '' );
	}

	/**
	 * Wege für die Ausgabe: alle veröffentlichten (Redaktion sieht auch
	 * Entwürfe), nach Reihenfolge. Mit Schlüssel nur dieser eine.
	 */
	private static function wege( $schluessel ) {
		$status = current_user_can( BI_CAP ) ? array( 'publish', 'draft', 'pending', 'private' ) : array( 'publish' );
		$out    = array();
		foreach ( get_posts( array(
			'post_type'      => self::CPT,
			'post_status'    => $status,
			'posts_per_page' => -1,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'no_found_rows'  => true,
		) ) as $p ) {
			if ( '' === $schluessel || get_post_meta( $p->ID, self::META_SCHLUESSEL, true ) === $schluessel ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/** [bi_fahrplan] oder [bi_fahrplan linie="betriebsrat"] */
	public static function shortcode( $atts ) {
		if ( ! self::aktiv() ) {
			return '';
		}
		$a    = shortcode_atts( array( 'linie' => '', 'weg' => '' ), $atts, 'bi_fahrplan' );
		$schl = sanitize_key( '' !== $a['weg'] ? $a['weg'] : $a['linie'] );
		$wege = self::wege( $schl );
		if ( ! $wege ) {
			return current_user_can( BI_CAP )
				? '<p class="bil-leer"><strong>Bildungsfahrplan:</strong> '
					. ( '' !== $schl ? 'Weg „' . esc_html( $schl ) . '“ nicht gefunden.' : 'Noch keine Bildungswege angelegt.' )
					. ' Unter Bildungsprogramm → Bildungswege anlegen oder Schlüssel prüfen.</p>'
				: '';
		}

		wp_enqueue_style( 'bi-fahrplan' );
		wp_enqueue_script( 'bi-fahrplan' );

		$termine = self::kommende_termine();
		$wahl    = '';
		$inhalt  = '';
		foreach ( $wege as $i => $w ) {
			$schl_w = (string) get_post_meta( $w->ID, self::META_SCHLUESSEL, true );
			$anker  = 'bildungsweg-' . ( '' !== $schl_w ? $schl_w : $w->ID );
			$ziel   = (string) get_post_meta( $w->ID, self::META_ZIELGRUPPE, true );
			$wahl  .= '<button type="button" class="bil-wahl__knopf" role="tab" id="' . esc_attr( $anker ) . '-tab"'
				. ' aria-controls="' . esc_attr( $anker ) . '" aria-selected="' . ( 0 === $i ? 'true' : 'false' ) . '"'
				. ' data-bil-tab="' . esc_attr( $anker ) . '">' . esc_html( '' !== $ziel ? $ziel : get_the_title( $w ) ) . '</button>';
			$inhalt .= self::render_weg( $w, $anker, $termine, count( $wege ) > 1, 0 !== $i );
		}

		$nav = count( $wege ) > 1
			? '<div class="bil-wahl" role="tablist" aria-label="Bildungsweg wählen">' . $wahl . '</div>'
			: '';

		return '<div class="bil" data-bil>' . $nav . $inhalt . self::legende() . '</div>';
	}

	/** Ein Weg aus einem Beitrag. */
	private static function render_weg( $w, $anker, $termine, $als_tab, $versteckt ) {
		return self::render_weg_daten( array(
			'titel'      => get_the_title( $w ),
			'ziel'       => (string) get_post_meta( $w->ID, self::META_ZIELGRUPPE, true ),
			'einleitung' => (string) $w->post_content,
			'entwurf'    => 'publish' !== $w->post_status,
			'kaesten'    => self::kaesten( $w->ID ),
		), $anker, $termine, $als_tab, $versteckt );
	}

	/**
	 * Halte der Strecke: jede Haltestelle ist ein eigener Halt, in der
	 * gepflegten Reihenfolge, sortiert nach Stufe. Haltestellen derselben
	 * Stufe gehören zum selben Abschnitt (z. B. Einstieg) – die Einstiegshilfe fragt sie
	 * gemeinsam ab.
	 *
	 * @return array [ ['kasten'=>…, 'nr'=>1], … ]
	 */
	public static function halte( $kaesten ) {
		$out   = array();
		$nr    = 0;
		foreach ( self::spalten( $kaesten ) as $liste ) {
			foreach ( $liste as $k ) {
				$nr++;
				$out[] = array( 'kasten' => $k, 'nr' => $nr );
			}
		}
		return $out;
	}

	/**
	 * Ein Weg: Kopf, Einstiegshilfe, Strecke. Auch für die Live-Vorschau im
	 * Editor (dort mit ungespeicherten Kästen).
	 *
	 * @param array $weg [titel, ziel, einleitung, entwurf, kaesten]
	 */
	public static function render_weg_daten( $weg, $anker, $termine, $als_tab = false, $versteckt = false ) {
		$kaesten    = $weg['kaesten'];
		$treffer    = self::seminare_je_kasten( $kaesten, $termine );
		$abschnitte = self::abschnitte( $kaesten );
		$lage       = self::lage( $kaesten );

		// Halte werden durchgezählt; jeder Ast zählt ab der Weiche weiter,
		// nach dem Zusammenlaufen geht es mit der höchsten Nummer weiter.
		// Spalten der Gabel: so viele wie die breiteste Weiche des Weges. Die
		// Hauptstrecke läuft dann in der Mitte dieser Spalten.
		$spalten_n = 0;
		foreach ( $abschnitte as $a ) {
			if ( 'weiche' === $a['typ'] ) {
				$spalten_n = max( $spalten_n, count( $a['aeste'] ) );
			}
		}
		$strecke = '';
		$nr      = 0;
		foreach ( $abschnitte as $ai => $a ) {
			$zuletzt = count( $abschnitte ) - 1 === $ai;
			if ( 'stamm' === $a['typ'] ) {
				foreach ( $a['kaesten'] as $ki => $k ) {
					$nr++;
					$strecke .= self::render_halt( $k, $treffer[ $k['id'] ], $nr, $zuletzt && count( $a['kaesten'] ) - 1 === $ki );
				}
				continue;
			}
			$strecke .= self::render_weiche( $a, $treffer, $nr, ! $zuletzt, $nr_danach, $spalten_n );
			$nr       = $nr_danach;
		}

		$daten = array( 'kaesten' => array() );
		foreach ( $kaesten as $k ) {
			$daten['kaesten'][] = array(
				'id'      => $k['id'],
				'name'    => $k['name'],
				'stufe'   => $k['stufe'],
				'art'     => $k['art'],
				'pflicht' => $k['pflicht'],
				'fragen'  => self::fragen( $k ),
				'weiche'  => $lage[ $k['id'] ]['weiche'],
				'ast'     => $lage[ $k['id'] ]['ast'],
			);
		}

		$entwurf    = ! empty( $weg['entwurf'] ) ? '<p class="bil-entwurf">Entwurf – nur für die Redaktion sichtbar.</p>' : '';
		$einleitung = trim( (string) $weg['einleitung'] );
		$einleitung = '' !== $einleitung ? '<div class="bil-einleitung">' . wpautop( wp_kses_post( $einleitung ) ) . '</div>' : '';
		$attr       = $als_tab ? ' role="tabpanel" aria-labelledby="' . esc_attr( $anker ) . '-tab"' : '';

		return '<section class="bil-weg" id="' . esc_attr( $anker ) . '" data-bil-weg' . $attr . ( $versteckt ? ' hidden' : '' ) . '>'
			. $entwurf
			. '<header class="bil-weg__kopf"><p class="bil-weg__ueber">Bildungsweg</p>'
			. '<h2 class="bil-weg__titel">' . esc_html( $weg['titel'] ) . '</h2>'
			. ( '' !== $weg['ziel'] ? '<p class="bil-weg__ziel">' . esc_html( $weg['ziel'] ) . '</p>' : '' )
			. '</header>'
			. $einleitung
			. self::einstieg_panel()
			. '<ol class="bil-strecke' . ( $spalten_n ? ' bil-strecke--gabel bil-strecke--gabel' . min( 4, $spalten_n ) . ' bil-strecke--' . ( $spalten_n % 2 ? 'ungerade' : 'gerade' ) : '' ) . '"'
			. ( $spalten_n ? ' style="--bil-gabel-n:' . (int) $spalten_n . '"' : '' ) . ' aria-label="Strecke">' . $strecke . '</ol>'
			. '<script type="application/json" data-bil-daten>' . wp_json_encode( $daten, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>'
			. '</section>';
	}

	/** Ein Halt der Strecke mit seiner Haltestelle. */
	private static function render_halt( $k, $t, $nr, $endhalt ) {
		$label = ( 1 === $nr ? 'Einstieg · ' : '' ) . $nr . '. Halt';
		return '<li class="bil-halt' . ( $endhalt ? ' bil-halt--ziel' : '' ) . '" data-stufe="' . (int) $k['stufe'] . '">'
			. '<span class="bil-halt__punkt" aria-hidden="true"></span>'
			. '<ul class="bil-halt__stellen">' . self::render_kasten( $k, $t, $label ) . '</ul></li>';
	}

	/**
	 * Spalten, in denen die Äste einer Weiche laufen: gleichmäßig um die
	 * Mitte, damit kein Ast wie die Hauptstrecke aussieht. Zwei Äste in drei
	 * Spalten → außen links und außen rechts, die Mitte bleibt frei.
	 *
	 * @return int[] Spalte je Ast, ab 0
	 */
	public static function gabel_spalten( $anzahl, $spalten_n ) {
		if ( $anzahl >= $spalten_n ) {
			return range( 0, $anzahl - 1 );
		}
		$out = array();
		for ( $i = 0; $i < $anzahl; $i++ ) {
			$out[] = (int) round( $i * ( $spalten_n - 1 ) / max( 1, $anzahl - 1 ) );
		}
		return $out;
	}

	/**
	 * Eine Weiche: Kopf mit Weichensymbol und Richtungsschildern (schmal als
	 * Umschalter), darunter die Äste nebeneinander. Die Hauptstrecke kommt in
	 * der Mitte an und gabelt sich nach links und rechts – kein Ast fährt
	 * „geradeaus“. Laufen die Äste danach wieder zusammen ($zusammen), führen
	 * sie unten in die Mitte zurück, sonst endet jeder Ast mit einem Endhalt.
	 *
	 * @param int $nr_danach höchste vergebene Halt-Nummer (Ausgabe)
	 */
	private static function render_weiche( $a, $treffer, $nr, $zusammen, &$nr_danach, $spalten_n = 2 ) {
		$nr_danach = $nr;
		$namen     = array_keys( $a['aeste'] );
		$schilder  = '';
		$spalten   = '';
		$gesamt    = max( $spalten_n, count( $namen ) );
		$lage      = self::gabel_spalten( count( $namen ), $gesamt );
		$mitte     = ( $gesamt - 1 ) / 2;
		foreach ( $namen as $i => $name ) {
			$name     = (string) $name;
			$liste    = $a['aeste'][ $name ];
			$schilder .= '<button type="button" class="bil-richtung" data-bil-richtung="' . (int) $i . '" aria-pressed="' . ( 0 === $i ? 'true' : 'false' ) . '">'
				. '<span class="bil-richtung__pfeil" aria-hidden="true">' . ( $lage[ $i ] < $mitte ? '↙' : ( $lage[ $i ] > $mitte ? '↘' : '↓' ) ) . '</span>' . esc_html( $name ) . '</button>';
			$halte = '';
			$n     = $nr;
			foreach ( $liste as $ki => $k ) {
				$n++;
				$halte .= self::render_halt( $k, $treffer[ $k['id'] ], $n, ! $zusammen && count( $liste ) - 1 === $ki );
			}
			$nr_danach = max( $nr_danach, $n );
			// Links der Mitte gespiegelt (Gleis rechts, Kasten links davon).
			// --bil-k = ganze Spaltenbreiten zwischen Gleis und Mitte; den Rest
			// (halber Abstand, Gleis) rechnet das CSS je nach gerader/ungerader Spaltenzahl.
			$k         = $mitte - $lage[ $i ]; // > 0: links der Mitte, < 0: rechts
			$seite     = $k > 0 ? 'links' : ( $k < 0 ? 'rechts' : 'mitte' );
			$k         = abs( $k );
			$k         = 0 === $gesamt % 2 ? $k - 0.5 : ( 'links' === $seite ? $k - 1 : $k );
			$spalten  .= '<div class="bil-ast bil-ast--' . $seite . '" data-bil-ast="' . (int) $i . '" data-bil-ast-name="' . esc_attr( $name ) . '"'
				. ' style="grid-column:' . ( $lage[ $i ] + 1 ) . ';--bil-k:' . max( 0, $k ) . '">'
				. '<p class="bil-ast__schild"><span class="bil-ast__tafel"><span class="bil-ast__richtung">Richtung</span> ' . esc_html( $name ) . '</span></p>'
				. '<ol class="bil-ast__strecke" aria-label="' . esc_attr( 'Ast ' . $name ) . '">' . $halte . '</ol>'
				. ( $zusammen ? '<div class="bil-ast__zurueck" aria-hidden="true"></div>' : '' )
				. '</div>';
		}
		$anzahl = count( $namen );
		return '<li class="bil-weiche' . ( $zusammen ? ' bil-weiche--zusammen' : '' ) . '" data-bil-weiche="' . (int) $a['nr'] . '">'
			. '<div class="bil-weiche__kopf">'
			. '<span class="bil-halt__punkt bil-weiche__punkt" aria-hidden="true"></span>'
			. '<p class="bil-weiche__titel"><span class="bil-weiche__wort">Weiche</span> Hier teilt sich der Weg in ' . ( 2 === $anzahl ? 'zwei' : ( 3 === $anzahl ? 'drei' : (int) $anzahl ) ) . ' Richtungen.'
			. ( $zusammen ? ' Danach geht es gemeinsam weiter.' : '' ) . '</p>'
			. '<div class="bil-weiche__schilder" role="group" aria-label="Richtung zeigen">' . $schilder . '</div>'
			. '</div>'
			. '<div class="bil-aeste">' . $spalten . '</div>'
			. ( $zusammen ? '<p class="bil-weiche__zusammen"><span class="bil-weiche__wort">Zusammen</span> Hier laufen die Richtungen wieder zusammen.</p>' : '' )
			. '</li>';
	}

	/** „Wo steigst du ein?“ – der Inhalt der Fragen kommt von fahrplan.js. */
	private static function einstieg_panel() {
		return '<div class="bil-einstieg" data-bil-einstieg>'
			. '<h3 class="bil-einstieg__titel">Wo steigst du ein?</h3>'
			. '<div class="bil-einstieg__wahl" data-bil-start>'
			. '<button type="button" class="bil-knopf" data-bil-modus="neu"><strong>Ich fange neu an</strong><span>Zeig mir den Anfang.</span></button>'
			. '<button type="button" class="bil-knopf" data-bil-modus="vorwissen"><strong>Ich habe schon Vorwissen</strong><span>Ein paar Ja/Nein-Fragen, Halt für Halt.</span></button>'
			. '</div>'
			. '<div class="bil-frage" data-bil-frage hidden aria-live="polite"></div>'
			. '<div class="bil-ergebnis" data-bil-ergebnis hidden aria-live="polite"></div>'
			. '<noscript><p>Für die Fragen wird JavaScript benötigt. Der Weg mit allen Seminaren steht unten.</p></noscript>'
			. '</div>';
	}

	/** Ein Kasten wie im Heft, darunter aufklappbar die Seminare. */
	private static function render_kasten( $k, $t, $halt = '' ) {
		$punkte = '';
		foreach ( $k['punkte'] as $p ) {
			$punkte .= '<li>' . esc_html( $p ) . '</li>';
		}
		$html = '<li class="bil-kasten bil-kasten--' . esc_attr( $k['art'] ) . '" data-bil-kasten="' . esc_attr( $k['id'] ) . '">'
			. '<p class="bil-kasten__marke" data-bil-marke hidden></p>'
			. '<p class="bil-kasten__art">' . ( '' !== $halt ? '<span class="bil-kasten__halt">' . esc_html( $halt ) . '</span>' : '' )
			. '<span class="bil-kasten__artname">' . esc_html( self::ARTEN[ $k['art'] ] ) . '</span></p>'
			. '<h3 class="bil-kasten__name">' . esc_html( $k['name'] ) . '</h3>'
			. ( $punkte ? '<ul class="bil-kasten__punkte">' . $punkte . '</ul>' : '' );

		if ( $k['regional'] ) {
			$html .= '<p class="bil-kasten__regional">Regional bei deiner Geschäftsstelle. '
				. '<a href="' . esc_url( BI_Detail::gs_such_url() ) . '" target="_blank" rel="noopener">Geschäftsstelle finden</a></p>';
		}

		if ( $t['seminare'] ) {
			$zeilen = '';
			foreach ( $t['seminare'] as $s ) {
				$zeilen .= '<li><a href="' . esc_url( get_permalink( $s['id'] ) ) . '">' . esc_html( $s['titel'] ) . '</a>'
					. '<span class="bil-seminar__info">' . (int) $s['anzahl'] . ( 1 === (int) $s['anzahl'] ? ' Termin' : ' Termine' )
					. ', nächster am ' . esc_html( date_i18n( 'd.m.Y', strtotime( $s['naechster'] ) ) ) . '</span></li>';
			}
			$n     = count( $t['seminare'] );
			$html .= '<details class="bil-seminare"><summary><span class="bil-seminare__wort">Abfahrten</span> '
				. ( 1 === $n ? '1 Seminar' : $n . ' Seminare' ) . ' · ' . (int) $t['anzahl'] . ( 1 === (int) $t['anzahl'] ? ' Termin' : ' Termine' )
				. '</summary><ul class="bil-seminare__liste">' . $zeilen . '</ul></details>';
		} elseif ( $k['titel'] && ! $k['regional'] ) {
			$html .= '<p class="bil-kasten__keine">Zurzeit kein Termin ausgeschrieben.</p>';
		}

		return $html . '</li>';
	}

	/** Legende wie im Heft. */
	private static function legende() {
		$html = '<ul class="bil-legende" aria-label="Legende">';
		foreach ( self::ARTEN as $a => $l ) {
			$html .= '<li><span class="bil-legende__farbe bil-kasten--' . esc_attr( $a ) . '"></span>' . esc_html( $l ) . '</li>';
		}
		return $html . '</ul>';
	}
}
