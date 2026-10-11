<?php
// Données publiques pour recruteur.eu (imports mensuels, affichage depuis SQLite) :
// communes (geo.api.gouv.fr), zones d'emploi (INSEE ZE2020), enquête BMO (France Travail), loyers d'annonce (carte des loyers),
// prix de vente DVF (Etalab), lieux d'accompagnement (Annuaire de l'administration), fiches entreprises (API Recherche d'entreprises, INSEE/Sirene).
// Chaque bloc affiché nomme sa source et son année ; en cas d'échec d'import, la version précédente reste en place.
declare(strict_types=1);

const PD_SRC = [
    'communes' => 'https://geo.api.gouv.fr/communes?fields=nom,code,codesPostaux,population,centre,codeDepartement&format=json',
    'ze' => 'https://www.insee.fr/fr/statistiques/fichier/4652957/ZE2020_au_01-01-2025.zip',
    'bmo_dataset' => 'https://www.data.gouv.fr/api/1/datasets/561fa564c751df4f2acdbb48/',
    'fap' => 'https://data.dares.travail-emploi.gouv.fr/api/explore/v2.1/catalog/datasets/dares_nomenclature_fap2021/exports/csv?use_labels=false&delimiter=%3B',
    'loyers_q' => 'https://www.data.gouv.fr/api/1/datasets/?q=carte+des+loyers+indicateurs+commune&page_size=10',
    'dvf' => 'https://data-pipeline-open.s3.sbg.io.cloud.ovh.net/dvf/stats_whole_period.csv',
    'annuaire' => 'https://api-lannuaire.service-public.fr/api/explore/v2.1/catalog/datasets/api-lannuaire-administration/exports/json',
    'naf' => 'https://data.grandlyon.com/fr/datapusher/ws/rdata/insee.codenaf/all.csv?maxfeatures=-1',
];
const PD_LIEUX = ['france_travail' => 'Agence France Travail', 'cap_emploi' => 'Cap emploi', 'france_services' => 'France services'];
const PD_TRANCHES = ['00' => '0 salarié', '01' => '1 ou 2 salariés', '02' => '3 à 5 salariés', '03' => '6 à 9 salariés', '11' => '10 à 19 salariés', '12' => '20 à 49 salariés', '21' => '50 à 99 salariés', '22' => '100 à 199 salariés', '31' => '200 à 249 salariés', '32' => '250 à 499 salariés', '41' => '500 à 999 salariés', '42' => '1 000 à 1 999 salariés', '51' => '2 000 à 4 999 salariés', '52' => '5 000 à 9 999 salariés', '53' => '10 000 salariés et plus'];

function pd_tables(PDO $db): void
{
    static $d = false; if ($d) return; $d = true;
    $db->exec("CREATE TABLE IF NOT EXISTS pd_communes(insee TEXT PRIMARY KEY, name TEXT, slug TEXT, dep TEXT, cps TEXT, pop INTEGER, lat REAL, lon REAL, ze TEXT, zename TEXT);
        CREATE INDEX IF NOT EXISTS ix_pdc_slug ON pd_communes(slug);
        CREATE TABLE IF NOT EXISTS pd_bmo(fap TEXT, metier TEXT, dep TEXT, bassin TEXT, bassin_nom TEXT, met INTEGER, xmet INTEGER, smet INTEGER);
        CREATE INDEX IF NOT EXISTS ix_bmo_fap ON pd_bmo(fap, dep); CREATE INDEX IF NOT EXISTS ix_bmo_dep ON pd_bmo(dep, bassin);
        CREATE TABLE IF NOT EXISTS job_metier_fap(mslug TEXT PRIMARY KEY, fap TEXT, label TEXT);
        CREATE TABLE IF NOT EXISTS pd_loyer(insee TEXT PRIMARY KEY, app REAL, mai REAL, typ TEXT);
        CREATE TABLE IF NOT EXISTS pd_dvf(insee TEXT PRIMARY KEY, n_app INTEGER, m_app INTEGER, n_mai INTEGER, m_mai INTEGER);
        CREATE TABLE IF NOT EXISTS pd_lieux(id TEXT PRIMARY KEY, type TEXT, nom TEXT, adresse TEXT, cp TEXT, ville TEXT, lat REAL, lon REAL, tel TEXT, url TEXT, communes TEXT);
        CREATE INDEX IF NOT EXISTS ix_lieux_cp ON pd_lieux(type, cp);
        CREATE TABLE IF NOT EXISTS pd_naf(code TEXT PRIMARY KEY, label TEXT);
        CREATE TABLE IF NOT EXISTS pd_company(slug TEXT PRIMARY KEY, ok INTEGER, siren TEXT, nom TEXT, naf TEXT, tranche TEXT, cat TEXT, created TEXT, siege TEXT, n_etab INTEGER, checked_at TEXT);");
}
function pd_meta(string $k, ?string $v = null): string { if ($v !== null) setting_set('pd_' . $k, $v); return (string)setting('pd_' . $k, ''); }

function pd_fetch(string $url, string $to, int $min = 1000, int $timeout = 300): ?string
{
    $fh = fopen($to . '.tmp', 'w'); $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; recruteur.eu donnees publiques)']);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch); fclose($fh);
    if ($code !== 200 || filesize($to . '.tmp') < $min) { @unlink($to . '.tmp'); return "HTTP $code $err"; }
    rename($to . '.tmp', $to); return null;
}

