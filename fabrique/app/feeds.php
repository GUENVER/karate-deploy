<?php
// robots.txt, ads.txt, sitemaps, RSS, IndexNow.
declare(strict_types=1);

function out_robots(array $site): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nDisallow: /_admin/\nDisallow: /_api/\nDisallow: /recherche/\nAllow: /\n\nSitemap: https://{$site['host']}/sitemap.xml\n";
}

function out_ads_txt(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $pub = preg_replace('/^ca-/', '', setting('adsense_pub', 'ca-pub-8104956615440701'));
    echo "google.com, $pub, DIRECT, f08c47fec0942fa0\n" . setting('ads_txt_extra', '');
}

function out_sitemap_index(array $site): void
{
    $n = (int)site_db($site['host'])->query("SELECT COUNT(*) FROM posts WHERE status='publish'")->fetchColumn();
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-pages.xml</loc></sitemap>';
    for ($i = 1; $i <= max(1, (int)ceil($n / 1000)); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-posts-$i.xml</loc></sitemap>";
    if (!empty($site['fuel'])) for ($i = 1; $i <= fuel_sitemap_count($site); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-carburant-$i.xml</loc></sitemap>";
    if (function_exists('places_mod') && places_mod($site)) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-lieux.xml</loc></sitemap>';
    if (!empty($site['dpe'])) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-dpe.xml</loc></sitemap>';
    if (!empty($site['ev'])) for ($i = 1; $i <= ev_sitemap_count($site); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-bornes-$i.xml</loc></sitemap>";
    if (!empty($site['jobs'])) for ($i = 1; $i <= jobs_sitemap_count($site); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-jobs-$i.xml</loc></sitemap>";
    echo '</sitemapindex>';
}

function out_sitemap_posts(array $site, int $i): void
{
    $st = site_db($site['host'])->prepare("SELECT path, COALESCE(updated_at,published_at) m, image FROM posts WHERE status='publish' ORDER BY id LIMIT 1000 OFFSET ?");
    $st->execute([($i - 1) * 1000]);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';
    foreach ($st as $r) {
        echo '<url><loc>https://' . $site['host'] . h($r['path']) . '</loc><lastmod>' . substr($r['m'], 0, 10) . '</lastmod>';
        if ($r['image']) echo '<image:image><image:loc>' . h(abs_url($site, $r['image'])) . '</image:loc></image:image>';
        echo '</url>';
    }
    echo '</urlset>';
}

function out_sitemap_pages(array $site): void
{
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://' . $site['host'] . '/</loc></url>';
    foreach (site_categories($site) as $c) echo '<url><loc>https://' . $site['host'] . '/category/' . h($c['slug']) . '/</loc></url>';
    echo '</urlset>';
}

function abs_url(array $site, string $u): string { return str_starts_with($u, '/') ? 'https://' . $site['host'] . $u : $u; }

function out_rss(array $site): void
{
    $rows = site_db($site['host'])->query("SELECT path,title,excerpt,published_at,image FROM posts WHERE status='publish' ORDER BY published_at DESC LIMIT 30")->fetchAll();
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel><title>' . h($site['name']) . '</title><link>https://' . $site['host'] . '/</link><description>' . h($site['tagline']) . '</description><language>' . h($site['lang']) . '</language>';
    foreach ($rows as $r) {
        echo '<item><title>' . h($r['title']) . '</title><link>https://' . $site['host'] . h($r['path']) . '</link><guid>https://' . $site['host'] . h($r['path']) . '</guid><pubDate>' . gmdate(DATE_RSS, strtotime($r['published_at'] . ' UTC')) . '</pubDate><description>' . h(strip_tags((string)$r['excerpt'])) . '</description>' . rss_image($site, (string)$r['image'], (string)$r['title']) . '</item>';
    }
    echo '</channel></rss>';
}

// Image de l'article en JPEG (Pinterest et certains lecteurs RSS n'acceptent pas le WebP) : copie .jpg créée une fois à côté du .webp.
function rss_image(array $site, string $img, string $title): string
{
    if ($img === '') return '';
    $url = $img;
    if (str_starts_with($img, '/_m/') && str_ends_with($img, '.webp')) {
        $src = cfg('public_dir') . $img;
        $jpg = substr($src, 0, -5) . '.jpg';
        if (!is_file($jpg) && is_file($src) && function_exists('imagecreatefromwebp') && ($im = @imagecreatefromwebp($src))) { imagejpeg($im, $jpg, 85); imagedestroy($im); }
        if (is_file($jpg)) $url = substr($img, 0, -5) . '.jpg';
    }
    if ($url[0] === '/') $url = 'https://' . $site['host'] . $url;
    $type = str_ends_with($url, '.webp') ? 'image/webp' : 'image/jpeg';
    return '<enclosure url="' . h($url) . '" type="' . $type . '" length="0"/><media:content url="' . h($url) . '" medium="image" type="' . $type . '"><media:title>' . h($title) . '</media:title></media:content>';
}

function indexnow_key(): string
{
    $k = setting('indexnow_key', '');
    if ($k === '') { $k = bin2hex(random_bytes(16)); setting_set('indexnow_key', $k); }
    return $k;
}

function indexnow_ping(array $site, array $urls): void
{
    if (!$urls) return;
    $body = json_encode(['host' => $site['host'], 'key' => indexnow_key(), 'keyLocation' => 'https://' . $site['host'] . '/' . indexnow_key() . '.txt', 'urlList' => array_values($urls)]);
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    curl_exec($ch);
    curl_close($ch);
}
