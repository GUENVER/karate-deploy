<?php
// Rendu public : gabarits, SEO (meta, schema.org), monétisation (AdSense, Amazon), maillage interne.
declare(strict_types=1);

function site_categories(array $site): array
{
    return site_db($site['host'])->query("SELECT c.slug, c.name, c.description, COUNT(p.id) n FROM categories c
        LEFT JOIN posts p ON p.category=c.slug AND p.status='publish' GROUP BY c.slug HAVING n>0 ORDER BY n DESC")->fetchAll();
}

function css(array $site): string
{
    $c = preg_match('/^#[0-9a-f]{3,6}$/i', (string)$site['color']) ? $site['color'] : '#0b6e4f';
    return ":root{--c:$c;--t:#1d2327;--m:#5b6670;--b:#e6e8eb;--bg:#fff;--s:#f6f7f8}
*{box-sizing:border-box}body{margin:0;font:18px/1.7 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--t);background:var(--bg)}
a{color:var(--c)}img{max-width:100%;height:auto}.w{max-width:1140px;margin:0 auto;padding:0 16px}
header.top{border-bottom:1px solid var(--b);background:#fff}header.top .w{display:flex;align-items:center;gap:20px;flex-wrap:wrap;padding:14px 16px}
.logo{font-weight:800;font-size:1.35rem;text-decoration:none;color:var(--t)}.logo span{color:var(--c)}
nav.cats{display:flex;gap:14px;flex-wrap:wrap;align-items:center;font-size:.9rem}nav.cats a{text-decoration:none;color:var(--m)}nav.cats a:hover{color:var(--c)}
form.q{margin-left:auto}form.q input{border:1px solid var(--b);border-radius:20px;padding:6px 14px;font-size:.9rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:26px;margin:28px 0}
.card{border:1px solid var(--b);border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
.card img{aspect-ratio:16/9;object-fit:cover;width:100%;display:block;background:var(--s)}
.card .in{padding:14px 18px 18px}.card h2,.card h3{font-size:1.1rem;line-height:1.35;margin:.3em 0}.card h2 a,.card h3 a{color:var(--t);text-decoration:none}
.card p{color:var(--m);font-size:.92rem;margin:.4em 0 0}.kick{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--c);font-weight:700;text-decoration:none}
.layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:48px;margin:30px auto}
@media(max-width:900px){.layout{grid-template-columns:minmax(0,1fr)}aside{order:2}}
article h1{font-size:2.1rem;line-height:1.2;margin:.2em 0 .4em}article h2{font-size:1.5rem;line-height:1.3;margin:1.8em 0 .5em}article h3{font-size:1.2rem;margin:1.4em 0 .4em}
.meta{color:var(--m);font-size:.88rem}.crumbs{font-size:.85rem;color:var(--m)}.crumbs a{color:var(--m)}
.hero{border-radius:12px;margin:18px 0;aspect-ratio:16/9;object-fit:cover;width:100%}.credit{font-size:.75rem;color:var(--m);margin-top:-12px}
.toc{background:var(--s);border-radius:10px;padding:14px 20px;margin:20px 0;font-size:.95rem}.toc strong{display:block;margin-bottom:6px}.toc ol{margin:0;padding-left:20px}
table{border-collapse:collapse;width:100%;margin:1em 0;font-size:.95rem;display:block;overflow-x:auto}th,td{border:1px solid var(--b);padding:8px 10px;text-align:left}th{background:var(--s)}
blockquote{border-left:4px solid var(--c);margin:1em 0;padding:.3em 1em;background:var(--s)}
.lead{font-size:1.1rem;background:var(--s);border-left:4px solid var(--c);padding:14px 18px;border-radius:0 10px 10px 0}
.amz{border:2px solid #ff9900;border-radius:12px;padding:16px 20px;margin:28px 0;background:#fffaf2}.amz h3{margin:0 0 10px;font-size:1.1rem}
.amz ul{list-style:none;padding:0;margin:0}.amz li{padding:10px 0;border-top:1px solid #f3e2c7;display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.amz li:first-child{border-top:0}.amz .btn{background:#ff9900;color:#111;text-decoration:none;font-weight:700;padding:8px 14px;border-radius:8px;font-size:.9rem;white-space:nowrap}
.amz small,.disc{color:var(--m);font-size:.78rem}.faq details{border:1px solid var(--b);border-radius:10px;padding:10px 16px;margin:8px 0}.faq summary{font-weight:600;cursor:pointer}
.ad{margin:26px 0;min-height:100px;text-align:center}aside .box{border:1px solid var(--b);border-radius:12px;padding:16px;margin-bottom:22px}
.kpi{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0}.kpi div{flex:1 1 130px;background:var(--s);border-radius:10px;padding:12px;font-size:.85rem}.kpi b{display:block;font-size:1.4rem;color:var(--c)}
#evcalc .row{display:flex;flex-wrap:wrap;gap:10px}#evcalc label{flex:1 1 140px;font-size:.85rem}#evcalc input{width:100%;padding:7px;border:1px solid var(--b);border-radius:8px;font-size:1rem}aside h4{margin:0 0 10px}
aside ul{padding-left:18px;margin:0;font-size:.93rem}aside li{margin:6px 0}.pag{display:flex;gap:10px;justify-content:center;margin:30px 0}.pag a,.pag span{padding:6px 12px;border:1px solid var(--b);border-radius:8px;text-decoration:none}
.dd{position:relative}.dd summary{cursor:pointer;list-style:none;background:var(--c);color:#fff;padding:2px 12px;line-height:1.5;border-radius:20px;white-space:nowrap;display:inline-block}.dd summary:hover,.dd[open] summary{filter:brightness(1.12)}.dd summary strong{color:#fff}.dd summary::-webkit-details-marker{display:none}.dd summary:after{content:' ▾';color:#fff}
.ddp{position:absolute;left:0;top:36px;z-index:50;background:#fff;border:1px solid var(--b);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.12);padding:14px 18px;width:min(560px,92vw);display:grid;grid-template-columns:1fr 1fr;gap:2px 22px;font-size:.9rem}
.ddp b{grid-column:1/-1;margin:8px 0 2px;color:var(--c);font-size:.75rem;text-transform:uppercase;letter-spacing:.05em}.ddp a{color:var(--t)!important;padding:3px 0}.ddp a:hover{color:var(--c)!important}
@media(max-width:700px){.ddp{position:static;box-shadow:none;width:100%;margin-top:8px}}
header.hl .hlw{justify-content:center;padding:10px 16px 8px}.hlogo img{height:70px;width:auto;display:block}
.hnav{border-top:1px solid var(--b);background:var(--s)}.hnav .w{display:flex;align-items:center;gap:14px;padding:8px 16px}
.hnav nav.cats{flex:1;flex-wrap:nowrap;overflow-x:auto;gap:4px;scrollbar-width:none;justify-content:flex-start}.hnav nav.cats::-webkit-scrollbar{display:none}
.hnav nav.cats a{white-space:nowrap;padding:5px 11px;border-radius:8px;color:var(--t);font-weight:500}.hnav nav.cats a:hover,.hnav nav.cats a[aria-current]{background:#fff;color:var(--c);box-shadow:0 1px 3px rgba(0,0,0,.08)}
.hnav form.q{margin-left:0}.hnav form.q input{width:180px;background:#fff}
@media(max-width:700px){.hlogo img{height:50px}.hnav .w{flex-wrap:wrap;gap:8px}.hnav form.q{flex:1 1 100%;order:3}.hnav form.q input{width:100%}.hnav nav.cats{flex:1 1 100%;order:2;-webkit-mask-image:linear-gradient(90deg,#000 82%,transparent);mask-image:linear-gradient(90deg,#000 82%,transparent);padding-right:30px}}
pre{white-space:pre-wrap;word-break:break-word;overflow-x:auto;max-width:100%;background:var(--s);padding:12px;border-radius:8px;font-size:.85rem}main,article{min-width:0;overflow-wrap:break-word}main iframe,main video{max-width:100%}.layout>*{min-width:0}
footer.bot{border-top:1px solid var(--b);margin-top:50px;padding:26px 0;color:var(--m);font-size:.88rem;background:var(--s)}footer.bot a{color:var(--m);margin-right:14px}";
}

function layout(array $site, array $m, string $body): string
{
    $pub = setting('adsense_pub', 'ca-pub-8104956615440701');
    $head = '<title>' . h($m['title']) . '</title><meta name="description" content="' . h($m['desc'] ?? '') . '">';
    $head .= '<meta name="robots" content="' . ($m['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1') . '">';
    if (!empty($m['canonical'])) $head .= '<link rel="canonical" href="' . h($m['canonical']) . '">';
    $head .= '<meta property="og:site_name" content="' . h($site['name']) . '"><meta property="og:title" content="' . h($m['og_title'] ?? $m['title']) . '">';
    $head .= '<meta property="og:description" content="' . h($m['desc'] ?? '') . '"><meta property="og:type" content="' . ($m['og_type'] ?? 'website') . '">';
    if (!empty($m['canonical'])) $head .= '<meta property="og:url" content="' . h($m['canonical']) . '">';
    if (!empty($m['image'])) $head .= '<meta property="og:image" content="' . h($m['image']) . '"><meta name="twitter:card" content="summary_large_image">';
    $head .= '<link rel="alternate" type="application/rss+xml" title="' . h($site['name']) . '" href="/feed/">';
    foreach ($m['schema'] ?? [] as $sc) $head .= '<script type="application/ld+json">' . json_encode($sc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    if ($site['adsense'] && $pub && empty($m['noads'])) $head .= '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . h($pub) . '" crossorigin="anonymous"></script>'; // chargement immédiat (le chargement différé faisait perdre les visiteurs rapides)
    if ($ga = (setting('ga4_id:' . $site['host'], '') ?: setting('ga4_id', ''))) $head .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . h($ga) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config","' . h($ga) . '");</script>';

    $nav = ''; $navJobs = '';
    if (!empty($site['fuel'])) $nav .= '<a href="/prix-carburant/"><strong>Prix carburant</strong></a>';
    if (function_exists('places_mod') && ($pm = places_mod($site))) $nav .= '<a href="/' . $pm['prefix'] . '/"><strong>' . h($pm['nav']) . '</strong></a>';
    if (!empty($site['commune'])) $nav .= '<a href="/commune/"><strong>Fiches communes</strong></a>';
    if (!empty($site['dpe'])) $nav .= '<a href="/dpe/"><strong>DPE de votre commune</strong></a>';
    if (!empty($site['ev'])) $nav .= '<a href="/bornes-recharge/"><strong>Bornes de recharge</strong></a>';
    if (!empty($site['jobs']) && function_exists('jobs_db') && jobs_db($site['host'])->query("SELECT 1 FROM jobs WHERE status='open' LIMIT 1")->fetchColumn()) $navJobs = jobs_menu($site);
    foreach (array_slice(site_categories($site), 0, 7) as $c) $nav .= '<a href="/category/' . h($c['slug']) . '/"' . (str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/category/' . $c['slug'] . '/') ? ' aria-current="page"' : '') . '>' . h(nav_label($c['name'])) . '</a>';
    $parts = explode(' ', $site['name'], 2);
    $logo = h($parts[0]) . (isset($parts[1]) ? ' <span>' . h($parts[1]) . '</span>' : '');
    $year = gmdate('Y');
    $amzDisc = $site['amazon'] && setting('amazon_tag', '') ? '<p class="disc">En tant que Partenaire Amazon, ' . h($site['name']) . ' réalise un bénéfice sur les achats remplissant les conditions requises. Les liens marqués « Voir sur Amazon » sont des liens d\'affiliation.</p>' : '';

    return '<!doctype html><html lang="' . h($site['lang'] ?: 'fr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . $head . '<style>' . css($site) . (!empty($site['jobs']) ? jobs_css() : '') . '</style></head><body' . (!empty($site['jobs']) ? ' class="jobs"' : '') . '>'
        . (($lg = setting('logo:' . $site['host'], ''))
            ? '<header class="top hl"><div class="w hlw"><a class="hlogo" href="/" aria-label="' . h($site['name']) . ' — accueil"><img src="' . h($lg) . '" alt="' . h($site['name']) . '" width="288" height="70" fetchpriority="high"></a></div>'
              . '<div class="hnav"><div class="w">' . $navJobs . '<nav class="cats" aria-label="Rubriques">' . $nav . '</nav>'
              . '<form class="q" action="' . (!empty($site['jobs']) ? '/chercher/' : '/recherche/') . '" method="get"><input type="search" name="q" placeholder="' . (!empty($site['jobs']) ? 'Métier, mot-clé…' : 'Rechercher…') . '" aria-label="Rechercher"></form></div></div></header>'
            : '<header class="top"><div class="w"><a class="logo" href="/">' . $logo . '</a><nav class="cats">' . $navJobs . $nav . '</nav>'
              . '<form class="q" action="/recherche/" method="get"><input type="search" name="q" placeholder="Rechercher…" aria-label="Rechercher"></form></div></header>')
        . '<main class="w">' . $body . '</main>'
        . '<footer class="bot"><div class="w"><p><strong>' . h($site['name']) . '</strong> — ' . h($site['tagline']) . '</p>'
        . '<p><a href="/a-propos/">À propos</a><a href="/contact/">Contact</a><a href="/mentions-legales/">Mentions légales</a>' . (!empty($site['jobs']) ? '<a href="/nos-sources/">Nos sources</a><a href="/barometre-emploi/">Baromètre de l\'emploi</a>' : '') . '<a href="/confidentialite/">Confidentialité</a><a href="/plan-du-site/">Plan du site</a></p>'
        . $amzDisc . '<p>© ' . $year . ' ' . h($site['name']) . '</p></div></footer><script>setTimeout(function(){var d=new FormData();d.append("p",location.pathname);navigator.sendBeacon("/_pv",d)},1500)</script></body></html>';
}

// Menu « Offres d'emploi » déroulant : toutes les offres, régions, grandes villes.
function jobs_menu(array $site): string
{
    // mis en cache 1 h : ce menu est affiché sur toutes les pages (requêtes de comptage coûteuses)
    $cf = cfg('data_dir') . '/cache/' . $site['host'] . '.menu.html';
    if (is_file($cf) && filemtime($cf) > time() - 3600) return (string)file_get_contents($cf);
    $out = jobs_menu_build($site);
    @file_put_contents($cf, $out, LOCK_EX);
    return $out;
}

function jobs_menu_build(array $site): string
{
    $db = jobs_db($site['host']);
    $counts = [];
    foreach ($db->query("SELECT region, COUNT(*) n FROM jobs WHERE status='open' GROUP BY region") as $r) $counts[$r['region']] = (int)$r['n'];
    $hasCsp = (bool)$db->query("SELECT 1 FROM jobs WHERE status='open' AND id LIKE 'csp-%' LIMIT 1")->fetchColumn();
    $o = '<a href="/offres-emploi/"><strong>Toutes les offres</strong></a>' . ($hasCsp ? '<a href="/emploi-public/"><strong>Emploi public</strong></a>' : '<span></span>') . '<a href="/emploi/"><strong>Offres par métier</strong></a><a href="/salaire/"><strong>Salaires par métier</strong></a><a href="/barometre-emploi/"><strong>Baromètre de l\'emploi</strong></a><span></span><b>Par région</b>';
    foreach (JOB_REGIONS as $code => [$slug, $name]) if (!empty($counts[$code])) $o .= '<a href="/offres-emploi/' . $slug . '/">' . h($name) . '</a>';
    try { $cities = $db->query('SELECT slug, name FROM job_cities ORDER BY n DESC LIMIT 12')->fetchAll(); } catch (Throwable $e) { $cities = []; }
    if ($cities) { $o .= '<b>Grandes villes</b>'; foreach ($cities as $c) $o .= '<a href="/offres-emploi/ville/' . h($c['slug']) . '/">' . h($c['name']) . '</a>'; }
    return '<details class="dd"><summary><strong>Offres d\'emploi</strong></summary><nav class="ddp" aria-label="Offres d\'emploi par région">' . $o . '</nav></details>'
        . '<script>document.addEventListener("click",function(e){document.querySelectorAll("details.dd[open]").forEach(function(d){if(!d.contains(e.target))d.removeAttribute("open")})});if(matchMedia("(hover:hover)").matches)document.querySelectorAll("details.dd").forEach(function(d){d.addEventListener("mouseenter",function(){d.open=true});d.addEventListener("mouseleave",function(){d.open=false})})</script>';
}

// Accès direct aux offres par région en tête de l'accueil d'un site d'emploi.
function jobs_home_regions(array $site): string
{
    $db = jobs_db($site['host']);
    $counts = [];
    foreach ($db->query("SELECT region, COUNT(*) n FROM jobs WHERE status='open' GROUP BY region") as $r) $counts[$r['region']] = (int)$r['n'];
    if (!$counts) return '';
    $nf = fn($n) => number_format($n, 0, ',', ' ');
    $total = array_sum($counts);
    $nm = function_exists('metier_tables') ? (metier_tables($db) ?? 0) + (int)$db->query('SELECT COUNT(*) FROM job_metiers')->fetchColumn() : 0;
    $o = '<section class="jhome" style="margin:22px 0 30px"><h1 style="margin:0 0 6px">' . $nf($total) . ' offres d\'emploi en France</h1>'
        . '<div class="jhome-stats"><div><b>' . $nf($total) . '</b>offres ouvertes</div>' . ($nm ? '<div><b>' . $nf($nm) . '</b>métiers</div>' : '') . '<div><b>' . count($counts) . '</b>régions</div><div><b>' . $nf((int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND created_at>=datetime('now','-1 day')")->fetchColumn()) . '</b>nouvelles en 24 h</div></div>'
        . '<p style="margin:0 0 6px">CDI, CDD, intérim, alternance et emploi public, dans toutes les régions. Mis à jour le ' . h(date_fr(now())) . '.</p>'
        . '<form action="/chercher/" method="get" style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 4px"><input name="q" placeholder="Métier, mot-clé, entreprise" aria-label="Métier" style="flex:2 1 220px;padding:11px;border:1px solid var(--b);border-radius:8px;font-size:1rem"><input name="l" placeholder="Ville, département ou région" aria-label="Lieu" style="flex:1 1 160px;padding:11px;border:1px solid var(--b);border-radius:8px;font-size:1rem"><button style="background:var(--c);color:#fff;border:0;border-radius:8px;padding:11px 20px;font-weight:700;font-size:1rem">Rechercher</button></form>';
    if (function_exists('fr_map')) $o .= '<style>' . fr_map_css() . '</style><div style="display:flex;flex-wrap:wrap;gap:24px;align-items:flex-start"><div style="flex:1 1 380px">' . fr_map($counts, '/offres-emploi/') . '</div><div style="flex:1 1 300px">';
    $l = '';
    foreach (JOB_REGIONS as $code => [$slug, $name]) if (!empty($counts[$code])) $l .= '<a href="/offres-emploi/' . $slug . '/">' . h($name) . ' <small>(' . $nf($counts[$code]) . ')</small></a>';
    $o .= '<h2 style="font-size:1.15rem;margin:8px 0">Par région</h2><div style="display:flex;flex-wrap:wrap;gap:6px 16px;font-size:.95rem">' . $l . '</div>';
    if (function_exists('metier_tables')) {
        metier_tables($db);
        $top = $db->query('SELECT slug, name, n FROM job_metiers ORDER BY n DESC LIMIT 14')->fetchAll();
        if ($top) $o .= '<h2 style="font-size:1.15rem;margin:16px 0 4px">Métiers qui recrutent</h2>' . count_chips(array_map(fn($r) => [$r['name'], $r['n'], '/emploi/' . $r['slug'] . '/'], $top));
        $o .= str_replace('<h2>', '<h2 style="font-size:1.15rem;margin:16px 0 4px">', intents_chips($db));
    }
    $np = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND id LIKE 'csp-%'")->fetchColumn();
    $na = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND contract='alternance'")->fetchColumn();
    $o .= '<p style="margin:10px 0 0;display:flex;flex-wrap:wrap;gap:8px">'
        . ($np ? '<a class="btn" style="background:var(--c);color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none" href="/emploi-public/">Emploi public (' . $nf($np) . ')</a>' : '')
        . ($na ? '<a class="btn" style="background:var(--c);color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none" href="/offres-emploi/' . JOB_REGIONS['11'][0] . '/?contrat=alternance">Alternance (' . $nf($na) . ')</a>' : '')
        . '<a class="btn" style="background:#fff;color:var(--c);border:1px solid var(--c);padding:7px 14px;border-radius:8px;text-decoration:none" href="/emploi/">Tous les métiers</a></p>';
    if (function_exists('fr_map')) $o .= '</div></div>';
    $latest = $db->query("SELECT * FROM jobs WHERE status='open' ORDER BY created_at DESC LIMIT 6")->fetchAll();
    $o .= '<h2>Dernières offres publiées</h2><div class="grid">' . implode('', array_map('job_card', $latest)) . '</div><p><a href="/offres-emploi/"><strong>Voir toutes les offres d\'emploi →</strong></a></p></section><h2>Conseils emploi et carrière</h2>';
    return $o;
}

// Libellé de menu en casse française (« Cv Et Lettre De Motivation » -> « CV et lettre de motivation »).
function nav_label(string $n): string
{
    $l = mb_strtolower(trim($n));
    $l = preg_replace_callback('/\b(cv|rh|rgpd|cdi|cdd)\b/u', fn($m) => mb_strtoupper($m[1]), $l);
    return mb_strtoupper(mb_substr($l, 0, 1)) . mb_substr($l, 1);
}

function card(array $p, bool $h2 = true): string
{
    $t = $h2 ? 'h2' : 'h3';
    $img = $p['image'] ? '<a href="' . h($p['path']) . '"><img src="' . h($p['image']) . '" alt="' . h($p['image_alt'] ?: $p['title']) . '" loading="lazy" width="600" height="338"></a>' : '';
    $cat = $p['category'] ? '<a class="kick" href="/category/' . h($p['category']) . '/">' . h($p['cat_name'] ?? $p['category']) . '</a>' : '';
    return '<div class="card">' . $img . '<div class="in">' . $cat . "<$t><a href=\"" . h($p['path']) . '">' . h($p['title']) . "</a></$t><p>" . h(mb_strimwidth(strip_tags((string)$p['excerpt']), 0, 160, '…')) . '</p></div></div>';
}

function list_posts(array $site, string $where, array $args, int $page, int $per = 18): array
{
    $db = site_db($site['host']);
    $st = $db->prepare("SELECT COUNT(*) FROM posts p WHERE p.status='publish' $where");
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT p.id,p.path,p.title,p.excerpt,p.image,p.image_alt,p.category,p.published_at,c.name cat_name FROM posts p LEFT JOIN categories c ON c.slug=p.category
        WHERE p.status='publish' $where ORDER BY p.published_at DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($args);
    return [$st->fetchAll(), (int)ceil($total / $per), $total];
}

function pager(string $base, int $page, int $pages): string
{
    if ($pages < 2) return '';
    $o = '<nav class="pag">';
    if ($page > 1) $o .= '<a href="' . h($page == 2 ? $base : $base . 'page/' . ($page - 1) . '/') . '" rel="prev">← Précédent</a>';
    $o .= '<span>Page ' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) $o .= '<a href="' . h($base . 'page/' . ($page + 1) . '/') . '" rel="next">Suivant →</a>';
    return $o . '</nav>';
}

function org_schema(array $site): array
{
    return ['@type' => 'Organization', 'name' => $site['name'], 'url' => 'https://' . $site['host'] . '/'];
}

function page_home(array $site, int $page): string
{
    [$posts, $pages] = list_posts($site, '', [], $page);
    if ($page > 1 && !$posts) return '';
    $body = $page == 1 ? '<h1 style="margin:28px 0 0;font-size:1.7rem">' . h($site['name']) . ' — ' . h($site['tagline']) . '</h1>' : '<h1 style="margin:28px 0 0;font-size:1.5rem">Articles — page ' . $page . '</h1>';
    if ($page == 1 && !empty($site['jobs']) && function_exists('jobs_db') && ($jh = jobs_home_regions($site)) !== '') $body = $jh; // site d'emploi : l'accueil commence par les offres (h1 unique)
    if ($page == 1 && !empty($site['fuel']) && function_exists('fuel_home_block')) $body .= fuel_home_block($site) . '<h2>Nos derniers guides</h2>';
    if ($page == 1 && function_exists('places_mod') && places_mod($site) && ($pb = places_home_block($site))) $body .= $pb . '<h2>Nos derniers guides</h2>';
    if ($page == 1 && !empty($site['commune']) && function_exists('commune_home_block') && ($cb = commune_home_block($site))) $body .= $cb . '<h2>Nos derniers guides</h2>';
    if ($page == 1 && !empty($site['dpe']) && function_exists('dpe_home_block') && ($db_ = dpe_home_block($site))) $body .= $db_ . '<h2>Nos derniers guides</h2>';
    if ($page == 1 && !empty($site['ev']) && function_exists('ev_home_block') && ($eb = ev_home_block($site))) $body .= $eb . '<h2>Nos derniers guides</h2>';
    $body .= '<div class="grid">' . implode('', array_map('card', $posts)) . '</div>' . pager('/', $page, $pages);
    $url = 'https://' . $site['host'] . '/' . ($page > 1 ? "page/$page/" : '');
    return layout($site, [
        'title' => $site['name'] . ($page > 1 ? " — page $page" : ' — ' . $site['tagline']), 'desc' => $site['tagline'] . '. ' . mb_strimwidth((string)$site['niche'], 0, 120, '…'),
        'canonical' => $url,
        'schema' => [['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $site['name'], 'url' => 'https://' . $site['host'] . '/',
            'alternateName' => $site['host'], 'inLanguage' => 'fr-FR', 'publisher' => ['@id' => 'https://' . $site['host'] . '/#organization'],
            'potentialAction' => ['@type' => 'SearchAction', 'target' => 'https://' . $site['host'] . (!empty($site['jobs']) ? '/chercher/?q={q}' : '/recherche/?q={q}'), 'query-input' => 'required name=q']],
            array_filter(['@context' => 'https://schema.org', '@type' => 'Organization', '@id' => 'https://' . $site['host'] . '/#organization', 'name' => $site['name'], 'url' => 'https://' . $site['host'] . '/',
                'logo' => ($lg = setting('logo:' . $site['host'], '')) ? ['@type' => 'ImageObject', 'url' => 'https://' . $site['host'] . strtok($lg, '?'), 'width' => 577, 'height' => 140] : null,
                'description' => $site['tagline'] ?: null, 'email' => setting('contact_email', '') ?: null, 'areaServed' => !empty($site['jobs']) ? 'FR' : null])],
    ], $body);
}

function page_category(array $site, string $slug, int $page): string
{
    $db = site_db($site['host']);
    $st = $db->prepare('SELECT * FROM categories WHERE slug=?');
    $st->execute([$slug]);
    if (!$cat = $st->fetch()) return '';
    [$posts, $pages, $total] = list_posts($site, 'AND p.category=?', [$slug], $page);
    if (!$posts) return '';
    $base = "/category/$slug/";
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › ' . h($cat['name']) . '</p><h1>' . h($cat['name']) . '</h1>'
        . ($cat['description'] && $page == 1 ? '<p>' . h($cat['description']) . '</p>' : '')
        . '<div class="grid">' . implode('', array_map('card', $posts)) . '</div>' . pager($base, $page, $pages);
    return layout($site, [
        'title' => $cat['name'] . ($page > 1 ? " — page $page" : '') . ' | ' . $site['name'],
        'desc' => $cat['description'] ?: ("Tous nos articles « {$cat['name']} » : $total guides et conseils pratiques."),
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''),
        'schema' => [breadcrumbs($site, [[$cat['name'], $base]])],
    ], $body);
}

function page_search(array $site, string $q): string
{
    $q = trim(mb_substr($q, 0, 80));
    $posts = [];
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], '', $q) . '%';
        $st = site_db($site['host'])->prepare("SELECT p.*, c.name cat_name FROM posts p LEFT JOIN categories c ON c.slug=p.category WHERE p.status='publish' AND (p.title LIKE ? OR p.keyword LIKE ?) ORDER BY p.published_at DESC LIMIT 30");
        $st->execute([$like, $like]);
        $posts = $st->fetchAll();
    }
    $body = '<h1 style="margin-top:28px">Recherche : ' . h($q) . '</h1>' . ($posts ? '<div class="grid">' . implode('', array_map('card', $posts)) . '</div>' : '<p>Aucun résultat.</p>');
    return layout($site, ['title' => 'Recherche | ' . $site['name'], 'desc' => '', 'robots' => 'noindex,follow'], $body);
}

function breadcrumbs(array $site, array $items): array
{
    $l = [['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => 'https://' . $site['host'] . '/']];
    foreach ($items as $i => [$n, $u]) $l[] = ['@type' => 'ListItem', 'position' => $i + 2, 'name' => $n, 'item' => 'https://' . $site['host'] . $u];
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $l];
}

// Ajoute des id aux H2 et renvoie la table des matières.
function add_toc(string &$html): string
{
    $toc = []; $used = [];
    $html = preg_replace_callback('#<h2>(.*?)</h2>#is', function ($m) use (&$toc, &$used) {
        $id = slugify(strip_tags($m[1]), 60); $b = $id; $i = 2;
        while (isset($used[$id])) $id = $b . '-' . $i++;
        $used[$id] = 1; $toc[] = [$id, strip_tags($m[1])];
        return '<h2 id="' . $id . '">' . $m[1] . '</h2>';
    }, $html);
    if (count($toc) < 3) return '';
    return '<nav class="toc"><strong>Sommaire</strong><ol>' . implode('', array_map(fn($t) => '<li><a href="#' . $t[0] . '">' . h($t[1]) . '</a></li>', $toc)) . '</ol></nav>';
}

// Maillage interne : lie la 1re occurrence du mot-clé d'autres articles (hors titres et liens existants).
function autolink(string $html, array $targets, int $max = 6): string
{
    if (!$targets) return $html;
    usort($targets, fn($a, $b) => mb_strlen($b['kw']) <=> mb_strlen($a['kw']));
    $parts = preg_split('#(<[^>]+>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $skip = 0; $done = 0; $usedPaths = [];
    foreach ($parts as &$p) {
        if ($p === '' ) continue;
        if ($p[0] === '<') {
            if (preg_match('#^<(a|h[1-6])\b#i', $p)) $skip++;
            elseif (preg_match('#^</(a|h[1-6])>#i', $p)) $skip = max(0, $skip - 1);
            continue;
        }
        if ($skip || $done >= $max) continue;
        foreach ($targets as $t) {
            if ($done >= $max || isset($usedPaths[$t['path']])) continue;
            $re = '/(?<![\p{L}\p{N}])(' . preg_quote($t['kw'], '/') . ')(?![\p{L}\p{N}])/iu';
            if (preg_match($re, $p)) {
                $p = preg_replace($re, '<a href="' . h($t['path']) . '">$1</a>', $p, 1);
                $usedPaths[$t['path']] = 1; $done++;
                break;
            }
        }
    }
    return implode('', $parts);
}

function amazon_box(array $site, array $products): string
{
    $tag = setting('amazon_tag', '');
    if (!$site['amazon'] || !$tag || !$products) return '';
    $li = '';
    foreach (array_slice($products, 0, 4) as $pr) {
        $q = trim((string)($pr['q'] ?? $pr['query'] ?? ''));
        if ($q === '') continue;
        $url = 'https://www.amazon.fr/s?k=' . rawurlencode($q) . '&tag=' . rawurlencode($tag);
        $li .= '<li><div><strong>' . h($pr['label'] ?? $q) . '</strong>' . (!empty($pr['why']) ? '<br><small>' . h($pr['why']) . '</small>' : '') . '</div>'
            . '<a class="btn" href="' . h($url) . '" rel="sponsored nofollow noopener" target="_blank">Voir sur Amazon</a></li>';
    }
    return $li ? '<div class="amz"><h3>Notre sélection pour aller plus loin</h3><ul>' . $li . '</ul><small>Lien affilié : nous pouvons percevoir une commission, sans surcoût pour vous.</small></div>' : '';
}

function ad_unit(array $site, string $slotKey): string
{
    $slot = setting('adsense_slot_' . $slotKey, '');
    $pub = setting('adsense_pub', '');
    if (!$site['adsense'] || !$slot || !$pub) return '';
    return '<div class="ad"><ins class="adsbygoogle" style="display:block;text-align:center" data-ad-layout="in-article" data-ad-format="fluid" data-ad-client="' . h($pub) . '" data-ad-slot="' . h($slot) . '"></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></div>';
}

// Insère des blocs après le n-ième </h2>…section : on insère avant le (n+1)-ième <h2.
function insert_before_h2(string $html, int $n, string $block): string
{
    if ($block === '') return $html;
    $i = 0;
    $out = preg_replace_callback('#<h2[\s>]#i', function ($m) use (&$i, $n, $block) { $i++; return $i === $n ? $block . $m[0] : $m[0]; }, $html);
    return $i >= $n ? $out : $html . $block;
}

function page_post(array $site, array $p): string
{
    $db = site_db($site['host']);
    $cat = null;
    if ($p['category']) { $st = $db->prepare('SELECT * FROM categories WHERE slug=?'); $st->execute([$p['category']]); $cat = $st->fetch() ?: null; }
    $html = (string)$p['content'];

    // Maillage interne : mots-clés des autres articles (les plus récents / même catégorie en priorité).
    $st = $db->prepare("SELECT path, keyword kw FROM posts WHERE status='publish' AND id<>? AND keyword<>'' AND length(keyword) BETWEEN 6 AND 60 ORDER BY (category=?) DESC, published_at DESC LIMIT 400");
    $st->execute([$p['id'], (string)$p['category']]);
    $html = autolink($html, $st->fetchAll());

    $toc = add_toc($html);
    $html = insert_before_h2($html, 2, ad_unit($site, 'in_article'));
    $html = insert_before_h2($html, 4, amazon_box($site, json_decode((string)$p['products'], true) ?: []));
    $html = insert_before_h2($html, 6, ad_unit($site, 'in_article'));

    $faq = json_decode((string)$p['faq'], true) ?: [];
    $faqHtml = '';
    if ($faq) {
        $faqHtml = '<section class="faq"><h2 id="faq">Questions fréquentes</h2>';
        foreach ($faq as $f) $faqHtml .= '<details><summary>' . h($f['q']) . '</summary><p>' . h($f['a']) . '</p></details>';
        $faqHtml .= '</section>';
    }

    $st = $db->prepare("SELECT p.path,p.title,p.excerpt,p.image,p.image_alt,p.category,c.name cat_name FROM posts p LEFT JOIN categories c ON c.slug=p.category WHERE p.status='publish' AND p.id<>? AND p.category=? ORDER BY p.published_at DESC LIMIT 6");
    $st->execute([$p['id'], (string)$p['category']]);
    $related = $st->fetchAll();
    $st = $db->query("SELECT path,title FROM posts WHERE status='publish' ORDER BY views DESC, published_at DESC LIMIT 8");
    $popular = $st->fetchAll();

    $url = post_url($site, $p);
    $pub = substr((string)$p['published_at'], 0, 10);
    $upd = substr((string)($p['updated_at'] ?: $p['published_at']), 0, 10);
    $mins = max(2, (int)round($p['words'] / 230));
    $crumb = $cat ? [[$cat['name'], '/category/' . $cat['slug'] . '/']] : [];

    $body = '<div class="layout"><article>'
        . '<p class="crumbs"><a href="/">Accueil</a>' . ($cat ? ' › <a href="/category/' . h($cat['slug']) . '/">' . h($cat['name']) . '</a>' : '') . '</p>'
        . '<h1>' . h($p['title']) . '</h1>'
        . '<p class="meta">Par la rédaction de ' . h($site['name']) . ' · Publié le ' . date_fr($pub) . ($upd !== $pub ? ' · Mis à jour le ' . date_fr($upd) : '') . ' · ' . $mins . ' min de lecture</p>'
        . ($p['image'] ? '<img class="hero" src="' . h($p['image']) . '" alt="' . h($p['image_alt'] ?: $p['title']) . '" width="1200" height="675" fetchpriority="high">' . ($p['image_credit'] ? '<p class="credit">' . h($p['image_credit']) . '</p>' : '') : '')
        . $toc . $html . $faqHtml
        . ($related ? '<h2>À lire aussi</h2><div class="grid">' . implode('', array_map(fn($r) => card($r, false), $related)) . '</div>' : '')
        . '</article><aside>'
        . '<div class="box"><h4>Les plus lus</h4><ul>' . implode('', array_map(fn($r) => '<li><a href="' . h($r['path']) . '">' . h($r['title']) . '</a></li>', $popular)) . '</ul></div>'
        . '<div class="box"><h4>Rubriques</h4><ul>' . implode('', array_map(fn($c) => '<li><a href="/category/' . h($c['slug']) . '/">' . h($c['name']) . '</a> (' . $c['n'] . ')</li>', array_slice(site_categories($site), 0, 12))) . '</ul></div>'
        . '</aside></div>';

    $schema = [[
        '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => mb_substr($p['title'], 0, 110), 'description' => $p['meta_desc'],
        'datePublished' => str_replace(' ', 'T', $p['published_at']) . 'Z', 'dateModified' => str_replace(' ', 'T', $p['updated_at'] ?: $p['published_at']) . 'Z',
        'mainEntityOfPage' => $url, 'image' => $p['image'] ? [abs_url($site, $p['image'])] : null, 'wordCount' => (int)$p['words'],
        'author' => org_schema($site), 'publisher' => org_schema($site), 'inLanguage' => $site['lang'] ?: 'fr',
    ], breadcrumbs($site, array_merge($crumb, [[$p['title'], $p['path']]]))];
    if ($faq) $schema[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faq)];

    return layout($site, [
        'title' => $p['meta_title'] ?: $p['title'], 'og_title' => $p['title'], 'desc' => $p['meta_desc'] ?: mb_strimwidth(strip_tags((string)$p['excerpt']), 0, 155, '…'),
        'canonical' => $url, 'image' => $p['image'] ? abs_url($site, $p['image']) : '', 'og_type' => 'article', 'schema' => $schema,
    ], $body);
}

function date_fr(string $d): string
{
    $m = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $t = strtotime($d) ?: time();
    return (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t);
}

function page_static(array $site, string $key): string
{
    // adresse de contact propre au domaine du site ({domaine} = domaine racine, ex. contact@decouverte.org)
    $root = implode('.', array_slice(explode('.', $site['host']), -2));
    $email = str_replace('{domaine}', $root, setting('contact_email', 'contact@{domaine}'));
    $editor = trim((string)setting('editor_name', ''));
    $pages = [
        'a-propos' => ['À propos', '<p>' . h($site['name']) . ' est un site d\'information consacré à : ' . h($site['niche']) . '.</p><p>Notre objectif : proposer des guides clairs, pratiques et régulièrement mis à jour. Les contenus sont préparés par notre rédaction avec l\'aide d\'outils d\'intelligence artificielle, puis contrôlés automatiquement (structure, doublons, sources).</p><p>Une erreur, une suggestion ? <a href="/contact/">Contactez-nous</a>.</p>'],
        'contact' => ['Contact', '<p>Pour toute question, correction ou proposition de partenariat : <a href="mailto:' . h($email) . '">' . h($email) . '</a>.</p>'],
        'mentions-legales' => ['Mentions légales', '<p><strong>Éditeur :</strong> ' . ($editor !== '' ? h($editor) : 'l\'équipe de ' . h($site['name']) . ', éditeur non professionnel. Conformément à l\'article 6-III-2 de la loi n° 2004-575 du 21 juin 2004 (LCEN), ses éléments d\'identification ont été communiqués à l\'hébergeur') . '. Contact : ' . h($email) . '.</p><p><strong>Hébergement :</strong> o2switch, Chemin des Pardiaux, 63000 Clermont-Ferrand, France.</p><p>Les informations publiées sont fournies à titre indicatif et ne remplacent pas l\'avis d\'un professionnel.</p>' . ($site['amazon'] ? '<p>Ce site participe au Programme Partenaires d\'Amazon EU, un programme d\'affiliation conçu pour permettre à des sites de percevoir une rémunération grâce à la création de liens vers Amazon.fr.</p>' : '')],
        'confidentialite' => ['Politique de confidentialité', '<p>Ce site ne collecte aucune donnée personnelle directement. Des partenaires tiers, dont Google, utilisent des cookies pour diffuser des annonces en fonction de vos visites sur ce site et d\'autres sites. Vous pouvez désactiver la publicité personnalisée dans les <a href="https://adssettings.google.com" rel="nofollow">paramètres des annonces Google</a>. Pour en savoir plus : <a href="https://policies.google.com/technologies/partner-sites" rel="nofollow">règles de confidentialité des partenaires Google</a>.</p><p>Mesure d\'audience : statistiques anonymes et agrégées.</p>'],
    ];
    if (!empty($site['jobs'])) { // site d'emploi : provenance des offres, transparence (confiance, E-E-A-T)
        $pages['nos-sources'] = ['Nos sources', '<p>' . h($site['name']) . ' rassemble des offres d\'emploi publiques et partenaires, mises à jour plusieurs fois par jour. Nous ne sommes pas recruteur : chaque offre renvoie vers son site d\'origine, où se fait la candidature.</p>'
            . '<h2>D\'où viennent les offres ?</h2><ul>'
            . '<li><strong>France Travail</strong> (ex-Pôle emploi) : offres d\'emploi publiées par les entreprises et partenaires, via l\'API officielle « Offres d\'emploi » (francetravail.io).</li>'
            . '<li><strong>Choisir le service public</strong> : offres des trois fonctions publiques (État, territoriale, hospitalière), plateforme officielle de la DGAFP, données publiques sous Licence Ouverte.</li>'
            . '<li><strong>La bonne alternance</strong> : offres en alternance, formations en apprentissage et entreprises qui recrutent en alternance, via l\'API Apprentissage de l\'État (beta.gouv.fr).</li>'
            . '<li><strong>Adzuna</strong> et <strong>Careerjet</strong> : agrégateurs d\'offres partenaires (liens sponsorisés).</li>'
            . '<li><strong>Missions locales</strong> : annuaire officiel, via l\'API Apprentissage.</li></ul>'
            . '<h2>Mise à jour et retrait des offres</h2><p>Les offres sont actualisées automatiquement plusieurs fois par jour. Une offre pourvue ou expirée est retirée et redirige vers une offre proche encore ouverte. Les salaires médians affichés sont calculés à partir des offres qui indiquent une rémunération.</p>'
            . '<h2>Un problème sur une offre ?</h2><p>Signalez-le à <a href="mailto:' . h($email) . '">' . h($email) . '</a> : nous la retirons rapidement.</p>'];
        $pages['a-propos'][1] .= '<p>Pour les offres d\'emploi, consultez <a href="/nos-sources/">nos sources</a> : France Travail, Choisir le service public, La bonne alternance et nos partenaires.</p>';
    }
    if (!isset($pages[$key])) return '';
    [$t, $c] = $pages[$key];
    return layout($site, ['title' => $t . ' | ' . $site['name'], 'desc' => $t . ' — ' . $site['name'], 'canonical' => 'https://' . $site['host'] . "/$key/", 'noads' => true],
        '<article style="max-width:760px;margin:30px 0"><h1>' . h($t) . '</h1>' . $c . '</article>');
}

// Plan du site lisible par les visiteurs (le sitemap XML reste destiné aux moteurs de recherche).
function page_plan(array $site): string
{
    $li = fn(array $links) => '<ul style="columns:2 260px;padding-left:18px">' . implode('', array_map(fn($l) => '<li><a href="' . h($l[1]) . '">' . h($l[0]) . '</a>' . (isset($l[2]) ? ' <small>(' . number_format((int)$l[2], 0, ',', ' ') . ')</small>' : '') . '</li>', $links)) . '</ul>';
    $b = '<h1 style="margin-top:28px">Plan du site</h1>';
    $b .= '<h2>Pages principales</h2>' . $li([['Accueil', '/'], ['À propos', '/a-propos/'], ['Contact', '/contact/'], ['Mentions légales', '/mentions-legales/'], ['Confidentialité', '/confidentialite/']]
        + (!empty($site['jobs']) ? [5 => ['Nos sources', '/nos-sources/']] : []));
    if (!empty($site['jobs']) && function_exists('jobs_db')) {
        $db = jobs_db($site['host']);
        $counts = [];
        foreach ($db->query("SELECT region, COUNT(*) n FROM jobs WHERE status='open' GROUP BY region") as $r) $counts[$r['region']] = (int)$r['n'];
        $jl = [['Toutes les offres d\'emploi', '/offres-emploi/', array_sum($counts)]];
        if (function_exists('csp_tables') && ($np = (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND id LIKE 'csp-%'")->fetchColumn())) $jl[] = ['Emploi public', '/emploi-public/', $np];
        if (function_exists('metier_tables')) { metier_tables($db); $jl[] = ['Offres par métier', '/emploi/', (int)$db->query('SELECT COUNT(*) FROM job_metiers')->fetchColumn()]; }
        $b .= '<h2>Offres d\'emploi</h2>' . $li($jl);
        $rl = []; foreach (JOB_REGIONS as $code => [$slug, $name]) if (!empty($counts[$code])) $rl[] = ['Emploi ' . $name, '/offres-emploi/' . $slug . '/', $counts[$code]];
        $b .= '<h2>Par région</h2>' . $li($rl);
        jobs_cities_ready($db);
        $b .= '<h2>Par ville</h2>' . $li(array_map(fn($c) => ['Emploi ' . $c['name'], '/offres-emploi/ville/' . $c['slug'] . '/', $c['n']], $db->query('SELECT slug, name, n FROM job_cities ORDER BY name')->fetchAll()));
        if (function_exists('metier_tables')) $b .= '<h2>Métiers qui recrutent le plus</h2>' . $li(array_map(fn($m) => [$m['name'], '/emploi/' . $m['slug'] . '/', $m['n']], $db->query('SELECT slug, name, n FROM job_metiers ORDER BY n DESC LIMIT 60')->fetchAll()))
            . '<p><a href="/emploi/">Tous les métiers de A à Z →</a> · <a href="/salaire/">Salaires par métier →</a></p>'
            . (function_exists('intent_counts') ? '<h2>Recherches populaires</h2>' . $li(array_values(array_filter(array_map(fn($k, $v) => (($c = intent_counts($db)[$k] ?? 0) >= 5) ? [$v[0], '/' . $k . '/', $c] : null, array_keys(JOB_INTENTS), JOB_INTENTS)))) : '');
    }
    $cats = site_categories($site);
    if ($cats) $b .= '<h2>Rubriques</h2>' . $li(array_map(fn($c) => [nav_label($c['name']), '/category/' . $c['slug'] . '/', $c['n']], $cats));
    $b .= '<p class="disc">Pour les moteurs de recherche : <a href="/sitemap.xml">sitemap XML</a>.</p>';
    return layout($site, ['title' => 'Plan du site | ' . $site['name'], 'desc' => 'Plan du site ' . $site['name'] . ' : toutes les rubriques' . (!empty($site['jobs']) ? ', régions, villes et métiers' : '') . '.',
        'canonical' => 'https://' . $site['host'] . '/plan-du-site/', 'noads' => true], $b);
}

function page_404(array $site): string
{
    [$posts] = list_posts($site, '', [], 1, 6);
    return layout($site, ['title' => 'Page introuvable | ' . $site['name'], 'robots' => 'noindex,follow', 'noads' => true],
        '<h1 style="margin-top:28px">Page introuvable</h1><p>Cette page n\'existe pas ou a été déplacée. Voici nos derniers articles :</p><div class="grid">' . implode('', array_map('card', $posts)) . '</div>');
}

// Habillage des sites d'emploi : police Inter (auto-hébergée), couleurs du logo, cartes et fiches d'offres.
function jobs_css(): string
{
    return "@font-face{font-family:Inter;font-style:normal;font-weight:400 800;font-display:swap;src:url(/_m/site/inter-latin.woff2) format('woff2');unicode-range:U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD}"
    . "@font-face{font-family:Inter;font-style:normal;font-weight:400 800;font-display:swap;src:url(/_m/site/inter-latin-ext.woff2) format('woff2');unicode-range:U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF}"
    . ".jobs{--c:#0055a4;--a:#00b09b;--t:#1a2433;--m:#5e6b7a;--b:#e3e8ef;--s:#f4f7fb}"
    . "body.jobs{font-family:Inter,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;font-size:17px;line-height:1.65;background:#fbfcfe}"
    . ".jobs h1,.jobs h2,.jobs h3{font-family:Inter,system-ui,sans-serif;letter-spacing:-.02em;color:#0f1b2d;line-height:1.25}"
    . ".jobs h1{font-size:clamp(1.6rem,3.2vw,2.3rem);font-weight:800;margin:.6em 0 .4em}.jobs h2{font-size:1.35rem;font-weight:750;margin:1.8em 0 .7em;padding-bottom:.35em;border-bottom:2px solid var(--b)}"
    . ".jobs .lead,.jobs main>p:first-of-type{font-size:1.05rem;color:#344255}"
    . ".jobs .crumbs{font-size:.85rem;color:var(--m)}.jobs .crumbs a{color:var(--m)}"
    . ".jobs .grid{gap:16px}"
    . ".jobs .card.jc{background:#fff;border:1px solid var(--b);border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.04);transition:box-shadow .15s,transform .15s,border-color .15s;overflow:hidden}"
    . ".jobs .card.jc:hover{box-shadow:0 8px 24px rgba(16,24,40,.10);transform:translateY(-2px);border-color:#c9d6e8}"
    . ".jc .in{padding:18px 18px 16px;display:flex;flex-direction:column;gap:10px;height:100%}"
    . ".jc-h{display:flex;gap:12px;align-items:flex-start}.jc-logo{flex:0 0 42px;height:42px;border-radius:10px;color:#fff;font-weight:800;font-size:1.1rem;display:flex;align-items:center;justify-content:center}"
    . ".jc-t{min-width:0}.jc h3{font-size:1.02rem;margin:0;line-height:1.35}.jc h3 a{color:#0f1b2d;text-decoration:none}.jc h3 a:hover{color:var(--c)}"
    . ".jc-co{margin:3px 0 0;font-size:.88rem;color:var(--m);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}"
    . ".jc-tags{display:flex;flex-wrap:wrap;gap:6px}.tag{font-size:.75rem;font-weight:650;padding:3px 10px;border-radius:999px;background:#eef2f7;color:#334155}"
    . ".t-cdi{background:#e6f6ee;color:#0f7a43}.t-cdd{background:#e8f0fb;color:#0055a4}.t-interim{background:#fdf0e3;color:#b4540a}.t-alternance{background:#f1ebfb;color:#6b35b8}.t-stage{background:#fdeaf1;color:#b4235a}.t-freelance{background:#e9f7f6;color:#00796f}.t-pub{background:#e6f1fa;color:#0b4f8a}.t-sal{background:#e7f8f5;color:#00806f}"
    . ".jc-meta{margin:0;display:flex;flex-wrap:wrap;gap:4px 14px;font-size:.85rem;color:var(--m)}.jc-meta span,.jsum-h span{display:inline-flex;align-items:center;gap:4px}.jc-meta svg{color:#8a97a8}"
    . ".jc-btn{margin-top:auto;align-self:flex-start;font-size:.88rem;font-weight:650;color:var(--c);text-decoration:none;border:1.5px solid #cfdcee;border-radius:9px;padding:6px 14px}.jc-btn:hover{background:var(--c);color:#fff;border-color:var(--c)}"
    . ".jsum{background:#fff;border:1px solid var(--b);border-radius:16px;padding:20px;margin:14px 0 22px;box-shadow:0 2px 8px rgba(16,24,40,.05)}"
    . ".jsum-h{display:flex;gap:14px;align-items:center;margin-bottom:12px}.jsum-h .jc-logo{flex-basis:52px;height:52px;font-size:1.35rem}.jsum-h b{display:block;font-size:1.05rem;color:#0f1b2d}.jsum-h span{font-size:.85rem;color:var(--m)}"
    . ".jsum-g{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px 18px;margin:14px 0 18px}.jsum-g div{background:var(--s);border-radius:10px;padding:10px 12px}.jsum-g dt{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:var(--m);font-weight:650}.jsum-g dd{margin:2px 0 0;font-weight:600;font-size:.95rem}"
    . ".jbtn{display:inline-block;background:var(--c);color:#fff!important;font-weight:700;padding:13px 24px;border-radius:10px;text-decoration:none;box-shadow:0 4px 14px rgba(0,85,164,.25)}.jbtn:hover{background:#00468a}"
    . ".jdesc{background:#fff;border:1px solid var(--b);border-radius:16px;padding:6px 22px 18px}.jdesc h2{border:0;margin-top:1em}"
    . ".jobs .cnt a,.jobs .cnt span{background:#fff;border:1px solid var(--b);border-radius:999px}.jobs .cnt a:hover{border-color:var(--c);color:var(--c)}.jobs .cnt b{color:var(--c)}"
    . ".jobs .kpi>div{background:#fff;border:1px solid var(--b);border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.04)}.jobs .kpi b{color:var(--c)}"
    . ".jobs .jhome{background:linear-gradient(135deg,#f0f6fd 0%,#eafaf7 100%);border:1px solid #dfe9f5;border-radius:20px;padding:26px 26px 18px}"
    . ".jobs .jhome h1{margin-top:0}.jobs .jhome form input{background:#fff}.jobs .jhome form button{box-shadow:0 4px 14px rgba(0,85,164,.25)}"
    . ".jhome-stats{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 4px}.jhome-stats div{background:#fff;border:1px solid #dfe9f5;border-radius:12px;padding:8px 14px;font-size:.9rem;color:var(--m)}.jhome-stats b{display:block;font-size:1.25rem;color:var(--c);font-weight:800}"
    . ".jobs table th{background:var(--s)}.jobs .box{border-radius:14px}"
    . "@media(max-width:700px){.jobs .jhome{padding:18px 16px 12px;border-radius:14px}.jsum{padding:16px}.jdesc{padding:4px 16px 14px}.jbtn{display:block;text-align:center}}";
}