// Lecteur XLSX minimal (chaînes partagées + une feuille), sans dépendance
function pd_xlsx_rows(string $file, string $sheetName = ''): Generator
{
    $z = new ZipArchive(); if ($z->open($file) !== true) return;
    $ss = [];
    if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) { $r = new XMLReader(); $r->XML($x); $cur = null;
        while ($r->read()) { if ($r->nodeType === XMLReader::ELEMENT && $r->name === 'si') $cur = ''; elseif ($r->nodeType === XMLReader::TEXT && $cur !== null) $cur .= $r->value; elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->name === 'si') { $ss[] = $cur; $cur = null; } } }
    $target = 'xl/worksheets/sheet1.xml';
    if ($sheetName !== '' && ($wb = $z->getFromName('xl/workbook.xml')) && ($rels = $z->getFromName('xl/_rels/workbook.xml.rels'))) {
        if (preg_match('~<sheet[^>]+name="' . preg_quote($sheetName, '~') . '"[^>]+r:id="([^"]+)"~u', $wb, $m) && preg_match('~Id="' . preg_quote($m[1], '~') . '"[^>]+Target="([^"]+)"|Target="([^"]+)"[^>]+Id="' . preg_quote($m[1], '~') . '"~', $rels, $t)) $target = 'xl/' . ltrim($t[1] ?: $t[2], '/');
        $target = str_replace('xl//xl/', 'xl/', $target);
    }
    $r = new XMLReader(); if (!$r->open('zip://' . $file . '#' . $target)) return;
    $row = []; $col = 0; $type = ''; $val = null;
    while ($r->read()) {
        if ($r->nodeType === XMLReader::ELEMENT) {
            if ($r->name === 'row') $row = [];
            elseif ($r->name === 'c') { $ref = (string)$r->getAttribute('r'); $col = 0; foreach (str_split(preg_replace('/\d/', '', $ref)) as $ch) $col = $col * 26 + ord($ch) - 64; $col--; $type = (string)$r->getAttribute('t'); $val = ''; if ($r->isEmptyElement) $row[$col] = ''; }
            elseif ($r->name === 'v' || $r->name === 't') { $r->read(); $val .= $r->value; }
        } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
            if ($r->name === 'c') $row[$col] = $type === 's' ? ($ss[(int)$val] ?? '') : (string)$val;
            elseif ($r->name === 'row') { if ($row) { $mx = max(array_keys($row)); $o = []; for ($i = 0; $i <= $mx; $i++) $o[] = $row[$i] ?? ''; yield $o; } }
        }
    }
    $r->close();
}

function pd_import_all(PDO $db, string $dir): array
{
    pd_tables($db); @mkdir($dir, 0750, true); $o = [];
    foreach (['naf', 'communes', 'ze', 'bmo', 'loyers', 'dvf', 'annuaire'] as $k) {
        try { $o[$k] = ('pd_import_' . $k)($db, $dir); } catch (Throwable $e) { $o[$k] = ['error' => $e->getMessage()]; }
    }
    try { $o['metier_fap'] = pd_match_metiers($db); } catch (Throwable $e) { $o['metier_fap'] = ['error' => $e->getMessage()]; }
    return $o;
}

function pd_import_naf(PDO $db, string $dir): array
{
    if ($e = pd_fetch(PD_SRC['naf'], "$dir/naf.csv", 20000)) return ['error' => $e];
    $fp = fopen("$dir/naf.csv", 'r'); fgets($fp); $rows = [];
    while (($r = fgetcsv($fp, 0, ';', '"', '')) !== false) if (count($r) >= 2 && preg_match('/^\d\d\.\d\d[A-Z]$/', trim($r[0], "\xEF\xBB\xBF "))) $rows[trim($r[0], "\xEF\xBB\xBF ")] = trim($r[1]);
    if (count($rows) < 600) return ['error' => 'NAF incomplète (' . count($rows) . ')'];
    $db->beginTransaction(); $db->exec('DELETE FROM pd_naf'); $i = $db->prepare('INSERT INTO pd_naf VALUES(?,?)'); foreach ($rows as $c => $l) $i->execute([$c, $l]); $db->commit();
    return ['n' => count($rows)];
}

function pd_import_communes(PDO $db, string $dir): array
{
    if ($e = pd_fetch(PD_SRC['communes'], "$dir/communes.json", 1000000)) return ['error' => $e];
    $a = json_decode((string)file_get_contents("$dir/communes.json"), true);
    if (!is_array($a) || count($a) < 30000) return ['error' => 'liste des communes incomplète'];
    $old = []; foreach ($db->query('SELECT insee, ze, zename FROM pd_communes') as $r) $old[$r['insee']] = [$r['ze'], $r['zename']];
    $db->beginTransaction(); $db->exec('DELETE FROM pd_communes');
    $i = $db->prepare('INSERT OR REPLACE INTO pd_communes VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach ($a as $c) { $ll = $c['centre']['coordinates'] ?? [null, null];
        $i->execute([$c['code'], $c['nom'], slugify($c['nom'], 80), $c['codeDepartement'] ?? '', implode(',', $c['codesPostaux'] ?? []), (int)($c['population'] ?? 0), $ll[1], $ll[0], $old[$c['code']][0] ?? '', $old[$c['code']][1] ?? '']); }
    $db->commit();
    return ['n' => count($a)];
}

