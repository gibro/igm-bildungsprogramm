<?php
/**
 * Aufbewahrungsfrist für Anmeldungen (SEC-011).
 *
 * Anmeldungen enthalten vollständige private und dienstliche Kontaktdaten sowie
 * die Angabe zur Gewerkschaftsmitgliedschaft – nach Art. 9 DSGVO eine besondere
 * Kategorie personenbezogener Daten. Ohne Frist wächst der Bestand unbegrenzt und
 * mit ihm der Schaden eines späteren Vorfalls in WordPress, Datenbank oder Backup.
 *
 * ── Woran die Frist hängt ────────────────────────────────────────────────────
 * Gerechnet wird ab SEMINARENDE, nicht ab Anmeldedatum. Das Bildungsprogramm ist
 * bis über ein Jahr im Voraus buchbar; ab Anmeldedatum gerechnet würden
 * Anmeldungen gelöscht, lange bevor das Seminar überhaupt stattfindet.
 *
 * Maßgeblich ist in dieser Reihenfolge:
 *   1. _bi_enddatum des Seminars
 *   2. _bi_startdatum des Seminars (wenn kein Ende hinterlegt ist)
 *   3. das Anmeldedatum (wenn das Seminar nicht mehr existiert)
 *
 * ── Schonfrist vor dem ersten Lauf ───────────────────────────────────────────
 * Beim ersten Aktivieren steht sofort ein Altbestand zur Löschung an. Damit das
 * niemanden unvorbereitet trifft, läuft die Bereinigung erst nach GRACE_DAYS an;
 * bis dahin weist eine Meldung im Backend auf die Zahl der betroffenen
 * Anmeldungen hin. Die Frist lässt sich in dieser Zeit noch ändern.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Retention {

	const HOOK        = 'bi_retention_cleanup';
	const START_OPTION = 'bi_retention_start';   // Zeitpunkt, ab dem gelöscht wird
	const LOG_OPTION   = 'bi_retention_last';    // Ergebnis des letzten Laufs
	const GRACE_DAYS   = 7;
	const BATCH        = 200;                    // Höchstzahl je Lauf

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );
		add_action( 'admin_notices', array( __CLASS__, 'grace_notice' ) );
		add_action( 'admin_post_bi_retention_run', array( __CLASS__, 'handle_run' ) );
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
		// Schonfrist beim ersten Mal setzen – ab dann wird tatsächlich gelöscht.
		if ( ! get_option( self::START_OPTION ) ) {
			update_option( self::START_OPTION, time() + self::GRACE_DAYS * DAY_IN_SECONDS, false );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Aufbewahrungsdauer in Tagen (0 = Bereinigung aus) */
	public static function days() {
		return max( 0, (int) BI_Settings::get( 'retention_days' ) );
	}

	/** 'delete' = Zeile entfernen, 'anonymize' = Personenbezug entfernen, Zeile behalten */
	public static function mode() {
		return ( 'anonymize' === BI_Settings::get( 'retention_mode' ) ) ? 'anonymize' : 'delete';
	}

	/** Zeitpunkt, ab dem automatisch bereinigt wird (0 = noch nicht gesetzt) */
	public static function starts_at() {
		return (int) get_option( self::START_OPTION, 0 );
	}

	public static function in_grace_period() {
		$start = self::starts_at();
		return $start && time() < $start;
	}

	/** Stichtag: alles, dessen maßgebliches Datum davor liegt, ist fällig */
	public static function cutoff() {
		return gmdate( 'Y-m-d', current_time( 'timestamp' ) - self::days() * DAY_IN_SECONDS );
	}

	/**
	 * IDs fälliger Anmeldungen.
	 *
	 * COALESCE bildet die oben beschriebene Reihenfolge ab. NULLIF fängt leere
	 * Meta-Werte ab: ein hinterlegtes, aber leeres _bi_enddatum darf nicht dazu
	 * führen, dass eine Anmeldung nie fällig wird.
	 */
	public static function due_ids( $limit = self::BATCH ) {
		global $wpdb;
		if ( ! self::days() ) {
			return array();
		}
		$sql = $wpdb->prepare(
			'SELECT DISTINCT a.id
			   FROM ' . BI_Registration::table() . ' a
			   LEFT JOIN ' . $wpdb->postmeta . ' e ON e.post_id = a.seminar_id AND e.meta_key = %s
			   LEFT JOIN ' . $wpdb->postmeta . ' s ON s.post_id = a.seminar_id AND s.meta_key = %s
			  WHERE COALESCE( NULLIF( e.meta_value, %s ), NULLIF( s.meta_value, %s ), DATE( a.created ) ) < %s
			    AND a.status <> %s
			  ORDER BY a.id ASC
			  LIMIT %d',
			'_bi_enddatum',
			'_bi_startdatum',
			'',
			'',
			self::cutoff(),
			'anonymisiert',
			(int) $limit
		);
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Wie viele Anmeldungen sind derzeit fällig?
	 *
	 * Kurz zwischengespeichert: Der Hinweis im Backend fragt das sonst auf jeder
	 * einzelnen Adminseite neu ab, und die Abfrage verbindet zwei Tabellen.
	 */
	public static function count_due( $cached = true ) {
		global $wpdb;
		if ( ! self::days() ) {
			return 0;
		}
		$ckey = 'bi_retention_due';
		if ( $cached ) {
			$hit = get_transient( $ckey );
			if ( false !== $hit ) {
				return (int) $hit;
			}
		}
		$sql = $wpdb->prepare(
			'SELECT COUNT( DISTINCT a.id )
			   FROM ' . BI_Registration::table() . ' a
			   LEFT JOIN ' . $wpdb->postmeta . ' e ON e.post_id = a.seminar_id AND e.meta_key = %s
			   LEFT JOIN ' . $wpdb->postmeta . ' s ON s.post_id = a.seminar_id AND s.meta_key = %s
			  WHERE COALESCE( NULLIF( e.meta_value, %s ), NULLIF( s.meta_value, %s ), DATE( a.created ) ) < %s
			    AND a.status <> %s',
			'_bi_enddatum',
			'_bi_startdatum',
			'',
			'',
			self::cutoff(),
			'anonymisiert'
		);
		$due = (int) $wpdb->get_var( $sql );
		set_transient( $ckey, $due, 5 * MINUTE_IN_SECONDS );
		return $due;
	}

	/**
	 * Fällige Anmeldungen bereinigen. Wird täglich per Cron aufgerufen.
	 *
	 * @param bool $force Schonfrist übergehen (manueller Aufruf im Backend).
	 * @return int Zahl der bereinigten Anmeldungen.
	 */
	public static function run( $force = false ) {
		if ( ! self::days() ) {
			return 0;
		}
		if ( ! $force && self::in_grace_period() ) {
			return 0;
		}

		$ids = self::due_ids();
		if ( ! $ids ) {
			return 0;
		}

		$done = ( 'anonymize' === self::mode() ) ? self::anonymize( $ids ) : self::purge( $ids );

		delete_transient( 'bi_retention_due' );
		update_option( self::LOG_OPTION, array(
			'time'  => time(),
			'count' => $done,
			'mode'  => self::mode(),
			'rest'  => self::count_due( false ),
		), false );

		return $done;
	}

	/** Zeilen endgültig entfernen */
	private static function purge( array $ids ) {
		global $wpdb;
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->query( 'DELETE FROM ' . BI_Registration::table() . " WHERE id IN ($in)" );
		if ( false === $rows ) {
			error_log( 'BI_Retention: Löschen fehlgeschlagen. ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}
		return (int) $rows;
	}

	/**
	 * Personenbezug entfernen, Zeile behalten.
	 *
	 * Übrig bleiben Seminar, Zeitpunkt, Seminarform und Kampagne – damit bleibt
	 * auswertbar, wie viele Anmeldungen auf ein Seminar oder ein Mailing
	 * entfielen, ohne dass noch eine Person dahintersteht. Die JSON-Spalte mit
	 * allen Formularfeldern wird vollständig geleert.
	 */
	private static function anonymize( array $ids ) {
		global $wpdb;
		$done = 0;
		foreach ( $ids as $id ) {
			$ok = $wpdb->update(
				BI_Registration::table(),
				array(
					'vorname'   => '',
					'nachname'  => '',
					'email'     => '',
					'telefon'   => '',
					'betrieb'   => '',
					'plz'       => '',
					'nachricht' => '',
					'data'      => null,
					'status'    => 'anonymisiert',
				),
				array( 'id' => (int) $id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			if ( false === $ok ) {
				error_log( 'BI_Retention: Anonymisieren von #' . $id . ' fehlgeschlagen. ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				continue;
			}
			$done++;
		}
		return $done;
	}

	/** Manueller Anstoß aus den Einstellungen */
	public static function handle_run() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_retention_run' );

		$done = self::run( true );
		$msg  = $done
			? sprintf( '%d Anmeldung(en) bereinigt. Noch offen: %d.', $done, self::count_due( false ) )
			: 'Es war keine Anmeldung fällig.';

		wp_safe_redirect( add_query_arg( array(
			'page'   => 'bi-einstellungen',
			'tab'    => 'datenschutz',
			'bi_msg' => rawurlencode( $msg ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Hinweis im Backend, solange die Schonfrist läuft und etwas ansteht */
	public static function grace_notice() {
		if ( ! current_user_can( BI_CAP ) || ! self::in_grace_period() || ! self::days() ) {
			return;
		}
		$due = self::count_due();
		if ( ! $due ) {
			return;
		}
		$verb = ( 'anonymize' === self::mode() ) ? 'anonymisiert' : 'gelöscht';
		printf(
			'<div class="notice notice-warning"><p><strong>Bildungsprogramm:</strong> '
			. 'Ab %1$s werden Anmeldungen automatisch %2$s, sobald das Seminar länger als %3$d Tage vorbei ist. '
			. 'Derzeit betrifft das <strong>%4$d Anmeldung(en)</strong>. '
			. '<a href="%5$s">Jetzt prüfen und Frist anpassen</a></p></div>',
			esc_html( date_i18n( 'd.m.Y', self::starts_at() ) ),
			esc_html( $verb ),
			(int) self::days(),
			(int) $due,
			esc_url( admin_url( 'admin.php?page=bi-einstellungen&tab=datenschutz' ) )
		);
	}

	/** Abschnitt im Einstellungs-Tab „Datenschutz" */
	public static function render_section() {
		$due  = self::count_due( false );
		$last = get_option( self::LOG_OPTION, array() );
		?>
		<h2 class="title">Aufbewahrung der Anmeldungen</h2>
		<p>Anmeldungen enthalten vollständige Kontaktdaten und die Angabe zur Mitgliedschaft.
			Sie werden automatisch bereinigt, sobald das zugehörige Seminar die eingestellte
			Zahl von Tagen vorbei ist.</p>

		<div class="notice notice-info inline" style="margin:12px 0;padding:10px 14px">
			<p style="margin:0"><strong>Gerechnet wird ab Seminarende, nicht ab Anmeldedatum.</strong>
				Das Programm ist über ein Jahr im Voraus buchbar – ab Anmeldedatum gerechnet würden
				Anmeldungen verschwinden, bevor das Seminar stattgefunden hat. Existiert das Seminar
				nicht mehr, zählt ersatzweise das Anmeldedatum.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_save_settings">
			<input type="hidden" name="bi_tab" value="datenschutz">
			<?php wp_nonce_field( 'bi_save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="bi_retention_days">Frist nach Seminarende</label></th>
					<td>
						<input type="number" min="0" max="3650" step="1" id="bi_retention_days"
							name="retention_days" value="<?php echo esc_attr( self::days() ); ?>" class="small-text"> Tage
						<p class="description">0 schaltet die automatische Bereinigung ab.
							Prüfe die Zahl mit der Stelle, die bei euch für Datenschutz zuständig ist –
							es können steuer- oder förderrechtliche Aufbewahrungspflichten dagegenstehen.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Verfahren</th>
					<td>
						<label style="display:block;margin-bottom:6px">
							<input type="radio" name="retention_mode" value="delete" <?php checked( 'delete', self::mode() ); ?>>
							<strong>Löschen</strong> – die Anmeldung wird vollständig entfernt.
						</label>
						<label style="display:block">
							<input type="radio" name="retention_mode" value="anonymize" <?php checked( 'anonymize', self::mode() ); ?>>
							<strong>Anonymisieren</strong> – Name, Anschrift, Telefon, E-Mail, Betrieb und Bemerkungen
							werden entfernt, Seminar und Kampagne bleiben stehen.
						</label>
						<p class="description">Anonymisieren erfüllt die Frist ebenfalls, erhält aber die Auswertung
							„wie viele Anmeldungen je Seminar und Mailing". Löschen ist gründlicher, kostet euch
							diese Statistik.</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Frist speichern' ); ?>
		</form>

		<h3>Derzeitiger Stand</h3>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<tr>
					<th style="width:260px">Fällige Anmeldungen</th>
					<td><strong><?php echo (int) $due; ?></strong><?php
						if ( $due ) {
							echo ' (Seminar vor dem ' . esc_html( date_i18n( 'd.m.Y', strtotime( self::cutoff() ) ) ) . ' beendet)';
						}
					?></td>
				</tr>
				<tr>
					<th>Automatische Bereinigung</th>
					<td><?php
						if ( ! self::days() ) {
							echo 'abgeschaltet (Frist steht auf 0)';
						} elseif ( self::in_grace_period() ) {
							echo 'startet am <strong>' . esc_html( date_i18n( 'd.m.Y', self::starts_at() ) ) . '</strong> – bis dahin passiert nichts';
						} else {
							echo 'läuft täglich, höchstens ' . (int) self::BATCH . ' Anmeldungen je Lauf';
						}
					?></td>
				</tr>
				<tr>
					<th>Letzter Lauf</th>
					<td><?php
						if ( empty( $last['time'] ) ) {
							echo 'noch keiner';
						} else {
							printf(
								'%s – %d bereinigt (%s), danach offen: %d',
								esc_html( date_i18n( 'd.m.Y H:i', (int) $last['time'] ) ),
								(int) $last['count'],
								esc_html( 'anonymize' === ( $last['mode'] ?? '' ) ? 'anonymisiert' : 'gelöscht' ),
								(int) ( $last['rest'] ?? 0 )
							);
						}
					?></td>
				</tr>
			</tbody>
		</table>

		<?php if ( $due && self::days() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px"
				onsubmit="return confirm('<?php echo esc_attr( sprintf(
					'%d Anmeldung(en) werden jetzt %s. Das lässt sich nicht rückgängig machen. Fortfahren?',
					$due,
					( 'anonymize' === self::mode() ) ? 'anonymisiert' : 'gelöscht'
				) ); ?>');">
				<input type="hidden" name="action" value="bi_retention_run">
				<?php wp_nonce_field( 'bi_retention_run' ); ?>
				<button type="submit" class="button">Bereinigung jetzt ausführen</button>
				<span class="description" style="margin-left:8px">Verarbeitet bis zu <?php echo (int) self::BATCH; ?> Anmeldungen.</span>
			</form>
		<?php endif; ?>
		<?php
	}
}
