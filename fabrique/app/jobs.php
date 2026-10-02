<?php
// Agrégateur d'offres d'emploi régionales : sources officielles (France Travail, Adzuna), pages régions et villes,
// fiches offres balisées JobPosting (Google Jobs), synchronisation par cron, signalement IndexNow (Bing / ChatGPT).
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
// Fiches pauvres (extrait Adzuna, description très courte) : affichées mais exclues de l'index et des sitemaps.
const JOB_INDEXABLE_SQL = "id NOT LIKE 'az-%' AND length(description) >= 300";
function job_is_thin(array $j): bool { return str_starts_with((string)$j['id'], 'az-') || mb_strlen((string)$j['description']) < 300; }
const JOB_CITY_MIN = 20; // nombre minimal d'offres ouvertes pour qu'une ville ait sa page

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
        CREATE INDEX IF NOT EXISTS ix_jobs_slug ON jobs(slug);
        CREATE TABLE IF NOT EXISTS job_cities(slug TEXT PRIMARY KEY, name TEXT, region TEXT, n INTEGER, raw TEXT, updated TEXT);
        CREATE INDEX IF NOT EXISTS ix_job_cities_reg ON job_cities(region, n);");
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

// Salaire structuré à partir du libellé (« Mensuel de 1801.80 Euros à 2000.00 Euros », « 35 000 à 48 000 € / an »…).
// Renvoie null si l'unité est inconnue ou si les montants sont incohérents (ex. « Annuel de 12 Euros »).
function job_salary(string $s): ?array
{
    $s = trim($s);
    if ($s === '') return null;
    $t = mb_strtolower($s);
    $unit = match (true) {
        str_starts_with($t, 'horaire') || (bool)preg_match('#/\s*(h|heure)\b#u', $t) => 'HOUR',
        str_starts_with($t, 'mensuel') || (bool)preg_match('#/\s*mois\b#u', $t) => 'MONTH',
        str_starts_with($t, 'annuel') || (bool)preg_match('#/\s*an\b#u', $t) => 'YEAR',
        default => null,
    };
    if ($unit === null) return null;
    $head = preg_split('/\s+-\s+/u', $s)[0];
    $head = preg_replace('/sur\s+\d+(?:[.,]\d+)?\s+mois/iu', '', $head);
    preg_match_all('/\d[\d \x{202F}\x{A0}]*(?:[.,]\d+)?/u', $head, $m);
    $nums = [];
    foreach ($m[0] as $n) {
        $n = str_replace([' ', "\u{202F}", "\u{A0}"], '', trim($n));
        $n = str_replace(',', '.', $n);
        if (is_numeric($n)) $nums[] = (float)$n;
    }
    if (!$nums) return null;
    $min = $nums[0]; $max = $nums[1] ?? $nums[0];
    if ($max < $min) [$min, $max] = [$max, $min];
    [$lo, $hi] = ['HOUR' => [9, 200], 'MONTH' => [500, 20000], 'YEAR' => [8000, 300000]][$unit];
    if ($min < $lo || $max > $hi) return null;
    return ['unit' => $unit, 'min' => $min, 'max' => $max];
}

