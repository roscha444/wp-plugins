# SRK Migration

Export/Import von Themes, Plugins, Seiteninhalten und Einstellungen zwischen WordPress-Instanzen.

## Funktionsweise

Das Plugin muss auf **beiden** WordPress-Instanzen installiert sein (Quelle und Ziel).

### Export (Quelle)

Unter **Werkzeuge → SRK Migration → Export** wählen, was exportiert werden soll:

| Komponente | Beschreibung |
|---|---|
| **Theme** | Aktives Theme inkl. aller Dateien |
| **Plugins** | Aktive Plugins einzeln auswählbar |
| **Seiteninhalte** | Alle veröffentlichten Seiten mit Block-Content, Hierarchie und Templates |
| **Einstellungen** | `srk_*`, `profisan_*` Optionen sowie Blogname, Frontpage, Permalinks |

Der Export erzeugt eine ZIP-Datei zum Download.

### Import (Ziel)

Unter **Werkzeuge → SRK Migration → Import** die ZIP-Datei hochladen:

- Themes und Plugins werden direkt nach `wp-content/` entpackt und aktiviert
- Seiten werden anhand des Slugs abgeglichen (Update oder Neuanlage)
- Eltern-Kind-Beziehungen und Seitentemplates bleiben erhalten
- Einstellungen werden direkt übernommen
- Permalinks werden automatisch aktualisiert

## Sicherheit

- **Passwörter, Secrets und API-Keys werden nie exportiert.** Das Plugin filtert sensible Daten automatisch.
- `srk_smtp_options` (SMTP-Zugangsdaten) wird komplett übersprungen.
- Schlüssel wie `password`, `secret`, `token`, `api_key` werden rekursiv aus allen Option-Werten entfernt.
- SMTP und andere Zugangsdaten müssen auf der Zielinstanz neu konfiguriert werden.

## ZIP-Struktur

```
srk-migration-YYYY-MM-DD-HHmmss.zip
├── manifest.json
├── themes/<theme-name>/...
├── plugins/<plugin-name>/...
└── data/
    ├── pages.json
    └── options.json
```

## Anforderungen

- WordPress 6.3+
- PHP 8.0+ mit ZipArchive-Extension

## Lizenz

GPL-2.0-or-later
