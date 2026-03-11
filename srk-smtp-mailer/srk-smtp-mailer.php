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

	$host       = $opts['host'];
	$port       = (int) ( $opts['port'] ?? 587 );
	$encryption = $opts['encryption'] ?? 'tls';
	$username   = $opts['username'];
	$password   = $opts['password'];

	// Step 1: Basic DNS / connectivity check.
	$ip = gethostbyname( $host );
	if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
		wp_send_json_error(
			"DNS-Auflösung fehlgeschlagen: Der Host \"{$host}\" konnte nicht aufgelöst werden. "
			. 'Bitte prüfen Sie den Hostnamen.'
		);
	}

	// Step 2: Socket-level port check.
	$errno  = 0;
	$errstr = '';
	$scheme = 'ssl' === $encryption ? 'ssl://' : '';
	$conn   = @fsockopen( $scheme . $host, $port, $errno, $errstr, 10 );

	if ( ! $conn ) {
		$detail  = "Host: {$host}, Port: {$port}, Verschlüsselung: " . ( $encryption ?: 'Keine' ) . "\n";
		$detail .= "Fehlercode: {$errno}\n";
		$detail .= "Fehlermeldung: {$errstr}\n\n";
		$detail .= "Mögliche Ursachen:\n";
		$detail .= "• Port {$port} ist durch eine Firewall blockiert\n";
		$detail .= "• Der SMTP-Server ist nicht erreichbar\n";
		if ( 587 === $port && 'ssl' === $encryption ) {
			$detail .= "• Port 587 verwendet normalerweise TLS, nicht SSL (versuchen Sie Port 465 für SSL)\n";
		}
		if ( 465 === $port && 'tls' === $encryption ) {
			$detail .= "• Port 465 verwendet normalerweise SSL, nicht TLS (versuchen Sie Port 587 für TLS)\n";
		}

		wp_send_json_error( "Verbindung zum Server fehlgeschlagen:\n\n{$detail}" );
	}
	fclose( $conn );

	// Step 3: SMTP authentication test with debug output.
	$mailer = new PHPMailer\PHPMailer\PHPMailer( true );

	// Capture SMTP debug output.
	$debug_log = '';
	$mailer->SMTPDebug = 3;
	$mailer->Debugoutput = function ( $str ) use ( &$debug_log ) {
		$debug_log .= trim( $str ) . "\n";
	};

	try {
		$mailer->isSMTP();
		$mailer->Host       = $host;
		$mailer->Port       = $port;
		$mailer->SMTPSecure = $encryption;
		$mailer->SMTPAuth   = true;
		$mailer->Username   = $username;
		$mailer->Password   = $password;
		$mailer->Timeout    = 10;

		$mailer->smtpConnect();
		$mailer->smtpClose();

		wp_send_json_success(
			"Verbindung erfolgreich!\n\n"
			. "Host: {$host}:{$port} ({$encryption})\n"
			. "Authentifizierung: OK"
		);
	} catch ( \Exception $e ) {
		$error  = "SMTP-Authentifizierung fehlgeschlagen:\n\n";
		$error .= "Host: {$host}:{$port} ({$encryption})\n";
		$error .= "Benutzer: {$username}\n\n";
		$error .= "Fehler: " . $e->getMessage() . "\n\n";

		// Extract useful lines from debug log.
		$useful_lines = [];
		foreach ( explode( "\n", $debug_log ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) continue;
			// Show server responses and errors.
			if ( preg_match( '/^SERVER\s*->|^SMTP ERROR|^\d{3}\s|AUTH|LOGIN|STARTTLS/i', $line ) ) {
				$useful_lines[] = $line;
			}
		}

		if ( $useful_lines ) {
			$error .= "Server-Log:\n" . implode( "\n", array_slice( $useful_lines, 0, 15 ) );
		}

		wp_send_json_error( $error );
	}
} );
