<?php
/**
 * Plugin Name:       Bildungsprogramm
 * Plugin URI:        https://bildung.igmetall.de/
 * Description:        Eigenständiges Seminar-/Veranstaltungssystem: Such- & Filterleiste, CSV-Import für Präsenz- und Online-Seminare sowie PLZ-Geschäftsstellen, Anmeldeformular mit konfigurierbaren Mail-Triggern. Unabhängig von Formidable.
 * Version:           1.126.1
 * Author:            IG Metall Bildung
 * Text Domain:       bi-seminarsuche
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * ============================================================
 *  Übersicht der Module (alle im Ordner includes/):
 *    class-bi-icons.php         – Piktogramme der Detailseiten als Inline-SVG
 *    class-bi-felder.php        – eigene Datenfelder anlegen/umbenennen/löschen
 *    class-bi-reihen.php        – Ausbildungsreihen: CPT bi_reihe + Zuordnung der Termine, Reihenseite, [bi_reihen]
 *    class-bi-cpt.php          – CPT "bi_seminar" + Taxonomien + Editier-Metabox
 *    class-bi-online.php        – CPT "bi_online" (Online-Seminare) + Anmelde-Weiche
 *    class-bi-plz.php          – Tabelle wp_bi_plz, Lookup, CSV-Import
 *    class-bi-import.php        – Seminar-CSV-Import mit Spalten-Mapping (beide Post-Types)
 *    class-bi-datenpflege.php   – Arbeitsmenge filtern, CSV-/JSON-Export, Paket-Import
 *    class-bi-anmeldefelder.php – Feld-Bestand des Anmeldeformulars (Kernfelder + eigene)
 *    class-bi-formulare.php     – mehrere Anmeldeformulare: Seiten, Feldauswahl, Zuordnung
 *    class-bi-registration.php  – Anmeldeformular [bi_anmeldung] + Tabelle wp_bi_anmeldungen
 *    class-bi-mailer.php        – Mail-Trigger-Engine (sofort oder wöchentlich gesammelt) + Einstellungsseite
 *    class-bi-mail-table.php    – Listentabelle der Benachrichtigungen (wird bei Bedarf geladen)
 *    class-bi-tracking.php      – Kampagnen-Links (Newsletter) + Auswertung Klick → Anmeldung
 *    class-bi-retention.php     – Aufbewahrungsfrist der Anmeldungen (Löschen/Anonymisieren)
 *    class-bi-ampel.php         – Verfügbarkeits-Ampel: Tabelle, Nachschlagen, Ausgabe, Cron
 *    class-bi-ampel-crawler.php – holt die Ampelzustände von modules.igmetall.de
 *    class-bi-ampel-parser.php  – reines HTML-Parsing der Termintabelle (ohne WordPress)
 *    class-bi-filter.php        – Such-/Filterleiste [bi_seminarsuche] + Suchmaske [bi_suchmaske] + Autovervollständigung
 *    class-bi-suche.php         – Suchindex (wp_bi_suchindex), Wortschatz, Tippfehler-Korrektur
 *    class-bi-kacheln.php       – Marketing: Kacheln [bi_kachel] / [bi_kacheln] / [bi_kachel_reihen] und Listenansicht [bi_liste]
 *    class-bi-pdf.php           – Seminardetails als PDF-Anhang der Anmeldemails
 *    class-bi-beschluss.php     – Beschlussvorlage nach § 37 Abs. 6 BetrVG als Word-Datei
 *    class-bi-docx.php          – minimaler Word-Schreiber (.docx) ohne Fremdbibliothek
 *    class-bi-embed.php         – Einbettungsmodus: Seiten ohne Theme-Rahmen für fremde iframes
 *    class-bi-sync.php          – Abgleich mehrerer Installationen (Sprockhövel/Berlin → Zentrale)
 *    class-bi-cache.php         – Seiten-Cache leeren, wenn sich Seminardaten ändern
 *    class-bi-admin.php         – Admin-Menü-Struktur
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direktaufruf verhindern
}

define( 'BI_VERSION', '1.126.1' );
define( 'BI_DB_VERSION', '8' ); // Schema-Version der eigenen Tabellen (Upgrade via dbDelta)
define( 'BI_FILE', __FILE__ );
define( 'BI_PATH', plugin_dir_path( __FILE__ ) );
define( 'BI_URL', plugin_dir_url( __FILE__ ) );
define( 'BI_CPT', 'bi_seminar' );          // Post-Type-Slug (Präsenz-Seminare)
define( 'BI_ONLINE', 'bi_online' );        // Post-Type-Slug (Online-Seminare)
define( 'BI_TAX_ORT', 'bi_ort' );          // Bildungszentrum / Seminarort bzw. Veranstalter*in
// Heißt in der Oberfläche „Themenfeld". Der Slug bleibt bewusst „bi_handlungsfeld":
// er steht so in der Datenbank (term_taxonomy), in gespeicherten Filter-Links und in
// den Permalinks. Ein Umbenennen wäre eine Datenwanderung, kein Wortwechsel.
define( 'BI_TAX_THEMA', 'bi_handlungsfeld' );
define( 'BI_TAX_ZIEL', 'bi_zielgruppe' );
define( 'BI_TAX_FREI', 'bi_freistellung' );
define( 'BI_TAX_PROGRAMM', 'bi_programm' );  // Programmjahr (z. B. 2026) zur Trennung der Jahrgänge

/**
 * ============================================================
 *  Wer darf das Bildungsprogramm bedienen?
 * ============================================================
 *
 * Bis Version 1.111.0 hingen alle Plugin-Seiten und -Aktionen an
 * `manage_options`. Das ist die Berechtigung für die WordPress-Verwaltung
 * selbst und faktisch Administratoren vorbehalten – Redakteur:innen kamen
 * damit nicht einmal ins Menü, obwohl sie Seminare pflegen sollen.
 *
 * Der naheliegende Weg wäre gewesen, der Rolle „Redakteur" einfach
 * `manage_options` zu geben. Das wäre ein grober Fehler: Damit dürften
 * Redakteur:innen auch Permalinks, Startseite, Sprache, Benutzerrechte und
 * die Einstellungsseiten sämtlicher anderer Plugins verändern. Die
 * Berechtigung sagt „darf WordPress verwalten", nicht „darf dieses Plugin
 * bedienen".
 *
 * Deshalb hat das Plugin eine eigene Berechtigung: BI_CAP. Alle Seiten und
 * Aktionen prüfen ausschließlich sie. Wer sie bekommt, entscheidet der
 * Filter weiter unten.
 *
 * ── Warum ein Filter und keine Rollenänderung ────────────────────────────
 * Der übliche Weg wäre `add_cap()` beim Aktivieren des Plugins. Das schreibt
 * die Berechtigung dauerhaft in die Rollen-Tabelle der Datenbank – mit drei
 * Nachteilen, die hier alle zutreffen:
 *
 *   1. Dieses Plugin wird von Hand kopiert. Der Aktivierungs-Hook läuft
 *      dabei nicht, die Berechtigung käme also nie an.
 *   2. Was in der Datenbank steht, bleibt dort. Beim Entfernen des Plugins
 *      hinge die Berechtigung weiter an der Rolle.
 *   3. Ein Rollen-Zustand in der Datenbank kann von dem abweichen, was der
 *      Code annimmt – eine Fehlerquelle, die man nicht sieht.
 *
 * Der Filter `user_has_cap` vergibt die Berechtigung stattdessen bei jeder
 * Prüfung neu. Nichts wird gespeichert, nichts muss migriert werden, und mit
 * dem Entfernen des Plugins ist auch die Berechtigung weg.
 */
