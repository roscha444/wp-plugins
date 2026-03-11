<?php

defined( 'ABSPATH' ) || exit;

class SRK_SMTP_Settings {

	private string $option_key = 'srk_smtp_options';

	private const CIPHER = 'aes-256-cbc';

	public static function encrypt_password( string $plain ): string {
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$key = hash( 'sha256', AUTH_KEY, true );
		$iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
		$enc = openssl_encrypt( $plain, self::CIPHER, $key, 0, $iv );
		return 'enc:' . base64_encode( $iv . '::' . $enc );
	}

	public static function decrypt_password( string $stored ): string {
		if ( ! str_starts_with( $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$data = base64_decode( substr( $stored, 4 ) );
		if ( false === $data || ! str_contains( $data, '::' ) ) {
			return $stored;
		}
		[ $iv, $enc ] = explode( '::', $data, 2 );
		$key   = hash( 'sha256', AUTH_KEY, true );
		$plain = openssl_decrypt( $enc, self::CIPHER, $key, 0, $iv );
		return false !== $plain ? $plain : $stored;
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public function add_menu(): void {
		add_options_page(
			'SRK SMTP Mailer',
			'SRK SMTP',
			'manage_options',
			'srk-smtp',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'srk_smtp_group', $this->option_key, [
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	public function sanitize( array $input ): array {
		$old = get_option( $this->option_key, [] );

		return [
			'host'       => sanitize_text_field( $input['host'] ?? '' ),
			'port'       => absint( $input['port'] ?? 587 ),
			'encryption' => in_array( $input['encryption'] ?? '', [ 'tls', 'ssl', '' ], true )
				? $input['encryption'] : 'tls',
			'username'   => sanitize_text_field( $input['username'] ?? '' ),
			'password'   => ! empty( $input['password'] )
				? self::encrypt_password( $input['password'] )
				: ( $old['password'] ?? '' ),
			'from_email' => sanitize_email( $input['from_email'] ?? '' ),
			'from_name'       => sanitize_text_field( $input['from_name'] ?? '' ),
			'allow_self_signed' => ! empty( $input['allow_self_signed'] ),
			'enable_log'        => ! empty( $input['enable_log'] ),
		];
	}

	public function render_page(): void {
		$opts = get_option( $this->option_key, [] );
		$test_nonce = wp_create_nonce( 'srk_smtp_test' );
		?>
		<div class="wrap">
			<h1>SRK SMTP Mailer</h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'srk_smtp_group' ); ?>

				<table class="form-table">
					<tr>
						<th><label for="srk_host">SMTP-Server</label></th>
						<td><input type="text" id="srk_host" name="<?php echo esc_attr( $this->option_key ); ?>[host]" value="<?php echo esc_attr( $opts['host'] ?? '' ); ?>" class="regular-text" placeholder="smtp.example.com"></td>
					</tr>
					<tr>
						<th><label for="srk_port">Port</label></th>
						<td><input type="number" id="srk_port" name="<?php echo esc_attr( $this->option_key ); ?>[port]" value="<?php echo esc_attr( $opts['port'] ?? 587 ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th><label for="srk_encryption">Verschlüsselung</label></th>
						<td>
							<select id="srk_encryption" name="<?php echo esc_attr( $this->option_key ); ?>[encryption]">
								<option value="tls" <?php selected( $opts['encryption'] ?? 'tls', 'tls' ); ?>>TLS</option>
								<option value="ssl" <?php selected( $opts['encryption'] ?? '', 'ssl' ); ?>>SSL</option>
								<option value="" <?php selected( $opts['encryption'] ?? '', '' ); ?>>Keine</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="srk_username">Benutzername</label></th>
						<td><input type="text" id="srk_username" name="<?php echo esc_attr( $this->option_key ); ?>[username]" value="<?php echo esc_attr( $opts['username'] ?? '' ); ?>" class="regular-text" autocomplete="off"></td>
					</tr>
					<tr>
						<th><label for="srk_password">Passwort</label></th>
						<td><input type="password" id="srk_password" name="<?php echo esc_attr( $this->option_key ); ?>[password]" value="" class="regular-text" placeholder="<?php echo ! empty( $opts['password'] ) ? '••••••••' : ''; ?>" autocomplete="new-password">
						<p class="description">Leer lassen, um das gespeicherte Passwort beizubehalten.</p></td>
					</tr>
					<tr>
						<th><label for="srk_from_email">Absender E-Mail</label></th>
						<td><input type="email" id="srk_from_email" name="<?php echo esc_attr( $this->option_key ); ?>[from_email]" value="<?php echo esc_attr( $opts['from_email'] ?? '' ); ?>" class="regular-text" placeholder="noreply@ihre-domain.de"></td>
					</tr>
					<tr>
						<th><label for="srk_from_name">Absender Name</label></th>
						<td><input type="text" id="srk_from_name" name="<?php echo esc_attr( $this->option_key ); ?>[from_name]" value="<?php echo esc_attr( $opts['from_name'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
					</tr>
					<tr>
						<th>Zertifikate</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_key ); ?>[allow_self_signed]" value="1" <?php checked( ! empty( $opts['allow_self_signed'] ) ); ?>>
								Self-signed Zertifikate erlauben
							</label>
							<p class="description">Nur aktivieren, wenn Ihr Mailserver ein selbstsigniertes SSL-Zertifikat verwendet. Deaktiviert die Zertifikatsvalidierung.</p>
						</td>
					</tr>
					<tr>
						<th>E-Mail-Log</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $this->option_key ); ?>[enable_log]" value="1" <?php checked( $opts['enable_log'] ?? true ); ?>>
								E-Mail-Log aktivieren
							</label>
							<p class="description">Protokolliert alle gesendeten und fehlgeschlagenen E-Mails.</p>
						</td>
					</tr>
				</table>

				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>

			<hr>
			<h2>Verbindungstest</h2>
			<p>
				<button type="button" id="srk-smtp-test-btn" class="button button-secondary">Verbindung testen</button>
				<button type="button" id="srk-smtp-send-test-btn" class="button button-secondary" style="margin-left:8px;">Test-E-Mail senden</button>
			</p>
			<div id="srk-smtp-test-email-row" style="display:none;margin-top:8px;">
				<input type="email" id="srk-smtp-test-email" class="regular-text" placeholder="empfaenger@example.com" style="vertical-align:middle;">
				<button type="button" id="srk-smtp-send-confirm-btn" class="button button-primary" style="margin-left:6px;vertical-align:middle;">Senden</button>
				<button type="button" id="srk-smtp-send-cancel-btn" class="button" style="margin-left:4px;vertical-align:middle;">Abbrechen</button>
			</div>
			<pre id="srk-smtp-test-result" style="margin-top:10px;padding:12px 16px;border-radius:6px;font-size:13px;line-height:1.6;display:none;max-width:700px;white-space:pre-wrap;word-break:break-word;"></pre>

			<hr>
			<h2>E-Mail-Log</h2>
			<?php $this->render_log_table(); ?>
			<?php
			$log_nonce = wp_create_nonce( 'srk_smtp_clear_log' );
			?>
			<p style="margin-top:12px;">
				<button type="button" id="srk-smtp-clear-log-btn" class="button button-secondary" style="color:#b32d2e;">Log löschen</button>
			</p>
		</div>

		<script>
		function srkSmtpShowResult(result, text, type) {
			result.style.display = 'block';
			result.textContent = text;
			if (type === 'loading') {
				result.style.background = '#f6f7f7'; result.style.color = '#666'; result.style.border = '1px solid #ddd';
			} else if (type === 'success') {
				result.style.background = '#ecfdf5'; result.style.color = '#166534'; result.style.border = '1px solid #bbf7d0';
			} else {
				result.style.background = '#fef2f2'; result.style.color = '#991b1b'; result.style.border = '1px solid #fecaca';
			}
		}

		document.getElementById('srk-smtp-test-btn').addEventListener('click', function() {
			var btn = this;
			var result = document.getElementById('srk-smtp-test-result');
			btn.disabled = true;
			srkSmtpShowResult(result, 'Teste Verbindung…', 'loading');

			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=srk_smtp_test&nonce=<?php echo esc_js( $test_nonce ); ?>'
			})
			.then(function(r) { return r.json(); })
			.then(function(res) {
				srkSmtpShowResult(result, res.data, res.success ? 'success' : 'error');
				btn.disabled = false;
			})
			.catch(function() {
				srkSmtpShowResult(result, 'Fehler beim Testen.', 'error');
				btn.disabled = false;
			});
		});

		document.getElementById('srk-smtp-send-test-btn').addEventListener('click', function() {
			var row = document.getElementById('srk-smtp-test-email-row');
			var input = document.getElementById('srk-smtp-test-email');
			row.style.display = 'block';
			input.focus();
		});

		document.getElementById('srk-smtp-send-cancel-btn').addEventListener('click', function() {
			document.getElementById('srk-smtp-test-email-row').style.display = 'none';
		});

		document.getElementById('srk-smtp-send-confirm-btn').addEventListener('click', function() {
			var btn = this;
			var input = document.getElementById('srk-smtp-test-email');
			var email = input.value.trim();
			var result = document.getElementById('srk-smtp-test-result');

			if (!email) { input.focus(); return; }

			btn.disabled = true;
			srkSmtpShowResult(result, 'Sende Test-E-Mail an ' + email + '…', 'loading');

			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=srk_smtp_send_test&nonce=<?php echo esc_js( $test_nonce ); ?>&to=' + encodeURIComponent(email)
			})
			.then(function(r) { return r.json(); })
			.then(function(res) {
				srkSmtpShowResult(result, res.data, res.success ? 'success' : 'error');
				btn.disabled = false;
			})
			.catch(function() {
				srkSmtpShowResult(result, 'Fehler beim Senden.', 'error');
				btn.disabled = false;
			});
		});

