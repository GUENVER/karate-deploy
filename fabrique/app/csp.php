<?php
// Emploi public : offres de Choisir le service public (choisirleservicepublic.gouv.fr, DGAFP), données publiques
// sous Licence Ouverte. Import par cli/csp_sync.php, pages /emploi-public/.
declare(strict_types=1);

const CSP_BASE = 'https://choisirleservicepublic.gouv.fr';
const CSP_VERSANTS = ['etat' => ["Fonction publique de l'État", "de l'État"], 'territoriale' => ['Fonction publique Territoriale', 'territoriale'], 'hospitaliere' => ['Fonction publique Hospitalière', 'hospitalière']];
const CSP_DEP_REG = ['84' => '01 03 07 15 26 38 42 43 63 69 73 74', '27' => '21 25 39 58 70 71 89 90', '53' => '22 29 35 56', '24' => '18 28 36 37 41 45', '94' => '2A 2B 20',
    '44' => '08 10 51 52 54 55 57 67 68 88', '32' => '02 59 60 62 80', '11' => '75 77 78 91 92 93 94 95', '28' => '14 27 50 61 76', '75' => '16 17 19 23 24 33 40 47 64 79 86 87',
    '76' => '09 11 12 30 31 32 34 46 48 65 66 81 82', '52' => '44 49 53 72 85', '93' => '04 05 06 13 83 84', '01' => '971', '02' => '972', '03' => '973', '04' => '974', '06' => '976'];

function csp_region_of_dep(string $dep): ?string
{
    foreach (CSP_DEP_REG as $reg => $deps) if (in_array($dep, explode(' ', $deps), true)) return (string)$reg;
    return null;
}

function csp_tables(PDO $db): void
{
    static $done = [];
    if (!empty($done[spl_object_id($db)])) return;
    $done[spl_object_id($db)] = 1;
    $db->exec("CREATE TABLE IF NOT EXISTS csp_meta(id TEXT PRIMARY KEY, ref TEXT, versant TEXT, categorie TEXT, domaine TEXT, employeur TEXT, dep TEXT, dep_name TEXT, deadline TEXT, metier TEXT, statut TEXT, fetched TEXT);
        CREATE INDEX IF NOT EXISTS ix_csp_versant ON csp_meta(versant);
        CREATE TABLE IF NOT EXISTS csp_queue(ref TEXT PRIMARY KEY, url TEXT, found TEXT, status TEXT DEFAULT 'todo', tries INTEGER DEFAULT 0);");
}

function csp_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_ENCODING => '', CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; recruteur.eu/1.0; +https://www.recruteur.eu/a-propos/)']);
    $b = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$code, $b];
}

// Liens d'offres d'une page de résultats : [référence => url]
function csp_list_page(int $n): ?array
{
    [$code, $b] = csp_get(CSP_BASE . '/nos-offres/' . ($n > 1 ? "page/$n/" : ''));
    if ($code !== 200) return null;
    preg_match_all('#href="(https://choisirleservicepublic\.gouv\.fr/offre-emploi/[a-z0-9\-]+-reference-([A-Za-z0-9_\-]+)/)"#', $b, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) $out[$x[2]] = $x[1];
    return $out;
}

function csp_text(string $html): string
{
    // découpe sans regex sur les blocs script/style (pages de plusieurs Mo : évite les limites PCRE)
    foreach (['script', 'style'] as $tag) {
        $out = ''; $pos = 0;
        while (($i = stripos($html, '<' . $tag, $pos)) !== false) {
            $out .= substr($html, $pos, $i - $pos);
            $j = stripos($html, '</' . $tag . '>', $i);
            if ($j === false) { $pos = strlen($html); break; }
            $pos = $j + strlen($tag) + 3;
        }
        $html = $out . substr($html, $pos);
    }
    $html = (string)preg_replace('#<br\s*/?>|</(p|div|li|h\d|dt|dd|tr)>#i', "\n", $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string)(preg_replace("/[ \t\x{00A0}]+/u", ' ', $t) ?? str_replace("\t", ' ', $t));
    return trim((string)preg_replace("/\n\s*\n+/", "\n", $t));
}

