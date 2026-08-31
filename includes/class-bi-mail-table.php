<?php
/**
 * Listentabelle der Benachrichtigungen.
 *
 * Eine WP_List_Table wie bei Beiträgen oder Seiten: jede Benachrichtigung ist eine
 * eigene Zeile mit Zeilenaktionen (Bearbeiten, Duplizieren, Aktivieren/Deaktivieren,
 * Löschen) und Sammelaktionen. Bearbeitet wird auf einer eigenen Seite, siehe
 * BI_Mailer::render_edit().
 *
 * Die Daten kommen aus der Option bi_mail_triggers (BI_Mailer::get_triggers()), der
 * Zeilenschlüssel ist die feste id des Datensatzes. Verarbeitet werden die Aktionen
 * nicht hier, sondern in BI_Mailer::handle_actions() – das läuft auf admin_init und
 * kann deshalb nach der Aktion umleiten.
 *
 * Diese Datei wird erst beim Rendern der Übersicht geladen, damit WP_List_Table
 * (nur im Admin verfügbar) sicher existiert.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BI_Mail_Table extends WP_List_Table {

	/** Taxonomien für die lesbare Bedingung, einmal geholt statt je Zeile */
	private $taxes = array();

	public function __construct() {
		parent::__construct( array(
			'singular' => 'benachrichtigung',
			'plural'   => BI_Mailer::TABLE_PLURAL, // bestimmt den Nonce der Sammelaktionen
			'ajax'     => false,
		) );
		$this->taxes = BI_CPT::taxonomies();
	}

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'name'      => 'Bezeichnung',
			'type'      => 'Empfänger',
			'condition' => 'Bedingung',
			'schedule'  => 'Versandart',
			'status'    => 'Status',
		);
	}

	public function get_sortable_columns() {
		return array(
			'name'     => array( 'name', false ),
			'type'     => array( 'type', false ),
			'schedule' => array( 'schedule', false ),
			'status'   => array( 'status', false ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'activate'   => 'Aktivieren',
			'deactivate' => 'Deaktivieren',
			'delete'     => 'Löschen',
		);
	}

	/**
	 * Es sind erfahrungsgemäß eine Handvoll Benachrichtigungen – die Liste wird
	 * vollständig ausgegeben, nur sortiert. Eine Seitenaufteilung würde hier mehr
	 * verdecken als helfen.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );

		$items = BI_Mailer::get_triggers();

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$order   = ( isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? -1 : 1;

		if ( array_key_exists( $orderby, $this->get_sortable_columns() ) ) {
			$labels = BI_Mailer::type_labels();
			usort( $items, function ( $a, $b ) use ( $orderby, $order, $labels ) {
				switch ( $orderby ) {
					case 'type':
						$va = $labels[ $a['type'] ?? '' ] ?? '';
						$vb = $labels[ $b['type'] ?? '' ] ?? '';
						break;
					case 'schedule':
						$va = BI_Mailer::schedule_of( $a );
						$vb = BI_Mailer::schedule_of( $b );
						break;
					case 'status':
						$va = empty( $a['active'] ) ? '1' : '0'; // aktive zuerst
						$vb = empty( $b['active'] ) ? '1' : '0';
						break;
					default:
						$va = $a['name'] ?? '';
						$vb = $b['name'] ?? '';
				}
				return $order * strnatcasecmp( $va, $vb );
			} );
		}

		$this->items = $items;
	}

	public function no_items() {
		printf(
			'Noch keine Benachrichtigung angelegt. <a href="%s">Jetzt die erste anlegen</a>.',
			esc_url( BI_Mailer::page_url( array( 'action' => 'new' ) ) )
		);
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="bi_ids[]" value="%d">', (int) $item['id'] );
	}

	protected function column_name( $item ) {
		$id    = (int) $item['id'];
		$label = ( $item['name'] ?? '' ) !== '' ? $item['name'] : '(ohne Bezeichnung)';
		$edit  = BI_Mailer::edit_url( $id );

		$actions = array(
			'edit'      => sprintf( '<a href="%s">Bearbeiten</a>', esc_url( $edit ) ),
			'toggle'    => sprintf(
				'<a href="%s">%s</a>',
				esc_url( BI_Mailer::row_action_url( empty( $item['active'] ) ? 'activate' : 'deactivate', $id ) ),
				empty( $item['active'] ) ? 'Aktivieren' : 'Deaktivieren'
			),
			'duplicate' => sprintf(
				'<a href="%s">Duplizieren</a>',
				esc_url( BI_Mailer::row_action_url( 'duplicate', $id ) )
			),
			'delete'    => sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'Diese Benachrichtigung wirklich löschen?\');">Löschen</a>',
				esc_url( BI_Mailer::row_action_url( 'delete', $id ) )
			),
		);

		$out = sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>',
			esc_url( $edit ),
			esc_html( $label )
		);
		if ( ( $item['subject'] ?? '' ) !== '' ) {
			$out .= '<br><span class="bi-muted">' . esc_html( $item['subject'] ) . '</span>';
		}

		return $out . $this->row_actions( $actions );
	}

	protected function column_type( $item ) {
		$labels = BI_Mailer::type_labels();
		$out    = esc_html( $labels[ $item['type'] ?? '' ] ?? ( $item['type'] ?? '' ) );

		if ( 'custom' === ( $item['type'] ?? '' ) ) {
			$out .= ( $item['recipient'] ?? '' ) !== ''
				? '<br><span class="bi-muted">' . esc_html( $item['recipient'] ) . '</span>'
				: '<br><span class="bi-warn">keine Adresse hinterlegt</span>';
		}

		// Eine Kopie geht an Menschen, die in der Empfängerspalte nicht stehen.
		// Wer die Liste überfliegt, soll sehen, dass hier jemand mitliest –
		// sonst fällt eine vergessene Archivadresse erst auf, wenn sich jemand
		// über Post wundert, die er nicht bestellt hat.
		if ( ( $item['cc'] ?? '' ) !== '' ) {
			$out .= '<br><span class="bi-muted">Cc: ' . esc_html( $item['cc'] ) . '</span>';
		}
		return $out;
	}

	protected function column_condition( $item ) {
		$label = BI_Mailer::condition_label( $item, $this->taxes );
		return $label
			? esc_html( $label )
			: '<span class="bi-muted">bei jeder Anmeldung</span>';
	}

	protected function column_schedule( $item ) {
		return 'weekly' === BI_Mailer::schedule_of( $item )
			? '<span class="bi-badge bi-badge--weekly">wöchentlich gesammelt</span>'
			: '<span class="bi-badge bi-badge--instant">sofort</span>';
	}

	protected function column_status( $item ) {
		return empty( $item['active'] )
			? '<span class="bi-badge bi-badge--off">inaktiv</span>'
			: '<span class="bi-badge bi-badge--on">aktiv</span>';
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/** Inaktive Zeilen abgesetzt darstellen */
	public function single_row( $item ) {
		echo empty( $item['active'] ) ? '<tr class="bi-row-inactive">' : '<tr>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}
}