// Équivalent brut mensuel (milieu de fourchette) : 151,67 h par mois, 12 mois par an.
function job_salary_monthly(array $s): float
{
    $mid = ($s['min'] + $s['max']) / 2;
    return match ($s['unit']) { 'HOUR' => $mid * 151.67, 'YEAR' => $mid / 12, default => $mid };
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

// Synchronise les offres de toutes les régions pour un site ; ferme les offres non revues depuis 3 jours,
// recalcule l'index des villes et signale à IndexNow les offres nouvelles / expirées et les pages de synthèse.
function jobs_sync(array $site, callable $log): array
{
    $db = jobs_db($site['host']);
    $tok = ft_token();
    if (!$tok && setting('adzuna_app_id', '') === '') return ['error' => 'aucune source configurée (clés France Travail / Adzuna dans Réglages)'];
    $now = now(); $n = 0;
    $maxRow = (int)$db->query('SELECT COALESCE(MAX(rowid),0) FROM jobs')->fetchColumn();
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
    $closing = $db->query("SELECT slug FROM jobs WHERE status='open' AND seen_at < datetime('now','-3 days')")->fetchAll(PDO::FETCH_COLUMN);
    $db->exec("UPDATE jobs SET status='closed' WHERE status='open' AND seen_at < datetime('now','-3 days')");
    // offres expirées conservées un an (titre, lieu, contrat) pour rediriger les anciens liens (ChatGPT, partages) vers une offre proche ;
    // la description, volumineuse, est vidée après 40 jours.
    $db->exec("UPDATE jobs SET description='' WHERE status='closed' AND description<>'' AND seen_at < datetime('now','-40 days')");
    $db->exec("DELETE FROM jobs WHERE status='closed' AND seen_at < datetime('now','-365 days')");
    $st = $db->prepare("SELECT slug FROM jobs WHERE status='open' AND rowid > ?"); $st->execute([$maxRow]);
    $fresh = $st->fetchAll(PDO::FETCH_COLUMN);
    $cities = jobs_cities_build($db);
    cache_clear($site['host']);
    $pinged = jobs_indexnow($site, $db, $fresh, $closing);
    return ['upserted' => $n, 'open' => (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open'")->fetchColumn(),
        'new' => count($fresh), 'closed' => count($closing), 'cities' => $cities, 'indexnow' => $pinged];
}

// Signale à IndexNow (Bing, Yandex… ; Bing alimente la recherche de ChatGPT) les URL qui ont changé.
function jobs_indexnow(array $site, PDO $db, array $fresh, array $closed): int
{
    if (!function_exists('indexnow_ping')) return 0;
    $b = 'https://' . $site['host'];
    $urls = [$b . '/offres-emploi/'];
    foreach (JOB_REGIONS as [$s]) $urls[] = $b . '/offres-emploi/' . $s . '/';
    foreach ($db->query('SELECT slug FROM job_cities') as $r) $urls[] = $b . '/offres-emploi/ville/' . $r['slug'] . '/';
    foreach (array_merge($fresh, $closed) as $s) $urls[] = $b . '/offre/' . $s . '/';
    $urls = array_values(array_unique($urls));
    foreach (array_chunk($urls, 10000) as $chunk) { try { indexnow_ping($site, $chunk); } catch (Throwable $e) {} }
    return count($urls);
}

// ---------- Villes ----------

function job_city_norm(string $c): string
{
    $c = trim(preg_replace('/\s+/u', ' ', $c));
    $c = preg_replace('/\s+cedex(\s*\d+)?$/iu', '', $c);
    $c = preg_replace('/\s*\(?\d{1,2}\s*(e|er|ème|eme)?\)?(\s+arrondissement)?$/iu', '', $c); // PARIS 15, Lyon 3e, Marseille 8e Arrondissement
    $c = preg_replace('/\s+\d{1,2}\s*(e|er|ème|eme)?\s+canton$/iu', '', $c); // Béziers 3e Canton
    $c = trim($c, " -,");
    if ($c === '' || preg_match('/^\d/', $c)) return '';
    // casse française : Boulogne-sur-Mer, Saint-Jean-de-Luz, L'Isle-d'Abeau
    $small = ['sur', 'sous', 'en', 'de', 'du', 'des', 'la', 'le', 'les', 'lès', 'lez', 'aux', 'au', 'et', 'l', 'd'];
    $parts = preg_split("/([\s\-'’])/u", mb_strtolower($c), -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = ''; $first = true;
    foreach ($parts as $p) {
        if ($p === '' || preg_match("/^[\s\-'’]$/u", $p)) { $out .= $p; continue; }
        $out .= ($first || !in_array($p, $small, true)) ? mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1) : $p;
        $first = false;
    }
    return $out;
}

// Recalcule la table des villes ayant au moins JOB_CITY_MIN offres ouvertes ; renvoie le nombre de villes.
function jobs_cities_build(PDO $db): int
{
    $g = [];
    foreach ($db->query("SELECT city, region, COUNT(*) n FROM jobs WHERE status='open' AND city<>'' GROUP BY city, region") as $r) {
        $name = job_city_norm((string)$r['city']);
        if (mb_strlen($name) < 2 || !isset(JOB_REGIONS[$r['region']])) continue;
        $k = $name . '|' . $r['region'];
        $g[$k] ??= ['name' => $name, 'region' => (string)$r['region'], 'n' => 0, 'raw' => []];
        $g[$k]['n'] += (int)$r['n'];
        $g[$k]['raw'][] = (string)$r['city'];
    }
    $g = array_filter($g, fn($c) => $c['n'] >= JOB_CITY_MIN);
    $byName = [];
    foreach ($g as $c) $byName[$c['name']][] = $c['region'];
    $db->beginTransaction();
    $db->exec('DELETE FROM job_cities');
    $ins = $db->prepare('INSERT OR REPLACE INTO job_cities(slug,name,region,n,raw,updated) VALUES(?,?,?,?,?,?)');
    foreach ($g as $c) {
        $slug = slugify($c['name'], 60) . (count($byName[$c['name']]) > 1 ? '-' . JOB_REGIONS[$c['region']][0] : '');
        $ins->execute([$slug, $c['name'], $c['region'], $c['n'], json_encode($c['raw'], JSON_UNESCAPED_UNICODE), now()]);
    }
    $db->commit();
    return count($g);
}

function jobs_cities_ready(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!(int)$db->query('SELECT COUNT(*) FROM job_cities')->fetchColumn()) jobs_cities_build($db);
}

function job_city_of(PDO $db, array $j): ?array
{
    jobs_cities_ready($db);
    $st = $db->prepare('SELECT slug, name, n FROM job_cities WHERE region=? AND name=?');
    $st->execute([(string)$j['region'], job_city_norm((string)$j['city'])]);
    return $st->fetch() ?: null;
}

function city_links(array $rows): string
{
    return implode(', ', array_map(fn($c) => '<a href="/offres-emploi/ville/' . h($c['slug']) . '/">' . h($c['name']) . '</a> (' . (int)$c['n'] . ')', $rows));
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
    return '<p class="disc">Offres issues de sources publiques et partenaires (France Travail, Choisir le service public, La bonne alternance, Adzuna) — <a href="/nos-sources/">nos sources</a>. Mise à jour : ' . date_fr(now()) . ' à ' . (new DateTime('now', new DateTimeZone('Europe/Paris')))->format('H') . ' h. La candidature se fait sur le site d\'origine de l\'offre.</p>';
}

// Bloc AdSense « display » responsive (ID dans le réglage adsense_slot_display) ; rien si non configuré.
function job_ad(array $site): string
{
    $slot = (string)setting('adsense_slot_display', '');
    $pub = (string)setting('adsense_pub', '');
    if (empty($site['adsense']) || $slot === '' || $pub === '') return '';
    return '<div class="ad" style="margin:24px 0;min-height:100px"><ins class="adsbygoogle" style="display:block" data-ad-client="' . h($pub) . '" data-ad-slot="' . h($slot) . '" data-ad-format="auto" data-full-width-responsive="true"></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>';
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
    jobs_cities_ready($db);
    $topCities = $db->query('SELECT slug, name, n FROM job_cities ORDER BY n DESC LIMIT 40')->fetchAll();
    $latest = $db->query("SELECT * FROM jobs WHERE status='open' ORDER BY created_at DESC LIMIT 12")->fetchAll();
    $body = '<h1 style="margin-top:28px">Offres d\'emploi par région</h1><p>' . number_format($total, 0, ',', ' ') . ' offres d\'emploi actualisées plusieurs fois par jour : CDI, CDD, intérim, alternance et stages dans toutes les régions de France.</p>'
        . (function_exists('fr_map') ? '<style>' . fr_map_css() . '</style>' . fr_map($counts, '/offres-emploi/') : '')
        . '<h2>Offres par type de contrat</h2>' . count_chips(array_map(fn($r) => [JOB_CONTRACTS[$r['contract']] ?? ucfirst((string)$r['contract']), $r['n'], ''], $db->query("SELECT contract, COUNT(*) n FROM jobs WHERE status='open' AND contract<>'' GROUP BY contract ORDER BY n DESC")->fetchAll()))
        . '<h2>Offres par secteur d\'activité</h2>' . count_chips(array_map(fn($r) => [$r['sector'], $r['n'], ''], $db->query("SELECT sector, COUNT(*) n FROM jobs WHERE status='open' AND sector<>'' GROUP BY sector ORDER BY n DESC LIMIT 20")->fetchAll()))
        . (function_exists('intents_chips') ? intents_chips($db) : '')
        . (function_exists('csp_tables') && ($np = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND id LIKE 'csp-%'")->fetchColumn()) ? '<p><a href="/emploi-public/"><strong>Emploi public : ' . number_format($np, 0, ',', ' ') . ' offres de la fonction publique →</strong></a></p>' : '')
        . '<h2>Offres d\'emploi par région</h2><div class="grid">' . $regs . '</div>'
        . ($topCities ? '<h2>Villes qui recrutent le plus</h2><p>' . city_links($topCities) . '.</p>' : '')
        . '<h2>Dernières offres publiées</h2><div class="grid">' . implode('', array_map('job_card', $latest)) . '</div>' . jobs_disclaimer();
    return layout($site, ['title' => 'Offres d\'emploi en France : ' . number_format($total, 0, ',', ' ') . ' offres à jour aujourd\'hui | ' . $site['name'], 'desc' => "$total offres d'emploi en France par région : CDI, CDD, intérim, alternance. Mises à jour quotidiennes.", 'canonical' => 'https://' . $site['host'] . '/offres-emploi/'], $body);
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
    jobs_cities_ready($db);
    $st = $db->prepare('SELECT slug, name, n FROM job_cities WHERE region=? ORDER BY n DESC LIMIT 20'); $st->execute([$reg['code']]);
    $cityRows = $st->fetchAll();
    if ($cityRows) $cities = city_links($cityRows);
    else {
        $st = $db->prepare("SELECT city, COUNT(*) n FROM jobs WHERE status='open' AND region=? AND city<>'' GROUP BY city ORDER BY n DESC LIMIT 12"); $st->execute([$reg['code']]);
        $cities = implode(', ', array_map(fn($c) => h($c['city']) . ' (' . $c['n'] . ')', $st->fetchAll()));
    }
    $guides = site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT 6")->fetchAll();
    $title = 'Offres d\'emploi ' . $reg['name'] . ($contract ? ' en ' . JOB_CONTRACTS[$contract] : '');
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › ' . h($reg['name']) . '</p>'
        . '<h1>' . h($title) . '</h1><p>' . number_format($total, 0, ',', ' ') . ' offres d\'emploi en ' . h($reg['name']) . ', mises à jour plusieurs fois par jour. Villes qui recrutent le plus : ' . $cities . '.</p>'
        . $chips . '<div class="grid">' . implode('', array_map('job_card', $jobs)) . '</div>'
        . pager($base, $page, (int)ceil($total / $per))
        . ($guides ? '<h2>Nos conseils pour décrocher le poste</h2><ul>' . implode('', array_map(fn($g) => '<li><a href="' . h($g['path']) . '">' . h($g['title']) . '</a></li>', $guides)) . '</ul>' : '')
        . careerjet_slot($site, $contract ? JOB_CONTRACTS[$contract] : '', $reg['name'])
        . jobs_disclaimer();
    return layout($site, [
        'title' => ($page > 1 || $contract ? $title . ($page > 1 ? " — page $page" : '') : 'Emploi ' . $reg['name'] . ' : ' . number_format($total, 0, ',', ' ') . ' offres, mises à jour aujourd\'hui') . ' | ' . $site['name'],
        'desc' => number_format($total, 0, ',', ' ') . " offres d'emploi en {$reg['name']} mises à jour aujourd'hui : CDI, CDD, intérim, alternance, emploi public. Postulez directement auprès des recruteurs.",
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''), 'robots' => $contract ? 'noindex,follow' : null,
        'schema' => [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], [$reg['name'], $base]])],
    ], $body);
}

