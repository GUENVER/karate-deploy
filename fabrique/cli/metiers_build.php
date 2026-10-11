<?php
// Reconstruit les pages métier et métier + ville (toutes les 3 h, après jobs_sync).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/metiers.php';
foreach (['lba_blocks', 'fuel', 'rubriques'] as $inc) require_once dirname(__DIR__) . "/app/$inc.php";
ini_set('memory_limit', '768M');
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $s) {
    $r = metiers_build(jobs_db($s['host']));
    cache_clear($s['host']);
    // rubriques alternance : comptages recalculés ici (CLI) pour que les visiteurs ne tombent jamais sur un cache froid
    foreach (glob(cfg('data_dir') . '/cache/' . $s['host'] . '.rub-*.json') ?: [] as $rb) @unlink($rb);
    try { rub_region_counts($s, 'lba_formations'); rub_region_counts($s, 'lba_recruteurs'); rub_missions_by_dep($s); } catch (Throwable $e) { echo $e->getMessage(), "\n"; }
    echo date('c') . " {$s['host']} : {$r['metiers']} métiers, {$r['combos']} pages métier + ville\n";
}
