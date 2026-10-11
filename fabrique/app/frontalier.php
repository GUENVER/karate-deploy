<?php
// Emploi frontalier : offres en Suisse et en Belgique (Adzuna, stockées dans jobs_x) et Luxembourg (Careerjet en direct).
// Table séparée de `jobs` : ces offres ne remontent ni dans les régions françaises, ni dans les alertes, ni dans les statistiques.
declare(strict_types=1);

const FX_ZONES = [
    'geneve' => ['ch', 'Genève', 'Genève', 'Suisse', 'Haute-Savoie et Ain'],
    'lausanne' => ['ch', 'Lausanne', 'Lausanne et canton de Vaud', 'Suisse', 'Haute-Savoie et Doubs'],
    'neuchatel' => ['ch', 'Neuchâtel', 'Neuchâtel et Jura suisse', 'Suisse', 'Doubs'],
    'bale' => ['ch', 'Basel', 'Bâle', 'Suisse', 'Haut-Rhin'],
    'luxembourg' => ['lu', 'Luxembourg', 'Luxembourg', 'Luxembourg', 'Moselle et Meurthe-et-Moselle'],
    'bruxelles' => ['be', 'Bruxelles', 'Bruxelles', 'Belgique', 'Nord'],
    'liege' => ['be', 'Liège', 'Liège', 'Belgique', 'Ardennes'],
    'mons' => ['be', 'Mons', 'Mons, Charleroi et La Louvière', 'Belgique', 'Nord'],
    'tournai' => ['be', 'Tournai', 'Tournai et Mouscron', 'Belgique', 'Nord (métropole lilloise)'],
    'sarrebruck' => ['de', 'Saarbrücken', 'Sarrebruck', 'Allemagne', 'Moselle'],
    'karlsruhe' => ['de', 'Karlsruhe', 'Karlsruhe et Pays de Bade', 'Allemagne', 'Bas-Rhin'],
    'fribourg-en-brisgau' => ['de', 'Freiburg', 'Fribourg-en-Brisgau', 'Allemagne', 'Haut-Rhin'],
    'saint-sebastien' => ['es', 'San Sebastián', 'Saint-Sébastien et Pays basque espagnol', 'Espagne', 'Pyrénées-Atlantiques'],
    'gerone' => ['es', 'Gerona', 'Gérone et Catalogne', 'Espagne', 'Pyrénées-Orientales'],
];
const FX_COUNTRIES = ['ch' => ['Suisse', 'CHF', 'CH', 'fr_CH'], 'be' => ['Belgique', 'EUR', 'BE', 'fr_BE'], 'lu' => ['Luxembourg', 'EUR', 'LU', 'fr_LU'], 'de' => ['Allemagne', 'EUR', 'DE', 'de_DE'], 'es' => ['Espagne', 'EUR', 'ES', 'es_ES']];

function fx_db(array $site): PDO
{
    $db = jobs_db($site['host']);
    static $ok = false;
    if (!$ok) {
        $db->exec("CREATE TABLE IF NOT EXISTS jobs_x(id TEXT PRIMARY KEY, country TEXT, zone TEXT, slug TEXT, title TEXT, company TEXT, description TEXT, city TEXT,
            contract TEXT, contract_label TEXT, worktime TEXT, salary TEXT, sector TEXT, url TEXT, created_at TEXT, seen_at TEXT, status TEXT DEFAULT 'open')");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS jobs_x_slug ON jobs_x(slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS jobs_x_zone ON jobs_x(zone, status, created_at)');
        $ok = true;
    }
    return $db;
}

