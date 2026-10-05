<?php
// Pages entreprise « X recrute » : /recruteur/, /recruteur/<entreprise>/, /recruteur/<entreprise>/<ville>/.
// Ces URL existaient sur l'ancien WordPress et restent la première source de clics Google (« zeop recrutement »…).
// Table job_companies reconstruite à chaque synchronisation ; les pages lisent les offres via l'index ix_jobs_company.

const COMPANY_MIN_CITY = 5;   // offres minimales dans une ville pour une page « X recrute à Ville »
const COMPANY_INDEX_MAX = 400; // entreprises listées sur /recruteur/

function company_short(string $co): string { return trim(preg_replace('/\s+—\s+.*$/u', '', $co)); }
// Nom affiché : « RANDSTAD » → « Randstad » (la base garde la graphie d'origine, utilisée pour les requêtes)
function company_display(string $co): string { $s = company_short($co); return (mb_strlen($s) > 3 && $s === mb_strtoupper($s)) ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8') : $s; }
function company_slug(string $co): string { return slugify(company_short($co), 60); }
function company_is_real(string $co): bool { $s = company_short($co); return $s !== '' && !str_contains($co, '—') && !preg_match('/^communes?\b|non communiqu/iu', $s); }

function company_tables(PDO $db): void
{
    static $ok = false; if ($ok) return; $ok = true;
    $db->exec('CREATE TABLE IF NOT EXISTS job_companies(slug TEXT PRIMARY KEY, name TEXT, n INTEGER, n_closed INTEGER, cities TEXT, sectors TEXT, contracts TEXT, regions TEXT, updated TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_jobs_company ON jobs(status, company)');
}

// Reconstruit job_companies (appelé par jobs_sync, après la synchro). Les entreprises sans offre ouverte depuis moins d'un an
// sont gardées avec n=0 pour que leur page reste en 200 (noindex) avec des offres proches, au lieu d'un 410.
function companies_build(PDO $db): int
{
    company_tables($db);
    $g = [];
    foreach ($db->query("SELECT company, status, city, sector, contract, region, COUNT(*) n FROM jobs WHERE company<>'' AND status IN ('open','closed') GROUP BY company, status, city, sector, contract, region") as $r) {
        if (!company_is_real((string)$r['company'])) continue;
        $slug = company_slug((string)$r['company']);
        if ($slug === '') continue;
        $c = &$g[$slug];
        $c ??= ['name' => company_short((string)$r['company']), 'n' => 0, 'n_closed' => 0, 'cities' => [], 'sectors' => [], 'contracts' => [], 'regions' => []];
        $n = (int)$r['n'];
        if ($r['status'] !== 'open') { $c['n_closed'] += $n; continue; }
        $c['n'] += $n;
        if ($r['city'] !== '') { $k = job_city_norm((string)$r['city']); $c['cities'][$k] = ($c['cities'][$k] ?? 0) + $n; }
        if ($r['sector'] !== '') $c['sectors'][$r['sector']] = ($c['sectors'][$r['sector']] ?? 0) + $n;
        if ($r['contract'] !== '') $c['contracts'][$r['contract']] = ($c['contracts'][$r['contract']] ?? 0) + $n;
        if (isset(JOB_REGIONS[$r['region']])) $c['regions'][$r['region']] = ($c['regions'][$r['region']] ?? 0) + $n;
        unset($c);
    }
    $db->beginTransaction();
    $db->exec('DELETE FROM job_companies');
    $ins = $db->prepare('INSERT INTO job_companies(slug,name,n,n_closed,cities,sectors,contracts,regions,updated) VALUES(?,?,?,?,?,?,?,?,?)');
    $now = now();
    foreach ($g as $slug => $c) {
        foreach (['cities', 'sectors', 'contracts', 'regions'] as $k) { arsort($c[$k]); $c[$k] = array_slice($c[$k], 0, 40, true); }
        $ins->execute([$slug, $c['name'], $c['n'], $c['n_closed'], json_encode($c['cities'], JSON_UNESCAPED_UNICODE), json_encode($c['sectors'], JSON_UNESCAPED_UNICODE), json_encode($c['contracts']), json_encode($c['regions']), $now]);
    }
    $db->commit();
    return count($g);
}

function companies_ready(PDO $db): void
{
    static $done = false; if ($done) return; $done = true;
    company_tables($db);
    if (!(int)$db->query('SELECT COUNT(*) FROM job_companies')->fetchColumn()) companies_build($db);
}

function company_get(PDO $db, string $slug): ?array
{
    companies_ready($db);
    $st = $db->prepare('SELECT * FROM job_companies WHERE slug=?'); $st->execute([$slug]);
    if (!$c = $st->fetch()) return null;
    foreach (['cities', 'sectors', 'contracts', 'regions'] as $k) $c[$k] = json_decode((string)$c[$k], true) ?: [];
    return $c;
}

function company_link(string $co, ?int $n = null): string
{
    if (!company_is_real($co)) return h(company_display($co));
    return '<a href="/recruteur/' . h(company_slug($co)) . '/">' . h(company_display($co)) . '</a>' . ($n !== null ? ' (' . $n . ')' : '');
}

function company_jobs(PDO $db, array $c, string $cityNorm = '', int $limit = 36): array
{
    // on retrouve toutes les graphies de l'entreprise (« Randstad », « Randstad — Lyon »…) par le slug
    $st = $db->prepare("SELECT * FROM jobs WHERE status='open' AND company >= ? AND company < ? ORDER BY created_at DESC LIMIT 600"); // borne haute : utilise l'index ix_jobs_company
    $st->execute([$c['name'], $c['name'] . '~']);
    $rows = [];
    foreach ($st as $j) {
        if (company_slug((string)$j['company']) !== $c['slug']) continue;
        if ($cityNorm !== '' && job_city_norm((string)$j['city']) !== $cityNorm) continue;
        $rows[] = $j;
        if (count($rows) >= $limit) break;
    }
    return $rows;
}

// Grille de cartes avec un bloc publicitaire inséré après la 4e carte, puis tous les 12 (listes région, ville, métier, entreprise).
function job_grid(array $site, array $jobs, bool $ads = true): string
{
    if (!$jobs) return '';
    $cards = array_map('job_card', $jobs);
    if (!$ads || !$site['adsense']) return '<div class="grid">' . implode('', $cards) . '</div>';
    $out = ''; $chunks = [array_splice($cards, 0, 4)];
    while ($cards) $chunks[] = array_splice($cards, 0, 12);
    foreach ($chunks as $i => $ch) $out .= '<div class="grid">' . implode('', $ch) . '</div>' . ($i < count($chunks) - 1 ? job_ad($site) : '');
    return $out;
}

function page_companies_index(array $site): string
{
    $db = jobs_db($site['host']); companies_ready($db);
    $rows = $db->query('SELECT slug, name, n FROM job_companies WHERE n > 0 ORDER BY n DESC LIMIT ' . COMPANY_INDEX_MAX)->fetchAll();
    if (!$rows) return '';
    $total = (int)$db->query('SELECT COUNT(*) FROM job_companies WHERE n > 0')->fetchColumn();
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › Entreprises qui recrutent</p>'
        . '<h1>Entreprises qui recrutent en ce moment</h1>'
        . '<p>' . number_format($total, 0, ',', ' ') . ' employeurs publient actuellement des offres d\'emploi. Chaque fiche regroupe les postes ouverts, les villes et les types de contrat proposés, avec un lien direct vers la candidature.</p>'
        . job_ad($site)
        . '<h2>Les employeurs qui publient le plus d\'offres</h2>' . count_chips(array_map(fn($r) => [company_display($r['name']), $r['n'], '/recruteur/' . $r['slug'] . '/'], $rows))
        . '<h2>Chercher un employeur</h2><form class="q" action="/chercher/" method="get" style="max-width:420px"><input type="search" name="q" placeholder="Nom de l\'entreprise ou métier" aria-label="Rechercher"></form>'
        . jobs_disclaimer();
    return layout($site, ['title' => 'Entreprises qui recrutent : ' . number_format($total, 0, ',', ' ') . ' employeurs avec des offres en cours | ' . $site['name'],
        'desc' => 'Liste des entreprises qui recrutent en ce moment en France : offres d\'emploi ouvertes par employeur, villes, contrats, candidature directe.',
        'canonical' => 'https://' . $site['host'] . '/recruteur/', 'schema' => [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], ['Entreprises qui recrutent', '/recruteur/']])]], $body);
}

