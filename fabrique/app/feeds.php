<?php
// robots.txt, ads.txt, sitemaps, RSS, IndexNow.
declare(strict_types=1);

function out_robots(array $site): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $jobs = !empty($site['jobs']);
    echo "User-agent: meta-externalagent\nDisallow: /\n\nUser-agent: Baiduspider\nDisallow: /\n\n" . ($jobs ? "User-agent: AhrefsBot\nDisallow: /\n\n" : '') . ($site["host"] === "www.recruteur.eu" ? "User-agent: OAI-SearchBot\nDisallow: /wp-sitemap\nDisallow: /_admin/\nDisallow: /_api/\nDisallow: /recherche/\nDisallow: /_cj\nDisallow: /chercher/\nDisallow: /alerte/\nAllow: /\n\n" : "") . "User-agent: ChatGPT-User\nDisallow: /_admin/\nDisallow: /_api/\nDisallow: /_cj\nAllow: /\n\nUser-agent: *\nDisallow: /_admin/\nDisallow: /_api/\nDisallow: /recherche/\nDisallow: /_cj\nDisallow: /chercher/\nDisallow: /alerte/\nAllow: /\n\nSitemap: https://{$site['host']}/sitemap.xml\n" . ($jobs ? "Sitemap: https://{$site['host']}/sitemap-priorite.xml\n" : '');
}

function out_ads_txt(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $pub = preg_replace('/^ca-/', '', setting('adsense_pub', 'ca-pub-8104956615440701'));
    echo "google.com, $pub, DIRECT, f08c47fec0942fa0\n" . setting('ads_txt_extra', '');
}

function out_sitemap_index(array $site): void
{
    // cache 6 h : les comptages (offres, communes…) sont lourds et l'index est demandé en boucle par les robots
    $cf = dirname(__DIR__) . '/data/cache/' . $site['host'] . '.sitemap-index.xml';
    if (is_file($cf) && filemtime($cf) > time() - 21600) { header('Content-Type: application/xml; charset=utf-8'); readfile($cf); return; }
    if (is_file($cf)) { // périmé : on sert l'ancienne version tout de suite, puis on régénère après avoir rendu la main au client
        $lk = @fopen($cf . '.lock', 'c');
        $old = (string)@file_get_contents($cf);
        header('Content-Type: application/xml; charset=utf-8'); header('Content-Length: ' . strlen($old)); header('Connection: close'); echo $old;
        if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return;
        ignore_user_abort(true); set_time_limit(600);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); elseif (function_exists('litespeed_finish_request')) litespeed_finish_request(); else { while (ob_get_level()) ob_end_flush(); flush(); }
        error_reporting(E_ERROR); ob_start(); out_sitemap_index_build($site); $x = ob_get_clean();
        if (strpos($x, '</sitemapindex>') !== false) @file_put_contents($cf, $x, LOCK_EX); flock($lk, LOCK_UN); fclose($lk); return;
    }
    $lk = @fopen($cf . '.lock', 'c');
    if ($lk && !flock($lk, LOCK_EX | LOCK_NB) && is_file($cf)) { header('Content-Type: application/xml; charset=utf-8'); readfile($cf); return; } // une seule régénération à la fois, les autres reçoivent l'ancienne version
    ignore_user_abort(true); set_time_limit(600);
    ob_start(); out_sitemap_index_build($site); $x = ob_get_clean();
    @file_put_contents($cf, $x, LOCK_EX); if ($lk) { flock($lk, LOCK_UN); fclose($lk); } header('Content-Type: application/xml; charset=utf-8'); echo $x;
}

