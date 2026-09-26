<?php
// Annuaires de lieux issus de données publiques (un module par site, colonne sites.places) :
// salles de spectacle (Basilic, ministère de la Culture), jardins remarquables (ministère de la Culture),
// randonnées (OpenStreetMap, poussées par le VPS via /_api/places). Pages : accueil / département / fiche.
// Dépend de fuel.php (DEPTS, dept_of, dept_slug, dept_by_slug, city_name).
declare(strict_types=1);

const PLACE_MODULES = [
    'theatres' => ['prefix' => 'salles-de-spectacle', 'item' => 'salle', 'title' => 'Théâtres et salles de spectacle', 'one' => 'salle de spectacle', 'many' => 'théâtres et salles de spectacle',
        'nav' => 'Salles de spectacle', 'schema' => 'PerformingArtsTheater', 'src' => 'Base des lieux et équipements culturels (Basilic) du ministère de la Culture, Licence Ouverte Etalab',
        'url' => 'https://static.data.gouv.fr/resources/base-des-lieux-et-equipements-culturels-basilic/20260218-084338/base-des-lieux-et-des-equipements-culturels.csv', 'dataset' => '61777ddaa9101d073e5506cd'],
    'jardins' => ['prefix' => 'jardins-remarquables', 'item' => 'jardin', 'title' => 'Jardins remarquables', 'one' => 'jardin remarquable', 'many' => 'jardins remarquables',
        'nav' => 'Jardins remarquables', 'schema' => 'Park', 'src' => 'Liste des jardins labellisés « Jardin remarquable », ministère de la Culture, Licence Ouverte Etalab',
        'url' => 'https://ministere-culture.s3.sbg.io.cloud.ovh.net/BASE_DES_LIEUX/base_des_lieux_labels_jardins_remarquables.csv'],
    'randos' => ['prefix' => 'randonnees', 'item' => 'randonnee', 'title' => 'Randonnées balisées', 'one' => 'randonnée', 'many' => 'itinéraires de randonnée balisés',
        'nav' => 'Randonnées', 'schema' => 'TouristAttraction', 'src' => 'contributeurs OpenStreetMap (licence ODbL), itinéraires balisés GR, GR de Pays et PR'],
];

function places_mod(array $site): ?array { $k = (string)($site['places'] ?? ''); return isset(PLACE_MODULES[$k]) ? PLACE_MODULES[$k] + ['key' => $k] : null; }

function places_db(string $host): PDO
{
    $db = site_db($host);
    static $init = [];
    if (empty($init[$host])) {
        $db->exec("CREATE TABLE IF NOT EXISTS places(id TEXT PRIMARY KEY, slug TEXT, name TEXT, kind TEXT, address TEXT, cp TEXT, dept TEXT, city TEXT, city_slug TEXT,
            lat REAL, lon REAL, rank INTEGER DEFAULT 0, data TEXT DEFAULT '{}', seen_at TEXT);
        CREATE INDEX IF NOT EXISTS ix_pl_dept ON places(dept, city);
        CREATE INDEX IF NOT EXISTS ix_pl_slug ON places(slug);");
        $init[$host] = 1;
    }
    return $db;
}

