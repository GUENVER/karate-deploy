<?php
// Import d'un WordPress (API REST publique) dans la base SQLite d'un site de la fabrique.
// Usage : php cli/import_wp.php <hote-fabrique> <url-source> [--dedupe]
// --dedupe : les articles dont le sujet est un quasi-doublon d'un article déjà importé sont remplacés par une redirection 301.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/api.php';

[$_, $host, $src] = $argv + [null, null, null];
$dedupe = in_array('--dedupe', $argv, true);
if (function_exists('proc_nice')) @proc_nice(19);
if (!$host || !$src) { fwrite(STDERR, "usage: import_wp.php host src [--dedupe]\n"); exit(1); }
$site = site_by_host($host) ?: exit("site $host absent du registre\n");
$db = site_db($site['host']);
$src = rtrim($src, '/');

function get_json(string $url, ?array &$headers = null)
{
    $headers = [];
    for ($try = 0; $try < 4; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_USERAGENT => 'Mozilla/5.0 (fabrique-import)',
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$headers) { if (strpos($l, ':')) { [$k, $v] = explode(':', $l, 2); $headers[strtolower(trim($k))] = trim($v); } return strlen($l); }]);
        $b = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 200) return json_decode((string)$b, true);
        sleep(2 + $try * 3);
    }
    throw new RuntimeException("HTTP $code $url");
}

// Rubriques
$catMap = [];
for ($pg = 1; ; $pg++) {
    $cats = get_json("$src/wp-json/wp/v2/categories?per_page=100&page=$pg&_fields=id,slug,name,description,count", $hd);
    foreach ($cats as $c) {
        $catMap[$c['id']] = $c['slug'];
        if ($c['count'] > 0) $db->prepare('INSERT INTO categories(slug,name,description) VALUES(?,?,?) ON CONFLICT(slug) DO UPDATE SET name=excluded.name')
            ->execute([$c['slug'], html_entity_decode($c['name']), trim(strip_tags(html_entity_decode((string)$c['description'])))]);
    }
    if ($pg >= (int)($hd['x-wp-totalpages'] ?? 1)) break;
}
echo count($catMap) . " rubriques\n";

$ins = $db->prepare('INSERT INTO posts(slug,path,title,excerpt,content,meta_title,meta_desc,category,image,image_alt,keyword,words,status,published_at,updated_at,source)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(slug) DO UPDATE SET content=excluded.content, title=excluded.title, updated_at=excluded.updated_at, meta_title=excluded.meta_title, meta_desc=excluded.meta_desc, image=excluded.image');
$redir = $db->prepare('INSERT OR REPLACE INTO redirects(src,dst,code) VALUES(?,?,301)');
$seen = []; // signature -> path gardé
$n = 0; $dups = 0;
$fields = 'id,date_gmt,modified_gmt,slug,link,title,content,excerpt,categories,yoast_head_json';
for ($pg = 1; ; $pg++) {
    // du plus ancien au plus récent : l'original est conservé, les resucées suivantes redirigées
    $posts = get_json("$src/wp-json/wp/v2/posts?per_page=50&page=$pg&orderby=date&order=asc&_fields=$fields", $hd);
    $db->beginTransaction();
    foreach ($posts as $p) {
        $title = trim(html_entity_decode(strip_tags($p['title']['rendered']), ENT_QUOTES, 'UTF-8'));
        $path = parse_url($p['link'], PHP_URL_PATH) ?: '/' . $p['slug'] . '/';
        // doublon = même slug de base (sans suffixe -N) ou même titre normalisé
        $keys = ['s:' . preg_replace('/-\d+$/', '', $p['slug']), 't:' . topic_sig($title)];
        if ($dedupe) {
            foreach ($keys as $k) if (isset($seen[$k])) { $redir->execute([$path, $seen[$k]]); $dups++; continue 2; }
        }
        foreach ($keys as $k) $seen[$k] = $path;
        $y = $p['yoast_head_json'] ?? [];
        $content = (string)$p['content']['rendered'];
        $content = preg_replace('#<script[^>]*>.*?</script>#is', '', $content);
        $content = preg_replace('#<ins class="adsbygoogle.*?</ins>#is', '', $content);
        $img = $y['og_image'][0]['url'] ?? '';
        $ins->execute([
            $p['slug'], $path, $title, trim(html_entity_decode(strip_tags($p['excerpt']['rendered']), ENT_QUOTES, 'UTF-8')), $content,
            html_entity_decode((string)($y['title'] ?? $title), ENT_QUOTES, 'UTF-8'), html_entity_decode((string)($y['description'] ?? ''), ENT_QUOTES, 'UTF-8'),
            $catMap[$p['categories'][0] ?? 0] ?? '', $img, $title, '', word_count($content), 'publish',
            str_replace('T', ' ', $p['date_gmt']), str_replace('T', ' ', $p['modified_gmt']), 'wp',
        ]);
        $n++;
    }
    $db->commit();
    echo "page $pg : $n importés, $dups doublons redirigés\n";
    if ($pg >= (int)($hd['x-wp-totalpages'] ?? 1)) break;
}
// Mot-clé de maillage = titre raccourci aux mots utiles (pour autolink)
foreach ($db->query("SELECT id,title FROM posts WHERE keyword='' OR keyword IS NULL")->fetchAll() as $r) {
    $kw = preg_replace('/\s*[:|–—\-(].*$/u', '', $r['title']);
    if (mb_strlen($kw) > 60 || mb_strlen($kw) < 8) $kw = '';
    $db->prepare('UPDATE posts SET keyword=? WHERE id=?')->execute([mb_strtolower($kw), $r['id']]);
}
cache_clear($site['host']);
registry()->prepare('UPDATE sites SET last_post_at=? WHERE id=?')->execute([$db->query('SELECT MAX(published_at) FROM posts')->fetchColumn(), $site['id']]);
flog($site['host'], 'import', "$n articles importés depuis $src, $dups doublons redirigés");
echo "OK $n articles, $dups redirections\n";
