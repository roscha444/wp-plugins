<?php
/**
 * Plugin Name: SRK Migration
 * Description: Export/Import von Themes, Plugins, Seiteninhalten und Einstellungen zwischen WordPress-Instanzen.
 * Version: 1.3.0
 * Author: Robin Schumacher
 * Author URI: https://srk-hosting.de
 * Text Domain: srk-migration
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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SRK_Migration {

	const VERSION = '1.3.0';
	const SLUG    = 'srk-migration';

	/** Directories / files to skip when archiving themes and plugins. */
	private array $exclude = [ '.git', '.DS_Store', 'node_modules', '.gitignore', 'deploy' ];

	/**
	 * Derive option prefixes dynamically from the active theme and plugins.
	 * E.g. srk-hosting + srk-security → ['srk_'], profisan-theme → ['profisan_'].
	 */
	private function get_option_prefixes(): array {
		$prefixes = [];

		// From active theme slug.
		$theme_part = explode( '-', get_stylesheet() )[0] ?? '';
		if ( $theme_part ) {
			$prefixes[] = $theme_part . '_';
		}

		// From active plugin directory slugs.
		$active_plugins = get_option( 'active_plugins', [] );
		foreach ( $active_plugins as $plugin_file ) {
			$slug = dirname( $plugin_file );
			if ( '.' === $slug ) {
				continue;
			}
			$part = explode( '-', $slug )[0] ?? '';
			if ( $part ) {
				$prefixes[] = $part . '_';
			}
		}

		return array_unique( $prefixes );
	}

	/** Individual core options to always export. */
	private array $core_options = [
		'blogname',
		'blogdescription',
		'show_on_front',
		'permalink_structure',
	];

	/** Option keys that contain sensitive data and must never be exported. */
	private array $sensitive_options = [
		'srk_smtp_options',
	];

	/** Keys to strip from option values (e.g. password fields inside arrays). */
	private array $sensitive_keys = [ 'password', 'passwd', 'pass', 'secret', 'token', 'api_key' ];

	/** Transient key for import results. */
	private const RESULT_TRANSIENT = 'srk_migration_result';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'admin_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_request' ] );
	}

	/* ──────────────────────────────────────────────
	 *  Admin menu
	 * ────────────────────────────────────────────── */

	public function admin_menu(): void {
		add_menu_page(
			'SRK Migration',
			'SRK Migration',
			'manage_options',
			self::SLUG,
			[ $this, 'render_export_page' ],
			'dashicons-migrate',
			80
		);

		add_submenu_page(
			self::SLUG,
			'Export',
			'Export',
			'manage_options',
			self::SLUG,
			[ $this, 'render_export_page' ]
		);

		add_submenu_page(
			self::SLUG,
			'Import',
			'Import',
			'manage_options',
			self::SLUG . '-import',
			[ $this, 'render_import_page' ]
		);
	}

	/* ──────────────────────────────────────────────
	 *  Request handler (runs on admin_init)
	 * ────────────────────────────────────────────── */

	public function handle_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['srk_migration_export'] ) ) {
			check_admin_referer( 'srk_migration_export' );
			$this->handle_export();
		}

		if ( isset( $_POST['srk_migration_import'] ) ) {
			check_admin_referer( 'srk_migration_import' );
			$this->handle_import();
		}
	}

	/* ══════════════════════════════════════════════
	 *  EXPORT
	 * ══════════════════════════════════════════════ */

	private function handle_export(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( 'ZipArchive PHP-Extension ist nicht verfügbar.' );
		}

		$include_theme   = ! empty( $_POST['export_theme'] );
		$include_plugins = ! empty( $_POST['export_plugins'] );
		$include_pages   = ! empty( $_POST['export_pages'] );
		$include_menus   = ! empty( $_POST['export_menus'] );
		$include_options = ! empty( $_POST['export_options'] );

		$selected_plugins = isset( $_POST['export_plugin_list'] ) && is_array( $_POST['export_plugin_list'] )
			? array_map( 'sanitize_text_field', $_POST['export_plugin_list'] )
			: [];

		$tmp_file = wp_tempnam( 'srk-migration' );
		$zip      = new ZipArchive();

		if ( $zip->open( $tmp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			wp_die( 'ZIP-Datei konnte nicht erstellt werden.' );
		}

		$manifest = [
			'version'  => self::VERSION,
			'created'  => gmdate( 'c' ),
			'site_url' => site_url(),
			'includes' => [],
		];

		// ── Theme ──
		if ( $include_theme ) {
			$theme_slug = get_stylesheet();
			$theme_dir  = get_stylesheet_directory();
			$this->zip_add_directory( $zip, $theme_dir, 'themes/' . $theme_slug );
			$manifest['includes']['theme'] = $theme_slug;
		}

		// ── Plugins ──
		if ( $include_plugins && $selected_plugins ) {
			$manifest['includes']['plugins'] = [];
			foreach ( $selected_plugins as $plugin_file ) {
				$plugin_slug = dirname( $plugin_file );
				if ( $plugin_slug === '.' ) {
					continue; // skip single-file plugins
				}
				$plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
				if ( is_dir( $plugin_dir ) ) {
					$this->zip_add_directory( $zip, $plugin_dir, 'plugins/' . $plugin_slug );
					$manifest['includes']['plugins'][] = $plugin_file;
				}
			}
		}

		// ── Seiteninhalte ──
		if ( $include_pages ) {
			$pages_data = $this->export_pages();
			$zip->addFromString( 'data/pages.json', wp_json_encode( $pages_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			$manifest['includes']['pages'] = count( $pages_data );
		}

		// ── Navigationsmenüs ──
		if ( $include_menus ) {
			$menus_data = $this->export_menus();
			$zip->addFromString( 'data/menus.json', wp_json_encode( $menus_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			$manifest['includes']['menus'] = count( $menus_data['menus'] ?? [] );
		}

		// ── Einstellungen ──
		if ( $include_options ) {
			$options_data = $this->export_options();
			$zip->addFromString( 'data/options.json', wp_json_encode( $options_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
			$manifest['includes']['options'] = count( $options_data );
		}

		$zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
		$zip->close();

		// Stream ZIP directly as download — never stored in a web-accessible location.
		$filename = 'srk-migration-' . gmdate( 'Y-m-d-His' ) . '.zip';
		$size     = filesize( $tmp_file );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . $size );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $tmp_file );
		unlink( $tmp_file );
		exit;
	}

	/**
	 * Recursively add a directory to a ZipArchive, respecting exclusions.
	 */
	private function zip_add_directory( ZipArchive $zip, string $source, string $prefix ): void {
		$source = rtrim( realpath( $source ), '/' );
		if ( ! $source || ! is_dir( $source ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$real_path = $item->getRealPath();
			$relative  = substr( $real_path, strlen( $source ) + 1 );

			// Check exclusions against each path segment.
			$skip = false;
			foreach ( $this->exclude as $exc ) {
				if ( str_starts_with( $relative, $exc . '/' ) || str_starts_with( $relative, $exc ) || str_contains( $relative, '/' . $exc . '/' ) || $relative === $exc ) {
					$skip = true;
					break;
				}
			}
			if ( $skip ) {
				continue;
			}

			$entry = $prefix . '/' . $relative;
			if ( $item->isDir() ) {
				$zip->addEmptyDir( $entry );
			} else {
				// Read content immediately — addFile() defers reads and fails in sandboxed environments.
				$content = file_get_contents( $real_path );
				if ( $content !== false ) {
					$zip->addFromString( $entry, $content );
				}
			}
		}
	}

	/**
	 * Export all published pages with hierarchy info.
	 * Includes front page and blog page slugs for ID-independent import.
	 */
	private function export_pages(): array {
		$pages  = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'menu_order' ] );
		$export = [];

		// Build slug lookup for parent resolution.
		$id_to_slug = [];
		foreach ( $pages as $p ) {
			$id_to_slug[ $p->ID ] = $p->post_name;
		}

		foreach ( $pages as $p ) {
			$entry = [
				'slug'        => $p->post_name,
				'title'       => $p->post_title,
				'content'     => $p->post_content,
				'status'      => $p->post_status,
				'menu_order'  => $p->menu_order,
				'template'    => get_page_template_slug( $p->ID ),
				'parent_slug' => $p->post_parent ? ( $id_to_slug[ $p->post_parent ] ?? '' ) : '',
			];

			// Mark front page and blog page by slug (not by ID).
			if ( (int) get_option( 'page_on_front' ) === $p->ID ) {
				$entry['is_front_page'] = true;
			}
			if ( (int) get_option( 'page_for_posts' ) === $p->ID ) {
				$entry['is_posts_page'] = true;
			}

			$export[] = $entry;
		}

		return $export;
	}

	/**
	 * Export all registered navigation menus with their items and location assignments.
	 */
	private function export_menus(): array {
		$nav_menus  = wp_get_nav_menus();
		$locations  = get_nav_menu_locations();
		$loc_lookup = array_flip( $locations ); // term_id => location_slug

		$menus = [];
		foreach ( $nav_menus as $menu ) {
			$items     = wp_get_nav_menu_items( $menu->term_id, [ 'update_post_term_cache' => false ] );
			$exported  = [];
			$id_to_idx = []; // menu_item_id => index for parent resolution

			if ( $items ) {
				foreach ( $items as $i => $item ) {
					$id_to_idx[ $item->ID ] = $i;

					$entry = [
						'title'      => $item->title,
						'type'       => $item->type,        // 'post_type', 'custom', 'taxonomy'
						'object'     => $item->object,      // 'page', 'post', 'category', 'custom'
						'url'        => $item->url,
						'target'     => $item->target,
						'classes'    => array_filter( $item->classes ),
						'menu_order' => $item->menu_order,
						'parent_idx' => null,
					];

					// For page/post links store the slug for ID-independent import.
					if ( 'post_type' === $item->type && $item->object_id ) {
						$linked = get_post( $item->object_id );
						if ( $linked ) {
							$entry['object_slug'] = $linked->post_name;
						}
					}

					// Resolve parent to index (set in second pass).
					if ( (int) $item->menu_item_parent ) {
						$entry['parent_idx'] = $id_to_idx[ (int) $item->menu_item_parent ] ?? null;
					}

					$exported[] = $entry;
				}
			}

			$menus[] = [
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'locations' => isset( $loc_lookup[ $menu->term_id ] ) ? [ $loc_lookup[ $menu->term_id ] ] : [],
				'items'     => $exported,
			];
		}

		return [ 'menus' => $menus ];
	}

	/**
	 * Export options matching configured prefixes + core options.
	 * Sensitive options (passwords, secrets, tokens) are excluded.
	 * page_on_front and page_for_posts are handled via page slugs, not IDs.
	 */
	private function export_options(): array {
		global $wpdb;
		$options = [];

		// Core options (page_on_front/page_for_posts handled via page export).
		foreach ( $this->core_options as $key ) {
			if ( in_array( $key, $this->sensitive_options, true ) ) {
				continue;
			}
			$options[ $key ] = get_option( $key );
		}

		// Prefixed options (auto-detected from active theme + plugins).
		foreach ( $this->get_option_prefixes() as $prefix ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
					$prefix . '%',
					'\_transient%'
				)
			);
			foreach ( $rows as $row ) {
				if ( in_array( $row->option_name, $this->sensitive_options, true ) ) {
					continue;
				}
				$value = maybe_unserialize( $row->option_value );
				$options[ $row->option_name ] = $this->strip_sensitive_keys( $value );
			}
		}

		return $options;
	}

	/**
	 * Recursively strip sensitive keys from arrays.
	 */
	private function strip_sensitive_keys( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $this->sensitive_keys as $key ) {
			unset( $value[ $key ] );
		}
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = $this->strip_sensitive_keys( $v );
			}
		}
		return $value;
	}

	/* ══════════════════════════════════════════════
	 *  IMPORT
	 * ══════════════════════════════════════════════ */

	private function handle_import(): void {
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->set_result( 'error', 'ZipArchive PHP-Extension ist nicht verfügbar.' );
			return;
		}

		if ( empty( $_FILES['import_file']['tmp_name'] ) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK ) {
			$this->set_result( 'error', 'Keine Datei hochgeladen oder Upload-Fehler.' );
			return;
		}

		$tmp_file = $_FILES['import_file']['tmp_name'];
		$zip      = new ZipArchive();

		if ( $zip->open( $tmp_file ) !== true ) {
			@unlink( $tmp_file );
			$this->set_result( 'error', 'ZIP-Datei konnte nicht geöffnet werden.' );
			return;
		}

		$manifest_json = $zip->getFromName( 'manifest.json' );
		if ( ! $manifest_json ) {
			$zip->close();
			@unlink( $tmp_file );
			$this->set_result( 'error', 'Kein manifest.json in der ZIP-Datei gefunden.' );
			return;
		}

		$manifest = json_decode( $manifest_json, true );
		if ( ! $manifest || ! isset( $manifest['includes'] ) ) {
			$zip->close();
			@unlink( $tmp_file );
			$this->set_result( 'error', 'manifest.json ist ungültig.' );
			return;
		}

		$results  = [];
		$includes = $manifest['includes'];

		// ── Theme importieren ──
		if ( ! empty( $includes['theme'] ) ) {
			$theme_slug = sanitize_file_name( $includes['theme'] );
			$theme_dest = get_theme_root() . '/' . $theme_slug;
			$this->zip_extract_prefix( $zip, 'themes/' . $theme_slug . '/', $theme_dest );
			switch_theme( $theme_slug );
			$results[] = "Theme '{$theme_slug}' importiert und aktiviert.";
		}

		// ── Plugins importieren ──
		if ( ! empty( $includes['plugins'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( $includes['plugins'] as $plugin_file ) {
				$plugin_slug = dirname( $plugin_file );
				$plugin_dest = WP_PLUGIN_DIR . '/' . $plugin_slug;
				$this->zip_extract_prefix( $zip, 'plugins/' . $plugin_slug . '/', $plugin_dest );

				if ( ! is_plugin_active( $plugin_file ) ) {
					$activated = activate_plugin( $plugin_file );
					if ( is_wp_error( $activated ) ) {
						$results[] = "Plugin '{$plugin_slug}': Fehler beim Aktivieren — {$activated->get_error_message()}";
						continue;
					}
				}
				$results[] = "Plugin '{$plugin_slug}' importiert und aktiviert.";
			}
		}

		// ── Seiteninhalte importieren ──
		if ( ! empty( $includes['pages'] ) ) {
			$pages_json = $zip->getFromName( 'data/pages.json' );
			if ( $pages_json ) {
				$pages   = json_decode( $pages_json, true );
				$count   = $this->import_pages( $pages );
				$results[] = "{$count} Seiten importiert/aktualisiert.";
			}
		}

		// ── Navigationsmenüs importieren ──
		if ( ! empty( $includes['menus'] ) ) {
			$menus_json = $zip->getFromName( 'data/menus.json' );
			if ( $menus_json ) {
				$menus_data = json_decode( $menus_json, true );
				$menu_count = $this->import_menus( $menus_data );
				$results[]  = "{$menu_count} Navigationsmenü(s) importiert.";
			}
		}

		// ── Einstellungen importieren ──
		if ( ! empty( $includes['options'] ) ) {
			$options_json = $zip->getFromName( 'data/options.json' );
			if ( $options_json ) {
				$options = json_decode( $options_json, true );
				foreach ( $options as $key => $value ) {
					update_option( $key, $value );
				}
				$results[] = count( $options ) . ' Einstellungen importiert.';
			}
		}

		$zip->close();

		// Delete uploaded ZIP immediately — must never remain on disk.
		@unlink( $tmp_file );

		// Permalinks aktualisieren.
		flush_rewrite_rules();

		$this->set_result( 'success', implode( "\n", $results ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-import' ) );
		exit;
	}

	/**
	 * Extract all files under a given prefix from the ZIP into a destination directory.
	 */
	private function zip_extract_prefix( ZipArchive $zip, string $prefix, string $dest ): void {
		$dest = rtrim( $dest, '/' );

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( ! str_starts_with( $name, $prefix ) ) {
				continue;
			}

			$relative = substr( $name, strlen( $prefix ) );
			if ( $relative === '' ) {
				continue;
			}

			$target = $dest . '/' . $relative;

			// Directory entry.
			if ( str_ends_with( $name, '/' ) ) {
				wp_mkdir_p( $target );
				continue;
			}

			// File entry.
			wp_mkdir_p( dirname( $target ) );
			$content = $zip->getFromIndex( $i );
			if ( $content !== false ) {
				file_put_contents( $target, $content );
			}
		}
	}

	/**
	 * Import pages, handling parent-child relationships, front page and blog page.
	 */
	private function import_pages( array $pages ): int {
		$count      = 0;
		$slug_to_id = [];

		// First pass: create or update all pages.
		foreach ( $pages as $page ) {
			$existing = get_page_by_path( $page['slug'] );
			$args     = [
				'post_title'   => $page['title'],
				'post_content' => $page['content'],
				'post_status'  => $page['status'] ?? 'publish',
				'post_type'    => 'page',
				'menu_order'   => $page['menu_order'] ?? 0,
			];

			if ( ! empty( $page['template'] ) ) {
				$args['page_template'] = $page['template'];
			}

			if ( $existing ) {
				$args['ID'] = $existing->ID;
				wp_update_post( $args );
				$slug_to_id[ $page['slug'] ] = $existing->ID;
			} else {
				$args['post_name'] = $page['slug'];
				$id = wp_insert_post( $args );
				if ( ! is_wp_error( $id ) ) {
					$slug_to_id[ $page['slug'] ] = $id;
				}
			}
			$count++;
		}

		// Second pass: set parent relationships.
		foreach ( $pages as $page ) {
			if ( empty( $page['parent_slug'] ) || ! isset( $slug_to_id[ $page['slug'] ] ) ) {
				continue;
			}
			$parent_id = $slug_to_id[ $page['parent_slug'] ] ?? 0;
			if ( $parent_id ) {
				wp_update_post( [
					'ID'          => $slug_to_id[ $page['slug'] ],
					'post_parent' => $parent_id,
				] );
			}
		}

		// Set front page and blog page from export markers.
		foreach ( $pages as $page ) {
			if ( ! empty( $page['is_front_page'] ) && isset( $slug_to_id[ $page['slug'] ] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $slug_to_id[ $page['slug'] ] );
			}
			if ( ! empty( $page['is_posts_page'] ) && isset( $slug_to_id[ $page['slug'] ] ) ) {
				update_option( 'page_for_posts', $slug_to_id[ $page['slug'] ] );
			}
		}

		return $count;
	}

	/**
	 * Import navigation menus, recreating items and assigning locations.
	 */
	private function import_menus( array $data ): int {
		$menus = $data['menus'] ?? [];
		$count = 0;

		// Build page slug → ID lookup for resolving post_type links.
		$all_pages   = get_pages( [ 'post_status' => 'publish' ] );
		$slug_to_id  = [];
		foreach ( $all_pages as $p ) {
			$slug_to_id[ $p->post_name ] = $p->ID;
		}

		$location_map = [];

		foreach ( $menus as $menu ) {
			// Delete existing menu with same slug to avoid duplicates.
			$existing = wp_get_nav_menu_object( $menu['slug'] );
			if ( $existing ) {
				wp_delete_nav_menu( $existing->term_id );
			}

			$menu_id = wp_create_nav_menu( $menu['name'] );
			if ( is_wp_error( $menu_id ) ) {
				continue;
			}

			// Track item index → new menu_item_id for parent resolution.
			$idx_to_item_id = [];

			foreach ( $menu['items'] as $idx => $item ) {
				$args = [
					'menu-item-title'    => $item['title'],
					'menu-item-position' => $item['menu_order'],
					'menu-item-target'   => $item['target'] ?? '',
					'menu-item-classes'  => implode( ' ', $item['classes'] ?? [] ),
					'menu-item-status'   => 'publish',
				];

				// Resolve parent.
				if ( $item['parent_idx'] !== null && isset( $idx_to_item_id[ $item['parent_idx'] ] ) ) {
					$args['menu-item-parent-id'] = $idx_to_item_id[ $item['parent_idx'] ];
				}

				if ( 'post_type' === $item['type'] && ! empty( $item['object_slug'] ) ) {
					// Link to page/post by slug.
					$object_id = $slug_to_id[ $item['object_slug'] ] ?? 0;
					if ( $object_id ) {
						$args['menu-item-type']      = 'post_type';
						$args['menu-item-object']    = $item['object'];
						$args['menu-item-object-id'] = $object_id;
					} else {
						// Fallback: custom link with original URL.
						$args['menu-item-type'] = 'custom';
						$args['menu-item-url']  = $item['url'];
					}
				} elseif ( 'custom' === $item['type'] ) {
					$args['menu-item-type'] = 'custom';
					$args['menu-item-url']  = $item['url'];
				} else {
					// Taxonomy or other — import as custom link.
					$args['menu-item-type'] = 'custom';
					$args['menu-item-url']  = $item['url'];
				}

				$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
				if ( ! is_wp_error( $item_id ) ) {
					$idx_to_item_id[ $idx ] = $item_id;
				}
			}

			// Map locations.
			foreach ( $menu['locations'] as $location ) {
				$location_map[ $location ] = $menu_id;
			}

			$count++;
		}

		// Assign menu locations.
		if ( $location_map ) {
			$current = get_nav_menu_locations();
			set_theme_mod( 'nav_menu_locations', array_merge( $current, $location_map ) );
		}

		return $count;
	}

	/* ──────────────────────────────────────────────
	 *  Result transient helpers
	 * ────────────────────────────────────────────── */

	private function set_result( string $type, string $message ): void {
		set_transient( self::RESULT_TRANSIENT, [ 'type' => $type, 'message' => $message ], 60 );
	}

	private function get_result(): ?array {
		$result = get_transient( self::RESULT_TRANSIENT );
		if ( $result ) {
			delete_transient( self::RESULT_TRANSIENT );
			return $result;
		}
		return null;
	}

	/* ══════════════════════════════════════════════
	 *  ADMIN PAGES
	 * ══════════════════════════════════════════════ */

	public function render_export_page(): void {
		?>
		<div class="wrap">
			<h1>SRK Migration — Export</h1>
			<p>Erstelle ein Migrations-Paket mit Theme, Plugins, Seiteninhalten und Einstellungen.</p>
			<?php
		$active_theme   = wp_get_theme();
		$active_plugins = get_option( 'active_plugins', [] );
		$all_plugins    = get_plugins();
		?>
		<form method="post">
			<?php wp_nonce_field( 'srk_migration_export' ); ?>

			<table class="form-table">
				<!-- Theme -->
				<tr>
					<th scope="row">Theme</th>
					<td>
						<label>
							<input type="checkbox" name="export_theme" value="1" checked>
							<?php echo esc_html( $active_theme->get( 'Name' ) ); ?>
							<span class="description">(<?php echo esc_html( get_stylesheet() ); ?>)</span>
						</label>
					</td>
				</tr>

				<!-- Plugins -->
				<tr>
					<th scope="row">Plugins</th>
					<td>
						<label style="display: block; margin-bottom: 8px;">
							<input type="checkbox" name="export_plugins" value="1" checked
								   id="srk-toggle-plugins">
							Aktive Plugins einschließen
						</label>
						<fieldset id="srk-plugin-list" style="margin-left: 24px;">
							<?php foreach ( $active_plugins as $plugin_file ) :
								// Skip self.
								if ( str_starts_with( $plugin_file, 'srk-migration/' ) ) {
									continue;
								}
								$name = $all_plugins[ $plugin_file ]['Name'] ?? $plugin_file;
								?>
								<label style="display: block; margin-bottom: 4px;">
									<input type="checkbox" name="export_plugin_list[]"
										   value="<?php echo esc_attr( $plugin_file ); ?>" checked>
									<?php echo esc_html( $name ); ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					</td>
				</tr>

				<!-- Seiteninhalte -->
				<tr>
					<th scope="row">Seiteninhalte</th>
					<td>
						<label style="display: block; margin-bottom: 8px;">
							<input type="checkbox" name="export_pages" value="1" checked>
							Veröffentlichte Seiten exportieren
						</label>
						<?php
						$pages = get_pages( [ 'post_status' => 'publish', 'sort_column' => 'menu_order' ] );
						if ( $pages ) :
						?>
						<fieldset style="margin-left: 24px;">
							<?php foreach ( $pages as $p ) :
								$depth  = 0;
								$parent = $p->post_parent;
								while ( $parent ) {
									$depth++;
									$parent_obj = get_post( $parent );
									$parent     = $parent_obj ? $parent_obj->post_parent : 0;
								}
								$indent = str_repeat( '— ', $depth );
								$flags  = [];
								if ( (int) get_option( 'page_on_front' ) === $p->ID ) {
									$flags[] = 'Startseite';
								}
								if ( (int) get_option( 'page_for_posts' ) === $p->ID ) {
									$flags[] = 'Blogseite';
								}
								$flag_str = $flags ? ' — ' . implode( ', ', $flags ) : '';
							?>
								<label style="display: block; margin-bottom: 2px;">
									<?php echo esc_html( $indent . $p->post_title ); ?>
									<span class="description">(<?php echo esc_html( '/' . $p->post_name . '/' . $flag_str ); ?>)</span>
								</label>
							<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php echo count( $pages ); ?> Seiten (inkl. Startseiten-Zuweisung)</p>
						<?php endif; ?>
					</td>
				</tr>

				<!-- Navigationsmenüs -->
				<tr>
					<th scope="row">Navigationsmenüs</th>
					<td>
						<label>
							<input type="checkbox" name="export_menus" value="1" checked>
							Navigationsmenüs exportieren
						</label>
						<p class="description">
							Alle registrierten Menüs mit Menüpunkten und Zuweisungen zu Positionen.
						</p>
					</td>
				</tr>

				<!-- Einstellungen -->
				<tr>
					<th scope="row">Einstellungen</th>
					<td>
						<label>
							<input type="checkbox" name="export_options" value="1" checked>
							Plugin- und Theme-Einstellungen exportieren
						</label>
						<p class="description">
							Blogname, Frontpage, Permalinks sowie alle Plugin-/Theme-Optionen dieser Installation.
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Export herunterladen', 'primary', 'srk_migration_export' ); ?>
		</form>

		<script>
		document.getElementById('srk-toggle-plugins')?.addEventListener('change', function() {
			document.querySelectorAll('#srk-plugin-list input').forEach(function(cb) {
				cb.checked = this.checked;
				cb.disabled = !this.checked;
			}.bind(this));
		});
		</script>
		</div>
		<?php
	}

	public function render_import_page(): void {
		$result    = $this->get_result();
		$max_upload = size_format( wp_max_upload_size() );
		?>
		<div class="wrap">
		<h1>SRK Migration — Import</h1>
		<p>Lade ein Migrations-Paket hoch, um Theme, Plugins, Seiteninhalte und Einstellungen zu importieren.</p>

		<?php if ( $result ) : ?>
			<div class="notice notice-<?php echo $result['type'] === 'error' ? 'error' : 'success'; ?> is-dismissible">
				<?php foreach ( explode( "\n", $result['message'] ) as $line ) : ?>
					<p><?php echo esc_html( $line ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'srk_migration_import' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">Migrations-Datei</th>
					<td>
						<input type="file" name="import_file" accept=".zip" required>
						<p class="description">
							ZIP-Datei aus dem SRK Migration Export. Max. <?php echo esc_html( $max_upload ); ?>.
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'Importieren', 'primary', 'srk_migration_import' ); ?>
		</form>

		<div class="card" style="max-width: 600px; margin-top: 20px;">
			<h3 style="margin-top: 0;">Hinweise</h3>
			<ul style="list-style: disc; margin-left: 20px;">
				<li>Vorhandene Themes und Plugins werden überschrieben.</li>
				<li>Seiten werden anhand des Slugs abgeglichen (Update oder Neuanlage).</li>
				<li>Startseite und Blogseite werden automatisch zugewiesen.</li>
				<li>Navigationsmenüs werden neu erstellt und Positionen zugewiesen.</li>
				<li>Einstellungen werden direkt übernommen.</li>
				<li>Die hochgeladene ZIP-Datei wird nach dem Import sofort gelöscht.</li>
				<li>Dieses Plugin muss auf beiden Instanzen installiert sein.</li>
			</ul>
		</div>
		</div>
		<?php
	}
}

new SRK_Migration();
