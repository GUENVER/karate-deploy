<?php
// Onglet « Statistiques » de l'admin : fréquentation (pages vues mesurées par la fabrique), Search Console (clics,
// impressions, position, requêtes, pages — rapport poussé chaque jour par le hub) et revenus AdSense, pour tous les sites.
declare(strict_types=1);

function fleet_stats(): array
{
    $out = [];
    foreach (registry()->query("SELECT * FROM sites WHERE status<>'deleted' ORDER BY host") as $s) $out[$s['host']] = site_stats($s) + ['name' => $s['name'], 'remote' => null];
    foreach ((array)cfg('remotes', []) as $rm) {
        $ch = curl_init(rtrim($rm['api_base'], '/') . '/_api/stats');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['X-Fabrique-Token: ' . $rm['token']]]);
        foreach ((array)json_decode((string)curl_exec($ch), true) as $st) if (is_array($st) && isset($st['host'])) $out[$st['host']] = $st + ['name' => $st['host'], 'remote' => $rm['api_base']];
        curl_close($ch);
    }
    return $out;
}

function nf($v, int $d = 0): string { return number_format((float)$v, $d, ',', ' '); }
function delta(float $now, float $before): string
{
    if ($before <= 0) return $now > 0 ? '<small class="ok">nouveau</small>' : '';
    $p = 100 * ($now - $before) / $before;
    return '<small class="' . ($p >= 0 ? 'ok' : 'err') . '">' . ($p >= 0 ? '+' : '') . nf($p) . ' %</small>';
}

// Histogramme SVG : $bars = [date => valeur], $line = [date => valeur] (échelle propre), sur les N derniers jours.
function chart(array $bars, array $line = [], int $days = 90, string $lb = 'clics', string $ll = 'pages vues'): string
{
    $dates = [];
    for ($i = $days - 1; $i >= 0; $i--) $dates[] = gmdate('Y-m-d', time() - $i * 86400);
    $w = 1000; $h = 170; $bw = $w / $days;
    $mb = max(1, ...array_map(fn($d) => (float)($bars[$d] ?? 0), $dates));
    $ml = max(1, ...array_map(fn($d) => (float)($line[$d] ?? 0), $dates));
    $svg = '';
    foreach ($dates as $i => $d) {
        $v = (float)($bars[$d] ?? 0); $bh = $v / $mb * ($h - 20);
        $svg .= '<rect x="' . round($i * $bw + 1, 1) . '" y="' . round($h - $bh, 1) . '" width="' . round($bw - 2, 1) . '" height="' . round($bh, 1) . '" fill="#0b6e4f" opacity=".75"><title>' . $d . ' : ' . nf($v) . ' ' . $lb . '</title></rect>';
    }
    if ($line) $svg .= '<polyline fill="none" stroke="#e08a00" stroke-width="2" points="' . implode(' ', array_map(fn($i, $d) => round($i * $bw + $bw / 2, 1) . ',' . round($h - (float)($line[$d] ?? 0) / $ml * ($h - 20), 1), array_keys($dates), $dates)) . '"/>';
    return '<div class="box" style="padding:10px"><svg viewBox="0 0 ' . $w . ' ' . ($h + 18) . '" style="width:100%;height:auto">' . $svg
        . '<text x="0" y="' . ($h + 14) . '" font-size="12" fill="#666">' . $dates[0] . '</text><text x="' . $w . '" y="' . ($h + 14) . '" font-size="12" fill="#666" text-anchor="end">' . end($dates) . '</text></svg>'
        . '<small><span style="color:#0b6e4f">■</span> ' . $lb . ' (max ' . nf($mb) . '/j)' . ($line ? ' · <span style="color:#e08a00">━</span> ' . $ll . ' (max ' . nf($ml) . '/j)' : '') . '</small></div>';
}