function page_company(array $site, string $slug, string $citySlug = ''): string
{
    $db = jobs_db($site['host']);
    $c = company_get($db, $slug);
    if (!$c) return company_unknown($site, $db, $slug); // entreprise jamais vue : offres proches déduites du nom, en 410
    $name = company_display($c['name']);
    $city = null;
    if ($citySlug !== '') {
        foreach ($c['cities'] as $cn => $n) if (slugify($cn, 60) === $citySlug && $n >= COMPANY_MIN_CITY) { $city = [$cn, $n]; break; }
        if (!$city) { header('Location: /recruteur/' . $slug . '/', true, 301); return 'REDIRECT'; }
    }
    $jobs = company_jobs($db, $c, $city[0] ?? '');
    $n = $city ? count($jobs) : (int)$c['n'];
    $nf = fn($x) => number_format((int)$x, 0, ',', ' ');
    $where = $city ? ' à ' . $city[0] : '';
    $base = '/recruteur/' . $slug . '/' . ($city ? $citySlug . '/' : '');
    $crumbs = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/recruteur/">Entreprises qui recrutent</a> › ' . ($city ? '<a href="/recruteur/' . h($slug) . '/">' . h($name) . '</a> › ' . h($city[0]) : h($name)) . '</p>';
    $bc = [['Entreprises qui recrutent', '/recruteur/'], [$name, '/recruteur/' . $slug . '/']];
    if ($city) $bc[] = [$city[0], $base];

    if ($n === 0 || !$jobs) { // plus d'offre en ce moment : page conservée (200, noindex) avec des offres proches
        $sect = array_key_first($c['sectors']); $reg = array_key_first($c['regions']);
        $sim = [];
        if ($sect) { $st = $db->prepare("SELECT * FROM jobs WHERE status='open' AND sector=? ORDER BY created_at DESC LIMIT 12"); $st->execute([$sect]); $sim = $st->fetchAll(); }
        if (count($sim) < 6 && $reg) { $st = $db->prepare("SELECT * FROM jobs WHERE status='open' AND region=? ORDER BY created_at DESC LIMIT 12"); $st->execute([$reg]); $sim = array_merge($sim, $st->fetchAll()); }
        $body = $crumbs . '<h1>' . h($name) . ' : offres d\'emploi</h1>'
            . '<p class="lead">' . h($name) . ' n\'a pas d\'offre d\'emploi publiée en ce moment' . $where . '. ' . ($c['n_closed'] ? 'Cet employeur a publié ' . $nf($c['n_closed']) . ' offres au cours des douze derniers mois ; ' : '') . 'voici des offres proches encore ouvertes.</p>'
            . job_grid($site, array_slice($sim, 0, 12))
            . '<p><a href="/recruteur/">Toutes les entreprises qui recrutent</a> · <a href="/offres-emploi/">Toutes les offres d\'emploi</a></p>' . jobs_disclaimer();
        return layout($site, ['title' => h($name) . ' recrutement : offres d\'emploi | ' . $site['name'], 'desc' => $name . ' : pas d\'offre d\'emploi ouverte actuellement. Offres proches et entreprises du même secteur qui recrutent.', 'robots' => 'noindex,follow', 'canonical' => 'https://' . $site['host'] . $base], $body);
    }

    $contracts = []; $cities = []; $sal = []; $sectors = [];
    foreach ($jobs as $j) {
        $contracts[$j['contract'] ?: 'autre'] = ($contracts[$j['contract'] ?: 'autre'] ?? 0) + 1;
        if ($j['sector'] !== '') $sectors[$j['sector']] = ($sectors[$j['sector']] ?? 0) + 1;
        if (($s = job_salary((string)$j['salary'])) && ($m = job_salary_monthly($s)) > 900 && $m < 20000) $sal[] = $m;
    }
    arsort($contracts); arsort($sectors);
    $topC = array_key_first($contracts); $topS = array_key_first($sectors);
    $cityRows = [];
    if (!$city) foreach ($c['cities'] as $cn => $cnN) { $cityRows[] = [$cn, $cnN, $cnN >= COMPANY_MIN_CITY ? '/recruteur/' . $slug . '/' . slugify($cn, 60) . '/' : '']; }
    sort($sal); $med = $sal ? $sal[intdiv(count($sal), 2)] : 0;
    $regNames = array_map(fn($r) => JOB_REGIONS[$r][1], array_keys($c['regions']));
    $firstCity = array_key_first($c['cities']);

    $intro = '<p class="lead">' . h($name) . ' recrute : <strong>' . $nf($n) . ' offre' . ($n > 1 ? 's' : '') . ' d\'emploi</strong>' . h($where) . ', mise' . ($n > 1 ? 's' : '') . ' à jour aujourd\'hui.'
        . ($topC ? ' Les postes sont surtout proposés en ' . h(JOB_CONTRACTS[$topC] ?? $topC) . ' (' . round(100 * $contracts[$topC] / $n) . ' % des offres)' : '')
        . ($topS ? ', dans le secteur ' . h(mb_strtolower($topS)) : '') . '.'
        . (!$city && $firstCity ? ' ' . h($name) . ' recrute principalement à ' . h($firstCity) . (count($c['cities']) > 1 ? ' et dans ' . (count($c['cities']) - 1) . ' autre' . (count($c['cities']) > 2 ? 's' : '') . ' ville' . (count($c['cities']) > 2 ? 's' : '') : '') . ($regNames ? ' (' . h(implode(', ', array_slice($regNames, 0, 3))) . ')' : '') . '.' : '')
        . ($med ? ' Salaire médian annoncé : ' . $nf(round($med / 10) * 10) . ' € par mois.' : '')
        . ' Chaque offre renvoie vers le site d\'origine, où se fait la candidature.</p>';

    $kpi = '<div class="kpi"><div><b>' . $nf($n) . '</b><span>offres ouvertes</span></div>'
        . ($topC ? '<div><b>' . h(JOB_CONTRACTS[$topC] ?? $topC) . '</b><span>contrat principal</span></div>' : '')
        . (!$city ? '<div><b>' . count($c['cities']) . '</b><span>ville' . (count($c['cities']) > 1 ? 's' : '') . '</span></div>' : '')
        . ($med ? '<div><b>' . $nf(round($med / 10) * 10) . ' €</b><span>salaire médian / mois</span></div>' : '') . '</div>';

    $faq = [
        ["Comment postuler chez $name ?", "Chaque offre d'emploi de $name listée ici renvoie vers son annonce d'origine (site de l'entreprise, France Travail, Adzuna ou partenaire), où se fait la candidature. Préparez un CV à jour et une lettre adaptée au poste."],
        ["$name recrute-t-il en ce moment ?", "Oui : " . $nf($n) . " offre" . ($n > 1 ? 's sont' : ' est') . " actuellement ouverte" . ($n > 1 ? 's' : '') . $where . ". La liste est actualisée plusieurs fois par jour à partir des annonces publiées par l'employeur."],
    ];
    if ($topC) $faq[] = ["Quels types de contrat propose $name ?", implode(', ', array_map(fn($k, $v) => (JOB_CONTRACTS[$k] ?? ucfirst($k)) . ' (' . $v . ')', array_keys($contracts), $contracts)) . '.'];

    $body = $crumbs . '<h1>' . h($name) . ' recrute' . h($where) . ' : ' . $nf($n) . ' offre' . ($n > 1 ? 's' : '') . ' d\'emploi</h1>' . $intro . $kpi
        . '<h2>Offres d\'emploi ' . h($name) . h($where) . '</h2>' . job_grid($site, $jobs)
        . ($cityRows ? '<h2>Où ' . h($name) . ' recrute</h2>' . count_chips($cityRows) : '')
        . (count($contracts) > 1 ? '<h2>Types de contrat</h2>' . count_chips(array_map(fn($k, $v) => [JOB_CONTRACTS[$k] ?? ucfirst($k), $v, ''], array_keys($contracts), $contracts)) : '')
        . (function_exists('metier_links_for_job') && $jobs ? metier_links_for_job($site, $jobs[0]) : '')
        . '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq))
        . '<p><a href="/recruteur/">Toutes les entreprises qui recrutent</a>' . ($topS ? ' · <a href="/offres-emploi/">Toutes les offres d\'emploi</a>' : '') . '</p>' . jobs_disclaimer();

    $schema = [breadcrumbs($site, $bc),
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)],
        ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => "Offres d'emploi $name$where", 'numberOfItems' => min(count($jobs), 30),
            'itemListElement' => array_map(fn($i, $j) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => 'https://' . $site['host'] . job_url($j), 'name' => $j['title']], array_keys(array_slice($jobs, 0, 30)), array_slice($jobs, 0, 30))]];
    return layout($site, [
        'title' => $name . ' recrutement' . $where . ' : ' . $nf($n) . ' offre' . ($n > 1 ? 's' : '') . ' d\'emploi' . ($topC ? ' ' . (JOB_CONTRACTS[$topC] ?? '') : '') . ' | ' . $site['name'],
        'desc' => mb_strimwidth("$name recrute$where : " . $nf($n) . " offre" . ($n > 1 ? 's' : '') . " d'emploi en cours" . ($topC ? ', surtout en ' . (JOB_CONTRACTS[$topC] ?? $topC) : '') . ($firstCity && !$city ? ", notamment à $firstCity" : '') . ($med ? ', salaire médian ' . $nf(round($med / 10) * 10) . ' €/mois' : '') . '. Postulez directement auprès de l\'employeur.', 0, 158, '…'),
        'canonical' => 'https://' . $site['host'] . $base, 'schema' => $schema,
    ], $body);
}

