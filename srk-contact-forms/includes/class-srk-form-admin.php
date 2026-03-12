<?php

defined( 'ABSPATH' ) || exit;

class SRK_Form_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_save' ] );
		add_action( 'admin_init', [ $this, 'handle_delete' ] );
	}

	public function register_settings(): void {
		register_setting( 'srk_cf_options_group', 'srk_cf_options', [
			'sanitize_callback' => function ( $input ) {
				return [
					'enable_antispam' => ! empty( $input['enable_antispam'] ),
				];
			},
		] );
	}

	public function add_menu(): void {
		$hook = add_menu_page(
			'SRK Formulare',
			'SRK Formulare',
			'manage_options',
			'srk-forms',
			[ $this, 'render_page' ],
			'dashicons-feedback',
			26
		);

		add_action( 'admin_print_scripts-' . $hook, [ $this, 'enqueue_assets' ] );
	}

	public function enqueue_assets(): void {
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_script(
			'srk-admin-forms',
			SRK_CF_URL . 'assets/js/srk-admin-forms.js',
			[ 'jquery', 'jquery-ui-sortable' ],
			SRK_CF_VERSION,
			true
		);
		wp_enqueue_style(
			'srk-admin-forms',
			SRK_CF_URL . 'assets/css/srk-admin-forms.css',
			[],
			SRK_CF_VERSION
		);
	}

	public function render_page(): void {
		$action = $_GET['action'] ?? 'list';

		switch ( $action ) {
			case 'edit':
			case 'new':
				$this->render_edit( $action );
				break;
			default:
				$this->render_list();
		}
	}

	// ── List View ──

	private function render_list(): void {
		$forms = SRK_Form_Registry::all();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">SRK Formulare</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=srk-forms&action=new' ) ) . '" class="page-title-action">Neues Formular</a>';
		echo '<hr class="wp-header-end">';

		if ( isset( $_GET['msg'] ) ) {
			$messages = [
				'saved'   => 'Formular gespeichert.',
				'deleted' => 'Formular gelöscht.',
			];
			$msg = $messages[ $_GET['msg'] ] ?? '';
			if ( $msg ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}

		echo '<table class="widefat striped" style="margin-top:1rem;">';
		echo '<thead><tr><th>ID</th><th>Titel</th><th>Empfänger</th><th>Felder</th><th>Shortcode</th><th>Aktionen</th></tr></thead>';
		echo '<tbody>';

		if ( empty( $forms ) ) {
			echo '<tr><td colspan="6">Keine Formulare vorhanden.</td></tr>';
		}

		foreach ( $forms as $id => $form ) {
			$edit_url   = admin_url( 'admin.php?page=srk-forms&action=edit&form_id=' . urlencode( $id ) );
			$delete_url = wp_nonce_url(
				admin_url( 'admin.php?page=srk-forms&action=delete&form_id=' . urlencode( $id ) ),
				'srk_cf_delete_' . $id
			);

			echo '<tr>';
			echo '<td><code>' . esc_html( $id ) . '</code></td>';
			echo '<td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $form['title'] ?? $id ) . '</a></strong></td>';
			echo '<td>' . esc_html( $form['recipient'] ?? '–' ) . '</td>';
			echo '<td>' . count( $form['fields'] ?? [] ) . '</td>';
			echo '<td><code>[srk_contact_form id="' . esc_html( $id ) . '"]</code></td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">Bearbeiten</a> | <a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Formular wirklich löschen?\');" style="color:#b32d2e;">Löschen</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// Global settings.
		$cf_opts = get_option( 'srk_cf_options', [] );
		echo '<hr style="margin-top:2rem;">';
		echo '<h2>Einstellungen</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'srk_cf_options_group' );
		echo '<table class="form-table"><tr>';
		echo '<th>Spam-Schutz</th>';
		echo '<td><label>';
		echo '<input type="checkbox" name="srk_cf_options[enable_antispam]" value="1"' . checked( $cf_opts['enable_antispam'] ?? true, true, false ) . '>';
		echo ' Spam-Schutz aktivieren (Honeypot + Zeitprüfung + Token)';
		echo '</label>';
		echo '<p class="description">Unsichtbar für Nutzer. Blockiert automatisierte Bot-Einreichungen ohne CAPTCHA.</p>';
		echo '</td></tr></table>';
		submit_button( 'Einstellungen speichern' );
		echo '</form>';

		echo '</div>';
	}

	// ── Edit / New View ──

	private function render_edit( string $action ): void {
		$form_id = sanitize_key( $_GET['form_id'] ?? '' );
		$form    = [];

		if ( 'edit' === $action && $form_id ) {
			$form = SRK_Form_Registry::get( $form_id ) ?? [];
		}

		$is_new    = 'new' === $action;
		$title     = $is_new ? 'Neues Formular' : 'Formular bearbeiten';
		$fields    = $form['fields'] ?? [];

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';

		echo '<form method="post" action="">';
		wp_nonce_field( 'srk_cf_save', 'srk_cf_nonce' );
		echo '<input type="hidden" name="srk_cf_action" value="save_form">';

		if ( ! $is_new ) {
			echo '<input type="hidden" name="srk_cf_original_id" value="' . esc_attr( $form_id ) . '">';
		}

		echo '<table class="form-table">';

		// Form ID
		echo '<tr><th><label for="srk_cf_id">Formular-ID</label></th>';
		echo '<td><input type="text" id="srk_cf_id" name="srk_cf_id" value="' . esc_attr( $form_id ) . '" class="regular-text" pattern="[a-z0-9\-]+" ' . ( $is_new ? '' : 'readonly' ) . ' required>';
		echo '<p class="description">Eindeutige ID (Kleinbuchstaben, Zahlen, Bindestriche). Wird im Shortcode verwendet.</p></td></tr>';

		// Title
		echo '<tr><th><label for="srk_cf_title">Titel</label></th>';
		echo '<td><input type="text" id="srk_cf_title" name="srk_cf_title" value="' . esc_attr( $form['title'] ?? '' ) . '" class="regular-text" required></td></tr>';

		// Recipient
		echo '<tr><th><label for="srk_cf_recipient">Empfänger E-Mail</label></th>';
		echo '<td><input type="email" id="srk_cf_recipient" name="srk_cf_recipient" value="' . esc_attr( $form['recipient'] ?? '' ) . '" class="regular-text" required></td></tr>';

		// Subject
		echo '<tr><th><label for="srk_cf_subject">E-Mail-Betreff</label></th>';
		echo '<td><input type="text" id="srk_cf_subject" name="srk_cf_subject" value="' . esc_attr( $form['subject'] ?? '' ) . '" class="regular-text"></td></tr>';

		// Submit label
		echo '<tr><th><label for="srk_cf_submit_label">Button-Text</label></th>';
		echo '<td><input type="text" id="srk_cf_submit_label" name="srk_cf_submit_label" value="' . esc_attr( $form['submit_label'] ?? 'Nachricht versenden' ) . '" class="regular-text"></td></tr>';

		// Success message
		echo '<tr><th><label for="srk_cf_success_msg">Erfolgsmeldung</label></th>';
		echo '<td><input type="text" id="srk_cf_success_msg" name="srk_cf_success_msg" value="' . esc_attr( $form['success_msg'] ?? '' ) . '" class="large-text"></td></tr>';

		// Privacy page
		echo '<tr><th><label for="srk_cf_privacy_page">Datenschutz-URL</label></th>';
		echo '<td><input type="text" id="srk_cf_privacy_page" name="srk_cf_privacy_page" value="' . esc_attr( $form['privacy_page'] ?? '/datenschutzerklaerung/' ) . '" class="regular-text"></td></tr>';

		echo '</table>';

		// ── Field Builder ──
		echo '<h2 style="margin-top:2rem;">Felder</h2>';
		echo '<table class="widefat srk-field-builder" id="srk-field-builder">';
		echo '<thead><tr><th class="srk-fb-drag"></th><th>Name</th><th>Label</th><th>Typ</th><th>Pflicht</th><th>Breite</th><th>Placeholder</th><th>Optionen (Select)</th><th></th></tr></thead>';
		echo '<tbody id="srk-field-rows">';

		if ( ! empty( $fields ) ) {
			foreach ( $fields as $i => $field ) {
				$this->render_field_row( $i, $field );
			}
		}

		echo '</tbody>';
		echo '</table>';

		echo '<p style="margin-top:0.5rem;"><button type="button" class="button" id="srk-add-field">+ Feld hinzufügen</button></p>';

		// Hidden template row
		echo '<script type="text/html" id="srk-field-row-template">';
		$this->render_field_row( '__INDEX__', [] );
		echo '</script>';

		submit_button( 'Formular speichern' );

		echo '</form>';
		echo '</div>';
	}

	private function render_field_row( $index, array $field ): void {
		$prefix  = "srk_cf_fields[{$index}]";
		$name    = $field['name'] ?? '';
		$label   = $field['label'] ?? '';
		$type    = $field['type'] ?? 'text';
		$req     = ! empty( $field['required'] );
		$width   = $field['width'] ?? 'full';
		$ph      = $field['placeholder'] ?? '';
		$options = '';

		if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
			$lines = [];
			foreach ( $field['options'] as $val => $lbl ) {
				if ( '' === $val ) {
					$lines[] = '|' . $lbl;
				} else {
					$lines[] = $val . '|' . $lbl;
				}
			}
			$options = implode( "\n", $lines );
		}

		$types = [ 'text' => 'Text', 'email' => 'E-Mail', 'tel' => 'Telefon', 'select' => 'Dropdown', 'textarea' => 'Textfeld' ];

		echo '<tr class="srk-field-row">';
		echo '<td class="srk-fb-drag"><span class="dashicons dashicons-menu" style="cursor:grab;color:#999;"></span></td>';
		echo '<td><input type="text" name="' . esc_attr( $prefix ) . '[name]" value="' . esc_attr( $name ) . '" class="small-text" required style="width:100px;"></td>';
		echo '<td><input type="text" name="' . esc_attr( $prefix ) . '[label]" value="' . esc_attr( $label ) . '" style="width:120px;"></td>';

		echo '<td><select name="' . esc_attr( $prefix ) . '[type]" class="srk-field-type-select">';
		foreach ( $types as $val => $lbl ) {
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $type, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
		}
		echo '</select></td>';

		echo '<td><input type="checkbox" name="' . esc_attr( $prefix ) . '[required]" value="1"' . checked( $req, true, false ) . '></td>';

		echo '<td><select name="' . esc_attr( $prefix ) . '[width]">';
		echo '<option value="half"' . selected( $width, 'half', false ) . '>Halb</option>';
		echo '<option value="full"' . selected( $width, 'full', false ) . '>Voll</option>';
		echo '</select></td>';

		echo '<td><input type="text" name="' . esc_attr( $prefix ) . '[placeholder]" value="' . esc_attr( $ph ) . '" style="width:140px;"></td>';

		$display = 'select' === $type ? '' : 'display:none;';
		echo '<td><textarea name="' . esc_attr( $prefix ) . '[options]" rows="3" style="width:180px;' . $display . '" class="srk-field-options" placeholder="wert|Label (pro Zeile)">' . esc_textarea( $options ) . '</textarea></td>';

		echo '<td><button type="button" class="button srk-remove-field" title="Entfernen">&times;</button></td>';
		echo '</tr>';
	}

	// ── Save Handler ──

	public function handle_save(): void {
		if ( empty( $_POST['srk_cf_action'] ) || 'save_form' !== $_POST['srk_cf_action'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}

		check_admin_referer( 'srk_cf_save', 'srk_cf_nonce' );

		$original_id = sanitize_key( $_POST['srk_cf_original_id'] ?? '' );
		$form_id     = sanitize_key( $_POST['srk_cf_id'] ?? '' );

		if ( ! $form_id ) {
			wp_die( 'Formular-ID fehlt.' );
		}

		$config = [
			'title'        => sanitize_text_field( $_POST['srk_cf_title'] ?? '' ),
			'recipient'    => sanitize_email( $_POST['srk_cf_recipient'] ?? '' ),
			'subject'      => sanitize_text_field( $_POST['srk_cf_subject'] ?? '' ),
			'submit_label' => sanitize_text_field( $_POST['srk_cf_submit_label'] ?? 'Nachricht versenden' ),
			'success_msg'  => sanitize_text_field( $_POST['srk_cf_success_msg'] ?? '' ),
			'privacy_page' => esc_url_raw( $_POST['srk_cf_privacy_page'] ?? '/datenschutzerklaerung/' ),
			'fields'       => $this->sanitize_fields( $_POST['srk_cf_fields'] ?? [] ),
		];

		// If ID changed (shouldn't happen for edits, but just in case), remove old.
		if ( $original_id && $original_id !== $form_id ) {
			SRK_Form_Registry::delete( $original_id );
		}

		SRK_Form_Registry::save_form( $form_id, $config );

		wp_safe_redirect( admin_url( 'admin.php?page=srk-forms&msg=saved' ) );
		exit;
	}

	private function sanitize_fields( array $raw_fields ): array {
		$allowed_types  = [ 'text', 'email', 'tel', 'select', 'textarea' ];
		$allowed_widths = [ 'half', 'full' ];
		$fields         = [];

		foreach ( $raw_fields as $raw ) {
			if ( empty( $raw['name'] ) ) {
				continue;
			}

			$type = in_array( $raw['type'] ?? 'text', $allowed_types, true ) ? $raw['type'] : 'text';

			$field = [
				'name'        => sanitize_key( $raw['name'] ),
				'label'       => sanitize_text_field( $raw['label'] ?? '' ),
				'type'        => $type,
				'required'    => ! empty( $raw['required'] ),
				'width'       => in_array( $raw['width'] ?? 'full', $allowed_widths, true ) ? $raw['width'] : 'full',
				'placeholder' => sanitize_text_field( $raw['placeholder'] ?? '' ),
			];

			if ( 'select' === $type && ! empty( $raw['options'] ) ) {
				$field['options'] = $this->parse_select_options( $raw['options'] );
			}

			$fields[] = $field;
		}

		return $fields;
	}

	private function parse_select_options( string $raw ): array {
		$options = [];
		$lines   = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		foreach ( $lines as $line ) {
			if ( str_contains( $line, '|' ) ) {
				[ $value, $label ] = explode( '|', $line, 2 );
				$options[ trim( $value ) ] = trim( $label );
			} else {
				$options[ $line ] = $line;
			}
		}

		return $options;
	}

	// ── Delete Handler ──

	public function handle_delete(): void {
		if ( ! isset( $_GET['page'], $_GET['action'], $_GET['form_id'] ) ) {
			return;
		}

		if ( 'srk-forms' !== $_GET['page'] || 'delete' !== $_GET['action'] ) {
			return;
		}

		$form_id = sanitize_key( $_GET['form_id'] );
		check_admin_referer( 'srk_cf_delete_' . $form_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}

		SRK_Form_Registry::delete( $form_id );

		wp_safe_redirect( admin_url( 'admin.php?page=srk-forms&msg=deleted' ) );
		exit;
	}
}
