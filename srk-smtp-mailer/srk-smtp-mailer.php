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
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2016–2026 Robin Schumacher / SRK Hosting (https://srk-hosting.de)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * COMMERCIAL NOTICE:
 * This plugin is developed and sold by Robin Schumacher / SRK Hosting.
 * While the source code is licensed under GPL-2.0-or-later, purchasing
 * a license grants you access to updates, support, and future releases.
 * Updates, support and new releases require an active license.
 */

defined( 'ABSPATH' ) || exit;

define( 'SRK_SMTP_VERSION', '1.0.0' );
define( 'SRK_SMTP_PATH', plugin_dir_path( __FILE__ ) );

require_once SRK_SMTP_PATH . 'includes/class-srk-smtp-settings.php';
require_once SRK_SMTP_PATH . 'includes/class-srk-smtp-logger.php';

// Install tables on activation.
register_activation_hook( __FILE__, [ 'SRK_SMTP_Logger', 'create_table' ] );

// Auto-create tables if missing (e.g. after update without reactivation).
add_action( 'admin_init', function () {
	$db_version = get_option( 'srk_smtp_db_version', '0' );
	if ( version_compare( $db_version, '1.1.0', '<' ) ) {
		SRK_SMTP_Logger::create_table();
		update_option( 'srk_smtp_db_version', '1.1.0' );
	}
} );

// Boot plugin.
add_action( 'plugins_loaded', function () {
	new SRK_SMTP_Settings();
	SRK_SMTP_Logger::init();
} );

// DSGVO-compliant IP hash: HMAC-SHA256 with AUTH_KEY — not reversible.
function srk_smtp_hash_ip(): string {
	// Support reverse proxies (Cloudflare, nginx, load balancers).
	// Only trust X-Forwarded-For if REMOTE_ADDR is a known proxy.
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';

	// Only trust X-Forwarded-For if REMOTE_ADDR is a known trusted proxy.
	$trusted_proxies = apply_filters( 'srk_smtp_trusted_proxies', [ '127.0.0.1', '::1' ] );
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) && in_array( $ip, $trusted_proxies, true ) ) {
		// X-Forwarded-For can contain: "client, proxy1, proxy2" — take the first.
		$forwarded = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$candidate = trim( $forwarded[0] );
		if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
			$ip = $candidate;
		}
	}

	return $ip ? hash_hmac( 'sha256', $ip, AUTH_KEY ) : '';
}

// Track every send/fail in the rate table (always active, DSGVO-compliant).
add_action( 'wp_mail_succeeded', function () {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'srk_smtp_rate', [
		'status'  => 'sent',
		'ip_hash' => srk_smtp_hash_ip(),
	], [ '%s', '%s' ] );
} );

add_action( 'wp_mail_failed', function () {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'srk_smtp_rate', [
		'status'  => 'failed',
		'ip_hash' => srk_smtp_hash_ip(),
	], [ '%s', '%s' ] );
} );

// Probabilistic cleanup: remove rate entries older than 30 days (on success and failure).
$srk_smtp_cleanup = function () {
	if ( wp_rand( 1, 50 ) === 1 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}srk_smtp_rate WHERE created_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS )
		) );
	}
};
add_action( 'wp_mail_succeeded', $srk_smtp_cleanup );
add_action( 'wp_mail_failed', $srk_smtp_cleanup );

// Rate limiting: queries rate table — independent of log setting.
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	global $wpdb;
	$opts  = get_option( 'srk_smtp_options', [] );
	$table = $wpdb->prefix . 'srk_smtp_rate';

	$limit_hour = (int) ( $opts['rate_limit_hour'] ?? 30 );
	$limit_day  = (int) ( $opts['rate_limit_day'] ?? 100 );
	$limit_ip   = (int) ( $opts['rate_limit_ip'] ?? 5 );

	if ( 0 === $limit_hour && 0 === $limit_day && 0 === $limit_ip ) {
		return null;
	}

	// Check per-IP hourly limit (SELECT FOR UPDATE to prevent race conditions).
	if ( $limit_ip > 0 ) {
		$ip_hash = srk_smtp_hash_ip();
		if ( $ip_hash ) {
			$wpdb->query( 'SET @srk_lock = GET_LOCK("srk_rate_limit", 2)' );
			$count_ip = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND created_at >= %s",
				$ip_hash,
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			) );

			if ( $count_ip >= $limit_ip ) {
				$wpdb->query( 'DO RELEASE_LOCK("srk_rate_limit")' );
				$logging_enabled = ! isset( $opts['enable_log'] ) || $opts['enable_log'];
				if ( $logging_enabled ) {
					$wpdb->insert( $wpdb->prefix . 'srk_smtp_log', [
						'mail_type' => 'general',
						'subject'   => mb_substr( $atts['subject'] ?? '', 0, 255 ),
						'status'    => 'failed',
						'error_msg' => "IP-Rate-Limit erreicht: {$count_ip}/{$limit_ip} E-Mails pro Stunde (IP).",
					], [ '%s', '%s', '%s', '%s' ] );
				}
				return false;
			}
			$wpdb->query( 'DO RELEASE_LOCK("srk_rate_limit")' );
		}
	}

	// Check hourly limit.
	if ( $limit_hour > 0 ) {
		$count_hour = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'sent' AND created_at >= %s",
			gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
		) );

		if ( $count_hour >= $limit_hour ) {
			$logging_enabled = ! isset( $opts['enable_log'] ) || $opts['enable_log'];
			if ( $logging_enabled ) {
				$wpdb->insert( $wpdb->prefix . 'srk_smtp_log', [
					'mail_type' => 'general',
					'subject'   => mb_substr( $atts['subject'] ?? '', 0, 255 ),
					'status'    => 'failed',
					'error_msg' => "Rate-Limit erreicht: {$count_hour}/{$limit_hour} E-Mails pro Stunde.",
				], [ '%s', '%s', '%s', '%s' ] );
			}
			return false;
		}
	}

	// Check daily limit.
	if ( $limit_day > 0 ) {
		$count_day = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'sent' AND created_at >= %s",
			gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
		) );

		if ( $count_day >= $limit_day ) {
			$logging_enabled = ! isset( $opts['enable_log'] ) || $opts['enable_log'];
			if ( $logging_enabled ) {
				$wpdb->insert( $wpdb->prefix . 'srk_smtp_log', [
					'mail_type' => 'general',
					'subject'   => mb_substr( $atts['subject'] ?? '', 0, 255 ),
					'status'    => 'failed',
					'error_msg' => "Rate-Limit erreicht: {$count_day}/{$limit_day} E-Mails pro Tag.",
				], [ '%s', '%s', '%s', '%s' ] );
			}
			return false;
		}
	}

	return null;
}, 10, 2 );