// Synchronisation (cron 2×/jour) : 2 pages Adzuna par zone suisse ou belge, rayon 25 km ; offres non revues depuis 4 jours fermées.
function fx_sync(array $site, callable $log): int
{
    $id = setting('adzuna_app_id', ''); $key = setting('adzuna_app_key', '');
    if ($id === '' || $key === '') return 0;
    $db = fx_db($site); $now = now(); $n = 0;
    $up = $db->prepare("INSERT INTO jobs_x(id,country,zone,slug,title,company,description,city,contract,contract_label,worktime,salary,sector,url,created_at,seen_at,status)
        VALUES(:id,:country,:zone,:slug,:title,:company,:description,:city,:contract,:contract_label,:worktime,:salary,:sector,:url,:created_at,:seen,'open')
        ON CONFLICT(id) DO UPDATE SET seen_at=excluded.seen_at, status='open', title=excluded.title, description=excluded.description, salary=excluded.salary, url=excluded.url");
    foreach (FX_ZONES as $zone => [$cc, $where]) {
        if ($cc === 'lu') continue;
        $cur = FX_COUNTRIES[$cc][1];
        $got = 0;
        for ($p = 1; $p <= 2; $p++) {
            $j = http_get_json('https://api.adzuna.com/v1/api/jobs/' . $cc . '/search/' . $p . '?' . http_build_query(['app_id' => $id, 'app_key' => $key, 'results_per_page' => 50, 'where' => $where, 'distance' => 25, 'sort_by' => 'date', 'content-type' => 'application/json']));
            $rows = $j['results'] ?? [];
            $db->beginTransaction();
            foreach ($rows as $o) {
                $title = trim(strip_tags((string)($o['title'] ?? ''))); $url = (string)($o['redirect_url'] ?? '');
                if ($title === '' || $url === '') continue;
                $city = (string)($o['location']['display_name'] ?? '');
                $sal = !empty($o['salary_min']) ? number_format((float)$o['salary_min'], 0, ',', ' ') . (!empty($o['salary_max']) && $o['salary_max'] != $o['salary_min'] ? ' à ' . number_format((float)$o['salary_max'], 0, ',', ' ') : '') . ' ' . $cur . ' / an' : '';
                $oid = 'azx-' . $cc . '-' . $o['id'];
                $up->execute(['id' => $oid, 'country' => $cc, 'zone' => $zone, 'slug' => slugify($title . ' ' . $city, 70) . '-' . substr(md5($oid), 0, 6),
                    'title' => $title, 'company' => (string)($o['company']['display_name'] ?? ''), 'description' => trim(strip_tags((string)($o['description'] ?? ''))), 'city' => $city,
                    'contract' => contract_key((string)($o['contract_type'] ?? '')), 'contract_label' => ['permanent' => 'CDI', 'contract' => 'CDD'][$o['contract_type'] ?? ''] ?? '',
                    'worktime' => ['full_time' => 'Temps plein', 'part_time' => 'Temps partiel'][$o['contract_time'] ?? ''] ?? '', 'salary' => $sal,
                    'sector' => (string)($o['category']['label'] ?? ''), 'url' => $url, 'created_at' => gmdate('Y-m-d H:i:s', strtotime((string)($o['created'] ?? 'now'))), 'seen' => $now]);
                $got++;
            }
            $db->commit();
            usleep(400000);
            if (count($rows) < 50) break;
        }
        $n += $got;
        $log(FX_ZONES[$zone][2] . ' : ' . $got . ' offres');
    }
    $db->exec("UPDATE jobs_x SET status='closed' WHERE status='open' AND seen_at < datetime('now','-4 days')");
    $db->exec("DELETE FROM jobs_x WHERE status='closed' AND seen_at < datetime('now','-30 days')");
    return $n;
}

function fx_url(array $j): string { return '/emploi-frontalier/offre/' . $j['slug'] . '/'; }

function fx_card(array $j): string
{
    $co = trim((string)$j['company']);
    $ini = mb_strtoupper(mb_substr($co ?: (string)$j['title'], 0, 1));
    $hue = hexdec(substr(md5($co ?: (string)$j['title']), 0, 2)) * 360 / 255;
    $ck = (string)$j['contract'];
    $tags = '<span class="tag t-' . h($ck ?: 'autre') . '">' . h(JOB_CONTRACTS[$ck] ?? 'Offre') . '</span><span class="tag">' . h(FX_COUNTRIES[$j['country']][0] ?? '') . '</span>';
    return '<div class="card jc"><div class="in"><div class="jc-h"><span class="jc-logo" style="background:hsl(' . (int)$hue . ',55%,42%)" aria-hidden="true">' . h($ini) . '</span><div class="jc-t">'
        . '<h3><a href="' . h(fx_url($j)) . '">' . h($j['title']) . '</a></h3>' . ($co !== '' ? '<p class="jc-co">' . h($co) . '</p>' : '') . '</div></div>'
        . '<div class="jc-tags">' . $tags . '</div>'
        . '<p class="jc-meta">' . ((string)$j['city'] !== '' ? '<span>' . ICO_PIN . h($j['city']) . '</span>' : '') . '<span>' . ICO_CLOCK . h(job_ago((string)$j['created_at'])) . '</span></p>'
        . '<a class="jc-btn" href="' . h(fx_url($j)) . '" tabindex="-1" aria-hidden="true">Voir l\'offre</a></div></div>';
}

function fx_grid(array $site, array $jobs): string
{
    if (!$jobs) return '';
    $cards = array_map('fx_card', $jobs);
    if (empty($site['adsense'])) return '<div class="grid">' . implode('', $cards) . '</div>';
    $out = ''; $chunks = [array_splice($cards, 0, 2)];
    while ($cards) $chunks[] = array_splice($cards, 0, 12);
    foreach ($chunks as $i => $ch) $out .= '<div class="grid">' . implode('', $ch) . '</div>' . ($i < count($chunks) - 1 ? job_ad($site) : '');
    return $out;
}

function fx_counts(PDO $db): array
{
    $c = [];
    foreach ($db->query("SELECT zone, COUNT(*) n FROM jobs_x WHERE status='open' GROUP BY zone") as $r) $c[$r['zone']] = (int)$r['n'];
    return $c;
}

function fx_info(string $cc): string
{
    $t = [
        'ch' => 'Pour travailler en Suisse en résidant en France, un ressortissant européen obtient un <b>permis G</b> (frontalier), demandé par l\'employeur au canton. Le frontalier doit en principe rentrer à son domicile en France au moins une fois par semaine. Le salaire est versé en francs suisses (CHF) et l\'imposition dépend du canton de travail.',
        'lu' => 'Le Luxembourg est le premier pays d\'emploi des frontaliers lorrains. Aucun permis n\'est nécessaire pour un ressortissant européen. Le salaire est en principe imposé au Luxembourg, avec un nombre limité de jours de télétravail possibles depuis la France.',
        'de' => 'Aucun permis n\'est nécessaire pour un ressortissant européen. Les frontaliers d\'Alsace et de Moselle qui travaillent en Allemagne relèvent d\'une convention fiscale franco-allemande qui prévoit, sous conditions de résidence et de lieu de travail proches de la frontière, une imposition en France. Les annonces sont généralement rédigées en allemand.',
        'es' => 'Aucun permis n\'est nécessaire pour un ressortissant européen. Le salaire est en principe imposé en Espagne, et la sécurité sociale relève du pays d\'emploi. Les annonces sont généralement rédigées en espagnol (ou en basque et en catalan).',
        'be' => 'Aucun permis n\'est nécessaire pour un ressortissant européen. Pour les nouveaux frontaliers, le salaire est en principe imposé en Belgique, et la sécurité sociale relève du pays d\'emploi.',
    ];
    return '<div class="box"><h4>Travailler en ' . h(FX_COUNTRIES[$cc][0]) . ' en vivant en France</h4><p>' . $t[$cc] . '</p><p><small>Règles résumées à titre indicatif : vérifiez votre situation auprès de l\'employeur, du centre des impôts ou du réseau EURES avant de vous engager.</small></p></div>';
}

function fx_faq_html(array $faq): string { return '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq)); }
function fx_faq_schema(array $faq): array { return ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)]; }