// Entreprise inconnue de la base : 410 + offres dont l'entreprise ou le titre contient les mots de l'URL.
function company_unknown(array $site, PDO $db, string $slug): string
{
    $words = array_values(array_filter(explode('-', $slug), fn($w) => strlen($w) > 2 && !in_array($w, ['sas', 'sarl', 'groupe', 'group', 'the', 'and', 'les', 'des'], true)));
    $rows = [];
    foreach (array_slice($words, 0, 2) as $w) {
        $st = $db->prepare("SELECT * FROM jobs WHERE status='open' AND (company LIKE ? OR slug LIKE ?) ORDER BY created_at DESC LIMIT 12"); $st->execute(['%' . $w . '%', '%' . $w . '%']);
        foreach ($st as $j) $rows[$j['id']] = $j;
    }
    http_response_code(410);
    $title = ucwords(str_replace('-', ' ', $slug));
    $body = '<div class="layout"><article><p class="crumbs" style="margin-top:24px"><a href="/recruteur/">Entreprises qui recrutent</a></p><h1>' . h($title) . ' : aucune offre d\'emploi en ligne</h1>'
        . '<p class="lead">Nous n\'avons pas d\'offre publiée par cet employeur en ce moment. ' . ($rows ? 'Voici des offres proches encore ouvertes.' : '') . '</p>'
        . job_grid($site, array_values($rows)) . '<p><a href="/recruteur/">Toutes les entreprises qui recrutent</a> · <a href="/offres-emploi/">Toutes les offres d\'emploi</a></p>' . jobs_disclaimer() . '</article></div>';
    return layout($site, ['title' => $title . ' recrutement : offres d\'emploi | ' . $site['name'], 'desc' => 'Aucune offre d\'emploi de ' . $title . ' en ligne actuellement. Offres proches et entreprises qui recrutent.', 'robots' => 'noindex,follow'], $body);
}