function pd_import_ze(PDO $db, string $dir): array
{
    if ($e = pd_fetch(PD_SRC['ze'], "$dir/ze.zip", 100000)) return ['error' => $e];
    $z = new ZipArchive(); if ($z->open("$dir/ze.zip") !== true) return ['error' => 'zip ZE illisible'];
    $name = ''; for ($k = 0; $k < $z->numFiles; $k++) if (str_ends_with($z->getNameIndex($k), '.xlsx')) $name = $z->getNameIndex($k);
    if ($name === '') return ['error' => 'xlsx ZE absent'];
    file_put_contents("$dir/ze.xlsx", $z->getFromName($name)); $z->close();
    $hdr = null; $n = 0; $db->beginTransaction(); $u = $db->prepare('UPDATE pd_communes SET ze=?, zename=? WHERE insee=?');
    foreach (pd_xlsx_rows("$dir/ze.xlsx", 'Composition_communale') as $r) {
        if ($hdr === null) { if (in_array('CODGEO', $r, true)) $hdr = array_flip($r); continue; }
        if (!isset($hdr['ZE2020'])) break;
        $u->execute([$r[$hdr['ZE2020']] ?? '', $r[$hdr['LIBZE2020']] ?? '', $r[$hdr['CODGEO']] ?? '']); $n += $u->rowCount();
    }
    $db->commit();
    return $n < 30000 ? ['error' => "zones d'emploi : $n communes rattachées seulement"] : ['n' => $n];
}

function pd_import_bmo(PDO $db, string $dir): array
{
    $ds = json_decode((string)@file_get_contents(PD_SRC['bmo_dataset']), true);
    $url = ''; $year = 0;
    foreach ($ds['resources'] ?? [] as $r) if (preg_match('/BMO\s*(20\d\d)/u', (string)$r['title'], $m) && str_ends_with(strtolower((string)$r['url']), '.xlsx') && (int)$m[1] > $year) { $year = (int)$m[1]; $url = (string)$r['url']; }
    if ($url === '') return ['error' => 'ressource BMO introuvable'];
    if ($year <= (int)pd_meta('bmo_year') && (int)$db->query('SELECT COUNT(*) FROM pd_bmo')->fetchColumn() > 10000) return ['n' => 'à jour', 'annee' => $year];
    if ($e = pd_fetch($url, "$dir/bmo.xlsx", 500000)) return ['error' => $e];
    $hdr = null; $rows = [];
    $num = fn($v) => is_numeric($v) ? (int)$v : null; // « * » = secret statistique
    foreach (pd_xlsx_rows("$dir/bmo.xlsx", 'BMO_' . $year . '_open_data') as $r) {
        if ($hdr === null) { if (in_array('Code métier BMO', $r, true)) $hdr = array_flip($r); continue; }
        $rows[] = [$r[$hdr['Code métier BMO']], $r[$hdr['Nom métier BMO']], (string)$r[$hdr['Dept']], (string)$r[$hdr['BE' . substr((string)$year, 2)]] ?? '', (string)($r[$hdr['NOMBE' . substr((string)$year, 2)]] ?? ''), $num($r[$hdr['met']]), $num($r[$hdr['xmet']]), $num($r[$hdr['smet']])];
    }
    if (count($rows) < 20000) return ['error' => 'BMO incomplète (' . count($rows) . ' lignes)'];
    $db->beginTransaction(); $db->exec('DELETE FROM pd_bmo'); $i = $db->prepare('INSERT INTO pd_bmo VALUES(?,?,?,?,?,?,?,?)'); foreach ($rows as $r) $i->execute($r); $db->commit();
    pd_meta('bmo_year', (string)$year);
    return ['n' => count($rows), 'annee' => $year];
}

function pd_import_loyers(PDO $db, string $dir): array
{
    $ds = json_decode((string)@file_get_contents(PD_SRC['loyers_q']), true); $best = null; $year = 0;
    foreach ($ds['data'] ?? [] as $d) if (preg_match('/Carte des loyers.*commune en (20\d\d)/u', (string)$d['title'], $m) && (int)$m[1] > $year) { $year = (int)$m[1]; $best = $d; }
    if (!$best) return ['error' => 'jeu « carte des loyers » introuvable'];
    $urls = []; foreach ($best['resources'] as $r) { if (preg_match('~/pred-app-mef-dhup\.csv$~', (string)$r['url'])) $urls['app'] = $r['url']; if (preg_match('~/pred-mai-mef-dhup\.csv$~', (string)$r['url'])) $urls['mai'] = $r['url']; }
    if (!isset($urls['app'])) return ['error' => 'fichier loyers appartement introuvable'];
    $val = [];
    foreach ($urls as $k => $u) {
        if ($e = pd_fetch($u, "$dir/loyer_$k.csv", 500000)) return ['error' => $e];
        $fp = fopen("$dir/loyer_$k.csv", 'r'); $h = fgetcsv($fp, 0, ';', '"', ''); $h = array_flip(array_map(fn($x) => trim($x, "\xEF\xBB\xBF\" "), $h));
        while (($r = fgetcsv($fp, 0, ';', '"', '')) !== false) { $ins = $r[$h['INSEE_C']] ?? ''; $v = (float)str_replace(',', '.', (string)($r[$h['loypredm2']] ?? '')); if ($ins !== '' && $v > 0) { $val[$ins][$k] = round($v, 1); $val[$ins]['t'] = (string)($r[$h['TYPPRED']] ?? ''); } }
        fclose($fp);
    }
    if (count($val) < 30000) return ['error' => 'loyers incomplets'];
    $db->beginTransaction(); $db->exec('DELETE FROM pd_loyer'); $i = $db->prepare('INSERT INTO pd_loyer VALUES(?,?,?,?)'); foreach ($val as $ins => $v) $i->execute([$ins, $v['app'] ?? null, $v['mai'] ?? null, $v['t'] ?? '']); $db->commit();
    pd_meta('loyer_year', (string)$year);
    return ['n' => count($val), 'annee' => $year];
}

