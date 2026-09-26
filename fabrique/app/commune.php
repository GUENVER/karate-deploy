<?php
// Fiches communes (données publiques) : population et géographie (geo.api.gouv.fr), prix immobiliers DVF (Etalab),
// risques majeurs et arrêtés de catastrophe naturelle (GASPAR / Géorisques), performance énergétique (DPE ADEME,
// lue dans la base du site qui a le module DPE). Pages : /commune/, /commune/{département}/, /commune/{département}/{commune}/.
// Dépend de fuel.php (DEPTS, dept_slug, dept_by_slug, sparkline) et dpe.php.
declare(strict_types=1);

const COMMUNE_SRC = [
    'geo' => 'https://geo.api.gouv.fr/communes?fields=nom,code,codesPostaux,population,surface,centre,codeDepartement&format=json',
    'dvf' => 'https://data-pipeline-open.s3.sbg.io.cloud.ovh.net/dvf/stats_whole_period.csv',
    'dvf_mois' => 'https://data-pipeline-open.s3.sbg.io.cloud.ovh.net/dvf/stats_dvf.csv',
    'gaspar' => 'https://files.georisques.fr/GASPAR/gaspar.zip',
];

function commune_db(string $host): PDO
{
    $db = site_db($host);
    static $init = [];
    if (empty($init[$host])) {
        $db->exec("CREATE TABLE IF NOT EXISTS communes(insee TEXT PRIMARY KEY, name TEXT, slug TEXT, dept TEXT, cp TEXT, pop INTEGER, surface REAL, lat REAL, lon REAL);
        CREATE INDEX IF NOT EXISTS ix_com_dept ON communes(dept, slug);
        CREATE TABLE IF NOT EXISTS dvf(insee TEXT PRIMARY KEY, n_apt INTEGER, med_apt INTEGER, n_mai INTEGER, med_mai INTEGER, n_all INTEGER, med_all INTEGER);
        CREATE TABLE IF NOT EXISTS dvf_year(insee TEXT, year INTEGER, n INTEGER, moy INTEGER, PRIMARY KEY(insee, year));
        CREATE TABLE IF NOT EXISTS risques(insee TEXT, risque TEXT, PRIMARY KEY(insee, risque));
        CREATE TABLE IF NOT EXISTS catnat(insee TEXT PRIMARY KEY, n INTEGER, events TEXT);
        CREATE TABLE IF NOT EXISTS commune_extra(insee TEXT, kind TEXT, data TEXT, PRIMARY KEY(insee, kind));");
        $init[$host] = 1;
    }
    return $db;
}

function commune_dl(string $url, string $file, int $min = 1000): string
{
    $fh = fopen($file, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 900, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (fabrique)']);
    $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch); fclose($fh);
    if (!$ok || $code !== 200 || filesize($file) < $min) throw new RuntimeException("téléchargement $url : HTTP $code");
    return $file;
}

function commune_sync(array $site, callable $log): array
{
    $db = commune_db($site['host']);
    $dir = cfg('data_dir');
    $out = [];
    // 1. Communes
    $list = json_decode((string)file_get_contents(commune_dl(COMMUNE_SRC['geo'], "$dir/communes.json")), true) ?: [];
    if (count($list) > 30000) {
        $db->beginTransaction();
        $db->exec('DELETE FROM communes');
        $st = $db->prepare('INSERT OR REPLACE INTO communes VALUES(?,?,?,?,?,?,?,?,?)');
        foreach ($list as $c) $st->execute([$c['code'], $c['nom'], slugify($c['nom'], 70), $c['codeDepartement'], $c['codesPostaux'][0] ?? '', (int)($c['population'] ?? 0),
            round((float)($c['surface'] ?? 0) / 100, 2), $c['centre']['coordinates'][1] ?? null, $c['centre']['coordinates'][0] ?? null]);
        $db->commit();
    }
    @unlink("$dir/communes.json");
    $out['communes'] = count($list); $log('communes ' . count($list));
    // 2. Prix immobiliers, toute la période
    $in = fopen(commune_dl(COMMUNE_SRC['dvf'], "$dir/dvf.csv"), 'r');
    $ix = array_flip(fgetcsv($in, 0, ',', '"', ''));
    $db->beginTransaction(); $db->exec('DELETE FROM dvf');
    $st = $db->prepare('INSERT OR REPLACE INTO dvf VALUES(?,?,?,?,?,?,?)'); $n = 0; $arr = [];
    while (($r = fgetcsv($in, 0, ',', '"', '')) !== false) {
        if ($r[$ix['echelle_geo']] !== 'commune') continue;
        $v = fn($k) => $r[$ix[$k]] === '' ? null : (int)$r[$ix[$k]];
        $row = [$r[$ix['code_geo']], $v('nb_ventes_whole_appartement'), $v('med_prix_m2_whole_appartement'), $v('nb_ventes_whole_maison'), $v('med_prix_m2_whole_maison'), $v('nb_ventes_whole_apt_maison'), $v('med_prix_m2_whole_apt_maison')];
        $st->execute($row);
        if ($city = commune_parent($row[0])) $arr[$city][] = $row;
        $n++;
    }
    // Paris, Lyon, Marseille : DVF est publié par arrondissement → moyenne pondérée des médianes pour la ville
    foreach ($arr as $city => $rows) {
        $w = function (int $ni, int $mi) use ($rows) { $s = 0; $t = 0; foreach ($rows as $r) if ($r[$ni]) { $s += $r[$ni] * $r[$mi]; $t += $r[$ni]; } return [$t ?: null, $t ? (int)round($s / $t) : null]; };
        $st->execute(array_merge([$city], $w(1, 2), $w(3, 4), $w(5, 6)));
    }
    $db->commit(); fclose($in); @unlink("$dir/dvf.csv");
    $out['dvf'] = $n; $log("dvf $n");
    // 3. Évolution annuelle (moyenne pondérée des statistiques mensuelles)
    $in = fopen(commune_dl(COMMUNE_SRC['dvf_mois'], "$dir/dvf_mois.csv"), 'r');
    $ix = array_flip(fgetcsv($in, 0, ',', '"', ''));
    $acc = []; $par = []; $cur = null;
    $db->beginTransaction(); $db->exec('DELETE FROM dvf_year');
    $st = $db->prepare('INSERT OR REPLACE INTO dvf_year VALUES(?,?,?,?)');
    $flush = function () use (&$acc, $st) { foreach ($acc as $k => [$nb, $sum]) { [$c, $y] = explode('|', $k); if ($nb) $st->execute([$c, (int)$y, $nb, (int)round($sum / $nb)]); } $acc = []; };
    $i = 0;
    while (($r = fgetcsv($in, 0, ',', '"', '')) !== false) {
        if ($r[$ix['echelle_geo']] !== 'commune' || $r[$ix['nb_ventes_apt_maison']] === '') continue;
        $code = $r[$ix['code_geo']];
        if ($p = commune_parent($code)) { $k = $p . '|' . substr($r[$ix['annee_mois']], 0, 4); $nb = (int)$r[$ix['nb_ventes_apt_maison']]; $par[$k][0] = ($par[$k][0] ?? 0) + $nb; $par[$k][1] = ($par[$k][1] ?? 0) + $nb * (float)$r[$ix['moy_prix_m2_apt_maison']]; }
        if ($code !== $cur && count($acc) > 5000) $flush();
        $cur = $code;
        $k = $code . '|' . substr($r[$ix['annee_mois']], 0, 4);
        $nb = (int)$r[$ix['nb_ventes_apt_maison']];
        $acc[$k][0] = ($acc[$k][0] ?? 0) + $nb;
        $acc[$k][1] = ($acc[$k][1] ?? 0) + $nb * (float)$r[$ix['moy_prix_m2_apt_maison']];
        if (++$i % 200000 === 0) usleep(200000);
    }
    $flush(); $acc = $par; $flush(); $db->commit(); fclose($in); @unlink("$dir/dvf_mois.csv");
    $out['dvf_annees'] = (int)$db->query('SELECT COUNT(*) FROM dvf_year')->fetchColumn(); $log('dvf_year ok');
    // 4. Risques majeurs et catastrophes naturelles
    $zip = new ZipArchive();
    if ($zip->open(commune_dl(COMMUNE_SRC['gaspar'], "$dir/gaspar.zip")) === true) {
        $db->beginTransaction(); $db->exec('DELETE FROM risques'); $db->exec('DELETE FROM catnat');
        for ($z = 0; $z < $zip->numFiles; $z++) {
            $name = $zip->getNameIndex($z);
            if (str_starts_with($name, 'ddrm_risq_')) {
                $st = $db->prepare('INSERT OR IGNORE INTO risques VALUES(?,?)');
                $lines = explode("\n", (string)$zip->getFromIndex($z));
                foreach (array_slice($lines, 1) as $l) { $r = str_getcsv(trim($l), ';', '"', ''); if (count($r) >= 4 && strlen($r[3]) <= 2) $st->execute([$r[0], trim($r[2])]); } // risques principaux (codes à 1-2 chiffres)
                $out['risques'] = count($lines);
            } elseif (str_starts_with($name, 'catnat_')) {
                $ev = [];
                $fh = $zip->getStream($name); fgets($fh);
                while (($l = fgets($fh)) !== false) {
                    $r = str_getcsv(trim($l), ';', '"', '');
                    if (count($r) < 7) continue;
                    $lab = mb_check_encoding($r[4], 'UTF-8') ? $r[4] : mb_convert_encoding($r[4], 'UTF-8', 'Windows-1252');
                    $ev[$r[1]][] = [substr($r[5], 0, 10), $lab];
                }
                fclose($fh);
                $st = $db->prepare('INSERT OR REPLACE INTO catnat VALUES(?,?,?)');
                foreach ($ev as $c => $list) { usort($list, fn($a, $b) => strcmp($b[0], $a[0])); $st->execute([$c, count($list), json_encode(array_slice($list, 0, 12), JSON_UNESCAPED_UNICODE)]); }
                $out['catnat'] = count($ev);
            }
        }
        $db->commit(); $zip->close();
    }
    @unlink("$dir/gaspar.zip");
    foreach (['delinq' => 'commune_sync_delinq', 'ecoles' => 'commune_sync_ecoles'] as $k => $fn) {
        try { $out[$k] = $fn($db, $dir); $log("$k ok"); } catch (Throwable $e) { $out[$k] = 'erreur : ' . $e->getMessage(); }
    }
    cache_clear($site['host']);
    return $out;
}

function commune_extra_set(PDO $db, string $kind, array $rows): int
{
    $st = $db->prepare('INSERT OR REPLACE INTO commune_extra VALUES(?,?,?)');
    $db->beginTransaction();
    foreach ($rows as $insee => $d) $st->execute([(string)$insee, $kind, json_encode($d, JSON_UNESCAPED_UNICODE)]);
    $db->commit();
    return count($rows);
}

function commune_extra(PDO $db, string $insee, string $kind): array
{
    $st = $db->prepare('SELECT data FROM commune_extra WHERE insee=? AND kind=?'); $st->execute([$insee, $kind]);
    return json_decode((string)$st->fetchColumn(), true) ?: [];
}

// Délinquance enregistrée (SSMSI, ministère de l'Intérieur) : dernière année + précédente, taux département et France.
const DELINQ_URL = 'https://static.data.gouv.fr/resources/bases-statistiques-communale-departementale-et-regionale-de-la-delinquance-enregistree-par-la-police-et-la-gendarmerie-nationales/20260709-115942/donnee-data.gouv-2025-geographie2026-produit-le2026-06-25.csv.gz';
const DELINQ_DEP_URL = 'https://static.data.gouv.fr/resources/bases-statistiques-communale-departementale-et-regionale-de-la-delinquance-enregistree-par-la-police-et-la-gendarmerie-nationales/20260709-120038/donnee-dep-data.gouv-2025-geographie2026-produit-le2026-06-25.csv';

function commune_sync_delinq(PDO $db, string $dir): int
{
    $num = fn($v) => $v === 'NA' || $v === '' ? null : (float)str_replace(',', '.', $v);
    // départements + France
    $in = fopen(commune_dl((string)cfg('delinq_dep_url', DELINQ_DEP_URL), "$dir/delinq_dep.csv"), 'r');
    $h = array_map(fn($x) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $x), "\" \r\n"), fgetcsv($in, 0, ';', '"', ''));
    $ix = array_flip($h); $last = 0; $rows = [];
    while (($r = fgetcsv($in, 0, ';', '"', '')) !== false) { $y = (int)$r[$ix['annee']]; $last = max($last, $y); $rows[] = $r; }
    fclose($in); @unlink("$dir/delinq_dep.csv");
    $dep = []; $fr = [];
    foreach ($rows as $r) {
        if ((int)$r[$ix['annee']] !== $last) continue;
        $ind = $r[$ix['indicateur']]; $n = (float)$num($r[$ix['nombre']]); $pop = (float)$r[$ix['insee_pop']];
        $dep['D' . ltrim($r[$ix['Code_departement']], '0')][$ind] = $num($r[$ix['taux_pour_mille']]);
        $fr[$ind][0] = ($fr[$ind][0] ?? 0) + $n; $fr[$ind][1] = ($fr[$ind][1] ?? 0) + $pop;
    }
    $dep['FR'] = array_map(fn($x) => $x[1] ? 1000 * $x[0] / $x[1] : null, $fr);
    commune_extra_set($db, 'delinq_ref', $dep);
    // communes (fichier compressé, lecture en flux)
    commune_dl((string)cfg('delinq_url', DELINQ_URL), "$dir/delinq.csv.gz");
    $gz = gzopen("$dir/delinq.csv.gz", 'r');
    $ix = array_flip(array_map(fn($x) => trim($x, "\"\xEF\xBB\xBF \r\n"), str_getcsv((string)gzgets($gz), ';', '"', '')));
    // table de travail (le fichier n'est pas trié par commune) : 2 dernières années seulement
    $db->exec('DROP TABLE IF EXISTS delinq_raw; CREATE TABLE delinq_raw(insee TEXT, ind TEXT, year INTEGER, n INTEGER, taux REAL, pop INTEGER)');
    $st = $db->prepare('INSERT INTO delinq_raw VALUES(?,?,?,?,?,?)');
    $db->beginTransaction(); $i = 0;
    while (($l = gzgets($gz)) !== false) {
        $r = str_getcsv(rtrim($l), ';', '"', '');
        $y = (int)($r[$ix['annee']] ?? 0);
        if ($y < $last - 1) continue;
        $diff = $r[$ix['est_diffuse']] === 'diff';
        $st->execute([$r[$ix['CODGEO_2026']], $r[$ix['indicateur']], $y, $diff ? (int)$num($r[$ix['nombre']]) : null, $diff ? $num($r[$ix['taux_pour_mille']]) : null, (int)$r[$ix['insee_pop']]]);
        if (++$i % 50000 === 0) { $db->commit(); usleep(100000); $db->beginTransaction(); }
    }
    $db->commit(); gzclose($gz); @unlink("$dir/delinq.csv.gz");
    $db->exec('CREATE INDEX ix_dr ON delinq_raw(insee)');
    $acc = []; $n = 0; $cur = null;
    foreach ($db->query('SELECT * FROM delinq_raw ORDER BY insee, ind, year') as $r) {
        if ($r['insee'] !== $cur && count($acc) >= 2000) { $n += commune_extra_set($db, 'delinq', $acc); $acc = []; }
        $cur = $r['insee'];
        $acc[$cur]['year'] = $last;
        if ((int)$r['year'] === $last) { $acc[$cur]['pop'] = (int)$r['pop']; $acc[$cur]['ind'][$r['ind']][0] = $r['n'] === null ? null : (int)$r['n']; $acc[$cur]['ind'][$r['ind']][1] = $r['taux'] === null ? null : (float)$r['taux']; }
        else $acc[$cur]['ind'][$r['ind']][2] = $r['n'] === null ? null : (int)$r['n'];
    }
    $n += commune_extra_set($db, 'delinq', $acc);
    $db->exec('DROP TABLE delinq_raw');
    return $n;
}

