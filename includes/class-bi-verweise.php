<?php
/**
 * Verweise: Seminarnamen in »…« automatisch verlinken.
 *
 * ============================================================================
 *  WORUM ES GEHT
 * ============================================================================
 *  Die Voraussetzungen eines Seminars nennen andere Seminare beim Namen:
 *
 *    Teilnahme am Seminar »Mitbestimmung und Betriebsratshandeln«
 *
 *  Im gedruckten Bildungsprogramm stand dahinter „(siehe Seite 14)". Online
 *  ergibt eine Seitenzahl keinen Sinn – dort soll der Name selbst der Weg zum
 *  Seminar sein. Im Feld steht deshalb nur lesbarer Text; der Link entsteht
 *  erst beim Anzeigen. So bleiben Export, Mails und PDF sauber, und ein Link
 *  kann nicht veralten: Er zeigt immer auf den NÄCHSTEN Termin, den es gerade
 *  gibt.
 *
 * ============================================================================
 *  WIE EIN NAME ZU SEINEM ZIEL KOMMT
 * ============================================================================
 *  1. Gibt es eine Regel für den Namen (Einstellungen → Verweise), gilt sie:
 *       Name = Seminartitel            → Link auf dieses Seminar
 *       Name = Themenfeld: Begriff      → Link auf die Suche, nach Themenfeld gefiltert
 *       Name = -                        → bewusst kein Link (z. B. regionale Seminare)
 *  2. Sonst wird der Name selbst als Seminartitel gesucht.
 *  3. Findet sich kein kommender, sichtbarer Termin, bleibt der Name Text.
 *
 *  Die Regeln braucht es, weil die Namen in den Texten oft nicht wörtlich den
 *  Titeln entsprechen: »Lean im Betrieb« heißt in den Daten „Lean im Betrieb:
 *  Eine Strategie für den Betriebsrat", »BR kompakt« ist eine ganze Reihe,
 *  »Entgelt I« ein regionales Angebot, das gar nicht im Programm steht.
 *
 *  Verglichen wird nachsichtig: ohne Groß-/Kleinschreibung, mehrfache
 *  Leerzeichen zählen als eines, Gedankenstrich und Bindestrich sind gleich.
 *  In den Quelldaten steht „Erfolgreiche Gesprächsführung  - im Dialog …" mit
 *  zwei Leerzeichen – ein strenger Vergleich fände das nie.
 *
 * ============================================================================
 *  WARUM DIE REGELN EIN TEXTFELD SIND UND KEINE TABELLE
 * ============================================================================
 *  Eine Zeile je Regel lässt sich zwischen den drei Installationen per
 *  Kopieren und Einfügen abgleichen, in einer Mail verschicken und ohne
 *  Klickstrecke durchsehen. Die Prüftabelle unter dem Feld zeigt nach dem
 *  Speichern, welche Regel ein Ziel findet und welche nicht.
 *
 *  Solange nichts gespeichert ist, gilt die Startbelegung (STARTBELEGUNG) – die
 *  geprüften Verweise aus dem Bildungsprogramm 2027.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Verweise {

	/** Option mit den Regeln (Text, eine Regel je Zeile). */
	const OPTION = 'bi_verweise';

	/** Einstellungen-Reiter */
	const TAB = 'verweise';

	/** Meta-Felder, deren Text verlinkt wird – für die Prüfliste „ohne Ziel". */
	const FELDER = array( '_bi_voraussetzungen', '_bir_voraussetzungen' );

	/**
	 * Startbelegung: die geprüften Verweise aus dem Bildungsprogramm 2027
	 * (handoff/Pruefliste-Voraussetzungen-2027.xlsx, Blatt „Verweise").
	 * Namen, die wörtlich einem Seminartitel entsprechen, brauchen keine Regel.
	 */
	const STARTBELEGUNG = <<<'TXT'
# Name im Text = Ziel
#   Ziel ist ein Seminartitel, „Themenfeld: Begriff" oder „-" (kein Link).
#   Namen, die genau wie ein Seminartitel lauten, brauchen keine Regel.
#   Zeilen mit # am Anfang sind Kommentare.

# Reihen → Suche nach Themenfeld
BR kompakt = Themenfeld: BR kompakt - die Ausbildungsreihe für Betriebsräte
VL kompakt = Themenfeld: VL kompakt - die Ausbildungsreihe für Vertrauensleute

