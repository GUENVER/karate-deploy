<?php
// Prix des carburants (open data officiel prix-carburants.gouv.fr, Licence Ouverte Etalab) :
// synchronisation du flux instantané, pages France / département / ville / station, historique des moyennes.
declare(strict_types=1);

const FUELS = ['Gazole' => 'Gazole', 'E10' => 'SP95-E10', 'SP95' => 'SP95', 'SP98' => 'SP98', 'E85' => 'E85 (superéthanol)', 'GPLc' => 'GPL'];
const FUEL_FRESH_DAYS = 10; // un prix plus ancien est affiché mais exclu des moyennes

const DEPTS = ['01' => 'Ain', '02' => 'Aisne', '03' => 'Allier', '04' => 'Alpes-de-Haute-Provence', '05' => 'Hautes-Alpes', '06' => 'Alpes-Maritimes', '07' => 'Ardèche', '08' => 'Ardennes',
    '09' => 'Ariège', '10' => 'Aube', '11' => 'Aude', '12' => 'Aveyron', '13' => 'Bouches-du-Rhône', '14' => 'Calvados', '15' => 'Cantal', '16' => 'Charente', '17' => 'Charente-Maritime',
    '18' => 'Cher', '19' => 'Corrèze', '2A' => 'Corse-du-Sud', '2B' => 'Haute-Corse', '21' => "Côte-d'Or", '22' => "Côtes-d'Armor", '23' => 'Creuse', '24' => 'Dordogne', '25' => 'Doubs',
    '26' => 'Drôme', '27' => 'Eure', '28' => 'Eure-et-Loir', '29' => 'Finistère', '30' => 'Gard', '31' => 'Haute-Garonne', '32' => 'Gers', '33' => 'Gironde', '34' => 'Hérault',
    '35' => 'Ille-et-Vilaine', '36' => 'Indre', '37' => 'Indre-et-Loire', '38' => 'Isère', '39' => 'Jura', '40' => 'Landes', '41' => 'Loir-et-Cher', '42' => 'Loire', '43' => 'Haute-Loire',
    '44' => 'Loire-Atlantique', '45' => 'Loiret', '46' => 'Lot', '47' => 'Lot-et-Garonne', '48' => 'Lozère', '49' => 'Maine-et-Loire', '50' => 'Manche', '51' => 'Marne', '52' => 'Haute-Marne',
    '53' => 'Mayenne', '54' => 'Meurthe-et-Moselle', '55' => 'Meuse', '56' => 'Morbihan', '57' => 'Moselle', '58' => 'Nièvre', '59' => 'Nord', '60' => 'Oise', '61' => 'Orne',
    '62' => 'Pas-de-Calais', '63' => 'Puy-de-Dôme', '64' => 'Pyrénées-Atlantiques', '65' => 'Hautes-Pyrénées', '66' => 'Pyrénées-Orientales', '67' => 'Bas-Rhin', '68' => 'Haut-Rhin',
    '69' => 'Rhône', '70' => 'Haute-Saône', '71' => 'Saône-et-Loire', '72' => 'Sarthe', '73' => 'Savoie', '74' => 'Haute-Savoie', '75' => 'Paris', '76' => 'Seine-Maritime',
    '77' => 'Seine-et-Marne', '78' => 'Yvelines', '79' => 'Deux-Sèvres', '80' => 'Somme', '81' => 'Tarn', '82' => 'Tarn-et-Garonne', '83' => 'Var', '84' => 'Vaucluse', '85' => 'Vendée',
    '86' => 'Vienne', '87' => 'Haute-Vienne', '88' => 'Vosges', '89' => 'Yonne', '90' => 'Territoire de Belfort', '91' => 'Essonne', '92' => 'Hauts-de-Seine', '93' => 'Seine-Saint-Denis',
    '94' => 'Val-de-Marne', '95' => "Val-d'Oise", '971' => 'Guadeloupe', '972' => 'Martinique', '973' => 'Guyane', '974' => 'La Réunion', '976' => 'Mayotte'];

