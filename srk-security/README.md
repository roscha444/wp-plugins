# SRK Security

WordPress-Hardening-Plugin — aktiviert automatisch alle Schutzmaßnahmen ohne Konfiguration.

## Schutzmaßnahmen

- **WordPress-Version versteckt** — Entfernt Versionsnummer aus HTML-Head und RSS-Feeds
- **XML-RPC deaktiviert** — Blockiert die XML-RPC-Schnittstelle komplett (Brute-Force-Vektor)
- **Pingbacks deaktiviert** — Entfernt X-Pingback-Header (DDoS-Amplification-Vektor)
- **Author-Enumeration blockiert** — `?author=N` wird mit HTTP 403 beantwortet
- **REST API User-Enumeration blockiert** — `/wp-json/wp/v2/users` für nicht eingeloggte Besucher entfernt
- **Login-Fehlermeldungen generisch** — Verrät nicht, ob ein Benutzername existiert
- **Security Headers** — X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy
- **Theme/Plugin-Editor deaktiviert** — DISALLOW_FILE_EDIT verhindert PHP-Bearbeitung im Admin

## Admin-Dashboard

Unter dem Menüpunkt "SRK Security" zeigt eine Übersicht alle aktiven Maßnahmen mit Status-Haken.

## Anforderungen

- WordPress 6.3+
- PHP 8.0+
