<?php
/**
 * Anmeldeformulare: mehrere Formulare aus einem Feld-Bestand.
 *
 * ============================================================================
 *  AUFBAU
 * ============================================================================
 *  BI_Anmeldefelder  = WELCHE Felder es gibt (Bestand, Schlüssel, Typ, Texte)
 *  BI_Formulare      = WELCHES Formular welche davon abfragt, auf welcher
 *                      Seite, in welcher Reihenfolge, wie breit und ob als
 *                      Pflichtangabe.
 *
 *  Ein Formular ist damit reine Auswahl. Es erfindet keine Felder – täte es
 *  das, hätten zwei Formulare bald zwei Schreibweisen für dieselbe Angabe, und
 *  der CSV-Export hätte zwei Spalten für eine Frage.
 *
 * ============================================================================
 *  WELCHES FORMULAR GILT FÜR WELCHES SEMINAR?
 * ============================================================================
 *  Drei Stufen, die erste, die etwas sagt, gewinnt:
 *
 *    1. Feld „Anmeldeformular" am Seminar   (die Ausnahme von Hand)
 *    2. Regelliste, erste zutreffende Regel (der Normalfall, wächst mit dem
 *       Import mit – niemand muss 500 Seminare anfassen)
 *    3. Standardformular
 *
 *  Die Regeln benutzen dieselbe Vergleichslogik wie die Anmeldevarianten
 *  (BI_Settings::rule_matches) – ein Feld des Seminars, ein Teiltext. Damit
 *  gilt hier, was dort schon gilt: Die Ausnahme gehört nach oben.
 *
 * ============================================================================
 *  WAS EIN FORMULAR NICHT DARF
 * ============================================================================
 *  Vorname, Nachname und E-Mail bleiben in jedem Formular. Ohne sie gäbe es
 *  niemanden, an den die Bestätigung ginge, und in der Anmeldeliste stünde eine
 *  Zeile ohne Person.
 *
 *  Die betriebliche PLZ ist nicht erzwungen, aber gewarnt: An ihr hängt
 *  BI_PLZ::lookup() und damit die Benachrichtigung der Geschäftsstelle. Ein
 *  Formular ohne sie erzeugt Anmeldungen, die keine Geschäftsstelle je sieht –
 *  das kann gewollt sein (reines Online-Seminar), still passieren darf es nicht.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Formulare {

	/** Menü-Slug der eigenen Verwaltungsseite */
	const PAGE = 'bi-formulare';

	/** Formulare: Schlüssel => [name, seiten] */
	const OPTION = 'bi_formulare';

	/** Schlüssel des Standardformulars */
	const OPTION_STANDARD = 'bi_formular_standard';

	/** Zuordnungsregeln: [ [field, value, formular], … ] */
	const OPTION_REGELN = 'bi_formular_regeln';

	/** Meta-Schlüssel der Ausnahme am Seminar */
	const META = '_bi_formular';

	/** Erlaubte Spaltenbreiten: Wert => Beschriftung */
	public static function breiten() {
		return array(
			12 => 'ganze Zeile',
			8  => 'zwei Drittel',
			6  => 'halbe Zeile',
			4  => 'ein Drittel',
		);
	}

	/** Farbkategorien der Seiten (die Gestaltung kennt genau diese drei) */
	public static function kategorien() {
		return array(
			'privat'     => 'Private Angaben (blau)',
			'dienstlich' => 'Dienstliche Angaben (bernstein)',
			'neutral'    => 'Abschluss (neutral)',
		);
	}

	public static function init() {
		add_action( 'admin_post_bi_formular_neu',       array( __CLASS__, 'handle_neu' ) );
		add_action( 'admin_post_bi_formular_save',      array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_bi_formular_delete',    array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_bi_formular_standard',  array( __CLASS__, 'handle_standard' ) );
		add_action( 'admin_post_bi_formular_regeln',    array( __CLASS__, 'handle_regeln' ) );
	}

	/* ===================================================================
	 *  Bestand der Formulare
	 * =================================================================== */

	/**
	 * Das mitgelieferte Standardformular – Wort für Wort das, was vor der
	 * Formularverwaltung fest im Code stand. Eine frische Installation sieht
	 * deshalb genau aus wie vorher, und wer nie etwas ändert, merkt von der
	 * ganzen Verwaltung nichts.
	 */
	public static function defaults() {
		return array(
			'standard' => array(
				'name'   => 'Standardformular',
				'seiten' => array(
					array(
						'title' => 'Persönliche Angaben',
						'sub'   => 'Privat',
						'desc'  => 'Name und Anschrift der teilnehmenden Person.',
						'cat'   => 'privat',
						'felder' => array(
							array( 'key' => 'anrede',   'req' => 0, 'col' => 6 ),
							array( 'key' => 'titel',    'req' => 0, 'col' => 6 ),
							array( 'key' => 'vorname',  'req' => 1, 'col' => 6 ),
							array( 'key' => 'nachname', 'req' => 1, 'col' => 6 ),
							array( 'key' => 'strasse',  'req' => 1, 'col' => 12 ),
							array( 'key' => 'plz',      'req' => 1, 'col' => 4 ),
							array( 'key' => 'ort',      'req' => 1, 'col' => 8 ),
						),
					),
					array(
						'title' => 'Kontakt & Mitgliedschaft',
						'sub'   => 'Privat',
						'desc'  => 'Wie wir dich erreichen und deine IG-Metall-Mitgliedschaft.',
						'cat'   => 'privat',
						'felder' => array(
							array( 'key' => 'telefon',         'req' => 0, 'col' => 6 ),
							array( 'key' => 'mobil',           'req' => 1, 'col' => 6 ),
							array( 'key' => 'email',           'req' => 1, 'col' => 12 ),
							array( 'key' => 'mitglied',        'req' => 0, 'col' => 12 ),
							array( 'key' => 'mitgliedsnummer', 'req' => 0, 'col' => 6 ),
						),
					),
					array(
						'title' => 'Betrieb & Funktion',
						'sub'   => 'Dienstlich',
						'desc'  => 'Angaben zu Arbeitgeber, Funktion und Freistellung.',
						'cat'   => 'dienstlich',
						'felder' => array(
							array( 'key' => 'betrieb',         'req' => 1, 'col' => 12 ),
							array( 'key' => 'betrieb_strasse', 'req' => 1, 'col' => 12 ),
							array( 'key' => 'betrieb_plz',     'req' => 1, 'col' => 4 ),
							array( 'key' => 'betrieb_ort',     'req' => 1, 'col' => 8 ),
							array( 'key' => 'betrieb_email',   'req' => 1, 'col' => 12 ),
							array( 'key' => 'funktion',        'req' => 0, 'col' => 6 ),
							array( 'key' => 'freistellung',    'req' => 1, 'col' => 6 ),
						),
					),
					array(
						'title' => 'Abschluss',
						'sub'   => 'Abschluss',
						'desc'  => 'Wünsche ergänzen und Anmeldung absenden.',
						'cat'   => 'neutral',
						'felder' => array(
							array( 'key' => 'bemerkungen', 'req' => 0, 'col' => 12 ),
						),
					),
				),
			),
		);
	}

	/** Alle Formulare: Schlüssel => [name, seiten]. */
	public static function alle() {
		$gespeichert = get_option( self::OPTION, array() );
		if ( ! is_array( $gespeichert ) || ! $gespeichert ) {
			return self::defaults();
		}
		return $gespeichert;
	}

	/** Ein Formular oder null. */
	public static function get( $key ) {
		$alle = self::alle();
		return isset( $alle[ $key ] ) ? $alle[ $key ] : null;
	}

	/** Schlüssel => Name, für Auswahlfelder. */
	public static function choices() {
		$out = array();
		foreach ( self::alle() as $key => $f ) {
			$out[ $key ] = (string) ( $f['name'] ?? $key );
		}
		return $out;
	}

	/** Schlüssel des Standardformulars – immer einer, der auch existiert. */
	public static function standard_key() {
		$alle = self::alle();
		$key  = (string) get_option( self::OPTION_STANDARD, '' );
		if ( '' !== $key && isset( $alle[ $key ] ) ) {
			return $key;
		}
		// Kein (gültiger) Standard hinterlegt: das erste Formular. So gibt es nie
		// einen Zustand, in dem eine Anmeldung ohne Formular dasteht.
		$keys = array_keys( $alle );
		return $keys ? $keys[0] : 'standard';
	}

	/** Zuordnungsregeln. */
	public static function regeln() {
		$r = get_option( self::OPTION_REGELN, array() );
		return is_array( $r ) ? $r : array();
	}

	/* ===================================================================
	 *  Auflösung: welches Formular für dieses Seminar?
	 * =================================================================== */

	/**
	 * Formularschlüssel für ein Seminar.
	 *
	 * WIRD IMMER SERVERSEITIG AUS DER SEMINAR-ID BESTIMMT – nie aus dem
	 * abgeschickten Formular. Käme der Schlüssel aus dem POST, könnte ein
	 * direkt abgesetzter Aufruf das Formular mit den wenigsten Pflichtfeldern
	 * benennen und damit die Prüfung des eigentlich gültigen umgehen.
	 */
	public static function formular_for( $post_id ) {
		$alle = self::alle();

		$meta = (string) get_post_meta( (int) $post_id, self::META, true );
		if ( '' !== $meta && isset( $alle[ $meta ] ) ) {
			return $meta;
		}

		foreach ( self::regeln() as $regel ) {
			$ziel = (string) ( $regel['formular'] ?? '' );
			if ( ! isset( $alle[ $ziel ] ) ) {
				continue; // Regel zeigt auf ein gelöschtes Formular
			}
			if ( BI_Settings::rule_matches( (int) $post_id, $regel ) ) {
				return $ziel;
			}
		}

		return self::standard_key();
	}

	/**
	 * Die Seiten eines Formulars, fertig zusammengesetzt aus Bestand und
	 * Auswahl – genau die Struktur, die BI_Registration zum Anzeigen und zum
	 * Prüfen braucht.
	 *
	 * Ein Feld, das es im Bestand nicht mehr gibt, fällt hier still heraus:
	 * Gelöscht wird es zwar auch aus allen Formularen (siehe feld_entfernen),
	 * aber eine von Hand bearbeitete Option darf trotzdem nicht dazu führen,
	 * dass das Formular gar nicht mehr erscheint.
	 */
	public static function seiten( $formular_key = '' ) {
		$f = self::get( $formular_key );
		if ( ! $f ) {
			$f = self::get( self::standard_key() );
		}
		if ( ! $f ) {
			return array();
		}

		$bestand = BI_Anmeldefelder::alle();
		$out     = array();

		foreach ( (array) ( $f['seiten'] ?? array() ) as $i => $seite ) {
			$fields = array();
			foreach ( (array) ( $seite['felder'] ?? array() ) as $eintrag ) {
				$key = (string) ( $eintrag['key'] ?? '' );
				if ( '' === $key || ! isset( $bestand[ $key ] ) || isset( $fields[ $key ] ) ) {
					continue;
				}
				$def             = $bestand[ $key ];
				$def['required'] = ! empty( $eintrag['req'] );
				$col             = (int) ( $eintrag['col'] ?? 12 );
				if ( ! isset( self::breiten()[ $col ] ) || 12 === $col ) {
					$def['full'] = true;
				} else {
					$def['col'] = $col;
				}
				$fields[ $key ] = $def;
			}

			$cat = (string) ( $seite['cat'] ?? 'neutral' );
			$out[] = array(
				'key'      => 'seite' . ( (int) $i + 1 ),
				'title'    => (string) ( $seite['title'] ?? '' ),
				'sub'      => (string) ( $seite['sub'] ?? '' ),
				'desc'     => (string) ( $seite['desc'] ?? '' ),
				'category' => isset( self::kategorien()[ $cat ] ) ? $cat : 'neutral',
				'fields'   => $fields,
			);
		}
		return $out;
	}

	/** Alle Felder eines Formulars flach: Schlüssel => Definition. */
	public static function felder( $formular_key = '' ) {
		$out = array();
		foreach ( self::seiten( $formular_key ) as $seite ) {
			foreach ( $seite['fields'] as $key => $def ) {
				$out[ $key ] = $def;
			}
		}
		return $out;
	}

	/** Ist dieses Feld in diesem Formular enthalten? */
	public static function hat_feld( $formular_key, $feld ) {
		return isset( self::felder( $formular_key )[ $feld ] );
	}

	/** Feldschlüssel => Namen der Formulare, die es abfragen. */
	public static function feld_nutzung() {
		$out = array();
		foreach ( self::alle() as $key => $f ) {
			foreach ( (array) ( $f['seiten'] ?? array() ) as $seite ) {
				foreach ( (array) ( $seite['felder'] ?? array() ) as $eintrag ) {
					$fk = (string) ( $eintrag['key'] ?? '' );
					if ( '' === $fk ) {
						continue;
					}
					$name = (string) ( $f['name'] ?? $key );
					if ( ! in_array( $name, $out[ $fk ] ?? array(), true ) ) {
						$out[ $fk ][] = $name;
					}
				}
			}
		}
		return $out;
	}

	/** Ein Feld aus allen Formularen entfernen. Gibt die Zahl der betroffenen Formulare zurück. */
	public static function feld_entfernen( $feld ) {
		$alle     = self::alle();
		$betroffen = 0;
		foreach ( $alle as $key => $f ) {
			$traf = false;
			foreach ( (array) ( $f['seiten'] ?? array() ) as $si => $seite ) {
				$neu = array();
				foreach ( (array) ( $seite['felder'] ?? array() ) as $eintrag ) {
					if ( (string) ( $eintrag['key'] ?? '' ) === (string) $feld ) {
						$traf = true;
						continue;
					}
					$neu[] = $eintrag;
				}
				$alle[ $key ]['seiten'][ $si ]['felder'] = $neu;
			}
			if ( $traf ) {
				$betroffen++;
			}
		}
		if ( $betroffen ) {
			update_option( self::OPTION, $alle );
		}
		return $betroffen;
	}

	/**
	 * Was an diesem Formular auffällt – Sätze für die Bearbeiten-Ansicht.
	 *
	 * Kein Formular wird deswegen abgelehnt; die Sätze sagen, was die Folge ist.
	 */
	public static function warnungen( $formular_key ) {
		$felder = self::felder( $formular_key );
		$aus    = array();

		foreach ( BI_Anmeldefelder::pflicht() as $key ) {
			if ( ! isset( $felder[ $key ] ) ) {
				$aus[] = sprintf( 'Das Pflichtfeld „%s" fehlt – ohne es lässt sich die Anmeldung niemandem zuordnen.', $key );
			}
		}
		if ( ! isset( $felder['betrieb_plz'] ) ) {
			$aus[] = 'Ohne die <strong>betriebliche PLZ</strong> lässt sich keine Geschäftsstelle ermitteln: '
				. 'Die Benachrichtigung vom Typ „Geschäftsstelle" bleibt bei Anmeldungen über dieses Formular aus, '
				. 'und in der Anmeldeliste steht keine Zuständigkeit.';
		}
		if ( ! isset( $felder['freistellung'] ) ) {
			$aus[] = 'Ohne das Feld <strong>Freistellung</strong> wird nicht erfasst, nach welcher Vorschrift '
				. 'jemand teilnimmt. Der Platzhalter <code>{freistellung}</code> bleibt leer, und die '
				. 'Beschlussvorlage nach § 37,6 hat keine Grundlage.';
		}
		foreach ( self::seiten( $formular_key ) as $i => $seite ) {
			if ( ! $seite['fields'] ) {
				$aus[] = sprintf( 'Seite %d („%s") fragt nichts ab – sie erscheint als leerer Schritt.', $i + 1, $seite['title'] );
			}
		}
		return $aus;
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

	private static function redirect( $args = array(), $msg = '' ) {
		$args = array_merge( array( 'page' => self::PAGE ), $args );
		if ( $msg ) {
			$args['bi_msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Freien Schlüssel für ein neues Formular finden. */
	private static function neuer_key( $name ) {
		$roh = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$roh = strtr( $roh, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
		$roh = trim( preg_replace( '/[^a-z0-9]+/', '_', $roh ), '_' );
		$roh = '' === $roh ? 'formular' : substr( $roh, 0, 30 );

		$alle = self::alle();
		$key  = $roh;
		$i    = 2;
		while ( isset( $alle[ $key ] ) ) {
			$key = $roh . '_' . $i;
			$i++;
		}
		return $key;
	}

	public static function handle_neu() {
		self::guard( 'bi_formular_neu' );

		$name = sanitize_text_field( bi_post( 'name' ) );
		if ( '' === trim( $name ) ) {
			self::redirect( array(), 'Ohne Namen kein Formular.' );
		}
		$vorlage = sanitize_key( bi_post( 'vorlage' ) );
		$alle    = self::alle();
		$key     = self::neuer_key( $name );

		// Aus einer Vorlage abschreiben ist der Normalfall: Zwei Formulare
		// unterscheiden sich meist in drei Feldern, nicht in dreißig.
		$seiten = isset( $alle[ $vorlage ] )
			? $alle[ $vorlage ]['seiten']
			: array(
				array(
					'title' => 'Persönliche Angaben',
					'sub'   => 'Privat',
					'desc'  => '',
					'cat'   => 'privat',
					'felder' => array(
						array( 'key' => 'vorname',  'req' => 1, 'col' => 6 ),
						array( 'key' => 'nachname', 'req' => 1, 'col' => 6 ),
						array( 'key' => 'email',    'req' => 1, 'col' => 12 ),
					),
				),
			);

		$alle[ $key ] = array( 'name' => $name, 'seiten' => $seiten );
		update_option( self::OPTION, $alle );

		self::redirect( array( 'formular' => $key ), sprintf( 'Formular „%s" angelegt.', $name ) );
	}

	/**
	 * Das ganze Formular in einem Zug speichern.
	 *
	 * Jede Schaltfläche – auch die Pfeile und das Papierkorb-Symbol – ist eine
	 * gewöhnliche Absende-Schaltfläche und schickt den kompletten Stand mit.
	 * Deshalb geht beim Umsortieren nichts verloren, und es braucht kein
	 * JavaScript: Wer die Beschriftung einer Seite ändert und dann ein Feld
	 * verschiebt, hat am Ende beides.
	 */
	public static function handle_save() {
		self::guard( 'bi_formular_save' );

		$key  = sanitize_key( bi_post( 'key' ) );
		$alle = self::alle();
		if ( ! isset( $alle[ $key ] ) ) {
			self::redirect( array(), 'Formular nicht gefunden.' );
		}

		$bestand  = BI_Anmeldefelder::alle();
		$breiten  = self::breiten();
		$kats     = self::kategorien();
		$seiten_p = isset( $_POST['s'] ) && is_array( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$felder_p = isset( $_POST['f'] ) && is_array( $_POST['f'] ) ? wp_unslash( $_POST['f'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		ksort( $seiten_p, SORT_NUMERIC );

		$seiten  = array();
		$gesehen = array(); // ein Feld darf im ganzen Formular nur einmal stehen
		foreach ( $seiten_p as $i => $seite ) {
			$cat    = sanitize_key( $seite['cat'] ?? 'neutral' );
			$felder = array();

			$roh = isset( $felder_p[ $i ] ) && is_array( $felder_p[ $i ] ) ? $felder_p[ $i ] : array();
			ksort( $roh, SORT_NUMERIC );
			foreach ( $roh as $eintrag ) {
				$fk = sanitize_key( $eintrag['key'] ?? '' );
				if ( '' === $fk || ! isset( $bestand[ $fk ] ) || isset( $gesehen[ $fk ] ) ) {
					continue;
				}
				$col            = (int) ( $eintrag['col'] ?? 12 );
				$gesehen[ $fk ] = true;
				$felder[]       = array(
					'key' => $fk,
					'req' => ! empty( $eintrag['req'] ) ? 1 : 0,
					'col' => isset( $breiten[ $col ] ) ? $col : 12,
				);
			}

			$seiten[] = array(
				'title'  => sanitize_text_field( $seite['title'] ?? '' ),
				'sub'    => sanitize_text_field( $seite['sub'] ?? '' ),
				'desc'   => sanitize_text_field( $seite['desc'] ?? '' ),
				'cat'    => isset( $kats[ $cat ] ) ? $cat : 'neutral',
				'felder' => $felder,
			);
		}

		$name   = sanitize_text_field( bi_post( 'name' ) );
		$aktion = sanitize_text_field( bi_post( 'bi_do' ) );
		$msg    = '';

		// ---------- Umsortieren, Hinzufügen, Entfernen ----------
		$teile = explode( ':', $aktion );
		$was   = $teile[0] ?? '';
		$si    = isset( $teile[1] ) ? (int) $teile[1] : -1;
		$fi    = isset( $teile[2] ) ? (int) $teile[2] : -1;

		if ( 'seite_add' === $was ) {
			$seiten[] = array( 'title' => 'Neue Seite', 'sub' => '', 'desc' => '', 'cat' => 'neutral', 'felder' => array() );
			$msg      = 'Seite hinzugefügt.';

		} elseif ( 'seite_del' === $was && isset( $seiten[ $si ] ) ) {
			if ( count( $seiten ) < 2 ) {
				$msg = 'Die letzte Seite lässt sich nicht entfernen – ein Formular ohne Seite hätte nichts zu zeigen.';
			} else {
				// Die Felder der Seite gehen nicht verloren, sie wandern auf die
				// vorige. Ein Klick auf „Seite entfernen" soll die Seite
				// entfernen, nicht die Arbeit daran.
				$ziel = $si > 0 ? $si - 1 : 1;
				$seiten[ $ziel ]['felder'] = array_merge( $seiten[ $ziel ]['felder'], $seiten[ $si ]['felder'] );
				unset( $seiten[ $si ] );
				$seiten = array_values( $seiten );
				$msg    = 'Seite entfernt, ihre Felder stehen jetzt auf der Nachbarseite.';
			}

		} elseif ( ( 'seite_up' === $was || 'seite_down' === $was ) && isset( $seiten[ $si ] ) ) {
			$ziel = $si + ( 'seite_up' === $was ? -1 : 1 );
			if ( isset( $seiten[ $ziel ] ) ) {
				list( $seiten[ $si ], $seiten[ $ziel ] ) = array( $seiten[ $ziel ], $seiten[ $si ] );
			}

		} elseif ( 'feld_del' === $was && isset( $seiten[ $si ]['felder'][ $fi ] ) ) {
			$fk = $seiten[ $si ]['felder'][ $fi ]['key'];
			if ( in_array( $fk, BI_Anmeldefelder::pflicht(), true ) ) {
				$msg = 'Vorname, Nachname und E-Mail bleiben in jedem Formular.';
			} else {
				unset( $seiten[ $si ]['felder'][ $fi ] );
				$seiten[ $si ]['felder'] = array_values( $seiten[ $si ]['felder'] );
			}

		} elseif ( 'feld_add' === $was && isset( $seiten[ $si ] ) ) {
			$add = isset( $_POST['add'] ) && is_array( $_POST['add'] ) ? wp_unslash( $_POST['add'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fk  = sanitize_key( $add[ $si ] ?? '' );
			if ( '' === $fk || ! isset( $bestand[ $fk ] ) ) {
				$msg = 'Bitte erst ein Feld auswählen.';
			} elseif ( isset( $gesehen[ $fk ] ) ) {
				$msg = 'Dieses Feld steht schon im Formular.';
			} else {
				$seiten[ $si ]['felder'][] = array( 'key' => $fk, 'req' => 0, 'col' => 12 );
				$msg = sprintf( 'Feld „%s" hinzugefügt.', $bestand[ $fk ]['label'] );
			}

		} elseif ( ( 'feld_up' === $was || 'feld_down' === $was ) && isset( $seiten[ $si ]['felder'][ $fi ] ) ) {
			$hoch = ( 'feld_up' === $was );
			$ziel = $fi + ( $hoch ? -1 : 1 );

			if ( isset( $seiten[ $si ]['felder'][ $ziel ] ) ) {
				list( $seiten[ $si ]['felder'][ $fi ], $seiten[ $si ]['felder'][ $ziel ] )
					= array( $seiten[ $si ]['felder'][ $ziel ], $seiten[ $si ]['felder'][ $fi ] );
			} else {
				// Am Rand der Seite wandert das Feld auf die Nachbarseite. So
				// braucht das Verschieben zwischen Seiten kein eigenes Bedienelement.
				$nachbar = $si + ( $hoch ? -1 : 1 );
				if ( isset( $seiten[ $nachbar ] ) ) {
					$feld = $seiten[ $si ]['felder'][ $fi ];
					unset( $seiten[ $si ]['felder'][ $fi ] );
					$seiten[ $si ]['felder'] = array_values( $seiten[ $si ]['felder'] );
					if ( $hoch ) {
						$seiten[ $nachbar ]['felder'][] = $feld;
					} else {
						array_unshift( $seiten[ $nachbar ]['felder'], $feld );
					}
					$msg = sprintf( 'Feld auf Seite %d verschoben.', $nachbar + 1 );
				}
			}
		}

		$alle[ $key ]['name']   = '' !== $name ? $name : (string) ( $alle[ $key ]['name'] ?? $key );
		$alle[ $key ]['seiten'] = $seiten;
		update_option( self::OPTION, $alle );

		self::redirect( array( 'formular' => $key ), $msg ?: 'Formular gespeichert.' );
	}

	public static function handle_delete() {
		self::guard( 'bi_formular_delete' );

		$key  = sanitize_key( bi_post( 'key' ) );
		$alle = self::alle();
		if ( ! isset( $alle[ $key ] ) ) {
			self::redirect( array(), 'Formular nicht gefunden.' );
		}
		if ( count( $alle ) < 2 ) {
			self::redirect( array(), 'Das letzte Formular lässt sich nicht löschen – ohne Formular gäbe es keine Anmeldung.' );
		}

		$name = (string) ( $alle[ $key ]['name'] ?? $key );
		unset( $alle[ $key ] );
		update_option( self::OPTION, $alle );

		// Regeln, die auf das gelöschte Formular zeigen, mitnehmen. Eine Regel
		// ins Leere sähe aus wie eine Zuordnung und wäre keine.
		$regeln = array();
		$weg    = 0;
		foreach ( self::regeln() as $r ) {
			if ( (string) ( $r['formular'] ?? '' ) === $key ) {
				$weg++;
				continue;
			}
			$regeln[] = $r;
		}
		if ( $weg ) {
			update_option( self::OPTION_REGELN, $regeln );
		}

		// Seminare, die es von Hand zugewiesen hatten, fallen auf die Regeln
		// bzw. den Standard zurück.
		$seminare = self::meta_loeschen( $key );

		self::redirect( array(), sprintf(
			'Formular „%s" gelöscht.%s%s',
			$name,
			$weg ? sprintf( ' %d Regel(n) entfernt.', $weg ) : '',
			$seminare ? sprintf( ' %d Seminar(e) nutzen wieder die Zuordnung.', $seminare ) : ''
		) );
	}

	/** Zuweisungen dieses Formulars an Seminaren entfernen. Gibt die Zeilenzahl zurück. */
	private static function meta_loeschen( $key ) {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
			self::META,
			$key
		) );
	}

	public static function handle_standard() {
		self::guard( 'bi_formular_standard' );

		$key = sanitize_key( bi_post( 'key' ) );
		if ( ! self::get( $key ) ) {
			self::redirect( array(), 'Formular nicht gefunden.' );
		}
		update_option( self::OPTION_STANDARD, $key );
		self::redirect( array(), sprintf( '„%s" ist jetzt das Standardformular.', self::choices()[ $key ] ) );
	}

	public static function handle_regeln() {
		self::guard( 'bi_formular_regeln' );

		$felder    = BI_Settings::rule_fields();
		$formulare = self::choices();
		$roh       = isset( $_POST['regel'] ) && is_array( $_POST['regel'] ) ? wp_unslash( $_POST['regel'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		ksort( $roh, SORT_NUMERIC );

		$regeln = array();
		$pos_von = array(); // Zeilennummer im Formular => Position in der Liste
		foreach ( $roh as $i => $r ) {
			$field    = sanitize_key( $r['field'] ?? '' );
			$value    = sanitize_text_field( $r['value'] ?? '' );
			$formular = sanitize_key( $r['formular'] ?? '' );
			if ( ! isset( $felder[ $field ] ) || '' === trim( $value ) || ! isset( $formulare[ $formular ] ) ) {
				continue; // leere Zeile = gelöschte Zeile
			}
			$regeln[]           = array( 'field' => $field, 'value' => $value, 'formular' => $formular );
			$pos_von[ (int) $i ] = count( $regeln ) - 1;
		}

		// Verschieben: dieselbe Bedienung wie bei den Anmeldevarianten. Die
		// Schaltfläche nennt die Zeilennummer aus dem Formular, nicht die
		// Position – nur so trifft sie die gemeinte Regel, wenn im selben
		// Durchgang eine andere Zeile geleert wurde.
		$move = sanitize_text_field( bi_post( 'bi_move' ) );
		if ( '' !== $move ) {
			list( $richtung, $nr ) = array_pad( explode( ':', $move ), 2, '' );
			// Über die Zuordnung, nicht über die Zeilennummer: Wurde im selben
			// Durchgang eine Zeile darüber geleert, ist die Nummer nicht mehr die
			// Position – der Pfeil träfe sonst die falsche Regel.
			$pos  = $pos_von[ (int) $nr ] ?? -1;
			$ziel = $pos + ( 'up' === $richtung ? -1 : 1 );
			if ( isset( $regeln[ $pos ], $regeln[ $ziel ] ) ) {
				list( $regeln[ $pos ], $regeln[ $ziel ] ) = array( $regeln[ $ziel ], $regeln[ $pos ] );
			}
		}

		update_option( self::OPTION_REGELN, $regeln );
		self::redirect( array( 'tab' => 'zuordnung' ), sprintf( '%d Regel(n) gespeichert.', count( $regeln ) ) );
	}

	/* ===================================================================
	 *  Oberfläche
	 * =================================================================== */

	public static function render_page() {
		$notice = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'formulare'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offen  = isset( $_GET['formular'] ) ? sanitize_key( wp_unslash( $_GET['formular'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs   = array(
			'formulare' => 'Formulare',
			'bestand'   => 'Feld-Bestand',
			'zuordnung' => 'Zuordnung',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'formulare';
		}
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap">
			<h1>Anmeldeformulare</h1>
			<?php if ( $notice ) : ?><div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $k, $base ) ); ?>" class="nav-tab<?php echo $tab === $k ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'bestand' === $tab ) {
				BI_Anmeldefelder::render_section();
			} elseif ( 'zuordnung' === $tab ) {
				self::render_zuordnung();
			} elseif ( '' !== $offen && self::get( $offen ) ) {
				self::render_editor( $offen );
			} else {
				self::render_liste();
			}
			?>
		</div>
		<?php
	}

	/** Übersicht aller Formulare */
	private static function render_liste() {
		$alle     = self::alle();
		$standard = self::standard_key();
		$base     = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<p>Ein Anmeldeformular besteht aus <strong>Seiten</strong> und den <strong>Feldern</strong>, die es aus dem
		   <a href="<?php echo esc_url( add_query_arg( 'tab', 'bestand', $base ) ); ?>">Feld-Bestand</a> auswählt.
		   Welches Formular ein Seminar bekommt, sagt die
		   <a href="<?php echo esc_url( add_query_arg( 'tab', 'zuordnung', $base ) ); ?>">Zuordnung</a>.</p>

		<table class="widefat striped" style="margin-bottom:20px">
			<thead><tr>
				<th style="width:26%">Formular</th>
				<th style="width:10%">Seiten</th>
				<th style="width:10%">Felder</th>
				<th style="width:24%">Wird benutzt für</th>
				<th>Aktion</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $alle as $key => $f ) :
				$seiten = self::seiten( $key );
				$anzahl = 0;
				foreach ( $seiten as $s ) {
					$anzahl += count( $s['fields'] );
				}
				$warn = self::warnungen( $key );
				?>
				<tr>
					<td>
						<strong><a href="<?php echo esc_url( add_query_arg( 'formular', $key, $base ) ); ?>"><?php echo esc_html( $f['name'] ?? $key ); ?></a></strong>
						<?php if ( $key === $standard ) : ?>
							<span class="dashicons dashicons-yes" style="color:#008a20" title="Standardformular"></span> <em>Standard</em>
						<?php endif; ?>
						<br><code><?php echo esc_html( $key ); ?></code>
					</td>
					<td><?php echo esc_html( count( $seiten ) ); ?></td>
					<td><?php echo esc_html( $anzahl ); ?><?php if ( $warn ) : ?>
						<br><span style="color:#996800;font-size:12px"><?php echo esc_html( count( $warn ) ); ?> Hinweis(e)</span>
					<?php endif; ?></td>
					<td><?php echo esc_html( self::zuordnung_text( $key ) ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'formular', $key, $base ) ); ?>">Bearbeiten</a>
						<?php if ( $key !== $standard ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<input type="hidden" name="action" value="bi_formular_standard">
								<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
								<?php wp_nonce_field( 'bi_formular_standard' ); ?>
								<button class="button button-small">Als Standard</button>
							</form>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
							      onsubmit="return confirm('Formular „<?php echo esc_attr( $f['name'] ?? $key ); ?>“ wirklich löschen?');">
								<input type="hidden" name="action" value="bi_formular_delete">
								<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
								<?php wp_nonce_field( 'bi_formular_delete' ); ?>
								<button class="button button-small button-link-delete">Löschen</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<div class="card" style="max-width:100%">
			<h2 style="margin-top:0">Neues Formular</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_formular_neu">
				<?php wp_nonce_field( 'bi_formular_neu' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="bi_fname">Name</label></th>
						<td><input type="text" id="bi_fname" name="name" class="regular-text" required
						           placeholder="z. B. Kurzanmeldung Online-Seminare"></td>
					</tr>
					<tr>
						<th><label for="bi_fvorlage">Vorlage</label></th>
						<td>
							<select id="bi_fvorlage" name="vorlage">
								<option value="">leeres Formular (Name, Nachname, E-Mail)</option>
								<?php foreach ( self::choices() as $k => $name ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $name ); ?> abschreiben</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Zwei Formulare unterscheiden sich meist in wenigen Feldern – abschreiben
							   und streichen geht schneller als neu zusammenstellen.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Formular anlegen' ); ?>
			</form>
		</div>
		<?php
	}

	/** Ein Satz darüber, wodurch dieses Formular zum Einsatz kommt. */
	private static function zuordnung_text( $key ) {
		$teile = array();
		if ( $key === self::standard_key() ) {
			$teile[] = 'alle übrigen Seminare';
		}
		$n = 0;
		foreach ( self::regeln() as $r ) {
			if ( (string) ( $r['formular'] ?? '' ) === $key ) {
				$n++;
			}
		}
		if ( $n ) {
			$teile[] = sprintf( '%d Regel(n)', $n );
		}
		$seminare = self::meta_anzahl( $key );
		if ( $seminare ) {
			$teile[] = sprintf( '%s Seminar(e) von Hand', number_format_i18n( $seminare ) );
		}
		return $teile ? implode( ', ', $teile ) : 'nichts – es wird nirgends benutzt';
	}

	/** Wie viele Seminare weisen dieses Formular von Hand zu? */
	public static function meta_anzahl( $key ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s",
			self::META,
			$key
		) );
	}

	/** Der Editor eines Formulars: Seiten, Felder, Reihenfolge. */
	private static function render_editor( $key ) {
		$f       = self::get( $key );
		$seiten  = self::seiten( $key );
		$bestand = BI_Anmeldefelder::alle();
		$breiten = self::breiten();
		$kats    = self::kategorien();
		$pflicht = BI_Anmeldefelder::pflicht();
		$warn    = self::warnungen( $key );
		$base    = admin_url( 'admin.php?page=' . self::PAGE );
		// Einmal ermitteln, welche Felder schon im Formular stehen: Die
		// Auswahlliste „Feld hinzufügen" steht unter jeder Seite und würde die
		// Frage sonst je Seite und je Feld des Bestands neu stellen.
		$vorhanden = self::felder( $key );
		?>
		<p><a href="<?php echo esc_url( $base ); ?>">&larr; Alle Formulare</a></p>

		<?php if ( $warn ) : ?>
			<div class="notice notice-warning" style="margin:0 0 16px">
				<p><strong>Das ist die Folge dieser Auswahl:</strong></p>
				<ul style="list-style:disc;margin:0 0 10px 22px">
					<?php foreach ( $warn as $satz ) : ?>
						<li><?php echo wp_kses( $satz, array( 'strong' => array(), 'code' => array(), 'em' => array() ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_formular_save">
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
			<?php wp_nonce_field( 'bi_formular_save' ); ?>

			<?php // Die Eingabetaste in einem Textfeld löst die ERSTE Schaltfläche des
			      // Formulars aus. Ohne diese hier wäre das ein Pfeil, und aus einem
			      // Tippfehler würde eine verschobene Seite. ?>
			<button type="submit" name="bi_do" value="" style="display:none" aria-hidden="true" tabindex="-1">Speichern</button>

			<div class="card" style="max-width:100%;margin:0 0 20px">
				<table class="form-table" style="margin:0">
					<tr>
						<th><label for="bi_name">Name des Formulars</label></th>
						<td><input type="text" id="bi_name" name="name" class="regular-text"
						           value="<?php echo esc_attr( $f['name'] ?? $key ); ?>">
							<span style="color:#646970;margin-left:10px">Schlüssel: <code><?php echo esc_html( $key ); ?></code></span></td>
					</tr>
				</table>
			</div>

			<?php foreach ( $seiten as $si => $seite ) :
				$anzahl_felder = count( $seite['fields'] );
				$feld_keys     = array_keys( $seite['fields'] );
				?>
				<div class="card" style="max-width:100%;margin:0 0 18px">
					<h2 style="margin-top:0;display:flex;align-items:center;gap:10px">
						<span>Seite <?php echo (int) $si + 1; ?></span>
						<button class="button button-small" name="bi_do" value="seite_up:<?php echo (int) $si; ?>" <?php disabled( 0, $si ); ?> aria-label="Seite nach oben">↑</button>
						<button class="button button-small" name="bi_do" value="seite_down:<?php echo (int) $si; ?>" <?php disabled( count( $seiten ) - 1, $si ); ?> aria-label="Seite nach unten">↓</button>
						<button class="button button-small button-link-delete" name="bi_do" value="seite_del:<?php echo (int) $si; ?>"
						        style="margin-left:auto">Seite entfernen</button>
					</h2>

					<table class="form-table" style="margin-top:0">
						<tr>
							<th><label>Überschrift</label></th>
							<td><input type="text" style="width:100%;max-width:420px"
							           name="s[<?php echo (int) $si; ?>][title]"
							           value="<?php echo esc_attr( $seite['title'] ); ?>"></td>
						</tr>
						<tr>
							<th><label>Kurzwort im Schrittzähler</label></th>
							<td><input type="text" style="width:100%;max-width:220px"
							           name="s[<?php echo (int) $si; ?>][sub]"
							           value="<?php echo esc_attr( $seite['sub'] ); ?>"
							           placeholder="z. B. Privat">
								<select name="s[<?php echo (int) $si; ?>][cat]" style="margin-left:10px">
									<?php foreach ( $kats as $ck => $clabel ) : ?>
										<option value="<?php echo esc_attr( $ck ); ?>" <?php selected( $seite['category'], $ck ); ?>><?php echo esc_html( $clabel ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label>Beschreibung</label></th>
							<td><input type="text" style="width:100%"
							           name="s[<?php echo (int) $si; ?>][desc]"
							           value="<?php echo esc_attr( $seite['desc'] ); ?>"></td>
						</tr>
					</table>

					<table class="widefat striped" style="margin-bottom:12px">
						<thead><tr>
							<th style="width:34%">Feld</th>
							<th style="width:16%">Schlüssel</th>
							<th style="width:14%">Breite</th>
							<th style="width:12%">Pflicht</th>
							<th style="width:24%">Reihenfolge</th>
						</tr></thead>
						<tbody>
						<?php if ( ! $feld_keys ) : ?>
							<tr><td colspan="5" style="color:#996800">Diese Seite fragt nichts ab.</td></tr>
						<?php endif; ?>
						<?php foreach ( $feld_keys as $fi => $fk ) :
							$def = $seite['fields'][ $fk ];
							$col = ! empty( $def['full'] ) ? 12 : (int) ( $def['col'] ?? 12 );
							$ist_pflicht = in_array( $fk, $pflicht, true );
							?>
							<tr>
								<td>
									<input type="hidden" name="f[<?php echo (int) $si; ?>][<?php echo (int) $fi; ?>][key]" value="<?php echo esc_attr( $fk ); ?>">
									<strong><?php echo esc_html( $def['label'] ); ?></strong>
									<span style="color:#646970">· <?php echo esc_html( BI_Anmeldefelder::type_label( $def['type'] ) ); ?></span>
									<?php if ( isset( BI_Anmeldefelder::spalten()[ $fk ] ) ) : ?>
										<br><span style="color:#646970;font-size:12px">füllt die Spalte <code><?php echo esc_html( BI_Anmeldefelder::spalten()[ $fk ] ); ?></code></span>
									<?php endif; ?>
								</td>
								<td><code><?php echo esc_html( $fk ); ?></code></td>
								<td>
									<select name="f[<?php echo (int) $si; ?>][<?php echo (int) $fi; ?>][col]">
										<?php foreach ( $breiten as $bv => $blabel ) : ?>
											<option value="<?php echo esc_attr( $bv ); ?>" <?php selected( $col, $bv ); ?>><?php echo esc_html( $blabel ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<?php if ( $ist_pflicht ) : ?>
										<input type="hidden" name="f[<?php echo (int) $si; ?>][<?php echo (int) $fi; ?>][req]" value="1">
										<span title="Ohne dieses Feld geht es nicht">immer</span>
									<?php else : ?>
										<label><input type="checkbox" value="1"
										              name="f[<?php echo (int) $si; ?>][<?php echo (int) $fi; ?>][req]"
										              <?php checked( ! empty( $def['required'] ) ); ?>> Pflicht</label>
									<?php endif; ?>
								</td>
								<td>
									<button class="button button-small" name="bi_do" value="feld_up:<?php echo (int) $si; ?>:<?php echo (int) $fi; ?>"
									        <?php disabled( 0 === $si && 0 === $fi ); ?> aria-label="nach oben">↑</button>
									<button class="button button-small" name="bi_do" value="feld_down:<?php echo (int) $si; ?>:<?php echo (int) $fi; ?>"
									        <?php disabled( count( $seiten ) - 1 === $si && $anzahl_felder - 1 === $fi ); ?> aria-label="nach unten">↓</button>
									<?php if ( ! $ist_pflicht ) : ?>
										<button class="button button-small button-link-delete" name="bi_do" value="feld_del:<?php echo (int) $si; ?>:<?php echo (int) $fi; ?>">entfernen</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p>
						<select name="add[<?php echo (int) $si; ?>]">
							<option value="">— Feld hinzufügen —</option>
							<?php foreach ( $bestand as $bk => $bdef ) :
								if ( isset( $vorhanden[ $bk ] ) ) {
									continue; // steht schon irgendwo im Formular
								} ?>
								<option value="<?php echo esc_attr( $bk ); ?>"><?php echo esc_html( $bdef['label'] ); ?><?php echo empty( $bdef['eigen'] ) ? '' : ' (eigenes Feld)'; ?></option>
							<?php endforeach; ?>
						</select>
						<button class="button" name="bi_do" value="feld_add:<?php echo (int) $si; ?>">Hinzufügen</button>
					</p>
				</div>
			<?php endforeach; ?>

			<p>
				<button class="button" name="bi_do" value="seite_add">Seite hinzufügen</button>
				<button class="button button-primary" name="bi_do" value="">Formular speichern</button>
			</p>

			<p class="description" style="max-width:760px">
				Die <strong>letzte Seite</strong> trägt immer die Zusammenfassung, die Seminarangaben und die
				Einwilligung mit dem Absendeknopf – unabhängig davon, wie sie heißt. Ein Feld, das am oberen
				oder unteren Rand seiner Seite noch einen Schritt weiter geschoben wird, wandert auf die
				Nachbarseite. Alle Schaltflächen speichern den ganzen Stand mit; verlieren kann man dabei nichts.
			</p>
		</form>
		<?php
	}

	/** Regeln: welches Seminar bekommt welches Formular? */
	private static function render_zuordnung() {
		$regeln    = self::regeln();
		$felder    = BI_Settings::rule_fields();
		$formulare = self::choices();
		$standard  = self::standard_key();
		?>
		<div class="card" style="max-width:100%;margin:0 0 20px">
			<h2 style="margin-top:0">Welches Seminar bekommt welches Formular?</h2>
			<p>Drei Stufen, die erste, die etwas sagt, gewinnt:</p>
			<ol style="margin:0 0 14px 22px;list-style:decimal">
				<li>Das Feld <strong>„Anmeldeformular"</strong> in der Bearbeiten-Maske des Seminars – die Ausnahme von Hand.</li>
				<li>Die <strong>Regeln</strong> unten, von oben nach unten geprüft, die erste zutreffende gewinnt.</li>
				<li>Das <strong>Standardformular</strong>: <em><?php echo esc_html( $formulare[ $standard ] ?? $standard ); ?></em>.</li>
			</ol>
			<p style="color:#646970">Die Regeln lesen dieselben Felder wie die Regeln der Anmeldevarianten und
			   vergleichen einen <strong>Teiltext</strong> (Groß- und Kleinschreibung, Punkte und Kommas sind egal).
			   Wie dort gilt: Die Ausnahme gehört nach oben.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_formular_regeln">
			<?php wp_nonce_field( 'bi_formular_regeln' ); ?>
			<table class="widefat striped">
				<thead><tr>
					<th style="width:8%"></th>
					<th style="width:24%">Wenn dieses Feld …</th>
					<th style="width:28%">… diesen Text enthält</th>
					<th>… dann dieses Formular</th>
				</tr></thead>
				<tbody>
				<?php
				$zeilen = $regeln;
				$zeilen[] = array( 'field' => '', 'value' => '', 'formular' => '' ); // Leerzeile zum Anlegen
				foreach ( $zeilen as $i => $r ) : ?>
					<tr>
						<td>
							<?php if ( $i < count( $regeln ) ) : ?>
								<button class="button button-small" name="bi_move" value="up:<?php echo (int) $i; ?>" <?php disabled( 0, $i ); ?> aria-label="nach oben">↑</button>
								<button class="button button-small" name="bi_move" value="down:<?php echo (int) $i; ?>" <?php disabled( count( $regeln ) - 1, $i ); ?> aria-label="nach unten">↓</button>
							<?php endif; ?>
						</td>
						<td>
							<select name="regel[<?php echo (int) $i; ?>][field]" style="width:100%">
								<option value="">— Zeile leer lassen = löschen —</option>
								<?php foreach ( $felder as $fk => $flabel ) : ?>
									<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $r['field'] ?? '', $fk ); ?>><?php echo esc_html( $flabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="text" style="width:100%" name="regel[<?php echo (int) $i; ?>][value]"
						           value="<?php echo esc_attr( $r['value'] ?? '' ); ?>"
						           placeholder="z. B. Bildungsurlaub"></td>
						<td>
							<select name="regel[<?php echo (int) $i; ?>][formular]" style="width:100%">
								<option value="">— bitte wählen —</option>
								<?php foreach ( $formulare as $fk => $name ) : ?>
									<option value="<?php echo esc_attr( $fk ); ?>" <?php selected( $r['formular'] ?? '', $fk ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( 'Regeln speichern' ); ?>
		</form>
		<?php
	}
}
