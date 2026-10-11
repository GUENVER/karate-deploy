<?php
// Contrôleur frontal unique : tous les sites de la fabrique passent ici (sélection par nom d'hôte).
declare(strict_types=1);

// Robots sans intérêt qui exécutent le JavaScript (pages vues et impressions publicitaires fictives, risque AdSense) :
// robot d'entraînement IA de Meta et signature du botnet de septembre. Les robots de recherche restent autorisés.
if (preg_match('#meta-externalagent|Baiduspider|Chrome/133\.0\.6943\.141 Safari/537\.36$#i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) || preg_match('#^(116\.179\.|220\.181\.108\.|111\.225\.|144\.225\.)#', (string)($_SERVER['REMOTE_ADDR'] ?? '')) || (str_starts_with((string)($_SERVER['REMOTE_ADDR'] ?? ''), '57.141.') && stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'facebookexternalhit') === false)) { http_response_code(403); header('Content-Type: text/plain'); exit('Forbidden'); } // + Baidu : robots qui exécutent le JS, aucun visiteur français ; Meta 57.141.* : seuls les aperçus de liens (facebookexternalhit) passent

require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/render.php';
require dirname(__DIR__) . '/app/feeds.php';
require dirname(__DIR__) . '/app/jobs.php';
require dirname(__DIR__) . '/app/careerjet.php';
require dirname(__DIR__) . '/app/lba_blocks.php';
require dirname(__DIR__) . '/app/rubriques.php';
require dirname(__DIR__) . '/app/csp.php';
require dirname(__DIR__) . '/app/fr_map.php';
require dirname(__DIR__) . '/app/metiers.php';
require dirname(__DIR__) . '/app/recruteurs.php';
require dirname(__DIR__) . '/app/fuel.php';
require dirname(__DIR__) . '/app/ev.php';
require dirname(__DIR__) . '/app/places.php';
require dirname(__DIR__) . '/app/dpe.php';
require dirname(__DIR__) . '/app/commune.php';
require dirname(__DIR__) . '/app/bzh.php';
require dirname(__DIR__) . '/app/nor.php';
require dirname(__DIR__) . '/app/alerts.php';
require dirname(__DIR__) . '/app/tgbot.php';
require dirname(__DIR__) . '/app/enrich.php';
require dirname(__DIR__) . '/app/rome.php';
require dirname(__DIR__) . '/app/decode.php';
require dirname(__DIR__) . '/app/filters.php';
foreach (['complements', 'depmap', 'pubdata', 'frontalier', 'proches', 'saison', 'etranger', 'salreg', 'outremer', 'outils'] as $__m) if (is_file(dirname(__DIR__) . "/app/$__m.php")) require dirname(__DIR__) . "/app/$__m.php";

$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (str_starts_with($path, '/_admin')) { require dirname(__DIR__) . '/app/admin.php'; admin_main($path); exit; }
if (str_starts_with($path, '/_api/')) { require dirname(__DIR__) . '/app/api.php'; api_main(substr($path, 6)); exit; }

$site = site_by_host($host);
if (!$site && $path === '/') { header('Location: /_admin/', true, 302); exit; } // hôte d'administration (ex. fabrique.caen.pro)
if (!$site || $site['status'] === 'deleted') { http_response_code(404); echo 'Site inconnu.'; exit; }

// ads.txt servi tel quel sur chaque alias (le robot AdSense le lit à la racine du domaine, sans redirection)
if ($path === '/ads.txt') { out_ads_txt(); exit; }
if ($path === '/google6db13020cf1e8760.html') { header('Content-Type: text/html; charset=utf-8'); echo 'google-site-verification: google6db13020cf1e8760.html'; exit; } // vérif Search Console du compte de service rank-tracker

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
if (!empty($site['bzh']) && preg_match('#^/sitemap-bzh-([a-z]+-\d+|hubs)\.xml$#', $path, $m)) { bzh_out_sitemap($site, $m[1]); exit; }
if (!empty($site['bzh']) && $path === '/llms.txt') { bzh_llms($site); exit; }
if (nor_on($site) && preg_match('#^/sitemap-nor-(evenement-\d+|festival-1|hubs)\.xml$#', $path, $m)) { nor_out_sitemap($site, $m[1]); exit; }
if (preg_match('#^/sitemap-posts-(\d+)\.xml$#', $path, $m)) { out_sitemap_posts($site, (int)$m[1]); exit; }
if ($site['jobs'] && preg_match('#^/sitemap-jobs-(\d+)\.xml$#', $path, $m)) { out_sitemap_jobs($site, (int)$m[1]); exit; }
if ($site['jobs'] && $path === '/sitemap-metiers.xml') { xml_cached($site, 'sitemap-metiers', 21600, fn() => out_sitemap_metiers($site)); exit; }
if ($site['jobs'] && $path === '/sitemap-recruteurs.xml') { xml_cached($site, 'sitemap-recruteurs', 21600, fn() => out_sitemap_companies($site)); exit; }
if ($site['jobs'] && $path === '/llms.txt') { out_llms_txt($site); }
if ($site['jobs'] && $path === '/sitemap-priorite.xml') { out_sitemap_priority($site); exit; }
if (!empty($site['commune']) && preg_match('#^/sitemap-communes-(\d+)\.xml$#', $path, $m)) { out_sitemap_communes($site, (int)$m[1]); exit; }
if (!empty($site['dpe']) && $path === '/sitemap-dpe.xml') { out_sitemap_dpe($site); exit; }
if (places_mod($site) && $path === '/sitemap-lieux.xml') { out_sitemap_places($site); exit; }
if (!empty($site['ev']) && preg_match('#^/sitemap-bornes-(\d+)\.xml$#', $path, $m)) { out_sitemap_ev($site, (int)$m[1]); exit; }
if (!empty($site['fuel']) && preg_match('#^/sitemap-carburant-(\d+)\.xml$#', $path, $m)) { out_sitemap_fuel($site, (int)$m[1]); exit; }
if (preg_match('#^/(wp-admin|wp-login\.php|xmlrpc\.php|wp-json)#', $path)) { http_response_code(410); exit; }

if ($site['status'] === 'paused' && !isset($_COOKIE['fab_admin'])) { http_response_code(503); header('Retry-After: 3600'); echo 'Maintenance.'; exit; }

// Compteur de pages vues : balise envoyée par le navigateur (JavaScript), les robots qui imitent un navigateur ne l'exécutent pas.
if ($path === '/_cj') careerjet_endpoint($site);
if ($path === '/_ac' && !empty($site['jobs']) && function_exists('ac_endpoint')) ac_endpoint($site);
if ($path === '/_pv') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !is_bot()) {
        try {
            $db = site_db($site['host']);
            $db->prepare('INSERT INTO stats(day,pv) VALUES(?,1) ON CONFLICT(day) DO UPDATE SET pv=pv+1')->execute([gmdate('Y-m-d')]);
            $p = substr((string)($_POST['p'] ?? ''), 0, 300);
            if ($p !== '') $db->prepare("UPDATE posts SET views=views+1 WHERE path=? AND status='publish'")->execute([$p]);
        } catch (Throwable $e) {}
    }
    http_response_code(204); exit;
}

