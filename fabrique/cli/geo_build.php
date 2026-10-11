<?php
// Coordonnées des offres ouvertes de recruteur.eu pour la page « près de chez moi ». Cron toutes les 3 h, après jobs_sync.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/proches.php';
if (function_exists('proc_nice')) @proc_nice(19);
set_time_limit(0);
$site = site_by_host('www.recruteur.eu');
if (!$site) { echo "site inconnu\n"; exit(1); }
$t = microtime(true);
echo date('c') . ' ' . json_encode(pc_build($site)) . ' en ' . round(microtime(true) - $t, 1) . "s\n";
