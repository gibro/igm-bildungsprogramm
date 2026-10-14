<?php
/**
 * Seminar-Import aus CSV mit Spalten-Mapping (zweistufig).
 *
 *  Schritt 1: CSV hochladen -> Datei wird in uploads/bi-import/ zwischengelagert,
 *             Kopfzeile wird gelesen, Mapping-Formular angezeigt.
 *  Schritt 2: Nutzer ordnet jede Zielspalte einer CSV-Spalte zu -> Import.
 *
 *  Der Import läuft für BEIDE Beitragstypen: Präsenz-Seminare (BI_CPT) und
 *  Online-Seminare (BI_ONLINE). Der Beitragstyp wird durch das Formular gereicht
 *  und bestimmt Zielfelder (BI_CPT::meta_fields) und Taxonomie-Beschriftungen.
 *
 *  Zielfelder:
 *    title, content (Post), _bi_* (Meta), bi_ort/bi_handlungsfeld/
 *    bi_zielgruppe/bi_freistellung/bi_programm (Taxonomie; mehrfach mit | oder , getrennt).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Import {

	public static function init() {
		add_action( 'admin_post_bi_import_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_bi_import_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'wp_ajax_bi_import_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'admin_post_bi_delete_all_seminars', array( __CLASS__, 'handle_delete_all' ) );
		add_action( 'admin_post_bi_teil_upload', array( __CLASS__, 'handle_teil_upload' ) );
		add_action( 'admin_post_bi_teil_zuordnen', array( __CLASS__, 'handle_teil_zuordnen' ) );
		add_action( 'admin_post_bi_teil_run', array( __CLASS__, 'handle_teil_run' ) );
	}

	/** ---------- Beitragstyp-Helfer ---------- */

	/** Nur bekannte Beitragstypen zulassen; Fallback = Präsenz. */
	private static function sanitize_pt( $post_type ) {
		$post_type = is_string( $post_type ) ? sanitize_key( $post_type ) : '';
		return in_array( $post_type, bi_seminar_post_types(), true ) ? $post_type : BI_CPT;
	}

	/** Einstellungen-Tab, zu dem nach einer Aktion zurückgesprungen wird. */
	private static function tab_for( $post_type ) {
		return ( BI_ONLINE === $post_type ) ? 'onlineimport' : 'seminarimport';
	}

	/** Plural-Bezeichnung für Überschriften und Meldungen. */
	private static function label_for( $post_type ) {
		return ( BI_ONLINE === $post_type ) ? 'Online-Seminare' : 'Seminare';
	}

	/** Alle Status, die als „Seminar in der Datenbank" gelten (für Zählen + Löschen). */
	private static function all_seminar_statuses() {
		return array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' );
	}

	/** Anzahl aller Seminare eines Beitragstyps (alle Status) – für Anzeige im Lösch-Block. */
	private static function seminar_total( $post_type ) {
		return count( get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => self::all_seminar_statuses(),
			'fields'           => 'ids',
			'numberposts'      => -1,
			'suppress_filters' => true,
		) ) );
	}

	/**
	 * Zielfelder: key => Beschriftung. Taxonomien tragen Präfix "tax:".
	 * Die Meta-Felder werden aus BI_CPT::meta_fields() abgeleitet, damit neue
	 * Felder automatisch im Import erscheinen.
	 */
	public static function targets( $post_type = BI_CPT ) {
		$post_type = self::sanitize_pt( $post_type );

		$t = array(
			'title'   => 'Seminartitel (Pflicht)',
			'content' => 'Seminarbeschreibung',
		);
		foreach ( BI_CPT::meta_fields( $post_type ) as $key => $cfg ) {
			$t[ $key ] = $cfg['label'];
		}

		$taxes = BI_CPT::taxonomies( $post_type );
		$t[ 'tax:' . BI_TAX_ORT ]      = $taxes[ BI_TAX_ORT ]['single'];
		$t[ 'tax:' . BI_TAX_THEMA ]    = 'Themenfeld';
		$t[ 'tax:' . BI_TAX_ZIEL ]     = 'Zielgruppe (mehrfach)';
		$t[ 'tax:' . BI_TAX_FREI ]     = 'Freistellung (mehrfach)';
		$t[ 'tax:' . BI_TAX_PROGRAMM ] = 'Programm (Jahrgang)';
		return $t;
	}

	private static function dir() {
		$up = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'bi-import';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir;
	}

	/** ---------- Admin-Bereich (eingebettet in Einstellungen) ---------- */

	public static function render_section( $post_type = BI_CPT ) {
		$post_type = self::sanitize_pt( $post_type );
		$step      = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( $_GET['step'] ) ) : 'upload';

		echo '<div><h2 class="title">' . esc_html( self::label_for( $post_type ) ) . ' importieren</h2>';
		if ( 'lauf' === $step ) {
			self::render_lauf( $post_type );
		} elseif ( 'map' === $step ) {
			self::render_mapping( $post_type );
		} else {
			self::render_upload( $post_type );
		}
		echo '</div>';
	}

	private static function render_upload( $post_type ) {
		$label = self::label_for( $post_type );
		?>
		<p>Lade eine CSV-Datei hoch. Im nächsten Schritt ordnest du die Spalten den Feldern zu.
		   Mehrfach-Felder (Zielgruppe, Freistellung) dürfen in einer Zelle mehrere Werte enthalten – getrennt mit <code>|</code> oder <code>,</code>.</p>
		<?php if ( BI_ONLINE === $post_type ) : ?>
			<p class="description">Erwartete Spalten: Titel, Untertitel, Seminarbeschreibung, Themen im Seminar,
				Zielgruppe, Veranstalter*in, Referent*innen, Startdatum, Enddatum,
				Uhrzeit Seminarbeginn/-ende, Seminarnummer, Freistellung, Kosten, Online-Link,
				Ansprechpartner*in, Anmeldung (E-Mail).
				Für die Anmelde-Weiche zusätzlich <strong>Webinar-Tool</strong>
				(<code>teams_webinar</code>, <code>teams_meeting</code>, <code>zoom</code> oder <code>anderes</code>)
				und <strong>Anmeldelink (Teams-Webinar)</strong>.</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="bi_import_upload">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
			<?php wp_nonce_field( 'bi_import_upload' ); ?>
			<table class="form-table">
				<tr><th><label for="bi_csv">CSV-Datei</label></th>
					<td><input type="file" name="bi_csv" id="bi_csv" accept=".csv,text/csv" required></td></tr>
				<tr><th>Erste Zeile</th>
					<td><label><input type="checkbox" name="has_header" value="1" checked> ist eine Kopfzeile (Spaltennamen)</label></td></tr>
			</table>
			<?php submit_button( 'Weiter zur Zuordnung' ); ?>
		</form>

		<?php self::render_teilimport_hinweis(); ?>

		<?php $total = self::seminar_total( $post_type ); ?>
		<hr style="margin:28px 0">
		<div style="border:1px solid #d63638;border-left-width:4px;background:#fcf0f1;padding:14px 18px;max-width:760px">
			<h3 style="margin-top:0;color:#d63638">Alle <?php echo esc_html( $label ); ?> löschen</h3>
			<p>Entfernt <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong> Eintrag/Einträge aller Status
			   <strong>unwiderruflich</strong> aus der Datenbank (inkl. Termine/Metadaten). Taxonomie-Begriffe
			   (Bildungszentren, Themenfelder …) und Anmeldungen bleiben erhalten. Der jeweils andere
			   Beitragstyp ist nicht betroffen. <strong>Vorher ein Backup anlegen.</strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			      onsubmit="return confirm('Wirklich ALLE <?php echo (int) $total; ?> Einträge endgültig löschen?\nDas kann nicht rückgängig gemacht werden.');">
				<input type="hidden" name="action" value="bi_delete_all_seminars">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
				<?php wp_nonce_field( 'bi_delete_all_seminars' ); ?>
				<p style="margin:0 0 10px"><label>Zum Bestätigen <code>LÖSCHEN</code> eintippen:<br>
					<input type="text" name="confirm" autocomplete="off" placeholder="LÖSCHEN" style="margin-top:4px;width:200px"></label></p>
				<?php submit_button( 'Alle ' . $label . ' löschen', 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/* ===================================================================
	 *  Teil-Import: einzelne Felder vorhandener Einträge überschreiben
	 * =================================================================== */

	/**
	 * WARUM ES DEN TEIL-IMPORT GIBT
	 *
	 * Der Import oben ist ein Voll-Import: Er schreibt bei einem vorhandenen
	 * Seminar immer auch Titel, Beschreibung und Status – aus der Spalte, die
	 * zugeordnet ist, und LEER, wenn keine zugeordnet ist. Eine Datei mit nur
	 * „Seminarnummer; Voraussetzungen" hätte damit jede Beschreibung gelöscht.
	 *
	 * Der Teil-Import fasst dagegen nur an, was zugeordnet ist:
	 *   - Schlüssel ist die Seminarnummer; angelegt wird NIE etwas.
	 *   - Jede Spalte der Datei wird von Hand einem Feld zugeordnet oder
	 *     ignoriert (Vorschlag wie beim Voll-Import). Erlaubt sind Titel,
	 *     Beschreibung, alle Datenfelder und die Begriffe; Begriffe wahlweise
	 *     ersetzen oder ergänzen.
	 *   - Die Zellen werden genauso umgewandelt wie beim Voll-Import (Datum,
	 *     Uhrzeit, Ja/Nein, Beträge, Auswahlfelder). Die erste Fassung (1.135.0)
	 *     tat das nicht: „ja" in einem Ja/Nein-Feld wurde zu „nein", ein
	 *     „01.03.2027" landete ungewandelt in der Datenbank.
	 *   - Leere Zellen lassen den vorhandenen Wert stehen – außer, der Haken
	 *     „Leere Zellen leeren das Feld" ist gesetzt. Ein Titel wird nie geleert.
	 *   - Eine Spalte „Seminarform" (Präsenz / Online) lenkt jede Zeile in ihren
	 *     Beitragstyp. Ohne sie gilt die Seminarform aus dem ersten Schritt.
	 *   - Vor dem Speichern steht eine Vorschau mit Vorher/Nachher. Übernommen
	 *     wird, was die Vorschau zeigt: Der Plan wird beim Übernehmen aus Datei
	 *     und Zuordnung neu berechnet, nicht aus der Seite gelesen.
	 *   - In der Zentrale werden Seminare aus dem Abgleich nicht angefasst – der
	 *     nächste Abgleich schriebe sie ohnehin zurück (siehe BI_Sync).
	 *
	 * Seit 1.143.0 liegt er in der Datenpflege (Reiter „Teil-Import"). Dort ist
	 * er das Gegenstück zum CSV-Export: exportieren, Spalten bearbeiten oder
	 * streichen, zurückspielen.
	 */

	/** Pseudo-Ziele der Zuordnung, die kein Feld beschreiben. */
	const TEIL_NUMMER = '#nummer';
	const TEIL_FORM   = '#form';

	/** So viele Feldänderungen listet die Vorschau einzeln auf. */
	const TEIL_VORSCHAU = 300;

	/** Adresse des Reiters in der Datenpflege. */
	public static function teil_url( $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => 'bi-datenpflege', 'tab' => 'teilimport' ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/** Zurück auf den Reiter, mit Meldung. */
	private static function teil_zurueck( $args = array(), $msg = '' ) {
		self::redirect( array_merge( array( 'page' => 'bi-datenpflege', 'tab' => 'teilimport' ), $args ), $msg );
	}

	/** Nur eigene Zwischendateien zulassen – der Name kommt aus der Adresszeile. */
	private static function teil_token( $raw ) {
		$token = sanitize_file_name( (string) $raw );
		return preg_match( '/^teil-[A-Za-z0-9]+\.csv$/', $token ) ? $token : '';
	}

	/** Schlüssel der gespeicherten Zuordnung, je Datei und Person. */
	private static function teil_cfg_key( $token ) {
		return 'bi_teil_' . md5( $token . '|' . get_current_user_id() );
	}

	/**
	 * Mögliche Ziele für beide Seminarformen.
	 *
	 * @return array key => array( label, gruppe, pts ) – pts = Seminarformen,
	 *               die das Feld kennen. Die Beschriftung stammt aus der
	 *               gewählten Seminarform, sonst aus der anderen.
	 */
	public static function teil_ziele( $post_type = BI_CPT ) {
		$post_type = self::sanitize_pt( $post_type );
		$pts       = bi_seminar_post_types();
		// Gewählte Seminarform zuerst, damit ihre Beschriftungen gewinnen.
		usort( $pts, function ( $a, $b ) use ( $post_type ) {
			return ( $b === $post_type ) - ( $a === $post_type );
		} );

		$ziele = array(
			'title'   => array( 'label' => 'Seminartitel', 'gruppe' => 'Allgemein', 'pts' => $pts ),
			'content' => array( 'label' => 'Seminarbeschreibung', 'gruppe' => 'Allgemein', 'pts' => $pts ),
		);
		foreach ( $pts as $pt ) {
			foreach ( BI_CPT::meta_fields( $pt ) as $key => $cfg ) {
				if ( '_bi_seminarnummer' === $key ) {
					continue; // Schlüssel, nicht Ziel
				}
				if ( ! isset( $ziele[ $key ] ) ) {
					$ziele[ $key ] = array( 'label' => $cfg['label'], 'gruppe' => 'Felder', 'pts' => array() );
				}
				$ziele[ $key ]['pts'][] = $pt;
			}
		}
		foreach ( $pts as $pt ) {
			foreach ( BI_CPT::taxonomies( $pt ) as $slug => $cfg ) {
				$key = 'tax:' . $slug;
				if ( ! isset( $ziele[ $key ] ) ) {
					$ziele[ $key ] = array( 'label' => $cfg['single'], 'gruppe' => 'Begriffe', 'pts' => array() );
				}
				$ziele[ $key ]['pts'][] = $pt;
			}
		}
		return $ziele;
	}

	/**
	 * Zuordnungsvorschlag: Spalte => Ziel. Dieselben Namen und Aliasse wie beim
	 * Voll-Import, für beide Seminarformen. Eine Spalte bekommt höchstens ein
	 * Ziel, ein Ziel höchstens eine Spalte.
	 */
	public static function teil_vorschlag( $headers, $post_type = BI_CPT ) {
		$vorschlag = array();
		$vergeben  = array();
		foreach ( $headers as $i => $h ) {
			if ( in_array( strtolower( trim( (string) $h ) ), array( 'form', 'seminarform' ), true ) ) {
				$vorschlag[ $i ] = self::TEIL_FORM;
				$vergeben[ self::TEIL_FORM ] = true;
				break;
			}
		}
		$ziele = self::teil_ziele( $post_type );
		$pts   = array_unique( array_merge( array( self::sanitize_pt( $post_type ) ), bi_seminar_post_types() ) );
		foreach ( $pts as $pt ) {
			foreach ( self::guess_mapping( self::targets( $pt ), $headers, true ) as $key => $idx ) {
				$ziel = ( '_bi_seminarnummer' === $key ) ? self::TEIL_NUMMER : $key;
				if ( isset( $vorschlag[ $idx ] ) || isset( $vergeben[ $ziel ] ) ) {
					continue;
				}
				if ( self::TEIL_NUMMER !== $ziel && ! isset( $ziele[ $ziel ] ) ) {
					continue;
				}
				$vorschlag[ $idx ] = $ziel;
				$vergeben[ $ziel ] = true;
			}
		}
		ksort( $vorschlag );
		return $vorschlag;
	}

	/**
	 * Zuordnung prüfen. Liefert eine Fehlermeldung oder ''.
	 *
	 * @param array $map Spalte => Ziel ('' = ignorieren).
	 */
	public static function teil_pruefen( $map, $post_type = BI_CPT ) {
		$ziele  = self::teil_ziele( $post_type );
		$zaehl  = array();
		$felder = 0;
		foreach ( $map as $ziel ) {
			if ( '' === $ziel ) {
				continue;
			}
			if ( self::TEIL_NUMMER !== $ziel && self::TEIL_FORM !== $ziel && ! isset( $ziele[ $ziel ] ) ) {
				return 'Unbekanntes Ziel in der Zuordnung.';
			}
			$zaehl[ $ziel ] = ( $zaehl[ $ziel ] ?? 0 ) + 1;
			if ( isset( $ziele[ $ziel ] ) ) {
				$felder++;
			}
		}
		if ( empty( $zaehl[ self::TEIL_NUMMER ] ) ) {
			return 'Bitte eine Spalte als „Seminarnummer (Schlüssel)" zuordnen – über sie werden die Seminare gefunden.';
		}
		foreach ( $zaehl as $ziel => $n ) {
			if ( $n > 1 ) {
				$label = self::TEIL_NUMMER === $ziel ? 'Seminarnummer' : ( self::TEIL_FORM === $ziel ? 'Seminarform' : $ziele[ $ziel ]['label'] );
				return sprintf( '„%s" ist %d Spalten zugeordnet – jedes Feld darf nur aus einer Spalte kommen.', $label, $n );
			}
		}
		if ( ! $felder ) {
			return 'Außer der Seminarnummer ist keine Spalte einem Feld zugeordnet – es gäbe nichts zu schreiben.';
		}
		return '';
	}

	/** Wird dieses Seminar in der Zentrale vom Abgleich geführt (und ist damit gesperrt)? */
	private static function teil_gesperrt( $post_id ) {
		if ( ! class_exists( 'BI_Sync' ) ) {
			return false;
		}
		if ( ! get_post_meta( $post_id, BI_Sync::META_QUELLE, true ) || get_post_meta( $post_id, BI_Sync::META_GELOEST, true ) ) {
			return false;
		}
		return (bool) apply_filters( 'bi_sync_schreibschutz', true, $post_id );
	}

	/** Begriffsnamen eines Seminars. */
	private static function teil_begriffe( $post_id, $tax ) {
		$namen = wp_get_object_terms( $post_id, $tax, array( 'orderby' => 'term_order', 'fields' => 'names' ) );
		return ( is_wp_error( $namen ) || ! $namen ) ? array() : array_values( (array) $namen );
	}

	/** Namenslisten vergleichen – Reihenfolge und Großschreibung egal. */
	private static function teil_gleiche_begriffe( $a, $b ) {
		$norm = function ( $liste ) {
			$liste = array_map( function ( $s ) {
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ) ) : strtolower( trim( $s ) );
			}, $liste );
			$liste = array_values( array_unique( $liste ) );
			sort( $liste );
			return $liste;
		};
		return $norm( $a ) === $norm( $b );
	}

	/** Wert für die Vorschau: kurz, ohne Markup, Ja/Nein ausgeschrieben. */
	private static function teil_anzeige( $wert, $typ ) {
		if ( is_array( $wert ) ) {
			return $wert ? implode( ' | ', $wert ) : '—';
		}
		if ( 'bool' === $typ ) {
			return '1' === (string) $wert ? 'ja' : 'nein';
		}
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $wert ) ) );
		if ( '' === $text ) {
			return '—';
		}
		return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $text, 0, 140, '…' ) : $text;
	}

	/**
	 * Den Plan berechnen: Was würde sich an welchem Seminar ändern?
	 *
	 * Schreibt nichts. Derselbe Plan dient der Vorschau und dem Übernehmen.
	 *
	 * @param string $path Datei.
	 * @param array  $cfg  pt, map (Spalte => Ziel), leere (bool),
	 *                     date_format, modus (Spalte => ersetzen|ergaenzen).
	 * @return array|string Plan oder Fehlermeldung.
	 */
	public static function teil_plan( $path, $cfg ) {
		list( $delim, $headers ) = self::peek( $path );
		if ( ! $headers ) {
			return 'Die Datei ist leer.';
		}
		$post_type = self::sanitize_pt( $cfg['pt'] ?? BI_CPT );
		$map       = (array) ( $cfg['map'] ?? array() );
		$fehler    = self::teil_pruefen( $map, $post_type );
		if ( $fehler ) {
			return $fehler;
		}
		$leere       = ! empty( $cfg['leere'] );
		$date_format = (string) ( $cfg['date_format'] ?? 'auto' );
		$modus       = (array) ( $cfg['modus'] ?? array() );
		$ziele       = self::teil_ziele( $post_type );

		$nr_idx   = array_search( self::TEIL_NUMMER, $map, true );
		$form_idx = array_search( self::TEIL_FORM, $map, true );
		$spalten  = array(); // Ziel => Spalte
		foreach ( $map as $idx => $ziel ) {
			if ( isset( $ziele[ $ziel ] ) ) {
				$spalten[ $ziel ] = (int) $idx;
			}
		}

		$felder = array();
		$taxes  = array();
		foreach ( bi_seminar_post_types() as $pt ) {
			$felder[ $pt ] = BI_CPT::meta_fields( $pt );
			$taxes[ $pt ]  = BI_CPT::taxonomies( $pt );
		}

		$plan = array(
			'pt'           => $post_type,
			'spalten'      => $spalten,
			'eintraege'    => array(),
			'werte'        => 0,
			'zeilen'       => 0,
			'unveraendert' => 0,
			'ohne_nummer'  => 0,
			'unbekannt'    => array(),
			'doppelt'      => array(),
			'gesperrt'     => array(),
			'fehlt_feld'   => array(),
		);
		$gesehen = array();

		$handle = fopen( $path, 'r' );
		$zeile  = 0;
		while ( ( $row = fgetcsv( $handle, 0, $delim, '"', '\\' ) ) !== false ) {
			$zeile++;
			if ( 1 === $zeile ) {
				continue; // Kopfzeile
			}
			if ( array( null ) === $row ) {
				continue; // Leerzeile
			}
			$plan['zeilen']++;
			$nummer = trim( self::cell( $row, $nr_idx ) );
			if ( '' === $nummer ) {
				$plan['ohne_nummer']++;
				continue;
			}
			$pt = $post_type;
			if ( false !== $form_idx ) {
				$form = strtolower( trim( self::cell( $row, $form_idx ) ) );
				if ( '' !== $form ) {
					$pt = ( 0 === strpos( $form, 'online' ) ) ? BI_ONLINE : BI_CPT;
				}
			}

			// Eine Nummer zweimal in der Datei: Die erste Zeile zählt. Welche
			// gemeint ist, kann nur der Mensch entscheiden – deshalb sichtbar.
			$schluessel = $pt . ':' . $nummer;
			if ( isset( $gesehen[ $schluessel ] ) ) {
				$plan['doppelt'][] = $nummer;
				continue;
			}
			$gesehen[ $schluessel ] = true;

			$post_id = BI_CPT::post_zu_nummer( $nummer, $pt );
			if ( ! $post_id ) {
				$plan['unbekannt'][] = $nummer;
				continue;
			}
			if ( self::teil_gesperrt( $post_id ) ) {
				$plan['gesperrt'][] = $nummer;
				continue;
			}

			$aenderungen = array();
			foreach ( $spalten as $ziel => $idx ) {
				if ( ! in_array( $pt, $ziele[ $ziel ]['pts'], true ) ) {
					$plan['fehlt_feld'][ $ziele[ $ziel ]['label'] ] = ( $plan['fehlt_feld'][ $ziele[ $ziel ]['label'] ] ?? 0 ) + 1;
					continue;
				}
				$roh  = self::cell_roh( $row, $idx );
				$leer = ( '' === trim( $roh ) );
				if ( $leer && ! $leere ) {
					continue;
				}

				if ( 'title' === $ziel ) {
					if ( $leer ) {
						continue; // Ein Seminar ohne Titel gibt es nicht.
					}
					$alt = (string) get_the_title( $post_id );
					$neu = wp_strip_all_tags( self::cell( $row, $idx ) );
					$typ = 'text';
				} elseif ( 'content' === $ziel ) {
					$alt = (string) get_post_field( 'post_content', $post_id );
					$neu = wp_kses_post( $roh );
					$typ = 'html';
				} elseif ( 0 === strpos( $ziel, 'tax:' ) ) {
					$tax      = substr( $ziel, 4 );
					$multi    = ! empty( $taxes[ $pt ][ $tax ]['multi'] );
					$ergaenze = ( 'ergaenzen' === ( $modus[ $idx ] ?? '' ) );
					$alt      = self::teil_begriffe( $post_id, $tax );
					$roh_tx   = self::split_terms( self::cell( $row, $idx ), $multi );
					$neu      = $roh_tx;
					// Wie beim Voll-Import: Bei Präsenz führt bi_ort das Bildungszentrum,
					// alles andere wird „Andere". Hier nur zur Anzeige, ohne Nebenwirkung –
					// den Ort sichert ort_terms() erst beim Übernehmen.
					if ( BI_TAX_ORT === $tax && BI_CPT === $pt ) {
						$neu = array();
						foreach ( $roh_tx as $name ) {
							$neu[] = BI_CPT::ist_bildungszentrum( $name ) ? $name : BI_CPT::ANDERE;
						}
						$neu = array_values( array_unique( $neu ) );
					}
					if ( $ergaenze ) {
						if ( ! $neu ) {
							continue; // Ergänzen um nichts ändert nichts.
						}
						$neu = array_values( array_unique( array_merge( $alt, $neu ) ) );
					}
					if ( self::teil_gleiche_begriffe( $alt, $neu ) ) {
						continue;
					}
					$aenderungen[ $ziel ] = array(
						'label'    => $ziele[ $ziel ]['label'],
						'typ'      => 'tax',
						'alt'      => $alt,
						'neu'      => $neu,
						'roh'      => $roh_tx,
						'ergaenze' => $ergaenze,
					);
					continue;
				} else {
					$fcfg = $felder[ $pt ][ $ziel ];
					$typ  = $fcfg['type'];
					$neu  = self::meta_wert( $row, $idx, $fcfg, $date_format );
					$alt  = ( 'bool' === $typ )
						? ( BI_CPT::meta_bool( $post_id, $ziel ) ? '1' : '0' )
						: (string) get_post_meta( $post_id, $ziel, true );
				}

				if ( (string) $alt === (string) $neu ) {
					continue;
				}
				$aenderungen[ $ziel ] = array(
					'label' => $ziele[ $ziel ]['label'],
					'typ'   => $typ,
					'alt'   => $alt,
					'neu'   => $neu,
				);
			}

			if ( ! $aenderungen ) {
				$plan['unveraendert']++;
				continue;
			}
			$plan['werte']      += count( $aenderungen );
			$plan['eintraege'][] = array(
				'id'      => (int) $post_id,
				'nummer'  => $nummer,
				'pt'      => $pt,
				'titel'   => (string) get_the_title( $post_id ),
				'felder'  => $aenderungen,
			);
		}
		fclose( $handle );
		return $plan;
	}

	/**
	 * Den Plan schreiben.
	 *
	 * @return array Zähler: eintraege, werte.
	 */
	public static function teil_anwenden( $plan ) {
		$stat = array( 'eintraege' => 0, 'werte' => 0 );

		foreach ( $plan['eintraege'] as $e ) {
			$id      = $e['id'];
			$postarr = array();
			foreach ( $e['felder'] as $ziel => $f ) {
				if ( 'title' === $ziel ) {
					$postarr['post_title'] = $f['neu'];
				} elseif ( 'content' === $ziel ) {
					$postarr['post_content'] = $f['neu'];
				} elseif ( 'tax' === $f['typ'] ) {
					$tax   = substr( $ziel, 4 );
					$namen = $f['neu'];
					if ( BI_TAX_ORT === $tax && BI_CPT === $e['pt'] && $f['roh'] ) {
						// Jetzt mit Nebenwirkung: sichert einen fremden Ort als Seminarort.
						$namen = self::ort_terms( $f['roh'], $id );
						if ( $f['ergaenze'] ) {
							$namen = array_values( array_unique( array_merge( $f['alt'], $namen ) ) );
						}
					}
					wp_set_object_terms( $id, $namen, $tax, false );
				} else {
					update_post_meta( $id, $ziel, $f['neu'] );
				}
				$stat['werte']++;
			}
			if ( $postarr ) {
				$postarr['ID'] = $id;
				wp_update_post( wp_slash( $postarr ) );
			}

			if ( isset( $e['felder']['_bi_teil_reihe'] ) && class_exists( 'BI_Reihen' ) ) {
				BI_Reihen::zuordnen( $id );
			}
			$stat['eintraege']++;
		}

		if ( $stat['eintraege'] ) {
			if ( class_exists( 'BI_Cache' ) ) {
				BI_Cache::leeren( true );
			}
		}
		return $stat;
	}

	/** Hinweise zum Plan als Sätze – für Vorschau und Abschlussmeldung. */
	private static function teil_hinweise( $plan ) {
		$h = array();
		$beispiele = function ( $liste ) {
			$liste = array_values( array_unique( $liste ) );
			return implode( ', ', array_slice( $liste, 0, 12 ) ) . ( count( $liste ) > 12 ? ' …' : '' );
		};
		if ( $plan['unbekannt'] ) {
			$h[] = sprintf( '%s Seminarnummern gibt es hier nicht (es wird nichts angelegt): %s.',
				number_format_i18n( count( $plan['unbekannt'] ) ), $beispiele( $plan['unbekannt'] ) );
		}
		if ( $plan['gesperrt'] ) {
			$h[] = sprintf( '%s Seminare kommen aus dem Abgleich mit einer Quelle und bleiben unberührt – bitte dort ändern: %s.',
				number_format_i18n( count( $plan['gesperrt'] ) ), $beispiele( $plan['gesperrt'] ) );
		}
		if ( $plan['doppelt'] ) {
			$h[] = sprintf( '%s Seminarnummern stehen mehrfach in der Datei; es zählt jeweils die erste Zeile: %s.',
				number_format_i18n( count( $plan['doppelt'] ) ), $beispiele( $plan['doppelt'] ) );
		}
		foreach ( $plan['fehlt_feld'] as $label => $n ) {
			$h[] = sprintf( '„%s" gibt es bei der anderen Seminarform nicht – in %s Zeilen übergangen.', $label, number_format_i18n( $n ) );
		}
		if ( $plan['ohne_nummer'] ) {
			$h[] = sprintf( '%s Zeilen ohne Seminarnummer übersprungen.', number_format_i18n( $plan['ohne_nummer'] ) );
		}
		return $h;
	}

	/* ---------- Teil-Import: Seiten ---------- */

	/** Der Reiter „Teil-Import" in der Datenpflege. */
	public static function render_teilimport_page() {
		$schritt = isset( $_GET['schritt'] ) ? sanitize_key( wp_unslash( $_GET['schritt'] ) ) : '';
		$token   = self::teil_token( wp_unslash( $_GET['datei'] ?? '' ) );
		$path    = $token ? self::dir() . '/' . $token : '';

		if ( $schritt && ( ! $token || ! file_exists( $path ) ) ) {
			echo '<div class="notice notice-warning"><p>Die hochgeladene Datei ist nicht mehr da (übernommen oder älter als einen Tag). Bitte neu hochladen.</p></div>';
			$schritt = '';
		}
		if ( 'vorschau' === $schritt ) {
			self::render_teil_vorschau( $token, $path );
		} elseif ( 'zuordnen' === $schritt ) {
			self::render_teil_zuordnen( $token, $path );
		} else {
			self::render_teil_upload();
		}
	}

	private static function render_teil_upload() {
		?>
		<div class="card" style="max-width:900px;margin-top:16px">
			<h2 style="margin-top:0">Teil-Import: einzelne Felder überschreiben</h2>
			<p>Überschreibt bei <strong>vorhandenen</strong> Seminaren nur die Felder, die du im nächsten Schritt
			   den Spalten der Datei zuordnest – zum Beispiel <code>Seminarnummer;Voraussetzungen</code> oder
			   <code>Seminarnummer;Startdatum;Enddatum;Ausgebucht?</code>. Alles andere bleibt, wie es ist.</p>
			<ul style="list-style:disc;margin-left:20px">
				<li>Schlüssel ist die <strong>Seminarnummer</strong>. Neue Seminare werden <strong>nie</strong> angelegt.</li>
				<li>Möglich sind Titel, Beschreibung, alle Datenfelder und die Begriffe (ersetzen oder ergänzen).</li>
				<li>Vor dem Speichern zeigt eine <strong>Vorschau</strong> jede Änderung mit Vorher und Nachher.</li>
				<li>Am bequemsten: unter <em>Auswahl &amp; Export</em> eine CSV ziehen, Spalten bearbeiten oder
				    löschen, hier wieder hochladen – die Spalten sind dann schon richtig zugeordnet.</li>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="bi_teil_upload">
				<?php wp_nonce_field( 'bi_teil_upload' ); ?>
				<table class="form-table">
					<tr><th><label for="bi_teil_csv">CSV-Datei</label></th>
						<td><input type="file" name="bi_csv" id="bi_teil_csv" accept=".csv,.txt,text/csv" required>
							<p class="description">Erste Zeile = Spaltennamen, Trennzeichen <code>;</code> oder <code>,</code>.
							   UTF-8 oder Excel-Standard (Windows-1252) – das wird erkannt.</p></td></tr>
					<tr><th><label for="bi_teil_pt">Seminarform</label></th>
						<td><select name="post_type" id="bi_teil_pt">
								<option value="<?php echo esc_attr( BI_CPT ); ?>">Präsenz-Seminare</option>
								<option value="<?php echo esc_attr( BI_ONLINE ); ?>">Online-Seminare</option>
							</select>
							<p class="description">Hat die Datei eine Spalte <code>Seminarform</code> (Präsenz/Online),
							   entscheidet sie je Zeile. Präsenz und Online dürfen dieselbe Nummer führen.</p></td></tr>
				</table>
				<?php submit_button( 'Weiter zur Zuordnung' ); ?>
			</form>
			<?php if ( class_exists( 'BI_Sync' ) && BI_Sync::ist_quelle() ) : ?>
				<p class="description">Diese Website gibt ihre Seminare an die Zentrale weiter. Änderungen aus dem
				   Teil-Import gehen mit dem nächsten Abgleich dorthin mit.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_teil_zuordnen( $token, $path ) {
		list( $delim, $headers, $sample ) = self::peek( $path );
		$gespeichert = get_transient( self::teil_cfg_key( $token ) );
		$post_type   = self::sanitize_pt( $gespeichert['pt'] ?? wp_unslash( $_GET['pt'] ?? '' ) );
		$map         = is_array( $gespeichert ) ? (array) $gespeichert['map'] : self::teil_vorschlag( $headers, $post_type );
		$modus       = is_array( $gespeichert ) ? (array) $gespeichert['modus'] : array();
		$leere       = is_array( $gespeichert ) && ! empty( $gespeichert['leere'] );
		$date_format = is_array( $gespeichert ) ? (string) $gespeichert['date_format'] : 'auto';
		$ziele       = self::teil_ziele( $post_type );
		$andere      = ( BI_CPT === $post_type ) ? 'Online' : 'Präsenz';

		$gruppen = array();
		foreach ( $ziele as $key => $z ) {
			$label = $z['label'];
			if ( ! in_array( $post_type, $z['pts'], true ) ) {
				$label .= ' (nur ' . $andere . ')';
			}
			$gruppen[ $z['gruppe'] ][ $key ] = $label;
		}
		?>
		<div class="card" style="max-width:100%;margin-top:16px">
			<h2 style="margin-top:0">Teil-Import: Spalten zuordnen</h2>
			<p><strong>Trennzeichen:</strong> <code><?php echo esc_html( $delim ); ?></code> ·
			   <strong>Spalten:</strong> <?php echo count( $headers ); ?> ·
			   <strong>Seminarform:</strong> <?php echo esc_html( self::label_for( $post_type ) ); ?></p>
			<p>Jede Spalte entweder einem Feld zuordnen oder ignorieren. Genau eine Spalte muss die
			   <strong>Seminarnummer</strong> sein. Der Vorschlag beruht auf den Spaltennamen – bitte prüfen.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bi-teil-form">
				<input type="hidden" name="action" value="bi_teil_zuordnen">
				<input type="hidden" name="datei" value="<?php echo esc_attr( $token ); ?>">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
				<?php wp_nonce_field( 'bi_teil_zuordnen' ); ?>

				<table class="widefat striped" style="max-width:1100px">
					<thead><tr>
						<th style="width:22%">Spalte in der Datei</th>
						<th style="width:38%">Beispielwerte</th>
						<th>Schreiben in</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $headers as $i => $h ) : ?>
						<?php
						$ziel = $map[ $i ] ?? '';
						$bsp  = array();
						foreach ( $sample as $r ) {
							$w = trim( (string) ( $r[ $i ] ?? '' ) );
							if ( '' !== $w ) {
								$bsp[] = mb_strimwidth( wp_strip_all_tags( $w ), 0, 50, '…' );
							}
							if ( count( $bsp ) >= 3 ) {
								break;
							}
						}
						?>
						<tr>
							<td><strong><?php echo esc_html( '' !== trim( (string) $h ) ? $h : 'Spalte ' . ( $i + 1 ) ); ?></strong></td>
							<td style="color:#50575e"><?php echo $bsp ? esc_html( implode( ' · ', $bsp ) ) : '<em>leer</em>'; ?></td>
							<td>
								<select name="map[<?php echo (int) $i; ?>]" class="bi-teil-ziel" style="min-width:280px">
									<option value="">— ignorieren —</option>
									<optgroup label="Schlüssel">
										<option value="<?php echo esc_attr( self::TEIL_NUMMER ); ?>" <?php selected( $ziel, self::TEIL_NUMMER ); ?>>Seminarnummer (Schlüssel)</option>
										<option value="<?php echo esc_attr( self::TEIL_FORM ); ?>" <?php selected( $ziel, self::TEIL_FORM ); ?>>Seminarform (Präsenz/Online)</option>
									</optgroup>
									<?php foreach ( $gruppen as $gruppe => $opts ) : ?>
										<optgroup label="<?php echo esc_attr( $gruppe ); ?>">
											<?php foreach ( $opts as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $ziel, $key ); ?>><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
								<select name="modus[<?php echo (int) $i; ?>]" class="bi-teil-modus"
								        style="<?php echo 0 === strpos( $ziel, 'tax:' ) ? '' : 'display:none'; ?>">
									<option value="ersetzen" <?php selected( $modus[ $i ] ?? '', 'ersetzen' ); ?>>vorhandene Begriffe ersetzen</option>
									<option value="ergaenzen" <?php selected( $modus[ $i ] ?? '', 'ergaenzen' ); ?>>zu vorhandenen ergänzen</option>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<table class="form-table">
					<tr><th>Leere Zellen</th>
						<td><label><input type="checkbox" name="leere" value="1" <?php checked( $leere ); ?>>
							leeren das Feld</label>
							<p class="description">Ohne Haken bleibt bei einer leeren Zelle der vorhandene Wert stehen.
							   Ein Titel wird nie geleert; Ja/Nein-Felder fallen auf ihren Standard zurück.</p></td></tr>
					<tr><th><label for="bi_teil_df">Datumsformat</label></th>
						<td><select name="date_format" id="bi_teil_df">
								<?php foreach ( array( 'auto' => 'automatisch erkennen', 'd.m.Y' => 'TT.MM.JJJJ', 'Y-m-d' => 'JJJJ-MM-TT', 'd/m/Y' => 'TT/MM/JJJJ' ) as $fmt => $lbl ) : ?>
									<option value="<?php echo esc_attr( $fmt ); ?>" <?php selected( $date_format, $fmt ); ?>><?php echo esc_html( $lbl ); ?></option>
								<?php endforeach; ?>
							</select></td></tr>
				</table>
				<p class="submit">
					<?php submit_button( 'Vorschau anzeigen', 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( self::teil_url() ); ?>">Andere Datei</a>
				</p>
				<p class="description">Die Vorschau speichert noch nichts.</p>
			</form>
		</div>
		<script>
		( function () {
			document.querySelectorAll( '#bi-teil-form .bi-teil-ziel' ).forEach( function ( sel ) {
				sel.addEventListener( 'change', function () {
					var modus = sel.parentNode.querySelector( '.bi-teil-modus' );
					modus.style.display = 0 === sel.value.indexOf( 'tax:' ) ? '' : 'none';
				} );
			} );
		} )();
		</script>
		<?php
	}

	private static function render_teil_vorschau( $token, $path ) {
		$cfg = get_transient( self::teil_cfg_key( $token ) );
		if ( ! is_array( $cfg ) ) {
			echo '<div class="notice notice-warning"><p>Die Zuordnung ist abgelaufen. Bitte noch einmal zuordnen.</p></div>';
			self::render_teil_zuordnen( $token, $path );
			return;
		}
		@set_time_limit( 0 );
		$plan = self::teil_plan( $path, $cfg );
		if ( is_string( $plan ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $plan ) . '</p></div>';
			self::render_teil_zuordnen( $token, $path );
			return;
		}
		$zurueck = self::teil_url( array( 'schritt' => 'zuordnen', 'datei' => $token ) );
		$n       = count( $plan['eintraege'] );
		$gelistet = 0;
		?>
		<div class="card" style="max-width:100%;margin-top:16px">
			<h2 style="margin-top:0">Teil-Import: Vorschau</h2>
			<p style="font-size:14px">
				<strong><?php echo esc_html( number_format_i18n( $n ) ); ?></strong> <?php echo 1 === $n ? 'Seminar wird' : 'Seminare werden'; ?> geändert
				(<?php echo esc_html( number_format_i18n( $plan['werte'] ) ); ?> Werte),
				<?php echo esc_html( number_format_i18n( $plan['unveraendert'] ) ); ?> sind schon aktuell.
				Gelesen: <?php echo esc_html( number_format_i18n( $plan['zeilen'] ) ); ?> Zeilen.
			</p>
			<?php foreach ( self::teil_hinweise( $plan ) as $satz ) : ?>
				<p style="background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px;margin:6px 0"><?php echo esc_html( $satz ); ?></p>
			<?php endforeach; ?>

			<?php if ( $n ) : ?>
				<table class="widefat striped" style="margin-top:14px">
					<thead><tr>
						<th style="width:26%">Seminar</th>
						<th style="width:14%">Feld</th>
						<th style="width:30%">Vorher</th>
						<th style="width:30%">Nachher</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $plan['eintraege'] as $e ) : ?>
						<?php foreach ( $e['felder'] as $f ) : ?>
							<?php if ( ++$gelistet > self::TEIL_VORSCHAU ) { break 2; } ?>
							<tr>
								<td><a href="<?php echo esc_url( (string) get_edit_post_link( $e['id'] ) ); ?>"><?php echo esc_html( $e['titel'] ); ?></a><br>
									<span class="description"><?php echo esc_html( $e['nummer'] . ( BI_ONLINE === $e['pt'] ? ' · Online' : '' ) ); ?></span></td>
								<td><?php echo esc_html( $f['label'] ); ?><?php echo ! empty( $f['ergaenze'] ) ? '<br><span class="description">ergänzen</span>' : ''; ?></td>
								<td style="color:#8a2424"><?php echo esc_html( self::teil_anzeige( $f['alt'], $f['typ'] ) ); ?></td>
								<td style="color:#1a6e1a"><?php echo esc_html( self::teil_anzeige( $f['neu'], $f['typ'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $plan['werte'] > self::TEIL_VORSCHAU ) : ?>
					<p class="description">Gezeigt sind die ersten <?php echo (int) self::TEIL_VORSCHAU; ?> von
					   <?php echo esc_html( number_format_i18n( $plan['werte'] ) ); ?> Änderungen – übernommen werden alle.</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px"
				      onsubmit="return confirm( <?php echo esc_attr( wp_json_encode( sprintf( '%d Seminare jetzt ändern?', $n ) ) ); ?> );">
					<input type="hidden" name="action" value="bi_teil_run">
					<input type="hidden" name="datei" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( 'bi_teil_run' ); ?>
					<?php submit_button( 'Änderungen übernehmen', 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( $zurueck ); ?>">Zuordnung ändern</a>
					<a class="button-link" style="margin-left:10px" href="<?php echo esc_url( self::teil_url() ); ?>">Abbrechen</a>
				</form>
				<?php if ( class_exists( 'BI_Sync' ) && BI_Sync::ist_zentrale() ) : ?>
					<p class="description">Hinweis für die Zentrale: Seminare der Quellen pflegt die jeweilige Quelle.
					   Dieselbe Datei dort einspielen, sonst bleiben deren Seminare unverändert.</p>
				<?php endif; ?>
			<?php else : ?>
				<p>Es gibt nichts zu ändern.</p>
				<p><a class="button" href="<?php echo esc_url( $zurueck ); ?>">Zuordnung ändern</a>
				   <a class="button" href="<?php echo esc_url( self::teil_url() ); ?>">Andere Datei</a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------- Teil-Import: Aktionen ---------- */

	/** Zwischendateien des Teil-Imports, die älter als einen Tag sind, wegräumen. */
	private static function teil_aufraeumen() {
		foreach ( (array) glob( self::dir() . '/teil-*.csv' ) as $alt ) {
			if ( $alt && filemtime( $alt ) < time() - DAY_IN_SECONDS ) {
				@unlink( $alt );
			}
		}
	}

	public static function handle_teil_upload() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_teil_upload' );

		$post_type = self::sanitize_pt( wp_unslash( $_POST['post_type'] ?? '' ) );
		if ( empty( $_FILES['bi_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bi_csv']['tmp_name'] ) ) {
			self::teil_zurueck( array(), 'Teil-Import: Keine Datei empfangen.' );
		}
		$ext = strtolower( pathinfo( (string) ( $_FILES['bi_csv']['name'] ?? '' ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			self::teil_zurueck( array(), 'Teil-Import: Bitte eine CSV-Datei hochladen (in Excel: „Speichern unter" → CSV).' );
		}

		self::teil_aufraeumen();
		$token = 'teil-' . wp_generate_password( 12, false ) . '.csv';
		$dest  = self::dir() . '/' . $token;
		if ( ! move_uploaded_file( $_FILES['bi_csv']['tmp_name'], $dest ) ) {
			self::teil_zurueck( array(), 'Teil-Import: Die Datei konnte nicht gespeichert werden.' );
		}

		// Excel speichert „CSV" unter Windows in Windows-1252. Ohne Umwandlung
		// würden aus Umlauten Fragezeichen – im Titel wie im Feld.
		$inhalt = (string) file_get_contents( $dest );
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $inhalt, 'UTF-8' ) ) {
			file_put_contents( $dest, mb_convert_encoding( $inhalt, 'UTF-8', 'Windows-1252' ) );
		}

		list( , $headers ) = self::peek( $dest );
		if ( count( $headers ) < 2 ) {
			@unlink( $dest );
			self::teil_zurueck( array(), 'Teil-Import: Die Datei braucht mindestens zwei Spalten – die Seminarnummer und ein Feld.' );
		}

		delete_transient( self::teil_cfg_key( $token ) );
		self::teil_zurueck( array( 'schritt' => 'zuordnen', 'datei' => $token, 'pt' => $post_type ) );
	}

	public static function handle_teil_zuordnen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_teil_zuordnen' );

		$token = self::teil_token( wp_unslash( $_POST['datei'] ?? '' ) );
		if ( ! $token || ! file_exists( self::dir() . '/' . $token ) ) {
			self::teil_zurueck( array(), 'Teil-Import: Die Datei ist nicht mehr da. Bitte neu hochladen.' );
		}
		$post_type = self::sanitize_pt( wp_unslash( $_POST['post_type'] ?? '' ) );

		$map = array();
		foreach ( (array) wp_unslash( $_POST['map'] ?? array() ) as $idx => $ziel ) {
			$map[ (int) $idx ] = sanitize_text_field( (string) $ziel );
		}
		$modus = array();
		foreach ( (array) wp_unslash( $_POST['modus'] ?? array() ) as $idx => $m ) {
			$modus[ (int) $idx ] = ( 'ergaenzen' === $m ) ? 'ergaenzen' : 'ersetzen';
		}
		$df  = sanitize_text_field( wp_unslash( $_POST['date_format'] ?? 'auto' ) );
		$cfg = array(
			'pt'          => $post_type,
			'map'         => $map,
			'modus'       => $modus,
			'leere'       => ! empty( $_POST['leere'] ),
			'date_format' => in_array( $df, array( 'auto', 'd.m.Y', 'Y-m-d', 'd/m/Y' ), true ) ? $df : 'auto',
		);
		// Auch eine fehlerhafte Zuordnung merken: Wer zurückkommt, soll seine
		// Auswahl wiederfinden statt wieder beim Vorschlag zu beginnen.
		set_transient( self::teil_cfg_key( $token ), $cfg, DAY_IN_SECONDS );

		$fehler = self::teil_pruefen( $map, $post_type );
		if ( $fehler ) {
			self::teil_zurueck( array( 'schritt' => 'zuordnen', 'datei' => $token ), 'Teil-Import: ' . $fehler );
		}
		self::teil_zurueck( array( 'schritt' => 'vorschau', 'datei' => $token ) );
	}

	public static function handle_teil_run() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_teil_run' );

		$token = self::teil_token( wp_unslash( $_POST['datei'] ?? '' ) );
		$path  = $token ? self::dir() . '/' . $token : '';
		$cfg   = $token ? get_transient( self::teil_cfg_key( $token ) ) : false;
		if ( ! $token || ! file_exists( $path ) || ! is_array( $cfg ) ) {
			self::teil_zurueck( array(), 'Teil-Import: Datei oder Zuordnung nicht mehr vorhanden – vermutlich schon übernommen.' );
		}

		@set_time_limit( 0 );
		$plan = self::teil_plan( $path, $cfg );
		if ( is_string( $plan ) ) {
			self::teil_zurueck( array( 'schritt' => 'zuordnen', 'datei' => $token ), 'Teil-Import: ' . $plan );
		}
		$stat = self::teil_anwenden( $plan );

		// Erst nach dem Schreiben wegräumen: Ein zweiter Klick findet dann keine
		// Datei mehr und schreibt nicht noch einmal.
		@unlink( $path );
		delete_transient( self::teil_cfg_key( $token ) );

		$msg = sprintf(
			'Teil-Import: %s Seminare geändert (%s Werte), %s waren schon aktuell.',
			number_format_i18n( $stat['eintraege'] ),
			number_format_i18n( $stat['werte'] ),
			number_format_i18n( $plan['unveraendert'] )
		);
		$hinweise = self::teil_hinweise( $plan );
		if ( $hinweise ) {
			$msg .= ' ' . implode( ' ', $hinweise );
		}
		self::teil_zurueck( array(), $msg );
	}

	/**
	 * Hinweis an der alten Stelle im Seminar-Import: Der Teil-Import ist in die
	 * Datenpflege umgezogen (1.143.0). Ohne ihn suchte man ihn hier vergeblich.
	 */
	private static function render_teilimport_hinweis() {
		?>
		<hr style="margin:28px 0">
		<h3>Nur einzelne Felder überschreiben?</h3>
		<p style="max-width:820px">Dafür gibt es den <strong>Teil-Import</strong> in der
		   <a href="<?php echo esc_url( self::teil_url() ); ?>">Datenpflege → Teil-Import</a>: Datei mit Seminarnummer
		   und beliebigen weiteren Spalten hochladen, Spalten den Feldern zuordnen, Vorschau prüfen, übernehmen.
		   Er legt nichts an und lässt alle nicht zugeordneten Felder unberührt – anders als dieser Import, der
		   Titel, Beschreibung und Status immer mitschreibt.</p>
		<?php
	}

	private static function render_mapping( $post_type ) {
		$token  = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path   = self::dir() . '/' . $token;
		if ( ! $token || ! file_exists( $path ) ) {
			echo '<div class="notice notice-error"><p>Hochgeladene Datei nicht gefunden. Bitte erneut hochladen.</p></div>';
			self::render_upload( $post_type );
			return;
		}
		$has_header = ! empty( $_GET['header'] );
		list( $delim, $headers, $sample ) = self::peek( $path );

		// Spaltenauswahl: bei Kopfzeile Namen anzeigen, sonst "Spalte N"
		$col_options = array();
		foreach ( $headers as $i => $h ) {
			$col_options[ $i ] = $has_header && $h !== '' ? $h : 'Spalte ' . ( $i + 1 );
		}
		// Auto-Vorschlag: Zielfeld-Label grob mit Header matchen
		$targets = self::targets( $post_type );
		$guess   = self::guess_mapping( $targets, $col_options, $has_header );
		?>
		<p><strong>Erkanntes Trennzeichen:</strong> <code><?php echo esc_html( $delim ); ?></code> ·
		   <strong>Spalten:</strong> <?php echo count( $headers ); ?> ·
		   <strong>Ziel:</strong> <?php echo esc_html( self::label_for( $post_type ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_import_run">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
			<input type="hidden" name="file" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="header" value="<?php echo $has_header ? 1 : 0; ?>">
			<?php wp_nonce_field( 'bi_import_run' ); ?>
			<table class="form-table">
				<?php foreach ( $targets as $key => $label ) : ?>
					<tr>
						<th><?php echo esc_html( $label ); ?></th>
						<td>
							<select name="map[<?php echo esc_attr( $key ); ?>]">
								<option value="">— nicht importieren —</option>
								<?php foreach ( $col_options as $i => $name ) : ?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $guess[ $key ] ?? '', $i ); ?>>
										<?php echo esc_html( $name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th>Datumsformat</th>
					<td>
						<select name="date_format">
							<option value="auto">automatisch erkennen</option>
							<option value="Y-m-d">JJJJ-MM-TT</option>
							<option value="d.m.Y">TT.MM.JJJJ</option>
							<option value="d/m/Y">TT/MM/JJJJ</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Status der neuen Einträge</th>
					<td>
						<select name="post_status">
							<option value="publish">Veröffentlicht</option>
							<option value="draft">Entwurf</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Seminarnummer</th>
					<td>Steht die Seminarnummer einer Zeile schon an einem Eintrag, wird dieser Eintrag
						aktualisiert – nie ein zweiter angelegt. Eine Nummer kommt je Seminarform nur
						einmal vor; das lässt sich nicht abschalten.</td>
				</tr>
			</table>
			<?php submit_button( 'Import starten' ); ?>
		</form>

		<h2>Vorschau (erste Zeilen)</h2>
		<table class="widefat striped" style="max-width:100%;overflow:auto;display:block">
			<thead><tr><?php foreach ( $col_options as $name ) {
				echo '<th>' . esc_html( $name ) . '</th>';
			} ?></tr></thead>
			<tbody><?php foreach ( $sample as $r ) {
				echo '<tr>';
				foreach ( $col_options as $i => $name ) {
					echo '<td>' . esc_html( mb_strimwidth( (string) ( $r[ $i ] ?? '' ), 0, 60, '…' ) ) . '</td>';
				}
				echo '</tr>';
			} ?></tbody>
		</table>
		<?php
	}

	/** ---------- Schritt 1: Upload ---------- */

	public static function handle_upload() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_import_upload' );

		$post_type = self::sanitize_pt( wp_unslash( $_POST['post_type'] ?? '' ) );
		$tab       = self::tab_for( $post_type );

		if ( empty( $_FILES['bi_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bi_csv']['tmp_name'] ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => $tab ), 'Keine Datei empfangen.' );
		}
		$token = 'csv-' . wp_generate_password( 8, false ) . '.csv';
		$dest  = self::dir() . '/' . $token;
		if ( ! move_uploaded_file( $_FILES['bi_csv']['tmp_name'], $dest ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => $tab ), 'Datei konnte nicht gespeichert werden.' );
		}
		self::redirect( array(
			'page'   => 'bi-einstellungen', 'tab' => $tab,
			'step'   => 'map',
			'file'   => $token,
			'header' => empty( $_POST['has_header'] ) ? 0 : 1,
		) );
	}

	/** ---------- Schritt 2: Import ---------- */

	public static function handle_run() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_import_run' );

		$post_type = self::sanitize_pt( wp_unslash( $_POST['post_type'] ?? '' ) );
		$tab       = self::tab_for( $post_type );

		$token = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
		$path  = self::dir() . '/' . $token;
		if ( ! $token || ! file_exists( $path ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => $tab ), 'Datei nicht gefunden.' );
		}

		$map        = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array();
		$has_header = ! empty( $_POST['header'] );

		if ( empty( $map['title'] ) && '0' !== ( $map['title'] ?? '' ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => $tab, 'step' => 'map', 'file' => $token, 'header' => $has_header ? 1 : 0 ),
				'Bitte die Spalte für den Seminartitel zuordnen.' );
		}

		// Zuordnung nur auf bekannte Zielfelder eindampfen – aus dem Formular
		// könnte alles kommen, und der Lauf überlebt in einem Transient.
		$erlaubt = array_keys( self::targets( $post_type ) );
		$sauber  = array();
		foreach ( $map as $ziel => $spalte ) {
			if ( in_array( $ziel, $erlaubt, true ) && '' !== $spalte ) {
				$sauber[ $ziel ] = (int) $spalte;
			}
		}

		list( $delim ) = self::peek( $path );

		$lauf = array(
			'token'       => $token,
			'post_type'   => $post_type,
			'delim'       => $delim,
			'map'         => $sauber,
			'has_header'  => $has_header,
			'date_format' => sanitize_text_field( wp_unslash( $_POST['date_format'] ?? 'auto' ) ),
			'post_status' => ( ( $_POST['post_status'] ?? 'publish' ) === 'draft' ) ? 'draft' : 'publish',
			'dedupe'      => true, // Seminarnummer ist Schlüssel – immer, nicht wählbar
			'offset'      => 0,
			'zeile'       => 0,
			'gesamt'      => self::zeilen_zaehlen( $path, $delim, $has_header ),
			'created'     => 0,
			'updated'     => 0,
			'skipped'     => 0,
		);
		set_transient( self::lauf_key( $token ), $lauf, DAY_IN_SECONDS );

		self::redirect( array(
			'page' => 'bi-einstellungen',
			'tab'  => $tab,
			'step' => 'lauf',
			'file' => $token,
		) );
	}

	/** Schlüssel des Transients, in dem ein laufender Import steht. */
	private static function lauf_key( $token ) {
		return 'bi_import_lauf_' . md5( (string) $token );
	}

	/**
	 * Datenzeilen zählen – für den Fortschrittsbalken.
	 *
	 * Einmal durch die Datei, ohne etwas zu schreiben. Bei den Dateigrößen des
	 * Bildungsprogramms (gut tausend Zeilen) kostet das nichts, und ohne eine
	 * Gesamtzahl bliebe der Balken eine Zierde ohne Aussage.
	 */
	private static function zeilen_zaehlen( $path, $delim, $has_header ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return 0;
		}
		$n = 0;
		while ( false !== fgetcsv( $handle, 0, $delim ) ) {
			$n++;
		}
		fclose( $handle );
		return max( 0, $has_header ? $n - 1 : $n );
	}

	/* ---------- Schritt 3: Lauf mit Fortschritt ---------- */

	/**
	 * Fortschrittsseite. Der eigentliche Import läuft in Häppchen über AJAX.
	 *
	 * Grund: Über tausend Zeilen mit je einem wp_insert_post, Meta-Feldern und
	 * Begriffen sprengen jedes vernünftige Zeitlimit – und ein Browser, der
	 * minutenlang auf eine Antwort wartet, sieht aus wie ein Absturz. So bleibt
	 * jeder Aufruf kurz, und man sieht, dass etwas passiert.
	 */
	private static function render_lauf( $post_type ) {
		$token = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$lauf  = $token ? get_transient( self::lauf_key( $token ) ) : false;

		if ( ! is_array( $lauf ) ) {
			echo '<div class="notice notice-error"><p>Der Import-Lauf ist nicht mehr auffindbar. Bitte die Datei erneut hochladen.</p></div>';
			self::render_upload( $post_type );
			return;
		}
		?>
		<p>Der Import läuft in Häppchen. Dieses Fenster bitte offen lassen, bis er durch ist.
		   Neu laden oder ein zweites Fenster schadet nicht – es arbeitet immer nur eines, das andere schaut zu.</p>

		<div style="max-width:640px">
			<progress id="bi-imp-balken" value="0" max="<?php echo (int) $lauf['gesamt']; ?>" style="width:100%;height:22px"></progress>
			<p id="bi-imp-zahl" style="font-size:15px;margin:8px 0 0">
				0 von <?php echo esc_html( number_format_i18n( $lauf['gesamt'] ) ); ?> Zeilen
			</p>
			<p id="bi-imp-detail" style="color:#646970;margin:4px 0 0">Wird vorbereitet …</p>
			<div id="bi-imp-fertig" style="display:none;margin-top:16px"></div>
		</div>

		<script>
		( function () {
			var balken = document.getElementById( 'bi-imp-balken' );
			var zahl   = document.getElementById( 'bi-imp-zahl' );
			var detail = document.getElementById( 'bi-imp-detail' );
			var fertig = document.getElementById( 'bi-imp-fertig' );
			var gesamt = <?php echo (int) $lauf['gesamt']; ?>;

			function zeigen( d ) {
				balken.value = d.verarbeitet;
				zahl.textContent = d.verarbeitet.toLocaleString( 'de-DE' ) + ' von '
					+ gesamt.toLocaleString( 'de-DE' ) + ' Zeilen';
				detail.textContent = d.created.toLocaleString( 'de-DE' ) + ' neu · '
					+ d.updated.toLocaleString( 'de-DE' ) + ' aktualisiert · '
					+ d.skipped.toLocaleString( 'de-DE' ) + ' übersprungen';
			}

			function schritt() {
				var daten = new FormData();
				daten.append( 'action', 'bi_import_step' );
				daten.append( 'file', <?php echo wp_json_encode( $token ); ?> );
				daten.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'bi_import_step' ) ); ?> );

				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST', body: daten, credentials: 'same-origin'
				} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( antwort ) {
						if ( ! antwort || ! antwort.success ) {
							detail.textContent = ( antwort && antwort.data ) ? antwort.data : 'Der Import wurde unterbrochen.';
							detail.style.color = '#b32d2e';
							return;
						}
						if ( antwort.data.warten ) {
							// Ein anderes Fenster arbeitet – nur mitlesen, gleich wieder fragen.
							zeigen( antwort.data );
							detail.textContent = antwort.data.meldung;
							setTimeout( schritt, 2500 );
							return;
						}
						if ( ! antwort.data.anderswo ) {
							zeigen( antwort.data );
						}
						if ( antwort.data.fertig ) {
							balken.value = gesamt;
							detail.textContent = antwort.data.meldung;
							fertig.style.display = 'block';
							fertig.innerHTML = '<a class="button button-primary" href="'
								+ <?php echo wp_json_encode( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>
								+ '">Zu den importierten Einträgen</a>';
							return;
						}
						schritt();
					} )
					.catch( function () {
						detail.textContent = 'Verbindung unterbrochen. Seite neu laden, der Import setzt dort fort, wo er stand.';
						detail.style.color = '#b32d2e';
					} );
			}
			schritt();
		} )();
		</script>
		<?php
	}

	/** Ein Häppchen abarbeiten und den Stand zurückmelden. */
	public static function ajax_step() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_send_json_error( 'Keine Berechtigung.' );
		}
		check_ajax_referer( 'bi_import_step' );

		$token = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
		if ( '' === $token ) {
			wp_send_json_error( 'Der Import-Lauf ist nicht mehr auffindbar.' );
		}

		/*
		 * SPERRE JE LAUF. Die Fortschrittsseite startet ihre Schleife bei jedem
		 * Laden – wird sie neu geladen oder in einem zweiten Fenster geöffnet,
		 * laufen zwei Schleifen auf derselben Datei. Ohne Sperre verarbeiten
		 * beide dieselben Zeilen zur selben Zeit, und die Nummernprüfung der
		 * einen sieht den Eintrag der anderen noch nicht: jede Zeile zweimal,
		 * mit aufeinanderfolgenden IDs in derselben Sekunde (Berlin, 25.08.2026,
		 * 152 Dubletten). Jetzt darf nur eine Anfrage je Lauf Zeilen anfassen;
		 * die andere schaut zu und fragt später wieder.
		 */
		if ( ! self::sperre_setzen( $token ) ) {
			$lauf = get_transient( self::lauf_key( $token ) );
			wp_send_json_success( array(
				'warten'      => true,
				'fertig'      => false,
				'verarbeitet' => is_array( $lauf ) ? (int) $lauf['created'] + (int) $lauf['updated'] + (int) $lauf['skipped'] : 0,
				'gesamt'      => is_array( $lauf ) ? (int) $lauf['gesamt'] : 0,
				'created'     => is_array( $lauf ) ? (int) $lauf['created'] : 0,
				'updated'     => is_array( $lauf ) ? (int) $lauf['updated'] : 0,
				'skipped'     => is_array( $lauf ) ? (int) $lauf['skipped'] : 0,
				'meldung'     => 'Ein anderes Fenster verarbeitet diesen Import gerade – hier wird nur zugeschaut.',
			) );
		}

		// Ab hier gehört der Lauf dieser Anfrage. Kein wp_send_json_*() mehr vor
		// dem Lösen: wp_die() beendet PHP, ein finally liefe nicht mehr.
		$antwort = self::schritt( $token );
		self::sperre_loesen( $token );

		if ( empty( $antwort['ok'] ) ) {
			wp_send_json_error( $antwort['data'] );
		}
		wp_send_json_success( $antwort['data'] );
	}

	/**
	 * Ein Häppchen verarbeiten – unter der Sperre aus ajax_step().
	 *
	 * @return array ok (bool) + data (Antwort bzw. Fehlertext).
	 */
	private static function schritt( $token ) {
		$lauf = get_transient( self::lauf_key( $token ) );
		if ( ! is_array( $lauf ) ) {
			// Kein Lauf mehr: Ein anderes Fenster hat ihn zu Ende gebracht, oder
			// er ist verfallen. Für die Seite ist beides „fertig" – ein Fehler
			// wäre falsch, es ist ja nichts kaputt.
			return array( 'ok' => true, 'data' => array(
				'fertig'      => true,
				'anderswo'    => true,
				'verarbeitet' => 0, 'gesamt' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
				'meldung'     => 'Dieser Import ist bereits abgeschlossen – vermutlich in einem anderen Fenster. Die Zahlen stehen dort.',
			) );
		}

		$path = self::dir() . '/' . $lauf['token'];
		if ( ! file_exists( $path ) ) {
			delete_transient( self::lauf_key( $token ) );
			return array( 'ok' => false, 'data' => 'Die hochgeladene Datei ist nicht mehr da.' );
		}

		@set_time_limit( 0 );
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return array( 'ok' => false, 'data' => 'Die Datei lässt sich nicht lesen.' );
		}
		if ( $lauf['offset'] > 0 ) {
			fseek( $handle, (int) $lauf['offset'] );
		}

		$haeppchen = (int) apply_filters( 'bi_import_haeppchen', 40 );
		$n         = 0;

		while ( $n < $haeppchen && ( $row = fgetcsv( $handle, 0, $lauf['delim'] ) ) !== false ) {
			$lauf['zeile']++;

			// Erste Zeile: BOM entfernen, Kopfzeile überspringen.
			if ( 1 === $lauf['zeile'] ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				if ( $lauf['has_header'] ) {
					$lauf['offset'] = ftell( $handle );
					continue;
				}
			}

			self::verarbeite_zeile( $row, $lauf );
			$lauf['offset'] = ftell( $handle );
			$n++;
		}

		$fertig = feof( $handle );
		fclose( $handle );

		$verarbeitet = (int) $lauf['created'] + (int) $lauf['updated'] + (int) $lauf['skipped'];
		$meldung     = sprintf(
			'%s Einträge neu angelegt, %s aktualisiert, %s übersprungen.',
			number_format_i18n( $lauf['created'] ),
			number_format_i18n( $lauf['updated'] ),
			number_format_i18n( $lauf['skipped'] )
		);

		if ( $fertig ) {
			// Der Bestand ist ein anderer als vorher – ein Seiten-Cache wüsste
			// davon sonst nichts und lieferte den alten weiter aus.
			if ( class_exists( 'BI_Cache' ) ) {
				BI_Cache::leeren( true );
			}
			@unlink( $path );
			delete_transient( self::lauf_key( $token ) );
		} else {
			set_transient( self::lauf_key( $token ), $lauf, DAY_IN_SECONDS );
		}

		return array( 'ok' => true, 'data' => array(
			'fertig'      => $fertig,
			'verarbeitet' => $verarbeitet,
			'gesamt'      => (int) $lauf['gesamt'],
			'created'     => (int) $lauf['created'],
			'updated'     => (int) $lauf['updated'],
			'skipped'     => (int) $lauf['skipped'],
			'meldung'     => $meldung,
		) );
	}

	/** Schlüssel der Sperre eines Laufs (Zeile in wp_options, nicht autoload). */
	private static function sperre_key( $token ) {
		return 'bi_import_sperre_' . md5( (string) $token );
	}

	/**
	 * Sperre nehmen. Ein INSERT auf den eindeutigen option_name: Von zwei
	 * gleichzeitigen Anfragen gewinnt genau eine, die Datenbank entscheidet.
	 * Transients taugen dafür nicht – get_transient/set_transient sind zwei
	 * Schritte, und dazwischen passt die zweite Anfrage.
	 *
	 * Eine verwaiste Sperre (PHP mitten im Häppchen abgestürzt) verfällt nach
	 * zehn Minuten; so lange braucht kein Häppchen von 40 Zeilen.
	 */
	private static function sperre_setzen( $token ) {
		global $wpdb;
		$key = self::sperre_key( $token );

		$alt = $wpdb->get_var( $wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key
		) );
		if ( null !== $alt && (int) $alt < time() - 10 * MINUTE_IN_SECONDS ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $key ) );
		}

		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
			$key, (string) time()
		) );
		return (bool) $ok; // 0 betroffene Zeilen = jemand anders hält sie
	}

	private static function sperre_loesen( $token ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::sperre_key( $token ) ) );
	}

	/**
	 * Eine CSV-Zeile in einen Eintrag überführen. Zählt im Lauf mit.
	 *
	 * @param array $row  Zeile aus fgetcsv().
	 * @param array $lauf Laufzustand (per Referenz, die Zähler wandern mit).
	 */
	private static function verarbeite_zeile( $row, &$lauf ) {
		$map         = $lauf['map'];
		$post_type   = $lauf['post_type'];
		$date_format = $lauf['date_format'];

		$title = self::cell( $row, $map['title'] ?? '' );
		if ( '' === trim( $title ) ) {
			$lauf['skipped']++;
			return;
		}

		// Vorhandenen Eintrag per Seminarnummer finden – immer, das ist nicht
		// abschaltbar: Eine Seminarnummer kommt je Installation und Seminarform
		// genau einmal vor. Nur innerhalb desselben Beitragstyps – Präsenz und
		// Online dürfen dieselbe Nummer führen. Getrimmt, weil sanitize_text_field
		// beim Speichern ebenfalls trimmt: „B00027462 " und „B00027462" sind
		// dasselbe Seminar.
		$existing = 0;
		$nummer   = trim( self::cell( $row, $map['_bi_seminarnummer'] ?? '' ) );
		if ( '' !== $nummer ) {
			$existing = BI_CPT::post_zu_nummer( $nummer, $post_type );
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => wp_strip_all_tags( $title ),
			'post_content' => wp_kses_post( self::cell_roh( $row, $map['content'] ?? '' ) ),
			'post_status'  => $lauf['post_status'],
		);
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr );
		} else {
			$post_id = wp_insert_post( $postarr );
		}
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			$lauf['skipped']++;
			return;
		}
		if ( $existing ) {
			$lauf['updated']++;
		} else {
			$lauf['created']++;
		}

		// Meta-Felder
		foreach ( BI_CPT::meta_fields( $post_type ) as $key => $cfg ) {
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}
			update_post_meta( $post_id, $key, self::meta_wert( $row, $map[ $key ], $cfg, $date_format ) );
		}

		// Kein automatisches Ausblenden bei „Anmeldung nicht möglich": Solche
		// Seminare bleiben auf der Website, werden aber ausgegraut gezeigt – ohne
		// Buchen-Button (siehe BI_Filter::row und BI_Detail).
		// Wer nicht buchen kann, will trotzdem wissen, dass es das Seminar gibt.

		// Taxonomien
		$taxes = BI_CPT::taxonomies( $post_type );
		foreach ( array( BI_TAX_ORT, BI_TAX_THEMA, BI_TAX_ZIEL, BI_TAX_FREI, BI_TAX_PROGRAMM ) as $tax ) {
			$mkey = 'tax:' . $tax;
			if ( ! isset( $map[ $mkey ] ) ) {
				continue;
			}
			$raw   = self::cell( $row, $map[ $mkey ] );
			$multi = ! empty( $taxes[ $tax ]['multi'] );
			$terms = self::split_terms( $raw, $multi );
			// Nur bei Präsenz-Seminaren führt bi_ort das Bildungszentrum. Bei
			// Online-Seminaren steht dort die Veranstalter*in – die ist kein
			// Bildungszentrum und darf trotzdem nicht unter „Andere" landen.
			if ( BI_TAX_ORT === $tax && BI_CPT === $post_type ) {
				$terms = self::ort_terms( $terms, $post_id );
			}
			if ( $terms ) {
				wp_set_object_terms( $post_id, $terms, $tax, false );
			}
		}

		// Zuordnung zur Ausbildungsreihe aus „Teil | Reihe" auflösen. Legt die
		// Reihe als Entwurf an, wenn ihr Name zum ersten Mal auftaucht.
		BI_Reihen::zuordnen( $post_id );
	}

	/**
	 * Eine Zelle in den Wert verwandeln, der in der Datenbank steht – je nach
	 * Feldtyp. Voll-Import und Teil-Import benutzen dieselbe Umwandlung, damit
	 * „ja", „01.03.2027" oder „Teams Webinar" in beiden gleich ankommen.
	 */
	private static function meta_wert( $row, $idx, $cfg, $date_format = 'auto' ) {
		$val = self::cell( $row, $idx );
		switch ( $cfg['type'] ) {
			case 'html':
				// Maskierung erhalten – siehe cell()
				return wp_kses_post( self::cell_roh( $row, $idx ) );
			case 'date':
				return self::parse_date( $val, $date_format );
			case 'time':
				return self::parse_time( $val );
			case 'textarea':
				return sanitize_textarea_field( $val );
			case 'email':
				return sanitize_email( $val );
			case 'url':
				return esc_url_raw( trim( $val ) );
			case 'select':
				return self::parse_choice( $val, $cfg );
			case 'money':
				return BI_CPT::money_parse( $val );
			case 'bool':
				return self::parse_bool( $val, ! empty( $cfg['default'] ) ) ? '1' : '0';
			default:
				return sanitize_text_field( $val );
		}
	}

	/** ---------- Helfer ---------- */

	/** Trennzeichen erraten + Header + Beispielzeilen lesen */
	private static function peek( $path ) {
		$handle = fopen( $path, 'r' );
		$first  = fgets( $handle );
		$first  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $first );
		$delim  = ( substr_count( $first, ';' ) > substr_count( $first, ',' ) ) ? ';' : ',';
		rewind( $handle );

		$headers = array();
		$sample  = array();
		$i = 0;
		while ( ( $row = fgetcsv( $handle, 0, $delim ) ) !== false ) {
			if ( 0 === $i ) {
				$row[0]  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				$headers = $row;
			} elseif ( $i <= 5 ) {
				$sample[] = $row;
			} else {
				break;
			}
			$i++;
		}
		fclose( $handle );
		return array( $delim, $headers, $sample );
	}

	/**
	 * Ein Feld der CSV-Zeile als Klartext – HTML-Codes aufgelöst.
	 *
	 * Die Exportdatei des Seminarverwaltungssystems maskiert Sonderzeichen als
	 * HTML: Aus dem Gedankenstrich wird `&#8211;`, aus dem Und-Zeichen `&amp;`.
	 * In einem Titel oder einem Textfeld ist das kein Zeichen, sondern Text – die
	 * Ausgabe schickt ihn durch esc_html() und zeigt „Übergang in den Ruhestand
	 * &#8211; Aufgaben des BR", auf der Detailseite wie in Mails und PDF.
	 * Deshalb wird hier einmal dekodiert, bevor der Wert gespeichert wird.
	 *
	 * HTML-Felder holen ihren Wert über cell_roh(): Dort ist `&amp;` richtig
	 * notiert und wird vom Browser ohnehin als „&" gezeigt; ein Dekodieren machte
	 * aus maskiertem Text ungewollt echtes Markup.
	 */
	private static function cell( $row, $idx ) {
		return self::entfessle( self::cell_roh( $row, $idx ) );
	}

	/** Feld der CSV-Zeile, wie es dasteht (für HTML-Felder). */
	private static function cell_roh( $row, $idx ) {
		if ( '' === $idx || null === $idx ) {
			return '';
		}
		return (string) ( $row[ intval( $idx ) ] ?? '' );
	}

	/**
	 * HTML-Codes in Klartext auflösen („&#8211;" -> „–").
	 *
	 * Genau einmal, nicht in der Schleife bis nichts mehr übrig ist: „&amp;#8211;"
	 * bedeutet den Text „&#8211;" und soll er auch bleiben.
	 *
	 * Öffentlich, weil die Datenpflege dieselbe Umwandlung für bereits
	 * importierte Einträge braucht (BI_Datenpflege::textcodes).
	 */
	public static function entfessle( $wert ) {
		$wert = (string) $wert;
		if ( false === strpos( $wert, '&' ) ) {
			return $wert; // der Normalfall, ohne Arbeit
		}
		return html_entity_decode( $wert, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Datum in Y-m-d normalisieren */
	private static function parse_date( $val, $format ) {
		$val = trim( $val );
		if ( '' === $val ) {
			return '';
		}
		if ( 'auto' === $format ) {
			$ts = strtotime( str_replace( '.', '-', $val ) );
			if ( false === $ts ) {
				$ts = strtotime( $val );
			}
			return $ts ? gmdate( 'Y-m-d', $ts ) : '';
		}
		$dt = DateTime::createFromFormat( $format, $val );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	}

	/** Uhrzeit auf HH:MM normalisieren (akzeptiert "13:00", "13.00", "13 Uhr", "9:5") */
	private static function parse_time( $val ) {
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return '';
		}
		if ( preg_match( '/(\d{1,2})[:.\s]+(\d{1,2})/', $val, $m ) ) {
			return sprintf( '%02d:%02d', min( 23, (int) $m[1] ), min( 59, (int) $m[2] ) );
		}
		if ( preg_match( '/^(\d{1,2})$/', $val, $m ) ) {
			return sprintf( '%02d:00', min( 23, (int) $m[1] ) );
		}
		return '';
	}

	/**
	 * Auswahlfeld (Typ „select") aus einer CSV-Zelle bestimmen. Erlaubt sind der
	 * Schlüssel selbst (z. B. „teams_webinar") und die Beschriftung; verglichen wird
	 * normalisiert (Kleinschreibung, ohne Sonderzeichen), damit auch „Teams Webinar"
	 * oder „Teams – Webinar" trifft. Leere/unbekannte Zelle -> Default des Feldes.
	 */
	private static function parse_choice( $val, $cfg ) {
		$norm = function ( $s ) {
			$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s ) : strtolower( (string) $s );
			return preg_replace( '/[^a-z0-9]/', '', $s );
		};
		$needle  = $norm( $val );
		$default = isset( $cfg['default'] ) ? (string) $cfg['default'] : '';

		if ( '' === $needle ) {
			return $default;
		}
		foreach ( (array) $cfg['options'] as $key => $label ) {
			if ( $needle === $norm( $key ) || $needle === $norm( $label ) ) {
				return (string) $key;
			}
		}
		// Teiltreffer als zweite Chance (z. B. „Webinar" -> teams_webinar)
		foreach ( (array) $cfg['options'] as $key => $label ) {
			if ( '' !== $needle && false !== strpos( $norm( $label ), $needle ) ) {
				return (string) $key;
			}
		}
		return $default;
	}

	/**
	 * Mehrfachwerte einer Taxonomie-Zelle aufteilen.
	 *
	 * WICHTIG (Komma-Falle): In den Quelldaten trennt „, " (Komma + Leerzeichen) mehrere
	 * Werte, während interne Kommas auftreten als
	 *   - Dezimalkommas, z. B. „§ 37,6 BetrVG", „§ 179,4 SGB IX"  (Komma direkt vor Ziffer)
	 *   - Bestandteil EINES Einzelwerts, z. B. „Gesundheit, Prävention, Arbeitsschutz".
	 *
	 * Darum:
	 *   - Mehrfach-Taxonomie (multi): an Pipe ODER „Komma + Whitespace" trennen
	 *     -> Dezimalkommas (Komma+Ziffer, kein Space) bleiben erhalten.
	 *   - Einzelwert-Taxonomie: nur an Pipe trennen, Komma bleibt Teil des Werts.
	 */
	private static function split_terms( $raw, $multi ) {
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$pattern = $multi ? '/\s*\|\s*|,\s+/u' : '/\s*\|\s*/u';
		$parts   = preg_split( $pattern, $raw );
		return array_values( array_filter( array_map( 'trim', (array) $parts ), 'strlen' ) );
	}

	/**
	 * Begriffe der Taxonomie „Bildungszentrum" einsortieren.
	 *
	 * Die Taxonomie soll das ZUSTÄNDIGE Bildungszentrum führen – eine kurze,
	 * geschlossene Liste, die als Filter-Chip taugt. Steht in der Spalte etwas
	 * anderes (Hotel, Tagungshaus, „auf Anfrage"), landet der Eintrag unter
	 * „Andere". Sonst wächst der Chip mit jedem Einzelfall zu.
	 *
	 * Der Originalwert geht dabei nicht verloren: Steht für diesen Eintrag noch
	 * kein Seminarort, wird er dorthin gesichert und erscheint damit weiterhin
	 * als Ort auf der Detailseite.
	 *
	 * Maßgeblich ist, ob das FELD für diese Zeile leer ist – nicht, ob die Datei
	 * überhaupt eine Spalte dafür hat. Eine Datei mit Spalte, die in einzelnen
	 * Zeilen leer bleibt, hätte sonst genau dort den Ort verloren. Die
	 * Meta-Felder sind an dieser Stelle bereits geschrieben, der Wert der Zeile
	 * steht also schon da, falls die Datei ihn mitbringt.
	 *
	 * @param array $terms   Begriffe aus der Zelle.
	 * @param int   $post_id Betroffener Eintrag.
	 */
	private static function ort_terms( $terms, $post_id ) {
		$out = array();
		foreach ( $terms as $name ) {
			if ( BI_CPT::ist_bildungszentrum( $name ) ) {
				$out[] = $name;
				continue;
			}
			$out[] = BI_CPT::ANDERE;

			if ( '' === trim( (string) get_post_meta( $post_id, '_bi_seminarort', true ) ) ) {
				update_post_meta( $post_id, '_bi_seminarort', sanitize_text_field( $name ) );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Ja/Nein-Zelle interpretieren. Leere Zelle -> $default. */
	private static function parse_bool( $val, $default ) {
		$v = strtolower( trim( (string) $val ) );
		if ( '' === $v ) {
			return $default;
		}
		if ( in_array( $v, array( 'ja', 'yes', 'y', '1', 'true', 'wahr', 'x' ), true ) ) {
			return true;
		}
		if ( in_array( $v, array( 'nein', 'no', 'n', '0', 'false', 'falsch' ), true ) ) {
			return false;
		}
		return $default;
	}

	/** Auto-Mapping: Zielfeld-Label grob mit Header-Namen matchen */
	private static function guess_mapping( $targets, $col_options, $has_header ) {
		$guess = array();
		if ( ! $has_header ) {
			return $guess;
		}
		/*
		 * Überschrift auf eine Vergleichsform bringen: klein, ohne Umlaute,
		 * ohne Trennzeichen. „Übernachtung und Verpflegung" wird zu
		 * „ubernachtungundverpflegung".
		 *
		 * Die Umlaute werden VOR dem Kleinschreiben ersetzt, und zwar in beiden
		 * Schreibweisen. strtolower() arbeitet byteweise: Ein großes „Ü" ist in
		 * UTF-8 zwei Bytes und bleibt dabei unverändert stehen; die Ersetzung
		 * für das kleine „ü" greift dann nicht mehr, und der letzte Schritt
		 * wirft das Zeichen ersatzlos weg. Aus „Übernachtung" würde
		 * „bernachtung" – ein Name, den kein Alias trifft.
		 *
		 * Lange fiel das nicht auf, weil die Beschriftung des Zielfeldes
		 * dieselbe Behandlung erfährt: Zwei gleich verstümmelte Namen sind
		 * immer noch gleich. Erst als das Feld „Übernachtung und Verpflegung"
		 * in „Unterkunft" und „Verpflegung" geteilt wurde, musste der Alias
		 * greifen – und tat es nicht.
		 */
		$norm = function ( $s ) {
			$s = strtr( (string) $s, array(
				'Ä' => 'a', 'Ö' => 'o', 'Ü' => 'u',
				'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
			) );
			$s = strtolower( $s );
			return preg_replace( '/[^a-z0-9]/', '', $s );
		};
		$aliases = array(
			'title'             => array( 'titel', 'seminartitel', 'name', 'bezeichnung' ),
			'content'           => array( 'beschreibung', 'seminarbeschreibung', 'inhalt', 'text' ),
			'_bi_untertitel'    => array( 'untertitel', 'subtitel', 'subtitle' ),
			'_bi_startdatum'    => array( 'startdatum', 'start', 'von', 'datum', 'beginn' ),
			'_bi_enddatum'      => array( 'enddatum', 'ende', 'bis' ),
			'_bi_startuhrzeit'  => array( 'uhrzeitseminarbeginn', 'startuhrzeit', 'beginnuhrzeit', 'uhrzeitbeginn', 'startzeit' ),
			'_bi_enduhrzeit'    => array( 'uhrzeitseminarende', 'enduhrzeit', 'uhrzeitende', 'endzeit' ),
			'_bi_seminarnummer' => array( 'seminarnummer', 'nummer', 'nr', 'kursnummer', 'id' ),
			'_bi_plaetze'       => array( 'plaetze', 'plätze', 'freieplaetze', 'anzahlplaetze', 'kapazitaet' ),
			'_bi_kosten'        => array( 'kosten', 'preis', 'gebuehr', 'kostenhinweis' ),
			// Aufgeschlüsselte Kosten ab Programm 2027. Die Schreibweisen sind
			// normalisiert (Kleinschreibung, ohne Sonderzeichen, ü -> u).
			'_bi_kosten_seminar' => array( 'seminarkosten', 'seminargebuhr', 'seminargebuehr', 'kostenseminar' ),
			// Das Feld heißt seit 1.118.0 „Unterkunft"; die zusammengesetzten
			// Schreibweisen bleiben als Alias stehen, weil die Exportdateien der
			// Jahrgänge bis 2027 die Spalte weiterhin so überschreiben. Eine
			// Spalte, die BLOSS „Verpflegung" heißt, gehört dagegen an das neue
			// Feld darunter – deshalb steht sie hier nicht.
			'_bi_kosten_uev'         => array( 'unterkunft', 'ubernachtung', 'ubernachtungen', 'ubernachtungundverpflegung', 'ubernachtungverpflegung', 'unundverpflegung', 'unverpflegung', 'unterkunftundverpflegung', 'uev' ),
			'_bi_kosten_verpflegung' => array( 'verpflegung', 'essen', 'vollpension', 'verpflegungskosten' ),
			'_bi_kosten_tagung'  => array( 'tagungspauschale', 'tagungsgebuhr', 'tagungsgebuehr' ),
			'_bi_kosten_kur'     => array( 'kurbeitrag', 'kurtaxe' ),
			'_bi_kosten_mwst'    => array( 'mwst', 'mehrwertsteuer', 'ust', 'umsatzsteuer' ),
			'_bi_kinderbetreuung' => array( 'kinderbetreuung', 'kb' ),
			// Nur die zusammengesetzte Überschrift als Alias: eine Spalte, die
			// bloß „Reihe" heißt, trägt in den Quelldaten das Programmjahr.
			'_bi_teil_reihe'     => array( 'teilreihe' ),
			// „Seminarort" bezeichnet ab 2027 den tatsächlichen Veranstaltungsort.
			// Bis 2026 stand unter derselben Überschrift das Bildungszentrum –
			// beim Reimport einer alten Datei ist die Zuordnung deshalb von Hand
			// zu prüfen (die Maske zeigt sie an).
			'_bi_seminarort'    => array( 'seminarort', 'veranstaltungsort', 'tagungsort', 'ort', 'hotel' ),
			'_bi_referenten'    => array( 'referentinnen', 'referenten', 'referent', 'referentin', 'dozentinnen', 'dozenten' ),
			'_bi_themen'        => array( 'themenimseminar', 'themen', 'seminarthemen' ),
			'_bi_voraussetzungen' => array( 'voraussetzungen', 'voraussetzung', 'teilnahmevoraussetzungen', 'teilnahmevoraussetzung' ),
			'_bi_online_tool'   => array( 'webinartool', 'tool', 'plattform', 'meetingtool', 'format' ),
			'_bi_anmeldelink'   => array( 'anmeldelink', 'anmeldungslink', 'registrierungslink', 'anmeldeseite' ),
			'_bi_online_link'   => array( 'onlinelink', 'link', 'teilnahmelink', 'zugangslink' ),
			'_bi_ansprechpartner'       => array( 'ansprechpartner', 'ansprechpartnerin', 'ansprechpartnerinnen' ),
			// Im Online-Formular heißt dieselbe Adresse „Anmeldung"
			// Die Spalten „Anmeldung*" meinten immer die Anmeldestelle, nie eine
			// Person – sie zielen deshalb seit der Trennung (1.116.0) auf das
			// Bildungszentrums-Feld. Was in einer Datei „E-Mail Ansprechpartner"
			// heißt, bleibt beim Ansprechpartner.
			'_bi_ansprechpartner_email'   => array( 'emailansprechpartner', 'mailansprechpartner', 'ansprechpartneremail' ),
			'_bi_ansprechpartner_telefon' => array( 'telefonansprechpartner', 'ansprechpartnertelefon', 'telansprechpartner', 'telefonnummeransprechpartner' ),
			'_bi_bz_email'                => array( 'anmeldungemail', 'anmeldungmail', 'emailanmeldung', 'anmeldung', 'emailbildungszentrum', 'bildungszentrumemail', 'mailbildungszentrum' ),
			// Achtung: „ort" und „seminarort" gehören ab 2027 zum Meta-Feld
			// _bi_seminarort (tatsächlicher Veranstaltungsort). Hier steht nur
			// noch das zuständige Bildungszentrum bzw. die Veranstalter*in.
			'tax:' . BI_TAX_ORT   => array( 'bildungszentrum', 'zustaendigesbildungszentrum', 'zustandigesbildungszentrum', 'zustaendigesbiz', 'biz', 'standort', 'veranstalterin', 'veranstalter' ),
			'tax:' . BI_TAX_THEMA => array( 'handlungsfeld', 'themenfeld', 'thema', 'kategorie' ),
			'tax:' . BI_TAX_ZIEL  => array( 'zielgruppe', 'zielgruppen' ),
			'tax:' . BI_TAX_FREI  => array( 'freistellung', 'freistellungen' ),
			'tax:' . BI_TAX_PROGRAMM => array( 'programm', 'programmjahr', 'jahrgang', 'jahr' ),
		);
		foreach ( $targets as $key => $label ) {
			foreach ( $col_options as $i => $name ) {
				$n = $norm( $name );
				if ( $n === $norm( $label ) || in_array( $n, $aliases[ $key ] ?? array(), true ) ) {
					$guess[ $key ] = $i;
					break;
				}
			}
		}
		return $guess;
	}

	private static function redirect( $args, $msg = '' ) {
		if ( $msg ) {
			$args['bi_msg'] = rawurlencode( $msg );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Löscht ALLE Einträge eines Beitragstyps (alle Status) unwiderruflich. Geschützt per Nonce, Recht und Tipp-Bestätigung. */
	public static function handle_delete_all() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_delete_all_seminars' );

		$post_type = self::sanitize_pt( wp_unslash( $_POST['post_type'] ?? '' ) );
		$tab       = self::tab_for( $post_type );

		$confirm = isset( $_POST['confirm'] ) ? trim( wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 'LÖSCHEN' !== $confirm ) {
			self::redirect(
				array( 'page' => 'bi-einstellungen', 'tab' => $tab ),
				'Löschen abgebrochen – das Bestätigungswort „LÖSCHEN" wurde nicht eingegeben.'
			);
		}

		@set_time_limit( 0 );
		$ids = get_posts( array(
			'post_type'        => $post_type,
			'post_status'      => self::all_seminar_statuses(),
			'fields'           => 'ids',
			'numberposts'      => -1,
			'suppress_filters' => true,
		) );
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) { // true = endgültig, kein Papierkorb
				$deleted++;
			}
		}

		self::redirect(
			array( 'page' => 'bi-einstellungen', 'tab' => $tab ),
			sprintf( '%s Eintrag/Einträge endgültig gelöscht (%s).', number_format_i18n( $deleted ), self::label_for( $post_type ) )
		);
	}
}
