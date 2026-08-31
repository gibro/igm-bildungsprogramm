<?php
/**
 * Crawler für die Verfügbarkeits-Ampel.
 *
 * Holt die Ampelzustände von modules.igmetall.de und schreibt sie in die
 * Tabelle wp_bi_ampel. Die Website selbst fragt dort NIE live an – sie liest
 * nur die lokale Tabelle (siehe class-bi-ampel.php).
 *
 * ── Warum ein Crawler und kein Direktabruf ───────────────────────────────────
 * Die Seminarnummer ist bei der Quelle nicht adressierbar: es gibt keinen
 * Endpunkt „?snr=O00026442" (am 11.08.2026 gegen ?sid=, ?snr=, ?nr=,
 * ?seminarnr= und ?seminarnummer= geprüft – alle liefern eine leere Seite).
 * Adressierbar ist nur das Seminar-ANGEBOT über seine sid; ein Angebot enthält
 * 1..n Termine, und jeder Termin hat eigene Nummer, eigenen Ort und eigene
 * Ampel. Die Zuordnung Seminarnummer -> sid ist also n:1 und muss lokal
 * vorgehalten werden.
 *
 * ── Ablauf eines Laufs ───────────────────────────────────────────────────────
 *   1. sammeln : Trefferliste der erweiterten Seminarsuche holen -> sids
 *   2. holen   : je sid das Fragment holen, Termintabelle parsen, Zeilen
 *                unter der laufenden Lauf-Nummer (run_id) einfügen
 *   3. umschalten: run_id wird zur „lebenden" Generation, alte Zeilen fallen weg
 *
 * Schritt 3 ist der Grund für die run_id-Spalte: bis der Lauf komplett ist,
 * bleibt die alte Generation unverändert sichtbar. Ein abgebrochener Lauf
 * hinterlässt keine halbleere Tabelle, sondern gar nichts.
 *
 * ── Warum in Häppchen ────────────────────────────────────────────────────────
 * Rund 230 Angebote mit je 1–2 Sekunden Pause sind ein Vielfaches jedes
 * PHP-Zeitlimits. Deshalb arbeitet jeder Cron-Tick nur ein Stück ab
 * (Zeit- und Anfragebudget) und merkt sich den Rest im Lauf-Zustand.
 *
 * ── Rücksicht auf die Quelle ─────────────────────────────────────────────────
 * Ein User-Agent mit Zweck und Kontaktadresse, keine Parallelität, Pause
 * zwischen den Anfragen, Timeout und ein Wiederholungsversuch mit Wartezeit.
 * Beide angefragten Pfade sind in der robots.txt von modules.igmetall.de
 * ausdrücklich nicht gesperrt (geprüft 11.08.2026).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Ampel_Crawler {

	/** Trefferliste (liefert die sids bereits als fertige Links – kein Slug-Umweg nötig) */
	const LISTE = 'https://modules.igmetall.de/modules/service/bildung-und-seminare/erweiterte-seminarsuche-iframe';

	/** Detail-Fragment eines Angebots */
	const DETAIL = 'https://modules.igmetall.de/modules/service/bildung-und-seminare/seminardetails-iframe';

	/** Treffer je Listenseite. 250 lieferte im Test den vollständigen Katalog in einer Anfrage. */
	const SEITENGROESSE = 250;

	/** Sicherheitsnetz gegen eine Trefferliste, die nie leer wird */
	const MAX_SEITEN = 20;

	/** Budget je Cron-Tick */
	const MAX_ANFRAGEN = 20;
	const MAX_SEKUNDEN = 25;

	/** Pause zwischen zwei Anfragen in Millisekunden (Rücksicht auf die Quelle) */
	const PAUSE_MS = 1200;

	/** Ein Lauf, der so lange hängt, gilt als abgestürzt und wird verworfen (Sekunden) */
	const LAUF_TIMEOUT = 6 * HOUR_IN_SECONDS;

	/** ---------- Lauf starten und weiterführen ---------- */

	/**
	 * Neuen Lauf anlegen. Ein bereits laufender Lauf wird nicht angetastet.
	 *
	 * @param string $grund 'plan' | 'manuell' | 'import'
	 * @return bool true, wenn ein Lauf gestartet wurde.
	 */
	public static function start( $grund = 'plan' ) {
		if ( BI_Ampel::lauf() ) {
			return false;
		}

		// Die Anforderung „außerplanmäßig abgleichen" ist mit dem Start erfüllt.
		// Würde sie erst beim erfolgreichen Abschluss zurückgesetzt, liefe nach
		// einem gescheiterten Lauf alle fünf Minuten der nächste an.
		BI_Ampel::faellig_zuruecksetzen();

		BI_Ampel::lauf_setzen( array(
			// Kennung der Generation. Der Zufallsanteil ist nicht kosmetisch: zwei
			// Läufe in derselben Sekunde – etwa Abbrechen und sofort neu starten –
			// bekämen sonst dieselbe Kennung und der neue Lauf schriebe mitten in
			// die noch angezeigte Generation hinein.
			'run_id'    => time() . '-' . mt_rand( 100000, 999999 ),
			'phase'     => 'sammeln',
			'queue'     => array(),
			'gesamt'    => 0,
			'zeilen'    => 0,
			'fehler'    => 0,
			'unbekannt' => array(),
			'seite'     => 1,
			'gestartet' => time(),
			'grund'     => in_array( $grund, array( 'plan', 'manuell', 'import' ), true ) ? $grund : 'plan',
		) );
		return true;
	}

	/**
	 * Ein Stück des laufenden Laufs abarbeiten. Wird vom Cron-Tick gerufen.
	 *
	 * @return string Kurzbericht für Protokoll/Anzeige.
	 */
	public static function schritt() {
		$lauf = BI_Ampel::lauf();
		if ( ! $lauf ) {
			return 'kein Lauf aktiv';
		}

		// Hängengebliebenen Lauf aufräumen, statt ihn ewig weiterzuschleppen.
		if ( time() - (int) $lauf['gestartet'] > self::LAUF_TIMEOUT ) {
			BI_Ampel::generation_loeschen( $lauf['run_id'] );
			BI_Ampel::lauf_loeschen();
			BI_Ampel::protokoll_schreiben( array(
				'zeit'   => time(),
				'status' => 'abgebrochen',
				'text'   => 'Lauf lief länger als ' . ( self::LAUF_TIMEOUT / HOUR_IN_SECONDS ) . ' Stunden und wurde verworfen.',
				'grund'  => $lauf['grund'],
			) );
			return 'Lauf abgebrochen (Zeitüberschreitung)';
		}

		// Zwei gleichzeitige Ticks dürfen nicht dieselben sids doppelt holen.
		$sperre = 'bi_ampel_sperre';
		if ( get_transient( $sperre ) ) {
			return 'übersprungen (anderer Durchlauf aktiv)';
		}
		set_transient( $sperre, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$bericht = ( 'sammeln' === $lauf['phase'] ) ? self::sammeln( $lauf ) : self::holen( $lauf );
		} finally {
			delete_transient( $sperre );
		}

		return $bericht;
	}

	/** Phase 1: sids aus der Trefferliste einsammeln */
	private static function sammeln( array $lauf ) {
		$start    = time();
		$anfragen = 0;
		$budget   = false; // true = Tick vorbei, aber die Liste ist noch nicht durch

		while ( $lauf['seite'] <= self::MAX_SEITEN ) {
			if ( $anfragen >= self::MAX_ANFRAGEN || ( time() - $start ) >= self::MAX_SEKUNDEN ) {
				$budget = true;
				break;
			}

			$html = self::abrufen( add_query_arg( array(
				'qsem' => '',
				'p'    => (int) $lauf['seite'],
				's'    => self::SEITENGROESSE,
				'es'   => 1,
				'from' => '',
				'to'   => '',
				'rel'  => '',
				'cat'  => '',
				'stg'  => '',
			), self::LISTE ) );
			$anfragen++;

			if ( null === $html ) {
				$lauf['fehler']++;
				$lauf['seite']++;
				continue;
			}

			$neu = self::sids_aus_liste( $html );
			$vor = count( $lauf['queue'] );
			$lauf['queue'] = array_values( array_unique( array_merge( $lauf['queue'], $neu ) ) );

			// Keine neuen sids mehr -> Katalog ist durch.
			if ( count( $lauf['queue'] ) === $vor ) {
				break;
			}
			$lauf['seite']++;
		}

		// Budget alle, Liste noch nicht durch: Zwischenstand sichern und in der
		// Sammelphase bleiben. Ohne das ginge der Lauf mit einem Bruchteil der
		// Angebote weiter und die neue Generation wäre unvollständig.
		if ( $budget ) {
			BI_Ampel::lauf_setzen( $lauf );
			return sprintf( 'sammelt weiter, bisher %d Seminarangebote', count( $lauf['queue'] ) );
		}

		// Nichts gefunden? Dann stimmt an der Quelle etwas nicht – Lauf verwerfen,
		// bevor er die gepflegte Generation gegen eine leere austauscht.
		if ( empty( $lauf['queue'] ) ) {
			BI_Ampel::lauf_loeschen();
			BI_Ampel::protokoll_schreiben( array(
				'zeit'   => time(),
				'status' => 'fehler',
				'text'   => sprintf(
					'Die Trefferliste lieferte keine einzige Seminar-Kennung (%d Abrufe fehlgeschlagen). '
					. 'Die bisherigen Daten bleiben unverändert stehen.',
					(int) $lauf['fehler']
				),
				'fehler' => (int) $lauf['fehler'],
				'grund'  => $lauf['grund'],
			) );
			return 'Abbruch: Trefferliste leer';
		}

		$lauf['gesamt'] = count( $lauf['queue'] );
		$lauf['phase']  = 'holen';
		BI_Ampel::lauf_setzen( $lauf );

		return sprintf( '%d Seminarangebote gefunden', $lauf['gesamt'] );
	}

	/** Phase 2: Fragmente holen und Termine schreiben */
	private static function holen( array $lauf ) {
		$start    = time();
		$anfragen = 0;
		$zeilen   = 0;

		while ( ! empty( $lauf['queue'] ) ) {
			if ( $anfragen >= self::MAX_ANFRAGEN || ( time() - $start ) >= self::MAX_SEKUNDEN ) {
				break;
			}

			$sid  = array_shift( $lauf['queue'] );
			$html = self::abrufen( add_query_arg( 'sid', $sid, self::DETAIL ) );
			$anfragen++;

			if ( null === $html ) {
				$lauf['fehler']++;
				continue;
			}

			$ergebnis = BI_Ampel_Parser::parse( $html );
			if ( ! empty( $ergebnis['unbekannt'] ) ) {
				$lauf['unbekannt'] = array_values( array_unique(
					array_merge( $lauf['unbekannt'], $ergebnis['unbekannt'] )
				) );
			}

			foreach ( $ergebnis['termine'] as $termin ) {
				$termin['sid']   = $sid;
				$termin['titel'] = $ergebnis['titel'];
				if ( BI_Ampel::zeile_schreiben( $lauf['run_id'], $termin ) ) {
					$zeilen++;
				}
			}
		}

		$lauf['zeilen'] += $zeilen;

		if ( ! empty( $lauf['queue'] ) ) {
			BI_Ampel::lauf_setzen( $lauf );
			return sprintf( '%d Angebote geholt, %d offen', $anfragen, count( $lauf['queue'] ) );
		}

		return self::abschliessen( $lauf );
	}

	/** Phase 3: neue Generation live schalten, alte entfernen */
	private static function abschliessen( array $lauf ) {
		// Eine Generation ohne Termine darf die alte nicht ablösen.
		if ( $lauf['zeilen'] < 1 ) {
			BI_Ampel::generation_loeschen( $lauf['run_id'] );
			BI_Ampel::lauf_loeschen();
			BI_Ampel::protokoll_schreiben( array(
				'zeit'   => time(),
				'status' => 'fehler',
				'text'   => 'Der Lauf hat keinen einzigen Termin geliefert. Die bisherigen Daten bleiben unverändert stehen.',
				'grund'  => $lauf['grund'],
			) );
			return 'Abbruch: keine Termine';
		}

		BI_Ampel::generation_live_schalten( $lauf['run_id'] );
		BI_Ampel::lauf_loeschen();

		// Erst jetzt, mit der gültigen Generation: „Warteliste möglich" heißt
		// ausgebucht, „Verfügbar"/„Fast ausgebucht" heißt wieder frei.
		$bilanz = BI_Ampel::ausgebucht_abgleichen();

		BI_Ampel::protokoll_schreiben( array(
			'zeit'      => time(),
			'status'    => empty( $lauf['unbekannt'] ) ? 'ok' : 'warnung',
			'text'      => sprintf(
				'%d Termine aus %d Seminarangeboten übernommen%s.%s',
				(int) $lauf['zeilen'],
				(int) $lauf['gesamt'],
				$lauf['fehler'] ? sprintf( ', %d Abrufe fehlgeschlagen', (int) $lauf['fehler'] ) : '',
				self::bilanz_text( $bilanz )
			),
			'ausgebucht' => (int) $bilanz['gesetzt'],
			'freigegeben' => (int) $bilanz['geloest'],
			'respektiert' => (int) ( $bilanz['respektiert'] ?? 0 ),
			'zeilen'    => (int) $lauf['zeilen'],
			'angebote'  => (int) $lauf['gesamt'],
			'fehler'    => (int) $lauf['fehler'],
			'dauer'     => time() - (int) $lauf['gestartet'],
			'unbekannt' => $lauf['unbekannt'],
			'grund'     => $lauf['grund'],
		) );

		return sprintf( 'Lauf beendet: %d Termine', (int) $lauf['zeilen'] );
	}

	/**
	 * Satzteil fürs Protokoll: was der Lauf am Haken „Ausgebucht" geändert hat.
	 * Nichts geändert – nichts geschrieben; eine Null wäre nur Rauschen.
	 */
	private static function bilanz_text( array $bilanz ) {
		$teile = array();
		if ( ! empty( $bilanz['gesetzt'] ) ) {
			$teile[] = sprintf( '%d auf „ausgebucht" gesetzt', (int) $bilanz['gesetzt'] );
		}
		if ( ! empty( $bilanz['geloest'] ) ) {
			$teile[] = sprintf( '%d wieder freigegeben', (int) $bilanz['geloest'] );
		}
		// Die respektierten Handkorrekturen gehören ins Protokoll, gerade weil
		// nichts geschehen ist: Sonst wundert sich später jemand, warum ein
		// Seminar buchbar bleibt, das die Quelle als voll meldet.
		if ( ! empty( $bilanz['respektiert'] ) ) {
			$teile[] = sprintf( '%d Handkorrektur(en) unangetastet gelassen', (int) $bilanz['respektiert'] );
		}
		return $teile ? ' ' . ucfirst( implode( ', ', $teile ) ) . '.' : '';
	}

	/** ---------- HTTP ---------- */

	/**
	 * Eine Seite holen. Gibt den Rumpf zurück oder null bei Misserfolg.
	 * Ein Wiederholungsversuch mit Wartezeit, dann Aufgabe – der nächste
	 * nächtliche Lauf versucht es ohnehin erneut.
	 */
	private static function abrufen( $url ) {
		$versuche = 2;
		for ( $i = 1; $i <= $versuche; $i++ ) {
			$antwort = wp_remote_get( $url, array(
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => self::user_agent(),
				'headers'     => array( 'Accept' => 'text/html' ),
			) );

			if ( ! is_wp_error( $antwort ) && 200 === (int) wp_remote_retrieve_response_code( $antwort ) ) {
				self::pause();
				return (string) wp_remote_retrieve_body( $antwort );
			}

			if ( $i < $versuche ) {
				sleep( 3 ); // kurzer Rückzug, dann ein zweiter Versuch
			}
		}

		self::pause();
		return null;
	}

	/**
	 * Pause nach jeder Anfrage. Über den Filter `bi_ampel_pause_ms` lässt sie sich
	 * verlängern, falls die Gegenseite empfindlicher reagiert als erwartet – und
	 * für Tests auf 0 setzen.
	 */
	private static function pause() {
		$ms = (int) apply_filters( 'bi_ampel_pause_ms', self::PAUSE_MS );
		if ( $ms > 0 ) {
			usleep( $ms * 1000 );
		}
	}

	/**
	 * User-Agent mit Zweck und Kontaktadresse. Wer auf der Gegenseite ins
	 * Logfile schaut, soll sehen, wer da anfragt und wen er anschreiben kann.
	 */
	public static function user_agent() {
		$kontakt = trim( (string) BI_Settings::get( 'ampel_kontakt' ) );
		if ( '' === $kontakt ) {
			$kontakt = home_url( '/' );
		}
		return sprintf(
			'IGM-Bildungsprogramm-Verfuegbarkeit/%s (Abgleich der Seminar-Verfuegbarkeit; Kontakt: %s)',
			BI_VERSION,
			$kontakt
		);
	}

	/**
	 * sids aus einer Trefferliste ziehen.
	 *
	 * Die Liste verlinkt direkt auf „…/seminardetails?sid=<UUID>" – der im
	 * Handoff beschriebene Umweg über sprechende Slugs und deren Redirect
	 * entfällt damit vollständig.
	 */
	public static function sids_aus_liste( $html ) {
		if ( ! preg_match_all( '/sid=([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/', (string) $html, $treffer ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'strtolower', $treffer[1] ) ) );
	}
}