// Anciennes URL WordPress /offre-d-emploi/<titre-ville-NN>/ : redirection vers l'offre ouverte la plus proche (mots du titre),
// sinon vers la page métier, sinon vers la liste générale.
function legacy_offre_redirect(array $site, string $path): ?string
{
    if (!preg_match('#^/offre-d-emploi/([a-z0-9\-]+)/?$#', $path, $m)) return null;
    $db = jobs_db($site['host']);
    $stop = ['pour', 'avec', 'sans', 'dans', 'chez', 'poste', 'emploi', 'offre', 'recherche', 'recrute', 'temps', 'plein', 'partiel'];
    $words = array_values(array_unique(array_filter(explode('-', preg_replace('/-\d+$|-fh$|-hf$|-h-f$|-f-h$/', '', $m[1])), fn($w) => strlen($w) > 3 && !in_array($w, $stop, true))));
    if ($words) {
        usort($words, fn($a, $b) => strlen($b) <=> strlen($a));
        $ref = array_flip($words); $best = null; $bs = 0;
        foreach (array_slice($words, 0, 2) as $w) {
            $st = $db->prepare("SELECT slug FROM jobs WHERE status='open' AND " . JOB_INDEXABLE_SQL . " AND slug LIKE ? ORDER BY created_at DESC LIMIT 200"); $st->execute(['%' . $w . '%']);
            foreach ($st as $c) { $s = count(array_intersect_key(array_flip(explode('-', (string)$c['slug'])), $ref)); if ($s > $bs) { $bs = $s; $best = $c['slug']; } }
        }
        if ($best && $bs >= 2) return '/offre/' . $best . '/';
        if (function_exists('metier_of') && ($mt = metier_of(implode(' ', $words)))) { metier_tables($db); $sm = $db->prepare('SELECT 1 FROM job_metiers WHERE slug=?'); $sm->execute([$mt[0]]); if ($sm->fetchColumn()) return '/emploi/' . $mt[0] . '/'; }
        if ($best) return '/offre/' . $best . '/';
    }
    return '/offres-emploi/';
}

