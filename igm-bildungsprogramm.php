<?php
/**
 * Plugin Name:       Bildungsprogramm
 * Plugin URI:        https://bildung.igmetall.de/
 * Description:        Eigenständiges Seminar-/Veranstaltungssystem: Such- & Filterleiste, CSV-Import für Veranstaltungen und PLZ-Geschäftsstellen, Anmeldeformular mit konfigurierbaren Mail-Triggern. Unabhängig von Formidable.
 * Version:           1.38.0
 * Author:            IG Metall Bildung
 * Text Domain:       bi-seminarsuche
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * ============================================================
 *  Übersicht der Module (alle im Ordner includes/):
 *    class-bi-cpt.php          – CPT "bi_seminar" + Taxonomien + Editier-Metabox
 *    class-bi-plz.php          – Tabelle wp_bi_plz, Lookup, CSV-Import
 *    class-bi-import.php        – Seminar-CSV-Import mit Spalten-Mapping
 *    class-bi-registration.php  – Anmeldeformular [bi_anmeldung] + Tabelle wp_bi_anmeldungen
 *    class-bi-mailer.php        – Mail-Trigger-Engine (sofort oder wöchentlich gesammelt) + Einstellungsseite
 *    class-bi-mail-table.php    – Listentabelle der Mail-Benachrichtigungen (wird bei Bedarf geladen)
 *    class-bi-tracking.php      – Kampagnen-Links (Newsletter) + Auswertung Klick → Anmeldung
 *    class-bi-filter.php        – Such-/Filterleiste [bi_seminarsuche]
 *    class-bi-kacheln.php       – Marketing-Kacheln [bi_kacheln] / [bi_kachel]
 *    class-bi-admin.php         – Admin-Menü-Struktur
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direktaufruf verhindern
}

define( 'BI_VERSION', '1.38.0' );
define( 'BI_DB_VERSION', '3' ); // Schema-Version der eigenen Tabellen (Upgrade via dbDelta)
define( 'BI_FILE', __FILE__ );
define( 'BI_PATH', plugin_dir_path( __FILE__ ) );
define( 'BI_URL', plugin_dir_url( __FILE__ ) );
define( 'BI_CPT', 'bi_seminar' );          // Post-Type-Slug
define( 'BI_TAX_ORT', 'bi_ort' );          // Bildungszentrum / Seminarort
define( 'BI_TAX_THEMA', 'bi_handlungsfeld' );
define( 'BI_TAX_ZIEL', 'bi_zielgruppe' );
define( 'BI_TAX_FREI', 'bi_freistellung' );
define( 'BI_TAX_PROGRAMM', 'bi_programm' );  // Programmjahr (z. B. 2026) zur Trennung der Jahrgänge

/** Module laden */
require_once BI_PATH . 'includes/class-bi-cpt.php';
require_once BI_PATH . 'includes/class-bi-plz.php';
require_once BI_PATH . 'includes/class-bi-import.php';
require_once BI_PATH . 'includes/class-bi-registration.php';
require_once BI_PATH . 'includes/class-bi-mailer.php';
require_once BI_PATH . 'includes/class-bi-tracking.php';
require_once BI_PATH . 'includes/class-bi-filter.php';
require_once BI_PATH . 'includes/class-bi-kacheln.php';
require_once BI_PATH . 'includes/class-bi-detail.php';
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
	BI_PLZ::init();
	BI_Import::init();
	BI_Registration::init();
	BI_Mailer::init();
	BI_Tracking::init();
	BI_Filter::init();
	BI_Kacheln::init();
	BI_Detail::init();
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

	// CPT + Taxonomien registrieren, damit Rewrite-Regeln stimmen
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