function fuel_db(string $host): PDO
{
    $db = site_db($host);
    static $init = [];
    if (empty($init[$host])) {
        $db->exec("CREATE TABLE IF NOT EXISTS stations(id TEXT PRIMARY KEY, cp TEXT, dept TEXT, city TEXT, city_slug TEXT, address TEXT, lat REAL, lon REAL,
            pop TEXT, services TEXT DEFAULT '[]', h24 INTEGER DEFAULT 0, slug TEXT, seen_at TEXT);
        CREATE INDEX IF NOT EXISTS ix_st_city ON stations(dept, city_slug);
        CREATE TABLE IF NOT EXISTS fuel_prices(station_id TEXT, fuel TEXT, price REAL, maj TEXT, PRIMARY KEY(station_id, fuel));
        CREATE INDEX IF NOT EXISTS ix_fp_fuel ON fuel_prices(fuel, price);
        CREATE TABLE IF NOT EXISTS fuel_history(day TEXT, scope TEXT, fuel TEXT, avg REAL, min REAL, n INTEGER, PRIMARY KEY(day, scope, fuel));");
        $init[$host] = 1;
    }
    return $db;
}

function dept_of(string $cp): string
{
    if (str_starts_with($cp, '97')) return substr($cp, 0, 3);
    if (str_starts_with($cp, '20')) return ((int)substr($cp, 0, 3) < 202) ? '2A' : '2B';
    return substr($cp, 0, 2);
}
function dept_slug(string $code): string { return slugify((DEPTS[$code] ?? $code) . '-' . strtolower($code), 60); }
function dept_by_slug(string $slug): ?string { foreach (DEPTS as $c => $n) if (dept_slug((string)$c) === $slug) return (string)$c; return null; }
function city_name(string $raw): string
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    if ($raw === mb_strtoupper($raw)) $raw = mb_convert_case(mb_strtolower($raw), MB_CASE_TITLE);
    return preg_replace_callback("/\b(Le|La|Les|De|Du|Des|Sur|Sous|En|Et|L'|D')\b/u", fn($m) => mb_strtolower($m[1]), $raw) ?: $raw;
}
function eur(float $v, int $d = 3): string { return number_format($v, $d, ',', ' ') . ' €'; }

// ---------- Synchronisation ----------

