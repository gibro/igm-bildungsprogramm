<?php
/**
 * ============================================================
 *  Abgleich zwischen mehreren Installationen  (BI_Sync)
 * ============================================================
 *
 * Das Bildungsprogramm läuft an mehreren Orten: bildung.igmetall.de zeigt den
 * ganzen Bestand, igmetall-sprockhoevel.de und igmetall-bildung-berlin.de je
 * nur ihren eigenen. Gepflegt wird dort, wo die Seminare herkommen – und genau
 * dort entsteht das Problem, das dieses Modul löst: Eine Korrektur in
 * Sprockhövel stand bisher nur in Sprockhövel.
 *
 * ── Warum kein iframe ────────────────────────────────────────────────────
 * Weil die Satellitenseiten wirklich nur ihre eigenen Seminare zeigen sollen.
 * Ein eingebetteter zentraler Bestand wäre entweder zu groß (alles) oder eine
 * gefilterte Ansicht, die sich mit jeder Filterwahl der Besucher wieder öffnet.
 * Die Satelliten führen ihren Bestand deshalb selbst; abgeglichen wird darunter.
 *
 * ── Die Rollen ───────────────────────────────────────────────────────────
 * Jede Installation ist entweder QUELLE oder ZENTRALE – nie beides:
 *
 *   QUELLE   (Sprockhövel, Berlin)
 *            gibt ihren Bestand über zwei geschützte Adressen heraus und meldet
 *            der Zentrale, wenn sich etwas geändert hat. Sie schreibt nie in
 *            eine andere Installation.
 *
 *   ZENTRALE (bildung.igmetall.de)
 *            holt ab, schreibt, blendet Verschwundenes aus und führt Protokoll.
 *
 * ── Warum Holen und nicht Schicken ───────────────────────────────────────
 * Der naheliegende Weg wäre, die Quelle beim Speichern das geänderte Seminar
 * an die Zentrale schicken zu lassen. Das ist die fragilste aller Varianten:
 * Ist die Zentrale in dieser Sekunde nicht erreichbar – Wartung, Zeitüberschreitung,
 * ein hängender Datenbankserver –, ist die Änderung weg. Niemand merkt es,
 * denn in der Quelle sieht alles richtig aus. Genau so entstehen die
 * auseinanderlaufenden Datenstände, die dieses Modul verhindern soll.
 *
 * Deshalb: Die Quelle schickt beim Speichern nur ein KLOPFEN – ein paar Bytes,
 * kein Inhalt. Die Zentrale holt daraufhin ab. Und unabhängig davon läuft in
 * der Zentrale ein Takt, der ohnehin regelmäßig nachfragt. Geht das Klopfen
 * verloren, holt der nächste Takt alles nach. Der Abgleich ist damit
 * selbstheilend: Er braucht kein einziges Ereignis, um vollständig zu werden –
 * die Ereignisse machen ihn nur schnell.
 *
 * ── Woran ein Seminar wiedererkannt wird ─────────────────────────────────
 * An der SEMINARNUMMER, wie überall in diesem Plugin (CSV-Import, JSON-Paket,
 * Verfügbarkeits-Ampel). Post-IDs taugen nicht: Sie sind in jeder Installation
 * andere.
 *
 * Der Schlüssel ist aber nicht die Nummer allein, sondern QUELLE + BEITRAGSTYP +
 * NUMMER. Ohne die Quelle im Schlüssel würden sich Berlin und Sprockhövel
 * gegenseitig überschreiben, sobald sie dieselbe Nummer vergeben – und dass zwei
 * unabhängig geführte Nummernkreise irgendwann kollidieren, ist keine Frage des
 * Ob.
 *
 * Ein Seminar OHNE Seminarnummer wird nicht abgeglichen. Es hat keine Identität,
 * die den Sprung zwischen zwei Datenbanken überlebt; jeder Lauf legte es erneut
 * an. Solche Einträge stehen namentlich im Protokoll.
 *
 * ── Der Erstlauf überschreibt, er verdoppelt nicht ───────────────────────
 * Die Zentrale hat die Sprockhövel- und Berlin-Seminare heute schon, importiert
 * per CSV. Findet der Abgleich kein gestempeltes Gegenstück, sucht er deshalb
 * ein zweites Mal – nach der blanken Seminarnummer. Was er findet, ÜBERNIMMT er:
 * Der vorhandene Eintrag bekommt den Herkunftsstempel und wird von da an
 * abgeglichen. Ohne diesen zweiten Blick stünde nach dem ersten Lauf jedes
 * Seminar doppelt in der Zentrale.
 *
 * ── Schreibschutz in der Zentrale ────────────────────────────────────────
 * Abgeglichene Seminare sind in der Zentrale nur lesbar. Das ist keine
 * Bevormundung, sondern die einzige ehrliche Konsequenz aus „die Quelle
 * gewinnt": Eine Korrektur, die der nächste Lauf ohne Nachfrage überschreibt,
 * ist schlimmer als eine, die gar nicht erst möglich ist – im ersten Fall
 * glaubt jemand, sie sei erledigt.
 *
 * Wer einen Eintrag wirklich in der Zentrale übernehmen will, LÖST ihn aus dem
 * Abgleich (Knopf in der Seminarliste). Er ist dann frei bearbeitbar und wird
 * nicht mehr angefasst – auch nicht wieder eingefangen.
 *
 * ── Was NICHT abgeglichen wird ───────────────────────────────────────────
 * Anmeldungen, Anmeldeformulare, Mail-Trigger, PLZ-Tabelle, Kampagnen,
 * Einstellungen. Abgeglichen werden Seminare (Präsenz und Online), die von
 * ihnen benutzten Begriffe und – auf Wunsch – ihre Ausbildungsreihen.
 *
 * ANMELDUNGEN LIEGEN GETRENNT. Ist dasselbe Seminar auf zwei Seiten anmeldbar,
 * gibt es zwei Anmeldelisten und zwei Platzzählungen. Das ist so entschieden;
 * das Protokoll weist deshalb aus, wie viele Anmeldungen die Quelle zu einem
 * Seminar führt, damit die Zahl in der Zentrale nicht für die ganze Wahrheit
 * gehalten wird.
 *
 * ── Sicherheit ───────────────────────────────────────────────────────────
 * Jede Verbindung hat ein gemeinsames Geheimnis, das auf beiden Seiten steht.
 * Es geht im Kopf `X-BI-Sync-Key` mit und wird mit hash_equals() verglichen –
 * zeitkonstant, damit sich der Schlüssel nicht Zeichen für Zeichen erraten
 * lässt. Ohne eingetragene Gegenstelle wird die Adresse gar nicht erst
 * registriert: Was es nicht gibt, kann nicht angegriffen werden. Dazu ein
 * Kontingent gegen Rateversuche.
 *
 * Die Adressen geben ausschließlich Seminardaten heraus – nie Anmeldungen,
 * nie personenbezogene Daten.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Sync {

	/** Einstellungen dieses Moduls (eigene Option, nicht in bi_settings) */
	const OPTION = 'bi_sync';

	/** Zustand des laufenden Abgleichs (Zentrale) */
	const OPT_LAUF = 'bi_sync_lauf';

	/** Berichte der letzten Läufe (Zentrale) */
	const OPT_PROTOKOLL = 'bi_sync_protokoll';

	/** Quellen, die noch abzuholen sind (Zentrale) */
	const OPT_WARTE = 'bi_sync_warteschlange';

	/** Zeitpunkt des letzten vollständigen Takts je Quelle (Zentrale) */
	const OPT_TAKT = 'bi_sync_takt_stand';

	const HOOK_TICK = 'bi_sync_tick';        // Taktgeber der Zentrale (alle 5 Minuten)
	const HOOK_PING = 'bi_sync_klopfen';     // Sammel-Meldung der Quelle
	const SCHEDULE  = 'bi_sync_5min';

	const NS     = 'bi/v1';
	const HEADER = 'X-BI-Sync-Key';

	/* ---- Stempel an den Beiträgen der Zentrale ---- */
	const META_QUELLE    = '_bi_sync_quelle';      // Slug der Quelle, aus der der Eintrag stammt
	const META_SCHLUESSEL= '_bi_sync_schluessel';  // "<post_type>:<seminarnummer>"
	const META_STAND     = '_bi_sync_stand';       // Änderungsmarke der Quelle beim letzten Abgleich
	const META_EDIT      = '_bi_sync_edit_url';    // Bearbeiten-Adresse in der Quelle
	const META_ANMELDUNGEN = '_bi_sync_anmeldungen'; // Anmeldungen, die in der Quelle liegen
	const META_GELOEST   = '_bi_sync_geloest';     // 1 = von Hand aus dem Abgleich gelöst
	const META_FEHLT     = '_bi_sync_fehlt';       // Datum, an dem der Eintrag in der Quelle verschwand

	/* ---- Änderungsmarke an den Beiträgen der Quelle ---- */
	const META_GEAENDERT = '_bi_sync_geaendert';

	/** Seminare je HTTP-Anfrage. Bewusst klein: ein Paket trägt ganze Fließtexte. */
	const HAEPPCHEN = 25;

	/** Sekunden, die ein Cron-Aufruf höchstens arbeitet, bevor er sich neu einplant. */
	const BUDGET = 20;

	/**
	 * Budget für den Knopf „Jetzt abgleichen".
	 *
	 * Großzügiger als beim Cron, weil ein Mensch davor sitzt und wartet: Ein
	 * Lauf, der nach 20 Sekunden abgibt und auf den nächsten Cron-Aufruf hofft,
	 * sieht von der Bedienoberfläche aus wie „nichts passiert".
	 */
	const BUDGET_HAND = 45;

	/**
	 * So viele fehlgeschlagene Häppchen in Folge, dann Abbruch mit Bericht.
	 *
	 * Ohne diese Grenze arbeitete sich ein Lauf bei einer nicht erreichbaren
	 * Quelle durch ALLE Häppchen – jedes mit vollem Zeitüberschreitungs-Timeout –,
	 * ohne je fertig zu werden und damit ohne je einen Bericht zu schreiben.
	 * Genau die Kombination, bei der die Oberfläche schweigt, obwohl etwas
	 * kaputt ist.
	 */
	const FEHLVERSUCHE = 3;

	/** Wartezeit zwischen Änderung und Klopfen. Fängt Import-Läufe ab. */
	const KLOPF_VERZUG = 60;

	/** Fassung des Austauschformats. Erhöhen, wenn sich die Struktur ändert. */
	const FORMAT_VERSION = 1;

	/* ===================================================================
	 *  Einstellungen
	 * =================================================================== */

	public static function defaults() {
		return array(
			// 'aus' | 'quelle' | 'zentrale'
			'rolle'     => 'aus',
			// Rolle QUELLE: Zentralen, die abholen dürfen – [ ['url','schluessel'] ]
			'zentralen' => array(),
			// Rolle ZENTRALE: Quellen, die abgeholt werden – [ ['slug','name','url','schluessel'] ]
			'quellen'   => array(),
			// Rolle QUELLE: welche Post-Status herausgegeben werden
			'status'    => array( 'publish' ),
			// Nur abgleichen, was noch bevorsteht (siehe zeitfenster_sql/im_zeitfenster)
			'nur_kuenftig' => 1,
			// Tage Karenz vor dem heutigen Tag
			'karenz'    => 30,
			// Rolle ZENTRALE: Minuten zwischen zwei vollständigen Takten
			'takt'      => 60,
			// Ausbildungsreihen mitnehmen
			'reihen'    => 1,
			// Beitragsbilder holen (nur wo noch keines gesetzt ist)
			'bilder'    => 0,
			// Rolle ZENTRALE: abgeglichene Seminare gegen Bearbeiten sperren
			'schutz'    => 1,
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$s     = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );

		// Verirrte Werte abfangen: Die Option wird auch von Hand gepflegt, und
		// ein Skalar, wo eine Liste erwartet wird, wäre ein Fatal Error mitten
		// im Cron – also an einer Stelle, an der ihn niemand sieht.
		foreach ( array( 'zentralen', 'quellen', 'status' ) as $key ) {
			if ( ! is_array( $s[ $key ] ) ) {
				$s[ $key ] = array();
			}
		}
		if ( ! $s['status'] ) {
			$s['status'] = array( 'publish' );
		}
		return $s;
	}

	public static function get( $key ) {
		$s = self::all();
		return $s[ $key ] ?? null;
	}

	/**
	 * Autoload absichtlich AN: Die Rolle wird bei jeder einzelnen Anfrage
	 * gebraucht – init() entscheidet daran, welche Haken es überhaupt setzt.
	 * Ohne Autoload wäre das eine zusätzliche Datenbankabfrage auf jeder
	 * Frontend-Seite, für ein paar hundert Bytes.
	 */
	public static function save( $s ) {
		update_option( self::OPTION, $s, true );
	}

	public static function ist_quelle() {
		return 'quelle' === self::get( 'rolle' );
	}

	public static function ist_zentrale() {
		return 'zentrale' === self::get( 'rolle' );
	}

	/* ===================================================================
	 *  Einhängen
	 * =================================================================== */

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Speicher-Handler der Einstellungsseite (beide Rollen)
		add_action( 'admin_post_bi_sync_speichern', array( __CLASS__, 'handle_speichern' ) );
		add_action( 'admin_post_bi_sync_jetzt', array( __CLASS__, 'handle_jetzt' ) );
		add_action( 'admin_post_bi_sync_loesen', array( __CLASS__, 'handle_loesen' ) );
		add_action( 'admin_post_bi_sync_abbrechen', array( __CLASS__, 'handle_abbrechen' ) );

		if ( self::ist_quelle() ) {
			// Änderungsmarke setzen. Ohne sie bliebe eine Änderung unsichtbar, die
			// nur Meta-Felder betrifft – etwa der Haken „Ausgebucht", den die
			// Verfügbarkeits-Ampel programmgesteuert setzt: post_modified rührt
			// sie nicht an.
			foreach ( bi_seminar_post_types() as $pt ) {
				add_action( 'save_post_' . $pt, array( __CLASS__, 'markieren' ), 99 );
			}
			add_action( 'added_post_meta', array( __CLASS__, 'markieren_meta' ), 10, 3 );
			add_action( 'updated_post_meta', array( __CLASS__, 'markieren_meta' ), 10, 3 );
			add_action( 'deleted_post_meta', array( __CLASS__, 'markieren_meta' ), 10, 3 );
			add_action( 'set_object_terms', array( __CLASS__, 'markieren_terms' ), 10, 6 );

			// Löschen und Papierkorb: Der Eintrag verschwindet aus dem Bestand.
			// Gemeldet wird nur, dass es Neues gibt – WAS fehlt, stellt die
			// Zentrale beim Abgleich der Bestandsliste selbst fest.
			add_action( 'trashed_post', array( __CLASS__, 'klopfen_planen' ) );
			add_action( 'untrashed_post', array( __CLASS__, 'klopfen_planen' ) );
			add_action( 'deleted_post', array( __CLASS__, 'klopfen_planen' ) );

			add_action( self::HOOK_PING, array( __CLASS__, 'klopfen_senden' ) );
		}

		if ( self::ist_zentrale() ) {
			add_action( self::HOOK_TICK, array( __CLASS__, 'tick' ) );
			self::ensure_cron();

			if ( self::get( 'schutz' ) ) {
				add_filter( 'map_meta_cap', array( __CLASS__, 'schreibschutz' ), 10, 4 );
			}
			add_filter( 'post_row_actions', array( __CLASS__, 'zeilen_aktion' ), 10, 2 );
			add_action( 'admin_notices', array( __CLASS__, 'hinweis_im_editor' ) );

			foreach ( bi_seminar_post_types() as $pt ) {
				add_filter( 'manage_' . $pt . '_posts_columns', array( __CLASS__, 'spalte' ) );
				add_action( 'manage_' . $pt . '_posts_custom_column', array( __CLASS__, 'spalte_inhalt' ), 10, 2 );
			}
		}
	}

	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Alle 5 Minuten (Abgleich)',
		);
		return $schedules;
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK_TICK );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::HOOK_TICK );
		wp_clear_scheduled_hook( self::HOOK_PING );
	}

	/* ===================================================================
	 *  Rolle QUELLE – Änderungsmarke und Klopfen
	 * =================================================================== */

	/**
	 * Änderungsmarke am Seminar setzen und das Klopfen einplanen.
	 *
	 * Die Marke ist eine GMT-Sekunde, kein Datum: Sie wird zwischen zwei
	 * Installationen mit womöglich verschiedener Zeitzone verglichen, und dabei
	 * ist jede Formatierung eine Fehlerquelle mehr.
	 */
	public static function markieren( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// EINMAL JE ANFRAGE GENÜGT. Ein CSV-Import schreibt vierzig Meta-Felder
		// je Seminar, und jedes davon löst diesen Haken aus – bei 500 Seminaren
		// wären das 20.000 zusätzliche Schreibvorgänge für eine Zahl, die sich
		// innerhalb einer Anfrage ohnehin nicht ändert.
		static $gestempelt = array();
		if ( isset( $gestempelt[ $post_id ] ) ) {
			return;
		}

		if ( ! bi_is_seminar_post( $post_id ) ) {
			return;
		}
		$gestempelt[ $post_id ] = true;
		update_post_meta( $post_id, self::META_GEAENDERT, time() );
		self::klopfen_planen();
	}

	/**
	 * Meta-Änderung an einem Seminar.
	 *
	 * Die eigene Marke ist ausgenommen – sonst riefe sich das Setzen der Marke
	 * selbst wieder auf. Ebenso die Notizfelder der Ampel: Sie halten fest, WIE
	 * ein Wert zustande kam, nicht den Wert selbst.
	 */
	public static function markieren_meta( $meta_id, $post_id, $meta_key ) {
		if ( self::META_GEAENDERT === $meta_key ) {
			return;
		}
		if ( 0 !== strpos( (string) $meta_key, '_bi_' ) ) {
			return;
		}
		self::markieren( $post_id );
	}

	/** Begriffs-Zuordnung geändert. */
	public static function markieren_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		self::markieren( $object_id );
	}

	/**
	 * Das Klopfen einplanen – nicht senden.
	 *
	 * Ein CSV-Import schreibt hunderte Seminare in einem Durchgang. Würde jede
	 * Änderung sofort melden, liefen hunderte HTTP-Anfragen mitten im Import –
	 * und die Zentrale bekäme hundertmal dieselbe Nachricht. Ein einziges
	 * verzögertes Ereignis genügt: Die Zentrale holt danach ohnehin ALLES ab,
	 * was sich seit ihrem letzten Stand geändert hat.
	 */
	public static function klopfen_planen( $post_id = 0 ) {
		if ( $post_id && ! bi_is_seminar_post( $post_id ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK_PING ) ) {
			return; // steht schon an
		}
		wp_schedule_single_event( time() + self::KLOPF_VERZUG, self::HOOK_PING );
	}

	/** Allen eingetragenen Zentralen melden, dass es Neues gibt. */
	public static function klopfen_senden() {
		foreach ( self::all()['zentralen'] as $z ) {
			$url = isset( $z['url'] ) ? esc_url_raw( (string) $z['url'] ) : '';
			$key = isset( $z['schluessel'] ) ? (string) $z['schluessel'] : '';
			if ( ! $url || '' === $key ) {
				continue;
			}
			wp_remote_post( trailingslashit( $url ) . 'wp-json/' . self::NS . '/sync/klopfen', array(
				'timeout'  => 8,
				'blocking' => false, // die Antwort interessiert nicht – der Takt der Zentrale fängt jeden Fehlschlag auf
				'headers'  => array(
					self::HEADER   => $key,
					'Content-Type' => 'application/json',
				),
				'body'     => wp_json_encode( array( 'site' => home_url() ) ),
			) );
		}
	}

	/* ===================================================================
	 *  REST-Adressen
	 * =================================================================== */

	public static function register_routes() {
		if ( self::ist_quelle() && self::all()['zentralen'] ) {
			register_rest_route( self::NS, '/sync/bestand', array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'route_bestand' ),
				'permission_callback' => array( __CLASS__, 'darf_abholen' ),
			) );
			register_rest_route( self::NS, '/sync/paket', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'route_paket' ),
				'permission_callback' => array( __CLASS__, 'darf_abholen' ),
			) );
		}

		if ( self::ist_zentrale() && self::all()['quellen'] ) {
			register_rest_route( self::NS, '/sync/klopfen', array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'route_klopfen' ),
				'permission_callback' => '__return_true', // der Schlüssel wird im Callback geprüft und benennt die Quelle
			) );
		}
	}

	/** Schlüssel aus dem Kopf der Anfrage. */
	private static function schluessel_aus( $request ) {
		$key = (string) $request->get_header( self::HEADER );
		if ( '' === $key ) {
			// Manche Server reichen eigene X-Header nicht durch. Der Parameter ist
			// der Notausgang – er landet allerdings in Server-Protokollen, deshalb
			// ist der Kopf der vorgesehene Weg.
			$key = (string) $request->get_param( 'schluessel' );
		}
		return trim( $key );
	}

	/**
	 * Darf diese Anfrage den Bestand sehen?
	 *
	 * hash_equals() statt „===": Der eingebaute Vergleich bricht beim ersten
	 * abweichenden Zeichen ab, und aus diesem Zeitunterschied lässt sich ein
	 * Schlüssel Zeichen für Zeichen erraten. Dazu ein Kontingent, damit ein
	 * Rateversuch nicht beliebig oft wiederholt werden kann.
	 */
	public static function darf_abholen( $request ) {
		// Großzügig, weil ein einziger Lauf schon dutzende Häppchen holt und ein
		// Wiederholungslauf kurz darauf nicht am Kontingent scheitern soll. Die
		// Bremse gegen das Raten des Schlüssels ist sie trotzdem: Ein Angreifer
		// ohne Schlüssel kommt an keiner der Anfragen vorbei.
		if ( ! bi_rate_hit( 'sync|' . bi_client_ip(), 400, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'bi_sync_limit', 'Zu viele Versuche.', array( 'status' => 429 ) );
		}
		$key = self::schluessel_aus( $request );
		if ( '' === $key ) {
			return new WP_Error( 'bi_sync_auth', 'Nicht erlaubt.', array( 'status' => 403 ) );
		}
		foreach ( self::all()['zentralen'] as $z ) {
			$soll = isset( $z['schluessel'] ) ? (string) $z['schluessel'] : '';
			if ( '' !== $soll && hash_equals( $soll, $key ) ) {
				return true;
			}
		}
		return new WP_Error( 'bi_sync_auth', 'Nicht erlaubt.', array( 'status' => 403 ) );
	}

	/**
	 * Bestandsliste: je Schlüssel die Änderungsmarke.
	 *
	 * Sie ist absichtlich winzig – ein paar Dutzend Bytes je Seminar. Die
	 * Zentrale kann sie deshalb bei JEDEM Takt vollständig holen und daraus zwei
	 * Dinge ableiten: was sich geändert hat und was fehlt. Das Fehlen ist der
	 * Grund für diese Adresse: Ein gelöschtes Seminar kann sich nicht selbst
	 * melden.
	 */
	public static function route_bestand( $request ) {
		global $wpdb;
		$s = self::all();

		// EINE Abfrage für den ganzen Bestand. Der Weg über get_posts() und
		// get_post_meta() je Eintrag wären bei tausend Seminaren zweitausend
		// Abfragen – für eine Liste, die bei jedem Takt neu geholt wird.
		// Der INNER JOIN auf die Seminarnummer erledigt zugleich die Auswahl:
		// Wer keine hat, hat keine Identität und steht deshalb nicht im Bestand.
		list( $fenster_sql, $fenster_werte ) = self::zeitfenster_sql( 'sd' );

		$pt_in  = implode( ',', array_fill( 0, count( bi_seminar_post_types() ), '%s' ) );
		$st_in  = implode( ',', array_fill( 0, count( $s['status'] ), '%s' ) );
		$werte  = array_merge( bi_seminar_post_types(), $s['status'], $fenster_werte );
		$zeilen = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT p.ID, p.post_type, p.post_modified_gmt,
			        nr.meta_value AS nummer,
			        ge.meta_value AS geaendert
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} nr ON nr.post_id = p.ID AND nr.meta_key = '_bi_seminarnummer'
			   LEFT  JOIN {$wpdb->postmeta} ge ON ge.post_id = p.ID AND ge.meta_key = '" . self::META_GEAENDERT . "'
			   LEFT  JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = '_bi_startdatum'
			  WHERE p.post_type IN ({$pt_in})
			    AND p.post_status IN ({$st_in})
			    AND nr.meta_value <> ''
			    AND {$fenster_sql}",
			$werte
		) );

		$eintraege = array();
		foreach ( (array) $zeilen as $z ) {
			$schluessel = $z->post_type . ':' . trim( (string) $z->nummer );
			// Der spätere von beiden Zeitpunkten gewinnt – siehe stand_von().
			$eintraege[ $schluessel ] = max(
				(int) $z->geaendert,
				(int) strtotime( $z->post_modified_gmt . ' UTC' )
			);
		}

		return rest_ensure_response( array(
			'format'    => 'bi-sync',
			'version'   => self::FORMAT_VERSION,
			'site'      => home_url(),
			'plugin'    => BI_VERSION,
			'erzeugt'   => time(),
			// DIE ZENTRALE MUSS WISSEN, WIE WEIT DIESE LISTE REICHT.
			// Ohne den Stichtag hielte ihr Aufräumschritt jedes vergangene
			// Seminar für gelöscht und blendete es aus – der Bestand wäre nicht
			// „unvollständig", sondern falsch.
			'stichtag'  => self::stichtag(),
			'anzahl'    => count( $eintraege ),
			'eintraege' => $eintraege,
		) );
	}

	/**
	 * Paket zu einer Liste von Schlüsseln.
	 *
	 * Aufbau wie das JSON-Paket der Datenpflege – dieselben Felder, dieselben
	 * Begriffe, dieselben Reihen. Das ist kein Zufall: Beide beantworten
	 * dieselbe Frage („wie sieht dieses Seminar hier aus?"), und zwei Antworten
	 * darauf würden mit der Zeit auseinanderlaufen. Ergänzt ist nur der Block
	 * `sync` je Eintrag: Schlüssel, Änderungsmarke, Bearbeiten-Adresse.
	 */
	public static function route_paket( $request ) {
		$s      = self::all();
		$wunsch = $request->get_param( 'schluessel' );
		if ( ! is_array( $wunsch ) ) {
			return new WP_Error( 'bi_sync_param', 'Keine Schlüssel angegeben.', array( 'status' => 400 ) );
		}
		$wunsch = array_slice( array_map( 'strval', $wunsch ), 0, 200 );

		$terms_used = array();
		$eintraege  = array();
		$reihen_ids = array();
		$mit_reihen = ! empty( $s['reihen'] );

		foreach ( $wunsch as $schluessel ) {
			$post_id = self::post_zu_schluessel( $schluessel, $s['status'] );
			if ( ! $post_id ) {
				continue;
			}
			$post   = get_post( $post_id );
			$pt     = $post->post_type;
			$fields = BI_CPT::meta_fields( $pt );

			$meta = array();
			foreach ( $fields as $key => $cfg ) {
				$meta[ $key ] = ( 'bool' === $cfg['type'] )
					? ( BI_CPT::meta_bool( $post_id, $key ) ? '1' : '0' )
					: (string) get_post_meta( $post_id, $key, true );
			}

			if ( $mit_reihen ) {
				$rid = (int) get_post_meta( $post_id, BI_Reihen::META_REIHE, true );
				if ( ! $rid ) {
					$roh = BI_Reihen::parse( (string) get_post_meta( $post_id, BI_Reihen::META_ROH, true ) );
					$rid = $roh ? BI_Reihen::reihe_id( $roh['reihe'], false ) : 0;
				}
				if ( $rid ) {
					$reihen_ids[ $rid ] = true;
				}
			}

			$eintraege[] = array(
				'post_type' => $pt,
				'title'     => $post->post_title,
				'content'   => $post->post_content,
				'status'    => $post->post_status,
				'meta'      => $meta,
				'terms'     => BI_Datenpflege::paket_terms( $post_id, BI_CPT::taxonomies( $pt ), $terms_used ),
				'bild'      => (string) get_the_post_thumbnail_url( $post_id, 'full' ),
				'sync'      => array(
					'schluessel'  => $schluessel,
					'stand'       => self::stand_von( $post_id ),
					// get_edit_post_link() gäbe hier nichts zurück: Es prüft die
					// Rechte der angemeldeten Person, und bei einem Abruf über die
					// Schnittstelle ist niemand angemeldet. Die Adresse ist ohnehin
					// kein Geheimnis – wer sie öffnet, muss sich dort anmelden.
					'edit_url'    => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
					'permalink'   => get_permalink( $post_id ),
					'anmeldungen' => self::anmeldungen_zaehlen( $post_id ),
				),
			);
		}

		$terms_export = array();
		foreach ( $terms_used as $slug => $liste ) {
			$terms_export[ $slug ] = array_values( $liste );
		}

		$reihen_export = array();
		foreach ( array_keys( $reihen_ids ) as $rid ) {
			$reihe = get_post( $rid );
			if ( ! $reihe || BI_Reihen::CPT !== $reihe->post_type ) {
				continue;
			}
			$rmeta = array();
			foreach ( BI_Reihen::meta_fields() as $key => $cfg ) {
				$rmeta[ $key ] = ( 'bool' === $cfg['type'] )
					? ( BI_CPT::meta_bool( $rid, $key ) ? '1' : '0' )
					: (string) get_post_meta( $rid, $key, true );
			}
			$reihen_export[] = array(
				'title'   => $reihe->post_title,
				'slug'    => $reihe->post_name,
				'content' => $reihe->post_content,
				'excerpt' => $reihe->post_excerpt,
				'status'  => $reihe->post_status,
				'meta'    => $rmeta,
				'bild'    => (string) get_the_post_thumbnail_url( $rid, 'full' ),
			);
		}

		return rest_ensure_response( array(
			'format'   => 'bi-sync-paket',
			'version'  => self::FORMAT_VERSION,
			'site'     => home_url(),
			'terms'    => $terms_export,
			'reihen'   => $reihen_export,
			'seminare' => $eintraege,
		) );
	}

	/** Die Quelle meldet, dass es Neues gibt. Der Schlüssel benennt sie. */
	public static function route_klopfen( $request ) {
		if ( ! bi_rate_hit( 'syncping|' . bi_client_ip(), 60, 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'bi_sync_limit', 'Zu viele Versuche.', array( 'status' => 429 ) );
		}
		$key = self::schluessel_aus( $request );
		if ( '' === $key ) {
			return new WP_Error( 'bi_sync_auth', 'Nicht erlaubt.', array( 'status' => 403 ) );
		}
		foreach ( self::all()['quellen'] as $q ) {
			$soll = isset( $q['schluessel'] ) ? (string) $q['schluessel'] : '';
			if ( '' !== $soll && hash_equals( $soll, $key ) ) {
				self::einreihen( (string) $q['slug'] );
				if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
					self::ensure_cron();
				}
				// Gleich anstoßen statt auf den Fünfminutentakt zu warten.
				wp_schedule_single_event( time() + 10, self::HOOK_TICK );
				return rest_ensure_response( array( 'ok' => true ) );
			}
		}
		return new WP_Error( 'bi_sync_auth', 'Nicht erlaubt.', array( 'status' => 403 ) );
	}

	/* ===================================================================
	 *  Schlüssel und Änderungsmarke
	 * =================================================================== */

	/* ===================================================================
	 *  Das Zeitfenster
	 * =================================================================== */

	/**
	 * Ab welchem Startdatum ein Seminar abgeglichen wird – '' = alle.
	 *
	 * Ein Seminar, dessen Start vorbei ist, erscheint in keiner Suche: Die
	 * Anzeige-Regel dieses Plugins lautet überall `_bi_startdatum >= heute`
	 * (BI_CPT::bookable_clauses, BI_Filter). Es über die Leitung zu schicken,
	 * kostet Zeit und ändert nichts an dem, was jemand zu sehen bekommt. Der
	 * erste Lauf einer Quelle schrumpft damit von „alles, was je stattfand" auf
	 * „was noch kommt".
	 *
	 * Der Stichtag ist NICHT heute, sondern heute minus Karenz. Zwei Gründe:
	 * Quelle und Zentrale können in verschiedenen Zeitzonen stehen (um 23:30 ist
	 * dort schon morgen), und ein gerade erst vergangenes Seminar soll seine
	 * letzte Änderung noch mitbekommen.
	 */
	public static function stichtag() {
		$s = self::all();
		if ( empty( $s['nur_kuenftig'] ) ) {
			return '';
		}
		$karenz = max( 0, (int) $s['karenz'] );
		return gmdate( 'Y-m-d', time() - $karenz * DAY_IN_SECONDS );
	}

	/**
	 * Liegt dieses Startdatum im Zeitfenster?
	 *
	 * EIN LEERES ODER KRUMMES DATUM GILT IMMER ALS DRIN. Das ist keine
	 * Nachlässigkeit, sondern die einzige sichere Richtung: Ein Vergleich gegen
	 * „01.08.2026" oder gegen leeren Text ergibt lexikalisch „kleiner als der
	 * Stichtag", das Seminar fiele aus dem Bestand – und der Aufräumschritt in
	 * der Zentrale hielte es daraufhin für gelöscht und blendete es aus. Ein
	 * Datenfehler darf keine Seminare verschwinden lassen.
	 */
	public static function im_zeitfenster( $datum ) {
		$stichtag = self::stichtag();
		if ( '' === $stichtag ) {
			return true;
		}
		$datum = trim( (string) $datum );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
			return true;
		}
		return $datum >= $stichtag;
	}

	/**
	 * Liegt dieses Startdatum im Fenster, nach dem die QUELLE gefragt wurde?
	 *
	 * Eigene Methode statt im_zeitfenster(), weil der Stichtag hier von außen
	 * kommt: Der Aufräumschritt muss mit demselben Maß messen, mit dem die
	 * Bestandsliste erzeugt wurde – sonst beurteilt er Einträge, nach denen nie
	 * gefragt war. Leerer Stichtag = die Quelle hat alles geliefert.
	 */
	private static function vor_stichtag_ok( $datum, $stichtag ) {
		if ( '' === (string) $stichtag ) {
			return true;
		}
		$datum = trim( (string) $datum );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) ) {
			return true; // krummes oder fehlendes Datum: immer beurteilen
		}
		return $datum >= $stichtag;
	}

	/**
	 * Die SQL-Bedingung dazu, als [ Text, Werte ].
	 *
	 * Spiegelt im_zeitfenster() – inklusive der Regel, dass alles Krumme drin
	 * bleibt. Der REGEXP-Zweig ist genau dafür da; ohne ihn verschwänden
	 * Seminare mit deutschem Datumsformat aus dem Bestand.
	 */
	private static function zeitfenster_sql( $alias ) {
		$stichtag = self::stichtag();
		if ( '' === $stichtag ) {
			return array( '1=1', array() );
		}
		return array(
			"( {$alias}.meta_value IS NULL
			   OR {$alias}.meta_value NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
			   OR {$alias}.meta_value >= %s )",
			array( $stichtag ),
		);
	}

	/** "<post_type>:<seminarnummer>" oder '' – ohne Nummer keine Identität. */
	public static function schluessel_fuer( $post_id ) {
		$nummer = trim( (string) get_post_meta( (int) $post_id, '_bi_seminarnummer', true ) );
		if ( '' === $nummer ) {
			return '';
		}
		return get_post_type( $post_id ) . ':' . $nummer;
	}

	/** Post-ID zu einem Schlüssel (in der Quelle). */
	private static function post_zu_schluessel( $schluessel, $status ) {
		$teile = explode( ':', (string) $schluessel, 2 );
		if ( 2 !== count( $teile ) ) {
			return 0;
		}
		list( $pt, $nummer ) = $teile;
		if ( ! in_array( $pt, bi_seminar_post_types(), true ) || '' === trim( $nummer ) ) {
			return 0;
		}
		$found = get_posts( array(
			'post_type'        => $pt,
			'post_status'      => $status,
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => '_bi_seminarnummer',
			'meta_value'       => trim( $nummer ),
			'suppress_filters' => true,
			'no_found_rows'    => true,
		) );
		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Änderungsmarke eines Seminars als GMT-Sekunde.
	 *
	 * Zwei Quellen, der spätere Wert gewinnt: die eigene Marke (fängt auch
	 * Meta-Änderungen ohne Beitrags-Speicherung ab) und post_modified_gmt (gilt
	 * für alles, was schon vor diesem Modul in der Datenbank stand – ohne diesen
	 * Rückfall hätte der Altbestand die Marke 0 und die Zentrale hielte ihn für
	 * uralt oder für unverändert, je nach Blickrichtung).
	 */
	public static function stand_von( $post_id ) {
		$eigen = (int) get_post_meta( (int) $post_id, self::META_GEAENDERT, true );
		$post  = get_post( $post_id );
		$wp    = $post ? (int) strtotime( $post->post_modified_gmt . ' UTC' ) : 0;
		return max( $eigen, $wp );
	}

	/** Anmeldungen, die in DIESER Installation zu dem Seminar liegen. */
	private static function anmeldungen_zaehlen( $post_id ) {
		global $wpdb;
		$tabelle = bi_table( 'anmeldungen' );
		// Die Tabelle gibt es immer; ein Fehlschlag wäre trotzdem kein Grund,
		// den ganzen Abgleich scheitern zu lassen.
		$n = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tabelle} WHERE seminar_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $post_id
		) );
		return (int) $n;
	}

	/* ===================================================================
	 *  Rolle ZENTRALE – der Lauf
	 * =================================================================== */

	private static function einreihen( $slug ) {
		$warte = get_option( self::OPT_WARTE, array() );
		if ( ! is_array( $warte ) ) {
			$warte = array();
		}
		if ( ! in_array( $slug, $warte, true ) ) {
			$warte[] = $slug;
			update_option( self::OPT_WARTE, $warte, false );
		}
	}

	private static function quelle_finden( $slug ) {
		foreach ( self::all()['quellen'] as $q ) {
			if ( isset( $q['slug'] ) && $q['slug'] === $slug ) {
				return $q;
			}
		}
		return null;
	}

	/**
	 * Taktgeber. Läuft alle fünf Minuten und tut eines von drei Dingen:
	 * einen angefangenen Lauf fortsetzen, einen wartenden beginnen, oder – wenn
	 * der eingestellte Takt abgelaufen ist – von sich aus nachfragen.
	 */
	public static function tick() {
		if ( ! self::ist_zentrale() ) {
			return;
		}

		$lauf = self::lauf_zustand();
		if ( $lauf ) {
			self::lauf_fortsetzen( $lauf );
			return;
		}

		// Wartende Quelle (Klopfen oder Knopf)?
		$warte = get_option( self::OPT_WARTE, array() );
		$warte = is_array( $warte ) ? $warte : array();
		$slug  = array_shift( $warte );
		if ( $slug ) {
			update_option( self::OPT_WARTE, $warte, false );
			self::lauf_beginnen( $slug );
			return;
		}

		// Nichts wartet – ist der reguläre Takt fällig?
		$takt  = max( 5, (int) self::get( 'takt' ) ) * MINUTE_IN_SECONDS;
		$stand = get_option( self::OPT_TAKT, array() );
		$stand = is_array( $stand ) ? $stand : array();

		foreach ( self::all()['quellen'] as $q ) {
			$slug = (string) ( $q['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			if ( time() - (int) ( $stand[ $slug ] ?? 0 ) >= $takt ) {
				self::lauf_beginnen( $slug );
				return; // eine je Takt – die nächste kommt in fünf Minuten dran
			}
		}
	}

	/** Einen Abgleich beginnen: Bestandsliste holen und vergleichen. */
	public static function lauf_beginnen( $slug, $budget = self::BUDGET ) {
		$q = self::quelle_finden( $slug );
		if ( ! $q ) {
			return 'abgebrochen';
		}

		$stand = get_option( self::OPT_TAKT, array() );
		$stand = is_array( $stand ) ? $stand : array();
		$stand[ $slug ] = time();
		update_option( self::OPT_TAKT, $stand, false );

		$antwort = self::abrufen( $q, 'sync/bestand', null );
		if ( is_wp_error( $antwort ) ) {
			self::protokoll_schreiben( $slug, array(
				'fehler' => 'Bestandsliste nicht erreichbar: ' . $antwort->get_error_message(),
			) );
			return 'abgebrochen';
		}

		$bestand  = isset( $antwort['eintraege'] ) && is_array( $antwort['eintraege'] ) ? $antwort['eintraege'] : array();
		$stichtag = isset( $antwort['stichtag'] ) ? (string) $antwort['stichtag'] : '';

		// EINE SICHERUNG, DIE SCHON EINMAL GEBRAUCHT WIRD, WENN MAN SIE VERMISST:
		// Ein leerer Bestand bei erreichbarer Quelle sähe aus wie „dort wurde
		// alles gelöscht" – und der Aufräumschritt würde daraufhin JEDES Seminar
		// dieser Quelle in der Zentrale ausblenden. Häufigere Ursache ist aber
		// eine halb eingerichtete Quelle (falscher Status, Wartungsmodus, leere
		// Datenbank nach einem Umzug). Deshalb: nichts tun und laut sein.
		if ( ! $bestand ) {
			self::protokoll_schreiben( $slug, array(
				'fehler' => sprintf(
					'Die Quelle meldet einen leeren Bestand%s. Es wurde nichts geändert – bitte in der Quelle prüfen, ob dort Seminare im freigegebenen Status stehen und ob das Zeitfenster nicht zu eng steht.',
					$stichtag ? ' (abgefragt ab Startdatum ' . $stichtag . ')' : ''
				),
			) );
			return 'abgebrochen';
		}

		// Was hat sich geändert? Verglichen wird gegen den Stand, der beim
		// letzten Abgleich am Eintrag vermerkt wurde.
		$hier  = self::eigener_bestand( $slug );
		$offen = array();
		foreach ( $bestand as $schluessel => $dort ) {
			$da = isset( $hier[ $schluessel ] ) ? $hier[ $schluessel ] : null;
			if ( null === $da || (int) $dort > (int) $da['stand'] ) {
				$offen[] = (string) $schluessel;
			}
		}

		$lauf = array(
			'quelle'   => $slug,
			'begonnen' => time(),
			'offen'    => $offen,
			'bestand'  => array_keys( $bestand ),
			'stichtag' => $stichtag,
			'gesamt'   => count( $offen ),
			'zahlen'   => array(
				'neu'           => 0,
				'aktualisiert'  => 0,
				'uebernommen'   => 0,
				'ausgeblendet'  => 0,
				'uebersprungen' => 0,
			),
			'hinweise' => array(),
		);
		update_option( self::OPT_LAUF, $lauf, false );

		return self::lauf_fortsetzen( $lauf, $budget );
	}

	/** Häppchen für Häppchen holen und schreiben, bis das Zeitbudget aufgebraucht ist. */
	private static function lauf_fortsetzen( $lauf, $budget = self::BUDGET ) {
		$slug = (string) $lauf['quelle'];
		$q    = self::quelle_finden( $slug );
		if ( ! $q ) {
			delete_option( self::OPT_LAUF );
			return 'abgebrochen';
		}

		$start   = time();
		$fehler  = 0;

		while ( ! empty( $lauf['offen'] ) ) {
			if ( time() - $start > $budget ) {
				// PAUSE – UND DARÜBER MUSS ETWAS ZU SEHEN SEIN.
				// Früher stand hier nur update_option() und ein return. Der Lauf
				// arbeitete dann zwar korrekt weiter, aber die Oberfläche zeigte
				// weder Bericht noch Fortschritt: Wer den Knopf gedrückt hatte,
				// sah eine Erfolgsmeldung und darunter nichts. Der Zustand wird
				// deshalb sichtbar abgelegt und in der Maske angezeigt.
				$lauf['pausiert'] = time();
				update_option( self::OPT_LAUF, $lauf, false );
				wp_schedule_single_event( time() + 30, self::HOOK_TICK );
				return 'unterwegs';
			}

			$haeppchen = array_splice( $lauf['offen'], 0, self::HAEPPCHEN );
			$paket     = self::abrufen( $q, 'sync/paket', array( 'schluessel' => $haeppchen ) );

			if ( is_wp_error( $paket ) ) {
				// Das Häppchen zurück in die Schlange – sonst gilt es als erledigt
				// und die Seminare darin würden bis zur nächsten Änderung in der
				// Quelle nie wieder geholt.
				$lauf['offen'] = array_merge( $haeppchen, $lauf['offen'] );
				$fehler++;
				$lauf['hinweise'][] = 'Ein Häppchen kam nicht an: ' . $paket->get_error_message();

				if ( $fehler >= self::FEHLVERSUCHE ) {
					delete_option( self::OPT_LAUF );
					self::protokoll_schreiben( $slug, array(
						'fehler'   => sprintf(
							'Nach %d Fehlversuchen abgebrochen: %s Die Bestandsliste kam an, die Seminardaten nicht – prüfe, ob POST-Anfragen an /wp-json/ auf der Quelle durchkommen (Sicherheits-Plugin, WAF, Server-Regel).',
							$fehler,
							$paket->get_error_message()
						),
						'zahlen'   => $lauf['zahlen'],
						'hinweise' => $lauf['hinweise'],
					) );
					return 'abgebrochen';
				}
				continue;
			}
			$fehler = 0;

			// Begriffe zuerst: sonst legte wp_set_object_terms() sie ohne Slug,
			// Beschreibung und E-Mail-Adresse an (siehe BI_Datenpflege).
			BI_Datenpflege::terms_anlegen( $paket['terms'] ?? array() );
			self::reihen_schreiben( $paket['reihen'] ?? array(), $slug, $lauf );

			foreach ( ( $paket['seminare'] ?? array() ) as $eintrag ) {
				$ergebnis = self::seminar_schreiben( $eintrag, $slug, $lauf );
				if ( isset( $lauf['zahlen'][ $ergebnis ] ) ) {
					$lauf['zahlen'][ $ergebnis ]++;
				}
			}
		}

		// Alles geholt – jetzt das Fehlende ausblenden.
		self::aufraeumen( $lauf );

		delete_option( self::OPT_LAUF );
		self::protokoll_schreiben( $slug, array(
			'zahlen'   => $lauf['zahlen'],
			'hinweise' => $lauf['hinweise'],
			'dauer'    => time() - (int) $lauf['begonnen'],
		) );

		if ( $lauf['zahlen']['neu'] || $lauf['zahlen']['aktualisiert'] || $lauf['zahlen']['ausgeblendet'] ) {
			if ( class_exists( 'BI_Cache' ) ) {
				BI_Cache::leeren( true );
			}
			if ( class_exists( 'BI_Ampel' ) ) {
				BI_Ampel::nach_import();
			}
		}
		return 'fertig';
	}

	/** Zustand eines gerade unterwegs befindlichen Laufs – oder null. */
	public static function lauf_zustand() {
		$lauf = get_option( self::OPT_LAUF, array() );
		return ( is_array( $lauf ) && ! empty( $lauf['quelle'] ) ) ? $lauf : null;
	}

	/**
	 * Ein Seminar aus dem Paket in die Zentrale schreiben.
	 *
	 * @return string neu|aktualisiert|uebernommen|uebersprungen
	 */
	private static function seminar_schreiben( $eintrag, $slug, &$lauf ) {
		if ( ! is_array( $eintrag ) || empty( $eintrag['sync']['schluessel'] ) ) {
			return 'uebersprungen';
		}
		$schluessel = (string) $eintrag['sync']['schluessel'];
		$pt         = ( isset( $eintrag['post_type'] ) && in_array( $eintrag['post_type'], bi_seminar_post_types(), true ) )
			? $eintrag['post_type'] : BI_CPT;

		$title = wp_strip_all_tags( (string) ( $eintrag['title'] ?? '' ) );
		if ( '' === trim( $title ) ) {
			$lauf['hinweise'][] = 'Ohne Titel übersprungen: ' . $schluessel;
			return 'uebersprungen';
		}

		$vorhanden = self::finden( $schluessel, $slug );
		if ( 'geloest' === $vorhanden ) {
			$lauf['hinweise'][] = 'Aus dem Abgleich gelöst, deshalb unangetastet: ' . $title . ' (' . $schluessel . ')';
			return 'uebersprungen';
		}
		if ( 'fremd' === $vorhanden ) {
			$lauf['hinweise'][] = sprintf(
				'Die Seminarnummer %s gibt es hier schon aus einer anderen Quelle. „%s" aus „%s" wurde deshalb NICHT geschrieben – zwei Nummernkreise sind kollidiert, und welcher Eintrag der richtige ist, kann nur ein Mensch entscheiden.',
				$schluessel, $title, $slug
			);
			return 'uebersprungen';
		}
		$ergebnis = $vorhanden['ergebnis'];
		$post_id  = $vorhanden['id'];

		$postarr = array(
			'post_type'    => $pt,
			'post_title'   => $title,
			'post_content' => wp_kses_post( (string) ( $eintrag['content'] ?? '' ) ),
			'post_status'  => in_array( $eintrag['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true )
				? $eintrag['status'] : 'publish',
		);
		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			$lauf['hinweise'][] = 'Konnte nicht geschrieben werden: ' . $title;
			return 'uebersprungen';
		}
		$post_id = (int) $post_id;

		// Meta-Felder. Das Paket ist ein Spiegel: Auch leere Werte werden
		// geschrieben, sonst behielte ein in der Quelle geleertes Feld hier
		// seinen alten Inhalt.
		$meta   = isset( $eintrag['meta'] ) && is_array( $eintrag['meta'] ) ? $eintrag['meta'] : array();
		$fields = BI_CPT::meta_fields( $pt );
		foreach ( $fields as $key => $cfg ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				continue;
			}
			update_post_meta( $post_id, $key, BI_Datenpflege::sanitize_meta( (string) $meta[ $key ], $cfg ) );
		}

		// Der Haken „Ausgebucht" kommt aus der Quelle und ist damit eine Aussage
		// über den Stand, keine Handkorrektur an der hiesigen Ampel – ihre Notiz
		// wird deshalb vergessen (wie beim CSV- und Paket-Import).
		if ( array_key_exists( '_bi_ausgebucht', $meta ) && class_exists( 'BI_Ampel' ) ) {
			BI_Ampel::hand_zuruecksetzen( $post_id );
		}

		// Begriffe: genau die aus dem Paket, auch wenn es keine sind.
		$terms = isset( $eintrag['terms'] ) && is_array( $eintrag['terms'] ) ? $eintrag['terms'] : array();
		foreach ( $terms as $tax => $namen ) {
			if ( ! taxonomy_exists( $tax ) || ! is_array( $namen ) ) {
				continue;
			}
			$namen = array_values( array_filter( array_map( 'strval', $namen ), 'strlen' ) );
			wp_set_object_terms( $post_id, $namen, $tax, false );
		}

		// Zuordnung zur Ausbildungsreihe aus „Teil | Reihe" auflösen. Der
		// save_post-Haken hilft hier nicht: Er feuert INNERHALB von
		// wp_insert_post(), also bevor die Meta-Felder oben geschrieben sind.
		BI_Reihen::zuordnen( $post_id );

		// Herkunftsstempel
		update_post_meta( $post_id, self::META_QUELLE, $slug );
		update_post_meta( $post_id, self::META_SCHLUESSEL, $schluessel );
		update_post_meta( $post_id, self::META_STAND, (int) ( $eintrag['sync']['stand'] ?? time() ) );
		update_post_meta( $post_id, self::META_EDIT, esc_url_raw( (string) ( $eintrag['sync']['edit_url'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_ANMELDUNGEN, (int) ( $eintrag['sync']['anmeldungen'] ?? 0 ) );
		delete_post_meta( $post_id, self::META_FEHLT );

		// Beitragsbild nur, wo noch keines steht – ein zweiter Lauf soll die
		// Mediathek nicht mit Kopien füllen.
		if ( self::get( 'bilder' ) && ! empty( $eintrag['bild'] ) && ! has_post_thumbnail( $post_id ) ) {
			BI_Datenpflege::bild_holen( (string) $eintrag['bild'], $post_id );
		}

		return $ergebnis;
	}

	/**
	 * Den passenden Eintrag in der Zentrale suchen.
	 *
	 * Zwei Blicke, in dieser Reihenfolge:
	 *   1. Stempel (Quelle + Schlüssel) – der Normalfall nach dem ersten Lauf.
	 *   2. Blanke Seminarnummer – der Erstlauf. Was hier gefunden wird, stammt
	 *      aus dem CSV-Import und wird ÜBERNOMMEN statt verdoppelt.
	 *
	 * @return array{id:int,ergebnis:string}|string 'geloest', wenn der Eintrag
	 *         von Hand aus dem Abgleich genommen wurde.
	 */
	private static function finden( $schluessel, $slug ) {
		$teile = explode( ':', $schluessel, 2 );
		$pt    = $teile[0] ?? BI_CPT;
		$nummer = $teile[1] ?? '';

		$treffer = get_posts( array(
			'post_type'   => $pt,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_query'  => array(
				'relation' => 'AND',
				array( 'key' => self::META_QUELLE, 'value' => $slug ),
				array( 'key' => self::META_SCHLUESSEL, 'value' => $schluessel ),
			),
		) );
		if ( $treffer ) {
			$id = (int) $treffer[0];
			if ( get_post_meta( $id, self::META_GELOEST, true ) ) {
				return 'geloest';
			}
			return array( 'id' => $id, 'ergebnis' => 'aktualisiert' );
		}

		if ( '' !== trim( $nummer ) ) {
			$alt = get_posts( array(
				'post_type'   => $pt,
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_bi_seminarnummer',
				'meta_value'  => trim( $nummer ),
			) );
			if ( $alt ) {
				$id = (int) $alt[0];
				if ( get_post_meta( $id, self::META_GELOEST, true ) ) {
					return 'geloest';
				}
				// Gehört schon einer ANDEREN Quelle? Dann Finger weg und melden –
				// zwei Nummernkreise sind kollidiert, und das muss ein Mensch
				// entscheiden, nicht ein Cron um drei Uhr nachts.
				$fremd = (string) get_post_meta( $id, self::META_QUELLE, true );
				if ( '' !== $fremd && $fremd !== $slug ) {
					return 'fremd';
				}
				return array( 'id' => $id, 'ergebnis' => 'uebernommen' );
			}
		}

		return array( 'id' => 0, 'ergebnis' => 'neu' );
	}

	/**
	 * Ausbildungsreihen aus dem Paket.
	 *
	 * Wiedererkannt am Namen – derselbe Schlüssel, mit dem „Teil | Reihe" am
	 * Seminar seine Reihe findet. Anders als beim Paket-Import wird eine
	 * vorhandene Reihe hier NICHT bedingungslos überschrieben: In der Zentrale
	 * laufen mehrere Quellen zusammen, und eine Reihe gleichen Namens aus
	 * Sprockhövel und Berlin würde sich sonst bei jedem Lauf gegenseitig
	 * überschreiben – ein Datenstand, der je nach Reihenfolge der Läufe anders
	 * aussieht. Überschrieben wird deshalb nur, was derselben Quelle gehört.
	 */
	private static function reihen_schreiben( $reihen, $slug, &$lauf ) {
		if ( ! is_array( $reihen ) || ! $reihen ) {
			return;
		}
		foreach ( $reihen as $r ) {
			$titel = trim( wp_strip_all_tags( (string) ( $r['title'] ?? '' ) ) );
			if ( '' === $titel ) {
				continue;
			}
			$rid = BI_Reihen::reihe_id( $titel, false );

			if ( $rid ) {
				$eigner = (string) get_post_meta( $rid, self::META_QUELLE, true );
				if ( '' !== $eigner && $eigner !== $slug ) {
					$lauf['hinweise'][] = sprintf(
						'Ausbildungsreihe „%s" gibt es hier schon aus der Quelle „%s" – aus „%s" wurde sie deshalb nicht übernommen. Gleiche Reihennamen in zwei Quellen bitte in der Zentrale zusammenführen.',
						$titel, $eigner, $slug
					);
					continue;
				}
				if ( '' === $eigner && get_post_meta( $rid, self::META_GELOEST, true ) ) {
					continue;
				}
			}

			$postarr = array(
				'post_type'    => BI_Reihen::CPT,
				'post_title'   => $titel,
				'post_content' => wp_kses_post( (string) ( $r['content'] ?? '' ) ),
				'post_excerpt' => sanitize_textarea_field( (string) ( $r['excerpt'] ?? '' ) ),
				'post_status'  => in_array( $r['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true )
					? $r['status'] : 'publish',
			);
			if ( $rid ) {
				$postarr['ID'] = $rid;
				// Der Slug bleibt: Er steckt in Links, die anderswo gesetzt sind.
			} elseif ( ! empty( $r['slug'] ) ) {
				$postarr['post_name'] = sanitize_title( (string) $r['slug'] );
			}

			$neu = $rid ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
			if ( ! $neu || is_wp_error( $neu ) ) {
				continue;
			}
			$neu = (int) $neu;

			foreach ( BI_Reihen::meta_fields() as $key => $cfg ) {
				if ( ! isset( $r['meta'][ $key ] ) ) {
					continue;
				}
				update_post_meta( $neu, $key, BI_Datenpflege::sanitize_meta( (string) $r['meta'][ $key ], $cfg ) );
			}
			update_post_meta( $neu, self::META_QUELLE, $slug );

			if ( self::get( 'bilder' ) && ! empty( $r['bild'] ) && ! has_post_thumbnail( $neu ) ) {
				BI_Datenpflege::bild_holen( (string) $r['bild'], $neu );
			}
		}
	}

	/**
	 * Alle Einträge der Zentrale, die aus dieser Quelle stammen.
	 *
	 * Eine Abfrage, aus demselben Grund wie in route_bestand(): Diese Liste wird
	 * zweimal je Lauf gebraucht – einmal, um zu sehen, was zu holen ist, und
	 * einmal, um zu sehen, was fehlt.
	 *
	 * Absichtlich OHNE Einschränkung auf den Post-Status: Ein Eintrag, der hier
	 * im Papierkorb liegt, gehört trotzdem noch zu dieser Quelle. Bliebe er
	 * außen vor, hielte der nächste Lauf ihn für unbekannt und legte ihn ein
	 * zweites Mal an.
	 */
	private static function eigener_bestand( $slug ) {
		global $wpdb;

		$pt_in = implode( ',', array_fill( 0, count( bi_seminar_post_types() ), '%s' ) );
		$werte = array_merge( bi_seminar_post_types(), array( $slug ) );

		$zeilen = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT p.ID,
			        q.meta_value  AS quelle,
			        sk.meta_value AS schluessel,
			        st.meta_value AS stand,
			        sd.meta_value AS startdatum
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} q  ON q.post_id  = p.ID AND q.meta_key  = '" . self::META_QUELLE . "'
			   INNER JOIN {$wpdb->postmeta} sk ON sk.post_id = p.ID AND sk.meta_key = '" . self::META_SCHLUESSEL . "'
			   LEFT  JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = '" . self::META_STAND . "'
			   LEFT  JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = '_bi_startdatum'
			  WHERE p.post_type IN ({$pt_in})
			    AND q.meta_value = %s",
			$werte
		) );

		$out = array();
		foreach ( (array) $zeilen as $z ) {
			$schluessel = (string) $z->schluessel;
			if ( '' === $schluessel ) {
				continue;
			}
			$out[ $schluessel ] = array(
				'id'         => (int) $z->ID,
				'stand'      => (int) $z->stand,
				'startdatum' => (string) $z->startdatum,
			);
		}
		return $out;
	}

	/**
	 * Was die Quelle nicht mehr führt, wird hier ausgeblendet – nicht gelöscht.
	 *
	 * Gelöscht wäre aufgeräumter und wäre falsch: An dem Eintrag hängen
	 * Anmeldungen, auf ihn zeigen Links aus Newslettern, und ein Versehen in der
	 * Quelle (Papierkorb statt Entwurf) wäre hier nicht mehr zurückzuholen.
	 * Der Eintrag verliert nur seinen Platz in Suche und Website – genau das,
	 * was ein Besucher merken soll.
	 */
	private static function aufraeumen( &$lauf ) {
		$slug     = (string) $lauf['quelle'];
		$bestand  = array_flip( (array) $lauf['bestand'] );
		$stichtag = (string) ( $lauf['stichtag'] ?? '' );
		$hier     = self::eigener_bestand( $slug );

		foreach ( $hier as $schluessel => $eintrag ) {
			if ( isset( $bestand[ $schluessel ] ) ) {
				continue;
			}

			// DIE WICHTIGSTE ZEILE DIESER METHODE.
			//
			// Wenn die Quelle nur noch abgleicht, was bevorsteht, dann fehlt
			// alles Vergangene in ihrer Bestandsliste – nicht weil es gelöscht
			// wurde, sondern weil danach gar nicht gefragt war. Ohne diese
			// Prüfung blendete der erste Lauf mit eingeschaltetem Zeitfenster
			// den gesamten Altbestand der Zentrale aus. Also: Was schon vor dem
			// Stichtag der Quelle liegt, wird hier nicht beurteilt.
			//
			// Maßgeblich ist der Stichtag, den die QUELLE mitgeschickt hat, nicht
			// der eigene: Beide Installationen können verschieden eingestellt
			// sein, und gültig ist, wonach tatsächlich gefragt wurde.
			if ( ! self::vor_stichtag_ok( $eintrag['startdatum'], $stichtag ) ) {
				continue;
			}

			if ( get_post_meta( $eintrag['id'], self::META_GELOEST, true ) ) {
				continue;
			}
			// Schon ausgeblendet? Dann nicht noch einmal zählen – sonst meldete
			// jeder Lauf dieselbe Zahl und niemand wüsste, ob etwas Neues fehlt.
			if ( get_post_meta( $eintrag['id'], self::META_FEHLT, true ) ) {
				continue;
			}
			update_post_meta( $eintrag['id'], '_bi_anzeigen', '0' );
			update_post_meta( $eintrag['id'], self::META_FEHLT, current_time( 'Y-m-d H:i:s' ) );
			$lauf['zahlen']['ausgeblendet']++;
			$lauf['hinweise'][] = sprintf(
				'In der Quelle nicht mehr vorhanden, deshalb ausgeblendet: %s (%s)',
				get_the_title( $eintrag['id'] ),
				$schluessel
			);
		}
	}

	/* ===================================================================
	 *  HTTP zur Quelle
	 * =================================================================== */

	/**
	 * Eine Adresse der Quelle abrufen. $body === null bedeutet GET.
	 *
	 * @return array|WP_Error
	 */
	private static function abrufen( $q, $pfad, $body ) {
		$url = trailingslashit( (string) ( $q['url'] ?? '' ) );
		$key = (string) ( $q['schluessel'] ?? '' );
		if ( ! $url || '' === $key ) {
			return new WP_Error( 'bi_sync_config', 'Adresse oder Schlüssel fehlt.' );
		}

		$args = array(
			// Kürzer als das Zeitbudget eines Laufs: Eine einzige hängende Anfrage
			// darf nicht das ganze Häppchen-Budget aufbrauchen.
			'timeout'     => 15,
			'redirection' => 3,
			'headers'     => array(
				self::HEADER => $key,
				'Accept'     => 'application/json',
			),
		);
		if ( null === $body ) {
			$antwort = wp_remote_get( $url . 'wp-json/' . self::NS . '/' . $pfad, $args );
		} else {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			$antwort                         = wp_remote_post( $url . 'wp-json/' . self::NS . '/' . $pfad, $args );
		}

		if ( is_wp_error( $antwort ) ) {
			return $antwort;
		}
		$code = (int) wp_remote_retrieve_response_code( $antwort );
		if ( 200 !== $code ) {
			$hinweis = ( 403 === $code )
				? 'Der Schlüssel wurde nicht anerkannt. Er muss in Quelle und Zentrale zeichengleich stehen.'
				: ( 404 === $code ? 'Adresse nicht gefunden. Steht die Quelle auf der Rolle „Quelle" und ist die Zentrale dort eingetragen?' : '' );
			return new WP_Error( 'bi_sync_http', trim( 'HTTP ' . $code . '. ' . $hinweis ) );
		}

		$daten = json_decode( (string) wp_remote_retrieve_body( $antwort ), true );
		if ( ! is_array( $daten ) ) {
			return new WP_Error( 'bi_sync_json', 'Die Antwort war kein lesbares JSON.' );
		}
		return $daten;
	}

	/* ===================================================================
	 *  Protokoll
	 * =================================================================== */

	public static function protokoll_schreiben( $slug, $daten ) {
		$p = get_option( self::OPT_PROTOKOLL, array() );
		if ( ! is_array( $p ) ) {
			$p = array();
		}
		array_unshift( $p, array_merge( array(
			'zeit'   => current_time( 'Y-m-d H:i:s' ),
			'quelle' => $slug,
		), $daten ) );
		update_option( self::OPT_PROTOKOLL, array_slice( $p, 0, 20 ), false );
	}

	public static function protokoll() {
		$p = get_option( self::OPT_PROTOKOLL, array() );
		return is_array( $p ) ? $p : array();
	}

	/* ===================================================================
	 *  Schreibschutz in der Zentrale
	 * =================================================================== */

	/**
	 * Abgeglichene Seminare sind nur lesbar.
	 *
	 * Über map_meta_cap und nicht über ein ausgegrautes Feld: Die Sperre gilt
	 * damit überall auf einmal – Bearbeiten-Maske, Schnellbearbeitung,
	 * Massenbearbeitung, Papierkorb und die REST-Schnittstelle. Eine Sperre, die
	 * nur die sichtbare Maske kennt, ist keine.
	 */
	public static function schreibschutz( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post_id = (int) $args[0];
		$typen   = array_merge( bi_seminar_post_types(), array( BI_Reihen::CPT ) );
		if ( ! in_array( get_post_type( $post_id ), $typen, true ) ) {
			return $caps;
		}
		if ( ! get_post_meta( $post_id, self::META_QUELLE, true ) ) {
			return $caps;
		}
		if ( get_post_meta( $post_id, self::META_GELOEST, true ) ) {
			return $caps;
		}
		/**
		 * Notausgang für Sonderfälle – etwa eine Migration, die von Hand
		 * nachfassen muss. Bewusst ein Filter und kein Häkchen: Wer ihn setzt,
		 * weiß, dass der nächste Abgleich die Änderung wieder überschreibt.
		 */
		if ( ! apply_filters( 'bi_sync_schreibschutz', true, $post_id ) ) {
			return $caps;
		}
		return array( 'do_not_allow' );
	}

	/** Spalte „Abgleich" in der Seminarliste der Zentrale. */
	public static function spalte( $cols ) {
		$neu = array();
		foreach ( $cols as $key => $label ) {
			$neu[ $key ] = $label;
			if ( 'title' === $key ) {
				$neu['bi_sync'] = 'Abgleich';
			}
		}
		return isset( $neu['bi_sync'] ) ? $neu : array_merge( $cols, array( 'bi_sync' => 'Abgleich' ) );
	}

	public static function spalte_inhalt( $col, $post_id ) {
		if ( 'bi_sync' !== $col ) {
			return;
		}
		$slug = (string) get_post_meta( $post_id, self::META_QUELLE, true );
		if ( ! $slug ) {
			echo '<span style="color:#787c82">—</span>';
			return;
		}
		$q    = self::quelle_finden( $slug );
		$name = $q ? (string) $q['name'] : $slug;

		if ( get_post_meta( $post_id, self::META_GELOEST, true ) ) {
			echo '<span title="Wird nicht mehr abgeglichen">🔓 ' . esc_html( $name ) . ' (gelöst)</span>';
			return;
		}

		$fehlt = (string) get_post_meta( $post_id, self::META_FEHLT, true );
		if ( $fehlt ) {
			echo '<span style="color:#b32d2e" title="Seit ' . esc_attr( $fehlt ) . ' nicht mehr im Bestand der Quelle">⚠ fehlt in ' . esc_html( $name ) . '</span>';
			return;
		}

		$edit = (string) get_post_meta( $post_id, self::META_EDIT, true );
		echo '🔒 ';
		if ( $edit ) {
			echo '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html( $name ) . '</a>';
		} else {
			echo esc_html( $name );
		}
		$anm = (int) get_post_meta( $post_id, self::META_ANMELDUNGEN, true );
		if ( $anm ) {
			echo '<br><span style="color:#787c82;font-size:11px">' . esc_html( $anm ) . ' Anmeldung' . ( 1 === $anm ? '' : 'en' ) . ' dort</span>';
		}
	}

	/** Zeilenaktion „Aus dem Abgleich lösen" bzw. „Wieder abgleichen". */
	public static function zeilen_aktion( $actions, $post ) {
		$typen = array_merge( bi_seminar_post_types(), array( BI_Reihen::CPT ) );
		if ( ! in_array( $post->post_type, $typen, true ) || ! current_user_can( BI_CAP ) ) {
			return $actions;
		}
		if ( ! get_post_meta( $post->ID, self::META_QUELLE, true ) ) {
			return $actions;
		}
		$geloest = (bool) get_post_meta( $post->ID, self::META_GELOEST, true );
		$url     = wp_nonce_url(
			admin_url( 'admin-post.php?action=bi_sync_loesen&post=' . (int) $post->ID . '&modus=' . ( $geloest ? 'zurueck' : 'loesen' ) ),
			'bi_sync_loesen_' . $post->ID
		);
		$actions['bi_sync'] = '<a href="' . esc_url( $url ) . '">'
			. ( $geloest ? 'wieder abgleichen' : 'aus dem Abgleich lösen' )
			. '</a>';
		return $actions;
	}

	/** Hinweis über der Bearbeiten-Maske, falls doch jemand hineinkommt. */
	public static function hinweis_im_editor() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return;
		}
		$slug = (string) get_post_meta( $post_id, self::META_QUELLE, true );
		if ( ! $slug || get_post_meta( $post_id, self::META_GELOEST, true ) ) {
			return;
		}
		$q    = self::quelle_finden( $slug );
		$name = $q ? (string) $q['name'] : $slug;
		$edit = (string) get_post_meta( $post_id, self::META_EDIT, true );

		echo '<div class="notice notice-warning"><p><strong>Dieses Seminar wird abgeglichen.</strong> Gepflegt wird es in <em>'
			. esc_html( $name ) . '</em>; Änderungen hier würden beim nächsten Abgleich überschrieben.';
		if ( $edit ) {
			echo ' <a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">Dort bearbeiten →</a>';
		}
		echo '</p></div>';
	}

	/* ===================================================================
	 *  Einstellungsseite
	 * =================================================================== */

	private static function redirect( $msg = '' ) {
		$args = array( 'page' => 'bi-einstellungen', 'tab' => 'abgleich' );
		if ( '' !== $msg ) {
			$args['bi_msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_speichern() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_sync_speichern' );

		$s          = self::all();
		$alte_rolle = $s['rolle'];

		$rolle = sanitize_key( bi_post( 'rolle', 'aus' ) );
		$s['rolle']  = in_array( $rolle, array( 'aus', 'quelle', 'zentrale' ), true ) ? $rolle : 'aus';
		$s['takt']   = max( 5, min( 1440, (int) bi_post( 'takt', 60 ) ) );
		$s['reihen'] = ! empty( $_POST['reihen'] ) ? 1 : 0;
		$s['bilder'] = ! empty( $_POST['bilder'] ) ? 1 : 0;
		$s['schutz'] = ! empty( $_POST['schutz'] ) ? 1 : 0;

		$status = isset( $_POST['status'] ) && is_array( $_POST['status'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['status'] ) ) : array();
		$s['status'] = array_values( array_intersect( $status, array( 'publish', 'draft', 'private' ) ) ) ?: array( 'publish' );

		$s['nur_kuenftig'] = ! empty( $_POST['nur_kuenftig'] ) ? 1 : 0;
		$s['karenz']       = max( 0, min( 3650, (int) bi_post( 'karenz', 30 ) ) );

		// Rolle QUELLE: Zentralen
		$zentralen = array();
		$z_urls    = isset( $_POST['z_url'] ) && is_array( $_POST['z_url'] ) ? wp_unslash( $_POST['z_url'] ) : array();
		$z_keys    = isset( $_POST['z_key'] ) && is_array( $_POST['z_key'] ) ? wp_unslash( $_POST['z_key'] ) : array();
		foreach ( $z_urls as $i => $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			$key = trim( (string) ( $z_keys[ $i ] ?? '' ) );
			if ( '' === $url || '' === $key ) {
				continue;
			}
			$zentralen[] = array( 'url' => $url, 'schluessel' => $key );
		}
		$s['zentralen'] = $zentralen;

		// Rolle ZENTRALE: Quellen
		$quellen  = array();
		$vergeben = array();
		$q_names = isset( $_POST['q_name'] ) && is_array( $_POST['q_name'] ) ? wp_unslash( $_POST['q_name'] ) : array();
		$q_urls  = isset( $_POST['q_url'] ) && is_array( $_POST['q_url'] ) ? wp_unslash( $_POST['q_url'] ) : array();
		$q_keys  = isset( $_POST['q_key'] ) && is_array( $_POST['q_key'] ) ? wp_unslash( $_POST['q_key'] ) : array();
		$q_slugs = isset( $_POST['q_slug'] ) && is_array( $_POST['q_slug'] ) ? wp_unslash( $_POST['q_slug'] ) : array();
		foreach ( $q_urls as $i => $url ) {
			$url  = esc_url_raw( trim( (string) $url ) );
			$key  = trim( (string) ( $q_keys[ $i ] ?? '' ) );
			$name = sanitize_text_field( (string) ( $q_names[ $i ] ?? '' ) );
			if ( '' === $url || '' === $key ) {
				continue;
			}
			// DER SLUG IST DER SCHLÜSSEL ZUM BESTAND und darf sich niemals ändern:
			// An ihm hängt jeder Herkunftsstempel in der Datenbank. Ein neuer Slug
			// hieße, dass die Zentrale ihren eigenen Bestand nicht mehr wiedererkennt
			// und alles ein zweites Mal anlegt. Deshalb wird ein einmal vergebener
			// Slug mitgeschleppt und nicht aus dem (änderbaren) Namen neu gebildet.
			$slug = sanitize_key( (string) ( $q_slugs[ $i ] ?? '' ) );
			if ( '' === $slug ) {
				$slug = sanitize_key( $name ) ?: 'quelle';
			}
			// Zwei Quellen mit derselben Kennung wären zwei Bestände, die sich für
			// einen halten – jeder Lauf blendete aus, was der andere gerade schrieb.
			$basis = $slug;
			$n     = 2;
			while ( isset( $vergeben[ $slug ] ) ) {
				$slug = $basis . $n;
				$n++;
			}
			$vergeben[ $slug ] = true;
			$quellen[] = array(
				'slug'       => $slug,
				'name'       => $name ?: $slug,
				'url'        => $url,
				'schluessel' => $key,
			);
		}
		$s['quellen'] = $quellen;

		self::save( $s );

		if ( 'zentrale' === $s['rolle'] ) {
			self::ensure_cron();
		} elseif ( 'zentrale' === $alte_rolle ) {
			self::clear_cron();
		}

		self::redirect( 'Einstellungen des Abgleichs gespeichert.' );
	}

	/**
	 * Knopf „Jetzt abgleichen" – und „Weiter", wenn ein Lauf unterwegs ist.
	 *
	 * FRÜHER STAND HIER delete_option( OPT_LAUF ) und danach lauf_beginnen().
	 * Das war der schlimmste Fehler dieser Datei: Ein Lauf, der sein Zeitbudget
	 * aufgebraucht hatte und auf den nächsten Cron-Aufruf wartete, wurde beim
	 * nächsten Klick weggeworfen und fing von vorn an. Wer wartete und deshalb
	 * noch einmal drückte, sorgte genau dafür, dass der Abgleich nie fertig
	 * wurde. Ein unterwegs befindlicher Lauf wird jetzt FORTGESETZT.
	 */
	public static function handle_jetzt() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_sync_jetzt' );

		$slug = sanitize_key( bi_post( 'quelle' ) );
		if ( ! $slug || ! self::quelle_finden( $slug ) ) {
			self::redirect( 'Diese Quelle ist nicht eingetragen.' );
		}

		@set_time_limit( 0 );

		$lauf = self::lauf_zustand();
		if ( $lauf && $lauf['quelle'] === $slug ) {
			$status = self::lauf_fortsetzen( $lauf, self::BUDGET_HAND );
		} else {
			// Ein Lauf einer ANDEREN Quelle wird nicht unterbrochen – er stünde
			// sonst halb erledigt da, und niemand wüsste es.
			if ( $lauf ) {
				self::redirect( sprintf(
					'Es läuft gerade ein Abgleich mit „%s". Bitte erst den zu Ende bringen.',
					$lauf['quelle']
				) );
			}
			$status = self::lauf_beginnen( $slug, self::BUDGET_HAND );
		}

		$p     = self::protokoll();
		$letzt = $p ? $p[0] : array();

		if ( 'abgebrochen' === $status ) {
			self::redirect( 'Abgleich abgebrochen: ' . ( $letzt['fehler'] ?? 'Grund unbekannt – siehe Bericht.' ) );
		}
		if ( 'unterwegs' === $status ) {
			$offen = self::lauf_zustand();
			self::redirect( sprintf(
				'Der Abgleich mit „%s" ist noch unterwegs: %d von %d Seminaren geholt. Auf „Weiter" drücken oder warten – er arbeitet auch von selbst weiter.',
				$slug,
				(int) ( $offen['gesamt'] ?? 0 ) - count( (array) ( $offen['offen'] ?? array() ) ),
				(int) ( $offen['gesamt'] ?? 0 )
			) );
		}
		self::redirect( 'Abgleich mit „' . $slug . '" fertig – Bericht unten.' );
	}

	/**
	 * Einen steckengebliebenen Lauf verwerfen.
	 *
	 * Verliert nichts Bleibendes: Was schon geschrieben wurde, ist geschrieben
	 * und trägt seine Änderungsmarke. Der nächste Lauf holt genau den Rest.
	 */
	public static function handle_abbrechen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_sync_abbrechen' );

		$lauf = self::lauf_zustand();
		if ( $lauf ) {
			self::protokoll_schreiben( (string) $lauf['quelle'], array(
				'fehler'   => sprintf(
					'Von Hand abgebrochen – %d Seminare waren noch offen. Bereits Geschriebenes bleibt; der nächste Lauf holt den Rest.',
					count( (array) ( $lauf['offen'] ?? array() ) )
				),
				'zahlen'   => $lauf['zahlen'] ?? array(),
				'hinweise' => $lauf['hinweise'] ?? array(),
			) );
		}
		delete_option( self::OPT_LAUF );
		self::redirect( 'Der Lauf wurde abgebrochen.' );
	}

	/** Einen Eintrag aus dem Abgleich lösen oder wieder aufnehmen. */
	public static function handle_loesen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		check_admin_referer( 'bi_sync_loesen_' . $post_id );

		$modus = sanitize_key( bi_get( 'modus', 'loesen' ) );
		if ( 'zurueck' === $modus ) {
			delete_post_meta( $post_id, self::META_GELOEST );
			// Den Stand zurücksetzen, damit der nächste Lauf den Eintrag frisch holt.
			delete_post_meta( $post_id, self::META_STAND );
			$msg = 'Der Eintrag wird wieder abgeglichen und beim nächsten Lauf aus der Quelle überschrieben.';
		} else {
			update_post_meta( $post_id, self::META_GELOEST, '1' );
			$msg = 'Der Eintrag ist aus dem Abgleich gelöst: frei bearbeitbar, wird aber nicht mehr aktualisiert.';
		}

		$zurueck = wp_get_referer() ?: admin_url( 'edit.php?post_type=' . get_post_type( $post_id ) );
		wp_safe_redirect( add_query_arg( 'bi_msg', rawurlencode( $msg ), $zurueck ) );
		exit;
	}

	/** Ein Schlüssel, der sich nicht raten lässt. */
	public static function schluessel_erzeugen() {
		return wp_generate_password( 48, false, false );
	}

	/**
	 * Ein Schlüsselfeld mit Kopier- und Erzeugen-Knopf.
	 *
	 * Der Vorschlag steht als WERT im Feld, nicht als Platzhalter. Ein
	 * Platzhalter ist grau, lässt sich nicht markieren und nicht kopieren – und
	 * genau das Kopieren ist hier die ganze Arbeit: Derselbe Schlüssel muss auf
	 * der anderen Website landen, zeichengleich.
	 *
	 * Eine leere Zeile bekommt deshalb einen frisch erzeugten Schlüssel. Sie
	 * wird beim Speichern ohnehin nur berücksichtigt, wenn auch eine Adresse
	 * daneben steht – ein ungenutzter Vorschlag verschwindet also folgenlos.
	 */
	private static function schluessel_feld( $name, $wert ) {
		$wert = (string) $wert;
		$neu  = ( '' === $wert );
		if ( $neu ) {
			$wert = self::schluessel_erzeugen();
		}
		?>
		<div class="bi-sync-key">
			<input type="text" class="large-text code" name="<?php echo esc_attr( $name ); ?>"
			       value="<?php echo esc_attr( $wert ); ?>" spellcheck="false" autocomplete="off"
			       <?php echo $neu ? '' : 'data-gespeichert="1"'; ?>>
			<button type="button" class="button bi-sync-copy">Kopieren</button>
			<button type="button" class="button bi-sync-new" title="Ersetzt den Schlüssel. Er muss danach auch auf der Gegenstelle neu eingetragen werden.">Neu</button>
			<?php if ( $neu ) : ?>
				<span class="description bi-sync-frisch">frisch erzeugt – kopieren und auf der Gegenstelle eintragen</span>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_section() {
		$s      = self::all();
		$notice = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<h2>Abgleich mit anderen Installationen</h2>

		<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

		<p>Das Bildungsprogramm läuft an mehreren Orten. Damit eine Änderung in
		   Sprockhövel oder Berlin nicht dort steckenbleibt, holt die Zentrale die
		   Seminare regelmäßig ab. Jede Installation hat dabei <strong>eine</strong> Rolle.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_sync_speichern">
			<?php wp_nonce_field( 'bi_sync_speichern' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Rolle dieser Website</th>
					<td>
						<label><input type="radio" name="rolle" value="aus" <?php checked( $s['rolle'], 'aus' ); ?>> <strong>Aus</strong> – kein Abgleich</label><br>
						<label><input type="radio" name="rolle" value="quelle" <?php checked( $s['rolle'], 'quelle' ); ?>> <strong>Quelle</strong> – hier werden Seminare gepflegt (Sprockhövel, Berlin)</label><br>
						<label><input type="radio" name="rolle" value="zentrale" <?php checked( $s['rolle'], 'zentrale' ); ?>> <strong>Zentrale</strong> – hier laufen alle Bestände zusammen (bildung.igmetall.de)</label>
						<p class="description">Eine Website ist entweder Quelle oder Zentrale, nie beides.</p>
					</td>
				</tr>
			</table>

			<hr>
			<h3>Wenn diese Website <em>Quelle</em> ist</h3>
			<p class="description">Trage hier die Zentralen ein, die den Bestand abholen dürfen. Ohne Eintrag
			   ist die Abhol-Adresse gar nicht erst vorhanden – sie kann dann auch nicht angegriffen werden.</p>

			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th style="width:45%">Adresse der Zentrale</th><th>Gemeinsamer Schlüssel</th></tr></thead>
				<tbody>
				<?php
				$zeilen = $s['zentralen'];
				$zeilen[] = array( 'url' => '', 'schluessel' => '' ); // eine leere Zeile zum Ergänzen
				foreach ( $zeilen as $i => $z ) :
					?>
					<tr>
						<td><input type="url" class="regular-text" name="z_url[]" value="<?php echo esc_attr( $z['url'] ?? '' ); ?>" placeholder="https://bildung.igmetall.de"></td>
						<td><?php self::schluessel_feld( 'z_key[]', $z['schluessel'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Der Schlüssel muss in Quelle und Zentrale <strong>zeichengleich</strong> stehen.
			   In einer leeren Zeile steht bereits ein frisch erzeugter – <em>Kopieren</em> drücken und auf der
			   Gegenstelle einsetzen. Zum Leeren einer Zeile die Adresse löschen.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Herausgegeben werden</th>
					<td>
						<?php foreach ( array( 'publish' => 'Veröffentlichte', 'draft' => 'Entwürfe', 'private' => 'Private' ) as $key => $label ) : ?>
							<label style="margin-right:16px"><input type="checkbox" name="status[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $s['status'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						<p class="description">Ausgeblendete Seminare (Haken „Auf der Website anzeigen" aus) wandern
						   <strong>mit</strong> – samt Haken. Sie sind in der Zentrale dann genauso ausgeblendet.
						   Nur was hier gar nicht mehr herausgegeben wird, gilt der Zentrale als verschwunden.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Zeitfenster</th>
					<td>
						<label><input type="checkbox" name="nur_kuenftig" value="1" <?php checked( $s['nur_kuenftig'] ); ?>>
						   Nur abgleichen, was noch bevorsteht</label>
						<p style="margin:8px 0 4px">
							mit <input type="number" name="karenz" min="0" max="3650" value="<?php echo esc_attr( $s['karenz'] ); ?>" class="small-text"> Tagen Karenz
							<?php if ( ! empty( $s['nur_kuenftig'] ) ) : ?>
								<span class="description">– derzeit: alles ab Startdatum <strong><?php echo esc_html( self::stichtag() ); ?></strong></span>
							<?php endif; ?>
						</p>
						<p class="description">
							Ein Seminar, dessen Start vorbei ist, erscheint in <strong>keiner</strong> Suche – die
							Anzeige-Regel dieses Plugins lautet überall „Startdatum ab heute". Es abzugleichen kostet
							Zeit und ändert an dem, was jemand zu sehen bekommt, nichts. Der erste Lauf schrumpft
							damit von „alles, was je stattfand" auf „was noch kommt".<br>
							Die <strong>Karenz</strong> ist der Sicherheitsabstand: Quelle und Zentrale können in
							verschiedenen Zeitzonen stehen, und ein gerade vergangenes Seminar soll seine letzte
							Änderung noch mitbekommen.<br>
							<strong>Seminare ohne (oder mit krummem) Startdatum wandern immer mit.</strong> Ein
							Datenfehler darf keine Einträge aus der Zentrale verschwinden lassen.
						</p>
					</td>
				</tr>
			</table>

			<hr>
			<h3>Wenn diese Website <em>Zentrale</em> ist</h3>
			<p class="description">Die Quellen, deren Bestand hier zusammenläuft.</p>

			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th>Bezeichnung</th><th style="width:30%">Adresse</th><th>Gemeinsamer Schlüssel</th><th style="width:110px">Kennung</th></tr></thead>
				<tbody>
				<?php
				$zeilen = $s['quellen'];
				$zeilen[] = array( 'slug' => '', 'name' => '', 'url' => '', 'schluessel' => '' );
				foreach ( $zeilen as $q ) :
					?>
					<tr>
						<td><input type="text" class="regular-text" name="q_name[]" value="<?php echo esc_attr( $q['name'] ?? '' ); ?>" placeholder="Sprockhövel"></td>
						<td><input type="url" class="regular-text" name="q_url[]" value="<?php echo esc_attr( $q['url'] ?? '' ); ?>" placeholder="https://igmetall-sprockhoevel.de"></td>
						<td><?php self::schluessel_feld( 'q_key[]', $q['schluessel'] ?? '' ); ?></td>
						<td>
							<input type="text" class="small-text code" name="q_slug[]" value="<?php echo esc_attr( $q['slug'] ?? '' ); ?>" readonly>
							<input type="hidden" name="q_slug_vorhanden[]" value="1">
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Die <strong>Kennung</strong> vergibt sich beim ersten Speichern aus der Bezeichnung und
			   bleibt danach stehen: An ihr hängt jeder Herkunftsstempel in der Datenbank. Die Bezeichnung lässt sich
			   jederzeit ändern, die Kennung nicht.</p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Takt</th>
					<td>
						alle <input type="number" name="takt" min="5" max="1440" step="5" value="<?php echo esc_attr( $s['takt'] ); ?>" class="small-text"> Minuten
						<p class="description">Zusätzlich meldet jede Quelle sofort, wenn sich etwas geändert hat – die
						   Zentrale holt dann binnen einer Minute ab. Der Takt ist das Sicherheitsnetz für den Fall,
						   dass eine Meldung nicht ankommt.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Mitnehmen</th>
					<td>
						<label><input type="checkbox" name="reihen" value="1" <?php checked( $s['reihen'] ); ?>> Ausbildungsreihen</label><br>
						<label><input type="checkbox" name="bilder" value="1" <?php checked( $s['bilder'] ); ?>> Beitragsbilder holen <span class="description">(nur, wo hier noch keines gesetzt ist)</span></label>
					</td>
				</tr>
				<tr>
					<th scope="row">Schreibschutz</th>
					<td>
						<label><input type="checkbox" name="schutz" value="1" <?php checked( $s['schutz'] ); ?>> Abgeglichene Seminare hier nur lesbar</label>
						<p class="description">Empfohlen. Ohne den Schutz lässt sich hier bearbeiten, was der nächste
						   Abgleich wieder überschreibt – und niemand erfährt davon. Einzelne Einträge lassen sich in
						   der Seminarliste dauerhaft <em>aus dem Abgleich lösen</em>; die sind dann frei bearbeitbar
						   und werden nicht mehr angefasst.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Speichern' ); ?>
		</form>

		<?php
		$unterwegs = self::ist_zentrale() ? self::lauf_zustand() : null;
		if ( $unterwegs ) :
			$gesamt  = (int) ( $unterwegs['gesamt'] ?? 0 );
			$offen   = count( (array) ( $unterwegs['offen'] ?? array() ) );
			$fertig  = max( 0, $gesamt - $offen );
			$prozent = $gesamt ? round( $fertig / $gesamt * 100 ) : 100;
			?>
			<hr>
			<div class="notice notice-info" style="margin:0 0 12px">
				<p>
					<strong>Ein Abgleich mit „<?php echo esc_html( $unterwegs['quelle'] ); ?>" ist unterwegs.</strong><br>
					<?php printf( '%d von %d Seminaren geholt (%d %%).', (int) $fertig, (int) $gesamt, (int) $prozent ); ?>
					<?php
					$z = $unterwegs['zahlen'] ?? array();
					printf(
						' Bisher: %d neu, %d aktualisiert, %d übernommen, %d übersprungen.',
						(int) ( $z['neu'] ?? 0 ),
						(int) ( $z['aktualisiert'] ?? 0 ),
						(int) ( $z['uebernommen'] ?? 0 ),
						(int) ( $z['uebersprungen'] ?? 0 )
					);
					?>
				</p>
				<?php if ( ! empty( $unterwegs['hinweise'] ) ) : ?>
					<ul style="margin:0 0 10px 16px;list-style:disc">
						<?php foreach ( array_slice( (array) $unterwegs['hinweise'], -5 ) as $h ) : ?>
							<li><?php echo esc_html( $h ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
						<input type="hidden" name="action" value="bi_sync_jetzt">
						<input type="hidden" name="quelle" value="<?php echo esc_attr( $unterwegs['quelle'] ); ?>">
						<?php wp_nonce_field( 'bi_sync_jetzt' ); ?>
						<button type="submit" class="button button-primary">Weiter</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
						<input type="hidden" name="action" value="bi_sync_abbrechen">
						<?php wp_nonce_field( 'bi_sync_abbrechen' ); ?>
						<button type="submit" class="button">Abbrechen</button>
					</form>
				</p>
				<p class="description" style="margin-bottom:10px">Er arbeitet auch von selbst weiter, sobald der
				   WordPress-Cron das nächste Mal läuft – dafür genügt ein beliebiger Seitenaufruf. „Weiter" holt
				   ein größeres Stück auf einmal.</p>
			</div>
		<?php endif; ?>

		<?php if ( self::ist_zentrale() && $s['quellen'] ) : ?>
			<hr>
			<h3>Von Hand abgleichen</h3>
			<p>
			<?php foreach ( $s['quellen'] as $q ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px">
					<input type="hidden" name="action" value="bi_sync_jetzt">
					<input type="hidden" name="quelle" value="<?php echo esc_attr( $q['slug'] ); ?>">
					<?php wp_nonce_field( 'bi_sync_jetzt' ); ?>
					<button type="submit" class="button"<?php echo $unterwegs ? ' disabled' : ''; ?>>Jetzt abgleichen: <?php echo esc_html( $q['name'] ); ?></button>
				</form>
			<?php endforeach; ?>
			</p>
			<p class="description">Der Lauf arbeitet in Häppchen von <?php echo (int) self::HAEPPCHEN; ?> Seminaren.
			   <strong>Der erste Lauf holt den ganzen Bestand</strong> – in der Zentrale steht ja noch keine
			   Änderungsmarke, an der sich ablesen ließe, was neu ist. Ab dem zweiten Lauf kommt nur noch, was
			   sich in der Quelle wirklich geändert hat.</p>
		<?php endif; ?>

		<?php
		$p = self::protokoll();
		if ( $p ) :
			?>
			<hr>
			<h3>Die letzten Läufe</h3>
			<table class="widefat striped">
				<thead><tr><th style="width:150px">Zeit</th><th style="width:140px">Quelle</th><th>Ergebnis</th></tr></thead>
				<tbody>
				<?php foreach ( $p as $e ) : ?>
					<tr>
						<td><?php echo esc_html( $e['zeit'] ?? '' ); ?></td>
						<td><?php echo esc_html( $e['quelle'] ?? '' ); ?></td>
						<td>
							<?php if ( ! empty( $e['fehler'] ) ) : ?>
								<span style="color:#b32d2e">⚠ <?php echo esc_html( $e['fehler'] ); ?></span>
							<?php else :
								$z = $e['zahlen'] ?? array();
								printf(
									'%d neu, %d aktualisiert, %d übernommen, %d ausgeblendet, %d übersprungen',
									(int) ( $z['neu'] ?? 0 ),
									(int) ( $z['aktualisiert'] ?? 0 ),
									(int) ( $z['uebernommen'] ?? 0 ),
									(int) ( $z['ausgeblendet'] ?? 0 ),
									(int) ( $z['uebersprungen'] ?? 0 )
								);
								if ( ! empty( $e['hinweise'] ) ) {
									echo '<ul style="margin:6px 0 0 16px;list-style:disc">';
									foreach ( array_slice( (array) $e['hinweise'], 0, 25 ) as $h ) {
										echo '<li>' . esc_html( $h ) . '</li>';
									}
									echo '</ul>';
								}
							endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<style>
			.bi-sync-key { display:flex; gap:6px; align-items:center; flex-wrap:wrap }
			.bi-sync-key input { flex:1 1 260px; min-width:200px }
			.bi-sync-frisch { flex-basis:100%; color:#646970 }
			.bi-sync-key .bi-sync-ok { color:#1d6b2f; font-weight:600 }
		</style>
		<script>
		( function () {
			// Kopieren. navigator.clipboard gibt es nur über HTTPS (und auf
			// localhost); über eine unverschlüsselte Verbindung ist es schlicht
			// nicht da. Deshalb der zweite Weg über execCommand – veraltet, aber
			// überall vorhanden. Ohne den bliebe der Knopf auf einer HTTP-Seite
			// wirkungslos, und niemand wüsste, warum.
			function kopieren( feld, knopf ) {
				var fertig = function () {
					var alt = knopf.textContent;
					knopf.textContent = 'Kopiert';
					knopf.classList.add( 'bi-sync-ok' );
					setTimeout( function () {
						knopf.textContent = alt;
						knopf.classList.remove( 'bi-sync-ok' );
					}, 1600 );
				};
				feld.focus();
				feld.select();
				feld.setSelectionRange( 0, 999 );

				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( feld.value ).then( fertig, function () {
						if ( document.execCommand( 'copy' ) ) { fertig(); }
					} );
					return;
				}
				try {
					if ( document.execCommand( 'copy' ) ) { fertig(); return; }
				} catch ( e ) {}
				// Auch das ging nicht: Der Text ist immerhin markiert, Strg+C tut es.
				knopf.textContent = 'Strg+C';
			}

			// 48 Zeichen aus dem Zufallsgenerator des Browsers – dieselbe Länge
			// und derselbe Zeichenvorrat wie wp_generate_password() sie serverseitig
			// liefert.
			function erzeugen() {
				var vorrat = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
				var bytes  = new Uint32Array( 48 );
				window.crypto.getRandomValues( bytes );
				var out = '';
				for ( var i = 0; i < bytes.length; i++ ) {
					out += vorrat.charAt( bytes[ i ] % vorrat.length );
				}
				return out;
			}

			document.addEventListener( 'click', function ( ev ) {
				var knopf = ev.target.closest ? ev.target.closest( '.bi-sync-copy, .bi-sync-new' ) : null;
				if ( ! knopf ) { return; }
				ev.preventDefault();
				var feld = knopf.parentNode.querySelector( 'input' );
				if ( ! feld ) { return; }

				if ( knopf.classList.contains( 'bi-sync-copy' ) ) {
					kopieren( feld, knopf );
					return;
				}
				// „Neu": Ein bereits GESPEICHERTER Schlüssel wird damit ungültig,
				// bis er auch auf der Gegenstelle steht. Deshalb einmal nachfragen.
				// Beim frischen Vorschlag einer leeren Zeile wäre die Frage sinnlos –
				// dort hängt noch nichts daran.
				if ( feld.hasAttribute( 'data-gespeichert' ) ) {
					if ( ! window.confirm( 'Neuen Schlüssel erzeugen? Der Abgleich mit dieser Gegenstelle bricht ab, bis der neue Schlüssel auch dort eingetragen ist.' ) ) {
						return;
					}
				}
				feld.value = erzeugen();
				kopieren( feld, knopf.parentNode.querySelector( '.bi-sync-copy' ) || knopf );
			} );
		} )();
		</script>
		<?php
	}
}
