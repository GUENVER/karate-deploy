<?php
// Classe les offres ouvertes de recruteur.eu par saison (Noël, hiver, été…). Cron toutes les 3 h, après jobs_sync.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/saison.php';
if (function_exists('proc_nice')) @proc_nice(19);
set_time_limit(0);
$site = site_by_host('www.recruteur.eu'); if (!$site) exit(1);
$t = microtime(true);
$sai = ss_fetch_sai($site, fn($m) => null);
if ($sai) echo date('c') . " France Travail saisonnier : $sai offres\n";
echo date('c') . ' ' . json_encode(ss_build($site)) . ' en ' . round(microtime(true) - $t, 1) . "s\n";
