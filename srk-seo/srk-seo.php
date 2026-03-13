<?php
/**
 * Plugin Name: SRK SEO
 * Description: SEO-Titel und Meta-Beschreibungen pro Seite verwalten.
 * Version: 1.0.0
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

	const VERSION  = '1.0.0';
	const META_TITLE = '_srk_seo_title';
	const META_DESC  = '_srk_seo_description';

	public function __construct() {
		// Admin page.
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_save' ] );

		// Frontend output.
		add_filter( 'document_title_parts', [ $this, 'filter_title' ] );
		add_action( 'wp_head', [ $this, 'output_meta_description' ], 1 );
	}

	/* ── Admin ─────────────────────────────────────────────────────── */

	public function add_menu(): void {
		add_menu_page(
			'SRK SEO',
			'SRK SEO',
			'manage_options',
			'srk-seo',
			[ $this, 'render_page' ],
			'dashicons-search',
			90
		);
	}

	public function render_page(): void {
		$pages = get_pages( [ 'sort_column' => 'menu_order,post_title' ] );
		$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
		?>
		<div class="wrap">
			<h1>SRK SEO</h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>SEO-Einstellungen gespeichert.</p></div>
			<?php endif; ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'srk_seo_save', 'srk_seo_nonce' ); ?>
				<table class="widefat fixed striped" style="max-width:900px;">
					<thead>
						<tr>
							<th style="width:20%;">Seite</th>
							<th style="width:35%;">SEO-Titel</th>
							<th style="width:45%;">Meta-Beschreibung</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pages as $p ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $p->post_title ); ?></strong></td>
								<td>
									<input
										type="text"
										name="srk_seo[<?php echo $p->ID; ?>][title]"
										value="<?php echo esc_attr( get_post_meta( $p->ID, self::META_TITLE, true ) ); ?>"
										class="large-text"
										placeholder="SEO-Titel"
									/>
								</td>
								<td>
									<textarea
										name="srk_seo[<?php echo $p->ID; ?>][description]"
										rows="2"
										class="large-text"
										placeholder="Meta-Beschreibung"
									><?php echo esc_textarea( get_post_meta( $p->ID, self::META_DESC, true ) ); ?></textarea>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="submit">
					<?php submit_button( 'Speichern', 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

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

		$data = $_POST['srk_seo'] ?? [];

		foreach ( $data as $post_id => $fields ) {
			$post_id = (int) $post_id;
			if ( ! $post_id ) {
				continue;
			}

			$title = sanitize_text_field( $fields['title'] ?? '' );
			$desc  = sanitize_textarea_field( $fields['description'] ?? '' );

			if ( $title ) {
				update_post_meta( $post_id, self::META_TITLE, $title );
			} else {
				delete_post_meta( $post_id, self::META_TITLE );
			}

			if ( $desc ) {
				update_post_meta( $post_id, self::META_DESC, $desc );
			} else {
				delete_post_meta( $post_id, self::META_DESC );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=srk-seo&saved=1' ) );
		exit;
	}

	/* ── Frontend ──────────────────────────────────────────────────── */

	public function filter_title( array $title ): array {
		$seo_title = $this->get_current_meta( self::META_TITLE );
		if ( $seo_title ) {
			$title['title'] = $seo_title;
			unset( $title['tagline'] );
		}
		return $title;
	}

	public function output_meta_description(): void {
		$desc = $this->get_current_meta( self::META_DESC );
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
	}

	private function get_current_meta( string $key ): string {
		if ( is_front_page() && get_option( 'page_on_front' ) ) {
			return (string) get_post_meta( (int) get_option( 'page_on_front' ), $key, true );
		}
		$id = get_queried_object_id();
		if ( ! $id ) {
			return '';
		}
		return (string) get_post_meta( $id, $key, true );
	}

	/* ── Activation: seed defaults ─────────────────────────────────── */

	public static function activate(): void {
		$defaults = [
			'startseite' => [
				'title' => 'Modernes Webdesign, Hosting & Entwicklung – SRK Hosting',
				'description' => 'Professionelles Webdesign, zuverlässiges Webhosting und individuelle Webentwicklung aus einer Hand. Modernes Design und persönlicher Service.',
			],
			'webdesign' => [
				'title' => 'Webdesign – Moderne Webseiten für Unternehmen | SRK Hosting',
				'description' => 'Individuelle Webseiten für kleine und mittelständische Unternehmen. Responsive Design, schnelle Ladezeiten und suchmaschinenoptimiert.',
			],
			'angebot' => [
				'title' => 'Hosting-Pakete & Preise – Webhosting ab 9 €/Monat | SRK Hosting',
				'description' => 'Webhosting-Pakete mit SSL, E-Mail und persönlichem Support. Basic, Extended oder Custom – das passende Paket für Ihr Projekt.',
			],
			'email-transferservice' => [
				'title' => 'E-Mail Transferservice – Postfächer sicher umziehen | SRK Hosting',
				'description' => 'Stressfreier E-Mail-Umzug zu Ihrem neuen Hosting. Alle Postfächer, Ordner und Nachrichten werden sicher übertragen.',
			],
			'spamexperts-virenschutz' => [
				'title' => 'SpamExperts Virenschutz – E-Mail-Sicherheit | SRK Hosting',
				'description' => 'Professioneller Spam- und Virenschutz für Ihre E-Mails. SpamExperts filtert Bedrohungen bevor sie Ihr Postfach erreichen.',
			],
			'webseiten-migration' => [
				'title' => 'Webseiten-Migration – Website sicher umziehen | SRK Hosting',
				'description' => 'Professioneller Umzug Ihrer Website zu neuem Hosting. Ohne Ausfallzeit, inklusive DNS-Umstellung und Funktionsprüfung.',
			],
		];

		foreach ( $defaults as $slug => $meta ) {
			$page = get_page_by_path( $slug );
			if ( ! $page ) {
				continue;
			}
			// Only seed if no value exists yet.
			if ( ! get_post_meta( $page->ID, self::META_TITLE, true ) ) {
				update_post_meta( $page->ID, self::META_TITLE, $meta['title'] );
			}
			if ( ! get_post_meta( $page->ID, self::META_DESC, true ) ) {
				update_post_meta( $page->ID, self::META_DESC, $meta['description'] );
			}
		}
	}
}

register_activation_hook( __FILE__, [ 'SRK_SEO', 'activate' ] );
new SRK_SEO();
