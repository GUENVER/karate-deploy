<?php
// Pré-génère le cache HTML de bretagne.decouverte.org (toutes les pages du sitemap), pour que les visiteurs
// ne paient jamais la génération d'une page. Usage : php cli/bzh_warm.php [--stale] [--max=N]
//   --stale : ne régénère que les pages absentes ou périmées (sinon tout)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/bzh.php';
if (function_exists('proc_nice')) @proc_nice(19);
set_time_limit(0);
$lock = fopen(cfg('data_dir') . '/bzh_warm.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
$site = site_by_host(BZH_HOST) ?: exit("site inconnu\n");
$onlyStale = in_array('--stale', $argv, true);
$max = 0; foreach ($argv as $a) if (preg_match('/^--max=(\d+)$/', $a, $m)) $max = (int)$m[1];
$urls = array_map(fn($u) => substr($u, strlen('https://' . BZH_HOST)), bzh_all_urls($site));
$t0 = microtime(true); $n = 0; $skip = 0; $err = 0; $slow = [];
foreach ($urls as $path) {
    $f = cache_key(BZH_HOST, $path);
    if ($onlyStale && is_file($f) && cache_is_fresh($f)) { $skip++; continue; }
    $t = microtime(true);
    try {
        $html = bzh_route($site, $path);
        if (is_string($html) && $html !== '') {
            if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
            file_put_contents($f . '.tmp', $html, LOCK_EX); rename($f . '.tmp', $f);
            $n++;
        }
    } catch (Throwable $e) { $err++; if ($err < 10) echo "ERR $path : " . $e->getMessage() . "\n"; }
    $d = microtime(true) - $t;
    if ($d > 1) $slow[] = round($d, 1) . "s $path";
    if ($max && $n >= $max) break;
    usleep(20000);
}
echo date('H:i:s') . " cache : $n pages générées, $skip déjà fraîches, $err erreurs en " . round(microtime(true) - $t0) . " s\n";
if ($slow) echo "lentes (" . count($slow) . ") : " . implode(' | ', array_slice($slow, 0, 15)) . "\n";
