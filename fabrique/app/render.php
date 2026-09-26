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
nav.cats{display:flex;gap:14px;flex-wrap:wrap;font-size:.9rem}nav.cats a{text-decoration:none;color:var(--m)}nav.cats a:hover{color:var(--c)}
form.q{margin-left:auto}form.q input{border:1px solid var(--b);border-radius:20px;padding:6px 14px;font-size:.9rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:26px;margin:28px 0}
.card{border:1px solid var(--b);border-radius:12px;overflow:hidden;background:#fff;display:flex;flex-direction:column}
.card img{aspect-ratio:16/9;object-fit:cover;width:100%;display:block;background:var(--s)}
.card .in{padding:14px 18px 18px}.card h2,.card h3{font-size:1.1rem;line-height:1.35;margin:.3em 0}.card h2 a,.card h3 a{color:var(--t);text-decoration:none}
.card p{color:var(--m);font-size:.92rem;margin:.4em 0 0}.kick{font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:var(--c);font-weight:700;text-decoration:none}
.layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:48px;margin:30px auto}
@media(max-width:900px){.layout{grid-template-columns:1fr}aside{order:2}}
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
    if ($site['adsense'] && $pub && empty($m['noads'])) $head .= '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . h($pub) . '" crossorigin="anonymous"></script>';
    if ($ga = setting('ga4_id', '')) $head .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . h($ga) . '"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("js",new Date());gtag("config","' . h($ga) . '");</script>';

    $nav = '';
    if (!empty($site['fuel'])) $nav .= '<a href="/prix-carburant/"><strong>Prix carburant</strong></a>';
    if (function_exists('places_mod') && ($pm = places_mod($site))) $nav .= '<a href="/' . $pm['prefix'] . '/"><strong>' . h($pm['nav']) . '</strong></a>';
    if (!empty($site['commune'])) $nav .= '<a href="/commune/"><strong>Fiches communes</strong></a>';
    if (!empty($site['dpe'])) $nav .= '<a href="/dpe/"><strong>DPE de votre commune</strong></a>';
    if (!empty($site['ev'])) $nav .= '<a href="/bornes-recharge/"><strong>Bornes de recharge</strong></a>';
    if (!empty($site['jobs']) && function_exists('jobs_db') && jobs_db($site['host'])->query("SELECT 1 FROM jobs WHERE status='open' LIMIT 1")->fetchColumn()) $nav .= '<a href="/offres-emploi/"><strong>Offres d\'emploi</strong></a>';
    foreach (array_slice(site_categories($site), 0, 7) as $c) $nav .= '<a href="/category/' . h($c['slug']) . '/">' . h($c['name']) . '</a>';
    $parts = explode(' ', $site['name'], 2);
    $logo = h($parts[0]) . (isset($parts[1]) ? ' <span>' . h($parts[1]) . '</span>' : '');
    $year = gmdate('Y');
    $amzDisc = $site['amazon'] && setting('amazon_tag', '') ? '<p class="disc">En tant que Partenaire Amazon, ' . h($site['name']) . ' réalise un bénéfice sur les achats remplissant les conditions requises. Les liens marqués « Voir sur Amazon » sont des liens d\'affiliation.</p>' : '';

    return '<!doctype html><html lang="' . h($site['lang'] ?: 'fr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . $head . '<style>' . css($site) . '</style></head><body>'
        . '<header class="top"><div class="w"><a class="logo" href="/">' . $logo . '</a><nav class="cats">' . $nav . '</nav>'
        . '<form class="q" action="/recherche/" method="get"><input type="search" name="q" placeholder="Rechercher…" aria-label="Rechercher"></form></div></header>'
        . '<main class="w">' . $body . '</main>'
        . '<footer class="bot"><div class="w"><p><strong>' . h($site['name']) . '</strong> — ' . h($site['tagline']) . '</p>'
        . '<p><a href="/a-propos/">À propos</a><a href="/contact/">Contact</a><a href="/mentions-legales/">Mentions légales</a><a href="/confidentialite/">Confidentialité</a><a href="/sitemap.xml">Plan du site</a></p>'
        . $amzDisc . '<p>© ' . $year . ' ' . h($site['name']) . '</p></div></footer><script>setTimeout(function(){var d=new FormData();d.append("p",location.pathname);navigator.sendBeacon("/_pv",d)},1500)</script></body></html>';
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
            'potentialAction' => ['@type' => 'SearchAction', 'target' => 'https://' . $site['host'] . '/recherche/?q={q}', 'query-input' => 'required name=q']]],
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
    if (!isset($pages[$key])) return '';
    [$t, $c] = $pages[$key];
    return layout($site, ['title' => $t . ' | ' . $site['name'], 'desc' => $t . ' — ' . $site['name'], 'canonical' => 'https://' . $site['host'] . "/$key/", 'noads' => true],
        '<article style="max-width:760px;margin:30px 0"><h1>' . h($t) . '</h1>' . $c . '</article>');
}

function page_404(array $site): string
{
    [$posts] = list_posts($site, '', [], 1, 6);
    return layout($site, ['title' => 'Page introuvable | ' . $site['name'], 'robots' => 'noindex,follow', 'noads' => true],
        '<h1 style="margin-top:28px">Page introuvable</h1><p>Cette page n\'existe pas ou a été déplacée. Voici nos derniers articles :</p><div class="grid">' . implode('', array_map('card', $posts)) . '</div>');
}