function fuel_sync(array $site, callable $log): array
{
    $db = fuel_db($site['host']);
    $zip = cfg('data_dir') . '/fuel_instantane.zip';
    $ch = curl_init((string)cfg('fuel_url', 'https://donnees.roulez-eco.fr/opendata/instantane'));
    $fh = fopen($zip, 'w');
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'Mozilla/5.0 (fabrique)']);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch); fclose($fh);
    if (!in_array($code, [0, 200], true) || filesize($zip) < 200) return ["error" => "téléchargement HTTP $code"]; // 0 = file:// (tests)

    $x = new XMLReader();
    if (!$x->open('zip://' . $zip . '#PrixCarburants_instantane.xml')) return ['error' => 'zip illisible'];
    $now = now(); $n = 0;
    $stU = $db->prepare('INSERT INTO stations(id,cp,dept,city,city_slug,address,lat,lon,pop,services,h24,slug,seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET cp=excluded.cp, dept=excluded.dept, city=excluded.city, city_slug=excluded.city_slug, address=excluded.address, lat=excluded.lat, lon=excluded.lon,
        pop=excluded.pop, services=excluded.services, h24=excluded.h24, slug=excluded.slug, seen_at=excluded.seen_at');
    $del = $db->prepare('DELETE FROM fuel_prices WHERE station_id=?');
    $pU = $db->prepare('INSERT OR REPLACE INTO fuel_prices(station_id,fuel,price,maj) VALUES(?,?,?,?)');
    $db->beginTransaction();
    while ($x->read()) {
        if ($x->nodeType !== XMLReader::ELEMENT || $x->name !== 'pdv') continue;
        $el = @simplexml_load_string($x->readOuterXml());
        if (!$el) continue;
        $cp = (string)$el['cp'];
        $city = city_name((string)$el->ville);
        $addr = trim(preg_replace('/\s+/', ' ', (string)$el->adresse));
        if ($cp === '' || $city === '') continue;
        $id = (string)$el['id'];
        $services = [];
        foreach ($el->services->service ?? [] as $s) $services[] = (string)$s;
        $dept = dept_of($cp);
        $stU->execute([$id, $cp, $dept, $city, slugify($city, 60), $addr, (float)$el['latitude'] / 100000, (float)$el['longitude'] / 100000, (string)$el['pop'],
            json_encode($services, JSON_UNESCAPED_UNICODE), isset($el->horaires['automate-24-24']) && (string)$el->horaires['automate-24-24'] === '1' ? 1 : 0,
            slugify('station ' . $addr . ' ' . $city, 70) . '-' . $id, $now]);
        $del->execute([$id]);
        foreach ($el->prix ?? [] as $p) {
            $f = (string)$p['nom'];
            if (!isset(FUELS[$f]) || (float)$p['valeur'] <= 0) continue;
            $v = (float)$p['valeur'];
            if ($v > 50) $v /= 1000; // anciens flux en millièmes d'euro
            $pU->execute([$id, $f, $v, (string)$p['maj']]);
        }
        $n++;
    }
    $x->close();
    $db->commit();
    $db->exec("DELETE FROM fuel_prices WHERE station_id IN (SELECT id FROM stations WHERE seen_at < datetime('now','-3 days'))");

    // moyennes du jour (France + départements), prix frais uniquement
    $day = gmdate('Y-m-d');
    $fresh = gmdate('Y-m-d H:i:s', time() - FUEL_FRESH_DAYS * 86400);
    $h = $db->prepare('INSERT OR REPLACE INTO fuel_history(day,scope,fuel,avg,min,n) VALUES(?,?,?,?,?,?)');
    $db->beginTransaction();
    foreach ($db->query("SELECT fuel, AVG(price) a, MIN(price) m, COUNT(*) n FROM fuel_prices WHERE maj >= '$fresh' GROUP BY fuel") as $r) $h->execute([$day, 'FR', $r['fuel'], $r['a'], $r['m'], $r['n']]);
    foreach ($db->query("SELECT s.dept, p.fuel, AVG(p.price) a, MIN(p.price) m, COUNT(*) n FROM fuel_prices p JOIN stations s ON s.id=p.station_id WHERE p.maj >= '$fresh' GROUP BY s.dept, p.fuel") as $r)
        $h->execute([$day, $r['dept'], $r['fuel'], $r['a'], $r['m'], $r['n']]);
    $db->commit();
    @unlink($zip);
    cache_clear($site['host']);
    $log("$n stations");
    return ['stations' => $n];
}

// ---------- Blocs de rendu ----------

