<?php
/**
 * Marketing – Shortcodes [bi_kachel] und [bi_liste] + Backend-Builder mit Live-Vorschau.
 *
 * ZWEI DARSTELLUNGEN, EINE MASCHINE. Beide zeigen dieselbe gefilterte Auswahl an
 * Seminaren, nur in anderer Form:
 *
 *   Kachel  [bi_kachel] – ein klickbarer Teaser (Bild + Überschrift + Text,
 *           wahlweise mit Button), der auf die Seminarübersicht mit vorbefüllten
 *           Filtern verlinkt. Er wirbt für eine Auswahl, ohne sie zu zeigen.
 *   Liste   [bi_liste] – die nächsten Termine dieser Auswahl als Trefferzeilen,
 *           Zeile für Zeile dieselben wie unter der Suchbox (BI_Filter::
 *           zeilen_fuer_params), ohne Bild. Sie zeigt die Auswahl, statt für sie
 *           zu werben – für Seiten, auf denen die Termine selbst das Argument
 *           sind.
 *
 * Beide werden im selben Builder gebaut, mit denselben Filtern, und lassen sich
 * gleichermaßen unter einem Namen speichern.
 *
 * Eine Kachel füllt immer die
 * volle Breite ihres Containers – die Größe bestimmt die umgebende Box (z. B. eine
 * Elementor-Spalte), in die der Shortcode eingefügt wird.
 *
 * Layouts:
 *   layout="1"  Bild oben, Text darunter (Standard)
 *   layout="2"  Text liegt über dem Bild (Overlay mit dunklem Verlauf)
 *
 * Der rote Button lässt sich in BEIDEN Layouts abwählen: button="" heißt „keine
 * Schaltfläche". Verlinkt war ohnehin nie der Button, sondern die ganze Kachel –
 * er sagt das nur noch einmal ausdrücklich. Ohne ihn endet die Kachel nach dem
 * Text.
 *
 * Filter-Attribute (entsprechen 1:1 den GET-Parametern der Suche, Mehrfachwerte
 * pipe-getrennt): q, ort, thema, ziel, frei, von, bis, nr (Seminarnummern).
 *
 * Statt auf die Suche kann eine Kachel auch auf eine AUSBILDUNGSREIHE zeigen:
 *   reihe        ID oder Slug einer Reihe. Überschrift, Text und Bild kommen
 *                dann aus der Reihe, solange die Attribute leer bleiben.
 *   meta         "nein" blendet die Kennzahlen (Teile, Gruppen, Start) aus.
 *   beschreibung "nein" blendet den Beschreibungstext aus. Gedacht für
 *                Reihen-Kacheln: Dort füllt sich der Text von selbst aus der
 *                Reihe, und ein leeres text-Attribut heißt deshalb „nimm den
 *                Auszug", nicht „lass ihn weg". Wer nur Bild, Titel und Button
 *                will, braucht also einen ausdrücklichen Schalter.
 * [bi_kachel_reihen] rendert mehrere Reihen als Kachel-Übersicht – ausgewählte
 * (Attribut reihen="12|34") oder alle ausgeschriebenen.
 *
 * Weitere Attribute:
 *   bild         Attachment-ID oder Bild-URL
 *   ratio        Bildausschnitt-Seitenverhältnis, z. B. "16:9", "1:1", "21:9";
 *                "auto" = Bild nicht beschneiden (nur Layout 1); Standard 16:10
 *   fokus        Fokuspunkt des Zuschnitts als "x% y%" (z. B. "50% 20%" = oben mittig);
 *                im Builder per Klick ins Bild setzbar
 *   titel        Überschrift der Kachel
 *   text         Beschreibungstext
 *   button       Button-Beschriftung (Standard: "Zu den Seminaren");
 *                button="" lässt den Button ganz weg
 *   ueberschrift "h1" | "h2" | "h3" – HTML-Tag der Überschrift (Standard: h3)
 *   url          feste Ziel-URL statt gebautem Filter-Link (Filter-Attribute werden ignoriert)
 *   suche_url    Suchseite überschreiben (Standard: Einstellung "Seminarübersicht")
 *   programm     Programmjahr(e) nur für den Redaktions-Zähler; mehrere pipe-getrennt
 *                ("2027|2028"). Muss zum programm-Attribut des
 *                [bi_seminarsuche]-Shortcodes auf der Zielseite passen.
 *
 * Attribute der Listenansicht [bi_liste]: dieselben Filter-Attribute wie oben,
 * dazu
 *   anzahl       Höchstzahl der Zeilen (Standard 5)
 *   titel/text   Überschrift und Einleitung über der Liste (beide optional)
 *   ueberschrift HTML-Tag der Überschrift (h1/h2/h3)
 *   button       Beschriftung des Links unter der Liste; „%d" darin wird durch
 *                die Gesamtzahl ersetzt. button="" lässt den Link weg.
 *   leer         Text, wenn nichts gefunden wurde
 *   programm     Programmjahr(e) – hier ein echter Filter, nicht nur ein Zähler
 *   suche_url    Ziel des Links unter der Liste
 *
 * Gespeicherte Kacheln: Eine im Builder gestaltete Kachel oder Liste lässt sich
 * unter einem
 * Namen ablegen und danach mit [bi_kachel gespeichert="<schlüssel>"] überall
 * einsetzen. Das ist mehr als ein Kopierhelfer – die Kachel bleibt EINE Kachel:
 * Wer sie im Builder ändert, ändert sie an jeder Stelle, an der sie steht. Der
 * Reiter „Gespeicherte Kacheln" zeigt deshalb zu jeder auch, wo sie benutzt wird.
 *
 * Backend: Menüpunkt "Bildungsprogramm → Marketing" – Kachel oder Liste gestalten,
 * Filter per Klick auswählen, Live-Vorschau sehen und den fertigen Shortcode
 * kopieren.
 *
 * Redaktions-Zähler: Eingeloggte Nutzer*innen mit edit_posts sehen auf jeder Kachel
 * ein Badge mit der aktuellen Trefferzahl des Links. Besucher sehen es nicht – für
 * sie wird auch keine Zähl-Query ausgeführt.
 *
 * [bi_kacheln spalten="2|3|4"] ... [/bi_kacheln] bleibt als optionaler Grid-Container
 * erhalten, falls mehrere Kacheln ohne Page-Builder nebeneinander stehen sollen.
 *
 * Vorgefertigte Themen-Kacheln: Tab "Kachel-Vorlagen" auf der Kachel-Seite ordnet
 * jedem Themenfeld-Filter ein Mediathek-Bild und ein Layout (1/2) zu;
 * [bi_kachel_vorlagen spalten="2|3|4"] rendert alle zugeordneten Kacheln als Grid
 * (nur Filter-Label als Überschrift, Layout je Kachel aus der Zuordnung).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Kacheln {

	/** Option: Themenfeld-Term-ID => Attachment-ID (Mediathek) für vorgefertigte Kacheln */
	const OPTION_VORLAGEN = 'bi_kachel_vorlagen';

	/** Option: Schlüssel => gespeicherte Kachel [name, ziel, atts, erstellt, geaendert] */
	const OPTION_GESPEICHERT = 'bi_kacheln_gespeichert';

	/** Hook-Suffix der Kachel-Seite (für gezieltes Asset-Laden) */
	private static $hook = '';

	/** Filter-Attribute, die 1:1 als GET-Parameter an die Suchseite gehen */
	private static function filter_params() {
		return array( 'q', 'form', 'ort', 'thema', 'ziel', 'frei', 'von', 'bis', 'nr' );
	}

	/** Alle Felder, die der Builder an die Vorschau schickt */
	private static function builder_fields() {
		return array_merge(
			array( 'layout', 'ueberschrift', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'programm', 'suche_url' ),
			// Ziel der Kachel steckt in 'kachelziel' (filter | reihe | reihen) und
			// wird in ajax_preview() eigens gelesen – 'ziel' ist hier schon der
			// Filter „Zielgruppe".
			array( 'reihe', 'reihen', 'spalten', 'meta', 'beschreibung' ),
			// Nur für die Listenansicht: wie viele Zeilen sie zeigt.
			array( 'anzahl' ),
			self::filter_params()
		);
	}

	/**
	 * Die beiden Darstellungen – Kachel oder Liste.
	 *
	 * Die Darstellung ist eine eigene Frage neben dem Ziel: WAS gezeigt wird
	 * (Filterauswahl, eine Reihe, mehrere Reihen) steht in 'ziel', WIE es
	 * gezeigt wird hier. Eine Liste gibt es nur für die Filterauswahl – eine
	 * einzelne Ausbildungsreihe als Trefferliste wäre keine Reihe mehr, sondern
	 * ihre Termine, und dafür gibt es die Reihenseite.
	 */
	public static function darstellungen() {
		return array(
			'kachel' => 'Kachel (Bild, Überschrift, Button)',
			'liste'  => 'Liste (Trefferzeilen wie unter der Suche)',
		);
	}

	/** Darstellung eines gespeicherten Eintrags bzw. eines Formularwerts. */
	private static function darstellung( $wert ) {
		return ( 'liste' === (string) $wert ) ? 'liste' : 'kachel';
	}

	/**
	 * Eine Ausbildungsreihe zu ID oder Slug – oder null.
	 *
	 * Der Slug ist erlaubt, weil eine ID in einem Shortcode nichts erzählt und
	 * beim Umzug auf eine andere Installation ins Leere zeigt.
	 */
	private static function reihe_post( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref || ! class_exists( 'BI_Reihen' ) ) {
			return null;
		}
		$post = ctype_digit( $ref )
			? get_post( (int) $ref )
			: get_page_by_path( sanitize_title( $ref ), OBJECT, BI_Reihen::CPT );
		return ( $post && BI_Reihen::CPT === $post->post_type ) ? $post : null;
	}

	/** Teasertext einer Reihe: Auszug, sonst der Anfang des Inhalts. */
	private static function reihe_teaser( $reihe ) {
		$teaser = trim( (string) get_the_excerpt( $reihe ) );
		if ( '' === $teaser ) {
			$teaser = wp_trim_words( wp_strip_all_tags( (string) $reihe->post_content ), 24 );
		}
		return $teaser;
	}

	/**
	 * Kennzahlen einer Reihe für die Kachel: „4 Teile · 2 Gruppen · ab 03/2026".
	 *
	 * Gezählt werden nur KOMMENDE Termine – dieselbe Grundlage wie auf der
	 * Reihenseite und in der Übersicht [bi_reihen]. Eine Reihe, deren Termine
	 * alle gelaufen sind, hat hier also keine Zahlen; genau das ist die
	 * Auskunft, die eine Marketing-Kachel geben muss.
	 *
	 * @return array [ 'teile' => int, 'gruppen' => int, 'start' => string ]
	 */
	private static function reihe_kennzahlen( $reihe_id ) {
		$gruppen = BI_Reihen::termine( (int) $reihe_id );
		$teile   = BI_Reihen::teile( $gruppen );

		$start = '';
		foreach ( $gruppen as $nach_teil ) {
			foreach ( $nach_teil as $posts ) {
				foreach ( $posts as $p ) {
					$roh = (string) get_post_meta( $p->ID, '_bi_startdatum', true );
					if ( '' !== $roh && ( '' === $start || $roh < $start ) ) {
						$start = $roh;
					}
				}
			}
		}

		return array(
			'teile'   => count( $teile ),
			// Durchgang 0 ist „ohne feste Gruppe" und zählt nicht als Gruppe.
			'gruppen' => count( array_filter( array_keys( $gruppen ) ) ),
			'start'   => '' !== $start ? date_i18n( 'm/Y', strtotime( $start ) ) : '',
		);
	}

	public static function init() {
		add_shortcode( 'bi_kacheln', array( __CLASS__, 'grid' ) );
		add_shortcode( 'bi_kachel', array( __CLASS__, 'tile' ) );
		add_shortcode( 'bi_kachel_vorlagen', array( __CLASS__, 'vorlagen_grid' ) );
		add_shortcode( 'bi_kachel_reihen', array( __CLASS__, 'reihen_grid' ) );
		add_shortcode( 'bi_liste', array( __CLASS__, 'liste' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		// Nach BI_Admin::menu (Prio 10) einhängen, damit der Hauptmenüpunkt existiert
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_ajax_bi_kachel_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'admin_post_bi_save_kachel_vorlagen', array( __CLASS__, 'save_vorlagen' ) );
		add_action( 'admin_post_bi_kachel_speichern', array( __CLASS__, 'handle_speichern' ) );
		add_action( 'admin_post_bi_kachel_loeschen', array( __CLASS__, 'handle_loeschen' ) );
	}

	public static function register_assets() {
		wp_register_style( 'bi-kacheln', BI_URL . 'assets/css/kacheln.css', array(), BI_VERSION );
	}

	/** ---------- [bi_kacheln] – optionaler Grid-Container ---------- */

	public static function grid( $atts, $content = '' ) {
		$atts = shortcode_atts( array(
			'spalten' => '3',
		), $atts, 'bi_kacheln' );

		$spalten = in_array( $atts['spalten'], array( '2', '3', '4' ), true ) ? $atts['spalten'] : '3';

		wp_enqueue_style( 'bi-kacheln' );

		return '<div class="bi-kacheln bi-kacheln-' . esc_attr( $spalten ) . '">'
			. do_shortcode( $content )
			. '</div>';
	}

	/* ===================================================================
	 *  Gespeicherte Kacheln
	 *
	 *  Eine gespeicherte Kachel ist eine Kachel, kein Textbaustein: Im Beitrag
	 *  steht nur der VERWEIS [bi_kachel gespeichert="…"], die Gestaltung bleibt
	 *  hier. Wer die Kachel ändert, ändert sie überall – und genau deshalb muss
	 *  ablesbar sein, wo „überall" ist (siehe verwendungen()).
	 * =================================================================== */

	/** Alle gespeicherten Kacheln: Schlüssel => [name, ziel, atts, erstellt, geaendert]. */
	public static function gespeicherte() {
		$alle = get_option( self::OPTION_GESPEICHERT, array() );
		return is_array( $alle ) ? $alle : array();
	}

	/** Eine gespeicherte Kachel oder null. */
	public static function gespeichert( $key ) {
		$alle = self::gespeicherte();
		$key  = sanitize_key( $key );
		return isset( $alle[ $key ] ) ? $alle[ $key ] : null;
	}

	/**
	 * Freien Schlüssel aus dem Namen bilden.
	 *
	 * Der Schlüssel steht ab dann in jedem Beitrag, der die Kachel benutzt – er
	 * wird deshalb NICHT nachträglich geändert, auch wenn jemand die Kachel
	 * umbenennt. Ein umbenannter Schlüssel ließe überall tote Verweise zurück.
	 */
	private static function neuer_key( $name ) {
		$roh = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$roh = strtr( $roh, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		$roh = trim( preg_replace( '/[^a-z0-9]+/', '_', $roh ), '_' );
		$roh = '' === $roh ? 'kachel' : substr( $roh, 0, 40 );

		$alle = self::gespeicherte();
		$key  = $roh;
		$i    = 2;
		while ( isset( $alle[ $key ] ) ) {
			$key = $roh . '_' . $i;
			$i++;
		}
		return $key;
	}

	/**
	 * Wo wird diese Kachel benutzt?
	 *
	 * Gesucht wird an ZWEI Stellen, und die zweite ist die wichtigere: Der
	 * Shortcode steht selten im Beitragsinhalt, meistens in einem
	 * Shortcode-Widget von Elementor – und das legt die ganze Seite als JSON in
	 * einem eigenen Meta-Feld ab, mit maskierten Anführungszeichen. Wer nur
	 * post_content durchsucht, bekommt „wird nirgends benutzt" und glaubt es.
	 *
	 * Die LIKE-Abfrage ist grob (sie findet auch „gespeichert" und den Schlüssel
	 * getrennt voneinander); der reguläre Ausdruck darunter entscheidet. So
	 * bleibt die Abfrage indexfreundlich und das Ergebnis genau – „fruehjahr"
	 * trifft nicht „fruehjahr_2".
	 *
	 * @return array je Fundstelle: [id, titel, typ, status, bearbeiten, ansehen, quelle]
	 */
	public static function verwendungen( $key ) {
		global $wpdb;
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return array();
		}

		$muster = '/gespeichert\s*=\s*["\']?' . preg_quote( $key, '/' ) . '["\']?(?![\w-])/';
		$like   = '%' . $wpdb->esc_like( 'gespeichert' ) . '%' . $wpdb->esc_like( $key ) . '%';
		$raus   = "'trash','auto-draft','inherit'";
		$treffer = array();

		$merken = function ( $row, $quelle ) use ( &$treffer ) {
			$id = (int) $row->ID;
			if ( isset( $treffer[ $id ] ) ) {
				return;
			}
			$typ = get_post_type_object( $row->post_type );
			$treffer[ $id ] = array(
				'id'         => $id,
				'titel'      => $row->post_title !== '' ? $row->post_title : sprintf( '(ohne Titel, #%d)', $id ),
				'typ'        => $typ ? $typ->labels->singular_name : $row->post_type,
				'status'     => $row->post_status,
				'bearbeiten' => (string) get_edit_post_link( $id, '' ),
				'ansehen'    => 'publish' === $row->post_status ? (string) get_permalink( $id ) : '',
				'quelle'     => $quelle,
			);
		};

		// 1. Shortcode im Beitragsinhalt
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_type, post_status, post_content
			   FROM {$wpdb->posts}
			  WHERE post_status NOT IN ($raus)
			    AND post_content LIKE %s
			  LIMIT 300",
			$like
		) );
		foreach ( (array) $rows as $row ) {
			if ( preg_match( $muster, (string) $row->post_content ) ) {
				$merken( $row, 'Inhalt' );
			}
		}

		// 2. Shortcode-Widget in einem Seitenbaukasten (Elementor)
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, p.post_status, pm.meta_value
			   FROM {$wpdb->postmeta} pm
			   INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key = '_elementor_data'
			    AND pm.meta_value LIKE %s
			    AND p.post_status NOT IN ($raus)
			  LIMIT 300",
			$like
		) );
		foreach ( (array) $rows as $row ) {
			// Im JSON stehen die Anführungszeichen maskiert: gespeichert=\"key\".
			if ( preg_match( $muster, stripslashes( (string) $row->meta_value ) ) ) {
				$merken( $row, 'Elementor' );
			}
		}

		return array_values( $treffer );
	}

	/** Kachel aus dem Builder speichern (neu oder überschreiben). */
	public static function handle_speichern() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_kachel_speichern' );

		$name = sanitize_text_field( bi_post( 'kachel_name' ) );
		if ( '' === trim( $name ) ) {
			self::redirect_kacheln( array(), 'Ohne Namen keine gespeicherte Kachel.' );
		}

		$alle = self::gespeicherte();
		$key  = sanitize_key( bi_post( 'kachel_key' ) );
		$neu  = ! isset( $alle[ $key ] );
		if ( $neu ) {
			$key = self::neuer_key( $name );
		}

		$ziel = sanitize_key( bi_post( 'kachelziel', 'filter' ) );
		if ( ! in_array( $ziel, array( 'filter', 'reihe', 'reihen' ), true ) ) {
			$ziel = 'filter';
		}

		// Die Listenansicht zeigt eine gefilterte Auswahl – ein anderes Ziel
		// kann sie nicht haben, und ein gespeichertes „Liste + Ausbildungsreihe"
		// wäre beim nächsten Öffnen im Builder nur verwirrend.
		$darstellung = self::darstellung( bi_post( 'darstellung', 'kachel' ) );
		if ( 'liste' === $darstellung ) {
			$ziel = 'filter';
		}

		// Nur gefüllte Angaben ablegen: Was leer ist, soll die Vorgabe des
		// Shortcodes bleiben und nicht als leerer Wert einfrieren.
		$atts = array();
		foreach ( self::builder_fields() as $feld ) {
			$wert = sanitize_text_field( bi_post( $feld ) );
			if ( '' !== trim( $wert ) ) {
				$atts[ $feld ] = $wert;
			} elseif ( 'button' === $feld ) {
				// EINE AUSNAHME, UND ZWAR DIESELBE WIE IM SHORTCODE: button=""
				// heißt „keine Schaltfläche" und ist eine Ansage, kein fehlender
				// Wert. Fiele er wie jeder andere leere Wert weg, träte beim
				// Anzeigen die Vorgabe an seine Stelle – der Button wäre wieder
				// da, und zwar genau der, den jemand eben abgewählt hat.
				$atts['button'] = '';
			}
		}
		// Das Ziel bestimmt, welche Angaben überhaupt etwas tun – der Rest würde
		// nur verwirren, wenn die Kachel später wieder im Builder landet.
		if ( 'filter' !== $ziel ) {
			foreach ( array_merge( self::filter_params(), array( 'programm', 'suche_url' ) ) as $feld ) {
				unset( $atts[ $feld ] );
			}
		}
		if ( 'reihe' !== $ziel ) {
			unset( $atts['reihe'] );
		}
		if ( 'reihen' !== $ziel ) {
			unset( $atts['reihen'], $atts['spalten'] );
		} else {
			foreach ( array( 'bild', 'fokus', 'titel', 'text' ) as $feld ) {
				unset( $atts[ $feld ] ); // kommen bei der Übersicht aus jeder Reihe selbst
			}
		}
		// Was zur anderen Darstellung gehört, wird gar nicht erst mitgespeichert:
		// Eine Liste hat kein Bild und kein Layout, eine Kachel keine Zeilenzahl.
		if ( 'liste' === $darstellung ) {
			foreach ( array( 'layout', 'bild', 'fokus', 'ratio' ) as $feld ) {
				unset( $atts[ $feld ] );
			}
		} else {
			unset( $atts['anzahl'] );
		}

		$jetzt = current_time( 'mysql' );
		$alle[ $key ] = array(
			'name'        => $name,
			'ziel'        => $ziel,
			'darstellung' => $darstellung,
			'atts'        => $atts,
			'erstellt'    => $neu ? $jetzt : ( $alle[ $key ]['erstellt'] ?? $jetzt ),
			'geaendert'   => $jetzt,
		);
		update_option( self::OPTION_GESPEICHERT, $alle );

		$wort = ( 'liste' === $darstellung ) ? 'Liste' : 'Kachel';
		self::redirect_kacheln(
			array( 'tab' => 'gespeichert' ),
			$neu
				? sprintf( '%s „%s" gespeichert. Einsetzen mit %s.', $wort, $name, self::gespeichert_shortcode( $key, $darstellung ) )
				: sprintf( '%s „%s" aktualisiert – überall, wo sie eingesetzt ist.', $wort, $name )
		);
	}

	/** Gespeicherte Kachel löschen. */
	public static function handle_loeschen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_kachel_loeschen' );

		$key  = sanitize_key( bi_post( 'key' ) );
		$alle = self::gespeicherte();
		if ( ! isset( $alle[ $key ] ) ) {
			self::redirect_kacheln( array( 'tab' => 'gespeichert' ), 'Kachel nicht gefunden.' );
		}

		$name    = (string) $alle[ $key ]['name'];
		$wort    = ( 'liste' === self::darstellung( $alle[ $key ]['darstellung'] ?? '' ) ) ? 'Liste' : 'Kachel';
		$benutzt = count( self::verwendungen( $key ) );
		unset( $alle[ $key ] );
		update_option( self::OPTION_GESPEICHERT, $alle );

		self::redirect_kacheln( array( 'tab' => 'gespeichert' ), sprintf(
			$wort . ' „%s" gelöscht.%s',
			$name,
			$benutzt ? sprintf( ' Achtung: Sie stand noch an %d Stelle(n) – dort erscheint jetzt nichts mehr.', $benutzt ) : ''
		) );
	}

	/**
	 * Der Verweis-Shortcode einer gespeicherten Kachel bzw. Liste.
	 *
	 * Beide Shortcodes verstehen jeden Schlüssel – die Darstellung entscheidet
	 * ohnehin der gespeicherte Eintrag, nicht der Name des Shortcodes. Hier
	 * steht trotzdem der passende: Wer [bi_liste gespeichert="…"] in der Seite
	 * liest, weiß, was dort steht, ohne im Backend nachzusehen.
	 */
	private static function gespeichert_shortcode( $key, $darstellung ) {
		$name = ( 'liste' === self::darstellung( $darstellung ) ) ? 'bi_liste' : 'bi_kachel';
		return '[' . $name . ' gespeichert="' . $key . '"]';
	}

	private static function redirect_kacheln( $args = array(), $msg = '' ) {
		$args = array_merge( array( 'page' => 'bi-kacheln' ), $args );
		if ( $msg ) {
			$args['bi_msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** ---------- [bi_kachel] – einzelne Kachel ---------- */

	public static function tile( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();

		// ---------- Verweis auf eine gespeicherte Kachel ----------
		//
		// Die gespeicherten Angaben liegen UNTER den im Shortcode genannten:
		// [bi_kachel gespeichert="fruehjahr" titel="Anderer Titel"] nimmt die
		// gespeicherte Kachel und tauscht nur die Überschrift. Deshalb wird hier
		// mit den ROHEN Attributen gearbeitet – nach shortcode_atts() ließe sich
		// „nicht angegeben" nicht mehr von „auf Vorgabe gesetzt" unterscheiden.
		$ref = isset( $atts['gespeichert'] ) ? sanitize_key( $atts['gespeichert'] ) : '';
		if ( '' !== $ref ) {
			$eintrag = self::gespeichert( $ref );
			if ( ! $eintrag ) {
				// Für Besucher lieber nichts als eine leere Hülle; die Redaktion
				// soll den toten Verweis sehen, sonst sucht ihn niemand.
				return current_user_can( 'edit_posts' )
					? '<p class="bi-kachel-hinweis">Gespeicherte Kachel <code>' . esc_html( $ref ) . '</code> gibt es nicht (mehr). '
						. 'Hinweis nur für eingeloggte Redakteur*innen.</p>'
					: '';
			}
			$atts = array_merge( (array) $eintrag['atts'], $atts );
			// Eine gespeicherte Übersicht mehrerer Reihen ist ein Gitter, keine
			// einzelne Kachel. Ein Schlüssel, zwei Ausgaben – niemand soll sich
			// merken müssen, welcher Shortcode zu welcher Kachel gehört.
			if ( 'reihen' === ( $eintrag['ziel'] ?? '' ) ) {
				return self::reihen_grid( $atts );
			}
			// Aus demselben Grund gilt der Schlüssel auch für die Listenansicht:
			// Wer [bi_kachel gespeichert="…"] schreibt und eine Liste gespeichert
			// hat, bekommt die Liste – und nicht eine leere Kachel.
			if ( 'liste' === self::darstellung( $eintrag['darstellung'] ?? '' ) ) {
				unset( $atts['gespeichert'] ); // aufgelöst ist aufgelöst – kein zweiter Durchgang
				return self::liste( $atts );
			}
		}

		$defaults = array(
			'bild'         => '',
			'ratio'        => '',
			'fokus'        => '',
			'titel'        => '',
			'text'         => '',
			'button'       => 'Zu den Seminaren',
			'layout'       => '1',
			'ueberschrift' => 'h3',
			'url'          => '',
			'suche_url'    => '',
			'programm'     => '',
			'reihe'        => '',
			'meta'         => '',
			'beschreibung' => '',
			'gespeichert'  => '',
		);
		foreach ( self::filter_params() as $p ) {
			$defaults[ $p ] = '';
		}
		$atts = shortcode_atts( $defaults, $atts, 'bi_kachel' );

		wp_enqueue_style( 'bi-kacheln' );

		// ---------- Ziel „Ausbildungsreihe" ----------
		//
		// Die Reihe füllt alles, was leer geblieben ist: Überschrift, Text und
		// Bild. Wer etwas einträgt, behält es – die Kachel ist ein Werbemittel,
		// und ein Werbetext ist nicht immer der Auszug.
		$reihe_ref = trim( (string) $atts['reihe'] );
		$reihe     = '' !== $reihe_ref ? self::reihe_post( $reihe_ref ) : null;
		$reihe_ok  = $reihe && 'publish' === get_post_status( $reihe->ID );
		$fehler    = '';

		if ( '' !== $reihe_ref && ! $reihe_ok ) {
			// Für Besucher lieber gar keine Kachel als eine, die ins Leere führt.
			// Die Redaktion sieht stattdessen, woran es liegt.
			if ( ! current_user_can( 'edit_posts' ) ) {
				return '';
			}
			$fehler = $reihe
				? 'Diese Ausbildungsreihe ist noch nicht veröffentlicht.'
				: 'Ausbildungsreihe nicht gefunden.';
			$atts['titel'] = '' !== trim( $atts['titel'] ) ? $atts['titel'] : 'Ausbildungsreihe';
		}

		// Ausdrücklich abgeschaltete Beschreibung. Steht VOR dem Auffüllen aus der
		// Reihe: Sonst hinge das Ergebnis daran, ob jemand zusätzlich noch einen
		// Text eingetippt hat, und der Schalter täte mal etwas und mal nichts.
		$ohne_text = 'nein' === strtolower( trim( (string) $atts['beschreibung'] ) );
		if ( $ohne_text ) {
			$atts['text'] = '';
		}

		if ( $reihe_ok ) {
			if ( '' === trim( $atts['titel'] ) ) {
				$atts['titel'] = get_the_title( $reihe );
			}
			if ( ! $ohne_text && '' === trim( $atts['text'] ) ) {
				$atts['text'] = self::reihe_teaser( $reihe );
			}
			if ( '' === trim( $atts['bild'] ) && has_post_thumbnail( $reihe->ID ) ) {
				$atts['bild'] = (string) get_post_thumbnail_id( $reihe->ID );
			}
			// „Zu den Seminaren" ist die Vorgabe für Filter-Kacheln und hier
			// schlicht falsch: Der Klick führt auf eine Reihe, nicht in eine Liste.
			if ( 'Zu den Seminaren' === trim( $atts['button'] ) ) {
				$atts['button'] = 'Zur Ausbildungsreihe';
			}
		}

		// Layout: "1"/"standard" = Bild oben, "2"/"overlay" = Text über dem Bild
		$layout = in_array( trim( (string) $atts['layout'] ), array( '2', 'overlay' ), true ) ? 'overlay' : 'standard';
		$h_tag  = in_array( $atts['ueberschrift'], array( 'h1', 'h2', 'h3' ), true ) ? $atts['ueberschrift'] : 'h3';

		// Filter-Parameter einsammeln (Rohwerte, unkodiert)
		$params = array();
		foreach ( self::filter_params() as $p ) {
			$v = trim( (string) $atts[ $p ] );
			if ( '' !== $v ) {
				$params[ $p ] = $v;
			}
		}

		// Link-Ziel: feste URL, Reihenseite oder Suchseite + Filter-Parameter
		if ( '' !== trim( $atts['url'] ) ) {
			$href = trim( $atts['url'] );
		} elseif ( '' !== $reihe_ref ) {
			$href = $reihe_ok ? (string) get_permalink( $reihe->ID ) : '#';
		} else {
			$base = trim( $atts['suche_url'] );
			if ( '' === $base && class_exists( 'BI_Registration' ) ) {
				// Einstellung „Seminarübersicht", sonst Seite mit [bi_seminarsuche] automatisch finden
				$base = BI_Registration::uebersicht_url();
			}
			if ( '' === $base ) {
				$base = home_url( '/' );
			}
			// Werte selbst kodieren (add_query_arg kodiert nicht): Pipe/Umlaute/Leerzeichen
			$href = $params ? add_query_arg( array_map( 'rawurlencode', $params ), $base ) : $base;
		}

		// Bildausschnitt: Seitenverhältnis (ratio) + Fokuspunkt (fokus) als Inline-Styles
		$ratio_css = '';
		$ratio_raw = trim( (string) $atts['ratio'] );
		if ( 'auto' === $ratio_raw ) {
			$ratio_css = 'auto';
		} elseif ( preg_match( '/^(\d{1,3})\s*[:\/]\s*(\d{1,3})$/', $ratio_raw, $m ) && (int) $m[2] > 0 ) {
			$ratio_css = $m[1] . ' / ' . $m[2];
		}
		$fokus_css = '';
		if ( preg_match( '/^(\d{1,3})%\s+(\d{1,3})%$/', trim( (string) $atts['fokus'] ), $m ) ) {
			$fokus_css = min( 100, (int) $m[1] ) . '% ' . min( 100, (int) $m[2] ) . '%';
		}

		$img_style  = $fokus_css ? 'object-position:' . $fokus_css . ';' : '';
		$tile_style = '';
		if ( $ratio_css ) {
			if ( 'overlay' === $layout ) {
				// Overlay: das Verhältnis bestimmt die ganze Kachel ("auto" ist hier nicht sinnvoll)
				if ( 'auto' !== $ratio_css ) {
					$tile_style = 'aspect-ratio:' . $ratio_css . ';min-height:0;';
				}
			} else {
				$img_style .= 'aspect-ratio:' . $ratio_css . ';';
			}
		}

		// Bild: Attachment-ID (Media-Bibliothek) oder direkte URL
		$img_html = '';
		$bild     = trim( $atts['bild'] );
		if ( '' !== $bild ) {
			if ( ctype_digit( $bild ) ) {
				$img_atts = array(
					'class'   => 'bi-kachel-img',
					'alt'     => $atts['titel'],
					'loading' => 'lazy',
				);
				if ( $img_style ) {
					$img_atts['style'] = $img_style;
				}
				$img_html = wp_get_attachment_image( (int) $bild, 'large', false, $img_atts );
			} else {
				$img_html = '<img class="bi-kachel-img" src="' . esc_url( $bild ) . '" alt="' . esc_attr( $atts['titel'] ) . '"'
					. ( $img_style ? ' style="' . esc_attr( $img_style ) . '"' : '' ) . ' loading="lazy">';
			}
		}

		// ---------- Kennzahlen der Reihe (im Kachelkörper, für alle sichtbar) ----------
		$meta_html = '';
		if ( $reihe_ok && 'nein' !== strtolower( trim( (string) $atts['meta'] ) ) ) {
			$k     = self::reihe_kennzahlen( $reihe->ID );
			$teile = array();
			if ( $k['teile'] ) {
				$teile[] = sprintf( _n( '%d Teil', '%d Teile', $k['teile'], 'bi-seminarsuche' ), $k['teile'] );
			}
			if ( $k['gruppen'] ) {
				$teile[] = sprintf( _n( '%d Gruppe', '%d Gruppen', $k['gruppen'], 'bi-seminarsuche' ), $k['gruppen'] );
			}
			if ( '' !== $k['start'] ) {
				$teile[] = 'ab ' . $k['start'];
			}
			foreach ( $teile as $t ) {
				$meta_html .= '<span>' . esc_html( $t ) . '</span>';
			}
			$meta_html = $meta_html ? '<span class="bi-kachel-meta">' . $meta_html . '</span>' : '';
		}

		// Ob ein Button da ist, muss das CSS wissen: Im Overlay hängt der Text
		// sonst oben in der Kachel und darunter steht leere dunkle Fläche – es
		// war der Button, der ihn nach unten gezogen hat. Eine eigene Klasse
		// statt :has(), damit es auch in älteren Browsern stimmt.
		$btn_text = trim( (string) $atts['button'] );
		$klassen  = 'bi-kachel bi-kachel-' . $layout . ( '' === $btn_text ? ' bi-kachel-ohne-btn' : '' );

		// Redaktions-Zähler: nur für eingeloggte Redakteur*innen.
		$badge = '';
		if ( '' !== $reihe_ref && current_user_can( 'edit_posts' ) ) {
			// Bei einer Reihe zählt nicht die Trefferzahl eines Filters, sondern
			// ob überhaupt Termine dranhängen: Eine Reihe ohne kommende Termine
			// sieht im Frontend fertig aus und ist es nicht.
			if ( '' !== $fehler ) {
				$badge = '<span class="bi-kachel-count bi-kachel-count-0">' . esc_html( $fehler ) . '</span>';
			} else {
				$k    = self::reihe_kennzahlen( $reihe->ID );
				$text = $k['teile']
					? sprintf( _n( '%d kommender Teil', '%d kommende Teile', $k['teile'], 'bi-seminarsuche' ), $k['teile'] )
					: 'keine kommenden Termine';
				$badge = '<span class="bi-kachel-count' . ( $k['teile'] ? '' : ' bi-kachel-count-0' ) . '"'
					. ' title="Stand dieser Ausbildungsreihe – nur für eingeloggte Redakteur*innen sichtbar">'
					. esc_html( $text ) . '</span>';
			}
		} elseif ( '' === trim( $atts['url'] ) && current_user_can( 'edit_posts' ) && class_exists( 'BI_Filter' ) ) {
			$count = BI_Filter::count_for_params( $params, sanitize_text_field( $atts['programm'] ) );
			$label = sprintf( '%s buchbare%s Seminar%s', number_format_i18n( $count ), 1 === $count ? 's' : '', 1 === $count ? '' : 'e' );
			$badge = '<span class="bi-kachel-count' . ( $count > 0 ? '' : ' bi-kachel-count-0' ) . '"'
				. ' title="Trefferzahl dieses Kachel-Links – nur für eingeloggte Redakteur*innen sichtbar">'
				. esc_html( $label ) . '</span>';
		}

		ob_start();
		?>
		<a class="<?php echo esc_attr( $klassen ); ?>" href="<?php echo esc_url( $href ); ?>"<?php echo $tile_style ? ' style="' . esc_attr( $tile_style ) . '"' : ''; ?>>
			<?php if ( $img_html ) : ?>
				<span class="bi-kachel-media"><?php echo $img_html; ?></span>
			<?php endif; ?>
			<span class="bi-kachel-body">
				<?php if ( '' !== trim( $atts['titel'] ) ) : ?>
					<<?php echo $h_tag; ?> class="bi-kachel-titel"><?php echo esc_html( $atts['titel'] ); ?></<?php echo $h_tag; ?>>
				<?php endif; ?>
				<?php if ( '' !== trim( $atts['text'] ) ) : ?>
					<span class="bi-kachel-text"><?php echo esc_html( $atts['text'] ); ?></span>
				<?php endif; ?>
				<?php echo $meta_html; // phpcs:ignore – intern escaped ?>
				<?php // button="" heißt: keine Schaltfläche. Geklickt wird die ganze Kachel. ?>
				<?php if ( '' !== $btn_text ) : ?>
					<span class="bi-kachel-btn"><?php echo esc_html( $btn_text ); ?></span>
				<?php endif; ?>
			</span>
			<?php echo $badge; ?>
		</a>
		<?php
		return ob_get_clean();
	}

	/** ---------- [bi_liste] – Trefferzeilen statt Kachel ---------- */

	/**
	 * Die gefilterte Auswahl als Liste – dieselben Zeilen wie unter der Suchbox.
	 *
	 * WARUM ES DIE ZWEITE DARSTELLUNG GIBT: Eine Kachel wirbt für eine Auswahl,
	 * ohne sie zu zeigen – gut für eine Startseite, auf der Bild und Aussage
	 * zählen. Auf einer Themenseite ist aber oft der Termin das Argument: „Was
	 * läuft demnächst zum Arbeitsrecht?" beantwortet eine Kachel nicht, eine
	 * Liste schon.
	 *
	 * Die Zeilen kommen unverändert aus BI_Filter (zeilen_fuer_params) – es ist
	 * dieselbe Liste, nur woanders. Nachgebaut wäre sie am Tag der zweiten
	 * Änderung schon nicht mehr dieselbe. Ein Bild gibt es nicht: Die Zeile lebt
	 * von Datum, Titel und Ort.
	 *
	 * Der Link unter der Liste führt auf die Suchseite mit genau diesen Filtern –
	 * die Liste zeigt den Anfang, die Suche das Ganze.
	 */
	public static function liste( $atts ) {
		$atts = is_array( $atts ) ? $atts : array();

		// ---------- Verweis auf einen gespeicherten Eintrag ----------
		// Wie bei [bi_kachel]: die gespeicherten Angaben liegen UNTER den im
		// Shortcode genannten, damit sich eine einzelne Angabe übersteuern lässt.
		$ref = isset( $atts['gespeichert'] ) ? sanitize_key( $atts['gespeichert'] ) : '';
		if ( '' !== $ref ) {
			$eintrag = self::gespeichert( $ref );
			if ( ! $eintrag ) {
				return current_user_can( 'edit_posts' )
					? '<p class="bi-kachel-hinweis">Gespeicherte Liste <code>' . esc_html( $ref ) . '</code> gibt es nicht (mehr). '
						. 'Hinweis nur für eingeloggte Redakteur*innen.</p>'
					: '';
			}
			$atts = array_merge( (array) $eintrag['atts'], $atts );
			// Der Schlüssel gilt in beide Richtungen: Steht unter ihm eine
			// Kachel, kommt eine Kachel – auch wenn hier [bi_liste] steht.
			if ( 'liste' !== self::darstellung( $eintrag['darstellung'] ?? '' ) ) {
				unset( $atts['gespeichert'] ); // sonst löste tile() denselben Verweis noch einmal auf
				return ( 'reihen' === ( $eintrag['ziel'] ?? '' ) ) ? self::reihen_grid( $atts ) : self::tile( $atts );
			}
		}

		$defaults = array(
			'anzahl'       => '5',
			'titel'        => '',
			'text'         => '',
			'ueberschrift' => 'h3',
			'button'       => 'Alle %d Seminare anzeigen',
			'leer'         => 'Zurzeit ist zu dieser Auswahl kein Seminar buchbar.',
			'suche_url'    => '',
			'programm'     => '',
			'gespeichert'  => '',
		);
		foreach ( self::filter_params() as $p ) {
			$defaults[ $p ] = '';
		}
		$atts = shortcode_atts( $defaults, $atts, 'bi_liste' );

		if ( ! class_exists( 'BI_Filter' ) ) {
			return '';
		}

		wp_enqueue_style( 'bi-kacheln' );
		// Die Trefferzeilen sind Frontend-Zeilen: ihre Gestaltung steht dort,
		// wo auch die Ergebnisliste sie herholt.
		wp_enqueue_style( 'bi-frontend' );

		$h_tag = in_array( $atts['ueberschrift'], array( 'h1', 'h2', 'h3' ), true ) ? $atts['ueberschrift'] : 'h3';

		// Filter-Parameter einsammeln (Rohwerte, unkodiert) – wie bei der Kachel
		$params = array();
		foreach ( self::filter_params() as $p ) {
			$v = trim( (string) $atts[ $p ] );
			if ( '' !== $v ) {
				$params[ $p ] = $v;
			}
		}

		$programm = sanitize_text_field( $atts['programm'] );
		$treffer  = BI_Filter::zeilen_fuer_params( $params, $programm, (int) $atts['anzahl'] );

		// Ziel des Links unter der Liste: dieselbe Adresse, die auch eine Kachel
		// mit dieser Auswahl ansteuern würde.
		$base = trim( $atts['suche_url'] );
		if ( '' === $base && class_exists( 'BI_Registration' ) ) {
			$base = BI_Registration::uebersicht_url();
		}
		if ( '' === $base ) {
			$base = home_url( '/' );
		}
		$href = $params ? add_query_arg( array_map( 'rawurlencode', $params ), $base ) : $base;

		// „%d" in der Beschriftung wird zur Gesamtzahl. Ersetzt statt sprintf:
		// Ein Prozentzeichen im Text („50% Rabatt") soll kein Formatfehler sein.
		$btn_text = trim( (string) $atts['button'] );
		if ( '' !== $btn_text ) {
			$btn_text = str_replace( '%d', number_format_i18n( $treffer['gesamt'] ), $btn_text );
		}

		// Redaktions-Hinweis wie bei der Kachel: Eine Liste, die nichts findet,
		// sieht im Frontend nach „gepflegt, aber leer" aus. Wer eingeloggt ist,
		// soll den Unterschied sehen.
		$badge = '';
		if ( ! $treffer['gezeigt'] && current_user_can( 'edit_posts' ) ) {
			$badge = '<span class="bi-kachel-count bi-kachel-count-0 bi-liste-count"'
				. ' title="Stand dieser Liste – nur für eingeloggte Redakteur*innen sichtbar">'
				. 'kein buchbares Seminar zu diesen Filtern</span>';
		}

		ob_start();
		?>
		<div class="bi-liste bi-results">
			<?php echo $badge; // phpcs:ignore – intern escaped ?>
			<?php if ( '' !== trim( $atts['titel'] ) ) : ?>
				<<?php echo $h_tag; ?> class="bi-liste-titel"><?php echo esc_html( $atts['titel'] ); ?></<?php echo $h_tag; ?>>
			<?php endif; ?>
			<?php if ( '' !== trim( $atts['text'] ) ) : ?>
				<p class="bi-liste-text"><?php echo esc_html( $atts['text'] ); ?></p>
			<?php endif; ?>

			<?php if ( $treffer['gezeigt'] ) : ?>
				<div class="bi-list"><?php echo $treffer['html']; // phpcs:ignore – intern escaped ?></div>
				<?php if ( '' !== $btn_text ) : ?>
					<a class="bi-liste-mehr" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $btn_text ); ?></a>
				<?php endif; ?>
			<?php elseif ( '' !== trim( $atts['leer'] ) ) : ?>
				<p class="bi-liste-leer"><?php echo esc_html( $atts['leer'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/** ---------- [bi_kachel_reihen] – Übersicht mehrerer Ausbildungsreihen ---------- */

	/**
	 * Mehrere Reihen als Kachel-Gitter.
	 *
	 * Zwei Betriebsarten, und der Unterschied ist Absicht:
	 *
	 *   reihen="12|34"  Handverlesene Auswahl in genau dieser Reihenfolge. Sie
	 *                   wird angezeigt, wie sie dasteht – auch eine Reihe, die
	 *                   der Haken „Auf der Website anzeigen" aus den Listen
	 *                   genommen hat. Wer sie hier hinschreibt, meint sie.
	 *   (leer)          Alle ausgeschriebenen Reihen, alphabetisch. Hier gilt
	 *                   der Haken, wie er überall gilt.
	 */
	public static function reihen_grid( $atts ) {
		$atts = shortcode_atts( array(
			'reihen'       => '',
			'spalten'      => '3',
			'anzahl'       => '-1',
			'layout'       => '1',
			'ratio'        => '',
			'ueberschrift' => 'h3',
			'button'       => 'Zur Ausbildungsreihe',
			'meta'         => '',
			'beschreibung' => '',
			'sortierung'   => '',
		), $atts, 'bi_kachel_reihen' );

		if ( ! class_exists( 'BI_Reihen' ) ) {
			return '';
		}

		$spalten = in_array( trim( (string) $atts['spalten'] ), array( '2', '3', '4' ), true ) ? trim( (string) $atts['spalten'] ) : '3';
		$ids     = array();

		$auswahl = array_filter( array_map( 'trim', preg_split( '/[|,]/', (string) $atts['reihen'] ) ), 'strlen' );
		if ( $auswahl ) {
			foreach ( $auswahl as $ref ) {
				$post = self::reihe_post( $ref );
				// Doppelte Nennung ergäbe zwei gleiche Kacheln nebeneinander.
				if ( $post && 'publish' === get_post_status( $post->ID ) && ! in_array( $post->ID, $ids, true ) ) {
					$ids[] = (int) $post->ID;
				}
			}
			if ( 'titel' === $atts['sortierung'] ) {
				usort( $ids, function ( $a, $b ) {
					return strcasecmp( get_the_title( $a ), get_the_title( $b ) );
				} );
			}
		} else {
			$ids = get_posts( array(
				'post_type'   => BI_Reihen::CPT,
				'post_status' => 'publish',
				'numberposts' => (int) $atts['anzahl'],
				'orderby'     => 'title',
				'order'       => 'ASC',
				'fields'      => 'ids',
				// „Auf der Website anzeigen" gilt hier wie in der Seminarliste:
				// Die Reihe fällt aus der Übersicht, ihre Seite bleibt erreichbar.
				'meta_query'  => array( BI_CPT::visible_clause() ),
			) );
		}

		if ( ! $ids ) {
			// Besucher sollen keine leere Überschrift sehen; die Redaktion soll
			// wissen, warum an dieser Stelle nichts steht.
			return current_user_can( 'edit_posts' )
				? '<p class="bi-kachel-hinweis">Keine Ausbildungsreihe gefunden – diese Übersicht bleibt für Besucher leer. '
					. 'Hinweis nur für eingeloggte Redakteur*innen.</p>'
				: '';
		}

		if ( (int) $atts['anzahl'] > 0 ) {
			$ids = array_slice( $ids, 0, (int) $atts['anzahl'] );
		}

		wp_enqueue_style( 'bi-kacheln' );

		$html = '<div class="bi-kacheln bi-kacheln-' . esc_attr( $spalten ) . '">';
		foreach ( $ids as $id ) {
			$html .= self::tile( array(
				'reihe'        => (string) (int) $id,
				'layout'       => $atts['layout'],
				'ratio'        => $atts['ratio'],
				'ueberschrift' => $atts['ueberschrift'],
				'button'       => $atts['button'],
				'meta'         => $atts['meta'],
				'beschreibung' => $atts['beschreibung'],
			) );
		}
		return $html . '</div>';
	}

	/** ---------- Shortcode-String aus Builder-Feldern bauen ---------- */

	private static function build_shortcode( $atts ) {
		// Achtung: 'ziel' ist zweideutig. Als Builder-Feld benennt es das Ziel der
		// Kachel (filter | reihe | reihen), als Filter-Attribut die Zielgruppe.
		// Getrennt gehalten wird beides hier: Das Builder-Feld heißt im
		// abgeschickten Formular 'kachelziel', das Filter-Attribut bleibt 'ziel'.
		$kachelziel  = isset( $atts['kachelziel'] ) ? (string) $atts['kachelziel'] : 'filter';
		$darstellung = self::darstellung( $atts['darstellung'] ?? 'kachel' );

		if ( 'liste' === $darstellung ) {
			// Die Liste kennt weder Bild noch Layout – und ihr „Button" ist der
			// Link unter den Zeilen. Die Vorgabe steht hier genauso wie bei den
			// Kacheln, damit ein unveränderter Wert nicht im Shortcode landet.
			$order    = array( 'anzahl', 'titel', 'text', 'ueberschrift', 'button', 'q', 'form', 'ort', 'thema', 'ziel', 'frei', 'von', 'bis', 'nr', 'programm', 'suche_url' );
			$defaults = array( 'anzahl' => '5', 'ueberschrift' => 'h3', 'button' => 'Alle %d Seminare anzeigen' );
			$name     = 'bi_liste';
		} elseif ( 'reihen' === $kachelziel ) {
			$order    = array( 'reihen', 'spalten', 'layout', 'ratio', 'ueberschrift', 'button', 'meta', 'beschreibung' );
			$defaults = array( 'button' => 'Zur Ausbildungsreihe', 'ueberschrift' => 'h3', 'spalten' => '3', 'meta' => 'ja', 'beschreibung' => 'ja' );
			$name     = 'bi_kachel_reihen';
		} elseif ( 'reihe' === $kachelziel ) {
			$order    = array( 'reihe', 'layout', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'ueberschrift', 'meta', 'beschreibung' );
			$defaults = array( 'button' => 'Zur Ausbildungsreihe', 'ueberschrift' => 'h3', 'meta' => 'ja', 'beschreibung' => 'ja' );
			$name     = 'bi_kachel';
		} else {
			$order    = array( 'layout', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'ueberschrift', 'q', 'form', 'ort', 'thema', 'ziel', 'frei', 'von', 'bis', 'nr', 'programm', 'suche_url' );
			$defaults = array( 'button' => 'Zu den Seminaren', 'ueberschrift' => 'h3' );
			$name     = 'bi_kachel';
		}

		$parts = array( $name );
		foreach ( $order as $k ) {
			$v = isset( $atts[ $k ] ) ? trim( (string) $atts[ $k ] ) : '';
			if ( 'layout' === $k ) {
				$parts[] = 'layout="' . ( in_array( $v, array( '2', 'overlay' ), true ) ? '2' : '1' ) . '"';
				continue;
			}
			// EIN LEERER BUTTON IST EINE ANSAGE, KEIN FEHLENDER WERT: button=""
			// schaltet die Schaltfläche ab. Fiele das Attribut wie jeder andere
			// leere Wert weg, träte beim Rendern die Vorgabe an seine Stelle –
			// der Button wäre wieder da, und zwar genau der, den jemand eben
			// abgewählt hat.
			if ( 'button' === $k && '' === $v ) {
				$parts[] = 'button=""';
				continue;
			}
			if ( '' === $v || ( isset( $defaults[ $k ] ) && $v === $defaults[ $k ] ) ) {
				continue;
			}
			// Doppelte Anführungszeichen würden das Shortcode-Attribut sprengen
			$parts[] = $k . '="' . str_replace( '"', "'", $v ) . '"';
		}
		return '[' . implode( ' ', $parts ) . ']';
	}

	/** ===================================================================
	 *  Backend: Builder für Kachel und Liste mit Live-Vorschau
	 * =================================================================== */

	public static function menu() {
		// Der Menü-Slug bleibt „bi-kacheln": Er steht in gespeicherten Links, in
		// der Menü-Reihenfolge (BI_Admin::MENU_ORDER) und in jedem Lesezeichen.
		// Umbenannt wurde die Beschriftung, nicht die Adresse.
		self::$hook = add_submenu_page(
			'bi-seminarsuche',
			'Marketing',
			'Marketing',
			BI_CAP,
			'bi-kacheln',
			array( __CLASS__, 'render_page' )
		);
	}

	/** Aktiver Tab der Marketing-Seite: 'builder' (Standard), 'gespeichert' oder 'vorlagen' */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'vorlagen', 'gespeichert' ), true ) ? $tab : 'builder';
	}

	/** Im Builder geöffnete gespeicherte Kachel (leer = neue Kachel). */
	private static function current_kachel() {
		$key = isset( $_GET['kachel'] ) ? sanitize_key( wp_unslash( $_GET['kachel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ( '' !== $key && self::gespeichert( $key ) ) ? $key : '';
	}

	public static function admin_assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_media(); // Mediathek-Auswahl (Builder + Vorlagen)
		wp_enqueue_style( 'bi-kacheln', BI_URL . 'assets/css/kacheln.css', array(), BI_VERSION );
		// Die Vorschau zeigt bei der Listenansicht echte Trefferzeilen – ohne
		// die Frontend-Gestaltung wären sie hier nackte Absätze und die Vorschau
		// eine Behauptung.
		wp_enqueue_style( 'bi-frontend', BI_URL . 'assets/css/frontend.css', array(), BI_VERSION );
		if ( 'builder' !== self::current_tab() ) {
			return; // die übrigen Reiter bringen ihr eigenes Inline-JS mit
		}
		wp_enqueue_script( 'bi-kachel-builder', BI_URL . 'assets/js/kachel-builder.js', array(), BI_VERSION, true );

		// Eine geöffnete gespeicherte Kachel füllt die Maske vor. Der Bild-Link
		// muss mitkommen: Der Builder kennt nur die Anhang-ID, das Vorschaubild
		// daneben braucht eine Adresse.
		$offen  = self::current_kachel();
		$eintrag = $offen ? self::gespeichert( $offen ) : null;
		$vorbelegung = null;
		if ( $eintrag ) {
			$vorbelegung = array(
				'key'         => $offen,
				'name'        => (string) $eintrag['name'],
				'ziel'        => (string) ( $eintrag['ziel'] ?? 'filter' ),
				'darstellung' => self::darstellung( $eintrag['darstellung'] ?? '' ),
				'atts'        => (array) $eintrag['atts'],
				'bildUrl'     => '',
			);
			$bild = (string) ( $eintrag['atts']['bild'] ?? '' );
			if ( ctype_digit( $bild ) ) {
				$vorbelegung['bildUrl'] = (string) wp_get_attachment_image_url( (int) $bild, 'medium' );
			} elseif ( '' !== $bild ) {
				$vorbelegung['bildUrl'] = $bild;
			}
		}

		wp_add_inline_script(
			'bi-kachel-builder',
			'window.biKachelBuilder = ' . wp_json_encode( array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'bi_kachel_preview' ),
				'vorbelegung' => $vorbelegung,
			) ) . ';',
			'before'
		);
	}

	/** Seiten-Dispatcher: Überschrift + Tab-Navigation, dann aktiver Tab */
	public static function render_page() {
		$tab    = self::current_tab();
		$base   = admin_url( 'admin.php?page=bi-kacheln' );
		$notice = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$anzahl = count( self::gespeicherte() );
		?>
		<div class="wrap">
			<h1>Marketing</h1>
			<?php if ( $notice ) : ?><div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
			<nav class="nav-tab-wrapper" style="margin-bottom:16px">
				<a href="<?php echo esc_url( $base ); ?>" class="nav-tab <?php echo 'builder' === $tab ? 'nav-tab-active' : ''; ?>">Builder</a>
				<a href="<?php echo esc_url( $base . '&tab=gespeichert' ); ?>" class="nav-tab <?php echo 'gespeichert' === $tab ? 'nav-tab-active' : ''; ?>">Gespeichert<?php echo $anzahl ? ' (' . (int) $anzahl . ')' : ''; ?></a>
				<a href="<?php echo esc_url( $base . '&tab=vorlagen' ); ?>" class="nav-tab <?php echo 'vorlagen' === $tab ? 'nav-tab-active' : ''; ?>">Kachel-Vorlagen</a>
			</nav>
			<?php
			if ( 'vorlagen' === $tab ) {
				self::render_vorlagen();
			} elseif ( 'gespeichert' === $tab ) {
				self::render_gespeichert();
			} else {
				self::render_builder();
			}
			?>
		</div>
		<?php
	}

	/** AJAX: Vorschau-HTML + fertigen Shortcode für die aktuellen Builder-Felder liefern */
	public static function ajax_preview() {
		check_ajax_referer( 'bi_kachel_preview' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Keine Berechtigung.' );
		}
		$atts = array();
		foreach ( self::builder_fields() as $k ) {
			$atts[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		// Das Builder-Feld für das Kachelziel heißt 'kachelziel' – 'ziel' ist
		// schon der Filter „Zielgruppe" und darf hier nichts anderes bedeuten.
		$atts['kachelziel'] = isset( $_POST['kachelziel'] ) ? sanitize_key( wp_unslash( $_POST['kachelziel'] ) ) : 'filter';
		// Darstellung: Kachel oder Liste. Sie steht neben dem Ziel, nicht darin –
		// siehe darstellungen().
		$atts['darstellung'] = self::darstellung( isset( $_POST['darstellung'] ) ? sanitize_key( wp_unslash( $_POST['darstellung'] ) ) : 'kachel' );

		if ( 'liste' === $atts['darstellung'] ) {
			$atts['reihe'] = '';
			$html          = self::liste( $atts );
		} elseif ( 'reihen' === $atts['kachelziel'] ) {
			$html = self::reihen_grid( $atts );
		} else {
			if ( 'reihe' !== $atts['kachelziel'] ) {
				$atts['reihe'] = ''; // Filter-Kachel: eine gemerkte Reihe darf nicht durchschlagen
			}
			$html = self::tile( $atts );
		}

		wp_send_json_success( array(
			'html'      => $html,
			'shortcode' => self::build_shortcode( $atts ),
		) );
	}

	public static function render_builder() {
		$offen   = self::current_kachel();
		$eintrag = $offen ? self::gespeichert( $offen ) : null;
		$choices = class_exists( 'BI_Filter' ) ? BI_Filter::facet_choices() : array();
		// Ausbildungsreihen für den Ziel-Umschalter. Auch die, die der Haken
		// „Auf der Website anzeigen" aus den Listen nimmt: Eine handverlesene
		// Kachel ist eine bewusste Entscheidung und darf sie zeigen.
		$reihen = class_exists( 'BI_Reihen' ) ? get_posts( array(
			'post_type'   => BI_Reihen::CPT,
			'post_status' => 'publish',
			'numberposts' => 200,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) ) : array();
		$labels  = array( 'form' => 'Seminarform', 'ort' => 'Bildungszentrum', 'thema' => 'Themenfeld', 'ziel' => 'Zielgruppe', 'frei' => 'Freistellung' );

		$programme = get_terms( array( 'taxonomy' => BI_TAX_PROGRAMM, 'hide_empty' => false ) );
		if ( is_wp_error( $programme ) ) {
			$programme = array();
		}
		?>
		<style>
			.bi-kb-layout { display: flex; gap: 28px; align-items: flex-start; flex-wrap: wrap; margin-top: 12px; }
			.bi-kb-form { flex: 1 1 480px; max-width: 640px; background: #fff; border: 1px solid #ccd0d4; padding: 4px 20px 20px; }
			.bi-kb-side { flex: 1 1 380px; max-width: 620px; position: sticky; top: 46px; }
			.bi-kb-form h2 { font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 6px; }
			.bi-kb-field { margin: 0 0 14px; }
			.bi-kb-field label.bi-kb-label { display: block; font-weight: 600; margin-bottom: 4px; }
			.bi-kb-field input[type=text], .bi-kb-field input[type=url], .bi-kb-field textarea, .bi-kb-field select { width: 100%; }
			.bi-kb-radio label { margin-right: 18px; }
			.bi-kb-facet { border: 1px solid #dcdcde; background: #fbfbfc; max-height: 170px; overflow: auto; padding: 8px 10px; border-radius: 4px; }
			.bi-kb-facet label { display: block; margin: 2px 0; }
			/* Mehrere Ja/Nein-Schalter untereinander statt nebeneinander – sonst
			   liest man „Kennzahlen der Reihe anzeigen Beschreibungstext anzeigen". */
			.bi-kb-schalter label { display: block; margin: 2px 0; }
			.bi-kb-cols { display: flex; gap: 16px; flex-wrap: wrap; }
			.bi-kb-cols > div { flex: 1 1 220px; }
			#bi-kb-bild-box { position: relative; display: inline-block; cursor: crosshair; margin: 6px 0; line-height: 0; }
			#bi-kb-bild-box img { display: block; max-width: 240px; height: auto; border: 1px solid #dcdcde; border-radius: 4px; }
			#bi-kb-fokus-marker { position: absolute; width: 18px; height: 18px; border: 2px solid #fff; outline: 1px solid #2271b1; border-radius: 50%; background: rgba(34, 113, 177, .4); transform: translate(-50%, -50%); pointer-events: none; display: none; box-shadow: 0 0 4px rgba(0,0,0,.4); }
			.bi-kb-preview-box { background: #f0f0f1; border: 1px dashed #c3c4c7; padding: 22px; border-radius: 6px; }
			#bi-kb-preview { margin: 0 auto; transition: max-width .2s; }
			.bi-kb-widths { margin-bottom: 10px; display: flex; gap: 6px; align-items: center; }
			.bi-kb-widths .button.active { border-color: #2271b1; color: #2271b1; box-shadow: inset 0 0 0 1px #2271b1; }
			#bi-kb-shortcode { font-family: Consolas, Monaco, monospace; margin-top: 6px; }
			.bi-kb-copied { color: #00a32a; font-weight: 600; margin-left: 8px; }
		</style>

		<?php if ( $eintrag ) : $genutzt = count( self::verwendungen( $offen ) ); ?>
			<div class="notice notice-info inline" style="margin:0 0 14px">
				<p>Du bearbeitest
				   <?php echo 'liste' === self::darstellung( $eintrag['darstellung'] ?? '' ) ? 'die gespeicherte Liste' : 'die gespeicherte Kachel'; ?>
				   <strong><?php echo esc_html( $eintrag['name'] ); ?></strong>
				   (<code><?php echo esc_html( $offen ); ?></code>).
				   <?php if ( $genutzt ) : ?>
					   Sie steht an <strong><?php echo (int) $genutzt; ?> Stelle(n)</strong> – was du hier speicherst,
					   ändert sich dort überall.
				   <?php else : ?>
					   Sie ist noch nirgends eingesetzt.
				   <?php endif; ?>
				   <a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-kacheln&tab=gespeichert' ) ); ?>">Zur Übersicht</a>
				   · <a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-kacheln' ) ); ?>">neu beginnen</a></p>
			</div>
		<?php endif; ?>

		<p>Gestalte hier eine <strong>Kachel</strong> oder eine <strong>Liste</strong>, stelle die Filter ein
			und beobachte rechts die Live-Vorschau. Den fertigen Shortcode kopierst du unten und fügst ihn in
			eine beliebige Box ein (z.&nbsp;B. ein Shortcode-Widget in Elementor) – beide füllen die Box
			automatisch aus.</p>

			<div class="bi-kb-layout">

				<form id="bi-kb-form" class="bi-kb-form" onsubmit="return false">

					<h2>Ziel und Darstellung</h2>

					<div class="bi-kb-field bi-kb-radio">
						<label><input type="radio" name="kachelziel" value="filter" checked> Seminarsuche mit Filtern</label>
						<label><input type="radio" name="kachelziel" value="reihe"> Eine Ausbildungsreihe</label>
						<label><input type="radio" name="kachelziel" value="reihen"> Mehrere Ausbildungsreihen</label>
					</div>

					<?php // Die Darstellung ist eine eigene Frage neben dem Ziel – siehe darstellungen().
					      // Eine Liste gibt es nur für die Filterauswahl, deshalb hängt der Umschalter
					      // an data-ziel="filter". ?>
					<div class="bi-kb-field bi-kb-schalter bi-kb-only" data-ziel="filter">
						<span class="bi-kb-label">Darstellung</span>
						<label><input type="radio" name="darstellung" value="kachel" checked> <strong>Kachel</strong> – Bild, Überschrift, Button</label>
						<label><input type="radio" name="darstellung" value="liste"> <strong>Liste</strong> – die nächsten Termine als Trefferzeilen</label>
						<p class="description">Die Liste zeigt dieselben Zeilen wie die Ergebnisliste unter der
							Suchbox – Datum, Titel, Ort, Nummer, ohne Bild. Die Kachel wirbt für eine Auswahl,
							die Liste zeigt sie. Gefiltert wird bei beiden gleich, gespeichert auch.</p>
					</div>

					<div class="bi-kb-field bi-kb-only" data-ziel="reihe" style="display:none">
						<label class="bi-kb-label" for="bi-kb-reihe">Welche Ausbildungsreihe?</label>
						<select id="bi-kb-reihe" name="reihe">
							<option value="">— bitte wählen —</option>
							<?php foreach ( $reihen as $r ) : ?>
								<option value="<?php echo esc_attr( $r->ID ); ?>"><?php echo esc_html( get_the_title( $r ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">Überschrift, Text und Bild kommen aus der Reihe, solange du sie unten
							leer lässt. Trägst du etwas ein, gilt deine Fassung – eine Kachel ist ein Werbemittel,
							und ein Werbetext ist nicht immer der Auszug.</p>
					</div>

					<div class="bi-kb-only" data-ziel="reihen" style="display:none">
						<div class="bi-kb-field">
							<span class="bi-kb-label">Welche Reihen?</span>
							<div class="bi-kb-radio" style="margin-bottom:6px">
								<label><input type="radio" name="reihen_modus" value="alle" checked> alle ausgeschriebenen</label>
								<label><input type="radio" name="reihen_modus" value="auswahl"> nur ausgewählte</label>
							</div>
							<div class="bi-kb-facet" id="bi-kb-reihen-liste" style="display:none">
								<?php if ( ! $reihen ) : ?>
									<em>Noch keine Ausbildungsreihe angelegt.</em>
								<?php else : ?>
									<?php foreach ( $reihen as $r ) : ?>
										<label>
											<input type="checkbox" name="reihen[]" value="<?php echo esc_attr( $r->ID ); ?>">
											<?php echo esc_html( get_the_title( $r ) ); ?>
										</label>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
							<p class="description">„Alle" folgt dem Haken <em>Auf der Website anzeigen</em>; eine
								handverlesene Auswahl wird gezeigt, wie sie dasteht – in der Reihenfolge dieser Liste.</p>
						</div>

						<div class="bi-kb-field" style="max-width:220px">
							<label class="bi-kb-label" for="bi-kb-spalten">Spalten</label>
							<select id="bi-kb-spalten" name="spalten">
								<option value="2">2</option>
								<option value="3" selected>3</option>
								<option value="4">4</option>
							</select>
							<p class="description">Auf schmalen Bildschirmen bricht das Gitter von selbst um.</p>
						</div>
					</div>

					<div class="bi-kb-field bi-kb-only bi-kb-schalter" data-ziel="reihe reihen" style="display:none">
						<label><input type="checkbox" name="meta" value="ja" checked> Kennzahlen der Reihe anzeigen
							(Teile, Gruppen, frühester Start)</label>
						<label><input type="checkbox" name="beschreibung" value="ja" checked> Beschreibungstext anzeigen</label>
						<p class="description">Ohne Haken zeigt die Kachel nur Bild, Überschrift und Button. Der Text
							kommt sonst aus der Reihe – ein leeres Textfeld heißt dort „nimm den Auszug", nicht
							„lass ihn weg".</p>
					</div>

					<h2>Gestaltung</h2>

					<div class="bi-kb-field bi-kb-only" data-darst="liste" style="display:none;max-width:220px">
						<label class="bi-kb-label" for="bi-kb-anzahl">Wie viele Zeilen?</label>
						<input type="number" id="bi-kb-anzahl" name="anzahl" value="5" min="1" max="50" step="1">
						<p class="description">Gezeigt werden die <strong>nächsten</strong> Termine der Auswahl –
							sortiert nach Startdatum, buchbar wie in der Suche. Der Link darunter führt auf die
							vollständige Liste.</p>
					</div>

					<div class="bi-kb-field bi-kb-radio bi-kb-only" data-darst="kachel">
						<span class="bi-kb-label">Layout</span>
						<label><input type="radio" name="layout" value="1" checked> <strong>1</strong> – Bild oben, Text darunter</label>
						<label><input type="radio" name="layout" value="2"> <strong>2</strong> – Text über dem Bild (Overlay)</label>
					</div>

					<div class="bi-kb-field bi-kb-only" data-ziel="filter reihe" data-darst="kachel">
						<span class="bi-kb-label">Bild</span>
						<input type="hidden" name="bild" value="">
						<input type="hidden" name="fokus" value="">
						<div id="bi-kb-bild-box" style="display:none" title="Klick ins Bild setzt den Fokuspunkt">
							<img id="bi-kb-bild-thumb" src="" alt="">
							<span id="bi-kb-fokus-marker"></span>
						</div>
						<p class="description" id="bi-kb-fokus-hint" style="display:none">
							<strong>Klick ins Bild</strong> setzt den Fokuspunkt – er bestimmt, welcher Ausschnitt beim
							Zuschneiden sichtbar bleibt. <a href="#" id="bi-kb-fokus-reset">Fokus zurücksetzen</a>
						</p>
						<button type="button" class="button" id="bi-kb-bild-waehlen">Bild aus Mediathek wählen</button>
						<button type="button" class="button-link-delete" id="bi-kb-bild-entfernen" style="display:none">Bild entfernen</button>
					</div>

					<div class="bi-kb-field bi-kb-only" data-darst="kachel" style="max-width:320px">
						<label class="bi-kb-label" for="bi-kb-ratio">Bildausschnitt (Seitenverhältnis)</label>
						<select id="bi-kb-ratio" name="ratio">
							<option value="">16:10 (Standard)</option>
							<option value="16:9">16:9</option>
							<option value="3:2">3:2</option>
							<option value="4:3">4:3</option>
							<option value="1:1">Quadratisch (1:1)</option>
							<option value="21:9">Panorama (21:9)</option>
							<option value="auto">Bild nicht beschneiden (nur Layout 1)</option>
						</select>
					</div>

					<div class="bi-kb-cols">
						<div class="bi-kb-field bi-kb-only" data-ziel="filter reihe">
							<label class="bi-kb-label" for="bi-kb-titel">Überschrift</label>
							<input type="text" id="bi-kb-titel" name="titel" placeholder="z. B. BR kompakt">
							<p class="description bi-kb-only" data-darst="liste" style="display:none">Steht über der Liste. Leer lassen,
								wenn die Seite schon eine Überschrift dafür hat.</p>
						</div>
						<div class="bi-kb-field" style="flex:0 1 140px">
							<label class="bi-kb-label" for="bi-kb-htag">HTML-Tag</label>
							<select id="bi-kb-htag" name="ueberschrift">
								<option value="h1">h1</option>
								<option value="h2">h2</option>
								<option value="h3" selected>h3</option>
							</select>
						</div>
					</div>

					<div class="bi-kb-field bi-kb-only" data-ziel="filter reihe">
						<label class="bi-kb-label" for="bi-kb-text">Text</label>
						<textarea id="bi-kb-text" name="text" rows="2" placeholder="Kurzer Teaser-Text …"></textarea>
						<p class="description bi-kb-only" data-darst="liste" style="display:none">Eine Einleitung zwischen Überschrift
							und der ersten Zeile. Auch sie ist freiwillig.</p>
					</div>

					<div class="bi-kb-field bi-kb-schalter">
						<label><input type="checkbox" name="button_an" value="ja" checked>
							<span class="bi-kb-only" data-darst="kachel">Roten Button anzeigen</span>
							<span class="bi-kb-only" data-darst="liste" style="display:none">Link unter der Liste anzeigen</span></label>
						<p class="description bi-kb-only" data-darst="kachel">Ohne Haken endet die Kachel nach dem Text.
							Klickbar bleibt sie genauso: Verlinkt war nie der Button, sondern die ganze Kachel. Gilt
							für beide Layouts.</p>
						<p class="description bi-kb-only" data-darst="liste" style="display:none">Der Link führt auf die Seminarübersicht
							mit genau diesen Filtern – die Liste zeigt den Anfang, die Suche das Ganze. Ohne Haken
							endet die Liste nach der letzten Zeile.</p>
					</div>

					<div class="bi-kb-field" id="bi-kb-button-feld">
						<label class="bi-kb-label" for="bi-kb-button">
							<span class="bi-kb-only" data-darst="kachel">Button-Beschriftung</span>
							<span class="bi-kb-only" data-darst="liste" style="display:none">Beschriftung des Links</span>
						</label>
						<input type="text" id="bi-kb-button" name="button" value="Zu den Seminaren">
						<p class="description bi-kb-only" data-darst="liste" style="display:none"><code>%d</code> in der Beschriftung wird
							durch die Gesamtzahl der Treffer ersetzt – „Alle %d Seminare anzeigen" wird so von
							selbst richtig.</p>
					</div>

					<div class="bi-kb-only" data-ziel="filter">
					<h2>Filter – welche Seminare sind gemeint?</h2>
					<p class="description" style="margin-bottom:12px">Die Auswahl entspricht exakt der Filterleiste im
						Frontend (nur Einträge mit buchbaren Seminaren, Zahl = aktuelle Treffer).
						<span class="bi-kb-only" data-darst="kachel">Die Kachel verlinkt auf die Seminarübersicht mit
						genau diesen vorausgewählten Filtern; das Badge in der Vorschau zeigt die Trefferzahl der
						Kombination.</span>
						<span class="bi-kb-only" data-darst="liste" style="display:none">Die Liste zeigt die nächsten Termine dieser
						Auswahl, der Link darunter führt auf die vollständige Suche.</span></p>

					<div class="bi-kb-cols">
						<?php foreach ( $labels as $param => $label ) : ?>
							<div class="bi-kb-field">
								<span class="bi-kb-label"><?php echo esc_html( $label ); ?></span>
								<div class="bi-kb-facet">
									<?php if ( empty( $choices[ $param ] ) ) : ?>
										<em>Keine Einträge vorhanden.</em>
									<?php else : ?>
										<?php foreach ( $choices[ $param ] as $opt ) : ?>
											<?php if ( ! empty( $opt['separator'] ) ) : ?>
												<hr style="margin:6px 0;border:0;border-top:1px solid #dcdcde">
											<?php else : ?>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( $param ); ?>[]" value="<?php echo esc_attr( $opt['value'] ); ?>">
													<?php echo esc_html( $opt['label'] ); ?>
													<span style="color:#787c82">(<?php echo esc_html( number_format_i18n( $opt['count'] ) ); ?>)</span>
												</label>
											<?php endif; ?>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="bi-kb-cols">
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-q">Suchbegriff (Titel)</label>
							<input type="text" id="bi-kb-q" name="q" placeholder="z. B. Datenschutz">
						</div>
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-nr">Seminarnummern (mit | getrennt)</label>
							<input type="text" id="bi-kb-nr" name="nr" placeholder="z. B. LO12345|BO67890">
						</div>
					</div>

					<div class="bi-kb-cols">
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-von">Startdatum ab</label>
							<input type="date" id="bi-kb-von" name="von">
						</div>
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-bis">Startdatum bis</label>
							<input type="date" id="bi-kb-bis" name="bis">
						</div>
					</div>

					<h2>Optional</h2>

					<div class="bi-kb-cols">
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-programm">Programmjahr</label>
							<select id="bi-kb-programm" name="programm[]" multiple
							        size="<?php echo (int) max( 3, min( 6, count( $programme ) ) ); ?>"
							        style="min-width:220px">
								<?php foreach ( $programme as $p ) : ?>
									<option value="<?php echo esc_attr( $p->name ); ?>"><?php echo esc_html( $p->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Nichts markiert heißt <em>alle Jahrgänge</em>. Mehrere mit
								<kbd>Strg</kbd>/<kbd>Cmd</kbd> markieren – im Jahreswechsel etwa 2027 und 2028
								zusammen.
								<span class="bi-kb-only" data-darst="kachel">Bei der Kachel wirkt die Angabe nur auf
								den Zähler: Nötig, wenn die Zielseite <code>[bi_seminarsuche programm="…"]</code>
								nutzt; dort sind mehrere Jahrgänge ebenfalls pipe-getrennt anzugeben.</span>
								<span class="bi-kb-only" data-darst="liste" style="display:none">Bei der Liste ist es ein echter Filter:
								Gezeigt werden dann nur Termine aus diesen Jahrgängen.</span></p>
						</div>
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-suche-url">Andere Suchseite (URL)</label>
							<input type="url" id="bi-kb-suche-url" name="suche_url" placeholder="Standard: Einstellung „Seminarübersicht"">
						</div>
					</div>
					</div>

				</form>

				<div class="bi-kb-side">
					<div class="bi-kb-widths">
						<span style="font-weight:600">Vorschau-Breite:</span>
						<button type="button" class="button" data-width="300">schmal</button>
						<button type="button" class="button active" data-width="400">mittel</button>
						<button type="button" class="button" data-width="560">breit</button>
					</div>
					<div class="bi-kb-preview-box">
						<div id="bi-kb-preview" style="max-width:400px">
							<div id="bi-kb-preview-inner"><em>Vorschau wird geladen …</em></div>
						</div>
					</div>

					<h2 style="margin-bottom:0">Shortcode</h2>
					<textarea id="bi-kb-shortcode" class="large-text code" rows="4" readonly></textarea>
					<p>
						<button type="button" class="button button-primary" id="bi-kb-copy">Shortcode kopieren</button>
						<span class="bi-kb-copied" id="bi-kb-copied" style="display:none">Kopiert ✓</span>
					</p>

					<?php // Speichern ist der andere Weg: Statt den fertigen Shortcode zu
					      // kopieren, bekommt die Kachel einen Namen und wird über einen
					      // Verweis eingesetzt. Dann liegt die Gestaltung an EINER Stelle
					      // und lässt sich später überall zugleich ändern. ?>
					<div class="card" style="max-width:100%;margin-top:6px">
						<h2 style="margin-top:0">Speichern</h2>
						<p class="description" style="margin-bottom:8px">Gespeichertes wird mit
							<code>[bi_kachel gespeichert="…"]</code> bzw. <code>[bi_liste gespeichert="…"]</code>
							eingesetzt. Eine Änderung hier wirkt dann überall, wo es steht – im Gegensatz zum
							kopierten Shortcode oben, der eine Momentaufnahme ist. Beide Shortcodes verstehen jeden
							Schlüssel: Was herauskommt, entscheidet die gespeicherte Darstellung.</p>
						<p>
							<label for="bi-kb-kachelname" class="bi-kb-label">Name</label>
							<input type="text" id="bi-kb-kachelname" class="regular-text" style="width:100%"
							       value="<?php echo esc_attr( $eintrag['name'] ?? '' ); ?>"
							       placeholder="z. B. Frühjahrskampagne Betriebsrat">
						</p>
						<p>
							<button type="button" class="button button-primary" id="bi-kb-speichern">
								<?php echo $eintrag ? 'Änderungen speichern' : 'Speichern'; ?>
							</button>
							<?php if ( $eintrag ) : ?>
								<button type="button" class="button" id="bi-kb-speichern-neu">Als neuen Eintrag speichern</button>
							<?php endif; ?>
						</p>
					</div>
				</div>

		</div>

		<?php // Das Speichern läuft über ein gewöhnliches Formular: Der Builder
		      // selbst schickt nie ab (er lebt von der Vorschau), deshalb füllt das
		      // Skript dieses Formular und drückt darauf. So gibt es eine echte
		      // Weiterleitung mit Meldung statt eines stillen Hintergrundaufrufs. ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bi-kb-save-form" style="display:none">
			<input type="hidden" name="action" value="bi_kachel_speichern">
			<input type="hidden" name="kachel_key" value="<?php echo esc_attr( $offen ); ?>">
			<input type="hidden" name="kachel_name" value="">
			<?php wp_nonce_field( 'bi_kachel_speichern' ); ?>
		</form>
		<?php
	}

	/** ===================================================================
	 *  Reiter „Gespeicherte Kacheln"
	 * =================================================================== */

	private static function render_gespeichert() {
		$alle = self::gespeicherte();
		$base = admin_url( 'admin.php?page=bi-kacheln' );
		$ziel_label = array( 'filter' => 'Seminarsuche', 'reihe' => 'Ausbildungsreihe', 'reihen' => 'Übersicht mehrerer Reihen' );
		?>
		<p style="max-width:820px">Hier liegen die Kacheln und Listen, die im Builder gespeichert wurden. In der
			Seite steht nur der <strong>Verweis</strong> – die Gestaltung bleibt hier. Wer einen Eintrag ändert,
			ändert ihn damit an <em>jeder</em> Stelle, an der er eingesetzt ist; die Spalte
			<strong>Benutzt in</strong> sagt, welche das sind.</p>

		<?php if ( ! $alle ) : ?>
			<div class="card" style="max-width:100%">
				<p style="margin:0">Noch nichts gespeichert. Im
					<a href="<?php echo esc_url( $base ); ?>">Builder</a> eine Kachel oder Liste gestalten und dort
					rechts <em>„Speichern"</em> benutzen.</p>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<table class="widefat striped">
			<thead><tr>
				<th style="width:20%">Eintrag</th>
				<th style="width:280px">Vorschau</th>
				<th style="width:20%">Shortcode</th>
				<th>Benutzt in</th>
				<th style="width:150px">Aktion</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $alle as $key => $eintrag ) :
				$darst     = self::darstellung( $eintrag['darstellung'] ?? '' );
				$ist_liste = ( 'liste' === $darst );
				$shortcode = self::gespeichert_shortcode( $key, $darst );
				$genutzt   = self::verwendungen( $key );
				$hinweis   = sprintf(
					'%s „%s" löschen?%s',
					$ist_liste ? 'Liste' : 'Kachel',
					$eintrag['name'],
					$genutzt ? sprintf( ' Sie steht noch an %d Stelle(n) – dort erscheint danach nichts mehr.', count( $genutzt ) ) : ''
				);
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $eintrag['name'] ); ?></strong><br>
						<code><?php echo esc_html( $key ); ?></code><br>
						<span style="color:#646970;font-size:12px">
							<?php echo esc_html( $ist_liste ? 'Liste' : 'Kachel' ); ?>
							· <?php echo esc_html( $ziel_label[ $eintrag['ziel'] ?? 'filter' ] ?? $eintrag['ziel'] ); ?>
							· geändert <?php echo esc_html( mysql2date( 'd.m.Y', (string) ( $eintrag['geaendert'] ?? '' ) ) ); ?>
						</span>
					</td>
					<td>
						<?php // Echtes Element, keine Nachbildung: Was hier steht, steht auch auf der Seite.
						      // Die Liste bekommt mehr Platz als eine Kachel – in 200 Pixeln wäre eine
						      // Trefferzeile keine Vorschau, sondern ein Knäuel. ?>
						<div style="max-width:<?php echo $ist_liste ? '280' : '200'; ?>px;pointer-events:none">
							<?php echo self::tile( array( 'gespeichert' => $key ) ); // phpcs:ignore – intern escaped ?>
						</div>
					</td>
					<td>
						<input type="text" class="widefat code bi-kg-code" readonly
						       value="<?php echo esc_attr( $shortcode ); ?>" onclick="this.select()">
						<button type="button" class="button button-small bi-kg-copy" style="margin-top:6px">Kopieren</button>
					</td>
					<td>
						<?php if ( ! $genutzt ) : ?>
							<span style="color:#996800">nirgends – gespeichert, aber noch nicht eingesetzt</span>
						<?php else : ?>
							<ul style="margin:0;list-style:disc;padding-left:18px">
								<?php foreach ( $genutzt as $v ) : ?>
									<li>
										<?php if ( $v['bearbeiten'] ) : ?>
											<a href="<?php echo esc_url( $v['bearbeiten'] ); ?>"><?php echo esc_html( $v['titel'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $v['titel'] ); ?>
										<?php endif; ?>
										<span style="color:#646970;font-size:12px">
											<?php
											$zusatz = array( $v['typ'] );
											if ( 'publish' !== $v['status'] ) {
												$zusatz[] = $v['status'];
											}
											if ( 'Inhalt' !== $v['quelle'] ) {
												$zusatz[] = $v['quelle'];
											}
											echo esc_html( implode( ' · ', $zusatz ) );
											?>
										</span>
										<?php if ( $v['ansehen'] ) : ?>
											<a href="<?php echo esc_url( $v['ansehen'] ); ?>" style="font-size:12px">ansehen</a>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'kachel' => $key ), $base ) ); ?>">Bearbeiten</a>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-top:4px"
						      onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( $hinweis ) ); ?>);">
							<input type="hidden" name="action" value="bi_kachel_loeschen">
							<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
							<?php wp_nonce_field( 'bi_kachel_loeschen' ); ?>
							<button class="button button-small button-link-delete">Löschen</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description" style="max-width:820px;margin-top:14px">
			Gesucht wird der Verweis im <strong>Seiteninhalt</strong> und in den <strong>Elementor-Daten</strong> –
			ein Shortcode-Widget legt seinen Inhalt nicht im Beitragstext ab. Andere Seitenbaukästen und Widgets
			außerhalb von Beiträgen findet die Spalte nicht; dort bleibt „nirgends" stehen, obwohl die Kachel läuft.
			Die Spalte ist also eine Hilfe, keine Garantie – vor dem Löschen lieber einmal mehr hinsehen.
		</p>

		<script>
		( function () {
			document.querySelectorAll( '.bi-kg-copy' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var input = btn.parentNode.querySelector( '.bi-kg-code' );
					if ( ! input ) { return; }
					input.select();
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( input.value );
					} else {
						document.execCommand( 'copy' );
					}
					var alt = btn.textContent;
					btn.textContent = 'Kopiert ✓';
					setTimeout( function () { btn.textContent = alt; }, 1500 );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/** ===================================================================
	 *  Vorgefertigte Themen-Kacheln (Vorlagen)
	 *
	 *  Pro Themenfeld wird ein lizenziertes Bild aus der Mediathek zugeordnet
	 *  (Option OPTION_VORLAGEN: term_id => attachment_id). Daraus entstehen
	 *  fertige Kacheln im Overlay-Layout, ohne Teaser-Text, mit dem
	 *  Filter-Label als Überschrift (z. B. „Grundlagen für Betriebsrät*innen"),
	 *  verlinkt auf die Übersicht mit vorausgewähltem Themenfeld.
	 * =================================================================== */

	/**
	 * Themenfeld-Einträge exakt wie in der Frontend-Filterleiste: dieselbe Auswahl
	 * (nur Einträge mit buchbaren Seminaren), dieselben Labels (thema_label) und
	 * dieselbe Reihenfolge („Grundlagen …" gepinnt). Quelle: BI_Filter::facet_choices().
	 */
	private static function vorlagen_choices() {
		if ( ! class_exists( 'BI_Filter' ) ) {
			return array();
		}
		$choices = BI_Filter::facet_choices();
		$out     = array();
		foreach ( (array) ( $choices['thema'] ?? array() ) as $opt ) {
			if ( ! empty( $opt['separator'] ) ) {
				continue;
			}
			$term = get_term_by( 'name', $opt['value'], BI_TAX_THEMA );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$out[] = array( 'term' => $term, 'label' => $opt['label'], 'count' => (int) $opt['count'] );
		}
		return $out;
	}

	/**
	 * Eintrag { bild, layout } eines Terms aus der Option.
	 * Standard-Layout ist 1 (Bild oben); auch für Alt-Einträge, die nur als
	 * Attachment-ID (int) gespeichert wurden.
	 */
	private static function vorlage_entry( $map, $term_id ) {
		$raw = $map[ $term_id ] ?? null;
		if ( is_array( $raw ) ) {
			return array(
				'bild'   => (int) ( $raw['bild'] ?? 0 ),
				'layout' => in_array( $raw['layout'] ?? '', array( '1', '2' ), true ) ? $raw['layout'] : '1',
			);
		}
		return array( 'bild' => (int) $raw, 'layout' => '1' );
	}

	/** Fertiger Shortcode einer Themen-Kachel (button="" = nur Überschrift, kein Text) */
	private static function vorlage_shortcode( $term, $entry, $label ) {
		$q = function ( $v ) {
			return str_replace( '"', "'", $v );
		};
		return '[bi_kachel layout="' . esc_attr( $entry['layout'] ) . '" bild="' . (int) $entry['bild'] . '" titel="' . $q( $label )
			. '" button="" thema="' . $q( $term->name ) . '"]';
	}

	/** [bi_kachel_vorlagen] – alle Themen-Kacheln mit zugeordnetem Bild als Grid.
	 *  Das Layout kommt je Kachel aus der Vorlagen-Zuordnung (bewusste Wahl je Themenfeld). */
	public static function vorlagen_grid( $atts ) {
		$atts = shortcode_atts( array(
			'spalten' => '3',
			'ratio'   => '',
			'button'  => '',
		), $atts, 'bi_kachel_vorlagen' );

		$map = get_option( self::OPTION_VORLAGEN, array() );
		if ( ! is_array( $map ) || ! $map ) {
			return '';
		}

		$tiles = '';
		foreach ( self::vorlagen_choices() as $choice ) {
			$entry = self::vorlage_entry( $map, $choice['term']->term_id );
			if ( ! $entry['bild'] ) {
				continue;
			}
			$tiles .= self::tile( array(
				'layout' => $entry['layout'],
				'bild'   => (string) $entry['bild'],
				'ratio'  => $atts['ratio'],
				'titel'  => $choice['label'],
				'text'   => '',
				'button' => $atts['button'],
				'thema'  => $choice['term']->name,
			) );
		}
		if ( '' === $tiles ) {
			return '';
		}

		$spalten = in_array( $atts['spalten'], array( '2', '3', '4' ), true ) ? $atts['spalten'] : '3';
		wp_enqueue_style( 'bi-kacheln' );
		return '<div class="bi-kacheln bi-kacheln-' . esc_attr( $spalten ) . '">' . $tiles . '</div>';
	}

	/** Speichern der Bild-Zuordnungen (admin-post) */
	public static function save_vorlagen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_kachel_vorlagen' );

		// Bestehende Zuordnungen behalten und nur die im Formular gelisteten Filter
		// aktualisieren – die Liste zeigt nur Themenfelder mit aktuell buchbaren
		// Seminaren; Zuordnungen ausgeblendeter Felder dürfen nicht verloren gehen.
		$in  = isset( $_POST['vorlage'] ) && is_array( $_POST['vorlage'] ) ? wp_unslash( $_POST['vorlage'] ) : array();
		$map = get_option( self::OPTION_VORLAGEN, array() );
		$map = is_array( $map ) ? $map : array();
		foreach ( $in as $term_id => $row ) {
			$term_id = (int) $term_id;
			if ( $term_id <= 0 ) {
				continue;
			}
			$bild   = (int) ( is_array( $row ) ? ( $row['bild'] ?? 0 ) : $row );
			$layout = ( is_array( $row ) && in_array( $row['layout'] ?? '', array( '1', '2' ), true ) ) ? $row['layout'] : '1';
			if ( $bild > 0 ) {
				$map[ $term_id ] = array( 'bild' => $bild, 'layout' => $layout );
			} else {
				unset( $map[ $term_id ] );
			}
		}
		update_option( self::OPTION_VORLAGEN, $map, false );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-kacheln', 'tab' => 'vorlagen', 'bi_msg' => rawurlencode( 'Vorlagen gespeichert.' ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/** Vorlagen-Tab: Bild + Layout je Themenfeld-Filter zuordnen, fertige Shortcodes kopieren */
	public static function render_vorlagen() {
		$map     = get_option( self::OPTION_VORLAGEN, array() );
		$choices = self::vorlagen_choices();
		$msg     = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		?>
		<style>
			.bi-kv-table td { vertical-align: middle; }
			.bi-kv-thumb { width: 96px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #dcdcde; display: block; }
			.bi-kv-thumb-empty { width: 96px; height: 60px; border: 1px dashed #c3c4c7; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #787c82; font-size: 11px; }
			.bi-kv-shortcode { font-family: Consolas, Monaco, monospace; width: 100%; }
			.bi-kv-copied { color: #00a32a; font-weight: 600; }
			/* Schematische Layout-Vorschau */
			.bi-kv-previews { display: flex; gap: 28px; flex-wrap: wrap; margin: 4px 0 18px; }
			.bi-kv-prev-caption { display: block; margin-bottom: 6px; font-size: 12.5px; color: #50575e; }
			.bi-kv-mock { width: 220px; border: 1px solid #dcdcde; border-radius: 8px; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
			.bi-kv-mock-img { display: flex; align-items: center; justify-content: center; aspect-ratio: 16 / 10; background: linear-gradient(135deg, #c8cdd3, #9aa1a9); color: #fff; font-size: 12px; letter-spacing: .04em; }
			.bi-kv-mock-1 .bi-kv-mock-title { display: block; padding: 12px 14px 14px; font-weight: 700; font-size: 14px; color: #1d2327; }
			.bi-kv-mock-2 { position: relative; aspect-ratio: 16 / 10; background: linear-gradient(135deg, #c8cdd3, #9aa1a9); }
			.bi-kv-mock-2 .bi-kv-mock-title { position: absolute; left: 0; right: 0; bottom: 0; padding: 26px 14px 12px; font-weight: 700; font-size: 14px; color: #fff; background: linear-gradient(transparent, rgba(0,0,0,.65)); }
		</style>

		<?php if ( $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>
		<p>Die Liste entspricht <strong>exakt der Themenfeld-Filterleiste im Frontend</strong> – gleiche
			Einträge (nur mit buchbaren Seminaren), gleiche Überschriften, gleiche Reihenfolge. Ordne jedem
			Filter ein Bild aus der <strong>Mediathek</strong> zu (lizenzierte IG-Metall-Motive dort hochladen)
			und wähle bewusst das Layout. Die Kacheln tragen keinen Text – nur das Filter-Label als
			Überschrift – und verlinken auf die Übersicht mit vorausgewähltem Themenfeld.<br>
			Einzelne Kachel: Shortcode aus der Zeile kopieren. Alle Kacheln auf einmal:
			<code>[bi_kachel_vorlagen spalten="3"]</code> (zeigt automatisch alle Filter mit Bild, in der
			Reihenfolge der Filterleiste, jede im hier gewählten Layout). Die Zuordnung bleibt gespeichert,
			auch wenn ein Themenfeld zwischenzeitlich keine buchbaren Seminare hat und deshalb hier nicht
			auftaucht.</p>

		<div class="bi-kv-previews">
			<div>
				<span class="bi-kv-prev-caption"><strong>Layout 1</strong> – Bild oben, Überschrift darunter</span>
				<div class="bi-kv-mock bi-kv-mock-1">
					<span class="bi-kv-mock-img">Bild</span>
					<span class="bi-kv-mock-title">Grundlagen für Betriebsrät*innen</span>
				</div>
			</div>
			<div>
				<span class="bi-kv-prev-caption"><strong>Layout 2</strong> – Überschrift über dem Bild (Overlay)</span>
				<div class="bi-kv-mock bi-kv-mock-2">
					<span class="bi-kv-mock-title">Grundlagen für Betriebsrät*innen</span>
				</div>
			</div>
		</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_save_kachel_vorlagen">
				<?php wp_nonce_field( 'bi_kachel_vorlagen' ); ?>

				<?php submit_button( 'Vorlagen speichern', 'primary', 'submit', true, array( 'id' => 'bi-kv-submit-top' ) ); ?>

				<table class="widefat striped bi-kv-table">
					<thead>
						<tr>
							<th style="width:110px">Bild</th>
							<th>Themenfeld / Kachel-Überschrift</th>
							<th style="width:230px">Layout</th>
							<th style="width:200px">Aktion</th>
							<th>Shortcode</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! $choices ) : ?>
							<tr><td colspan="5"><em>Aktuell bietet die Filterleiste keine Themenfeld-Einträge an
								(keine buchbaren Seminare mit Themenfeld).</em></td></tr>
						<?php endif; ?>
						<?php foreach ( $choices as $choice ) : ?>
							<?php
							$term  = $choice['term'];
							$titel = $choice['label'];
							$entry = self::vorlage_entry( $map, $term->term_id );
							$thumb = $entry['bild'] ? wp_get_attachment_image_url( $entry['bild'], 'medium' ) : '';
							?>
							<tr>
								<td>
									<input type="hidden" class="bi-kv-id" name="vorlage[<?php echo (int) $term->term_id; ?>][bild]" value="<?php echo $entry['bild'] ?: ''; ?>">
									<img class="bi-kv-thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" <?php echo $thumb ? '' : 'style="display:none"'; ?>>
									<span class="bi-kv-thumb-empty" <?php echo $thumb ? 'style="display:none"' : ''; ?>>kein Bild</span>
								</td>
								<td>
									<strong><?php echo esc_html( $titel ); ?></strong>
									<span style="color:#787c82">(<?php echo esc_html( number_format_i18n( $choice['count'] ) ); ?>)</span>
									<?php if ( $titel !== $term->name ) : ?>
										<br><span class="description">Term: <?php echo esc_html( $term->name ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<select name="vorlage[<?php echo (int) $term->term_id; ?>][layout]">
										<option value="1" <?php selected( $entry['layout'], '1' ); ?>>1 – Bild oben (Standard)</option>
										<option value="2" <?php selected( $entry['layout'], '2' ); ?>>2 – Overlay</option>
									</select>
								</td>
								<td>
									<button type="button" class="button bi-kv-pick">Bild wählen</button>
									<button type="button" class="button-link-delete bi-kv-remove" <?php echo $entry['bild'] ? '' : 'style="display:none"'; ?>>entfernen</button>
								</td>
								<td>
									<?php if ( $entry['bild'] ) : ?>
										<input type="text" class="bi-kv-shortcode" readonly
											value="<?php echo esc_attr( self::vorlage_shortcode( $term, $entry, $titel ) ); ?>"
											onclick="this.select()">
										<button type="button" class="button bi-kv-copy" style="margin-top:4px">Kopieren</button>
										<span class="bi-kv-copied" style="display:none">✓</span>
									<?php else : ?>
										<span class="description">Bild wählen und speichern – dann erscheint hier der Shortcode.</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description">Nach Änderungen an Bild oder Layout speichern – die Shortcodes rechts
					aktualisieren sich beim Speichern.</p>
				<?php submit_button( 'Vorlagen speichern' ); ?>
			</form>

		<script>
		( function () {
			document.querySelectorAll( '.bi-kv-pick' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var row   = btn.closest( 'tr' );
					var frame = wp.media( { title: 'Kachel-Bild wählen', multiple: false, library: { type: 'image' } } );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						row.querySelector( '.bi-kv-id' ).value = att.id;
						var img = row.querySelector( '.bi-kv-thumb' );
						img.src = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
						img.style.display = '';
						row.querySelector( '.bi-kv-thumb-empty' ).style.display = 'none';
						row.querySelector( '.bi-kv-remove' ).style.display = '';
					} );
					frame.open();
				} );
			} );
			document.querySelectorAll( '.bi-kv-remove' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var row = btn.closest( 'tr' );
					row.querySelector( '.bi-kv-id' ).value = '';
					row.querySelector( '.bi-kv-thumb' ).style.display = 'none';
					row.querySelector( '.bi-kv-thumb-empty' ).style.display = '';
					btn.style.display = 'none';
				} );
			} );
			document.querySelectorAll( '.bi-kv-copy' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var row   = btn.closest( 'td' );
					var input = row.querySelector( '.bi-kv-shortcode' );
					input.select();
					navigator.clipboard.writeText( input.value ).then( function () {
						var ok = row.querySelector( '.bi-kv-copied' );
						ok.style.display = '';
						setTimeout( function () { ok.style.display = 'none'; }, 1500 );
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
