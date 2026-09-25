<?php
// Noyau : config, bases SQLite (registre + une base par site), helpers.
declare(strict_types=1);

define('FAB_ROOT', dirname(__DIR__));

function cfg(?string $k = null, $def = null)
{
    static $c = null;
    if ($c === null) {
        $f = FAB_ROOT . '/config.php';
        $c = is_file($f) ? require $f : [];
        $c += ['data_dir' => FAB_ROOT . '/data', 'public_dir' => FAB_ROOT . '/public'];
    }
    return $k === null ? $c : ($c[$k] ?? $def);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return gmdate('Y-m-d H:i:s'); }

function pdo_open(string $file): PDO
{
    $new = !is_file($file);
    if ($new && !is_dir(dirname($file))) mkdir(dirname($file), 0750, true);
    $db = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=8000; PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
    return $db;
}

function registry(): PDO
{
    static $db = null;
    if ($db) return $db;
    $db = pdo_open(cfg('data_dir') . '/registry.sqlite');
    $db->exec("CREATE TABLE IF NOT EXISTS sites(
        id INTEGER PRIMARY KEY, host TEXT UNIQUE NOT NULL, name TEXT, tagline TEXT, niche TEXT, lang TEXT DEFAULT 'fr',
        color TEXT DEFAULT '#0b6e4f', status TEXT DEFAULT 'active', adsense INTEGER DEFAULT 1, amazon INTEGER DEFAULT 1,
        gen INTEGER DEFAULT 1, per_day REAL DEFAULT 3, min_words INTEGER DEFAULT 1200, permalink TEXT DEFAULT '/%slug%/',
        seeds TEXT DEFAULT '', ymyl INTEGER DEFAULT 0, created_at TEXT, next_gen_at TEXT, last_post_at TEXT, notes TEXT);
    CREATE TABLE IF NOT EXISTS aliases(alias TEXT PRIMARY KEY, host TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT);
    CREATE TABLE IF NOT EXISTS log(id INTEGER PRIMARY KEY, at TEXT, host TEXT, kind TEXT, msg TEXT);");
    $cols = array_column($db->query('PRAGMA table_info(sites)')->fetchAll(), 'name');
    if (!in_array('jobs', $cols, true)) $db->exec('ALTER TABLE sites ADD COLUMN jobs INTEGER DEFAULT 0');
    return $db;
}

function setting(string $k, $def = '')
{
    $st = registry()->prepare('SELECT v FROM settings WHERE k=?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $def : $v;
}
function setting_set(string $k, string $v): void
{
    registry()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}

function flog(string $host, string $kind, string $msg): void
{
    registry()->prepare('INSERT INTO log(at,host,kind,msg) VALUES(?,?,?,?)')->execute([now(), $host, $kind, mb_substr($msg, 0, 500)]);
    registry()->exec('DELETE FROM log WHERE id < (SELECT MAX(id) FROM log) - 2000');
}

function site_by_host(string $host): ?array
{
    $host = strtolower(preg_replace('/:\d+$/', '', trim($host)));
    $r = registry();
    foreach ([$host, preg_replace('/^www\./', '', $host)] as $h) {
        $st = $r->prepare('SELECT * FROM sites WHERE host=?');
        $st->execute([$h]);
        if ($s = $st->fetch()) return $s;
        $st = $r->prepare('SELECT s.* FROM aliases a JOIN sites s ON s.host=a.host WHERE a.alias=?');
        $st->execute([$h]);
        if ($s = $st->fetch()) return $s;
    }
    return null;
}

function site_db(string $host): PDO
{
    static $dbs = [];
    if (isset($dbs[$host])) return $dbs[$host];
    $db = pdo_open(cfg('data_dir') . '/sites/' . preg_replace('/[^a-z0-9.\-]/', '_', $host) . '.sqlite');
    $db->exec("CREATE TABLE IF NOT EXISTS posts(
        id INTEGER PRIMARY KEY, slug TEXT UNIQUE, path TEXT UNIQUE, title TEXT, excerpt TEXT, content TEXT,
        meta_title TEXT, meta_desc TEXT, category TEXT, tags TEXT DEFAULT '[]', image TEXT, image_alt TEXT, image_credit TEXT,
        faq TEXT DEFAULT '[]', products TEXT DEFAULT '[]', keyword TEXT, words INTEGER DEFAULT 0, status TEXT DEFAULT 'publish',
        published_at TEXT, updated_at TEXT, source TEXT, provider TEXT, views INTEGER DEFAULT 0);
    CREATE INDEX IF NOT EXISTS ix_posts_pub ON posts(status, published_at);
    CREATE INDEX IF NOT EXISTS ix_posts_cat ON posts(category, published_at);
    CREATE TABLE IF NOT EXISTS categories(slug TEXT PRIMARY KEY, name TEXT, description TEXT);
    CREATE TABLE IF NOT EXISTS topics(
        id INTEGER PRIMARY KEY, keyword TEXT UNIQUE, title TEXT, category TEXT, intent TEXT, cluster TEXT,
        priority INTEGER DEFAULT 50, status TEXT DEFAULT 'queued', attempts INTEGER DEFAULT 0,
        created_at TEXT, updated_at TEXT, post_id INTEGER, note TEXT);
    CREATE INDEX IF NOT EXISTS ix_topics ON topics(status, priority);
    CREATE TABLE IF NOT EXISTS redirects(src TEXT PRIMARY KEY, dst TEXT, code INTEGER DEFAULT 301);
    CREATE TABLE IF NOT EXISTS stats(day TEXT PRIMARY KEY, pv INTEGER DEFAULT 0);");
    return $dbs[$host] = $db;
}

function slugify(string $s, int $max = 80): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss', '’' => '-', "'" => '-']);
    if (function_exists('transliterator_transliterate')) $s = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
    else $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim(substr($s, 0, $max), '-');
    return $s !== '' ? $s : 'article';
}

