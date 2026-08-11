<?php
/**
 * Detailansicht eines Seminars (Einzelseite der CPTs bi_seminar und bi_online).
 *
 * Nachbau des früheren Formidable-„igm-detail"-Templates:
 *   linke Spalte  = Titel, Beschreibung, Themen, „Weitere Termine zu diesem Seminar"
 *   rechte Spalte = Sidebar „Seminardetails" + Buchungs-Button
 *
 * Buchungs-Logik Präsenz (entspricht Feld [342]):
 *   ausgebucht                       -> „Ausgebucht" (kein Button)
 *   Anmeldung möglich (Direkt)       -> „Jetzt buchen" -> Anmeldeformular ?seminar=ID
 *   Anmeldung nicht möglich (=nein)  -> „nur über die Geschäftsstelle" + Link zur GS-Suche
 *
 * Buchungs-Logik Online (BI_Online::variante):
 *   ausgebucht  -> „Ausgebucht"
 *   extern      -> Teams-Webinar mit eigener Anmeldeseite: Button führt zu Microsoft
 *   direct      -> internes Anmeldeformular (wie Präsenz)
 *   gs          -> Geschäftsstellen-Variante (wie Präsenz)
 *   Zusätzlich: ist ein öffentlicher Online-Link gepflegt, erscheint „Direkt teilnehmen".
 *
 * Wird über den template_include-Filter geladen, bleibt aber im Theme-Rahmen
 * (get_header()/get_footer() im Template).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BI_Detail {

	const GS_SUCHE_URL = 'https://www.igmetall.de/vor-ort';

	public static function init() {
		// template_include statt single_template -> wirkt auch bei Block-/FSE-Themes
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		// Anmeldeseiten-Cache leeren, wenn eine Seite gespeichert wird
		add_action( 'save_post_page', function () {
			delete_transient( 'bi_anmeldung_page_url' );
			delete_transient( 'bi_uebersicht_page_url' );
		} );
	}

	/** Eigenes Single-Template für beide Seminar-Beitragstypen verwenden */
	public static function template_include( $template ) {
		if ( is_singular( bi_seminar_post_types() ) ) {
			$own = BI_PATH . 'templates/single-bi_seminar.php';
			if ( file_exists( $own ) ) {
				return $own;
			}
		}
		return $template;
	}

	/** Vollständige Detail-Ausgabe für ein Seminar */
	public static function render( $post_id ) {
		$post_id   = intval( $post_id );
		$is_online = bi_is_online( $post_id );
		$desc      = apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
		$themen    = get_post_meta( $post_id, '_bi_themen', true );
		$untertitel = $is_online ? (string) get_post_meta( $post_id, '_bi_untertitel', true ) : '';

		ob_start();
		?>
		<div class="igm-detail">
			<div class="igm-detail__layout">

				<div class="igm-detail__main">
					<h1 class="igm-detail__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h1>

					<?php if ( '' !== trim( $untertitel ) ) : ?>
						<p class="igm-detail__subtitle"><?php echo esc_html( $untertitel ); ?></p>
					<?php endif; ?>

					<?php if ( trim( wp_strip_all_tags( $desc ) ) !== '' ) : ?>
						<div class="igm-detail__desc"><?php echo wp_kses_post( $desc ); ?></div>
					<?php endif; ?>

					<?php if ( '' !== trim( (string) $themen ) ) : ?>
						<h2 class="igm-detail__section-title">Themen des Seminars</h2>
						<?php
						// Enthält das Feld HTML (z. B. <ul><li>…)? Dann interpretieren, sonst Plaintext mit Absätzen.
						$themen_html = ( $themen !== wp_strip_all_tags( $themen ) )
							? wp_kses_post( $themen )
							: wpautop( esc_html( $themen ) );
						?>
						<div class="igm-detail__topics"><?php echo $themen_html; // phpcs:ignore – wp_kses_post / esc_html ?></div>
					<?php endif; ?>

					<?php echo self::weitere_termine( $post_id ); // phpcs:ignore – escaped intern ?>
				</div>

				<aside class="igm-detail__sidebar">
					<div class="igm-sidebar-box">
						<h3 class="igm-sidebar-box__headline">Seminardetails</h3>
						<?php echo self::sidebar_rows( $post_id ); // phpcs:ignore – escaped intern ?>
						<?php echo self::booking_block( $post_id ); // phpcs:ignore – escaped intern ?>
					</div>
				</aside>

			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/** Detailzeilen der Sidebar */
	private static function sidebar_rows( $post_id ) {
		$row = function ( $label, $value, $icon = '' ) {
			if ( '' === trim( (string) $value ) ) {
				return '';
			}
			return '<div class="igm-sidebar-row"><strong>' . $icon . esc_html( $label ) . ':</strong> ' . $value . '</div>';
		};

		// Mail-Icon (Umschlag) für die Ansprechpartner*in-Zeile
		$mail_icon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 5L2 7"/></svg>';

		$nr      = get_post_meta( $post_id, '_bi_seminarnummer', true );
		$start   = get_post_meta( $post_id, '_bi_startdatum', true );
		$end     = get_post_meta( $post_id, '_bi_enddatum', true );
		$startuh = get_post_meta( $post_id, '_bi_startuhrzeit', true );
		$enduh   = get_post_meta( $post_id, '_bi_enduhrzeit', true );
		$kosten  = get_post_meta( $post_id, '_bi_kosten', true );
		$plaetze = get_post_meta( $post_id, '_bi_plaetze', true );
		$ap      = get_post_meta( $post_id, '_bi_ansprechpartner', true );
		$apmail  = get_post_meta( $post_id, '_bi_ansprechpartner_email', true );

		$ort  = self::term_list( $post_id, BI_TAX_ORT );
		$frei = self::term_list( $post_id, BI_TAX_FREI );
		$ziel = self::term_list( $post_id, BI_TAX_ZIEL );

		$zeitraum = '';
		if ( $start ) {
			$zeitraum = date_i18n( 'd.m.Y', strtotime( $start ) );
			if ( $end && $end !== $start ) {
				$zeitraum .= ' – ' . date_i18n( 'd.m.Y', strtotime( $end ) );
			}
		}

		$uhrzeit = '';
		if ( $startuh || $enduh ) {
			$uhrzeit = $startuh ? $startuh . ' Uhr' : '';
			if ( $enduh ) {
				$uhrzeit = ( $uhrzeit ? $uhrzeit . ' – ' : 'bis ' ) . $enduh . ' Uhr';
			}
		}

		$rows = '';

		if ( bi_is_online( $post_id ) ) {
			$referenten = get_post_meta( $post_id, '_bi_referenten', true );

			$rows .= $row( 'Seminarnummer', esc_html( $nr ) );
			$rows .= $row( 'Format', esc_html( 'Online-Seminar' ) );
			$rows .= $row( 'Veranstalter*in', esc_html( $ort ) );
			$rows .= $row( 'Zeitraum', esc_html( $zeitraum ) );
			$rows .= $row( 'Uhrzeit', esc_html( $uhrzeit ) );
			$rows .= $row( 'Referent*innen', esc_html( $referenten ) );
			$rows .= $row( 'Freistellung', esc_html( $frei ) );
			$rows .= $row( 'Zielgruppe', esc_html( $ziel ) );
			$rows .= $row( 'Kosten', esc_html( $kosten ) );
			$rows .= $row( 'Plattform', esc_html( BI_Online::tool_label( $post_id ) ) );
		} else {
			$anrd = get_post_meta( $post_id, '_bi_anreisedatum', true );
			$anru = get_post_meta( $post_id, '_bi_anreiseuhrzeit', true );

			$rows .= $row( 'Seminarnummer', esc_html( $nr ) );
			$rows .= $row( 'Ort', esc_html( $ort ) );
			$rows .= $row( 'Zeitraum', esc_html( $zeitraum ) );
			$rows .= $row( 'Uhrzeit', esc_html( $uhrzeit ) );

			if ( $anrd || $anru ) {
				$an = $anrd ? date_i18n( 'd.m.Y', strtotime( $anrd ) ) : '';
				if ( $anru ) {
					$an = trim( $an . ( $an ? ', ' : '' ) . 'ab ' . $anru . ' Uhr' );
				}
				$rows .= $row( 'Anreise', esc_html( $an ) );
			}

			$rows .= $row( 'Freistellung', esc_html( $frei ) );
			$rows .= $row( 'Zielgruppe', esc_html( $ziel ) );
			$rows .= $row( 'Kosten', esc_html( $kosten ) );
			if ( '' !== trim( (string) $plaetze ) ) {
				$rows .= $row( 'Freie Plätze', esc_html( $plaetze ) );
			}
		}

		if ( '' !== trim( (string) $ap ) ) {
			// E-Mail-Link über den Namen legen, nicht separat darunter
			$name = ( $apmail && is_email( $apmail ) )
				? '<a href="mailto:' . esc_attr( $apmail ) . '">' . esc_html( $ap ) . '</a>'
				: esc_html( $ap );
			// Mail-Icon vor den Namen (nicht vor das Label)
			$rows .= $row( 'Ansprechpartner*in', $mail_icon . $name );
		}

		return $rows;
	}

	/** Ziel-URL der Direktanmeldung für ein Seminar */
	private static function direct_url( $post_id ) {
		$url = BI_Registration::anmeldung_page_url();
		return $url
			? add_query_arg( 'seminar', $post_id, $url )
			: add_query_arg( 'seminar', $post_id, get_permalink( $post_id ) );
	}

	/** Reiner Buchungs-Button je nach Variante/Status (für Sidebar + Termin-Tabelle) */
	private static function booking_button( $post_id ) {
		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return '<span class="igm-btn-buchen igm-btn-buchen--disabled" aria-disabled="true">Ausgebucht</span>';
		}

		// Online-Seminare: eigene Weiche (externe Anmeldeseite bzw. offener Zugang).
		if ( bi_is_online( $post_id ) ) {
			$variante = BI_Online::variante( $post_id );
			if ( 'extern' === $variante ) {
				return '<a class="igm-btn-buchen" aria-label="Zur Anmeldung beim Webinar-Anbieter" href="'
					. esc_url( BI_Online::anmeldelink( $post_id ) ) . '" target="_blank" rel="noopener">Zur Anmeldung</a>';
			}
			if ( 'offen' === $variante ) {
				return '<a class="igm-btn-buchen igm-btn-buchen--alt" aria-label="Direkt am Online-Seminar teilnehmen" href="'
					. esc_url( BI_Online::online_link( $post_id ) ) . '" target="_blank" rel="noopener">Direkt teilnehmen</a>';
			}
		}

		if ( 'direct' === BI_Settings::variant_for( $post_id ) ) {
			return '<a class="igm-btn-buchen" aria-label="Jetzt Seminar buchen" href="' . esc_url( self::direct_url( $post_id ) ) . '">'
				. esc_html( BI_Settings::get( 'direct_label' ) ) . '</a>';
		}
		// GS-Variante: Link zur Geschäftsstellensuche; sobald das PLZ-Widget die
		// zuständige Geschäftsstelle kennt, schreibt gs-anfrage.js den Button auf
		// einen mailto:-Link mit vorausgefüllter Anfrage um.
		return '<a class="igm-btn-buchen igm-btn-buchen--alt igm-js-gs-btn" aria-label="Anmeldung über die Geschäftsstelle"'
			. self::gs_mail_attrs( $post_id ) . ' href="'
			. esc_url( self::gs_url() ) . '" target="_blank" rel="noopener">' . esc_html( BI_Settings::get( 'gs_label' ) ) . '</a>';
	}

	/** Buchungs-Block der Sidebar (mit Hinweistext) */
	private static function booking_block( $post_id ) {
		$is_online = bi_is_online( $post_id );

		if ( BI_CPT::meta_bool( $post_id, '_bi_ausgebucht' ) ) {
			return '<div class="igm-buchen-hinweis">Dieses Seminar ist <strong>ausgebucht</strong>.</div>';
		}

		$html    = '';
		$variante = $is_online ? BI_Online::variante( $post_id ) : '';

		if ( 'extern' === $variante ) {
			$html .= '<div class="igm-buchen-hinweis">Die Anmeldung läuft über die Anmeldeseite des Webinars.</div>';
			$html .= self::booking_button( $post_id );
		} elseif ( 'offen' === $variante ) {
			// Öffentliche Veranstaltung: nur der Teilnahme-Link (wird unten angehängt)
			$html .= '<div class="igm-buchen-hinweis">Diese Veranstaltung ist <strong>öffentlich zugänglich</strong> – eine Anmeldung ist nicht nötig.</div>';
		} elseif ( 'direct' === BI_Settings::variant_for( $post_id ) ) {
			$html .= self::booking_button( $post_id );
		} else {
			$html .= self::gs_anfrage_widget( $post_id );
		}

		// Öffentlich zugängliche Online-Veranstaltung: Teilnahme-Link zusätzlich anbieten.
		// (Bei 'offen' ist er der einzige Handlungsaufruf, sonst steht er unter der Anmeldung.)
		if ( $is_online ) {
			$link = BI_Online::online_link( $post_id );
			if ( '' !== $link ) {
				$html .= '<a class="igm-btn-buchen igm-btn-buchen--alt" href="' . esc_url( $link )
					. '" target="_blank" rel="noopener">Direkt teilnehmen</a>';
			}
		}

		return $html;
	}

	/**
	 * PLZ-Suche + Mail-Anfrage für die GS-Variante (Sidebar).
	 * gs-anfrage.js macht den AJAX-Lookup (bi_plz_lookup) und baut den
	 * „Anfrage senden"-mailto-Link aus den data-bi-Attributen.
	 */
	private static function gs_anfrage_widget( $post_id ) {
		$html  = '<div class="igm-gs-anfrage"' . self::gs_mail_attrs( $post_id ) . '>';
		$html .= '<div class="igm-buchen-hinweis">' . esc_html( BI_Settings::get( 'gs_hinweis' ) ) . '</div>';
		$html .= '<label class="igm-gs-anfrage__label" for="igm-gs-plz">Postleitzahl deines Wohnorts:</label>';
		$html .= '<div class="igm-gs-anfrage__form">';
		$html .= '<input type="text" id="igm-gs-plz" class="igm-gs-anfrage__plz" inputmode="numeric" pattern="[0-9]{5}" maxlength="5" placeholder="z. B. 60329" aria-label="Postleitzahl">';
		$html .= '<button type="button" class="igm-gs-anfrage__find">Geschäftsstelle finden</button>';
		$html .= '</div>';
		$html .= '<div class="igm-gs-anfrage__result" role="status" hidden></div>';
		$html .= '<a class="igm-gs-anfrage__fallback" href="' . esc_url( self::gs_url() )
			. '" target="_blank" rel="noopener">Oder: zur Geschäftsstellensuche auf igmetall.de</a>';
		$html .= '</div>';
		return $html;
	}

	/** data-bi-subject/-body mit vorausgefüllter Seminar-Anfrage für mailto-Links */
	private static function gs_mail_attrs( $post_id ) {
		$nr    = get_post_meta( $post_id, '_bi_seminarnummer', true );
		$titel = get_the_title( $post_id );
		$start = get_post_meta( $post_id, '_bi_startdatum', true );
		$end   = get_post_meta( $post_id, '_bi_enddatum', true );
		$ort   = self::term_list( $post_id, BI_TAX_ORT );

		$zeitraum = $start ? date_i18n( 'd.m.Y', strtotime( $start ) ) : '';
		if ( $end && $end !== $start ) {
			$zeitraum .= ' – ' . date_i18n( 'd.m.Y', strtotime( $end ) );
		}

		$subject = 'Seminaranmeldung ' . trim( ( $nr ? $nr . ' – ' : '' ) . $titel );

		$body  = "Guten Tag,\r\n\r\n";
		$body .= "hiermit möchte ich mich für folgendes Seminar anmelden:\r\n\r\n";
		$body .= 'Seminar: ' . $titel . "\r\n";
		if ( $nr ) {
			$body .= 'Seminarnummer: ' . $nr . "\r\n";
		}
		if ( $zeitraum ) {
			$body .= 'Zeitraum: ' . $zeitraum . "\r\n";
		}
		if ( bi_is_online( $post_id ) ) {
			$body .= 'Format: Online-Seminar' . ( $ort ? ' (' . $ort . ')' : '' ) . "\r\n";
		} elseif ( $ort ) {
			$body .= 'Bildungszentrum: ' . $ort . "\r\n";
		}
		$body .= 'Link: ' . get_permalink( $post_id ) . "\r\n\r\n";
		$body .= "Meine Daten:\r\nName: \r\nAnschrift: \r\nGeburtsdatum: \r\nTelefon: \r\nE-Mail: \r\nBetrieb: \r\n\r\n";
		$body .= "Viele Grüße\r\n";

		return ' data-bi-subject="' . esc_attr( $subject ) . '" data-bi-body="' . esc_attr( $body ) . '"';
	}

	private static function gs_url() {
		$url = BI_Settings::get( 'gs_url' );
		return $url ?: self::GS_SUCHE_URL;
	}

	/**
	 * „Weitere Termine zu diesem Seminar" – gleiche Titel, aber SPÄTER stattfindende,
	 * zukünftige Termine desselben Beitragstyps. Tabelle (Ort/Veranstalter*in |
	 * Zeitraum | Buchungs-Button je Termin).
	 */
	private static function weitere_termine( $post_id ) {
		global $wpdb;
		$post_type = get_post_type( $post_id );
		$is_online = bi_is_online( $post_id );
		$title     = get_the_title( $post_id );

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title = %s AND ID <> %d",
			$post_type,
			$title,
			$post_id
		) );
		if ( empty( $ids ) ) {
			return '';
		}

		$current_start = get_post_meta( $post_id, '_bi_startdatum', true );
		$today         = current_time( 'Y-m-d' );

		$meta_query = array( BI_CPT::visible_clause() );
		// später als das aktuell gezeigte Seminar …
		if ( $current_start ) {
			$meta_query[] = array( 'key' => '_bi_startdatum', 'value' => $current_start, 'compare' => '>', 'type' => 'DATE' );
		}
		// … und nicht in der Vergangenheit
		$meta_query[] = array( 'key' => '_bi_startdatum', 'value' => $today, 'compare' => '>=', 'type' => 'DATE' );

		$q = new WP_Query( array(
			'post_type'      => $post_type,
			'post__in'       => array_map( 'intval', $ids ),
			'posts_per_page' => 30,
			'meta_key'       => '_bi_startdatum',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_type'      => 'DATE',
			'meta_query'     => $meta_query,
		) );
		if ( ! $q->have_posts() ) {
			return '';
		}

		ob_start();
		echo '<div class="igm-weitere-termine">';
		echo '<h2 class="igm-detail__section-title">Weitere Termine zu diesem Seminar</h2>';
		echo '<table class="igm-termin-table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html( $is_online ? 'Veranstalter*in' : 'Seminarort' ) . '</th>';
		echo '<th scope="col">Zeitraum</th>';
		echo '<th scope="col"><span class="screen-reader-text">Buchung</span></th>';
		echo '</tr></thead><tbody>';
		while ( $q->have_posts() ) {
			$q->the_post();
			$id    = get_the_ID();
			$start = get_post_meta( $id, '_bi_startdatum', true );
			$end   = get_post_meta( $id, '_bi_enddatum', true );
			$ort   = self::term_list( $id, BI_TAX_ORT );

			$datum = $start ? date_i18n( 'd.m.Y', strtotime( $start ) ) : '';
			if ( $end && $end !== $start ) {
				$datum .= ' – ' . date_i18n( 'd.m.Y', strtotime( $end ) );
			}

			echo '<tr>';
			echo '<td class="igm-termin-table__ort" data-label="' . esc_attr( $is_online ? 'Veranstalter*in' : 'Seminarort' ) . '">' . esc_html( $ort ) . '</td>';
			echo '<td class="igm-termin-table__zeitraum" data-label="Zeitraum">'
				. '<a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( $datum ) . '</a></td>';
			echo '<td class="igm-termin-table__action">' . self::booking_button( $id ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		wp_reset_postdata();
		return ob_get_clean();
	}

	private static function term_list( $post_id, $tax, $sep = ', ' ) {
		$names = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'names' ) );
		return ( is_array( $names ) && $names ) ? implode( $sep, $names ) : '';
	}
}
