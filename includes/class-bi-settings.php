<?php
/**
 * Einstellungen – globale Konfiguration der Anmeldevarianten.
 *
 * Option bi_settings:
 *   anmeldung_page_id  int    Seite mit [bi_anmeldung] für die Direktanmeldung (0 = automatisch erkennen)
 *   gs_url             string Link zur Geschäftsstellensuche
 *   direct_label       string Button-Text Direktanmeldung
 *   gs_label           string Button-Text Geschäftsstellen-Anmeldung
 *   gs_hinweis         string Hinweistext bei Geschäftsstellen-Anmeldung
 *
 * Pro Seminar entscheidet das Flag _bi_anmeldung_moeglich, WELCHE Variante greift
 * (true = Direktanmeldung, false = nur über Geschäftsstelle). Hier werden die
 * Ziele/Texte beider Varianten zentral hinterlegt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Settings {

	const OPTION = 'bi_settings';

	public static function init() {
		add_action( 'admin_post_bi_save_settings', array( __CLASS__, 'save' ) );
		// Vorschlagstext im WordPress-eigenen Datenschutz-Leitfaden
		// (Werkzeuge → Datenschutz → Richtlinien-Leitfaden).
		add_action( 'admin_init', array( __CLASS__, 'register_privacy_policy' ) );
	}

	public static function defaults() {
		return array(
			'anmeldung_page_id'  => 0,
			'uebersicht_page_id' => 0,
			'gs_url'            => 'https://www.igmetall.de/vor-ort',
			'direct_label'      => 'Jetzt buchen',
			'gs_label'          => 'Zur Geschäftsstellensuche',
			'gs_hinweis'        => 'Anmeldung nur über deine Geschäftsstelle möglich.',
			'rules'             => array(),
		);
	}

	/** Felder, auf die sich eine Regel beziehen kann */
	public static function rule_fields() {
		return array(
			'freistellung'  => 'Freistellung',
			'handlungsfeld' => 'Handlungsfeld',
			'zielgruppe'    => 'Zielgruppe',
			'ort'           => 'Bildungszentrum',
			'flag'          => '„Anmeldung möglich"-Flag',
		);
	}

	/** Mögliche Ergebnis-Varianten einer Regel */
	public static function rule_variants() {
		return array(
			'direct' => 'Direktanmeldung (Variante 1)',
			'gs'     => 'Geschäftsstelle (Variante 2)',
		);
	}

	private static function field_tax( $field ) {
		$map = array(
			'freistellung'  => BI_TAX_FREI,
			'handlungsfeld' => BI_TAX_THEMA,
			'zielgruppe'    => BI_TAX_ZIEL,
			'ort'           => BI_TAX_ORT,
		);
		return $map[ $field ] ?? '';
	}

	public static function rules() {
		$all = self::all();
		return ( isset( $all['rules'] ) && is_array( $all['rules'] ) ) ? $all['rules'] : array();
	}

	/** Vergleichs-Normalisierung: Kleinschreibung ohne Leerzeichen/Punkte/Kommas (37.6 = 37,6) */
	private static function norm( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return preg_replace( '/[\s.,]/u', '', $s );
	}

	/** Prüft, ob eine Regel auf ein Seminar zutrifft */
	private static function rule_matches( $post_id, $rule ) {
		$field = $rule['field'] ?? '';
		$value = trim( (string) ( $rule['value'] ?? '' ) );
		if ( '' === $field || '' === $value ) {
			return false;
		}

		if ( 'flag' === $field ) {
			$raw = (string) get_post_meta( $post_id, '_bi_anmeldung_moeglich', true );
			$v   = strtolower( $value );
			if ( in_array( $v, array( 'leer', 'nicht gesetzt', 'none', 'empty', 'ohne' ), true ) ) {
				return '' === $raw;
			}
			if ( in_array( $v, array( 'ja', 'yes', '1', 'true' ), true ) ) {
				return '1' === $raw;
			}
			if ( in_array( $v, array( 'nein', 'no', '0', 'false' ), true ) ) {
				return '0' === $raw;
			}
			return false;
		}

		$tax = self::field_tax( $field );
		if ( ! $tax ) {
			return false;
		}
		$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'names' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return false;
		}
		$needle = self::norm( $value );
		foreach ( $terms as $name ) {
			if ( '' !== $needle && false !== strpos( self::norm( $name ), $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Anmeldevariante eines Seminars ermitteln: 'direct' | 'gs'.
	 * Erst Regeln (erste Treffer gewinnt), sonst das Flag _bi_anmeldung_moeglich.
	 */
	public static function variant_for( $post_id ) {
		foreach ( self::rules() as $rule ) {
			if ( self::rule_matches( $post_id, $rule ) ) {
				return ( 'gs' === ( $rule['variant'] ?? '' ) ) ? 'gs' : 'direct';
			}
		}
		return BI_CPT::meta_bool( $post_id, '_bi_anmeldung_moeglich' ) ? 'direct' : 'gs';
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Permalink der Direktanmeldungs-Seite (konfiguriert, sonst leer) */
	public static function anmeldung_page_url() {
		$pid = (int) self::get( 'anmeldung_page_id' );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return get_permalink( $pid );
		}
		return '';
	}

	/** Permalink der Seminarübersichts-Seite mit [bi_seminarsuche] (konfiguriert, sonst leer) */
	public static function uebersicht_page_url() {
		$pid = (int) self::get( 'uebersicht_page_id' );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			return get_permalink( $pid );
		}
		return '';
	}

	/** ---------- Admin-Seite ---------- */

	public static function render_page() {
		$s      = self::all();
		$notice = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'allgemein';
		$tabs   = array(
			'allgemein'     => 'Anmeldung & Regeln',
			'seminarimport' => 'Seminar-Import',
			'plzimport'     => 'PLZ-Import',
			'datenschutz'   => 'Datenschutz',
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'allgemein';
		}
		$base = admin_url( 'admin.php?page=bi-einstellungen' );
		?>
		<div class="wrap">
			<h1>Einstellungen</h1>
			<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'seminarimport' === $tab ) {
				BI_Import::render_section();
				echo '</div>';
				return;
			}
			if ( 'plzimport' === $tab ) {
				BI_PLZ::render_section();
				echo '</div>';
				return;
			}
			if ( 'datenschutz' === $tab ) {
				self::render_privacy_section();
				echo '</div>';
				return;
			}
			?>

			<p>Hier legst du die beiden <strong>Anmeldevarianten</strong> fest. Pro Seminar steuert das Feld
			   <em>„Anmeldung möglich"</em>, welche Variante auf der Detailseite erscheint:
			   <strong>ja</strong> → Direktanmeldung (Formular), <strong>nein</strong> → nur über die Geschäftsstelle.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_save_settings">
				<?php wp_nonce_field( 'bi_save_settings' ); ?>

				<h2 class="title">Seiten</h2>
				<table class="form-table">
					<tr>
						<th><label for="uebersicht_page_id">Seminarübersicht</label></th>
						<td>
							<?php
							wp_dropdown_pages( array(
								'name'              => 'uebersicht_page_id',
								'id'                => 'uebersicht_page_id',
								'selected'          => (int) $s['uebersicht_page_id'],
								'show_option_none'  => '— automatisch erkennen —',
								'option_none_value' => 0,
							) );
							?>
							<p class="description">Die Seite mit dem Shortcode <code>[bi_seminarsuche]</code>.
								Der Button „Zur Seminarübersicht" auf der Anmelde-Bestätigung springt hierher.
								„Automatisch erkennen" sucht die passende Seite selbst.</p>
						</td>
					</tr>
				</table>

				<h2 class="title">Variante 1: Anmeldung per Formular (Direktanmeldung)</h2>
				<table class="form-table">
					<tr>
						<th><label for="anmeldung_page_id">Anmeldeseite</label></th>
						<td>
							<?php
							wp_dropdown_pages( array(
								'name'              => 'anmeldung_page_id',
								'id'                => 'anmeldung_page_id',
								'selected'          => (int) $s['anmeldung_page_id'],
								'show_option_none'  => '— automatisch erkennen —',
								'option_none_value' => 0,
							) );
							?>
							<p class="description">Die Seite, die den Shortcode <code>[bi_anmeldung]</code> enthält.
								Das Seminar wird automatisch als <code>?seminar=ID</code> übergeben.
								„Automatisch erkennen" sucht selbst die passende Seite.</p>
						</td>
					</tr>
					<tr>
						<th><label for="direct_label">Button-Text</label></th>
						<td><input type="text" class="regular-text" id="direct_label" name="direct_label" value="<?php echo esc_attr( $s['direct_label'] ); ?>"></td>
					</tr>
				</table>

				<h2 class="title">Variante 2: Anmeldung über die Geschäftsstelle</h2>
				<table class="form-table">
					<tr>
						<th><label for="gs_url">Link zur Geschäftsstellensuche</label></th>
						<td><input type="url" class="regular-text" id="gs_url" name="gs_url" value="<?php echo esc_attr( $s['gs_url'] ); ?>" placeholder="https://www.igmetall.de/vor-ort"></td>
					</tr>
					<tr>
						<th><label for="gs_hinweis">Hinweistext</label></th>
						<td><input type="text" class="large-text" id="gs_hinweis" name="gs_hinweis" value="<?php echo esc_attr( $s['gs_hinweis'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="gs_label">Button-Text</label></th>
						<td><input type="text" class="regular-text" id="gs_label" name="gs_label" value="<?php echo esc_attr( $s['gs_label'] ); ?>"></td>
					</tr>
				</table>

				<h2 class="title">Regeln: Welche Variante gilt für welches Seminar?</h2>
				<p>Die Regeln werden <strong>von oben nach unten</strong> geprüft – die <strong>erste zutreffende</strong> Regel
				   bestimmt die Variante. Trifft keine Regel zu, gilt das Seminar-Feld „Anmeldung möglich"
				   (gesetzt/ja → Direktanmeldung, nein → Geschäftsstelle).</p>
				<p class="description">
					Werte: bei Taxonomien ein <em>Teiltext</em> – z. B. <code>Bildungsurlaub</code> oder <code>37,6</code>
					(„enthält"-Vergleich; Punkt/Komma/Groß-Klein egal). Beim Flag: <code>ja</code>, <code>nein</code> oder <code>leer</code>.
				</p>

				<table class="widefat striped" style="max-width:940px;margin-bottom:8px">
					<thead><tr>
						<th style="width:230px">Wenn Feld</th>
						<th>enthält / ist</th>
						<th style="width:260px">dann Variante</th>
						<th style="width:90px">Löschen</th>
					</tr></thead>
					<tbody>
						<?php
						$rules    = self::rules();
						$rules[]  = array( 'field' => '', 'value' => '', 'variant' => 'direct' ); // Leerzeile zum Anlegen
						$last_idx = count( $rules ) - 1;
						foreach ( $rules as $i => $rule ) :
							$is_new = ( $i === $last_idx );
							?>
							<tr>
								<td>
									<select name="rule[<?php echo $i; ?>][field]">
										<option value=""><?php echo $is_new ? '— neue Regel —' : '—'; ?></option>
										<?php foreach ( self::rule_fields() as $val => $lbl ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule['field'] ?? '', $val ); ?>><?php echo esc_html( $lbl ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="text" class="regular-text" name="rule[<?php echo $i; ?>][value]" value="<?php echo esc_attr( $rule['value'] ?? '' ); ?>" placeholder="z. B. Bildungsurlaub / 37,6 / ja"></td>
								<td>
									<select name="rule[<?php echo $i; ?>][variant]">
										<?php foreach ( self::rule_variants() as $val => $lbl ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule['variant'] ?? 'direct', $val ); ?>><?php echo esc_html( $lbl ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><?php if ( ! $is_new ) : ?><label><input type="checkbox" name="rule[<?php echo $i; ?>][delete]" value="1"> löschen</label><?php endif; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>
		</div>
		<?php
	}

	/* ===================================================================
	 *  Datenschutz-Textbaustein
	 * =================================================================== */

	/**
	 * Vorschlagstext für die Datenschutzerklärung, als Abschnitte.
	 * Bewusst mit eckigen Klammern als Lücken dort, wo nur die Betreiberin
	 * die Antwort kennt (Aufbewahrungsfristen, Name des Consent-Werkzeugs).
	 *
	 * @return array [ ['title' => string, 'paragraphs' => string[]], … ]
	 */
	public static function privacy_sections() {
		$cookie = BI_Tracking::COOKIE;
		$tage   = BI_Tracking::COOKIE_DAYS;
		$param  = BI_Tracking::PARAM;

		return array(
			array(
				'title'      => 'Anmeldung zu Seminaren',
				'paragraphs' => array(
					'Über das Anmeldeformular für Seminare verarbeiten wir die von dir eingegebenen Daten: '
						. 'Anrede, Titel, Vor- und Nachname, private Anschrift, Telefon- und Mobilnummer, E-Mail-Adresse, '
						. 'Angabe zur Mitgliedschaft in der IG Metall einschließlich Mitgliedsnummer, Betrieb mit Anschrift '
						. 'und dienstlicher E-Mail-Adresse, '
						. 'Funktion im Betriebsrat, Art der Freistellung sowie deine freiwilligen Bemerkungen.',
					'Zweck der Verarbeitung ist die Bearbeitung deiner Anmeldung und die Durchführung des Seminars. '
						. 'Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Durchführung vorvertraglicher Maßnahmen und des '
						. 'Vertragsverhältnisses), für freiwillige Angaben Art. 6 Abs. 1 lit. a DSGVO.',
					'Die Anmeldung wird in der Datenbank dieser Website gespeichert und zusätzlich per E-Mail an die '
						. 'zuständigen Stellen übermittelt: an die anhand der Postleitzahl deines Betriebs ermittelte '
						. 'Geschäftsstelle sowie – soweit für das Seminar eingerichtet – an das durchführende '
						. 'Bildungszentrum und an die hinterlegte Ansprechperson. Eine Bestätigung geht an die von dir angegebene E-Mail-Adresse. Eine Übermittlung '
						. 'in Drittstaaten findet nicht statt.',
					'Wir speichern die Anmeldedaten [Aufbewahrungsdauer ergänzen, z. B. „bis zum Ablauf der gesetzlichen '
						. 'Aufbewahrungsfristen, längstens X Jahre nach Ende des Seminars"] und löschen sie anschließend.',
				),
			),
			array(
				'title'      => 'Reichweitenmessung bei Newsletter- und Mailing-Links',
				'paragraphs' => array(
					'Links, mit denen wir in Newslettern, Mailings oder Anzeigen auf unser Seminarangebot hinweisen, können '
						. 'eine Kampagnenkennung enthalten (Adressen der Form https://…/?' . $param . '=…). Rufst du einen solchen '
						. 'Link auf, speichern wir in deinem Browser das Cookie „' . $cookie . '". Es enthält eine zufällig '
						. 'erzeugte Kennung und die Nummer der Kampagne, wird ausschließlich von dieser Website gesetzt '
						. '(First-Party-Cookie) und läuft nach ' . $tage . ' Tagen ab.',
					'Anhand dieser Kennung halten wir fest, welche Schritte auf den Klick folgen: der Aufruf des Links, das '
						. 'Ansehen einer Seminarseite, das Öffnen des Anmeldeformulars und das Absenden einer Anmeldung – '
						. 'jeweils mit Zeitpunkt und betroffenem Seminar. Weder deine IP-Adresse noch Angaben zu Browser oder '
						. 'Gerät werden dabei gespeichert. Die Kennung ist eine Zufallsfolge ohne Bezug zu deiner Person. '
						. 'Erst wenn du eine Anmeldung absendest, wird bei dieser Anmeldung zusätzlich die Bezeichnung der '
						. 'Kampagne vermerkt.',
					'Zweck ist die Reichweitenmessung: Wir möchten auswerten, wie viele Anmeldungen auf ein bestimmtes '
						. 'Mailing zurückgehen, um unsere Informationsangebote gezielter zu gestalten. Das Speichern des '
						. 'Cookies und das Auslesen der darin enthaltenen Information erfolgen auf Grundlage deiner '
						. 'Einwilligung nach § 25 Abs. 1 TDDDG, die anschließende Verarbeitung der Daten auf Grundlage von '
						. 'Art. 6 Abs. 1 lit. a DSGVO.',
					'Du kannst deine Einwilligung jederzeit mit Wirkung für die Zukunft widerrufen [Hinweis auf das '
						. 'Einwilligungs-Werkzeug ergänzen, z. B. „über die Cookie-Einstellungen am Seitenende"] und das '
						. 'Cookie jederzeit in den Einstellungen deines Browsers löschen. Ohne das Cookie findet keine '
						. 'Zuordnung statt; die Nutzung unseres Seminarangebots ist davon nicht berührt.',
					'Die erfassten Ereignisse werden automatisch gelöscht, sobald sie älter als zwölf Monate sind. Die Angabe, über welche Kampagne '
						. 'eine Anmeldung zustande kam, wird gemeinsam mit der Anmeldung gelöscht.',
				),
			),
		);
	}

	/** Vorschlagstext als reiner Text (zum Kopieren) */
	public static function privacy_text() {
		$out = array();
		foreach ( self::privacy_sections() as $s ) {
			$out[] = $s['title'];
			$out[] = str_repeat( '-', mb_strlen( $s['title'] ) );
			$out[] = '';
			foreach ( $s['paragraphs'] as $p ) {
				$out[] = $p;
				$out[] = '';
			}
		}
		return trim( implode( "\n", $out ) ) . "\n";
	}

	/** Vorschlagstext als HTML (für den WordPress-Datenschutz-Leitfaden) */
	public static function privacy_html() {
		$html = '';
		foreach ( self::privacy_sections() as $s ) {
			$html .= '<h3>' . esc_html( $s['title'] ) . '</h3>';
			foreach ( $s['paragraphs'] as $p ) {
				$html .= '<p class="privacy-policy-tutorial">' . esc_html( $p ) . '</p>';
			}
		}
		return $html;
	}

	public static function register_privacy_policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Bildungsprogramm', self::privacy_html() );
		}
	}

	private static function render_privacy_section() {
		$text = self::privacy_text();
		?>
		<h2 class="title">Textbaustein für die Datenschutzerklärung</h2>
		<p>Dieser Vorschlag beschreibt, was das Plugin tatsächlich verarbeitet: die Daten aus dem
			Anmeldeformular und die Reichweitenmessung der Kampagnen-Links. Die Stellen in
			<code>[eckigen Klammern]</code> musst du selbst füllen – nur du kennst eure Aufbewahrungsfristen
			und euer Einwilligungs-Werkzeug.</p>

		<div class="notice notice-warning inline" style="margin:12px 0;padding:10px 14px">
			<p style="margin:0"><strong>Keine Rechtsberatung.</strong> Der Text ist ein Entwurf zum Weiterreichen an
				die Stelle, die bei euch für Datenschutz zuständig ist – ungeprüft übernehmen solltest du ihn nicht.
				Besonders zu klären: Das Cookie der Reichweitenmessung ist technisch nicht zwingend erforderlich.
				Ohne Einwilligung (Consent-Banner) fehlt ihm nach § 25 Abs. 1 TDDDG die Grundlage – der Text ist
				entsprechend auf Einwilligung formuliert. Wird kein Banner eingesetzt, ist entweder eines nötig oder
				die Kampagnen-Auswertung bleibt ungenutzt.</p>
		</div>

		<p>
			<button type="button" class="button button-primary" id="bi-copy-privacy">In die Zwischenablage kopieren</button>
			<span id="bi-copy-done" style="display:none;color:#1a7f37;margin-left:8px">kopiert ✓</span>
		</p>

		<textarea id="bi-privacy-text" class="large-text code" rows="26" readonly onclick="this.select()"><?php echo esc_textarea( $text ); ?></textarea>

		<script>
		document.getElementById('bi-copy-privacy').addEventListener('click', function () {
			var ta = document.getElementById('bi-privacy-text');
			ta.select();
			var ok = false;
			try { ok = document.execCommand('copy'); } catch (e) {}
			if (!ok && navigator.clipboard) { navigator.clipboard.writeText(ta.value); ok = true; }
			if (ok) {
				var hint = document.getElementById('bi-copy-done');
				hint.style.display = 'inline';
				setTimeout(function () { hint.style.display = 'none'; }, 2000);
			}
		});
		</script>

		<p class="description" style="margin-top:10px">Derselbe Text steht auch im WordPress-eigenen
			Richtlinien-Leitfaden unter <em>Werkzeuge → Datenschutz</em> bereit.</p>

		<h2 class="title">Was das Plugin technisch speichert</h2>
		<table class="widefat striped" style="max-width:940px">
			<thead><tr><th style="width:280px">Wo</th><th>Was</th></tr></thead>
			<tbody>
				<tr><td><code><?php echo esc_html( BI_Registration::table() ); ?></code></td>
					<td>Alle Angaben aus dem Anmeldeformular, Zeitpunkt, Seminar-Bezug und – falls vorhanden –
						die Kampagne, über die die Anmeldung zustande kam.</td></tr>
				<tr><td><code><?php echo esc_html( BI_Tracking::table_events() ); ?></code></td>
					<td>Ereignisse der Kampagnen-Auswertung: Zufallskennung, Art des Schritts, Seminar, Zeitpunkt.
						Keine IP-Adresse, kein User-Agent.</td></tr>
				<tr><td><code><?php echo esc_html( BI_Tracking::table_kampagnen() ); ?></code></td>
					<td>Die von euch angelegten Kampagnen (Bezeichnung, Kürzel, Ziel) – keine Personendaten.</td></tr>
				<tr><td>Cookie <code><?php echo esc_html( BI_Tracking::COOKIE ); ?></code></td>
					<td>Zufallskennung + Kampagnennummer, <?php echo (int) BI_Tracking::COOKIE_DAYS; ?> Tage Laufzeit,
						First-Party, nur gesetzt nach Klick auf einen Kampagnen-Link.</td></tr>
				<tr><td><code><?php echo esc_html( BI_PLZ::table() ); ?></code></td>
					<td>Zuordnung Postleitzahl → Geschäftsstelle samt E-Mail. Stammdaten der Organisation,
						keine Daten der Anmeldenden.</td></tr>
			</tbody>
		</table>
		<p class="description">Ereignisse älter als zwölf Monate lassen sich unter <em>Kampagnen</em> löschen.</p>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_settings' );

		$out = array(
			'anmeldung_page_id'  => intval( $_POST['anmeldung_page_id'] ?? 0 ),
			'uebersicht_page_id' => intval( $_POST['uebersicht_page_id'] ?? 0 ),
			'gs_url'            => esc_url_raw( wp_unslash( $_POST['gs_url'] ?? '' ) ),
			'direct_label'      => sanitize_text_field( wp_unslash( $_POST['direct_label'] ?? '' ) ),
			'gs_label'          => sanitize_text_field( wp_unslash( $_POST['gs_label'] ?? '' ) ),
			'gs_hinweis'        => sanitize_text_field( wp_unslash( $_POST['gs_hinweis'] ?? '' ) ),
		);
		// Leere Texte auf Default zurücksetzen
		$def = self::defaults();
		foreach ( array( 'gs_url', 'direct_label', 'gs_label', 'gs_hinweis' ) as $k ) {
			if ( '' === $out[ $k ] ) {
				$out[ $k ] = $def[ $k ];
			}
		}

		// Regeln einsammeln (Reihenfolge bleibt erhalten = Prüfreihenfolge)
		$rules        = array();
		$valid_fields = array_keys( self::rule_fields() );
		$in_rules     = ( isset( $_POST['rule'] ) && is_array( $_POST['rule'] ) ) ? wp_unslash( $_POST['rule'] ) : array();
		foreach ( $in_rules as $r ) {
			if ( ! empty( $r['delete'] ) ) {
				continue;
			}
			$field = $r['field'] ?? '';
			$value = trim( (string) ( $r['value'] ?? '' ) );
			if ( ! in_array( $field, $valid_fields, true ) || '' === $value ) {
				continue; // unvollständige/Leerzeile verwerfen
			}
			$rules[] = array(
				'field'   => $field,
				'value'   => sanitize_text_field( $value ),
				'variant' => ( 'gs' === ( $r['variant'] ?? '' ) ) ? 'gs' : 'direct',
			);
		}
		$out['rules'] = $rules;

		update_option( self::OPTION, $out );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-einstellungen', 'bi_msg' => rawurlencode( 'Einstellungen gespeichert.' ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