function page_fx_home(array $site): string
{
    $db = fx_db($site); $cnt = fx_counts($db); $tot = array_sum($cnt);
    $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $blocks = '';
    foreach (['ch', 'lu', 'de', 'be', 'es'] as $cc) {
        $items = '';
        foreach (FX_ZONES as $z => $d) if ($d[0] === $cc) $items .= '<li><a href="/emploi-frontalier/' . $z . '/">Emploi ' . h($d[2]) . '</a>' . (isset($cnt[$z]) ? ' (' . $nf($cnt[$z]) . ' offres)' : '') . ' — depuis ' . h($d[4]) . '</li>';
        $blocks .= '<h2>Travailler en ' . h(FX_COUNTRIES[$cc][0]) . '</h2><ul>' . $items . '</ul>';
    }
    $latest = $db->query("SELECT * FROM jobs_x WHERE status='open' ORDER BY created_at DESC LIMIT 12")->fetchAll();
    $faq = [["Où trouver un emploi frontalier ?", "Les bassins d'emploi frontaliers les plus actifs depuis la France sont Genève et le canton de Vaud (Haute-Savoie, Ain), Bâle (Haut-Rhin), le Luxembourg (Moselle, Meurthe-et-Moselle) l'Allemagne (Moselle, Alsace), la Belgique (Nord, Ardennes) et l'Espagne (Pays basque, Pyrénées-Orientales). " . $site['name'] . " regroupe " . $nf($tot) . " offres ouvertes en Suisse, en Allemagne, en Belgique et en Espagne, mises à jour chaque jour, ainsi que les offres du Luxembourg."],
        ["Faut-il un permis pour travailler en Suisse quand on vit en France ?", "Oui : un ressortissant européen qui réside en France et travaille en Suisse obtient un permis G (frontalier), demandé par l'employeur auprès du canton."],
        ["Comment postuler à une offre frontalière ?", "Chaque offre renvoie vers son site d'origine, où se fait la candidature. Les annonces de Bâle et d'Allemagne sont souvent rédigées en allemand, celles d'Espagne en espagnol."]];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › Emploi frontalier</p>'
        . '<h1>Emploi frontalier : Suisse, Luxembourg, Allemagne, Belgique, Espagne</h1>'
        . '<p class="lead">' . $nf($tot) . ' offres d\'emploi ouvertes à Genève, Lausanne, Bâle, Sarrebruck, Karlsruhe, Fribourg-en-Brisgau, Bruxelles, Liège, Tournai, Saint-Sébastien et Gérone, plus les offres du Luxembourg, pour les personnes qui vivent en France près de la frontière. Mise à jour chaque jour.</p>'
        . $blocks . (function_exists('ew_route') ? '<p><a href="/emploi-etranger/"><strong>Plus loin : emploi à l\'étranger pour francophones (Royaume-Uni, Canada, Espagne, Italie, Pays-Bas) →</strong></a></p>' : '') . job_ad($site)
        . ($latest ? '<h2>Dernières offres frontalières</h2>' . fx_grid($site, $latest) : '')
        . fx_faq_html($faq)
        . '<p class="disc">Offres Suisse et Belgique : Jobs by Adzuna. Offres Luxembourg : Careerjet. La candidature se fait sur le site d\'origine de l\'offre.</p>';
    $schema = [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], ['Emploi frontalier', '/emploi-frontalier/']]), fx_faq_schema($faq)];
    return layout($site, ['title' => 'Emploi frontalier Suisse, Luxembourg, Allemagne, Belgique, Espagne : ' . $nf($tot) . ' offres | ' . $site['name'],
        'desc' => 'Offres d\'emploi frontalier en Suisse, au Luxembourg, en Allemagne, en Belgique et en Espagne pour les résidents français, mises à jour chaque jour.',
        'canonical' => 'https://' . $site['host'] . '/emploi-frontalier/', 'schema' => $schema], $body);
}

