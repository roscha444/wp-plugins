<?php
/**
 * Plugin Name: SRK SMTP Mailer
 * Description: SMTP-Konfiguration für WordPress mit Connection-Test und E-Mail-Log.
 * Version: 1.0.0
 * Author: Robin Schumacher
 * Author URI: https://srk-hosting.de
 * Text Domain: srk-smtp-mailer
 * Requires at least: 6.3
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'SRK_SMTP_VERSION', '1.0.0' );
define( 'SRK_SMTP_PATH', plugin_dir_path( __FILE__ ) );

require_once SRK_SMTP_PATH . 'includes/class-srk-smtp-settings.php';
require_once SRK_SMTP_PATH . 'includes/class-srk-smtp-logger.php';

// Install log table on activation.
register_activation_hook( __FILE__, [ 'SRK_SMTP_Logger', 'create_table' ] );

// Boot plugin.
add_action( 'plugins_loaded', function () {
	new SRK_SMTP_Settings();
	SRK_SMTP_Logger::init();
} );

// Configure PHPMailer to use SMTP.
add_action( 'phpmailer_init', function ( $phpmailer ) {
	$opts = get_option( 'srk_smtp_options', [] );

	if ( empty( $opts['host'] ) ) {
		return;
	}

	$phpmailer->isSMTP();
	$phpmailer->Host       = $opts['host'];
	$phpmailer->Port       = (int) ( $opts['port'] ?? 587 );
	$phpmailer->SMTPSecure = $opts['encryption'] ?? 'tls';
	$phpmailer->SMTPAuth   = true;
	$phpmailer->Username   = $opts['username'] ?? '';
	$phpmailer->Password   = $opts['password'] ?? '';

	if ( ! empty( $opts['from_email'] ) ) {
		$phpmailer->From     = $opts['from_email'];
		$phpmailer->FromName = $opts['from_name'] ?? get_bloginfo( 'name' );
	}
} );

// AJAX: Connection test.
add_action( 'wp_ajax_srk_smtp_test', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Keine Berechtigung.' );
	}

	check_ajax_referer( 'srk_smtp_test', 'nonce' );

	$opts = get_option( 'srk_smtp_options', [] );

	if ( empty( $opts['host'] ) || empty( $opts['username'] ) || empty( $opts['password'] ) ) {
		wp_send_json_error( 'SMTP-Einstellungen unvollständig.' );
	}

	require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
	require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
	require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

	$mailer = new PHPMailer\PHPMailer\PHPMailer( true );

	try {
		$mailer->isSMTP();
		$mailer->Host       = $opts['host'];
		$mailer->Port       = (int) ( $opts['port'] ?? 587 );
		$mailer->SMTPSecure = $opts['encryption'] ?? 'tls';
		$mailer->SMTPAuth   = true;
		$mailer->Username   = $opts['username'];
		$mailer->Password   = $opts['password'];
		$mailer->Timeout    = 10;

		$mailer->smtpConnect();
		$mailer->smtpClose();

		wp_send_json_success( 'Verbindung erfolgreich!' );
	} catch ( \Exception $e ) {
		wp_send_json_error( 'Verbindung fehlgeschlagen: ' . $e->getMessage() );
	}
} );
