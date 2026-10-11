<?php
// Alertes e-mail : traitement des rebonds puis envoi plafonné (cron horaire, minute 23). Telegram : `php alerts_cron.php telegram` (8 h, 12 h, 18 h).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/fuel.php';
require dirname(__DIR__) . '/app/alerts.php';
require dirname(__DIR__) . '/app/tgbot.php';
if (function_exists('proc_nice')) @proc_nice(19);
$mode = $argv[1] ?? 'email';
$lock = fopen(cfg('data_dir') . "/alerts_$mode.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $site) {
    $t0 = microtime(true);
    $r = match ($mode) { 'telegram' => tg_post_run($site), 'tgdm' => tgb_daily_run($site), 'edito_lundi' => tgb_editorial_run($site, 'lundi'), 'edito_jeudi' => tgb_editorial_run($site, 'jeudi'),
        default => ['rebonds' => alerts_bounces($site), 'envoi' => alerts_send_run($site)] };
    $r['sec'] = round(microtime(true) - $t0, 1);
    echo date('c') . " {$site['host']} $mode " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