function admin_stats(): void
{
    $fleet = fleet_stats();
    $gsc = json_decode((string)setting('gsc_report', ''), true) ?: ['sites' => []];
    $ads = json_decode((string)setting('adsense_report', ''), true) ?: [];
    $earn = fn(array $rep, string $host) => array_sum(array_map(fn($r) => preg_replace('/^www\./', '', $r['domain']) === preg_replace('/^www\./', '', $host) ? (float)$r['earnings'] : 0, $rep['sites'] ?? []));
    $T = ['c28' => 0, 'p28' => 0, 'i28' => 0, 'pv30' => 0, 'pv7' => 0, 'ads30' => 0, 'posw' => 0];
    $clicks = []; $pv = []; $rows = ''; $queries = []; $opp = [];
    foreach ($fleet as $h => $st) {
        $g = $gsc['sites'][$h] ?? [];
        foreach ((array)($g['series'] ?? []) as $d => $v) $clicks[$d] = ($clicks[$d] ?? 0) + $v[0];
        foreach ((array)($st['pv_series'] ?? []) as $d => $v) $pv[$d] = ($pv[$d] ?? 0) + $v;
        $c28 = (int)($g['d28']['clicks'] ?? 0); $i28 = (int)($g['d28']['impr'] ?? 0);
        $T['c28'] += $c28; $T['p28'] += (int)($g['prev28']['clicks'] ?? 0); $T['i28'] += $i28; $T['posw'] += (float)($g['d28']['pos'] ?? 0) * $i28;
        $T['pv30'] += (int)$st['pv_30d']; $T['pv7'] += (int)$st['pv_7d']; $a30 = $earn($ads['d30'] ?? [], $h); $T['ads30'] += $a30;
        foreach ((array)($g['queries'] ?? []) as $q) {
            $queries[] = [$q[0], $q[1], $q[2], $q[3], $h];
            if ($q[3] >= 4.5 && $q[3] <= 20 && $q[2] >= 5) $opp[] = [$q[0], $q[1], $q[2], $q[3], $h];
        }
        $rows .= '<tr><td><a href="/_admin/stats/' . h($h) . '"><b>' . h($h) . '</b></a></td><td>' . nf($st['pv_7d']) . '</td><td>' . nf($st['pv_30d']) . '</td>'
            . '<td>' . nf($g['d7']['clicks'] ?? 0) . '</td><td>' . nf($c28) . ' ' . delta($c28, (float)($g['prev28']['clicks'] ?? 0)) . '</td><td>' . nf($i28) . '</td>'
            . '<td>' . (isset($g['d28']) ? nf($g['d28']['ctr'], 1) . ' %' : '—') . '</td><td>' . (!empty($g['d28']['pos']) ? nf($g['d28']['pos'], 1) : '—') . '</td>'
            . '<td>' . nf($a30, 2) . ' €</td><td>' . (!empty($g['error']) ? '<small class="err">' . h($g['error']) . '</small>' : '') . '</td></tr>';
    }
    usort($queries, fn($a, $b) => [$b[1], $b[2]] <=> [$a[1], $a[2]]);
    usort($opp, fn($a, $b) => $b[2] <=> $a[2]);
    $qt = fn(array $list) => '<table><tr><th>Requête</th><th>Site</th><th>Clics</th><th>Impressions</th><th>Position</th></tr>' . implode('', array_map(fn($q) => '<tr><td>' . h($q[0]) . '</td><td><small>' . h($q[4]) . '</small></td><td>' . nf($q[1]) . '</td><td>' . nf($q[2]) . '</td><td>' . nf($q[3], 1) . '</td></tr>', $list)) . '</table>';
    $body = '<div class="kpi"><div><b>' . nf($T['c28']) . '</b>clics Google /28 j ' . delta($T['c28'], $T['p28']) . '</div><div><b>' . nf($T['i28']) . '</b>impressions /28 j</div>'
        . '<div><b>' . ($T['i28'] ? nf(100 * $T['c28'] / $T['i28'], 1) : 0) . ' %</b>taux de clic</div><div><b>' . ($T['i28'] ? nf($T['posw'] / $T['i28'], 1) : '—') . '</b>position moyenne</div>'
        . '<div><b>' . nf($T['pv7']) . '</b>pages vues /7 j</div><div><b>' . nf($T['pv30']) . '</b>pages vues /30 j</div><div><b>' . nf($T['ads30'], 2) . ' €</b>AdSense /30 j</div></div>'
        . '<p><small>Search Console : données jusqu\'au ' . h($gsc['end'] ?? '—') . ' (mise à jour ' . h($gsc['at'] ?? 'en attente') . ', ~2 jours de décalage chez Google). Pages vues : visiteurs réels mesurés par les sites (robots exclus).</small></p>'
        . '<h2>Clics Google et pages vues — 90 jours, tous sites</h2>' . chart($clicks, $pv)
        . '<h2>Par site</h2><div class="box" style="padding:0;overflow:auto"><table><tr><th>Site</th><th>PV 7 j</th><th>PV 30 j</th><th>Clics 7 j</th><th>Clics 28 j</th><th>Impr. 28 j</th><th>CTR</th><th>Position</th><th>AdSense 30 j</th><th></th></tr>' . $rows . '</table></div>'
        . '<h2>Meilleures requêtes (28 j, tous sites)</h2><div class="box" style="padding:0;overflow:auto">' . $qt(array_slice($queries, 0, 50)) . '</div>'
        . '<h2>Opportunités : requêtes en page 1-2 à pousser</h2><p><small>Positions 5 à 20 avec des impressions : c\'est là qu\'un enrichissement fait gagner le plus de clics (traité chaque mercredi automatiquement).</small></p><div class="box" style="padding:0;overflow:auto">' . $qt(array_slice($opp, 0, 40)) . '</div>';
    admin_page('Statistiques', $body);
}