// Enregistre des lieux normalisés : id, name, kind, address, cp, city, lat, lon, rank, fields{}, desc, image, credit, links[[libellé,url]].
function places_store(array $site, array $rows, string $stamp): int
{
    $db = places_db($site['host']);
    $st = $db->prepare('INSERT INTO places(id,slug,name,kind,address,cp,dept,city,city_slug,lat,lon,rank,data,seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET name=excluded.name, kind=excluded.kind, address=excluded.address, cp=excluded.cp, dept=excluded.dept, city=excluded.city,
        city_slug=excluded.city_slug, lat=excluded.lat, lon=excluded.lon, rank=excluded.rank, data=excluded.data, seen_at=excluded.seen_at');
    $n = 0;
    $db->beginTransaction();
    foreach ($rows as $r) {
        $cp = (string)($r['cp'] ?? ''); $city = city_name((string)($r['city'] ?? '')); $name = trim((string)($r['name'] ?? ''));
        if (!preg_match('/^\d{5}$/', $cp) || $city === '' || $name === '' || empty($r['id'])) continue;
        $id = (string)$r['id'];
        $data = array_intersect_key($r, array_flip(['fields', 'desc', 'image', 'credit', 'links']));
        $st->execute([$id, slugify($name . ' ' . $city, 70) . '-' . substr(md5($id), 0, 5), mb_substr($name, 0, 150), mb_substr((string)($r['kind'] ?? ''), 0, 80), mb_substr((string)($r['address'] ?? ''), 0, 160),
            $cp, dept_of($cp), $city, slugify($city, 60), (float)($r['lat'] ?? 0), (float)($r['lon'] ?? 0), (int)($r['rank'] ?? 0), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $stamp]);
        $n++;
    }
    $db->commit();
    return $n;
}

function places_finish(array $site, string $stamp): int
{
    $st = places_db($site['host'])->prepare('DELETE FROM places WHERE seen_at<>?');
    $st->execute([$stamp]);
    cache_clear($site['host']);
    return $st->rowCount();
}

function places_csv(string $url, string $sep): Generator
{
    $file = cfg('data_dir') . '/places_src.csv';
    $fh = fopen($file, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 600, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch); fclose($fh);
    if (!$ok || $code !== 200 || filesize($file) < 10000) throw new RuntimeException("téléchargement $url : HTTP $code");
    $in = fopen($file, 'r');
    $head = array_map(fn($x) => trim(preg_replace('/^\xEF\xBB\xBF/', '', $x)), fgetcsv($in, 0, $sep, '"', ''));
    while (($r = fgetcsv($in, 0, $sep, '"', '')) !== false) yield array_combine($head, array_pad(array_slice($r, 0, count($head)), count($head), ''));
    fclose($in); @unlink($file);
}

// Synchronisation locale (sources accessibles depuis le serveur). Les randonnées arrivent par l'API.
function places_sync(array $site): array
{
    $m = places_mod($site);
    if (!$m || empty($m['url'])) return ['info' => 'module alimenté par l\'API'];
    $stamp = gmdate('Y-m-d H:i:s'); $rows = []; $n = 0;
    $flush = function () use (&$rows, &$n, $site, $stamp) { $n += places_store($site, $rows, $stamp); $rows = []; usleep(200000); };
    if ($m['key'] === 'theatres') {
        foreach (places_csv($m['url'], ';') as $r) {
            if (!in_array($r['Type équipement ou lieu'], ['Théâtre', 'Scène', 'Opéra'], true) || ($r['Demographie_AP'] ?? 'Actif') !== 'Actif') continue;
            $label = trim($r['Label et appellation']);
            $rows[] = ['id' => $r['Identifiant_deps_a_partir_de_2022'] ?: md5($r['Nom'] . $r['code_insee']), 'name' => $r['Nom'], 'kind' => $r['Type équipement ou lieu'] === 'Scène' ? ($label ?: 'Scène') : $r['Type équipement ou lieu'],
                'address' => $r['Adresse'], 'cp' => $r['Code Postal'], 'city' => $r['libelle_geographique'], 'lat' => $r['Latitude'], 'lon' => $r['Longitude'],
                'rank' => ($label ? 50 : 0) + min(40, (int)$r['Jauge_du_theatre'] / 25),
                'fields' => array_filter(['Type' => $r['Type équipement ou lieu'], 'Label' => $label, 'Disciplines' => $r['Sous_domaine'], 'Jauge' => $r['Jauge_du_theatre'] ? (int)$r['Jauge_du_theatre'] . ' places' : '',
                    'Nombre de salles' => $r['Nombre_de_salles_de_theatre'] ? (string)(int)$r['Nombre_de_salles_de_theatre'] : '', 'Structure' => $r['Organisme_Siege_du_theatre'], 'Intercommunalité' => $r['Libelle_EPCI']])];
            if (count($rows) >= 500) $flush();
        }
    } elseif ($m['key'] === 'jardins') {
        foreach (places_csv($m['url'], ';') as $r) {
            $j = fn($k) => array_values(array_filter((array)(json_decode((string)$r[$k], true) ?: [])));
            $desc = trim(strip_tags(html_entity_decode(implode("\n\n", $j('description')), ENT_QUOTES)), " \n,");
            $img = $j('images')[0] ?? '';
            $rows[] = ['id' => $r['id'], 'name' => $r['nom_du_jardin'], 'kind' => implode(', ', array_diff($j('types'), ['Privé', 'Public'])) ?: 'Jardin', 'address' => $r['numero_et_libelle_voie'] ?: $r['adresse_complete'],
                'cp' => $r['code_postal'], 'city' => $r['commune'], 'lat' => $r['latitude'], 'lon' => $r['longitude'], 'rank' => ($desc ? 40 : 0) + ($img ? 30 : 0),
                'fields' => array_filter(['Type' => implode(', ', $j('types')), 'Label obtenu en' => $r['annee_obtention'], 'Ouvert au public' => $r['accessible_au_public'], 'Ouverture' => $r['conditions_ouverture'],
                    'Téléphone' => implode(', ', $j('telephones'))]),
                'desc' => mb_substr($desc, 0, 4000), 'image' => $img, 'credit' => implode(' ', $j('mentions_legales')),
                'links' => array_map(fn($u) => ['Site internet', $u], array_slice($j('site_internet_et_autres_liens'), 0, 2))];
            if (count($rows) >= 500) $flush();
        }
    }
    $flush();
    return ['lieux' => $n, 'supprimes' => $n > 50 ? places_finish($site, $stamp) : 0];
}

// ---------- Rendu ----------

function place_url(array $m, array $p): string { return '/' . $m['item'] . '/' . $p['slug'] . '/'; }
function places_footer(array $m): string { return '<p class="disc">Source : ' . h($m['src']) . '. Informations publiques mises à jour automatiquement : vérifiez horaires et conditions d\'accès avant votre visite.</p>'; }

function places_guides(array $site, int $n = 6): string
{
    $g = site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT $n")->fetchAll();
    return $g ? '<h2>Nos guides</h2><ul>' . implode('', array_map(fn($p) => '<li><a href="' . h($p['path']) . '">' . h($p['title']) . '</a></li>', $g)) . '</ul>' : '';
}

function place_cards(array $m, array $rows): string
{
    return '<div class="grid">' . implode('', array_map(function ($p) use ($m) {
        $d = json_decode((string)$p['data'], true) ?: [];
        return '<div class="card">' . (!empty($d['image']) ? '<a href="' . h(place_url($m, $p)) . '"><img src="' . h($d['image']) . '" alt="' . h($p['name']) . '" loading="lazy" style="width:100%;height:160px;object-fit:cover"></a>' : '')
            . '<div class="in"><a href="' . h(place_url($m, $p)) . '"><strong>' . h($p['name']) . '</strong></a><p><small>' . h($p['kind']) . ' · ' . h($p['city']) . ' (' . h($p['dept']) . ')</small></p></div></div>';
    }, $rows)) . '</div>';
}

function page_places_home(array $site): string
{
    $m = places_mod($site);
    $db = places_db($site['host']);
    $total = (int)$db->query('SELECT COUNT(*) FROM places')->fetchColumn();
    if (!$total) return '';
    $depts = $db->query('SELECT dept, COUNT(*) n FROM places GROUP BY dept ORDER BY dept')->fetchAll(PDO::FETCH_KEY_PAIR);
    $top = $db->query('SELECT * FROM places ORDER BY rank DESC, name LIMIT 12')->fetchAll();
    $base = '/' . $m['prefix'] . '/';
    $body = '<h1 style="margin-top:28px">' . h($m['title']) . ' : ' . number_format($total, 0, ',', ' ') . ' adresses par département</h1>'
        . '<p class="lead">L\'annuaire des ' . h($m['many']) . ' : ' . number_format($total, 0, ',', ' ') . ' fiches dans ' . count($depts) . ' départements, avec adresse, informations pratiques et carte.</p>'
        . '<h2>À découvrir</h2>' . place_cards($m, $top)
        . '<h2>Par département</h2><p>' . implode(' · ', array_map(fn($d, $n) => '<a href="' . $base . h(dept_slug((string)$d)) . '/">' . h((string)(DEPTS[$d] ?? $d)) . '</a> (' . $n . ')', array_keys($depts), $depts)) . '</p>'
        . places_guides($site) . places_footer($m);
    return layout($site, ['title' => $m['title'] . ' : annuaire par département | ' . $site['name'],
        'desc' => 'Annuaire des ' . $m['many'] . ' : ' . $total . ' adresses classées par département et par ville, avec informations pratiques et carte.',
        'canonical' => 'https://' . $site['host'] . $base, 'schema' => [breadcrumbs($site, [[$m['title'], $base]])]], $body);
}

function page_places_dept(array $site, string $dept): string
{
    $m = places_mod($site);
    $db = places_db($site['host']);
    $st = $db->prepare('SELECT * FROM places WHERE dept=? ORDER BY city, rank DESC, name'); $st->execute([$dept]);
    $rows = $st->fetchAll();
    if (!$rows) return '';
    $name = DEPTS[$dept] ?? $dept; $base = '/' . $m['prefix'] . '/'; $url = $base . dept_slug($dept) . '/';
    $byCity = [];
    foreach ($rows as $p) $byCity[$p['city']][] = $p;
    $top = $rows; usort($top, fn($a, $b) => $b['rank'] <=> $a['rank']);
    $body = '<p class="crumbs" style="margin-top:24px"><a href="' . $base . '">' . h($m['title']) . '</a> › ' . h($name) . '</p>'
        . '<h1>' . h(ucfirst($m['many'])) . ' dans le département ' . h($name) . ' (' . h($dept) . ')</h1>'
        . '<p class="lead">' . count($rows) . ' ' . h(count($rows) > 1 ? $m['many'] : $m['one']) . ' dans ' . count($byCity) . ' commune(s) du département ' . h($name) . '.</p>'
        . (count($rows) > 6 ? '<h2>Incontournables</h2>' . place_cards($m, array_slice($top, 0, 6)) : '')
        . '<h2>Toutes les adresses par commune</h2>';
    foreach ($byCity as $city => $ps) $body .= '<h3>' . h($city) . '</h3><ul>' . implode('', array_map(fn($p) => '<li><a href="' . h(place_url($m, $p)) . '">' . h($p['name']) . '</a> <small>— ' . h($p['kind']) . '</small></li>', $ps)) . '</ul>';
    $body .= places_guides($site, 4) . places_footer($m);
    $list = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => array_map(fn($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => 'https://' . $site['host'] . place_url($m, $p), 'name' => $p['name']], array_slice($top, 0, 20), array_keys(array_slice($top, 0, 20)))];
    return layout($site, ['title' => ucfirst($m['many']) . ' ' . $name . ' (' . $dept . ') : ' . count($rows) . ' adresses | ' . $site['name'],
        'desc' => 'Les ' . count($rows) . ' ' . $m['many'] . ' du département ' . $name . ' (' . $dept . ') : adresses, informations pratiques et carte, commune par commune.',
        'canonical' => 'https://' . $site['host'] . $url, 'schema' => [breadcrumbs($site, [[$m['title'], $base], [$name, $url]]), $list]], $body);
}

