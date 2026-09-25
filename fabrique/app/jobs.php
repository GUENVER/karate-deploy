<?php
// Agrégateur d'offres d'emploi régionales : sources officielles (France Travail, Adzuna), pages régions,
// fiches offres balisées JobPosting (Google Jobs), synchronisation par cron.
declare(strict_types=1);

const JOB_REGIONS = [
    '84' => ['auvergne-rhone-alpes', 'Auvergne-Rhône-Alpes'], '27' => ['bourgogne-franche-comte', 'Bourgogne-Franche-Comté'],
    '53' => ['bretagne', 'Bretagne'], '24' => ['centre-val-de-loire', 'Centre-Val de Loire'], '94' => ['corse', 'Corse'],
    '44' => ['grand-est', 'Grand Est'], '32' => ['hauts-de-france', 'Hauts-de-France'], '11' => ['ile-de-france', 'Île-de-France'],
    '28' => ['normandie', 'Normandie'], '75' => ['nouvelle-aquitaine', 'Nouvelle-Aquitaine'], '76' => ['occitanie', 'Occitanie'],
    '52' => ['pays-de-la-loire', 'Pays de la Loire'], '93' => ['provence-alpes-cote-d-azur', "Provence-Alpes-Côte d'Azur"],
    '01' => ['guadeloupe', 'Guadeloupe'], '02' => ['martinique', 'Martinique'], '03' => ['guyane', 'Guyane'],
    '04' => ['la-reunion', 'La Réunion'], '06' => ['mayotte', 'Mayotte'],
];
const JOB_CONTRACTS = ['cdi' => 'CDI', 'cdd' => 'CDD', 'interim' => 'Intérim', 'alternance' => 'Alternance', 'stage' => 'Stage', 'freelance' => 'Indépendant', 'autre' => 'Autre'];

function jobs_db(string $host): PDO
{
    $db = site_db($host);
    static $init = [];
    if (empty($init[$host])) {
        $db->exec("CREATE TABLE IF NOT EXISTS jobs(
            id TEXT PRIMARY KEY, src TEXT, slug TEXT, title TEXT, company TEXT, description TEXT, city TEXT, postal TEXT,
            region TEXT, contract TEXT, contract_label TEXT, worktime TEXT, salary TEXT, experience TEXT, sector TEXT, url TEXT,
            created_at TEXT, seen_at TEXT, status TEXT DEFAULT 'open');
        CREATE INDEX IF NOT EXISTS ix_jobs_reg ON jobs(status, region, created_at);
        CREATE INDEX IF NOT EXISTS ix_jobs_slug ON jobs(slug);");
        $init[$host] = 1;
    }
    return $db;
}

function region_by_slug(string $slug): ?array
{
    foreach (JOB_REGIONS as $code => [$s, $n]) if ($s === $slug) return ['code' => (string)$code, 'slug' => $s, 'name' => $n];
    return null;
}

function contract_key(string $raw): string
{
    // codes France Travail (CDI, CDD, MIS, SAI, LIB, FRA, CCE…) + nature du contrat, ou types Adzuna (permanent, contract)
    $r = mb_strtolower($raw);
    $code = strtoupper(strtok(trim($raw), ' ') ?: '');
    if (preg_match('/apprenti|professionnalisation|altern/', $r)) return 'alternance';
    if (str_contains($r, 'stage') || str_contains($r, 'internship')) return 'stage';
    return match (true) {
        $code === 'CDI' || $r === 'permanent' => 'cdi',
        in_array($code, ['CDD', 'SAI'], true) || $r === 'contract' => 'cdd',
        $code === 'MIS' => 'interim',
        in_array($code, ['LIB', 'FRA', 'CCE', 'REP'], true) => 'freelance',
        default => 'autre',
    };
}

// ---------- Sources ----------

function http_get_json(string $url, array $headers = [], ?string $post = null): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'fabrique-jobs/1.0']);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { fwrite(STDERR, "HTTP $code $url " . substr((string)$r, 0, 200) . "\n"); return null; }
    return json_decode((string)$r, true);
}

function ft_token(): ?string
{
    $id = setting('ft_client_id', ''); $sec = setting('ft_client_secret', '');
    if ($id === '' || $sec === '') return null;
    $j = http_get_json('https://entreprise.francetravail.fr/connexion/oauth2/access_token?realm=%2Fpartenaire', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $sec, 'scope' => 'api_offresdemploiv2 o2dsoffre']));
    return $j['access_token'] ?? null;
}

