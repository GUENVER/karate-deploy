<?php
// Suivi quotidien des robots et erreurs de recruteur.eu (complète trafic_recruteur.php, cron 23:58).
// - compteurs par robot IA / moteur, codes d'erreur, anciens sitemaps WordPress ;
// - visiteurs arrivés de ChatGPT sur des offres marquées fermées ;
// - IP des robots comparées aux listes officielles (OpenAI, Bing, Google) : un user-agent seul ne prouve rien ;
// - archive gzip du journal du jour (30 jours conservés) pour pouvoir comparer plusieurs jours.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
if (function_exists('proc_nice')) @proc_nice(19);
$LOG = '/home2/guenver/access-logs/recruteur.webnormandie.com-ssl_log';
$DIR = '/home2/guenver/fabrique/data';
$CSV = "$DIR/robots_recruteur.csv";
$DB  = "$DIR/sites/www.recruteur.eu.sqlite";
$COLS = ['date','oai','oai_offre_ok','oai_wpsitemap','oai_4xx','oai_ip_hors_liste','chatgpt_user','chatgpt_user_4xx','chatgpt_user_ip_hors_liste',
         'gptbot','bingbot','bingbot_ip_hors_liste','googlebot','googlebot_ip_hors_liste','perplexity','claude','meta_apercu_403','ahrefs',
         'chatgpt_arrivees_offre','chatgpt_arrivees_offre_fermee','err_404','err_5xx','visites_email','visites_telegram','visites_partage'];

// listes d'IP officielles, rafraîchies une fois par jour
$lists = ['oai' => 'https://openai.com/searchbot.json', 'cgu' => 'https://openai.com/chatgpt-user.json',
          'bing' => 'https://www.bing.com/toolbox/bingbot.json', 'google' => 'https://developers.google.com/static/search/apis/ipranges/googlebot.json'];
@mkdir("$DIR/iplists", 0750, true);
$nets = [];
foreach ($lists as $k => $u) {
    $f = "$DIR/iplists/$k.json";
    if (!is_file($f) || filemtime($f) < time() - 86400) {
        $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_USERAGENT => 'Mozilla/5.0']);
        $j = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($c === 200 && json_decode($j, true)) file_put_contents($f, $j);
    }
    $nets[$k] = [];
    foreach ((json_decode((string)@file_get_contents($f), true)['prefixes'] ?? []) as $p) {
        $cidr = $p['ipv4Prefix'] ?? $p['ipv6Prefix'] ?? null; if (!$cidr) continue;
        [$net, $len] = explode('/', $cidr); $bin = @inet_pton($net); if ($bin !== false) $nets[$k][] = [$bin, (int)$len];
    }
}
$inList = function (string $ip, string $k) use ($nets): ?bool {
    if (!$nets[$k]) return null; // liste indisponible : non vérifiable
    $a = @inet_pton($ip); if ($a === false) return false;
    foreach ($nets[$k] as [$n, $len]) {
        if (strlen($n) !== strlen($a)) continue;
        $bytes = intdiv($len, 8); $bits = $len % 8;
        if (substr($a, 0, $bytes) !== substr($n, 0, $bytes)) continue;
        if ($bits === 0 || ((ord($a[$bytes]) ^ ord($n[$bytes])) & (0xFF << (8 - $bits)) & 0xFF) === 0) return true;
    }
    return false;
};

