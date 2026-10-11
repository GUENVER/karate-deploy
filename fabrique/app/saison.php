<?php
// Emplois saisonniers : calendrier annuel + classement automatique des offres (cli/saison_build.php toutes les 3 h).
// Adresses permanentes /emploi-saisonnier/<saison>/ ; la saison en cours est mise en avant d'elle-même (menu, accueil).
declare(strict_types=1);

// clé => [libellé, titre court, début MM-JJ, fin MM-JJ, règle SQL sur jobs, introduction]
const SS_SEASONS = [
    'noel' => ['Jobs de Noël et des fêtes', 'Noël', '10-01', '12-31',
        "((title LIKE '%noël%' OR title LIKE '%noel%' OR title LIKE '%NOËL%' OR title LIKE '%NOEL%' OR title LIKE '%fêtes de fin%' OR description LIKE '%période de noël%' OR description LIKE '%période des fêtes%' OR description LIKE '%fêtes de fin d''année%' OR description LIKE '%renfort de fin d''année%' OR description LIKE '%renfort de noël%') AND contract IN ('cdd','interim','autre','')) OR (contract_label LIKE 'Saisonnier%' AND substr(created_at,6,2) IN ('09','10','11','12') AND (title LIKE '%vend%' OR title LIKE '%VEND%' OR title LIKE '%caiss%' OR title LIKE '%CAISS%' OR title LIKE '%hôte%' OR title LIKE '%préparat%' OR title LIKE '%PREPARAT%' OR title LIKE '%emball%' OR title LIKE '%livr%' OR title LIKE '%chocolat%' OR title LIKE '%traiteur%'))",
        'Vendeurs, hôtes de caisse, préparateurs de commandes, emballeurs, livreurs, serveurs : les renforts de fin d\'année recrutent d\'octobre à décembre, en CDD ou en intérim.'],
    'logistique' => ['Renforts logistique : Black Friday et pics d\'activité', 'Black Friday et logistique', '10-01', '11-30',
        "(contract IN ('cdd','interim') AND (title LIKE '%préparateur%' OR title LIKE '%PREPARATEUR%' OR title LIKE '%manutention%' OR title LIKE '%cariste%' OR title LIKE '%magasinier%' OR title LIKE '%logisti%' OR title LIKE '%colis%') AND (description LIKE '%pic d''activité%' OR description LIKE '%renfort%' OR description LIKE '%saisonn%' OR description LIKE '%fin d''année%' OR description LIKE '%black friday%' OR description LIKE '%surcroît%'))",
        'Entrepôts et plateformes logistiques renforcent leurs équipes pour le Black Friday et les commandes de fin d\'année : préparation de commandes, cariste, manutention.'],
    'ski' => ['Saison d\'hiver et stations de ski', 'Saison d\'hiver', '10-01', '03-15',
        "(description LIKE '%saison d''hiver%' OR title LIKE '%saison hiver%' OR title LIKE '%saison d''hiver%' OR description LIKE '%station de ski%' OR description LIKE '%domaine skiable%' OR description LIKE '%remontées mécaniques%' OR title LIKE '%moniteur de ski%' OR ((title LIKE '%saisonn%' OR description LIKE '%contrat saisonnier%') AND substr(postal,1,2) IN ('73','74','05','38','65','66','09','04','31','64','2A','2B','88','25','39','01')) OR (contract_label LIKE 'Saisonnier%' AND substr(created_at,6,2) IN ('09','10','11','12','01','02') AND substr(postal,1,2) IN ('73','74','05','38','65','66','09','04','31','64','2A','2B','88','25','39','01')))",
        'Remontées mécaniques, hôtellerie-restauration, location de skis, animation : les stations recrutent dès l\'automne pour la saison d\'hiver, souvent logé.'],
    'soldes' => ['Jobs des soldes d\'hiver', 'Soldes d\'hiver', '12-01', '01-31',
        "((title LIKE '%soldes%' OR description LIKE '%période de soldes%' OR description LIKE '%pendant les soldes%') AND contract IN ('cdd','interim','autre',''))",
        'Commerces et grands magasins renforcent leurs équipes de vente et de réserve pour les soldes d\'hiver.'],
    'ete' => ['Jobs d\'été et saisonniers', 'Saison d\'été', '03-01', '08-31',
        "(title LIKE '%saisonnier%' OR title LIKE '%saisonnière%' OR title LIKE '%SAISONNIER%' OR description LIKE '%saison estivale%' OR description LIKE '%saison d''été%' OR description LIKE '%juillet et août%' OR description LIKE '%juillet-août%' OR title LIKE '%job d''été%' OR title LIKE '%été 20%')",
        'Campings, plages, restauration, parcs de loisirs, animation, agriculture : les emplois saisonniers d\'été se recrutent dès le printemps.'],
    'vendanges' => ['Vendanges', 'Vendanges', '08-01', '10-31',
        "(title LIKE '%vendang%' OR title LIKE '%VENDANG%' OR description LIKE '%vendanges%')",
        'Coupeurs, porteurs, cavistes : les vendanges recrutent de fin août à octobre selon les régions viticoles.'],
];