function ft_fetch(string $token, string $region, int $max = 300): array
{
    $out = [];
    for ($start = 0; $start < $max; $start += 150) {
        $j = http_get_json('https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search?sort=1&region=' . $region . '&range=' . $start . '-' . ($start + 149),
            ['Authorization: Bearer ' . $token, 'Accept: application/json']);
        foreach ($j['resultats'] ?? [] as $o) {
            $out[] = [
                'id' => 'ft-' . $o['id'], 'src' => 'France Travail', 'title' => $o['intitule'] ?? '', 'company' => $o['entreprise']['nom'] ?? '',
                'description' => $o['description'] ?? '', 'city' => preg_replace('/^\d+\s*-\s*/', '', (string)($o['lieuTravail']['libelle'] ?? '')),
                'postal' => $o['lieuTravail']['codePostal'] ?? '', 'region' => $region, 'contract' => contract_key(($o['typeContrat'] ?? '') . ' ' . ($o['natureContrat'] ?? '')),
                'contract_label' => $o['typeContratLibelle'] ?? '', 'worktime' => $o['dureeTravailLibelleConverti'] ?? ($o['dureeTravailLibelle'] ?? ''),
                'salary' => $o['salaire']['libelle'] ?? '', 'experience' => $o['experienceLibelle'] ?? '', 'sector' => $o['secteurActiviteLibelle'] ?? ($o['romeLibelle'] ?? ''),
                'url' => $o['origineOffre']['urlOrigine'] ?? ('https://candidat.francetravail.fr/offres/recherche/detail/' . $o['id']),
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($o['dateCreation'] ?? 'now')),
            ];
        }
        if (count($j['resultats'] ?? []) < 150) break;
        usleep(400000);
    }
    return $out;
}

function adzuna_fetch(string $region, int $pages = 1): array // plan gratuit : ~250 appels/jour → 18 régions × 1 page × 8 synchros
{
    $id = setting('adzuna_app_id', ''); $key = setting('adzuna_app_key', '');
    if ($id === '' || $key === '') return [];
    $name = JOB_REGIONS[$region][1];
    $out = [];
    for ($p = 1; $p <= $pages; $p++) {
        $j = http_get_json('https://api.adzuna.com/v1/api/jobs/fr/search/' . $p . '?' . http_build_query(['app_id' => $id, 'app_key' => $key, 'results_per_page' => 50, 'where' => $name, 'sort_by' => 'date', 'content-type' => 'application/json']));
        foreach ($j['results'] ?? [] as $o) {
            $area = $o['location']['area'] ?? [];
            $sal = !empty($o['salary_min']) ? number_format((float)$o['salary_min'], 0, ',', ' ') . (!empty($o['salary_max']) && $o['salary_max'] != $o['salary_min'] ? ' à ' . number_format((float)$o['salary_max'], 0, ',', ' ') : '') . ' € / an' : '';
            $out[] = [
                'id' => 'az-' . $o['id'], 'src' => 'Adzuna', 'title' => strip_tags($o['title'] ?? ''), 'company' => $o['company']['display_name'] ?? '',
                'description' => strip_tags($o['description'] ?? ''), 'city' => end($area) ?: ($o['location']['display_name'] ?? ''), 'postal' => '',
                'region' => $region, 'contract' => contract_key((string)($o['contract_type'] ?? '')), 'contract_label' => ['permanent' => 'CDI', 'contract' => 'CDD'][$o['contract_type'] ?? ''] ?? '',
                'worktime' => ['full_time' => 'Temps plein', 'part_time' => 'Temps partiel'][$o['contract_time'] ?? ''] ?? '', 'salary' => $sal, 'experience' => '',
                'sector' => $o['category']['label'] ?? '', 'url' => $o['redirect_url'] ?? '', 'created_at' => gmdate('Y-m-d H:i:s', strtotime($o['created'] ?? 'now')),
            ];
        }
        usleep(400000);
    }
    return $out;
}

