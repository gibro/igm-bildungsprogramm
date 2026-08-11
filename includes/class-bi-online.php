<?php
/**
 * Custom Post Type "bi_online" – Online-Seminare.
 *
 * Zweiter Beitragstyp neben den Präsenz-Seminaren (BI_CPT). Bewusst ein eigener
 * Post-Type: eigene Backend-Liste, eigenes Feldset, eigener CSV-Import. Im
 * Frontend erscheinen beide Typen in derselben Darstellung; ein Filter-Chip
 * „Seminarform" trennt sie.
 *
 * WICHTIG – geteilte Schlüssel:
 *   Für alle gemeinsamen Angaben (Datum, Uhrzeit, Seminarnummer, Plätze, Kosten,
 *   Themen, Ansprechpartner, Flags) werden dieselben Meta-Keys `_bi_*` verwendet
 *   wie bei Präsenz-Seminaren, und dieselben Taxonomien. Dadurch arbeiten
 *   BI_Mailer::build_context(), BI_PDF::seminar_data(), BI_Beschluss und
 *   BI_Tracking ohne Sonderfälle weiter. Nur die online-spezifischen Felder
 *   bekommen eigene Schlüssel.
 *
 * Feldquelle: https://bildung.igmetall.de/online-seminar-anlegen/ – bewusst
 * gekürzt um die Angaben, die im Bildungsprogramm nicht gebraucht werden
 * (siehe meta_fields()). Die Zielgruppen kommen NICHT aus jenem Formular,
 * sondern aus den vorhandenen Präsenz-Begriffen (geteilte Taxonomie bi_zielgruppe).
 *
 * Anmelde-Weiche (BI_Online::variante):
 *   ausgebucht                          -> „Ausgebucht"
 *   Teams-Webinar MIT Anmeldelink       -> externe Anmeldeseite (Microsoft)
 *   Teams-Besprechung / anderes Tool    -> internes Anmeldeformular [bi_anmeldung]
 *   (darüber hinaus greift die Regel-Engine aus BI_Settings wie bei Präsenz)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Online {

	/** Auswahl „Webinar-Tool": Schlüssel => Beschriftung */
	public static function tools() {
		return array(
			'teams_webinar' => 'Microsoft Teams – Webinar (eigene Anmeldeseite)',
			'teams_meeting' => 'Microsoft Teams – Besprechung',
			'anderes'       => 'Anderes Webinar-Tool',
		);
	}

	/** Klartext des gewählten Webinar-Tools (leer, wenn nichts gepflegt) */
	public static function tool_label( $post_id ) {
		$tools = self::tools();
		$key   = (string) get_post_meta( $post_id, '_bi_online_tool', true );
		return isset( $tools[ $key ] ) ? $tools[ $key ] : '';
	}

	/**
	 * Meta-Felder: key => [label, type]. Die Labels entsprechen den erwarteten
	 * CSV-Spalten und werden im Import als Zielfelder angeboten.
	 *
	 * Geteilt mit BI_CPT (gleiche Schlüssel!): _bi_startdatum, _bi_enddatum,
	 * _bi_startuhrzeit, _bi_enduhrzeit, _bi_seminarnummer, _bi_kosten, _bi_themen,
	 * _bi_ansprechpartner, _bi_ansprechpartner_email sowie die drei Flags.
	 *
	 * Bewusst NICHT dabei (im Gegensatz zu Präsenz-Seminaren): Anreisedatum/-zeit,
	 * Plätze, Voraussetzung, Last-Minute sowie die internen Angaben zur Einreichung.
	 */
	public static function meta_fields() {
		return array(
			'_bi_untertitel'    => array( 'label' => 'Untertitel', 'type' => 'text' ),
			'_bi_startdatum'    => array( 'label' => 'Startdatum', 'type' => 'date' ),
			'_bi_enddatum'      => array( 'label' => 'Enddatum', 'type' => 'date' ),
			'_bi_startuhrzeit'  => array( 'label' => 'Uhrzeit Seminarbeginn', 'type' => 'time' ),
			'_bi_enduhrzeit'    => array( 'label' => 'Uhrzeit Seminarende', 'type' => 'time' ),
			'_bi_seminarnummer' => array( 'label' => 'Seminarnummer', 'type' => 'text' ),
			'_bi_referenten'    => array( 'label' => 'Referent*innen', 'type' => 'text' ),
			'_bi_themen'        => array( 'label' => 'Themen im Seminar', 'type' => 'html' ),
			'_bi_kosten'        => array( 'label' => 'Kosten', 'type' => 'text' ),

			// ---- Zugang & Anmelde-Weiche ----
			'_bi_online_tool'   => array(
				'label'   => 'Webinar-Tool',
				'type'    => 'select',
				'options' => self::tools(),
				'default' => 'teams_meeting',
				'hint'    => 'Bei „Teams – Webinar" führt der Buchungs-Button auf die externe Anmeldeseite (Feld darunter).',
			),
			'_bi_anmeldelink'   => array(
				'label' => 'Anmeldelink (Teams-Webinar)',
				'type'  => 'url',
				'hint'  => 'Registrierungsseite des Teams-Webinars. Nur dann übernimmt Microsoft die Anmeldung.',
			),
			'_bi_online_link'   => array(
				'label' => 'Online-Link (öffentlich)',
				'type'  => 'url',
				'hint'  => 'NUR ausfüllen, wenn die Veranstaltung öffentlich zugänglich sein soll – der Link steht dann auf der Detailseite.',
			),

			// ---- Kontakte ----
			// Die Anmelde-Adresse IST die Adresse der Ansprechpartner*in – deshalb
			// derselbe Meta-Schlüssel wie bei Präsenz-Seminaren, nur anders beschriftet
			// (die CSV-Spalte des Anlege-Formulars heißt „Anmeldung").
			'_bi_ansprechpartner'       => array( 'label' => 'Ansprechpartner*in', 'type' => 'text' ),
			'_bi_ansprechpartner_email' => array(
				'label' => 'Anmeldung (E-Mail)',
				'type'  => 'email',
				'hint'  => 'Adresse der Ansprechpartner*in: An sie gehen die Anmeldungen, sie steht auf der Detailseite und speist den Mail-Trigger „Ansprechpartner".',
			),

			// ---- Flags: Default = Verhalten bei leerer CSV-Zelle ----
			'_bi_anmeldung_moeglich' => array( 'label' => 'Anmeldung möglich', 'type' => 'bool', 'default' => true ),
			'_bi_anzeigen'           => array( 'label' => 'Anzeigen?', 'type' => 'bool', 'default' => true ),
			'_bi_ausgebucht'         => array( 'label' => 'Ausgebucht?', 'type' => 'bool', 'default' => false ),
		);
	}

	/**
	 * Taxonomien der Online-Seminare – dieselben Slugs wie bei Präsenz, nur mit
	 * angepasster Beschriftung (bi_ort = Veranstalter*in statt Bildungszentrum).
	 * Registriert werden sie zentral in BI_CPT::register() für beide Post-Types.
	 */
	public static function taxonomies() {
		return array(
			BI_TAX_ORT      => array( 'label' => 'Veranstalter*innen', 'single' => 'Veranstalter*in', 'multi' => false, 'has_email' => true ),
			BI_TAX_THEMA    => array( 'label' => 'Handlungsfelder', 'single' => 'Handlungsfeld', 'multi' => false, 'has_email' => false ),
			BI_TAX_ZIEL     => array( 'label' => 'Zielgruppen', 'single' => 'Zielgruppe', 'multi' => true, 'has_email' => false ),
			BI_TAX_FREI     => array( 'label' => 'Freistellungen', 'single' => 'Freistellung', 'multi' => true, 'has_email' => false ),
			BI_TAX_PROGRAMM => array( 'label' => 'Programme', 'single' => 'Programm', 'multi' => false, 'has_email' => false ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/** CPT registrieren. Die Taxonomien hängt BI_CPT::register() an beide Typen. */
	public static function register() {
		register_post_type( BI_ONLINE, array(
			'labels' => array(
				'name'          => 'Online-Seminare',
				'singular_name' => 'Online-Seminar',
				'add_new'       => 'Neues Online-Seminar',
				'add_new_item'  => 'Neues Online-Seminar anlegen',
				'edit_item'     => 'Online-Seminar bearbeiten',
				'new_item'      => 'Neues Online-Seminar',
				'view_item'     => 'Online-Seminar ansehen',
				'search_items'  => 'Online-Seminare durchsuchen',
				'not_found'     => 'Keine Online-Seminare gefunden',
				'menu_name'     => 'Online-Seminare',
			),
			'public'       => true,
			'show_in_menu' => 'bi-seminarsuche',
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-video-alt2',
			// thumbnail = Referent*innen-Bild aus dem Anlege-Formular
			'supports'     => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'  => false,
			'rewrite'      => array( 'slug' => 'online-seminar' ),
		) );
	}

	/* ===================================================================
	 *  Anmelde-Weiche
	 * =================================================================== */

	/**
	 * Welche Anmeldung gilt für dieses Online-Seminar?
	 *
	 *   'ausgebucht' – keine Anmeldung möglich
	 *   'extern'     – Teams-Webinar mit eigener Anmeldeseite bei Microsoft
	 *   'offen'      – öffentlich zugänglich, keine Anmeldung nötig (nur Teilnahme-Link)
	 *   'direct'     – internes Anmeldeformular [bi_anmeldung]
	 *   'gs'         – über die Geschäftsstelle (aus der Regel-Engine)
	 */
	public static function variante( $post_id ) {
		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return 'ausgebucht';
		}
		if ( 'teams_webinar' === (string) get_post_meta( $post_id, '_bi_online_tool', true )
			&& '' !== self::anmeldelink( $post_id ) ) {
			return 'extern';
		}

		$variant = BI_Settings::variant_for( $post_id );

		// Öffentlich zugängliche Veranstaltung ohne eigene Anmeldung: Es gibt einen
		// Teilnahme-Link und das Seminar ist ausdrücklich nicht anmeldbar – dann wäre
		// die Geschäftsstellen-Suche irreführend, es zählt nur der Link.
		if ( 'gs' === $variant
			&& '' !== self::online_link( $post_id )
			&& ! BI_CPT::meta_bool( $post_id, '_bi_anmeldung_moeglich' ) ) {
			return 'offen';
		}

		return $variant;
	}

	/** Externer Anmeldelink (Teams-Webinar), geprüft; leer wenn nicht gepflegt. */
	public static function anmeldelink( $post_id ) {
		$url = trim( (string) get_post_meta( $post_id, '_bi_anmeldelink', true ) );
		return $url ? esc_url_raw( $url ) : '';
	}

	/** Öffentlicher Teilnahme-Link („Direkt teilnehmen"); leer wenn nicht gepflegt. */
	public static function online_link( $post_id ) {
		$url = trim( (string) get_post_meta( $post_id, '_bi_online_link', true ) );
		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * Online-Seminare, die als Teams-Webinar gepflegt sind, aber keinen Anmeldelink
	 * haben – dort fällt die Anmeldung still auf das interne Formular zurück.
	 * Wird im Dashboard als Hinweis ausgegeben.
	 *
	 * @return int[] Post-IDs
	 */
	public static function ohne_anmeldelink() {
		$ids = get_posts( array(
			'post_type'      => BI_ONLINE,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
				array( 'key' => '_bi_online_tool', 'value' => 'teams_webinar' ),
				array(
					'relation' => 'OR',
					array( 'key' => '_bi_anmeldelink', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_bi_anmeldelink', 'value' => '', 'compare' => '=' ),
				),
			),
		) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}
