<?php
// Emploi à l'étranger pour francophones : offres qui demandent le français (Adzuna, 1 synchro/jour), table jobs_w séparée de jobs.
declare(strict_types=1);

// code => [code Adzuna, mot-clé « français » dans la langue du pays, nom, devise, « en/au » , slug, guide]
const EW_COUNTRIES = [
    'royaume-uni' => ['gb', 'french', 'Royaume-Uni', 'GBP', 'au', 'Depuis la fin de la libre circulation (1er janvier 2021), un Français a besoin d\'un visa pour travailler au Royaume-Uni, en général un visa « Skilled Worker » parrainé par l\'employeur. Les personnes installées avant 2021 relèvent du statut EU Settlement Scheme. Les salaires sont en livres sterling.'],
    'allemagne' => ['de', 'französisch', 'Allemagne', 'EUR', 'en', 'Aucun permis n\'est nécessaire pour un ressortissant européen. À l\'arrivée, il faut déclarer son domicile (Anmeldung) auprès de la mairie, ce qui donne accès au numéro fiscal. La plupart des postes demandent aussi l\'allemand.'],
    'espagne' => ['es', 'francés', 'Espagne', 'EUR', 'en', 'Aucun permis n\'est nécessaire pour un ressortissant européen. Il faut obtenir un NIE (numéro d\'identification d\'étranger) et s\'affilier à la sécurité sociale espagnole. Les postes de service client et de tourisme recherchent souvent des francophones.'],
    'italie' => ['it', 'francese', 'Italie', 'EUR', 'en', 'Aucun permis n\'est nécessaire pour un ressortissant européen. Il faut un codice fiscale (numéro fiscal) et, au-delà de trois mois, s\'inscrire à l\'état civil de la commune (anagrafe).'],
    'pays-bas' => ['nl', 'frans', 'Pays-Bas', 'EUR', 'aux', 'Aucun permis n\'est nécessaire pour un ressortissant européen. L\'inscription à la mairie (gemeente) donne le numéro BSN, indispensable pour travailler. L\'anglais est très répandu en entreprise.'],
    'canada' => ['ca', 'français', 'Canada', 'CAD', 'au', 'Un permis de travail est nécessaire. Les 18-35 ans peuvent obtenir un permis vacances-travail (PVT) via le programme Expérience internationale Canada. Le Québec et le Nouveau-Brunswick offrent de nombreux postes en français.'],
];

function ew_db(array $site): PDO
{
    $db = jobs_db($site['host']);
    static $ok = false;
    if (!$ok) {
        $db->exec("CREATE TABLE IF NOT EXISTS jobs_w(id TEXT PRIMARY KEY, country TEXT, slug TEXT, title TEXT, company TEXT, description TEXT, city TEXT, cslug TEXT, area TEXT,
            contract TEXT, contract_label TEXT, worktime TEXT, salary TEXT, sector TEXT, url TEXT, created_at TEXT, seen_at TEXT, status TEXT DEFAULT 'open')");
        $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS jobs_w_slug ON jobs_w(slug)');
        $db->exec('CREATE INDEX IF NOT EXISTS jobs_w_c ON jobs_w(country, status, created_at)');
        $ok = true;
    }
    return $db;
}

