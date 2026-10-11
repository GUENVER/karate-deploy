<?php
// recruteur.eu : enrichissement des offres et données de référence.
//   php recruteur_enrich.php enrich   (toutes les 20 min) : champs calculés des offres nouvelles ou modifiées, puis agrégats
//   php recruteur_enrich.php rome     (trimestriel)       : référentiel ROME 4.0
//   php recruteur_enrich.php ref      (mensuel)           : données publiques (communes, zones d'emploi, BMO, loyers, DVF, annuaire)
//   php recruteur_enrich.php sirene   (horaire)           : fiches entreprises (API Recherche d'entreprises), par lots
// En cas d'échec d'une source : l'ancienne version reste servie ; alerte mail seulement après deux échecs consécutifs.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
foreach (['core', 'render', 'feeds', 'jobs', 'fuel', 'csp', 'metiers', 'recruteurs', 'alerts', 'tgbot', 'enrich', 'rome'] as $m) require dirname(__DIR__) . "/app/$m.php";
foreach (['complements', 'pubdata', 'depmap'] as $m) if (is_file(dirname(__DIR__) . "/app/$m.php")) require dirname(__DIR__) . "/app/$m.php";
if (function_exists('proc_nice')) @proc_nice(19);
@set_time_limit(0); ini_set('memory_limit', '1024M');
$mode = $argv[1] ?? 'enrich';
$lock = fopen(cfg('data_dir') . "/recruteur_$mode.lock", 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
$fail = function (string $what, string $why) {
    $k = 'fail_' . $what; $n = (int)setting($k, '0') + 1; setting_set($k, (string)$n);
    echo date('c') . " ÉCHEC $what : $why (échecs consécutifs : $n)\n";
    if ($n === 2 && function_exists('alert_mail')) alert_mail("recruteur.eu : import « $what » en échec", "Deux échecs consécutifs : $why\nLes données précédentes restent affichées.");
};
$okk = fn(string $what) => setting_set('fail_' . $what, '0');
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $site) {
    $t0 = microtime(true); $db = jobs_db($site['host']); $r = [];
    if ($mode === 'enrich') {
        $r = jx_run($site, (int)($argv[2] ?? 40000));
        if ((int)date('i') < 20 || ($argv[3] ?? '') === 'agg') $r += jx_aggregates($site); // agrégats une fois par heure
        if (((int)date('G') === 4 && (int)date('i') < 20) || ($argv[3] ?? '') === 'agg') { if (function_exists('comp_history_build')) $r['company_hist'] = comp_history_build($db); }
    } elseif ($mode === 'rome') {
        $r = rome_import($db, cfg('data_dir') . '/ref');
        isset($r['error']) ? $fail('rome', $r['error']) : $okk('rome');
    } elseif ($mode === 'ref' && function_exists('pd_import_all')) {
        foreach (pd_import_all($db, cfg('data_dir') . '/ref') as $what => $res) { $r[$what] = $res; isset($res['error']) ? $fail($what, $res['error']) : $okk($what); }
    } elseif ($mode === 'sirene' && function_exists('pd_sirene_batch')) {
        $r = pd_sirene_batch($db, (int)($argv[2] ?? 300));
    }
    $r['sec'] = round(microtime(true) - $t0, 1);
    echo date('c') . " {$site['host']} $mode " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
}
