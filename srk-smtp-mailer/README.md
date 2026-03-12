# SRK SMTP Mailer

WordPress-Plugin zur SMTP-Konfiguration. Ersetzt den Standard-Mailversand (`wp_mail`) durch authentifizierten SMTP-Versand.

## Funktionen

- **SMTP-Konfiguration** im Admin-Bereich: Host, Port, Verschlüsselung (TLS/SSL), Benutzername, Passwort, Absender
- **Passwort-Verschlüsselung** — AES-256-CBC mit AUTH_KEY, kein Klartext in der Datenbank
- **Self-Signed Zertifikate** — opt-in Unterstützung für selbstsignierte SSL-Zertifikate
- **Connection-Test** — 3-stufig: DNS-Auflösung, Socket-Check, SMTP-Authentifizierung mit Debug-Output
- **Test-E-Mail** — Versand an frei wählbare Empfänger-Adresse direkt aus dem Admin
- **E-Mail-Log** — Typ, Betreff, Status und Fehlermeldung, abschaltbar
- **Rate-Limiting** — global pro Stunde und pro Tag, plus pro IP-Adresse pro Stunde
- **DSGVO-konform** — IP-Adressen werden als HMAC-SHA256-Hash gespeichert, keine Klartexte
- **Reverse-Proxy-Support** — erkennt echte Client-IP hinter Cloudflare/nginx via X-Forwarded-For
- **Statistik-Dashboard** — Gesendet/Fehlgeschlagen der letzten 24 Stunden und 30 Tage mit Tageslimit-Anzeige
- **Unabhängige Architektur** — Rate-Limiting und Statistik arbeiten unabhängig vom Log (eigene Tabelle `wp_srk_smtp_rate`)
- **Header-Injection-Schutz** — Newlines werden aus Subject, To und allen Custom-Headern gestrippt
- **Auto-Tabellenerstellung** — neue Tabellen werden automatisch angelegt, kein manuelles Reaktivieren nötig

## Anforderungen

- WordPress 6.3+
- PHP 8.0+
- OpenSSL-Erweiterung (für Passwort-Verschlüsselung)