function pd_import_dvf(PDO $db, string $dir): array
{
    if ($e = pd_fetch(PD_SRC['dvf'], "$dir/dvf.csv", 1000000)) return ['error' => $e];
    $fp = fopen("$dir/dvf.csv", 'r'); $h = array_flip(fgetcsv($fp, 0, ',', '"', '')); $n = 0;
    $db->beginTransaction(); $db->exec('DELETE FROM pd_dvf'); $i = $db->prepare('INSERT OR REPLACE INTO pd_dvf VALUES(?,?,?,?,?)');
    while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) { if (($r[$h['echelle_geo']] ?? '') !== 'commune') continue;
        $i->execute([$r[$h['code_geo']], (int)$r[$h['nb_ventes_whole_appartement']], (int)$r[$h['med_prix_m2_whole_appartement']], (int)$r[$h['nb_ventes_whole_maison']], (int)$r[$h['med_prix_m2_whole_maison']]]); $n++; }
    $db->commit(); fclose($fp);
    return $n < 25000 ? ['error' => "DVF incomplet ($n)"] : ['n' => $n];
}

function pd_import_annuaire(PDO $db, string $dir): array
{
    $all = [];
    foreach (array_keys(PD_LIEUX) as $t) {
        $u = PD_SRC['annuaire'] . '?' . http_build_query(['where' => 'search(pivot, "' . $t . '")', 'select' => 'id,nom,adresse,telephone,pivot,url_service_public']);
        if ($e = pd_fetch($u, "$dir/annuaire_$t.json", 1000, 180)) return ['error' => "$t : $e"];
        $a = json_decode((string)file_get_contents("$dir/annuaire_$t.json"), true);
        if (!is_array($a) || count($a) < 20) return ['error' => "$t : export vide"];
        foreach ($a as $r) {
            $piv = json_decode((string)($r['pivot'] ?? '[]'), true) ?: []; $ok = false; $com = [];
            foreach ($piv as $p) if (($p['type_service_local'] ?? '') === $t) { $ok = true; $com = array_merge($com, $p['code_insee_commune'] ?? []); }
            if (!$ok) continue;
            $ad = (json_decode((string)($r['adresse'] ?? '[]'), true) ?: [[]])[0] ?? [];
            $tel = (json_decode((string)($r['telephone'] ?? '[]'), true) ?: [[]])[0]['valeur'] ?? '';
            $all[] = [$r['id'], $t, (string)$r['nom'], trim(($ad['numero_voie'] ?? '') . ' ' . ($ad['complement1'] ?? '')), (string)($ad['code_postal'] ?? ''), (string)($ad['nom_commune'] ?? ''), is_numeric($ad['latitude'] ?? '') ? (float)$ad['latitude'] : null, is_numeric($ad['longitude'] ?? '') ? (float)$ad['longitude'] : null, (string)$tel, (string)($r['url_service_public'] ?? ''), implode(',', array_slice(array_unique($com), 0, 400))];
        }
    }
    if (count($all) < 2000) return ['error' => 'annuaire incomplet (' . count($all) . ')'];
    $db->beginTransaction(); $db->exec('DELETE FROM pd_lieux'); $i = $db->prepare('INSERT OR REPLACE INTO pd_lieux VALUES(?,?,?,?,?,?,?,?,?,?,?)'); foreach ($all as $r) $i->execute($r); $db->commit();
    return ['n' => count($all)];
}

