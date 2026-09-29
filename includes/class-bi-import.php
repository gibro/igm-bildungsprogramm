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
 *
 *  Nach jedem Lauf mit Treffern wird über BI_Ampel::nach_import() ein
 *  außerplanmäßiger Abgleich der Verfügbarkeits-Ampel angefordert – sonst
 *  stünden frisch importierte Termine bis zum nächsten nächtlichen Lauf ohne
 *  Verfügbarkeitsangabe da.
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
		add_action( 'admin_post_bi_teilimport', array( __CLASS__, 'handle_teilimport' ) );
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
				(<code>teams_webinar</code>, <code>teams_meeting</code> oder <code>anderes</code>)
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

		<?php self::render_teilimport( $post_type ); ?>

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
	 *  Teil-Import: einzelne Felder vorhandener Einträge nachtragen
	 * =================================================================== */

	/**
	 * WARUM ES DEN TEIL-IMPORT GIBT
	 *
	 * Der Import oben ist ein Voll-Import: Er schreibt bei einem vorhandenen
	 * Seminar immer auch Titel, Beschreibung und Status – aus der Spalte, die
	 * zugeordnet ist, und LEER, wenn keine zugeordnet ist. Eine Datei mit nur
	 * „Seminarnummer; Voraussetzungen" hätte damit jede Beschreibung gelöscht.
	 *
	 * Der Teil-Import fasst dagegen nur an, was in der Datei steht:
	 *   - Schlüssel ist die Seminarnummer; angelegt wird NIE etwas.
	 *   - Geschrieben werden nur Meta-Felder, deren Spalte erkannt wird (gleiche
	 *     Namen und Aliasse wie beim Voll-Import). Titel, Beschreibung, Status
	 *     und Begriffe bleiben unberührt, auch wenn die Datei solche Spalten hat.
	 *   - Leere Zellen lassen den vorhandenen Wert stehen – außer, der Haken
	 *     „Leere Zellen leeren das Feld" ist gesetzt.
	 *   - Eine Spalte „Form" (Präsenz / Online) lenkt jede Zeile in ihren
	 *     Beitragstyp. Ohne sie gilt der Reiter, in dem hochgeladen wird.
	 *
	 * Angelegt für das einmalige Nachtragen der Voraussetzungen 2027, aber für
	 * jedes Feld brauchbar.
	 */
	private static function render_teilimport( $post_type ) {
		?>
		<hr style="margin:28px 0">
		<h3>Nur einzelne Felder nachtragen (Teil-Import)</h3>
		<p style="max-width:820px">Aktualisiert <strong>vorhandene</strong> Einträge über die <strong>Seminarnummer</strong>
		   und schreibt <strong>nur</strong> die Felder, die als Spalte in der Datei stehen – zum Beispiel
		   <code>Seminarnummer;Voraussetzungen</code>. Titel, Beschreibung, Status und Begriffe bleiben unberührt,
		   neue Einträge werden nicht angelegt. Eine Spalte <code>Form</code> (Präsenz/Online) verteilt die Zeilen
		   auf beide Seminarformen; ohne sie gilt dieser Reiter.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="bi_teilimport">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( $post_type ); ?>">
			<?php wp_nonce_field( 'bi_teilimport' ); ?>
			<table class="form-table">
				<tr><th><label for="bi_teil_csv">CSV-Datei</label></th>
					<td><input type="file" name="bi_csv" id="bi_teil_csv" accept=".csv,text/csv" required>
						<p class="description">Erste Zeile = Spaltennamen, Trennzeichen <code>;</code> oder <code>,</code>, UTF-8.</p></td></tr>
				<tr><th>Leere Zellen</th>
					<td><label><input type="checkbox" name="leere" value="1"> leeren das Feld (sonst bleibt der vorhandene Wert stehen)</label></td></tr>
				<tr><th>Probelauf</th>
					<td><label><input type="checkbox" name="probe" value="1" checked> nur zählen, nichts speichern</label></td></tr>
			</table>
			<?php submit_button( 'Teil-Import starten', 'secondary' ); ?>
		</form>
		<?php
	}

	public static function handle_teilimport() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_teilimport' );

		$post_type = self::sanitize_pt( wp_unslash( $_POST['post_type'] ?? '' ) );
		$zurueck   = array( 'page' => 'bi-einstellungen', 'tab' => self::tab_for( $post_type ) );

		if ( empty( $_FILES['bi_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bi_csv']['tmp_name'] ) ) {
			self::redirect( $zurueck, 'Teil-Import: Keine Datei hochgeladen.' );
		}

		@set_time_limit( 0 );
		$ergebnis = self::teilimport_datei(
			$_FILES['bi_csv']['tmp_name'],
			$post_type,
			! empty( $_POST['leere'] ),
			! empty( $_POST['probe'] )
		);
		self::redirect( $zurueck, $ergebnis );
	}

	/**
	 * Die Arbeit des Teil-Imports; liefert die Meldung für die Seite.
	 *
	 * @param string $path      CSV-Datei.
	 * @param string $post_type Beitragstyp, wenn die Datei keine Spalte „Form" hat.
	 * @param bool   $leere     Leere Zellen leeren das Feld.
	 * @param bool   $probe     Nichts speichern, nur zählen.
	 */
	public static function teilimport_datei( $path, $post_type, $leere, $probe ) {
		list( $delim, $headers ) = self::peek( $path );
		if ( ! $headers ) {
			return 'Teil-Import: Die Datei ist leer.';
		}

		// Spalten je Beitragstyp erkennen – die Feldsets von Präsenz und Online
		// unterscheiden sich, die Schlüssel der gemeinsamen Felder nicht.
		$spalten = array();
		$felder  = array();
		foreach ( bi_seminar_post_types() as $pt ) {
			$map = self::guess_mapping( self::targets( $pt ), $headers, true );
			if ( ! isset( $map['_bi_seminarnummer'] ) ) {
				return 'Teil-Import: Keine Spalte „Seminarnummer" gefunden – sie ist der Schlüssel.';
			}
			$felder[ $pt ]  = BI_CPT::meta_fields( $pt );
			$spalten[ $pt ] = array();
			foreach ( $map as $key => $idx ) {
				// Nur Meta-Felder; die Nummer ist der Schlüssel, nicht das Ziel.
				if ( '_bi_seminarnummer' === $key || ! isset( $felder[ $pt ][ $key ] ) ) {
					continue;
				}
				$spalten[ $pt ][ $key ] = $idx;
			}
		}
		$nr_idx = self::guess_mapping( self::targets( $post_type ), $headers, true )['_bi_seminarnummer'];

		$form_idx = null;
		foreach ( $headers as $i => $h ) {
			if ( in_array( strtolower( trim( (string) $h ) ), array( 'form', 'seminarform' ), true ) ) {
				$form_idx = $i;
				break;
			}
		}

		$erkannt = array();
		foreach ( $spalten as $pt => $cols ) {
			foreach ( $cols as $key => $idx ) {
				$erkannt[ $key ] = $felder[ $pt ][ $key ]['label'];
			}
		}
		if ( ! $erkannt ) {
			return 'Teil-Import: Außer der Seminarnummer wurde keine Spalte einem Feld zugeordnet. Die Spaltennamen müssen wie die Feldnamen lauten.';
		}

		$stat      = array( 'aktualisiert' => 0, 'unveraendert' => 0, 'unbekannt' => 0, 'leer' => 0, 'werte' => 0 );
		$unbekannt = array();

		$handle = fopen( $path, 'r' );
		$zeile  = 0;
		while ( ( $row = fgetcsv( $handle, 0, $delim, '"', '\\' ) ) !== false ) {
			$zeile++;
			if ( 1 === $zeile ) {
				continue; // Kopfzeile
			}
			$nummer = trim( self::cell( $row, $nr_idx ) );
			if ( '' === $nummer ) {
				$stat['leer']++;
				continue;
			}
			$pt = $post_type;
			if ( null !== $form_idx ) {
				$form = strtolower( trim( self::cell( $row, $form_idx ) ) );
				$pt   = ( 0 === strpos( $form, 'online' ) ) ? BI_ONLINE : BI_CPT;
			}
			$post_id = BI_CPT::post_zu_nummer( $nummer, $pt );
			if ( ! $post_id ) {
				$stat['unbekannt']++;
				if ( count( $unbekannt ) < 8 ) {
					$unbekannt[] = $nummer;
				}
				continue;
			}

			$geaendert = false;
			foreach ( $spalten[ $pt ] as $key => $idx ) {
				$cfg = $felder[ $pt ][ $key ];
				$val = ( 'html' === $cfg['type'] ) ? self::cell_roh( $row, $idx ) : self::cell( $row, $idx );
				if ( '' === trim( $val ) && ! $leere ) {
					continue;
				}
				$neu = BI_Datenpflege::sanitize_meta( $val, $cfg );
				if ( (string) get_post_meta( $post_id, $key, true ) === (string) $neu ) {
					continue;
				}
				$stat['werte']++;
				$geaendert = true;
				if ( ! $probe ) {
					update_post_meta( $post_id, $key, $neu );
				}
			}
			$stat[ $geaendert ? 'aktualisiert' : 'unveraendert' ]++;
		}
		fclose( $handle );

		if ( ! $probe && $stat['aktualisiert'] && class_exists( 'BI_Cache' ) ) {
			BI_Cache::leeren( true );
		}

		$msg = sprintf(
			'%sTeil-Import (%s): %s Einträge %s, %s schon aktuell, %s Seminarnummern nicht gefunden.',
			$probe ? 'PROBELAUF – nichts gespeichert. ' : '',
			implode( ', ', $erkannt ),
			number_format_i18n( $stat['aktualisiert'] ),
			$probe ? 'würden geändert' : 'geändert',
			number_format_i18n( $stat['unveraendert'] ),
			number_format_i18n( $stat['unbekannt'] )
		);
		if ( $unbekannt ) {
			$msg .= ' Zum Beispiel: ' . implode( ', ', $unbekannt ) . ( $stat['unbekannt'] > count( $unbekannt ) ? ' …' : '' ) . '.';
		}
		if ( $stat['leer'] ) {
			$msg .= ' ' . number_format_i18n( $stat['leer'] ) . ' Zeilen ohne Seminarnummer übersprungen.';
		}
		return $msg;
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
			// Ein Import bringt neue Seminarnummern mit, zu denen noch keine
			// Verfügbarkeit vorliegt. Deshalb einen außerplanmäßigen Abgleich
			// anfordern, statt bis zum nächsten nächtlichen Lauf zu warten.
			if ( $lauf['created'] || $lauf['updated'] ) {
				$hinweis = BI_Ampel::nach_import();
				if ( $hinweis ) {
					$meldung .= ' ' . $hinweis;
				}
			}
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
			$val = self::cell( $row, $map[ $key ] );
			switch ( $cfg['type'] ) {
				case 'html':
					// Maskierung erhalten – siehe cell()
					update_post_meta( $post_id, $key, wp_kses_post( self::cell_roh( $row, $map[ $key ] ) ) );
					break;
				case 'date':
					update_post_meta( $post_id, $key, self::parse_date( $val, $date_format ) );
					break;
				case 'time':
					update_post_meta( $post_id, $key, self::parse_time( $val ) );
					break;
				case 'textarea':
					update_post_meta( $post_id, $key, sanitize_textarea_field( $val ) );
					break;
				case 'email':
					update_post_meta( $post_id, $key, sanitize_email( $val ) );
					break;
				case 'url':
					update_post_meta( $post_id, $key, esc_url_raw( trim( $val ) ) );
					break;
				case 'select':
					update_post_meta( $post_id, $key, self::parse_choice( $val, $cfg ) );
					break;
				case 'money':
					update_post_meta( $post_id, $key, BI_CPT::money_parse( $val ) );
					break;
				case 'bool':
					update_post_meta( $post_id, $key, self::parse_bool( $val, ! empty( $cfg['default'] ) ) ? '1' : '0' );
					break;
				default:
					update_post_meta( $post_id, $key, sanitize_text_field( $val ) );
			}
		}

		// Stand der Haken „Ausgebucht" in der Datei, ist das eine frische Aussage
		// der Programmplanung – keine Korrektur an der Ampel. Ihre Notiz wird
		// deshalb vergessen, damit der nächste Abgleich wieder ohne Rückfrage
		// schreiben darf (siehe BI_Ampel::hand_zuruecksetzen).
		if ( isset( $map['_bi_ausgebucht'] ) && class_exists( 'BI_Ampel' ) ) {
			BI_Ampel::hand_zuruecksetzen( $post_id );
		}

		// Kein automatisches Ausblenden bei „Anmeldung nicht möglich": Solche
		// Seminare bleiben auf der Website, werden aber ausgegraut gezeigt – ohne
		// Ampel und ohne Buchen-Button (siehe BI_Filter::row und BI_Detail).
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
