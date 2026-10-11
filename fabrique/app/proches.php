<?php
// « Offres d'emploi près de chez moi » : recherche par rayon autour d'une position (géolocalisation du navigateur) ou d'une ville.
// Coordonnées des offres précalculées dans job_geo par cli/geo_build.php (toutes les 3 h, après la synchro).
declare(strict_types=1);

const PC_PATH = '/emploi-pres-de-chez-moi/';

function pc_tables(PDO $db): void
{
    static $ok = false; if ($ok) return;
    $db->exec('CREATE TABLE IF NOT EXISTS job_geo(id TEXT PRIMARY KEY, lat REAL, lon REAL)');
    $db->exec('CREATE INDEX IF NOT EXISTS job_geo_lat ON job_geo(lat)');
    $ok = true;
}

// Recalcule les coordonnées de toutes les offres ouvertes : position fournie par la source, sinon code postal, sinon nom de commune.
function pc_build(array $site): array
{
    $db = jobs_db($site['host']); pc_tables($db);
    $byCp = []; $bySlug = [];
    foreach ($db->query('SELECT slug, cps, pop, lat, lon FROM pd_communes WHERE lat IS NOT NULL ORDER BY pop ASC') as $c) { // tri croissant : la commune la plus peuplée l'emporte
        $bySlug[(string)$c['slug']] = [(float)$c['lat'], (float)$c['lon']];
        foreach (explode(',', (string)$c['cps']) as $cp) if ($cp !== '') $byCp[$cp] = [(float)$c['lat'], (float)$c['lon']];
    }
    $src = [];
    foreach ($db->query('SELECT id, lat, lon FROM job_rome WHERE lat IS NOT NULL') as $r) $src[$r['id']] = [(float)$r['lat'], (float)$r['lon']];
    try { foreach ($db->query('SELECT id, lat, lon FROM lba_job_meta WHERE lat IS NOT NULL') as $r) $src[$r['id']] = [(float)$r['lat'], (float)$r['lon']]; } catch (Throwable $e) {}
    $stat = ['source' => 0, 'cp' => 0, 'ville' => 0, 'aucune' => 0];
    $rows = $db->query("SELECT id, city, postal FROM jobs WHERE status='open'")->fetchAll();
    $db->beginTransaction();
    $db->exec('DELETE FROM job_geo');
    $ins = $db->prepare('INSERT INTO job_geo(id, lat, lon) VALUES(?,?,?)');
    foreach ($rows as $j) {
        $p = $src[$j['id']] ?? null; $k = 'source';
        if (!$p && preg_match('/^\d{5}$/', (string)$j['postal'])) { $p = $byCp[$j['postal']] ?? null; $k = 'cp'; }
        if (!$p && (string)$j['city'] !== '') {
            $c = preg_replace(['/\s*\(.*\)$/u', '/\s+-\s+.*$/u', '/\s+\d+(e|er|ème)?\s+arrondissement$/iu'], '', (string)$j['city']);
            $p = $bySlug[slugify((string)$c, 80)] ?? null; $k = 'ville';
        }
        if (!$p) { $stat['aucune']++; continue; }
        $ins->execute([$j['id'], $p[0], $p[1]]); $stat[$k]++;
    }
    $db->commit();
    return $stat;
}

function pc_dist(float $a, float $b, float $c, float $d): float
{
    $r = M_PI / 180; $x = sin(($c - $a) * $r / 2) ** 2 + cos($a * $r) * cos($c * $r) * sin(($d - $b) * $r / 2) ** 2;
    return 12742 * asin(min(1, sqrt($x)));
}

// Ville saisie → coordonnées (la commune la plus peuplée de ce nom, ou un code postal).
function pc_geocode(PDO $db, string $l): ?array
{
    $l = trim($l); if ($l === '') return null;
    if (preg_match('/^\d{5}$/', $l)) { $st = $db->prepare("SELECT name, lat, lon FROM pd_communes WHERE (',' || cps || ',') LIKE ? AND lat IS NOT NULL ORDER BY pop DESC LIMIT 1"); $st->execute(['%,' . $l . ',%']); }
    else { $st = $db->prepare('SELECT name, lat, lon FROM pd_communes WHERE slug=? AND lat IS NOT NULL ORDER BY pop DESC LIMIT 1'); $st->execute([slugify($l, 80)]); }
    $r = $st->fetch(); return $r ? ['name' => (string)$r['name'], 'lat' => (float)$r['lat'], 'lon' => (float)$r['lon']] : null;
}

