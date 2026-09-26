<?php
// Diagnostics de performance énergétique (base DPE logements existants de l'ADEME, Licence Ouverte) :
// répartition des étiquettes A→G par commune et par département, part des « passoires thermiques » (F et G).
// Dépend de fuel.php (DEPTS, dept_slug, dept_by_slug, city_name).
declare(strict_types=1);

const DPE_API = 'https://data.ademe.fr/data-fair/api/v1/datasets/dpe03existant/values_agg';
const DPE_MIN = 30; // pas de page commune sous 30 diagnostics (statistique trop fragile)
const DPE_COLORS = ['A' => '#009c6d', 'B' => '#52b153', 'C' => '#a5cc74', 'D' => '#f4e70f', 'E' => '#f0b50f', 'F' => '#eb8235', 'G' => '#d7221f'];

function dpe_db(string $host): PDO
{
    $db = site_db($host);
    static $init = [];
    if (empty($init[$host])) {
        $db->exec("CREATE TABLE IF NOT EXISTS dpe_stats(insee TEXT PRIMARY KEY, dept TEXT, city TEXT, city_slug TEXT, cp TEXT, n INTEGER, a INTEGER, b INTEGER, c INTEGER, d INTEGER, e INTEGER, f INTEGER, g INTEGER, conso REAL, at TEXT);
        CREATE INDEX IF NOT EXISTS ix_dpe_dept ON dpe_stats(dept, city_slug);");
        $init[$host] = 1;
    }
    return $db;
}

function dpe_get(string $url): array
{
    for ($i = 0; $i < 3; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_USERAGENT => 'Mozilla/5.0 (fabrique)']);
        $j = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (is_array($j)) return $j;
        sleep(5);
    }
    throw new RuntimeException('API indisponible : ' . $url);
}

function dpe_sync(array $site, callable $log): array
{
    $db = dpe_db($site['host']);
    $stamp = gmdate('Y-m-d H:i:s'); $n = 0;
    $up = $db->prepare('INSERT OR REPLACE INTO dpe_stats(insee,dept,city,city_slug,cp,n,a,b,c,d,e,f,g,conso,at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach (array_keys(DEPTS) as $dept) {
        $dept = (string)$dept;
        try {
            $communes = [];
            foreach (dpe_get('https://geo.api.gouv.fr/departements/' . $dept . '/communes?fields=nom,code,codesPostaux') as $c) $communes[$c['code']] = $c;
            $q = '&qs=' . rawurlencode('code_departement_ban:"' . $dept . '"');
            $labels = dpe_get(DPE_API . '?field=code_insee_ban;etiquette_dpe&agg_size=1000;7' . $q);
            $conso = [];
            foreach (dpe_get(DPE_API . '?field=code_insee_ban&agg_size=1000&metric=avg&metric_field=conso_5_usages_par_m2_ep' . $q)['aggs'] ?? [] as $a) $conso[$a['value']] = (float)$a['metric'];
            $db->beginTransaction();
            foreach ($labels['aggs'] ?? [] as $a) {
                $c = $communes[$a['value']] ?? null;
                if (!$c) continue;
                $k = array_fill_keys(['A', 'B', 'C', 'D', 'E', 'F', 'G'], 0);
                foreach ($a['aggs'] ?? [] as $x) if (isset($k[$x['value']])) $k[$x['value']] = (int)$x['total'];
                $tot = array_sum($k);
                if (!$tot) continue;
                $city = city_name($c['nom']);
                $up->execute([$a['value'], $dept, $city, slugify($city, 60), $c['codesPostaux'][0] ?? '', $tot, $k['A'], $k['B'], $k['C'], $k['D'], $k['E'], $k['F'], $k['G'], round($conso[$a['value']] ?? 0, 1), $stamp]);
                $n++;
            }
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); $log("$dept : " . $e->getMessage()); }
        usleep(500000);
    }
    cache_clear($site['host']);
    return ['communes' => $n];
}

// ---------- Rendu ----------

function dpe_pct(array $r, string $l): float { return $r['n'] ? 100 * $r[strtolower($l)] / $r['n'] : 0; }
function dpe_passoires(array $r): float { return $r['n'] ? 100 * ($r['f'] + $r['g']) / $r['n'] : 0; }
function pct(float $v): string { return number_format($v, 1, ',', ' ') . ' %'; }

