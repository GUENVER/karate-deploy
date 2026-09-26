<?php
// Bornes de recharge pour véhicules électriques (base nationale IRVE consolidée par data.gouv.fr, Licence Ouverte Etalab) :
// synchronisation hebdomadaire, pages France / département / commune / station, calculateur de coût de recharge.
// Dépend de fuel.php (DEPTS, dept_of, dept_slug, city_name).
declare(strict_types=1);

const IRVE_DATASET = '5448d3e0c751df01f85d0572';

function ev_db(string $host): PDO
{
    $db = site_db($host);
    static $init = [];
    if (empty($init[$host])) {
        $db->exec("CREATE TABLE IF NOT EXISTS bornes(id TEXT PRIMARY KEY, slug TEXT, name TEXT, operator TEXT, network TEXT, address TEXT, cp TEXT, dept TEXT, city TEXT, city_slug TEXT,
            lat REAL, lon REAL, npdc INTEGER DEFAULT 0, pmax REAL DEFAULT 0, t2 INTEGER DEFAULT 0, ccs INTEGER DEFAULT 0, chademo INTEGER DEFAULT 0, ef INTEGER DEFAULT 0,
            free INTEGER DEFAULT 0, cb INTEGER DEFAULT 0, acces TEXT, horaires TEXT, pmr TEXT, implantation TEXT, tarif TEXT, since TEXT, seen_at TEXT);
        CREATE INDEX IF NOT EXISTS ix_b_city ON bornes(dept, city_slug);
        CREATE INDEX IF NOT EXISTS ix_b_slug ON bornes(slug);");
        $init[$host] = 1;
    }
    return $db;
}

function ev_bool(string $v): int { return in_array(strtolower(trim($v)), ['true', '1', 'oui', 'vrai'], true) ? 1 : 0; }
function ev_kw(string $v): float { $p = (float)str_replace(',', '.', $v); return $p > 1000 ? $p / 1000 : $p; } // certains exploitants déclarent en W

