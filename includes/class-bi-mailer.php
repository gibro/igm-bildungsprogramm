<?php
/**
 * Mail-Trigger-Engine.
 *
 * Trigger werden in der Option bi_mail_triggers als Array gespeichert. Jeder Trigger:
 *   active        bool
 *   name          Bezeichnung (intern)
 *   type          geschaeftsstelle | teilnehmer | bildungszentrum | custom
 *   recipient     E-Mail (nur type=custom)
 *   from          Absender ("Name <mail>")
 *   subject       Betreff (mit Platzhaltern)
 *   body          Text (mit Platzhaltern)
 *   cond_tax      Bedingung: Taxonomie-Slug (optional)
 *   cond_value    Bedingung: Term-Name (optional)
 *   cond_op       Bedingung: 'is' = Seminar muss den Wert haben, 'not' = darf ihn nicht haben
 *
 * Platzhalter siehe BI_Mailer::placeholders().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Mailer {

	const OPTION      = 'bi_mail_triggers';
	const TEST_OPTION = 'bi_mail_test';

	public static function init() {
		add_action( 'admin_post_bi_save_triggers', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_bi_save_test', array( __CLASS__, 'save_test' ) );
	}

	/** Test-Modus-Einstellungen */
	public static function get_test() {
		$t = get_option( self::TEST_OPTION, array() );
		return wp_parse_args( is_array( $t ) ? $t : array(), array( 'enabled' => 0, 'address' => '' ) );
	}

	public static function get_triggers() {
		$t = get_option( self::OPTION, array() );
		return is_array( $t ) ? $t : array();
	}

	/** Standard-Trigger bei Aktivierung (nur wenn noch keine existieren) */
	public static function seed_default_triggers() {
		if ( get_option( self::OPTION, null ) !== null ) {
			return;
		}
		$default_from = get_bloginfo( 'name' ) . ' <' . get_option( 'admin_email' ) . '>';
		update_option( self::OPTION, array(
			array(
				'active'  => 1,
				'name'    => 'Benachrichtigung an Geschäftsstelle',
				'type'    => 'geschaeftsstelle',
				'recipient' => '',
				'from'    => $default_from,
				'subject' => 'Neue Seminar-Anmeldung aus Ihrem Gebiet ({plz})',
				'body'    => "Hallo {geschaeftsstelle},\n\nüber das Bildungsprogramm ist eine neue Anmeldung aus Ihrem Gebiet eingegangen.\n\nSeminar: {seminar_titel}\nNummer: {seminar_nummer}\nStart: {seminar_startdatum}\n\nName: {name}\nBetrieb: {betrieb}\nBetriebs-PLZ: {plz}\nE-Mail: {email}\nTelefon: {telefon}\n\nNachricht:\n{nachricht}\n\n— automatisch erzeugt",
				'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
			),
			array(
				'active'  => 1,
				'name'    => 'Bestätigung an Teilnehmer',
				'type'    => 'teilnehmer',
				'recipient' => '',
				'from'    => $default_from,
				'subject' => 'Ihre Anmeldung zu „{seminar_titel}“',
				'body'    => "Hallo {vorname} {nachname},\n\nvielen Dank für Ihre Anmeldung zum Seminar „{seminar_titel}“.\n\nStart: {seminar_startdatum}\nOrt: {seminar_ort}\n\nIhre zuständige Geschäftsstelle ({geschaeftsstelle}) wird sich bei Ihnen melden.\n\nViele Grüße\nIhr Bildungsteam",
				'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
			),
			array(
				'active'  => 1,
				'name'    => 'Benachrichtigung an Ansprechpartner',
				'type'    => 'ansprechpartner',
				'recipient' => '',
				'from'    => $default_from,
				'subject' => 'Neue Anmeldung: {seminar_titel}',
				'body'    => "Hallo {ansprechpartner},\n\nfür Ihr Seminar ist eine neue Anmeldung eingegangen.\n\nSeminar: {seminar_titel} ({seminar_nummer})\nOrt: {seminar_ort}\nStart: {seminar_startdatum}\n\nTeilnehmer: {name}, {betrieb}, PLZ {plz}\nE-Mail: {email}",
				'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
			),
		) );
	}

	/** ---------- Versand ---------- */

	/**
	 * Benachrichtigungen versenden.
	 *
	 * @param array  $submission Anmeldedaten.
	 * @param string $force_to   Wenn gesetzt (Soforttest): ALLE Mails an diese Adresse,
	 *                           Bedingungen werden ignoriert. Sonst greift ggf. der Test-Modus.
	 * @return int Anzahl versendeter Mails.
	 */
	public static function dispatch( $submission, $force_to = '' ) {
		if ( ! $submission ) {
			return 0;
		}
		$ctx  = self::build_context( $submission );
		$test = self::get_test();

		// Umleitungs-Adresse: Soforttest hat Vorrang, sonst globaler Test-Modus
		$route_to = '';
		if ( $force_to && is_email( $force_to ) ) {
			$route_to = $force_to;
		} elseif ( ! empty( $test['enabled'] ) && is_email( $test['address'] ) ) {
			$route_to = $test['address'];
		}

		$sent = 0;
		foreach ( self::get_triggers() as $trigger ) {
			if ( empty( $trigger['active'] ) ) {
				continue;
			}
			// Bedingung nur im Echtbetrieb prüfen (beim Soforttest ignorieren)
			if ( '' === $force_to && ! self::condition_met( $trigger, $submission ) ) {
				continue;
			}

			$orig = self::resolve_recipient( $trigger, $submission, $ctx );
			$to   = $route_to ? $route_to : $orig;
			if ( ! $to || ! is_email( $to ) ) {
				continue;
			}

			$subject = self::replace( $trigger['subject'] ?? '', $ctx );
			$body    = self::replace( $trigger['body'] ?? '', $ctx );

			if ( $route_to ) {
				$subject = '[TEST] ' . $subject;
				$body    = "==> TEST-VERSAND — diese Mail wäre an „" . ( $orig ?: 'kein Empfänger ermittelt' ) . "“ gegangen.\n\n" . $body;
			}

			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
			if ( ! empty( $trigger['from'] ) ) {
				$headers[] = 'From: ' . $trigger['from'];
			}

			$ok = wp_mail( $to, $subject, $body, $headers );
			if ( $ok ) {
				$sent++;
			} else {
				error_log( '[BI-Mailer] Versand an ' . $to . ' fehlgeschlagen (Benachrichtigung: ' . ( $trigger['name'] ?? '?' ) . ')' );
			}
		}
		return $sent;
	}

	private static function resolve_recipient( $trigger, $submission, $ctx ) {
		switch ( $trigger['type'] ?? 'custom' ) {
			case 'geschaeftsstelle':
				return $ctx['{geschaeftsstelle_email}'];
			case 'teilnehmer':
				return $submission['email'];
			case 'bildungszentrum':
				return self::bildungszentrum_email( $submission['seminar_id'] );
			case 'ansprechpartner':
				return get_post_meta( $submission['seminar_id'], '_bi_ansprechpartner_email', true );
			case 'custom':
			default:
				return self::replace( $trigger['recipient'] ?? '', $ctx );
		}
	}

	private static function condition_met( $trigger, $submission ) {
		if ( empty( $trigger['cond_tax'] ) || empty( $trigger['cond_value'] ) ) {
			return true;
		}
		$terms = wp_get_object_terms( $submission['seminar_id'], $trigger['cond_tax'], array( 'fields' => 'names' ) );
		$has   = is_array( $terms ) && in_array( $trigger['cond_value'], $terms, true );
		return ( 'not' === ( $trigger['cond_op'] ?? 'is' ) ) ? ! $has : $has;
	}

	private static function bildungszentrum_email( $seminar_id ) {
		$terms = wp_get_object_terms( $seminar_id, BI_TAX_ORT );
		if ( is_array( $terms ) && $terms ) {
			$mail = get_term_meta( $terms[0]->term_id, 'email', true );
			if ( $mail && is_email( $mail ) ) {
				return $mail;
			}
		}
		return '';
	}

	/** ---------- Platzhalter ---------- */

	public static function placeholders() {
		return array(
			'{anrede}'               => 'Anrede',
			'{titel}'                => 'Titel',
			'{vorname}'              => 'Vorname',
			'{nachname}'             => 'Nachname',
			'{name}'                 => 'Vor- und Nachname',
			'{strasse}'              => 'Straße + Hausnr. (privat)',
			'{plz_privat}'           => 'PLZ (privat)',
			'{ort}'                  => 'Ort (privat)',
			'{telefon}'              => 'Telefon',
			'{mobil}'                => 'Mobiltelefon',
			'{email}'                => 'E-Mail des Teilnehmers',
			'{mitglied}'             => 'IG-Metall-Mitglied (ja/nein)',
			'{mitgliedsnummer}'      => 'Mitgliedsnummer',
			'{funktion}'             => 'Funktion im Betriebsrat',
			'{betrieb}'              => 'Betrieb',
			'{betrieb_strasse}'      => 'Straße + Hausnr. (Betrieb)',
			'{plz}'                  => 'Betriebs-PLZ',
			'{betrieb_ort}'          => 'Ort (Betrieb)',
			'{betrieb_email}'        => 'E-Mail (Betrieb)',
			'{freistellung}'         => 'Gewählte Freistellung',
			'{bemerkungen}'          => 'Bemerkungen',
			'{nachricht}'            => 'Bemerkungen (Alias)',
			'{geschaeftsstelle}'     => 'Name der zuständigen Geschäftsstelle',
			'{geschaeftsstelle_email}' => 'E-Mail der Geschäftsstelle',
			'{seminar_titel}'        => 'Seminartitel',
			'{seminar_nummer}'       => 'Seminarnummer',
			'{seminar_startdatum}'   => 'Startdatum (TT.MM.JJJJ)',
			'{seminar_startuhrzeit}' => 'Startuhrzeit',
			'{seminar_enddatum}'     => 'Enddatum',
			'{seminar_enduhrzeit}'   => 'Enduhrzeit',
			'{seminar_anreisedatum}' => 'Anreisedatum',
			'{seminar_anreiseuhrzeit}' => 'Anreiseuhrzeit',
			'{seminar_themen}'       => 'Themen im Seminar',
			'{seminar_ort}'          => 'Bildungszentrum / Ort',
			'{ansprechpartner}'      => 'Ansprechpartner des Seminars',
			'{ansprechpartner_email}' => 'E-Mail des Ansprechpartners',
			'{datum}'                => 'Heutiges Datum',
		);
	}

	private static function build_context( $submission ) {
		$sid     = (int) $submission['seminar_id'];
		$gs      = BI_PLZ::lookup( $submission['plz'] );
		$ort     = wp_get_object_terms( $sid, BI_TAX_ORT, array( 'fields' => 'names' ) );
		$start   = get_post_meta( $sid, '_bi_startdatum', true );
		$end     = get_post_meta( $sid, '_bi_enddatum', true );
		$anreise = get_post_meta( $sid, '_bi_anreisedatum', true );

		$d = ( isset( $submission['data'] ) && is_array( $submission['data'] ) ) ? $submission['data'] : array();

		return array(
			'{anrede}'                 => $d['anrede'] ?? '',
			'{titel}'                  => $d['titel'] ?? '',
			'{vorname}'                => $submission['vorname'],
			'{nachname}'               => $submission['nachname'],
			'{name}'                   => trim( $submission['vorname'] . ' ' . $submission['nachname'] ),
			'{strasse}'                => $d['strasse'] ?? '',
			'{plz_privat}'             => $d['plz'] ?? '',
			'{ort}'                    => $d['ort'] ?? '',
			'{telefon}'                => $submission['telefon'],
			'{mobil}'                  => $d['mobil'] ?? '',
			'{email}'                  => $submission['email'],
			'{mitglied}'               => $d['mitglied'] ?? '',
			'{mitgliedsnummer}'        => $d['mitgliedsnummer'] ?? '',
			'{funktion}'               => $d['funktion'] ?? '',
			'{betrieb}'                => $submission['betrieb'],
			'{betrieb_strasse}'        => $d['betrieb_strasse'] ?? '',
			'{plz}'                    => $submission['plz'],
			'{betrieb_ort}'            => $d['betrieb_ort'] ?? '',
			'{betrieb_email}'          => $d['betrieb_email'] ?? '',
			'{freistellung}'           => $d['freistellung'] ?? '',
			'{bemerkungen}'            => $submission['nachricht'],
			'{nachricht}'              => $submission['nachricht'],
			'{geschaeftsstelle}'       => $gs ? $gs['geschaeftsstelle'] : '',
			'{geschaeftsstelle_email}' => $gs ? $gs['email'] : '',
			'{seminar_titel}'          => get_the_title( $sid ),
			'{seminar_nummer}'         => get_post_meta( $sid, '_bi_seminarnummer', true ),
			'{seminar_startdatum}'     => $start ? date_i18n( 'd.m.Y', strtotime( $start ) ) : '',
			'{seminar_startuhrzeit}'   => get_post_meta( $sid, '_bi_startuhrzeit', true ),
			'{seminar_enddatum}'       => $end ? date_i18n( 'd.m.Y', strtotime( $end ) ) : '',
			'{seminar_enduhrzeit}'     => get_post_meta( $sid, '_bi_enduhrzeit', true ),
			'{seminar_anreisedatum}'   => $anreise ? date_i18n( 'd.m.Y', strtotime( $anreise ) ) : '',
			'{seminar_anreiseuhrzeit}' => get_post_meta( $sid, '_bi_anreiseuhrzeit', true ),
			'{seminar_themen}'         => get_post_meta( $sid, '_bi_themen', true ),
			'{seminar_ort}'            => ( is_array( $ort ) && $ort ) ? $ort[0] : '',
			'{ansprechpartner}'        => get_post_meta( $sid, '_bi_ansprechpartner', true ),
			'{ansprechpartner_email}'  => get_post_meta( $sid, '_bi_ansprechpartner_email', true ),
			'{datum}'                  => date_i18n( 'd.m.Y' ),
		);
	}

	private static function replace( $text, $ctx ) {
		return strtr( (string) $text, $ctx );
	}

	/** ---------- Admin-Seite ---------- */

	public static function render_page() {
		$triggers = self::get_triggers();
		$notice   = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		$taxes    = BI_CPT::taxonomies();
		?>
		<div class="wrap">
			<h1>Mail-Benachrichtigungen</h1>
			<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
			<p>Diese Mails werden nach jeder Anmeldung verschickt – sofern aktiv und ihre Bedingung erfüllt ist.</p>

			<?php $cons = self::consistency_notices( $triggers, $taxes ); ?>
			<?php if ( $cons ) : ?>
				<div class="notice notice-warning" style="padding:10px 14px">
					<p style="margin:0 0 6px"><strong>Konsistenz-Check der aktiven Benachrichtigungen:</strong></p>
					<ul style="margin:0;list-style:disc;padding-left:20px">
						<?php foreach ( $cons as $n ) : ?>
							<li><?php echo esc_html( $n ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php echo self::test_box(); // phpcs:ignore – intern escaped ?>


			<details style="margin:12px 0;background:#fff;border:1px solid #ccd0d4;padding:10px 14px">
				<summary style="cursor:pointer;font-weight:600">Verfügbare Platzhalter</summary>
				<ul style="columns:2">
					<?php foreach ( self::placeholders() as $ph => $desc ) : ?>
						<li><code><?php echo esc_html( $ph ); ?></code> – <?php echo esc_html( $desc ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_save_triggers">
				<?php wp_nonce_field( 'bi_save_triggers' ); ?>

				<p class="description" style="margin:12px 0">Tipp: Klicke auf eine Benachrichtigung, um sie auf- bzw. zuzuklappen.</p>
				<?php
				$type_labels = self::type_labels();
				// Mindestens eine leere Zeile zum Anlegen anhängen
				$render = $triggers;
				$render[] = self::empty_trigger();
				foreach ( $render as $i => $t ) :
					$is_new = ( $i === count( $render ) - 1 );
					?>
					<details class="bi-trigger-card" style="background:#fff;border:1px solid #ccd0d4;border-radius:4px;margin:12px 0"<?php echo $is_new ? ' open' : ''; ?>>
						<summary style="cursor:pointer;padding:14px 18px;font-weight:600;font-size:14px">
							<?php if ( $is_new ) : ?>
								＋ Neue Benachrichtigung anlegen
							<?php else : ?>
								<?php echo esc_html( $t['name'] ?: ( 'Trigger ' . ( $i + 1 ) ) ); ?>
								<span style="font-weight:400;color:#646970">— <?php echo esc_html( $type_labels[ $t['type'] ] ?? $t['type'] ); ?></span>
								<?php $cond_label = self::condition_label( $t, $taxes ); ?>
								<?php if ( $cond_label ) : ?>
									<span style="background:#fcf9e8;color:#8a6d00;border:1px solid #f0e6bb;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:400;margin-left:6px">nur wenn <?php echo esc_html( $cond_label ); ?></span>
								<?php else : ?>
									<span style="background:#f0f6fc;color:#2271b1;border:1px solid #c5d9ed;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:400;margin-left:6px">bei jeder Anmeldung</span>
								<?php endif; ?>
								<?php if ( ! empty( $t['active'] ) ) : ?>
									<span style="background:#edfaef;color:#1a7f37;border:1px solid #b8e6c2;padding:1px 8px;border-radius:10px;font-size:12px;margin-left:6px">aktiv</span>
								<?php else : ?>
									<span style="background:#f0f0f1;color:#646970;padding:1px 8px;border-radius:10px;font-size:12px;margin-left:6px">inaktiv</span>
								<?php endif; ?>
							<?php endif; ?>
						</summary>
						<div style="padding:0 18px 14px">
						<table class="form-table">
							<tr>
								<th>Aktiv</th>
								<td><label><input type="checkbox" name="trigger[<?php echo $i; ?>][active]" value="1" <?php checked( ! empty( $t['active'] ) ); ?>> aktiv</label>
								<?php if ( ! $is_new ) : ?>
									&nbsp;&nbsp;<label style="color:#b32d2e"><input type="checkbox" name="trigger[<?php echo $i; ?>][delete]" value="1"> löschen</label>
								<?php endif; ?></td>
							</tr>
							<tr>
								<th><label>Bezeichnung</label></th>
								<td><input type="text" class="regular-text" name="trigger[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $t['name'] ); ?>"></td>
							</tr>
							<tr>
								<th><label>Empfänger-Typ</label></th>
								<td>
									<select name="trigger[<?php echo $i; ?>][type]" class="bi-trigger-type">
										<?php foreach ( array(
											'geschaeftsstelle' => 'Zuständige Geschäftsstelle (per PLZ)',
											'teilnehmer'       => 'Teilnehmer (Bestätigung)',
											'bildungszentrum'  => 'Bildungszentrum (Seminarort)',
											'ansprechpartner'  => 'Ansprechpartner des Seminars',
											'custom'           => 'Feste/eigene Adresse',
										) as $val => $lbl ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $t['type'], $val ); ?>><?php echo esc_html( $lbl ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th><label>Feste Adresse</label></th>
								<td>
									<input type="text" class="regular-text" name="trigger[<?php echo $i; ?>][recipient]" value="<?php echo esc_attr( $t['recipient'] ); ?>" placeholder="z. B. anmeldung@example.de">
									<p class="description">Nur relevant beim Empfänger-Typ <strong>„Feste/eigene Adresse"</strong>: eine fest hinterlegte
										E-Mail-Adresse, an die diese Benachrichtigung immer geht (z. B. ein zentrales Postfach oder Archiv).
										Platzhalter sind erlaubt – z. B. <code>{ansprechpartner_email}</code> oder <code>{betrieb_email}</code>.
										Bei allen anderen Typen wird der Empfänger automatisch ermittelt; dieses Feld bleibt dann leer.</p>
								</td>
							</tr>
							<tr>
								<th><label>Absender (From)</label></th>
								<td><input type="text" class="regular-text" name="trigger[<?php echo $i; ?>][from]" value="<?php echo esc_attr( $t['from'] ); ?>" placeholder="Name &lt;mail@domain.de&gt;"></td>
							</tr>
							<tr>
								<th><label>Bedingung</label></th>
								<td>
									nur senden, wenn Seminar in
									<select name="trigger[<?php echo $i; ?>][cond_tax]">
										<option value="">— keine Bedingung —</option>
										<?php foreach ( $taxes as $slug => $cfg ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $t['cond_tax'], $slug ); ?>><?php echo esc_html( $cfg['single'] ); ?></option>
										<?php endforeach; ?>
									</select>
									den Wert
									<input type="text" name="trigger[<?php echo $i; ?>][cond_value]" value="<?php echo esc_attr( $t['cond_value'] ); ?>" placeholder="z. B. Datenschutz">
									<select name="trigger[<?php echo $i; ?>][cond_op]">
										<option value="is" <?php selected( $t['cond_op'] ?? 'is', 'is' ); ?>>hat</option>
										<option value="not" <?php selected( $t['cond_op'] ?? 'is', 'not' ); ?>>nicht hat</option>
									</select>
									<p class="description">Ohne Bedingung wird diese Benachrichtigung bei <strong>jeder</strong> Anmeldung verschickt.
										Sollen sich zwei Benachrichtigungen an denselben Empfänger gegenseitig ausschließen, gib ihnen
										gegenteilige Bedingungen auf denselben Wert – z. B. eine mit Bildungszentrum <em>hat</em>
										„Kritische Akademie" und eine mit <em>nicht hat</em>. So greift bei jeder Anmeldung genau eine
										der beiden.</p>
								</td>
							</tr>
							<tr>
								<th><label>Betreff</label></th>
								<td><input type="text" class="large-text" name="trigger[<?php echo $i; ?>][subject]" value="<?php echo esc_attr( $t['subject'] ); ?>"></td>
							</tr>
							<tr>
								<th><label>Text</label></th>
								<td><textarea class="large-text" rows="8" name="trigger[<?php echo $i; ?>][body]"><?php echo esc_textarea( $t['body'] ); ?></textarea></td>
							</tr>
						</table>
						</div>
					</details>
				<?php endforeach; ?>

				<?php submit_button( 'Benachrichtigungen speichern' ); ?>
			</form>
		</div>
		<?php
	}

	private static function empty_trigger() {
		return array(
			'active' => 0, 'name' => '', 'type' => 'custom', 'recipient' => '',
			'from' => '', 'subject' => '', 'body' => '', 'cond_tax' => '', 'cond_value' => '', 'cond_op' => 'is',
		);
	}

	private static function type_labels() {
		return array(
			'geschaeftsstelle' => 'Geschäftsstelle',
			'teilnehmer'       => 'Teilnehmer',
			'bildungszentrum'  => 'Bildungszentrum',
			'ansprechpartner'  => 'Ansprechpartner',
			'custom'           => 'Feste Adresse',
		);
	}

	/** Lesbare Kurzform der Bedingung eines Triggers, z. B. „Bildungszentrum = Kritische Akademie“; leer ohne Bedingung. */
	private static function condition_label( $t, $taxes ) {
		if ( empty( $t['cond_tax'] ) || empty( $t['cond_value'] ) ) {
			return '';
		}
		$tax = isset( $taxes[ $t['cond_tax'] ] ) ? $taxes[ $t['cond_tax'] ]['single'] : $t['cond_tax'];
		$op  = ( 'not' === ( $t['cond_op'] ?? 'is' ) ) ? '≠' : '=';
		return $tax . ' ' . $op . ' „' . $t['cond_value'] . '“';
	}

	/**
	 * Konsistenz-Check: prüft die aktiven Trigger je Empfänger (Typ bzw. bei fester
	 * Adresse je Empfängeradresse) auf Doppelversand und Lücken. Rein informativ –
	 * greift nicht in den Versand ein.
	 *
	 * @return string[] Hinweise für den Admin.
	 */
	private static function consistency_notices( $triggers, $taxes ) {
		$type_labels = self::type_labels();

		$groups = array();
		foreach ( $triggers as $t ) {
			if ( empty( $t['active'] ) ) {
				continue;
			}
			$key = $t['type'] ?? 'custom';
			if ( 'custom' === $key ) {
				$key .= ':' . strtolower( trim( $t['recipient'] ?? '' ) );
			}
			$groups[ $key ][] = $t;
		}

		$names = function ( $list ) {
			$n = array();
			foreach ( $list as $t ) {
				$n[] = '„' . ( $t['name'] !== '' ? $t['name'] : 'ohne Bezeichnung' ) . '“';
			}
			return implode( ', ', $n );
		};

		$notices = array();
		foreach ( $groups as $key => $list ) {
			$type  = strtok( $key, ':' );
			$label = $type_labels[ $type ] ?? $type;
			if ( 'custom' === $type ) {
				$addr   = substr( $key, strlen( 'custom:' ) );
				$label .= $addr ? ' ' . $addr : '';
			}

			$uncond = array();
			$cond   = array();
			foreach ( $list as $t ) {
				if ( empty( $t['cond_tax'] ) || empty( $t['cond_value'] ) ) {
					$uncond[] = $t;
				} else {
					$cond[] = $t;
				}
			}

			if ( count( $uncond ) >= 2 ) {
				$notices[] = sprintf(
					'Mehrfachversand an %s: %s haben alle keine Bedingung – bei jeder Anmeldung werden sie alle verschickt.',
					$label, $names( $uncond )
				);
			}
			if ( $uncond && $cond ) {
				$notices[] = sprintf(
					'Überschneidung bei %s: %s wird bei jeder Anmeldung verschickt; trifft zusätzlich die Bedingung von %s zu, gehen mehrere Mails an denselben Empfänger. Falls ungewollt: der Benachrichtigung ohne Bedingung die gegenteilige Bedingung („nicht hat“) geben.',
					$label, $names( $uncond ), $names( $cond )
				);
			}
			if ( ! $uncond && $cond ) {
				$covered = false;
				$dupes   = array();
				$n       = count( $cond );
				for ( $i = 0; $i < $n; $i++ ) {
					for ( $j = $i + 1; $j < $n; $j++ ) {
						$a = $cond[ $i ];
						$b = $cond[ $j ];
						if ( $a['cond_tax'] !== $b['cond_tax'] || $a['cond_value'] !== $b['cond_value'] ) {
							continue;
						}
						if ( ( $a['cond_op'] ?? 'is' ) === ( $b['cond_op'] ?? 'is' ) ) {
							$dupes = array( $a, $b );
						} else {
							$covered = true; // komplementäres Paar: hat / nicht hat auf denselben Wert
						}
					}
				}
				if ( $dupes ) {
					$notices[] = sprintf(
						'Doppelte Bedingung bei %s: %s haben dieselbe Bedingung und werden immer gemeinsam verschickt.',
						$label, $names( $dupes )
					);
				}
				if ( ! $covered ) {
					$notices[] = sprintf(
						'Lücke möglich bei %s: Alle Benachrichtigungen an diesen Empfänger (%s) haben Bedingungen. Anmeldungen, bei denen keine davon zutrifft, lösen keine Mail an ihn aus. Falls ungewollt: ein Paar mit „hat“ / „nicht hat“ auf denselben Wert anlegen.',
						$label, $names( $cond )
					);
				}
			}
		}
		return $notices;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_triggers' );

		$in  = isset( $_POST['trigger'] ) && is_array( $_POST['trigger'] ) ? wp_unslash( $_POST['trigger'] ) : array();
		$out = array();
		foreach ( $in as $row ) {
			if ( ! empty( $row['delete'] ) ) {
				continue;
			}
			// Leere Neu-Zeile ohne Inhalt verwerfen
			if ( '' === trim( $row['name'] ?? '' ) && '' === trim( $row['subject'] ?? '' ) && '' === trim( $row['body'] ?? '' ) ) {
				continue;
			}
			$out[] = array(
				'active'     => ! empty( $row['active'] ) ? 1 : 0,
				'name'       => sanitize_text_field( $row['name'] ?? '' ),
				'type'       => in_array( $row['type'] ?? '', array( 'geschaeftsstelle', 'teilnehmer', 'bildungszentrum', 'ansprechpartner', 'custom' ), true ) ? $row['type'] : 'custom',
				'recipient'  => sanitize_text_field( $row['recipient'] ?? '' ),
				'from'       => sanitize_text_field( $row['from'] ?? '' ),
				'subject'    => sanitize_text_field( $row['subject'] ?? '' ),
				'body'       => sanitize_textarea_field( $row['body'] ?? '' ),
				'cond_tax'   => sanitize_text_field( $row['cond_tax'] ?? '' ),
				'cond_value' => sanitize_text_field( $row['cond_value'] ?? '' ),
				'cond_op'    => ( 'not' === ( $row['cond_op'] ?? 'is' ) ) ? 'not' : 'is',
			);
		}
		update_option( self::OPTION, $out );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-mail-trigger', 'bi_msg' => rawurlencode( count( $out ) . ' Benachrichtigungen gespeichert.' ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/** ---------- Test-Versand ---------- */

	/** Box oben auf der Seite: EIN Formular – Test-Modus + Testadresse (persistent) + Soforttest */
	private static function test_box() {
		$test    = self::get_test();
		$address = $test['address'] ?: wp_get_current_user()->user_email;

		ob_start();
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;padding:14px 18px;margin:16px 0">
			<h2 style="margin-top:0">Test-Versand</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_save_test">
				<?php wp_nonce_field( 'bi_save_test' ); ?>

				<p style="margin:0 0 10px">
					<label><input type="checkbox" name="test_enabled" value="1" <?php checked( ! empty( $test['enabled'] ) ); ?>>
						<strong>Test-Modus aktiv</strong></label> –
					solange aktiv, gehen <em>alle</em> Benachrichtigungen (auch bei echten Anmeldungen) ausschließlich an die
					Testadresse statt an echte Empfänger; der Betreff bekommt „[TEST]" vorangestellt.
				</p>

				<table class="form-table" style="margin-top:0">
					<tr>
						<th style="width:140px"><label for="bi_test_address">Testadresse</label></th>
						<td><input type="email" id="bi_test_address" name="test_address" value="<?php echo esc_attr( $address ); ?>" class="regular-text" placeholder="test@example.de"></td>
					</tr>
				</table>

				<p style="margin:6px 0 4px">
					<button type="submit" name="bi_do" value="save" class="button button-secondary">Speichern</button>
					<button type="submit" name="bi_do" value="send" class="button button-primary">Speichern &amp; Testmail senden</button>
				</p>
				<p class="description" style="margin:0">„Testmail senden" verschickt zu allen <strong>aktiven</strong> Benachrichtigungen je eine
					Beispiel-Mail (mit Testdaten, Bedingungen werden ignoriert) an die oben gespeicherte Testadresse.</p>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function save_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_test' );

		$opt = array(
			'enabled' => ! empty( $_POST['test_enabled'] ) ? 1 : 0,
			'address' => sanitize_email( wp_unslash( $_POST['test_address'] ?? '' ) ),
		);
		update_option( self::TEST_OPTION, $opt );

		$do = sanitize_text_field( wp_unslash( $_POST['bi_do'] ?? 'save' ) );

		if ( 'send' === $do ) {
			if ( ! is_email( $opt['address'] ) ) {
				self::redirect_msg( 'Bitte zuerst eine gültige Testadresse eintragen.' );
			}
			$sent = self::dispatch( self::sample_submission( $opt['address'] ), $opt['address'] );
			self::redirect_msg( sprintf(
				'%d Testmail(s) an %s gesendet (Posteingang/Spam prüfen; bei 0 → SMTP/Mailversand prüfen). Test-Modus: %s.',
				$sent,
				$opt['address'],
				$opt['enabled'] ? 'AKTIV' : 'inaktiv'
			) );
		}

		$msg = $opt['enabled']
			? ( is_email( $opt['address'] )
				? 'Gespeichert. Test-Modus AKTIV – alle Mails gehen an ' . $opt['address'] . '.'
				: 'Test-Modus aktiviert, aber es fehlt eine gültige Testadresse!' )
			: 'Gespeichert. Test-Modus inaktiv.';
		self::redirect_msg( $msg );
	}

	/** Beispiel-Anmeldung mit Testdaten; nimmt das neueste Seminar für Seminar-Platzhalter */
	private static function sample_submission( $to ) {
		$latest = get_posts( array( 'post_type' => BI_CPT, 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'publish' ) );
		$sid    = $latest ? (int) $latest[0] : 0;

		$data = array(
			'vorname'         => 'Max',
			'nachname'        => 'Mustermann',
			'strasse'         => 'Musterstraße 1',
			'plz'             => '01067',
			'ort'             => 'Dresden',
			'telefon'         => '0123 456789',
			'email'           => $to,
			'mitgliedsnummer' => '1234567',
			'betrieb'         => 'Muster GmbH',
			'betrieb_strasse' => 'Werkstraße 2',
			'betrieb_plz'     => '01067',
			'betrieb_ort'     => 'Dresden',
			'betrieb_email'   => 'personal@muster-gmbh.de',
			'freistellung'    => 'Bildungsurlaub',
			'bemerkungen'     => 'Dies ist eine automatische Testanmeldung.',
		);

		return array(
			'seminar_id' => $sid,
			'vorname'    => 'Max',
			'nachname'   => 'Mustermann',
			'email'      => $to,
			'telefon'    => '0123 456789',
			'betrieb'    => 'Muster GmbH',
			'plz'        => '01067', // betriebliche PLZ -> Geschäftsstellen-Lookup
			'nachricht'  => 'Dies ist eine automatische Testanmeldung.',
			'data'       => $data,
		);
	}

	private static function redirect_msg( $msg ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-mail-trigger', 'bi_msg' => rawurlencode( $msg ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