if (!empty($site['commune']) && $path === '/commune/recherche/') { echo page_commune_search($site, (string)($_GET['q'] ?? '')); exit; } // jamais en cache
if (!empty($site['jobs']) && $path === '/_tgbot/' && $_SERVER['REQUEST_METHOD'] === 'POST') { tgb_webhook($site); exit; }
if (!empty($site['jobs']) && ($path === '/alerte/' || $path === '/alerte/stop/')) { $h = alerts_route($site, $path); if ($h !== null) echo $h; exit; } // jamais en cache
if (!empty($site['jobs']) && $path === '/emploi-pres-de-chez-moi/' && function_exists('page_proches') && (isset($_GET['l']) || isset($_GET['lat']))) { echo page_proches($site); exit; } // recherche : jamais en cache
if (!empty($site['jobs']) && $path === '/chercher/') { echo page_job_search($site, (string)($_GET['q'] ?? ''), (string)($_GET['l'] ?? '')); exit; } // jamais en cache
if ($path === '/recherche/' || $path === '/recherche') { echo page_search($site, (string)($_GET['q'] ?? '')); exit; }

// Listes filtrées ou triées (formulaire) : jamais indexées, cache court d'une heure par combinaison normalisée.
if (!empty($site['jobs']) && function_exists('jf_active') && jf_active()) {
    $GLOBALS['jf_noindex'] = true;
    $fkey = cache_key($site['host'], $path, 'F' . jf_qs(), '');
    if (is_file($fkey) && filemtime($fkey) > time() - 3600) { header('Content-Type: text/html; charset=utf-8'); header('X-Cache: HIT-F'); readfile($fkey); exit; }
    $html = route($site, $path);
    if ($html === null) exit;
    if ($html === '') { http_response_code(404); echo page_404($site); exit; }
    if ((int)http_response_code() === 200) { if (!is_dir(dirname($fkey))) @mkdir(dirname($fkey), 0750, true); @file_put_contents($fkey, $html, LOCK_EX); }
    header('X-Cache: MISS-F'); header('Content-Type: text/html; charset=utf-8'); echo $html; exit;
}