function out_sitemap_index_build(array $site): void
{
    $n = (int)site_db($site['host'])->query("SELECT COUNT(*) FROM posts WHERE status='publish'")->fetchColumn();
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-pages.xml</loc></sitemap>';
    for ($i = 1; $i <= max(1, (int)ceil($n / 1000)); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-posts-$i.xml</loc></sitemap>";
    if (!empty($site['fuel'])) for ($i = 1; $i <= fuel_sitemap_count($site); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-carburant-$i.xml</loc></sitemap>";
    if (function_exists('places_mod') && places_mod($site)) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-lieux.xml</loc></sitemap>';
    if (!empty($site['commune'])) for ($i = 1; $i <= commune_sitemap_count($site); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-communes-$i.xml</loc></sitemap>";
    if (!empty($site['dpe'])) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-dpe.xml</loc></sitemap>';
    if (!empty($site['ev'])) for ($i = 1; $i <= ev_sitemap_count($site); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-bornes-$i.xml</loc></sitemap>";
    if (!empty($site['jobs'])) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-priorite.xml</loc></sitemap>'; // offres récentes les plus complètes
    if (!empty($site['jobs'])) for ($i = 1; $i <= jobs_sitemap_count($site); $i++) echo '<sitemap><loc>https://' . $site['host'] . "/sitemap-jobs-$i.xml</loc></sitemap>";
    if (!empty($site['jobs'])) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-metiers.xml</loc></sitemap>';
    if (!empty($site['jobs'])) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-recruteurs.xml</loc></sitemap>';
    if (!empty($site['bzh']) && function_exists('bzh_sitemap_names')) foreach (bzh_sitemap_names($site) as $bn) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-bzh-' . $bn . '.xml</loc></sitemap>';
    if (function_exists('nor_on') && nor_on($site)) foreach (nor_sitemap_names($site) as $nn) echo '<sitemap><loc>https://' . $site['host'] . '/sitemap-nor-' . $nn . '.xml</loc></sitemap>';
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
    if (!empty($site['jobs']) && function_exists('intent_counts')) { // pages thématiques (alternance, débutant…) et leurs déclinaisons régionales
        $db = jobs_db($site['host']);
        foreach (intent_counts($db) as $k => $n) if ($n >= 5) echo '<url><loc>https://' . $site['host'] . '/' . $k . '/</loc></url>';
        foreach ($db->query("SELECT m.intent, j.region, COUNT(*) n FROM job_intent_map m JOIN jobs j ON j.id=m.id WHERE j.status='open' GROUP BY 1,2 HAVING n>=5") as $r) if (isset(JOB_REGIONS[$r['region']])) echo '<url><loc>https://' . $site['host'] . '/' . $r['intent'] . '/' . JOB_REGIONS[$r['region']][0] . '/</loc></url>';
        if (function_exists('page_proches')) echo '<url><loc>https://' . $site['host'] . '/emploi-pres-de-chez-moi/</loc></url>';
        if (function_exists('ew_sitemap_urls')) foreach (ew_sitemap_urls($site) as $u) echo '<url><loc>https://' . $site['host'] . $u . '</loc></url>';
        if (function_exists('ss_sitemap_urls')) foreach (ss_sitemap_urls($site) as $u) echo '<url><loc>https://' . $site['host'] . $u . '</loc></url>';
        if (function_exists('fx_sitemap_urls')) foreach (fx_sitemap_urls($site) as $u) echo '<url><loc>https://' . $site['host'] . $u . '</loc></url>';
        if (function_exists('rub_sitemap_urls')) foreach (rub_sitemap_urls($site) as $u) echo '<url><loc>https://' . $site['host'] . $u . '</loc></url>';
    }
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

// Renvoie le code HTTP d'IndexNow (200/202 = accepté, 0 = pas de réponse) et le journalise dans data/indexnow.log.
function indexnow_ping(array $site, array $urls): int
{
    if (!$urls) return 0;
    $body = json_encode(['host' => $site['host'], 'key' => indexnow_key(), 'keyLocation' => 'https://' . $site['host'] . '/' . indexnow_key() . '.txt', 'urlList' => array_values($urls)]);
    $ch = curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $r = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    @file_put_contents(cfg('data_dir') . '/indexnow.log', gmdate('c') . ' ' . $site['host'] . ' ' . count($urls) . " URL -> HTTP $code" . ($code >= 300 || $code === 0 ? ' ' . substr(preg_replace('/\s+/', ' ', $r), 0, 200) : '') . "\n", FILE_APPEND | LOCK_EX);
    return $code;
}
