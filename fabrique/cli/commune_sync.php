<?php
// Synchronise les fiches communes (population, DVF, risques, catastrophes naturelles). Cron mensuel, priorité basse.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/fuel.php';
require dirname(__DIR__) . '/app/dpe.php';
require dirname(__DIR__) . '/app/commune.php';
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/commune.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND commune=1")->fetchAll() as $s) {
    $t = microtime(true);
    try { $r = commune_sync($s, fn($m) => print(date('c') . " $m\n")); } catch (Throwable $e) { $r = ['erreur' => $e->getMessage()]; }
    echo date('c') . " {$s['host']} " . json_encode($r, JSON_UNESCAPED_UNICODE) . ' en ' . round(microtime(true) - $t, 1) . "s\n";
}
