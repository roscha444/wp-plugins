<?php

defined( 'ABSPATH' ) || exit;

class SRK_SMTP_Logger {

	public static function init(): void {
		add_action( 'wp_mail_succeeded', [ __CLASS__, 'log_success' ] );
		add_action( 'wp_mail_failed',    [ __CLASS__, 'log_failure' ] );
	}

	public static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Email log table (optional, can be disabled).
		$log_table = $wpdb->prefix . 'srk_smtp_log';
		dbDelta( "CREATE TABLE {$log_table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sent_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			mail_type  VARCHAR(50)     NOT NULL DEFAULT 'general',
			subject    VARCHAR(255)    NOT NULL DEFAULT '',
			status     VARCHAR(10)     NOT NULL DEFAULT 'sent',
			error_msg  TEXT            NULL,
			PRIMARY KEY (id),
			KEY idx_sent_at (sent_at)
		) {$charset};" );

		// Rate limit / statistics table (always active, DSGVO-compliant: no personal data).
		// ip_hash = HMAC-SHA256 of IP with AUTH_KEY salt — not reversible.
		$rate_table = $wpdb->prefix . 'srk_smtp_rate';
		dbDelta( "CREATE TABLE {$rate_table} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			status     VARCHAR(10)     NOT NULL DEFAULT 'sent',
			ip_hash    VARCHAR(64)     NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_created_status (created_at, status),
			KEY idx_ip_created (ip_hash, created_at)
		) {$charset};" );
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

		$table = $wpdb->prefix . 'srk_smtp_log';

		$wpdb->insert(
			$table,
			[
				'mail_type' => self::detect_type( $subject ),
				'subject'   => mb_substr( $subject, 0, 255 ),
				'status'    => $status,
				'error_msg' => $error_msg,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		// Probabilistic cleanup: delete entries older than 90 days.
		if ( wp_rand( 1, 100 ) === 1 ) {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE sent_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
			) );
		}
	}
}
