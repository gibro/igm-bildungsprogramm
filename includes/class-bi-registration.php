<?php
/**
 * Anmeldeformular [bi_anmeldung] – mehrstufiger Wizard (4 Schritte) + Tabelle wp_bi_anmeldungen.
 *
 * Design-Vorgabe: Sidebar (Seminarinfos + Stepper) links, Formularpanel rechts.
 * Schritte: 1 Persönliche Angaben · 2 Kontakt & Mitgliedschaft · 3 Betrieb & Funktion · 4 Abschluss.
 * Privat = blau, Dienstlich = bernstein, Abschluss = neutral.
 *
 * Geschäftsstellen-Ermittlung läuft im Hintergrund über die BETRIEBLICHE PLZ (Feld betrieb_plz):
 * diese wird in die Spalte `plz` geschrieben und über BI_PLZ::lookup() der zuständigen GS zugeordnet
 * (Mail-Benachrichtigung Typ „Geschäftsstelle").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Registration {

	public static function init() {
		add_shortcode( 'bi_anmeldung', array( __CLASS__, 'shortcode' ) );
		add_action( 'admin_post_nopriv_bi_anmeldung', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_bi_anmeldung', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_bi_export_anmeldungen', array( __CLASS__, 'handle_export' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'budget_notice' ) );
	}

	public static function register_assets() {
		wp_register_style( 'bi-archivo', 'https://fonts.googleapis.com/css2?family=Archivo:wght@500;600;700;800&display=swap', array(), null );
		wp_register_style( 'bi-anmeldung', BI_URL . 'assets/css/anmeldung.css', array( 'bi-archivo' ), BI_VERSION );
		wp_register_script( 'bi-anmeldung', BI_URL . 'assets/js/anmeldung.js', array(), BI_VERSION, true );
	}

	public static function table() {
		return bi_table( 'anmeldungen' );
	}

	/** URL der Anmeldeseite (Einstellung, sonst Auto-Erkennung) */
	public static function anmeldung_page_url() {
		$configured = BI_Settings::anmeldung_page_url();
		if ( $configured ) {
			return $configured;
		}
		$cached = get_transient( 'bi_anmeldung_page_url' );
		if ( false !== $cached ) {
			return $cached;
		}
		$url   = '';
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids' ) );
		foreach ( $pages as $pid ) {
			if ( has_shortcode( (string) get_post_field( 'post_content', $pid ), 'bi_anmeldung' ) ) {
				$url = get_permalink( $pid );
				break;
			}
		}
		set_transient( 'bi_anmeldung_page_url', $url, HOUR_IN_SECONDS );
		return $url;
	}

	/** URL der Seminarübersicht (Einstellung, sonst Seite mit [bi_seminarsuche], sonst Startseite) */
	public static function uebersicht_url() {
		$configured = BI_Settings::uebersicht_page_url();
		if ( $configured ) {
			return $configured;
		}
		$cached = get_transient( 'bi_uebersicht_page_url' );
		if ( false !== $cached ) {
			return $cached ?: home_url();
		}
		$url   = '';
		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 100, 'fields' => 'ids' ) );
		foreach ( $pages as $pid ) {
			if ( has_shortcode( (string) get_post_field( 'post_content', $pid ), 'bi_seminarsuche' ) ) {
				$url = get_permalink( $pid );
				break;
			}
		}
		set_transient( 'bi_uebersicht_page_url', $url, HOUR_IN_SECONDS );
		return $url ?: home_url();
	}

	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created DATETIME NOT NULL,
			seminar_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_type VARCHAR(20) NOT NULL DEFAULT 'bi_seminar',
			seminar_titel VARCHAR(255) NOT NULL DEFAULT '',
			seminar_nummer VARCHAR(60) NOT NULL DEFAULT '',
			seminar_termin VARCHAR(60) NOT NULL DEFAULT '',
			vorname VARCHAR(190) NOT NULL DEFAULT '',
			nachname VARCHAR(190) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			telefon VARCHAR(60) NOT NULL DEFAULT '',
			betrieb VARCHAR(190) NOT NULL DEFAULT '',
			plz VARCHAR(5) NOT NULL DEFAULT '',
			nachricht TEXT NULL,
			data LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'neu',
			kampagne VARCHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY seminar_id (seminar_id),
			KEY post_type (post_type),
			KEY created (created),
			KEY kampagne (kampagne)
		) $charset;";
		dbDelta( $sql );
	}

	/**
	 * Wizard-Schritte. Feld: [label, type, required, full, placeholder, options, default, max].
	 * type: text|email|tel|plz|textarea|select|radio|freistellung
	 * category: privat|dienstlich|neutral
	 *
	 * `max` ist die fachliche Höchstlänge in Zeichen. Sie wird zweifach durchgesetzt:
	 * als maxlength im Browser (dort merkt sie niemand) und noch einmal serverseitig
	 * in handle_submit(). Ohne die serverseitige Prüfung kann ein direkt abgesetztes
	 * POST beliebig große Werte in Datenbank, JSON-Spalte und alle drei Mailtexte
	 * schreiben. Die Werte liegen bewusst deutlich über jeder realen Eingabe und
	 * unterhalb der jeweiligen Spaltenbreite in create_table().
	 */
	public static function form_steps() {
		return array(
			array(
				'key' => 'persoenlich', 'category' => 'privat',
				'title' => 'Persönliche Angaben', 'sub' => 'Privat',
				'desc'  => 'Name und Anschrift der teilnehmenden Person.',
				'fields' => array(
					'anrede'   => array( 'label' => 'Anrede', 'type' => 'select', 'col' => 6, 'options' => array( '', 'Frau', 'Herr', 'Divers', 'Keine Angabe' ) ),
					'titel'    => array( 'label' => 'Titel', 'type' => 'text', 'col' => 6, 'placeholder' => 'z. B. Dr.', 'max' => 60 ),
					'vorname'  => array( 'label' => 'Vorname', 'type' => 'text', 'col' => 6, 'required' => true, 'placeholder' => 'Vorname', 'max' => 100 ),
					'nachname' => array( 'label' => 'Nachname', 'type' => 'text', 'col' => 6, 'required' => true, 'placeholder' => 'Nachname', 'max' => 100 ),
					'strasse'  => array( 'label' => 'Straße & Hausnummer', 'type' => 'text', 'full' => true, 'required' => true, 'placeholder' => 'Musterstraße 12', 'max' => 150 ),
					'plz'      => array( 'label' => 'PLZ', 'type' => 'plz', 'col' => 4, 'required' => true, 'placeholder' => '12345' ),
					'ort'      => array( 'label' => 'Ort', 'type' => 'text', 'col' => 8, 'required' => true, 'placeholder' => 'Beispielort', 'max' => 100 ),
				),
			),
			array(
				'key' => 'kontakt', 'category' => 'privat',
				'title' => 'Kontakt & Mitgliedschaft', 'sub' => 'Privat',
				'desc'  => 'Wie wir dich erreichen und deine IG-Metall-Mitgliedschaft.',
				'fields' => array(
					'telefon'         => array( 'label' => 'Telefon', 'type' => 'tel', 'col' => 6, 'placeholder' => '0202 1234567', 'max' => 40 ),
					'mobil'           => array( 'label' => 'Mobiltelefon', 'type' => 'tel', 'col' => 6, 'required' => true, 'placeholder' => '0151 1234567', 'max' => 40 ),
					'email'           => array( 'label' => 'E-Mail', 'type' => 'email', 'full' => true, 'required' => true, 'placeholder' => 'name@beispiel.de', 'max' => 180 ),
					'mitglied'        => array( 'label' => 'Bist du Mitglied der IG Metall?', 'type' => 'radio', 'full' => true, 'default' => 'ja', 'options' => array( 'ja' => 'Ja', 'nein' => 'Nein' ) ),
					'mitgliedsnummer' => array( 'label' => 'Mitgliedsnummer', 'type' => 'text', 'col' => 6, 'placeholder' => 'z. B. 1234567890', 'max' => 40 ),
				),
			),
			array(
				'key' => 'betrieb', 'category' => 'dienstlich',
				'title' => 'Betrieb & Funktion', 'sub' => 'Dienstlich',
				'desc'  => 'Angaben zu Arbeitgeber, Funktion und Freistellung.',
				'fields' => array(
					'betrieb'         => array( 'label' => 'Betrieb / Arbeitgeber', 'type' => 'text', 'full' => true, 'required' => true, 'placeholder' => 'Name des Unternehmens', 'max' => 150 ),
					'betrieb_strasse' => array( 'label' => 'Straße & Hausnummer (Betrieb)', 'type' => 'text', 'full' => true, 'required' => true, 'placeholder' => 'Werkstraße 1', 'max' => 150 ),
					'betrieb_plz'     => array( 'label' => 'PLZ (Betrieb)', 'type' => 'plz', 'col' => 4, 'required' => true, 'placeholder' => '12345', 'hint' => 'Bestimmt die zuständige Geschäftsstelle.' ),
					'betrieb_ort'     => array( 'label' => 'Ort (Betrieb)', 'type' => 'text', 'col' => 8, 'required' => true, 'placeholder' => 'Beispielort', 'max' => 100 ),
					'betrieb_email'   => array( 'label' => 'E-Mail (Betrieb)', 'type' => 'email', 'full' => true, 'required' => true, 'placeholder' => 'personal@beispiel-gmbh.de', 'hint' => 'Dienstliche Adresse, z. B. von Personalabteilung oder Betriebsrat.', 'max' => 180 ),
					'funktion'        => array( 'label' => 'Funktion im Betriebsrat', 'type' => 'select', 'col' => 6, 'options' => array( '', 'BR-Mitglied', 'BR-Vorsitz', 'stellv. BR-Vorsitz', 'Ersatzmitglied', 'JAV', 'Schwerbehindertenvertretung' ) ),
					'freistellung'    => array( 'label' => 'Freistellung nach', 'type' => 'freistellung', 'col' => 6, 'required' => true ),
				),
			),
			array(
				'key' => 'abschluss', 'category' => 'neutral',
				'title' => 'Abschluss', 'sub' => 'Abschluss',
				'desc'  => 'Wünsche ergänzen und Anmeldung absenden.',
				'fields' => array(
					'bemerkungen' => array( 'label' => 'Bemerkungen / Sonstiges', 'type' => 'textarea', 'full' => true, 'placeholder' => 'Anmerkungen zur Anreise, besondere Wünsche o. Ä.', 'max' => 2000 ),
				),
			),
		);
	}

	/**
	 * Zulässige Werte eines select-/radio-Feldes.
	 *
	 * Die Optionslisten sind teils Liste (select: Werte) und teils Zuordnung
	 * (radio: Schlüssel => Beschriftung); beides muss hier auf dieselbe Menge
	 * abgebildet werden. Leere Liste = keine Wertebeschränkung.
	 */
	private static function allowed_values( $f ) {
		if ( empty( $f['options'] ) || ! is_array( $f['options'] ) ) {
			return array();
		}
		$opts = $f['options'];
		// Assoziative Liste (radio) -> Schlüssel sind die Werte, sonst die Werte selbst.
		$keys = array_keys( $opts );
		return ( $keys === range( 0, count( $opts ) - 1 ) ) ? array_values( $opts ) : array_map( 'strval', $keys );
	}

	private static function all_fields() {
		$out = array();
		foreach ( self::form_steps() as $step ) {
			foreach ( $step['fields'] as $key => $f ) {
				$out[ $key ] = $f;
			}
		}
		return $out;
	}

	/** Kategorie -> Badge-Daten */
	private static function category( $cat ) {
		$map = array(
			'privat'     => array( 'label' => 'Private Angaben', 'dot' => '#2A6FDB' ),
			'dienstlich' => array( 'label' => 'Dienstliche Angaben', 'dot' => '#C2810E' ),
			'neutral'    => array( 'label' => 'Abschluss', 'dot' => '#a1a1aa' ),
		);
		return $map[ $cat ] ?? $map['neutral'];
	}

	/** Seminar-Infos für Sidebar/Übersicht/Erfolg */
	private static function seminar_info( $id ) {
		$start = get_post_meta( $id, '_bi_startdatum', true );
		$end   = get_post_meta( $id, '_bi_enddatum', true );
		$ort   = wp_get_object_terms( $id, BI_TAX_ORT, array( 'fields' => 'names' ) );

		$termin = '';
		if ( $start ) {
			$termin = date_i18n( 'd.m.', strtotime( $start ) );
			if ( $end && $end !== $start ) {
				$termin .= ' – ' . date_i18n( 'd.m.Y', strtotime( $end ) );
			} else {
				$termin = date_i18n( 'd.m.Y', strtotime( $start ) );
			}
		}

		// Bei Online-Seminaren steht in der Ortszeile „Online" (plus Veranstalter*in).
		$ortname = ( is_array( $ort ) && $ort ) ? $ort[0] : '';
		if ( bi_is_online( $id ) ) {
			$ortname = $ortname ? 'Online · ' . $ortname : 'Online';
		}

		return array(
			'title'           => get_the_title( $id ),
			'nummer'          => get_post_meta( $id, '_bi_seminarnummer', true ),
			'termin'          => $termin,
			'ort'             => $ortname,
			'ansprechpartner' => get_post_meta( $id, '_bi_ansprechpartner', true ),
		);
	}

	/** Anzeigename der Seminarform zu einem Beitragstyp */
	public static function form_label( $post_type ) {
		return ( BI_ONLINE === $post_type ) ? 'Online' : 'Präsenz';
	}

	/** ---------- Shortcode ---------- */

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'seminar' => 0 ), $atts, 'bi_anmeldung' );

		wp_enqueue_style( 'bi-archivo' );
		wp_enqueue_style( 'bi-anmeldung' );
		wp_enqueue_script( 'bi-anmeldung' );

		$seminar_id = intval( $atts['seminar'] );
		if ( ! $seminar_id ) {
			$seminar_id = intval( bi_get( 'seminar' ) );
		}

		$state = bi_get( 'bi_anmeldung' );

		// Erfolgs-Screen
		if ( 'ok' === $state ) {
			return self::render_success( $seminar_id );
		}

		// Auf Fehlerseiten stehen wieder Eingaben im Formular – die dürfen kein
		// Seiten-Cache und kein Proxy zwischenspeichern und an Dritte ausliefern.
		if ( in_array( $state, array( 'err', 'limit', 'fail' ), true ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}

		$has_fixed = $seminar_id && bi_is_seminar_post( $seminar_id );

		// Kein (gültiges) Seminar -> Auswahl anbieten
		if ( ! $has_fixed ) {
			return self::render_seminar_picker();
		}

		// Nicht direkt buchbar -> Hinweis statt Formular
		if ( ! BI_CPT::is_bookable( $seminar_id ) ) {
			$variante = bi_is_online( $seminar_id ) ? BI_Online::variante( $seminar_id ) : '';
			$link     = '';

			if ( BI_CPT::meta_bool( $seminar_id, '_bi_ausgebucht' ) ) {
				$grund = ' Das Seminar ist ausgebucht.';
			} elseif ( 'extern' === $variante ) {
				$grund = ' Die Anmeldung läuft über die Anmeldeseite des Webinars.';
				$link  = '<a href="' . esc_url( BI_Online::anmeldelink( $seminar_id ) ) . '" target="_blank" rel="noopener">Zur Anmeldung</a>';
			} elseif ( 'offen' === $variante ) {
				$grund = ' Die Veranstaltung ist öffentlich zugänglich – eine Anmeldung ist nicht nötig.';
				$link  = '<a href="' . esc_url( BI_Online::online_link( $seminar_id ) ) . '" target="_blank" rel="noopener">Direkt teilnehmen</a>';
			} else {
				$grund = ' Die Anmeldung läuft hier über die Geschäftsstelle.';
			}

			return '<div class="bi-wiz-note">Für dieses Seminar ist keine Direktanmeldung möglich.'
				. esc_html( $grund ) . ( $link ? ' ' . $link : '' ) . '</div>';
		}

		// Fehlerzustand: In der URL steht nur noch ein Zufallstoken, die Eingaben
		// selbst liegen serverseitig (siehe store_error_state).
		$old = in_array( $state, array( 'err', 'limit', 'fail' ), true ) ? self::read_error_state( bi_get( 'e' ) ) : array();

		$notice = '';
		if ( 'err' === $state ) {
			$notice = 'Bitte prüfe deine Eingaben – einige Pflichtfelder fehlen oder sind ungültig.';
		} elseif ( 'limit' === $state ) {
			$notice = 'Von diesem Anschluss sind in kurzer Zeit ungewöhnlich viele Anmeldungen eingegangen. '
				. 'Bitte versuche es in etwa einer Stunde erneut – oder wende dich direkt an deine Geschäftsstelle.';
		} elseif ( 'fail' === $state ) {
			$notice = 'Deine Anmeldung konnte aus technischen Gründen nicht gespeichert werden. '
				. 'Deine Eingaben stehen noch im Formular – bitte schicke sie erneut ab. '
				. 'Klappt es weiterhin nicht, wende dich bitte an deine Geschäftsstelle.';
		}

		// Trichter-Schritt „Anmeldung begonnen": Das Formular wird nur über den
		// Buchungs-Button erreicht, sein Aufruf ist also der Klick auf „Jetzt buchen".
		BI_Tracking::track( 'formular', $seminar_id );

		$info      = self::seminar_info( $seminar_id );
		$frei_opts = self::freistellung_options( $seminar_id );
		$steps     = self::form_steps();

		ob_start();
		?>
		<div class="bi-wiz" style="--accent:#E2001A;--sidebar-bg:#E2001A;">
			<form class="bi-wiz__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
				<input type="hidden" name="action" value="bi_anmeldung">
				<input type="hidden" name="seminar_id" value="<?php echo esc_attr( $seminar_id ); ?>">
				<input type="hidden" name="redirect" value="<?php echo esc_url( self::current_url() ); ?>">
				<?php wp_nonce_field( 'bi_anmeldung', 'bi_anmeldung_nonce' ); ?>
				<?php echo self::timestamp_field(); // phpcs:ignore – intern escaped ?>
				<div class="bi-wiz__hp" aria-hidden="true"><label>Bitte leer lassen<input type="text" name="bi_hp" tabindex="-1" autocomplete="off"></label></div>

				<!-- Sidebar -->
				<aside class="bi-wiz__sidebar">
					<div class="bi-wiz__eyebrow">Seminaranmeldung</div>
					<h2 class="bi-wiz__title"><?php echo esc_html( $info['title'] ); ?></h2>
					<div class="bi-wiz__divider"></div>
					<div class="bi-wiz__info">
						<?php if ( $info['termin'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Termin</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['termin'] ); ?></span></div><?php endif; ?>
						<?php if ( $info['ort'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Ort</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['ort'] ); ?></span></div><?php endif; ?>
						<div class="bi-wiz__info-cols">
							<?php if ( $info['nummer'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Seminarnummer</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['nummer'] ); ?></span></div><?php endif; ?>
							<?php if ( $info['ansprechpartner'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Ansprechpartner*in</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['ansprechpartner'] ); ?></span></div><?php endif; ?>
						</div>
					</div>
					<div class="bi-wiz__divider"></div>
					<nav class="bi-wiz__stepper">
						<?php foreach ( $steps as $i => $st ) :
							$c = self::category( $st['category'] ); ?>
							<button type="button" class="bi-wiz__step-item<?php echo 0 === $i ? ' is-active' : ' is-todo'; ?>" data-goto="<?php echo $i; ?>">
								<span class="bi-wiz__circle"><?php echo $i + 1; ?></span>
								<span class="bi-wiz__step-text">
									<span class="bi-wiz__step-title"><?php echo esc_html( $st['title'] ); ?></span>
									<span class="bi-wiz__step-sub"><span class="bi-wiz__dot" style="background:<?php echo esc_attr( $c['dot'] ); ?>"></span><?php echo esc_html( $st['sub'] ); ?></span>
								</span>
							</button>
						<?php endforeach; ?>
					</nav>
				</aside>

				<!-- Formularpanel -->
				<div class="bi-wiz__panel">
					<div class="bi-wiz__panel-head">
						<div class="bi-wiz__counter">Schritt <span class="bi-wiz__cur">1</span> von <?php echo count( $steps ); ?></div>
						<div class="bi-wiz__progress"><span class="bi-wiz__progress-fill" style="width:<?php echo esc_attr( round( 100 / count( $steps ) ) ); ?>%"></span></div>
					</div>

					<?php if ( $notice ) : ?>
						<div class="bi-wiz__formerror"><?php echo esc_html( $notice ); ?></div>
					<?php endif; ?>

					<?php foreach ( $steps as $i => $st ) :
						$c = self::category( $st['category'] ); ?>
						<section class="bi-wiz__step<?php echo 0 === $i ? ' is-active' : ''; ?>" data-step="<?php echo $i; ?>">
							<div class="bi-wiz__badge bi-wiz__badge--<?php echo esc_attr( $st['category'] ); ?>">
								<span class="bi-wiz__badge-dot" style="background:<?php echo esc_attr( $c['dot'] ); ?>"></span><?php echo esc_html( $c['label'] ); ?>
							</div>
							<h2 class="bi-wiz__step-h2"><?php echo esc_html( $st['title'] ); ?></h2>
							<p class="bi-wiz__step-desc"><?php echo esc_html( $st['desc'] ); ?></p>
							<div class="bi-wiz__msg" hidden>Bitte fülle die markierten Pflichtfelder korrekt aus.</div>

							<div class="bi-wiz__grid">
								<?php foreach ( $st['fields'] as $key => $f ) {
									echo self::render_field( $key, $f, $old[ $key ] ?? '', $frei_opts ); // phpcs:ignore – intern escaped
								} ?>
							</div>

							<?php if ( 'abschluss' === $st['key'] ) : ?>
								<div class="bi-wiz__review">
									<div class="bi-wiz__review-title">Deine Angaben</div>
									<div class="bi-wiz__review-list" data-review>
										<p class="bi-wiz__review-empty">Bitte fülle die vorherigen Schritte aus.</p>
									</div>
								</div>
								<?php echo self::summary_card( $info ); // phpcs:ignore ?>
								<label class="bi-wiz__consent">
									<input type="checkbox" name="datenschutz" value="1" data-req="1">
									<span>Ich melde mich verbindlich an und habe die Hinweise zur Verarbeitung meiner personenbezogenen Daten zur Kenntnis genommen. <span class="bi-wiz__req">*</span></span>
								</label>
							<?php endif; ?>
						</section>
					<?php endforeach; ?>

					<div class="bi-wiz__footer">
						<button type="button" class="bi-wiz__back" hidden>← Zurück</button>
						<button type="submit" class="bi-wiz__primary">Weiter →</button>
					</div>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Einzelfeld rendern */
	private static function render_field( $key, $f, $val, $frei_opts ) {
		$id   = 'bi_' . $key;
		$req  = ! empty( $f['required'] );
		$full = ! empty( $f['full'] );
		$col  = isset( $f['col'] ) ? intval( $f['col'] ) : 12;
		$cls  = 'bi-wiz__field' . ( $full ? ' bi-wiz__field--full' : ' bi-wiz__field--col' . $col );
		$star = $req ? ' <span class="bi-wiz__req">*</span>' : '';
		$ph   = isset( $f['placeholder'] ) ? $f['placeholder'] : '';
		$reqa = $req ? ' data-req="1"' : '';
		// Höchstlänge schon im Browser durchsetzen: dann läuft niemand versehentlich
		// in die serverseitige Prüfung aus handle_submit().
		$maxa = ! empty( $f['max'] ) ? ' maxlength="' . esc_attr( (int) $f['max'] ) . '"' : '';

		ob_start();
		echo '<div class="' . esc_attr( $cls ) . '">';

		if ( 'radio' === $f['type'] ) {
			echo '<label class="bi-wiz__label">' . esc_html( $f['label'] ) . $star . '</label>';
			echo '<div class="bi-wiz__radio-group">';
			$cur = '' !== $val ? $val : ( $f['default'] ?? '' );
			foreach ( (array) $f['options'] as $ov => $ol ) {
				printf(
					'<label class="bi-wiz__radio"><input type="radio" name="%1$s" value="%2$s"%3$s>%4$s</label>',
					esc_attr( $key ),
					esc_attr( $ov ),
					checked( $cur, $ov, false ),
					esc_html( $ol )
				);
			}
			echo '</div>';
			echo '</div>';
			return ob_get_clean();
		}

		echo '<label class="bi-wiz__label" for="' . esc_attr( $id ) . '">' . esc_html( $f['label'] ) . $star . '</label>';

		switch ( $f['type'] ) {
			case 'textarea':
				echo '<textarea class="bi-wiz__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" rows="4" placeholder="' . esc_attr( $ph ) . '"' . $maxa . $reqa . '>' . esc_textarea( $val ) . '</textarea>';
				break;

			case 'select':
				echo '<select class="bi-wiz__input bi-wiz__select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . $reqa . '>';
				foreach ( (array) $f['options'] as $opt ) {
					$label = '' === $opt ? 'Bitte wählen' : $opt;
					echo '<option value="' . esc_attr( $opt ) . '"' . selected( $val, $opt, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'freistellung':
				$frei_opts = array_values( (array) $frei_opts );
				// Bietet das Seminar nur eine Freistellung an, ist sie vorausgewählt.
				$single = ( 1 === count( $frei_opts ) );
				if ( $single && '' === $val ) {
					$val = $frei_opts[0];
				}
				echo '<select class="bi-wiz__input bi-wiz__select" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '"' . $reqa . '>';
				if ( ! $single ) {
					echo '<option value="">Bitte wählen</option>';
				}
				foreach ( $frei_opts as $opt ) {
					echo '<option value="' . esc_attr( $opt ) . '"' . selected( $val, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
				}
				echo '</select>';
				break;

			case 'plz':
				echo '<input type="text" class="bi-wiz__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" autocomplete="postal-code" placeholder="' . esc_attr( $ph ) . '" data-type="plz"' . $reqa . '>';
				break;

			default: // text, email, tel
				$ac = self::autocomplete_for( $key );
				echo '<input type="' . esc_attr( $f['type'] ) . '" class="bi-wiz__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '" placeholder="' . esc_attr( $ph ) . '"'
					. ( $ac ? ' autocomplete="' . esc_attr( $ac ) . '"' : '' )
					. ( 'email' === $f['type'] ? ' data-type="email"' : '' ) . $maxa . $reqa . '>';
		}

		if ( ! empty( $f['hint'] ) ) {
			echo '<span class="bi-wiz__hint">' . esc_html( $f['hint'] ) . '</span>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	private static function autocomplete_for( $key ) {
		$map = array(
			'vorname' => 'given-name', 'nachname' => 'family-name', 'strasse' => 'street-address',
			'ort' => 'address-level2', 'telefon' => 'tel', 'mobil' => 'tel', 'email' => 'email', 'betrieb' => 'organization',
		);
		return $map[ $key ] ?? '';
	}

	/** Übersichtskarte in Schritt 4 */
	private static function summary_card( $info ) {
		$rows = array(
			'Seminar' => $info['title'],
			'Nummer'  => $info['nummer'],
			'Termin'  => $info['termin'],
			'Ort'     => $info['ort'],
		);
		ob_start();
		echo '<div class="bi-wiz__summary"><div class="bi-wiz__summary-title">Deine Anmeldung im Überblick</div><div class="bi-wiz__summary-list">';
		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}
			echo '<div class="bi-wiz__summary-item"><span class="bi-wiz__summary-label">' . esc_html( $label ) . '</span><span class="bi-wiz__summary-val">' . esc_html( $value ) . '</span></div>';
		}
		echo '</div></div>';
		return ob_get_clean();
	}

	/** Erfolgs-Screen */
	private static function render_success( $seminar_id ) {
		$info  = $seminar_id ? self::seminar_info( $seminar_id ) : array( 'title' => '', 'nummer' => '', 'termin' => '', 'ansprechpartner' => '' );
		$reset = remove_query_arg( array( 'bi_anmeldung', 'old', 'e' ) );
		ob_start();
		?>
		<div class="bi-wiz bi-wiz--success" style="--accent:#E2001A;">
			<div class="bi-wiz__success">
				<div class="bi-wiz__success-circle">✓</div>
				<h2 class="bi-wiz__success-h2">Anmeldung übermittelt</h2>
				<p class="bi-wiz__success-text">Vielen Dank! Deine Anmeldung<?php echo $info['title'] ? ' für das Seminar <strong>' . esc_html( $info['title'] ) . '</strong>' : ''; ?> ist eingegangen. Eine Bestätigung erhältst du per E-Mail.</p>
				<?php if ( $info['nummer'] || $info['termin'] || $info['ansprechpartner'] ) : ?>
					<div class="bi-wiz__success-box">
						<?php if ( $info['nummer'] ) : ?><div class="bi-wiz__summary-item"><span class="bi-wiz__summary-label">Seminarnummer</span><span class="bi-wiz__summary-val"><?php echo esc_html( $info['nummer'] ); ?></span></div><?php endif; ?>
						<?php if ( $info['termin'] ) : ?><div class="bi-wiz__summary-item"><span class="bi-wiz__summary-label">Termin</span><span class="bi-wiz__summary-val"><?php echo esc_html( $info['termin'] ); ?></span></div><?php endif; ?>
						<?php if ( $info['ansprechpartner'] ) : ?><div class="bi-wiz__summary-item"><span class="bi-wiz__summary-label">Rückfragen</span><span class="bi-wiz__summary-val"><?php echo esc_html( $info['ansprechpartner'] ); ?></span></div><?php endif; ?>
					</div>
				<?php endif; ?>
				<a class="bi-wiz__success-btn" href="<?php echo esc_url( self::uebersicht_url() ); ?>">Zur Seminarübersicht</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Auswahl, wenn kein Seminar übergeben wurde */
	private static function render_seminar_picker() {
		$today = current_time( 'Y-m-d' );
		$posts = get_posts( array(
			'post_type'   => bi_seminar_post_types(),
			'numberposts' => -1,
			'orderby'     => 'meta_value',
			'meta_key'    => '_bi_startdatum',
			'order'       => 'ASC',
			'meta_query'  => array_merge(
				array(
					array( 'key' => '_bi_startdatum', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' ),
					BI_CPT::visible_clause(),
				),
				BI_CPT::bookable_clauses()
			),
		) );
		$posts = array_filter( $posts, function ( $p ) {
			return BI_CPT::is_bookable( $p->ID );
		} );

		ob_start();
		echo '<div class="bi-wiz-note"><p><strong>Bitte wähle ein Seminar.</strong></p>';
		if ( $posts ) {
			echo '<form method="get" class="bi-wiz-picker">';
			echo '<select name="seminar" onchange="if(this.value)this.form.submit()"><option value="">– Seminar wählen –</option>';
			foreach ( $posts as $p ) {
				$d    = get_post_meta( $p->ID, '_bi_startdatum', true );
				$form = bi_is_online( $p->ID ) ? ' – Online' : '';
				echo '<option value="' . esc_attr( $p->ID ) . '">' . esc_html( get_the_title( $p ) . ( $d ? ' (' . date_i18n( 'd.m.Y', strtotime( $d ) ) . ')' : '' ) . $form ) . '</option>';
			}
			echo '</select> <button type="submit">Weiter</button></form>';
		} else {
			echo '<p>Derzeit sind keine Seminare zur Direktanmeldung verfügbar.</p>';
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Freistellungs-Optionen im Anmeldeformular.
	 *
	 * Primärquelle sind die Seminardaten: die Terme der Taxonomie bi_freistellung
	 * am Seminar (kommen aus der Spalte „Freistellung" des CSV-Imports). Nur wenn
	 * am Seminar nichts gepflegt ist, greift die kanonische Gesamtliste als Fallback.
	 */
	private static function freistellung_options( $seminar_id = 0 ) {
		$canonical = array(
			'Bildungsurlaub',
			'Bildungsurlaubsgesetz',
			'§ 37,6 BetrVG',
			'§ 37,7 BetrVG',
			'§ 179,4 SGB IX',
			'keine Freistellung',
		);

		$terms = $seminar_id ? wp_get_object_terms( $seminar_id, BI_TAX_FREI, array( 'fields' => 'names' ) ) : array();
		if ( is_wp_error( $terms ) || ! $terms ) {
			return $canonical;
		}

		$terms = array_values( array_unique( array_filter( array_map( 'trim', (array) $terms ) ) ) );
		if ( ! $terms ) {
			return $canonical;
		}

		// Kanonische Reihenfolge erzwingen, unbekannte Terme hinten anhängen.
		$known   = array_values( array_intersect( $canonical, $terms ) );
		$unknown = array_values( array_diff( $terms, $canonical ) );

		return array_merge( $known, $unknown );
	}

	/** ---------- Missbrauchsschutz ---------- */

	/** Lebensdauer zwischengelagerter Formulareingaben nach einem Fehler (Sekunden) */
	const ERR_TTL = 600;

	/** Option, die einen ausgelösten Mail-Notaus für die Adminmeldung festhält */
	const BUDGET_OPTION = 'bi_mail_budget_tripped';

	/**
	 * Fehlerhafte Eingaben serverseitig zwischenlagern.
	 *
	 * Früher hingen alle Formularwerte als old[...] an der Redirect-URL. Damit
	 * standen Name, Anschrift, Telefon, Mailadressen, Betrieb und – besonders
	 * heikel – die Angabe zur Gewerkschaftsmitgliedschaft in Browserverlauf,
	 * Server- und Proxy-Logs, Referrern und in jeder geteilten URL. Zurück geht
	 * jetzt nur ein Zufallstoken, das nach zehn Minuten wertlos ist.
	 */
	private static function store_error_state( array $data ) {
		$token = wp_generate_password( 20, false, false );
		set_transient( 'bi_reg_err_' . $token, $data, self::ERR_TTL );
		return $token;
	}

	/** Zwischengelagerte Eingaben zu einem Token (leeres Array = unbekannt/abgelaufen) */
	private static function read_error_state( $token ) {
		$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) $token );
		if ( '' === $token ) {
			return array();
		}
		$data = get_transient( 'bi_reg_err_' . $token );
		return is_array( $data ) ? $data : array();
	}

	/** Abbruch mit Zustand ($state = err|limit|fail) und optional gemerkten Eingaben */
	private static function fail( $state, $redirect, $seminar_id, array $data = array() ) {
		$args = array( 'bi_anmeldung' => $state, 'seminar' => (int) $seminar_id );
		if ( $data ) {
			$args['e'] = self::store_error_state( $data );
		}
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/**
	 * Globaler Notaus für den Mailversand.
	 *
	 * Jede Anmeldung erzeugt drei Mails, zwei davon mit serverseitig gerendertem
	 * PDF- und Word-Anhang. IP- und Mail-Kontingente lassen sich durch Rotation
	 * umgehen, dieses Budget nicht: Es begrenzt, wie viele Anmeldemails pro
	 * Stunde die Domain überhaupt verlassen. Im Normalbetrieb greift es nie.
	 * Anmeldungen werden dabei weiterhin vollständig gespeichert – es entfällt
	 * nur der automatische Versand, die Geschäftsstelle sieht sie in der Liste.
	 */
	private static function mail_budget_ok() {
		$limit = (int) apply_filters( 'bi_mail_budget_per_hour', 100 );
		if ( $limit <= 0 ) {
			return true; // 0 oder negativ = Budget bewusst abgeschaltet
		}
		$key = 'bi_mail_budget_' . gmdate( 'YmdH' );
		$n   = (int) get_transient( $key );
		if ( $n >= $limit ) {
			update_option( self::BUDGET_OPTION, time(), false );
			return false;
		}
		set_transient( $key, $n + 1, 2 * HOUR_IN_SECONDS );
		return true;
	}

	/** Hinweis im Backend, wenn der Mail-Notaus in den letzten 24 Stunden gegriffen hat */
	public static function budget_notice() {
		$when = (int) get_option( self::BUDGET_OPTION, 0 );
		if ( ! $when || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( time() - $when > DAY_IN_SECONDS ) {
			delete_option( self::BUDGET_OPTION );
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Bildungsprogramm:</strong> '
			. 'Das stündliche Mailbudget für Anmeldungen war zuletzt am '
			. esc_html( date_i18n( 'd.m.Y H:i', $when ) ) . ' erschöpft. '
			. 'Anmeldungen wurden weiterhin gespeichert, aber nicht per Mail verschickt – '
			. 'bitte die Liste der Anmeldungen prüfen.</p></div>';
	}

	/** Signierte Zeitmarke fürs Formular (Gegenstück zu submitted_too_fast) */
	private static function timestamp_field() {
		$ts = time();
		return '<input type="hidden" name="bi_ts" value="' . esc_attr( $ts ) . '">'
			. '<input type="hidden" name="bi_tsig" value="' . esc_attr( wp_hash( 'bi_form|' . $ts ) ) . '">';
	}

	/**
	 * Wurde das Formular unrealistisch schnell abgeschickt?
	 *
	 * Vier Schritte mit rund zwanzig Feldern kostet keinen Menschen unter drei
	 * Sekunden. Bewusst „fail open": Fehlen die Felder ganz – etwa weil ein
	 * Seiten-Cache noch eine ältere Fassung des Formulars ausliefert –, gilt das
	 * NICHT als Bot. Nur eine vorhandene, gültig signierte und zu junge Zeitmarke
	 * führt zur Ablehnung. So kann ein Cache niemandem die Anmeldung verbauen.
	 */
	private static function submitted_too_fast() {
		$ts  = (int) bi_post( 'bi_ts' );
		$sig = bi_post( 'bi_tsig' );
		if ( ! $ts || '' === $sig || ! hash_equals( wp_hash( 'bi_form|' . $ts ), $sig ) ) {
			return false;
		}
		$age = time() - $ts;
		return ( $age >= 0 && $age < (int) apply_filters( 'bi_min_fill_seconds', 3 ) );
	}

	/** ---------- POST verarbeiten ---------- */

	public static function handle_submit() {
		// Ziel muss auf der eigenen Seite liegen. wp_safe_redirect() würde fremde
		// Hosts zwar ebenfalls abweisen, aber ins wp-admin umleiten – dort landet
		// eine anmeldende Person auf der Loginmaske statt auf der Anmeldeseite.
		$redirect = wp_validate_redirect( esc_url_raw( bi_post( 'redirect' ) ), self::anmeldung_page_url() ?: home_url() );

		if ( ! wp_verify_nonce( bi_post( 'bi_anmeldung_nonce' ), 'bi_anmeldung' ) ) {
			wp_safe_redirect( add_query_arg( 'bi_anmeldung', 'err', $redirect ) );
			exit;
		}

		$seminar_id = (int) bi_post( 'seminar_id' );

		// Honeypot und Zeitfalle: beides kann nur ein Automat auslösen. Beide melden
		// bewusst Erfolg zurück, damit ein Bot nicht erkennt, woran er gescheitert ist.
		if ( '' !== bi_post( 'bi_hp' ) || self::submitted_too_fast() ) {
			wp_safe_redirect( add_query_arg( 'bi_anmeldung', 'ok', $redirect ) );
			exit;
		}

		$data   = array();
		$errors = false;

		foreach ( self::all_fields() as $key => $f ) {
			$raw = bi_post( $key );
			if ( 'textarea' === $f['type'] ) {
				$raw = sanitize_textarea_field( $raw );
			} elseif ( 'email' === $f['type'] ) {
				$raw = sanitize_email( $raw );
			} else {
				$raw = sanitize_text_field( $raw );
			}
			if ( 'plz' === $f['type'] ) {
				$raw = substr( preg_replace( '/\D/', '', $raw ), 0, 5 );
			}
			if ( ! empty( $f['required'] ) && '' === trim( $raw ) ) {
				$errors = true;
			}
			if ( 'email' === $f['type'] && '' !== $raw && ! is_email( $raw ) ) {
				$errors = true;
			}
			// Längenbegrenzung: im Browser verhindert maxlength die Eingabe, hier
			// scheitert ein direkt abgesetztes POST mit überlangen Werten.
			if ( ! empty( $f['max'] ) && mb_strlen( $raw ) > (int) $f['max'] ) {
				$errors = true;
			}
			// Auswahlfelder dürfen nur ihre eigenen Optionen liefern.
			$allowed = self::allowed_values( $f );
			if ( $allowed && '' !== $raw && ! in_array( $raw, $allowed, true ) ) {
				$errors = true;
			}
			$data[ $key ] = $raw;
		}

		// Freistellung muss eine der am Seminar hinterlegten Möglichkeiten sein.
		if ( '' !== ( $data['freistellung'] ?? '' )
			&& ! in_array( $data['freistellung'], self::freistellung_options( $seminar_id ), true ) ) {
			$errors = true;
		}

		if ( '' === bi_post( 'datenschutz' ) || ! $seminar_id || ! bi_is_seminar_post( $seminar_id )
			|| ! BI_CPT::is_bookable( $seminar_id ) ) {
			$errors = true;
		}

		if ( $errors ) {
			self::fail( 'err', $redirect, $seminar_id, $data );
		}

		// Doppelte Anmeldung derselben Person zum selben Seminar innerhalb weniger
		// Minuten: kommt im Alltag durch Doppelklick oder Zurück-Taste zustande.
		// Für die anmeldende Person ist das ein Erfolg – nur die zweite Mailrunde
		// entfällt. Nebenbei verteuert das jeden Wiederholungsversuch.
		$dupe = 'bi_dupe|' . strtolower( $data['email'] ) . '|' . $seminar_id;
		if ( ! bi_rate_hit( $dupe, 1, 10 * MINUTE_IN_SECONDS ) ) {
			wp_safe_redirect( add_query_arg( array( 'bi_anmeldung' => 'ok', 'seminar' => $seminar_id ), $redirect ) );
			exit;
		}

		// Zwei Kontingente, beide großzügig über jedem realistischen Verhalten:
		// pro Mailadresse (fängt Rotation über wechselnde Seminare ab) und pro
		// Absenderadresse (fängt Rotation über wechselnde Mailadressen ab).
		$ok_mail = bi_rate_hit( 'bi_mail|' . strtolower( $data['email'] ), (int) apply_filters( 'bi_limit_per_email', 5 ), HOUR_IN_SECONDS );
		$ok_ip   = bi_rate_hit( 'bi_ip|' . bi_client_ip(), (int) apply_filters( 'bi_limit_per_ip', 20 ), HOUR_IN_SECONDS );
		if ( ! $ok_mail || ! $ok_ip ) {
			self::fail( 'limit', $redirect, $seminar_id, $data );
		}

		// GS-relevante PLZ = betriebliche PLZ -> Spalte plz.
		// Seminar-Titel/-Nummer/-Termin als Snapshot mitspeichern, damit die Anmeldung
		// auch nach Löschen/Re-Import des Seminars lesbar bleibt.
		$info = self::seminar_info( $seminar_id );
		global $wpdb;
		$inserted = $wpdb->insert( self::table(), array(
			'created'        => current_time( 'mysql' ),
			'seminar_id'     => $seminar_id,
			// Seminarform mitschreiben: bleibt lesbar, auch wenn der Post später verschwindet.
			'post_type'      => get_post_type( $seminar_id ),
			'seminar_titel'  => $info['title'],
			'seminar_nummer' => $info['nummer'],
			'seminar_termin' => $info['termin'],
			'vorname'        => $data['vorname'],
			'nachname'       => $data['nachname'],
			'email'          => $data['email'],
			'telefon'        => $data['telefon'],
			'betrieb'        => $data['betrieb'],
			'plz'            => $data['betrieb_plz'],
			'nachricht'      => $data['bemerkungen'],
			'data'           => wp_json_encode( $data ),
			'status'         => 'neu',
			// Kampagne, über die dieser Besuch hereinkam (leer, wenn ohne Kampagnen-Link).
			// Bewusst als Kopie in der Anmeldung: Die Zahl bleibt gültig, auch wenn die
			// Tracking-Ereignisse später aufgeräumt werden.
			'kampagne'       => BI_Tracking::current_slug(),
		), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		$submission_id = (int) $wpdb->insert_id;

		// Scheitert der Insert (volle Platte, Schemaabweichung, DB-Fehler), darf die
		// Seite keinen Erfolg melden: die Person hielte sich sonst für angemeldet,
		// ohne dass es einen Datensatz gibt. Die Eingaben bleiben erhalten, damit
		// ein zweiter Versuch nicht bei null anfängt.
		if ( false === $inserted || ! $submission_id ) {
			error_log( 'BI_Registration: Anmeldung konnte nicht gespeichert werden. ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			// Duplikatsperre wieder lösen: Es gibt keine erste Anmeldung, gegen die
			// sie schützen müsste. Ohne diese Freigabe liefe der zweite Versuch in
			// die Sperre und bekäme einen Erfolg gemeldet, den es nicht gibt.
			bi_rate_release( $dupe );
			self::fail( 'fail', $redirect, $seminar_id, $data );
		}

		BI_Tracking::track( 'anmeldung', $seminar_id, $submission_id );

		// Der Notaus greift erst hier: gespeichert ist die Anmeldung in jedem Fall,
		// nur der automatische Versand entfällt, wenn das Stundenbudget erschöpft ist.
		if ( self::mail_budget_ok() ) {
			BI_Mailer::dispatch( self::get( $submission_id ) );
		}

		wp_safe_redirect( add_query_arg( array( 'bi_anmeldung' => 'ok', 'seminar' => $seminar_id ), $redirect ) );
		exit;
	}

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( $row && ! empty( $row['data'] ) ) {
			$row['data'] = json_decode( $row['data'], true );
		}
		return $row;
	}

	private static function current_url() {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		// `old` stammt aus der alten Fassung und wird nur noch abgeräumt, falls
		// jemand eine solche URL gespeichert hat; `e` ist das Fehlertoken.
		$req = remove_query_arg( array( 'bi_anmeldung', 'old', 'e' ), $req );
		return home_url( $req );
	}

	/** ---------- Admin: Anmeldungen ---------- */

	public static function render_page() {
		$view = isset( $_GET['view'] ) ? intval( $_GET['view'] ) : 0;
		if ( $view ) {
			self::render_detail( $view );
		} else {
			self::render_list();
		}
	}

	/** WHERE-Klausel für die Suche (optional auf eine Seminarform eingegrenzt) */
	private static function build_where( $search, $form = '' ) {
		global $wpdb;

		$form_cond   = '';
		$form_params = array();
		if ( '' !== $form && in_array( $form, bi_seminar_post_types(), true ) ) {
			// Alt-Datensätze ohne gefüllte Spalte gelten als Präsenz.
			$form_cond = ( BI_CPT === $form )
				? " AND ( post_type = %s OR post_type = '' )"
				: ' AND post_type = %s';
			$form_params[] = $form;
		}

		if ( '' === trim( $search ) ) {
			return array( '1=1' . $form_cond, $form_params );
		}
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$sids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( %s, %s ) AND post_title LIKE %s",
			BI_CPT,
			BI_ONLINE,
			$like
		) );
		$cond   = '(vorname LIKE %s OR nachname LIKE %s OR email LIKE %s OR telefon LIKE %s OR betrieb LIKE %s OR plz LIKE %s OR nachricht LIKE %s OR data LIKE %s OR seminar_titel LIKE %s OR seminar_nummer LIKE %s';
		$params = array( $like, $like, $like, $like, $like, $like, $like, $like, $like, $like );
		if ( $sids ) {
			$cond .= ' OR seminar_id IN (' . implode( ',', array_map( 'intval', $sids ) ) . ')';
		}
		$cond .= ')' . $form_cond;
		return array( $cond, array_merge( $params, $form_params ) );
	}

	private static function sanitize_orderby( $ob ) {
		$allowed = array(
			'created' => 'created', 'name' => 'nachname', 'nachname' => 'nachname',
			'email' => 'email', 'betrieb' => 'betrieb', 'plz' => 'plz', 'seminar' => 'seminar_id',
			'form' => 'post_type',
		);
		return $allowed[ $ob ] ?? 'created';
	}

	private static function count_rows( $search, $form = '' ) {
		global $wpdb;
		list( $where, $params ) = self::build_where( $search, $form );
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE $where";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	}

	private static function fetch( $search, $orderby, $order, $limit, $offset, $form = '' ) {
		global $wpdb;
		list( $where, $params ) = self::build_where( $search, $form );
		$col   = self::sanitize_orderby( $orderby );
		$order = ( 'asc' === strtolower( $order ) ) ? 'ASC' : 'DESC';
		$sql   = 'SELECT * FROM ' . self::table() . " WHERE $where ORDER BY $col $order, id DESC LIMIT %d OFFSET %d";
		$params[] = (int) $limit;
		$params[] = (int) $offset;
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	}

	/**
	 * Titel/Nummer/Termin einer Anmeldung: Snapshot aus der Zeile,
	 * sonst live vom Seminar-Post (ältere Anmeldungen ohne Snapshot).
	 */
	private static function seminar_display( $r ) {
		$titel  = trim( (string) ( $r['seminar_titel'] ?? '' ) );
		$nummer = trim( (string) ( $r['seminar_nummer'] ?? '' ) );
		$termin = trim( (string) ( $r['seminar_termin'] ?? '' ) );

		if ( '' === $titel || '' === $nummer || '' === $termin ) {
			$info   = self::seminar_info( (int) $r['seminar_id'] );
			$titel  = '' !== $titel ? $titel : (string) $info['title'];
			$nummer = '' !== $nummer ? $nummer : (string) $info['nummer'];
			$termin = '' !== $termin ? $termin : (string) $info['termin'];
		}
		if ( '' === $titel ) {
			$titel = 'Seminar #' . (int) $r['seminar_id'] . ' (gelöscht)';
		}
		return array( 'titel' => $titel, 'nummer' => $nummer, 'termin' => $termin );
	}

	/** Anzeigewert eines Feldes (Radio/Select-Labels auflösen) */
	private static function display_value( $key, $f, $value ) {
		if ( ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
			$assoc = array_keys( $f['options'] ) !== range( 0, count( $f['options'] ) - 1 );
			if ( $assoc && isset( $f['options'][ $value ] ) ) {
				return $f['options'][ $value ];
			}
		}
		return $value;
	}

	private static function render_list() {
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created';
		$order   = ( isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ) ? 'asc' : 'desc';
		$form    = isset( $_GET['form'] ) ? sanitize_key( $_GET['form'] ) : '';
		$form    = in_array( $form, bi_seminar_post_types(), true ) ? $form : '';
		$paged   = max( 1, intval( $_GET['paged'] ?? 1 ) );
		$per     = 50;
		$total   = self::count_rows( $search, $form );
		$pages   = max( 1, (int) ceil( $total / $per ) );
		$paged   = min( $paged, $pages );
		$rows    = self::fetch( $search, $orderby, $order, $per, ( $paged - 1 ) * $per, $form );

		$export = wp_nonce_url(
			add_query_arg( array( 'action' => 'bi_export_anmeldungen', 's' => $search, 'form' => $form ), admin_url( 'admin-post.php' ) ),
			'bi_export_anmeldungen'
		);

		$col = function ( $key, $label ) use ( $search, $orderby, $order, $form ) {
			$active    = ( self::sanitize_orderby( $orderby ) === self::sanitize_orderby( $key ) );
			$new_order = ( $active && 'asc' === $order ) ? 'desc' : 'asc';
			$arrow     = $active ? ( 'asc' === $order ? ' ▲' : ' ▼' ) : '';
			$url       = add_query_arg(
				array( 'page' => 'bi-anmeldungen', 's' => $search, 'form' => $form, 'orderby' => $key, 'order' => $new_order ),
				admin_url( 'admin.php' )
			);
			return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $arrow . '</a>';
		};

		echo '<div class="wrap"><h1 class="wp-heading-inline">Anmeldungen</h1>';
		echo ' <a href="' . esc_url( $export ) . '" class="page-title-action">Als CSV exportieren</a>';
		echo '<hr class="wp-header-end">';

		// Suchformular + Filter nach Seminarform
		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="bi-anmeldungen">';
		echo '<p class="search-box"><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Name, E-Mail, Betrieb, PLZ, Seminar …" style="width:320px">';
		echo ' <select name="form">';
		echo '<option value="">alle Seminarformen</option>';
		foreach ( bi_seminar_post_types() as $pt ) {
			echo '<option value="' . esc_attr( $pt ) . '"' . selected( $form, $pt, false ) . '>'
				. esc_html( self::form_label( $pt ) ) . '</option>';
		}
		echo '</select>';
		echo ' <button class="button">Suchen</button>';
		if ( '' !== $search || '' !== $form ) {
			echo ' <a class="button-link" href="' . esc_url( admin_url( 'admin.php?page=bi-anmeldungen' ) ) . '">zurücksetzen</a>';
		}
		echo '</p></form>';

		echo '<p>' . esc_html( number_format_i18n( $total ) ) . ' Anmeldung(en)'
			. ( '' !== $search ? ' für „' . esc_html( $search ) . '"' : '' )
			. ( '' !== $form ? ' (' . esc_html( self::form_label( $form ) ) . ')' : '' ) . '.</p>';

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . $col( 'created', 'Datum' ) . '</th>'
			. '<th>' . $col( 'form', 'Form' ) . '</th>'
			. '<th>' . $col( 'seminar', 'Seminar' ) . '</th>'
			. '<th>' . $col( 'name', 'Name' ) . '</th>'
			. '<th>' . $col( 'email', 'E-Mail' ) . '</th>'
			. '<th>' . $col( 'betrieb', 'Betrieb' ) . '</th>'
			. '<th>' . $col( 'plz', 'Betriebs-PLZ' ) . '</th>'
			. '<th>Geschäftsstelle</th><th></th>'
			. '</tr></thead><tbody>';

		if ( $rows ) {
			foreach ( $rows as $r ) {
				$gs   = BI_PLZ::lookup( $r['plz'] );
				$link = add_query_arg( array( 'page' => 'bi-anmeldungen', 'view' => $r['id'] ), admin_url( 'admin.php' ) );
				$sem  = self::seminar_display( $r );
				$sub  = array_filter( array( $sem['nummer'] ? 'Nr. ' . $sem['nummer'] : '', $sem['termin'] ) );
				echo '<tr>'
					. '<td>' . esc_html( date_i18n( 'd.m.Y H:i', strtotime( $r['created'] ) ) ) . '</td>'
					. '<td>' . esc_html( self::form_label( $r['post_type'] ?? BI_CPT ) ) . '</td>'
					. '<td>' . esc_html( $sem['titel'] )
						. ( $sub ? '<br><span style="color:#666;font-size:12px">' . esc_html( implode( ' · ', $sub ) ) . '</span>' : '' ) . '</td>'
					. '<td><a href="' . esc_url( $link ) . '"><strong>' . esc_html( trim( $r['vorname'] . ' ' . $r['nachname'] ) ?: '(ohne Namen)' ) . '</strong></a></td>'
					. '<td>' . esc_html( $r['email'] ) . '</td>'
					. '<td>' . esc_html( $r['betrieb'] ) . '</td>'
					. '<td>' . esc_html( $r['plz'] ) . '</td>'
					. '<td>' . esc_html( $gs ? $gs['geschaeftsstelle'] : '—' ) . '</td>'
					. '<td><a href="' . esc_url( $link ) . '" class="button button-small">Details</a></td>'
					. '</tr>';
			}
		} else {
			echo '<tr><td colspan="9">Keine Anmeldungen gefunden.</td></tr>';
		}
		echo '</tbody></table>';

		// Pagination
		if ( $pages > 1 ) {
			$base = admin_url( 'admin.php' ) . '?' . http_build_query( array(
				'page' => 'bi-anmeldungen', 's' => $search, 'form' => $form, 'orderby' => $orderby, 'order' => $order,
			) ) . '&paged=%#%';
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array(
				'base'      => $base,
				'format'    => '',
				'current'   => $paged,
				'total'     => $pages,
				'prev_text' => '‹',
				'next_text' => '›',
			) ) . '</div></div>';
		}

		echo '</div>';
	}

	private static function render_detail( $id ) {
		$r = self::get( $id );
		$back = admin_url( 'admin.php?page=bi-anmeldungen' );
		echo '<div class="wrap"><h1>Anmeldung #' . intval( $id ) . '</h1>';
		echo '<p><a href="' . esc_url( $back ) . '">← Zurück zur Liste</a></p>';

		if ( ! $r ) {
			echo '<div class="notice notice-error"><p>Anmeldung nicht gefunden.</p></div></div>';
			return;
		}

		$data = is_array( $r['data'] ?? null ) ? $r['data'] : array();
		$gs   = BI_PLZ::lookup( $r['plz'] );

		// Kopf-Box
		echo '<table class="widefat" style="max-width:780px;margin-bottom:20px"><tbody>';
		echo '<tr><th style="width:220px">Eingegangen am</th><td>' . esc_html( date_i18n( 'd.m.Y H:i', strtotime( $r['created'] ) ) ) . '</td></tr>';
		echo '<tr><th>Seminarform</th><td>' . esc_html( self::form_label( $r['post_type'] ?? BI_CPT ) ) . '</td></tr>';
		$sem      = self::seminar_display( $r );
		$sem_link = get_edit_post_link( $r['seminar_id'] );
		echo '<tr><th>Seminar</th><td>' . ( $sem_link ? '<a href="' . esc_url( $sem_link ) . '">' . esc_html( $sem['titel'] ) . '</a>' : esc_html( $sem['titel'] ) )
			. ' <span style="color:#666">(Nr. ' . esc_html( $sem['nummer'] ?: '—' ) . ')</span></td></tr>';
		echo '<tr><th>Termin</th><td>' . ( $sem['termin'] ? esc_html( $sem['termin'] ) : '<span style="color:#999">—</span>' ) . '</td></tr>';
		echo '<tr><th>Zuständige Geschäftsstelle</th><td>' . ( $gs
			? esc_html( $gs['geschaeftsstelle'] ) . ' &lt;' . esc_html( $gs['email'] ) . '&gt;'
			: '<em>keine Zuordnung für PLZ ' . esc_html( $r['plz'] ) . '</em>' ) . '</td></tr>';
		echo '<tr><th>Status</th><td>' . esc_html( $r['status'] ) . '</td></tr>';
		echo '<tr><th>Kampagne</th><td>' . ( ! empty( $r['kampagne'] )
			? esc_html( $r['kampagne'] )
			: '<span style="color:#999">— direkt, ohne Kampagnen-Link</span>' ) . '</td></tr>';
		echo '</tbody></table>';

		// Felder nach Schritten gruppiert
		foreach ( self::form_steps() as $step ) {
			echo '<h2>' . esc_html( $step['title'] ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:780px;margin-bottom:18px"><tbody>';
			foreach ( $step['fields'] as $key => $f ) {
				$val = self::display_value( $key, $f, (string) ( $data[ $key ] ?? '' ) );
				echo '<tr><th style="width:220px">' . esc_html( $f['label'] ) . '</th><td>' . ( '' !== trim( $val ) ? nl2br( esc_html( $val ) ) : '<span style="color:#999">—</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	/** ---------- CSV-Export ---------- */

	/**
	 * Zelle für den Export entschärfen.
	 *
	 * Excel und LibreOffice werten Zellen, die mit = + - @ oder einem Steuerzeichen
	 * beginnen, beim Öffnen als Formel aus. Ein Bemerkungsfeld mit =HYPERLINK(…)
	 * liefe also auf dem Rechner der Person, die den Export öffnet. Das
	 * vorangestellte Hochkomma zwingt zur Textdarstellung; im Tabellenprogramm
	 * ist es nicht Teil des Zellinhalts.
	 */
	private static function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_export_anmeldungen' );

		$search = sanitize_text_field( bi_get( 's' ) );
		$form   = sanitize_key( bi_get( 'form' ) );
		$form   = in_array( $form, bi_seminar_post_types(), true ) ? $form : '';
		$rows   = self::fetch( $search, 'created', 'desc', 100000, 0, $form );
		$fields = self::all_fields();

		$header = array( 'ID', 'Eingegangen', 'Seminarform', 'Seminar', 'Seminarnummer', 'Termin', 'Geschäftsstelle', 'GS-E-Mail' );
		foreach ( $fields as $f ) {
			$header[] = $f['label'];
		}
		$header[] = 'Status';
		$header[] = 'Kampagne';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="anmeldungen-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // BOM für korrekte Umlaute in Excel
		fputcsv( $out, $header, ';' );

		foreach ( $rows as $r ) {
			$data = ! empty( $r['data'] ) ? json_decode( $r['data'], true ) : array();
			$gs   = BI_PLZ::lookup( $r['plz'] );
			$sem  = self::seminar_display( $r );
			$line = array(
				$r['id'],
				$r['created'],
				self::form_label( $r['post_type'] ?? BI_CPT ),
				$sem['titel'],
				$sem['nummer'],
				$sem['termin'],
				$gs ? $gs['geschaeftsstelle'] : '',
				$gs ? $gs['email'] : '',
			);
			foreach ( $fields as $key => $f ) {
				$line[] = self::display_value( $key, $f, (string) ( $data[ $key ] ?? '' ) );
			}
			$line[] = $r['status'];
			$line[] = $r['kampagne'] ?? '';
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $line ), ';' );
		}
		fclose( $out );
		exit;
	}
}