function page_place(array $site, string $slug): string
{
    $m = places_mod($site);
    $db = places_db($site['host']);
    $st = $db->prepare('SELECT * FROM places WHERE slug=?'); $st->execute([$slug]);
    $p = $st->fetch();
    if (!$p) return '';
    $d = json_decode((string)$p['data'], true) ?: [];
    $dname = DEPTS[$p['dept']] ?? $p['dept']; $base = '/' . $m['prefix'] . '/'; $durl = $base . dept_slug($p['dept']) . '/';
    $fields = ['Adresse' => trim($p['address'] . ', ' . $p['cp'] . ' ' . $p['city'], ', ')] + (array)($d['fields'] ?? []);
    // lieux proches (±0,3° ≈ 25 km), triés par distance approximative
    $near = [];
    if ($p['lat']) {
        $q = $db->prepare('SELECT *, ((lat-?)*(lat-?)+(lon-?)*(lon-?)) dist FROM places WHERE id<>? AND lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? ORDER BY dist LIMIT 8');
        $q->execute([$p['lat'], $p['lat'], $p['lon'], $p['lon'], $p['id'], $p['lat'] - .3, $p['lat'] + .3, $p['lon'] - .45, $p['lon'] + .45]);
        $near = $q->fetchAll();
    }
    $osm = 'https://www.openstreetmap.org/?mlat=' . $p['lat'] . '&mlon=' . $p['lon'] . '#map=15/' . $p['lat'] . '/' . $p['lon'];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="' . $base . '">' . h($m['title']) . '</a> › <a href="' . $durl . '">' . h($dname) . '</a> › ' . h($p['city']) . '</p>'
        . '<h1>' . h($p['name']) . ' (' . h($p['city']) . ')</h1>'
        . (!empty($d['image']) ? '<figure><img src="' . h($d['image']) . '" alt="' . h($p['name']) . '" style="width:100%;max-height:420px;object-fit:cover;border-radius:12px">' . (!empty($d['credit']) ? '<figcaption><small>' . h(mb_strimwidth($d['credit'], 0, 160, '…')) . '</small></figcaption>' : '') . '</figure>' : '')
        . '<p class="lead">' . h(ucfirst($m['one'])) . ' à ' . h($p['city']) . ' (' . h($dname) . ')' . ($p['kind'] ? ' — ' . h($p['kind']) : '') . '.</p>'
        . '<table><tbody>' . implode('', array_map(fn($k, $v) => '<tr><th style="text-align:left">' . h($k) . '</th><td>' . h((string)$v) . '</td></tr>', array_keys($fields), $fields)) . '</tbody></table>'
        . (!empty($d['desc']) ? '<h2>Présentation</h2>' . implode('', array_map(fn($x) => '<p>' . h(trim($x)) . '</p>', array_filter(preg_split('/\n{2,}/', (string)$d['desc'])))) : '')
        . '<p>' . ($p['lat'] ? '<a href="' . h($osm) . '" rel="nofollow noopener" target="_blank">Voir sur la carte</a>' : '')
        . implode('', array_map(fn($l) => ' · <a href="' . h($l[1]) . '" rel="nofollow noopener" target="_blank">' . h($l[0]) . '</a>', (array)($d['links'] ?? []))) . '</p>'
        . ($near ? '<h2>À proximité</h2><ul>' . implode('', array_map(fn($x) => '<li><a href="' . h(place_url($m, $x)) . '">' . h($x['name']) . '</a> <small>— ' . h($x['city']) . '</small></li>', $near)) . '</ul>' : '')
        . places_guides($site, 4) . places_footer($m);
    $schema = ['@context' => 'https://schema.org', '@type' => $m['schema'], 'name' => $p['name'],
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $p['address'], 'postalCode' => $p['cp'], 'addressLocality' => $p['city'], 'addressCountry' => 'FR']]
        + ($p['lat'] ? ['geo' => ['@type' => 'GeoCoordinates', 'latitude' => $p['lat'], 'longitude' => $p['lon']]] : []) + (!empty($d['image']) ? ['image' => $d['image']] : [])
        + (!empty($d['desc']) ? ['description' => mb_strimwidth((string)$d['desc'], 0, 300, '…')] : []);
    return layout($site, ['title' => $p['name'] . ' à ' . $p['city'] . ' : ' . $m['one'] . ' (' . $p['dept'] . ') | ' . $site['name'],
        'desc' => mb_strimwidth($p['name'] . ', ' . $m['one'] . ' à ' . $p['city'] . ' (' . $dname . ') : ' . ($d['desc'] ?? implode(', ', array_map(fn($k, $v) => "$k : $v", array_keys($fields), $fields))), 0, 158, '…'),
        'canonical' => 'https://' . $site['host'] . place_url($m, $p), 'image' => $d['image'] ?? null,
        'schema' => [$schema, breadcrumbs($site, [[$m['title'], $base], [$dname, $durl], [$p['name'], place_url($m, $p)]])]], $body);
}

