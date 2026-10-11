<?php
// Référentiel ROME 4.0 (France Travail, open data) : fiches, appellations, mobilités (métiers proches), compétences, métiers réglementés.
declare(strict_types=1);

const ROME_URL = 'https://api.francetravail.fr/api-nomenclatureemploi/v1/open-data/csv';

function rome_tables(PDO $db): void
{
    static $d = false; if ($d) return; $d = true;
    $db->exec("CREATE TABLE IF NOT EXISTS rome_codes(code TEXT PRIMARY KEY, label TEXT, regl INTEGER, cadre INTEGER, def TEXT, acces TEXT);
        CREATE TABLE IF NOT EXISTS rome_app(k TEXT, code TEXT, label TEXT, rare INTEGER, PRIMARY KEY(k, code));
        CREATE TABLE IF NOT EXISTS rome_mob(code TEXT, cible TEXT, ord INTEGER, PRIMARY KEY(code, cible));
        CREATE TABLE IF NOT EXISTS rome_comp(code TEXT, ogr TEXT, coeur INTEGER, PRIMARY KEY(code, ogr));
        CREATE TABLE IF NOT EXISTS rome_item(ogr TEXT PRIMARY KEY, label TEXT, rub TEXT);
        CREATE INDEX IF NOT EXISTS ix_rome_comp_ogr ON rome_comp(ogr)");
}
function rome_ready(PDO $db): bool { rome_tables($db); static $r = null; return $r ??= (bool)$db->query('SELECT 1 FROM rome_codes LIMIT 1')->fetchColumn(); }
function rome_txt(string $s): string { return trim(str_replace("''", "'", $s)); }

// Clés de rapprochement d'un intitulé : « Vendeur / Vendeuse en boulangerie » → vendeur-en-boulangerie, vendeuse-en-boulangerie
function rome_keys(string $label): array
{
    $t = preg_replace('/\((?:[^()]*)\)|\[(?:[^\[\]]*)\]/u', ' ', $label);
    $t = preg_replace('#\b[hfdxn]\s*/\s*[hfdxn](\s*/\s*[hfdxn])?\b#iu', ' ', $t);
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    $out = [];
    if (preg_match('#^(.*?)\s/\s(.*)$#u', $t, $m)) {
        $lw = preg_split('/\s+/u', trim($m[1])); $rw = preg_split('/\s+/u', trim($m[2]));
        $tail = implode(' ', array_slice($rw, count($lw)));
        $out[] = trim(implode(' ', $lw) . ' ' . $tail);
        $out[] = trim(implode(' ', array_slice($rw, 0, count($lw))) . ' ' . $tail);
    } else $out[] = $t;
    return array_values(array_unique(array_filter(array_map(fn($x) => slugify($x, 80), $out))));
}

function rome_of_title(PDO $db, string $title): string
{
    static $ex = null, $pf = null, $memo = [];
    $ex ??= $db->prepare('SELECT code FROM rome_app WHERE k=? ORDER BY rare, code LIMIT 1');
    $pf ??= $db->prepare('SELECT code, COUNT(*) c FROM rome_app WHERE k >= ? AND k < ? AND rare=0 GROUP BY code ORDER BY c DESC LIMIT 2');
    $look = function (string $k) use ($ex, $pf, &$memo): string {
        if (isset($memo[$k])) return $memo[$k];
        $ex->execute([$k]); $c = (string)$ex->fetchColumn();
        if ($c === '') { $pf->execute([$k . '-', $k . '.']); $r = $pf->fetchAll(); if ($r && (count($r) === 1 || $r[0]['c'] >= 2 * $r[1]['c'])) $c = (string)$r[0]['code']; }
        return $memo[$k] = $c;
    };
    $keys = [];
    if (function_exists('metier_of') && ($m = metier_of($title))) $keys[] = $m[0];
    foreach (rome_keys(preg_replace('/\s[-–|:,]\s.*$/u', '', $title)) as $k) $keys[] = $k;
    foreach (array_unique($keys) as $k) {
        $w = explode('-', $k);
        for ($n = count($w); $n >= 1; $n--) {
            $kk = implode('-', array_slice($w, 0, $n));
            if (in_array($w[$n - 1], ['de', 'd', 'du', 'des', 'en', 'et', 'a', 'au', 'aux', 'la', 'le', 'les', 'l', 'pour', 'h', 'f'], true) || strlen($kk) < 4) continue;
            if (($c = $look($kk)) !== '') return $c;
        }
    }
    return '';
}

// Import (cron trimestriel) : en cas d'échec, l'ancienne version reste en place.
function rome_import(PDO $db, string $dir): array
{
    rome_tables($db);
    $zip = $dir . '/rome.zip';
    $ch = curl_init(ROME_URL); $fh = fopen($zip . '.tmp', 'w');
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180, CURLOPT_USERAGENT => 'recruteur.eu/1.0']);
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); fclose($fh);
    if ($code !== 200 || filesize($zip . '.tmp') < 1000000) { @unlink($zip . '.tmp'); return ['error' => "téléchargement ROME HTTP $code"]; }
    rename($zip . '.tmp', $zip);
    $z = new ZipArchive(); if ($z->open($zip) !== true) return ['error' => 'zip ROME illisible'];
    $read = function (string $prefix) use ($z): array {
        for ($i = 0; $i < $z->numFiles; $i++) { $n = $z->getNameIndex($i); if (str_starts_with($n, $prefix)) {
            $fp = $z->getStream($n); $h = fgetcsv($fp, 0, ',', '"', ''); $rows = [];
            while (($r = fgetcsv($fp, 0, ',', '"', '')) !== false) if (count($r) === count($h)) $rows[] = array_combine($h, $r);
            fclose($fp); return $rows; } }
        return [];
    };
    $codes = $read('unix_referentiel_code_rome_v'); $apps = $read('unix_referentiel_appellation_v'); $mob = $read('unix_rubrique_mobilite_v');
    $txt = $read('unix_texte_v'); $coh = $read('unix_coherence_item_v'); $items = $read('unix_item_v');
    if (count($codes) < 1000 || count($apps) < 10000 || count($mob) < 5000) return ['error' => 'fichiers ROME incomplets', 'codes' => count($codes), 'apps' => count($apps)];
    $def = []; $acc = [];
    foreach ($txt as $t) { $k = $t['code_rome']; if ($t['libelle_type_texte'] === 'definition') $def[$k][(int)$t['position_phrase']] = rome_txt($t['libelle_texte']); elseif ($t['libelle_type_texte'] === 'acces_metier') $acc[$k][(int)$t['position_phrase']] = rome_txt($t['libelle_texte']); }
    $db->beginTransaction();
    foreach (['rome_codes', 'rome_app', 'rome_mob', 'rome_comp', 'rome_item'] as $t) $db->exec("DELETE FROM $t");
    $i = $db->prepare('INSERT OR REPLACE INTO rome_codes VALUES(?,?,?,?,?,?)');
    foreach ($def as &$x) ksort($x); unset($x); foreach ($acc as &$x) ksort($x); unset($x);
    foreach ($codes as $c) { $k = $c['code_rome'];
        $i->execute([$k, rome_txt($c['libelle_rome']), $c['emploi_reglemente'] === 'O' ? 1 : 0, $c['emploi_cadre'] === 'O' ? 1 : 0, implode(' ', array_slice($def[$k] ?? [], 0, 3)), implode(' ', $acc[$k] ?? [])]); }
    $i = $db->prepare('INSERT OR IGNORE INTO rome_app VALUES(?,?,?,?)');
    foreach ($apps as $a) foreach (array_merge(rome_keys(rome_txt($a['libelle_appellation_long'])), rome_keys(rome_txt($a['libelle_appellation_court']))) as $k) $i->execute([$k, $a['code_rome'], rome_txt($a['libelle_appellation_court']), $a['peu_usite'] === 'O' ? 1 : 0]);
    foreach ($codes as $c) foreach (rome_keys(rome_txt($c['libelle_rome'])) as $k) $i->execute([$k, $c['code_rome'], rome_txt($c['libelle_rome']), 0]);
    $i = $db->prepare('INSERT OR IGNORE INTO rome_mob VALUES(?,?,?)');
    foreach ($mob as $m) $i->execute([$m['code_rome'], $m['code_rome_cible'], (int)$m['numero_ordre']]);
    $i = $db->prepare('INSERT OR IGNORE INTO rome_comp VALUES(?,?,?)');
    foreach ($coh as $c) $i->execute([$c['code_rome'], $c['code_ogr'], $c['coeur_metier'] === 'Principale' ? 1 : 0]);
    $i = $db->prepare('INSERT OR IGNORE INTO rome_item VALUES(?,?,?)');
    foreach ($items as $it) $i->execute([$it['code_ogr'], rome_txt($it['libelle']), $it['code_rubrique']]);
    $db->commit();
    setting_set('rome_version', date('Y-m-d'));
    return ['codes' => count($codes), 'apps' => count($apps), 'mob' => count($mob), 'comp' => count($coh)];
}