		document.getElementById('srk-smtp-test-email').addEventListener('keydown', function(e) {
			if (e.key === 'Enter') { e.preventDefault(); document.getElementById('srk-smtp-send-confirm-btn').click(); }
		});

		document.getElementById('srk-smtp-clear-log-btn').addEventListener('click', function() {
			if (!confirm('Gesamtes E-Mail-Log wirklich löschen?')) return;
			var btn = this;
			btn.disabled = true;

			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=srk_smtp_clear_log&nonce=<?php echo esc_js( $log_nonce ); ?>'
			})
			.then(function(r) { return r.json(); })
			.then(function(res) {
				if (res.success) { location.reload(); }
				else { alert(res.data); btn.disabled = false; }
			})
			.catch(function() { btn.disabled = false; });
		});
		</script>
		<?php
	}

	private function render_log_table(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'srk_smtp_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			echo '<p>Log-Tabelle nicht vorhanden. Plugin bitte deaktivieren und erneut aktivieren.</p>';
			return;
		}

		$logs = $wpdb->get_results(
			"SELECT * FROM {$table} ORDER BY sent_at DESC LIMIT 50"
		);

		if ( empty( $logs ) ) {
			echo '<p>Noch keine E-Mails geloggt.</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>Datum</th><th>Typ</th><th>Betreff</th><th>Status</th><th>Fehler</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$status_color = 'sent' === $log->status ? '#16a34a' : '#dc2626';
			$status_label = 'sent' === $log->status ? 'Gesendet' : 'Fehlgeschlagen';
			$error_text   = ! empty( $log->error_msg ) ? $log->error_msg : '–';

			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td style="color:%s;font-weight:600;">%s</td><td style="font-size:12px;max-width:300px;word-break:break-word;">%s</td></tr>',
				esc_html( wp_date( 'd.m.Y H:i', strtotime( $log->sent_at ) ) ),
				esc_html( $log->mail_type ),
				esc_html( $log->subject ),
				esc_attr( $status_color ),
				esc_html( $status_label ),
				esc_html( $error_text )
			);
		}

		echo '</tbody></table>';
	}
}