// Normalisation pour détecter les doublons de sujets (mots significatifs triés).
function topic_sig(string $s): string
{
    $s = str_replace('-', ' ', slugify($s, 200));
    $stop = array_flip(explode(' ', 'le la les un une des de du d l et ou a au aux en pour par sur dans avec sans comment quel quelle quels quelles que qui quoi est sont votre vos ton tes son ses mon mes notre nos guide complet facilement rapidement tout tous toutes ce cette ces faire bien meilleur meilleure meilleurs 2024 2025 2026 2027'));
    $w = array_filter(explode(' ', $s), fn($x) => $x !== '' && !isset($stop[$x]) && strlen($x) > 1);
    $w = array_unique(array_map(fn($x) => rtrim($x, 's'), $w));
    sort($w);
    return implode(' ', $w);
}

function similar_sig(string $a, string $b): float
{
    $A = array_flip(explode(' ', $a)); $B = array_flip(explode(' ', $b));
    if (!$A || !$B) return 0.0;
    $i = count(array_intersect_key($A, $B));
    return $i / max(1, count($A + $B));
}

function post_url(array $site, array $p): string { return 'https://' . $site['host'] . $p['path']; }

function build_path(array $site, string $slug, string $date): string
{
    $t = strtotime($date . ' UTC') ?: time();
    return strtr($site['permalink'] ?: '/%slug%/', ['%slug%' => $slug, '%year%' => gmdate('Y', $t), '%monthnum%' => gmdate('m', $t), '%day%' => gmdate('d', $t)]);
}

function cache_dir(string $host): string { return cfg('data_dir') . '/cache/' . $host; }
function cache_clear(string $host): void
{
    foreach (glob(cache_dir($host) . '/*.html') ?: [] as $f) @unlink($f);
}

function is_bot(): bool
{
    return (bool)preg_match('/bot|crawl|spider|slurp|facebookexternalhit|curl|wget|python|headless|preview|monitor|lighthouse/i', $_SERVER['HTTP_USER_AGENT'] ?? 'bot');
}

// Nettoyage du HTML produit par l'IA : balises autorisées, aucun attribut sauf href/src/alt.
function sanitize_html(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed|form)[^>]*>.*?</\1>#is', '', $html);
    $html = strip_tags($html, '<h2><h3><h4><p><ul><ol><li><strong><em><b><i><table><thead><tbody><tr><th><td><blockquote><a><br><img><figure><figcaption><code><pre>');
    $html = preg_replace_callback('#<(\w+)([^>]*)>#', function ($m) {
        $tag = strtolower($m[1]); $keep = '';
        if (in_array($tag, ['a', 'img'], true)) {
            foreach (['href', 'src', 'alt', 'title'] as $at) {
                if (preg_match('/\b' . $at . '\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $m[2], $mm)) {
                    $v = $mm[2] !== '' ? $mm[2] : ($mm[3] ?? '');
                    if (in_array($at, ['href', 'src'], true) && !preg_match('#^(https?:)?/#i', $v)) continue;
                    $keep .= ' ' . $at . '="' . h(html_entity_decode($v)) . '"';
                }
            }
        }
        return '<' . $tag . $keep . '>';
    }, $html);
    return trim($html);
}

function word_count(string $html): int
{
    return count(preg_split('/\s+/u', trim(strip_tags($html)), -1, PREG_SPLIT_NO_EMPTY));
}
