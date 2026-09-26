<?php
// Recherche de niches automatique (hub, cron mensuel).
// Signaux : Google Trends FR, requêtes Search Console de la flotte, jeux data.gouv.fr populaires
// → l'IA propose des niches → chaque niche est notée : demande (Google Suggest), concurrence (Bing, SerpApi si configuré),
// données publiques automatisables (data.gouv), revenu estimé → top 5 envoyé à l'admin de la fabrique (bouton « Créer ce site »).
// Le rapport est aussi envoyé par e-mail (Gmail du hub, repli mail()).
// Usage : php niche_research.php [--candidats=20] [--dry] [--mail-last]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

define('MCP_LIB_MODE', true);
require '/home3/guenver/mcp.guenver.com/mcp.php';
$CFG = require '/home3/guenver/mcp.guenver.com/config.php';
define('GOOGLE_TOOLS_LIB', true);
require __DIR__ . '/google_tools.php';
$G = require __DIR__ . '/gen-config.php';
$opt = getopt('', ['candidats::', 'dry', 'mail-last']);
const NR_MAIL_TO = 'e.guenver@gmail.com';
const NR_ADMIN = 'https://fabrique.caen.pro/_admin/niches';
const NR_LAST = __DIR__ . '/niche_last.json';
$nCand = (int)($opt['candidats'] ?? 20);
@proc_nice(10);

function nr_say(string $m): void { echo date('c') . ' ' . $m . "\n"; }

function nr_get(string $url, array $h = []): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36']);
    $r = (string)curl_exec($ch);
    curl_close($ch);
    return $r;
}

function nr_fab(array $G, string $route, ?array $body = null): array
{
    $ch = curl_init(rtrim($G['api_base'], '/') . '/_api/' . $route);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['X-Fabrique-Token: ' . $G['api_token'], 'Content-Type: application/json']]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
    $j = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return is_array($j) ? $j : [];
}

