<?php
// Tri et filtres des listes d'offres. Formulaire GET (pas de liens à explorer), pages filtrées en noindex, cache 1 h (index.php).
// Les robots reçoivent toujours la liste non filtrée.
declare(strict_types=1);

const JF_KEYS = ['tri', 'tps', 'sal', 'salmin', 'deb', 'tele', 'fr', 'sect', 'dep'];

function jf_get(): array
{
    static $f = null; if ($f !== null) return $f;
    $f = [];
    if (!function_exists('mod_on') || !mod_on('filtres') || (function_exists('is_bot') && is_bot())) return $f;
    $g = fn($k) => is_string($_GET[$k] ?? null) ? trim($_GET[$k]) : '';
    if (in_array($g('tri'), ['salaire', 'limite'], true)) $f['tri'] = $g('tri');
    if (in_array($g('tps'), ['plein', 'partiel'], true)) $f['tps'] = $g('tps');
    if ($g('sal') === '1') $f['sal'] = '1';
    if (($v = (int)$g('salmin')) >= 1000 && $v <= 10000) $f['salmin'] = (string)(intdiv($v, 100) * 100);
    if ($g('deb') === '1') $f['deb'] = '1';
    if ($g('tele') === '1') $f['tele'] = '1';
    if (in_array($g('fr'), ['24h', '7j'], true)) $f['fr'] = $g('fr');
    if (in_array($g('sect'), ['public', 'prive'], true)) $f['sect'] = $g('sect');
    if (preg_match('/^(?:\d{2}|2A|2B|97[1-6])$/i', $g('dep'))) $f['dep'] = strtoupper($g('dep'));
    return $f;
}
function jf_active(): bool { return (bool)jf_get(); }
function jf_qs(?array $f = null, bool $contrat = true): string
{
    $f ??= jf_get(); $q = $f;
    if ($contrat && function_exists('job_contract_filter') && ($c = job_contract_filter()) !== '') $q['contrat'] = $c;
    ksort($q);
    return $q ? '?' . http_build_query($q) : '';
}
function jf_pager_qs(): string { return jf_active() ? jf_qs() : ''; }

// [condition SQL à ajouter (« AND … »), arguments, ORDER BY]
function jf_sql(array $f, string $a = ''): array
{
    $w = ''; $args = [];
    if (($f['tps'] ?? '') === 'plein') $w .= " AND {$a}e_tps=1";
    if (($f['tps'] ?? '') === 'partiel') $w .= " AND {$a}e_tps=2";
    if (!empty($f['sal'])) $w .= " AND {$a}e_sal IS NOT NULL";
    if (!empty($f['salmin'])) { $w .= " AND {$a}e_sal >= ?"; $args[] = (float)$f['salmin']; }
    if (!empty($f['deb'])) $w .= " AND {$a}e_deb=1";
    if (!empty($f['tele'])) $w .= " AND {$a}e_tele=1";
    if (($f['fr'] ?? '') !== '') { $w .= " AND {$a}created_at >= ?"; $args[] = gmdate('Y-m-d H:i:s', time() - ($f['fr'] === '24h' ? 86400 : 7 * 86400)); }
    if (($f['sect'] ?? '') === 'public') $w .= " AND {$a}id LIKE 'csp-%'";
    if (($f['sect'] ?? '') === 'prive') $w .= " AND {$a}id NOT LIKE 'csp-%'";
    if (!empty($f['dep'])) { $w .= " AND {$a}e_dep=?"; $args[] = $f['dep']; }
    $order = match ($f['tri'] ?? '') {
        'salaire' => "{$a}e_sal IS NULL, {$a}e_sal DESC, {$a}created_at DESC",
        'limite' => "(SELECT CASE WHEN cm.deadline>=date('now') THEN cm.deadline END FROM csp_meta cm WHERE cm.id={$a}id) IS NULL, (SELECT cm.deadline FROM csp_meta cm WHERE cm.id={$a}id), {$a}created_at DESC",
        default => "{$a}created_at DESC",
    };
    return [$w, $args, $order];
}

// Même filtrage sur une liste déjà chargée (pages métier, ville).
function jf_rows(array $rows, array $f): array
{
    if (!$f) return $rows;
    $lim = ($f['fr'] ?? '') !== '' ? gmdate('Y-m-d H:i:s', time() - ($f['fr'] === '24h' ? 86400 : 7 * 86400)) : '';
    $rows = array_values(array_filter($rows, function ($j) use ($f, $lim) {
        if (($f['tps'] ?? '') === 'plein' && (int)($j['e_tps'] ?? 0) !== 1) return false;
        if (($f['tps'] ?? '') === 'partiel' && (int)($j['e_tps'] ?? 0) !== 2) return false;
        if (!empty($f['sal']) && ($j['e_sal'] ?? null) === null) return false;
        if (!empty($f['salmin']) && (float)($j['e_sal'] ?? 0) < (float)$f['salmin']) return false;
        if (!empty($f['deb']) && empty($j['e_deb'])) return false;
        if (!empty($f['tele']) && empty($j['e_tele'])) return false;
        if ($lim !== '' && (string)$j['created_at'] < $lim) return false;
        $pub = str_starts_with((string)$j['id'], 'csp-');
        if (($f['sect'] ?? '') === 'public' && !$pub) return false;
        if (($f['sect'] ?? '') === 'prive' && $pub) return false;
        if (!empty($f['dep']) && (string)($j['e_dep'] ?? '') !== $f['dep']) return false;
        return true;
    }));
    if (($f['tri'] ?? '') === 'salaire') usort($rows, fn($x, $y) => [($x['e_sal'] ?? null) === null, -(float)($x['e_sal'] ?? 0)] <=> [($y['e_sal'] ?? null) === null, -(float)($y['e_sal'] ?? 0)]);
    return $rows;
}

