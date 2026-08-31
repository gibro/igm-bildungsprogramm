<?php
/**
 * Volltextsuche: der Suchindex, der Wortschatz und die Tippfehler-Korrektur.
 *
 * ============================================================================
 *  WARUM ES EINE EIGENE TABELLE GIBT
 * ============================================================================
 *  Bis 1.108.0 suchte die WHERE-Bedingung je Suchwort mit zwei Unterabfragen:
 *  eine über `postmeta` (dreißig Schlüssel, `meta_value LIKE '%wort%'`) und eine
 *  über die drei Term-Tabellen. Sechs Wörter waren zwölf Unterabfragen, und
 *  keine davon konnte einen Index benutzen – `LIKE '%…%'` kann das nie. Auf dem
 *  Bestand von rund 1.800 buchbaren Seminaren kostete eine Suche damit
 *  Sekunden, und sie lief pro Seitenaufruf mehrfach (Liste, Facettenzähler,
 *  Probeabfrage).
 *
 *  Seit 1.109.0 steht neben jedem Seminar EINE Zeile in `wp_bi_suchindex`:
 *  Titel, Beschreibung, alle Meta-Werte, alle Begriffe – in einem Feld, ohne
 *  HTML. Darauf liegt ein FULLTEXT-Index, und die ganze Bedingung ist ein
 *  einziges EXISTS mit einem MATCH … AGAINST.
 *
 *  ZWEI WEGE, WEIL DEUTSCH ZUSAMMENSETZT:
 *
 *    MATCH (schnell, benutzt den Index)
 *      Findet Wortanfänge: „betriebs" findet „Betriebsrat".
 *
 *    LIKE auf derselben Tabelle (langsamer, aber EINE Tabelle mit EINER Zeile
 *    je Seminar statt dreißig Meta-Zeilen)
 *      Findet auch mittendrin: „rat" findet „Betriebsrat". Im Deutschen ist das
 *      kein Sonderfall, sondern der Alltag – deshalb bleibt dieser Weg.
 *
 *  Gefragt wird erst mit MATCH. Nur wenn das nichts findet, läuft dieselbe
 *  Suche noch einmal mit LIKE. Der teure Weg wird also nur bezahlt, wenn der
 *  billige nichts bringt – dieselbe Regel wie bei der Tippfehler-Korrektur.
 *
 *  KEIN FULLTEXT VERFÜGBAR? Auf einer alten MySQL-Version (oder mit MyISAM)
 *  lässt sich der Index nicht anlegen. Dann wird durchgehend mit LIKE gesucht –
 *  langsamer als MATCH, aber immer noch um Größenordnungen schneller als die
 *  Unterabfragen über postmeta. Erkannt wird das beim Anlegen der Tabelle.
 *
 * ============================================================================
 *  WAS IM INDEX STEHT
 * ============================================================================
 *    - post_title
 *    - post_content, entkleidet (siehe text_entkleiden)
 *    - jeder Meta-Wert aus meta_keys() – auch die selbst angelegten Felder
 *    - jedes HTML-Feld (Themen im Seminar …), entkleidet
 *    - die Begriffe aller filterbaren Taxonomien
 *    - dazu von jedem Wort mit Umlaut die aufgelöste Schreibweise
 *      („Betriebsräte" -> „betriebsraete"), damit eine Tastatur ohne Umlaute
 *      direkt trifft statt erst über die Korrektur
 *
 *  NICHT DRIN: Ja/Nein, Datum, Uhrzeit, Zahl und Betrag. Ihre Werte tragen
 *  keine Wörter – „1" fände jedes Seminar mit irgendeinem gesetzten Haken.
 *
 * ============================================================================
 *  WIE DER INDEX AKTUELL BLEIBT
 * ============================================================================
 *  Beim Speichern, bei jeder Änderung an einem beteiligten Feld und beim
 *  Setzen von Begriffen. Gesammelt wird während des Aufrufs, geschrieben
 *  einmal am Ende (shutdown): Ein CSV-Import setzt zwanzig Meta-Felder je
 *  Zeile und würde die Zeile sonst zwanzigmal neu bauen.
 *
 *  FÜR DEN BESTAND läuft ein Nachbau in Häppchen (rebuild_batch), angestoßen
 *  bei jedem Aufruf im Backend und zusätzlich von einem Cron-Ereignis. Kein
 *  Knopf, den jemand drücken muss, und keine Abhängigkeit von WP-Cron allein:
 *  Ist der Index unvollständig, steht der Stand als Hinweis im Backend.
 *
 * ============================================================================
 *  DER WORTSCHATZ
 * ============================================================================
 *  Er speist die Autovervollständigung und die Tippfehler-Korrektur und kommt
 *  aus den Titeln, den Begriffen der Taxonomien und einer Handvoll
 *  BEGRIFFSFELDER (Seminarort, Untertitel, Referent*innen, Ansprechpartner*in,
 *  Teil | Reihe).
 *
 *  BEWUSST NICHT AUS DEM FLIESSTEXT: Beschreibung und Themen bestehen zu großen
 *  Teilen aus „werden", „einer", „Teilnehmerinnen". Im Wortschatz verdrängten
 *  diese Wörter die Begriffe (die Häufigkeit entscheidet bei gleichem Abstand),
 *  und jede Korrektur führe gegen das nächstbeste Füllwort. Ein Wortschatz ist
 *  eine Begriffsliste, kein Textkorpus.
 *
 * ============================================================================
 *  DIE KORREKTUR
 * ============================================================================
 *  Erst wird ganz normal gesucht. **Nur wenn das nichts findet**, tritt die
 *  Korrektur an: Jedes Wort der Anfrage wird mit dem Wortschatz verglichen
 *  (Levenshtein-Abstand) und, wenn ein naher Nachbar existiert, ersetzt.
 *
 *  KEINE STILLE KORREKTUR: Über der Liste steht, was passiert ist – „Keine
 *  Treffer für ‚Arbeitsercht'. Gezeigt werden Treffer für ‚Arbeitsrecht'."
 *  Eine Suche, die heimlich etwas anderes sucht als eingetippt, ist nicht
 *  hilfreich, sondern verwirrend: Man sieht Ergebnisse und weiß nicht, wieso.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Suche {

	/** Wortschatz im Transient (verfällt zusätzlich nach einem Tag). */
	const TRANSIENT = 'bi_suche_wortschatz';

	/** Kürzere Wörter bleiben außen vor: Bei „AuG" ist jeder Nachbar zufällig. */
	const MIN_LAENGE = 4;

	/** Obergrenze des Wortschatzes – Schutz vor einem entgleisten Bestand. */
	const MAX_WOERTER = 8000;

	/** Stand des Nachbaus: ['version','offset','erledigt','gesamt','fertig']. */
	const OPTION_INDEX = 'bi_suchindex';

	/** Merker, ob der FULLTEXT-Index tatsächlich angelegt werden konnte. */
	const OPTION_FULLTEXT = 'bi_suchindex_fulltext';

	/**
	 * Zusammensetzung des Indextextes. Ändert sie sich, wird vollständig neu
	 * gebaut – sonst blieben alte Zeilen auf ewig unvollständig.
	 */
	const INDEX_VERSION = '2';

	/** Wie viele Seminare ein Häppchen des Nachbaus umfasst. */
	const REBUILD_BATCH = 200;

	/** Cron-Ereignis, das den Nachbau vorantreibt, falls WP-Cron läuft. */
	const CRON_HOOK = 'bi_suchindex_nachbauen';

	/**
	 * Kürzestes Wort, das MATCH überhaupt sehen kann.
	 *
	 * InnoDB nimmt kürzere Wörter gar nicht erst in den Index auf
	 * (innodb_ft_min_token_size, Vorgabe 3). Ein Pflichtwort, das im Index
	 * nicht vorkommt, lässt MATCH die GESAMTE Abfrage leer zurückgeben – aus
	 * „BR im Betrieb" würde also nicht etwa eine ungenauere Suche, sondern gar
	 * keine. Enthält die Eingabe ein zu kurzes Wort, wird deshalb von vornherein
	 * mit LIKE gesucht.
	 */
	const MIN_TOKEN = 3;

	/** Während dieses Aufrufs geänderte Beiträge (siehe nachtragen). */
	private static $offen = array();

	public static function init() {
		foreach ( self::index_post_types() as $pt ) {
			add_action( 'save_post_' . $pt, array( __CLASS__, 'verwerfen' ) );
			// Priorität 99: Erst speichert die Metabox ihre Felder, dann bauen
			// wir die Indexzeile. Andersherum stünde immer der vorige Stand drin.
			add_action( 'save_post_' . $pt, array( __CLASS__, 'vormerken' ), 99 );
		}
		add_action( 'deleted_post', array( __CLASS__, 'geloescht' ) );

		// Begriffe gehören in den Index UND in den Wortschatz – ein umbenanntes
		// Bildungszentrum muss beides verwerfen wie ein neues Seminar.
		add_action( 'created_term', array( __CLASS__, 'verwerfen' ) );
		add_action( 'edited_term', array( __CLASS__, 'verwerfen' ) );
		add_action( 'delete_term', array( __CLASS__, 'verwerfen' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'vormerken' ) );

		// CSV-Import und Massenbearbeitung schreiben Meta-Felder, ohne dass
		// save_post noch einmal feuert. Ohne diese drei Haken bliebe der Index
		// nach einem Import auf dem Stand von vorher.
		add_action( 'added_post_meta', array( __CLASS__, 'vormerken_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'vormerken_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'vormerken_meta' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'nachtragen' ) );

		// Nachbau des Bestands: bei jedem Aufruf im Backend ein Häppchen, und
		// zusätzlich per Cron – falls niemand ins Backend schaut.
		add_action( 'admin_init', array( __CLASS__, 'rebuild_anstossen' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'rebuild_batch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'index_hinweis' ) );
	}

	public static function verwerfen() {
		delete_transient( self::TRANSIENT );
	}

	/* ===================================================================
	 *  Die Tabelle
	 * =================================================================== */

	public static function table() {
		return bi_table( 'suchindex' );
	}

	/**
	 * Tabelle anlegen bzw. aktualisieren.
	 *
	 * DER FULLTEXT-INDEX WIRD SEPARAT ANGELEGT, nicht über dbDelta: dbDelta
	 * erkennt einen vorhandenen FULLTEXT-Schlüssel nicht zuverlässig wieder und
	 * versuchte ihn bei jedem Aufruf erneut anzulegen. Stattdessen wird einmal
	 * nachgesehen, ob er da ist, und andernfalls ein ALTER TABLE abgesetzt.
	 * Schlägt das fehl (alte MySQL-Version, MyISAM), merkt sich das Plugin das
	 * und sucht durchgehend mit LIKE – langsamer, aber nicht kaputt.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( "CREATE TABLE $table (
			post_id BIGINT(20) UNSIGNED NOT NULL,
			post_type VARCHAR(20) NOT NULL DEFAULT '',
			aktualisiert DATETIME NULL DEFAULT NULL,
			inhalt LONGTEXT NULL,
			PRIMARY KEY  (post_id),
			KEY post_type (post_type)
		) $charset;" );

		$hat = $wpdb->get_var( "SHOW INDEX FROM $table WHERE Key_name = 'bi_volltext'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $hat ) {
			$wpdb->hide_errors();
			$wpdb->query( "ALTER TABLE $table ADD FULLTEXT KEY bi_volltext (inhalt)" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->show_errors();
			$hat = $wpdb->get_var( "SHOW INDEX FROM $table WHERE Key_name = 'bi_volltext'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		update_option( self::OPTION_FULLTEXT, $hat ? '1' : '0', true );

		// Vorgänger aufräumen: Bis 1.108.0 stand der entkleidete Text als
		// Meta-Feld an jedem Seminar. Es wird nirgends mehr gelesen und wäre
		// sonst eine Zeile je Seminar, die jede Meta-Abfrage mitschleppt.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_bi_suchtext' ) );
	}

	/** Steht ein FULLTEXT-Index zur Verfügung? */
	public static function fulltext_vorhanden() {
		return '1' === (string) get_option( self::OPTION_FULLTEXT, '0' );
	}

	/* ===================================================================
	 *  Welche Felder in den Index gehen
	 * =================================================================== */

	/**
	 * Feldtypen, deren Werte keine Wörter tragen – sie bleiben außen vor.
	 *
	 * WARUM NICHT WIRKLICH ALLE: Ein Ja/Nein-Feld steht als „1" in der
	 * Datenbank. Wäre es dabei, fände die Eingabe „1" jedes Seminar, bei dem
	 * irgendein Haken gesetzt ist – also praktisch jedes. Dasselbe gilt für
	 * Datum, Uhrzeit, Zahl und Betrag: „12" träfe jeden Termin im Dezember,
	 * jeden am 12., jede Uhrzeit ab 12 Uhr und jeden Preis mit einer 12 darin.
	 * Für genau diese Felder gibt es den Chip „Zeitraum" und die Ampel; als
	 * Volltext wären sie kein Fund, sondern Rauschen.
	 */
	public static function keine_wortfelder() {
		return array( 'bool', 'date', 'time', 'number', 'money' );
	}

	/**
	 * Alle Meta-Schlüssel, deren Werte in den Index wandern.
	 *
	 * Gelesen wird die Feld-Registry beider Seminarformen – damit ist ein in
	 * der Datenpflege neu angelegtes eigenes Feld (`_bix_…`) von allein
	 * durchsuchbar, ohne dass hier etwas nachgetragen werden muss.
	 *
	 * HTML-FELDER STEHEN NICHT DRIN, sondern kommen entkleidet dazu
	 * (html_felder). Ihr roher Wert enthielte Tagnamen und Attribute.
	 *
	 * Über den Filter `bi_suche_meta_keys` lässt sich die Liste anpassen.
	 */
	public static function meta_keys() {
		static $keys = null;
		if ( is_array( $keys ) ) {
			return $keys;
		}
		$raus    = self::keine_wortfelder();
		$sammeln = array();
		foreach ( bi_seminar_post_types() as $pt ) {
			foreach ( BI_CPT::meta_fields( $pt ) as $key => $cfg ) {
				$typ = (string) ( $cfg['type'] ?? 'text' );
				if ( in_array( $typ, $raus, true ) || 'html' === $typ ) {
					continue;
				}
				$sammeln[ $key ] = true; // Schlüssel als Index: beide Formen teilen sich Felder
			}
		}
		$keys = array_values( array_map( 'strval', array_keys( $sammeln ) ) );
		$keys = array_values( (array) apply_filters( 'bi_suche_meta_keys', $keys ) );
		return $keys;
	}

	/**
	 * Taxonomien, deren Begriffe in den Index gehen.
	 *
	 * Dieselben, die auch die Filter-Chips anbieten: Wer „Sprockhövel" oder
	 * „Betriebsverfassungsrecht" tippt, meint denselben Filter – nur eben mit
	 * der Tastatur statt mit zwei Klicks.
	 */
	public static function taxonomien() {
		return array_values( (array) apply_filters( 'bi_suche_taxonomien', array(
			BI_TAX_ORT,
			BI_TAX_THEMA,
			BI_TAX_ZIEL,
			BI_TAX_FREI,
			BI_TAX_PROGRAMM,
		) ) );
	}

	/** Beitragstypen, für die eine Indexzeile geführt wird. */
	public static function index_post_types() {
		$typen = bi_seminar_post_types();
		if ( class_exists( 'BI_Reihen' ) ) {
			$typen[] = BI_Reihen::CPT;
		}
		return $typen;
	}

	/**
	 * Meta-Schlüssel vom Typ „html" – ihr Inhalt wandert entkleidet in den Index.
	 *
	 * Die Reihen sind dabei, obwohl die Frontend-Suche sie nicht abfragt: Ihr
	 * Text gehört genauso entkleidet, und ein zweites Regelwerk für denselben
	 * Zweck wäre eine Fehlerquelle mehr.
	 */
	public static function html_felder() {
		static $keys = null;
		if ( is_array( $keys ) ) {
			return $keys;
		}
		$sammeln = array();
		foreach ( self::index_post_types() as $pt ) {
			foreach ( BI_CPT::meta_fields( $pt ) as $key => $cfg ) {
				if ( 'html' === (string) ( $cfg['type'] ?? 'text' ) ) {
					$sammeln[ $key ] = true;
				}
			}
		}
		$keys = array_values( array_map( 'strval', array_keys( $sammeln ) ) );
		return $keys;
	}

	/* ===================================================================
	 *  Text aufbereiten
	 * =================================================================== */

	/**
	 * Tags, die MITTEN IN EINEM WORT stehen dürfen.
	 *
	 * Sie verschwinden spurlos, alle anderen hinterlassen ein Leerzeichen –
	 * siehe text_entkleiden().
	 */
	const INLINE_TAGS = 'b|strong|i|em|u|s|span|a|mark|code|sub|sup|small|abbr|q|cite|var|kbd';

	/**
	 * HTML in vergleichbaren Text verwandeln.
	 *
	 * VIER SCHRITTE, UND JEDER IST NÖTIG:
	 *   1. script/style samt Inhalt raus – dort steht Code, kein Text.
	 *   2. Auszeichnungen im Wort (b, strong, em, a …) verschwinden SPURLOS.
	 *      „Betriebs<strong>rat</strong>" ist ein Wort und muss eines bleiben;
	 *      mit Leerzeichen stünde „Betriebs rat" im Index und wäre für die
	 *      Suche nach „Betriebsrat" so unauffindbar wie vorher.
	 *   3. Jedes ANDERE Tag wird zu einem LEERZEICHEN. Sonst klebte
	 *      „<li>Kündigung</li><li>Abmahnung</li>" zu „KündigungAbmahnung" –
	 *      derselbe Fehler, nur andersherum.
	 *   4. Entities auflösen: „&amp;" ist ein Und-Zeichen, „&nbsp;" ein
	 *      Leerzeichen. Ungelöst stünde „amp" als Wort im Text.
	 */
	public static function text_entkleiden( $html ) {
		$text = (string) $html;
		if ( '' === trim( $text ) ) {
			return '';
		}
		$text = preg_replace( '@<(script|style)[^>]*>.*?</\1>@is', ' ', $text );
		$text = preg_replace( '@</?(?:' . self::INLINE_TAGS . ')(?:\s[^>]*)?/?>@i', '', (string) $text );
		$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::zusammenziehen( $text );
	}

	/** Leerraum zusammenziehen (auch geschütztes Leerzeichen) und trimmen. */
	public static function zusammenziehen( $text ) {
		$text = str_replace( "\xc2\xa0", ' ', (string) $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Von jedem Wort mit Umlaut die aufgelöste Schreibweise.
	 *
	 * WOZU: Die Datenbank hält „ä" und „a" für gleich, „ae" und „ä" aber nicht.
	 * Wer ohne Umlauttaste tippt – der häufigste „Tippfehler", der gar keiner
	 * ist –, fände „Betriebsräte" sonst nur über die Korrektur, also erst nach
	 * einer zweiten Abfrage und mit einem Hinweis über der Liste. Steht
	 * „betriebsraete" mit im Index, trifft die erste Abfrage direkt.
	 *
	 * Nur die Wörter, die sich tatsächlich unterscheiden – der Index soll nicht
	 * doppelt so groß werden, nur damit „arbeitsrecht" zweimal darin steht.
	 */
	public static function umlaut_variante( $text ) {
		$out = array();
		foreach ( self::zerlegen( $text ) as $wort ) {
			$klein = function_exists( 'mb_strtolower' ) ? mb_strtolower( $wort, 'UTF-8' ) : strtolower( $wort );
			$norm  = self::normalisieren( $wort );
			if ( '' === $norm || $norm === $klein ) {
				continue;
			}
			$out[ $norm ] = true;
		}
		return implode( ' ', array_keys( $out ) );
	}

	/** Der vollständige Indextext eines Beitrags. */
	public static function index_text( $post_id ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$teile = array(
			(string) $post->post_title,
			self::text_entkleiden( $post->post_content ),
		);
		foreach ( self::meta_keys() as $key ) {
			$wert = get_post_meta( $post_id, $key, true );
			if ( is_scalar( $wert ) ) {
				$teile[] = (string) $wert;
			}
		}
		foreach ( self::html_felder() as $key ) {
			$teile[] = self::text_entkleiden( get_post_meta( $post_id, $key, true ) );
		}
		foreach ( self::taxonomien() as $tax ) {
			$namen = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $namen ) ) {
				$teile = array_merge( $teile, (array) $namen );
			}
		}

		$text = self::zusammenziehen( implode( ' ', array_filter( array_map( 'trim', $teile ), 'strlen' ) ) );
		$text = self::zusammenziehen( $text . ' ' . self::umlaut_variante( $text ) );

		// Deckel gegen einen versehentlich eingefügten Riesentext.
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 60000, 'UTF-8' ) : substr( $text, 0, 60000 );
	}

	/* ===================================================================
	 *  Index schreiben
	 * =================================================================== */

	/** Indexzeile eines Beitrags schreiben (oder löschen, wenn nichts übrig bleibt). */
	public static function index_schreiben( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::index_post_types(), true ) || 'trash' === $post->post_status ) {
			self::index_loeschen( $post_id );
			return '';
		}
		$text = self::index_text( $post_id );
		if ( '' === $text ) {
			self::index_loeschen( $post_id );
			return '';
		}
		$wpdb->replace(
			self::table(),
			array(
				'post_id'      => $post_id,
				'post_type'    => $post->post_type,
				'aktualisiert' => current_time( 'mysql' ),
				'inhalt'       => $text,
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return $text;
	}

	public static function index_loeschen( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/** Ein Beitrag ist weg – die Indexzeile auch, und der Wortschatz ist alt. */
	public static function geloescht( $post_id ) {
		self::index_loeschen( $post_id );
		self::verwerfen();
	}

	/* ---------- Sammeln statt sofort schreiben ---------- */

	/** Einen Beitrag für das Ende des Aufrufs vormerken. */
	public static function vormerken( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( get_post_type( $post_id ), self::index_post_types(), true ) ) {
			return;
		}
		self::$offen[ $post_id ] = true;
	}

	/**
	 * Änderung an einem beteiligten Meta-Feld: denselben Beitrag vormerken.
	 *
	 * Der Index selbst steht in einer eigenen Tabelle und löst deshalb gar
	 * keine Meta-Hooks aus – ein Kreislauf ist hier nicht möglich.
	 */
	public static function vormerken_meta( $meta_id, $post_id, $meta_key ) {
		$meta_key = (string) $meta_key;
		if ( ! in_array( $meta_key, self::meta_keys(), true ) && ! in_array( $meta_key, self::html_felder(), true ) ) {
			return;
		}
		self::vormerken( $post_id );
	}

	/** Am Ende des Aufrufs: alle vorgemerkten Beiträge einmal neu indizieren. */
	public static function nachtragen() {
		if ( ! self::$offen ) {
			return;
		}
		$ids = array_keys( self::$offen );
		self::$offen = array(); // vor dem Schreiben leeren
		foreach ( $ids as $id ) {
			self::index_schreiben( $id );
		}
		self::$offen = array();
	}

	/* ---------- Nachbau des Bestands ---------- */

	/** Stand des Nachbaus, immer vollständig gefüllt. */
	public static function index_stand() {
		$stand = get_option( self::OPTION_INDEX, array() );
		if ( ! is_array( $stand ) || ( $stand['version'] ?? '' ) !== self::INDEX_VERSION ) {
			$stand = array(
				'version'  => self::INDEX_VERSION,
				'offset'   => 0,
				'erledigt' => 0,
				'gesamt'   => self::index_gesamt(),
			);
		}
		$stand['offset']   = (int) ( $stand['offset'] ?? 0 );
		$stand['erledigt'] = (int) ( $stand['erledigt'] ?? 0 );
		$stand['gesamt']   = (int) ( $stand['gesamt'] ?? 0 );
		return $stand;
	}

	/** Ist der Index vollständig? */
	public static function index_fertig() {
		$stand = self::index_stand();
		return ! empty( $stand['fertig'] );
	}

	/** Wie viele Beiträge insgesamt zu indizieren sind. */
	private static function index_gesamt() {
		global $wpdb;
		$typen = self::index_post_types();
		$platz = implode( ',', array_fill( 0, count( $typen ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($platz) AND post_status <> 'trash'", // phpcs:ignore WordPress.DB.PreparedSQL
			$typen
		) );
	}

	/**
	 * Ein Häppchen nachbauen. Gibt zurück, wie viele Beiträge bearbeitet wurden.
	 *
	 * ÜBER DIE ID SORTIERT UND MIT MERKPOSTEN: So ist jeder Lauf für sich
	 * abgeschlossen und der nächste macht dort weiter, wo der vorige aufhörte –
	 * auch wenn dazwischen ein Zeitlimit zuschlägt.
	 */
	public static function rebuild_batch() {
		$stand = self::index_stand();
		if ( ! empty( $stand['fertig'] ) ) {
			return 0;
		}
		global $wpdb;
		$typen = self::index_post_types();
		$platz = implode( ',', array_fill( 0, count( $typen ), '%s' ) );
		$ids   = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($platz) AND post_status <> 'trash'" // phpcs:ignore WordPress.DB.PreparedSQL
			. ' AND ID > %d ORDER BY ID ASC LIMIT %d',
			array_merge( $typen, array( (int) $stand['offset'], self::REBUILD_BATCH ) )
		) );

		if ( ! $ids ) {
			$stand['fertig'] = true;
			$stand['gesamt'] = $stand['erledigt'];
			update_option( self::OPTION_INDEX, $stand, false );
			return 0;
		}
		foreach ( $ids as $id ) {
			self::index_schreiben( (int) $id );
			$stand['offset'] = max( (int) $stand['offset'], (int) $id );
		}
		$stand['erledigt'] += count( $ids );
		update_option( self::OPTION_INDEX, $stand, false );
		return count( $ids );
	}

	/**
	 * Den Nachbau vorantreiben – aber höchstens alle paar Sekunden.
	 *
	 * WARUM AN admin_init UND NICHT NUR AN WP-CRON: Auf vielen Installationen
	 * ist WP-Cron abgeschaltet (DISABLE_WP_CRON) oder läuft nur, wenn jemand
	 * die Seite aufruft. Ein Index, der von genau dieser Bedingung abhängt,
	 * bliebe womöglich für immer halb fertig – und die Suche fände nichts.
	 */
	public static function rebuild_anstossen() {
		if ( self::index_fertig() || wp_doing_ajax() ) {
			return;
		}
		if ( get_transient( 'bi_suchindex_laeuft' ) ) {
			return;
		}
		set_transient( 'bi_suchindex_laeuft', 1, 5 );

		// SO VIELE HÄPPCHEN, WIE IN DREI SEKUNDEN PASSEN – nicht nur eines.
		//
		// Solange der Index leer ist, findet die Volltextsuche NICHTS. Ein
		// einzelnes Häppchen je Seitenaufruf hieße bei zweitausend Seminaren
		// zehn Aufrufe im Backend, bis die Suche wieder trägt; wer nach dem
		// Update nicht zufällig genug herumklickt, hätte eine kaputte Suche.
		// Drei Sekunden sind auf einer Verwaltungsseite spürbar, aber einmalig –
		// und der Zustand, den sie beenden, ist der teurere.
		$ende = microtime( true ) + 3.0;
		do {
			$getan = self::rebuild_batch();
		} while ( $getan > 0 && microtime( true ) < $ende );

		if ( ! self::index_fertig() && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_HOOK );
		}
	}

	/** Hinweis im Backend, solange der Index unvollständig ist. */
	public static function index_hinweis() {
		if ( self::index_fertig() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$stand = self::index_stand();
		printf(
			'<div class="notice notice-info"><p><strong>Bildungsprogramm:</strong> Der Suchindex wird aufgebaut – %s von etwa %s Einträgen. '
			. 'Bis er fertig ist, findet die Volltextsuche nicht alle Seminare. '
			. 'Der Aufbau läuft von allein weiter; jeder Aufruf im Backend treibt ihn voran.</p></div>',
			esc_html( number_format_i18n( $stand['erledigt'] ) ),
			esc_html( number_format_i18n( max( $stand['gesamt'], $stand['erledigt'] ) ) )
		);
	}

	/* ===================================================================
	 *  Die Suchbedingung
	 * =================================================================== */

	/**
	 * Womit soll diese Eingabe gesucht werden – 'match' oder 'like'?
	 *
	 * MATCH ist der schnelle Weg, taugt aber nicht immer: ohne FULLTEXT-Index
	 * gar nicht, und mit einem Wort unter MIN_TOKEN Zeichen liefert es
	 * verlässlich NICHTS statt ungenau (siehe MIN_TOKEN).
	 */
	public static function modus_fuer( $text ) {
		if ( ! self::fulltext_vorhanden() ) {
			return 'like';
		}
		return ( '' === self::boolean_ausdruck( self::zerlegen( $text ) ) ) ? 'like' : 'match';
	}

	/**
	 * Die Wörter als Boolean-Ausdruck: `+arbeitsrecht* +grundlagen*`
	 *
	 * Das `+` macht jedes Wort zur Pflicht (UND zwischen den Wörtern), das `*`
	 * erlaubt den Wortanfang. Operatorzeichen aus der Eingabe werden entfernt,
	 * nicht maskiert: Ein `-` in der Eingabe wäre in BOOLEAN MODE ein
	 * Ausschluss, und aus der Suche nach „E-Learning" würde „alles ohne
	 * Learning".
	 *
	 * @return string '' , wenn MATCH nicht taugt.
	 */
	public static function boolean_ausdruck( array $woerter ) {
		$teile = array();
		foreach ( array_slice( $woerter, 0, 6 ) as $wort ) {
			$sauber = preg_replace( '/[^\p{L}\p{N}_]+/u', '', (string) $wort );
			if ( '' === $sauber ) {
				continue;
			}
			$laenge = function_exists( 'mb_strlen' ) ? mb_strlen( $sauber, 'UTF-8' ) : strlen( $sauber );
			if ( $laenge < self::MIN_TOKEN ) {
				return '';
			}
			$teile[] = '+' . $sauber . '*';
		}
		return $teile ? implode( ' ', $teile ) : '';
	}

	/**
	 * Die WHERE-Bedingung: EIN EXISTS auf die Indextabelle.
	 *
	 * Vorher waren es je Suchwort zwei Unterabfragen über postmeta und die
	 * Term-Tabellen – bei sechs Wörtern zwölf. Jetzt ist es eine, unabhängig
	 * von der Wortzahl, gegen eine Tabelle mit genau einer Zeile je Seminar.
	 *
	 * @param string[] $woerter Die Suchwörter.
	 * @param string   $modus   'match' oder 'like'.
	 * @return string SQL-Fragment oder '' .
	 */
	public static function such_klausel( array $woerter, $modus = 'match' ) {
		global $wpdb;
		$tab = self::table();

		if ( 'match' === $modus ) {
			$ausdruck = self::boolean_ausdruck( $woerter );
			if ( '' === $ausdruck ) {
				return '';
			}
			return $wpdb->prepare(
				"EXISTS ( SELECT 1 FROM {$tab} AS bi_si WHERE bi_si.post_id = {$wpdb->posts}.ID" // phpcs:ignore WordPress.DB.PreparedSQL
				. ' AND MATCH ( bi_si.inhalt ) AGAINST ( %s IN BOOLEAN MODE ) )',
				$ausdruck
			);
		}

		// Mehr als sechs Wörter bringen keine Genauigkeit mehr, nur Last.
		$teile = array();
		foreach ( array_slice( $woerter, 0, 6 ) as $wort ) {
			$teile[] = $wpdb->prepare( 'bi_si.inhalt LIKE %s', '%' . $wpdb->esc_like( $wort ) . '%' );
		}
		if ( ! $teile ) {
			return '';
		}
		return "EXISTS ( SELECT 1 FROM {$tab} AS bi_si WHERE bi_si.post_id = {$wpdb->posts}.ID" // phpcs:ignore WordPress.DB.PreparedSQL
			. ' AND (' . implode( ' AND ', $teile ) . ') )';
	}

	/* ===================================================================
	 *  Seminarnummer erkennen
	 * =================================================================== */

	/**
	 * Sieht diese Eingabe nach einer Seminarnummer aus?
	 *
	 * Seminarnummern sind EIN Wort aus Buchstaben und Ziffern – „SE14822" im
	 * alten, „B00027030" im neuen Zuschnitt. Genau daran wird erkannt: ein
	 * Wort, mindestens vier Zeichen lang, mindestens eine Ziffer darin.
	 *
	 * WOZU DIE PRÜFUNG ÜBERHAUPT: Die Volltextsuche vergleicht die Eingabe
	 * zusätzlich am Stück mit der Seminarnummer – aber nur, wenn sie danach
	 * aussieht. Sonst zöge jede Suche nach „2026" alle Nummern mit einer 26
	 * darin herein, und die Trefferliste wäre nicht länger die Antwort auf die
	 * Frage.
	 *
	 * ZWEITER ZWECK: Eine Nummer, die nichts findet, darf NICHT von der
	 * Tippfehler-Korrektur zu einem Titelwort verbogen werden. „SE14822" hat
	 * keinen nahen Nachbarn im Wortschatz, den jemand gemeint haben könnte.
	 */
	public static function ist_nummer( $text ) {
		$text = trim( (string) $text );
		if ( strlen( $text ) < 4 ) {
			return false;
		}
		// Ein Wort: Buchstaben, Ziffern und die Trennzeichen, die in Nummern
		// vorkommen dürfen. Ein Leerzeichen beendet die Nummer – „SE14822 Bonn"
		// ist eine Titelsuche, keine Nummer.
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9\-_.\/]*$/', $text ) ) {
			return false;
		}
		return (bool) preg_match( '/[0-9]/', $text );
	}

	/* ===================================================================
	 *  Normalisieren
	 * =================================================================== */

	/**
	 * Vergleichsform eines Wortes: klein, ohne Umlaute, ohne Zierrat.
	 *
	 * Die Umlaut-Auflösung ist der halbe Nutzen: Die Datenbank vergleicht zwar
	 * „ä" und „a" als gleich, „ae" und „ä" aber nicht – und genau so tippen
	 * Leute, die die Umlauttaste nicht suchen wollen.
	 */
	public static function normalisieren( $wort ) {
		$wort = (string) $wort;
		$wort = function_exists( 'mb_strtolower' ) ? mb_strtolower( $wort, 'UTF-8' ) : strtolower( $wort );
		$wort = strtr( $wort, array(
			'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
			'á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e',
			'í' => 'i', 'ì' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ú' => 'u', 'ù' => 'u',
		) );
		// Alles, was kein Buchstabe und keine Ziffer ist, trennt Wörter.
		return preg_replace( '/[^a-z0-9]+/u', '', $wort );
	}

	/** Anfrage in vergleichbare Wörter zerlegen (Reihenfolge bleibt erhalten). */
	public static function zerlegen( $text ) {
		$teile = preg_split( '/[^\p{L}\p{N}]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $teile ) ? $teile : array();
	}

	/* ===================================================================
	 *  Wortschatz
	 * =================================================================== */

	/**
	 * Meta-Felder, deren Werte Begriffe sind – sie gehören in den Wortschatz.
	 *
	 * Handverlesen und nicht aus der Registry abgeleitet: Ein Wortschatz lebt
	 * davon, dass die häufigsten Wörter darin die wichtigsten sind. Nähme man
	 * jedes Textfeld, stünden Mailadressen, Kostenhinweise und Links darin.
	 *
	 * Filter: `bi_suche_wortschatz_felder`.
	 */
	public static function wortschatz_felder() {
		return array_values( (array) apply_filters( 'bi_suche_wortschatz_felder', array(
			'_bi_seminarort',       // „Sprockhövel", „Hotel Elbflorenz"
			'_bi_untertitel',       // Online-Seminare tragen den Kern oft hier
			'_bi_referenten',
			'_bi_ansprechpartner',
			'_bi_teil_reihe',       // der Name der Ausbildungsreihe
		) ) );
	}

	/**
	 * Wortschatz aus Titeln, Begriffen und Begriffsfeldern:
	 *
	 *     normalisiertes Wort => [ 'wort' => Originalschreibweise, 'n' => Anzahl ]
	 *
	 * WARUM BEIDES: Verglichen wird auf der normalisierten Form, GESUCHT wird mit
	 * der Originalschreibweise.
	 *
	 * BEGRIFFE ZÄHLEN MEHRFACH: Ein Bildungszentrum steht einmal in der
	 * Term-Tabelle, aber hinter dreihundert Seminaren. Ohne Gewicht verlöre
	 * „Sprockhövel" jeden Gleichstand gegen ein beliebiges Titelwort. Gezählt
	 * wird deshalb, an wie vielen Beiträgen der Begriff hängt.
	 */
	public static function wortschatz() {
		$gespeichert = get_transient( self::TRANSIENT );
		if ( is_array( $gespeichert ) ) {
			return $gespeichert;
		}

		global $wpdb;
		$typen = self::index_post_types();
		$platz = implode( ',', array_fill( 0, count( $typen ), '%s' ) );

		// 1. Titel
		$texte = (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT post_title FROM {$wpdb->posts}
			 WHERE post_type IN ($platz) AND post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL
			$typen
		) );

		// 2. Begriffsfelder
		$felder = self::wortschatz_felder();
		if ( $felder ) {
			$mplatz = implode( ',', array_fill( 0, count( $felder ), '%s' ) );
			$werte  = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT m.meta_value FROM {$wpdb->postmeta} AS m
				 INNER JOIN {$wpdb->posts} AS p ON ( p.ID = m.post_id )
				 WHERE m.meta_key IN ($mplatz) AND m.meta_value <> ''
				   AND p.post_type IN ($platz) AND p.post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $felder, $typen )
			) );
			$texte = array_merge( $texte, $werte );
		}

		$schatz = self::aus_titeln( $texte );

		// 3. Begriffe der Taxonomien, gewichtet mit ihrer Verwendung
		$taxen = self::taxonomien();
		if ( $taxen ) {
			$tplatz = implode( ',', array_fill( 0, count( $taxen ), '%s' ) );
			$zeilen = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT t.name AS name, tt.count AS n FROM {$wpdb->terms} AS t
				 INNER JOIN {$wpdb->term_taxonomy} AS tt ON ( tt.term_id = t.term_id )
				 WHERE tt.taxonomy IN ($tplatz)", // phpcs:ignore WordPress.DB.PreparedSQL
				$taxen
			) );
			foreach ( $zeilen as $zeile ) {
				$gewicht = max( 1, (int) $zeile->n );
				foreach ( self::zerlegen( $zeile->name ) as $wort ) {
					$norm = self::normalisieren( $wort );
					if ( strlen( $norm ) < self::MIN_LAENGE || ctype_digit( $norm ) ) {
						continue;
					}
					if ( ! isset( $schatz[ $norm ] ) ) {
						$schatz[ $norm ] = array( 'wort' => $wort, 'n' => 0 );
					}
					$schatz[ $norm ]['n'] += $gewicht;
				}
			}
		}

		$schatz = self::deckeln( $schatz );
		set_transient( self::TRANSIENT, $schatz, DAY_IN_SECONDS );
		return $schatz;
	}

	/**
	 * Wortschatz aus einer Liste von Texten bauen. Ohne WordPress aufrufbar –
	 * daran hängt der Test.
	 */
	public static function aus_titeln( array $titel ) {
		$schatz = array();
		foreach ( $titel as $t ) {
			foreach ( self::zerlegen( $t ) as $wort ) {
				$norm = self::normalisieren( $wort );
				if ( strlen( $norm ) < self::MIN_LAENGE || ctype_digit( $norm ) ) {
					continue;
				}
				if ( ! isset( $schatz[ $norm ] ) ) {
					$schatz[ $norm ] = array( 'wort' => $wort, 'n' => 0 );
				}
				$schatz[ $norm ]['n']++;
			}
		}
		return self::deckeln( $schatz );
	}

	/** Obergrenze durchsetzen: die häufigsten Wörter bleiben. */
	private static function deckeln( array $schatz ) {
		if ( count( $schatz ) <= self::MAX_WOERTER ) {
			return $schatz;
		}
		uasort( $schatz, function ( $a, $b ) {
			return $b['n'] - $a['n'];
		} );
		return array_slice( $schatz, 0, self::MAX_WOERTER, true );
	}

	/* ===================================================================
	 *  Wortvorschläge (Autovervollständigung)
	 * =================================================================== */

	/**
	 * Wörter aus dem Wortschatz, die mit der Eingabe beginnen.
	 *
	 * NUR ANFÄNGE, keine Treffer mittendrin: „arbeit" soll „Arbeitsrecht"
	 * vorschlagen, nicht „Sozialarbeit". Wer den Anfang eines Wortes tippt,
	 * meint dieses Wort – ein Vorschlag, der irgendwo in der Mitte passt, wirkt
	 * wie ein Fehler.
	 *
	 * Sortiert nach Häufigkeit: Der Begriff, an dem die meisten Seminare
	 * hängen, steht oben.
	 *
	 * @param string $anfang Nur das letzte Wort der Eingabe.
	 * @param int    $limit  Wie viele Vorschläge höchstens.
	 * @return string[] Originalschreibweisen.
	 */
	public static function wort_vorschlaege( $anfang, $limit = 5, $schatz = null ) {
		$norm = self::normalisieren( $anfang );
		if ( strlen( $norm ) < 2 ) {
			return array();
		}
		if ( ! is_array( $schatz ) ) {
			$schatz = self::wortschatz();
		}
		$treffer = array();
		foreach ( $schatz as $kandidat => $eintrag ) {
			if ( $kandidat === $norm || 0 !== strpos( $kandidat, $norm ) ) {
				continue; // das schon getippte Wort ist kein Vorschlag
			}
			$treffer[] = array( 'wort' => $eintrag['wort'], 'n' => (int) $eintrag['n'] );
		}
		usort( $treffer, function ( $a, $b ) {
			if ( $a['n'] === $b['n'] ) {
				return strlen( $a['wort'] ) - strlen( $b['wort'] ); // bei Gleichstand das kürzere
			}
			return $b['n'] - $a['n'];
		} );
		return array_column( array_slice( $treffer, 0, max( 0, (int) $limit ) ), 'wort' );
	}

	/* ===================================================================
	 *  Korrigieren
	 * =================================================================== */

	/**
	 * Wie viele Buchstaben dürfen danebenliegen?
	 *
	 * Kurze Wörter sind heikel: Bei vier Buchstaben ist der Abstand 2 schon ein
	 * anderes Wort („Bonn" -> „Bonn"/„Born"/„Bann"). Deshalb wächst die Toleranz
	 * mit der Länge, und sie bleibt klein genug, dass eine Korrektur eine
	 * Korrektur bleibt und kein Vorschlag ins Blaue.
	 */
	private static function toleranz( $laenge ) {
		if ( $laenge <= 5 ) {
			return 1;
		}
		return ( $laenge <= 9 ) ? 2 : 3;
	}

	/**
	 * Ein einzelnes Wort korrigieren.
	 *
	 * @return string Originalschreibweise aus dem Wortschatz, oder '' , wenn das
	 *                Wort schon passt bzw. nichts nah genug liegt.
	 */
	public static function wort_korrigieren( $wort, array $schatz ) {
		$norm = self::normalisieren( $wort );
		$len  = strlen( $norm );
		if ( $len < self::MIN_LAENGE || ! $schatz ) {
			return '';
		}

		// Bekanntes Wort: nur dann ersetzen, wenn es ANDERS geschrieben wurde –
		// „betriebsraete" wird zu „Betriebsräte", „Betriebsräte" bleibt.
		if ( isset( $schatz[ $norm ] ) ) {
			return ( $schatz[ $norm ]['wort'] !== $wort ) ? $schatz[ $norm ]['wort'] : '';
		}
		// Anfang eines längeren Wortes: „arbeit" findet „Arbeitsrecht" von selbst
		// und soll nicht zu „Arbeitet" werden.
		foreach ( $schatz as $kandidat => $eintrag ) {
			if ( 0 === strpos( $kandidat, $norm ) ) {
				return '';
			}
		}

		$max     = self::toleranz( $len );
		$treffer = '';
		$besser  = $max + 1;
		$haeufig = 0;

		foreach ( $schatz as $kandidat => $eintrag ) {
			// Längenfilter vorweg: Wer sich um mehr als die Toleranz in der Länge
			// unterscheidet, kann den Abstand nicht mehr unterbieten – und
			// levenshtein() für jedes der tausenden Wörter wäre Verschwendung.
			if ( abs( strlen( $kandidat ) - $len ) > $max ) {
				continue;
			}
			$abstand = levenshtein( $norm, $kandidat );
			if ( $abstand > $max ) {
				continue;
			}
			// Näher gewinnt; bei gleichem Abstand das häufigere Wort.
			if ( $abstand < $besser || ( $abstand === $besser && $eintrag['n'] > $haeufig ) ) {
				$besser  = $abstand;
				$haeufig = $eintrag['n'];
				$treffer = $eintrag['wort'];
			}
		}
		return $treffer;
	}

	/**
	 * Ganze Anfrage korrigieren.
	 *
	 * @return string Korrigierte Anfrage oder '' , wenn nichts zu korrigieren
	 *                war (oder nichts Nahes gefunden wurde).
	 */
	public static function korrigieren( $anfrage, $schatz = null ) {
		$woerter = self::zerlegen( $anfrage );
		if ( ! $woerter ) {
			return '';
		}
		if ( ! is_array( $schatz ) ) {
			$schatz = self::wortschatz();
		}

		$neu      = array();
		$geaendert = false;
		foreach ( $woerter as $w ) {
			$k = self::wort_korrigieren( $w, $schatz );
			if ( '' !== $k ) {
				$neu[]     = $k;
				$geaendert = true;
			} else {
				$neu[] = $w;
			}
		}
		return $geaendert ? implode( ' ', $neu ) : '';
	}
}
