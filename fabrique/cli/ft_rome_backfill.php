<?php
// Rattrapage unique du code ROME et des coordonnées des offres France Travail déjà en base (API détail, 3 appels/s, budget par passage).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/ft_rome.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
$BUDGET = (int)($argv[1] ?? 1500); $t0 = time();
foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $site) {
    $db = jobs_db($site['host']);
    $db->exec('CREATE TABLE IF NOT EXISTS job_rome(id TEXT PRIMARY KEY, rome TEXT, lat REAL, lon REAL)');
    $ids = $db->query("SELECT j.id FROM jobs j LEFT JOIN job_rome r ON r.id=j.id WHERE j.status='open' AND j.id LIKE 'ft-%' AND r.id IS NULL ORDER BY j.created_at DESC LIMIT $BUDGET")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { echo date('c') . " {$site['host']} rattrapage ROME terminé\n"; continue; }
    $tok = ft_token(); if (!$tok) { echo date('c') . " jeton France Travail indisponible\n"; exit(1); }
    $ins = $db->prepare('INSERT OR REPLACE INTO job_rome(id,rome,lat,lon) VALUES(?,?,?,?)'); $ok = 0; $err = 0;
    foreach ($ids as $id) {
        if (time() - $t0 > 1500 || $err > 30) break;
        $ch = curl_init('https://api.francetravail.io/partenaire/offresdemploi/v2/offres/' . rawurlencode(substr($id, 3)));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'fabrique-jobs/1.0', CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/json']]);
        $b = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        usleep(330000);
        if ($code === 401) { $tok = ft_token() ?: $tok; $err++; continue; }
        if ($code === 429) { sleep(5); $err++; continue; }
        if ($code === 200 && ($o = json_decode($b, true)) && !empty($o['id'])) { $ins->execute([$id, (string)($o['romeCode'] ?? ''), isset($o['lieuTravail']['latitude']) ? (float)$o['lieuTravail']['latitude'] : null, isset($o['lieuTravail']['longitude']) ? (float)$o['lieuTravail']['longitude'] : null]); $ok++; }
        elseif (in_array($code, [204, 404, 410], true)) { $ins->execute([$id, '', null, null]); }
        else $err++;
    }
    echo date('c') . " {$site['host']} ROME rattrapés : $ok, erreurs : $err, restants ~" . max(0, count($ids) - $ok) . "\n";
}
