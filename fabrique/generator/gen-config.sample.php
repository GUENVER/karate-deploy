<?php
return [
    'api_base' => 'https://fabrique.caen.pro',
    'api_token' => '',
    // rotation des IA gratuites du hub (une par article) ; secours = chaîne du hub
    'providers' => ['gemini', 'mistral', 'groq', 'cerebras', 'nvidia', 'cloudflare'],
    'models' => ['gemini' => 'gemini-2.5-flash'],
];