// Écoles, collèges, lycées (annuaire de l'Éducation nationale) + résultats au brevet et au bac.
function edu_csv(string $ds, string $where, string $file): Generator
{
    $url = 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/' . $ds . '/exports/csv?delimiter=%3B' . ($where ? '&where=' . rawurlencode($where) : '');
    $in = fopen(commune_dl($url, $file, 10), 'r'); // un export vide (session pas encore publiée) est normal
    $h = array_map(fn($x) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $x), "\" \r\n"), fgetcsv($in, 0, ';', '"', '') ?: []);
    while ($h && ($r = fgetcsv($in, 0, ';', '"', '')) !== false) yield array_combine($h, array_pad(array_slice($r, 0, count($h)), count($h), ''));
    fclose($in); @unlink($file);
}

function commune_sync_ecoles(PDO $db, string $dir): int
{
    $res = [];
    $y = (int)date('Y');
    foreach ([$y, $y - 1, $y - 2] as $s) { // brevet : dernière session publiée
        foreach (edu_csv('fr-en-dnb-par-etablissement', 'session="' . $s . '"', "$dir/dnb.csv") as $r) $res[$r['numero_d_etablissement']] = ['brevet' => (float)str_replace([',', '%'], ['.', ''], $r['taux_de_reussite']), 'session' => $s];
        if ($res) break;
    }
    foreach (['fr-en-indicateurs-de-resultat-des-lycees-gt_v2', 'fr-en-indicateurs-de-resultat-des-lycees-pro_v2'] as $ds) {
        foreach ([$y, $y - 1, $y - 2] as $s) {
            $got = 0;
            foreach (edu_csv($ds, 'year(annee)=' . $s, "$dir/ival.csv") as $r) {
                if ($r['taux_reu_total'] === '') continue;
                $res[$r['uai']] = ($res[$r['uai']] ?? []) + ['bac' => (float)$r['taux_reu_total'], 'va' => $r['va_reu_total'] === '' ? null : (float)$r['va_reu_total'], 'mentions' => $r['taux_men_total'] === '' ? null : (float)$r['taux_men_total'], 'session_bac' => $s];
                $got++;
            }
            if ($got) break;
        }
    }
    $acc = [];
    foreach (edu_csv('fr-en-annuaire-education', 'etat="OUVERT"', "$dir/annuaire.csv") as $r) {
        $t = $r['type_etablissement'];
        if (!in_array($t, ['Ecole', 'Collège', 'Lycée'], true)) continue;
        $sub = $t === 'Ecole' ? ($r['ecole_maternelle'] === '1' && $r['ecole_elementaire'] !== '1' ? 'maternelle' : ($r['ecole_elementaire'] === '1' && $r['ecole_maternelle'] !== '1' ? 'élémentaire' : 'primaire')) : '';
        $e = array_filter(['uai' => $r['identifiant_de_l_etablissement'], 'nom' => $r['nom_etablissement'], 'type' => $t, 'sub' => $sub, 'statut' => $r['statut_public_prive'],
            'voies' => implode(', ', array_keys(array_filter(['générale' => $r['voie_generale'] === '1', 'technologique' => $r['voie_technologique'] === '1', 'professionnelle' => $r['voie_professionnelle'] === '1'])))]
            + ($res[$r['identifiant_de_l_etablissement']] ?? []), fn($v) => $v !== '' && $v !== null);
        $acc[$r['code_commune']][] = $e;
        if ($p = commune_parent($r['code_commune'])) $acc[$p][] = $e; // Paris, Lyon, Marseille : aussi sur la fiche de la ville
    }
    return commune_extra_set($db, 'ecoles', $acc);
}

