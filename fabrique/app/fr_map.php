<?php
// Carte de France des régions (SVG léger, liens réels vers les pages région). Tracés : Admin Express (IGN, Licence Ouverte)
// via france-geojson, simplifiés dans app/fr_regions.json.
declare(strict_types=1);

function fr_map(array $counts, string $prefix, string $unit = 'offres'): string
{
    static $geo = null;
    $geo ??= json_decode((string)@file_get_contents(__DIR__ . '/fr_regions.json'), true);
    if (!$geo) return '';
    $max = max(1, ...array_values($counts ?: [1]));
    $nf = fn($n) => number_format($n, 0, ',', ' ');
    $short = fn($n) => $n >= 10000 ? round($n / 1000) . ' k' : ($n >= 1000 ? number_format($n / 1000, 1, ',', '') . ' k' : (string)$n);
    $paths = ''; $labels = '';
    foreach ($geo['regions'] as $code => $r) {
        if (!isset(JOB_REGIONS[$code])) continue;
        [$slug, $name] = JOB_REGIONS[$code];
        $n = (int)($counts[$code] ?? 0);
        $op = $n ? 0.18 + 0.72 * (log(1 + $n) / log(1 + $max)) : 0.06;
        $paths .= '<a href="' . h($prefix . $slug . '/') . '" aria-label="' . h($name . ' : ' . $nf($n) . ' ' . $unit) . '"><path d="' . $r['d'] . '" fill-opacity="' . round($op, 2) . '"><title>' . h($name . ' — ' . $nf($n) . ' ' . $unit) . '</title></path></a>';
        if ($n) $labels .= '<text x="' . $r['cx'] . '" y="' . $r['cy'] . '">' . h($short($n)) . '</text>';
    }
    $drom = [];
    foreach (['01', '02', '03', '04', '06'] as $code) if (!empty($counts[$code])) $drom[] = '<a href="' . h($prefix . JOB_REGIONS[$code][0] . '/') . '">' . h(JOB_REGIONS[$code][1]) . '</a> (' . $nf((int)$counts[$code]) . ')';
    return '<figure class="frmap"><svg viewBox="0 0 ' . (int)$geo['w'] . ' ' . (int)$geo['h'] . '" role="img" aria-labelledby="frmap-t"><title id="frmap-t">Carte des ' . h($unit) . ' par région</title>'
        . '<g class="frm-r">' . $paths . '</g><g class="frm-l" aria-hidden="true">' . $labels . '</g></svg>'
        . '<figcaption>Cliquez sur une région' . ($drom ? ' · Outre-mer : ' . implode(', ', $drom) : '') . '</figcaption></figure>';
}

function fr_map_css(): string
{
    return '.frmap{margin:18px auto 8px;max-width:620px}.frmap svg{width:100%;height:auto;display:block}'
        . '.frm-r path{fill:var(--c);stroke:#fff;stroke-width:1.2;transition:fill-opacity .15s}.frm-r a:hover path,.frm-r a:focus path{fill-opacity:1;stroke:#ffb400;stroke-width:2}'
        . '.frm-l text{font:700 13px system-ui,sans-serif;fill:#fff;text-anchor:middle;dominant-baseline:middle;pointer-events:none;paint-order:stroke;stroke:rgba(0,0,0,.35);stroke-width:2px}'
        . '.frmap figcaption{text-align:center;font-size:.85rem;color:var(--m);margin-top:6px}'
        . '.cnt{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 18px}.cnt span,.cnt a{background:var(--s);border-radius:20px;padding:6px 12px;font-size:.9rem;text-decoration:none;color:var(--t)}.cnt b{color:var(--c)}'
        . '@media(max-width:700px){.frmap{max-width:100%}.frm-l text{font-size:15px}}';
}

// Puces « libellé (nombre) », avec lien optionnel.
function count_chips(array $rows): string
{
    $o = '';
    foreach ($rows as [$label, $n, $href]) {
        $in = h($label) . ' <b>' . number_format((int)$n, 0, ',', ' ') . '</b>';
        $o .= $href ? '<a href="' . h($href) . '">' . $in . '</a>' : '<span>' . $in . '</span>';
    }
    return '<div class="cnt">' . $o . '</div>';
}
