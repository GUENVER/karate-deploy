<?php
// Pages métier (/emploi/<métier>/) et métier + ville (/emploi/<métier>/<ville>/), construites à partir des intitulés
// d'offres normalisés (« Aide-soignant H/F - SSIAD » → « Aide-soignant »). Reconstruit par cli/metiers_build.php.
declare(strict_types=1);

const METIER_MIN = 30;       // offres ouvertes minimum pour une page métier
const METIER_CITY_MIN = 6;   // offres minimum pour une page métier + ville

function metier_tables(PDO $db): void
{
    static $done = [];
    if (!empty($done[spl_object_id($db)])) return;
    $done[spl_object_id($db)] = 1;
    $db->exec('CREATE TABLE IF NOT EXISTS job_metiers(slug TEXT PRIMARY KEY, name TEXT, n INTEGER, updated TEXT);
        CREATE TABLE IF NOT EXISTS job_metier_map(id TEXT PRIMARY KEY, mslug TEXT, cslug TEXT);
        CREATE INDEX IF NOT EXISTS ix_jmm_m ON job_metier_map(mslug, cslug);
        CREATE TABLE IF NOT EXISTS job_metier_cities(mslug TEXT, cslug TEXT, n INTEGER, PRIMARY KEY(mslug, cslug));');
}

// Intitulé d'offre → [clé, libellé] du métier, ou null.
function metier_of(string $title): ?array
{
    $t = ' ' . trim(preg_replace('/\s+/u', ' ', $title)) . ' ';
    $t = preg_replace('/^\s*(alternance|apprenti(e)?|stage|stagiaire|cdi|cdd|int[ée]rim|job [ée]tudiant)\s*[-–:|]\s*/iu', ' ', $t);
    $t = preg_replace('/\((?:[^()]*)\)|\[(?:[^\[\]]*)\]/u', ' ', $t);                       // (H/F), (e), [CDI]
    $t = preg_replace('#\b[hfdxn]\s*/\s*[hfdxn](\s*/\s*[hfdxn])?\b#iu', ' ', $t);              // H/F, F/H/X
    $t = preg_replace('/\s[-–|:,]\s.*$/u', ' ', $t);                                           // « - SSIAD », « | Paris »
    // « Vendeur / Vendeuse en boulangerie » → « Vendeur en boulangerie » (on retire la forme féminine, on garde le complément)
    if (preg_match('#^(.*?)\s/\s(.*)$#u', trim($t), $sl)) {
        $lw = preg_split('/\s+/u', trim($sl[1])); $rw = preg_split('/\s+/u', trim($sl[2]));
        $t = ' ' . implode(' ', $lw) . ' ' . implode(' ', array_slice($rw, count($lw))) . ' ';
    }
    $t = preg_replace('/\b(cdi|cdd|int[ée]rim|en alternance|alternance|apprentissage|temps (plein|partiel)|\d+\s*(h|mois|ans?)\b.*|confirm[ée]e?s?|exp[ée]riment[ée]\.?e?s?|junior|senior|débutant(e)?\s*accepté(e)?|h\s*-\s*f)\b/iu', ' ', $t);
    $t = preg_replace('/\.e\b|\(e\)|·e\b/u', '', $t);
    $t = trim(preg_replace('/[\s.\-]+$|^[\s.\-]+/u', '', preg_replace('/\s+/u', ' ', $t)));
    if (mb_strlen($t) < 3 || mb_strlen($t) > 60 || preg_match('/^\d/u', $t)) return null;
    $key = slugify($t, 60);
    if ($key === '' || substr_count($key, '-') > 6 || preg_match('/-(de|d|du|des|en|et|a|au|aux|la|le|les|pour)$/', $key)) return null;
    // intitulés trop génériques pour faire une page métier utile
    if (in_array($key, ['assistant', 'agent', 'employe', 'technicien', 'operateur', 'ouvrier', 'preparateur', 'conducteur', 'responsable', 'charge', 'chef', 'gestionnaire', 'conseiller', 'directeur', 'adjoint', 'attache', 'collaborateur', 'manager', 'stagiaire', 'apprenti', 'h', 'f'], true)) return null;
    $name = mb_strtoupper(mb_substr($t, 0, 1)) . mb_strtolower(mb_substr($t, 1));
    return [$key, $name];
}

function metiers_build(PDO $db): array
{
    metier_tables($db);
    jobs_cities_ready($db);
    $cities = [];
    foreach ($db->query('SELECT slug, name, region FROM job_cities') as $c) $cities[$c['region'] . '|' . $c['name']] = $c['slug'];
    $map = []; $cnt = []; $names = [];
    foreach ($db->query("SELECT id, title, city, region FROM jobs WHERE status='open'") as $j) {
        if (!($m = metier_of((string)$j['title']))) continue;
        [$k, $n] = $m;
        $cnt[$k] = ($cnt[$k] ?? 0) + 1;
        $names[$k][$n] = ($names[$k][$n] ?? 0) + 1;
        $map[] = [$j['id'], $k, $cities[$j['region'] . '|' . job_city_norm((string)$j['city'])] ?? ''];
    }
    $keep = array_filter($cnt, fn($n) => $n >= METIER_MIN);
    $db->beginTransaction();
    $db->exec('DELETE FROM job_metiers'); $db->exec('DELETE FROM job_metier_map'); $db->exec('DELETE FROM job_metier_cities');
    $im = $db->prepare('INSERT INTO job_metiers(slug,name,n,updated) VALUES(?,?,?,?)');
    foreach ($keep as $k => $n) { arsort($names[$k]); $im->execute([$k, array_key_first($names[$k]), $n, now()]); }
    $ix = $db->prepare('INSERT OR REPLACE INTO job_metier_map(id,mslug,cslug) VALUES(?,?,?)');
    foreach ($map as $r) if (isset($keep[$r[1]])) $ix->execute($r);
    $db->exec('INSERT INTO job_metier_cities(mslug,cslug,n) SELECT mslug, cslug, COUNT(*) FROM job_metier_map WHERE cslug<>\'\' GROUP BY mslug, cslug HAVING COUNT(*) >= ' . METIER_CITY_MIN);
    $db->commit();
    return ['metiers' => count($keep), 'combos' => (int)$db->query('SELECT COUNT(*) FROM job_metier_cities')->fetchColumn()];
}

function metier_salary_stats(array $jobs): ?array
{
    $v = [];
    foreach ($jobs as $j) if (($s = job_salary((string)$j['salary'])) && ($m = job_salary_monthly($s)) > 900 && $m < 20000) $v[] = $m;
    if (count($v) < 3) return null;
    sort($v);
    $q = fn($p) => $v[(int)floor($p * (count($v) - 1))];
    return ['n' => count($v), 'p25' => $q(.25), 'med' => $q(.5), 'p75' => $q(.75)];
}

function metier_fmt_eur(float $x): string { return number_format(round($x / 10) * 10, 0, ',', ' ') . ' €'; }

function page_metiers_index(array $site): string
{
    $db = jobs_db($site['host']); metier_tables($db);
    $rows = $db->query('SELECT slug, name, n FROM job_metiers ORDER BY name')->fetchAll();
    if (!$rows) return '';
    $top = $db->query('SELECT slug, name, n FROM job_metiers ORDER BY n DESC LIMIT 30')->fetchAll();
    $by = [];
    foreach ($rows as $r) { $l = mb_strtoupper(mb_substr(iconv('UTF-8', 'ASCII//TRANSLIT', $r['name']) ?: $r['name'], 0, 1)); $by[$l][] = $r; }
    $alpha = '';
    foreach ($by as $l => $list) $alpha .= '<h3>' . h($l) . '</h3><p>' . implode(' · ', array_map(fn($r) => '<a href="/emploi/' . h($r['slug']) . '/">' . h($r['name']) . '</a> (' . (int)$r['n'] . ')', $list)) . '</p>';
    $body = '<h1 style="margin-top:28px">Offres d\'emploi par métier</h1><p>' . count($rows) . ' métiers qui recrutent en ce moment, avec le nombre d\'offres, les salaires proposés et les villes qui embauchent.</p>'
        . '<h2>Métiers qui recrutent le plus</h2>' . count_chips(array_map(fn($r) => [$r['name'], $r['n'], '/emploi/' . $r['slug'] . '/'], $top))
        . '<h2>Tous les métiers de A à Z</h2>' . $alpha . jobs_disclaimer();
    return layout($site, ['title' => 'Offres d\'emploi par métier : ' . count($rows) . ' métiers qui recrutent | ' . $site['name'],
        'desc' => 'Trouvez un emploi par métier : ' . count($rows) . ' métiers, nombre d\'offres, salaires et villes qui recrutent. Mis à jour chaque jour.',
        'canonical' => 'https://' . $site['host'] . '/emploi/', 'schema' => [breadcrumbs($site, [['Métiers', '/emploi/']])]], '<style>' . fr_map_css() . '</style>' . $body);
}

function page_metier(array $site, string $mslug, string $cslug = ''): string
{
    $db = jobs_db($site['host']); metier_tables($db);
    $st = $db->prepare('SELECT * FROM job_metiers WHERE slug=?'); $st->execute([$mslug]);
    if (!($m = $st->fetch())) return '';
    $city = null;
    if ($cslug !== '') {
        $st = $db->prepare('SELECT c.*, mc.n mn FROM job_metier_cities mc JOIN job_cities c ON c.slug=mc.cslug WHERE mc.mslug=? AND mc.cslug=?'); $st->execute([$mslug, $cslug]);
        if (!($city = $st->fetch())) return '';
    }
    $where = "j.status='open' AND mm.mslug=?" . ($city ? ' AND mm.cslug=?' : '');
    $args = $city ? [$mslug, $cslug] : [$mslug];
    $st = $db->prepare("SELECT j.* FROM jobs j JOIN job_metier_map mm ON mm.id=j.id WHERE $where ORDER BY j.created_at DESC"); $st->execute($args);
    $all = $st->fetchAll();
    if (!$all) return '';
    $n = count($all); $name = $m['name']; $lname = mb_strtolower($name);
    $where_ = $city ? ' à ' . $city['name'] : '';
    $nf = fn($x) => number_format($x, 0, ',', ' ');
    $sal = metier_salary_stats($all);
    $contracts = []; foreach ($all as $j) { $k = JOB_CONTRACTS[$j['contract']] ?? 'Autre'; $contracts[$k] = ($contracts[$k] ?? 0) + 1; } arsort($contracts);
    $parts = [];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/emploi/">Métiers</a> › ' . ($city ? '<a href="/emploi/' . h($mslug) . '/">' . h($name) . '</a> › ' . h($city['name']) : h($name)) . '</p>'
        . '<h1>Emploi ' . h($lname) . h($where_) . ' : ' . $nf($n) . ' offres</h1>'
        . '<p class="lead">' . $nf($n) . ' offres d\'emploi de ' . h($lname) . h($where_) . ' sont ouvertes aujourd\'hui'
        . ($sal ? ', pour un salaire médian proposé de <strong>' . metier_fmt_eur($sal['med']) . ' brut par mois</strong>' : '') . '. Mise à jour : ' . h(date_fr(now())) . '.</p>';
    $kpi = '<div class="kpi"><div><b>' . $nf($n) . '</b>offres ouvertes</div>';
    if ($sal) $kpi .= '<div><b>' . metier_fmt_eur($sal['med']) . '</b>salaire médian brut/mois</div><div><b>' . metier_fmt_eur($sal['p25']) . ' – ' . metier_fmt_eur($sal['p75']) . '</b>fourchette courante</div>';
    $kpi .= '<div><b>' . h((string)array_key_first($contracts)) . '</b>contrat le plus proposé (' . round(100 * reset($contracts) / $n) . ' %)</div></div>';
    $body .= $kpi . '<h2>Types de contrat</h2>' . count_chips(array_map(fn($k, $v) => [$k, $v, ''], array_keys($contracts), $contracts));
    if (!$city) {
        $byR = []; foreach ($all as $j) $byR[$j['region']] = ($byR[$j['region']] ?? 0) + 1;
        $body .= '<h2>Où trouver un emploi de ' . h($lname) . ' ?</h2>' . fr_map($byR, '/offres-emploi/');
        $st = $db->prepare('SELECT c.slug, c.name, mc.n FROM job_metier_cities mc JOIN job_cities c ON c.slug=mc.cslug WHERE mc.mslug=? ORDER BY mc.n DESC LIMIT 40'); $st->execute([$mslug]);
        if ($cs = $st->fetchAll()) $body .= '<h2>' . h($name) . ' : villes qui recrutent</h2>' . count_chips(array_map(fn($c) => [$c['name'], $c['n'], '/emploi/' . $mslug . '/' . $c['slug'] . '/'], $cs));
    } else {
        $st = $db->prepare('SELECT c.slug, c.name, mc.n FROM job_metier_cities mc JOIN job_cities c ON c.slug=mc.cslug WHERE mc.mslug=? AND mc.cslug<>? AND c.region=? ORDER BY mc.n DESC LIMIT 15'); $st->execute([$mslug, $cslug, $city['region']]);
        if ($cs = $st->fetchAll()) $body .= '<h2>' . h($name) . ' : autres villes de la région</h2>' . count_chips(array_map(fn($c) => [$c['name'], $c['n'], '/emploi/' . $mslug . '/' . $c['slug'] . '/'], $cs));
        $body .= '<p><a href="/offres-emploi/ville/' . h($cslug) . '/">Toutes les offres d\'emploi à ' . h($city['name']) . '</a> · <a href="/emploi/' . h($mslug) . '/">' . h($name) . ' : toute la France</a></p>';
    }
    $emp = []; foreach ($all as $j) if ($j['company'] !== '' && !str_contains($j['company'], '—')) $emp[$j['company']] = ($emp[$j['company']] ?? 0) + 1; arsort($emp);
    if (count($emp) >= 3) $body .= '<h2>Employeurs qui recrutent</h2>' . count_chips(array_map(fn($k, $v) => [$k, $v, ''], array_slice(array_keys($emp), 0, 12), array_slice($emp, 0, 12)));
    $body .= '<h2>Dernières offres de ' . h($lname) . h($where_) . '</h2><div class="grid">' . implode('', array_map('job_card', array_slice($all, 0, 30))) . '</div>';
    $faq = [['Combien d\'offres d\'emploi de ' . $lname . $where_ . ' ?', $nf($n) . ' offres sont ouvertes aujourd\'hui sur ' . $site['name'] . ', mises à jour plusieurs fois par jour.']];
    if ($sal) $faq[] = ['Quel salaire pour un ' . $lname . $where_ . ' ?', 'Sur ' . $sal['n'] . ' offres indiquant un salaire, la rémunération médiane est de ' . metier_fmt_eur($sal['med']) . ' brut par mois ; la moitié des offres se situe entre ' . metier_fmt_eur($sal['p25']) . ' et ' . metier_fmt_eur($sal['p75']) . '.'];
    $faq[] = ['Quel contrat pour un ' . $lname . ' ?', 'Le contrat le plus proposé est le ' . array_key_first($contracts) . ' (' . round(100 * reset($contracts) / $n) . ' % des offres).'];
    $body .= '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($q) => '<h3>' . h($q[0]) . '</h3><p>' . h($q[1]) . '</p>', $faq)) . jobs_disclaimer();
    $schema = [breadcrumbs($site, array_filter([['Métiers', '/emploi/'], [$name, '/emploi/' . $mslug . '/'], $city ? [$city['name'], '/emploi/' . $mslug . '/' . $cslug . '/'] : null])),
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]];
    return layout($site, ['title' => 'Emploi ' . $lname . $where_ . ' : ' . $nf($n) . ' offres' . ($sal ? ', salaire ' . metier_fmt_eur($sal['med']) : '') . ' | ' . $site['name'],
        'desc' => $nf($n) . ' offres d\'emploi de ' . $lname . $where_ . ($sal ? ', salaire médian ' . metier_fmt_eur($sal['med']) . ' brut/mois' : '') . '. CDI, CDD, intérim : postulez directement. Mis à jour aujourd\'hui.',
        'canonical' => 'https://' . $site['host'] . '/emploi/' . $mslug . '/' . ($city ? $cslug . '/' : ''), 'schema' => $schema],
        '<style>' . fr_map_css() . '</style>' . $body);
}

