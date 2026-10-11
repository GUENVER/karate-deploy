<?php
// Synchronisation des données open data de bretagne.decouverte.org (module bzh).
// Usage : php cli/bzh_sync.php [communes|dt|merimee|inventaire|baignade|festivals|ferries|all] [--indexnow]
// Sources : geo.api.gouv.fr, DATAtourisme (extrait régional Bretagne), Mérimée, Inventaire du patrimoine (Région Bretagne),
// qualité des eaux de baignade, liste des festivals (ministère de la Culture), GTFS BreizhGo Bateaux (Korrigo).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/bzh.php';
if (function_exists('proc_nice')) @proc_nice(19);
ini_set('memory_limit', '1024M');
set_time_limit(0);

$lock = fopen(cfg('data_dir') . '/bzh_sync.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("déjà en cours\n");

$steps = array_values(array_filter(array_slice($argv, 1), fn($a) => $a[0] !== '-')) ?: ['all'];
if ($steps === ['all']) $steps = ['communes', 'breton', 'dt', 'merimee', 'inventaire', 'baignade', 'festivals', 'ferries', 'finish'];
$ping = in_array('--indexnow', $argv, true);
$dir = cfg('data_dir') . '/bzh';
@mkdir($dir, 0750, true);
$db = bzh_db();
$stamp = gmdate('Y-m-d H:i:s');
$today = date('Y-m-d');

function say(string $m): void { echo date('H:i:s') . ' ' . $m . "\n"; }

function fetch_to(string $url, string $file, int $timeout = 600): bool
{
    $fp = fopen($file . '.part', 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'decouverte.org/bzh-sync (+https://bretagne.decouverte.org/)', CURLOPT_FAILONERROR => true]);
    $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fp);
    if (!$ok || $code >= 400 || filesize($file . '.part') < 100) { @unlink($file . '.part'); return false; }
    rename($file . '.part', $file);
    return true;
}

// URL courante d'une ressource data.gouv (les URL changent à chaque publication).
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
        $BYNAME[bzh_norm($c['nom'])][] = $c['insee'];
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
    return ($among === null && $bd > 0.04) ? null : $best; // hors Bretagne (> ~20 km du centre le plus proche)
}
function find_commune(string $insee = '', string $cp = '', string $name = '', ?float $lat = null, ?float $lon = null): ?string
{
    global $COM, $BYNAME, $BYCP;
    if ($insee !== '' && isset($COM[$insee])) return $insee;
    $n = bzh_norm($name);
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
    foreach (BZH_DEPTS as $code => [$dn, $ds]) {
        $j = json_decode((string)file_get_contents("https://geo.api.gouv.fr/communes?codeDepartement=$code&fields=nom,code,codesPostaux,population,centre,surface,epci&format=json"), true);
        if (!$j) throw new RuntimeException("geo.api : rien pour $code");
        $seen = [];
        foreach ($j as $c) {
            $slug = slugify($c['nom']);
            if (isset($seen[$slug])) $slug .= '-' . $c['code'];
            $seen[$slug] = 1;
            $db->prepare('INSERT INTO communes(insee,nom,slug,dept,cps,pop,lat,lon,surface,epci) VALUES(?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(insee) DO UPDATE SET nom=excluded.nom, slug=excluded.slug, dept=excluded.dept, cps=excluded.cps, pop=excluded.pop, lat=excluded.lat, lon=excluded.lon, surface=excluded.surface, epci=excluded.epci')
                ->execute([$c['code'], $c['nom'], $slug, $code, implode(',', $c['codesPostaux'] ?? []), (int)($c['population'] ?? 0),
                    $c['centre']['coordinates'][1] ?? null, $c['centre']['coordinates'][0] ?? null, round(((float)($c['surface'] ?? 0)) / 100, 2), $c['epci']['nom'] ?? '']);
            $n++;
        }
    }
    $db->commit();
    say("communes : $n");
}