// Synchronise les offres de toutes les régions pour un site ; ferme les offres non revues depuis 3 jours.
function jobs_sync(array $site, callable $log): array
{
    $db = jobs_db($site['host']);
    $tok = ft_token();
    if (!$tok && setting('adzuna_app_id', '') === '') return ['error' => 'aucune source configurée (clés France Travail / Adzuna dans Réglages)'];
    $now = now(); $n = 0;
    $up = $db->prepare("INSERT INTO jobs(id,src,slug,title,company,description,city,postal,region,contract,contract_label,worktime,salary,experience,sector,url,created_at,seen_at,status)
        VALUES(:id,:src,:slug,:title,:company,:description,:city,:postal,:region,:contract,:contract_label,:worktime,:salary,:experience,:sector,:url,:created_at,:seen,'open')
        ON CONFLICT(id) DO UPDATE SET seen_at=excluded.seen_at, status='open', title=excluded.title, description=excluded.description, salary=excluded.salary, url=excluded.url");
    foreach (array_keys(JOB_REGIONS) as $reg) {
        $reg = (string)$reg;
        $offers = array_merge($tok ? ft_fetch($tok, $reg) : [], adzuna_fetch($reg));
        $db->beginTransaction();
        foreach ($offers as $o) {
            if ($o['title'] === '' || $o['url'] === '') continue;
            $o['slug'] = slugify($o['title'] . ' ' . $o['city'], 70) . '-' . substr(md5($o['id']), 0, 6);
            $o['seen'] = $now;
            $up->execute($o);
            $n++;
        }
        $db->commit();
        $log(JOB_REGIONS[$reg][1] . ' : ' . count($offers) . ' offres');
    }
    $db->exec("UPDATE jobs SET status='closed' WHERE status='open' AND seen_at < datetime('now','-3 days')");
    $db->exec("DELETE FROM jobs WHERE status='closed' AND seen_at < datetime('now','-40 days')");
    cache_clear($site['host']);
    return ['upserted' => $n, 'open' => (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn()];
}

// ---------- Pages ----------

function job_url(array $j): string { return '/offre/' . $j['slug'] . '/'; }

function job_card(array $j): string
{
    $meta = array_filter([$j['company'], $j['city'], $j['contract_label'] ?: (JOB_CONTRACTS[$j['contract']] ?? ''), $j['salary']]);
    return '<div class="card"><div class="in"><span class="kick">' . h(JOB_CONTRACTS[$j['contract']] ?? 'Offre') . '</span><h3><a href="' . h(job_url($j)) . '">' . h($j['title']) . '</a></h3>'
        . '<p>' . h(implode(' · ', $meta)) . '</p><p><small>Publiée le ' . h(date_fr($j['created_at'])) . '</small></p></div></div>';
}

function jobs_disclaimer(): string
{
    return '<p class="disc">Offres issues de sources publiques et partenaires (France Travail, Adzuna). La candidature se fait sur le site d\'origine de l\'offre.</p>';
}

function page_jobs_home(array $site): string
{
    $db = jobs_db($site['host']);
    $counts = [];
    foreach ($db->query("SELECT region, COUNT(*) n FROM jobs WHERE status='open' GROUP BY region") as $r) $counts[$r['region']] = (int)$r['n'];
    $total = array_sum($counts);
    $regs = '';
    foreach (JOB_REGIONS as $code => [$slug, $name]) {
        if (empty($counts[$code])) continue;
        $regs .= '<div class="card"><div class="in"><h3><a href="/offres-emploi/' . $slug . '/">Emploi ' . h($name) . '</a></h3><p>' . number_format($counts[$code], 0, ',', ' ') . ' offres en cours</p></div></div>';
    }
    $latest = $db->query("SELECT * FROM jobs WHERE status='open' ORDER BY created_at DESC LIMIT 12")->fetchAll();
    $body = '<h1 style="margin-top:28px">Offres d\'emploi par région</h1><p>' . number_format($total, 0, ',', ' ') . ' offres d\'emploi actualisées plusieurs fois par jour : CDI, CDD, intérim, alternance et stages dans toutes les régions de France.</p>'
        . '<div class="grid">' . $regs . '</div><h2>Dernières offres publiées</h2><div class="grid">' . implode('', array_map('job_card', $latest)) . '</div>' . jobs_disclaimer();
    return layout($site, ['title' => 'Offres d\'emploi par région | ' . $site['name'], 'desc' => "$total offres d'emploi en France par région : CDI, CDD, intérim, alternance. Mises à jour quotidiennes.", 'canonical' => 'https://' . $site['host'] . '/offres-emploi/'], $body);
}

function page_jobs_region(array $site, array $reg, int $page, string $contract): string
{
    $db = jobs_db($site['host']);
    $per = 30;
    $where = "status='open' AND region=?" . ($contract ? ' AND contract=?' : '');
    $args = $contract ? [$reg['code'], $contract] : [$reg['code']];
    $st = $db->prepare("SELECT COUNT(*) FROM jobs WHERE $where"); $st->execute($args);
    $total = (int)$st->fetchColumn();
    if (!$total) return '';
    $st = $db->prepare("SELECT * FROM jobs WHERE $where ORDER BY created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args);
    $jobs = $st->fetchAll();
    if (!$jobs) return '';
    $base = '/offres-emploi/' . $reg['slug'] . '/';
    $chips = '<p>' . implode(' ', array_map(fn($k, $l) => '<a class="kick" style="margin-right:10px" href="' . $base . '?contrat=' . $k . '" rel="nofollow">' . $l . '</a>', array_keys(JOB_CONTRACTS), JOB_CONTRACTS)) . '</p>';
    $st = $db->prepare("SELECT city, COUNT(*) n FROM jobs WHERE status='open' AND region=? AND city<>'' GROUP BY city ORDER BY n DESC LIMIT 12"); $st->execute([$reg['code']]);
    $cities = implode(', ', array_map(fn($c) => h($c['city']) . ' (' . $c['n'] . ')', $st->fetchAll()));
    $guides = site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT 6")->fetchAll();
    $title = 'Offres d\'emploi ' . $reg['name'] . ($contract ? ' en ' . JOB_CONTRACTS[$contract] : '');
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › ' . h($reg['name']) . '</p>'
        . '<h1>' . h($title) . '</h1><p>' . number_format($total, 0, ',', ' ') . ' offres d\'emploi en ' . h($reg['name']) . ', mises à jour plusieurs fois par jour. Villes qui recrutent le plus : ' . $cities . '.</p>'
        . $chips . '<div class="grid">' . implode('', array_map('job_card', $jobs)) . '</div>'
        . pager($base, $page, (int)ceil($total / $per))
        . ($guides ? '<h2>Nos conseils pour décrocher le poste</h2><ul>' . implode('', array_map(fn($g) => '<li><a href="' . h($g['path']) . '">' . h($g['title']) . '</a></li>', $guides)) . '</ul>' : '')
        . jobs_disclaimer();
    return layout($site, [
        'title' => $title . ($page > 1 ? " — page $page" : '') . ' | ' . $site['name'], 'desc' => "$total offres d'emploi en {$reg['name']} : CDI, CDD, intérim, alternance. Postulez directement auprès des recruteurs.",
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''), 'robots' => $contract ? 'noindex,follow' : null,
        'schema' => [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], [$reg['name'], $base]])],
    ], $body);
}

