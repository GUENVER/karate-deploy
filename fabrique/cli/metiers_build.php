<?php
// Reconstruit les pages métier et métier + ville (toutes les 3 h, après jobs_sync).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/metiers.php';
ini_set('memory_limit', '768M');
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $s) {
    $r = metiers_build(jobs_db($s['host']));
    cache_clear($s['host']);
    echo date('c') . " {$s['host']} : {$r['metiers']} métiers, {$r['combos']} pages métier + ville\n";
}
