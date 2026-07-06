<?php
/**
 * Custom Post Type "bi_seminar" + Taxonomien + Editier-Oberfläche.
 *
 * Datenmodell:
 *   post_title            = Seminartitel
 *   post_content          = Beschreibung (optional)
 *   Meta _bi_startdatum   = Y-m-d  (Pflicht für "buchbar" + Datumsfilter)
 *   Meta _bi_enddatum     = Y-m-d  (optional)
 *   Meta _bi_seminarnummer
 *   Meta _bi_plaetze      = int (optional, freie Plätze)
 *   Meta _bi_kosten       = Text (optional)
 *
 * Filterbare Facetten sind Taxonomien (echtes ODER über tax_query):
 *   bi_ort           Bildungszentrum / Seminarort   (Term-Meta "email" für Mail-Trigger)
 *   bi_handlungsfeld Themenfeld
 *   bi_zielgruppe    Zielgruppe (mehrfach)
 *   bi_freistellung  Freistellung (mehrfach)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_CPT {

	/** Meta-Felder: key => [label, type]. Labels entsprechen den CSV-Spalten (Alle-Termine.csv). */
	public static function meta_fields() {
		return array(
			'_bi_startdatum'     => array( 'label' => 'Startdatum', 'type' => 'date' ),
			'_bi_startuhrzeit'   => array( 'label' => 'Startuhrzeit', 'type' => 'time' ),
			'_bi_enddatum'       => array( 'label' => 'Enddatum', 'type' => 'date' ),
			'_bi_enduhrzeit'     => array( 'label' => 'Enduhrzeit', 'type' => 'time' ),
			'_bi_anreisedatum'   => array( 'label' => 'Anreisedatum', 'type' => 'date' ),
			'_bi_anreiseuhrzeit' => array( 'label' => 'Anreiseuhrzeit', 'type' => 'time' ),
			'_bi_seminarnummer'  => array( 'label' => 'Seminarnummer', 'type' => 'text' ),
			'_bi_plaetze'        => array( 'label' => 'Freie Plätze', 'type' => 'number' ),
			'_bi_kosten'         => array( 'label' => 'Kosten / Hinweis', 'type' => 'text' ),
			'_bi_themen'         => array( 'label' => 'Themen im Seminar', 'type' => 'html' ),
			'_bi_ansprechpartner'       => array( 'label' => 'Ansprechpartner', 'type' => 'text' ),
			'_bi_ansprechpartner_email' => array( 'label' => 'E-Mail Ansprechpartner', 'type' => 'email' ),
			// Flags – Default = Verhalten bei leerer CSV-Zelle
			'_bi_anmeldung_moeglich' => array( 'label' => 'Anmeldung möglich', 'type' => 'bool', 'default' => true ),
			'_bi_anzeigen'           => array( 'label' => 'Anzeigen?', 'type' => 'bool', 'default' => true ),
			'_bi_ausgebucht'         => array( 'label' => 'Ausgebucht?', 'type' => 'bool', 'default' => false ),
		);
	}

	/** Bool-Meta mit Feld-Default lesen (leer/ungesetzt -> Default aus meta_fields) */
	public static function meta_bool( $post_id, $key ) {
		$fields = self::meta_fields();
		$val    = get_post_meta( $post_id, $key, true );
		if ( '' === $val ) {
			return ! empty( $fields[ $key ]['default'] );
		}
		return '1' === (string) $val;
	}

	public static function is_visible( $post_id ) {
		return self::meta_bool( $post_id, '_bi_anzeigen' );
	}

	/**
	 * Direkt über das Formular buchbar? Berücksichtigt die Regel-Engine
	 * (BI_Settings::variant_for) und den Ausgebucht-Status.
	 */
	public static function is_bookable( $post_id ) {
		if ( self::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return false;
		}
		return 'direct' === BI_Settings::variant_for( $post_id );
	}

	/** meta_query-Fragment: nur sichtbare Seminare (Anzeigen != nein, oder Meta fehlt) */
	public static function visible_clause() {
		return array(
			'relation' => 'OR',
			array( 'key' => '_bi_anzeigen', 'value' => '1' ),
			array( 'key' => '_bi_anzeigen', 'compare' => 'NOT EXISTS' ),
		);
	}

	/** meta_query-Fragmente: nur buchbare Seminare (Anmeldung möglich UND nicht ausgebucht) */
	public static function bookable_clauses() {
		return array(
			array(
				'relation' => 'OR',
				array( 'key' => '_bi_anmeldung_moeglich', 'value' => '1' ),
				array( 'key' => '_bi_anmeldung_moeglich', 'compare' => 'NOT EXISTS' ),
			),
			array(
				'relation' => 'OR',
				array( 'key' => '_bi_ausgebucht', 'value' => '1', 'compare' => '!=' ),
				array( 'key' => '_bi_ausgebucht', 'compare' => 'NOT EXISTS' ),
			),
		);
	}

	/** Frontend-Styles auch auf der Seminar-Einzelseite laden */
	public static function enqueue_single() {
		if ( is_singular( BI_CPT ) ) {
			wp_enqueue_style( 'bi-frontend' );
		}
	}

	/** Taxonomien: slug => [label, multi] */
	public static function taxonomies() {
		return array(
			BI_TAX_ORT   => array( 'label' => 'Bildungszentrum', 'single' => 'Bildungszentrum', 'multi' => false, 'has_email' => true ),
			BI_TAX_THEMA => array( 'label' => 'Handlungsfelder', 'single' => 'Handlungsfeld', 'multi' => false, 'has_email' => false ),
			BI_TAX_ZIEL  => array( 'label' => 'Zielgruppen', 'single' => 'Zielgruppe', 'multi' => true, 'has_email' => false ),
			BI_TAX_FREI  => array( 'label' => 'Freistellungen', 'single' => 'Freistellung', 'multi' => true, 'has_email' => false ),
			BI_TAX_PROGRAMM => array( 'label' => 'Programme', 'single' => 'Programm', 'multi' => false, 'has_email' => false ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );

		// Editier-Oberfläche
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . BI_CPT, array( __CLASS__, 'save_meta' ), 10, 2 );

		// Frontend-Styles auch auf der Seminar-Einzelseite (Detailansicht: BI_Detail)
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_single' ), 20 );

		// Term-Meta "E-Mail" für Bildungszentren (Mail-Trigger an den Seminarort)
		add_action( BI_TAX_ORT . '_add_form_fields', array( __CLASS__, 'term_email_add_field' ) );
		add_action( BI_TAX_ORT . '_edit_form_fields', array( __CLASS__, 'term_email_edit_field' ) );
		add_action( 'created_' . BI_TAX_ORT, array( __CLASS__, 'save_term_email' ) );
		add_action( 'edited_' . BI_TAX_ORT, array( __CLASS__, 'save_term_email' ) );

		// Backend-Suche zusätzlich über die Seminarnummer
		add_filter( 'posts_join', array( __CLASS__, 'search_join' ), 10, 2 );
		add_filter( 'posts_where', array( __CLASS__, 'search_where' ), 10, 2 );
		add_filter( 'posts_distinct', array( __CLASS__, 'search_distinct' ), 10, 2 );

		// Spalten in der Seminar-Übersicht
		add_filter( 'manage_' . BI_CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . BI_CPT . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . BI_CPT . '_sortable_columns', array( __CLASS__, 'admin_sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_orderby' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_filter_missing' ) );
	}

	/** CPT + Taxonomien registrieren */
	public static function register() {
		register_post_type( BI_CPT, array(
			'labels' => array(
				'name'               => 'Seminare',
				'singular_name'      => 'Seminar',
				'add_new'            => 'Neues Seminar',
				'add_new_item'       => 'Neues Seminar anlegen',
				'edit_item'          => 'Seminar bearbeiten',
				'new_item'           => 'Neues Seminar',
				'view_item'          => 'Seminar ansehen',
				'search_items'       => 'Seminare durchsuchen',
				'not_found'          => 'Keine Seminare gefunden',
				'menu_name'          => 'Seminare',
			),
			'public'        => true,
			'show_in_menu'  => 'bi-seminarsuche', // unter unserem Hauptmenü
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-welcome-learn-more',
			'supports'      => array( 'title', 'editor', 'thumbnail' ),
			'has_archive'   => false,
			'rewrite'       => array( 'slug' => 'seminar' ),
		) );

		foreach ( self::taxonomies() as $slug => $cfg ) {
			$args = array(
				'labels' => array(
					'name'          => $cfg['label'],
					'singular_name' => $cfg['single'],
					'search_items'  => $cfg['label'] . ' suchen',
					'all_items'     => 'Alle ' . $cfg['label'],
					'edit_item'     => $cfg['single'] . ' bearbeiten',
					'add_new_item'  => $cfg['single'] . ' hinzufügen',
					'menu_name'     => $cfg['label'],
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => $slug ),
			);
			// Mehrfach-Taxonomien: Zuweisungsreihenfolge (= CSV-Reihenfolge) speichern
			// und bei der Ausgabe statt alphabetischer Sortierung verwenden.
			if ( ! empty( $cfg['multi'] ) ) {
				$args['sort'] = true;
				$args['args'] = array( 'orderby' => 'term_order' );
			}
			register_taxonomy( $slug, BI_CPT, $args );
		}
	}

	/** ---------- Editier-Metabox (Datum, Nummer, Plätze …) ---------- */

	public static function add_meta_box() {
		add_meta_box( 'bi_seminar_details', 'Seminar-Details', array( __CLASS__, 'render_meta_box' ), BI_CPT, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'bi_save_meta', 'bi_meta_nonce' );
		echo '<style>.bi-mb label{display:block;font-weight:600;margin:10px 0 4px}.bi-mb input,.bi-mb textarea{width:100%;max-width:340px}.bi-mb textarea{max-width:640px;min-height:120px}</style>';
		echo '<div class="bi-mb">';
		foreach ( self::meta_fields() as $key => $cfg ) {
			$val = get_post_meta( $post->ID, $key, true );
			if ( 'textarea' === $cfg['type'] || 'html' === $cfg['type'] ) {
				$hint = ( 'html' === $cfg['type'] ) ? '<br><span style="color:#666;font-weight:400">HTML erlaubt (z. B. &lt;ul&gt;&lt;li&gt;…&lt;/li&gt;&lt;/ul&gt;).</span>' : '';
				printf(
					'<label for="%1$s">%2$s%4$s</label><textarea id="%1$s" name="%1$s" rows="6">%3$s</textarea>',
					esc_attr( $key ),
					esc_html( $cfg['label'] ),
					esc_textarea( $val ),
					$hint
				);
			} elseif ( 'bool' === $cfg['type'] ) {
				$checked = ( '' === $val ) ? ! empty( $cfg['default'] ) : ( '1' === (string) $val );
				printf(
					'<label for="%1$s">%2$s</label><input type="hidden" name="%1$s" value="0"><input type="checkbox" id="%1$s" name="%1$s" value="1" style="width:auto;max-width:none"%3$s>',
					esc_attr( $key ),
					esc_html( $cfg['label'] ),
					$checked ? ' checked' : ''
				);
			} else {
				printf(
					'<label for="%1$s">%2$s</label><input type="%3$s" id="%1$s" name="%1$s" value="%4$s">',
					esc_attr( $key ),
					esc_html( $cfg['label'] ),
					esc_attr( $cfg['type'] ),
					esc_attr( $val )
				);
			}
		}
		echo '<p style="margin-top:12px;color:#666">Bildungszentrum, Handlungsfeld, Zielgruppen und Freistellungen werden rechts über die Taxonomie-Boxen gepflegt.</p>';
		echo '</div>';
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['bi_meta_nonce'] ) || ! wp_verify_nonce( $_POST['bi_meta_nonce'], 'bi_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( self::meta_fields() as $key => $cfg ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $key ] );
			switch ( $cfg['type'] ) {
				case 'html':
					$val = wp_kses_post( $raw );
					break;
				case 'textarea':
					$val = sanitize_textarea_field( $raw );
					break;
				case 'email':
					$val = sanitize_email( $raw );
					break;
				default: // text, date, time, number, bool ('0'/'1')
					$val = sanitize_text_field( $raw );
			}
			update_post_meta( $post_id, $key, $val );
		}
	}

	/** ---------- Term-Meta "E-Mail" für Bildungszentren ---------- */

	public static function term_email_add_field() {
		echo '<div class="form-field"><label for="bi_term_email">E-Mail (für Mail-Trigger an dieses Bildungszentrum)</label>';
		echo '<input type="email" name="bi_term_email" id="bi_term_email" value=""></div>';
	}

	public static function term_email_edit_field( $term ) {
		$val = get_term_meta( $term->term_id, 'email', true );
		echo '<tr class="form-field"><th><label for="bi_term_email">E-Mail</label></th>';
		echo '<td><input type="email" name="bi_term_email" id="bi_term_email" value="' . esc_attr( $val ) . '">';
		echo '<p class="description">Adresse des Bildungszentrums für den Mail-Trigger „Mail an Bildungszentrum".</p></td></tr>';
	}

	public static function save_term_email( $term_id ) {
		if ( isset( $_POST['bi_term_email'] ) ) {
			update_term_meta( $term_id, 'email', sanitize_email( wp_unslash( $_POST['bi_term_email'] ) ) );
		}
	}

	/** ---------- Übersichts-Spalten ---------- */

	public static function admin_columns( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'title' === $k ) {
				$new['bi_startdatum'] = 'Startdatum';
				$new['bi_nummer']     = 'Nummer';
			}
		}
		return $new;
	}

	public static function admin_column_content( $col, $post_id ) {
		if ( 'bi_startdatum' === $col ) {
			$d = get_post_meta( $post_id, '_bi_startdatum', true );
			echo $d ? esc_html( date_i18n( 'd.m.Y', strtotime( $d ) ) ) : '—';
		} elseif ( 'bi_nummer' === $col ) {
			echo esc_html( get_post_meta( $post_id, '_bi_seminarnummer', true ) ?: '—' );
		}
	}

	public static function admin_sortable( $cols ) {
		$cols['bi_startdatum'] = 'bi_startdatum';
		return $cols;
	}

	public static function admin_orderby( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'bi_startdatum' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', '_bi_startdatum' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Listen-Filter für die Dashboard-Hinweise: zeigt nur Seminare, denen eine
	 * bestimmte Angabe fehlt – statt aller Seminare.
	 *   ?bi_missing_start=1  -> ohne Startdatum
	 *   ?bi_missing_ap=1     -> kommende Seminare ohne Ansprechpartner-E-Mail
	 */
	public static function admin_filter_missing( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || BI_CPT !== $query->get( 'post_type' ) ) {
			return;
		}

		if ( ! empty( $_GET['bi_missing_start'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'OR',
				array( 'key' => '_bi_startdatum', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_bi_startdatum', 'value' => '', 'compare' => '=' ),
			) );
		} elseif ( ! empty( $_GET['bi_missing_ap'] ) ) {
			$query->set( 'meta_query', array(
				'relation' => 'AND',
				array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
				array(
					'relation' => 'OR',
					array( 'key' => '_bi_ansprechpartner_email', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_bi_ansprechpartner_email', 'value' => '', 'compare' => '=' ),
				),
			) );
		}
	}

	/** ---------- Backend-Suche inkl. Seminarnummer ---------- */

	/** Greift nur bei der Haupt-Suchquery der Seminar-Liste im Backend */
	private static function is_seminar_admin_search( $query ) {
		return is_admin()
			&& $query instanceof WP_Query
			&& $query->is_main_query()
			&& BI_CPT === $query->get( 'post_type' )
			&& '' !== trim( (string) $query->get( 's' ) );
	}

	public static function search_join( $join, $query ) {
		global $wpdb;
		if ( self::is_seminar_admin_search( $query ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} AS bi_sm ON ( {$wpdb->posts}.ID = bi_sm.post_id AND bi_sm.meta_key = '_bi_seminarnummer' ) ";
		}
		return $join;
	}

	public static function search_where( $where, $query ) {
		global $wpdb;
		if ( self::is_seminar_admin_search( $query ) ) {
			// Hinter die Titel-LIKE-Bedingung ein „ODER Seminarnummer LIKE …" einfügen
			$where = preg_replace(
				"/\(\s*{$wpdb->posts}\.post_title\s+LIKE\s*('[^']+')\s*\)/",
				'(' . $wpdb->posts . '.post_title LIKE \1) OR (bi_sm.meta_value LIKE \1)',
				$where
			);
		}
		return $where;
	}

	public static function search_distinct( $distinct, $query ) {
		if ( self::is_seminar_admin_search( $query ) ) {
			return 'DISTINCT';
		}
		return $distinct;
	}
}
