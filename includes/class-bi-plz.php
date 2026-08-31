<?php
/**
 * PLZ -> Geschäftsstelle-Zuordnung.
 *
 * Tabelle wp_bi_plz: plz (5-stellig, indexiert), geschaeftsstelle, email.
 * Import aus CSV (Spalten frei wählbar: PLZ, Geschäftsstelle, E-Mail).
 * Lookup BI_PLZ::lookup('01067') => ['geschaeftsstelle'=>'Dresden','email'=>'dresden@igmetall.de'] | null
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_PLZ {

	public static function init() {
		add_action( 'admin_post_bi_import_plz', array( __CLASS__, 'handle_import' ) );
		// Frontend-Lookup für die Geschäftsstellen-Anfrage auf der Detailseite.
		// Bewusst ohne Nonce: rein lesend, öffentliche Daten, muss Seiten-Caching überleben.
		add_action( 'wp_ajax_bi_plz_lookup', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_nopriv_bi_plz_lookup', array( __CLASS__, 'ajax_lookup' ) );
	}

	/** AJAX: PLZ -> { geschaeftsstelle, email } */
	public static function ajax_lookup() {
		// Die Antwort enthält die Mailadresse der Geschäftsstelle – die braucht der
		// mailto:-Button auf der Detailseite, sie steht ohnehin öffentlich auf
		// igmetall.de. Der PLZ-Raum ist aber endlich: ohne Drosselung ließe sich das
		// gesamte Verzeichnis in wenigen Minuten abfragen. Das Kontingent liegt weit
		// über jeder normalen Nutzung – dort wird eine, selten zwei PLZ gesucht.
		if ( ! bi_rate_hit( 'bi_plz|' . bi_client_ip(), (int) apply_filters( 'bi_limit_plz_lookups', 60 ), HOUR_IN_SECONDS ) ) {
			wp_send_json_error( array( 'message' => 'Zu viele Abfragen in kurzer Zeit. Bitte versuche es später erneut.' ), 429 );
		}

		$row = self::lookup( sanitize_text_field( bi_get( 'plz' ) ) );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => 'Zu dieser Postleitzahl wurde keine Geschäftsstelle gefunden.' ) );
		}
		wp_send_json_success( array(
			'geschaeftsstelle' => $row['geschaeftsstelle'],
			'email'            => $row['email'],
		) );
	}

	public static function table() {
		return bi_table( 'plz' );
	}

	/** Tabelle bei Aktivierung anlegen */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plz VARCHAR(5) NOT NULL,
			geschaeftsstelle VARCHAR(190) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			UNIQUE KEY plz (plz),
			KEY gs (geschaeftsstelle)
		) $charset;";
		dbDelta( $sql );
	}

	/** Lookup einer einzelnen PLZ */
	public static function lookup( $plz ) {
		global $wpdb;
		$plz = preg_replace( '/\D/', '', (string) $plz );
		if ( strlen( $plz ) < 5 ) {
			return null;
		}
		$plz = substr( $plz, 0, 5 );
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT geschaeftsstelle, email FROM ' . self::table() . ' WHERE plz = %s',
			$plz
		), ARRAY_A );
		return $row ?: null;
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/** ---------- Admin-Bereich (eingebettet in Einstellungen) ---------- */

	public static function render_section() {
		$count = self::count();
		?>
		<div>
			<h2 class="title">PLZ-Zuordnung importieren</h2>
			<p>Aktuell sind <strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong> Postleitzahlen hinterlegt.</p>
			<p>CSV-Datei mit drei Spalten: <code>PLZ</code>, <code>Geschäftsstelle</code>, <code>E-Mail</code>.
			   Trennzeichen <code>,</code> oder <code>;</code> wird automatisch erkannt. Bestehende PLZ werden aktualisiert.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="bi_import_plz">
				<?php wp_nonce_field( 'bi_import_plz' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="bi_plz_csv">CSV-Datei</label></th>
						<td><input type="file" name="bi_plz_csv" id="bi_plz_csv" accept=".csv,text/csv" required></td>
					</tr>
					<tr>
						<th>Spaltenreihenfolge</th>
						<td>
							<label>PLZ-Spalte <input type="number" name="col_plz" value="1" min="1" style="width:70px"></label>
							&nbsp;<label>Geschäftsstelle <input type="number" name="col_gs" value="2" min="1" style="width:70px"></label>
							&nbsp;<label>E-Mail <input type="number" name="col_mail" value="3" min="1" style="width:70px"></label>
							<p class="description">Spalten-Nummern (1-basiert). Eine Kopfzeile wird automatisch übersprungen, wenn sie keine gültige PLZ enthält.</p>
						</td>
					</tr>
					<tr>
						<th>Vor Import leeren?</th>
						<td><label><input type="checkbox" name="truncate" value="1"> Bestehende Tabelle vorher komplett leeren</label></td>
					</tr>
				</table>
				<?php submit_button( 'CSV importieren' ); ?>
			</form>
		</div>
		<?php
	}

	/** ---------- Import verarbeiten ---------- */

	public static function handle_import() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_import_plz' );

		if ( empty( $_FILES['bi_plz_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['bi_plz_csv']['tmp_name'] ) ) {
			self::redirect( 'Keine Datei empfangen.' );
		}

		$col_plz  = max( 1, intval( $_POST['col_plz'] ?? 1 ) ) - 1;
		$col_gs   = max( 1, intval( $_POST['col_gs'] ?? 2 ) ) - 1;
		$col_mail = max( 1, intval( $_POST['col_mail'] ?? 3 ) ) - 1;
		$truncate = ! empty( $_POST['truncate'] );

		global $wpdb;
		$table = self::table();
		if ( $truncate ) {
			$wpdb->query( "TRUNCATE TABLE $table" );
		}

		$handle = fopen( $_FILES['bi_plz_csv']['tmp_name'], 'r' );
		if ( ! $handle ) {
			self::redirect( 'Datei konnte nicht gelesen werden.' );
		}

		// Trennzeichen aus der ersten Zeile erraten
		$first = fgets( $handle );
		$first = self::strip_bom( $first );
		$delim = ( substr_count( $first, ';' ) > substr_count( $first, ',' ) ) ? ';' : ',';
		rewind( $handle );

		$imported = 0;
		$skipped  = 0;
		$first_line = true;
		while ( ( $row = fgetcsv( $handle, 0, $delim ) ) !== false ) {
			if ( $first_line ) {
				$row[0]     = self::strip_bom( $row[0] );
				$first_line = false;
			}
			$plz = preg_replace( '/\D/', '', (string) ( $row[ $col_plz ] ?? '' ) );
			if ( strlen( $plz ) < 4 ) { // Kopfzeile / Leerzeile überspringen
				$skipped++;
				continue;
			}
			$plz   = str_pad( substr( $plz, 0, 5 ), 5, '0', STR_PAD_LEFT );
			$gs    = trim( (string) ( $row[ $col_gs ] ?? '' ) );
			$mail  = sanitize_email( trim( (string) ( $row[ $col_mail ] ?? '' ) ) );

			$wpdb->replace( $table, array(
				'plz'              => $plz,
				'geschaeftsstelle' => $gs,
				'email'            => $mail,
			), array( '%s', '%s', '%s' ) );
			$imported++;
		}
		fclose( $handle );

		self::redirect( sprintf( '%d PLZ importiert/aktualisiert, %d Zeilen übersprungen.', $imported, $skipped ) );
	}

	private static function strip_bom( $s ) {
		return preg_replace( '/^\xEF\xBB\xBF/', '', (string) $s );
	}

	private static function redirect( $msg ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-einstellungen', 'tab' => 'plzimport', 'bi_msg' => rawurlencode( $msg ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
