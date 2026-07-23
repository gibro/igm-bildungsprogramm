<?php
/**
 * Kampagnen-Tracking: Wirkung von Newsletter-/Mailing-Links messbar machen.
 *
 * Idee: Statt den Newsletter direkt auf die Seminarsuche zu verlinken, verlinkt er auf
 * einen Kampagnen-Link  https://…/?bi_k=<slug> . Dieser Aufruf wird protokolliert und
 * leitet auf das eigentliche Ziel weiter (Seminar-Detailseite oder vorgefilterte Suche).
 * Ab da wird der Weg desselben Besuchs mitgeschrieben:
 *
 *   klick     → Kampagnen-Link aufgerufen
 *   seminar   → eine Seminar-Detailseite angesehen
 *   formular  → Anmeldeformular geöffnet („Jetzt buchen" gedrückt)
 *   anmeldung → Anmeldung abgeschickt
 *
 * Zuordnung über ein Cookie (bi_track = "<kampagne-id>.<zufalls-token>", 30 Tage).
 * Gespeichert wird nur dieser Zufalls-Token – keine IP, kein User-Agent, kein Name.
 * Die Anmeldung selbst merkt sich zusätzlich den Kampagnen-Slug in der Spalte
 * `kampagne` von wp_bi_anmeldungen: Die Zahl „echte Anmeldungen je Kampagne" bleibt
 * dadurch auch dann gültig, wenn Ereignisse später aufgeräumt werden.
 *
 * Tabellen: wp_bi_kampagnen (Definitionen), wp_bi_events (Ereignisse).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Tracking {

	const COOKIE      = 'bi_track';
	const COOKIE_DAYS = 30;
	const PARAM       = 'bi_k';
	const KEEP_DAYS   = 365; // Aufbewahrung der Ereignisse; danach automatisch gelöscht
	const CRON_HOOK   = 'bi_tracking_cleanup';

	/** Schritte des Trichters in der Reihenfolge, in der sie durchlaufen werden */
	public static function steps() {
		return array(
			'klick'     => 'Link aufgerufen',
			'seminar'   => 'Seminar angesehen',
			'formular'  => 'Anmeldung begonnen',
			'anmeldung' => 'Anmeldung abgeschickt',
		);
	}

	public static function init() {
		// Priorität 20: erst nachdem BI_CPT den Post-Type registriert hat (init/10) –
		// vorher liefert get_permalink() für ein Seminar noch keine saubere Adresse.
		add_action( 'init', array( __CLASS__, 'maybe_handle_link' ), 20 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_track_seminar' ) );
		add_action( 'admin_post_bi_save_kampagne', array( __CLASS__, 'save_kampagne' ) );
		add_action( 'admin_post_bi_delete_kampagne', array( __CLASS__, 'delete_kampagne' ) );
		add_action( 'admin_post_bi_prune_events', array( __CLASS__, 'prune_events' ) );

		// Alte Ereignisse täglich wegräumen. Die Aufbewahrungsfrist steht so in der
		// Datenschutzerklärung – sie darf nicht davon abhängen, dass jemand im
		// Backend auf einen Knopf drückt.
		add_action( self::CRON_HOOK, array( __CLASS__, 'prune_old' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_cron' ) );
	}

	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function clear_cron() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Ereignisse löschen, die älter als die Aufbewahrungsfrist sind.
	 *
	 * @return int Anzahl gelöschter Zeilen.
	 */
	public static function prune_old() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::KEEP_DAYS * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table_events() . ' WHERE created < %s',
			$cut
		) );
	}

	public static function table_kampagnen() {
		return bi_table( 'kampagnen' );
	}

	public static function table_events() {
		return bi_table( 'events' );
	}

	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$k = self::table_kampagnen();
		$e = self::table_events();

		dbDelta( "CREATE TABLE $k (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created DATETIME NOT NULL,
			slug VARCHAR(64) NOT NULL DEFAULT '',
			name VARCHAR(190) NOT NULL DEFAULT '',
			quelle VARCHAR(190) NOT NULL DEFAULT '',
			ziel_post BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ziel_url VARCHAR(500) NOT NULL DEFAULT '',
			notiz TEXT NULL,
			aktiv TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) $charset;" );

		dbDelta( "CREATE TABLE $e (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created DATETIME NOT NULL,
			kampagne_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			token VARCHAR(32) NOT NULL DEFAULT '',
			typ VARCHAR(20) NOT NULL DEFAULT '',
			seminar_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			anmeldung_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY kampagne_id (kampagne_id),
			KEY token (token),
			KEY typ (typ),
			KEY created (created)
		) $charset;" );
	}

	/** ---------- Kampagnen lesen/schreiben ---------- */

	public static function get_kampagnen( $only_active = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . self::table_kampagnen();
		if ( $only_active ) {
			$sql .= ' WHERE aktiv = 1';
		}
		$sql .= ' ORDER BY created DESC, id DESC';
		return $wpdb->get_results( $sql, ARRAY_A ) ?: array();
	}

	public static function get_kampagne( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table_kampagnen() . ' WHERE id = %d',
			(int) $id
		), ARRAY_A );
	}

	private static function get_by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . self::table_kampagnen() . ' WHERE slug = %s',
			$slug
		), ARRAY_A );
	}

	/** Der Link, der in den Newsletter kommt */
	public static function link( $kampagne ) {
		return add_query_arg( self::PARAM, $kampagne['slug'], home_url( '/' ) );
	}

	/**
	 * Weiterleitungsziel. Ein hinterlegtes Seminar hat Vorrang und wird jedes Mal frisch
	 * aufgelöst – so bleibt der Link gültig, wenn sich der Permalink später ändert.
	 */
	public static function target_url( $kampagne ) {
		$pid = (int) ( $kampagne['ziel_post'] ?? 0 );
		if ( $pid && 'publish' === get_post_status( $pid ) ) {
			$url = get_permalink( $pid );
			if ( $url ) {
				return $url;
			}
		}
		if ( ! empty( $kampagne['ziel_url'] ) ) {
			return $kampagne['ziel_url'];
		}
		return BI_Registration::uebersicht_url();
	}

	/** ---------- Erfassung ---------- */

	/** Kampagnen-Link: protokollieren, Cookie setzen, weiterleiten. */
	public static function maybe_handle_link() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || empty( $_GET[ self::PARAM ] ) ) {
			return;
		}

		$slug     = sanitize_title( wp_unslash( $_GET[ self::PARAM ] ) );
		$kampagne = $slug ? self::get_by_slug( $slug ) : null;
		$target   = $kampagne ? self::target_url( $kampagne ) : BI_Registration::uebersicht_url();
		// Ziel darf den Kampagnen-Parameter nicht erneut enthalten – sonst Endlosschleife.
		$target   = remove_query_arg( self::PARAM, $target );

		// Zusätzliche Parameter am Kampagnen-Link mitnehmen – so lässt sich ein Link
		// im Newsletter noch nachschärfen (z. B. &programm=2026), ohne neue Kampagne.
		$extra = array();
		foreach ( (array) $_GET as $key => $value ) {
			if ( self::PARAM === $key || ! is_scalar( $value ) ) {
				continue;
			}
			$extra[ sanitize_key( $key ) ] = sanitize_text_field( wp_unslash( $value ) );
		}
		if ( $extra ) {
			$target = add_query_arg( $extra, $target );
		}

		if ( $kampagne && ! empty( $kampagne['aktiv'] ) && ! self::is_bot() ) {
			$token = wp_generate_password( 24, false, false );
			self::set_cookie( (int) $kampagne['id'], $token );
			self::log( (int) $kampagne['id'], $token, 'klick', (int) $kampagne['ziel_post'] );
		}

		// Ziele außerhalb der eigenen Domain werden nicht bedient (kein offener Redirect);
		// stattdessen landet der Besuch auf der Seminarübersicht statt im wp-admin.
		wp_redirect( wp_validate_redirect( $target, BI_Registration::uebersicht_url() ), 302 );
		exit;
	}

	/** Seminar-Detailseite angesehen */
	public static function maybe_track_seminar() {
		if ( is_singular( BI_CPT ) ) {
			self::track( 'seminar', get_queried_object_id() );
		}
	}

	/**
	 * Ereignis im laufenden Kampagnen-Pfad festhalten.
	 *
	 * @param string $typ         klick|seminar|formular|anmeldung
	 * @param int    $seminar_id  Betroffenes Seminar (0 = keins).
	 * @param int    $anmeldung_id Zeile in wp_bi_anmeldungen (nur bei typ=anmeldung).
	 */
	public static function track( $typ, $seminar_id = 0, $anmeldung_id = 0 ) {
		$cur = self::current();
		if ( ! $cur || self::is_bot() ) {
			return;
		}
		// „seminar" und „formular" nur einmal je Pfad und Seminar zählen – sonst würde
		// jedes Neuladen der Seite den Trichter aufblähen. Anmeldungen zählen immer.
		if ( 'anmeldung' !== $typ && self::has_event( $cur['id'], $cur['token'], $typ, $seminar_id ) ) {
			return;
		}
		self::log( $cur['id'], $cur['token'], $typ, $seminar_id, $anmeldung_id );
	}

	/** Slug der Kampagne, aus der der aktuelle Besuch stammt ('' = keine) */
	public static function current_slug() {
		$cur = self::current();
		if ( ! $cur ) {
			return '';
		}
		$kampagne = self::get_kampagne( $cur['id'] );
		return $kampagne ? $kampagne['slug'] : '';
	}

	private static function log( $kampagne_id, $token, $typ, $seminar_id = 0, $anmeldung_id = 0 ) {
		global $wpdb;
		$wpdb->insert( self::table_events(), array(
			'created'      => current_time( 'mysql' ),
			'kampagne_id'  => (int) $kampagne_id,
			'token'        => $token,
			'typ'          => $typ,
			'seminar_id'   => (int) $seminar_id,
			'anmeldung_id' => (int) $anmeldung_id,
		), array( '%s', '%d', '%s', '%s', '%d', '%d' ) );
	}

	private static function has_event( $kampagne_id, $token, $typ, $seminar_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table_events() . ' WHERE kampagne_id = %d AND token = %s AND typ = %s AND seminar_id = %d LIMIT 1',
			(int) $kampagne_id,
			$token,
			$typ,
			(int) $seminar_id
		) );
	}

	/** Aktueller Pfad aus dem Cookie: ['id' => Kampagne, 'token' => Pfad] oder null */
	private static function current() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		if ( ! preg_match( '/^(\d+)\.([A-Za-z0-9]{8,32})$/', $raw, $m ) ) {
			return null;
		}
		return array( 'id' => (int) $m[1], 'token' => $m[2] );
	}

	private static function set_cookie( $kampagne_id, $token ) {
		$value  = $kampagne_id . '.' . $token;
		$expire = time() + self::COOKIE_DAYS * DAY_IN_SECONDS;

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		}
		// Damit spätere Hooks im selben Request den Pfad schon kennen.
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * Grobe Bot-Erkennung. Wichtig vor allem wegen der Link-Scanner in
	 * Mail-Programmen und Firewalls, die jeden Newsletter-Link einmal aufrufen.
	 * Perfekt ist das nicht – Klickzahlen bleiben eine Näherung.
	 */
	private static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		foreach ( array( 'bot', 'crawl', 'spider', 'slurp', 'preview', 'fetch', 'monitor', 'scan', 'curl', 'wget', 'python', 'java/', 'headless', 'lighthouse', 'pingdom', 'uptime', 'validator' ) as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** ---------- Auswertung ---------- */

	/** Zeitfenster-Auswahl der Auswertung */
	public static function ranges() {
		return array(
			'30'  => 'letzte 30 Tage',
			'90'  => 'letzte 90 Tage',
			'365' => 'letzte 12 Monate',
			'0'   => 'gesamter Zeitraum',
		);
	}

	private static function range_start( $days ) {
		$days = (int) $days;
		if ( $days <= 0 ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $days * DAY_IN_SECONDS );
	}

	/**
	 * Trichter-Zahlen je Kampagne.
	 *
	 * Die ersten drei Schritte kommen aus den Ereignissen, die Anmeldungen dagegen aus
	 * der Anmeldetabelle selbst: Das ist die Zahl, auf die es ankommt, und sie stimmt
	 * auch dann noch, wenn alte Ereignisse gelöscht wurden.
	 *
	 * @return array [kampagne_id => ['klick'=>n,'seminar'=>n,'formular'=>n,'anmeldung'=>n]]
	 */
	public static function stats( $days = 0 ) {
		global $wpdb;
		$since = self::range_start( $days );
		$where = $since ? $wpdb->prepare( 'WHERE created >= %s', $since ) : '';

		$rows = $wpdb->get_results(
			"SELECT kampagne_id,
				COUNT(DISTINCT CASE WHEN typ = 'klick'     THEN token END) AS klick,
				COUNT(DISTINCT CASE WHEN typ = 'seminar'   THEN token END) AS seminar,
				COUNT(DISTINCT CASE WHEN typ = 'formular'  THEN token END) AS formular
			FROM " . self::table_events() . " $where GROUP BY kampagne_id",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['kampagne_id'] ] = array(
				'klick'     => (int) $r['klick'],
				'seminar'   => (int) $r['seminar'],
				'formular'  => (int) $r['formular'],
				'anmeldung' => 0,
			);
		}

		// Anmeldungen je Kampagnen-Slug -> auf die Kampagnen-ID abbilden
		$sql  = 'SELECT kampagne, COUNT(*) AS anzahl FROM ' . BI_Registration::table() . " WHERE kampagne <> ''";
		if ( $since ) {
			$sql = $wpdb->prepare( $sql . ' AND created >= %s', $since );
		}
		$anm = $wpdb->get_results( $sql . ' GROUP BY kampagne', ARRAY_A );

		$by_slug = array();
		foreach ( self::get_kampagnen() as $k ) {
			$by_slug[ $k['slug'] ] = (int) $k['id'];
		}
		foreach ( (array) $anm as $r ) {
			$id = $by_slug[ $r['kampagne'] ] ?? 0;
			if ( ! $id ) {
				continue;
			}
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array( 'klick' => 0, 'seminar' => 0, 'formular' => 0, 'anmeldung' => 0 );
			}
			$out[ $id ]['anmeldung'] = (int) $r['anzahl'];
		}
		return $out;
	}

	/** Anmeldungen einer Kampagne (aus der Anmeldetabelle, nicht aus den Ereignissen) */
	public static function anmeldungen( $slug, $days = 0 ) {
		global $wpdb;
		$since = self::range_start( $days );
		$sql   = 'SELECT id, created, vorname, nachname, seminar_id, seminar_titel, seminar_nummer FROM '
			. BI_Registration::table() . ' WHERE kampagne = %s';
		$args  = array( $slug );
		if ( $since ) {
			$sql   .= ' AND created >= %s';
			$args[] = $since;
		}
		$sql .= ' ORDER BY created DESC, id DESC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	/** Meistgesehene Seminare einer Kampagne */
	public static function top_seminare( $kampagne_id, $days = 0, $limit = 10 ) {
		global $wpdb;
		$since = self::range_start( $days );
		$sql   = "SELECT seminar_id,
				COUNT(DISTINCT CASE WHEN typ = 'seminar' THEN token END) AS ansichten,
				SUM(CASE WHEN typ = 'anmeldung' THEN 1 ELSE 0 END)       AS anmeldungen
			FROM " . self::table_events() . ' WHERE kampagne_id = %d AND seminar_id > 0';
		$args  = array( (int) $kampagne_id );
		if ( $since ) {
			$sql   .= ' AND created >= %s';
			$args[] = $since;
		}
		$sql   .= ' GROUP BY seminar_id ORDER BY anmeldungen DESC, ansichten DESC LIMIT %d';
		$args[] = (int) $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	/**
	 * Die letzten Pfade einer Kampagne: je Besuch (Token) alle Ereignisse in
	 * zeitlicher Reihenfolge – damit lässt sich ein einzelner Weg nachvollziehen.
	 */
	public static function paths( $kampagne_id, $limit = 20 ) {
		global $wpdb;
		$tokens = $wpdb->get_col( $wpdb->prepare(
			'SELECT token FROM ' . self::table_events() . ' WHERE kampagne_id = %d GROUP BY token ORDER BY MAX(created) DESC LIMIT %d',
			(int) $kampagne_id,
			(int) $limit
		) );
		if ( ! $tokens ) {
			return array();
		}

		$in   = implode( ',', array_fill( 0, count( $tokens ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT token, typ, seminar_id, created FROM ' . self::table_events()
			. " WHERE kampagne_id = %d AND token IN ($in) ORDER BY created ASC, id ASC",
			array_merge( array( (int) $kampagne_id ), $tokens )
		), ARRAY_A );

		$paths = array();
		foreach ( (array) $rows as $r ) {
			$paths[ $r['token'] ][] = $r;
		}
		// Reihenfolge der Token-Abfrage (neueste zuerst) beibehalten
		$sorted = array();
		foreach ( $tokens as $t ) {
			if ( isset( $paths[ $t ] ) ) {
				$sorted[ $t ] = $paths[ $t ];
			}
		}
		return $sorted;
	}

	/** ---------- Admin ---------- */

	public static function render_page() {
		$view = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0;
		if ( $view ) {
			self::render_detail( $view );
			return;
		}
		self::render_list();
	}

	private static function current_range() {
		$r = isset( $_GET['range'] ) ? (string) (int) $_GET['range'] : '90';
		return array_key_exists( $r, self::ranges() ) ? $r : '90';
	}

	private static function range_form( $extra = array() ) {
		$range = self::current_range();
		ob_start();
		echo '<form method="get" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="bi-kampagnen">';
		foreach ( $extra as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<label>Zeitraum: <select name="range" onchange="this.form.submit()">';
		foreach ( self::ranges() as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $range, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> <button class="button">Anzeigen</button>';
		echo '</form>';
		return ob_get_clean();
	}

	private static function render_list() {
		$notice    = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		$range     = self::current_range();
		$kampagnen = self::get_kampagnen();
		$stats     = self::stats( $range );
		$edit      = isset( $_GET['edit'] ) ? self::get_kampagne( (int) $_GET['edit'] ) : null;
		?>
		<div class="wrap">
			<h1>Kampagnen</h1>
			<?php if ( $notice ) : ?><div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>

			<p>Für jeden Newsletter, jedes Mailing oder jede Anzeige eine Kampagne anlegen und den erzeugten
				Link statt der normalen Adresse verwenden. Der Link leitet auf das eingestellte Ziel weiter und
				protokolliert dabei den weiteren Weg: <strong>Link aufgerufen → Seminar angesehen →
				Anmeldung begonnen → Anmeldung abgeschickt</strong>.</p>

			<?php echo self::range_form(); // phpcs:ignore – intern escaped ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>Kampagne</th>
						<th>Link</th>
						<th style="width:90px">Klicks</th>
						<th style="width:120px">Seminar angesehen</th>
						<th style="width:120px">Anmeldung begonnen</th>
						<th style="width:110px">Anmeldungen</th>
						<th style="width:90px">Quote</th>
						<th style="width:120px"></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $kampagnen ) : ?>
					<tr><td colspan="8">Noch keine Kampagne angelegt.</td></tr>
				<?php endif; ?>
				<?php foreach ( $kampagnen as $k ) :
					$s     = $stats[ (int) $k['id'] ] ?? array( 'klick' => 0, 'seminar' => 0, 'formular' => 0, 'anmeldung' => 0 );
					$quote = $s['klick'] ? round( $s['anmeldung'] / $s['klick'] * 100, 1 ) : 0;
					$link  = self::link( $k );
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-kampagnen&view=' . (int) $k['id'] . '&range=' . $range ) ); ?>"><?php echo esc_html( $k['name'] ); ?></a></strong>
							<?php if ( empty( $k['aktiv'] ) ) : ?>
								<span style="background:#f0f0f1;color:#646970;padding:1px 8px;border-radius:10px;font-size:12px">inaktiv</span>
							<?php endif; ?>
							<?php if ( $k['quelle'] ) : ?><div style="color:#646970;font-size:12px"><?php echo esc_html( $k['quelle'] ); ?></div><?php endif; ?>
						</td>
						<td><input type="text" readonly value="<?php echo esc_attr( $link ); ?>" style="width:100%;font-size:12px" onclick="this.select()"></td>
						<td><?php echo (int) $s['klick']; ?></td>
						<td><?php echo (int) $s['seminar']; ?></td>
						<td><?php echo (int) $s['formular']; ?></td>
						<td><strong><?php echo (int) $s['anmeldung']; ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $quote, 1 ) ); ?> %</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-kampagnen&edit=' . (int) $k['id'] . '&range=' . $range ) ); ?>">Bearbeiten</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="margin-top:8px">„Klicks" zählt Besuche, nicht Aufrufe: Mehrfaches Neuladen
				derselben Seite wird nicht doppelt gezählt, ein zweiter Klick auf den Newsletter-Link später schon.
				Link-Scanner von Mailprogrammen werden nach Kennung aussortiert – exakt ist das nicht, die Klickzahl
				bleibt eine Näherung. Die Spalte <strong>Anmeldungen</strong> stammt dagegen aus den echten
				Anmeldedatensätzen.</p>

			<?php echo self::form_box( $edit ); // phpcs:ignore – intern escaped ?>
			<?php echo self::privacy_box(); // phpcs:ignore – intern escaped ?>
		</div>
		<?php
	}

	private static function form_box( $edit = null ) {
		$is_edit = is_array( $edit );
		$k       = wp_parse_args( $is_edit ? $edit : array(), array(
			'id' => 0, 'name' => '', 'slug' => '', 'quelle' => '', 'ziel_post' => 0, 'ziel_url' => '', 'notiz' => '', 'aktiv' => 1,
		) );

		$seminare = get_posts( array(
			'post_type'   => BI_CPT,
			'numberposts' => 200,
			'post_status' => 'publish',
			'orderby'     => 'meta_value',
			'meta_key'    => '_bi_startdatum',
			'order'       => 'ASC',
			'meta_query'  => array(
				array( 'key' => '_bi_startdatum', 'value' => current_time( 'Y-m-d' ), 'compare' => '>=', 'type' => 'DATE' ),
			),
		) );

		ob_start();
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;padding:14px 18px;margin:20px 0;max-width:900px">
			<h2 style="margin-top:0"><?php echo $is_edit ? 'Kampagne bearbeiten' : 'Neue Kampagne'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_save_kampagne">
				<input type="hidden" name="id" value="<?php echo (int) $k['id']; ?>">
				<?php wp_nonce_field( 'bi_save_kampagne' ); ?>

				<table class="form-table">
					<tr>
						<th><label for="bi_k_name">Bezeichnung</label></th>
						<td><input type="text" id="bi_k_name" class="regular-text" name="name" value="<?php echo esc_attr( $k['name'] ); ?>" placeholder="z. B. Newsletter Juli 2026" required></td>
					</tr>
					<tr>
						<th><label for="bi_k_quelle">Quelle / Kanal</label></th>
						<td><input type="text" id="bi_k_quelle" class="regular-text" name="quelle" value="<?php echo esc_attr( $k['quelle'] ); ?>" placeholder="z. B. Mitglieder-Newsletter, Betriebsräte-Mailing">
							<p class="description">Nur zur Einordnung in der Liste – hat keine Wirkung auf den Link.</p></td>
					</tr>
					<tr>
						<th><label for="bi_k_slug">Kürzel (Link)</label></th>
						<td><input type="text" id="bi_k_slug" class="regular-text" name="slug" value="<?php echo esc_attr( $k['slug'] ); ?>" placeholder="wird aus der Bezeichnung erzeugt">
							<p class="description">Taucht im Link auf: <code><?php echo esc_html( home_url( '/?' . self::PARAM . '=' ) ); ?>…</code>
								Bei einer bestehenden Kampagne besser <strong>nicht</strong> ändern – bereits versendete Links laufen sonst ins Leere.</p></td>
					</tr>
					<tr>
						<th><label for="bi_k_post">Ziel: Seminar</label></th>
						<td>
							<select id="bi_k_post" name="ziel_post">
								<option value="0">— kein festes Seminar —</option>
								<?php foreach ( $seminare as $p ) :
									$d = get_post_meta( $p->ID, '_bi_startdatum', true ); ?>
									<option value="<?php echo (int) $p->ID; ?>" <?php selected( (int) $k['ziel_post'], (int) $p->ID ); ?>>
										<?php echo esc_html( get_the_title( $p ) . ( $d ? ' (' . date_i18n( 'd.m.Y', strtotime( $d ) ) . ')' : '' ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">Führt direkt auf die Detailseite dieses Seminars. Der Permalink wird bei jedem
								Aufruf frisch aufgelöst – ändert sich später der Titel, bleibt der Newsletter-Link gültig.</p>
						</td>
					</tr>
					<tr>
						<th><label for="bi_k_url">Ziel: freie Adresse</label></th>
						<td><input type="url" id="bi_k_url" class="large-text" name="ziel_url" value="<?php echo esc_attr( $k['ziel_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/seminare/?handlungsfeld=digitalisierung' ) ); ?>">
							<p class="description">Wird nur genutzt, wenn oben kein Seminar gewählt ist – z. B. die Seminarsuche mit
								gesetzten Filtern. Am einfachsten: Suche im Frontend wie gewünscht filtern und die Adresse aus der
								Adresszeile kopieren. Muss auf dieser Website liegen; ohne Angabe geht es zur Seminarübersicht.</p></td>
					</tr>
					<tr>
						<th><label for="bi_k_notiz">Notiz</label></th>
						<td><textarea id="bi_k_notiz" class="large-text" rows="2" name="notiz"><?php echo esc_textarea( $k['notiz'] ); ?></textarea></td>
					</tr>
					<tr>
						<th>Aktiv</th>
						<td><label><input type="checkbox" name="aktiv" value="1" <?php checked( ! empty( $k['aktiv'] ) ); ?>> Klicks werden gezählt</label>
							<p class="description">Inaktive Kampagnen leiten weiterhin weiter, zählen aber nicht mehr mit.</p></td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary"><?php echo $is_edit ? 'Änderungen speichern' : 'Kampagne anlegen'; ?></button>
					<?php if ( $is_edit ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=bi-kampagnen' ) ); ?>">Abbrechen</a>
					<?php endif; ?>
				</p>
			</form>

			<?php if ( $is_edit ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
					onsubmit="return confirm('Kampagne und alle zugehörigen Ereignisse wirklich löschen? Die Anmeldungen selbst bleiben erhalten.');">
					<input type="hidden" name="action" value="bi_delete_kampagne">
					<input type="hidden" name="id" value="<?php echo (int) $k['id']; ?>">
					<?php wp_nonce_field( 'bi_delete_kampagne' ); ?>
					<button type="submit" class="button-link-delete" style="background:none;border:0;color:#b32d2e;cursor:pointer;padding:0">Kampagne löschen</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function privacy_box() {
		global $wpdb;
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_events() );
		ob_start();
		?>
		<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin:16px 0;max-width:900px">
			<h2 style="margin-top:0;font-size:14px">Datenschutz &amp; Aufräumen</h2>
			<p style="margin:0 0 8px">Gespeichert wird je Besuch nur eine Zufallskennung im First-Party-Cookie
				<code><?php echo esc_html( self::COOKIE ); ?></code> (<?php echo (int) self::COOKIE_DAYS; ?> Tage Laufzeit) –
				keine IP-Adresse, kein Gerät, kein Name. Die Kennung lässt sich keiner Person zuordnen; erst die
				abgeschickte Anmeldung enthält Personendaten und speichert dazu den Kampagnen-Namen.
				Derzeit <strong><?php echo (int) $total; ?></strong> Ereignisse gespeichert.</p>
			<p style="margin:0 0 8px">Ereignisse älter als <?php echo (int) self::KEEP_DAYS; ?> Tage werden
				<strong>täglich automatisch gelöscht</strong>; die Anmeldezahlen der Kampagnen bleiben davon
				unberührt. Der Knopf stößt dasselbe sofort an.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				onsubmit="return confirm('Ereignisse älter als 12 Monate jetzt löschen? Die Anmeldezahlen der Kampagnen bleiben erhalten.');">
				<input type="hidden" name="action" value="bi_prune_events">
				<?php wp_nonce_field( 'bi_prune_events' ); ?>
				<button type="submit" class="button">Jetzt aufräumen</button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_detail( $id ) {
		$k = self::get_kampagne( $id );
		if ( ! $k ) {
			echo '<div class="wrap"><h1>Kampagne</h1><div class="notice notice-error"><p>Kampagne nicht gefunden.</p></div></div>';
			return;
		}

		$range = self::current_range();
		$stats = self::stats( $range );
		$s     = $stats[ (int) $k['id'] ] ?? array( 'klick' => 0, 'seminar' => 0, 'formular' => 0, 'anmeldung' => 0 );
		$anm   = self::anmeldungen( $k['slug'], $range );
		$tops  = self::top_seminare( $k['id'], $range );
		$paths = self::paths( $k['id'] );
		$steps = self::steps();
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $k['name'] ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-kampagnen&range=' . $range ) ); ?>">← Alle Kampagnen</a> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-kampagnen&edit=' . (int) $k['id'] ) ); ?>">Bearbeiten</a>
			</p>

			<table class="widefat" style="max-width:900px;margin-bottom:16px"><tbody>
				<tr><th style="width:180px">Link für den Newsletter</th>
					<td><input type="text" readonly value="<?php echo esc_attr( self::link( $k ) ); ?>" style="width:100%" onclick="this.select()"></td></tr>
				<tr><th>Leitet weiter auf</th><td><a href="<?php echo esc_url( self::target_url( $k ) ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( self::target_url( $k ) ); ?></a></td></tr>
				<?php if ( $k['quelle'] ) : ?><tr><th>Quelle / Kanal</th><td><?php echo esc_html( $k['quelle'] ); ?></td></tr><?php endif; ?>
				<?php if ( $k['notiz'] ) : ?><tr><th>Notiz</th><td><?php echo nl2br( esc_html( $k['notiz'] ) ); ?></td></tr><?php endif; ?>
				<tr><th>Angelegt</th><td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $k['created'] ) ) ); ?></td></tr>
			</tbody></table>

			<?php echo self::range_form( array( 'view' => (int) $k['id'] ) ); // phpcs:ignore – intern escaped ?>

			<h2>Weg vom Link zur Anmeldung</h2>
			<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 18px;max-width:900px">
				<?php
				$base = max( 1, (int) $s['klick'] );
				$prev = null;
				foreach ( $steps as $key => $label ) :
					$val   = (int) $s[ $key ];
					$width = min( 100, round( $val / $base * 100 ) );
					$drop  = ( null !== $prev && $prev > 0 ) ? round( ( 1 - $val / $prev ) * 100 ) : null;
					?>
					<div style="margin-bottom:12px">
						<div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:3px">
							<span><strong><?php echo esc_html( $label ); ?></strong></span>
							<span>
								<strong><?php echo (int) $val; ?></strong>
								<?php if ( null !== $drop ) : ?>
									<span style="color:#646970">— <?php echo (int) $drop; ?> % Absprung gegenüber dem Schritt davor</span>
								<?php endif; ?>
							</span>
						</div>
						<div style="background:#f0f0f1;height:22px;border-radius:3px;overflow:hidden">
							<div style="background:#2271b1;height:22px;width:<?php echo (int) $width; ?>%"></div>
						</div>
					</div>
					<?php
					$prev = $val;
				endforeach;
				?>
				<p style="margin:10px 0 0;color:#646970;font-size:13px">
					Aus <strong><?php echo (int) $s['klick']; ?></strong> Klicks wurden
					<strong><?php echo (int) $s['anmeldung']; ?></strong> Anmeldungen
					(<?php echo esc_html( number_format_i18n( $s['klick'] ? round( $s['anmeldung'] / $s['klick'] * 100, 1 ) : 0, 1 ) ); ?> %).
				</p>
			</div>

			<h2>Anmeldungen aus dieser Kampagne</h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th style="width:130px">Eingegangen</th><th>Name</th><th>Seminar</th><th style="width:90px"></th></tr></thead>
				<tbody>
				<?php if ( ! $anm ) : ?>
					<tr><td colspan="4">Im gewählten Zeitraum noch keine Anmeldung über diesen Link.</td></tr>
				<?php endif; ?>
				<?php foreach ( $anm as $a ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $a['created'] ) ) ); ?></td>
						<td><?php echo esc_html( trim( $a['vorname'] . ' ' . $a['nachname'] ) ); ?></td>
						<td><?php echo esc_html( $a['seminar_titel'] ?: ( $a['seminar_id'] ? get_the_title( (int) $a['seminar_id'] ) : '—' ) ); ?>
							<?php if ( $a['seminar_nummer'] ) : ?><span style="color:#646970">(<?php echo esc_html( $a['seminar_nummer'] ); ?>)</span><?php endif; ?></td>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=bi-anmeldungen&view=' . (int) $a['id'] ) ); ?>">Ansehen</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Seminare in dieser Kampagne</h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Seminar</th><th style="width:140px">angesehen</th><th style="width:140px">Anmeldungen</th></tr></thead>
				<tbody>
				<?php if ( ! $tops ) : ?>
					<tr><td colspan="3">Noch keine Seminaraufrufe erfasst.</td></tr>
				<?php endif; ?>
				<?php foreach ( $tops as $t ) :
					$titel = get_the_title( (int) $t['seminar_id'] );
					$edit  = get_edit_post_link( (int) $t['seminar_id'] ); ?>
					<tr>
						<td><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $titel ) . '</a>' : esc_html( $titel ?: '#' . (int) $t['seminar_id'] ); ?></td>
						<td><?php echo (int) $t['ansichten']; ?></td>
						<td><?php echo (int) $t['anmeldungen']; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Einzelne Wege (letzte 20 Besuche)</h2>
			<details style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;max-width:900px">
				<summary style="cursor:pointer;font-weight:600">Aufklappen</summary>
				<?php if ( ! $paths ) : ?>
					<p>Noch keine Besuche erfasst.</p>
				<?php endif; ?>
				<?php foreach ( $paths as $token => $events ) : ?>
					<div style="border-top:1px solid #f0f0f1;padding:8px 0">
						<?php
						$parts = array();
						foreach ( $events as $e ) {
							$label = $steps[ $e['typ'] ] ?? $e['typ'];
							if ( $e['seminar_id'] ) {
								$titel = get_the_title( (int) $e['seminar_id'] );
								if ( $titel ) {
									$label .= ' („' . $titel . '")';
								}
							}
							$parts[] = esc_html( date_i18n( 'd.m. H:i', strtotime( $e['created'] ) ) . ' ' . $label );
						}
						echo '<div style="font-size:13px">' . implode( ' <span style="color:#646970">→</span> ', $parts ) . '</div>';
						?>
					</div>
				<?php endforeach; ?>
				<p class="description" style="margin-top:10px">Ein Weg = ein Klick auf den Kampagnen-Link und alles,
					was dieser Besuch danach im Bildungsprogramm gemacht hat. Kommt jemand später über denselben Link
					erneut, beginnt ein neuer Weg.</p>
			</details>
		</div>
		<?php
	}

	/** ---------- Speichern ---------- */

	public static function save_kampagne() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_save_kampagne' );

		global $wpdb;
		$id   = (int) ( $_POST['id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $name ) {
			self::redirect_msg( 'Bitte eine Bezeichnung angeben.' );
		}

		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		$slug = self::unique_slug( $slug ?: 'kampagne', $id );

		$data = array(
			'slug'      => $slug,
			'name'      => $name,
			'quelle'    => sanitize_text_field( wp_unslash( $_POST['quelle'] ?? '' ) ),
			'ziel_post' => (int) ( $_POST['ziel_post'] ?? 0 ),
			'ziel_url'  => esc_url_raw( wp_unslash( $_POST['ziel_url'] ?? '' ) ),
			'notiz'     => sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
			'aktiv'     => empty( $_POST['aktiv'] ) ? 0 : 1,
		);
		$format = array( '%s', '%s', '%s', '%d', '%s', '%s', '%d' );

		if ( $id ) {
			$wpdb->update( self::table_kampagnen(), $data, array( 'id' => $id ), $format, array( '%d' ) );
			self::redirect_msg( 'Kampagne gespeichert.' );
		}

		$data['created'] = current_time( 'mysql' );
		$format[]        = '%s';
		$wpdb->insert( self::table_kampagnen(), $data, $format );
		self::redirect_msg( 'Kampagne angelegt. Der Link steht in der Liste zum Kopieren bereit.' );
	}

	private static function unique_slug( $slug, $ignore_id = 0 ) {
		global $wpdb;
		$base = substr( $slug, 0, 56 );
		$try  = $base;
		$i    = 2;
		while ( true ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				'SELECT id FROM ' . self::table_kampagnen() . ' WHERE slug = %s AND id <> %d LIMIT 1',
				$try,
				(int) $ignore_id
			) );
			if ( ! $exists ) {
				return $try;
			}
			$try = $base . '-' . $i;
			$i++;
		}
	}

	public static function delete_kampagne() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_delete_kampagne' );

		global $wpdb;
		$id = (int) ( $_POST['id'] ?? 0 );
		if ( $id ) {
			$wpdb->delete( self::table_kampagnen(), array( 'id' => $id ), array( '%d' ) );
			$wpdb->delete( self::table_events(), array( 'kampagne_id' => $id ), array( '%d' ) );
		}
		self::redirect_msg( 'Kampagne gelöscht. Die Anmeldungen selbst bleiben erhalten.' );
	}

	public static function prune_events() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_prune_events' );
		self::redirect_msg( self::prune_old() . ' Ereignisse gelöscht.' );
	}

	private static function redirect_msg( $msg ) {
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-kampagnen', 'bi_msg' => rawurlencode( $msg ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
