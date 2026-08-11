<?php
/**
 * Plugin Name:       Bildungsprogramm
 * Plugin URI:        https://bildung.igmetall.de/
 * Description:        Eigenständiges Seminar-/Veranstaltungssystem: Such- & Filterleiste, CSV-Import für Präsenz- und Online-Seminare sowie PLZ-Geschäftsstellen, Anmeldeformular mit konfigurierbaren Mail-Triggern. Unabhängig von Formidable.
 * Version:           1.43.0
 * Author:            IG Metall Bildung
 * Text Domain:       bi-seminarsuche
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * ============================================================
 *  Übersicht der Module (alle im Ordner includes/):
 *    class-bi-cpt.php          – CPT "bi_seminar" + Taxonomien + Editier-Metabox
 *    class-bi-online.php        – CPT "bi_online" (Online-Seminare) + Anmelde-Weiche
 *    class-bi-plz.php          – Tabelle wp_bi_plz, Lookup, CSV-Import
 *    class-bi-import.php        – Seminar-CSV-Import mit Spalten-Mapping (beide Post-Types)
 *    class-bi-registration.php  – Anmeldeformular [bi_anmeldung] + Tabelle wp_bi_anmeldungen
 *    class-bi-mailer.php        – Mail-Trigger-Engine (sofort oder wöchentlich gesammelt) + Einstellungsseite
 *    class-bi-mail-table.php    – Listentabelle der Mail-Benachrichtigungen (wird bei Bedarf geladen)
 *    class-bi-tracking.php      – Kampagnen-Links (Newsletter) + Auswertung Klick → Anmeldung
 *    class-bi-filter.php        – Such-/Filterleiste [bi_seminarsuche] + Suchmaske [bi_suchmaske]
 *    class-bi-kacheln.php       – Marketing-Kacheln [bi_kacheln] / [bi_kachel]
 *    class-bi-pdf.php           – Seminardetails als PDF-Anhang der Anmeldemails
 *    class-bi-beschluss.php     – Beschlussvorlage nach § 37 Abs. 6 BetrVG als Word-Datei
 *    class-bi-docx.php          – minimaler Word-Schreiber (.docx) ohne Fremdbibliothek
 *    class-bi-admin.php         – Admin-Menü-Struktur
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direktaufruf verhindern
}

define( 'BI_VERSION', '1.43.0' );
define( 'BI_DB_VERSION', '4' ); // Schema-Version der eigenen Tabellen (Upgrade via dbDelta)
define( 'BI_FILE', __FILE__ );
define( 'BI_PATH', plugin_dir_path( __FILE__ ) );
define( 'BI_URL', plugin_dir_url( __FILE__ ) );
define( 'BI_CPT', 'bi_seminar' );          // Post-Type-Slug (Präsenz-Seminare)
define( 'BI_ONLINE', 'bi_online' );        // Post-Type-Slug (Online-Seminare)
define( 'BI_TAX_ORT', 'bi_ort' );          // Bildungszentrum / Seminarort bzw. Veranstalter*in
define( 'BI_TAX_THEMA', 'bi_handlungsfeld' );
define( 'BI_TAX_ZIEL', 'bi_zielgruppe' );
define( 'BI_TAX_FREI', 'bi_freistellung' );
define( 'BI_TAX_PROGRAMM', 'bi_programm' );  // Programmjahr (z. B. 2026) zur Trennung der Jahrgänge

/** Module laden */
require_once BI_PATH . 'includes/class-bi-cpt.php';
require_once BI_PATH . 'includes/class-bi-online.php';
require_once BI_PATH . 'includes/class-bi-plz.php';
require_once BI_PATH . 'includes/class-bi-import.php';
require_once BI_PATH . 'includes/class-bi-registration.php';
require_once BI_PATH . 'includes/class-bi-mailer.php';
require_once BI_PATH . 'includes/class-bi-tracking.php';
require_once BI_PATH . 'includes/class-bi-filter.php';
require_once BI_PATH . 'includes/class-bi-kacheln.php';
require_once BI_PATH . 'includes/class-bi-detail.php';
require_once BI_PATH . 'includes/class-bi-pdf.php';
require_once BI_PATH . 'includes/class-bi-beschluss.php';
require_once BI_PATH . 'includes/class-bi-settings.php';
require_once BI_PATH . 'includes/class-bi-admin.php';

/** DB-Schema bei Plugin-Update aktualisieren (Aktivierungshook läuft bei Updates nicht) */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'bi_db_version' ) !== BI_DB_VERSION ) {
		BI_PLZ::create_table();
		BI_Registration::create_table();
		BI_Tracking::create_tables();
		update_option( 'bi_db_version', BI_DB_VERSION );
	}
}, 5 );

/** Module initialisieren (Hooks registrieren) */
add_action( 'plugins_loaded', function () {
	BI_CPT::init();
	BI_Online::init();
	BI_PLZ::init();
	BI_Import::init();
	BI_Registration::init();
	BI_Mailer::init();
	BI_Tracking::init();
	BI_Filter::init();
	BI_Kacheln::init();
	BI_Detail::init();
	BI_PDF::init();
	BI_Settings::init();
	BI_Admin::init();
} );

/** ---------- Aktivierung / Deaktivierung ---------- */

register_activation_hook( __FILE__, 'bi_activate' );
function bi_activate() {
	// Datenbanktabellen anlegen
	BI_PLZ::create_table();
	BI_Registration::create_table();
	BI_Tracking::create_tables();

	// CPTs + Taxonomien registrieren, damit Rewrite-Regeln stimmen
	BI_Online::register();
	BI_CPT::register();
	flush_rewrite_rules();

	// Standard-Mail-Trigger anlegen, falls noch keine vorhanden
	BI_Mailer::seed_default_triggers();

	// Cron-Jobs einplanen: wöchentliche Anmelde-Zusammenfassung, tägliches
	// Aufräumen der Tracking-Ereignisse
	BI_Mailer::ensure_cron();
	BI_Tracking::ensure_cron();
}

register_deactivation_hook( __FILE__, 'bi_deactivate' );
function bi_deactivate() {
	flush_rewrite_rules();

	// Wochen-Cron abbestellen; die Warteschlange bleibt erhalten und wird nach
	// erneuter Aktivierung mit dem nächsten Lauf abgearbeitet.
	BI_Mailer::clear_cron();
	BI_Tracking::clear_cron();
}

/** Hilfsfunktion: einheitlicher Tabellenname mit Präfix */
function bi_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'bi_' . $name;
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
