<?php
/**
 * Datenpflege: Arbeitsmenge filtern, exportieren und Pakete wieder einlesen.
 *
 *  Leitgedanke: Es gibt genau EINE Auswahl („Arbeitsmenge"). Sie wird oben auf der
 *  Seite gefiltert, und jede Aktion darunter wirkt auf exakt diese Menge. Damit ist
 *  vor dem Klick immer klar, was betroffen ist – auch später, wenn hier die
 *  Massenbearbeitung (Suchen & Ersetzen, Wertelisten) dazukommt.
 *
 *  Diese Ausbaustufe kann:
 *    1. Arbeitsmenge filtern (Seminarform, Status, Freitext, Zeitraum, Taxonomien,
 *       „Feld ist leer", „Feld enthält") inkl. Vorschau der ersten Treffer.
 *    2. CSV-Export – die Kopfzeile entspricht exakt den Feldnamen, die BI_Import
 *       kennt. Dadurch ordnet der Seminar-Import in der Zielinstallation die
 *       Spalten von selbst zu (siehe BI_Import::guess_mapping()).
 *    3. JSON-Paket-Export – verlustfreier Umzug: alle Meta-Felder, die verwendeten
 *       Taxonomie-Begriffe inklusive Term-Meta „email" der Bildungszentren sowie
 *       die URL des Beitragsbilds.
 *    4. JSON-Paket-Import – legt fehlende Begriffe an, statt sie zu verlieren, und
 *       aktualisiert vorhandene Einträge wahlweise über die Seminarnummer.
 *
 *  Bewusst getrennt: CSV ist das Austauschformat für Tabellenkalkulation und den
 *  bestehenden Import, das JSON-Paket das Format für den 1:1-Umzug zwischen zwei
 *  Installationen dieses Plugins. Ein CSV-Export braucht deshalb eine einzelne
 *  Seminarform (Präsenz und Online haben unterschiedliche Feldsets), das Paket
 *  kann beide gemeinsam transportieren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Datenpflege {

	const PAGE           = 'bi-datenpflege';
	const PAKET_FORMAT   = 'bi-paket';
	/**
	 * 1 = erste Fassung.
	 * 2 = Begriffe werden gespiegelt: Jede Taxonomie steht im Paket, auch die
	 *     leere, und der Import setzt sie genau so. Ein Paket der Fassung 1
	 *     lässt sich weiterhin einlesen – dort fehlt der Schlüssel schlicht,
	 *     und die Zuordnung in der Zielinstallation bleibt unangetastet.
	 * 3 = Auf Wunsch liegen die AUSBILDUNGSREIHEN mit im Paket (Block `reihen`).
	 *     Fehlt der Block, verhält sich der Import wie bisher: Er legt eine
	 *     fehlende Reihe als leeren Entwurf an, damit die Termine ihre Zuordnung
	 *     behalten.
	 */
	const PAKET_VERSION  = 3;

	/** Wie viele Treffer die Vorschau auf der Seite zeigt. */
	const VORSCHAU = 10;

	public static function init() {
		add_action( 'admin_post_bi_export_csv',   array( __CLASS__, 'handle_export_csv' ) );
		add_action( 'admin_post_bi_export_json',  array( __CLASS__, 'handle_export_json' ) );
		add_action( 'admin_post_bi_paket_upload', array( __CLASS__, 'handle_paket_upload' ) );
		add_action( 'admin_post_bi_paket_run',    array( __CLASS__, 'handle_paket_run' ) );
		add_action( 'admin_post_bi_orte_aufraeumen', array( __CLASS__, 'handle_orte_aufraeumen' ) );
		add_action( 'admin_post_bi_textcodes_reparieren', array( __CLASS__, 'handle_textcodes_reparieren' ) );
		add_action( 'admin_post_bi_zentren_nachtragen', array( __CLASS__, 'handle_zentren_nachtragen' ) );
		add_action( 'admin_post_bi_begriffe_umbenennen', array( __CLASS__, 'handle_begriffe_umbenennen' ) );
		add_action( 'admin_post_bi_begriffe_zusammenfuehren', array( __CLASS__, 'handle_begriffe_zusammenfuehren' ) );
		add_action( 'admin_post_bi_begriffe_leeren', array( __CLASS__, 'handle_begriffe_leeren' ) );

		// Freitextsuche der Arbeitsmenge zusätzlich über die Seminarnummer.
		// Greift nur bei Abfragen, die bi_dp_search gesetzt haben – BI_CPT macht
		// dasselbe für die Haupt-Suchquery der Seminarliste.
		add_filter( 'posts_join',     array( __CLASS__, 'search_join' ), 10, 2 );
		add_filter( 'posts_where',    array( __CLASS__, 'search_where' ), 10, 2 );
		add_filter( 'posts_distinct', array( __CLASS__, 'search_distinct' ), 10, 2 );
	}

	/* ===================================================================
	 *  Arbeitsmenge: Filter einlesen, Abfrage bauen
	 * =================================================================== */

	/** Alle Status, die als „Eintrag in der Datenbank" gelten (ohne Papierkorb). */
	private static function alle_status() {
		return array( 'publish', 'draft', 'pending', 'future', 'private' );
	}

	/** Taxonomie-Slugs (für beide Beitragstypen identisch). */
	private static function tax_slugs() {
		return array_keys( BI_CPT::taxonomies() );
	}

	/**
	 * Meta-Felder der gewählten Seminarform. Bei „beide" die Vereinigung beider
	 * Feldsets – die Beschriftung der Präsenz-Seminare gewinnt, weil sie in der
	 * Oberfläche die geläufigere ist.
	 */
	public static function meta_all( $pt ) {
		if ( 'beide' !== $pt ) {
			return BI_CPT::meta_fields( $pt );
		}
		$fields = BI_CPT::meta_fields( BI_CPT );
		foreach ( BI_CPT::meta_fields( BI_ONLINE ) as $key => $cfg ) {
			if ( ! isset( $fields[ $key ] ) ) {
				$fields[ $key ] = $cfg;
			}
		}
		return $fields;
	}

	/** Filterwerte aus $_GET/$_POST normalisieren – Fremdwerte fallen auf Standards zurück. */
	public static function filter_from( $src ) {
		$val = function ( $key, $default = '' ) use ( $src ) {
			return isset( $src[ $key ] ) ? bi_scalar( wp_unslash( $src[ $key ] ), $default ) : $default;
		};

		$pt = sanitize_key( $val( 'pt', BI_CPT ) );
		if ( ! in_array( $pt, array( BI_CPT, BI_ONLINE, 'beide' ), true ) ) {
			$pt = BI_CPT;
		}
		$status = sanitize_key( $val( 'status', 'alle' ) );
		if ( ! in_array( $status, array( 'alle', 'publish', 'draft' ), true ) ) {
			$status = 'alle';
		}

		$fields = self::meta_all( $pt );
		$leer   = sanitize_key( $val( 'leer' ) );
		$feld   = sanitize_key( $val( 'feld' ) );

		$f = array(
			'pt'       => $pt,
			'status'   => $status,
			's'        => sanitize_text_field( $val( 's' ) ),
			'von'      => self::datum( $val( 'von' ) ),
			'bis'      => self::datum( $val( 'bis' ) ),
			'leer'     => isset( $fields[ $leer ] ) ? $leer : '',
			'feld'     => isset( $fields[ $feld ] ) ? $feld : '',
			'enthaelt' => sanitize_text_field( $val( 'enthaelt' ) ),
			'tax'      => array(),
		);
		// Taxonomie-Filter: je Taxonomie beliebig viele Begriffe. Ein einzelner
		// Wert (alte Links, alte Lesezeichen) wird von (array) mit erfasst, das
		// Format bleibt also abwärtskompatibel.
		foreach ( self::tax_slugs() as $tax ) {
			$roh = isset( $src[ 'tax_' . $tax ] ) ? wp_unslash( $src[ 'tax_' . $tax ] ) : array();
			$ids = array();
			foreach ( (array) $roh as $einzel ) {
				$id = (int) bi_scalar( $einzel, 0 );
				// „— alle —" schickt die 0 mit; sie darf nicht als Begriff gelten.
				if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			if ( $ids ) {
				$f['tax'][ $tax ] = $ids;
			}
		}
		return $f;
	}

	/** Datum aus einem <input type="date"> auf Y-m-d prüfen; sonst leer. */
	private static function datum( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$dt = DateTime::createFromFormat( 'Y-m-d', $raw );
		return ( $dt && $dt->format( 'Y-m-d' ) === $raw ) ? $raw : '';
	}

	/**
	 * Abfrage der Arbeitsmenge.
	 *
	 * Sortiert wird nach Titel, NICHT nach Startdatum: eine Sortierung über
	 * meta_key würde Einträge ohne dieses Meta-Feld per JOIN aussortieren – beim
	 * Export wären damit still Datensätze verschwunden.
	 *
	 * @param array $f        Normalisierter Filter aus filter_from().
	 * @param int   $per_page -1 = alle.
	 * @param int   $paged    Seite (1-basiert).
	 */
	public static function query( $f, $per_page = -1, $paged = 1 ) {
		$args = array(
			'post_type'      => ( 'beide' === $f['pt'] ) ? bi_seminar_post_types() : array( $f['pt'] ),
			'post_status'    => ( 'alle' === $f['status'] ) ? self::alle_status() : array( $f['status'] ),
			'posts_per_page' => (int) $per_page,
			'paged'          => max( 1, (int) $paged ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$meta = array();
		if ( $f['von'] ) {
			$meta[] = array( 'key' => '_bi_startdatum', 'value' => $f['von'], 'compare' => '>=', 'type' => 'DATE' );
		}
		if ( $f['bis'] ) {
			$meta[] = array( 'key' => '_bi_startdatum', 'value' => $f['bis'], 'compare' => '<=', 'type' => 'DATE' );
		}
		if ( $f['leer'] ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => $f['leer'], 'compare' => 'NOT EXISTS' ),
				array( 'key' => $f['leer'], 'value' => '', 'compare' => '=' ),
			);
		}
		if ( $f['feld'] && '' !== $f['enthaelt'] ) {
			$meta[] = array( 'key' => $f['feld'], 'value' => $f['enthaelt'], 'compare' => 'LIKE' );
		}
		if ( $meta ) {
			$meta['relation']   = 'AND';
			$args['meta_query'] = $meta;
		}

		// Innerhalb einer Taxonomie ODER (Programm 2027 *oder* 2028), zwischen
		// verschiedenen Taxonomien UND – genau wie in der Filterleiste im Frontend.
		// Andersherum wäre die Mehrfachauswahl sinnlos: „2027 und 2028" zugleich
		// trifft kein einziges Seminar, weil jedes nur zu einem Jahrgang gehört.
		$tax = array();
		foreach ( $f['tax'] as $slug => $term_ids ) {
			$tax[] = array(
				'taxonomy' => $slug,
				'field'    => 'term_id',
				'terms'    => array_map( 'intval', (array) $term_ids ),
				'operator' => 'IN',
			);
		}
		if ( $tax ) {
			$tax['relation']   = 'AND';
			$args['tax_query'] = $tax;
		}

		if ( '' !== $f['s'] ) {
			$args['s']            = $f['s'];
			$args['bi_dp_search'] = 1; // schaltet die Suche über die Seminarnummer zu
		}

		return new WP_Query( $args );
	}

	/* ---------- Freitextsuche inkl. Seminarnummer ---------- */

	private static function ist_suche( $query ) {
		return $query instanceof WP_Query && $query->get( 'bi_dp_search' );
	}

	public static function search_join( $join, $query ) {
		global $wpdb;
		if ( self::ist_suche( $query ) ) {
			$join .= " LEFT JOIN {$wpdb->postmeta} AS bi_dpn ON ( {$wpdb->posts}.ID = bi_dpn.post_id AND bi_dpn.meta_key = '_bi_seminarnummer' ) ";
		}
		return $join;
	}

	public static function search_where( $where, $query ) {
		global $wpdb;
		if ( self::ist_suche( $query ) ) {
			$where = preg_replace(
				"/\(\s*{$wpdb->posts}\.post_title\s+LIKE\s*('[^']+')\s*\)/",
				'(' . $wpdb->posts . '.post_title LIKE \1) OR (bi_dpn.meta_value LIKE \1)',
				$where
			);
		}
		return $where;
	}

	public static function search_distinct( $distinct, $query ) {
		return self::ist_suche( $query ) ? 'DISTINCT' : $distinct;
	}

	/* ===================================================================
	 *  Werte für den Export aufbereiten
	 * =================================================================== */

	/**
	 * CSV-Kopfzeile: Zielschlüssel => Spaltenname.
	 *
	 * Die Namen sind bewusst genau die, die BI_Import::guess_mapping() erkennt
	 * (Label-Gleichheit oder Alias). Wer diese Datei in einer anderen Installation
	 * hochlädt, findet die Zuordnung deshalb bereits ausgefüllt vor.
	 */
	public static function csv_headers( $pt ) {
		$h = array(
			'title'   => 'Seminartitel',
			'content' => 'Seminarbeschreibung',
		);
		foreach ( BI_CPT::meta_fields( $pt ) as $key => $cfg ) {
			$h[ $key ] = $cfg['label'];
		}
		foreach ( BI_CPT::taxonomies( $pt ) as $slug => $cfg ) {
			$h[ 'tax:' . $slug ] = $cfg['single'];
		}
		return $h;
	}

	/**
	 * Meta-Wert als Text für die CSV. Ja/Nein-Felder werden ausgeschrieben (mit
	 * dem Feld-Default bei leerem Wert), alles andere geht roh raus – Datum und
	 * Uhrzeit liegen bereits normalisiert in der Datenbank.
	 */
	private static function csv_meta( $post_id, $key, $cfg ) {
		if ( 'bool' === $cfg['type'] ) {
			return BI_CPT::meta_bool( $post_id, $key ) ? 'ja' : 'nein';
		}
		return (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Begriffe einer Taxonomie als Text.
	 *
	 * Getrennt wird mit „ | ": BI_Import::split_terms() trennt Mehrfachwerte an
	 * der Pipe, ein Komma bliebe dagegen bei „Gesundheit, Prävention" Teil des
	 * Begriffs. Die Reihenfolge folgt term_order, also der Reihenfolge, in der die
	 * Begriffe zugewiesen wurden.
	 */
	private static function term_text( $post_id, $tax ) {
		$terms = wp_get_object_terms( $post_id, $tax, array( 'orderby' => 'term_order', 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		return implode( ' | ', $terms );
	}

	/**
	 * Zerfällt dieser Begriff beim CSV-Reimport in mehrere?
	 *
	 * BI_Import::split_terms() trennt Mehrfach-Taxonomien an der Pipe ODER an
	 * „Komma + Leerzeichen" – letzteres, weil die Quelldaten des Bildungsprogramms
	 * genau so mehrere Werte in eine Zelle schreiben. Ein Begriff, der selbst ein
	 * „, " enthält (z. B. „Gesundheit, Prävention, Arbeitsschutz"), kommt beim
	 * Reimport deshalb als drei Begriffe an – egal, womit der Export trennt.
	 *
	 * Das lässt sich exportseitig nicht reparieren, ohne den regulären CSV-Import
	 * kaputtzumachen. Deshalb wird es sichtbar gemacht: die Oberfläche warnt und
	 * verweist auf das JSON-Paket, das die Begriffe einzeln überträgt.
	 */
	public static function begriff_zerfaellt( $name ) {
		return (bool) preg_match( '/,\s/u', (string) $name );
	}

	/** Verwendete Mehrfach-Begriffe, die beim CSV-Reimport zerfallen würden. */
	public static function kritische_begriffe( $pt ) {
		$treffer = array();
		foreach ( BI_CPT::taxonomies( 'beide' === $pt ? BI_CPT : $pt ) as $slug => $cfg ) {
			if ( empty( $cfg['multi'] ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $slug, 'hide_empty' => true, 'fields' => 'names' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			foreach ( $terms as $name ) {
				if ( self::begriff_zerfaellt( $name ) ) {
					$treffer[] = $name;
				}
			}
		}
		return $treffer;
	}

	/** Eine CSV-Zeile in der Reihenfolge der Kopfzeile. */
	private static function csv_row( $post, $keys ) {
		$fields = BI_CPT::meta_fields( $post->post_type );
		$row    = array();
		foreach ( $keys as $key ) {
			if ( 'title' === $key ) {
				$row[] = $post->post_title;
			} elseif ( 'content' === $key ) {
				$row[] = $post->post_content;
			} elseif ( 0 === strpos( $key, 'tax:' ) ) {
				$row[] = self::term_text( $post->ID, substr( $key, 4 ) );
			} else {
				$row[] = self::csv_meta( $post->ID, $key, $fields[ $key ] ?? array( 'type' => 'text' ) );
			}
		}
		return $row;
	}

	/** Dateiname mit Zeitstempel. */
	private static function filename( $teil, $endung ) {
		return sprintf( 'bildungsprogramm-%s-%s.%s', $teil, date_i18n( 'Y-m-d-Hi' ), $endung );
	}

	/** Header für einen Download setzen und alle offenen Puffer verwerfen. */
	private static function download_headers( $mime, $filename ) {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: ' . $mime . '; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	}

	/* ===================================================================
	 *  Export
	 * =================================================================== */

	public static function handle_export_csv() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_export' );

		$f = self::filter_from( $_POST );
		if ( 'beide' === $f['pt'] ) {
			self::redirect( $f, 'Der CSV-Export braucht eine einzelne Seminarform – Präsenz und Online haben unterschiedliche Felder. Für beide zusammen bitte das JSON-Paket nehmen.' );
		}

		@set_time_limit( 0 );
		$headers = self::csv_headers( $f['pt'] );
		$keys    = array_keys( $headers );
		$q       = self::query( $f, -1 );

		self::download_headers( 'text/csv', self::filename( self::label_for( $f['pt'] ), 'csv' ) );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM, damit Excel die Umlaute richtig liest
		// Semikolon als Trennzeichen: BI_Import::peek() erkennt es und deutsche
		// Tabellenkalkulationen öffnen die Datei damit ohne Import-Assistent.
		fputcsv( $out, array_values( $headers ), ';' );
		foreach ( $q->posts as $post ) {
			fputcsv( $out, self::csv_row( $post, $keys ), ';' );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Die Begriffe eines Beitrags fürs Paket – JEDE Taxonomie des Beitragstyps,
	 * auch die leere.
	 *
	 * Die leere Liste ist der Kern der Sache: Das Paket ist ein Spiegel des
	 * Quellstands, und „hier steht nichts" ist eine Aussage. Vorher wurde eine
	 * Taxonomie ohne Begriffe einfach weggelassen – der Import fand dann keinen
	 * Schlüssel, ließ die Zuordnung in der Zielinstallation stehen und ein
	 * aufgeräumtes Seminar behielt dort seine alten Begriffe. Genau daran hingen
	 * die alten Begriffe fest und ließen sich auch nicht als „unbenutzt"
	 * entfernen: Sie WAREN ja noch benutzt.
	 *
	 * @param array $terms_used Sammelstelle für die Begriffsliste des Pakets
	 *                          (slug => term_id => Eintrag), wird ergänzt.
	 * @return array slug => Namensliste
	 */
	/**
	 * Öffentlich, weil BI_Sync dieselbe Arbeit macht: Der Abgleich zwischen zwei
	 * Installationen spricht das Paketformat dieser Klasse. Zwei eigene Fassungen
	 * derselben Routine würden mit der Zeit auseinanderlaufen – und der Abgleich
	 * schriebe dann anders, als der Paket-Import es täte.
	 */
	public static function paket_terms( $post_id, $taxes, &$terms_used ) {
		$terms = array();
		foreach ( $taxes as $slug => $cfg ) {
			$objs  = wp_get_object_terms( $post_id, $slug, array( 'orderby' => 'term_order' ) );
			$names = array();
			if ( ! is_wp_error( $objs ) ) {
				foreach ( $objs as $t ) {
					$names[] = $t->name;
					if ( ! isset( $terms_used[ $slug ][ $t->term_id ] ) ) {
						$eintrag = array(
							'name'         => $t->name,
							'slug'         => $t->slug,
							'beschreibung' => (string) $t->description,
						);
						if ( ! empty( $cfg['has_email'] ) ) {
							$eintrag['email'] = (string) get_term_meta( $t->term_id, 'email', true );
						}
						$terms_used[ $slug ][ $t->term_id ] = $eintrag;
					}
				}
			}
			$terms[ $slug ] = $names;
		}
		return $terms;
	}

	public static function handle_export_json() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_export' );

		@set_time_limit( 0 );
		$f = self::filter_from( $_POST );
		$q = self::query( $f, -1 );

		$mit_reihen = ! empty( $_POST['mit_reihen'] );

		$terms_used = array();
		$eintraege  = array();
		$reihen_ids = array();

		foreach ( $q->posts as $post ) {
			$pt     = $post->post_type;
			$fields = BI_CPT::meta_fields( $pt );
			$taxes  = BI_CPT::taxonomies( $pt );

			// Alle Felder mitschreiben, auch leere: das Paket ist ein Spiegel des
			// Quellstands. Sonst behielte ein bereits vorhandener Eintrag in der
			// Zielinstallation seinen alten Wert, obwohl er in der Quelle geleert wurde.
			$meta = array();
			foreach ( $fields as $key => $cfg ) {
				$meta[ $key ] = ( 'bool' === $cfg['type'] )
					? ( BI_CPT::meta_bool( $post->ID, $key ) ? '1' : '0' )
					: (string) get_post_meta( $post->ID, $key, true );
			}

			$terms = self::paket_terms( $post->ID, $taxes, $terms_used );

			// Zu welcher Ausbildungsreihe gehört dieser Termin? Maßgeblich ist das
			// abgeleitete Feld; steht dort nichts, entscheidet der Rohtext
			// „Teil | Reihe" – angelegt wird hier nichts, wir exportieren nur.
			if ( $mit_reihen ) {
				$rid = (int) get_post_meta( $post->ID, BI_Reihen::META_REIHE, true );
				if ( ! $rid ) {
					$roh = BI_Reihen::parse( (string) get_post_meta( $post->ID, BI_Reihen::META_ROH, true ) );
					$rid = $roh ? BI_Reihen::reihe_id( $roh['reihe'], false ) : 0;
				}
				if ( $rid ) {
					$reihen_ids[ $rid ] = true;
				}
			}

			$eintraege[] = array(
				'post_type' => $pt,
				'title'     => $post->post_title,
				'content'   => $post->post_content,
				'status'    => $post->post_status,
				'meta'      => $meta,
				'terms'     => $terms,
				'bild'      => (string) get_the_post_thumbnail_url( $post->ID, 'full' ),
			);
		}

		// term_id-Schlüssel wegwerfen: in der Zielinstallation zählt der Name.
		$terms_export = array();
		foreach ( $terms_used as $slug => $liste ) {
			$terms_export[ $slug ] = array_values( $liste );
		}

		// Die Reihen der Auswahl – der Behälter gehört zu den Terminen, die im
		// Paket liegen. Wer nur ein Programmjahr exportiert, bekommt genau die
		// Reihen, zu denen dessen Termine gehören.
		$reihen_export = array();
		foreach ( array_keys( $reihen_ids ) as $rid ) {
			$reihe = get_post( $rid );
			if ( ! $reihe || BI_Reihen::CPT !== $reihe->post_type ) {
				continue;
			}
			$meta = array();
			foreach ( BI_Reihen::meta_fields() as $key => $cfg ) {
				$meta[ $key ] = ( 'bool' === $cfg['type'] )
					? ( BI_CPT::meta_bool( $rid, $key ) ? '1' : '0' )
					: (string) get_post_meta( $rid, $key, true );
			}
			$reihen_export[] = array(
				'title'   => $reihe->post_title,
				'slug'    => $reihe->post_name,
				'content' => $reihe->post_content,
				'excerpt' => $reihe->post_excerpt,
				'status'  => $reihe->post_status,
				'meta'    => $meta,
				'bild'    => (string) get_the_post_thumbnail_url( $rid, 'full' ),
			);
		}

		$paket = array(
			'format'  => self::PAKET_FORMAT,
			'version' => self::PAKET_VERSION,
			'erzeugt' => current_time( 'Y-m-d H:i:s' ),
			'quelle'  => array(
				'site'   => home_url(),
				'plugin' => BI_VERSION,
			),
			'auswahl' => array(
				'seminarform' => $f['pt'],
				'anzahl'      => count( $eintraege ),
				'mit_reihen'  => $mit_reihen ? 1 : 0,
			),
			'terms'    => $terms_export,
			'reihen'   => $reihen_export,
			'seminare' => $eintraege,
		);

		self::download_headers( 'application/json', self::filename( 'paket', 'json' ) );
		echo wp_json_encode( $paket, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ===================================================================
	 *  Paket-Import
	 * =================================================================== */

	/** Ablageort der hochgeladenen Pakete (derselbe Ordner wie beim CSV-Import). */
	private static function dir() {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'bi-import';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	public static function handle_paket_upload() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_paket_upload' );

		if ( empty( $_FILES['bi_paket']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bi_paket']['tmp_name'] ) ) {
			self::redirect( array(), 'Keine Datei empfangen.' );
		}
		$token = 'paket-' . wp_generate_password( 8, false ) . '.json';
		$dest  = self::dir() . '/' . $token;
		if ( ! move_uploaded_file( $_FILES['bi_paket']['tmp_name'], $dest ) ) {
			self::redirect( array(), 'Datei konnte nicht gespeichert werden.' );
		}

		$paket = self::paket_lesen( $dest );
		if ( ! $paket ) {
			@unlink( $dest );
			self::redirect( array(), 'Das ist kein gültiges Paket dieses Plugins (erwartet wird eine JSON-Datei mit format: „' . self::PAKET_FORMAT . '").' );
		}

		self::redirect( array( 'paket' => $token ) );
	}

	/** Paketdatei einlesen und grob prüfen. Gibt das Array zurück oder null. */
	private static function paket_lesen( $path ) {
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || self::PAKET_FORMAT !== ( $data['format'] ?? '' ) || ! isset( $data['seminare'] ) || ! is_array( $data['seminare'] ) ) {
			return null;
		}
		return $data;
	}

	public static function handle_paket_run() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_paket_run' );

		$token = sanitize_file_name( bi_post( 'paket' ) );
		$path  = self::dir() . '/' . $token;
		$paket = $token ? self::paket_lesen( $path ) : null;
		if ( ! $paket ) {
			self::redirect( array(), 'Paket nicht gefunden oder nicht lesbar. Bitte erneut hochladen.' );
		}

		@set_time_limit( 0 );

		$status_wahl = sanitize_key( bi_post( 'post_status', 'original' ) );
		$dedupe      = ! empty( $_POST['dedupe'] );
		$bilder      = ! empty( $_POST['bilder'] );
		$aufraeumen  = ! empty( $_POST['begriffe_aufraeumen'] );

		$terms_neu = self::terms_anlegen( $paket['terms'] ?? array() );

		// Die Reihen VOR den Seminaren: Sonst fände BI_Reihen::zuordnen() beim
		// ersten Termin keine Reihe des Namens und legte einen leeren Entwurf an –
		// und der stünde danach neben der richtigen Reihe aus dem Paket.
		$reihen_stat = self::reihen_anlegen( $paket['reihen'] ?? array(), $status_wahl, $bilder );

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$bilder_geholt = 0;

		foreach ( $paket['seminare'] as $eintrag ) {
			if ( ! is_array( $eintrag ) ) {
				$skipped++;
				continue;
			}
			$pt = ( isset( $eintrag['post_type'] ) && in_array( $eintrag['post_type'], bi_seminar_post_types(), true ) )
				? $eintrag['post_type'] : BI_CPT;

			$title = wp_strip_all_tags( (string) ( $eintrag['title'] ?? '' ) );
			if ( '' === trim( $title ) ) {
				$skipped++;
				continue;
			}

			$meta   = isset( $eintrag['meta'] ) && is_array( $eintrag['meta'] ) ? $eintrag['meta'] : array();
			$fields = BI_CPT::meta_fields( $pt );

			// Status: „original" übernimmt den Wert aus dem Paket, sofern er bekannt ist.
			$status = 'original' === $status_wahl
				? ( in_array( $eintrag['status'] ?? '', self::alle_status(), true ) ? $eintrag['status'] : 'publish' )
				: ( 'draft' === $status_wahl ? 'draft' : 'publish' );

			// Vorhandenen Eintrag über die Seminarnummer finden – immer nur innerhalb
			// desselben Beitragstyps, genau wie beim CSV-Import.
			$existing = 0;
			$nummer   = trim( (string) ( $meta['_bi_seminarnummer'] ?? '' ) );
			if ( $dedupe && '' !== $nummer ) {
				$found = get_posts( array(
					'post_type'   => $pt,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => '_bi_seminarnummer',
					'meta_value'  => $nummer,
				) );
				$existing = $found ? (int) $found[0] : 0;
			}

			$postarr = array(
				'post_type'    => $pt,
				'post_title'   => $title,
				'post_content' => wp_kses_post( (string) ( $eintrag['content'] ?? '' ) ),
				'post_status'  => $status,
			);
			if ( $existing ) {
				$postarr['ID'] = $existing;
				$post_id       = wp_update_post( $postarr );
			} else {
				$post_id = wp_insert_post( $postarr );
			}
			// Erst zählen, wenn das Schreiben geklappt hat – sonst stünde derselbe
			// Eintrag in der Meldung gleichzeitig als angelegt und übersprungen.
			if ( ! $post_id || is_wp_error( $post_id ) ) {
				$skipped++;
				continue;
			}
			if ( $existing ) {
				$updated++;
			} else {
				$created++;
			}

			foreach ( $fields as $key => $cfg ) {
				if ( ! array_key_exists( $key, $meta ) ) {
					continue;
				}
				update_post_meta( $post_id, $key, self::sanitize_meta( (string) $meta[ $key ], $cfg ) );
			}

			// Wie beim CSV-Import: Der Haken „Ausgebucht" aus dem Paket ist eine
			// Aussage über den Stand, keine Korrektur an der Ampel – ihre Notiz
			// wird deshalb vergessen (siehe BI_Ampel::hand_zuruecksetzen).
			if ( array_key_exists( '_bi_ausgebucht', $meta ) && class_exists( 'BI_Ampel' ) ) {
				BI_Ampel::hand_zuruecksetzen( $post_id );
			}

			// Begriffe setzen – GENAU die aus dem Paket, auch wenn es keine sind.
			//
			// Die leere Liste löscht die Zuordnung. Das ist der Unterschied
			// zwischen „Paket einlesen" und „Paket dazumischen": Wer ein Seminar
			// in der Quelle aufgeräumt hat, will es hier aufgeräumt wiederfinden.
			// Ein Paket der Fassung 1 enthält leere Taxonomien nicht – dort fehlt
			// der Schlüssel, und die Zuordnung bleibt unberührt wie bisher.
			$terms = isset( $eintrag['terms'] ) && is_array( $eintrag['terms'] ) ? $eintrag['terms'] : array();
			foreach ( $terms as $slug => $namen ) {
				if ( ! taxonomy_exists( $slug ) || ! is_array( $namen ) ) {
					continue;
				}
				$namen = array_values( array_filter( array_map( 'strval', $namen ), 'strlen' ) );
				wp_set_object_terms( $post_id, $namen, $slug, false );
			}

			// Zuordnung zur Ausbildungsreihe aus „Teil | Reihe" auflösen – genau wie
			// am Ende des CSV-Imports.
			//
			// Der save_post-Haken, der dasselbe beim Bearbeiten von Hand erledigt,
			// hilft hier nicht: Er feuert innerhalb von wp_insert_post(), also
			// BEVOR die Meta-Felder oben geschrieben sind. Bei einem neuen Eintrag
			// sieht er gar nichts, bei einem vorhandenen den alten Stand – die
			// abgeleiteten Felder blieben so leer oder veraltet, und die Termine
			// fänden nach dem Umzug ihre Reihe nicht wieder.
			BI_Reihen::zuordnen( $post_id );

			// Beitragsbild nur holen, wenn noch keines gesetzt ist – ein zweiter Lauf
			// desselben Pakets soll die Mediathek nicht mit Kopien füllen.
			if ( $bilder && ! empty( $eintrag['bild'] ) && ! has_post_thumbnail( $post_id ) ) {
				if ( self::bild_holen( (string) $eintrag['bild'], (int) $post_id ) ) {
					$bilder_geholt++;
				}
			}
		}

		@unlink( $path );

		// Begriffe, an denen jetzt nichts mehr hängt. Sie entstehen bei jedem
		// Paket-Import: Das Paket bringt die Begriffe der Quelle mit, es nimmt
		// aber keine mit, die es dort nicht mehr gibt. Ohne diese Zeile bliebe
		// die Frage offen, warum die Liste nach dem Import genauso lang ist wie
		// vorher – der häufigste Stolperstein beim Umzug zwischen zwei Installationen.
		$verwaist  = 0;
		$entfernt  = 0;
		foreach ( self::alle_taxonomien() as $tax ) {
			if ( $aufraeumen ) {
				$entfernt += self::begriffe_leeren( $tax );
			} else {
				$verwaist += count( self::begriffe_unbenutzt( $tax ) );
			}
		}

		$meldung = sprintf(
			'%d Einträge neu angelegt, %d aktualisiert, %d übersprungen. %d Begriffe neu angelegt.',
			$created,
			$updated,
			$skipped,
			$terms_neu
		);
		if ( $entfernt ) {
			$meldung .= sprintf( ' %d unbenutzte Begriffe entfernt.', $entfernt );
		} elseif ( $verwaist ) {
			$meldung .= sprintf(
				' %d Begriffe hängen jetzt an keinem Eintrag mehr – sie stehen weiter in der Liste. Reiter „Begriffe": „unbenutzte Begriffe entfernen".',
				$verwaist
			);
		}
		if ( $reihen_stat['neu'] || $reihen_stat['aktualisiert'] ) {
			$meldung .= sprintf(
				' %d Ausbildungsreihen angelegt, %d aktualisiert.',
				$reihen_stat['neu'],
				$reihen_stat['aktualisiert']
			);
		}
		$bilder_geholt += $reihen_stat['bilder'];
		if ( $bilder_geholt ) {
			$meldung .= sprintf( ' %d Beitragsbilder übernommen.', $bilder_geholt );
		}
		if ( $created || $updated ) {
			$hinweis = BI_Ampel::nach_import();
			if ( $hinweis ) {
				$meldung .= ' ' . $hinweis;
			}
		}

		self::redirect( array(), $meldung );
	}

	/**
	 * Ausbildungsreihen aus dem Paket anlegen oder auffrischen.
	 *
	 * Wiedererkannt wird über den TITEL. Das ist kein Notbehelf, sondern
	 * derselbe Schlüssel, mit dem das Feld „Teil | Reihe" am Seminar seine Reihe
	 * findet: In diesem Datenmodell IST der Name der Reihe ihre Identität. Eine
	 * ID wäre in der Zielinstallation eine andere, ein Slug ließe sich unabhängig
	 * vom Namen ändern.
	 *
	 * VORHANDENE REIHEN WERDEN ÜBERSCHRIEBEN – Text, Auszug, Status und alle
	 * Felder. Das Paket ist ein Spiegel des Quellstands; wer in der
	 * Zielinstallation eine Reihe überarbeitet hat, verliert diese Fassung. Der
	 * Slug bleibt dagegen stehen, wenn die Reihe schon da ist: Er steckt in
	 * Links, die andernorts gesetzt sind.
	 *
	 * @return array ['neu'=>int,'aktualisiert'=>int,'bilder'=>int]
	 */
	private static function reihen_anlegen( $reihen, $status_wahl, $bilder ) {
		$stat = array( 'neu' => 0, 'aktualisiert' => 0, 'bilder' => 0 );
		if ( ! is_array( $reihen ) || ! $reihen ) {
			return $stat;
		}
		$fields = BI_Reihen::meta_fields();

		foreach ( $reihen as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$titel = wp_strip_all_tags( (string) ( $r['title'] ?? '' ) );
			if ( '' === trim( $titel ) ) {
				continue;
			}

			$status = 'original' === $status_wahl
				? ( in_array( $r['status'] ?? '', self::alle_status(), true ) ? $r['status'] : 'publish' )
				: ( 'draft' === $status_wahl ? 'draft' : 'publish' );

			$vorhanden = BI_Reihen::reihe_id( $titel, false );

			$postarr = array(
				'post_type'    => BI_Reihen::CPT,
				'post_title'   => $titel,
				'post_content' => wp_kses_post( (string) ( $r['content'] ?? '' ) ),
				'post_excerpt' => sanitize_textarea_field( (string) ( $r['excerpt'] ?? '' ) ),
				'post_status'  => $status,
			);
			if ( $vorhanden ) {
				$postarr['ID'] = $vorhanden;
				$post_id       = wp_update_post( $postarr );
			} else {
				// Nur beim Anlegen: Der Slug aus der Quelle hält die Adresse der
				// Reihenseite gleich. Bei einer vorhandenen Reihe bliebe er stehen –
				// ihn zu ändern hieße, gesetzte Links ins Leere laufen zu lassen.
				if ( ! empty( $r['slug'] ) ) {
					$postarr['post_name'] = sanitize_title( (string) $r['slug'] );
				}
				$post_id = wp_insert_post( $postarr );
			}
			if ( ! $post_id || is_wp_error( $post_id ) ) {
				continue;
			}
			if ( $vorhanden ) {
				$stat['aktualisiert']++;
			} else {
				$stat['neu']++;
			}

			$meta = isset( $r['meta'] ) && is_array( $r['meta'] ) ? $r['meta'] : array();
			foreach ( $fields as $key => $cfg ) {
				if ( ! array_key_exists( $key, $meta ) ) {
					continue;
				}
				update_post_meta( $post_id, $key, self::sanitize_meta( (string) $meta[ $key ], $cfg ) );
			}

			if ( $bilder && ! empty( $r['bild'] ) && ! has_post_thumbnail( $post_id ) ) {
				if ( self::bild_holen( (string) $r['bild'], (int) $post_id ) ) {
					$stat['bilder']++;
				}
			}
		}
		return $stat;
	}

	/**
	 * Begriffe aus dem Paket anlegen, bevor die Seminare geschrieben werden.
	 *
	 * wp_set_object_terms() würde fehlende Begriffe zwar selbst anlegen, dabei
	 * gingen aber Slug, Beschreibung und vor allem die Term-Meta „email" der
	 * Bildungszentren verloren – und ohne die läuft der Mail-Trigger ins Leere.
	 *
	 * @return int Anzahl neu angelegter Begriffe.
	 */
	/**
	 * Öffentlich, weil BI_Sync dieselbe Arbeit macht: Der Abgleich zwischen zwei
	 * Installationen spricht das Paketformat dieser Klasse. Zwei eigene Fassungen
	 * derselben Routine würden mit der Zeit auseinanderlaufen – und der Abgleich
	 * schriebe dann anders, als der Paket-Import es täte.
	 */
	public static function terms_anlegen( $terms ) {
		$neu = 0;
		if ( ! is_array( $terms ) ) {
			return 0;
		}
		foreach ( $terms as $slug => $liste ) {
			if ( ! taxonomy_exists( $slug ) || ! is_array( $liste ) ) {
				continue;
			}
			foreach ( $liste as $t ) {
				$name = isset( $t['name'] ) ? trim( (string) $t['name'] ) : '';
				if ( '' === $name ) {
					continue;
				}
				$vorhanden = get_term_by( 'name', $name, $slug );
				if ( $vorhanden ) {
					$term_id = (int) $vorhanden->term_id;
				} else {
					$args = array( 'description' => sanitize_textarea_field( (string) ( $t['beschreibung'] ?? '' ) ) );
					if ( ! empty( $t['slug'] ) ) {
						$args['slug'] = sanitize_title( (string) $t['slug'] );
					}
					$res = wp_insert_term( $name, $slug, $args );
					// Slug schon vergeben? Dann ohne Slug anlegen und WordPress einen finden lassen.
					if ( is_wp_error( $res ) && isset( $args['slug'] ) ) {
						unset( $args['slug'] );
						$res = wp_insert_term( $name, $slug, $args );
					}
					if ( is_wp_error( $res ) ) {
						continue;
					}
					$term_id = (int) $res['term_id'];
					$neu++;
				}
				// Adresse nur ergänzen, nie überschreiben: eine lokal gepflegte
				// Adresse ist aktueller als die aus dem Paket.
				if ( $term_id && ! empty( $t['email'] ) && ! get_term_meta( $term_id, 'email', true ) ) {
					update_term_meta( $term_id, 'email', sanitize_email( (string) $t['email'] ) );
				}
			}
		}
		return $neu;
	}

	/** Meta-Wert aus dem Paket säubern. Werte liegen bereits normalisiert vor. */
	/**
	 * Öffentlich, weil BI_Sync dieselbe Arbeit macht: Der Abgleich zwischen zwei
	 * Installationen spricht das Paketformat dieser Klasse. Zwei eigene Fassungen
	 * derselben Routine würden mit der Zeit auseinanderlaufen – und der Abgleich
	 * schriebe dann anders, als der Paket-Import es täte.
	 */
	public static function sanitize_meta( $raw, $cfg ) {
		switch ( $cfg['type'] ) {
			case 'html':
				return wp_kses_post( $raw );
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'email':
				return sanitize_email( $raw );
			case 'url':
				return esc_url_raw( trim( $raw ) );
			case 'select':
				return isset( $cfg['options'][ $raw ] ) ? sanitize_text_field( $raw ) : (string) ( $cfg['default'] ?? '' );
			case 'money':
				return BI_CPT::money_parse( $raw );
			case 'bool':
				return ( '1' === trim( $raw ) ) ? '1' : '0';
			default:
				return sanitize_text_field( $raw );
		}
	}

	/** Beitragsbild von der Quellseite in die Mediathek holen. */
	/**
	 * Öffentlich, weil BI_Sync dieselbe Arbeit macht: Der Abgleich zwischen zwei
	 * Installationen spricht das Paketformat dieser Klasse. Zwei eigene Fassungen
	 * derselben Routine würden mit der Zeit auseinanderlaufen – und der Abgleich
	 * schriebe dann anders, als der Paket-Import es täte.
	 */
	public static function bild_holen( $url, $post_id ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, $post_id, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return false;
		}
		set_post_thumbnail( $post_id, (int) $attachment_id );
		return true;
	}

	/* ===================================================================
	 *  Orte aufräumen
	 * =================================================================== */

	/**
	 * Begriffe der Taxonomie „Bildungszentrum", die keine Bildungszentren sind –
	 * also Hotels, Tagungshäuser, „auf Anfrage". Sie stammen aus den Jahrgängen,
	 * in denen es nur eine gemeinsame Ortsangabe gab.
	 */
	public static function fremde_orte() {
		$out   = array();
		$terms = get_terms( array( 'taxonomy' => BI_TAX_ORT, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $out;
		}
		foreach ( $terms as $t ) {
			if ( BI_CPT::ist_bildungszentrum( $t->name ) || BI_CPT::ANDERE === $t->name ) {
				continue;
			}
			$out[] = $t;
		}
		return $out;
	}

	/** Begriff mit diesem Namen finden oder anlegen. 0 = nicht möglich. */
	private static function term_id_fuer_namen( $name ) {
		$vorhanden = get_term_by( 'name', $name, BI_TAX_ORT );
		if ( $vorhanden && ! is_wp_error( $vorhanden ) ) {
			return (int) $vorhanden->term_id;
		}
		$res = wp_insert_term( $name, BI_TAX_ORT );
		return is_wp_error( $res ) ? 0 : (int) $res['term_id'];
	}

	/**
	 * Alle Beiträge eines Begriffs auf einen anderen umhängen.
	 * Gibt die Anzahl der geänderten Beiträge zurück.
	 *
	 * Es werden BEIDE Beitragstypen erfasst: Der Begriff selbst wird danach
	 * gelöscht, also müssen auch Online-Seminare mitkommen, die ihn als
	 * Veranstalter*in führen – sonst verlören sie ihre Zuordnung.
	 */
	private static function term_umhaengen( $alt_id, $ziel_id, $taxonomy = BI_TAX_ORT ) {
		$ids = get_posts( array(
			'post_type'   => bi_seminar_post_types(),
			'post_status' => self::alle_status(),
			'numberposts' => -1,
			'fields'      => 'ids',
			'tax_query'   => array(
				array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => (int) $alt_id ),
			),
		) );

		$n = 0;
		foreach ( $ids as $id ) {
			$aktuell = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $aktuell ) ) {
				continue;
			}
			// Bei Mehrfach-Taxonomien bleiben die übrigen Begriffe stehen; nur der
			// eine wird ersetzt. array_unique fängt den Fall ab, dass ein Eintrag
			// Quelle UND Ziel trägt.
			$neu = array();
			foreach ( (array) $aktuell as $tid ) {
				$neu[] = ( (int) $tid === (int) $alt_id ) ? (int) $ziel_id : (int) $tid;
			}
			wp_set_object_terms( $id, array_values( array_unique( $neu ) ), $taxonomy, false );
			$n++;
		}
		return $n;
	}

	/* ===================================================================
	 *  Reiter „Begriffe" – Taxonomien aufräumen
	 *
	 *  Die WordPress-eigenen Begriffsseiten liegen unter edit-tags.php und sind
	 *  aus diesem Plugin heraus nur über einen Link in der Bearbeiten-Maske
	 *  eines Seminars erreichbar. Für Datenpflege ist das der falsche Weg: Man
	 *  räumt Begriffe nicht auf, indem man ein Seminar öffnet. Hier stehen
	 *  deshalb alle fünf Taxonomien mit den drei Handgriffen, die im Alltag
	 *  gebraucht werden – umbenennen, zusammenführen, unbenutzte entfernen.
	 * =================================================================== */

	/** Die Taxonomie des Reiters „Begriffe" aus der Adresszeile. */
	private static function begriffe_tax() {
		$slug  = sanitize_key( bi_get( 'tax', BI_TAX_ORT ) );
		$taxes = array_keys( BI_CPT::taxonomies() );
		return in_array( $slug, $taxes, true ) ? $slug : BI_TAX_ORT;
	}

	/** Begriffe umbenennen. Reine Anzeigeänderung, keine Zuordnung wird berührt. */
	public static function handle_begriffe_umbenennen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_begriffe' );

		$tax     = sanitize_key( bi_post( 'tax' ) );
		$eingabe = ( isset( $_POST['name'] ) && is_array( $_POST['name'] ) ) ? wp_unslash( $_POST['name'] ) : array();
		if ( ! taxonomy_exists( $tax ) ) {
			self::redirect( array(), 'Unbekannte Taxonomie.' );
		}

		$n = 0;
		foreach ( $eingabe as $term_id => $neu ) {
			$neu  = sanitize_text_field( $neu );
			$term = get_term( (int) $term_id, $tax );
			if ( ! $term || is_wp_error( $term ) || '' === trim( $neu ) || $neu === $term->name ) {
				continue;
			}
			$res = wp_update_term( (int) $term_id, $tax, array( 'name' => $neu ) );
			if ( ! is_wp_error( $res ) ) {
				$n++;
			}
		}

		self::redirect_begriffe( $tax, sprintf( '%s Begriff(e) umbenannt.', number_format_i18n( $n ) ) );
	}

	/** Begriffe zusammenführen: Zuordnungen wandern zum Ziel, die Quelle wird gelöscht. */
	public static function handle_begriffe_zusammenfuehren() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_begriffe' );

		@set_time_limit( 0 );

		$tax     = sanitize_key( bi_post( 'tax' ) );
		$eingabe = ( isset( $_POST['ziel'] ) && is_array( $_POST['ziel'] ) ) ? wp_unslash( $_POST['ziel'] ) : array();
		if ( ! taxonomy_exists( $tax ) ) {
			self::redirect( array(), 'Unbekannte Taxonomie.' );
		}

		$verschoben = 0;
		$geloescht  = 0;

		foreach ( $eingabe as $quelle_id => $ziel_id ) {
			$quelle_id = (int) $quelle_id;
			$ziel_id   = (int) $ziel_id;
			if ( ! $quelle_id || ! $ziel_id || $quelle_id === $ziel_id ) {
				continue;
			}
			// Beide müssen zu dieser Taxonomie gehören – aus einem Formular kann
			// alles kommen, und ein fremder Begriff würde still Unsinn anrichten.
			$q = get_term( $quelle_id, $tax );
			$z = get_term( $ziel_id, $tax );
			if ( ! $q || ! $z || is_wp_error( $q ) || is_wp_error( $z ) ) {
				continue;
			}

			$verschoben += self::term_umhaengen( $quelle_id, $ziel_id, $tax );

			$frisch = get_term( $quelle_id, $tax );
			if ( $frisch && ! is_wp_error( $frisch ) && 0 === (int) $frisch->count ) {
				wp_delete_term( $quelle_id, $tax );
				$geloescht++;
			}
		}

		self::redirect_begriffe( $tax, sprintf(
			'%s Zuordnung(en) umgehängt, %s Begriff(e) entfernt.',
			number_format_i18n( $verschoben ),
			number_format_i18n( $geloescht )
		) );
	}

	/**
	 * Begriffe einer Taxonomie, an denen kein einziger Beitrag hängt.
	 *
	 * Gefragt wird die Zuordnungstabelle, nicht die Spalte `count`: Die zählt
	 * nur veröffentlichte Beiträge. Ein Begriff, der ausschließlich an einem
	 * Entwurf hängt, hätte dort die Zahl 0 – und „unbenutzt" wäre eine
	 * Falschaussage, die beim Löschen einen Entwurf beschädigt.
	 *
	 * @return int[] term_ids
	 */
	public static function begriffe_unbenutzt( $tax ) {
		global $wpdb;
		if ( ! taxonomy_exists( $tax ) ) {
			return array();
		}
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT tt.term_id
			   FROM {$wpdb->term_taxonomy} tt
			   LEFT JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			  WHERE tt.taxonomy = %s
			    AND tr.object_id IS NULL",
			$tax
		) ) );
	}

	/** Unbenutzte Begriffe einer Taxonomie löschen. Gibt die Anzahl zurück. */
	public static function begriffe_leeren( $tax ) {
		$n = 0;
		foreach ( self::begriffe_unbenutzt( $tax ) as $term_id ) {
			if ( ! is_wp_error( wp_delete_term( $term_id, $tax ) ) ) {
				$n++;
			}
		}
		return $n;
	}

	/** Alle Taxonomie-Slugs beider Seminarformen, jeder einmal. */
	public static function alle_taxonomien() {
		$slugs = array();
		foreach ( bi_seminar_post_types() as $pt ) {
			foreach ( array_keys( BI_CPT::taxonomies( $pt ) ) as $slug ) {
				$slugs[ $slug ] = true;
			}
		}
		return array_keys( $slugs );
	}

	/** Begriffe ohne jede Zuordnung entfernen. */
	public static function handle_begriffe_leeren() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_begriffe' );

		$tax = sanitize_key( bi_post( 'tax' ) );
		if ( ! taxonomy_exists( $tax ) ) {
			self::redirect( array(), 'Unbekannte Taxonomie.' );
		}

		$n = self::begriffe_leeren( $tax );

		self::redirect_begriffe( $tax, sprintf( '%s unbenutzte Begriff(e) entfernt.', number_format_i18n( $n ) ) );
	}

	/** Zurück auf den Reiter „Begriffe" derselben Taxonomie. */
	private static function redirect_begriffe( $tax, $msg ) {
		wp_safe_redirect( add_query_arg( array(
			'page'   => self::PAGE,
			'tab'    => 'begriffe',
			'tax'    => $tax,
			'bi_msg' => rawurlencode( $msg ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Präsenz-Seminare ohne zuständiges Bildungszentrum – aufgeschlüsselt danach,
	 * ob ihr Seminarort verrät, welches gemeint ist.
	 *
	 * ANLASS: Bis 2026 trug die Importdatei nur eine Spalte „Seminarort", und die
	 * enthielt das Bildungszentrum. Ab 2027 gibt es beide Spalten getrennt, und
	 * „Seminarort" bedeutet seither den tatsächlichen Veranstaltungsort. Wer eine
	 * alte Datei nach dieser Umstellung erneut einliest, bekommt das
	 * Bildungszentrum deshalb ins Feld Seminarort geschrieben – die Taxonomie
	 * bleibt leer, und die Seminare fehlen im Filter und beim Mail-Trigger.
	 *
	 * @return array ['zentren' => [kanonisch => [anzahl, ids]], 'andere' => [...], 'leer' => int]
	 */
	public static function fehlende_zentren() {
		$ids = get_posts( array(
			'post_type'   => BI_CPT,
			'post_status' => self::alle_status(),
			'numberposts' => -1,
			'fields'      => 'ids',
			'tax_query'   => array(
				array( 'taxonomy' => BI_TAX_ORT, 'operator' => 'NOT EXISTS' ),
			),
		) );

		$ergebnis = array( 'zentren' => array(), 'andere' => array(), 'leer' => 0 );
		if ( ! $ids ) {
			return $ergebnis;
		}
		update_meta_cache( 'post', $ids ); // sonst je Eintrag eine eigene Abfrage

		foreach ( $ids as $id ) {
			$ort = trim( (string) get_post_meta( $id, '_bi_seminarort', true ) );
			if ( '' === $ort ) {
				$ergebnis['leer']++;
				continue;
			}
			$kanonisch = BI_CPT::zentrum_fuer( $ort );
			if ( '' !== $kanonisch ) {
				$ergebnis['zentren'][ $kanonisch ][] = (int) $id;
			} else {
				$ergebnis['andere'][ $ort ][] = (int) $id;
			}
		}
		ksort( $ergebnis['zentren'] );
		ksort( $ergebnis['andere'] );
		return $ergebnis;
	}

	/**
	 * Das Bildungszentrum aus dem Seminarort nachtragen.
	 *
	 * Benennt der Seminarort ein Bildungszentrum, wandert er in die Taxonomie und
	 * wird im Feld geleert – dort stünde sonst dieselbe Angabe ein zweites Mal,
	 * und die Detailseite zeigt das Bildungszentrum ohnehin, wenn kein
	 * abweichender Ort gepflegt ist.
	 *
	 * Benennt er etwas anderes (Hotel, „auf Anfrage"), wird „Andere" gesetzt und
	 * der Ort bleibt stehen – das ist dieselbe Regel wie beim Import.
	 */
	public static function handle_zentren_nachtragen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_zentren_nachtragen' );

		@set_time_limit( 0 );
		$offen = self::fehlende_zentren();

		$zugeordnet = 0;
		$andere     = 0;

		foreach ( $offen['zentren'] as $kanonisch => $ids ) {
			$term_id = self::ziel_term_fuer_zentrum( $kanonisch );
			if ( ! $term_id ) {
				continue;
			}
			foreach ( $ids as $id ) {
				wp_set_object_terms( $id, array( (int) $term_id ), BI_TAX_ORT, false );
				delete_post_meta( $id, '_bi_seminarort' );
				$zugeordnet++;
			}
		}

		foreach ( $offen['andere'] as $ids ) {
			foreach ( $ids as $id ) {
				wp_set_object_terms( $id, array( BI_CPT::ANDERE ), BI_TAX_ORT, false );
				$andere++;
			}
		}

		if ( ! $zugeordnet && ! $andere ) {
			self::redirect( array(), 'Es gab nichts nachzutragen.' );
		}

		self::redirect( array(), sprintf(
			'%s Seminar(e) ihrem Bildungszentrum zugeordnet, %s unter „%s" eingeordnet.',
			number_format_i18n( $zugeordnet ),
			number_format_i18n( $andere ),
			BI_CPT::ANDERE
		) );
	}

	/**
	 * Passenden Begriff für ein Bildungszentrum finden.
	 *
	 * Erst der Begriff mit genau diesem Namen, sonst irgendein vorhandener
	 * desselben Hauses – lieber die eingeführte Schreibweise weiterverwenden als
	 * eine achte danebenzustellen. Erst wenn es gar keinen gibt, wird angelegt.
	 */
	private static function ziel_term_fuer_zentrum( $kanonisch ) {
		$exakt = get_term_by( 'name', $kanonisch, BI_TAX_ORT );
		if ( $exakt && ! is_wp_error( $exakt ) ) {
			return (int) $exakt->term_id;
		}
		$terms = get_terms( array( 'taxonomy' => BI_TAX_ORT, 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( BI_CPT::zentrum_fuer( $t->name ) === $kanonisch ) {
					return (int) $t->term_id;
				}
			}
		}
		return self::term_id_fuer_namen( $kanonisch );
	}

	/* ===================================================================
	 *  HTML-Codes in Klartextfeldern
	 * =================================================================== */

	/**
	 * Welche Meta-Felder sind Klartext? Nur die werden repariert.
	 *
	 * `html`-Felder bleiben außen vor: Dort ist `&amp;` richtig geschrieben und
	 * wird im Browser als „&" gezeigt – ein Auflösen machte aus maskiertem Text
	 * ungewollt echtes Markup. Datum, Uhrzeit, Geldbeträge und Ja/Nein tragen
	 * ohnehin keine Sonderzeichen.
	 */
	private static function textfelder() {
		$keys = array();
		foreach ( array( BI_CPT, BI_ONLINE, BI_Reihen::CPT ) as $pt ) {
			foreach ( BI_CPT::meta_fields( $pt ) as $key => $cfg ) {
				if ( in_array( $cfg['type'], array( 'text', 'textarea', 'select' ), true ) ) {
					$keys[ $key ] = isset( $cfg['label'] ) ? $cfg['label'] : $key;
				}
			}
		}
		return $keys;
	}

	/**
	 * Stellen, an denen ein HTML-Code als Text in den Daten steht.
	 *
	 * Hintergrund: Die Exportdatei des Seminarverwaltungssystems maskiert
	 * Sonderzeichen („&#8211;" statt „–"). Bis Version 1.76.0 übernahm der Import
	 * das wörtlich, und weil Titel und Textfelder bei der Ausgabe durch
	 * esc_html() laufen, stand der Code danach sichtbar auf der Detailseite, in
	 * den Mails und im PDF. Der Import löst das inzwischen selbst auf
	 * (BI_Import::entfessle) – die vor dem Update eingelesenen Einträge tragen
	 * ihn aber weiter mit sich.
	 *
	 * Gesucht wird breit (alles mit „&") und erst danach entschieden: Ein Wert
	 * kommt nur in die Liste, wenn das Auflösen ihn wirklich verändert. Ein
	 * einzelnes „&" in „Arbeit & Gesundheit" bleibt damit unangetastet.
	 *
	 * @return array Liste aus art, id, feld, label, kontext, alt, neu.
	 */
	public static function textcodes() {
		global $wpdb;

		$like     = '%' . $wpdb->esc_like( '&' ) . '%';
		$treffer  = array();
		$post_typen = array_merge( bi_seminar_post_types(), array( BI_Reihen::CPT ) );
		$platz      = implode( ',', array_fill( 0, count( $post_typen ), '%s' ) );

		// ---- Titel ----
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_type FROM {$wpdb->posts}
			 WHERE post_type IN ($platz) AND post_status <> 'trash' AND post_title LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( $post_typen, array( $like ) )
		) );
		foreach ( (array) $rows as $r ) {
			$neu = BI_Import::entfessle( $r->post_title );
			if ( $neu === $r->post_title ) {
				continue;
			}
			$pt_obj    = get_post_type_object( $r->post_type );
			$treffer[] = array(
				'art'     => 'titel',
				'id'      => (int) $r->ID,
				'feld'    => '',
				'label'   => 'Titel',
				'kontext' => $pt_obj ? $pt_obj->labels->singular_name : $r->post_type,
				'alt'     => $r->post_title,
				'neu'     => $neu,
			);
		}

		// ---- Klartext-Meta ----
		$felder = self::textfelder();
		if ( $felder ) {
			$keys  = array_keys( $felder );
			$platz_k = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT m.post_id, m.meta_key, m.meta_value, p.post_title
				 FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key IN ($platz_k) AND m.meta_value LIKE %s
				   AND p.post_type IN ($platz) AND p.post_status <> 'trash'", // phpcs:ignore WordPress.DB.PreparedSQL
				array_merge( $keys, array( $like ), $post_typen )
			) );
			foreach ( (array) $rows as $r ) {
				$neu = BI_Import::entfessle( $r->meta_value );
				if ( $neu === $r->meta_value ) {
					continue;
				}
				$treffer[] = array(
					'art'     => 'meta',
					'id'      => (int) $r->post_id,
					'feld'    => $r->meta_key,
					'label'   => $felder[ $r->meta_key ],
					'kontext' => $r->post_title,
					'alt'     => $r->meta_value,
					'neu'     => $neu,
				);
			}
		}

		// ---- Begriffe ----
		$taxen   = self::tax_slugs();
		$platz_t = implode( ',', array_fill( 0, count( $taxen ), '%s' ) );
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.name, tt.taxonomy FROM {$wpdb->terms} t
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy IN ($platz_t) AND t.name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL
			array_merge( $taxen, array( $like ) )
		) );
		foreach ( (array) $rows as $r ) {
			$neu = BI_Import::entfessle( $r->name );
			if ( $neu === $r->name ) {
				continue;
			}
			$tax       = get_taxonomy( $r->taxonomy );
			$treffer[] = array(
				'art'     => 'begriff',
				'id'      => (int) $r->term_id,
				'feld'    => $r->taxonomy,
				'label'   => 'Begriff',
				'kontext' => $tax ? $tax->labels->singular_name : $r->taxonomy,
				'alt'     => $r->name,
				'neu'     => $neu,
			);
		}

		return $treffer;
	}

	/**
	 * Die gefundenen HTML-Codes in Klartext umschreiben.
	 *
	 * Wirkt auf alle Fundstellen, nicht auf die Arbeitsmenge: Ein zur Hälfte
	 * bereinigter Bestand wäre schlechter als ein durchgängig unbereinigter –
	 * man sähe der Liste nicht mehr an, was schon durch ist.
	 */
	public static function handle_textcodes_reparieren() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_textcodes_reparieren' );

		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$titel = 0;
		$meta  = 0;
		$term  = 0;

		foreach ( self::textcodes() as $t ) {
			if ( 'titel' === $t['art'] ) {
				$ok = wp_update_post( array( 'ID' => $t['id'], 'post_title' => $t['neu'] ), true );
				if ( ! is_wp_error( $ok ) ) {
					$titel++;
				}
			} elseif ( 'meta' === $t['art'] ) {
				update_post_meta( $t['id'], $t['feld'], $t['neu'] );
				$meta++;
			} else {
				$ok = wp_update_term( $t['id'], $t['feld'], array( 'name' => $t['neu'] ) );
				if ( ! is_wp_error( $ok ) ) {
					$term++;
				}
			}
		}

		$teile = array();
		if ( $titel ) {
			$teile[] = number_format_i18n( $titel ) . ' Titel';
		}
		if ( $meta ) {
			$teile[] = number_format_i18n( $meta ) . ( 1 === $meta ? ' Feld' : ' Felder' );
		}
		if ( $term ) {
			$teile[] = number_format_i18n( $term ) . ( 1 === $term ? ' Begriff' : ' Begriffe' );
		}


		$msg = $teile
			? 'HTML-Codes aufgelöst: ' . implode( ', ', $teile ) . '.'
			: 'Es gab nichts aufzulösen.';

		self::redirect( array(), $msg );
	}

	/**
	 * Fremde Orte unter „Andere" zusammenfassen.
	 *
	 * Der bisherige Begriff wird vorher als Seminarort gesichert, sofern dort
	 * noch nichts steht – die Angabe „Moxy Hotel Bochum" ist ja richtig, sie
	 * gehört nur nicht in den Filter-Chip der Bildungszentren.
	 *
	 * Anschließend werden die leergeräumten Begriffe gelöscht: Die Filterleiste
	 * lädt ihre Begriffe mit hide_empty=false, ein zurückgelassener Begriff
	 * stünde also weiter als Filter ohne Treffer in der Liste.
	 *
	 * Wirkt bewusst auf ALLE Seminare statt auf die Arbeitsmenge – eine halb
	 * aufgeräumte Taxonomie wäre unübersichtlicher als eine gar nicht aufgeräumte.
	 */
	public static function handle_orte_aufraeumen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_orte_aufraeumen' );

		@set_time_limit( 0 );

		$terms = self::fremde_orte();
		$ids   = array();
		foreach ( $terms as $term ) {
			$treffer = get_posts( array(
				'post_type'   => bi_seminar_post_types(),
				'post_status' => self::alle_status(),
				'numberposts' => -1,
				'fields'      => 'ids',
				'tax_query'   => array(
					array( 'taxonomy' => BI_TAX_ORT, 'field' => 'term_id', 'terms' => (int) $term->term_id ),
				),
			) );
			foreach ( $treffer as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$gesichert = 0;
		foreach ( array_keys( $ids ) as $id ) {
			$namen = wp_get_object_terms( $id, BI_TAX_ORT, array( 'fields' => 'names' ) );
			if ( is_wp_error( $namen ) || ! $namen ) {
				continue;
			}
			$neu = array();
			foreach ( $namen as $name ) {
				if ( BI_CPT::ist_bildungszentrum( $name ) ) {
					$neu[] = $name;
					continue;
				}
				$neu[] = BI_CPT::ANDERE;
				if ( '' === trim( (string) get_post_meta( $id, '_bi_seminarort', true ) ) ) {
					update_post_meta( $id, '_bi_seminarort', sanitize_text_field( $name ) );
					$gesichert++;
				}
			}
			wp_set_object_terms( $id, array_values( array_unique( $neu ) ), BI_TAX_ORT, false );
		}

		$geloescht = 0;
		foreach ( $terms as $term ) {
			$frisch = get_term( $term->term_id, BI_TAX_ORT );
			if ( $frisch && ! is_wp_error( $frisch ) && 0 === (int) $frisch->count ) {
				wp_delete_term( $term->term_id, BI_TAX_ORT );
				$geloescht++;
			}
		}

		self::redirect( array(), sprintf(
			'%s Seminar(e) unter „%s" zusammengefasst, %s Ortsangabe(n) als Seminarort gesichert, %s leere Begriffe entfernt.',
			number_format_i18n( count( $ids ) ),
			BI_CPT::ANDERE,
			number_format_i18n( $gesichert ),
			number_format_i18n( $geloescht )
		) );
	}

	/* ===================================================================
	 *  Oberfläche
	 * =================================================================== */

	private static function label_for( $pt ) {
		if ( 'beide' === $pt ) {
			return 'seminare';
		}
		return ( BI_ONLINE === $pt ) ? 'online-seminare' : 'praesenz-seminare';
	}

	/** Zurück auf die Datenpflege-Seite, Filter bleibt erhalten. */
	private static function redirect( $f, $msg = '' ) {
		$args = array( 'page' => self::PAGE );
		if ( isset( $f['pt'] ) ) {
			$args = array_merge( $args, self::filter_query_args( $f ) );
		} elseif ( isset( $f['paket'] ) ) {
			$args['paket'] = $f['paket'];
		}
		if ( $msg ) {
			$args['bi_msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Filter als Query-Argumente (für Links und Redirects). */
	private static function filter_query_args( $f ) {
		$args = array(
			'pt'       => $f['pt'],
			'status'   => $f['status'],
			's'        => $f['s'],
			'von'      => $f['von'],
			'bis'      => $f['bis'],
			'leer'     => $f['leer'],
			'feld'     => $f['feld'],
			'enthaelt' => $f['enthaelt'],
		);
		// Leere Werte weglassen, damit die Adresszeile nur die gesetzten Filter zeigt.
		$args = array_filter( $args, function ( $v ) {
			return '' !== (string) $v;
		} );

		// Erst danach die Taxonomien anhängen: Sie sind Arrays, und die Prüfung
		// oben würde bei einer Umwandlung nach String stolpern. Leere Einträge
		// gibt es hier ohnehin nicht – filter_from() legt nur gefüllte an.
		foreach ( $f['tax'] as $slug => $term_ids ) {
			$args[ 'tax_' . $slug ] = array_map( 'intval', (array) $term_ids );
		}
		return $args;
	}

	/** Filter als versteckte Felder – damit die Export-Formulare dieselbe Menge treffen. */
	private static function filter_hidden_fields( $f ) {
		foreach ( self::filter_query_args( $f ) as $key => $value ) {
			// Mehrfachauswahl: je Begriff ein eigenes Feld mit „[]" im Namen,
			// sonst käme beim Absenden nur der letzte Wert an.
			if ( is_array( $value ) ) {
				foreach ( $value as $einzel ) {
					printf( '<input type="hidden" name="%s[]" value="%s">', esc_attr( $key ), esc_attr( $einzel ) );
				}
				continue;
			}
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $key ), esc_attr( $value ) );
		}
	}

	/* ===================================================================
	 *  Reiter „Ausbildungsreihen"
	 * =================================================================== */

	/**
	 * Zwei Listen: die erkannten Reihen mit ihrem Zustand, und die Termine, deren
	 * Angabe „Teil | Reihe" nicht zur Zuordnung gereicht hat.
	 *
	 * Die zweite Liste ist die eigentlich wichtige: In den Quelldaten trägt der
	 * Großteil der Einträge nur „Teil 1: <Titel>" ohne den Reihennamen dahinter.
	 * Solche Termine bleiben einzelne Seminare, bis das Feld in der Quelldatei
	 * ergänzt ist – deshalb steht hier, welche es betrifft und was jeweils fehlt.
	 */
	private static function render_reihen_section() {
		$reihen = BI_Reihen::uebersicht();
		list( $offen, $offen_gesamt ) = BI_Reihen::pruefliste();
		?>
		<div class="card" style="max-width:100%;margin:0 0 20px">
			<h2 style="margin-top:0">So entsteht eine Reihe</h2>
			<p>Die Zuordnung kommt aus dem Feld <code>Teil | Reihe</code> am Seminar:</p>
			<p><code style="font-size:14px;padding:6px 10px;display:inline-block;background:#f0f0f1">Teil 2 | Ausbildungsreihe Aufgaben der VK-Leitung</code></p>
			<p>Läuft die Reihe in <strong>festen Gruppen</strong>, die alle Teile gemeinsam durchlaufen,
			   kommt die Gruppennummer davor – im Heft heißt sie „Termine Reihe 1":</p>
			<p><code style="font-size:14px;padding:6px 10px;display:inline-block;background:#f0f0f1">Reihe 1 - Teil 2 | Ausbildungsreihe Aufgaben der VK-Leitung</code></p>
			<ul style="list-style:disc;margin-left:20px">
				<li>Links steht <strong>nur</strong> <code>Teil &lt;Zahl&gt;</code>, davor optional
					<code>Reihe &lt;Zahl&gt; -</code> für die feste Gruppe – kein Titel, kein Beschreibungstext.</li>
				<li>Rechts der Reihenname, <strong>zeichengleich</strong> bei allen Terminen derselben Reihe.
					Eine abweichende Schreibweise erzeugt eine zweite Reihe.</li>
				<li>Taucht ein Reihenname zum ersten Mal auf, entsteht die Reihe als <strong>Entwurf</strong>.
					Sie wird erst öffentlich, wenn jemand den Einleitungstext geschrieben und sie
					veröffentlicht hat.</li>
			</ul>
			<p>Teil-Titel und „Themen im Seminar" holt die Reihenseite aus den Terminen selbst –
			   sie müssen kein zweites Mal gepflegt werden.</p>
		</div>

		<div class="card" style="max-width:100%;margin:0 0 20px">
			<h2 style="margin-top:0">Erkannte Reihen (<?php echo esc_html( number_format_i18n( count( $reihen ) ) ); ?>)</h2>
			<?php if ( $reihen ) : ?>
				<table class="widefat striped">
					<thead><tr><th>Reihe</th><th style="width:90px">Teile</th><th style="width:110px">Gruppen</th><th style="width:100px">Termine</th><th style="width:130px">Einleitung</th><th style="width:110px">Status</th></tr></thead>
					<tbody>
					<?php foreach ( $reihen as $r ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $r['post']->ID ) ); ?>"><?php echo esc_html( $r['post']->post_title ); ?></a></td>
							<td><?php echo esc_html( number_format_i18n( $r['teile'] ) ); ?></td>
							<td><?php echo $r['durchgaenge']
								? esc_html( number_format_i18n( $r['durchgaenge'] ) . ' feste' )
								: '<span style="color:#646970">einzeln buchbar</span>'; ?></td>
							<td><?php echo esc_html( number_format_i18n( $r['termine'] ) ); ?></td>
							<td><?php echo $r['text']
								? '<span style="color:#008a20">vorhanden</span>'
								: '<strong style="color:#b32d2e">fehlt</strong>'; ?></td>
							<td><?php echo esc_html( 'publish' === $r['post']->post_status ? 'veröffentlicht' : 'Entwurf' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p style="color:#646970">Noch keine Reihe erkannt. Sie entstehen beim nächsten Import, sobald
				   Termine ein gefülltes Feld <code>Teil | Reihe</code> mitbringen.</p>
			<?php endif; ?>
		</div>

		<div class="card" style="max-width:100%">
			<h2 style="margin-top:0">Nicht zugeordnet (<?php echo esc_html( number_format_i18n( $offen_gesamt ) ); ?>)</h2>
			<?php if ( $offen ) : ?>
				<p>Diese Termine tragen eine Teil-Angabe, die nicht zur Zuordnung reicht. Zu ergänzen ist das
				   in der <strong>Quelldatei</strong> – beim nächsten Import greift die Zuordnung dann von selbst.</p>
				<table class="widefat striped">
					<thead><tr><th style="width:28%">Seminar</th><th style="width:110px">Nummer</th><th>Inhalt des Feldes</th><th style="width:26%">Was fehlt</th></tr></thead>
					<tbody>
					<?php foreach ( $offen as $z ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $z['post']->ID ) ); ?>"><?php echo esc_html( $z['post']->post_title ); ?></a></td>
							<td><?php echo esc_html( get_post_meta( $z['post']->ID, '_bi_seminarnummer', true ) ?: '—' ); ?></td>
							<td><code><?php echo esc_html( mb_strimwidth( $z['roh'], 0, 90, '…' ) ); ?></code></td>
							<td><?php echo esc_html( $z['grund'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $offen_gesamt > count( $offen ) ) : ?>
					<p class="description" style="margin-top:10px">Gezeigt werden die ersten
					   <?php echo esc_html( number_format_i18n( count( $offen ) ) ); ?> von
					   <?php echo esc_html( number_format_i18n( $offen_gesamt ) ); ?> Fällen.</p>
				<?php endif; ?>
			<?php else : ?>
				<p style="color:#646970">Kein offener Fall – jede Teil-Angabe konnte einer Reihe zugeordnet werden.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Der Reiter „Begriffe": eine Taxonomie, drei Handgriffe. */
	private static function render_begriffe_section() {
		$tax   = self::begriffe_tax();
		$taxes = BI_CPT::taxonomies();
		$cfg   = $taxes[ $tax ];
		$base  = admin_url( 'admin.php?page=' . self::PAGE . '&tab=begriffe' );

		$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name' ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;

		$praesenz = BI_CPT::term_counts( $tax, BI_CPT );
		$online   = BI_CPT::term_counts( $tax, BI_ONLINE );
		$unbenutzt = 0;
		foreach ( $terms as $t ) {
			if ( 0 === (int) $t->count ) {
				$unbenutzt++;
			}
		}
		?>
		<h2 class="nav-tab-wrapper" style="margin-bottom:16px">
			<?php foreach ( $taxes as $slug => $t_cfg ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tax', $slug, $base ) ); ?>"
				   class="nav-tab<?php echo $tax === $slug ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $t_cfg['label'] ); ?></a>
			<?php endforeach; ?>
		</h2>

		<div class="card" style="max-width:100%;margin:0 0 20px">
			<h2 style="margin-top:0"><?php echo esc_html( $cfg['label'] ); ?>
				<span style="font-weight:400;color:#646970">· <?php echo esc_html( number_format_i18n( count( $terms ) ) ); ?> Begriffe</span></h2>
			<p>Umbenennen ändert nur die Anzeige – keine Zuordnung wird berührt.
			   Zusammenführen hängt die Seminare des Begriffs auf das Ziel um und entfernt ihn danach;
			   das lässt sich <strong>nicht rückgängig machen</strong>, deshalb steht überall
			   <em>„nicht zusammenführen"</em> voreingestellt.
			   <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . $tax . '&post_type=' . BI_CPT ) ); ?>">Vollständiger
			   Begriffs-Editor von WordPress</a> (Titelform, Beschreibung<?php echo ! empty( $cfg['has_email'] ) ? ', E-Mail-Adresse' : ''; ?>).</p>

			<?php if ( ! $terms ) : ?>
				<p style="color:#646970">Noch keine Begriffe vorhanden.</p>
			<?php else : ?>
				<?php // Ein Formular, zwei Ziele: Die Knöpfe wählen über formaction, welche
				      // Aktion greift. Die Rückfrage hängt deshalb am Knopf, nicht am Formular. ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="tax" value="<?php echo esc_attr( $tax ); ?>">
					<?php wp_nonce_field( 'bi_begriffe' ); ?>

					<table class="widefat striped">
						<thead><tr>
							<th style="width:38%">Begriff</th>
							<th style="width:90px">Präsenz</th>
							<th style="width:90px">Online</th>
							<th>Zusammenführen mit</th>
						</tr></thead>
						<tbody>
						<?php foreach ( $terms as $t ) : ?>
							<?php
							$p_n = isset( $praesenz[ (int) $t->term_id ] ) ? (int) $praesenz[ (int) $t->term_id ] : 0;
							$o_n = isset( $online[ (int) $t->term_id ] ) ? (int) $online[ (int) $t->term_id ] : 0;
							?>
							<tr>
								<td>
									<input type="text" name="name[<?php echo (int) $t->term_id; ?>]"
									       value="<?php echo esc_attr( $t->name ); ?>" style="width:100%">
								</td>
								<td><?php echo esc_html( number_format_i18n( $p_n ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $o_n ) ); ?></td>
								<td>
									<select name="ziel[<?php echo (int) $t->term_id; ?>]">
										<option value="">— nicht zusammenführen —</option>
										<?php foreach ( $terms as $ziel ) : ?>
											<?php if ( (int) $ziel->term_id === (int) $t->term_id ) { continue; } ?>
											<option value="<?php echo (int) $ziel->term_id; ?>"><?php echo esc_html( $ziel->name ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<p style="margin-top:14px">
						<button type="submit" class="button button-primary"
						        formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=bi_begriffe_umbenennen' ) ); ?>">
							Namen speichern
						</button>
						<button type="submit" class="button"
						        formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=bi_begriffe_zusammenfuehren' ) ); ?>"
						        onclick="return confirm('Ausgewählte Begriffe endgültig zusammenführen?');">
							Ausgewählte zusammenführen
						</button>
					</p>
				</form>
			<?php endif; ?>
		</div>

		<?php if ( $unbenutzt ) : ?>
			<div class="card" style="max-width:100%">
				<h2 style="margin-top:0">Unbenutzte Begriffe</h2>
				<p><strong><?php echo esc_html( number_format_i18n( $unbenutzt ) ); ?></strong>
				   Begriffe dieser Taxonomie sind keinem Seminar zugeordnet. Sie stehen trotzdem in der
				   Filterleiste des Frontends – die lädt ihre Begriffe bewusst vollständig, damit ein Filter
				   nicht verschwindet, nur weil gerade kein Termin ansteht.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				      onsubmit="return confirm('<?php echo esc_attr( $unbenutzt ); ?> unbenutzte Begriffe entfernen?');">
					<input type="hidden" name="action" value="bi_begriffe_leeren">
					<input type="hidden" name="tax" value="<?php echo esc_attr( $tax ); ?>">
					<?php wp_nonce_field( 'bi_begriffe' ); ?>
					<?php submit_button( 'Unbenutzte Begriffe entfernen', 'secondary', '', false ); ?>
				</form>
			</div>
		<?php endif; ?>
		<?php
	}

	/** Reiter-Navigation der Datenpflege. */
	private static function render_tabs( $tabs, $aktiv, $base ) {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( add_query_arg( 'tab', $key, $base ) ),
				$aktiv === $key ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';
	}

	public static function render_page() {
		$notice = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'auswahl';
		$tabs   = array(
			'auswahl' => 'Auswahl & Export',
			'begriffe' => 'Begriffe',
			'felder'   => 'Felder',
			'reihen'   => 'Ausbildungsreihen',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'auswahl';
		}
		$base = admin_url( 'admin.php?page=' . self::PAGE );

		if ( in_array( $tab, array( 'felder', 'reihen', 'begriffe' ), true ) ) {
			echo '<div class="wrap"><h1>Datenpflege</h1>';
			if ( $notice ) {
				echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>';
			}
			self::render_tabs( $tabs, $tab, $base );
			if ( 'felder' === $tab ) {
				BI_Felder::render_section();
			} elseif ( 'begriffe' === $tab ) {
				self::render_begriffe_section();
			} else {
				self::render_reihen_section();
			}
			echo '</div>';
			return;
		}

		$f     = self::filter_from( $_GET );
		$token = isset( $_GET['paket'] ) ? sanitize_file_name( wp_unslash( $_GET['paket'] ) ) : '';

		$q       = self::query( $f, self::VORSCHAU );
		$treffer = (int) $q->found_posts;
		$fields  = self::meta_all( $f['pt'] );
		?>
		<div class="wrap">
			<h1>Datenpflege</h1>

			<?php if ( $notice ) : ?>
				<div class="notice notice-info"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php self::render_tabs( $tabs, $tab, $base ); ?>

			<p>Erst die <strong>Arbeitsmenge</strong> festlegen, dann exportieren. Jede Aktion auf dieser Seite
			   wirkt auf genau die unten angezeigte Auswahl.</p>

			<?php // ---------- Filter ---------- ?>
			<div class="card" style="max-width:100%;margin:0 0 20px">
				<h2 style="margin-top:0">Arbeitsmenge</h2>
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" id="bi-dp-filter">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
					<table class="form-table">
						<tr>
							<th><label for="bi_dp_pt">Seminarform</label></th>
							<td>
								<select name="pt" id="bi_dp_pt">
									<option value="<?php echo esc_attr( BI_CPT ); ?>" <?php selected( $f['pt'], BI_CPT ); ?>>Präsenz-Seminare</option>
									<option value="<?php echo esc_attr( BI_ONLINE ); ?>" <?php selected( $f['pt'], BI_ONLINE ); ?>>Online-Seminare</option>
									<option value="beide" <?php selected( $f['pt'], 'beide' ); ?>>beide</option>
								</select>
								<select name="status">
									<option value="alle" <?php selected( $f['status'], 'alle' ); ?>>alle Status</option>
									<option value="publish" <?php selected( $f['status'], 'publish' ); ?>>nur veröffentlicht</option>
									<option value="draft" <?php selected( $f['status'], 'draft' ); ?>>nur Entwürfe</option>
								</select>
								<p class="description">Der CSV-Export braucht eine einzelne Seminarform, das JSON-Paket kann beide.</p>
							</td>
						</tr>
						<tr>
							<th><label for="bi_dp_s">Suche</label></th>
							<td><input type="search" name="s" id="bi_dp_s" value="<?php echo esc_attr( $f['s'] ); ?>" class="regular-text"
							           placeholder="Titel oder Seminarnummer"></td>
						</tr>
						<tr>
							<th>Startdatum</th>
							<td>
								von <input type="date" name="von" value="<?php echo esc_attr( $f['von'] ); ?>">
								bis <input type="date" name="bis" value="<?php echo esc_attr( $f['bis'] ); ?>">
							</td>
						</tr>
						<?php foreach ( BI_CPT::taxonomies( 'beide' === $f['pt'] ? BI_CPT : $f['pt'] ) as $slug => $cfg ) : ?>
							<?php
							// Von Hand statt wp_dropdown_categories(): Das kann keine
							// Mehrfachauswahl. Gewählt sind die Begriffe aus dem Filter.
							$terms   = get_terms( array( 'taxonomy' => $slug, 'hide_empty' => false, 'orderby' => 'name' ) );
							$terms   = is_wp_error( $terms ) ? array() : $terms;
							$gewaehlt = array_map( 'intval', (array) ( $f['tax'][ $slug ] ?? array() ) );
							// Sichtbare Zeilen: so hoch wie nötig, aber nie höher als acht –
							// bei über hundert Zielgruppen wäre die Seite sonst nicht mehr lesbar.
							$zeilen  = max( 3, min( 8, count( $terms ) ) );
							?>
							<tr>
								<th><label for="bi_dp_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $cfg['single'] ); ?></label></th>
								<td>
									<?php if ( ! $terms ) : ?>
										<em>Keine Begriffe vorhanden.</em>
									<?php else : ?>
										<select name="tax_<?php echo esc_attr( $slug ); ?>[]"
										        id="bi_dp_<?php echo esc_attr( $slug ); ?>"
										        multiple size="<?php echo (int) $zeilen; ?>"
										        style="min-width:280px;max-width:100%">
											<?php foreach ( $terms as $t ) : ?>
												<option value="<?php echo (int) $t->term_id; ?>"
													<?php selected( in_array( (int) $t->term_id, $gewaehlt, true ) ); ?>>
													<?php echo esc_html( $t->name ); ?>
												</option>
											<?php endforeach; ?>
										</select>
										<?php if ( $gewaehlt ) : ?>
											<p class="description" style="margin:4px 0 0">
												<?php echo esc_html( sprintf(
													1 === count( $gewaehlt ) ? '%s Begriff gewählt' : '%s Begriffe gewählt (ODER-verknüpft)',
													number_format_i18n( count( $gewaehlt ) )
												) ); ?>
											</p>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th></th>
							<td>
								<p class="description" style="margin:0">
									Nichts markiert heißt <em>alle</em>. Mehrere Begriffe mit gedrückter
									<kbd>Strg</kbd>- bzw. <kbd>Cmd</kbd>-Taste markieren: Innerhalb eines Feldes
									gilt dann <strong>oder</strong> (Programm 2027 <em>oder</em> 2028), zwischen
									den Feldern <strong>und</strong>.
								</p>
							</td>
						</tr>
						<tr>
							<th><label for="bi_dp_leer">Feld ist leer</label></th>
							<td>
								<select name="leer" id="bi_dp_leer">
									<option value="">— egal —</option>
									<?php foreach ( $fields as $key => $cfg ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['leer'], $key ); ?>><?php echo esc_html( $cfg['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description">Findet Lücken – z.&nbsp;B. alle Seminare ohne Ansprechpartner-E-Mail.</p>
							</td>
						</tr>
						<tr>
							<th><label for="bi_dp_feld">Feld enthält</label></th>
							<td>
								<select name="feld" id="bi_dp_feld">
									<option value="">— egal —</option>
									<?php foreach ( $fields as $key => $cfg ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $f['feld'], $key ); ?>><?php echo esc_html( $cfg['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<input type="text" name="enthaelt" value="<?php echo esc_attr( $f['enthaelt'] ); ?>" class="regular-text" placeholder="Suchtext">
							</td>
						</tr>
					</table>
					<p class="submit" style="margin:0">
						<?php // Ohne Namen, damit der Knopf nicht selbst in der Adresszeile landet.
						submit_button( 'Auswahl übernehmen', 'primary', '', false ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="button">Filter zurücksetzen</a>
					</p>
				</form>
			</div>

			<?php // ---------- Treffer + Vorschau ---------- ?>
			<div class="card" style="max-width:100%;margin:0 0 20px">
				<h2 style="margin-top:0">
					<?php echo esc_html( number_format_i18n( $treffer ) ); ?>
					<?php echo esc_html( 1 === $treffer ? 'Eintrag in der Arbeitsmenge' : 'Einträge in der Arbeitsmenge' ); ?>
				</h2>

				<?php /* Wird sichtbar, sobald oben ein Filter verändert, aber nicht übernommen wurde.
				         Ohne diesen Hinweis gilt die Zahl weiter für die alte Auswahl – und genau
				         darauf würde sich auch das Löschen beziehen. */ ?>
				<div id="bi-dp-veraltet" style="display:none;background:#fcf9e8;border-left:4px solid #dba617;padding:10px 14px;margin:0 0 12px">
					<strong>Die Filter oben wurden geändert, aber noch nicht übernommen.</strong>
					Diese Zahl und der Export gelten noch für die vorige Auswahl.
					Bitte <em>„Auswahl übernehmen"</em> klicken.
				</div>
				<?php if ( $q->have_posts() ) : ?>
					<table class="widefat striped">
						<thead><tr><th>Titel</th><th style="width:130px">Nummer</th><th style="width:110px">Start</th><th style="width:180px">Ort / Veranstalter*in</th><th style="width:100px">Status</th></tr></thead>
						<tbody>
						<?php foreach ( $q->posts as $post ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a></td>
								<td><?php echo esc_html( get_post_meta( $post->ID, '_bi_seminarnummer', true ) ?: '—' ); ?></td>
								<td><?php
									$d = get_post_meta( $post->ID, '_bi_startdatum', true );
									echo esc_html( $d ? date_i18n( 'd.m.Y', strtotime( $d ) ) : '—' );
								?></td>
								<td><?php echo esc_html( self::term_text( $post->ID, BI_TAX_ORT ) ?: '—' ); ?></td>
								<td><?php echo esc_html( $post->post_status ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $treffer > self::VORSCHAU ) : ?>
						<p class="description" style="margin:10px 0 0">Vorschau der ersten <?php echo (int) self::VORSCHAU; ?> Einträge – exportiert werden alle <?php echo esc_html( number_format_i18n( $treffer ) ); ?>.</p>
					<?php endif; ?>
				<?php else : ?>
					<p style="color:#646970">Kein Eintrag passt auf diese Auswahl.</p>
				<?php endif; ?>
			</div>

			<?php // ---------- Export ---------- ?>
			<div class="card" style="max-width:100%;margin:0 0 20px">
				<h2 style="margin-top:0">Export</h2>
				<div style="display:flex;gap:24px;flex-wrap:wrap">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:1;min-width:320px">
						<input type="hidden" name="action" value="bi_export_csv">
						<?php wp_nonce_field( 'bi_export' ); ?>
						<?php self::filter_hidden_fields( $f ); ?>
						<h3 style="margin-top:0">CSV</h3>
						<p>Eine Zeile je Eintrag, Semikolon als Trennzeichen. Die Kopfzeile entspricht den Feldnamen des
						   Seminar-Imports – in der Zielinstallation ist die Spaltenzuordnung deshalb schon ausgefüllt.
						   Mehrfachwerte stehen mit <code>|</code> getrennt in einer Zelle.</p>
						<?php submit_button( 'CSV herunterladen', 'primary', 'submit', false, $treffer ? array() : array( 'disabled' => 'disabled' ) ); ?>
						<?php if ( 'beide' === $f['pt'] ) : ?>
							<p class="description" style="color:#b32d2e">Bitte oben eine einzelne Seminarform wählen.</p>
						<?php endif; ?>
						<?php $kritisch = self::kritische_begriffe( $f['pt'] ); ?>
						<?php if ( $kritisch ) : ?>
							<div style="background:#fcf9e8;border-left:4px solid #dba617;padding:10px 14px;margin-top:12px">
								<strong>Achtung beim Reimport:</strong>
								<?php echo esc_html( 1 === count( $kritisch ) ? 'Ein Begriff enthält' : count( $kritisch ) . ' Begriffe enthalten' ); ?>
								ein Komma mit Leerzeichen und würde(n) beim Einlesen in mehrere Begriffe zerfallen:
								<em><?php echo esc_html( implode( ' · ', array_slice( $kritisch, 0, 6 ) ) ); ?><?php echo count( $kritisch ) > 6 ? ' …' : ''; ?></em>.
								Für diese Daten das <strong>JSON-Paket</strong> nehmen – dort steht jeder Begriff einzeln.
							</div>
						<?php endif; ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="flex:1;min-width:320px">
						<input type="hidden" name="action" value="bi_export_json">
						<?php wp_nonce_field( 'bi_export' ); ?>
						<?php self::filter_hidden_fields( $f ); ?>
						<h3 style="margin-top:0">JSON-Paket</h3>
						<p>Für den Umzug in eine andere Installation dieses Plugins: alle Felder, die verwendeten
						   Begriffe samt E-Mail-Adresse der Bildungszentren und die Adresse des Beitragsbilds.
						   Präsenz- und Online-Seminare dürfen gemeinsam im Paket liegen.</p>
						<p><label><input type="checkbox" name="mit_reihen" value="1" checked>
							<strong>Ausbildungsreihen mitnehmen</strong></label></p>
						<p class="description" style="margin-top:-6px">Mitgenommen werden die Reihen, zu denen die
						   Termine dieser Auswahl gehören – mit Einleitungstext, Auszug, Bild und allen Feldern.
						   Ohne Haken enthält das Paket nur die Seminare; ihre Zuordnung bleibt zwar erhalten, in
						   der Zielinstallation entsteht daraus aber eine <em>leere Reihe im Entwurfsstatus</em>,
						   die von Hand getextet werden muss.</p>
						<?php submit_button( 'Paket herunterladen', 'secondary', 'submit', false, $treffer ? array() : array( 'disabled' => 'disabled' ) ); ?>
					</form>
				</div>
			</div>

			<?php // ---------- HTML-Codes in Klartextfeldern ---------- ?>
			<?php $codes = self::textcodes(); ?>
			<?php if ( $codes ) : ?>
				<div class="card" style="max-width:100%;margin:0 0 20px;border-left:4px solid #dba617">
					<h2 style="margin-top:0">HTML-Codes in Titeln und Textfeldern</h2>
					<p>An <strong><?php echo esc_html( number_format_i18n( count( $codes ) ) ); ?></strong>
					   Stellen steht ein HTML-Code als Text in den Daten – etwa
					   <code>&amp;#8211;</code> statt eines Gedankenstrichs. So wurde er aus der
					   Exportdatei übernommen, und weil Titel und Textfelder bei der Ausgabe maskiert
					   werden, steht der Code sichtbar auf der Detailseite, in den Mails und im PDF.</p>
					<p>Der Import löst das seit Version 1.77.0 selbst auf. Diese Schaltfläche holt es für
					   die Einträge nach, die vorher eingelesen wurden.</p>

					<table class="widefat striped" style="margin:0 0 12px">
						<thead>
							<tr>
								<th style="width:22%">Wo</th>
								<th style="width:39%">Vorher</th>
								<th style="width:39%">Nachher</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( array_slice( $codes, 0, self::VORSCHAU ) as $t ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $t['label'] ); ?></strong><br>
									<span class="description"><?php echo esc_html( $t['kontext'] ); ?></span>
								</td>
								<td><code><?php echo esc_html( $t['alt'] ); ?></code></td>
								<td><?php echo esc_html( $t['neu'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $codes ) > self::VORSCHAU ) : ?>
						<p class="description">… und <?php echo esc_html( number_format_i18n( count( $codes ) - self::VORSCHAU ) ); ?>
						   weitere Stellen.</p>
					<?php endif; ?>

					<p class="description"><strong>Nicht angefasst werden HTML-Felder</strong> (Beschreibung,
					   „Themen im Seminar"): Dort ist die Maskierung richtig – der Browser zeigt sie ohnehin
					   als Sonderzeichen. Ein einzelnes „&amp;" wie in „Arbeit &amp; Gesundheit" bleibt
					   ebenfalls stehen.</p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					      onsubmit="return confirm('<?php echo esc_attr( number_format_i18n( count( $codes ) ) ); ?> Stellen umschreiben?');">
						<input type="hidden" name="action" value="bi_textcodes_reparieren">
						<?php wp_nonce_field( 'bi_textcodes_reparieren' ); ?>
						<?php submit_button( 'HTML-Codes auflösen', 'primary', '', false ); ?>
						<span class="description" style="margin-left:10px">Wirkt auf alle Einträge, unabhängig von der Auswahl oben.</span>
					</form>
				</div>
			<?php endif; ?>

			<?php // ---------- Fehlende Bildungszentren nachtragen ---------- ?>
			<?php
			$offen         = self::fehlende_zentren();
			$offen_zentren = 0;
			foreach ( $offen['zentren'] as $ids ) {
				$offen_zentren += count( $ids );
			}
			$offen_andere = 0;
			foreach ( $offen['andere'] as $ids ) {
				$offen_andere += count( $ids );
			}
			?>
			<?php if ( $offen_zentren || $offen_andere ) : ?>
				<div class="card" style="max-width:100%;margin:0 0 20px;border-left:4px solid #dba617">
					<h2 style="margin-top:0">Fehlende Bildungszentren nachtragen</h2>
					<p><strong><?php echo esc_html( number_format_i18n( $offen_zentren + $offen_andere ) ); ?></strong>
					   Präsenz-Seminare haben kein zuständiges Bildungszentrum, obwohl ihr <em>Seminarort</em>
					   ausgefüllt ist. Sie fehlen damit im Filter und beim Mail-Trigger „Bildungszentrum".</p>
					<p>Das passiert, wenn eine Importdatei aus der Zeit vor 2027 nach der Umstellung erneut
					   eingelesen wurde: Damals bedeutete die Spalte <em>Seminarort</em> das Bildungszentrum,
					   seither den tatsächlichen Veranstaltungsort.</p>

					<?php if ( $offen['zentren'] ) : ?>
						<p style="margin:0 0 6px"><strong>Wird zugeordnet:</strong></p>
						<p style="margin:0 0 12px">
							<?php foreach ( $offen['zentren'] as $kanonisch => $ids ) : ?>
								<span style="display:inline-block;background:#f0f0f1;border-radius:12px;padding:2px 10px;margin:0 4px 4px 0">
									<?php echo esc_html( $kanonisch ); ?>
									<span style="color:#646970">· <?php echo esc_html( number_format_i18n( count( $ids ) ) ); ?></span>
								</span>
							<?php endforeach; ?>
						</p>
						<p>Der Name wandert dabei in die Taxonomie und wird im Feld <em>Seminarort</em> geleert –
						   dort stünde sonst dieselbe Angabe ein zweites Mal.</p>
					<?php endif; ?>

					<?php if ( $offen['andere'] ) : ?>
						<p style="margin:0 0 6px"><strong>Kein Bildungszentrum – wird unter „<?php echo esc_html( BI_CPT::ANDERE ); ?>"
						   geführt, der Seminarort bleibt stehen:</strong></p>
						<p style="margin:0 0 12px">
							<?php foreach ( array_slice( $offen['andere'], 0, 8, true ) as $name => $ids ) : ?>
								<span style="display:inline-block;background:#f0f0f1;border-radius:12px;padding:2px 10px;margin:0 4px 4px 0">
									<?php echo esc_html( $name ); ?>
									<span style="color:#646970">· <?php echo esc_html( number_format_i18n( count( $ids ) ) ); ?></span>
								</span>
							<?php endforeach; ?>
							<?php echo count( $offen['andere'] ) > 8 ? '…' : ''; ?>
						</p>
					<?php endif; ?>

					<?php if ( $offen['leer'] ) : ?>
						<p class="description"><?php echo esc_html( number_format_i18n( $offen['leer'] ) ); ?>
						   weitere Seminare haben weder Bildungszentrum noch Seminarort – dort fehlt die Angabe
						   schlicht, die lassen sich hier nicht nachtragen.</p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					      onsubmit="return confirm('<?php echo esc_attr( number_format_i18n( $offen_zentren + $offen_andere ) ); ?> Seminare zuordnen?');">
						<input type="hidden" name="action" value="bi_zentren_nachtragen">
						<?php wp_nonce_field( 'bi_zentren_nachtragen' ); ?>
						<?php submit_button( 'Bildungszentren nachtragen', 'primary', '', false ); ?>
						<span class="description" style="margin-left:10px">Wirkt auf alle Seminare, unabhängig von der Auswahl oben.</span>
					</form>
				</div>
			<?php endif; ?>

			<?php // ---------- Orte aufräumen ---------- ?>
			<?php $fremde = self::fremde_orte(); ?>
			<?php if ( $fremde ) : ?>
				<div class="card" style="max-width:100%;margin:0 0 20px">
					<h2 style="margin-top:0">Orte aufräumen</h2>
					<p>Im Filter <em>Bildungszentrum</em> stehen <?php echo esc_html( number_format_i18n( count( $fremde ) ) ); ?>
					   Einträge, die keine Bildungszentren sind – Hotels, Tagungshäuser und Ähnliches aus den Jahrgängen,
					   in denen es nur eine gemeinsame Ortsangabe gab:</p>
					<p style="margin:0 0 12px">
						<?php foreach ( $fremde as $t ) : ?>
							<span style="display:inline-block;background:#f0f0f1;border-radius:12px;padding:2px 10px;margin:0 4px 4px 0">
								<?php echo esc_html( $t->name ); ?>
								<span style="color:#646970">· <?php echo esc_html( number_format_i18n( $t->count ) ); ?></span>
							</span>
						<?php endforeach; ?>
					</p>
					<p>Beim Aufräumen wandern diese Seminare im Filter unter <strong><?php echo esc_html( BI_CPT::ANDERE ); ?></strong>.
					   Der bisherige Name geht nicht verloren: Er wird als <em>Seminarort</em> gesichert, sofern dort noch nichts
					   steht, und erscheint damit weiterhin auf der Detailseite. Die leergeräumten Begriffe werden entfernt,
					   sonst blieben sie als Filter ohne Treffer stehen.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					      onsubmit="return confirm('<?php echo esc_attr( count( $fremde ) ); ?> Ortsangaben unter „<?php echo esc_attr( BI_CPT::ANDERE ); ?>“ zusammenfassen?');">
						<input type="hidden" name="action" value="bi_orte_aufraeumen">
						<?php wp_nonce_field( 'bi_orte_aufraeumen' ); ?>
						<?php submit_button( 'Orte zusammenfassen', 'secondary', '', false ); ?>
						<span class="description" style="margin-left:10px">Wirkt auf alle Seminare, unabhängig von der Auswahl oben.</span>
					</form>
				</div>
			<?php endif; ?>

			<?php // Geänderte, aber noch nicht übernommene Filter sichtbar machen –
			      // sonst bezöge sich ein Export auf eine andere Menge als die,
			      // die in den Auswahlfeldern steht. ?>
			<script>
			( function () {
				var filter  = document.getElementById( 'bi-dp-filter' );
				var hinweis = document.getElementById( 'bi-dp-veraltet' );
				if ( ! filter || ! hinweis ) { return; }

				function veraltet() { hinweis.style.display = 'block'; }
				filter.addEventListener( 'change', veraltet );
				filter.addEventListener( 'input', veraltet );
			} )();
			</script>

			<?php // ---------- Paket einlesen ---------- ?>
			<div class="card" style="max-width:100%">
				<h2 style="margin-top:0">Paket einlesen</h2>
				<?php
				$paket = $token ? self::paket_lesen( self::dir() . '/' . $token ) : null;
				if ( $paket ) :
					$anzahl = count( $paket['seminare'] );
					$formen = array();
					foreach ( $paket['seminare'] as $e ) {
						$pt            = $e['post_type'] ?? BI_CPT;
						$formen[ $pt ] = ( $formen[ $pt ] ?? 0 ) + 1;
					}
					$term_anzahl = 0;
					foreach ( (array) ( $paket['terms'] ?? array() ) as $liste ) {
						$term_anzahl += count( (array) $liste );
					}
					?>
					<table class="widefat striped" style="max-width:720px;margin-bottom:14px">
						<tbody>
							<tr><td style="width:200px">Quelle</td><td><?php echo esc_html( (string) ( $paket['quelle']['site'] ?? '—' ) ); ?>
								(Plugin <?php echo esc_html( (string) ( $paket['quelle']['plugin'] ?? '?' ) ); ?>)</td></tr>
							<tr><td>Erzeugt</td><td><?php echo esc_html( (string) ( $paket['erzeugt'] ?? '—' ) ); ?></td></tr>
							<tr><td>Enthält</td><td>
								<?php echo esc_html( number_format_i18n( $anzahl ) ); ?> Einträge
								<?php
								$teile = array();
								foreach ( $formen as $pt => $n ) {
									$teile[] = number_format_i18n( $n ) . '&nbsp;×&nbsp;' . ( BI_ONLINE === $pt ? 'Online' : 'Präsenz' );
								}
								echo $teile ? ' (' . wp_kses_post( implode( ', ', $teile ) ) . ')' : '';
								?>
								· <?php echo esc_html( number_format_i18n( $term_anzahl ) ); ?> Begriffe
								<?php $reihen_im_paket = count( (array) ( $paket['reihen'] ?? array() ) ); ?>
								· <?php echo esc_html( number_format_i18n( $reihen_im_paket ) ); ?> Ausbildungsreihen
							</td></tr>
						</tbody>
					</table>

					<?php
					// Ein Paket enthält nur die Seminarform, die beim Export
					// ausgewählt war. Die andere bleibt hier unangetastet – mit
					// ihren alten Begriffen. Das erklärt den häufigsten Irrtum:
					// „Ich habe importiert und habe immer noch alle alten Begriffe."
					$fehlend = array();
					foreach ( bi_seminar_post_types() as $pt ) {
						if ( isset( $formen[ $pt ] ) ) {
							continue;
						}
						$vorhanden = (int) ( wp_count_posts( $pt )->publish ?? 0 );
						if ( $vorhanden > 0 ) {
							$fehlend[] = sprintf(
								'%s (%s hier vorhanden)',
								BI_ONLINE === $pt ? 'Online-Seminare' : 'Präsenz-Seminare',
								number_format_i18n( $vorhanden )
							);
						}
					}
					if ( $fehlend ) : ?>
						<div class="notice notice-warning inline" style="margin:0 0 14px">
							<p><strong>Dieses Paket enthält keine <?php echo esc_html( implode( ', ', $fehlend ) ); ?>.</strong>
							   Diese Einträge bleiben unverändert – mit ihren bisherigen Begriffen. Wer den ganzen
							   Bestand übertragen will, exportiert in der Quelle beide Seminarformen und liest beide
							   Pakete ein.</p>
						</div>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="bi_paket_run">
						<input type="hidden" name="paket" value="<?php echo esc_attr( $token ); ?>">
						<?php wp_nonce_field( 'bi_paket_run' ); ?>
						<table class="form-table">
							<tr>
								<th>Status der Einträge</th>
								<td>
									<select name="post_status">
										<option value="original">wie im Paket</option>
										<option value="draft">alle als Entwurf</option>
										<option value="publish">alle veröffentlichen</option>
									</select>
								</td>
							</tr>
							<tr>
								<th>Duplikate</th>
								<td><label><input type="checkbox" name="dedupe" value="1" checked>
									vorhandene Einträge mit gleicher Seminarnummer aktualisieren statt doppelt anlegen</label></td>
							</tr>
							<tr>
								<th>Beitragsbilder</th>
								<td><label><input type="checkbox" name="bilder" value="1">
									Bilder von der Quellseite in die Mediathek holen</label>
									<p class="description">Nur sinnvoll, wenn die Quellseite von hier aus erreichbar ist. Vorhandene Beitragsbilder werden nicht überschrieben.</p></td>
							</tr>
							<?php if ( $reihen_im_paket ) : ?>
							<tr>
								<th>Ausbildungsreihen</th>
								<td><?php echo esc_html( number_format_i18n( $reihen_im_paket ) ); ?> Reihen werden angelegt
									oder <strong>überschrieben</strong>.
									<p class="description">Wiedererkannt am <strong>Namen</strong> – so findet auch das Feld
									   „Teil | Reihe" am Seminar seine Reihe. Text, Auszug, Status und alle Felder kommen
									   aus dem Paket; eine hier überarbeitete Fassung geht dabei verloren. Die Adresse der
									   Reihenseite (Slug) bleibt bei vorhandenen Reihen unverändert.</p></td>
							</tr>
							<?php endif; ?>
							<tr>
								<th>Begriffe</th>
								<td><label><input type="checkbox" name="begriffe_aufraeumen" value="1">
									nach dem Import Begriffe entfernen, an denen kein Eintrag mehr hängt</label>
									<p class="description">Das Paket bringt die Begriffe der Quelle mit, es nimmt aber keine mit, die
									   es dort nicht mehr gibt: Eine in der Quelle zusammengeführte Zielgruppe bleibt hier
									   sonst als leerer Eintrag stehen. <strong>Achtung:</strong> Entfernt werden alle
									   Begriffe ohne Zuordnung – auch solche, die nichts mit diesem Paket zu tun haben.
									   Ohne Haken steht die Zahl hinterher in der Meldung, und der Reiter
									   <em>Begriffe</em> räumt je Taxonomie einzeln auf.</p></td>
							</tr>
						</table>
						<?php submit_button( 'Paket importieren' ); ?>
					</form>
				<?php else : ?>
					<p>Ein Paket aus einer anderen Installation einlesen. Fehlende Begriffe werden dabei angelegt,
					   vorhandene E-Mail-Adressen der Bildungszentren bleiben unverändert.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="bi_paket_upload">
						<?php wp_nonce_field( 'bi_paket_upload' ); ?>
						<p><input type="file" name="bi_paket" accept=".json,application/json" required></p>
						<?php submit_button( 'Paket hochladen', 'secondary' ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