function out_sitemap_companies(array $site): void
{
    $db = jobs_db($site['host']); companies_ready($db);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>https://' . $site['host'] . '/recruteur/</loc><lastmod>' . gmdate('Y-m-d') . '</lastmod></url>';
    foreach ($db->query('SELECT slug, cities, updated FROM job_companies WHERE n > 0 ORDER BY n DESC LIMIT 5000') as $r) {
        $lm = substr((string)$r['updated'], 0, 10);
        echo '<url><loc>https://' . $site['host'] . '/recruteur/' . h($r['slug']) . '/</loc><lastmod>' . $lm . '</lastmod></url>';
        foreach (json_decode((string)$r['cities'], true) ?: [] as $cn => $n) if ($n >= COMPANY_MIN_CITY) echo '<url><loc>https://' . $site['host'] . '/recruteur/' . h($r['slug']) . '/' . h(slugify($cn, 60)) . '/</loc><lastmod>' . $lm . '</lastmod></url>';
    }
    echo '</urlset>';
}

// Sortie XML mise en cache sur disque (sitemaps lourds) ; une seule régénération à la fois, les autres servent l'ancienne version.
function xml_cached(array $site, string $key, int $ttl, callable $build): void
{
    $cf = cfg('data_dir') . '/cache/' . $site['host'] . '.' . $key . '.xml';
    header('Content-Type: application/xml; charset=utf-8');
    if (is_file($cf) && filemtime($cf) > time() - $ttl) { readfile($cf); return; }
    $lk = @fopen($cf . '.lock', 'c');
    if ($lk && !flock($lk, LOCK_EX | LOCK_NB) && is_file($cf)) { readfile($cf); return; }
    ignore_user_abort(true); set_time_limit(600);
    ob_start(); $build(); $x = ob_get_clean();
    @file_put_contents($cf, $x, LOCK_EX);
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
    echo $x;
}