function ew_sync(array $site, callable $log): int
{
    $id = setting('adzuna_app_id', ''); $key = setting('adzuna_app_key', '');
    if ($id === '' || $key === '') return 0;
    $db = ew_db($site); $now = now(); $n = 0;
    $up = $db->prepare("INSERT INTO jobs_w(id,country,slug,title,company,description,city,cslug,area,contract,contract_label,worktime,salary,sector,url,created_at,seen_at,status)
        VALUES(:id,:country,:slug,:title,:company,:description,:city,:cslug,:area,:contract,:contract_label,:worktime,:salary,:sector,:url,:created_at,:seen,'open')
        ON CONFLICT(id) DO UPDATE SET seen_at=excluded.seen_at, status='open', title=excluded.title, description=excluded.description, salary=excluded.salary, url=excluded.url");
    foreach (EW_COUNTRIES as $cs => [$cc, $kw, , $cur]) {
        $got = 0;
        for ($p = 1; $p <= 2; $p++) {
            $j = http_get_json('https://api.adzuna.com/v1/api/jobs/' . $cc . '/search/' . $p . '?' . http_build_query(['app_id' => $id, 'app_key' => $key, 'results_per_page' => 50, 'what' => $kw, 'sort_by' => 'date', 'content-type' => 'application/json']));
            $rows = $j['results'] ?? [];
            $db->beginTransaction();
            foreach ($rows as $o) {
                $title = trim(strip_tags((string)($o['title'] ?? ''))); $url = (string)($o['redirect_url'] ?? '');
                if ($title === '' || $url === '') continue;
                $area = (array)($o['location']['area'] ?? []);
                $city = (string)(count($area) >= 3 ? end($area) : ($area[1] ?? ($o['location']['display_name'] ?? '')));
                $sal = !empty($o['salary_min']) ? number_format((float)$o['salary_min'], 0, ',', ' ') . (!empty($o['salary_max']) && $o['salary_max'] != $o['salary_min'] ? ' à ' . number_format((float)$o['salary_max'], 0, ',', ' ') : '') . ' ' . $cur . ' / an' : '';
                $oid = 'azw-' . $cc . '-' . $o['id'];
                $up->execute(['id' => $oid, 'country' => $cs, 'slug' => slugify($title . ' ' . $city, 70) . '-' . substr(md5($oid), 0, 6), 'title' => $title,
                    'company' => (string)($o['company']['display_name'] ?? ''), 'description' => trim(strip_tags((string)($o['description'] ?? ''))), 'city' => $city, 'cslug' => count($area) >= 3 ? slugify($city, 60) : '',
                    'area' => (string)($area[1] ?? ''), 'contract' => contract_key((string)($o['contract_type'] ?? '')), 'contract_label' => ['permanent' => 'CDI', 'contract' => 'CDD'][$o['contract_type'] ?? ''] ?? '',
                    'worktime' => ['full_time' => 'Temps plein', 'part_time' => 'Temps partiel'][$o['contract_time'] ?? ''] ?? '', 'salary' => $sal,
                    'sector' => (string)($o['category']['label'] ?? ''), 'url' => $url, 'created_at' => gmdate('Y-m-d H:i:s', strtotime((string)($o['created'] ?? 'now'))), 'seen' => $now]);
                $got++;
            }
            $db->commit(); usleep(400000);
            if (count($rows) < 50) break;
        }
        $n += $got; $log(EW_COUNTRIES[$cs][2] . ' : ' . $got . ' offres');
    }
    $db->exec("UPDATE jobs_w SET status='closed' WHERE status='open' AND seen_at < datetime('now','-10 days')");
    $db->exec("DELETE FROM jobs_w WHERE status='closed' AND seen_at < datetime('now','-30 days')");
    return $n;
}

function ew_url(array $j): string { return '/emploi-etranger/offre/' . $j['slug'] . '/'; }
function ew_in(string $cs): string { $p = EW_COUNTRIES[$cs][4]; return $p . ' ' . EW_COUNTRIES[$cs][2]; }

function ew_grid(array $site, array $jobs): string
{
    if (!$jobs) return '';
    $cards = array_map(function ($j) {
        $co = trim((string)$j['company']); $ini = mb_strtoupper(mb_substr($co ?: (string)$j['title'], 0, 1)); $hue = hexdec(substr(md5($co ?: (string)$j['title']), 0, 2)) * 360 / 255; $ck = (string)$j['contract'];
        return '<div class="card jc"><div class="in"><div class="jc-h"><span class="jc-logo" style="background:hsl(' . (int)$hue . ',55%,42%)" aria-hidden="true">' . h($ini) . '</span><div class="jc-t"><h3><a href="' . h(ew_url($j)) . '">' . h($j['title']) . '</a></h3>' . ($co !== '' ? '<p class="jc-co">' . h($co) . '</p>' : '') . '</div></div>'
            . '<div class="jc-tags"><span class="tag t-' . h($ck ?: 'autre') . '">' . h(JOB_CONTRACTS[$ck] ?? 'Offre') . '</span><span class="tag">' . h(EW_COUNTRIES[$j['country']][2] ?? '') . '</span></div>'
            . '<p class="jc-meta">' . ((string)$j['city'] !== '' ? '<span>' . ICO_PIN . h($j['city']) . '</span>' : '') . '<span>' . ICO_CLOCK . h(job_ago((string)$j['created_at'])) . '</span></p>'
            . '<a class="jc-btn" href="' . h(ew_url($j)) . '" tabindex="-1" aria-hidden="true">Voir l\'offre</a></div></div>';
    }, $jobs);
    if (empty($site['adsense'])) return '<div class="grid">' . implode('', $cards) . '</div>';
    $out = ''; $chunks = [array_splice($cards, 0, 2)]; while ($cards) $chunks[] = array_splice($cards, 0, 12);
    foreach ($chunks as $i => $ch) $out .= '<div class="grid">' . implode('', $ch) . '</div>' . ($i < count($chunks) - 1 ? job_ad($site) : '');
    return $out;
}

function ew_faq(array $faq): array { return [implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq)), ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)]]; }

