<?php
declare(strict_types=1);
// Autorisation Google pour la fabrique (hub) : AdSense (lecture), vérification de sites, Search Console.
// Usage unique : https://mcp.guenver.com/google_ads_oauth.php?k=<clé de _secure_data/google_ads_setup_key>
$CFG = include __DIR__ . '/config.php';
$sec = __DIR__ . '/../_secure_data';
$keyFile = $sec . '/google_ads_setup_key';
$tokFile = $sec . '/google_ads_token.json';
$g = $CFG['google'] ?? [];
$redirect = 'https://mcp.guenver.com/google_ads_oauth.php';
$scopes = 'https://www.googleapis.com/auth/adsense.readonly https://www.googleapis.com/auth/siteverification https://www.googleapis.com/auth/webmasters';
header('Content-Type: text/html; charset=utf-8');

function out(string $msg): never { echo '<!doctype html><meta name="viewport" content="width=device-width"><body style="font:16px sans-serif;padding:2em">' . $msg . '</body>'; exit; }

$key = is_file($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
if ($key === '') out('Autorisation désactivée (pas de clé).');

if (!isset($_GET['code'])) {
    if (!hash_equals($key, (string)($_GET['k'] ?? ''))) { http_response_code(403); out('Clé invalide.'); }
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $g['client_id'], 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => $scopes,
        'access_type' => 'offline', 'prompt' => 'consent select_account', 'state' => hash('sha256', $key),
    ]));
    exit;
}

if (!hash_equals(hash('sha256', $key), (string)($_GET['state'] ?? ''))) { http_response_code(403); out('State invalide.'); }
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30,
    CURLOPT_POSTFIELDS => http_build_query(['code' => $_GET['code'], 'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'],
        'redirect_uri' => $redirect, 'grant_type' => 'authorization_code'])]);
$d = json_decode((string)curl_exec($ch), true);
curl_close($ch);
if (empty($d['refresh_token'])) out('Échec : ' . htmlspecialchars((string)($d['error_description'] ?? $d['error'] ?? 'réponse inattendue')));

$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($d['access_token']));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$info = json_decode((string)curl_exec($ch), true);
curl_close($ch);

file_put_contents($tokFile, json_encode(['refresh_token' => $d['refresh_token'], 'scope' => $d['scope'] ?? '', 'email' => $info['email'] ?? null, 'created' => date('c')]), LOCK_EX);
chmod($tokFile, 0600);
unlink($keyFile);
out('Google connecté pour la fabrique (AdSense + Search Console). Vous pouvez fermer cette page.');