function ss_tables(PDO $db): void
{
    static $ok = false; if ($ok) return;
    $db->exec('CREATE TABLE IF NOT EXISTS job_season_map(id TEXT, season TEXT, PRIMARY KEY(id, season))');
    $db->exec('CREATE INDEX IF NOT EXISTS ix_jsm ON job_season_map(season)');
    $ok = true;
}

function ss_build(array $site): array
{
    $db = jobs_db($site['host']); ss_tables($db);
    $db->beginTransaction(); $db->exec('DELETE FROM job_season_map');
    foreach (SS_SEASONS as $k => $s) $db->exec("INSERT OR IGNORE INTO job_season_map(id,season) SELECT id, '$k' FROM jobs WHERE status='open' AND " . $s[4]);
    $db->commit();
    return ss_counts($db);
}

function ss_counts(PDO $db): array
{
    ss_tables($db); $o = [];
    foreach ($db->query("SELECT m.season, COUNT(*) n FROM job_season_map m JOIN jobs j ON j.id=m.id WHERE j.status='open' GROUP BY m.season") as $r) $o[$r['season']] = (int)$r['n'];
    return $o;
}

// Saison en cours ? (gère les saisons à cheval sur deux années)
function ss_is_active(string $k, ?int $ts = null): bool
{
    $md = date('m-d', $ts ?? time()); [, , $a, $b] = SS_SEASONS[$k];
    return $a <= $b ? ($md >= $a && $md <= $b) : ($md >= $a || $md <= $b);
}

function ss_active(): array { return array_values(array_filter(array_keys(SS_SEASONS), 'ss_is_active')); }

function ss_date_fr(string $md, bool $next = true): string
{
    static $m = ['01' => 'janvier', '02' => 'février', '03' => 'mars', '04' => 'avril', '05' => 'mai', '06' => 'juin', '07' => 'juillet', '08' => 'août', '09' => 'septembre', '10' => 'octobre', '11' => 'novembre', '12' => 'décembre'];
    [$mm, $dd] = explode('-', $md); return ((int)$dd === 1 ? '1er' : (int)$dd) . ' ' . $m[$mm];
}

function ss_faq(string $k, int $n): array
{
    [$label, $short, $a, $b] = SS_SEASONS[$k];
    return [
        ['Quand recrute-t-on pour : ' . mb_strtolower($short) . ' ?', 'Les recrutements ont lieu principalement du ' . ss_date_fr($a) . ' au ' . ss_date_fr($b) . '. Il est conseillé de postuler dès le début de la période : les meilleurs postes partent vite.'],
        ['Quel contrat pour un emploi saisonnier ?', 'Le plus souvent un CDD saisonnier ou une mission d\'intérim. Le contrat saisonnier n\'ouvre en principe pas droit à l\'indemnité de fin de contrat (prime de précarité), sauf disposition plus favorable de la convention collective ; une mission d\'intérim y ouvre droit.'],
        ['Combien d\'offres sont disponibles ?', 'Au ' . date_fr(now()) . ', recruteur.eu recense ' . number_format($n, 0, ',', ' ') . ' offres correspondant à cette saison, mises à jour toutes les 3 heures.'],
    ];
}

