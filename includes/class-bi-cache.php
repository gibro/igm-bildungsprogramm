<?php
/**
 * Seiten-Cache leeren, wenn sich am Seminarbestand etwas geändert hat.
 *
 * ============================================================================
 *  WOZU
 * ============================================================================
 *  Ein Seiten-Cache liefert fertiges HTML aus, ohne PHP zu starten – auf
 *  bildung.igmetall.de der Unterschied zwischen rund einer Sekunde und rund
 *  fünfzig Millisekunden. Er hat aber keine Ahnung davon, wann unsere Daten
 *  sich ändern. Ohne diese Klasse zeigte die Seminarliste nach einem CSV-Import
 *  stundenlang den alten Bestand, und die Trefferzahl in der Filterleiste
 *  („1.200 buchbare Seminare") stünde eingefroren im HTML.
 *
 *  Ein Cache, der falsche Zahlen ausliefert, ist schlimmer als kein Cache:
 *  Beim langsamen Aufruf sieht man wenigstens, was stimmt.
 *
 * ============================================================================
 *  ZWEI ANLÄSSE
 * ============================================================================
 *  1. DATEN GEÄNDERT – Seminar gespeichert, importiert, massenbearbeitet,
 *     gelöscht, Begriffe zugeordnet.
 *
 *  2. DER TAG HAT GEWECHSELT. „Buchbar" heißt Startdatum ab HEUTE, und das
 *     wird beim Rendern ausgewertet. Eine um 23:50 gespeicherte Seite zeigt um
 *     00:10 noch das Seminar von gestern. Deshalb läuft kurz nach Mitternacht
 *     ein Leeren, ohne dass sich irgendetwas geändert haben muss.
 *
 * ============================================================================
 *  EINMAL JE AUFRUF, HÖCHSTENS EINMAL JE MINUTE
 * ============================================================================
 *  Ein Import setzt zwanzig Meta-Felder je Zeile und legt hundert Zeilen je
 *  Häppchen an. Würde jede Änderung sofort leeren, liefen tausende Löschläufe
 *  über das Cache-Verzeichnis. Deshalb:
 *
 *    - Während des Aufrufs wird nur VORGEMERKT.
 *    - Am Ende (shutdown) wird EINMAL geleert.
 *    - Und höchstens einmal je Minute: Kommt in der Sperrfrist noch etwas,
 *      bleibt es als „offen" stehen und wird beim nächsten Aufruf nachgeholt.
 *      Sonst blieben nach dem letzten Häppchen eines Imports genau die
 *      Änderungen ungeleert, auf die es ankommt.
 *
 *  Das Nachholen hängt NICHT an WP-Cron allein: Auf vielen Installationen ist
 *  der abgeschaltet. Ein offener Auftrag wird deshalb auch von jedem beliebigen
 *  Aufruf erledigt, sobald die Sperrfrist um ist.
 *
 * ============================================================================
 *  WELCHE CACHES ERKANNT WERDEN
 * ============================================================================
 *  WP Super Cache, WP Rocket, W3 Total Cache, WP Fastest Cache, Cache Enabler,
 *  LiteSpeed Cache, SG Optimizer, Kinsta, WP Engine.
 *
 *  NICHT dabei ist Autoptimize: Das ist kein Seiten-Cache, sondern ein
 *  Asset-Optimierer. Seinen Zwischenspeicher bei jeder Seminaränderung zu
 *  leeren, hieße CSS und JS ohne Grund neu bauen zu lassen.
 *
 *  Erkannt wird an der Funktion bzw. Klasse, nicht am Plugin-Namen – so wirkt
 *  es auch, wenn jemand zwei davon parallel betreibt.
 *
 *  Ist keiner da, passiert schlicht nichts. Und wer einen anderen benutzt,
 *  hängt sich an die Aktion `bi_cache_leeren` (siehe leeren()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Cache {

	/** Sperre gegen Leer-Stürme (Transient, Sekunden). */
	const SPERRE = 'bi_cache_sperre';

	/** Ein Leeren war fällig, kam aber in die Sperrfrist. */
	const OPTION_OFFEN = 'bi_cache_offen';

	/** Kürzester Abstand zwischen zwei Leerläufen. */
	const ABSTAND = 60;

	/** Nächtliches Leeren wegen des Tageswechsels (wiederkehrend). */
	const CRON_TAG = 'bi_cache_tageswechsel';

	/**
	 * Einmaliger Anlauf für einen offenen Auftrag.
	 *
	 * BEWUSST EIN ZWEITER HAKEN: Mit nur einem fände wp_next_scheduled() immer
	 * den täglichen Termin, und der Nachhol-Anlauf würde nie angemeldet – bzw.
	 * umgekehrt der tägliche nie, solange ein Nachholer offen ist.
	 */
	const CRON_NACH = 'bi_cache_nachholen';

	/** Wurde in diesem Aufruf etwas geändert? */
	private static $faellig = false;

	public static function init() {
		foreach ( self::post_types() as $pt ) {
			add_action( 'save_post_' . $pt, array( __CLASS__, 'vormerken_post' ), 100 );
		}
		add_action( 'deleted_post', array( __CLASS__, 'vormerken_post' ) );
		add_action( 'trashed_post', array( __CLASS__, 'vormerken_post' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'vormerken_post' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'vormerken_post' ) );

		// Massenbearbeitung, CSV-Import und die Ampel schreiben Meta-Felder,
		// ohne dass save_post noch einmal feuert. „Ausgebucht", „Freie Plätze"
		// und das Startdatum stehen genau dort – und sie ändern die Seite.
		add_action( 'added_post_meta', array( __CLASS__, 'vormerken_meta' ), 10, 2 );
		add_action( 'updated_post_meta', array( __CLASS__, 'vormerken_meta' ), 10, 2 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'vormerken_meta' ), 10, 2 );

		// Priorität 20: erst zieht BI_Suche den Suchindex nach (shutdown/10),
		// dann wird geleert. Andersherum baute der nächste Besucher die Seite
		// womöglich noch aus dem alten Index auf.
		add_action( 'shutdown', array( __CLASS__, 'nachtragen' ), 20 );

		// Offenen Auftrag nachholen – ohne auf WP-Cron angewiesen zu sein.
		add_action( 'init', array( __CLASS__, 'offenes_nachholen' ) );

		// Tageswechsel und Nachhol-Anlauf
		add_action( self::CRON_TAG, array( __CLASS__, 'leeren' ) );
		add_action( self::CRON_NACH, array( __CLASS__, 'leeren' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );
	}

	/** Beitragstypen, deren Änderung die öffentlichen Seiten betrifft. */
	private static function post_types() {
		$typen = bi_seminar_post_types();
		if ( class_exists( 'BI_Reihen' ) ) {
			$typen[] = BI_Reihen::CPT;
		}
		return $typen;
	}

	/* ===================================================================
	 *  Vormerken
	 * =================================================================== */

	public static function vormerken_post( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( get_post_type( $post_id ), self::post_types(), true ) ) {
			return;
		}
		self::$faellig = true;
	}

	/**
	 * Meta-Änderung an einem unserer Beiträge.
	 *
	 * BEWUSST OHNE FELDLISTE: Anders als beim Suchindex zählt hier JEDES Feld.
	 * „Ausgebucht", „Freie Plätze" und das Startdatum tragen keine Wörter und
	 * stehen deshalb nicht im Suchindex – die Seite ändern sie trotzdem.
	 *
	 * Ein Sturm droht dadurch nicht: Der Suchindex steht in einer eigenen
	 * Tabelle und löst gar keine Meta-Hooks aus, und alles Übrige fängt die
	 * Sperrfrist ab (siehe leeren()).
	 */
	public static function vormerken_meta( $meta_id, $post_id ) {
		self::vormerken_post( $post_id );
	}

	/* ===================================================================
	 *  Leeren
	 * =================================================================== */

	/** Am Ende des Aufrufs: einmal leeren, wenn etwas anlag. */
	public static function nachtragen() {
		if ( ! self::$faellig ) {
			return;
		}
		self::$faellig = false;
		self::leeren();
	}

	/**
	 * Einen offenen Auftrag nachholen, sobald die Sperrfrist um ist.
	 *
	 * Läuft an `init`, also bei jedem Aufruf – aber nur, wenn tatsächlich etwas
	 * offen ist, und dann kostet es einen Options-Lesezugriff.
	 */
	public static function offenes_nachholen() {
		if ( ! get_option( self::OPTION_OFFEN ) || get_transient( self::SPERRE ) ) {
			return;
		}
		self::leeren();
	}

	/**
	 * Den Seiten-Cache leeren.
	 *
	 * @param bool $sofort true = Sperrfrist übergehen (für „von Hand leeren").
	 * @return bool Wurde tatsächlich geleert?
	 */
	public static function leeren( $sofort = false ) {
		if ( ! $sofort && get_transient( self::SPERRE ) ) {
			// In der Sperrfrist: als offen vormerken, damit der letzte Stand
			// nicht verlorengeht, und einen Cron-Anlauf anmelden.
			update_option( self::OPTION_OFFEN, 1, false );
			if ( ! wp_next_scheduled( self::CRON_NACH ) ) {
				wp_schedule_single_event( time() + self::ABSTAND + 5, self::CRON_NACH );
			}
			return false;
		}

		set_transient( self::SPERRE, 1, self::ABSTAND );
		delete_option( self::OPTION_OFFEN );

		foreach ( self::leerer() as $leerer ) {
			call_user_func( $leerer['ruf'] );
		}

		/**
		 * Für jeden Cache, den das Plugin nicht kennt.
		 *
		 * add_action( 'bi_cache_leeren', function () { … } );
		 */
		do_action( 'bi_cache_leeren' );

		update_option( 'bi_cache_zuletzt', current_time( 'mysql' ), false );
		return true;
	}

	/**
	 * Die erkannten Caches: Name => Aufruf.
	 *
	 * Geprüft wird die Funktion bzw. Klasse, nicht der Plugin-Name – so greift
	 * es auch, wenn zwei parallel laufen, und es bricht nicht, wenn ein Plugin
	 * umbenannt wird.
	 */
	public static function leerer() {
		$gefunden = array();

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			$gefunden[] = array( 'name' => 'WP Super Cache', 'ruf' => 'wp_cache_clear_cache' );
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			$gefunden[] = array( 'name' => 'WP Rocket', 'ruf' => 'rocket_clean_domain' );
		}
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			$gefunden[] = array( 'name' => 'W3 Total Cache', 'ruf' => 'w3tc_flush_posts' );
		}
		if ( class_exists( 'Cache_Enabler' ) && method_exists( 'Cache_Enabler', 'clear_complete_cache' ) ) {
			$gefunden[] = array( 'name' => 'Cache Enabler', 'ruf' => array( 'Cache_Enabler', 'clear_complete_cache' ) );
		}
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$gefunden[] = array(
				'name' => 'WP Fastest Cache',
				'ruf'  => function () { $GLOBALS['wp_fastest_cache']->deleteCache( true ); },
			);
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			$gefunden[] = array(
				'name' => 'LiteSpeed Cache',
				'ruf'  => function () { do_action( 'litespeed_purge_all' ); },
			);
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			$gefunden[] = array( 'name' => 'SiteGround Optimizer', 'ruf' => 'sg_cachepress_purge_cache' );
		}
		if ( class_exists( 'Kinsta\\Cache' ) && ! empty( $GLOBALS['kinsta_cache'] ) ) {
			$gefunden[] = array(
				'name' => 'Kinsta Cache',
				'ruf'  => function () { $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches(); },
			);
		}
		if ( class_exists( 'WpeCommon' ) && method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			$gefunden[] = array( 'name' => 'WP Engine', 'ruf' => array( 'WpeCommon', 'purge_varnish_cache' ) );
		}
		return (array) apply_filters( 'bi_cache_leerer', $gefunden );
	}

	/** Namen der erkannten Caches – für die Anzeige in den Einstellungen. */
	public static function namen() {
		return wp_list_pluck( self::leerer(), 'name' );
	}

	/** Wann zuletzt geleert wurde ('' = noch nie). */
	public static function zuletzt() {
		return (string) get_option( 'bi_cache_zuletzt', '' );
	}

	/* ===================================================================
	 *  Tageswechsel
	 * =================================================================== */

	/**
	 * Kurz nach Mitternacht einmal leeren.
	 *
	 * WOZU: „Buchbar" heißt Startdatum ab HEUTE, ausgewertet beim Rendern. Ohne
	 * diesen Lauf zeigte eine gestern gespeicherte Seite heute noch den Termin
	 * von gestern – und zwar so lange, bis der Cache von sich aus abläuft.
	 *
	 * Zehn Minuten nach Mitternacht, nicht Punkt: WP-Cron läuft nur, wenn
	 * jemand die Seite aufruft, und um Punkt Mitternacht ist das seltener.
	 */
	public static function ensure_cron() {
		if ( wp_next_scheduled( self::CRON_TAG ) ) {
			return;
		}
		// current_time('timestamp') liefert die ORTSZEIT als Zeitstempel; WP-Cron
		// rechnet in UTC. Deshalb den Versatz wieder abziehen.
		$lokal   = strtotime( 'tomorrow 00:10', current_time( 'timestamp' ) );
		$versatz = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		wp_schedule_event( $lokal - $versatz, 'daily', self::CRON_TAG );
	}

	public static function cron_abmelden() {
		foreach ( array( self::CRON_TAG, self::CRON_NACH ) as $hook ) {
			$ts = wp_next_scheduled( $hook );
			if ( $ts ) {
				wp_unschedule_event( $ts, $hook );
			}
		}
	}
}
