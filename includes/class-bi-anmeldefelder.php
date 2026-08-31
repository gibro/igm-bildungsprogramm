<?php
/**
 * Feld-Bestand des Anmeldeformulars – die eine Liste, aus der sich alle
 * Anmeldeformulare bedienen.
 *
 * ============================================================================
 *  WARUM EIN BESTAND UND NICHT FELDER JE FORMULAR
 * ============================================================================
 *  Ein Feld ist mehr als eine Zeile im Formular: Sein Schlüssel steht in der
 *  JSON-Spalte jeder Anmeldung, in der Kopfzeile des CSV-Exports und in den
 *  Mail-Platzhaltern. Dürfte jedes Formular sein eigenes „Betrieb" anlegen,
 *  gäbe es nach kurzer Zeit `betrieb`, `firma` und `arbeitgeber` nebeneinander –
 *  drei Spalten im Export, drei Platzhalter in den Vorlagen, und keine
 *  Auswertung über alle Anmeldungen hinweg.
 *
 *  Deshalb: Der Bestand hier ist die Wahrheit über die Felder. Ein Formular
 *  (BI_Formulare) WÄHLT daraus aus und legt fest, auf welcher Seite, an welcher
 *  Stelle, wie breit und ob als Pflichtangabe.
 *
 * ============================================================================
 *  ZWEI KLASSEN VON FELDERN – wie bei BI_Felder
 * ============================================================================
 *    Kernfelder (Schlüssel ohne Präfix)
 *      → Schlüssel und Typ liegen fest, löschen nicht möglich.
 *      → Beschriftung, Platzhaltertext und Hinweis dürfen geändert werden.
 *      → Sieben von ihnen füllen eigene Tabellenspalten (siehe spalten()).
 *
 *    Eigene Felder (Präfix `x_`)
 *      → frei anlegbar, benennbar und löschbar; landen in der JSON-Spalte
 *        `data` der Anmeldung und bekommen einen Mail-Platzhalter.
 *
 *  Das Präfix ist die ganze Unterscheidung. Ein eigenes Feld kann damit nie
 *  einen Kernschlüssel überdecken, und eine spätere Plugin-Version darf neue
 *  Kernfelder einführen, ohne mit selbst angelegten zu kollidieren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Anmeldefelder {

	/** Eigene Felder: Schlüssel => Definition */
	const OPTION_EIGEN = 'bi_anmeldefelder';

	/** Geänderte Texte der Kernfelder: Schlüssel => [label, placeholder, hint] */
	const OPTION_TEXTE = 'bi_anmeldefeld_texte';

	/** Präfix, das ein eigenes Feld von einem Kernfeld unterscheidet */
	const PREFIX = 'x_';

	public static function init() {
		add_action( 'admin_post_bi_anmeldefeld_add',    array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_bi_anmeldefeld_save',   array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bi_anmeldefeld_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_bi_anmeldefeld_texte',  array( __CLASS__, 'handle_texte' ) );
	}

	/* ===================================================================
	 *  Registry
	 * =================================================================== */

	/**
	 * Feldtypen, die sich neu anlegen lassen: Typ => Beschriftung.
	 *
	 * `freistellung` fehlt hier mit Absicht: Dieses Feld füllt seine Auswahl aus
	 * den Freistellungen des Seminars und lässt sich nicht zweimal sinnvoll
	 * anlegen. Es bleibt ein Kernfeld.
	 */
	public static function types() {
		return array(
			'text'     => 'Einzeiliger Text',
			'textarea' => 'Mehrzeiliger Text',
			'email'    => 'E-Mail-Adresse',
			'tel'      => 'Telefonnummer',
			'plz'      => 'Postleitzahl (5 Ziffern)',
			'date'     => 'Datum',
			'select'   => 'Auswahlliste',
			'radio'    => 'Auswahl (Schaltflächen nebeneinander)',
			'checkbox' => 'Kästchen zum Ankreuzen',
		);
	}

	/**
	 * Beschriftung eines Feldtyps für die Anzeige.
	 *
	 * Kennt zusätzlich die Typen, die sich nicht neu anlegen lassen – sonst
	 * stünde im Editor „· freistellung" statt einer Auskunft.
	 */
	public static function type_label( $type ) {
		$sonder = array( 'freistellung' => 'Freistellung des Seminars' );
		return self::types()[ $type ] ?? ( $sonder[ $type ] ?? $type );
	}

	/**
	 * Die Kernfelder – der Bestand, mit dem das Plugin ausgeliefert wird.
	 *
	 * Bis zur Formularverwaltung standen genau diese Felder fest verdrahtet in
	 * BI_Registration::form_steps(). Sie stehen jetzt hier, damit es EINE Liste
	 * gibt: Das mitgelieferte Standardformular in BI_Formulare::defaults()
	 * verweist nur noch auf diese Schlüssel.
	 *
	 * `max` ist die fachliche Höchstlänge in Zeichen und wird zweifach
	 * durchgesetzt: als maxlength im Browser und noch einmal serverseitig in
	 * BI_Registration::handle_submit(). Ohne die zweite Prüfung schriebe ein
	 * direkt abgesetztes POST beliebig große Werte in Datenbank, JSON-Spalte und
	 * alle drei Mailtexte. Die Werte liegen bewusst über jeder realen Eingabe
	 * und unter der jeweiligen Spaltenbreite in BI_Registration::create_table().
	 */
	public static function kernfelder() {
		return array(
			'anrede'          => array( 'label' => 'Anrede', 'type' => 'select', 'options' => array( '', 'Frau', 'Herr', 'Divers', 'Keine Angabe' ) ),
			'titel'           => array( 'label' => 'Titel', 'type' => 'text', 'placeholder' => 'z. B. Dr.', 'max' => 60 ),
			'vorname'         => array( 'label' => 'Vorname', 'type' => 'text', 'placeholder' => 'Vorname', 'max' => 100 ),
			'nachname'        => array( 'label' => 'Nachname', 'type' => 'text', 'placeholder' => 'Nachname', 'max' => 100 ),
			'strasse'         => array( 'label' => 'Straße & Hausnummer', 'type' => 'text', 'placeholder' => 'Musterstraße 12', 'max' => 150 ),
			'plz'             => array( 'label' => 'PLZ', 'type' => 'plz', 'placeholder' => '12345' ),
			'ort'             => array( 'label' => 'Ort', 'type' => 'text', 'placeholder' => 'Beispielort', 'max' => 100 ),

			'telefon'         => array( 'label' => 'Telefon', 'type' => 'tel', 'placeholder' => '0202 1234567', 'max' => 40 ),
			'mobil'           => array( 'label' => 'Mobiltelefon', 'type' => 'tel', 'placeholder' => '0151 1234567', 'max' => 40 ),
			'email'           => array( 'label' => 'E-Mail', 'type' => 'email', 'placeholder' => 'name@beispiel.de', 'max' => 180 ),
			'mitglied'        => array( 'label' => 'Bist du Mitglied der IG Metall?', 'type' => 'radio', 'default' => 'ja', 'options' => array( 'ja' => 'Ja', 'nein' => 'Nein' ) ),
			'mitgliedsnummer' => array( 'label' => 'Mitgliedsnummer', 'type' => 'text', 'placeholder' => 'z. B. 1234567890', 'max' => 40 ),

			'betrieb'         => array( 'label' => 'Betrieb / Arbeitgeber', 'type' => 'text', 'placeholder' => 'Name des Unternehmens', 'max' => 150 ),
			'betrieb_strasse' => array( 'label' => 'Straße & Hausnummer (Betrieb)', 'type' => 'text', 'placeholder' => 'Werkstraße 1', 'max' => 150 ),
			'betrieb_plz'     => array( 'label' => 'PLZ (Betrieb)', 'type' => 'plz', 'placeholder' => '12345', 'hint' => 'Bestimmt die zuständige Geschäftsstelle.' ),
			'betrieb_ort'     => array( 'label' => 'Ort (Betrieb)', 'type' => 'text', 'placeholder' => 'Beispielort', 'max' => 100 ),
			'betrieb_email'   => array( 'label' => 'E-Mail (Betrieb)', 'type' => 'email', 'placeholder' => 'personal@beispiel-gmbh.de', 'hint' => 'Dienstliche Adresse, z. B. von Personalabteilung oder Betriebsrat.', 'max' => 180 ),
			'funktion'        => array( 'label' => 'Funktion im Betriebsrat', 'type' => 'select', 'options' => array( '', 'BR-Mitglied', 'BR-Vorsitz', 'stellv. BR-Vorsitz', 'Ersatzmitglied', 'JAV', 'Schwerbehindertenvertretung' ) ),
			'freistellung'    => array( 'label' => 'Freistellung nach', 'type' => 'freistellung' ),

			'bemerkungen'     => array( 'label' => 'Bemerkungen / Sonstiges', 'type' => 'textarea', 'placeholder' => 'Anmerkungen zur Anreise, besondere Wünsche o. Ä.', 'max' => 2000 ),
		);
	}

	/**
	 * Kernfelder, die eine eigene Spalte in wp_bi_anmeldungen füllen:
	 * Feldschlüssel => Spaltenname.
	 *
	 * Fehlt eines davon in einem Formular, bleibt die Spalte leer. Das ist bei
	 * `telefon` oder `bemerkungen` folgenlos, bei `betrieb_plz` nicht: An dieser
	 * Spalte hängt BI_PLZ::lookup() und damit die gesamte
	 * Geschäftsstellen-Benachrichtigung. Der Formulareditor warnt deshalb, wenn
	 * sie fehlt (siehe BI_Formulare::warnungen).
	 */
	public static function spalten() {
		return array(
			'vorname'     => 'vorname',
			'nachname'    => 'nachname',
			'email'       => 'email',
			'telefon'     => 'telefon',
			'betrieb'     => 'betrieb',
			'betrieb_plz' => 'plz',
			'bemerkungen' => 'nachricht',
		);
	}

	/**
	 * Felder, ohne die ein Formular nicht auskommt – der Editor nimmt sie nicht
	 * heraus.
	 *
	 * Ohne Namen und Mailadresse gäbe es niemanden, an den die Bestätigung
	 * ginge, und in der Anmeldeliste stünde eine Zeile ohne Person. Beides ist
	 * kein Formular mehr, sondern ein Datenverlust mit Absendeknopf.
	 */
	public static function pflicht() {
		return array( 'vorname', 'nachname', 'email' );
	}

	/** Ist das ein Kernfeld (also vom Code vorausgesetzt)? */
	public static function ist_kernfeld( $key ) {
		return 0 !== strpos( (string) $key, self::PREFIX );
	}

	/** Eigene Felder in gespeicherter Reihenfolge: Schlüssel => Definition. */
	public static function eigene() {
		$felder = get_option( self::OPTION_EIGEN, array() );
		return is_array( $felder ) ? $felder : array();
	}

	/** Geänderte Texte der Kernfelder. */
	public static function texte() {
		$texte = get_option( self::OPTION_TEXTE, array() );
		return is_array( $texte ) ? $texte : array();
	}

	/**
	 * Der ganze Bestand: Kernfelder mit ihren geänderten Texten, danach die
	 * eigenen Felder. Alles, was ein Formular anbieten kann.
	 */
	public static function alle() {
		$texte = self::texte();
		$out   = array();
		foreach ( self::kernfelder() as $key => $cfg ) {
			foreach ( array( 'label', 'placeholder', 'hint' ) as $feld ) {
				if ( isset( $texte[ $key ][ $feld ] ) && '' !== $texte[ $key ][ $feld ] ) {
					$cfg[ $feld ] = (string) $texte[ $key ][ $feld ];
				}
			}
			$out[ $key ] = $cfg;
		}
		foreach ( self::eigene() as $key => $cfg ) {
			// Doppelter Boden: Ein eigenes Feld darf ein Kernfeld nie überdecken.
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ] = array(
				'label'       => (string) ( $cfg['label'] ?? $key ),
				'type'        => (string) ( $cfg['type'] ?? 'text' ),
				'placeholder' => (string) ( $cfg['placeholder'] ?? '' ),
				'hint'        => (string) ( $cfg['hint'] ?? '' ),
				'options'     => (array) ( $cfg['options'] ?? array() ),
				'default'     => $cfg['default'] ?? '',
				'max'         => (int) ( $cfg['max'] ?? 200 ),
				'eigen'       => true,
			);
		}
		return $out;
	}

	/** Eine Felddefinition aus dem Bestand, oder null. */
	public static function feld( $key ) {
		$alle = self::alle();
		return $alle[ $key ] ?? null;
	}

	/* ===================================================================
	 *  Schlüssel
	 * =================================================================== */

	/** Schlüssel aus einer Beschriftung ableiten: „Essenswunsch" -> `x_essenswunsch`. */
	public static function key_aus_label( $label, $ausser = '' ) {
		$roh = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $label ) : strtolower( (string) $label );
		$roh = strtr( $roh, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		$roh = preg_replace( '/[^a-z0-9]+/', '_', $roh );
		$roh = trim( (string) $roh, '_' );
		if ( '' === $roh ) {
			$roh = 'feld';
		}
		$base = self::PREFIX . substr( $roh, 0, 40 );

		$key = $base;
		$i   = 2;
		while ( self::key_belegt( $key, $ausser ) ) {
			$key = $base . '_' . $i;
			$i++;
		}
		return $key;
	}

	/** Ist der Schlüssel schon vergeben? Geprüft wird gegen Bestand UND Kernfelder. */
	public static function key_belegt( $key, $ausser = '' ) {
		if ( $key === $ausser ) {
			return false;
		}
		return isset( self::kernfelder()[ $key ] ) || isset( self::eigene()[ $key ] );
	}

	/**
	 * Vom Menschen eingegebenen Schlüssel säubern und mit Präfix versehen.
	 *
	 * Umlaute werden umgeschrieben, nicht weggeworfen: Ein getipptes
	 * „Übernachtung" ergäbe sonst `x_bernachtung` – ein Schlüssel, den niemand
	 * so gemeint hat und der für immer bleibt.
	 */
	public static function key_saeubern( $roh ) {
		$roh = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $roh ) ) : strtolower( trim( (string) $roh ) );
		$roh = strtr( $roh, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		if ( 0 === strpos( $roh, self::PREFIX ) ) {
			$roh = substr( $roh, strlen( self::PREFIX ) );
		}
		$roh = preg_replace( '/[^a-z0-9_]+/', '_', $roh );
		$roh = trim( (string) $roh, '_' );
		return '' === $roh ? '' : self::PREFIX . substr( $roh, 0, 40 );
	}

	/* ===================================================================
	 *  Mail-Platzhalter
	 * =================================================================== */

	/** Platzhaltername eines eigenen Feldes: `x_essen` -> `{anmeldung_essen}`. */
	public static function platzhalter_name( $key ) {
		return '{anmeldung_' . substr( (string) $key, strlen( self::PREFIX ) ) . '}';
	}

	/**
	 * Zusätzliche Mail-Platzhalter: Name => Beschreibung.
	 *
	 * @param array $belegt Bereits vergebene Platzhalter – ein eigenes Feld darf
	 *                      einen Kern-Platzhalter nicht überschreiben.
	 */
	public static function platzhalter_labels( $belegt = array() ) {
		$out = array();
		foreach ( self::eigene() as $key => $cfg ) {
			$name = self::platzhalter_name( $key );
			if ( isset( $belegt[ $name ] ) || isset( $out[ $name ] ) ) {
				continue;
			}
			$out[ $name ] = (string) ( $cfg['label'] ?? $key ) . ' (eigenes Anmeldefeld)';
		}
		return $out;
	}

	/**
	 * Werte der zusätzlichen Platzhalter aus den Anmeldedaten.
	 *
	 * Steht ein Feld im Bestand, aber nicht im benutzten Formular, bleibt der
	 * Platzhalter leer statt stehen zu bleiben – dieselbe Mailvorlage taugt so
	 * für alle Formulare.
	 *
	 * @param array $data   Die JSON-Spalte der Anmeldung.
	 * @param array $belegt Bereits vergebene Platzhalter.
	 */
	public static function platzhalter_werte( $data, $belegt = array() ) {
		$data = is_array( $data ) ? $data : array();
		$out  = array();
		foreach ( self::eigene() as $key => $cfg ) {
			$name = self::platzhalter_name( $key );
			if ( isset( $belegt[ $name ] ) || isset( $out[ $name ] ) ) {
				continue;
			}
			$out[ $name ] = self::anzeigewert( $key, (string) ( $data[ $key ] ?? '' ) );
		}
		return $out;
	}

	/**
	 * Gespeicherter Wert, lesbar gemacht: Ein Kästchen speichert „1", in der
	 * Mail und im Export soll aber „ja" stehen; eine Auswahlliste speichert den
	 * Schlüssel, gemeint ist die Beschriftung.
	 */
	public static function anzeigewert( $key, $value ) {
		$f = self::feld( $key );
		if ( ! $f ) {
			return (string) $value;
		}
		if ( 'checkbox' === $f['type'] ) {
			return '1' === (string) $value ? 'ja' : '';
		}
		$opts = (array) ( $f['options'] ?? array() );
		if ( $opts && ! self::ist_liste( $opts ) && isset( $opts[ $value ] ) ) {
			return (string) $opts[ $value ];
		}
		return (string) $value;
	}

	/** Ist die Optionsliste eine einfache Aufzählung (0,1,2 …) statt einer Zuordnung? */
	public static function ist_liste( $options ) {
		$keys = array_keys( (array) $options );
		return $keys === range( 0, count( $keys ) - 1 );
	}

	/* ===================================================================
	 *  Aktionen
	 * =================================================================== */

	private static function guard( $nonce ) {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( $nonce );
	}

	private static function redirect( $msg = '' ) {
		$args = array( 'page' => BI_Formulare::PAGE, 'tab' => 'bestand' );
		if ( $msg ) {
			$args['bi_msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Auswahlliste aus dem Textfeld lesen: je Zeile „Beschriftung" oder „schluessel|Beschriftung". */
	private static function options_lesen( $roh ) {
		$out = array();
		foreach ( preg_split( '/\R/u', (string) $roh ) as $zeile ) {
			$zeile = trim( $zeile );
			if ( '' === $zeile ) {
				continue;
			}
			if ( false !== strpos( $zeile, '|' ) ) {
				list( $k, $l ) = array_map( 'trim', explode( '|', $zeile, 2 ) );
			} else {
				$k = '';
				$l = $zeile;
			}
			$k = sanitize_key( '' !== $k ? $k : $l );
			if ( '' === $k || '' === $l ) {
				continue;
			}
			$out[ $k ] = sanitize_text_field( $l );
		}
		return $out;
	}

	/** Auswahlliste zurück in die Textform (für das Bearbeiten-Formular). */
	private static function options_text( $options ) {
		$zeilen = array();
		foreach ( (array) $options as $k => $l ) {
			$zeilen[] = ( self::ist_liste( $options ) ? '' : $k . '|' ) . $l;
		}
		return implode( "\n", $zeilen );
	}

	/** Felddefinition aus dem abgeschickten Formular bauen. */
	private static function cfg_aus_post( $vorhanden = array() ) {
		$type = sanitize_key( bi_post( 'type', 'text' ) );
		if ( ! isset( self::types()[ $type ] ) ) {
			$type = 'text';
		}
		$max = (int) bi_post( 'max', 200 );
		$cfg = array(
			'label'       => sanitize_text_field( bi_post( 'label' ) ),
			'type'        => $type,
			'placeholder' => sanitize_text_field( bi_post( 'placeholder' ) ),
			'hint'        => sanitize_text_field( bi_post( 'hint' ) ),
			'options'     => array(),
			'default'     => '',
			// Textarea darf länger sein; alles andere bleibt in Spaltenbreite.
			'max'         => max( 1, min( $max > 0 ? $max : 200, 'textarea' === $type ? 5000 : 500 ) ),
		);
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$cfg['options'] = self::options_lesen( wp_unslash( $_POST['options'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$default        = sanitize_key( bi_post( 'default' ) );
			$cfg['default'] = isset( $cfg['options'][ $default ] ) ? $default : '';
		}
		return array_merge( $vorhanden, $cfg );
	}

	public static function handle_add() {
		self::guard( 'bi_anmeldefeld_add' );

		$label = sanitize_text_field( bi_post( 'label' ) );
		if ( '' === trim( $label ) ) {
			self::redirect( 'Ohne Beschriftung kein Feld.' );
		}
		$key = self::key_saeubern( bi_post( 'key' ) );
		if ( '' === $key ) {
			$key = self::key_aus_label( $label );
		}
		if ( self::key_belegt( $key ) ) {
			self::redirect( sprintf( 'Der Schlüssel %s ist schon vergeben.', $key ) );
		}

		$felder         = self::eigene();
		$felder[ $key ] = self::cfg_aus_post();
		update_option( self::OPTION_EIGEN, $felder );

		self::redirect( sprintf( 'Feld „%s" angelegt (%s). Es steht jetzt in jedem Formular zur Auswahl.', $label, $key ) );
	}

	public static function handle_save() {
		self::guard( 'bi_anmeldefeld_save' );

		$key    = sanitize_key( bi_post( 'key' ) );
		$felder = self::eigene();
		if ( ! isset( $felder[ $key ] ) ) {
			self::redirect( 'Feld nicht gefunden.' );
		}
		$felder[ $key ] = self::cfg_aus_post( $felder[ $key ] );
		update_option( self::OPTION_EIGEN, $felder );

		self::redirect( sprintf( 'Feld „%s" gespeichert.', $felder[ $key ]['label'] ) );
	}

	/**
	 * Ein eigenes Feld entfernen.
	 *
	 * Die gespeicherten Werte in den Anmeldungen bleiben stehen – sie liegen in
	 * der JSON-Spalte und gehören zu einer abgeschickten Anmeldung, nicht zum
	 * Formular. Legt jemand denselben Schlüssel wieder an, sind sie zurück.
	 */
	public static function handle_delete() {
		self::guard( 'bi_anmeldefeld_delete' );

		$key    = sanitize_key( bi_post( 'key' ) );
		$felder = self::eigene();
		if ( ! isset( $felder[ $key ] ) ) {
			self::redirect( 'Feld nicht gefunden.' );
		}
		if ( self::ist_kernfeld( $key ) ) {
			self::redirect( 'Kernfelder lassen sich nicht löschen.' );
		}

		$label = (string) ( $felder[ $key ]['label'] ?? $key );
		unset( $felder[ $key ] );
		update_option( self::OPTION_EIGEN, $felder );

		// Aus allen Formularen mit entfernen, sonst verwiese eine Seite auf ein
		// Feld, das es nicht mehr gibt.
		$weg = BI_Formulare::feld_entfernen( $key );

		self::redirect( sprintf(
			'Feld „%s" entfernt%s. Bereits gespeicherte Werte bleiben in den Anmeldungen erhalten.',
			$label,
			$weg ? sprintf( ' – auch aus %d Formular(en)', $weg ) : ''
		) );
	}

	public static function handle_texte() {
		self::guard( 'bi_anmeldefeld_texte' );

		$eingabe = isset( $_POST['t'] ) && is_array( $_POST['t'] ) ? wp_unslash( $_POST['t'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$kern    = self::kernfelder();
		$texte   = array();
		$anzahl  = 0;

		foreach ( $kern as $key => $cfg ) {
			foreach ( array( 'label', 'placeholder', 'hint' ) as $feld ) {
				$neu = isset( $eingabe[ $key ][ $feld ] ) ? sanitize_text_field( $eingabe[ $key ][ $feld ] ) : '';
				// Nur echte Abweichungen speichern – so wirkt eine spätere
				// Änderung des Standardtextes im Code weiterhin durch.
				if ( '' !== $neu && $neu !== (string) ( $cfg[ $feld ] ?? '' ) ) {
					$texte[ $key ][ $feld ] = $neu;
					$anzahl++;
				}
			}
		}
		update_option( self::OPTION_TEXTE, $texte );

		self::redirect( $anzahl
			? sprintf( '%d Text(e) geändert.', $anzahl )
			: 'Alle Texte stehen wieder auf dem Standard.' );
	}

	/* ===================================================================
	 *  Oberfläche (Tab „Feld-Bestand")
	 * =================================================================== */

	public static function render_section() {
		$eigene = self::eigene();
		$kern   = self::kernfelder();
		$texte  = self::texte();
		$nutzung = BI_Formulare::feld_nutzung();
		?>
		<div class="card" style="max-width:100%;margin:0 0 20px">
			<h2 style="margin-top:0">Eigene Anmeldefelder</h2>
			<p>Diese Felder stehen jedem Formular zur Auswahl. Ob sie tatsächlich abgefragt werden – und
			   auf welcher Seite, an welcher Stelle und als Pflichtangabe – entscheidet das
			   <a href="<?php echo esc_url( add_query_arg( array( 'page' => BI_Formulare::PAGE ), admin_url( 'admin.php' ) ) ); ?>">jeweilige Formular</a>.
			   Die Werte landen in der Anmeldung, im CSV-Export und als Platzhalter in den Mailvorlagen.</p>

			<?php if ( $eigene ) : ?>
				<table class="widefat striped" style="margin-bottom:16px">
					<thead><tr>
						<th style="width:24%">Beschriftung</th>
						<th style="width:18%">Schlüssel</th>
						<th style="width:18%">Typ</th>
						<th style="width:20%">Mail-Platzhalter</th>
						<th>Benutzt in</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $eigene as $key => $cfg ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $cfg['label'] ?? $key ); ?></strong></td>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( self::type_label( $cfg['type'] ?? 'text' ) ); ?></td>
							<td><code><?php echo esc_html( self::platzhalter_name( $key ) ); ?></code></td>
							<td><?php
								$in = $nutzung[ $key ] ?? array();
								echo $in
									? esc_html( implode( ', ', $in ) )
									: '<span style="color:#996800">keinem Formular</span>'; // phpcs:ignore – feste Zeichenkette
							?></td>
						</tr>
						<tr>
							<td colspan="5" style="padding-top:0">
								<details>
									<summary style="cursor:pointer">Bearbeiten oder löschen</summary>
									<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:10px">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:2;min-width:380px">
											<input type="hidden" name="action" value="bi_anmeldefeld_save">
											<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
											<?php wp_nonce_field( 'bi_anmeldefeld_save' ); ?>
											<?php self::render_feld_formular( $cfg ); ?>
											<?php submit_button( 'Änderungen speichern', 'primary', '', false ); ?>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:1;min-width:280px"
										      onsubmit="return confirm('Feld „<?php echo esc_attr( $cfg['label'] ?? $key ); ?>“ wirklich entfernen?');">
											<input type="hidden" name="action" value="bi_anmeldefeld_delete">
											<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
											<?php wp_nonce_field( 'bi_anmeldefeld_delete' ); ?>
											<div style="border:1px solid #d63638;border-left-width:4px;background:#fcf0f1;padding:12px 14px">
												<strong style="color:#d63638">Feld entfernen</strong>
												<p style="margin:.5em 0">Es verschwindet aus allen Formularen. Werte, die bereits
												   angemeldete Personen eingetragen haben, bleiben in ihrer Anmeldung stehen.</p>
												<?php submit_button( 'Feld entfernen', 'delete', '', false ); ?>
											</div>
										</form>
									</div>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color:#646970">Noch kein eigenes Anmeldefeld angelegt.</p>
			<?php endif; ?>

			<details <?php echo $eigene ? '' : 'open'; ?> style="border-top:1px solid #dcdcde;padding-top:12px">
				<summary style="cursor:pointer;font-weight:600">Neues Feld anlegen</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;max-width:640px">
					<input type="hidden" name="action" value="bi_anmeldefeld_add">
					<?php wp_nonce_field( 'bi_anmeldefeld_add' ); ?>
					<?php self::render_feld_formular( array() ); ?>
					<?php submit_button( 'Feld anlegen' ); ?>
				</form>
			</details>
		</div>

		<?php // ---------- Kernfelder ---------- ?>
		<div class="card" style="max-width:100%">
			<h2 style="margin-top:0">Kernfelder</h2>
			<p>Diese Felder setzt das Plugin an anderer Stelle voraus – Schlüssel und Typ liegen deshalb
			   fest, und löschen lassen sie sich nicht. <strong>Beschriftung, Platzhaltertext und Hinweis</strong>
			   sind dagegen reine Anzeige und dürfen angepasst werden. Leeres Feld = Standardtext.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_anmeldefeld_texte">
				<?php wp_nonce_field( 'bi_anmeldefeld_texte' ); ?>
				<table class="widefat striped">
					<thead><tr>
						<th style="width:14%">Schlüssel</th>
						<th style="width:22%">Beschriftung</th>
						<th style="width:22%">Platzhaltertext</th>
						<th style="width:28%">Hinweis darunter</th>
						<th>Benutzt in</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $kern as $key => $cfg ) : $spalten = self::spalten(); ?>
						<tr>
							<td><code><?php echo esc_html( $key ); ?></code>
								<?php if ( isset( $spalten[ $key ] ) ) : ?>
									<br><span style="color:#646970;font-size:12px">eigene Spalte</span>
								<?php endif; ?>
							</td>
							<td><input type="text" class="regular-text" style="width:100%"
							           name="t[<?php echo esc_attr( $key ); ?>][label]"
							           value="<?php echo esc_attr( $texte[ $key ]['label'] ?? '' ); ?>"
							           placeholder="<?php echo esc_attr( $cfg['label'] ); ?>"></td>
							<td><?php if ( isset( $cfg['placeholder'] ) ) : ?>
								<input type="text" style="width:100%"
								       name="t[<?php echo esc_attr( $key ); ?>][placeholder]"
								       value="<?php echo esc_attr( $texte[ $key ]['placeholder'] ?? '' ); ?>"
								       placeholder="<?php echo esc_attr( $cfg['placeholder'] ); ?>">
							<?php endif; ?></td>
							<td><input type="text" style="width:100%"
							           name="t[<?php echo esc_attr( $key ); ?>][hint]"
							           value="<?php echo esc_attr( $texte[ $key ]['hint'] ?? '' ); ?>"
							           placeholder="<?php echo esc_attr( $cfg['hint'] ?? '—' ); ?>"></td>
							<td><?php
								$in = $nutzung[ $key ] ?? array();
								echo esc_html( $in ? implode( ', ', $in ) : '—' );
							?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( 'Texte speichern' ); ?>
			</form>
		</div>
		<?php
	}

	/** Eingabemaske für ein eigenes Feld (Anlegen und Bearbeiten teilen sie sich). */
	private static function render_feld_formular( $cfg ) {
		$type = (string) ( $cfg['type'] ?? 'text' );
		?>
		<table class="form-table">
			<tr>
				<th><label>Beschriftung</label></th>
				<td><input type="text" name="label" class="regular-text" required
				           value="<?php echo esc_attr( $cfg['label'] ?? '' ); ?>"></td>
			</tr>
			<?php if ( ! $cfg ) : ?>
			<tr>
				<th><label>Schlüssel</label></th>
				<td><input type="text" name="key" class="regular-text" placeholder="wird aus der Beschriftung gebildet">
					<p class="description">Bleibt für immer – er steht in den Anmeldungen, im Export und im
					   Mail-Platzhalter. Präfix <code><?php echo esc_html( self::PREFIX ); ?></code> wird ergänzt.</p></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><label>Typ</label></th>
				<td><select name="type">
					<?php foreach ( self::types() as $t => $label ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></td>
			</tr>
			<tr>
				<th><label>Auswahlmöglichkeiten</label></th>
				<td><textarea name="options" rows="4" class="large-text" placeholder="Eine je Zeile, z. B.&#10;vegetarisch|Vegetarisch&#10;vegan|Vegan"><?php echo esc_textarea( self::options_text( $cfg['options'] ?? array() ) ); ?></textarea>
					<p class="description">Nur bei Auswahlliste und Auswahl-Schaltflächen. Je Zeile
					   <code>schluessel|Beschriftung</code>; ohne senkrechten Strich wird der Schlüssel aus der
					   Beschriftung gebildet.</p></td>
			</tr>
			<tr>
				<th><label>Vorauswahl</label></th>
				<td><input type="text" name="default" class="regular-text"
				           value="<?php echo esc_attr( $cfg['default'] ?? '' ); ?>"
				           placeholder="Schlüssel einer Auswahlmöglichkeit"></td>
			</tr>
			<tr>
				<th><label>Platzhaltertext</label></th>
				<td><input type="text" name="placeholder" class="regular-text"
				           value="<?php echo esc_attr( $cfg['placeholder'] ?? '' ); ?>"></td>
			</tr>
			<tr>
				<th><label>Hinweis darunter</label></th>
				<td><input type="text" name="hint" class="large-text"
				           value="<?php echo esc_attr( $cfg['hint'] ?? '' ); ?>"></td>
			</tr>
			<tr>
				<th><label>Höchstlänge</label></th>
				<td><input type="number" name="max" min="1" max="5000" step="1" style="width:100px"
				           value="<?php echo esc_attr( (int) ( $cfg['max'] ?? 200 ) ); ?>">
					<p class="description">Zeichen. Gilt im Browser und noch einmal beim Absenden.</p></td>
			</tr>
		</table>
		<?php
	}
}