function page_ss_home(array $site): string
{
    $db = jobs_db($site['host']); $c = ss_counts($db); $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $act = ss_active(); $cards = '';
    $order = array_merge($act, array_diff(array_keys(SS_SEASONS), $act));
    foreach ($order as $k) { [$label, $short, $a, $b, , $intro] = SS_SEASONS[$k]; $on = in_array($k, $act, true);
        $cards .= '<div class="card"><div class="in"><span class="kick">' . ($on ? 'En cours' : 'À partir du ' . h(ss_date_fr($a))) . '</span><h3><a href="/emploi-saisonnier/' . $k . '/">' . h($label) . '</a></h3><p>' . h($intro) . '</p><p><b>' . $nf($c[$k] ?? 0) . '</b> offres</p></div></div>'; }
    $latest = $db->query("SELECT * FROM jobs WHERE status='open' AND contract_label LIKE 'Saisonnier%' ORDER BY created_at DESC LIMIT 24")->fetchAll();
    $nSai = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND contract_label LIKE 'Saisonnier%'")->fetchColumn();
    if (!$latest && $act) { $st = $db->prepare("SELECT j.* FROM jobs j JOIN job_season_map m ON m.id=j.id WHERE j.status='open' AND m.season IN (" . implode(',', array_fill(0, count($act), '?')) . ") GROUP BY j.id ORDER BY j.created_at DESC LIMIT 12"); $st->execute($act); $latest = $st->fetchAll(); }
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › Emplois saisonniers</p>'
        . '<h1>Emplois saisonniers</h1><p class="lead">' . number_format($nSai, 0, ',', ' ') . ' offres en contrat saisonnier, plus les renforts de Noël, de la saison d\'hiver, des soldes, de l\'été et des vendanges, classés automatiquement et mis à jour toutes les 3 heures.' . ($act ? ' En ce moment : ' . h(implode(', ', array_map(fn($k) => SS_SEASONS[$k][1], $act))) . '.' : '') . '</p>'
        . '<div class="grid">' . $cards . '</div>' . job_ad($site)
        . ($latest ? '<h2>Dernières offres saisonnières</h2>' . job_grid($site, $latest) : '') . jobs_disclaimer();
    return layout($site, ['title' => 'Emplois saisonniers : Noël, hiver, été, vendanges | ' . $site['name'],
        'desc' => 'Offres d\'emploi saisonnier mises à jour toutes les 3 heures : jobs de Noël, saison d\'hiver, soldes, jobs d\'été, vendanges, partout en France.',
        'canonical' => 'https://' . $site['host'] . '/emploi-saisonnier/', 'schema' => [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], ['Emplois saisonniers', '/emploi-saisonnier/']])]], $body);
}

