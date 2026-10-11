<?php
// Vérifie auprès de l'API France Travail (détail par identifiant) l'état réel des offres FT que la synchro ne revoit plus.
// La synchro ne lit que les offres les plus récentes de chaque région : une offre encore en ligne peut sortir de cette fenêtre.
//   1. offres ouvertes non revues depuis 2 jours : confirmées (restent ouvertes) ou fermées si l'API ne les connaît plus ;
//   2. offres fermées depuis moins de 30 jours, jamais vérifiées : rouvertes si l'API les donne encore actives.
// Cron horaire (minute 47, hors synchro), priorité basse, 4 appels/s maxi (limite API : 10/s), budget et durée plafonnés.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/ft_verify.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");

$BUDGET = (int)($argv[1] ?? 1000);   // appels API par passage
$MAXSEC = 900;                        // 15 min maxi
$t0 = time();
$log = fn(string $m) => print(date('c') . " $m\n");

foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $site) {
    $host = $site['host'];
    $db = jobs_db($host);
    $tok = ft_token();
    if (!$tok) { $log("$host : pas de jeton France Travail, rien vérifié"); continue; }

    // 1. ouvertes à risque (les plus anciennes d'abord), 2. fermées récentes jamais vérifiées (les plus récentes d'abord)
    $risk = $db->query("SELECT id, slug, region, status FROM jobs WHERE id LIKE 'ft-%' AND status='open' AND seen_at < datetime('now','-2 days')
        AND (verified_at IS NULL OR verified_at < datetime('now','-3 days')) ORDER BY COALESCE(verified_at, seen_at) LIMIT " . (int)ceil($BUDGET * 0.7))->fetchAll(); // 30 % du budget toujours réservé au rattrapage
    $left = $BUDGET - count($risk);
    $back = $left > 0 ? $db->query("SELECT id, slug, region, status FROM jobs WHERE id LIKE 'ft-%' AND status='closed' AND verified_at IS NULL
        AND seen_at > datetime('now','-30 days') ORDER BY seen_at DESC LIMIT $left")->fetchAll() : [];

    $now = now();
    $setLive = $db->prepare("UPDATE jobs SET status='open', verified_at=:now,
        updated_at=CASE WHEN status<>'open' OR title IS NOT :title OR description IS NOT :description OR salary IS NOT :salary THEN :now ELSE updated_at END,
        title=:title, description=:description, salary=:salary, url=:url WHERE id=:id");
    $setGone = $db->prepare("UPDATE jobs SET status='closed', verified_at=? WHERE id=?");
    $st = ['checked' => 0, 'live' => 0, 'reopened' => 0, 'gone' => 0, 'closed_now' => 0, 'errors' => 0];
    $ping = [];
    foreach (array_merge($risk, $back) as $r) {
        if (time() - $t0 > $MAXSEC || $st['errors'] >= 25) break;
        $u = 'https://api.francetravail.io/partenaire/offresdemploi/v2/offres/' . rawurlencode(substr($r['id'], 3));
        for ($try = 0; $try < 2; $try++) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'fabrique-jobs/1.0',
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Accept: application/json']]);
            $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
            if ($code === 401 && $try === 0) { $tok = ft_token() ?: $tok; continue; } // jeton expiré pendant le passage
            if ($code === 429 && $try === 0) { sleep(3); continue; }
            break;
        }
        usleep(250000);
        $st['checked']++;
        $o = $code === 200 ? json_decode($body, true) : null;
        if ($code === 200 && is_array($o) && !empty($o['id'])) {
            $m = ft_map($o, (string)$r['region']);
            if (!empty($m['_rome']) || ($m['_lat'] ?? null) !== null) { static $ri = null; $ri ??= (function () use ($host) { $d = jobs_db($host); $d->exec('CREATE TABLE IF NOT EXISTS job_rome(id TEXT PRIMARY KEY, rome TEXT, lat REAL, lon REAL)'); return $d->prepare('INSERT OR REPLACE INTO job_rome(id,rome,lat,lon) VALUES(?,?,?,?)'); })(); $ri->execute([$r['id'], $m['_rome'], $m['_lat'], $m['_lon']]); }
            if ($m['title'] === '' || $m['url'] === '') { $st['errors']++; continue; }
            $setLive->execute([':now' => $now, ':title' => $m['title'], ':description' => $m['description'], ':salary' => $m['salary'], ':url' => $m['url'], ':id' => $r['id']]);
            $st['live']++;
            if ($r['status'] !== 'open') { $st['reopened']++; $ping[] = $r['slug']; @unlink(cache_key($host, '/offre/' . $r['slug'] . '/')); } // la page « expirée » en cache doit disparaître
        } elseif ($code === 204 || $code === 404 || $code === 410) {
            $setGone->execute([$now, $r['id']]);
            $st['gone']++;
            if ($r['status'] === 'open') { $st['closed_now']++; $ping[] = $r['slug']; @unlink(cache_key($host, '/offre/' . $r['slug'] . '/')); }
        } else {
            $st['errors']++; // 5xx, 429 persistant… : on retentera au prochain passage, l'offre ne change pas d'état
        }
    }
    if ($st['reopened'] || $st['closed_now']) {
        foreach (glob(cfg('data_dir') . '/cache/' . $host . '.*.xml') ?: [] as $f) @touch($f, 1); // sitemaps à reconstruire
        $ok = 0;
        foreach (array_chunk(array_map(fn($s) => "https://$host/offre/$s/", $ping), 5000) as $c) { $code = indexnow_ping($site, $c); if ($code === 200 || $code === 202) $ok += count($c); }
        $st['indexnow'] = $ok;
    }
    $st['candidates'] = count($risk) . '+' . count($back);
    $st['sec'] = time() - $t0;
    $log("$host " . json_encode($st, JSON_UNESCAPED_UNICODE));
    flog($host, 'ft_verify', json_encode($st, JSON_UNESCAPED_UNICODE));
}
