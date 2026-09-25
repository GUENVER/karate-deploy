<?php
// Synchronise les offres d'emploi (sites avec l'agrégateur activé). Cron toutes les 3 h, priorité basse.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/jobs.php';
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/jobs.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $s) {
    $r = jobs_sync($s, fn($m) => print(date('c') . " {$s['host']} $m\n"));
    echo date('c') . " {$s['host']} " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    flog($s['host'], 'jobs', json_encode($r, JSON_UNESCAPED_UNICODE));
}