// ---------- Rapprochement métiers du site ↔ familles professionnelles BMO (libellés, strict) ----------
function pd_stem(string $w): string
{
    foreach (['trices' => 'teur', 'trice' => 'teur', 'euses' => 'eur', 'euse' => 'eur', 'ieres' => 'ier', 'iere' => 'ier', 'eres' => 'er', 'ere' => 'er', 'iennes' => 'ien', 'ienne' => 'ien', 'antes' => 'ant', 'ante' => 'ant', 'entes' => 'ent', 'ente' => 'ent', 'ives' => 'if', 'ive' => 'if', 'aux' => 'al'] as $s => $r) if (strlen($w) > strlen($s) + 2 && str_ends_with($w, $s)) return substr($w, 0, -strlen($s)) . $r;
    if (strlen($w) > 4 && (str_ends_with($w, 's') || str_ends_with($w, 'x'))) $w = substr($w, 0, -1);
    if (strlen($w) > 5 && str_ends_with($w, 'e') && !str_ends_with($w, 'ee')) $w = substr($w, 0, -1);
    return $w;
}
function pd_tokens(string $s): array
{
    $stop = ['de', 'd', 'du', 'des', 'en', 'et', 'a', 'au', 'aux', 'la', 'le', 'les', 'l', 'pour', 'sur', 'dans', 'ou', 'h', 'f', 'hf', 'non', 'autres', 'autre'];
    return array_values(array_unique(array_map('pd_stem', array_filter(explode('-', slugify($s, 120)), fn($w) => $w !== '' && !in_array($w, $stop, true)))));
}
function pd_match_metiers(PDO $db): array
{
    // Rapprochement strict : tous les mots du métier dans un même segment du libellé BMO (« X et Y »), segment qui commence par le métier,
    // au plus 2 mots en plus, pas de niveau différent (maîtrise, cadres…), et un seul candidat possible sauf correspondance exacte.
    $level = array_map('pd_stem', ['maitrise', 'cadres', 'ingenieurs', 'techniciens', 'qualifies', 'peu', 'chefs', 'responsables', 'directeurs', 'superieurs', 'agents', 'sportifs', 'transports']);
    $segs = [];
    foreach ($db->query('SELECT DISTINCT fap, metier FROM pd_bmo') as $r) {
        $parts = preg_split('/\s+et\s+|,\s*|\s+ou\s+/u', preg_replace('/\(.*?\)/u', ' ', (string)$r['metier']));
        foreach ($parts as $p) if (($t = pd_tokens($p)) !== []) $segs[] = [$r['fap'], (string)$r['metier'], $t];
    }
    if (!$segs) return ['error' => 'BMO vide'];
    $db->beginTransaction(); $db->exec('DELETE FROM job_metier_fap'); $ins = $db->prepare('INSERT INTO job_metier_fap VALUES(?,?,?)'); $n = 0;
    foreach ($db->query('SELECT slug, name FROM job_metiers')->fetchAll() as $m) {
        if (in_array($m['slug'], ['agent-d-exploitation', 'agent-de-service', 'employe-polyvalent', 'chef-de-projet', 'agent-polyvalent'], true)) continue; // intitulés trop génériques
        $mt = pd_tokens((string)$m['name']); if (!$mt) continue;
        $cand = [];
        foreach ($segs as [$fap, $lab, $st]) {
            if ($st[0] !== $mt[0] || array_diff($mt, $st)) continue;
            $extra = array_diff($st, $mt);
            if (count($extra) > 2 || array_intersect($extra, $level) || (count($mt) === 1 && $extra)) continue;
            $x = count($extra); if (!isset($cand[$fap]) || $x < $cand[$fap][0]) $cand[$fap] = [$x, $lab];
        }
        if (!$cand) continue;
        uasort($cand, fn($a, $b) => $a[0] <=> $b[0]); $first = reset($cand);
        if (count($cand) > 1 && $first[0] > 0) continue; // plusieurs familles possibles : on n'affiche rien
        $zero = array_filter($cand, fn($c) => $c[0] === 0); if (count($zero) > 1) continue;
        $ins->execute([$m['slug'], (string)array_key_first($cand), $first[1]]); $n++;
    }
    $db->commit();
    return ['n' => $n];
}

// ---------- Affichage ----------
function pd_on(): bool { return function_exists('mod_on') && mod_on('donnees'); }
function pd_nf($x): string { return number_format((float)$x, 0, ',', ' '); }

