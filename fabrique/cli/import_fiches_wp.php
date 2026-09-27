<?php
// Réimporte les fiches touristiques de l'ancien WordPress de normandie.me dans l'annuaire « fiches » de la fabrique,
// en conservant exactement leurs URL (/fiches-touristiques/{slug}/) déjà indexées par Google.
// Usage : php cli/import_fiches_wp.php /chemin/wp-config.php www.normandie.me
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/fuel.php';
require dirname(__DIR__) . '/app/places.php';
if (function_exists('proc_nice')) @proc_nice(19);
[$cfgFile, $host] = [$argv[1] ?? '', $argv[2] ?? ''];
$site = site_by_host($host) ?: exit("site inconnu\n");
$c = (string)file_get_contents($cfgFile);
$g = fn($k) => preg_match("/$k.,\\s*.([^'\"]+)/", $c, $m) ? $m[1] : '';
preg_match("/table_prefix\\s*=\\s*.([^'\"]+)/", $c, $t);
$wp = new PDO('mysql:host=localhost;dbname=' . $g('DB_NAME') . ';charset=utf8mb4', $g('DB_USER'), $g('DB_PASSWORD'));
$T = $t[1];
$generic = ["Lieu d'intérêt", "Point d'intérêt", 'Établissement alimentaire', 'Produit', 'Lieu', ''];
$stamp = gmdate('Y-m-d H:i:s'); $n = 0; $last = 0;
do {
    $st = $wp->prepare("SELECT ID, post_name, post_title, post_content FROM {$T}posts WHERE post_type='fiches-touristiques' AND post_status='publish' AND ID>? ORDER BY ID LIMIT 500");
    $st->execute([$last]);
    $posts = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$posts) break;
    $ids = implode(',', array_map('intval', array_column($posts, 'ID')));
    $meta = [];
    foreach ($wp->query("SELECT post_id, meta_key, meta_value FROM {$T}postmeta WHERE post_id IN ($ids) AND meta_key IN ('latitude','longitude','adresse_postale','site_internet','code_postal','ville','classement')") as $m) $meta[$m['post_id']][$m['meta_key']] = $m['meta_value'];
    $rows = [];
    foreach ($posts as $p) {
        $m = $meta[$p['ID']] ?? [];
        $cats = array_values(array_unique(array_filter(array_map('trim', explode('|', (string)($m['classement'] ?? ''))))));
        $specific = array_values(array_diff($cats, $generic));
        $rows[] = ['id' => 'wp' . $p['ID'], 'slug' => $p['post_name'], 'name' => html_entity_decode($p['post_title'], ENT_QUOTES), 'kind' => $specific[0] ?? ($cats[0] ?? 'Lieu touristique'),
            'address' => $m['adresse_postale'] ?? '', 'cp' => $m['code_postal'] ?? '', 'city' => $m['ville'] ?? '', 'lat' => $m['latitude'] ?? 0, 'lon' => $m['longitude'] ?? 0,
            'rank' => min(60, (int)(strlen((string)$p['post_content']) / 20)) + (!empty($m['site_internet']) ? 10 : 0),
            'fields' => array_filter(['Catégories' => implode(', ', $specific ?: $cats)]), 'desc' => trim(strip_tags(html_entity_decode((string)$p['post_content'], ENT_QUOTES))),
            'links' => !empty($m['site_internet']) ? [['Site internet', $m['site_internet']]] : []];
        $last = (int)$p['ID'];
    }
    $n += places_store($site, $rows, $stamp);
    usleep(200000);
} while (true);
cache_clear($site['host']);
echo "$n fiches importées\n";
