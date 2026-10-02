<?php
// Référentiels La bonne alternance (API Apprentissage) : formations en apprentissage et missions locales.
// Cron hebdomadaire. Jeton : réglage lba_token.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/lba_blocks.php';
ini_set('memory_limit', '1536M');
if (function_exists('proc_nice')) @proc_nice(19);
$lock = fopen(cfg('data_dir') . '/lba_ref.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");
$say = fn(string $m) => print(date('c') . " $m\n");
$tok = setting('lba_token', '');
if ($tok === '') exit("lba_token absent\n");

function lba_get(string $path, string $tok): ?array
{
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init('https://api.apprentissage.beta.gouv.fr/api' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok]]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($code === 200) return json_decode((string)$r, true);
        sleep($code === 429 ? 60 : 10);
    }
    return null;
}

// 1. Formations (pagination nationale)
$forms = [];
for ($p = 0; ; $p++) {
    $j = lba_get('/formation/v1/search?page_size=1000&page_index=' . $p, $tok);
    if (!$j) exit("échec page formations $p\n");
    foreach ($j['data'] ?? [] as $f) {
        if (($f['statut']['catalogue'] ?? '') !== 'publié') continue;
        $c = $f['certification']['valeur'] ?? [];
        $lieu = $f['lieu'] ?? []; $ad = $lieu['adresse'] ?? [];
        $co = $lieu['geolocalisation']['coordinates'] ?? [null, null];
        $et = $f['formateur']['organisme']['etablissement'] ?? [];
        $org = trim((string)($et['enseigne'] ?? '')) ?: trim((string)($f['formateur']['organisme']['unite_legale']['raison_sociale'] ?? ''));
        $title = trim((string)($f['onisep']['intitule'] ?? '')) ?: trim((string)($c['intitule']['rncp'] ?? '')) ?: trim((string)($c['intitule']['cfd']['long'] ?? ''));
        if ($title === '' || empty($ad['region']['code_insee'])) continue;
        $dur = (int)($f['modalite']['duree_indicative'] ?? 0);
        $forms[] = [
            (string)($f['identifiant']['cle_ministere_educatif'] ?? md5(json_encode($f))), $title, (string)($c['intitule']['niveau']['cfd']['sigle'] ?? ''),
            (int)($c['intitule']['niveau']['cfd']['europeen'] ?? $c['intitule']['niveau']['rncp']['europeen'] ?? 0),
            mb_convert_case(mb_strtolower($org), MB_CASE_TITLE), (string)($ad['commune']['nom'] ?? ''), job_city_norm((string)($ad['commune']['nom'] ?? '')),
            (string)($ad['code_postal'] ?? ''), (string)($ad['departement']['code_insee'] ?? ''), (string)$ad['region']['code_insee'],
            $co[1], $co[0], implode(',', array_column($c['domaines']['rome']['rncp'] ?? [], 'code')),
            $dur ? $dur . ' an' . ($dur > 1 ? 's' : '') : '', substr((string)($f['sessions'][0]['debut'] ?? ''), 0, 10),
            (string)($f['onisep']['url'] ?? ''), mb_substr(trim((string)($f['contenu_educatif']['objectif'] ?? '')), 0, 600),
        ];
    }
    $pages = (int)($j['pagination']['page_count'] ?? 0);
    if ($p + 1 >= $pages) break;
    sleep(2);
}
$say(count($forms) . ' formations en apprentissage');

// 2. Missions locales
$ml = lba_get('/geographie/v1/mission-locale', $tok) ?: [];
$say(count($ml) . ' missions locales');
if (count($forms) < 1000 || count($ml) < 100) exit("volumes anormaux, rien remplacé\n");

foreach (registry()->query("SELECT * FROM sites WHERE status='active' AND jobs=1")->fetchAll() as $s) {
    $db = jobs_db($s['host']);
    lba_tables($db);
    $db->beginTransaction();
    $db->exec('DELETE FROM lba_formations');
    $ins = $db->prepare('INSERT OR REPLACE INTO lba_formations(id,title,sigle,niveau,organisme,city,cname,postal,dep,region,lat,lon,romes,duree,debut,url,objectif) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($forms as $f) $ins->execute($f);
    $db->exec('DELETE FROM lba_missions');
    $ins = $db->prepare('INSERT OR REPLACE INTO lba_missions(id,nom,adresse,cp,ville,lat,lon,email,tel,web) VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach ($ml as $m) {
        $l = $m['localisation'] ?? []; $co = $l['geopoint']['coordinates'] ?? [null, null];
        $ins->execute([(string)$m['id'], trim((string)$m['nom']), trim((string)($l['adresse'] ?? '')), (string)($l['cp'] ?? ''), (string)($l['ville'] ?? ''), $co[1], $co[0],
            (string)($m['contact']['email'] ?? ''), (string)($m['contact']['telephone'] ?? ''), (string)($m['contact']['siteWeb'] ?? '')]);
    }
    $db->commit();
    cache_clear($s['host']);
    $say("{$s['host']} : référentiels à jour");
}