function page_job(array $site, string $slug): ?string
{
    $db = jobs_db($site['host']);
    $st = $db->prepare('SELECT * FROM jobs WHERE slug=?'); $st->execute([$slug]);
    $j = $st->fetch();
    if (!$j) return '';
    $reg = JOB_REGIONS[$j['region']] ?? ['', ''];
    $st = $db->prepare("SELECT * FROM jobs WHERE status='open' AND region=? AND id<>? ORDER BY (contract=?) DESC, created_at DESC LIMIT 6"); $st->execute([$j['region'], $j['id'], $j['contract']]);
    $similar = $st->fetchAll();
    $closed = $j['status'] !== 'open';
    if ($closed) http_response_code(410);
    $facts = array_filter(['Entreprise' => $j['company'], 'Lieu' => trim($j['city'] . ' ' . $j['postal']), 'Contrat' => $j['contract_label'] ?: (JOB_CONTRACTS[$j['contract']] ?? ''),
        'Durée du travail' => $j['worktime'], 'Salaire' => $j['salary'], 'Expérience' => $j['experience'], 'Secteur' => $j['sector']]);
    $rel = $j['src'] === 'Adzuna' ? 'sponsored nofollow noopener' : 'nofollow noopener';
    $body = '<div class="layout"><article><p class="crumbs"><a href="/offres-emploi/">Offres d\'emploi</a> › <a href="/offres-emploi/' . h($reg[0]) . '/">' . h($reg[1]) . '</a></p>'
        . '<h1>' . h($j['title']) . '</h1>'
        . ($closed ? '<p class="lead">Cette offre n\'est plus disponible. Voici des offres similaires.</p>' : '')
        . '<table>' . implode('', array_map(fn($k, $v) => '<tr><th>' . h($k) . '</th><td>' . h($v) . '</td></tr>', array_keys($facts), $facts)) . '</table>'
        . ($closed ? '' : '<p><a class="btn" style="background:var(--c);color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700" href="' . h($j['url']) . '" rel="' . $rel . '" target="_blank">Postuler sur le site de l\'offre</a></p>')
        . '<h2>Description du poste</h2><div>' . nl2br(h($j['description'])) . '</div>'
        . '<p class="disc">Source : ' . h($j['src']) . ($j['src'] === 'Adzuna' ? ' — Jobs by Adzuna' : '') . '. Publiée le ' . h(date_fr($j['created_at'])) . '.</p>'
        . ($similar ? '<h2>Offres similaires en ' . h($reg[1]) . '</h2><div class="grid">' . implode('', array_map('job_card', $similar)) . '</div>' : '')
        . '</article><aside><div class="box"><h4>Préparer sa candidature</h4><ul>'
        . implode('', array_map(fn($g) => '<li><a href="' . h($g['path']) . '">' . h($g['title']) . '</a></li>', site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY published_at DESC LIMIT 8")->fetchAll()))
        . '</ul></div></aside></div>';
    $types = ['cdi' => 'FULL_TIME', 'cdd' => 'TEMPORARY', 'interim' => 'TEMPORARY', 'alternance' => 'INTERN', 'stage' => 'INTERN', 'freelance' => 'CONTRACTOR', 'autre' => 'OTHER'];
    $schema = [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], [$reg[1], '/offres-emploi/' . $reg[0] . '/'], [$j['title'], job_url($j)]])];
    if (!$closed) $schema[] = ['@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => $j['title'], 'description' => nl2br(h($j['description'])),
        'datePosted' => substr($j['created_at'], 0, 10), 'validThrough' => gmdate('Y-m-d\TH:i:s\Z', strtotime($j['created_at'] . ' UTC') + 45 * 86400),
        'employmentType' => $types[$j['contract']] ?? 'OTHER', 'hiringOrganization' => ['@type' => 'Organization', 'name' => $j['company'] ?: 'Entreprise non communiquée'],
        'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $j['city'], 'postalCode' => $j['postal'], 'addressRegion' => $reg[1], 'addressCountry' => 'FR']],
        'identifier' => ['@type' => 'PropertyValue', 'name' => $j['src'], 'value' => $j['id']], 'directApply' => false];
    return layout($site, [
        'title' => mb_substr($j['title'], 0, 50) . ' — ' . $j['city'] . ' | ' . $site['name'], 'desc' => mb_strimwidth(trim(($j['company'] ? $j['company'] . ' recrute : ' : 'Offre : ') . $j['title'] . ' à ' . $j['city'] . '. ' . preg_replace('/\s+/', ' ', $j['description'])), 0, 155, '…'),
        'canonical' => 'https://' . $site['host'] . job_url($j), 'robots' => $closed ? 'noindex,follow' : null, 'schema' => $schema,
    ], $body);
}

function out_sitemap_jobs(array $site, int $i): void
{
    $st = jobs_db($site['host'])->prepare("SELECT slug, created_at FROM jobs WHERE status='open' ORDER BY created_at DESC LIMIT 1000 OFFSET ?");
    $st->execute([($i - 1) * 1000]);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    if ($i === 1) { echo '<url><loc>https://' . $site['host'] . '/offres-emploi/</loc></url>'; foreach (JOB_REGIONS as [$s]) echo '<url><loc>https://' . $site['host'] . '/offres-emploi/' . $s . '/</loc></url>'; }
    foreach ($st as $r) echo '<url><loc>https://' . $site['host'] . '/offre/' . h($r['slug']) . '/</loc><lastmod>' . substr($r['created_at'], 0, 10) . '</lastmod></url>';
    echo '</urlset>';
}

function jobs_sitemap_count(array $site): int
{
    return (int)ceil(max(1, (int)jobs_db($site['host'])->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn()) / 1000);
}