define( 'BI_CAP', 'bi_manage' );

/**
 * BI_CAP an Administrator:innen und Redakteur:innen vergeben.
 *
 * Erkannt wird nicht über den Rollennamen, sondern über zwei Berechtigungen –
 * das ist die WordPress-übliche Art und funktioniert auch bei umbenannten oder
 * selbst gebauten Rollen:
 *
 *   manage_options     → Administrator:in (und alles, was administriert).
 *                        Ohne diese Zeile würden Admins sich selbst aussperren.
 *   edit_others_posts  → Redakteur:in und höher. Autor:innen haben sie NICHT,
 *                        Redakteur:innen schon. Genau die gewünschte Grenze.
 *
 * Soll später eine weitere Rolle Zugriff bekommen, genügt es, ihr eine dieser
 * beiden Berechtigungen zu geben – oder `bi_manage` direkt, etwa über ein
 * Rollen-Plugin. Der Filter setzt bestehende Rechte nur, entzieht sie nie.
 */
add_filter( 'user_has_cap', function ( $allcaps ) {
	if ( ! empty( $allcaps['manage_options'] ) || ! empty( $allcaps['edit_others_posts'] ) ) {
		$allcaps[ BI_CAP ] = true;
	}

	return $allcaps;
} );

/** Module laden */
require_once BI_PATH . 'includes/class-bi-icons.php';
require_once BI_PATH . 'includes/class-bi-felder.php';
require_once BI_PATH . 'includes/class-bi-cpt.php';
require_once BI_PATH . 'includes/class-bi-reihen.php';
require_once BI_PATH . 'includes/class-bi-online.php';
require_once BI_PATH . 'includes/class-bi-plz.php';
require_once BI_PATH . 'includes/class-bi-import.php';
require_once BI_PATH . 'includes/class-bi-datenpflege.php';
require_once BI_PATH . 'includes/class-bi-anmeldefelder.php';
require_once BI_PATH . 'includes/class-bi-formulare.php';
require_once BI_PATH . 'includes/class-bi-registration.php';
require_once BI_PATH . 'includes/class-bi-mailer.php';
require_once BI_PATH . 'includes/class-bi-tracking.php';
require_once BI_PATH . 'includes/class-bi-retention.php';
require_once BI_PATH . 'includes/class-bi-ampel-parser.php';
require_once BI_PATH . 'includes/class-bi-ampel-crawler.php';
require_once BI_PATH . 'includes/class-bi-ampel.php';
require_once BI_PATH . 'includes/class-bi-filter.php';
require_once BI_PATH . 'includes/class-bi-suche.php';
require_once BI_PATH . 'includes/class-bi-kacheln.php';
require_once BI_PATH . 'includes/class-bi-detail.php';
require_once BI_PATH . 'includes/class-bi-pdf.php';
require_once BI_PATH . 'includes/class-bi-beschluss.php';
/**
 * Der Einbettungsmodus ist das einzige Modul, das nicht mit require_once
 * geladen wird – und dafür gibt es einen Grund aus der Praxis.
 *
 * Dieses Plugin wird von Hand ins Live-Verzeichnis kopiert. Fehlt dabei eine
 * Datei, bricht require_once die Ausführung ab: kein Frontend, kein wp-admin,
 * nur noch „Es gab einen kritischen Fehler auf deiner Website“. Genau das ist
 * beim ersten Deploy dieses Moduls passiert.
 *
 * Für die Module, die es seit jeher gibt, bleibt der harte Abbruch richtig –
 * ohne sie ist das Plugin sinnlos. Der Einbettungsmodus dagegen ist eine
 * Zutat: Fehlt er, funktioniert die Website vollständig weiter, nur die
 * iframe-Einbettung nicht. Ein Vermerk im Fehlerprotokoll ist dafür die
 * angemessene Reaktion – kein Totalausfall.
 */
