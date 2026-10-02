<?php
// Importe les offres d'alternance de La bonne alternance (API Apprentissage, export quotidien mis à jour vers 3 h).
// Cron quotidien. Jeton : réglage lba_token. Les offres non revues sont fermées par jobs_sync (3 jours).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/jobs_lba.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
$say = fn(string $m) => print(date('c') . " $m\n");

$tok = setting('lba_token', '');
if ($tok === '') exit("lba_token absent\n");
$ch = curl_init('https://api.apprentissage.beta.gouv.fr/api/job/v1/export');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
$j = json_decode((string)curl_exec($ch), true);
curl_close($ch);
if (empty($j['url'])) exit('export indisponible : ' . json_encode($j) . "\n");

$tmp = cfg('data_dir') . '/tmp';
if (!is_dir($tmp)) mkdir($tmp, 0750, true);
$raw = "$tmp/lba_export.json"; $jsonl = "$tmp/lba_offres.jsonl";
$fp = fopen($raw, 'w');
$ch = curl_init($j['url']);
curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 900]);
$ok = curl_exec($ch); curl_close($ch); fclose($fp);
if (!$ok || filesize($raw) < 1000000) { @unlink($raw); exit("téléchargement échoué\n"); }
$say('export ' . round(filesize($raw) / 1048576) . ' Mo (maj ' . ($j['lastUpdate'] ?? '?') . ')');
$n = trim((string)shell_exec('python3 ' . escapeshellarg(__DIR__ . '/lba_filter.py') . ' ' . escapeshellarg($raw) . ' ' . escapeshellarg($jsonl) . ' 2>&1'));
@unlink($raw);
if (!ctype_digit($n) || (int)$n < 100) { @unlink($jsonl); exit("filtrage anormal : $n\n"); }
$say("$n offres d'alternance retenues");

foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $s) {
    $db = jobs_db($s['host']);
    $now = now(); $c = 0;
    $up = $db->prepare("INSERT INTO jobs(id,src,slug,title,company,description,city,postal,region,contract,contract_label,worktime,salary,experience,sector,url,created_at,seen_at,status)
        VALUES(:id,:src,:slug,:title,:company,:description,:city,:postal,:region,:contract,:contract_label,:worktime,:salary,:experience,:sector,:url,:created_at,:seen,'open')
        ON CONFLICT(id) DO UPDATE SET seen_at=excluded.seen_at, status='open', title=excluded.title, description=excluded.description, url=excluded.url, contract_label=excluded.contract_label");
    $db->beginTransaction();
    foreach (new SplFileObject($jsonl) as $line) {
        if (!($o = json_decode((string)$line, true))) continue;
        $o['created_at'] = $o['created_at'] ?: $now;
        $o['slug'] = slugify($o['title'] . ' ' . $o['city'], 70) . '-' . substr(md5($o['id']), 0, 6);
        $o['seen'] = $now;
        $up->execute($o);
        if (++$c % 2000 === 0) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    cache_clear($s['host']);
    $open = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND id LIKE 'lba-%'")->fetchColumn();
    $say("{$s['host']} : $c importées, $open offres La bonne alternance ouvertes");
    flog($s['host'], 'jobs', "La bonne alternance : $c importées");
}
@unlink($jsonl);