# Andere Schreibweise im Text als im Titel
Zentrale Aufgaben der Schwerbehindertenvertretung (THP I) = Zentrale Aufgaben der Schwerbehindertenvertretung
Teilhabepraxis I – Zentrale Aufgaben der Schwerbehindertenvertretung (THP I) = Zentrale Aufgaben der Schwerbehindertenvertretung
Situation und Interessen junger Arbeitnehmer*innen im Betrieb II = Jugend II - Zwischen Solidarität und Konkurrenz
Einführung in die Arbeit des Wirtschaftsausschusses (WiA I) = Einführung in die Arbeit des Wirtschaftsausschusses
Strategische Personalplanung im Wirtschaftsausschuss (WiA II) = Strategische Personalplanung im Wirtschaftsausschuss
Erfolgreiche Gesprächsführung = Erfolgreiche Gesprächsführung - im Dialog überzeugen und wirksam argumentieren!
Lean im Betrieb = Lean im Betrieb: Eine Strategie für den Betriebsrat
Excel-Grundlagen und KI für den Betriebsrat = Excel-Grundlagen für Betriebsrat und SBV
Einmaleins der Kommunikation = Überzeugend reden und auftreten
Künstliche Intelligenz = Künstliche Intelligenz: Betriebliche Anwendungen und Mitbestimmung
Kompetenzen für KI im Betrieb: Wie Betriebsräte bei der Qualifizierung für KI-Systeme mitbestimmen können = Wie Betriebsräte bei der Qualifizierung für KI-Systeme mitbestimmen können
Betriebliches Eingliederungsmanagement – Arbeitsfähigkeit erhalten und sichern (THP III) = Betriebliches Eingliederungsmanagement (BEM) - Arbeitsfähigkeit erhalten und sichern
Demokratie und Faschismus I = Demokratie und Faschismus I: Der europäische Faschismus als Krisenerscheinung
Einführung in die Betriebsratsarbeit = Einführung in die Betriebsratsarbeit (BR I)

