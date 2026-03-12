<?php
/**
 * Plugin Name: SRK Security
 * Description: WordPress-Hardening: Security Headers, XML-RPC, Pingbacks, User-Enumeration, Login-Schutz und mehr.
 * Version: 1.0.0
 * Author: Robin Schumacher
 * Author URI: https://srk-hosting.de
 * Text Domain: srk-security
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
 * Unauthorized commercial redistribution without the author's consent
 * is not permitted. The name "SRK Security" and associated branding
 * are trademarks of Robin Schumacher / SRK Hosting and may not be used
 * without written permission.
 */

defined( 'ABSPATH' ) || exit;

define( 'SRK_SEC_VERSION', '1.0.0' );

// ── 1. Remove WordPress Version Disclosure ──
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// ── 2. Disable XML-RPC (Brute-Force vector) ──
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );

// ── 3. Disable Pingbacks (DDoS vector) ──
add_filter( 'wp_headers', function ( $headers ) {
	unset( $headers['X-Pingback'] );
	return $headers;
} );

// ── 4. Block Author Enumeration (?author=N) ──
add_action( 'template_redirect', function () {
	if ( isset( $_GET['author'] ) && ! is_admin() ) {
		wp_die( 'Zugriff verweigert.', 'Verboten', [ 'response' => 403 ] );
	}
} );

// ── 5. Block REST API User Enumeration ──
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );

// ── 6. Generic Login Error Message ──
add_filter( 'login_errors', function () {
	return 'Benutzername oder Passwort ist falsch.';
} );

// ── 7. Security Headers ──
add_action( 'send_headers', function () {
	if ( is_admin() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
} );

// ── 8. Disable File Editor in Admin ──
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// ── Admin Settings Page ──
add_action( 'admin_menu', function () {
	add_options_page(
		'SRK Security',
		'SRK Security',
		'manage_options',
		'srk-security',
		'srk_sec_render_page'
	);
} );

function srk_sec_render_page(): void {
	$measures = [
		[
			'title'       => 'WordPress-Version versteckt',
			'description' => 'Entfernt die WordPress-Versionsnummer aus dem HTML-Head und RSS-Feeds. Verhindert Information Disclosure.',
			'hook'        => 'the_generator',
		],
		[
			'title'       => 'XML-RPC deaktiviert',
			'description' => 'Blockiert die XML-RPC-Schnittstelle komplett. Verhindert Brute-Force-Angriffe über system.multicall.',
			'hook'        => 'xmlrpc_enabled',
		],
		[
			'title'       => 'Pingbacks deaktiviert',
			'description' => 'Entfernt den X-Pingback-Header. Verhindert Missbrauch als DDoS-Amplification-Vektor.',
			'hook'        => 'wp_headers',
		],
		[
			'title'       => 'Author-Enumeration blockiert',
			'description' => 'Blockiert ?author=N Anfragen mit HTTP 403. Verhindert das Ausspähen von Benutzernamen.',
			'hook'        => 'template_redirect',
		],
		[
			'title'       => 'REST API User-Enumeration blockiert',
			'description' => 'Entfernt /wp-json/wp/v2/users für nicht eingeloggte Besucher. Schützt Benutzernamen und IDs.',
			'hook'        => 'rest_endpoints',
		],
		[
			'title'       => 'Login-Fehlermeldungen generisch',
			'description' => 'Zeigt eine einheitliche Fehlermeldung bei fehlgeschlagenem Login. Verrät nicht, ob der Benutzername existiert.',
			'hook'        => 'login_errors',
		],
		[
			'title'       => 'Security Headers aktiv',
			'description' => 'X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, Referrer-Policy, Permissions-Policy.',
			'hook'        => 'send_headers',
		],
		[
			'title'       => 'Theme/Plugin-Editor deaktiviert',
			'description' => 'DISALLOW_FILE_EDIT verhindert das Bearbeiten von PHP-Dateien über das WordPress-Admin-Panel.',
			'hook'        => null,
			'check'       => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
		],
	];

	?>
	<div class="wrap">
		<h1>SRK Security</h1>
		<p style="font-size:14px;color:#64748b;margin-bottom:1.5rem;">
			Version <?php echo esc_html( SRK_SEC_VERSION ); ?> &mdash;
			Alle Schutzmaßnahmen sind automatisch aktiv. Keine Konfiguration erforderlich.
		</p>

		<table class="widefat striped" style="max-width:800px;">
			<thead>
				<tr>
					<th style="width:30px;"></th>
					<th>Schutzmaßnahme</th>
					<th>Beschreibung</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $measures as $m ) :
					$active = isset( $m['check'] ) ? $m['check'] : has_filter( $m['hook'] );
				?>
				<tr>
					<td>
						<?php if ( $active ) : ?>
							<span style="color:#16a34a;font-size:18px;font-weight:bold;" title="Aktiv">&#10003;</span>
						<?php else : ?>
							<span style="color:#dc2626;font-size:18px;font-weight:bold;" title="Inaktiv">&#10007;</span>
						<?php endif; ?>
					</td>
					<td style="font-weight:600;"><?php echo esc_html( $m['title'] ); ?></td>
					<td style="color:#64748b;font-size:13px;"><?php echo esc_html( $m['description'] ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div style="margin-top:2rem;padding:1rem 1.25rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;max-width:800px;">
			<strong style="color:#166534;">Alle Maßnahmen aktiv.</strong>
			<span style="color:#166534;">Dieses Plugin schützt Ihre WordPress-Installation automatisch.</span>
		</div>
	</div>
	<?php
}
