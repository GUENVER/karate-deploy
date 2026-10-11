<?php
// Synchronisation de l'agenda de www.normandie.me (module nor).
// Usage : php cli/nor_sync.php [communes|dt|festivals|finish|all] [--indexnow]
// Sources : geo.api.gouv.fr, DATAtourisme (extrait régional Normandie, quotidien), liste des festivals (ministère de la Culture).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/nor.php';
if (function_exists('proc_nice')) @proc_nice(19);
ini_set('memory_limit', '1024M');
set_time_limit(0);

$lock = fopen(cfg('data_dir') . '/nor_sync.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");

$steps = array_values(array_filter(array_slice($argv, 1), fn($a) => $a[0] !== '-')) ?: ['all'];
if ($steps === ['all']) $steps = ['communes', 'dt', 'festivals', 'finish'];
$ping = in_array('--indexnow', $argv, true);
$dir = cfg('data_dir') . '/nor';
@mkdir($dir, 0750, true);
$db = nor_db();
$stamp = gmdate('Y-m-d H:i:s');
$today = date('Y-m-d');

function say(string $m): void { echo date('Y-m-d H:i:s') . ' ' . $m . "\n"; }

function fetch_to(string $url, string $file, int $timeout = 600): bool
{
    $fp = fopen($file . '.part', 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'normandie.me/agenda-sync (+https://www.normandie.me/agenda/)', CURLOPT_FAILONERROR => true]);
    $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
    if (!$ok || $code >= 400 || filesize($file . '.part') < 100) { @unlink($file . '.part'); return false; }
    rename($file . '.part', $file);
    return true;
}

function dgouv_resource(string $dataset, string $titleRe): ?string
{
    $j = json_decode((string)@file_get_contents('https://www.data.gouv.fr/api/1/datasets/' . $dataset . '/'), true);
    foreach ($j['resources'] ?? [] as $r) if (preg_match($titleRe, (string)$r['title'])) return $r['url'];
    return null;
}

function csv_rows(string $file, string $sep): Generator
{
    $fh = fopen($file, 'r');
    $head = fgetcsv($fh, 0, $sep, '"', '');
    $head = array_map(fn($x) => trim(preg_replace('/^(\xEF\xBB\xBF)+/', '', (string)$x)), $head);
    $n = count($head);
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        if (count($r) < $n) $r = array_pad($r, $n, '');
        yield array_combine($head, array_slice($r, 0, $n));
    }
    fclose($fh);
}

// ---------- Communes ----------
$COM = []; $BYNAME = []; $BYCP = [];
function load_communes(PDO $db): void
{
    global $COM, $BYNAME, $BYCP;
    $COM = []; $BYNAME = []; $BYCP = [];
    foreach ($db->query('SELECT insee, nom, cps, lat, lon FROM communes') as $c) {
        $COM[$c['insee']] = $c;
        $BYNAME[nor_norm($c['nom'])][] = $c['insee'];
        foreach (explode(',', (string)$c['cps']) as $cp) if ($cp !== '') $BYCP[$cp][] = $c['insee'];
    }
}
function nearest_commune(float $lat, float $lon, ?array $among = null): ?string
{
    global $COM;
    $best = null; $bd = 1e9;
    foreach ($among ?? array_keys($COM) as $i) {
        $c = $COM[$i] ?? null; if (!$c || $c['lat'] === null) continue;
        $d = ($c['lat'] - $lat) ** 2 + (($c['lon'] - $lon) * 0.66) ** 2;
        if ($d < $bd) { $bd = $d; $best = (string)$i; }
    }
    return ($among === null && $bd > 0.04) ? null : $best;
}
function find_commune(string $insee = '', string $cp = '', string $name = '', ?float $lat = null, ?float $lon = null): ?string
{
    global $COM, $BYNAME, $BYCP;
    if ($insee !== '' && isset($COM[$insee])) return $insee;
    $n = nor_norm($name);
    $cands = $n !== '' ? ($BYNAME[$n] ?? []) : [];
    if ($cp !== '' && isset($BYCP[$cp])) {
        $both = array_values(array_intersect($cands, $BYCP[$cp]));
        if (count($both) === 1) return $both[0];
        if (!$cands && count($BYCP[$cp]) === 1) return $BYCP[$cp][0];
        if (!$cands && $lat) return nearest_commune($lat, $lon, $BYCP[$cp]);
    }
    if (count($cands) === 1) return $cands[0];
    if (count($cands) > 1 && $lat) return nearest_commune($lat, $lon, $cands);
    if ($lat && $lon) return nearest_commune($lat, $lon);
    return null;
}