// Bloc BMO d'une page métier (France ou département)
function pd_bmo_metier_html(PDO $db, string $mslug, string $mname, string $dep = '', string $hrefPrefix = ''): string
{
    if (!pd_on()) return '';
    try { pd_tables($db); $st = $db->prepare('SELECT fap, label FROM job_metier_fap WHERE mslug=?'); $st->execute([$mslug]); $f = $st->fetch(); } catch (Throwable $e) { return ''; }
    if (!$f) return '';
    $year = pd_meta('bmo_year');
    $st = $db->prepare('SELECT SUM(met) m, SUM(xmet) x, SUM(smet) s, SUM(CASE WHEN xmet IS NOT NULL THEN met END) mx, SUM(CASE WHEN smet IS NOT NULL THEN met END) ms FROM pd_bmo WHERE fap=?' . ($dep !== '' ? ' AND dep=?' : ''));
    $st->execute($dep !== '' ? [$f['fap'], $dep] : [$f['fap']]); $t = $st->fetch();
    if (!$t || (int)$t['m'] < 10) return '';
    $pd = $t['mx'] ? round(100 * $t['x'] / $t['mx']) : null; $ps = $t['ms'] ? round(100 * $t['s'] / $t['ms']) : null;
    $zone = $dep !== '' && defined('DEPTS') ? (DEPTS[$dep] ?? $dep) : 'France';
    $o = '<section class="box" style="margin:24px 0"><h2 style="margin-top:0">Projets de recrutement ' . h($year) . ' : ' . h($zone) . '</h2>'
        . '<p>Selon l\'enquête « Besoins en main-d\'œuvre » ' . h($year) . ' de France Travail, les employeurs ' . ($dep !== '' ? 'du département ' : '') . 'prévoient <strong>' . pd_nf($t['m']) . ' recrutements</strong> pour le métier « ' . h($f['label']) . ' »'
        . ($pd !== null ? ', dont <strong>' . $pd . ' %</strong> jugés difficiles' : '') . ($ps !== null ? ' et ' . $ps . ' % saisonniers' : '') . '.</p>';
    if ($dep === '') {
        $st = $db->prepare('SELECT dep, SUM(met) m FROM pd_bmo WHERE fap=? AND met IS NOT NULL GROUP BY dep ORDER BY m DESC LIMIT 8'); $st->execute([$f['fap']]);
        $li = []; foreach ($st as $r) { $dn = defined('DEPTS') ? (DEPTS[$r['dep']] ?? $r['dep']) : $r['dep']; $li[] = ($hrefPrefix !== '' ? '<a href="' . h($hrefPrefix . $r['dep'] . '#offres') . '">' . h($dn) . '</a>' : h($dn)) . ' (' . pd_nf($r['m']) . ')'; }
        if ($li) $o .= '<p><strong>Départements qui prévoient le plus de recrutements :</strong> ' . implode(', ', $li) . '.</p>';
    } else {
        $st = $db->prepare('SELECT bassin_nom, SUM(met) m FROM pd_bmo WHERE fap=? AND dep=? AND met IS NOT NULL GROUP BY bassin_nom ORDER BY m DESC LIMIT 6'); $st->execute([$f['fap'], $dep]);
        $li = []; foreach ($st as $r) $li[] = h(mb_convert_case(mb_strtolower((string)$r['bassin_nom']), MB_CASE_TITLE)) . ' (' . pd_nf($r['m']) . ')'; if ($li) $o .= '<p><strong>Par bassin d\'emploi :</strong> ' . implode(', ', $li) . '.</p>';
    }
    return $o . '<p style="font-size:13px;opacity:.75">Intentions déclarées par les employeurs en début d\'année, pas des offres publiées ni des embauches. « Difficiles » : recrutements que l\'employeur anticipe comme difficiles. Rattachement au métier par libellé.</p></section>';
}

function pd_city_commune(PDO $db, array $city): ?array
{
    pd_tables($db);
    $deps = defined('CSP_DEP_REG') && isset(CSP_DEP_REG[$city['region']]) ? explode(' ', CSP_DEP_REG[$city['region']]) : [];
    $st = $db->prepare('SELECT * FROM pd_communes WHERE slug=? ORDER BY pop DESC'); $st->execute([slugify((string)$city['name'], 80)]);
    foreach ($st as $c) if (!$deps || in_array($c['dep'], $deps, true)) return $c;
    return null;
}

