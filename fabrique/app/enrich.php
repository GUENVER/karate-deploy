<?php
// Enrichissement des offres (recruteur.eu) : champs calculés une fois (cron), utilisés par les filtres, le décodeur et les métiers proches.
// e_sal : brut mensuel (champ salaire), e_tps : 1 plein / 2 partiel, e_tele, e_deb, e_dep (département), e_trunc (texte tronqué), e_rome.
declare(strict_types=1);

const JX_COLS = ['e_sal' => 'REAL', 'e_tps' => 'INTEGER DEFAULT 0', 'e_tele' => 'INTEGER DEFAULT 0', 'e_deb' => 'INTEGER DEFAULT 0',
    'e_dep' => "TEXT DEFAULT ''", 'e_trunc' => 'INTEGER DEFAULT 0', 'e_rome' => "TEXT DEFAULT ''", 'e_at' => 'TEXT'];

function mod_on(string $k): bool { return setting('mod_' . $k, '1') === '1'; } // interrupteurs : setting mod_decodeur, mod_filtres, mod_cartes, mod_donnees, mod_rome, mod_complements

function jx_schema(PDO $db): void
{
    static $done = false; if ($done) return; $done = true;
    $have = array_column($db->query('PRAGMA table_info(jobs)')->fetchAll(), 'name');
    foreach (JX_COLS as $c => $t) if (!in_array($c, $have, true)) $db->exec("ALTER TABLE jobs ADD COLUMN $c $t");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_jobs_dep ON jobs(status, e_dep, created_at);
        CREATE INDEX IF NOT EXISTS ix_jobs_regsal ON jobs(status, region, e_sal);
        CREATE TABLE IF NOT EXISTS job_rome(id TEXT PRIMARY KEY, rome TEXT, lat REAL, lon REAL);
        CREATE TABLE IF NOT EXISTS job_metier_rome(mslug TEXT PRIMARY KEY, rome TEXT, n INTEGER, share REAL, updated TEXT);
        CREATE TABLE IF NOT EXISTS job_metier_reg_sal(mslug TEXT, region TEXT, n INTEGER, med REAL, p25 REAL, p75 REAL, PRIMARY KEY(mslug, region));
        CREATE TABLE IF NOT EXISTS job_dups(k TEXT PRIMARY KEY, n INTEGER, first_at TEXT)");
}

// Nettoyage d'affichage : résidus HTML laissés par certains logiciels de diffusion (« br /> », « &lt;br&gt; », &nbsp;).
function jx_clean(string $d): string
{
    $d = preg_replace('~(?:&lt;|<)\s*br\s*/?\s*(?:&gt;|>)~iu', "\n", $d);
    $d = preg_replace('~(?<=[\p{L}\p{N}.,:;!?)])\s?br\s?/>~u', "\n", $d);     // « suivantesbr /> » (chevron ouvrant perdu)
    $d = preg_replace('~(?:&lt;|<)/?(?:p|li|ul|div|strong|b|em|i|span)\s*/?(?:&gt;|>)~iu', "\n", $d);
    $d = str_replace(['&nbsp;', '&amp;', '&#39;', '&rsquo;', '&quot;'], [' ', '&', "'", '’', '"'], $d);
    $d = preg_replace("/[ \t]+\n/u", "\n", $d);
    return trim(preg_replace("/\n{3,}/u", "\n\n", $d));
}

// Texte vraisemblablement tronqué à la source (extrait Adzuna, plafond de 5 000 caractères, points de suspension, résidu HTML en fin de texte).
function jx_truncated(array $j): bool
{
    $raw = rtrim((string)$j['description']);
    if ($raw === '') return false;
    if (str_starts_with((string)$j['id'], 'az-')) return true;
    if (mb_strlen($raw) === 5000) return true;
    if (preg_match('~(…|\.\.\.|br\s?/>|&lt;br\s?/?&gt;|<br\s?/?>)\s*$~u', $raw)) return true;
    return false;
}

const JX_TELE_NEG = '~(pas de|sans|aucun|non|impossible|exclu)\s+(de\s+)?t[ée]l[ée]\s?travail|t[ée]l[ée]\s?travail\s*:?\s*(non|impossible|exclu|pas possible|n.est pas)|présentiel (obligatoire|exigé|uniquement|100)~iu';
const JX_TELE_POS = '~t[ée]l[ée]\s?travail|full remote|100\s?%\s?remote|\bremote\b|travail (?:hybride|à distance)|\bhybride\b~iu';
const JX_DEB = '~d[ée]butant\.?e?s?\s*(?:\(e\)\s*)?accept[ée]|d[ée]butants? bienvenue?s?|aucune exp[ée]rience (?:n.est )?(?:requise|exig[ée]e|n[ée]cessaire)|sans exp[ée]rience|ouvert aux d[ée]butants~iu';

function jx_compute(array $j, string $cspDep = ''): array
{
    $txt = (string)$j['title'] . "\n" . (string)$j['description'];
    $sal = null;
    if (($s = job_salary((string)$j['salary'])) && ($m = job_salary_monthly($s)) >= 900 && $m <= 20000) $sal = round($m, 2);
    $wt = mb_strtolower((string)$j['worktime']);
    $tps = str_contains($wt, 'partiel') ? 2 : (str_contains($wt, 'plein') ? 1 : 0);
    if ($tps === 0) $tps = preg_match('~temps partiel|mi-temps|\b(1[0-9]|2[0-9])\s?h(?:eures)?\s*(?:/|par)\s*semaine~iu', $txt) ? 2 : (preg_match('~temps plein|temps complet|\b3[5-9]\s?h(?:eures)?\b~iu', $txt) ? 1 : 0);
    $tele = (preg_match(JX_TELE_POS, $txt) && !preg_match(JX_TELE_NEG, $txt)) ? 1 : 0;
    $deb = (preg_match('~d[ée]butant accept~iu', (string)$j['experience']) || preg_match(JX_DEB, $txt)) ? 1 : 0;
    $dep = function_exists('tgb_dept_of_postal') ? tgb_dept_of_postal((string)$j['postal']) : substr((string)$j['postal'], 0, 2);
    if ($dep === '' && $cspDep !== '') $dep = $cspDep;
    if ($dep === '20') $dep = '2A';
    return ['e_sal' => $sal, 'e_tps' => $tps, 'e_tele' => $tele, 'e_deb' => $deb, 'e_dep' => $dep, 'e_trunc' => jx_truncated($j) ? 1 : 0];
}