function page_fx_zone(array $site, string $zone, int $page): string
{
    if (!isset(FX_ZONES[$zone])) return '';
    [$cc, , $name, $country, $from] = FX_ZONES[$zone];
    $db = fx_db($site); $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $per = 30; $off = ($page - 1) * $per;
    $st = $db->prepare("SELECT COUNT(*) FROM jobs_x WHERE status='open' AND zone=?"); $st->execute([$zone]);
    $tot = (int)$st->fetchColumn();
    if ($page > 1 && $off >= $tot) return '';
    $st = $db->prepare("SELECT * FROM jobs_x WHERE status='open' AND zone=? ORDER BY created_at DESC LIMIT $per OFFSET $off"); $st->execute([$zone]);
    $jobs = $st->fetchAll();
    $base = '/emploi-frontalier/' . $zone . '/';
    $oth = array_diff_key(FX_ZONES, [$zone => 1]);
    $others = implode(' · ', array_map(fn($z, $d) => '<a href="/emploi-frontalier/' . $z . '/">' . h($d[2]) . '</a>', array_keys($oth), $oth));
    $pager = $tot > $off + $per ? '<p><a href="' . $base . 'page/' . ($page + 1) . '/">Offres suivantes →</a></p>' : '';
    $lead = $cc === 'lu'
        ? 'Offres d\'emploi au Luxembourg pour les frontaliers résidant en ' . h($from) . ', fournies en direct par Careerjet.'
        : $nf($tot) . ' offres d\'emploi ouvertes à ' . h($name) . ' (' . h($country) . ') et dans un rayon de 25 km, pour les frontaliers résidant en ' . h($from) . '. Mise à jour chaque jour.';
    $faq = [["Combien d'offres d'emploi frontalier à $name ?", $cc === 'lu' ? "Les offres du Luxembourg sont chargées en direct depuis Careerjet à chaque visite." : "Au " . date_fr(now()) . ", " . $site['name'] . " recense " . $nf($tot) . " offres ouvertes à $name et autour, mises à jour chaque jour."],
        ["Depuis quels départements français travaille-t-on à $name ?", "Les frontaliers qui travaillent dans la zone de $name résident surtout en $from."]];
    $body = '<div class="layout"><article><p class="crumbs" style="margin-top:24px"><a href="/offres-emploi/">Offres d\'emploi</a> › <a href="/emploi-frontalier/">Emploi frontalier</a> › ' . h($name) . '</p>'
        . '<h1>Emploi frontalier ' . h($name) . ($page > 1 ? ' — page ' . $page : '') . '</h1><p class="lead">' . $lead . '</p>'
        . ($jobs ? fx_grid($site, $jobs) . $pager : '') . job_ad($site)
        . (function_exists('careerjet_slot') ? careerjet_slot($site, '', $cc === 'lu' ? 'Luxembourg' : $name, FX_COUNTRIES[$cc][3]) : '')
        . fx_faq_html($faq)
        . '<h2>Autres zones frontalières</h2><p>' . $others . '</p>'
        . '<p class="disc">' . ($cc === 'lu' ? 'Offres : Careerjet.' : 'Offres : Jobs by Adzuna.') . ' La candidature se fait sur le site d\'origine de l\'offre.</p>'
        . '</article><aside>' . fx_info($cc) . '</aside></div>';
    $schema = [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], ['Emploi frontalier', '/emploi-frontalier/'], [$name, $base]]), fx_faq_schema($faq)];
    return layout($site, ['title' => 'Emploi frontalier ' . $name . ($cc !== 'lu' ? ' : ' . $nf($tot) . ' offres' : '') . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'],
        'desc' => mb_strimwidth('Offres d\'emploi frontalier à ' . $name . ' (' . $country . ') pour les résidents de ' . $from . ' : CDI, CDD, salaires, mises à jour chaque jour.', 0, 158, '…'),
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? 'page/' . $page . '/' : ''), 'schema' => $schema], $body);
}