function jf_form(string $action, array $o = []): string
{
    if (!function_exists('mod_on') || !mod_on('filtres')) return '';
    $f = jf_get(); $c = function_exists('job_contract_filter') ? job_contract_filter() : '';
    $sel = function (string $name, array $opts, string $cur, string $label) { $h = '<label><span>' . h($label) . '</span><select name="' . h($name) . '">'; foreach ($opts as $v => $t) $h .= '<option value="' . h((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . h($t) . '</option>'; return $h . '</select></label>'; };
    $chk = fn(string $name, string $label) => '<label class="jf-c"><input type="checkbox" name="' . h($name) . '" value="1"' . (!empty($f[$name]) ? ' checked' : '') . '> ' . h($label) . '</label>';
    $tri = ['' => 'Plus récentes', 'salaire' => 'Salaire le plus élevé'] + (!empty($o['public']) ? ['limite' => 'Date limite la plus proche'] : []);
    $ct = ['' => 'Tous'] + JOB_CONTRACTS;
    $h = '<details class="jf"' . ($f || $c !== '' ? ' open' : '') . '><summary>Trier et filtrer les offres' . ($f ? ' <span class="jf-n">' . count($f) . ' actif' . (count($f) > 1 ? 's' : '') . '</span>' : '') . '</summary>'
        . '<form method="get" action="' . h($action) . '">' . implode('', array_map(fn($k, $v) => $v !== '' ? '<input type="hidden" name="' . h((string)$k) . '" value="' . h((string)$v) . '">' : '', array_keys($o['hidden'] ?? []), $o['hidden'] ?? [])) . '<div class="jf-g">'
        . $sel('tri', $tri, $f['tri'] ?? '', 'Trier par')
        . (!isset($o['contrat']) || $o['contrat'] ? $sel('contrat', $ct, $c, 'Contrat') : '')
        . $sel('tps', ['' => 'Indifférent', 'plein' => 'Temps plein', 'partiel' => 'Temps partiel'], $f['tps'] ?? '', 'Temps de travail')
        . $sel('salmin', ['' => 'Indifférent', '1500' => '1 500 € et plus', '1800' => '1 800 € et plus', '2000' => '2 000 € et plus', '2500' => '2 500 € et plus', '3000' => '3 000 € et plus', '4000' => '4 000 € et plus'], $f['salmin'] ?? '', 'Salaire brut mensuel')
        . $sel('fr', ['' => 'Toutes', '24h' => 'Moins de 24 h', '7j' => 'Moins de 7 jours'], $f['fr'] ?? '', 'Publiées')
        . (empty($o['nosect']) ? $sel('sect', ['' => 'Public et privé', 'public' => 'Emploi public', 'prive' => 'Secteur privé'], $f['sect'] ?? '', 'Employeur') : '')
        . (!empty($o['deps']) ? $sel('dep', ['' => 'Tous'] + array_combine(array_keys($o['deps']), array_map(fn($k, $n) => $n . ' (' . $k . ')', array_keys($o['deps']), $o['deps'])), $f['dep'] ?? '', 'Département') : '')
        . '</div><div class="jf-g2">' . $chk('sal', 'Salaire indiqué') . $chk('deb', 'Débutant accepté') . $chk('tele', 'Télétravail mentionné') . '</div>'
        . '<div class="jf-b"><button type="submit">Afficher les offres</button>' . ($f || $c !== '' ? ' <a href="' . h($action) . '">Réinitialiser</a>' : '') . '</div>'
        . '<p class="jf-i">« Débutant accepté » et « télétravail » sont repérés dans le texte des annonces : vérifiez sur l\'offre.</p></form></details>'
        . '<style>.jf{margin:16px 0;border:1px solid var(--b);border-radius:12px;padding:10px 14px}.jf summary{cursor:pointer;font-weight:600}.jf-n{font-weight:400;font-size:13px;background:var(--c);color:#fff;border-radius:10px;padding:1px 8px;margin-left:6px}.jf-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin-top:12px}.jf label span{display:block;font-size:13px;opacity:.8;margin-bottom:3px}.jf select{width:100%;padding:8px;border:1px solid var(--b);border-radius:8px;font:inherit;background:transparent;color:inherit}.jf-g2{display:flex;flex-wrap:wrap;gap:14px;margin-top:10px}.jf-c{font-size:15px}.jf-b{margin-top:12px}.jf-b button{padding:9px 16px;border:0;border-radius:8px;background:var(--c);color:#fff;font:inherit;font-weight:600;cursor:pointer}.jf-b a{margin-left:10px}.jf-i{font-size:12px;opacity:.7;margin:8px 0 0}</style>';
    return $h;
}

function jf_empty(): string { return '<p>Aucune offre ne correspond à ces critères. <a href="?">Retirer les filtres</a>.</p>'; }

// Départements d'une région (codes => noms) pour le menu
function jf_region_deps(string $reg): array
{
    if (!defined('CSP_DEP_REG') || !isset(CSP_DEP_REG[$reg]) || !defined('DEPTS')) return [];
    $o = []; foreach (explode(' ', CSP_DEP_REG[$reg]) as $d) if (isset(DEPTS[$d])) $o[$d] = DEPTS[$d];
    return $o;
}
