<?php
/**
 * Such- & Filterleiste + Ergebnisliste – Shortcode [bi_seminarsuche].
 *
 * Liest die GET-Parameter q, form, ort, thema, ziel, frei, von, bis, nr
 * (Mehrfachwerte pipe-getrennt: ?ziel=A|B bzw. ?nr=LO12345|BO67890) und baut
 * daraus eine WP_Query gegen beide Seminar-Beitragstypen. nr filtert auf die
 * Seminarnummer (_bi_seminarnummer). Facetten sind Taxonomien -> echtes ODER
 * über tax_query (operator IN). "Buchbar" = _bi_startdatum >= heute.
 *
 * Sonderfall Facette „form" (Seminarform): sie hängt an keiner Taxonomie,
 * sondern schaltet zwischen den Beitragstypen
 *   praesenz -> bi_seminar
 *   online   -> bi_online
 * Der Chip erscheint nur, wenn im aktuellen Ergebnis-Universum tatsächlich
 * beide Formen vorkommen.
 *
 * Attribute:
 *   [bi_seminarsuche anmeldung_url="/anmeldung"]  Ziel des "Anmelden"-Buttons
 *   [bi_seminarsuche per_page="20"]
 *   [bi_seminarsuche form="online"]               Seite fest auf eine Seminarform beschränken
 *
 * Zusätzlich [bi_suchmaske]: nur die Such-/Filterleiste, ohne Ergebnisliste – für
 * Startseite, Sidebar o. ä. Die Filter wirken dort NICHT sofort, sondern werden
 * gesammelt; ein Button "Suche starten" springt mit allen gewählten Parametern auf
 * die Seite mit [bi_seminarsuche]. Die Trefferzahl und die Facetten-Zähler werden
 * per AJAX (Action bi_filter_refresh) live nachgeladen.
 *
 *   [bi_suchmaske]                             Ziel = Einstellung "Seminarübersicht"
 *   [bi_suchmaske ziel_url="/seminare"]        Zielseite manuell setzen
 *   [bi_suchmaske button="Seminare anzeigen"]  Beschriftung des Buttons
 *   [bi_suchmaske titel="…" kicker="…" hinweis="…" programm="2026" form="online"]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Filter {

	/** GET-Param => Taxonomie + multi-Flag. 'form' ist eine Pseudo-Facette ohne Taxonomie. */
	public static function facets() {
		return array(
			'form'  => array( 'tax' => '',            'multi' => true ),
			'ort'   => array( 'tax' => BI_TAX_ORT,    'multi' => true ),
			'thema' => array( 'tax' => BI_TAX_THEMA,  'multi' => true ),
			'ziel'  => array( 'tax' => BI_TAX_ZIEL,   'multi' => true ),
			'frei'  => array( 'tax' => BI_TAX_FREI,   'multi' => true ),
		);
	}

	/** Alle Parameternamen, die eine Suche transportiert (auch für Kacheln/AJAX). */
	public static function query_params() {
		return array_merge( array( 'q' ), array_keys( self::facets() ), array( 'von', 'bis', 'nr' ) );
	}

	/* ---------- Seminarform (Pseudo-Facette „form") ---------- */

	/** Filterwert => Beitragstyp */
	public static function form_map() {
		return array(
			'praesenz' => BI_CPT,
			'online'   => BI_ONLINE,
		);
	}

	/** Filterwert => Anzeigename */
	private static function form_labels() {
		return array(
			'praesenz' => 'Präsenz-Seminare',
			'online'   => 'Online-Seminare',
		);
	}

	/** Umkehrung: Beitragstyp => Filterwert */
	private static function form_key_for( $post_type ) {
		$key = array_search( $post_type, self::form_map(), true );
		return $key ?: '';
	}

	/** Vom Shortcode fest vorgegebene Seminarform (leer = beide, per Chip wählbar) */
	private static $force_form = '';

	/**
	 * Beitragstypen der aktuellen Abfrage: Shortcode-Vorgabe schlägt Chip-Auswahl,
	 * ohne beides gelten beide Typen.
	 */
	private static function selected_post_types( $skip_facet = '' ) {
		$map = self::form_map();

		if ( '' !== self::$force_form && isset( $map[ self::$force_form ] ) ) {
			return array( $map[ self::$force_form ] );
		}
		if ( 'form' === $skip_facet || '' === bi_get( 'form' ) ) {
			return bi_seminar_post_types();
		}
		$vals = array_filter( array_map( 'trim', explode( '|', sanitize_text_field( bi_get( 'form' ) ) ) ), 'strlen' );

		$types = array();
		foreach ( $vals as $v ) {
			if ( isset( $map[ $v ] ) ) {
				$types[] = $map[ $v ];
			}
		}
		return $types ? array_values( array_unique( $types ) ) : bi_seminar_post_types();
	}

	/** Optionen des Seminarform-Chips aus den Beitragstyp-Zählern [post_type => n] */
	private static function form_options( $counts ) {
		$opts = array();
		foreach ( self::form_labels() as $value => $label ) {
			$pt = self::form_map()[ $value ];
			$c  = isset( $counts[ $pt ] ) ? (int) $counts[ $pt ] : 0;
			if ( $c <= 0 ) {
				continue; // Form ohne kommende Termine nicht anbieten
			}
			$opts[] = array( 'value' => $value, 'label' => $label, 'count' => $c );
		}
		return $opts;
	}

	/* ---------- Bildungszentrum-Gruppierung (reine Filter-Anzeige) ----------
	 * Reduziert die vielen echten Seminarorte auf wenige Filter-Gruppen, OHNE
	 * Begriffe oder Seminar-Zuordnungen zu verändern. Wird nur beim Aufbau der
	 * Filteroptionen und beim Übersetzen der Auswahl in die Query verwendet –
	 * der angezeigte Ort am Seminar bleibt immer der echte Term-Name.
	 */

	/** Gruppen-Regeln: Filter-Label => Teilstrings (klein), die im Ortsnamen vorkommen. */
	private static function ort_group_rules() {
		return array(
			'Bildungszentrum Sprockhövel' => array( 'sprockhövel', 'hattingen' ),
			'Bildungszentrum Berlin'      => array( 'berlin' ),
			'Kritische Akademie Inzell'   => array( 'inzell' ),
			'Bildungszentrum Beverungen'  => array( 'beverungen' ),
			'Bildungszentrum Bad Orb'     => array( 'bad orb' ),
			'Bildungszentrum Lohr'        => array( 'lohr' ),
			'Bildungszentrum Schliersee'  => array( 'schliersee' ),
			'Vorstand'                    => array( 'vorstand' ),
		);
	}

	/** Anzeigereihenfolge der Filter-Gruppen (Sammelgruppe „Andere" zuletzt). */
	private static function ort_group_order() {
		return array_merge( array_keys( self::ort_group_rules() ), array( 'Andere' ) );
	}

	/** Filter-Gruppe für einen echten Ortsnamen; Fallback „Andere". */
	private static function ort_group_for( $name ) {
		$n = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		foreach ( self::ort_group_rules() as $group => $needles ) {
			foreach ( $needles as $needle ) {
				if ( '' !== $needle && false !== strpos( $n, $needle ) ) {
					return $group;
				}
			}
		}
		return 'Andere';
	}

	/** Echte Ortsbegriffe (Term-Namen), die zu den gewählten Gruppen-Labels gehören. */
	private static function ort_terms_for_groups( $groups ) {
		$terms = get_terms( array( 'taxonomy' => BI_TAX_ORT, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			if ( in_array( self::ort_group_for( $term->name ), $groups, true ) ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}

	/**
	 * Zielgruppen-Mapping: echter Term-Name => Anzeige-Gruppe.
	 * Mehrere Terme dürfen auf dieselbe Gruppe zeigen (Zusammenfassung). Nicht gelistete
	 * Terme behalten ihren eigenen Namen als Gruppe. Echte Begriffe bleiben unverändert.
	 */
	private static function ziel_label_map() {
		return array(
			'junge Arbeitnehmer*innen'                           => 'Junge Arbeitnehmer*innen',
			'stellvertretende Betriebsratsvorsitzende'           => 'Stellv. BR-Vorsitzende',
			'Mitglieder der Tarifkommission'                     => 'Tarifkommission',
			'Jugend- und Auszubildendenvertretung'               => 'JAV',
			'Jugend- und Auszubildendenvertretungen'             => 'JAV',
			'JAV-Vorsitzende und Stellvertreter*innen'           => 'JAV',
			'Wirtschaftsausschuss-Mitglieder'                    => 'Wirtschaftsausschuss',
			'Mitglieder des Wirtschaftsausschusses'              => 'Wirtschaftsausschuss',
			'am Wirtschaftsausschuss interessierte Beschäftigte' => 'Wirtschaftsausschuss',
		);
	}

	/** Anzeige-Gruppe für einen echten Zielgruppen-Namen; Fallback = der Name selbst. */
	private static function ziel_group_for( $name ) {
		$map = self::ziel_label_map();
		return isset( $map[ $name ] ) ? $map[ $name ] : $name;
	}

	/** Echte Zielgruppen-Begriffe, die zu den gewählten Gruppen-Labels gehören. */
	private static function ziel_terms_for_groups( $groups ) {
		$terms = get_terms( array( 'taxonomy' => BI_TAX_ZIEL, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			if ( in_array( self::ziel_group_for( $term->name ), $groups, true ) ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}

	/**
	 * Baut gruppierte Optionen: fasst Terme über $group_fn zu Anzeige-Gruppen zusammen und
	 * summiert deren Zähler. value = Gruppen-Label (wird in der Query auf die echten Begriffe
	 * ausgeweitet). $order (optional) gibt eine feste Anzeigereihenfolge der Gruppen vor.
	 */
	private static function grouped_options( $terms, $counts, $group_fn, $order = null ) {
		$group_count = array();
		foreach ( $terms as $term ) {
			$c = isset( $counts[ $term->term_id ] ) ? $counts[ $term->term_id ] : 0;
			if ( $c <= 0 ) {
				continue; // ohne kommende Seminare nicht anbieten
			}
			$g = call_user_func( $group_fn, $term->name );
			$group_count[ $g ] = ( isset( $group_count[ $g ] ) ? $group_count[ $g ] : 0 ) + $c;
		}
		$opts = array();
		if ( is_array( $order ) ) {
			foreach ( $order as $g ) {
				if ( ! empty( $group_count[ $g ] ) ) {
					$opts[] = array( 'value' => $g, 'label' => $g, 'count' => $group_count[ $g ] );
				}
			}
		} else {
			foreach ( $group_count as $g => $c ) {
				$opts[] = array( 'value' => $g, 'label' => $g, 'count' => $c );
			}
		}
		return $opts;
	}

	/** Anzeige-Label für Themenfeld-Begriffe (echter Term bleibt unverändert; Match per Präfix). */
	private static function thema_label( $name ) {
		if ( 0 === stripos( $name, 'VL kompakt' ) ) {
			return 'Grundlagen für Vertrauensleute';
		}
		if ( 0 === stripos( $name, 'BR kompakt' ) ) {
			return 'Grundlagen für Betriebsrät*innen';
		}
		return $name;
	}

	/**
	 * Themenfeld-Optionen sortieren: die beiden „Grundlagen…"-Filter immer ganz nach oben
	 * (außerhalb der alphabetischen Reihenfolge), danach ein Zeilenumbruch, dann der Rest.
	 */
	private static function thema_order_options( $opts ) {
		$pinned_labels = array( 'Grundlagen für Betriebsrät*innen', 'Grundlagen für Vertrauensleute' );
		$pinned = array();
		$rest   = array();
		foreach ( $opts as $o ) {
			$idx = array_search( $o['label'], $pinned_labels, true );
			if ( false !== $idx ) {
				$pinned[ $idx ] = $o; // an gewünschter Position einsortieren
			} else {
				$rest[] = $o;
			}
		}
		if ( ! $pinned ) {
			return $opts;
		}
		ksort( $pinned );
		$pinned = array_values( $pinned );
		if ( $rest ) {
			$pinned[] = array( 'separator' => true ); // erzwingt im Grid eine neue Zeile
		}
		return array_merge( $pinned, $rest );
	}

	/**
	 * Zielgruppen-Optionen sortieren: „Betriebsrät*innen" und „Vertrauensleute" immer ganz
	 * nach oben (außerhalb der alphabetischen Reihenfolge), danach die alphabetische Liste.
	 */
	private static function ziel_order_options( $opts ) {
		$pinned_labels = array( 'Betriebsrät*innen', 'Schwerbehindertenvertretung', 'Vertrauensleute' );
		$pinned = array();
		$rest   = array();
		foreach ( $opts as $o ) {
			$idx = array_search( $o['label'], $pinned_labels, true );
			if ( false !== $idx ) {
				$pinned[ $idx ] = $o;
			} else {
				$rest[] = $o;
			}
		}
		if ( ! $pinned ) {
			return $opts;
		}
		ksort( $pinned );
		$pinned = array_values( $pinned );
		// Restliche Optionen alphabetisch nach Anzeige-Label
		usort( $rest, function ( $a, $b ) {
			return strcasecmp( $a['label'], $b['label'] );
		} );
		if ( $rest ) {
			$pinned[] = array( 'separator' => true ); // gepinnte Kriterien optisch absetzen
		}
		return array_merge( $pinned, $rest );
	}

	/**
	 * Anzahl Seminare je Term im aktuellen Filter-Universum (veröffentlicht, sichtbar,
	 * Start ab heute, optional auf ein Programmjahr beschränkt). Liefert [ term_id => Anzahl ].
	 */
	private static function facet_counts( $programm = '', $skip_facet = '' ) {
		return self::term_counts_for_ids( self::count_base_ids( $programm, $skip_facet ) );
	}

	/**
	 * Post-IDs des aktuellen Filter-Universums: kommend + sichtbar, Datumsbereich, Freitextsuche
	 * und alle bereits gewählten Facetten – optional ohne eine bestimmte Facette ($skip_facet).
	 */
	private static function count_base_ids( $programm = '', $skip_facet = '' ) {
		$args = self::build_args(
			array( 'fields' => 'ids', 'nopaging' => true, 'orderby' => 'none' ),
			$programm,
			$skip_facet
		);
		if ( self::$title_query ) {
			$args['bi_title_search'] = 1; // Freitext-Suche im posts_where-Filter aktivieren
		}
		$q = new WP_Query( $args );
		return $q->posts;
	}

	/** Term-Zähler [ term_id => Anzahl ] über eine Menge von Post-IDs (eine gruppierte Abfrage). */
	private static function term_counts_for_ids( $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results(
			"SELECT tt.term_id AS term_id, COUNT(*) AS c
			 FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 WHERE tr.object_id IN ($in)
			 GROUP BY tt.term_id",
			ARRAY_A
		);
		$map = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$map[ (int) $r['term_id'] ] = (int) $r['c'];
			}
		}
		return $map;
	}

	/** Beitragstyp-Zähler [ post_type => Anzahl ] über eine Menge von Post-IDs. */
	private static function post_type_counts_for_ids( $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results(
			"SELECT post_type, COUNT(*) AS c FROM {$wpdb->posts} WHERE ID IN ($in) GROUP BY post_type",
			ARRAY_A
		);
		$map = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$map[ (string) $r['post_type'] ] = (int) $r['c'];
			}
		}
		return $map;
	}

	private static $title_query = '';

	/**
	 * Optionen einer Facette – exakt wie in der Frontend-Filterleiste: nur Einträge
	 * mit kommenden Seminaren, gleiche Gruppierung (Bildungszentren, Zielgruppen),
	 * gleiche Anzeigenamen und Sortierung (inkl. gepinnter Einträge + Separatoren).
	 * Einträge: [ value, label, count ] oder [ separator => true ].
	 *
	 * @param string     $param  Facetten-Parameter.
	 * @param array|null $counts Zähler: Term-IDs (Taxonomie-Facetten) bzw. Beitragstypen
	 *                           (Facette „form"). null = selbst berechnen.
	 */
	public static function frontend_options( $param, $counts = null ) {
		$facets = self::facets();
		if ( ! isset( $facets[ $param ] ) ) {
			return array();
		}

		if ( 'form' === $param ) {
			if ( null === $counts ) {
				// eigene Auswahl ausklammern, sonst zeigt der Chip nur die gewählte Form
				$counts = self::post_type_counts_for_ids( self::count_base_ids( '', 'form' ) );
			}
			return self::form_options( $counts );
		}

		if ( null === $counts ) {
			$counts = self::facet_counts();
		}
		$terms = get_terms( array( 'taxonomy' => $facets[ $param ]['tax'], 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		if ( 'ort' === $param ) {
			// Echte Orte zu festen Filter-Gruppen zusammenfassen (feste Reihenfolge).
			return self::grouped_options( $terms, $counts, array( __CLASS__, 'ort_group_for' ), self::ort_group_order() );
		}
		if ( 'ziel' === $param ) {
			// Zielgruppen gruppieren (inkl. JAV/Wirtschaftsausschuss-Zusammenfassung), dann gepinnt + alphabetisch.
			return self::ziel_order_options(
				self::grouped_options( $terms, $counts, array( __CLASS__, 'ziel_group_for' ), null )
			);
		}

		$opts = array();
		foreach ( $terms as $term ) {
			$c = isset( $counts[ $term->term_id ] ) ? $counts[ $term->term_id ] : 0;
			if ( $c <= 0 ) {
				continue; // Optionen ohne kommende Seminare ausblenden
			}
			$label  = ( 'thema' === $param ) ? self::thema_label( $term->name ) : $term->name;
			$opts[] = array( 'value' => $term->name, 'label' => $label, 'count' => $c );
		}
		if ( 'thema' === $param ) {
			$opts = self::thema_order_options( $opts );
		}
		return $opts;
	}

	/**
	 * Auswahl-Optionen je Facette für den Kachel-Builder – dieselbe Auswahl wie in
	 * der Frontend-Filterleiste (frontend_options), für alle Facetten auf einmal.
	 */
	public static function facet_choices() {
		$base_ids = self::count_base_ids();
		$terms    = self::term_counts_for_ids( $base_ids );
		$types    = self::post_type_counts_for_ids( $base_ids );

		$choices = array();
		foreach ( array_keys( self::facets() ) as $param ) {
			$choices[ $param ] = self::frontend_options( $param, ( 'form' === $param ) ? $types : $terms );
		}
		return $choices;
	}

	/**
	 * Anzahl buchbarer Seminare für einen Parametersatz (q, form, ort, thema, ziel,
	 * frei, von, bis, nr – Werte wie in der URL, unkodiert). Wird von den Marketing-Kacheln
	 * genutzt, um die Trefferzahl eines Kachel-Links zu ermitteln. Setzt $_GET
	 * vorübergehend auf die Parameter, damit build_args unverändert greift.
	 */
	public static function count_for_params( array $params, $programm = '' ) {
		$get_backup   = $_GET;
		$title_backup = self::$title_query;

		$_GET = array();
		foreach ( $params as $key => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$_GET[ $key ] = $value;
			}
		}
		self::$title_query = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';

		$count = count( self::count_base_ids( $programm ) );

		$_GET              = $get_backup;
		self::$title_query = $title_backup;
		return $count;
	}

	public static function init() {
		add_shortcode( 'bi_seminarsuche', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'bi_suchmaske', array( __CLASS__, 'shortcode_maske' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'posts_where', array( __CLASS__, 'title_where' ), 10, 2 );

		// Live-Zähler + Facetten für die eigenständige Suchmaske (lesend, daher auch für Gäste)
		add_action( 'wp_ajax_bi_filter_refresh', array( __CLASS__, 'ajax_refresh' ) );
		add_action( 'wp_ajax_nopriv_bi_filter_refresh', array( __CLASS__, 'ajax_refresh' ) );
	}

	public static function register_assets() {
		$fp = BI_URL . 'assets/vendor/flatpickr/';

		// flatpickr-Bibliothek + deutsche Lokalisierung (de.js setzt flatpickr.l10ns.de)
		wp_register_script( 'flatpickr', $fp . 'flatpickr.min.js', array(), '4.6.13', true );
		wp_register_script( 'flatpickr-de', $fp . 'de.js', array( 'flatpickr' ), '4.6.13', true );
		wp_register_style( 'flatpickr', $fp . 'flatpickr.min.css', array(), '4.6.13' );
		wp_register_style( 'flatpickr-igm', $fp . 'flatpickr-igm.css', array( 'flatpickr' ), '4.6.13' );

		wp_register_style( 'bi-filterleiste', BI_URL . 'assets/css/filterleiste.css', array(), BI_VERSION );
		wp_register_style( 'bi-frontend', BI_URL . 'assets/css/frontend.css', array(), BI_VERSION );
		// filterleiste.js läuft erst nach flatpickr + de-Lokalisierung
		wp_register_script( 'bi-filterleiste', BI_URL . 'assets/js/filterleiste.js', array( 'flatpickr-de' ), BI_VERSION, true );
	}

	/** Kategorien (inkl. Optionen aus den vorhandenen Terms) für die JS-Konfiguration */
	private static function js_config( $programm = '' ) {
		$icons  = array( 'form' => 'screen', 'ort' => 'pin', 'thema' => 'tag', 'ziel' => 'users', 'frei' => 'file' );
		$labels = array( 'form' => 'Seminarform', 'ort' => 'Bildungszentrum', 'thema' => 'Themenfeld', 'ziel' => 'Zielgruppe', 'frei' => 'Freistellung' );

		// Facettierte Zähler: Basis sind alle bereits gewählten Filter (kommend + sichtbar,
		// Datumsbereich, Suche, ggf. Programmjahr). Pro Facette mit eigener Auswahl werden die
		// Zähler OHNE diese Auswahl berechnet, damit man innerhalb der Facette noch wechseln kann.
		$base_ids    = self::count_base_ids( $programm );
		$full_terms  = self::term_counts_for_ids( $base_ids );
		$full_types  = self::post_type_counts_for_ids( $base_ids );

		$cats = array();
		foreach ( self::facets() as $param => $f ) {
			$own = ( '' !== bi_get( $param ) );

			if ( 'form' === $param ) {
				// Bei fest vorgegebener Seminarform (Shortcode-Attribut) keinen Chip anbieten.
				if ( '' !== self::$force_form ) {
					continue;
				}
				$counts = $own ? self::post_type_counts_for_ids( self::count_base_ids( $programm, 'form' ) ) : $full_types;
				$opts   = self::form_options( $counts );
				// Nur anbieten, wenn es etwas zu unterscheiden gibt – oder wenn bereits
				// eine Form gewählt ist, damit der Chip zum Zurücknehmen sichtbar bleibt.
				if ( count( $opts ) < 2 && ! $own ) {
					continue;
				}
			} else {
				$counts = $own ? self::facet_counts( $programm, $param ) : $full_terms;
				$opts   = self::frontend_options( $param, $counts );
			}

			// Zähler als eigenes Feld (im JS als dezente graue Zahl hinter dem Label gerendert).
			foreach ( $opts as &$o ) {
				if ( isset( $o['count'] ) ) {
					$o['count'] = number_format_i18n( $o['count'] );
				}
			}
			unset( $o );

			$cats[] = array(
				'param'   => $param,
				'label'   => $labels[ $param ],
				'icon'    => $icons[ $param ],
				'multi'   => (bool) $f['multi'],
				'options' => $opts,
			);
		}
		// Datumsbereich
		$cats[] = array( 'type' => 'daterange', 'label' => 'Zeitraum', 'icon' => 'calendar', 'fromParam' => 'von', 'toParam' => 'bis' );

		return array(
			'searchParam' => 'q',
			'root'        => '#bi-filter',
			'categories'  => $cats,
		);
	}

	/** ---------- Query-Aufbau ---------- */

	private static function build_args( $extra = array(), $programm = '', $skip_facet = '' ) {
		$today = current_time( 'Y-m-d' );

		$meta_query = array(
			'relation' => 'AND',
			// Benannte Klausel -> nach ihr wird sortiert (eindeutig trotz weiterer Datums-Filter)
			'startdatum' => array( 'key' => '_bi_startdatum', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
			BI_CPT::visible_clause(), // Anzeigen? = nein ausblenden
		);
		if ( '' !== bi_get( 'von' ) ) {
			$meta_query[] = array( 'key' => '_bi_startdatum', 'value' => sanitize_text_field( bi_get( 'von' ) ), 'compare' => '>=', 'type' => 'DATE' );
		}
		if ( '' !== bi_get( 'bis' ) ) {
			$meta_query[] = array( 'key' => '_bi_startdatum', 'value' => sanitize_text_field( bi_get( 'bis' ) ), 'compare' => '<=', 'type' => 'DATE' );
		}
		// Seminarnummern (pipe-getrennt, z. B. von Marketing-Kacheln): ODER innerhalb der Liste
		if ( '' !== bi_get( 'nr' ) ) {
			$nrs = array_filter( array_map( 'trim', explode( '|', sanitize_text_field( bi_get( 'nr' ) ) ) ), 'strlen' );
			if ( $nrs ) {
				$meta_query[] = array( 'key' => '_bi_seminarnummer', 'value' => $nrs, 'compare' => 'IN' );
			}
		}

		$tax_query = array();
		foreach ( self::facets() as $param => $f ) {
			if ( 'form' === $param || '' === $f['tax'] ) {
				continue; // Seminarform wirkt über post_type, nicht über eine Taxonomie
			}
			if ( $param === $skip_facet ) {
				continue; // eigene Facette für deren Zähler ausblenden (facettierte Suche)
			}
			if ( '' === bi_get( $param ) ) {
				continue;
			}
			$vals = array_filter( array_map( 'trim', explode( '|', bi_get( $param ) ) ), 'strlen' );
			if ( ! $vals ) {
				continue;
			}
			$vals = array_map( 'sanitize_text_field', $vals );
			if ( 'ort' === $param ) {
				// Gewählte Bildungszentrum-Gruppen auf die echten Ortsbegriffe ausweiten
				$vals = self::ort_terms_for_groups( $vals );
				if ( ! $vals ) {
					continue;
				}
			} elseif ( 'ziel' === $param ) {
				// Gewählte Zielgruppen-Gruppen (inkl. JAV/Wirtschaftsausschuss) auf echte Begriffe ausweiten
				$vals = self::ziel_terms_for_groups( $vals );
				if ( ! $vals ) {
					continue;
				}
			}
			$tax_query[] = array(
				'taxonomy' => $f['tax'],
				'field'    => 'name',
				'terms'    => $vals,
				'operator' => 'IN', // ODER innerhalb einer Facette
			);
		}
		// Auf ein Programmjahr beschränken (Shortcode-Attribut programm="2026")
		if ( '' !== $programm ) {
			$tax_query[] = array(
				'taxonomy' => BI_TAX_PROGRAMM,
				'field'    => 'name',
				'terms'    => array( $programm ),
			);
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND'; // UND zwischen verschiedenen Facetten
		}

		$args = array(
			'post_type'   => self::selected_post_types( $skip_facet ),
			'post_status' => 'publish',
			// Immer aufsteigend nach Startdatum (ab heute) sortieren
			'orderby'     => array( 'startdatum' => 'ASC' ),
			'meta_query'  => $meta_query,
		);
		if ( $tax_query ) {
			$args['tax_query'] = $tax_query;
		}
		return array_merge( $args, $extra );
	}

	/**
	 * Gesamtzahl buchbarer Seminare ohne jeden Filter (optional auf ein Programmjahr
	 * beschränkt). Bezugsgröße für die Anzeige "… von N Seminaren". Eine fest
	 * vorgegebene Seminarform (Shortcode-Attribut) wirkt auch hier.
	 */
	private static function total_count( $programm = '' ) {
		$map   = self::form_map();
		$types = ( '' !== self::$force_form && isset( $map[ self::$force_form ] ) )
			? array( $map[ self::$force_form ] )
			: bi_seminar_post_types();

		$args = array(
			'post_type'   => $types,
			'post_status' => 'publish',
			'fields'      => 'ids',
			'nopaging'    => true,
			'meta_query'  => array(
				array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
				BI_CPT::visible_clause(),
			),
		);
		if ( '' !== $programm ) {
			$args['tax_query'] = array(
				array( 'taxonomy' => BI_TAX_PROGRAMM, 'field' => 'name', 'terms' => array( $programm ) ),
			);
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/** Titel-Suche (q) per posts_where, damit nur der Titel durchsucht wird */
	public static function title_where( $where, $query ) {
		if ( self::$title_query && $query->get( 'bi_title_search' ) ) {
			global $wpdb;
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s ", '%' . $wpdb->esc_like( self::$title_query ) . '%' );
		}
		return $where;
	}

	/** Shortcode-Attribut form="online|praesenz" prüfen */
	private static function sanitize_form_att( $value ) {
		$value = sanitize_key( $value );
		return isset( self::form_map()[ $value ] ) ? $value : '';
	}

	/** ---------- Shortcode ---------- */

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'anmeldung_url' => '',
			'per_page'      => 20,
			'programm'      => '', // auf ein Programmjahr beschränken, z. B. programm="2026"
			'form'          => '', // auf eine Seminarform beschränken: praesenz | online
		), $atts, 'bi_seminarsuche' );
		$programm = sanitize_text_field( $atts['programm'] );
		// Früh setzen, damit die facettierten Zähler in js_config die Freitextsuche berücksichtigen.
		self::$title_query = sanitize_text_field( bi_get( 'q' ) );
		self::$force_form  = self::sanitize_form_att( $atts['form'] );

		wp_enqueue_style( 'flatpickr' );
		wp_enqueue_style( 'flatpickr-igm' );
		wp_enqueue_style( 'bi-filterleiste' );
		wp_enqueue_style( 'bi-frontend' );
		wp_enqueue_script( 'bi-filterleiste' ); // zieht flatpickr + de.js als Abhängigkeit mit

		// Konfiguration robust inline ausgeben (statt wp_localize_script): funktioniert auch
		// mit Page-Buildern und JS-Optimierung/Caching, die localize-Daten sonst verschlucken.
		$config_json = wp_json_encode( self::js_config( $programm ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		// Gesamtzahl buchbar (ohne Filter, aber im selben Programmjahr)
		$total = self::total_count( $programm );

		// Gefilterte Ergebnisliste ($title_query wurde oben bereits gesetzt)
		$paged = max( 1, get_query_var( 'paged' ), intval( bi_get( 'paged', '1' ) ) );
		$args  = self::build_args( array(
			'posts_per_page'  => intval( $atts['per_page'] ),
			'paged'           => $paged,
			'bi_title_search' => self::$title_query ? 1 : 0,
		), $programm );
		$q = new WP_Query( $args );
		$count = (int) $q->found_posts;

		ob_start();
		?>
		<div id="bi-filter"
			data-bi-config="<?php echo esc_attr( $config_json ); ?>"
			data-bi-total="<?php echo esc_attr( $total ); ?>"
			data-bi-count="<?php echo esc_attr( $count ); ?>"></div>

		<div class="bi-results">
			<?php if ( $q->have_posts() ) : ?>
				<div class="bi-list">
					<?php while ( $q->have_posts() ) : $q->the_post(); ?>
						<?php echo self::render_row( get_the_ID() ); ?>
					<?php endwhile; ?>
				</div>
				<?php
				$big = 999999999;
				echo '<div class="bi-pagination">' . paginate_links( array(
					'base'    => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
					'format'  => '?paged=%#%',
					'current' => $paged,
					'total'   => $q->max_num_pages,
					'prev_text' => '‹',
					'next_text' => '›',
				) ) . '</div>';
				?>
			<?php else : ?>
				<p class="bi-noresults">Keine Seminare gefunden. Bitte passen Sie die Filter an.</p>
			<?php endif; ?>
		</div>
		<?php
		wp_reset_postdata();
		self::$title_query = '';
		self::$force_form  = '';
		return ob_get_clean();
	}

	/** ---------- Eigenständige Suchmaske [bi_suchmaske] ---------- */

	/** Fortlaufende Nummer, damit mehrere Masken auf einer Seite eigene IDs bekommen. */
	private static $maske_id = 0;

	/**
	 * Nur die Such-/Filterleiste, ohne Ergebnisliste. Die Auswahl wird im Browser
	 * gesammelt (kein Reload je Klick); "Suche starten" übergibt sie als GET-Parameter
	 * an die Seite mit [bi_seminarsuche].
	 */
	public static function shortcode_maske( $atts ) {
		$atts = shortcode_atts( array(
			'ziel_url' => '',   // Zielseite; leer = Einstellung "Seminarübersicht"
			'programm' => '',   // auf ein Programmjahr beschränken, z. B. programm="2026"
			'form'     => '',   // auf eine Seminarform beschränken: praesenz | online
			'kicker'   => 'Bildungsprogramm',
			'titel'    => 'Seminar finden',
			'button'   => 'Suche starten',
			'hinweis'  => 'Mehrfachauswahl möglich · Ergebnisse auf der Seminarübersicht',
		), $atts, 'bi_suchmaske' );

		$programm = sanitize_text_field( $atts['programm'] );
		$form     = self::sanitize_form_att( $atts['form'] );

		// Zielseite: Attribut > Einstellung/Autoerkennung > Startseite
		// esc_url_raw filtert unerlaubte Protokolle (javascript: o. ä.), relative Pfade bleiben erhalten.
		$ziel = trim( $atts['ziel_url'] );
		$ziel = ( '' !== $ziel ) ? esc_url_raw( $ziel ) : '';
		if ( '' === $ziel && class_exists( 'BI_Registration' ) ) {
			$ziel = BI_Registration::uebersicht_url();
		}
		if ( '' === $ziel ) {
			$ziel = home_url( '/' );
		}

		wp_enqueue_style( 'flatpickr' );
		wp_enqueue_style( 'flatpickr-igm' );
		wp_enqueue_style( 'bi-filterleiste' );
		wp_enqueue_script( 'bi-filterleiste' );

		// Konfiguration ohne aktive Filter aufbauen: die Maske startet immer leer,
		// auch wenn in der URL der Trägerseite zufällig Filterparameter stehen.
		$get_backup   = $_GET;
		$title_backup = self::$title_query;
		$_GET              = array();
		self::$title_query = '';
		self::$force_form  = $form;

		$config = self::js_config( $programm );
		$count  = count( self::count_base_ids( $programm ) );
		$total  = self::total_count( $programm );

		$_GET              = $get_backup;
		self::$title_query = $title_backup;
		self::$force_form  = '';

		$id = 'bi-suchmaske-' . ( ++self::$maske_id );

		$config['root']        = '#' . $id;
		$config['standalone']  = true;
		$config['targetUrl']   = $ziel;
		$config['buttonLabel'] = $atts['button'];
		$config['kicker']      = $atts['kicker'];
		$config['title']       = $atts['titel'];
		$config['hint']        = $atts['hinweis'];
		$config['programm']    = $programm;
		$config['form']        = $form;
		$config['ajaxUrl']     = admin_url( 'admin-ajax.php' );

		$config_json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			data-bi-config="<?php echo esc_attr( $config_json ); ?>"
			data-bi-total="<?php echo esc_attr( $total ); ?>"
			data-bi-count="<?php echo esc_attr( $count ); ?>"></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX: liefert zur übergebenen Auswahl die neu berechneten Facetten-Optionen
	 * (inkl. Zähler) sowie Treffer- und Gesamtzahl. Nur lesend, daher ohne Nonce.
	 */
	public static function ajax_refresh() {
		$programm = sanitize_text_field( bi_post( 'programm' ) );
		$form     = self::sanitize_form_att( bi_post( 'bi_force_form' ) );

		$get_backup   = $_GET;
		$title_backup = self::$title_query;

		// $_GET aus den geposteten Parametern nachbilden, damit build_args/js_config unverändert greifen.
		// Nur Skalare übernehmen und je Wert begrenzen: sonst erzeugt ein Arrayparameter
		// oder ein sehr langer Wert in den nachgelagerten explode()/IN()-Pfaden Last statt Ergebnis.
		$_GET = array();
		foreach ( self::query_params() as $key ) {
			$value = trim( bi_post( $key ) );
			if ( '' !== $value ) {
				$_GET[ $key ] = substr( $value, 0, 300 );
			}
		}
		self::$title_query = sanitize_text_field( bi_get( 'q' ) );
		self::$force_form  = $form;

		$config = self::js_config( $programm );
		$count  = count( self::count_base_ids( $programm ) );
		$total  = self::total_count( $programm );

		$_GET              = $get_backup;
		self::$title_query = $title_backup;
		self::$force_form  = '';

		wp_send_json_success( array(
			'categories' => $config['categories'],
			'count'      => $count,
			'total'      => $total,
		) );
	}

	/** Eine Listenzeile (Datumsblock links · Infos · Details-Button rechts) */
	private static function render_row( $post_id ) {
		$is_online = bi_is_online( $post_id );

		$start   = get_post_meta( $post_id, '_bi_startdatum', true );
		$end     = get_post_meta( $post_id, '_bi_enddatum', true );
		$uhr     = get_post_meta( $post_id, '_bi_startuhrzeit', true );
		$enduhr  = get_post_meta( $post_id, '_bi_enduhrzeit', true );
		$anru    = get_post_meta( $post_id, '_bi_anreiseuhrzeit', true );
		$nr      = get_post_meta( $post_id, '_bi_seminarnummer', true );
		$ort     = wp_get_object_terms( $post_id, BI_TAX_ORT, array( 'fields' => 'names' ) );
		$prog    = wp_get_object_terms( $post_id, BI_TAX_PROGRAMM, array( 'fields' => 'names' ) );

		$ausgebucht = BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' );
		$permalink  = get_permalink( $post_id );

		$ts        = $start ? strtotime( $start ) : false;
		$day       = $ts ? date_i18n( 'd', $ts ) : '';
		$monthyear = $ts ? date_i18n( 'F Y', $ts ) : '';
		$time      = $uhr ? $uhr : $anru; // Startzeit, sonst Anreisezeit

		// Ortszeile: bei Online-Seminaren „Online" (plus Veranstalter*in, falls gepflegt)
		$ortzeile = ( $ort && ! is_wp_error( $ort ) ) ? $ort[0] : '';
		if ( $is_online ) {
			$ortzeile = $ortzeile ? 'Online · ' . $ortzeile : 'Online';
		}

		// Dauer: mehrtägig in Tagen, eintägige Online-Termine als Uhrzeit-Spanne
		$dauer       = '';
		$dauer_label = 'Dauer';
		if ( $start && $end && $end !== $start ) {
			$days = (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1;
			if ( $days >= 1 ) {
				$dauer = $days . ( 1 === $days ? ' Tag' : ' Tage' );
			}
		} elseif ( $is_online && $uhr && $enduhr ) {
			$dauer       = $uhr . ' – ' . $enduhr . ' Uhr';
			$dauer_label = 'Zeit';
		} elseif ( $start && $end ) {
			$dauer = '1 Tag';
		}

		ob_start();
		?>
		<article class="bi-row<?php echo $ausgebucht ? ' bi-row-ausgebucht' : ''; ?>">
			<div class="bi-row-date">
				<?php if ( $monthyear ) : ?><span class="bi-row-monthyear"><?php echo esc_html( $monthyear ); ?></span><?php endif; ?>
				<span class="bi-row-day"><?php echo esc_html( $day ?: '–' ); ?></span>
				<?php if ( $time ) : ?><span class="bi-row-time"><?php echo esc_html( $time ); ?> Uhr</span><?php endif; ?>
			</div>

			<div class="bi-row-body">
				<h3 class="bi-row-title">
					<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
					<?php if ( $is_online ) : ?><span class="bi-row-badge bi-row-badge--online">Online</span><?php endif; ?>
					<?php if ( $ausgebucht ) : ?><span class="bi-row-badge">Ausgebucht</span><?php endif; ?>
				</h3>
				<?php if ( $ortzeile ) : ?>
					<div class="bi-row-ort"><?php echo esc_html( $ortzeile ); ?></div>
				<?php endif; ?>
				<div class="bi-row-sub">
					<?php if ( $prog && ! is_wp_error( $prog ) ) : ?><span><?php echo esc_html( $prog[0] ); ?></span><?php endif; ?>
					<?php if ( $dauer ) : ?><span><strong><?php echo esc_html( $dauer_label ); ?>:</strong> <?php echo esc_html( $dauer ); ?></span><?php endif; ?>
					<?php if ( $nr ) : ?><span><strong>Nr.:</strong> <?php echo esc_html( $nr ); ?></span><?php endif; ?>
				</div>
			</div>

			<div class="bi-row-action">
				<a class="bi-btn-details" href="<?php echo esc_url( $permalink ); ?>">Details</a>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}
}