function admin_stats_site(string $h): void
{
    $fleet = fleet_stats();
    $st = $fleet[$h] ?? null;
    if (!$st) { admin_page('Introuvable', '<p>Site inconnu.</p>'); return; }
    $g = (json_decode((string)setting('gsc_report', ''), true) ?: ['sites' => []])['sites'][$h] ?? [];
    $ads = json_decode((string)setting('adsense_report', ''), true) ?: [];
    $earn = fn(array $rep) => array_sum(array_map(fn($r) => preg_replace('/^www\./', '', $r['domain']) === preg_replace('/^www\./', '', $h) ? (float)$r['earnings'] : 0, $rep['sites'] ?? []));
    $series = (array)($g['series'] ?? []);
    $tbl = fn(array $rows, string $first) => '<table><tr><th>' . $first . '</th><th>Clics</th><th>Impressions</th><th>CTR</th><th>Position</th></tr>' . implode('', array_map(fn($r) => '<tr><td>' . ($first === 'Page' ? '<a href="https://' . h($h) . h($r[0]) . '" target="_blank">' . h($r[0]) . '</a>' : h($r[0])) . '</td><td>' . nf($r[1]) . '</td><td>' . nf($r[2]) . '</td><td>' . ($r[2] ? nf(100 * $r[1] / $r[2], 1) : 0) . ' %</td><td>' . nf($r[3], 1) . '</td></tr>', $rows)) . '</table>';
    $body = '<p><a href="/_admin/stats">← Tous les sites</a> · <a href="https://' . h($h) . '/" target="_blank">voir le site</a>' . ($st['remote'] ? '' : ' · <a href="/_admin/site/' . h($h) . '">gérer le site</a>') . '</p>'
        . (!empty($g['error']) ? '<p class="err">Search Console : ' . h($g['error']) . '</p>' : '')
        . '<div class="kpi"><div><b>' . nf($g['d28']['clicks'] ?? 0) . '</b>clics /28 j ' . delta((float)($g['d28']['clicks'] ?? 0), (float)($g['prev28']['clicks'] ?? 0)) . '</div><div><b>' . nf($g['d28']['impr'] ?? 0) . '</b>impressions /28 j</div>'
        . '<div><b>' . nf($g['d28']['ctr'] ?? 0, 1) . ' %</b>taux de clic</div><div><b>' . (!empty($g['d28']['pos']) ? nf($g['d28']['pos'], 1) : '—') . '</b>position moyenne</div>'
        . '<div><b>' . nf($g['d90']['clicks'] ?? 0) . '</b>clics /90 j</div><div><b>' . nf($st['pv_7d']) . '</b>pages vues /7 j</div><div><b>' . nf($st['pv_30d']) . '</b>pages vues /30 j</div>'
        . '<div><b>' . nf($earn($ads['d30'] ?? []), 2) . ' €</b>AdSense /30 j</div><div><b>' . nf($st['posts']) . '</b>articles</div></div>'
        . '<h2>Clics Google et pages vues — 90 jours</h2>' . chart(array_map(fn($v) => $v[0], $series), (array)($st['pv_series'] ?? []))
        . '<h2>Impressions Google — 90 jours</h2>' . chart(array_map(fn($v) => $v[1], $series), [], 90, 'impressions')
        . '<div class="row"><div><h2>Requêtes (28 j)</h2><div class="box" style="padding:0;overflow:auto">' . $tbl((array)($g['queries'] ?? []), 'Requête') . '</div></div>'
        . '<div><h2>Pages (28 j)</h2><div class="box" style="padding:0;overflow:auto">' . $tbl((array)($g['pages'] ?? []), 'Page') . '</div></div></div>'
        . (!empty($g['devices']) ? '<h2>Appareils (28 j)</h2><div class="box" style="padding:0">' . '<table><tr><th>Appareil</th><th>Clics</th><th>Impressions</th></tr>' . implode('', array_map(fn($r) => '<tr><td>' . h(['DESKTOP' => 'Ordinateur', 'MOBILE' => 'Mobile', 'TABLET' => 'Tablette'][$r[0]] ?? $r[0]) . '</td><td>' . nf($r[1]) . '</td><td>' . nf($r[2]) . '</td></tr>', $g['devices'])) . '</table></div>' : '');
    admin_page('Statistiques · ' . $h, $body);
}
