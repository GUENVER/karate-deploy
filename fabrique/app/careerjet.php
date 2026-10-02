<?php
// Careerjet (programme éditeur) : bloc « Plus d'offres » chargé en direct par le navigateur du visiteur.
// Les résultats ne sont ni stockés en base ni indexés (endpoint /_cj en noindex, exclu du robots.txt).
// Clé : réglage careerjet_key. L'IP du serveur doit être autorisée dans le compte Publisher Careerjet.
declare(strict_types=1);

// Emplacement à insérer dans une page : le contenu est demandé à /_cj après affichage (compatible avec le cache HTML).
function careerjet_slot(array $site, string $keywords, string $location): string
{
    if (setting('careerjet_key', '') === '' || empty($site['jobs'])) return '';
    $q = http_build_query(['k' => mb_substr($keywords, 0, 80), 'l' => mb_substr($location, 0, 60)]);
    return '<div class="cj" data-q="' . h($q) . '"></div>'
        . '<script>(function(){var d=document.currentScript.previousElementSibling;fetch("/_cj?"+d.dataset.q).then(function(r){return r.ok?r.text():""}).then(function(t){d.innerHTML=t}).catch(function(){})})()</script>';
}

// Point d'entrée /_cj : appelle l'API avec l'IP et le navigateur du visiteur, cache 15 min par recherche.
function careerjet_endpoint(array $site): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: private, max-age=900');
    $key = setting('careerjet_key', '');
    if ($key === '' || is_bot()) exit;
    $kw = trim(mb_substr((string)($_GET['k'] ?? ''), 0, 80));
    $loc = trim(mb_substr((string)($_GET['l'] ?? ''), 0, 60));
    $dir = cfg('data_dir') . '/cache/careerjet';
    $file = $dir . '/' . md5($kw . '|' . $loc) . '.json';
    $jobs = null;
    if (is_file($file) && filemtime($file) > time() - 900) $jobs = json_decode((string)file_get_contents($file), true);
    if (!is_array($jobs)) {
        $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip = trim(explode(',', $ip)[0]);
        $ch = curl_init('https://search.api.careerjet.net/v4/query?' . http_build_query([
            'locale_code' => 'fr_FR', 'keywords' => $kw, 'location' => $loc, 'sort' => 'date', 'page_size' => 12, 'fragment_size' => 160,
            'user_ip' => $ip, 'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0')]));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERPWD => $key . ':', CURLOPT_HTTPAUTH => CURLAUTH_BASIC]);
        $r = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $jobs = ($r['type'] ?? '') === 'JOBS' ? array_slice((array)($r['jobs'] ?? []), 0, 12) : [];
        if (($r['type'] ?? '') === 'JOBS') { if (!is_dir($dir)) @mkdir($dir, 0750, true); @file_put_contents($file, json_encode($jobs, JSON_UNESCAPED_UNICODE)); } // erreurs non mises en cache
    }
    if (!$jobs) exit;
    $cards = '';
    foreach ($jobs as $j) {
        if (empty($j['url']) || empty($j['title'])) continue;
        $meta = array_filter([$j['company'] ?? '', $j['locations'] ?? '', $j['salary'] ?? '']);
        $cards .= '<div class="card"><div class="in"><span class="kick">Partenaire</span><h3><a href="' . h($j['url']) . '" rel="sponsored nofollow noopener" target="_blank">' . h(strip_tags((string)$j['title'])) . '</a></h3>'
            . '<p>' . h(implode(' · ', $meta)) . '</p><p><small>' . h(mb_strimwidth(strip_tags((string)($j['description'] ?? '')), 0, 160, '…')) . '</small></p></div></div>';
    }
    if ($cards === '') exit;
    echo '<h2>Plus d\'offres' . ($loc !== '' ? ' — ' . h($loc) : '') . '</h2><div class="grid">' . $cards . '</div>'
        . '<p class="disc">Offres complémentaires fournies par <a href="https://www.careerjet.fr/" rel="nofollow noopener" target="_blank">Careerjet</a>.</p>';
    exit;
}
