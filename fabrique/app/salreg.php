<?php
// Salaire d'un métier dans une région (/salaire/<métier>/<région>/), calculé sur les salaires réels des offres ouvertes.
declare(strict_types=1);

const SALREG_MIN = 5;

function page_salaire_region(array $site, string $mslug, string $regSlug): string
{
    $reg = region_by_slug($regSlug); if (!$reg) return '';
    $db = jobs_db($site['host']); metier_tables($db);
    $st = $db->prepare('SELECT * FROM job_metiers WHERE slug=?'); $st->execute([$mslug]);
    if (!($m = $st->fetch())) return '';
    $all = metier_jobs($db, $mslug);
    $jobs = array_values(array_filter($all, fn($j) => (string)$j['region'] === $reg['code']));
    $sal = metier_salary_stats($jobs);
    if (!$sal || $sal['n'] < SALREG_MIN) return '';
    $nat = metier_salary_stats($all);
    $name = (string)$m['name']; $l = mb_strtolower($name); $rn = $reg['name'];
    $net = metier_fmt_eur($sal['med'] * 0.78); $year = metier_fmt_eur($sal['med'] * 12);
    $diff = $nat && $nat['med'] > 0 ? (int)round(100 * ($sal['med'] - $nat['med']) / $nat['med']) : null;
    $cmp = $diff === null ? '' : ($diff === 0 ? 'au niveau de la médiane nationale' : (abs($diff) . ' % ' . ($diff > 0 ? 'au-dessus' : 'en dessous') . ' de la médiane nationale (' . metier_fmt_eur($nat['med']) . ')'));
    // autres régions pour ce métier
    $byR = []; foreach ($all as $j) $byR[$j['region']][] = $j;
    $rows = '';
    foreach (JOB_REGIONS as $code => [$rs, $rname]) if (!empty($byR[$code]) && ($s = metier_salary_stats($byR[$code])) && $s['n'] >= SALREG_MIN)
        $rows .= '<tr><td>' . ((string)$code === $reg['code'] ? '<b>' . h($rname) . '</b>' : '<a href="/salaire/' . h($mslug) . '/' . $rs . '/">' . h($rname) . '</a>') . '</td><td>' . metier_fmt_eur($s['med']) . '</td><td>' . (int)$s['n'] . '</td></tr>';
    usort($jobs, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
    $faq = [['Quel est le salaire d\'un ' . $l . ' en ' . $rn . ' ?', 'D\'après ' . $sal['n'] . ' offres d\'emploi ouvertes en ' . $rn . ' qui indiquent une rémunération, le salaire médian proposé est de ' . metier_fmt_eur($sal['med']) . ' brut par mois (environ ' . $net . ' net)' . ($cmp ? ', ' . $cmp : '') . '.'],
        ['Quelle fourchette de salaire en ' . $rn . ' ?', 'La moitié des offres propose entre ' . metier_fmt_eur($sal['p25']) . ' et ' . metier_fmt_eur($sal['p75']) . ' brut par mois.'],
        ['Combien gagne un ' . $l . ' par an en ' . $rn . ' ?', 'Environ ' . $year . ' brut par an sur la base du salaire médian, hors primes et 13e mois.']];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/salaire/">Salaires</a> › <a href="/salaire/' . h($mslug) . '/">' . h($name) . '</a> › ' . h($rn) . '</p>'
        . '<h1>Salaire ' . h($l) . ' en ' . h($rn) . ' : ' . metier_fmt_eur($sal['med']) . ' brut par mois</h1>'
        . '<p class="lead">Calculé sur ' . (int)$sal['n'] . ' offres d\'emploi ouvertes aujourd\'hui en ' . h($rn) . ' : médiane <strong>' . metier_fmt_eur($sal['med']) . ' brut par mois</strong> (environ ' . $net . ' net)' . ($cmp ? ', ' . h($cmp) : '') . '.</p>'
        . '<div class="kpi"><div><b>' . metier_fmt_eur($sal['med']) . '</b>médiane brute/mois</div><div><b>' . $net . '</b>net estimé/mois</div><div><b>' . metier_fmt_eur($sal['p25']) . '</b>bas de fourchette</div><div><b>' . metier_fmt_eur($sal['p75']) . '</b>haut de fourchette</div></div>'
        . job_ad($site)
        . '<h2>Offres de ' . h($l) . ' en ' . h($rn) . '</h2>' . job_grid($site, array_slice($jobs, 0, 12))
        . '<p><a href="/emploi/' . h($mslug) . '/">Toutes les offres ' . h(metier_de($l)) . '</a> · <a href="/offres-emploi/' . h($regSlug) . '/">Toutes les offres en ' . h($rn) . '</a></p>'
        . ($rows ? '<h2>Salaire ' . h($l) . ' dans les autres régions</h2><table><tr><th>Région</th><th>Médiane brute</th><th>Offres</th></tr>' . $rows . '</table>' : '')
        . '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($q) => '<h3>' . h($q[0]) . '</h3><p>' . h($q[1]) . '</p>', $faq))
        . '<p class="disc">Salaires bruts mensuels annoncés dans les offres (hors primes), ramenés à un équivalent mensuel temps plein ; net estimé à 78 % du brut. Mise à jour : ' . date_fr(now()) . '.</p>';
    return layout($site, ['title' => 'Salaire ' . $l . ' en ' . $rn . ' : ' . metier_fmt_eur($sal['med']) . ' brut/mois (' . date('Y') . ') | ' . $site['name'],
        'desc' => mb_strimwidth('Salaire ' . $l . ' en ' . $rn . ' : ' . metier_fmt_eur($sal['med']) . ' brut par mois en médiane (' . $net . ' net), fourchette ' . metier_fmt_eur($sal['p25']) . ' – ' . metier_fmt_eur($sal['p75']) . '. Calculé sur ' . $sal['n'] . ' offres réelles.', 0, 158, '…'),
        'canonical' => 'https://' . $site['host'] . '/salaire/' . $mslug . '/' . $regSlug . '/',
        'schema' => [breadcrumbs($site, [['Salaires', '/salaire/'], [$name, '/salaire/' . $mslug . '/'], [$rn, '/salaire/' . $mslug . '/' . $regSlug . '/']]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]]], $body);
}

// Couples métier × région qui ont une page (mêmes calculs que la page), mis en cache 6 h.
function salreg_sitemap_urls(array $site): array
{
    $cf = cfg('data_dir') . '/cache/' . $site['host'] . '.salreg.json';
    if (is_file($cf) && filemtime($cf) > time() - 21600 && is_array($u = json_decode((string)file_get_contents($cf), true))) return $u;
    $u = [];
    try { $db = jobs_db($site['host']); metier_tables($db);
        foreach ($db->query('SELECT slug FROM job_metier_sal')->fetchAll(PDO::FETCH_COLUMN) as $ms) {
            $by = []; foreach (metier_jobs($db, (string)$ms) as $j) $by[$j['region']][] = $j;
            foreach ($by as $r => $js) if (isset(JOB_REGIONS[$r]) && ($st = metier_salary_stats($js)) && $st['n'] >= SALREG_MIN) $u[] = '/salaire/' . $ms . '/' . JOB_REGIONS[$r][0] . '/';
        }
        @file_put_contents($cf, json_encode($u));
    } catch (Throwable $e) {}
    return $u;
}
