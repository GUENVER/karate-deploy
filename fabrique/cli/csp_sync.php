<?php
// Emploi public : import des offres de Choisir le service public (pages publiques, données sous Licence Ouverte).
// Cron horaire avec verrou et budget de temps ; rythme poli (≈1 requête/s). Première passe : reprise complète progressive.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/csp.php';
ini_set('memory_limit', '512M');
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/csp_sync.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
$t0 = time(); $budget = (int)($argv[1] ?? 3000); // secondes
$say = fn(string $m) => print(date('c') . " $m\n");
$sites = registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll();
if (!$sites) exit;
$main = jobs_db($sites[0]['host']); csp_tables($main);
$known = fn(string $ref) => (bool)$main->query('SELECT 1 FROM csp_queue WHERE ref=' . $main->quote($ref))->fetchColumn();
$enqueue = $main->prepare("INSERT OR IGNORE INTO csp_queue(ref,url,found) VALUES(?,?,?)");

// 1. Nouvelles offres : pages récentes jusqu'à une page entièrement connue
$new = 0;
for ($p = 1; $p <= 60; $p++) {
    $list = csp_list_page($p);
    if ($list === null) break;
    $fresh = 0;
    foreach ($list as $ref => $url) if (!$known($ref)) { $enqueue->execute([$ref, $url, now()]); $fresh++; }
    $new += $fresh;
    if (!$fresh && $p > 1) break;
    usleep(800000);
}
// 2. Reprise complète progressive (première passe)
$bp = (int)setting('csp_backfill_page', '1');
if ($bp > 0) {
    $end = $bp + 150;
    for (; $bp < $end && time() - $t0 < $budget / 3; $bp++) {
        $list = csp_list_page($bp);
        if ($list === null) { $bp = 0; break; }
        if (!$list) { $bp = 0; break; } // fin des pages
        foreach ($list as $ref => $url) $enqueue->execute([$ref, $url, now()]);
        usleep(800000);
    }
    setting_set('csp_backfill_page', (string)$bp);
}
$say("$new nouvelles offres repérées, reprise page " . ($bp ?: 'terminée') . ', file : ' . $main->query("SELECT COUNT(*) FROM csp_queue WHERE status='todo'")->fetchColumn());

// 3. Lecture des offres en file (les plus récentes d'abord)
$ups = []; $metas = [];
foreach ($sites as $s) {
    $db = jobs_db($s['host']); csp_tables($db);
    $ups[$s['host']] = [$db, $db->prepare("INSERT INTO jobs(id,src,slug,title,company,description,city,postal,region,contract,contract_label,worktime,salary,experience,sector,url,created_at,seen_at,status)
        VALUES(:id,:src,:slug,:title,:company,:description,:city,:postal,:region,:contract,:contract_label,:worktime,:salary,:experience,:sector,:url,:created_at,:seen,'open')
        ON CONFLICT(id) DO UPDATE SET seen_at=excluded.seen_at, status='open', title=excluded.title, description=excluded.description, salary=excluded.salary, url=excluded.url, contract_label=excluded.contract_label"),
        $db->prepare('INSERT OR REPLACE INTO csp_meta(id,ref,versant,categorie,domaine,employeur,dep,dep_name,deadline,metier,statut,fetched) VALUES(:id,:ref,:versant,:categorie,:domaine,:employeur,:dep,:dep_name,:deadline,:metier,:statut,:fetched)')];
}
$done = 0; $bad = 0; $urls = [];
$q = $main->prepare("SELECT ref, url FROM csp_queue WHERE status='todo' AND tries<3 ORDER BY found DESC, ref DESC LIMIT 500");
$mark = $main->prepare('UPDATE csp_queue SET status=?, tries=tries+1 WHERE ref=?');
while (time() - $t0 < $budget) {
    $q->execute(); $batch = $q->fetchAll();
    if (!$batch) break;
    foreach ($batch as $b) {
        if (time() - $t0 >= $budget) break 2;
        [$code, $html] = csp_get($b['url']);
        $r = $code === 200 ? csp_parse($html, $b['ref'], $b['url']) : null;
        if (!$r) { $mark->execute([$code === 404 ? 'gone' : 'todo', $b['ref']]); $bad++; usleep(800000); continue; }
        [$job, $meta] = $r;
        $job['slug'] = slugify($job['title'] . ' ' . $meta['dep_name'], 70) . '-' . substr(md5($job['id']), 0, 6);
        $job['seen'] = now();
        foreach ($ups as [$db, $up, $mt]) { $up->execute($job); $mt->execute($meta); }
        $mark->execute(['done', $b['ref']]);
        $urls[] = $job['slug'];
        $done++;
        usleep(800000);
    }
}
$say("$done offres importées, $bad en échec");

// 4. Validité : offres dont la date limite est passée → fermées ; les autres restent ouvertes (jobs_sync ferme ce qui n'est pas revu)
foreach ($ups as $h => [$db]) {
    $db->exec("UPDATE jobs SET seen_at='" . now() . "', status='open' WHERE id IN (SELECT id FROM csp_meta WHERE (deadline<>'' AND deadline>=date('now')) OR (deadline='' AND fetched>=datetime('now','-45 days')))");
    $db->exec("UPDATE jobs SET status='closed' WHERE status='open' AND id IN (SELECT id FROM csp_meta WHERE deadline<>'' AND deadline<date('now'))");
    $open = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND id LIKE 'csp-%'")->fetchColumn();
    if ($done) {
        cache_clear($h);
        $site = registry()->query('SELECT * FROM sites WHERE host=' . $db->quote($h))->fetch();
        foreach (array_chunk(array_map(fn($s) => "https://$h/offre/$s/", $urls), 5000) as $c) indexnow_ping($site, $c);
    }
    $say("$h : $open offres d'emploi public ouvertes");
}
