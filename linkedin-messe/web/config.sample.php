<?php
// Kopie als config.php ablegen (wird NICHT per Deploy überschrieben) und Werte eintragen.
return [
    // Öffentliche Basis-URL der App, ohne Slash am Ende.
    'base_url' => 'https://plaxtika.de/linkedin',

    // Zufälliger String (mind. 32 Zeichen), z. B. `php -r "echo bin2hex(random_bytes(32));"`.
    // Verschlüsselt das gespeicherte LinkedIn-Token und schützt cron.php per URL.
    'app_secret' => 'BITTE-AENDERN',

    // LinkedIn Developer App (developer.linkedin.com → Auth).
    'linkedin_client_id' => '',
    'linkedin_client_secret' => '',
    // Monatsversion der LinkedIn REST API (YYYYMM). Versionen laufen nach ca. 12 Monaten aus.
    'linkedin_version' => '202509',

    // Deine LinkedIn-Mitglieds-ID ("sub"). Leer lassen, einmal einloggen –
    // die App zeigt dir dann die ID an, die du hier einträgst. Nur dieses Konto darf rein.
    'allowed_sub' => '',

    // Anthropic API-Key (console.anthropic.com).
    'anthropic_api_key' => '',
    'claude_model' => 'claude-opus-5-5',
    'claude_effort' => 'medium',

    // Testbetrieb: true = nichts geht wirklich an LinkedIn/Claude, alles wird simuliert.
    'dry_run' => false,

    'timezone' => 'Europe/Berlin',
];