function fuel_hist(PDO $db, string $scope, string $fuel, int $days = 60): array
{
    $st = $db->prepare("SELECT day, avg FROM fuel_history WHERE scope=? AND fuel=? AND day >= date('now', ?) ORDER BY day");
    $st->execute([$scope, $fuel, '-' . $days . ' days']);
    return $st->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Mini-courbe SVG (sans JavaScript) de l'évolution d'une moyenne.
function sparkline(array $pts, int $w = 160, int $h = 36): string
{
    if (count($pts) < 2) return '<small>historique en construction</small>';
    $v = array_values($pts); $mn = min($v); $mx = max($v); $rg = max(0.001, $mx - $mn); $n = count($v) - 1;
    $path = implode(' ', array_map(fn($i) => round($i * $w / $n, 1) . ',' . round($h - 3 - ($v[$i] - $mn) / $rg * ($h - 6), 1), array_keys($v)));
    return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="évolution"><polyline fill="none" stroke="var(--c)" stroke-width="2" points="' . $path . '"/></svg>';
}

function trend(array $pts): string
{
    if (count($pts) < 2) return '';
    $v = array_values($pts); $last = end($v); $ref = $v[max(0, count($v) - 8)];
    $d = $last - $ref;
    if (abs($d) < 0.0005) return '<small>stable sur 7 j</small>';
    return '<small style="color:' . ($d > 0 ? '#b32d2e' : '#0b6e4f') . '">' . ($d > 0 ? '▲ +' : '▼ ') . number_format($d * 100, 1, ',', ' ') . ' cts sur 7 j</small>';
}

function fuel_table(PDO $db, string $scope, string $title): string
{
    $st = $db->prepare("SELECT fuel, avg, min, n FROM fuel_history WHERE scope=? AND day=(SELECT MAX(day) FROM fuel_history WHERE scope=?)");
    $st->execute([$scope, $scope]);
    $rows = $st->fetchAll(PDO::FETCH_UNIQUE);
    if (!$rows) return '';
    $o = '<table><thead><tr><th>' . h($title) . '</th><th>Prix moyen</th><th>Moins cher</th><th>Évolution (60 j)</th><th>Stations</th></tr></thead><tbody>';
    foreach (FUELS as $k => $label) {
        if (empty($rows[$k])) continue;
        $hist = fuel_hist($db, $scope, $k);
        $o .= '<tr><td><strong>' . h($label) . '</strong></td><td>' . eur((float)$rows[$k]['avg']) . '</td><td>' . eur((float)$rows[$k]['min']) . '</td><td>' . sparkline($hist) . '<br>' . trend($hist) . '</td><td>' . (int)$rows[$k]['n'] . '</td></tr>';
    }
    return $o . '</tbody></table>';
}

function station_label(array $s): string { return $s['address'] . ', ' . $s['city']; }
function station_url(array $s): string { return '/station/' . $s['slug'] . '/'; }

function cheapest_list(PDO $db, string $fuel, string $where, array $args, int $limit = 10): string
{
    $fresh = gmdate('Y-m-d H:i:s', time() - FUEL_FRESH_DAYS * 86400);
    $st = $db->prepare("SELECT s.*, p.price, p.maj FROM fuel_prices p JOIN stations s ON s.id=p.station_id WHERE p.fuel=? AND p.maj>=? $where ORDER BY p.price LIMIT $limit");
    $st->execute(array_merge([$fuel, $fresh], $args));
    $rows = $st->fetchAll();
    if (!$rows) return '';
    return '<ol>' . implode('', array_map(fn($r) => '<li><a href="' . h(station_url($r)) . '">' . h(station_label($r)) . '</a> — <strong>' . eur((float)$r['price']) . '</strong> <small>(màj ' . h(date_fr($r['maj'])) . ')</small></li>', $rows)) . '</ol>';
}

function fuel_footer(): string
{
    return '<p class="disc">Prix issus du flux officiel du ministère de l\'Économie (prix-carburants.gouv.fr, Licence Ouverte Etalab), actualisés toutes les heures. Les stations déclarent elles-mêmes leurs prix : vérifiez à la pompe.</p>';
}

function fuel_guides(array $site, int $n = 6): string
{
    $g = site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT $n")->fetchAll();
    return $g ? '<h2>Nos guides pour dépenser moins en carburant</h2><ul>' . implode('', array_map(fn($p) => '<li><a href="' . h($p['path']) . '">' . h($p['title']) . '</a></li>', $g)) . '</ul>' : '';
}

// ---------- Pages ----------

function page_fuel_france(array $site): string
{
    $db = fuel_db($site['host']);
    $body = '<h1 style="margin-top:28px">Prix des carburants aujourd\'hui en France</h1>'
        . '<p>Prix moyens et stations les moins chères, mis à jour toutes les heures à partir des données officielles de ' . number_format((int)$db->query('SELECT COUNT(*) FROM stations')->fetchColumn(), 0, ',', ' ') . ' stations-service.</p>'
        . fuel_table($db, 'FR', 'Carburant (France)');
    foreach (['Gazole', 'E10'] as $f) $body .= '<h2>' . h(FUELS[$f]) . ' : les 10 stations les moins chères de France</h2>' . cheapest_list($db, $f, '', []);
    $rows = $db->query("SELECT scope, avg FROM fuel_history WHERE fuel='Gazole' AND scope<>'FR' AND day=(SELECT MAX(day) FROM fuel_history) ORDER BY avg")->fetchAll(PDO::FETCH_KEY_PAIR);
    $body .= '<h2>Prix du gazole par département</h2><div class="grid">' . implode('', array_map(fn($c, $a) => '<div class="card"><div class="in"><a href="/prix-carburant/' . h(dept_slug((string)$c)) . '/"><strong>' . h(DEPTS[$c] ?? $c) . ' (' . h((string)$c) . ')</strong></a><p>Gazole moyen : ' . eur((float)$a) . '</p></div></div>', array_keys($rows), $rows)) . '</div>'
        . fuel_guides($site) . fuel_footer();
    return layout($site, ['title' => 'Prix carburant aujourd\'hui : gazole, SP95-E10, SP98, E85 | ' . $site['name'], 'desc' => 'Prix moyen du gazole, du SP95-E10, du SP98, de l\'E85 et du GPL en France aujourd\'hui, et stations les moins chères par département et par ville.',
        'canonical' => 'https://' . $site['host'] . '/prix-carburant/', 'schema' => [breadcrumbs($site, [['Prix carburant', '/prix-carburant/']])]], $body);
}

function page_fuel_dept(array $site, string $dept): string
{
    $db = fuel_db($site['host']);
    $name = DEPTS[$dept] ?? $dept;
    $st = $db->prepare('SELECT city, city_slug, COUNT(*) n FROM stations WHERE dept=? GROUP BY city_slug ORDER BY n DESC, city');
    $st->execute([$dept]);
    $cities = $st->fetchAll();
    if (!$cities) return '';
    $base = '/prix-carburant/' . dept_slug($dept) . '/';
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/prix-carburant/">Prix carburant</a> › ' . h($name) . '</p><h1>Prix des carburants dans le département ' . h($name) . ' (' . h($dept) . ')</h1>'
        . '<p>Prix moyens du jour dans ' . count($cities) . ' communes du département, comparés à la moyenne nationale.</p>'
        . fuel_table($db, $dept, 'Carburant (' . $name . ')');
    foreach (FUELS as $f => $label) { $l = cheapest_list($db, $f, 'AND s.dept=?', [$dept], 5); if ($l) $body .= '<h2>' . h($label) . ' le moins cher : ' . h($name) . '</h2>' . $l; }
    $body .= '<h2>Prix des carburants par commune</h2><p>' . implode(' · ', array_map(fn($c) => '<a href="' . $base . h($c['city_slug']) . '/">' . h($c['city']) . '</a> (' . $c['n'] . ')', $cities)) . '</p>' . fuel_guides($site) . fuel_footer();
    return layout($site, ['title' => 'Prix carburant ' . $name . ' (' . $dept . ') : stations les moins chères | ' . $site['name'],
        'desc' => "Prix du gazole, SP95-E10, SP98, E85 et GPL aujourd'hui dans le département $name ($dept) : moyennes et stations les moins chères, mis à jour toutes les heures.",
        'canonical' => 'https://' . $site['host'] . $base, 'schema' => [breadcrumbs($site, [['Prix carburant', '/prix-carburant/'], [$name, $base]])]], $body);
}

function page_fuel_city(array $site, string $dept, string $citySlug): string
{
    $db = fuel_db($site['host']);
    $st = $db->prepare('SELECT * FROM stations WHERE dept=? AND city_slug=? ORDER BY address');
    $st->execute([$dept, $citySlug]);
    $stations = $st->fetchAll();
    if (!$stations) return '';
    $city = $stations[0]['city']; $dname = DEPTS[$dept] ?? $dept;
    $ids = array_column($stations, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pr = [];
    $q = $db->prepare("SELECT station_id, fuel, price, maj FROM fuel_prices WHERE station_id IN ($in)"); $q->execute($ids);
    foreach ($q as $r) $pr[$r['station_id']][$r['fuel']] = $r;
    $fuels = array_values(array_filter(array_keys(FUELS), fn($f) => array_filter($pr, fn($p) => isset($p[$f]))));
    $best = [];
    $fresh = gmdate('Y-m-d H:i:s', time() - FUEL_FRESH_DAYS * 86400); // un prix périmé n'est jamais « le moins cher »
    foreach ($fuels as $f) { $m = null; foreach ($pr as $sid => $p) if (isset($p[$f]) && $p[$f]['maj'] >= $fresh && ($m === null || $p[$f]['price'] < $m[1])) $m = [$sid, $p[$f]['price']]; $best[$f] = $m; }
    $tbl = '<table><thead><tr><th>Station</th>' . implode('', array_map(fn($f) => '<th>' . h(FUELS[$f]) . '</th>', $fuels)) . '</tr></thead><tbody>';
    foreach ($stations as $s) {
        $tbl .= '<tr><td><a href="' . h(station_url($s)) . '">' . h($s['address']) . '</a>' . ($s['h24'] ? '<br><small>automate 24h/24</small>' : '') . '</td>';
        foreach ($fuels as $f) { $p = $pr[$s['id']][$f] ?? null; $tbl .= '<td>' . ($p ? ((string)($best[$f][0] ?? '') === (string)$s['id'] ? '<strong style="color:#0b6e4f">' . eur((float)$p['price']) . '</strong>' : eur((float)$p['price'])) . '<br><small>' . h(date_fr($p['maj'])) . '</small>' : '—') . '</td>'; }
        $tbl .= '</tr>';
    }
    $tbl .= '</tbody></table>';
    $faq = [];
    foreach (array_slice($fuels, 0, 3) as $f) if (!empty($best[$f])) {
        $bs = array_values(array_filter($stations, fn($s) => (string)$s['id'] === (string)$best[$f][0]))[0];
        $faq[] = ['q' => 'Où trouver le ' . FUELS[$f] . ' le moins cher à ' . $city . ' ?', 'a' => 'Actuellement, la station la moins chère pour le ' . FUELS[$f] . ' à ' . $city . ' est ' . station_label($bs) . ', à ' . eur((float)$best[$f][1]) . ' le litre (prix déclaré par la station).'];
    }
    $faq[] = ['q' => 'Combien de stations-service à ' . $city . ' ?', 'a' => count($stations) . ' station(s) déclarent leurs prix à ' . $city . ' (' . $dname . ').'];
    $near = $db->prepare('SELECT city, city_slug, COUNT(*) n FROM stations WHERE dept=? AND city_slug<>? GROUP BY city_slug ORDER BY n DESC LIMIT 15');
    $near->execute([$dept, $citySlug]);
    $dbase = '/prix-carburant/' . dept_slug($dept) . '/';
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/prix-carburant/">Prix carburant</a> › <a href="' . $dbase . '">' . h($dname) . '</a> › ' . h($city) . '</p>'
        . '<h1>Prix carburant à ' . h($city) . ' (' . h($dept) . ') : stations les moins chères</h1>'
        . '<p class="lead">' . implode(' ', array_map(fn($f) => '<strong>' . h(FUELS[$f]) . '</strong> dès ' . eur((float)$best[$f][1]) . '.', array_filter($fuels, fn($f) => !empty($best[$f])))) . ' Prix officiels déclarés par les ' . count($stations) . ' station(s) de ' . h($city) . ', mis à jour toutes les heures.</p>'
        . $tbl . '<section class="faq"><h2>Questions fréquentes</h2>' . implode('', array_map(fn($x) => '<details><summary>' . h($x['q']) . '</summary><p>' . h($x['a']) . '</p></details>', $faq)) . '</section>'
        . '<h2>Autres communes du département ' . h($dname) . '</h2><p>' . implode(' · ', array_map(fn($c) => '<a href="' . $dbase . h($c['city_slug']) . '/">' . h($c['city']) . '</a>', $near->fetchAll())) . '</p>'
        . fuel_guides($site, 4) . fuel_footer();
    return layout($site, ['title' => 'Prix carburant ' . $city . ' (' . $dept . ') aujourd\'hui : station la moins chère | ' . $site['name'],
        'desc' => 'Prix du ' . implode(', ', array_map(fn($f) => FUELS[$f], $fuels)) . " à $city aujourd'hui : comparez les " . count($stations) . ' stations et trouvez la moins chère.',
        'canonical' => 'https://' . $site['host'] . $dbase . $citySlug . '/',
        'schema' => [breadcrumbs($site, [['Prix carburant', '/prix-carburant/'], [$dname, $dbase], [$city, $dbase . $citySlug . '/']]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]], $faq)]]], $body);
}