// URL du dernier fichier consolidé (change chaque jour).
function irve_url(): string
{
    if ($u = (string)cfg('irve_url', '')) return $u;
    $ch = curl_init('https://www.data.gouv.fr/api/1/datasets/' . IRVE_DATASET . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $d = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    foreach ($d['resources'] ?? [] as $r) if (($r['format'] ?? '') === 'csv' && str_starts_with((string)$r['title'], 'Consolidation')) return (string)$r['url'];
    throw new RuntimeException('fichier IRVE introuvable');
}

function ev_sync(array $site, callable $log): array
{
    $db = ev_db($site['host']);
    $file = cfg('data_dir') . '/irve.csv';
    $url = irve_url();
    $fh = fopen($file, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 900, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $ok = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch); fclose($fh);
    if (!$ok || ($code !== 200 && $code !== 0) || filesize($file) < 1000000) throw new RuntimeException("téléchargement IRVE : HTTP $code");
    $log('fichier ' . round(filesize($file) / 1048576) . ' Mo');

    $stamp = gmdate('Y-m-d H:i:s');
    $in = fopen($file, 'r');
    $head = fgetcsv($in, 0, ',', '"', '');
    $ix = array_flip($head);
    $g = fn(array $r, string $k) => trim((string)($r[$ix[$k] ?? -1] ?? ''));
    $up = $db->prepare('INSERT INTO bornes(id,slug,name,operator,network,address,cp,dept,city,city_slug,lat,lon,npdc,pmax,t2,ccs,chademo,ef,free,cb,acces,horaires,pmr,implantation,tarif,since,seen_at)
        VALUES(:id,:slug,:name,:op,:net,:addr,:cp,:dept,:city,:cs,:lat,:lon,1,:p,:t2,:ccs,:cha,:ef,:free,:cb,:acces,:hor,:pmr,:impl,:tarif,:since,:seen)
        ON CONFLICT(id) DO UPDATE SET npdc=CASE WHEN bornes.seen_at=excluded.seen_at THEN bornes.npdc+1 ELSE 1 END,
            pmax=CASE WHEN bornes.seen_at=excluded.seen_at THEN MAX(bornes.pmax,excluded.pmax) ELSE excluded.pmax END,
            t2=CASE WHEN bornes.seen_at=excluded.seen_at THEN MAX(bornes.t2,excluded.t2) ELSE excluded.t2 END,
            ccs=CASE WHEN bornes.seen_at=excluded.seen_at THEN MAX(bornes.ccs,excluded.ccs) ELSE excluded.ccs END,
            chademo=CASE WHEN bornes.seen_at=excluded.seen_at THEN MAX(bornes.chademo,excluded.chademo) ELSE excluded.chademo END,
            ef=CASE WHEN bornes.seen_at=excluded.seen_at THEN MAX(bornes.ef,excluded.ef) ELSE excluded.ef END,
            name=excluded.name, operator=excluded.operator, network=excluded.network, address=excluded.address, cp=excluded.cp, dept=excluded.dept, city=excluded.city,
            city_slug=excluded.city_slug, lat=excluded.lat, lon=excluded.lon, free=excluded.free, cb=excluded.cb, acces=excluded.acces, horaires=excluded.horaires,
            pmr=excluded.pmr, implantation=excluded.implantation, tarif=excluded.tarif, since=excluded.since, seen_at=excluded.seen_at');
    $n = 0; $skip = 0;
    $db->beginTransaction();
    while (($r = fgetcsv($in, 0, ',', '"', '')) !== false) {
        $cp = $g($r, 'consolidated_code_postal'); $city = $g($r, 'consolidated_commune');
        $lat = (float)$g($r, 'consolidated_latitude'); $lon = (float)$g($r, 'consolidated_longitude');
        if ((!preg_match('/^\d{5}$/', $cp) || $city === '') && $lat > 41 && $lat < 51.2 && $lon > -5.5 && $lon < 9.8
            && preg_match('/\b((?:0[1-9]|[1-8]\d|9[0-5]|97)\d{3})\b[ ,]*([^\d,]{2,})?/u', $g($r, 'adresse_station'), $am)) { // code postal/commune non consolidés : repris de l'adresse
            $cp = $am[1];
            if ($city === '') $city = trim((string)preg_replace('/\s*(cedex.*|france)$/iu', '', trim($am[2] ?? '')));
        }
        if (!preg_match('/^\d{5}$/', $cp) || $city === '' || !$lat) { $skip++; continue; }
        $sid = $g($r, 'id_station_itinerance');
        $id = preg_match('/^FR[A-Z0-9]{3}P/i', $sid) ? strtoupper($sid) : 'X' . substr(md5($g($r, 'nom_station') . '|' . round($lat, 5) . '|' . round($lon, 5)), 0, 15);
        $city = city_name($city);
        $name = $g($r, 'nom_station') ?: ($g($r, 'nom_enseigne') ?: 'Borne de recharge');
        $up->execute(['id' => $id, 'slug' => slugify($city . ' ' . $name, 60) . '-' . substr(md5($id), 0, 6), 'name' => mb_substr($name, 0, 120),
            'op' => mb_substr($g($r, 'nom_operateur'), 0, 80), 'net' => mb_substr($g($r, 'nom_enseigne'), 0, 80), 'addr' => mb_substr($g($r, 'adresse_station'), 0, 160),
            'cp' => $cp, 'dept' => dept_of($cp), 'city' => $city, 'cs' => slugify($city, 60), 'lat' => $lat, 'lon' => $lon, 'p' => ev_kw($g($r, 'puissance_nominale')),
            't2' => ev_bool($g($r, 'prise_type_2')), 'ccs' => ev_bool($g($r, 'prise_type_combo_ccs')), 'cha' => ev_bool($g($r, 'prise_type_chademo')), 'ef' => ev_bool($g($r, 'prise_type_ef')),
            'free' => ev_bool($g($r, 'gratuit')), 'cb' => ev_bool($g($r, 'paiement_cb')), 'acces' => mb_substr($g($r, 'condition_acces'), 0, 60), 'hor' => mb_substr($g($r, 'horaires'), 0, 120),
            'pmr' => mb_substr($g($r, 'accessibilite_pmr'), 0, 60), 'impl' => mb_substr($g($r, 'implantation_station'), 0, 60), 'tarif' => mb_substr($g($r, 'tarification'), 0, 300),
            'since' => substr($g($r, 'date_mise_en_service'), 0, 10), 'seen' => $stamp]);
        if (++$n % 5000 === 0) { $db->commit(); usleep(300000); $db->beginTransaction(); }
    }
    $db->commit();
    fclose($in); @unlink($file);
    $del = 0;
    if ($n > 50000) { $st = $db->prepare('DELETE FROM bornes WHERE seen_at<>?'); $st->execute([$stamp]); $del = $st->rowCount(); }
    cache_clear($site['host']);
    return ['points_de_charge' => $n, 'ignores' => $skip, 'stations' => (int)$db->query('SELECT COUNT(*) FROM bornes')->fetchColumn(), 'supprimees' => $del];
}

// ---------- Rendu ----------

function ev_url(array $b): string { return '/borne/' . $b['slug'] . '/'; }
function ev_power(float $p): string { return $p ? number_format($p, $p < 10 ? 1 : 0, ',', ' ') . ' kW' : '—'; }
function ev_speed(float $p): string { return $p >= 150 ? 'ultra-rapide' : ($p >= 50 ? 'rapide' : ($p >= 22 ? 'accélérée' : 'normale')); }
function ev_plugs(array $b): string { return implode(', ', array_keys(array_filter(['Type 2' => $b['t2'], 'Combo CCS' => $b['ccs'], 'CHAdeMO' => $b['chademo'], 'Prise domestique E/F' => $b['ef']]))) ?: '—'; }
function ev_label(array $b): string { return $b['name'] . ($b['network'] && stripos($b['name'], $b['network']) === false ? ' (' . $b['network'] . ')' : ''); }

function ev_counts(PDO $db, string $where = '', array $args = []): array
{
    $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(npdc),0) pdc, SUM(pmax>=50) fast, SUM(pmax>=150) ultra, SUM(free) free FROM bornes $where");
    $st->execute($args);
    return array_map('intval', $st->fetch());
}

function ev_table(array $rows): string
{
    if (!$rows) return '';
    return '<table><thead><tr><th>Station</th><th>Puissance max</th><th>Prises</th><th>Points</th></tr></thead><tbody>' . implode('', array_map(fn($b) => '<tr><td><a href="' . h(ev_url($b)) . '">' . h(ev_label($b)) . '</a><br><small>' . h($b['address']) . '</small></td><td><strong>' . ev_power((float)$b['pmax']) . '</strong><br><small>' . ev_speed((float)$b['pmax']) . '</small></td><td>' . h(ev_plugs($b)) . '</td><td>' . (int)$b['npdc'] . ($b['free'] ? '<br><small style="color:#0b6e4f">gratuit</small>' : '') . '</td></tr>', $rows)) . '</tbody></table>';
}

function ev_footer(): string
{
    return '<p class="disc">Données issues de la base nationale des infrastructures de recharge (IRVE) publiée sur data.gouv.fr (Licence Ouverte Etalab), mise à jour chaque semaine. Disponibilité en temps réel et tarifs exacts : vérifiez auprès de l\'opérateur avant de vous déplacer.</p>';
}

function ev_guides(array $site, int $n = 6): string
{
    $g = site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT $n")->fetchAll();
    return $g ? '<h2>Nos guides voiture électrique</h2><ul>' . implode('', array_map(fn($p) => '<li><a href="' . h($p['path']) . '">' . h($p['title']) . '</a></li>', $g)) . '</ul>' : '';
}

// Calculateur coût de recharge / coût aux 100 km (côté navigateur, valeurs modifiables).
function ev_calculator(): string
{
    return '<div class="box" id="evcalc"><h2 style="margin-top:0">Calculer le coût d\'une recharge</h2><div class="row">'
        . '<label>Capacité batterie (kWh)<input type="number" id="ec_bat" value="60" min="10" max="200"></label>'
        . '<label>Recharger de (%)<input type="number" id="ec_from" value="20" min="0" max="100"></label>'
        . '<label>à (%)<input type="number" id="ec_to" value="80" min="0" max="100"></label>'
        . '<label>Prix du kWh (€)<input type="number" id="ec_price" value="0.25" step="0.01" min="0"></label>'
        . '<label>Consommation (kWh/100 km)<input type="number" id="ec_cons" value="17" step="0.5" min="5"></label>'
        . '<label>Puissance de charge (kW)<input type="number" id="ec_kw" value="11" min="1"></label></div>'
        . '<p id="ec_out" style="font-size:1.1rem"></p><p><small>Prix du kWh : à domicile, reprenez celui de votre contrat d\'électricité ; sur borne publique, consultez l\'appli de l\'opérateur. Pertes de charge (~10 %) incluses.</small></p></div>'
        . '<script>(function(){var $=function(i){return parseFloat(document.getElementById(i).value)||0};function c(){var kwh=$("ec_bat")*Math.max(0,$("ec_to")-$("ec_from"))/100*1.1,cost=kwh*$("ec_price"),t=kwh/Math.max(1,$("ec_kw")),km=kwh/1.1/Math.max(1,$("ec_cons"))*100;'
        . 'document.getElementById("ec_out").innerHTML="<strong>"+cost.toFixed(2).replace(".",",")+" €</strong> pour "+kwh.toFixed(1).replace(".",",")+" kWh, soit environ "+Math.round(km)+" km d\'autonomie. Durée ≈ "+Math.floor(t)+" h "+Math.round((t%1)*60)+" min. Coût aux 100 km : <strong>"+($("ec_cons")*1.1*$("ec_price")).toFixed(2).replace(".",",")+" €</strong>."}'
        . 'document.querySelectorAll("#evcalc input").forEach(function(e){e.addEventListener("input",c)});c()})();</script>';
}

function page_ev_france(array $site): string
{
    $db = ev_db($site['host']);
    $c = ev_counts($db);
    if (!$c['n']) return '';
    $rows = $db->query('SELECT dept, COUNT(*) n, SUM(pmax>=50) fast FROM bornes GROUP BY dept ORDER BY dept')->fetchAll();
    $top = $db->query('SELECT * FROM bornes WHERE pmax>=150 ORDER BY pmax DESC, npdc DESC LIMIT 15')->fetchAll();
    $nf = fn($v) => number_format($v, 0, ',', ' ');
    $body = '<h1 style="margin-top:28px">Bornes de recharge en France : carte par département et par commune</h1>'
        . '<p class="lead">' . $nf($c['n']) . ' stations de recharge publiques et ' . $nf($c['pdc']) . ' points de charge recensés, dont ' . $nf($c['fast']) . ' stations rapides (50 kW et plus) et ' . $nf($c['ultra']) . ' ultra-rapides (150 kW et plus). ' . $nf($c['free']) . ' stations sont déclarées gratuites.</p>'
        . ev_calculator()
        . '<h2>Bornes de recharge par département</h2><div class="grid">' . implode('', array_map(fn($r) => '<div class="card"><div class="in"><a href="/bornes-recharge/' . h(dept_slug((string)$r['dept'])) . '/"><strong>' . h(DEPTS[$r['dept']] ?? $r['dept']) . ' (' . h((string)$r['dept']) . ')</strong></a><p>' . $nf((int)$r['n']) . ' stations, dont ' . $nf((int)$r['fast']) . ' rapides</p></div></div>', array_filter($rows, fn($r) => isset(DEPTS[$r['dept']])))) . '</div>'
        . '<h2>Les stations les plus puissantes</h2>' . ev_table($top) . ev_guides($site) . ev_footer();
    return layout($site, ['title' => 'Bornes de recharge voiture électrique en France : carte et liste | ' . $site['name'],
        'desc' => 'Trouvez une borne de recharge près de chez vous : ' . $nf($c['n']) . ' stations en France par département et par commune, puissance, prises (Type 2, CCS, CHAdeMO), gratuité.',
        'canonical' => 'https://' . $site['host'] . '/bornes-recharge/', 'schema' => [breadcrumbs($site, [['Bornes de recharge', '/bornes-recharge/']])]], $body);
}

function page_ev_dept(array $site, string $dept): string
{
    $db = ev_db($site['host']);
    $st = $db->prepare('SELECT city, city_slug, COUNT(*) n FROM bornes WHERE dept=? GROUP BY city_slug ORDER BY n DESC, city');
    $st->execute([$dept]);
    $cities = $st->fetchAll();
    if (!$cities) return '';
    $name = DEPTS[$dept] ?? $dept;
    $c = ev_counts($db, 'WHERE dept=?', [$dept]);
    $fast = $db->prepare('SELECT * FROM bornes WHERE dept=? AND pmax>=50 ORDER BY pmax DESC, npdc DESC LIMIT 20'); $fast->execute([$dept]);
    $base = '/bornes-recharge/' . dept_slug($dept) . '/';
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/bornes-recharge/">Bornes de recharge</a> › ' . h($name) . '</p>'
        . '<h1>Bornes de recharge dans le département ' . h($name) . ' (' . h($dept) . ')</h1>'
        . '<p class="lead">' . $c['n'] . ' stations publiques (' . $c['pdc'] . ' points de charge) dans ' . count($cities) . ' communes, dont ' . $c['fast'] . ' rapides (≥ 50 kW) et ' . $c['free'] . ' gratuites.</p>'
        . (($t = ev_table($fast->fetchAll())) ? '<h2>Recharge rapide dans le département ' . h($name) . '</h2>' . $t : '')
        . '<h2>Bornes de recharge par commune</h2><p>' . implode(' · ', array_map(fn($x) => '<a href="' . $base . h($x['city_slug']) . '/">' . h($x['city']) . '</a> (' . $x['n'] . ')', $cities)) . '</p>'
        . ev_guides($site) . ev_footer();
    return layout($site, ['title' => 'Bornes de recharge ' . $name . ' (' . $dept . ') : ' . $c['n'] . ' stations | ' . $site['name'],
        'desc' => "Toutes les bornes de recharge pour voiture électrique dans le département $name ($dept) : {$c['n']} stations, recharge rapide, prises et gratuité, commune par commune.",
        'canonical' => 'https://' . $site['host'] . $base, 'schema' => [breadcrumbs($site, [['Bornes de recharge', '/bornes-recharge/'], [$name, $base]])]], $body);
}

function page_ev_city(array $site, string $dept, string $citySlug): string
{
    $db = ev_db($site['host']);
    $st = $db->prepare('SELECT * FROM bornes WHERE dept=? AND city_slug=? ORDER BY pmax DESC, npdc DESC');
    $st->execute([$dept, $citySlug]);
    $rows = $st->fetchAll();
    if (!$rows) return '';
    $city = $rows[0]['city']; $dname = DEPTS[$dept] ?? $dept;
    $dbase = '/bornes-recharge/' . dept_slug($dept) . '/';
    $pdc = array_sum(array_column($rows, 'npdc'));
    $fast = array_values(array_filter($rows, fn($b) => $b['pmax'] >= 50));
    $free = array_values(array_filter($rows, fn($b) => $b['free']));
    $faq = [['q' => 'Combien de bornes de recharge à ' . $city . ' ?', 'a' => count($rows) . ' station(s) de recharge publique(s) sont recensées à ' . $city . ', pour un total de ' . $pdc . ' point(s) de charge.']];
    $faq[] = $fast ? ['q' => 'Où recharger rapidement à ' . $city . ' ?', 'a' => 'La station la plus puissante est ' . ev_label($fast[0]) . ' (' . $fast[0]['address'] . '), jusqu\'à ' . ev_power((float)$fast[0]['pmax']) . '.']
        : ['q' => 'Y a-t-il une borne rapide à ' . $city . ' ?', 'a' => 'Aucune station de 50 kW ou plus n\'est déclarée à ' . $city . ' : consultez les communes voisines du département ' . $dname . '.'];
    $faq[] = $free ? ['q' => 'Existe-t-il une borne gratuite à ' . $city . ' ?', 'a' => 'Oui, ' . count($free) . ' station(s) sont déclarées gratuites, par exemple ' . ev_label($free[0]) . ' (' . $free[0]['address'] . '). Les conditions peuvent changer : vérifiez sur place.']
        : ['q' => 'Existe-t-il une borne gratuite à ' . $city . ' ?', 'a' => 'Aucune station gratuite n\'est déclarée à ' . $city . ' dans la base nationale.'];
    $near = $db->prepare('SELECT city, city_slug, COUNT(*) n FROM bornes WHERE dept=? AND city_slug<>? GROUP BY city_slug ORDER BY n DESC LIMIT 15');
    $near->execute([$dept, $citySlug]);
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/bornes-recharge/">Bornes de recharge</a> › <a href="' . $dbase . '">' . h($dname) . '</a> › ' . h($city) . '</p>'
        . '<h1>Bornes de recharge à ' . h($city) . ' (' . h($dept) . ')</h1>'
        . '<p class="lead">' . count($rows) . ' station(s) et ' . $pdc . ' point(s) de charge pour voiture électrique à ' . h($city) . ($fast ? ', dont ' . count($fast) . ' en recharge rapide' : '') . '. Puissance, prises disponibles, accès et gratuité.</p>'
        . ev_table($rows)
        . '<section class="faq"><h2>Questions fréquentes</h2>' . implode('', array_map(fn($x) => '<details><summary>' . h($x['q']) . '</summary><p>' . h($x['a']) . '</p></details>', $faq)) . '</section>'
        . '<h2>Autres communes du département ' . h($dname) . '</h2><p>' . implode(' · ', array_map(fn($x) => '<a href="' . $dbase . h($x['city_slug']) . '/">' . h($x['city']) . '</a>', $near->fetchAll())) . '</p>'
        . ev_guides($site, 4) . ev_footer();
    return layout($site, ['title' => 'Borne de recharge ' . $city . ' (' . $dept . ') : ' . count($rows) . ' stations | ' . $site['name'],
        'desc' => 'Où recharger sa voiture électrique à ' . $city . ' ? ' . count($rows) . ' stations, ' . $pdc . ' points de charge' . ($fast ? ', recharge rapide jusqu\'à ' . ev_power((float)$fast[0]['pmax']) : '') . ' : adresses, prises et gratuité.',
        'canonical' => 'https://' . $site['host'] . $dbase . $citySlug . '/',
        'schema' => [breadcrumbs($site, [['Bornes de recharge', '/bornes-recharge/'], [$dname, $dbase], [$city, $dbase . $citySlug . '/']]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]], $faq)]]], $body);
}