function dpe_bars(array $r): string
{
    $o = '<div class="dpe">';
    foreach (DPE_COLORS as $l => $col) {
        $p = dpe_pct($r, $l);
        $o .= '<div style="display:flex;align-items:center;gap:8px;margin:3px 0"><b style="width:22px;text-align:center;color:#fff;background:' . $col . ';border-radius:4px">' . $l . '</b>'
            . '<span style="flex:1;background:var(--s);border-radius:4px;height:18px"><span style="display:block;height:18px;width:' . round($p, 1) . '%;background:' . $col . ';border-radius:4px"></span></span>'
            . '<span style="width:120px;font-size:.85rem">' . pct($p) . ' <small>(' . number_format((int)$r[strtolower($l)], 0, ',', ' ') . ')</small></span></div>';
    }
    return $o . '</div>';
}

function dpe_sum(PDO $db, string $where = '', array $args = []): array
{
    $st = $db->prepare("SELECT SUM(n) n, SUM(a) a, SUM(b) b, SUM(c) c, SUM(d) d, SUM(e) e, SUM(f) f, SUM(g) g, SUM(conso*n)/SUM(n) conso FROM dpe_stats $where");
    $st->execute($args);
    return array_map(fn($v) => (float)$v, $st->fetch());
}

function dpe_footer(): string
{
    return '<p class="disc">Source : base des diagnostics de performance énergétique des logements existants (DPE depuis juillet 2021), ADEME, Licence Ouverte Etalab, mise à jour chaque mois. '
        . 'Statistiques sur les diagnostics réalisés (ventes, locations), pas sur l\'ensemble du parc de logements. Consommation en énergie primaire, 5 usages, par m² et par an.</p>';
}

function dpe_guides(array $site, int $n = 6): string
{
    $g = site_db($site['host'])->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT $n")->fetchAll();
    return $g ? '<h2>Nos guides pour rénover et économiser</h2><ul>' . implode('', array_map(fn($p) => '<li><a href="' . h($p['path']) . '">' . h($p['title']) . '</a></li>', $g)) . '</ul>' : '';
}

function page_dpe_france(array $site): string
{
    $db = dpe_db($site['host']);
    $fr = dpe_sum($db);
    if (!$fr['n']) return '';
    $depts = $db->query('SELECT dept, SUM(n) n, 100.0*SUM(f+g)/SUM(n) pf, SUM(conso*n)/SUM(n) conso FROM dpe_stats GROUP BY dept ORDER BY pf DESC')->fetchAll();
    $body = '<h1 style="margin-top:28px">DPE : les passoires thermiques en France, département par département</h1>'
        . '<p class="lead">Sur ' . number_format((int)$fr['n'], 0, ',', ' ') . ' diagnostics de performance énergétique réalisés depuis juillet 2021, <strong>' . pct(dpe_passoires($fr)) . '</strong> des logements sont classés F ou G (passoires thermiques). Consommation moyenne : ' . round($fr['conso']) . ' kWh/m²/an.</p>'
        . dpe_bars($fr)
        . '<h2>Part de passoires thermiques par département</h2><table><thead><tr><th>Département</th><th>DPE analysés</th><th>F + G</th><th>Conso. moyenne</th></tr></thead><tbody>'
        . implode('', array_map(fn($d) => '<tr><td><a href="/dpe/' . h(dept_slug((string)$d['dept'])) . '/">' . h((string)(DEPTS[$d['dept']] ?? $d['dept'])) . ' (' . h((string)$d['dept']) . ')</a></td><td>' . number_format((int)$d['n'], 0, ',', ' ') . '</td><td>' . pct((float)$d['pf']) . '</td><td>' . round((float)$d['conso']) . ' kWh/m²</td></tr>', $depts))
        . '</tbody></table>' . dpe_guides($site) . dpe_footer();
    return layout($site, ['title' => 'DPE par département : part des passoires thermiques F et G en France | ' . $site['name'],
        'desc' => 'Répartition des étiquettes DPE (A à G) en France et dans chaque département : ' . pct(dpe_passoires($fr)) . ' de passoires thermiques, consommation moyenne, classement.',
        'canonical' => 'https://' . $site['host'] . '/dpe/', 'schema' => [breadcrumbs($site, [['DPE par commune', '/dpe/']])]], $body);
}

