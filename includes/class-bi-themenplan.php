<?php
/**
 * ============================================================
 *  Themenpläne zu Seminaren  (BI_Themenplan)
 * ============================================================
 *
 * Ein Themenplan ist ein fertiges Dokument (meist PDF), das den Ablauf eines
 * Seminars Tag für Tag beschreibt. Er wird nicht im Seminar gepflegt, sondern
 * liegt als Datei in einem eigenen Verzeichnis:
 *
 *     wp-content/uploads/bi-themenplaene/<Seminarnummer>.pdf
 *
 * ── Warum die Seminarnummer der Dateiname ist ───────────────────────────
 * Die Nummer ist im ganzen Plugin der Schlüssel (CSV-Import, Abgleich).
 * Dateien lassen sich so per FTP, per Upload-Maske oder gesammelt aus dem
 * Seminarverwaltungssystem ablegen, ohne dass jemand ein Seminar öffnen und
 * etwas zuordnen muss. Groß-/Kleinschreibung zählt beim Zuordnen nicht.
 *
 * ── Warum uploads/ und nicht der Plugin-Ordner ──────────────────────────
 * Der Plugin-Ordner wird bei jedem Deploy ersetzt. Was dort läge, wäre nach
 * dem nächsten Update weg.
 *
 * ── Erlaubte Formate ─────────────────────────────────────────────────────
 * PDF, Word (.docx/.doc) und OpenDocument (.odt). Liegen zu einer Nummer
 * mehrere Dateien, gewinnt das PDF – es sieht bei allen gleich aus. Beim
 * Hochladen über die Maske wird eine ältere Fassung mit anderer Endung
 * deshalb gleich mit entfernt.
 *
 * ── Präsenz und Online ───────────────────────────────────────────────────
 * Die Nummer ist pro Seminarform eindeutig, nicht über beide hinweg. Trägt
 * ein Online-Seminar dieselbe Nummer wie ein Präsenz-Seminar, zeigen beide
 * denselben Plan. Das ist bei gleicher Nummer auch inhaltlich richtig.
 *
 * ── Abgleich mit der Zentrale ────────────────────────────────────────────
 * bildung.igmetall.de zeigt auch die Seminare aus Sprockhövel, Berlin und
 * Lohr/Bad Orb – deren Themenpläne liegen aber dort, nicht in der Zentrale.
 * Die Quelle gibt deshalb im Paket die Adresse ihres Plans mit; die Zentrale
 * merkt sie sich (Meta `_bi_sync_themenplan`) und verlinkt dorthin. Eine
 * Datei, die in der Zentrale selbst liegt, geht immer vor.
 *
 * Damit eine neue Datei die Zentrale auch erreicht, wenn sie per FTP kam und
 * kein Seminar gespeichert wurde, vergleicht abgleichen() das Verzeichnis mit
 * dem letzten bekannten Stand und setzt bei betroffenen Seminaren die
 * Änderungsmarke. Das läuft beim Öffnen der Verwaltung, nach jedem Upload
 * und jedes Mal, wenn die Zentrale den Bestand abfragt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Themenplan {

	/** Unterordner in wp-content/uploads */
	const ORDNER = 'bi-themenplaene';

	/** Erlaubte Endungen in der Rangfolge, in der sie gewinnen. */
	const ENDUNGEN = array(
		'pdf'  => 'application/pdf',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'doc'  => 'application/msword',
		'odt'  => 'application/vnd.oasis.opendocument.text',
	);

	/** Zentrale: Adresse des Plans in der Quelle */
	const META_FREMD = '_bi_sync_themenplan';

	/** Letzter bekannter Verzeichnisstand: [ nummer => "datei|mtime|größe" ] */
	const OPTION_STAND = 'bi_themenplan_stand';

	const TAB = 'themenplaene';

	/** Verzeichnisinhalt je Anfrage: [ nummer_klein => dateiname ] */
	private static $dateien = null;

	public static function init() {
		add_action( 'admin_post_bi_themenplan_hochladen', array( __CLASS__, 'handle_hochladen' ) );
		add_action( 'admin_post_bi_themenplan_loeschen', array( __CLASS__, 'handle_loeschen' ) );
		add_action( 'wp_ajax_bi_themenplan_paket', array( __CLASS__, 'ajax_paket' ) );
	}

	/* ===================================================================
	 *  Verzeichnis
	 * =================================================================== */

	/** Absoluter Pfad des Verzeichnisses (ohne Schrägstrich am Ende) oder ''. */
	public static function verzeichnis() {
		$up = wp_upload_dir( null, false );
		if ( empty( $up['basedir'] ) ) {
			return '';
		}
		return trailingslashit( $up['basedir'] ) . self::ORDNER;
	}

	/** Öffentliche Adresse des Verzeichnisses (ohne Schrägstrich am Ende) oder ''. */
	public static function url_basis() {
		$up = wp_upload_dir( null, false );
		if ( empty( $up['baseurl'] ) ) {
			return '';
		}
		return trailingslashit( $up['baseurl'] ) . self::ORDNER;
	}

	/**
	 * Verzeichnis anlegen, falls es fehlt – mit einer leeren index.php, damit
	 * der Webserver keine Dateiliste ausgibt. Die Pläne selbst bleiben
	 * öffentlich abrufbar, sie sollen ja verlinkt werden.
	 */
	public static function verzeichnis_anlegen() {
		$dir = self::verzeichnis();
		if ( '' === $dir ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		return true;
	}

	/** Seminarnummer in die Form bringen, in der verglichen wird. */
	public static function schluessel( $nummer ) {
		return strtolower( trim( (string) $nummer ) );
	}

	/**
	 * Alle Themenpläne im Verzeichnis: [ nummer_klein => dateiname ].
	 * Bei mehreren Dateien zu einer Nummer gewinnt die Endung, die in
	 * ENDUNGEN weiter vorn steht.
	 */
	public static function dateien( $neu = false ) {
		if ( null !== self::$dateien && ! $neu ) {
			return self::$dateien;
		}
		self::$dateien = array();
		$dir = self::verzeichnis();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return self::$dateien;
		}
		$rang  = array_flip( array_keys( self::ENDUNGEN ) );
		$namen = @scandir( $dir ); // phpcs:ignore
		if ( ! is_array( $namen ) ) {
			return self::$dateien;
		}
		foreach ( $namen as $name ) {
			if ( '.' === $name[0] || ! is_file( $dir . '/' . $name ) ) {
				continue;
			}
			$endung = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! isset( $rang[ $endung ] ) ) {
				continue;
			}
			$key = self::schluessel( pathinfo( $name, PATHINFO_FILENAME ) );
			if ( '' === $key ) {
				continue;
			}
			if ( isset( self::$dateien[ $key ] ) ) {
				$alt = strtolower( pathinfo( self::$dateien[ $key ], PATHINFO_EXTENSION ) );
				if ( $rang[ $alt ] <= $rang[ $endung ] ) {
					continue;
				}
			}
			self::$dateien[ $key ] = $name;
		}
		return self::$dateien;
	}

	/** Dateiname des Plans zu einer Seminarnummer oder ''. */
	public static function datei_fuer_nummer( $nummer ) {
		$key = self::schluessel( $nummer );
		if ( '' === $key ) {
			return '';
		}
		$alle = self::dateien();
		return isset( $alle[ $key ] ) ? $alle[ $key ] : '';
	}

	/** Adresse des Plans, der in DIESER Installation liegt, oder ''. */
	public static function url_lokal( $post_id ) {
		$datei = self::datei_fuer_nummer( get_post_meta( (int) $post_id, '_bi_seminarnummer', true ) );
		if ( '' === $datei ) {
			return '';
		}
		return self::url_basis() . '/' . rawurlencode( $datei );
	}

	/**
	 * Adresse des Plans für die Detailseite: erst die eigene Datei, dann die
	 * aus der Quelle mitgelieferte Adresse (nur in der Zentrale gesetzt).
	 */
	public static function url( $post_id ) {
		$url = self::url_lokal( $post_id );
		if ( '' !== $url ) {
			return $url;
		}
		$fremd = (string) get_post_meta( (int) $post_id, self::META_FREMD, true );
		return ( '' !== $fremd && wp_http_validate_url( $fremd ) ) ? $fremd : '';
	}

	/** Endung aus einer Adresse, für die Beschriftung des Knopfs. */
	private static function format_von( $url ) {
		$pfad   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$endung = strtolower( pathinfo( $pfad, PATHINFO_EXTENSION ) );
		$namen  = array( 'pdf' => 'PDF', 'docx' => 'Word', 'doc' => 'Word', 'odt' => 'OpenDocument' );
		return isset( $namen[ $endung ] ) ? $namen[ $endung ] : '';
	}

	/* ===================================================================
	 *  Detailseite
	 * =================================================================== */

	/**
	 * Download-Box „Themenplan" für die Sidebar der Detailseite, direkt über
	 * der Box „Seminardetails als PDF". Leer, wenn es keinen Plan gibt – dann
	 * entfällt die Box ersatzlos.
	 *
	 * Dieselben Klassen wie die PDF-Box (igm-box--download): gleiche Gestalt,
	 * und beim Drucken verschwinden beide.
	 *
	 * Neues Fenster statt download-Attribut: Ein PDF soll der Browser zeigen
	 * können, und bei einer Adresse aus der Quelle (andere Domain) würde das
	 * download-Attribut ohnehin ignoriert.
	 */
	public static function box( $post_id ) {
		$url = self::url( $post_id );
		if ( '' === $url ) {
			return '';
		}
		$format = self::format_von( $url );
		$label  = 'Themenplan herunterladen' . ( '' !== $format ? ' (' . $format . ')' : '' );

		return '<div class="igm-box igm-box--klein igm-box--download igm-box--themenplan" data-bi-mobil="ueberblick">'
			. '<h2 class="igm-box__titel">Themenplan</h2>'
			. '<p class="igm-box__text">Die Themen des Seminars in chronologischer Reihenfolge zum Download</p>'
			. '<a class="igm-btn igm-btn--sek igm-btn--download" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
			. BI_Icons::get( 'download', 20 )
			. '<span>' . esc_html( $label ) . '</span></a>'
			. '</div>';
	}

	/* ===================================================================
	 *  Benachrichtigungen: Themenplan als Anhang
	 * =================================================================== */

	/**
	 * Gibt es in dieser Installation überhaupt Themenpläne – eigene Dateien
	 * oder (in der Zentrale) Adressen aus den Quellen? Nur dann bietet die
	 * Benachrichtigung die Option „Themenplan anhängen" an.
	 */
	public static function vorhanden() {
		if ( self::dateien() ) {
			return true;
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' LIMIT 1",
			self::META_FREMD
		) );
	}

	/**
	 * Themenplan eines Seminars als temporäre Datei für wp_mail().
	 *
	 * IMMER EINE KOPIE: Der Mailer räumt seine Anhänge nach dem Versand mit
	 * BI_PDF::cleanup() weg. Bekäme er den Pfad im Themenplan-Verzeichnis,
	 * wäre der Plan nach der ersten Anmeldung gelöscht.
	 *
	 * Liegt der Plan nur in der Quelle (Zentrale), wird er dort abgeholt.
	 *
	 * @return string Pfad oder '' (kein Plan, oder Abruf/Kopie gescheitert).
	 */
	public static function anhang_datei( $post_id ) {
		if ( ! class_exists( 'BI_PDF' ) ) {
			return '';
		}
		$post_id = (int) $post_id;
		$nummer  = trim( (string) get_post_meta( $post_id, '_bi_seminarnummer', true ) );
		$bytes   = '';
		$endung  = '';

		$datei = self::datei_fuer_nummer( $nummer );
		if ( '' !== $datei ) {
			$bytes  = (string) @file_get_contents( self::verzeichnis() . '/' . $datei ); // phpcs:ignore
			$endung = strtolower( pathinfo( $datei, PATHINFO_EXTENSION ) );
		} else {
			$fremd = (string) get_post_meta( $post_id, self::META_FREMD, true );
			if ( '' === $fremd || ! wp_http_validate_url( $fremd ) ) {
				return '';
			}
			$antwort = wp_safe_remote_get( $fremd, array( 'timeout' => 15 ) );
			if ( is_wp_error( $antwort ) || 200 !== (int) wp_remote_retrieve_response_code( $antwort ) ) {
				error_log( '[BI-Themenplan] Abruf fehlgeschlagen: ' . $fremd );
				return '';
			}
			$bytes  = (string) wp_remote_retrieve_body( $antwort );
			$endung = strtolower( pathinfo( (string) wp_parse_url( $fremd, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		}
		if ( '' === $bytes || ! isset( self::ENDUNGEN[ $endung ] ) ) {
			return '';
		}
		$name = 'Themenplan' . ( '' !== $nummer ? '-' . sanitize_file_name( $nummer ) : '' ) . '.' . $endung;
		return BI_PDF::temp_datei( $bytes, $name );
	}

	/* ===================================================================
	 *  Abgleich: Änderungen am Verzeichnis an die Seminare melden
	 * =================================================================== */

	/** Fingerabdruck des Verzeichnisses: [ nummer_klein => "datei|mtime|größe" ] */
	private static function fingerabdruck() {
		$dir   = self::verzeichnis();
		$stand = array();
		foreach ( self::dateien( true ) as $key => $datei ) {
			$pfad = $dir . '/' . $datei;
			$stand[ $key ] = $datei . '|' . (int) @filemtime( $pfad ) . '|' . (int) @filesize( $pfad ); // phpcs:ignore
		}
		return $stand;
	}

	/**
	 * Verzeichnis mit dem letzten Stand vergleichen; bei jedem Seminar, dessen
	 * Plan neu, ersetzt oder entfernt ist, die Änderungsmarke des Abgleichs
	 * setzen und den Seiten-Cache vormerken.
	 *
	 * Nur in einer Quelle nötig – und billig: ein scandir() und eine Option.
	 *
	 * @return int Anzahl geänderter Seminarnummern.
	 */
	public static function abgleichen() {
		$jetzt = self::fingerabdruck();
		$alt   = get_option( self::OPTION_STAND, array() );
		$alt   = is_array( $alt ) ? $alt : array();
		if ( $jetzt === $alt ) {
			return 0;
		}

		$geaendert = array();
		foreach ( array_unique( array_merge( array_keys( $jetzt ), array_keys( $alt ) ) ) as $key ) {
			if ( ( $jetzt[ $key ] ?? '' ) !== ( $alt[ $key ] ?? '' ) ) {
				$geaendert[] = (string) $key;
			}
		}
		update_option( self::OPTION_STAND, $jetzt, false );

		if ( $geaendert ) {
			$quelle = class_exists( 'BI_Sync' ) && BI_Sync::ist_quelle();
			foreach ( self::posts_zu_nummern( $geaendert ) as $post_id ) {
				if ( $quelle ) {
					BI_Sync::markieren( $post_id );
				}
				if ( class_exists( 'BI_Cache' ) ) {
					BI_Cache::vormerken_post( $post_id );
				}
			}
		}
		return count( $geaendert );
	}

	/**
	 * Seminare (beide Formen, alle Status außer Papierkorb) zu einer Liste von
	 * Nummern – ohne Rücksicht auf Groß-/Kleinschreibung.
	 *
	 * @param string[] $nummern kleingeschriebene Nummern
	 * @return int[]
	 */
	private static function posts_zu_nummern( $nummern ) {
		global $wpdb;
		$nummern = array_values( array_filter( array_map( 'strval', (array) $nummern ), 'strlen' ) );
		if ( ! $nummern ) {
			return array();
		}
		$pts   = bi_seminar_post_types();
		$pt_in = implode( ',', array_fill( 0, count( $pts ), '%s' ) );
		$nr_in = implode( ',', array_fill( 0, count( $nummern ), '%s' ) );
		$sql   = "SELECT p.ID FROM {$wpdb->posts} p
		           INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_bi_seminarnummer'
		           WHERE p.post_type IN ($pt_in)
		             AND p.post_status NOT IN ('trash', 'auto-draft')
		             AND LOWER(TRIM(m.meta_value)) IN ($nr_in)";
		// phpcs:ignore WordPress.DB.PreparedSQL
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $pts, $nummern ) ) ) );
	}

	/**
	 * Zuordnung für die Verwaltung: [ nummer_klein => [ [id, titel, form, status], … ] ]
	 */
	private static function zuordnung( $nummern ) {
		$ergebnis = array();
		foreach ( self::posts_zu_nummern( $nummern ) as $post_id ) {
			$key = self::schluessel( get_post_meta( $post_id, '_bi_seminarnummer', true ) );
			$ergebnis[ $key ][] = array(
				'id'     => $post_id,
				'titel'  => get_the_title( $post_id ),
				'form'   => bi_is_online( $post_id ) ? 'Online' : 'Präsenz',
				'status' => get_post_status( $post_id ),
			);
		}
		return $ergebnis;
	}

	/* ===================================================================
	 *  Zentrale: Adresse aus dem Paket übernehmen
	 * =================================================================== */

	/**
	 * Mitgelieferte Adresse am Seminar der Zentrale speichern oder – wenn die
	 * Quelle keinen Plan (mehr) hat – entfernen.
	 */
	public static function fremd_speichern( $post_id, $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' !== $url && wp_http_validate_url( $url ) ) {
			update_post_meta( (int) $post_id, self::META_FREMD, $url );
		} else {
			delete_post_meta( (int) $post_id, self::META_FREMD );
		}
	}

	/* ===================================================================
	 *  Verwaltung (Datenpflege → Themenpläne)
	 * =================================================================== */

	private static function seite_url( $msg = '' ) {
		$args = array( 'page' => BI_Datenpflege::PAGE, 'tab' => self::TAB );
		if ( '' !== $msg ) {
			$args['bi_msg'] = rawurlencode( $msg );
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function render_section() {
		self::verzeichnis_anlegen();
		self::abgleichen();

		$dir     = self::verzeichnis();
		$dateien = self::dateien( true );
		ksort( $dateien, SORT_NATURAL );
		$zu      = self::zuordnung( array_keys( $dateien ) );
		$max     = size_format( wp_max_upload_size() );
		$grenzen = self::grenzen();
		?>
		<p>Themenpläne werden der Seminarnummer nach zugeordnet: Die Datei
		   <code>B00027030.pdf</code> erscheint auf der Detailseite jedes Seminars mit der Nummer
		   <strong>B00027030</strong> (Präsenz und Online). Groß-/Kleinschreibung spielt keine Rolle.
		   Erlaubt sind PDF, Word (.docx, .doc) und OpenDocument (.odt); gibt es zu einer Nummer
		   mehrere Dateien, wird das PDF gezeigt.</p>
		<p>Verzeichnis auf dem Server: <code><?php echo esc_html( $dir ); ?></code> – Dateien können
		   auch per FTP dort abgelegt werden.</p>

		<div class="card" style="max-width:100%;margin:0 0 20px">
			<h2 style="margin-top:0">Themenpläne hochladen</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="bi-tp-form"
			      data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			      data-fertig="<?php echo esc_url( self::seite_url() ); ?>"
			      data-max-anzahl="<?php echo (int) $grenzen['anzahl']; ?>"
			      data-max-post="<?php echo (int) $grenzen['post']; ?>"
			      data-max-datei="<?php echo (int) $grenzen['datei']; ?>">
				<input type="hidden" name="action" value="bi_themenplan_hochladen">
				<?php wp_nonce_field( 'bi_themenplan_hochladen' ); ?>
				<p><input type="file" name="bi_themenplan[]" multiple accept=".pdf,.docx,.doc,.odt"></p>
				<p class="description">Beliebig viele Dateien auf einmal – sie werden in Paketen zu höchstens
				   <?php echo (int) $grenzen['anzahl']; ?> Dateien übertragen. Dateiname = Seminarnummer.
				   Eine vorhandene Datei zur selben Nummer wird ersetzt. Höchstens <?php echo esc_html( $max ); ?> je Datei.</p>
				<?php submit_button( 'Hochladen', 'primary', 'submit', false ); ?>
				<span id="bi-tp-status" style="margin-left:10px"></span>
			</form>
			<div id="bi-tp-ergebnis"></div>
		</div>
		<script>
		/*
		 * PHP nimmt je Anfrage nur max_file_uploads Dateien an (meist 20) und
		 * verwirft den Rest OHNE Fehlermeldung; übersteigt die Anfrage
		 * post_max_size, kommt gar nichts an. Deshalb schickt der Browser die
		 * Auswahl in Paketen, die beide Grenzen einhalten. Ohne JavaScript
		 * bleibt das normale Formular – mit dem Hinweis auf die Grenze.
		 */
		( function () {
			var form = document.getElementById( 'bi-tp-form' );
			if ( ! form || ! window.fetch || ! window.FormData ) { return; }
			var status = document.getElementById( 'bi-tp-status' );
			var ausgabe = document.getElementById( 'bi-tp-ergebnis' );
			var maxAnzahl = parseInt( form.dataset.maxAnzahl, 10 ) || 20;
			var maxPost = parseInt( form.dataset.maxPost, 10 ) || 8388608;
			var maxDatei = parseInt( form.dataset.maxDatei, 10 ) || maxPost;
			var reserve = 64 * 1024; // Formularfelder und Trennzeilen

			form.addEventListener( 'submit', function ( e ) {
				var input = form.querySelector( 'input[type=file]' );
				var files = Array.prototype.slice.call( input.files || [] );
				if ( ! files.length ) { return; }
				e.preventDefault();

				var zuGross = [], pakete = [], paket = [], groesse = 0;
				files.forEach( function ( f ) {
					if ( f.size > maxDatei || f.size + reserve > maxPost ) {
						zuGross.push( f.name + ' (zu groß)' );
						return;
					}
					if ( paket.length && ( paket.length >= maxAnzahl || groesse + f.size + reserve > maxPost ) ) {
						pakete.push( paket ); paket = []; groesse = 0;
					}
					paket.push( f ); groesse += f.size;
				} );
				if ( paket.length ) { pakete.push( paket ); }

				var knopf = form.querySelector( '[type=submit]' );
				knopf.disabled = true;
				var ok = 0, fehl = zuGross.slice(), gesendet = 0;
				var nonce = form.querySelector( '[name=_wpnonce]' ).value;

				function weiter( i ) {
					if ( i >= pakete.length ) { return fertig(); }
					status.textContent = 'Übertrage … ' + gesendet + ' von ' + files.length + ' Dateien';
					var fd = new FormData();
					fd.append( 'action', 'bi_themenplan_paket' );
					fd.append( '_wpnonce', nonce );
					pakete[ i ].forEach( function ( f ) { fd.append( 'bi_themenplan[]', f, f.name ); } );
					fetch( form.dataset.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( j ) {
							if ( ! j || ! j.success ) { throw new Error( ( j && j.data ) || 'Fehler' ); }
							ok += j.data.ok.length;
							fehl = fehl.concat( j.data.fehl );
						} )
						.catch( function ( err ) {
							pakete[ i ].forEach( function ( f ) { fehl.push( f.name + ' (' + err.message + ')' ); } );
						} )
						.then( function () { gesendet += pakete[ i ].length; weiter( i + 1 ); } );
				}

				function fertig() {
					var msg = ok + ' Themenplan/-pläne gespeichert.';
					if ( ! fehl.length ) {
						window.location.href = form.dataset.fertig + '&bi_msg=' + encodeURIComponent( msg );
						return;
					}
					status.textContent = '';
					knopf.disabled = false;
					var html = '<div class="notice notice-warning inline"><p><strong>' + msg + ' Nicht übernommen (' + fehl.length + '):</strong></p><ul style="list-style:disc;margin-left:20px">';
					fehl.forEach( function ( t ) {
						html += '<li>' + String( t ).replace( /[&<>"]/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ]; } ) + '</li>';
					} );
					html += '</ul><p><a href="' + form.dataset.fertig + '">Liste neu laden</a></p></div>';
					ausgabe.innerHTML = html;
				}

				weiter( 0 );
			} );
		} )();
		</script>

		<h2>Vorhandene Themenpläne (<?php echo (int) count( $dateien ); ?>)</h2>
		<?php if ( ! $dateien ) : ?>
			<p>Noch keine Themenpläne im Verzeichnis.</p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Datei</th><th>Seminar</th><th style="width:1%"></th></tr></thead>
				<tbody>
				<?php foreach ( $dateien as $key => $datei ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::url_basis() . '/' . rawurlencode( $datei ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $datei ); ?></a></td>
						<td>
							<?php if ( empty( $zu[ $key ] ) ) : ?>
								<span style="color:#b32d2e">kein Seminar mit dieser Nummer</span>
							<?php else : ?>
								<?php foreach ( $zu[ $key ] as $s ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $s['id'] ) ?: get_permalink( $s['id'] ) ); ?>"><?php echo esc_html( $s['titel'] ); ?></a>
									<span class="description">(<?php echo esc_html( $s['form'] ); ?><?php echo 'publish' !== $s['status'] ? ', ' . esc_html( $s['status'] ) : ''; ?>)</span><br>
								<?php endforeach; ?>
							<?php endif; ?>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Diesen Themenplan wirklich löschen?');">
								<input type="hidden" name="action" value="bi_themenplan_loeschen">
								<input type="hidden" name="datei" value="<?php echo esc_attr( $datei ); ?>">
								<?php wp_nonce_field( 'bi_themenplan_loeschen' ); ?>
								<button type="submit" class="button button-link-delete">Löschen</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Server-Grenzen für eine Upload-Anfrage:
	 *   anzahl  max_file_uploads – mehr Dateien verwirft PHP stillschweigend
	 *   post    post_max_size    – darüber kommt die ganze Anfrage leer an
	 *   datei   größte erlaubte Einzeldatei (wp_max_upload_size)
	 */
	public static function grenzen() {
		$anzahl = (int) ini_get( 'max_file_uploads' );
		$post   = (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		return array(
			'anzahl' => $anzahl > 0 ? $anzahl : 20,
			'post'   => $post > 0 ? $post : (int) wp_max_upload_size(),
			'datei'  => (int) wp_max_upload_size(),
		);
	}

	/**
	 * Hochgeladene Dateien prüfen und ablegen.
	 *
	 * @param array $files Eintrag aus $_FILES (Mehrfachfeld bi_themenplan[])
	 * @return array{0:string[],1:string[]} [ gespeichert, nicht übernommen ]
	 */
	private static function speichern( $files ) {
		$dir  = self::verzeichnis();
		$ok   = array();
		$fehl = array();

		if ( ! is_array( $files ) || ! isset( $files['name'] ) || ! is_array( $files['name'] ) ) {
			return array( $ok, $fehl );
		}
		foreach ( $files['name'] as $i => $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}
			$err = (int) $files['error'][ $i ];
			if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
				$fehl[] = $name . ' (zu groß)';
				continue;
			}
			if ( UPLOAD_ERR_OK !== $err || ! is_uploaded_file( $files['tmp_name'][ $i ] ) ) {
				$fehl[] = $name . ' (Upload fehlgeschlagen)';
				continue;
			}
			$endung = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$nummer = trim( pathinfo( $name, PATHINFO_FILENAME ) );
			if ( ! isset( self::ENDUNGEN[ $endung ] ) ) {
				$fehl[] = $name . ' (Format nicht erlaubt)';
				continue;
			}
			// Nur Buchstaben, Ziffern, Bindestrich, Unterstrich, Punkt – eine
			// Seminarnummer braucht nicht mehr, und alles andere wäre eine
			// Einladung, den Pfad zu verlassen.
			if ( '' === $nummer || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $nummer ) ) {
				$fehl[] = $name . ' (Dateiname ist keine Seminarnummer)';
				continue;
			}
			$check = wp_check_filetype_and_ext( $files['tmp_name'][ $i ], $name, self::ENDUNGEN );
			if ( empty( $check['ext'] ) ) {
				$fehl[] = $name . ' (Inhalt passt nicht zur Endung)';
				continue;
			}

			// Ältere Fassungen zur selben Nummer entfernen – auch mit anderer
			// Endung oder Schreibweise, sonst gewänne womöglich die alte.
			self::nummer_entfernen( $nummer );

			$ziel = $dir . '/' . $nummer . '.' . $endung;
			if ( ! @move_uploaded_file( $files['tmp_name'][ $i ], $ziel ) ) { // phpcs:ignore
				$fehl[] = $name . ' (konnte nicht gespeichert werden)';
				continue;
			}
			@chmod( $ziel, 0644 ); // phpcs:ignore
			$ok[] = $nummer . '.' . $endung;
		}

		self::dateien( true );
		self::abgleichen();
		return array( $ok, $fehl );
	}

	/** Ein Paket aus dem Browser (siehe Skript in render_section). Antwort JSON. */
	public static function ajax_paket() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_send_json_error( 'Keine Berechtigung', 403 );
		}
		check_ajax_referer( 'bi_themenplan_hochladen' );
		if ( ! self::verzeichnis_anlegen() ) {
			wp_send_json_error( 'Verzeichnis fehlt' );
		}
		// Leeres $_FILES trotz Auswahl: Die Anfrage war größer als post_max_size.
		if ( empty( $_FILES['bi_themenplan'] ) ) { // phpcs:ignore WordPress.Security
			wp_send_json_error( 'Paket kam leer an – Server-Grenze post_max_size' );
		}
		list( $ok, $fehl ) = self::speichern( $_FILES['bi_themenplan'] ); // phpcs:ignore WordPress.Security
		wp_send_json_success( array( 'ok' => $ok, 'fehl' => $fehl ) );
	}

	/** Hochladen ohne JavaScript: ein Formular, eine Anfrage. */
	public static function handle_hochladen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_themenplan_hochladen' );

		if ( ! self::verzeichnis_anlegen() ) {
			wp_safe_redirect( self::seite_url( 'Das Verzeichnis für Themenpläne konnte nicht angelegt werden.' ) );
			exit;
		}
		$files = $_FILES['bi_themenplan'] ?? null; // phpcs:ignore WordPress.Security
		list( $ok, $fehl ) = self::speichern( $files );

		$msg = array();
		if ( $ok ) {
			$msg[] = count( $ok ) . ' Themenplan/-pläne gespeichert.';
		}
		if ( $fehl ) {
			$msg[] = 'Nicht übernommen: ' . implode( '; ', $fehl );
		}
		// Genau so viele Dateien wie max_file_uploads: Sehr wahrscheinlich hat
		// PHP weitere verworfen, ohne es zu melden.
		$anzahl = is_array( $files ) && isset( $files['name'] ) ? count( (array) $files['name'] ) : 0;
		$grenze = self::grenzen()['anzahl'];
		if ( $anzahl >= $grenze ) {
			$msg[] = 'Achtung: Der Server nimmt höchstens ' . $grenze . ' Dateien je Vorgang an – weitere wurden womöglich verworfen. Bitte die übrigen in einem zweiten Durchgang hochladen.';
		}
		if ( ! $msg ) {
			$msg[] = 'Keine Datei angekommen. Entweder war keine ausgewählt oder die Auswahl war größer als die Server-Grenze (' . size_format( self::grenzen()['post'] ) . ' je Vorgang).';
		}
		wp_safe_redirect( self::seite_url( implode( ' ', $msg ) ) );
		exit;
	}

	public static function handle_loeschen() {
		if ( ! current_user_can( BI_CAP ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_themenplan_loeschen' );

		$datei = isset( $_POST['datei'] ) ? (string) wp_unslash( $_POST['datei'] ) : '';
		// Nur ein Name aus der aktuellen Liste wird gelöscht – nie ein Pfad.
		if ( '' === $datei || ! in_array( $datei, self::dateien( true ), true ) ) {
			wp_safe_redirect( self::seite_url( 'Datei nicht gefunden.' ) );
			exit;
		}
		$ok = @unlink( self::verzeichnis() . '/' . $datei ); // phpcs:ignore

		self::dateien( true );
		self::abgleichen();

		wp_safe_redirect( self::seite_url( $ok ? 'Themenplan ' . $datei . ' gelöscht.' : 'Löschen fehlgeschlagen: ' . $datei ) );
		exit;
	}

	/** Alle Dateien zu einer Nummer löschen (jede erlaubte Endung, jede Schreibweise). */
	private static function nummer_entfernen( $nummer ) {
		$dir = self::verzeichnis();
		$key = self::schluessel( $nummer );
		$namen = @scandir( $dir ); // phpcs:ignore
		if ( ! is_array( $namen ) ) {
			return;
		}
		foreach ( $namen as $name ) {
			if ( '.' === $name[0] ) {
				continue;
			}
			$endung = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( isset( self::ENDUNGEN[ $endung ] ) && self::schluessel( pathinfo( $name, PATHINFO_FILENAME ) ) === $key ) {
				@unlink( $dir . '/' . $name ); // phpcs:ignore
			}
		}
	}
}
