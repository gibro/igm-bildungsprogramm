<?php
/**
 * Admin-Menüstruktur. Bündelt alle Seiten unter „Bildungsprogramm".
 *
 *   Bildungsprogramm (Hauptmenüpunkt -> Übersicht / Mini-Dashboard, Startseite des Plugins)
 *   ├─ Übersicht           (Mini-Dashboard mit Kennzahlen + Einrichtungs-Anleitung)
 *   ├─ Seminare            (CPT – via show_in_menu)
 *   ├─ Anmeldungen
 *   ├─ Mail-Benachrichtigungen
 *   ├─ Kampagnen             (Newsletter-Links + Auswertung Klick → Anmeldung)
 *   ├─ Marketing-Kacheln    (Kachel-Builder, hängt sich in class-bi-kacheln.php ein)
 *   └─ Einstellungen        (Tabs: Anmeldung & Regeln · Seminar-Import · PLZ-Import — immer zuletzt)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		// Spät genug, damit auch das per show_in_menu eingehängte CPT-Untermenü „Seminare" schon da ist.
		add_action( 'admin_menu', array( __CLASS__, 'reorder_submenu' ), 999 );
	}

	public static function menu() {
		add_menu_page(
			'Bildungsprogramm',
			'Bildungsprogramm',
			'manage_options',
			'bi-seminarsuche',
			array( __CLASS__, 'render_overview' ), // Startseite = Mini-Dashboard
			'dashicons-search',
			26
		);

		// Untermenü „Übersicht" (gleicher Slug wie Hauptpunkt -> Landeseite des Plugins)
		add_submenu_page( 'bi-seminarsuche', 'Übersicht', 'Übersicht', 'manage_options', 'bi-seminarsuche', array( __CLASS__, 'render_overview' ) );

		// CPT „Seminare" hängt über show_in_menu='bi-seminarsuche' automatisch hier ein.

		add_submenu_page( 'bi-seminarsuche', 'Anmeldungen', 'Anmeldungen', 'manage_options', 'bi-anmeldungen', array( 'BI_Registration', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Mail-Benachrichtigungen', 'Mail-Benachrichtigungen', 'manage_options', 'bi-mail-trigger', array( 'BI_Mailer', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Kampagnen', 'Kampagnen', 'manage_options', 'bi-kampagnen', array( 'BI_Tracking', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Einstellungen', 'Einstellungen', 'manage_options', 'bi-einstellungen', array( 'BI_Settings', 'render_page' ) );
	}

	/**
	 * Sorgt dafür, dass „Übersicht" das erste und „Einstellungen" das letzte Untermenü ist.
	 * Das CPT „Seminare" wird von WordPress per show_in_menu eingehängt und stünde sonst vorn;
	 * später registrierte Punkte (z. B. „Marketing-Kacheln") landen sonst hinter den Einstellungen.
	 */
	public static function reorder_submenu() {
		global $submenu;
		if ( empty( $submenu['bi-seminarsuche'] ) ) {
			return;
		}

		$items         = $submenu['bi-seminarsuche'];
		$uebersicht    = array();
		$einstellungen = array();
		$rest          = array();

		foreach ( $items as $item ) {
			// $item[2] = menu_slug; die Übersicht teilt sich den Slug mit dem Hauptpunkt.
			if ( isset( $item[2] ) && 'bi-seminarsuche' === $item[2] ) {
				$uebersicht[] = $item;
			} elseif ( isset( $item[2] ) && 'bi-einstellungen' === $item[2] ) {
				$einstellungen[] = $item;
			} else {
				$rest[] = $item;
			}
		}

		// Neu indexieren, damit WordPress die Reihenfolge übernimmt.
		$submenu['bi-seminarsuche'] = array_values( array_merge( $uebersicht, $rest, $einstellungen ) );
	}

	/* ===================================================================
	 *  Mini-Dashboard
	 * =================================================================== */

	public static function render_overview() {
		global $wpdb;

		$today = current_time( 'Y-m-d' );
		$now   = current_time( 'timestamp' );
		$table = BI_Registration::table();

		// ---- Kennzahlen ------------------------------------------------
		$seminars_pub = (int) ( wp_count_posts( BI_CPT )->publish ?? 0 );

		$kommend = function ( $post_type ) use ( $today ) {
			$q = new WP_Query( array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					array( 'key' => '_bi_startdatum', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
				),
			) );
			return (int) $q->found_posts;
		};
		$seminars_kommend = $kommend( BI_CPT );

		$online_pub     = (int) ( wp_count_posts( BI_ONLINE )->publish ?? 0 );
		$online_kommend = $kommend( BI_ONLINE );

		$anm_30 = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE created >= %s",
			date( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS )
		) );

		// ---- Hinweise (nur anzeigen, wenn etwas anliegt) ---------------
		$hinweise = array();

		// Welche Empfänger-Typen nutzen aktive Mail-Trigger? Danach richtet sich,
		// welche fehlenden Adressen überhaupt relevant sind.
		$active_types = array();
		foreach ( BI_Mailer::get_triggers() as $tr ) {
			if ( ! empty( $tr['active'] ) && ! empty( $tr['type'] ) ) {
				$active_types[ $tr['type'] ] = true;
			}
		}

		// (1) Mail-Test-Modus aktiv?
		$test = BI_Mailer::get_test();
		if ( ! empty( $test['enabled'] ) && is_email( $test['address'] ) ) {
			$hinweise[] = sprintf(
				'<strong>E-Mail-Test-Modus aktiv:</strong> alle Benachrichtigungen gehen an <code>%s</code> statt an die echten Empfänger. <a href="%s">Mail-Einstellungen öffnen</a>',
				esc_html( $test['address'] ),
				esc_url( admin_url( 'admin.php?page=bi-mail-trigger' ) )
			);
		}

		// (1b) Wöchentliche Zusammenfassung eingerichtet, aber kein Cron-Termin geplant
		//      (z. B. weil WP-Cron per DISABLE_WP_CRON abgeschaltet wurde) – die
		//      Warteschlange würde dann immer weiter wachsen, ohne je rauszugehen.
		$weekly_active = false;
		foreach ( BI_Mailer::get_triggers() as $tr ) {
			if ( ! empty( $tr['active'] ) && 'weekly' === ( $tr['schedule'] ?? 'instant' ) ) {
				$weekly_active = true;
				break;
			}
		}
		if ( $weekly_active && ! wp_next_scheduled( BI_Mailer::CRON_HOOK ) ) {
			$hinweise[] = sprintf(
				'<strong>Wöchentliche Zusammenfassung ohne geplanten Termin:</strong> Es warten %d Anmeldung(en) in der Warteschlange, aber es ist kein Cron-Lauf eingeplant. <a href="%s">Versandtermin neu speichern</a> – bleibt das Problem, ist WP-Cron auf dem Server abgeschaltet.',
				count( BI_Mailer::get_queue() ),
				esc_url( admin_url( 'admin.php?page=bi-mail-trigger' ) )
			);
		}

		// (2) Bildungszentren ohne hinterlegte E-Mail – nur relevant, wenn ein aktiver
		//     Trigger vom Typ „bildungszentrum" diese Adresse tatsächlich nutzt.
		if ( isset( $active_types['bildungszentrum'] ) ) {
			$ohne_mail = array();
			$ort_terms = get_terms( array( 'taxonomy' => BI_TAX_ORT, 'hide_empty' => false ) );
			if ( is_array( $ort_terms ) ) {
				foreach ( $ort_terms as $t ) {
					if ( ! get_term_meta( $t->term_id, 'email', true ) ) {
						$ohne_mail[] = $t->name;
					}
				}
			}
			if ( $ohne_mail ) {
				$names = esc_html( implode( ', ', array_slice( $ohne_mail, 0, 8 ) ) ) . ( count( $ohne_mail ) > 8 ? ' …' : '' );
				$hinweise[] = sprintf(
					'<strong>%s ohne hinterlegte E-Mail</strong> – der Trigger „Benachrichtigung an Bildungszentrum" läuft dort ins Leere: %s. <a href="%s">Bearbeiten</a>',
					1 === count( $ohne_mail ) ? '1 Bildungszentrum' : count( $ohne_mail ) . ' Bildungszentren',
					$names,
					esc_url( admin_url( 'edit-tags.php?taxonomy=' . BI_TAX_ORT . '&post_type=' . BI_CPT ) )
				);
			}
		}

		// (2b) Kommende Seminare ohne Ansprechpartner-E-Mail – nur relevant, wenn ein
		//      aktiver Trigger vom Typ „ansprechpartner" diese Adresse nutzt.
		if ( isset( $active_types['ansprechpartner'] ) ) {
			// Je Beitragstyp getrennt zählen, damit der Link auf die richtige Liste zeigt.
			// Beide nutzen denselben Meta-Schlüssel; bei Online-Seminaren heißt das Feld
			// nur „Anmeldung (E-Mail)".
			foreach ( bi_seminar_post_types() as $pt ) {
				$q_ohne_ap = new WP_Query( array(
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_query'     => array(
						'relation' => 'AND',
						array( 'key' => '_bi_startdatum', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
						array(
							'relation' => 'OR',
							array( 'key' => '_bi_ansprechpartner_email', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_bi_ansprechpartner_email', 'value' => '', 'compare' => '=' ),
						),
					),
				) );
				$ohne_ap = (int) $q_ohne_ap->found_posts;
				if ( ! $ohne_ap ) {
					continue;
				}
				$wort  = ( BI_ONLINE === $pt ) ? 'Online-Seminar' : 'Seminar';
				$feld  = ( BI_ONLINE === $pt ) ? 'Anmelde-E-Mail' : 'Ansprechpartner-E-Mail';
				$hinweise[] = sprintf(
					'<strong>%s ohne %s</strong> – der Trigger „Benachrichtigung an Ansprechpartner" kann dort nicht versendet werden. <a href="%s">Prüfen</a>',
					1 === $ohne_ap ? '1 kommendes ' . $wort : $ohne_ap . ' kommende ' . $wort . 'e',
					$feld,
					esc_url( admin_url( 'edit.php?post_type=' . $pt . '&bi_missing_ap=1' ) )
				);
			}
		}

		// (2c) Online-Seminare, die als Teams-Webinar gepflegt sind, aber keinen
		//      Anmeldelink haben – die Weiche fällt dort still aufs interne Formular zurück.
		$ohne_link = BI_Online::ohne_anmeldelink();
		if ( $ohne_link ) {
			$hinweise[] = sprintf(
				'<strong>%s als Teams-Webinar ohne Anmeldelink</strong> – dort greift statt der externen Anmeldeseite das interne Formular. <a href="%s">Online-Seminare prüfen</a>',
				1 === count( $ohne_link ) ? '1 kommendes Online-Seminar' : count( $ohne_link ) . ' kommende Online-Seminare',
				esc_url( admin_url( 'edit.php?post_type=' . BI_ONLINE . '&bi_missing_link=1' ) )
			);
		}

		// (3) Veröffentlichte Seminare ohne Startdatum – je Beitragstyp getrennt,
		//     damit der Link direkt in die passende Liste führt.
		foreach ( bi_seminar_post_types() as $pt ) {
			$q_ohne_datum = new WP_Query( array(
				'post_type'      => $pt,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_bi_startdatum', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_bi_startdatum', 'value' => '', 'compare' => '=' ),
				),
			) );
			$ohne_datum = (int) $q_ohne_datum->found_posts;
			if ( ! $ohne_datum ) {
				continue;
			}
			$wort = ( BI_ONLINE === $pt ) ? 'Online-Seminar' : 'Seminar';
			$hinweise[] = sprintf(
				'<strong>%s ohne Startdatum</strong> – nicht buchbar und nicht im Datumsfilter sichtbar. <a href="%s">Prüfen</a>',
				1 === $ohne_datum ? '1 veröffentlichtes ' . $wort : $ohne_datum . ' veröffentlichte ' . $wort . 'e',
				esc_url( admin_url( 'edit.php?post_type=' . $pt . '&bi_missing_start=1' ) )
			);
		}

		// ---- Letzte 5 Anmeldungen --------------------------------------
		$letzte = $wpdb->get_results(
			"SELECT created, vorname, nachname, seminar_id FROM $table ORDER BY created DESC, id DESC LIMIT 5",
			ARRAY_A
		);

		// ---- Nächste startende Seminare (14 Tage) ----------------------
		$bis    = date( 'Y-m-d', $now + 14 * DAY_IN_SECONDS );
		$q_next = new WP_Query( array(
			'post_type'      => bi_seminar_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => 6,
			'meta_key'       => '_bi_startdatum',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_type'      => 'DATE',
			'meta_query'     => array(
				array( 'key' => '_bi_startdatum', 'value' => array( $today, $bis ), 'compare' => 'BETWEEN', 'type' => 'DATE' ),
			),
		) );
		?>
		<div class="wrap">
			<h1>Bildungsprogramm</h1>
			<p>Eigenständiges Seminar-System – unabhängig von Formidable.</p>

			<?php // ---- KPI-Kacheln ---- ?>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0">
				<?php
				self::card(
					number_format_i18n( $seminars_pub ),
					'Präsenz-Seminare (veröffentlicht)',
					admin_url( 'edit.php?post_type=' . BI_CPT ),
					sprintf( 'davon %s kommend', number_format_i18n( $seminars_kommend ) )
				);
				self::card(
					number_format_i18n( $online_pub ),
					'Online-Seminare (veröffentlicht)',
					admin_url( 'edit.php?post_type=' . BI_ONLINE ),
					sprintf( 'davon %s kommend', number_format_i18n( $online_kommend ) )
				);
				self::card(
					number_format_i18n( $anm_30 ),
					'Anmeldungen (letzte 30 Tage)',
					admin_url( 'admin.php?page=bi-anmeldungen' )
				);
				?>
			</div>

			<?php // ---- Hinweis-Box (nur bei Bedarf) ---- ?>
			<?php if ( $hinweise ) : ?>
				<div style="background:#fcf9e8;border:1px solid #dba617;border-left-width:4px;padding:12px 16px;margin:0 0 20px;max-width:980px">
					<h2 style="margin:.2em 0 .4em;font-size:14px">Braucht Aufmerksamkeit</h2>
					<ul style="margin:0;padding-left:18px;list-style:disc">
						<?php foreach ( $hinweise as $h ) : ?>
							<li style="margin:4px 0"><?php echo wp_kses_post( $h ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php // ---- Zwei Listen nebeneinander ---- ?>
			<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;margin-bottom:20px">

				<div class="card" style="flex:1;min-width:340px;margin:0">
					<h2 style="margin-top:0">Letzte Anmeldungen</h2>
					<?php if ( $letzte ) : ?>
						<table class="widefat striped">
							<thead><tr><th>Datum</th><th>Name</th><th>Seminar</th></tr></thead>
							<tbody>
							<?php foreach ( $letzte as $r ) : ?>
								<?php
								$titel = $r['seminar_id'] ? get_the_title( (int) $r['seminar_id'] ) : '';
								$edit  = $r['seminar_id'] ? get_edit_post_link( (int) $r['seminar_id'] ) : '';
								?>
								<tr>
									<td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $r['created'] ) ) ); ?></td>
									<td><?php echo esc_html( trim( $r['vorname'] . ' ' . $r['nachname'] ) ); ?></td>
									<td>
										<?php if ( $titel && $edit ) : ?>
											<a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $titel ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $titel ?: '—' ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<p style="margin:10px 0 0"><a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-anmeldungen' ) ); ?>">Alle Anmeldungen →</a></p>
					<?php else : ?>
						<p style="color:#646970">Noch keine Anmeldungen vorhanden.</p>
					<?php endif; ?>
				</div>

				<div class="card" style="flex:1;min-width:340px;margin:0">
					<h2 style="margin-top:0">Nächste Seminare (14 Tage)</h2>
					<?php if ( $q_next->have_posts() ) : ?>
						<table class="widefat striped">
							<thead><tr><th style="width:110px">Start</th><th>Seminar</th></tr></thead>
							<tbody>
							<?php
							while ( $q_next->have_posts() ) :
								$q_next->the_post();
								$d = get_post_meta( get_the_ID(), '_bi_startdatum', true );
								?>
								<tr>
									<td><?php echo esc_html( $d ? date_i18n( 'd.m.Y', strtotime( $d ) ) : '—' ); ?></td>
									<td><a href="<?php echo esc_url( get_edit_post_link() ); ?>"><?php echo esc_html( get_the_title() ); ?></a></td>
								</tr>
							<?php endwhile; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p style="color:#646970">In den nächsten 14 Tagen startet kein Seminar.</p>
					<?php endif; ?>
					<?php wp_reset_postdata(); ?>
				</div>

			</div>

			<?php // ---- Einrichtungs-Anleitung (eingeklappt) ---- ?>
			<details class="card" style="max-width:980px">
				<summary style="cursor:pointer;font-size:14px;font-weight:600;padding:4px 0">So richtest du das Plugin ein &amp; Shortcodes</summary>
				<div style="margin-top:12px">
					<ol>
						<li><strong>PLZ importieren</strong> → <em>Einstellungen → Tab „PLZ-Import"</em>, CSV mit Spalten PLZ / Geschäftsstelle / E-Mail.</li>
						<li><strong>Seminare importieren</strong> → <em>Einstellungen → Tab „Seminar-Import"</em>, CSV hochladen und Spalten zuordnen.</li>
						<li><strong>Online-Seminare importieren</strong> → <em>Einstellungen → Tab „Online-Seminar-Import"</em>. Eigenes Feldset
							(Referent*innen, Veranstalter*in, Webinar-Tool, Anmeldelink …); Zielgruppen und Freistellungen teilen sich
							beide Seminarformen. Für Teams-<em>Webinare</em> den <em>Anmeldelink</em> setzen – dann führt der
							Buchungs-Button auf die Anmeldeseite des Webinars statt ins eigene Formular.</li>
						<li><strong>Bildungszentren-Mails</strong> → unter „Seminare → Bildungszentrum" je Term eine E-Mail eintragen (für den Trigger „Bildungszentrum").</li>
						<li><strong>Mail-Benachrichtigungen</strong> prüfen/anpassen → gleichnamiger Menüpunkt.
							Dort je Benachrichtigung auch die <em>Antwortadresse</em> und die <em>PDF-Anhänge</em>
							(Seminardetails, Beschlussvorlage nach § 37 Abs. 6 BetrVG) einstellen.</li>
						<li><strong>PDF-Anhänge</strong> vorbereiten → <em>Einstellungen → Tab „PDF-Anhänge"</em>:
							Logo hochladen und Name/Anschrift des Veranstalters eintragen, dann die Vorschau prüfen.</li>
						<li><strong>Einstellungen</strong> → Anmeldevarianten konfigurieren: Direktanmeldung-Seite und Link zur Geschäftsstellensuche.</li>
						<li><strong>Such-Seite</strong> anlegen mit Shortcode
							<code>[bi_seminarsuche anmeldung_url="/anmeldung"]</code>.</li>
						<li><strong>Anmelde-Seite</strong> anlegen mit Shortcode <code>[bi_anmeldung]</code>
							(das Seminar kommt automatisch per <code>?seminar=ID</code> aus der Suche).</li>
					</ol>
					<h3>Shortcodes</h3>
					<table class="widefat striped" style="max-width:780px">
						<tbody>
							<tr><td><code>[bi_seminarsuche]</code></td><td>Such-/Filterleiste mit Ergebnisliste – Präsenz- und Online-Seminare gemeinsam, getrennt über den Filter-Chip „Seminarform". Attribute: <code>anmeldung_url</code>, <code>per_page</code>, <code>programm</code> (auf ein Programmjahr beschränken, z.&nbsp;B. <code>programm="2026"</code>), <code>form</code> (<code>praesenz</code> oder <code>online</code> – blendet den Chip aus und zeigt nur diese Form).</td></tr>
							<tr><td><code>[bi_suchmaske]</code></td><td>Nur die Suchmaske ohne Ergebnisliste – z.&nbsp;B. für Startseite oder Sidebar. Die Filter wirken hier nicht sofort; der Button „Suche starten" springt mit der Auswahl auf die Seminarübersicht. Attribute: <code>ziel_url</code> (Standard: Einstellung „Seminarübersicht"), <code>button</code>, <code>titel</code>, <code>kicker</code>, <code>hinweis</code>, <code>programm</code>.</td></tr>
							<tr><td><code>[bi_anmeldung]</code></td><td>Anmeldeformular. Attribut: <code>seminar="ID"</code> für festes Seminar.</td></tr>
						</tbody>
					</table>
				</div>
			</details>
		</div>
		<?php
	}

	/** Eine KPI-Kachel rendern (Zahl groß, Label darunter, optional Sub-Zeile). */
	private static function card( $value, $label, $href, $sub = '' ) {
		printf(
			'<a href="%s" style="flex:1;min-width:200px;background:#fff;border:1px solid #ccd0d4;padding:18px 22px;text-decoration:none;color:#1d2327">'
			. '<div style="font-size:32px;font-weight:700;line-height:1.1">%s</div>'
			. '<div style="color:#646970">%s</div>'
			. '%s'
			. '</a>',
			esc_url( $href ),
			esc_html( $value ),
			esc_html( $label ),
			$sub ? '<div style="color:#646970;font-size:12px;margin-top:4px">' . esc_html( $sub ) . '</div>' : ''
		);
	}
}
