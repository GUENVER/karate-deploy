<?php
// Contrôleur frontal unique : tous les sites de la fabrique passent ici (sélection par nom d'hôte).
declare(strict_types=1);

require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/fuel.php';

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (str_starts_with($path, '/_admin')) { require dirname(__DIR__) . '/app/admin.php'; admin_main($path); exit; }
if (str_starts_with($path, '/_api/')) { require dirname(__DIR__) . '/app/api.php'; api_main(substr($path, 6)); exit; }

$site = site_by_host($host);
if (!$site && $path === '/') { header('Location: /_admin/', true, 302); exit; } // hôte d'administration (ex. fabrique.caen.pro)
if (!$site || $site['status'] === 'deleted') { http_response_code(404); echo 'Site inconnu.'; exit; }

// ads.txt servi tel quel sur chaque alias (le robot AdSense le lit à la racine du domaine, sans redirection)
if ($path === '/ads.txt') { out_ads_txt(); exit; }

// www / alias -> hôte canonique
if ($host !== $site['host']) { header('Location: https://' . $site['host'] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301); exit; }

switch ($path) {
    case '/robots.txt': out_robots($site); exit;
    case '/ads.txt': out_ads_txt(); exit;
    case '/sitemap.xml': case '/sitemap_index.xml': case '/wp-sitemap.xml': out_sitemap_index($site); exit;
    case '/sitemap-pages.xml': out_sitemap_pages($site); exit;
    case '/feed/': case '/feed': out_rss($site); exit;
    case '/' . indexnow_key() . '.txt': header('Content-Type: text/plain'); echo indexnow_key(); exit;
}
if (preg_match('#^/sitemap-posts-(\d+)\.xml$#', $path, $m)) { out_sitemap_posts($site, (int)$m[1]); exit; }
if ($site['jobs'] && preg_match('#^/sitemap-jobs-(\d+)\.xml$#', $path, $m)) { out_sitemap_jobs($site, (int)$m[1]); exit; }
if (!empty($site['fuel']) && preg_match('#^/sitemap-carburant-(\d+)\.xml$#', $path, $m)) { out_sitemap_fuel($site, (int)$m[1]); exit; }
if (preg_match('#^/(wp-admin|wp-login\.php|xmlrpc\.php|wp-json)#', $path)) { http_response_code(410); exit; }

if ($site['status'] === 'paused' && !isset($_COOKIE['fab_admin'])) { http_response_code(503); header('Retry-After: 3600'); echo 'Maintenance.'; exit; }

// Compteur de pages vues (hors robots).
if (!is_bot() && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try { site_db($site['host'])->prepare('INSERT INTO stats(day,pv) VALUES(?,1) ON CONFLICT(day) DO UPDATE SET pv=pv+1')->execute([gmdate('Y-m-d')]); } catch (Throwable $e) {}
}

if ($path === '/recherche/' || $path === '/recherche') { echo page_search($site, (string)($_GET['q'] ?? '')); exit; }

// Cache HTML (vidé à chaque publication).
$ckey = cache_dir($site['host']) . '/' . md5($path . '?' . ($_GET['contrat'] ?? '')) . '.html';
if (is_file($ckey) && filemtime($ckey) > time() - 86400) { header('Content-Type: text/html; charset=utf-8'); header('X-Cache: HIT'); readfile($ckey); count_view($site, $path); exit; }

$html = route($site, $path);
if ($html === null) exit; // redirection déjà envoyée
if ($html === '') { http_response_code(404); echo page_404($site); exit; }
if ((int)http_response_code() === 200) { // on ne met en cache que les pages 200 (pas les 410 d'offres expirées)
    if (!is_dir(dirname($ckey))) @mkdir(dirname($ckey), 0750, true);
    @file_put_contents($ckey, $html, LOCK_EX);
}
header('Content-Type: text/html; charset=utf-8');
echo $html;
count_view($site, $path);

function count_view(array $site, string $path): void
{
    if (is_bot()) return;
    try { site_db($site['host'])->prepare("UPDATE posts SET views=views+1 WHERE path=? AND status='publish'")->execute([$path]); } catch (Throwable $e) {}
}

