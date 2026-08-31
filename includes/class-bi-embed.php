<?php
/**
 * ============================================================
 *  BI_Embed – Einbettungsmodus für fremde Websites (iframe)
 * ============================================================
 *
 *  Das Problem, das dieses Modul löst
 *  ----------------------------------
 *  Wer die Seminarsuche per <iframe> auf einer anderen Website zeigt, will
 *  den Rahmen von bildung.igmetall.de (Kopf- und Fußbereich) nicht mit
 *  ausliefern. Eine eigens angelegte Seite „ohne Header“ hilft nur für den
 *  ersten Aufschlag: Sobald jemand im Rahmen weiterklickt – auf ein Seminar,
 *  auf die Anmeldung, auf Seite 2 der Treffer –, landet er auf einer ganz
 *  normalen Seite und der Rahmen ist wieder da.
 *
 *  Der Einbettungsmodus ist deshalb kein Seitentyp, sondern ein Zustand, der
 *  über alle Klicks hinweg mitläuft:
 *
 *    1. Erkennen – woran merkt eine Anfrage, dass sie im Rahmen steckt?
 *    2. Ausliefern – Seite ohne Theme-Kopf/-Fuß rendern.
 *    3. Weiterreichen – jeder erzeugte Link trägt den Zustand weiter.
 *
 *  Zu 1) Zwei Wege, bewusst in dieser Reihenfolge:
 *
 *    a) Der Parameter ?bi_embed=1 in der Adresse. Er ist der führende Weg,
 *       weil er im Seiten-Cache einen eigenen Schlüssel erzeugt: Die
 *       rahmenlose Fassung kann so nie versehentlich an normale Besucher
 *       ausgeliefert werden (und umgekehrt).
 *    b) Der Anfrage-Kopf `Sec-Fetch-Dest: iframe`. Den schicken heutige
 *       Browser bei JEDER Navigation innerhalb eines Rahmens mit, nicht nur
 *       beim ersten Aufruf. Er ist das Fangnetz für Links, die der Parameter
 *       nicht erreicht hat. Weil er im Cache-Schlüssel NICHT auftaucht, darf
 *       eine so erkannte Seite niemals gespeichert werden – deshalb setzt
 *       header_regeln() in diesem Fall harte No-Cache-Kopfzeilen.
 *
 *  Zu 3) Serverseitig hängen die Permalink-Filter den Parameter an alle
 *  eigenen Adressen. Das deckt Trefferliste, Blätterlinks, Detailseiten und
 *  die Weiterleitung nach der Anmeldung ab. Fremde Adressen bleiben unberührt.
 *
 *  Was dieses Modul NICHT tut
 *  --------------------------
 *  Es verändert nichts, solange der Einbettungsmodus nicht aktiv ist. Ohne
 *  Parameter und ohne Rahmen ist bildung.igmetall.de exakt die Seite, die sie
 *  vorher war.
 *
 *  Stellschrauben (im Child-Theme oder einem Mini-Plugin)
 *  -----------------------------------------------------
 *    add_filter( 'bi_embed_frame_ancestors', function ( $hosts ) {
 *        $hosts[] = 'https://www.beispiel.de';
 *        return $hosts;
 *    } );
 *
 *    add_filter( 'bi_embed_sec_fetch', '__return_false' );  // Fangnetz aus
 *    add_filter( 'bi_embed_aktiv', function ( $an ) { … } ); // letztes Wort
 *
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Embed {

	/** Name des Parameters in der Adresse */
	const PARAM = 'bi_embed';

	/** Einmal ermittelt, dann gemerkt (null = noch nicht geprüft) */
	private static $aktiv = null;

	public static function init() {
		// Die Erkennung selbst braucht keinen Hook – sie liest nur die Anfrage.
		// Alle Wirkungen hängen aber an ihr, deshalb hier einmal früh prüfen.
		if ( ! self::aktiv() ) {
			return;
		}

		// Kopfzeilen (Cache, frame-ancestors) so früh wie möglich.
		add_action( 'template_redirect', array( __CLASS__, 'header_regeln' ), 0 );

		// Eigener Rahmen statt Theme-Rahmen. Priorität 200: BI_Detail und
		// BI_Reihen setzen ihre Templates bei 99, dieses hier gilt danach.
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 200 );

		// Adressen, die WordPress erzeugt, tragen den Zustand weiter.
		foreach ( array( 'post_link', 'page_link', 'post_type_link', 'term_link', 'get_pagenum_link', 'wp_redirect' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'url' ), 20 );
		}

		// Suchmaske: Der Sprungknopf „Suche starten“ baut sein Ziel im Browser
		// aus CONFIG.targetUrl neu zusammen – die Adresse muss den Parameter
		// deshalb schon serverseitig mitbringen (siehe BI_Filter::shortcode_maske).
		//
		// Nicht aber, wenn der Sprung den Rahmen verlassen soll: Dann ist das
		// Ziel eine ganz normale Seite (oder liegt sogar auf der einbettenden
		// Website), und der Einbettungsmodus hätte dort nichts verloren.
		if ( ! self::ziel_oben() ) {
			add_filter( 'bi_suchmaske_ziel_url', array( __CLASS__, 'url' ), 20 );
		}

		// Zwischenspeicher sauber halten. BI_Registration merkt sich die Adresse
		// der Anmelde- und der Übersichtsseite eine Stunde lang. Würde sie im
		// Rahmen befüllt, bekämen anschließend ALLE Besucher – auch die auf der
		// normalen Website – die rahmenlose Adresse zu sehen. Der Parameter
		// gehört in die Ausgabe, nicht in einen Speicher, der geteilt wird.
		foreach ( array( 'bi_anmeldung_page_url', 'bi_uebersicht_page_url' ) as $t ) {
			add_filter( 'pre_set_transient_' . $t, array( __CLASS__, 'url_ohne_param' ) );
		}

		// Kein Admin-Balken im Rahmen: Er gehört zur Redaktion, nicht zur Einbettung.
		add_filter( 'show_admin_bar', '__return_false' );

		// Suchmaschinen sollen die rahmenlose Fassung nicht als eigene Seite
		// führen. Die kanonische Adresse zeigt auf die Seite ohne Parameter –
		// dafür muss WordPress' eigenes rel=canonical weichen, es liefe sonst
		// durch unsere Permalink-Filter und zeigte auf sich selbst.
		remove_action( 'wp_head', 'rel_canonical' );
		add_action( 'wp_head', array( __CLASS__, 'kopf_meta' ), 1 );

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/* ===================================================================
	 *  1) Erkennen
	 * =================================================================== */

	/**
	 * Läuft diese Anfrage im Einbettungsmodus?
	 *
	 * Wird mehrfach abgefragt (Filter, Template, Assets) und deshalb gemerkt.
	 */
	public static function aktiv() {
		if ( null !== self::$aktiv ) {
			return self::$aktiv;
		}

		$an = false;

		// Verwaltung, AJAX, REST und Cron bleiben außen vor: Dort wäre ein
		// rahmenloses Template schlicht falsch, und der Sec-Fetch-Kopf kann
		// bei einem fetch() aus dem Rahmen heraus durchaus „iframe“ sein.
		$intern = is_admin()
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX );

		if ( ! $intern ) {
			$param = bi_get( self::PARAM, '' );
			if ( '' !== $param ) {
				// ?bi_embed=0 schaltet bewusst ab – nützlich zum Nachsehen,
				// wie eine Seite ohne Rahmen-Modus aussieht.
				$an = ! in_array( $param, array( '0', 'false', 'nein' ), true );
			} elseif ( self::sec_fetch_iframe() ) {
				$an = true;
			}
		}

		self::$aktiv = (bool) apply_filters( 'bi_embed_aktiv', $an );
		return self::$aktiv;
	}

	/**
	 * Fangnetz: Meldet der Browser selbst, dass das Ziel ein Rahmen ist?
	 *
	 * `Sec-Fetch-Dest` ist ein sogenannter verbotener Kopf – er lässt sich aus
	 * JavaScript nicht setzen und stammt garantiert vom Browser. Ältere
	 * Browser (Safari vor 16.4) schicken ihn nicht; für die bleibt der
	 * Parameter aus 1a) der Weg, und der greift ohnehin zuerst.
	 */
	private static function sec_fetch_iframe() {
		if ( ! apply_filters( 'bi_embed_sec_fetch', true ) ) {
			return false;
		}
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ) : '';
		return in_array( $dest, array( 'iframe', 'frame' ), true );
	}

	/**
	 * Soll „Suche starten“ aus dem Rahmen herausspringen?
	 *
	 * Gesteuert über die Adresse: <code>&bi_ziel=oben</code>.
	 *
	 * Der Hintergrund: Eine eingebettete Suchmaske ist flach – 400 Pixel
	 * genügen. Die Trefferliste, auf die sie springt, ist es nicht. Bliebe der
	 * Sprung im Rahmen, quetschte sich eine lange Liste in ein Fenster, das für
	 * eine Maske bemessen wurde. Mit „oben“ übernimmt stattdessen das ganze
	 * Browserfenster – entweder die Seminarübersicht auf dieser Website oder
	 * eine eigene Ergebnisseite der einbettenden Website, je nachdem, was in
	 * ziel_url steht.
	 *
	 * Erlaubt ist das, weil der Sprung an einem Klick hängt: Ein Rahmen darf das
	 * oberste Fenster steuern, wenn eine Person ihn dazu aufgefordert hat.
	 */
	public static function ziel_oben() {
		return self::aktiv() && 'oben' === strtolower( bi_get( 'bi_ziel', '' ) );
	}

	/** Wurde der Modus über den Parameter angefordert (statt nur erkannt)? */
	private static function per_parameter() {
		return '' !== bi_get( self::PARAM, '' );
	}

	/* ===================================================================
	 *  2) Kopfzeilen: Cache und Rahmen-Erlaubnis
	 * =================================================================== */

	/**
	 * Zwei Dinge, die vor der ersten Ausgabe geklärt sein müssen.
	 *
	 * Cache: Wurde der Modus nur am Sec-Fetch-Kopf erkannt, unterscheidet sich
	 * die Adresse nicht von der normalen Seite. Ein Seiten-Cache würde die
	 * rahmenlose Fassung unter derselben Adresse ablegen und anschließend
	 * jedem Besucher zeigen. Deshalb: nicht speichern. Beim Parameter-Weg ist
	 * das nicht nötig – der Parameter ist Teil des Schlüssels –, schadet aber
	 * auch nicht, solange die Trefferliste ohnehin selten identisch ist.
	 *
	 * Rahmen: X-Frame-Options kennt nur „gar nicht“ oder „gleiche Domain“ und
	 * wird von manchen Sicherheits-Plugins gesetzt. Es muss weg, sonst zeigt
	 * der Rahmen auf der Fremdseite nichts. An seine Stelle tritt
	 * frame-ancestors, das einzelne Hosts erlauben kann.
	 */
	public static function header_regeln() {
		if ( headers_sent() ) {
			return;
		}

		if ( ! self::per_parameter() ) {
			nocache_headers();
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
		}

		header_remove( 'X-Frame-Options' );

		$hosts = self::frame_ancestors();
		if ( ! empty( $hosts ) ) {
			// Angehängt (zweiter Parameter false), nicht ersetzt: Eine bereits
			// gesetzte Richtlinie der Website könnte weit mehr regeln als nur
			// den Rahmen, und die zu überschreiben wäre ein Sicherheitsverlust.
			// Nebenwirkung: Steht dort schon ein eigenes frame-ancestors, gilt
			// die Schnittmenge beider Regeln – dann bleibt der Rahmen leer und
			// die vorhandene Richtlinie muss angepasst werden.
			header( 'Content-Security-Policy: frame-ancestors ' . implode( ' ', $hosts ), false );
		}
	}

	/**
	 * Wer darf die Seiten in einen Rahmen setzen?
	 *
	 * Drei Quellen, in dieser Reihenfolge:
	 *   1. fest: die eigene Domain und alles unterhalb von igmetall.de
	 *   2. die Einstellung „Einbettung (iframe)“ im Backend
	 *   3. der Filter bi_embed_frame_ancestors – für Fälle, die eine
	 *      Einstellung nicht abbildet
	 *
	 * Die Einstellung gibt es, weil die Alternative das Bearbeiten einer
	 * PHP-Datei wäre. Ein Tippfehler dort legt die ganze Website samt Backend
	 * lahm – für eine Liste von Domains ist das ein absurdes Risiko.
	 */
	private static function frame_ancestors() {
		$hosts = array( "'self'", 'https://*.igmetall.de', 'https://igmetall.de' );

		foreach ( self::hosts_aus_einstellung() as $host ) {
			$hosts[] = $host;
		}

		$hosts = (array) apply_filters( 'bi_embed_frame_ancestors', $hosts );

		$sauber = array();
		foreach ( $hosts as $host ) {
			$host = self::host_normalisieren( $host );
			if ( '' !== $host ) {
				$sauber[] = $host;
			}
		}
		return array_values( array_unique( $sauber ) );
	}

	/**
	 * Dieselbe Liste für die Anzeige im Backend.
	 *
	 * Die Einstellungsseite zeigt die Zeile, die tatsächlich ausgeliefert wird –
	 * nicht eine nachgebaute. Eine Vorschau, die vom Original abweichen kann,
	 * wäre schlimmer als gar keine: Man prüfte dann die Vorschau statt die Wirkung.
	 */
	public static function frame_ancestors_vorschau() {
		return self::frame_ancestors();
	}

	/** Die im Backend eingetragenen Herkünfte, eine je Zeile. */
	public static function hosts_aus_einstellung() {
		if ( ! class_exists( 'BI_Settings' ) ) {
			return array();
		}
		$roh = (string) BI_Settings::get( 'embed_hosts' );
		if ( '' === trim( $roh ) ) {
			return array();
		}

		$raus = array();
		foreach ( preg_split( '/\R/', $roh ) as $zeile ) {
			$host = self::host_normalisieren( $zeile );
			if ( '' !== $host ) {
				$raus[] = $host;
			}
		}
		return array_values( array_unique( $raus ) );
	}

	/**
	 * Eine Zeile in eine gültige Herkunft verwandeln – oder verwerfen.
	 *
	 * Verlangt wird eine Herkunft, kein Hostname: „https://www.beispiel.de“.
	 * Ein Pfad, ein Schrägstrich am Ende oder ein Leerzeichen fliegen raus.
	 * Das ist keine Kosmetik: Ein Leerzeichen mitten in der Liste erzeugt
	 * einen zweiten Eintrag, ein Semikolon sogar eine zweite Richtlinie –
	 * damit ließe sich der ganze Schutz aushebeln.
	 *
	 * Vergessenes Protokoll wird nachgetragen (https), weil das der einzige
	 * Fehler ist, dessen Absicht eindeutig ist.
	 */
	public static function host_normalisieren( $zeile ) {
		$zeile = trim( (string) $zeile );
		if ( '' === $zeile ) {
			return '';
		}

		// Schlüsselwörter der Richtliniensprache unverändert durchlassen.
		if ( in_array( strtolower( $zeile ), array( "'self'", "'none'", '*' ), true ) ) {
			return strtolower( $zeile );
		}

		if ( ! preg_match( '~^https?://~i', $zeile ) ) {
			$zeile = 'https://' . $zeile;
		}
		$zeile = rtrim( $zeile, '/' );

		// Alles ab dem ersten Schrägstrich nach dem Host ist ein Pfad und in
		// einer frame-ancestors-Liste bedeutungslos.
		$zeile = preg_replace( '~^(https?://[^/]+).*$~i', '$1', $zeile );

		// Letzte Kontrolle: nur Zeichen, die in der Liste vorkommen dürfen.
		return preg_match( '~^https?://[a-z0-9.*:_-]+$~i', $zeile ) ? $zeile : '';
	}

	/* ===================================================================
	 *  3) Ausliefern
	 * =================================================================== */

	/** Rahmenloses Template statt des Theme-Rahmens */
	public static function template_include( $template ) {
		$eigen = BI_PATH . 'templates/embed.php';
		return file_exists( $eigen ) ? $eigen : $template;
	}

	/** noindex + kanonische Adresse ohne Parameter */
	public static function kopf_meta() {
		echo "<meta name=\"robots\" content=\"noindex,follow\">\n";

		$rein = self::url_ohne_param( self::aktuelle_url() );
		if ( '' !== $rein ) {
			echo '<link rel="canonical" href="' . esc_url( $rein ) . "\">\n";
		}
	}

	public static function body_class( $classes ) {
		$classes[] = 'bi-embed';
		return $classes;
	}

	public static function assets() {
		wp_enqueue_style( 'bi-embed', BI_URL . 'assets/css/embed.css', array(), BI_VERSION );
		wp_enqueue_script( 'bi-embed', BI_URL . 'assets/js/embed.js', array(), BI_VERSION, true );
		wp_localize_script( 'bi-embed', 'biEmbed', array(
			'param' => self::PARAM,
			'host'  => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
		) );
	}

	/* ===================================================================
	 *  4) Weiterreichen
	 * =================================================================== */

	/**
	 * Hängt den Parameter an eine eigene Adresse. Alles andere bleibt, wie es ist.
	 *
	 * Wird als Filter auf sechs Hooks gelegt und muss deshalb jede Eingabe
	 * unverändert zurückgeben können, die kein passender Link ist.
	 */
	public static function url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}
		if ( ! self::eigene_adresse( $url ) ) {
			return $url;
		}
		return add_query_arg( self::PARAM, '1', $url );
	}

	/**
	 * Zeigt die Adresse auf diese Website – und auf einen Bereich, in dem der
	 * Einbettungsmodus überhaupt etwas zu suchen hat?
	 *
	 * Verwaltung, Login, AJAX und REST bleiben ausgenommen: Dort richtet der
	 * Parameter nichts aus und stünde nur in Formular-Adressen herum.
	 */
	private static function eigene_adresse( $url ) {
		$teile = wp_parse_url( $url );
		if ( false === $teile ) {
			return false;
		}

		// Protokoll: nur http(s). mailto:, tel:, javascript: scheiden aus.
		if ( isset( $teile['scheme'] ) && ! in_array( strtolower( $teile['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		// Fremder Host? Fehlt der Host, ist die Adresse relativ – also eigen.
		if ( ! empty( $teile['host'] ) ) {
			$eigen = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( strtolower( $teile['host'] ) !== strtolower( $eigen ) ) {
				return false;
			}
		}

		$pfad = isset( $teile['path'] ) ? strtolower( $teile['path'] ) : '';
		foreach ( array( '/wp-admin/', '/wp-login.php', '/wp-json/', '/wp-cron.php', 'admin-ajax.php' ) as $tabu ) {
			if ( false !== strpos( $pfad, $tabu ) ) {
				return false;
			}
		}

		return true;
	}

	/** Öffentlich, damit andere Module gezielt eine Embed-Adresse bauen können. */
	public static function url_ohne_param( $url ) {
		return remove_query_arg( self::PARAM, (string) $url );
	}

	/**
	 * Die gerade aufgerufene Adresse.
	 *
	 * Bewusst aus home_url() plus REQUEST_URI zusammengesetzt und nicht aus
	 * HTTP_HOST: Der Host aus der Anfrage ist frei wählbar und hätte in einer
	 * kanonischen Adresse nichts verloren.
	 */
	private static function aktuelle_url() {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return home_url( $req );
	}
}
