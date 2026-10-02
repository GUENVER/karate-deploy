<?php
// Données La bonne alternance / API Apprentissage complémentaires aux offres : formations en apprentissage,
// missions locales, entreprises qui recrutent en alternance. Affichées sur les pages ville et les offres d'alternance.
declare(strict_types=1);

function lba_tables(PDO $db): void
{
    static $done = [];
    $k = spl_object_id($db);
    if (!empty($done[$k])) return;
    $done[$k] = 1;
    $db->exec("CREATE TABLE IF NOT EXISTS lba_job_meta(id TEXT PRIMARY KEY, romes TEXT, lat REAL, lon REAL);
        CREATE TABLE IF NOT EXISTS lba_recruteurs(siret TEXT, name TEXT, naf TEXT, size TEXT, city TEXT, cname TEXT, postal TEXT, region TEXT, lat REAL, lon REAL, url TEXT);
        CREATE INDEX IF NOT EXISTS ix_lba_rec_city ON lba_recruteurs(region, cname);
        CREATE TABLE IF NOT EXISTS lba_formations(id TEXT PRIMARY KEY, title TEXT, sigle TEXT, niveau INTEGER, organisme TEXT, city TEXT, cname TEXT, postal TEXT, dep TEXT, region TEXT,
            lat REAL, lon REAL, romes TEXT, duree TEXT, debut TEXT, url TEXT, objectif TEXT);
        CREATE INDEX IF NOT EXISTS ix_lba_form_city ON lba_formations(region, cname);
        CREATE INDEX IF NOT EXISTS ix_lba_form_dep ON lba_formations(dep);
        CREATE TABLE IF NOT EXISTS lba_missions(id TEXT PRIMARY KEY, nom TEXT, adresse TEXT, cp TEXT, ville TEXT, lat REAL, lon REAL, email TEXT, tel TEXT, web TEXT);");
}

function lba_dist(float $la1, float $lo1, float $la2, float $lo2): float
{
    $r = M_PI / 180;
    return 6371 * acos(min(1, sin($la1 * $r) * sin($la2 * $r) + cos($la1 * $r) * cos($la2 * $r) * cos(($lo2 - $lo1) * $r)));
}

// Centre approximatif d'une ville : moyenne des positions connues (formations puis entreprises).
function lba_city_center(PDO $db, string $cname, string $region): ?array
{
    foreach (['lba_formations', 'lba_recruteurs'] as $t) {
        $st = $db->prepare("SELECT AVG(lat) la, AVG(lon) lo, COUNT(*) n FROM $t WHERE region=? AND cname=? AND lat IS NOT NULL");
        $st->execute([$region, $cname]);
        $r = $st->fetch();
        if ($r && (int)$r['n'] > 0) return [(float)$r['la'], (float)$r['lo']];
    }
    return null;
}

function lba_mission_block(PDO $db, ?array $center, string $cname): string
{
    if (!$center) return '';
    $best = null; $bd = 1e9;
    foreach ($db->query('SELECT * FROM lba_missions WHERE lat IS NOT NULL') as $m) {
        $d = lba_dist($center[0], $center[1], (float)$m['lat'], (float)$m['lon']);
        if ($d < $bd) { $bd = $d; $best = $m; }
    }
    if (!$best || $bd > 60) return '';
    $web = $best['web'] && preg_match('#^https?://#', (string)$best['web']) ? '<a href="' . h($best['web']) . '" rel="nofollow noopener" target="_blank">Site de la mission locale</a>' : '';
    return '<h2>Mission locale près de ' . h($cname) . '</h2><div class="box" style="border:1px solid var(--b);border-radius:12px;padding:14px 18px">'
        . '<p style="margin:0 0 6px"><strong>Mission locale ' . h(mb_convert_case(mb_strtolower((string)$best['nom']), MB_CASE_TITLE)) . '</strong></p>'
        . '<p style="margin:0">' . h(trim($best['adresse'] . ', ' . $best['cp'] . ' ' . mb_convert_case(mb_strtolower((string)$best['ville']), MB_CASE_TITLE), ' ,')) . '</p>'
        . '<p style="margin:6px 0 0">' . implode(' · ', array_filter([$best['tel'] ? 'Tél. ' . h($best['tel']) : '', $best['email'] ? '<a href="mailto:' . h($best['email']) . '">' . h($best['email']) . '</a>' : '', $web])) . '</p>'
        . '<p class="disc" style="margin-top:8px">Les missions locales accompagnent gratuitement les 16-25 ans : emploi, alternance, formation, logement, mobilité.</p></div>';
}

function lba_formation_list(array $rows, string $title): string
{
    if (!$rows) return '';
    $li = '';
    foreach ($rows as $f) {
        $meta = array_filter([$f['sigle'], $f['organisme'], $f['city'], $f['duree']]);
        $t = h(mb_strtoupper(mb_substr((string)$f['title'], 0, 1)) . mb_substr((string)$f['title'], 1));
        $li .= '<li>' . ($f['url'] ? '<a href="' . h($f['url']) . '" rel="nofollow noopener" target="_blank">' . $t . '</a>' : $t) . '<br><small>' . h(implode(' · ', $meta)) . '</small></li>';
    }
    return '<h2>' . h($title) . '</h2><ul>' . $li . '</ul><p class="disc">Formations en apprentissage : catalogue national (Réseau des Carif-Oref, ONISEP), via La bonne alternance.</p>';
}

function lba_city_blocks(array $site, array $city): string
{
    $db = jobs_db($site['host']);
    lba_tables($db);
    $cname = (string)$city['name']; $reg = (string)$city['region'];
    $center = lba_city_center($db, $cname, $reg);
    $out = lba_mission_block($db, $center, $cname);
    // formations en apprentissage dans la ville (sinon autour)
    $st = $db->prepare('SELECT * FROM lba_formations WHERE region=? AND cname=? GROUP BY title, organisme ORDER BY niveau, title LIMIT 15');
    $st->execute([$reg, $cname]);
    $rows = $st->fetchAll();
    $out .= lba_formation_list($rows, 'Formations en alternance à ' . $cname);
    // entreprises qui recrutent en alternance
    $st = $db->prepare('SELECT COUNT(*) FROM lba_recruteurs WHERE region=? AND cname=?'); $st->execute([$reg, $cname]);
    $nRec = (int)$st->fetchColumn();
    if ($nRec) {
        $order = "CASE WHEN size LIKE '%1000%' OR size LIKE '%2000%' OR size LIKE '%5000%' THEN 1 WHEN size LIKE '%500%' OR size LIKE '%250%' THEN 2 WHEN size LIKE '%200%' OR size LIKE '%100%' THEN 3 WHEN size LIKE '%50%' THEN 4 ELSE 5 END";
        $st = $db->prepare("SELECT * FROM lba_recruteurs WHERE region=? AND cname=? AND name<>'' GROUP BY siret ORDER BY $order, name LIMIT 20");
        $st->execute([$reg, $cname]);
        $li = '';
        foreach ($st as $r) $li .= '<li><a href="' . h($r['url']) . '" rel="nofollow noopener" target="_blank">' . h(mb_convert_case(mb_strtolower((string)$r['name']), MB_CASE_TITLE)) . '</a><br><small>' . h(implode(' · ', array_filter([$r['naf'], $r['size'] ? $r['size'] . (stripos((string)$r['size'], 'sal') === false ? ' salariés' : '') : '']))) . '</small></li>';
        $out .= '<h2>Entreprises qui recrutent en alternance à ' . h($cname) . '</h2><p>' . number_format($nRec, 0, ',', ' ')
            . ' entreprises de ' . h($cname) . ' sont identifiées comme susceptibles d\'embaucher un alternant, même sans offre publiée : une candidature spontanée a de vraies chances d\'aboutir. Sélection :</p><ul>' . $li . '</ul>'
            . '<p class="disc">Source : La bonne alternance (algorithme de prédiction des recrutements en alternance). Le lien permet de candidater directement.</p>';
    }
    return $out;
}

// Offre d'alternance : formations liées au métier à proximité + mission locale.
function lba_job_blocks(array $site, array $j): string
{
    if (($j['contract'] ?? '') !== 'alternance') return '';
    $db = jobs_db($site['host']);
    lba_tables($db);
    $st = $db->prepare('SELECT romes, lat, lon FROM lba_job_meta WHERE id=?'); $st->execute([$j['id']]);
    $m = $st->fetch();
    $cname = job_city_norm((string)$j['city']);
    $center = $m && $m['lat'] !== null ? [(float)$m['lat'], (float)$m['lon']] : lba_city_center($db, $cname, (string)$j['region']);
    $rows = [];
    $dep = str_starts_with((string)$j['postal'], '97') ? substr((string)$j['postal'], 0, 3) : substr((string)$j['postal'], 0, 2);
    if ($m && $m['romes'] && $dep !== '') {
        $romes = array_slice(array_filter(explode(',', (string)$m['romes'])), 0, 6);
        // code ROME exact, puis même famille de métiers (3 premiers caractères), dans le département
        foreach ([$romes, array_unique(array_map(fn($r) => substr($r, 0, 3), $romes))] as $codes) {
            $like = implode(' OR ', array_fill(0, count($codes), 'romes LIKE ?'));
            $st = $db->prepare("SELECT * FROM lba_formations WHERE dep=? AND ($like) GROUP BY title, organisme LIMIT 40");
            $st->execute(array_merge([$dep], array_map(fn($r) => "%$r%", $codes)));
            if ($rows = $st->fetchAll()) break;
        }
        if ($center) usort($rows, fn($a, $b) => lba_dist($center[0], $center[1], (float)$a['lat'], (float)$a['lon']) <=> lba_dist($center[0], $center[1], (float)$b['lat'], (float)$b['lon']));
        $rows = array_slice($rows, 0, 8);
    }
    return lba_formation_list($rows, 'Formations en alternance pour ce métier près de ' . ($cname ?: 'chez vous'))
        . lba_mission_block($db, $center, $cname ?: (string)$j['city']);
}