function commune_parent(string $code): ?string
{
    if (preg_match('/^751(0[1-9]|1\d|20)$/', $code)) return '75056';
    if (preg_match('/^6938[1-9]$/', $code)) return '69123';
    if (preg_match('/^132(0[1-9]|1[0-6])$/', $code)) return '13055';
    return null;
}

// ---------- Rendu ----------

function euro0(?float $v): string { return $v ? number_format($v, 0, ',', ' ') . ' €' : '—'; }
function nf0($v): string { return number_format((float)$v, 0, ',', ' '); }
function commune_url(array $c): string { return '/commune/' . dept_slug((string)$c['dept']) . '/' . $c['slug'] . '/'; }

function commune_dpe_site(): ?array
{
    static $s = false;
    if ($s === false) $s = registry()->query("SELECT * FROM sites WHERE dpe=1 AND status='active' LIMIT 1")->fetch() ?: null;
    return $s;
}

function commune_guides(array $site, int $n = 6): string
{
    $g = site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT $n")->fetchAll();
    return $g ? '<h2>Nos guides</h2><ul>' . implode('', array_map(fn($p) => '<li><a href="' . h($p['path']) . '">' . h($p['title']) . '</a></li>', $g)) . '</ul>' : '';
}

function commune_footer(): string
{
    return '<p class="disc">Sources publiques (Licence Ouverte Etalab) : découpage et population légale (geo.api.gouv.fr / Insee), demandes de valeurs foncières DVF (DGFiP, statistiques Etalab), '
        . 'base GASPAR des risques majeurs et arrêtés de catastrophe naturelle (Géorisques), diagnostics de performance énergétique (ADEME), contrôle sanitaire de l\'eau (ministère de la Santé), annuaire et résultats des établissements (Éducation nationale), délinquance enregistrée (SSMSI, ministère de l\'Intérieur). Données mises à jour chaque mois.</p>';
}

