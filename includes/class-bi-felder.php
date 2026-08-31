<?php
/**
 * Eigene Datenfelder: anlegen, umbenennen, löschen – und Beschriftungen der
 * Kernfelder ändern.
 *
 * ============================================================================
 *  WARUM ES ZWEI KLASSEN VON FELDERN GIBT
 * ============================================================================
 *  Die in BI_CPT::meta_fields() und BI_Online::meta_fields() fest deklarierten
 *  Felder werden im übrigen Code beim Namen genannt – `_bi_startdatum` allein
 *  über 50-mal: Datumsfilter, Verfügbarkeits-Ampel, „buchbar", Mail-Platzhalter,
 *  PDF-Anhang, Detailseite. Ein Löschen oder ein neuer Schlüssel würde dort
 *  nichts sichtbar kaputtmachen, sondern still ins Leere laufen: Seminare
 *  verschwänden aus dem Datumsfilter, die Ampel bliebe grau, Mails hätten
 *  Lücken. Deshalb:
 *
 *    Kernfelder (Schlüssel ohne Präfix `_bix_`)
 *      → Schlüssel und Typ liegen fest, löschen nicht möglich.
 *      → Die BESCHRIFTUNG darf geändert werden (sie ist reine Anzeige).
 *
 *    Eigene Felder (Schlüssel mit Präfix `_bix_`)
 *      → frei anlegbar, umbenennbar (auch der Schlüssel) und löschbar.
 *
 *  Das Präfix ist die ganze Unterscheidung: Was mit `_bix_` beginnt, gehört den
 *  Redakteur*innen, alles andere dem Code. Damit kann auch eine spätere
 *  Plugin-Version neue Kernfelder einführen, ohne je mit einem selbst angelegten
 *  Feld zu kollidieren.
 *
 * ============================================================================
 *  WO EIN NEUES FELD ÜBERALL AUFTAUCHT
 * ============================================================================
 *  Von allein (weil diese Stellen über meta_fields() laufen):
 *    - Bearbeiten-Maske des Seminars (Metabox) inkl. Speichern
 *    - CSV-Import: als Zielfeld in der Spaltenzuordnung
 *    - Datenpflege: Filter „Feld ist leer" / „Feld enthält", CSV- und JSON-Export
 *
 *  Auf Wunsch je Feld:
 *    - Detailseite (Schalter „auf der Detailseite anzeigen")
 *    - Mail-Platzhalter `{seminar_<schlüssel>}`
 *
 *  Seit 1.107.0 auch: die VOLLTEXTSUCHE im Frontend. Ein neues Textfeld ist
 *  von allein durchsuchbar, ohne dass irgendwo etwas nachgetragen werden muss
 *  (BI_Suche::meta_keys()). Ausgenommen bleiben Ja/Nein, Datum, Uhrzeit, Zahl
 *  und Betrag – deren Werte tragen keine Wörter, sondern nur Rauschen. Ein Feld
 *  vom Typ „Text mit HTML" wird nicht roh verglichen, sondern wandert entkleidet
 *  in den Suchtext (BI_Suche::html_felder()).
 *
 *  Bewusst NICHT: ein eigener Filter-CHIP in der Leiste. Filterbare Facetten
 *  sind Taxonomien, keine Meta-Felder – ein Chip braucht Begriffe, keine
 *  Freitextwerte.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Felder {

	/** Eigene Felder: Schlüssel => Definition */
	const OPTION_FELDER = 'bi_felder';

	/** Geänderte Beschriftungen der Kernfelder: Schlüssel => Beschriftung */
	const OPTION_LABELS = 'bi_feld_labels';

	/** Präfix, das ein eigenes Feld von einem Kernfeld unterscheidet */
	const PREFIX = '_bix_';

	public static function init() {
		add_action( 'admin_post_bi_feld_add',    array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_bi_feld_save',   array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bi_feld_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_bi_feld_move',   array( __CLASS__, 'handle_move' ) );
		add_action( 'admin_post_bi_feld_labels', array( __CLASS__, 'handle_labels' ) );
	}

	/* ===================================================================
	 *  Registry
	 * =================================================================== */

	/** Feldtypen, die angelegt werden dürfen: Typ => Beschriftung. */
	public static function types() {
		return array(
			'text'     => 'Einzeilger Text',
			'textarea' => 'Mehrzeiliger Text',
			'html'     => 'Text mit HTML (z. B. Aufzählung)',
			'number'   => 'Zahl',
			'money'    => 'Betrag in Euro',
			'date'     => 'Datum',
			'time'     => 'Uhrzeit',
			'email'    => 'E-Mail-Adresse',
			'url'      => 'Internetadresse',
			'select'   => 'Auswahlliste',
			'bool'     => 'Ja/Nein',
		);
	}

	/** Ist das ein Kernfeld (also vom Code vorausgesetzt)? */
	public static function ist_kernfeld( $key ) {
		return 0 !== strpos( (string) $key, self::PREFIX );
	}

	/** Alle eigenen Felder in gespeicherter Reihenfolge: Schlüssel => Definition. */
	public static function alle() {
		$felder = get_option( self::OPTION_FELDER, array() );
		return is_array( $felder ) ? $felder : array();
	}

	/** Geänderte Beschriftungen der Kernfelder. */
	public static function label_overrides() {
		$labels = get_option( self::OPTION_LABELS, array() );
		return is_array( $labels ) ? $labels : array();
	}

	/**
	 * Eigene Felder eines Beitragstyps in der Form, die meta_fields() liefert.
	 *
	 * @param string $post_type BI_CPT oder BI_ONLINE.
	 */
	public static function custom( $post_type ) {
		$out = array();
		foreach ( self::alle() as $key => $cfg ) {
			$pt = $cfg['pt'] ?? 'beide';
			if ( 'beide' !== $pt && $pt !== $post_type ) {
				continue;
			}
			$out[ $key ] = array(
				'label'   => (string) ( $cfg['label'] ?? $key ),
				'type'    => (string) ( $cfg['type'] ?? 'text' ),
				'hint'    => (string) ( $cfg['hint'] ?? '' ),
				'options' => (array) ( $cfg['options'] ?? array() ),
				'default' => $cfg['default'] ?? '',
				'gruppe'  => (string) ( $cfg['gruppe'] ?? 'weitere' ),
				'eigen'   => true, // Kennzeichen für die Oberfläche
			);
		}
		return $out;
	}

	/**
	 * Kernfelder um geänderte Beschriftungen ergänzen und die eigenen Felder
	 * anhängen. Genau hier hängt sich die Feldverwaltung in BI_CPT::meta_fields()
	 * ein – alles, was von dort liest, bekommt neue Felder automatisch.
	 */
	public static function erweitern( $fields, $post_type ) {
		$labels = self::label_overrides();
		foreach ( $fields as $key => $cfg ) {
			if ( isset( $labels[ $key ] ) && '' !== $labels[ $key ] ) {
				$fields[ $key ]['label'] = (string) $labels[ $key ];
			}
		}
		return array_merge( $fields, self::custom( $post_type ) );
	}

	/* ===================================================================
	 *  Schlüssel
	 * =================================================================== */

	/**
	 * Schlüssel aus einer Beschriftung ableiten: „Raum­kategorie" -> `_bix_raumkategorie`.
	 * Bei Kollision wird durchnummeriert.
	 */
	public static function key_aus_label( $label, $ausser = '' ) {
		$roh = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $label ) : strtolower( (string) $label );
		$roh = strtr( $roh, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		$roh = preg_replace( '/[^a-z0-9]+/', '_', $roh );
		$roh = trim( (string) $roh, '_' );
		if ( '' === $roh ) {
			$roh = 'feld';
		}
		$roh  = substr( $roh, 0, 40 );
		$base = self::PREFIX . $roh;

		$key = $base;
		$i   = 2;
		while ( self::key_belegt( $key, $ausser ) ) {
			$key = $base . '_' . $i;
			$i++;
		}
		return $key;
	}

	/**
	 * Ist der Schlüssel schon vergeben? Geprüft wird gegen die eigenen Felder UND
	 * gegen beide Kern-Feldsets – ein eigenes Feld darf niemals ein Kernfeld
	 * überdecken, sonst schriebe die Bearbeiten-Maske in dessen Wert.
	 */
	public static function key_belegt( $key, $ausser = '' ) {
		if ( $key === $ausser ) {
			return false;
		}
		if ( isset( self::alle()[ $key ] ) ) {
			return true;
		}
		foreach ( bi_seminar_post_types() as $pt ) {
			$kern = ( BI_ONLINE === $pt ) ? BI_Online::meta_fields() : BI_CPT::kernfelder();
			if ( isset( $kern[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Vom Menschen eingegebenen Schlüssel säubern und mit Präfix versehen. */
	public static function key_saeubern( $roh ) {
		$roh = strtolower( trim( (string) $roh ) );
		if ( 0 === strpos( $roh, self::PREFIX ) ) {
			$roh = substr( $roh, strlen( self::PREFIX ) );
		}
		$roh = preg_replace( '/[^a-z0-9_]+/', '_', $roh );
		$roh = trim( (string) $roh, '_' );
		return '' === $roh ? '' : self::PREFIX . substr( $roh, 0, 40 );
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
		$args = array( 'page' => BI_Datenpflege::PAGE, 'tab' => 'felder' );
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
			$zeilen[] = $k . '|' . $l;
		}
		return implode( "\n", $zeilen );
	}

	/** Felddefinition aus dem abgeschickten Formular bauen. */
	private static function cfg_aus_post( $vorhanden = array() ) {
		$type = sanitize_key( bi_post( 'type', 'text' ) );
		if ( ! isset( self::types()[ $type ] ) ) {
			$type = 'text';
		}
		$pt = sanitize_key( bi_post( 'pt', 'beide' ) );
		if ( ! in_array( $pt, array( BI_CPT, BI_ONLINE, 'beide' ), true ) ) {
			$pt = 'beide';
		}

		$gruppe = sanitize_key( bi_post( 'gruppe', 'weitere' ) );
		if ( ! isset( BI_CPT::feld_gruppen( BI_CPT )[ $gruppe ] ) ) {
			$gruppe = 'weitere';
		}

		$cfg = array(
			'label'   => sanitize_text_field( bi_post( 'label' ) ),
			'type'    => $type,
			'hint'    => sanitize_text_field( bi_post( 'hint' ) ),
			'pt'      => $pt,
			'gruppe'  => $gruppe,
			'detail'  => ! empty( $_POST['detail'] ),
			'mail'    => ! empty( $_POST['mail'] ),
			'options' => array(),
			'default' => '',
		);
		if ( 'select' === $type ) {
			$cfg['options'] = self::options_lesen( wp_unslash( $_POST['options'] ?? '' ) );
			$default        = sanitize_key( bi_post( 'default' ) );
			$cfg['default'] = isset( $cfg['options'][ $default ] ) ? $default : '';
		} elseif ( 'bool' === $type ) {
			$cfg['default'] = ! empty( $_POST['default_bool'] );
		}
		// Nicht übergebene Angaben eines bestehenden Feldes behalten.
		return array_merge( $vorhanden, $cfg );
	}

	public static function handle_add() {
		self::guard( 'bi_feld_add' );

		$label = sanitize_text_field( bi_post( 'label' ) );
		if ( '' === trim( $label ) ) {
			self::redirect( 'Das Feld braucht eine Beschriftung.' );
		}

		$wunsch = self::key_saeubern( bi_post( 'key' ) );
		$key    = $wunsch ? $wunsch : self::key_aus_label( $label );
		if ( self::key_belegt( $key ) ) {
			self::redirect( sprintf( 'Der Schlüssel %s ist bereits vergeben.', $key ) );
		}

		$felder         = self::alle();
		$felder[ $key ] = self::cfg_aus_post();
		update_option( self::OPTION_FELDER, $felder );

		self::redirect( sprintf( 'Feld „%s" angelegt (Schlüssel %s).', $label, $key ) );
	}

	public static function handle_save() {
		self::guard( 'bi_feld_save' );

		$key    = sanitize_key( bi_post( 'key' ) );
		$felder = self::alle();
		if ( ! isset( $felder[ $key ] ) ) {
			self::redirect( 'Feld nicht gefunden.' );
		}

		$label = sanitize_text_field( bi_post( 'label' ) );
		if ( '' === trim( $label ) ) {
			self::redirect( 'Das Feld braucht eine Beschriftung.' );
		}

		$neu_cfg = self::cfg_aus_post( $felder[ $key ] );

		// Schlüsselwechsel: die gespeicherten Werte müssen mitwandern, sonst
		// stünde das Feld anschließend leer da, während die alten Werte als
		// verwaiste Zeilen in wp_postmeta zurückblieben.
		$neuer_key = self::key_saeubern( bi_post( 'neuer_key' ) );
		$verschoben = -1;
		if ( $neuer_key && $neuer_key !== $key ) {
			if ( self::key_belegt( $neuer_key, $key ) ) {
				self::redirect( sprintf( 'Der Schlüssel %s ist bereits vergeben.', $neuer_key ) );
			}
			$verschoben = self::meta_umbenennen( $key, $neuer_key );

			// Reihenfolge erhalten: neu aufbauen statt anhängen.
			$neue_liste = array();
			foreach ( $felder as $k => $cfg ) {
				if ( $k === $key ) {
					$neue_liste[ $neuer_key ] = $neu_cfg;
				} else {
					$neue_liste[ $k ] = $cfg;
				}
			}
			$felder = $neue_liste;
			$key    = $neuer_key;
		} else {
			$felder[ $key ] = $neu_cfg;
		}

		update_option( self::OPTION_FELDER, $felder );

		$msg = sprintf( 'Feld „%s" gespeichert.', $label );
		if ( $verschoben >= 0 ) {
			$msg .= sprintf( ' Neuer Schlüssel %s – %s Wert(e) übernommen.', $key, number_format_i18n( $verschoben ) );
		}
		self::redirect( $msg );
	}

	public static function handle_delete() {
		self::guard( 'bi_feld_delete' );

		$key    = sanitize_key( bi_post( 'key' ) );
		$felder = self::alle();
		if ( ! isset( $felder[ $key ] ) ) {
			self::redirect( 'Feld nicht gefunden.' );
		}
		// Doppelter Boden: die Oberfläche bietet Kernfelder gar nicht zum Löschen
		// an, aber ein abgeschicktes Formular könnte alles behaupten.
		if ( self::ist_kernfeld( $key ) ) {
			self::redirect( 'Kernfelder können nicht gelöscht werden.' );
		}

		$label = (string) ( $felder[ $key ]['label'] ?? $key );
		$daten = ! empty( $_POST['mit_daten'] );

		unset( $felder[ $key ] );
		update_option( self::OPTION_FELDER, $felder );

		$msg = sprintf( 'Feld „%s" entfernt.', $label );
		if ( $daten ) {
			$geloescht = self::meta_loeschen( $key );
			$msg      .= sprintf( ' %s gespeicherte Wert(e) gelöscht.', number_format_i18n( $geloescht ) );
		} else {
			$anzahl = self::meta_anzahl( $key );
			$msg   .= $anzahl
				? sprintf( ' %s gespeicherte Wert(e) bleiben erhalten – legst du den Schlüssel %s wieder an, sind sie zurück.', number_format_i18n( $anzahl ), $key )
				: '';
		}
		self::redirect( $msg );
	}

	public static function handle_move() {
		self::guard( 'bi_feld_move' );

		$key      = sanitize_key( bi_post( 'key' ) );
		$richtung = 'up' === bi_post( 'richtung' ) ? -1 : 1;
		$felder   = self::alle();
		$keys     = array_keys( $felder );
		$pos      = array_search( $key, $keys, true );
		if ( false === $pos ) {
			self::redirect( 'Feld nicht gefunden.' );
		}
		$ziel = $pos + $richtung;
		if ( $ziel < 0 || $ziel >= count( $keys ) ) {
			self::redirect();
		}
		// Tauschen und die Definitionen in der neuen Reihenfolge neu aufbauen.
		list( $keys[ $pos ], $keys[ $ziel ] ) = array( $keys[ $ziel ], $keys[ $pos ] );
		$neu = array();
		foreach ( $keys as $k ) {
			$neu[ $k ] = $felder[ $k ];
		}
		update_option( self::OPTION_FELDER, $neu );
		self::redirect();
	}

	public static function handle_labels() {
		self::guard( 'bi_feld_labels' );

		$eingabe = isset( $_POST['label'] ) && is_array( $_POST['label'] ) ? wp_unslash( $_POST['label'] ) : array();
		$labels  = array();
		$geaendert = 0;

		foreach ( bi_seminar_post_types() as $pt ) {
			$kern = ( BI_ONLINE === $pt ) ? BI_Online::meta_fields() : BI_CPT::kernfelder();
			foreach ( $kern as $key => $cfg ) {
				if ( ! isset( $eingabe[ $key ] ) ) {
					continue;
				}
				$neu = sanitize_text_field( $eingabe[ $key ] );
				// Nur echte Abweichungen speichern – dann wirkt eine spätere
				// Änderung der Standard-Beschriftung im Code weiterhin durch.
				if ( '' !== $neu && $neu !== $cfg['label'] ) {
					$labels[ $key ] = $neu;
					$geaendert++;
				}
			}
		}
		update_option( self::OPTION_LABELS, $labels );

		self::redirect( $geaendert
			? sprintf( '%d Beschriftung(en) geändert.', $geaendert )
			: 'Alle Beschriftungen stehen wieder auf dem Standard.' );
	}

	/* ===================================================================
	 *  Datenbank
	 * =================================================================== */

	/** Wie viele gespeicherte Werte hat dieses Feld? */
	public static function meta_anzahl( $key ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = %s",
			$key
		) );
	}

	/** Schlüssel aller gespeicherten Werte umbenennen. Gibt die Zeilenzahl zurück. */
	public static function meta_umbenennen( $alt, $neu ) {
		global $wpdb;
		$rows = $wpdb->update( $wpdb->postmeta, array( 'meta_key' => $neu ), array( 'meta_key' => $alt ) );
		wp_cache_flush(); // Meta-Cache kennt sonst weiter den alten Schlüssel
		return (int) $rows;
	}

	/** Alle gespeicherten Werte eines Feldes löschen. Gibt die Zeilenzahl zurück. */
	public static function meta_loeschen( $key ) {
		global $wpdb;
		$rows = $wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $key ) );
		wp_cache_flush();
		return (int) $rows;
	}

	/* ===================================================================
	 *  Detailseite und Mail-Platzhalter
	 * =================================================================== */

	/** Wert eines eigenen Feldes lesbar aufbereiten (Datum, Ja/Nein, Auswahl). */
	public static function anzeigewert( $post_id, $key, $cfg ) {
		$val = (string) get_post_meta( $post_id, $key, true );
		switch ( $cfg['type'] ?? 'text' ) {
			case 'bool':
				if ( '' === $val ) {
					return ! empty( $cfg['default'] ) ? 'ja' : '';
				}
				return '1' === $val ? 'ja' : 'nein';
			case 'date':
				return $val ? date_i18n( 'd.m.Y', strtotime( $val ) ) : '';
			case 'time':
				return $val ? $val . ' Uhr' : '';
			case 'money':
				return BI_CPT::money_format( $val );
			case 'select':
				return (string) ( $cfg['options'][ $val ] ?? '' );
			default:
				return $val;
		}
	}

	/**
	 * Zeilen der eigenen Felder für die Sidebar der Detailseite.
	 * Nur Felder mit gesetztem Schalter „auf der Detailseite anzeigen".
	 *
	 * @param callable $row Zeilen-Renderer aus BI_Detail (Beschriftung, Wert).
	 */
	public static function detail_rows( $post_id, $row ) {
		$out = '';
		foreach ( self::custom( get_post_type( $post_id ) ) as $key => $cfg ) {
			$def = self::alle()[ $key ] ?? array();
			if ( empty( $def['detail'] ) ) {
				continue;
			}
			$wert = self::anzeigewert( $post_id, $key, $cfg );
			if ( '' === trim( $wert ) ) {
				continue;
			}
			$out .= $row( $cfg['label'], 'url' === $cfg['type']
				? '<a href="' . esc_url( $wert ) . '" rel="noopener">' . esc_html( $wert ) . '</a>'
				: esc_html( $wert ) );
		}
		return $out;
	}

	/** Platzhaltername eines eigenen Feldes: `_bix_raum` -> `{seminar_raum}` */
	public static function platzhalter_name( $key ) {
		return '{seminar_' . substr( $key, strlen( self::PREFIX ) ) . '}';
	}

	/**
	 * Zusätzliche Mail-Platzhalter: Name => Beschreibung.
	 *
	 * @param array $belegt Bereits vergebene Platzhalter – ein eigenes Feld darf
	 *                      einen Kern-Platzhalter nicht überschreiben.
	 */
	public static function platzhalter_labels( $belegt = array() ) {
		$out = array();
		foreach ( self::alle() as $key => $cfg ) {
			if ( empty( $cfg['mail'] ) ) {
				continue;
			}
			$name = self::platzhalter_name( $key );
			if ( isset( $belegt[ $name ] ) || isset( $out[ $name ] ) ) {
				continue;
			}
			$out[ $name ] = (string) ( $cfg['label'] ?? $key ) . ' (eigenes Feld)';
		}
		return $out;
	}

	/** Werte der zusätzlichen Platzhalter für ein Seminar. */
	public static function platzhalter_werte( $seminar_id, $belegt = array() ) {
		$out    = array();
		$fields = self::custom( get_post_type( $seminar_id ) ?: BI_CPT );
		foreach ( self::alle() as $key => $cfg ) {
			if ( empty( $cfg['mail'] ) ) {
				continue;
			}
			$name = self::platzhalter_name( $key );
			if ( isset( $belegt[ $name ] ) || isset( $out[ $name ] ) ) {
				continue;
			}
			// Feld gilt für diese Seminarform nicht -> leerer Platzhalter, damit
			// dieselbe Vorlage für beide Formen taugt.
			$out[ $name ] = isset( $fields[ $key ] ) ? self::anzeigewert( $seminar_id, $key, $fields[ $key ] ) : '';
		}
		return $out;
	}

	/* ===================================================================
	 *  Oberfläche (Tab „Felder" der Datenpflege)
	 * =================================================================== */

	public static function render_section() {
		$felder = self::alle();
		$typen  = self::types();
		$labels = self::label_overrides();
		?>
		<div class="card" style="max-width:100%;margin:0 0 20px">
			<h2 style="margin-top:0">Eigene Felder</h2>
			<p>Eigene Felder erscheinen sofort in der Bearbeiten-Maske des Seminars, im CSV-Import
			   (als Zielspalte) sowie im Export und in den Filtern dieser Seite. Auf der Detailseite
			   und in Mails stehen sie nur, wenn der jeweilige Schalter gesetzt ist.</p>

			<?php if ( $felder ) : ?>
				<table class="widefat striped" style="margin-bottom:16px">
					<thead><tr>
						<th style="width:22%">Beschriftung</th>
						<th style="width:22%">Schlüssel</th>
						<th style="width:14%">Typ</th>
						<th style="width:12%">Gilt für</th>
						<th style="width:10%">Werte</th>
						<th>Aktion</th>
					</tr></thead>
					<tbody>
					<?php $i = 0; $anzahl = count( $felder ); ?>
					<?php foreach ( $felder as $key => $cfg ) : $i++; ?>
						<tr>
							<td><strong><?php echo esc_html( $cfg['label'] ?? $key ); ?></strong>
								<?php if ( ! empty( $cfg['detail'] ) ) : ?><br><span style="color:#646970;font-size:12px">Detailseite</span><?php endif; ?>
								<?php if ( ! empty( $cfg['mail'] ) ) : ?><br><span style="color:#646970;font-size:12px">Mail: <code><?php echo esc_html( self::platzhalter_name( $key ) ); ?></code></span><?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td><?php echo esc_html( $typen[ $cfg['type'] ?? 'text' ] ?? $cfg['type'] ); ?></td>
							<td><?php
								$pt = $cfg['pt'] ?? 'beide';
								echo esc_html( 'beide' === $pt ? 'beide Formen' : ( BI_ONLINE === $pt ? 'Online' : 'Präsenz' ) );
							?></td>
							<td><?php echo esc_html( number_format_i18n( self::meta_anzahl( $key ) ) ); ?></td>
							<td>
								<?php // Reihenfolge ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="bi_feld_move">
									<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
									<input type="hidden" name="richtung" value="up">
									<?php wp_nonce_field( 'bi_feld_move' ); ?>
									<button class="button button-small" <?php disabled( 1, $i ); ?> aria-label="nach oben">↑</button>
								</form>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<input type="hidden" name="action" value="bi_feld_move">
									<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
									<input type="hidden" name="richtung" value="down">
									<?php wp_nonce_field( 'bi_feld_move' ); ?>
									<button class="button button-small" <?php disabled( $anzahl, $i ); ?> aria-label="nach unten">↓</button>
								</form>
							</td>
						</tr>
						<tr>
							<td colspan="6" style="padding-top:0">
								<details>
									<summary style="cursor:pointer">Bearbeiten, umbenennen oder löschen</summary>
									<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:10px">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:2;min-width:380px">
											<input type="hidden" name="action" value="bi_feld_save">
											<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
											<?php wp_nonce_field( 'bi_feld_save' ); ?>
											<?php self::render_feld_formular( $cfg, $key ); ?>
											<?php submit_button( 'Änderungen speichern', 'primary', '', false ); ?>
										</form>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:1;min-width:280px"
										      onsubmit="return confirm('Feld „<?php echo esc_attr( $cfg['label'] ?? $key ); ?>“ wirklich entfernen?');">
											<input type="hidden" name="action" value="bi_feld_delete">
											<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
											<?php wp_nonce_field( 'bi_feld_delete' ); ?>
											<div style="border:1px solid #d63638;border-left-width:4px;background:#fcf0f1;padding:12px 14px">
												<strong style="color:#d63638">Feld entfernen</strong>
												<p style="margin:.5em 0">Ohne Haken bleiben die
													<?php echo esc_html( number_format_i18n( self::meta_anzahl( $key ) ) ); ?>
													gespeicherten Werte in der Datenbank stehen – das Feld lässt sich mit
													demselben Schlüssel jederzeit wieder anlegen.</p>
												<p style="margin:.5em 0"><label><input type="checkbox" name="mit_daten" value="1">
													auch die gespeicherten Werte löschen (unwiderruflich)</label></p>
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
				<p style="color:#646970">Noch kein eigenes Feld angelegt.</p>
			<?php endif; ?>

			<details <?php echo $felder ? '' : 'open'; ?> style="border-top:1px solid #dcdcde;padding-top:12px">
				<summary style="cursor:pointer;font-weight:600">Neues Feld anlegen</summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;max-width:640px">
					<input type="hidden" name="action" value="bi_feld_add">
					<?php wp_nonce_field( 'bi_feld_add' ); ?>
					<?php self::render_feld_formular( array(), '' ); ?>
					<?php submit_button( 'Feld anlegen' ); ?>
				</form>
			</details>
		</div>

		<?php // ---------- Beschriftungen der Kernfelder ---------- ?>
		<div class="card" style="max-width:100%">
			<h2 style="margin-top:0">Beschriftungen der Kernfelder</h2>
			<p>Diese Felder setzt das Plugin an vielen Stellen voraus – ihr Schlüssel und ihr Typ liegen
			   deshalb fest, und löschen lassen sie sich nicht. Die <strong>Beschriftung</strong> ist
			   dagegen reine Anzeige und darf angepasst werden. Leeres Feld = Standard.</p>
			<div style="background:#fcf9e8;border-left:4px solid #dba617;padding:10px 14px;margin-bottom:14px">
				<strong>Wirkt auch auf den CSV-Import:</strong> Die automatische Spaltenzuordnung vergleicht die
				Kopfzeile mit diesen Beschriftungen. Wer hier umbenennt, sollte die nächste Import-Datei
				kurz prüfen – die Zuordnung lässt sich dort ohnehin von Hand korrigieren.
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_feld_labels">
				<?php wp_nonce_field( 'bi_feld_labels' ); ?>
				<?php
				$gesehen = array();
				foreach ( bi_seminar_post_types() as $pt ) :
					$kern = ( BI_ONLINE === $pt ) ? BI_Online::meta_fields() : BI_CPT::kernfelder();
					$offen = array();
					foreach ( $kern as $key => $cfg ) {
						if ( isset( $gesehen[ $key ] ) ) {
							continue; // geteilte Felder nur einmal zeigen
						}
						$gesehen[ $key ] = true;
						$offen[ $key ]   = $cfg;
					}
					if ( ! $offen ) {
						continue;
					}
					?>
					<h3><?php echo esc_html( BI_ONLINE === $pt ? 'Nur bei Online-Seminaren' : 'Präsenz-Seminare (und geteilte Felder)' ); ?></h3>
					<table class="widefat striped" style="margin-bottom:14px">
						<thead><tr><th style="width:30%">Standard</th><th style="width:30%">Schlüssel</th><th>Eigene Beschriftung</th></tr></thead>
						<tbody>
						<?php foreach ( $offen as $key => $cfg ) : ?>
							<tr>
								<td><?php echo esc_html( $cfg['label'] ); ?></td>
								<td><code><?php echo esc_html( $key ); ?></code></td>
								<td><input type="text" name="label[<?php echo esc_attr( $key ); ?>]" class="regular-text"
								           value="<?php echo esc_attr( ( isset( $labels[ $key ] ) && $labels[ $key ] !== $cfg['label'] ) ? $labels[ $key ] : '' ); ?>"
								           placeholder="<?php echo esc_attr( $cfg['label'] ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
				<?php submit_button( 'Beschriftungen speichern' ); ?>
			</form>
		</div>
		<?php
	}

	/** Formularfelder einer Felddefinition – geteilt von „anlegen" und „bearbeiten". */
	private static function render_feld_formular( $cfg, $key ) {
		$id   = $key ? esc_attr( $key ) : 'neu';
		$type = $cfg['type'] ?? 'text';
		$pt   = $cfg['pt'] ?? 'beide';
		?>
		<table class="form-table">
			<tr>
				<th><label for="label_<?php echo $id; ?>">Beschriftung</label></th>
				<td><input type="text" name="label" id="label_<?php echo $id; ?>" class="regular-text" required
				           value="<?php echo esc_attr( $cfg['label'] ?? '' ); ?>"></td>
			</tr>
			<tr>
				<th><label for="type_<?php echo $id; ?>">Typ</label></th>
				<td>
					<select name="type" id="type_<?php echo $id; ?>">
						<?php foreach ( self::types() as $t => $t_label ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $key ) : ?>
						<p class="description">Ein Typwechsel ändert nur die Eingabemaske – bereits gespeicherte
						   Werte bleiben unverändert stehen und passen danach eventuell nicht mehr.</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="options_<?php echo $id; ?>">Auswahlliste</label></th>
				<td>
					<textarea name="options" id="options_<?php echo $id; ?>" rows="4" class="large-text" placeholder="ez|Einzelzimmer&#10;dz|Doppelzimmer"><?php echo esc_textarea( self::options_text( $cfg['options'] ?? array() ) ); ?></textarea>
					<p class="description">Nur beim Typ „Auswahlliste": je Zeile ein Eintrag, wahlweise
					   <code>schluessel|Beschriftung</code>. Der Schlüssel steht später in CSV und Paket.</p>
				</td>
			</tr>
			<tr>
				<th>Vorgabe bei Ja/Nein</th>
				<td><label><input type="checkbox" name="default_bool" value="1" <?php checked( ! empty( $cfg['default'] ) && 'bool' === $type ); ?>>
					leeres Feld gilt als „ja"</label>
					<p class="description">Nur beim Typ „Ja/Nein" – bestimmt, was eine leere CSV-Zelle bedeutet.</p></td>
			</tr>
			<tr>
				<th><label for="gruppe_<?php echo $id; ?>">Abschnitt</label></th>
				<td>
					<select name="gruppe" id="gruppe_<?php echo $id; ?>">
						<?php foreach ( BI_CPT::feld_gruppen( BI_CPT ) as $g => $g_label ) : ?>
							<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $cfg['gruppe'] ?? 'weitere', $g ); ?>><?php echo esc_html( $g_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Wo das Feld in der Bearbeiten-Maske des Seminars erscheint.</p>
				</td>
			</tr>
			<tr>
				<th>Gilt für</th>
				<td>
					<label><input type="radio" name="pt" value="beide" <?php checked( $pt, 'beide' ); ?>> beide Seminarformen</label><br>
					<label><input type="radio" name="pt" value="<?php echo esc_attr( BI_CPT ); ?>" <?php checked( $pt, BI_CPT ); ?>> nur Präsenz-Seminare</label><br>
					<label><input type="radio" name="pt" value="<?php echo esc_attr( BI_ONLINE ); ?>" <?php checked( $pt, BI_ONLINE ); ?>> nur Online-Seminare</label>
				</td>
			</tr>
			<tr>
				<th>Anzeigen</th>
				<td>
					<label><input type="checkbox" name="detail" value="1" <?php checked( ! empty( $cfg['detail'] ) ); ?>>
						auf der Detailseite in der Seitenleiste zeigen</label><br>
					<label><input type="checkbox" name="mail" value="1" <?php checked( ! empty( $cfg['mail'] ) ); ?>>
						als Mail-Platzhalter bereitstellen<?php if ( $key ) : ?>
							(<code><?php echo esc_html( self::platzhalter_name( $key ) ); ?></code>)<?php endif; ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="hint_<?php echo $id; ?>">Hinweis</label></th>
				<td><input type="text" name="hint" id="hint_<?php echo $id; ?>" class="large-text"
				           value="<?php echo esc_attr( $cfg['hint'] ?? '' ); ?>">
					<p class="description">Erklärung unter dem Feld in der Bearbeiten-Maske.</p></td>
			</tr>
			<?php if ( $key ) : ?>
				<tr>
					<th><label for="neuer_key_<?php echo $id; ?>">Schlüssel</label></th>
					<td>
						<input type="text" name="neuer_key" id="neuer_key_<?php echo $id; ?>" class="regular-text"
						       value="<?php echo esc_attr( $key ); ?>">
						<p class="description">Beim Ändern wandern die
						   <?php echo esc_html( number_format_i18n( self::meta_anzahl( $key ) ) ); ?>
						   gespeicherten Werte mit. Ein Mail-Platzhalter in bestehenden Vorlagen heißt danach
						   anders und muss dort nachgezogen werden.</p>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th><label for="key_neu">Schlüssel</label></th>
					<td><input type="text" name="key" id="key_neu" class="regular-text" placeholder="wird aus der Beschriftung gebildet">
						<p class="description">Optional. Das Präfix <code><?php echo esc_html( self::PREFIX ); ?></code>
						   wird automatisch gesetzt.</p></td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}
}
