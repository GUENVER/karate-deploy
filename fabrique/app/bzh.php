<?php
// Module « bzh » : guide de la Bretagne commune par commune (bretagne.decouverte.org), entièrement alimenté par l'open data.
// Données : base dédiée data/bzh/bzh.sqlite remplie par cli/bzh_sync.php. Pages : accueil, départements, communes,
// fiches (lieux, randonnées, événements, monuments, patrimoine, plages, festivals), listes, îles et horaires des bateaux.
declare(strict_types=1);

const BZH_HOST = 'bretagne.decouverte.org';
const BZH_DEPTS = ['22' => ["Côtes-d'Armor", 'cotes-d-armor'], '29' => ['Finistère', 'finistere'], '35' => ['Ille-et-Vilaine', 'ille-et-vilaine'], '56' => ['Morbihan', 'morbihan']];
// type => [préfixe fiche, préfixe liste, libellé pluriel, libellé singulier, type schema.org]
const BZH_KINDS = [
    'lieu' => ['a-voir', 'lieux-a-visiter', 'Lieux à visiter', 'lieu à visiter', 'TouristAttraction'],
    'monument' => ['monument', 'monuments-historiques', 'Monuments historiques', 'monument historique', 'LandmarksOrHistoricalBuildings'],
    'patrimoine' => ['patrimoine', 'patrimoine-bretagne', 'Patrimoine', 'élément du patrimoine', 'LandmarksOrHistoricalBuildings'],
    'plage' => ['plage', 'plages', 'Plages et baignade', 'site de baignade', 'Beach'],
    'rando' => ['randonnee', 'randonnees', 'Randonnées et circuits', 'randonnée', 'TouristAttraction'],
    'evenement' => ['evenement', 'agenda', 'Agenda des sorties', 'événement', 'Event'],
    'festival' => ['festival', 'festivals', 'Festivals', 'festival', 'Festival'],
];
// îles desservies par BreizhGo : slug => [nom, commune de rattachement, motif de reconnaissance dans les lignes GTFS]
const BZH_ILES = [
    'belle-ile-en-mer' => ['Belle-Île-en-Mer', 'Le Palais', '~belle~'],
    'groix' => ['Île de Groix', 'Groix', '~groix~'],
    'houat' => ["Île d'Houat", "Île-d'Houat", '~houat~'],
    'hoedic' => ["Île d'Hœdic", 'Hœdic', '~ho?e?dic~'],
    'ile-d-arz' => ["Île d'Arz", "Île-d'Arz", '~\barz\b~'],
    'brehat' => ['Île de Bréhat', 'Île-de-Bréhat', '~brehat~'],
    'ouessant' => ['Ouessant', 'Ouessant', '~ouessant~'],
    'molene' => ['Molène', 'Île-Molène', '~molene~'],
    'sein' => ['Île de Sein', 'Île-de-Sein', '~\bsein\b~'],
];
const BZH_PER = 30;
// thèmes de l'agenda : slug => [libellé, sous-types d'événements]
const BZH_EV_THEMES = [
    'concerts' => ['Concerts et festivals', ['Concert', 'Festival']],
    'expositions' => ['Expositions', ['Exposition']],
    'marches-brocantes' => ['Marchés et brocantes', ['Marché', 'Marché, foire', 'Vide-greniers, brocante', 'Foire, salon']],
    'spectacles' => ['Spectacles et théâtre', ['Spectacle']],
    'visites-guidees' => ['Visites guidées', ['Visite guidée', 'Portes ouvertes']],
    'enfants' => ['Sorties en famille', ['Jeune public']],
    'sport' => ['Événements sportifs', ['Sport', 'Compétition sportive']],
    'stages-ateliers' => ['Stages, ateliers, conférences', ['Stage, atelier', 'Conférence']],
    'fetes-animations' => ['Fêtes et animations', ['Fête, animation', 'Jeu, animation']],
];

// ---------- Base ----------

