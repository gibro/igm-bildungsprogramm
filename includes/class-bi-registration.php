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
 * (Benachrichtigung Typ „Geschäftsstelle").
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
		// Archivo lokal ausliefern statt über fonts.googleapis.com: der CDN-Abruf
		// überträgt die IP-Adresse an Google – auf der Anmeldeseite, auf der
		// personenbezogene Daten erhoben werden, datenschutzrechtlich nicht haltbar
		// (LG München I, 3 O 17493/20). Dateien: assets/fonts/archivo-*.woff2.
		wp_register_style( 'bi-archivo', BI_URL . 'assets/css/archivo.css', array(), BI_VERSION );
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
			reihe_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			durchgang SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			teil SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			sammel_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
			KEY kampagne (kampagne),
			KEY sammel_id (sammel_id),
			KEY reihe_id (reihe_id)
		) $charset;";
		dbDelta( $sql );
	}

	/* ===================================================================
	 *  Anmeldung zu einer Ausbildungsreihe
	 * ===================================================================
	 *
	 *  EINE ZEILE JE TEIL, ZUSAMMENGEHALTEN VON `sammel_id`.
	 *
	 *  Die naheliegende Alternative – eine Zeile mit einer Liste von Seminaren –
	 *  wäre an jeder Auswertung gescheitert: Mail-Platzhalter, PDF-Anhang,
	 *  Geschäftsstellen-Zuordnung, Export und Kampagnen-Auswertung gehen alle von
	 *  genau einem Seminar je Anmeldung aus. Fachlich stimmt die Zerlegung
	 *  ohnehin besser: Teil 1 in Lohr und Teil 2 in Berlin sind zwei Buchungen in
	 *  zwei Bildungszentren, die auch getrennt bestätigt und storniert werden.
	 *
	 *  Zusammengehalten werden sie über `sammel_id` – die id der ersten Zeile.
	 *  `reihe_id`, `durchgang` und `teil` machen die Zeile für sich lesbar, ohne
	 *  dass jemand die Zuordnung über das Seminar nachschlagen muss.
	 *
	 *  Beim Versand werden die Zeilen wieder zusammengefasst: BI_Mailer::
	 *  dispatch_reihe() schickt je Empfängeradresse EINE Mail. Sonst bekäme die
	 *  angemeldete Person vier Bestätigungen für eine Anmeldung.
	 */

	/** Zeilen einer Sammelanmeldung, nach Teil sortiert. */
	public static function sammlung( $sammel_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . self::table() . ' WHERE sammel_id = %d ORDER BY teil ASC, id ASC',
			(int) $sammel_id
		), ARRAY_A );
		foreach ( $rows as $i => $row ) {
			if ( ! empty( $row['data'] ) ) {
				$rows[ $i ]['data'] = json_decode( $row['data'], true );
			}
		}
		return $rows;
	}

	/**
	 * Wizard-Schritte eines Formulars. Feld: [label, type, required, full, col,
	 * placeholder, options, default, max].
	 * type: text|email|tel|plz|date|textarea|select|radio|checkbox|freistellung
	 * category: privat|dienstlich|neutral
	 *
	 * Die Struktur kommt aus der Formularverwaltung (BI_Formulare), die Felder
	 * aus dem Bestand (BI_Anmeldefelder). Bis dahin stand beides fest verdrahtet
	 * an dieser Stelle; das mitgelieferte Standardformular ist Wort für Wort
	 * dasselbe, damit eine bestehende Installation nichts merkt.
	 *
	 * `max` ist die fachliche Höchstlänge in Zeichen. Sie wird zweifach durchgesetzt:
	 * als maxlength im Browser (dort merkt sie niemand) und noch einmal serverseitig
	 * in handle_submit(). Ohne die serverseitige Prüfung kann ein direkt abgesetztes
	 * POST beliebig große Werte in Datenbank, JSON-Spalte und alle drei Mailtexte
	 * schreiben. Die Werte liegen bewusst deutlich über jeder realen Eingabe und
	 * unterhalb der jeweiligen Spaltenbreite in create_table().
	 *
	 * @param string $formular Schlüssel des Formulars; leer = Standardformular.
	 */
	public static function form_steps( $formular = '' ) {
		return BI_Formulare::seiten( $formular );
	}

	/**
	 * Welches Formular gilt für dieses Seminar?
	 *
	 * Immer aus der Seminar-ID abgeleitet, nie aus dem abgeschickten Formular:
	 * Käme der Schlüssel aus dem POST, ließe sich das Formular mit den
	 * wenigsten Pflichtfeldern benennen und damit die Prüfung des eigentlich
	 * gültigen umgehen.
	 */
	public static function formular_key( $seminar_id ) {
		return BI_Formulare::formular_for( (int) $seminar_id );
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

	/** Alle Felder EINES Formulars – die Menge, die geprüft und gespeichert wird. */
	private static function all_fields( $formular = '' ) {
		$out = array();
		foreach ( self::form_steps( $formular ) as $step ) {
			foreach ( $step['fields'] as $key => $f ) {
				$out[ $key ] = $f;
			}
		}
		return $out;
	}

	/**
	 * Der ganze Feld-Bestand – für Ansichten, die über alle Anmeldungen laufen.
	 *
	 * Export und Detailansicht dürfen NICHT die Felder eines einzelnen Formulars
	 * nehmen: Sonst wechselten die Spalten der CSV-Datei je nachdem, welches
	 * Formular gerade das Standardformular ist, und eine Anmeldung aus einem
	 * anderen Formular verlöre in der Anzeige die Hälfte ihrer Angaben.
	 */
	private static function bestand_fields() {
		return BI_Anmeldefelder::alle();
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

	/**
	 * Termin-IDs aus der Anfrage („12,34,56"). Nur Zahlen – geprüft wird die
	 * Auswahl anschließend in BI_Reihen::auswahl_pruefen().
	 */
	private static function termine_aus_request() {
		$roh = bi_get( 'termine' );
		if ( '' === $roh ) {
			$roh = bi_post( 'termine' );
		}
		if ( '' === trim( (string) $roh ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', explode( ',', (string) $roh ) ) ) );
	}

	/**
	 * Anzeigedaten einer Reihenanmeldung: Titel der Reihe, Gruppe, Umfang und
	 * die gewählten Teile in ihrer Reihenfolge.
	 *
	 * @param array $termine [ teil => post_id ], bereits geprüft und sortiert.
	 */
	private static function reihe_info( $reihe_id, $durchgang, $termine ) {
		$liste = array();
		foreach ( $termine as $teil => $sid ) {
			$info    = self::seminar_info( $sid );
			$liste[] = array(
				'teil'   => (int) $teil,
				'id'     => (int) $sid,
				'titel'  => $info['title'],
				'nummer' => $info['nummer'],
				'termin' => $info['termin'],
				'ort'    => $info['ort'],
			);
		}
		$anzahl = count( $liste );
		return array(
			'titel'  => get_the_title( $reihe_id ),
			'gruppe' => $durchgang ? 'Reihe ' . (int) $durchgang : 'ohne feste Gruppe',
			'umfang' => sprintf( _n( '%d Teil', '%d Teile', $anzahl, 'bi-seminarsuche' ), $anzahl ),
			'liste'  => $liste,
		);
	}

	/** ---------- Shortcode ---------- */

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'seminar' => 0, 'reihe' => 0 ), $atts, 'bi_anmeldung' );

		wp_enqueue_style( 'bi-archivo' );
		wp_enqueue_style( 'bi-anmeldung' );
		wp_enqueue_script( 'bi-anmeldung' );

		$seminar_id = intval( $atts['seminar'] );
		if ( ! $seminar_id ) {
			$seminar_id = intval( bi_get( 'seminar' ) );
		}
		$reihe_id = intval( $atts['reihe'] ) ?: intval( bi_get( 'reihe' ) );

		$state = bi_get( 'bi_anmeldung' );

		// Erfolgs-Screen
		if ( 'ok' === $state ) {
			return $reihe_id
				? self::render_success_reihe( $reihe_id, self::termine_aus_request() )
				: self::render_success( $seminar_id );
		}

		// Auf Fehlerseiten stehen wieder Eingaben im Formular – die dürfen kein
		// Seiten-Cache und kein Proxy zwischenspeichern und an Dritte ausliefern.
		if ( in_array( $state, array( 'err', 'limit', 'fail' ), true ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}

		/* ---- Reihenanmeldung: eine Auswahl, ein Formular, mehrere Anmeldungen ---- */
		if ( $reihe_id ) {
			$pruefung = BI_Reihen::auswahl_pruefen( $reihe_id, self::termine_aus_request() );
			if ( ! $pruefung['ok'] ) {
				$zurueck = get_permalink( $reihe_id );
				return '<div class="bi-wiz-note"><p><strong>Die Auswahl passt nicht.</strong> '
					. esc_html( $pruefung['grund'] ) . '</p>'
					. ( $zurueck ? '<p><a href="' . esc_url( $zurueck ) . '">Zurück zur Ausbildungsreihe</a></p>' : '' )
					. '</div>';
			}
			return self::render_form( array(
				'reihe_id'  => $reihe_id,
				'durchgang' => $pruefung['durchgang'],
				'termine'   => $pruefung['termine'],
			), $state );
		}

		$has_fixed = $seminar_id && bi_is_seminar_post( $seminar_id );

		// Kein (gültiges) Seminar -> Auswahl anbieten
		if ( ! $has_fixed ) {
			return self::render_seminar_picker();
		}

		// Teil einer nur komplett buchbaren Reihe: Die Einzelanmeldung gibt es
		// nicht – auch nicht über eine von Hand getippte Adresse. Auf der
		// Seminarseite steht dafür gar kein Button mehr; hier zählt, dass die
		// Adresse aus der URL stammt und jede getippte Zahl tragen kann.
		$nur_komplett = BI_Reihen::nur_komplett( $seminar_id );
		if ( $nur_komplett ) {
			$zurueck = get_permalink( $nur_komplett );
			return '<div class="bi-wiz-note"><p><strong>Dieses Seminar ist Teil einer Ausbildungsreihe</strong>, '
				. 'die sich nur vollständig buchen lässt. Eine Anmeldung zu einem einzelnen Teil ist nicht möglich.</p>'
				. ( $zurueck ? '<p><a href="' . esc_url( $zurueck ) . '">Zur Ausbildungsreihe „'
					. esc_html( get_the_title( $nur_komplett ) ) . '"</a></p>' : '' )
				. '</div>';
		}

		// Nicht direkt buchbar -> Hinweis statt Formular
		if ( ! BI_CPT::is_bookable( $seminar_id ) ) {
			$variante = bi_is_online( $seminar_id ) ? BI_Online::variante( $seminar_id ) : '';
			$link     = '';

			if ( BI_CPT::meta_bool( $seminar_id, '_bi_ausgebucht' ) ) {
				$grund = ' Das Seminar ist ausgebucht.';
			} elseif ( 'keine' === BI_Settings::variant_for( $seminar_id ) ) {
				// Variante 3: Hier gibt es keinen Ausweichweg, nur die Auskunft.
				$grund = ' ' . BI_Settings::get( 'keine_hinweis' );
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

		return self::render_form( array( 'termine' => array( 0 => $seminar_id ) ), $state );
	}

	/**
	 * Der Wizard – für ein einzelnes Seminar wie für eine ganze Reihe.
	 *
	 * @param array  $buchung reihe_id, durchgang, termine (teil => post_id).
	 * @param string $state   Zustand aus der URL (err/limit/fail).
	 */
	private static function render_form( $buchung, $state ) {
		$reihe_id  = (int) ( $buchung['reihe_id'] ?? 0 );
		$termine   = (array) $buchung['termine'];
		$ids       = array_values( array_map( 'intval', $termine ) );
		$erste     = (int) reset( $ids );
		$ist_reihe = $reihe_id > 0;

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
		BI_Tracking::track( 'formular', $erste );

		$info = self::seminar_info( $erste );
		// Freistellung: Bei einer Reihe muss die Angabe zu allen Teilen passen,
		// also zählt der Durchschnitt nicht, sondern die Schnittmenge – was nur bei
		// einem Teil erlaubt ist, ist für die Reihe keine gültige Freistellung.
		$frei_opts = self::freistellung_options( $ids );
		// Das Formular hängt am Seminar. Bei einer Reihe entscheidet der erste
		// Teil für alle – eine Anmeldung, ein Satz Angaben; zwei verschiedene
		// Formulare in einem Vorgang gäbe es keine sinnvolle Reihenfolge für.
		$steps     = self::form_steps( self::formular_key( $erste ) );
		$letzte    = count( $steps ) - 1;
		$teile     = $ist_reihe ? self::reihe_info( $reihe_id, $buchung['durchgang'] ?? 0, $termine ) : null;

		ob_start();
		?>
		<div class="bi-wiz" style="--accent:#E2001A;--sidebar-bg:#E2001A;">
			<form class="bi-wiz__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
				<input type="hidden" name="action" value="bi_anmeldung">
				<input type="hidden" name="seminar_id" value="<?php echo esc_attr( $erste ); ?>">
				<?php if ( $ist_reihe ) : ?>
					<input type="hidden" name="reihe_id" value="<?php echo esc_attr( $reihe_id ); ?>">
					<input type="hidden" name="termine" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">
				<?php endif; ?>
				<input type="hidden" name="redirect" value="<?php echo esc_url( self::current_url() ); ?>">
				<?php wp_nonce_field( 'bi_anmeldung', 'bi_anmeldung_nonce' ); ?>
				<?php echo self::timestamp_field(); // phpcs:ignore – intern escaped ?>
				<div class="bi-wiz__hp" aria-hidden="true"><label>Bitte leer lassen<input type="text" name="bi_hp" tabindex="-1" autocomplete="off"></label></div>

				<!-- Sidebar -->
				<aside class="bi-wiz__sidebar">
					<div class="bi-wiz__eyebrow"><?php echo esc_html( $ist_reihe ? 'Anmeldung zur Ausbildungsreihe' : 'Seminaranmeldung' ); ?></div>
					<h2 class="bi-wiz__title"><?php echo esc_html( $ist_reihe ? $teile['titel'] : $info['title'] ); ?></h2>
					<div class="bi-wiz__divider"></div>
					<?php if ( $ist_reihe ) : ?>
						<div class="bi-wiz__info">
							<div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Gruppe</span><span class="bi-wiz__info-val"><?php echo esc_html( $teile['gruppe'] ); ?></span></div>
							<div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Umfang</span><span class="bi-wiz__info-val"><?php echo esc_html( $teile['umfang'] ); ?></span></div>
						</div>
						<div class="bi-wiz__divider"></div>
						<ol class="bi-wiz__teile">
							<?php foreach ( $teile['liste'] as $t ) : ?>
								<li class="bi-wiz__teil">
									<span class="bi-wiz__teil-nr">Teil <?php echo esc_html( $t['teil'] ); ?></span>
									<span class="bi-wiz__teil-titel"><?php echo esc_html( $t['titel'] ); ?></span>
									<span class="bi-wiz__teil-meta"><?php echo esc_html( trim( $t['termin'] . ( $t['ort'] ? ' · ' . $t['ort'] : '' ) ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ol>
					<?php else : ?>
					<div class="bi-wiz__info">
						<?php if ( $info['termin'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Termin</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['termin'] ); ?></span></div><?php endif; ?>
						<?php if ( $info['ort'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Ort</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['ort'] ); ?></span></div><?php endif; ?>
						<div class="bi-wiz__info-cols">
							<?php if ( $info['nummer'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Seminarnummer</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['nummer'] ); ?></span></div><?php endif; ?>
							<?php if ( $info['ansprechpartner'] ) : ?><div class="bi-wiz__info-row"><span class="bi-wiz__info-label">Ansprechpartner*in</span><span class="bi-wiz__info-val"><?php echo esc_html( $info['ansprechpartner'] ); ?></span></div><?php endif; ?>
						</div>
					</div>
					<?php endif; ?>
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

							<?php // Zusammenfassung, Seminarangaben und Einwilligung stehen immer
							      // auf der LETZTEN Seite – egal, wie sie heißt. Vorher hing das an
							      // einem festen Schlüssel; ein umbenannter Abschluss hätte den
							      // Absendeknopf verschwinden lassen.
							if ( $i === $letzte ) : ?>
								<div class="bi-wiz__review">
									<div class="bi-wiz__review-title">Deine Angaben</div>
									<div class="bi-wiz__review-list" data-review>
										<p class="bi-wiz__review-empty">Bitte fülle die vorherigen Schritte aus.</p>
									</div>
								</div>
								<?php echo $ist_reihe ? self::summary_card_reihe( $teile ) : self::summary_card( $info ); // phpcs:ignore ?>
								<label class="bi-wiz__consent">
									<input type="checkbox" name="datenschutz" value="1" data-req="1">
									<span><?php echo $ist_reihe
										? 'Ich melde mich verbindlich zu <strong>allen oben genannten Teilen</strong> der Ausbildungsreihe an und habe die Hinweise zur Verarbeitung meiner personenbezogenen Daten zur Kenntnis genommen.'
										: 'Ich melde mich verbindlich an und habe die Hinweise zur Verarbeitung meiner personenbezogenen Daten zur Kenntnis genommen.'; // phpcs:ignore ?> <span class="bi-wiz__req">*</span></span>
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
				// Aufzählung („Frau", „Herr") -> der Text ist zugleich der Wert;
				// Zuordnung („ja|Ja") -> der Schlüssel ist der Wert.
				$ov = is_int( $ov ) ? $ol : $ov;
				printf(
					'<label class="bi-wiz__radio"><input type="radio" name="%1$s" value="%2$s"%3$s>%4$s</label>',
					esc_attr( $key ),
					esc_attr( $ov ),
					checked( $cur, $ov, false ),
					esc_html( $ol )
				);
			}
			echo '</div>';
			if ( ! empty( $f['hint'] ) ) {
				echo '<span class="bi-wiz__hint">' . esc_html( $f['hint'] ) . '</span>';
			}
			echo '</div>';
			return ob_get_clean();
		}

		// Ein einzelnes Kästchen trägt seine Frage neben sich, nicht darüber –
		// eine Beschriftung über einem Haken liest sich wie eine Überschrift ohne Inhalt.
		if ( 'checkbox' === $f['type'] ) {
			printf(
				'<label class="bi-wiz__radio"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s%4$s>%5$s</label>',
				esc_attr( $id ),
				esc_attr( $key ),
				checked( '1', $val, false ),
				$reqa,
				esc_html( $f['label'] ) . $star
			);
			if ( ! empty( $f['hint'] ) ) {
				echo '<span class="bi-wiz__hint">' . esc_html( $f['hint'] ) . '</span>';
			}
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
				$cur  = ( '' === $val && ! empty( $f['default'] ) ) ? (string) $f['default'] : $val;
				$opts = (array) $f['options'];
				// Bei einer Zuordnung („vegan|Vegan") fehlt die leere Auswahl,
				// die eine Aufzählung als ersten Eintrag mitbringt.
				if ( $opts && ! BI_Anmeldefelder::ist_liste( $opts ) ) {
					echo '<option value="">Bitte wählen</option>';
				}
				foreach ( $opts as $ov => $ol ) {
					$ov    = is_int( $ov ) ? $ol : $ov;
					$label = '' === $ol ? 'Bitte wählen' : $ol;
					echo '<option value="' . esc_attr( $ov ) . '"' . selected( $cur, $ov, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			case 'date':
				echo '<input type="date" class="bi-wiz__input" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '"' . $reqa . '>';
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

	/** Übersichtskarte in Schritt 4 – Reihenanmeldung */
	private static function summary_card_reihe( $teile ) {
		ob_start();
		echo '<div class="bi-wiz__summary bi-wiz__summary--reihe"><div class="bi-wiz__summary-title">Deine Anmeldung im Überblick</div>';
		echo '<div class="bi-wiz__summary-list">';
		echo '<div class="bi-wiz__summary-item"><span class="bi-wiz__summary-label">Ausbildungsreihe</span>'
			. '<span class="bi-wiz__summary-val">' . esc_html( $teile['titel'] ) . '</span></div>';
		echo '<div class="bi-wiz__summary-item"><span class="bi-wiz__summary-label">Gruppe</span>'
			. '<span class="bi-wiz__summary-val">' . esc_html( $teile['gruppe'] . ' · ' . $teile['umfang'] ) . '</span></div>';
		foreach ( $teile['liste'] as $t ) {
			$wert = trim( $t['titel'] . ' – ' . $t['termin'] . ( $t['ort'] ? ', ' . $t['ort'] : '' ) );
			echo '<div class="bi-wiz__summary-item"><span class="bi-wiz__summary-label">Teil ' . (int) $t['teil'] . '</span>'
				. '<span class="bi-wiz__summary-val">' . esc_html( $wert ) . '</span></div>';
		}
		echo '</div>';
		echo '<p class="bi-wiz__summary-note">Mit dem Absenden entstehen ' . (int) count( $teile['liste'] )
			. ' Anmeldungen – eine je Teil. Jedes Bildungszentrum bestätigt seinen Teil selbst.</p>';
		echo '</div>';
		return ob_get_clean();
	}

	/** Erfolgs-Screen – Reihenanmeldung */
	private static function render_success_reihe( $reihe_id, $ids ) {
		$pruefung = BI_Reihen::auswahl_pruefen( $reihe_id, $ids );
		// Nach dem Absenden zählt nur noch die Anzeige: Sind die Termine
		// zwischenzeitlich ausgebucht, ist die Anmeldung trotzdem eingegangen.
		$termine = $pruefung['ok'] ? $pruefung['termine'] : array();
		$titel   = get_the_title( $reihe_id );

		ob_start();
		?>
		<div class="bi-wiz bi-wiz--success" style="--accent:#E2001A;">
			<div class="bi-wiz__success">
				<div class="bi-wiz__success-circle">✓</div>
				<h2 class="bi-wiz__success-h2">Anmeldung übermittelt</h2>
				<p class="bi-wiz__success-text">Vielen Dank! Deine Anmeldung<?php echo $titel ? ' zur Ausbildungsreihe <strong>' . esc_html( $titel ) . '</strong>' : ''; ?> ist eingegangen –
					für jeden Teil eine eigene. Eine Bestätigung erhältst du per E-Mail.</p>
				<?php if ( $termine ) : ?>
					<div class="bi-wiz__success-box">
						<?php foreach ( $termine as $teil => $sid ) :
							$info = self::seminar_info( $sid ); ?>
							<div class="bi-wiz__summary-item">
								<span class="bi-wiz__summary-label">Teil <?php echo (int) $teil; ?></span>
								<span class="bi-wiz__summary-val"><?php echo esc_html( trim( $info['title'] . ' – ' . $info['termin'] . ( $info['ort'] ? ', ' . $info['ort'] : '' ) ) ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<a class="bi-wiz__success-btn" href="<?php echo esc_url( self::uebersicht_url() ); ?>">Zur Seminarübersicht</a>
			</div>
		</div>
		<?php
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
	 * Die feste Liste, auf die das Formular zurückfällt, wenn am Seminar keine
	 * Freistellung gepflegt ist. Öffentlich, weil die Benachrichtigungen (BI_Mailer)
	 * wissen müssen, welche Werte „Freistellung laut Anmeldung“ annehmen kann.
	 *
	 * @return string[]
	 */
	public static function freistellung_kanon() {
		return array(
			'Bildungsurlaub',
			'Bildungsurlaubsgesetz',
			'§ 37,6 BetrVG',
			'§ 37,7 BetrVG',
			'§ 179,4 SGB IX',
			'keine Freistellung',
		);
	}

	/**
	 * Freistellungs-Optionen im Anmeldeformular.
	 *
	 * Primärquelle sind die Seminardaten: die Terme der Taxonomie bi_freistellung
	 * am Seminar (kommen aus der Spalte „Freistellung" des CSV-Imports). Nur wenn
	 * am Seminar nichts gepflegt ist, greift die kanonische Gesamtliste als Fallback.
	 *
	 * Bei einer Reihenanmeldung wird die Frage einmal für alle Teile gestellt.
	 * Gültig ist dann nur, was bei JEDEM Teil zulässig ist – die Schnittmenge.
	 * Wäre sie leer (unterschiedlich gepflegte Teile), stünde niemandem eine
	 * Auswahl zur Verfügung; dann tritt die Vereinigung an ihre Stelle, damit das
	 * Formular benutzbar bleibt.
	 *
	 * @param int|int[] $seminar_id Ein Seminar oder mehrere (Reihenanmeldung).
	 */
	private static function freistellung_options( $seminar_id = 0 ) {
		$canonical = self::freistellung_kanon();

		$ids = array_values( array_filter( array_map( 'intval', (array) $seminar_id ) ) );
		if ( ! $ids ) {
			return $canonical;
		}

		$je_seminar = array();
		foreach ( $ids as $id ) {
			$t = wp_get_object_terms( $id, BI_TAX_FREI, array( 'fields' => 'names' ) );
			$t = is_wp_error( $t ) ? array() : array_values( array_unique( array_filter( array_map( 'trim', (array) $t ) ) ) );
			if ( $t ) {
				$je_seminar[] = $t;
			}
		}
		if ( ! $je_seminar ) {
			return $canonical;
		}

		$terms = array_shift( $je_seminar );
		$union = $terms;
		foreach ( $je_seminar as $weitere ) {
			$terms = array_intersect( $terms, $weitere );
			$union = array_merge( $union, $weitere );
		}
		$terms = $terms ? array_values( $terms ) : array_values( array_unique( $union ) );
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
	/**
	 * Abbruch mit Rücksprung ins Formular.
	 *
	 * Bei einer Reihenanmeldung müssen Reihe und Terminauswahl mit zurück –
	 * sonst stünde nach einem Tippfehler plötzlich das Einzelformular da und die
	 * Auswahl wäre verloren.
	 */
	private static function fail( $state, $redirect, $seminar_id, array $data = array(), $reihe_id = 0 ) {
		$args = array( 'bi_anmeldung' => $state, 'seminar' => (int) $seminar_id );
		if ( $reihe_id ) {
			$args['reihe']   = (int) $reihe_id;
			$args['termine'] = implode( ',', self::termine_aus_request() );
		}
		if ( $data ) {
			$args['e'] = self::store_error_state( $data );
		}
		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	/** Query-Parameter für den Erfolgs-Screen (Einzel- wie Reihenanmeldung). */
	private static function erfolg_args( $seminar_id, $reihe_id, $termine ) {
		if ( ! $reihe_id ) {
			return array( 'bi_anmeldung' => 'ok', 'seminar' => (int) $seminar_id );
		}
		return array(
			'bi_anmeldung' => 'ok',
			'reihe'        => (int) $reihe_id,
			'termine'      => implode( ',', array_map( 'intval', array_values( $termine ) ) ),
		);
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
		if ( ! $when || ! current_user_can( BI_CAP ) ) {
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
		$reihe_id   = (int) bi_post( 'reihe_id' );

		// Honeypot und Zeitfalle: beides kann nur ein Automat auslösen. Beide melden
		// bewusst Erfolg zurück, damit ein Bot nicht erkennt, woran er gescheitert ist.
		if ( '' !== bi_post( 'bi_hp' ) || self::submitted_too_fast() ) {
			wp_safe_redirect( add_query_arg( 'bi_anmeldung', 'ok', $redirect ) );
			exit;
		}

		// Reihenanmeldung: Die Auswahl wird noch einmal vollständig geprüft – das
		// Formular kann seit dem Aufruf veraltet sein (Termin ausgebucht, Seminar
		// zurückgezogen), und die IDs stammen aus einer URL.
		$reihe_termine = array();
		$durchgang     = 0;
		if ( $reihe_id ) {
			$pruefung = BI_Reihen::auswahl_pruefen( $reihe_id, self::termine_aus_request() );
			if ( ! $pruefung['ok'] ) {
				self::fail( 'err', $redirect, $seminar_id, array(), $reihe_id );
			}
			$reihe_termine = $pruefung['termine'];
			$durchgang     = $pruefung['durchgang'];
			// Für alle folgenden Prüfungen (Freistellung, Buchbarkeit) zählt der
			// erste Teil stellvertretend; die übrigen sind bereits geprüft.
			$seminar_id = (int) reset( $reihe_termine );
		}

		// Welches Formular gilt – aus der Seminar-ID, nicht aus dem POST. Geprüft
		// wird genau die Feldmenge, die dieses Seminar auch angezeigt bekommen
		// hat; ein Feld aus einem anderen Formular wird schlicht nicht gelesen.
		$formular = self::formular_key( $seminar_id );

		$data   = array();
		$errors = false;

		foreach ( self::all_fields( $formular ) as $key => $f ) {
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
			if ( 'checkbox' === $f['type'] ) {
				// Ein nicht angehaktes Kästchen sendet gar nichts. Alles außer
				// der eigenen „1" ist deshalb ein Nein, kein Fehler.
				$raw = ( '1' === $raw ) ? '1' : '';
			}
			if ( 'date' === $f['type'] && '' !== $raw && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				$raw = '';
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

		// Freistellung muss eine der am Seminar hinterlegten Möglichkeiten sein –
		// bei einer Reihe eine, die zu allen gewählten Teilen passt.
		$frei_prueflinge = $reihe_termine ? array_values( $reihe_termine ) : $seminar_id;
		if ( '' !== ( $data['freistellung'] ?? '' )
			&& ! in_array( $data['freistellung'], self::freistellung_options( $frei_prueflinge ), true ) ) {
			$errors = true;
		}

		// Nur komplett buchbare Reihe: als Einzelanmeldung nie zulässig. Bei
		// einer Reihenanmeldung ($reihe_id) hat auswahl_pruefen die Vollzähligkeit
		// schon geprüft – dort ist $seminar_id der erste Teil und dieser Riegel
		// wäre falsch herum.
		$einzeln_gesperrt = ! $reihe_id && $seminar_id && BI_Reihen::nur_komplett( $seminar_id );

		if ( '' === bi_post( 'datenschutz' ) || ! $seminar_id || ! bi_is_seminar_post( $seminar_id )
			|| ! BI_CPT::is_bookable( $seminar_id ) || $einzeln_gesperrt ) {
			$errors = true;
		}

		if ( $errors ) {
			self::fail( 'err', $redirect, $seminar_id, $data, $reihe_id );
		}

		// Das benutzte Formular mitschreiben. Die Anmeldung bleibt damit lesbar,
		// auch wenn das Formular später umgebaut oder gelöscht wird – die
		// Detailansicht gruppiert danach.
		$data['_formular'] = $formular;

		// Doppelte Anmeldung derselben Person innerhalb weniger Minuten: kommt im
		// Alltag durch Doppelklick oder Zurück-Taste zustande. Für die anmeldende
		// Person ist das ein Erfolg – nur die zweite Mailrunde entfällt. Nebenbei
		// verteuert das jeden Wiederholungsversuch. Bei einer Reihe zählt die
		// Gruppe als Ganzes, sonst sperrte der erste Teil die übrigen aus.
		$dupe = 'bi_dupe|' . strtolower( $data['email'] ?? '' ) . '|'
			. ( $reihe_id ? 'r' . $reihe_id . ':' . $durchgang : $seminar_id );
		if ( ! bi_rate_hit( $dupe, 1, 10 * MINUTE_IN_SECONDS ) ) {
			wp_safe_redirect( add_query_arg( self::erfolg_args( $seminar_id, $reihe_id, $reihe_termine ), $redirect ) );
			exit;
		}

		// Zwei Kontingente, beide großzügig über jedem realistischen Verhalten:
		// pro Mailadresse (fängt Rotation über wechselnde Seminare ab) und pro
		// Absenderadresse (fängt Rotation über wechselnde Mailadressen ab).
		$ok_mail = bi_rate_hit( 'bi_mail|' . strtolower( $data['email'] ?? '' ), (int) apply_filters( 'bi_limit_per_email', 5 ), HOUR_IN_SECONDS );
		$ok_ip   = bi_rate_hit( 'bi_ip|' . bi_client_ip(), (int) apply_filters( 'bi_limit_per_ip', 20 ), HOUR_IN_SECONDS );
		if ( ! $ok_mail || ! $ok_ip ) {
			self::fail( 'limit', $redirect, $seminar_id, $data, $reihe_id );
		}

		// Eine Zeile je Teil; bei der Einzelanmeldung ist das genau eine.
		$termine   = $reihe_termine ? $reihe_termine : array( 0 => $seminar_id );
		$angelegt  = array();
		$sammel_id = 0;

		foreach ( $termine as $teil => $sid ) {
			$row_id = self::zeile_anlegen( (int) $sid, $data, $reihe_id, $durchgang, (int) $teil, $sammel_id );

			// Scheitert ein Insert (volle Platte, Schemaabweichung, DB-Fehler), darf
			// die Seite keinen Erfolg melden: die Person hielte sich sonst für
			// angemeldet, ohne dass es einen Datensatz gibt. Bei einer Reihe werden
			// die schon angelegten Zeilen wieder entfernt – eine halb gebuchte Reihe
			// wäre schlimmer als gar keine, weil niemand ihr ansieht, dass sie
			// unvollständig ist.
			if ( ! $row_id ) {
				self::zeilen_entfernen( $angelegt );
				// Duplikatsperre wieder lösen: Es gibt keine erste Anmeldung, gegen
				// die sie schützen müsste. Ohne diese Freigabe liefe der zweite
				// Versuch in die Sperre und bekäme einen Erfolg gemeldet, den es
				// nicht gibt.
				bi_rate_release( $dupe );
				self::fail( 'fail', $redirect, $seminar_id, $data, $reihe_id );
			}

			// Die erste Zeile ist die Klammer über alle weiteren.
			if ( $reihe_id && ! $sammel_id ) {
				$sammel_id = $row_id;
				self::sammel_id_setzen( $row_id, $row_id );
			}

			// Kampagnen-Trichter: EIN Erfolg je Anmeldevorgang, nicht einer je Teil.
			// Die Frage lautet „wie viele Menschen hat dieser Link zur Anmeldung
			// gebracht?" – eine Person ist eine Antwort, auch wenn dabei vier
			// Zeilen entstehen.
			if ( ! $angelegt ) {
				BI_Tracking::track( 'anmeldung', (int) $sid, $row_id );
			}
			$angelegt[] = $row_id;
		}

		// Der Notaus greift erst hier: gespeichert ist die Anmeldung in jedem Fall,
		// nur der automatische Versand entfällt, wenn das Stundenbudget erschöpft ist.
		if ( self::mail_budget_ok() ) {
			if ( $sammel_id ) {
				// Je Empfängeradresse eine Mail – nicht eine je Teil.
				BI_Mailer::dispatch_reihe( self::sammlung( $sammel_id ) );
			} else {
				BI_Mailer::dispatch( self::get( $angelegt[0] ) );
			}
		}

		wp_safe_redirect( add_query_arg( self::erfolg_args( $seminar_id, $reihe_id, $termine ), $redirect ) );
		exit;
	}

	/**
	 * Eine Anmeldezeile schreiben. 0 bei Fehlschlag.
	 *
	 * GS-relevante PLZ = betriebliche PLZ -> Spalte plz.
	 * Seminar-Titel/-Nummer/-Termin als Snapshot mitspeichern, damit die Anmeldung
	 * auch nach Löschen/Re-Import des Seminars lesbar bleibt.
	 */
	private static function zeile_anlegen( $seminar_id, $data, $reihe_id, $durchgang, $teil, $sammel_id ) {
		global $wpdb;
		$info = self::seminar_info( $seminar_id );

		$ok = $wpdb->insert( self::table(), array(
			'created'        => current_time( 'mysql' ),
			'seminar_id'     => $seminar_id,
			// Seminarform mitschreiben: bleibt lesbar, auch wenn der Post später verschwindet.
			'post_type'      => get_post_type( $seminar_id ),
			'seminar_titel'  => $info['title'],
			'seminar_nummer' => $info['nummer'],
			'seminar_termin' => $info['termin'],
			'reihe_id'       => (int) $reihe_id,
			'durchgang'      => (int) $durchgang,
			'teil'           => (int) $teil,
			'sammel_id'      => (int) $sammel_id,
			// Die eigenen Spalten werden aus festen Feldschlüsseln gefüllt. Fragt
			// ein Formular eines davon nicht ab, bleibt die Spalte leer statt
			// eine Warnung zu werfen – welche Folge das hat, sagt der Editor
			// beim Zusammenstellen (BI_Formulare::warnungen).
			'vorname'        => $data['vorname'] ?? '',
			'nachname'       => $data['nachname'] ?? '',
			'email'          => $data['email'] ?? '',
			'telefon'        => $data['telefon'] ?? '',
			'betrieb'        => $data['betrieb'] ?? '',
			'plz'            => $data['betrieb_plz'] ?? '',
			'nachricht'      => $data['bemerkungen'] ?? '',
			'data'           => wp_json_encode( $data ),
			'status'         => 'neu',
			// Kampagne, über die dieser Besuch hereinkam (leer, wenn ohne Kampagnen-Link).
			// Bewusst als Kopie in der Anmeldung: Die Zahl bleibt gültig, auch wenn die
			// Tracking-Ereignisse später aufgeräumt werden.
			'kampagne'       => BI_Tracking::current_slug(),
		), array(
			'%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d',
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
		) );

		if ( false === $ok || ! $wpdb->insert_id ) {
			error_log( 'BI_Registration: Anmeldung konnte nicht gespeichert werden. ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	private static function sammel_id_setzen( $row_id, $sammel_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'sammel_id' => (int) $sammel_id ), array( 'id' => (int) $row_id ), array( '%d' ), array( '%d' ) );
	}

	/** Zurücknehmen halb angelegter Sammelanmeldungen. */
	private static function zeilen_entfernen( $ids ) {
		global $wpdb;
		foreach ( (array) $ids as $id ) {
			$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
		}
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

	/** Anzeigewert eines Feldes (Radio/Select-Labels auflösen, Haken als „ja") */
	private static function display_value( $key, $f, $value ) {
		if ( 'checkbox' === ( $f['type'] ?? '' ) ) {
			return '1' === (string) $value ? 'ja' : '';
		}
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
				// Nach Ablauf der Aufbewahrungsfrist anonymisierte Zeilen kenntlich machen –
				// „(ohne Namen)" würde sonst wie ein Fehler beim Ausfüllen aussehen.
				$name = ( 'anonymisiert' === ( $r['status'] ?? '' ) )
					? '(anonymisiert)'
					: ( trim( $r['vorname'] . ' ' . $r['nachname'] ) ?: '(ohne Namen)' );
				// Gehört die Zeile zu einer Reihenanmeldung, steht das dabei: Sonst
				// sähen vier Zeilen derselben Person wie vier Einzelanmeldungen aus.
				$reihe = '';
				if ( ! empty( $r['reihe_id'] ) ) {
					$rt    = get_the_title( (int) $r['reihe_id'] );
					$reihe = '<br><span style="display:inline-block;margin-top:3px;padding:1px 6px;background:#f0f0f1;border-left:3px solid #e2001a;font-size:11px;color:#3c3c3c">'
						. esc_html( trim( 'Reihe: ' . $rt
							. ( ! empty( $r['durchgang'] ) ? ' · Gruppe ' . (int) $r['durchgang'] : '' )
							. ( ! empty( $r['teil'] ) ? ' · Teil ' . (int) $r['teil'] : '' ) ) )
						. '</span>';
				}
				echo '<tr>'
					. '<td>' . esc_html( date_i18n( 'd.m.Y H:i', strtotime( $r['created'] ) ) ) . '</td>'
					. '<td>' . esc_html( self::form_label( $r['post_type'] ?? BI_CPT ) ) . '</td>'
					. '<td>' . esc_html( $sem['titel'] )
						. ( $sub ? '<br><span style="color:#666;font-size:12px">' . esc_html( implode( ' · ', $sub ) ) . '</span>' : '' )
						. $reihe . '</td>'
					. '<td><a href="' . esc_url( $link ) . '"><strong>' . esc_html( $name ) . '</strong></a></td>'
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

		// Reihenanmeldung: die Geschwisterzeilen mit auflisten. Ohne sie sähe die
		// Anmeldung wie eine einzelne aus, obwohl sie Teil einer Abfolge ist.
		if ( ! empty( $r['sammel_id'] ) ) {
			$geschwister = self::sammlung( (int) $r['sammel_id'] );
			$zeilen      = '';
			foreach ( $geschwister as $g ) {
				$gs_sem = self::seminar_display( $g );
				$link   = add_query_arg( array( 'page' => 'bi-anmeldungen', 'view' => $g['id'] ), admin_url( 'admin.php' ) );
				$aktuell = (int) $g['id'] === (int) $id;
				$zeilen .= '<li style="margin:0 0 4px">'
					. 'Teil ' . (int) $g['teil'] . ': '
					. ( $aktuell
						? '<strong>' . esc_html( $gs_sem['titel'] ) . '</strong> (diese Anmeldung)'
						: '<a href="' . esc_url( $link ) . '">' . esc_html( $gs_sem['titel'] ) . '</a>' )
					. ' <span style="color:#666">' . esc_html( trim( $gs_sem['termin'] . ' · Nr. ' . ( $gs_sem['nummer'] ?: '—' ) ) ) . '</span>'
					. '</li>';
			}
			echo '<tr><th>Ausbildungsreihe</th><td>'
				. esc_html( get_the_title( (int) $r['reihe_id'] ) )
				. ( ! empty( $r['durchgang'] ) ? ' <span style="color:#666">(Gruppe ' . (int) $r['durchgang'] . ')</span>' : '' )
				. '<ul style="margin:8px 0 0">' . $zeilen . '</ul>'
				. '<p class="description" style="margin:6px 0 0">Diese Teile wurden in einem Zug angemeldet. '
				. 'Jeder Teil ist eine eigene Anmeldung und wird vom jeweiligen Bildungszentrum bestätigt.</p>'
				. '</td></tr>';
		}
		echo '<tr><th>Zuständige Geschäftsstelle</th><td>' . ( $gs
			? esc_html( $gs['geschaeftsstelle'] ) . ' &lt;' . esc_html( $gs['email'] ) . '&gt;'
			: '<em>keine Zuordnung für PLZ ' . esc_html( $r['plz'] ) . '</em>' ) . '</td></tr>';
		echo '<tr><th>Status</th><td>' . esc_html( $r['status'] ) . '</td></tr>';
		echo '<tr><th>Kampagne</th><td>' . ( ! empty( $r['kampagne'] )
			? esc_html( $r['kampagne'] )
			: '<span style="color:#999">— direkt, ohne Kampagnen-Link</span>' ) . '</td></tr>';
		echo '</tbody></table>';

		// Felder nach den Seiten des Formulars gruppiert, mit dem diese Anmeldung
		// eingegangen ist. Gibt es das Formular nicht mehr, greift das
		// Standardformular – und alles, was dann keine Zeile bekäme, steht
		// darunter unter „Weitere Angaben". Ein gespeicherter Wert verschwindet
		// nicht aus der Ansicht, nur weil jemand später ein Formular umgebaut hat.
		$benutzt = (string) ( $data['_formular'] ?? '' );
		$gezeigt = array();

		foreach ( self::form_steps( $benutzt ) as $step ) {
			echo '<h2>' . esc_html( $step['title'] ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:780px;margin-bottom:18px"><tbody>';
			foreach ( $step['fields'] as $key => $f ) {
				$gezeigt[ $key ] = true;
				$val = self::display_value( $key, $f, (string) ( $data[ $key ] ?? '' ) );
				echo '<tr><th style="width:220px">' . esc_html( $f['label'] ) . '</th><td>' . ( '' !== trim( $val ) ? nl2br( esc_html( $val ) ) : '<span style="color:#999">—</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$rest = array();
		foreach ( self::bestand_fields() as $key => $f ) {
			if ( isset( $gezeigt[ $key ] ) || '' === trim( (string) ( $data[ $key ] ?? '' ) ) ) {
				continue;
			}
			$rest[ $key ] = $f;
		}
		if ( $rest ) {
			echo '<h2>Weitere Angaben</h2>';
			echo '<p class="description" style="max-width:780px">Diese Werte stehen in der Anmeldung, gehören aber nicht '
				. 'zu den Seiten des benutzten Formulars – meist, weil das Formular seither geändert wurde.</p>';
			echo '<table class="widefat striped" style="max-width:780px;margin-bottom:18px"><tbody>';
			foreach ( $rest as $key => $f ) {
				$val = self::display_value( $key, $f, (string) $data[ $key ] );
				echo '<tr><th style="width:220px">' . esc_html( $f['label'] ) . '</th><td>' . nl2br( esc_html( $val ) ) . '</td></tr>';
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
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_export_anmeldungen' );

		$search = sanitize_text_field( bi_get( 's' ) );
		$form   = sanitize_key( bi_get( 'form' ) );
		$form   = in_array( $form, bi_seminar_post_types(), true ) ? $form : '';
		$rows = self::fetch( $search, 'created', 'desc', 100000, 0, $form );

		// Der GANZE Feld-Bestand, nicht die Felder eines Formulars: Die Spalten
		// sollen gleich bleiben, egal welches Formular gerade Standard ist und
		// aus welchem Formular die einzelne Zeile stammt.
		$fields = self::bestand_fields();

		// „Sammel-ID" macht die Zeilen einer Reihenanmeldung in der Tabelle wieder
		// zusammenführbar – sortiert man danach, stehen die Teile beieinander.
		$header = array( 'ID', 'Sammel-ID', 'Eingegangen', 'Seminarform', 'Seminar', 'Seminarnummer', 'Termin',
			'Ausbildungsreihe', 'Gruppe', 'Teil', 'Geschäftsstelle', 'GS-E-Mail', 'Formular' );
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
				! empty( $r['sammel_id'] ) ? $r['sammel_id'] : '',
				$r['created'],
				self::form_label( $r['post_type'] ?? BI_CPT ),
				$sem['titel'],
				$sem['nummer'],
				$sem['termin'],
				! empty( $r['reihe_id'] ) ? get_the_title( (int) $r['reihe_id'] ) : '',
				! empty( $r['durchgang'] ) ? 'Reihe ' . (int) $r['durchgang'] : '',
				! empty( $r['teil'] ) ? (int) $r['teil'] : '',
				$gs ? $gs['geschaeftsstelle'] : '',
				$gs ? $gs['email'] : '',
				// Aus welchem Formular diese Zeile stammt – sonst ließe sich eine
				// leere Spalte nicht von einer nie gestellten Frage unterscheiden.
				BI_Formulare::choices()[ $data['_formular'] ?? '' ] ?? (string) ( $data['_formular'] ?? '' ),
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
