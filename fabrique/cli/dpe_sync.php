<?php
// Synchronise les statistiques DPE par commune (sites avec le module activé). Cron mensuel, priorité basse.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/fuel.php';
require dirname(__DIR__) . '/app/dpe.php';
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/dpe.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND dpe=1")->fetchAll() as $s) {
    $t = microtime(true);
    $r = dpe_sync($s, fn($m) => print(date('c') . " $m\n"));
    echo date('c') . " {$s['host']} " . json_encode($r, JSON_UNESCAPED_UNICODE) . ' en ' . round(microtime(true) - $t, 1) . "s\n";
}