function page_borne(array $site, string $slug): string
{
    $db = ev_db($site['host']);
    $st = $db->prepare('SELECT * FROM bornes WHERE slug=?'); $st->execute([$slug]);
    $b = $st->fetch();
    if (!$b) return '';
    $dname = DEPTS[$b['dept']] ?? $b['dept'];
    $dbase = '/bornes-recharge/' . dept_slug($b['dept']) . '/';
    $p = (float)$b['pmax'];
    $t = $p ? 60 * 0.6 * 60 / min($p, 150) : 0; // 20→80 % d'une batterie de 60 kWh, puissance plafonnée à 150 kW
    $info = array_filter([
        'Adresse' => $b['address'], 'Commune' => $b['cp'] . ' ' . $b['city'], 'Opérateur' => $b['operator'], 'Enseigne' => $b['network'],
        'Puissance maximale' => ev_power($p) . ' (charge ' . ev_speed($p) . ')', 'Points de charge' => (string)$b['npdc'], 'Prises' => ev_plugs($b),
        'Gratuit' => $b['free'] ? 'oui (déclaré)' : 'non', 'Paiement par carte bancaire' => $b['cb'] ? 'oui' : 'non précisé', 'Tarification' => $b['tarif'],
        'Accès' => $b['acces'], 'Horaires' => $b['horaires'] === '24/7' ? '24 h/24, 7 j/7' : $b['horaires'], 'Accessibilité PMR' => $b['pmr'], 'Emplacement' => $b['implantation'],
        'En service depuis' => $b['since'] ? date_fr($b['since']) : '',
    ], fn($v) => $v !== '' && $v !== null);
    $others = $db->prepare('SELECT * FROM bornes WHERE dept=? AND city_slug=? AND id<>? ORDER BY pmax DESC LIMIT 10'); $others->execute([$b['dept'], $b['city_slug'], $b['id']]);
    $osm = 'https://www.openstreetmap.org/?mlat=' . $b['lat'] . '&mlon=' . $b['lon'] . '#map=17/' . $b['lat'] . '/' . $b['lon'];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/bornes-recharge/">Bornes de recharge</a> › <a href="' . $dbase . '">' . h($dname) . '</a> › <a href="' . $dbase . h($b['city_slug']) . '/">' . h($b['city']) . '</a></p>'
        . '<h1>Borne de recharge ' . h(ev_label($b)) . ' à ' . h($b['city']) . '</h1>'
        . '<p class="lead">Station de recharge ' . ev_speed($p) . ' jusqu\'à ' . ev_power($p) . ', ' . (int)$b['npdc'] . ' point(s) de charge. '
        . ($t ? 'Pour recharger de 20 à 80 % une batterie de 60 kWh, comptez environ ' . ($t >= 60 ? floor($t / 60) . ' h ' . round(fmod($t, 60)) . ' min' : round($t) . ' min') . ' (si votre voiture accepte cette puissance).' : '') . '</p>'
        . '<table><tbody>' . implode('', array_map(fn($k, $v) => '<tr><th style="text-align:left">' . h($k) . '</th><td>' . h((string)$v) . '</td></tr>', array_keys($info), $info)) . '</tbody></table>'
        . '<p><a href="' . h($osm) . '" rel="nofollow noopener" target="_blank">Voir l\'emplacement sur la carte</a></p>'
        . (($o = $others->fetchAll()) ? '<h2>Autres bornes à ' . h($b['city']) . '</h2>' . ev_table($o) : '')
        . ev_guides($site, 4) . ev_footer();
    return layout($site, ['title' => 'Borne ' . mb_strimwidth(ev_label($b), 0, 45, '…') . ' ' . $b['city'] . ' : ' . ev_power($p) . ' | ' . $site['name'],
        'desc' => 'Borne de recharge ' . ev_label($b) . ', ' . $b['address'] . ' : ' . ev_power($p) . ', ' . (int)$b['npdc'] . ' point(s) de charge, prises ' . ev_plugs($b) . ($b['free'] ? ', gratuite' : '') . '.',
        'canonical' => 'https://' . $site['host'] . ev_url($b),
        'schema' => [['@context' => 'https://schema.org', '@type' => 'AutomotiveBusiness', 'name' => 'Borne de recharge ' . ev_label($b),
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $b['address'], 'postalCode' => $b['cp'], 'addressLocality' => $b['city'], 'addressCountry' => 'FR'],
            'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $b['lat'], 'longitude' => $b['lon']], 'isAccessibleForFree' => (bool)$b['free']] + ($b['horaires'] === '24/7' ? ['openingHours' => 'Mo-Su 00:00-23:59'] : []),
            breadcrumbs($site, [['Bornes de recharge', '/bornes-recharge/'], [$dname, $dbase], [$b['city'], $dbase . $b['city_slug'] . '/']])]], $body);
}

