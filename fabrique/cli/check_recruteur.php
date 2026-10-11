<?php
// Vérification de recruteur.eu : pages clés, liens du menu, échantillon de chaque sitemap, liens internes des pages clés.
$H = 'https://www.recruteur.eu';
$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 recruteur-check';
function get(string $u, string $ua): array {
    $c = curl_init($u); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 40, CURLOPT_FOLLOWLOCATION => 0, CURLOPT_HEADER => 0]);
    $b = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); $t = curl_getinfo($c, CURLINFO_TOTAL_TIME); curl_close($c);
    return [$code, $b, $t];
}
$key = ['/', '/offres-emploi/', '/offres-emploi/normandie/', '/offres-emploi/ville/caen/', '/emploi-public/', '/alternance/', '/alternance/normandie/', '/emploi-cdi/', '/emploi-cdd/', '/interim/',
    '/formation-alternance/', '/formation-alternance/normandie/', '/formation-alternance/normandie/page/2/', '/entreprises-alternance/', '/entreprises-alternance/normandie/', '/mission-locale/',
    '/emploi/', '/salaire/', '/barometre-emploi/', '/recruteur/', '/chercher/', '/chercher/?q=infirmier&l=Caen', '/chercher/?q=vendeur', '/plan-du-site/', '/nos-sources/',
    '/category/cv-et-lettre-de-motivation/', '/sitemap.xml', '/sitemap-pages.xml', '/robots.txt', '/ads.txt', '/feed/'];
$bad = []; $seen = []; $n = 0; $slow = [];
$check = function (string $p, string $from) use (&$bad, &$seen, &$n, &$slow, $H, $UA) {
    if (isset($seen[$p])) return $seen[$p];
    [$c, $b, $t] = get($H . $p, $UA); $n++;
    $ok = $c === 200 || (in_array($c, [301, 302], true) && str_starts_with($p, '/offre')) ;
    if (!$ok) $bad[] = "$c $p (depuis $from)";
    if ($t > 6) $slow[] = round($t, 1) . "s $p";
    if ($c === 200 && str_contains($b, 'Fatal error')) $bad[] = "FATAL dans la page $p";
    return $seen[$p] = [$c, $b];
};
// 1. pages clés + liens internes de chacune
$links = [];
foreach ($key as $p) {
    [$c, $b] = $check($p, 'liste');
    if ($c === 200 && preg_match_all('#href="(/[^"\#]*)"#', $b, $m)) foreach ($m[1] as $l) { $l = html_entity_decode($l); if (!preg_match('#^/(_|feed)#', $l)) $links[$l] = $p; }
}
// menu (présence de toutes les entrées)
[, $home] = $seen['/'];
foreach (['/alternance/', '/formation-alternance/', '/entreprises-alternance/', '/mission-locale/', '/emploi-cdi/', '/emploi-cdd/', '/interim/', '/emploi-public/', '/salaire/', '/recruteur/', 'class="jh-burger"', 'name="l"'] as $need) if (!str_contains($home, $need)) $bad[] = "absent du menu : $need";
foreach ($links as $l => $from) $check($l, $from);
// 2. échantillon de chaque sitemap
[, $idx] = $seen['/sitemap.xml'];
preg_match_all('#<loc>([^<]+)</loc>#', $idx, $sm);
$smCount = 0;
foreach ($sm[1] as $i => $s) {
    if (str_contains($s, 'sitemap-jobs-') && $i % 8 !== 2) continue; // 1 sitemap d'offres sur 8
    [$c, $b] = get($s, $UA);
    if ($c !== 200) { $bad[] = "$c $s"; continue; }
    preg_match_all('#<loc>([^<]+)</loc>#', $b, $u); $smCount += count($u[1]);
    $sample = $u[1]; shuffle($sample);
    foreach (array_slice($sample, 0, str_contains($s, 'pages') ? 60 : 12) as $url) $check(parse_url($url, PHP_URL_PATH), basename($s));
}
echo "URLs testées : $n — URLs dans les sitemaps échantillonnés : $smCount\n";
echo $bad ? "PROBLÈMES (" . count($bad) . ") :\n- " . implode("\n- ", array_slice($bad, 0, 60)) . "\n" : "AUCUN PROBLÈME\n";
if ($slow) echo "Lentes (>6 s, 1er affichage hors cache) : " . implode(', ', array_slice($slow, 0, 15)) . "\n";
