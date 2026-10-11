<?php
// Régénère en tâche de fond l'index sitemap des sites lourds (évite qu'un visiteur ou un robot paie la régénération).
// Usage : php cli/sitemap_warm.php [hôte…]  (défaut : www.recruteur.eu)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/careerjet.php';
require dirname(__DIR__) . '/app/lba_blocks.php';
require dirname(__DIR__) . '/app/rubriques.php';
require dirname(__DIR__) . '/app/csp.php';
require dirname(__DIR__) . '/app/fr_map.php';
require dirname(__DIR__) . '/app/metiers.php';
require dirname(__DIR__) . '/app/recruteurs.php';
require dirname(__DIR__) . '/app/fuel.php';
require dirname(__DIR__) . '/app/ev.php';
require dirname(__DIR__) . '/app/places.php';
require dirname(__DIR__) . '/app/dpe.php';
require dirname(__DIR__) . '/app/commune.php';
require dirname(__DIR__) . '/app/bzh.php';
require dirname(__DIR__) . '/app/nor.php';
require dirname(__DIR__) . '/app/alerts.php';
require dirname(__DIR__) . '/app/tgbot.php';
require dirname(__DIR__) . '/app/enrich.php';
require dirname(__DIR__) . '/app/rome.php';
require dirname(__DIR__) . '/app/decode.php';
require dirname(__DIR__) . '/app/filters.php';
if (function_exists('proc_nice')) @proc_nice(19);
set_time_limit(0);
$hosts = array_slice($argv, 1) ?: ['www.recruteur.eu'];
foreach ($hosts as $h) {
    $site = site_by_host($h); if (!$site) { echo "$h : site inconnu\n"; continue; }
    $cf = dirname(__DIR__) . '/data/cache/' . $h . '.sitemap-index.xml';
    $lk = fopen($cf . '.lock', 'c'); if (!flock($lk, LOCK_EX | LOCK_NB)) { echo "$h : déjà en cours\n"; continue; }
    $t = microtime(true); ob_start(); out_sitemap_index_build($site); $x = ob_get_clean();
    if (strpos($x, '</sitemapindex>') !== false) { file_put_contents($cf . '.tmp', $x); rename($cf . '.tmp', $cf); }
    flock($lk, LOCK_UN); fclose($lk);
    echo date('c') . " $h : " . strlen($x) . ' o en ' . round(microtime(true) - $t, 1) . "s\n";
}
