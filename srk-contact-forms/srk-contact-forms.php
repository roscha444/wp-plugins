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
 */

defined( 'ABSPATH' ) || exit;

define( 'SRK_CF_VERSION', '1.0.0' );
define( 'SRK_CF_PATH', plugin_dir_path( __FILE__ ) );
define( 'SRK_CF_URL', plugin_dir_url( __FILE__ ) );

require_once SRK_CF_PATH . 'includes/class-srk-form-builder.php';
require_once SRK_CF_PATH . 'includes/class-srk-form-handler.php';
require_once SRK_CF_PATH . 'includes/class-srk-form-registry.php';

add_action( 'plugins_loaded', function () {
	SRK_Form_Handler::init();
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
