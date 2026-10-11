<?php
// Module « nor » : agenda des sorties et festivals de Normandie sur www.normandie.me, alimenté par l'open data.
// Copie autonome du module Bretagne (bzh.php), sans dépendance envers lui.
// Données : data/nor/nor.sqlite rempli par cli/nor_sync.php ; textes rédigés poussés par le hub via /_api/nor_text.
declare(strict_types=1);

const NOR_HOST = 'www.normandie.me';
const NOR_DEPTS = ['14' => ['Calvados', 'calvados', 'dans le Calvados', 'du Calvados'], '27' => ['Eure', 'eure', "dans l'Eure", "de l'Eure"],
    '50' => ['Manche', 'manche', 'dans la Manche', 'de la Manche'], '61' => ['Orne', 'orne', "dans l'Orne", "de l'Orne"],
    '76' => ['Seine-Maritime', 'seine-maritime', 'en Seine-Maritime', 'de Seine-Maritime']];
// thème => [titre, sous-types d'événement, motif sur le nom (ou null)]
const NOR_THEMES = [
    'vide-greniers' => ['Vide-greniers et brocantes', ['Vide-greniers, brocante'], '/vide.?grenier|brocante|braderie|bric.?[àa].?brac|\bpuces\b|\btroc\b/iu'],
    'marches-de-noel' => ['Marchés de Noël', [], '/march[ée]s? de no[eë]l|village de no[eë]l|no[eë]l.{0,20}march[ée]/iu'],
    'marches' => ['Marchés et foires', ['Marché', 'Marché, foire', 'Foire, salon'], '/\bmarch[ée]s?\b|\bfoire\b/iu'],
    'concerts' => ['Concerts', ['Concert'], null],
    'spectacles' => ['Spectacles et théâtre', ['Spectacle'], '/th[ée][âa]tre|spectacle|humour|cirque/iu'],
    'expositions' => ['Expositions', ['Exposition'], '/\bexpo(sition)?s?\b/iu'],
    'fetes' => ['Fêtes et animations', ['Fête, animation', 'Fête traditionnelle', 'Jeu, animation'], '/\bf[êe]te\b/iu'],
    'enfants' => ['Sorties avec les enfants', ['Jeune public'], '/enfants?|en famille|jeune public/iu'],
    'visites-guidees' => ['Visites guidées', ['Visite guidée', 'Portes ouvertes'], '/visite guid[ée]e/iu'],
    'randonnees' => ['Randonnées et balades', ['Randonnée, balade'], '/randonn[ée]e|balade|marche nordique/iu'],
    'sport' => ['Événements sportifs', ['Sport', 'Compétition sportive'], null],
    'conferences' => ['Conférences et ateliers', ['Conférence', 'Stage, atelier'], null],
    'cinema' => ['Cinéma', ['Cinéma'], '/cin[ée]ma|projection/iu'],
    'halloween' => ['Halloween', [], '/halloween/iu'],
    'feux-d-artifice' => ["Feux d'artifice", [], "/feux? d.artifice/iu"],
];
const NOR_PER = 60;
const NOR_TXT_MIN = 300; // caractères de texte rédigé pour qu'une fiche événement soit indexable

// ---------- Base ----------

function nor_on(array $site): bool { return ($site['host'] ?? '') === NOR_HOST; }