function commune_search_form(string $q = ''): string
{
    return '<form action="/commune/recherche/" method="get" class="box" style="display:flex;gap:8px"><input name="q" value="' . h($q) . '" placeholder="Nom de votre commune…" style="flex:1;padding:10px;border:1px solid var(--b);border-radius:8px;font-size:1rem"><button class="btn" style="padding:10px 16px">Voir la fiche</button></form>';
}

function page_commune_home(array $site): string
{
    $db = commune_db($site['host']);
    $n = (int)$db->query('SELECT COUNT(*) FROM communes')->fetchColumn();
    if (!$n) return '';
    $big = $db->query('SELECT c.*, d.med_all FROM communes c LEFT JOIN dvf d ON d.insee=c.insee ORDER BY pop DESC LIMIT 30')->fetchAll();
    $body = '<h1 style="margin-top:28px">Fiches communes : prix de l\'immobilier, risques, énergie et chiffres clés</h1>'
        . '<p class="lead">' . nf0($n) . ' communes de France : prix au m² des maisons et appartements, évolution des prix, risques naturels, catastrophes naturelles, performance énergétique des logements et population.</p>'
        . commune_search_form()
        . '<h2>Les plus grandes villes</h2><table><thead><tr><th>Commune</th><th>Population</th><th>Prix médian au m²</th></tr></thead><tbody>'
        . implode('', array_map(fn($c) => '<tr><td><a href="' . h(commune_url($c)) . '">' . h($c['name']) . '</a> <small>(' . h($c['dept']) . ')</small></td><td>' . nf0($c['pop']) . '</td><td>' . euro0((float)$c['med_all']) . '</td></tr>', $big))
        . '</tbody></table><h2>Par département</h2><p>' . implode(' · ', array_map(fn($d) => '<a href="/commune/' . h(dept_slug((string)$d)) . '/">' . h((string)DEPTS[$d]) . '</a>', array_keys(DEPTS))) . '</p>'
        . commune_guides($site) . commune_footer();
    return layout($site, ['title' => 'Fiches communes : prix immobilier, risques, DPE, population | ' . $site['name'],
        'desc' => 'Tout savoir sur votre commune : prix au m² et évolution, risques naturels et technologiques, catastrophes naturelles, DPE des logements, population. ' . nf0($n) . ' communes.',
        'canonical' => 'https://' . $site['host'] . '/commune/', 'schema' => [breadcrumbs($site, [['Communes', '/commune/']])]], $body);
}