// Bloc ville : vivre sur place (loyers, prix), où se faire accompagner, besoins du bassin d'emploi
function pd_city_html(PDO $db, array $city): string
{
    if (!pd_on()) return '';
    try { $c = pd_city_commune($db, $city); } catch (Throwable $e) { return ''; }
    if (!$c) return '';
    $o = '';
    $l = $db->prepare('SELECT * FROM pd_loyer WHERE insee=?'); $l->execute([$c['insee']]); $l = $l->fetch();
    $d = $db->prepare('SELECT * FROM pd_dvf WHERE insee=?'); $d->execute([$c['insee']]); $d = $d->fetch();
    $rows = '';
    if ($c['pop']) $rows .= '<tr><th>Population</th><td>' . pd_nf($c['pop']) . ' habitants <small>(INSEE via geo.api.gouv.fr)</small></td></tr>';
    if ($c['zename']) $rows .= '<tr><th>Zone d\'emploi</th><td>' . h($c['zename']) . ' <small>(INSEE, zonage 2020)</small></td></tr>';
    if ($l && $l['app']) $rows .= '<tr><th>Loyer d\'annonce moyen</th><td>' . number_format((float)$l['app'], 1, ',', ' ') . ' €/m² charges comprises (appartement)' . ($l['mai'] ? ', ' . number_format((float)$l['mai'], 1, ',', ' ') . ' €/m² (maison)' : '') . ' <small>(Carte des loyers ' . h(pd_meta('loyer_year')) . ', ministère du Logement' . ($l['typ'] === 'maille' ? ', estimation sur un ensemble de communes' : '') . ')</small></td></tr>';
    if ($d && ($d['n_app'] >= 20 || $d['n_mai'] >= 20)) $rows .= '<tr><th>Prix de vente médian</th><td>' . ($d['n_app'] >= 20 ? pd_nf($d['m_app']) . ' €/m² (appartement)' : '') . ($d['n_app'] >= 20 && $d['n_mai'] >= 20 ? ', ' : '') . ($d['n_mai'] >= 20 ? pd_nf($d['m_mai']) . ' €/m² (maison)' : '') . ' <small>(DVF, ventes des dernières années, Etalab)</small></td></tr>';
    if ($rows !== '') $o .= '<h2>Vivre à ' . h($city['name']) . '</h2><table class="pdt">' . $rows . '</table>';
    // lieux d'accompagnement : compétence géographique, sinon les plus proches
    $li = '';
    foreach (PD_LIEUX as $t => $lab) {
        $st = $db->prepare("SELECT * FROM pd_lieux WHERE type=? AND (',' || communes || ',') LIKE ? LIMIT 2"); $st->execute([$t, '%,' . $c['insee'] . ',%']); $rs = $st->fetchAll();
        if (!$rs && $c['lat'] !== null) { $st = $db->prepare('SELECT *, ((lat-?)*(lat-?) + (lon-?)*(lon-?)*0.48) d2 FROM pd_lieux WHERE type=? AND lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? ORDER BY d2 LIMIT ' . ($t === 'france_services' ? 2 : 1));
            $st->execute([$c['lat'], $c['lat'], $c['lon'], $c['lon'], $t, $c['lat'] - 0.3, $c['lat'] + 0.3, $c['lon'] - 0.45, $c['lon'] + 0.45]); $rs = $st->fetchAll(); }
        foreach ($rs as $r) $li .= '<li><strong>' . h($r['nom']) . '</strong> — ' . h(trim($r['adresse'] . ', ' . $r['cp'] . ' ' . $r['ville'], ' ,')) . ($r['tel'] !== '' ? ' · ' . h($r['tel']) : '') . ($r['url'] !== '' ? ' · <a href="' . h($r['url']) . '" rel="nofollow noopener" target="_blank">horaires et contact</a>' : '') . '</li>';
    }
    if ($li !== '') $o .= '<h2>Se faire accompagner près de ' . h($city['name']) . '</h2><ul>' . $li . '</ul><p style="font-size:13px;opacity:.75">Source : Annuaire de l\'administration (service-public.gouv.fr).</p>';
    // BMO : bassin dont le nom correspond à la zone d'emploi ou à la commune
    $year = pd_meta('bmo_year');
    $st = $db->prepare('SELECT bassin, bassin_nom FROM pd_bmo WHERE dep=? GROUP BY bassin'); $st->execute([$c['dep']]);
    $bas = null; foreach ($st as $b) { $bn = slugify((string)$b['bassin_nom'], 80); if ($bn === slugify((string)$c['zename'], 80) || $bn === slugify((string)$c['name'], 80) || $bn === 'bassin-de-' . slugify((string)$c['name'], 80)) { $bas = $b; break; } }
    if ($bas) {
        $st = $db->prepare('SELECT metier, SUM(met) m, SUM(xmet) x FROM pd_bmo WHERE dep=? AND bassin=? AND met IS NOT NULL GROUP BY fap ORDER BY m DESC LIMIT 8'); $st->execute([$c['dep'], $bas['bassin']]); $top = $st->fetchAll();
        $tt = $db->prepare('SELECT SUM(met) FROM pd_bmo WHERE dep=? AND bassin=?'); $tt->execute([$c['dep'], $bas['bassin']]); $tot = (int)$tt->fetchColumn();
        if ($top && $tot) $o .= '<h2>Métiers recherchés dans le bassin d\'emploi</h2><p>Enquête « Besoins en main-d\'œuvre » ' . h($year) . ' (France Travail), bassin ' . h(mb_convert_case(mb_strtolower((string)$bas['bassin_nom']), MB_CASE_TITLE)) . ' : <strong>' . pd_nf($tot) . ' projets de recrutement</strong> déclarés par les employeurs. Les plus nombreux :</p><ul>'
            . implode('', array_map(fn($r) => '<li>' . h($r['metier']) . ' : ' . pd_nf($r['m']) . ($r['x'] !== null && $r['m'] ? ' (' . round(100 * $r['x'] / $r['m']) . ' % jugés difficiles)' : '') . '</li>', $top)) . '</ul><p style="font-size:13px;opacity:.75">Intentions de recrutement, pas des offres publiées.</p>';
    }
    return $o === '' ? '' : '<section class="pdc" style="margin:24px 0">' . $o . '<style>.pdt{width:100%;border-collapse:collapse}.pdt th,.pdt td{text-align:left;vertical-align:top;padding:7px 6px;border-top:1px solid var(--b)}.pdt th{width:32%}.pdt small{opacity:.7}</style></section>';
}