function nor_db(): PDO
{
    static $db = null;
    if ($db) return $db;
    @mkdir(cfg('data_dir') . '/nor', 0750, true);
    $db = pdo_open(cfg('data_dir') . '/nor/nor.sqlite');
    $db->exec("CREATE TABLE IF NOT EXISTS communes(insee TEXT PRIMARY KEY, nom TEXT, slug TEXT, dept TEXT, cps TEXT, pop INTEGER, lat REAL, lon REAL, epci TEXT, n_ev INTEGER DEFAULT 0);
    CREATE UNIQUE INDEX IF NOT EXISTS ux_ncom ON communes(dept, slug);
    CREATE TABLE IF NOT EXISTS items(id TEXT PRIMARY KEY, kind TEXT, sub TEXT, slug TEXT, ym TEXT, name TEXT, insee TEXT, lat REAL, lon REAL, d1 TEXT, d2 TEXT, rank INTEGER DEFAULT 0, page INTEGER DEFAULT 0,
        data TEXT, seen TEXT, created TEXT, txt TEXT, txt_at TEXT, txt_sig TEXT);
    CREATE UNIQUE INDEX IF NOT EXISTS ux_nitems ON items(kind, slug);
    CREATE INDEX IF NOT EXISTS ix_nitems_c ON items(insee, kind, d1);
    CREATE INDEX IF NOT EXISTS ix_nitems_d ON items(kind, d2, d1);
    CREATE INDEX IF NOT EXISTS ix_nitems_lat ON items(kind, lat);
    CREATE TABLE IF NOT EXISTS themes(id TEXT, theme TEXT, PRIMARY KEY(theme, id));
    CREATE INDEX IF NOT EXISTS ix_nthemes_id ON themes(id);
    CREATE TABLE IF NOT EXISTS gone(kind TEXT, slug TEXT, at TEXT, PRIMARY KEY(kind, slug));");
    return $db;
}

// ---------- Helpers ----------

function nor_norm(string $s): string { return str_replace(['saint-', 'sainte-'], ['st-', 'ste-'], slugify(str_replace(['œ', 'Œ'], ['oe', 'Oe'], $s), 120)); }
function nor_clean(?string $s): string { return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8')))); }
function nor_ucfirst(string $s): string { $s = trim($s); return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
function nor_data(array $r): array { return json_decode((string)($r['data'] ?? '{}'), true) ?: []; }
function nor_nf(int $n): string { return number_format($n, 0, ',', "\u{202F}"); }
function nor_plural(int $n, string $one, string $many): string { return nor_nf($n) . ' ' . ($n > 1 ? $many : $one); }
function nor_today(): string { return date('Y-m-d'); }
function nor_dn(string $d): string { return NOR_DEPTS[$d][0] ?? $d; }
function nor_in(string $d): string { return NOR_DEPTS[$d][2] ?? ''; }
function nor_du(string $d): string { return NOR_DEPTS[$d][3] ?? ''; }
function nor_dept_by_slug(string $s): ?string { foreach (NOR_DEPTS as $c => $v) if ($v[1] === $s) return (string)$c; return null; }
function nor_canon(string $path): string { return 'https://' . NOR_HOST . $path; }
function nor_a(string $nom): string
{
    if (str_starts_with($nom, 'Le ')) return 'au ' . substr($nom, 3);
    if (str_starts_with($nom, 'Les ')) return 'aux ' . substr($nom, 4);
    return 'à ' . $nom;
}
function nor_de(string $nom): string
{
    if (str_starts_with($nom, 'Le ')) return 'du ' . substr($nom, 3);
    if (str_starts_with($nom, 'Les ')) return 'des ' . substr($nom, 4);
    return preg_match('/^[AEIOUYÉÈÎÏÔHaeiouyéèîh]/u', $nom) ? "d'" . $nom : 'de ' . $nom;
}
function nor_date(string $d, bool $wd = true): string
{
    $t = strtotime($d); if (!$t) return $d;
    $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w', $t)];
    $m = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int)date('n', $t)];
    $dn = (int)date('j', $t);
    return ($wd ? $j . ' ' : '') . ($dn === 1 ? '1er' : $dn) . ' ' . $m . ' ' . date('Y', $t);
}
function nor_period(?string $a, ?string $b): string
{
    if (!$a) return '';
    if (!$b || $b === $a) return 'le ' . nor_date($a);
    return 'du ' . nor_date($a, false) . ' au ' . nor_date($b, false);
}
function nor_weekend(): array
{
    $w = (int)date('N');
    $sat = $w === 6 ? nor_today() : ($w === 7 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d', strtotime('next saturday')));
    return [$sat, date('Y-m-d', strtotime("$sat +1 day"))];
}
function nor_km(float $a, float $b, float $c, float $d): float { return 6371 * 2 * asin(sqrt(sin(deg2rad($c - $a) / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin(deg2rad($d - $b) / 2) ** 2)); }
function nor_ev_url(array $r): string { return '/agenda/' . substr((string)$r['ym'], 0, 4) . '/' . substr((string)$r['ym'], 5, 2) . '/' . $r['slug'] . '/'; }
function nor_fest_url(array $r): string { return '/festivals/' . $r['slug'] . '/'; }
function nor_item_url(array $r): string { return $r['kind'] === 'festival' ? nor_fest_url($r) : nor_ev_url($r); }
function nor_com_agenda_url(array $c): string { return '/agenda/' . NOR_DEPTS[$c['dept']][1] . '/' . $c['slug'] . '/'; }
function nor_indexable_ev(array $r): bool { return $r['d2'] >= nor_today() && mb_strlen(strip_tags((string)$r['txt'])) >= NOR_TXT_MIN; }

// Période type de la cartographie des festivals (tranche de saison, pas des dates d'édition).
function nor_fest_period(string $p): string
{
    $p = mb_strtolower($p);
    if (str_starts_with($p, 'avant')) return 'au premier semestre, avant l\'été';
    if (str_starts_with($p, 'après') || str_starts_with($p, 'apres')) return 'après l\'été, entre septembre et décembre';
    if (str_starts_with($p, 'saison')) return 'en été';
    return trim(preg_replace('/\s*\(.*\)$/', '', $p));
}

// Recherche d'hébergements Booking autour d'une commune et de dates (lien rendu affilié par le script Stay22).
function nor_stay_link(string $commune, ?string $d1 = null, ?string $d2 = null): string
{
    $t = nor_today();
    $in = $d1 && $d1 >= $t ? $d1 : null;
    $out = $in ? (($d2 && $d2 > $in) ? $d2 : date('Y-m-d', strtotime($in . ' +1 day'))) : null;
    $q = ['ss' => $commune . ', Normandie, France', 'lang' => 'fr'] + ($in ? ['checkin' => $in, 'checkout' => $out] : []);
    return '<p><a class="nm-stay" href="https://www.booking.com/searchresults.fr.html?' . h(http_build_query($q)) . '" rel="sponsored noopener" target="_blank">Voir les hébergements disponibles ' . h(nor_a($commune)) . ($in ? ' ' . h(nor_period($in, $out)) : '') . '</a> <small class="disc">(lien partenaire)</small></p>';
}

// Encart GetYourGuide (partenaire EJ5XM8I) : activités recherchées par mot-clé, campagne = type de page.
const NOR_GYG_PARTNER = 'EJ5XM8I';
const NOR_GYG_DEPT_Q = ['14' => 'D-Day', '50' => 'Normandie', '76' => 'Étretat', '27' => 'Giverny', '61' => 'Normandie'];
function nor_gyg(string $q, string $cmp, string $title = 'Visites et excursions à réserver'): string
{
    return '<section class="nr-sec nr-gyg"><h2>' . h($title) . '</h2><div data-gyg-widget="activities" data-gyg-partner-id="' . NOR_GYG_PARTNER . '" data-gyg-locale-code="fr-FR" data-gyg-currency="EUR" data-gyg-number-of-items="3" data-gyg-q="' . h($q) . '" data-gyg-cmp="' . h($cmp) . '"></div>'
        . '<p class="disc">Liens partenaires GetYourGuide : nous percevons une commission sur les réservations, sans surcoût pour vous.</p>'
        . '<script async defer src="https://widget.getyourguide.com/dist/pa.umd.production.min.js" data-gyg-partner-id="' . NOR_GYG_PARTNER . '"></script></section>';
}

// Sous-type d'un événement DATAtourisme (catégories nettoyées + nom).
function nor_ev_sub(array $c, string $name): string
{
    $has = fn(...$k) => (bool)array_intersect($k, $c);
    if ($has('GarageSale', 'BricABrac') || preg_match('/vide.?grenier|brocante|braderie|bric.?[àa].?brac/iu', $name)) return 'Vide-greniers, brocante';
    foreach ([['Festival', 'Festival'], ['Concert', 'Concert'], ['MusicEvent', 'Concert'], ['Exhibition', 'Exposition'], ['ExhibitionEvent', 'Exposition'], ['Market', 'Marché'], ['SaleEvent', 'Marché, foire'],
        ['FairOrShow', 'Foire, salon'], ['SportsCompetition', 'Compétition sportive'], ['SportsEvent', 'Sport'], ['ChildrensEvent', 'Jeune public'], ['Cinema', 'Cinéma'], ['ScreeningEvent', 'Cinéma'],
        ['ShowEvent', 'Spectacle'], ['TheaterEvent', 'Spectacle'], ['Conference', 'Conférence'], ['Reading', 'Conférence'], ['Traineeship', 'Stage, atelier'], ['Practice', 'Stage, atelier'], ['Workshop', 'Stage, atelier'],
        ['Rambling', 'Randonnée, balade'], ['WalkingTour', 'Randonnée, balade'], ['Visit', 'Visite guidée'], ['OpenDay', 'Portes ouvertes'], ['TraditionalCelebration', 'Fête traditionnelle'],
        ['LocalAnimation', 'Fête, animation'], ['SocialEvent', 'Fête, animation'], ['Game', 'Jeu, animation'], ['CulturalEvent', 'Sortie culturelle']] as [$k, $l]) if ($has($k)) return $l;
    return 'Sortie';
}
function nor_themes_of(string $sub, string $name): array
{
    $o = [];
    foreach (NOR_THEMES as $t => [, $subs, $re]) if (in_array($sub, $subs, true) || ($re && preg_match($re, $name))) $o[] = $t;
    if (in_array('marches-de-noel', $o, true)) $o = array_values(array_diff($o, ['marches']));
    return $o;
}

function nor_crumbs(array $site, array $items): array
{
    $html = '<nav class="crumbs" aria-label="Fil d\'Ariane"><a href="/">Accueil</a>';
    foreach ($items as [$n, $u]) $html .= ' › ' . ($u ? '<a href="' . h($u) . '">' . h($n) . '</a>' : h($n));
    return [$html . '</nav>', breadcrumbs($site, array_values(array_filter($items, fn($x) => $x[1])))];
}

function nor_css(): string
{
    return '<style>.nr-sec{margin:34px 0}.nr-sec h2{font-size:1.4rem;margin:0 0 12px}.nr-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;padding:0;list-style:none;margin:12px 0}'
        . '.nr-list li{border:1px solid var(--b);border-radius:10px;padding:10px 14px;background:#fff;line-height:1.4}.nr-list li a{font-weight:600;text-decoration:none}.nr-list li small{display:block;color:var(--m);font-size:.82rem;margin-top:3px}'
        . '.nr-tags{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0;padding:0;list-style:none}.nr-tags a{border:1px solid var(--b);border-radius:20px;padding:4px 12px;text-decoration:none;font-size:.9rem;background:#fff}.nr-tags a[aria-current]{background:var(--c);color:#fff;border-color:var(--c)}'
        . '.nr-facts{width:100%;display:table}.nr-facts th{width:34%;font-weight:600}.nr-map{width:100%;height:300px;border:0;border-radius:12px;margin:12px 0;background:var(--s)}'
        . '.nr-ans{font-size:1.08rem;background:var(--s);border-left:4px solid var(--c);padding:14px 18px;border-radius:0 10px 10px 0;margin:16px 0}.nr-day{margin:22px 0 6px;font-size:1.1rem;color:var(--c);text-transform:capitalize}'
        . '.nr-src{color:var(--m);font-size:.82rem;margin-top:30px;border-top:1px solid var(--b);padding-top:12px}.nr-past{background:#fde2e1;border-radius:10px;padding:10px 14px;color:#8c1d18}.nr-txt p{margin:.6em 0}</style>';
}

function nor_page(array $site, array $m, string $body, array $schema = []): string
{
    $m += ['schema' => []];
    $m['schema'] = array_merge($m['schema'], $schema);
    if (!empty($m['path'])) $m['canonical'] = nor_canon($m['path']);
    return layout($site, $m, nor_css() . $body);
}

function nor_li(array $r, string $extra = ''): string
{
    $d = nor_data($r);
    $name = h($r['name']);
    if (in_array($r['kind'], ['evenement', 'festival'], true) && $r['page']) $a = '<a href="' . h(nor_item_url($r)) . '">' . $name . '</a>';
    elseif (!empty($d['web'][0])) $a = '<a href="' . h($d['web'][0]) . '" rel="nofollow noopener" target="_blank">' . $name . '</a>';
    else $a = '<strong>' . $name . '</strong>';
    return '<li>' . $a . ($extra !== '' ? '<small>' . h($extra) . '</small>' : '') . '</li>';
}
function nor_ul(array $rows, ?callable $extra = null): string { return $rows ? '<ul class="nr-list">' . implode('', array_map(fn($r) => nor_li($r, $extra ? $extra($r) : ''), $rows)) . '</ul>' : ''; }

function nor_items(string $where, array $args, string $order, int $limit = 12, int $offset = 0): array
{
    $st = nor_db()->prepare("SELECT i.*, c.nom cnom, c.slug cslug, c.dept FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset");
    $st->execute($args);
    return $st->fetchAll();
}
function nor_count(string $where, array $args): int { $st = nor_db()->prepare("SELECT COUNT(*) FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $where"); $st->execute($args); return (int)$st->fetchColumn(); }

function nor_near(float $lat, float $lon, string $kind, string $notId, int $n, float $km, string $extraWhere = '', array $extraArgs = []): array
{
    $dl = $km / 111; $dlo = $km / 74;
    $st = nor_db()->prepare("SELECT i.*, c.nom cnom, c.slug cslug, c.dept, ((i.lat-?)*(i.lat-?)+((i.lon-?)*0.67)*((i.lon-?)*0.67)) dd FROM items i LEFT JOIN communes c ON c.insee=i.insee
        WHERE i.kind=? AND i.id<>? AND i.lat BETWEEN ? AND ? AND i.lon BETWEEN ? AND ? $extraWhere ORDER BY dd LIMIT $n");
    $st->execute(array_merge([$lat, $lat, $lon, $lon, $kind, $notId, $lat - $dl, $lat + $dl, $lon - $dlo, $lon + $dlo], $extraArgs));
    return $st->fetchAll();
}

function nor_map(?float $lat, ?float $lon, string $title, float $z = 0.012): string
{
    if (!$lat || !$lon) return '';
    $bb = ($lon - $z * 1.5) . ',' . ($lat - $z) . ',' . ($lon + $z * 1.5) . ',' . ($lat + $z);
    return '<iframe class="nr-map" loading="lazy" title="' . h('Carte : ' . $title) . '" src="https://www.openstreetmap.org/export/embed.html?bbox=' . h($bb) . '&amp;layer=mapnik&amp;marker=' . h($lat . ',' . $lon) . '"></iframe>'
        . '<p class="disc"><a href="https://www.google.com/maps/dir/?api=1&amp;destination=' . h($lat . ',' . $lon) . '" rel="nofollow noopener" target="_blank">Itinéraire</a> · Carte © contributeurs OpenStreetMap</p>';
}

function nor_faq(array $qa): array
{
    if (!$qa) return ['', []];
    $html = '<section class="nr-sec faq"><h2>Questions fréquentes</h2>';
    $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
    foreach ($qa as [$q, $a]) {
        $html .= '<details><summary>' . h($q) . '</summary><p>' . h($a) . '</p></details>';
        $ld['mainEntity'][] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    return [$html . '</section>', [$ld]];
}

function nor_sources(string ...$s): string
{
    $map = ['dt' => 'DATAtourisme (offices de tourisme, agences départementales et Comité régional du tourisme de Normandie), Licence Ouverte Etalab',
        'festivals' => 'cartographie nationale des festivals, ministère de la Culture, Licence Ouverte',
        'communes' => 'Insee et API Découpage administratif (geo.api.gouv.fr)'];
    $o = array_map(fn($k) => $map[$k] ?? $k, array_unique($s));
    return '<p class="nr-src">Sources : ' . h(implode(' ; ', $o)) . '. Informations publiques mises à jour chaque jour : vérifiez dates, horaires et tarifs auprès de l\'organisateur ou de l\'office de tourisme avant de vous déplacer.</p>';
}

function nor_pager(string $base, int $page, int $pages): string
{
    if ($pages <= 1) return '';
    $o = '<nav class="pag">';
    if ($page > 1) $o .= '<a href="' . $base . ($page > 2 ? 'page/' . ($page - 1) . '/' : '') . '">‹ Précédent</a>';
    $o .= '<span>Page ' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) $o .= '<a href="' . $base . 'page/' . ($page + 1) . '/">Suivant ›</a>';
    return $o . '</nav>';
}

function nor_dept_tags(string $prefix, ?string $cur, string $suffix = ''): string
{
    return '<ul class="nr-tags">' . implode('', array_map(fn($c, $v) => '<li><a href="' . $prefix . $v[1] . '/' . $suffix . '"' . ((string)$c === (string)$cur ? ' aria-current="page"' : '') . '>' . h($v[0]) . '</a></li>', array_keys(NOR_DEPTS), NOR_DEPTS)) . '</ul>';
}
function nor_theme_tags(?string $cur): string
{
    return '<ul class="nr-tags"><li><a href="/agenda/ce-week-end/">Ce week-end</a></li>' . implode('', array_map(fn($t, $v) => '<li><a href="/agenda/' . $t . '/"' . ($t === $cur ? ' aria-current="page"' : '') . '>' . h($v[0]) . '</a></li>', array_keys(NOR_THEMES), NOR_THEMES)) . '</ul>';
}

// ---------- Routage ----------

// Renvoie le HTML, '' (404), null (redirection envoyée) ou false (pas une page du module).
function nor_route(array $site, string $path)
{
    if (!str_starts_with($path, '/agenda') && !str_starts_with($path, '/festivals')) return false;
    if ($path === '/agenda' || $path === '/festivals') { header('Location: ' . $path . '/', true, 301); return null; }
    $pg = 1;
    if (preg_match('#^(.*/)page/(\d+)/$#', $path, $m)) { $path = $m[1]; $pg = max(1, (int)$m[2]); if ($pg === 1) { header('Location: ' . $path, true, 301); return null; } }
    if ($path === '/agenda/') return nor_agenda($site, null, null, null, $pg, false);
    if ($path === '/agenda/ce-week-end/') return nor_agenda($site, null, null, null, $pg, true);
    if (preg_match('#^/agenda/(\d{4})/(\d{2})/([a-z0-9\-]+)/$#', $path, $m)) return $pg > 1 ? '' : nor_event($site, $m[3], $m[1] . '-' . $m[2]);
    if (preg_match('#^/agenda/([a-z0-9\-]+)/$#', $path, $m)) {
        if ($d = nor_dept_by_slug($m[1])) return nor_agenda($site, $d, null, null, $pg, false);
        if (isset(NOR_THEMES[$m[1]])) return nor_agenda($site, null, $m[1], null, $pg, false);
        return '';
    }
    if (preg_match('#^/agenda/([a-z0-9\-]+)/([a-z0-9\-]+)/$#', $path, $m)) {
        $d = nor_dept_by_slug($m[1]);
        if ($d && $m[2] === 'ce-week-end') return nor_agenda($site, $d, null, null, $pg, true);
        if ($d) {
            $st = nor_db()->prepare('SELECT * FROM communes WHERE dept=? AND slug=?'); $st->execute([$d, $m[2]]);
            return ($c = $st->fetch()) ? nor_agenda($site, $d, null, $c, $pg, false) : '';
        }
        if (isset(NOR_THEMES[$m[1]]) && ($d2 = nor_dept_by_slug($m[2]))) return nor_agenda($site, $d2, $m[1], null, $pg, false);
        return '';
    }
    if ($path === '/festivals/') return nor_festivals($site, null, $pg);
    if (preg_match('#^/festivals/([a-z0-9\-]+)/$#', $path, $m)) {
        if ($d = nor_dept_by_slug($m[1])) return nor_festivals($site, $d, $pg);
        return $pg > 1 ? '' : nor_festival($site, $m[1]);
    }
    return '';
}

// ---------- Listes d'agenda ----------

function nor_agenda(array $site, ?string $d, ?string $theme, ?array $com, int $page, bool $weekend): string
{
    $t = nor_today();
    [$sat, $sun] = nor_weekend();
    $from = $weekend ? $sat : $t;
    $w = "i.kind='evenement' AND i.page=1 AND i.d2>=?"; $a = [$from];
    if ($weekend) { $w .= ' AND i.d1<=?'; $a[] = $sun; }
    if ($com) { $w .= ' AND i.insee=?'; $a[] = $com['insee']; }
    elseif ($d) { $w .= ' AND c.dept=?'; $a[] = $d; }
    if ($theme) { $w .= ' AND i.id IN (SELECT id FROM themes WHERE theme=?)'; $a[] = $theme; }
    $n = nor_count($w, $a); $pages = max(1, (int)ceil($n / NOR_PER));
    if ($page > $pages) return '';
    $rows = nor_items($w, $a, 'MAX(i.d1, ' . nor_db()->quote($from) . '), i.rank DESC, i.name', NOR_PER, ($page - 1) * NOR_PER);

    $where = $com ? ' ' . nor_a($com['nom']) : ($d ? ' ' . nor_in($d) : ' en Normandie');
    $T = $theme ? NOR_THEMES[$theme][0] : null;
    if ($com) { $base = nor_com_agenda_url($com); $h1 = 'Agenda des sorties' . $where; }
    elseif ($theme) { $base = '/agenda/' . $theme . '/' . ($d ? NOR_DEPTS[$d][1] . '/' : ''); $h1 = $T . $where; }
    elseif ($weekend) { $base = '/agenda/' . ($d ? NOR_DEPTS[$d][1] . '/' : '') . 'ce-week-end/'; $h1 = 'Que faire ce week-end' . $where . ' ?'; }
    else { $base = '/agenda/' . ($d ? NOR_DEPTS[$d][1] . '/' : ''); $h1 = 'Agenda des sorties' . $where; }

    $cr = [['Agenda', '/agenda/']];
    if ($theme) { $cr[] = [$T, $d ? '/agenda/' . $theme . '/' : '']; if ($d) $cr[] = [nor_dn($d), '']; }
    elseif ($com) { $cr[] = [nor_dn($d), '/agenda/' . NOR_DEPTS[$d][1] . '/']; $cr[] = [$com['nom'], '']; }
    elseif ($d) { $cr[] = [nor_dn($d), $weekend ? '/agenda/' . NOR_DEPTS[$d][1] . '/' : '']; if ($weekend) $cr[] = ['Ce week-end', '']; }
    elseif ($weekend) $cr[] = ['Ce week-end', ''];
    else $cr = [['Agenda', '']];
    [$crh, $bc] = nor_crumbs($site, $cr);

    $lead = ($weekend ? 'Le week-end du ' . nor_date($sat, false) . ', ' : '') . nor_plural($n, 'événement est annoncé', 'événements sont annoncés') . $where
        . ($weekend ? '' : ' à partir d\'aujourd\'hui') . ($T ? ' (' . mb_strtolower($T) . ')' : '') . '.';
    if ($rows && !$weekend) $lead .= ' Prochain rendez-vous : « ' . $rows[0]['name'] . ' », ' . nor_period(max((string)$rows[0]['d1'], $t), (string)$rows[0]['d2']) . ($com ? '' : ' ' . nor_a((string)$rows[0]['cnom'])) . '.';
    $body = $crh . '<h1 style="margin-top:20px">' . h($h1) . ($page > 1 ? ' – page ' . $page : '') . '</h1><p class="nr-ans">' . h($lead) . '</p>';
    if (!$com) $body .= nor_dept_tags($theme ? '/agenda/' . $theme . '/' : '/agenda/', $d, $weekend && !$theme ? 'ce-week-end/' : '');
    if (!$com && $page === 1) $body .= nor_theme_tags($theme);

    $grp = [];
    foreach ($rows as $r) { $k = max((string)$r['d1'], $from); $grp[$k][] = $r; }
    $i = 0;
    foreach ($grp as $day => $rs) {
        $body .= '<h2 class="nr-day">' . h(nor_date($day)) . '</h2>' . nor_ul($rs, fn($r) => trim($r['sub'] . ($com ? '' : ' · ' . $r['cnom']) . ($r['d2'] > $r['d1'] ? ' · jusqu\'au ' . nor_date($r['d2'], false) : ''), ' ·'));
        if (++$i === 2 && count($grp) > 2) $body .= ad_unit($site, 'in_article');
    }

    $robots = null;
    if (!$rows) {
        $body .= '<p>Aucun événement n\'est annoncé pour le moment' . h($where) . '. L\'agenda est mis à jour chaque jour.</p>';
        $robots = 'noindex,follow';
    }
    if ($com) {
        if ($n < 3) $robots = 'noindex,follow';
        if ($com['lat']) {
            $st = nor_db()->prepare("SELECT c.*, ((c.lat-?)*(c.lat-?)+((c.lon-?)*0.67)*((c.lon-?)*0.67)) dd FROM communes c WHERE c.insee<>? AND c.n_ev>=3 ORDER BY dd LIMIT 10");
            $st->execute([$com['lat'], $com['lat'], $com['lon'], $com['lon'], $com['insee']]);
            $nb = $st->fetchAll();
            if ($nb) $body .= '<section class="nr-sec"><h2>Sorties dans les communes voisines</h2><ul class="nr-tags">' . implode('', array_map(fn($c) => '<li><a href="' . nor_com_agenda_url($c) . '">' . h($c['nom']) . ' (' . (int)$c['n_ev'] . ')</a></li>', $nb)) . '</ul></section>';
        }
    } elseif ($d && !$theme && !$weekend && $page === 1) {
        $st = nor_db()->prepare('SELECT * FROM communes WHERE dept=? AND n_ev>=3 ORDER BY n_ev DESC, nom LIMIT 40'); $st->execute([$d]);
        $cs = $st->fetchAll();
        if ($cs) $body .= '<section class="nr-sec"><h2>Agenda par commune ' . h(nor_du($d)) . '</h2><ul class="nr-tags">' . implode('', array_map(fn($c) => '<li><a href="' . nor_com_agenda_url($c) . '">' . h($c['nom']) . ' (' . (int)$c['n_ev'] . ')</a></li>', $cs)) . '</ul></section>';
    } elseif (!$d && !$theme && !$weekend && $page === 1) {
        $cs = nor_db()->query('SELECT * FROM communes WHERE n_ev>=3 ORDER BY n_ev DESC, nom LIMIT 30')->fetchAll();
        if ($cs) $body .= '<section class="nr-sec"><h2>Agenda des principales villes</h2><ul class="nr-tags">' . implode('', array_map(fn($c) => '<li><a href="' . nor_com_agenda_url($c) . '">' . h($c['nom']) . ' (' . (int)$c['n_ev'] . ')</a></li>', $cs)) . '</ul></section>';
    }
    if ($page === 1 && !$theme && !$weekend) {
        if ($com && $n >= 20) $body .= nor_gyg($com['nom'], 'agenda-commune', 'Que visiter ' . nor_a($com['nom']) . ' et autour');
        elseif (!$com && $d) $body .= nor_gyg(NOR_GYG_DEPT_Q[$d], 'agenda-dept', 'Visites et excursions ' . nor_in($d));
        elseif (!$com && !$d) $body .= nor_gyg('Normandie', 'agenda');
    }
    if ($page === 1 && !$com) $body .= '<p>Voir aussi : <a href="/festivals/' . ($d ? NOR_DEPTS[$d][1] . '/' : '') . '">les festivals' . h($d ? ' ' . nor_du($d) : ' de Normandie') . '</a>'
        . ($weekend ? '' : ' · <a href="/agenda/' . ($d ? NOR_DEPTS[$d][1] . '/' : '') . 'ce-week-end/">que faire ce week-end</a>') . '</p>';
    $body .= nor_pager($base, $page, $pages) . nor_sources('dt');
    $desc = $weekend ? nor_plural($n, 'sortie', 'sorties') . $where . ' le week-end du ' . nor_date($sat, false) . ' : concerts, marchés, expositions, fêtes, visites et animations.'
        : nor_plural($n, 'sortie', 'sorties') . ' à venir' . $where . ($T ? ' – ' . mb_strtolower($T) : ' : concerts, marchés, vide-greniers, expositions, fêtes et spectacles') . ', mis à jour chaque jour.';
    return nor_page($site, ['title' => $h1 . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'], 'path' => $base . ($page > 1 ? 'page/' . $page . '/' : ''), 'robots' => $robots, 'desc' => $desc], $body, [$bc]);
}

// ---------- Fiche événement ----------

function nor_event(array $site, string $slug, string $ym)
{
    $st = nor_db()->prepare("SELECT i.*, c.nom cnom, c.slug cslug, c.dept, c.lat clat, c.lon clon FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE i.kind='evenement' AND i.slug=?");
    $st->execute([$slug]);
    $r = $st->fetch();
    if (!$r) {
        $g = nor_db()->prepare("SELECT 1 FROM gone WHERE kind='evenement' AND slug=?"); $g->execute([$slug]);
        if ($g->fetchColumn()) { http_response_code(410); return nor_page($site, ['title' => 'Événement terminé | ' . $site['name'], 'robots' => 'noindex,follow', 'noads' => true], '<h1 style="margin-top:28px">Cet événement est terminé</h1><p>Il n\'est plus annoncé par sa source. <a href="/agenda/">Voir l\'agenda des sorties en Normandie</a> · <a href="/agenda/ce-week-end/">Que faire ce week-end</a></p>'); }
        return '';
    }
    if ($r['ym'] !== $ym) { header('Location: ' . nor_ev_url($r), true, 301); return null; }
    if (!$r['page']) return '';
    $d = nor_data($r); $t = nor_today();
    $dept = (string)$r['dept'];
    $past = $r['d2'] < $t;
    $curl = nor_com_agenda_url(['dept' => $dept, 'slug' => $r['cslug']]);
    [$crh, $bc] = nor_crumbs($site, [['Agenda', '/agenda/'], [nor_dn($dept), '/agenda/' . NOR_DEPTS[$dept][1] . '/'], [$r['cnom'], $curl], [$r['name'], '']]);
    $sub = mb_strtolower((string)$r['sub']);
    $lieu = trim(($d['adresse'] ?? '') . ', ' . ($d['cp'] ?? '') . ' ' . $r['cnom'], ', ');
    $lead = '« ' . $r['name'] . ' » (' . $sub . ') a lieu ' . nor_period($r['d1'], $r['d2']) . ' ' . nor_a((string)$r['cnom']) . ' (' . nor_dn($dept) . ')' . (!empty($d['adresse']) ? ', ' . $d['adresse'] : '') . '.';
    $place = ['@type' => 'Place', 'name' => trim(($d['adresse'] ?? '') . ' ' . $r['cnom']), 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $d['adresse'] ?? '', 'postalCode' => $d['cp'] ?? '', 'addressLocality' => $r['cnom'], 'addressRegion' => 'Normandie', 'addressCountry' => 'FR']];
    if ($r['lat']) $place['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$r['lat'], 'longitude' => (float)$r['lon']];
    $schema = ['@context' => 'https://schema.org', '@type' => $r['sub'] === 'Festival' ? 'Festival' : 'Event', 'name' => $r['name'], 'url' => nor_canon(nor_ev_url($r)), 'startDate' => $r['d1'], 'endDate' => $r['d2'],
        'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'location' => $place, 'organizer' => ['@type' => 'Organization', 'name' => $d['auteur'] ?? $r['cnom']]];
    $txt = trim((string)$r['txt']);
    if ($txt !== '') $schema['description'] = mb_substr(nor_clean($txt), 0, 300);
    if (!empty($d['web'][0])) { $schema['sameAs'] = $d['web'][0]; $schema['organizer']['url'] = $d['web'][0]; }

    $facts = array_filter(['Type' => $r['sub'], 'Date' . (count($d['periodes'] ?? []) > 1 ? ' (prochaine)' : '') => nor_ucfirst(nor_period($r['d1'], $r['d2'])), 'Lieu' => $lieu,
        'Commune' => $r['cnom'] . ' (' . nor_dn($dept) . ')', 'Publié par' => $d['auteur'] ?? '', 'Mise à jour de la source' => !empty($d['maj']) ? nor_date($d['maj'], false) : ''], fn($v) => trim((string)$v) !== '');
    $body = $crh . '<article><h1>' . h($r['name']) . '</h1>';
    if ($past) $body .= '<p class="nr-past">Cet événement est terminé. Voir <a href="/agenda/' . NOR_DEPTS[$dept][1] . '/">les prochaines sorties ' . h(nor_du($dept)) . '</a>.</p>';
    $body .= '<p class="nr-ans">' . h($lead) . '</p>';
    $body .= '<table class="nr-facts">' . implode('', array_map(fn($k, $v) => '<tr><th>' . h($k) . '</th><td>' . h($v) . '</td></tr>', array_keys($facts), $facts));
    if (!empty($d['web'][0])) $body .= '<tr><th>Site de l\'organisateur</th><td><a href="' . h($d['web'][0]) . '" rel="nofollow noopener" target="_blank">' . h(preg_replace('~^https?://(www\.)?|/$~', '', $d['web'][0])) . '</a></td></tr>';
    $body .= '</table>';
    if ($txt !== '') $body .= '<section class="nr-sec nr-txt"><h2>À propos de cet événement</h2>' . sanitize_html($txt) . '</section>';
    if (count($d['periodes'] ?? []) > 1) $body .= '<section class="nr-sec"><h2>Toutes les dates</h2><ul>' . implode('', array_map(fn($p) => '<li>' . h(nor_ucfirst(nor_period($p[0], $p[1]))) . '</li>', array_slice($d['periodes'], 0, 20))) . '</ul></section>';
    $body .= ad_unit($site, 'in_article');
    $body .= nor_map($r['lat'] ? (float)$r['lat'] : null, $r['lon'] ? (float)$r['lon'] : null, $r['name']);

    $lat = (float)($r['lat'] ?: $r['clat']); $lon = (float)($r['lon'] ?: $r['clon']);
    $same = [];
    if ($lat) {
        $same = nor_near($lat, $lon, 'evenement', $r['id'], 8, 20, 'AND i.page=1 AND i.d2>=? AND i.d1<=? AND i.d2>=?', [$t, $r['d2'], max((string)$r['d1'], $t)]);
        if ($same) $body .= '<section class="nr-sec"><h2>Aux mêmes dates dans le secteur</h2>' . nor_ul($same, fn($x) => trim($x['sub'] . ' · ' . $x['cnom'] . ' · ' . round(nor_km($lat, $lon, (float)$x['lat'], (float)$x['lon'])) . ' km', ' ·')) . '</section>';
        // lieux à visiter autour (fiches existantes du site)
        try {
            $pm = function_exists('places_mod') ? places_mod($site) : null;
            if ($pm) {
                $dl = 10 / 111; $dlo = 10 / 74;
                $ps = site_db($site['host'])->prepare("SELECT slug, name, kind, city, lat, lon, ((lat-?)*(lat-?)+((lon-?)*0.67)*((lon-?)*0.67)) dd FROM places WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ? AND kind NOT LIKE 'Héberg%' AND kind NOT LIKE 'Service%' AND kind NOT LIKE 'Transport%' ORDER BY dd LIMIT 6");
                $ps->execute([$lat, $lat, $lon, $lon, $lat - $dl, $lat + $dl, $lon - $dlo, $lon + $dlo]);
                $pl = $ps->fetchAll();
                if ($pl) $body .= '<section class="nr-sec"><h2>À visiter autour</h2><ul class="nr-list">' . implode('', array_map(fn($p) => '<li><a href="/' . $pm['item'] . '/' . h($p['slug']) . '/">' . h($p['name']) . '</a><small>' . h(trim($p['kind'] . ' · ' . $p['city'] . ' · ' . round(nor_km($lat, $lon, (float)$p['lat'], (float)$p['lon']), 1) . ' km', ' ·')) . '</small></li>', $pl)) . '</ul></section>';
            }
        } catch (Throwable $e) {}
        $heb = nor_near($lat, $lon, 'hebergement', $r['id'], 6, 12);
        $body .= '<section class="nr-sec"><h2>Où dormir à proximité</h2>' . nor_stay_link((string)$r['cnom'], max((string)$r['d1'], $t), (string)$r['d2']) . ($heb ? nor_ul($heb, fn($x) => trim($x['sub'] . ' · ' . $x['cnom'] . ' · ' . round(nor_km($lat, $lon, (float)$x['lat'], (float)$x['lon']), 1) . ' km', ' ·')) : '') . '</section>';
    }
    $qa = [['Quand a lieu « ' . $r['name'] . ' » ?', nor_ucfirst(nor_period($r['d1'], $r['d2'])) . (count($d['periodes'] ?? []) > 1 ? ' (prochaine date ; ' . count($d['periodes']) . ' dates annoncées au total).' : '.')],
        ['Où se déroule « ' . $r['name'] . ' » ?', nor_ucfirst(nor_a((string)$r['cnom'])) . ' (' . nor_dn($dept) . ')' . (!empty($d['adresse']) ? ', ' . $d['adresse'] : '') . '.']];
    if ($same) $qa[] = ['Que faire d\'autre aux mêmes dates près ' . nor_de((string)$r['cnom']) . ' ?', implode(' ; ', array_map(fn($x) => $x['name'] . ' (' . $x['cnom'] . ')', array_slice($same, 0, 4))) . '.'];
    if (!$past) $body .= nor_gyg(NOR_GYG_DEPT_Q[$dept] ?? 'Normandie', 'evenement', 'À faire aussi dans le secteur');
    [$fh, $fld] = nor_faq($qa);
    $body .= $fh;
    $ths = nor_db()->prepare('SELECT theme FROM themes WHERE id=?'); $ths->execute([$r['id']]);
    $links = ['<a href="' . $curl . '">agenda ' . h(nor_de((string)$r['cnom'])) . '</a>', '<a href="/agenda/' . NOR_DEPTS[$dept][1] . '/ce-week-end/">ce week-end ' . h(nor_in($dept)) . '</a>'];
    foreach ($ths->fetchAll(PDO::FETCH_COLUMN) as $th) if (isset(NOR_THEMES[$th])) $links[] = '<a href="/agenda/' . $th . '/' . NOR_DEPTS[$dept][1] . '/">' . h(mb_strtolower(NOR_THEMES[$th][0])) . ' ' . h(nor_in($dept)) . '</a>';
    $body .= '<p>Voir aussi : ' . implode(' · ', $links) . '</p>' . nor_sources('dt') . '</article>';
    $robots = nor_indexable_ev($r) ? null : 'noindex,follow';
    return nor_page($site, ['title' => $r['name'] . ' – ' . $r['cnom'] . ', ' . nor_period($r['d1'], $r['d2']) . ' | ' . $site['name'], 'path' => nor_ev_url($r), 'robots' => $robots, 'og_type' => 'article',
        'desc' => mb_substr($lead, 0, 158)], $body, array_merge([$bc, $schema], $fld));
}

// ---------- Festivals ----------

function nor_festivals(array $site, ?string $d, int $page): string
{
    $w = "i.kind='festival' AND i.page=1" . ($d ? ' AND c.dept=?' : ''); $a = $d ? [$d] : [];
    $n = nor_count($w, $a); $pages = max(1, (int)ceil($n / NOR_PER));
    if ($page > $pages) return '';
    $rows = nor_items($w, $a, 'i.rank DESC, i.name', NOR_PER, ($page - 1) * NOR_PER);
    $where = $d ? ' ' . nor_in($d) : ' en Normandie';
    $base = '/festivals/' . ($d ? NOR_DEPTS[$d][1] . '/' : '');
    [$crh, $bc] = nor_crumbs($site, $d ? [['Festivals', '/festivals/'], [nor_dn($d), '']] : [['Festivals', '']]);
    $body = $crh . '<h1 style="margin-top:20px">Festivals' . h($where) . ($page > 1 ? ' – page ' . $page : '') . '</h1>';
    $body .= '<p class="nr-ans">' . h(nor_plural($n, 'festival est recensé', 'festivals sont recensés') . $where . ' par le ministère de la Culture : musique, spectacle vivant, cinéma, arts visuels et livre. Chaque fiche indique la période habituelle et les prochaines dates annoncées.') . '</p>';
    $body .= nor_dept_tags('/festivals/', $d);
    $body .= nor_ul($rows, fn($r) => trim($r['sub'] . ' · ' . $r['cnom'] . (!empty(nor_data($r)['periode']) ? ' · ' . nor_fest_period((string)nor_data($r)['periode']) : ''), ' ·'));
    $body .= nor_pager($base, $page, $pages) . nor_sources('festivals');
    return nor_page($site, ['title' => 'Festivals' . $where . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'], 'path' => $base . ($page > 1 ? 'page/' . $page . '/' : ''),
        'desc' => nor_plural($n, 'festival', 'festivals') . $where . ' : musique, théâtre, cinéma, arts de la rue, période et prochaines dates.'], $body, [$bc]);
}

function nor_festival(array $site, string $slug)
{
    $st = nor_db()->prepare("SELECT i.*, c.nom cnom, c.slug cslug, c.dept FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE i.kind='festival' AND i.slug=?");
    $st->execute([$slug]);
    $r = $st->fetch();
    if (!$r) {
        $g = nor_db()->prepare("SELECT 1 FROM gone WHERE kind='festival' AND slug=?"); $g->execute([$slug]);
        if ($g->fetchColumn()) { http_response_code(410); return nor_page($site, ['title' => 'Festival retiré | ' . $site['name'], 'robots' => 'noindex,follow', 'noads' => true], '<h1 style="margin-top:28px">Ce festival n\'est plus recensé</h1><p><a href="/festivals/">Voir tous les festivals de Normandie</a></p>'); }
        return '';
    }
    $d = nor_data($r); $dept = (string)$r['dept']; $t = nor_today();
    [$crh, $bc] = nor_crumbs($site, [['Festivals', '/festivals/'], [nor_dn($dept), '/festivals/' . NOR_DEPTS[$dept][1] . '/'], [$r['name'], '']]);
    $sub = mb_strtolower((string)$r['sub']);
    $per = !empty($d['periode']) ? nor_fest_period((string)$d['periode']) : '';
    $lead = $r['name'] . ' est un festival ' . ($sub ? '(' . $sub . ') ' : '') . 'organisé ' . nor_a((string)$r['cnom']) . ' (' . nor_dn($dept) . ')' . (!empty($d['creation']) ? ' depuis ' . $d['creation'] : '') . ($per ? ', habituellement ' . $per : '') . '.';
    // éditions annoncées dans l'agenda (même commune, nom proche)
    $ed = [];
    $es = nor_db()->prepare("SELECT i.*, c.nom cnom, c.slug cslug, c.dept FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE i.kind='evenement' AND i.page=1 AND i.insee=? AND i.d2>=? ORDER BY i.d1 LIMIT 80");
    $es->execute([$r['insee'], $t]);
    $nn = nor_norm(preg_replace('/^festival\s+/iu', '', (string)$r['name']));
    foreach ($es as $e) { similar_text($nn, nor_norm(preg_replace('/^festival\s+/iu', '', (string)$e['name'])), $pc); if ($pc >= 60 || (strlen($nn) >= 5 && str_contains(nor_norm((string)$e['name']), $nn))) $ed[] = $e; }
    if ($ed) $lead .= ' Prochaine date annoncée : ' . nor_period($ed[0]['d1'], $ed[0]['d2']) . '.';
    $facts = array_filter(['Discipline' => $r['sub'], 'Genres' => $d['genres'] ?? '', 'Période habituelle' => !empty($d['periode']) ? nor_ucfirst(nor_fest_period((string)$d['periode'])) : '', 'Année de création' => $d['creation'] ?? '', 'Commune' => $r['cnom'] . ' (' . nor_dn($dept) . ')',
        'Adresse' => $d['adresse'] ?? '', 'Envergure' => $d['envergure'] ?? ''], fn($v) => trim((string)$v) !== '');
    $place = ['@type' => 'Place', 'name' => $r['cnom'], 'address' => ['@type' => 'PostalAddress', 'addressLocality' => $r['cnom'], 'addressRegion' => 'Normandie', 'addressCountry' => 'FR']];
    $schema = ['@context' => 'https://schema.org', '@type' => 'Festival', 'name' => $r['name'], 'url' => nor_canon(nor_fest_url($r)), 'location' => $place];
    if ($ed) $schema += ['startDate' => $ed[0]['d1'], 'endDate' => $ed[0]['d2'], 'eventStatus' => 'https://schema.org/EventScheduled'];
    if (!empty($d['web'][0])) $schema['sameAs'] = $d['web'][0];
    $body = $crh . '<article><h1>' . h($r['name']) . '</h1><p class="nr-ans">' . h($lead) . '</p>';
    $body .= '<table class="nr-facts">' . implode('', array_map(fn($k, $v) => '<tr><th>' . h($k) . '</th><td>' . h($v) . '</td></tr>', array_keys($facts), $facts));
    if (!empty($d['web'][0])) $body .= '<tr><th>Site officiel</th><td><a href="' . h($d['web'][0]) . '" rel="nofollow noopener" target="_blank">' . h(preg_replace('~^https?://(www\.)?|/$~', '', $d['web'][0])) . '</a></td></tr>';
    $body .= '</table>';
    if (trim((string)$r['txt']) !== '') $body .= '<section class="nr-sec nr-txt"><h2>Le festival</h2>' . sanitize_html((string)$r['txt']) . '</section>';
    if ($ed) $body .= '<section class="nr-sec"><h2>Prochaines dates annoncées</h2>' . nor_ul($ed, fn($x) => nor_ucfirst(nor_period($x['d1'], $x['d2']))) . '</section>';
    $body .= ad_unit($site, 'in_article') . nor_map($r['lat'] ? (float)$r['lat'] : null, $r['lon'] ? (float)$r['lon'] : null, $r['name'], 0.02);
    if ($r['lat']) {
        $nb = nor_near((float)$r['lat'], (float)$r['lon'], 'festival', $r['id'], 6, 40, 'AND i.page=1');
        if ($nb) $body .= '<section class="nr-sec"><h2>Autres festivals dans le secteur</h2>' . nor_ul($nb, fn($x) => trim($x['sub'] . ' · ' . $x['cnom'], ' ·')) . '</section>';
        $heb = nor_near((float)$r['lat'], (float)$r['lon'], 'hebergement', $r['id'], 6, 12);
        $body .= '<section class="nr-sec"><h2>Où dormir à proximité</h2>' . nor_stay_link((string)$r['cnom'], $ed ? max((string)$ed[0]['d1'], $t) : null, $ed ? (string)$ed[0]['d2'] : null) . ($heb ? nor_ul($heb, fn($x) => trim($x['sub'] . ' · ' . $x['cnom'], ' ·')) : '') . '</section>';
    }
    $qa = [['Quand a lieu ' . $r['name'] . ' ?', $ed ? 'La prochaine date annoncée est ' . nor_period($ed[0]['d1'], $ed[0]['d2']) . '.' : ($per ? 'Le festival se déroule habituellement ' . $per . '. Les dates de la prochaine édition ne sont pas encore publiées.' : 'Les dates de la prochaine édition ne sont pas encore publiées.')],
        ['Où se déroule ' . $r['name'] . ' ?', nor_ucfirst(nor_a((string)$r['cnom'])) . ' (' . nor_dn($dept) . ')' . (!empty($d['adresse']) ? ', ' . $d['adresse'] : '') . '.']];
    $body .= nor_gyg(NOR_GYG_DEPT_Q[$dept] ?? 'Normandie', 'festival', 'À faire aussi dans le secteur');
    [$fh, $fld] = nor_faq($qa);
    $body .= $fh . '<p>Voir aussi : <a href="/agenda/' . NOR_DEPTS[$dept][1] . '/' . h((string)$r['cslug']) . '/">agenda ' . h(nor_de((string)$r['cnom'])) . '</a> · <a href="/festivals/' . NOR_DEPTS[$dept][1] . '/">festivals ' . h(nor_du($dept)) . '</a></p>' . nor_sources('festivals', 'dt') . '</article>';
    return nor_page($site, ['title' => $r['name'] . ' – festival ' . nor_a((string)$r['cnom']) . ($ed ? ', ' . substr((string)$ed[0]['d1'], 0, 4) : '') . ' | ' . $site['name'], 'path' => nor_fest_url($r), 'og_type' => 'article',
        'desc' => mb_substr($lead, 0, 158)], $body, array_merge([$bc, $schema], $fld));
}

// ---------- Sitemaps ----------

function nor_sitemap_names(array $site): array
{
    $db = nor_db(); $o = ['hubs'];
    $n = (int)$db->query("SELECT COUNT(*) FROM items WHERE kind='evenement' AND page=1 AND d2>=" . $db->quote(nor_today()) . ' AND length(txt)>=' . NOR_TXT_MIN)->fetchColumn();
    for ($i = 1; $i <= (int)ceil($n / 5000); $i++) $o[] = "evenement-$i";
    if ($db->query("SELECT 1 FROM items WHERE kind='festival' AND page=1 LIMIT 1")->fetchColumn()) $o[] = 'festival-1';
    return $o;
}

function nor_hub_urls(): array
{
    $db = nor_db(); $u = ['/agenda/', '/agenda/ce-week-end/', '/festivals/'];
    foreach (NOR_DEPTS as [, $ds]) { $u[] = "/agenda/$ds/"; $u[] = "/agenda/$ds/ce-week-end/"; $u[] = "/festivals/$ds/"; }
    $t = $db->quote(nor_today());
    foreach (array_keys(NOR_THEMES) as $th) {
        $n = (int)$db->query("SELECT COUNT(*) FROM themes x JOIN items i ON i.id=x.id WHERE x.theme=" . $db->quote($th) . " AND i.d2>=$t")->fetchColumn();
        if ($n >= 3) $u[] = "/agenda/$th/";
        foreach ($db->query("SELECT c.dept, COUNT(*) n FROM themes x JOIN items i ON i.id=x.id JOIN communes c ON c.insee=i.insee WHERE x.theme=" . $db->quote($th) . " AND i.d2>=$t GROUP BY c.dept HAVING n>=3") as $r) $u[] = "/agenda/$th/" . NOR_DEPTS[$r['dept']][1] . '/';
    }
    foreach ($db->query('SELECT * FROM communes WHERE n_ev>=3') as $c) $u[] = nor_com_agenda_url($c);
    return $u;
}

function nor_out_sitemap(array $site, string $name): void
{
    $db = nor_db();
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $day = date('Y-m-d');
    if ($name === 'hubs') {
        foreach (nor_hub_urls() as $u) echo '<url><loc>' . h(nor_canon($u)) . '</loc><lastmod>' . $day . '</lastmod></url>';
    } elseif (preg_match('/^evenement-(\d+)$/', $name, $m)) {
        $st = $db->prepare("SELECT kind, slug, ym, substr(COALESCE(txt_at, seen),1,10) m FROM items WHERE kind='evenement' AND page=1 AND d2>=? AND length(txt)>=? ORDER BY d1, id LIMIT 5000 OFFSET ?");
        $st->execute([nor_today(), NOR_TXT_MIN, ((int)$m[1] - 1) * 5000]);
        foreach ($st as $r) echo '<url><loc>' . h(nor_canon(nor_ev_url($r))) . '</loc><lastmod>' . h($r['m']) . '</lastmod></url>';
    } elseif ($name === 'festival-1') {
        foreach ($db->query("SELECT slug, substr(seen,1,10) m FROM items WHERE kind='festival' AND page=1 ORDER BY rank DESC") as $r) echo '<url><loc>' . h(nor_canon('/festivals/' . $r['slug'] . '/')) . '</loc><lastmod>' . h($r['m']) . '</lastmod></url>';
    }
    echo '</urlset>';
}

// URL à signaler (IndexNow) : hubs + fiches indexables.
function nor_all_urls(): array
{
    $u = array_map('nor_canon', nor_hub_urls());
    $st = nor_db()->prepare("SELECT kind, slug, ym FROM items WHERE page=1 AND ((kind='evenement' AND d2>=? AND length(txt)>=?) OR kind='festival')");
    $st->execute([nor_today(), NOR_TXT_MIN]);
    foreach ($st as $r) $u[] = nor_canon(nor_item_url($r));
    return $u;
}

// ---------- Textes rédigés (API pour le hub) ----------

// GET : fiches sans texte ; POST {items:[{id, html}]} : enregistre.
function nor_text_api(array $in): array
{
    $db = nor_db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $out = 0;
        foreach ((array)($in['items'] ?? [$in]) as $it) {
            $id = (string)($it['id'] ?? ''); $html = sanitize_html((string)($it['html'] ?? ''));
            if ($id === '' || mb_strlen(strip_tags($html)) < 200) continue;
            if (preg_match('/en tant qu.(ia|intelligence artificielle|assistant)|as an ai|je ne peux pas|\[(insérer|insert)|lorem ipsum/iu', $html)) continue;
            $sig = $db->prepare('SELECT data FROM items WHERE id=?'); $sig->execute([$id]);
            $dd = $sig->fetchColumn(); if ($dd === false) continue;
            $db->prepare('UPDATE items SET txt=?, txt_at=?, txt_sig=? WHERE id=?')->execute([$html, now(), md5((string)(json_decode((string)$dd, true)['desc'] ?? '')), $id]);
            $out++;
        }
        if ($out) cache_clear(NOR_HOST);
        return ['ok' => true, 'stored' => $out];
    }
    $lim = max(1, min(100, (int)($_GET['limit'] ?? 20))); $off = max(0, (int)($_GET['offset'] ?? 0));
    $st = $db->prepare("SELECT i.id, i.kind, i.sub, i.name, i.d1, i.d2, i.data, c.nom cnom, c.dept FROM items i LEFT JOIN communes c ON c.insee=i.insee
        WHERE i.page=1 AND ((i.kind='evenement' AND i.d2>=?) OR i.kind='festival') AND (i.txt IS NULL OR i.txt='') ORDER BY i.kind='festival' DESC, i.d1, i.rank DESC LIMIT $lim OFFSET $off");
    $st->execute([nor_today()]);
    $o = [];
    foreach ($st as $r) {
        $d = nor_data($r);
        $o[] = ['id' => $r['id'], 'kind' => $r['kind'], 'type' => $r['sub'], 'nom' => $r['name'], 'commune' => $r['cnom'], 'departement' => nor_dn((string)$r['dept']),
            'dates' => $r['kind'] === 'evenement' ? nor_period($r['d1'], $r['d2']) . (count($d['periodes'] ?? []) > 1 ? ' (' . count($d['periodes']) . ' dates)' : '') : (!empty($d['periode']) ? 'habituellement ' . nor_fest_period((string)$d['periode']) . ' (période indicative, dates exactes non fournies)' : ''),
            'adresse' => $d['adresse'] ?? '', 'description_source' => mb_substr((string)($d['desc'] ?? ''), 0, 1500), 'genres' => $d['genres'] ?? '', 'creation' => $d['creation'] ?? ''];
    }
    return $o;
}

// ---------- Menu principal du site ----------

function nor_menu(array $site): string
{
    static $html = null;
    if ($html !== null) return $html;
    $cur = (string)($_SERVER['REQUEST_URI'] ?? '');
    $L = fn(string $u, string $t) => '<a href="' . $u . '"' . ($cur === $u ? ' aria-current="page"' : '') . '>' . h($t) . '</a>';
    // thèmes d'agenda les plus fournis en ce moment
    $th = [];
    try {
        $st = nor_db()->prepare("SELECT x.theme, COUNT(*) n FROM themes x JOIN items i ON i.id=x.id WHERE i.d2>=? GROUP BY x.theme HAVING n>=10 ORDER BY n DESC");
        $st->execute([nor_today()]);
        $mo = (int)date('n'); $prio = array_merge($mo === 10 ? ['halloween'] : [], in_array($mo, [11, 12], true) ? ['marches-de-noel'] : [], ['vide-greniers']);
        $top = array_column($st->fetchAll(), 'theme');
        foreach (array_slice(array_values(array_unique(array_merge(array_intersect($prio, $top), $top))), 0, 8) as $t) if (isset(NOR_THEMES[$t])) $th[] = $L('/agenda/' . $t . '/', NOR_THEMES[$t][0]);
    } catch (Throwable $e) {}
    $depts = ['14' => 'calvados-14', '27' => 'eure-27', '50' => 'manche-50', '61' => 'orne-61', '76' => 'seine-maritime-76'];
    $cats = [];
    foreach (site_categories($site) as $c) $cats[$c['slug']] = $c['name'];
    $C = fn(string $slug, string $t) => isset($cats[$slug]) ? $L('/category/' . $slug . '/', $t) : '';
    $dd = function (string $label, string $href, string $panel, bool $wide = false) use ($cur) {
        $on = str_starts_with($cur, $href) ? ' class="on"' : '';
        return '<details class="nm-dd"><summary' . $on . '>' . h($label) . '</summary><div class="nm-p' . ($wide ? ' w3' : '') . '">' . $panel . '</div></details>';
    };
    $agenda = '<div><b>Quand</b>' . $L('/agenda/ce-week-end/', 'Ce week-end') . $L('/agenda/', 'Tout l\'agenda') . $L('/festivals/', 'Festivals') . '</div>'
        . '<div><b>Où</b>' . implode('', array_map(fn($d, $v) => $L('/agenda/' . $v[1] . '/', $v[0]), array_keys(NOR_DEPTS), NOR_DEPTS)) . '</div>'
        . ($th ? '<div><b>Envies</b>' . implode('', $th) . '</div>' : '');
    $visit = '<div><b>Lieux</b>' . $L('/lieux-a-visiter/', 'Tous les lieux à visiter') . implode('', array_map(fn($d, $sl) => $L('/lieux-a-visiter/' . $sl . '/', NOR_DEPTS[$d][0]), array_keys($depts), $depts)) . '</div>'
        . '<div><b>Idées</b>' . $C('que-faire-en-normandie', 'Que faire en Normandie') . $C('plages-et-cote', 'Plages et côte') . $C('villes-et-villages', 'Villes et villages') . $C('week-ends-et-sejours', 'Week-ends et séjours') . $C('sorties-en-famille', 'Sorties en famille') . '</div>';
    $gastro = '<div>' . $C('gastronomie', 'Gastronomie et terroir') . $C('recettes-normandes', 'Recettes normandes') . '</div>';
    $cult = '<div>' . $C('histoire-et-patrimoine', 'Histoire et patrimoine') . $C('culture-et-traditions', 'Culture et traditions') . $C('fete-et-traditions', 'Fêtes et traditions') . '</div>';
    $html = '<style>header.top .cats{overflow:visible;flex-wrap:wrap;gap:6px 4px}.nm-dd{position:relative}.nm-dd summary{list-style:none;cursor:pointer;padding:6px 10px;border-radius:8px;color:var(--t);font-weight:600;white-space:nowrap}'
        . '.nm-dd summary::-webkit-details-marker{display:none}.nm-dd summary:after{content:"";display:inline-block;margin-left:6px;width:6px;height:6px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:translateY(-3px) rotate(45deg);opacity:.6}'
        . '.nm-dd summary:hover,.nm-dd[open] summary,.nm-dd summary.on{background:var(--s);color:var(--c)}.nm-top{padding:6px 10px;border-radius:8px;font-weight:600;color:var(--t)!important;white-space:nowrap}.nm-top:hover{background:var(--s);color:var(--c)!important}.nm-hl{background:var(--c);color:#fff!important}.nm-hl:hover{filter:brightness(1.1);background:var(--c);color:#fff!important}'
        . '.nm-p{position:absolute;left:0;top:calc(100% + 6px);z-index:60;background:#fff;border:1px solid var(--b);border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.13);padding:14px 18px;display:grid;grid-template-columns:repeat(2,minmax(170px,1fr));gap:4px 26px;min-width:380px}'
        . '.nm-p.w3{grid-template-columns:repeat(3,minmax(160px,1fr));min-width:560px}.nm-p:before{content:"";position:absolute;left:0;right:0;top:-10px;height:10px}.nm-p div{display:flex;flex-direction:column}.nm-p b{color:var(--c);font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;margin:4px 0 4px}'
        . '.nm-p a{color:var(--t)!important;padding:4px 0;text-decoration:none;font-size:.92rem}.nm-p a:hover,.nm-p a[aria-current]{color:var(--c)!important}'
        . '@media(min-width:1180px){header.top .w{gap:14px;flex-wrap:nowrap}header.top .cats{flex:1 1 auto}.nm-box{flex-wrap:nowrap}header.top form.q input{width:150px}}.nm-t{display:none}.nm-bt{display:none}.nm-box{display:flex;flex-wrap:wrap;align-items:center;gap:4px}'
        . '@media(max-width:760px){header.top .w{gap:10px}header.top .cats{order:5;width:100%;flex:1 1 100%;display:block;overflow:visible}header.top form.q{flex:1 1 auto;min-width:0}header.top form.q input{width:100%;max-width:100%}'
        . '.nm-bt{display:inline-flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;padding:8px 12px;border:1px solid var(--b);border-radius:8px;background:#fff}.nm-bt span,.nm-bt span:before,.nm-bt span:after{display:block;width:18px;height:2px;background:var(--t);position:relative}.nm-bt span:before,.nm-bt span:after{content:"";position:absolute;left:0}.nm-bt span:before{top:-6px}.nm-bt span:after{top:6px}'
        . '.nm-box{display:none;flex-direction:column;align-items:stretch;margin-top:8px;border-top:1px solid var(--b);padding-top:6px}.nm-t:checked~.nm-box{display:flex}.nm-dd summary,.nm-top{display:block;padding:10px 6px}.nm-hl{text-align:center}'
        . '.nm-p,.nm-p.w3{position:static;box-shadow:none;border:0;min-width:0;grid-template-columns:1fr 1fr;padding:4px 6px 10px}.nm-p:before{display:none}}</style>'
        . '<input type="checkbox" id="nm-t" class="nm-t" aria-hidden="true"><label for="nm-t" class="nm-bt" aria-label="Ouvrir le menu"><span></span>Menu</label><div class="nm-box">'
        . '<a class="nm-top nm-hl" href="/agenda/ce-week-end/">Ce week-end</a>'
        . $dd('Agenda', '/agenda/', $agenda, true)
        . $dd('À visiter', '/lieux-a-visiter/', $visit)
        . $dd('Gastronomie', '/category/gastronomie/', $gastro)
        . $dd('Patrimoine', '/category/histoire-et-patrimoine/', $cult)
        . ($C('vivre-en-normandie', '') !== '' ? '<a class="nm-top" href="/category/vivre-en-normandie/">Vivre en Normandie</a>' : '') . '</div>'
        . '<script>(function(){var D=document.querySelectorAll("details.nm-dd");document.addEventListener("click",function(e){D.forEach(function(d){if(!d.contains(e.target))d.removeAttribute("open")})});'
        . 'D.forEach(function(d){d.addEventListener("toggle",function(){if(d.open)D.forEach(function(o){if(o!==d)o.removeAttribute("open")})})});'
        . 'if(matchMedia("(hover:hover)").matches)D.forEach(function(d){var t;d.addEventListener("mouseenter",function(){clearTimeout(t);d.open=true});d.addEventListener("mouseleave",function(){t=setTimeout(function(){d.open=false},180)})})})()</script>';
    return $html;
}

// ---------- En-tête complet de normandie.me (logo, recherche, barre de navigation à méga-menus) ----------

function nor_header(array $site): string
{
    $cur = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $L = fn(string $u, string $t, string $sub = '') => '<a href="' . $u . '"' . ($cur === $u ? ' aria-current="page"' : '') . '>' . h($t) . ($sub !== '' ? '<small>' . h($sub) . '</small>' : '') . '</a>';
    $cats = []; foreach (site_categories($site) as $c) $cats[$c['slug']] = 1;
    $C = fn(string $slug, string $t, string $sub = '') => isset($cats[$slug]) ? $L('/category/' . $slug . '/', $t, $sub) : '';
    $th = [];
    try {
        $st = nor_db()->prepare("SELECT x.theme, COUNT(*) n FROM themes x JOIN items i ON i.id=x.id WHERE i.d2>=? GROUP BY x.theme HAVING n>=10 ORDER BY n DESC");
        $st->execute([nor_today()]); $top = array_column($st->fetchAll(), 'n', 'theme');
        $mo = (int)date('n'); $prio = array_merge($mo === 10 ? ['halloween'] : [], in_array($mo, [11, 12], true) ? ['marches-de-noel'] : [], ['vide-greniers']);
        foreach (array_slice(array_values(array_unique(array_merge(array_intersect($prio, array_keys($top)), array_keys($top)))), 0, 7) as $t) if (isset(NOR_THEMES[$t])) $th[] = $L('/agenda/' . $t . '/', NOR_THEMES[$t][0]);
        $nWe = nor_count("i.kind='evenement' AND i.page=1 AND i.d2>=? AND i.d1<=?", nor_weekend());
    } catch (Throwable $e) { $nWe = 0; }
    $deptsL = ['14' => 'calvados-14', '27' => 'eure-27', '50' => 'manche-50', '61' => 'orne-61', '76' => 'seine-maritime-76'];
    $menus = [
        'Agenda' => ['/agenda/', '<div class="nh-col"><b>Quand ?</b>' . $L('/agenda/ce-week-end/', 'Ce week-end') . $L('/agenda/', 'Tout l\'agenda') . $L('/festivals/', 'Les festivals') . '</div>'
            . '<div class="nh-col"><b>Où ?</b>' . implode('', array_map(fn($d, $v) => $L('/agenda/' . $v[1] . '/', $v[0]), array_keys(NOR_DEPTS), NOR_DEPTS)) . '</div>'
            . ($th ? '<div class="nh-col"><b>Envies</b>' . implode('', $th) . '</div>' : '')
            . '<a class="nh-feat" href="/agenda/ce-week-end/"><span>Ce week-end</span><strong>' . ($nWe ? nor_nf($nWe) . ' sorties' : 'Toutes les sorties') . ' en Normandie</strong><em>Voir le programme →</em></a>'],
        'À visiter' => ['/lieux-a-visiter/', '<div class="nh-col"><b>Par département</b>' . $L('/lieux-a-visiter/', 'Tous les lieux à visiter') . implode('', array_map(fn($d, $sl) => $L('/lieux-a-visiter/' . $sl . '/', NOR_DEPTS[$d][0]), array_keys($deptsL), $deptsL)) . '</div>'
            . '<div class="nh-col"><b>Idées de sorties</b>' . $C('que-faire-en-normandie', 'Que faire en Normandie') . $C('plages-et-cote', 'Plages et côte') . $C('villes-et-villages', 'Villes et villages') . $C('week-ends-et-sejours', 'Week-ends et séjours') . $C('sorties-en-famille', 'Sorties en famille') . '</div>'],
        'Gastronomie' => ['/category/gastronomie/', '<div class="nh-col">' . $C('gastronomie', 'Gastronomie et terroir', 'Cidre, calvados, fromages, fruits de mer') . $C('recettes-normandes', 'Recettes normandes', 'Les classiques de la cuisine normande') . '</div>'],
        'Patrimoine' => ['/category/histoire-et-patrimoine/', '<div class="nh-col">' . $C('histoire-et-patrimoine', 'Histoire et patrimoine', 'Abbayes, châteaux, Débarquement') . $C('culture-et-traditions', 'Culture et traditions', 'Fêtes, patois, artisanat') . '</div>'],
    ];
    $nav = '';
    foreach ($menus as $lab => [$href, $panel]) {
        $on = str_starts_with($cur, $href) || ($lab === 'Agenda' && str_starts_with($cur, '/festivals/')) ? ' nh-on' : '';
        $wide = $lab === 'Agenda' ? ' nh-wide' : '';
        $nav .= '<li class="nh-it' . $on . '"><button type="button" class="nh-tg" aria-expanded="false">' . h($lab) . '<i></i></button><div class="nh-pn' . $wide . '"><div class="nh-pi">' . $panel . '</div></div></li>';
    }
    if (isset($cats['vivre-en-normandie'])) $nav .= '<li class="nh-it' . (str_starts_with($cur, '/category/vivre-en-normandie/') ? ' nh-on' : '') . '"><a class="nh-tg" href="/category/vivre-en-normandie/">Vivre en Normandie</a></li>';
    $css = '<style>:root{--c:#1F4C75;--nh-navy:#053360;--nh-blue:#1F4C75;--nh-gold:#E6A93A;--nh-green:#3E7138}'
        . 'header.nh{position:relative;z-index:50;background:#fff;box-shadow:0 1px 0 rgba(5,51,96,.08)}.nh-top{max-width:1180px;margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:22px}'
        . '.nh-logo{display:block;flex:0 0 auto;line-height:0}.nh-logo img{height:68px;width:auto}'
        . '.nh-q{flex:1;max-width:420px;margin-left:auto;position:relative}.nh-q input{width:100%;height:44px;border:1px solid #dfe5ec;background:#f4f7fa;border-radius:999px;padding:0 18px 0 44px;font:inherit;font-size:.95rem;color:#1d2327;transition:.15s}'
        . '.nh-q input:focus{outline:0;background:#fff;border-color:var(--nh-blue);box-shadow:0 0 0 3px rgba(31,76,117,.15)}.nh-q svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);width:17px;height:17px;stroke:#6b7a8a}'
        . '.nh-cta{flex:0 0 auto;display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 20px;border-radius:999px;background:var(--nh-gold);color:#2b1d00!important;font-weight:700;text-decoration:none;font-size:.95rem;box-shadow:0 2px 8px rgba(230,169,58,.35);transition:.15s}.nh-cta:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(230,169,58,.45)}'
        . '.nh-bar{background:var(--nh-navy);background:linear-gradient(90deg,#053360,#1F4C75)}.nh-nav{max-width:1180px;margin:0 auto;padding:0 12px;display:flex;list-style:none;margin-top:0;margin-bottom:0;gap:2px}'
        . '.nh-it{position:static}.nh-tg{display:flex;align-items:center;gap:8px;height:52px;padding:0 16px;background:none;border:0;color:#fff!important;font:inherit;font-size:.98rem;font-weight:600;letter-spacing:.01em;cursor:pointer;text-decoration:none;position:relative}'
        . '.nh-tg:after{content:"";position:absolute;left:16px;right:16px;bottom:0;height:3px;border-radius:3px 3px 0 0;background:var(--nh-gold);transform:scaleX(0);transition:transform .18s}.nh-it:hover .nh-tg:after,.nh-it.nh-open .nh-tg:after,.nh-on .nh-tg:after{transform:scaleX(1)}'
        . '.nh-tg i{width:7px;height:7px;border-right:2px solid rgba(255,255,255,.8);border-bottom:2px solid rgba(255,255,255,.8);transform:translateY(-2px) rotate(45deg);transition:.15s}.nh-open .nh-tg i{transform:translateY(1px) rotate(-135deg)}'
        . '.nh-pn{position:absolute;left:0;right:0;top:100%;background:#fff;box-shadow:0 18px 40px rgba(5,51,96,.18);border-top:3px solid var(--nh-gold);opacity:0;visibility:hidden;transform:translateY(-6px);transition:opacity .16s,transform .16s,visibility .16s}'
        . '.nh-open .nh-pn{opacity:1;visibility:visible;transform:none}.nh-pi{max-width:1180px;margin:0 auto;padding:26px 28px 30px;display:flex;gap:44px;align-items:flex-start}'
        . '.nh-col{display:flex;flex-direction:column;min-width:180px}.nh-col b{font-size:.74rem;text-transform:uppercase;letter-spacing:.09em;color:var(--nh-blue);margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #eef2f6}'
        . '.nh-col a{color:#1d2327!important;text-decoration:none;padding:6px 0;font-size:.95rem;transition:color .12s,transform .12s}.nh-col a:hover,.nh-col a[aria-current]{color:var(--nh-blue)!important;transform:translateX(3px)}.nh-col a small{display:block;color:#6b7a8a;font-size:.8rem;margin-top:1px}'
        . '.nh-feat{margin-left:auto;align-self:stretch;min-width:240px;max-width:280px;border-radius:14px;padding:22px;background:linear-gradient(140deg,#053360,#2F6693);color:#fff!important;text-decoration:none;display:flex;flex-direction:column;justify-content:flex-end;gap:6px;box-shadow:0 10px 24px rgba(5,51,96,.25)}'
        . '.nh-feat span{font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--nh-gold);font-weight:700}.nh-feat strong{font-size:1.25rem;line-height:1.25}.nh-feat em{font-style:normal;font-size:.9rem;opacity:.85}.nh-feat:hover em{opacity:1;text-decoration:underline}'
        . '.nh-bt{display:none}'
        . '@media(max-width:900px){.nh-top{padding:10px 16px;gap:12px;flex-wrap:wrap}.nh-logo img{height:52px}.nh-cta{display:none}.nh-q{order:3;flex:1 1 100%;max-width:none}.nh-q input{height:42px}'
        . '.nh-bt{display:inline-flex;align-items:center;justify-content:center;margin-left:auto;width:46px;height:46px;border-radius:12px;border:1px solid #dfe5ec;background:#fff;cursor:pointer}.nh-bt span,.nh-bt span:before,.nh-bt span:after{display:block;width:20px;height:2px;border-radius:2px;background:var(--nh-navy);position:relative;transition:.2s}.nh-bt span:before,.nh-bt span:after{content:"";position:absolute;left:0}.nh-bt span:before{top:-6px}.nh-bt span:after{top:6px}'
        . 'header.nh.nh-m .nh-bt span{background:transparent}header.nh.nh-m .nh-bt span:before{top:0;transform:rotate(45deg)}header.nh.nh-m .nh-bt span:after{top:0;transform:rotate(-45deg)}'
        . '.nh-bar{display:none}header.nh.nh-m .nh-bar{display:block}.nh-nav{flex-direction:column;padding:6px 0 10px}.nh-tg{width:100%;justify-content:space-between;height:52px;padding:0 20px;font-size:1.02rem;border-bottom:1px solid rgba(255,255,255,.08)}.nh-tg:after{display:none}'
        . '.nh-pn{position:static;opacity:1;visibility:visible;transform:none;box-shadow:none;border-top:0;display:none;background:#f6f8fb}.nh-open .nh-pn{display:block}.nh-pi{flex-direction:column;gap:18px;padding:16px 20px 20px}.nh-feat{margin-left:0;max-width:none;min-width:0}}'
        . '@media(max-width:900px){.nh-cta-m{display:flex;margin:10px 20px 6px;justify-content:center;height:46px;border-radius:12px;background:var(--nh-gold);color:#2b1d00!important;font-weight:700;align-items:center;text-decoration:none}}@media(min-width:901px){.nh-cta-m{display:none}}</style>';
    $js = '<script>(function(){var H=document.querySelector("header.nh");if(!H)return;var I=H.querySelectorAll(".nh-it"),hov=matchMedia("(hover:hover) and (min-width:901px)");'
        . 'function cl(x){I.forEach(function(i){if(i!==x){i.classList.remove("nh-open");var b=i.querySelector("button");if(b)b.setAttribute("aria-expanded","false")}})}'
        . 'I.forEach(function(it){var b=it.querySelector("button.nh-tg"),t;if(!b)return;b.addEventListener("click",function(e){e.stopPropagation();var o=!it.classList.contains("nh-open");cl(it);it.classList.toggle("nh-open",o);b.setAttribute("aria-expanded",o)});'
        . 'it.addEventListener("mouseenter",function(){if(!hov.matches)return;clearTimeout(t);cl(it);it.classList.add("nh-open")});it.addEventListener("mouseleave",function(){if(!hov.matches)return;t=setTimeout(function(){it.classList.remove("nh-open")},160)})});'
        . 'document.addEventListener("click",function(e){if(!H.contains(e.target))cl(null)});document.addEventListener("keydown",function(e){if(e.key==="Escape")cl(null)});'
        . 'H.querySelector(".nh-bt").addEventListener("click",function(){var m=H.classList.toggle("nh-m");this.setAttribute("aria-expanded",m)})})()</script>';
    return $css . '<header class="nh"><div class="nh-top"><a class="nh-logo" href="/" aria-label="' . h($site['name']) . ' — accueil"><picture><source srcset="/assets/logo-passion-normandie.webp" type="image/webp"><img src="/assets/logo-passion-normandie.png" alt="' . h($site['name']) . '" width="188" height="68" fetchpriority="high"></picture></a>'
        . '<form class="nh-q" action="/recherche/" method="get" role="search"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" name="q" placeholder="Une sortie, un lieu, une recette…" aria-label="Rechercher"></form>'
        . '<a class="nh-cta" href="/agenda/ce-week-end/">Que faire ce week-end ?</a>'
        . '<button type="button" class="nh-bt" aria-label="Menu" aria-expanded="false"><span></span></button></div>'
        . '<nav class="nh-bar" aria-label="Menu principal"><ul class="nh-nav">' . $nav . '</ul><a class="nh-cta-m" href="/agenda/ce-week-end/">Que faire ce week-end ?</a></nav></header>' . $js;
}
