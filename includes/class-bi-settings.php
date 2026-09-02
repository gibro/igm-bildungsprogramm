<?php
/**
 * Einstellungen – globale Konfiguration der Anmeldevarianten.
 *
 * Option bi_settings:
 *   anmeldung_page_id  int    Seite mit [bi_anmeldung] für die Direktanmeldung (0 = automatisch erkennen)
 *   direct_label       string Button-Text Direktanmeldung
 *   gs_label           string Button-Text Geschäftsstellen-Anmeldung
 *   gs_hinweis         string Hinweistext bei Geschäftsstellen-Anmeldung
 *   keine_label        string Text im Störer, wenn keine Anmeldung vorgesehen ist
 *   keine_hinweis      string Erläuterung unter dem Störer
 *   pdf_logo_id        int    Anhang-ID des Logos für die PDF-Anhänge (0 = keins)
 *   pdf_veranstalter   string Name und Anschrift des Veranstalters (mehrzeilig) –
 *                             steht in den Seminardetails und in der Beschlussvorlage
 *   retention_days     int    Aufbewahrung der Anmeldungen in Tagen NACH SEMINARENDE (0 = aus)
 *   retention_mode     string delete | anonymize
 *   ampel_aktiv        bool   Verfügbarkeits-Ampel anzeigen und abgleichen
 *   ampel_intervall    int    Stunden zwischen zwei planmäßigen Abgleichen
 *   ampel_max_alter    int    Stunden, ab denen Ampeldaten nicht mehr angezeigt werden
 *   ampel_kontakt      string Kontaktangabe im User-Agent der Abrufe
 *   filter_facets      array  Parameternamen der Filter, die die Such-Filterleiste
 *                             anbietet (Auswahl aus BI_Filter::facets())
 *   filter_shortcuts   array  Eigene Chips der Filterleiste: je Eintrag
 *                             array( 'label' => Name, 'url' => Adresse einer Suche )
 *
 * Drei Varianten:
 *   direct  Anmeldeformular auf der Website
 *   gs      über die Geschäftsstelle (PLZ-Suche, vorausgefüllte Mail-Anfrage)
 *   keine   gar keine Anmeldung – auf der Detailseite steht der Störer
 *
 * ALLE DREI WEGE KOMMEN AUS DEN REGELN. Die Haken am Seminar (Anmeldung
 * möglich, Ausgebucht, Anzeigen) sind Angaben zum Seminar, keine Anmeldewege;
 * sie stehen den Regeln als FELD zur Verfügung und können den Weg damit
 * beeinflussen – müssen aber nicht. Trifft keine Regel zu, gilt der Normalfall
 * 'direct'.
 *
 * Die Voreinstellung bringt eine Regel mit: Haken „Anmeldung möglich" = nein ->
 * keine. Damit wirkt der Haken wie erwartet, steht aber sichtbar und änderbar
 * in der Liste statt fest verdrahtet im Code.
 *
 * Hier werden die Ziele/Texte aller Varianten zentral hinterlegt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Settings {

	const OPTION = 'bi_settings';

	public static function init() {
		add_action( 'admin_post_bi_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_bi_cache_leeren', array( __CLASS__, 'handle_cache_leeren' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		// Vorschlagstext im WordPress-eigenen Datenschutz-Leitfaden
		// (Werkzeuge → Datenschutz → Richtlinien-Leitfaden).
		add_action( 'admin_init', array( __CLASS__, 'register_privacy_policy' ) );
	}

	/** Mediathek-Auswahl (wp.media) nur im Tab „PDF-Anhänge" laden */
	public static function admin_assets() {
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( wp_unslash( $_REQUEST['page'] ) ) : '';
		$tab  = isset( $_REQUEST['tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['tab'] ) ) : '';
		if ( 'bi-einstellungen' === $page && 'pdf' === $tab ) {
			wp_enqueue_media();
		}
	}

	public static function defaults() {
		return array(
			'anmeldung_page_id'  => 0,
			'uebersicht_page_id' => 0,
			'direct_label'      => 'Jetzt buchen',
			'gs_label'          => 'Zur Geschäftsstellensuche',
			'gs_hinweis'        => 'Anmeldung nur über deine Geschäftsstelle möglich.',
			// Variante 3: keine Anmeldung. Der Störer ist das runde Element des
			// Design-Systems – kurz und plakativ; der Hinweis darunter erklärt.
			'keine_label'       => 'Keine Anmeldung',
			'keine_hinweis'     => 'Für dieses Seminar ist keine Anmeldung über die Website möglich.',
			// Freistellungen, bei denen eine ganze Ausbildungsreihe in einem Zug
			// angemeldet werden darf. § 37,6 BetrVG und § 179,4 SGB IX laufen
			// gleich: Das Gremium beschließt, der Arbeitgeber trägt die Kosten –
			// dafür braucht es niemanden dazwischen. Bildungsurlaub, § 37,7 und
			// „keine Freistellung" hängen an der Zustimmung des Arbeitgebers und
			// laufen deshalb weiter über die Geschäftsstelle.
			'sammel_freistellung' => "§ 37,6 BetrVG\n§ 179,4 SGB IX",
			'pdf_logo_id'       => 0,
			'pdf_veranstalter'  => '',
			'retention_days'    => 90,
			'retention_mode'    => 'delete',
			'ampel_aktiv'       => 1,
			'ampel_intervall'   => 24,
			'ampel_max_alter'   => 48,
			'ampel_kontakt'     => '',
			// Eine Regel ist von Anfang an da: Sie macht aus dem Haken „Anmeldung
			// möglich" das, was alle darunter verstehen. Sichtbar und löschbar
			// wie jede andere – anders als eine fest verdrahtete Sonderregel, die
			// man in den Einstellungen vergeblich sucht.
			// Greift nur bei einer frischen Installation: Sobald einmal
			// gespeichert wurde, steht in der Option die eigene Liste.
			'rules'             => array(
				array( 'field' => 'flag', 'value' => 'nein', 'variant' => 'keine' ),
			),
			// Chips der Such-Filterleiste. Der Standard ist der Stand vor dieser
			// Einstellung – „Programm" bleibt also aus, bis es jemand einschaltet.
			'filter_facets'     => array( 'form', 'ort', 'thema', 'ziel', 'frei' ),
			// Eigene Chips („Schnellzugriff"): frei benannte, gespeicherte
			// Suchen, die als zusätzliche Knöpfe in der Leiste stehen. Leer,
			// weil nur die Redaktion weiß, welche Suchen sich lohnen.
			'filter_shortcuts'  => array(),
			// Fremde Websites, die Suche und Anmeldung in einem iframe zeigen
			// dürfen – eine Herkunft je Zeile. Leer = nur igmetall.de.
			'embed_hosts'       => '',
		);
	}

	/**
	 * Felder, auf die sich eine Regel beziehen kann.
	 *
	 * Dieselbe Liste bedient zwei Regelwerke: die Anmeldevarianten hier und die
	 * Zuordnung der Anmeldeformulare (BI_Formulare). Beide vergleichen einen
	 * Teiltext gegen ein Feld des Seminars – dass sie sich die Vergleichslogik
	 * teilen, hält die Bedienung gleich und die Überraschungen klein.
	 */
	public static function rule_fields() {
		return array(
			'freistellung'  => 'Freistellung',
			'handlungsfeld' => 'Themenfeld',
			'zielgruppe'    => 'Zielgruppe',
			'ort'           => 'Bildungszentrum / Veranstalter*in',
			'seminarform'   => 'Seminarform (Präsenz / Online)',
			'flag'          => 'Haken „Anmeldung möglich"',
			'ausgebucht'    => 'Haken „Ausgebucht"',
			'anzeigen'      => 'Haken „Auf der Website anzeigen"',
		);
	}

	/**
	 * Schreibweisen der Seminarform für den Teiltext-Vergleich.
	 *
	 * Zwei Schreibweisen für Präsenz, weil die Normalisierung in norm() zwar
	 * Punkte und Groß-/Kleinschreibung einebnet, aber kein „ä" in „ae"
	 * verwandelt – wer „Praesenz" tippt, meint dasselbe.
	 */
	private static function form_names( $post_type ) {
		return ( BI_ONLINE === $post_type )
			? array( 'Online', 'Online-Seminar' )
			: array( 'Präsenz', 'Praesenz', 'Präsenz-Seminar' );
	}

	/**
	 * Regelfeld -> Meta-Schlüssel der Ja/Nein-Haken am Seminar.
	 *
	 * Der Schlüssel 'flag' heißt aus der Zeit, in der es nur einen Haken gab.
	 * Umbenennen hieße, gespeicherte Regeln umzuschreiben – dafür ist der Name
	 * zu billig.
	 */
	private static function field_haken( $field ) {
		$map = array(
			'flag'       => '_bi_anmeldung_moeglich',
			'ausgebucht' => '_bi_ausgebucht',
			'anzeigen'   => '_bi_anzeigen',
		);
		return $map[ $field ] ?? '';
	}

	/** Mögliche Ergebnis-Varianten einer Regel */
	public static function rule_variants() {
		return array(
			'direct' => 'Direktanmeldung (Variante 1)',
			'gs'     => 'Geschäftsstelle (Variante 2)',
			'keine'  => 'Keine Anmeldung möglich (Variante 3)',
		);
	}

	/** Die gültigen Varianten – eine Erlaubnisliste, keine Verbotsliste. */
	public static function variant_keys() {
		return array_keys( self::rule_variants() );
	}

	private static function field_tax( $field ) {
		$map = array(
			'freistellung'  => BI_TAX_FREI,
			'handlungsfeld' => BI_TAX_THEMA,
			'zielgruppe'    => BI_TAX_ZIEL,
			'ort'           => BI_TAX_ORT,
		);
		return $map[ $field ] ?? '';
	}

	public static function rules() {
		$all = self::all();
		return ( isset( $all['rules'] ) && is_array( $all['rules'] ) ) ? $all['rules'] : array();
	}

	/**
	 * Vergleichsform für den „enthält"-Vergleich der Regeln.
	 *
	 * Kleingeschrieben, ohne die Wörter, mit denen ein Paragraf geschrieben
	 * wird, und ohne jedes Zeichen, das kein Buchstabe und keine Ziffer ist.
	 * Damit gelten als dasselbe:
	 *
	 *     § 37,6 BetrVG    § 37.6 BetrVG    § 37(6) BetrVG    § 37 Abs. 6 BetrVG
	 *
	 * WARUM DAS SO WEIT GEHT: Die Freistellung steht in den Quelldaten, im
	 * Freistellungsfeld einer Reihe und im Regelwerk – dreimal von Hand
	 * geschrieben, dreimal anders. Bis 1.95.0 fielen hier nur Punkt, Komma und
	 * Leerraum weg; die Klammerschreibweise „§ 37(6) BetrVG" traf deshalb die
	 * Regel „enthält 37,6" NICHT, obwohl auf der Einstellungsseite steht, dass
	 * beides dasselbe meint. Der Fehler ist teuer und unsichtbar: Es passiert
	 * nichts Falsches, es greift nur die Regel nicht – und der Anmeldeweg fällt
	 * still auf etwas anderes zurück.
	 *
	 * UMLAUTE BLEIBEN. Verglichen wird auf \p{L}, nicht auf a-z: „Präsenz" bleibt
	 * „präsenz". Ein Themenfeld oder Bildungszentrum verlöre sonst Buchstaben,
	 * und Regelwert und Begriff müssten den Verlust zufällig gleich treffen.
	 *
	 * Die Zahlen bleiben getrennt: § 37,6 -> „376betrvg", § 37,7 -> „377betrvg".
	 * Genau der Unterschied, um den es fachlich geht, überlebt.
	 */
	public static function norm( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s ) : strtolower( (string) $s );
		$s = str_replace(
			array( '§', 'paragraf', 'paragraph', 'par.', 'abs.', 'absatz', 'ziffer', 'nr.' ),
			' ',
			$s
		);
		return preg_replace( '/[^\p{L}\p{N}]/u', '', $s );
	}

	/**
	 * Trifft der Regelwert („ja" / „nein" / „leer") auf den gespeicherten Rohwert
	 * des Feldes „Anmeldung möglich"? Verglichen wird der rohe Wert, nicht der
	 * über meta_bool() gedeutete: „nie gespeichert" ist etwas anderes als „nein"
	 * und muss sich als „leer" auch getrennt ansprechen lassen.
	 */
	private static function flag_matches( $value, $raw ) {
		$v   = strtolower( trim( (string) $value ) );
		$raw = (string) $raw;
		if ( in_array( $v, array( 'leer', 'nicht gesetzt', 'none', 'empty', 'ohne' ), true ) ) {
			return '' === $raw;
		}
		if ( in_array( $v, array( 'ja', 'yes', '1', 'true' ), true ) ) {
			return '1' === $raw;
		}
		if ( in_array( $v, array( 'nein', 'no', '0', 'false' ), true ) ) {
			return '0' === $raw;
		}
		return false;
	}

	/** Enthält einer der Begriffe den gesuchten Teiltext? (Punkt/Komma/Leerzeichen/Groß-Klein egal) */
	private static function term_matches( $value, $names ) {
		$needle = self::norm( trim( (string) $value ) );
		if ( '' === $needle || ! $names ) {
			return false;
		}
		foreach ( (array) $names as $name ) {
			if ( false !== strpos( self::norm( $name ), $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Prüft, ob eine Regel auf ein Seminar zutrifft.
	 *
	 * Öffentlich, weil die Zuordnung der Anmeldeformulare (BI_Formulare) genau
	 * diesen Vergleich braucht. Eine zweite Umsetzung daneben wäre eine zweite
	 * Wahrheit darüber, was „enthält" heißt.
	 */
	public static function rule_matches( $post_id, $rule ) {
		$field = $rule['field'] ?? '';
		$value = trim( (string) ( $rule['value'] ?? '' ) );
		if ( '' === $field || '' === $value ) {
			return false;
		}

		// Eine Ausbildungsreihe ist kein Seminar, geht aber durch dasselbe
		// Regelwerk – siehe reihen_felder().
		if ( class_exists( 'BI_Reihen' ) && BI_Reihen::CPT === get_post_type( $post_id ) ) {
			return self::reihe_matches( $post_id, $field, $value );
		}

		if ( 'seminarform' === $field ) {
			return self::term_matches( $value, self::form_names( get_post_type( $post_id ) ) );
		}

		$haken = self::field_haken( $field );
		if ( '' !== $haken ) {
			return self::flag_matches( $value, get_post_meta( $post_id, $haken, true ) );
		}

		$tax = self::field_tax( $field );
		if ( ! $tax ) {
			return false;
		}
		$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return false;
		}
		return self::term_matches( $value, $terms );
	}

	/* ---------- Dieselben Regeln für eine Ausbildungsreihe ---------- */

	/**
	 * Welche Regelfelder eine Ausbildungsreihe überhaupt beantworten kann.
	 *
	 * Die Reihe hat ein EIGENES Freistellungsfeld (`_bir_freistellung`) und den
	 * Haken „Auf der Website anzeigen" – mehr nicht. Themenfeld, Zielgruppe und
	 * Bildungszentrum sind Taxonomien der Seminare; die Reihe ist für sie gar
	 * nicht registriert. „Anmeldung möglich" und „Ausgebucht" gibt es an ihr
	 * nicht, und eine Seminarform hat eine Abfolge von Terminen auch nicht.
	 *
	 * WARUM DIE LISTE UND NICHT EINFACH „passt halt nicht":
	 * Ein leeres Feld ist bei den Haken ein ANSPRECHBARER Wert („leer"). Eine
	 * Regel „Haken ‚Anmeldung möglich‘ = leer → keine Anmeldung" träfe damit auf
	 * JEDE Reihe zu, weil das Meta an ihr nie gesetzt wurde – und die Reihen
	 * wären über Nacht alle unbuchbar. Dasselbe bei der Seminarform: Ohne diese
	 * Liste gälte jede Reihe als „Präsenz", weil form_names() alles, was nicht
	 * Online ist, für Präsenz hält.
	 *
	 * @return string[] Regelfelder, die an einer Reihe eine Aussage haben.
	 */
	public static function reihen_felder() {
		return array( 'freistellung', 'anzeigen' );
	}

	/** Trifft eine Regel auf eine Ausbildungsreihe zu? */
	private static function reihe_matches( $reihe_id, $field, $value ) {
		if ( ! in_array( $field, self::reihen_felder(), true ) ) {
			return false;
		}
		if ( 'anzeigen' === $field ) {
			return self::flag_matches( $value, get_post_meta( $reihe_id, '_bi_anzeigen', true ) );
		}

		// Freistellung: an der Reihe ein Textfeld, kein Begriffs-Vorrat. Zerlegt
		// wird nach Zeilen, wie überall sonst auch, wo mehrere Angaben in einem
		// Feld stehen. Verglichen wird danach mit demselben „enthält" wie bei
		// den Begriffen des Seminars – „37,6" findet „§ 37,6 BetrVG".
		$roh    = (string) get_post_meta( $reihe_id, '_bir_freistellung', true );
		$zeilen = array_filter( array_map( 'trim', preg_split( '/\R/', $roh ) ), 'strlen' );
		return $zeilen ? self::term_matches( $value, $zeilen ) : false;
	}

	/**
	 * Die Variante, die eine Regel VERGIBT – oder '' , wenn keine greift.
	 *
	 * Der Unterschied zu variant_for() ist der Normalfall: Dort wird aus „keine
	 * Regel trifft zu" ein 'direct'. Wer wissen will, ob überhaupt jemand
	 * entschieden hat, kann das danach nicht mehr auseinanderhalten – und für
	 * die Ausbildungsreihen ist genau das die Frage: Greift eine Regel, gilt
	 * sie; greift keine, bleibt es beim bisherigen Weg über die Freistellungen
	 * der einzelnen Termine (BI_Reihen).
	 *
	 * @return string '' | 'direct' | 'gs' | 'keine'
	 */
	public static function matched_variant( $post_id ) {
		foreach ( self::rules() as $rule ) {
			if ( self::rule_matches( $post_id, $rule ) ) {
				$variant = (string) ( $rule['variant'] ?? '' );
				// Erlaubnisliste: Eine unbekannte Variante – etwa aus einer alten
				// gespeicherten Regel – darf nicht dazu führen, dass ein Seminar
				// plötzlich als direkt buchbar gilt.
				return in_array( $variant, self::variant_keys(), true ) ? $variant : 'direct';
			}
		}
		return '';
	}

	/**
	 * Anmeldevariante eines Seminars ermitteln: 'direct' | 'gs' | 'keine'.
	 *
	 * Erst die Regeln (erster Treffer gewinnt), sonst der Haken
	 * „Anmeldung möglich" am Seminar: gesetzt = 'direct', nicht gesetzt =
	 * 'keine'. Die Geschäftsstellen-Variante ('gs') kommt ausschließlich aus
	 * einer Regel – siehe unten.
	 */
	public static function variant_for( $post_id ) {
		$variant = self::matched_variant( $post_id );
		if ( '' !== $variant ) {
			return $variant;
		}
		// Trifft keine Regel zu, gilt der Normalfall: Direktanmeldung.
		//
		// DIE HAKEN AM SEMINAR ENTSCHEIDEN HIER NICHT MIT. Sie sind Angaben zum
		// Seminar, keine Anmeldewege – ob und wie sie den Weg beeinflussen, sagt
		// die Regel, die sich auf sie beruft („Haken ‚Anmeldung möglich‘ = nein →
		// Keine Anmeldung"). So steht der Weg an einer Stelle statt an zweien,
		// und man sieht ihn dort, wo man ihn ändert.
		//
		// Die Voreinstellung bringt genau diese eine Regel mit (siehe
		// defaults()), damit ein leerer Haken auch ohne Handgriff wirkt.
		return 'direct';
	}

	/**
	 * Wie oft entscheidet welche Regel – und welche Regel wird von einer
	 * darüberstehenden verdeckt?
	 *
	 * Ausgewertet an den tatsächlichen Seminaren, nicht an den Regeltexten:
	 * Ob eine Regel je zum Zug kommt, hängt an den Daten. „Freistellung enthält
	 * 37,6 → Direktanmeldung" verdeckt „Flag nein → keine Anmeldung", obwohl die
	 * beiden auf verschiedenen Feldern stehen und sich aus dem Text heraus nichts
	 * ansehen lassen. Genau dieser Fall bleibt sonst unbemerkt: Die untere Regel
	 * sieht richtig aus, greift aber nie.
	 *
	 * Läuft nur beim Aufbau der Einstellungsseite. Wenige Abfragen: einmal die
	 * IDs, je Taxonomie einmal die Begriffe, je genutztem Haken einmal die Spalte –
 * danach nur noch
	 * PHP-Vergleiche.
	 *
	 * @return array je Regelindex: ['trifft'=>int,'gewinnt'=>int,'verdeckt'=>int,'verdeckt_von'=>int|null]
	 *               plus unter dem Schlüssel 'meta': ['seminare'=>int,'gekappt'=>bool]
	 */
	public static function rule_stats( $limit = 4000 ) {
		$rules = self::rules();
		$stats = array( 'meta' => array( 'seminare' => 0, 'gekappt' => false ) );
		foreach ( $rules as $i => $r ) {
			$stats[ $i ] = array( 'trifft' => 0, 'gewinnt' => 0, 'verdeckt' => 0, 'verdeckt_von' => null );
		}
		if ( ! $rules ) {
			return $stats;
		}

		$ids = get_posts( array(
			'post_type'        => bi_seminar_post_types(),
			'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'posts_per_page'   => $limit + 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => true,
		) );
		if ( count( $ids ) > $limit ) {
			$ids                       = array_slice( $ids, 0, $limit );
			$stats['meta']['gekappt']  = true;
		}
		$stats['meta']['seminare'] = count( $ids );
		if ( ! $ids ) {
			return $stats;
		}

		// Begriffe je Regelfeld vorab holen – sonst je Seminar und Regel eine Abfrage.
		$terms_by = array();
		foreach ( $rules as $r ) {
			$feld = (string) ( $r['field'] ?? '' );
			$tax  = self::field_tax( $feld );
			if ( ! $tax || isset( $terms_by[ $feld ] ) ) {
				continue;
			}
			$terms_by[ $feld ] = array();
			$objs = wp_get_object_terms( $ids, $tax, array( 'fields' => 'all_with_object_id' ) );
			if ( ! is_wp_error( $objs ) ) {
				foreach ( $objs as $t ) {
					$terms_by[ $feld ][ (int) $t->object_id ][] = $t->name;
				}
			}
		}

		// Haken-Rohwerte in einem Zug, je Meta-Schlüssel eine Abfrage.
		// get_post_meta() je Seminar wäre hier tausendfach dasselbe, und der
		// Meta-Cache für alle Felder wäre teurer als die paar Spalten.
		$haken = array(); // feld => [ post_id => Rohwert ]
		$keys  = array();
		foreach ( $rules as $r ) {
			$feld = (string) ( $r['field'] ?? '' );
			$key  = self::field_haken( $feld );
			if ( '' !== $key ) {
				$keys[ $feld ] = $key;
			}
		}
		foreach ( $keys as $feld => $key ) {
			global $wpdb;
			$in   = implode( ',', array_map( 'intval', $ids ) ); // nur Ganzzahlen, daher ohne prepare()
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($in)", $key ) ); // phpcs:ignore WordPress.DB
			$haken[ $feld ] = array();
			foreach ( (array) $rows as $row ) {
				$haken[ $feld ][ (int) $row->post_id ] = (string) $row->meta_value;
			}
		}

		// Seminarform: eine Abfrage statt get_post_type() je Seminar. Nur nötig,
		// wenn überhaupt eine Regel danach fragt.
		$formen = array();
		foreach ( $rules as $r ) {
			if ( 'seminarform' !== (string) ( $r['field'] ?? '' ) ) {
				continue;
			}
			global $wpdb;
			$in   = implode( ',', array_map( 'intval', $ids ) ); // nur Ganzzahlen, daher ohne prepare()
			$rows = $wpdb->get_results( "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore WordPress.DB
			foreach ( (array) $rows as $row ) {
				$formen[ (int) $row->ID ] = self::form_names( (string) $row->post_type );
			}
			break;
		}

		foreach ( $ids as $pid ) {
			$sieger = null;
			foreach ( $rules as $i => $r ) {
				$feld = (string) ( $r['field'] ?? '' );
				$wert = trim( (string) ( $r['value'] ?? '' ) );
				if ( '' === $feld || '' === $wert ) {
					continue;
				}
				if ( isset( $haken[ $feld ] ) ) {
					$passt = self::flag_matches( $wert, $haken[ $feld ][ $pid ] ?? '' );
				} elseif ( 'seminarform' === $feld ) {
					$passt = self::term_matches( $wert, $formen[ $pid ] ?? array() );
				} else {
					$passt = self::term_matches( $wert, $terms_by[ $feld ][ $pid ] ?? array() );
				}
				if ( ! $passt ) {
					continue;
				}
				$stats[ $i ]['trifft']++;
				if ( null === $sieger ) {
					$sieger = $i;
					$stats[ $i ]['gewinnt']++;
				} else {
					$stats[ $i ]['verdeckt']++;
					if ( null === $stats[ $i ]['verdeckt_von'] ) {
						$stats[ $i ]['verdeckt_von'] = $sieger;
					}
				}
			}
		}

		return $stats;
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Permalink der Direktanmeldungs-Seite (konfiguriert, sonst leer) */
	public static function anmeldung_page_url() {
		$pid = (int) self::get( 'anmeldung_page_id' );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return get_permalink( $pid );
		}
		return '';
	}

	/** Permalink der Seminarübersichts-Seite mit [bi_seminarsuche] (konfiguriert, sonst leer) */
	public static function uebersicht_page_url() {
		$pid = (int) self::get( 'uebersicht_page_id' );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return get_permalink( $pid );
		}
		return '';
	}

	/** ---------- Admin-Seite ---------- */

	public static function render_page() {
		$s      = self::all();
		$notice = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'allgemein';
		$tabs   = array(
			'allgemein'      => 'Anmeldung & Regeln',
			'suche'          => 'Such-Filterleiste',
			'pdf'            => 'PDF-Anhänge',
			'seminarimport'  => 'Seminar-Import',
			'onlineimport'   => 'Online-Seminar-Import',
			'plzimport'      => 'PLZ-Import',
			'verfuegbarkeit' => 'Verfügbarkeits-Ampel',
			'einbettung'     => 'Einbettung (iframe)',
			'abgleich'       => 'Abgleich',
			'datenschutz'    => 'Datenschutz',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'allgemein';
		}
		$base = admin_url( 'admin.php?page=bi-einstellungen' );
		?>
		<div class="wrap">
			<h1>Einstellungen</h1>
			<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'suche' === $tab ) {
				self::render_suche_section( $s );
				echo '</div>';
				return;
			}
			if ( 'pdf' === $tab ) {
				self::render_pdf_section( $s );
				echo '</div>';
				return;
			}
			if ( 'seminarimport' === $tab ) {
				BI_Import::render_section( BI_CPT );
				echo '</div>';
				return;
			}
			if ( 'onlineimport' === $tab ) {
				BI_Import::render_section( BI_ONLINE );
				echo '</div>';
				return;
			}
			if ( 'plzimport' === $tab ) {
				BI_PLZ::render_section();
				echo '</div>';
				return;
			}
			if ( 'verfuegbarkeit' === $tab ) {
				BI_Ampel::render_section();
				echo '</div>';
				return;
			}
			if ( 'einbettung' === $tab ) {
				self::render_embed_section( $s );
				echo '</div>';
				return;
			}
			if ( 'abgleich' === $tab ) {
				// class_exists: Das Abgleich-Modul wird wie der Einbettungsmodus
				// nachsichtig geladen (siehe igm-bildungsprogramm.php). Fehlt die
				// Datei nach einem Handdeploy, soll der Reiter das sagen und nicht
				// die Einstellungsseite mitreißen.
				if ( class_exists( 'BI_Sync' ) ) {
					BI_Sync::render_section();
				} else {
					echo '<div class="notice notice-error"><p><strong>includes/class-bi-sync.php fehlt.</strong> '
						. 'Der Abgleich mit anderen Installationen ist deshalb aus. Datei nachträglich hochladen.</p></div>';
				}
				echo '</div>';
				return;
			}
			if ( 'datenschutz' === $tab ) {
				// Erst die Frist (wirkt tatsächlich auf Daten), dann der Textbaustein.
				BI_Retention::render_section();
				echo '<hr style="margin:28px 0">';
				self::render_privacy_section();
				echo '</div>';
				return;
			}
			?>

			<p>Hier legst du die drei <strong>Anmeldevarianten</strong> fest. Ohne Regel entscheidet das Feld
			   <em>„Anmeldung möglich"</em> am Seminar: <strong>ja</strong> → Direktanmeldung (Formular),
			   <strong>nein</strong> → nur über die Geschäftsstelle. Die dritte Variante
			   <em>„Keine Anmeldung möglich"</em> lässt sich nur über eine <a href="#bi-regeln">Regel</a>
			   setzen – sie ist im Ja/Nein-Feld nicht unterzubringen.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_save_settings">
				<?php wp_nonce_field( 'bi_save_settings' ); ?>
				<?php /* Die Pfeile in der Regeltabelle sind Absende-Schaltflächen. Ohne diese
				         unsichtbare erste Schaltfläche wäre der oberste Pfeil das Ziel der
				         Eingabetaste – Enter in einem Textfeld würde dann eine Regel
				         verschieben statt zu speichern. */ ?>
				<button type="submit" name="bi_speichern" value="1" tabindex="-1" aria-hidden="true"
				        style="position:absolute;left:-9999px;width:1px;height:1px">Speichern</button>

				<h2 class="title">Seiten</h2>
				<table class="form-table">
					<tr>
						<th><label for="uebersicht_page_id">Seminarübersicht</label></th>
						<td>
							<?php
							wp_dropdown_pages( array(
								'name'              => 'uebersicht_page_id',
								'id'                => 'uebersicht_page_id',
								'selected'          => (int) $s['uebersicht_page_id'],
								'show_option_none'  => '— automatisch erkennen —',
								'option_none_value' => 0,
							) );
							?>
							<p class="description">Die Seite mit dem Shortcode <code>[bi_seminarsuche]</code>.
								Der Button „Zur Seminarübersicht" auf der Anmelde-Bestätigung springt hierher.
								„Automatisch erkennen" sucht die passende Seite selbst.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Variante 1: Anmeldung per Formular (Direktanmeldung)</h2>
				<table class="form-table">
					<tr>
						<th><label for="anmeldung_page_id">Anmeldeseite</label></th>
						<td>
							<?php
							wp_dropdown_pages( array(
								'name'              => 'anmeldung_page_id',
								'id'                => 'anmeldung_page_id',
								'selected'          => (int) $s['anmeldung_page_id'],
								'show_option_none'  => '— automatisch erkennen —',
								'option_none_value' => 0,
							) );
							?>
							<p class="description">Die Seite, die den Shortcode <code>[bi_anmeldung]</code> enthält.
								Das Seminar wird automatisch als <code>?seminar=ID</code> übergeben.
								„Automatisch erkennen" sucht selbst die passende Seite.</p>
						</td>
					</tr>
					<tr>
						<th><label for="direct_label">Button-Text</label></th>
						<td><input type="text" class="regular-text" id="direct_label" name="direct_label" value="<?php echo esc_attr( $s['direct_label'] ); ?>"></td>
					</tr>
				</table>

				<h2 class="title">Variante 2: Anmeldung über die Geschäftsstelle</h2>
				<p>Auf der Detailseite steht dann die <strong>PLZ-Suche</strong>: Postleitzahl eingeben,
				   zuständige Geschäftsstelle nachschlagen, Anfrage als vorausgefüllte E-Mail öffnen.</p>
				<table class="form-table">
					<tr>
						<th><label for="gs_hinweis">Hinweistext</label></th>
						<td><input type="text" class="large-text" id="gs_hinweis" name="gs_hinweis" value="<?php echo esc_attr( $s['gs_hinweis'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="gs_label">Button-Text</label></th>
						<td><input type="text" class="regular-text" id="gs_label" name="gs_label" value="<?php echo esc_attr( $s['gs_label'] ); ?>"></td>
					</tr>
				</table>

				<h2 class="title">Variante 3: Keine Anmeldung möglich</h2>
				<p>Für Seminare, die über diese Website gar nicht gebucht werden – etwa weil sie einer
				   geschlossenen Gruppe gehören oder anderswo verwaltet werden. Statt eines Buttons steht
				   dann der <strong>Störer</strong> auf der Detailseite: das runde Element des
				   Design-Systems, kurz und plakativ, darunter der erklärende Satz.</p>
				<p class="description" style="margin:-8px 0 12px">Diese Variante greift <strong>nur über eine
				   Regel</strong> weiter unten – das Feld „Anmeldung möglich" am Seminar kennt nur ja/nein
				   und kann „gar nicht" von „über die Geschäftsstelle" nicht unterscheiden.</p>
				<table class="form-table">
					<tr>
						<th><label for="keine_label">Text im Störer</label></th>
						<td>
							<input type="text" class="regular-text" id="keine_label" name="keine_label" value="<?php echo esc_attr( $s['keine_label'] ); ?>">
							<p class="description">Kurz halten – der Störer ist rund. Zwei bis drei Wörter.</p>
						</td>
					</tr>
					<tr>
						<th><label for="keine_hinweis">Hinweistext</label></th>
						<td>
							<input type="text" class="large-text" id="keine_hinweis" name="keine_hinweis" value="<?php echo esc_attr( $s['keine_hinweis'] ); ?>">
							<p class="description">Steht unter dem Störer und sagt, warum – und wohin man sich sonst wendet.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Ausbildungsreihen: Wann darf am Stück gebucht werden?</h2>
				<p><strong>Zuerst gelten die Regeln weiter unten.</strong> Sie werden auch auf die Reihe
				   selbst angewendet – verglichen wird dann ihr eigenes Feld <em>Freistellung</em>. Eine
				   Aussage wie „§ 37,6 BetrVG → Direktanmeldung" gilt der Sache nach und damit für die
				   Abfolge genauso wie für das einzelne Seminar. Trifft eine Regel zu, entscheidet
				   sie – auch mit <em>Keine Anmeldung</em>.</p>
				<p>Trifft <strong>keine</strong> Regel auf die Reihe zu – etwa weil ihr Freistellungsfeld
				   leer ist –, gilt diese Liste: Die Reihe lässt sich nur in einem Zug anmelden, wenn
				   <strong>jeder</strong> ihrer Teile eine der hier genannten Freistellungen trägt. Trägt
				   auch nur ein Teil eine andere, läuft die Anmeldung der ganzen Reihe über die
				   <strong>Geschäftsstellen-Anfrage per E-Mail</strong> – mit allen gewählten Terminen
				   vorausgefüllt.</p>
				<p class="description">
					An einer Reihe wirken nur die Regelfelder <em>Freistellung</em> und
					<em>Auf der Website anzeigen</em> – mehr hat sie nicht. Themenfeld, Zielgruppe und
					Bildungszentrum hängen an den Seminaren, und die Haken <em>Anmeldung möglich</em> und
					<em>Ausgebucht</em> gibt es an einer Abfolge nicht. Regeln auf diesen Feldern lassen
					eine Reihe unberührt, statt sie versehentlich alle zu treffen.
				</p>
				<table class="form-table">
					<tr>
						<th><label for="sammel_freistellung">Freistellungen mit Direktanmeldung</label></th>
						<td>
							<textarea id="sammel_freistellung" name="sammel_freistellung" rows="4" class="large-text code"><?php
								echo esc_textarea( $s['sammel_freistellung'] ); ?></textarea>
							<p class="description">
								Eine Angabe je Zeile. Verglichen wird nachsichtig: <code>§ 37,6 BetrVG</code>,
								<code>§ 37 Abs. 6 BetrVG</code> und <code>§37.6 BetrVG</code> gelten als dasselbe.
								Leer = keine Reihe ist am Stück buchbar, alles läuft über die Geschäftsstelle.
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title" id="bi-regeln">Regeln: Welche Variante gilt für welches Seminar?</h2>
				<p><strong>Alle drei Anmeldewege kommen aus dieser Liste.</strong> Sie wird
				   <strong>von oben nach unten</strong> geprüft – die <strong>erste zutreffende</strong> Regel bestimmt
				   den Weg. Trifft keine Regel zu, gilt der Normalfall <strong>Direktanmeldung</strong>.</p>
				<p>Dieselben Regeln gelten für <strong>Ausbildungsreihen</strong>, verglichen wird dort das
				   Freistellungsfeld der Reihe. Der Unterschied steckt nur im Normalfall: Trifft auf eine
				   Reihe keine Regel zu, wird sie <em>nicht</em> still direkt buchbar, sondern es
				   entscheiden wie bisher die Freistellungen ihrer Termine (siehe oben).</p>
				<p>Die Haken am Seminar (<em>Anmeldung möglich</em>, <em>Ausgebucht</em>,
				   <em>Auf der Website anzeigen</em>) sind Angaben zum Seminar, keine Anmeldewege. Sie stehen hier
				   als Feld zur Verfügung und <em>können</em> den Weg damit beeinflussen – genau wie Freistellung,
				   Themenfeld, Zielgruppe oder Bildungszentrum.</p>
				<p class="description">
					Werte: bei Taxonomien ein <em>Teiltext</em> – z. B. <code>Bildungsurlaub</code> oder <code>37,6</code>
					(„enthält"-Vergleich; Punkt/Komma/Groß-Klein egal). Bei den Haken: <code>ja</code>, <code>nein</code>
					oder <code>leer</code> (nie gespeichert – etwas anderes als <code>nein</code>).
				</p>

				<?php
				// Sicherheitsnetz: Ohne eine Regel auf „Anmeldung möglich" wirkt der
				// leere Haken nicht mehr – die betroffenen Seminare wären buchbar.
				// Bei einer frischen Installation steht die Regel in den Vorgaben;
				// wer sie löscht, soll wenigstens sehen, was das bedeutet.
				$hat_flag_regel = false;
				foreach ( self::rules() as $r ) {
					if ( 'flag' === ( $r['field'] ?? '' ) ) {
						$hat_flag_regel = true;
						break;
					}
				}
				$ohne_anmeldung = $hat_flag_regel ? 0 : (int) count( get_posts( array(
					'post_type'   => bi_seminar_post_types(),
					'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'fields'      => 'ids',
					'numberposts' => 200,
					'meta_query'  => array( array( 'key' => '_bi_anmeldung_moeglich', 'value' => '0' ) ),
				) ) );
				?>
				<?php if ( $ohne_anmeldung ) : ?>
					<div class="notice notice-warning inline" style="margin:0 0 12px;max-width:1080px">
						<p><strong><?php echo esc_html( number_format_i18n( $ohne_anmeldung ) ); ?><?php echo 200 === $ohne_anmeldung ? '+' : ''; ?>
						   Seminare haben den Haken „Anmeldung möglich" <em>nicht</em> gesetzt</strong> – und keine Regel
						   bezieht sich auf diesen Haken. Damit gilt für sie der Normalfall: Sie sind
						   <strong>direkt buchbar</strong>.</p>
						<p>Ist das nicht gewollt, lege unten eine Regel an:
						   <code>Haken „Anmeldung möglich"</code> · <code>nein</code> · <code>Keine Anmeldung möglich</code>.</p>
					</div>
				<?php endif; ?>

				<?php $stats = self::rule_stats(); ?>
				<table class="widefat striped" style="max-width:1080px;margin-bottom:8px">
					<thead><tr>
						<th style="width:76px">Reihen&shy;folge</th>
						<th style="width:230px">Wenn Feld</th>
						<th>enthält / ist</th>
						<th style="width:260px">dann Variante</th>
						<th style="width:90px">Löschen</th>
					</tr></thead>
					<tbody>
						<?php
						$rules    = self::rules();
						$rules[]  = array( 'field' => '', 'value' => '', 'variant' => 'direct' ); // Leerzeile zum Anlegen
						$last_idx = count( $rules ) - 1;
						foreach ( $rules as $i => $rule ) :
							$is_new = ( $i === $last_idx );
							$st     = $is_new ? null : ( $stats[ $i ] ?? null );
							?>
							<tr>
								<td>
									<?php if ( ! $is_new ) : ?>
										<span style="color:#646970"><?php echo (int) $i + 1; ?>.</span>
										<button type="submit" class="button button-small" name="rule_move" value="up-<?php echo (int) $i; ?>"
										        title="nach oben" aria-label="Regel <?php echo (int) $i + 1; ?> nach oben schieben"
										        <?php disabled( 0 === $i ); ?>>&#9650;</button>
										<button type="submit" class="button button-small" name="rule_move" value="down-<?php echo (int) $i; ?>"
										        title="nach unten" aria-label="Regel <?php echo (int) $i + 1; ?> nach unten schieben"
										        <?php disabled( $i >= $last_idx - 1 ); ?>>&#9660;</button>
									<?php endif; ?>
								</td>
								<td>
									<select name="rule[<?php echo $i; ?>][field]">
										<option value=""><?php echo $is_new ? '— neue Regel —' : '—'; ?></option>
										<?php foreach ( self::rule_fields() as $val => $lbl ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule['field'] ?? '', $val ); ?>><?php echo esc_html( $lbl ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<input type="text" class="regular-text" name="rule[<?php echo $i; ?>][value]" value="<?php echo esc_attr( $rule['value'] ?? '' ); ?>" placeholder="z. B. Bildungsurlaub / 37,6 / ja">
									<?php
									if ( $st ) :
										// Rot nur, wenn die Regel wirkungslos ist. Dass eine Ausnahme
										// weiter oben der allgemeinen Regel darunter Seminare wegnimmt,
										// ist der Zweck der Reihenfolge und keine Warnung wert – die
										// Zahl dazu steht trotzdem da.
										$stumpf = ( 0 === $st['gewinnt'] );
										?>
										<p class="description" style="margin:4px 0 0<?php echo $stumpf ? ';color:#b32d2e' : ''; ?>">
											<?php if ( 0 === $st['trifft'] ) : ?>
												<strong>Trifft auf kein Seminar zu</strong> – Wert prüfen.
											<?php elseif ( $stumpf ) : ?>
												<strong>Greift nie:</strong> Bei allen <?php echo esc_html( number_format_i18n( $st['trifft'] ) ); ?>
												zutreffenden Seminaren entscheidet Regel <?php echo (int) $st['verdeckt_von'] + 1; ?> zuerst.
												Zum Wirken muss diese Regel darüber stehen.
											<?php else : ?>
												Entscheidet <?php echo esc_html( number_format_i18n( $st['gewinnt'] ) ); ?>
												von <?php echo esc_html( number_format_i18n( $st['trifft'] ) ); ?> zutreffenden Seminaren.
												<?php if ( $st['verdeckt'] ) : ?>
													Bei <?php echo esc_html( number_format_i18n( $st['verdeckt'] ) ); ?>
													entscheidet Regel <?php echo (int) $st['verdeckt_von'] + 1; ?> zuerst.
												<?php endif; ?>
											<?php endif; ?>
										</p>
									<?php endif; ?>
								</td>
								<td>
									<select name="rule[<?php echo $i; ?>][variant]">
										<?php foreach ( self::rule_variants() as $val => $lbl ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule['variant'] ?? 'direct', $val ); ?>><?php echo esc_html( $lbl ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><?php if ( ! $is_new ) : ?><label><input type="checkbox" name="rule[<?php echo $i; ?>][delete]" value="1"> löschen</label><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="max-width:1080px">
					<?php if ( $stats['meta']['seminare'] ) : ?>
						Die Angaben unter den Werten sind an <strong><?php echo esc_html( number_format_i18n( $stats['meta']['seminare'] ) ); ?></strong>
						Seminaren gemessen<?php echo $stats['meta']['gekappt'] ? ' (mehr wurden nicht geprüft)' : ''; ?> –
						ein Stand von jetzt, kein fester Wert. Dass eine Regel einer anderen Seminare wegnimmt, ist normal:
						Die Ausnahme gehört nach oben, das Allgemeine nach unten. Zu prüfen ist nur, was <strong>rot</strong>
						steht – eine Regel, die nie zum Zug kommt. Die Pfeile links verschieben sie.
						<br><strong>Gezählt werden nur Seminare</strong>, keine Ausbildungsreihen: Die Reihen sind zu
						wenige, um an ihnen etwas zu erkennen, und eine Regel, die nur auf sie zutrifft, stünde hier
						sonst als „trifft nie zu" – rot, obwohl sie tut, was sie soll.
					<?php else : ?>
						Es gibt noch keine Seminare, an denen sich die Regeln messen ließen.
					<?php endif; ?>
				</p>

				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>
		</div>
		<?php
	}

	/* ===================================================================
	 *  Tab „PDF-Anhänge"
	 * =================================================================== */

	/**
	 * Logo und Veranstalterangabe für die beiden PDF-Anhänge der Anmeldemails,
	 * plus Vorschau-Links. Welche Benachrichtigung welches PDF anhängt, wird
	 * je Benachrichtigung unter „Benachrichtigungen" eingestellt.
	 */
	/**
	 * Tab „Such-Filterleiste": welche Chips die Leiste im Frontend anbietet.
	 *
	 * Die Liste der möglichen Filter kommt aus BI_Filter::facets() – wer dort
	 * eine Facette ergänzt, findet sie hier ohne weiteres Zutun wieder.
	 */
	private static function render_suche_section( $s ) {
		if ( ! class_exists( 'BI_Filter' ) ) {
			echo '<p>Die Filterleiste steht in dieser Installation nicht zur Verfügung.</p>';
			return;
		}

		$facets = BI_Filter::facets();
		$aktiv  = is_array( $s['filter_facets'] ?? null ) ? $s['filter_facets'] : array_keys( $facets );

		// Wie viele Einträge jede Facette gerade anbieten würde. Eine Facette ohne
		// buchbare Seminare bliebe im Frontend leer – das soll man hier sehen,
		// bevor man sie einschaltet und sich über den fehlenden Chip wundert.
		$choices = BI_Filter::facet_choices();
		?>
		<p>Hier legst du fest, <strong>welche Filter die Suchleiste anbietet</strong> – sowohl auf der
			Seminarübersicht (<code>[bi_seminarsuche]</code>) als auch in der eigenständigen
			Suchmaske (<code>[bi_suchmaske]</code>).</p>

		<div class="notice notice-info inline" style="margin:16px 0;padding:8px 12px">
			<p style="margin:.4em 0"><strong>Abschalten nimmt nur den Chip weg, nicht die Wirkung.</strong>
				Steht der Filter in einer Adresse – etwa in einer Marketing-Kachel oder einem
				verschickten Link –, filtert er weiterhin. Sonst würden solche Links in dem Moment
				still eine größere Treffermenge zeigen, in dem hier ein Haken fällt.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_save_settings">
			<input type="hidden" name="bi_tab" value="suche">
			<?php wp_nonce_field( 'bi_save_settings' ); ?>

			<table class="form-table">
				<tr>
					<th>Angebotene Filter</th>
					<td>
						<fieldset>
							<?php foreach ( $facets as $param => $f ) : ?>
								<?php
								$anzahl = 0;
								foreach ( (array) ( $choices[ $param ] ?? array() ) as $opt ) {
									if ( empty( $opt['separator'] ) ) {
										$anzahl++;
									}
								}
								?>
								<label style="display:block;margin-bottom:6px">
									<input type="checkbox" name="filter_facets[]"
									       value="<?php echo esc_attr( $param ); ?>"
										<?php checked( in_array( $param, $aktiv, true ) ); ?>>
									<strong><?php echo esc_html( $f['label'] ); ?></strong>
									<code style="margin-left:6px">?<?php echo esc_html( $param ); ?>=</code>
									<span style="color:#646970">
										<?php
										echo esc_html( $anzahl
											? sprintf( '– derzeit %s Auswahlmöglichkeiten', number_format_i18n( $anzahl ) )
											: '– derzeit keine buchbaren Einträge, der Chip bliebe leer' );
										?>
									</span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description">Die Reihenfolge in der Leiste ist fest vorgegeben und entspricht
							dieser Liste. Ein Chip erscheint nur, wenn er tatsächlich etwas zu unterscheiden
							hat – wo es nur eine Möglichkeit gibt, bleibt er ohnehin weg.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">Programm-Filter im Jahreswechsel</h2>
			<p>Der Filter <strong>Programm</strong> ist für den Übergang gedacht, in dem zwei Programmjahre
				nebeneinander buchbar sind. Ihn dauerhaft angehakt zu lassen ist unbedenklich:
				<strong>Der Chip erscheint nur, solange es wirklich mehr als ein Programm zu wählen gibt.</strong>
				Läuft das alte Jahr aus, verschwindet er von allein – ein einzelnes verbliebenes Programm ist
				keine Wahl mehr, sondern nur noch eine Feststellung. Angeboten werden ohnehin ausschließlich
				Programme mit buchbaren Seminaren.</p>
			<p class="description">Legt eine Seite das Jahr per Shortcode fest
				(<code>[bi_seminarsuche programm="2027"]</code>), erscheint dort kein Programm-Chip:
				Er könnte die Vorgabe der Seite nicht aufheben, sondern nur weiter einengen.</p>

			<?php self::render_shortcut_tabelle(); ?>

			<?php submit_button( 'Speichern' ); ?>
		</form>

		<?php self::render_cache_kasten(); ?>
		<?php
	}

	/**
	 * Eigene Chips: frei benannte Suchen, die als zusätzlicher Knopf in der
	 * Leiste stehen („Schnellzugriff").
	 *
	 * Eingegeben wird, was man ohnehin vor sich hat: der Name des Chips und die
	 * Adresse einer Suche, wie sie nach dem Filtern in der Adresszeile steht.
	 * Aus der Adresse zählt NUR der Filterteil – die Seite selbst wird bewusst
	 * verworfen (siehe BI_Filter::url_params). Der Chip setzt Filter, er springt
	 * nicht auf eine andere Seite; damit ist es gleichgültig, von welcher Seite
	 * die Adresse kopiert wurde, und derselbe Chip taugt für Ergebnisseite und
	 * Suchmaske.
	 *
	 * Die Zeilen liegen in derselben Form vor wie die Anmelderegeln: eine
	 * Leerzeile am Ende legt einen neuen Chip an, ein Haken löscht, die Pfeile
	 * verschieben. Zu jeder Zeile steht, welche Filter erkannt wurden und wie
	 * viele Seminare sie gerade träfe – ein vertippter Parameter fällt so beim
	 * Speichern auf und nicht erst im Frontend.
	 */
	private static function render_shortcut_tabelle() {
		$chips   = self::filter_shortcuts();
		$chips[] = array( 'label' => '', 'url' => '' ); // Leerzeile zum Anlegen
		$last    = count( $chips ) - 1;
		?>
		<h2 class="title" id="bi-chips">Eigene Chips</h2>
		<p>Neben den Filtern oben kann die Leiste <strong>eigene Chips</strong> anbieten – gespeicherte
			Suchen mit einem Namen, den du vergibst. Gedacht sind sie für Zusammenstellungen, die sich
			aus keinem einzelnen Filter ergeben: <em>„Neu im Programm"</em>, <em>„Für neue
			Betriebsräte"</em>, <em>„Online im Herbst"</em>.</p>
		<p class="description" style="max-width:1080px">
			<strong>So kommst du an die Adresse:</strong> Auf der Seminarübersicht die gewünschten Filter
			setzen, dann die Adresse aus der Adresszeile des Browsers kopieren und hier einfügen.
			Übernommen wird daraus nur der Filterteil – ein Klick auf den Chip <strong>setzt diese
			Filter, ohne die Seite zu wechseln</strong>. Ein zweiter Klick nimmt genau sie wieder
			zurück; was du daneben ausgewählt hast, bleibt stehen. Auch die reine Parameterliste
			(<code>form=online&amp;thema=Arbeitsrecht</code>) wird angenommen.
		</p>

		<table class="widefat striped" style="max-width:1080px;margin-bottom:8px">
			<thead><tr>
				<th style="width:76px">Reihen&shy;folge</th>
				<th style="width:240px">Name des Chips</th>
				<th>Adresse der Suche</th>
				<th style="width:90px">Löschen</th>
			</tr></thead>
			<tbody>
				<?php foreach ( $chips as $i => $chip ) : ?>
					<?php $is_new = ( $i === $last ); ?>
					<tr>
						<td>
							<?php if ( ! $is_new ) : ?>
								<span style="color:#646970"><?php echo (int) $i + 1; ?>.</span>
								<button type="submit" class="button button-small" name="shortcut_move" value="up-<?php echo (int) $i; ?>"
								        title="nach oben" aria-label="Chip <?php echo (int) $i + 1; ?> nach oben schieben"
								        <?php disabled( 0 === $i ); ?>>&#9650;</button>
								<button type="submit" class="button button-small" name="shortcut_move" value="down-<?php echo (int) $i; ?>"
								        title="nach unten" aria-label="Chip <?php echo (int) $i + 1; ?> nach unten schieben"
								        <?php disabled( $i >= $last - 1 ); ?>>&#9660;</button>
							<?php endif; ?>
						</td>
						<td>
							<input type="text" class="regular-text" style="width:100%"
							       name="shortcut[<?php echo (int) $i; ?>][label]"
							       value="<?php echo esc_attr( $chip['label'] ?? '' ); ?>"
							       placeholder="<?php echo $is_new ? '— neuer Chip —' : ''; ?>">
						</td>
						<td>
							<input type="text" class="regular-text" style="width:100%"
							       name="shortcut[<?php echo (int) $i; ?>][url]"
							       value="<?php echo esc_attr( $chip['url'] ?? '' ); ?>"
							       placeholder="https://…/seminare/?thema=Arbeitsrecht&amp;form=online">
							<?php if ( ! $is_new ) : ?>
								<?php echo self::shortcut_hinweis( $chip['url'] ?? '' ); ?>
							<?php endif; ?>
						</td>
						<td><?php if ( ! $is_new ) : ?><label><input type="checkbox" name="shortcut[<?php echo (int) $i; ?>][delete]" value="1"> löschen</label><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description" style="max-width:1080px">Ein Chip ohne Namen oder ohne erkennbaren Filter
			wird beim Speichern verworfen – er hätte im Frontend nichts zu tun. Am Chip steht bewusst
			keine Trefferzahl: Sie kostete bei jeder Suche eine eigene Abfrage. Die Zahl hier ist der
			Stand von jetzt und nur zur Kontrolle da.</p>
		<?php
	}

	/**
	 * Zeile unter dem Adressfeld: welche Filter erkannt wurden und wie viele
	 * Seminare der Chip derzeit träfe.
	 */
	private static function shortcut_hinweis( $url ) {
		if ( ! class_exists( 'BI_Filter' ) ) {
			return '';
		}
		$params = BI_Filter::url_params( $url );
		if ( ! $params ) {
			return '<p class="description" style="margin:4px 0 0;color:#b32d2e"><strong>Kein Filter erkannt</strong> – '
				. 'die Adresse enthält keinen der bekannten Parameter (' . esc_html( implode( ', ', BI_Filter::query_params() ) ) . ').</p>';
		}

		$teile = array();
		foreach ( $params as $key => $wert ) {
			$teile[] = '<code>' . esc_html( $key ) . '=' . esc_html( $wert ) . '</code>';
		}
		$treffer = BI_Filter::count_for_params( $params );

		return '<p class="description" style="margin:4px 0 0' . ( $treffer ? '' : ';color:#b32d2e' ) . '">'
			. implode( ' · ', $teile ) . ' – '
			. ( $treffer
				? sprintf( 'derzeit %s Treffer', esc_html( number_format_i18n( $treffer ) ) )
				: '<strong>derzeit kein Treffer</strong> – Werte prüfen (Schreibweise wie im Filter)' )
			. '</p>';
	}

	/** Gespeicherte eigene Chips (Liste aus label/url), immer als Liste. */
	public static function filter_shortcuts() {
		$all = self::all();
		$roh = ( isset( $all['filter_shortcuts'] ) && is_array( $all['filter_shortcuts'] ) ) ? $all['filter_shortcuts'] : array();

		$out = array();
		foreach ( $roh as $chip ) {
			if ( ! is_array( $chip ) ) {
				continue;
			}
			$out[] = array(
				'label' => (string) ( $chip['label'] ?? '' ),
				'url'   => (string) ( $chip['url'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Was der Seiten-Cache mit dieser Seite macht – und ein Knopf zum Leeren.
	 *
	 * WARUM DAS HIER STEHT: Ein Seiten-Cache ist der größte Hebel für die
	 * Ladezeit, aber er liefert HTML aus, das irgendwann gebaut wurde. Ob das
	 * Plugin ihn nach einem Import überhaupt erreichen kann, sieht man sonst
	 * nirgends – und ein Cache, der die alte Trefferzahl ausliefert, ist
	 * schlimmer als kein Cache.
	 */
	private static function render_cache_kasten() {
		if ( ! class_exists( 'BI_Cache' ) ) {
			return;
		}
		$namen   = BI_Cache::namen();
		$zuletzt = BI_Cache::zuletzt();
		?>
		<h2 class="title">Seiten-Cache</h2>
		<?php if ( $namen ) : ?>
			<p><span class="dashicons dashicons-yes" style="color:#00a32a"></span>
				Erkannt: <strong><?php echo esc_html( implode( ', ', $namen ) ); ?></strong>.
				Das Plugin leert ihn selbst, sobald sich Seminare ändern – nach einem Import, nach dem
				Speichern eines Seminars, nach einer Massenbearbeitung. Dazu einmal kurz nach Mitternacht:
				<em>buchbar</em> heißt „Startdatum ab heute", und das wandert mit dem Datum weiter.</p>
			<?php if ( $zuletzt ) : ?>
				<p class="description">Zuletzt geleert:
					<?php echo esc_html( mysql2date( 'j. F Y, H:i', $zuletzt ) ); ?> Uhr.</p>
			<?php endif; ?>
		<?php else : ?>
			<p><span class="dashicons dashicons-info-outline"></span>
				<strong>Kein Seiten-Cache erkannt.</strong> Die Seiten werden bei jedem Aufruf neu gebaut.
				Ein Cache-Plugin ist der wirksamste einzelne Schritt für die Ladezeit – wichtig ist nur,
				dass Adressen <strong>mit Parametern</strong> (<code>?q=</code>, <code>?ort=</code> …)
				vom Caching ausgenommen bleiben. Sonst bekämen alle Besucher dieselbe Trefferliste,
				egal was sie filtern.</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_cache_leeren">
			<?php wp_nonce_field( 'bi_cache_leeren' ); ?>
			<?php submit_button( 'Cache jetzt leeren', 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public static function handle_cache_leeren() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_cache_leeren' );

		// true = an der Sperrfrist vorbei: Wer den Knopf drückt, will jetzt
		// leeren und nicht in einer Minute.
		$ok  = class_exists( 'BI_Cache' ) ? BI_Cache::leeren( true ) : false;
		$msg = $ok
			? 'Der Seiten-Cache wurde geleert.'
			: 'Es ist kein Seiten-Cache eingerichtet, den das Plugin leeren könnte.';

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-einstellungen', 'tab' => 'suche', 'bi_msg' => rawurlencode( $msg ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Tab „Einbettung (iframe)“.
	 *
	 * Eine Liste von Domains – mehr braucht der Einbettungsmodus an
	 * Einstellung nicht. Wichtig ist vor allem, dass sie hier steht und nicht
	 * in einer PHP-Datei: Ein Tippfehler in der functions.php legt die ganze
	 * Website samt Backend lahm, ein Tippfehler in diesem Feld wird beim
	 * Speichern verworfen und gemeldet.
	 */
	private static function render_embed_section( $s ) {
		$gespeichert = (string) ( $s['embed_hosts'] ?? '' );
		$wirksam     = class_exists( 'BI_Embed' ) ? BI_Embed::frame_ancestors_vorschau() : array();
		$beispiel    = class_exists( 'BI_Registration' ) ? BI_Registration::uebersicht_url() : home_url( '/seminare/' );
		$beispiel    = add_query_arg( 'bi_embed', '1', $beispiel );
		?>
		<p>Damit lässt sich die <strong>Seminarsuche samt Anmeldung auf einer anderen Website</strong>
			in einem Rahmen (<code>iframe</code>) zeigen – ohne Kopf- und Fußbereich von
			<?php echo esc_html( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?>,
			und zwar über alle Klicks hinweg: Trefferliste, Seminardetails, Anmeldung.</p>

		<h2 class="title">Adresse zum Einbetten</h2>
		<p>Diese Adresse trägt die andere Website in ihr Einbettungsfeld ein:</p>
		<p><input type="text" class="large-text code" readonly onclick="this.select()"
		          value="<?php echo esc_attr( $beispiel ); ?>"></p>
		<p class="description">Der Anhang <code>?bi_embed=1</code> ist der Schalter. Ohne ihn kommt die
			Seite mit dem gewohnten Rahmen. Zusätzlich möglich:
			<code>&amp;bi_pro_seite=10</code> für eine kürzere Trefferliste, und jeder Filter,
			etwa <code>&amp;thema=digitalisierung</code>. Die Trefferzahl kann die Besucherin
			auch selbst über der Liste umstellen; im Rahmen bleibt hier jede Zahl zwischen
			3 und 50 erlaubt, damit bestehende Einbettungen unverändert weiterlaufen.</p>

		<h2 class="title">Wer einbetten darf</h2>

		<div class="notice notice-warning inline" style="margin:16px 0;padding:8px 12px">
			<p style="margin:.4em 0"><strong>Ohne Eintrag zeigt der Rahmen auf einer fremden Website nichts.</strong>
				Browser lassen das Einbetten nur zu, wenn diese Website die fremde Herkunft
				ausdrücklich benennt. Das ist kein Schikane-Schutz, sondern verhindert, dass
				jemand die Anmeldemaske unter eigenem Namen zeigt.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_save_settings">
			<input type="hidden" name="bi_tab" value="einbettung">
			<?php wp_nonce_field( 'bi_save_settings' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="bi_embed_hosts">Erlaubte Websites</label></th>
					<td>
						<textarea id="bi_embed_hosts" name="embed_hosts" rows="5" class="large-text code"
						          placeholder="https://cms.igmetall.cloud"><?php echo esc_textarea( $gespeichert ); ?></textarea>
						<p class="description">
							Eine Adresse je Zeile, mit <code>https://</code>, ohne Pfad und ohne
							Schrägstrich am Ende. <code>https://*.igmetall.cloud</code> erlaubt alle
							Unterdomains. Fehlt <code>https://</code>, wird es ergänzt; alles andere
							Ungültige wird beim Speichern verworfen und hier gemeldet.
						</p>
						<p class="description">
							<strong>Nicht nötig:</strong> diese Website selbst und alles unterhalb von
							<code>igmetall.de</code> – beides ist fest voreingestellt.
						</p>
					</td>
				</tr>
				<tr>
					<th>Wirkt derzeit</th>
					<td>
						<code style="display:inline-block;padding:6px 8px;background:#f6f7f7">
							frame-ancestors <?php echo esc_html( implode( ' ', $wirksam ) ); ?>
						</code>
						<p class="description">Diese Zeile schickt die Website mit jeder Seite im
							Einbettungsmodus. Steht die gesuchte Domain nicht darin, ist der Rahmen leer.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Speichern' ); ?>
		</form>

		<h2 class="title">Wenn der Rahmen trotzdem leer bleibt</h2>
		<p>Dann setzt vermutlich ein Sicherheits-Plugin oder der Webserver die Kopfzeile
			<code>X-Frame-Options</code>. Das Plugin entfernt sie, solange sie aus PHP kommt –
			stammt sie aus der <code>.htaccess</code> oder der nginx-Konfiguration, muss sie dort
			entfallen. Prüfen lässt sich das in den Entwicklerwerkzeugen des Browsers unter
			„Netzwerk“ an den Antwort-Kopfzeilen der eingebetteten Seite.</p>

		<p class="description">Die Höhe des Rahmens kann diese Website nicht bestimmen – das darf
			nur die einbettende Seite. Wie sie das tut, steht in der Anleitung
			<em>„Seminarsuche und Anmeldung per iframe einbetten“</em>.</p>
		<?php
	}

	private static function render_pdf_section( $s ) {
		$logo_id  = (int) ( $s['pdf_logo_id'] ?? 0 );
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$sample   = class_exists( 'BI_PDF' ) ? BI_PDF::sample_seminar_id() : 0;
		?>
		<p>Die Anmeldemails können zwei Dateien mitschicken: die <strong>Seminardetails</strong> als PDF und die
			<strong>Beschlussvorlage</strong> („Mitteilung über Seminarteilnahme nach § 37 Abs. 6 BetrVG") als
			<strong>Word-Datei</strong> – die muss der Betriebsrat noch ergänzen können.
			Ob eine Benachrichtigung sie anhängt, stellst du in der jeweiligen
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-mail-trigger' ) ); ?>">Benachrichtigung</a> ein.
			Hier stehen die Angaben, die in beiden Dokumenten gleich sind.</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_save_settings">
			<input type="hidden" name="bi_tab" value="pdf">
			<?php wp_nonce_field( 'bi_save_settings' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="bi_pdf_logo">Logo (oben rechts)</label></th>
					<td>
						<input type="hidden" id="bi_pdf_logo" name="pdf_logo_id" value="<?php echo esc_attr( $logo_id ); ?>">
						<div id="bi-pdf-logo-preview" style="margin-bottom:8px">
							<img src="<?php echo esc_url( $logo_url ); ?>" alt=""
								style="max-width:220px;height:auto;border:1px solid #dcdcde;padding:6px;background:#fff;<?php echo $logo_url ? '' : 'display:none'; ?>">
						</div>
						<button type="button" class="button" id="bi-pdf-logo-pick">Logo auswählen</button>
						<button type="button" class="button-link" id="bi-pdf-logo-clear" style="margin-left:10px;color:#b32d2e;<?php echo $logo_id ? '' : 'display:none'; ?>">entfernen</button>
						<p class="description">Erscheint <strong>oben rechts auf den Seminardetails</strong>.
							Ohne eigene Auswahl steht dort das <strong>mitgelieferte IG-Metall-Signet</strong>.
							Möglich sind <strong>JPG, PNG und GIF</strong> – SVG kann nicht in ein PDF eingebettet werden.
							Empfehlung: PNG mit mindestens 600&nbsp;px Breite. Das Logo wird in ein Feld von
							32&nbsp;×&nbsp;15&nbsp;mm eingepasst – ein Schriftzug schöpft die Breite aus, ein
							quadratisches Zeichen die Höhe.<br>
							Die <strong>Beschlussvorlage bekommt bewusst kein Logo</strong>: Sie ist ein Schreiben des Betriebsrats
							an den Arbeitgeber, ein Verbandslogo hätte darauf nichts zu suchen.</p>
					</td>
				</tr>
				<tr>
					<th><label for="bi_pdf_veranstalter">Veranstalter</label></th>
					<td>
						<textarea id="bi_pdf_veranstalter" name="pdf_veranstalter" rows="4" class="large-text" placeholder="IG Metall&#10;Straße Hausnummer&#10;PLZ Ort"><?php echo esc_textarea( $s['pdf_veranstalter'] ?? '' ); ?></textarea>
						<p class="description">Name und Anschrift des Veranstalters, eine Angabe je Zeile.
							Füllt in der Beschlussvorlage die Stelle „<em>Das Seminar wird von … veranstaltet</em>" –
							das ist eine der Angaben, auf die es beim Nachweis nach § 37 Abs. 6 BetrVG ankommt.
							Bleibt das Feld leer, steht dort <code>IG Metall</code> ohne Anschrift.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Einstellungen speichern' ); ?>
		</form>

		<h2 class="title">Vorschau</h2>
		<?php if ( $sample ) : ?>
			<p>Beide Dokumente mit dem nächsten anstehenden Seminar
				(<strong><?php echo esc_html( get_the_title( $sample ) ); ?></strong>) ansehen.
				Die Beschlussvorlage nutzt dabei die Beispieldaten aus der Vorlage
				(Erika Mustermann, H.H. Normal GmbH).</p>
			<p>
				<a class="button" target="_blank" rel="noopener"
					href="<?php echo esc_url( BI_PDF::preview_url( 'seminar', $sample ) ); ?>">Seminardetails ansehen</a>
				<a class="button"
					href="<?php echo esc_url( BI_PDF::preview_url( 'beschluss', $sample ) ); ?>">Beschlussvorlage herunterladen (Word)</a>
			</p>
			<p class="description">Änderungen an Logo und Veranstalter erst speichern, dann die Vorschau öffnen.</p>
		<?php else : ?>
			<p class="description">Für die Vorschau wird mindestens ein Seminar mit Startdatum ab heute benötigt.</p>
		<?php endif; ?>

		<script>
		(function () {
			var pick = document.getElementById('bi-pdf-logo-pick');
			var clear = document.getElementById('bi-pdf-logo-clear');
			var field = document.getElementById('bi_pdf_logo');
			var img = document.querySelector('#bi-pdf-logo-preview img');
			if (!pick || !window.wp || !wp.media) return;
			var frame;

			pick.addEventListener('click', function () {
				if (!frame) {
					frame = wp.media({ title: 'Logo für die PDF-Anhänge', library: { type: 'image' }, multiple: false });
					frame.on('select', function () {
						var att = frame.state().get('selection').first().toJSON();
						field.value = att.id;
						img.src = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
						img.style.display = '';
						clear.style.display = '';
					});
				}
				frame.open();
			});

			clear.addEventListener('click', function () {
				field.value = 0;
				img.src = '';
				img.style.display = 'none';
				clear.style.display = 'none';
			});
		})();
		</script>
		<?php
	}

	/* ===================================================================
	 *  Datenschutz-Textbaustein
	 * =================================================================== */

	/**
	 * Vorschlagstext für die Datenschutzerklärung, als Abschnitte.
	 * Bewusst mit eckigen Klammern als Lücken dort, wo nur die Betreiberin
	 * die Antwort kennt (Aufbewahrungsfristen, Name des Consent-Werkzeugs).
	 *
	 * @return array [ ['title' => string, 'paragraphs' => string[]], … ]
	 */
	/**
	 * Satz zur Aufbewahrung – spiegelt die tatsächlich eingestellte Frist wider,
	 * damit Textbaustein und Verhalten des Plugins nicht auseinanderlaufen.
	 */
	private static function retention_paragraph() {
		$days = BI_Retention::days();
		if ( ! $days ) {
			return 'Wir speichern die Anmeldedaten [Aufbewahrungsdauer ergänzen – im Plugin ist derzeit '
				. 'keine automatische Löschung eingestellt] und löschen sie anschließend.';
		}
		$verb = ( 'anonymize' === BI_Retention::mode() )
			? 'anonymisiert, sodass sie sich anschließend keiner Person mehr zuordnen lassen'
			: 'gelöscht';
		return sprintf(
			'Die Anmeldedaten werden %d Tage nach Ende des Seminars automatisch %s. '
				. 'Bestehen im Einzelfall längere gesetzliche Aufbewahrungspflichten, etwa aus Steuer- oder '
				. 'Förderrecht, gehen diese vor [ggf. ergänzen].',
			$days,
			$verb
		);
	}

	public static function privacy_sections() {
		$cookie = BI_Tracking::COOKIE;
		$tage   = BI_Tracking::COOKIE_DAYS;
		$param  = BI_Tracking::PARAM;

		return array(
			array(
				'title'      => 'Anmeldung zu Seminaren',
				'paragraphs' => array(
					'Über das Anmeldeformular für Seminare verarbeiten wir die von dir eingegebenen Daten: '
						. 'Anrede, Titel, Vor- und Nachname, private Anschrift, Telefon- und Mobilnummer, E-Mail-Adresse, '
						. 'Angabe zur Mitgliedschaft in der IG Metall einschließlich Mitgliedsnummer, Betrieb mit Anschrift '
						. 'und dienstlicher E-Mail-Adresse, '
						. 'Funktion im Betriebsrat, Art der Freistellung sowie deine freiwilligen Bemerkungen.',
					'Zweck der Verarbeitung ist die Bearbeitung deiner Anmeldung und die Durchführung des Seminars. '
						. 'Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Durchführung vorvertraglicher Maßnahmen und des '
						. 'Vertragsverhältnisses), für freiwillige Angaben Art. 6 Abs. 1 lit. a DSGVO.',
					'Die Anmeldung wird in der Datenbank dieser Website gespeichert und zusätzlich per E-Mail an die '
						. 'zuständigen Stellen übermittelt: an die anhand der Postleitzahl deines Betriebs ermittelte '
						. 'Geschäftsstelle sowie – soweit für das Seminar eingerichtet – an das durchführende '
						. 'Bildungszentrum und an die hinterlegte Ansprechperson. Eine Bestätigung geht an die von dir angegebene E-Mail-Adresse. Eine Übermittlung '
						. 'in Drittstaaten findet nicht statt.',
					self::retention_paragraph(),
				),
			),
			array(
				'title'      => 'Anfrage an die Geschäftsstelle',
				'paragraphs' => array(
					'Bei Seminaren, deren Anmeldung über die Geschäftsstelle läuft, kannst du auf der Seminarseite die '
						. 'Postleitzahl deines Arbeitsortes eingeben. Sie wird an diese Website übermittelt, dort mit '
						. 'unserem Verzeichnis der Geschäftsstellen abgeglichen und nicht gespeichert. Zurück kommen Name '
						. 'und E-Mail-Adresse der zuständigen Geschäftsstelle. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b '
						. 'DSGVO (Durchführung vorvertraglicher Maßnahmen).',
					'Damit du dieselbe Postleitzahl beim nächsten Besuch nicht erneut eingeben musst, wird sie zusammen '
						. 'mit der gefundenen Geschäftsstelle im lokalen Speicher deines Browsers abgelegt '
						. '(localStorage-Eintrag „biGsLookup"). Diese Angaben verlassen deinen Browser nicht; du kannst '
						. 'sie jederzeit löschen, indem du die Websitedaten in den Einstellungen deines Browsers '
						. 'entfernst.',
					'Vor dem Absenden fragen wir in einem Fenster nach deinen Kontaktdaten: Vorname, Name, '
						. 'E-Mail-Adresse und – freiwillig – Mobilnummer, Anschrift, Mitgliedsnummer und Betrieb. '
						. 'Diese Angaben werden NICHT an diese Website übertragen und hier auch nicht gespeichert. Sie '
						. 'werden ausschließlich in deinem Browser in den Text einer E-Mail eingesetzt, die sich '
						. 'anschließend in deinem eigenen E-Mail-Programm öffnet. Ob und an wen du diese E-Mail '
						. 'abschickst, entscheidest allein du; die weitere Verarbeitung liegt dann bei der '
						. 'empfangenden Geschäftsstelle.',
				),
			),
			array(
				'title'      => 'Reichweitenmessung bei Newsletter- und Mailing-Links',
				'paragraphs' => array(
					'Links, mit denen wir in Newslettern, Mailings oder Anzeigen auf unser Seminarangebot hinweisen, können '
						. 'eine Kampagnenkennung enthalten (Adressen der Form https://…/?' . $param . '=…). Rufst du einen solchen '
						. 'Link auf, speichern wir in deinem Browser das Cookie „' . $cookie . '". Es enthält eine zufällig '
						. 'erzeugte Kennung und die Nummer der Kampagne, wird ausschließlich von dieser Website gesetzt '
						. '(First-Party-Cookie) und läuft nach ' . $tage . ' Tagen ab.',
					'Anhand dieser Kennung halten wir fest, welche Schritte auf den Klick folgen: der Aufruf des Links, das '
						. 'Ansehen einer Seminarseite, das Öffnen des Anmeldeformulars und das Absenden einer Anmeldung – '
						. 'jeweils mit Zeitpunkt und betroffenem Seminar. Weder deine IP-Adresse noch Angaben zu Browser oder '
						. 'Gerät werden dabei gespeichert. Die Kennung ist eine Zufallsfolge ohne Bezug zu deiner Person. '
						. 'Erst wenn du eine Anmeldung absendest, wird bei dieser Anmeldung zusätzlich die Bezeichnung der '
						. 'Kampagne vermerkt.',
					'Zweck ist die Reichweitenmessung: Wir möchten auswerten, wie viele Anmeldungen auf ein bestimmtes '
						. 'Mailing zurückgehen, um unsere Informationsangebote gezielter zu gestalten. Das Speichern des '
						. 'Cookies und das Auslesen der darin enthaltenen Information erfolgen auf Grundlage deiner '
						. 'Einwilligung nach § 25 Abs. 1 TDDDG, die anschließende Verarbeitung der Daten auf Grundlage von '
						. 'Art. 6 Abs. 1 lit. a DSGVO.',
					'Du kannst deine Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen [Hinweis auf das '
						. 'Einwilligungs-Werkzeug ergänzen, z. B. „über die Cookie-Einstellungen am Seitenende"] und das '
						. 'Cookie jederzeit in den Einstellungen deines Browsers löschen. Ohne das Cookie findet keine '
						. 'Zuordnung statt; die Nutzung unseres Seminarangebots ist davon nicht berührt.',
					'Die erfassten Ereignisse werden automatisch gelöscht, sobald sie älter als zwölf Monate sind. Die Angabe, über welche Kampagne '
						. 'eine Anmeldung zustande kam, wird gemeinsam mit der Anmeldung gelöscht.',
				),
			),
		);
	}

	/** Vorschlagstext als reiner Text (zum Kopieren) */
	public static function privacy_text() {
		$out = array();
		foreach ( self::privacy_sections() as $s ) {
			$out[] = $s['title'];
			$out[] = str_repeat( '-', mb_strlen( $s['title'] ) );
			$out[] = '';
			foreach ( $s['paragraphs'] as $p ) {
				$out[] = $p;
				$out[] = '';
			}
		}
		return trim( implode( "\n", $out ) ) . "\n";
	}

	/** Vorschlagstext als HTML (für den WordPress-Datenschutz-Leitfaden) */
	public static function privacy_html() {
		$html = '';
		foreach ( self::privacy_sections() as $s ) {
			$html .= '<h3>' . esc_html( $s['title'] ) . '</h3>';
			foreach ( $s['paragraphs'] as $p ) {
				$html .= '<p class="privacy-policy-tutorial">' . esc_html( $p ) . '</p>';
			}
		}
		return $html;
	}

	public static function register_privacy_policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Bildungsprogramm', self::privacy_html() );
		}
	}

	private static function render_privacy_section() {
		$text = self::privacy_text();
		?>
		<h2 class="title">Textbaustein für die Datenschutzerklärung</h2>
		<p>Dieser Vorschlag beschreibt, was das Plugin tatsächlich verarbeitet: die Daten aus dem
			Anmeldeformular und die Reichweitenmessung der Kampagnen-Links. Die Stellen in
			<code>[eckigen Klammern]</code> musst du selbst füllen – nur du kennst eure Aufbewahrungsfristen
			und euer Einwilligungs-Werkzeug.</p>

		<div class="notice notice-warning inline" style="margin:12px 0;padding:10px 14px">
			<p style="margin:0"><strong>Keine Rechtsberatung.</strong> Der Text ist ein Entwurf zum Weiterreichen an
				die Stelle, die bei euch für Datenschutz zuständig ist – ungeprüft übernehmen solltest du ihn nicht.
				Besonders zu klären: Das Cookie der Reichweitenmessung ist technisch nicht zwingend erforderlich.
				Ohne Einwilligung (Consent-Banner) fehlt ihm nach § 25 Abs. 1 TDDDG die Grundlage – der Text ist
				entsprechend auf Einwilligung formuliert. Wird kein Banner eingesetzt, ist entweder eines nötig oder
				die Kampagnen-Auswertung bleibt ungenutzt.</p>
		</div>

		<p>
			<button type="button" class="button button-primary" id="bi-copy-privacy">In die Zwischenablage kopieren</button>
			<span id="bi-copy-done" style="display:none;color:#1a7f37;margin-left:8px">kopiert ✓</span>
		</p>

		<textarea id="bi-privacy-text" class="large-text code" rows="26" readonly onclick="this.select()"><?php echo esc_textarea( $text ); ?></textarea>

		<script>
		document.getElementById('bi-copy-privacy').addEventListener('click', function () {
			var ta = document.getElementById('bi-privacy-text');
			ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) {}
			if (!ok && navigator.clipboard) { navigator.clipboard.writeText(ta.value); ok = true; }
			if (ok) {
				var hint = document.getElementById('bi-copy-done');
				hint.style.display = 'inline';
				setTimeout(function () { hint.style.display = 'none'; }, 2000);
			}
		});
		</script>

		<p class="description" style="margin-top:10px">Derselbe Text steht auch im WordPress-eigenen
			Richtlinien-Leitfaden unter <em>Werkzeuge → Datenschutz</em> bereit.</p>

		<h2 class="title">Was das Plugin technisch speichert</h2>
		<table class="widefat striped" style="max-width:940px">
			<thead><tr><th style="width:280px">Wo</th><th>Was</th></tr></thead>
			<tbody>
				<tr><td><code><?php echo esc_html( BI_Registration::table() ); ?></code></td>
					<td>Alle Angaben aus dem Anmeldeformular, Zeitpunkt, Seminar-Bezug und – falls vorhanden –
						die Kampagne, über die die Anmeldung zustande kam.</td></tr>
				<tr><td><code><?php echo esc_html( BI_Tracking::table_events() ); ?></code></td>
					<td>Ereignisse der Kampagnen-Auswertung: Zufallskennung, Art des Schritts, Seminar, Zeitpunkt.
						Keine IP-Adresse, kein User-Agent.</td></tr>
				<tr><td><code><?php echo esc_html( BI_Tracking::table_kampagnen() ); ?></code></td>
					<td>Die von euch angelegten Kampagnen (Bezeichnung, Kürzel, Ziel) – keine Personendaten.</td></tr>
				<tr><td>Cookie <code><?php echo esc_html( BI_Tracking::COOKIE ); ?></code></td>
					<td>Zufallskennung + Kampagnennummer, <?php echo (int) BI_Tracking::COOKIE_DAYS; ?> Tage Laufzeit,
						First-Party, nur gesetzt nach Klick auf einen Kampagnen-Link.</td></tr>
				<tr><td><code><?php echo esc_html( BI_PLZ::table() ); ?></code></td>
					<td>Zuordnung Postleitzahl → Geschäftsstelle samt E-Mail. Stammdaten der Organisation,
						keine Daten der Anmeldenden.</td></tr>
			</tbody>
		</table>
		<p class="description">Ereignisse älter als zwölf Monate lassen sich unter <em>Kampagnen</em> löschen.</p>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_settings' );

		// Jeder Tab schickt nur seine eigenen Felder – deshalb auf den gespeicherten
		// Werten aufsetzen, sonst würde das Speichern im einen Tab den anderen leeren.
		$out    = self::all();
		$tab    = isset( $_POST['bi_tab'] ) ? sanitize_key( wp_unslash( $_POST['bi_tab'] ) ) : 'allgemein';
		$msg    = 'Einstellungen gespeichert.';
		$anchor = '';

		// Wird die Ampel in diesem Speichern abgeschaltet, sind die Haken
		// „Ausgebucht", die sie gesetzt hat, danach herrenlos: Ohne Abgleich
		// nimmt sie niemand mehr zurück. Sie werden deshalb freigegeben – aber
		// erst, nachdem die Einstellung wirklich gespeichert ist.
		$ampel_abgeschaltet = false;

		if ( 'suche' === $tab ) {
			// Nur bekannte Facettennamen übernehmen und in der Reihenfolge von
			// facets() ablegen: Was aus dem Formular kommt, bestimmt das Ob,
			// nicht das Wo. Kein Haken = leeres Array, nicht „alle" – sonst
			// ließe sich der letzte Filter nie abschalten.
			$erlaubt = class_exists( 'BI_Filter' ) ? array_keys( BI_Filter::facets() ) : array();
			$gewahlt = ( isset( $_POST['filter_facets'] ) && is_array( $_POST['filter_facets'] ) )
				? array_map( 'sanitize_key', wp_unslash( $_POST['filter_facets'] ) )
				: array();

			$out['filter_facets'] = array_values( array_intersect( $erlaubt, $gewahlt ) );

			// Eigene Chips. Wie bei den Regeln bleibt der Schlüssel die
			// Zeilennummer aus dem Formular – nur so trifft ein Pfeil auch dann
			// die gemeinte Zeile, wenn im selben Zug eine andere gelöscht wurde.
			$chips    = array();
			$in_chips = ( isset( $_POST['shortcut'] ) && is_array( $_POST['shortcut'] ) ) ? wp_unslash( $_POST['shortcut'] ) : array();
			$verworfen = 0;
			foreach ( $in_chips as $idx => $c ) {
				if ( ! empty( $c['delete'] ) ) {
					continue;
				}
				$label = sanitize_text_field( (string) ( $c['label'] ?? '' ) );
				$url   = trim( (string) ( $c['url'] ?? '' ) );
				if ( '' === $label && '' === $url ) {
					continue; // unberührte Leerzeile
				}
				// Ein Chip ohne Namen oder ohne erkennbaren Filter hätte im
				// Frontend nichts zu tun – lieber jetzt melden als später
				// suchen lassen.
				$params = class_exists( 'BI_Filter' ) ? BI_Filter::url_params( $url ) : array();
				if ( '' === $label || ! $params ) {
					$verworfen++;
					continue;
				}
				// Gespeichert wird die Adresse, wie sie eingegeben wurde: Sie ist
				// das, was die Redaktion beim nächsten Öffnen wiedererkennt.
				// Bewusst NICHT durch esc_url_raw: Erlaubt ist auch die reine
				// Parameterliste (form=online&thema=…), und esc_url_raw machte
				// daraus eine „Adresse" mit vorangestelltem http://, die niemand
				// mehr auswerten kann. Gefährlich ist das nicht – die Angabe wird
				// nie zu einem Link, sondern nur nach Filterparametern
				// durchsucht (BI_Filter::url_params).
				$chips[ (int) $idx ] = array(
					'label' => $label,
					'url'   => sanitize_text_field( $url ),
				);
			}

			$move                    = isset( $_POST['shortcut_move'] ) ? sanitize_text_field( wp_unslash( $_POST['shortcut_move'] ) ) : '';
			$out['filter_shortcuts'] = self::move_rule( $chips, $move );
			if ( '' !== $move ) {
				// Nach einem Pfeilklick zurück zur Chip-Tabelle springen – sonst
				// landet man oben auf der Seite und sucht die Zeile, die man
				// gerade verschoben hat.
				$msg    = 'Reihenfolge geändert und gespeichert.';
				$anchor = '#bi-chips';
			}
			if ( $verworfen ) {
				$msg .= sprintf(
					' %d Chip-Zeile(n) wurden nicht übernommen: Es fehlt der Name oder die Adresse enthält keinen bekannten Filter.',
					$verworfen
				);
			}

			// Die Leiste steht als fertiges HTML in der Seite (die Konfiguration
			// hängt als data-Attribut daran). Ein Seiten-Cache zeigte sonst
			// weiter die alten Chips – und niemand käme auf die Idee, dass die
			// eigene Einstellung schon gespeichert ist.
			if ( class_exists( 'BI_Cache' ) ) {
				BI_Cache::leeren( true );
			}
		} elseif ( 'pdf' === $tab ) {
			$out['pdf_logo_id']      = intval( $_POST['pdf_logo_id'] ?? 0 );
			$out['pdf_veranstalter'] = sanitize_textarea_field( wp_unslash( $_POST['pdf_veranstalter'] ?? '' ) );
		} elseif ( 'verfuegbarkeit' === $tab ) {
			$ampel_vorher           = ! empty( $out['ampel_aktiv'] );
			$out['ampel_aktiv']     = empty( $_POST['ampel_aktiv'] ) ? 0 : 1;
			$ampel_abgeschaltet     = ( $ampel_vorher && ! $out['ampel_aktiv'] );
			$out['ampel_intervall'] = min( 168, max( 1, intval( bi_post( 'ampel_intervall' ) ) ) );
			// Die Anzeigefrist muss über dem Abstand der Läufe liegen, sonst fällt die
			// Ampel zwischen zwei Abgleichen regelmäßig aus. Zu kleine Eingaben werden
			// deshalb stillschweigend auf den doppelten Abstand angehoben.
			$out['ampel_max_alter'] = min( 720, max( 1, intval( bi_post( 'ampel_max_alter' ) ) ) );
			if ( $out['ampel_max_alter'] <= $out['ampel_intervall'] ) {
				$out['ampel_max_alter'] = min( 720, $out['ampel_intervall'] * 2 );
			}
			$out['ampel_kontakt']   = sanitize_text_field( bi_post( 'ampel_kontakt' ) );
		} elseif ( 'einbettung' === $tab ) {
			// Zeilenweise durch dieselbe Prüfung schicken, die auch beim
			// Ausliefern gilt. Was hier stehen bleibt, wirkt also genau so –
			// keine Einstellung, die gespeichert aussieht und nichts tut.
			$zeilen = preg_split( '/\R/', (string) wp_unslash( $_POST['embed_hosts'] ?? '' ) );
			$gut    = array();
			$schrott = array();
			foreach ( $zeilen as $zeile ) {
				if ( '' === trim( (string) $zeile ) ) {
					continue;
				}
				$host = class_exists( 'BI_Embed' ) ? BI_Embed::host_normalisieren( $zeile ) : '';
				if ( '' !== $host ) {
					$gut[] = $host;
				} else {
					$schrott[] = trim( (string) $zeile );
				}
			}
			$out['embed_hosts'] = implode( "\n", array_values( array_unique( $gut ) ) );
			if ( $schrott ) {
				$msg = sprintf(
					'Gespeichert. Nicht übernommen wurde: %s – erwartet wird eine Adresse wie https://cms.igmetall.cloud',
					implode( ', ', array_slice( $schrott, 0, 3 ) )
				);
			}
			$anchor = '';
		} elseif ( 'datenschutz' === $tab ) {
			// Obergrenze 3650 Tage: schuetzt vor Tippfehlern, die die Frist praktisch aufheben.
			$out['retention_days'] = min( 3650, max( 0, intval( bi_post( 'retention_days' ) ) ) );
			$out['retention_mode'] = ( 'anonymize' === bi_post( 'retention_mode' ) ) ? 'anonymize' : 'delete';
		} else {
			$out['anmeldung_page_id']  = intval( $_POST['anmeldung_page_id'] ?? 0 );
			$out['uebersicht_page_id'] = intval( $_POST['uebersicht_page_id'] ?? 0 );
			$out['direct_label']       = sanitize_text_field( wp_unslash( $_POST['direct_label'] ?? '' ) );
			$out['gs_label']           = sanitize_text_field( wp_unslash( $_POST['gs_label'] ?? '' ) );
			$out['gs_hinweis']         = sanitize_text_field( wp_unslash( $_POST['gs_hinweis'] ?? '' ) );
			$out['keine_label']        = sanitize_text_field( wp_unslash( $_POST['keine_label'] ?? '' ) );
			$out['keine_hinweis']      = sanitize_text_field( wp_unslash( $_POST['keine_hinweis'] ?? '' ) );

			// Freistellungsliste: eine Angabe je Zeile. Leer ist ein zulässiger
			// Wert („keine Reihe am Stück buchbar") und wird deshalb NICHT auf den
			// Standard zurückgesetzt – anders als die Textfelder darunter.
			$out['sammel_freistellung'] = implode( "\n", array_values( array_filter( array_map(
				'sanitize_text_field',
				preg_split( '/\R/', (string) wp_unslash( $_POST['sammel_freistellung'] ?? '' ) )
			), function ( $z ) {
				return '' !== trim( $z );
			} ) ) );

			// Leere Texte auf Default zurücksetzen
			$def = self::defaults();
			foreach ( array( 'direct_label', 'gs_label', 'gs_hinweis', 'keine_label', 'keine_hinweis' ) as $k ) {
				if ( '' === $out[ $k ] ) {
					$out[ $k ] = $def[ $k ];
				}
			}

			// Regeln einsammeln (Reihenfolge bleibt erhalten = Prüfreihenfolge).
			// Schlüssel bleibt die Zeilennummer aus dem Formular, damit ein Pfeil
			// auch dann die gemeinte Regel trifft, wenn im selben Durchgang eine
			// andere Zeile gelöscht oder ergänzt wurde.
			$rules        = array();
			$valid_fields = array_keys( self::rule_fields() );
			$in_rules     = ( isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ) ? wp_unslash( $_POST['rule'] ) : array();
			foreach ( $in_rules as $idx => $r ) {
				if ( ! empty( $r['delete'] ) ) {
					continue;
				}
				$field = $r['field'] ?? '';
				$value = trim( (string) ( $r['value'] ?? '' ) );
				if ( ! in_array( $field, $valid_fields, true ) || '' === $value ) {
					continue; // unvollständige/Leerzeile verwerfen
				}
				$rules[ (int) $idx ] = array(
					'field'   => $field,
					'value'   => sanitize_text_field( $value ),
					'variant' => in_array( (string) ( $r['variant'] ?? '' ), self::variant_keys(), true )
						? (string) $r['variant']
						: 'direct',
				);
			}

			$move         = isset( $_POST['rule_move'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_move'] ) ) : '';
			$out['rules'] = self::move_rule( $rules, $move );
			if ( '' !== $move ) {
				$msg    = 'Reihenfolge geändert und gespeichert.';
				$anchor = '#bi-regeln';
			}
		}

		update_option( self::OPTION, $out );

		if ( $ampel_abgeschaltet && class_exists( 'BI_Ampel' ) ) {
			$frei = BI_Ampel::ausgebucht_freigeben();
			$msg .= $frei
				? sprintf( ' Die Ampel ist abgeschaltet; %s Seminar(e), die sie auf ausgebucht gesetzt hatte, sind wieder freigegeben.', number_format_i18n( $frei ) )
				: ' Die Ampel ist abgeschaltet; kein Seminar stand wegen der Ampel auf ausgebucht.';
		}

		$args = array( 'page' => 'bi-einstellungen', 'bi_msg' => rawurlencode( $msg ) );
		if ( 'allgemein' !== $tab ) {
			$args['tab'] = $tab; // im selben Tab bleiben
		}
		// Nach einem Pfeilklick zurück zur Regeltabelle springen – sonst landet
		// man oben auf der Seite und sucht die Zeile, die man gerade verschoben hat.
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) . $anchor );
		exit;
	}

	/**
	 * Verschiebt eine Zeile um einen Platz nach oben oder unten.
	 *
	 * Die Schlüssel von $rules sind die Zeilennummern aus dem Formular, der
	 * Pfeil nennt dieselbe Nummer. Beides zusammen trifft die richtige Zeile
	 * auch dann, wenn im selben Absenden eine Zeile gelöscht wurde und sich die
	 * Positionen verschoben haben.
	 *
	 * Der Name stammt von den Anmelderegeln, für die es geschrieben wurde; die
	 * eigenen Chips der Filterleiste nutzen dieselbe Mechanik, weil sie dieselbe
	 * Bedienung haben sollen. Was verschoben wird, ist der Funktion gleich.
	 *
	 * @param array  $rules key = Formularzeile, Wert = Regel bzw. Chip
	 * @param string $move  'up-3' | 'down-3' | ''
	 * @return array neu durchnummerierte Liste in Anzeigereihenfolge
	 */
	private static function move_rule( $rules, $move ) {
		$liste = array_values( $rules );
		if ( ! preg_match( '/^(up|down)-(\d+)$/', (string) $move, $m ) ) {
			return $liste;
		}
		$pos = array_search( (int) $m[2], array_keys( $rules ), true );
		if ( false === $pos ) {
			return $liste; // die gemeinte Zeile wurde im selben Zug gelöscht
		}
		$ziel = ( 'up' === $m[1] ) ? $pos - 1 : $pos + 1;
		if ( $ziel < 0 || $ziel >= count( $liste ) ) {
			return $liste; // am Rand angekommen
		}
		$merk           = $liste[ $pos ];
		$liste[ $pos ]  = $liste[ $ziel ];
		$liste[ $ziel ] = $merk;
		return $liste;
	}
}