function page_ss_season(array $site, string $k, ?array $reg, int $page): string
{
    if (!isset(SS_SEASONS[$k])) return '';
    [$label, $short, $a, $b, , $intro] = SS_SEASONS[$k];
    $db = jobs_db($site['host']); ss_tables($db); $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $w = "j.status='open' AND m.season=?" . ($reg ? ' AND j.region=?' : ''); $args = $reg ? [$k, $reg['code']] : [$k];
    $st = $db->prepare("SELECT COUNT(*) FROM jobs j JOIN job_season_map m ON m.id=j.id WHERE $w"); $st->execute($args); $tot = (int)$st->fetchColumn();
    $per = 30; if ($page > 1 && ($page - 1) * $per >= $tot) return '';
    $st = $db->prepare("SELECT j.* FROM jobs j JOIN job_season_map m ON m.id=j.id WHERE $w ORDER BY j.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args); $jobs = $st->fetchAll();
    $on = ss_is_active($k);
    $base = '/emploi-saisonnier/' . $k . '/' . ($reg ? $reg['slug'] . '/' : '');
    $byR = []; $st = $db->prepare("SELECT j.region, COUNT(*) n FROM jobs j JOIN job_season_map m ON m.id=j.id WHERE j.status='open' AND m.season=? GROUP BY j.region ORDER BY n DESC"); $st->execute([$k]);
    foreach ($st as $r) if (isset(JOB_REGIONS[$r['region']]) && (int)$r['n'] >= 3) $byR[] = [JOB_REGIONS[$r['region']][1], (int)$r['n'], '/emploi-saisonnier/' . $k . '/' . JOB_REGIONS[$r['region']][0] . '/'];
    $where = $reg ? ' en ' . $reg['name'] : '';
    $status = $on ? '<p><b>Saison en cours</b> : recrutements du ' . h(ss_date_fr($a)) . ' au ' . h(ss_date_fr($b)) . '.</p>' : '<p><b>Hors saison</b> : les recrutements reprennent à partir du ' . h(ss_date_fr($a)) . '. Les offres déjà publiées sont affichées ci-dessous.</p>';
    $faq = ss_faq($k, $tot);
    $others = implode(' · ', array_map(fn($x) => '<a href="/emploi-saisonnier/' . $x . '/">' . h(SS_SEASONS[$x][0]) . '</a>', array_diff(array_keys(SS_SEASONS), [$k])));
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/offres-emploi/">Offres d\'emploi</a> › <a href="/emploi-saisonnier/">Emplois saisonniers</a> › ' . ($reg ? '<a href="/emploi-saisonnier/' . $k . '/">' . h($short) . '</a> › ' . h($reg['name']) : h($short)) . '</p>'
        . '<h1>' . h($label) . h($where) . ($page > 1 ? ' — page ' . $page : '') . '</h1>'
        . '<p class="lead">' . $nf($tot) . ' offres' . h($where) . '. ' . h($intro) . '</p>' . $status
        . ($byR && !$reg ? '<h2>Par région</h2>' . count_chips($byR) : '')
        . ($jobs ? '<h2 id="offres">Offres' . h($where) . '</h2>' . job_grid($site, $jobs) . ($tot > $page * $per ? '<p><a href="' . $base . 'page/' . ($page + 1) . '/">Offres suivantes →</a></p>' : '') : '<p>Aucune offre pour le moment : revenez au début de la saison, la liste se remplit automatiquement.</p>')
        . job_ad($site)
        . '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq))
        . '<h2>Autres saisons</h2><p>' . $others . '</p>' . jobs_disclaimer();
    $crumb = [['Emplois saisonniers', '/emploi-saisonnier/'], [$short, '/emploi-saisonnier/' . $k . '/']]; if ($reg) $crumb[] = [$reg['name'], $base];
    $schema = [breadcrumbs($site, $crumb), ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)]];
    return layout($site, ['title' => $label . $where . ' : ' . $nf($tot) . ' offres' . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'],
        'desc' => mb_strimwidth($nf($tot) . ' offres ' . mb_strtolower($label) . $where . '. ' . $intro, 0, 158, '…'),
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? 'page/' . $page . '/' : ''), 'schema' => $schema, 'robots' => (!$reg && $tot === 0) || ($reg && $tot < 3) ? 'noindex,follow' : null], $body);
}

function ss_route(array $site, string $path): ?string
{
    if ($path === '/emploi-saisonnier/') return page_ss_home($site);
    if (preg_match('#^/emploi-saisonnier/([a-z]+)/(?:(?!page/)([a-z\-]+)/)?(?:page/(\d+)/)?$#', $path, $m)) {
        $reg = null;
        if (!empty($m[2]) && !($reg = region_by_slug($m[2]))) return '';
        return page_ss_season($site, $m[1], $reg, max(1, (int)($m[3] ?? 1)));
    }
    return '';
}

