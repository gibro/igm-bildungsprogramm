<?php
/**
 * Such- & Filterleiste + Ergebnisliste – Shortcode [bi_seminarsuche].
 *
 * Liest die GET-Parameter q, form, programm, ort, thema, ziel, frei, von, bis, nr
 * (Mehrfachwerte pipe-getrennt: ?ziel=A|B bzw. ?nr=LO12345|BO67890) und baut
 * daraus eine WP_Query gegen beide Seminar-Beitragstypen. nr filtert auf die
 * Seminarnummer (_bi_seminarnummer). Facetten sind Taxonomien -> echtes ODER
 * über tax_query (operator IN). "Buchbar" = _bi_startdatum >= heute.
 *
 * q ist die VOLLTEXTSUCHE über alle Datenfelder: Titel, Beschreibung, jedes
 * Meta-Feld (auch die selbst angelegten) und die Begriffe aller filterbaren
 * Taxonomien. Verglichen wird Wort für Wort und mit UND verknüpft – ein Wort
 * darf im Titel stehen, das nächste im Seminarort. Sieht die Eingabe nach
 * einer Seminarnummer aus (ein Wort, ab vier Zeichen, mit Ziffer), wird sie
 * zusätzlich am Stück gegen die Seminarnummer gehalten. Der Unterschied zu nr:
 * q ist die getippte Suche und vergleicht auch Teilstücke, nr ist der exakte
 * Filter für verlinkte Listen.
 *
 * Sonderfall Facette „form" (Seminarform): sie hängt an keiner Taxonomie,
 * sondern schaltet zwischen den Beitragstypen
 *   praesenz -> bi_seminar
 *   online   -> bi_online
 * Der Chip erscheint nur, wenn im aktuellen Ergebnis-Universum tatsächlich
 * beide Formen vorkommen.
 *
 * WELCHE Chips die Leiste anbietet, steht unter Einstellungen → Such-Filterleiste
 * (Option bi_settings['filter_facets'], siehe aktive_facetten()). Gedacht ist das
 * für Filter, die nur zeitweise gebraucht werden – etwa „Programm" im Übergang
 * von einem Programmjahr zum nächsten. Abschalten nimmt nur den Chip weg; ein
 * Wert in der Adresszeile filtert weiterhin, damit bestehende Links und
 * Marketing-Kacheln nicht still ihre Bedeutung ändern.
 *
 * Zusätzlich kann die Redaktion dort EIGENE CHIPS anlegen: ein Name und die
 * Adresse einer Suche (bi_settings['filter_shortcuts'], siehe shortcuts()).
 * Sie stehen als „Schnellzugriff" über den Filtern und setzen beim Klick genau
 * die Filter aus dieser Adresse – ein zweiter Klick nimmt sie wieder zurück.
 * Die Seite aus der Adresse wird verworfen: Ein Chip filtert, er springt nicht.
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

	/**
	 * GET-Param => Taxonomie, multi-Flag, Beschriftung und Symbol.
	 * 'form' ist eine Pseudo-Facette ohne Taxonomie (schaltet den Beitragstyp).
	 *
	 * Diese Liste ist die einzige Quelle: Filterleiste, Suchmaske und die
	 * Auswahl in den Einstellungen lesen Beschriftung und Symbol von hier.
	 * Die Reihenfolge ist zugleich die Reihenfolge der Chips in der Leiste.
	 */
	public static function facets() {
		return array(
			'form'     => array( 'tax' => '',              'multi' => true, 'label' => 'Seminarform',     'icon' => 'screen' ),
			'programm' => array( 'tax' => BI_TAX_PROGRAMM, 'multi' => true, 'label' => 'Programm',        'icon' => 'book' ),
			'ort'      => array( 'tax' => BI_TAX_ORT,      'multi' => true, 'label' => 'Bildungszentrum', 'icon' => 'pin' ),
			'thema'    => array( 'tax' => BI_TAX_THEMA,    'multi' => true, 'label' => 'Themenfeld',      'icon' => 'tag' ),
			'ziel'     => array( 'tax' => BI_TAX_ZIEL,     'multi' => true, 'label' => 'Zielgruppe',      'icon' => 'users' ),
			'frei'     => array( 'tax' => BI_TAX_FREI,     'multi' => true, 'label' => 'Freistellung',    'icon' => 'file' ),
		);
	}

	/**
	 * Facetten, die die Leiste tatsächlich als Chip anbietet.
	 *
	 * Bewusst NUR eine Frage der Anzeige: Ein abgeschalteter Filter verschwindet
	 * aus der Leiste, ein Wert in der Adresszeile wirkt aber weiter. Sonst
	 * würden alle Marketing-Kacheln und verschickten Links, die auf diese
	 * Facette zeigen, in dem Moment stillschweigend die falsche (nämlich eine
	 * größere) Treffermenge zeigen, in dem jemand den Chip abschaltet.
	 *
	 * @return string[] Parameternamen in der Reihenfolge von facets().
	 */
	public static function aktive_facetten() {
		$alle = array_keys( self::facets() );

		if ( ! class_exists( 'BI_Settings' ) ) {
			return $alle;
		}
		$gewaehlt = BI_Settings::get( 'filter_facets' );
		if ( ! is_array( $gewaehlt ) ) {
			return $alle; // noch nie gespeichert: alles anbieten, was es bisher gab
		}

		$out = array();
		foreach ( $alle as $param ) {
			if ( in_array( $param, $gewaehlt, true ) ) {
				$out[] = $param;
			}
		}
		return $out;
	}

	/** Alle Parameternamen, die eine Suche transportiert (auch für Kacheln/AJAX). */
	public static function query_params() {
		return array_merge( array( 'q' ), array_keys( self::facets() ), array( 'von', 'bis', 'nr' ) );
	}

	/* ---------- Eigene Chips („Schnellzugriff") ---------- */

	/**
	 * Filterparameter aus einer eingegebenen Such-Adresse lesen.
	 *
	 * Angenommen wird beides: die ganze Adresse, wie sie nach dem Filtern in der
	 * Adresszeile steht (https://…/seminare/?thema=…&form=online), und die reine
	 * Parameterliste (thema=…&form=online). Die Redaktion soll nicht darüber
	 * nachdenken müssen, welchen Teil sie kopiert hat.
	 *
	 * ÜBERNOMMEN WIRD NUR DER FILTERTEIL, NIE DIE SEITE. Ein Chip setzt Filter,
	 * er springt nicht weg – sonst hinge an ihm zusätzlich die Frage, auf welcher
	 * Seite man landet, und derselbe Chip verhielte sich in der Suchmaske anders
	 * als in der Trefferliste. Unbekannte Parameter (utm_…, paged, alles andere)
	 * fallen weg: Was hier durchkommt, ist genau das, was die Suche auch
	 * auswertet.
	 *
	 * @param string $url Adresse oder Parameterliste.
	 * @return array Parametername => Wert (unkodiert), in der Reihenfolge von query_params().
	 */
	public static function url_params( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return array();
		}

		// Anker abschneiden, dann alles ab dem ersten „?" nehmen. Steht kein „?"
		// darin, gilt die Eingabe selbst als Parameterliste – ein reiner Pfad
		// ohne Parameter ergibt dann schlicht nichts.
		$url   = (string) strtok( $url, '#' );
		$frage = strpos( $url, '?' );
		$query = ( false === $frage ) ? $url : substr( $url, $frage + 1 );

		$roh = array();
		wp_parse_str( (string) $query, $roh );

		$out = array();
		foreach ( self::query_params() as $key ) {
			if ( ! isset( $roh[ $key ] ) || is_array( $roh[ $key ] ) ) {
				continue;
			}
			$wert = sanitize_text_field( (string) $roh[ $key ] );
			if ( '' !== $wert ) {
				$out[ $key ] = $wert;
			}
		}
		return $out;
	}

	/**
	 * Die eigenen Chips aus den Einstellungen, fertig für die Leiste.
	 *
	 * Zeilen ohne Namen oder ohne erkennbaren Filter fallen still weg – sie
	 * hätten im Frontend nichts zu tun. Gemeldet wird das schon beim Speichern
	 * (BI_Settings::save), hier ist es nur die zweite Absicherung für Bestände,
	 * die vor dieser Prüfung angelegt wurden.
	 *
	 * @return array Liste aus array( 'label' => string, 'params' => array )
	 */
	public static function shortcuts() {
		if ( ! class_exists( 'BI_Settings' ) ) {
			return array();
		}

		$out = array();
		foreach ( BI_Settings::filter_shortcuts() as $chip ) {
			$label  = trim( (string) ( $chip['label'] ?? '' ) );
			$params = self::url_params( $chip['url'] ?? '' );
			if ( '' === $label || ! $params ) {
				continue;
			}
			// Wie bei den Facetten: Über JSON geht Text, kein HTML (siehe klartext).
			$out[] = array(
				'label'  => self::klartext( $label ),
				'params' => $params,
			);
		}
		return $out;
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
		// Innerhalb EINES Aufrufs kommt dieselbe Frage mehrfach: einmal für die
		// Gesamtzahl, einmal je Facette mit eigener Auswahl, und in der Suchmaske
		// noch einmal für die Kopfzahl. Das ist jedes Mal eine Abfrage über den
		// ganzen Bestand – gemerkt wird sie deshalb, solange sich am Zustand
		// nichts ändert. Der Schlüssel muss ALLES enthalten, was build_args liest:
		// $_GET wird an mehreren Stellen bewusst umgebogen (Suchmaske, AJAX,
		// Vorschläge), und ein Schlüssel ohne das lieferte dort die Zahlen der
		// vorigen Frage.
		$key = wp_json_encode( array(
			$programm, $skip_facet, self::$title_query, self::$such_modus, self::$force_form,
			array_intersect_key( (array) $_GET, array_flip( self::query_params() ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) );
		if ( isset( self::$ids_cache[ $key ] ) ) {
			return self::$ids_cache[ $key ];
		}

		$args = self::build_args(
			array( 'fields' => 'ids', 'nopaging' => true, 'orderby' => 'none' ),
			$programm,
			$skip_facet
		);
		if ( self::$title_query ) {
			$args['bi_title_search'] = 1; // Volltextsuche im posts_where-Filter aktivieren
		}
		$q = new WP_Query( $args );

		self::$ids_cache[ $key ] = $q->posts;
		return $q->posts;
	}

	/** Gemerkte Ergebnisse von count_base_ids – nur für die Dauer eines Aufrufs. */
	private static $ids_cache = array();

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
	 * Womit gerade gesucht wird: 'match' (FULLTEXT) oder 'like' (mitten im Wort).
	 *
	 * Steht hier und nicht als Parameter, weil posts_where die Bedingung baut –
	 * und der Filter bekommt nur die Abfrage zu sehen, nicht unseren Zustand.
	 */
	private static $such_modus = 'like';

	/**
	 * Suchtext setzen – und dazu den Weg, auf dem gesucht wird.
	 *
	 * IMMER BEIDES: Ein Suchtext ohne passenden Modus wäre eine Abfrage, die je
	 * nach vorheriger Verwendung mal etwas findet und mal nicht. Deshalb geht
	 * jede Zuweisung durch diese Stelle.
	 */
	private static function such_setzen( $text ) {
		self::$title_query = (string) $text;
		self::$such_modus  = BI_Suche::modus_fuer( self::$title_query );
	}

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
		self::such_setzen( isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '' );

		$count = count( self::count_base_ids( $programm ) );

		$_GET              = $get_backup;
		self::such_setzen( $title_backup );
		return $count;
	}

	/**
	 * Trefferzeilen zu einem Parametersatz – dieselben Zeilen wie unter der Suche.
	 *
	 * Damit die Listenansicht des Marketing-Bereichs (BI_Kacheln::liste) nicht
	 * eine zweite, ähnliche Zeile erfindet: Was hier herauskommt, ist Zeile für
	 * Zeile dasselbe HTML wie in der Ergebnisliste – gleiche Angaben, gleiche
	 * Reihenfolge, gleiche Gestaltung. Eine nachgebaute Liste liefe
	 * auseinander, sobald jemand an einer der beiden etwas ändert.
	 *
	 * Sortiert wird wie überall nach Startdatum aufsteigend, gezeigt wird nur,
	 * was buchbar ist. Der Tippfehler-Rückfall der Suche bleibt bewusst außen
	 * vor: Der Suchbegriff kommt hier aus der Redaktion und nicht aus einem
	 * Eingabefeld – was dort steht, ist gemeint.
	 *
	 * @param array  $params   q, form, ort, thema, ziel, frei, von, bis, nr – unkodiert.
	 * @param string $programm Programmjahr(e), pipe-getrennt (leer = alle).
	 * @param int    $anzahl   Höchstzahl der Zeilen.
	 * @return array [ 'html' => string, 'gezeigt' => int, 'gesamt' => int ]
	 */
	public static function zeilen_fuer_params( array $params, $programm = '', $anzahl = 5 ) {
		$get_backup   = $_GET;
		$title_backup = self::$title_query;
		$form_backup  = self::$force_form;

		$_GET = array();
		foreach ( $params as $key => $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$_GET[ $key ] = $value;
			}
		}
		// Eine von der Seite vorgegebene Seminarform darf hier nicht
		// hineinwirken: Die Liste ist ein eigenes Werbemittel und sagt selbst
		// über den Filter „form", was sie zeigt.
		self::$force_form = '';
		self::such_setzen( isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '' );

		$anzahl = max( 1, min( 50, (int) $anzahl ) );
		$q      = self::liste_holen( $programm, $anzahl, 1 );

		$html = '';
		foreach ( $q->posts as $post ) {
			$html .= self::render_row( $post->ID );
		}

		$out = array(
			'html'    => $html,
			'gezeigt' => count( $q->posts ),
			'gesamt'  => (int) $q->found_posts,
		);

		$_GET             = $get_backup;
		self::such_setzen( $title_backup );
		self::$force_form = $form_backup;
		return $out;
	}

	public static function init() {
		add_shortcode( 'bi_seminarsuche', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'bi_suchmaske', array( __CLASS__, 'shortcode_maske' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'posts_where', array( __CLASS__, 'title_where' ), 10, 2 );

		// Live-Zähler + Facetten für die eigenständige Suchmaske (lesend, daher auch für Gäste)
		add_action( 'wp_ajax_bi_filter_refresh', array( __CLASS__, 'ajax_refresh' ) );
		add_action( 'wp_ajax_nopriv_bi_filter_refresh', array( __CLASS__, 'ajax_refresh' ) );

		// Autovervollständigung des Suchfeldes (ebenfalls rein lesend)
		add_action( 'wp_ajax_bi_suche_vorschlag', array( __CLASS__, 'ajax_vorschlag' ) );
		add_action( 'wp_ajax_nopriv_bi_suche_vorschlag', array( __CLASS__, 'ajax_vorschlag' ) );

		// Ändert sich der Bestand, taugt die zwischengespeicherte Facettenliste
		// der Vorschläge nicht mehr.
		foreach ( array_merge( bi_seminar_post_types(), array( BI_Reihen::CPT ) ) as $pt ) {
			add_action( 'save_post_' . $pt, array( __CLASS__, 'vorschlaege_verwerfen' ) );
		}
		add_action( 'deleted_post', array( __CLASS__, 'vorschlaege_verwerfen' ) );
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

	/**
	 * Anzeigetext für die JS-Konfiguration: HTML-Entities auflösen.
	 *
	 * WARUM DAS NÖTIG IST: get_the_title() schickt den Titel durch wptexturize().
	 * Aus «"Ent-rüstet euch!"» wird dabei «&#8222;Ent-rüstet euch!&#8220;» und
	 * aus einem Halbgeviertstrich «&#8211;» – das ist für HTML richtig und für
	 * JSON falsch. Das JavaScript maskiert beim Einsetzen selbst (escapeHtml),
	 * und dabei wird aus «&#8211;» ein «&amp;#8211;», das der Browser als
	 * Zeichenfolge anzeigt statt als Strich. Auch Begriffe aus der Term-Tabelle
	 * stehen dort maskiert («Arbeit &amp; Recht»).
	 *
	 * Die Regel dahinter: Über JSON geht TEXT, kein HTML. Maskiert wird erst da,
	 * wo eingesetzt wird.
	 *
	 * NUR ANZEIGETEXTE, NIE WERTE: Ein Filterwert wandert in die Adresszeile und
	 * wird serverseitig gegen den Begriffsnamen gehalten – so, wie er in der
	 * Datenbank steht. Ihn aufzulösen bräche den Filter für jeden Begriff mit
	 * einem Sonderzeichen.
	 */
	private static function klartext( $text ) {
		return html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Kategorien (inkl. Optionen aus den vorhandenen Terms) für die JS-Konfiguration */
	private static function js_config( $programm = '' ) {
		$facets = self::facets();
		$aktiv  = self::aktive_facetten();

		// Facettierte Zähler: Basis sind alle bereits gewählten Filter (kommend + sichtbar,
		// Datumsbereich, Suche, ggf. Programmjahr). Pro Facette mit eigener Auswahl werden die
		// Zähler OHNE diese Auswahl berechnet, damit man innerhalb der Facette noch wechseln kann.
		$base_ids    = self::count_base_ids( $programm );
		$full_terms  = self::term_counts_for_ids( $base_ids );
		$full_types  = self::post_type_counts_for_ids( $base_ids );

		$cats = array();
		foreach ( $facets as $param => $f ) {
			if ( ! in_array( $param, $aktiv, true ) ) {
				continue; // in den Einstellungen abgeschaltet
			}
			$own = ( '' !== bi_get( $param ) );

			// Legt der Shortcode das Programmjahr fest, wäre ein Programm-Chip
			// daneben irreführend: Er könnte die Vorgabe nicht aufheben, sondern
			// nur weiter einengen. Genau wie bei form="…" also keinen anbieten.
			if ( 'programm' === $param && '' !== $programm ) {
				continue;
			}

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

				// Beim Programm zusätzlich dieselbe Regel wie bei der Seminarform:
				// Bleibt nur ein Programmjahr buchbar, ist das keine Wahl mehr,
				// sondern eine Feststellung – der Chip verschwindet dann von
				// selbst, ohne dass jemand die Einstellung nachziehen muss. Bei
				// den übrigen Facetten bleibt ein einzelner Eintrag stehen: dort
				// ist er eine Aussage über den Bestand, die man sehen will.
				if ( 'programm' === $param && count( $opts ) < 2 && ! $own ) {
					continue;
				}
			}

			// Zähler als eigenes Feld (im JS als dezente graue Zahl hinter dem Label gerendert).
			// Die Beschriftung wird zu Text – der Wert bleibt, wie er ist (siehe klartext).
			foreach ( $opts as &$o ) {
				if ( isset( $o['count'] ) ) {
					$o['count'] = number_format_i18n( $o['count'] );
				}
				if ( isset( $o['label'] ) ) {
					$o['label'] = self::klartext( $o['label'] );
				}
			}
			unset( $o );

			$cats[] = array(
				'param'   => $param,
				'label'   => $f['label'],
				'icon'    => $f['icon'],
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
			// Eigene Chips aus den Einstellungen. Sie hängen an keiner Facette
			// und werden deshalb auch nicht abgeschaltet, wenn ein Filter aus
			// der Leiste genommen wird: Der Chip trägt seine Werte selbst.
			'shortcuts'   => self::shortcuts(),
			// Auch die Ergebnisseite braucht die Adresse: Die
			// Autovervollständigung fragt dort genauso nach wie in der Maske.
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			// Legt der Shortcode Programmjahr oder Seminarform fest, gilt das
			// auch für die Vorschläge – sonst schlüge eine Seite, die nur
			// Online-Seminare zeigt, Präsenztermine vor. Die Suchmaske
			// überschreibt beide Werte anschließend mit ihren eigenen.
			'programm'    => (string) $programm,
			'form'        => (string) self::$force_form,
		);
	}

	/** ---------- Query-Aufbau ---------- */

	private static function build_args( $extra = array(), $programm = '', $skip_facet = '' ) {
		$today = current_time( 'Y-m-d' );

		$meta_query = array(
			'relation' => 'AND',
			// Benannte Klausel -> nach ihr wird sortiert (eindeutig trotz weiterer Datums-Filter)
			'startdatum' => array( 'key' => '_bi_startdatum', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
			// „Anzeigen? = nein" steckt NICHT hier, sondern als Unterabfrage in
			// BI_CPT::sichtbar_arg() – als meta_query kostete die Bedingung auf
			// dieser Liste dreistellige Millisekunden je Abfrage.
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
		// Auf ein oder mehrere Programmjahre beschränken (Shortcode-Attribut
		// programm="2026" bzw. programm="2027|2028").
		$programm_terms = self::programm_terms( $programm );
		if ( $programm_terms ) {
			$tax_query[] = array(
				'taxonomy' => BI_TAX_PROGRAMM,
				'field'    => 'name',
				'terms'    => $programm_terms,
				'operator' => 'IN',
			);
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND'; // UND zwischen verschiedenen Facetten
		}

		$args = array_merge( array(
			'post_type'   => self::selected_post_types( $skip_facet ),
			'post_status' => 'publish',
			// Immer aufsteigend nach Startdatum (ab heute) sortieren
			'orderby'     => array( 'startdatum' => 'ASC' ),
			'meta_query'  => $meta_query,
		), BI_CPT::sichtbar_arg() );
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
		// Hängt nur am Programmjahr und an der fest vorgegebenen Seminarform –
		// nicht an Filtern und nicht an der Suche. In der Suchmaske und im
		// AJAX-Zähler wird sie trotzdem mehrfach erfragt.
		$key = $programm . '|' . self::$force_form;
		if ( isset( self::$total_cache[ $key ] ) ) {
			return self::$total_cache[ $key ];
		}
		$map   = self::form_map();
		$types = ( '' !== self::$force_form && isset( $map[ self::$force_form ] ) )
			? array( $map[ self::$force_form ] )
			: bi_seminar_post_types();

		// Gezählt, nicht geholt: Bis 1.95.1 lief das mit nopaging und lieferte
		// tausend IDs, die niemand las – und sortierte sie auch noch nach
		// post_date. Gebraucht wird die Zahl, also holt die Abfrage eine Zeile
		// und liest found_posts.
		$args = array_merge( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'orderby'        => 'none',
			'meta_query'     => array(
				array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
			),
		), BI_CPT::sichtbar_arg() );
		$programm_terms = self::programm_terms( $programm );
		if ( $programm_terms ) {
			$args['tax_query'] = array(
				array( 'taxonomy' => BI_TAX_PROGRAMM, 'field' => 'name', 'terms' => $programm_terms, 'operator' => 'IN' ),
			);
		}
		$q = new WP_Query( $args );

		self::$total_cache[ $key ] = (int) $q->found_posts;
		return self::$total_cache[ $key ];
	}

	/** Gemerkte Gesamtzahlen – nur für die Dauer eines Aufrufs. */
	private static $total_cache = array();

	/**
	 * Volltextsuche (q) per posts_where – EIN EXISTS auf die Indextabelle.
	 *
	 * WORT FÜR WORT, MIT UND VERKNÜPFT: „arbeitsrecht grundlagen" findet
	 * „Grundlagen des Arbeitsrechts", die Reihenfolge der Wörter spielt keine
	 * Rolle.
	 *
	 * ÜBER ALLE FELDER: Titel, Beschreibung, jedes Meta-Feld (auch die selbst
	 * angelegten) und die Begriffe aller filterbaren Taxonomien. Sie stehen
	 * zusammengefasst in `wp_bi_suchindex`, einer Zeile je Seminar – gebaut und
	 * gepflegt von BI_Suche.
	 *
	 * WARUM ÜBERHAUPT EINE EIGENE TABELLE (seit 1.109.0): Bis 1.108.0 stand hier
	 * je Suchwort eine Unterabfrage über dreißig postmeta-Schlüssel und eine
	 * über die drei Term-Tabellen. Sechs Wörter waren zwölf Unterabfragen, keine
	 * davon indexfähig – `LIKE '%…%'` ist es nie. Auf dem Bestand kostete das
	 * Sekunden, und zwar mehrfach je Seitenaufruf. Jetzt ist es EIN EXISTS,
	 * unabhängig von der Wortzahl.
	 *
	 * ZWEI WEGE (self::$such_modus): erst MATCH über den FULLTEXT-Index, und nur
	 * wenn das nichts findet, dasselbe noch einmal mit LIKE – das findet auch
	 * mitten im Wort („rat" -> „Betriebsrat"), was im Deutschen kein Sonderfall
	 * ist. Wer entscheidet, steht in shortcode(); warum, in BI_Suche.
	 *
	 * DIE SEMINARNUMMER STEHT ZUSÄTZLICH DANEBEN: Sieht die Eingabe nach einer
	 * Nummer aus, wird die GANZE Eingabe am Stück gegen die Seminarnummer
	 * gehalten (siehe nummer_klausel). Die Wort-für-Wort-Bedingung reichte
	 * nicht: „LO-1234" zerfällt in „LO" und „1234", und die dürften dann an zwei
	 * verschiedenen Stellen im Text stehen.
	 *
	 * DIE KLAMMERN SIND NICHT KOSMETIK: ohne sie hinge das ODER am Ende der
	 * GESAMTEN WHERE-Bedingung und hebelte Datumsgrenze, Sichtbarkeit und alle
	 * Facetten aus.
	 */
	public static function title_where( $where, $query ) {
		if ( ! self::$title_query || ! $query->get( 'bi_title_search' ) ) {
			return $where;
		}
		$woerter = BI_Suche::zerlegen( self::$title_query );
		if ( ! $woerter ) {
			return $where;
		}
		$bedingung = BI_Suche::such_klausel( $woerter, self::$such_modus );
		if ( '' === $bedingung ) {
			return $where;
		}

		$nummer = self::nummer_klausel();
		if ( '' !== $nummer ) {
			$bedingung = '(' . $bedingung . ' OR ' . $nummer . ')';
		}
		return $where . ' AND ' . $bedingung . ' ';
	}

	/**
	 * Bedingung „diese Seminarnummer" – oder '' , wenn die Eingabe keine ist.
	 *
	 * WOZU NOCH, wo doch der ganze Index durchsucht wird? Weil hier die GANZE
	 * Eingabe am Stück verglichen wird, und zwar nur gegen die Nummer. Die
	 * Wort-für-Wort-Bedingung zerlegt „LO-1234" in „LO" und „1234" und ließe die
	 * beiden Teile irgendwo im Text stehen – gefunden würde dann auch ein
	 * Seminar in Lohr, das 1234 € kostet.
	 *
	 * NUR WENN DIE EINGABE NACH EINER NUMMER AUSSIEHT: Sonst zöge jede Suche
	 * nach „2026" alle Nummern mit einer 26 darin herein.
	 *
	 * Ein Teilstück reicht („B0002" findet „B00027030"), damit die Suche auch
	 * beim Abtippen aus dem Programmheft trägt.
	 */
	private static function nummer_klausel() {
		$roh = trim( (string) self::$title_query );
		if ( ! BI_Suche::ist_nummer( $roh ) ) {
			return '';
		}
		global $wpdb;
		return $wpdb->prepare(
			"EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS bi_nr WHERE bi_nr.post_id = {$wpdb->posts}.ID"
			. " AND bi_nr.meta_key = '_bi_seminarnummer' AND bi_nr.meta_value LIKE %s )",
			'%' . $wpdb->esc_like( $roh ) . '%'
		);
	}

	/**
	 * Freitext übernehmen – ohne Probeabfrage.
	 *
	 * BIS 1.108.0 lief hier eine KOMPLETTE zusätzliche Suche, nur um zu wissen,
	 * ob es überhaupt Treffer gibt. Bei einer Suche waren das drei bis vier
	 * teure Abfragen je Seitenaufruf für eine Liste. Jetzt wird schlicht die
	 * Liste geholt; ob sie leer ist, sagt found_posts von selbst
	 * (siehe liste_holen / shortcode).
	 */
	private static function freitext_setzen() {
		$roh = sanitize_text_field( bi_get( 'q' ) );
		self::such_setzen( $roh );
		return $roh;
	}

	/**
	 * Die Ergebnisliste holen.
	 *
	 * @param string $programm  Programmjahr aus dem Shortcode-Attribut.
	 * @param int    $per_page  Treffer je Seite.
	 * @param int    $paged      Seitennummer.
	 */
	private static function liste_holen( $programm, $per_page, $paged ) {
		return new WP_Query( self::build_args( array(
			'posts_per_page'  => $per_page,
			'paged'           => $paged,
			'bi_title_search' => self::$title_query ? 1 : 0,
		), $programm ) );
	}

	/**
	 * Findet die Liste nichts, noch einmal anders fragen – höchstens zweimal.
	 *
	 * ERST DER ANDERE SUCHWEG: MATCH kennt nur Wortanfänge. „rat" findet damit
	 * kein „Betriebsrat", und im Deutschen ist das kein Sonderfall. Also läuft
	 * dieselbe Suche noch einmal mit LIKE über dieselbe Tabelle – langsamer,
	 * aber nur in dem Fall, in dem der schnelle Weg nichts gebracht hat.
	 *
	 * DANN ERST DER TIPPFEHLER. Eine Seminarnummer bleibt davon ausgenommen:
	 * Findet sie nichts, ist das kein Buchstabendreher, sondern eine Nummer ohne
	 * Seminar (vergangen, unsichtbar, gar nicht im Bestand). Sie zu einem
	 * ähnlich geschriebenen Titelwort zu verbiegen, beantwortete eine Frage, die
	 * niemand gestellt hat.
	 *
	 * @return array [ WP_Query, ['original'=>…, 'korrigiert'=>…] ]
	 */
	private static function liste_mit_rueckfall( $roh, $programm, $per_page, $paged ) {
		$q     = self::liste_holen( $programm, $per_page, $paged );
		$stand = array( 'original' => $roh, 'korrigiert' => '' );
		if ( '' === $roh || (int) $q->found_posts > 0 ) {
			return array( $q, $stand );
		}

		if ( 'match' === self::$such_modus ) {
			self::$such_modus = 'like';
			$q = self::liste_holen( $programm, $per_page, $paged );
			if ( (int) $q->found_posts > 0 ) {
				return array( $q, $stand );
			}
		}

		if ( BI_Suche::ist_nummer( $roh ) ) {
			return array( $q, $stand );
		}
		$korrigiert = BI_Suche::korrigieren( $roh );
		if ( '' === $korrigiert ) {
			return array( $q, $stand );
		}

		$modus_vorher = self::$such_modus;
		self::such_setzen( $korrigiert );
		$k = self::liste_holen( $programm, $per_page, $paged );
		if ( (int) $k->found_posts > 0 ) {
			$stand['korrigiert'] = $korrigiert;
			return array( $k, $stand );
		}

		// Bringt die Korrektur auch nichts, bleibt es bei der Eingabe: Die
		// Meldung „keine Treffer" soll das nennen, was jemand eingetippt hat.
		self::such_setzen( $roh );
		self::$such_modus  = $modus_vorher;
		return array( $q, $stand );
	}

	/** Hinweis über der Liste, wenn statt der Eingabe etwas anderes gesucht wurde. */
	private static function korrektur_hinweis( $stand ) {
		if ( empty( $stand['korrigiert'] ) ) {
			return '';
		}
		return '<p class="bi-korrektur">Keine Treffer für <strong>' . esc_html( $stand['original'] )
			. '</strong>. Gezeigt werden Treffer für <strong>' . esc_html( $stand['korrigiert'] )
			. '</strong>.</p>';
	}

	/** Shortcode-Attribut form="online|praesenz" prüfen */
	private static function sanitize_form_att( $value ) {
		$value = sanitize_key( $value );
		return isset( self::form_map()[ $value ] ) ? $value : '';
	}

	/**
	 * Shortcode-Attribut programm="2027" oder programm="2027|2028" in Begriffe zerlegen.
	 *
	 * Mehrere Jahrgänge sind ODER-verknüpft: Im Übergang soll eine Seite beide
	 * Programme zeigen können. Getrennt wird mit der Pipe – dasselbe Zeichen wie
	 * bei allen anderen Mehrfachwerten der Suche.
	 *
	 * @return string[] Leeres Array = keine Einschränkung.
	 */
	private static function programm_terms( $programm ) {
		$teile = array_filter( array_map( 'trim', explode( '|', (string) $programm ) ), 'strlen' );
		return array_values( array_map( 'sanitize_text_field', $teile ) );
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
		self::$force_form = self::sanitize_form_att( $atts['form'] );
		$roh              = self::freitext_setzen();

		wp_enqueue_style( 'flatpickr' );
		wp_enqueue_style( 'flatpickr-igm' );
		wp_enqueue_style( 'bi-filterleiste' );
		wp_enqueue_style( 'bi-frontend' );
		wp_enqueue_script( 'bi-filterleiste' ); // zieht flatpickr + de.js als Abhängigkeit mit

		$paged = max( 1, get_query_var( 'paged' ), intval( bi_get( 'paged', '1' ) ) );

		// Trefferzahl je Seite: normalerweise das Shortcode-Attribut. Im
		// Einbettungsmodus darf zusätzlich die Adresse entscheiden – dort ist
		// die Adresse oft das Einzige, was sich einstellen lässt, weil das
		// fremde Redaktionssystem nur ein Feld dafür anbietet. Eine kürzere
		// Liste macht die Höhe des Rahmens vorhersehbar.
		// Öffentlich einstellbar heißt: begrenzt. Ohne Deckel wäre
		// ?bi_pro_seite=5000 eine Einladung, den Server rechnen zu lassen.
		$per_page = intval( $atts['per_page'] );
		if ( class_exists( 'BI_Embed' ) && BI_Embed::aktiv() ) {
			$wunsch = intval( bi_get( 'bi_pro_seite', '0' ) );
			if ( $wunsch > 0 ) {
				$per_page = min( 50, max( 3, $wunsch ) );
			}
		}

		// ERST DIE LISTE, DANN DIE FACETTEN.
		//
		// Bis 1.108.0 lief es andersherum: eine Probeabfrage („gibt es Treffer?"),
		// dann die Facettenzähler, dann die Liste – bei einer Suche also drei bis
		// vier Mal dieselbe teure Bedingung. Jetzt sagt die Liste selbst, ob sie
		// leer ist, und die Zähler rechnen hinterher mit dem Text und dem Suchweg,
		// mit dem am Ende tatsächlich gesucht wurde.
		list( $q, $suchstand ) = self::liste_mit_rueckfall( $roh, $programm, $per_page, $paged );
		$count = (int) $q->found_posts;

		// Konfiguration robust inline ausgeben (statt wp_localize_script): funktioniert auch
		// mit Page-Buildern und JS-Optimierung/Caching, die localize-Daten sonst verschlucken.
		$config_json = wp_json_encode( self::js_config( $programm ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

		// Gesamtzahl buchbar (ohne Filter, aber im selben Programmjahr)
		$total = self::total_count( $programm );

		ob_start();
		?>
		<div id="bi-filter"
			data-bi-config="<?php echo esc_attr( $config_json ); ?>"
			data-bi-total="<?php echo esc_attr( $total ); ?>"
			data-bi-count="<?php echo esc_attr( $count ); ?>"></div>

		<div class="bi-results">
			<?php echo self::korrektur_hinweis( $suchstand ); ?>
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
		self::such_setzen( '' );
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
		// Der Sprungknopf „Suche starten“ baut sein Ziel im Browser neu zusammen
		// und übernimmt dabei nur bekannte Filterparameter. Was sonst noch an der
		// Adresse hängen muss – etwa der Einbettungsmodus (BI_Embed) –, gehört
		// deshalb schon hier hinein und nicht erst in die Trefferliste.
		$ziel = (string) apply_filters( 'bi_suchmaske_ziel_url', $ziel );

		wp_enqueue_style( 'flatpickr' );
		wp_enqueue_style( 'flatpickr-igm' );
		wp_enqueue_style( 'bi-filterleiste' );
		wp_enqueue_script( 'bi-filterleiste' );

		// Konfiguration ohne aktive Filter aufbauen: die Maske startet immer leer,
		// auch wenn in der URL der Trägerseite zufällig Filterparameter stehen.
		$get_backup   = $_GET;
		$title_backup = self::$title_query;
		$_GET              = array();
		self::such_setzen( '' );
		self::$force_form  = $form;

		$config = self::js_config( $programm );
		$count  = count( self::count_base_ids( $programm ) );
		$total  = self::total_count( $programm );

		$_GET              = $get_backup;
		self::such_setzen( $title_backup );
		self::$force_form  = '';

		$id = 'bi-suchmaske-' . ( ++self::$maske_id );

		$config['root']        = '#' . $id;
		$config['standalone']  = true;
		$config['targetUrl']   = $ziel;
		// Auch hier Text statt HTML: Ein Page-Builder gibt Anführungszeichen und
		// Gedankenstriche gern schon maskiert weiter.
		$config['buttonLabel'] = self::klartext( $atts['button'] );
		$config['kicker']      = self::klartext( $atts['kicker'] );
		$config['title']       = self::klartext( $atts['titel'] );
		$config['hint']        = self::klartext( $atts['hinweis'] );
		$config['programm']    = $programm;
		$config['form']        = $form;
		$config['ajaxUrl']     = admin_url( 'admin-ajax.php' );
		// Im iframe kann der Sprung bewusst das ganze Browserfenster übernehmen
		// statt im Rahmen zu bleiben – siehe BI_Embed::ziel_oben().
		$config['targetTop']   = ( class_exists( 'BI_Embed' ) && BI_Embed::ziel_oben() ) ? 1 : 0;

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
		// Achtung: das Shortcode-Attribut programm="…" und die gleichnamige Facette
		// sind zwei verschiedene Dinge. Das Attribut kommt deshalb – wie form="…" –
		// unter eigenem Namen an, sonst überschriebe die Auswahl aus der Leiste die
		// Vorgabe der Seite (oder umgekehrt).
		$programm = sanitize_text_field( bi_post( 'bi_force_programm' ) );
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
		self::such_setzen( sanitize_text_field( bi_get( 'q' ) ) );
		self::$force_form  = $form;

		$config = self::js_config( $programm );
		$count  = count( self::count_base_ids( $programm ) );
		$total  = self::total_count( $programm );

		$_GET              = $get_backup;
		self::such_setzen( $title_backup );
		self::$force_form  = '';

		wp_send_json_success( array(
			'categories' => $config['categories'],
			'count'      => $count,
			'total'      => $total,
		) );
	}


	/* ===================================================================
	 *  Autovervollständigung
	 * ===================================================================
	 *
	 *  Drei Arten von Vorschlägen, weil es drei Arten von Absicht gibt:
	 *
	 *    Suchbegriff  „arbeit" → „Arbeitsrecht". Das Wort zu Ende schreiben,
	 *                 ohne raten zu müssen, wie es im Bestand heißt.
	 *    Filter       „sprock" → Chip „Bildungszentrum: Sprockhövel". Ein
	 *                 Filterwert ist genauer als derselbe Text im Volltext –
	 *                 und der Chip bleibt danach sichtbar und abwählbar.
	 *    Seminar      der Treffer selbst, mit Datum und Ort. Ein Klick führt
	 *                 auf die Detailseite statt in eine Liste mit einem Eintrag.
	 *
	 *  REIHENFOLGE: erst verfeinern, dann treffen – außer bei einer
	 *  Seminarnummer. Wer eine Nummer abtippt, will genau ein Seminar und
	 *  nichts sonst; dann stehen die Seminare oben.
	 *
	 *  GECACHT WIRD ZWEIMAL: die Facettenliste (ändert sich nur mit dem
	 *  Bestand, gilt für jede Eingabe) und die fertige Antwort je Eingabe.
	 *  Ohne das liefe bei jedem Tastendruck eine Zählabfrage über den ganzen
	 *  Bestand.
	 */

	/**
	 * Ab wie vielen Zeichen überhaupt vorgeschlagen wird.
	 *
	 * Bei zwei Zeichen passt fast alles, und der Kasten ist eher Rauschen als
	 * Hilfe – aber jede Anfrage kostet einen vollen WordPress-Start. Drei
	 * Zeichen sind die Grenze, ab der ein Vorschlag etwas aussagt.
	 */
	const VORSCHLAG_MIN = 3;

	/**
	 * Ab wie vielen Zeichen auch Seminare vorgeschlagen werden.
	 *
	 * Die beiden anderen Gruppen kommen aus zwischengespeicherten Listen und
	 * kosten praktisch nichts. Ein Seminarvorschlag ist dagegen eine echte
	 * Abfrage gegen den Bestand – bei drei Buchstaben mit einer Trefferliste,
	 * die noch nichts über die Absicht verrät.
	 */
	const VORSCHLAG_SEMINARE_AB = 4;

	/** Längere Eingaben werden abgeschnitten – ein Vorschlagsdienst ist kein Fass. */
	const VORSCHLAG_MAX = 60;

	/** Wie lange eine fertige Vorschlagsliste gilt. */
	const VORSCHLAG_TTL = 300;

	/** Der Bestand hat sich geändert – die zwischengespeicherten Facetten verwerfen. */
	public static function vorschlaege_verwerfen() {
		delete_transient( 'bi_vorschlag_facetten' );
	}

	public static function ajax_vorschlag() {
		$roh = trim( sanitize_text_field( bi_post( 'q' ) ) );
		// mb_substr statt substr: Ein Schnitt mitten in ein „ü" ergäbe kaputtes
		// UTF-8, und das käme als leerer Vorschlag zurück statt als Treffer.
		$roh = function_exists( 'mb_substr' )
			? mb_substr( $roh, 0, self::VORSCHLAG_MAX, 'UTF-8' )
			: substr( $roh, 0, self::VORSCHLAG_MAX );

		$laenge = function_exists( 'mb_strlen' ) ? mb_strlen( $roh, 'UTF-8' ) : strlen( $roh );
		if ( $laenge < self::VORSCHLAG_MIN ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$programm = sanitize_text_field( bi_post( 'bi_force_programm' ) );
		$form     = self::sanitize_form_att( bi_post( 'bi_force_form' ) );

		$key   = 'bi_vs_' . md5( BI_Suche::normalisieren( $roh ) . '|' . $roh . '|' . $programm . '|' . $form );
		$items = get_transient( $key );
		if ( ! is_array( $items ) ) {
			$items = self::vorschlaege( $roh, $programm, $form );
			set_transient( $key, $items, self::VORSCHLAG_TTL );
		}
		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * Die Vorschlagsliste zu einer Eingabe.
	 *
	 * @return array[] Einträge mit 'typ', 'label', 'zusatz' und – je nach Typ –
	 *                 'q' (neuer Suchtext), 'param'/'value' (Filter) oder 'url'.
	 */
	public static function vorschlaege( $roh, $programm = '', $form = '' ) {
		$ist_nummer = BI_Suche::ist_nummer( $roh );
		$laenge     = function_exists( 'mb_strlen' ) ? mb_strlen( $roh, 'UTF-8' ) : strlen( $roh );

		$seminare = ( $ist_nummer || $laenge >= self::VORSCHLAG_SEMINARE_AB )
			? self::vorschlag_seminare( $roh, $ist_nummer ? 6 : 4, $programm, $form )
			: array();
		if ( $ist_nummer ) {
			return $seminare;
		}
		return array_merge(
			self::vorschlag_woerter( $roh, 3 ),
			self::vorschlag_filter( $roh, 3 ),
			$seminare
		);
	}

	/**
	 * Das letzte Wort der Eingabe zu Ende schreiben.
	 *
	 * NUR DAS LETZTE WORT: Wer „grundlagen arbeit" tippt, ist beim zweiten Wort;
	 * das erste steht schon fest. Der Vorschlag ersetzt deshalb nur den
	 * angefangenen Rest und gibt die ganze neue Eingabe zurück.
	 */
	private static function vorschlag_woerter( $roh, $limit ) {
		$woerter = BI_Suche::zerlegen( $roh );
		if ( ! $woerter ) {
			return array();
		}
		$letztes = array_pop( $woerter );
		$vorne   = $woerter ? implode( ' ', $woerter ) . ' ' : '';

		$out = array();
		foreach ( BI_Suche::wort_vorschlaege( $letztes, $limit ) as $wort ) {
			$out[] = array(
				'typ'    => 'wort',
				'label'  => self::klartext( $wort ),
				'zusatz' => '',
				'q'      => self::klartext( $vorne . $wort ),
			);
		}
		return $out;
	}

	/**
	 * Passende Filterwerte – als Chip, nicht als Volltext.
	 *
	 * Verglichen wird gegen die FERTIGEN Optionen der Leiste, nicht gegen die
	 * Begriffe der Taxonomie: Bildungszentrum und Zielgruppe fasst die Leiste zu
	 * Gruppen zusammen („Berlin-Pichelssee" gehört zu „Bildungszentrum Berlin").
	 * Ein Vorschlag mit dem rohen Begriff führte auf einen Filterwert, den es in
	 * der Leiste gar nicht gibt – der Chip bliebe leer.
	 */
	private static function vorschlag_filter( $roh, $limit ) {
		$nadel = BI_Suche::normalisieren( $roh );
		if ( strlen( $nadel ) < self::VORSCHLAG_MIN ) {
			return array();
		}
		$facets = self::facets();
		$out    = array();

		foreach ( self::vorschlag_facetten() as $param => $optionen ) {
			if ( ! isset( $facets[ $param ] ) || 'form' === $param ) {
				continue; // die Seminarform tippt niemand
			}
			foreach ( $optionen as $opt ) {
				// Aufgelöst wird VOR dem Vergleich: „Arbeit &amp; Recht" zerfiele
				// sonst in die Wörter „Arbeit", „amp" und „Recht", und die Eingabe
				// „amp" schlüge einen Filter vor.
				$label = self::klartext( $opt['label'] ?? '' );
				if ( '' === $label ) {
					continue;
				}
				// Wortanfang, nicht irgendwo mittendrin: „rat" soll nicht
				// „Betriebsrat" und „Beirat" gleichermaßen vorschlagen.
				$treffer = false;
				foreach ( BI_Suche::zerlegen( $label ) as $teil ) {
					if ( 0 === strpos( BI_Suche::normalisieren( $teil ), $nadel ) ) {
						$treffer = true;
						break;
					}
				}
				if ( ! $treffer ) {
					continue;
				}
				$out[] = array(
					'typ'    => 'filter',
					'label'  => $label,
					'zusatz' => (string) $facets[ $param ]['label'],
					'param'  => $param,
					'value'  => (string) ( $opt['value'] ?? $label ),
				);
				if ( count( $out ) >= $limit ) {
					return $out;
				}
			}
		}
		return $out;
	}

	/**
	 * Die Optionen aller Facetten, wie die Leiste sie anbietet – gecacht.
	 *
	 * Sie hängen nicht an der Eingabe, sondern nur am Bestand. Ohne diesen
	 * Zwischenspeicher liefe die Zählabfrage über alle buchbaren Seminare bei
	 * jedem Tastendruck.
	 */
	private static function vorschlag_facetten() {
		$cache = get_transient( 'bi_vorschlag_facetten' );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		$get_backup   = $_GET;
		$title_backup = self::$title_query;
		$_GET              = array();
		self::such_setzen( '' );

		$choices = self::facet_choices();

		$_GET              = $get_backup;
		self::such_setzen( $title_backup );

		set_transient( 'bi_vorschlag_facetten', $choices, HOUR_IN_SECONDS );
		return $choices;
	}

	/**
	 * Seminare, die zur Eingabe passen – mit Datum und Ort als zweite Zeile.
	 *
	 * Gesucht wird mit derselben Volltext-Bedingung wie in der Liste (alle
	 * Datenfelder). Filter aus der Adresszeile bleiben dabei außen vor: Ein
	 * Vorschlag soll zeigen, was es gibt, und nicht davon abhängen, welche
	 * Chips gerade gesetzt sind.
	 */
	private static function vorschlag_seminare( $roh, $limit, $programm = '', $form = '' ) {
		$get_backup   = $_GET;
		$title_backup = self::$title_query;
		$form_backup  = self::$force_form;

		$_GET              = array();
		self::such_setzen( $roh );
		self::$force_form  = $form;

		$args = self::build_args( array(
			'posts_per_page'  => max( 1, (int) $limit ),
			'no_found_rows'   => true,
			'fields'          => 'ids',
			'bi_title_search' => 1,
		), $programm );
		$q = new WP_Query( $args );

		$_GET              = $get_backup;
		self::such_setzen( $title_backup );
		self::$force_form  = $form_backup;

		$out = array();
		foreach ( (array) $q->posts as $post_id ) {
			$post_id = (int) $post_id;
			$start   = get_post_meta( $post_id, '_bi_startdatum', true );
			$ts      = $start ? strtotime( $start ) : false;
			$ort     = BI_CPT::ort_anzeige( $post_id );
			if ( bi_is_online( $post_id ) ) {
				$ort = $ort ? 'Online · ' . $ort : 'Online';
			}
			$zusatz = array_filter( array( $ts ? date_i18n( 'j. F Y', $ts ) : '', $ort ), 'strlen' );

			$out[] = array(
				'typ'    => 'seminar',
				'label'  => self::klartext( get_the_title( $post_id ) ),
				'zusatz' => self::klartext( implode( ' · ', $zusatz ) ),
				'url'    => get_permalink( $post_id ),
			);
		}
		return $out;
	}

	/** Eine Listenzeile (Datumsblock links · Infos · Weiter-Link rechts) */
	private static function render_row( $post_id ) {
		$is_online = bi_is_online( $post_id );

		$start   = get_post_meta( $post_id, '_bi_startdatum', true );
		$end     = get_post_meta( $post_id, '_bi_enddatum', true );
		$uhr     = get_post_meta( $post_id, '_bi_startuhrzeit', true );
		$enduhr  = get_post_meta( $post_id, '_bi_enduhrzeit', true );
		$nr      = get_post_meta( $post_id, '_bi_seminarnummer', true );
		$ort     = BI_CPT::ort_anzeige( $post_id );
		$prog    = wp_get_object_terms( $post_id, BI_TAX_PROGRAMM, array( 'fields' => 'names' ) );

		// Nicht buchbar heißt nicht unsichtbar: Der Treffer bleibt in der Liste,
		// tritt aber zurück (gedämpft, mit Grund als Chip). Wer nicht buchen
		// kann, will trotzdem wissen, dass es das Seminar gibt.
		$grund      = BI_Detail::nicht_buchbar( $post_id );
		$ausgebucht = ( '' !== $grund );
		$permalink  = get_permalink( $post_id );

		$ts        = $start ? strtotime( $start ) : false;
		$day       = $ts ? date_i18n( 'd', $ts ) : '';
		$monthyear = $ts ? date_i18n( 'F Y', $ts ) : '';
		// OHNE UHRZEIT. Sie stand unter dem Datum und beantwortete in der Liste
		// keine Frage: Wer vergleicht, sucht Tag, Ort und Dauer – wann genau es
		// morgens losgeht, steht auf der Detailseite und in der Bestätigung.
		// Bei Online-Terminen bleibt die Zeitspanne als Dauer stehen (unten).

		// Ortszeile: bei Online-Seminaren „Online" (plus Veranstalter*in, falls gepflegt)
		$ortzeile = $ort;
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
			</div>

			<div class="bi-row-body">
				<h3 class="bi-row-title">
					<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
					<?php if ( $is_online ) : ?><span class="bi-row-badge bi-row-badge--online">Online</span><?php endif; ?>
					<?php if ( $ausgebucht ) : ?><span class="bi-row-badge"><?php echo esc_html( $grund ); ?></span><?php endif; ?>
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
				<?php
				// „und Anmeldung" nur, wo es die auch gibt: Bei einem ausgebuchten
				// Termin, einem ohne vorgesehene Anmeldung und bei einer offenen
				// Online-Veranstaltung führt die Detailseite zu keinem Formular –
				// die Liste soll nichts versprechen, was dort nicht steht.
				$aktion = BI_Detail::anmeldung_moeglich( $post_id ) ? 'Details und Anmeldung' : 'Details';
				?>
				<a class="bi-btn-details" href="<?php echo esc_url( $permalink ); ?>">
					<span class="bi-btn-details__text"><?php echo esc_html( $aktion ); ?></span>
					<span class="bi-btn-details__pfeil"><?php echo BI_Icons::get( 'pfeil-rechts', 15 ); ?></span>
				</a>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}
}