$bi_embed_datei = BI_PATH . 'includes/class-bi-embed.php';
if ( file_exists( $bi_embed_datei ) ) {
	require_once $bi_embed_datei;
} else {
	error_log( 'Bildungsprogramm: includes/class-bi-embed.php fehlt – der Einbettungsmodus (iframe) bleibt aus. Datei nachträglich hochladen.' );
}
// Dieselbe Absicherung, aus demselben Grund: Fehlt die Datei nach einem
// Handdeploy, bleibt eben der Cache stehen, bis ihn jemand von Hand leert –
// eine unbequeme Folge, aber keine weiße Seite.
$bi_cache_datei = BI_PATH . 'includes/class-bi-cache.php';
if ( file_exists( $bi_cache_datei ) ) {
	require_once $bi_cache_datei;
} else {
	error_log( 'Bildungsprogramm: includes/class-bi-cache.php fehlt – der Seiten-Cache wird nach Datenänderungen NICHT mehr geleert. Datei nachträglich hochladen.' );
}
// Und noch einmal dieselbe Absicherung: Fehlt der Abgleich nach einem
// Handdeploy, laufen die Installationen eben auseinander, bis die Datei da ist –
// unschön, aber die Website bleibt online. Ein blankes require_once hätte hier
// alle drei Websites gleichzeitig getroffen.
$bi_sync_datei = BI_PATH . 'includes/class-bi-sync.php';
if ( file_exists( $bi_sync_datei ) ) {
	require_once $bi_sync_datei;
} else {
	error_log( 'Bildungsprogramm: includes/class-bi-sync.php fehlt – der Abgleich mit anderen Installationen bleibt aus. Datei nachträglich hochladen.' );
}
require_once BI_PATH . 'includes/class-bi-settings.php';
require_once BI_PATH . 'includes/class-bi-admin.php';

