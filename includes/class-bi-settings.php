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
