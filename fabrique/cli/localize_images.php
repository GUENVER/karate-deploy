<?php
// Rapatrie en local (WebP) les images distantes des articles (URL Pixabay /get/ temporaires, Pexels…) ;
// les images mortes sont retirées. Usage : php cli/localize_images.php <hote> [--limit=500]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/api.php';

$site = site_by_host($argv[1] ?? '') ?: exit("site inconnu\n");
$limit = (int)(preg_filter('/^--limit=/', '', implode(' ', array_slice($argv, 2))) ?: 100000);
$db = site_db($site['host']);
$own = preg_quote($site['host'], '#');
$ok = 0; $dead = 0; $n = 0;
$rows = $db->query("SELECT id, slug, content, image FROM posts WHERE content LIKE '%<img%http%' OR image LIKE 'http%'")->fetchAll();
foreach ($rows as $p) {
    if ($n++ >= $limit) break;
    $i = 0;
    $content = preg_replace_callback('#<img\b[^>]*?\bsrc="(https?://[^"]+)"[^>]*>#i', function ($m) use ($site, $p, $own, &$i, &$ok, &$dead) {
        $url = html_entity_decode($m[1]);
        if (preg_match("#^https?://(www\\.)?$own/#i", $url)) return $m[0];
        $local = fetch_image($site, $url, $p['slug'] . '-' . (++$i));
        if (!$local) { $dead++; return ''; }
        $ok++;
        $alt = preg_match('/\balt="([^"]*)"/i', $m[0], $a) ? $a[1] : '';
        return '<img src="' . $local . '" alt="' . $alt . '" loading="lazy">';
    }, (string)$p['content']);
    $image = (string)$p['image'];
    if (preg_match('#^https?://#', $image) && !preg_match("#^https?://(www\\.)?$own/#i", $image)) {
        $image = fetch_image($site, html_entity_decode($image), $p['slug']) ?: '';
        if (!$image && preg_match('#<img src="(/_m/[^"]+)"#', $content, $mm)) $image = $mm[1];
    }
    $db->prepare('UPDATE posts SET content=?, image=? WHERE id=?')->execute([$content, $image, $p['id']]);
}
cache_clear($site['host']);
echo "$n articles traités, $ok images rapatriées, $dead mortes retirées\n";