function page_commune_search(array $site, string $q): string
{
    $db = commune_db($site['host']);
    $q = trim($q);
    $rows = [];
    if ($q !== '') {
        $st = $db->prepare('SELECT * FROM communes WHERE slug LIKE ? OR cp=? ORDER BY (slug=?) DESC, pop DESC LIMIT 30');
        $st->execute([slugify($q, 70) . '%', $q, slugify($q, 70)]);
        $rows = $st->fetchAll();
        if (count($rows) === 1) { header('Location: ' . commune_url($rows[0]), true, 302); return ''; }
    }
    $body = '<h1 style="margin-top:28px">Rechercher une commune</h1>' . commune_search_form($q)
        . ($rows ? '<ul>' . implode('', array_map(fn($c) => '<li><a href="' . h(commune_url($c)) . '">' . h($c['name']) . '</a> <small>(' . h($c['cp']) . ', ' . h((string)(DEPTS[$c['dept']] ?? $c['dept'])) . ')</small></li>', $rows)) . '</ul>' : ($q !== '' ? '<p>Aucune commune trouvée.</p>' : ''));
    return layout($site, ['title' => 'Rechercher une commune | ' . $site['name'], 'robots' => 'noindex,follow', 'canonical' => 'https://' . $site['host'] . '/commune/recherche/'], $body);
}

function page_commune_dept(array $site, string $dept): string
{
    $db = commune_db($site['host']);
    $st = $db->prepare('SELECT c.*, d.med_all, d.med_mai, d.med_apt, d.n_all FROM communes c LEFT JOIN dvf d ON d.insee=c.insee WHERE c.dept=? ORDER BY c.pop DESC'); $st->execute([$dept]);
    $rows = $st->fetchAll();
    if (!$rows) return '';
    $name = DEPTS[$dept] ?? $dept; $url = '/commune/' . dept_slug($dept) . '/';
    $pop = array_sum(array_column($rows, 'pop'));
    $priced = array_filter($rows, fn($r) => $r['n_all'] >= 20);
    usort($priced, fn($a, $b) => $b['med_all'] <=> $a['med_all']);
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/commune/">Communes</a> › ' . h($name) . '</p>'
        . '<h1>Communes du département ' . h($name) . ' (' . h($dept) . ') : prix immobilier et chiffres clés</h1>'
        . '<p class="lead">' . count($rows) . ' communes, ' . nf0($pop) . ' habitants. Prix au m² des maisons et appartements, risques et fiche complète de chaque commune.</p>'
        . ($priced ? '<h2>Les communes les plus chères</h2><ol>' . implode('', array_map(fn($c) => '<li><a href="' . h(commune_url($c)) . '">' . h($c['name']) . '</a> : ' . euro0((float)$c['med_all']) . '/m²</li>', array_slice($priced, 0, 10))) . '</ol>'
            . '<h2>Les communes les moins chères</h2><ol>' . implode('', array_map(fn($c) => '<li><a href="' . h(commune_url($c)) . '">' . h($c['name']) . '</a> : ' . euro0((float)$c['med_all']) . '/m²</li>', array_slice(array_reverse($priced), 0, 10))) . '</ol>' : '')
        . '<h2>Toutes les communes</h2><table><thead><tr><th>Commune</th><th>Habitants</th><th>Maison /m²</th><th>Appartement /m²</th></tr></thead><tbody>'
        . implode('', array_map(fn($c) => '<tr><td><a href="' . h(commune_url($c)) . '">' . h($c['name']) . '</a></td><td>' . nf0($c['pop']) . '</td><td>' . euro0((float)$c['med_mai']) . '</td><td>' . euro0((float)$c['med_apt']) . '</td></tr>', $rows))
        . '</tbody></table>' . commune_guides($site, 4) . commune_footer();
    return layout($site, ['title' => 'Communes ' . $name . ' (' . $dept . ') : prix immobilier au m² et fiches | ' . $site['name'],
        'desc' => 'Les ' . count($rows) . ' communes du département ' . $name . ' : prix au m² des maisons et appartements, communes les plus et moins chères, risques et chiffres clés.',
        'canonical' => 'https://' . $site['host'] . $url, 'schema' => [breadcrumbs($site, [['Communes', '/commune/'], [$name, $url]])]], $body);
}