// ---------- Stockage des fiches ----------
function item_put(PDO $db, array $it): void
{
    static $slugs = [];
    $k = $it['kind'];
    if (!isset($slugs[$k])) {
        $slugs[$k] = [];
        $st = $db->prepare('SELECT id, slug FROM items WHERE kind=?'); $st->execute([$k]);
        foreach ($st as $r) $slugs[$k][$r['slug']] = $r['id'];
    }
    $old = $db->prepare('SELECT slug FROM items WHERE id=?'); $old->execute([$it['id']]);
    $slug = $old->fetchColumn();
    if (!$slug) {
        $base = slugify($it['name'] . (!empty($it['city']) ? ' ' . $it['city'] : ''), 90);
        $slug = $base; $i = 2;
        while (isset($slugs[$k][$slug]) && $slugs[$k][$slug] !== $it['id']) $slug = $base . '-' . $i++;
        $slugs[$k][$slug] = $it['id'];
    }
    $db->prepare('INSERT INTO items(id,kind,sub,slug,name,insee,lat,lon,d1,d2,rank,page,data,seen,created) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET kind=excluded.kind, sub=excluded.sub, name=excluded.name, insee=excluded.insee, lat=excluded.lat, lon=excluded.lon,
        d1=excluded.d1, d2=excluded.d2, rank=excluded.rank, page=excluded.page, data=excluded.data, seen=excluded.seen')
        ->execute([$it['id'], $k, $it['sub'] ?? '', $slug, mb_substr($it['name'], 0, 200), $it['insee'], $it['lat'] ?: null, $it['lon'] ?: null,
            $it['d1'] ?? null, $it['d2'] ?? null, (int)($it['rank'] ?? 0), (int)($it['page'] ?? 0),
            json_encode($it['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $GLOBALS['stamp'], $GLOBALS['stamp']]);
}

function purge_unseen(PDO $db, array $kinds, string $source): int
{
    $in = implode(',', array_map(fn($k) => $db->quote($k), $kinds));
    $st = $db->prepare("SELECT kind, slug FROM items WHERE kind IN ($in) AND json_extract(data,'$.src')=? AND seen<?");
    $st->execute([$source, $GLOBALS['stamp']]);
    $n = 0;
    foreach ($st->fetchAll() as $r) { $db->prepare('INSERT OR REPLACE INTO gone(kind,slug,at) VALUES(?,?,?)')->execute([$r['kind'], $r['slug'], $GLOBALS['stamp']]); $n++; }
    $db->prepare("DELETE FROM items WHERE kind IN ($in) AND json_extract(data,'$.src')=? AND seen<?")->execute([$source, $GLOBALS['stamp']]);
    return $n;
}

// ---------- DATAtourisme (extrait Bretagne) ----------
function step_dt(PDO $db, string $dir, string $today): void
{
    $url = dgouv_resource('5b598be088ee387c0c353714', '/^datatourisme-reg-bre\.csv$/');
    if (!$url || !fetch_to($url, "$dir/dt.csv")) { if (!is_file("$dir/dt.csv")) throw new RuntimeException('DATAtourisme indisponible'); say('DATAtourisme : téléchargement échoué, fichier précédent utilisé'); }
    $c = ['lieu' => 0, 'rando' => 0, 'evenement' => 0, 'hebergement' => 0, 'restaurant' => 0, 'office' => 0, 'hors' => 0, 'passe' => 0];
    $db->beginTransaction(); $i = 0;
    foreach (csv_rows("$dir/dt.csv", ',') as $r) {
        $cats = array_map(fn($u) => preg_replace('~^.*[#/]~', '', $u), explode('|', $r['Categories_de_POI']));
        [$cp, $city] = array_pad(explode('#', $r['Code_postal_et_commune'], 2), 2, '');
        $lat = (float)$r['Latitude']; $lon = (float)$r['Longitude'];
        $insee = find_commune('', $cp, $city, $lat ?: null, $lon ?: null);
        if (!$insee) { $c['hors']++; continue; }
        [$kind, $sub] = bzh_dt_kind($cats);
        $data = ['src' => 'dt', 'cats' => array_values(array_unique($cats)), 'adresse' => trim($r['Adresse_postale']), 'cp' => $cp, 'ville' => $city,
            'maj' => $r['Date_de_mise_a_jour'], 'auteur' => $r['Createur_de_la_donnee'], 'uri' => $r['URI_ID_du_POI']];
        $links = [];
        foreach (explode('|', $r['Contacts_du_POI']) as $ct) {
            [$lab, $u] = array_pad(explode('#', $ct, 2), 2, '');
            if (preg_match('~^https?://~', $u) && !in_array($u, $links, true)) $links[] = $u;
        }
        if ($links) $data['web'] = array_slice($links, 0, 2);
        if (trim($r['Classements_du_POI']) !== '') $data['classements'] = array_values(array_unique(array_filter(array_map(fn($x) => trim(explode('#', $x)[0]), explode('|', $r['Classements_du_POI'])))));
        $it = ['id' => 'dt:' . basename($r['URI_ID_du_POI']), 'kind' => $kind, 'sub' => $sub, 'name' => trim($r['Nom_du_POI']), 'city' => $city, 'insee' => $insee, 'lat' => $lat, 'lon' => $lon, 'data' => $data, 'rank' => 0];
        if ($kind === 'evenement') {
            $periods = [];
            foreach (explode('|', $r['Periodes_regroupees']) as $p) {
                [$a, $b] = array_pad(explode('<->', $p), 2, '');
                $a = substr($a, 0, 10); $b = substr($b ?: $a, 0, 10);
                if (preg_match('/^\d{4}-\d\d-\d\d$/', $a) && $b >= $today) $periods[] = [$a, $b];
            }
            if (!$periods) { $c['passe']++; continue; }
            usort($periods, fn($x, $y) => strcmp($x[0], $y[0]));
            $periods = array_values(array_unique($periods, SORT_REGULAR));
            $it['d1'] = max($periods[0][0], $today) === $periods[0][0] ? $periods[0][0] : $periods[0][0];
            $it['d2'] = $periods[0][1];
            $it['data']['periodes'] = array_slice($periods, 0, 30);
            $it['page'] = 1;
            $it['rank'] = 10 + (in_array('Festival', $cats, true) ? 20 : 0) + (in_array('Concert', $cats, true) ? 5 : 0);
        } elseif ($kind === 'lieu' || $kind === 'rando') {
            $it['page'] = 1;
            $it['rank'] = 20 + (!empty($data['web']) ? 10 : 0) + (!empty($data['classements']) ? 10 : 0) + (in_array($sub, ['Musée', 'Site naturel', 'Château', 'Parc et jardin', 'Phare'], true) ? 15 : 0);
        } else {
            $it['rank'] = !empty($data['classements']) ? 20 : 0;
        }
        item_put($db, $it);
        $c[$kind] = ($c[$kind] ?? 0) + 1;
        if (++$i % 2000 === 0) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    $c['retires'] = purge_unseen($db, ['lieu', 'rando', 'evenement', 'hebergement', 'restaurant', 'office', 'activite'], 'dt');
    say('DATAtourisme : ' . json_encode($c, JSON_UNESCAPED_UNICODE));
}

// ---------- Mérimée (monuments historiques) ----------
function step_merimee(PDO $db, string $dir): void
{
    $url = dgouv_resource('5af120e5b595087cfabcde81', '/\.csv$/i') ?: 'https://ministere-culture.s3.sbg.io.cloud.ovh.net/POP/merimee.csv';
    if (!fetch_to($url, "$dir/merimee.csv", 1200) && !is_file("$dir/merimee.csv")) throw new RuntimeException('Mérimée indisponible');
    $n = 0; $db->beginTransaction();
    foreach (csv_rows("$dir/merimee.csv", '|') as $r) {
        if (!isset(BZH_DEPTS[$r['Departement_format_numerique'] ?? ''])) continue;
        [$lat, $lon] = array_map('floatval', array_pad(explode(',', (string)$r['coordonnees_au_format_WGS84']), 2, 0));
        $insee = find_commune(trim((string)$r['COG_Insee_lors_de_la_protection']), '', (string)$r['Commune_forme_editoriale'], $lat ?: null, $lon ?: null);
        if (!$insee) continue;
        $name = trim($r['Titre_editorial_de_la_notice'] ?: bzh_ucfirst((string)$r['Denomination_de_l_edifice']));
        $adr = trim((string)($r['Adresse_forme_editoriale'] ?: $r['Lieudit']));
        if (preg_match('/^(immeuble|maison|vieille maison|maisons|hôtel|ensemble|édifice|croix|calvaire|menhir|dolmen|allée couverte|tumulus|stèle|fontaine|manoir|chapelle|église|place)s?$/iu', $name)) $name .= $adr !== '' ? ', ' . $adr : ' ' . bzh_de((string)$r['Commune_forme_editoriale']);
        $hist = bzh_clean($r['Historique']); $desc = bzh_clean($r['Description_de_l_edifice']);
        $prot = (string)$r['Typologie_de_la_protection'];
        $data = array_filter(['src' => 'merimee', 'ref' => $r['Reference'], 'denomination' => $r['Denomination_de_l_edifice'], 'siecle' => str_replace(';', ', ', (string)$r['Siecle_de_la_campagne_principale_de_construction']),
            'datation' => $r['Datation_de_l_edifice'], 'protection' => $r['Date_et_typologie_de_la_protection'], 'type_protection' => $prot, 'statut' => $r['Statut_juridique_de_l_edifice'],
            'materiaux' => $r['Materiaux_du_gros_oeuvre'], 'couverture' => $r['Materiaux_de_la_couverture'], 'auteur' => $r['Auteur_de_l_edifice'], 'adresse' => $r['Adresse_forme_editoriale'],
            'lieudit' => $r['Lieudit'], 'domaine' => $r['Domaine'], 'vocable' => $r['Vocable___pour_les_edifices_cultuels'], 'historique' => mb_substr($hist, 0, 6000), 'description' => mb_substr($desc, 0, 6000),
            'remarquable' => $r['Elements_remarquables_dans_l_edifice'], 'maj' => $r['Date_de_la_derniere_mise_a_jour']]);
        $rich = mb_strlen($hist . $desc);
        item_put($db, ['id' => 'mh:' . $r['Reference'], 'kind' => 'monument', 'sub' => bzh_ucfirst((string)$r['Denomination_de_l_edifice']), 'name' => $name, 'city' => $r['Commune_forme_editoriale'],
            'insee' => $insee, 'lat' => $lat, 'lon' => $lon, 'data' => $data, 'page' => ($rich >= 120 || ($lat && $r['Denomination_de_l_edifice'])) ? 1 : 0,
            'rank' => 30 + (str_contains($prot, 'classé') ? 25 : 0) + min(30, intdiv($rich, 150))]);
        if (++$n % 1000 === 0) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    say("Mérimée : $n monuments, retirés " . purge_unseen($db, ['monument'], 'merimee'));
}

// ---------- Inventaire du patrimoine (Région Bretagne) ----------
function step_inventaire(PDO $db, string $dir): void
{
    $B = 'https://data.bretagne.bzh/api/explore/v2.1/catalog/datasets/inventaire-du-patrimoine-breton-couche-simplifiee/exports/csv';
    $q = http_build_query(['select' => 'reference,titre_courant,denomination,commune,lieudit,datation_principale,commentaire_historique,commentaire_descriptif,materiaux_murs,statut,selection,domaine,lien_dossier,geo_point_2d',
        'where' => 'commentaire_historique is not null or commentaire_descriptif is not null', 'delimiter' => '|']);
    if (!fetch_to("$B?$q", "$dir/inventaire.csv", 1200) && !is_file("$dir/inventaire.csv")) throw new RuntimeException('Inventaire indisponible');
    // monuments déjà publiés : on évite les doublons (même commune, nom proche)
    $mh = [];
    foreach ($db->query("SELECT insee, name FROM items WHERE kind='monument'") as $m) $mh[$m['insee']][] = bzh_norm($m['name']);
    $n = 0; $p = 0; $db->beginTransaction();
    foreach (csv_rows("$dir/inventaire.csv", '|') as $r) {
        [$lat, $lon] = array_map('floatval', array_pad(explode(',', (string)$r['geo_point_2d']), 2, 0));
        $insee = find_commune('', '', (string)$r['commune'], $lat ?: null, $lon ?: null);
        if (!$insee) continue;
        $hist = bzh_clean($r['commentaire_historique']); $desc = bzh_clean($r['commentaire_descriptif']);
        $name = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string)$r['titre_courant'])) ?: bzh_ucfirst((string)$r['denomination']);
        $prive = str_contains(mb_strtolower((string)$r['statut']), 'privée');
        $sel = mb_strtolower((string)$r['selection']);
        $dup = false; $nn = bzh_norm($name);
        foreach ($mh[$insee] ?? [] as $x) { similar_text($nn, $x, $pc); if ($pc > 75) { $dup = true; break; } }
        $rich = mb_strlen($hist . $desc);
        $page = !$dup && $rich >= 260 && (!$prive || in_array($sel, ['sélection requise', 'étudié'], true)) ? 1 : 0;
        $den = trim(explode(';', (string)$r['denomination'])[0]);
        item_put($db, ['id' => 'inv:' . $r['reference'], 'kind' => 'patrimoine', 'sub' => bzh_ucfirst($den), 'name' => $name, 'city' => $r['commune'], 'insee' => $insee, 'lat' => $lat, 'lon' => $lon,
            'page' => $page, 'rank' => ($sel === 'sélection requise' ? 30 : ($sel === 'étudié' ? 25 : 0)) + min(30, intdiv($rich, 120)) + ($prive ? 0 : 10) + (stripos((string)$r['domaine'], 'ARCHITECTURE') !== false ? 20 : 0),
            'data' => array_filter(['src' => 'inventaire', 'ref' => $r['reference'], 'denomination' => $r['denomination'], 'lieudit' => $r['lieudit'], 'datation' => $r['datation_principale'],
                'historique' => mb_substr($hist, 0, 5000), 'description' => mb_substr($desc, 0, 5000), 'materiaux' => $r['materiaux_murs'], 'statut' => $r['statut'], 'selection' => $r['selection'],
                'domaine' => $r['domaine'], 'dossier' => $r['lien_dossier']])]);
        $n++; $p += $page;
        if ($n % 2000 === 0) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    say("Inventaire : $n éléments, $p pages, retirés " . purge_unseen($db, ['patrimoine'], 'inventaire'));
}

// ---------- Baignade ----------
function step_baignade(PDO $db, string $dir): void
{
    $url = dgouv_resource('qualite-des-sites-de-baignade-1', '/\.csv$/i');
    if ((!$url || !fetch_to($url, "$dir/baignade.csv")) && !is_file("$dir/baignade.csv")) throw new RuntimeException('Baignade indisponible');
    $n = 0; $db->beginTransaction();
    foreach (csv_rows("$dir/baignade.csv", ';') as $r) {
        if (!isset(BZH_DEPTS[$r['departement'] ?? ''])) continue;
        $insee = find_commune((string)$r['code_geographique'], '', (string)$r['libelle_geographique'], (float)$r['latitude'] ?: null, (float)$r['longitude'] ?: null);
        if (!$insee) continue;
        $name = bzh_title((string)$r['nom_site']);
        $mer = $r['type_eau_norm'] === 'COTIERE';
        $q = (string)$r['qualite'];
        item_put($db, ['id' => 'bg:' . $r['code_geographique'] . ':' . slugify($r['nom_site']), 'kind' => 'plage', 'sub' => $mer ? 'Plage' : 'Baignade en eau douce', 'name' => $name, 'city' => $r['libelle_geographique'],
            'insee' => $insee, 'lat' => (float)$r['latitude'], 'lon' => (float)$r['longitude'], 'page' => 1,
            'rank' => ['Excellent' => 40, 'Bon' => 30, 'Suffisant' => 15, 'Insuffisant' => 0][$q] ?? 5,
            'data' => ['src' => 'baignade', 'qualite' => $q, 'eau' => $mer ? 'mer' : 'eau douce', 'nom_officiel' => $r['nom_site'], 'statut' => $r['statut_donnees'], 'epci' => $r['libelle_epci']]]);
        $n++;
    }
    $db->commit();
    say("Baignade : $n sites, retirés " . purge_unseen($db, ['plage'], 'baignade'));
}

// ---------- Festivals ----------
function step_festivals(PDO $db, string $dir): void
{
    $url = dgouv_resource('liste-des-festivals-en-france', '/festivals-global/i');
    if ((!$url || !fetch_to($url, "$dir/festivals.csv")) && !is_file("$dir/festivals.csv")) throw new RuntimeException('Festivals indisponible');
    $n = 0; $db->beginTransaction();
    foreach (csv_rows("$dir/festivals.csv", ';') as $r) {
        $nom = trim((string)($r['Nom du festival'] ?? ''));
        if ($nom === '' || ($r['Région principale de déroulement'] ?? '') !== 'Bretagne') continue;
        [$lat, $lon] = array_map('floatval', array_pad(explode(',', (string)$r['Géocodage xy']), 2, 0));
        $insee = find_commune((string)$r['Code Insee commune'], (string)$r['Code postal (de la commune principale de déroulement)'], (string)$r['Commune principale de déroulement'], $lat ?: null, $lon ?: null);
        if (!$insee) continue;
        $sous = implode(', ', array_filter(array_map('trim', [$r['Sous-catégorie spectacle vivant'], $r['Sous-catégorie musique'], $r['Sous-catégorie cinéma et audiovisuel'], $r['Sous-catégorie arts visuels et arts numériques'], $r['Sous-catégorie livre et littérature']])));
        $web = trim((string)$r['Site internet du festival']);
        if ($web !== '' && !preg_match('~^https?://~', $web)) $web = 'https://' . $web;
        item_put($db, ['id' => 'fest:' . ($r['Identifiant'] ?: slugify($nom . $r['Code Insee commune'])), 'kind' => 'festival', 'sub' => (string)$r['Discipline dominante'], 'name' => $nom, 'city' => $r['Commune principale de déroulement'],
            'insee' => $insee, 'lat' => $lat, 'lon' => $lon, 'page' => 1, 'rank' => 20 + ($web ? 10 : 0) + ((int)$r['Année de création du festival'] && (int)$r['Année de création du festival'] < 2000 ? 15 : 0),
            'data' => array_filter(['src' => 'festivals', 'discipline' => $r['Discipline dominante'], 'genres' => $sous, 'periode' => $r['Période principale de déroulement du festival'], 'creation' => $r['Année de création du festival'],
                'decennie' => $r['Décennie de création du festival'], 'web' => $web ? [$web] : null, 'adresse' => trim($r['Adresse postale'] . ' ' . $r["Complément d'adresse (facultatif)"]), 'envergure' => $r['Envergure territoriale'], 'epci' => $r['Libellé EPCI']])]);
        $n++;
    }
    $db->commit();
    say("Festivals : $n, retirés " . purge_unseen($db, ['festival'], 'festivals'));
}

// ---------- Horaires des bateaux vers les îles (GTFS BreizhGo) ----------
function step_ferries(PDO $db, string $dir, string $today): void
{
    $db->exec('DELETE FROM ferries');
    $tot = 0;
    foreach (['56', 'ARZ', 'BREHAT', '29'] as $f) {
        $zip = "$dir/gtfs_$f.zip"; $x = "$dir/gtfs_$f";
        if (!fetch_to("https://www.korrigo.bzh/ftp/OPENDATA/BREIZHGO_BATEAU_$f.gtfs.zip", $zip, 120) && !is_file($zip)) { say("GTFS $f indisponible"); continue; }
        @mkdir($x, 0750, true);
        $za = new ZipArchive(); if ($za->open($zip) !== true) continue; $za->extractTo($x); $za->close();
        $read = function (string $t) use ($x) { $o = []; if (is_file("$x/$t")) foreach (csv_rows("$x/$t", ',') as $r) $o[] = $r; return $o; };
        $routes = []; foreach ($read('routes.txt') as $r) $routes[$r['route_id']] = $r['route_long_name'] ?: $r['route_short_name'];
        $stops = []; foreach ($read('stops.txt') as $r) $stops[$r['stop_id']] = bzh_stop_name($r['stop_name']);
        $cal = []; foreach ($read('calendar.txt') as $r) $cal[$r['service_id']] = $r;
        $exc = []; foreach ($read('calendar_dates.txt') as $r) $exc[$r['service_id']][$r['date']] = (int)$r['exception_type'];
        $first = []; $last = [];
        foreach ($read('stop_times.txt') as $r) {
            $t = $r['trip_id']; $s = (int)$r['stop_sequence'];
            if (!isset($first[$t]) || $s < $first[$t][0]) $first[$t] = [$s, $r['stop_id'], $r['departure_time']];
            if (!isset($last[$t]) || $s > $last[$t][0]) $last[$t] = [$s, $r['stop_id'], $r['arrival_time']];
        }
        $ins = $db->prepare('INSERT INTO ferries(ile, route, day, dep, arr, origin, dest) VALUES(?,?,?,?,?,?,?)');
        $db->beginTransaction();
        foreach ($read('trips.txt') as $tr) {
            $t = $tr['trip_id']; if (!isset($first[$t], $last[$t])) continue;
            $rn = $routes[$tr['route_id']] ?? '';
            $o = $stops[$first[$t][1]] ?? ''; $d = $stops[$last[$t][1]] ?? '';
            $iles = bzh_route_islands($rn . ' ' . $o . ' ' . $d);
            if (!$iles) continue;
            for ($k = 0; $k < 31; $k++) {
                $day = date('Ymd', strtotime("$today +$k day"));
                $sid = $tr['service_id']; $on = false;
                if (isset($cal[$sid])) { $c = $cal[$sid]; $wd = strtolower(date('l', strtotime($day))); $on = $day >= $c['start_date'] && $day <= $c['end_date'] && $c[$wd] === '1'; }
                if (isset($exc[$sid][$day])) $on = $exc[$sid][$day] === 1;
                if (!$on) continue;
                foreach ($iles as $ile) { $ins->execute([$ile, $rn, date('Y-m-d', strtotime($day)), substr($first[$t][2], 0, 5), substr($last[$t][2], 0, 5), $o, $d]); $tot++; }
            }
        }
        $db->commit();
    }
    say("Bateaux : $tot traversées sur 31 jours");
}

// ---------- Noms bretons des communes (Wikidata) ----------
function step_breton(PDO $db): void
{
    $q = 'SELECT ?insee ?br WHERE { ?c wdt:P374 ?insee . FILTER(REGEX(?insee, "^(22|29|35|56)")) ?c rdfs:label ?br . FILTER(LANG(?br)="br") }';
    $ch = curl_init('https://query.wikidata.org/sparql?query=' . rawurlencode($q));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => ['Accept: text/csv'], CURLOPT_USERAGENT => 'decouverte.org-bzh/1.0 (contact@guenver.com)']);
    $csv = (string)curl_exec($ch); curl_close($ch);
    $n = 0; $seen = [];
    foreach (array_slice(preg_split('/\r?\n/', trim($csv)), 1) as $l) {
        [$i, $br] = array_pad(str_getcsv($l, ',', '"', ''), 2, '');
        if ($i === '' || $br === '' || isset($seen[$i])) continue;
        $seen[$i] = 1;
        $st = $db->prepare('UPDATE communes SET nom_br=? WHERE insee=? AND nom<>?'); $st->execute([$br, $i, $br]); $n += $st->rowCount();
    }
    say("Noms bretons : $n");
}

// ---------- Fin : nettoyage, cache, IndexNow ----------
function step_finish(PDO $db, string $today, bool $ping): void
{
    // événements terminés : conservés 30 jours (page en noindex), puis 410
    $old = date('Y-m-d', strtotime("$today -30 day"));
    $st = $db->prepare("SELECT kind, slug FROM items WHERE kind='evenement' AND d2<?"); $st->execute([$old]);
    foreach ($st->fetchAll() as $r) $db->prepare('INSERT OR REPLACE INTO gone(kind,slug,at) VALUES(?,?,?)')->execute([$r['kind'], $r['slug'], gmdate('Y-m-d H:i:s')]);
    $db->prepare("DELETE FROM items WHERE kind='evenement' AND d2<?")->execute([$old]);
    // compteurs par commune (accélère les pages hubs)
    $db->exec("UPDATE communes SET n_items=(SELECT COUNT(*) FROM items i WHERE i.insee=communes.insee)");
    $db->exec('ANALYZE');
    $site = site_by_host(BZH_HOST);
    if ($site) {
        // pas de purge globale (chaque page purgée serait régénérée par un visiteur) : cli/bzh_warm.php régénère tout ensuite
        @unlink(cfg('data_dir') . '/cache/' . BZH_HOST . '.sitemap-index.xml');
        if ($ping) {
            require_once dirname(__DIR__) . '/app/feeds.php';
            $urls = bzh_all_urls($site);
            foreach (array_chunk($urls, 9000) as $ch) { indexnow_ping($site, $ch); sleep(2); }
            say('IndexNow : ' . count($urls) . ' URL envoyées');
        }
    }
    $s = $db->query('SELECT kind, COUNT(*) n, SUM(page) p FROM items GROUP BY kind')->fetchAll();
    say('Bilan : ' . json_encode($s, JSON_UNESCAPED_UNICODE));
}

load_communes($db);
foreach ($steps as $s) {
    $t = microtime(true);
    try {
        match ($s) {
            'communes' => (function () use ($db) { step_communes($db); load_communes($db); })(),
            'dt' => step_dt($db, $dir, $today),
            'merimee' => step_merimee($db, $dir),
            'inventaire' => step_inventaire($db, $dir),
            'baignade' => step_baignade($db, $dir),
            'festivals' => step_festivals($db, $dir),
            'ferries' => step_ferries($db, $dir, $today),
            'breton' => step_breton($db),
            'finish' => step_finish($db, $today, $ping),
            default => say("étape inconnue : $s"),
        };
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        say("ERREUR $s : " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
    say("$s terminé en " . round(microtime(true) - $t, 1) . ' s');
}
