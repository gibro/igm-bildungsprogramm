<?php
/**
 * Admin-Menüstruktur. Bündelt alle Seiten unter „Bildungsprogramm".
 *
 *   Bildungsprogramm (Hauptmenüpunkt -> Übersicht / Mini-Dashboard, Startseite des Plugins)
 *   ├─ Übersicht           (Mini-Dashboard mit Kennzahlen + Einrichtungs-Anleitung)
 *   ├─ Seminare            (CPT – via show_in_menu; enthält auch die Online-Seminare)
 *   ├─ Ausbildungsreihen   (CPT – via show_in_menu)
 *   ├─ Anmeldungen
 *   ├─ Benachrichtigungen  (Mail-Trigger)
 *   ├─ Kampagnen           (Newsletter-Links + Auswertung Klick → Anmeldung)
 *   ├─ Marketing           (Builder für Kacheln und Listen, hängt sich in class-bi-kacheln.php ein)
 *   ├─ Datenpflege         (Arbeitsmenge filtern, CSV-/JSON-Export, Paket-Import)
 *   └─ Einstellungen       (Tabs: Anmeldung & Regeln · Such-Filterleiste · Importe …)
 *
 * Die Reihenfolge steht in MENU_ORDER und wird nachträglich hergestellt: Die
 * beiden Beitragstypen hängt WordPress selbst ein (show_in_menu), und die
 * Kacheln registrieren sich aus ihrer eigenen Klasse. Ohne das Nachsortieren
 * richtet sich die Reihenfolge danach, wer zufällig zuerst dran war.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Admin {

	/**
	 * Gewünschte Reihenfolge der Untermenüpunkte (Menü-Slugs).
	 *
	 * Punkte, die hier nicht stehen, landen hinter den aufgeführten und vor den
	 * Einstellungen – ein neu registrierter Punkt verschwindet also nicht, er
	 * sortiert sich nur nicht von selbst an die gewünschte Stelle.
	 */
	const MENU_ORDER = array(
		'bi-seminarsuche',                 // Übersicht (teilt den Slug mit dem Hauptpunkt)
		'edit.php?post_type=bi_seminar',   // Seminare (Präsenz + Online in einer Liste)
		'edit.php?post_type=bi_reihe',     // Ausbildungsreihen
		'bi-anmeldungen',
		'bi-formulare',                    // Anmeldeformulare (Felder, Seiten, Zuordnung)
		'bi-mail-trigger',                 // Benachrichtigungen
		'bi-kampagnen',
		'bi-kacheln',                      // Marketing (Kacheln + Listen; Slug aus der Zeit der Kacheln)
		'bi-datenpflege',
		'bi-einstellungen',                // immer zuletzt
	);

	/**
	 * Link in die Seminarliste, wahlweise auf eine Seminarform eingegrenzt.
	 *
	 * Seit Präsenz- und Online-Seminare gemeinsam unter „Seminare" stehen,
	 * führen alle Hinweise dorthin – nicht mehr in die eigene Online-Liste.
	 * Sonst landete man auf einem Bildschirm, zu dem das Menü keinen Punkt
	 * mehr hat, und wüsste nicht, wo man ist.
	 *
	 * @param string $form  '' (beide), 'praesenz' oder 'online'.
	 * @param array  $extra Weitere Query-Argumente, z. B. bi_missing_start.
	 */
	public static function seminarliste_url( $form = '', $extra = array() ) {
		$args = array( 'post_type' => BI_CPT );
		if ( '' !== $form ) {
			$args['bi_form'] = $form;
		}
		return add_query_arg( array_merge( $args, $extra ), admin_url( 'edit.php' ) );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		// Spät genug, damit auch das per show_in_menu eingehängte CPT-Untermenü „Seminare" schon da ist.
		add_action( 'admin_menu', array( __CLASS__, 'reorder_submenu' ), 999 );
	}

	public static function menu() {
		add_menu_page(
			'Bildungsprogramm',
			'Bildungsprogramm',
			BI_CAP,
			'bi-seminarsuche',
			array( __CLASS__, 'render_overview' ), // Startseite = Mini-Dashboard
			'dashicons-search',
			26
		);

		// Untermenü „Übersicht" (gleicher Slug wie Hauptpunkt -> Landeseite des Plugins)
		add_submenu_page( 'bi-seminarsuche', 'Übersicht', 'Übersicht', BI_CAP, 'bi-seminarsuche', array( __CLASS__, 'render_overview' ) );

		// CPT „Seminare" hängt über show_in_menu='bi-seminarsuche' automatisch hier ein.

		add_submenu_page( 'bi-seminarsuche', 'Anmeldungen', 'Anmeldungen', BI_CAP, 'bi-anmeldungen', array( 'BI_Registration', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Anmeldeformulare', 'Anmeldeformulare', BI_CAP, BI_Formulare::PAGE, array( 'BI_Formulare', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Benachrichtigungen', 'Benachrichtigungen', BI_CAP, 'bi-mail-trigger', array( 'BI_Mailer', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Kampagnen', 'Kampagnen', BI_CAP, 'bi-kampagnen', array( 'BI_Tracking', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Datenpflege', 'Datenpflege', BI_CAP, BI_Datenpflege::PAGE, array( 'BI_Datenpflege', 'render_page' ) );
		add_submenu_page( 'bi-seminarsuche', 'Einstellungen', 'Einstellungen', BI_CAP, 'bi-einstellungen', array( 'BI_Settings', 'render_page' ) );
	}

	/**
	 * Stellt die Reihenfolge aus MENU_ORDER her.
	 *
	 * Nötig, weil die Punkte aus drei Quellen kommen: hier registrierte Seiten,
	 * von WordPress per show_in_menu eingehängte Beitragstypen und Seiten, die
	 * sich aus ihrer eigenen Klasse anmelden (Marketing). Wer nicht in
	 * der Liste steht, wird nicht verworfen, sondern einsortiert – vor die
	 * Einstellungen, die immer zuletzt stehen sollen.
	 */
	public static function reorder_submenu() {
		global $submenu;
		if ( empty( $submenu['bi-seminarsuche'] ) ) {
			return;
		}

		$order    = self::MENU_ORDER;
		$sortiert = array_fill_keys( $order, array() ); // Plätze in der gewünschten Reihenfolge
		$unbekannt = array();

		foreach ( $submenu['bi-seminarsuche'] as $item ) {
			$slug = $item[2] ?? '';
			if ( isset( $sortiert[ $slug ] ) ) {
				$sortiert[ $slug ][] = $item;
			} else {
				$unbekannt[] = $item;
			}
		}

		// Unbekannte Punkte vor die Einstellungen hängen, damit die zuletzt stehen.
		$vorne  = array();
		$hinten = array();
		foreach ( $order as $slug ) {
			if ( 'bi-einstellungen' === $slug ) {
				$hinten = array_merge( $hinten, $sortiert[ $slug ] );
			} else {
				$vorne = array_merge( $vorne, $sortiert[ $slug ] );
			}
		}

		// Neu indexieren, damit WordPress die Reihenfolge übernimmt.
		$submenu['bi-seminarsuche'] = array_values( array_merge( $vorne, $unbekannt, $hinten ) );
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
						// Gezählt wird die ZUSTELLADRESSE. Die der Ansprechperson darf
						// fehlen, ohne dass eine Mail ausbleibt – seit die beiden
						// Rollen getrennte Felder haben (1.116.0).
						array(
							'relation' => 'OR',
							array( 'key' => '_bi_bz_email', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_bi_bz_email', 'value' => '', 'compare' => '=' ),
						),
					),
				) );
				$ohne_ap = (int) $q_ohne_ap->found_posts;
				if ( ! $ohne_ap ) {
					continue;
				}
				$wort  = ( BI_ONLINE === $pt ) ? 'Online-Seminar' : 'Seminar';
				$feld  = ( BI_ONLINE === $pt ) ? 'Anmelde-E-Mail' : 'E-Mail des Bildungszentrums';
				$hinweise[] = sprintf(
					'<strong>%s ohne %s</strong> – dort greift nur noch die Adresse am Begriff des Bildungszentrums; fehlt auch die, findet die Benachrichtigung keinen Empfänger. <a href="%s">Prüfen</a>',
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
				esc_url( self::seminarliste_url( 'online', array( 'bi_missing_link' => 1 ) ) )
			);
		}

		/*
		 * Fehlende Startdaten standen früher hier. Sie stehen jetzt in der
		 * Tabelle „Datenqualität" weiter unten – zusammen mit den neun anderen
		 * Lücken, mit Anteil am Bestand und mit einem Weg zur Massenbearbeitung.
		 * Zweimal dieselbe Zahl auf einer Seite ist keine doppelte Warnung,
		 * sondern eine, der man nicht mehr ansieht, wo sie herkommt.
		 *
		 * Was hier bleibt, ist die andere Sorte Meldung: Dinge, die JETZT etwas
		 * blockieren (Test-Modus, abgebrochener Ampel-Lauf, Webinare ohne
		 * Anmeldelink) – nicht die Bestandsaufnahme.
		 */

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
					self::seminarliste_url( 'praesenz' ),
					sprintf( 'davon %s kommend', number_format_i18n( $seminars_kommend ) )
				);
				self::card(
					number_format_i18n( $online_pub ),
					'Online-Seminare (veröffentlicht)',
					self::seminarliste_url( 'online' ),
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

			<?php
			// ---- Datenqualität ----
			// Die Hinweis-Box oben nennt die paar Fälle, die JETZT etwas
			// blockieren. Diese Tabelle ist das andere: eine Bestandsaufnahme
			// über alle veröffentlichten Seminare, mit der sich nach einem
			// Import prüfen lässt, ob der Jahrgang vollständig gepflegt ist.
			$form_wahl = isset( $_GET['bi_q_form'] ) ? sanitize_key( wp_unslash( $_GET['bi_q_form'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$form_wahl = in_array( $form_wahl, array( 'praesenz', 'online' ), true ) ? $form_wahl : '';
			$qualitaet = BI_CPT::datenqualitaet( $form_wahl );
			$offen     = array_values( array_filter( $qualitaet, function ( $p ) {
				return $p['anzahl'] > 0;
			} ) );
			$gesamt    = ( 'online' === $form_wahl ) ? $online_pub : ( ( 'praesenz' === $form_wahl ) ? $seminars_pub : $seminars_pub + $online_pub );
			?>
			<div class="card" style="max-width:100%;margin:0 0 20px">
				<h2 style="margin-top:0">
					Datenqualität
					<span style="font-weight:400;color:#646970;font-size:13px">
						– <?php echo esc_html( number_format_i18n( $gesamt ) ); ?> veröffentlichte Seminare geprüft
					</span>
				</h2>

				<p style="margin:0 0 12px">
					<?php foreach ( array( '' => 'Beide Formen', 'praesenz' => 'Nur Präsenz', 'online' => 'Nur Online' ) as $wert => $label ) : ?>
						<?php $aktiv = ( $wert === $form_wahl ); ?>
						<a href="<?php echo esc_url( add_query_arg( 'bi_q_form', $wert, admin_url( 'admin.php?page=bi-seminarsuche' ) ) ); ?>"
						   class="button button-small<?php echo $aktiv ? ' button-primary' : ''; ?>"
						   style="margin-right:4px"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</p>

				<?php if ( ! $offen ) : ?>
					<p style="color:#008a20;margin:0"><strong>Keine Lücke gefunden.</strong>
					   Alle veröffentlichten Seminare tragen die Angaben, von denen Suche, Detailseite
					   und Anmeldung leben.</p>
				<?php else : ?>
					<?php
					/*
					 * Feste Spaltenbreiten: Ohne sie richtet sich die Tabelle nach
					 * dem längsten Wort. „Ansprechpartner-E-Mail fehlt" zieht dann
					 * die erste Spalte auf, und die Wirkung – der Satz, der die
					 * Zeile überhaupt erst erklärt – bricht auf sechs Zeilen um.
					 */
					?>
					<table class="widefat striped" style="table-layout:fixed">
						<thead><tr>
							<th style="width:210px">Was fehlt</th>
							<th style="width:110px">Betroffen</th>
							<th>Wirkung</th>
							<th style="width:150px">Im Batch pflegbar?</th>
							<th style="width:160px"></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $offen as $p ) : ?>
							<?php $anteil = $gesamt ? round( $p['anzahl'] / $gesamt * 100 ) : 0; ?>
							<tr>
								<td><strong><?php echo esc_html( $p['was'] ); ?></strong></td>
								<td>
									<strong style="color:<?php echo $anteil >= 10 ? '#b32d2e' : '#996800'; ?>">
										<?php echo esc_html( number_format_i18n( $p['anzahl'] ) ); ?></strong>
									<span style="color:#646970;font-size:12px"><?php echo esc_html( $anteil . ' %' ); ?></span>
								</td>
								<td style="color:#50575e"><?php echo esc_html( $p['wirkung'] ); ?></td>
								<td style="color:#50575e"><?php echo $p['batch']
									? 'Ja – markieren, <em>Bearbeiten</em>'
									: '<span style="color:#8c8f94">Nein – je Termin verschieden</span>'; ?></td>
								<td><a class="button button-small" href="<?php echo esc_url( $p['url'] ); ?>">Anzeigen &amp; bearbeiten</a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description" style="margin-top:10px">
						Jede Zeile führt in die gefilterte Seminarliste – dort markieren und mit der Massenaktion
						<strong>Bearbeiten</strong> füllen. <strong>Beschreibung und Themen</strong> lassen sich dabei
						für höchstens <?php echo esc_html( number_format_i18n( BI_CPT::BULK_TEXT_MAX ) ); ?> markierte
						Seminare setzen: Ein Fließtext gilt für einen Seminarinhalt, und der überschriebene Text wäre
						nicht wiederherstellbar. Startdatum und Seminarnummer sind je Termin verschieden und stehen
						deshalb gar nicht in der Maske – die gehören in die Quelldatei.
					</p>
				<?php endif; ?>
			</div>

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
						<li><strong>Benachrichtigungen</strong> prüfen/anpassen → gleichnamiger Menüpunkt.
							Dort je Benachrichtigung auch die <em>Antwortadresse</em> und die <em>PDF-Anhänge</em>
							(Seminardetails, Beschlussvorlage nach § 37 Abs. 6 BetrVG) einstellen.</li>
						<li><strong>PDF-Anhänge</strong> vorbereiten → <em>Einstellungen → Tab „PDF-Anhänge"</em>:
							Logo hochladen und Name/Anschrift des Veranstalters eintragen, dann die Vorschau prüfen.</li>
						<li><strong>Einstellungen</strong> → Anmeldevarianten konfigurieren: Direktanmeldung-Seite und Link zur Geschäftsstellensuche.</li>
						<li><strong>Such-Seite</strong> anlegen mit Shortcode
							<code>[bi_seminarsuche anmeldung_url="/anmeldung"]</code>.</li>
						<li><strong>Anmelde-Seite</strong> anlegen mit Shortcode <code>[bi_anmeldung]</code>
							(das Seminar kommt automatisch per <code>?seminar=ID</code> aus der Suche).</li>
						<li>Optional: <strong>Seite für die Ausbildungsreihen</strong> mit <code>[bi_reihen]</code>.
							Der Beitragstyp hat keine automatische Archivseite – ohne diesen Schritt sind Reihen nur
							über ihre Einzelseiten und über Links aus der Suche erreichbar.</li>
					</ol>
					<h3>Shortcodes</h3>
					<table class="widefat striped" style="max-width:780px">
						<tbody>
							<tr><th colspan="2" style="text-align:left">Suche und Anmeldung</th></tr>
							<tr><td><code>[bi_seminarsuche]</code></td><td>Such-/Filterleiste mit Ergebnisliste – Präsenz- und Online-Seminare gemeinsam, getrennt über den Filter-Chip „Seminarform". Zwischen Filterleiste und Trefferliste kann die Besucherin selbst wählen, wie viele Seminare auf eine Seite kommen (10/20/50/100). Attribute: <code>anmeldung_url</code>, <code>per_page</code> (Vorgabe, Standard 20), <code>pro_seite</code> (wählbare Stufen, z.&nbsp;B. <code>pro_seite="10|25|50"</code>; <code>pro_seite="nein"</code> = keine Wahl anbieten), <code>programm</code> (auf ein Programmjahr beschränken, z.&nbsp;B. <code>programm="2026"</code>), <code>form</code> (<code>praesenz</code> oder <code>online</code> – blendet den Chip aus und zeigt nur diese Form).</td></tr>
							<tr><td><code>[bi_suchmaske]</code></td><td>Nur die Suchmaske ohne Ergebnisliste – z.&nbsp;B. für Startseite oder Sidebar. Die Filter wirken hier nicht sofort; der Button „Suche starten" springt mit der Auswahl auf die Seminarübersicht. Attribute: <code>ziel_url</code> (Standard: Einstellung „Seminarübersicht"), <code>button</code>, <code>titel</code>, <code>kicker</code>, <code>hinweis</code>, <code>programm</code>.</td></tr>
							<tr><td><code>[bi_anmeldung]</code></td><td>Anmeldeformular. Attribut: <code>seminar="ID"</code> für festes Seminar. Welche Seiten und Felder es zeigt, steht unter <em>Anmeldeformulare</em>.</td></tr>

							<tr><th colspan="2" style="text-align:left">Ausbildungsreihen</th></tr>
							<tr><td><code>[bi_reihen]</code></td><td><strong>Übersicht aller Reihen</strong> im Gewand der Detailseiten: Bild mit „N&nbsp;Teile"-Label, Titel, Auszug, Fußzeile mit Gruppen, frühestem Start und Orten. Entwürfe bleiben draußen. Attribute: <code>titel</code> (leer = ohne Überschrift), <code>overline</code>, <code>subline</code>, <code>anzahl</code>.<br><span style="color:#646970">Einen automatischen Archiv-Link gibt es nicht – dieser Shortcode gehört auf eine eigene Seite.</span></td></tr>
							<tr><td><code>[bi_kachel_reihen]</code></td><td>Dieselben Reihen als <strong>Kacheln</strong> – für eine Startseite, wo sie neben anderen Kacheln stehen. Ohne Angabe alle ausgeschriebenen, sonst die handverlesenen in ihrer Reihenfolge. Attribute: <code>reihen="12|34"</code>, <code>spalten</code> (2/3/4), <code>anzahl</code>, <code>sortierung="titel"</code>, <code>layout</code>, <code>ratio</code>, <code>ueberschrift</code>, <code>button</code> (<code>button=""</code> = ohne Button), <code>meta="nein"</code>.</td></tr>

							<tr><th colspan="2" style="text-align:left">Marketing <span style="font-weight:400;color:#646970">– zusammengestellt unter <em>Marketing</em></span></th></tr>
							<tr><td><code>[bi_kachel]</code></td><td>Eine Kachel: Bild, Überschrift, Text, wahlweise Button. Ziel wahlweise die Suche mit vorbelegten Filtern (<code>q</code>, <code>form</code>, <code>ort</code>, <code>thema</code>, <code>ziel</code>, <code>frei</code>, <code>von</code>, <code>bis</code>, <code>nr</code> – mehrere Werte mit <code>|</code>), eine feste <code>url</code> oder eine Ausbildungsreihe (<code>reihe="ID oder Slug"</code>). Gestaltung: <code>layout</code> (1/2), <code>bild</code>, <code>ratio</code>, <code>fokus</code>, <code>titel</code>, <code>text</code>, <code>button</code> (<code>button=""</code> lässt den roten Button weg), <code>ueberschrift</code>.</td></tr>
							<tr><td><code>[bi_liste]</code></td><td><strong>Dieselbe gefilterte Auswahl als Liste</strong> statt als Kachel: die nächsten Termine als Trefferzeilen – Zeile für Zeile dieselben wie unter der Suchbox, ohne Bild. Filter-Attribute wie bei <code>[bi_kachel]</code>, dazu <code>anzahl</code> (Zeilen, Standard 5), <code>titel</code>, <code>text</code>, <code>ueberschrift</code>, <code>button</code> (Link unter der Liste; <code>%d</code> darin wird zur Gesamtzahl, <code>button=""</code> lässt ihn weg), <code>leer</code>, <code>programm</code> (hier ein echter Filter), <code>suche_url</code>.</td></tr>
							<tr><td><code>[bi_kachel gespeichert="…"]</code><br><code>[bi_liste gespeichert="…"]</code></td><td><strong>Verweis auf eine gespeicherte Kachel oder Liste.</strong> Die Gestaltung bleibt im Reiter <em>Gespeichert</em> – wer sie dort ändert, ändert sie überall zugleich. Beide Shortcodes verstehen jeden Schlüssel: Was herauskommt, entscheidet die gespeicherte Darstellung. Weitere Attribute übersteuern die gespeicherte Fassung im Einzelfall.</td></tr>
							<tr><td><code>[bi_kachel_vorlagen]</code></td><td>Alle Themenfelder mit hinterlegtem Bild als fertiges Gitter (Reiter <em>Kachel-Vorlagen</em>). Attribute: <code>spalten</code>, <code>ratio</code>, <code>button</code>.</td></tr>
							<tr><td><code>[bi_kacheln spalten="3"]…[/bi_kacheln]</code></td><td>Gitter-Behälter, wenn mehrere <code>[bi_kachel]</code> ohne Page-Builder nebeneinander stehen sollen. Mobil bricht es von selbst um.</td></tr>
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
