<?php
/**
 * Plugin Name: SRK Security
 * Description: WordPress-Hardening: CSP mit Nonce, Security Headers, XML-RPC, Pingbacks, User-Enumeration, Login-Schutz und mehr.
 * Version: 1.1.0
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
 * Updates, support and new releases require an active license.
 */

defined( 'ABSPATH' ) || exit;

define( 'SRK_SEC_VERSION', '1.1.0' );

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

// ── 7. Disable File Editor in Admin ──
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}

// ═══════════════════════════════════════════════════════
// ── 8. Content Security Policy (CSP) with Nonce ──
// ═══════════════════════════════════════════════════════

/**
 * CSP Nonce Manager
 *
 * Generates a per-request nonce and injects it into all inline
 * <script> and <style> tags so that `unsafe-inline` is NOT needed.
 * Admins can whitelist external domains via Settings.
 */
class SRK_CSP {

	private static ?string $nonce = null;

	/**
	 * Get or generate the CSP nonce for this request.
	 */
	public static function nonce(): string {
		if ( null === self::$nonce ) {
			self::$nonce = base64_encode( random_bytes( 16 ) );
		}
		return self::$nonce;
	}

	/**
	 * Get the saved CSP whitelist from options.
	 */
	public static function whitelist(): array {
		$raw = get_option( 'srk_sec_csp_whitelist', '' );
		if ( empty( $raw ) ) {
			return [];
		}
		$domains = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
		$safe    = [];
		foreach ( $domains as $domain ) {
			// Only allow valid domain patterns (with optional wildcard prefix)
			if ( preg_match( '#^(\*\.)?[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z]{2,})+$#i', $domain ) ) {
				$safe[] = $domain;
			}
		}
		return $safe;
	}

	/**
	 * Check if CSP is enabled.
	 */
	public static function enabled(): bool {
		return (bool) get_option( 'srk_sec_csp_enabled', true );
	}

	/**
	 * Build the full CSP header value.
	 */
	public static function build_header(): string {
		$nonce    = self::nonce();
		$wl       = self::whitelist();
		$wl_str   = ! empty( $wl ) ? ' ' . implode( ' ', $wl ) : '';

		$directives = [
			"default-src 'self'",
			"script-src 'self' 'nonce-{$nonce}'{$wl_str}",
			"style-src 'self' 'nonce-{$nonce}'{$wl_str}",
			"style-src-attr 'unsafe-inline'",
			"img-src 'self' data:{$wl_str}",
			"font-src 'self'{$wl_str}",
			"connect-src 'self'{$wl_str}",
			"frame-src 'self'",
			"frame-ancestors 'self'",
			"base-uri 'self'",
			"form-action 'self'",
			"object-src 'none'",
		];

		return implode( '; ', $directives );
	}

	/**
	 * Initialize all hooks.
	 */
	public static function init(): void {
		if ( ! self::enabled() || is_admin() ) {
			return;
		}

		// Add nonce to enqueued script tags
		add_filter( 'script_loader_tag', [ __CLASS__, 'add_nonce_to_script' ], 999, 2 );

		// Add nonce to inline scripts
		add_filter( 'wp_inline_script_attributes', [ __CLASS__, 'add_nonce_attr' ], 999 );

		// Add nonce to enqueued style tags
		add_filter( 'style_loader_tag', [ __CLASS__, 'add_nonce_to_style' ], 999, 2 );

		// Capture and nonce inline styles via output buffering
		add_action( 'wp_head', [ __CLASS__, 'ob_start' ], 0 );
		add_action( 'wp_head', [ __CLASS__, 'ob_flush' ], PHP_INT_MAX );
		add_action( 'wp_footer', [ __CLASS__, 'ob_start' ], 0 );
		add_action( 'wp_footer', [ __CLASS__, 'ob_flush' ], PHP_INT_MAX );
	}

	/**
	 * Add nonce attribute to <script> tags.
	 */
	public static function add_nonce_to_script( string $tag, string $handle ): string {
		if ( str_contains( $tag, 'nonce=' ) ) {
			return $tag;
		}
		return str_replace( '<script ', '<script nonce="' . esc_attr( self::nonce() ) . '" ', $tag );
	}

