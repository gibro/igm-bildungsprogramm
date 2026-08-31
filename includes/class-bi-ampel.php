<?php
/**
 * Verfügbarkeits-Ampel der Seminare.
 *
 * Zeigt auf der Detailseite an, ob ein Termin noch frei ist. Die Angabe stammt
 * aus dem Seminarverwaltungssystem des Vorstands und wird über die öffentlichen
 * Seiten von modules.igmetall.de eingesammelt (class-bi-ampel-crawler.php),
 * geparst (class-bi-ampel-parser.php) und hier vorgehalten.
 *
 * ── Warum eine eigene Tabelle und kein Live-Abruf ────────────────────────────
 * Beim Seitenaufruf wird NICHT bei igmetall.de angefragt. Sonst bestimmte ein
 * fremder Server unsere Ladezeit, ein Ausfall dort bräche unsere Seite, und für
 * eine einzige Farbe würde jedes Mal die komplette Termintabelle eines Angebots
 * geholt. Stattdessen: nächtlicher Abgleich, zur Laufzeit ein indizierter
 * Datenbank-Zugriff.
 *
 * ── Generationen (Spalte run_id) ─────────────────────────────────────────────
 * Jeder Lauf schreibt unter einer eigenen Lauf-Nummer. Erst wenn er vollständig
 * durch ist, wird diese Nummer „live" geschaltet und die alte Generation
 * gelöscht. Ein abgebrochener Lauf hinterlässt damit keine halbleere Tabelle,
 * sondern verändert die Anzeige überhaupt nicht.
 *
 * ── Wann KEINE Ampel erscheint ───────────────────────────────────────────────
 *   - die Seminarnummer steht nicht in der Tabelle
 *   - die Klasse in der Quelle war unbekannt (siehe Parser)
 *   - die Daten sind älter als die eingestellte Höchstfrist (Vorgabe 48 h)
 *   - das Modul ist abgeschaltet
 * In all diesen Fällen wird nichts angezeigt. Eine grüne Ampel auf einem vollen
 * Seminar wäre schlimmer als gar keine Ampel.
 *
 * ── Barrierefreiheit ─────────────────────────────────────────────────────────
 * Die Information hängt nie allein an der Farbe: neben dem Punkt steht immer
 * das Textlabel der Quelle, davor für Screenreader das Wort „Verfügbarkeit".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Ampel {

	/** Cron: Taktgeber. Prüft, ob ein Lauf ansteht, und arbeitet ihn häppchenweise ab. */
	const HOOK      = 'bi_ampel_tick';
	const SCHEDULE  = 'bi_ampel_5min';

	const OPT_LIVE      = 'bi_ampel_live';       // run_id der derzeit gültigen Generation
	const OPT_LAUF      = 'bi_ampel_lauf';       // Zustand des laufenden Laufs
	const OPT_PROTOKOLL = 'bi_ampel_protokoll';  // Ergebnis des letzten Laufs
	const OPT_FAELLIG   = 'bi_ampel_faellig';    // 1 = außerplanmäßig fällig (z. B. nach Import)

	/**
	 * Was die Ampel zuletzt selbst in den Haken „Ausgebucht" geschrieben hat
	 * ('1' oder '0'). Der Bezugspunkt für Handkorrekturen: Weicht der Haken
	 * heute davon ab, hat ihn seither ein Mensch geändert.
	 */
	const META_NOTIZ = '_bi_ausgebucht_ampel';

	/**
	 * Steht am Seminar, solange der Haken „Ausgebucht" auf die Ampel zurückgeht
	 * – also von ihr gesetzt und seither von niemandem angefasst wurde. Nur was
	 * diesen Vermerk trägt, gibt das Abschalten der Ampel wieder frei; ein von
	 * Hand oder aus einem Import gesetzter Haken bleibt stehen.
	 */
	const META_QUELLE = '_bi_ausgebucht_von_ampel';

	/** Zwischenspeicher für den Seitenaufbau: Seminarnummer => Zeile|false */
	private static $cache = array();

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_bi_ampel_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_bi_ampel_abbrechen', array( __CLASS__, 'handle_abbrechen' ) );
		add_action( 'admin_post_bi_ampel_hand_loesen', array( __CLASS__, 'handle_hand_loesen' ) );
		add_action( 'admin_post_bi_ampel_freigeben', array( __CLASS__, 'handle_freigeben' ) );
		// Treibt einen laufenden Abgleich vom geöffneten Einstellungs-Tab aus voran.
		add_action( 'wp_ajax_bi_ampel_schritt', array( __CLASS__, 'ajax_schritt' ) );
	}

	/** ---------- Tabelle ---------- */

	public static function table() {
		return bi_table( 'ampel' );
	}

	/**
	 * Tabelle anlegen bzw. aktualisieren.
	 *
	 * datum_von/datum_bis sind bewusst VARCHAR und nicht DATE: sie werden nur
	 * angezeigt, nie gerechnet, und ein leeres Datum aus einer unvollständigen
	 * Quellzeile darf im strict mode keinen Schreibfehler auslösen. Das Format
	 * bleibt Y-m-d und sortiert damit auch als Text richtig.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(20) NOT NULL DEFAULT '',
			seminarnummer VARCHAR(32) NOT NULL DEFAULT '',
			sid CHAR(36) NOT NULL DEFAULT '',
			titel VARCHAR(255) NOT NULL DEFAULT '',
			standort VARCHAR(128) NOT NULL DEFAULT '',
			datum_von VARCHAR(10) NOT NULL DEFAULT '',
			datum_bis VARCHAR(10) NOT NULL DEFAULT '',
			ampel VARCHAR(16) NOT NULL DEFAULT '',
			label VARCHAR(64) NOT NULL DEFAULT '',
			abgerufen_am DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY lauf_nummer (run_id, seminarnummer),
			KEY nummer (seminarnummer),
			KEY sid (sid)
		) $charset;";
		dbDelta( $sql );
	}

	/** Eine Terminzeile der laufenden Generation schreiben. */
	public static function zeile_schreiben( $run_id, array $termin ) {
		global $wpdb;
		if ( empty( $termin['seminarnummer'] ) ) {
			return false;
		}
		$ok = $wpdb->replace( self::table(), array(
			'run_id'        => (string) $run_id,
			'seminarnummer' => substr( (string) $termin['seminarnummer'], 0, 32 ),
			'sid'           => substr( (string) ( $termin['sid'] ?? '' ), 0, 36 ),
			'titel'         => substr( (string) ( $termin['titel'] ?? '' ), 0, 255 ),
			'standort'      => substr( (string) ( $termin['standort'] ?? '' ), 0, 128 ),
			'datum_von'     => substr( (string) ( $termin['datum_von'] ?? '' ), 0, 10 ),
			'datum_bis'     => substr( (string) ( $termin['datum_bis'] ?? '' ), 0, 10 ),
			'ampel'         => substr( (string) ( $termin['ampel'] ?? '' ), 0, 16 ),
			'label'         => substr( (string) ( $termin['label'] ?? '' ), 0, 64 ),
			'abgerufen_am'  => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		if ( false === $ok ) {
			error_log( 'BI_Ampel: Zeile ' . $termin['seminarnummer'] . ' konnte nicht geschrieben werden. ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		return true;
	}

	/** ---------- Generationen ---------- */

	public static function live_generation() {
		return (string) get_option( self::OPT_LIVE, '' );
	}

	/** Neue Generation gültig machen und alle anderen entfernen. */
	public static function generation_live_schalten( $run_id ) {
		global $wpdb;
		update_option( self::OPT_LIVE, (string) $run_id, false );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE run_id <> %s', (string) $run_id ) );
		self::$cache = array();
	}

	/**
	 * Den Haken „Ausgebucht" aus der Verfügbarkeit der gültigen Generation setzen.
	 *
	 * „Warteliste möglich" (rot) heißt: keine freien Plätze mehr. Genau das sagt
	 * der Haken – also setzt ihn der Abgleich, statt die Angabe nur anzuzeigen
	 * und die Pflege von Hand nachziehen zu lassen. „Verfügbar" (grün) und „Fast
	 * ausgebucht" (orange) nehmen ihn wieder zurück; ein Termin, der wieder frei
	 * wird, bliebe sonst für immer als ausgebucht stehen.
	 *
	 * WAS DAS FÜR HANDARBEIT BEDEUTET: Ein von Hand gesetzter Haken hält nur bis
	 * zum nächsten Lauf, sofern die Quelle den Termin kennt. Seminare, deren
	 * Nummer in der Generation NICHT vorkommt, bleiben unangetastet – dort
	 * entscheidet weiter die Pflege.
	 *
	 * Termine mit unbekanntem Zustand (ampel = '') zählen nicht: Aus „weiß nicht"
	 * darf keine Aussage über die Buchbarkeit werden.
	 *
	 * @return array ['gesetzt' => int, 'geloest' => int]
	 */
	public static function ausgebucht_abgleichen() {
		global $wpdb;

		$bilanz = array( 'gesetzt' => 0, 'geloest' => 0, 'respektiert' => 0 );
		$run    = self::live_generation();
		if ( '' === $run ) {
			return $bilanz;
		}

		// Sollzustand je Seminarnummer aus der gültigen Generation
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT seminarnummer, ampel FROM ' . self::table() . ' WHERE run_id = %s AND ampel <> %s', // phpcs:ignore WordPress.DB.PreparedSQL
			$run,
			''
		) );
		if ( ! $rows ) {
			return $bilanz;
		}

		$soll = array();
		foreach ( $rows as $r ) {
			$nummer = BI_Ampel_Parser::nummer_normalisieren( $r->seminarnummer );
			if ( '' !== $nummer ) {
				$soll[ $nummer ] = ( 'red' === (string) $r->ampel );
			}
		}
		if ( ! $soll ) {
			return $bilanz;
		}

		// Die Seminare zu diesen Nummern in einem Zug holen – je Nummer eine
		// eigene Abfrage wären bei tausend Terminen tausend Abfragen.
		$nummern = array_keys( $soll );
		$platz   = implode( ',', array_fill( 0, count( $nummern ), '%s' ) );
		$typen   = bi_seminar_post_types();
		$platz_t = implode( ',', array_fill( 0, count( $typen ), '%s' ) );

		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.post_id, m.meta_value AS nummer
			 FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = '_bi_seminarnummer' AND m.meta_value IN ($platz)
			   AND p.post_type IN ($platz_t) AND p.post_status <> 'trash'", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( $nummern, $typen )
		) );

		foreach ( (array) $posts as $p ) {
			$nummer = BI_Ampel_Parser::nummer_normalisieren( $p->nummer );
			if ( ! isset( $soll[ $nummer ] ) ) {
				continue;
			}
			$id    = (int) $p->post_id;
			$voll  = $soll[ $nummer ];
			$ist   = BI_CPT::meta_bool( $id, '_bi_ausgebucht' );
			$notiz = (string) get_post_meta( $id, self::META_NOTIZ, true );

			// Hat jemand den Haken angefasst, seit die Ampel zuletzt geschrieben hat?
			//
			// Dann gilt die Handkorrektur – aber nicht für immer, sondern so lange,
			// wie die Quelle DASSELBE sagt wie beim letzten Mal. Meldet sie etwas
			// Neues, ist das eine frische Nachricht und darf die alte Korrektur
			// ablösen. Ohne diese zweite Hälfte fröre ein einmal von Hand
			// freigegebenes Seminar für immer als buchbar ein, auch wenn es
			// später wirklich voll ist.
			if ( '' !== $notiz && $ist !== ( '1' === $notiz ) ) {
				if ( $voll === ( '1' === $notiz ) ) {
					$bilanz['respektiert']++;
					continue;
				}
			}

			if ( $ist !== $voll ) {
				update_post_meta( $id, '_bi_ausgebucht', $voll ? '1' : '0' );
				$bilanz[ $voll ? 'gesetzt' : 'geloest' ]++;
			}

			// Wem gehört der Haken? Davon hängt ab, ob das Abschalten des Moduls
			// ihn später wieder herausnimmt (siehe ausgebucht_freigeben).
			if ( ! $voll ) {
				// Die Quelle sagt frei. Steht der Haken trotzdem, ist er nicht
				// ihrer – so bleibt eine Handkorrektur von der Freigabe verschont.
				update_post_meta( $id, self::META_QUELLE, '0' );
			} elseif ( $ist !== $voll ) {
				// Diesen Lauf selbst umgelegt: ab jetzt ihrer.
				update_post_meta( $id, self::META_QUELLE, '1' );
			} elseif ( '' === (string) get_post_meta( $id, self::META_QUELLE, true ) ) {
				// Der Haken stand schon vorher, und niemand hat vermerkt, von wem.
				// Das ist der Altbestand aus der Zeit vor diesem Vermerk: Dort ist
				// die alte Notiz der einzige Hinweis – sie sagt immerhin, ob die
				// Ampel zuletzt „voll" geschrieben hat. Ohne diesen Nachtrag
				// müsste die Freigabe für immer raten, und ein von Hand gesetzter
				// Haken sähe aus wie ihrer.
				update_post_meta( $id, self::META_QUELLE, ( '1' === $notiz ) ? '1' : '0' );
			}

			// Die Notiz auch dann nachziehen, wenn nichts zu ändern war: Sonst
			// fehlte der Bezugspunkt für die nächste Runde. update_post_meta()
			// schreibt nur, wenn sich der Wert wirklich ändert.
			update_post_meta( $id, self::META_NOTIZ, $voll ? '1' : '0' );
		}

		return $bilanz;
	}

	/**
	 * Ist der Haken „Ausgebucht" dieses Seminars eine Handkorrektur – also seit
	 * dem letzten Ampel-Schreiben von Hand geändert?
	 */
	public static function hand_korrektur( $post_id ) {
		$notiz = (string) get_post_meta( (int) $post_id, self::META_NOTIZ, true );
		if ( '' === $notiz ) {
			return false;
		}
		return BI_CPT::meta_bool( (int) $post_id, '_bi_ausgebucht' ) !== ( '1' === $notiz );
	}

	/**
	 * Die Notiz vergessen – danach schreibt die Ampel wieder ohne Rückfrage.
	 *
	 * Wird nicht nur von Hand aufgerufen, sondern auch von den Importen: Steht
	 * der Haken in einer eingelesenen Datei, ist das eine frische Aussage der
	 * Programmplanung und keine Korrektur an der Ampel. Ohne dieses Vergessen
	 * hielte ein Reimport, der alle Haken auf „nein" setzt, anschließend jedes
	 * volle Seminar fälschlich als buchbar fest.
	 */
	public static function hand_zuruecksetzen( $post_id ) {
		delete_post_meta( (int) $post_id, self::META_NOTIZ );
		// Mit der Notiz fällt auch der Besitzanspruch: Steht der Haken in einer
		// eingelesenen Datei, gehört er der Programmplanung und nicht der Ampel.
		delete_post_meta( (int) $post_id, self::META_QUELLE );
	}

	/**
	 * Alle Seminare, deren Haken „Ausgebucht" gerade von Hand gegen die Ampel
	 * steht. Eine Abfrage; verglichen wird danach in PHP.
	 *
	 * @return array je Eintrag [id, titel, nummer, ausgebucht(bool), ampel(bool)]
	 */
	public static function hand_korrekturen( $limit = 200 ) {
		global $wpdb;
		$typen   = bi_seminar_post_types();
		$platz_t = implode( ',', array_fill( 0, count( $typen ), '%s' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title,
			        n.meta_value AS notiz,
			        COALESCE( a.meta_value, '' ) AS ist,
			        COALESCE( s.meta_value, '' ) AS nummer
			   FROM {$wpdb->postmeta} n
			   INNER JOIN {$wpdb->posts} p ON p.ID = n.post_id
			   LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_bi_ausgebucht'
			   LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_bi_seminarnummer'
			  WHERE n.meta_key = %s
			    AND p.post_type IN ($platz_t)
			    AND p.post_status <> 'trash'
			  ORDER BY p.post_title ASC
			  LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( array( self::META_NOTIZ ), $typen, array( (int) $limit ) )
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$ampel = ( '1' === (string) $r->notiz );
			// Leerer Haken heißt „nicht ausgebucht" – das ist die Vorgabe des Feldes.
			$ist   = ( '1' === (string) $r->ist );
			if ( $ist === $ampel ) {
				continue; // keine Abweichung
			}
			$out[] = array(
				'id'         => (int) $r->ID,
				'titel'      => (string) $r->post_title,
				'nummer'     => (string) $r->nummer,
				'ausgebucht' => $ist,
				'ampel'      => $ampel,
			);
		}
		return $out;
	}

	/**
	 * Alle Seminare, deren Haken „Ausgebucht" auf die Ampel zurückgeht.
	 *
	 * Das sind die Kandidaten für die Freigabe beim Abschalten des Moduls. Zwei
	 * Fälle zählen dazu:
	 *
	 *   - Am Seminar steht der Vermerk META_QUELLE. Ihn setzt der Abgleich genau
	 *     dann, wenn er den Haken selbst umgelegt hat.
	 *   - Der Vermerk fehlt, aber die Notiz sagt „ausgebucht". Das ist der
	 *     Altbestand aus der Zeit vor dem Vermerk: Damals wurde nur festgehalten,
	 *     WAS die Ampel zuletzt geschrieben hat, nicht ob sie den Haken damit
	 *     bewegt hat. Für diese Seminare ist die Notiz der einzige Hinweis – und
	 *     sie sagt immerhin, dass die Ampel dort zuletzt „voll" geschrieben hat.
	 *
	 * Nicht dabei: Seminare ohne beides. Deren Haken stammt von Hand oder aus
	 * einem Import und bleibt unangetastet – eine Freigabe wäre dort schlimmer
	 * als eine fehlende, weil sie ein wirklich volles Seminar buchbar machte.
	 *
	 * @return array je Eintrag [id, titel, nummer]
	 */
	public static function ausgebucht_von_ampel( $limit = 500 ) {
		global $wpdb;

		$rows = $wpdb->get_results( self::von_ampel_sql(
			"p.ID, p.post_title, COALESCE( s.meta_value, '' ) AS nummer",
			'ORDER BY p.post_title ASC LIMIT %d',
			array( max( 1, (int) $limit ) )
		) );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'     => (int) $r->ID,
				'titel'  => (string) $r->post_title,
				'nummer' => (string) $r->nummer,
			);
		}
		return $out;
	}

	/** Wie viele Seminare stehen wegen der Ampel auf ausgebucht? */
	public static function ausgebucht_von_ampel_anzahl() {
		global $wpdb;
		return (int) $wpdb->get_var( self::von_ampel_sql( 'COUNT(*)', '' ) );
	}

	/**
	 * Der gemeinsame Zugriff hinter Liste und Zählung – einmal formuliert, damit
	 * die Bedingung „gehört der Ampel" nicht an zwei Stellen auseinanderlaufen
	 * kann. Was sie bedeutet, steht bei ausgebucht_von_ampel().
	 */
	private static function von_ampel_sql( $select, $tail, array $extra = array() ) {
		global $wpdb;
		$typen   = bi_seminar_post_types();
		$platz_t = implode( ',', array_fill( 0, count( $typen ), '%s' ) );

		return $wpdb->prepare(
			"SELECT $select
			   FROM {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_bi_ausgebucht' AND a.meta_value = '1'
			   LEFT JOIN {$wpdb->postmeta} q ON q.post_id = p.ID AND q.meta_key = %s
			   LEFT JOIN {$wpdb->postmeta} n ON n.post_id = p.ID AND n.meta_key = %s
			   LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_bi_seminarnummer'
			  WHERE p.post_type IN ($platz_t)
			    AND p.post_status <> 'trash'
			    AND ( q.meta_value = '1' OR ( q.meta_value IS NULL AND n.meta_value = '1' ) )
			  $tail", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( array( self::META_QUELLE, self::META_NOTIZ ), $typen, $extra )
		);
	}

	/**
	 * Alle von der Ampel gesetzten Ausbuchungen zurücknehmen.
	 *
	 * Der Anlass: Die Quelle ist zeitweise unzuverlässig, das Modul wird
	 * abgeschaltet – und die Seminare, die dabei fälschlich auf „ausgebucht"
	 * stehen, bleiben es. Von Hand wäre das eine Suche über den ganzen Bestand,
	 * bei der man mühsam auseinanderhalten müsste, welcher Haken von der Ampel
	 * und welcher aus der Pflege stammt. Genau das weiß der Vermerk.
	 *
	 * Mit dem Haken gehen Vermerk UND Notiz. Das ist Absicht: Danach steht am
	 * Seminar keine halbe Aussage der Ampel mehr, sondern nichts – und wird das
	 * Modul später wieder eingeschaltet, schreibt der erste Abgleich dort ohne
	 * Rückfrage den Stand der Quelle. Bliebe die Notiz stehen, sähe die Freigabe
	 * wie eine Handkorrektur aus und würde ein später wirklich volles Seminar
	 * auf Dauer als buchbar festhalten.
	 *
	 * @return int Zahl der freigegebenen Seminare
	 */
	public static function ausgebucht_freigeben() {
		$n = 0;
		foreach ( self::ausgebucht_von_ampel( 5000 ) as $seminar ) {
			update_post_meta( $seminar['id'], '_bi_ausgebucht', '0' );
			delete_post_meta( $seminar['id'], self::META_QUELLE );
			delete_post_meta( $seminar['id'], self::META_NOTIZ );
			$n++;
		}
		return $n;
	}

	/** Eine (unvollständige) Generation wegräumen. */
	public static function generation_loeschen( $run_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE run_id = %s', (string) $run_id ) );
	}

	/** ---------- Lauf-Zustand ---------- */

	public static function lauf() {
		$lauf = get_option( self::OPT_LAUF, array() );
		return ( is_array( $lauf ) && ! empty( $lauf['run_id'] ) ) ? $lauf : null;
	}

	public static function lauf_setzen( array $lauf ) {
		update_option( self::OPT_LAUF, $lauf, false );
	}

	public static function lauf_loeschen() {
		delete_option( self::OPT_LAUF );
	}

	public static function protokoll_schreiben( array $eintrag ) {
		update_option( self::OPT_PROTOKOLL, $eintrag, false );
	}

	public static function protokoll() {
		$p = get_option( self::OPT_PROTOKOLL, array() );
		return is_array( $p ) ? $p : array();
	}

	/** ---------- Cron ---------- */

	public static function cron_schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Alle 5 Minuten (Verfügbarkeits-Ampel)',
		);
		return $schedules;
	}

	/**
	 * Der Taktgeber muss stehen, bevor geplant wird – bei der Aktivierung ist der
	 * cron_schedules-Filter noch nicht registriert und wp_schedule_event() würde
	 * das unbekannte Intervall ablehnen.
	 */
	private static function ensure_interval() {
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		}
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::ensure_interval();
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Ein Cron-Tick.
	 *
	 * Läuft gerade ein Lauf, wird ein Stück davon abgearbeitet. Sonst wird
	 * geprüft, ob ein neuer ansteht – entweder weil das Abstands-Intervall
	 * abgelaufen ist oder weil ein Import einen außerplanmäßigen Abgleich
	 * angefordert hat.
	 *
	 * Hinweis zum Betrieb: WP-Cron feuert nur bei Seitenaufrufen. Für einen
	 * verlässlichen nächtlichen Abgleich gehört ein echter System-Cron auf
	 * wp-cron.php (und DISABLE_WP_CRON in der wp-config.php). Der Ablauf hier
	 * funktioniert mit beidem.
	 */
	public static function tick() {
		if ( ! self::aktiv() ) {
			return;
		}
		if ( self::lauf() ) {
			BI_Ampel_Crawler::schritt();
			return;
		}
		if ( self::faellig() ) {
			if ( BI_Ampel_Crawler::start( self::faellig_ausserplanmaessig() ? 'import' : 'plan' ) ) {
				BI_Ampel_Crawler::schritt();
			}
		}
	}

	/** Steht ein neuer Lauf an? */
	public static function faellig() {
		if ( self::faellig_ausserplanmaessig() ) {
			return true;
		}
		$protokoll = self::protokoll();
		$letzter   = isset( $protokoll['zeit'] ) ? (int) $protokoll['zeit'] : 0;
		return ( time() - $letzter ) >= self::intervall() * HOUR_IN_SECONDS;
	}

	public static function faellig_ausserplanmaessig() {
		return (bool) get_option( self::OPT_FAELLIG, 0 );
	}

	public static function faellig_markieren() {
		update_option( self::OPT_FAELLIG, 1, false );
	}

	public static function faellig_zuruecksetzen() {
		delete_option( self::OPT_FAELLIG );
	}

	/** ---------- Einstellungen ---------- */

	public static function aktiv() {
		return (bool) BI_Settings::get( 'ampel_aktiv' );
	}

	/** Stunden zwischen zwei planmäßigen Läufen */
	public static function intervall() {
		return max( 1, (int) BI_Settings::get( 'ampel_intervall' ) );
	}

	/** Höchstalter der Daten in Stunden; danach wird nichts mehr angezeigt */
	public static function max_alter() {
		return max( 1, (int) BI_Settings::get( 'ampel_max_alter' ) );
	}

	/** ---------- Nachschlagen ---------- */

	/**
	 * Ampel zu einer Seminarnummer. null, wenn nichts Belastbares vorliegt.
	 *
	 * @return array|null [ ampel, label, abgerufen_am (Timestamp), stand (d.m.Y) ]
	 */
	public static function fuer_nummer( $nummer ) {
		$nummer = BI_Ampel_Parser::nummer_normalisieren( $nummer );
		if ( '' === $nummer || ! self::aktiv() ) {
			return null;
		}

		if ( ! array_key_exists( $nummer, self::$cache ) ) {
			self::vorladen( array( $nummer ) );
		}
		$zeile = self::$cache[ $nummer ] ?? false;
		if ( ! $zeile ) {
			return null;
		}

		// Unbekannte Klasse in der Quelle: gespeichert, aber nicht anzeigbar.
		if ( '' === $zeile['ampel'] ) {
			return null;
		}

		$abgerufen = $zeile['abgerufen_am'] ? strtotime( $zeile['abgerufen_am'] ) : 0;
		if ( ! $abgerufen || ( current_time( 'timestamp' ) - $abgerufen ) > self::max_alter() * HOUR_IN_SECONDS ) {
			return null; // veraltet – lieber keine Angabe als eine überholte
		}

		return array(
			'ampel'        => $zeile['ampel'],
			'label'        => $zeile['label'],
			'abgerufen_am' => $abgerufen,
			'stand'        => date_i18n( 'd.m.Y', $abgerufen ),
		);
	}

	/**
	 * Mehrere Nummern in einer Abfrage in den Zwischenspeicher holen.
	 * Wird von der Termintabelle der Detailseite genutzt, damit dort nicht je
	 * Zeile eine eigene Abfrage läuft.
	 */
	public static function vorladen( array $nummern ) {
		global $wpdb;

		$offen = array();
		foreach ( $nummern as $nr ) {
			$nr = BI_Ampel_Parser::nummer_normalisieren( $nr );
			if ( '' !== $nr && ! array_key_exists( $nr, self::$cache ) ) {
				$offen[ $nr ] = $nr;
			}
		}
		if ( ! $offen ) {
			return;
		}

		$live = self::live_generation();
		if ( '' === $live ) {
			foreach ( $offen as $nr ) {
				self::$cache[ $nr ] = false;
			}
			return;
		}

		// Erst als „nicht vorhanden" vormerken, gefundene Zeilen überschreiben das.
		foreach ( $offen as $nr ) {
			self::$cache[ $nr ] = false;
		}

		$platzhalter = implode( ',', array_fill( 0, count( $offen ), '%s' ) );
		$werte       = array_merge( array( $live ), array_values( $offen ) );
		$zeilen      = $wpdb->get_results( $wpdb->prepare(
			'SELECT seminarnummer, ampel, label, abgerufen_am FROM ' . self::table()
			. " WHERE run_id = %s AND seminarnummer IN ($platzhalter)",
			$werte
		), ARRAY_A );

		foreach ( (array) $zeilen as $zeile ) {
			self::$cache[ $zeile['seminarnummer'] ] = $zeile;
		}
	}

	/** Ampel zu einem Beitrag (liest dessen Seminarnummer). */
	public static function fuer_post( $post_id ) {
		return self::fuer_nummer( get_post_meta( (int) $post_id, '_bi_seminarnummer', true ) );
	}

	/**
	 * Wie fuer_nummer(), aber mit Begründung – für die Stichprobe im Backend.
	 *
	 * fuer_nummer() gibt bei jedem Hindernis schlicht null zurück, weil auf der
	 * Website nichts erscheinen soll. Bei der Fehlersuche ist genau das lästig:
	 * man sieht keine Ampel und weiß nicht, woran es liegt. Diese Fassung sagt es.
	 *
	 * @return array [ ok (bool), text (string) ]
	 */
	public static function pruefen( $nummer ) {
		$roh  = trim( (string) $nummer );
		$norm = BI_Ampel_Parser::nummer_normalisieren( $roh );

		if ( '' === $roh ) {
			return array( 'ok' => false, 'text' => 'am Seminar ist keine Seminarnummer hinterlegt' );
		}
		if ( '' === $norm ) {
			return array( 'ok' => false, 'text' => 'unerwartetes Format der Seminarnummer' );
		}
		if ( ! self::aktiv() ) {
			return array( 'ok' => false, 'text' => 'das Modul ist abgeschaltet' );
		}
		if ( '' === self::live_generation() ) {
			return array( 'ok' => false, 'text' => 'es ist noch kein Abgleich vollständig durchgelaufen' );
		}

		self::vorladen( array( $norm ) );
		$zeile = self::$cache[ $norm ] ?? false;

		if ( ! $zeile ) {
			return array( 'ok' => false, 'text' => 'diese Nummer steht nicht in den abgeglichenen Daten' );
		}
		if ( '' === $zeile['ampel'] ) {
			return array( 'ok' => false, 'text' => 'die Quelle meldet einen unbekannten Zustand („' . $zeile['label'] . '")' );
		}

		$ts = $zeile['abgerufen_am'] ? strtotime( $zeile['abgerufen_am'] ) : 0;
		if ( ! $ts ) {
			return array( 'ok' => false, 'text' => 'kein Abrufzeitpunkt gespeichert' );
		}
		$alter = (int) floor( ( current_time( 'timestamp' ) - $ts ) / HOUR_IN_SECONDS );
		if ( $alter > self::max_alter() ) {
			return array( 'ok' => false, 'text' => sprintf( 'Daten sind %d Stunden alt, erlaubt sind %d', $alter, self::max_alter() ) );
		}

		return array( 'ok' => true, 'text' => $zeile['label'] );
	}

	/**
	 * Die nächsten anstehenden Präsenz-Seminare mit dem Ergebnis des Nachschlagens.
	 * Beantwortet im Backend die Frage „warum steht auf der Detailseite nichts?".
	 */
	public static function stichprobe( $anzahl = 10 ) {
		$posts = get_posts( array(
			'post_type'      => BI_CPT,
			'post_status'    => 'publish',
			'numberposts'    => (int) $anzahl,
			'meta_key'       => '_bi_startdatum',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_type'      => 'DATE',
			'meta_query'     => array(
				array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
			),
		) );

		$zeilen = array();
		foreach ( $posts as $p ) {
			$nr       = (string) get_post_meta( $p->ID, '_bi_seminarnummer', true );
			$zeilen[] = array(
				'id'      => $p->ID,
				'titel'   => get_the_title( $p ),
				'nummer'  => $nr,
				'ergebnis' => self::pruefen( $nr ),
			);
		}
		return $zeilen;
	}

	/** ---------- Ausgabe ---------- */

	/**
	 * Ampel als HTML. Leerer String, wenn nichts vorliegt.
	 *
	 * Der Punkt ist rein dekorativ (aria-hidden); die Aussage steht als Text
	 * daneben, wie es die Quelle auch macht. Vor dem Text steht für Screenreader
	 * unsichtbar das Wort „Verfügbarkeit", damit die Angabe nicht kontextlos
	 * vorgelesen wird.
	 */
	public static function badge( $nummer ) {
		$daten = is_array( $nummer ) ? $nummer : self::fuer_nummer( $nummer );
		if ( ! $daten ) {
			return '';
		}
		return '<span class="igm-ampel igm-ampel--' . esc_attr( $daten['ampel'] ) . '">'
			. '<span class="igm-ampel__punkt" aria-hidden="true"></span>'
			. '<span class="igm-ampel__text">'
			. '<span class="igm-visually-hidden">Verfügbarkeit: </span>'
			. esc_html( $daten['label'] )
			. '</span></span>';
	}

	/** Dezenter Hinweis auf das Alter der Angabe. Gehört immer neben die Ampel. */
	public static function stand_hinweis( $daten ) {
		if ( ! is_array( $daten ) || empty( $daten['stand'] ) ) {
			return '';
		}
		return '<span class="igm-ampel__stand">Stand: ' . esc_html( $daten['stand'] ) . '</span>';
	}

	/** ---------- Nach einem Seminar-Import ---------- */

	/**
	 * Ein Import bringt neue oder geänderte Seminarnummern mit. Deren
	 * Verfügbarkeit steht noch in keiner Generation – also außerplanmäßigen
	 * Abgleich anfordern und den Taktgeber gleich anstoßen, statt bis zum
	 * nächsten regulären Tick zu warten.
	 *
	 * Bis der Lauf durch ist, bleibt die bisherige Generation sichtbar; neue
	 * Nummern zeigen so lange schlicht keine Ampel.
	 *
	 * @return string Satz für die Rückmeldung an die importierende Person.
	 */
	public static function nach_import() {
		if ( ! self::aktiv() ) {
			return '';
		}

		$laeuft = (bool) self::lauf();

		self::faellig_markieren();
		self::ensure_cron();

		// Nicht bis zum nächsten regulären Taktschlag warten. Liegt ohnehin einer
		// in den nächsten Minuten, lehnt WordPress den Zusatztermin ab – dann ist
		// er auch nicht nötig.
		wp_schedule_single_event( time() + 30, self::HOOK );

		return $laeuft
			? 'Der Abgleich der Verfügbarkeits-Ampel läuft bereits; die neuen Termine sind mit dem nächsten Lauf dabei.'
			: 'Der Abgleich der Verfügbarkeits-Ampel wurde angefordert. Er braucht rund eine Stunde – bis dahin zeigen die neuen Termine noch keine Ampel.';
	}

	/** ---------- Kennzahlen fürs Backend ---------- */

	/** Zahl der Termine in der gültigen Generation */
	public static function anzahl_live() {
		global $wpdb;
		$live = self::live_generation();
		if ( '' === $live ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . self::table() . ' WHERE run_id = %s', $live
		) );
	}

	/**
	 * Abdeckung: wie viele der eigenen Präsenz-Seminare finden ihre Nummer
	 * in der Ampel-Tabelle wieder? Das ist die Frühwarnung schlechthin – fällt
	 * der Wert nach einem Import oder einem Relaunch der Quelle ab, stimmt etwas
	 * mit der Zuordnung nicht.
	 *
	 * Gezählt werden nur veröffentlichte Seminare, deren Termin noch bevorsteht.
	 */
	public static function abdeckung() {
		global $wpdb;
		$live = self::live_generation();
		$heute = current_time( 'Y-m-d' );

		$eigene = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT( DISTINCT nr.meta_value )
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} nr ON nr.post_id = p.ID AND nr.meta_key = '_bi_seminarnummer' AND nr.meta_value <> ''
			   LEFT JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = '_bi_startdatum'
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND COALESCE( NULLIF( sd.meta_value, '' ), %s ) >= %s",
			BI_CPT,
			$heute,
			$heute
		) );

		if ( '' === $live || ! $eigene ) {
			return array( 'eigene' => $eigene, 'gefunden' => 0 );
		}

		$gefunden = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT( DISTINCT nr.meta_value )
			   FROM {$wpdb->posts} p
			   JOIN {$wpdb->postmeta} nr ON nr.post_id = p.ID AND nr.meta_key = '_bi_seminarnummer' AND nr.meta_value <> ''
			   LEFT JOIN {$wpdb->postmeta} sd ON sd.post_id = p.ID AND sd.meta_key = '_bi_startdatum'
			   JOIN " . self::table() . " a ON a.seminarnummer = nr.meta_value AND a.run_id = %s
			  WHERE p.post_type = %s AND p.post_status = 'publish'
			    AND COALESCE( NULLIF( sd.meta_value, '' ), %s ) >= %s",
			$live,
			BI_CPT,
			$heute,
			$heute
		) );

		return array( 'eigene' => $eigene, 'gefunden' => $gefunden );
	}

	/** Verteilung der Zustände in der gültigen Generation */
	public static function verteilung() {
		global $wpdb;
		$live = self::live_generation();
		if ( '' === $live ) {
			return array();
		}
		$zeilen = $wpdb->get_results( $wpdb->prepare(
			'SELECT ampel, label, COUNT(*) AS anzahl FROM ' . self::table()
			. ' WHERE run_id = %s GROUP BY ampel, label ORDER BY anzahl DESC',
			$live
		), ARRAY_A );
		return (array) $zeilen;
	}

	/** ---------- Backend ---------- */

	/**
	 * Hinweis im Backend, wenn der letzte Lauf schiefging oder die Quelle eine
	 * unbekannte Ampel-Klasse geliefert hat. Beides sind Frühwarnzeichen für
	 * einen Relaunch von igmetall.de.
	 */
	public static function notice() {
		if ( ! current_user_can( BI_CAP ) || ! self::aktiv() ) {
			return;
		}
		$p = self::protokoll();
		if ( empty( $p['status'] ) || 'ok' === $p['status'] ) {
			return;
		}

		$link = admin_url( 'admin.php?page=bi-einstellungen&tab=verfuegbarkeit' );

		if ( 'fehler' === $p['status'] || 'abgebrochen' === $p['status'] ) {
			printf(
				'<div class="notice notice-error"><p><strong>Verfügbarkeits-Ampel:</strong> %s <a href="%s">Zu den Einstellungen</a></p></div>',
				esc_html( (string) ( $p['text'] ?? 'Der letzte Abgleich ist fehlgeschlagen.' ) ),
				esc_url( $link )
			);
			return;
		}

		if ( ! empty( $p['unbekannt'] ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Verfügbarkeits-Ampel:</strong> '
				. 'Die Quelle liefert unbekannte Zustände (%s). Für die betroffenen Termine wird keine Ampel angezeigt. '
				. 'Das deutet auf eine Änderung an igmetall.de hin. <a href="%s">Zu den Einstellungen</a></p></div>',
				esc_html( implode( ', ', (array) $p['unbekannt'] ) ),
				esc_url( $link )
			);
		}
	}

	/** Abschnitt im Einstellungs-Tab „Verfügbarkeits-Ampel" */
	public static function render_section() {
		$lauf      = self::lauf();
		$protokoll = self::protokoll();
		$abdeckung = self::abdeckung();
		$naechster = wp_next_scheduled( self::HOOK );
		?>
		<h2 class="title">Verfügbarkeits-Ampel</h2>
		<p>Auf der Seminar-Detailseite erscheint neben dem Termin eine Ampel: <em>Verfügbar</em>,
		   <em>Fast ausgebucht</em> oder <em>Warteliste möglich</em>. Die Angabe stammt aus dem
		   Seminarverwaltungssystem des Vorstands und wird über die Seminarsuche auf
		   <code>igmetall.de</code> abgeglichen – verknüpft über die <strong>Seminarnummer</strong>.</p>

		<div class="notice notice-info inline" style="margin:12px 0;padding:10px 14px">
			<p style="margin:0"><strong>Beim Seitenaufruf wird nichts nachgeladen.</strong>
				Die Website liest ausschließlich die eigene Tabelle. Fehlt eine Nummer oder sind die
				Daten älter als die eingestellte Höchstfrist, erscheint <em>keine</em> Ampel – nie
				ersatzweise „verfügbar".</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_save_settings">
			<input type="hidden" name="bi_tab" value="verfuegbarkeit">
			<?php wp_nonce_field( 'bi_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Modul</th>
					<td>
						<label><input type="checkbox" name="ampel_aktiv" value="1" <?php checked( self::aktiv() ); ?>>
							Ampel anzeigen und regelmäßig abgleichen</label>
						<p class="description">Abgeschaltet verschwindet die Ampel von der Website und es
							werden keine Anfragen mehr an igmetall.de gestellt. <strong>Beim Abschalten werden
							außerdem alle Seminare wieder freigegeben, die die Ampel auf „ausgebucht" gesetzt
							hat</strong> – sonst bliebe eine unzuverlässige Quelle auch nach ihrem Abschalten
							im Bestand stehen. Von Hand oder aus einem Import gesetzte Haken bleiben.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bi_ampel_intervall">Abstand der Abgleiche</label></th>
					<td>
						<input type="number" min="1" max="168" step="1" id="bi_ampel_intervall" class="small-text"
							name="ampel_intervall" value="<?php echo esc_attr( self::intervall() ); ?>"> Stunden
						<p class="description">Einmal täglich (24) genügt. Seminartermine liegen Monate in der
							Zukunft; eine Ampel, die einen Tag alt ist, ist unkritisch.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bi_ampel_max_alter">Daten anzeigen bis zu einem Alter von</label></th>
					<td>
						<input type="number" min="1" max="720" step="1" id="bi_ampel_max_alter" class="small-text"
							name="ampel_max_alter" value="<?php echo esc_attr( self::max_alter() ); ?>"> Stunden
						<p class="description">Danach wird gar keine Ampel mehr angezeigt. Muss größer sein als der
							Abstand der Abgleiche – sonst verschwindet die Ampel regelmäßig zwischen zwei Läufen.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="bi_ampel_kontakt">Kontaktangabe für die Quelle</label></th>
					<td>
						<input type="text" class="regular-text" id="bi_ampel_kontakt" name="ampel_kontakt"
							value="<?php echo esc_attr( BI_Settings::get( 'ampel_kontakt' ) ); ?>"
							placeholder="bildung@igmetall.de">
						<p class="description">Steht im User-Agent jeder Anfrage, damit auf der Gegenseite
							erkennbar ist, wer da abfragt und wen man anschreiben kann.
							Leer: die Adresse dieser Website.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Einstellungen speichern' ); ?>
		</form>

		<h3>Derzeitiger Stand</h3>
		<table class="widefat striped" style="max-width:760px">
			<tbody>
				<tr>
					<th style="width:280px">Termine mit Ampel</th>
					<td><strong><?php echo (int) self::anzahl_live(); ?></strong></td>
				</tr>
				<tr>
					<th>Eigene Seminare abgedeckt</th>
					<td>
						<?php
						$e = (int) $abdeckung['eigene'];
						$g = (int) $abdeckung['gefunden'];
						printf( '<strong>%d von %d</strong>', $g, $e );
						if ( $e ) {
							printf( ' (%d&nbsp;%%)', (int) round( $g / $e * 100 ) );
						}
						?>
						<p class="description" style="margin:4px 0 0">Veröffentlichte Präsenz-Seminare, deren Termin
							noch bevorsteht. Ein deutlicher Einbruch nach einem Import heißt: die Seminarnummern
							passen nicht mehr zusammen.</p>
					</td>
				</tr>
				<tr>
					<th>Letzter Abgleich</th>
					<td><?php
						if ( empty( $protokoll['zeit'] ) ) {
							echo 'noch keiner';
						} else {
							echo esc_html( date_i18n( 'd.m.Y H:i', (int) $protokoll['zeit'] ) ) . ' – ';
							echo esc_html( (string) ( $protokoll['text'] ?? '' ) );
							if ( ! empty( $protokoll['dauer'] ) ) {
								printf( ' <span class="description">(Dauer: %d Min.)</span>', (int) ceil( $protokoll['dauer'] / 60 ) );
							}
						}
					?></td>
				</tr>
				<tr>
					<th>Laufender Abgleich</th>
					<td><?php
						if ( ! $lauf ) {
							echo self::faellig() ? 'steht an, startet mit dem nächsten Taktschlag' : 'keiner';
						} elseif ( 'sammeln' === $lauf['phase'] ) {
							echo 'sammelt gerade die Seminar-Kennungen ein';
						} else {
							printf(
								'%d von %d Seminarangeboten geholt, %d Termine geschrieben',
								(int) $lauf['gesamt'] - count( $lauf['queue'] ),
								(int) $lauf['gesamt'],
								(int) $lauf['zeilen']
							);
						}
					?></td>
				</tr>
				<tr>
					<th>Nächster Taktschlag</th>
					<td><?php
						echo $naechster
							? esc_html( date_i18n( 'd.m.Y H:i', $naechster ) )
							: '<strong>nicht eingeplant</strong> – bitte diese Seite neu laden';
					?>
						<p class="description" style="margin:4px 0 0">Der Takt läuft über WP-Cron und feuert damit nur
							bei Seitenaufrufen – auf einem Test- oder Redaktionssystem ohne Publikumsverkehr also
							kaum. Für den nächtlichen Abgleich gehört ein echter System-Cron auf
							<code>wp-cron.php</code> eingerichtet und <code>DISABLE_WP_CRON</code> in die
							<code>wp-config.php</code>. Von Hand gestartet treibt diese Seite den Lauf selbst voran
							und ist nicht auf den Cron angewiesen.</p>
					</td>
				</tr>
			</tbody>
		</table>

		<h3>Stichprobe: was steht auf der Detailseite?</h3>
		<p>Die nächsten anstehenden Präsenz-Seminare und das, was das Nachschlagen zu ihrer
		   Seminarnummer liefert. Steht hier überall dasselbe Hindernis, liegt es nicht am
		   einzelnen Seminar.</p>
		<table class="widefat striped" style="max-width:940px">
			<thead><tr><th style="width:130px">Nummer</th><th>Seminar</th><th style="width:340px">Ergebnis</th></tr></thead>
			<tbody>
				<?php
				$stichprobe = self::stichprobe( 10 );
				if ( ! $stichprobe ) :
					?>
					<tr><td colspan="3"><em>Es sind keine anstehenden Präsenz-Seminare veröffentlicht.</em></td></tr>
					<?php
				endif;
				foreach ( $stichprobe as $z ) :
					?>
					<tr>
						<td><code><?php echo esc_html( $z['nummer'] ?: '—' ); ?></code></td>
						<td><a href="<?php echo esc_url( get_permalink( $z['id'] ) ); ?>"><?php echo esc_html( $z['titel'] ); ?></a></td>
						<td><?php
							if ( $z['ergebnis']['ok'] ) {
								echo '<span style="color:#00733f">✓ ' . esc_html( $z['ergebnis']['text'] ) . '</span>';
							} else {
								echo '<span style="color:#b32d2e">keine Ampel: ' . esc_html( $z['ergebnis']['text'] ) . '</span>';
							}
						?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php $verteilung = self::verteilung(); ?>
		<?php if ( $verteilung ) : ?>
			<h3>Verteilung der Zustände</h3>
			<table class="widefat striped" style="max-width:760px">
				<thead><tr><th style="width:120px">Zustand</th><th>Beschriftung der Quelle</th><th style="width:100px">Termine</th></tr></thead>
				<tbody>
					<?php foreach ( $verteilung as $z ) : ?>
						<tr>
							<td><?php echo $z['ampel'] ? esc_html( $z['ampel'] ) : '<em>unbekannt</em>'; ?></td>
							<td><?php echo esc_html( $z['label'] ); ?></td>
							<td><?php echo (int) $z['anzahl']; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php // ---------- Von der Ampel gesetzte Ausbuchungen ---------- ?>
		<?php
		$von_ampel   = self::ausgebucht_von_ampel( 100 );
		$von_ampel_n = self::ausgebucht_von_ampel_anzahl();
		?>
		<h3>Von der Ampel gesetzte Ausbuchungen</h3>
		<p style="max-width:820px">Meldet die Quelle einen Termin als voll, setzt der Abgleich am Seminar den
		   Haken <em>Ausgebucht</em>. Ist die Quelle unzuverlässig, stehen diese Haken auch dann noch, wenn das
		   Modul längst abgeschaltet ist – denn ohne Abgleich nimmt sie niemand mehr zurück. Der Knopf hier
		   holt sie alle auf einmal heraus. <strong>Angefasst wird nur, was von der Ampel stammt</strong>;
		   von Hand oder aus einem Import gesetzte Haken bleiben stehen.</p>

		<?php if ( ! $von_ampel ) : ?>
			<p style="color:#646970">Zurzeit steht kein Seminar wegen der Ampel auf ausgebucht.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Seminar</th><th style="width:130px">Seminarnummer</th></tr></thead>
				<tbody>
				<?php foreach ( $von_ampel as $v ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $v['id'] ) ); ?>"><?php echo esc_html( $v['titel'] ); ?></a></td>
						<td><code><?php echo esc_html( $v['nummer'] ?: '—' ); ?></code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $von_ampel_n > count( $von_ampel ) ) : ?>
				<p class="description">… und <?php echo esc_html( number_format_i18n( $von_ampel_n - count( $von_ampel ) ) ); ?>
				   weitere. Der Knopf gibt alle <?php echo esc_html( number_format_i18n( $von_ampel_n ) ); ?> frei,
				   nicht nur die aufgeführten.</p>
			<?php endif; ?>
			<?php if ( self::aktiv() ) : ?>
				<p class="description" style="max-width:820px"><strong>Das Modul ist eingeschaltet.</strong>
				   Eine Freigabe hält deshalb nur bis zum nächsten Abgleich: Sagt die Quelle weiter „voll",
				   setzt er die Haken wieder. Ist die Quelle das Problem, gehört zuerst das Modul abgeschaltet –
				   das gibt die Seminare gleich mit frei.</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('<?php echo (int) $von_ampel_n; ?> Seminar(e) wieder freigeben? Der Haken Ausgebucht wird dort herausgenommen.');">
				<input type="hidden" name="action" value="bi_ampel_freigeben">
				<?php wp_nonce_field( 'bi_ampel_freigeben' ); ?>
				<?php submit_button( 'Alle wieder freigeben', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<?php // ---------- Handkorrekturen ---------- ?>
		<?php $hand = self::hand_korrekturen(); ?>
		<h3>Handkorrekturen am Haken „Ausgebucht"</h3>
		<p style="max-width:820px">Meldet die Quelle einen Termin falsch als voll und jemand nimmt den Haken
		   <em>Ausgebucht</em> hier von Hand heraus, <strong>setzt der nächste Abgleich ihn nicht wieder</strong>.
		   Die Korrektur hält so lange, wie die Quelle dasselbe sagt wie zuvor. Meldet sie etwas Neues, ist das
		   eine frische Nachricht und löst die Korrektur ab – sonst bliebe ein einmal freigegebenes Seminar
		   für immer buchbar, auch wenn es später wirklich voll ist. Auch der umgekehrte Fall gilt: ein von Hand
		   gesetzter Haken bleibt stehen.</p>

		<?php if ( ! $hand ) : ?>
			<p style="color:#646970">Zurzeit steht kein Haken gegen die Ampel.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th>Seminar</th>
					<th style="width:130px">Seminarnummer</th>
					<th style="width:150px">Haken steht auf</th>
					<th style="width:150px">Quelle sagt</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $hand as $h ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $h['id'] ) ); ?>"><?php echo esc_html( $h['titel'] ); ?></a></td>
						<td><code><?php echo esc_html( $h['nummer'] ); ?></code></td>
						<td><strong><?php echo $h['ausgebucht'] ? 'ausgebucht' : 'buchbar'; ?></strong> (von Hand)</td>
						<td><?php echo $h['ampel'] ? 'ausgebucht' : 'buchbar'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description" style="max-width:820px">Eine einzelne Korrektur nimmst du zurück, indem du den
			   Haken am Seminar wieder auf den Stand der Quelle setzt – dann sind sich beide einig und die Ampel
			   führt wieder. Der Knopf hier hebt <strong>alle</strong> auf einmal auf: Der nächste Abgleich
			   schreibt danach überall wieder ohne Rückfrage.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('Alle <?php echo (int) count( $hand ); ?> Handkorrekturen aufheben? Der nächste Abgleich überschreibt sie dann mit dem Stand der Quelle.');">
				<input type="hidden" name="action" value="bi_ampel_hand_loesen">
				<?php wp_nonce_field( 'bi_ampel_hand_loesen' ); ?>
				<?php submit_button( 'Alle Handkorrekturen aufheben', 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<h3>Abgleich von Hand</h3>
		<p>Ein vollständiger Lauf holt rund 230 Seminarangebote mit gut einer Sekunde Pause dazwischen –
		   das dauert etwa fünf Minuten. <strong>Diese Seite dabei offen lassen:</strong> sie treibt den
		   Lauf voran. Wird sie geschlossen, macht der Cron-Takt allein weiter, dann dauert es
		   entsprechend länger.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
			<input type="hidden" name="action" value="bi_ampel_run">
			<?php wp_nonce_field( 'bi_ampel_run' ); ?>
			<?php submit_button( 'Abgleich jetzt starten', 'primary', 'submit', false, $lauf ? array( 'disabled' => 'disabled' ) : array() ); ?>
		</form>
		<?php if ( $lauf ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:8px"
				onsubmit="return confirm('Laufenden Abgleich verwerfen? Die derzeit angezeigten Daten bleiben unverändert.');">
				<input type="hidden" name="action" value="bi_ampel_abbrechen">
				<?php wp_nonce_field( 'bi_ampel_abbrechen' ); ?>
				<?php submit_button( 'Laufenden Abgleich verwerfen', 'delete', 'submit', false ); ?>
			</form>

			<div id="bi-ampel-fortschritt" style="max-width:760px;margin-top:18px">
				<div style="background:#dcdcde;border-radius:4px;height:22px;overflow:hidden">
					<div id="bi-ampel-balken" style="background:#2271b1;height:100%;width:2%;transition:width .4s"></div>
				</div>
				<p id="bi-ampel-text" style="margin:8px 0 0" role="status">Der Abgleich läuft …</p>
			</div>

			<script>
			/* Ruft wiederholt ein Häppchen ab, bis der Lauf durch ist. Ein Aufruf
			   dauert bis zu 25 Sekunden – deshalb kein Intervall, sondern jeweils
			   erst nach der Antwort der nächste. */
			(function () {
				var balken = document.getElementById('bi-ampel-balken'),
				    text   = document.getElementById('bi-ampel-text'),
				    daten  = new URLSearchParams({ action: 'bi_ampel_schritt', _ajax_nonce: '<?php echo esc_js( wp_create_nonce( 'bi_ampel_schritt' ) ); ?>' });

				function schritt() {
					fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
						method: 'POST', credentials: 'same-origin', body: daten
					})
					.then(function (r) { return r.json(); })
					.then(function (a) {
						if (!a || !a.success) {
							text.textContent = 'Abbruch: ' + ((a && a.data && a.data.text) || 'unerwartete Antwort des Servers.');
							balken.style.background = '#d63638';
							return;
						}
						var d = a.data;
						balken.style.width = d.anteil + '%';
						text.textContent = d.text;
						if (d.fertig) {
							balken.style.background = d.fehler ? '#d63638' : '#00a32a';
							text.textContent = d.text + ' – Seite wird neu geladen …';
							setTimeout(function () { location.reload(); }, 1500);
							return;
						}
						schritt();
					})
					.catch(function (e) {
						text.textContent = 'Verbindung unterbrochen (' + e + '). Der Lauf steht still; Seite neu laden, um fortzufahren.';
						balken.style.background = '#dba617';
					});
				}
				schritt();
			})();
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Manueller Start aus den Einstellungen.
	 *
	 * Legt nur den Lauf an und springt zurück auf den Tab. Weiter treibt ihn die
	 * Fortschrittsanzeige dort (ajax_schritt) – so ist der Abgleich in wenigen
	 * Minuten durch, statt über Stunden am Cron-Takt zu hängen.
	 */
	public static function handle_run() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_ampel_run' );

		if ( ! self::aktiv() ) {
			self::redirect( 'Das Modul ist abgeschaltet – bitte zuerst einschalten.' );
		}

		self::ensure_cron();

		if ( BI_Ampel_Crawler::start( 'manuell' ) ) {
			self::redirect( 'Abgleich gestartet. Lass diese Seite offen, bis der Balken durch ist.' );
		}
		self::redirect( 'Es läuft bereits ein Abgleich.' );
	}

	/**
	 * Ein Häppchen abarbeiten, angestoßen von der geöffneten Einstellungsseite.
	 *
	 * Der Grund für diesen Weg: WP-Cron feuert nur bei Seitenaufrufen. Auf einem
	 * Test- oder Redaktionssystem ohne Publikumsverkehr käme ein Lauf sonst nie
	 * über den ersten Schritt hinaus. Wer den Tab offen lässt, treibt ihn hier
	 * selbst voran; der nächtliche Lauf über den Cron bleibt davon unberührt.
	 */
	public static function ajax_schritt() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_send_json_error( array( 'text' => 'Keine Berechtigung.' ), 403 );
		}
		check_ajax_referer( 'bi_ampel_schritt' );

		@set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$text = BI_Ampel_Crawler::schritt();
		$lauf = self::lauf();

		if ( ! $lauf ) {
			$protokoll = self::protokoll();
			wp_send_json_success( array(
				'fertig'  => true,
				'fehler'  => in_array( ( $protokoll['status'] ?? '' ), array( 'fehler', 'abgebrochen' ), true ),
				'text'    => (string) ( $protokoll['text'] ?? $text ),
				'anteil'  => 100,
			) );
		}

		$gesamt = (int) $lauf['gesamt'];
		$offen  = count( $lauf['queue'] );
		$anteil = ( 'holen' === $lauf['phase'] && $gesamt > 0 )
			? (int) round( ( $gesamt - $offen ) / $gesamt * 100 )
			: 2;

		wp_send_json_success( array(
			'fertig' => false,
			'fehler' => false,
			'text'   => $text,
			'anteil' => max( 2, min( 99, $anteil ) ),
		) );
	}

	/** Laufenden Lauf verwerfen; die live geschaltete Generation bleibt unangetastet. */
	/**
	 * Alle Handkorrekturen aufheben: Die Notizen werden vergessen, der nächste
	 * Abgleich schreibt danach wieder ohne Rückfrage.
	 *
	 * Gelöscht wird die NOTIZ, nicht der Haken. Der Stand am Seminar bleibt also,
	 * wie er ist, bis die Quelle das nächste Mal etwas anderes sagt – ein Knopf,
	 * der sofort hundert Haken umlegt, wäre eine Überraschung zu viel.
	 */
	public static function handle_hand_loesen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_ampel_hand_loesen' );

		$n = 0;
		foreach ( self::hand_korrekturen( 1000 ) as $h ) {
			self::hand_zuruecksetzen( $h['id'] );
			$n++;
		}

		self::redirect( $n
			? sprintf( '%s Handkorrektur(en) aufgehoben. Der nächste Abgleich schreibt dort wieder den Stand der Quelle.', number_format_i18n( $n ) )
			: 'Es stand keine Handkorrektur gegen die Ampel.' );
	}

	/**
	 * Alle von der Ampel gesetzten Ausbuchungen von Hand zurücknehmen.
	 *
	 * Denselben Weg geht das Abschalten des Moduls automatisch (siehe
	 * BI_Settings::save). Der Knopf ist für den Fall danach: abgeschaltet wurde
	 * schon, die Haken stehen noch.
	 */
	public static function handle_freigeben() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_ampel_freigeben' );

		$n = self::ausgebucht_freigeben();

		self::redirect( $n
			? sprintf( '%s Seminar(e) wieder freigegeben – der Haken „Ausgebucht" stammte dort von der Ampel.', number_format_i18n( $n ) )
			: 'Kein Seminar steht wegen der Ampel auf ausgebucht.' );
	}

	public static function handle_abbrechen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_ampel_abbrechen' );

		$lauf = self::lauf();
		if ( $lauf ) {
			self::generation_loeschen( $lauf['run_id'] );
			self::lauf_loeschen();
			self::redirect( 'Der laufende Abgleich wurde verworfen. Die angezeigten Daten sind unverändert.' );
		}
		self::redirect( 'Es lief kein Abgleich.' );
	}

	private static function redirect( $msg ) {
		wp_safe_redirect( add_query_arg( array(
			'page'   => 'bi-einstellungen',
			'tab'    => 'verfuegbarkeit',
			'bi_msg' => rawurlencode( $msg ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
