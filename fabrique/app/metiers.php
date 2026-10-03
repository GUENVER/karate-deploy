<?php
// Pages métier (/emploi/<métier>/) et métier + ville (/emploi/<métier>/<ville>/), construites à partir des intitulés
// d'offres normalisés (« Aide-soignant H/F - SSIAD » → « Aide-soignant »). Reconstruit par cli/metiers_build.php.
declare(strict_types=1);

const METIER_MIN = 30;       // offres ouvertes minimum pour une page métier
const METIER_CITY_MIN = 5;   // offres minimum pour une page métier + ville

function metier_tables(PDO $db): void
{
    static $done = [];
    if (!empty($done[spl_object_id($db)])) return;
    $done[spl_object_id($db)] = 1;
    $db->exec('CREATE TABLE IF NOT EXISTS job_metiers(slug TEXT PRIMARY KEY, name TEXT, n INTEGER, updated TEXT);
        CREATE TABLE IF NOT EXISTS job_metier_map(id TEXT PRIMARY KEY, mslug TEXT, cslug TEXT);
        CREATE INDEX IF NOT EXISTS ix_jmm_m ON job_metier_map(mslug, cslug);
        CREATE TABLE IF NOT EXISTS job_metier_cities(mslug TEXT, cslug TEXT, n INTEGER, PRIMARY KEY(mslug, cslug));
        CREATE TABLE IF NOT EXISTS job_metier_sal(slug TEXT PRIMARY KEY, n INTEGER, med REAL, p25 REAL, p75 REAL);');
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
    // code postal -> ville la plus fréquente parmi les offres dont le nom de commune est reconnu (rattache les variantes d'écriture)
    $byPostal = [];
    foreach ($db->query("SELECT city, postal, region FROM jobs WHERE status='open' AND length(postal)=5") as $j) {
        $c = $cities[$j['region'] . '|' . job_city_norm((string)$j['city'])] ?? '';
        if ($c !== '') $byPostal[$j['postal']][$c] = ($byPostal[$j['postal']][$c] ?? 0) + 1;
    }
    $byArea = []; // zone (3 premiers chiffres du code postal) -> ville principale : rattache les communes voisines (bassin d'emploi)
    foreach ($byPostal as $pc => $cs) foreach ($cs as $c => $n) $byArea[substr((string)$pc, 0, 3)][$c] = ($byArea[substr((string)$pc, 0, 3)][$c] ?? 0) + $n;
    foreach ($byArea as $a3 => $cs) { arsort($cs); $byArea[$a3] = array_key_first($cs); }
    foreach ($byPostal as $pc => $cs) { arsort($cs); $byPostal[$pc] = array_key_first($cs); }
    $map = []; $cnt = []; $names = [];
    foreach ($db->query("SELECT id, title, city, postal, region FROM jobs WHERE status='open'") as $j) {
        if (!($m = metier_of((string)$j['title']))) continue;
        [$k, $n] = $m;
        $cnt[$k] = ($cnt[$k] ?? 0) + 1;
        $names[$k][$n] = ($names[$k][$n] ?? 0) + 1;
        $map[] = [$j['id'], $k, $cities[$j['region'] . '|' . job_city_norm((string)$j['city'])] ?? ($byPostal[(string)$j['postal']] ?? (strlen((string)$j['postal']) === 5 ? ($byArea[substr((string)$j['postal'], 0, 3)] ?? '') : ''))];
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
    // salaires par métier (pages /salaire/)
    $db->beginTransaction(); $db->exec('DELETE FROM job_metier_sal');
    $is = $db->prepare('INSERT INTO job_metier_sal(slug,n,med,p25,p75) VALUES(?,?,?,?,?)');
    foreach (array_keys($keep) as $k) if (($s = metier_salary_stats(metier_jobs($db, (string)$k))) && $s['n'] >= 8) $is->execute([$k, $s['n'], $s['med'], $s['p25'], $s['p75']]);
    $db->commit();
    intents_build($db);
    barometre_snapshot($db);
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
        . '<p class="lead">' . $nf($n) . ' offres d\'emploi ' . h(metier_de($lname)) . h($where_) . ' sont ouvertes aujourd\'hui'
        . ($sal ? ', pour un salaire médian proposé de <strong>' . metier_fmt_eur($sal['med']) . ' brut par mois</strong>' : '') . '. Mise à jour : ' . h(date_fr(now())) . '.</p>';
    $kpi = '<div class="kpi"><div><b>' . $nf($n) . '</b>offres ouvertes</div>';
    if ($sal) $kpi .= '<div><b>' . metier_fmt_eur($sal['med']) . '</b>salaire médian brut/mois</div><div><b>' . metier_fmt_eur($sal['p25']) . ' – ' . metier_fmt_eur($sal['p75']) . '</b>fourchette courante</div>';
    $kpi .= '<div><b>' . h((string)array_key_first($contracts)) . '</b>contrat le plus proposé (' . round(100 * reset($contracts) / $n) . ' %)</div></div>';
    if (!$city) { $db->exec('CREATE TABLE IF NOT EXISTS job_metier_text(slug TEXT PRIMARY KEY, html TEXT, updated TEXT)'); $tx = $db->prepare('SELECT html FROM job_metier_text WHERE slug=?'); $tx->execute([$mslug]); $txt = (string)$tx->fetchColumn(); } else $txt = '';
    $body .= $kpi . ($txt !== '' ? '<section class="mtext">' . $txt . '</section>' : '') . '<h2>Types de contrat</h2>' . count_chips(array_map(fn($k, $v) => [$k, $v, ''], array_keys($contracts), $contracts));
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
    $st = $db->prepare('SELECT 1 FROM job_metier_sal WHERE slug=?'); $st->execute([$mslug]);
    if ($st->fetchColumn()) $body .= '<p><a href="/salaire/' . h($mslug) . '/"><strong>Salaire ' . h($lname) . ' : détail par région et par contrat →</strong></a></p>';
    $body .= '<h2>Dernières offres ' . h(metier_de($lname)) . h($where_) . '</h2><div class="grid">' . implode('', array_map('job_card', array_slice($all, 0, 30))) . '</div>';
    if (!$city) $body .= metier_formations_html(metier_formations($db, $mslug, $name), $name);
    $faq = [['Combien d\'offres d\'emploi ' . metier_de($lname) . $where_ . ' ?', $nf($n) . ' offres sont ouvertes aujourd\'hui sur ' . $site['name'] . ', mises à jour plusieurs fois par jour.']];
    if ($sal) $faq[] = ['Quel salaire pour un ' . $lname . $where_ . ' ?', 'Sur ' . $sal['n'] . ' offres indiquant un salaire, la rémunération médiane est de ' . metier_fmt_eur($sal['med']) . ' brut par mois ; la moitié des offres se situe entre ' . metier_fmt_eur($sal['p25']) . ' et ' . metier_fmt_eur($sal['p75']) . '.'];
    $faq[] = ['Quel contrat pour un ' . $lname . ' ?', 'Le contrat le plus proposé est le ' . array_key_first($contracts) . ' (' . round(100 * reset($contracts) / $n) . ' % des offres).'];
    $body .= '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($q) => '<h3>' . h($q[0]) . '</h3><p>' . h($q[1]) . '</p>', $faq)) . jobs_disclaimer();
    $schema = [breadcrumbs($site, array_filter([['Métiers', '/emploi/'], [$name, '/emploi/' . $mslug . '/'], $city ? [$city['name'], '/emploi/' . $mslug . '/' . $cslug . '/'] : null])),
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]];
    return layout($site, ['title' => 'Emploi ' . $lname . $where_ . ' : ' . $nf($n) . ' offres' . ($sal ? ', salaire ' . metier_fmt_eur($sal['med']) : '') . ' | ' . $site['name'],
        'desc' => $nf($n) . ' offres d\'emploi ' . metier_de($lname) . $where_ . ($sal ? ', salaire médian ' . metier_fmt_eur($sal['med']) . ' brut/mois' : '') . '. CDI, CDD, intérim : postulez directement. Mis à jour aujourd\'hui.',
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
    echo "<url><loc>$b/salaire/</loc><lastmod>$d</lastmod></url><url><loc>$b/barometre-emploi/</loc><lastmod>$d</lastmod></url>";
    if (function_exists('csp_type_links')) foreach (csp_type_links($db, true) as $l) echo "<url><loc>$b{$l[1]}</loc><lastmod>$d</lastmod></url>";
    $db->exec('CREATE TABLE IF NOT EXISTS job_intent_map(id TEXT, intent TEXT, PRIMARY KEY(id, intent))');
    foreach ($db->query("SELECT m.intent, j.region, COUNT(*) n FROM job_intent_map m JOIN jobs j ON j.id=m.id WHERE j.status='open' GROUP BY 1,2") as $r) {
        static $seen = [];
        if (empty($seen[$r['intent']])) { echo "<url><loc>$b/{$r['intent']}/</loc><lastmod>$d</lastmod></url>"; $seen[$r['intent']] = 1; }
        if ($r['n'] >= 5 && isset(JOB_REGIONS[$r['region']])) echo "<url><loc>$b/{$r['intent']}/" . JOB_REGIONS[$r['region']][0] . "/</loc><lastmod>$d</lastmod></url>";
    }
    foreach ($db->query('SELECT slug FROM job_metier_sal') as $r) echo "<url><loc>$b/salaire/{$r['slug']}/</loc><lastmod>$d</lastmod></url>";
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
    $rows = $db->query("SELECT slug FROM jobs WHERE status='open' AND " . JOB_INDEXABLE_SQL . " AND created_at >= datetime('now','-10 days')
        ORDER BY CASE WHEN id LIKE 'csp-%' THEN 0 WHEN id LIKE 'lba-%' THEN 1 WHEN salary<>'' AND length(description)>600 THEN 2 ELSE 3 END, created_at DESC LIMIT 3000")->fetchAll(PDO::FETCH_COLUMN);
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($rows as $s) echo '<url><loc>https://' . $site['host'] . '/offre/' . $s . '/</loc></url>';
    echo '</urlset>';
}

// Formations en apprentissage liées à un métier : codes ROME des offres d'alternance du métier, sinon intitulé.
function metier_formations(PDO $db, string $mslug, string $name, int $limit = 8): array
{
    if (!function_exists('lba_tables')) return [];
    lba_tables($db);
    $st = $db->prepare("SELECT m.romes FROM lba_job_meta m JOIN job_metier_map mm ON mm.id=m.id WHERE mm.mslug=? AND m.romes<>''"); $st->execute([$mslug]);
    $cnt = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $r) foreach (explode(',', $r) as $c) if ($c !== '') $cnt[$c] = ($cnt[$c] ?? 0) + 1;
    arsort($cnt);
    $romes = array_slice(array_keys($cnt), 0, 3);
    if ($romes) {
        $like = implode(' OR ', array_fill(0, count($romes), 'romes LIKE ?'));
        $st = $db->prepare("SELECT title, sigle, MIN(niveau) niveau, MIN(url) url, COUNT(DISTINCT organisme) n FROM lba_formations WHERE $like GROUP BY title ORDER BY n DESC LIMIT $limit");
        $st->execute(array_map(fn($r) => "%$r%", $romes));
        if ($rows = $st->fetchAll()) return $rows;
    }
    $st = $db->prepare("SELECT title, sigle, MIN(niveau) niveau, MIN(url) url, COUNT(DISTINCT organisme) n FROM lba_formations WHERE title LIKE ? GROUP BY title ORDER BY n DESC LIMIT $limit");
    $st->execute(['%' . mb_strtolower($name) . '%']);
    return $st->fetchAll();
}

function metier_formations_html(array $rows, string $name): string
{
    if (!$rows) return '';
    $li = implode('', array_map(fn($f) => '<li>' . ($f['url'] ? '<a href="' . h($f['url']) . '" rel="nofollow noopener" target="_blank">' . h(mb_strtoupper(mb_substr($f['title'], 0, 1)) . mb_substr($f['title'], 1)) . '</a>' : h($f['title']))
        . ' <small>' . h(trim($f['sigle'] . ' · ' . (int)$f['n'] . ' établissement' . ($f['n'] > 1 ? 's' : '') . ' en France', ' ·')) . '</small></li>', $rows));
    return '<h2>Se former au métier ' . h(metier_de(mb_strtolower($name))) . ' en alternance</h2><ul>' . $li . '</ul><p class="disc">Formations en apprentissage du catalogue national (Carif-Oref, ONISEP), via La bonne alternance.</p>';
}

// Pages salaire : /salaire/ et /salaire/<métier>/ (métiers avec au moins 8 offres indiquant un salaire).
function metier_jobs(PDO $db, string $mslug): array
{
    $st = $db->prepare("SELECT j.* FROM jobs j JOIN job_metier_map mm ON mm.id=j.id WHERE j.status='open' AND mm.mslug=?"); $st->execute([$mslug]);
    return $st->fetchAll();
}

function page_salaires_index(array $site): string
{
    $db = jobs_db($site['host']); metier_tables($db);
    $rows = array_map(fn($r) => [$r, $r], $db->query('SELECT m.slug, m.name, s.n, s.med, s.p25, s.p75 FROM job_metier_sal s JOIN job_metiers m ON m.slug=s.slug ORDER BY s.med DESC')->fetchAll());
    if (!$rows) return '';
    $tr = implode('', array_map(fn($r) => '<tr><td><a href="/salaire/' . h($r[0]['slug']) . '/">' . h($r[0]['name']) . '</a></td><td>' . metier_fmt_eur($r[1]['med']) . '</td><td>' . metier_fmt_eur($r[1]['p25']) . ' – ' . metier_fmt_eur($r[1]['p75']) . '</td><td>' . (int)$r[1]['n'] . '</td></tr>', $rows));
    $body = '<h1 style="margin-top:28px">Salaires par métier</h1><p>Salaires bruts mensuels proposés dans les offres d\'emploi ouvertes aujourd\'hui, pour ' . count($rows) . ' métiers. Médiane et fourchette courante (la moitié des offres), calculées sur les offres qui indiquent une rémunération.</p>'
        . '<div style="overflow-x:auto"><table><tr><th>Métier</th><th>Salaire médian</th><th>Fourchette courante</th><th>Offres avec salaire</th></tr>' . $tr . '</table></div>' . jobs_disclaimer();
    return layout($site, ['title' => 'Salaires par métier : ' . count($rows) . ' métiers, salaires réels des offres | ' . $site['name'],
        'desc' => 'Combien gagne un aide-soignant, un cariste, un comptable ? Salaires bruts mensuels de ' . count($rows) . ' métiers, calculés sur les offres d\'emploi du jour.',
        'canonical' => 'https://' . $site['host'] . '/salaire/', 'schema' => [breadcrumbs($site, [['Salaires', '/salaire/']])]], $body);
}

function page_salaire(array $site, string $mslug): string
{
    $db = jobs_db($site['host']); metier_tables($db);
    $st = $db->prepare('SELECT * FROM job_metiers WHERE slug=?'); $st->execute([$mslug]);
    if (!($m = $st->fetch())) return '';
    $all = metier_jobs($db, $mslug);
    $sal = metier_salary_stats($all);
    if (!$sal || $sal['n'] < 8) return '';
    $name = $m['name']; $l = mb_strtolower($name);
    $byR = []; $byC = [];
    foreach ($all as $j) { $byR[$j['region']][] = $j; $byC[JOB_CONTRACTS[$j['contract']] ?? 'Autre'][] = $j; }
    $rowsR = '';
    foreach (JOB_REGIONS as $code => [$slug, $rn]) if (!empty($byR[$code]) && ($s = metier_salary_stats($byR[$code]))) $rowsR .= '<tr><td><a href="/offres-emploi/' . $slug . '/">' . h($rn) . '</a></td><td>' . metier_fmt_eur($s['med']) . '</td><td>' . metier_fmt_eur($s['p25']) . ' – ' . metier_fmt_eur($s['p75']) . '</td><td>' . (int)$s['n'] . '</td></tr>';
    $rowsC = '';
    foreach ($byC as $c => $js) if ($s = metier_salary_stats($js)) $rowsC .= '<tr><td>' . h($c) . '</td><td>' . metier_fmt_eur($s['med']) . '</td><td>' . (int)$s['n'] . '</td></tr>';
    $year = metier_fmt_eur($sal['med'] * 12);
    $net = metier_fmt_eur($sal['med'] * 0.78);
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/salaire/">Salaires</a> › ' . h($name) . '</p>'
        . '<h1>Salaire ' . h($l) . ' : ' . metier_fmt_eur($sal['med']) . ' brut par mois</h1>'
        . '<p class="lead">D\'après ' . (int)$sal['n'] . ' offres d\'emploi ouvertes aujourd\'hui qui indiquent une rémunération, un ' . h($l) . ' est payé en médiane <strong>' . metier_fmt_eur($sal['med']) . ' brut par mois</strong> (environ ' . $net . ' net), soit ' . $year . ' brut par an. La moitié des offres se situe entre ' . metier_fmt_eur($sal['p25']) . ' et ' . metier_fmt_eur($sal['p75']) . '.</p>'
        . '<div class="kpi"><div><b>' . metier_fmt_eur($sal['med']) . '</b>médiane brute/mois</div><div><b>' . $net . '</b>net estimé/mois</div><div><b>' . metier_fmt_eur($sal['p25']) . '</b>bas de fourchette</div><div><b>' . metier_fmt_eur($sal['p75']) . '</b>haut de fourchette</div></div>'
        . ($rowsR ? '<h2>Salaire ' . h($l) . ' par région</h2><div style="overflow-x:auto"><table><tr><th>Région</th><th>Médiane brute</th><th>Fourchette</th><th>Offres</th></tr>' . $rowsR . '</table></div>' : '')
        . ($rowsC ? '<h2>Selon le type de contrat</h2><table><tr><th>Contrat</th><th>Médiane brute</th><th>Offres</th></tr>' . $rowsC . '</table>' : '')
        . '<p><a class="btn" style="background:var(--c);color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none" href="/emploi/' . h($mslug) . '/">Voir les ' . count($all) . ' offres ' . h(metier_de($l)) . '</a></p>'
        . metier_formations_html(metier_formations($db, $mslug, $name), $name)
        . '<h2>Questions fréquentes</h2>';
    $faq = [['Quel est le salaire d\'un ' . $l . ' ?', 'Le salaire médian proposé est de ' . metier_fmt_eur($sal['med']) . ' brut par mois (environ ' . $net . ' net), d\'après ' . $sal['n'] . ' offres d\'emploi actuelles.'],
        ['Combien gagne un ' . $l . ' par an ?', 'Environ ' . $year . ' brut par an sur la base du salaire médian, hors primes et 13e mois.'],
        ['Quelle fourchette de salaire pour un ' . $l . ' ?', 'La moitié des offres propose entre ' . metier_fmt_eur($sal['p25']) . ' et ' . metier_fmt_eur($sal['p75']) . ' brut par mois.']];
    $body .= implode('', array_map(fn($q) => '<h3>' . h($q[0]) . '</h3><p>' . h($q[1]) . '</p>', $faq)) . '<p class="disc">Salaires bruts mensuels annoncés dans les offres (hors primes), convertis en équivalent mensuel temps plein ; net estimé à 78 % du brut. ' . 'Mise à jour : ' . date_fr(now()) . '.</p>';
    return layout($site, ['title' => 'Salaire ' . $l . ' : ' . metier_fmt_eur($sal['med']) . ' brut/mois en ' . date('Y') . ' | ' . $site['name'],
        'desc' => 'Salaire ' . $l . ' : ' . metier_fmt_eur($sal['med']) . ' brut par mois en médiane (' . $net . ' net), fourchette ' . metier_fmt_eur($sal['p25']) . ' – ' . metier_fmt_eur($sal['p75']) . ', par région. Calculé sur ' . $sal['n'] . ' offres réelles.',
        'canonical' => 'https://' . $site['host'] . '/salaire/' . $mslug . '/',
        'schema' => [breadcrumbs($site, [['Salaires', '/salaire/'], [$name, '/salaire/' . $mslug . '/']]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]]], $body);
}

function metier_de(string $w): string { return (preg_match('/^[aeiouyhéèêëàâîïôöûü]/iu', $w) ? "d'" : 'de ') . $w; }

// ---------- Maillage depuis une fiche offre ----------
function metier_links_for_job(array $site, array $j): string
{
    $db = jobs_db($site['host']); metier_tables($db);
    $st = $db->prepare('SELECT mm.mslug, mm.cslug, m.name, m.n, c.name cname, mc.n cn, s.med FROM job_metier_map mm JOIN job_metiers m ON m.slug=mm.mslug
        LEFT JOIN job_metier_cities mc ON mc.mslug=mm.mslug AND mc.cslug=mm.cslug LEFT JOIN job_cities c ON c.slug=mm.cslug LEFT JOIN job_metier_sal s ON s.slug=mm.mslug WHERE mm.id=?');
    $st->execute([$j['id']]);
    if (!($r = $st->fetch())) return '';
    $l = mb_strtolower($r['name']);
    $links = [['Toutes les offres ' . metier_de($l), '/emploi/' . $r['mslug'] . '/', $r['n']]];
    if ($r['cn']) $links[] = [$r['name'] . ' à ' . $r['cname'], '/emploi/' . $r['mslug'] . '/' . $r['cslug'] . '/', $r['cn']];
    if ($r['med']) $links[] = ['Salaire ' . $l . ' : ' . metier_fmt_eur((float)$r['med']) . ' brut/mois', '/salaire/' . $r['mslug'] . '/', null];
    return '<h2>Pour aller plus loin</h2><div class="cnt">' . implode('', array_map(fn($x) => '<a href="' . h($x[1]) . '">' . h($x[0]) . ($x[2] ? ' <b>' . number_format((int)$x[2], 0, ',', ' ') . '</b>' : '') . '</a>', $links)) . '</div>';
}

// ---------- Pages d'intention (emploi sans diplôme, étudiant, week-end…) ----------
const JOB_INTENTS = [
    'emploi-sans-diplome' => ['Emploi sans diplôme', "(description LIKE '%sans diplôme%' OR description LIKE '%aucun diplôme%' OR description LIKE '%pas de diplôme%' OR description LIKE '%sans qualification%' OR experience LIKE '%sans diplôme%')",
        'Offres accessibles sans diplôme ni qualification particulière : l\'employeur forme au poste ou recherche avant tout la motivation.'],
    'emploi-debutant' => ['Emploi débutant accepté', "(experience LIKE '%débutant%' OR description LIKE '%débutant accepté%' OR description LIKE '%débutants acceptés%' OR description LIKE '%débutant(e) accepté%' OR description LIKE '%aucune expérience requise%' OR description LIKE '%aucune expérience n\'est requise%')",
        'Postes ouverts aux débutants, sans expérience exigée : idéal pour un premier emploi ou une reconversion.'],
    'job-etudiant' => ['Job étudiant', "(title LIKE '%étudiant%' OR title LIKE '%ETUDIANT%' OR description LIKE '%job étudiant%' OR description LIKE '%emploi étudiant%' OR description LIKE '%compatible avec vos études%' OR description LIKE '%compatible avec des études%')",
        'Jobs compatibles avec les études : temps partiel, soirs, week-ends ou vacances scolaires.'],
    'job-week-end' => ['Job le week-end', "(title LIKE '%week-end%' OR title LIKE '%weekend%' OR title LIKE '%WEEK-END%' OR description LIKE '%week-end uniquement%' OR description LIKE '%les week-ends%' OR description LIKE '%samedi et dimanche%' OR description LIKE '%samedis et dimanches%')",
        'Offres d\'emploi le samedi et le dimanche : complément de revenu, étudiants, double activité.'],
    'emploi-teletravail' => ['Emploi en télétravail', "(title LIKE '%télétravail%' OR title LIKE '%TELETRAVAIL%' OR title LIKE '%full remote%' OR description LIKE '%télétravail%' OR description LIKE '%full remote%' OR description LIKE '%100% remote%')",
        'Postes avec télétravail possible, partiel ou total : vérifiez le nombre de jours précisé dans chaque offre.'],
    'emploi-temps-partiel' => ['Emploi à temps partiel', "(worktime LIKE '%partiel%' OR title LIKE '%temps partiel%' OR title LIKE '%TEMPS PARTIEL%')",
        'Offres à temps partiel : moins de 35 heures par semaine, pour concilier emploi et vie personnelle.'],
    'emploi-urgent' => ['Emploi urgent : prise de poste immédiate', "(title LIKE '%urgent%' OR title LIKE '%URGENT%' OR description LIKE '%poste à pourvoir immédiatement%' OR description LIKE '%prise de poste immédiate%' OR description LIKE '%poste à pourvoir dès que possible%' OR description LIKE '%démarrage immédiat%')",
        'Recrutements urgents : postes à pourvoir immédiatement, réponses rapides des employeurs.'],
];

function intents_build(PDO $db): int
{
    $db->exec('CREATE TABLE IF NOT EXISTS job_intent_map(id TEXT, intent TEXT, PRIMARY KEY(id, intent)); CREATE INDEX IF NOT EXISTS ix_jim ON job_intent_map(intent)');
    $db->beginTransaction(); $db->exec('DELETE FROM job_intent_map');
    foreach (JOB_INTENTS as $k => [, $sql]) $db->exec("INSERT OR IGNORE INTO job_intent_map(id,intent) SELECT id, '$k' FROM jobs WHERE status='open' AND $sql");
    $db->commit();
    return (int)$db->query('SELECT COUNT(*) FROM job_intent_map')->fetchColumn();
}

function intent_counts(PDO $db): array
{
    $db->exec('CREATE TABLE IF NOT EXISTS job_intent_map(id TEXT, intent TEXT, PRIMARY KEY(id, intent))');
    $o = []; foreach ($db->query("SELECT m.intent, COUNT(*) n FROM job_intent_map m JOIN jobs j ON j.id=m.id WHERE j.status='open' GROUP BY m.intent") as $r) $o[$r['intent']] = (int)$r['n'];
    return $o;
}

function page_intent(array $site, string $key, ?array $reg, int $page): string
{
    if (!isset(JOB_INTENTS[$key])) return '';
    [$label, , $intro] = JOB_INTENTS[$key];
    $db = jobs_db($site['host']);
    $w = "j.status='open' AND m.intent=?" . ($reg ? ' AND j.region=?' : ''); $args = $reg ? [$key, $reg['code']] : [$key];
    $st = $db->prepare("SELECT COUNT(*) FROM jobs j JOIN job_intent_map m ON m.id=j.id WHERE $w"); $st->execute($args);
    $total = (int)$st->fetchColumn();
    if ($total < 5) return '';
    $per = 30;
    $st = $db->prepare("SELECT j.* FROM jobs j JOIN job_intent_map m ON m.id=j.id WHERE $w ORDER BY j.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args);
    $jobs = $st->fetchAll();
    if (!$jobs) return '';
    $nf = fn($n) => number_format($n, 0, ',', ' ');
    $title = $label . ($reg ? ' en ' . $reg['name'] : '');
    $base = '/' . $key . '/' . ($reg ? $reg['slug'] . '/' : '');
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › ' . ($reg ? '<a href="/' . $key . '/">' . h($label) . '</a> › ' . h($reg['name']) : h($label)) . '</p>'
        . '<h1>' . h($title) . ' : ' . $nf($total) . ' offres</h1><p class="lead">' . h($intro) . ' ' . $nf($total) . ' offres ouvertes' . ($reg ? ' en ' . h($reg['name']) : ' en France') . ', mises à jour le ' . h(date_fr(now())) . '.</p>';
    if (!$reg && $page === 1) {
        $byR = []; $st = $db->prepare("SELECT j.region, COUNT(*) n FROM jobs j JOIN job_intent_map m ON m.id=j.id WHERE j.status='open' AND m.intent=? GROUP BY j.region"); $st->execute([$key]);
        foreach ($st as $r) $byR[$r['region']] = (int)$r['n'];
        $body .= '<style>' . fr_map_css() . '</style>' . fr_map(array_filter($byR, fn($n) => $n >= 5), '/' . $key . '/', 'offres')
            . count_chips(array_values(array_filter(array_map(fn($c, $v) => $v >= 5 ? [JOB_REGIONS[$c][1], $v, '/' . $key . '/' . JOB_REGIONS[$c][0] . '/'] : null, array_keys($byR), $byR))));
    }
    $others = array_filter(array_map(fn($k, $v) => $k !== $key ? [$v[0], 0, '/' . $k . '/'] : null, array_keys(JOB_INTENTS), JOB_INTENTS));
    $body .= '<div class="grid">' . implode('', array_map('job_card', $jobs)) . '</div>' . pager($base, $page, (int)ceil($total / $per))
        . '<h2>Autres recherches</h2><div class="cnt">' . implode('', array_map(fn($o) => '<a href="' . h($o[2]) . '">' . h($o[0]) . '</a>', $others)) . '</div>' . jobs_disclaimer();
    return layout($site, ['title' => $title . ' : ' . $nf($total) . ' offres' . ($page > 1 ? " — page $page" : '') . ' | ' . $site['name'],
        'desc' => $nf($total) . ' offres : ' . mb_strtolower($title) . '. ' . $intro, 'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''),
        'schema' => [breadcrumbs($site, array_values(array_filter([['Offres d\'emploi', '/offres-emploi/'], [$label, '/' . $key . '/'], $reg ? [$reg['name'], $base] : null])))]], $body);
}

function intents_chips(PDO $db): string
{
    $c = intent_counts($db);
    $rows = []; foreach (JOB_INTENTS as $k => [$label]) if (($c[$k] ?? 0) >= 5) $rows[] = [$label, $c[$k], '/' . $k . '/'];
    return $rows ? '<h2>Recherches populaires</h2>' . count_chips($rows) : '';
}

// ---------- Anciennes adresses WordPress (/localisation/, /region/) ----------
function legacy_job_redirect(array $site, string $path): ?string
{
    $db = jobs_db($site['host']);
    if (preg_match('#^/localisation/([a-z0-9\-]+)/#', $path, $m)) {
        $slug = preg_replace('/^\d{5}-|-(\d{2,3}|2a|2b)$/', '', $m[1]);
        jobs_cities_ready($db);
        $st = $db->prepare('SELECT slug FROM job_cities WHERE slug=? OR slug LIKE ? ORDER BY n DESC LIMIT 1'); $st->execute([$slug, $slug . '-%']);
        if ($c = $st->fetchColumn()) return '/offres-emploi/ville/' . $c . '/';
        $dep = preg_match('/-(\d{2,3}|2a|2b)$/', $m[1], $d) ? strtoupper($d[1]) : (preg_match('/^(\d{2})\d{3}-/', $m[1], $d) ? $d[1] : '');
        if ($dep && function_exists('csp_region_of_dep') && ($r = csp_region_of_dep($dep))) return '/offres-emploi/' . JOB_REGIONS[$r][0] . '/';
        return '/offres-emploi/';
    }
    if (preg_match('#^/region/([a-z\-]+)#', $path, $m)) {
        $old = ['alsace' => '44', 'lorraine' => '44', 'champagne-ardenne' => '44', 'aquitaine' => '75', 'limousin' => '75', 'poitou-charentes' => '75', 'auvergne' => '84', 'rhone-alpes' => '84',
            'bourgogne' => '27', 'franche-comte' => '27', 'centre' => '24', 'basse-normandie' => '28', 'haute-normandie' => '28', 'nord-pas-de-calais' => '32', 'picardie' => '32',
            'languedoc-roussillon' => '76', 'midi-pyrenees' => '76', 'paca' => '93', 'provence-alpes-cote-d-azur' => '93'];
        foreach (JOB_REGIONS as $code => [$slug]) $old[$slug] = $code;
        foreach ($old as $k => $code) if (str_starts_with($m[1], $k)) return '/offres-emploi/' . JOB_REGIONS[$code][0] . '/';
        return '/offres-emploi/';
    }
    return null;
}

// ---------- Recherche d'offres interne (non indexée, jamais en cache) ----------
function page_job_search(array $site, string $q, string $l): string
{
    $db = jobs_db($site['host']);
    $q = trim(mb_substr($q, 0, 60)); $l = trim(mb_substr($l, 0, 40));
    $where = "status='open'"; $args = [];
    foreach (array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY), 0, 4) as $w) { $where .= ' AND (title LIKE ? OR company LIKE ?)'; $args[] = "%$w%"; $args[] = "%$w%"; }
    if ($l !== '') {
        $reg = null; foreach (JOB_REGIONS as $code => [$slug, $name]) if (slugify($name) === slugify($l)) $reg = $code;
        if ($reg) { $where .= ' AND region=?'; $args[] = $reg; }
        elseif (preg_match('/^\d{2,5}$/', $l)) { $where .= ' AND postal LIKE ?'; $args[] = $l . '%'; }
        else { $where .= ' AND city LIKE ?'; $args[] = "%$l%"; }
    }
    $jobs = [];
    $n = 0;
    if ($q !== '' || $l !== '') {
        $st = $db->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM jobs WHERE $where LIMIT 2000)"); $st->execute($args); $n = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT * FROM jobs WHERE $where ORDER BY created_at DESC LIMIT 40"); $st->execute($args); $jobs = $st->fetchAll();
    }
    $form = '<form action="/chercher/" method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin:16px 0"><input name="q" value="' . h($q) . '" placeholder="Métier, mot-clé, entreprise" style="flex:2 1 220px;padding:10px;border:1px solid var(--b);border-radius:8px">'
        . '<input name="l" value="' . h($l) . '" placeholder="Ville, département ou région" style="flex:1 1 160px;padding:10px;border:1px solid var(--b);border-radius:8px">'
        . '<button style="background:var(--c);color:#fff;border:0;border-radius:8px;padding:10px 18px;font-weight:700">Rechercher</button></form>';
    $body = '<h1 style="margin-top:28px">Rechercher une offre d\'emploi</h1>' . $form;
    if ($q !== '' || $l !== '') {
        $body .= '<p><strong>' . ($n >= 2000 ? 'Plus de 2 000' : number_format($n, 0, ',', ' ')) . ' offre' . ($n > 1 ? 's' : '') . '</strong>' . ($q !== '' ? ' pour « ' . h($q) . ' »' : '') . ($l !== '' ? ' à ' . h($l) : '') . '.</p>';
        if ($jobs) $body .= '<div class="grid">' . implode('', array_map('job_card', $jobs)) . '</div>';
        if ($q !== '' && ($m = metier_of($q))) { metier_tables($db); $st = $db->prepare('SELECT slug, name, n FROM job_metiers WHERE slug=?'); $st->execute([$m[0]]); if ($r = $st->fetch()) $body .= '<p><a href="/emploi/' . h($r['slug']) . '/"><strong>Toutes les offres ' . h(metier_de(mb_strtolower($r['name']))) . ' (' . (int)$r['n'] . ') →</strong></a></p>'; }
        if (!$jobs) $body .= '<p>Aucune offre ne correspond. Essayez un terme plus court ou consultez <a href="/emploi/">les offres par métier</a>.</p>';
    } else $body .= (function_exists('intents_chips') ? intents_chips($db) : '');
    return layout($site, ['title' => 'Rechercher une offre d\'emploi' . ($q !== '' ? ' : ' . $q : '') . ' | ' . $site['name'], 'desc' => '', 'robots' => 'noindex,follow'], $body);
}

// Offres du même métier (même région en priorité) sous une fiche : plus de pages vues par visite.
function metier_similar_for_job(array $site, array $j): string
{
    $db = jobs_db($site['host']); metier_tables($db);
    $st = $db->prepare('SELECT mslug FROM job_metier_map WHERE id=?'); $st->execute([$j['id']]);
    if (!($ms = $st->fetchColumn())) return '';
    $st = $db->prepare("SELECT j.* FROM jobs j JOIN job_metier_map mm ON mm.id=j.id WHERE mm.mslug=? AND j.status='open' AND j.id<>? ORDER BY (j.region=?) DESC, j.created_at DESC LIMIT 6");
    $st->execute([$ms, $j['id'], $j['region']]);
    $rows = $st->fetchAll();
    return $rows ? '<h2>Offres proches pour ce métier</h2><div class="grid">' . implode('', array_map('job_card', $rows)) . '</div>' : '';
}

// ---------- Baromètre mensuel de l'emploi (chiffres datés, cités par les IA) ----------
function barometre_snapshot(PDO $db): void
{
    $db->exec('CREATE TABLE IF NOT EXISTS job_barometre(month TEXT PRIMARY KEY, data TEXT, updated TEXT)');
    $q = fn(string $sql) => $db->query($sql)->fetchAll();
    $d = [
        'total' => (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn(),
        'new7' => (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND created_at>=datetime('now','-7 days')")->fetchColumn(),
        'regions' => array_column($q("SELECT region, COUNT(*) n FROM jobs WHERE status='open' GROUP BY region"), 'n', 'region'),
        'contracts' => array_column($q("SELECT contract, COUNT(*) n FROM jobs WHERE status='open' GROUP BY contract"), 'n', 'contract'),
        'public' => (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND id LIKE 'csp-%'")->fetchColumn(),
        'metiers' => array_column($q('SELECT slug, n FROM job_metiers ORDER BY n DESC LIMIT 40'), 'n', 'slug'),
        'names' => array_column($q('SELECT slug, name FROM job_metiers'), 'name', 'slug'),
        'salaires' => array_map(fn($r) => [(float)$r['med'], (float)$r['p25'], (float)$r['p75'], (int)$r['n']], array_column($q('SELECT slug, med, p25, p75, n FROM job_metier_sal'), null, 'slug')),
        'intents' => function_exists('intent_counts') ? intent_counts($db) : [],
    ];
    $db->prepare('INSERT OR REPLACE INTO job_barometre(month,data,updated) VALUES(?,?,?)')->execute([gmdate('Y-m'), json_encode($d, JSON_UNESCAPED_UNICODE), now()]);
}

function page_barometre(array $site): string
{
    $db = jobs_db($site['host']);
    $db->exec('CREATE TABLE IF NOT EXISTS job_barometre(month TEXT PRIMARY KEY, data TEXT, updated TEXT)');
    $rows = $db->query('SELECT month, data, updated FROM job_barometre ORDER BY month DESC LIMIT 2')->fetchAll();
    if (!$rows) return '';
    $d = json_decode($rows[0]['data'], true); $p = isset($rows[1]) ? json_decode($rows[1]['data'], true) : null;
    $mois = ['01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril', '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août', '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'];
    $label = $mois[substr($rows[0]['month'], 5, 2)] . ' ' . substr($rows[0]['month'], 0, 4);
    $nf = fn($n) => number_format((float)$n, 0, ',', ' ');
    $pct = fn($a, $b) => $b ? sprintf('%+d %%', round(100 * ($a - $b) / $b)) : '—';
    $evo = fn($a, $b) => $p ? ' (' . $pct($a, $b) . ' sur un mois)' : '';
    $tot = (int)$d['total'];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › Baromètre de l\'emploi</p>'
        . '<h1>Baromètre de l\'emploi en France — ' . h($label) . '</h1>'
        . '<p class="lead">Chiffres calculés sur les <strong>' . $nf($tot) . ' offres d\'emploi ouvertes</strong> recensées par ' . h($site['name']) . ' (France Travail, Choisir le service public, La bonne alternance et partenaires). Données mises à jour le ' . h(date_fr($rows[0]['updated'])) . '.</p>'
        . '<div class="kpi"><div><b>' . $nf($tot) . '</b>offres ouvertes' . h($evo($tot, $p['total'] ?? 0)) . '</div><div><b>' . $nf($d['new7']) . '</b>nouvelles offres en 7 jours</div>'
        . '<div><b>' . $nf($d['public']) . '</b>offres d\'emploi public</div><div><b>' . $nf($d['contracts']['alternance'] ?? 0) . '</b>offres en alternance</div></div>';
    // régions
    arsort($d['regions']); $tr = '';
    foreach ($d['regions'] as $code => $n) if (isset(JOB_REGIONS[$code])) $tr .= '<tr><td><a href="/offres-emploi/' . JOB_REGIONS[$code][0] . '/">' . h(JOB_REGIONS[$code][1]) . '</a></td><td align="right">' . $nf($n) . '</td><td align="right">' . round(100 * $n / max(1, $tot), 1) . ' %</td>' . ($p ? '<td align="right">' . $pct($n, $p['regions'][$code] ?? 0) . '</td>' : '') . '</tr>';
    $body .= '<h2>Offres d\'emploi par région</h2><div style="overflow-x:auto"><table><tr><th>Région</th><th>Offres</th><th>Part</th>' . ($p ? '<th>Évolution</th>' : '') . '</tr>' . $tr . '</table></div>';
    // contrats
    arsort($d['contracts']); $tc = '';
    foreach ($d['contracts'] as $k => $n) if ($k !== '') $tc .= '<tr><td>' . h(JOB_CONTRACTS[$k] ?? ucfirst($k)) . '</td><td align="right">' . $nf($n) . '</td><td align="right">' . round(100 * $n / max(1, $tot), 1) . ' %</td></tr>';
    $body .= '<h2>Répartition par type de contrat</h2><table><tr><th>Contrat</th><th>Offres</th><th>Part</th></tr>' . $tc . '</table>';
    // métiers qui recrutent
    $tm = ''; $i = 0;
    foreach ($d['metiers'] as $s => $n) { if (++$i > 25) break; $sal = $d['salaires'][$s] ?? null;
        $tm .= '<tr><td>' . $i . '</td><td><a href="/emploi/' . h($s) . '/">' . h($d['names'][$s] ?? $s) . '</a></td><td align="right">' . $nf($n) . '</td><td align="right">' . ($sal ? '<a href="/salaire/' . h($s) . '/">' . metier_fmt_eur($sal[0]) . '</a>' : '—') . '</td>' . ($p ? '<td align="right">' . (isset($p['metiers'][$s]) ? $pct($n, $p['metiers'][$s]) : 'nouveau') . '</td>' : '') . '</tr>'; }
    $body .= '<h2>Les 25 métiers qui recrutent le plus</h2><div style="overflow-x:auto"><table><tr><th>#</th><th>Métier</th><th>Offres</th><th>Salaire médian brut/mois</th>' . ($p ? '<th>Évolution</th>' : '') . '</tr>' . $tm . '</table></div>';
    // salaires
    $sal = $d['salaires']; uasort($sal, fn($a, $b) => $b[0] <=> $a[0]);
    $ts = fn(array $list) => implode('', array_map(fn($s, $v) => '<tr><td><a href="/salaire/' . h($s) . '/">' . h($d['names'][$s] ?? $s) . '</a></td><td align="right">' . metier_fmt_eur($v[0]) . '</td><td align="right">' . metier_fmt_eur($v[1]) . ' – ' . metier_fmt_eur($v[2]) . '</td></tr>', array_keys($list), $list));
    $body .= '<h2>Salaires proposés : les mieux et les moins bien payés</h2><p>Salaires bruts mensuels médians proposés dans les offres qui indiquent une rémunération.</p>'
        . '<h3>Les 10 métiers les mieux payés</h3><table><tr><th>Métier</th><th>Médiane</th><th>Fourchette</th></tr>' . $ts(array_slice($sal, 0, 10, true)) . '</table>'
        . '<h3>Les 10 métiers les moins bien payés</h3><table><tr><th>Métier</th><th>Médiane</th><th>Fourchette</th></tr>' . $ts(array_slice($sal, -10, 10, true)) . '</table>';
    // intentions
    if (!empty($d['intents'])) $body .= '<h2>Offres accessibles</h2>' . count_chips(array_values(array_filter(array_map(fn($k, $v) => isset(JOB_INTENTS[$k]) ? [JOB_INTENTS[$k][0], $v, '/' . $k . '/'] : null, array_keys($d['intents']), $d['intents']))));
    $body .= '<h2>Méthodologie</h2><p>Le baromètre est calculé automatiquement sur l\'ensemble des offres ouvertes au jour de la mise à jour, dédoublonnées par source. Les métiers sont regroupés à partir des intitulés des offres ; les salaires médians portent sur les offres indiquant une rémunération, converties en brut mensuel temps plein. Les évolutions comparent au mois précédent. Reproduction autorisée avec mention « source : ' . h($site['name']) . ' (' . h($site['host']) . ') ».</p>' . jobs_disclaimer();
    $schema = [breadcrumbs($site, [['Baromètre de l\'emploi', '/barometre-emploi/']]),
        ['@context' => 'https://schema.org', '@type' => 'Dataset', 'name' => 'Baromètre de l\'emploi en France — ' . $label, 'description' => 'Offres d\'emploi ouvertes en France par région, contrat et métier, avec salaires médians proposés.',
            'url' => 'https://' . $site['host'] . '/barometre-emploi/', 'temporalCoverage' => $rows[0]['month'], 'dateModified' => substr($rows[0]['updated'], 0, 10), 'spatialCoverage' => 'France',
            'creator' => ['@type' => 'Organization', 'name' => $site['name'], 'url' => 'https://' . $site['host'] . '/'], 'license' => 'https://creativecommons.org/licenses/by/4.0/', 'isAccessibleForFree' => true]];
    return layout($site, ['title' => 'Baromètre de l\'emploi ' . $label . ' : ' . $nf($tot) . ' offres, métiers et salaires | ' . $site['name'],
        'desc' => 'Baromètre de l\'emploi ' . $label . ' : ' . $nf($tot) . ' offres ouvertes, régions, contrats, métiers qui recrutent et salaires médians. Chiffres datés et sourcés.',
        'canonical' => 'https://' . $site['host'] . '/barometre-emploi/', 'schema' => $schema], $body);
}

// ---------- /llms.txt : sommaire du site pour les assistants IA ----------
function out_llms_txt(array $site): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $b = 'https://' . $site['host'];
    $db = jobs_db($site['host']);
    $tot = number_format((int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn(), 0, ',', ' ');
    echo "# {$site['name']}\n\n> Agrégateur français d'offres d'emploi : $tot offres ouvertes (CDI, CDD, intérim, alternance, emploi public), mises à jour plusieurs fois par jour depuis France Travail, Choisir le service public (DGAFP), La bonne alternance et des partenaires. Chaque offre renvoie vers le site d'origine pour postuler.\n\n";
    echo "## Sections principales\n- [Offres d'emploi par région]($b/offres-emploi/)\n- [Offres par métier]($b/emploi/) : nombre d'offres, salaire médian, villes qui recrutent, formations\n- [Salaires par métier]($b/salaire/) : salaires bruts mensuels médians proposés dans les offres\n- [Emploi public]($b/emploi-public/) : fonction publique d'État, territoriale et hospitalière, par type d'employeur et par département\n- [Baromètre de l'emploi]($b/barometre-emploi/) : chiffres mensuels datés (régions, contrats, métiers, salaires)\n- [Plan du site]($b/plan-du-site/)\n\n";
    echo "## Recherches fréquentes\n";
    foreach (JOB_INTENTS as $k => [$l]) echo "- [$l]($b/$k/)\n";
    echo "\n## Métiers qui recrutent le plus\n";
    foreach ($db->query('SELECT slug, name, n FROM job_metiers ORDER BY n DESC LIMIT 30') as $m) echo "- [{$m['name']}]($b/emploi/{$m['slug']}/) : {$m['n']} offres\n";
    echo "\n## Sources et méthode\n- [Nos sources]($b/nos-sources/)\n- Données dédoublonnées ; offres expirées retirées automatiquement. Citation autorisée avec mention de la source ({$site['host']}).\n";
    exit;
}