// Page ville : offres ouvertes + chiffres propres au site (contrats, salaires annoncés, secteurs, employeurs), FAQ.
function page_jobs_city(array $site, string $slug): string
{
    $db = jobs_db($site['host']);
    jobs_cities_ready($db);
    $st = $db->prepare('SELECT * FROM job_cities WHERE slug=?'); $st->execute([$slug]);
    $c = $st->fetch();
    if (!$c) return '';
    $raw = json_decode((string)$c['raw'], true) ?: [];
    if (!$raw) return '';
    $st = $db->prepare("SELECT slug,title,company,city,postal,contract,contract_label,salary,sector,worktime,created_at FROM jobs WHERE status='open' AND region=? AND city IN ("
        . implode(',', array_fill(0, count($raw), '?')) . ') ORDER BY created_at DESC');
    $st->execute(array_merge([(string)$c['region']], $raw));
    $jobs = $st->fetchAll();
    $total = count($jobs);
    if ($total < 5) return '';
    $reg = JOB_REGIONS[$c['region']] ?? ['', ''];
    $name = (string)$c['name'];
    $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $contracts = []; $sectors = []; $companies = []; $monthly = []; $new7 = 0; $full = 0; $wt = 0;
    $lim = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
    foreach ($jobs as $j) {
        $contracts[$j['contract']] = ($contracts[$j['contract']] ?? 0) + 1;
        if ((string)$j['sector'] !== '' && !preg_match('~^(unknown|autres?$|non renseign)|autres?/g[ée]n[ée]ral~iu', (string)$j['sector'])) $sectors[$j['sector']] = ($sectors[$j['sector']] ?? 0) + 1;
        if ((string)$j['company'] !== '') $companies[$j['company']] = ($companies[$j['company']] ?? 0) + 1;
        if ($j['created_at'] >= $lim) $new7++;
        if ($s = job_salary((string)$j['salary'])) $monthly[] = job_salary_monthly($s);
        if ((string)$j['worktime'] !== '') { $wt++; if (preg_match('/plein|complet/iu', (string)$j['worktime'])) $full++; }
    }
    arsort($contracts); arsort($sectors); arsort($companies);
    $companies = array_slice(array_filter($companies, fn($n) => $n >= 2), 0, 8, true);
    $sectors = array_slice($sectors, 0, 8, true);
    sort($monthly);
    $q = function (float $p) use ($monthly): float {
        $n = count($monthly); $i = ($n - 1) * $p; $lo = (int)floor($i);
        return $monthly[$lo] + ($monthly[min($n - 1, $lo + 1)] - $monthly[$lo]) * ($i - $lo);
    };
    $nsal = count($monthly);
    $med = $nsal >= 5 ? $q(0.5) : null;
    $pct = fn(int $n) => (int)round(100 * $n / $total);
    $day = date_fr(now());
    $base = '/offres-emploi/ville/' . $slug . '/';
    $recent = $new7 >= $total ? 'toutes publiées ces 7 derniers jours' : 'dont ' . $nf($new7) . ' publiées ces 7 derniers jours';

    $facts = ['Offres d\'emploi ouvertes' => $nf($total), 'Publiées ces 7 derniers jours' => $nf($new7), 'Part de CDI' => $pct($contracts['cdi'] ?? 0) . ' %'];
    if ($wt >= 5) $facts['Temps plein'] = round(100 * $full / $wt) . ' % des offres qui précisent la durée du travail';
    if ($med !== null) {
        $facts['Salaire médian annoncé'] = $nf($med) . ' € brut par mois (' . $nf($nsal) . ' offres affichent un salaire)';
        $facts['Fourchette courante'] = $nf($q(0.25)) . ' à ' . $nf($q(0.75)) . ' € brut par mois';
    }
    $contractTxt = implode(', ', array_map(fn($k, $v) => (JOB_CONTRACTS[$k] ?? $k) . ' ' . $pct($v) . ' % (' . $nf($v) . ')', array_keys(array_slice($contracts, 0, 5, true)), array_slice($contracts, 0, 5, true)));
    $sectorTxt = implode(', ', array_map(fn($k, $v) => $k . ' (' . $v . ')', array_keys(array_slice($sectors, 0, 5, true)), array_slice($sectors, 0, 5, true)));

    $faq = [["Combien y a-t-il d'offres d'emploi à $name ?", "Au $day, " . $site['name'] . " recense " . $nf($total) . " offres d'emploi ouvertes à $name ({$reg[1]}), " . $recent . ". La liste est mise à jour plusieurs fois par jour."],
        ["Quels types de contrat sont proposés à $name ?", "Répartition des offres ouvertes : $contractTxt."]];
    if ($med !== null) $faq[] = ["Quel salaire est proposé dans les offres d'emploi à $name ?", "Le salaire médian annoncé est de " . $nf($med) . " € brut par mois, calculé sur " . $nf($nsal) . " offres qui affichent une rémunération (salaires horaires et annuels ramenés au mois). La moitié de ces offres se situe entre " . $nf($q(0.25)) . " et " . $nf($q(0.75)) . " € brut par mois."];
    if ($sectorTxt !== '') $faq[] = ["Quels secteurs recrutent à $name ?", "Secteurs qui publient le plus d'offres en ce moment : $sectorTxt."];
    $faq[] = ["Comment postuler à une offre d'emploi à $name ?", "Chaque offre renvoie vers son site d'origine (France Travail, Adzuna ou le site du recruteur), où se fait la candidature. Nos guides CV, lettre de motivation et entretien d'embauche aident à préparer le dossier."];

    $st = $db->prepare('SELECT slug, name, n FROM job_cities WHERE region=? AND slug<>? ORDER BY n DESC LIMIT 15'); $st->execute([(string)$c['region'], $slug]);
    $others = $st->fetchAll();
    $tbl = fn(array $rows) => '<table>' . implode('', array_map(fn($k, $v) => '<tr><th>' . h((string)$k) . '</th><td>' . h((string)$v) . '</td></tr>', array_keys($rows), $rows)) . '</table>';

    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › <a href="/offres-emploi/' . h($reg[0]) . '/">' . h($reg[1]) . '</a> › ' . h($name) . '</p>'
        . '<h1>Offres d\'emploi à ' . h($name) . '</h1>'
        . '<p class="lead">' . $nf($total) . ' offres d\'emploi ouvertes à ' . h($name) . ' (' . h($reg[1]) . ') au ' . h($day) . ', ' . $recent . '. Chiffres calculés par ' . h($site['name']) . ' à partir des offres France Travail et Adzuna, mis à jour plusieurs fois par jour.</p>'
        . '<h2>Le marché de l\'emploi à ' . h($name) . ' en chiffres</h2>' . $tbl($facts)
        . '<h2>Types de contrat</h2>' . $tbl(array_combine(array_map(fn($k) => JOB_CONTRACTS[$k] ?? $k, array_keys($contracts)), array_map(fn($v) => $nf($v) . ' offres (' . $pct($v) . ' %)', $contracts)))
        . ($sectors ? '<h2>Secteurs qui recrutent à ' . h($name) . '</h2>' . $tbl(array_map(fn($v) => $nf($v) . ' offres', $sectors)) : '')
        . ($companies ? '<h2>Employeurs qui publient le plus d\'offres</h2>' . $tbl(array_map(fn($v) => $nf($v) . ' offres', $companies)) : '')
        . job_ad($site)
        . '<h2>Dernières offres d\'emploi à ' . h($name) . '</h2><div class="grid">' . implode('', array_map('job_card', array_slice($jobs, 0, 30))) . '</div>'
        . '<p><a href="/offres-emploi/' . h($reg[0]) . '/">Toutes les offres d\'emploi en ' . h($reg[1]) . '</a></p>'
        . '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq))
        . ($others ? '<h2>Autres villes qui recrutent en ' . h($reg[1]) . '</h2><p>' . city_links($others) . '.</p>' : '')
        . (function_exists('lba_city_blocks') ? lba_city_blocks($site, $c) : '')
        . careerjet_slot($site, '', $name)
        . jobs_disclaimer();
    $schema = [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], [$reg[1], '/offres-emploi/' . $reg[0] . '/'], ['Emploi ' . $name, $base]]),
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)]];
    $desc = $nf($total) . " offres d'emploi à $name : " . $pct($contracts['cdi'] ?? 0) . ' % en CDI' . ($med !== null ? ', salaire médian annoncé ' . $nf($med) . ' € brut/mois' : '') . ($sectors ? '. Secteurs qui recrutent : ' . implode(', ', array_slice(array_keys($sectors), 0, 3)) : '') . '.';
    return layout($site, ['title' => 'Emploi ' . $name . ' : ' . $nf($total) . ' offres, mises à jour aujourd\'hui | ' . $site['name'], 'desc' => mb_strimwidth($desc, 0, 158, '…'),
        'canonical' => 'https://' . $site['host'] . $base, 'schema' => $schema], $body);
}