// Métiers proches d'un métier du site : mobilités ROME → métiers du site rattachés au code cible, avec compétences communes.
function rome_close_html(array $site, PDO $db, string $mslug, string $mname, string $region = '', string $dep = ''): string
{
    if (!mod_on('rome') || !rome_ready($db)) return '';
    $st = $db->prepare('SELECT rome FROM job_metier_rome WHERE mslug=?'); $st->execute([$mslug]); $code = (string)$st->fetchColumn();
    if ($code === '') return '';
    $src = $db->prepare('SELECT * FROM rome_codes WHERE code=?'); $src->execute([$code]); $src = $src->fetch();
    $st = $db->prepare("SELECT mb.cible, mb.ord, rc.label, rc.regl, rc.acces, mr.mslug, jm.name, jm.n
        FROM rome_mob mb JOIN rome_codes rc ON rc.code=mb.cible
        LEFT JOIN job_metier_rome mr ON mr.rome=mb.cible LEFT JOIN job_metiers jm ON jm.slug=mr.mslug
        WHERE mb.code=? ORDER BY mb.ord, jm.n DESC");
    $st->execute([$code]);
    $cnt = $db->prepare("SELECT COUNT(*) FROM rome_comp a JOIN rome_comp b ON b.ogr=a.ogr AND b.code=? WHERE a.code=?");
    $seen = []; $rows = [];
    foreach ($st as $r) { if (isset($seen[$r['cible']]) || $r['cible'] === $code) continue; $seen[$r['cible']] = 1; $cnt->execute([$r['cible'], $code]); $r['nc'] = (int)$cnt->fetchColumn(); if ($r['nc'] >= 3) $rows[] = $r; if (count($seen) >= 25) break; }
    usort($rows, fn($x, $y) => [$y['mslug'] ? 1 : 0, $y['nc']] <=> [$x['mslug'] ? 1 : 0, $x['nc']]);
    $rows = array_slice($rows, 0, 7);
    if (!$rows) return '';
    $cmp = $db->prepare("SELECT i.label FROM rome_comp a JOIN rome_comp b ON b.ogr=a.ogr AND b.code=? JOIN rome_item i ON i.ogr=a.ogr WHERE a.code=? AND i.rub='1' ORDER BY (a.coeur+b.coeur) DESC LIMIT 3");
    $nOpen = $db->prepare("SELECT COUNT(*) FROM jobs j JOIN job_metier_map mm ON mm.id=j.id WHERE mm.mslug=? AND j.status='open'" . ($dep !== '' ? ' AND j.e_dep=?' : ($region !== '' ? ' AND j.region=?' : '')));
    $li = '';
    foreach ($rows as $r) {
        $cmp->execute([$r['cible'], $code]); $common = $cmp->fetchAll(PDO::FETCH_COLUMN);
        $nc = (int)$r['nc'];
        $label = $r['name'] ?: $r['label'];
        $open = '';
        if ($r['mslug']) { $nOpen->execute(array_values(array_filter([$r['mslug'], $dep !== '' ? $dep : ($region !== '' ? $region : null)], fn($x) => $x !== null))); $no = (int)$nOpen->fetchColumn(); $open = $no ? ' — <a href="/emploi/' . h($r['mslug']) . '/">' . number_format($no, 0, ',', ' ') . ' offre' . ($no > 1 ? 's' : '') . ($dep !== '' ? ' dans le département' : ($region !== '' ? ' dans la région' : '')) . '</a>' : ''; }
        $req = '';
        if ((int)$r['regl'] === 1) { $acc = trim((string)$r['acces']); $req = '<br><small>⚠️ Métier réglementé' . ($acc !== '' ? ' : ' . h(mb_strimwidth($acc, 0, 220, '…')) : ' : un diplôme ou une habilitation est exigé pour l\'exercer.') . '</small>'; }
        $li .= '<li><strong>' . h($label) . '</strong>' . $open . ($nc ? '<br><small>' . $nc . ' compétence' . ($nc > 1 ? 's' : '') . ' en commun' . ($common ? ', par exemple : ' . h(implode(' ; ', $common)) : '') . '.</small>' : '') . $req . '</li>';
    }
    return '<section class="box" style="margin:24px 0"><h2 style="margin-top:0">Métiers proches ' . h(metier_de(mb_strtolower($mname))) . '</h2>'
        . '<p style="font-size:14px;opacity:.85">Passerelles du répertoire des métiers de France Travail (ROME, fiche ' . h($code) . ' « ' . h((string)($src['label'] ?? '')) . ' »). Des compétences communes ne suffisent pas toujours : certains métiers exigent un diplôme ou une habilitation.</p><ul>' . $li . '</ul></section>';
}