function nr_mail(array $report, array $CFG): string
{
    $e = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
    $html = '<div style="font-family:Arial,sans-serif;max-width:680px"><h2>Idées de sites de niche — ' . $e($report['at']) . '</h2>'
        . '<p>' . (int)$report['candidats'] . ' niches analysées (tendances Google, actualités, Search Console, données publiques). Les 5 meilleures :</p>';
    foreach ($report['top'] as $i => $c) {
        $m = $c['mesures'] ?? [];
        $html .= '<div style="border:1px solid #ddd;border-radius:10px;padding:12px 16px;margin:10px 0"><h3 style="margin:0">' . ($i + 1) . '. ' . $e($c['name']) . ' — score ' . (int)$c['score'] . '/100</h3>'
            . '<p style="margin:6px 0;color:#555">' . $e(preg_replace('/\..*$/', '', (string)$c['sub'])) . '.decouverte.org · ' . $e($c['tagline'] ?? '') . '</p><p style="margin:6px 0">' . $e($c['pourquoi'] ?? '') . '</p>'
            . '<p style="margin:6px 0;font-size:13px;color:#555">Demande : ' . (int)($m['suggestions'] ?? 0) . ' suggestions Google · concurrence : ' . (int)($m['sites_autorite'] ?? 0) . ' gros sites sur ' . (int)($m['resultats_analyses'] ?? 0) . ' · RPM estimé : ' . $e($m['rpm'] ?? '') . ' €'
            . (!empty($c['donnees']) ? ' · données publiques : ' . $e($c['donnees']) : '') . '</p></div>';
    }
    if (!empty($report['autres'])) $html .= '<p><b>Autres pistes :</b> ' . implode(', ', array_map(fn($c) => $e($c['name']) . ' (' . (int)$c['score'] . ')', $report['autres'])) . '</p>';
    $html .= '<p><a href="' . NR_ADMIN . '" style="background:#0b6e4f;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Créer un de ces sites en un clic</a></p></div>';
    $subject = 'Fabrique : ' . count($report['top']) . ' idées de sites de niche (' . date('m/Y') . ')';
    try {
        if (function_exists('tool_gmail_send')) { $r = tool_gmail_send(['to' => [NR_MAIL_TO], 'subject' => $subject, 'body' => $html, 'html' => true, 'confirm' => true], $CFG); if (!empty($r['sent'])) return 'gmail ok'; }
    } catch (Throwable $ex) { nr_say('gmail : ' . $ex->getMessage()); }
    return mail(NR_MAIL_TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8") ? 'mail() ok' : 'échec envoi';
}

if (isset($opt['mail-last'])) { $r = json_decode((string)@file_get_contents(NR_LAST), true); if (!$r) exit("aucun rapport\n"); nr_say(nr_mail($r, $CFG)); exit; }

// ---------- 1. Signaux ----------
$existing = [];
foreach (glob(__DIR__ . '/gen-config*.php') as $f) {
    if (str_contains($f, 'sample')) continue;
    foreach (nr_fab(include $f, 'sites') as $s) $existing[] = $s['host'] . ' : ' . mb_substr((string)$s['niche'], 0, 120);
}
nr_say(count($existing) . ' sites existants');

preg_match_all('#<title>([^<]+)</title>#', nr_get('https://trends.google.fr/trending/rss?geo=FR'), $m);
$trends = array_slice(array_map('html_entity_decode', $m[1] ?? []), 1, 25);
nr_say(count($trends) . ' tendances Google');
preg_match_all('#<item><title>([^<]+)</title>#', nr_get('https://news.google.com/rss?hl=fr&gl=FR&ceid=FR:fr'), $m);
$news = array_slice(array_map(fn($t) => preg_replace('/ - [^-]+$/', '', html_entity_decode($t)), $m[1] ?? []), 0, 30);
nr_say(count($news) . ' titres d\'actualité');

$gscQueries = [];
try {
    foreach (gt_req('GET', 'https://www.googleapis.com/webmasters/v3/sites')['siteEntry'] ?? [] as $e) {
        if ($e['permissionLevel'] === 'siteUnverifiedUser') continue;
        $r = gt_req('POST', 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($e['siteUrl']) . '/searchAnalytics/query',
            ['startDate' => gmdate('Y-m-d', time() - 90 * 86400), 'endDate' => gmdate('Y-m-d'), 'dimensions' => ['query'], 'rowLimit' => 40]);
        foreach ($r['rows'] ?? [] as $row) if ($row['position'] > 6) $gscQueries[] = $row['keys'][0] . ' (' . (int)$row['impressions'] . ' impr., pos ' . round($row['position']) . ')';
    }
} catch (Throwable $e) { nr_say('GSC : ' . $e->getMessage()); }
$gscQueries = array_slice(array_unique($gscQueries), 0, 60);
nr_say(count($gscQueries) . ' requêtes Search Console');

$dg = json_decode(nr_get('https://www.data.gouv.fr/api/1/datasets/?sort=-reuses&page_size=40'), true);
$datasets = array_map(fn($d) => $d['title'], $dg['data'] ?? []);
nr_say(count($datasets) . ' jeux data.gouv');

// ---------- 2. Candidats IA ----------
$prompt = "Tu es un expert en sites de niche monétisés (AdSense France + affiliation Amazon.fr) et en SEO francophone.\n"
    . "Sites déjà existants (NE PAS reproposer ces thèmes) :\n" . implode("\n", $existing) . "\n\n"
    . "Tendances Google France du moment :\n" . implode(', ', $trends) . "\n\n"
    . "Titres d'actualité du moment (à utiliser pour repérer des besoins DURABLES qui émergent : nouvelle réglementation, nouvelle technologie, nouvelle aide, changement d'usage — pas pour faire un site d'actualité) :\n" . implode("\n", $news) . "\n\n"
    . "Requêtes où nos sites apparaissent déjà dans Google sans être en tête (demande avérée) :\n" . implode("\n", $gscQueries) . "\n\n"
    . "Jeux de données publics français populaires (automatisation possible, comme nos pages prix carburants / offres d'emploi) :\n" . implode(' | ', $datasets) . "\n\n"
    . "Propose $nCand niches de sites DISTINCTES pour la France : sujets durables (pas d'actualité), fort volume de recherches longue traîne, bon revenu publicitaire, "
    . "concurrence abordable, idéalement enrichies par des données publiques mises à jour automatiquement. Évite la santé, la finance personnelle risquée, les jeux d'argent, le contenu adulte.\n"
    . "Réponds UNIQUEMENT en JSON : [{\"sub\":\"sous-domaine court sans accent\",\"name\":\"Nom du site\",\"tagline\":\"accroche\",\"niche\":\"ligne éditoriale détaillée (2-3 phrases)\","
    . "\"seeds\":[\"8 requêtes graines réelles\"],\"categories\":[\"5-6 rubriques\"],\"rpm_estime\":\"revenu estimé en euros pour 1000 pages vues (nombre)\","
    . "\"donnees\":\"jeu de données public exploitable ou vide\",\"pourquoi\":\"1 phrase\"}]";
$raw = '';
foreach ([['gemini', 'gemini-2.5-flash'], [null, null]] as [$p, $mdl]) {
    try { $o = ['max_tokens' => 12000]; if ($p) { $o['provider'] = $p; $o['model'] = $mdl; } $raw = ai_llm_request($prompt, 'Tu réponds en JSON valide uniquement.', $o, $CFG)['text']; break; }
    catch (Throwable $e) { nr_say('IA : ' . $e->getMessage()); }
}
$a = strpos($raw, '['); $b = strrpos($raw, ']');
$cands = ($a !== false && $b > $a) ? (json_decode(substr($raw, $a, $b - $a + 1), true) ?: []) : [];
nr_say(count($cands) . ' candidats');
if (!$cands) exit(1);

// ---------- 3. Notation ----------
const NR_AUTHORITY = ['wikipedia', 'amazon.', 'leboncoin', 'marmiton', 'doctissimo', 'service-public', 'ouest-france', 'lefigaro', 'lemonde', 'quechoisir', '60millions', 'youtube',
    'larousse', 'journaldesfemmes', 'leroymerlin', 'castorama', 'fnac', 'boulanger', 'cdiscount', 'ameli', 'gouv.fr', 'francetvinfo', 'linternaute', '20minutes', 'bfmtv', 'futura-sciences'];
const NR_WEAK = ['forum', 'reddit', 'quora', 'commentcamarche', 'blogspot', 'wordpress.com', 'over-blog', 'pinterest', 'facebook', 'tiktok'];

function nr_suggest(string $q): int
{
    $r = json_decode(nr_get('https://suggestqueries.google.com/complete/search?client=firefox&hl=fr&gl=fr&ie=utf-8&oe=utf-8&q=' . rawurlencode($q)), true);
    return count((array)($r[1] ?? []));
}

function nr_serp(string $q): array
{
    global $CFG;
    $key = (string)($CFG['apis']['serpapi'] ?? '');
    if ($key !== '') {
        $j = json_decode(nr_get('https://serpapi.com/search.json?engine=google&gl=fr&hl=fr&num=10&q=' . rawurlencode($q) . '&api_key=' . rawurlencode($key)), true);
        if (!empty($j['organic_results'])) return array_map(fn($r) => (string)parse_url($r['link'], PHP_URL_HOST), $j['organic_results']);
    }
    preg_match_all('#<cite>([^<]+)#', nr_get('https://www.bing.com/search?cc=fr&setlang=fr&q=' . rawurlencode($q)), $m);
    return array_slice(array_map(fn($c) => (string)preg_replace('#^https?://([^/ ›]+).*$#u', '$1', trim(html_entity_decode($c))), $m[1] ?? []), 0, 10);
}

$scored = [];
foreach ($cands as $c) {
    $seeds = array_slice(array_values(array_filter((array)($c['seeds'] ?? []))), 0, 4);
    $c['sub'] = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)preg_replace('/\..*$/', '', (string)($c['sub'] ?? ''))));
    if (!$seeds || $c['sub'] === '') continue;
    $demand = 0;
    foreach ($seeds as $s) foreach (['', 'comment ', 'meilleur ', 'prix '] as $mod) { $demand += nr_suggest($mod . $s); usleep(250000); }
    $auth = 0; $weak = 0; $n = 0;
    foreach (array_slice($seeds, 0, 2) as $s) {
        foreach (nr_serp($s) as $d) {
            $n++;
            foreach (NR_AUTHORITY as $a) if (str_contains($d, $a)) { $auth++; break; }
            foreach (NR_WEAK as $w) if (str_contains($d, $w)) { $weak++; break; }
        }
        usleep(800000);
    }
    $dgj = json_decode(nr_get('https://www.data.gouv.fr/api/1/datasets/?page_size=1&q=' . rawurlencode($seeds[0])), true);
    $data = (int)($dgj['total'] ?? 0);
    $rpm = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($c['rpm_estime'] ?? '3')));
    $sDemand = min(1, $demand / 120);
    $sComp = $n ? max(0, 1 - ($auth - $weak * 0.5) / $n) : 0.5;
    $sRpm = min(1, $rpm / 10);
    $sData = ($data > 0 || !empty($c['donnees'])) ? 1 : 0;
    $c['score'] = round(100 * (0.4 * $sDemand + 0.3 * $sComp + 0.2 * $sRpm + 0.1 * $sData));
    $c['mesures'] = ['suggestions' => $demand, 'resultats_analyses' => $n, 'sites_autorite' => $auth, 'forums_ugc' => $weak, 'jeux_datagouv' => $data, 'rpm' => $rpm];
    $scored[] = $c;
    nr_say(sprintf('%-20s score %3d  (demande %d, autorité %d/%d, forums %d, data %d)', $c['sub'], $c['score'], $demand, $auth, $n, $weak, $data));
}
usort($scored, fn($x, $y) => $y['score'] <=> $x['score']);
$top = array_slice($scored, 0, 5);
$report = ['at' => date('Y-m-d H:i'), 'candidats' => count($scored), 'top' => $top, 'autres' => array_map(fn($c) => ['sub' => $c['sub'], 'name' => $c['name'], 'score' => $c['score']], array_slice($scored, 5))];
if (isset($opt['dry'])) { echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n"; exit; }
file_put_contents(NR_LAST, json_encode($report, JSON_UNESCAPED_UNICODE));
$r = nr_fab($G, 'niches', $report);
nr_say('envoyé à la fabrique : ' . json_encode($r));
nr_say('e-mail : ' . nr_mail($report, $CFG));