function page_dpe_dept(array $site, string $dept): string
{
    $db = dpe_db($site['host']);
    $d = dpe_sum($db, 'WHERE dept=?', [$dept]);
    if (!$d['n']) return '';
    $fr = dpe_sum($db);
    $name = DEPTS[$dept] ?? $dept; $url = '/dpe/' . dept_slug($dept) . '/';
    $st = $db->prepare('SELECT * FROM dpe_stats WHERE dept=? AND n>=? ORDER BY n DESC'); $st->execute([$dept, DPE_MIN]);
    $cities = $st->fetchAll();
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/dpe/">DPE</a> › ' . h($name) . '</p>'
        . '<h1>DPE dans le département ' . h($name) . ' (' . h($dept) . ') : étiquettes énergie et passoires thermiques</h1>'
        . '<p class="lead">' . number_format((int)$d['n'], 0, ',', ' ') . ' diagnostics analysés : <strong>' . pct(dpe_passoires($d)) . '</strong> de logements classés F ou G (France : ' . pct(dpe_passoires($fr)) . '). Consommation moyenne : ' . round($d['conso']) . ' kWh/m²/an.</p>'
        . dpe_bars($d)
        . '<h2>DPE par commune</h2><table><thead><tr><th>Commune</th><th>DPE</th><th>A + B</th><th>F + G</th><th>Conso.</th></tr></thead><tbody>'
        . implode('', array_map(fn($c) => '<tr><td><a href="' . $url . h($c['city_slug']) . '/">' . h($c['city']) . '</a></td><td>' . $c['n'] . '</td><td>' . pct(dpe_pct($c, 'A') + dpe_pct($c, 'B')) . '</td><td>' . pct(dpe_passoires($c)) . '</td><td>' . round((float)$c['conso']) . '</td></tr>', $cities))
        . '</tbody></table>' . dpe_guides($site) . dpe_footer();
    return layout($site, ['title' => 'DPE ' . $name . ' (' . $dept . ') : passoires thermiques par commune | ' . $site['name'],
        'desc' => "Diagnostics de performance énergétique dans le département $name : " . pct(dpe_passoires($d)) . ' de logements F ou G, répartition A à G et classement des communes.',
        'canonical' => 'https://' . $site['host'] . $url, 'schema' => [breadcrumbs($site, [['DPE', '/dpe/'], [$name, $url]])]], $body);
}

