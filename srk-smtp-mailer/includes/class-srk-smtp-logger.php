<?php

defined( 'ABSPATH' ) || exit;

class SRK_SMTP_Logger {

	public static function init(): void {
		add_action( 'wp_mail_succeeded', [ __CLASS__, 'log_success' ] );
		add_action( 'wp_mail_failed',    [ __CLASS__, 'log_failure' ] );
	}

	public static function create_table(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'srk_smtp_log';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sent_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			mail_type  VARCHAR(50)     NOT NULL DEFAULT 'general',
			subject    VARCHAR(255)    NOT NULL DEFAULT '',
			status     VARCHAR(10)     NOT NULL DEFAULT 'sent',
			error_msg  TEXT            NULL,
			PRIMARY KEY (id),
			KEY idx_sent_at (sent_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a successfully sent email.
	 *
	 * @param array $mail_data Data from wp_mail_succeeded action.
	 */
	public static function log_success( array $mail_data ): void {
		self::insert( $mail_data['subject'] ?? '', 'sent' );
	}

	/**
	 * Log a failed email.
	 *
	 * @param \WP_Error $error Error from wp_mail_failed action.
	 */
	public static function log_failure( \WP_Error $error ): void {
		$data    = $error->get_error_data();
		$subject = '';

		if ( is_array( $data ) && isset( $data['subject'] ) ) {
			$subject = $data['subject'];
		}

		self::insert( $subject, 'failed', $error->get_error_message() );
	}

	/**
	 * Determine the mail type from the subject line.
	 */
	private static function detect_type( string $subject ): string {
		$subject_lower = mb_strtolower( $subject );

		$map = [
			'kontaktanfrage'  => 'contact',
			'hosting-anfrage' => 'quote',
			'angebotsanfrage' => 'quote',
			'passwort'        => 'system',
			'password'        => 'system',
		];

		foreach ( $map as $keyword => $type ) {
			if ( str_contains( $subject_lower, $keyword ) ) {
				return $type;
			}
		}

		return 'general';
	}

	private static function insert( string $subject, string $status, ?string $error_msg = null ): void {
		$opts = get_option( 'srk_smtp_options', [] );
		if ( isset( $opts['enable_log'] ) && ! $opts['enable_log'] ) {
			return;
		}

		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'srk_smtp_log',
			[
				'mail_type' => self::detect_type( $subject ),
				'subject'   => mb_substr( $subject, 0, 255 ),
				'status'    => $status,
				'error_msg' => $error_msg,
			],
			[ '%s', '%s', '%s', '%s' ]
		);
	}
}