// Sanitize outgoing email headers to prevent header injection attacks.
add_filter( 'wp_mail', function ( $args ) {
	// Strip newlines from subject (prevents header injection).
	$args['subject'] = preg_replace( '/[\r\n]+/', ' ', $args['subject'] );

	// Validate and sanitize To address.
	if ( is_string( $args['to'] ) ) {
		$args['to'] = sanitize_email( $args['to'] );
	}

	// Strip newlines from all custom headers.
	if ( ! empty( $args['headers'] ) ) {
		$headers = is_array( $args['headers'] ) ? $args['headers'] : explode( "\n", $args['headers'] );
		$args['headers'] = array_map( function ( $h ) {
			return preg_replace( '/[\r\n]+/', ' ', $h );
		}, $headers );
	}

	return $args;
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

	// Step 0: SSRF protection — block internal/private hosts.
	$resolved_ip = gethostbyname( $host );
	if ( $resolved_ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
		wp_send_json_error( 'DNS-Auflösung fehlgeschlagen. Bitte prüfen Sie den Hostnamen.' );
	}
	$check_ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : $resolved_ip;
	if ( $check_ip && ! filter_var( $check_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		wp_send_json_error( 'Verbindung zu internen/privaten IP-Adressen ist nicht erlaubt.' );
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

		// Extract useful lines from debug log (strip credentials and sensitive data).
		$useful_lines = [];
		foreach ( explode( "\n", $debug_log ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) continue;
			// Skip lines containing credentials.
			if ( preg_match( '/^CLIENT\s*->.*AUTH\s+(PLAIN|LOGIN)\s+\S/i', $line ) ) continue;
			// Show server responses and errors only.
			if ( preg_match( '/^SERVER\s*->|^SMTP ERROR|^\d{3}\s|STARTTLS/i', $line ) ) {
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

	// Rate limit test emails: max 5 per hour.
	$test_count = (int) get_transient( 'srk_smtp_test_count' );
	if ( $test_count >= 5 ) {
		wp_send_json_error( 'Test-E-Mail-Limit erreicht (max. 5 pro Stunde).' );
	}
	set_transient( 'srk_smtp_test_count', $test_count + 1, HOUR_IN_SECONDS );

	$from_email = ! empty( $opts['from_email'] ) ? $opts['from_email'] : get_option( 'admin_email' );
	$from_name  = ! empty( $opts['from_name'] ) ? $opts['from_name'] : get_bloginfo( 'name' );
	$to         = ! empty( $_POST['to'] ) ? sanitize_email( $_POST['to'] ) : $from_email;

	if ( ! is_email( $to ) ) {
		wp_send_json_error( 'Ungültige Empfänger-Adresse.' );
	}
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

// AJAX: Clear rate limit counters.
add_action( 'wp_ajax_srk_smtp_clear_rate', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Keine Berechtigung.' );
	}

	check_ajax_referer( 'srk_smtp_clear_rate', 'nonce' );

	global $wpdb;
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}srk_smtp_rate" );

	wp_send_json_success( 'Rate-Limits zurückgesetzt.' );
} );

// AJAX: Clear email log.
add_action( 'wp_ajax_srk_smtp_clear_log', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Keine Berechtigung.' );
	}

	check_ajax_referer( 'srk_smtp_clear_log', 'nonce' );

	global $wpdb;
	$table = $wpdb->prefix . 'srk_smtp_log';
	$wpdb->query( "TRUNCATE TABLE {$table}" );

	wp_send_json_success( 'Log gelöscht.' );
} );