function page_fx_job(array $site, string $slug): ?string
{
    $db = fx_db($site);
    $st = $db->prepare('SELECT * FROM jobs_x WHERE slug=?'); $st->execute([$slug]);
    $j = $st->fetch();
    if (!$j) return '';
    if ($j['status'] !== 'open') { header('Location: /emploi-frontalier/' . $j['zone'] . '/', true, 301); return null; }
    $zone = FX_ZONES[$j['zone']] ?? ['', '', '', '', ''];
    $st = $db->prepare("SELECT * FROM jobs_x WHERE status='open' AND zone=? AND id<>? ORDER BY created_at DESC LIMIT 6"); $st->execute([$j['zone'], $j['id']]);
    $similar = $st->fetchAll();
    $facts = array_filter(['Entreprise' => $j['company'], 'Lieu' => $j['city'], 'Pays' => FX_COUNTRIES[$j['country']][0] ?? '', 'Contrat' => $j['contract_label'] ?: (JOB_CONTRACTS[$j['contract']] ?? ''),
        'Durée du travail' => $j['worktime'], 'Salaire' => $j['salary'], 'Secteur' => $j['sector']]);
    $desc = trim((string)$j['description']);
    $rel = 'sponsored nofollow noopener';
    $body = '<div class="layout"><article><p class="crumbs"><a href="/emploi-frontalier/">Emploi frontalier</a> › <a href="/emploi-frontalier/' . h($j['zone']) . '/">' . h($zone[2]) . '</a></p>'
        . '<h1>' . h($j['title']) . '</h1>'
        . job_summary($j, $facts, $rel)
        . ($desc !== '' ? '<section class="jdesc"><h2>Description du poste</h2><div>' . nl2br(h($desc)) . '</div><p class="jtrunc" style="margin:10px 0 0;padding:10px 12px;border-left:3px solid var(--c);background:rgba(127,127,127,.08);border-radius:6px">Ceci est un extrait de l\'annonce. <a href="' . h($j['url']) . '" rel="' . $rel . '" target="_blank">Lire l\'annonce complète sur le site d\'origine →</a></p></section>' : '')
        . '<p class="disc">Source : Jobs by Adzuna. Publiée le ' . h(date_fr((string)$j['created_at'])) . '.</p>'
        . job_ad($site)
        . ($similar ? '<h2>Autres offres frontalières à ' . h($zone[2]) . '</h2>' . fx_grid($site, $similar) : '')
        . '</article><aside>' . fx_info((string)$j['country']) . '</aside></div>';
    $schema = [breadcrumbs($site, [['Emploi frontalier', '/emploi-frontalier/'], [$zone[2], '/emploi-frontalier/' . $j['zone'] . '/'], [$j['title'], fx_url($j)]])];
    return layout($site, ['title' => mb_substr((string)$j['title'], 0, 60) . ' – ' . $zone[2] . ' (frontalier) | ' . $site['name'],
        'desc' => mb_strimwidth(trim(($j['company'] ? $j['company'] . ' recrute : ' : 'Offre : ') . $j['title'] . ' à ' . $j['city'] . '. ' . preg_replace('/\s+/', ' ', $desc)), 0, 155, '…'),
        'canonical' => 'https://' . $site['host'] . fx_url($j), 'robots' => 'noindex,follow', 'schema' => $schema], $body); // extraits Adzuna : non indexés, comme les offres Adzuna françaises
}

function fx_route(array $site, string $path): ?string
{
    if ($path === '/emploi-frontalier/') return page_fx_home($site);
    if (preg_match('#^/emploi-frontalier/offre/([a-z0-9\-]+)/$#', $path, $m)) return page_fx_job($site, $m[1]);
    if (preg_match('#^/emploi-frontalier/([a-z\-]+)/(?:page/(\d+)/)?$#', $path, $m)) return page_fx_zone($site, $m[1], max(1, (int)($m[2] ?? 1)));
    return '';
}

function fx_sitemap_urls(array $site): array
{
    return array_merge(['/emploi-frontalier/'], array_map(fn($z) => '/emploi-frontalier/' . $z . '/', array_keys(FX_ZONES)));
}