function page_station(array $site, string $slug): string
{
    $db = fuel_db($site['host']);
    $st = $db->prepare('SELECT * FROM stations WHERE slug=?'); $st->execute([$slug]);
    $s = $st->fetch();
    if (!$s) return '';
    $q = $db->prepare('SELECT fuel, price, maj FROM fuel_prices WHERE station_id=?'); $q->execute([$s['id']]);
    $prices = $q->fetchAll(PDO::FETCH_UNIQUE);
    $services = json_decode((string)$s['services'], true) ?: [];
    $dname = DEPTS[$s['dept']] ?? $s['dept'];
    $dbase = '/prix-carburant/' . dept_slug($s['dept']) . '/';
    $rows = '';
    foreach (FUELS as $f => $label) {
        if (empty($prices[$f])) continue;
        $avg = $db->prepare("SELECT avg FROM fuel_history WHERE scope=? AND fuel=? ORDER BY day DESC LIMIT 1"); $avg->execute([$s['dept'], $f]);
        $a = (float)$avg->fetchColumn(); $p = (float)$prices[$f]['price'];
        $rows .= '<tr><td><strong>' . h($label) . '</strong></td><td>' . eur($p) . '</td><td>' . ($a ? (($p <= $a ? '<span style="color:#0b6e4f">' : '<span style="color:#b32d2e">') . ($p - $a >= 0 ? '+' : '') . number_format(($p - $a) * 100, 1, ',', ' ') . ' cts</span>') : '—') . '</td><td><small>' . h(date_fr($prices[$f]['maj'])) . '</small></td></tr>';
    }
    $others = $db->prepare('SELECT * FROM stations WHERE dept=? AND city_slug=? AND id<>? LIMIT 10'); $others->execute([$s['dept'], $s['city_slug'], $s['id']]);
    $osm = 'https://www.openstreetmap.org/?mlat=' . $s['lat'] . '&mlon=' . $s['lon'] . '#map=17/' . $s['lat'] . '/' . $s['lon'];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/prix-carburant/">Prix carburant</a> › <a href="' . $dbase . '">' . h($dname) . '</a> › <a href="' . $dbase . h($s['city_slug']) . '/">' . h($s['city']) . '</a></p>'
        . '<h1>Station-service ' . h($s['address']) . ', ' . h($s['city']) . ' : prix des carburants</h1>'
        . '<p>' . h($s['address']) . ', ' . h($s['cp']) . ' ' . h($s['city']) . ($s['pop'] === 'A' ? ' — station d\'autoroute' : '') . ($s['h24'] ? ' — automate 24h/24' : '') . '. <a href="' . h($osm) . '" rel="nofollow noopener" target="_blank">Voir sur la carte</a></p>'
        . ($rows ? '<table><thead><tr><th>Carburant</th><th>Prix</th><th>vs moyenne ' . h($dname) . '</th><th>Mis à jour</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '<p>Aucun prix déclaré actuellement.</p>')
        . ($services ? '<h2>Services</h2><ul>' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $services)) . '</ul>' : '')
        . (($o = $others->fetchAll()) ? '<h2>Autres stations à ' . h($s['city']) . '</h2><ul>' . implode('', array_map(fn($r) => '<li><a href="' . h(station_url($r)) . '">' . h(station_label($r)) . '</a></li>', $o)) . '</ul>' : '')
        . fuel_guides($site, 4) . fuel_footer();
    $offers = [];
    foreach ($prices as $f => $p) $offers[] = ['@type' => 'Offer', 'name' => FUELS[$f] ?? $f, 'price' => number_format((float)$p['price'], 3, '.', ''), 'priceCurrency' => 'EUR'];
    return layout($site, ['title' => 'Station ' . mb_strimwidth($s['address'], 0, 40, '…') . ' ' . $s['city'] . ' : prix carburant | ' . $site['name'],
        'desc' => 'Prix du carburant à la station ' . station_label($s) . ' : ' . implode(', ', array_map(fn($f, $p) => (FUELS[$f] ?? $f) . ' ' . eur((float)$p['price']), array_keys($prices), $prices)) . '.',
        'canonical' => 'https://' . $site['host'] . station_url($s),
        'schema' => [['@context' => 'https://schema.org', '@type' => 'GasStation', 'name' => 'Station-service ' . station_label($s),
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $s['address'], 'postalCode' => $s['cp'], 'addressLocality' => $s['city'], 'addressCountry' => 'FR'],
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $s['lat'], 'longitude' => $s['lon']], 'makesOffer' => $offers],
            breadcrumbs($site, [['Prix carburant', '/prix-carburant/'], [$dname, $dbase], [$s['city'], $dbase . $s['city_slug'] . '/']])]], $body);
}