// Offre ouverte la plus proche d'une offre expirée : mots du titre communs, même département, même contrat, même région.
function job_best_match(PDO $db, array $j): ?string
{
    $words = fn(string $t) => array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)), fn($w) => mb_strlen($w) > 2 && !in_array($w, ['les', 'des', 'une', 'pour', 'avec', 'sur'], true));
    $ref = array_flip($words((string)$j['title']));
    $dep = substr((string)$j['postal'], 0, 2);
    $st = $db->prepare("SELECT slug, title, postal, contract FROM jobs WHERE status='open' AND region=? AND id<>? ORDER BY created_at DESC LIMIT 400");
    $st->execute([$j['region'], $j['id']]);
    $best = null; $bs = 0;
    foreach ($st as $c) {
        $common = count(array_intersect_key(array_flip($words((string)$c['title'])), $ref));
        $s = 4 * $common + ($dep !== '' && substr((string)$c['postal'], 0, 2) === $dep ? 2 : 0) + ($c['contract'] === $j['contract'] ? 1 : 0);
        if ($s > $bs) { $bs = $s; $best = $c['slug']; }
    }
    return $bs >= 4 ? $best : null; // au moins un mot du titre en commun, sinon page 410 avec offres similaires
}

// Lien vers une offre inconnue (supprimée de la base ou mal recopiée) : 410 + offres proches déduites des mots de l'URL.
function page_job_gone(array $site, PDO $db, string $slug): string
{
    $stop = ['pour', 'avec', 'sans', 'dans', 'chez', 'poste', 'emploi', 'offre'];
    $words = array_values(array_unique(array_filter(explode('-', preg_replace('/-[a-f0-9]{6}$/', '', $slug)), fn($w) => strlen($w) > 3 && !in_array($w, $stop, true))));
    if (!$words) return '';
    usort($words, fn($a, $b) => strlen($b) <=> strlen($a));
    $ref = array_flip($words);
    $scored = [];
    foreach (array_slice($words, 0, 2) as $w) {
        $st = $db->prepare("SELECT * FROM jobs WHERE status='open' AND slug LIKE ? ORDER BY created_at DESC LIMIT 300");
        $st->execute(['%' . $w . '%']);
        foreach ($st as $c) $scored[$c['id']] ??= [count(array_intersect_key(array_flip(explode('-', (string)$c['slug'])), $ref)), $c];
    }
    if (!$scored) return '';
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    $sugg = array_map(fn($x) => $x[1], array_slice($scored, 0, 12));
    http_response_code(410);
    $body = '<div class="layout"><article><p class="crumbs" style="margin-top:24px"><a href="/offres-emploi/">Offres d\'emploi</a></p>'
        . '<h1>Cette offre d\'emploi n\'est plus disponible</h1>'
        . '<p class="lead">Elle a été pourvue ou retirée par le recruteur. Voici des offres proches encore ouvertes.</p>'
        . '<div class="grid">' . implode('', array_map('job_card', array_slice($sugg, 0, 6))) . '</div>'
        . job_ad($site)
        . (count($sugg) > 6 ? '<div class="grid">' . implode('', array_map('job_card', array_slice($sugg, 6))) . '</div>' : '')
        . '<p><a href="/offres-emploi/">Toutes les offres d\'emploi par région</a></p>' . jobs_disclaimer() . '</article></div>';
    return layout($site, ['title' => 'Offre d\'emploi expirée | ' . $site['name'], 'desc' => 'Cette offre d\'emploi n\'est plus disponible. Découvrez des offres proches encore ouvertes.', 'robots' => 'noindex,follow'], $body);
}

