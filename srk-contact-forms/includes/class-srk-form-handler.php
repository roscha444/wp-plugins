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

			// Strip URLs from non-URL fields to prevent phishing links.
			if ( 'email' !== $field['type'] ) {
				$value = self::strip_dangerous_content( $value );
			}

			$values[ $field['name'] ] = [
				'label' => $field['label'],
				'value' => $value,
			];
		}

		// Build email.
		$recipient = $config['recipient'] ?? get_option( 'admin_email' );
		$subject   = self::sanitize_header_value( $config['subject'] ?? 'Formular-Anfrage' );

		// Append name or identifier to subject if available.
		$name_field = $values['name']['value'] ?? ( ( $values['firstname']['value'] ?? '' ) . ' ' . ( $values['lastname']['value'] ?? '' ) );
		$name_field = trim( $name_field );
		if ( $name_field ) {
			$subject .= ' – ' . self::sanitize_header_value( $name_field );
		}

		$body = self::build_email_body( $config['title'] ?? $form_id, $values );

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		// Set From header from SMTP plugin settings or WordPress default.
		$smtp_opts  = get_option( 'srk_smtp_options', [] );
		$from_email = ! empty( $smtp_opts['from_email'] ) ? $smtp_opts['from_email'] : get_option( 'admin_email' );
		$from_name  = ! empty( $smtp_opts['from_name'] ) ? $smtp_opts['from_name'] : get_bloginfo( 'name' );
		$headers[]  = 'From: ' . self::sanitize_header_value( $from_name ) . ' <' . $from_email . '>';

		// Set reply-to if email field exists (validated email only).
		$reply_email = $values['email']['value'] ?? '';
		if ( $reply_email && is_email( $reply_email ) ) {
			$safe_name = self::sanitize_header_value( $name_field ?: $reply_email );
			$headers[] = 'Reply-To: ' . $safe_name . ' <' . $reply_email . '>';
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

	/**
	 * Strip URLs, HTML tags, and suspicious patterns from user input.
	 */
	private static function strip_dangerous_content( string $value ): string {
		// Remove any HTML tags.
		$value = wp_strip_all_tags( $value );

		// Remove URLs (http/https/ftp).
		$value = preg_replace( '#https?://\S+#i', '[Link entfernt]', $value );
		$value = preg_replace( '#ftp://\S+#i', '[Link entfernt]', $value );

		// Remove bare domain patterns (example.com/path).
		$value = preg_replace( '#\b\w+\.\w{2,6}/\S+#i', '[Link entfernt]', $value );

		// Remove <script>, javascript:, data: patterns.
		$value = preg_replace( '#(javascript|data|vbscript)\s*:#i', '', $value );

		return $value;
	}

	/**
	 * Sanitize values used in email headers to prevent header injection.
	 * Strips newlines, carriage returns, and null bytes.
	 */
	private static function sanitize_header_value( string $value ): string {
		return preg_replace( '/[\r\n\0]+/', ' ', $value );
	}

	private static function build_email_body( string $title, array $values ): string {
		$separator = str_repeat( '─', 44 );
		$site_name = get_bloginfo( 'name' );
		$date      = wp_date( 'd.m.Y \u\m H:i \U\h\r' );

		$body  = "╔══════════════════════════════════════════╗\n";
		$body .= "  {$title}\n";
		$body .= "╚══════════════════════════════════════════╝\n\n";
		$body .= "Eingegangen am {$date}\n";
		$body .= "{$separator}\n\n";

		foreach ( $values as $field ) {
			$val   = $field['value'] ?: '–';
			$label = $field['label'];
			$body .= "  {$label}:\n";
			$body .= "  {$val}\n\n";
		}

		$body .= "{$separator}\n";
		$body .= "Diese Nachricht wurde über das Kontaktformular\n";
		$body .= "auf {$site_name} gesendet.\n";

		return $body;
	}
}
