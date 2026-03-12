<?php
/**
 * Plugin Name: SRK Contact Forms
 * Description: Dynamische Kontaktformulare mit Shortcode-Unterstützung. Nutzt wp_mail (kompatibel mit SRK SMTP Mailer).
 * Version: 1.0.0
 * Author: Robin Schumacher
 * Author URI: https://srk-hosting.de
 * Text Domain: srk-contact-forms
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
 * is not permitted. The name "SRK Contact Forms" and associated branding
 * are trademarks of Robin Schumacher / SRK Hosting and may not be used
 * without written permission.
 */

defined( 'ABSPATH' ) || exit;

define( 'SRK_CF_VERSION', '1.0.0' );
define( 'SRK_CF_PATH', plugin_dir_path( __FILE__ ) );
define( 'SRK_CF_URL', plugin_dir_url( __FILE__ ) );

require_once SRK_CF_PATH . 'includes/class-srk-form-builder.php';
require_once SRK_CF_PATH . 'includes/class-srk-form-handler.php';
require_once SRK_CF_PATH . 'includes/class-srk-form-registry.php';
require_once SRK_CF_PATH . 'includes/class-srk-form-admin.php';

// Seed default forms and options on first activation.
register_activation_hook( __FILE__, function () {
	if ( false === get_option( 'srk_cf_forms' ) ) {
		update_option( 'srk_cf_forms', SRK_Form_Registry::default_forms() );
	}
	if ( false === get_option( 'srk_cf_options' ) ) {
		update_option( 'srk_cf_options', [ 'enable_antispam' => true ] );
	}
} );

add_action( 'plugins_loaded', function () {
	SRK_Form_Handler::init();

	if ( is_admin() ) {
		new SRK_Form_Admin();
	}
} );

// Enqueue frontend assets only when shortcode is used.
add_action( 'wp_enqueue_scripts', function () {
	wp_register_style( 'srk-contact-forms', SRK_CF_URL . 'assets/css/srk-forms.css', [], SRK_CF_VERSION );
	wp_register_script( 'srk-contact-forms', SRK_CF_URL . 'assets/js/srk-forms.js', [], SRK_CF_VERSION, true );
	wp_localize_script( 'srk-contact-forms', 'srkFormsConfig', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
	] );
} );

// Shortcode: [srk_contact_form id="contact"] or [srk_contact_form id="quote"]
add_shortcode( 'srk_contact_form', function ( $atts ) {
	$atts = shortcode_atts( [ 'id' => 'contact' ], $atts );

	wp_enqueue_style( 'srk-contact-forms' );
	wp_enqueue_script( 'srk-contact-forms' );

	$form_config = SRK_Form_Registry::get( $atts['id'] );

	if ( ! $form_config ) {
		return '<!-- SRK Contact Forms: Unknown form "' . esc_html( $atts['id'] ) . '" -->';
	}

	$builder = new SRK_Form_Builder( $atts['id'], $form_config );
	return $builder->render();
} );