function ev_home_block(array $site): string
{
    $c = ev_counts(ev_db($site['host']));
    if (!$c['n']) return '';
    $nf = fn($v) => number_format($v, 0, ',', ' ');
    $depts = implode(' · ', array_map(fn($k) => '<a href="/bornes-recharge/' . h(dept_slug((string)$k)) . '/">' . h(DEPTS[$k]) . '</a>', array_keys(DEPTS)));
    return '<h2 style="margin-top:28px">Trouver une borne de recharge</h2><div class="kpi"><div><b>' . $nf($c['n']) . '</b>stations en France</div><div><b>' . $nf($c['pdc']) . '</b>points de charge</div><div><b>' . $nf($c['fast']) . '</b>stations rapides</div><div><b>' . $nf($c['free']) . '</b>stations gratuites</div></div>'
        . '<p><a class="kick" href="/bornes-recharge/">Toutes les bornes par département et par commune →</a></p><details><summary><strong>Bornes par département</strong></summary><p>' . $depts . '</p></details>' . ev_calculator();
}

function out_sitemap_ev(array $site, int $i): void
{
    $db = ev_db($site['host']);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $day = substr((string)$db->query('SELECT MAX(seen_at) FROM bornes')->fetchColumn(), 0, 10) ?: gmdate('Y-m-d');
    if ($i === 1) {
        echo '<url><loc>https://' . $site['host'] . '/bornes-recharge/</loc><lastmod>' . $day . '</lastmod></url>';
        foreach ($db->query('SELECT DISTINCT dept FROM bornes') as $r) if (isset(DEPTS[$r['dept']])) echo '<url><loc>https://' . $site['host'] . '/bornes-recharge/' . dept_slug((string)$r['dept']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
        foreach ($db->query('SELECT DISTINCT dept, city_slug FROM bornes') as $r) if (isset(DEPTS[$r['dept']])) echo '<url><loc>https://' . $site['host'] . '/bornes-recharge/' . dept_slug((string)$r['dept']) . '/' . h($r['city_slug']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    } else {
        $st = $db->prepare('SELECT slug FROM bornes ORDER BY id LIMIT 5000 OFFSET ?'); $st->execute([($i - 2) * 5000]);
        foreach ($st as $r) echo '<url><loc>https://' . $site['host'] . '/borne/' . h($r['slug']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    }
    echo '</urlset>';
}

function ev_sitemap_count(array $site): int
{
    return 1 + (int)ceil(max(1, (int)ev_db($site['host'])->query('SELECT COUNT(*) FROM bornes')->fetchColumn()) / 5000);
}