function page_job(array $site, string $slug): ?string
{
    $db = jobs_db($site['host']);
    $st = $db->prepare('SELECT * FROM jobs WHERE slug=?'); $st->execute([$slug]);
    $j = $st->fetch();
    if (!$j) return page_job_gone($site, $db, $slug);
    $reg = JOB_REGIONS[$j['region']] ?? ['', ''];
    $st = $db->prepare("SELECT * FROM jobs WHERE status='open' AND region=? AND id<>? ORDER BY (contract=?) DESC, created_at DESC LIMIT 6"); $st->execute([$j['region'], $j['id'], $j['contract']]);
    $similar = $st->fetchAll();
    $closed = $j['status'] !== 'open';
    if ($closed && ($to = job_best_match($db, $j))) { // offre expirée : 301 vers l'offre ouverte la plus proche
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        header('Location: /offre/' . $to . '/' . ($qs !== '' ? '?' . $qs : ''), true, 301);
        return null;
    }
    if ($closed) http_response_code(410);
    $city = job_city_of($db, $j);
    $facts = array_filter(['Entreprise' => $j['company'], 'Lieu' => trim($j['city'] . ' ' . $j['postal']), 'Contrat' => $j['contract_label'] ?: (JOB_CONTRACTS[$j['contract']] ?? ''),
        'Durée du travail' => $j['worktime'], 'Salaire' => $j['salary'], 'Expérience' => $j['experience'], 'Secteur' => $j['sector']]);
    $rel = $j['src'] === 'Adzuna' ? 'sponsored nofollow noopener' : 'nofollow noopener';
    $simHtml = $similar ? '<h2>Offres similaires en ' . h($reg[1]) . '</h2><div class="grid">' . implode('', array_map('job_card', $similar)) . '</div>' : '';
    $cityHtml = $city ? '<p><a href="/offres-emploi/ville/' . h($city['slug']) . '/">Toutes les offres d\'emploi à ' . h($city['name']) . '</a> (' . (int)$city['n'] . ' offres, salaires et secteurs qui recrutent)</p>' : '';
    $desc = trim((string)$j['description']);
    $body = '<div class="layout"><article><p class="crumbs"><a href="/offres-emploi/">Offres d\'emploi</a> › <a href="/offres-emploi/' . h($reg[0]) . '/">' . h($reg[1]) . '</a>'
        . ($city ? ' › <a href="/offres-emploi/ville/' . h($city['slug']) . '/">' . h($city['name']) . '</a>' : '') . '</p>'
        . '<h1>' . h($j['title']) . '</h1>'
        . ($closed ? '<p class="lead">Cette offre n\'est plus disponible. Voici des offres similaires encore ouvertes.</p>' . $simHtml . $cityHtml . job_ad($site) : '')
        . '<table>' . implode('', array_map(fn($k, $v) => '<tr><th>' . h($k) . '</th><td>' . h($v) . '</td></tr>', array_keys($facts), $facts)) . '</table>'
        . ($closed ? '' : '<p><a class="btn" style="background:var(--c);color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:700" href="' . h($j['url']) . '" rel="' . $rel . '" target="_blank">Postuler sur le site de l\'offre</a></p>')
        . ($desc !== '' ? '<h2>Description du poste</h2><div>' . nl2br(h($desc)) . '</div>' : '')
        . '<p class="disc">Source : ' . h($j['src']) . ($j['src'] === 'Adzuna' ? ' — Jobs by Adzuna' : '') . '. Publiée le ' . h(date_fr($j['created_at'])) . '.</p>'
        . ($closed ? '' : job_ad($site) . (function_exists('metier_links_for_job') ? metier_links_for_job($site, $j) . metier_similar_for_job($site, $j) : '') . (function_exists('lba_job_blocks') ? lba_job_blocks($site, $j) : '') . $simHtml . $cityHtml)
        . '</article><aside><div class="box"><h4>Préparer sa candidature</h4><ul>'
        . implode('', array_map(fn($g) => '<li><a href="' . h($g['path']) . '">' . h($g['title']) . '</a></li>', site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY published_at DESC LIMIT 8")->fetchAll()))
        . '</ul></div></aside></div>';
    $types = ['cdi' => 'FULL_TIME', 'cdd' => 'TEMPORARY', 'interim' => 'TEMPORARY', 'alternance' => 'INTERN', 'stage' => 'INTERN', 'freelance' => 'CONTRACTOR', 'autre' => 'OTHER'];
    $schema = [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], [$reg[1], '/offres-emploi/' . $reg[0] . '/'], [$j['title'], job_url($j)]])];
    if (!$closed) {
        $posting = ['@context' => 'https://schema.org', '@type' => 'JobPosting', 'title' => $j['title'], 'description' => nl2br(h($desc)),
            'datePosted' => substr($j['created_at'], 0, 10),
            'employmentType' => $types[$j['contract']] ?? 'OTHER', 'hiringOrganization' => ['@type' => 'Organization', 'name' => $j['company'] ?: 'Entreprise non communiquée'],
            'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $j['city'], 'postalCode' => $j['postal'], 'addressRegion' => $reg[1], 'addressCountry' => 'FR']],
            'identifier' => ['@type' => 'PropertyValue', 'name' => $j['src'], 'value' => $j['id']], 'directApply' => false];
        if (str_starts_with((string)$j['id'], 'csp-') && function_exists('csp_tables')) { // emploi public : employeur, département et date limite réels
            csp_tables($db); $cm = $db->prepare('SELECT employeur, dep_name, deadline FROM csp_meta WHERE id=?'); $cm->execute([$j['id']]);
            if ($cm = $cm->fetch()) {
                if ($cm['employeur']) $posting['hiringOrganization']['name'] = $cm['employeur'];
                $posting['jobLocation']['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $cm['dep_name'] ?: $reg[1], 'addressRegion' => $reg[1], 'addressCountry' => 'FR'];
                if ($cm['deadline']) $posting['validThrough'] = $cm['deadline'] . 'T23:59:00+02:00';
            }
        } elseif ($j['city'] === '') unset($posting['jobLocation']['address']['addressLocality']);
        if ($sal = job_salary((string)$j['salary'])) {
            $posting['baseSalary'] = ['@type' => 'MonetaryAmount', 'currency' => 'EUR', 'value' => ['@type' => 'QuantitativeValue', 'unitText' => $sal['unit']]
                + ($sal['min'] == $sal['max'] ? ['value' => $sal['min']] : ['minValue' => $sal['min'], 'maxValue' => $sal['max']])];
        }
        $schema[] = $posting;
    }
    return layout($site, [
        'title' => mb_substr($j['title'], 0, 50) . ' — ' . $j['city'] . ' | ' . $site['name'], 'desc' => mb_strimwidth(trim(($j['company'] ? $j['company'] . ' recrute : ' : 'Offre : ') . $j['title'] . ' à ' . $j['city'] . '. ' . preg_replace('/\s+/', ' ', $desc)), 0, 155, '…'),
        'canonical' => 'https://' . $site['host'] . job_url($j), 'robots' => ($closed || job_is_thin($j)) ? 'noindex,follow' : null, 'schema' => $schema,
    ], $body);
}