/** DB-Schema bei Plugin-Update aktualisieren (Aktivierungshook läuft bei Updates nicht) */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'bi_db_version' ) !== BI_DB_VERSION ) {
		BI_PLZ::create_table();
		BI_Registration::create_table();
		BI_Tracking::create_tables();
		BI_Ampel::create_table();
		BI_Suche::create_table();
		bi_migrate_bz_email();
		update_option( 'bi_db_version', BI_DB_VERSION );
	}
}, 5 );

/**
 * ============================================================
 *  Einmalige Datenübernahme: Zustelladresse vom Ansprechpartner trennen
 * ============================================================
 *
 * Bis 1.115.0 gab es am Seminar nur ein Adressfeld: `_bi_ansprechpartner_email`.
 * Es hieß „E-Mail Ansprechpartner", trug aber die Zustelladresse des
 * Bildungszentrums – dorthin gingen alle Benachrichtigungen. Zwei Rollen in
 * einem Feld, und die Beschriftung nannte die falsche.
 *
 * Seit 1.116.0 sind es zwei Felder. Diese Funktion bringt die vorhandenen Daten
 * an die richtige Stelle:
 *
 *   1. Jede vorhandene Adresse wird nach `_bi_bz_email` KOPIERT – nicht
 *      verschoben. Der Altbestand im Ansprechpartner-Feld bleibt unangetastet
 *      und wird von Hand aufgeräumt; bis dahin steht in beiden Feldern
 *      dasselbe, und nichts verhält sich anders als vorher.
 *
 *   2. Gespeicherte Mail-Trigger vom Typ `ansprechpartner` werden auf
 *      `bildungszentrum` umgeschrieben. Beide Typen stellen ohnehin gleich zu
 *      (siehe BI_Mailer::resolve_recipient) – aber ein Trigger, der in der
 *      Oberfläche „Zuständiges Bildungszentrum" anzeigt und intern etwas
 *      anderes heißt, ist eine Falle für den nächsten Umbau.
 *
 * EINE EINZIGE ABFRAGE für Schritt 1, und sie ist wiederholbar: Der LEFT JOIN
 * schreibt nur dort, wo noch nichts steht. Ein zweiter Lauf – nach einem
 * Zurücksetzen der DB-Version, nach einem Abgleich, nach einem Import – ändert
 * nichts mehr und überschreibt vor allem keine Adresse, die inzwischen von Hand
 * korrigiert wurde.
 */