function page_dpe_city(array $site, string $dept, string $slug): string
{
    $db = dpe_db($site['host']);
    $st = $db->prepare('SELECT * FROM dpe_stats WHERE dept=? AND city_slug=? AND n>=? ORDER BY n DESC LIMIT 1'); $st->execute([$dept, $slug, DPE_MIN]);
    $c = $st->fetch();
    if (!$c) return '';
    $d = dpe_sum($db, 'WHERE dept=?', [$dept]); $fr = dpe_sum($db);
    $name = DEPTS[$dept] ?? $dept; $durl = '/dpe/' . dept_slug($dept) . '/'; $url = $durl . $slug . '/';
    $pf = dpe_passoires($c); $pd = dpe_passoires($d);
    $counts = [];
    foreach (array_keys(DPE_COLORS) as $l) $counts[$l] = (int)$c[strtolower($l)];
    arsort($counts);
    $mode = (string)array_key_first($counts);
    $faq = [
        ['q' => 'Combien de passoires thermiques à ' . $c['city'] . ' ?', 'a' => pct($pf) . ' des ' . $c['n'] . ' logements diagnostiqués à ' . $c['city'] . ' sont classés F ou G, contre ' . pct($pd) . ' dans le département ' . $name . ' et ' . pct(dpe_passoires($fr)) . ' en France.'],
        ['q' => 'Quelle est l\'étiquette DPE la plus fréquente à ' . $c['city'] . ' ?', 'a' => 'La classe ' . $mode . ' est la plus fréquente (' . pct(dpe_pct($c, $mode)) . ' des diagnostics).'],
        ['q' => 'Quelle est la consommation moyenne des logements à ' . $c['city'] . ' ?', 'a' => 'Environ ' . round((float)$c['conso']) . ' kWh d\'énergie primaire par m² et par an, contre ' . round($d['conso']) . ' kWh/m² en moyenne dans le département.'],
        ['q' => 'Peut-on encore louer un logement classé G ?', 'a' => 'Depuis le 1er janvier 2025, les logements classés G ne peuvent plus faire l\'objet d\'un nouveau bail ; l\'interdiction s\'étendra aux logements F en 2028 puis E en 2034 (loi Climat et résilience).'],
    ];
    $near = $db->prepare('SELECT city, city_slug FROM dpe_stats WHERE dept=? AND n>=? AND insee<>? ORDER BY n DESC LIMIT 15'); $near->execute([$dept, DPE_MIN, $c['insee']]);
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/dpe/">DPE</a> › <a href="' . $durl . '">' . h($name) . '</a> › ' . h($c['city']) . '</p>'
        . '<h1>DPE à ' . h($c['city']) . ' (' . h($c['cp'] ?: $dept) . ') : performance énergétique des logements</h1>'
        . '<p class="lead">' . $c['n'] . ' diagnostics de performance énergétique réalisés à ' . h($c['city']) . ' depuis juillet 2021 : <strong>' . pct($pf) . ' de passoires thermiques</strong> (F et G), '
        . ($pf > $pd ? 'plus' : 'moins') . ' que la moyenne du département (' . pct($pd) . '). Consommation moyenne : ' . round((float)$c['conso']) . ' kWh/m²/an.</p>'
        . dpe_bars($c)
        . '<section class="faq"><h2>Questions fréquentes</h2>' . implode('', array_map(fn($x) => '<details><summary>' . h($x['q']) . '</summary><p>' . h($x['a']) . '</p></details>', $faq)) . '</section>'
        . '<h2>Autres communes du département ' . h($name) . '</h2><p>' . implode(' · ', array_map(fn($x) => '<a href="' . $durl . h($x['city_slug']) . '/">' . h($x['city']) . '</a>', $near->fetchAll())) . '</p>'
        . dpe_guides($site, 4) . dpe_footer();
    return layout($site, ['title' => 'DPE ' . $c['city'] . ' : ' . pct($pf) . ' de passoires thermiques | ' . $site['name'],
        'desc' => 'Performance énergétique des logements à ' . $c['city'] . ' (' . $name . ') : répartition des étiquettes DPE A à G sur ' . $c['n'] . ' diagnostics, part de passoires thermiques et consommation moyenne.',
        'canonical' => 'https://' . $site['host'] . $url,
        'schema' => [breadcrumbs($site, [['DPE', '/dpe/'], [$name, $durl], [$c['city'], $url]]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x['a']]], $faq)]]], $body);
}

function dpe_home_block(array $site): string
{
    $db = dpe_db($site['host']);
    $fr = dpe_sum($db);
    if (!$fr['n']) return '';
    $depts = $db->query('SELECT DISTINCT dept FROM dpe_stats ORDER BY dept')->fetchAll(PDO::FETCH_COLUMN);
    return '<h2 style="margin-top:28px">Le DPE de votre commune</h2><p>' . pct(dpe_passoires($fr)) . ' des logements diagnostiqués en France sont des passoires thermiques (F ou G). Et chez vous ?</p>' . dpe_bars($fr)
        . '<details><summary><strong>Choisir un département</strong></summary><p>' . implode(' · ', array_map(fn($d) => '<a href="/dpe/' . h(dept_slug((string)$d)) . '/">' . h((string)(DEPTS[$d] ?? $d)) . '</a>', $depts)) . '</p></details>';
}

function out_sitemap_dpe(array $site): void
{
    $db = dpe_db($site['host']);
    $day = substr((string)$db->query('SELECT MAX(at) FROM dpe_stats')->fetchColumn(), 0, 10) ?: gmdate('Y-m-d');
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://' . $site['host'] . '/dpe/</loc><lastmod>' . $day . '</lastmod></url>';
    foreach ($db->query('SELECT DISTINCT dept FROM dpe_stats') as $r) echo '<url><loc>https://' . $site['host'] . '/dpe/' . dept_slug((string)$r['dept']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    $st = $db->prepare('SELECT dept, city_slug FROM dpe_stats WHERE n>=? GROUP BY dept, city_slug'); $st->execute([DPE_MIN]);
    foreach ($st as $r) echo '<url><loc>https://' . $site['host'] . '/dpe/' . dept_slug((string)$r['dept']) . '/' . h($r['city_slug']) . '/</loc><lastmod>' . $day . '</lastmod></url>';
    echo '</urlset>';
}