function out_sitemap_jobs(array $site, int $i): void
{
    $db = jobs_db($site['host']);
    $st = $db->prepare("SELECT slug, created_at FROM jobs WHERE status='open' AND " . JOB_INDEXABLE_SQL . " ORDER BY created_at DESC LIMIT 1000 OFFSET ?");
    $st->execute([($i - 1) * 1000]);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    if ($i === 1) {
        $today = gmdate('Y-m-d');
        echo '<url><loc>https://' . $site['host'] . '/offres-emploi/</loc><lastmod>' . $today . '</lastmod></url>';
        foreach (JOB_REGIONS as [$s]) echo '<url><loc>https://' . $site['host'] . '/offres-emploi/' . $s . '/</loc><lastmod>' . $today . '</lastmod></url>';
        if (function_exists('csp_tables') && jobs_db($site['host'])->query("SELECT 1 FROM jobs WHERE status='open' AND id LIKE 'csp-%' LIMIT 1")->fetchColumn()) {
            echo '<url><loc>https://' . $site['host'] . '/emploi-public/</loc><lastmod>' . $today . '</lastmod></url>';
            foreach (JOB_REGIONS as [$s]) echo '<url><loc>https://' . $site['host'] . '/emploi-public/' . $s . '/</loc><lastmod>' . $today . '</lastmod></url>';
            foreach (array_keys(CSP_VERSANTS) as $v) echo '<url><loc>https://' . $site['host'] . '/emploi-public/versant/' . $v . '/</loc><lastmod>' . $today . '</lastmod></url>';
        }
        jobs_cities_ready($db);
        foreach ($db->query('SELECT slug, updated FROM job_cities ORDER BY n DESC') as $c) echo '<url><loc>https://' . $site['host'] . '/offres-emploi/ville/' . h($c['slug']) . '/</loc><lastmod>' . substr((string)$c['updated'], 0, 10) . '</lastmod></url>';
    }
    foreach ($st as $r) echo '<url><loc>https://' . $site['host'] . '/offre/' . h($r['slug']) . '/</loc><lastmod>' . substr($r['created_at'], 0, 10) . '</lastmod></url>';
    echo '</urlset>';
}

function jobs_sitemap_count(array $site): int
{
    return (int)ceil(max(1, (int)jobs_db($site['host'])->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND " . JOB_INDEXABLE_SQL)->fetchColumn()) / 1000);
}
