# SRK SMTP Mailer

WordPress-Plugin zur SMTP-Konfiguration. Ersetzt den Standard-Mailversand (`wp_mail`) durch authentifizierten SMTP-Versand.

## Funktionen

- **SMTP-Konfiguration** im Admin-Bereich (Einstellungen > SRK SMTP): Host, Port, Verschlüsselung (TLS/SSL), Benutzername, Passwort, Absender
- **Connection-Test** per Klick direkt aus den Einstellungen
- **E-Mail-Log** mit Typ (contact, quote, system, general), Betreff und Status — keine personenbezogenen Daten

## Implementierung

- Nutzt den WordPress-Hook `phpmailer_init`, um PHPMailer auf SMTP umzukonfigurieren
- Connection-Test über AJAX mit direktem `smtpConnect()` gegen den konfigurierten Server
- Log-Tabelle (`wp_srk_smtp_log`) wird bei Plugin-Aktivierung angelegt
- Logging über die WordPress-Hooks `wp_mail_succeeded` und `wp_mail_failed`
- Mail-Typ wird automatisch anhand des Betreffs erkannt (Kontaktanfrage, Hosting-Anfrage, System etc.)
- Passwort wird nur überschrieben, wenn ein neues eingegeben wird

## Anforderungen

- WordPress 6.3+
- PHP 8.0+
