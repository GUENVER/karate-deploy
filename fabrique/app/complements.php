<?php
// Compléments recruteur.eu : historique employeur, dates limites de l'emploi public (« clôture bientôt »).
declare(strict_types=1);

// Date limite d'une offre publique (AAAA-MM-JJ) ou '' ; requête indexée, mémorisée pour la page.
function comp_deadline(string $id): string
{
    if (!str_starts_with($id, 'csp-') || !function_exists('mod_on') || !mod_on('complements')) return '';
    static $st = null, $memo = [];
    if (isset($memo[$id])) return $memo[$id];
    try { $st ??= site_db('www.recruteur.eu')->prepare('SELECT deadline FROM csp_meta WHERE id=?'); $st->execute([$id]); $d = (string)$st->fetchColumn(); } catch (Throwable $e) { $d = ''; }
    return $memo[$id] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : '';
}
function comp_days_left(string $d): ?int { if ($d === '') return null; $t = strtotime($d . ' 23:59:59'); return $t ? (int)floor(($t - time()) / 86400) : null; }
function comp_deadline_tag(array $j): string
{
    $d = comp_deadline((string)($j['id'] ?? '')); $n = comp_days_left($d);
    if ($n === null || $n < 0 || $n > 14) return '';
    return '<span class="tag t-dl" style="background:#b45309;color:#fff">' . ($n === 0 ? 'Clôture aujourd\'hui' : 'Clôture dans ' . $n . ' j') . '</span>';
}
function comp_deadline_line(array $j): string
{
    $d = comp_deadline((string)$j['id']); $n = comp_days_left($d);
    if ($n === null) return '';
    if ($n < 0) return '<p class="jtrunc" style="margin:10px 0;padding:10px 12px;border-left:3px solid #b45309;border-radius:6px"><strong>Date limite de candidature dépassée</strong> (' . h(date_fr($d)) . ') d\'après l\'annonce.</p>';
    return '<p class="jtrunc" style="margin:10px 0;padding:10px 12px;border-left:3px solid #b45309;border-radius:6px"><strong>Date limite de candidature : ' . h(date_fr($d)) . '</strong>' . ($n === 0 ? ' (aujourd\'hui)' : ' (dans ' . $n . ' jour' . ($n > 1 ? 's' : '') . ')') . '.</p>';
}

// Emploi public : offres qui ferment dans les 7 prochains jours (page /emploi-public/ et pages département).
function comp_closing_html(PDO $db, string $dep = '', int $limit = 12): string
{
    if (!mod_on('complements')) return '';
    $w = "j.status='open' AND m.deadline BETWEEN date('now') AND date('now','+7 days')" . ($dep !== '' ? ' AND m.dep=?' : '');
    $st = $db->prepare("SELECT COUNT(*) FROM csp_meta m JOIN jobs j ON j.id=m.id WHERE $w"); $st->execute($dep !== '' ? [$dep] : []); $n = (int)$st->fetchColumn();
    if ($n < 3) return '';
    $st = $db->prepare("SELECT j.* FROM csp_meta m JOIN jobs j ON j.id=m.id WHERE $w ORDER BY m.deadline, j.created_at DESC LIMIT $limit"); $st->execute($dep !== '' ? [$dep] : []);
    return '<h2>Candidatures qui ferment dans les 7 prochains jours (' . number_format($n, 0, ',', ' ') . ')</h2><div class="grid">' . implode('', array_map('job_card', $st->fetchAll())) . '</div>';
}