	/**
	 * Add nonce to inline script attributes array.
	 */
	public static function add_nonce_attr( array $attrs ): array {
		$attrs['nonce'] = self::nonce();
		return $attrs;
	}

	/**
	 * Add nonce attribute to <style> and <link rel="stylesheet"> tags.
	 */
	public static function add_nonce_to_style( string $tag, string $handle ): string {
		if ( str_contains( $tag, 'nonce=' ) ) {
			return $tag;
		}
		if ( str_contains( $tag, '<link' ) ) {
			return str_replace( '<link ', '<link nonce="' . esc_attr( self::nonce() ) . '" ', $tag );
		}
		return str_replace( '<style', '<style nonce="' . esc_attr( self::nonce() ) . '"', $tag );
	}

	/**
	 * Start output buffering to catch inline <style> blocks.
	 */
	public static function ob_start(): void {
		ob_start();
	}

	/**
	 * Flush buffer and inject nonces into any <style> and <script> tags without them.
	 */
	public static function ob_flush(): void {
		$html = ob_get_clean();
		if ( ! empty( $html ) ) {
			$nonce = esc_attr( self::nonce() );
			// Add nonce to <style> tags that don't have one yet
			$html = preg_replace_callback(
				'#<style(\s[^>]*)?>(?!.*nonce=)#i',
				function ( $matches ) use ( $nonce ) {
					if ( str_contains( $matches[0], 'nonce=' ) ) {
						return $matches[0];
					}
					$attrs = $matches[1] ?? '';
					return '<style nonce="' . $nonce . '"' . $attrs . '>';
				},
				$html
			);
			// Add nonce to <script> tags that don't have one yet
			// (catches wp_localize_script output and other inline scripts)
			$html = preg_replace_callback(
				'#<script(\s[^>]*)?>(?!.*nonce=)#i',
				function ( $matches ) use ( $nonce ) {
					if ( str_contains( $matches[0], 'nonce=' ) ) {
						return $matches[0];
					}
					$attrs = $matches[1] ?? '';
					return '<script nonce="' . $nonce . '"' . $attrs . '>';
				},
				$html
			);
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}

// Init CSP on template_redirect (after is_admin() is reliable)
add_action( 'template_redirect', [ 'SRK_CSP', 'init' ], 0 );

// ── 9. Security Headers (incl. CSP) ──
add_action( 'send_headers', function () {
	if ( is_admin() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );

	if ( SRK_CSP::enabled() ) {
		header( 'Content-Security-Policy: ' . SRK_CSP::build_header() );
	}
} );

// ═══════════════════════════════════════════════════════
// ── Admin Settings ──
// ═══════════════════════════════════════════════════════

add_action( 'admin_init', function () {
	register_setting( 'srk_sec_settings', 'srk_sec_csp_enabled', [
		'type'              => 'boolean',
		'default'           => true,
		'sanitize_callback' => 'rest_sanitize_boolean',
	] );
	register_setting( 'srk_sec_settings', 'srk_sec_csp_whitelist', [
		'type'              => 'string',
		'default'           => '',
		'sanitize_callback' => 'sanitize_textarea_field',
	] );
} );

add_action( 'admin_menu', function () {
	add_menu_page(
		'SRK Security',
		'SRK Security',
		'manage_options',
		'srk-security',
		'srk_sec_render_page',
		'dashicons-shield',
		81
	);
} );

function srk_sec_render_page(): void {
	$measures = [
		[
			'title'       => 'WordPress-Version versteckt',
			'description' => 'Entfernt die WordPress-Versionsnummer aus dem HTML-Head und RSS-Feeds.',
			'hook'        => 'the_generator',
		],
		[
			'title'       => 'XML-RPC deaktiviert',
			'description' => 'Blockiert die XML-RPC-Schnittstelle komplett. Verhindert Brute-Force über system.multicall.',
			'hook'        => 'xmlrpc_enabled',
		],
		[
			'title'       => 'Pingbacks deaktiviert',
			'description' => 'Entfernt den X-Pingback-Header. Verhindert DDoS-Amplification.',
			'hook'        => 'wp_headers',
		],
		[
			'title'       => 'Author-Enumeration blockiert',
			'description' => 'Blockiert ?author=N mit HTTP 403.',
			'hook'        => 'template_redirect',
		],
		[
			'title'       => 'REST API User-Enumeration blockiert',
			'description' => 'Entfernt /wp-json/wp/v2/users für nicht eingeloggte Besucher.',
			'hook'        => 'rest_endpoints',
		],
		[
			'title'       => 'Login-Fehlermeldungen generisch',
			'description' => 'Verrät nicht, ob ein Benutzername existiert.',
			'hook'        => 'login_errors',
		],
		[
			'title'       => 'Security Headers aktiv',
			'description' => 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy.',
			'hook'        => 'send_headers',
		],
		[
			'title'       => 'Theme/Plugin-Editor deaktiviert',
			'description' => 'DISALLOW_FILE_EDIT verhindert PHP-Bearbeitung im Admin.',
			'hook'        => null,
			'check'       => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
		],
		[
			'title'       => 'Content Security Policy (CSP)',
			'description' => 'Strict CSP mit Nonce. Blockiert alle nicht-erlaubten externen Ressourcen.',
			'hook'        => null,
			'check'       => SRK_CSP::enabled(),
		],
	];

	// Handle form save
	if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) {
		echo '<div class="notice notice-success is-dismissible"><p>Einstellungen gespeichert.</p></div>';
	}

	?>
	<div class="wrap">
		<h1>SRK Security</h1>
		<p style="font-size:14px;color:#64748b;margin-bottom:1.5rem;">
			Version <?php echo esc_html( SRK_SEC_VERSION ); ?>
		</p>

		<h2>Schutzmaßnahmen</h2>
		<table class="widefat striped" style="max-width:800px;">
			<thead>
				<tr>
					<th style="width:30px;"></th>
					<th>Maßnahme</th>
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

		<hr style="margin:2rem 0;max-width:800px;">

		<h2>Content Security Policy (CSP)</h2>
		<form method="post" action="options.php" style="max-width:800px;">
			<?php settings_fields( 'srk_sec_settings' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">CSP aktivieren</th>
					<td>
						<label>
							<input type="checkbox" name="srk_sec_csp_enabled" value="1" <?php checked( SRK_CSP::enabled() ); ?>>
							Content Security Policy mit Nonce aktivieren
						</label>
						<p class="description">
							Blockiert alle externen Ressourcen (Scripts, Styles, Fonts, Bilder) die nicht explizit erlaubt sind.
							Inline-Scripts und -Styles funktionieren nur mit dem automatisch gesetzten Nonce.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Domain-Whitelist</th>
					<td>
						<textarea name="srk_sec_csp_whitelist" rows="8" class="large-text code" placeholder="Beispiel:&#10;cdn.example.com&#10;*.googleapis.com&#10;fonts.gstatic.com"><?php echo esc_textarea( get_option( 'srk_sec_csp_whitelist', '' ) ); ?></textarea>
						<p class="description">
							Eine Domain pro Zeile. Wildcard-Prefix erlaubt: <code>*.example.com</code><br>
							Diese Domains werden in <code>script-src</code>, <code>style-src</code>, <code>img-src</code>,
							<code>font-src</code> und <code>connect-src</code> erlaubt.
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Speichern' ); ?>
		</form>

		<?php if ( SRK_CSP::enabled() ) : ?>
		<div style="margin-top:1rem;padding:1rem 1.25rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;max-width:800px;">
			<strong style="color:#1e40af;">Aktive CSP-Policy:</strong>
			<code style="display:block;margin-top:8px;padding:10px;background:#f8fafc;border-radius:4px;word-break:break-all;font-size:12px;line-height:1.6;">
				<?php echo esc_html( SRK_CSP::build_header() ); ?>
			</code>
		</div>
		<?php endif; ?>

		<?php
		$wl = SRK_CSP::whitelist();
		if ( ! empty( $wl ) ) : ?>
		<div style="margin-top:1rem;padding:1rem 1.25rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;max-width:800px;">
			<strong style="color:#166534;">Erlaubte externe Domains:</strong>
			<ul style="margin:8px 0 0;padding-left:20px;">
				<?php foreach ( $wl as $domain ) : ?>
					<li><code><?php echo esc_html( $domain ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>
	</div>
	<?php
}
