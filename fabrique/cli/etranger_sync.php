<?php
// Offres à l'étranger pour francophones (Adzuna, 6 pays × 2 pages). Cron 1×/jour.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/etranger.php';
if (function_exists('proc_nice')) @proc_nice(19);
set_time_limit(0);
$site = site_by_host('www.recruteur.eu'); if (!$site) exit(1);
$n = ew_sync($site, fn($m) => print(date('c') . ' ' . $m . "\n"));
echo date('c') . " total : $n offres\n";