// Historique d'un employeur : précalculé chaque nuit pour toutes les entreprises (une seule lecture des offres des 12 derniers mois).
function comp_history_build(PDO $db): int
{
    $db->exec('CREATE TABLE IF NOT EXISTS job_company_hist(slug TEXT PRIMARY KEY, data TEXT, updated TEXT)');
    $acc = []; $slugs = []; $mets = []; $m0 = gmdate('Y-m', strtotime('first day of -5 month'));
    foreach ($db->query("SELECT title, company, status, created_at, seen_at FROM jobs WHERE company<>'' AND created_at >= datetime('now','-365 days')") as $j) {
        $co = (string)$j['company']; $sl = $slugs[$co] ??= company_slug($co); if ($sl === '') continue;
        $a = &$acc[$sl]; $a ??= ['n' => 0, 'closed' => 0, 'dur' => [], 'months' => [], 'met' => []];
        $a['n']++;
        $m = substr((string)$j['created_at'], 0, 7); if ($m >= $m0) $a['months'][$m] = ($a['months'][$m] ?? 0) + 1;
        if ($a['n'] <= 300 && function_exists('metier_of')) { $tt = (string)$j['title']; $mt = $mets[$tt] ??= (metier_of($tt)[1] ?? ''); if ($mt !== '') $a['met'][$mt] = ($a['met'][$mt] ?? 0) + 1; }
        if ($j['status'] !== 'open') { $a['closed']++; $d = (strtotime((string)$j['seen_at']) - strtotime((string)$j['created_at'])) / 86400; if ($d >= 0 && $d < 400 && count($a['dur']) < 3000) $a['dur'][] = $d; }
        unset($a);
    }
    $db->beginTransaction(); $db->exec('DELETE FROM job_company_hist');
    $ins = $db->prepare('INSERT INTO job_company_hist(slug,data,updated) VALUES(?,?,?)'); $now = now(); $k = 0;
    foreach ($acc as $sl => $a) {
        if ($a['n'] < 5) continue;
        sort($a['dur']); arsort($a['met']);
        $ins->execute([$sl, json_encode(['n' => $a['n'], 'closed' => $a['closed'], 'med' => count($a['dur']) >= 5 ? round($a['dur'][intdiv(count($a['dur']), 2)], 1) : null, 'months' => $a['months'], 'met' => array_slice($a['met'], 0, 6, true)], JSON_UNESCAPED_UNICODE), $now]); $k++;
    }
    $db->commit();
    return $k;
}

function comp_history_html(PDO $db, array $c): string
{
    if (!mod_on('complements')) return '';
    try { $st = $db->prepare('SELECT data FROM job_company_hist WHERE slug=?'); $st->execute([$c['slug']]); $d = json_decode((string)$st->fetchColumn(), true); } catch (Throwable $e) { return ''; }
    if (!$d) return '';
    $months = []; for ($i = 5; $i >= 0; $i--) { $k = gmdate('Y-m', strtotime("first day of -$i month")); $months[$k] = (int)($d['months'][$k] ?? 0); }
    while (count($months) > 2 && reset($months) === 0) array_shift($months); // mois antérieurs au suivi par le site
    $mx = max(1, max($months)); $mo = ['01' => 'janv.', '02' => 'févr.', '03' => 'mars', '04' => 'avr.', '05' => 'mai', '06' => 'juin', '07' => 'juil.', '08' => 'août', '09' => 'sept.', '10' => 'oct.', '11' => 'nov.', '12' => 'déc.'];
    $bars = ''; foreach ($months as $k => $v) $bars .= '<div style="flex:1;text-align:center;font-size:12px"><div style="height:60px;display:flex;align-items:flex-end;justify-content:center"><div title="' . $v . ' offres" style="width:70%;height:' . max(2, (int)round(60 * $v / $mx)) . 'px;background:var(--c);border-radius:3px 3px 0 0"></div></div>' . number_format($v, 0, ',', ' ') . '<br><span style="opacity:.7">' . $mo[substr($k, 5)] . '</span></div>';
    $nf = fn($x) => number_format((int)$x, 0, ',', ' '); $n = (int)$d['n']; $cl = (int)$d['closed']; $med = $d['med'];
    $medTxt = $med === null ? '' : ($med < 1 ? ' La moitié des offres retirées l\'ont été <strong>en moins d\'un jour</strong> (pourvues, republiées ou retirées).' : ' Une offre reste en ligne <strong>' . (int)round($med) . ' jour' . (round($med) > 1 ? 's' : '') . '</strong> en médiane avant d\'être retirée (pourvue ou non).');
    $met = $d['met'] ?? [];
    return '<section class="box" style="margin:24px 0"><h2 style="margin-top:0">Historique des recrutements</h2>'
        . '<p>Depuis 12 mois, le site a relevé <strong>' . $nf($n) . ' offre' . ($n > 1 ? 's' : '') . '</strong> de cet employeur' . ($cl ? ', dont ' . $nf($cl) . ' déjà retirée' . ($cl > 1 ? 's' : '') : '') . '.' . $medTxt . '</p>'
        . '<div style="display:flex;gap:6px;max-width:420px;margin:10px 0">' . $bars . '</div><p style="font-size:13px;opacity:.75;margin-top:0">Offres publiées par mois, depuis le début du suivi (6 mois au plus).</p>'
        . ($met ? '<p><strong>Métiers les plus recherchés :</strong> ' . h(implode(', ', array_map(fn($k, $v) => $k . ' (' . $v . ')', array_keys($met), $met))) . '.</p>' : '')
        . '<p style="font-size:13px;opacity:.75">Calculé sur les annonces vues par le site (France Travail, emploi public et partenaires) ; un retrait ne signifie pas forcément une embauche.</p></section>';
}