// Lot d'enrichissement : nouvelles offres et offres modifiées depuis le dernier passage.
function jx_run(array $site, int $max = 40000): array
{
    $db = jobs_db($site['host']); jx_schema($db);
    if (function_exists('csp_tables')) csp_tables($db);
    $st = $db->prepare("SELECT j.id, j.src, j.title, j.description, j.salary, j.worktime, j.experience, j.postal, j.contract, COALESCE(m.dep,'') cdep, COALESCE(r.rome,'') rome
        FROM jobs j LEFT JOIN csp_meta m ON m.id=j.id LEFT JOIN job_rome r ON r.id=j.id
        WHERE j.status='open' AND (j.e_at IS NULL OR j.e_at < COALESCE(j.updated_at, j.created_at) OR (j.e_rome='' AND r.rome<>'')) LIMIT $max");
    $st->execute();
    $rows = $st->fetchAll();
    $up = $db->prepare('UPDATE jobs SET e_sal=?, e_tps=?, e_tele=?, e_deb=?, e_dep=?, e_trunc=?, e_rome=?, e_at=? WHERE id=?');
    $now = now(); $n = 0;
    $app = function_exists('rome_ready') && rome_ready($db);
    $db->beginTransaction();
    foreach ($rows as $j) {
        $e = jx_compute($j, (string)$j['cdep']);
        $rome = (string)$j['rome'];
        if ($rome === '' && $app) $rome = rome_of_title($db, (string)$j['title']);
        $up->execute([$e['e_sal'], $e['e_tps'], $e['e_tele'], $e['e_deb'], $e['e_dep'], $e['e_trunc'], $rome, $now, $j['id']]);
        if (++$n % 2000 === 0) { $db->commit(); $db->beginTransaction(); }
    }
    $db->commit();
    return ['enriched' => $n];
}

// Agrégats : code ROME majoritaire par métier, salaires par métier × région, republications.
function jx_aggregates(array $site): array
{
    $db = jobs_db($site['host']); jx_schema($db); $now = now();
    $o = [];
    if (function_exists('metier_tables')) {
        metier_tables($db);
        $db->beginTransaction();
        $db->exec('DELETE FROM job_metier_rome');
        $rows = $db->query("SELECT mm.mslug, j.e_rome rome, COUNT(*) n FROM job_metier_map mm JOIN jobs j ON j.id=mm.id WHERE j.status='open' AND j.e_rome<>'' GROUP BY mm.mslug, j.e_rome ORDER BY mm.mslug, n DESC")->fetchAll();
        $tot = []; foreach ($rows as $r) $tot[$r['mslug']] = ($tot[$r['mslug']] ?? 0) + (int)$r['n'];
        $ins = $db->prepare('INSERT OR IGNORE INTO job_metier_rome(mslug,rome,n,share,updated) VALUES(?,?,?,?,?)');
        $k = 0; foreach ($rows as $r) { $sh = $r['n'] / max(1, $tot[$r['mslug']]); if ($r['n'] >= 3 && $sh >= 0.4) { $ins->execute([$r['mslug'], $r['rome'], $r['n'], round($sh, 3), $now]); $k++; } }
        $db->exec('DELETE FROM job_metier_reg_sal');
        $sal = [];
        foreach ($db->query("SELECT mm.mslug, j.region, j.e_sal FROM job_metier_map mm JOIN jobs j ON j.id=mm.id WHERE j.status='open' AND j.e_sal IS NOT NULL AND j.e_tps<>2 AND j.contract NOT IN ('stage','alternance')") as $r) $sal[$r['mslug']][$r['region']][] = (float)$r['e_sal'];
        $ins = $db->prepare('INSERT INTO job_metier_reg_sal(mslug,region,n,med,p25,p75) VALUES(?,?,?,?,?,?)');
        $q = function (array $a, float $p): float { $i = ($p * (count($a) - 1)); $lo = (int)floor($i); $hi = (int)ceil($i); return $a[$lo] + ($a[$hi] - $a[$lo]) * ($i - $lo); };
        $ns = 0; foreach ($sal as $m => $regs) foreach ($regs as $reg => $a) { if (count($a) < 20) continue; sort($a); $ins->execute([$m, $reg, count($a), round($q($a, .5)), round($q($a, .25)), round($q($a, .75))]); $ns++; }
        $db->commit();
        $o += ['metier_rome' => $k, 'reg_sal' => $ns];
    }
    // republications : même intitulé, même employeur, même code postal (toutes dates, offres encore en base)
    $db->beginTransaction();
    $db->exec('DELETE FROM job_dups');
    $db->exec("INSERT INTO job_dups(k,n,first_at) SELECT lower(title)||'|'||lower(company)||'|'||postal, COUNT(*), MIN(created_at) FROM jobs WHERE company<>'' GROUP BY 1 HAVING COUNT(*)>1");
    $db->commit();
    $o['dups'] = (int)$db->query('SELECT COUNT(*) FROM job_dups')->fetchColumn();
    return $o;
}
