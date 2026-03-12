<?php

defined( 'ABSPATH' ) || exit;

/**
 * Handles AJAX form submissions for all registered forms.
 */
class SRK_Form_Handler {

	public static function init(): void {
		add_action( 'wp_ajax_srk_cf_submit',        [ __CLASS__, 'handle' ] );
		add_action( 'wp_ajax_nopriv_srk_cf_submit', [ __CLASS__, 'handle' ] );
	}

	public static function handle(): void {
		$form_id = sanitize_key( $_POST['form_id'] ?? '' );

		if ( ! $form_id ) {
			wp_send_json_error( 'Ungültiges Formular.' );
		}

		// Verify nonce.
		if ( ! wp_verify_nonce( $_POST['srk_cf_nonce'] ?? '', 'srk_cf_submit_' . $form_id ) ) {
			wp_send_json_error( 'Sicherheitsprüfung fehlgeschlagen. Bitte laden Sie die Seite neu.' );
		}

		$config = SRK_Form_Registry::get( $form_id );

		if ( ! $config ) {
			wp_send_json_error( 'Formular nicht gefunden.' );
		}

		// Antispam checks.
		self::check_antispam();

		// Validate privacy consent.
		if ( 'yes' !== sanitize_text_field( $_POST['srk_privacy'] ?? '' ) ) {
			wp_send_json_error( 'Bitte stimmen Sie der Datenschutzerklärung zu.' );
		}

		// Collect and validate fields.
		$values = [];
		foreach ( $config['fields'] as $field ) {
			$raw = $_POST[ $field['name'] ] ?? '';

			$value = 'textarea' === $field['type']
				? sanitize_textarea_field( $raw )
				: ( 'email' === $field['type'] ? sanitize_email( $raw ) : sanitize_text_field( $raw ) );

			if ( ! empty( $field['required'] ) && '' === $value ) {
				wp_send_json_error( 'Bitte füllen Sie alle Pflichtfelder aus.' );
			}

			$values[ $field['name'] ] = [
				'label' => $field['label'],
				'value' => $value,
			];
		}

		// Build email.
		$recipient = $config['recipient'] ?? get_option( 'admin_email' );
		$subject   = $config['subject'] ?? 'Formular-Anfrage';

		// Append name or identifier to subject if available.
		$name_field = $values['name']['value'] ?? ( ( $values['firstname']['value'] ?? '' ) . ' ' . ( $values['lastname']['value'] ?? '' ) );
		$name_field = trim( $name_field );
		if ( $name_field ) {
			$subject .= ' – ' . $name_field;
		}

		$body = self::build_email_body( $config['title'] ?? $form_id, $values );

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		// Set From header from SMTP plugin settings or WordPress default.
		$smtp_opts  = get_option( 'srk_smtp_options', [] );
		$from_email = ! empty( $smtp_opts['from_email'] ) ? $smtp_opts['from_email'] : get_option( 'admin_email' );
		$from_name  = ! empty( $smtp_opts['from_name'] ) ? $smtp_opts['from_name'] : get_bloginfo( 'name' );
		$headers[]  = 'From: ' . $from_name . ' <' . $from_email . '>';

		// Set reply-to if email field exists.
		$reply_email = $values['email']['value'] ?? '';
		if ( $reply_email ) {
			$headers[] = 'Reply-To: ' . ( $name_field ?: $reply_email ) . ' <' . $reply_email . '>';
		}

		$sent = wp_mail( $recipient, $subject, $body, $headers );

		if ( $sent ) {
			wp_send_json_success( $config['success_msg'] ?? 'Nachricht gesendet.' );
		} else {
			wp_send_json_error( 'Nachricht konnte nicht gesendet werden. Bitte versuchen Sie es später erneut.' );
		}
	}

	private static function check_antispam(): void {
		$opts = get_option( 'srk_cf_options', [] );
		if ( isset( $opts['enable_antispam'] ) && ! $opts['enable_antispam'] ) {
			return;
		}

		// Honeypot: must be empty.
		if ( ! empty( $_POST['srk_cf_website'] ) ) {
			wp_send_json_error( 'Spam erkannt.' );
		}

		// Timestamp: form must be open for at least 3 seconds.
		$ts = (int) ( $_POST['srk_cf_ts'] ?? 0 );
		if ( ! $ts || ( time() - $ts ) < 3 ) {
			wp_send_json_error( 'Formular zu schnell abgesendet. Bitte versuchen Sie es erneut.' );
		}

		// Token: verify integrity (prevents replay with fake timestamps).
		$form_id = sanitize_key( $_POST['form_id'] ?? '' );
		$token   = $_POST['srk_cf_token'] ?? '';
		if ( ! $token || $token !== wp_hash( $form_id . '|' . $ts ) ) {
			wp_send_json_error( 'Sicherheitsprüfung fehlgeschlagen.' );
		}

		// Token age: reject if older than 1 hour (stale forms).
		if ( ( time() - $ts ) > HOUR_IN_SECONDS ) {
			wp_send_json_error( 'Formular abgelaufen. Bitte laden Sie die Seite neu.' );
		}
	}

	private static function build_email_body( string $title, array $values ): string {
		$separator = str_repeat( '=', 40 );

		$body = $title . "\n" . $separator . "\n\n";

		foreach ( $values as $field ) {
			$val   = $field['value'] ?: '–';
			$label = str_pad( $field['label'] . ':', 20 );
			$body .= "{$label}{$val}\n";
		}

		return $body;
	}
}