function bi_migrate_bz_email() {
	global $wpdb;

	$kopiert = (int) $wpdb->query(
		"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value)
		 SELECT ap.post_id, '_bi_bz_email', ap.meta_value
		   FROM {$wpdb->postmeta} ap
		   LEFT JOIN {$wpdb->postmeta} bz
		          ON bz.post_id = ap.post_id AND bz.meta_key = '_bi_bz_email'
		  WHERE ap.meta_key = '_bi_ansprechpartner_email'
		    AND ap.meta_value <> ''
		    AND bz.meta_id IS NULL"
	);
	if ( $kopiert ) {
		wp_cache_flush(); // die Meta-Zwischenspeicher kennen die neuen Zeilen sonst nicht
	}

	$triggers   = get_option( 'bi_mail_triggers', array() );
	$umgestellt = 0;
	if ( is_array( $triggers ) ) {
		foreach ( $triggers as $i => $t ) {
			if ( is_array( $t ) && 'ansprechpartner' === ( $t['type'] ?? '' ) ) {
				$triggers[ $i ]['type'] = 'bildungszentrum';
				$umgestellt++;
			}
		}
		if ( $umgestellt ) {
			update_option( 'bi_mail_triggers', $triggers );
		}
	}

	update_option( 'bi_bz_migration', array(
		'zeit'       => current_time( 'Y-m-d H:i:s' ),
		'kopiert'    => $kopiert,
		'umgestellt' => $umgestellt,
	), false );
}

/**
 * Ergebnis der Datenübernahme melden – einmal, dann weggeklickt.
 *
 * Eine Migration, die stillschweigend jedes Seminar anfasst, gehört nicht ins
 * Fehlerprotokoll, sondern vor Augen: Auf drei Installationen läuft sie
 * dreimal, und wer nicht weiß, dass sie lief, sucht den Fehler später an der
 * falschen Stelle.
 */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( BI_CAP ) ) {
		return;
	}
	$m = get_option( 'bi_bz_migration' );
	if ( ! is_array( $m ) || ! empty( $m['gesehen'] ) ) {
		return;
	}
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=bi_bz_migration_ok' ), 'bi_bz_migration_ok' );
	printf(
		'<div class="notice notice-info"><p><strong>Bildungsprogramm: Ansprechpartner und Bildungszentrum sind jetzt getrennt.</strong><br>'
		. '%d Adressen wurden in das neue Feld <em>„E-Mail zuständiges Bildungszentrum"</em> übernommen, %d Benachrichtigungen darauf umgestellt. '
		. 'Die alten Angaben unter <em>Ansprechpartner</em> sind unverändert geblieben – ab jetzt steuern sie keinen Versand mehr.</p>'
		. '<p><a href="%s" class="button">Verstanden</a></p></div>',
		(int) $m['kopiert'],
		(int) $m['umgestellt'],
		esc_url( $url )
	);
} );