// Après une synchro : on ne vide que les pages de liste ; les fiches d'offres encore ouvertes gardent leur cache
// (régénérer 3 000 fiches à chaque passage de robot saturait le serveur). Les pages clés sont ensuite préchauffées.
function jobs_cache_refresh(array $site, PDO $db): array
{
    $dir = cache_dir($site['host']);
    $keep = [];
    foreach ($db->query("SELECT slug FROM jobs WHERE status='open'") as $r) $keep[md5('/offre/' . $r['slug'] . '/?')] = 1;
    $n = 0; $k = 0;
    foreach (glob($dir . '/*.html') ?: [] as $f) { if (isset($keep[basename($f, '.html')])) { $k++; continue; } @unlink($f); $n++; }
    return ['purged' => $n, 'kept' => $k];
}

function jobs_cache_warm(array $site): int
{
    $urls = ['/', '/offres-emploi/', '/recruteur/', '/emploi-public/', '/plan-du-site/', '/emploi/', '/salaire/', '/barometre-emploi/', '/sitemap.xml', '/sitemap-metiers.xml', '/sitemap-recruteurs.xml'];
    foreach (JOB_REGIONS as [$s]) $urls[] = '/offres-emploi/' . $s . '/';
    $n = 0;
    foreach ($urls as $u) {
        $ch = curl_init('https://' . $site['host'] . $u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'fabrique-warm', CURLOPT_NOBODY => false]);
        if (curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200) $n++;
        curl_close($ch);
    }
    return $n;
}
