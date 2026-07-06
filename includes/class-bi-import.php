<?php
/**
 * Seminar-Import aus CSV mit Spalten-Mapping (zweistufig).
 *
 *  Schritt 1: CSV hochladen -> Datei wird in uploads/bi-import/ zwischengelagert,
 *             Kopfzeile wird gelesen, Mapping-Formular angezeigt.
 *  Schritt 2: Nutzer ordnet jede Zielspalte einer CSV-Spalte zu -> Import.
 *
 *  Zielfelder:
 *    title, content (Post), _bi_* (Meta), bi_ort/bi_handlungsfeld/
 *    bi_zielgruppe/bi_freistellung (Taxonomie; mehrfach mit | oder , getrennt).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Import {

	public static function init() {
		add_action( 'admin_post_bi_import_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_bi_import_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_bi_delete_all_seminars', array( __CLASS__, 'handle_delete_all' ) );
	}

	/** Alle Status, die als „Seminar in der Datenbank" gelten (für Zählen + Löschen). */
	private static function all_seminar_statuses() {
		return array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' );
	}

	/** Anzahl aller Seminare (alle Status) – für Anzeige im Lösch-Block. */
	private static function seminar_total() {
		return count( get_posts( array(
			'post_type'        => BI_CPT,
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
	public static function targets() {
		$t = array(
			'title'   => 'Seminartitel (Pflicht)',
			'content' => 'Seminarbeschreibung',
		);
		foreach ( BI_CPT::meta_fields() as $key => $cfg ) {
			$t[ $key ] = $cfg['label'];
		}
		$t[ 'tax:' . BI_TAX_ORT ]      = 'Bildungszentrum / Seminarort';
		$t[ 'tax:' . BI_TAX_THEMA ]    = 'Handlungsfeld';
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

	public static function render_section() {
		$step = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( $_GET['step'] ) ) : 'upload';
		echo '<div><h2 class="title">Seminare importieren</h2>';
		if ( 'map' === $step ) {
			self::render_mapping();
		} else {
			self::render_upload();
		}
		echo '</div>';
	}

	private static function render_upload() {
		?>
		<p>Lade eine CSV-Datei hoch. Im nächsten Schritt ordnest du die Spalten den Seminar-Feldern zu.
		   Mehrfach-Felder (Zielgruppe, Freistellung) dürfen in einer Zelle mehrere Werte enthalten – getrennt mit <code>|</code> oder <code>,</code>.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="bi_import_upload">
			<?php wp_nonce_field( 'bi_import_upload' ); ?>
			<table class="form-table">
				<tr><th><label for="bi_csv">CSV-Datei</label></th>
					<td><input type="file" name="bi_csv" id="bi_csv" accept=".csv,text/csv" required></td></tr>
				<tr><th>Erste Zeile</th>
					<td><label><input type="checkbox" name="has_header" value="1" checked> ist eine Kopfzeile (Spaltennamen)</label></td></tr>
			</table>
			<?php submit_button( 'Weiter zur Zuordnung' ); ?>
		</form>

		<?php $total = self::seminar_total(); ?>
		<hr style="margin:28px 0">
		<div style="border:1px solid #d63638;border-left-width:4px;background:#fcf0f1;padding:14px 18px;max-width:760px">
			<h3 style="margin-top:0;color:#d63638">Alle Seminare löschen</h3>
			<p>Entfernt <strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong> Seminar(e) aller Status
			   <strong>unwiderruflich</strong> aus der Datenbank (inkl. Termine/Metadaten). Taxonomie-Begriffe
			   (Bildungszentren, Themenfelder …) und Anmeldungen bleiben erhalten. <strong>Vorher ein Backup anlegen.</strong></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			      onsubmit="return confirm('Wirklich ALLE <?php echo (int) $total; ?> Seminare endgültig löschen?\nDas kann nicht rückgängig gemacht werden.');">
				<input type="hidden" name="action" value="bi_delete_all_seminars">
				<?php wp_nonce_field( 'bi_delete_all_seminars' ); ?>
				<p style="margin:0 0 10px"><label>Zum Bestätigen <code>LÖSCHEN</code> eintippen:<br>
					<input type="text" name="confirm" autocomplete="off" placeholder="LÖSCHEN" style="margin-top:4px;width:200px"></label></p>
				<?php submit_button( 'Alle Seminare löschen', 'delete', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_mapping() {
		$token  = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		$path   = self::dir() . '/' . $token;
		if ( ! $token || ! file_exists( $path ) ) {
			echo '<div class="notice notice-error"><p>Hochgeladene Datei nicht gefunden. Bitte erneut hochladen.</p></div>';
			self::render_upload();
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
		$guess = self::guess_mapping( self::targets(), $col_options, $has_header );
		?>
		<p><strong>Erkanntes Trennzeichen:</strong> <code><?php echo esc_html( $delim ); ?></code> ·
		   <strong>Spalten:</strong> <?php echo count( $headers ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_import_run">
			<input type="hidden" name="file" value="<?php echo esc_attr( $token ); ?>">
			<input type="hidden" name="header" value="<?php echo $has_header ? 1 : 0; ?>">
			<?php wp_nonce_field( 'bi_import_run' ); ?>
			<table class="form-table">
				<?php foreach ( self::targets() as $key => $label ) : ?>
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
					<th>Status der neuen Seminare</th>
					<td>
						<select name="post_status">
							<option value="publish">Veröffentlicht</option>
							<option value="draft">Entwurf</option>
						</select>
					</td>
				</tr>
				<tr>
					<th>Duplikate</th>
					<td><label><input type="checkbox" name="dedupe" value="1" checked>
						vorhandene Seminare mit gleicher Seminarnummer aktualisieren statt doppelt anlegen</label></td>
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
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_import_upload' );

		if ( empty( $_FILES['bi_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bi_csv']['tmp_name'] ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => 'seminarimport' ), 'Keine Datei empfangen.' );
		}
		$token = 'csv-' . wp_generate_password( 8, false ) . '.csv';
		$dest  = self::dir() . '/' . $token;
		if ( ! move_uploaded_file( $_FILES['bi_csv']['tmp_name'], $dest ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => 'seminarimport' ), 'Datei konnte nicht gespeichert werden.' );
		}
		self::redirect( array(
			'page'   => 'bi-einstellungen', 'tab' => 'seminarimport',
			'step'   => 'map',
			'file'   => $token,
			'header' => empty( $_POST['has_header'] ) ? 0 : 1,
		) );
	}

	/** ---------- Schritt 2: Import ---------- */

	public static function handle_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_import_run' );

		$token = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
		$path  = self::dir() . '/' . $token;
		if ( ! $token || ! file_exists( $path ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => 'seminarimport' ), 'Datei nicht gefunden.' );
		}

		$map         = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array();
		$has_header  = ! empty( $_POST['header'] );
		$date_format = sanitize_text_field( wp_unslash( $_POST['date_format'] ?? 'auto' ) );
		$post_status = ( ( $_POST['post_status'] ?? 'publish' ) === 'draft' ) ? 'draft' : 'publish';
		$dedupe      = ! empty( $_POST['dedupe'] );

		if ( empty( $map['title'] ) && '0' !== ( $map['title'] ?? '' ) ) {
			self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => 'seminarimport', 'step' => 'map', 'file' => $token, 'header' => $has_header ? 1 : 0 ),
				'Bitte die Spalte für den Seminartitel zuordnen.' );
		}

		list( $delim ) = self::peek( $path );
		$handle = fopen( $path, 'r' );
		$created = 0; $updated = 0; $skipped = 0;
		$line = 0;

		while ( ( $row = fgetcsv( $handle, 0, $delim ) ) !== false ) {
			$line++;
			if ( 1 === $line ) {
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				if ( $has_header ) {
					continue;
				}
			}
			$title = self::cell( $row, $map['title'] ?? '' );
			if ( '' === trim( $title ) ) {
				$skipped++;
				continue;
			}

			// Vorhandenes Seminar per Seminarnummer finden?
			$existing = 0;
			$nummer   = self::cell( $row, $map['_bi_seminarnummer'] ?? '' );
			if ( $dedupe && '' !== trim( $nummer ) ) {
				$found = get_posts( array(
					'post_type'   => BI_CPT,
					'post_status' => 'any',
					'numberposts' => 1,
					'fields'      => 'ids',
					'meta_key'    => '_bi_seminarnummer',
					'meta_value'  => $nummer,
				) );
				$existing = $found ? (int) $found[0] : 0;
			}

			$postarr = array(
				'post_type'    => BI_CPT,
				'post_title'   => wp_strip_all_tags( $title ),
				'post_content' => wp_kses_post( self::cell( $row, $map['content'] ?? '' ) ),
				'post_status'  => $post_status,
			);
			if ( $existing ) {
				$postarr['ID'] = $existing;
				$post_id = wp_update_post( $postarr );
				$updated++;
			} else {
				$post_id = wp_insert_post( $postarr );
				$created++;
			}
			if ( ! $post_id || is_wp_error( $post_id ) ) {
				$skipped++;
				continue;
			}

			// Meta-Felder
			foreach ( BI_CPT::meta_fields() as $key => $cfg ) {
				if ( ! isset( $map[ $key ] ) || '' === $map[ $key ] ) {
					continue;
				}
				$val = self::cell( $row, $map[ $key ] );
				switch ( $cfg['type'] ) {
					case 'date':
						update_post_meta( $post_id, $key, self::parse_date( $val, $date_format ) );
						break;
					case 'time':
						update_post_meta( $post_id, $key, self::parse_time( $val ) );
						break;
					case 'html':
						update_post_meta( $post_id, $key, wp_kses_post( $val ) );
						break;
					case 'textarea':
						update_post_meta( $post_id, $key, sanitize_textarea_field( $val ) );
						break;
					case 'email':
						update_post_meta( $post_id, $key, sanitize_email( $val ) );
						break;
					case 'bool':
						update_post_meta( $post_id, $key, self::parse_bool( $val, ! empty( $cfg['default'] ) ) ? '1' : '0' );
						break;
					default:
						update_post_meta( $post_id, $key, sanitize_text_field( $val ) );
				}
			}

			// Taxonomien
			$taxes = BI_CPT::taxonomies();
			foreach ( array( BI_TAX_ORT, BI_TAX_THEMA, BI_TAX_ZIEL, BI_TAX_FREI, BI_TAX_PROGRAMM ) as $tax ) {
				$mkey = 'tax:' . $tax;
				if ( ! isset( $map[ $mkey ] ) || '' === $map[ $mkey ] ) {
					continue;
				}
				$raw   = self::cell( $row, $map[ $mkey ] );
				$multi = ! empty( $taxes[ $tax ]['multi'] );
				$terms = self::split_terms( $raw, $multi );
				if ( $terms ) {
					wp_set_object_terms( $post_id, $terms, $tax, false );
				}
			}
		}
		fclose( $handle );
		@unlink( $path );

		self::redirect( array( 'page' => 'bi-einstellungen', 'tab' => 'seminarimport' ),
			sprintf( '%d Seminare neu angelegt, %d aktualisiert, %d übersprungen.', $created, $updated, $skipped ) );
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

	private static function cell( $row, $idx ) {
		if ( '' === $idx || null === $idx ) {
			return '';
		}
		return (string) ( $row[ intval( $idx ) ] ?? '' );
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
		$norm = function ( $s ) {
			$s = strtolower( $s );
			$s = strtr( $s, array( 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss' ) );
			return preg_replace( '/[^a-z0-9]/', '', $s );
		};
		$aliases = array(
			'title'             => array( 'titel', 'seminartitel', 'name', 'bezeichnung' ),
			'content'           => array( 'beschreibung', 'seminarbeschreibung', 'inhalt', 'text' ),
			'_bi_startdatum'    => array( 'startdatum', 'start', 'von', 'datum', 'beginn' ),
			'_bi_enddatum'      => array( 'enddatum', 'ende', 'bis' ),
			'_bi_seminarnummer' => array( 'seminarnummer', 'nummer', 'nr', 'kursnummer', 'id' ),
			'_bi_plaetze'       => array( 'plaetze', 'plätze', 'freieplaetze', 'kapazitaet' ),
			'_bi_kosten'        => array( 'kosten', 'preis', 'gebuehr' ),
			'tax:' . BI_TAX_ORT   => array( 'bildungszentrum', 'ort', 'seminarort', 'standort' ),
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

	/** Löscht ALLE Seminare (alle Status) unwiderruflich. Geschützt per Nonce, Recht und Tipp-Bestätigung. */
	public static function handle_delete_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_delete_all_seminars' );

		$confirm = isset( $_POST['confirm'] ) ? trim( wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 'LÖSCHEN' !== $confirm ) {
			self::redirect(
				array( 'page' => 'bi-einstellungen', 'tab' => 'seminarimport' ),
				'Löschen abgebrochen – das Bestätigungswort „LÖSCHEN" wurde nicht eingegeben.'
			);
		}

		@set_time_limit( 0 );
		$ids = get_posts( array(
			'post_type'        => BI_CPT,
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
			array( 'page' => 'bi-einstellungen', 'tab' => 'seminarimport' ),
			sprintf( '%s Seminar(e) endgültig gelöscht.', number_format_i18n( $deleted ) )
		);
	}
}