function page_ew_home(array $site): string
{
    $db = ew_db($site); $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $cnt = []; foreach ($db->query("SELECT country, COUNT(*) n FROM jobs_w WHERE status='open' GROUP BY country") as $r) $cnt[$r['country']] = (int)$r['n'];
    $tot = array_sum($cnt); $cards = '';
    foreach (EW_COUNTRIES as $cs => $c) $cards .= '<div class="card"><div class="in"><h3><a href="/emploi-etranger/' . $cs . '/">Travailler ' . h(ew_in($cs)) . '</a></h3><p>' . $nf($cnt[$cs] ?? 0) . ' offres pour francophones</p></div></div>';
    $latest = $db->query("SELECT * FROM jobs_w WHERE status='open' ORDER BY created_at DESC LIMIT 12")->fetchAll();
    [$fh, $fs] = ew_faq([["Comment trouver un emploi à l'étranger quand on parle français ?", "Les entreprises qui recherchent des francophones à l'étranger recrutent surtout en service client, vente, tourisme, enseignement et fonctions commerciales. " . $site['name'] . " sélectionne chaque jour les offres qui demandent le français au Royaume-Uni, en Allemagne, en Espagne, en Italie, aux Pays-Bas et au Canada."],
        ["Faut-il un visa pour travailler à l'étranger ?", "Non dans l'Union européenne (Allemagne, Espagne, Italie, Pays-Bas, Belgique) : la libre circulation s'applique. Oui au Royaume-Uni depuis 2021 et au Canada, où un permis de travail est nécessaire (le PVT est possible pour les 18-35 ans)."],
        ["Et la Belgique, la Suisse ou le Luxembourg ?", "Ces pays sont traités dans notre rubrique emploi frontalier, avec les offres de Bruxelles, Liège, Genève, Lausanne et Luxembourg."]]);
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › Emploi à l\'étranger</p>'
        . '<h1>Emploi à l\'étranger pour francophones</h1><p class="lead">' . $nf($tot) . ' offres d\'emploi à l\'étranger qui demandent le français : Royaume-Uni, Allemagne, Espagne, Italie, Pays-Bas et Canada, sélectionnées et mises à jour chaque jour.</p>'
        . '<div class="grid">' . $cards . '</div><p><a href="/emploi-frontalier/">Belgique, Suisse, Luxembourg : voir l\'emploi frontalier →</a></p>' . job_ad($site)
        . ($latest ? '<h2>Dernières offres à l\'étranger</h2>' . ew_grid($site, $latest) : '') . '<h2>Questions fréquentes</h2>' . $fh
        . '<p class="disc">Offres : Jobs by Adzuna. La candidature se fait sur le site d\'origine de l\'offre.</p>';
    return layout($site, ['title' => 'Emploi à l\'étranger pour francophones : ' . $nf($tot) . ' offres | ' . $site['name'],
        'desc' => 'Offres d\'emploi à l\'étranger qui demandent le français : Royaume-Uni, Allemagne, Espagne, Italie, Pays-Bas, Canada. Visa, démarches, mises à jour chaque jour.',
        'canonical' => 'https://' . $site['host'] . '/emploi-etranger/', 'schema' => [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], ['Emploi à l\'étranger', '/emploi-etranger/']]), $fs]], $body);
}

