<?php
/**
 * Detailansicht eines Seminars (Einzelseite der CPTs bi_seminar und bi_online).
 *
 * ============================================================================
 *  AUFBAU DER SEITE
 * ============================================================================
 *  Kopf          Overline (Format · Themenfeld), Titel in Cond Black/Rot/Versal,
 *                Badge zur Zugehörigkeit zu einer Reihe, Bild mit dem roten
 *                Dreieck des Design-Systems. Die Seminarnummer steht NICHT hier,
 *                sondern in der Box „Seminardetails" – sie ist eine Angabe, kein
 *                Hinweis.
 *  Kennzahlen    graues Band unter einem 4px-Trennstrich: die vier Angaben, die
 *                über die Teilnahme entscheiden (Zeitraum, Ort, Freistellung,
 *                Zielgruppe) – je Piktogramm, Label, Wert.
 *  Hauptspalte   Beschreibung, „Themen des Seminars", „Weitere Termine zu
 *                diesem Seminar" als Zeilen (nicht als Tabelle).
 *  Sidebar       Box „Seminardetails" mit Verfügbarkeits-Ampel, Datenliste,
 *                Kostenaufstellung und Buchungsbereich; darunter die Box
 *                „Seminardetails als PDF" und der Kontakt.
 *
 *  Die Gestaltung folgt dem Design-Handoff „Seminardetail" (IG Metall Design
 *  System): scharfe Ecken, Rot #E3051B für Aktionen und Überschriften, Meta
 *  Head IGM Cond in Versalien für alles Überschriftliche.
 *
 * ============================================================================
 *  WAS AUS WELCHER QUELLE KOMMT
 * ============================================================================
 *  Buchungs-Logik Präsenz – die drei Haken am Seminar, in dieser Reihenfolge:
 *    Anzeigen = nein   -> steht in keiner Liste; die Seite bleibt über den Link
 *                         erreichbar (gezieltes Angebot an eine Zielgruppe)
 *    ausgebucht        -> ausgegraut, kein Anmeldelink, Hinweis „Ausgebucht"
 *    Anmeldung möglich -> „Jetzt buchen" -> Anmeldeformular ?seminar=ID
 *    ohne diesen Haken -> Störer statt Button
 *  Davor steht die Reihe: Trägt sie den Haken „Nur komplett buchbar", gibt es
 *  zu diesem Teil überhaupt keine Einzelanmeldung – an die Stelle des Buttons
 *  tritt der Weg zur Reihenseite (BI_Reihen::komplett_verweis).
 *  Die Geschäftsstellen-Variante (PLZ-Suche + Mail-Anfrage) hängt nicht am
 *  Haken, sondern an einer Regel (BI_Settings::variant_for).
 *
 *  Buchungs-Logik Online (BI_Online::variante):
 *    ausgebucht  -> „Ausgebucht"
 *    extern      -> Teams-Webinar mit eigener Anmeldeseite: Button führt zu Microsoft
 *    direct      -> internes Anmeldeformular (wie Präsenz)
 *    gs          -> Geschäftsstellen-Variante (wie Präsenz)
 *    Zusätzlich: ist ein öffentlicher Online-Link gepflegt, erscheint „Direkt teilnehmen".
 *
 *  Verfügbarkeits-Ampel (BI_Ampel):
 *    In der Sidebar und in „Weitere Termine" steht der aus dem
 *    Seminarverwaltungssystem abgeglichene Zustand (Verfügbar / Fast ausgebucht /
 *    Warteliste möglich). Liegt nichts Belastbares vor, erscheint gar nichts –
 *    die Box bzw. der Punkt entfällt dann ersatzlos, statt eine Farbe zu raten.
 *
 *  Wird über den template_include-Filter geladen, bleibt aber im Theme-Rahmen
 *  (get_header()/get_footer() im Template).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Detail {

	const GS_SUCHE_URL = 'https://www.igmetall.de/vor-ort';

	public static function init() {
		// Früh registrieren: BI_CPT::enqueue_single und BI_Reihen holen die
		// Handles später nur noch ab.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		// template_include statt single_template -> wirkt auch bei Block-/FSE-Themes
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		// Anmeldeseiten-Cache leeren, wenn eine Seite gespeichert wird
		add_action( 'save_post_page', function () {
			delete_transient( 'bi_anmeldung_page_url' );
			delete_transient( 'bi_uebersicht_page_url' );
		} );
	}

	/**
	 * Stylesheet und Skripte beider Detailansichten (Seminar wie Reihe).
	 *
	 * Die Geschäftsstellen-Anfrage wird hier registriert statt an ihrer
	 * Verwendungsstelle eingebunden: Seminarseite und Reihenseite brauchen sie
	 * beide, und die Adresse für den AJAX-Aufruf soll nur einmal gesetzt werden.
	 */
	public static function register_assets() {
		wp_register_style( 'bi-detailseiten', BI_URL . 'assets/css/detailseiten.css', array(), BI_VERSION );
		wp_register_script( 'bi-reihe', BI_URL . 'assets/js/reihe.js', array(), BI_VERSION, true );
		wp_register_script( 'bi-gs-anfrage', BI_URL . 'assets/js/gs-anfrage.js', array(), BI_VERSION, true );
		wp_localize_script( 'bi-gs-anfrage', 'biGsAnfrage', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
		// Führt den Zurück-Link auf die zuletzt gesehene Trefferliste zurück
		// (samt Filtern). Ohne das Skript zeigt der Link auf die Übersicht.
		wp_register_script( 'bi-zurueck', BI_URL . 'assets/js/zurueck.js', array(), BI_VERSION, true );
	}

	/**
	 * „Zurück zur Suche" über dem Titel.
	 *
	 * Der Weg auf eine Detailseite führt fast immer über die Trefferliste, und
	 * von dort zurück will man dieselbe Liste wiedersehen – mit den Filtern, die
	 * man gesetzt hat. Der Browser-Zurück-Knopf kann das, wird aber nicht
	 * gefunden, wer über einen geteilten Link kam; und wer im Seminar mehrere
	 * Termine angesehen hat, muss ihn mehrfach drücken.
	 *
	 * Serverseitig steht hier der Weg zur Übersichtsseite ohne Filter – der
	 * stimmt immer. `zurueck.js` ersetzt ihn, wenn im Tab eine echte
	 * Trefferliste gemerkt ist. Ohne JavaScript bleibt der Link brauchbar.
	 */
	public static function zurueck_link() {
		// uebersicht_url() nimmt die Einstellung „Seminarübersicht", sonst die
		// Seite mit [bi_seminarsuche]; findet es keine, gibt es die Startseite
		// zurück. Die ist als Ziel eines „Zurück zur Suche" falsch – dann steht
		// hier lieber kein Link.
		$url = class_exists( 'BI_Registration' ) ? BI_Registration::uebersicht_url() : BI_Settings::uebersicht_page_url();
		if ( '' === $url || untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
			return '';
		}
		return '<p class="igm-brotkrumen"><a class="igm-zurueck" href="' . esc_url( $url ) . '" data-bi-zurueck>'
			. BI_Icons::get( 'pfeil-links', 18 )
			. '<span>Zurück zur Suche</span></a></p>';
	}

	/** Eigenes Single-Template für beide Seminar-Beitragstypen verwenden */
	public static function template_include( $template ) {
		if ( is_singular( bi_seminar_post_types() ) ) {
			$own = BI_PATH . 'templates/single-bi_seminar.php';
			if ( file_exists( $own ) ) {
				return $own;
			}
		}
		return $template;
	}

	/* ===================================================================
	 *  Bausteine, die Seminar- und Reihenseite teilen
	 * =================================================================== */

	/**
	 * Kopfbereich: Overline, Titel, Untertitel, Badges, Bild mit Dreieck.
	 *
	 * @param array $args overline, titel, untertitel, badges (fertiges HTML),
	 *                    bild_id, brotkrumen (fertiges HTML).
	 */
	public static function hero( $args ) {
		$a = array_merge( array(
			'overline'    => '',
			'titel'       => '',
			'untertitel'  => '',
			'badges'      => '',
			'bild_id'     => 0,
			'brotkrumen'  => '',
		), $args );

		$html = '<header class="igm-hero igm-breite">';
		$html .= '<div class="igm-hero__text">';
		$html .= $a['brotkrumen'];
		if ( '' !== $a['overline'] ) {
			$html .= '<p class="igm-hero__overline">' . esc_html( $a['overline'] ) . '</p>';
		}
		$html .= '<h1 class="igm-hero__titel">' . esc_html( $a['titel'] ) . '</h1>';
		if ( '' !== trim( (string) $a['untertitel'] ) ) {
			$html .= '<p class="igm-hero__untertitel">' . esc_html( $a['untertitel'] ) . '</p>';
		}
		if ( '' !== $a['badges'] ) {
			$html .= '<div class="igm-hero__badges">' . $a['badges'] . '</div>';
		}
		$html .= '</div>';

		if ( $a['bild_id'] && has_post_thumbnail( $a['bild_id'] ) ) {
			// Das Dreieck ist reine Zier des Design-Systems und steht deshalb als
			// leeres Element da – benannt wird es nicht, gelesen auch nicht.
			$html .= '<div class="igm-hero__bild">'
				. get_the_post_thumbnail( $a['bild_id'], 'large', array( 'alt' => '' ) )
				. '<span class="igm-hero__dreieck" aria-hidden="true"></span>'
				. '</div>';
		}
		return $html . '</header>';
	}

	/**
	 * Kennzahlenband. Erwartet [ label => wert ]; leere Werte fallen weg, das
	 * Piktogramm ergibt sich aus dem Label (BI_Icons::fuer_label).
	 */
	public static function fakten( $angaben ) {
		$items = '';
		foreach ( $angaben as $label => $wert ) {
			if ( '' === trim( (string) $wert ) ) {
				continue;
			}
			$items .= '<div class="igm-fakten__item">'
				. BI_Icons::get( BI_Icons::fuer_label( $label ), 24 )
				. '<div><dt class="igm-fakten__label">' . esc_html( $label ) . '</dt>'
				. '<dd class="igm-fakten__wert">' . esc_html( $wert ) . '</dd></div>'
				. '</div>';
		}
		if ( '' === $items ) {
			return '<div class="igm-trennstrich"></div>';
		}
		return '<div class="igm-trennstrich"></div>'
			. '<section class="igm-fakten"><dl class="igm-fakten__inner igm-breite">' . $items . '</dl></section>';
	}

	/** Abschnittsüberschrift mit rotem Strich und optionaler Unterzeile. */
	public static function abschnitt( $titel, $subline = '', $overline = '' ) {
		$html = '<div class="igm-abschnitt">';
		if ( '' !== $overline ) {
			$html .= '<p class="igm-abschnitt__overline">' . esc_html( $overline ) . '</p>';
		}
		$html .= '<h2 class="igm-abschnitt__titel">' . esc_html( $titel ) . '</h2>';
		$html .= '<div class="igm-abschnitt__strich"></div>';
		if ( '' !== $subline ) {
			$html .= '<p class="igm-abschnitt__subline">' . esc_html( $subline ) . '</p>';
		}
		return $html . '</div>';
	}

	/** Badge (Chip) im Kopfbereich. $inhalt darf HTML enthalten (Link). */
	public static function badge( $inhalt, $variante = '' ) {
		$klasse = 'igm-badge' . ( '' !== $variante ? ' igm-badge--' . $variante : '' );
		return '<span class="' . esc_attr( $klasse ) . '">' . $inhalt . '</span>';
	}

	/**
	 * Eine Terminzeile mit vier Spalten: Seminarort, Zeitraum, Verfügbarkeit,
	 * Buchen-Button. Ohne Spaltenüberschriften – die Angaben sprechen für sich,
	 * und über einer Handvoll Zeilen wäre eine Kopfzeile mehr Rahmen als Inhalt.
	 *
	 * Anklickbar ist der Zeitraum, nicht die ganze Zeile: Am Ende steht ein
	 * Button, und ein Button in einem Link ist weder gültiges HTML noch
	 * bedienbar – man träfe beim Klicken mal das eine, mal das andere.
	 *
	 * @param array|null $ampel Ergebnis von BI_Ampel::fuer_nummer().
	 */
	public static function termin_zeile( $post_id, $ampel = null ) {
		$post_id = (int) $post_id;
		$grund   = self::nicht_buchbar( $post_id );

		// Nicht buchbare Termine bleiben in der Liste, treten aber zurück: keine
		// Ampel, kein Button, gedämpfte Schrift. Sie beantworten weiter die Frage
		// „gibt es das Seminar überhaupt und wann?" – nur eben ohne Weg zur
		// Anmeldung. Ein roter Punkt daneben wäre doppelt gemoppelt, der Grund
		// steht ja im Klartext dort, wo sonst der Button ist.
		$verfuegbar = ( '' === $grund && is_array( $ampel ) ) ? BI_Ampel::badge( $ampel ) : '';

		$aktion = ( '' !== $grund )
			? '<span class="igm-termin__aus">' . esc_html( $grund ) . '</span>'
			: self::zeilen_button( $post_id );

		$klasse = 'igm-termin igm-termin--link' . ( '' !== $grund ? ' igm-termin--aus' : '' );

		return '<div class="' . esc_attr( $klasse ) . '">'
			. '<span class="igm-termin__ort">' . esc_html( BI_CPT::ort_anzeige( $post_id ) ) . '</span>'
			. '<a class="igm-termin__datum" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">'
			. esc_html( self::zeitraum( $post_id, true ) ) . '</a>'
			. '<span class="igm-termin__ampel">' . $verfuegbar . '</span>'
			. '<span class="igm-termin__aktion">' . $aktion . '</span>'
			. '</div>';
	}

	/**
	 * Warum lässt sich dieser Termin nicht buchen? '' = er lässt sich buchen.
	 *
	 * Die zwei Wege, auf denen eine Anmeldung ausfällt, tragen verschiedene
	 * Gründe und werden deshalb auch verschieden benannt:
	 *
	 *   „Ausgebucht"      Der Termin ist voll (Haken am Seminar; der
	 *                     Verfügbarkeits-Abgleich setzt ihn aus „Warteliste
	 *                     möglich").
	 *   „Keine Anmeldung" Für dieses Seminar ist über die Website gar keine
	 *                     Anmeldung vorgesehen (Variante 3 der Regel-Engine).
	 *
	 * Öffentlich, damit Trefferliste und Reihenseite dieselbe Auskunft geben.
	 */
	public static function nicht_buchbar( $post_id ) {
		$post_id = (int) $post_id;
		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return 'Ausgebucht';
		}
		if ( 'keine' === BI_Settings::variant_for( $post_id ) ) {
			$label = trim( (string) BI_Settings::get( 'keine_label' ) );
			return ( '' !== $label ) ? $label : 'Keine Anmeldung';
		}
		return '';
	}

	/**
	 * Führt dieses Seminar überhaupt zu einer Anmeldung?
	 *
	 * Gemeint ist: Gibt es einen Weg, sich anzumelden – über das Formular, über
	 * die Geschäftsstelle oder über die Anmeldeseite eines Webinars. Nicht
	 * gemeint ist, ob dieser Weg bequem ist.
	 *
	 * Drei Fälle sagen nein:
	 *   ausgebucht          – der Termin ist voll (nicht_buchbar)
	 *   keine Anmeldung     – Variante 3 der Regel-Engine (nicht_buchbar)
	 *   offene Veranstaltung – öffentlich zugängliches Online-Seminar; dort gibt
	 *                          es einen Teilnahme-Link, aber keine Anmeldung
	 *
	 * Gebraucht wird das in der Trefferliste: Der Weiter-Link heißt „Details und
	 * Anmeldung" nur dort, wo beides stimmt – sonst verspricht die Liste einen
	 * Knopf, den die Detailseite nicht hat.
	 */
	public static function anmeldung_moeglich( $post_id ) {
		$post_id = (int) $post_id;
		if ( '' !== self::nicht_buchbar( $post_id ) ) {
			return false;
		}
		if ( bi_is_online( $post_id ) && 'offen' === BI_Online::variante( $post_id ) ) {
			return false;
		}
		// Teil einer nur komplett buchbaren Reihe: Auf der Detailseite steht der
		// Weg zur Reihe, kein Anmeldeformular.
		if ( BI_Reihen::nur_komplett( $post_id ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Der Buchen-Button einer Terminzeile.
	 *
	 * Anders als in der Sidebar führt er IMMER auf die Detailseite des Termins
	 * und heißt überall gleich „Jetzt buchen".
	 *
	 * WARUM NICHT DIREKT ZUR GESCHÄFTSSTELLENSUCHE: In einer Liste mehrerer
	 * Termine standen sonst zwei verschieden beschriftete Buttons untereinander,
	 * und einer davon sprang mitten im Vergleichen auf igmetall.de – weg von der
	 * Seite, ohne dass man den Termin gesehen hätte. Ein Ziel für alle Zeilen
	 * ist unmissverständlich: erst der Termin, dort dann die PLZ-Suche mit allem
	 * Drum und Dran.
	 *
	 * Die nicht buchbaren Fälle kommen hier gar nicht mehr an – die fängt
	 * termin_zeile() vorher ab (siehe nicht_buchbar).
	 */
	private static function zeilen_button( $post_id ) {
		// Teil einer nur komplett buchbaren Reihe: Auf der Zielseite steht kein
		// Buchen-Button, sondern der Verweis auf die Reihe. „Jetzt buchen"
		// verspräche hier etwas, das die Detailseite nicht einlöst. Das Ziel
		// bleibt dasselbe – erst der Termin, dort dann der Weg weiter.
		$label = BI_Reihen::nur_komplett( $post_id )
			? 'Zum Termin'
			: BI_Settings::get( 'direct_label' );

		return '<a class="igm-btn-buchen" href="' . esc_url( (string) get_permalink( $post_id ) ) . '">'
			. esc_html( $label ) . '</a>';
	}

	/**
	 * „16.08. – 21.08.2026". Mit $kurz steht das Jahr nur am Ende – in einer
	 * Terminliste wiederholt es sich sonst in jeder Zeile zweimal.
	 */
	public static function zeitraum( $post_id, $kurz = false ) {
		$start = get_post_meta( $post_id, '_bi_startdatum', true );
		$end   = get_post_meta( $post_id, '_bi_enddatum', true );
		if ( ! $start ) {
			return '';
		}
		if ( ! $end || $end === $start ) {
			return date_i18n( 'd.m.Y', strtotime( $start ) );
		}
		$von = date_i18n( $kurz ? 'd.m.' : 'd.m.Y', strtotime( $start ) );
		return $von . ( $kurz ? '–' : ' – ' ) . date_i18n( 'd.m.Y', strtotime( $end ) );
	}

	/**
	 * Themenblock: Das Feld trägt mal HTML (<ul><li>…), mal reinen Text mit
	 * Zeilenumbrüchen. Beides landet in derselben Aufzählung mit ▸-Marke.
	 */
	public static function themen_html( $themen ) {
		$themen = (string) $themen;
		if ( '' === trim( $themen ) ) {
			return '';
		}
		if ( $themen !== wp_strip_all_tags( $themen ) ) {
			return '<div class="igm-themen">' . wp_kses_post( $themen ) . '</div>';
		}
		$zeilen = array_filter( array_map( 'trim', preg_split( '/\R+/', $themen ) ) );
		if ( ! $zeilen ) {
			return '';
		}
		$html = '<ul class="igm-liste">';
		foreach ( $zeilen as $z ) {
			// Führende Aufzählungszeichen aus der Quelle entfernen – die Marke
			// setzt das Design, nicht die Datei.
			$html .= '<li>' . esc_html( ltrim( $z, "-–•*\t " ) ) . '</li>';
		}
		return $html . '</ul>';
	}

	/* ===================================================================
	 *  Die Seite
	 * =================================================================== */

	/** Vollständige Detail-Ausgabe für ein Seminar */
	public static function render( $post_id ) {
		$post_id    = intval( $post_id );
		$is_online  = bi_is_online( $post_id );
		$desc       = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$themen     = get_post_meta( $post_id, '_bi_themen', true );
		$untertitel = $is_online ? (string) get_post_meta( $post_id, '_bi_untertitel', true ) : '';

		$html = '<div class="igm-seite igm-seite--seminar">';

		/* ---- Kopf ---- */
		// Kein Badge mit der Seminarnummer unter dem Titel: Sie ist eine Angabe
		// wie Zeitraum oder Ort und steht in der Box „Seminardetails". Über dem
		// Titel entschied sie nichts, nahm aber den Platz des Hinweises ein, der
		// dort wirklich zählt – der Zugehörigkeit zu einer Reihe.
		$badges = '';
		$reihe  = BI_Reihen::hinweis( $post_id );
		if ( '' !== $reihe ) {
			$badges .= self::badge( $reihe, 'rot' );
		}

		$html .= self::hero( array(
			'brotkrumen' => self::zurueck_link(),
			'overline'   => self::overline( $post_id ),
			'titel'      => get_the_title( $post_id ),
			'untertitel' => $untertitel,
			'badges'     => $badges,
			'bild_id'    => $post_id,
		) );

		/* ---- Kennzahlenband ---- */
		$html .= self::fakten( self::kennzahlen( $post_id ) );

		/* ---- Zwei Spalten ---- */
		$html .= '<div class="igm-layout igm-breite"><main class="igm-layout__main">';

		if ( '' !== trim( wp_strip_all_tags( $desc ) ) ) {
			$html .= '<div class="igm-fliesstext">' . wp_kses_post( $desc ) . '</div>';
		}

		$themen_html = self::themen_html( $themen );
		if ( '' !== $themen_html ) {
			$html .= '<div>' . self::abschnitt( 'Themen des Seminars' )
				. '<div class="igm-abschnitt__inhalt">' . $themen_html . '</div></div>';
		}

		$html .= self::weitere_termine( $post_id );
		$html .= '</main>';

		/* ---- Sidebar ---- */
		$html .= '<aside class="igm-layout__side">';
		$html .= '<div class="igm-box igm-box--akzent">';
		$html .= '<h2 class="igm-box__titel">Seminardetails</h2>';
		$html .= self::ampel_box( $post_id );
		// lang="de" damit die Silbentrennung des Browsers greift (hyphens: auto):
		// „Schwerbehindertenvertretung" passt nie in die schmale Wertespalte und
		// soll an einer Silbengrenze brechen, nicht dort, wo der Platz endet.
		$html .= '<dl class="igm-daten" lang="de">' . self::sidebar_rows( $post_id ) . '</dl>';
		$html .= self::kosten_block( $post_id );
		$html .= self::booking_block( $post_id );
		$html .= '</div>';
		$html .= self::pdf_box( $post_id );
		$html .= self::kontakt_box( $post_id );
		$html .= '</aside>';

		$html .= '</div></div>';
		return $html;
	}

	/**
	 * Zeile über dem Titel: Format und Themenfeld. Sie beantwortet vor dem
	 * Lesen des Titels die zwei Fragen, nach denen im Programm gesucht wird –
	 * „vor Ort oder online?" und „worum geht es überhaupt?".
	 */
	private static function overline( $post_id ) {
		$teile = array( bi_is_online( $post_id ) ? 'Online-Seminar' : 'Präsenzseminar' );
		$thema = self::term_list( $post_id, BI_TAX_THEMA );
		if ( '' !== $thema ) {
			$teile[] = $thema;
		}
		return implode( ' · ', $teile );
	}

	/** Die vier Angaben des Kennzahlenbands, je nach Beitragstyp. */
	private static function kennzahlen( $post_id ) {
		$zeitraum = self::zeitraum( $post_id );
		$frei     = self::term_list( $post_id, BI_TAX_FREI );
		$ziel     = self::term_list( $post_id, BI_TAX_ZIEL );

		if ( bi_is_online( $post_id ) ) {
			return array(
				'Zeitraum'        => $zeitraum,
				'Veranstalter*in' => self::term_list( $post_id, BI_TAX_ORT ),
				'Freistellung'    => $frei,
				'Zielgruppe'      => $ziel,
			);
		}
		return array(
			'Zeitraum'     => $zeitraum,
			'Ort'          => BI_CPT::ort_anzeige( $post_id ),
			'Freistellung' => $frei,
			'Zielgruppe'   => $ziel,
		);
	}

	/**
	 * Verfügbarkeits-Ampel als farbig hinterlegter Kasten über der Datenliste.
	 * Ohne belastbare Angabe bleibt der Kasten weg – lieber keine Aussage als
	 * eine überholte.
	 */
	private static function ampel_box( $post_id ) {
		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return '<div class="igm-ampelbox igm-ampelbox--red">'
				. '<span class="igm-ampel"><span class="igm-ampel__punkt" aria-hidden="true"></span>'
				. '<span class="igm-ampel__text"><span class="igm-visually-hidden">Verfügbarkeit: </span>Ausgebucht</span></span>'
				. '</div>';
		}
		$ampel = BI_Ampel::fuer_nummer( get_post_meta( $post_id, '_bi_seminarnummer', true ) );
		if ( ! $ampel ) {
			return '';
		}
		return '<div class="igm-ampelbox igm-ampelbox--' . esc_attr( $ampel['ampel'] ) . '">'
			. BI_Ampel::badge( $ampel ) . BI_Ampel::stand_hinweis( $ampel )
			. '</div>';
	}

	/**
	 * Uhrzeit-Zeile der Sidebar. Start- und Endzeit gehören zu unterschiedlichen
	 * Tagen; der Wochentag dazu kommt aus dem jeweiligen Datum, damit „Beginn
	 * Montag, 09:00 Uhr" / „Ende Freitag, 16:00 Uhr" ohne Nachrechnen lesbar
	 * ist. Beide Angaben stehen auf eigenen Zeilen, eintägige Seminare nennen
	 * den Wochentag einmal vorn, und fehlt ein Datum, bleibt die reine
	 * Zeitangabe stehen.
	 *
	 * Gibt fertiges Markup zurück (Zeilenumbruch): Aufrufer dürfen das Ergebnis
	 * nicht noch einmal durch esc_html() schicken.
	 */
	private static function zeiten_html( $start, $end, $startuh, $enduh ) {
		$startuh = trim( (string) $startuh );
		$enduh   = trim( (string) $enduh );
		if ( '' === $startuh && '' === $enduh ) {
			return '';
		}

		$ts_start = $start ? strtotime( $start ) : false;
		$ts_end   = $end ? strtotime( $end ) : false;

		$starttag = $ts_start ? date_i18n( 'l', $ts_start ) : '';
		$endtag   = $ts_end ? date_i18n( 'l', $ts_end ) : $starttag;

		// Ohne Enddatum – oder wenn beide auf denselben Tag fallen – läuft das
		// Seminar an einem Tag ab. Verglichen wird das Datum, nicht der
		// Wochentagsname: Montag bis Montag der Folgewoche ist nicht eintägig.
		$eintaegig = ! $ts_end
			|| ( $ts_start && date_i18n( 'Y-m-d', $ts_start ) === date_i18n( 'Y-m-d', $ts_end ) );

		if ( $eintaegig && $starttag ) {
			$zeit = ( '' !== $startuh && '' !== $enduh )
				? $startuh . ' – ' . $enduh
				: ( '' !== $startuh ? 'ab ' . $startuh : 'bis ' . $enduh );
			return esc_html( $starttag . ', ' . $zeit . ' Uhr' );
		}

		$zeilen = array();
		if ( '' !== $startuh ) {
			$zeilen[] = 'Beginn ' . ( $starttag ? $starttag . ', ' : '' ) . $startuh . ' Uhr';
		}
		if ( '' !== $enduh ) {
			$zeilen[] = 'Ende ' . ( $endtag ? $endtag . ', ' : '' ) . $enduh . ' Uhr';
		}

		return implode( '<br>', array_map( 'esc_html', $zeilen ) );
	}

	/** Detailzeilen der Sidebar (Definitionsliste) */
	private static function sidebar_rows( $post_id ) {
		$row = function ( $label, $value ) {
			if ( '' === trim( (string) $value ) ) {
				return '';
			}
			return '<div class="igm-daten__zeile"><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
		};

		$nr      = get_post_meta( $post_id, '_bi_seminarnummer', true );
		$start   = get_post_meta( $post_id, '_bi_startdatum', true );
		$end     = get_post_meta( $post_id, '_bi_enddatum', true );
		$startuh = get_post_meta( $post_id, '_bi_startuhrzeit', true );
		$enduh   = get_post_meta( $post_id, '_bi_enduhrzeit', true );
		$plaetze = get_post_meta( $post_id, '_bi_plaetze', true );

		$frei = self::term_list( $post_id, BI_TAX_FREI );
		$ziel = self::term_list( $post_id, BI_TAX_ZIEL );

		$zeitraum = self::zeitraum( $post_id );

		// Die Uhrzeit-Zeile ist bis auf Weiteres ausgeblendet. Wieder einblenden:
		// hier entkommentieren und die beiden $row( 'Uhrzeit', … )-Aufrufe unten
		// ebenfalls. Die Aufbereitung selbst steckt in zeiten_html().
		// $uhrzeit = self::zeiten_html( $start, $end, $startuh, $enduh );

		$rows = '';

		if ( bi_is_online( $post_id ) ) {
			$rows .= $row( 'Seminarnummer', esc_html( $nr ) );
			$rows .= $row( 'Format', 'Online-Seminar' );
			$rows .= $row( 'Veranstalter*in', esc_html( self::term_list( $post_id, BI_TAX_ORT ) ) );
			$rows .= $row( 'Zeitraum', esc_html( $zeitraum ) );
			// $rows .= $row( 'Uhrzeit', $uhrzeit );
			$rows .= $row( 'Referent*innen', esc_html( (string) get_post_meta( $post_id, '_bi_referenten', true ) ) );
			$rows .= $row( 'Freistellung', esc_html( $frei ) );
			$rows .= $row( 'Zielgruppe', esc_html( $ziel ) );
			$rows .= $row( 'Plattform', esc_html( BI_Online::tool_label( $post_id ) ) );
		} else {
			$anrd = get_post_meta( $post_id, '_bi_anreisedatum', true );
			$anru = get_post_meta( $post_id, '_bi_anreiseuhrzeit', true );

			// „Ort" ist der tatsächliche Veranstaltungsort; fehlt er, tritt das
			// zuständige Bildungszentrum an seine Stelle (siehe ort_anzeige()).
			$rows .= $row( 'Seminarnummer', esc_html( $nr ) );
			$rows .= $row( 'Ort', esc_html( BI_CPT::ort_anzeige( $post_id ) ) );
			$rows .= $row( 'Zeitraum', esc_html( $zeitraum ) );
			// $rows .= $row( 'Uhrzeit', $uhrzeit );

			if ( $anrd || $anru ) {
				$an = $anrd ? date_i18n( 'd.m.Y', strtotime( $anrd ) ) : '';
				if ( $anru ) {
					$an = trim( $an . ( $an ? ', ' : '' ) . 'ab ' . $anru . ' Uhr' );
				}
				$rows .= $row( 'Anreise', esc_html( $an ) );
			}

			$rows .= $row( 'Freistellung', esc_html( $frei ) );
			$rows .= $row( 'Zielgruppe', esc_html( $ziel ) );
			if ( BI_CPT::meta_bool( $post_id, '_bi_kinderbetreuung' ) ) {
				$rows .= $row( 'Kinderbetreuung', 'wird angeboten' );
			}
			if ( '' !== trim( (string) $plaetze ) ) {
				$rows .= $row( 'Freie Plätze', esc_html( $plaetze ) );
			}
		}

		// Eigene Felder aus der Datenpflege, sofern dort „auf der Detailseite
		// anzeigen" gesetzt ist.
		$rows .= BI_Felder::detail_rows( $post_id, $row );

		return $rows;
	}

	/**
	 * Kostenaufstellung: Posten links, Betrag rechtsbündig.
	 *
	 * Ab Programm 2027 liegen die Kosten aufgeschlüsselt vor (Seminarkosten,
	 * Unterkunft, Verpflegung, Tagungspauschale, Kurbeitrag, MwSt.); die
	 * Summe wird daraus hier berechnet. Ist kein einziger Posten gepflegt –
	 * also bei allen älteren Jahrgängen –, bleibt es bei der einen Freitext-
	 * Zeile „Kosten".
	 *
	 * Das Freitextfeld wird auch bei aufgeschlüsselten Kosten noch ausgegeben,
	 * denn es heißt „Kosten / Hinweis" und trägt Hinweise wie „Kostenübernahme
	 * durch den Arbeitgeber", die keine Zahl sind.
	 */
	private static function kosten_block( $post_id ) {
		$freitext = trim( (string) get_post_meta( $post_id, '_bi_kosten', true ) );
		$felder   = BI_CPT::kosten_posten( $post_id );

		$liste  = array();
		$rest   = '';
		$gesamt = null;

		if ( $felder ) {
			// Ab Programm 2027: eigene Felder je Posten. Die MwSt. ist dort ein
			// eigener Posten, die Summe also der Bruttobetrag.
			foreach ( $felder as $p ) {
				$liste[] = array( 'label' => $p['label'], 'betrag' => $p['betrag'], 'hinweis' => '' );
			}
			$gesamt = BI_CPT::gesamtkosten( $post_id );
			$rest   = $freitext;
		} else {
			// Jahrgänge davor: alles in einem Freitextfeld. Was sich sicher lesen
			// lässt, wird zur selben Aufstellung; der Rest bleibt als Hinweis
			// darunter stehen (siehe BI_CPT::kosten_aus_text).
			$gelesen = BI_CPT::kosten_aus_text( $freitext );
			$liste   = $gelesen['posten'];
			$rest    = $gelesen['rest'];

			// Eine Summe nur, wenn kein Posten „zzgl." trägt. Sonst stünde dort
			// ein Betrag, den am Ende niemand zahlt: Übernachtung und Verpflegung
			// kommen in diesen Jahrgängen netto, die Steuer obendrauf.
			$offen = false;
			$summe = 0.0;
			foreach ( $liste as $p ) {
				$summe += $p['betrag'];
				if ( preg_match( '/zzgl|zuz(ü|ue)gl/iu', (string) $p['hinweis'] ) ) {
					$offen = true;
				}
			}
			$gesamt = $offen ? null : round( $summe, 2 );
		}

		// Die Preiskategorie („Kat: D5 Tage") ist eine Angabe der internen
		// Kalkulation und beantwortet auf der Detailseite keine Frage – die Dauer
		// steht im Kennzahlenband, der Preis in der Aufstellung darüber.
		$rest = BI_CPT::ohne_kategorie( $rest );

		if ( ! $liste ) {
			$nur_text = BI_CPT::ohne_kategorie( $freitext );
			if ( '' === $nur_text ) {
				return '';
			}
			return '<div class="igm-kosten"><p class="igm-kosten__titel">Kosten</p>'
				. '<p class="igm-kosten__text">' . esc_html( $nur_text ) . '</p></div>';
		}

		$html = '<div class="igm-kosten"><p class="igm-kosten__titel">Kosten</p><table><tbody>';
		foreach ( $liste as $p ) {
			$hinweis = '' !== $p['hinweis']
				? ' <span class="igm-kosten__ust">' . esc_html( $p['hinweis'] ) . '</span>'
				: '';
			$html .= '<tr><td>' . esc_html( $p['label'] ) . $hinweis . '</td>'
				. '<td>' . esc_html( BI_CPT::money_format( $p['betrag'] ) ) . '</td></tr>';
		}
		if ( null !== $gesamt && count( $liste ) > 1 ) {
			$html .= '<tr class="igm-kosten__gesamt"><td>Gesamt</td>'
				. '<td>' . esc_html( BI_CPT::money_format( $gesamt ) ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		if ( '' !== $rest ) {
			$html .= '<p class="igm-kosten__hinweis">' . esc_html( $rest ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Download-Box unter den Seminardetails.
	 *
	 * Es ist dasselbe PDF, das den Anmeldemails beiliegt (BI_PDF::seminar_bytes) –
	 * damit steht auf dem Blatt, das jemand vor der Anmeldung mitnimmt oder in die
	 * Betriebsratssitzung legt, wortgleich dasselbe wie später in der Bestätigung.
	 *
	 * Eigene Box statt eines Links unter dem Buchen-Button: Der Buchungsbereich
	 * soll genau einen Handlungsaufruf haben. Geprüft wird mit vorhanden() statt
	 * available(), sonst lüde jede Detailseite FPDF nach, nur um einen Link zu
	 * zeigen. Ist die Bibliothek nicht da, entfällt die Box ersatzlos.
	 */
	private static function pdf_box( $post_id ) {
		if ( ! class_exists( 'BI_PDF' ) || ! BI_PDF::vorhanden() ) {
			return '';
		}
		// Entwürfe und Vorschauen bekommen keinen Download-Link: Er führte für
		// alle außer der Redaktion ins Leere.
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}

		return '<div class="igm-box igm-box--klein igm-box--download">'
			. '<h2 class="igm-box__titel">Seminardetails als PDF</h2>'
			. '<p class="igm-box__text">Alle Daten zu diesem Seminar auf einem Blatt – zum Ausdrucken und Weitergeben.</p>'
			. '<a class="igm-btn igm-btn--sek igm-btn--download" href="' . esc_url( BI_PDF::download_url( $post_id ) ) . '" download>'
			. BI_Icons::get( 'download', 20 )
			. '<span>PDF herunterladen</span></a>'
			. '</div>';
	}

	/**
	 * Kontaktbox unter den Seminardetails. Leer, wenn niemand gepflegt ist.
	 *
	 * Hier steht ausschließlich die ANSPRECHPERSON – Name, Telefon, E-Mail.
	 * Die Adresse des zuständigen Bildungszentrums (`_bi_bz_email`) gehört
	 * bewusst nicht hierher: Sie ist eine interne Zustelladresse für
	 * Benachrichtigungen, keine Kontaktangabe für Besucher. Bis 1.115.0 stand
	 * sie faktisch doch hier, weil beide Rollen sich ein Feld teilten.
	 *
	 * Jede Zeile erscheint nur, wenn sie gepflegt ist. Ein leeres Telefonfeld
	 * hinterlässt keine Lücke und keine tote Zeile.
	 */
	private static function kontakt_box( $post_id ) {
		$ap  = trim( (string) get_post_meta( $post_id, '_bi_ansprechpartner', true ) );
		$tel = trim( (string) get_post_meta( $post_id, '_bi_ansprechpartner_telefon', true ) );
		$mail = trim( (string) get_post_meta( $post_id, '_bi_ansprechpartner_email', true ) );
		if ( '' === $ap ) {
			return '';
		}

		$zeilen = array();

		$zeilen[] = ( $mail && is_email( $mail ) )
			? '<a href="mailto:' . esc_attr( $mail ) . '">' . BI_Icons::get( 'mail', 17 ) . '<span>' . esc_html( $ap ) . '</span></a>'
			: '<span class="igm-kontakt__name">' . BI_Icons::get( 'person', 17 ) . '<span>' . esc_html( $ap ) . '</span></span>';

		if ( '' !== $tel ) {
			// tel:-Adresse ohne Leerzeichen und Schreibhilfen – auf dem Telefon
			// wird daraus ein Anruf, am Rechner bleibt der lesbare Text stehen.
			$wahl = preg_replace( '/[^0-9+]/', '', $tel );
			$zeilen[] = '<a href="tel:' . esc_attr( $wahl ) . '">'
				. BI_Icons::get( 'telefon', 17 ) . '<span>' . esc_html( $tel ) . '</span></a>';
		}

		return '<div class="igm-box igm-box--klein">'
			. '<h2 class="igm-box__titel">Ansprechpartner*in</h2>'
			. '<div class="igm-kontakt">' . implode( '', $zeilen ) . '</div>'
			. '</div>';
	}

	/* ===================================================================
	 *  Buchung
	 * =================================================================== */

	/** Ziel-URL der Direktanmeldung für ein Seminar */
	private static function direct_url( $post_id ) {
		$url = BI_Registration::anmeldung_page_url();
		return $url
			? add_query_arg( 'seminar', $post_id, $url )
			: add_query_arg( 'seminar', $post_id, get_permalink( $post_id ) );
	}

	/**
	 * Wohin der Buchen-Button eines Termins führt – ohne Markup, damit auch die
	 * Reihenseite den Weg kennt, ohne die Weiche ein zweites Mal zu bauen.
	 * Leerer String bei ausgebuchten Terminen.
	 */
	public static function buchen_url( $post_id ) {
		$post_id = (int) $post_id;
		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return '';
		}
		if ( bi_is_online( $post_id ) ) {
			$variante = BI_Online::variante( $post_id );
			if ( 'extern' === $variante ) {
				return (string) BI_Online::anmeldelink( $post_id );
			}
			if ( 'offen' === $variante ) {
				return (string) BI_Online::online_link( $post_id );
			}
		}
		$variante = BI_Settings::variant_for( $post_id );
		// „Keine Anmeldung möglich": Es gibt kein Ziel. Ein Link auf die
		// Geschäftsstellensuche wäre hier eine falsche Auskunft.
		if ( 'keine' === $variante ) {
			return '';
		}
		if ( 'direct' === $variante ) {
			return (string) self::direct_url( $post_id );
		}
		return (string) self::gs_such_url();
	}

	/**
	 * Der Störer – das runde Element des Design-Systems und die einzige Stelle,
	 * an der das Corporate Design eine Rundung kennt.
	 *
	 * Er tritt an die Stelle des Buchen-Buttons, wenn für ein Seminar gar keine
	 * Anmeldung vorgesehen ist. Bewusst kein ausgegrauter Button: Ein Button,
	 * der nichts tut, sieht aus wie ein Fehler; ein Störer sagt, dass es so
	 * gemeint ist.
	 */
	public static function stoerer( $text, $hinweis = '' ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$html = '<div class="igm-stoerer-block">'
			. '<span class="igm-stoerer">' . esc_html( $text ) . '</span>';
		if ( '' !== trim( (string) $hinweis ) ) {
			$html .= '<p class="igm-buchen-hinweis">' . esc_html( $hinweis ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * Reiner Buchungs-Button für die Sidebar.
	 *
	 * Deckt nur die Fälle ab, in denen ein Button überhaupt das richtige Bauteil
	 * ist – Direktanmeldung und die beiden Online-Sonderwege. Die
	 * Geschäftsstellen-Variante bekommt stattdessen die PLZ-Suche
	 * (gs_anfrage_widget), Variante 3 den Störer; beides entscheidet
	 * booking_block(), bevor es hier hereinkommt.
	 */
	private static function booking_button( $post_id ) {
		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return '<span class="igm-btn-buchen igm-btn-buchen--disabled" aria-disabled="true">Ausgebucht</span>';
		}

		// Online-Seminare: eigene Weiche (externe Anmeldeseite bzw. offener Zugang).
		if ( bi_is_online( $post_id ) ) {
			$variante = BI_Online::variante( $post_id );
			if ( 'extern' === $variante ) {
				return '<a class="igm-btn-buchen" aria-label="Zur Anmeldung beim Webinar-Anbieter" href="'
					. esc_url( BI_Online::anmeldelink( $post_id ) ) . '" target="_blank" rel="noopener">Zur Anmeldung</a>';
			}
			if ( 'offen' === $variante ) {
				return '<a class="igm-btn-buchen igm-btn-buchen--alt" aria-label="Direkt am Online-Seminar teilnehmen" href="'
					. esc_url( BI_Online::online_link( $post_id ) ) . '" target="_blank" rel="noopener">Direkt teilnehmen</a>';
			}
		}

		$variante = BI_Settings::variant_for( $post_id );

		// Variante 3: gar keine Anmeldung – statt eines Buttons der Störer.
		if ( 'keine' === $variante ) {
			return self::stoerer( BI_Settings::get( 'keine_label' ) );
		}

		if ( 'direct' === $variante ) {
			return '<a class="igm-btn-buchen" aria-label="Jetzt Seminar buchen" href="' . esc_url( self::direct_url( $post_id ) ) . '">'
				. esc_html( BI_Settings::get( 'direct_label' ) ) . '</a>';
		}

		// Bleibt die Geschäftsstellen-Variante. Ein Button auf die
		// Geschäftsstellensuche stand hier früher – er ist der PLZ-Suche
		// gewichen, die die zuständige Stelle gleich nennt und die Anfrage
		// vorausfüllt. booking_block() ruft diese Stelle deshalb gar nicht mehr
		// mit 'gs' auf; kommt es doch dazu, ist die PLZ-Suche die richtige
		// Antwort und nicht ein Sprung auf igmetall.de.
		return self::gs_anfrage_widget( $post_id );
	}

	/** Buchungs-Block der Sidebar (Button + Hinweistext zum Beschlussverfahren) */
	private static function booking_block( $post_id ) {
		$is_online = bi_is_online( $post_id );

		/*
		 * Teil einer nur komplett buchbaren Reihe: Hier gibt es nichts zu
		 * buchen – weder über das Formular noch über die Geschäftsstelle.
		 * Statt des Buttons steht der Weg zur Reihe, wo alle Teile in einem Zug
		 * angemeldet werden.
		 *
		 * Diese Weiche steht VOR der Frage nach „ausgebucht": Ist dieser eine
		 * Termin voll, führt der Weg trotzdem zur Reihe – dort stehen die
		 * übrigen Gruppen, in denen noch Plätze frei sein können. Der Hinweis
		 * auf den vollen Termin bleibt darüber stehen, er ist ja weiter wahr.
		 */
		$reihe_verweis = BI_Reihen::komplett_verweis( $post_id );
		if ( '' !== $reihe_verweis ) {
			$voll = BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' )
				? '<div class="igm-buchen-hinweis">Dieser Termin ist <strong>ausgebucht</strong>.</div>'
				: '';
			return $voll . $reihe_verweis;
		}

		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return '<div class="igm-buchen-hinweis">Dieses Seminar ist <strong>ausgebucht</strong>.</div>';
		}

		$html     = '';
		$variante = $is_online ? BI_Online::variante( $post_id ) : '';

		if ( 'extern' === $variante ) {
			$html .= self::booking_button( $post_id );
			$html .= '<div class="igm-buchen-hinweis">Die Anmeldung läuft über die Anmeldeseite des Webinars.</div>';
		} elseif ( 'offen' === $variante ) {
			// Öffentliche Veranstaltung: nur der Teilnahme-Link (wird unten angehängt)
			$html .= '<div class="igm-buchen-hinweis">Diese Veranstaltung ist <strong>öffentlich zugänglich</strong> – eine Anmeldung ist nicht nötig.</div>';
		} elseif ( 'keine' === BI_Settings::variant_for( $post_id ) ) {
			// Störer statt Button, mit dem erklärenden Satz darunter.
			$html .= self::stoerer( BI_Settings::get( 'keine_label' ), BI_Settings::get( 'keine_hinweis' ) );
		} elseif ( 'direct' === BI_Settings::variant_for( $post_id ) ) {
			$html .= self::booking_button( $post_id );
			if ( ! $is_online ) {
				$html .= '<p class="igm-buchen-hinweis">Der Betriebsrat beschließt die Teilnahme und meldet sie dem Arbeitgeber.</p>';
			}
		} else {
			$html .= self::gs_anfrage_widget( $post_id );
		}

		// Öffentlich zugängliche Online-Veranstaltung: Teilnahme-Link zusätzlich anbieten.
		// (Bei 'offen' ist er der einzige Handlungsaufruf, sonst steht er unter der Anmeldung.)
		if ( $is_online ) {
			$link = BI_Online::online_link( $post_id );
			if ( '' !== $link ) {
				$html .= '<a class="igm-btn-buchen igm-btn-buchen--alt" href="' . esc_url( $link )
					. '" target="_blank" rel="noopener">Direkt teilnehmen</a>';
			}
		}

		return $html;
	}

	/**
	 * PLZ-Suche + Mail-Anfrage für die GS-Variante (Sidebar).
	 * gs-anfrage.js macht den AJAX-Lookup (bi_plz_lookup) und baut den
	 * „Anfrage senden"-mailto-Link aus den data-bi-Attributen.
	 */
	private static function gs_anfrage_widget( $post_id ) {
		$html  = '<div class="igm-gs-anfrage"' . self::gs_mail_attrs( $post_id ) . '>';
		$html .= '<div class="igm-buchen-hinweis">' . esc_html( BI_Settings::get( 'gs_hinweis' ) ) . '</div>';
		$html .= '<label class="igm-gs-anfrage__label" for="igm-gs-plz">Postleitzahl deines Arbeitsortes:</label>';
		$html .= '<div class="igm-gs-anfrage__form">';
		$html .= '<input type="text" id="igm-gs-plz" class="igm-gs-anfrage__plz" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" placeholder="z. B. 60329" aria-label="Postleitzahl">';
		$html .= '<button type="button" class="igm-gs-anfrage__find">Geschäftsstelle finden</button>';
		$html .= '</div>';
		$html .= '<div class="igm-gs-anfrage__result" role="status" hidden></div>';
		$html .= '<a class="igm-gs-anfrage__fallback" href="' . esc_url( self::gs_such_url() )
			. '" target="_blank" rel="noopener">Oder: zur Geschäftsstellensuche auf igmetall.de</a>';
		$html .= self::gs_modal_html( 'igm-gs' );
		$html .= '</div>';
		return $html;
	}

	/* ---------- Persönliche Daten für die Geschäftsstellen-Anfrage ---------- */

	/**
	 * Die Felder, die vor dem Absenden abgefragt werden.
	 *
	 * WARUM ES DIESEN SCHRITT GIBT: Bis dahin öffnete „Anfrage senden" eine Mail
	 * mit leeren Zeilen – „Name: ", „Anschrift: " – die im Mailprogramm
	 * auszufüllen waren. Wer sie am Telefon oder unterwegs öffnete, schickte sie
	 * oft so ab, wie sie war, und die Geschäftsstelle musste hinterhertelefonieren.
	 * Ein kurzes Formular davor stellt dieselben Fragen an der Stelle, an der
	 * ohnehin gerade jemand tippt.
	 *
	 * Die Beschriftung ist zugleich die Beschriftung in der Mail: Was im Formular
	 * „Mitgliedsnummer" heißt, heißt auch dort so. Zwei Listen wären zwei
	 * Wahrheiten.
	 *
	 * PFLICHT IST ALLES BIS AUF DIE MITGLIEDSNUMMER. Eine Anfrage, in der Name,
	 * Erreichbarkeit, Anschrift oder Betrieb fehlen, kostet die Geschäftsstelle
	 * einen Anruf – und der Anruf braucht wiederum die Nummer, die vielleicht
	 * auch fehlt. Die Mitgliedsnummer bleibt freiwillig: Sie steht auf einem
	 * Ausweis, den niemand vor sich liegen hat, und die Geschäftsstelle findet
	 * das Mitglied auch über den Namen.
	 *
	 * Ein leeres freiwilliges Feld fehlt in der Mail einfach – eine Zeile
	 * „Mitgliedsnummer: " ohne Inhalt sagt nichts, was das Fehlen der Zeile
	 * nicht auch sagt.
	 *
	 * „breit" heißt: eigene Zeile im zweispaltigen Dialog – Anschrift und
	 * Betriebsname sind zu lang für eine halbe.
	 *
	 * @return array je Feld: name, label, typ, pflicht, autocomplete, hinweis, breit
	 */
	public static function gs_felder() {
		return array(
			array( 'name' => 'vorname',  'label' => 'Vorname',         'typ' => 'text',  'pflicht' => true,  'autocomplete' => 'given-name' ),
			array( 'name' => 'nachname', 'label' => 'Name',            'typ' => 'text',  'pflicht' => true,  'autocomplete' => 'family-name' ),
			array( 'name' => 'email',    'label' => 'E-Mail',          'typ' => 'email', 'pflicht' => true,  'autocomplete' => 'email' ),
			array( 'name' => 'mobil',    'label' => 'Mobilnummer',     'typ' => 'tel',   'pflicht' => true,  'autocomplete' => 'tel' ),
			array( 'name' => 'adresse',  'label' => 'Adresse',         'typ' => 'text',  'pflicht' => true,  'autocomplete' => 'street-address', 'hinweis' => 'Straße, Hausnummer, PLZ, Ort', 'breit' => true ),
			array( 'name' => 'mitglied', 'label' => 'Mitgliedsnummer', 'typ' => 'text',  'pflicht' => false, 'hinweis' => 'steht auf deinem Mitgliedsausweis' ),
			array( 'name' => 'betrieb',  'label' => 'Betrieb',         'typ' => 'text',  'pflicht' => true,  'autocomplete' => 'organization', 'breit' => true ),
		);
	}

	/**
	 * Der Dialog, der sich bei „Anfrage senden" öffnet.
	 *
	 * Ein echtes <dialog> statt eines nachgebauten Kastens: Escape, der
	 * Fokusring innerhalb des Dialogs und das Abdunkeln dahinter kommen damit
	 * vom Browser und nicht aus Skriptcode, der sie nachahmt. Kennt ein Browser
	 * showModal() nicht, springt gs-anfrage.js auf den alten Weg zurück – Mail
	 * mit leeren Zeilen – statt gar nichts zu tun.
	 *
	 * ABGESCHICKT WIRD NICHTS AN DIESE WEBSITE. Das Formular hat kein action-
	 * Ziel; die Eingaben wandern ausschließlich in den Text der Mail, die sich
	 * danach im Mailprogramm öffnet. Genau das sagt der Hinweis am Ende auch den
	 * Besucher*innen – wer sieben Felder mit persönlichen Daten vor sich hat,
	 * darf wissen, wohin sie gehen.
	 *
	 * @param string $prefix Kennung für id/for – auf einer Reihenseite gibt es
	 *                       mehrere Anfragen auf einer Seite.
	 */
	public static function gs_modal_html( $prefix ) {
		$prefix = sanitize_html_class( $prefix );

		$html  = '<dialog class="igm-gs-modal" aria-labelledby="' . esc_attr( $prefix ) . '-modal-titel">';
		$html .= '<form method="dialog" class="igm-gs-modal__form">';
		$html .= '<h2 class="igm-gs-modal__titel" id="' . esc_attr( $prefix ) . '-modal-titel">Deine Daten für die Anfrage</h2>';
		$html .= '<p class="igm-gs-modal__intro">Damit deine Geschäftsstelle die Anmeldung bearbeiten kann, '
			. 'braucht sie ein paar Angaben. Sie werden in die E-Mail eingesetzt, die sich anschließend öffnet – '
			. 'abgeschickt wird sie erst von dir.</p>';

		$html .= '<div class="igm-gs-modal__felder">';
		foreach ( self::gs_felder() as $feld ) {
			$id = $prefix . '-f-' . $feld['name'];
			$html .= '<p class="igm-gs-modal__feld' . ( empty( $feld['breit'] ) ? '' : ' igm-gs-modal__feld--breit' ) . '">';
			$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $feld['label'] );
			$html .= $feld['pflicht']
				? ' <span class="igm-gs-modal__pflicht" aria-hidden="true">*</span>'
				: ' <span class="igm-gs-modal__optional">(freiwillig)</span>';
			$html .= '</label>';
			$html .= '<input type="' . esc_attr( $feld['typ'] ) . '" id="' . esc_attr( $id ) . '"'
				. ' name="' . esc_attr( $feld['name'] ) . '"'
				// Die Beschriftung reist am Feld mit: Das Skript baut daraus die
				// Zeilen der Mail und muss die deutschen Wörter nicht kennen.
				. ' data-bi-label="' . esc_attr( $feld['label'] ) . '"'
				. ( ! empty( $feld['autocomplete'] ) ? ' autocomplete="' . esc_attr( $feld['autocomplete'] ) . '"' : '' )
				. ( $feld['pflicht'] ? ' required' : '' )
				. ( ! empty( $feld['hinweis'] ) ? ' placeholder="' . esc_attr( $feld['hinweis'] ) . '"' : '' )
				. '>';
			$html .= '</p>';
		}
		$html .= '</div>';

		$html .= '<p class="igm-gs-modal__hinweis"><span aria-hidden="true">*</span> Pflichtangabe – ohne sie '
			. 'muss die Geschäftsstelle nachfragen. '
			. 'Deine Eingaben werden <strong>nicht an diese Website übertragen</strong> und hier auch nicht '
			. 'gespeichert – sie stehen gleich nur in deiner E-Mail.</p>';

		$html .= '<div class="igm-gs-modal__aktionen">';
		$html .= '<button type="button" class="igm-btn igm-btn--sek igm-btn--auto igm-gs-modal__abbrechen">Abbrechen</button>';
		$html .= '<button type="submit" class="igm-btn igm-btn--auto igm-gs-modal__absenden">Absenden</button>';
		$html .= '</div>';

		$html .= '</form>';
		$html .= '</dialog>';
		return $html;
	}

	/**
	 * data-Attribute, aus denen gs-anfrage.js die Mail zusammensetzt.
	 *
	 * DREI TEILE STATT EINES: Der Seminarteil steht fest, der Datenblock kommt
	 * aus dem Dialog, und dazwischen darf nichts verrutschen. Zusammengesetzt
	 * wird erst beim Absenden – vorher weiß niemand, was jemand eingibt.
	 *
	 *   data-bi-subject  Betreff
	 *   data-bi-body     Anrede + Angaben zum Seminar, endet mit einer Leerzeile
	 *   data-bi-daten    leerer Datenblock – der Rückfall für Browser ohne
	 *                    <dialog> und damit genau die Mail von früher
	 *   data-bi-gruss    Grußformel; das Skript hängt den eingegebenen Namen an
	 */
	private static function gs_mail_attrs( $post_id ) {
		$nr    = get_post_meta( $post_id, '_bi_seminarnummer', true );
		$titel = get_the_title( $post_id );
		$ort   = BI_CPT::ort_anzeige( $post_id );

		$zeitraum = self::zeitraum( $post_id );

		$subject = 'Seminaranmeldung ' . trim( ( $nr ? $nr . ' – ' : '' ) . $titel );

		$body  = "Guten Tag,\r\n\r\n";
		$body .= "hiermit möchte ich mich für folgendes Seminar anmelden:\r\n\r\n";
		$body .= 'Seminar: ' . $titel . "\r\n";
		if ( $nr ) {
			$body .= 'Seminarnummer: ' . $nr . "\r\n";
		}
		if ( $zeitraum ) {
			$body .= 'Zeitraum: ' . $zeitraum . "\r\n";
		}
		if ( bi_is_online( $post_id ) ) {
			$body .= 'Format: Online-Seminar' . ( $ort ? ' (' . $ort . ')' : '' ) . "\r\n";
		} elseif ( $ort ) {
			$body .= 'Bildungszentrum: ' . $ort . "\r\n";
		}
		$body .= 'Link: ' . get_permalink( $post_id ) . "\r\n\r\n";

		return ' data-bi-subject="' . esc_attr( $subject ) . '"'
			. ' data-bi-body="' . esc_attr( $body ) . '"'
			. self::gs_mail_rest_attrs();
	}

	/**
	 * Datenblock und Grußformel – für Seminar- und Reihenanfrage dieselben.
	 *
	 * Der leere Block ist der Rückfall: Er nennt dieselben Felder wie der Dialog,
	 * damit eine Mail, die ohne ihn zustande kommt, nicht plötzlich nach anderen
	 * Angaben fragt als das Formular.
	 */
	public static function gs_mail_rest_attrs() {
		$leer = "Meine Daten:\r\n";
		foreach ( self::gs_felder() as $feld ) {
			$leer .= $feld['label'] . ": \r\n";
		}

		return ' data-bi-daten="' . esc_attr( $leer ) . '"'
			. ' data-bi-gruss="' . esc_attr( "Viele Grüße\r\n" ) . '"';
	}

	/**
	 * Adresse der Geschäftsstellensuche auf igmetall.de – auch die Reihenseite
	 * braucht sie.
	 *
	 * Bewusst keine Einstellung mehr: Sie war nur noch der Ausweichlink unter
	 * der PLZ-Suche („Oder: zur Geschäftsstellensuche auf igmetall.de"), zeigt
	 * seit jeher auf dieselbe Seite und stand in den Einstellungen zwischen
	 * Texten, die man wirklich ändert. Wer sie doch umbiegen muss, hängt sich an
	 * den Filter.
	 */
	public static function gs_such_url() {
		return (string) apply_filters( 'bi_gs_suche_url', self::GS_SUCHE_URL );
	}

	/* ===================================================================
	 *  Weitere Termine
	 * =================================================================== */

	/**
	 * „Weitere Termine zu diesem Seminar" – gleicher Titel, alle noch
	 * anstehenden Termine desselben Beitragstyps.
	 *
	 * MASSSTAB IST HEUTE, NICHT DER GEZEIGTE TERMIN. Bis 1.84.0 standen hier nur
	 * Termine, die SPÄTER lagen als der gerade geöffnete. Wer über einen Link auf
	 * den Dezember-Termin kam, sah den freien im September nicht – obwohl der
	 * buchbar war und besser gepasst hätte. Aus einer Liste, die beim Vergleichen
	 * helfen soll, wurde so eine, die Möglichkeiten verschweigt.
	 *
	 * Zeilen statt Tabelle: Die Angaben sind kurz und immer dieselben vier, und
	 * eine Zeile darf ganz Link sein – in einer Tabelle wäre nur die
	 * Datumszelle klickbar gewesen.
	 *
	 * Alle Nummern werden in einem Zug vorgeladen, damit nicht je Zeile eine
	 * eigene Datenbank-Abfrage läuft.
	 */
	private static function weitere_termine( $post_id ) {
		global $wpdb;

		/*
		 * DER TITEL KOMMT ROH AUS DEM BEITRAG, NICHT AUS get_the_title().
		 *
		 * Hier wird gegen die Spalte `post_title` verglichen, und dort steht der
		 * Titel unbehandelt. get_the_title() dagegen schickt ihn durch den
		 * `the_title`-Filter, und darin sitzt wptexturize(): Aus „Arbeitszeit -
		 * Gestaltungsmöglichkeiten" wird „Arbeitszeit &#8211;
		 * Gestaltungsmöglichkeiten", aus geraden Anführungszeichen werden
		 * typografische, aus drei Punkten ein Auslassungszeichen.
		 *
		 * Verglichen wurde damit ein Text, der so in keiner Zeile steht – und
		 * zwar unbemerkt, weil die Abfrage nicht scheitert, sondern schlicht
		 * nichts findet. Die Seite ließ dann einfach den Abschnitt weg, als
		 * gäbe es keinen zweiten Termin. Betroffen war jeder Titel mit einem
		 * Zeichen, an dem wptexturize etwas zu tun hat – bei diesen Seminaren
		 * also fast jeder zweite, weil der Gedankenstrich im Titel die Regel
		 * ist. „Besser eingruppieren" funktionierte, „Arbeitszeit –
		 * Gestaltungsmöglichkeiten des Betriebsrats" nie.
		 *
		 * Dieselbe Falle ist in class-bi-filter.php beschrieben (siehe
		 * klartext()): Über die Anzeige geht der aufbereitete Titel, in einen
		 * Vergleich gehört der rohe.
		 */
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$post_type = $post->post_type;
		$title     = $post->post_title;

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title = %s AND ID <> %d",
			$post_type,
			$title,
			$post_id
		) );
		if ( empty( $ids ) ) {
			return '';
		}

		$today = current_time( 'Y-m-d' );

		// Alles, was ab heute noch stattfindet – auch Termine VOR dem gerade
		// gezeigten. Die Liste ist nach Startdatum sortiert; der frühere steht
		// dann oben, wo man ihn erwartet.
		$meta_query = array(
			BI_CPT::visible_clause(),
			array( 'key' => '_bi_startdatum', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
		);

		$q = new WP_Query( array(
			'post_type'      => $post_type,
			'post__in'       => array_map( 'intval', $ids ),
			'posts_per_page' => 30,
			'meta_key'       => '_bi_startdatum',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_type'      => 'DATE',
			'meta_query'     => $meta_query,
		) );
		if ( ! $q->have_posts() ) {
			return '';
		}

		// Erst alle Seminarnummern einsammeln und in einer Abfrage nachschlagen,
		// dann die Ampeln je Termin abholen.
		$nummern = array();
		foreach ( $q->posts as $p ) {
			$nummern[ $p->ID ] = (string) get_post_meta( $p->ID, '_bi_seminarnummer', true );
		}
		BI_Ampel::vorladen( array_values( $nummern ) );

		$ampeln = array();
		foreach ( $nummern as $id => $nr ) {
			$daten = BI_Ampel::fuer_nummer( $nr );
			if ( $daten ) {
				$ampeln[ $id ] = $daten;
			}
		}

		$zeilen = '';
		foreach ( $q->posts as $p ) {
			$zeilen .= self::termin_zeile( $p->ID, isset( $ampeln[ $p->ID ] ) ? $ampeln[ $p->ID ] : null );
		}

		$html = '<div class="igm-weitere-termine">';
		$html .= self::abschnitt(
			'Weitere Termine zu diesem Seminar',
			bi_is_online( $post_id )
				? 'Gleicher Inhalt, andere Woche'
				: 'Gleicher Inhalt, andere Woche oder anderes Bildungszentrum'
		);
		// Liegt für keinen Termin eine Verfügbarkeit vor, fällt die Spalte weg –
		// sonst stünde dort eine leere Spalte, die nur Platz kostet.
		$klasse = 'igm-termine igm-abschnitt__inhalt' . ( $ampeln ? '' : ' igm-termine--ohne-ampel' );
		$html  .= '<div class="' . $klasse . '">' . $zeilen . '</div>';
		if ( $ampeln ) {
			// Eine Ampel ohne Stand-Angabe wäre eine Behauptung ohne Datum.
			$erste = reset( $ampeln );
			$html .= '<p class="igm-termine__stand">Verfügbarkeit – Stand: ' . esc_html( $erste['stand'] ) . '</p>';
		}
		return $html . '</div>';
	}

	private static function term_list( $post_id, $tax, $sep = ', ' ) {
		$names = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'names' ) );
		return ( is_array( $names ) && $names ) ? implode( $sep, $names ) : '';
	}
}