add_action( 'admin_post_bi_bz_migration_ok', function () {
	if ( ! current_user_can( BI_CAP ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	check_admin_referer( 'bi_bz_migration_ok' );
	$m = get_option( 'bi_bz_migration' );
	if ( is_array( $m ) ) {
		$m['gesehen'] = 1;
		update_option( 'bi_bz_migration', $m, false );
	}
	wp_safe_redirect( wp_get_referer() ?: admin_url() );
	exit;
} );

/** Module initialisieren (Hooks registrieren) */
add_action( 'plugins_loaded', function () {
	BI_Felder::init();
	BI_CPT::init();
	BI_Reihen::init();
	BI_Online::init();
	BI_PLZ::init();
	BI_Import::init();
	BI_Datenpflege::init();
	BI_Anmeldefelder::init();
	BI_Formulare::init();
	BI_Registration::init();
	BI_Mailer::init();
	BI_Tracking::init();
	BI_Retention::init();
	BI_Ampel::init();
	BI_Filter::init();
	BI_Suche::init();
	BI_Kacheln::init();
	BI_Detail::init();
	BI_PDF::init();
	BI_Settings::init();
	BI_Admin::init();
	// Zuletzt: Der Einbettungsmodus legt sich über die Ausgabe der anderen
	// Module (Template, Permalinks) und muss deren Hooks vorfinden.
	// class_exists: siehe die Begründung beim Laden weiter oben.
	if ( class_exists( 'BI_Embed' ) ) {
		BI_Embed::init();
	}
	// class_exists: siehe die Begründung beim Laden weiter oben.
	if ( class_exists( 'BI_Cache' ) ) {
		BI_Cache::init();
	}
	// Der Abgleich hängt sich je nach Rolle an save_post (Quelle) oder an den
	// Taktgeber (Zentrale) und muss die Beitragstypen schon kennen – deshalb
	// hier unten und nicht zwischen den Kernmodulen.
	// class_exists: siehe die Begründung beim Laden weiter oben.
	if ( class_exists( 'BI_Sync' ) ) {
		BI_Sync::init();
	}
} );

/** ---------- Aktivierung / Deaktivierung ---------- */

register_activation_hook( __FILE__, 'bi_activate' );
function bi_activate() {
	// Datenbanktabellen anlegen
	BI_PLZ::create_table();
	BI_Registration::create_table();
	BI_Tracking::create_tables();
	BI_Ampel::create_table();
	BI_Suche::create_table();

	// CPTs + Taxonomien registrieren, damit Rewrite-Regeln stimmen
	BI_Online::register();
	BI_CPT::register();
	BI_Reihen::register();
	flush_rewrite_rules();

	// Standard-Mail-Trigger anlegen, falls noch keine vorhanden
	BI_Mailer::seed_default_triggers();

	// Und die Adressen an die richtige Stelle bringen (wiederholbar, siehe dort)
	bi_migrate_bz_email();

	// Cron-Jobs einplanen: wöchentliche Anmelde-Zusammenfassung, tägliches
	// Aufräumen der Tracking-Ereignisse, Taktgeber der Verfügbarkeits-Ampel
	BI_Mailer::ensure_cron();
	BI_Tracking::ensure_cron();
	BI_Retention::ensure_cron();
	BI_Ampel::ensure_cron();
	// class_exists: siehe die Begründung beim Laden weiter oben.
	if ( class_exists( 'BI_Sync' ) ) {
		BI_Sync::ensure_cron();
	}
}

register_deactivation_hook( __FILE__, 'bi_deactivate' );
function bi_deactivate() {
	flush_rewrite_rules();

	// Wochen-Cron abbestellen; die Warteschlange bleibt erhalten und wird nach
	// erneuter Aktivierung mit dem nächsten Lauf abgearbeitet.
	BI_Mailer::clear_cron();
	BI_Tracking::clear_cron();
	BI_Retention::clear_cron();
	BI_Ampel::clear_cron();
	// class_exists: siehe die Begründung beim Laden weiter oben.
	if ( class_exists( 'BI_Cache' ) ) {
		BI_Cache::cron_abmelden();
	}
	// class_exists: siehe die Begründung beim Laden weiter oben.
	if ( class_exists( 'BI_Sync' ) ) {
		BI_Sync::clear_cron();
	}
}

/** Hilfsfunktion: einheitlicher Tabellenname mit Präfix */
function bi_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'bi_' . $name;
}

/**
 * Wert aus einem Request als Skalar lesen.
 *
 * Öffentliche Parameter dürfen als Array ankommen (?thema[]=x, bi_track[a]=1).
 * Ohne diese Prüfung landen sie in sanitize_text_field()/explode() und lösen
 * dort einen TypeError aus – also HTTP 500 auf einer öffentlichen Seite, den
 * jeder ohne Aufwand wiederholen kann. Arrays und Objekte gelten deshalb als
 * „nicht gesetzt".
 */
function bi_scalar( $value, $default = '' ) {
	if ( is_array( $value ) || is_object( $value ) || null === $value ) {
		return $default;
	}
	return (string) $value;
}

/** Skalarer, bereits unslashed Wert aus $_GET */
function bi_get( $key, $default = '' ) {
	return isset( $_GET[ $key ] ) ? bi_scalar( wp_unslash( $_GET[ $key ] ), $default ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/** Skalarer, bereits unslashed Wert aus $_POST */
function bi_post( $key, $default = '' ) {
	return isset( $_POST[ $key ] ) ? bi_scalar( wp_unslash( $_POST[ $key ] ), $default ) : $default; // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

/** Skalarer Wert aus $_COOKIE */
function bi_cookie( $key, $default = '' ) {
	return isset( $_COOKIE[ $key ] ) ? bi_scalar( wp_unslash( $_COOKIE[ $key ] ), $default ) : $default;
}

/**
 * Ein Versuch im benannten Kontingent. true = erlaubt, false = Limit erreicht.
 *
 * Das Zeitfenster startet mit dem ersten Versuch und wird durch weitere Versuche
 * NICHT verlängert – sonst könnte sich ein hartnäckig wiederholender Browser
 * (oder ein Reload-Reflex) dauerhaft selbst aussperren.
 *
 * @param string $bucket Frei gewählter Schlüssel, z. B. "anmeldung|ip|1.2.3.4".
 * @param int    $limit  Erlaubte Versuche im Fenster.
 * @param int    $window Fensterlänge in Sekunden.
 */
function bi_rate_hit( $bucket, $limit, $window ) {
	$key   = 'bi_rl_' . md5( $bucket );
	$state = get_transient( $key );
	$now   = time();

	if ( ! is_array( $state ) || empty( $state['start'] ) || $state['start'] + $window <= $now ) {
		set_transient( $key, array( 'start' => $now, 'n' => 1 ), $window );
		return true;
	}
	if ( (int) $state['n'] >= $limit ) {
		return false;
	}
	$state['n'] = (int) $state['n'] + 1;
	set_transient( $key, $state, max( 1, $state['start'] + $window - $now ) );
	return true;
}

/** Kontingent wieder freigeben (wenn ein Versuch am Ende doch nicht zählen soll) */
function bi_rate_release( $bucket ) {
	delete_transient( 'bi_rl_' . md5( $bucket ) );
}

/**
 * Adresse des Absenders – ausschließlich als Schlüssel für Kontingente, sie wird
 * nirgends gespeichert.
 *
 * Hinter Reverse-Proxy oder CDN ist REMOTE_ADDR die Proxy-Adresse; ohne den
 * X-Forwarded-For-Zweig liefen dann alle Besucher gemeinsam in dasselbe Limit.
 * Der Header ist fälschbar und deshalb kein Sicherheitsmerkmal – für das
 * Auseinanderhalten echter Nutzer genügt er, und die fälschungssichere Bremse
 * ist das globale Mailbudget in BI_Registration.
 */
function bi_client_ip() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	$fwd = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? (string) $_SERVER['HTTP_X_FORWARDED_FOR'] : '';
	if ( '' !== $fwd ) {
		$first = trim( explode( ',', $fwd )[0] );
		if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
			$ip = $first;
		}
	}
	return (string) apply_filters( 'bi_client_ip', $ip );
}

/**
 * Alle Beitragstypen, die ein Seminar darstellen (Präsenz + Online).
 * Reihenfolge ist stabil: Präsenz zuerst.
 */
function bi_seminar_post_types() {
	return array( BI_CPT, BI_ONLINE );
}

/** Ist die Post-ID ein Seminar (Präsenz oder Online)? */
function bi_is_seminar_post( $post_id ) {
	return in_array( get_post_type( (int) $post_id ), bi_seminar_post_types(), true );
}

/** Ist die Post-ID ein Online-Seminar? */
function bi_is_online( $post_id ) {
	return BI_ONLINE === get_post_type( (int) $post_id );
}