function page_ew_country(array $site, string $cs, string $cslug, int $page): string
{
    if (!isset(EW_COUNTRIES[$cs])) return '';
    [$cc, , $name, , , $guide] = EW_COUNTRIES[$cs];
    $db = ew_db($site); $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $city = '';
    if ($cslug !== '') { $st = $db->prepare("SELECT city, COUNT(*) n FROM jobs_w WHERE status='open' AND country=? AND cslug=? GROUP BY city ORDER BY n DESC LIMIT 1"); $st->execute([$cs, $cslug]); $r = $st->fetch(); if (!$r || (int)$r['n'] < 5) return ''; $city = (string)$r['city']; }
    $w = "status='open' AND country=?" . ($city !== '' ? ' AND cslug=?' : ''); $args = $city !== '' ? [$cs, $cslug] : [$cs];
    $st = $db->prepare("SELECT COUNT(*) FROM jobs_w WHERE $w"); $st->execute($args); $tot = (int)$st->fetchColumn();
    $per = 30; if ($page > 1 && ($page - 1) * $per >= $tot) return '';
    $st = $db->prepare("SELECT * FROM jobs_w WHERE $w ORDER BY created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args); $jobs = $st->fetchAll();
    $cities = []; $st = $db->prepare("SELECT city, cslug, COUNT(*) n FROM jobs_w WHERE status='open' AND country=? AND cslug<>'' GROUP BY cslug HAVING n>=5 ORDER BY n DESC LIMIT 20"); $st->execute([$cs]);
    foreach ($st as $r) $cities[] = [$r['city'], (int)$r['n'], '/emploi-etranger/' . $cs . '/' . $r['cslug'] . '/'];
    $base = '/emploi-etranger/' . $cs . '/' . ($city !== '' ? $cslug . '/' : '');
    $where = $city !== '' ? 'à ' . $city : ew_in($cs);
    [$fh, $fs] = ew_faq([["Faut-il parler la langue pour travailler " . ew_in($cs) . " ?", "Les offres affichées ici mentionnent le français : il est souvent un atout, voire la langue de travail (service client, vente, support). La langue du pays ou l'anglais restent généralement demandés en complément."],
        ["Quelles démarches pour travailler " . ew_in($cs) . " ?", $guide . ' Vérifiez les règles en vigueur sur les sites officiels avant de partir.']]);
    $body = '<div class="layout"><article><p class="crumbs" style="margin-top:24px"><a href="/offres-emploi/">Offres d\'emploi</a> › <a href="/emploi-etranger/">Emploi à l\'étranger</a> › ' . ($city !== '' ? '<a href="/emploi-etranger/' . $cs . '/">' . h($name) . '</a> › ' . h($city) : h($name)) . '</p>'
        . '<h1>Emploi ' . h($where) . ' pour francophones' . ($page > 1 ? ' — page ' . $page : '') . '</h1>'
        . '<p class="lead">' . $nf($tot) . ' offres d\'emploi ' . h($where) . ' qui demandent le français, mises à jour chaque jour.</p>'
        . ($cities && $city === '' ? '<h2>Par ville</h2>' . count_chips($cities) : '')
        . ($jobs ? ew_grid($site, $jobs) . ($tot > $page * $per ? '<p><a href="' . $base . 'page/' . ($page + 1) . '/">Offres suivantes →</a></p>' : '') : '<p>Aucune offre pour le moment.</p>')
        . job_ad($site) . '<h2>Questions fréquentes</h2>' . $fh
        . '<p class="disc">Offres : Jobs by Adzuna. La candidature se fait sur le site d\'origine de l\'offre.</p>'
        . '</article><aside><div class="box"><h4>Travailler ' . h(ew_in($cs)) . '</h4><p>' . h($guide) . '</p><p><small>Résumé indicatif : vérifiez votre situation sur les sites officiels avant de partir.</small></p></div></aside></div>';
    $crumb = [['Emploi à l\'étranger', '/emploi-etranger/'], [$name, '/emploi-etranger/' . $cs . '/']]; if ($city !== '') $crumb[] = [$city, $base];
    return layout($site, ['title' => 'Emploi ' . $where . ' pour francophones : ' . $nf($tot) . ' offres' . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'],
        'desc' => mb_strimwidth($nf($tot) . ' offres d\'emploi ' . $where . ' qui demandent le français. Démarches, visa, offres mises à jour chaque jour.', 0, 158, '…'),
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? 'page/' . $page . '/' : ''), 'schema' => [breadcrumbs($site, $crumb), $fs]], $body);
}