function fuel_home_block(array $site): string
{
    $db = fuel_db($site['host']);
    $t = fuel_table($db, 'FR', 'Prix moyen en France aujourd\'hui');
    if (!$t) return '';
    $depts = implode(' · ', array_map(fn($c) => '<a href="/prix-carburant/' . h(dept_slug((string)$c)) . '/">' . h(DEPTS[$c]) . '</a>', array_keys(DEPTS)));
    return '<h2 style="margin-top:28px">Prix des carburants aujourd\'hui</h2>' . $t . '<p><a class="kick" href="/prix-carburant/">Toutes les stations les moins chères →</a></p><details><summary><strong>Prix par département</strong></summary><p>' . $depts . '</p></details>';
}

function out_sitemap_fuel(array $site, int $i): void
{
    $db = fuel_db($site['host']);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $today = gmdate('Y-m-d');
    if ($i === 1) {
        echo '<url><loc>https://' . $site['host'] . '/prix-carburant/</loc><lastmod>' . $today . '</lastmod></url>';
        foreach ($db->query('SELECT DISTINCT dept FROM stations') as $r) echo '<url><loc>https://' . $site['host'] . '/prix-carburant/' . dept_slug($r['dept']) . '/</loc><lastmod>' . $today . '</lastmod></url>';
        foreach ($db->query('SELECT DISTINCT dept, city_slug FROM stations') as $r) echo '<url><loc>https://' . $site['host'] . '/prix-carburant/' . dept_slug($r['dept']) . '/' . h($r['city_slug']) . '/</loc><lastmod>' . $today . '</lastmod></url>';
    } else {
        $st = $db->prepare('SELECT slug FROM stations ORDER BY id LIMIT 5000 OFFSET ?'); $st->execute([($i - 2) * 5000]);
        foreach ($st as $r) echo '<url><loc>https://' . $site['host'] . '/station/' . h($r['slug']) . '/</loc><lastmod>' . $today . '</lastmod></url>';
    }
    echo '</urlset>';
}

function fuel_sitemap_count(array $site): int
{
    return 1 + (int)ceil(max(1, (int)fuel_db($site['host'])->query('SELECT COUNT(*) FROM stations')->fetchColumn()) / 5000);
}