# Regionale Angebote – stehen nicht im Programm, bleiben Text
Einstieg für Betriebsrät*innen (BR Einstieg) = -
Arbeitnehmer*innen in Betrieb, Wirtschaft und Gesellschaft (A I) = -
Situation und Interessen junger Arbeitnehmer*innen im Betrieb I = -
Arbeits- und Gesundheitsschutz I = -
Entgelt I = -
Entgeltgestaltung II = -
Entgeltgestaltung II B = -
TXT;

	public static function init() {
		add_action( 'admin_post_bi_verweise_speichern', array( __CLASS__, 'handle_save' ) );
	}

	/* ===================================================================
	 *  Regeln
	 * =================================================================== */

	/** Der gespeicherte Regeltext – oder die Startbelegung, solange nichts gespeichert ist. */
	public static function text() {
		$t = get_option( self::OPTION, false );
		return ( false === $t ) ? self::STARTBELEGUNG : (string) $t;
	}

	/**
	 * Vergleichsform eines Namens oder Titels: klein, Striche vereinheitlicht,
	 * Anführungszeichen vereinheitlicht, Leerraum zusammengezogen.
	 */
	public static function norm( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = str_replace( "\xC2\xAD", '', $s ); // weiches Trennzeichen
		$s = str_replace( array( '–', '—', '‑', '‐' ), '-', $s );
		$s = str_replace( array( '„', '“', '”', '»', '«', '‚', '‘', '’' ), '"', $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		// „A - B", „A– B", „A -B" sind dasselbe; „Excel-Grundlagen" (ohne Leerraum) bleibt, wie es ist.
		$s = preg_replace( '/\s+-\s*|\s*-\s+/u', ' - ', $s );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $s ), 'UTF-8' ) : strtolower( trim( $s ) );
	}

	/**
	 * Regeltext zerlegen.
	 *
	 * @return array norm(Name) => ['name'=>…, 'art'=>'seminar'|'themenfeld'|'kein', 'ziel'=>…, 'zeile'=>int]
	 */
	public static function parse( $text ) {
		$regeln = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $nr => $zeile ) {
			$zeile = trim( $zeile );
			if ( '' === $zeile || '#' === $zeile[0] ) {
				continue;
			}
			$pos = strpos( $zeile, '=' );
			if ( false === $pos ) {
				continue;
			}
			$name = trim( substr( $zeile, 0, $pos ) );
			$ziel = trim( substr( $zeile, $pos + 1 ) );
			// Guillemets um den Namen sind erlaubt, aber nicht nötig.
			$name = trim( $name, " \t»«" );
			if ( '' === $name ) {
				continue;
			}
			if ( '' === $ziel || '-' === $ziel || '–' === $ziel ) {
				$art  = 'kein';
				$ziel = '';
			} elseif ( preg_match( '/^themenfeld\s*:\s*(.+)$/iu', $ziel, $m ) ) {
				$art  = 'themenfeld';
				$ziel = trim( $m[1] );
			} else {
				$art = 'seminar';
			}
			$regeln[ self::norm( $name ) ] = array(
				'name'  => $name,
				'art'   => $art,
				'ziel'  => $ziel,
				'zeile' => $nr + 1,
			);
		}
		return $regeln;
	}

	/** Die geltenden Regeln – je Aufruf der Seite nur einmal zerlegt. */
	private static function regeln() {
		static $regeln = null;
		if ( null === $regeln ) {
			$regeln = self::parse( self::text() );
		}
		return $regeln;
	}

	/* ===================================================================
	 *  Ausgabe
	 * =================================================================== */

	/**
	 * Text als HTML: maskiert, Zeilenumbrüche erhalten, »Namen« verlinkt.
	 *
	 * @param string $text    Feldinhalt (Klartext).
	 * @param int    $post_id Seminar, auf dessen Seite der Text steht – ein
	 *                        Verweis auf sich selbst wird nicht verlinkt.
	 */
	public static function html( $text, $post_id = 0 ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}
		$eigener = $post_id ? self::norm( get_the_title( $post_id ) ) : '';
		$post_type = $post_id ? get_post_type( $post_id ) : '';

		return nl2br( self::ersetzen( $text, function ( $name ) use ( $eigener, $post_type ) {
			return self::ziel_url( $name, $eigener, $post_type );
		} ) );
	}

	/**
	 * Kern ohne WordPress: jeden »Namen« durch einen Link ersetzen, den der
	 * Auflöser liefert; alles andere maskieren. Liefert der Auflöser '', bleibt
	 * der Name Text. Die Guillemets stehen AUSSERHALB des Links – sie gehören
	 * zum Satz, nicht zum Seminar.
	 *
	 * @param string   $text      Klartext.
	 * @param callable $aufloeser function( string $name ): string (URL oder '').
	 */
	public static function ersetzen( $text, $aufloeser ) {
		$teile = preg_split( '/»([^«»]+)«/u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $teile ) {
			return esc_html( $text );
		}
		$out = '';
		foreach ( $teile as $i => $teil ) {
			if ( 0 === $i % 2 ) {
				$out .= esc_html( $teil );
				continue;
			}
			$url = (string) call_user_func( $aufloeser, trim( $teil ) );
			$out .= '»' . ( '' !== $url
				? '<a class="bi-verweis" href="' . esc_url( $url ) . '">' . esc_html( $teil ) . '</a>'
				: esc_html( $teil ) ) . '«';
		}
		return $out;
	}

	/**
	 * Ziel eines Namens.
	 *
	 * @param string $name      Name wie im Text.
	 * @param string $eigener   norm() des Titels der aktuellen Seite ('' = keiner).
	 * @param string $post_type Beitragstyp der aktuellen Seite – bevorzugt.
	 * @return string URL oder ''.
	 */
	public static function ziel_url( $name, $eigener = '', $post_type = '' ) {
		$regel = self::regeln()[ self::norm( $name ) ] ?? null;
		if ( $regel ) {
			if ( 'kein' === $regel['art'] ) {
				return '';
			}
			if ( 'themenfeld' === $regel['art'] ) {
				return self::themenfeld_url( $regel['ziel'] );
			}
			$titel = $regel['ziel'];
		} else {
			$titel = $name;
		}

		$k = self::norm( $titel );
		if ( '' !== $eigener && $k === $eigener ) {
			return ''; // Verweis auf das Seminar, auf dessen Seite man steht
		}
		$id = self::naechster_termin( $k, $post_type );
		return $id ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Nächster sichtbarer, veröffentlichter Termin eines Titels. Derselbe
	 * Beitragstyp wie die aktuelle Seite hat Vorrang: Von einem Online-Seminar
	 * aus führt der Weg zuerst zum Online-Termin, wenn es einen gibt.
	 *
	 * @param string $k         norm() des Titels.
	 * @param string $post_type bevorzugter Beitragstyp ('' = egal).
	 * @return int Post-ID oder 0.
	 */
	public static function naechster_termin( $k, $post_type = '' ) {
		$kandidaten = self::termine()[ $k ] ?? array();
		foreach ( $kandidaten as $t ) {
			if ( '' === $post_type || $t['pt'] === $post_type ) {
				return $t['id'];
			}
		}
		return $kandidaten ? $kandidaten[0]['id'] : 0;
	}

	/**
	 * Alle kommenden, sichtbaren Termine, nach Titel gruppiert und nach
	 * Startdatum sortiert. Eine Abfrage je Seitenaufruf – und die nur, wenn ein
	 * Text überhaupt einen »Namen« enthält.
	 *
	 * „Sichtbar" heißt wie überall: `_bi_anzeigen` ist nicht ausdrücklich '0'
	 * (leer = Standard = anzeigen, siehe BI_CPT::is_visible).
	 *
	 * @return array norm(Titel) => [ ['id'=>int,'pt'=>string,'start'=>string], … ]
	 */
	private static function termine() {
		static $map = null;
		if ( null !== $map ) {
			return $map;
		}
		global $wpdb;
		$pts = bi_seminar_post_types();
		$in  = implode( ',', array_fill( 0, count( $pts ), '%s' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared – $in sind Platzhalter
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, m.meta_value AS start
			   FROM {$wpdb->posts} p
			  INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bi_startdatum'
			   LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_bi_anzeigen'
			  WHERE p.post_type IN ($in)
			    AND p.post_status = 'publish'
			    AND m.meta_value >= %s
			    AND ( a.meta_value IS NULL OR a.meta_value <> '0' )
			  ORDER BY m.meta_value ASC, p.ID ASC",
			array_merge( $pts, array( current_time( 'Y-m-d' ) ) )
		) );

		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ self::norm( $r->post_title ) ][] = array(
				'id'    => (int) $r->ID,
				'pt'    => $r->post_type,
				'start' => $r->start,
			);
		}
		return $map;
	}

	/** Link auf die Seminarsuche, nach Themenfeld gefiltert – oder '', wenn es den Begriff nicht gibt. */
	public static function themenfeld_url( $name ) {
		$term = self::themenfeld( $name );
		if ( ! $term ) {
			return '';
		}
		return add_query_arg( 'thema', rawurlencode( $term->name ), BI_Registration::uebersicht_url() );
	}

	/** Themenfeld-Begriff zu einem Namen, nachsichtig verglichen. */
	private static function themenfeld( $name ) {
		static $terms = null;
		if ( null === $terms ) {
			$terms = get_terms( array( 'taxonomy' => BI_TAX_THEMA, 'hide_empty' => false ) );
			$terms = is_wp_error( $terms ) ? array() : $terms;
		}
		$k = self::norm( $name );
		foreach ( $terms as $t ) {
			if ( self::norm( $t->name ) === $k ) {
				return $t;
			}
		}
		return null;
	}

	/* ===================================================================
	 *  Admin: Reiter „Verweise" in den Einstellungen
	 * =================================================================== */

	public static function render_section() {
		$text   = self::text();
		$regeln = self::parse( $text );
		$eigen  = ( false === get_option( self::OPTION, false ) );
		?>
		<h2 class="title">Verweise in den Voraussetzungen</h2>
		<p style="max-width:820px">Seminarnamen, die in den <strong>Voraussetzungen</strong> in <code>»…«</code> stehen,
		   werden auf der Detailseite automatisch verlinkt – auf den <strong>nächsten Termin</strong> des Seminars.
		   Heißt ein Name genau wie ein Seminartitel, geschieht das von allein. Für alle anderen legst du hier fest,
		   wohin er führt. Eine Regel je Zeile:</p>
		<pre style="background:#f6f7f7;padding:10px 14px;max-width:820px;white-space:pre-wrap">Lean im Betrieb = Lean im Betrieb: Eine Strategie für den Betriebsrat
BR kompakt = Themenfeld: BR kompakt - die Ausbildungsreihe für Betriebsräte
Entgelt I = -</pre>
		<p style="max-width:820px" class="description">Rechts vom <code>=</code> steht ein Seminartitel, ein Themenfeld
		   (Link auf die Seminarsuche) oder <code>-</code> für „bewusst kein Link" – etwa bei regionalen Seminaren,
		   die nicht im Programm stehen. Groß-/Kleinschreibung, doppelte Leerzeichen und Gedanken- statt Bindestrich
		   spielen keine Rolle.</p>
		<?php if ( $eigen ) : ?>
			<div class="notice notice-info inline"><p>Noch nichts gespeichert – es gilt die Startbelegung aus dem Bildungsprogramm 2027.</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bi_verweise_speichern">
			<?php wp_nonce_field( 'bi_verweise_speichern' ); ?>
			<textarea name="bi_verweise" rows="22" style="width:100%;max-width:1100px;font-family:monospace;font-size:12px"><?php echo esc_textarea( $text ); ?></textarea>
			<?php submit_button( 'Verweise speichern' ); ?>
		</form>

		<h3>Prüfung</h3>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th>Name im Text</th><th>Ziel</th><th>Ergebnis</th></tr></thead>
			<tbody>
			<?php foreach ( $regeln as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r['name'] ); ?></td>
					<td><?php echo esc_html( 'kein' === $r['art'] ? '–' : ( 'themenfeld' === $r['art'] ? 'Themenfeld: ' . $r['ziel'] : $r['ziel'] ) ); ?></td>
					<td><?php echo self::pruef_ergebnis( $r ); // phpcs:ignore – escaped ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$offen = self::namen_ohne_ziel();
		if ( $offen ) :
			?>
			<h3>Namen ohne Ziel</h3>
			<p class="description" style="max-width:820px">Diese Namen stehen in Voraussetzungen, haben keine Regel und
			   entsprechen keinem Seminar mit kommendem Termin. Sie erscheinen als Text. Wenn einer verlinkt werden soll,
			   eine Regel anlegen; wenn nicht, mit <code>= -</code> als erledigt markieren.</p>
			<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( $offen as $name => $anzahl ) : ?>
					<li><?php echo esc_html( '»' . $name . '«' ); ?> <span class="description">(<?php echo (int) $anzahl; ?>×)</span></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/** Ergebniszelle der Prüftabelle. */
	private static function pruef_ergebnis( $r ) {
		if ( 'kein' === $r['art'] ) {
			return '<span style="color:#646970">bleibt Text</span>';
		}
		if ( 'themenfeld' === $r['art'] ) {
			return self::themenfeld( $r['ziel'] )
				? '<span style="color:#008a20">✓ Themenfeld vorhanden</span>'
				: '<span style="color:#d63638">✗ Themenfeld gibt es nicht</span>';
		}
		$id = self::naechster_termin( self::norm( $r['ziel'] ) );
		if ( ! $id ) {
			return '<span style="color:#d63638">✗ kein kommender Termin mit diesem Titel</span>';
		}
		$start = get_post_meta( $id, '_bi_startdatum', true );
		return '<span style="color:#008a20">✓ nächster Termin ' . esc_html( $start ? date_i18n( 'd.m.Y', strtotime( $start ) ) : '' ) . '</span>'
			. ' <a href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener">ansehen</a>';
	}

	/**
	 * Namen in »…« aus allen Voraussetzungen, die weder eine Regel haben noch
	 * einem kommenden Seminar entsprechen.
	 *
	 * @return array Name => Anzahl der Texte, sortiert nach Häufigkeit.
	 */
	public static function namen_ohne_ziel() {
		global $wpdb;
		$in    = implode( ',', array_fill( 0, count( self::FELDER ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared – $in sind Platzhalter
		$texte = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($in) AND meta_value LIKE %s",
			array_merge( self::FELDER, array( '%' . $wpdb->esc_like( '»' ) . '%' ) )
		) );
		$regeln = self::regeln();
		$offen  = array();
		foreach ( (array) $texte as $t ) {
			if ( ! preg_match_all( '/»([^«»]+)«/u', (string) $t, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $name ) {
				$name = trim( $name );
				$k    = self::norm( $name );
				if ( isset( $regeln[ $k ] ) || self::naechster_termin( $k ) ) {
					continue;
				}
				$offen[ $name ] = ( $offen[ $name ] ?? 0 ) + 1;
			}
		}
		arsort( $offen );
		return $offen;
	}

	public static function handle_save() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_verweise_speichern' );

		$text = sanitize_textarea_field( wp_unslash( $_POST['bi_verweise'] ?? '' ) );
		update_option( self::OPTION, $text, false );

		// Die Detailseiten tragen die Links im fertigen HTML – ein Seiten-Cache
		// lieferte sonst die alten aus.
		if ( class_exists( 'BI_Cache' ) ) {
			BI_Cache::leeren( true );
		}

		wp_safe_redirect( add_query_arg( array(
			'page'   => 'bi-einstellungen',
			'tab'    => self::TAB,
			'bi_msg' => rawurlencode( count( self::parse( $text ) ) . ' Verweise gespeichert.' ),
		), admin_url( 'admin.php' ) ) );
		exit;
	}
}
