<?php
/**
 * Custom Post Type "bi_seminar" + Taxonomien + Editier-Oberfläche.
 *
 * Diese Klasse verwaltet die Präsenz-Seminare UND die gemeinsame Infrastruktur
 * beider Beitragstypen (Taxonomien, Metabox, Admin-Spalten). Die Online-Seminare
 * (BI_ONLINE) bringen ihr eigenes Feldset in class-bi-online.php mit; alle
 * Methoden hier nehmen deshalb einen optionalen $post_type entgegen.
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
 * Filterbare Facetten sind Taxonomien (echtes ODER über tax_query). Sie gelten
 * für BEIDE Beitragstypen und werden nur einmal registriert:
 *   bi_ort           Bildungszentrum / Seminarort bzw. Veranstalter*in
 *                    (Term-Meta "email" für Mail-Trigger)
 *   bi_handlungsfeld Themenfeld
 *   bi_zielgruppe    Zielgruppe (mehrfach)
 *   bi_freistellung  Freistellung (mehrfach)
 *   bi_programm      Programmjahr
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_CPT {

	/**
	 * Meta-Felder: key => [label, type]. Labels entsprechen den CSV-Spalten.
	 *
	 * Enthält die Kernfelder (unten) plus die in der Datenpflege angelegten
	 * eigenen Felder und die dort geänderten Beschriftungen. Alles, was von hier
	 * liest – Bearbeiten-Maske, CSV-Import, Export, Filter –, kennt eigene Felder
	 * dadurch automatisch.
	 *
	 * @param string $post_type BI_CPT (Standard) oder BI_ONLINE.
	 */
	public static function meta_fields( $post_type = BI_CPT ) {
		$fields = self::kernfelder( $post_type );
		// Die Auswahl der Anmeldeformulare steht in einer Option und kann sich
		// jederzeit ändern – sie gehört deshalb nicht in die feste Deklaration
		// oben, sondern wird hier eingesetzt. Ausbildungsreihen haben kein
		// eigenes Anmeldeformular; ihre Teile bringen es mit.
		if ( isset( $fields['_bi_formular'] ) ) {
			$fields['_bi_formular']['options'] = BI_Formulare::choices();
		}
		return BI_Felder::erweitern( $fields, $post_type );
	}

	/**
	 * Die vom Code vorausgesetzten Felder – ohne eigene Felder, ohne geänderte
	 * Beschriftungen. Ihre Schlüssel stehen an vielen Stellen im Code und dürfen
	 * sich deshalb nicht ändern (siehe class-bi-felder.php).
	 *
	 * @param string $post_type BI_CPT (Standard) oder BI_ONLINE.
	 */
	public static function kernfelder( $post_type = BI_CPT ) {
		if ( BI_ONLINE === $post_type ) {
			return BI_Online::meta_fields();
		}
		// Die Ausbildungsreihe nutzt dieselbe Metabox und dieselbe Speicherroutine,
		// bringt aber ihr eigenes Feldset mit.
		if ( BI_Reihen::CPT === $post_type ) {
			return BI_Reihen::meta_fields();
		}
		return array(
			'_bi_seminarnummer'  => array( 'label' => 'Seminarnummer', 'type' => 'text', 'gruppe' => 'termin' ),
			'_bi_startdatum'     => array( 'label' => 'Startdatum', 'type' => 'date', 'gruppe' => 'termin' ),
			'_bi_startuhrzeit'   => array( 'label' => 'Startuhrzeit', 'type' => 'time', 'gruppe' => 'termin' ),
			'_bi_enddatum'       => array( 'label' => 'Enddatum', 'type' => 'date', 'gruppe' => 'termin' ),
			'_bi_enduhrzeit'     => array( 'label' => 'Enduhrzeit', 'type' => 'time', 'gruppe' => 'termin' ),
			'_bi_anreisedatum'   => array( 'label' => 'Anreisedatum', 'type' => 'date', 'gruppe' => 'termin' ),
			'_bi_anreiseuhrzeit' => array( 'label' => 'Anreiseuhrzeit', 'type' => 'time', 'gruppe' => 'termin' ),

			'_bi_seminarort'     => array(
				'label'  => 'Seminarort',
				'type'   => 'text',
				'gruppe' => 'ort',
				'bulk'   => true,
				'hint'   => 'Wo das Seminar tatsächlich stattfindet – etwa ein Hotel. Bleibt das Feld leer, gilt das zuständige Bildungszentrum.',
			),

			// Steht bei der Gruppe „Ort", nicht bei „Kontakt": Die Adresse gehört
			// zum zuständigen Bildungszentrum, nicht zur Ansprechperson. Genau
			// diese Verwechslung soll die Aufteilung beenden.
			'_bi_bz_email'       => array(
				'label'  => 'E-Mail zuständiges Bildungszentrum',
				'type'   => 'email',
				'gruppe' => 'ort',
				'bulk'   => true,
				'hint'   => 'Wohin die Benachrichtigungen zu diesem Seminar gehen. Bleibt das Feld leer, gilt die Adresse, die am Begriff des zuständigen Bildungszentrums hinterlegt ist. Nicht zu verwechseln mit der E-Mail der Ansprechperson – die steuert keinen Versand.',
			),

			// Themen stehen in der Massenbearbeitung, aber nur bis BULK_TEXT_MAX
			// markierte Einträge (siehe bulk_text_erlaubt): Alle Termine eines
			// Teils tragen denselben Text – ihn einmal für die Handvoll Termine
			// eines Teils zu setzen ist der Normalfall, ihn für dreihundert
			// Termine zu setzen wäre einer.
			'_bi_themen'         => array( 'label' => 'Themen im Seminar', 'type' => 'html', 'gruppe' => 'inhalt', 'bulk' => true, 'bulk_max' => true ),

			'_bi_kosten_seminar' => array( 'label' => 'Seminarkosten', 'type' => 'money', 'gruppe' => 'kosten', 'bulk' => true ),

			/*
			 * UNTERKUNFT UND VERPFLEGUNG SIND ZWEI POSTEN, NICHT EINER.
			 *
			 * Bis 1.117.0 hieß ein Feld „Übernachtung und Verpflegung" – und
			 * versprach damit mehr, als drinstand: Der Betrag war IMMER nur der
			 * für die Unterkunft. Die Verpflegung fehlte schlicht, sie wurde
			 * nirgends erfasst.
			 *
			 * Die Beschriftung war also nicht bloß ungenau, sie war falsch. Wer
			 * die Kostenaufstellung las, hielt das Essen für bezahlt.
			 *
			 * DER ALTBESTAND IST KEINE SUMME. Das ist die Angabe, auf die es
			 * beim nächsten Umbau ankommt: Aus dem Wert in `_bi_kosten_uev` ist
			 * nichts herauszurechnen, wenn die Verpflegung danebentritt – er
			 * war und bleibt der reine Unterkunftsbetrag.
			 *
			 * Seit 1.118.0 sind es zwei Felder. Der SCHLÜSSEL des ersten bleibt
			 * `_bi_kosten_uev`, obwohl er jetzt „Unterkunft" heißt – aus einem
			 * Grund, der schwerer wiegt als der schiefe Name:
			 *
			 *   Drei Installationen gleichen ihre Seminare miteinander ab
			 *   (siehe class-bi-sync.php), und sie werden nicht am selben Tag
			 *   aktualisiert. Ein umbenannter Schlüssel wäre für die noch nicht
			 *   aktualisierte Gegenstelle ein unbekanntes Feld – sie lieferte
			 *   dafür nichts, und der Abgleich schriebe einen gepflegten Betrag
			 *   mit Leere über. Ein neuer Schlüssel für einen NEUEN Posten ist
			 *   dagegen harmlos: Was die Gegenstelle nicht kennt, hat dort auch
			 *   niemand gepflegt.
			 *
			 * Der Wert bleibt also stehen, wo er steht, und heißt ab sofort so,
			 * wie er immer gemeint war: Unterkunft.
			 *
			 * DIE VERPFLEGUNG IST VORERST LEER, UND DAS SOLL SIE SEIN. Für die
			 * laufenden Jahrgänge gibt es die Zahlen nicht – sie wurden nie
			 * erfasst, es ist also nichts nachzutragen. Ab dem Programm 2028
			 * bringt die Exportdatei eine eigene Spalte „Verpflegung" mit, die
			 * der Seminar-Import von allein hierher legt (siehe die
			 * Spalten-Aliasse in BI_Import::guess_mapping). Bis dahin fällt der
			 * Posten aus der Kostenaufstellung heraus, wie jeder leere Posten –
			 * er taucht nirgends als „0,00 €" auf.
			 */
			'_bi_kosten_uev'          => array( 'label' => 'Unterkunft', 'type' => 'money', 'gruppe' => 'kosten', 'bulk' => true ),
			'_bi_kosten_verpflegung'  => array( 'label' => 'Verpflegung', 'type' => 'money', 'gruppe' => 'kosten', 'bulk' => true ),

			'_bi_kosten_tagung'  => array( 'label' => 'Tagungspauschale', 'type' => 'money', 'gruppe' => 'kosten', 'bulk' => true ),
			'_bi_kosten_kur'     => array( 'label' => 'Kurbeitrag', 'type' => 'money', 'gruppe' => 'kosten', 'bulk' => true ),
			'_bi_kosten_mwst'    => array(
				'label'  => 'MwSt.',
				'type'   => 'money',
				'gruppe' => 'kosten',
				'bulk'   => true,
				'hint'   => 'Betrag in Euro, nicht der Prozentsatz.',
			),
			'_bi_kosten'         => array(
				'label'  => 'Kosten / Hinweis',
				'type'   => 'text',
				'gruppe' => 'kosten',
				'bulk'   => true,
				'hint'   => 'Freitext für Angaben, die keine Zahl sind – etwa „Kostenübernahme durch den Arbeitgeber".',
			),

			'_bi_plaetze'         => array( 'label' => 'Freie Plätze', 'type' => 'number', 'gruppe' => 'teilnahme', 'bulk' => true ),
			'_bi_kinderbetreuung' => array(
				'label'   => 'Kinderbetreuung wird angeboten',
				'type'    => 'bool',
				'default' => false,
				'gruppe'  => 'teilnahme',
				'bulk'   => true,
			),
			// Flags – Default = Verhalten bei leerer CSV-Zelle
			'_bi_anmeldung_moeglich' => array( 'label' => 'Anmeldung möglich', 'type' => 'bool', 'default' => true, 'gruppe' => 'teilnahme', 'bulk' => true ),
			'_bi_ausgebucht'         => array( 'label' => 'Ausgebucht', 'type' => 'bool', 'default' => false, 'gruppe' => 'teilnahme', 'bulk' => true ),
			'_bi_anzeigen'           => array( 'label' => 'Auf der Website anzeigen', 'type' => 'bool', 'default' => true, 'gruppe' => 'teilnahme', 'bulk' => true ),

			// Ausnahme von der Zuordnung: Welches Anmeldeformular dieses Seminar
			// benutzt. Leer = die Regeln in „Anmeldeformulare → Zuordnung"
			// entscheiden, und wenn keine greift, das Standardformular.
			'_bi_formular'           => array(
				'label'   => 'Anmeldeformular',
				'type'    => 'select',
				'gruppe'  => 'teilnahme',
				'bulk'    => true,
				'options' => array(), // wird in meta_fields() gefüllt – die Formulare stehen in einer Option
				'hint'    => 'Leer lassen heißt: Es gilt die Zuordnung aus den Regeln.',
			),

			/*
			 * ZWEI ADRESSEN AM SEMINAR, UND SIE HABEN VERSCHIEDENE AUFGABEN.
			 *
			 * Bis Version 1.115.0 gab es nur eine: `_bi_ansprechpartner_email`.
			 * Sie hieß „E-Mail Ansprechpartner", trug aber in Wahrheit die
			 * Zustelladresse des Bildungszentrums – dorthin gingen die
			 * Benachrichtigungen. Wer die Beschriftung las, hielt sie für die
			 * persönliche Adresse einer Ansprechperson und trug irgendwann eine
			 * solche ein. Damit landeten Anmeldungen in einem persönlichen
			 * Postfach statt in dem der Anmeldestelle – und niemand konnte am
			 * Feld sehen, dass das ein Fehler war.
			 *
			 * Seither sind es zwei Felder mit klaren Rollen:
			 *
			 *   _bi_bz_email                → WOHIN die Post geht. Nur dieses
			 *                                 Feld steuert Benachrichtigungen.
			 *   _bi_ansprechpartner_email   → WEN Interessierte fragen können.
			 *                                 Steht auf der Detailseite, ist für
			 *                                 den Versand ohne jede Bedeutung.
			 *
			 * Das Telefon gehört zur zweiten Gruppe: eine Kontaktangabe für
			 * Besucher, kein Zustellweg.
			 */
			'_bi_ansprechpartner'         => array( 'label' => 'Ansprechpartner', 'type' => 'text', 'gruppe' => 'kontakt', 'bulk' => true ),
			'_bi_ansprechpartner_email'   => array( 'label' => 'E-Mail Ansprechpartner', 'type' => 'email', 'gruppe' => 'kontakt', 'bulk' => true ),
			'_bi_ansprechpartner_telefon' => array( 'label' => 'Telefon Ansprechpartner', 'type' => 'text', 'gruppe' => 'kontakt', 'bulk' => true ),

			'_bi_teil_reihe'     => array(
				'label'  => 'Teil | Reihe',
				'type'   => 'text',
				'gruppe' => 'programm',
				'hint'   => 'Zuordnung zu einer Ausbildungsreihe: „Teil 2 | Name der Reihe". Feste Gruppen mit „Reihe 1 - Teil 2 | …". Links nur die Nummern, rechts der Reihenname – zeichengleich bei allen Terminen derselben Reihe.',
			),
		);
	}

	/**
	 * Abschnitte der Bearbeiten-Maske: Schlüssel => Überschrift.
	 *
	 * Die Maske war mit den Feldern des Programms 2027 auf über zwanzig flache
	 * Eingaben angewachsen, und die filterbaren Merkmale standen zusätzlich in
	 * eigenen Kästen daneben. Beides zusammen ergab eine Oberfläche, die man
	 * kennen musste, um sie zu bedienen. Jedes Feld und jede Taxonomie trägt
	 * deshalb einen Abschnitt („gruppe"); leere Abschnitte entfallen bei der
	 * Ausgabe, sodass dieselbe Liste für beide Seminarformen taugt.
	 */
	public static function feld_gruppen( $post_type = BI_CPT ) {
		if ( BI_Reihen::CPT === $post_type ) {
			return array(
				'reihe'   => 'Angaben für die ganze Reihe',
				'weitere' => 'Weitere Angaben',
			);
		}
		return array(
			'termin'    => 'Termin und Zeiten',
			'ort'       => 'Ort',
			'zugang'    => 'Zugang und Anmeldung',
			'inhalt'    => 'Inhalt und Einordnung',
			'kosten'    => 'Kosten',
			'teilnahme' => 'Teilnahme und Sichtbarkeit',
			'kontakt'   => 'Kontakt',
			'programm'  => 'Programm und Reihe',
			'weitere'   => 'Weitere Angaben',
		);
	}

	/* ---------- Bildungszentren ---------- */

	/** Sammelbegriff für alles, was kein Bildungszentrum ist. */
	const ANDERE = 'Andere';

	/**
	 * Erkennungswörter der Bildungszentren – normalisiert (klein, ohne
	 * Sonderzeichen, Umlaute aufgelöst wie in bz_norm()).
	 *
	 * Warum eine Liste und nicht die Prüfung auf das Wort „Bildungszentrum"?
	 * Weil die **Kritische Akademie** eines ist, ohne es im Namen zu führen –
	 * und weil umgekehrt „DGB Tagungszentrum Hattingen" keines ist. Der Name
	 * trägt die Information also nicht.
	 *
	 * Geprüft wird auf Enthaltensein, damit „Bildungszentrum Berlin",
	 * „Berlin-Pichelssee" und „Berlin" gleichermaßen treffen. Ein Hotel mit einem
	 * dieser Wörter im Namen würde damit fälschlich als Bildungszentrum gelten;
	 * in den vorliegenden Daten kommt das nicht vor. Über den Filter
	 * `bi_bildungszentren` lässt sich die Liste anpassen.
	 */
	public static function bildungszentren() {
		return (array) apply_filters( 'bi_bildungszentren', array(
			'Bildungszentrum Sprockhövel' => array( 'sprockhovel' ),
			'Bildungszentrum Berlin'      => array( 'berlin' ),
			'Bildungszentrum Beverungen'  => array( 'beverungen' ),
			'Bildungszentrum Bad Orb'     => array( 'badorb' ),
			'Bildungszentrum Lohr'        => array( 'lohr' ),
			'Bildungszentrum Schliersee'  => array( 'schliersee' ),
			// Zwei Erkennungswörter, ein Haus: „Inzell" und „Kritische Akademie"
			// meinen dasselbe und dürfen nicht zu zwei Zentren werden.
			'Kritische Akademie Inzell'   => array( 'kritischeakademie', 'inzell' ),
		) );
	}

	/**
	 * Zu welchem Bildungszentrum gehört dieser Ortsname? Leerer String, wenn zu
	 * keinem.
	 *
	 * Damit lassen sich auch die Schreibweisen zusammenführen, die über die
	 * Jahrgänge entstanden sind: „Sprockhövel", „Bildungszentrum Sprockhövel",
	 * „IG Metall-Bildungszentrum Sprockhövel" und „IG Metall Bildungszentrum
	 * Sprockhövel und Bologna" liefern alle denselben kanonischen Namen.
	 *
	 * Enthält ein Name die Wörter zweier Zentren, gewinnt das in der Liste
	 * zuerst genannte. In den vorliegenden Daten kommt das nicht vor.
	 */
	public static function zentrum_fuer( $name ) {
		$norm = self::bz_norm( $name );
		if ( '' === $norm || self::bz_norm( self::ANDERE ) === $norm ) {
			return '';
		}
		foreach ( self::bildungszentren() as $kanonisch => $woerter ) {
			foreach ( (array) $woerter as $wort ) {
				if ( false !== strpos( $norm, $wort ) ) {
					return (string) $kanonisch;
				}
			}
		}
		return '';
	}

	/** Vergleichsform eines Ortsnamens: klein, ohne Umlaute und Sonderzeichen. */
	private static function bz_norm( $name ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $name ) : strtolower( (string) $name );
		$s = strtr( $s, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss' ) );
		return (string) preg_replace( '/[^a-z0-9]/', '', $s );
	}

	/**
	 * Ist dieser Ort ein Bildungszentrum? Alles andere – Hotels,
	 * Tagungshäuser, „auf Anfrage" – wird unter ANDERE zusammengefasst, damit
	 * der Filter-Chip nicht mit Einzelfällen zuwächst.
	 */
	public static function ist_bildungszentrum( $name ) {
		return '' !== self::zentrum_fuer( $name );
	}

	/**
	 * Der Ort, der einem Menschen angezeigt wird.
	 *
	 * Vorrang hat der tatsächliche Veranstaltungsort aus `_bi_seminarort`. Fehlt
	 * er, tritt das zuständige Bildungszentrum an seine Stelle – außer es ist der
	 * Sammelbegriff „Andere". Der ist eine Ordnungshilfe für den Filter und als
	 * Ortsangabe wertlos: „Wo findet das Seminar statt?" – „Andere." Dann lieber
	 * keine Angabe.
	 *
	 * Diese eine Stelle gilt für Detailseite, Termintabellen, Ergebnisliste,
	 * Reihenseite und PDF. Vorher stand die Regel an fünf Orten – und an dreien
	 * davon fehlte sie, weshalb dort „Andere" als Ort auftauchte.
	 */
	public static function ort_anzeige( $post_id ) {
		$ort = trim( (string) get_post_meta( $post_id, '_bi_seminarort', true ) );
		if ( '' !== $ort ) {
			return $ort;
		}
		$terms = wp_get_object_terms( $post_id, BI_TAX_ORT, array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$name = trim( (string) $terms[0] );
		return ( self::ANDERE === $name ) ? '' : $name;
	}

	/**
	 * Freistellungen in der Reihenfolge, in der sie einem Menschen gezeigt
	 * werden – Detailseite und Seminardetails-PDF.
	 *
	 * Die Datenbank liefert die Begriffe alphabetisch, und alphabetisch steht
	 * „Bildungsurlaub" vor jedem Paragrafen. Fachlich ist es andersherum:
	 * § 37,6 BetrVG ist der Regelfall der Betriebsratsarbeit, § 179,4 SGB IX
	 * sein Gegenstück für die Schwerbehindertenvertretung, dann Bildungsurlaub
	 * bzw. Bildungszeit, dann § 37,7 BetrVG. Alles andere hängt sich dahinter
	 * an, alphabetisch, damit die Ausgabe bei gleichen Daten gleich bleibt.
	 *
	 * Fest verdrahtet mit Absicht: Die Reihenfolge ist eine Aussage über das
	 * Betriebsverfassungsrecht, keine Redaktionsentscheidung. Verglichen wird
	 * über BI_Settings::norm() und „enthält", damit „§ 37,6 BetrVG",
	 * „§ 37 Abs. 6 BetrVG" und „§ 37(6) BetrVG" dieselbe Stufe treffen – an
	 * genau dieser Schreibweise ist der Vergleich im Regelwerk schon einmal
	 * auseinandergelaufen.
	 *
	 * @param string[] $names Begriffsnamen, wie wp_get_object_terms sie liefert.
	 * @return string[] Dieselben Namen, sortiert.
	 */
	public static function frei_sortiert( $names ) {
		$names = array_values( (array) $names );
		if ( count( $names ) < 2 ) {
			return $names;
		}

		// Je Stufe alle Schreibweisen, die dort landen sollen. „Bildungsurlaub"
		// findet auch „Bildungsurlaub/-zeit"; „Bildungszeit" steht daneben,
		// weil manche Länder das Wort ohne „Urlaub" führen.
		$stufen = array(
			array( '§ 37,6 BetrVG' ),
			array( '§ 179,4 SGB IX' ),
			array( 'Bildungsurlaub', 'Bildungszeit' ),
			array( '§ 37,7 BetrVG' ),
		);
		$hinten = count( $stufen );

		$rang = function ( $name ) use ( $stufen, $hinten ) {
			$key = BI_Settings::norm( $name );
			foreach ( $stufen as $i => $schreibweisen ) {
				foreach ( $schreibweisen as $s ) {
					if ( false !== strpos( $key, BI_Settings::norm( $s ) ) ) {
						return $i;
					}
				}
			}
			return $hinten;
		};

		usort( $names, function ( $a, $b ) use ( $rang ) {
			$ra = $rang( $a );
			$rb = $rang( $b );
			return ( $ra === $rb ) ? strcasecmp( $a, $b ) : $ra - $rb;
		} );
		return $names;
	}

	/**
	 * Welche Begriffe im Auswahlfeld „Zuständiges Bildungszentrum" stehen dürfen.
	 *
	 * Bei Präsenz-Seminaren führt bi_ort das zuständige Bildungszentrum. Hotels,
	 * Tagungshäuser und Verlegenheitseinträge („auf Anfrage", „Hotel", „Online")
	 * stehen aus den alten Jahrgängen noch als Begriffe in der Datenbank, gehören
	 * aber ins Feld „Seminarort". Blieben sie zur Auswahl, entstünde der
	 * Mischmasch bei jeder Bearbeitung neu.
	 *
	 * Ein bereits gesetzter Begriff bleibt trotzdem in der Liste, auch wenn er
	 * nicht dazugehört: Sonst stünde beim Öffnen der Maske ein anderer Wert da
	 * als in der Datenbank, und das nächste Speichern würde ihn still ersetzen.
	 * Er wird stattdessen zurückgemeldet, damit die Maske ihn kennzeichnen kann.
	 *
	 * @param array $terms Alle Begriffe der Taxonomie.
	 * @param array $aktiv term_ids, die diesem Beitrag zugeordnet sind.
	 * @return array [ gefilterte Begriffe, term_id => Name der fremden Einträge ]
	 */
	public static function ort_auswahl( $terms, $aktiv ) {
		$fremd = array();
		$aktiv = array_map( 'intval', (array) $aktiv );

		$gefiltert = array_values( array_filter( $terms, function ( $t ) use ( $aktiv, &$fremd ) {
			if ( self::ist_bildungszentrum( $t->name ) || self::ANDERE === $t->name ) {
				return true;
			}
			if ( in_array( (int) $t->term_id, $aktiv, true ) ) {
				$fremd[ (int) $t->term_id ] = $t->name;
				return true;
			}
			return false;
		} ) );

		return array( $gefiltert, $fremd );
	}

	/* ---------- Geldbeträge ---------- */

	/**
	 * Eine Geldangabe auf einen reinen Dezimalwert bringen („€ 1.083,50" -> „1083.5").
	 * Leere oder unbrauchbare Eingaben ergeben einen leeren String.
	 *
	 * Die Tücke steckt im einzelnen Punkt: Im Programmheft steht „1.083" für
	 * tausenddreiundachtzig, in einer maschinell erzeugten Datei bedeutet
	 * „1083.50" dagegen Nachkommastellen. Unterschieden wird an der Stellenzahl
	 * hinter dem letzten Punkt – drei Stellen sind ein Tausenderpunkt. Ein Betrag
	 * wie „0.500" (fünfhundert Tausendstel) käme damit falsch an; in Euro-Preisen
	 * kommt er nicht vor.
	 */
	public static function money_parse( $raw ) {
		$s = trim( (string) $raw );
		if ( '' === $s ) {
			return '';
		}
		$s = preg_replace( '/[^0-9,.\-]/u', '', $s ); // Währungszeichen, Leerzeichen, Text weg
		if ( '' === $s || '-' === $s ) {
			return '';
		}

		$hat_komma = false !== strpos( $s, ',' );
		$hat_punkt = false !== strpos( $s, '.' );

		if ( $hat_komma && $hat_punkt ) {
			// Deutsches Format: Punkt trennt Tausender, Komma die Nachkommastellen.
			$s = str_replace( array( '.', ',' ), array( '', '.' ), $s );
		} elseif ( $hat_komma ) {
			$s = str_replace( ',', '.', $s );
		} elseif ( $hat_punkt ) {
			$teile = explode( '.', $s );
			if ( 3 === strlen( (string) end( $teile ) ) ) {
				$s = implode( '', $teile );
			}
		}

		return is_numeric( $s ) ? (string) round( (float) $s, 2 ) : '';
	}

	/** Gespeicherten Betrag für die Anzeige formatieren („1083.5" -> „1.083,50 €"). */
	public static function money_format( $val ) {
		if ( '' === trim( (string) $val ) || ! is_numeric( $val ) ) {
			return '';
		}
		return number_format_i18n( (float) $val, 2 ) . ' €';
	}

	/* ---------- Aufgeschlüsselte Kosten (ab Programm 2027) ---------- */

	/** Die Kostenposten in der Reihenfolge, in der sie in der Aufstellung stehen. */
	public static function kosten_felder() {
		return array(
			'_bi_kosten_seminar',
			'_bi_kosten_uev',          // Unterkunft – der Schlüssel ist älter als die Beschriftung
			'_bi_kosten_verpflegung',
			'_bi_kosten_tagung',
			'_bi_kosten_kur',
			'_bi_kosten_mwst',
		);
	}

	/**
	 * Gepflegte Kostenposten eines Seminars: Schlüssel => [label, betrag].
	 * Leere Posten fallen raus – eine Aufstellung mit „0,00 €"-Zeilen für nicht
	 * erhobene Beiträge wäre irreführend.
	 */
	public static function kosten_posten( $post_id ) {
		$fields = self::meta_fields( get_post_type( $post_id ) ?: BI_CPT );
		$out    = array();
		foreach ( self::kosten_felder() as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}
			$val = get_post_meta( $post_id, $key, true );
			if ( '' === trim( (string) $val ) || ! is_numeric( $val ) ) {
				continue;
			}
			$out[ $key ] = array(
				'label'  => $fields[ $key ]['label'],
				'betrag' => (float) $val,
			);
		}
		return $out;
	}

	/* ---------- Kosten aus Freitext (Jahrgänge vor 2027) ---------- */

	/**
	 * Posten, die in einem Kosten-Freitext erkannt werden.
	 *
	 * Bewusst eine Erlaubnisliste: Sie entscheidet, was als Kostenposten gilt
	 * und was Fließtext bleibt. „Seminarkostenpauschale € 660 zzgl. …" darf
	 * NICHT als Posten „Seminarkosten 660 €" enden – deshalb steht hinter jedem
	 * Namen im Muster eine Wortgrenze.
	 */
	private static function kosten_begriffe() {
		return array(
			'Seminarkosten', 'Seminargebühren', 'Seminargebühr',
			'Übernachtungen', 'Übernachtung', 'Unterkunft',
			'Verpflegung', 'Tagungspauschale', 'Kurbeitrag', 'Kurtaxe',
		);
	}

	/**
	 * Kostenposten aus einem Freitext lesen.
	 *
	 * ========================================================================
	 *  WARUM DAS SEIN MUSS
	 * ========================================================================
	 *  Ab Programm 2027 liegen die Kosten in eigenen Feldern und lassen sich als
	 *  Aufstellung zeigen. Die Jahrgänge davor haben alles in EINEM Freitextfeld
	 *  – in rund 70 verschiedenen Schreibweisen, die sich aber auf drei Muster
	 *  zurückführen lassen:
	 *
	 *    Seminarkosten: 1.460,-- € (USt.-frei) Unterkunft: 592,-- € (zzgl. USt.)
	 *    Übernachtung 600,00 € zzgl Ust.Verpflegung 475,00 € zzgl Ust.
	 *    Kat: E5 TageÜbernachtung 600,- € zzgl 7% Ust.Verpflegung 475,- € …
	 *
	 *  Der Rest ist echter Fließtext: „kostenfrei", „siehe Teil 1",
	 *  „€ 6.542 (Seminargebühr Teil 1-3), zzgl. Unterkunft + Verpflegung".
	 *
	 * ========================================================================
	 *  IM ZWEIFEL NICHTS TUN
	 * ========================================================================
	 *  Ein Betrag ist eine Zusage. Lieber bleibt ein Text unaufgeschlüsselt
	 *  stehen, als dass aus „€ 6.542 (Seminargebühr Teil 1-3)" ein Posten
	 *  „Seminargebühr 1,00 €" wird. Deshalb zwei harte Bedingungen:
	 *
	 *    1. Der Betrag muss DIREKT hinter dem Begriff stehen (nur „:" und
	 *       Leerraum dazwischen).
	 *    2. Er muss von einem Währungszeichen begleitet sein.
	 *
	 *  Was danach übrig bleibt – „Kat: E5 Tage" – wandert unverändert in den
	 *  Resttext und wird unter der Aufstellung gezeigt.
	 *
	 * @return array [ 'posten' => [ [label, betrag, hinweis], … ], 'rest' => string ]
	 */
	public static function kosten_aus_text( $text ) {
		$leer = array( 'posten' => array(), 'rest' => '' );

		// Geschützte Leerzeichen und Zeilenumbrüche gleichmachen – sie stehen in
		// den Quelldaten mitten in den Beträgen.
		$text = str_replace( array( "\xc2\xa0", "\r\n", "\r", "\n" ), ' ', (string) $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			return $leer;
		}

		$begriffe = array_map( 'preg_quote', self::kosten_begriffe(), array_fill( 0, count( self::kosten_begriffe() ), '/' ) );
		$muster   = '/(' . implode( '|', $begriffe ) . ')(?!\p{L})/ui';

		if ( ! preg_match_all( $muster, $text, $treffer, PREG_OFFSET_CAPTURE ) ) {
			return array( 'posten' => array(), 'rest' => $text );
		}

		$posten = array();
		$reste  = array();

		// Alles vor dem ersten Begriff ist Vorspann („Kat: E5 Tage").
		$erste = (int) $treffer[0][0][1];
		if ( $erste > 0 ) {
			$reste[] = substr( $text, 0, $erste );
		}

		$anzahl = count( $treffer[0] );
		for ( $i = 0; $i < $anzahl; $i++ ) {
			$name  = $treffer[1][ $i ][0];
			$start = (int) $treffer[0][ $i ][1] + strlen( $treffer[0][ $i ][0] );
			$ende  = ( $i + 1 < $anzahl ) ? (int) $treffer[0][ $i + 1 ][1] : strlen( $text );
			$stück = substr( $text, $start, $ende - $start );

			// Betrag direkt hinter dem Begriff, mit Währungszeichen davor oder
			// dahinter. „1.460,--", „600,00", „890,–" und „475,-" meinen dasselbe.
			//
			// Hinter dem € steht bewusst KEIN \b: Das Eurozeichen ist kein
			// Wortzeichen, und was folgt, ist ein Leerzeichen – zwischen zwei
			// Nicht-Wortzeichen gibt es keine Wortgrenze, die Bedingung wäre also
			// nie erfüllt.
			$betrag = '/^[\s:]*(?:(€|EUR)\s*)?(\d{1,3}(?:\.\d{3})+|\d+)(?:,(\d{2}|-{1,2}|–|—))?\s*(€|EUR)?/u';
			if ( ! preg_match( $betrag, $stück, $m )
				|| ( '' === ( $m[1] ?? '' ) && '' === ( $m[4] ?? '' ) ) ) {
				// Kein Betrag: Der Begriff ist hier Teil eines Satzes
				// („zzgl. Unterkunft und Verpflegung") und bleibt Fließtext.
				$reste[] = $name . $stück;
				continue;
			}

			$zahl  = str_replace( '.', '', $m[2] );
			$cent  = ( isset( $m[3] ) && preg_match( '/^\d{2}$/', $m[3] ) ) ? $m[3] : '00';
			$wert  = (float) ( $zahl . '.' . $cent );

			// Klammern und Trennzeichen weg, der Punkt bleibt: „zzgl. USt." ist
			// eine Abkürzung, „zzgl. USt" wäre falsch geschrieben.
			$hinweis = trim( substr( $stück, strlen( $m[0] ) ) );
			$hinweis = trim( $hinweis, " \t,;:()" );

			$posten[] = array(
				'label'   => $name,
				'betrag'  => $wert,
				'hinweis' => $hinweis,
			);
		}

		return array(
			'posten' => $posten,
			'rest'   => trim( preg_replace( '/\s+/u', ' ', implode( ' ', $reste ) ) ),
		);
	}

	/**
	 * Die Preiskategorie aus einem Kostentext nehmen („Kat: D5 Tage").
	 *
	 * Sie ist eine Angabe der internen Kalkulation: Buchstabe für die
	 * Kostenkategorie, Zahl für die Seminartage. Auf der Detailseite beantwortet
	 * sie keine Frage – die Dauer steht im Kennzahlenband, der Preis in der
	 * Aufstellung darüber –, und wer sie nicht kennt, hält „D5" womöglich für
	 * einen Raum oder einen Tarif.
	 *
	 * Der Parser lässt sie bewusst stehen (siehe kosten_aus_text): Er trennt
	 * Beträge von Text, ohne zu bewerten. Weggelassen wird erst bei der Ausgabe.
	 *
	 * Erkannt werden „Kat: E5 Tage", „Kat. D", „Kategorie A". Der Buchstabe muss
	 * für sich stehen – sonst risse „Kategorie Bildungsurlaub" das B heraus und
	 * ließe „ildungsurlaub" übrig.
	 */
	public static function ohne_kategorie( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return '';
		}
		$text = preg_replace(
			'/\bKat(?:egorie)?\s*[.:]?\s*[A-Z](?!\p{L})\s*(?:\d+\s*Tage?)?/u',
			' ',
			$text
		);
		// Zurück bleibt manchmal ein Trennzeichen ohne Inhalt davor.
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( $text, " \t,;:-–—" );
	}

	/**
	 * Summe der aufgeschlüsselten Kosten. null, wenn kein einziger Posten gepflegt
	 * ist – dann gilt weiterhin das Freitextfeld „Kosten / Hinweis".
	 */
	public static function gesamtkosten( $post_id ) {
		$posten = self::kosten_posten( $post_id );
		if ( ! $posten ) {
			return null;
		}
		$summe = 0.0;
		foreach ( $posten as $p ) {
			$summe += $p['betrag'];
		}
		return round( $summe, 2 );
	}

	/** Bool-Meta mit Feld-Default lesen (leer/ungesetzt -> Default aus meta_fields) */
	public static function meta_bool( $post_id, $key ) {
		$fields = self::meta_fields( get_post_type( $post_id ) ?: BI_CPT );
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
	 * Direkt über das eigene Formular buchbar? Berücksichtigt die Regel-Engine
	 * (BI_Settings::variant_for), den Ausgebucht-Status und bei Online-Seminaren
	 * zusätzlich die Weiche auf eine externe Anmeldeseite (Teams-Webinar).
	 */
	public static function is_bookable( $post_id ) {
		if ( self::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return false;
		}
		if ( BI_ONLINE === get_post_type( $post_id ) ) {
			return 'direct' === BI_Online::variante( $post_id );
		}
		return 'direct' === BI_Settings::variant_for( $post_id );
	}

	/**
	 * meta_query-Fragment: nur sichtbare Seminare (Anzeigen != nein, oder Meta fehlt).
	 *
	 * ============================================================================
	 *  NUR FÜR KLEINE ABFRAGEN – SONST sichtbar_arg() BENUTZEN
	 * ============================================================================
	 *  Dieses Fragment ist fachlich richtig und für die Datenbank teuer. Das
	 *  ODER zwingt WordPress, den meta_key aus der ON-Bedingung zu nehmen und
	 *  postmeta ZWEIMAL zusätzlich anzuhängen – einmal davon ohne jede
	 *  Einschränkung. Aus 2.400 Seminaren werden so über 40.000 Zwischenzeilen,
	 *  die ein GROUP BY danach wieder einsammelt.
	 *
	 *  Gemessen an der echten Datenbank (2.400 Seminare, 51.000 Meta-Zeilen):
	 *
	 *      mit diesem Fragment      117 ms
	 *      mit sichtbar_arg()         3 ms
	 *
	 *  Auf der Seminarsuche lief das dreimal je Seitenaufruf. Für eine Abfrage
	 *  über zwei Dutzend Reihen ist das Fragment weiterhin völlig in Ordnung –
	 *  für eine Liste über den ganzen Bestand nicht.
	 */
	public static function visible_clause() {
		return array(
			'relation' => 'OR',
			array( 'key' => '_bi_anzeigen', 'value' => '1' ),
			array( 'key' => '_bi_anzeigen', 'compare' => 'NOT EXISTS' ),
		);
	}

	/**
	 * Dasselbe als Abfrage-Argument – als korrelierter Unterabfrage statt als JOIN.
	 *
	 * In die WP_Query-Argumente mischen:
	 *
	 *     $args = array_merge( $args, BI_CPT::sichtbar_arg() );
	 *
	 * ZEICHENGLEICHE BEDEUTUNG: „Es gibt keine Zeile _bi_anzeigen mit einem
	 * anderen Wert als 1" ist genau „Wert ist 1 ODER es gibt keine Zeile" –
	 * durchgespielt für alle vier Fälle (keine Zeile, '1', '0', irgendetwas
	 * anderes). Der Unterschied steckt allein darin, wie die Datenbank es
	 * ausrechnet: über den post_id-Index statt über zwei zusätzliche JOINs.
	 *
	 * ACHTUNG BEI get_posts(): Das setzt suppress_filters auf true, und dann
	 * läuft posts_where nicht – die Einschränkung fiele still weg. Dort also
	 * entweder 'suppress_filters' => false setzen oder beim meta_query-Fragment
	 * bleiben. Deshalb prüft sichtbar_where() das ausdrücklich und meldet einen
	 * Programmierfehler, statt heimlich zu viel zu zeigen.
	 */
	public static function sichtbar_arg() {
		return array( 'bi_sichtbar' => 1 );
	}

	public static function sichtbar_where( $where, $query ) {
		global $wpdb;
		if ( ! $query instanceof WP_Query || ! $query->get( 'bi_sichtbar' ) ) {
			return $where;
		}
		return $where . $wpdb->prepare(
			" AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS bi_sicht"
			. " WHERE bi_sicht.post_id = {$wpdb->posts}.ID AND bi_sicht.meta_key = %s"
			. " AND bi_sicht.meta_value <> %s ) ",
			'_bi_anzeigen',
			'1'
		);
	}

	/**
	 * Warnen, wenn bi_sichtbar an einer Abfrage hängt, die posts_where gar nicht
	 * durchlässt. Ohne diese Prüfung wäre der Fehler unsichtbar: Die Liste zeigt
	 * dann einfach ein paar Einträge zu viel, und niemand sucht danach.
	 */
	public static function sichtbar_pruefen( $query ) {
		if ( $query instanceof WP_Query && $query->get( 'bi_sichtbar' ) && $query->get( 'suppress_filters' ) ) {
			_doing_it_wrong(
				'BI_CPT::sichtbar_arg',
				'bi_sichtbar wirkt nicht, solange suppress_filters gesetzt ist (get_posts()).',
				'1.96.0'
			);
		}
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

	/** Frontend-Styles auch auf der Seminar-Einzelseite laden (beide Beitragstypen) */
	public static function enqueue_single() {
		if ( is_singular( bi_seminar_post_types() ) ) {
			wp_enqueue_style( 'bi-frontend' );
			// Gestaltung der Detailansicht (IG Metall Design System). Nach
			// bi-frontend geladen, damit die dortigen Grundwerte überschrieben
			// werden können.
			wp_enqueue_style( 'bi-detailseiten' );
			// PLZ-Suche + Mail-Anfrage für Seminare mit Geschäftsstellen-Anmeldung
			// (registriert in BI_Detail::register_assets)
			wp_enqueue_script( 'bi-gs-anfrage' );
			// Zurück-Link auf die zuletzt gesehene Trefferliste führen
			wp_enqueue_script( 'bi-zurueck' );
		}
	}

	/**
	 * Taxonomien: slug => [label, multi]. Die Slugs sind für beide Beitragstypen
	 * identisch (echtes Teilen der Begriffe), nur die Beschriftung unterscheidet
	 * sich – bi_ort heißt bei Online-Seminaren „Veranstalter*in".
	 *
	 * @param string $post_type BI_CPT (Standard) oder BI_ONLINE.
	 */
	public static function taxonomies( $post_type = BI_CPT ) {
		if ( BI_ONLINE === $post_type ) {
			return BI_Online::taxonomies();
		}
		return array(
			BI_TAX_ORT   => array( 'label' => 'Bildungszentrum', 'single' => 'Zuständiges Bildungszentrum', 'multi' => false, 'has_email' => true, 'gruppe' => 'ort' ),
			BI_TAX_THEMA => array( 'label' => 'Themenfelder', 'single' => 'Themenfeld', 'multi' => false, 'has_email' => false, 'gruppe' => 'inhalt' ),
			BI_TAX_ZIEL  => array( 'label' => 'Zielgruppen', 'single' => 'Zielgruppe', 'multi' => true, 'has_email' => false, 'gruppe' => 'inhalt' ),
			BI_TAX_FREI  => array( 'label' => 'Freistellungen', 'single' => 'Freistellung', 'multi' => true, 'has_email' => false, 'gruppe' => 'teilnahme' ),
			BI_TAX_PROGRAMM => array( 'label' => 'Programme', 'single' => 'Programm', 'multi' => false, 'has_email' => false, 'gruppe' => 'programm' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );

		// Editier-Oberfläche (für beide Beitragstypen). Spät genug, damit die
		// Standardkästen der Taxonomien schon registriert sind und entfernt
		// werden können.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 20 );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_editor' ), 10, 2 );
		foreach ( bi_seminar_post_types() as $pt ) {
			add_action( 'save_post_' . $pt, array( __CLASS__, 'save_meta' ), 10, 2 );
		}

		// Frontend-Styles auch auf der Seminar-Einzelseite (Detailansicht: BI_Detail)
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_single' ), 20 );

		// Term-Meta "E-Mail" für Bildungszentren / Veranstalter*innen (Mail-Trigger an den Seminarort)
		add_action( BI_TAX_ORT . '_add_form_fields', array( __CLASS__, 'term_email_add_field' ) );
		add_action( BI_TAX_ORT . '_edit_form_fields', array( __CLASS__, 'term_email_edit_field' ) );
		add_action( 'created_' . BI_TAX_ORT, array( __CLASS__, 'save_term_email' ) );
		add_action( 'edited_' . BI_TAX_ORT, array( __CLASS__, 'save_term_email' ) );

		// Sichtbarkeit („Anzeigen? = nein") als Unterabfrage statt als JOIN
		add_filter( 'posts_where', array( __CLASS__, 'sichtbar_where' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'sichtbar_pruefen' ), 999 );

		// Backend-Suche zusätzlich über die Seminarnummer
		add_filter( 'posts_join', array( __CLASS__, 'search_join' ), 10, 2 );
		add_filter( 'posts_where', array( __CLASS__, 'search_where' ), 10, 2 );
		add_filter( 'posts_distinct', array( __CLASS__, 'search_distinct' ), 10, 2 );

		// Spalten in der Seminar-Übersicht (beide Beitragstypen)
		foreach ( bi_seminar_post_types() as $pt ) {
			add_filter( 'manage_' . $pt . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
			add_action( 'manage_' . $pt . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
			add_filter( 'manage_edit-' . $pt . '_sortable_columns', array( __CLASS__, 'admin_sortable' ) );
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_orderby' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_filter_missing' ) );
		add_filter( 'posts_where', array( __CLASS__, 'filter_leerer_text' ), 10, 2 );

		// Filterleiste über der Seminarliste. Reihenfolge beachten: admin_filter_datum
		// hängt sich an eine eventuell schon gesetzte meta_query an und muss deshalb
		// NACH admin_filter_missing laufen.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'admin_filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_filter_datum' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_filter_hinweis' ) );

		// Präsenz- und Online-Seminare in einer Liste. Bewusst als LETZTER
		// pre_get_posts-Eingriff (Priorität 30): Bis dahin sehen alle übrigen
		// Handler den gewohnten einzelnen Beitragstyp und greifen unverändert.
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_filter_form' ), 30 );
		add_action( 'wp', array( __CLASS__, 'admin_globals_reparieren' ) );
		add_filter( 'wp_count_posts', array( __CLASS__, 'admin_count_posts' ), 10, 2 );
		add_filter( 'views_edit-' . BI_CPT, array( __CLASS__, 'admin_view_meine' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'admin_post_state' ), 10, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'admin_neu_button' ) );
		add_filter( 'parent_file', array( __CLASS__, 'admin_menu_highlight' ) );

		// Massenbearbeitung: eigene Felder im Aufklapp-Bereich der Seminarliste
		add_action( 'bulk_edit_custom_box', array( __CLASS__, 'bulk_edit_felder' ), 10, 2 );
		add_action( 'save_post', array( __CLASS__, 'bulk_edit_speichern' ), 10, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'bulk_per_post' ) );
		// Die Seminarbeschreibung reist im selben Schreibvorgang mit, statt einen
		// zweiten anzustoßen.
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'bulk_edit_post_texte' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_text_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_titel_notice' ) );
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

		// Eine Registrierung je Taxonomie für BEIDE Beitragstypen. Ein zweiter
		// register_taxonomy()-Aufruf mit demselben Slug würde die Objekt-Zuordnung
		// überschreiben statt sie zu ergänzen.
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
			register_taxonomy( $slug, bi_seminar_post_types(), $args );
		}
	}

	/** ---------- Editier-Metabox (Datum, Nummer, Plätze …) ---------- */

	public static function add_meta_box() {
		foreach ( bi_seminar_post_types() as $pt ) {
			$title = ( BI_ONLINE === $pt ) ? 'Angaben zum Online-Seminar' : 'Angaben zum Seminar';
			add_meta_box( 'bi_seminar_details', $title, array( __CLASS__, 'render_meta_box' ), $pt, 'normal', 'high' );

			// Die Standardkästen der Taxonomien entfernen: Ihre Inhalte stehen jetzt
			// gruppiert in der Maske oben. Blieben sie stehen, gäbe es zwei Orte für
			// dieselbe Angabe – genau die Verwirrung, die der Umbau beseitigen soll.
			// (Alle Taxonomien sind nicht-hierarchisch, daher „tagsdiv-<slug>".)
			foreach ( array_keys( self::taxonomies( $pt ) ) as $slug ) {
				remove_meta_box( 'tagsdiv-' . $slug, $pt, 'side' );
				remove_meta_box( 'tagsdiv-' . $slug, $pt, 'normal' );
			}
		}
	}

	/**
	 * Klassischer Editor für die eigenen Beitragstypen.
	 *
	 * Der Block-Editor zeigt Taxonomien als eigene Bereiche in der Seitenleiste,
	 * die sich von PHP aus nicht entfernen lassen – die Angaben stünden dann
	 * doppelt da. Außerdem ist keiner dieser Beitragstypen ein Ort für
	 * Block-Gestaltung: Die Beschreibung ist Fließtext, alles andere sind Felder.
	 *
	 * Wer den Block-Editor doch will: add_filter( 'bi_klassischer_editor', '__return_false' ).
	 */
	public static function classic_editor( $use_block_editor, $post_type ) {
		$eigene = array_merge( bi_seminar_post_types(), array( BI_Reihen::CPT ) );
		if ( in_array( $post_type, $eigene, true ) && apply_filters( 'bi_klassischer_editor', true ) ) {
			return false;
		}
		return $use_block_editor;
	}

	/**
	 * Bearbeiten-Maske: alle Felder UND die filterbaren Merkmale in einer
	 * gruppierten Oberfläche.
	 *
	 * Die Taxonomien standen früher in eigenen Kästen daneben – dieselbe Angabe
	 * wurde also je nach Feldart an zwei verschiedenen Orten gepflegt, ohne dass
	 * die Oberfläche den Unterschied erklärt hätte. Sie sind deshalb hier
	 * einsortiert, und die Standardkästen werden in add_meta_box() entfernt.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'bi_save_meta', 'bi_meta_nonce' );

		$pt      = $post->post_type;
		$fields  = self::meta_fields( $pt );
		$mit_tax = in_array( $pt, bi_seminar_post_types(), true );
		$taxes   = $mit_tax ? self::taxonomies( $pt ) : array();
		$gruppen = self::feld_gruppen( $pt );

		// Einträge auf die Abschnitte verteilen. Reihenfolge innerhalb eines
		// Abschnitts: erst die Taxonomien, dann die Felder – die Einordnung steht
		// so vor den Details, die sie ausfüllen.
		$inhalt = array_fill_keys( array_keys( $gruppen ), array() );
		foreach ( $taxes as $slug => $cfg ) {
			$g = isset( $gruppen[ $cfg['gruppe'] ?? '' ] ) ? $cfg['gruppe'] : 'weitere';
			$inhalt[ $g ][] = array( 'art' => 'tax', 'key' => $slug, 'cfg' => $cfg );
		}
		foreach ( $fields as $key => $cfg ) {
			$g = isset( $gruppen[ $cfg['gruppe'] ?? '' ] ) ? $cfg['gruppe'] : 'weitere';
			$inhalt[ $g ][] = array( 'art' => 'meta', 'key' => $key, 'cfg' => $cfg );
		}

		self::meta_box_styles();
		if ( $mit_tax ) {
			echo '<input type="hidden" name="bi_tax_form" value="1">';
		}
		echo '<div class="bi-mb">';

		foreach ( $gruppen as $g => $titel ) {
			if ( empty( $inhalt[ $g ] ) ) {
				continue; // leere Abschnitte entfallen – dieselbe Liste taugt für beide Seminarformen
			}
			echo '<fieldset class="bi-mb-gruppe"><legend>' . esc_html( $titel ) . '</legend>';
			echo '<div class="bi-mb-grid">';
			foreach ( $inhalt[ $g ] as $eintrag ) {
				if ( 'tax' === $eintrag['art'] ) {
					self::render_tax_feld( $post, $eintrag['key'], $eintrag['cfg'] );
				} else {
					self::render_meta_feld( $post, $eintrag['key'], $eintrag['cfg'] );
				}
			}
			echo '</div>';
			if ( 'kosten' === $g && isset( $fields['_bi_kosten_seminar'] ) ) {
				echo '<p class="bi-mb-summe">Gesamtkosten: <strong data-bi-summe>–</strong>'
					. ' <span class="bi-mb-hint">Wird aus den Posten darüber berechnet und nicht gespeichert.</span></p>';
			}
			echo '</fieldset>';
		}

		echo '</div>';
		self::meta_box_script();
	}

	/** Ein einzelnes Meta-Feld der Maske. */
	private static function render_meta_feld( $post, $key, $cfg ) {
		$val  = get_post_meta( $post->ID, $key, true );
		$type = $cfg['type'];
		$hint = ! empty( $cfg['hint'] ) ? '<span class="bi-mb-hint">' . esc_html( $cfg['hint'] ) . '</span>' : '';
		$weit = in_array( $type, array( 'textarea', 'html' ), true );

		printf( '<div class="bi-mb-feld%s">', $weit ? ' bi-mb-feld--breit' : '' );

		if ( 'html' === $type ) {
			// Editor statt nacktem Textfeld: „Themen im Seminar" ist eine
			// Aufzählung, die niemand in HTML tippen können muss.
			printf( '<label>%s</label>', esc_html( $cfg['label'] ) );
			wp_editor( (string) $val, 'bi' . preg_replace( '/[^a-z0-9]/', '', strtolower( $key ) ), array(
				'textarea_name' => $key,
				'textarea_rows' => 8,
				'media_buttons' => false,
				'teeny'         => true,
				'quicktags'     => true,
			) );
			echo $hint; // phpcs:ignore – escaped
		} elseif ( 'textarea' === $type ) {
			printf(
				'<label for="%1$s">%2$s</label><textarea id="%1$s" name="%1$s" rows="5">%3$s</textarea>%4$s',
				esc_attr( $key ),
				esc_html( $cfg['label'] ),
				esc_textarea( $val ),
				$hint
			);
		} elseif ( 'bool' === $type ) {
			$checked = ( '' === $val ) ? ! empty( $cfg['default'] ) : ( '1' === (string) $val );
			printf(
				'<input type="hidden" name="%1$s" value="0">'
				. '<label class="bi-mb-check" for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s> %3$s</label>%4$s',
				esc_attr( $key ),
				$checked ? ' checked' : '',
				esc_html( $cfg['label'] ),
				$hint
			);
		} elseif ( 'select' === $type ) {
			$cur = ( '' === $val && isset( $cfg['default'] ) ) ? (string) $cfg['default'] : (string) $val;
			printf( '<label for="%1$s">%2$s</label><select id="%1$s" name="%1$s">', esc_attr( $key ), esc_html( $cfg['label'] ) );
			echo '<option value="">— bitte wählen —</option>';
			foreach ( (array) $cfg['options'] as $ov => $ol ) {
				printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $ov ), selected( $cur, $ov, false ), esc_html( $ol ) );
			}
			echo '</select>' . $hint; // phpcs:ignore – escaped
		} elseif ( 'money' === $type ) {
			// Bewusst ein Textfeld: <input type="number"> lehnt in deutschen
			// Browsern die Eingabe „1.083,50" ab, und genau so tippen die
			// Redakteur*innen sie aus dem Programmheft ab.
			printf(
				'<label for="%1$s">%2$s</label><input type="text" inputmode="decimal" class="bi-mb-money" id="%1$s" name="%1$s" value="%3$s" placeholder="z. B. 1.083,50">%4$s',
				esc_attr( $key ),
				esc_html( $cfg['label'] ),
				esc_attr( '' !== $val ? self::money_format( $val ) : '' ),
				$hint
			);
		} else {
			// text, date, time, number, email, url
			printf(
				'<label for="%1$s">%2$s</label><input type="%3$s" id="%1$s" name="%1$s" value="%4$s">%5$s',
				esc_attr( $key ),
				esc_html( $cfg['label'] ),
				esc_attr( $type ),
				esc_attr( $val ),
				$hint
			);
		}

		echo '</div>';
	}

	/**
	 * Eine Taxonomie als Feld der Maske: Einzelwert als Auswahlliste,
	 * Mehrfachwerte als Kästchenliste.
	 */
	private static function render_tax_feld( $post, $slug, $cfg ) {
		$terms = get_terms( array( 'taxonomy' => $slug, 'hide_empty' => false, 'orderby' => 'name' ) );
		$aktiv = wp_get_object_terms( $post->ID, $slug, array( 'fields' => 'ids' ) );
		$aktiv = is_wp_error( $aktiv ) ? array() : array_map( 'intval', $aktiv );
		$multi = ! empty( $cfg['multi'] );

		$fremd = array();
		if ( BI_TAX_ORT === $slug && BI_CPT === $post->post_type && ! is_wp_error( $terms ) ) {
			list( $terms, $fremd ) = self::ort_auswahl( (array) $terms, $aktiv );
		}

		printf( '<div class="bi-mb-feld%s">', $multi ? ' bi-mb-feld--breit' : '' );
		printf( '<label for="bi_tax_%s">%s</label>', esc_attr( $slug ), esc_html( $cfg['single'] ) );

		if ( is_wp_error( $terms ) || ! $terms ) {
			printf(
				'<p class="bi-mb-hint">Noch keine Begriffe vorhanden. <a href="%s">Jetzt anlegen</a>.</p>',
				esc_url( admin_url( 'edit-tags.php?taxonomy=' . $slug . '&post_type=' . $post->post_type ) )
			);
			echo '</div>';
			return;
		}

		if ( $multi ) {
			echo '<div class="bi-mb-checks">';
			foreach ( $terms as $t ) {
				printf(
					'<label class="bi-mb-check"><input type="checkbox" name="bi_tax[%1$s][]" value="%2$d"%3$s> %4$s</label>',
					esc_attr( $slug ),
					(int) $t->term_id,
					in_array( (int) $t->term_id, $aktiv, true ) ? ' checked' : '',
					esc_html( $t->name )
				);
			}
			echo '</div>';
		} else {
			printf( '<select id="bi_tax_%1$s" name="bi_tax[%1$s]">', esc_attr( $slug ) );
			echo '<option value="">— keine Angabe —</option>';
			foreach ( $terms as $t ) {
				printf(
					'<option value="%1$d"%2$s>%3$s</option>',
					(int) $t->term_id,
					selected( in_array( (int) $t->term_id, $aktiv, true ), true, false ),
					esc_html( $t->name . ( isset( $fremd[ (int) $t->term_id ] ) ? ' — kein Bildungszentrum' : '' ) )
				);
			}
			echo '</select>';
		}

		if ( $fremd ) {
			printf(
				'<span class="bi-mb-hint" style="color:#b32d2e">Der eingetragene Ort ist kein Bildungszentrum.'
				. ' Bitte hier das zuständige Bildungszentrum oder „%1$s" wählen und „%2$s" in das Feld'
				. ' <em>Seminarort</em> schreiben. Für alle Seminare auf einmal: <a href="%3$s">Datenpflege → Orte aufräumen</a>.</span>',
				esc_html( self::ANDERE ),
				esc_html( reset( $fremd ) ),
				esc_url( admin_url( 'admin.php?page=' . BI_Datenpflege::PAGE ) )
			);
		} elseif ( BI_TAX_ORT === $slug && BI_CPT === $post->post_type ) {
			echo '<span class="bi-mb-hint">Nur Bildungszentren. Wo das Seminar tatsächlich stattfindet, steht im Feld <em>Seminarort</em>.</span>';
		}

		printf(
			'<span class="bi-mb-hint"><a href="%s">Begriffe verwalten</a></span>',
			esc_url( admin_url( 'edit-tags.php?taxonomy=' . $slug . '&post_type=' . $post->post_type ) )
		);
		echo '</div>';
	}

	/** Styles der Maske. Bewusst inline: ein eigenes Stylesheet für eine Metabox lohnt nicht. */
	private static function meta_box_styles() {
		?>
		<style>
		.bi-mb-gruppe{border:1px solid #dcdcde;border-radius:4px;padding:4px 16px 16px;margin:0 0 18px;background:#fff}
		.bi-mb-gruppe legend{font-size:14px;font-weight:600;padding:0 6px;color:#1d2327}
		.bi-mb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px 20px;align-items:start}
		.bi-mb-feld--breit{grid-column:1/-1}
		.bi-mb label{display:block;font-weight:600;margin:0 0 4px}
		.bi-mb input[type=text],.bi-mb input[type=date],.bi-mb input[type=time],.bi-mb input[type=number],
		.bi-mb input[type=email],.bi-mb input[type=url],.bi-mb select,.bi-mb textarea{width:100%}
		.bi-mb .bi-mb-check{font-weight:400;display:block;margin:0 0 2px}
		.bi-mb .bi-mb-check input{width:auto;margin-right:6px}
		.bi-mb-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:0 16px;
			max-height:210px;overflow:auto;border:1px solid #dcdcde;padding:8px 10px;background:#fdfdfd}
		.bi-mb .bi-mb-hint{display:block;font-weight:400;color:#646970;font-size:12px;margin:4px 0 0}
		.bi-mb-summe{margin:12px 0 0;padding-top:10px;border-top:1px solid #dcdcde;font-size:14px}
		.bi-mb-summe strong{font-size:16px}
		</style>
		<?php
	}

	/** Laufende Summe der Kostenposten – reine Anzeigehilfe beim Eintippen. */
	private static function meta_box_script() {
		?>
		<script>
		( function () {
			var box = document.querySelector( '[data-bi-summe]' );
			if ( ! box ) { return; }
			var felder = document.querySelectorAll( '.bi-mb-money' );

			// Dieselbe Lesart wie BI_CPT::money_parse(): bei Punkt UND Komma trennt
			// der Punkt die Tausender; ein einzelner Punkt mit drei Stellen dahinter
			// ebenfalls. Sonst ist er das Dezimaltrennzeichen.
			function zahl( roh ) {
				var s = String( roh ).replace( /[^0-9,.-]/g, '' );
				if ( ! s ) { return 0; }
				if ( s.indexOf( ',' ) > -1 ) {
					s = s.replace( /\./g, '' ).replace( ',', '.' );
				} else {
					var teile = s.split( '.' );
					if ( teile.length > 1 && teile[ teile.length - 1 ].length === 3 ) {
						s = teile.join( '' );
					}
				}
				var n = parseFloat( s );
				return isNaN( n ) ? 0 : n;
			}

			function rechnen() {
				var summe = 0, gefuellt = false;
				felder.forEach( function ( f ) {
					if ( f.value.trim() !== '' ) { gefuellt = true; summe += zahl( f.value ); }
				} );
				box.textContent = gefuellt
					? summe.toLocaleString( 'de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ) + ' €'
					: '–';
			}

			felder.forEach( function ( f ) { f.addEventListener( 'input', rechnen ); } );
			rechnen();
		} )();
		</script>
		<?php
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
		foreach ( self::meta_fields( $post->post_type ) as $key => $cfg ) {
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
				case 'url':
					$val = esc_url_raw( trim( (string) $raw ) );
					break;
				case 'select':
					$val = isset( $cfg['options'][ $raw ] ) ? sanitize_text_field( $raw ) : '';
					break;
				case 'money':
					// In der Maske steht „1.083,50 €", gespeichert wird „1083.5".
					$val = self::money_parse( $raw );
					break;
				default: // text, date, time, number, bool ('0'/'1')
					$val = sanitize_text_field( $raw );
			}
			update_post_meta( $post_id, $key, $val );
		}

		self::save_tax( $post_id, $post );
	}

	/**
	 * Taxonomien aus der eigenen Maske speichern.
	 *
	 * Nur wenn das Formular auch wirklich abgeschickt wurde (Marker bi_tax_form).
	 * Ohne diese Prüfung würde jeder andere Speichervorgang – Schnellbearbeitung,
	 * REST, ein programmatisches wp_update_post – die Begriffe leeren, weil dann
	 * schlicht keine Kästchen mitkommen.
	 */
	private static function save_tax( $post_id, $post ) {
		if ( empty( $_POST['bi_tax_form'] ) || ! in_array( $post->post_type, bi_seminar_post_types(), true ) ) {
			return;
		}
		$eingabe = ( isset( $_POST['bi_tax'] ) && is_array( $_POST['bi_tax'] ) ) ? wp_unslash( $_POST['bi_tax'] ) : array();

		foreach ( self::taxonomies( $post->post_type ) as $slug => $cfg ) {
			$werte = isset( $eingabe[ $slug ] ) ? (array) $eingabe[ $slug ] : array();
			// Ganzzahlen: wp_set_object_terms deutet nur echte Integer als term_id,
			// eine Zeichenkette „12" würde einen Begriff mit dem Namen „12" anlegen.
			$ids = array_values( array_filter( array_map( 'intval', $werte ) ) );
			wp_set_object_terms( $post_id, $ids, $slug, false );
		}
	}

	/** ---------- Term-Meta "E-Mail" für Bildungszentren / Veranstalter*innen ---------- */

	public static function term_email_add_field() {
		echo '<div class="form-field"><label for="bi_term_email">E-Mail (für Mail-Trigger an dieses Bildungszentrum)</label>';
		echo '<input type="email" name="bi_term_email" id="bi_term_email" value=""></div>';
	}

	public static function term_email_edit_field( $term ) {
		$val = get_term_meta( $term->term_id, 'email', true );
		echo '<tr class="form-field"><th><label for="bi_term_email">E-Mail</label></th>';
		echo '<td><input type="email" name="bi_term_email" id="bi_term_email" value="' . esc_attr( $val ) . '">';
		echo '<p class="description">Adresse des Bildungszentrums bzw. der Veranstalter*in für den Mail-Trigger „Mail an Bildungszentrum".</p></td></tr>';
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

	/* ===================================================================
	 *  Datenqualität
	 * ===================================================================
	 *
	 *  Ein Jahrgang bringt über tausend Seminare mit, und eine fehlende Angabe
	 *  sieht man dem einzelnen Eintrag nicht an – die Seite ist nur still
	 *  schlechter: ohne Seminarnummer keine Verfügbarkeits-Ampel, ohne
	 *  Themenfeld kein Treffer im Filter, ohne Freistellung fällt eine ganze
	 *  Ausbildungsreihe auf den Weg über die Geschäftsstelle.
	 *
	 *  Diese Prüfung zählt die Lücken über den GANZEN Bestand und verlinkt jede
	 *  in die gefilterte Seminarliste, wo sie markiert und – wo es sinnvoll ist –
	 *  mit der Massenaktion „Bearbeiten" in einem Zug gefüllt werden können.
	 *
	 *  Geprüft werden veröffentlichte Seminare: Was im Entwurf fehlt, fällt
	 *  niemandem auf die Füße; was veröffentlicht ist, steht auf der Website.
	 */

	/** Anzahl veröffentlichter Seminare, auf die eine Bedingung zutrifft. */
	private static function qualitaet_zaehle( $args, $post_type ) {
		$q = new WP_Query( array_merge( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 1,
		), $args ) );
		return (int) $q->found_posts;
	}

	/**
	 * Die Prüfungen der Datenqualität.
	 *
	 * @param string $form '' (beide), 'praesenz' oder 'online'.
	 * @return array Liste aus [ was, anzahl, wirkung, batch, url ]
	 */
	public static function datenqualitaet( $form = '' ) {
		$pt = ( 'online' === $form ) ? array( BI_ONLINE ) : ( ( 'praesenz' === $form ) ? array( BI_CPT ) : bi_seminar_post_types() );

		$link = function ( $args ) use ( $form ) {
			return BI_Admin::seminarliste_url( $form, $args );
		};
		$meta = function ( $key ) use ( $pt ) {
			return array( 'meta_query' => array( self::leer_clause( $key ) ) );
		};
		$ohne_begriff = function ( $tax ) {
			return array( 'tax_query' => array( array( 'taxonomy' => $tax, 'operator' => 'NOT EXISTS' ) ) );
		};

		$pruefungen = array(
			array(
				'was'     => 'Startdatum fehlt',
				'args'    => $meta( '_bi_startdatum' ),
				'url'     => $link( array( 'bi_missing_start' => 1 ) ),
				'wirkung' => 'Das Seminar taucht weder im Datumsfilter noch in der Ergebnisliste auf und ist nicht buchbar.',
				'batch'   => false,
			),
			array(
				'was'     => 'Seminarnummer fehlt',
				'args'    => $meta( '_bi_seminarnummer' ),
				'url'     => $link( array( 'bi_missing_nummer' => 1 ) ),
				'wirkung' => 'Ohne Nummer gibt es keine Verfügbarkeits-Ampel, und dem Bildungszentrum fehlt in der Anmeldung die Zuordnung.',
				'batch'   => false,
			),
			array(
				'was'     => 'Beschreibung fehlt',
				'args'    => array( 'bi_leerer_text' => 1 ),
				'url'     => $link( array( 'bi_missing_text' => 1 ) ),
				'wirkung' => 'Die Detailseite beginnt ohne Text – die Kachel in der Suche bleibt eine Überschrift ohne Inhalt.',
				'batch'   => true,
			),
			array(
				'was'     => 'Themen im Seminar fehlen',
				'args'    => $meta( '_bi_themen' ),
				'url'     => $link( array( 'bi_missing_themen' => 1 ) ),
				'wirkung' => 'Der Abschnitt „Themen des Seminars" entfällt; auf einer Reihenseite klappt der Teil leer auf.',
				'batch'   => true,
			),
			array(
				// Geprüft wird die ZUSTELLADRESSE, nicht die der Ansprechperson.
				// Vor der Trennung war das dasselbe Feld; seither entscheidet
				// allein _bi_bz_email, ob eine Benachrichtigung ankommt.
				'was'     => 'E-Mail des Bildungszentrums fehlt',
				'args'    => $meta( '_bi_bz_email' ),
				'url'     => $link( array( 'bi_missing_ap' => 1 ) ),
				'wirkung' => 'Die Benachrichtigung findet keinen Empfänger – es sei denn, am Begriff des Bildungszentrums steht eine Adresse.',
				'batch'   => true,
			),
			array(
				'was'     => 'Bildungszentrum fehlt',
				'args'    => $ohne_begriff( BI_TAX_ORT ),
				'url'     => $link( array( BI_TAX_ORT => self::OHNE ) ),
				'wirkung' => 'Ohne Ort bleibt die Ortszeile leer, und der Filter „Bildungszentrum" findet das Seminar nicht.',
				'batch'   => true,
			),
			array(
				'was'     => 'Themenfeld fehlt',
				'args'    => $ohne_begriff( BI_TAX_THEMA ),
				'url'     => $link( array( BI_TAX_THEMA => self::OHNE ) ),
				'wirkung' => 'Das Seminar erscheint in keiner Themenauswahl – gefunden wird es dann nur über die Freitextsuche.',
				'batch'   => true,
			),
			array(
				'was'     => 'Zielgruppe fehlt',
				'args'    => $ohne_begriff( BI_TAX_ZIEL ),
				'url'     => $link( array( BI_TAX_ZIEL => self::OHNE ) ),
				'wirkung' => 'Der Zielgruppenfilter übergeht das Seminar, und auf der Detailseite fehlt die Angabe, für wen es gedacht ist.',
				'batch'   => true,
			),
			array(
				'was'     => 'Freistellung fehlt',
				'args'    => $ohne_begriff( BI_TAX_FREI ),
				'url'     => $link( array( BI_TAX_FREI => self::OHNE ) ),
				'wirkung' => 'Ohne Freistellung gilt der Termin als nicht direkt buchbar – eine Ausbildungsreihe fällt damit ganz auf die Anmeldung über die Geschäftsstelle.',
				'batch'   => true,
			),
			array(
				'was'     => 'Programmjahr fehlt',
				'args'    => $ohne_begriff( BI_TAX_PROGRAMM ),
				'url'     => $link( array( BI_TAX_PROGRAMM => self::OHNE ) ),
				'wirkung' => 'Das Seminar lässt sich keinem Jahrgang zuordnen und rutscht bei einer nach Programm gefilterten Suche heraus.',
				'batch'   => true,
			),
		);

		$out = array();
		foreach ( $pruefungen as $p ) {
			$p['anzahl'] = self::qualitaet_zaehle( $p['args'], $pt );
			unset( $p['args'] );
			$out[] = $p;
		}
		return $out;
	}

	/** Bedingung „dieses Meta-Feld ist leer oder gar nicht gesetzt". */
	public static function leer_clause( $key ) {
		return array(
			'relation' => 'OR',
			array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
			array( 'key' => $key, 'value' => '', 'compare' => '=' ),
		);
	}

	/**
	 * Listen-Filter für die Hinweise aus Dashboard und Datenpflege: zeigt nur
	 * Seminare, denen eine bestimmte Angabe fehlt – statt aller Seminare.
	 *
	 *   ?bi_missing_start=1   -> ohne Startdatum
	 *   ?bi_missing_ap=1      -> kommende Seminare ohne Ansprechpartner-E-Mail
	 *   ?bi_missing_link=1    -> Teams-Webinare ohne Anmeldelink (nur Online)
	 *   ?bi_missing_themen=1  -> ohne „Themen im Seminar"
	 *   ?bi_missing_nummer=1  -> ohne Seminarnummer
	 *   ?bi_reihe=__mit__     -> nur Termine, die einer Ausbildungsreihe gehören
	 *   ?bi_reihe=<ID>        -> nur Termine dieser einen Reihe
	 *
	 * ANGEHÄNGT STATT GESETZT: Früher ersetzte jeder dieser Filter die ganze
	 * meta_query und schloss die anderen per elseif aus. Damit war „ohne
	 * Freistellung UND aus einer Reihe" nicht zu haben – genau die Kombination,
	 * aus der die Mängelliste ihre Links baut. Jetzt sammeln alle gesetzten
	 * Filter ihre Bedingungen und hängen sie an, was schon da ist.
	 */
	public static function admin_filter_missing( $query ) {
		if ( ! is_admin() || ! $query->is_main_query()
			|| ! in_array( $query->get( 'post_type' ), bi_seminar_post_types(), true ) ) {
			return;
		}

		$clauses = array();

		if ( ! empty( $_GET['bi_missing_start'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$clauses[] = self::leer_clause( '_bi_startdatum' );
		}
		if ( ! empty( $_GET['bi_missing_ap'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$clauses[] = array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' );
			$clauses[] = self::leer_clause( '_bi_bz_email' );
		}
		if ( ! empty( $_GET['bi_missing_link'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$clauses[] = array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' );
			$clauses[] = array( 'key' => '_bi_online_tool', 'value' => 'teams_webinar' );
			$clauses[] = self::leer_clause( '_bi_anmeldelink' );
		}
		if ( ! empty( $_GET['bi_missing_themen'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$clauses[] = self::leer_clause( '_bi_themen' );
		}
		if ( ! empty( $_GET['bi_missing_nummer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$clauses[] = self::leer_clause( '_bi_seminarnummer' );
		}

		// Die Beschreibung steckt in post_content, nicht in einem Meta-Feld –
		// dafür gibt es keine meta_query. Hier wird nur eine Abfragevariable
		// gesetzt; die Bedingung hängt filter_leerer_text() an das WHERE.
		if ( ! empty( $_GET['bi_missing_text'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query->set( 'bi_leerer_text', 1 );
		}

		$reihe = self::filter_reihe();
		if ( 'mit' === $reihe ) {
			$clauses[] = array( 'key' => BI_Reihen::META_REIHE, 'compare' => 'EXISTS' );
		} elseif ( $reihe > 0 ) {
			$clauses[] = array( 'key' => BI_Reihen::META_REIHE, 'value' => (int) $reihe );
		}

		if ( ! $clauses ) {
			return;
		}

		// Nicht (array) casten: Ohne gesetzte meta_query liefert get() einen
		// leeren String, und (array) '' ergäbe array( '' ).
		$meta = $query->get( 'meta_query' );
		$meta = is_array( $meta ) ? $meta : array();
		$meta = array_merge( $meta, $clauses );
		$meta['relation'] = 'AND';
		$query->set( 'meta_query', $meta );
	}

	/**
	 * „Beschreibung ist leer" an die WHERE-Bedingung hängen.
	 *
	 * Gesteuert über die Abfragevariable bi_leerer_text, nicht über $_GET: Zu
	 * diesem Zeitpunkt ist post_type in der Sammelliste bereits ein Array, eine
	 * Prüfung auf den Beitragstyp ginge hier also fehl. Die Entscheidung fällt
	 * früher, in admin_filter_missing().
	 *
	 * Bewusst ohne is_main_query(): Die Zählung der Datenqualität setzt dieselbe
	 * Variable an einer eigenen Abfrage. Die Variable IST die Zustimmung – wo
	 * sie niemand setzt, passiert nichts.
	 */
	public static function filter_leerer_text( $where, $query ) {
		global $wpdb;
		if ( ! is_admin() || ! $query->get( 'bi_leerer_text' ) ) {
			return $where;
		}
		return $where . " AND TRIM({$wpdb->posts}.post_content) = ''";
	}

	/**
	 * Auswahl des Reihen-Filters: '' (alle), 'mit' (nur Reihen-Termine) oder
	 * die Post-ID einer Reihe.
	 */
	private static function filter_reihe() {
		$roh = isset( $_GET['bi_reihe'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_reihe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::REIHE_MIT === $roh ) {
			return 'mit';
		}
		return (int) $roh;
	}

	/* ===================================================================
	 *  Filterleiste der Seminarliste
	 *
	 *  Bewusst die WordPress-eigene Liste statt einer eigenen Oberfläche: Sie
	 *  bringt Auswahlkästchen, Massenaktionen, Papierkorb, Suche, Sortierung und
	 *  „Ansicht anpassen" schon mit. Ergänzt werden nur die Filter, die dem
	 *  Datenmodell fehlen – die Taxonomien und der Zeitraum des Startdatums.
	 *  Größere Mengen löscht man damit auf dem üblichen Weg: filtern, alle
	 *  markieren, Massenaktion „In den Papierkorb verschieben".
	 * =================================================================== */

	/** Wert im Auswahlfeld für „Einträge ohne Begriff dieser Taxonomie". */
	const OHNE = '__ohne__';

	/**
	 * Höchstzahl markierter Einträge für die Textfelder der Massenbearbeitung
	 * (Themen, Beschreibung).
	 *
	 * WARUM ÜBERHAUPT EINE GRENZE: Ein Kurzwert wie eine E-Mail-Adresse gilt oft
	 * für ein ganzes Bildungszentrum – dort ist „für alle setzen" der Normalfall.
	 * Ein Fließtext gilt für einen Seminarinhalt. Ihn über den halben Bestand zu
	 * legen ist fast immer ein Versehen, und rückgängig zu machen ist es nicht:
	 * Der vorherige Text ist dann weg. Die Grenze macht die nützliche Menge
	 * möglich (die Termine eines Teils, ein Durchgang, ein Bildungszentrum) und
	 * die schädliche unmöglich.
	 */
	const BULK_TEXT_MAX = 50;

	/** Wert im Reihen-Filter für „alle Termine, die zu irgendeiner Reihe gehören". */
	const REIHE_MIT = '__mit__';

	/* -------------------------------------------------------------------
	 *  Eine Liste für beide Seminarformen
	 *
	 *  Präsenz- und Online-Seminare sind zwei Beitragstypen, aber inhaltlich
	 *  dasselbe: derselbe Jahrgang, dieselben Taxonomien, dieselbe Arbeit.
	 *  Zwei getrennte Menüpunkte zwingen dazu, jede Suche zweimal zu machen.
	 *  Deshalb führt die Liste unter „Seminare" beide zusammen; das Auswahlfeld
	 *  „Seminarform" schränkt bei Bedarf wieder ein.
	 * ------------------------------------------------------------------- */

	/** Auswahl des Seminarform-Filters: '' (beide), 'praesenz' oder 'online'. */
	private static function listen_form() {
		$wahl = isset( $_GET['bi_form'] ) ? sanitize_key( wp_unslash( $_GET['bi_form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $wahl, array( 'praesenz', 'online' ), true ) ? $wahl : '';
	}

	/** Beitragstypen, die die zusammengeführte Liste gerade zeigt. */
	private static function listen_post_types() {
		$wahl = self::listen_form();
		if ( 'praesenz' === $wahl ) {
			return array( BI_CPT );
		}
		if ( 'online' === $wahl ) {
			return array( BI_ONLINE );
		}
		return bi_seminar_post_types();
	}

	/**
	 * Läuft gerade die zusammengeführte Seminarliste?
	 *
	 * Nur der Bildschirm des Präsenz-Beitragstyps führt zusammen. Die eigene
	 * Liste unter edit.php?post_type=bi_online bleibt unverändert – sie ist
	 * zwar aus dem Menü verschwunden, wird aber noch verlinkt.
	 */
	private static function ist_sammelliste() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && 'edit' === $screen->base && BI_CPT === $screen->post_type;
	}

	/** Die Abfrage der Seminarliste auf beide Beitragstypen ausweiten. */
	public static function admin_filter_form( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || BI_CPT !== $query->get( 'post_type' ) ) {
			return;
		}
		$typen = self::listen_post_types();
		if ( array( BI_CPT ) === $typen ) {
			return; // nichts zu tun – das ist der Normalfall der Liste
		}
		$query->set( 'post_type', $typen );
	}

	/**
	 * Die Status-Zähler über der Liste („Alle (2.300) | Veröffentlicht (…)").
	 *
	 * WordPress zählt dort nur den Beitragstyp des Bildschirms. Zeigt die Liste
	 * beide Formen, stünden über ihr Zahlen, die kleiner sind als die Liste
	 * lang ist. Statt die fertigen Links nachträglich umzuschreiben, wird hier
	 * die Quelle korrigiert – davon profitiert alles, was auf diesem Bildschirm
	 * zählt, nicht nur die Status-Links.
	 */
	public static function admin_count_posts( $counts, $post_type ) {
		if ( BI_CPT !== $post_type || ! self::ist_sammelliste() ) {
			return $counts;
		}
		$typen = self::listen_post_types();
		if ( ! in_array( BI_ONLINE, $typen, true ) ) {
			return $counts;
		}
		if ( ! in_array( BI_CPT, $typen, true ) ) {
			// Nur Online gewählt: die Zahlen des anderen Typs wären schlicht falsch.
			return wp_count_posts( BI_ONLINE );
		}

		// Beide: aufaddieren. remove/add_filter verhindert die Endlosschleife,
		// weil wp_count_posts() denselben Filter erneut auslöst.
		remove_filter( 'wp_count_posts', array( __CLASS__, 'admin_count_posts' ), 10 );
		$online = wp_count_posts( BI_ONLINE );
		add_filter( 'wp_count_posts', array( __CLASS__, 'admin_count_posts' ), 10, 2 );

		foreach ( (array) $online as $status => $n ) {
			$counts->$status = (int) ( $counts->$status ?? 0 ) + (int) $n;
		}
		return $counts;
	}

	/**
	 * Die globale $post_type nach der Listenabfrage wieder geradeziehen.
	 *
	 * WP_Query kennt post_type auch als Array – die Liste selbst kommt mit
	 * unserer Erweiterung also bestens zurecht. Danach kopiert aber
	 * WP::register_globals() JEDE Abfragevariable in den globalen Namensraum
	 * und überschreibt dabei die globale $post_type, die wp-admin/edit.php
	 * vorher auf den Beitragstyp des Bildschirms gesetzt hatte.
	 *
	 * Die Datei benutzt sie danach noch an vier Stellen – unter anderem für
	 * das versteckte Feld <input name="post_type"> des Listenformulars. Stünde
	 * dort ein Array, brächte esc_attr() eine PHP-Warnung in den Wert, und beim
	 * nächsten Absenden quittierte WordPress mit „Der Inhaltstyp ist ungültig."
	 * Betroffen wären der Filtern-Knopf und jede Massenaktion – also genau die
	 * Arbeitsabläufe, für die es diese Liste gibt.
	 *
	 * Deshalb hier repariert, sobald wp() durch ist: nichts Neues gesetzt, nur
	 * der überschriebene Wert zurückgeholt.
	 */
	public static function admin_globals_reparieren() {
		if ( ! isset( $GLOBALS['post_type'] ) || ! is_array( $GLOBALS['post_type'] ) ) {
			return; // nichts überschrieben – dann auch nichts zu tun
		}
		if ( ! self::ist_sammelliste() ) {
			return;
		}
		$GLOBALS['post_type'] = BI_CPT; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Den Status-Link „Meine" an die Sammelliste anpassen.
	 *
	 * Diese eine Zahl holt sich WordPress nicht über wp_count_posts(), sondern
	 * mit einer eigenen Abfrage auf genau einen Beitragstyp – sie bliebe also
	 * bei den Präsenz-Seminaren stehen, während die Liste daneben beide zeigt.
	 * WordPress blendet den Link außerdem aus, wenn er dieselbe Menge meint wie
	 * „Alle"; diese Regel wird hier mit der richtigen Zahl nachgezogen.
	 */
	public static function admin_view_meine( $views ) {
		global $wpdb;

		if ( ! isset( $views['mine'] ) || ! self::ist_sammelliste() ) {
			return $views;
		}

		$typen = self::listen_post_types();
		if ( array( BI_CPT ) === $typen ) {
			return $views; // unveränderte Einzelansicht – WordPress liegt richtig
		}

		$typ_platz = implode( ',', array_fill( 0, count( $typen ), '%s' ) );
		$meine     = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(1) FROM {$wpdb->posts}
			  WHERE post_type IN ( $typ_platz )
			    AND post_status NOT IN ( 'trash', 'auto-draft' )
			    AND post_author = %d",
			array_merge( $typen, array( get_current_user_id() ) )
		) );

		$alle = 0;
		foreach ( (array) wp_count_posts( BI_CPT ) as $status => $n ) {
			if ( ! in_array( $status, array( 'trash', 'auto-draft' ), true ) ) {
				$alle += (int) $n;
			}
		}

		if ( ! $meine || $meine === $alle ) {
			unset( $views['mine'] ); // sagt dasselbe wie „Alle" – dann lieber weg
			return $views;
		}

		// Nur die Zahl austauschen; Link, Beschriftung und Zustand bleiben, wie
		// WordPress sie gebaut hat. Greift das Muster nicht, bleibt alles beim Alten.
		$views['mine'] = preg_replace(
			'/(<span class="count">\()[^)]*(\)<\/span>)/',
			'${1}' . esc_html( number_format_i18n( $meine ) ) . '${2}',
			$views['mine'],
			1
		);
		return $views;
	}

	/**
	 * Online-Seminare haben keinen eigenen Menüpunkt mehr. Beim Bearbeiten oder
	 * Anlegen eines Online-Seminars stünde deshalb das ganze Menü unmarkiert da.
	 * Hier wird stattdessen „Bildungsprogramm → Seminare" hervorgehoben – die
	 * Liste, aus der man gekommen ist.
	 */
	public static function admin_menu_highlight( $parent_file ) {
		global $submenu_file, $current_screen;

		if ( $current_screen && BI_ONLINE === $current_screen->post_type ) {
			$submenu_file = 'edit.php?post_type=' . BI_CPT; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return 'bi-seminarsuche';
		}
		return $parent_file;
	}

	/** Online-Seminare in der gemeinsamen Liste als solche kennzeichnen. */
	public static function admin_post_state( $states, $post ) {
		if ( BI_ONLINE === $post->post_type && self::ist_sammelliste() ) {
			$states['bi_online'] = 'Online';
		}
		return $states;
	}

	/**
	 * Zweiter Knopf „Neues Online-Seminar" neben „Neues Seminar anlegen".
	 *
	 * WordPress bietet zwischen Überschrift und Knopf keinen Hook, deshalb hier
	 * per JavaScript. Ohne diesen Knopf gäbe es nach dem Entfernen des
	 * Menüpunkts keinen Weg mehr, ein Online-Seminar von Hand anzulegen.
	 */
	public static function admin_neu_button() {
		if ( ! self::ist_sammelliste() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$url = admin_url( 'post-new.php?post_type=' . BI_ONLINE );
		?>
		<script>
		( function () {
			var knopf = document.querySelector( '.wrap .page-title-action' );
			if ( ! knopf ) { return; }
			var neu = document.createElement( 'a' );
			neu.href = <?php echo wp_json_encode( $url ); ?>;
			neu.className = 'page-title-action';
			neu.textContent = 'Neues Online-Seminar';
			knopf.parentNode.insertBefore( neu, knopf.nextSibling );
		} )();
		</script>
		<?php
	}

	/** Post-Status, die die Listenansicht „Alle" zeigt (ohne Papierkorb). */
	private static function listen_status() {
		return array( 'publish', 'future', 'draft', 'pending', 'private' );
	}

	/**
	 * Trefferzahlen je Begriff – für genau die übergebenen Beitragstypen.
	 *
	 * Nicht $term->count nehmen: Der zählt alle Beitragstypen, an denen die
	 * Taxonomie hängt, und nur veröffentlichte Beiträge. Da sich Präsenz- und
	 * Online-Seminare dieselben Begriffe teilen, stand im Filter der
	 * Seminarliste eine Zahl, die auch Online-Seminare enthielt – und beim
	 * Filtern kamen dann weniger Einträge, als das Auswahlfeld versprochen
	 * hatte. Genau deshalb muss hier auch die Auswahl der Seminarform ankommen:
	 * Die Zahl im Auswahlfeld soll das versprechen, was die Liste dann zeigt.
	 *
	 * @param string       $taxonomy  Taxonomie-Slug.
	 * @param string|array $post_type Ein Beitragstyp oder mehrere.
	 * @return array [ term_id => Anzahl ], zusätzlich der Schlüssel 'ohne'
	 *               für Einträge ganz ohne Begriff dieser Taxonomie.
	 */
	public static function term_counts( $taxonomy, $post_type ) {
		global $wpdb;

		// Seit Präsenz- und Online-Seminare in einer Liste stehen, kann hier
		// auch mehr als ein Beitragstyp ankommen.
		$typen = array_values( array_unique( array_filter( (array) $post_type, 'strlen' ) ) );
		if ( ! $typen ) {
			return array( 'ohne' => 0 );
		}

		$status      = self::listen_status();
		$platzhalter = implode( ',', array_fill( 0, count( $status ), '%s' ) );
		$typ_platz   = implode( ',', array_fill( 0, count( $typen ), '%s' ) );

		$sql = $wpdb->prepare(
			"SELECT tt.term_id AS id, COUNT( DISTINCT p.ID ) AS n
			   FROM {$wpdb->term_relationships} tr
			   JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			   JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			  WHERE tt.taxonomy = %s AND p.post_type IN ( $typ_platz ) AND p.post_status IN ( $platzhalter )
			  GROUP BY tt.term_id",
			array_merge( array( $taxonomy ), $typen, $status )
		);

		$counts = array();
		foreach ( (array) $wpdb->get_results( $sql ) as $zeile ) {
			$counts[ (int) $zeile->id ] = (int) $zeile->n;
		}

		$counts['ohne'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			  WHERE p.post_type IN ( $typ_platz ) AND p.post_status IN ( $platzhalter )
			    AND p.ID NOT IN (
			        SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			          JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			         WHERE tt.taxonomy = %s )",
			array_merge( $typen, $status, array( $taxonomy ) )
		) );

		return $counts;
	}

	/** Die Auswahlfelder über der Liste. */
	public static function admin_filters( $post_type ) {
		if ( ! in_array( $post_type, bi_seminar_post_types(), true ) ) {
			return;
		}

		// Auf der Sammelliste zuerst die Seminarform – sie entscheidet, worauf
		// sich alle folgenden Auswahlfelder und deren Zahlen beziehen.
		$sammel = ( BI_CPT === $post_type );
		if ( $sammel ) {
			$aktuell = self::listen_form();
			$formen  = array(
				''          => 'Alle Seminarformen',
				'praesenz'  => 'nur Präsenz-Seminare',
				'online'    => 'nur Online-Seminare',
			);
			echo '<label class="screen-reader-text" for="bi_f_form">Seminarform</label><select name="bi_form" id="bi_f_form">';
			foreach ( $formen as $wert => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $wert ),
					selected( $aktuell, $wert, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		}

		// Zahlen in den Auswahlfeldern müssen zu dem passen, was die Liste zeigt.
		$zaehl_typen = $sammel ? self::listen_post_types() : array( $post_type );

		foreach ( self::taxonomies( $post_type ) as $slug => $cfg ) {
			$terms = get_terms( array( 'taxonomy' => $slug, 'hide_empty' => false, 'orderby' => 'name' ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$counts  = self::term_counts( $slug, $zaehl_typen );
			$aktuell = isset( $_GET[ $slug ] ) ? sanitize_text_field( wp_unslash( $_GET[ $slug ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			// Von Hand statt wp_dropdown_categories(): Deren „alle"-Eintrag hat
			// immer den Wert 0, was bei slug-basierten Werten als Suche nach dem
			// Begriff „0" ankäme. Ein leerer Wert lässt WordPress dagegen fallen.
			printf(
				'<label class="screen-reader-text" for="bi_f_%1$s">%2$s</label><select name="%1$s" id="bi_f_%1$s"><option value="">%3$s</option>',
				esc_attr( $slug ),
				esc_attr( $cfg['single'] ),
				esc_html( 'Alle ' . $cfg['label'] )
			);
			foreach ( $terms as $t ) {
				$n = isset( $counts[ (int) $t->term_id ] ) ? (int) $counts[ (int) $t->term_id ] : 0;
				if ( ! $n ) {
					continue; // in dieser Seminarform nicht belegt
				}
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $t->slug ),
					selected( $aktuell, $t->slug, false ),
					esc_html( $t->name . ' (' . number_format_i18n( $n ) . ')' )
				);
			}
			// Die Lücke sichtbar machen: Ohne diesen Eintrag ergeben die Zahlen
			// im Auswahlfeld nie die Gesamtzahl der Liste, und niemand kann sich
			// erklären, wo der Rest geblieben ist.
			if ( ! empty( $counts['ohne'] ) ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( self::OHNE ),
					selected( $aktuell, self::OHNE, false ),
					esc_html( '— ohne Angabe — (' . number_format_i18n( $counts['ohne'] ) . ')' )
				);
			}
			echo '</select>';
		}

		// Ausbildungsreihen: nur anbieten, wenn es welche gibt. Auf einer
		// Installation ohne Reihen wäre das ein Auswahlfeld ohne Auswahl.
		$reihen = get_posts( array(
			'post_type'   => BI_Reihen::CPT,
			'post_status' => 'any',
			'numberposts' => 100,
			'orderby'     => 'title',
			'order'       => 'ASC',
		) );
		if ( $reihen ) {
			$aktuell = isset( $_GET['bi_reihe'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_reihe'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<label class="screen-reader-text" for="bi_f_reihe">Ausbildungsreihe</label>'
				. '<select name="bi_reihe" id="bi_f_reihe"><option value="">Alle Ausbildungsreihen</option>';
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( self::REIHE_MIT ),
				selected( $aktuell, self::REIHE_MIT, false ),
				esc_html( '— alle Reihen-Termine —' )
			);
			foreach ( $reihen as $r ) {
				printf(
					'<option value="%d"%s>%s</option>',
					(int) $r->ID,
					selected( $aktuell, (string) $r->ID, false ),
					esc_html( $r->post_title )
				);
			}
			echo '</select>';
		}

		printf(
			'<label class="screen-reader-text" for="bi_von">Startdatum ab</label>'
			. '<input type="date" name="bi_von" id="bi_von" value="%s" title="Startdatum ab" style="width:auto">'
			. '<label class="screen-reader-text" for="bi_bis">Startdatum bis</label>'
			. '<input type="date" name="bi_bis" id="bi_bis" value="%s" title="Startdatum bis" style="width:auto">',
			esc_attr( self::filter_datum( 'bi_von' ) ),
			esc_attr( self::filter_datum( 'bi_bis' ) )
		);
	}

	/** Ein Datum aus der Filterleiste, auf Y-m-d geprüft. */
	private static function filter_datum( $key ) {
		$raw = isset( $_GET[ $key ] ) ? trim( (string) wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $raw ) {
			return '';
		}
		$dt = DateTime::createFromFormat( 'Y-m-d', $raw );
		return ( $dt && $dt->format( 'Y-m-d' ) === $raw ) ? $raw : '';
	}

	/**
	 * Zeitraum des Startdatums auf die Listenabfrage anwenden.
	 *
	 * Hängt sich an eine vorhandene meta_query an, statt sie zu ersetzen –
	 * sonst würden die Hinweis-Filter des Dashboards (bi_missing_*) beim
	 * Kombinieren still verschwinden.
	 */
	public static function admin_filter_datum( $query ) {
		if ( ! is_admin() || ! $query->is_main_query()
			|| ! in_array( $query->get( 'post_type' ), bi_seminar_post_types(), true ) ) {
			return;
		}

		self::filter_ohne_begriff( $query );

		$von = self::filter_datum( 'bi_von' );
		$bis = self::filter_datum( 'bi_bis' );
		if ( ! $von && ! $bis ) {
			return;
		}

		// Nicht (array) casten: Ist noch keine meta_query gesetzt, liefert
		// WP_Query::get() einen leeren String, und (array) '' ergibt array( '' ) –
		// also eine Bedingung, die keine ist.
		$meta = $query->get( 'meta_query' );
		$meta = is_array( $meta ) ? $meta : array();

		if ( $von ) {
			$meta[] = array( 'key' => '_bi_startdatum', 'value' => $von, 'compare' => '>=', 'type' => 'DATE' );
		}
		if ( $bis ) {
			$meta[] = array( 'key' => '_bi_startdatum', 'value' => $bis, 'compare' => '<=', 'type' => 'DATE' );
		}
		$query->set( 'meta_query', $meta );
	}

	/**
	 * Auswahl „— ohne Angabe —" in eine tax_query übersetzen.
	 *
	 * Der Wert steht in derselben Abfragevariablen wie ein Begriff-Slug, denn er
	 * kommt aus demselben Auswahlfeld. WordPress würde ihn als Slug deuten und
	 * nichts finden – deshalb wird die Variable hier geleert und durch eine
	 * NOT-EXISTS-Bedingung ersetzt. Das geht an dieser Stelle noch, weil
	 * WP_Query die tax_query nach pre_get_posts erneut aufbaut.
	 */
	private static function filter_ohne_begriff( $query ) {
		// Wie bei der meta_query: get() liefert ohne gesetzten Wert einen leeren
		// String, und (array) '' ergäbe array( '' ) – also eine Bedingung, die
		// keine ist.
		$tax_query = $query->get( 'tax_query' );
		$tax_query = is_array( $tax_query ) ? $tax_query : array();
		$neu       = false;

		foreach ( array_keys( self::taxonomies( $query->get( 'post_type' ) ) ) as $slug ) {
			if ( self::OHNE !== $query->get( $slug ) ) {
				continue;
			}
			$query->set( $slug, '' );
			$tax_query[] = array( 'taxonomy' => $slug, 'operator' => 'NOT EXISTS' );
			$neu         = true;
		}

		// Nur anfassen, wenn wirklich etwas dazugekommen ist – sonst schriebe
		// diese Methode bei jeder Listenansicht eine leere tax_query.
		if ( $neu ) {
			$query->set( 'tax_query', $tax_query );
		}
	}

	/** Ist gerade irgendein eigener Filter gesetzt? */
	private static function filter_aktiv( $post_type ) {
		if ( self::filter_datum( 'bi_von' ) || self::filter_datum( 'bi_bis' ) ) {
			return true;
		}
		// Reihen-Filter und die Lücken-Filter aus Dashboard und Datenpflege:
		// Ohne sie hier stünde über einer gefilterten Liste „alle Seminare".
		if ( ! empty( $_GET['bi_reihe'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}
		foreach ( array( 'bi_missing_start', 'bi_missing_ap', 'bi_missing_link', 'bi_missing_themen', 'bi_missing_nummer', 'bi_missing_text' ) as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		// Nur die Sammelliste kennt diesen Filter; auf der Einzelliste der
		// Online-Seminare wäre ein zufällig mitgeschleppter Parameter wirkungslos
		// und der Hinweis „Gefilterte Ansicht" damit eine Falschaussage.
		if ( BI_CPT === $post_type && '' !== self::listen_form() ) {
			return true;
		}
		foreach ( array_keys( self::taxonomies( $post_type ) ) as $slug ) {
			if ( ! empty( $_GET[ $slug ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		return false;
	}

	/**
	 * Hinweis, wie man aus einer gefilterten Liste größere Mengen löscht.
	 *
	 * Erscheint nur bei aktivem Filter: Die Massenaktion gilt immer nur für die
	 * aktuelle Seite, und das ist die Stelle, an der man sonst 65-mal blättert,
	 * ohne zu ahnen, dass „Ansicht anpassen" das in zwei Schritte verwandelt.
	 */
	public static function admin_filter_hinweis() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base
			|| ! in_array( $screen->post_type, bi_seminar_post_types(), true )
			|| ! self::filter_aktiv( $screen->post_type ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>Gefilterte Ansicht.</strong> '
			. 'Zum Löschen größerer Mengen oben rechts unter <em>Ansicht anpassen</em> die Anzahl pro Seite erhöhen, '
			. 'dann in der Titelzeile alle markieren und die Massenaktion <em>„In den Papierkorb verschieben"</em> wählen. '
			. 'Vorher sichern lässt sich die Auswahl als JSON-Paket unter <a href="%s">Datenpflege</a>.</p></div>',
			esc_url( admin_url( 'admin.php?page=' . BI_Datenpflege::PAGE ) )
		);
	}

	/* ===================================================================
	 *  Massenbearbeitung
	 *
	 *  WordPress hat dafür bereits einen Platz: die Massenaktion „Bearbeiten"
	 *  über der Liste klappt einen Bereich auf, in dem Angaben für ALLE
	 *  markierten Einträge gesetzt werden. Genau dorthin gehören die eigenen
	 *  Felder – zusammen mit der Filterleiste ergibt das den gefragten Ablauf:
	 *  nach Bildungszentrum filtern, alle markieren, Ansprechpartner-Adresse
	 *  eintragen, fertig.
	 *
	 *  Angeboten werden nur Felder mit 'bulk' => true. Bewusst eine Erlaubnis-
	 *  und keine Verbotsliste: Ein Startdatum für dreihundert Termine auf
	 *  einmal zu setzen ergibt keinen Sinn, und was in dieser Maske steht, wird
	 *  irgendwann auch geklickt.
	 * =================================================================== */

	/**
	 * Massenaktionen der Seminarliste per POST verschicken.
	 *
	 * WordPress verschickt das Listenformular mit GET. Bei einer Massenaktion
	 * wandert damit jede markierte ID in die Adresszeile – rund 18 Zeichen je
	 * Eintrag. Ab etwa 450 Markierungen reißt das die Längengrenze des Servers
	 * („Request-URI Too Long"), und die Aktion bricht ab, bevor irgendetwas
	 * passiert ist. Bei über zweitausend Seminaren ist das keine Randnotiz.
	 *
	 * Die IDs gehören ohnehin in den Rumpf der Anfrage und nicht in die Adresse:
	 * Sie sind kein Ort, den man verlinkt oder aus dem Verlauf wieder aufruft.
	 * WordPress selbst liest die Massenaktion aus $_REQUEST und kommt mit POST
	 * zurecht; das Umschalten passiert erst beim Absenden, damit das Filtern
	 * (ohne gewählte Aktion) weiterhin per GET läuft und die Filter damit in der
	 * Adresszeile stehen bleiben.
	 *
	 * Ein Nebeneffekt dieses Umschaltens muss dabei aufgeräumt werden: Bei GET
	 * baut der Browser die Adresse komplett aus den Formularfeldern neu, bei
	 * POST bleibt dagegen die Adresse der aktuellen Seite als Ziel stehen. Wer
	 * die Liste vorher über den Knopf „Filtern" eingegrenzt hat, hat darin noch
	 * filter_action=Filtern stehen – und WP_List_Table::current_action() hält
	 * jede Anfrage mit diesem Parameter für eine Filterung und führt gar keine
	 * Massenaktion aus. Die Seite lädt dann neu, als wäre nichts gewesen: keine
	 * Änderung, keine Meldung. Deshalb wird der Parameter beim Absenden aus der
	 * Ziel-Adresse entfernt.
	 */
	public static function bulk_per_post() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base
			|| ! in_array( $screen->post_type, array_merge( bi_seminar_post_types(), array( BI_Reihen::CPT ) ), true ) ) {
			return;
		}

		/*
		 * Zweite Grenze, gefährlicher als die erste: PHP nimmt je Anfrage nur
		 * max_input_vars Felder entgegen (üblich 1000) und verwirft den Rest
		 * OHNE Fehlermeldung. Eine Massenaktion über dieser Grenze würde also
		 * scheinbar gelingen und einen Teil der Markierungen einfach übergehen.
		 * Deshalb wird hier vorher gebremst, statt hinterher zu rätseln.
		 */
		$limit  = (int) ini_get( 'max_input_vars' );
		$sicher = $limit > 0 ? max( 50, $limit - 100 ) : 0; // Puffer für die übrigen Formularfelder
		?>
		<script>
		( function () {
			var form = document.getElementById( 'posts-filter' );
			if ( ! form ) { return; }
			var sicher   = <?php echo (int) $sicher; ?>;
			var textMax  = <?php echo (int) self::BULK_TEXT_MAX; ?>;

			function anzahlMarkiert() {
				return form.querySelectorAll( 'input[name="post[]"]:checked' ).length;
			}

			/*
			 * Die beiden Fließtext-Felder sperren, sobald zu viele Einträge
			 * markiert sind. Ohne das tippt jemand einen langen Text und erfährt
			 * erst nach dem Absenden, dass er nicht übernommen wurde – der Text
			 * ist dann weg, obwohl der Server nur das Richtige getan hat.
			 */
			function texteSperren() {
				var block = document.querySelector( '.bi-bulk-texte' );
				if ( ! block ) { return; }
				var zuViele = anzahlMarkiert() > textMax;
				var sperre  = block.querySelector( '.bi-bulk-texte__sperre' );

				// Auch das Titelfeld: Es steht in demselben Block und unter
				// derselben Grenze, ist aber ein <input> und wäre von einer
				// Auswahl nur über 'textarea' nicht erfasst worden.
				block.querySelectorAll( 'textarea, input.bi-bulk-titel' ).forEach( function ( feld ) {
					feld.disabled = zuViele;
					if ( zuViele ) { feld.value = ''; }
				} );
				if ( sperre ) {
					sperre.style.display = zuViele ? 'block' : 'none';
					sperre.textContent = zuViele
						? anzahlMarkiert().toLocaleString( 'de-DE' ) + ' Einträge markiert – Titel, Beschreibung '
						  + 'und Themen lassen sich nur bis ' + textMax.toLocaleString( 'de-DE' ) + ' setzen.'
						: '';
				}
			}

			// Der Bereich entsteht erst, wenn jemand „Bearbeiten" wählt; deshalb
			// bei jedem Klick in der Liste nachsehen statt einmal beim Laden.
			document.addEventListener( 'click', function () {
				window.setTimeout( texteSperren, 0 );
			}, true );

			form.addEventListener( 'submit', function ( e ) {
				var oben  = document.getElementById( 'bulk-action-selector-top' );
				var unten = document.getElementById( 'bulk-action-selector-bottom' );
				var aktiv = ( oben && '-1' !== oben.value ) || ( unten && '-1' !== unten.value );

				// Ohne gewählte Aktion ist es eine Filterung – die soll in der
				// Adresszeile landen, damit sie verlinkbar bleibt.
				form.method = aktiv ? 'post' : 'get';

				if ( aktiv ) {
					// filter_action aus der Ziel-Adresse nehmen, sonst verwirft
					// WordPress die Massenaktion (siehe Erläuterung oben).
					var ziel = new URL( window.location.href );
					ziel.searchParams.delete( 'filter_action' );
					form.action = ziel.pathname + ziel.search;
				} else {
					// Beim Filtern wieder das Standardziel verwenden, damit eine
					// zuvor gesetzte Adresse nicht kleben bleibt.
					form.removeAttribute( 'action' );
				}

				if ( ! aktiv || ! sicher ) { return; }

				var markiert = anzahlMarkiert();
				if ( markiert > sicher ) {
					e.preventDefault();
					window.alert(
						markiert.toLocaleString( 'de-DE' ) + ' Einträge sind markiert.\n\n'
						+ 'Dieser Server verarbeitet höchstens etwa ' + sicher.toLocaleString( 'de-DE' )
						+ ' auf einmal; darüber verwirft PHP den Rest stillschweigend – die Aktion sähe '
						+ 'erfolgreich aus, hätte aber nur einen Teil erfasst.\n\n'
						+ 'Bitte unter „Ansicht anpassen" die Anzahl pro Seite auf höchstens '
						+ sicher.toLocaleString( 'de-DE' ) + ' setzen und in mehreren Durchgängen arbeiten.'
					);
				}
			} );
		} )();
		</script>
		<?php
	}

	/** Felder, die sich sinnvoll für viele Einträge auf einmal setzen lassen. */
	public static function bulk_felder( $post_type ) {
		$out = array();
		foreach ( self::meta_fields( $post_type ) as $key => $cfg ) {
			if ( ! empty( $cfg['bulk'] ) ) {
				$out[ $key ] = $cfg;
			}
		}
		return $out;
	}

	/** Die Felder im aufgeklappten Bereich der Massenbearbeitung. */
	public static function bulk_edit_felder( $spalte, $post_type ) {
		// Der Haken feuert je Spalte; einmal ausgeben genügt.
		if ( 'bi_startdatum' !== $spalte || ! in_array( $post_type, bi_seminar_post_types(), true ) ) {
			return;
		}

		$felder = self::bulk_felder( $post_type );
		$taxes  = self::taxonomies( $post_type );
		wp_nonce_field( 'bi_bulk_edit', 'bi_bulk_nonce' );
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<span class="title">Bildungsprogramm</span>
				<p style="margin:0 0 8px;color:#646970">
					Nur ausgefüllte Felder werden übernommen. Was leer bleibt, bleibt bei jedem Eintrag unverändert.
				</p>

				<?php foreach ( $taxes as $slug => $cfg ) : ?>
					<?php
					$mehrfach = ! empty( $cfg['multi'] );
					$terms    = get_terms( array( 'taxonomy' => $slug, 'hide_empty' => false, 'orderby' => 'name' ) );
					if ( is_wp_error( $terms ) || ! $terms ) {
						continue;
					}
					if ( BI_TAX_ORT === $slug && BI_CPT === $post_type ) {
						list( $terms ) = self::ort_auswahl( (array) $terms, array() );
					}
					?>
					<label class="inline-edit-group">
						<span class="title" style="width:auto"><?php echo esc_html( $cfg['single'] ); ?></span>
						<select name="bi_bulk_tax[<?php echo esc_attr( $slug ); ?>]">
							<option value="">— unverändert —</option>
							<?php foreach ( $terms as $t ) : ?>
								<option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( $mehrfach ) : ?>
							<?php /* Mehrfachwerte: „hinzufügen oder ersetzen?" ist mehrdeutig – also
							         wird gefragt, statt zu raten. Voreinstellung ist das Hinzufügen,
							         weil es nichts wegnimmt. */ ?>
							<select name="bi_bulk_tax_modus[<?php echo esc_attr( $slug ); ?>]" style="margin-left:6px">
								<option value="add">hinzufügen</option>
								<option value="replace">ersetzen (alle anderen entfernen)</option>
								<option value="remove">entfernen</option>
							</select>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>

				<?php foreach ( $felder as $key => $cfg ) : ?>
					<?php
					if ( ! empty( $cfg['bulk_max'] ) ) {
						continue; // Textfelder stehen weiter unten, mit ihrer Mengengrenze
					}
					?>
					<label class="inline-edit-group">
						<span class="title" style="width:auto"><?php echo esc_html( $cfg['label'] ); ?></span>
						<?php if ( 'bool' === $cfg['type'] ) : ?>
							<select name="bi_bulk[<?php echo esc_attr( $key ); ?>]">
								<option value="">— unverändert —</option>
								<option value="1">ja</option>
								<option value="0">nein</option>
							</select>
						<?php elseif ( 'select' === $cfg['type'] ) : ?>
							<select name="bi_bulk[<?php echo esc_attr( $key ); ?>]">
								<option value="">— unverändert —</option>
								<?php foreach ( (array) $cfg['options'] as $ov => $ol ) : ?>
									<option value="<?php echo esc_attr( $ov ); ?>"><?php echo esc_html( $ol ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="text" name="bi_bulk[<?php echo esc_attr( $key ); ?>]" value=""
							       placeholder="— unverändert —">
						<?php endif; ?>
					</label>
				<?php endforeach; ?>

				<p style="margin:10px 0 0;color:#646970">
					Ein Feld leeren statt setzen: <code>--</code> eintragen.
				</p>

				<?php // ---- Fließtexte: nur bis zur Mengengrenze ---- ?>
				<div class="bi-bulk-texte" style="margin-top:14px;padding-top:12px;border-top:1px solid #dcdcde">
					<p style="margin:0 0 8px">
						<strong>Titel und Inhalte</strong>
						<span style="color:#646970">– nur bis <?php echo esc_html( number_format_i18n( self::BULK_TEXT_MAX ) ); ?>
						markierte Einträge. Ein Titel oder ein Fließtext gilt für ein Seminar, nicht für einen
						halben Jahrgang; überschrieben wäre der bisherige Text nicht wiederherstellbar.</span>
					</p>
					<p class="bi-bulk-texte__sperre" style="display:none;margin:0 0 8px;color:#b32d2e"><strong>
						Zu viele Einträge markiert – diese Felder bleiben leer.
					</strong></p>

					<label class="inline-edit-group" style="display:block">
						<span class="title" style="width:auto;display:block">Seminartitel</span>
						<input type="text" name="bi_bulk_title" class="bi-bulk-titel" style="width:100%"
						       placeholder="— unverändert —">
						<span style="display:block;margin:3px 0 10px;color:#646970">
							Setzt bei allen markierten Terminen denselben Titel – dafür ist das Feld da:
							Schreibvarianten desselben Seminars zusammenführen. Die Adresse der Seminarseite
							ändert sich dabei <strong>nicht</strong>, bestehende Links bleiben gültig.
							Ein Titel lässt sich hier nicht leeren.
						</span>
					</label>

					<label class="inline-edit-group" style="display:block">
						<span class="title" style="width:auto;display:block">Seminarbeschreibung</span>
						<textarea name="bi_bulk_content" rows="4" style="width:100%"
						          placeholder="— unverändert —"></textarea>
					</label>

					<?php foreach ( $felder as $key => $cfg ) : ?>
						<?php if ( empty( $cfg['bulk_max'] ) ) { continue; } ?>
						<label class="inline-edit-group" style="display:block">
							<span class="title" style="width:auto;display:block"><?php echo esc_html( $cfg['label'] ); ?></span>
							<textarea name="bi_bulk[<?php echo esc_attr( $key ); ?>]" rows="4" style="width:100%"
							          placeholder="— unverändert —"></textarea>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Werte der Massenbearbeitung schreiben.
	 *
	 * Läuft an save_post, deshalb die strenge Torprüfung: Ohne die Kennung
	 * bulk_edit und den eigenen Nonce würde jeder andere Speichervorgang hier
	 * hineinlaufen. Leere Felder bedeuten „unverändert" – ohne diese Regel
	 * würde eine Massenbearbeitung, die nur eine Adresse setzen soll, alle
	 * übrigen Felder der markierten Einträge leeren.
	 */
	public static function bulk_edit_speichern( $post_id, $post ) {
		if ( empty( $_REQUEST['bulk_edit'] ) || empty( $_REQUEST['bi_bulk_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['bi_bulk_nonce'] ) ), 'bi_bulk_edit' )
			|| ! in_array( $post->post_type, bi_seminar_post_types(), true )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$eingabe = ( isset( $_REQUEST['bi_bulk'] ) && is_array( $_REQUEST['bi_bulk'] ) ) ? wp_unslash( $_REQUEST['bi_bulk'] ) : array();
		foreach ( self::bulk_felder( $post->post_type ) as $key => $cfg ) {
			if ( ! isset( $eingabe[ $key ] ) ) {
				continue;
			}
			$roh = is_string( $eingabe[ $key ] ) ? trim( $eingabe[ $key ] ) : '';
			if ( '' === $roh ) {
				continue; // unverändert lassen
			}
			// Fließtexte nur bis zur Mengengrenze – und wenn übergangen, dann
			// hörbar (siehe bulk_text_notice).
			if ( ! empty( $cfg['bulk_max'] ) && ! self::bulk_text_erlaubt() ) {
				self::bulk_text_gemerkt();
				continue;
			}
			// Zwei Bindestriche leeren das Feld – anders ließe sich eine Angabe
			// für viele Einträge nur setzen, nie entfernen.
			$wert = ( '--' === $roh ) ? '' : self::bulk_wert( $roh, $cfg );
			update_post_meta( $post_id, $key, $wert );
		}

		$tax_eingabe = ( isset( $_REQUEST['bi_bulk_tax'] ) && is_array( $_REQUEST['bi_bulk_tax'] ) ) ? wp_unslash( $_REQUEST['bi_bulk_tax'] ) : array();
		$tax_modus   = ( isset( $_REQUEST['bi_bulk_tax_modus'] ) && is_array( $_REQUEST['bi_bulk_tax_modus'] ) ) ? wp_unslash( $_REQUEST['bi_bulk_tax_modus'] ) : array();

		foreach ( self::taxonomies( $post->post_type ) as $slug => $cfg ) {
			if ( empty( $tax_eingabe[ $slug ] ) ) {
				continue;
			}
			$term_id = (int) $tax_eingabe[ $slug ];
			if ( $term_id <= 0 ) {
				continue;
			}

			// Einzelwert-Taxonomien kennen nur „ersetzen" – mehr als einen Begriff
			// dürfen sie ohnehin nicht tragen.
			if ( empty( $cfg['multi'] ) ) {
				wp_set_object_terms( $post_id, array( $term_id ), $slug, false );
				continue;
			}

			$modus = isset( $tax_modus[ $slug ] ) ? sanitize_key( $tax_modus[ $slug ] ) : 'add';
			if ( 'replace' === $modus ) {
				wp_set_object_terms( $post_id, array( $term_id ), $slug, false );
			} elseif ( 'remove' === $modus ) {
				wp_remove_object_terms( $post_id, array( $term_id ), $slug );
			} else {
				// Anhängen: Ein bereits vorhandener Begriff bleibt einfach stehen.
				wp_set_object_terms( $post_id, array( $term_id ), $slug, true );
			}
		}
	}

	/** Einen Wert aus der Massenbearbeitung nach Feldtyp säubern. */
	/** Wie viele Einträge hat die laufende Massenbearbeitung markiert? */
	public static function bulk_anzahl() {
		$ids = ( isset( $_REQUEST['post'] ) && is_array( $_REQUEST['post'] ) ) ? $_REQUEST['post'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return count( $ids );
	}

	/**
	 * Dürfen in diesem Durchgang die Textfelder (Themen, Beschreibung)
	 * geschrieben werden?
	 *
	 * Ohne erkennbare Markierung lautet die Antwort nein – das ist die
	 * vorsichtige Richtung: Lieber einmal nichts tun als einmal zu viel
	 * überschreiben.
	 */
	public static function bulk_text_erlaubt() {
		$n = self::bulk_anzahl();
		return $n > 0 && $n <= self::BULK_TEXT_MAX;
	}

	/** Merkt sich, dass ein Text wegen der Mengengrenze NICHT geschrieben wurde. */
	private static function bulk_text_gemerkt() {
		set_transient( 'bi_bulk_text_skip_' . get_current_user_id(), self::bulk_anzahl(), MINUTE_IN_SECONDS );
	}

	/**
	 * Rückmeldung nach einer Massenbearbeitung, deren Textfelder übergangen
	 * wurden. Ohne sie hielte die bearbeitende Person den Text für gesetzt –
	 * die Liste sieht hinterher aus wie vorher, und niemand sagt, warum.
	 */
	public static function bulk_text_notice() {
		$key = 'bi_bulk_text_skip_' . get_current_user_id();
		$n   = (int) get_transient( $key );
		if ( ! $n ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>Titel, Themen und Beschreibung wurden nicht übernommen.</strong> '
			. 'Markiert waren %s Einträge; für diese Felder liegt die Grenze bei %s. '
			. 'Alle übrigen Angaben der Massenbearbeitung wurden gesetzt.</p>'
			. '<p>Ein Titel oder ein Fließtext gilt für ein Seminar, nicht für einen halben Jahrgang – und der '
			. 'überschriebene Text wäre nicht wiederherstellbar. Bitte in kleineren Mengen arbeiten.</p></div>',
			esc_html( number_format_i18n( $n ) ),
			esc_html( number_format_i18n( self::BULK_TEXT_MAX ) )
		);
	}

	/**
	 * Seminartitel und -beschreibung in der Massenbearbeitung setzen.
	 *
	 * Läuft über wp_insert_post_data statt über save_post: WordPress schreibt
	 * den Beitrag hier gerade ohnehin, Titel und Text reisen also mit. Über
	 * save_post bräuchte es ein zweites wp_update_post() – mitten im ersten,
	 * also eine Verschachtelung, die sich niemand wünscht.
	 *
	 * ========================================================================
	 *  WARUM DER TITEL HIER STEHT
	 * ========================================================================
	 *  WordPress bietet den Titel in der Massenbearbeitung bewusst nicht an:
	 *  Dreihundert Einträgen denselben Titel zu geben ist fast nie gewollt.
	 *  Bei diesen Seminaren ist es der Normalfall – ein Seminar hat zwanzig,
	 *  dreißig, vierzig Termine, und alle heißen gleich. Genau daran hängt
	 *  mehr, als man dem Titel ansieht: „Weitere Termine zu diesem Seminar"
	 *  gruppiert über den Titel (siehe BI_Detail::weitere_termine). Eine
	 *  Schreibvariante trennt deshalb Termine, die zusammengehören – aus
	 *  „Betriebsrats" und „Betriebsrates" werden zwei Seminare, und keines
	 *  von beiden zeigt die Termine des anderen.
	 *
	 *  Diese Varianten von Hand einzeln zu berichtigen ist bei vierzig
	 *  Terminen keine Arbeit, die jemand macht. Deshalb steht der Titel hier.
	 *
	 * ========================================================================
	 *  DREI ABSICHERUNGEN
	 * ========================================================================
	 *  1. DIESELBE MENGENGRENZE wie für die Fließtexte (BULK_TEXT_MAX). Der
	 *     Titel ist die Identität eines Eintrags; ihn für einen halben
	 *     Jahrgang zu überschreiben wäre nicht rückgängig zu machen.
	 *
	 *  2. KEIN LEEREN. Bei den übrigen Feldern leert „--" den Wert – hier
	 *     nicht. Ein Seminar ohne Titel wäre in der Liste „(kein Titel)", in
	 *     der Suche unauffindbar und in der Terminegruppierung mit jedem
	 *     anderen titellosen Eintrag verschmolzen.
	 *
	 *  3. DIE ADRESSE BLEIBT. wp_insert_post_data bekommt post_name bereits
	 *     fertig gereicht – WordPress berechnet den Namen vorher und nur für
	 *     neue Beiträge aus dem Titel. Ein hier geänderter Titel lässt den
	 *     Beitragsnamen also unangetastet, bestehende Links bleiben gültig.
	 *     (Das ist auch der Grund, warum die Adressen der Altbestände nicht
	 *     zum Titel passen müssen.)
	 *
	 *  Zeilenumbrüche und doppelte Leerzeichen fallen dabei weg
	 *  (wp_strip_all_tags mit $remove_breaks): Ein Seminartitel ist eine
	 *  Zeile. Im Bestand stehen Titel mit einem echten Umbruch darin – die
	 *  sehen in der Liste aus wie zwei Einträge und trennen ebenfalls
	 *  Termine, die zusammengehören.
	 */
	public static function bulk_edit_post_texte( $data, $postarr ) {
		if ( empty( $_REQUEST['bulk_edit'] ) || empty( $_REQUEST['bi_bulk_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['bi_bulk_nonce'] ) ), 'bi_bulk_edit' )
			|| ! in_array( $data['post_type'] ?? '', bi_seminar_post_types(), true ) ) {
			return $data;
		}
		$id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return $data;
		}

		$titel = isset( $_REQUEST['bi_bulk_title'] ) ? trim( (string) wp_unslash( $_REQUEST['bi_bulk_title'] ) ) : '';
		$text  = isset( $_REQUEST['bi_bulk_content'] ) ? trim( (string) wp_unslash( $_REQUEST['bi_bulk_content'] ) ) : '';

		if ( '' === $titel && '' === $text ) {
			return $data; // beides unverändert lassen
		}
		if ( ! self::bulk_text_erlaubt() ) {
			self::bulk_text_gemerkt();
			return $data;
		}

		if ( '' !== $titel ) {
			$neu = trim( wp_strip_all_tags( $titel, true ) );
			// Bliebe nach dem Säubern nichts übrig („<b></b>"), ist das kein
			// Auftrag zum Leeren, sondern eine unbrauchbare Eingabe.
			if ( '' !== $neu ) {
				if ( $neu !== ( $data['post_title'] ?? '' ) ) {
					self::bulk_titel_sync_pruefen( $id );
				}
				$data['post_title'] = $neu;
			}
		}

		if ( '' !== $text ) {
			$data['post_content'] = ( '--' === $text ) ? '' : wp_kses_post( $text );
		}

		return $data;
	}

	/**
	 * Merken, wenn ein geänderter Titel an einem Eintrag aus dem Abgleich hängt.
	 *
	 * Diese Einträge holen sich beim nächsten Lauf alles aus ihrer Quelle
	 * zurück, den Titel eingeschlossen (siehe BI_Sync). Eine Korrektur hier
	 * hielte also bis zum nächsten Abgleich und wäre dann still wieder weg –
	 * die schlechteste aller Rückmeldungen. Gesagt wird es hinterher und mit
	 * Zahl, siehe bulk_titel_notice().
	 */
	private static function bulk_titel_sync_pruefen( $post_id ) {
		if ( '' === (string) get_post_meta( $post_id, '_bi_sync_quelle', true ) ) {
			return;
		}
		$key = 'bi_bulk_titel_sync_' . get_current_user_id();
		set_transient( $key, (int) get_transient( $key ) + 1, MINUTE_IN_SECONDS );
	}

	/** „Der Abgleich holt sich diese Titel zurück" – einmal, dann weggeklickt. */
	public static function bulk_titel_notice() {
		$key = 'bi_bulk_titel_sync_' . get_current_user_id();
		$n   = (int) get_transient( $key );
		if ( ! $n ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%s der geänderten Titel gehören zu Einträgen aus dem Abgleich.</strong> '
			. 'Der Titel steht jetzt so da, wie du ihn gesetzt hast – aber beim nächsten Abgleich holt die Quelle ihren eigenen zurück.</p>'
			. '<p>Dauerhaft wird die Änderung nur, wenn sie in der Quell-Installation gemacht wird oder der Termin unter '
			. '<em>Abgleich</em> aus dem Abgleich gelöst ist.</p></div>',
			esc_html( number_format_i18n( $n ) )
		);
	}

	private static function bulk_wert( $roh, $cfg ) {
		switch ( $cfg['type'] ) {
			case 'bool':
				return ( '1' === $roh ) ? '1' : '0';
			// Formatierter Text (Themen im Seminar): Absätze und Listen müssen
			// erhalten bleiben – sanitize_text_field() würde beides plätten.
			case 'html':
			case 'textarea':
				return wp_kses_post( $roh );
			case 'email':
				return sanitize_email( $roh );
			case 'url':
				return esc_url_raw( $roh );
			case 'money':
				return self::money_parse( $roh );
			case 'select':
				return isset( $cfg['options'][ $roh ] ) ? sanitize_text_field( $roh ) : '';
			default:
				return sanitize_text_field( $roh );
		}
	}

	/** ---------- Backend-Suche inkl. Seminarnummer ---------- */

	/**
	 * Greift nur bei der Haupt-Suchquery einer Seminar-Liste im Backend.
	 *
	 * ACHTUNG BEIM BEITRAGSTYP: Sobald die Liste beide Seminarformen zeigt –
	 * der Normalfall, siehe admin_filter_form() –, ist post_type ein ARRAY.
	 * Ein in_array() auf den rohen Wert ging dann ins Leere, und die Suche über
	 * die Seminarnummer fiel still aus: Man tippte eine Nummer ein und bekam
	 * „Keine Seminare gefunden", obwohl das Seminar in der Liste steht.
	 */
	private static function is_seminar_admin_search( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return false;
		}
		if ( '' === trim( (string) $query->get( 's' ) ) ) {
			return false;
		}
		$typen = array_intersect( (array) $query->get( 'post_type' ), bi_seminar_post_types() );
		return ! empty( $typen );
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
