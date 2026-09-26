<?php
// Outils Google de la fabrique (hub) — jeton issu de google_ads_oauth.php.
// Usage CLI :
//   php google_tools.php adsense [jours]                 -> revenus par site (JSON)
//   php google_tools.php gsc-list                        -> propriétés Search Console
//   php google_tools.php verify-token <domaine>          -> jeton TXT à poser dans le DNS (propriété Domaine)
//   php google_tools.php verify <domaine>                -> vérifie (DNS) puis ajoute sc-domain:<domaine>
//   php google_tools.php sitemap <propriété> <url>       -> soumet un sitemap
//   php google_tools.php push                            -> envoie les revenus AdSense aux fabriques (cron quotidien)
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('GOOGLE_TOOLS_LIB')) exit;

function gt_cfg(): array { static $c = null; return $c ??= include '/home3/guenver/mcp.guenver.com/config.php'; }

function gt_token(): string
{
    static $tok = null, $exp = 0;
    if ($tok && time() < $exp - 60) return $tok;
    $t = json_decode((string)file_get_contents('/home3/guenver/_secure_data/google_ads_token.json'), true);
    $g = gt_cfg()['google'];
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 20, CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'], 'refresh_token' => $t['refresh_token'], 'grant_type' => 'refresh_token'])]);
    $d = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($d['access_token'])) throw new RuntimeException('jeton Google : ' . ($d['error_description'] ?? $d['error'] ?? 'échec'));
    $exp = time() + (int)($d['expires_in'] ?? 3000);
    return $tok = $d['access_token'];
}

function gt_req(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    $h = ['Authorization: Bearer ' . gt_token()];
    if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 40]);
    $r = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode($r, true) ?: [];
    if ($code >= 300) throw new RuntimeException("HTTP $code " . ($j['error']['message'] ?? substr($r, 0, 200)));
    return $j;
}

// Revenus AdSense par domaine sur N jours (+ total), en euros.
function adsense_report(int $days = 30): array
{
    $acc = gt_req('GET', 'https://adsense.googleapis.com/v2/accounts')['accounts'][0]['name'] ?? null;
    if (!$acc) throw new RuntimeException('aucun compte AdSense');
    $end = gmdate('Y-m-d'); $start = gmdate('Y-m-d', time() - ($days - 1) * 86400);
    [$sy, $sm, $sd] = explode('-', $start); [$ey, $em, $ed] = explode('-', $end);
    $q = http_build_query(['dateRange' => 'CUSTOM', 'startDate.year' => $sy, 'startDate.month' => (int)$sm, 'startDate.day' => (int)$sd,
        'endDate.year' => $ey, 'endDate.month' => (int)$em, 'endDate.day' => (int)$ed, 'currencyCode' => 'EUR']);
    $q .= '&dimensions=DOMAIN_NAME&metrics=ESTIMATED_EARNINGS&metrics=PAGE_VIEWS&metrics=IMPRESSIONS&metrics=CLICKS&metrics=PAGE_VIEWS_RPM&orderBy=-ESTIMATED_EARNINGS';
    $r = gt_req('GET', 'https://adsense.googleapis.com/v2/' . $acc . '/reports:generate?' . $q);
    $cols = array_map(fn($h) => $h['name'], $r['headers'] ?? []);
    $rows = [];
    foreach ($r['rows'] ?? [] as $row) {
        $v = array_combine($cols, array_map(fn($c) => $c['value'] ?? '', $row['cells']));
        $rows[] = ['domain' => $v['DOMAIN_NAME'], 'earnings' => (float)$v['ESTIMATED_EARNINGS'], 'pageviews' => (int)$v['PAGE_VIEWS'],
            'impressions' => (int)$v['IMPRESSIONS'], 'clicks' => (int)$v['CLICKS'], 'rpm' => (float)$v['PAGE_VIEWS_RPM']];
    }
    return ['account' => $acc, 'from' => $start, 'to' => $end, 'days' => $days, 'total' => array_sum(array_column($rows, 'earnings')), 'sites' => $rows,
        'adsense_sites' => array_map(fn($s) => ['domain' => $s['domain'], 'state' => $s['state']], gt_req('GET', 'https://adsense.googleapis.com/v2/' . $acc . '/sites')['sites'] ?? [])];
}

if (PHP_SAPI === 'cli' && !defined('GOOGLE_TOOLS_LIB')) {
    $cmd = $argv[1] ?? '';
    try {
        $out = match ($cmd) {
            'adsense' => adsense_report((int)($argv[2] ?? 30)),
            'gsc-list' => array_map(fn($e) => $e['siteUrl'] . ' (' . $e['permissionLevel'] . ')', gt_req('GET', 'https://www.googleapis.com/webmasters/v3/sites')['siteEntry'] ?? []),
            'verify-token' => gt_req('POST', 'https://www.googleapis.com/siteVerification/v1/token', ['site' => ['type' => 'INET_DOMAIN', 'identifier' => $argv[2]], 'verificationMethod' => 'DNS_TXT']),
            'verify' => (function () use ($argv) {
                $r = gt_req('POST', 'https://www.googleapis.com/siteVerification/v1/webResource?verificationMethod=DNS_TXT', ['site' => ['type' => 'INET_DOMAIN', 'identifier' => $argv[2]]]);
                gt_req('PUT', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode('sc-domain:' . $argv[2]));
                return ['verified' => $r['site']['identifier'] ?? $argv[2], 'owners' => count($r['owners'] ?? []), 'added' => 'sc-domain:' . $argv[2]];
            })(),
            'sitemap' => (function () use ($argv) {
                gt_req('PUT', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($argv[2]) . '/sitemaps/' . rawurlencode($argv[3]));
                return ['ok' => $argv[3]];
            })(),
            'push' => (function () {
                // envoie les revenus (7 j et 30 j) à chaque fabrique connue du générateur
                $rep = ['d7' => adsense_report(7), 'd30' => adsense_report(30), 'at' => date('Y-m-d H:i')];
                $done = [];
                foreach (glob('/home3/guenver/fabrique-gen/gen-config*.php') as $f) { if (str_contains($f, 'sample')) continue;
                    $G = include $f;
                    $ch = curl_init(rtrim($G['api_base'], '/') . '/_api/adsense');
                    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 30, CURLOPT_POSTFIELDS => json_encode($rep),
                        CURLOPT_HTTPHEADER => ['X-Fabrique-Token: ' . $G['api_token'], 'Content-Type: application/json']]);
                    curl_exec($ch);
                    $done[$G['api_base']] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    curl_close($ch);
                }
                return ['total_30j' => $rep['d30']['total'], 'fabriques' => $done];
            })(),
            default => ['usage' => 'adsense [jours] | gsc-list | verify-token <domaine> | verify <domaine> | sitemap <propriété> <url>'],
        };
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    } catch (Throwable $e) { fwrite(STDERR, 'ERREUR ' . $e->getMessage() . "\n"); exit(1); }
}
