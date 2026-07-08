<?php
/**
 * Marketing-Kacheln – Shortcode [bi_kachel] + Backend-Builder mit Live-Vorschau.
 *
 * Eine Kachel ist ein klickbarer Teaser (Bild + Überschrift + Text + Button), der
 * auf die Seminarübersicht mit vorbefüllten Filtern verlinkt. Sie füllt immer die
 * volle Breite ihres Containers – die Größe bestimmt die umgebende Box (z. B. eine
 * Elementor-Spalte), in die der Shortcode eingefügt wird.
 *
 * Layouts:
 *   layout="1"  Bild oben, Text darunter (Standard)
 *   layout="2"  Text liegt über dem Bild (Overlay mit dunklem Verlauf)
 *
 * Filter-Attribute (entsprechen 1:1 den GET-Parametern der Suche, Mehrfachwerte
 * pipe-getrennt): q, ort, thema, ziel, frei, von, bis, nr (Seminarnummern).
 *
 * Weitere Attribute:
 *   bild         Attachment-ID oder Bild-URL
 *   ratio        Bildausschnitt-Seitenverhältnis, z. B. "16:9", "1:1", "21:9";
 *                "auto" = Bild nicht beschneiden (nur Layout 1); Standard 16:10
 *   fokus        Fokuspunkt des Zuschnitts als "x% y%" (z. B. "50% 20%" = oben mittig);
 *                im Builder per Klick ins Bild setzbar
 *   titel        Überschrift der Kachel
 *   text         Beschreibungstext
 *   button       Button-Beschriftung (Standard: "Zu den Seminaren")
 *   ueberschrift "h1" | "h2" | "h3" – HTML-Tag der Überschrift (Standard: h3)
 *   url          feste Ziel-URL statt gebautem Filter-Link (Filter-Attribute werden ignoriert)
 *   suche_url    Suchseite überschreiben (Standard: Einstellung "Seminarübersicht")
 *   programm     Programmjahr nur für den Redaktions-Zähler (muss zum programm-Attribut
 *                des [bi_seminarsuche]-Shortcodes auf der Zielseite passen)
 *
 * Backend: Menüpunkt "Bildungsprogramm → Kacheln" – Kachel gestalten, Filter per
 * Klick auswählen, Live-Vorschau sehen und den fertigen Shortcode kopieren.
 *
 * Redaktions-Zähler: Eingeloggte Nutzer*innen mit edit_posts sehen auf jeder Kachel
 * ein Badge mit der aktuellen Trefferzahl des Links. Besucher sehen es nicht – für
 * sie wird auch keine Zähl-Query ausgeführt.
 *
 * [bi_kacheln spalten="2|3|4"] ... [/bi_kacheln] bleibt als optionaler Grid-Container
 * erhalten, falls mehrere Kacheln ohne Page-Builder nebeneinander stehen sollen.
 *
 * Vorgefertigte Themen-Kacheln: Backend-Seite "Kachel-Vorlagen" ordnet jedem
 * Themenfeld ein Mediathek-Bild zu; [bi_kachel_vorlagen spalten="2|3|4"] rendert
 * alle zugeordneten Kacheln als Grid (Overlay, nur Filter-Label als Überschrift).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Kacheln {

	/** Option: Themenfeld-Term-ID => Attachment-ID (Mediathek) für vorgefertigte Kacheln */
	const OPTION_VORLAGEN = 'bi_kachel_vorlagen';

	/** Hook-Suffix der Builder-Seite (für gezieltes Asset-Laden) */
	private static $hook = '';

	/** Hook-Suffix der Vorlagen-Seite */
	private static $hook_vorlagen = '';

	/** Filter-Attribute, die 1:1 als GET-Parameter an die Suchseite gehen */
	private static function filter_params() {
		return array( 'q', 'ort', 'thema', 'ziel', 'frei', 'von', 'bis', 'nr' );
	}

	/** Alle Felder, die der Builder an die Vorschau schickt */
	private static function builder_fields() {
		return array_merge(
			array( 'layout', 'ueberschrift', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'programm', 'suche_url' ),
			self::filter_params()
		);
	}

	public static function init() {
		add_shortcode( 'bi_kacheln', array( __CLASS__, 'grid' ) );
		add_shortcode( 'bi_kachel', array( __CLASS__, 'tile' ) );
		add_shortcode( 'bi_kachel_vorlagen', array( __CLASS__, 'vorlagen_grid' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		// Nach BI_Admin::menu (Prio 10) einhängen, damit der Hauptmenüpunkt existiert
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'wp_ajax_bi_kachel_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'admin_post_bi_save_kachel_vorlagen', array( __CLASS__, 'save_vorlagen' ) );
	}

	public static function register_assets() {
		wp_register_style( 'bi-kacheln', BI_URL . 'assets/css/kacheln.css', array(), BI_VERSION );
	}

	/** ---------- [bi_kacheln] – optionaler Grid-Container ---------- */

	public static function grid( $atts, $content = '' ) {
		$atts = shortcode_atts( array(
			'spalten' => '3',
		), $atts, 'bi_kacheln' );

		$spalten = in_array( $atts['spalten'], array( '2', '3', '4' ), true ) ? $atts['spalten'] : '3';

		wp_enqueue_style( 'bi-kacheln' );

		return '<div class="bi-kacheln bi-kacheln-' . esc_attr( $spalten ) . '">'
			. do_shortcode( $content )
			. '</div>';
	}

	/** ---------- [bi_kachel] – einzelne Kachel ---------- */

	public static function tile( $atts ) {
		$defaults = array(
			'bild'         => '',
			'ratio'        => '',
			'fokus'        => '',
			'titel'        => '',
			'text'         => '',
			'button'       => 'Zu den Seminaren',
			'layout'       => '1',
			'ueberschrift' => 'h3',
			'url'          => '',
			'suche_url'    => '',
			'programm'     => '',
		);
		foreach ( self::filter_params() as $p ) {
			$defaults[ $p ] = '';
		}
		$atts = shortcode_atts( $defaults, $atts, 'bi_kachel' );

		wp_enqueue_style( 'bi-kacheln' );

		// Layout: "1"/"standard" = Bild oben, "2"/"overlay" = Text über dem Bild
		$layout = in_array( trim( (string) $atts['layout'] ), array( '2', 'overlay' ), true ) ? 'overlay' : 'standard';
		$h_tag  = in_array( $atts['ueberschrift'], array( 'h1', 'h2', 'h3' ), true ) ? $atts['ueberschrift'] : 'h3';

		// Filter-Parameter einsammeln (Rohwerte, unkodiert)
		$params = array();
		foreach ( self::filter_params() as $p ) {
			$v = trim( (string) $atts[ $p ] );
			if ( '' !== $v ) {
				$params[ $p ] = $v;
			}
		}

		// Link-Ziel: feste URL oder Suchseite + Filter-Parameter
		if ( '' !== trim( $atts['url'] ) ) {
			$href = trim( $atts['url'] );
		} else {
			$base = trim( $atts['suche_url'] );
			if ( '' === $base && class_exists( 'BI_Registration' ) ) {
				// Einstellung „Seminarübersicht", sonst Seite mit [bi_seminarsuche] automatisch finden
				$base = BI_Registration::uebersicht_url();
			}
			if ( '' === $base ) {
				$base = home_url( '/' );
			}
			// Werte selbst kodieren (add_query_arg kodiert nicht): Pipe/Umlaute/Leerzeichen
			$href = $params ? add_query_arg( array_map( 'rawurlencode', $params ), $base ) : $base;
		}

		// Bildausschnitt: Seitenverhältnis (ratio) + Fokuspunkt (fokus) als Inline-Styles
		$ratio_css = '';
		$ratio_raw = trim( (string) $atts['ratio'] );
		if ( 'auto' === $ratio_raw ) {
			$ratio_css = 'auto';
		} elseif ( preg_match( '/^(\d{1,3})\s*[:\/]\s*(\d{1,3})$/', $ratio_raw, $m ) && (int) $m[2] > 0 ) {
			$ratio_css = $m[1] . ' / ' . $m[2];
		}
		$fokus_css = '';
		if ( preg_match( '/^(\d{1,3})%\s+(\d{1,3})%$/', trim( (string) $atts['fokus'] ), $m ) ) {
			$fokus_css = min( 100, (int) $m[1] ) . '% ' . min( 100, (int) $m[2] ) . '%';
		}

		$img_style  = $fokus_css ? 'object-position:' . $fokus_css . ';' : '';
		$tile_style = '';
		if ( $ratio_css ) {
			if ( 'overlay' === $layout ) {
				// Overlay: das Verhältnis bestimmt die ganze Kachel ("auto" ist hier nicht sinnvoll)
				if ( 'auto' !== $ratio_css ) {
					$tile_style = 'aspect-ratio:' . $ratio_css . ';min-height:0;';
				}
			} else {
				$img_style .= 'aspect-ratio:' . $ratio_css . ';';
			}
		}

		// Bild: Attachment-ID (Media-Bibliothek) oder direkte URL
		$img_html = '';
		$bild     = trim( $atts['bild'] );
		if ( '' !== $bild ) {
			if ( ctype_digit( $bild ) ) {
				$img_atts = array(
					'class'   => 'bi-kachel-img',
					'alt'     => $atts['titel'],
					'loading' => 'lazy',
				);
				if ( $img_style ) {
					$img_atts['style'] = $img_style;
				}
				$img_html = wp_get_attachment_image( (int) $bild, 'large', false, $img_atts );
			} else {
				$img_html = '<img class="bi-kachel-img" src="' . esc_url( $bild ) . '" alt="' . esc_attr( $atts['titel'] ) . '"'
					. ( $img_style ? ' style="' . esc_attr( $img_style ) . '"' : '' ) . ' loading="lazy">';
			}
		}

		// Redaktions-Zähler: nur für eingeloggte Redakteur*innen, nur bei gebauten Filter-Links
		$badge = '';
		if ( '' === trim( $atts['url'] ) && current_user_can( 'edit_posts' ) && class_exists( 'BI_Filter' ) ) {
			$count = BI_Filter::count_for_params( $params, sanitize_text_field( $atts['programm'] ) );
			$label = sprintf( '%s buchbare%s Seminar%s', number_format_i18n( $count ), 1 === $count ? 's' : '', 1 === $count ? '' : 'e' );
			$badge = '<span class="bi-kachel-count' . ( $count > 0 ? '' : ' bi-kachel-count-0' ) . '"'
				. ' title="Trefferzahl dieses Kachel-Links – nur für eingeloggte Redakteur*innen sichtbar">'
				. esc_html( $label ) . '</span>';
		}

		ob_start();
		?>
		<a class="bi-kachel bi-kachel-<?php echo esc_attr( $layout ); ?>" href="<?php echo esc_url( $href ); ?>"<?php echo $tile_style ? ' style="' . esc_attr( $tile_style ) . '"' : ''; ?>>
			<?php if ( $img_html ) : ?>
				<span class="bi-kachel-media"><?php echo $img_html; ?></span>
			<?php endif; ?>
			<span class="bi-kachel-body">
				<?php if ( '' !== trim( $atts['titel'] ) ) : ?>
					<<?php echo $h_tag; ?> class="bi-kachel-titel"><?php echo esc_html( $atts['titel'] ); ?></<?php echo $h_tag; ?>>
				<?php endif; ?>
				<?php if ( '' !== trim( $atts['text'] ) ) : ?>
					<span class="bi-kachel-text"><?php echo esc_html( $atts['text'] ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== trim( $atts['button'] ) ) : ?>
					<span class="bi-kachel-btn"><?php echo esc_html( $atts['button'] ); ?></span>
				<?php endif; ?>
			</span>
			<?php echo $badge; ?>
		</a>
		<?php
		return ob_get_clean();
	}

	/** ---------- Shortcode-String aus Builder-Feldern bauen ---------- */

	private static function build_shortcode( $atts ) {
		$order    = array( 'layout', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'ueberschrift', 'q', 'ort', 'thema', 'ziel', 'frei', 'von', 'bis', 'nr', 'programm', 'suche_url' );
		$defaults = array( 'button' => 'Zu den Seminaren', 'ueberschrift' => 'h3' );
		$parts    = array( 'bi_kachel' );
		foreach ( $order as $k ) {
			$v = isset( $atts[ $k ] ) ? trim( (string) $atts[ $k ] ) : '';
			if ( 'layout' === $k ) {
				$parts[] = 'layout="' . ( in_array( $v, array( '2', 'overlay' ), true ) ? '2' : '1' ) . '"';
				continue;
			}
			if ( '' === $v || ( isset( $defaults[ $k ] ) && $v === $defaults[ $k ] ) ) {
				continue;
			}
			// Doppelte Anführungszeichen würden das Shortcode-Attribut sprengen
			$parts[] = $k . '="' . str_replace( '"', "'", $v ) . '"';
		}
		return '[' . implode( ' ', $parts ) . ']';
	}

	/** ===================================================================
	 *  Backend: Kachel-Builder mit Live-Vorschau
	 * =================================================================== */

	public static function menu() {
		self::$hook = add_submenu_page(
			'bi-seminarsuche',
			'Marketing-Kacheln',
			'Marketing-Kacheln',
			'manage_options',
			'bi-kacheln',
			array( __CLASS__, 'render_builder' )
		);
		self::$hook_vorlagen = add_submenu_page(
			'bi-seminarsuche',
			'Kachel-Vorlagen',
			'Kachel-Vorlagen',
			'manage_options',
			'bi-kachel-vorlagen',
			array( __CLASS__, 'render_vorlagen' )
		);
	}

	public static function admin_assets( $hook ) {
		if ( $hook === self::$hook_vorlagen ) {
			wp_enqueue_media(); // Bildauswahl aus der Mediathek
			wp_enqueue_style( 'bi-kacheln', BI_URL . 'assets/css/kacheln.css', array(), BI_VERSION );
			return;
		}
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_media(); // Mediathek-Auswahl für das Kachel-Bild
		wp_enqueue_style( 'bi-kacheln', BI_URL . 'assets/css/kacheln.css', array(), BI_VERSION );
		wp_enqueue_script( 'bi-kachel-builder', BI_URL . 'assets/js/kachel-builder.js', array(), BI_VERSION, true );
		wp_add_inline_script(
			'bi-kachel-builder',
			'window.biKachelBuilder = ' . wp_json_encode( array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bi_kachel_preview' ),
			) ) . ';',
			'before'
		);
	}

	/** AJAX: Vorschau-HTML + fertigen Shortcode für die aktuellen Builder-Felder liefern */
	public static function ajax_preview() {
		check_ajax_referer( 'bi_kachel_preview' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( 'Keine Berechtigung.' );
		}
		$atts = array();
		foreach ( self::builder_fields() as $k ) {
			$atts[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';
		}
		wp_send_json_success( array(
			'html'      => self::tile( $atts ),
			'shortcode' => self::build_shortcode( $atts ),
		) );
	}

	public static function render_builder() {
		$choices = class_exists( 'BI_Filter' ) ? BI_Filter::facet_choices() : array();
		$labels  = array( 'ort' => 'Bildungszentrum', 'thema' => 'Themenfeld', 'ziel' => 'Zielgruppe', 'frei' => 'Freistellung' );

		$programme = get_terms( array( 'taxonomy' => BI_TAX_PROGRAMM, 'hide_empty' => false ) );
		if ( is_wp_error( $programme ) ) {
			$programme = array();
		}
		?>
		<style>
			.bi-kb-layout { display: flex; gap: 28px; align-items: flex-start; flex-wrap: wrap; margin-top: 12px; }
			.bi-kb-form { flex: 1 1 480px; max-width: 640px; background: #fff; border: 1px solid #ccd0d4; padding: 4px 20px 20px; }
			.bi-kb-side { flex: 1 1 380px; max-width: 620px; position: sticky; top: 46px; }
			.bi-kb-form h2 { font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 6px; }
			.bi-kb-field { margin: 0 0 14px; }
			.bi-kb-field label.bi-kb-label { display: block; font-weight: 600; margin-bottom: 4px; }
			.bi-kb-field input[type=text], .bi-kb-field input[type=url], .bi-kb-field textarea, .bi-kb-field select { width: 100%; }
			.bi-kb-radio label { margin-right: 18px; }
			.bi-kb-facet { border: 1px solid #dcdcde; background: #fbfbfc; max-height: 170px; overflow: auto; padding: 8px 10px; border-radius: 4px; }
			.bi-kb-facet label { display: block; margin: 2px 0; }
			.bi-kb-cols { display: flex; gap: 16px; flex-wrap: wrap; }
			.bi-kb-cols > div { flex: 1 1 220px; }
			#bi-kb-bild-box { position: relative; display: inline-block; cursor: crosshair; margin: 6px 0; line-height: 0; }
			#bi-kb-bild-box img { display: block; max-width: 240px; height: auto; border: 1px solid #dcdcde; border-radius: 4px; }
			#bi-kb-fokus-marker { position: absolute; width: 18px; height: 18px; border: 2px solid #fff; outline: 1px solid #2271b1; border-radius: 50%; background: rgba(34, 113, 177, .4); transform: translate(-50%, -50%); pointer-events: none; display: none; box-shadow: 0 0 4px rgba(0,0,0,.4); }
			.bi-kb-preview-box { background: #f0f0f1; border: 1px dashed #c3c4c7; padding: 22px; border-radius: 6px; }
			#bi-kb-preview { margin: 0 auto; transition: max-width .2s; }
			.bi-kb-widths { margin-bottom: 10px; display: flex; gap: 6px; align-items: center; }
			.bi-kb-widths .button.active { border-color: #2271b1; color: #2271b1; box-shadow: inset 0 0 0 1px #2271b1; }
			#bi-kb-shortcode { font-family: Consolas, Monaco, monospace; margin-top: 6px; }
			.bi-kb-copied { color: #00a32a; font-weight: 600; margin-left: 8px; }
		</style>

		<div class="wrap">
			<h1>Marketing-Kacheln</h1>
			<p>Gestalte hier eine Kachel, stelle die Filter ein und beobachte rechts die Live-Vorschau.
				Den fertigen Shortcode kopierst du unten und fügst ihn in eine beliebige Box ein
				(z.&nbsp;B. ein Shortcode-Widget in Elementor) – die Kachel füllt die Box automatisch aus.</p>

			<div class="bi-kb-layout">

				<form id="bi-kb-form" class="bi-kb-form" onsubmit="return false">

					<h2>Gestaltung</h2>

					<div class="bi-kb-field bi-kb-radio">
						<span class="bi-kb-label">Layout</span>
						<label><input type="radio" name="layout" value="1" checked> <strong>1</strong> – Bild oben, Text darunter</label>
						<label><input type="radio" name="layout" value="2"> <strong>2</strong> – Text über dem Bild (Overlay)</label>
					</div>

					<div class="bi-kb-field">
						<span class="bi-kb-label">Bild</span>
						<input type="hidden" name="bild" value="">
						<input type="hidden" name="fokus" value="">
						<div id="bi-kb-bild-box" style="display:none" title="Klick ins Bild setzt den Fokuspunkt">
							<img id="bi-kb-bild-thumb" src="" alt="">
							<span id="bi-kb-fokus-marker"></span>
						</div>
						<p class="description" id="bi-kb-fokus-hint" style="display:none">
							<strong>Klick ins Bild</strong> setzt den Fokuspunkt – er bestimmt, welcher Ausschnitt beim
							Zuschneiden sichtbar bleibt. <a href="#" id="bi-kb-fokus-reset">Fokus zurücksetzen</a>
						</p>
						<button type="button" class="button" id="bi-kb-bild-waehlen">Bild aus Mediathek wählen</button>
						<button type="button" class="button-link-delete" id="bi-kb-bild-entfernen" style="display:none">Bild entfernen</button>
					</div>

					<div class="bi-kb-field" style="max-width:320px">
						<label class="bi-kb-label" for="bi-kb-ratio">Bildausschnitt (Seitenverhältnis)</label>
						<select id="bi-kb-ratio" name="ratio">
							<option value="">16:10 (Standard)</option>
							<option value="16:9">16:9</option>
							<option value="3:2">3:2</option>
							<option value="4:3">4:3</option>
							<option value="1:1">Quadratisch (1:1)</option>
							<option value="21:9">Panorama (21:9)</option>
							<option value="auto">Bild nicht beschneiden (nur Layout 1)</option>
						</select>
					</div>

					<div class="bi-kb-cols">
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-titel">Überschrift</label>
							<input type="text" id="bi-kb-titel" name="titel" placeholder="z. B. BR kompakt">
						</div>
						<div class="bi-kb-field" style="flex:0 1 140px">
							<label class="bi-kb-label" for="bi-kb-htag">HTML-Tag</label>
							<select id="bi-kb-htag" name="ueberschrift">
								<option value="h1">h1</option>
								<option value="h2">h2</option>
								<option value="h3" selected>h3</option>
							</select>
						</div>
					</div>

					<div class="bi-kb-field">
						<label class="bi-kb-label" for="bi-kb-text">Text</label>
						<textarea id="bi-kb-text" name="text" rows="2" placeholder="Kurzer Teaser-Text …"></textarea>
					</div>

					<div class="bi-kb-field">
						<label class="bi-kb-label" for="bi-kb-button">Button-Beschriftung</label>
						<input type="text" id="bi-kb-button" name="button" value="Zu den Seminaren">
					</div>

					<h2>Filter – wohin führt der Klick?</h2>
					<p class="description" style="margin-bottom:12px">Die Kachel verlinkt auf die Seminarübersicht
						mit genau diesen vorausgewählten Filtern. Die Auswahl entspricht exakt der Filterleiste im
						Frontend (nur Einträge mit buchbaren Seminaren, Zahl = aktuelle Treffer).
						Das Badge in der Vorschau zeigt die Trefferzahl der Kombination.</p>

					<div class="bi-kb-cols">
						<?php foreach ( $labels as $param => $label ) : ?>
							<div class="bi-kb-field">
								<span class="bi-kb-label"><?php echo esc_html( $label ); ?></span>
								<div class="bi-kb-facet">
									<?php if ( empty( $choices[ $param ] ) ) : ?>
										<em>Keine Einträge vorhanden.</em>
									<?php else : ?>
										<?php foreach ( $choices[ $param ] as $opt ) : ?>
											<?php if ( ! empty( $opt['separator'] ) ) : ?>
												<hr style="margin:6px 0;border:0;border-top:1px solid #dcdcde">
											<?php else : ?>
												<label>
													<input type="checkbox" name="<?php echo esc_attr( $param ); ?>[]" value="<?php echo esc_attr( $opt['value'] ); ?>">
													<?php echo esc_html( $opt['label'] ); ?>
													<span style="color:#787c82">(<?php echo esc_html( number_format_i18n( $opt['count'] ) ); ?>)</span>
												</label>
											<?php endif; ?>
										<?php endforeach; ?>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="bi-kb-cols">
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-q">Suchbegriff (Titel)</label>
							<input type="text" id="bi-kb-q" name="q" placeholder="z. B. Datenschutz">
						</div>
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-nr">Seminarnummern (mit | getrennt)</label>
							<input type="text" id="bi-kb-nr" name="nr" placeholder="z. B. LO12345|BO67890">
						</div>
					</div>

					<div class="bi-kb-cols">
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-von">Startdatum ab</label>
							<input type="date" id="bi-kb-von" name="von">
						</div>
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-bis">Startdatum bis</label>
							<input type="date" id="bi-kb-bis" name="bis">
						</div>
					</div>

					<h2>Optional</h2>

					<div class="bi-kb-cols">
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-programm">Programmjahr (für den Zähler)</label>
							<select id="bi-kb-programm" name="programm">
								<option value="">– alle Jahrgänge –</option>
								<?php foreach ( $programme as $p ) : ?>
									<option value="<?php echo esc_attr( $p->name ); ?>"><?php echo esc_html( $p->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">Nur nötig, wenn die Zielseite <code>[bi_seminarsuche programm="…"]</code> nutzt.</p>
						</div>
						<div class="bi-kb-field">
							<label class="bi-kb-label" for="bi-kb-suche-url">Andere Suchseite (URL)</label>
							<input type="url" id="bi-kb-suche-url" name="suche_url" placeholder="Standard: Einstellung „Seminarübersicht"">
						</div>
					</div>

				</form>

				<div class="bi-kb-side">
					<div class="bi-kb-widths">
						<span style="font-weight:600">Vorschau-Breite:</span>
						<button type="button" class="button" data-width="300">schmal</button>
						<button type="button" class="button active" data-width="400">mittel</button>
						<button type="button" class="button" data-width="560">breit</button>
					</div>
					<div class="bi-kb-preview-box">
						<div id="bi-kb-preview" style="max-width:400px">
							<div id="bi-kb-preview-inner"><em>Vorschau wird geladen …</em></div>
						</div>
					</div>

					<h2 style="margin-bottom:0">Shortcode</h2>
					<textarea id="bi-kb-shortcode" class="large-text code" rows="4" readonly></textarea>
					<p>
						<button type="button" class="button button-primary" id="bi-kb-copy">Shortcode kopieren</button>
						<span class="bi-kb-copied" id="bi-kb-copied" style="display:none">Kopiert ✓</span>
					</p>
				</div>

			</div>
		</div>
		<?php
	}

	/** ===================================================================
	 *  Vorgefertigte Themen-Kacheln (Vorlagen)
	 *
	 *  Pro Themenfeld wird ein lizenziertes Bild aus der Mediathek zugeordnet
	 *  (Option OPTION_VORLAGEN: term_id => attachment_id). Daraus entstehen
	 *  fertige Kacheln im Overlay-Layout, ohne Teaser-Text, mit dem
	 *  Filter-Label als Überschrift (z. B. „Grundlagen für Betriebsrät*innen"),
	 *  verlinkt auf die Übersicht mit vorausgewähltem Themenfeld.
	 * =================================================================== */

	/** Alle Themenfeld-Terms, „Grundlagen …" zuerst (wie in der Filterleiste) */
	private static function vorlagen_terms() {
		$terms = get_terms( array( 'taxonomy' => BI_TAX_THEMA, 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$pinned = array();
		$rest   = array();
		foreach ( $terms as $t ) {
			$label = class_exists( 'BI_Filter' ) ? BI_Filter::thema_label( $t->name ) : $t->name;
			if ( 0 === strpos( $label, 'Grundlagen für ' ) ) {
				$pinned[ $label ] = $t;
			} else {
				$rest[] = $t;
			}
		}
		ksort( $pinned );
		return array_merge( array_values( $pinned ), $rest );
	}

	/** Kachel-Überschrift = Anzeige-Label des Filters */
	private static function vorlage_titel( $term ) {
		return class_exists( 'BI_Filter' ) ? BI_Filter::thema_label( $term->name ) : $term->name;
	}

	/** Fertiger Shortcode einer Themen-Kachel (button="" = nur Überschrift, kein Text) */
	private static function vorlage_shortcode( $term, $att_id ) {
		$q = function ( $v ) {
			return str_replace( '"', "'", $v );
		};
		return '[bi_kachel layout="2" bild="' . (int) $att_id . '" titel="' . $q( self::vorlage_titel( $term ) )
			. '" button="" thema="' . $q( $term->name ) . '"]';
	}

	/** [bi_kachel_vorlagen] – alle Themen-Kacheln mit zugeordnetem Bild als Grid */
	public static function vorlagen_grid( $atts ) {
		$atts = shortcode_atts( array(
			'spalten' => '3',
			'ratio'   => '',
			'button'  => '',
		), $atts, 'bi_kachel_vorlagen' );

		$map = get_option( self::OPTION_VORLAGEN, array() );
		if ( ! is_array( $map ) || ! $map ) {
			return '';
		}

		$tiles = '';
		foreach ( self::vorlagen_terms() as $term ) {
			$att_id = (int) ( $map[ $term->term_id ] ?? 0 );
			if ( ! $att_id ) {
				continue;
			}
			$tiles .= self::tile( array(
				'layout' => '2',
				'bild'   => (string) $att_id,
				'ratio'  => $atts['ratio'],
				'titel'  => self::vorlage_titel( $term ),
				'text'   => '',
				'button' => $atts['button'],
				'thema'  => $term->name,
			) );
		}
		if ( '' === $tiles ) {
			return '';
		}

		$spalten = in_array( $atts['spalten'], array( '2', '3', '4' ), true ) ? $atts['spalten'] : '3';
		wp_enqueue_style( 'bi-kacheln' );
		return '<div class="bi-kacheln bi-kacheln-' . esc_attr( $spalten ) . '">' . $tiles . '</div>';
	}

	/** Speichern der Bild-Zuordnungen (admin-post) */
	public static function save_vorlagen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'bi_kachel_vorlagen' );

		$in  = isset( $_POST['vorlage'] ) && is_array( $_POST['vorlage'] ) ? wp_unslash( $_POST['vorlage'] ) : array();
		$map = array();
		foreach ( $in as $term_id => $att_id ) {
			$term_id = (int) $term_id;
			$att_id  = (int) $att_id;
			if ( $term_id > 0 && $att_id > 0 ) {
				$map[ $term_id ] = $att_id;
			}
		}
		update_option( self::OPTION_VORLAGEN, $map, false );

		wp_safe_redirect( add_query_arg(
			array( 'page' => 'bi-kachel-vorlagen', 'bi_msg' => rawurlencode( 'Vorlagen gespeichert.' ) ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/** Backend-Seite: Bild je Themenfeld zuordnen + fertige Shortcodes kopieren */
	public static function render_vorlagen() {
		$map   = get_option( self::OPTION_VORLAGEN, array() );
		$terms = self::vorlagen_terms();
		$msg   = isset( $_GET['bi_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['bi_msg'] ) ) : '';
		?>
		<style>
			.bi-kv-table td { vertical-align: middle; }
			.bi-kv-thumb { width: 96px; height: 60px; object-fit: cover; border-radius: 4px; border: 1px solid #dcdcde; display: block; }
			.bi-kv-thumb-empty { width: 96px; height: 60px; border: 1px dashed #c3c4c7; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #787c82; font-size: 11px; }
			.bi-kv-shortcode { font-family: Consolas, Monaco, monospace; width: 100%; }
			.bi-kv-copied { color: #00a32a; font-weight: 600; }
		</style>
		<div class="wrap">
			<h1>Kachel-Vorlagen</h1>
			<?php if ( $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p>Ordne jedem Themenfeld ein Bild aus der <strong>Mediathek</strong> zu (lizenzierte IG-Metall-Motive
				dort hochladen). Daraus entstehen fertige Kacheln im Overlay-Layout – ohne Text, nur mit dem
				Filter-Label als Überschrift – die auf die Übersicht mit vorausgewähltem Themenfeld verlinken.<br>
				Einzelne Kachel: Shortcode aus der Zeile kopieren. Alle Kacheln auf einmal:
				<code>[bi_kachel_vorlagen spalten="3"]</code> (zeigt automatisch alle Themenfelder mit Bild).</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bi_save_kachel_vorlagen">
				<?php wp_nonce_field( 'bi_kachel_vorlagen' ); ?>

				<table class="widefat striped bi-kv-table">
					<thead>
						<tr>
							<th style="width:110px">Bild</th>
							<th>Themenfeld / Kachel-Überschrift</th>
							<th style="width:220px">Aktion</th>
							<th>Shortcode</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $terms as $term ) : ?>
							<?php
							$att_id = (int) ( $map[ $term->term_id ] ?? 0 );
							$thumb  = $att_id ? wp_get_attachment_image_url( $att_id, 'medium' ) : '';
							$titel  = self::vorlage_titel( $term );
							?>
							<tr>
								<td>
									<input type="hidden" class="bi-kv-id" name="vorlage[<?php echo (int) $term->term_id; ?>]" value="<?php echo $att_id ?: ''; ?>">
									<img class="bi-kv-thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" <?php echo $thumb ? '' : 'style="display:none"'; ?>>
									<span class="bi-kv-thumb-empty" <?php echo $thumb ? 'style="display:none"' : ''; ?>>kein Bild</span>
								</td>
								<td>
									<strong><?php echo esc_html( $titel ); ?></strong>
									<?php if ( $titel !== $term->name ) : ?>
										<br><span class="description">Term: <?php echo esc_html( $term->name ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<button type="button" class="button bi-kv-pick">Bild wählen</button>
									<button type="button" class="button-link-delete bi-kv-remove" <?php echo $att_id ? '' : 'style="display:none"'; ?>>entfernen</button>
								</td>
								<td>
									<?php if ( $att_id ) : ?>
										<input type="text" class="bi-kv-shortcode" readonly
											value="<?php echo esc_attr( self::vorlage_shortcode( $term, $att_id ) ); ?>"
											onclick="this.select()">
										<button type="button" class="button bi-kv-copy" style="margin-top:4px">Kopieren</button>
										<span class="bi-kv-copied" style="display:none">✓</span>
									<?php else : ?>
										<span class="description">Bild wählen und speichern – dann erscheint hier der Shortcode.</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( 'Vorlagen speichern' ); ?>
			</form>
		</div>

		<script>
		( function () {
			document.querySelectorAll( '.bi-kv-pick' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var row   = btn.closest( 'tr' );
					var frame = wp.media( { title: 'Kachel-Bild wählen', multiple: false, library: { type: 'image' } } );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						row.querySelector( '.bi-kv-id' ).value = att.id;
						var img = row.querySelector( '.bi-kv-thumb' );
						img.src = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
						img.style.display = '';
						row.querySelector( '.bi-kv-thumb-empty' ).style.display = 'none';
						row.querySelector( '.bi-kv-remove' ).style.display = '';
					} );
					frame.open();
				} );
			} );
			document.querySelectorAll( '.bi-kv-remove' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var row = btn.closest( 'tr' );
					row.querySelector( '.bi-kv-id' ).value = '';
					row.querySelector( '.bi-kv-thumb' ).style.display = 'none';
					row.querySelector( '.bi-kv-thumb-empty' ).style.display = '';
					btn.style.display = 'none';
				} );
			} );
			document.querySelectorAll( '.bi-kv-copy' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var row   = btn.closest( 'td' );
					var input = row.querySelector( '.bi-kv-shortcode' );
					input.select();
					navigator.clipboard.writeText( input.value ).then( function () {
						var ok = row.querySelector( '.bi-kv-copied' );
						ok.style.display = '';
						setTimeout( function () { ok.style.display = 'none'; }, 1500 );
					} );
				} );
			} );
		} )();
		</script>
		<?php
	}
}
