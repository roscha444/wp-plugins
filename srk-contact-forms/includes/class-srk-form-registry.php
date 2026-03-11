<?php

defined( 'ABSPATH' ) || exit;

/**
 * Registry for form configurations.
 *
 * Forms can be registered by this plugin or by third-party plugins
 * using the `srk_contact_forms` filter.
 */
class SRK_Form_Registry {

	private static ?array $forms = null;

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

		$defaults = self::default_forms();

		/**
		 * Filter to register additional forms or modify existing ones.
		 *
		 * @param array $forms Associative array of form_id => config.
		 */
		self::$forms = apply_filters( 'srk_contact_forms', $defaults );

		return self::$forms;
	}

	/**
	 * Built-in form definitions.
	 */
	private static function default_forms(): array {
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
