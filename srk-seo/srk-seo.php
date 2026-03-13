<?php
/**
 * Plugin Name: SRK SEO
 * Description: SEO-Titel und Meta-Beschreibungen pro Seite verwalten.
 * Version: 1.1.0
 * Author: Robin Schumacher
 * Author URI: https://srk-hosting.de
 * Text Domain: srk-seo
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

final class SRK_SEO {

	const VERSION = '1.1.0';

	/** Post-meta keys used by this plugin. */
	const META_TITLE     = '_srk_seo_title';
	const META_DESC      = '_srk_seo_description';
	const META_CANONICAL = '_srk_seo_canonical';
	const META_ROBOTS    = '_srk_seo_robots';
	const META_KEYWORD   = '_srk_seo_focus_keyword';
	const META_OG_TITLE  = '_srk_seo_og_title';
	const META_OG_DESC   = '_srk_seo_og_description';
	const META_OG_IMAGE  = '_srk_seo_og_image';

	public function __construct() {
		// Admin pages.
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_save' ] );

		// Frontend output.
		add_filter( 'document_title_parts', [ $this, 'filter_title' ] );
		add_action( 'wp_head', [ $this, 'output_head' ], 1 );
	}

	/* ── Admin Menu ────────────────────────────────────────────────── */

	public function add_menu(): void {
		add_menu_page(
			'SRK SEO',
			'SRK SEO',
			'manage_options',
			'srk-seo',
			[ $this, 'render_overview' ],
			'dashicons-search',
			90
		);

		add_submenu_page(
			'srk-seo',
			'Übersicht',
			'Übersicht',
			'manage_options',
			'srk-seo',
			[ $this, 'render_overview' ]
		);

		add_submenu_page(
			'srk-seo',
			'Seite bearbeiten',
			'Seite bearbeiten',
			'manage_options',
			'srk-seo-edit',
			[ $this, 'render_edit' ]
		);
	}

	/* ── Overview Page ─────────────────────────────────────────────── */

	public function render_overview(): void {
		$pages = get_pages( [ 'sort_column' => 'menu_order,post_title' ] );
		$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
		?>
		<div class="wrap">
			<h1>SRK SEO – Übersicht</h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>SEO-Einstellungen gespeichert.</p></div>
			<?php endif; ?>
			<table class="widefat fixed striped" style="max-width:1100px;margin-top:16px;">
				<thead>
					<tr>
						<th style="width:18%;">Seite</th>
						<th style="width:28%;">SEO-Titel</th>
						<th style="width:36%;">Meta-Beschreibung</th>
						<th style="width:10%;">Robots</th>
						<th style="width:8%;">Aktion</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pages as $p ) :
						$title  = get_post_meta( $p->ID, self::META_TITLE, true );
						$desc   = get_post_meta( $p->ID, self::META_DESC, true );
						$robots = get_post_meta( $p->ID, self::META_ROBOTS, true );
						$edit   = admin_url( 'admin.php?page=srk-seo-edit&post_id=' . $p->ID );
					?>
						<tr>
							<td><strong><?php echo esc_html( $p->post_title ); ?></strong></td>
							<td><?php echo $title ? esc_html( $title ) : '<span style="color:#999;">—</span>'; ?></td>
							<td><?php echo $desc ? esc_html( wp_trim_words( $desc, 15 ) ) : '<span style="color:#999;">—</span>'; ?></td>
							<td>
								<?php if ( $robots === 'noindex' ) : ?>
									<span style="color:#d63638;">noindex</span>
								<?php else : ?>
									<span style="color:#00a32a;">index</span>
								<?php endif; ?>
							</td>
							<td><a href="<?php echo esc_url( $edit ); ?>" class="button button-small">Bearbeiten</a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ── Edit Page ─────────────────────────────────────────────────── */

	public function render_edit(): void {
		$post_id = (int) ( $_GET['post_id'] ?? 0 );
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post || $post->post_type !== 'page' ) {
			echo '<div class="wrap"><h1>Seite nicht gefunden</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=srk-seo' ) ) . '">Zurück zur Übersicht</a></p></div>';
			return;
		}

		$title     = get_post_meta( $post_id, self::META_TITLE, true );
		$desc      = get_post_meta( $post_id, self::META_DESC, true );
		$canonical = get_post_meta( $post_id, self::META_CANONICAL, true );
		$robots    = get_post_meta( $post_id, self::META_ROBOTS, true );
		$keyword   = get_post_meta( $post_id, self::META_KEYWORD, true );
		$og_title  = get_post_meta( $post_id, self::META_OG_TITLE, true );
		$og_desc   = get_post_meta( $post_id, self::META_OG_DESC, true );
		$og_image  = get_post_meta( $post_id, self::META_OG_IMAGE, true );
		$saved     = isset( $_GET['saved'] ) && $_GET['saved'] === '1';

		$title_len = mb_strlen( $title );
		$desc_len  = mb_strlen( $desc );
		?>
		<div class="wrap">
			<h1>SEO: <?php echo esc_html( $post->post_title ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=srk-seo' ) ); ?>">&larr; Zurück zur Übersicht</a></p>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>SEO-Einstellungen gespeichert.</p></div>
			<?php endif; ?>
			<form method="post" action="" style="max-width:750px;">
				<?php wp_nonce_field( 'srk_seo_save', 'srk_seo_nonce' ); ?>
				<input type="hidden" name="srk_seo_post_id" value="<?php echo $post_id; ?>" />

				<!-- Google Preview -->
				<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin:16px 0 24px;">
					<p style="margin:0 0 4px;font-size:11px;color:#999;">Google-Vorschau</p>
					<p style="margin:0;font-size:18px;color:#1a0dab;line-height:1.3;" id="srk-preview-title"><?php echo esc_html( $title ?: $post->post_title ); ?></p>
					<p style="margin:4px 0 0;font-size:13px;color:#545454;line-height:1.4;" id="srk-preview-desc"><?php echo esc_html( $desc ?: '—' ); ?></p>
				</div>

				<!-- Search appearance -->
				<h2>Suchergebnisse</h2>
				<table class="form-table">
					<tr>
						<th><label for="srk-title">SEO-Titel</label></th>
						<td>
							<input type="text" id="srk-title" name="srk_seo_fields[title]" value="<?php echo esc_attr( $title ); ?>" class="large-text" placeholder="<?php echo esc_attr( $post->post_title ); ?>" />
							<p class="description">
								<span id="srk-title-count"><?php echo $title_len; ?></span> / 60 Zeichen
								<?php if ( $title_len > 60 ) : ?><span style="color:#d63638;"> — zu lang</span><?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="srk-desc">Meta-Beschreibung</label></th>
						<td>
							<textarea id="srk-desc" name="srk_seo_fields[description]" rows="3" class="large-text" placeholder="Beschreibung für Suchmaschinen"><?php echo esc_textarea( $desc ); ?></textarea>
							<p class="description">
								<span id="srk-desc-count"><?php echo $desc_len; ?></span> / 160 Zeichen
								<?php if ( $desc_len > 160 ) : ?><span style="color:#d63638;"> — zu lang</span><?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><label for="srk-keyword">Focus Keyword</label></th>
						<td>
							<input type="text" id="srk-keyword" name="srk_seo_fields[focus_keyword]" value="<?php echo esc_attr( $keyword ); ?>" class="regular-text" placeholder="z.B. Webdesign Odenwald" />
							<p class="description">Hauptsuchbegriff für diese Seite (intern, wird nicht ausgegeben).</p>
						</td>
					</tr>
				</table>

				<!-- Technical -->
				<h2>Technisch</h2>
				<table class="form-table">
					<tr>
						<th><label for="srk-canonical">Canonical URL</label></th>
						<td>
							<input type="url" id="srk-canonical" name="srk_seo_fields[canonical]" value="<?php echo esc_attr( $canonical ); ?>" class="large-text" placeholder="<?php echo esc_url( get_permalink( $post_id ) ); ?>" />
							<p class="description">Nur setzen, wenn diese Seite unter einer anderen URL erreichbar sein soll.</p>
						</td>
					</tr>
					<tr>
						<th>Robots</th>
						<td>
							<label>
								<input type="checkbox" name="srk_seo_fields[robots]" value="noindex" <?php checked( $robots, 'noindex' ); ?> />
								Diese Seite nicht indexieren (noindex)
							</label>
							<p class="description">Suchmaschinen werden diese Seite nicht in den Ergebnissen anzeigen.</p>
						</td>
					</tr>
				</table>

				<!-- Social / Open Graph -->
				<h2>Social Media (Open Graph)</h2>
				<table class="form-table">
					<tr>
						<th><label for="srk-og-title">OG-Titel</label></th>
						<td>
							<input type="text" id="srk-og-title" name="srk_seo_fields[og_title]" value="<?php echo esc_attr( $og_title ); ?>" class="large-text" placeholder="Standard: SEO-Titel" />
						</td>
					</tr>
					<tr>
						<th><label for="srk-og-desc">OG-Beschreibung</label></th>
						<td>
							<textarea id="srk-og-desc" name="srk_seo_fields[og_description]" rows="2" class="large-text" placeholder="Standard: Meta-Beschreibung"><?php echo esc_textarea( $og_desc ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th><label for="srk-og-image">OG-Bild URL</label></th>
						<td>
							<input type="url" id="srk-og-image" name="srk_seo_fields[og_image]" value="<?php echo esc_attr( $og_image ); ?>" class="large-text" placeholder="https://beispiel.de/bild.jpg" />
							<p class="description">Empfohlen: 1200×630 px. Wird beim Teilen auf Social Media angezeigt.</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<?php submit_button( 'Speichern', 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<script>
		(function() {
			var title = document.getElementById('srk-title');
			var desc  = document.getElementById('srk-desc');
			var pt    = document.getElementById('srk-preview-title');
			var pd    = document.getElementById('srk-preview-desc');
			var tc    = document.getElementById('srk-title-count');
			var dc    = document.getElementById('srk-desc-count');
			var ph    = title.placeholder;
			title.addEventListener('input', function() {
				pt.textContent = this.value || ph;
				tc.textContent = this.value.length;
			});
			desc.addEventListener('input', function() {
				pd.textContent = this.value || '—';
				dc.textContent = this.value.length;
			});
		})();
		</script>
		<?php
	}

	/* ── Save Handler ──────────────────────────────────────────────── */

	public function handle_save(): void {
		if ( ! isset( $_POST['srk_seo_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( $_POST['srk_seo_nonce'], 'srk_seo_save' ) ) {
			wp_die( 'Ungültiger Sicherheitstoken.' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$post_id = (int) ( $_POST['srk_seo_post_id'] ?? 0 );
		if ( ! $post_id ) {
			return;
		}

		$fields = $_POST['srk_seo_fields'] ?? [];

		$meta_map = [
			'title'          => self::META_TITLE,
			'description'    => self::META_DESC,
			'canonical'      => self::META_CANONICAL,
			'focus_keyword'  => self::META_KEYWORD,
			'og_title'       => self::META_OG_TITLE,
			'og_description' => self::META_OG_DESC,
			'og_image'       => self::META_OG_IMAGE,
		];

		foreach ( $meta_map as $field => $meta_key ) {
			$value = sanitize_text_field( $fields[ $field ] ?? '' );
			if ( $value ) {
				update_post_meta( $post_id, $meta_key, $value );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		}

		// Robots checkbox.
		$robots = isset( $fields['robots'] ) && $fields['robots'] === 'noindex' ? 'noindex' : '';
		if ( $robots ) {
			update_post_meta( $post_id, self::META_ROBOTS, $robots );
		} else {
			delete_post_meta( $post_id, self::META_ROBOTS );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=srk-seo-edit&post_id=' . $post_id . '&saved=1' ) );
		exit;
	}

	/* ── Frontend Output ───────────────────────────────────────────── */

	public function filter_title( array $title ): array {
		$seo_title = $this->get_current_meta( self::META_TITLE );
		if ( $seo_title ) {
			$title['title'] = $seo_title;
			unset( $title['tagline'] );
		}
		return $title;
	}

	public function output_head(): void {
		$id = $this->get_current_page_id();
		if ( ! $id ) {
			return;
		}

		// Meta description.
		$desc = (string) get_post_meta( $id, self::META_DESC, true );
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}

		// Canonical.
		$canonical = (string) get_post_meta( $id, self::META_CANONICAL, true );
		if ( $canonical ) {
			echo '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}

		// Robots.
		$robots = (string) get_post_meta( $id, self::META_ROBOTS, true );
		if ( $robots === 'noindex' ) {
			echo '<meta name="robots" content="noindex,follow">' . "\n";
		}

		// Open Graph.
		$og_title = (string) get_post_meta( $id, self::META_OG_TITLE, true );
		$og_desc  = (string) get_post_meta( $id, self::META_OG_DESC, true );
		$og_image = (string) get_post_meta( $id, self::META_OG_IMAGE, true );

		$og_title = $og_title ?: (string) get_post_meta( $id, self::META_TITLE, true );
		$og_desc  = $og_desc ?: $desc;

		if ( $og_title ) {
			echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
		}
		if ( $og_desc ) {
			echo '<meta property="og:description" content="' . esc_attr( $og_desc ) . '">' . "\n";
		}
		if ( $og_image ) {
			echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
		}
		echo '<meta property="og:type" content="website">' . "\n";
		$url = $canonical ?: get_permalink( $id );
		if ( $url ) {
			echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		}
	}

	/* ── Helpers ───────────────────────────────────────────────────── */

	private function get_current_meta( string $key ): string {
		$id = $this->get_current_page_id();
		if ( ! $id ) {
			return '';
		}
		return (string) get_post_meta( $id, $key, true );
	}

	private function get_current_page_id(): int {
		if ( is_front_page() && get_option( 'page_on_front' ) ) {
			return (int) get_option( 'page_on_front' );
		}
		return (int) get_queried_object_id();
	}
}

new SRK_SEO();
