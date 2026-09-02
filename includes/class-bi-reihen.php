<?php
/**
 * Ausbildungsreihen: Beitragstyp „bi_reihe" + Zuordnung der Termine.
 *
 * ============================================================================
 *  DAS PROBLEM
 * ============================================================================
 *  Eine Reihenseite im Bildungsprogramm (z. B. „Aufgaben der VK-Leitung",
 *  S. 76/77 im Heft 2027) trägt Texte auf drei Ebenen:
 *
 *    Reihe   Einleitung, Zielgruppe, Voraussetzungen, Freistellung,
 *            Seminarleitung – gilt für alles, gehört keinem Seminar.
 *    Teil    Titel und „Themen im Seminar" des einzelnen Bausteins.
 *    Termin  Datum, Seminarnummer, Ort – das sind die Seminare, die es
 *            als bi_seminar ohnehin schon gibt.
 *
 *  Nur die oberste Ebene ist wirklich neu. Die mittlere steckt bereits in den
 *  Terminen: Alle Termine eines Teils tragen denselben Titel und dieselben
 *  Themen. Deshalb wird auf der Reihenseite der Teil-Titel aus dem ersten
 *  Termin gelesen statt ihn ein zweites Mal zu pflegen – zwei Quellen für
 *  denselben Text laufen sonst binnen eines Jahrgangs auseinander.
 *
 * ============================================================================
 *  DIE ZUORDNUNG
 * ============================================================================
 *  Jeder Termin trägt das Feld „Teil | Reihe" (_bi_teil_reihe) in der Form
 *
 *      Teil 2 | Ausbildungsreihe Aufgaben der VK-Leitung
 *
 *  Links die Teilnummer, rechts der Reihenname. Der Reihenname IST der
 *  Schlüssel: Er wird mit dem Titel der Reihe verglichen, und taucht er zum
 *  ersten Mal auf, entsteht die Reihe als **Entwurf**. Absicht: Ohne den
 *  Einleitungstext ist eine Reihenseite wertlos, also soll sie erst öffentlich
 *  werden, wenn jemand sie geschrieben hat.
 *
 *  Eine abweichende Schreibweise erzeugt eine zweite Reihe – das ist der
 *  häufigste Fehler und der Grund für die Prüfliste in der Datenpflege.
 *
 * ============================================================================
 *  DIE AUSGABE
 * ============================================================================
 *  Zwei Ansichten nach dem Design-Handoff „Seminarreihen":
 *
 *    render()      Die Reihenseite. Kopf, Kennzahlenband, links die Inhalte
 *                  als Akkordeon und die Termine der festen Gruppen als
 *                  Zeitstrahl, rechts die Angaben, die für alle Teile gelten.
 *    shortcode()   [bi_reihen] – Kachelübersicht aller veröffentlichten Reihen.
 *
 *  Die feste Gruppe ist der Kern der Reihenseite: Man bucht keinen einzelnen
 *  Termin, sondern eine Abfolge. Deshalb steht je Gruppe ein Zeitstrahl mit
 *  einem Auswahlpunkt pro Teil, ein Ortsfilter darüber und ein
 *  Fortschrittszähler darunter – der Buchen-Button wird erst rot, wenn für
 *  jeden ausgeschriebenen Teil ein Termin gewählt ist (assets/js/reihe.js).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Reihen {

	/** Beitragstyp der Reihe */
	const CPT = 'bi_reihe';

	/** Rohwert aus der Importdatei: „Teil 2 | Reihenname" */
	const META_ROH = '_bi_teil_reihe';

	/** Daraus aufgelöst: Post-ID der Reihe, Teilnummer und Durchgang */
	const META_REIHE     = '_bi_reihe_id';
	const META_TEIL      = '_bi_teil';
	const META_DURCHGANG = '_bi_durchgang';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::CPT, array( 'BI_CPT', 'save_meta' ), 10, 2 );

		// Zuordnung nach jedem Speichern eines Seminars auffrischen – auch beim
		// Bearbeiten von Hand, nicht nur beim Import.
		foreach ( bi_seminar_post_types() as $pt ) {
			add_action( 'save_post_' . $pt, array( __CLASS__, 'nach_speichern' ), 20, 2 );
		}

		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_single' ), 20 );
		add_shortcode( 'bi_reihen', array( __CLASS__, 'shortcode' ) );

		// Spalte „Teile / Termine" in der Reihen-Übersicht
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );

		// „Reihen durchsuchen" findet auch die Seminarnummer eines Termins
		add_filter( 'posts_where', array( __CLASS__, 'search_where' ), 10, 2 );

		// Termine von der Reihe aus zuordnen (Metabox „Zugeordnete Teile und Termine")
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_ajax_bi_reihe_suche', array( __CLASS__, 'ajax_suche' ) );
		add_action( 'wp_ajax_bi_reihe_zuordnen', array( __CLASS__, 'ajax_zuordnen' ) );
	}

	/** Skript der Zuordnungs-Box – nur auf der Bearbeiten-Seite einer Reihe. */
	public static function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}
		wp_enqueue_script( 'bi-reihe-admin', BI_URL . 'assets/js/reihe-admin.js', array(), BI_VERSION, true );
		wp_localize_script( 'bi-reihe-admin', 'biReiheAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'bi_reihe_zuordnen' ),
		) );
	}

	/** Eigene Felder der Reihe (Ebene, die keinem Seminar gehört). */
	public static function meta_fields() {
		return array(
			'_bir_zielgruppe'      => array( 'label' => 'Zielgruppe', 'type' => 'textarea', 'gruppe' => 'reihe' ),
			'_bir_voraussetzungen' => array( 'label' => 'Voraussetzungen', 'type' => 'textarea', 'gruppe' => 'reihe' ),
			'_bir_freistellung'    => array( 'label' => 'Freistellung', 'type' => 'textarea', 'gruppe' => 'reihe' ),
			'_bir_leitung'         => array( 'label' => 'Seminarleitung', 'type' => 'textarea', 'gruppe' => 'reihe' ),
			'_bir_info'            => array( 'label' => 'Weitere Informationen', 'type' => 'html', 'gruppe' => 'reihe' ),
			'_bir_komplett'        => array(
				'label'   => 'Nur komplett buchbar',
				'type'    => 'bool',
				'default' => false,
				'gruppe'  => 'reihe',
				'hint'    => 'Setzt den Hinweis „Reihe nur komplett buchbar" auf die Seite.',
			),
			// Derselbe Schlüssel wie beim Seminar, damit BI_CPT::visible_clause()
			// auch für Reihen greift – eine zweite Bedeutung von „anzeigen" hätte
			// niemand im Kopf behalten.
			'_bi_anzeigen'         => array(
				'label'   => 'Auf der Website anzeigen',
				'default' => true,
				'type'    => 'bool',
				'gruppe'  => 'reihe',
				'hint'    => 'Nicht gesetzt: Die Reihe fehlt in der Übersicht [bi_reihen]. Über ihren Link bleibt sie erreichbar – so lässt sie sich gezielt einer Gruppe anbieten.',
			),
		);
	}

	public static function register() {
		register_post_type( self::CPT, array(
			'labels' => array(
				'name'          => 'Ausbildungsreihen',
				'singular_name' => 'Ausbildungsreihe',
				'add_new'       => 'Neue Reihe',
				'add_new_item'  => 'Neue Ausbildungsreihe anlegen',
				'edit_item'     => 'Ausbildungsreihe bearbeiten',
				'search_items'  => 'Reihen durchsuchen',
				'not_found'     => 'Keine Reihen gefunden',
				'menu_name'     => 'Ausbildungsreihen',
			),
			'public'       => true,
			'show_in_menu' => 'bi-seminarsuche',
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-networking',
			'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
			'has_archive'  => false,
			'rewrite'      => array( 'slug' => 'ausbildungsreihe' ),
		) );
	}

	/* ===================================================================
	 *  Zuordnung
	 * =================================================================== */

	/**
	 * Zuordnungsangabe zerlegen. Gibt null zurück, wenn der Wert die Zuordnung
	 * nicht hergibt – dann landet der Termin in der Prüfliste.
	 *
	 * Verstanden werden:
	 *
	 *     Teil 2 | Ausbildungsreihe X               ohne festen Durchgang
	 *     Reihe 1 - Teil 2 | Ausbildungsreihe X     Durchgang 1
	 *     Teil 2 | Ausbildungsreihe X | Reihe 1     gleichbedeutend
	 *
	 * ACHTUNG, DOPPELTE BEDEUTUNG VON „REIHE": Im Programmheft heißt sowohl die
	 * Ausbildungsreihe als Ganzes „Reihe" als auch der einzelne Durchgang
	 * („Termine Reihe 1", „Termine Reihe 2"). Gemeint ist Verschiedenes: rechts
	 * vom Strich steht die Ausbildungsreihe, die Zahl links bzw. am Ende
	 * bezeichnet den DURCHGANG – eine feste Gruppe, die alle Teile gemeinsam
	 * durchläuft. Im Code heißt das deshalb durchgehend „Durchgang"; in der
	 * Ausgabe steht wie im Heft „Reihe 1".
	 *
	 * Beim Lesen nachsichtig, wo es nichts kostet: „Teil 2:", „Teil 2 -",
	 * „Teil2" und Groß-/Kleinschreibung bedeuten dasselbe.
	 */
	public static function parse( $roh ) {
		$roh = trim( (string) $roh );
		if ( '' === $roh || false === strpos( $roh, '|' ) ) {
			return null;
		}
		$stuecke = array_map( 'trim', explode( '|', $roh ) );
		$links   = array_shift( $stuecke );
		$name    = trim( (string) array_shift( $stuecke ) );
		if ( '' === $name ) {
			return null;
		}

		// Links: optionaler Durchgang, dann die Teilnummer.
		$muster = '/^(?:reihe\s*(\d+)\s*[:.\x{2013}\x{2014}-]\s*)?teil\s*(\d+)\s*[:.\x{2013}\x{2014}-]?$/iu';
		if ( ! preg_match( $muster, $links, $m ) ) {
			return null;
		}
		$durchgang = ( isset( $m[1] ) && '' !== $m[1] ) ? (int) $m[1] : 0;

		// Nachgestellte Schreibweise „… | Reihe 1", falls links keiner stand.
		if ( ! $durchgang ) {
			foreach ( $stuecke as $rest ) {
				if ( preg_match( '/^reihe\s*(\d+)$/iu', trim( $rest ), $r ) ) {
					$durchgang = (int) $r[1];
					break;
				}
			}
		}

		return array(
			'teil'      => (int) $m[2],
			'reihe'     => $name,
			'durchgang' => $durchgang,
		);
	}

	/**
	 * Post-ID der Reihe zu einem Namen. Legt sie als Entwurf an, wenn sie noch
	 * nicht existiert – eine Reihenseite ohne Einleitungstext soll nicht
	 * öffentlich sein.
	 */
	public static function reihe_id( $name, $anlegen = true ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$gefunden = get_posts( array(
			'post_type'   => self::CPT,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'title'       => $name,
		) );
		if ( $gefunden ) {
			return (int) $gefunden[0];
		}
		if ( ! $anlegen ) {
			return 0;
		}
		$id = wp_insert_post( array(
			'post_type'   => self::CPT,
			'post_title'  => $name,
			'post_status' => 'draft',
		) );
		return ( $id && ! is_wp_error( $id ) ) ? (int) $id : 0;
	}

	/**
	 * Zuordnung eines Termins auffrischen. Gibt die Post-ID der Reihe zurück
	 * (0 = keine Zuordnung).
	 */
	public static function zuordnen( $post_id, $anlegen = true ) {
		$daten = self::parse( get_post_meta( $post_id, self::META_ROH, true ) );
		if ( ! $daten ) {
			delete_post_meta( $post_id, self::META_REIHE );
			delete_post_meta( $post_id, self::META_TEIL );
			delete_post_meta( $post_id, self::META_DURCHGANG );
			return 0;
		}
		$rid = self::reihe_id( $daten['reihe'], $anlegen );
		if ( ! $rid ) {
			return 0;
		}
		update_post_meta( $post_id, self::META_REIHE, $rid );
		update_post_meta( $post_id, self::META_TEIL, $daten['teil'] );
		update_post_meta( $post_id, self::META_DURCHGANG, $daten['durchgang'] );
		return $rid;
	}

	/** Nach dem Speichern eines Seminars die Zuordnung nachziehen. */
	public static function nach_speichern( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status || wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::zuordnen( $post_id );
	}

	/* ===================================================================
	 *  Termine einer Reihe
	 * =================================================================== */

	/**
	 * Termine einer Reihe, gruppiert als
	 *
	 *     [ durchgang => [ teilnummer => [ WP_Post, … ] ] ]
	 *
	 * Durchgang 0 sammelt die Termine ohne feste Gruppe; sie stehen zuerst.
	 *
	 * WARUM ZWEISTUFIG: Bei einer Reihe mit festen Durchgängen kommt dieselbe
	 * Teilnummer mehrfach vor – „Reihe 1 – Teil 2", „Reihe 2 – Teil 2",
	 * „Reihe 3 – Teil 2". Eine Gruppierung allein nach Teilnummer würfe sie in
	 * einen Topf, und dem einzelnen Termin sähe niemand mehr an, zu welcher
	 * Gruppe er gehört. Genau das ist der Sinn eines Durchgangs: eine feste
	 * Gruppe, die alle Teile gemeinsam durchläuft.
	 *
	 * @param bool $nur_kommend Vergangene Termine weglassen.
	 */
	public static function termine( $reihe_id, $nur_kommend = true ) {
		$meta = array(
			array( 'key' => self::META_REIHE, 'value' => (int) $reihe_id ),
			BI_CPT::visible_clause(),
		);
		if ( $nur_kommend ) {
			$meta[] = array(
				'key'     => '_bi_startdatum',
				'value'   => current_time( 'Y-m-d' ),
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		$q = new WP_Query( array(
			'post_type'      => bi_seminar_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 300,
			'meta_key'       => '_bi_startdatum',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_type'      => 'DATE',
			'meta_query'     => $meta,
		) );

		$gruppen = array();
		foreach ( $q->posts as $p ) {
			$d  = (int) get_post_meta( $p->ID, self::META_DURCHGANG, true );
			$nr = (int) get_post_meta( $p->ID, self::META_TEIL, true );
			$gruppen[ $d ][ $nr ][] = $p;
		}
		ksort( $gruppen, SORT_NUMERIC );
		foreach ( $gruppen as $d => $nach_teil ) {
			ksort( $nach_teil, SORT_NUMERIC );
			$gruppen[ $d ] = $nach_teil;
		}
		return $gruppen;
	}

	/**
	 * Die Teile einer Reihe, jeweils mit einem Beispieltermin – daraus liest die
	 * Ausgabe Titel und Themen. Über alle Durchgänge zusammengeführt, denn Teil 2
	 * ist inhaltlich derselbe Baustein, gleich in welcher Gruppe er läuft.
	 *
	 * @return array [ teilnummer => WP_Post ]
	 */
	public static function teile( $gruppen ) {
		$teile = array();
		foreach ( $gruppen as $nach_teil ) {
			foreach ( $nach_teil as $nr => $posts ) {
				if ( ! isset( $teile[ $nr ] ) && $posts ) {
					$teile[ $nr ] = $posts[0];
				}
			}
		}
		ksort( $teile, SORT_NUMERIC );
		return $teile;
	}

	/** Hat die Reihe feste Durchgänge (also Gruppen jenseits von 0)? */
	public static function hat_durchgaenge( $gruppen ) {
		foreach ( array_keys( $gruppen ) as $d ) {
			if ( (int) $d > 0 ) {
				return true;
			}
		}
		return false;
	}

	/** Anzahl zugeordneter Termine (alle Status, auch vergangene). */
	public static function termin_anzahl( $reihe_id ) {
		$q = new WP_Query( array(
			'post_type'      => bi_seminar_post_types(),
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_query'     => array( array( 'key' => self::META_REIHE, 'value' => (int) $reihe_id ) ),
		) );
		return (int) $q->found_posts;
	}

	/** Alle Termine der Reihe flach, in der Reihenfolge des Startdatums. */
	private static function alle_termine( $gruppen ) {
		$flach = array();
		foreach ( $gruppen as $nach_teil ) {
			foreach ( $nach_teil as $posts ) {
				foreach ( $posts as $p ) {
					$flach[ $p->ID ] = $p;
				}
			}
		}
		uasort( $flach, function ( $a, $b ) {
			return strcmp(
				(string) get_post_meta( $a->ID, '_bi_startdatum', true ),
				(string) get_post_meta( $b->ID, '_bi_startdatum', true )
			);
		} );
		return $flach;
	}

	/* ===================================================================
	 *  Anmeldung zu einer Reihe
	 * =================================================================== */

	/**
	 * Prüft eine Terminauswahl, bevor daraus eine Anmeldung wird.
	 *
	 * WARUM SERVERSEITIG: Die Auswahl entsteht im Browser und kommt als Liste
	 * von Post-IDs in der URL zurück. Ungeprüft könnte dort alles stehen –
	 * Termine einer fremden Reihe, zwei Termine desselben Teils, ein
	 * ausgebuchter Termin, ein Seminar, das gar nicht direkt buchbar ist.
	 * Jeder dieser Fälle erzeugte eine Anmeldung, die niemand erfüllen kann.
	 *
	 * Der Rückgabewert nennt bei einem Fehlschlag den Grund im Klartext, damit
	 * das Formular ihn anzeigen kann statt nur „ungültig".
	 *
	 * @param int   $reihe_id   Post-ID der Reihe.
	 * @param array $termin_ids Post-IDs der gewählten Termine.
	 * @return array [ ok, grund, termine (teil => post_id, nach Teil sortiert), durchgang ]
	 */
	public static function auswahl_pruefen( $reihe_id, $termin_ids ) {
		$fehler = function ( $grund ) {
			return array( 'ok' => false, 'grund' => $grund, 'termine' => array(), 'durchgang' => 0 );
		};

		$reihe_id = (int) $reihe_id;
		if ( ! $reihe_id || self::CPT !== get_post_type( $reihe_id ) || 'publish' !== get_post_status( $reihe_id ) ) {
			return $fehler( 'Diese Ausbildungsreihe gibt es nicht (mehr).' );
		}
		// Sagt eine Regel „keine Anmeldung", gibt es auch für die Reihe keine –
		// weder in diesem Formular noch über die Geschäftsstelle. Die Prüfung
		// gehört hierher und nicht nur auf die Seite: Die Adresse mit den
		// Termin-IDs lässt sich auch von Hand aufrufen.
		if ( 'keine' === self::variante( $reihe_id ) ) {
			return $fehler( 'Für diese Ausbildungsreihe ist keine Anmeldung über die Website vorgesehen.' );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $termin_ids ) ) ) );
		if ( ! $ids ) {
			return $fehler( 'Es wurde kein Termin gewählt.' );
		}
		// Deckel gegen aufgeblähte Anfragen: Keine Reihe im Programm hat mehr als
		// eine Handvoll Teile, und jede ID kostet mehrere Datenbankabfragen.
		if ( count( $ids ) > 20 ) {
			return $fehler( 'Es wurden zu viele Termine übergeben.' );
		}

		$gewaehlt  = array();
		$durchgang = null;
		foreach ( $ids as $id ) {
			if ( ! bi_is_seminar_post( $id ) || 'publish' !== get_post_status( $id ) ) {
				return $fehler( 'Ein gewählter Termin ist nicht (mehr) verfügbar.' );
			}
			if ( (int) get_post_meta( $id, self::META_REIHE, true ) !== $reihe_id ) {
				return $fehler( 'Ein gewählter Termin gehört nicht zu dieser Reihe.' );
			}
			// „Keine Anmeldung möglich" zuerst und mit eigenem Satz: Der Hinweis
			// auf die Geschäftsstelle wäre hier eine falsche Auskunft – dort ist
			// für dieses Seminar genauso wenig zu holen.
			if ( 'keine' === BI_Settings::variant_for( $id ) ) {
				return $fehler( 'Für mindestens ein gewähltes Seminar ist keine Anmeldung über die Website vorgesehen.' );
			}
			// Dieselbe Regel wie auf der Reihenseite: ohne § 37,6 (bzw. § 179,4)
			// führt der Weg über die Geschäftsstelle, nicht über dieses Formular.
			if ( ! self::termin_direkt_buchbar( $id, $reihe_id ) ) {
				return $fehler( 'Mindestens ein gewählter Termin lässt sich nicht direkt anmelden – '
					. 'für diese Reihe läuft die Anmeldung über deine Geschäftsstelle.' );
			}

			$teil = (int) get_post_meta( $id, self::META_TEIL, true );
			$d    = (int) get_post_meta( $id, self::META_DURCHGANG, true );
			if ( isset( $gewaehlt[ $teil ] ) ) {
				return $fehler( 'Für Teil ' . $teil . ' wurden zwei Termine gewählt.' );
			}
			// Eine feste Gruppe durchläuft die Reihe gemeinsam; Teile aus zwei
			// Gruppen zu mischen widerspricht genau dem.
			if ( null !== $durchgang && $d !== $durchgang ) {
				return $fehler( 'Die gewählten Termine gehören zu verschiedenen Gruppen.' );
			}
			$durchgang        = $d;
			$gewaehlt[ $teil ] = $id;
		}

		// „Nur komplett buchbar" heißt: kein Teil darf fehlen.
		if ( BI_CPT::meta_bool( $reihe_id, '_bir_komplett' ) ) {
			$alle = self::teile( self::termine( $reihe_id ) );
			$offen = array_diff( array_keys( $alle ), array_keys( $gewaehlt ) );
			if ( $offen ) {
				return $fehler( 'Diese Reihe ist nur komplett buchbar – es fehlt noch Teil '
					. implode( ', ', array_map( 'intval', $offen ) ) . '.' );
			}
		}

		ksort( $gewaehlt, SORT_NUMERIC );
		return array( 'ok' => true, 'grund' => '', 'termine' => $gewaehlt, 'durchgang' => (int) $durchgang );
	}

	/**
	 * Vergleichsform einer Freistellungsangabe.
	 *
	 * Dieselbe Freistellung steht in den Quelldaten in vielen Schreibweisen:
	 * „§ 37,6 BetrVG", „§ 37 Abs. 6 BetrVG", „§37.6 BetrVG", „Par. 37,6 BetrVG".
	 * Verglichen wird deshalb nicht der Text, sondern was von ihm übrig bleibt,
	 * wenn Paragrafenzeichen, „Abs.", Satzzeichen und Leerraum wegfallen:
	 * alle vier ergeben „376betrvg".
	 *
	 * Die Zahlen bleiben getrennt erhalten, damit § 37,6 und § 37,7 nicht
	 * zusammenfallen – der Unterschied ist genau der, um den es geht.
	 */
	public static function frei_schluessel( $name ) {
		// Dieselbe Vergleichsform wie im Regelwerk. Zwei Umsetzungen daneben
		// wären zwei Wahrheiten darüber, wann zwei Freistellungsangaben dasselbe
		// meinen – und genau daran ist die Klammerschreibweise „§ 37(6) BetrVG"
		// schon einmal auseinandergelaufen: Die Liste hier erkannte sie, das
		// Regelwerk nicht.
		return BI_Settings::norm( $name );
	}

	/** Freistellungen, bei denen eine Reihe am Stück gebucht werden darf. */
	/**
	 * Der Anmeldeweg der REIHE aus dem Regelwerk – oder '' , wenn keine Regel greift.
	 *
	 * ============================================================================
	 *  WARUM DIE REIHE DURCH DIESELBEN REGELN LÄUFT
	 * ============================================================================
	 *  Unter *Einstellungen → Anmeldung & Regeln* steht, welche Freistellung
	 *  welchen Anmeldeweg bedeutet. Diese Aussage gilt fachlich für die Sache,
	 *  nicht für den Beitragstyp: Wenn § 37,6 BetrVG heißt „das kann direkt
	 *  gebucht werden", dann heißt es das auch, wenn vier solche Seminare
	 *  hintereinander als Reihe angeboten werden. Die Reihe hat dafür ein
	 *  eigenes Freistellungsfeld – bis 1.95.0 wurde es für den Anmeldeweg
	 *  schlicht nicht gelesen.
	 *
	 * ============================================================================
	 *  UND WARUM DIE ALTE LISTE TROTZDEM BLEIBT
	 * ============================================================================
	 *  Greift KEINE Regel – etwa weil das Freistellungsfeld der Reihe leer ist –,
	 *  bleibt alles beim Alten: Dann entscheiden die Freistellungen der einzelnen
	 *  Termine gegen die Liste unter „Ausbildungsreihen: Wann darf am Stück
	 *  gebucht werden?". Der umgekehrte Weg – bei leerem Feld auf den Normalfall
	 *  'direct' zu fallen, wie es variant_for() für Seminare tut – würde jede
	 *  Reihe ohne Angabe still direkt buchbar machen. Das ist genau die falsche
	 *  Richtung: Ohne Freistellungsangabe lieber ein Mensch dazwischen als eine
	 *  Zusage, die niemand einlösen kann.
	 *
	 * @return string '' | 'direct' | 'gs' | 'keine'
	 */
	public static function variante( $reihe_id ) {
		return BI_Settings::matched_variant( (int) $reihe_id );
	}

	public static function sammel_freistellungen() {
		$roh = (string) BI_Settings::get( 'sammel_freistellung' );
		$out = array();
		foreach ( preg_split( '/\R/', $roh ) as $zeile ) {
			$key = self::frei_schluessel( $zeile );
			if ( '' !== $key ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Darf dieser Termin ohne Geschäftsstelle gebucht werden?
	 *
	 * Zwei Bedingungen, beide nötig:
	 *   1. Er ist überhaupt direkt buchbar (nicht ausgebucht, kein externes
	 *      Webinar, keine GS-Variante aus den Einstellungsregeln).
	 *   2. Er trägt mindestens eine Freistellung aus der Liste – im Standard
	 *      § 37,6 BetrVG und § 179,4 SGB IX. Dort beschließt das Gremium und der
	 *      Arbeitgeber trägt die Kosten; bei Bildungsurlaub, § 37,7 und „keine
	 *      Freistellung" hängt die Teilnahme an seiner Zustimmung, und die klärt
	 *      die Geschäftsstelle.
	 *
	 * Ein Termin ganz OHNE Freistellungsangabe gilt als nicht direkt buchbar.
	 * Das ist die vorsichtige Richtung: lieber einen Menschen dazwischen als
	 * eine Zusage, die niemand einlösen kann.
	 *
	 * $reihe_id: Sagt eine Regel etwas über die REIHE (variante()), gilt sie
	 * statt der Freistellungs-Liste. Die Regel ist die Aussage über die Abfolge;
	 * die Liste war nur der Behelf, solange das Regelwerk die Reihe nicht kannte.
	 * Der Boden bleibt aber liegen: Ein ausgebuchter Termin, ein Termin mit
	 * „Anmeldung möglich = nein" oder einer, den eine Regel selbst auf die
	 * Geschäftsstelle schickt, ist auch als Teil einer Reihe nicht direkt
	 * buchbar. Eine Reihe kann einem Seminar nichts erlauben, was es allein
	 * nicht darf.
	 */
	public static function termin_direkt_buchbar( $post_id, $reihe_id = 0 ) {
		if ( ! BI_CPT::is_bookable( $post_id ) ) {
			return false;
		}
		$regel = $reihe_id ? self::variante( $reihe_id ) : '';
		if ( '' !== $regel ) {
			return 'direct' === $regel;
		}
		$erlaubt = self::sammel_freistellungen();
		if ( ! $erlaubt ) {
			return false;
		}
		$terme = wp_get_object_terms( $post_id, BI_TAX_FREI, array( 'fields' => 'names' ) );
		if ( is_wp_error( $terme ) || ! $terme ) {
			return false;
		}
		foreach ( $terme as $name ) {
			if ( in_array( self::frei_schluessel( $name ), $erlaubt, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Kann diese Gruppe am Stück angemeldet werden? Nur dann, wenn jeder Teil
	 * ausgeschrieben ist und für jeden Teil mindestens ein direkt buchbarer
	 * Termin (siehe termin_direkt_buchbar) bereitsteht.
	 *
	 * Sobald ein einziger Teil fehlt oder nur über die Geschäftsstelle läuft,
	 * geht die ganze Gruppe diesen Weg: Eine Reihe halb im Formular und halb per
	 * Mail anzumelden wäre für die anmeldende Person nicht mehr zu überblicken.
	 *
	 * @param array $nach_teil [ teil => [ WP_Post, … ] ] dieser Gruppe.
	 * @param array $teile     Alle Teile der Reihe.
	 */
	private static function gruppe_sammelbuchbar( $nach_teil, $teile, $reihe_id = 0 ) {
		foreach ( $teile as $nr => $unused ) {
			if ( empty( $nach_teil[ $nr ] ) ) {
				return false;
			}
			$buchbar = false;
			foreach ( $nach_teil[ $nr ] as $p ) {
				if ( self::termin_direkt_buchbar( $p->ID, $reihe_id ) ) {
					$buchbar = true;
					break;
				}
			}
			if ( ! $buchbar ) {
				return false;
			}
		}
		return (bool) $teile;
	}

	/**
	 * Der Anmeldeweg EINER GRUPPE: 'direct' | 'gs' | 'keine'.
	 *
	 * ============================================================================
	 *  WARUM ES DIESE WEICHE BRAUCHT
	 * ============================================================================
	 *  Bis 1.95.0 kannte der Gruppenfuß nur zwei Ausgänge: Sammelanmeldung –
	 *  oder eben nicht, und „eben nicht" hieß immer Geschäftsstelle. Der Grund
	 *  wurde nie gefragt. Auf einer Reihe, deren Termine alle den Haken
	 *  „Anmeldung möglich" NICHT tragen – der Normalfall, solange ein Jahrgang
	 *  noch nicht zur Buchung geöffnet ist –, stand deshalb die
	 *  Geschäftsstellen-Anfrage: Man konnte eine Mail über Seminare schreiben,
	 *  für die es gar keine Anmeldung gibt. Die Regel sagt „Variante 3", die
	 *  Seite bot Variante 2 an.
	 *
	 * ============================================================================
	 *  DIE REIHENFOLGE
	 * ============================================================================
	 *   1. Ein Teil, für den ÜBERHAUPT KEIN Termin anmeldbar ist, schließt die
	 *      ganze Gruppe – auch den Umweg über die Geschäftsstelle. Dieselbe
	 *      Logik wie beim Weg über die Geschäftsstelle, nur eine Stufe strenger:
	 *      Sobald ein Teil nicht geht, geht die Gruppe nicht.
	 *   2. Sagt eine Regel „keine Anmeldung" über die REIHE, gilt das auch.
	 *   3. Sonst: Sammelanmeldung, wenn die Termine sie hergeben – sonst
	 *      Geschäftsstelle.
	 *
	 *  Schritt 1 steht VOR der Regel der Reihe: Eine Reihe kann einem Seminar
	 *  nichts erlauben, was es allein nicht darf.
	 */
	private static function gruppe_variante( $reihe_id, $nach_teil, $teile ) {
		if ( self::teil_ohne_anmeldung( $nach_teil, $teile ) ) {
			return 'keine';
		}
		if ( 'keine' === self::variante( $reihe_id ) ) {
			return 'keine';
		}
		return self::gruppe_sammelbuchbar( $nach_teil, $teile, $reihe_id ) ? 'direct' : 'gs';
	}

	/**
	 * Gibt es einen Teil, für den KEIN einziger Termin eine Anmeldung vorsieht?
	 *
	 * Gemeint ist ausschließlich die Variante 3 aus den Regeln – „für dieses
	 * Seminar ist keine Anmeldung über die Website vorgesehen". Ausgebucht ist
	 * etwas anderes (der Weg gäbe es, der Platz nicht), und ein Teil ganz ohne
	 * Termine ist wieder etwas anderes („Termine in Abstimmung"); beides sagt
	 * die Seite an Ort und Stelle und beides schließt die Gruppe nicht.
	 */
	private static function teil_ohne_anmeldung( $nach_teil, $teile ) {
		foreach ( $teile as $nr => $unused ) {
			$termine = isset( $nach_teil[ $nr ] ) ? $nach_teil[ $nr ] : array();
			if ( ! $termine ) {
				continue;
			}
			$einer = false;
			foreach ( $termine as $p ) {
				if ( 'keine' !== BI_Settings::variant_for( $p->ID ) ) {
					$einer = true;
					break;
				}
			}
			if ( ! $einer ) {
				return true;
			}
		}
		return false;
	}

	/** Adresse des Anmeldeformulars für eine Reihenauswahl. */
	public static function anmeldung_url( $reihe_id, $termin_ids = array() ) {
		$url = BI_Registration::anmeldung_page_url();
		if ( ! $url ) {
			return '';
		}
		$args = array( 'reihe' => (int) $reihe_id );
		if ( $termin_ids ) {
			$args['termine'] = implode( ',', array_map( 'intval', (array) $termin_ids ) );
		}
		return add_query_arg( $args, $url );
	}

	/* ===================================================================
	 *  Backend
	 * =================================================================== */

	public static function add_meta_boxes() {
		add_meta_box( 'bi_reihe_details', 'Angaben zur Reihe', array( 'BI_CPT', 'render_meta_box' ), self::CPT, 'normal', 'high' );
		add_meta_box( 'bi_reihe_termine', 'Zugeordnete Teile und Termine', array( __CLASS__, 'render_termin_box' ), self::CPT, 'normal', 'default' );
	}

	/**
	 * Übersicht der zugeordneten Termine im Backend – nur zum Nachsehen. Die
	 * Zuordnung selbst passiert über das Feld „Teil | Reihe" am Seminar, damit
	 * es genau eine Stelle gibt, an der sie gepflegt wird.
	 */
	/**
	 * Metabox der Reihe: zugeordnete Termine zeigen UND zuordnen.
	 *
	 * WARUM DIE ZUORDNUNG AUCH VON HIER AUS GEHT: Der Weg über das Feld
	 * „Teil | Reihe" am Seminar ist der des Imports – dort steht der Reihenname
	 * schon in der Datei. Wer eine Reihe von Hand anlegt (oder eine importierte
	 * im Entwurf ausbaut), müsste sonst jeden Termin einzeln suchen, öffnen und
	 * den Namen zeichengleich abtippen. Genau daran scheitert es: Eine
	 * abweichende Schreibweise erzeugt eine zweite Reihe.
	 *
	 * Gespeichert wird trotzdem NUR das Feld am Seminar – eine Quelle, wie
	 * gehabt. Diese Box schreibt es bloß richtig geschrieben hinein und ruft
	 * anschließend zuordnen() auf.
	 */
	public static function render_termin_box( $post ) {
		if ( '' === trim( (string) $post->post_title ) ) {
			echo '<p>Bitte zuerst einen <strong>Titel</strong> vergeben und speichern. Der Titel ist der
			      Schlüssel, über den die Termine der Reihe zugeordnet werden.</p>';
			return;
		}
		echo '<div id="bi-reihe-box" data-reihe="' . (int) $post->ID . '">';
		self::box_inhalt( $post->ID );
		echo '</div>';
	}

	/**
	 * Innenleben der Box. Eigene Methode, weil die AJAX-Antwort nach jeder
	 * Zuordnung dasselbe HTML zurückgibt – ein Neuladen der Bearbeiten-Seite
	 * würde die ungespeicherten Eingaben in den anderen Feldern verwerfen.
	 */
	public static function box_inhalt( $reihe_id ) {
		$reihe_id = (int) $reihe_id;
		$titel    = get_the_title( $reihe_id );
		$gruppen  = self::termine( $reihe_id, false );

		echo '<div class="bi-reihe-suche" style="margin:0 0 16px;padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde">';
		echo '<p style="margin:0 0 8px"><strong>Termin zuordnen</strong></p>';
		echo '<p style="margin:0 0 8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
			. '<input type="search" class="regular-text" id="bi-reihe-q" placeholder="Titel oder Seminarnummer" style="flex:1 1 260px">'
			. '<label>Teil <input type="number" id="bi-reihe-teil" value="1" min="1" step="1" style="width:70px"></label>'
			. '<label>feste Gruppe <input type="number" id="bi-reihe-durchgang" value="0" min="0" step="1" style="width:70px"></label>'
			. '<button type="button" class="button" id="bi-reihe-suchen">Suchen</button>'
			. '</p>';
		echo '<p class="description" style="margin:0">Die Teilnummer gilt für den Termin, den du gleich zuordnest –
		      sie lässt sich vor jedem Zuordnen ändern. <strong>Feste Gruppe 0</strong> heißt: keine
		      (Termine ohne festen Durchgang).</p>';
		echo '<div id="bi-reihe-treffer" style="margin-top:10px"></div>';
		echo '</div>';

		if ( ! $gruppen ) {
			echo '<p>Diesem Eintrag ist noch kein Termin zugeordnet. Alternativ geht das weiterhin am Seminar
			      über das Feld <code>Teil | Reihe</code>, z. B. <code>Teil 1 | ' . esc_html( $titel ) . '</code>.</p>';
			return;
		}
		echo '<p class="description">Dieselbe Zuordnung steht am Seminar im Feld <code>Teil | Reihe</code>.
		      Eine Zahl davor – <code>Reihe 1 - Teil 2 | …</code> – ordnet den Termin einer festen Gruppe zu.</p>';

		foreach ( $gruppen as $d => $nach_teil ) {
			if ( (int) $d > 0 ) {
				printf( '<h3 style="margin:18px 0 4px">Reihe %d (feste Gruppe)</h3>', (int) $d );
			} elseif ( count( $gruppen ) > 1 ) {
				echo '<h3 style="margin:18px 0 4px">Ohne feste Gruppe</h3>';
			}
			foreach ( $nach_teil as $nr => $termine ) {
				printf(
					'<h4 style="margin:14px 0 6px">Teil %d: %s <span style="font-weight:400;color:#646970">(%d Termine)</span></h4>',
					(int) $nr,
					esc_html( get_the_title( $termine[0] ) ),
					count( $termine )
				);
				echo '<table class="widefat striped"><tbody>';
				foreach ( $termine as $t ) {
					printf(
						'<tr><td style="width:110px">%s</td><td style="width:130px">%s</td><td>%s</td>'
						. '<td style="width:170px"><a href="%s">bearbeiten</a>'
						. ' · <button type="button" class="button-link bi-reihe-los" data-termin="%d"'
						. ' style="color:#b32d2e">Zuordnung lösen</button></td></tr>',
						esc_html( self::datum( $t->ID ) ),
						esc_html( get_post_meta( $t->ID, '_bi_seminarnummer', true ) ?: '—' ),
						esc_html( self::ort( $t->ID ) ),
						esc_url( (string) get_edit_post_link( $t->ID ) ),
						(int) $t->ID
					);
				}
				echo '</tbody></table>';
			}
		}
	}

	/* ===================================================================
	 *  Zuordnen von der Reihe aus (AJAX)
	 * =================================================================== */

	/**
	 * Wer die Reihe bearbeiten darf UND Seminare bearbeiten darf, darf zuordnen.
	 * Beides ist nötig: Geschrieben wird am Seminar, gemeint ist die Reihe.
	 *
	 * @return int Geprüfte Reihen-ID, 0 = nicht erlaubt.
	 */
	private static function ajax_reihe_id() {
		check_ajax_referer( 'bi_reihe_zuordnen', 'nonce' );
		$reihe_id = isset( $_POST['reihe'] ) ? (int) $_POST['reihe'] : 0;
		if ( ! $reihe_id || self::CPT !== get_post_type( $reihe_id ) ) {
			return 0;
		}
		return current_user_can( 'edit_post', $reihe_id ) ? $reihe_id : 0;
	}

	/**
	 * Seminare zur Suche. Gesucht wird in Titel und Seminarnummer, gezeigt
	 * werden die nächsten Termine – nach Startdatum, kommende zuerst.
	 *
	 * Vergangene Termine bleiben mit dabei: Eine Reihe wird oft erst nach dem
	 * ersten Teil fertig gepflegt, und dann muss sich auch dieser noch zuordnen
	 * lassen.
	 */
	public static function ajax_suche() {
		$reihe_id = self::ajax_reihe_id();
		if ( ! $reihe_id ) {
			wp_send_json_error( array( 'text' => 'Keine Berechtigung.' ) );
		}

		$q = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		if ( strlen( $q ) < 2 ) {
			wp_send_json_error( array( 'text' => 'Bitte mindestens zwei Zeichen eingeben.' ) );
		}

		// Sortiert nach Startdatum: Bei einem Seminartitel mit dreißig Terminen
		// will man die nächsten sehen, nicht dreißig gleich aussehende Zeilen in
		// zufälliger Folge.
		//
		// Das `meta_key` schließt Seminare OHNE Startdatum aus. Gewollt: Ein
		// Termin ohne Datum ist keiner – er ließe sich in der Reihe weder
		// einsortieren noch buchen. Erst Datum eintragen, dann zuordnen.
		//
		// `suppress_filters` muss ausdrücklich aus: get_posts() setzt es sonst
		// auf true, und dann liefe die Suche über die Seminarnummer nicht mit
		// (die hängt an posts_join/posts_where in BI_Datenpflege).
		$posts = get_posts( array(
			'post_type'        => bi_seminar_post_types(),
			'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'numberposts'      => 30,
			's'                => $q,
			'orderby'          => 'meta_value',
			'meta_key'         => '_bi_startdatum',
			'meta_type'        => 'DATE',
			'order'            => 'ASC',
			'bi_dp_search'     => 1, // sucht zusätzlich in der Seminarnummer (BI_Datenpflege)
			'suppress_filters' => false,
		) );

		$treffer = array();
		foreach ( $posts as $p ) {
			$zu = (int) get_post_meta( $p->ID, self::META_REIHE, true );
			$treffer[] = array(
				'id'     => (int) $p->ID,
				'titel'  => get_the_title( $p->ID ),
				'nummer' => (string) get_post_meta( $p->ID, '_bi_seminarnummer', true ),
				'datum'  => self::datum( $p->ID ),
				'ort'    => self::ort( $p->ID ),
				// Ehrlich sagen, wenn ein Termin schon woanders hängt – sonst
				// zieht ihn jemand weg, ohne es zu merken.
				'reihe'  => $zu ? get_the_title( $zu ) : '',
				'eigen'  => ( $zu === $reihe_id ),
			);
		}
		wp_send_json_success( array( 'treffer' => $treffer ) );
	}

	/**
	 * Termin zuordnen oder lösen. Geschrieben wird das Feld am Seminar, danach
	 * läuft die gewohnte Auswertung (zuordnen) – damit gibt es weiterhin genau
	 * eine Quelle für die Zuordnung.
	 */
	public static function ajax_zuordnen() {
		$reihe_id = self::ajax_reihe_id();
		if ( ! $reihe_id ) {
			wp_send_json_error( array( 'text' => 'Keine Berechtigung.' ) );
		}

		$termin_id = isset( $_POST['termin'] ) ? (int) $_POST['termin'] : 0;
		if ( ! $termin_id || ! bi_is_seminar_post( $termin_id ) || ! current_user_can( 'edit_post', $termin_id ) ) {
			wp_send_json_error( array( 'text' => 'Dieser Termin lässt sich nicht bearbeiten.' ) );
		}

		$loesen = ! empty( $_POST['loesen'] );
		if ( $loesen ) {
			delete_post_meta( $termin_id, self::META_ROH );
			self::zuordnen( $termin_id );
			wp_send_json_success( array(
				'html' => self::box_html( $reihe_id ),
				'text' => 'Zuordnung gelöst.',
			) );
		}

		$teil      = max( 1, isset( $_POST['teil'] ) ? (int) $_POST['teil'] : 1 );
		$durchgang = max( 0, isset( $_POST['durchgang'] ) ? (int) $_POST['durchgang'] : 0 );

		$wert = self::feldwert( $teil, $durchgang, get_the_title( $reihe_id ) );
		update_post_meta( $termin_id, self::META_ROH, $wert );
		$gesetzt = self::zuordnen( $termin_id, false );

		if ( $gesetzt !== $reihe_id ) {
			// Sollte nicht vorkommen – der Name kommt aus dem Titel. Wenn doch,
			// lieber sagen als still danebenliegen.
			wp_send_json_error( array(
				'text' => 'Die Zuordnung konnte nicht aufgelöst werden. Trägt eine zweite Reihe denselben Titel?',
				'html' => self::box_html( $reihe_id ),
			) );
		}

		wp_send_json_success( array(
			'html' => self::box_html( $reihe_id ),
			'text' => sprintf( 'Zugeordnet als %s.', $wert ),
		) );
	}

	/**
	 * Der Feldwert „Teil | Reihe", wie ihn parse() wieder auseinandernimmt.
	 *
	 * Die beiden gehören zusammen und müssen zusammen bleiben: Was hier
	 * geschrieben wird, muss parse() lesen können – sonst legt das nächste
	 * Speichern des Seminars die Zuordnung still wieder ab. Genau dieses Paar
	 * prüft tests/test-reihen.php.
	 *
	 * Der Reihenname kommt aus dem Titel, nicht aus einer Eingabe: Eine
	 * abweichende Schreibweise erzeugt eine zweite Reihe, und das ist der
	 * häufigste Fehler in diesen Daten.
	 */
	public static function feldwert( $teil, $durchgang, $titel ) {
		$teil      = max( 1, (int) $teil );
		$durchgang = max( 0, (int) $durchgang );
		$links     = $durchgang > 0
			? sprintf( 'Reihe %d - Teil %d', $durchgang, $teil )
			: sprintf( 'Teil %d', $teil );
		return $links . ' | ' . trim( (string) $titel );
	}

	/** Box-Innenleben als String (für die AJAX-Antwort). */
	private static function box_html( $reihe_id ) {
		ob_start();
		self::box_inhalt( $reihe_id );
		return (string) ob_get_clean();
	}

	public static function admin_columns( $cols ) {
		$neu = array();
		foreach ( $cols as $k => $v ) {
			$neu[ $k ] = $v;
			if ( 'title' === $k ) {
				$neu['bi_teile'] = 'Teile / Termine';
			}
		}
		return $neu;
	}

	/* ---------- „Reihen durchsuchen": auch über die Seminarnummer ---------- */

	/**
	 * Die Reihenliste im Backend über die Seminarnummer eines TERMINS finden.
	 *
	 * WARUM DAS NÖTIG IST: Eine Reihe trägt selbst keine Nummer – ihre Termine
	 * tragen sie. Wer aus einer Mail, einem Anmeldebogen oder vom Telefon eine
	 * Nummer vor sich hat und wissen will, zu welcher Reihe sie gehört, suchte
	 * bis 1.95.0 vergeblich: Die Liste verglich nur Titel und Text der Reihe.
	 * Für Seminare gilt die Regel längst (BI_CPT), hier fehlte sie.
	 *
	 * ALS EXISTS, NICHT ALS JOIN: Der Weg von der Reihe zum Termin geht über
	 * ZWEI Meta-Reihen (Nummer und Zuordnung) und ist 1:n – als Join gäbe es
	 * eine Zeile je Termin, also dieselbe Reihe zwanzigmal in der Liste. Ein
	 * DISTINCT dagegen verträgt sich nicht mit dem Sortieren der Liste. Das
	 * EXISTS lässt die Ergebnismenge unangetastet.
	 */
	private static function is_reihe_admin_search( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return false;
		}
		if ( '' === trim( (string) $query->get( 's' ) ) ) {
			return false;
		}
		return in_array( self::CPT, (array) $query->get( 'post_type' ), true );
	}

	public static function search_where( $where, $query ) {
		global $wpdb;
		if ( ! self::is_reihe_admin_search( $query ) ) {
			return $where;
		}
		// Hinter die Titel-Bedingung ein „ODER ein Termin dieser Reihe trägt die
		// Nummer" setzen. Der Suchbegriff steht bereits fertig quotiert in der
		// Bedingung und wird als Rückverweis (\1) übernommen – so ist er genau
		// einmal maskiert und genau so, wie WordPress ihn gebaut hat.
		$exists = ' OR EXISTS ( SELECT 1'
			. " FROM {$wpdb->postmeta} AS bi_rn"
			. " INNER JOIN {$wpdb->postmeta} AS bi_rz ON ( bi_rz.post_id = bi_rn.post_id AND bi_rz.meta_key = '" . self::META_REIHE . "' )"
			. ' WHERE bi_rn.meta_key = \'_bi_seminarnummer\' AND bi_rn.meta_value LIKE \1'
			. " AND CAST( bi_rz.meta_value AS SIGNED ) = {$wpdb->posts}.ID )";

		return preg_replace(
			"/\(\s*{$wpdb->posts}\.post_title\s+LIKE\s*('[^']+')\s*\)/",
			'(' . $wpdb->posts . '.post_title LIKE \1)' . $exists,
			$where
		);
	}

	public static function admin_column_content( $col, $post_id ) {
		if ( 'bi_teile' !== $col ) {
			return;
		}
		$gruppen = self::termine( $post_id, false );
		if ( ! $gruppen ) {
			echo '<span style="color:#b32d2e">keine Zuordnung</span>';
			return;
		}
		$zusatz = self::hat_durchgaenge( $gruppen )
			? sprintf( ', %d feste Gruppe(n)', count( array_filter( array_keys( $gruppen ) ) ) )
			: '';
		printf(
			'%d Teil(e), %s Termine%s',
			count( self::teile( $gruppen ) ),
			esc_html( number_format_i18n( self::termin_anzahl( $post_id ) ) ),
			esc_html( $zusatz )
		);
	}

	/* ===================================================================
	 *  Frontend – Rahmen
	 * =================================================================== */

	public static function template_include( $template ) {
		if ( is_singular( self::CPT ) ) {
			$eigen = BI_PATH . 'templates/single-bi_reihe.php';
			if ( file_exists( $eigen ) ) {
				return $eigen;
			}
		}
		return $template;
	}

	/**
	 * Stylesheet und Skripte auf der Reihenseite laden.
	 *
	 * gs-anfrage.js kommt mit, weil Gruppen ohne durchgängige § 37,6-Freistellung
	 * die PLZ-Suche und die vorausgefüllte Mail-Anfrage anbieten – dasselbe
	 * Bauteil wie auf der Seminar-Detailseite.
	 */
	public static function enqueue_single() {
		if ( is_singular( self::CPT ) ) {
			wp_enqueue_style( 'bi-detailseiten' );
			wp_enqueue_script( 'bi-reihe' );
			wp_enqueue_script( 'bi-gs-anfrage' );
			wp_enqueue_script( 'bi-zurueck' );
		}
	}

	/** „28.02.–05.03.2027" bzw. nur der Starttag. */
	private static function datum( $post_id ) {
		return BI_Detail::zeitraum( $post_id, true );
	}

	/** Tatsächlicher Ort, sonst das zuständige Bildungszentrum. */
	private static function ort( $post_id ) {
		return BI_CPT::ort_anzeige( $post_id );
	}

	/**
	 * Kurzform eines Ortsnamens für Filter-Chips und Terminzeilen. „Bildungs-
	 * zentrum Sprockhövel" wird zu „Sprockhövel": In einer Spalte, in der jede
	 * Zeile mit demselben Wort beginnt, trägt das Wort nichts bei.
	 */
	private static function ort_kurz( $ort ) {
		$ort = trim( (string) $ort );
		foreach ( array( 'IG Metall Bildungszentrum ', 'Bildungszentrum ', 'Bildungsstätte ', 'Parkhotel ', 'Hotel ' ) as $vorsatz ) {
			if ( 0 === stripos( $ort, $vorsatz ) ) {
				return trim( substr( $ort, strlen( $vorsatz ) ) );
			}
		}
		return $ort;
	}

	/* ===================================================================
	 *  Frontend – Reihenseite
	 * =================================================================== */

	/** Vollständige Ausgabe einer Reihenseite. */
	public static function render( $reihe_id ) {
		$reihe_id = (int) $reihe_id;
		$gruppen  = self::termine( $reihe_id );
		$teile    = self::teile( $gruppen );
		$komplett = BI_CPT::meta_bool( $reihe_id, '_bir_komplett' );

		// Alle Ampeln in einem Zug nachschlagen statt je Zeile einzeln.
		$flach = self::alle_termine( $gruppen );
		BI_Ampel::vorladen( array_map( function ( $p ) {
			return (string) get_post_meta( $p->ID, '_bi_seminarnummer', true );
		}, array_values( $flach ) ) );

		$html = '<div class="igm-seite igm-seite--reihe">';

		/* ---- Kopf ---- */
		$badges = '';
		if ( $komplett ) {
			$badges .= BI_Detail::badge( 'Reihe nur komplett buchbar', 'rot-voll' );
		}
		$aufbau = sprintf( _n( '%d Teil', '%d Teile', count( $teile ), 'bi-seminarsuche' ), count( $teile ) );
		$anzahl_gruppen = count( array_filter( array_keys( $gruppen ) ) );
		if ( $anzahl_gruppen ) {
			$aufbau .= ' · ' . sprintf( _n( '%d Gruppe', '%d Gruppen', $anzahl_gruppen, 'bi-seminarsuche' ), $anzahl_gruppen );
		}
		if ( $teile ) {
			$badges .= BI_Detail::badge( esc_html( $aufbau ) );
		}

		$html .= BI_Detail::hero( array(
			'brotkrumen' => BI_Detail::zurueck_link(),
			'overline'   => 'Ausbildungsreihe',
			'titel'      => get_the_title( $reihe_id ),
			'badges'     => $badges,
			'bild_id'    => $reihe_id,
		) );

		/* ---- Kennzahlenband ---- */
		$html .= BI_Detail::fakten( self::kennzahlen( $gruppen, $teile, $komplett ) );

		/* ---- Zwei Spalten ---- */
		$html .= '<div class="igm-layout igm-breite"><main class="igm-layout__main">';

		$einleitung = get_post_field( 'post_content', $reihe_id );
		if ( '' !== trim( (string) $einleitung ) ) {
			$html .= '<div class="igm-fliesstext">' . apply_filters( 'the_content', $einleitung ) . '</div>';
		}

		if ( $teile ) {
			$html .= self::inhalte_block( $teile, isset( $gruppen[0] ) ? $gruppen[0] : array() );
			$html .= self::gruppen_block( $reihe_id, $gruppen, $teile, $komplett );
		} else {
			$html .= '<p class="igm-termine__leer">Für diese Reihe sind derzeit keine Termine ausgeschrieben.</p>';
		}

		$html .= '</main>';

		/* ---- Sidebar ---- */
		$html .= '<aside class="igm-layout__side">';
		$html .= self::angaben_box( $reihe_id );
		// KEIN Kontaktkasten. „Fragen zur Reihe" stand hier einmal – mit der
		// Ansprechperson eines Termins, ersatzweise, weil die Reihe keine hat.
		// Dahinter steht aber kein Prozess: Wer die Reihe verantwortet, ist
		// nirgends erfasst, und eine Adresse anzubieten, an der niemand mit
		// Fragen zur Abfolge rechnet, schickt Leute ins Leere. Kommt so ein
		// Prozess, gehört er hierher – bis dahin lieber kein Angebot als ein
		// falsches. Der Weg zur Anmeldung steht im Fuß jeder Gruppe.
		$html .= '</aside>';

		return $html . '</div></div>';
	}

	/** Die vier Angaben des Kennzahlenbands einer Reihe. */
	private static function kennzahlen( $gruppen, $teile, $komplett ) {
		$flach = self::alle_termine( $gruppen );

		// Aufbau: „4 Teile, je 3 Tage" – die Dauer nur nennen, wenn sie bei allen
		// Teilen gleich ist. Eine gemittelte Dauer wäre eine Zahl ohne Aussage.
		$aufbau = sprintf( _n( '%d Teil', '%d Teile', count( $teile ), 'bi-seminarsuche' ), count( $teile ) );
		$tage   = array();
		foreach ( $teile as $p ) {
			$tage[] = self::dauer_tage( $p->ID );
		}
		$tage = array_unique( array_filter( $tage ) );
		if ( 1 === count( $tage ) ) {
			$d       = (int) reset( $tage );
			$aufbau .= ', je ' . sprintf( _n( '%d Tag', '%d Tage', $d, 'bi-seminarsuche' ), $d );
		}

		$anzahl_gruppen = count( array_filter( array_keys( $gruppen ) ) );
		if ( $anzahl_gruppen ) {
			$buchung = $komplett ? 'Als feste Gruppe, nur komplett' : 'Als feste Gruppe';
		} else {
			$buchung = $komplett ? 'Nur komplett buchbar' : 'Teile einzeln buchbar';
		}

		$start = '';
		foreach ( $flach as $p ) {
			$roh = (string) get_post_meta( $p->ID, '_bi_startdatum', true );
			if ( $roh ) {
				$start = date_i18n( 'd.m.Y', strtotime( $roh ) );
				break;
			}
		}

		$orte = array();
		foreach ( $flach as $p ) {
			$o = self::ort_kurz( self::ort( $p->ID ) );
			if ( '' !== $o && ! in_array( $o, $orte, true ) ) {
				$orte[] = $o;
			}
		}

		return array(
			'Aufbau'         => $aufbau,
			'Buchung'        => $buchung,
			'Nächster Start' => $start,
			'Orte'           => implode( ', ', $orte ),
		);
	}

	/** Dauer eines Termins in Tagen (einschließlich An- und Abreisetag). */
	private static function dauer_tage( $post_id ) {
		$start = (string) get_post_meta( $post_id, '_bi_startdatum', true );
		$end   = (string) get_post_meta( $post_id, '_bi_enddatum', true );
		if ( ! $start ) {
			return 0;
		}
		if ( ! $end || $end === $start ) {
			return 1;
		}
		$diff = ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS;
		return $diff > 0 ? (int) round( $diff ) + 1 : 1;
	}

	/**
	 * „Inhalte der Reihe": je Teil eine aufklappbare Zeile mit Themen und – bei
	 * einzeln buchbaren Teilen – den zugehörigen Terminen.
	 *
	 * Ohne JavaScript sind alle Teile offen (kein hidden im Markup); erst
	 * reihe.js klappt zu und lässt Teil 1 offen. Die Inhalte sind der Kern der
	 * Seite und dürfen nicht hinter einem Skript verschwinden.
	 *
	 * @param array $ohne_durchgang Termine ohne feste Gruppe: [ teil => [posts] ]
	 */
	private static function inhalte_block( $teile, $ohne_durchgang ) {
		$html = '<div>' . BI_Detail::abschnitt(
			'Inhalte der Reihe',
			count( $teile ) > 1 ? 'Die Teile bauen aufeinander auf und werden in dieser Reihenfolge durchlaufen' : ''
		);
		$html .= '<div class="igm-teile igm-abschnitt__inhalt">';

		foreach ( $teile as $nr => $beispiel ) {
			$id     = 'bi-teil-' . (int) $nr . '-' . (int) $beispiel->ID;
			$themen = BI_Detail::themen_html( get_post_meta( $beispiel->ID, '_bi_themen', true ) );

			$html .= '<article class="igm-teil">';
			$html .= '<button type="button" class="igm-teil__kopf" aria-expanded="true" aria-controls="' . esc_attr( $id ) . '">';
			$html .= '<span class="igm-teil__nr"><span class="igm-teil__nr-wort">Teil</span>'
				. '<span class="igm-teil__nr-zahl">' . (int) $nr . '</span></span>';
			$html .= '<span class="igm-teil__titel">' . esc_html( get_the_title( $beispiel ) ) . '</span>';
			$html .= '<span class="igm-teil__zeichen" aria-hidden="true">–</span>';
			$html .= '</button>';

			$html .= '<div class="igm-teil__inhalt" id="' . esc_attr( $id ) . '">';
			if ( '' !== $themen ) {
				$html .= '<h4 class="igm-teil__themen-titel">Themen im Seminar</h4>' . $themen;
			}
			// Einzeln buchbare Termine stehen direkt beim Teil. Termine fester
			// Durchgänge stehen weiter unten in ihrer Gruppe – dort ergibt nur
			// die vollständige Abfolge einen Sinn.
			if ( ! empty( $ohne_durchgang[ $nr ] ) ) {
				$zeilen  = '';
				$ampeln  = false;
				foreach ( $ohne_durchgang[ $nr ] as $t ) {
					$ampel   = BI_Ampel::fuer_post( $t->ID );
					$ampeln  = $ampeln || (bool) $ampel;
					$zeilen .= BI_Detail::termin_zeile( $t->ID, $ampel );
				}
				// Wie auf der Detailseite: ohne eine einzige Verfügbarkeit
				// entfällt die Spalte, statt leer mitzulaufen.
				$html .= '<div class="igm-teil__termine">'
					. '<h4 class="igm-teil__themen-titel">Termine</h4>'
					. '<div class="igm-termine' . ( $ampeln ? '' : ' igm-termine--ohne-ampel' ) . '">'
					. $zeilen . '</div></div>';
			}
			$html .= '</div></article>';
		}

		return $html . '</div></div>';
	}

	/**
	 * „Termine der festen Gruppen": je Durchgang ein Zeitstrahl mit einem
	 * Auswahlpunkt pro Teil, darüber der Ortsfilter, darunter Fortschritt und
	 * Buchen-Button.
	 *
	 * Aufgeführt werden ALLE Teile der Reihe, auch die, für die in dieser Gruppe
	 * noch kein Termin ausgeschrieben ist – sonst sähe eine halb geplante Gruppe
	 * aus wie eine kürzere Reihe.
	 */
	private static function gruppen_block( $reihe_id, $gruppen, $teile, $komplett = false ) {
		if ( ! self::hat_durchgaenge( $gruppen ) ) {
			return '';
		}

		$html = '<div>' . BI_Detail::abschnitt(
			'Termine der festen Gruppen',
			'Jede Gruppe durchläuft die Teile gemeinsam. Du buchst eine Gruppe, nicht einen einzelnen Termin.'
		);
		$html .= '<div class="igm-gruppen igm-abschnitt__inhalt">';

		foreach ( $gruppen as $d => $nach_teil ) {
			if ( (int) $d < 1 ) {
				continue;
			}
			$html .= self::gruppe( $reihe_id, (int) $d, $nach_teil, $teile, $komplett );
		}

		return $html . '</div></div>';
	}

	/**
	 * Eine feste Gruppe (Durchgang).
	 *
	 * $komplett trägt den Haken „Nur komplett buchbar" der Reihe ins Markup
	 * (data-komplett). Er entscheidet dort über die VORAUSWAHL: Ein Teil, für
	 * den es nur eine einzige buchbare Möglichkeit gibt, wird von reihe.js
	 * gleich gesetzt. Wo nichts zu entscheiden ist, ist ein Klick keine
	 * Entscheidung, sondern eine Hürde – und bei einer Reihe, die ohnehin nur
	 * am Stück zu haben ist, gleich vier davon.
	 */
	private static function gruppe( $reihe_id, $durchgang, $nach_teil, $teile, $komplett = false ) {
		$name  = 'Reihe ' . $durchgang;
		$feld  = 'bi-r' . (int) $reihe_id . '-g' . $durchgang . '-t';
		$letzte = count( $teile );

		// Orte dieser Gruppe in der Reihenfolge ihres ersten Auftretens.
		$orte = array();
		foreach ( $nach_teil as $posts ) {
			foreach ( $posts as $p ) {
				$o = self::ort( $p->ID );
				if ( '' !== $o && ! isset( $orte[ $o ] ) ) {
					$orte[ $o ] = self::ort_kurz( $o );
				}
			}
		}

		$html = '<section class="igm-gruppe" data-gruppe="' . esc_attr( $name ) . '"'
			. ( $komplett ? ' data-komplett="1"' : '' ) . '>';

		$html .= '<div class="igm-gruppe__kopf">';
		$html .= '<h3 class="igm-gruppe__name">' . esc_html( $name ) . '</h3>';
		$zeitraum = self::gruppen_zeitraum( $nach_teil );
		if ( '' !== $zeitraum ) {
			$html .= '<span class="igm-gruppe__zeitraum">' . esc_html( $zeitraum ) . '</span>';
		}
		$html .= '</div>';

		// Der Ortsfilter lohnt erst ab zwei Orten – bei einem wäre er eine
		// Auswahl ohne Alternative.
		if ( count( $orte ) > 1 ) {
			$html .= '<div class="igm-gruppe__orte" role="group" aria-label="Bildungszentrum filtern">';
			$html .= '<span class="igm-gruppe__orte-label">Bildungszentrum</span>';
			$html .= '<button type="button" class="igm-chip is-aktiv" data-ort="" aria-pressed="true">Alle</button>';
			foreach ( $orte as $voll => $kurz ) {
				$html .= '<button type="button" class="igm-chip" data-ort="' . esc_attr( $voll ) . '" aria-pressed="false">'
					. esc_html( $kurz ) . '</button>';
			}
			$html .= '</div>';
		}

		$html .= '<div class="igm-gruppe__plan">';
		$i = 0;
		foreach ( $teile as $nr => $beispiel ) {
			$i++;
			$termine = isset( $nach_teil[ $nr ] ) ? $nach_teil[ $nr ] : array();

			$html .= '<div class="igm-schritt" data-teil="' . (int) $nr . '">';
			$html .= '<div class="igm-schritt__spur"><span class="igm-schritt__marke">' . (int) $nr . '</span>';
			if ( $i < $letzte ) {
				$html .= '<span class="igm-schritt__linie"></span>';
			}
			$html .= '</div>';

			$html .= '<div class="igm-schritt__inhalt">';
			$html .= '<p class="igm-schritt__titel">Teil ' . (int) $nr . ' · '
				. esc_html( get_the_title( $beispiel ) ) . '</p>';
			$html .= '<p class="igm-schritt__hinweis igm-termine__hinweis" hidden>'
				. 'An diesem Bildungszentrum läuft dieser Teil nicht – hier die Alternativen.</p>';

			$html .= '<div class="igm-termine">';
			if ( $termine ) {
				foreach ( $termine as $t ) {
					$html .= self::wahl_zeile( $t->ID, $feld . (int) $nr, (int) $nr );
				}
			} else {
				$html .= '<p class="igm-termine__leer">Termine in Abstimmung</p>';
			}
			$html .= '</div></div></div>';
		}
		$html .= '</div>';

		/* ---- Fuß: Fortschritt und Buchung ---- */
		$buchbar = 0;
		foreach ( $teile as $nr => $unused ) {
			if ( ! empty( $nach_teil[ $nr ] ) ) {
				$buchbar++;
			}
		}

		// Drei Wege, und die Freistellung entscheidet, welcher:
		//
		//   Sammelanmeldung  Ein Formular für die ganze Gruppe. Das Gremium
		//                    beschließt, der Arbeitgeber zahlt – dafür braucht es
		//                    niemanden dazwischen.
		//   GS-Anfrage       Die Teilnahme hängt an der Zustimmung des
		//                    Arbeitgebers. Dann übernimmt die Geschäftsstelle –
		//                    über dieselbe PLZ-Suche und Mail-Anfrage wie beim
		//                    einzelnen Seminar, nur mit allen gewählten Terminen
		//                    im Text.
		//   Keine Anmeldung  Störer statt Button, wie am Seminar.
		//
		// GEFRAGT WIRD IN DIESER REIHENFOLGE:
		//   1. Trifft eine Regel aus den Einstellungen auf die REIHE zu? Dann
		//      gilt ihre Variante. Verglichen wird das eigene Freistellungsfeld
		//      der Reihe – eine Aussage über eine Freistellung gilt für die
		//      Abfolge genauso wie für das einzelne Seminar.
		//   2. Sonst wie bisher: Trägt jeder Teil eine Freistellung aus der Liste
		//      „Ausbildungsreihen: Wann darf am Stück gebucht werden?",
		//      Sammelanmeldung – sonst Geschäftsstelle.
		//
		// SEIT 1.95.0 SCHLÄGT DAS REGELWERK DIE LISTE. Trifft eine Regel aus
		// *Einstellungen → Anmeldung & Regeln* auf die Reihe zu (verglichen wird
		// ihr eigenes Freistellungsfeld), gilt deren Variante – auch 'keine'.
		// Trifft keine zu, entscheiden wie bisher die Freistellungen der Termine.
		$weg    = self::gruppe_variante( $reihe_id, $nach_teil, $teile );
		$sammel = ( 'direct' === $weg ) ? self::anmeldung_url( $reihe_id ) : '';
		$gs_id  = 'bi-gs-' . (int) $reihe_id . '-' . $durchgang;

		// „Keine Anmeldung" heißt: kein Weg, auch kein Umweg über die
		// Geschäftsstelle. Statt eines Buttons, der nirgendwohin führt, steht
		// der Störer da – dasselbe Bauteil und derselbe Text wie am Seminar.
		if ( 'keine' === $weg ) {
			$html .= '<div class="igm-gruppe__fuss igm-gruppe__fuss--keine">'
				. BI_Detail::stoerer( BI_Settings::get( 'keine_label' ), BI_Settings::get( 'keine_hinweis' ) )
				. '</div></section>';
			return $html;
		}

		$html .= '<div class="igm-gruppe__fuss">';
		$html .= '<div class="igm-gruppe__stand">';
		$html .= '<span class="igm-fortschritt">0 / ' . (int) $buchbar . '</span>';
		$html .= '<span class="igm-gruppe__status"'
			. ' data-text-offen="' . esc_attr( 'Termine wählen, um die Gruppe zu buchen.' ) . '"'
			. ' data-text-komplett="' . esc_attr( $sammel
				? 'Alle Teile gewählt – die Gruppe kann in einem Zug gebucht werden.'
				: 'Alle Teile gewählt – die Anmeldung läuft über deine Geschäftsstelle.' ) . '"'
			. ' data-text-teilweise="' . esc_attr( 'Für diese Gruppe sind noch nicht alle Teile ausgeschrieben.' ) . '"'
			// Vorausgewählt ist nicht dasselbe wie gewählt: Wer die Seite öffnet
			// und eine fertige Auswahl vorfindet, muss lesen können, dass sie
			// nicht von ihm stammt – und dass sie sich ändern lässt, wo es
			// überhaupt Alternativen gibt.
			. ' data-text-vorbelegt="' . esc_attr( $komplett
				? 'Diese Reihe ist nur komplett buchbar – alle Teile sind vorausgewählt.'
				: '' ) . '"'
			// Sackgasse benennen: Ist der einzige Termin eines Teils ausgebucht,
			// lässt sich die Gruppe nicht vervollständigen. Ohne diesen Satz
			// klickt man sich durch eine Auswahl, die nie fertig wird.
			. ' data-text-gesperrt="' . esc_attr( 'In dieser Gruppe ist mindestens ein Teil ausgebucht – sie lässt sich derzeit nicht komplett buchen.' ) . '">'
			. esc_html( $buchbar < count( $teile )
				? 'Für diese Gruppe sind noch nicht alle Teile ausgeschrieben.'
				: 'Termine wählen, um die Gruppe zu buchen.' )
			. '</span>';
		$html .= '</div>';

		// Ohne JavaScript bleibt der Button ein Hinweis: Die Auswahl entsteht erst
		// im Browser, ein Ziel gäbe es also gar nicht.
		if ( $sammel ) {
			$html .= '<a class="igm-btn igm-btn--sek igm-btn--aus igm-gruppe__btn" aria-disabled="true"'
				. ' data-sammel="' . esc_url( $sammel ) . '"'
				. ' data-text-offen="' . esc_attr( 'Auswahl vervollständigen' ) . '"'
				. ' data-text-komplett="' . esc_attr( $name . ' buchen' ) . '">Auswahl vervollständigen</a>';
		} else {
			$html .= '<button type="button" class="igm-btn igm-btn--sek igm-btn--aus igm-gruppe__btn igm-gruppe__btn--gs"'
				. ' disabled aria-expanded="false" aria-controls="' . esc_attr( $gs_id ) . '"'
				. ' data-text-offen="' . esc_attr( 'Auswahl vervollständigen' ) . '"'
				. ' data-text-komplett="' . esc_attr( 'Anfrage vorbereiten' ) . '">Auswahl vervollständigen</button>';
		}
		$html .= '</div>';

		if ( ! $sammel ) {
			$html .= self::gs_anfrage( $reihe_id, $durchgang, $name, $gs_id );
		}

		return $html . '</section>';
	}

	/**
	 * Geschäftsstellen-Anfrage für eine ganze Gruppe.
	 *
	 * Dasselbe Bauteil wie auf der Seminar-Detailseite – PLZ eingeben, die
	 * zuständige Geschäftsstelle nachschlagen, Anfrage als vorausgefüllte Mail
	 * öffnen (assets/js/gs-anfrage.js). Der Unterschied steckt im Text: Er nennt
	 * die Reihe, die Gruppe und alle gewählten Termine.
	 *
	 * Zusammengesetzt wird der Mailtext im Browser, weil die Terminauswahl dort
	 * entsteht. Hier stehen nur Kopf und Fuß; die Zeilen dazwischen liefert
	 * reihe.js aus den `data-mail`-Angaben der gewählten Zeilen, die persönlichen
	 * Daten der Dialog aus gs-anfrage.js. So bleibt der ganze deutsche Text in
	 * PHP und im Skript steht nur das Zusammenfügen.
	 */
	private static function gs_anfrage( $reihe_id, $durchgang, $name, $gs_id ) {
		$titel = get_the_title( $reihe_id );
		$gruppe = $durchgang ? $name : '';

		$betreff = 'Anmeldung Ausbildungsreihe ' . $titel . ( $gruppe ? ' – ' . $gruppe : '' );

		$kopf  = "Guten Tag,\r\n\r\n";
		$kopf .= "hiermit möchte ich mich für folgende Ausbildungsreihe anmelden:\r\n\r\n";
		$kopf .= 'Ausbildungsreihe: ' . $titel . "\r\n";
		if ( $gruppe ) {
			$kopf .= 'Feste Gruppe: ' . $gruppe . "\r\n";
		}
		$kopf .= 'Link: ' . get_permalink( $reihe_id ) . "\r\n\r\n";
		$kopf .= "Gewählte Termine:\r\n";

		// Nur noch die Leerzeile hinter den Terminen: Datenblock und Grußformel
		// kommen aus BI_Detail, weil sie beim Seminar wie bei der Reihe dieselben
		// sind und der Dialog beide füllt.
		$fuss = "\r\n";

		$html  = '<div class="igm-gruppe__gs" id="' . esc_attr( $gs_id ) . '" hidden>';
		$html .= '<div class="igm-gs-anfrage"'
			. ' data-gs-betreff="' . esc_attr( $betreff ) . '"'
			. ' data-gs-kopf="' . esc_attr( $kopf ) . '"'
			. ' data-gs-fuss="' . esc_attr( $fuss ) . '"'
			. BI_Detail::gs_mail_rest_attrs() . '>';
		$html .= '<p class="igm-buchen-hinweis">Diese Reihe enthält Teile, deren Freistellung der Arbeitgeber '
			. 'zustimmen muss. Die Anmeldung läuft deshalb über deine Geschäftsstelle – '
			. 'wir bereiten die Anfrage mit deinen gewählten Terminen vor.</p>';
		$html .= '<label class="igm-gs-anfrage__label" for="' . esc_attr( $gs_id ) . '-plz">Postleitzahl deines Arbeitsortes:</label>';
		$html .= '<div class="igm-gs-anfrage__form">';
		$html .= '<input type="text" id="' . esc_attr( $gs_id ) . '-plz" class="igm-gs-anfrage__plz" inputmode="numeric"'
			. ' pattern="[0-9]{5}" maxlength="5" placeholder="z. B. 60329" aria-label="Postleitzahl">';
		$html .= '<button type="button" class="igm-gs-anfrage__find">Geschäftsstelle finden</button>';
		$html .= '</div>';
		$html .= '<div class="igm-gs-anfrage__result" role="status" hidden></div>';
		$html .= '<a class="igm-gs-anfrage__fallback" href="' . esc_url( BI_Detail::gs_such_url() )
			. '" target="_blank" rel="noopener">Oder: zur Geschäftsstellensuche auf igmetall.de</a>';
		$html .= BI_Detail::gs_modal_html( $gs_id );
		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Eine wählbare Terminzeile. Der Auswahlpunkt ist ein echtes Radio, damit
	 * Tastatur und Vorlesehilfe das Muster von sich aus kennen; das Abwählen per
	 * zweitem Klick kommt aus reihe.js.
	 */
	private static function wahl_zeile( $post_id, $feldname, $teil ) {
		$post_id = (int) $post_id;
		$nr      = (string) get_post_meta( $post_id, '_bi_seminarnummer', true );
		$datum   = BI_Detail::zeitraum( $post_id, true );
		$ort     = self::ort( $post_id );
		$voll    = BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' );

		$zusatz = BI_CPT::meta_bool( $post_id, '_bi_kinderbetreuung' ) ? 'Kinderbetreuung' : '';
		if ( $voll ) {
			$zusatz = 'Ausgebucht';
		}

		// Die Vorlesehilfe bekommt die ganze Zeile als einen Satz – einzelne
		// Zellen ohne Zusammenhang („L00027060") helfen niemandem. Genannt wird,
		// was auch dasteht: Die Seminarnummer gehört nicht dazu (siehe unten).
		$label = sprintf(
			'Teil %d, %s, %s%s',
			(int) $teil,
			$datum,
			self::ort_kurz( $ort ),
			$zusatz ? ', ' . $zusatz : ''
		);

		// Zeile für den Mailtext der Geschäftsstellen-Anfrage. Sie wird nur
		// gebraucht, wenn die Gruppe diesen Weg geht – sie hier immer
		// mitzuschreiben ist billiger, als die Weiche bis hierher durchzureichen.
		$mailzeile = 'Teil ' . (int) $teil . ': ' . get_the_title( $post_id )
			. ( $datum ? ', ' . $datum : '' )
			. ( '' !== $ort ? ', ' . $ort : '' )
			. ( '' !== $nr ? ' (' . $nr . ')' : '' );

		$html = '<div class="igm-termin igm-termin--wahl' . ( $voll ? ' igm-termin--aus' : '' ) . '"'
			. ' data-ort="' . esc_attr( $ort ) . '"'
			. ' data-id="' . (int) $post_id . '"'
			. ' data-mail="' . esc_attr( $mailzeile ) . '"'
			. ' data-buchen="' . esc_url( BI_Detail::buchen_url( $post_id ) ) . '">';
		$html .= '<input type="radio" class="igm-termin__radio" name="' . esc_attr( $feldname ) . '"'
			. ' value="' . esc_attr( $post_id ) . '" aria-label="' . esc_attr( $label ) . '"'
			. ( $voll ? ' disabled' : '' ) . '>';
		$html .= '<span class="igm-termin__datum">' . esc_html( $datum ) . '</span>';
		$html .= '<span class="igm-termin__ort">' . esc_html( self::ort_kurz( $ort ) ) . '</span>';
		// OHNE SEMINARNUMMER. Auf dieser Ebene wählt man einen Termin nach Datum
		// und Ort aus – die Nummer beantwortet dabei keine Frage, kostete aber
		// eine eigene Spalte. In der schmalen Gruppenspalte blieb dem Ortsnamen
		// dadurch so wenig Platz, dass er umbrach und sich mit der Nummer
		// überlagerte. Wer die Nummer braucht, findet sie auf der Detailseite
		// des Termins; in die Geschäftsstellen-Mail geht sie weiterhin mit
		// ($mailzeile oben).
		$html .= '<span class="igm-termin__zusatz">' . esc_html( $zusatz ) . '</span>';
		return $html . '</div>';
	}

	/** „Februar bis September 2027" aus den Terminen einer Gruppe. */
	private static function gruppen_zeitraum( $nach_teil ) {
		$von = '';
		$bis = '';
		foreach ( $nach_teil as $posts ) {
			foreach ( $posts as $p ) {
				$s = (string) get_post_meta( $p->ID, '_bi_startdatum', true );
				$e = (string) get_post_meta( $p->ID, '_bi_enddatum', true );
				$e = $e ?: $s;
				if ( $s && ( '' === $von || $s < $von ) ) {
					$von = $s;
				}
				if ( $e && ( '' === $bis || $e > $bis ) ) {
					$bis = $e;
				}
			}
		}
		if ( '' === $von ) {
			return '';
		}
		if ( date_i18n( 'Y-m', strtotime( $von ) ) === date_i18n( 'Y-m', strtotime( $bis ) ) ) {
			return date_i18n( 'F Y', strtotime( $von ) );
		}
		return date_i18n( 'F', strtotime( $von ) ) . ' bis ' . date_i18n( 'F Y', strtotime( $bis ) );
	}

	/** Sidebar-Box „Angaben zur Reihe". */
	private static function angaben_box( $reihe_id ) {
		$meta = function ( $key ) use ( $reihe_id ) {
			return trim( (string) get_post_meta( $reihe_id, $key, true ) );
		};

		$zeilen = '';
		foreach ( array(
			'Zielgruppe'      => $meta( '_bir_zielgruppe' ),
			'Voraussetzungen' => $meta( '_bir_voraussetzungen' ),
			'Freistellung'    => $meta( '_bir_freistellung' ),
			'Seminarleitung'  => $meta( '_bir_leitung' ),
		) as $label => $text ) {
			if ( '' === $text ) {
				continue;
			}
			$zeilen .= '<div class="igm-daten__zeile"><dt>' . esc_html( $label ) . '</dt>'
				. '<dd>' . nl2br( esc_html( $text ) ) . '</dd></div>';
		}
		$info = $meta( '_bir_info' );
		if ( '' !== $info ) {
			$zeilen .= '<div class="igm-daten__zeile"><dt>Weitere Informationen</dt>'
				. '<dd>' . wp_kses_post( $info ) . '</dd></div>';
		}
		$zeilen .= '<div class="igm-daten__zeile"><dt>Kosten</dt>'
			. '<dd>Seminarkosten, Unterkunft und Verpflegung sind beim jeweiligen Termin ausgewiesen.</dd></div>';

		return '<div class="igm-box igm-box--akzent">'
			. '<h2 class="igm-box__titel">Angaben zur Reihe</h2>'
			. '<dl class="igm-daten igm-daten--block" lang="de">' . $zeilen . '</dl>'
			. '</div>';
	}

	/* ===================================================================
	 *  Frontend – Übersicht [bi_reihen]
	 * =================================================================== */

	/**
	 * Kachelübersicht aller veröffentlichten Reihen.
	 *
	 * Attribute:
	 *   titel     Überschrift (leer = keine)
	 *   overline  Zeile darüber, z. B. das Programmjahr
	 *   subline   Zeile darunter
	 *   anzahl    Höchstzahl der Kacheln (Standard: alle)
	 */
	public static function shortcode( $atts ) {
		$a = shortcode_atts( array(
			'titel'    => 'Ausbildungsreihen',
			'overline' => '',
			'subline'  => 'Mehrteilige Reihen mit festen Gruppen – aufeinander aufbauend, in einem Bildungszentrum',
			'anzahl'   => -1,
		), $atts, 'bi_reihen' );

		wp_enqueue_style( 'bi-detailseiten' );

		$reihen = get_posts( array(
			'post_type'   => self::CPT,
			'post_status' => 'publish',
			'numberposts' => (int) $a['anzahl'],
			'orderby'     => 'title',
			'order'       => 'ASC',
			// „Auf der Website anzeigen" gilt hier wie beim Seminar: Die Reihe
			// fällt aus der Übersicht, ihre Seite bleibt über den Link erreichbar.
			'meta_query'  => array( BI_CPT::visible_clause() ),
		) );

		$html = '<div class="igm-seite igm-seite--reihen">';
		if ( '' !== trim( (string) $a['titel'] ) ) {
			$html .= '<div class="igm-kopf igm-breite">'
				. BI_Detail::abschnitt( $a['titel'], $a['subline'], $a['overline'] )
				. '</div>';
		}

		if ( ! $reihen ) {
			return $html . '<p class="igm-reihen__leer igm-breite">Zurzeit ist keine Ausbildungsreihe ausgeschrieben.</p></div>';
		}

		$html .= '<div class="igm-reihen igm-breite">';
		foreach ( $reihen as $r ) {
			$html .= self::kachel( $r );
		}
		return $html . '</div></div>';
	}

	/** Eine Kachel der Übersicht. */
	private static function kachel( $reihe ) {
		$gruppen = self::termine( $reihe->ID );
		$teile   = self::teile( $gruppen );
		$flach   = self::alle_termine( $gruppen );
		$link    = (string) get_permalink( $reihe->ID );

		$teaser = trim( (string) get_the_excerpt( $reihe ) );
		if ( '' === $teaser ) {
			$teaser = wp_trim_words( wp_strip_all_tags( (string) $reihe->post_content ), 26 );
		}

		$anzahl_gruppen = count( array_filter( array_keys( $gruppen ) ) );
		$orte           = array();
		$start          = '';
		foreach ( $flach as $p ) {
			$o = self::ort_kurz( self::ort( $p->ID ) );
			if ( '' !== $o && ! in_array( $o, $orte, true ) ) {
				$orte[] = $o;
			}
			$roh = (string) get_post_meta( $p->ID, '_bi_startdatum', true );
			if ( '' === $start && $roh ) {
				$start = date_i18n( 'm/Y', strtotime( $roh ) );
			}
		}

		$html = '<article class="igm-reihen__kachel">';

		$html .= '<div class="igm-reihen__bild">';
		if ( has_post_thumbnail( $reihe->ID ) ) {
			$html .= '<a href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">'
				. get_the_post_thumbnail( $reihe->ID, 'medium_large', array( 'alt' => '' ) ) . '</a>';
		}
		if ( $teile ) {
			$html .= '<span class="igm-reihen__label">'
				. esc_html( sprintf( _n( '%d Teil', '%d Teile', count( $teile ), 'bi-seminarsuche' ), count( $teile ) ) )
				. '</span>';
		}
		$html .= '</div>';

		$html .= '<div class="igm-reihen__body">';
		$html .= '<h3 class="igm-reihen__titel"><a href="' . esc_url( $link ) . '">'
			. esc_html( get_the_title( $reihe ) ) . '</a></h3>';
		if ( '' !== $teaser ) {
			$html .= '<p class="igm-reihen__teaser">' . esc_html( $teaser ) . '</p>';
		}

		$meta = '';
		if ( $anzahl_gruppen ) {
			$meta .= '<strong>' . esc_html( sprintf( _n( '%d Gruppe', '%d Gruppen', $anzahl_gruppen, 'bi-seminarsuche' ), $anzahl_gruppen ) ) . '</strong>';
		}
		if ( '' !== $start ) {
			$meta .= '<span>ab ' . esc_html( $start ) . '</span>';
		}
		if ( $orte ) {
			$meta .= '<span>' . esc_html( count( $orte ) > 2
				? sprintf( '%d Orte', count( $orte ) )
				: implode( ', ', $orte ) ) . '</span>';
		}
		if ( '' !== $meta ) {
			$html .= '<div class="igm-reihen__meta">' . $meta . '</div>';
		}
		if ( BI_CPT::meta_bool( $reihe->ID, '_bir_komplett' ) ) {
			$html .= '<span class="igm-reihen__komplett">Nur komplett buchbar</span>';
		}

		return $html . '</div></article>';
	}

	/* ===================================================================
	 *  Hinweis auf der Seminar-Detailseite
	 * =================================================================== */

	/**
	 * Badge-Text für die Seminar-Detailseite: „Teil 2 der Reihe „…"".
	 * Leer, wenn der Termin keiner Reihe angehört oder die Reihe noch Entwurf
	 * ist – ein Link auf einen Entwurf führt Besucher ins Leere.
	 */
	public static function hinweis( $post_id ) {
		$rid = (int) get_post_meta( $post_id, self::META_REIHE, true );
		if ( ! $rid || 'publish' !== get_post_status( $rid ) ) {
			return '';
		}
		$teil = (int) get_post_meta( $post_id, self::META_TEIL, true );
		return sprintf(
			'<a href="%s">Teil %d der Reihe „%s"</a>',
			esc_url( (string) get_permalink( $rid ) ),
			$teil,
			esc_html( get_the_title( $rid ) )
		);
	}

	/**
	 * Post-ID der Reihe, wenn dieses Seminar NUR als Teil einer komplett
	 * buchbaren Reihe zu haben ist – sonst 0.
	 *
	 * Der Haken „Nur komplett buchbar" sitzt an der Reihe und sagt: Eine
	 * Anmeldung zu einem einzelnen Teil gibt es nicht. Die Reihenseite hielt
	 * sich daran (auswahl_pruefen weist eine unvollständige Auswahl ab), die
	 * Seminarseite wusste bis dahin nichts davon: Wer über die Suche direkt auf
	 * einen Teil kam, sah dort seinen gewohnten Buchen-Button und meldete sich
	 * zu einem Baustein an, den es einzeln gar nicht gibt.
	 *
	 * Eine Reihe im Entwurf zählt nicht. Auf eine unveröffentlichte Reihenseite
	 * lässt sich niemand verweisen, und ein weggenommener Button ohne Ziel wäre
	 * eine Sackgasse – dann bleibt es beim gewohnten Weg. Dieselbe Regel wie in
	 * hinweis(), aus demselben Grund.
	 */
	public static function nur_komplett( $post_id ) {
		$rid = (int) get_post_meta( (int) $post_id, self::META_REIHE, true );
		if ( ! $rid || 'publish' !== get_post_status( $rid ) ) {
			return 0;
		}
		return BI_CPT::meta_bool( $rid, '_bir_komplett' ) ? $rid : 0;
	}

	/**
	 * Was auf der Seminarseite an der Stelle des Buchen-Buttons steht, wenn die
	 * Reihe nur komplett buchbar ist: der Satz, warum hier nichts zu buchen
	 * ist, und der Weg dorthin, wo es geht.
	 *
	 * Leerer String bei einem frei buchbaren Seminar – die aufrufende Stelle
	 * fragt damit in einem Zug „betrifft mich das?" und „was steht dann da?".
	 */
	public static function komplett_verweis( $post_id ) {
		$rid = self::nur_komplett( $post_id );
		if ( ! $rid ) {
			return '';
		}

		$titel = esc_html( get_the_title( $rid ) );
		$teil  = (int) get_post_meta( (int) $post_id, self::META_TEIL, true );

		// Ohne Teilnummer (unsauber gepflegtes „Teil | Reihe") bleibt der Satz
		// wahr, nur ungenauer – das ist besser als eine erfundene „Teil 0".
		$satz = $teil
			? sprintf( 'Dieses Seminar ist <strong>Teil %d</strong> der Ausbildungsreihe „%s".', $teil, $titel )
			: sprintf( 'Dieses Seminar gehört zur Ausbildungsreihe „%s".', $titel );

		return '<div class="igm-reihe-verweis">'
			. '<p class="igm-buchen-hinweis">' . $satz
			. ' Sie lässt sich nur vollständig buchen – alle Teile werden auf der Reihenseite'
			. ' in einem Zug angemeldet.</p>'
			. '<a class="igm-btn-buchen" href="' . esc_url( (string) get_permalink( $rid ) ) . '">'
			. 'Zur Ausbildungsreihe</a>'
			. '</div>';
	}

	/* ===================================================================
	 *  Prüfliste für die Datenpflege
	 * =================================================================== */

	/**
	 * Termine, die eine Teil-Angabe tragen, aber keiner Reihe zugeordnet werden
	 * konnten – weil der Reihenname fehlt oder das Format nicht stimmt.
	 *
	 * Genau das ist der häufigste Fall in den Quelldaten: Rund 300 Einträge
	 * tragen nur „Teil 1: <Titel>" ohne den Reihennamen dahinter.
	 *
	 * @return array Liste aus [post, roh, grund]
	 */
	public static function pruefliste( $limit = 200 ) {
		$q = new WP_Query( array(
			'post_type'      => bi_seminar_post_types(),
			'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'posts_per_page' => (int) $limit,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array(
				array( 'key' => self::META_ROH, 'value' => '', 'compare' => '!=' ),
				array( 'key' => self::META_REIHE, 'compare' => 'NOT EXISTS' ),
			),
		) );

		$out = array();
		foreach ( $q->posts as $p ) {
			$roh = (string) get_post_meta( $p->ID, self::META_ROH, true );
			if ( false === strpos( $roh, '|' ) ) {
				$grund = 'kein Reihenname – es fehlt der Teil hinter dem senkrechten Strich';
			} elseif ( ! self::parse( $roh ) ) {
				$grund = 'links vom Strich steht nicht nur „Teil <Zahl>"';
			} else {
				$grund = 'Reihe konnte nicht angelegt werden';
			}
			$out[] = array( 'post' => $p, 'roh' => $roh, 'grund' => $grund );
		}
		return array( $out, (int) $q->found_posts );
	}

	/** Alle Reihen mit Kennzahlen – für die Übersicht in der Datenpflege. */
	public static function uebersicht() {
		$reihen = get_posts( array(
			'post_type'   => self::CPT,
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );
		$out = array();
		foreach ( $reihen as $r ) {
			$gruppen = self::termine( $r->ID, false );
			$out[]   = array(
				'post'       => $r,
				'teile'      => count( self::teile( $gruppen ) ),
				'durchgaenge'=> count( array_filter( array_keys( $gruppen ) ) ),
				'termine'    => self::termin_anzahl( $r->ID ),
				'text'       => '' !== trim( (string) $r->post_content ),
			);
		}
		return $out;
	}
}