function places_home_block(array $site): string
{
    $m = places_mod($site);
    $db = places_db($site['host']);
    $top = $db->query('SELECT * FROM places ORDER BY rank DESC, RANDOM() LIMIT 6')->fetchAll();
    if (!$top) return '';
    return '<h2 style="margin-top:28px">' . h($m['title']) . '</h2>' . place_cards($m, $top) . '<p><a class="kick" href="/' . $m['prefix'] . '/">Tout l\'annuaire par département →</a></p>';
}

function out_sitemap_places(array $site): void
{
    $m = places_mod($site);
    $db = places_db($site['host']);
    $day = substr((string)$db->query('SELECT MAX(seen_at) FROM places')->fetchColumn(), 0, 10) ?: gmdate('Y-m-d');
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<url><loc>https://' . $site['host'] . '/' . $m['prefix'] . '/</loc><lastmod>' . $day . '</lastmod></url>';
    foreach ($db->query('SELECT DISTINCT dept FROM places') as $r) if (isset(DEPTS[$r['dept']])) echo '<url><loc>https://' . $site['host'] . '/' . $m['prefix'] . '/' . dept_slug((string)$r['dept']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    foreach ($db->query('SELECT slug FROM places LIMIT 45000') as $r) echo '<url><loc>https://' . $site['host'] . '/' . $m['item'] . '/' . h($r['slug']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    echo '</urlset>';
}
