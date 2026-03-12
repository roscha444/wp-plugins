# SRK Security

WordPress-Hardening-Plugin mit strikter Content Security Policy (CSP), Security Headers und Schutz gegen gängige Angriffsvektoren.

## Features

| Maßnahme | Beschreibung |
|---|---|
| Content Security Policy (CSP) | Strict CSP mit per-Request Nonce — kein `unsafe-inline` nötig |
| Domain-Whitelist | Externe Domains über Admin-UI konfigurierbar |
| Security Headers | X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy |
| XML-RPC deaktiviert | Blockiert Brute-Force über `system.multicall` |
| Pingbacks deaktiviert | Verhindert DDoS-Amplification |
| Author-Enumeration blockiert | `?author=N` → HTTP 403 |
| REST API User-Enumeration blockiert | `/wp/v2/users` nur für eingeloggte Benutzer |
| Login-Fehlermeldungen generisch | Verrät nicht, ob ein Benutzername existiert |
| Theme/Plugin-Editor deaktiviert | `DISALLOW_FILE_EDIT` |

## CSP-System (v1.1.0)

Die CSP setzt auf einen per-Request Nonce statt `unsafe-inline`:

- **Script-Nonce**: Automatisch für alle `<script>`-Tags (enqueued, inline, `wp_localize_script`)
- **Style-Nonce**: Automatisch für alle `<style>`-Blöcke und `<link>`-Stylesheets
- **`style-src-attr 'unsafe-inline'`**: Erlaubt inline `style=""`-Attribute (kein XSS-Risiko, aber von WordPress/Plugins überall genutzt)
- **Output Buffering**: Fängt `<script>` und `<style>` Tags in `wp_head`/`wp_footer` ab, die nicht durch WordPress-Filter laufen (z.B. `wp_localize_script`)
- **Admin-Bereich ausgenommen**: CSP greift nur im Frontend

### Nonce-Injection

| Quelle | Mechanismus |
|---|---|
| `wp_enqueue_script()` | `script_loader_tag` Filter |
| `wp_add_inline_script()` | `wp_inline_script_attributes` Filter |
| `wp_localize_script()` | Output Buffering in `wp_footer` |
| `wp_enqueue_style()` | `style_loader_tag` Filter |
| Inline `<style>` Blöcke | Output Buffering in `wp_head`/`wp_footer` |

## Admin-Dashboard

Unter dem Menüpunkt "SRK Security" zeigt eine Übersicht alle aktiven Maßnahmen mit Status-Haken. CSP und Domain-Whitelist sind dort konfigurierbar.

## Anforderungen

- WordPress 6.3+
- PHP 8.0+

## Lizenz

GPL-2.0-or-later