function step_communes(PDO $db): void
{
    $n = 0;
    $db->beginTransaction();
    foreach (NOR_DEPTS as $code => $v) {
        $j = json_decode((string)file_get_contents("https://geo.api.gouv.fr/communes?codeDepartement=$code&fields=nom,code,codesPostaux,population,centre,epci&format=json"), true);
        if (!$j) throw new RuntimeException("geo.api : rien pour $code");
        $seen = [];
        foreach ($j as $c) {
            $slug = slugify($c['nom']);
            if (isset($seen[$slug]) || in_array($slug, ['ce-week-end', 'page'], true)) $slug .= '-' . $c['code'];
            $seen[$slug] = 1;
            $db->prepare('INSERT INTO communes(insee,nom,slug,dept,cps,pop,lat,lon,epci) VALUES(?,?,?,?,?,?,?,?,?)
                ON CONFLICT(insee) DO UPDATE SET nom=excluded.nom, slug=excluded.slug, dept=excluded.dept, cps=excluded.cps, pop=excluded.pop, lat=excluded.lat, lon=excluded.lon, epci=excluded.epci')
                ->execute([$c['code'], $c['nom'], $slug, (string)$code, implode(',', $c['codesPostaux'] ?? []), (int)($c['population'] ?? 0),
                    $c['centre']['coordinates'][1] ?? null, $c['centre']['coordinates'][0] ?? null, $c['epci']['nom'] ?? '']);
            $n++;
        }
    }
    $db->commit();
    say("communes : $n");
}

// ---------- Stockage ----------
function item_put(PDO $db, array $it): void
{
    static $slugs = [];
    $k = $it['kind'];
    if (!isset($slugs[$k])) {
        $slugs[$k] = [];
        $st = $db->prepare('SELECT id, slug FROM items WHERE kind=?'); $st->execute([$k]);
        foreach ($st as $r) $slugs[$k][$r['slug']] = $r['id'];
        $st = $db->prepare('SELECT slug FROM gone WHERE kind=?'); $st->execute([$k]);
        foreach ($st as $r) if (!isset($slugs[$k][$r['slug']])) $slugs[$k][$r['slug']] = '#gone';
    }
    $old = $db->prepare('SELECT slug, ym FROM items WHERE id=?'); $old->execute([$it['id']]);
    $o = $old->fetch();
    $slug = $o['slug'] ?? null; $ym = $o['ym'] ?? null;
    if (!$slug) {
        $base = slugify($it['name'] . (!empty($it['city']) ? ' ' . $it['city'] : ''), 90) ?: 'evenement';
        $slug = $base; $i = 2;
        while (isset($slugs[$k][$slug]) && $slugs[$k][$slug] !== $it['id']) $slug = $base . '-' . $i++;
        $slugs[$k][$slug] = $it['id'];
    }
    if (!$ym) $ym = substr((string)($it['d1'] ?? $GLOBALS['today']), 0, 7);
    $db->prepare('INSERT INTO items(id,kind,sub,slug,ym,name,insee,lat,lon,d1,d2,rank,page,data,seen,created) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET kind=excluded.kind, sub=excluded.sub, name=excluded.name, insee=excluded.insee, lat=excluded.lat, lon=excluded.lon,
        d1=excluded.d1, d2=excluded.d2, rank=excluded.rank, page=excluded.page, data=excluded.data, seen=excluded.seen')
        ->execute([$it['id'], $k, $it['sub'] ?? '', $slug, $ym, mb_substr($it['name'], 0, 200), $it['insee'], $it['lat'] ?: null, $it['lon'] ?: null,
            $it['d1'] ?? null, $it['d2'] ?? null, (int)($it['rank'] ?? 0), (int)($it['page'] ?? 0),
            json_encode($it['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $GLOBALS['stamp'], $GLOBALS['stamp']]);
    // texte périmé si la description source a changé
    if (isset($it['data']['desc'])) $db->prepare("UPDATE items SET txt=NULL WHERE id=? AND txt_sig IS NOT NULL AND txt_sig<>?")->execute([$it['id'], md5((string)$it['data']['desc'])]);
    if ($k === 'evenement') {
        $db->prepare('DELETE FROM themes WHERE id=?')->execute([$it['id']]);
        foreach (nor_themes_of((string)$it['sub'], (string)$it['name']) as $th) $db->prepare('INSERT OR IGNORE INTO themes(id,theme) VALUES(?,?)')->execute([$it['id'], $th]);
    }
}

function purge_unseen(PDO $db, array $kinds, string $source): int
{
    $in = implode(',', array_map(fn($k) => $db->quote($k), $kinds));
    $st = $db->prepare("SELECT id, kind, slug FROM items WHERE kind IN ($in) AND json_extract(data,'$.src')=? AND seen<?");
    $st->execute([$source, $GLOBALS['stamp']]);
    $n = 0;
    foreach ($st->fetchAll() as $r) {
        if ($r['kind'] !== 'hebergement') $db->prepare('INSERT OR REPLACE INTO gone(kind,slug,at) VALUES(?,?,?)')->execute([$r['kind'], $r['slug'], $GLOBALS['stamp']]);
        $db->prepare('DELETE FROM themes WHERE id=?')->execute([$r['id']]);
        $n++;
    }
    $db->prepare("DELETE FROM items WHERE kind IN ($in) AND json_extract(data,'$.src')=? AND seen<?")->execute([$source, $GLOBALS['stamp']]);
    return $n;
}

// ---------- DATAtourisme (extrait Normandie) ----------
function step_dt(PDO $db, string $dir, string $today): void
{
    $url = dgouv_resource('5b598be088ee387c0c353714', '/^datatourisme-reg-nor\.csv$/');
    if (!$url || !fetch_to($url, "$dir/dt.csv")) { if (!is_file("$dir/dt.csv")) throw new RuntimeException('DATAtourisme indisponible'); say('DATAtourisme : téléchargement échoué, fichier précédent utilisé'); }
    $c = ['evenement' => 0, 'hebergement' => 0, 'hors' => 0, 'passe' => 0, 'ignores' => 0];
    $db->beginTransaction(); $i = 0;
    foreach (csv_rows("$dir/dt.csv", ',') as $r) {
        $cats = array_map(fn($u) => preg_replace('~^.*[#/]~', '', $u), explode('|', $r['Categories_de_POI']));
        $isEv = (bool)array_intersect(['EntertainmentAndEvent', 'Event'], $cats);
        $isHeb = !$isEv && (bool)array_intersect(['Accommodation', 'LodgingBusiness'], $cats);
        if (!$isEv && !$isHeb) { $c['ignores']++; continue; }
        [$cp, $city] = array_pad(explode('#', $r['Code_postal_et_commune'], 2), 2, '');
        $lat = (float)$r['Latitude']; $lon = (float)$r['Longitude'];
        $insee = find_commune('', $cp, $city, $lat ?: null, $lon ?: null);
        if (!$insee) { $c['hors']++; continue; }
        $name = trim(preg_replace('/\s+/u', ' ', (string)$r['Nom_du_POI']));
        if ($name === '') continue;
        $data = ['src' => 'dt', 'adresse' => trim($r['Adresse_postale']), 'cp' => $cp, 'ville' => $city, 'maj' => $r['Date_de_mise_a_jour'], 'auteur' => $r['Createur_de_la_donnee']];
        $links = [];
        foreach (explode('|', $r['Contacts_du_POI']) as $ct) foreach (preg_split('/#|<>/', $ct) as $u) if (($u = trim($u)) !== '' && preg_match('~^https?://~', $u) && !in_array($u, $links, true)) $links[] = $u;
        if ($links) $data['web'] = array_slice($links, 0, 2);
        $id = 'dt:' . basename($r['URI_ID_du_POI']);
        if ($isHeb) {
            $sub = 'Hébergement';
            foreach ([['CamperVanArea', 'Aire de camping-car'], ['Camping', 'Camping'], ['CampingAndCaravanning', 'Camping'], ['Hotel', 'Hôtel'], ['HotelTrade', 'Hôtel'], ['BedAndBreakfast', "Chambre d'hôtes"], ['Guesthouse', "Chambre d'hôtes"],
                ['HolidayResort', 'Village vacances'], ['GroupLodging', 'Gîte de groupe'], ['CollectiveAccommodation', 'Hébergement collectif'], ['RentalAccommodation', 'Location de vacances'], ['SelfCateringAccommodation', 'Location de vacances']] as [$k, $l])
                if (in_array($k, $cats, true)) { $sub = $l; break; }
            if (trim($r['Classements_du_POI']) !== '') $data['classements'] = array_values(array_unique(array_filter(array_map(fn($x) => trim(explode('#', $x)[0]), explode('|', $r['Classements_du_POI'])))));
            item_put($db, ['id' => $id, 'kind' => 'hebergement', 'sub' => $sub, 'name' => $name, 'city' => $city, 'insee' => $insee, 'lat' => $lat, 'lon' => $lon, 'data' => $data, 'page' => 0,
                'rank' => (!empty($data['web']) ? 10 : 0) + (!empty($data['classements']) ? 10 : 0)]);
            $c['hebergement']++;
        } else {
            $periods = [];
            foreach (explode('|', $r['Periodes_regroupees']) as $p) {
                [$a, $b] = array_pad(explode('<->', $p), 2, '');
                $a = substr(trim($a), 0, 10); $b = substr(trim($b) ?: $a, 0, 10);
                if (preg_match('/^\d{4}-\d\d-\d\d$/', $a) && preg_match('/^\d{4}-\d\d-\d\d$/', $b) && $b >= $today) $periods[] = [$a, $b];
            }
            if (!$periods) { $c['passe']++; continue; }
            usort($periods, fn($x, $y) => strcmp($x[0], $y[0]));
            $periods = array_values(array_unique($periods, SORT_REGULAR));
            $sub = nor_ev_sub($cats, $name);
            $data['periodes'] = array_slice($periods, 0, 30);
            $data['desc'] = mb_substr(nor_clean($r['Description']), 0, 3000);
            if (!$lat) { global $COM; $lat = (float)($COM[$insee]['lat'] ?? 0); $lon = (float)($COM[$insee]['lon'] ?? 0); $data['geo_commune'] = 1; }
            item_put($db, ['id' => $id, 'kind' => 'evenement', 'sub' => $sub, 'name' => $name, 'city' => $city, 'insee' => $insee, 'lat' => $lat, 'lon' => $lon, 'data' => $data,
                'd1' => $periods[0][0], 'd2' => $periods[0][1], 'page' => 1,
                'rank' => 10 + ($sub === 'Festival' ? 20 : 0) + ($sub === 'Concert' ? 5 : 0) + (!empty($data['web']) ? 5 : 0) + (mb_strlen($data['desc']) > 300 ? 5 : 0)]);
            $c['evenement']++;
        }
        if (++$i % 2000 === 0) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    $c['retires'] = purge_unseen($db, ['evenement', 'hebergement'], 'dt');
    say('DATAtourisme : ' . json_encode($c, JSON_UNESCAPED_UNICODE));
}

// ---------- Festivals ----------
function step_festivals(PDO $db, string $dir): void
{
    $url = dgouv_resource('liste-des-festivals-en-france', '/festivals-global/i');
    if ((!$url || !fetch_to($url, "$dir/festivals.csv")) && !is_file("$dir/festivals.csv")) throw new RuntimeException('Festivals indisponible');
    $n = 0; $db->beginTransaction();
    foreach (csv_rows("$dir/festivals.csv", ';') as $r) {
        $nom = trim((string)($r['Nom du festival'] ?? ''));
        if ($nom === '' || ($r['Région principale de déroulement'] ?? '') !== 'Normandie') continue;
        [$lat, $lon] = array_map('floatval', array_pad(explode(',', (string)($r['Géocodage xy'] ?? '')), 2, 0));
        $insee = find_commune((string)$r['Code Insee commune'], (string)$r['Code postal (de la commune principale de déroulement)'], (string)$r['Commune principale de déroulement'], $lat ?: null, $lon ?: null);
        if (!$insee) continue;
        $sous = implode(', ', array_filter(array_map('trim', [$r['Sous-catégorie spectacle vivant'] ?? '', $r['Sous-catégorie musique'] ?? '', $r['Sous-catégorie cinéma et audiovisuel'] ?? '', $r['Sous-catégorie arts visuels et arts numériques'] ?? '', $r['Sous-catégorie livre et littérature'] ?? ''])));
        $web = trim((string)($r['Site internet du festival'] ?? ''));
        if ($web !== '' && !preg_match('~^https?://~', $web)) $web = 'https://' . $web;
        $an = (int)($r['Année de création du festival'] ?? 0);
        item_put($db, ['id' => 'fest:' . ($r['Identifiant'] ?? '' ?: slugify($nom . $r['Code Insee commune'])), 'kind' => 'festival', 'sub' => (string)$r['Discipline dominante'], 'name' => $nom, 'city' => $r['Commune principale de déroulement'],
            'insee' => $insee, 'lat' => $lat, 'lon' => $lon, 'page' => 1, 'rank' => 20 + ($web ? 10 : 0) + ($an && $an < 2000 ? 15 : 0),
            'data' => array_filter(['src' => 'festivals', 'genres' => $sous, 'periode' => $r['Période principale de déroulement du festival'] ?? '', 'creation' => $an ?: '',
                'web' => $web ? [$web] : null, 'adresse' => trim(($r['Adresse postale'] ?? '') . ' ' . ($r["Complément d'adresse (facultatif)"] ?? '')), 'envergure' => $r['Envergure territoriale'] ?? ''])]);
        $n++;
    }
    $db->commit();
    say("Festivals : $n, retirés " . purge_unseen($db, ['festival'], 'festivals'));
}

// ---------- Finalisation ----------
function step_finish(PDO $db, string $today, bool $ping): void
{
    // événements terminés : retirés à J+30 (410), noindex entre-temps (géré au rendu)
    $old = date('Y-m-d', strtotime('-30 days'));
    $st = $db->prepare("SELECT id, slug FROM items WHERE kind='evenement' AND d2<?"); $st->execute([$old]);
    foreach ($st->fetchAll() as $r) { $db->prepare("INSERT OR REPLACE INTO gone(kind,slug,at) VALUES('evenement',?,?)")->execute([$r['slug'], $GLOBALS['stamp']]); $db->prepare('DELETE FROM themes WHERE id=?')->execute([$r['id']]); }
    $db->prepare("DELETE FROM items WHERE kind='evenement' AND d2<?")->execute([$old]);
    $db->prepare("DELETE FROM gone WHERE at<?")->execute([date('Y-m-d', strtotime('-400 days'))]);
    $db->prepare("UPDATE communes SET n_ev=(SELECT COUNT(*) FROM items i WHERE i.insee=communes.insee AND i.kind='evenement' AND i.page=1 AND i.d2>=?)")->execute([$today]);
    $db->exec('ANALYZE');
    $site = site_by_host(NOR_HOST);
    if ($site) {
        cache_clear(NOR_HOST);
        @unlink(cfg('data_dir') . '/cache/' . NOR_HOST . '.sitemap-index.xml');
        if ($ping) {
            require_once dirname(__DIR__) . '/app/feeds.php';
            $urls = nor_all_urls();
            foreach (array_chunk($urls, 9000) as $ch) { indexnow_ping($site, $ch); sleep(2); }
            say('IndexNow : ' . count($urls) . ' URL envoyées');
        }
    }
    $s = $db->query('SELECT kind, COUNT(*) n, SUM(page) p, SUM(length(txt)>=' . NOR_TXT_MIN . ') t FROM items GROUP BY kind')->fetchAll();
    say('Bilan : ' . json_encode($s, JSON_UNESCAPED_UNICODE));
}

load_communes($db);
foreach ($steps as $s) {
    $t = microtime(true);
    try {
        match ($s) {
            'communes' => (function () use ($db) { step_communes($db); load_communes($db); })(),
            'dt' => step_dt($db, $dir, $today),
            'festivals' => step_festivals($db, $dir),
            'finish' => step_finish($db, $today, $ping),
            default => say("étape inconnue : $s"),
        };
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        say("ERREUR $s : " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
    say("$s terminé en " . round(microtime(true) - $t, 1) . ' s');
}
