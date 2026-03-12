<?php

defined( 'ABSPATH' ) || exit;

/**
 * Renders a form from a configuration array.
 */
class SRK_Form_Builder {

	private string $form_id;
	private array $config;

	public function __construct( string $form_id, array $config ) {
		$this->form_id = $form_id;
		$this->config  = $config;
	}

	public function render(): string {
		$uid = 'srk-cf-' . esc_attr( $this->form_id );

		ob_start();
		?>
		<div class="srk-cf-wrap" id="<?php echo $uid; ?>">
			<form class="srk-cf-form" data-form-id="<?php echo esc_attr( $this->form_id ); ?>" novalidate>
				<?php wp_nonce_field( 'srk_cf_submit_' . $this->form_id, 'srk_cf_nonce' ); ?>

				<?php $this->render_antispam(); ?>

				<div class="srk-cf-grid">
					<?php foreach ( $this->config['fields'] as $field ) : ?>
						<?php $this->render_field( $field ); ?>
					<?php endforeach; ?>

					<?php $this->render_privacy(); ?>
					<?php $this->render_error_box(); ?>
					<?php $this->render_submit(); ?>
				</div>
			</form>

			<div class="srk-cf-success" style="display:none;">
				<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
				<h3><?php echo esc_html( $this->config['title'] ?? 'Gesendet' ); ?></h3>
				<p><?php echo esc_html( $this->config['success_msg'] ?? 'Nachricht gesendet.' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private function render_field( array $field ): void {
		$width_class = 'full' === ( $field['width'] ?? 'full' ) ? 'srk-cf-full' : 'srk-cf-half';
		$required    = ! empty( $field['required'] ) ? 'required' : '';
		$name        = esc_attr( $field['name'] );
		$id          = 'srk_cf_' . $name;
		$placeholder = esc_attr( $field['placeholder'] ?? '' );

		echo '<div class="srk-cf-group ' . esc_attr( $width_class ) . '">';
		echo '<label for="' . $id . '">' . esc_html( $field['label'] );
		if ( $required ) {
			echo ' <span class="srk-cf-required">*</span>';
		}
		echo '</label>';

		switch ( $field['type'] ) {
			case 'textarea':
				echo '<textarea id="' . $id . '" name="' . $name . '" rows="5" placeholder="' . $placeholder . '" ' . $required . '></textarea>';
				break;

			case 'select':
				echo '<select id="' . $id . '" name="' . $name . '" ' . $required . '>';
				foreach ( ( $field['options'] ?? [] ) as $value => $label ) {
					echo '<option value="' . esc_attr( $value ) . '">' . esc_html( $label ) . '</option>';
				}
				echo '</select>';
				break;

			default:
				echo '<input type="' . esc_attr( $field['type'] ) . '" id="' . $id . '" name="' . $name . '" placeholder="' . $placeholder . '" ' . $required . '>';
				break;
		}

		echo '</div>';
	}

	private function render_privacy(): void {
		$privacy_url = esc_url( $this->config['privacy_page'] ?? '/datenschutzerklaerung/' );

		echo '<div class="srk-cf-group srk-cf-full srk-cf-consent">';
		echo '<label class="srk-cf-checkbox-label">';
		echo '<input type="checkbox" name="srk_privacy" value="yes" required>';
		echo '<span>Ich habe die <a href="' . $privacy_url . '" target="_blank">Datenschutzerklärung</a> gelesen und stimme der Verarbeitung meiner Daten zu. <span class="srk-cf-required">*</span></span>';
		echo '</label>';
		echo '</div>';
	}

	private function render_error_box(): void {
		echo '<div class="srk-cf-group srk-cf-full">';
		echo '<div class="srk-cf-error" style="display:none;"></div>';
		echo '</div>';
	}

	private function render_antispam(): void {
		$opts = get_option( 'srk_cf_options', [] );
		if ( isset( $opts['enable_antispam'] ) && ! $opts['enable_antispam'] ) {
			return;
		}

		$ts = time();
		$token = wp_hash( $this->form_id . '|' . $ts );

		// Honeypot: invisible field — bots fill it, humans don't.
		echo '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">';
		echo '<input type="text" name="srk_cf_website" value="" tabindex="-1" autocomplete="off">';
		echo '</div>';

		// Timestamp for minimum time check.
		echo '<input type="hidden" name="srk_cf_ts" value="' . esc_attr( $ts ) . '">';
		echo '<input type="hidden" name="srk_cf_token" value="' . esc_attr( $token ) . '">';
	}

	private function render_submit(): void {
		echo '<div class="srk-cf-group srk-cf-full srk-cf-submit">';
		echo '<button type="submit" class="srk-cf-btn">';
		echo '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> ';
		echo esc_html( $this->config['submit_label'] ?? 'Versenden' );
		echo '</button>';
		echo '</div>';
	}
}