// Cache HTML : une page périmée reste servie tant qu'aucun créneau de régénération n'est libre (jamais de cache froid).
$ckey = cache_key($site['host'], $path, is_string($_GET['contrat'] ?? null) ? $_GET['contrat'] : '', is_string($_GET['niveau'] ?? null) ? $_GET['niveau'] : '');
$regen = null;
if (is_file($ckey)) {
    $fresh = cache_is_fresh($ckey);
    if (!$fresh) $regen = cache_regen_slot();
    if ($fresh || !$regen) { header('Content-Type: text/html; charset=utf-8'); header('X-Cache: ' . ($fresh ? 'HIT' : 'STALE')); readfile($ckey); exit; }
    // Stale-while-revalidate : le visiteur reçoit tout de suite la version périmée, la régénération se fait après coupure de la réponse.
    $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
    if ($finish) {
        header('Content-Type: text/html; charset=utf-8'); header('X-Cache: STALE-REVALIDATE'); readfile($ckey);
        $finish(); ignore_user_abort(true); @set_time_limit(120);
        // 2026-10-09 : réponse déjà envoyée -> header()/http_response_code() sont sans effet. On intercepte ces avertissements :
        // une redirection ou un code non-200 (410 offre expirée…) pendant la régénération = on vide le cache au lieu d'enregistrer la page en 200.
        $swrBad = false;
        set_error_handler(function (int $no, string $msg, string $file = '', int $line = 0) use (&$swrBad): bool {
            if (str_starts_with($msg, 'Cannot set response code')) { $swrBad = true; return true; }
            if (str_starts_with($msg, 'Cannot modify header information')) {
                $src = @file($file); $l = $src[$line - 1] ?? '';
                if (stripos($l, 'Location') !== false || stripos($l, 'http_response_code') !== false) $swrBad = true;
                return true;
            }
            return false;
        }, E_WARNING);
        try { $html = route($site, $path); if (!$swrBad && is_string($html) && $html !== '' && (int)http_response_code() === 200) @file_put_contents($ckey, $html, LOCK_EX); else @unlink($ckey); } catch (Throwable $e) { error_log('fabrique swr ' . $path . ' : ' . $e->getMessage()); }
        restore_error_handler();
        exit;
    }
}

