<?php
// Copier en config.php (hors dépôt). Secrets uniquement ici.
return [
    'admin_pass_hash' => '', // password_hash('...', PASSWORD_DEFAULT)
    'api_token' => '',       // jeton partagé avec le générateur (bin2hex(random_bytes(24)))
    'cpanel_domains' => ['decouverte.org', 'normandie.me', 'recruteur.eu', 'art-martial.org', 'caen.pro'],
    'public_dir' => __DIR__ . '/public',
    'data_dir' => __DIR__ . '/data',
];
