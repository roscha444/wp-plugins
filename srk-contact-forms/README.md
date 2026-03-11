# SRK Contact Forms

WordPress-Plugin für dynamische Kontaktformulare. Formulare werden per Shortcode eingebunden und über eine zentrale Registry konfiguriert.

## Funktionen

- **Shortcode** `[srk_contact_form id="contact"]` oder `[srk_contact_form id="quote"]`
- **Dynamischer Formular-Builder**: Felder (Text, E-Mail, Telefon, Select, Textarea) werden aus einer Konfiguration generiert
- **AJAX-Versand** ohne Seiten-Reload, mit Validierung und Erfolgsmeldung
- **Erweiterbar**: Eigene Formulare über den Filter `srk_contact_forms` registrieren
- **Datenschutz-Checkbox** mit Link zur Datenschutzerklärung

## Implementierung

- `SRK_Form_Registry` verwaltet alle Formulardefinitionen (Felder, Empfänger, Labels)
- `SRK_Form_Builder` rendert ein Formular aus der Konfiguration als HTML
- `SRK_Form_Handler` verarbeitet AJAX-Submissions: Validierung, Sanitization, Mail-Versand via `wp_mail`
- Nutzt `wp_mail` für den Versand — in Kombination mit **SRK SMTP Mailer** wird automatisch über SMTP gesendet
- CSS und JS werden nur geladen, wenn der Shortcode auf der Seite verwendet wird

## Eigene Formulare registrieren

```php
add_filter( 'srk_contact_forms', function ( $forms ) {
    $forms['support'] = [
        'title'        => 'Supportanfrage',
        'recipient'    => 'support@example.com',
        'subject'      => 'Neue Supportanfrage',
        'fields'       => [
            [ 'name' => 'name',    'label' => 'Name',    'type' => 'text',     'required' => true,  'width' => 'half' ],
            [ 'name' => 'email',   'label' => 'E-Mail',  'type' => 'email',    'required' => true,  'width' => 'half' ],
            [ 'name' => 'message', 'label' => 'Problem', 'type' => 'textarea', 'required' => true,  'width' => 'full' ],
        ],
        'submit_label' => 'Anfrage senden',
        'success_msg'  => 'Wir kümmern uns darum!',
    ];
    return $forms;
} );
```

Einbindung: `[srk_contact_form id="support"]`

## Anforderungen

- WordPress 6.3+
- PHP 8.0+
- Optional: SRK SMTP Mailer (für SMTP-Versand)