function bzh_db(): PDO
{
    static $db = null;
    if ($db) return $db;
    $db = pdo_open(cfg('data_dir') . '/bzh/bzh.sqlite');
    $db->exec("CREATE TABLE IF NOT EXISTS communes(insee TEXT PRIMARY KEY, nom TEXT, slug TEXT, dept TEXT, cps TEXT, pop INTEGER, lat REAL, lon REAL, surface REAL, epci TEXT, n_items INTEGER DEFAULT 0, intro TEXT, intro_at TEXT);
    CREATE UNIQUE INDEX IF NOT EXISTS ux_com ON communes(dept, slug);
    CREATE TABLE IF NOT EXISTS items(id TEXT PRIMARY KEY, kind TEXT, sub TEXT, slug TEXT, name TEXT, insee TEXT, lat REAL, lon REAL, d1 TEXT, d2 TEXT, rank INTEGER DEFAULT 0, page INTEGER DEFAULT 0, data TEXT, seen TEXT, created TEXT, txt TEXT);
    CREATE UNIQUE INDEX IF NOT EXISTS ux_items ON items(kind, slug);
    CREATE INDEX IF NOT EXISTS ix_items_c ON items(insee, kind, rank);
    CREATE INDEX IF NOT EXISTS ix_items_d ON items(kind, d1);
    CREATE INDEX IF NOT EXISTS ix_items_lat ON items(lat);
    CREATE TABLE IF NOT EXISTS bzh_meta(k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS gone(kind TEXT, slug TEXT, at TEXT, PRIMARY KEY(kind, slug));
    CREATE TABLE IF NOT EXISTS ferries(ile TEXT, route TEXT, day TEXT, dep TEXT, arr TEXT, origin TEXT, dest TEXT);
    CREATE INDEX IF NOT EXISTS ix_ferries ON ferries(ile, day, dep);");
    try { $db->exec('ALTER TABLE communes ADD COLUMN nom_br TEXT'); } catch (Throwable $e) {}
    return $db;
}

function bzh_norm(string $s): string { return str_replace(['saint-', 'sainte-'], ['st-', 'ste-'], slugify(str_replace(['œ', 'Œ'], ['oe', 'Oe'], $s), 120)); }
function bzh_clean(?string $s): string { return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8')))); }
function bzh_ucfirst(string $s): string { $s = trim($s); return $s === '' ? '' : mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1); }
function bzh_title(string $s): string
{
    $s = mb_convert_case(mb_strtolower(trim($s)), MB_CASE_TITLE, 'UTF-8');
    return preg_replace_callback('/\b(De|Du|Des|La|Le|Les|L|D|Et|Sur|Sous|En|Au|Aux)\b/u', fn($m) => mb_strtolower($m[1]), bzh_ucfirst($s)) ?: $s;
}
function bzh_stop_name(string $s): string { return bzh_title(trim(preg_replace('/^gare maritime (de |du |d\')?/i', '', $s))); }
function bzh_route_islands(string $txt): array
{
    $t = str_replace('-', ' ', slugify($txt, 300)); $o = [];
    foreach (BZH_ILES as $slug => [, , $re]) if (preg_match($re, $t)) $o[] = $slug;
    return $o;
}

// Classement DATAtourisme -> type de fiche du site.
function bzh_dt_kind(array $c): array
{
    $has = fn(...$k) => (bool)array_intersect($k, $c);
    if ($has('Event', 'EntertainmentAndEvent')) {
        foreach ([['Concert', 'Concert'], ['MusicEvent', 'Concert'], ['Festival', 'Festival'], ['Exhibition', 'Exposition'], ['ExhibitionEvent', 'Exposition'], ['GarageSale', 'Vide-greniers, brocante'], ['BricABrac', 'Vide-greniers, brocante'],
            ['Market', 'Marché'], ['SaleEvent', 'Marché, foire'], ['FairOrShow', 'Foire, salon'], ['SportsCompetition', 'Compétition sportive'], ['SportsEvent', 'Sport'], ['ChildrensEvent', 'Jeune public'], ['ShowEvent', 'Spectacle'],
            ['TheaterEvent', 'Spectacle'], ['CircusPlace', 'Spectacle'], ['Conference', 'Conférence'], ['Traineeship', 'Stage, atelier'], ['Practice', 'Stage, atelier'], ['Visit', 'Visite guidée'], ['OpenDay', 'Portes ouvertes'],
            ['Game', 'Jeu, animation'], ['SocialEvent', 'Fête, animation'], ['CulturalEvent', 'Sortie culturelle']] as [$k, $l]) if ($has($k)) return ['evenement', $l];
        return ['evenement', 'Sortie'];
    }
    if ($has('Accommodation', 'LodgingBusiness')) {
        foreach ([['CamperVanArea', 'Aire de camping-car'], ['CampingAndCaravanning', 'Camping'], ['Camping', 'Camping'], ['Hotel', 'Hôtel'], ['HotelTrade', 'Hôtel'], ['BedAndBreakfast', "Chambre d'hôtes"],
            ['Guesthouse', "Chambre d'hôtes"], ['HolidayResort', 'Village vacances'], ['CollectiveAccommodation', 'Hébergement collectif'], ['GroupLodging', 'Gîte de groupe'], ['RentalAccommodation', 'Location de vacances']] as [$k, $l]) if ($has($k)) return ['hebergement', $l];
        return ['hebergement', 'Hébergement'];
    }
    if ($has('FoodEstablishment')) return ['restaurant', 'Restaurant'];
    if ($has('TouristInformationCenter', 'LocalTouristOffice')) return ['office', 'Office de tourisme'];
    if ($has('Tour', 'OrderedList')) return ['rando', $has('Rambling') ? 'Randonnée pédestre' : 'Circuit, balade'];
    foreach ([['Museum', 'Musée'], ['InterpretationCentre', "Centre d'interprétation"], ['Castle', 'Château'], ['ReligiousSite', 'Édifice religieux'], ['Church', 'Édifice religieux'], ['Lighthouse', 'Phare'],
        ['NaturalHeritage', 'Site naturel'], ['Landform', 'Site naturel'], ['ParkAndGarden', 'Parc et jardin'], ['Park', 'Parc et jardin'], ['ThemePark', 'Parc de loisirs'], ['AmusementPark', 'Parc de loisirs'],
        ['TeachingFarm', 'Ferme pédagogique'], ['TastingProvider', 'Producteur, dégustation'], ['Tasting', 'Producteur, dégustation'], ['Market', 'Marché'], ['Marina', 'Port de plaisance'], ['SightseeingBoat', 'Promenade en bateau'],
        ['SwimmingPool', 'Piscine'], ['GolfCourse', 'Golf'], ['MovieTheater', 'Cinéma'], ['Cinema', 'Cinéma'], ['Library', 'Médiathèque'], ['Casino', 'Casino'], ['Spa', 'Spa, bien-être'],
        ['CulturalSite', 'Site culturel'], ['SportsAndLeisurePlace', 'Sports et loisirs']] as [$k, $l]) if ($has($k)) return ['lieu', $l];
    if ($has('ActivityProvider', 'LeisureSportActivityProvider', 'Product')) return ['activite', 'Activité, prestataire'];
    if ($has('PlaceOfInterest')) return ['lieu', 'Lieu à découvrir'];
    return ['activite', 'Autre'];
}

// ---------- Helpers de rendu ----------

function bzh_dept_by_slug(string $s): ?string { foreach (BZH_DEPTS as $c => [, $sl]) if ($sl === $s) return (string)$c; return null; }
function bzh_dn(string $d): string { return BZH_DEPTS[$d][0] ?? $d; }
function bzh_in_dept(string $d): string { return ['22' => "dans les Côtes-d'Armor", '29' => 'dans le Finistère', '35' => "en Ille-et-Vilaine", '56' => 'dans le Morbihan'][$d] ?? ''; }
function bzh_du_dept(string $d): string { return ['22' => "des Côtes-d'Armor", '29' => 'du Finistère', '35' => "d'Ille-et-Vilaine", '56' => 'du Morbihan'][$d] ?? ''; }
function bzh_a(string $nom): string
{
    if (str_starts_with($nom, 'Le ')) return 'au ' . substr($nom, 3);
    if (str_starts_with($nom, 'Les ')) return 'aux ' . substr($nom, 4);
    return 'à ' . $nom;
}
function bzh_de(string $nom): string
{
    if (preg_match('/^Le /u', $nom)) return 'du ' . mb_substr($nom, 3);
    if (preg_match('/^Les /u', $nom)) return 'des ' . mb_substr($nom, 4);
    return preg_match('/^[AEIOUYÉÈÎÏÔHaeiouyéèîh]/u', $nom) ? "d'" . $nom : 'de ' . $nom;
}
function bzh_data(array $r): array { return json_decode((string)($r['data'] ?? '{}'), true) ?: []; }
function bzh_item_url(array $r): string { return '/' . BZH_KINDS[$r['kind']][0] . '/' . $r['slug'] . '/'; }
function bzh_com_url(array $c): string { return '/' . BZH_DEPTS[$c['dept']][1] . '/' . $c['slug'] . '/'; }
function bzh_nf(int $n): string { return number_format($n, 0, ',', "\u{202F}"); }
function bzh_plural(int $n, string $one, string $many): string { return bzh_nf($n) . ' ' . ($n > 1 ? $many : $one); }
function bzh_today(): string { return date('Y-m-d'); }
function bzh_date(string $d, bool $wd = true): string
{
    $t = strtotime($d); if (!$t) return $d;
    $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w', $t)];
    $m = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][(int)date('n', $t)];
    $dn = (int)date('j', $t);
    return ($wd ? $j . ' ' : '') . ($dn === 1 ? '1er' : $dn) . ' ' . $m . ' ' . date('Y', $t);
}
function bzh_period(?string $a, ?string $b): string
{
    if (!$a) return '';
    if (!$b || $b === $a) return 'le ' . bzh_date($a);
    return 'du ' . bzh_date($a, false) . ' au ' . bzh_date($b, false);
}
function bzh_weekend(): array
{
    $w = (int)date('N');
    $sat = $w === 6 ? bzh_today() : ($w === 7 ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d', strtotime('next saturday')));
    return [$sat, date('Y-m-d', strtotime("$sat +1 day"))];
}
function bzh_commune(string $insee): ?array { $st = bzh_db()->prepare('SELECT * FROM communes WHERE insee=?'); $st->execute([$insee]); return $st->fetch() ?: null; }
function bzh_canon(string $path): string { return 'https://' . BZH_HOST . $path; }

function bzh_crumbs(array $site, array $items): array
{
    $html = '<nav class="crumbs" aria-label="Fil d\'Ariane"><a href="/">Bretagne</a>';
    foreach ($items as [$n, $u]) $html .= ' › ' . ($u ? '<a href="' . h($u) . '">' . h($n) . '</a>' : h($n));
    return [$html . '</nav>', breadcrumbs($site, array_values(array_filter($items, fn($x) => $x[1])))];
}

function bzh_css(): string
{
    return '<style>.bz-hero{background:linear-gradient(135deg,#0a5c8f,#0d7a8a);color:#fff;border-radius:14px;padding:30px 28px;margin:22px 0}.bz-hero h1{margin:0 0 .3em;font-size:2.1rem;line-height:1.2}.bz-hero p{margin:0;font-size:1.08rem;opacity:.95}'
        . '.bz-sec{margin:34px 0}.bz-sec h2{font-size:1.45rem;margin:0 0 12px}.bz-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;padding:0;list-style:none;margin:12px 0}'
        . '.bz-list li{border:1px solid var(--b);border-radius:10px;padding:10px 14px;background:#fff;line-height:1.4}.bz-list li a{font-weight:600;text-decoration:none}.bz-list li small{display:block;color:var(--m);font-size:.82rem;margin-top:3px}'
        . '.bz-tags{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0;padding:0;list-style:none}.bz-tags a{border:1px solid var(--b);border-radius:20px;padding:4px 12px;text-decoration:none;font-size:.9rem;background:#fff}'
        . '.bz-facts{width:100%;display:table}.bz-facts th{width:34%;font-weight:600}.bz-q{display:inline-block;padding:2px 10px;border-radius:12px;font-weight:700;font-size:.85rem}.q-excellent{background:#d9f2e3;color:#11643a}.q-bon{background:#e3f0fb;color:#0b4f86}.q-suffisant{background:#fff3d6;color:#7a5600}.q-insuffisant{background:#fde2e1;color:#8c1d18}'
        . '.bz-map{width:100%;height:320px;border:0;border-radius:12px;margin:12px 0;background:var(--s)}.bz-ans{font-size:1.08rem;background:var(--s);border-left:4px solid var(--c);padding:14px 18px;border-radius:0 10px 10px 0;margin:16px 0}'
        . '.bz-day{margin:22px 0 6px;font-size:1.1rem;color:var(--c);text-transform:capitalize}.bz-ferry td,.bz-ferry th{font-size:.92rem}.bz-src{color:var(--m);font-size:.82rem;margin-top:30px;border-top:1px solid var(--b);padding-top:12px}.bz-past{background:#fde2e1;border-radius:10px;padding:10px 14px;color:#8c1d18}</style>';
}

function bzh_page(array $site, array $m, string $body, array $schema = []): string
{
    $m += ['schema' => []];
    $m['schema'] = array_merge($m['schema'], $schema);
    if (!empty($m['path'])) $m['canonical'] = bzh_canon($m['path']);
    // encart GetYourGuide (partenaire), hors pages retirées
    if (function_exists('gyg_box') && empty($m['noads']) && ($gq = (string)setting('gyg_q:' . $site['host'], ''))) $body .= gyg_box($gq, 'bretagne-' . (explode('/', trim((string)($m['path'] ?? '/'), '/'))[0] ?: 'accueil'), 'Visites et activités en Bretagne');
    return layout($site, $m, bzh_css() . $body);
}

function bzh_li(array $r, string $extra = ''): string
{
    $d = bzh_data($r);
    $sm = str_replace([';', ' ,'], [', ', ','], $extra !== '' ? $extra : trim(($r['sub'] ? $r['sub'] : '') . (!empty($r['cnom']) ? ' · ' . $r['cnom'] : ''), ' ·'));
    $name = h($r['name']);
    $a = $r['page'] ? '<a href="' . bzh_item_url($r) . '">' . $name . '</a>' : (!empty($d['web'][0]) ? '<a href="' . h($d['web'][0]) . '" rel="nofollow noopener" target="_blank">' . $name . '</a>' : '<strong>' . $name . '</strong>');
    return '<li>' . $a . ($sm !== '' ? '<small>' . h($sm) . '</small>' : '') . '</li>';
}
function bzh_ul(array $rows, ?callable $extra = null): string { return $rows ? '<ul class="bz-list">' . implode('', array_map(fn($r) => bzh_li($r, $extra ? $extra($r) : ''), $rows)) . '</ul>' : ''; }

function bzh_q_badge(string $q): string { return '<span class="bz-q q-' . h(slugify($q)) . '">' . h($q) . '</span>'; }

function bzh_items(string $where, array $args, string $order = 'rank DESC, name', int $limit = 12, int $offset = 0): array
{
    $st = bzh_db()->prepare("SELECT i.*, c.nom cnom, c.slug cslug, c.dept FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset");
    $st->execute($args);
    return $st->fetchAll();
}
function bzh_count(string $where, array $args): int { $st = bzh_db()->prepare("SELECT COUNT(*) FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $where"); $st->execute($args); return (int)$st->fetchColumn(); }

function bzh_near(float $lat, float $lon, string $kind, string $notId, int $n = 6, float $km = 15): array
{
    $dl = $km / 111; $dlo = $km / 74;
    $st = bzh_db()->prepare("SELECT i.*, c.nom cnom, c.dept, ((i.lat-?)*(i.lat-?)+((i.lon-?)*0.67)*((i.lon-?)*0.67)) dd FROM items i LEFT JOIN communes c ON c.insee=i.insee
        WHERE i.kind=? AND i.page=1 AND i.id<>? AND i.lat BETWEEN ? AND ? AND i.lon BETWEEN ? AND ?" . ($kind === 'evenement' ? ' AND i.d2>=?' : '') . " ORDER BY dd LIMIT $n");
    $a = [$lat, $lat, $lon, $lon, $kind, $notId, $lat - $dl, $lat + $dl, $lon - $dlo, $lon + $dlo];
    if ($kind === 'evenement') $a[] = bzh_today();
    $st->execute($a);
    return $st->fetchAll();
}
function bzh_dist(float $km): string { return $km < 0.1 ? 'à moins de 100 m' : ($km < 10 ? str_replace('.', ',', (string)round($km, 1)) : (string)round($km)) . ' km'; }
function bzh_km(float $a, float $b, float $c, float $d): float { return 6371 * 2 * asin(sqrt(sin(deg2rad($c - $a) / 2) ** 2 + cos(deg2rad($a)) * cos(deg2rad($c)) * sin(deg2rad($d - $b) / 2) ** 2)); }

function bzh_map(?float $lat, ?float $lon, string $title, float $z = 0.012): string
{
    if (!$lat || !$lon) return '';
    $bb = ($lon - $z * 1.5) . ',' . ($lat - $z) . ',' . ($lon + $z * 1.5) . ',' . ($lat + $z);
    return '<iframe class="bz-map" loading="lazy" title="' . h('Carte : ' . $title) . '" src="https://www.openstreetmap.org/export/embed.html?bbox=' . h($bb) . '&amp;layer=mapnik&amp;marker=' . h($lat . ',' . $lon) . '"></iframe>'
        . '<p class="disc"><a href="https://www.openstreetmap.org/?mlat=' . h((string)$lat) . '&amp;mlon=' . h((string)$lon) . '#map=15/' . h((string)$lat) . '/' . h((string)$lon) . '" rel="nofollow noopener" target="_blank">Agrandir la carte</a> · <a href="https://www.google.com/maps/dir/?api=1&amp;destination=' . h($lat . ',' . $lon) . '" rel="nofollow noopener" target="_blank">Itinéraire</a> · Carte © contributeurs OpenStreetMap</p>';
}

function bzh_faq(array $qa): array
{
    if (!$qa) return ['', []];
    $html = '<section class="bz-sec faq"><h2>Questions fréquentes</h2>';
    $ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
    foreach ($qa as [$q, $a]) {
        $html .= '<details><summary>' . h($q) . '</summary><p>' . h($a) . '</p></details>';
        $ld['mainEntity'][] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    return [$html . '</section>', [$ld]];
}

function bzh_sources(string ...$s): string
{
    $map = ['dt' => 'DATAtourisme (offices de tourisme et Comité régional du tourisme de Bretagne), Licence Ouverte Etalab',
        'merimee' => 'base Mérimée, ministère de la Culture (liste des immeubles protégés au titre des monuments historiques), Licence Ouverte',
        'inventaire' => 'Inventaire du patrimoine culturel, Région Bretagne (patrimoine.bzh), Licence Ouverte',
        'baignade' => 'contrôle sanitaire des eaux de baignade, ministère de la Santé et ARS (classement selon la directive 2006/7/CE), Licence Ouverte',
        'festivals' => 'cartographie nationale des festivals, ministère de la Culture, Licence Ouverte',
        'ferries' => 'horaires théoriques BreizhGo (Région Bretagne, réseau Korrigo), ODbL',
        'communes' => 'Insee et API Découpage administratif (geo.api.gouv.fr)'];
    $o = array_map(fn($k) => $map[$k] ?? $k, array_unique($s));
    return '<p class="bz-src">Sources : ' . h(implode(' ; ', $o)) . '. Informations publiques mises à jour automatiquement : vérifiez dates, horaires et conditions d\'accès auprès de l\'organisateur ou de l\'office de tourisme avant de vous déplacer.</p>';
}

function bzh_pager(string $base, int $page, int $pages): string
{
    if ($pages <= 1) return '';
    $o = '<nav class="pag">';
    if ($page > 1) $o .= '<a href="' . $base . ($page > 2 ? 'page/' . ($page - 1) . '/' : '') . '">‹ Précédent</a>';
    $o .= '<span>Page ' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) $o .= '<a href="' . $base . 'page/' . ($page + 1) . '/">Suivant ›</a>';
    return $o . '</nav>';
}

// ---------- Routage ----------

// Renvoie le HTML, '' (404), null (redirection envoyée) ou false (pas une page du module).
function bzh_route(array $site, string $path)
{
    if ($path === '/') return bzh_home($site);
    if ($path === '/llms.txt') { bzh_llms($site); exit; }
    if ($path === '/iles/') return bzh_iles($site);
    if (preg_match('#^/iles/([a-z0-9\-]+)/$#', $path, $m)) return isset(BZH_ILES[$m[1]]) ? bzh_ile($site, $m[1]) : '';
    if ($path === '/agenda/ce-week-end/') return bzh_agenda($site, null, 1, true);
    if (preg_match('#^/agenda/([a-z\-]+)/(?:page/(\d+)/)?$#', $path, $m) && isset(BZH_SEASONS[$m[1]])) return bzh_season($site, $m[1], max(1, (int)($m[2] ?? 1)));
    if ($path === '/classements/') return bzh_rankings($site);
    if (preg_match('#^/classements/([a-z\-]+)/$#', $path, $m)) return isset(BZH_RANKS[$m[1]]) ? bzh_ranking($site, $m[1]) : '';
    if ($path === '/br/') return bzh_br_index($site);
    if (preg_match('#^/br/([a-z\-]+)/([a-z0-9\-]+)/$#', $path, $m) && ($d = bzh_dept_by_slug($m[1]))) { $st = bzh_db()->prepare('SELECT * FROM communes WHERE dept=? AND slug=?'); $st->execute([$d, $m[2]]); return ($c = $st->fetch()) ? bzh_br_page($site, $c) : ''; }
    if (preg_match('#^/agenda/([a-z\-]+)/(?:page/(\d+)/)?$#', $path, $m) && isset(BZH_EV_THEMES[$m[1]])) return bzh_agenda($site, null, max(1, (int)($m[2] ?? 1)), false, $m[1]);
    foreach (BZH_KINDS as $k => [$pi, $pl]) {
        if (preg_match('#^/' . $pl . '/(?:([a-z0-9\-]+)/)?(?:page/(\d+)/)?$#', $path, $m)) {
            $d = !empty($m[1]) ? bzh_dept_by_slug($m[1]) : null;
            if (!empty($m[1]) && !$d) return '';
            $pg = max(1, (int)($m[2] ?? 1));
            return $k === 'evenement' ? bzh_agenda($site, $d, $pg, false) : bzh_list($site, $k, $d, $pg);
        }
        if (preg_match('#^/' . $pi . '/([a-z0-9\-]+)/$#', $path, $m)) return bzh_item($site, $k, $m[1]);
    }
    if (preg_match('#^/([a-z\-]+)/$#', $path, $m) && ($d = bzh_dept_by_slug($m[1]))) return bzh_dept($site, $d);
    if (preg_match('#^/([a-z\-]+)/([a-z0-9\-]+)/$#', $path, $m) && ($d = bzh_dept_by_slug($m[1]))) {
        $st = bzh_db()->prepare('SELECT * FROM communes WHERE dept=? AND slug=?'); $st->execute([$d, $m[2]]);
        return ($c = $st->fetch()) ? bzh_commune_page($site, $c) : '';
    }
    return false;
}

// ---------- Accueil ----------

function bzh_home(array $site): string
{
    $db = bzh_db(); $t = bzh_today();
    [$sat, $sun] = bzh_weekend();
    $nb = fn($k) => (int)$db->query('SELECT COUNT(*) FROM items WHERE page=1 AND kind=' . $db->quote($k) . ($k === 'evenement' ? ' AND d2>=' . $db->quote($t) : ''))->fetchColumn();
    $ncom = (int)$db->query('SELECT COUNT(*) FROM communes')->fetchColumn();
    $exc = (int)$db->query("SELECT COUNT(*) FROM items WHERE kind='plage' AND json_extract(data,'$.qualite')='Excellent'")->fetchColumn();
    $body = '<div class="bz-hero"><h1>Que faire en Bretagne ?</h1><p>Lieux à visiter, plages, îles, patrimoine et sorties dans les ' . bzh_nf($ncom) . ' communes des Côtes-d\'Armor, du Finistère, d\'Ille-et-Vilaine et du Morbihan, à partir des données publiques des offices de tourisme, du ministère de la Culture et de la Région.</p></div>';
    $body .= '<div class="kpi"><div><b>' . bzh_nf($nb('evenement')) . '</b>sorties à venir</div><div><b>' . bzh_nf($nb('monument')) . '</b>monuments historiques</div><div><b>' . bzh_nf($nb('plage')) . '</b>plages et baignades surveillées</div><div><b>' . bzh_nf($nb('lieu')) . '</b>lieux à visiter</div><div><b>' . bzh_nf($nb('rando')) . '</b>randonnées et circuits</div></div>';
    $body .= '<p class="bz-ans">En Bretagne, on peut ce week-end choisir parmi ' . bzh_nf(bzh_count('i.kind=? AND i.d1<=? AND i.d2>=?', ['evenement', $sun, $sat])) . ' sorties référencées par les offices de tourisme, se baigner sur l\'une des ' . bzh_nf($exc) . ' plages classées « excellentes » au dernier contrôle sanitaire, ou rejoindre une des ' . count(BZH_ILES) . ' îles desservies par les bateaux BreizhGo.</p>';
    $body .= '<section class="bz-sec"><h2>Explorer par département</h2><div class="grid">';
    foreach (BZH_DEPTS as $d => [$dn, $ds]) {
        $n = (int)$db->query('SELECT COUNT(*) FROM communes WHERE dept=' . $db->quote((string)$d))->fetchColumn();
        $body .= '<div class="card"><div class="in"><span class="kick">' . h((string)$d) . '</span><h3><a href="/' . $ds . '/">Que faire ' . h(bzh_in_dept((string)$d)) . '</a></h3><p>' . bzh_nf($n) . ' communes, ' . bzh_nf(bzh_count('c.dept=? AND i.page=1', [(string)$d])) . ' fiches</p></div></div>';
    }
    $body .= '</div></section>';
    $we = bzh_items('i.kind=? AND i.d1<=? AND i.d2>=?', ['evenement', $sun, $sat], 'i.rank DESC, i.d1', 12);
    if ($we) $body .= '<section class="bz-sec"><h2>Sorties ce week-end en Bretagne</h2>' . bzh_ul($we, fn($r) => $r['sub'] . ' · ' . $r['cnom']) . '<p><a href="/agenda/ce-week-end/">Toutes les sorties du week-end ›</a> · <a href="/agenda/">Agenda complet</a></p></section>';
    $body .= '<section class="bz-sec"><h2>Les îles de Bretagne</h2><ul class="bz-tags">' . implode('', array_map(fn($s, $v) => '<li><a href="/iles/' . $s . '/">' . h($v[0]) . '</a></li>', array_keys(BZH_ILES), BZH_ILES)) . '</ul><p><a href="/iles/">Horaires des bateaux et informations ›</a></p></section>';
    $mh = bzh_items("i.kind='monument' AND i.page=1", [], 'i.rank DESC', 12);
    if ($mh) $body .= '<section class="bz-sec"><h2>Monuments historiques à découvrir</h2>' . bzh_ul($mh, fn($r) => trim($r['sub'] . ' · ' . $r['cnom'], ' ·')) . '<p><a href="/monuments-historiques/">Tous les monuments historiques ›</a></p></section>';
    $pl = bzh_items("i.kind='plage' AND json_extract(i.data,'$.eau')='mer'", [], 'i.rank DESC, RANDOM()', 12);
    if ($pl) $body .= '<section class="bz-sec"><h2>Plages surveillées</h2>' . bzh_ul($pl, fn($r) => 'Eau ' . mb_strtolower((string)(bzh_data($r)['qualite'] ?? '')) . ' · ' . $r['cnom']) . '<p><a href="/plages/">Toutes les plages et la qualité de l\'eau ›</a></p></section>';
    $big = $db->query("SELECT * FROM communes ORDER BY pop DESC LIMIT 24")->fetchAll();
    $body .= '<section class="bz-sec"><h2>Grandes villes et communes</h2><ul class="bz-tags">' . implode('', array_map(fn($c) => '<li><a href="' . bzh_com_url($c) . '">' . h($c['nom']) . '</a></li>', $big)) . '</ul></section>';
    $body .= '<section class="bz-sec"><h2>Toutes les rubriques</h2><ul class="bz-tags">';
    foreach (BZH_KINDS as $k => [, $pl, $lab]) $body .= '<li><a href="/' . $pl . '/">' . h($lab) . '</a></li>';
    $body .= '<li><a href="/iles/">Îles et bateaux</a></li></ul></section>';
    [$posts] = list_posts($site, '', [], 1, 6);
    if ($posts) $body .= '<section class="bz-sec"><h2>Nos guides</h2><div class="grid">' . implode('', array_map('card', $posts)) . '</div></section>';
    $body .= bzh_sources('dt', 'merimee', 'baignade', 'ferries', 'communes');
    return bzh_page($site, ['title' => 'Que faire en Bretagne ? Lieux, plages, îles et sorties | ' . $site['name'], 'path' => '/',
        'desc' => 'Guide de la Bretagne commune par commune : sorties du week-end, plages et qualité de l\'eau, îles et horaires des bateaux, monuments historiques et patrimoine.'], $body,
        [['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $site['name'], 'url' => bzh_canon('/'), 'potentialAction' => ['@type' => 'SearchAction', 'target' => bzh_canon('/recherche/?q={q}'), 'query-input' => 'required name=q']],
         ['@context' => 'https://schema.org', '@type' => 'TouristDestination', 'name' => 'Bretagne', 'url' => bzh_canon('/')]]);
}

// ---------- Département ----------

function bzh_dept(array $site, string $d): string
{
    $db = bzh_db(); $dn = bzh_dn($d); $ds = BZH_DEPTS[$d][1]; $t = bzh_today();
    [$cr, $bc] = bzh_crumbs($site, [[$dn, '']]);
    $coms = $db->prepare('SELECT * FROM communes WHERE dept=? ORDER BY nom'); $coms->execute([$d]); $coms = $coms->fetchAll();
    $pop = array_sum(array_column($coms, 'pop'));
    $cnt = fn($k) => bzh_count('c.dept=? AND i.kind=? AND i.page=1' . ($k === 'evenement' ? ' AND i.d2>=?' : ''), $k === 'evenement' ? [$d, $k, $t] : [$d, $k]);
    $body = $cr . '<div class="bz-hero"><h1>Que faire ' . h(bzh_in_dept($d)) . ' ?</h1><p>' . bzh_nf(count($coms)) . ' communes, ' . bzh_nf($pop) . ' habitants : lieux à visiter, plages, patrimoine et sorties.</p></div>';
    $body .= '<div class="kpi">';
    foreach (['evenement' => 'sorties à venir', 'lieu' => 'lieux à visiter', 'monument' => 'monuments historiques', 'plage' => 'plages et baignades', 'rando' => 'randonnées', 'festival' => 'festivals'] as $k => $l) {
        $n = $cnt($k); if ($n) $body .= '<div><b>' . bzh_nf($n) . '</b><a href="/' . BZH_KINDS[$k][1] . '/' . $ds . '/">' . h($l) . '</a></div>';
    }
    $body .= '</div>';
    $top = $db->prepare('SELECT * FROM communes WHERE dept=? ORDER BY n_items DESC LIMIT 12'); $top->execute([$d]); $top = $top->fetchAll();
    $body .= '<p class="bz-ans">Les communes les plus riches en lieux de visite et en événements ' . h(bzh_du_dept($d)) . ' sont ' . h(implode(', ', array_column(array_slice($top, 0, 5), 'nom'))) . '.</p>';
    $lx = bzh_items('c.dept=? AND i.kind=? AND i.page=1', [$d, 'lieu'], 'i.rank DESC', 12);
    if ($lx) $body .= '<section class="bz-sec"><h2>Lieux à visiter ' . h(bzh_in_dept($d)) . '</h2>' . bzh_ul($lx) . '<p><a href="/lieux-a-visiter/' . $ds . '/">Tous les lieux ›</a></p></section>';
    $ev = bzh_items('c.dept=? AND i.kind=? AND i.d2>=?', [$d, 'evenement', $t], 'i.d1, i.rank DESC', 12);
    if ($ev) $body .= '<section class="bz-sec"><h2>Prochaines sorties</h2>' . bzh_ul($ev, fn($r) => bzh_period($r['d1'], $r['d2']) . ' · ' . $r['cnom']) . '<p><a href="/agenda/' . $ds . '/">Agenda ' . h(bzh_du_dept($d)) . ' ›</a></p></section>';
    $mh = bzh_items('c.dept=? AND i.kind=? AND i.page=1', [$d, 'monument'], 'i.rank DESC', 12);
    if ($mh) $body .= '<section class="bz-sec"><h2>Monuments historiques</h2>' . bzh_ul($mh) . '<p><a href="/monuments-historiques/' . $ds . '/">Tous les monuments ›</a></p></section>';
    $body .= '<section class="bz-sec"><h2>Toutes les communes ' . h(bzh_du_dept($d)) . '</h2><ul class="bz-tags">' . implode('', array_map(fn($c) => '<li><a href="' . bzh_com_url($c) . '">' . h($c['nom']) . '</a></li>', $coms)) . '</ul></section>';
    $body .= bzh_sources('dt', 'merimee', 'baignade', 'communes');
    return bzh_page($site, ['title' => 'Que faire ' . bzh_in_dept($d) . ' ? Visites, plages, sorties | ' . $site['name'], 'path' => "/$ds/",
        'desc' => 'Que faire ' . bzh_in_dept($d) . ' : ' . bzh_nf(count($coms)) . ' communes, lieux à visiter, monuments historiques, plages et agenda des sorties.'], $body, [$bc]);
}

// ---------- Commune ----------

function bzh_commune_stats(string $insee): array
{
    $st = bzh_db()->prepare("SELECT kind, COUNT(*) n, SUM(page) p FROM items WHERE insee=? AND (kind<>'evenement' OR d2>=?) GROUP BY kind");
    $st->execute([$insee, bzh_today()]);
    $o = []; foreach ($st as $r) $o[$r['kind']] = (int)$r['n'];
    return $o;
}

function bzh_commune_indexable(array $c, array $s): bool
{
    $score = ($s['lieu'] ?? 0) + ($s['monument'] ?? 0) * 2 + ($s['plage'] ?? 0) * 2 + ($s['evenement'] ?? 0) + ($s['patrimoine'] ?? 0) + ($s['rando'] ?? 0) + ($s['festival'] ?? 0) * 2 + ($s['hebergement'] ?? 0) / 3;
    return $score >= 3 || (int)$c['pop'] >= 2000;
}

function bzh_commune_page(array $site, array $c): string
{
    $db = bzh_db(); $t = bzh_today(); $i = $c['insee']; $nom = $c['nom']; $d = $c['dept']; $ds = BZH_DEPTS[$d][1];
    $s = bzh_commune_stats($i);
    $index = bzh_commune_indexable($c, $s);
    [$cr, $bc] = bzh_crumbs($site, [[bzh_dn($d), "/$ds/"], [$nom, '']]);
    $ile = null; foreach (BZH_ILES as $sl => $v) if (bzh_norm($v[1]) === bzh_norm($nom)) $ile = $sl;
    $q = fn($k, $n = 12, $order = 'i.rank DESC, i.name') => bzh_items('i.insee=? AND i.kind=?' . ($k === 'evenement' ? ' AND i.d2>=?' : ''), $k === 'evenement' ? [$i, $k, $t] : [$i, $k], $order, $n);

    // Réponse directe
    $parts = [];
    foreach (['lieu' => ['lieu à visiter', 'lieux à visiter'], 'monument' => ['monument historique', 'monuments historiques'], 'plage' => ['site de baignade surveillé', 'sites de baignade surveillés'],
        'rando' => ['randonnée ou circuit', 'randonnées et circuits'], 'patrimoine' => ['élément du patrimoine inventorié', 'éléments du patrimoine inventoriés'], 'evenement' => ['sortie à venir', 'sorties à venir'], 'festival' => ['festival', 'festivals']] as $k => [$a, $b])
        if (!empty($s[$k])) $parts[] = bzh_plural($s[$k], $a, $b);
    $intro = $nom . ' est une commune ' . bzh_du_dept($d) . ($c['pop'] ? ' de ' . bzh_nf((int)$c['pop']) . ' habitants' : '') . ($c['epci'] ? ', membre de ' . $c['epci'] : '') . '.' . (!empty($c['nom_br']) ? ' Son nom en breton est ' . $c['nom_br'] . '.' : '');
    $ans = $parts ? 'À ' . $nom . ', Découverte Bretagne recense ' . implode(', ', array_slice($parts, 0, -1)) . (count($parts) > 1 ? ' et ' : '') . end($parts) . '.' : 'Peu d\'informations touristiques sont publiées pour ' . $nom . ' : les communes voisines ci-dessous offrent davantage d\'idées de visites et de sorties.';
    $body = $cr . '<div class="bz-hero"><h1>Que faire ' . h(bzh_a($nom)) . ' ?</h1><p>' . h($intro) . '</p></div><p class="bz-ans">' . h($ans) . '</p>';
    $brok = bzh_br_ok($c);
    if ($brok) $body .= '<p class="disc"><a href="' . bzh_br_url($c) . '" hreflang="br" lang="br">Lenn ar bajenn-mañ e brezhoneg ›</a></p>';
    if (!empty($c['intro'])) $body .= '<div>' . sanitize_html((string)$c['intro']) . '</div>';
    $body .= '<div class="kpi">' . ($c['pop'] ? '<div><b>' . bzh_nf((int)$c['pop']) . '</b>habitants</div>' : '') . ($c['surface'] ? '<div><b>' . str_replace('.', ',', (string)$c['surface']) . ' km²</b>superficie</div>' : '')
        . '<div><b>' . h((string)$c['cps'] ? explode(',', (string)$c['cps'])[0] : $i) . '</b>code postal</div><div><b>' . h(bzh_dn($d)) . '</b>département</div></div>';
    $faq = [];

    if ($ile) {
        $f = $db->prepare('SELECT * FROM ferries WHERE ile=? AND day=? ORDER BY dep'); $f->execute([$ile, $t]); $fr = $f->fetchAll();
        $body .= '<section class="bz-sec"><h2>Bateaux pour ' . h(BZH_ILES[$ile][0]) . '</h2><p>' . ($fr ? bzh_plural(count($fr), 'traversée est programmée', 'traversées sont programmées') . " aujourd'hui sur les lignes BreizhGo." : "Aucune traversée BreizhGo n'est programmée aujourd'hui dans les horaires publiés.") . ' <a href="/iles/' . $ile . '/">Horaires des 7 prochains jours ›</a></p></section>';
    }
    $ev = $q('evenement', 10, 'i.d1, i.rank DESC');
    if ($ev) {
        $body .= '<section class="bz-sec"><h2>Agenda : sorties et événements ' . h(bzh_a($nom)) . '</h2>' . bzh_ul($ev, fn($r) => $r['sub'] . ' · ' . bzh_period($r['d1'], $r['d2'])) . '</section>';
        $faq[] = ['Que faire ' . bzh_a($nom) . ' ce week-end ?', 'Le prochain événement référencé est « ' . $ev[0]['name'] . ' » (' . mb_strtolower($ev[0]['sub']) . ', ' . bzh_period($ev[0]['d1'], $ev[0]['d2']) . '). ' . bzh_plural($s['evenement'] ?? count($ev), 'sortie est', 'sorties sont') . ' annoncées à ' . $nom . ' dans les prochaines semaines.'];
    }
    $lx = $q('lieu');
    if ($lx) {
        $body .= '<section class="bz-sec"><h2>Lieux à visiter ' . h(bzh_a($nom)) . '</h2>' . bzh_ul($lx) . '</section>';
        $faq[] = ['Que visiter ' . bzh_a($nom) . ' ?', 'Parmi les lieux à visiter ' . bzh_a($nom) . ' : ' . implode(', ', array_map(fn($r) => $r['name'] . ' (' . mb_strtolower($r['sub']) . ')', array_slice($lx, 0, 5))) . '.'];
    }
    $mh = $q('monument', 30);
    if ($mh) {
        $cl = count(array_filter($mh, fn($r) => str_contains((string)(bzh_data($r)['type_protection'] ?? ''), 'classé')));
        $body .= '<section class="bz-sec"><h2>Monuments historiques ' . h(bzh_de($nom)) . '</h2><p>' . h(bzh_plural($s['monument'], 'édifice est protégé', 'édifices sont protégés')) . ' au titre des monuments historiques' . ($cl ? ', dont ' . bzh_nf($cl) . ' classé' . ($cl > 1 ? 's' : '') : '') . '.</p>' . bzh_ul($mh, fn($r) => trim($r['sub'] . ' · ' . (bzh_data($r)['siecle'] ?? ''), ' ·')) . '</section>';
        $faq[] = ['Quels monuments historiques voir ' . bzh_a($nom) . ' ?', $nom . ' compte ' . bzh_plural($s['monument'], 'monument historique', 'monuments historiques') . ', notamment ' . implode(', ', array_column(array_slice($mh, 0, 4), 'name')) . '.'];
    }
    $pl = $q('plage', 30);
    if ($pl) {
        $ex = array_filter($pl, fn($r) => (bzh_data($r)['qualite'] ?? '') === 'Excellent');
        $body .= '<section class="bz-sec"><h2>Plages et baignade ' . h(bzh_a($nom)) . '</h2>' . bzh_ul($pl, fn($r) => 'Qualité de l\'eau : ' . mb_strtolower((string)(bzh_data($r)['qualite'] ?? 'non communiquée'))) . '</section>';
        $faq[] = ['Où se baigner ' . bzh_a($nom) . ' ?', $nom . ' compte ' . bzh_plural(count($pl), 'site de baignade surveillé', 'sites de baignade surveillés') . ($ex ? ', dont ' . bzh_nf(count($ex)) . ' classé' . (count($ex) > 1 ? 's' : '') . ' « excellent » : ' . implode(', ', array_column(array_slice(array_values($ex), 0, 4), 'name')) : '') . '.'];
    }
    $rd = $q('rando');
    if ($rd) $body .= '<section class="bz-sec"><h2>Randonnées et balades</h2>' . bzh_ul($rd) . '</section>';
    $fs = $q('festival');
    if ($fs) $body .= '<section class="bz-sec"><h2>Festivals ' . h(bzh_a($nom)) . '</h2>' . bzh_ul($fs, fn($r) => trim($r['sub'] . ' · ' . (bzh_data($r)['periode'] ?? ''), ' ·')) . '</section>';
    $pt = $q('patrimoine', 18);
    if ($pt) {
        $dn = $db->prepare("SELECT sub, COUNT(*) n FROM items WHERE insee=? AND kind='patrimoine' AND sub<>'' GROUP BY sub ORDER BY n DESC LIMIT 8"); $dn->execute([$i]);
        $types = array_map(fn($r) => mb_strtolower($r['sub']) . ' (' . $r['n'] . ')', $dn->fetchAll());
        $body .= '<section class="bz-sec"><h2>Patrimoine ' . h(bzh_de($nom)) . '</h2><p>L\'Inventaire du patrimoine de la Région Bretagne décrit ' . bzh_plural($s['patrimoine'], 'élément', 'éléments') . ' à ' . h($nom) . ($types ? ' : ' . h(implode(', ', $types)) : '') . '.</p>' . bzh_ul($pt, fn($r) => trim($r['sub'] . ' · ' . (bzh_data($r)['datation'] ?? ''), ' ·')) . '</section>';
    }
    $hb = $q('hebergement', 15, 'i.rank DESC, i.name');
    if ($hb) {
        $body .= '<section class="bz-sec"><h2>Où dormir ' . h(bzh_a($nom)) . ' ?</h2>' . bzh_ul($hb, fn($r) => trim($r['sub'] . ' · ' . implode(', ', bzh_data($r)['classements'] ?? []), ' ·')) . '</section>';
        $types = array_count_values(array_column($hb, 'sub'));
        $faq[] = ['Où dormir ' . bzh_a($nom) . ' ?', 'Les offices de tourisme référencent ' . bzh_plural($s['hebergement'], 'hébergement', 'hébergements') . ' ' . bzh_a($nom) . ' : ' . implode(', ', array_map(fn($k, $v) => mb_strtolower($k) . ' (' . $v . ')', array_keys($types), $types)) . '.'];
    }
    $rs = $q('restaurant', 12);
    if ($rs) $body .= '<section class="bz-sec"><h2>Où manger ' . h(bzh_a($nom)) . ' ?</h2>' . bzh_ul($rs, fn($r) => $r['sub']) . '</section>';
    $ac = $q('activite', 12);
    if ($ac) $body .= '<section class="bz-sec"><h2>Activités et prestataires</h2>' . bzh_ul($ac) . '</section>';
    $of = $q('office', 3);
    if ($of) $body .= '<section class="bz-sec"><h2>Office de tourisme</h2>' . bzh_ul($of, fn($r) => trim((string)(bzh_data($r)['adresse'] ?? ''))) . '</section>';
    if ($c['lat']) {
        $nb = $db->prepare('SELECT *, ((lat-?)*(lat-?)+((lon-?)*0.67)*((lon-?)*0.67)) dd FROM communes WHERE insee<>? ORDER BY dd LIMIT 10');
        $nb->execute([$c['lat'], $c['lat'], $c['lon'], $c['lon'], $i]);
        $nbs = $nb->fetchAll();
        $body .= '<section class="bz-sec"><h2>Que faire autour ' . h(bzh_de($nom)) . ' ?</h2><ul class="bz-list">' . implode('', array_map(fn($n) => '<li><a href="' . bzh_com_url($n) . '">' . h($n['nom']) . '</a><small>' . round(bzh_km((float)$c['lat'], (float)$c['lon'], (float)$n['lat'], (float)$n['lon'])) . ' km · ' . bzh_plural((int)$n['n_items'], 'fiche', 'fiches') . '</small></li>', $nbs)) . '</ul></section>';
        $body .= bzh_map((float)$c['lat'], (float)$c['lon'], $nom, 0.03);
    }
    [$fh, $fl] = bzh_faq($faq);
    $body .= $fh . bzh_sources('dt', 'merimee', 'inventaire', 'baignade', 'communes');
    $title = 'Que faire ' . bzh_a($nom) . ' (' . $d . ') ? Visites, sorties, plages | ' . $site['name'];
    return bzh_page($site, ['title' => $title, 'path' => bzh_com_url($c), 'head' => $brok ? bzh_br_alt($c) : '', 'robots' => $index ? null : 'noindex,follow',
        'desc' => mb_substr($ans . ' Agenda, lieux à visiter, monuments et plages ' . bzh_a($nom) . ' (' . bzh_dn($d) . ').', 0, 158)], $body,
        array_merge([$bc, ['@context' => 'https://schema.org', '@type' => 'City', 'name' => $nom, 'url' => bzh_canon(bzh_com_url($c)),
            'containedInPlace' => ['@type' => 'AdministrativeArea', 'name' => bzh_dn($d)], 'geo' => $c['lat'] ? ['@type' => 'GeoCoordinates', 'latitude' => $c['lat'], 'longitude' => $c['lon']] : null]], $fl));
}

// ---------- Fiche ----------

function bzh_item(array $site, string $kind, string $slug)
{
    $st = bzh_db()->prepare('SELECT i.*, c.nom cnom, c.slug cslug, c.dept, c.pop cpop FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE i.kind=? AND i.slug=?');
    $st->execute([$kind, $slug]);
    $r = $st->fetch();
    if (!$r) {
        $g = bzh_db()->prepare('SELECT 1 FROM gone WHERE kind=? AND slug=?'); $g->execute([$kind, $slug]);
        if ($g->fetchColumn()) { http_response_code(410); return bzh_page($site, ['title' => 'Page retirée | ' . $site['name'], 'robots' => 'noindex,follow', 'noads' => true], '<h1 style="margin-top:28px">Cette fiche a été retirée</h1><p>L\'événement est terminé ou n\'est plus publié par sa source. <a href="/agenda/">Voir l\'agenda des sorties en Bretagne</a>.</p>'); }
        return '';
    }
    if (!$r['page']) return '';
    $d = bzh_data($r); $K = BZH_KINDS[$kind]; $t = bzh_today();
    $c = ['nom' => $r['cnom'], 'slug' => $r['cslug'], 'dept' => $r['dept']];
    $curl = bzh_com_url($c);
    [$cr, $bc] = bzh_crumbs($site, [[bzh_dn($r['dept']), '/' . BZH_DEPTS[$r['dept']][1] . '/'], [$r['cnom'], $curl], [$r['name'], '']]);
    $past = $kind === 'evenement' && $r['d2'] < $t;
    $facts = []; $text = ''; $lead = ''; $schema = ['@context' => 'https://schema.org', '@type' => $K[4], 'name' => $r['name'], 'url' => bzh_canon(bzh_item_url($r))];
    $place = ['@type' => 'Place', 'name' => trim(($d['adresse'] ?? '') . ' ' . $r['cnom']), 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $d['adresse'] ?? '', 'postalCode' => $d['cp'] ?? '', 'addressLocality' => $r['cnom'], 'addressRegion' => 'Bretagne', 'addressCountry' => 'FR']];
    if ($r['lat']) { $place['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $r['lat'], 'longitude' => $r['lon']]; }
    $src = [$d['src'] ?? 'dt'];
    $sub = mb_strtolower((string)$r['sub']);

    switch ($kind) {
        case 'evenement':
            $lead = '« ' . $r['name'] . ' » (' . $sub . ') a lieu ' . bzh_period($r['d1'], $r['d2']) . ' ' . bzh_a($r['cnom']) . ' (' . bzh_dn($r['dept']) . ')' . (!empty($d['adresse']) ? ', ' . $d['adresse'] : '') . '.';
            $facts = ['Type' => $r['sub'], 'Prochaine date' => bzh_period($r['d1'], $r['d2']), 'Lieu' => trim(($d['adresse'] ?? '') . ', ' . ($d['cp'] ?? '') . ' ' . $r['cnom'], ', '), 'Organisation / source' => $d['auteur'] ?? ''];
            if (count($d['periodes'] ?? []) > 1) $text .= '<h2>Toutes les dates</h2><ul>' . implode('', array_map(fn($p) => '<li>' . h(bzh_ucfirst(bzh_period($p[0], $p[1]))) . '</li>', array_slice($d['periodes'], 0, 20))) . '</ul>';
            $schema += ['startDate' => $r['d1'], 'endDate' => $r['d2'], 'eventStatus' => 'https://schema.org/EventScheduled', 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode', 'location' => $place, 'organizer' => ['@type' => 'Organization', 'name' => $d['auteur'] ?? $r['cnom']]];
            break;
        case 'monument':
            $lead = $r['name'] . ' (' . ($sub ?: 'édifice') . (!empty($d['siecle']) ? ', ' . $d['siecle'] : '') . ') se trouve ' . bzh_a($r['cnom']) . ' (' . bzh_dn($r['dept']) . ') et bénéficie d\'une protection au titre des monuments historiques' . (!empty($d['protection']) ? ' : ' . str_replace(' ; ', ', ', $d['protection']) : '') . '.';
            $facts = ['Type d\'édifice' => $r['sub'], 'Époque de construction' => $d['siecle'] ?? '', 'Datation' => $d['datation'] ?? '', 'Protection' => $d['protection'] ?? '', 'Statut' => $d['statut'] ?? '', 'Matériaux' => str_replace(';', ', ', $d['materiaux'] ?? ''),
                'Couverture' => str_replace(';', ', ', $d['couverture'] ?? ''), 'Architecte, auteur' => $d['auteur'] ?? '', 'Vocable' => $d['vocable'] ?? '', 'Adresse' => trim(($d['adresse'] ?? '') . ' ' . ($d['lieudit'] ?? '')), 'Référence Mérimée' => $d['ref'] ?? ''];
            if (!empty($d['historique'])) $text .= '<h2>Historique</h2><p>' . h($d['historique']) . '</p>';
            if (!empty($d['description'])) $text .= '<h2>Description</h2><p>' . h($d['description']) . '</p>';
            if (!empty($d['remarquable'])) $text .= '<p><strong>Éléments remarquables :</strong> ' . h(str_replace(';', ', ', $d['remarquable'])) . '.</p>';
            if (!empty($d['ref'])) $text .= '<p><a href="https://pop.culture.gouv.fr/notice/merimee/' . h($d['ref']) . '" rel="noopener" target="_blank">Notice complète sur la Plateforme ouverte du patrimoine (POP)</a></p>';
            if (($d['statut'] ?? '') && str_contains(mb_strtolower($d['statut']), 'privée')) $text .= '<p class="disc">Propriété privée : l\'édifice ne se visite pas forcément. Respectez la propriété et renseignez-vous auprès de l\'office de tourisme.</p>';
            $schema += ['address' => $place['address']] + (isset($place['geo']) ? ['geo' => $place['geo']] : []);
            break;
        case 'patrimoine':
            $lead = $r['name'] . ' (' . ($sub ?: 'patrimoine') . (!empty($d['datation']) ? ', ' . $d['datation'] : '') . ') fait partie du patrimoine ' . bzh_de($r['cnom']) . ' (' . bzh_dn($r['dept']) . ') décrit par l\'Inventaire du patrimoine de la Région Bretagne.';
            $facts = ['Dénomination' => $d['denomination'] ?? '', 'Datation' => $d['datation'] ?? '', 'Lieu-dit' => $d['lieudit'] ?? '', 'Matériaux' => str_replace(' ;', ',', $d['materiaux'] ?? ''), 'Statut' => $d['statut'] ?? '', 'Domaine' => mb_strtolower($d['domaine'] ?? ''), 'Référence' => $d['ref'] ?? ''];
            if (!empty($d['historique'])) $text .= '<h2>Historique</h2><p>' . h($d['historique']) . '</p>';
            if (!empty($d['description'])) $text .= '<h2>Description</h2><p>' . h($d['description']) . '</p>';
            if (!empty($d['dossier'])) $text .= '<p><a href="' . h($d['dossier']) . '" rel="noopener" target="_blank">Dossier d\'inventaire complet et photographies sur patrimoine.bzh</a></p>';
            if (str_contains(mb_strtolower($d['statut'] ?? ''), 'privée')) $text .= '<p class="disc">Propriété privée : à observer depuis l\'espace public.</p>';
            $schema += ['address' => $place['address']] + (isset($place['geo']) ? ['geo' => $place['geo']] : []);
            break;
        case 'plage':
            $qual = (string)($d['qualite'] ?? '');
            $lead = 'La qualité de l\'eau du site de baignade « ' . $r['name'] . ' » ' . bzh_a($r['cnom']) . ' est classée ' . ($qual ? '« ' . mb_strtolower($qual) . ' »' : 'non communiquée') . ' selon le classement officiel issu du contrôle sanitaire (eau de ' . ($d['eau'] ?? 'mer') . ').';
            $facts = ['Qualité de l\'eau' => $qual, 'Type d\'eau' => $d['eau'] ?? '', 'Commune' => $r['cnom'], 'Intercommunalité' => $d['epci'] ?? '', 'Nom officiel du site' => $d['nom_officiel'] ?? ''];
            $text .= '<h2>Comprendre le classement</h2><p>Le classement « excellent », « bon », « suffisant » ou « insuffisant » est calculé sur les analyses bactériologiques des quatre dernières saisons, selon la directive européenne 2006/7/CE. Pendant la saison, les résultats de chaque prélèvement sont affichés en mairie et sur le site officiel baignades.sante.gouv.fr ; une baignade peut être interdite temporairement après un épisode pluvieux.</p>'
                . '<p><a href="https://baignades.sante.gouv.fr/" rel="noopener" target="_blank">Résultats officiels des derniers prélèvements</a></p>';
            $schema += ['address' => $place['address']] + (isset($place['geo']) ? ['geo' => $place['geo']] : []);
            break;
        case 'festival':
            $lead = $r['name'] . ' est un festival ' . ($sub ? '(' . $sub . ') ' : '') . 'organisé ' . bzh_a($r['cnom']) . ' (' . bzh_dn($r['dept']) . ')' . (!empty($d['creation']) ? ' depuis ' . $d['creation'] : '') . (!empty($d['periode']) ? ', en ' . mb_strtolower(preg_replace('/\s*\(.*\)$/', '', $d['periode'])) : '') . '.';
            $facts = ['Discipline' => $r['sub'], 'Genres' => $d['genres'] ?? '', 'Période' => $d['periode'] ?? '', 'Année de création' => $d['creation'] ?? '', 'Lieu' => trim(($d['adresse'] ?? '') . ' ' . $r['cnom']), 'Envergure' => $d['envergure'] ?? ''];
            $schema['@type'] = 'Festival'; $schema += ['location' => $place];
            break;
        default: // lieu, rando
            $lead = $r['name'] . ' est ' . ($kind === 'rando' ? 'un itinéraire de ' . ($sub === 'randonnée pédestre' ? 'randonnée pédestre' : 'balade') : ($sub ? 'un lieu de type « ' . $sub . ' »' : 'un lieu à découvrir')) . ' ' . bzh_a($r['cnom']) . ' (' . bzh_dn($r['dept']) . ').' . (!empty($d['adresse']) ? ' Adresse : ' . $d['adresse'] . '.' : '');
            $facts = ['Catégorie' => $r['sub'], 'Adresse' => trim(($d['adresse'] ?? '') . ', ' . ($d['cp'] ?? '') . ' ' . $r['cnom'], ', '), 'Classement, label' => implode(', ', $d['classements'] ?? []), 'Fiche publiée par' => $d['auteur'] ?? '', 'Mise à jour' => !empty($d['maj']) ? bzh_date($d['maj'], false) : ''];
            $schema += ['address' => $place['address']] + (isset($place['geo']) ? ['geo' => $place['geo']] : []);
    }
    if (!empty($d['web'])) { $facts['Site internet'] = '§' . $d['web'][0]; $schema['sameAs'] = $d['web'][0]; }
    $facts = array_filter($facts, fn($v) => trim((string)$v) !== '');
    $body = $cr . '<article><h1>' . h($r['name']) . '</h1>';
    if ($past) $body .= '<p class="bz-past">Cet événement est terminé. Consultez <a href="/agenda/' . BZH_DEPTS[$r['dept']][1] . '/">les prochaines sorties ' . h(bzh_du_dept($r['dept'])) . '</a>.</p>';
    $body .= '<p class="bz-ans">' . h($lead) . '</p>';
    if ($kind === 'plage' && !empty($d['qualite'])) $body .= '<p>Classement : ' . bzh_q_badge((string)$d['qualite']) . '</p>';
    $body .= '<table class="bz-facts">' . implode('', array_map(fn($k, $v) => '<tr><th>' . h($k) . '</th><td>' . (str_starts_with((string)$v, '§') ? '<a href="' . h(substr($v, 2)) . '" rel="nofollow noopener" target="_blank">' . h(preg_replace('~^https?://(www\.)?|/$~', '', substr($v, 2))) . '</a>' : h($v)) . '</td></tr>', array_keys($facts), $facts)) . '</table>';
    $body .= $text . ad_unit($site, 'in_article');
    $body .= bzh_map($r['lat'] ? (float)$r['lat'] : null, $r['lon'] ? (float)$r['lon'] : null, $r['name']);
    // À proximité
    if ($r['lat']) {
        $near = '';
        foreach (['monument' => 'Monuments historiques à proximité', 'lieu' => 'Lieux à visiter à proximité', 'plage' => 'Plages à proximité', 'evenement' => 'Sorties à venir à proximité', 'patrimoine' => 'Patrimoine à proximité'] as $k => $lab) {
            $nr = bzh_near((float)$r['lat'], (float)$r['lon'], $k, $r['id'], 6, $k === 'evenement' ? 12 : 10);
            if ($nr) $near .= '<h3>' . h($lab) . '</h3>' . bzh_ul($nr, fn($x) => trim(($k === 'evenement' ? bzh_period($x['d1'], $x['d2']) : $x['sub']) . ' · ' . $x['cnom'] . ' · ' . bzh_dist(bzh_km((float)$r['lat'], (float)$r['lon'], (float)$x['lat'], (float)$x['lon'])), ' ·'));
        }
        if ($near) $body .= '<section class="bz-sec"><h2>Autour de ' . h($r['name']) . '</h2>' . $near . '</section>';
    }
    $body .= '<p>Voir aussi : <a href="' . $curl . '">que faire ' . h(bzh_a($r['cnom'])) . '</a> · <a href="/' . $K[1] . '/' . BZH_DEPTS[$r['dept']][1] . '/">' . h(mb_strtolower($K[2])) . ' ' . h(bzh_du_dept($r['dept'])) . '</a></p>';
    $body .= bzh_sources(...$src) . '</article>';
    $title = match ($kind) {
        'evenement' => $r['name'] . ' – ' . $r['cnom'] . ', ' . bzh_period($r['d1'], $r['d2']),
        'plage' => $r['name'] . ' (' . $r['cnom'] . ') : qualité de l\'eau, baignade',
        'monument' => $r['name'] . ', monument historique ' . bzh_a($r['cnom']),
        'festival' => $r['name'] . ' – festival ' . bzh_a($r['cnom']),
        default => $r['name'] . ' – ' . $r['cnom'] . ' (' . $r['dept'] . ')',
    };
    return bzh_page($site, ['title' => $title . ' | ' . $site['name'], 'path' => bzh_item_url($r), 'robots' => $past ? 'noindex,follow' : null, 'og_type' => 'article',
        'desc' => mb_substr($lead, 0, 158)], $body, [$bc, $schema]);
}

// ---------- Listes ----------

function bzh_list(array $site, string $kind, ?string $d, int $page): string
{
    $K = BZH_KINDS[$kind];
    $w = 'i.kind=? AND i.page=1' . ($d ? ' AND c.dept=?' : ''); $a = $d ? [$kind, $d] : [$kind];
    $n = bzh_count($w, $a); $pages = max(1, (int)ceil($n / BZH_PER));
    if ($page > $pages) return '';
    $rows = bzh_items($w, $a, $kind === 'plage' ? 'c.nom, i.name' : 'i.rank DESC, i.name', BZH_PER, ($page - 1) * BZH_PER);
    $where = $d ? ' ' . bzh_in_dept($d) : ' en Bretagne';
    $base = '/' . $K[1] . '/' . ($d ? BZH_DEPTS[$d][1] . '/' : '');
    [$cr, $bc] = bzh_crumbs($site, $d ? [[$K[2], '/' . $K[1] . '/'], [bzh_dn($d), '']] : [[$K[2], '']]);
    $body = $cr . '<h1 style="margin-top:20px">' . h($K[2] . $where) . ($page > 1 ? ' – page ' . $page : '') . '</h1>';
    $body .= '<p class="bz-ans">' . h(bzh_plural($n, $K[3], mb_strtolower($K[2])) . ' référencés' . $where) . ', classés ' . ($kind === 'plage' ? 'par commune' : 'par intérêt') . '. Chaque fiche donne l\'adresse, la carte et ce qu\'il y a à voir autour.</p>';
    $body .= '<ul class="bz-tags">' . implode('', array_map(fn($c, $v) => '<li><a href="/' . $K[1] . '/' . $v[1] . '/"' . ($c == $d ? ' aria-current="page"' : '') . '>' . h($v[0]) . '</a></li>', array_keys(BZH_DEPTS), BZH_DEPTS)) . '</ul>';
    if ($kind === 'plage' && $page === 1) {
        $st = bzh_db()->prepare("SELECT json_extract(i.data,'$.qualite') q, COUNT(*) n FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $w GROUP BY q ORDER BY n DESC"); $st->execute($a);
        $body .= '<p>Répartition du classement : ' . implode(' · ', array_map(fn($r) => bzh_q_badge((string)$r['q']) . ' ' . bzh_nf((int)$r['n']), $st->fetchAll())) . '</p>';
    }
    $body .= bzh_ul($rows, fn($r) => trim(($kind === 'plage' ? 'Eau ' . mb_strtolower((string)(bzh_data($r)['qualite'] ?? '')) : $r['sub']) . ' · ' . $r['cnom'], ' ·'));
    $body .= bzh_pager($base, $page, $pages) . bzh_sources(match ($kind) { 'monument' => 'merimee', 'patrimoine' => 'inventaire', 'plage' => 'baignade', 'festival' => 'festivals', default => 'dt' });
    return bzh_page($site, ['title' => $K[2] . $where . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'], 'path' => $base . ($page > 1 ? 'page/' . $page . '/' : ''),
        'desc' => bzh_plural($n, $K[3], mb_strtolower($K[2])) . $where . ' : adresse, carte, informations pratiques et idées de visite à proximité.'], $body, [$bc]);
}

function bzh_agenda(array $site, ?string $d, int $page, bool $weekend, ?string $theme = null): string
{
    $t = bzh_today();
    [$sat, $sun] = bzh_weekend();
    $w = 'i.kind=? AND i.d2>=?'; $a = ['evenement', $weekend ? $sat : $t];
    if ($theme) { $subs = BZH_EV_THEMES[$theme][1]; $w .= ' AND i.sub IN (' . implode(',', array_fill(0, count($subs), '?')) . ')'; $a = array_merge($a, $subs); }
    if ($weekend) { $w .= ' AND i.d1<=?'; $a[] = $sun; }
    if ($d) { $w .= ' AND c.dept=?'; $a[] = $d; }
    $n = bzh_count($w, $a); $pages = max(1, (int)ceil($n / 60));
    if ($page > $pages) return '';
    $rows = bzh_items($w, $a, 'MAX(i.d1, ' . bzh_db()->quote($weekend ? $sat : $t) . '), i.rank DESC', 60, ($page - 1) * 60);
    $where = $d ? ' ' . bzh_in_dept($d) : ' en Bretagne';
    $base = $weekend ? '/agenda/ce-week-end/' : ($theme ? '/agenda/' . $theme . '/' : '/agenda/' . ($d ? BZH_DEPTS[$d][1] . '/' : ''));
    [$cr, $bc] = bzh_crumbs($site, $weekend ? [['Agenda', '/agenda/'], ['Ce week-end', '']] : ($theme ? [['Agenda', '/agenda/'], [BZH_EV_THEMES[$theme][0], '']] : ($d ? [['Agenda', '/agenda/'], [bzh_dn($d), '']] : [['Agenda', '']])));
    $h1 = $weekend ? 'Que faire ce week-end en Bretagne ?' : ($theme ? BZH_EV_THEMES[$theme][0] . ' en Bretagne' : 'Agenda des sorties' . $where);
    $body = $cr . '<h1 style="margin-top:20px">' . h($h1) . ($page > 1 ? ' – page ' . $page : '') . '</h1>';
    $body .= '<p class="bz-ans">' . h(($weekend ? 'Le week-end du ' . bzh_date($sat, false) . ', ' : '') . bzh_plural($n, 'événement est annoncé', 'événements sont annoncés') . $where . ($weekend ? '' : ' à partir d\'aujourd\'hui') . ' : concerts, fest-noz, marchés, expositions, visites guidées, sports et animations jeune public.') . '</p>';
    $body .= '<ul class="bz-tags"><li><a href="/agenda/ce-week-end/">Ce week-end</a></li>' . implode('', array_map(fn($c, $v) => '<li><a href="/agenda/' . $v[1] . '/">' . h($v[0]) . '</a></li>', array_keys(BZH_DEPTS), BZH_DEPTS)) . '</ul>';
    $body .= '<ul class="bz-tags">' . implode('', array_map(fn($s, $v) => '<li><a href="/agenda/' . $s . '/"' . ($s === $theme ? ' aria-current="page"' : '') . '>' . h($v[0]) . '</a></li>', array_keys(BZH_EV_THEMES), BZH_EV_THEMES)) . '</ul>';
    $cur = '';
    $grp = [];
    foreach ($rows as $r) { $k = max($r['d1'], $weekend ? $sat : $t); $grp[$k][] = $r; }
    foreach ($grp as $day => $rs) $body .= '<h2 class="bz-day">' . h(bzh_date($day)) . '</h2>' . bzh_ul($rs, fn($r) => $r['sub'] . ' · ' . $r['cnom'] . ($r['d2'] > $r['d1'] ? ' · jusqu\'au ' . bzh_date($r['d2'], false) : ''));
    if (!$rows) $body .= '<p>Aucun événement publié pour cette période.</p>';
    $body .= bzh_pager($base, $page, $pages) . bzh_sources('dt');
    return bzh_page($site, ['title' => $h1 . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'], 'path' => $base . ($page > 1 ? 'page/' . $page . '/' : ''),
        'desc' => bzh_plural($n, 'sortie', 'sorties') . $where . ($weekend ? ' ce week-end' : '') . ' : concerts, fest-noz, marchés, expositions, visites et animations, mis à jour chaque jour.'], $body, [$bc]);
}

// ---------- Îles ----------

function bzh_ile_commune(string $slug): ?array
{
    $st = bzh_db()->query('SELECT * FROM communes');
    foreach ($st as $c) if (bzh_norm($c['nom']) === bzh_norm(BZH_ILES[$slug][1])) return $c;
    return null;
}

function bzh_iles(array $site): string
{
    [$cr, $bc] = bzh_crumbs($site, [['Îles', '']]);
    $db = bzh_db(); $t = bzh_today();
    $body = $cr . '<h1 style="margin-top:20px">Îles de Bretagne : bateaux, horaires et que faire</h1><p class="bz-ans">' . count(BZH_ILES) . ' îles bretonnes sont desservies toute l\'année par les liaisons maritimes régionales BreizhGo. Retrouvez les horaires théoriques des prochains jours et les idées de visite sur chaque île.</p><ul class="bz-list">';
    foreach (BZH_ILES as $s => $v) {
        $st = $db->prepare('SELECT COUNT(*) FROM ferries WHERE ile=? AND day=?'); $st->execute([$s, $t]);
        $body .= '<li><a href="/iles/' . $s . '/">' . h($v[0]) . '</a><small>' . bzh_plural((int)$st->fetchColumn(), "traversée aujourd'hui", "traversées aujourd'hui") . '</small></li>';
    }
    $body .= '</ul>' . bzh_sources('ferries');
    return bzh_page($site, ['title' => 'Îles de Bretagne : horaires des bateaux et que faire | ' . $site['name'], 'path' => '/iles/', 'desc' => 'Horaires des bateaux BreizhGo vers Belle-Île, Groix, Houat, Hœdic, Arz, Bréhat, Ouessant, Molène et Sein, et idées de visite sur chaque île.'], $body, [$bc]);
}

function bzh_ile(array $site, string $slug): string
{
    $v = BZH_ILES[$slug]; $db = bzh_db(); $t = bzh_today();
    $c = bzh_ile_commune($slug);
    [$cr, $bc] = bzh_crumbs($site, [['Îles', '/iles/'], [$v[0], '']]);
    $st = $db->prepare('SELECT * FROM ferries WHERE ile=? AND day BETWEEN ? AND ? ORDER BY day, dep'); $st->execute([$slug, $t, date('Y-m-d', strtotime('+6 day'))]);
    $rows = $st->fetchAll();
    $routes = array_values(array_unique(array_column($rows, 'route')));
    $body = $cr . '<h1 style="margin-top:20px">' . h($v[0]) . ' : horaires des bateaux et que faire</h1>';
    $today = array_filter($rows, fn($r) => $r['day'] === $t);
    $body .= '<p class="bz-ans">' . h($v[0]) . ' est desservie par ' . ($routes ? 'la ou les lignes BreizhGo ' . implode(', ', $routes) : 'les liaisons maritimes régionales BreizhGo') . '. ' . ($today ? bzh_plural(count($today), 'traversée est programmée', 'traversées sont programmées') . " aujourd'hui, la première à " . reset($today)['dep'] . '.' : ($rows ? "Aucune traversée n'est programmée aujourd'hui dans les horaires publiés." : '')) . '</p>';
    $grp = []; foreach ($rows as $r) $grp[$r['day']][] = $r;
    foreach ($grp as $day => $rs) {
        $body .= '<h2 class="bz-day">' . h(bzh_date($day)) . '</h2><table class="bz-ferry"><tr><th>Départ</th><th>De</th><th>Vers</th><th>Arrivée</th></tr>';
        foreach ($rs as $r) $body .= '<tr><td>' . h($r['dep']) . '</td><td>' . h($r['origin']) . '</td><td>' . h($r['dest']) . '</td><td>' . h($r['arr']) . '</td></tr>';
        $body .= '</table>';
    }
    if (!$rows) $body .= '<p>Les horaires de cette liaison ne sont pas publiés actuellement dans les données ouvertes de la Région. Consultez directement le site <a href="https://www.breizhgo.bzh/" rel="noopener" target="_blank">BreizhGo</a> ou la compagnie qui assure la traversée.</p>';
    $body .= '<p class="disc">Horaires théoriques publiés par la Région Bretagne : ils peuvent changer (météo, marées, affluence). Réservez et vérifiez auprès de la compagnie BreizhGo avant de partir.</p>';
    if ($c) {
        $lx = bzh_items("i.insee=? AND i.page=1 AND i.kind IN ('lieu','monument','plage','rando','patrimoine')", [$c['insee']], 'i.rank DESC', 25);
        $seen = []; $lx = array_slice(array_values(array_filter($lx, function ($r) use (&$seen) { $k = bzh_norm($r['name']); if (isset($seen[$k])) return false; return $seen[$k] = true; })), 0, 15);
        if ($lx) $body .= '<section class="bz-sec"><h2>Que faire sur ' . h($v[0]) . ' ?</h2>' . bzh_ul($lx) . '<p><a href="' . bzh_com_url($c) . '">Tout sur ' . h($c['nom']) . ' : agenda, hébergements, restaurants ›</a></p></section>';
        $body .= bzh_map($c['lat'] ? (float)$c['lat'] : null, $c['lon'] ? (float)$c['lon'] : null, $v[0], 0.05);
    }
    [$fh, $fl] = bzh_faq(array_filter([
        $routes ? ['Comment aller sur ' . $v[0] . ' ?', 'Les bateaux réguliers BreizhGo relient ' . $v[0] . ' au continent : ' . implode(', ', $routes) . '. Les horaires varient selon la saison et le jour.'] : null,
        $today ? ['À quelle heure part le premier bateau aujourd\'hui ?', 'Le premier départ programmé aujourd\'hui est à ' . reset($today)['dep'] . ' (' . reset($today)['origin'] . ' vers ' . reset($today)['dest'] . ').'] : null,
    ]));
    $body .= $fh . bzh_sources('ferries', 'dt');
    return bzh_page($site, ['title' => $v[0] . ' : horaires des bateaux, que faire | ' . $site['name'], 'path' => "/iles/$slug/",
        'desc' => 'Horaires des bateaux pour ' . $v[0] . ' sur 7 jours, lignes BreizhGo, et idées de visite sur l\'île.'], $body, array_merge([$bc], $fl));
}

// ---------- Classements annuels ----------

// slug => [titre, critère, unité au singulier, unité au pluriel, libellé court]
const BZH_RANKS = [
    'plages-eau-excellente' => ['Les communes bretonnes aux plages les mieux classées', 'sites de baignade classés « excellent » au contrôle sanitaire', 'plage à l\'eau excellente', 'plages à l\'eau excellente', 'Plages les mieux classées'],
    'communes-monuments-historiques' => ['Les communes bretonnes les plus riches en monuments historiques', 'édifices protégés au titre des monuments historiques', 'monument historique', 'monuments historiques', 'Communes aux monuments'],
    'communes-patrimoine' => ['Les communes bretonnes au patrimoine le plus riche', 'éléments décrits par l\'Inventaire du patrimoine de la Région', 'élément du patrimoine', 'éléments du patrimoine', 'Communes au patrimoine'],
    'communes-les-plus-animees' => ['Les communes bretonnes les plus animées', 'sorties et événements annoncés à partir d\'aujourd\'hui', 'sortie annoncée', 'sorties annoncées', 'Communes les plus animées'],
    'festivals-les-plus-anciens' => ['Les plus anciens festivals de Bretagne', 'année de création des festivals recensés par le ministère de la Culture', 'festival', 'festivals', 'Festivals les plus anciens'],
];

function bzh_rank_rows(string $slug): array
{
    $db = bzh_db(); $t = bzh_today();
    $byCom = function (string $where, array $a) use ($db) {
        $st = $db->prepare("SELECT c.*, COUNT(*) n FROM items i JOIN communes c ON c.insee=i.insee WHERE $where GROUP BY c.insee ORDER BY n DESC, c.pop DESC LIMIT 50");
        $st->execute($a); return $st->fetchAll();
    };
    switch ($slug) {
        case 'plages-eau-excellente': return $byCom("i.kind='plage' AND json_extract(i.data,'$.qualite')='Excellent'", []);
        case 'communes-monuments-historiques': return $byCom("i.kind='monument'", []);
        case 'communes-patrimoine': return $byCom("i.kind='patrimoine'", []);
        case 'communes-les-plus-animees': return $byCom("i.kind='evenement' AND i.d2>=?", [$t]);
        case 'festivals-les-plus-anciens':
            return $db->query("SELECT i.*, c.nom cnom, c.slug cslug, c.dept, CAST(json_extract(i.data,'$.creation') AS INTEGER) y FROM items i JOIN communes c ON c.insee=i.insee
                WHERE i.kind='festival' AND CAST(json_extract(i.data,'$.creation') AS INTEGER) BETWEEN 1800 AND 2100 ORDER BY y, i.name LIMIT 50")->fetchAll();
    }
    return [];
}

function bzh_rankings(array $site): string
{
    [$cr, $bc] = bzh_crumbs($site, [['Classements', '']]);
    $y = date('Y');
    $body = $cr . '<h1 style="margin-top:20px">Classements ' . $y . ' des communes de Bretagne</h1><p class="bz-ans">Ces classements sont établis chaque année à partir des données publiques officielles : contrôle sanitaire des eaux de baignade, base Mérimée des monuments historiques, Inventaire du patrimoine de la Région Bretagne, agenda des offices de tourisme et cartographie nationale des festivals.</p><ul class="bz-list">';
    foreach (BZH_RANKS as $s => $R) {
        $r = bzh_rank_rows($s);
        $top = $r ? ($s === 'festivals-les-plus-anciens' ? $r[0]['name'] . ' (' . $r[0]['y'] . ')' : $r[0]['nom'] . ' (' . bzh_nf((int)$r[0]['n']) . ')') : '';
        $body .= '<li><a href="/classements/' . $s . '/">' . h($R[0] . ' en ' . $y) . '</a>' . ($top ? '<small>N° 1 : ' . h($top) . '</small>' : '') . '</li>';
    }
    $body .= '</ul>' . bzh_sources('baignade', 'merimee', 'inventaire', 'dt', 'festivals');
    return bzh_page($site, ['title' => 'Classements ' . $y . ' des communes de Bretagne | ' . $site['name'], 'path' => '/classements/',
        'desc' => 'Classements ' . $y . ' des communes bretonnes : plages à l\'eau excellente, monuments historiques, patrimoine, animations et plus anciens festivals.'], $body, [$bc]);
}

function bzh_ranking(array $site, string $slug): string
{
    $R = BZH_RANKS[$slug]; $y = date('Y');
    $rows = bzh_rank_rows($slug);
    if (!$rows) return '';
    $fest = $slug === 'festivals-les-plus-anciens';
    [$cr, $bc] = bzh_crumbs($site, [['Classements', '/classements/'], [$R[4], '']]);
    $f = $rows[0];
    if ($fest) {
        $lead = 'Le plus ancien festival breton recensé est « ' . $f['name'] . ' » ' . bzh_a($f['cnom']) . ' (' . bzh_dn($f['dept']) . '), créé en ' . $f['y'] . '.';
    } else {
        $nx = array_slice($rows, 1, 2);
        $lead = $f['nom'] . ' (' . bzh_dn($f['dept']) . ') arrive en tête du classement ' . $y . ' avec ' . bzh_plural((int)$f['n'], $R[2], $R[3])
            . ($nx ? (($nx[0]['n'] == $f['n']) ? ', à égalité avec ' . $nx[0]['nom'] . (isset($nx[1]) ? ', devant ' . $nx[1]['nom'] . ' (' . bzh_nf((int)$nx[1]['n']) . ')' : '') : ', devant ' . implode(' et ', array_map(fn($r) => $r['nom'] . ' (' . bzh_nf((int)$r['n']) . ')', $nx))) : '') . '.';
    }
    $body = $cr . '<h1 style="margin-top:20px">' . h($R[0]) . ' en ' . $y . '</h1><p class="bz-ans">' . h($lead) . '</p>';
    if ($slug === 'plages-eau-excellente') {
        $st = bzh_db()->query("SELECT c.dept, COUNT(*) n, SUM(json_extract(i.data,'$.qualite')='Excellent') e FROM items i JOIN communes c ON c.insee=i.insee WHERE i.kind='plage' GROUP BY c.dept ORDER BY 1.0*e/n DESC");
        $body .= '<h2>Part des sites de baignade classés « excellent » par département</h2><table><tr><th>Département</th><th>Sites surveillés</th><th>Classés excellent</th><th>Part</th></tr>';
        foreach ($st as $r) $body .= '<tr><td><a href="/plages/' . BZH_DEPTS[$r['dept']][1] . '/">' . h(bzh_dn($r['dept'])) . '</a></td><td>' . bzh_nf((int)$r['n']) . '</td><td>' . bzh_nf((int)$r['e']) . '</td><td>' . round(100 * $r['e'] / max(1, $r['n'])) . ' %</td></tr>';
        $body .= '</table><h2>Classement des communes</h2>';
    }
    $ld = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'name' => $R[0] . ' en ' . $y, 'itemListElement' => []];
    if ($fest) {
        $body .= '<table><tr><th>Rang</th><th>Festival</th><th>Commune</th><th>Création</th><th>Discipline</th></tr>';
        foreach ($rows as $k => $r) {
            $body .= '<tr><td>' . ($k + 1) . '</td><td><a href="' . bzh_item_url($r) . '">' . h($r['name']) . '</a></td><td><a href="' . bzh_com_url(['dept' => $r['dept'], 'slug' => $r['cslug']]) . '">' . h($r['cnom']) . '</a></td><td>' . (int)$r['y'] . '</td><td>' . h($r['sub']) . '</td></tr>';
            $ld['itemListElement'][] = ['@type' => 'ListItem', 'position' => $k + 1, 'name' => $r['name'], 'url' => bzh_canon(bzh_item_url($r))];
        }
    } else {
        $body .= '<table><tr><th>Rang</th><th>Commune</th><th>Département</th><th>' . h(bzh_ucfirst($R[3])) . '</th></tr>';
        $rank = 0; $prev = null;
        foreach ($rows as $k => $r) {
            if ($r['n'] !== $prev) { $rank = $k + 1; $prev = $r['n']; }
            $body .= '<tr><td>' . $rank . '</td><td><a href="' . bzh_com_url($r) . '">' . h($r['nom']) . '</a></td><td>' . h(bzh_dn($r['dept'])) . '</td><td>' . bzh_nf((int)$r['n']) . '</td></tr>';
            $ld['itemListElement'][] = ['@type' => 'ListItem', 'position' => $k + 1, 'name' => $r['nom'], 'url' => bzh_canon(bzh_com_url($r))];
        }
    }
    $body .= '</table><p>Critère : ' . h($R[1]) . '. Classement recalculé automatiquement à chaque mise à jour des données.</p>';
    $body .= '<ul class="bz-tags">' . implode('', array_map(fn($s, $v) => $s === $slug ? '' : '<li><a href="/classements/' . $s . '/">' . h($v[4]) . '</a></li>', array_keys(BZH_RANKS), BZH_RANKS)) . '</ul>';
    $body .= bzh_sources(match ($slug) { 'plages-eau-excellente' => 'baignade', 'communes-monuments-historiques' => 'merimee', 'communes-patrimoine' => 'inventaire', 'festivals-les-plus-anciens' => 'festivals', default => 'dt' });
    return bzh_page($site, ['title' => $R[0] . ' : classement ' . $y . ' | ' . $site['name'], 'path' => '/classements/' . $slug . '/', 'desc' => mb_substr($lead . ' Le classement complet ' . $y . '.', 0, 158)], $body, [$bc, $ld]);
}

// ---------- Pages saisonnières ----------

// slug => [titre, libellé court, expression, début, fin, mots-clés]  (dates MM-JJ = chaque année, AAAA-MM-JJ = dates fixes)
const BZH_SEASONS = [
    'halloween' => ['Halloween en Bretagne', 'Halloween', 'les animations d\'Halloween', '10-10', '11-03', ['halloween', 'sorcier', 'sorcière', 'citrouille', 'frisson', 'fantôme', 'monstre']],
    'vacances-toussaint' => ['Que faire pendant les vacances de la Toussaint en Bretagne ?', 'Vacances de la Toussaint', 'les vacances de la Toussaint', '2026-10-17', '2026-11-02', []],
    'marches-de-noel' => ['Marchés et animations de Noël en Bretagne', 'Marchés de Noël', 'les marchés et animations de Noël', '11-14', '01-06', ['noël', 'noel', 'avent', 'saint-nicolas']],
    'vacances-noel' => ['Que faire pendant les vacances de Noël en Bretagne ?', 'Vacances de Noël', 'les vacances de Noël', '2026-12-19', '2027-01-04', []],
    'fest-noz' => ['Fest-noz et fest-deiz en Bretagne', 'Fest-noz', 'les fest-noz et fest-deiz', null, null, ['fest-noz', 'fest noz', 'festnoz', 'fest-deiz', 'fest deiz']],
    'pardons' => ['Pardons en Bretagne', 'Pardons', 'les pardons', null, null, ['pardon']],
];

function bzh_season_range(array $S): ?array
{
    if (!$S[3]) return null;
    if (strlen($S[3]) === 10) return [$S[3], $S[4]];
    $y = (int)date('Y'); $t = bzh_today(); $wrap = $S[4] < $S[3];
    $e0 = ($wrap ? $y : $y - 1) . '-' . $S[4];
    if ($t <= $e0) return [($y - 1) . '-' . $S[3], $e0];
    return ["$y-{$S[3]}", ($wrap ? $y + 1 : $y) . '-' . $S[4]];
}

function bzh_season_where(string $slug): array
{
    $S = BZH_SEASONS[$slug];
    $w = "i.kind='evenement' AND i.d2>=?"; $a = [bzh_today()];
    if ($r = bzh_season_range($S)) { $w .= ' AND i.d1<=? AND i.d2>=?'; $a[] = $r[1]; $a[] = $r[0]; }
    if ($S[5]) { $w .= ' AND (' . implode(' OR ', array_fill(0, count($S[5]), 'lower(i.name) LIKE ?')) . ')'; foreach ($S[5] as $k) $a[] = '%' . $k . '%'; }
    return [$w, $a];
}

function bzh_season_count(string $slug): int { try { [$w, $a] = bzh_season_where($slug); return bzh_count($w, $a); } catch (Throwable $e) { return 0; } }

function bzh_season(array $site, string $slug, int $page): string
{
    $S = BZH_SEASONS[$slug]; $t = bzh_today();
    [$w, $a] = bzh_season_where($slug);
    $n = bzh_count($w, $a); $pages = max(1, (int)ceil($n / 60));
    if ($page > $pages) return '';
    $r = bzh_season_range($S);
    $rows = bzh_items($w, $a, 'MAX(i.d1, ' . bzh_db()->quote($t) . '), i.rank DESC', 60, ($page - 1) * 60);
    $base = '/agenda/' . $slug . '/';
    [$cr, $bc] = bzh_crumbs($site, [['Agenda', '/agenda/'], [$S[1], '']]);
    $yr = $r ? substr($r[0], 0, 4) : '';
    $h1 = $S[0] . ($yr && !str_ends_with($S[0], '?') ? ' ' . $yr : '');
    $st = bzh_db()->prepare("SELECT COUNT(DISTINCT i.insee) FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $w"); $st->execute($a); $nc = (int)$st->fetchColumn();
    $per = $r ? ' (' . bzh_period($r[0], $r[1]) . ')' : '';
    $lead = $n ? bzh_ucfirst(bzh_plural($n, 'événement est annoncé', 'événements sont annoncés')) . ' en Bretagne pour ' . $S[2] . $per . ', dans ' . bzh_plural($nc, 'commune', 'communes') . '.'
        : 'Aucun événement n\'est encore annoncé pour ' . $S[2] . $per . ' en Bretagne. Les offices de tourisme publient leurs programmes au fil des semaines : cette page est mise à jour chaque jour.';
    $body = $cr . '<h1 style="margin-top:20px">' . h($h1) . ($page > 1 ? ' – page ' . $page : '') . '</h1><p class="bz-ans">' . h($lead) . '</p>';
    if ($n) {
        $st = bzh_db()->prepare("SELECT c.dept, COUNT(*) n FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $w GROUP BY c.dept ORDER BY n DESC"); $st->execute($a);
        $body .= '<p>Par département : ' . h(implode(' · ', array_map(fn($x) => bzh_dn((string)$x['dept']) . ' ' . bzh_nf((int)$x['n']), $st->fetchAll()))) . '</p>';
    }
    $body .= '<ul class="bz-tags">' . implode('', array_map(fn($s, $v) => '<li><a href="/agenda/' . $s . '/"' . ($s === $slug ? ' aria-current="page"' : '') . '>' . h($v[1]) . '</a></li>', array_keys(BZH_SEASONS), BZH_SEASONS)) . '</ul>';
    $grp = []; foreach ($rows as $x) $grp[max($x['d1'], $t)][] = $x;
    foreach ($grp as $day => $rs) $body .= '<h2 class="bz-day">' . h(bzh_date($day)) . '</h2>' . bzh_ul($rs, fn($x) => $x['sub'] . ' · ' . $x['cnom'] . ($x['d2'] > $x['d1'] ? ' · jusqu\'au ' . bzh_date($x['d2'], false) : ''));
    $faq = [];
    if ($n) {
        $st = bzh_db()->prepare("SELECT c.nom, COUNT(*) n FROM items i LEFT JOIN communes c ON c.insee=i.insee WHERE $w GROUP BY c.insee ORDER BY n DESC LIMIT 5"); $st->execute($a);
        $tops = $st->fetchAll();
        $faq[] = ['Où trouver ' . $S[2] . ' en Bretagne ?', 'Les communes qui proposent le plus d\'événements sont ' . implode(', ', array_map(fn($x) => $x['nom'] . ' (' . $x['n'] . ')', $tops)) . '.'];
        $faq[] = ['Combien d\'événements sont prévus ?', $lead];
    }
    [$fh, $fl] = bzh_faq($faq);
    $body .= bzh_pager($base, $page, $pages) . $fh . bzh_sources('dt');
    return bzh_page($site, ['title' => $h1 . ($page > 1 ? ' (page ' . $page . ')' : '') . ' | ' . $site['name'], 'path' => $base . ($page > 1 ? 'page/' . $page . '/' : ''),
        'robots' => $n ? null : 'noindex,follow', 'desc' => mb_substr($lead, 0, 158)], $body, array_merge([$bc], $fl));
}

// ---------- Version bretonne des pages communes ----------

const BZH_DEPTS_BR = ['22' => ['Aodoù-an-Arvor', 'en Aodoù-an-Arvor'], '29' => ['Penn-ar-Bed', 'e Penn-ar-Bed'], '35' => ['Il-ha-Gwilen', 'en Il-ha-Gwilen'], '56' => ['Mor-Bihan', 'er Mor-Bihan']];
const BZH_Q_BR = ['Excellent' => 'dispar', 'Bon' => 'mat', 'Suffisant' => "a-walc'h", 'Insuffisant' => 're zister'];

function bzh_br_e(string $n): string
{
    foreach (['Ar ' => 'er ', 'An ' => 'en ', 'Al ' => 'el '] as $k => $v) if (str_starts_with($n, $k)) return $v . substr($n, strlen($k));
    return (preg_match('/^[AEIOUYHÉÈaeiouyh]/u', $n) ? 'en ' : 'e ') . $n;
}
function bzh_br_url(array $c): string { return '/br/' . BZH_DEPTS[$c['dept']][1] . '/' . $c['slug'] . '/'; }
function bzh_br_ok(array $c): bool { return !empty($c['nom_br']) && bzh_commune_indexable($c, bzh_commune_stats($c['insee'])); }
function bzh_br_alt(array $c): string { return '<link rel="alternate" hreflang="fr" href="' . h(bzh_canon(bzh_com_url($c))) . '"><link rel="alternate" hreflang="br" href="' . h(bzh_canon(bzh_br_url($c))) . '">'; }

function bzh_br_index(array $site): string
{
    $body = '<nav class="crumbs"><a href="/br/">Breizh</a></nav><h1 style="margin-top:20px" lang="br">Petra ober e Breizh ?</h1><p class="bz-ans" lang="br">Pajennoù ar c\'humunioù e brezhoneg : lec\'hioù da weladenniñ, monumantoù istorel, traezhennoù hag abadennoù da zont.</p>';
    foreach (BZH_DEPTS as $d => $v) {
        $st = bzh_db()->prepare("SELECT * FROM communes WHERE dept=? AND nom_br IS NOT NULL AND nom_br<>'' ORDER BY nom_br"); $st->execute([$d]);
        $cs = array_filter($st->fetchAll(), 'bzh_br_ok');
        if ($cs) $body .= '<h2 lang="br">' . h(BZH_DEPTS_BR[$d][0]) . '</h2><ul class="bz-tags" lang="br">' . implode('', array_map(fn($c) => '<li><a href="' . bzh_br_url($c) . '">' . h($c['nom_br']) . '</a></li>', $cs)) . '</ul>';
    }
    $body .= '<p class="disc">Version en langue bretonne des fiches communes. Les noms de lieux bretons proviennent de Wikidata.</p>';
    return bzh_page($site, ['title' => 'Petra ober e Breizh ? Kumunioù Breizh e brezhoneg | Dizoleiñ Breizh', 'path' => '/br/', 'lang' => 'br',
        'desc' => 'Pajennoù kumunioù Breizh e brezhoneg : lec\'hioù da weladenniñ, monumantoù istorel, traezhennoù hag abadennoù.'], $body);
}

function bzh_br_page(array $site, array $c): string
{
    if (!bzh_br_ok($c)) return '';
    $i = $c['insee']; $nb = $c['nom_br']; $d = $c['dept']; $t = bzh_today(); $s = bzh_commune_stats($i);
    $q = fn($k, $n = 12, $o = 'i.rank DESC, i.name') => array_values(array_filter(bzh_items('i.insee=? AND i.kind=? AND i.page=1' . ($k === 'evenement' ? ' AND i.d2>=?' : ''), $k === 'evenement' ? [$i, $k, $t] : [$i, $k], $o, $n), fn($r) => $r['page']));
    $ul = fn(array $rows, callable $x) => $rows ? '<ul class="bz-list">' . implode('', array_map(function ($r) use ($x) { $e = $x($r); return '<li><a href="' . bzh_item_url($r) . '" hreflang="fr">' . h($r['name']) . '</a>' . ($e !== '' ? '<small>' . h($e) . '</small>' : '') . '</li>'; }, $rows)) . '</ul>' : '';
    $parts = [];
    foreach (['lieu' => "lec'h da weladenniñ", 'monument' => 'monumant istorel', 'plage' => "lec'h-kouronkañ evezhiet", 'rando' => 'hent-bale', 'patrimoine' => 'elfenn eus ar glad', 'evenement' => 'abadenn da zont', 'festival' => 'festival'] as $k => $l)
        if (!empty($s[$k])) $parts[] = bzh_nf($s[$k]) . ' ' . $l;
    $intro = $nb . ' (' . $c['nom'] . ' e galleg) a zo ur gumun ' . BZH_DEPTS_BR[$d][1] . ($c['pop'] ? ', ennañ ' . bzh_nf((int)$c['pop']) . ' annezad' : '') . '.';
    $ans = $parts ? bzh_ucfirst(bzh_br_e($nb)) . ' ez eus : ' . implode(', ', $parts) . '.' : '';
    $body = '<nav class="crumbs" lang="br"><a href="/br/">Breizh</a> › ' . h(BZH_DEPTS_BR[$d][0]) . ' › ' . h($nb) . '</nav><div lang="br"><div class="bz-hero"><h1>Petra ober ' . h(bzh_br_e($nb)) . ' ?</h1><p>' . h($intro) . '</p></div>'
        . ($ans ? '<p class="bz-ans">' . h($ans) . '</p>' : '') . '<p class="disc"><a href="' . bzh_com_url($c) . '" hreflang="fr" lang="fr">Lire cette page en français ›</a></p>';
    $dt = fn($x) => date('d/m/Y', strtotime($x));
    $secs = [
        ['evenement', 'Abadennoù da zont', 10, 'i.d1, i.rank DESC', fn($r) => $dt($r['d1']) . ($r['d2'] > $r['d1'] ? ' – ' . $dt($r['d2']) : '')],
        ['lieu', "Lec'hioù da weladenniñ", 12, null, fn($r) => ''],
        ['monument', 'Monumantoù istorel', 20, null, fn($r) => ''],
        ['plage', 'Traezhennoù ha kouronkañ', 20, null, fn($r) => 'Kalite an dour : ' . (BZH_Q_BR[bzh_data($r)['qualite'] ?? ''] ?? '?')],
        ['rando', 'Hentoù-bale', 12, null, fn($r) => ''],
        ['festival', 'Festivalioù', 10, null, fn($r) => ''],
        ['patrimoine', 'Glad', 12, null, fn($r) => ''],
    ];
    foreach ($secs as [$k, $tt, $n, $o, $x]) { $rows = $o ? $q($k, $n, $o) : $q($k, $n); if ($rows) $body .= '<section class="bz-sec"><h2>' . h($tt) . '</h2>' . $ul($rows, $x) . '</section>'; }
    if ($c['lat']) {
        $nb2 = bzh_db()->prepare('SELECT * FROM communes WHERE insee<>? ORDER BY ((lat-?)*(lat-?)+((lon-?)*0.67)*((lon-?)*0.67)) LIMIT 8');
        $nb2->execute([$i, $c['lat'], $c['lat'], $c['lon'], $c['lon']]);
        $body .= '<section class="bz-sec"><h2>Kumunioù tro-war-dro</h2><ul class="bz-tags">' . implode('', array_map(fn($x) => bzh_br_ok($x) ? '<li><a href="' . bzh_br_url($x) . '">' . h($x['nom_br']) . '</a></li>' : '<li><a href="' . bzh_com_url($x) . '" hreflang="fr" lang="fr">' . h($x['nom']) . '</a></li>', $nb2->fetchAll())) . '</ul></section>';
        $body .= bzh_map((float)$c['lat'], (float)$c['lon'], $nb, 0.03);
    }
    $body .= '</div><p class="bz-src">Pajenn e brezhoneg savet diwar ar roadennoù publik ; anvioù al lec\'hioù a chom en o stumm ofisiel. — Page en breton établie à partir des données publiques (DATAtourisme, Mérimée, Inventaire du patrimoine, contrôle sanitaire des eaux de baignade) ; nom breton de la commune : Wikidata.</p>';
    return bzh_page($site, ['title' => 'Petra ober ' . bzh_br_e($nb) . ' (' . $c['nom'] . ') ? | Dizoleiñ Breizh', 'path' => bzh_br_url($c), 'lang' => 'br', 'head' => bzh_br_alt($c),
        'desc' => mb_substr($intro . ' ' . $ans, 0, 158)], $body,
        [['@context' => 'https://schema.org', '@type' => 'City', 'name' => $nb, 'alternateName' => $c['nom'], 'inLanguage' => 'br', 'url' => bzh_canon(bzh_br_url($c))]]);
}

// ---------- En-tête et méga-menu ----------

function bzh_header(array $site): string
{
    $cur = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $L = fn(string $u, string $t, string $sub = '') => '<a href="' . $u . '"' . ($cur === $u ? ' aria-current="page"' : '') . '>' . h($t) . ($sub !== '' ? '<small>' . h($sub) . '</small>' : '') . '</a>';
    try { [$sat, $sun] = bzh_weekend(); $nWe = bzh_count('i.kind=? AND i.d1<=? AND i.d2>=?', ['evenement', $sun, $sat]); } catch (Throwable $e) { $nWe = 0; }
    $D = fn(string $pre) => implode('', array_map(fn($c, $v) => $L('/' . $pre . '/' . $v[1] . '/', $v[0]), array_keys(BZH_DEPTS), BZH_DEPTS));
    $villes = [];
    try { foreach (bzh_db()->query('SELECT * FROM communes ORDER BY pop DESC LIMIT 8') as $c) $villes[] = $L(bzh_com_url($c), $c['nom']); } catch (Throwable $e) {}
    $saisons = []; foreach (BZH_SEASONS as $sl => $S) if (bzh_season_count($sl)) $saisons[] = $L('/agenda/' . $sl . '/', $S[1]);
    $cats = ''; foreach (array_slice(site_categories($site), 0, 7) as $c) $cats .= $L('/category/' . $c['slug'] . '/', nav_label($c['name']));
    $menus = [
        'Agenda' => ['/agenda/', '<div class="nh-col"><b>Quand ?</b>' . $L('/agenda/ce-week-end/', 'Ce week-end') . $L('/agenda/', 'Tout l\'agenda') . $L('/festivals/', 'Les festivals') . '</div>'
            . '<div class="nh-col"><b>Où ?</b>' . $D('agenda') . '</div>'
            . '<div class="nh-col"><b>Envies</b>' . implode('', array_map(fn($s, $v) => $L('/agenda/' . $s . '/', $v[0]), array_keys(BZH_EV_THEMES), BZH_EV_THEMES)) . '</div>'
            . ($saisons ? '<div class="nh-col"><b>En ce moment</b>' . implode('', $saisons) . '</div>' : '')
            . '<a class="nh-feat" href="/agenda/ce-week-end/"><span>Ce week-end</span><strong>' . ($nWe ? bzh_nf($nWe) . ' sorties' : 'Toutes les sorties') . ' en Bretagne</strong><em>Voir le programme →</em></a>', true],
        'À visiter' => ['/lieux-a-visiter/', '<div class="nh-col"><b>Lieux à visiter</b>' . $L('/lieux-a-visiter/', 'Toute la Bretagne') . $D('lieux-a-visiter') . '</div>'
            . '<div class="nh-col"><b>Patrimoine</b>' . $L('/monuments-historiques/', 'Monuments historiques', 'Châteaux, chapelles, enclos, mégalithes') . $L('/patrimoine-bretagne/', 'Patrimoine breton', 'Calvaires, fontaines, moulins, phares') . '</div>'
            . '<div class="nh-col"><b>Plein air</b>' . $L('/randonnees/', 'Randonnées et circuits', 'Balades et sentiers balisés') . $L('/plages/', 'Plages et baignade', 'Qualité de l\'eau des plages') . '</div>', false],
        'Mer et îles' => ['/iles/', '<div class="nh-col"><b>Les îles</b>' . implode('', array_map(fn($s, $v) => $L('/iles/' . $s . '/', $v[0]), array_keys(BZH_ILES), BZH_ILES)) . '</div>'
            . '<div class="nh-col"><b>Plages</b>' . $L('/plages/', 'Toutes les plages') . $D('plages') . '</div>'
            . '<a class="nh-feat" href="/iles/"><span>Traversées</span><strong>Horaires des bateaux vers les îles</strong><em>Les 7 prochains jours →</em></a>', true],
        'Destinations' => ['/finistere/', '<div class="nh-col"><b>Départements</b>' . implode('', array_map(fn($c, $v) => $L('/' . $v[1] . '/', $v[0]), array_keys(BZH_DEPTS), BZH_DEPTS)) . '</div>'
            . ($villes ? '<div class="nh-col"><b>Grandes villes</b>' . implode('', $villes) . '</div>' : '')
            . '<div class="nh-col"><b>Classements ' . date('Y') . '</b>' . implode('', array_map(fn($s, $v) => $L('/classements/' . $s . '/', $v[4]), array_keys(BZH_RANKS), BZH_RANKS)) . $L('/classements/', 'Tous les classements') . '</div>'
            . '<div class="nh-col"><b>Brezhoneg</b>' . $L('/br/', 'Breizh e brezhoneg', 'Pajennoù ar c\'humunioù') . '</div>', true],
    ];
    if ($cats) $menus['Guides'] = ['/category/', '<div class="nh-col"><b>Nos guides</b>' . $cats . '</div>', false];
    $nav = '';
    foreach ($menus as $lab => [$href, $panel, $wide]) {
        $on = $cur !== '/' && (($lab === 'Destinations' && (str_starts_with($cur, '/classements/') || str_starts_with($cur, '/br/'))) || str_starts_with($cur, $href) || ($lab === 'Agenda' && (str_starts_with($cur, '/festival') || str_starts_with($cur, '/evenement/'))) || ($lab === 'Mer et îles' && str_starts_with($cur, '/plage'))
            || ($lab === 'Destinations' && (bool)preg_match('#^/(cotes-d-armor|finistere|ille-et-vilaine|morbihan)/#', $cur))) ? ' nh-on' : '';
        $nav .= '<li class="nh-it' . $on . '"><button type="button" class="nh-tg" aria-expanded="false">' . h($lab) . '<i></i></button><div class="nh-pn' . ($wide ? ' nh-wide' : '') . '"><div class="nh-pi">' . $panel . '</div></div></li>';
    }
    $lg = (string)setting('logo:' . $site['host'], '');
    $logo = $lg !== '' ? '<picture><source srcset="' . h(preg_replace('/\.png$/', '.webp', $lg)) . '" type="image/webp"><img src="' . h($lg) . '" alt="' . h($site['name']) . '" width="304" height="110" fetchpriority="high"></picture>'
        : '<span class="nh-txt"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2c.9 2.6 2.4 4 4.6 4.4-1.6 1.2-2.3 2.8-2 4.9-1.1-1.3-1.9-1.9-2.6-1.9s-1.5.6-2.6 1.9c.3-2.1-.4-3.7-2-4.9C7.6 6 9.1 4.6 12 2zm0 9.5c1.4 0 2.4 2.6 2.4 6.2V22h-4.8v-4.3c0-3.6 1-6.2 2.4-6.2z"/></svg><span>Découverte<em>Bretagne</em></span></span>';
    $css = '<style>:root{--c:#0a5c8f;--nh-navy:#0b2e4f;--nh-blue:#0a5c8f;--nh-gold:#F2B33D}'
        . 'header.nh{position:relative;z-index:50;background:#fff;box-shadow:0 1px 0 rgba(11,46,79,.08)}.nh-top{max-width:1180px;margin:0 auto;padding:8px 20px;display:flex;align-items:center;gap:22px}'
        . '.nh-logo{display:block;flex:0 0 auto;line-height:0;text-decoration:none}.nh-logo img{height:96px;width:auto}'
        . '.nh-txt{display:flex;align-items:center;gap:10px;line-height:1}.nh-txt svg{width:40px;height:40px;fill:var(--nh-navy)}.nh-txt span{display:flex;flex-direction:column;font-weight:800;font-size:1.05rem;color:var(--nh-navy);letter-spacing:.02em;text-transform:uppercase}.nh-txt em{font-style:normal;font-size:1.55rem;color:var(--nh-blue);letter-spacing:.01em;text-transform:none;margin-top:2px}'
        . '.nh-q{flex:1;max-width:420px;margin-left:auto;position:relative}.nh-q input{width:100%;height:44px;border:1px solid #dfe5ec;background:#f4f7fa;border-radius:999px;padding:0 18px 0 44px;font:inherit;font-size:.95rem;color:#1d2327;transition:.15s}'
        . '.nh-q input:focus{outline:0;background:#fff;border-color:var(--nh-blue);box-shadow:0 0 0 3px rgba(10,92,143,.15)}.nh-q svg{position:absolute;left:16px;top:50%;transform:translateY(-50%);width:17px;height:17px;stroke:#6b7a8a}'
        . '.nh-cta{flex:0 0 auto;display:inline-flex;align-items:center;gap:8px;height:44px;padding:0 20px;border-radius:999px;background:var(--nh-gold);color:#2b1d00!important;font-weight:700;text-decoration:none;font-size:.95rem;box-shadow:0 2px 8px rgba(242,179,61,.35);transition:.15s}.nh-cta:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(242,179,61,.45)}'
        . '.nh-bar{background:var(--nh-navy);background:linear-gradient(90deg,#0b2e4f,#0a5c8f 60%,#0d7a8a)}.nh-nav{max-width:1180px;margin:0 auto;padding:0 12px;display:flex;list-style:none;margin-top:0;margin-bottom:0;gap:2px}'
        . '.nh-it{position:static}.nh-tg{display:flex;align-items:center;gap:8px;height:52px;padding:0 16px;background:none;border:0;color:#fff!important;font:inherit;font-size:.98rem;font-weight:600;letter-spacing:.01em;cursor:pointer;text-decoration:none;position:relative}'
        . '.nh-tg:after{content:"";position:absolute;left:16px;right:16px;bottom:0;height:3px;border-radius:3px 3px 0 0;background:var(--nh-gold);transform:scaleX(0);transition:transform .18s}.nh-it:hover .nh-tg:after,.nh-it.nh-open .nh-tg:after,.nh-on .nh-tg:after{transform:scaleX(1)}'
        . '.nh-tg i{width:7px;height:7px;border-right:2px solid rgba(255,255,255,.8);border-bottom:2px solid rgba(255,255,255,.8);transform:translateY(-2px) rotate(45deg);transition:.15s}.nh-open .nh-tg i{transform:translateY(1px) rotate(-135deg)}'
        . '.nh-pn{position:absolute;left:0;right:0;top:100%;background:#fff;box-shadow:0 18px 40px rgba(11,46,79,.18);border-top:3px solid var(--nh-gold);opacity:0;visibility:hidden;transform:translateY(-6px);transition:opacity .16s,transform .16s,visibility .16s}'
        . '.nh-open .nh-pn{opacity:1;visibility:visible;transform:none}.nh-pi{max-width:1180px;margin:0 auto;padding:26px 28px 30px;display:flex;gap:44px;align-items:flex-start}'
        . '.nh-col{display:flex;flex-direction:column;min-width:180px}.nh-col b{font-size:.74rem;text-transform:uppercase;letter-spacing:.09em;color:var(--nh-blue);margin-bottom:10px;padding-bottom:8px;border-bottom:2px solid #eef2f6}'
        . '.nh-col a{color:#1d2327!important;text-decoration:none;padding:6px 0;font-size:.95rem;transition:color .12s,transform .12s}.nh-col a:hover,.nh-col a[aria-current]{color:var(--nh-blue)!important;transform:translateX(3px)}.nh-col a small{display:block;color:#6b7a8a;font-size:.8rem;margin-top:1px}'
        . '.nh-feat{margin-left:auto;align-self:stretch;min-width:240px;max-width:280px;border-radius:14px;padding:22px;background:linear-gradient(140deg,#0b2e4f,#0d7a8a);color:#fff!important;text-decoration:none;display:flex;flex-direction:column;justify-content:flex-end;gap:6px;box-shadow:0 10px 24px rgba(11,46,79,.25)}'
        . '.nh-feat span{font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;color:var(--nh-gold);font-weight:700}.nh-feat strong{font-size:1.25rem;line-height:1.25}.nh-feat em{font-style:normal;font-size:.9rem;opacity:.85}.nh-feat:hover em{opacity:1;text-decoration:underline}'
        . '.nh-bt{display:none}'
        . '@media(max-width:900px){.nh-top{padding:8px 16px;gap:12px;flex-wrap:wrap}.nh-logo img{height:64px}.nh-txt svg{width:32px;height:32px}.nh-txt em{font-size:1.3rem}.nh-cta{display:none}.nh-q{order:3;flex:1 1 100%;max-width:none}.nh-q input{height:42px}'
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
    return $css . '<header class="nh"><div class="nh-top"><a class="nh-logo" href="/" aria-label="' . h($site['name']) . ' — accueil">' . $logo . '</a>'
        . '<form class="nh-q" action="/recherche/" method="get" role="search"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input type="search" name="q" placeholder="Une commune, une plage, une sortie…" aria-label="Rechercher"></form>'
        . '<a class="nh-cta" href="/agenda/ce-week-end/">Que faire ce week-end ?</a>'
        . '<button type="button" class="nh-bt" aria-label="Menu" aria-expanded="false"><span></span></button></div>'
        . '<nav class="nh-bar" aria-label="Menu principal"><ul class="nh-nav">' . $nav . '</ul><a class="nh-cta-m" href="/agenda/ce-week-end/">Que faire ce week-end ?</a></nav></header>' . $js;
}

// ---------- Sitemaps, llms.txt ----------

function bzh_sitemap_names(array $site): array
{
    $db = bzh_db(); $o = ['hubs'];
    foreach (array_keys(BZH_KINDS) as $k) {
        $n = (int)$db->query('SELECT COUNT(*) FROM items WHERE page=1 AND kind=' . $db->quote($k) . ($k === 'evenement' ? ' AND d2>=' . $db->quote(bzh_today()) : ''))->fetchColumn();
        for ($i = 1; $i <= (int)ceil($n / 5000); $i++) $o[] = "$k-$i";
    }
    return $o;
}

function bzh_hub_urls(): array
{
    $db = bzh_db(); $u = ['/', '/iles/', '/agenda/ce-week-end/'];
    foreach (BZH_DEPTS as $d => [, $ds]) $u[] = "/$ds/";
    foreach (BZH_KINDS as [, $pl]) { $u[] = "/$pl/"; foreach (BZH_DEPTS as [, $ds]) $u[] = "/$pl/$ds/"; }
    foreach (array_keys(BZH_ILES) as $s) $u[] = "/iles/$s/";
    foreach (array_keys(BZH_EV_THEMES) as $s) $u[] = "/agenda/$s/";
    foreach (array_keys(BZH_SEASONS) as $s) if (bzh_season_count($s)) $u[] = "/agenda/$s/";
    $u[] = '/classements/'; foreach (array_keys(BZH_RANKS) as $s) $u[] = "/classements/$s/";
    $u[] = '/br/';
    foreach ($db->query('SELECT * FROM communes') as $c) {
        if (bzh_commune_indexable($c, bzh_commune_stats($c['insee']))) { $u[] = bzh_com_url($c); if (!empty($c['nom_br'])) $u[] = bzh_br_url($c); }
    }
    return $u;
}

function bzh_out_sitemap(array $site, string $name): void
{
    $db = bzh_db();
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $day = date('Y-m-d');
    if ($name === 'hubs') {
        $cf = cfg('data_dir') . '/cache/' . BZH_HOST . '.bzh-hubs.json';
        $urls = is_file($cf) && filemtime($cf) > time() - 21600 ? json_decode((string)file_get_contents($cf), true) : null;
        if (!$urls) { $urls = bzh_hub_urls(); @file_put_contents($cf, json_encode($urls)); }
        foreach ($urls as $u) echo '<url><loc>' . h(bzh_canon($u)) . '</loc><lastmod>' . $day . '</lastmod></url>';
    } elseif (preg_match('/^([a-z]+)-(\d+)$/', $name, $m) && isset(BZH_KINDS[$m[1]])) {
        $st = $db->prepare('SELECT kind, slug, substr(seen,1,10) m FROM items WHERE page=1 AND kind=?' . ($m[1] === 'evenement' ? ' AND d2>=?' : '') . ' ORDER BY rank DESC, id LIMIT 5000 OFFSET ?');
        $st->execute($m[1] === 'evenement' ? [$m[1], bzh_today(), ((int)$m[2] - 1) * 5000] : [$m[1], ((int)$m[2] - 1) * 5000]);
        foreach ($st as $r) echo '<url><loc>' . h(bzh_canon(bzh_item_url($r))) . '</loc><lastmod>' . h($r['m']) . '</lastmod></url>';
    }
    echo '</urlset>';
}

function bzh_all_urls(array $site): array
{
    $u = array_map('bzh_canon', bzh_hub_urls());
    foreach (bzh_db()->query("SELECT kind, slug FROM items WHERE page=1 AND (kind<>'evenement' OR d2>=" . bzh_db()->quote(bzh_today()) . ') ORDER BY rank DESC') as $r) $u[] = bzh_canon(bzh_item_url($r));
    return $u;
}

function bzh_llms(array $site): void
{
    header('Content-Type: text/plain; charset=utf-8');
    $db = bzh_db();
    echo "# {$site['name']} (bretagne.decouverte.org)\n\n> Guide de la Bretagne commune par commune (Côtes-d'Armor, Finistère, Ille-et-Vilaine, Morbihan) construit à partir de données publiques officielles mises à jour automatiquement : agenda des sorties (DATAtourisme), monuments historiques (base Mérimée), patrimoine (Inventaire de la Région Bretagne), qualité des eaux de baignade (ministère de la Santé), festivals (ministère de la Culture) et horaires des bateaux vers les îles (BreizhGo).\n\n";
    echo "## Rubriques\n\n- [Agenda des sorties en Bretagne](" . bzh_canon('/agenda/') . "): événements à venir, mis à jour chaque jour\n- [Que faire ce week-end](" . bzh_canon('/agenda/ce-week-end/') . ")\n- [Îles et horaires des bateaux](" . bzh_canon('/iles/') . ")\n";
    foreach (BZH_KINDS as $k => [, $pl, $lab]) if ($k !== 'evenement') echo "- [$lab](" . bzh_canon("/$pl/") . ")\n";
    echo "\n## Départements\n\n";
    foreach (BZH_DEPTS as $d => [$dn, $ds]) echo "- [Que faire " . bzh_in_dept((string)$d) . "](" . bzh_canon("/$ds/") . ")\n";
    echo "\n## Principales communes\n\n";
    foreach ($db->query('SELECT * FROM communes ORDER BY pop DESC LIMIT 60') as $c) echo "- [Que faire " . bzh_a($c['nom']) . "](" . bzh_canon(bzh_com_url($c)) . ")\n";
    echo "\n## Plan complet\n\n- [Sitemap](" . bzh_canon('/sitemap.xml') . ")\n";
}
