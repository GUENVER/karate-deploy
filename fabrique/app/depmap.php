<?php
// Carte des départements (SVG léger, tracés Admin Express IGN via france-geojson, Licence Ouverte, simplifiés dans app/fr_deps.json).
// Chaque département renvoie vers la même catégorie de page, restreinte au département ($href).
declare(strict_types=1);

function fr_dep_map(array $counts, callable $href, string $unit = 'offres', array $only = [], string $caption = 'Cliquez sur un département'): string
{
    if (!function_exists('mod_on') || !mod_on('cartes')) return '';
    static $geo = null;
    $geo ??= json_decode((string)@file_get_contents(__DIR__ . '/fr_deps.json'), true);
    if (!$geo || !$counts) return '';
    $codes = array_map('strval', $only ? array_values(array_filter($only, fn($c) => isset($geo['deps'][$c]))) : array_keys($geo['deps']));
    if (!$codes) return fr_dep_list($counts, $href, $unit);
    $bb = [1e9, 1e9, -1e9, -1e9];
    foreach ($codes as $c) { $b = $geo['deps'][$c]['bb']; $bb = [min($bb[0], $b[0]), min($bb[1], $b[1]), max($bb[2], $b[2]), max($bb[3], $b[3])]; }
    $pad = $only ? 6 : 2; $vb = [floor($bb[0] - $pad), floor($bb[1] - $pad), ceil($bb[2] - $bb[0] + 2 * $pad), ceil($bb[3] - $bb[1] + 2 * $pad)];
    $sc = $vb[2] / 600; // taille des étiquettes proportionnelle au cadrage
    $max = max(1, ...array_values(array_intersect_key($counts, array_flip($codes)) ?: [1]));
    $nf = fn($n) => number_format($n, 0, ',', ' ');
    $short = fn($n) => $n >= 10000 ? round($n / 1000) . ' k' : ($n >= 1000 ? number_format($n / 1000, 1, ',', '') . ' k' : (string)$n);
    $paths = ''; $labels = '';
    foreach ($codes as $c) {
        $d = $geo['deps'][$c]; $n = (int)($counts[$c] ?? 0);
        $op = $n ? 0.15 + 0.75 * (log(1 + $n) / log(1 + $max)) : 0.05;
        $pth = '<path d="' . $d['d'] . '" fill-opacity="' . round($op, 2) . '"><title>' . h($d['n'] . ' (' . $c . ') — ' . $nf($n) . ' ' . $unit) . '</title></path>';
        $u = $n ? (string)$href((string)$c) : '';
        $paths .= $u !== '' ? '<a href="' . h($u) . '" aria-label="' . h($d['n'] . ' : ' . $nf($n) . ' ' . $unit) . '">' . $pth . '</a>' : $pth;
        if ($n && ($only || $n >= 0.04 * $max)) $labels .= '<text x="' . $d['cx'] . '" y="' . $d['cy'] . '" style="font-size:' . round(9 * max(.45, $sc), 1) . 'px">' . h($short($n)) . '</text>';
    }
    $dom = [];
    if (!$only) foreach (['971' => 'Guadeloupe', '972' => 'Martinique', '973' => 'Guyane', '974' => 'La Réunion', '976' => 'Mayotte'] as $c => $nm) if (!empty($counts[$c]) && ($u = (string)$href((string)$c)) !== '') $dom[] = '<a href="' . h($u) . '">' . h($nm) . '</a> (' . $nf((int)$counts[$c]) . ')';
    return '<figure class="frmap depmap"><svg viewBox="' . implode(' ', $vb) . '" role="img" aria-label="Carte des ' . h($unit) . ' par département"><g class="frm-r">' . $paths . '</g><g class="frm-l" aria-hidden="true">' . $labels . '</g></svg>'
        . '<figcaption>' . h($caption) . ($dom ? ' · Outre-mer : ' . implode(', ', $dom) : '') . '</figcaption></figure>'
        . '<style>.depmap svg{max-width:560px}.depmap .frm-r path{stroke-width:.6}</style>';
}

// Repli sans carte (outre-mer, etc.) : liste de liens
function fr_dep_list(array $counts, callable $href, string $unit): string
{
    arsort($counts); $o = [];
    foreach ($counts as $c => $n) if ($n && ($u = (string)$href((string)$c)) !== '') $o[] = '<a href="' . h($u) . '">' . h((defined('DEPTS') ? (DEPTS[$c] ?? $c) : $c)) . '</a> (' . number_format((int)$n, 0, ',', ' ') . ')';
    return $o ? '<p>' . implode(' · ', $o) . '</p>' : '';
}

// Comptage par département pour une condition SQL sur jobs (alias j)
function dep_counts(PDO $db, string $from, string $where, array $args = []): array
{
    $st = $db->prepare("SELECT j.e_dep d, COUNT(*) n FROM $from WHERE $where AND j.e_dep<>'' GROUP BY j.e_dep"); $st->execute($args);
    $o = []; foreach ($st as $r) $o[(string)$r['d']] = (int)$r['n']; return $o;
}

// Région (code) d'un département
function dep_region(string $dep): string { if (!defined('CSP_DEP_REG')) return ''; foreach (CSP_DEP_REG as $r => $ds) if (in_array($dep, explode(' ', $ds), true)) return (string)$r; return ''; }
