<?php
// Optimisation « page 2 → page 1 » (hub, cron hebdomadaire) : repère dans Search Console les articles classés
// entre la 5e et la 20e position, puis les enrichit (nouvelles sections + FAQ) sur les requêtes exactes des internautes.
// Usage : php gsc_boost.php [--max=12] [--per-site=2] [--dry]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

define('MCP_LIB_MODE', true);
require '/home3/guenver/mcp.guenver.com/mcp.php';
$CFG = require '/home3/guenver/mcp.guenver.com/config.php';
define('GOOGLE_TOOLS_LIB', true);
require __DIR__ . '/google_tools.php';
$opt = getopt('', ['max::', 'per-site::', 'dry']);
$max = (int)($opt['max'] ?? 12);
$perSite = (int)($opt['per-site'] ?? 2);
const GB_STATE = __DIR__ . '/boost_state.json';
const GB_COOLDOWN = 45 * 86400; // un article n'est pas réenrichi avant 45 jours
@proc_nice(10);
$lock = fopen(__DIR__ . '/gsc_boost.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");

function gb_say(string $m): void { echo date('c') . ' ' . $m . "\n"; }

function gb_fab(array $G, string $route, array $body): array
{
    $ch = curl_init(rtrim($G['api_base'], '/') . '/_api/' . $route);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['X-Fabrique-Token: ' . $G['api_token'], 'Content-Type: application/json']]);
    $j = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (!is_array($j)) throw new RuntimeException("fabrique $route : réponse invalide");
    if (isset($j['error'])) throw new RuntimeException("fabrique $route : " . $j['error']);
    return $j;
}

function gb_llm(string $prompt): string
{
    global $CFG;
    foreach ([['gemini', 'gemini-2.5-flash'], ['groq', null], [null, null]] as [$p, $m]) {
        try {
            $o = ['max_tokens' => 6000];
            if ($p) $o['provider'] = $p;
            if ($m) $o['model'] = $m;
            $t = (string)ai_llm_request($prompt, 'Tu es un rédacteur web SEO francophone expert. Tu écris en français impeccable, concret et utile.', $o, $CFG)['text'];
            if (trim($t) !== '') return $t;
        } catch (Throwable $e) { gb_say('  IA ' . ($p ?: 'chaîne') . ' : ' . substr($e->getMessage(), 0, 120)); }
    }
    throw new RuntimeException('IA indisponible');
}

// 1. Sites de la flotte → fabrique qui les héberge
$fleet = [];
foreach (glob(__DIR__ . '/gen-config*.php') as $f) {
    if (str_contains($f, 'sample')) continue;
    $G = include $f;
    $ch = curl_init(rtrim($G['api_base'], '/') . '/_api/sites');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['X-Fabrique-Token: ' . $G['api_token']]]);
    foreach ((array)json_decode((string)curl_exec($ch), true) as $s) if (is_array($s) && ($s['status'] ?? '') === 'active') $fleet[$s['host']] = ['G' => $G, 'niche' => $s['niche']];
    curl_close($ch);
}
gb_say(count($fleet) . ' sites actifs');

// 2. Requêtes Search Console des 28 derniers jours, par page
$state = json_decode((string)@file_get_contents(GB_STATE), true) ?: [];
$pages = [];
foreach (gt_req('GET', 'https://www.googleapis.com/webmasters/v3/sites')['siteEntry'] ?? [] as $e) {
    if ($e['permissionLevel'] === 'siteUnverifiedUser') continue;
    $r = gt_req('POST', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($e['siteUrl']) . '/searchAnalytics/query',
        ['startDate' => gmdate('Y-m-d', time() - 30 * 86400), 'endDate' => gmdate('Y-m-d', time() - 2 * 86400), 'dimensions' => ['page', 'query'], 'rowLimit' => 5000]);
    foreach ($r['rows'] ?? [] as $row) {
        [$url, $q] = $row['keys'];
        $host = (string)parse_url($url, PHP_URL_HOST);
        $path = (string)parse_url($url, PHP_URL_PATH);
        if (!isset($fleet[$host]) || $path === '/' || preg_match('#^/(category|page|prix-carburant|station|bornes-recharge|borne|offres-emploi|offre)/#', $path)) continue;
        if ($row['position'] < 4.5 || $row['position'] > 20) continue;
        $k = $host . $path;
        $pages[$k] ??= ['host' => $host, 'path' => $path, 'impr' => 0, 'queries' => []];
        $pages[$k]['impr'] += (int)$row['impressions'];
        $pages[$k]['queries'][] = ['q' => $q, 'impr' => (int)$row['impressions'], 'pos' => round($row['position'], 1)];
    }
}
$pages = array_filter($pages, fn($p) => $p['impr'] >= 5 && (time() - (int)($state[$p['host'] . $p['path']] ?? 0)) > GB_COOLDOWN);
usort($pages, fn($a, $b) => $b['impr'] <=> $a['impr']);
gb_say(count($pages) . ' articles entre la 5e et la 20e place');

