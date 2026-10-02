<?php
// Importe les offres d'alternance de La bonne alternance (API Apprentissage, export quotidien mis à jour vers 3 h).
// Cron quotidien. Jeton : réglage lba_token. Les offres non revues sont fermées par jobs_sync (3 jours).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/lba_blocks.php';
ini_set('memory_limit', '1536M');
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/jobs_lba.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
$say = fn(string $m) => print(date('c') . " $m\n");

$tok = setting('lba_token', '');
if ($tok === '') exit("lba_token absent\n");

// Rappel de renouvellement du jeton (valable 1 an) : mail à J-30, J-14, J-7, J-3, J-1, puis chaque jour une fois expiré.
$exp = (int)(json_decode((string)base64_decode(strtr(explode('.', $tok)[1] ?? '', '-_', '+/')), true)['exp'] ?? 0);
if ($exp) {
    $left = (int)floor(($exp - time()) / 86400);
    if (in_array($left, [30, 14, 7, 3, 1, 0], true) || $left < 0) {
        $msg = "Le jeton de l'API La bonne alternance utilisé par recruteur.eu " . ($left < 0 ? 'a EXPIRÉ le ' : 'expire le ') . date('d/m/Y', $exp)
            . ($left >= 0 ? " (dans $left jour(s))" : '') . ".\nSans renouvellement, les offres d'alternance ne sont plus mises à jour et se ferment au bout de 3 jours.\n\n"
            . "Pour le renouveler :\n1. Aller sur https://api.apprentissage.beta.gouv.fr/fr et se connecter (compte e.guenver@gmail.com).\n"
            . "2. Ouvrir « Mon compte », rubrique des jetons d'accès API.\n3. Générer un nouveau jeton (nom : recruteur-eu) et le copier.\n"
            . "4. Le transmettre à Claude (projet SITES ADSENSE & PUB) : il le remplace dans le réglage « lba_token » de la fabrique (WEBNORMANDIE, data/registry.sqlite).\n"
            . "   Ou le saisir soi-même dans l'admin de la fabrique : Réglages > lba_token.\n5. Vérifier le lendemain dans data/jobs_lba.log que l'import de 4 h 41 s'est bien déroulé.\n";
        foreach (['e.guenver@gmail.com', 'contact@guenver.com'] as $to)
            @mail($to, '=?UTF-8?B?' . base64_encode('[recruteur.eu] Jeton La bonne alternance : ' . ($left < 0 ? 'expiré' : "expire dans $left j")) . '?=', $msg,
                "From: fabrique@recruteur.eu\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        $say("rappel d'expiration envoyé (J$left)");
    }
}
$ch = curl_init('https://api.apprentissage.beta.gouv.fr/api/job/v1/export');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
$j = json_decode((string)curl_exec($ch), true);
curl_close($ch);
if (empty($j['url'])) exit('export indisponible : ' . json_encode($j) . "\n");

$tmp = cfg('data_dir') . '/tmp';
if (!is_dir($tmp)) mkdir($tmp, 0750, true);
$raw = "$tmp/lba_export.json"; $jsonl = "$tmp/lba_offres.jsonl"; $recl = "$tmp/lba_recruteurs.jsonl";
$fp = fopen($raw, 'w');
$ch = curl_init($j['url']);
curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 900]);
$ok = curl_exec($ch); curl_close($ch); fclose($fp);
if (!$ok || filesize($raw) < 1000000) { @unlink($raw); exit("téléchargement échoué\n"); }
$say('export ' . round(filesize($raw) / 1048576) . ' Mo (maj ' . ($j['lastUpdate'] ?? '?') . ')');
$n = trim((string)shell_exec('python3 ' . escapeshellarg(__DIR__ . '/lba_filter.py') . ' ' . escapeshellarg($raw) . ' ' . escapeshellarg($jsonl) . ' ' . escapeshellarg($recl) . ' 2>&1'));
@unlink($raw);
if (!ctype_digit($n) || (int)$n < 100) { @unlink($jsonl); exit("filtrage anormal : $n\n"); }
$say("$n offres d'alternance retenues");

foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $s) {
    $db = jobs_db($s['host']);
    $now = now(); $c = 0;
    $up = $db->prepare("INSERT INTO jobs(id,src,slug,title,company,description,city,postal,region,contract,contract_label,worktime,salary,experience,sector,url,created_at,seen_at,status)
        VALUES(:id,:src,:slug,:title,:company,:description,:city,:postal,:region,:contract,:contract_label,:worktime,:salary,:experience,:sector,:url,:created_at,:seen,'open')
        ON CONFLICT(id) DO UPDATE SET seen_at=excluded.seen_at, status='open', title=excluded.title, description=excluded.description, url=excluded.url, contract_label=excluded.contract_label");
    lba_tables($db);
    $meta = $db->prepare('INSERT OR REPLACE INTO lba_job_meta(id,romes,lat,lon) VALUES(?,?,?,?)');
    $db->beginTransaction();
    foreach (new SplFileObject($jsonl) as $line) {
        if (!($o = json_decode((string)$line, true))) continue;
        $meta->execute([$o['id'], $o['_romes'] ?? '', $o['_lat'] ?? null, $o['_lon'] ?? null]);
        unset($o['_romes'], $o['_lat'], $o['_lon']);
        $o['created_at'] = $o['created_at'] ?: $now;
        $o['slug'] = slugify($o['title'] . ' ' . $o['city'], 70) . '-' . substr(md5($o['id']), 0, 6);
        $o['seen'] = $now;
        $up->execute($o);
        if (++$c % 2000 === 0) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    // entreprises qui recrutent en alternance (remplacement complet)
    if (is_file($recl) && filesize($recl) > 1000) {
        $db->beginTransaction();
        $db->exec('DELETE FROM lba_recruteurs');
        $ins = $db->prepare('INSERT INTO lba_recruteurs(siret,name,naf,size,city,cname,postal,region,lat,lon,url) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $nr = 0;
        foreach (new SplFileObject($recl) as $line) {
            if (!($r = json_decode((string)$line, true))) continue;
            $ins->execute([$r['siret'], $r['name'], $r['naf'], $r['size'], $r['city'], job_city_norm((string)$r['city']), $r['postal'], $r['region'], $r['lat'], $r['lon'], $r['url']]);
            $nr++;
        }
        $db->commit();
        $say("{$s['host']} : $nr entreprises qui recrutent en alternance");
    }
    cache_clear($s['host']);
    $open = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND id LIKE 'lba-%'")->fetchColumn();
    $say("{$s['host']} : $c importées, $open offres La bonne alternance ouvertes");
    flog($s['host'], 'jobs', "La bonne alternance : $c importées");
}
@unlink($jsonl); @unlink($recl);
