<?php

defined( 'ABSPATH' ) || exit;

/**
 * Registry for form configurations.
 *
 * Forms are stored in wp_options and can be managed via the admin UI.
 * The `srk_contact_forms` filter still allows code-based overrides.
 */
class SRK_Form_Registry {

	private static ?array $forms = null;

	private const OPTION_KEY = 'srk_cf_forms';

	/**
	 * Get a form configuration by ID.
	 */
	public static function get( string $id ): ?array {
		$forms = self::all();
		return $forms[ $id ] ?? null;
	}

	/**
	 * Get all registered forms.
	 */
	public static function all(): array {
		if ( null !== self::$forms ) {
			return self::$forms;
		}

		$stored = self::stored_forms();

		/**
		 * Filter to register additional forms or override existing ones.
		 *
		 * @param array $forms Associative array of form_id => config.
		 */
		self::$forms = apply_filters( 'srk_contact_forms', $stored );

		return self::$forms;
	}

	/**
	 * Save a single form.
	 */
	public static function save_form( string $id, array $config ): void {
		$forms         = self::stored_forms();
		$forms[ $id ]  = $config;

		update_option( self::OPTION_KEY, $forms );
		self::$forms = null;
	}

	/**
	 * Delete a form by ID.
	 */
	public static function delete( string $id ): void {
		$forms = self::stored_forms();
		unset( $forms[ $id ] );

		update_option( self::OPTION_KEY, $forms );
		self::$forms = null;
	}

	/**
	 * Get forms from the database.
	 */
	private static function stored_forms(): array {
		$forms = get_option( self::OPTION_KEY, [] );
		return is_array( $forms ) ? $forms : [];
	}

	/**
	 * Default forms seeded on first activation.
	 */
	public static function default_forms(): array {
		return [
			'contact' => [
				'title'     => 'Kontaktformular',
				'recipient' => 'info@srk-hosting.de',
				'subject'   => 'Kontaktanfrage über die Website',
				'fields'    => [
					[
						'name'        => 'name',
						'label'       => 'Name',
						'type'        => 'text',
						'required'    => true,
						'placeholder' => 'Ihr vollständiger Name',
						'width'       => 'half',
					],
					[
						'name'        => 'email',
						'label'       => 'E-Mail',
						'type'        => 'email',
						'required'    => true,
						'placeholder' => 'ihre@email.de',
						'width'       => 'half',
					],
					[
						'name'        => 'phone',
						'label'       => 'Telefonnummer',
						'type'        => 'tel',
						'required'    => false,
						'placeholder' => 'z.B. 0170 1234567',
						'width'       => 'half',
					],
					[
						'name'        => 'subject',
						'label'       => 'Anfrage',
						'type'        => 'select',
						'required'    => true,
						'width'       => 'half',
						'options'     => [
							''             => 'Bitte wählen…',
							'webdesign'    => 'Webdesign & WordPress',
							'hosting'      => 'Webhosting & Domains',
							'entwicklung'  => 'ERP-Integration & Entwicklung',
							'sonstiges'    => 'Sonstiges',
						],
					],
					[
						'name'        => 'message',
						'label'       => 'Ihre Nachricht',
						'type'        => 'textarea',
						'required'    => true,
						'placeholder' => 'Wie kann ich Ihnen helfen?',
						'width'       => 'full',
					],
				],
				'privacy_page' => '/datenschutzerklaerung/',
				'submit_label' => 'Nachricht versenden',
				'success_msg'  => 'Vielen Dank für Ihre Anfrage. Ich melde mich in Kürze bei Ihnen.',
			],

			'quote' => [
				'title'     => 'Angebotsanfrage',
				'recipient' => 'info@srk-hosting.de',
				'subject'   => 'Hosting-Anfrage über die Website',
				'fields'    => [
					[
						'name'        => 'firstname',
						'label'       => 'Vorname',
						'type'        => 'text',
						'required'    => true,
						'width'       => 'half',
					],
					[
						'name'        => 'lastname',
						'label'       => 'Nachname',
						'type'        => 'text',
						'required'    => true,
						'width'       => 'half',
					],
					[
						'name'        => 'email',
						'label'       => 'E-Mail',
						'type'        => 'email',
						'required'    => true,
						'width'       => 'half',
					],
					[
						'name'        => 'phone',
						'label'       => 'Telefon',
						'type'        => 'tel',
						'required'    => true,
						'width'       => 'half',
					],
					[
						'name'        => 'company',
						'label'       => 'Firma',
						'type'        => 'text',
						'required'    => false,
						'width'       => 'full',
					],
					[
						'name'        => 'plan',
						'label'       => 'Gewünschter Plan',
						'type'        => 'select',
						'required'    => true,
						'width'       => 'full',
						'options'     => [
							''         => 'Bitte wählen…',
							'basic'    => 'Basic – 5 € / Monat',
							'extended' => 'Extended – 20 € / Monat',
							'custom'   => 'Custom – Auf Anfrage',
						],
					],
					[
						'name'        => 'domain',
						'label'       => 'Gewünschte Domain(s)',
						'type'        => 'text',
						'required'    => false,
						'placeholder' => 'z.B. meine-firma.de',
						'width'       => 'full',
					],
					[
						'name'        => 'message',
						'label'       => 'Anmerkungen',
						'type'        => 'textarea',
						'required'    => false,
						'placeholder' => 'Besondere Wünsche oder Anforderungen…',
						'width'       => 'full',
					],
				],
				'privacy_page' => '/datenschutzerklaerung/',
				'submit_label' => 'Angebot anfragen',
				'success_msg'  => 'Vielen Dank! Wir erstellen Ihr Angebot und melden uns in Kürze.',
			],
		];
	}
}