function ss_sitemap_urls(array $site): array
{
    $u = ['/emploi-saisonnier/']; foreach (array_keys(SS_SEASONS) as $k) $u[] = '/emploi-saisonnier/' . $k . '/';
    try { $db = jobs_db($site['host']); ss_tables($db);
        foreach ($db->query("SELECT m.season, j.region, COUNT(*) n FROM job_season_map m JOIN jobs j ON j.id=m.id WHERE j.status='open' GROUP BY 1,2 HAVING n>=3") as $r) if (isset(JOB_REGIONS[$r['region']])) $u[] = '/emploi-saisonnier/' . $r['season'] . '/' . JOB_REGIONS[$r['region']][0] . '/';
    } catch (Throwable $e) {}
    return $u;
}

// Lien de menu / bouton d'accueil : la saison en cours la plus porteuse, sinon la page générale.
function ss_nav(): array
{
    $act = ss_active();
    foreach (['noel', 'ete', 'ski', 'soldes', 'vendanges', 'logistique'] as $k) if (in_array($k, $act, true)) return ['/emploi-saisonnier/' . $k . '/', SS_SEASONS[$k][1] === 'Noël' ? 'Jobs de Noël' : SS_SEASONS[$k][1]];
    return ['/emploi-saisonnier/', 'Saisonnier'];
}

// Offres France Travail en contrat saisonnier (typeContrat=SAI), région par région : la synchro générale n'en voit qu'une partie.
// Appelée par cli/saison_build.php au plus toutes les 6 h ; les offres rejoignent la table jobs comme les autres offres France Travail.
function ss_fetch_sai(array $site, callable $log): int
{
    $last = (int)setting('ss_sai_last', '0');
    if ($last > time() - 6 * 3600) return 0;
    $tok = function_exists('ft_token') ? ft_token() : null;
    if (!$tok) return 0;
    $db = jobs_db($site['host']); $now = now(); $n = 0;
    $up = $db->prepare("INSERT INTO jobs(id,src,slug,title,company,description,city,postal,region,contract,contract_label,worktime,salary,experience,sector,url,created_at,seen_at,status)
        VALUES(:id,:src,:slug,:title,:company,:description,:city,:postal,:region,:contract,:contract_label,:worktime,:salary,:experience,:sector,:url,:created_at,:seen,'open')
        ON CONFLICT(id) DO UPDATE SET seen_at=excluded.seen_at, status='open',
            updated_at=CASE WHEN jobs.status<>'open' OR jobs.title IS NOT excluded.title OR jobs.description IS NOT excluded.description OR jobs.salary IS NOT excluded.salary THEN excluded.seen_at ELSE jobs.updated_at END,
            title=excluded.title, description=excluded.description, salary=excluded.salary, url=excluded.url");
    $romeIns = $db->prepare('INSERT OR REPLACE INTO job_rome(id,rome,lat,lon) VALUES(?,?,?,?)');
    foreach (array_keys(JOB_REGIONS) as $reg) {
        $reg = (string)$reg; $got = 0;
        for ($start = 0; $start < 450; $start += 150) {
            $j = http_get_json('https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search?sort=1&typeContrat=SAI&region=' . $reg . '&range=' . $start . '-' . ($start + 149),
                ['Authorization: Bearer ' . $tok, 'Accept: application/json']);
            $res = $j['resultats'] ?? [];
            $db->beginTransaction();
            foreach ($res as $raw) {
                $o = ft_map($raw, $reg);
                if (array_key_exists('_rome', $o)) { if ($o['_rome'] !== '' || $o['_lat'] !== null) $romeIns->execute([$o['id'], $o['_rome'], $o['_lat'], $o['_lon']]); unset($o['_rome'], $o['_lat'], $o['_lon']); }
                if ($o['title'] === '' || $o['url'] === '') continue;
                $o['slug'] = slugify($o['title'] . ' ' . $o['city'], 70) . '-' . substr(md5($o['id']), 0, 6);
                $o['seen'] = $now; $up->execute($o); $got++;
            }
            $db->commit();
            usleep(300000);
            if (count($res) < 150) break;
        }
        $n += $got; $log(JOB_REGIONS[$reg][1] . ' : ' . $got . ' offres saisonnières');
    }
    setting_set('ss_sai_last', (string)time());
    return $n;
}