// 3. Enrichissement
$done = 0; $bySite = [];
foreach ($pages as $pg) {
    if ($done >= $max) break;
    if (($bySite[$pg['host']] ?? 0) >= $perSite) continue;
    $G = $fleet[$pg['host']]['G'];
    usort($pg['queries'], fn($a, $b) => $b['impr'] <=> $a['impr']);
    $queries = array_slice(array_column($pg['queries'], 'q'), 0, 12);
    try {
        $post = gb_fab($G, 'post', ['host' => $pg['host'], 'path' => $pg['path']]);
        if (isset($opt['dry'])) { gb_say("[dry] {$pg['host']}{$pg['path']} ({$pg['impr']} impr.) : " . implode(' | ', $queries)); $done++; continue; }
        $prompt = "Article existant : « {$post['title']} » (site : {$post['niche']}).\n"
            . "Plan actuel (H2) :\n- " . implode("\n- ", $post['h2']) . "\n\n"
            . "Questions déjà en FAQ :\n- " . implode("\n- ", array_column($post['faq'], 'q')) . "\n\n"
            . "Extrait du texte :\n" . mb_substr($post['text'], 0, 3500) . "\n\n"
            . "Dans Google, cet article apparaît entre la 5e et la 20e position sur ces recherches réelles :\n- " . implode("\n- ", $queries) . "\n\n"
            . "Écris un COMPLÉMENT qui répond précisément à celles de ces recherches que l'article ne traite pas encore bien : 1 ou 2 nouvelles sections <h2> (avec <h3> si utile), "
            . "450 à 700 mots au total, concrètes (étapes, conseils, erreurs à éviter, tableau <table> si pertinent). Reprends naturellement les formulations des recherches dans les titres. "
            . "Ne répète pas ce qui est déjà dit. N'invente ni chiffres précis, ni dates, ni prix, ni études, ni témoignages. Pas de conclusion, pas d'introduction générale.\n"
            . "Balises autorisées : h2, h3, p, ul, ol, li, strong, em, table, thead, tbody, tr, th, td.\n"
            . "Puis 2 à 4 nouvelles questions de FAQ (différentes des existantes) avec réponses de 2 à 4 phrases.\n"
            . "Format EXACT :\n===HTML===\n(le HTML)\n===FAQ===\n[{\"q\":\"…\",\"a\":\"…\"}]";
        $out = gb_llm($prompt);
        if (!preg_match('/===HTML===\s*(.*?)\s*===FAQ===\s*(.*)$/s', $out, $m)) throw new RuntimeException('format IA invalide');
        $html = trim(preg_replace('/^```(html)?|```$/m', '', $m[1]));
        $a = strpos($m[2], '['); $b = strrpos($m[2], ']');
        $faq = ($a !== false && $b > $a) ? (json_decode(substr($m[2], $a, $b - $a + 1), true) ?: []) : [];
        $r = gb_fab($G, 'enrich', ['host' => $pg['host'], 'path' => $pg['path'], 'html' => $html, 'faq' => $faq, 'queries' => $queries]);
        $state[$pg['host'] . $pg['path']] = time();
        file_put_contents(GB_STATE, json_encode($state));
        $done++; $bySite[$pg['host']] = ($bySite[$pg['host']] ?? 0) + 1;
        gb_say("+ {$pg['host']}{$pg['path']} ({$pg['impr']} impr.) → {$r['words']} mots, " . count($faq) . ' FAQ');
        sleep(5);
    } catch (Throwable $e) { gb_say("! {$pg['host']}{$pg['path']} : " . $e->getMessage()); }
}
gb_say("$done article(s) enrichi(s)");