function page_ew_job(array $site, string $slug): ?string
{
    $db = ew_db($site); $st = $db->prepare('SELECT * FROM jobs_w WHERE slug=?'); $st->execute([$slug]); $j = $st->fetch();
    if (!$j) return '';
    if ($j['status'] !== 'open') { header('Location: /emploi-etranger/' . $j['country'] . '/', true, 301); return null; }
    $c = EW_COUNTRIES[$j['country']] ?? null; if (!$c) return '';
    $st = $db->prepare("SELECT * FROM jobs_w WHERE status='open' AND country=? AND id<>? ORDER BY (cslug=?) DESC, created_at DESC LIMIT 6"); $st->execute([$j['country'], $j['id'], $j['cslug']]); $sim = $st->fetchAll();
    $facts = array_filter(['Entreprise' => $j['company'], 'Lieu' => trim($j['city'] . ($j['area'] && $j['area'] !== $j['city'] ? ', ' . $j['area'] : '')), 'Pays' => $c[2], 'Contrat' => $j['contract_label'] ?: (JOB_CONTRACTS[$j['contract']] ?? ''), 'Durée du travail' => $j['worktime'], 'Salaire' => $j['salary'], 'Secteur' => $j['sector']]);
    $rel = 'sponsored nofollow noopener'; $desc = trim((string)$j['description']);
    $body = '<div class="layout"><article><p class="crumbs"><a href="/emploi-etranger/">Emploi à l\'étranger</a> › <a href="/emploi-etranger/' . h($j['country']) . '/">' . h($c[2]) . '</a></p>'
        . '<h1>' . h($j['title']) . '</h1>' . job_summary($j, $facts, $rel)
        . ($sim ? '<section class="jnear" style="margin:18px 0;padding:14px 16px;border:1px solid var(--b);border-radius:10px"><h2 style="margin:0 0 8px;font-size:1.1rem">Autres offres ' . h(ew_in($j['country'])) . '</h2><ul style="margin:0;padding-left:18px;line-height:1.7">' . implode('', array_map(fn($r) => '<li><a href="' . h(ew_url($r)) . '">' . h($r['title']) . '</a> <span>— ' . h($r['city']) . '</span></li>', $sim)) . '</ul></section>' : '')
        . job_ad($site)
        . ($desc !== '' ? '<section class="jdesc"><h2>Description du poste</h2><div>' . nl2br(h($desc)) . '</div><p class="jtrunc" style="margin:10px 0 0;padding:10px 12px;border-left:3px solid var(--c);background:rgba(127,127,127,.08);border-radius:6px">Ceci est un extrait de l\'annonce. <a href="' . h($j['url']) . '" rel="' . $rel . '" target="_blank">Lire l\'annonce complète sur le site d\'origine →</a></p></section>' : '')
        . '<p class="disc">Source : Jobs by Adzuna. Publiée le ' . h(date_fr((string)$j['created_at'])) . '.</p>'
        . '</article><aside><div class="box"><h4>Travailler ' . h(ew_in($j['country'])) . '</h4><p>' . h($c[5]) . '</p></div></aside></div>';
    return layout($site, ['title' => mb_substr((string)$j['title'], 0, 60) . ' – ' . $j['city'] . ' (' . $c[2] . ') | ' . $site['name'],
        'desc' => mb_strimwidth(trim(($j['company'] ? $j['company'] . ' recrute : ' : 'Offre : ') . $j['title'] . ' à ' . $j['city'] . ', ' . $c[2] . '. ' . preg_replace('/\s+/', ' ', $desc)), 0, 155, '…'),
        'canonical' => 'https://' . $site['host'] . ew_url($j), 'robots' => 'noindex,follow', 'schema' => [breadcrumbs($site, [['Emploi à l\'étranger', '/emploi-etranger/'], [$c[2], '/emploi-etranger/' . $j['country'] . '/'], [$j['title'], ew_url($j)]])]], $body);
}

function ew_route(array $site, string $path): ?string
{
    if ($path === '/emploi-etranger/') return page_ew_home($site);
    if (preg_match('#^/emploi-etranger/offre/([a-z0-9\-]+)/$#', $path, $m)) return page_ew_job($site, $m[1]);
    if (preg_match('#^/emploi-etranger/([a-z\-]+)/(?:(?!page/)([a-z0-9\-]+)/)?(?:page/(\d+)/)?$#', $path, $m)) return page_ew_country($site, $m[1], (string)($m[2] ?? ''), max(1, (int)($m[3] ?? 1)));
    return '';
}

function ew_sitemap_urls(array $site): array
{
    $u = ['/emploi-etranger/']; foreach (array_keys(EW_COUNTRIES) as $cs) $u[] = '/emploi-etranger/' . $cs . '/';
    try { foreach (ew_db($site)->query("SELECT country, cslug, COUNT(*) n FROM jobs_w WHERE status='open' AND cslug<>'' GROUP BY 1,2 HAVING n>=5") as $r) $u[] = '/emploi-etranger/' . $r['country'] . '/' . $r['cslug'] . '/'; } catch (Throwable $e) {}
    return $u;
}