function page_commune(array $site, string $dept, string $slug): string
{
    $db = commune_db($site['host']);
    $st = $db->prepare('SELECT * FROM communes WHERE dept=? AND slug=? ORDER BY pop DESC LIMIT 1'); $st->execute([$dept, $slug]);
    $c = $st->fetch();
    if (!$c) return '';
    $name = $c['name']; $dname = DEPTS[$dept] ?? $dept; $durl = '/commune/' . dept_slug($dept) . '/'; $url = commune_url($c);
    $one = function (string $sql, array $a) use ($db) { $s = $db->prepare($sql); $s->execute($a); return $s->fetch() ?: []; };
    $dvf = $one('SELECT * FROM dvf WHERE insee=?', [$c['insee']]);
    $st = $db->prepare('SELECT year, n, moy FROM dvf_year WHERE insee=? ORDER BY year'); $st->execute([$c['insee']]);
    $years = $st->fetchAll();
    $st = $db->prepare('SELECT risque FROM risques WHERE insee=? ORDER BY risque'); $st->execute([$c['insee']]);
    $risques = $st->fetchAll(PDO::FETCH_COLUMN);
    $cat = $one('SELECT * FROM catnat WHERE insee=?', [$c['insee']]);
    $events = json_decode((string)($cat['events'] ?? '[]'), true) ?: [];
    $dmed = $one('SELECT SUM(d.med_all*d.n_all)/SUM(d.n_all) m FROM dvf d JOIN communes c ON c.insee=d.insee WHERE c.dept=? AND d.n_all>0', [$dept])['m'] ?? null;
    $dens = $c['surface'] > 0 ? $c['pop'] / $c['surface'] : 0;
    $faq = [];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/commune/">Communes</a> › <a href="' . $durl . '">' . h($dname) . '</a> › ' . h($name) . '</p>'
        . '<h1>' . h($name) . ' (' . h($c['cp']) . ') : immobilier, risques, énergie et chiffres clés</h1>'
        . '<div class="kpi"><div><b>' . nf0($c['pop']) . '</b>habitants</div><div><b>' . number_format((float)$c['surface'], 1, ',', ' ') . ' km²</b>superficie</div><div><b>' . nf0($dens) . '</b>hab./km²</div>'
        . (!empty($dvf['med_all']) ? '<div><b>' . euro0((float)$dvf['med_all']) . '</b>prix médian au m²</div>' : '') . '</div>';
    // Immobilier
    if (!empty($dvf['n_all'])) {
        $body .= '<h2>Prix de l\'immobilier à ' . h($name) . '</h2><table><thead><tr><th></th><th>Prix médian au m²</th><th>Ventes analysées</th></tr></thead><tbody>'
            . (!empty($dvf['n_mai']) ? '<tr><td>Maisons</td><td><strong>' . euro0((float)$dvf['med_mai']) . '</strong></td><td>' . nf0($dvf['n_mai']) . '</td></tr>' : '')
            . (!empty($dvf['n_apt']) ? '<tr><td>Appartements</td><td><strong>' . euro0((float)$dvf['med_apt']) . '</strong></td><td>' . nf0($dvf['n_apt']) . '</td></tr>' : '')
            . '<tr><td>Ensemble</td><td><strong>' . euro0((float)$dvf['med_all']) . '</strong></td><td>' . nf0($dvf['n_all']) . '</td></tr></tbody></table>'
            . ($dmed ? '<p>Moyenne pondérée du département ' . h($dname) . ' : ' . euro0((float)$dmed) . '/m² — ' . h($name) . ' est ' . ($dvf['med_all'] > $dmed ? '<strong>plus chère</strong>' : '<strong>moins chère</strong>') . ' de ' . nf0(abs(100 * ($dvf['med_all'] - $dmed) / $dmed)) . ' %.</p>' : '');
        if (count($years) >= 2) {
            $first = $years[0]; $last = end($years);
            $body .= '<h3>Évolution du prix moyen au m²</h3><p>' . sparkline(array_map(fn($y) => (float)$y['moy'], $years), 320, 60) . '</p><table><thead><tr><th>Année</th><th>Prix moyen au m²</th><th>Ventes</th></tr></thead><tbody>'
                . implode('', array_map(fn($y) => '<tr><td>' . $y['year'] . '</td><td>' . euro0((float)$y['moy']) . '</td><td>' . nf0($y['n']) . '</td></tr>', array_reverse($years))) . '</tbody></table>';
            $var = $first['moy'] ? 100 * ($last['moy'] - $first['moy']) / $first['moy'] : 0;
            $faq[] = ['q' => 'Les prix de l\'immobilier augmentent-ils à ' . $name . ' ?', 'a' => 'Le prix moyen au m² est passé de ' . euro0((float)$first['moy']) . ' en ' . $first['year'] . ' à ' . euro0((float)$last['moy']) . ' en ' . $last['year'] . ', soit ' . ($var >= 0 ? '+' : '') . number_format($var, 1, ',', ' ') . ' %.'];
        }
        $faq[] = ['q' => 'Quel est le prix au m² à ' . $name . ' ?', 'a' => 'Le prix médian est de ' . euro0((float)$dvf['med_all']) . ' par m²' . (!empty($dvf['med_mai']) ? ', ' . euro0((float)$dvf['med_mai']) . ' pour une maison' : '') . (!empty($dvf['med_apt']) ? ' et ' . euro0((float)$dvf['med_apt']) . ' pour un appartement' : '') . ', d\'après ' . nf0($dvf['n_all']) . ' ventes enregistrées.'];
    } else $body .= '<h2>Prix de l\'immobilier à ' . h($name) . '</h2><p>Trop peu de ventes enregistrées pour établir un prix au m² fiable.</p>';
    // Énergie
    if (($ds = commune_dpe_site()) && function_exists('dpe_db')) {
        $s = dpe_db($ds['host'])->prepare('SELECT * FROM dpe_stats WHERE insee=?'); $s->execute([$c['insee']]);
        if (($dp = $s->fetch()) && $dp['n'] >= 10) {
            $body .= '<h2>Performance énergétique des logements</h2><p>' . $dp['n'] . ' diagnostics (DPE) réalisés depuis juillet 2021 : <strong>' . pct(dpe_passoires($dp)) . '</strong> de passoires thermiques (F et G), consommation moyenne ' . round((float)$dp['conso']) . ' kWh/m²/an.</p>' . dpe_bars($dp)
                . ($dp['n'] >= DPE_MIN ? '<p><a href="https://' . h($ds['host']) . '/dpe/' . h(dept_slug($dept)) . '/' . h($dp['city_slug']) . '/">Détail du DPE à ' . h($name) . ' →</a></p>' : '');
            $faq[] = ['q' => 'Y a-t-il beaucoup de passoires thermiques à ' . $name . ' ?', 'a' => pct(dpe_passoires($dp)) . ' des logements diagnostiqués sont classés F ou G.'];
        }
    }
    // Risques
    $body .= '<h2>Risques naturels et technologiques</h2>' . ($risques ? '<p>' . h($name) . ' est exposée à ' . count($risques) . ' risque(s) majeur(s) recensé(s) :</p><ul>' . implode('', array_map(fn($r) => '<li>' . h($r) . '</li>', $risques)) . '</ul>' : '<p>Aucun risque majeur recensé dans la base nationale GASPAR.</p>');
    $body .= '<h3>Arrêtés de catastrophe naturelle</h3>' . (!empty($cat['n']) ? '<p>' . (int)$cat['n'] . ' arrêté(s) de reconnaissance de l\'état de catastrophe naturelle depuis 1982. Les plus récents :</p><ul>'
        . implode('', array_map(fn($e) => '<li>' . h($e[1]) . ' — ' . h(date_fr($e[0])) . '</li>', array_slice($events, 0, 8))) . '</ul>' : '<p>Aucun arrêté de catastrophe naturelle recensé.</p>');
    $faq[] = ['q' => 'Quels sont les risques naturels à ' . $name . ' ?', 'a' => $risques ? implode(', ', $risques) . '.' : 'Aucun risque majeur n\'est recensé dans la base nationale GASPAR.'];
    $faq[] = ['q' => 'Combien de catastrophes naturelles à ' . $name . ' ?', 'a' => !empty($cat['n']) ? $cat['n'] . ' arrêté(s) de catastrophe naturelle depuis 1982, le dernier pour « ' . $events[0][1] . ' » (' . date_fr($events[0][0]) . ').' : 'Aucun arrêté de catastrophe naturelle n\'est recensé.'];
    // Eau du robinet (poussé par le VPS)
    $eau = commune_extra($db, $c['insee'], 'eau');
    if ($eau) {
        $body .= '<h2>Qualité de l\'eau du robinet à ' . h($name) . '</h2>'
            . '<div class="kpi"><div><b>' . pct((float)$eau['conf_bact']) . '</b>prélèvements conformes (bactériologie)</div><div><b>' . pct((float)$eau['conf_chim']) . '</b>conformes (physico-chimie)</div>'
            . (isset($eau['no3']) ? '<div><b>' . number_format((float)$eau['no3'], 1, ',', ' ') . ' mg/L</b>nitrates (limite 50)</div>' : '')
            . (isset($eau['th']) ? '<div><b>' . number_format((float)$eau['th'], 1, ',', ' ') . ' °f</b>dureté : ' . ($eau['th'] < 15 ? 'eau douce' : ($eau['th'] < 30 ? 'eau moyennement calcaire' : 'eau calcaire')) . '</div>' : '') . '</div>'
            . '<p>' . (int)$eau['n'] . ' prélèvement(s) analysé(s) par l\'agence régionale de santé en ' . h((string)$eau['year']) . (!empty($eau['reseau']) ? ' sur le réseau « ' . h($eau['reseau']) . ' »' : '') . '. '
            . (!empty($eau['last']) ? 'Dernière conclusion (' . h(date_fr($eau['last_date'])) . ') : « ' . h($eau['last']) . ' »' : '') . '</p>';
        $faq[] = ['q' => 'L\'eau du robinet est-elle potable à ' . $name . ' ?', 'a' => pct((float)$eau['conf_bact']) . ' des prélèvements de ' . $eau['year'] . ' sont conformes pour la bactériologie et ' . pct((float)$eau['conf_chim']) . ' pour la physico-chimie.' . (!empty($eau['last']) ? ' Dernière conclusion : ' . $eau['last'] : '')];
        if (isset($eau['th'])) $faq[] = ['q' => 'L\'eau est-elle calcaire à ' . $name . ' ?', 'a' => 'La dureté moyenne mesurée est de ' . number_format((float)$eau['th'], 1, ',', ' ') . ' °f (' . ($eau['th'] < 15 ? 'eau douce' : ($eau['th'] < 30 ? 'moyennement calcaire' : 'calcaire, un adoucisseur ou une carafe filtrante peut être utile')) . ').'];
    }
    // Écoles
    $ec = commune_extra($db, $c['insee'], 'ecoles');
    if ($ec) {
        $cnt = array_count_values(array_column($ec, 'type'));
        usort($ec, fn($a, $b) => [array_search($a['type'], ['Lycée', 'Collège', 'Ecole']), $a['nom']] <=> [array_search($b['type'], ['Lycée', 'Collège', 'Ecole']), $b['nom']]);
        $row = fn($e) => '<tr><td>' . h(ucwords(mb_strtolower($e['nom']))) . '</td><td>' . h($e['type'] === 'Ecole' ? 'École ' . ($e['sub'] ?? '') : $e['type']) . (!empty($e['voies']) ? '<br><small>' . h($e['voies']) . '</small>' : '') . '</td><td>' . h($e['statut'] ?? '') . '</td><td>'
            . (isset($e['bac']) ? 'Bac : <strong>' . pct((float)$e['bac']) . '</strong>' . (isset($e['va']) ? ' <small>(valeur ajoutée ' . ($e['va'] >= 0 ? '+' : '') . $e['va'] . ')</small>' : '') : '')
            . (isset($e['brevet']) ? 'Brevet : <strong>' . pct((float)$e['brevet']) . '</strong>' : '') . '</td></tr>';
        $body .= '<h2>Écoles, collèges et lycées à ' . h($name) . '</h2><p>' . implode(', ', array_map(fn($t, $n) => $n . ' ' . ($t === 'Ecole' ? 'école(s)' : mb_strtolower($t) . '(s)'), array_keys($cnt), $cnt)) . '.</p>'
            . '<table><thead><tr><th>Établissement</th><th>Type</th><th>Secteur</th><th>Résultats</th></tr></thead><tbody>' . implode('', array_map($row, array_slice($ec, 0, 60))) . '</tbody></table>';
        $best = array_values(array_filter($ec, fn($e) => isset($e['bac'])));
        usort($best, fn($a, $b) => $b['bac'] <=> $a['bac']);
        $faq[] = ['q' => 'Combien d\'écoles à ' . $name . ' ?', 'a' => implode(', ', array_map(fn($t, $n) => $n . ' ' . ($t === 'Ecole' ? 'école(s)' : mb_strtolower($t) . '(s)'), array_keys($cnt), $cnt)) . ' (annuaire de l\'Éducation nationale).'];
        if ($best) $faq[] = ['q' => 'Quel est le meilleur lycée de ' . $name . ' ?', 'a' => 'Au bac ' . $best[0]['session_bac'] . ', le meilleur taux de réussite est celui de ' . ucwords(mb_strtolower($best[0]['nom'])) . ' (' . pct((float)$best[0]['bac']) . '). La valeur ajoutée, qui tient compte du profil des élèves, est un indicateur complémentaire.'];
    }
    // Délinquance
    $dl = commune_extra($db, $c['insee'], 'delinq');
    if (!empty($dl['ind'])) {
        $ref = commune_extra($db, 'D' . ltrim($dept, '0'), 'delinq_ref'); $frr = commune_extra($db, 'FR', 'delinq_ref');
        $tr = '';
        foreach ($dl['ind'] as $ind => $v) {
            if (str_contains($ind, '(AFD)')) continue;
            $tr .= '<tr><td>' . h($ind) . '</td><td>' . (isset($v[0]) ? nf0($v[0]) : '<small>non diffusé</small>') . '</td><td>' . (isset($v[1]) ? number_format((float)$v[1], 2, ',', ' ') . ' ‰' : '—') . '</td><td>'
                . (isset($ref[$ind]) ? number_format((float)$ref[$ind], 2, ',', ' ') . ' ‰' : '—') . '</td><td>' . (isset($frr[$ind]) ? number_format((float)$frr[$ind], 2, ',', ' ') . ' ‰' : '—') . '</td><td>'
                . (isset($v[0], $v[2]) && $v[2] > 0 ? (($d = 100 * ($v[0] - $v[2]) / $v[2]) >= 0 ? '+' : '') . nf0($d) . ' %' : '—') . '</td></tr>';
        }
        $body .= '<h2>Sécurité et délinquance à ' . h($name) . '</h2><p>Faits enregistrés par la police et la gendarmerie en ' . (int)$dl['year'] . ', taux pour 1 000 habitants (ou logements pour les cambriolages), comparés au département et à la France.</p>'
            . '<table><thead><tr><th>Indicateur</th><th>Nombre</th><th>Taux</th><th>' . h($dname) . '</th><th>France</th><th>Évolution sur 1 an</th></tr></thead><tbody>' . $tr . '</tbody></table>'
            . '<p><small>« Non diffusé » : nombre trop faible pour être publié (secret statistique). Les faits sont comptés dans la commune où ils sont enregistrés.</small></p>';
        $cb = $dl['ind']['Cambriolages de logement'] ?? null;
        if (isset($cb[1])) $faq[] = ['q' => 'Y a-t-il beaucoup de cambriolages à ' . $name . ' ?', 'a' => nf0($cb[0]) . ' cambriolages de logement enregistrés en ' . $dl['year'] . ', soit ' . number_format((float)$cb[1], 2, ',', ' ') . ' pour 1 000 logements' . (isset($frr['Cambriolages de logement']) ? ' (France : ' . number_format((float)$frr['Cambriolages de logement'], 2, ',', ' ') . ' ‰)' : '') . '.'];
    }
    // Voisines
    $near = [];
    if ($c['lat']) {
        $s = $db->prepare('SELECT c.*, d.med_all FROM communes c LEFT JOIN dvf d ON d.insee=c.insee WHERE c.insee<>? AND c.lat BETWEEN ? AND ? AND c.lon BETWEEN ? AND ? ORDER BY ((c.lat-?)*(c.lat-?)+(c.lon-?)*(c.lon-?)) LIMIT 10');
        $s->execute([$c['insee'], $c['lat'] - .15, $c['lat'] + .15, $c['lon'] - .22, $c['lon'] + .22, $c['lat'], $c['lat'], $c['lon'], $c['lon']]);
        $near = $s->fetchAll();
    }
    $body .= ($near ? '<h2>Communes voisines</h2><table><thead><tr><th>Commune</th><th>Habitants</th><th>Prix médian /m²</th></tr></thead><tbody>' . implode('', array_map(fn($x) => '<tr><td><a href="' . h(commune_url($x)) . '">' . h($x['name']) . '</a></td><td>' . nf0($x['pop']) . '</td><td>' . euro0((float)$x['med_all']) . '</td></tr>', $near)) . '</tbody></table>' : '')
        . '<section class="faq"><h2>Questions fréquentes</h2>' . implode('', array_map(fn($x) => '<details><summary>' . h($x['q']) . '</summary><p>' . h($x['a']) . '</p></details>', $faq)) . '</section>'
        . commune_guides($site, 4) . commune_footer();
    return layout($site, ['title' => $name . ' (' . $c['cp'] . ') : prix immobilier au m², risques, DPE | ' . $site['name'],
        'desc' => $name . ' (' . $dname . ') : ' . (!empty($dvf['med_all']) ? 'prix médian ' . euro0((float)$dvf['med_all']) . '/m², ' : '') . nf0($c['pop']) . ' habitants, ' . count($risques) . ' risque(s) majeur(s)' . (!empty($cat['n']) ? ', ' . $cat['n'] . ' catastrophe(s) naturelle(s)' : '') . '. Fiche complète.',
        'canonical' => 'https://' . $site['host'] . $url,
        'schema' => [['@context' => 'https://schema.org', '@type' => 'City', 'name' => $name, 'address' => ['@type' => 'PostalAddress', 'postalCode' => $c['cp'], 'addressRegion' => $dname, 'addressCountry' => 'FR']]
            + ($c['lat'] ? ['geo' => ['@type' => 'GeoCoordinates', 'latitude' => $c['lat'], 'longitude' => $c['lon']]] : []),
            breadcrumbs($site, [['Communes', '/commune/'], [$dname, $durl], [$name, $url]]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]], $faq)]]], $body);
}