try { $html = route($site, $path); }
catch (Throwable $e) { // base verrouillée, etc. : on sert l'ancienne version plutôt qu'une erreur
    if (is_file($ckey)) { error_log('fabrique stale-on-error ' . $path . ' : ' . $e->getMessage()); http_response_code(200); header('Content-Type: text/html; charset=utf-8'); header('X-Cache: STALE-ERR'); readfile($ckey); exit; }
    throw $e;
}
if ($html === null) { @unlink($ckey); exit; } // redirection déjà envoyée
if ($html === '') { @unlink($ckey); http_response_code(404); echo page_404($site); exit; }
if ((int)http_response_code() === 200) { // on ne met en cache que les pages 200 (pas les 410 d'offres expirées)
    if (!is_dir(dirname($ckey))) @mkdir(dirname($ckey), 0750, true);
    @file_put_contents($ckey, $html, LOCK_EX);
} else @unlink($ckey); // 410, 404… : l'ancienne version n'est plus valable
header('X-Cache: MISS');
header('Content-Type: text/html; charset=utf-8');
echo $html;

function count_view(array $site, string $path): void
{
    if (is_bot()) return;
    try { site_db($site['host'])->prepare("UPDATE posts SET views=views+1 WHERE path=? AND status='publish'")->execute([$path]); } catch (Throwable $e) {}
}