function pc_nearest_name(PDO $db, float $lat, float $lon): string
{
    $st = $db->prepare('SELECT name, lat, lon FROM pd_communes WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? ORDER BY ((lat-?)*(lat-?)+(lon-?)*(lon-?)) LIMIT 1');
    $st->execute([$lat - .1, $lat + .1, $lon - .15, $lon + .15, $lat, $lat, $lon, $lon]);
    return (string)($st->fetchColumn() ?: '');
}

function page_proches(array $site): string
{
    $db = jobs_db($site['host']); pc_tables($db);
    $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $r = (int)($_GET['r'] ?? 20); if (!in_array($r, [5, 10, 20, 30, 50], true)) $r = 20;
    $fc = job_contract_filter();
    $l = trim(mb_substr((string)($_GET['l'] ?? ''), 0, 60));
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : null; $lon = isset($_GET['lon']) ? (float)$_GET['lon'] : null;
    if ($lat !== null && ($lat < -25 || $lat > 52 || $lon < -65 || $lon > 60)) $lat = $lon = null;
    $where = null; $res = ''; $searched = $l !== '' || $lat !== null;
    if ($lat === null && $l !== '' && ($g = pc_geocode($db, $l))) { $lat = $g['lat']; $lon = $g['lon']; $where = $g['name']; }
    if ($lat !== null && $where === null) $where = pc_nearest_name($db, $lat, $lon) ?: 'votre position';
    if ($lat !== null) {
        $dl = $r / 111; $dg = $r / (111 * max(.2, cos($lat * M_PI / 180)));
        $st = $db->prepare("SELECT j.*, g.lat glat, g.lon glon FROM job_geo g JOIN jobs j ON j.id=g.id WHERE g.lat BETWEEN ? AND ? AND g.lon BETWEEN ? AND ? AND j.status='open'" . ($fc !== '' ? ' AND j.contract=?' : '')
            . ' ORDER BY ((g.lat-?)*(g.lat-?)*12321 + (g.lon-?)*(g.lon-?)*' . (12321 * cos($lat * M_PI / 180) ** 2) . ') ASC, j.created_at DESC LIMIT 400');
        $st->execute(array_merge([$lat - $dl, $lat + $dl, $lon - $dg, $lon + $dg], $fc !== '' ? [$fc] : [], [$lat, $lat, $lon, $lon]));
        $jobs = [];
        foreach ($st as $j) { $j['km'] = pc_dist($lat, $lon, (float)$j['glat'], (float)$j['glon']); if ($j['km'] <= $r) $jobs[] = $j; }
        usort($jobs, fn($a, $b) => [round($a['km']), $b['created_at']] <=> [round($b['km']), $a['created_at']]);
        $n = count($jobs); $jobs = array_slice($jobs, 0, 60);
        $cards = array_map(fn($j) => str_replace('<p class="jc-meta">', '<p class="jc-meta"><span><b>' . ($j['km'] < 1 ? 'moins de 1' : $nf(round($j['km']))) . ' km</b></span>', job_card($j)), $jobs);
        $grid = '';
        if ($cards) { $chunks = [array_splice($cards, 0, 4)]; while ($cards) $chunks[] = array_splice($cards, 0, 12);
            foreach ($chunks as $i => $ch) $grid .= '<div class="grid">' . implode('', $ch) . '</div>' . ($i < count($chunks) - 1 ? job_ad($site) : ''); }
        $res = '<h2 id="offres">' . ($n >= 400 ? 'Plus de 400' : $nf($n)) . ' offre' . ($n > 1 ? 's' : '') . ' à moins de ' . $r . ' km de ' . h($where) . ($fc ? ' en ' . h(JOB_CONTRACTS[$fc]) : '') . '</h2>'
            . ($grid ?: '<p>Aucune offre dans ce rayon. Essayez un rayon plus large.</p>')
            . ($n > 60 ? '<p>Les 60 offres les plus proches sont affichées. Réduisez le rayon ou choisissez un type de contrat pour affiner.</p>' : '');
    } elseif ($l !== '') $res = '<p><b>Ville introuvable.</b> Saisissez le nom exact de la commune ou son code postal, ou utilisez « Me localiser ».</p>';
    $opt = fn(array $vals, $cur, callable $lab) => implode('', array_map(fn($v) => '<option value="' . h((string)$v) . '"' . ((string)$v === (string)$cur ? ' selected' : '') . '>' . h($lab($v)) . '</option>', $vals));
    $geoOn = $lat !== null && $l === '';
    $form = '<form method="get" action="' . PC_PATH . '#offres" class="pc-f" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:16px 0">'
        . '<input type="hidden" name="lat" value="' . ($lat !== null && $l === '' ? h((string)round($lat, 4)) : '') . '"' . ($geoOn ? '' : ' disabled') . '><input type="hidden" name="lon" value="' . ($lon !== null && $l === '' ? h((string)round($lon, 4)) : '') . '"' . ($geoOn ? '' : ' disabled') . '>'
        . '<button type="button" id="pc-geo" style="padding:10px 14px;border-radius:8px;border:0;background:var(--c);color:#fff;font-weight:600;cursor:pointer">📍 Me localiser</button>'
        . '<span>ou</span><input type="text" name="l" value="' . h($l) . '" placeholder="Ville ou code postal" oninput="this.form.lat.disabled=this.form.lon.disabled=true" aria-label="Ville ou code postal" style="padding:10px;border-radius:8px;border:1px solid rgba(127,127,127,.4);min-width:180px;flex:1">'
        . '<select name="r" aria-label="Rayon" style="padding:10px;border-radius:8px">' . $opt([5, 10, 20, 30, 50], $r, fn($v) => $v . ' km') . '</select>'
        . '<select name="contrat" aria-label="Contrat" style="padding:10px;border-radius:8px"><option value="">Tous contrats</option>' . $opt(array_keys(JOB_CONTRACTS), $fc, fn($v) => JOB_CONTRACTS[$v]) . '</select>'
        . '<button type="submit" style="padding:10px 14px;border-radius:8px">Rechercher</button><span id="pc-msg" role="status" style="width:100%;font-size:.9em"></span></form>'
        . '<script>(function(){var f=document.currentScript.previousElementSibling,b=f.querySelector("#pc-geo"),m=f.querySelector("#pc-msg");if(!navigator.geolocation){b.style.display="none";return;}'
        . 'b.addEventListener("click",function(){m.textContent="Localisation en cours…";navigator.geolocation.getCurrentPosition(function(p){var a=f.querySelector("[name=lat]"),o=f.querySelector("[name=lon]");a.disabled=o.disabled=false;'
        . 'a.value=p.coords.latitude.toFixed(4);o.value=p.coords.longitude.toFixed(4);f.querySelector("[name=l]").value="";f.querySelector("[name=l]").disabled=true;f.submit();},function(){m.textContent="Position indisponible : saisissez votre ville.";},{timeout:10000,maximumAge:600000});});})();</script>';
    jobs_cities_ready($db);
    $cities = $db->query('SELECT slug, name, n FROM job_cities ORDER BY n DESC LIMIT 30')->fetchAll();
    $faq = [['Comment trouver une offre d\'emploi près de chez moi ?', 'Cliquez sur « Me localiser » : votre navigateur transmet votre position approximative, et ' . $site['name'] . ' affiche les offres ouvertes les plus proches, triées par distance. Vous pouvez aussi saisir une ville ou un code postal.'],
        ['Ma position est-elle enregistrée ?', 'Non. La position sert uniquement à calculer la distance des offres le temps de la recherche ; elle n\'est ni conservée ni associée à vous.'],
        ['Quelles offres sont incluses ?', 'Les offres France Travail, La bonne alternance et Adzuna dont le lieu de travail est connu, mises à jour plusieurs fois par jour. Les distances sont calculées à vol d\'oiseau depuis le centre de la commune de l\'offre.']];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › Près de chez moi</p>'
        . '<h1>Offres d\'emploi près de chez moi</h1>'
        . '<p class="lead">Trouvez les offres d\'emploi les plus proches de chez vous, triées par distance : localisez-vous en un clic ou saisissez votre ville, puis choisissez le rayon (5 à 50 km).</p>'
        . $form . $res . job_ad($site)
        . (!$searched && $cities ? '<h2>Villes qui recrutent le plus</h2><p>' . city_links($cities) . '.</p>' : '')
        . '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq))
        . jobs_disclaimer();
    $schema = [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], ['Près de chez moi', PC_PATH]]),
        ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)]];
    return layout($site, ['title' => ($searched && $where ? 'Offres d\'emploi près de ' . $where . ' (' . $r . ' km)' : 'Offres d\'emploi près de chez moi : les plus proches, triées par distance') . ' | ' . $site['name'],
        'desc' => 'Les offres d\'emploi les plus proches de chez vous, triées par distance : CDI, CDD, intérim, alternance dans un rayon de 5 à 50 km. Localisation en un clic.',
        'canonical' => 'https://' . $site['host'] . PC_PATH, 'schema' => $schema, 'robots' => $searched ? 'noindex,follow' : null], $body);
}
