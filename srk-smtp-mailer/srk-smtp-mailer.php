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
	$phpmailer->Password   = SRK_SMTP_Settings::decrypt_password( $opts['password'] ?? '' );
	if ( ! empty( $opts['allow_self_signed'] ) ) {
		$phpmailer->SMTPOptions = [
			'ssl' => [
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			],
		];
	}

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
	$password   = SRK_SMTP_Settings::decrypt_password( $opts['password'] );

	// Step 1: Basic DNS / connectivity check.
	$ip = gethostbyname( $host );
	if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
		wp_send_json_error(
			"DNS-Auflösung fehlgeschlagen: Der Host \"{$host}\" konnte nicht aufgelöst werden. "
			. 'Bitte prüfen Sie den Hostnamen.'
		);
	}

	// Step 2: Socket-level port check (non-blocking, informational only).
	$socket_ok = false;
	$socket_info = '';
	$errno  = 0;
	$errstr = '';
	$scheme = 'ssl' === $encryption ? 'ssl://' : '';
	$conn   = @fsockopen( $scheme . $host, $port, $errno, $errstr, 10 );

	if ( $conn ) {
		$socket_ok = true;
		fclose( $conn );
	} else {
		$socket_info  = "Socket-Vortest: Port {$port} nicht direkt erreichbar";
		if ( $errno ) {
			$socket_info .= " (Code {$errno}: {$errstr})";
		}
		$socket_info .= "\nDies kann an der lokalen PHP-Konfiguration liegen. PHPMailer-Test wird trotzdem durchgeführt.\n\n";
	}

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
		$mailer->Timeout    = 15;

		if ( ! empty( $opts['allow_self_signed'] ) ) {
			$mailer->SMTPOptions = [
				'ssl' => [
					'verify_peer'       => false,
					'verify_peer_name'  => false,
					'allow_self_signed' => true,
				],
			];
		}

		$mailer->smtpConnect();
		$mailer->smtpClose();

		wp_send_json_success(
			"Verbindung erfolgreich!\n\n"
			. "Host: {$host}:{$port} ({$encryption})\n"
			. "Authentifizierung: OK"
		);
	} catch ( \Exception $e ) {
		$error = '';
		if ( $socket_info ) {
			$error .= $socket_info;
		}
		$error .= "SMTP-Verbindung fehlgeschlagen:\n\n";
		$error .= "Host: {$host}:{$port} ({$encryption})\n";
		$error .= "Benutzer: {$username}\n\n";
		$error .= "Fehler: " . $e->getMessage() . "\n\n";

		// Check for common issues.
		$msg = $e->getMessage();
		if ( str_contains( $msg, 'Could not connect' ) ) {
			$error .= "Mögliche Ursachen:\n";
			$error .= "• Port {$port} ist blockiert (Firewall oder Hoster)\n";
			$error .= "• Falsche Port/Verschlüsselung-Kombination (465=SSL, 587=TLS)\n";
			$error .= "• PHP OpenSSL-Erweiterung fehlt oder ist deaktiviert\n";
			$error .= "• Lokale Entwicklungsumgebung blockiert ausgehende SMTP-Verbindungen\n";
		} elseif ( str_contains( $msg, 'authenticate' ) || str_contains( $msg, 'AUTH' ) ) {
			$error .= "Mögliche Ursachen:\n";
			$error .= "• Falsches Passwort oder Benutzername\n";
			$error .= "• Konto ist gesperrt oder deaktiviert\n";
		}
		$error .= "\n";

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

// AJAX: Send test email.
add_action( 'wp_ajax_srk_smtp_send_test', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Keine Berechtigung.' );
	}

	check_ajax_referer( 'srk_smtp_test', 'nonce' );

	$opts = get_option( 'srk_smtp_options', [] );

	if ( empty( $opts['host'] ) || empty( $opts['username'] ) || empty( $opts['password'] ) ) {
		wp_send_json_error( 'SMTP-Einstellungen unvollständig.' );
	}

	$from_email = ! empty( $opts['from_email'] ) ? $opts['from_email'] : get_option( 'admin_email' );
	$from_name  = ! empty( $opts['from_name'] ) ? $opts['from_name'] : get_bloginfo( 'name' );
	$to         = $from_email;
	$subject    = 'SRK SMTP Mailer – Test-E-Mail';
	$body       = "Dies ist eine automatische Test-E-Mail vom SRK SMTP Mailer Plugin.\n\n"
		. "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt "
		. "ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation "
		. "ullamco laboris nisi ut aliquip ex ea commodo consequat.\n\n"
		. "Wenn Sie diese E-Mail erhalten, funktioniert der SMTP-Versand korrekt.\n\n"
		. "Gesendet: " . wp_date( 'd.m.Y H:i:s' ) . "\n"
		. "Server: " . ( $opts['host'] ?? '' ) . ':' . ( $opts['port'] ?? 587 );

	$headers = [
		'From: ' . $from_name . ' <' . $from_email . '>',
	];

	$sent = wp_mail( $to, $subject, $body, $headers );

	if ( $sent ) {
		wp_send_json_success( "Test-E-Mail erfolgreich gesendet an: {$to}" );
	} else {
		global $phpmailer;
		$error_msg = "Test-E-Mail konnte nicht gesendet werden.";
		if ( isset( $phpmailer ) && $phpmailer->ErrorInfo ) {
			$error_msg .= "\n\nFehler: " . $phpmailer->ErrorInfo;
		}
		wp_send_json_error( $error_msg );
	}
} );