function route(array $site, string $path): ?string
{
    if (!empty($site['bzh'])) { $bz = bzh_route($site, $path); if ($bz !== false) return $bz; }
    if (nor_on($site)) { $nr = nor_route($site, $path); if ($nr !== false) return $nr; }
    if ($path === '/') return page_home($site, 1);
    if (preg_match('#^/page/(\d+)/?$#', $path, $m)) return page_home($site, (int)$m[1]);
    if (preg_match('#^/category/(?:[^/]+/)*([^/]+)/(?:page/(\d+)/?)?$#', $path, $m) && ($h = page_category($site, $m[1], (int)($m[2] ?? 1) ?: 1)) !== '') return $h;
    if (preg_match('#^/(a-propos|contact|mentions-legales|confidentialite|nos-sources)/?$#', $path, $m)) return page_static($site, $m[1]);
    if ($path === '/plan-du-site/') return page_plan($site);
    if (!empty($site['fuel'])) {
        if ($path === '/prix-carburant/') return page_fuel_france($site);
        if (preg_match('#^/prix-carburant/([a-z0-9\-]+)/(?:([a-z0-9\-]+)/)?$#', $path, $m) && ($dept = dept_by_slug($m[1])))
            return empty($m[2]) ? page_fuel_dept($site, $dept) : page_fuel_city($site, $dept, $m[2]);
        if (preg_match('#^/station/([a-z0-9\-]+)/$#', $path, $m)) return page_station($site, $m[1]);
    }
    if (!empty($site['commune'])) {
        if ($path === '/commune/') return page_commune_home($site);
        if (preg_match('#^/commune/([a-z0-9\-]+)/(?:([a-z0-9\-]+)/)?$#', $path, $m) && ($dept = dept_by_slug($m[1])))
            return empty($m[2]) ? page_commune_dept($site, $dept) : page_commune($site, $dept, $m[2]);
    }
    if (!empty($site['dpe'])) {
        if ($path === '/dpe/') return page_dpe_france($site);
        if (preg_match('#^/dpe/([a-z0-9\-]+)/(?:([a-z0-9\-]+)/)?$#', $path, $m) && ($dept = dept_by_slug($m[1])))
            return empty($m[2]) ? page_dpe_dept($site, $dept) : page_dpe_city($site, $dept, $m[2]);
    }
    if ($pm = places_mod($site)) {
        if ($path === '/' . $pm['prefix'] . '/') return page_places_home($site);
        if (preg_match('#^/' . $pm['prefix'] . '/([a-z0-9\-]+)/$#', $path, $m) && ($dept = dept_by_slug($m[1]))) return page_places_dept($site, $dept);
        if (preg_match('#^/' . $pm['item'] . '/([a-z0-9\-]+)/$#', $path, $m)) return page_place($site, $m[1]);
    }
    if (!empty($site['ev'])) {
        if ($path === '/bornes-recharge/') return page_ev_france($site);
        if (preg_match('#^/bornes-recharge/([a-z0-9\-]+)/(?:([a-z0-9\-]+)/)?$#', $path, $m) && ($dept = dept_by_slug($m[1])))
            return empty($m[2]) ? page_ev_dept($site, $dept) : page_ev_city($site, $dept, $m[2]);
        if (preg_match('#^/borne/([a-z0-9\-]+)/$#', $path, $m)) return page_borne($site, $m[1]);
    }
    if (!empty($site['jobs'])) {
        if ($path === '/offres-emploi/') return page_jobs_home($site);
        if (preg_match('#^/offres-emploi/ville/([a-z0-9\-]+)/$#', $path, $m)) { $h = page_jobs_city($site, $m[1]); if ($h !== '') return $h;
            // 2026-10-09 : ville inconnue ou < 5 offres -> offres du département (recherche noindex) plutôt qu'une 404 (URL devinées par ChatGPT)
            $to = '/offres-emploi/';
            try { $cdb = new PDO('sqlite:' . dirname(__DIR__) . '/data/sites/ma-commune.decouverte.org.sqlite'); $cs = $cdb->prepare('SELECT dept FROM communes WHERE slug=? ORDER BY pop DESC LIMIT 1'); $cs->execute([$m[1]]);
                if ($dc = (string)$cs->fetchColumn()) $to = '/chercher/?l=' . rawurlencode(defined('DEPTS') && isset(DEPTS[$dc]) ? DEPTS[$dc] : $dc); } catch (Throwable $e) {}
            header('Location: ' . $to, true, 302); return null; }
        if (preg_match('#^/offres-emploi/([a-z\-]+)/(?:page/(\d+)/)?$#', $path, $m) && ($reg = region_by_slug($m[1]))) {
            $c = (string)($_GET['contrat'] ?? '');
            return page_jobs_region($site, $reg, max(1, (int)($m[2] ?? 1)), isset(JOB_CONTRACTS[$c]) ? $c : '');
        }
        if (preg_match('#^/(localisation|region)/#', $path) && ($to = legacy_job_redirect($site, $path))) { header('Location: ' . $to, true, 301); return null; }
        if (($to = legacy_offre_redirect($site, $path)) !== null) { header('Location: ' . $to, true, 301); return null; } // anciennes URL WordPress /offre-d-emploi/…
        if ($path === '/recruteur/') return page_companies_index($site);
        if (preg_match('#^/recruteur/([a-z0-9\-]+)/(?:([a-z0-9\-]+)/)?$#', $path, $m)) { $h = page_company($site, $m[1], $m[2] ?? ''); return $h === 'REDIRECT' ? null : $h; }
        if (preg_match('#^/(alternance|emploi-cdi|emploi-cdd|interim|emploi-sans-diplome|emploi-debutant|job-etudiant|job-week-end|emploi-teletravail|emploi-temps-partiel|emploi-urgent)/(?:([a-z\-]+)/)?(?:page/(\d+)/)?$#', $path, $m)) {
            $reg = !empty($m[2]) ? region_by_slug($m[2]) : null;
            if (empty($m[2]) || $reg) return page_intent($site, $m[1], $reg, max(1, (int)($m[3] ?? 1)));
        }
        if (preg_match('#^/formation-alternance/(?:(?!page/)([a-z\-]+)/)?(?:page/(\d+)/)?$#', $path, $m)) { $rg = !empty($m[1]) ? region_by_slug($m[1]) : null; if (empty($m[1]) || $rg) { if (!$rg && !empty($m[2])) return ''; return page_formations($site, $rg, max(1, (int)($m[2] ?? 1))); } }
        if (preg_match('#^/entreprises-alternance/(?:(?!page/)([a-z\-]+)/)?(?:page/(\d+)/)?$#', $path, $m)) { $rg = !empty($m[1]) ? region_by_slug($m[1]) : null; if (empty($m[1]) || $rg) { if (!$rg && !empty($m[2])) return ''; return page_entreprises_alt($site, $rg, max(1, (int)($m[2] ?? 1))); } }
        if ($path === '/mission-locale/') return page_missions($site, null);
        if (preg_match('#^/mission-locale/([a-z0-9\-]+)/$#', $path, $m) && ($dp = dept_by_slug($m[1]))) return page_missions($site, $dp);
        if ($path === '/emploi/') return page_metiers_index($site);
        if ($path === '/barometre-emploi/') return page_barometre($site);
        if ($path === '/salaire/') return page_salaires_index($site);
        // métier / salaire momentanément sans offre (recalcul toutes les 3 h) : redirection temporaire plutôt qu'une 404
        if (preg_match('#^/salaire/([a-z0-9\-]+)/([a-z\-]+)/$#', $path, $m) && function_exists('page_salaire_region')) { $h = page_salaire_region($site, $m[1], $m[2]); if ($h !== '') return $h; header('Location: /salaire/' . $m[1] . '/', true, 302); return null; }
        if (preg_match('#^/salaire/([a-z0-9\-]+)/$#', $path, $m)) { $h = page_salaire($site, $m[1]); if ($h !== '') return $h; header('Location: /emploi/' . $m[1] . '/', true, 302); return null; }
        if (preg_match('#^/emploi/([a-z0-9\-]+)/(?:([a-z0-9\-]+)/)?$#', $path, $m)) { $h = page_metier($site, $m[1], $m[2] ?? ''); if ($h !== '') return $h;
            if (!empty($m[2])) { header('Location: /emploi/' . $m[1] . '/', true, 302); return null; }
            $jdb = jobs_db($site['host']); $ex = fn($sl) => $sl !== '' && $sl !== $m[1] && (bool)$jdb->query('SELECT 1 FROM job_metiers WHERE slug=' . $jdb->quote($sl))->fetchColumn();
            $cands = [preg_replace('/e$/', '', $m[1]), preg_replace('/s$/', '', $m[1])]; $p = explode('-', $m[1]); while (count($p) > 1) { array_pop($p); $cands[] = implode('-', $p); $cands[] = preg_replace('/e$/', '', implode('-', $p)); }
            foreach ($cands as $cd) if ($ex($cd)) { header('Location: /emploi/' . $cd . '/', true, 301); return null; }
            header('Location: /chercher/?q=' . rawurlencode(str_replace('-', ' ', $m[1])), true, 302); return null; }
        if ($path === '/emploi-public/') return page_emploi_public($site);
        if (preg_match('#^/emploi-public/type/([a-z\-]+)/(?:(?!page/)([a-z0-9\-]+)/)?(?:page/(\d+)/)?$#', $path, $m)) return page_csp_type($site, $m[1], $m[2] ?? '', max(1, (int)($m[3] ?? 1)));
        if (preg_match('#^/emploi-public/departement/([a-z0-9\-]+)/(?:page/(\d+)/)?$#', $path, $m)) return page_csp_type($site, '', $m[1], max(1, (int)($m[2] ?? 1)));
        if (preg_match('#^/emploi-public/versant/(etat|territoriale|hospitaliere)/(?:page/(\d+)/)?$#', $path, $m)) return page_emploi_public_list($site, null, $m[1], max(1, (int)($m[2] ?? 1)));
        if (preg_match('#^/emploi-public/([a-z\-]+)/(?:page/(\d+)/)?$#', $path, $m) && ($reg = region_by_slug($m[1]))) return page_emploi_public_list($site, $reg, '', max(1, (int)($m[2] ?? 1)));
        if ($path === '/emploi-pres-de-chez-moi/' && function_exists('page_proches')) return page_proches($site);
        if ($path === '/emploi-outre-mer/' && function_exists('page_outremer')) return page_outremer($site);
        if ($path === '/simulateur-salaire-brut-net/' && function_exists('page_simulateur')) return page_simulateur($site);
        if (str_starts_with($path, '/emploi-etranger/') && function_exists('ew_route') && ($h = ew_route($site, $path)) !== '') return $h;
        if (str_starts_with($path, '/emploi-saisonnier/') && function_exists('ss_route') && ($h = ss_route($site, $path)) !== '') return $h;
        if (str_starts_with($path, '/emploi-frontalier/') && function_exists('fx_route') && ($h = fx_route($site, $path)) !== '') return $h;
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