function commune_home_block(array $site): string
{
    if (!(int)commune_db($site['host'])->query('SELECT COUNT(*) FROM communes')->fetchColumn()) return '';
    return '<h2 style="margin-top:28px">La fiche de votre commune</h2><p>Prix de l\'immobilier, risques, catastrophes naturelles, performance énergétique : tout savoir sur une commune avant d\'acheter ou de déménager.</p>' . commune_search_form()
        . '<details><summary><strong>Choisir un département</strong></summary><p>' . implode(' · ', array_map(fn($d) => '<a href="/commune/' . h(dept_slug((string)$d)) . '/">' . h((string)DEPTS[$d]) . '</a>', array_keys(DEPTS))) . '</p></details>';
}

function out_sitemap_communes(array $site, int $i): void
{
    $db = commune_db($site['host']);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $day = gmdate('Y-m-01');
    if ($i === 1) {
        echo '<url><loc>https://' . $site['host'] . '/commune/</loc><lastmod>' . $day . '</lastmod></url>';
        foreach ($db->query('SELECT DISTINCT dept FROM communes') as $r) if (isset(DEPTS[$r['dept']])) echo '<url><loc>https://' . $site['host'] . '/commune/' . dept_slug((string)$r['dept']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    }
    $st = $db->prepare('SELECT dept, slug FROM communes ORDER BY insee LIMIT 10000 OFFSET ?'); $st->execute([($i - 1) * 10000]);
    foreach ($st as $r) if (isset(DEPTS[$r['dept']])) echo '<url><loc>https://' . $site['host'] . '/commune/' . dept_slug((string)$r['dept']) . '/' . h($r['slug']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    echo '</urlset>';
}

function commune_sitemap_count(array $site): int
{
    return max(1, (int)ceil((int)commune_db($site['host'])->query('SELECT COUNT(*) FROM communes')->fetchColumn() / 10000));
}
