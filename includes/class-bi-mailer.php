<?php
/**
 * Mail-Trigger-Engine.
 *
 * Trigger werden in der Option bi_mail_triggers als Array gespeichert. Jeder Trigger:
 *   id             Fortlaufende, unveränderliche Nummer (Zeilenschlüssel der Listentabelle,
 *                  Gruppierungsschlüssel der Warteschlange). Altbestände ohne id bekommen
 *                  beim ersten Lesen eine zugewiesen, siehe get_triggers().
 *   active         bool
 *   name           Bezeichnung (intern)
 *   type           geschaeftsstelle | teilnehmer | bildungszentrum | ansprechpartner | custom
 *   recipient      E-Mail (nur type=custom)
 *   from           Absender ("Name <mail>")
 *   subject        Betreff (mit Platzhaltern)
 *   body           Text (mit Platzhaltern)
 *   cond_tax       Bedingung: Taxonomie-Slug (optional)
 *   cond_value     Bedingung: Term-Name (optional)
 *   cond_op        Bedingung: 'is' = Seminar muss den Wert haben, 'not' = darf ihn nicht haben
 *   schedule       'instant' = sofort eine Mail je Anmeldung (Standard)
 *                  'weekly'  = Anmeldung wird gesammelt und einmal pro Woche als
 *                              Zusammenfassung verschickt
 *   digest_subject Betreff der Wochenzusammenfassung (nur schedule=weekly)
 *   digest_intro   Einleitungstext der Wochenzusammenfassung (nur schedule=weekly)
 *
 * Wöchentlicher Versand:
 *   Trigger mit schedule=weekly senden bei der Anmeldung nichts, sondern legen einen
 *   fertig gerenderten Eintrag in der Warteschlange (Option bi_mail_queue) ab. Der
 *   WP-Cron-Job bi_mail_weekly_digest (Wochentag/Uhrzeit in Option bi_mail_schedule)
 *   fasst die Warteschlange je Benachrichtigung und Empfänger zu einer Mail zusammen.
 *   Weil Betreff/Text schon beim Eintreffen der Anmeldung gerendert werden, bleiben
 *   bereits eingereihte Einträge auch dann korrekt, wenn der Trigger später geändert wird.
 *
 * Admin-Oberfläche (Seite „Mail-Benachrichtigungen"):
 *   Übersicht  – Hero mit den drei seitenweiten Einstellungen (Wöchentlicher Versand,
 *                Test-Versand, Darstellung), darunter der Konsistenz-Check und die
 *                Listentabelle aller Benachrichtigungen (BI_Mail_Table).
 *   Bearbeiten – eigene Maske je Benachrichtigung, erreichbar über
 *                admin.php?page=bi-mail-trigger&action=edit&id=…
 *
 * Platzhalter siehe BI_Mailer::placeholders() (Einzelmail) bzw.
 * BI_Mailer::digest_placeholders() (zusätzlich in der Zusammenfassung).
 *
 * Rich Text:
 *   Betreff und Texte werden als reiner Text mit einfachen Steuerzeichen gepflegt
 *   (**fett**, _kursiv_, # Überschrift, - Liste, [Text](url), --- als Trennlinie).
 *   Daraus entstehen beim Versand zwei Fassungen: eine gestaltete HTML-Mail und
 *   eine Klartext-Fassung ohne Steuerzeichen. Beide gehen als multipart/alternative
 *   raus (HTML als Body, Klartext als AltBody) – Programme ohne HTML-Darstellung
 *   zeigen automatisch den Text. Ist die Option bi_mail_format auf 'plain'
 *   gesetzt, wird ausschließlich die Klartext-Fassung verschickt.
 *   Gespeichert wird immer nur der Quelltext mit Steuerzeichen, nie HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Mailer {

	const OPTION        = 'bi_mail_triggers';
	const SEQ_OPTION    = 'bi_mail_trigger_seq';
	const TEST_OPTION   = 'bi_mail_test';
	const QUEUE_OPTION  = 'bi_mail_queue';
	const SCHED_OPTION  = 'bi_mail_schedule';
	const FORMAT_OPTION = 'bi_mail_format';
	const CRON_HOOK     = 'bi_mail_weekly_digest';
	const CRON_SCHED    = 'bi_weekly';
	const PAGE          = 'bi-mail-trigger';

	/** Plural der Listentabelle – bestimmt auch den Nonce ihrer Sammelaktionen */
	const TABLE_PLURAL = 'benachrichtigungen';

	/** Klartext-Fassung der gerade versendeten Mail (siehe apply_alt_body()) */
	private static $alt_body = '';

	public static function init() {
		add_action( 'admin_post_bi_save_trigger', array( __CLASS__, 'save_trigger' ) );
		add_action( 'admin_post_bi_save_format', array( __CLASS__, 'save_format' ) );
		add_action( 'admin_post_bi_save_test', array( __CLASS__, 'save_test' ) );
		add_action( 'admin_post_bi_save_schedule', array( __CLASS__, 'save_schedule' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );

		// Zeilen- und Sammelaktionen der Listentabelle laufen über die Seiten-URL und
		// müssen vor jeder Ausgabe abgearbeitet werden – sonst ist kein Redirect möglich.
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );

		// Klartext-Fassung als AltBody -> PHPMailer baut daraus multipart/alternative
		add_action( 'phpmailer_init', array( __CLASS__, 'apply_alt_body' ) );

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_weekly' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );
	}

	/** Editor-Assets nur auf der Seite „Mail-Benachrichtigungen" */
	public static function admin_assets() {
		if ( ! self::is_page() ) {
			return;
		}
		wp_enqueue_style( 'bi-mail-editor', BI_URL . 'assets/css/mail-editor.css', array(), BI_VERSION );
		wp_enqueue_script( 'bi-mail-editor', BI_URL . 'assets/js/mail-editor.js', array(), BI_VERSION, true );
	}

	/** Läuft gerade die Seite „Mail-Benachrichtigungen"? */
	private static function is_page() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
		return self::PAGE === $page;
	}

	/** Test-Modus-Einstellungen */
	public static function get_test() {
		$t = get_option( self::TEST_OPTION, array() );
		return wp_parse_args( is_array( $t ) ? $t : array(), array( 'enabled' => 0, 'address' => '' ) );
	}

	/** ---------- Benachrichtigungen lesen und schreiben ---------- */

	/**
	 * Alle Benachrichtigungen – jede garantiert mit stabiler ID.
	 *
	 * Vor der Umstellung auf die Listentabelle war der Array-Index der Schlüssel; der
	 * verschiebt sich beim Löschen. Altbestände bekommen hier einmalig eine feste ID
	 * und werden gleich zurückgeschrieben.
	 */
	public static function get_triggers() {
		$t = get_option( self::OPTION, array() );
		if ( ! is_array( $t ) ) {
			return array();
		}

		$missing = false;
		foreach ( $t as $row ) {
			if ( empty( $row['id'] ) ) {
				$missing = true;
				break;
			}
		}
		if ( ! $missing ) {
			return array_values( $t );
		}

		$next = (int) get_option( self::SEQ_OPTION, 0 );
		foreach ( $t as $row ) {
			$next = max( $next, (int) ( $row['id'] ?? 0 ) );
		}
		foreach ( $t as $i => $row ) {
			if ( empty( $row['id'] ) ) {
				$t[ $i ]['id'] = ++$next;
			}
		}
		$t = array_values( $t );

		update_option( self::SEQ_OPTION, $next );
		update_option( self::OPTION, $t );
		return $t;
	}

	/** Eine Benachrichtigung anhand ihrer ID; null, wenn es sie nicht (mehr) gibt. */
	public static function get_trigger( $id ) {
		$id = (int) $id;
		foreach ( self::get_triggers() as $t ) {
			if ( (int) $t['id'] === $id ) {
				return $t;
			}
		}
		return null;
	}

	/** Nächste freie ID (fortlaufend, wird nie wiederverwendet) */
	private static function next_id() {
		$next = (int) get_option( self::SEQ_OPTION, 0 );
		foreach ( self::get_triggers() as $t ) {
			$next = max( $next, (int) $t['id'] );
		}
		$next++;
		update_option( self::SEQ_OPTION, $next );
		return $next;
	}

	/**
	 * Benachrichtigung anlegen oder aktualisieren.
	 *
	 * @param array $data Vollständiger, bereits bereinigter Datensatz. Ohne gültige
	 *                    'id' (bzw. mit einer unbekannten) wird neu angelegt.
	 * @return int ID des gespeicherten Datensatzes.
	 */
	private static function put_trigger( $data ) {
		$all   = self::get_triggers();
		$id    = (int) ( $data['id'] ?? 0 );
		$found = false;

		foreach ( $all as $i => $t ) {
			if ( $id && (int) $t['id'] === $id ) {
				$all[ $i ] = $data;
				$found     = true;
				break;
			}
		}
		if ( ! $found ) {
			$id          = self::next_id();
			$data['id']  = $id;
			$all[]       = $data;
		}

		update_option( self::OPTION, array_values( $all ) );
		return $id;
	}

	/** @return bool true, wenn es die Benachrichtigung gab. */
	private static function delete_trigger( $id ) {
		$id   = (int) $id;
		$all  = self::get_triggers();
		$rest = array();
		$hit  = false;

		foreach ( $all as $t ) {
			if ( (int) $t['id'] === $id ) {
				$hit = true;
				continue;
			}
			$rest[] = $t;
		}
		if ( $hit ) {
			update_option( self::OPTION, $rest );
		}
		return $hit;
	}

	/** Kopie einer Benachrichtigung – immer inaktiv, damit nichts versehentlich doppelt rausgeht. */
	private static function duplicate_trigger( $id ) {
		$t = self::get_trigger( $id );
		if ( ! $t ) {
			return 0;
		}
		$t['id']     = 0;
		$t['active'] = 0;
		$t['name']   = trim( ( $t['name'] ?? '' ) . ' (Kopie)' );
		return self::put_trigger( $t );
	}

	/** @return bool true, wenn der Status geändert wurde. */
	private static function set_active( $id, $active ) {
		$t = self::get_trigger( $id );
		if ( ! $t ) {
			return false;
		}
		$t['active'] = $active ? 1 : 0;
		self::put_trigger( $t );
		return true;
	}

	/** Standard-Trigger bei Aktivierung (nur wenn noch keine existieren) */
	public static function seed_default_triggers() {
		if ( get_option( self::OPTION, null ) !== null ) {
			return;
		}
		$default_from = get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>';
		update_option( self::SEQ_OPTION, 3 );
		update_option( self::OPTION, array(
			array(
				'id'      => 1,
				'active'  => 1,
				'name'    => 'Benachrichtigung an Geschäftsstelle',
				'type'    => 'geschaeftsstelle',
				'recipient' => '',
				'from'    => $default_from,
				'subject' => 'Neue Seminar-Anmeldung aus Ihrem Gebiet ({plz})',
				'body'    => "Hallo {geschaeftsstelle},\n\nüber das Bildungsprogramm ist eine neue Anmeldung aus Ihrem Gebiet eingegangen.\n\n## Seminar\n\n- **{seminar_titel}**\n- Nummer: {seminar_nummer}\n- Start: {seminar_startdatum}\n\n## Anmeldung\n\n- **{name}**\n- Betrieb: {betrieb}, PLZ {plz}\n- E-Mail: {email}\n- Telefon: {telefon}\n\n**Nachricht:**\n{nachricht}\n\n---\n\n_automatisch erzeugt_",
				'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
				'schedule' => 'instant', 'digest_subject' => '', 'digest_intro' => '',
			),
			array(
				'id'      => 2,
				'active'  => 1,
				'name'    => 'Bestätigung an Teilnehmer',
				'type'    => 'teilnehmer',
				'recipient' => '',
				'from'    => $default_from,
				'subject' => 'Ihre Anmeldung zu „{seminar_titel}“',
				'body'    => "Hallo {vorname} {nachname},\n\nvielen Dank für Ihre Anmeldung zum Seminar **{seminar_titel}**.\n\n- Start: {seminar_startdatum}\n- Ort: {seminar_ort}\n\nIhre zuständige Geschäftsstelle ({geschaeftsstelle}) wird sich bei Ihnen melden.\n\nViele Grüße\nIhr Bildungsteam",
				'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
				'schedule' => 'instant', 'digest_subject' => '', 'digest_intro' => '',
			),
			array(
				'id'      => 3,
				'active'  => 1,
				'name'    => 'Benachrichtigung an Ansprechpartner',
				'type'    => 'ansprechpartner',
				'recipient' => '',
				'from'    => $default_from,
				'subject' => 'Neue Anmeldung: {seminar_titel}',
				'body'    => "Hallo {ansprechpartner},\n\nfür Ihr Seminar ist eine neue Anmeldung eingegangen.\n\n- Seminar: **{seminar_titel}** ({seminar_nummer})\n- Ort: {seminar_ort}\n- Start: {seminar_startdatum}\n\n- Teilnehmer*in: **{name}**, {betrieb}, PLZ {plz}\n- E-Mail: {email}",
				'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
				'schedule' => 'instant', 'digest_subject' => '', 'digest_intro' => '',
			),
		) );
	}

	/** ---------- Versand ---------- */

	/**
	 * Benachrichtigungen versenden bzw. für die Wochenzusammenfassung einreihen.
	 *
	 * @param array  $submission Anmeldedaten.
	 * @param string $force_to   Wenn gesetzt (Soforttest): ALLE Mails an diese Adresse,
	 *                           Bedingungen werden ignoriert, auch wöchentliche Trigger
	 *                           gehen sofort raus. Sonst greift ggf. der Test-Modus.
	 * @return int Anzahl sofort versendeter Mails (eingereihte zählen nicht mit).
	 */
	public static function dispatch( $submission, $force_to = '' ) {
		if ( ! $submission ) {
			return 0;
		}
		$ctx  = self::build_context( $submission );
		$test = self::get_test();

		// Umleitungs-Adresse: Soforttest hat Vorrang, sonst globaler Test-Modus
		$route_to = '';
		if ( $force_to && is_email( $force_to ) ) {
			$route_to = $force_to;
		} elseif ( ! empty( $test['enabled'] ) && is_email( $test['address'] ) ) {
			$route_to = $test['address'];
		}

		$sent = 0;
		foreach ( self::get_triggers() as $trigger ) {
			if ( empty( $trigger['active'] ) ) {
				continue;
			}
			// Bedingung nur im Echtbetrieb prüfen (beim Soforttest ignorieren)
			if ( '' === $force_to && ! self::condition_met( $trigger, $submission ) ) {
				continue;
			}

			$orig = self::resolve_recipient( $trigger, $submission, $ctx );
			$to   = $route_to ? $route_to : $orig;
			if ( ! $to || ! is_email( $to ) ) {
				continue;
			}

			$subject = self::replace( $trigger['subject'] ?? '', $ctx );
			$body    = self::replace( $trigger['body'] ?? '', $ctx );

			// Wöchentliche Zusammenfassung: nur einreihen. Der Soforttest ($force_to)
			// umgeht die Warteschlange, damit der Admin sofort ein Ergebnis sieht.
			if ( '' === $force_to && 'weekly' === self::schedule_of( $trigger ) ) {
				self::queue_add( array(
					'group'          => self::trigger_key( $trigger ),
					'trigger'        => $trigger['name'] ?? '',
					'to'             => $to,
					'from'           => $trigger['from'] ?? '',
					'subject'        => $subject,
					'body'           => $body,
					'digest_subject' => $trigger['digest_subject'] ?? '',
					'digest_intro'   => $trigger['digest_intro'] ?? '',
					'created'        => current_time( 'mysql' ),
					'test'           => $route_to ? 1 : 0,
					'orig'           => $orig,
				) );
				continue;
			}

			if ( $route_to ) {
				$subject = '[TEST] ' . $subject;
				$body    = self::test_hint( 'diese Mail wäre an „' . ( $orig ?: 'kein Empfänger ermittelt' ) . '“ gegangen' ) . $body;
			}

			$ok = self::send( $to, $subject, $body, $trigger['from'] ?? '' );
			if ( $ok ) {
				$sent++;
			} else {
				error_log( '[BI-Mailer] Versand an ' . $to . ' fehlgeschlagen (Benachrichtigung: ' . ( $trigger['name'] ?? '?' ) . ')' );
			}
		}
		return $sent;
	}

	private static function resolve_recipient( $trigger, $submission, $ctx ) {
		switch ( $trigger['type'] ?? 'custom' ) {
			case 'geschaeftsstelle':
				return $ctx['{geschaeftsstelle_email}'];
			case 'teilnehmer':
				return $submission['email'];
			case 'bildungszentrum':
				return self::bildungszentrum_email( $submission['seminar_id'] );
			case 'ansprechpartner':
				return get_post_meta( $submission['seminar_id'], '_bi_ansprechpartner_email', true );
			case 'custom':
			default:
				return self::replace( $trigger['recipient'] ?? '', $ctx );
		}
	}

	private static function condition_met( $trigger, $submission ) {
		if ( empty( $trigger['cond_tax'] ) || empty( $trigger['cond_value'] ) ) {
			return true;
		}
		$terms = wp_get_object_terms( $submission['seminar_id'], $trigger['cond_tax'], array( 'fields' => 'names' ) );
		$has   = is_array( $terms ) && in_array( $trigger['cond_value'], $terms, true );
		return ( 'not' === ( $trigger['cond_op'] ?? 'is' ) ) ? ! $has : $has;
	}

	private static function bildungszentrum_email( $seminar_id ) {
		$terms = wp_get_object_terms( $seminar_id, BI_TAX_ORT );
		if ( is_array( $terms ) && $terms ) {
			$mail = get_term_meta( $terms[0]->term_id, 'email', true );
			if ( $mail && is_email( $mail ) ) {
				return $mail;
			}
		}
		return '';
	}

	/** ---------- Wöchentliche Zusammenfassung ---------- */

	/** 'instant' oder 'weekly' (Altbestand ohne Feld gilt als 'instant') */
	public static function schedule_of( $trigger ) {
		return ( 'weekly' === ( $trigger['schedule'] ?? 'instant' ) ) ? 'weekly' : 'instant';
	}

	/**
	 * Stabiler Gruppierungs-Schlüssel eines Triggers: die feste ID. Der Array-Index taugt
	 * dafür nicht – er verschiebt sich beim Löschen und würde Warteschlangen-Einträge
	 * falsch zusammenwerfen. Für Einträge, die noch vor der ID-Umstellung eingereiht
	 * wurden, bleibt der alte Schlüssel aus Bezeichnung/Typ/Adresse als Rückfall bestehen;
	 * sie gruppieren untereinander weiter korrekt.
	 */
	private static function trigger_key( $trigger ) {
		if ( ! empty( $trigger['id'] ) ) {
			return 'id:' . (int) $trigger['id'];
		}
		return md5( strtolower(
			( $trigger['name'] ?? '' ) . '|' . ( $trigger['type'] ?? '' ) . '|' . ( $trigger['recipient'] ?? '' )
		) );
	}

	public static function get_queue() {
		$q = get_option( self::QUEUE_OPTION, array() );
		return is_array( $q ) ? $q : array();
	}

	private static function queue_add( $item ) {
		$q   = self::get_queue();
		$q[] = $item;
		update_option( self::QUEUE_OPTION, $q, false );
	}

	/** Warteschlange nach Benachrichtigung gruppiert: [Bezeichnung => Anzahl] */
	public static function queue_summary() {
		$out = array();
		foreach ( self::get_queue() as $it ) {
			$name         = $it['trigger'] !== '' ? $it['trigger'] : 'ohne Bezeichnung';
			$out[ $name ] = ( $out[ $name ] ?? 0 ) + 1;
		}
		return $out;
	}

	/** Cron-Callback */
	public static function run_weekly() {
		$res = self::flush_queue();
		if ( $res['items'] ) {
			error_log( sprintf( '[BI-Mailer] Wochenzusammenfassung: %d Anmeldungen in %d Mail(s).', $res['items'], $res['mails'] ) );
		}
	}

	/**
	 * Warteschlange abarbeiten: je Benachrichtigung und Empfänger eine Sammelmail.
	 *
	 * @return array ['mails' => versendete Mails, 'items' => enthaltene Anmeldungen]
	 */
	public static function flush_queue() {
		$queue = self::get_queue();
		// Zuerst leeren, dann senden: Falls ein wp_mail() hängt oder der Job doppelt
		// startet, geht lieber eine Zusammenfassung verloren als dass sie doppelt kommt.
		update_option( self::QUEUE_OPTION, array(), false );

		if ( ! $queue ) {
			return array( 'mails' => 0, 'items' => 0 );
		}

		$groups = array();
		foreach ( $queue as $it ) {
			$key              = ( $it['group'] ?? '' ) . '|' . strtolower( $it['to'] ?? '' );
			$groups[ $key ][] = $it;
		}

		$sent = 0;
		foreach ( $groups as $items ) {
			$first = $items[0];
			$to    = $first['to'] ?? '';
			if ( ! is_email( $to ) ) {
				error_log( '[BI-Mailer] Wochenzusammenfassung ohne gültigen Empfänger verworfen (Benachrichtigung: ' . ( $first['trigger'] ?? '?' ) . ')' );
				continue;
			}

			$ctx     = self::digest_context( $items );
			$tpl     = trim( (string) ( $first['digest_subject'] ?? '' ) );
			$subject = self::replace( $tpl !== '' ? $tpl : 'Wochenzusammenfassung: {anzahl} neue Anmeldungen', $ctx );
			$body    = self::build_digest_body( $items, $ctx );

			if ( ! empty( $first['test'] ) ) {
				$subject = '[TEST] ' . $subject;
				$body    = self::test_hint( 'diese Zusammenfassung wäre an „' . ( $first['orig'] ?: 'kein Empfänger ermittelt' ) . '“ gegangen' ) . $body;
			}

			if ( self::send( $to, $subject, $body, $first['from'] ?? '' ) ) {
				$sent++;
			} else {
				error_log( '[BI-Mailer] Wochenzusammenfassung an ' . $to . ' fehlgeschlagen (Benachrichtigung: ' . ( $first['trigger'] ?? '?' ) . ')' );
			}
		}

		return array( 'mails' => $sent, 'items' => count( $queue ) );
	}

	/** Platzhalter, die zusätzlich nur in der Wochenzusammenfassung gefüllt werden */
	public static function digest_placeholders() {
		return array(
			'{anzahl}'          => 'Anzahl der gesammelten Anmeldungen',
			'{zeitraum}'        => 'Zeitraum der gesammelten Anmeldungen (TT.MM.JJJJ – TT.MM.JJJJ)',
			'{benachrichtigung}' => 'Bezeichnung der Benachrichtigung',
			'{datum}'           => 'Heutiges Datum',
		);
	}

	private static function digest_context( $items ) {
		$stamps = array();
		foreach ( $items as $it ) {
			$ts = strtotime( $it['created'] ?? '' );
			if ( $ts ) {
				$stamps[] = $ts;
			}
		}
		$von      = $stamps ? date_i18n( 'd.m.Y', min( $stamps ) ) : date_i18n( 'd.m.Y' );
		$bis      = $stamps ? date_i18n( 'd.m.Y', max( $stamps ) ) : date_i18n( 'd.m.Y' );
		$zeitraum = ( $von === $bis ) ? $von : $von . ' – ' . $bis;

		return array(
			'{anzahl}'           => (string) count( $items ),
			'{zeitraum}'         => $zeitraum,
			'{benachrichtigung}' => $items[0]['trigger'] ?? '',
			'{datum}'            => date_i18n( 'd.m.Y' ),
		);
	}

	private static function build_digest_body( $items, $ctx ) {
		$intro = trim( (string) ( $items[0]['digest_intro'] ?? '' ) );
		if ( '' === $intro ) {
			$intro = "Hallo,\n\nhier die gesammelten Anmeldungen aus dem Zeitraum {zeitraum} – insgesamt {anzahl}.";
		}

		$total = count( $items );
		$out   = self::replace( $intro, $ctx ) . "\n\n";

		foreach ( $items as $i => $it ) {
			$ts   = strtotime( $it['created'] ?? '' );
			$out .= "---\n\n";
			$out .= sprintf(
				"## Anmeldung %d von %d — eingegangen am %s\n\n",
				$i + 1,
				$total,
				$ts ? date_i18n( 'd.m.Y H:i', $ts ) : '—'
			);
			$out .= trim( (string) $it['body'] ) . "\n\n";
		}

		$out .= "---\n\n_automatisch erzeugte Wochenzusammenfassung_\n";
		return $out;
	}

	/** ---------- Cron-Steuerung ---------- */

	public static function cron_schedules( $schedules ) {
		$schedules[ self::CRON_SCHED ] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Wöchentlich (Bildungsprogramm)',
		);
		return $schedules;
	}

	/** Versandzeitpunkt: Wochentag (1 = Montag … 7 = Sonntag) + Uhrzeit in Website-Zeitzone */
	public static function get_schedule() {
		$s = get_option( self::SCHED_OPTION, array() );
		$s = wp_parse_args( is_array( $s ) ? $s : array(), array( 'weekday' => 1, 'hour' => 8, 'minute' => 0 ) );

		$s['weekday'] = max( 1, min( 7, (int) $s['weekday'] ) );
		$s['hour']    = max( 0, min( 23, (int) $s['hour'] ) );
		$s['minute']  = ( (int) $s['minute'] >= 30 ) ? 30 : 0;
		return $s;
	}

	public static function weekday_labels() {
		return array( 1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag' );
	}

	/** Nächster Versandzeitpunkt als UTC-Timestamp (berechnet in der Website-Zeitzone) */
	private static function next_run_ts() {
		$s   = self::get_schedule();
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );

		$target = array( 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday' );
		$day    = $target[ $s['weekday'] ];

		$next = new DateTime( 'now', $tz );
		$next->setTime( $s['hour'], $s['minute'], 0 );
		if ( $next->format( 'l' ) !== $day || $next <= $now ) {
			$next->modify( 'next ' . $day );
			$next->setTime( $s['hour'], $s['minute'], 0 );
		}
		return $next->getTimestamp();
	}

	/**
	 * Der Aktivierungshook läuft nach plugins_loaded – init() und damit der
	 * cron_schedules-Filter sind dort noch nicht registriert, und wp_schedule_event()
	 * würde das unbekannte Intervall ablehnen. Deshalb vor jedem Planen absichern.
	 */
	private static function ensure_interval() {
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		}
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::ensure_interval();
			wp_schedule_event( self::next_run_ts(), self::CRON_SCHED, self::CRON_HOOK );
		}
	}

	public static function reschedule_cron() {
		self::ensure_interval();
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_schedule_event( self::next_run_ts(), self::CRON_SCHED, self::CRON_HOOK );
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** ---------- Platzhalter ---------- */

	public static function placeholders() {
		return array(
			'{anrede}'               => 'Anrede',
			'{titel}'                => 'Titel',
			'{vorname}'              => 'Vorname',
			'{nachname}'             => 'Nachname',
			'{name}'                 => 'Vor- und Nachname',
			'{strasse}'              => 'Straße + Hausnr. (privat)',
			'{plz_privat}'           => 'PLZ (privat)',
			'{ort}'                  => 'Ort (privat)',
			'{telefon}'              => 'Telefon',
			'{mobil}'                => 'Mobiltelefon',
			'{email}'                => 'E-Mail des Teilnehmers',
			'{mitglied}'             => 'IG-Metall-Mitglied (ja/nein)',
			'{mitgliedsnummer}'      => 'Mitgliedsnummer',
			'{funktion}'             => 'Funktion im Betriebsrat',
			'{betrieb}'              => 'Betrieb',
			'{betrieb_strasse}'      => 'Straße + Hausnr. (Betrieb)',
			'{plz}'                  => 'Betriebs-PLZ',
			'{betrieb_ort}'          => 'Ort (Betrieb)',
			'{betrieb_email}'        => 'E-Mail (Betrieb)',
			'{freistellung}'         => 'Gewählte Freistellung',
			'{bemerkungen}'          => 'Bemerkungen',
			'{nachricht}'            => 'Bemerkungen (Alias)',
			'{geschaeftsstelle}'     => 'Name der zuständigen Geschäftsstelle',
			'{geschaeftsstelle_email}' => 'E-Mail der Geschäftsstelle',
			'{seminar_titel}'        => 'Seminartitel',
			'{seminar_nummer}'       => 'Seminarnummer',
			'{seminar_startdatum}'   => 'Startdatum (TT.MM.JJJJ)',
			'{seminar_startuhrzeit}' => 'Startuhrzeit',
			'{seminar_enddatum}'     => 'Enddatum',
			'{seminar_enduhrzeit}'   => 'Enduhrzeit',
			'{seminar_anreisedatum}' => 'Anreisedatum',
			'{seminar_anreiseuhrzeit}' => 'Anreiseuhrzeit',
			'{seminar_themen}'       => 'Themen im Seminar',
			'{seminar_ort}'          => 'Bildungszentrum / Ort',
			'{ansprechpartner}'      => 'Ansprechpartner des Seminars',
			'{ansprechpartner_email}' => 'E-Mail des Ansprechpartners',
			'{datum}'                => 'Heutiges Datum',
		);
	}

	private static function build_context( $submission ) {
		$sid     = (int) $submission['seminar_id'];
		$gs      = BI_PLZ::lookup( $submission['plz'] );
		$ort     = wp_get_object_terms( $sid, BI_TAX_ORT, array( 'fields' => 'names' ) );
		$start   = get_post_meta( $sid, '_bi_startdatum', true );
		$end     = get_post_meta( $sid, '_bi_enddatum', true );
		$anreise = get_post_meta( $sid, '_bi_anreisedatum', true );

		$d = ( isset( $submission['data'] ) && is_array( $submission['data'] ) ) ? $submission['data'] : array();

		return array(
			'{anrede}'                 => $d['anrede'] ?? '',
			'{titel}'                  => $d['titel'] ?? '',
			'{vorname}'                => $submission['vorname'],
			'{nachname}'               => $submission['nachname'],
			'{name}'                   => trim( $submission['vorname'] . ' ' . $submission['nachname'] ),
			'{strasse}'                => $d['strasse'] ?? '',
			'{plz_privat}'             => $d['plz'] ?? '',
			'{ort}'                    => $d['ort'] ?? '',
			'{telefon}'                => $submission['telefon'],
			'{mobil}'                  => $d['mobil'] ?? '',
			'{email}'                  => $submission['email'],
			'{mitglied}'               => $d['mitglied'] ?? '',
			'{mitgliedsnummer}'        => $d['mitgliedsnummer'] ?? '',
			'{funktion}'               => $d['funktion'] ?? '',
			'{betrieb}'                => $submission['betrieb'],
			'{betrieb_strasse}'        => $d['betrieb_strasse'] ?? '',
			'{plz}'                    => $submission['plz'],
			'{betrieb_ort}'            => $d['betrieb_ort'] ?? '',
			'{betrieb_email}'          => $d['betrieb_email'] ?? '',
			'{freistellung}'           => $d['freistellung'] ?? '',
			'{bemerkungen}'            => $submission['nachricht'],
			'{nachricht}'              => $submission['nachricht'],
			'{geschaeftsstelle}'       => $gs ? $gs['geschaeftsstelle'] : '',
			'{geschaeftsstelle_email}' => $gs ? $gs['email'] : '',
			'{seminar_titel}'          => get_the_title( $sid ),
			'{seminar_nummer}'         => get_post_meta( $sid, '_bi_seminarnummer', true ),
			'{seminar_startdatum}'     => $start ? date_i18n( 'd.m.Y', strtotime( $start ) ) : '',
			'{seminar_startuhrzeit}'   => get_post_meta( $sid, '_bi_startuhrzeit', true ),
			'{seminar_enddatum}'       => $end ? date_i18n( 'd.m.Y', strtotime( $end ) ) : '',
			'{seminar_enduhrzeit}'     => get_post_meta( $sid, '_bi_enduhrzeit', true ),
			'{seminar_anreisedatum}'   => $anreise ? date_i18n( 'd.m.Y', strtotime( $anreise ) ) : '',
			'{seminar_anreiseuhrzeit}' => get_post_meta( $sid, '_bi_anreiseuhrzeit', true ),
			'{seminar_themen}'         => get_post_meta( $sid, '_bi_themen', true ),
			'{seminar_ort}'            => ( is_array( $ort ) && $ort ) ? $ort[0] : '',
			'{ansprechpartner}'        => get_post_meta( $sid, '_bi_ansprechpartner', true ),
			'{ansprechpartner_email}'  => get_post_meta( $sid, '_bi_ansprechpartner_email', true ),
			'{datum}'                  => date_i18n( 'd.m.Y' ),
		);
	}

	private static function replace( $text, $ctx ) {
		return strtr( (string) $text, $ctx );
	}

	/** ---------- Rich Text: Steuerzeichen -> HTML bzw. Klartext ---------- */

	/** Werden HTML-Mails verschickt? (Die Klartext-Fassung geht immer mit raus.) */
	public static function html_enabled() {
		return 'plain' !== get_option( self::FORMAT_OPTION, 'html' );
	}

	/**
	 * Mail versenden: HTML-Fassung als Body, Klartext-Fassung als Fallback.
	 *
	 * @param string $to      Empfänger.
	 * @param string $subject Betreff (bereits gerendert).
	 * @param string $text    Quelltext mit Steuerzeichen (bereits gerendert).
	 * @param string $from    Absender-Header oder ''.
	 */
	private static function send( $to, $subject, $text, $from = '' ) {
		$headers = array();
		if ( '' !== trim( (string) $from ) ) {
			$headers[] = 'From: ' . $from;
		}

		$plain = self::markup_to_plain( $text );

		if ( self::html_enabled() ) {
			$headers[]      = 'Content-Type: text/html; charset=UTF-8';
			$body           = self::html_document( self::markup_to_html( $text ), $subject );
			self::$alt_body = $plain;
		} else {
			$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			$body      = $plain;
		}

		$ok = wp_mail( $to, $subject, $body, $headers );

		// Immer zurücksetzen, damit fremde Mails keinen AltBody von uns erben.
		self::$alt_body = '';
		return $ok;
	}

	/** Hängt die Klartext-Fassung an die gerade versendete Mail (Hook phpmailer_init). */
	public static function apply_alt_body( $phpmailer ) {
		if ( '' !== self::$alt_body ) {
			$phpmailer->AltBody = self::$alt_body;
		}
	}

	/** Hinweiszeile für Testmails – als Markup, damit sie in beiden Fassungen passt */
	private static function test_hint( $satz ) {
		return '**TEST-VERSAND** — ' . $satz . ".\n\n---\n\n";
	}

	/**
	 * Steuerzeichen -> HTML.
	 *
	 * Der Quelltext wird zuerst vollständig escaped: Die Texte sind reiner Text,
	 * eingesetzte Platzhalterwerte (Namen, Betriebe, Bemerkungen) dürfen kein
	 * Markup in die Mail schmuggeln. Erst danach entstehen die erlaubten Tags.
	 */
	private static function markup_to_html( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$lines = explode( "\n", esc_html( $text ) );

		$html = '';
		$para = array();
		$list = array();

		$flush_para = function () use ( &$html, &$para ) {
			if ( $para ) {
				$html .= '<p style="margin:0 0 14px;line-height:1.55">' . implode( '<br>', $para ) . '</p>';
				$para  = array();
			}
		};
		$flush_list = function () use ( &$html, &$list ) {
			if ( $list ) {
				$html .= '<ul style="margin:0 0 14px;padding-left:22px;line-height:1.55">';
				foreach ( $list as $li ) {
					$html .= '<li style="margin:0 0 5px">' . $li . '</li>';
				}
				$html .= '</ul>';
				$list  = array();
			}
		};

		$sizes = array( 1 => 21, 2 => 17, 3 => 15 );

		foreach ( $lines as $line ) {
			$t = trim( $line );

			// Leerzeile beendet Absatz bzw. Liste
			if ( '' === $t ) {
				$flush_list();
				$flush_para();
				continue;
			}

			// Trennlinie (vor der Listen-Prüfung, sonst schluckt „- " die Striche)
			if ( preg_match( '/^(?:-{3,}|={3,})$/', $t ) ) {
				$flush_list();
				$flush_para();
				$html .= '<hr style="border:0;border-top:1px solid #e3e3e6;margin:20px 0">';
				continue;
			}

			// Überschriften
			if ( preg_match( '/^(#{1,3})\s+(.+)$/', $t, $m ) ) {
				$flush_list();
				$flush_para();
				$lvl   = strlen( $m[1] );
				$tag   = 'h' . ( $lvl + 1 );
				$html .= '<' . $tag . ' style="margin:22px 0 8px;font-size:' . $sizes[ $lvl ] . 'px;line-height:1.3;color:#17171a">'
					. self::inline_html( $m[2] ) . '</' . $tag . '>';
				continue;
			}

			// Aufzählung
			if ( preg_match( '/^[-*•]\s+(.+)$/', $t, $m ) ) {
				$flush_para();
				$list[] = self::inline_html( $m[1] );
				continue;
			}

			$flush_list();
			$para[] = self::inline_html( $t );
		}

		$flush_list();
		$flush_para();

		return $html;
	}

	/** Zeichen-Auszeichnungen innerhalb einer Zeile (Eingabe ist bereits escaped) */
	private static function inline_html( $s ) {
		$s = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/',
			function ( $m ) {
				// esc_html() hat „&" zu „&amp;" gemacht – für esc_url() zurückdrehen.
				$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
				return '<a href="' . esc_url( $url ) . '" style="color:#e2001a">' . $m[1] . '</a>';
			},
			$s
		);
		$s = preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $s );
		// Unterstriche nur an Wortgrenzen, damit E-Mail-Adressen und Dateinamen heil bleiben
		$s = preg_replace( '/(?<![\w\/])_(?=\S)(.+?)(?<=\S)_(?![\w\/])/s', '<em>$1</em>', $s );
		return $s;
	}

	/** Steuerzeichen -> Klartext (Fallback-Fassung, nichts wird escaped) */
	private static function markup_to_plain( $text ) {
		$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$out  = array();

		foreach ( explode( "\n", $text ) as $line ) {
			$t = rtrim( $line );

			if ( preg_match( '/^\s*(?:-{3,}|={3,})\s*$/', $t ) ) {
				$out[] = str_repeat( '-', 56 );
				continue;
			}

			$t = preg_replace( '/^(\s*)#{1,3}\s+/', '$1', $t );
			$t = preg_replace( '/^(\s*)[*•]\s+/', '$1- ', $t );
			$out[] = self::inline_plain( $t );
		}

		return implode( "\n", $out );
	}

	private static function inline_plain( $s ) {
		$s = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/',
			function ( $m ) {
				return $m[1] . ' (' . $m[2] . ')';
			},
			$s
		);
		$s = preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '$1', $s );
		$s = preg_replace( '/(?<![\w\/])_(?=\S)(.+?)(?<=\S)_(?![\w\/])/s', '$1', $s );
		return $s;
	}

	/** Gestalteter Rahmen um den HTML-Inhalt (Tabellenlayout + Inline-Styles wegen der Mailclients) */
	private static function html_document( $body_html, $subject = '' ) {
		$site = get_bloginfo( 'name' );

		return '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#f4f4f6;-webkit-text-size-adjust:100%">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f6;padding:24px 12px">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e3e3e6;border-radius:8px">'
			. '<tr><td style="height:6px;background:#e2001a;border-radius:8px 8px 0 0;font-size:0;line-height:0">&nbsp;</td></tr>'
			. '<tr><td style="padding:28px 32px;font-family:Helvetica,Arial,sans-serif;font-size:15px;color:#17171a">'
			. $body_html
			. '</td></tr></table>'
			. '<div style="max-width:600px;margin:14px auto 0;font-family:Helvetica,Arial,sans-serif;font-size:12px;color:#8a8a90">'
			. esc_html( $site ) . '</div>'
			. '</td></tr></table></body></html>';
	}

	/** ---------- Admin-Seite ---------- */

	/**
	 * Router der Seite „Mail-Benachrichtigungen".
	 *
	 * Ohne action die Übersicht (Hero + Listentabelle), mit action=edit|new die
	 * Bearbeiten-Maske einer einzelnen Benachrichtigung.
	 */
	public static function render_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'edit' === $action || 'new' === $action ) {
			self::render_edit( 'new' === $action ? 0 : (int) ( $_GET['id'] ?? 0 ) );
			return;
		}
		self::render_list();
	}

	/** URL der Seite, optional mit zusätzlichen Parametern */
	public static function page_url( $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/** Bearbeiten-Link einer Benachrichtigung */
	public static function edit_url( $id ) {
		return self::page_url( array( 'action' => 'edit', 'id' => (int) $id ) );
	}

	/**
	 * Übersicht: Hero mit den drei seitenweiten Einstellungen, darunter der
	 * Konsistenz-Check und die Listentabelle aller Benachrichtigungen.
	 */
	private static function render_list() {
		require_once BI_PATH . 'includes/class-bi-mail-table.php';

		$triggers = self::get_triggers();
		$taxes    = BI_CPT::taxonomies();
		$notice   = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';

		$table = new BI_Mail_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Mail-Benachrichtigungen</h1>
			<a href="<?php echo esc_url( self::page_url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action">Neu hinzufügen</a>
			<hr class="wp-header-end">

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p>Jede Benachrichtigung ist ein eigener Eintrag. Sie wird nach einer Anmeldung verschickt, sofern
				sie aktiv ist und ihre Bedingung zutrifft – entweder <strong>sofort</strong> oder gesammelt als
				<strong>wöchentliche Zusammenfassung</strong>.</p>

			<div class="bi-hero">
				<?php echo self::schedule_box(); // phpcs:ignore – intern escaped ?>
				<?php echo self::test_box(); // phpcs:ignore – intern escaped ?>
				<?php echo self::format_box(); // phpcs:ignore – intern escaped ?>
			</div>

			<?php $cons = self::consistency_notices( $triggers, $taxes ); ?>
			<?php if ( $cons ) : ?>
				<div class="notice notice-warning" style="padding:10px 14px">
					<p style="margin:0 0 6px"><strong>Konsistenz-Check der aktiven Benachrichtigungen:</strong></p>
					<ul style="margin:0;list-style:disc;padding-left:20px">
						<?php foreach ( $cons as $n ) : ?>
							<li><?php echo esc_html( $n ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( self::page_url() ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="bi_bulk" value="1">
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Bearbeiten-Maske einer einzelnen Benachrichtigung.
	 *
	 * @param int $id 0 = neu anlegen.
	 */
	private static function render_edit( $id ) {
		$id     = (int) $id;
		$taxes  = BI_CPT::taxonomies();
		$notice = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		$t      = $id ? self::get_trigger( $id ) : null;

		if ( $id && ! $t ) {
			?>
			<div class="wrap">
				<h1>Benachrichtigung</h1>
				<div class="notice notice-error"><p>Diese Benachrichtigung gibt es nicht (mehr).</p></div>
				<p><a href="<?php echo esc_url( self::page_url() ); ?>">&larr; Zurück zur Übersicht</a></p>
			</div>
			<?php
			return;
		}
		if ( ! $t ) {
			$t = self::empty_trigger();
		}
		$schedule = self::schedule_of( $t );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php echo $id ? 'Benachrichtigung bearbeiten' : 'Neue Benachrichtigung'; ?></h1>
			<a href="<?php echo esc_url( self::page_url() ); ?>" class="page-title-action">Zur Übersicht</a>
			<hr class="wp-header-end">

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bi-trigger-form">
				<input type="hidden" name="action" value="bi_save_trigger">
				<input type="hidden" name="id" value="<?php echo $id; ?>">
				<?php wp_nonce_field( 'bi_save_trigger_' . $id ); ?>

				<table class="form-table">
					<tr>
						<th><label for="bi_name">Bezeichnung</label></th>
						<td>
							<input type="text" id="bi_name" class="regular-text" name="name" value="<?php echo esc_attr( $t['name'] ); ?>" placeholder="z. B. Benachrichtigung an Geschäftsstelle">
							<p class="description">Nur zur internen Unterscheidung – erscheint in der Übersicht und in der Warteschlange.</p>
						</td>
					</tr>
					<tr>
						<th>Status</th>
						<td><label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $t['active'] ) ); ?>> aktiv</label>
							<p class="description">Inaktive Benachrichtigungen werden bei Anmeldungen übersprungen.</p></td>
					</tr>
					<tr>
						<th><label for="bi_type">Empfänger-Typ</label></th>
						<td>
							<select id="bi_type" name="type" class="bi-trigger-type">
								<?php foreach ( array(
									'geschaeftsstelle' => 'Zuständige Geschäftsstelle (per PLZ)',
									'teilnehmer'       => 'Teilnehmer (Bestätigung)',
									'bildungszentrum'  => 'Bildungszentrum (Seminarort)',
									'ansprechpartner'  => 'Ansprechpartner des Seminars',
									'custom'           => 'Feste/eigene Adresse',
								) as $val => $lbl ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $t['type'], $val ); ?>><?php echo esc_html( $lbl ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Außer bei „Feste/eigene Adresse" wird der Empfänger automatisch aus der Anmeldung bzw. dem Seminar ermittelt.</p>
						</td>
					</tr>
					<tr data-when-type="custom">
						<th><label for="bi_recipient">Feste Adresse</label></th>
						<td>
							<input type="text" id="bi_recipient" class="regular-text" name="recipient" value="<?php echo esc_attr( $t['recipient'] ); ?>" placeholder="z. B. anmeldung@example.de">
							<p class="description">Eine fest hinterlegte E-Mail-Adresse, an die diese Benachrichtigung immer geht (z. B. ein zentrales
								Postfach oder Archiv). Platzhalter sind erlaubt – z. B. <code>{ansprechpartner_email}</code> oder <code>{betrieb_email}</code>.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bi_from">Absender (From)</label></th>
						<td><input type="text" id="bi_from" class="regular-text" name="from" value="<?php echo esc_attr( $t['from'] ); ?>" placeholder="Name &lt;mail@domain.de&gt;"></td>
					</tr>
					<tr>
						<th><label for="bi_cond_tax">Bedingung</label></th>
						<td>
							nur senden, wenn Seminar in
							<select id="bi_cond_tax" name="cond_tax">
								<option value="">— keine Bedingung —</option>
								<?php foreach ( $taxes as $slug => $cfg ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $t['cond_tax'], $slug ); ?>><?php echo esc_html( $cfg['single'] ); ?></option>
								<?php endforeach; ?>
							</select>
							den Wert
							<input type="text" name="cond_value" value="<?php echo esc_attr( $t['cond_value'] ); ?>" placeholder="z. B. Datenschutz">
							<select name="cond_op">
								<option value="is" <?php selected( $t['cond_op'] ?? 'is', 'is' ); ?>>hat</option>
								<option value="not" <?php selected( $t['cond_op'] ?? 'is', 'not' ); ?>>nicht hat</option>
							</select>
							<p class="description">Ohne Bedingung wird diese Benachrichtigung bei <strong>jeder</strong> Anmeldung verschickt.
								Sollen sich zwei Benachrichtigungen an denselben Empfänger gegenseitig ausschließen, gib ihnen
								gegenteilige Bedingungen auf denselben Wert – z. B. eine mit Bildungszentrum <em>hat</em>
								„Kritische Akademie" und eine mit <em>nicht hat</em>. So greift bei jeder Anmeldung genau eine
								der beiden.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bi_subject">Betreff</label></th>
						<td><input type="text" id="bi_subject" class="large-text" name="subject" value="<?php echo esc_attr( $t['subject'] ); ?>"></td>
					</tr>
					<tr>
						<th><label>Text</label></th>
						<td><?php echo self::editor_field( 'body', $t['body'], 14, '', self::placeholders() ); // phpcs:ignore – intern escaped ?></td>
					</tr>
					<tr>
						<th><label for="bi_schedule">Versandart</label></th>
						<td>
							<select id="bi_schedule" name="schedule" class="bi-trigger-schedule">
								<option value="instant" <?php selected( $schedule, 'instant' ); ?>>Sofort – eine Mail je Anmeldung</option>
								<option value="weekly" <?php selected( $schedule, 'weekly' ); ?>>Wöchentliche Zusammenfassung</option>
							</select>
							<p class="description">Bei <strong>Wöchentliche Zusammenfassung</strong> wird zur Anmeldung nichts versendet.
								Der obenstehende Text wird gerendert und gesammelt; zum eingestellten Wochentermin
								(<?php echo esc_html( self::schedule_label() ); ?>, einstellbar in der Übersicht) geht <em>eine</em> Mail
								mit allen gesammelten Anmeldungen an denselben Empfänger raus. Für Teilnehmer-Bestätigungen ist das
								nicht sinnvoll – dort „Sofort" lassen.</p>
						</td>
					</tr>
					<tr data-when-schedule="weekly">
						<th><label for="bi_digest_subject">Betreff (Zusammenfassung)</label></th>
						<td>
							<input type="text" id="bi_digest_subject" class="large-text" name="digest_subject" value="<?php echo esc_attr( $t['digest_subject'] ?? '' ); ?>" placeholder="Wochenzusammenfassung: {anzahl} neue Anmeldungen">
							<p class="description">Leer lassen für den Standardbetreff. Erlaubt sind <code>{anzahl}</code>,
								<code>{zeitraum}</code>, <code>{benachrichtigung}</code>, <code>{datum}</code>.</p>
						</td>
					</tr>
					<tr data-when-schedule="weekly">
						<th><label>Einleitung (Zusammenfassung)</label></th>
						<td>
							<?php echo self::editor_field( 'digest_intro', $t['digest_intro'] ?? '', 5, 'Hallo, hier die gesammelten Anmeldungen aus dem Zeitraum {zeitraum} – insgesamt {anzahl}.', self::digest_placeholders() ); // phpcs:ignore – intern escaped ?>
							<p class="description">Text vor der Auflistung der einzelnen Anmeldungen. Leer lassen für den Standardtext.</p>
						</td>
					</tr>
				</table>

				<p class="submit bi-editactions">
					<button type="submit" name="bi_after" value="stay" class="button button-primary">Speichern</button>
					<button type="submit" name="bi_after" value="list" class="button">Speichern und zurück</button>
					<a class="button-link" href="<?php echo esc_url( self::page_url() ); ?>">Abbrechen</a>
					<?php if ( $id ) : ?>
						<a class="button-link button-link-delete bi-editactions__del"
							href="<?php echo esc_url( self::row_action_url( 'delete', $id ) ); ?>"
							onclick="return confirm('Diese Benachrichtigung wirklich löschen?');">Löschen</a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Textfeld mit Formatier-Leiste.
	 *
	 * Die Knöpfe fügen nur Steuerzeichen in das Textfeld ein – gespeichert wird
	 * weiterhin reiner Text. Das Auswahlfeld rechts setzt Platzhalter an der
	 * Cursorposition ein.
	 *
	 * @param string $name         name-Attribut des Textfelds.
	 * @param string $value        Aktueller Inhalt.
	 * @param int    $rows         Höhe.
	 * @param string $placeholder  placeholder-Attribut.
	 * @param array  $placeholders Platzhalter für das Auswahlfeld [{tag} => Beschreibung].
	 */
	private static function editor_field( $name, $value, $rows, $placeholder = '', $placeholders = array() ) {
		$buttons = array(
			array( 'label' => 'F',  'title' => 'Fett',        'style' => 'font-weight:700',    'wrap' => '**' ),
			array( 'label' => 'K',  'title' => 'Kursiv',      'style' => 'font-style:italic',  'wrap' => '_' ),
			array( 'label' => 'H',  'title' => 'Überschrift', 'style' => 'font-weight:700',    'block' => '## ' ),
			array( 'label' => '•',  'title' => 'Aufzählung',  'style' => '',                   'block' => '- ' ),
			array( 'label' => '🔗', 'title' => 'Link',        'style' => '',                   'link' => '1' ),
			array( 'label' => '—',  'title' => 'Trennlinie',  'style' => '',                   'insert' => "\n---\n" ),
		);

		ob_start();
		echo '<div class="bi-mailedit">';
		echo '<div class="bi-mailedit__bar">';
		foreach ( $buttons as $b ) {
			printf(
				'<button type="button" class="button bi-mailedit__btn" title="%s" style="%s"%s%s%s%s>%s</button>',
				esc_attr( $b['title'] ),
				esc_attr( $b['style'] ),
				isset( $b['wrap'] ) ? ' data-wrap="' . esc_attr( $b['wrap'] ) . '"' : '',
				isset( $b['block'] ) ? ' data-block="' . esc_attr( $b['block'] ) . '"' : '',
				isset( $b['insert'] ) ? ' data-insert="' . esc_attr( $b['insert'] ) . '"' : '',
				isset( $b['link'] ) ? ' data-link="1"' : '',
				esc_html( $b['label'] )
			);
		}
		if ( $placeholders ) {
			echo '<select class="bi-mailedit__ph"><option value="">Platzhalter einfügen …</option>';
			foreach ( $placeholders as $tag => $desc ) {
				echo '<option value="' . esc_attr( $tag ) . '">' . esc_html( $tag . ' – ' . $desc ) . '</option>';
			}
			echo '</select>';
		}
		echo '</div>';

		printf(
			'<textarea class="large-text bi-mailedit__area" rows="%d" name="%s" placeholder="%s">%s</textarea>',
			intval( $rows ),
			esc_attr( $name ),
			esc_attr( $placeholder ),
			esc_textarea( $value )
		);
		echo '</div>';

		return ob_get_clean();
	}

	private static function empty_trigger() {
		return array(
			'id' => 0,
			'active' => 0, 'name' => '', 'type' => 'custom', 'recipient' => '',
			'from' => get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>',
			'subject' => '', 'body' => '', 'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
			'schedule' => 'instant', 'digest_subject' => '', 'digest_intro' => '',
		);
	}

	public static function type_labels() {
		return array(
			'geschaeftsstelle' => 'Geschäftsstelle',
			'teilnehmer'       => 'Teilnehmer',
			'bildungszentrum'  => 'Bildungszentrum',
			'ansprechpartner'  => 'Ansprechpartner',
			'custom'           => 'Feste Adresse',
		);
	}

	/** Lesbare Kurzform der Bedingung eines Triggers, z. B. „Bildungszentrum = Kritische Akademie“; leer ohne Bedingung. */
	public static function condition_label( $t, $taxes ) {
		if ( empty( $t['cond_tax'] ) || empty( $t['cond_value'] ) ) {
			return '';
		}
		$tax = isset( $taxes[ $t['cond_tax'] ] ) ? $taxes[ $t['cond_tax'] ]['single'] : $t['cond_tax'];
		$op  = ( 'not' === ( $t['cond_op'] ?? 'is' ) ) ? '≠' : '=';
		return $tax . ' ' . $op . ' „' . $t['cond_value'] . '“';
	}

	/** z. B. „jeden Montag um 08:00 Uhr“ */
	private static function schedule_label() {
		$s    = self::get_schedule();
		$days = self::weekday_labels();
		return sprintf( 'jeden %s um %02d:%02d Uhr', $days[ $s['weekday'] ], $s['hour'], $s['minute'] );
	}

	/**
	 * Konsistenz-Check: prüft die aktiven Trigger je Empfänger (Typ bzw. bei fester
	 * Adresse je Empfängeradresse) auf Doppelversand und Lücken. Rein informativ –
	 * greift nicht in den Versand ein.
	 *
	 * @return string[] Hinweise für den Admin.
	 */
	private static function consistency_notices( $triggers, $taxes ) {
		$type_labels = self::type_labels();

		$groups = array();
		foreach ( $triggers as $t ) {
			if ( empty( $t['active'] ) ) {
				continue;
			}
			$key = $t['type'] ?? 'custom';
			if ( 'custom' === $key ) {
				$key .= ':' . strtolower( trim( $t['recipient'] ?? '' ) );
			}
			$groups[ $key ][] = $t;
		}

		$names = function ( $list ) {
			$n = array();
			foreach ( $list as $t ) {
				$n[] = '„' . ( $t['name'] !== '' ? $t['name'] : 'ohne Bezeichnung' ) . '“';
			}
			return implode( ', ', $n );
		};

		$notices = array();

		// Teilnehmer-Bestätigung als Wochenzusammenfassung ist fast immer ein Versehen:
		// Der Teilnehmer bekäme eine Sammelmail mit fremden Anmeldungen.
		foreach ( $triggers as $t ) {
			if ( ! empty( $t['active'] ) && 'teilnehmer' === ( $t['type'] ?? '' ) && 'weekly' === self::schedule_of( $t ) ) {
				$notices[] = sprintf(
					'%s geht an den Teilnehmer, ist aber auf „wöchentliche Zusammenfassung" gestellt: Die Bestätigung käme erst Tage später – und alle Anmeldungen desselben Teilnehmers landen in einer Mail. In der Regel gehört hier „Sofort" hin.',
					$names( array( $t ) )
				);
			}
		}

		foreach ( $groups as $key => $list ) {
			$type  = strtok( $key, ':' );
			$label = $type_labels[ $type ] ?? $type;
			if ( 'custom' === $type ) {
				$addr   = substr( $key, strlen( 'custom:' ) );
				$label .= $addr ? ' ' . $addr : '';
			}

			$uncond = array();
			$cond   = array();
			foreach ( $list as $t ) {
				if ( empty( $t['cond_tax'] ) || empty( $t['cond_value'] ) ) {
					$uncond[] = $t;
				} else {
					$cond[] = $t;
				}
			}

			if ( count( $uncond ) >= 2 ) {
				$notices[] = sprintf(
					'Mehrfachversand an %s: %s haben alle keine Bedingung – bei jeder Anmeldung werden sie alle verschickt.',
					$label, $names( $uncond )
				);
			}
			if ( $uncond && $cond ) {
				$notices[] = sprintf(
					'Überschneidung bei %s: %s wird bei jeder Anmeldung verschickt; trifft zusätzlich die Bedingung von %s zu, gehen mehrere Mails an denselben Empfänger. Falls ungewollt: der Benachrichtigung ohne Bedingung die gegenteilige Bedingung („nicht hat“) geben.',
					$label, $names( $uncond ), $names( $cond )
				);
			}
			if ( ! $uncond && $cond ) {
				$covered = false;
				$dupes   = array();
				$n       = count( $cond );
				for ( $i = 0; $i < $n; $i++ ) {
					for ( $j = $i + 1; $j < $n; $j++ ) {
						$a = $cond[ $i ];
						$b = $cond[ $j ];
						if ( $a['cond_tax'] !== $b['cond_tax'] || $a['cond_value'] !== $b['cond_value'] ) {
							continue;
						}
						if ( ( $a['cond_op'] ?? 'is' ) === ( $b['cond_op'] ?? 'is' ) ) {
							$dupes = array( $a, $b );
						} else {
							$covered = true; // komplementäres Paar: hat / nicht hat auf denselben Wert
						}
					}
				}
				if ( $dupes ) {
					$notices[] = sprintf(
						'Doppelte Bedingung bei %s: %s haben dieselbe Bedingung und werden immer gemeinsam verschickt.',
						$label, $names( $dupes )
					);
				}
				if ( ! $covered ) {
					$notices[] = sprintf(
						'Lücke möglich bei %s: Alle Benachrichtigungen an diesen Empfänger (%s) haben Bedingungen. Anmeldungen, bei denen keine davon zutrifft, lösen keine Mail an ihn aus. Falls ungewollt: ein Paar mit „hat“ / „nicht hat“ auf denselben Wert anlegen.',
						$label, $names( $cond )
					);
				}
			}
		}
		return $notices;
	}

	/** Eine Benachrichtigung speichern (Bearbeiten-Maske) */
	public static function save_trigger() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		$id = (int) ( $_POST['id'] ?? 0 );
		check_admin_referer( 'bi_save_trigger_' . $id );

		$row = wp_unslash( $_POST );

		$data = array(
			'id'             => $id,
			'active'         => ! empty( $row['active'] ) ? 1 : 0,
			'name'           => sanitize_text_field( $row['name'] ?? '' ),
			'type'           => in_array( $row['type'] ?? '', array( 'geschaeftsstelle', 'teilnehmer', 'bildungszentrum', 'ansprechpartner', 'custom' ), true ) ? $row['type'] : 'custom',
			'recipient'      => sanitize_text_field( $row['recipient'] ?? '' ),
			'from'           => sanitize_text_field( $row['from'] ?? '' ),
			'subject'        => sanitize_text_field( $row['subject'] ?? '' ),
			'body'           => sanitize_textarea_field( $row['body'] ?? '' ),
			'cond_tax'       => sanitize_text_field( $row['cond_tax'] ?? '' ),
			'cond_value'     => sanitize_text_field( $row['cond_value'] ?? '' ),
			'cond_op'        => ( 'not' === ( $row['cond_op'] ?? 'is' ) ) ? 'not' : 'is',
			'schedule'       => ( 'weekly' === ( $row['schedule'] ?? 'instant' ) ) ? 'weekly' : 'instant',
			'digest_subject' => sanitize_text_field( $row['digest_subject'] ?? '' ),
			'digest_intro'   => sanitize_textarea_field( $row['digest_intro'] ?? '' ),
		);

		$id  = self::put_trigger( $data );
		$msg = sprintf(
			'%s gespeichert (%s).',
			$data['name'] !== '' ? '„' . $data['name'] . '"' : 'Benachrichtigung',
			$data['active'] ? 'aktiv' : 'inaktiv'
		);

		// „Speichern" bleibt in der Maske, „Speichern und zurück" geht in die Übersicht.
		$after = sanitize_key( wp_unslash( $_POST['bi_after'] ?? 'stay' ) );
		if ( 'list' === $after ) {
			self::redirect_msg( $msg );
		}
		self::redirect_msg( $msg, array( 'action' => 'edit', 'id' => $id ) );
	}

	/** Darstellung (HTML-Fassung an/aus) – eigene Karte im Hero */
	public static function save_format() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_format' );

		update_option( self::FORMAT_OPTION, ! empty( $_POST['mail_format_html'] ) ? 'html' : 'plain' );
		self::redirect_msg( 'Darstellung gespeichert: ' . ( self::html_enabled() ? 'HTML mit Text-Fallback' : 'nur Text' ) . '.' );
	}

	/** ---------- Zeilen- und Sammelaktionen der Listentabelle ---------- */

	/** Link einer Zeilenaktion inkl. Nonce */
	public static function row_action_url( $do, $id ) {
		return wp_nonce_url(
			self::page_url( array( 'bi_do' => $do, 'id' => (int) $id ) ),
			'bi_row_' . $do . '_' . (int) $id
		);
	}

	/**
	 * Läuft auf admin_init, also vor jeder Ausgabe – nur so ist nach der Aktion ein
	 * Redirect möglich (und ein erneutes Absenden beim Neuladen ausgeschlossen).
	 */
	public static function handle_actions() {
		if ( ! is_admin() || ! self::is_page() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Sammelaktion aus der Listentabelle
		if ( ! empty( $_POST['bi_bulk'] ) ) {
			check_admin_referer( 'bulk-' . self::TABLE_PLURAL );

			$do = sanitize_key( wp_unslash( $_POST['action'] ?? '-1' ) );
			if ( '-1' === $do || '' === $do ) {
				$do = sanitize_key( wp_unslash( $_POST['action2'] ?? '-1' ) );
			}
			$ids = array_map( 'intval', (array) ( $_POST['bi_ids'] ?? array() ) );
			$ids = array_filter( $ids );

			if ( ! $ids || ! in_array( $do, array( 'activate', 'deactivate', 'delete' ), true ) ) {
				return;
			}

			$n = 0;
			foreach ( $ids as $id ) {
				if ( 'delete' === $do ) {
					$n += self::delete_trigger( $id ) ? 1 : 0;
				} else {
					$n += self::set_active( $id, 'activate' === $do ) ? 1 : 0;
				}
			}

			$labels = array( 'activate' => 'aktiviert', 'deactivate' => 'deaktiviert', 'delete' => 'gelöscht' );
			self::redirect_msg( sprintf( '%d Benachrichtigung(en) %s.', $n, $labels[ $do ] ) );
		}

		// Einzelne Zeilenaktion
		$do = isset( $_GET['bi_do'] ) ? sanitize_key( wp_unslash( $_GET['bi_do'] ) ) : '';
		if ( ! in_array( $do, array( 'activate', 'deactivate', 'delete', 'duplicate' ), true ) ) {
			return;
		}
		$id = (int) ( $_GET['id'] ?? 0 );
		check_admin_referer( 'bi_row_' . $do . '_' . $id );

		$t    = self::get_trigger( $id );
		$name = $t && $t['name'] !== '' ? '„' . $t['name'] . '"' : 'Benachrichtigung';

		switch ( $do ) {
			case 'delete':
				self::delete_trigger( $id );
				self::redirect_msg( $name . ' gelöscht.' );
				break;
			case 'duplicate':
				$copy = self::duplicate_trigger( $id );
				if ( $copy ) {
					self::redirect_msg( $name . ' dupliziert – die Kopie ist zunächst inaktiv.', array( 'action' => 'edit', 'id' => $copy ) );
				}
				self::redirect_msg( 'Die Benachrichtigung gibt es nicht mehr.' );
				break;
			default:
				self::set_active( $id, 'activate' === $do );
				self::redirect_msg( $name . ( 'activate' === $do ? ' aktiviert.' : ' deaktiviert.' ) );
		}
	}

	/** ---------- Hero-Karte: Wöchentlicher Versand ---------- */

	private static function schedule_box() {
		$s       = self::get_schedule();
		$days    = self::weekday_labels();
		$summary = self::queue_summary();
		$total   = array_sum( $summary );
		$next    = wp_next_scheduled( self::CRON_HOOK );

		$weekly_triggers = 0;
		foreach ( self::get_triggers() as $t ) {
			if ( ! empty( $t['active'] ) && 'weekly' === self::schedule_of( $t ) ) {
				$weekly_triggers++;
			}
		}

		ob_start();
		?>
		<div class="bi-card bi-card--sched">
			<h2>Wöchentlicher Versand</h2>
			<p class="description">Zeitpunkt für alle Benachrichtigungen auf <strong>„Wöchentliche Zusammenfassung"</strong>.
				Gesammelt wird je Benachrichtigung <em>und</em> Empfänger – jede Geschäftsstelle bekommt also nur
				ihre eigenen Anmeldungen.
				<?php if ( ! $weekly_triggers ) : ?>
					<br><em>Derzeit ist keine aktive Benachrichtigung auf wöchentlich gestellt – der Termin bleibt ohne Wirkung.</em>
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bi-card__form">
				<input type="hidden" name="action" value="bi_save_schedule">
				<?php wp_nonce_field( 'bi_save_schedule' ); ?>

				<div class="bi-card__row">
					<label class="bi-card__label" for="bi_sched_weekday">Versandtermin</label>
					<select id="bi_sched_weekday" name="sched_weekday">
						<?php foreach ( $days as $num => $lbl ) : ?>
							<option value="<?php echo (int) $num; ?>" <?php selected( $s['weekday'], $num ); ?>><?php echo esc_html( $lbl ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="sched_hour">
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<option value="<?php echo $h; ?>" <?php selected( $s['hour'], $h ); ?>><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
						<?php endfor; ?>
					</select>
					:
					<select name="sched_minute">
						<option value="0" <?php selected( $s['minute'], 0 ); ?>>00</option>
						<option value="30" <?php selected( $s['minute'], 30 ); ?>>30</option>
					</select>
					Uhr
					<p class="description">Nächster Lauf:
						<strong><?php echo $next ? esc_html( wp_date( 'l, d.m.Y H:i', $next ) . ' Uhr' ) : 'nicht geplant'; ?></strong>.
						WP-Cron läuft nur, wenn die Seite besucht wird – der tatsächliche Versand kann sich dadurch
						um einige Minuten verschieben.</p>
				</div>

				<div class="bi-card__row">
					<span class="bi-card__label">Warteschlange</span>
					<?php if ( $total ) : ?>
						<strong><?php echo (int) $total; ?> Anmeldung(en)</strong> warten auf die nächste Zusammenfassung:
						<ul class="bi-card__list">
							<?php foreach ( $summary as $name => $cnt ) : ?>
								<li><?php echo esc_html( $name ); ?> – <?php echo (int) $cnt; ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<span class="bi-muted">Leer – derzeit wartet keine Anmeldung auf den Sammelversand.</span>
					<?php endif; ?>
				</div>

				<div class="bi-card__actions">
					<button type="submit" name="bi_do" value="save" class="button button-secondary">Termin speichern</button>
					<button type="submit" name="bi_do" value="flush" class="button"<?php disabled( ! $total ); ?>>Warteschlange jetzt versenden</button>
				</div>
				<p class="description">„Jetzt versenden" leert die Warteschlange sofort und verschiebt den regulären Termin nicht.</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function save_schedule() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_schedule' );

		$opt = array(
			'weekday' => max( 1, min( 7, (int) ( $_POST['sched_weekday'] ?? 1 ) ) ),
			'hour'    => max( 0, min( 23, (int) ( $_POST['sched_hour'] ?? 8 ) ) ),
			'minute'  => ( (int) ( $_POST['sched_minute'] ?? 0 ) >= 30 ) ? 30 : 0,
		);
		update_option( self::SCHED_OPTION, $opt );
		self::reschedule_cron();

		$do = sanitize_text_field( wp_unslash( $_POST['bi_do'] ?? 'save' ) );

		if ( 'flush' === $do ) {
			$res = self::flush_queue();
			self::redirect_msg( sprintf(
				'%d Anmeldung(en) in %d Zusammenfassungs-Mail(s) versendet. Nächster regulärer Lauf: %s.',
				$res['items'],
				$res['mails'],
				self::schedule_label()
			) );
		}

		self::redirect_msg( 'Versandtermin gespeichert: ' . self::schedule_label() . '.' );
	}

	/** ---------- Hero-Karte: Test-Versand ---------- */

	/** EIN Formular – Test-Modus + Testadresse (persistent) + Soforttest */
	private static function test_box() {
		$test    = self::get_test();
		$address = $test['address'] ?: wp_get_current_user()->user_email;

		ob_start();
		?>
		<div class="bi-card bi-card--test">
			<h2>Test-Versand</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bi-card__form">
				<input type="hidden" name="action" value="bi_save_test">
				<?php wp_nonce_field( 'bi_save_test' ); ?>

				<div class="bi-card__row">
					<label><input type="checkbox" name="test_enabled" value="1" <?php checked( ! empty( $test['enabled'] ) ); ?>>
						<strong>Test-Modus aktiv</strong></label>
					<p class="description">Solange aktiv, gehen <em>alle</em> Benachrichtigungen (auch bei echten Anmeldungen)
						ausschließlich an die Testadresse statt an echte Empfänger; der Betreff bekommt „[TEST]" vorangestellt.
						Wöchentliche Benachrichtigungen landen weiterhin erst in der Warteschlange – nur eben mit der
						Testadresse als Empfänger.</p>
				</div>

				<div class="bi-card__row">
					<label class="bi-card__label" for="bi_test_address">Testadresse</label>
					<input type="email" id="bi_test_address" name="test_address" value="<?php echo esc_attr( $address ); ?>" class="regular-text" placeholder="test@example.de">
				</div>

				<div class="bi-card__actions">
					<button type="submit" name="bi_do" value="save" class="button button-secondary">Speichern</button>
					<button type="submit" name="bi_do" value="send" class="button button-primary">Speichern &amp; Testmail senden</button>
				</div>
				<p class="description">„Testmail senden" verschickt zu allen <strong>aktiven</strong> Benachrichtigungen je eine
					Beispiel-Mail (mit Testdaten, Bedingungen werden ignoriert) an die gespeicherte Testadresse – auch zu den
					wöchentlichen, damit du Betreff und Text sofort siehst. Wie die Sammelmail aussieht, zeigt
					„Warteschlange jetzt versenden".</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/** ---------- Hero-Karte: Darstellung ---------- */

	private static function format_box() {
		ob_start();
		?>
		<div class="bi-card bi-card--format">
			<h2>Darstellung</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bi-card__form">
				<input type="hidden" name="action" value="bi_save_format">
				<?php wp_nonce_field( 'bi_save_format' ); ?>

				<div class="bi-card__row">
					<label><input type="checkbox" name="mail_format_html" value="1" <?php checked( self::html_enabled() ); ?>>
						<strong>Gestaltete Mails senden (HTML)</strong></label>
					<p class="description">Jede Mail geht dann in zwei Fassungen raus: gestaltet als HTML und zusätzlich als
						reiner Text. Mailprogramme, die kein HTML anzeigen (oder es abschalten), nehmen automatisch die
						Textfassung – es geht also nichts verloren. Ohne diese Option wird nur die Textfassung verschickt;
						die Steuerzeichen werden auch dann entfernt.</p>
				</div>

				<div class="bi-card__actions">
					<button type="submit" class="button button-secondary">Darstellung speichern</button>
				</div>
				<p class="description">Aktuell: <strong><?php echo self::html_enabled() ? 'HTML mit Text-Fallback' : 'nur Text'; ?></strong>.</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function save_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_test' );

		$opt = array(
			'enabled' => ! empty( $_POST['test_enabled'] ) ? 1 : 0,
			'address' => sanitize_email( wp_unslash( $_POST['test_address'] ?? '' ) ),
		);
		update_option( self::TEST_OPTION, $opt );

		$do = sanitize_text_field( wp_unslash( $_POST['bi_do'] ?? 'save' ) );

		if ( 'send' === $do ) {
			if ( ! is_email( $opt['address'] ) ) {
				self::redirect_msg( 'Bitte zuerst eine gültige Testadresse eintragen.' );
			}
			$sent = self::dispatch( self::sample_submission( $opt['address'] ), $opt['address'] );
			self::redirect_msg( sprintf(
				'%d Testmail(s) an %s gesendet (Posteingang/Spam prüfen; bei 0 → SMTP/Mailversand prüfen). Test-Modus: %s.',
				$sent,
				$opt['address'],
				$opt['enabled'] ? 'AKTIV' : 'inaktiv'
			) );
		}

		$msg = $opt['enabled']
			? ( is_email( $opt['address'] )
				? 'Gespeichert. Test-Modus AKTIV – alle Mails gehen an ' . $opt['address'] . '.'
				: 'Test-Modus aktiviert, aber es fehlt eine gültige Testadresse!' )
			: 'Gespeichert. Test-Modus inaktiv.';
		self::redirect_msg( $msg );
	}

	/** Beispiel-Anmeldung mit Testdaten; nimmt das neueste Seminar für Seminar-Platzhalter */
	private static function sample_submission( $to ) {
		$latest = get_posts( array( 'post_type' => BI_CPT, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'publish' ) );
		$sid    = $latest ? (int) $latest[0] : 0;

		$data = array(
			'vorname'         => 'Max',
			'nachname'        => 'Mustermann',
			'strasse'         => 'Musterstraße 1',
			'plz'             => '01067',
			'ort'             => 'Dresden',
			'telefon'         => '0123 456789',
			'email'           => $to,
			'mitgliedsnummer' => '1234567',
			'betrieb'         => 'Muster GmbH',
			'betrieb_strasse' => 'Werkstraße 2',
			'betrieb_plz'     => '01067',
			'betrieb_ort'     => 'Dresden',
			'betrieb_email'   => 'personal@muster-gmbh.de',
			'freistellung'    => 'Bildungsurlaub',
			'bemerkungen'     => 'Dies ist eine automatische Testanmeldung.',
		);

		return array(
			'seminar_id' => $sid,
			'vorname'    => 'Max',
			'nachname'   => 'Mustermann',
			'email'      => $to,
			'telefon'    => '0123 456789',
			'betrieb'    => 'Muster GmbH',
			'plz'        => '01067', // betriebliche PLZ -> Geschäftsstellen-Lookup
			'nachricht'  => 'Dies ist eine automatische Testanmeldung.',
			'data'       => $data,
		);
	}

	/**
	 * Zurück zur Seite „Mail-Benachrichtigungen" mit Erfolgsmeldung.
	 *
	 * @param string $msg  Meldung.
	 * @param array  $args Zusätzliche Parameter, z. B. zurück in die Bearbeiten-Maske.
	 */
	private static function redirect_msg( $msg, $args = array() ) {
		wp_safe_redirect( self::page_url( array_merge( $args, array( 'bi_msg' => rawurlencode( $msg ) ) ) ) );
		exit;
	}
}