// ---------- Fiches entreprises (API Recherche d'entreprises : gratuite, sans clé, 7 appels/s maxi) ----------
function pd_norm_co(string $s): string
{
    $s = ' ' . str_replace('-', ' ', slugify($s, 120)) . ' ';
    $s = preg_replace('/ (sas|sasu|sarl|eurl|sa|sca|snc|scop|sci|selarl|ste|societe|groupe|group|france|interim|fr) /', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}
function pd_sirene_batch(PDO $db, int $max = 300): array
{
    pd_tables($db); $ok = 0; $no = 0; $err = 0; $t0 = time();
    $rows = $db->query("SELECT c.slug, c.name, c.regions FROM job_companies c LEFT JOIN pd_company p ON p.slug=c.slug WHERE c.n >= 3 AND (p.slug IS NULL OR p.checked_at < datetime('now','-90 days')) ORDER BY c.n DESC LIMIT $max")->fetchAll();
    $up = $db->prepare('INSERT OR REPLACE INTO pd_company VALUES(?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($rows as $c) {
        if (time() - $t0 > 1200 || $err > 20) break;
        $name = trim((string)$c['name'], "\"' "); $dq = $db->prepare("SELECT e_dep, COUNT(*) n FROM jobs WHERE company >= ? AND company < ? AND status='open' AND e_dep<>'' GROUP BY e_dep ORDER BY n DESC LIMIT 1"); $dq->execute([$c['name'], $c['name'] . '~']); $dep = (string)$dq->fetchColumn();
        $q = ['q' => $name, 'per_page' => 10, 'etat_administratif' => 'A'] + ($dep !== '' && !str_starts_with($dep, '97') ? ['departement' => $dep] : []);
        $ch = curl_init('https://recherche-entreprises.api.gouv.fr/search?' . http_build_query($q)); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => 'recruteur.eu/1.0']);
        $b = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); usleep(200000);
        if ($code === 429) { sleep(5); $err++; continue; }
        if ($code !== 200) { $err++; continue; }
        $res = json_decode($b, true)['results'] ?? []; $n0 = pd_norm_co($name); $hit = [];
        foreach ($res as $r) {
            $names = array_filter([$r['nom_complet'] ?? '', $r['nom_raison_sociale'] ?? '', $r['sigle'] ?? '']);
            $same = false; foreach ($names as $nm) if ($n0 !== '' && pd_norm_co((string)$nm) === $n0) $same = true;
            $emp = !in_array((string)($r['tranche_effectif_salarie'] ?? ''), ['', 'NN', '00'], true);
            if ($same && $emp) $hit[$r['siren']] = $r;
        }
        if (count($hit) === 1) { $r = reset($hit); $s = $r['siege'] ?? [];
            $up->execute([$c['slug'], 1, $r['siren'], (string)$r['nom_complet'], (string)($r['activite_principale'] ?? ''), (string)($r['tranche_effectif_salarie'] ?? ''), (string)($r['categorie_entreprise'] ?? ''), (string)($r['date_creation'] ?? ''), trim(($s['code_postal'] ?? '') . ' ' . ($s['libelle_commune'] ?? '')), (int)($r['nombre_etablissements_ouverts'] ?? 0), now()]); $ok++; }
        else { $up->execute([$c['slug'], 0, '', '', '', '', '', '', '', 0, now()]); $no++; }
    }
    return ['identifiees' => $ok, 'non_identifiees' => $no, 'erreurs' => $err];
}

function pd_company_html(PDO $db, array $c): string
{
    if (!pd_on()) return '';
    try { pd_tables($db); $st = $db->prepare('SELECT * FROM pd_company WHERE slug=? AND ok=1'); $st->execute([$c['slug']]); $p = $st->fetch(); } catch (Throwable $e) { return ''; }
    if (!$p) return '';
    $naf = ''; if ($p['naf'] !== '') { $q = $db->prepare('SELECT label FROM pd_naf WHERE code=?'); $q->execute([$p['naf']]); $naf = trim((string)$q->fetchColumn()); }
    $rows = '<tr><th>Raison sociale</th><td>' . h($p['nom']) . ' — SIREN ' . h(trim(chunk_split((string)$p['siren'], 3, ' '))) . '</td></tr>'
        . ($naf !== '' ? '<tr><th>Activité</th><td>' . h($naf) . ' (' . h($p['naf']) . ')</td></tr>' : '')
        . (isset(PD_TRANCHES[$p['tranche']]) ? '<tr><th>Effectif</th><td>' . h(PD_TRANCHES[$p['tranche']]) . ($p['cat'] !== '' ? ' · catégorie ' . h($p['cat']) : '') . '</td></tr>' : '')
        . ($p['created'] !== '' ? '<tr><th>Création</th><td>' . h(date_fr((string)$p['created'])) . '</td></tr>' : '')
        . ($p['siege'] !== '' ? '<tr><th>Siège</th><td>' . h((string)$p['siege']) . ($p['n_etab'] > 1 ? ' · ' . pd_nf($p['n_etab']) . ' établissements ouverts' : '') . '</td></tr>' : '');
    $agency = str_starts_with((string)$p['naf'], '78.') ? '<p>Il s\'agit d\'une <strong>agence d\'emploi</strong> : les postes proposés sont souvent situés chez des entreprises clientes ; l\'annonce ou l\'agence précise l\'entreprise d\'accueil.</p>' : '';
    return '<section class="box" style="margin:24px 0"><h2 style="margin-top:0">Fiche entreprise</h2><table class="pdt">' . $rows . '</table>' . $agency
        . '<p style="font-size:13px;opacity:.75">Source : INSEE (Sirene) via l\'API Recherche d\'entreprises. Rapprochement automatique par le nom de l\'employeur et le département de ses offres : <a href="https://annuaire-entreprises.data.gouv.fr/entreprise/' . h($p['siren']) . '" rel="nofollow noopener" target="_blank">vérifier la fiche</a>.</p>'
        . '<style>.pdt{width:100%;border-collapse:collapse}.pdt th,.pdt td{text-align:left;vertical-align:top;padding:7px 6px;border-top:1px solid var(--b)}.pdt th{width:32%}</style></section>';
}