function out_sitemap_metiers(array $site): void
{
    header('Content-Type: application/xml; charset=utf-8');
    $db = jobs_db($site['host']); metier_tables($db);
    $b = 'https://' . $site['host']; $d = date('Y-m-d');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo "<url><loc>$b/emploi/</loc><lastmod>$d</lastmod></url>";
    foreach ($db->query('SELECT slug FROM job_metiers') as $r) echo "<url><loc>$b/emploi/{$r['slug']}/</loc><lastmod>$d</lastmod></url>";
    foreach ($db->query('SELECT mslug, cslug FROM job_metier_cities LIMIT 45000') as $r) echo "<url><loc>$b/emploi/{$r['mslug']}/{$r['cslug']}/</loc><lastmod>$d</lastmod></url>";
    echo '</urlset>';
}

// Liste prioritaire pour l'API d'indexation Google (quota limité) : offres récentes au contenu le plus unique
// (emploi public, alternance), puis offres avec salaire et description complète. Non référencée dans le sitemap.
function out_sitemap_priority(array $site): void
{
    header('Content-Type: application/xml; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $db = jobs_db($site['host']);
    $rows = $db->query("SELECT slug FROM jobs WHERE status='open' AND created_at >= datetime('now','-10 days')
        ORDER BY CASE WHEN id LIKE 'csp-%' THEN 0 WHEN id LIKE 'lba-%' THEN 1 WHEN salary<>'' AND length(description)>600 THEN 2 ELSE 3 END, created_at DESC LIMIT 3000")->fetchAll(PDO::FETCH_COLUMN);
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($rows as $s) echo '<url><loc>https://' . $site['host'] . '/offre/' . $s . '/</loc></url>';
    echo '</urlset>';
}
