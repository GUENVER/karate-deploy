<?php
// Pages vues humaines par nouvelle rubrique de recruteur.eu (cron quotidien 23:57) ; bloc inclus dans le rapport du lundi.
// Humain probable : navigateur (pas de robot connu), page HTML, avec référent (les robots de copie n'en envoient pas).
declare(strict_types=1);

const RUB_SECTIONS = [
    'frontalier' => '#^/emploi-frontalier/#', 'etranger' => '#^/emploi-etranger/#', 'saisonnier' => '#^/emploi-saisonnier/#',
    'outre_mer' => '#^/emploi-outre-mer/#', 'pres_de_chez_moi' => '#^/emploi-pres-de-chez-moi/#', 'salaire_region' => '#^/salaire/[a-z0-9\-]+/[a-z\-]+/#',
    'simulateur' => '#^/simulateur-salaire-brut-net/#', 'fiches_offres' => '#^/offre/#',
];
const RUB_CSV = '/home2/guenver/fabrique/data/rubriques_recruteur.csv';

function rub_collect(): string
{
    $log = '/home2/guenver/access-logs/recruteur.webnormandie.com-ssl_log';
    $h = @fopen($log, 'r'); if (!$h) return "log introuvable\n";
    $c = array_fill_keys(array_keys(RUB_SECTIONS), 0); $c['offre_vers_offre'] = 0; $c['total_humain'] = 0; $date = '';
    while (($l = fgets($h)) !== false) {
        if (!preg_match('/^\S+ \S+ \S+ \[([^:]+)[^\]]*\] "GET (\S+)[^"]*" (\d+) \S+ "([^"]*)" "([^"]*)"/', $l, $m)) continue;
        [, $d, $url, $code, $ref, $ua] = $m; $date = $d;
        if ($code !== '200' || $ref === '-' || $ref === '' || preg_match('#^https?://(www\.)?recruteur\.eu/?$#', $ref)) continue; // référent réduit au domaine : signature du robot de copie (une vraie navigation interne transmet l'adresse complète)
        if (preg_match('/bot|crawl|spider|slurp|curl|wget|python|headless|ChatGPT-User|OAI-SearchBot|facebookexternal|preview/i', $ua)) continue;
        $p = (string)parse_url($url, PHP_URL_PATH);
        if (preg_match('/\.(css|js|png|jpe?g|svg|ico|webp|gif|woff2?|xml|txt)$/i', $p) || str_starts_with($p, '/_')) continue;
        $c['total_humain']++;
        foreach (RUB_SECTIONS as $k => $re) if (preg_match($re, $p)) $c[$k]++;
        if (str_starts_with($p, '/offre/') && preg_match('#^https://(www\.)?recruteur\.eu/offre/#', $ref)) $c['offre_vers_offre']++;
    }
    fclose($h);
    $dt = DateTime::createFromFormat('d/M/Y', $date); $day = $dt ? $dt->format('Y-m-d') : date('Y-m-d');
    $cols = array_merge(['date'], array_keys($c));
    $lines = is_file(RUB_CSV) ? file(RUB_CSV, FILE_IGNORE_NEW_LINES) : [implode(';', $cols)];
    $lines = array_values(array_filter($lines, fn($x) => !str_starts_with($x, $day . ';')));
    $lines[] = $day . ';' . implode(';', $c);
    file_put_contents(RUB_CSV, implode("\n", $lines) . "\n");
    return end($lines) . "\n";
}

function rub_report(): string
{
    if (!is_file(RUB_CSV)) return '';
    $rl = file(RUB_CSV, FILE_IGNORE_NEW_LINES); $hd = explode(';', (string)array_shift($rl));
    $rows = array_values(array_filter(array_map(fn($x) => count(explode(';', $x)) === count($hd) ? array_combine($hd, explode(';', $x)) : null, $rl)));
    $cur = array_slice($rows, -7); $prev = array_slice($rows, -14, max(0, count($rows) - 7)); $prev = array_slice($prev, 0, 7);
    $sum = fn($set, $k) => array_sum(array_column($set, $k));
    $lab = ['frontalier' => 'Emploi frontalier', 'etranger' => 'Emploi à l\'étranger', 'saisonnier' => 'Emplois saisonniers', 'outre_mer' => 'Outre-mer',
        'pres_de_chez_moi' => 'Près de chez moi', 'salaire_region' => 'Salaires par région', 'simulateur' => 'Simulateur brut/net',
        'fiches_offres' => 'Fiches d\'offres', 'offre_vers_offre' => 'dont 2e offre vue (bloc « offres proches »)', 'total_humain' => 'Total pages vues humaines'];
    $b = "\nNouvelles rubriques — pages vues humaines (7 jours, vs 7 précédents) :\n";
    $tot = max(1, $sum($cur, 'total_humain'));
    foreach ($lab as $k => $t) { $a = $sum($cur, $k); $p = $prev ? $sum($prev, $k) : 0;
        $b .= str_pad($t, 44, '.') . ' ' . str_pad(number_format($a, 0, ',', ' '), 7, ' ', STR_PAD_LEFT) . '  (' . round(100 * $a / $tot) . ' % du total' . ($p ? ', ' . sprintf('%+d %%', round(($a - $p) / $p * 100)) : '') . ")\n"; }
    return $b . "Revenus estimés d'une rubrique ≈ sa part des pages vues × revenus AdSense de la semaine.\n";
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) echo ($argv[1] ?? 'collect') === 'report' ? rub_report() : rub_collect();