$h = @fopen($LOG, 'r'); if (!$h) exit("log introuvable\n");
$n = array_fill_keys(array_slice($COLS, 1), 0);
$land = []; $date = ''; $vis = ['email' => [], 'telegram' => [], 'partage' => []];
while (($l = fgets($h)) !== false) {
    if (!preg_match('/^(\S+) \S+ \S+ \[([^:]+)[^\]]*\] "\S+ (\S+)[^"]*" (\d+) \S+ "([^"]*)" "([^"]*)"/', $l, $m)) continue;
    [, $ip, $d, $url, $code, $ref, $ua] = $m; $date = $d; $code = (int)$code;
    if ($code === 404) $n['err_404']++;
    if ($code >= 500) $n['err_5xx']++;
    if (stripos($ua, 'OAI-SearchBot') !== false) {
        $n['oai']++;
        if (str_starts_with($url, '/wp-sitemap')) $n['oai_wpsitemap']++;
        if ($code === 200 && str_starts_with($url, '/offre/')) $n['oai_offre_ok']++;
        if ($code >= 400) $n['oai_4xx']++;
        if ($inList($ip, 'oai') === false) $n['oai_ip_hors_liste']++;
        continue;
    }
    if (stripos($ua, 'ChatGPT-User') !== false) {
        $n['chatgpt_user']++;
        if ($code >= 400) $n['chatgpt_user_4xx']++;
        if ($inList($ip, 'cgu') === false) $n['chatgpt_user_ip_hors_liste']++;
        continue;
    }
    if (stripos($ua, 'GPTBot') !== false) { $n['gptbot']++; continue; }
    if (stripos($ua, 'bingbot') !== false) { $n['bingbot']++; if ($inList($ip, 'bing') === false) $n['bingbot_ip_hors_liste']++; continue; }
    if (stripos($ua, 'Googlebot') !== false) { $n['googlebot']++; if ($inList($ip, 'google') === false) $n['googlebot_ip_hors_liste']++; continue; }
    if (stripos($ua, 'Perplexity') !== false) { $n['perplexity']++; continue; }
    if (preg_match('/Claude-|ClaudeBot|anthropic/i', $ua)) { $n['claude']++; continue; }
    if (stripos($ua, 'facebookexternalhit') !== false) { if ($code === 403) $n['meta_apercu_403']++; continue; }
    if (stripos($ua, 'AhrefsBot') !== false) { $n['ahrefs']++; continue; }
    if (preg_match('/bot|crawl|spider/i', $ua)) continue;
    if (preg_match('/utm_source=(email|telegram|partage)/', $url, $us)) $vis[$us[1]][$ip] = 1;
    if ((preg_match('/chatgpt\.com/i', $ref) || stripos($url, 'utm_source=chatgpt') !== false) && preg_match('#^/offre/([a-z0-9\-]+)/#', $url, $s)) $land[$s[1]] = 1;
}
fclose($h);
$n['chatgpt_arrivees_offre'] = count($land);
$n['visites_email'] = count($vis['email']); $n['visites_telegram'] = count($vis['telegram']); $n['visites_partage'] = count($vis['partage']);
if ($land) {
    try {
        $pdo = new PDO('sqlite:' . $DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $pdo->exec('PRAGMA busy_timeout=8000');
        $st = $pdo->prepare("SELECT status FROM jobs WHERE slug=?");
        foreach (array_keys($land) as $sl) { $st->execute([$sl]); if ($st->fetchColumn() === 'closed') $n['chatgpt_arrivees_offre_fermee']++; }
    } catch (Throwable $e) {}
}
$dt = DateTime::createFromFormat('d/M/Y', $date);
$day = $dt ? $dt->format('Y-m-d') : date('Y-m-d');
$row = array_merge([$day], array_values($n));
$lines = is_file($CSV) ? file($CSV, FILE_IGNORE_NEW_LINES) : [implode(';', $COLS)];
$lines = array_values(array_filter($lines, fn($x) => !str_starts_with($x, $day . ';')));
$lines[] = implode(';', $row);
file_put_contents($CSV, implode("\n", $lines) . "\n");

// archive du journal du jour (gzip), 30 jours conservés
@mkdir("$DIR/logs", 0750, true);
$gz = "$DIR/logs/recruteur-$day.log.gz";
$in = @fopen($LOG, 'rb'); $out = @gzopen($gz . '.tmp', 'wb6');
if ($in && $out) { while (!feof($in)) gzwrite($out, (string)fread($in, 1 << 20)); fclose($in); gzclose($out); rename($gz . '.tmp', $gz); }
foreach (glob("$DIR/logs/recruteur-*.log.gz") ?: [] as $f) if (filemtime($f) < time() - 30 * 86400) @unlink($f);

echo implode(';', $row), "\n";