// Analyse une page d'offre → [job, meta] ou null.
function csp_parse(string $html, string $ref, string $url): ?array
{
    if (!preg_match('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $m)) return null;
    $ld = json_decode($m[1], true);
    if (!$ld || ($ld['@type'] ?? '') !== 'JobPosting' || trim((string)($ld['title'] ?? '')) === '') return null;
    $main = substr($html, max(0, (int)strpos($html, '<main')));
    $t = csp_text($main);
    $after = function (string $label, int $maxLen = 200) use ($t): string {
        if (!preg_match('/' . $label . '\s*:?\s*\n?\s*([^\n]+)/u', $t, $m)) return '';
        $v = trim($m[1]);
        return ($v === '' || stripos($v, 'non renseign') === 0) ? '' : mb_substr($v, 0, $maxLen);
    };
    $between = function (string $from, array $to) use ($t): string {
        $i = mb_strpos($t, $from);
        if ($i === false) return '';
        $i += mb_strlen($from);
        $j = mb_strlen($t);
        foreach ($to as $s) { $k = mb_strpos($t, $s, $i); if ($k !== false && $k < $j) $j = $k; }
        return trim(str_replace('Afficher la suite', '', mb_substr($t, $i, $j - $i)));
    };
    $missions = $between('Vos missions en quelques mots', ['Profil recherché', "Niveau d'études minimum requis", 'Éléments de candidature', 'Localisation']);
    $profil = $between('Profil recherché', ["Niveau d'études minimum requis", 'Langues', 'Localisation', 'Éléments de candidature']);
    $qui = $between('Qui sommes-nous', ["À propos de l'offre", 'Statut du poste', "Des offres d'emplois"]);
    $qui = ltrim($qui, " ?\u{00A0}\n");
    $desc = trim($missions . ($profil !== '' ? "\n\nProfil recherché :\n" . $profil : '') . ($qui !== '' && mb_strlen($qui) > 30 ? "\n\nL'employeur :\n" . $qui : ''));
    if (mb_strlen($desc) < 80) $desc = trim((string)($ld['Description'] ?? $ld['description'] ?? ''));
    $versant = $after('Fonction publique');
    $deadline = preg_match('#Date limite de candidature\s*:\s*(\d{2})/(\d{2})/(\d{4})#u', $t, $d) ? "$d[3]-$d[2]-$d[1]" : '';
    $pc = (string)($ld['jobLocation']['address']['postalCode'] ?? $ld['addressLocality'] ?? '');
    $dep = preg_match('/\((\d{2,3}|2A|2B)\)/', $pc, $x) ? $x[1] : '';
    $depName = trim(preg_replace('/\s*\(.*$/', '', $pc));
    $reg = csp_region_of_dep($dep);
    if (!$reg) return null;
    $employeur = trim((string)($ld['hiringOrganization'] ?? '')) ?: $after('Employeur');
    $nature = $after("Nature de l’emploi") ?: $after("Nature de l'emploi");
    $contrat = $after('Nature du contrat');
    $cat = $after('Catégorie');
    $salC = $after('Fourchette indicative pour les contractuels'); $salF = $after('Fourchette indicative pour les fonctionnaires');
    $posted = preg_match('#(\d{2})/(\d{2})/(\d{4})#', (string)($ld['datePosted'] ?? ''), $p) ? "$p[3]-$p[2]-$p[1] 08:00:00" : now();
    $ck = preg_match('/\bCDI\b/u', $contrat) ? 'cdi' : (preg_match('/\bCDD\b|contrat à durée déterminée/iu', $contrat) ? 'cdd' : (preg_match('/apprenti|altern/iu', $contrat . ' ' . $ld['title']) ? 'alternance' : (preg_match('/stage/iu', $contrat) ? 'stage' : 'autre')));
    $job = [
        'id' => 'csp-' . $ref, 'src' => 'Choisir le service public', 'title' => trim((string)$ld['title']),
        'company' => trim($employeur . ($depName !== '' ? ' — ' . $depName . ($dep ? " ($dep)" : '') : '')), 'description' => $desc,
        'city' => '', 'postal' => $dep, 'region' => $reg, 'contract' => $ck,
        'contract_label' => trim(implode(' — ', array_filter([$cat ? preg_replace('/\s*\(.*$/', '', $cat) : '', $contrat ?: $nature]))),
        'worktime' => '', 'salary' => $salC ?: $salF, 'experience' => trim(implode(' · ', array_filter([trim((string)($ld['experienceRequirements'] ?? '')), trim((string)($ld['educationRequirements'] ?? ''))]))),
        'sector' => trim((string)($ld['industry'] ?? '')), 'url' => $url, 'created_at' => $posted,
    ];
    $vk = '';
    foreach (CSP_VERSANTS as $k => [$full, $short]) if (mb_stripos($versant, $short) !== false) $vk = $k;
    $meta = ['id' => $job['id'], 'ref' => $ref, 'versant' => $vk, 'categorie' => $cat, 'domaine' => $job['sector'], 'employeur' => $employeur, 'dep' => $dep, 'dep_name' => $depName,
        'deadline' => $deadline, 'metier' => $after('Métier de référence'), 'statut' => $after('Statut du poste'), 'fetched' => now()];
    return [$job, $meta];
}

// ---------- Pages /emploi-public/ ----------

function csp_where(): string { return "j.status='open' AND j.id LIKE 'csp-%'"; }

function csp_nav(string $active = ''): string
{
    $l = '<a class="kick" style="margin-right:12px" href="/emploi-public/">Toutes</a>';
    foreach (CSP_VERSANTS as $k => [$full]) $l .= '<a class="kick" style="margin-right:12px' . ($active === $k ? ';text-decoration:underline' : '') . '" href="/emploi-public/versant/' . $k . '/">' . h($full) . '</a>';
    return '<p>' . $l . '</p>';
}

function csp_disclaimer(): string
{
    return '<p class="disc">Offres d\'emploi publiques issues de <a href="https://choisirleservicepublic.gouv.fr/" rel="nofollow noopener" target="_blank">Choisir le service public</a>, la plateforme officielle de recrutement des trois fonctions publiques (données publiques, Licence Ouverte). La candidature se fait sur le site officiel.</p>';
}

function page_emploi_public(array $site): string
{
    $db = jobs_db($site['host']); csp_tables($db);
    $total = (int)$db->query('SELECT COUNT(*) FROM jobs j WHERE ' . csp_where())->fetchColumn();
    if (!$total) return '';
    $byV = []; foreach ($db->query('SELECT m.versant, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE ' . csp_where() . ' GROUP BY m.versant') as $r) $byV[$r['versant']] = (int)$r['n'];
    $byR = []; foreach ($db->query('SELECT j.region, COUNT(*) n FROM jobs j WHERE ' . csp_where() . ' GROUP BY j.region') as $r) $byR[$r['region']] = (int)$r['n'];
    $nf = fn($n) => number_format($n, 0, ',', ' ');
    $kpi = '<div class="kpi"><div><b>' . $nf($total) . '</b>offres ouvertes</div>';
    foreach (CSP_VERSANTS as $k => [$full]) $kpi .= '<div><b>' . $nf($byV[$k] ?? 0) . '</b><a href="/emploi-public/versant/' . $k . '/">' . h($full) . '</a></div>';
    $kpi .= '</div>';
    $regs = '';
    foreach (JOB_REGIONS as $code => [$slug, $name]) if (!empty($byR[$code])) $regs .= '<div class="card"><div class="in"><h3><a href="/emploi-public/' . $slug . '/">Emploi public ' . h($name) . '</a></h3><p>' . $nf($byR[$code]) . ' offres</p></div></div>';
    $latest = $db->query('SELECT j.* FROM jobs j WHERE ' . csp_where() . ' ORDER BY j.created_at DESC LIMIT 18')->fetchAll();
    $cats = []; foreach ($db->query("SELECT substr(m.categorie,1,11) c, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . " AND m.categorie LIKE 'Catégorie%' GROUP BY c ORDER BY c") as $r) $cats[] = h(trim($r['c'])) . ' : ' . $nf((int)$r['n']);
    $body = '<h1 style="margin-top:28px">Emploi public : offres de la fonction publique</h1>'
        . '<p>' . $nf($total) . ' offres d\'emploi dans les trois fonctions publiques (État, territoriale, hospitalière), ouvertes aux fonctionnaires et, pour beaucoup, aux contractuels sans concours. Mises à jour chaque jour.</p>'
        . $kpi
        . (function_exists('fr_map') ? '<style>' . fr_map_css() . '</style>' . fr_map($byR, '/emploi-public/') : '')
        . '<h2>Offres par catégorie</h2>' . count_chips(array_map(fn($r) => [$r['c'], $r['n'], ''], $db->query("SELECT CASE WHEN m.categorie LIKE 'Catégorie A+%' THEN 'Catégorie A+' WHEN m.categorie LIKE 'Catégorie A%' THEN 'Catégorie A' WHEN m.categorie LIKE 'Catégorie B%' THEN 'Catégorie B' WHEN m.categorie LIKE 'Catégorie C%' THEN 'Catégorie C' ELSE 'Non précisée' END c, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . " GROUP BY c ORDER BY c")->fetchAll()))
        . '<h2>Offres par domaine</h2>' . count_chips(array_map(fn($r) => [$r['domaine'], $r['n'], ''], $db->query("SELECT m.domaine, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . " AND m.domaine<>'' GROUP BY m.domaine ORDER BY n DESC LIMIT 24")->fetchAll()))
        . '<h2>Par type d\'employeur</h2>' . count_chips(array_map(fn($l) => [$l[0], $l[2], $l[1]], csp_type_links($db)))
        . '<h2>Emploi public par département</h2>' . count_chips(array_map(fn($r) => [$r['dep_name'] . ' (' . $r['dep'] . ')', $r['n'], '/emploi-public/departement/' . csp_dep_slug($r['dep_name']) . '/'], $db->query("SELECT m.dep, m.dep_name, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . " AND m.dep_name<>'' GROUP BY m.dep HAVING n>=5 ORDER BY m.dep")->fetchAll()))
        . '<h2>Emploi public par région</h2><div class="grid">' . $regs . '</div>'
        . '<h2>Dernières offres publiées</h2><div class="grid">' . implode('', array_map('job_card', $latest)) . '</div>'
        . '<h2>Travailler dans la fonction publique sans concours</h2><p>De nombreux postes sont ouverts aux <strong>contractuels</strong> : la mention « Emploi ouvert aux titulaires et aux contractuels » figure sur l\'offre. Les catégories indiquent le niveau : <strong>A</strong> (conception, encadrement, bac+3 et plus), <strong>B</strong> (application, bac à bac+2), <strong>C</strong> (exécution, sans diplôme ou CAP/BEP).</p>'
        . csp_disclaimer();
    return layout($site, ['title' => 'Emploi public : ' . $nf($total) . ' offres de la fonction publique | ' . $site['name'],
        'desc' => "$total offres d'emploi public : fonction publique d'État, territoriale et hospitalière, par région. Postes ouverts aux contractuels.",
        'canonical' => 'https://' . $site['host'] . '/emploi-public/', 'schema' => [breadcrumbs($site, [['Emploi public', '/emploi-public/']])]], $body);
}

function page_emploi_public_list(array $site, ?array $reg, string $versant, int $page): string
{
    $db = jobs_db($site['host']); csp_tables($db);
    $where = csp_where(); $args = [];
    if ($reg) { $where .= ' AND j.region=?'; $args[] = $reg['code']; }
    if ($versant !== '') { $where .= ' AND m.versant=?'; $args[] = $versant; }
    $st = $db->prepare("SELECT COUNT(*) FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE $where"); $st->execute($args);
    $total = (int)$st->fetchColumn();
    if (!$total) return '';
    $per = 30;
    $st = $db->prepare("SELECT j.* FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE $where ORDER BY j.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args);
    $jobs = $st->fetchAll();
    if (!$jobs) return '';
    $label = $versant !== '' ? CSP_VERSANTS[$versant][0] : 'Emploi public';
    $title = $label . ($reg ? ' en ' . $reg['name'] : '');
    $base = $reg ? '/emploi-public/' . $reg['slug'] . '/' : '/emploi-public/versant/' . $versant . '/';
    $deps = '';
    if ($reg) {
        $st = $db->prepare("SELECT m.dep_name, m.dep, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE $where GROUP BY m.dep ORDER BY n DESC"); $st->execute($args);
        $deps = implode(', ', array_map(fn($r) => (int)$r['n'] >= 5 ? '<a href="/emploi-public/departement/' . csp_dep_slug((string)$r['dep_name']) . '/">' . h($r['dep_name']) . '</a> (' . (int)$r['n'] . ')' : h($r['dep_name']) . ' (' . (int)$r['n'] . ')', $st->fetchAll()));
    }
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/emploi-public/">Emploi public</a> › ' . h($reg['name'] ?? $label) . '</p>'
        . '<h1>' . h($title) . '</h1><p>' . number_format($total, 0, ',', ' ') . ' offres d\'emploi public ouvertes' . ($reg ? ' en ' . h($reg['name']) . ($deps ? '. Par département : ' . $deps : '') : '') . '.</p>'
        . csp_nav($versant) . '<div class="grid">' . implode('', array_map('job_card', $jobs)) . '</div>' . pager($base, $page, (int)ceil($total / $per))
        . (function_exists('careerjet_slot') && $reg ? careerjet_slot($site, 'fonction publique', $reg['name']) : '') . csp_disclaimer();
    return layout($site, ['title' => $title . ($page > 1 ? " — page $page" : '') . ' : offres d\'emploi | ' . $site['name'],
        'desc' => "$total offres d'emploi public" . ($reg ? ' en ' . $reg['name'] : '') . ($versant ? ' — ' . $label : '') . " : postes de fonctionnaires et de contractuels, mis à jour chaque jour.",
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''),
        'schema' => [breadcrumbs($site, [['Emploi public', '/emploi-public/'], [$reg['name'] ?? $label, $base]])]], $body);
}

// ---------- Emploi public par type d'employeur et par département ----------
const CSP_TYPES = [
    'mairie' => ['Emploi en mairie', "(m.employeur IN ('Communes') OR m.employeur LIKE 'Mairie%' OR m.employeur LIKE 'Ville d%' OR m.employeur LIKE 'Commune d%')", 'Postes dans les communes : services techniques, administratifs, petite enfance, animation, police municipale…'],
    'intercommunalite' => ['Emploi en intercommunalité', "(m.employeur LIKE 'Etablissements publics de coopération intercommunale%' OR m.employeur LIKE 'Communauté d%' OR m.employeur LIKE 'Métropole%')", 'Communautés de communes, d\'agglomération et métropoles : eau, déchets, urbanisme, transports, sport…'],
    'conseil-departemental' => ['Emploi au conseil départemental', "(m.employeur LIKE 'Conseils départementaux%' OR m.employeur LIKE 'Département d%' OR m.employeur LIKE 'Conseil départemental%')", 'Action sociale, routes, collèges, protection de l\'enfance, autonomie…'],
    'conseil-regional' => ['Emploi au conseil régional', "(m.employeur LIKE 'Conseils régionaux%' OR m.employeur LIKE 'Région %' OR m.employeur LIKE 'Conseil régional%')", 'Lycées, transports, formation professionnelle, développement économique…'],
    'ccas-action-sociale' => ['Emploi en CCAS et action sociale', "(m.employeur LIKE 'Centres communaux d%' OR m.employeur LIKE 'CCAS%' OR m.employeur LIKE 'CIAS%')", 'Centres communaux et intercommunaux d\'action sociale : aide à domicile, EHPAD, accueil, insertion…'],
    'hopital' => ['Emploi à l\'hôpital', "(m.versant='hospitaliere' OR m.employeur LIKE '%Hospital%' OR m.employeur LIKE '%Hôpital%' OR m.employeur LIKE 'CHU%' OR m.employeur LIKE '%EHPAD%')", 'Hôpitaux, CHU, EHPAD publics : soins, administration, technique, logistique…'],
    'pompiers-sdis' => ['Emploi chez les pompiers (SDIS)', "(m.employeur LIKE 'Services départementaux d%incendie%' OR m.employeur LIKE 'SDIS%')", 'Services départementaux d\'incendie et de secours : postes administratifs, techniques et opérationnels.'],
    'education-nationale' => ['Emploi dans l\'Éducation nationale', "(m.employeur LIKE 'Rectorat%' OR m.employeur LIKE 'Académie%' OR m.employeur LIKE '%Education%' OR m.employeur LIKE '%Éducation%')", 'Rectorats et académies : enseignement contractuel, administration, vie scolaire, accompagnement des élèves.'],
    'armees-defense' => ['Emploi dans les armées et la Défense', "(m.employeur LIKE 'Armée%' OR m.employeur LIKE '%armées%' OR m.employeur LIKE '%armement%' OR m.employeur LIKE 'Marine%' OR m.employeur LIKE '%Défense%')", 'Postes civils et militaires du ministère des Armées : technique, logistique, administration, santé.'],
    'universite-recherche' => ['Emploi à l\'université et dans la recherche', "(m.employeur LIKE 'Universit%' OR m.employeur IN ('CNRS') OR m.employeur LIKE 'INRAE%' OR m.employeur LIKE 'Institut National de Recherche%' OR m.employeur LIKE 'Inserm%' OR m.employeur LIKE 'INSERM%')", 'Universités et organismes de recherche : ingénieurs, techniciens, administratifs, chercheurs contractuels.'],
];

function csp_dep_slug(string $name): string { return slugify(str_replace(['Côtes d Armor', '  '], ["Côtes-d'Armor", ' '], $name), 60); }

function csp_dep_by_slug(PDO $db, string $slug): ?array
{
    foreach ($db->query("SELECT DISTINCT m.dep, m.dep_name FROM csp_meta m JOIN jobs j ON j.id=m.id WHERE j.status='open' AND m.dep_name<>''") as $r) if (csp_dep_slug($r['dep_name']) === $slug) return $r;
    return null;
}

function page_csp_type(array $site, string $type, string $depSlug, int $page): string
{
    $db = jobs_db($site['host']); csp_tables($db);
    $t = $type !== '' ? (CSP_TYPES[$type] ?? null) : null;
    if ($type !== '' && !$t) return '';
    $dep = $depSlug !== '' ? csp_dep_by_slug($db, $depSlug) : null;
    if ($depSlug !== '' && !$dep) return '';
    $where = csp_where() . ($t ? ' AND ' . $t[1] : '') . ($dep ? ' AND m.dep=' . $db->quote($dep['dep']) : '');
    $total = (int)$db->query("SELECT COUNT(*) FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE $where")->fetchColumn();
    if ($total < 5) return '';
    $per = 30;
    $jobs = $db->query("SELECT j.* FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE $where ORDER BY j.created_at DESC LIMIT $per OFFSET " . (($page - 1) * $per))->fetchAll();
    if (!$jobs) return '';
    $nf = fn($n) => number_format($n, 0, ',', ' ');
    $label = $t ? $t[0] : 'Emploi public';
    $title = $label . ($dep ? ' — ' . $dep['dep_name'] . ' (' . $dep['dep'] . ')' : '');
    $base = $t ? '/emploi-public/type/' . $type . '/' . ($dep ? $depSlug . '/' : '') : '/emploi-public/departement/' . $depSlug . '/';
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/emploi-public/">Emploi public</a> › ' . ($t && $dep ? '<a href="/emploi-public/type/' . $type . '/">' . h($label) . '</a> › ' . h($dep['dep_name']) : h($t ? $label : $dep['dep_name'])) . '</p>'
        . '<h1>' . h($title) . ' : ' . $nf($total) . ' offres</h1><p class="lead">' . h($t ? $t[2] : 'Toutes les offres de la fonction publique d\'État, territoriale et hospitalière dans ce département.') . ' ' . $nf($total) . ' offres ouvertes, mises à jour le ' . h(date_fr(now())) . '.</p>';
    if ($page === 1) {
        // déclinaisons : départements (page type) ou types d'employeur (page département)
        $chips = [];
        if ($t && !$dep) {
            foreach ($db->query("SELECT m.dep, m.dep_name, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . ' AND ' . $t[1] . " AND m.dep_name<>'' GROUP BY m.dep ORDER BY n DESC") as $r)
                if ($r['n'] >= 5) $chips[] = [$r['dep_name'] . ' (' . $r['dep'] . ')', $r['n'], '/emploi-public/type/' . $type . '/' . csp_dep_slug($r['dep_name']) . '/'];
            if ($chips) $body .= '<h2>' . h($label) . ' par département</h2>' . count_chips($chips);
        } elseif ($dep) {
            foreach (CSP_TYPES as $k => $tt) {
                $n = (int)$db->query("SELECT COUNT(*) FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . ' AND ' . $tt[1] . ' AND m.dep=' . $db->quote($dep['dep']))->fetchColumn();
                if ($n >= 5 && $k !== $type) $chips[] = [$tt[0], $n, '/emploi-public/type/' . $k . '/' . $depSlug . '/'];
            }
            if ($chips) $body .= '<h2>Par type d\'employeur en ' . h($dep['dep_name']) . '</h2>' . count_chips($chips);
        }
    }
    $body .= '<div class="grid">' . implode('', array_map('job_card', $jobs)) . '</div>' . pager($base, $page, (int)ceil($total / $per)) . csp_disclaimer();
    return layout($site, ['title' => $title . ' : ' . $nf($total) . ' offres' . ($page > 1 ? " — page $page" : '') . ' | ' . $site['name'],
        'desc' => $nf($total) . ' offres : ' . mb_strtolower($title) . '. ' . ($t ? $t[2] : 'Fonction publique d\'État, territoriale et hospitalière.') . ' Postes ouverts aux contractuels.',
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''),
        'schema' => [breadcrumbs($site, array_values(array_filter([['Emploi public', '/emploi-public/'], $t ? [$label, '/emploi-public/type/' . $type . '/'] : null, $dep ? [$dep['dep_name'], $base] : null])))]], $body);
}

// Liens pour la page /emploi-public/ et le sitemap : [libellé, url, nb]
function csp_type_links(PDO $db, bool $withDeps = false): array
{
    csp_tables($db); $o = [];
    foreach (CSP_TYPES as $k => $t) {
        $n = (int)$db->query("SELECT COUNT(*) FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . ' AND ' . $t[1])->fetchColumn();
        if ($n < 5) continue;
        $o[] = [$t[0], '/emploi-public/type/' . $k . '/', $n];
        if ($withDeps) foreach ($db->query("SELECT m.dep_name, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . ' AND ' . $t[1] . " AND m.dep_name<>'' GROUP BY m.dep HAVING n>=5") as $r) $o[] = ['', '/emploi-public/type/' . $k . '/' . csp_dep_slug($r['dep_name']) . '/', $r['n']];
    }
    if ($withDeps) foreach ($db->query("SELECT m.dep_name, COUNT(*) n FROM jobs j JOIN csp_meta m ON m.id=j.id WHERE " . csp_where() . " AND m.dep_name<>'' GROUP BY m.dep HAVING n>=5") as $r) $o[] = ['', '/emploi-public/departement/' . csp_dep_slug($r['dep_name']) . '/', $r['n']];
    return $o;
}
