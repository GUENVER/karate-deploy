<?php
// Test de fumée de la fabrique : à lancer après CHAQUE modification du code, et par cron (--mail : alerte si échec).
// Vérifie : syntaxe PHP, cohérence de la clé de cache (une fiche demandée deux fois doit sortir du cache), pages clés en 200.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
$mail = in_array('--mail', $argv, true);
$ko = []; $ok = 0;
$UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 fabrique-smoke';

function req(string $u, string $ua): array {
    $c = curl_init($u);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => 1, CURLOPT_FOLLOWLOCATION => 0]);
    $r = (string)curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $t = (float)curl_getinfo($c, CURLINFO_TOTAL_TIME);
    $hs = (int)curl_getinfo($c, CURLINFO_HEADER_SIZE); curl_close($c);
    $xc = preg_match('/^x-cache:\s*(\S+)/mi', substr($r, 0, $hs), $m) ? strtoupper($m[1]) : '';
    return [$code, substr($r, $hs), $t, $xc];
}

// 1. Syntaxe de tout le code actif
foreach (array_merge(glob(dirname(__DIR__) . '/app/*.php'), glob(dirname(__DIR__) . '/public/*.php'), glob(dirname(__DIR__) . '/cli/*.php')) as $f) {
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
    if ($rc !== 0) $ko[] = "🔴 Erreur de code dans le fichier " . basename($f) . " (le site peut planter) : " . implode(' ', $o); else $ok++;
    $o = [];
}

$sites = registry()->query("SELECT * FROM sites WHERE status='active'")->fetchAll();
foreach ($sites as $s) {
    $H = 'https://' . $s['host'];
    $pages = ['/'];
    if (!empty($s['jobs'])) $pages = array_merge($pages, ['/offres-emploi/', '/offres-emploi/normandie/', '/offres-emploi/ville/caen/', '/emploi-public/', '/alternance/', '/recruteur/', '/sitemap.xml']);
    // 2. Pages clés
    foreach ($pages as $p) {
        [$c, $b, $t] = req($H . $p, $UA);
        if ($c !== 200) $ko[] = "🔴 Page inaccessible (code " . ($c ?: 'pas de réponse') . ") : $H$p";
        elseif (stripos($b, 'Fatal error') !== false || stripos($b, 'Uncaught') !== false) $ko[] = "🔴 La page affiche une erreur technique : $H$p";
        elseif ($t > 8) $ko[] = "🟠 Page lente (" . round($t, 1) . " s pour s'afficher) : $H$p";
        else $ok++;
    }
    // 3. Cohérence clé de cache : une fiche d'offre ouverte doit être servie depuis le cache au 2e appel
    //    et le fichier doit exister là où la purge (jobs_cache_refresh) le cherchera.
    if (!empty($s['jobs'])) {
        $slug = (string)site_db($s['host'])->query("SELECT slug FROM jobs WHERE status='open' ORDER BY rowid DESC LIMIT 1")->fetchColumn();
        if ($slug === '') { $ko[] = "🔴 $H n'a plus aucune offre d'emploi active en base (import des offres à vérifier)"; continue; }
        $p = '/offre/' . $slug . '/';
        req($H . $p, $UA);
        [$c, , , $xc] = req($H . $p, $UA);
        if ($c !== 200) $ko[] = "🔴 Fiche d'offre inaccessible (code " . ($c ?: 'pas de réponse') . ") : $H$p";
        elseif (!str_starts_with($xc, 'HIT') && !str_starts_with($xc, 'STALE')) $ko[] = "🟠 Cache inopérant : la page est recalculée à chaque visite (plus lent, serveur plus chargé) : $H$p";
        elseif (!is_file(cache_key($s['host'], $p))) $ko[] = "🟠 Cache mal rangé : une offre modifiée ou expirée risque de rester affichée dans son ancienne version : $H$p";
        else $ok++;
    }
}

$tot = $ok + count($ko);
$res = $ko
    ? "Contrôle automatique des sites de la fabrique : $ok/$tot vérifications OK, " . count($ko) . " à regarder.\n\n- " . implode("\n\n- ", $ko)
      . "\n\n🔴 = visible par les visiteurs, à traiter vite.  🟠 = le site fonctionne, gêne mineure.\n(Contrôle lancé toutes les 2 h ; ce mail n'est renvoyé qu'une fois par 6 h.)"
    : "OK ($ok contrôles)";
echo gmdate('c') . ' ' . $res . "\n";
if ($ko && $mail) alert_mail((preg_grep('/🔴/u', $ko) ? '🔴 Problème' : '🟠 Alerte mineure') . ' sur les sites – contrôle automatique', $res);
exit($ko ? 1 : 0);