function route(array $site, string $path): ?string
{
    if ($path === '/') return page_home($site, 1);
    if (preg_match('#^/page/(\d+)/?$#', $path, $m)) return page_home($site, (int)$m[1]);
    if (preg_match('#^/category/(?:[^/]+/)*([^/]+)/(?:page/(\d+)/?)?$#', $path, $m) && ($h = page_category($site, $m[1], (int)($m[2] ?? 1) ?: 1)) !== '') return $h;
    if (preg_match('#^/(a-propos|contact|mentions-legales|confidentialite)/?$#', $path, $m)) return page_static($site, $m[1]);
    if (!empty($site['fuel'])) {
        if ($path === '/prix-carburant/') return page_fuel_france($site);
        if (preg_match('#^/prix-carburant/([a-z0-9\-]+)/(?:([a-z0-9\-]+)/)?$#', $path, $m) && ($dept = dept_by_slug($m[1])))
            return empty($m[2]) ? page_fuel_dept($site, $dept) : page_fuel_city($site, $dept, $m[2]);
        if (preg_match('#^/station/([a-z0-9\-]+)/$#', $path, $m)) return page_station($site, $m[1]);
    }
    if (!empty($site['jobs'])) {
        if ($path === '/offres-emploi/') return page_jobs_home($site);
        if (preg_match('#^/offres-emploi/([a-z\-]+)/(?:page/(\d+)/)?$#', $path, $m) && ($reg = region_by_slug($m[1]))) {
            $c = (string)($_GET['contrat'] ?? '');
            return page_jobs_region($site, $reg, max(1, (int)($m[2] ?? 1)), isset(JOB_CONTRACTS[$c]) ? $c : '');
        }
        if (preg_match('#^/offre/([a-z0-9\-]+)/$#', $path, $m)) return page_job($site, $m[1]);
    }

    $db = site_db($site['host']);
    $norm = rtrim($path, '/') . '/';
    $st = $db->prepare("SELECT * FROM posts WHERE path=? AND status='publish'");
    $st->execute([$norm]);
    if ($p = $st->fetch()) {
        if ($norm !== $path) { header('Location: ' . $norm, true, 301); return null; }
        return page_post($site, $p);
    }
    $st = $db->prepare('SELECT dst, code FROM redirects WHERE src=?');
    $st->execute([$norm]);
    $r = $st->fetch();
    if (!$r) {
        // règles par préfixe (src se terminant par *), la plus longue d'abord — ex. '/offre-d-emploi/*' -> 301, '/*' -> 410
        foreach ($db->query("SELECT src, dst, code FROM redirects WHERE src LIKE '%*' ORDER BY length(src) DESC") as $rule) {
            if (str_starts_with($norm, rtrim($rule['src'], '*'))) { $r = $rule; break; }
        }
        // l'ancien slug d'un article existant reste prioritaire sur la règle générique
        if ($r && $r['src'] === '/*' && preg_match('#/([a-z0-9\-]+)/$#', $norm, $m)) {
            $s2 = $db->prepare("SELECT path FROM posts WHERE slug=? AND status='publish'"); $s2->execute([$m[1]]);
            if ($to = $s2->fetchColumn()) { header('Location: ' . $to, true, 301); return null; }
        }
    }
    if ($r) {
        if ((int)$r['code'] === 410) { http_response_code(410); return page_404($site); }
        header('Location: ' . $r['dst'], true, (int)$r['code']); return null;
    }
    // Ancienne URL WordPress /AAAA/MM/JJ/slug/ ou /slug/ : on retrouve l'article par son slug.
    if (preg_match('#/([a-z0-9\-]+)/$#', $norm, $m)) {
        $st = $db->prepare("SELECT path FROM posts WHERE slug=? AND status='publish'");
        $st->execute([$m[1]]);
        if ($to = $st->fetchColumn()) { header('Location: ' . $to, true, 301); return null; }
    }
    return '';
}
