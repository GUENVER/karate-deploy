<?php
// API machine-à-machine (générateur du hub, n8n, routines Claude). Auth : en-tête X-Fabrique-Token.
declare(strict_types=1);

function api_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function api_main(string $route): void
{
    $tok = $_SERVER['HTTP_X_FABRIQUE_TOKEN'] ?? '';
    if (!cfg('api_token') || !hash_equals((string)cfg('api_token'), $tok)) { api_out(['error' => 'auth'], 401); return; }
    $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
    try {
        switch (trim($route, '/')) {
            case 'jobs': api_out(api_jobs(max(1, min(10, (int)($_GET['limit'] ?? 3))), $_GET['host'] ?? null)); return;
            case 'backlog': api_out(api_backlog($in)); return;
            case 'ingest': api_out(api_ingest($in)); return;
            case 'fail': api_out(api_fail($in)); return;
            case 'stats': api_out(api_stats()); return;
            case 'niches': setting_set('niche_report', json_encode($in, JSON_UNESCAPED_UNICODE)); api_out(['ok' => true, 'top' => count($in['top'] ?? [])]); return;
            case 'adsense': setting_set('adsense_report', json_encode($in, JSON_UNESCAPED_UNICODE)); api_out(['ok' => true]); return;
            case 'post': api_out(api_post($in)); return;
            case 'commune_extra': // données par commune calculées ailleurs (ex. qualité de l'eau depuis le VPS)
                $s = need_site($in); if (empty($s['commune']) || !in_array($in['kind'] ?? '', ['eau'], true)) throw new RuntimeException('refusé');
                api_out(['ok' => true, 'stored' => commune_extra_set(commune_db($s['host']), $in['kind'], (array)($in['rows'] ?? []))]); return;
            case 'places': // import poussé (ex. randonnées OpenStreetMap depuis le VPS) : lots de lignes, puis final=true pour purger les absentes
                $s = need_site($in); if (!places_mod($s)) throw new RuntimeException('module lieux inactif');
                $n = places_store($s, (array)($in['rows'] ?? []), (string)$in['stamp']);
                api_out(['ok' => true, 'stored' => $n, 'purged' => !empty($in['final']) ? places_finish($s, (string)$in['stamp']) : 0]); return;
            case 'enrich': api_out(api_enrich($in)); return;
            case 'sites': api_out(registry()->query("SELECT host,name,niche,status,gen,per_day FROM sites WHERE status<>'deleted'")->fetchAll()); return;
            default: api_out(['error' => 'route'], 404);
        }
    } catch (Throwable $e) {
        api_out(['error' => $e->getMessage()], 500);
    }
}

function need_site(array $in): array
{
    $s = site_by_host((string)($in['host'] ?? ''));
    if (!$s) throw new RuntimeException('site inconnu');
    return $s;
}

// Distribue le travail : 1 tâche max par site dû (article ou constitution du backlog).
function api_jobs(int $limit, ?string $only): array
{
    $jobs = [];
    $sql = "SELECT * FROM sites WHERE status='active' AND gen=1 AND (next_gen_at IS NULL OR next_gen_at<=?)" . ($only ? ' AND host=?' : '') . ' ORDER BY next_gen_at LIMIT 20';
    $st = registry()->prepare($sql);
    $st->execute($only ? [now(), $only] : [now()]);
    foreach ($st->fetchAll() as $s) {
        if (count($jobs) >= $limit) break;
        $db = site_db($s['host']);
        $db->exec("UPDATE topics SET status='queued' WHERE status='writing' AND updated_at < datetime('now','-2 hours')");
        $cats = $db->query('SELECT slug,name FROM categories ORDER BY name')->fetchAll();
        $meta = ['host' => $s['host'], 'name' => $s['name'], 'niche' => $s['niche'], 'lang' => $s['lang'], 'min_words' => (int)$s['min_words'],
            'ymyl' => (int)$s['ymyl'], 'seeds' => array_values(array_filter(array_map('trim', preg_split('/[\n,;]+/', (string)$s['seeds'])))), 'categories' => $cats];
        $queued = (int)$db->query("SELECT COUNT(*) FROM topics WHERE status='queued'")->fetchColumn();
        if ($queued < 5) {
            $meta['existing'] = $db->query("SELECT keyword FROM topics UNION SELECT title FROM posts ORDER BY 1 LIMIT 600")->fetchAll(PDO::FETCH_COLUMN);
            $jobs[] = ['type' => 'backlog', 'site' => $meta];
            // on ne décale pas next_gen_at : l'article suivra dès que le backlog existe
            registry()->prepare('UPDATE sites SET next_gen_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() + 300), $s['id']]);
            continue;
        }
        $t = $db->query("SELECT * FROM topics WHERE status='queued' ORDER BY priority DESC, id LIMIT 1")->fetch();
        $db->prepare("UPDATE topics SET status='writing', updated_at=? WHERE id=?")->execute([now(), $t['id']]);
        $meta['recent_titles'] = $db->query("SELECT title FROM posts WHERE status='publish' ORDER BY published_at DESC LIMIT 40")->fetchAll(PDO::FETCH_COLUMN);
        $jobs[] = ['type' => 'article', 'site' => $meta, 'topic' => $t];
        $gap = 86400 / max(0.1, (float)$s['per_day']) * (0.6 + mt_rand() / mt_getrandmax() * 0.8);
        // amorçage : un site neuf reçoit ses 10 premiers articles rapidement (~1 toutes les 30 min)
        if ((int)$db->query("SELECT COUNT(*) FROM posts WHERE status='publish'")->fetchColumn() < 10) $gap = min($gap, 1200 + mt_rand(0, 1200));
        registry()->prepare('UPDATE sites SET next_gen_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() + (int)$gap), $s['id']]);
    }
    return ['jobs' => $jobs];
}

function api_backlog(array $in): array
{
    $s = need_site($in);
    $db = site_db($s['host']);
    $sigs = array_map('topic_sig', $db->query('SELECT keyword FROM topics UNION ALL SELECT title FROM posts')->fetchAll(PDO::FETCH_COLUMN));
    $sigSet = array_flip($sigs);
    $added = 0; $dups = 0;
    $ins = $db->prepare('INSERT OR IGNORE INTO topics(keyword,title,category,intent,cluster,priority,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
    foreach ((array)($in['topics'] ?? []) as $t) {
        $kw = trim((string)($t['keyword'] ?? ''));
        if ($kw === '' || mb_strlen($kw) > 120) continue;
        $sig = topic_sig($kw . ' ' . ($t['title'] ?? ''));
        $sigKw = topic_sig($kw);
        if (isset($sigSet[$sigKw]) || isset($sigSet[$sig])) { $dups++; continue; }
        $near = false;
        foreach ($sigs as $o) if (similar_sig($sigKw, $o) >= 0.75) { $near = true; break; }
        if ($near) { $dups++; continue; }
        $cat = ensure_category($db, (string)($t['category'] ?? ''));
        $ins->execute([$kw, (string)($t['title'] ?? ''), $cat, (string)($t['intent'] ?? ''), (string)($t['cluster'] ?? ''), (int)($t['priority'] ?? 50), now(), now()]);
        if ($db->lastInsertId()) { $added++; $sigs[] = $sigKw; $sigSet[$sigKw] = 1; }
    }
    flog($s['host'], 'backlog', "+$added sujets ($dups doublons écartés)");
    return ['added' => $added, 'duplicates' => $dups];
}

function ensure_category(PDO $db, string $name): string
{
    $name = trim($name);
    if ($name === '') return '';
    $st = $db->prepare('SELECT slug FROM categories WHERE slug=? OR lower(name)=lower(?)');
    $st->execute([slugify($name, 50), $name]);
    if ($slug = $st->fetchColumn()) return $slug;
    $slug = slugify($name, 50);
    $db->prepare('INSERT OR IGNORE INTO categories(slug,name,description) VALUES(?,?,?)')->execute([$slug, mb_convert_case($name, MB_CASE_TITLE), '']);
    return $slug;
}

function api_ingest(array $in): array
{
    $s = need_site($in);
    $db = site_db($s['host']);
    $p = (array)($in['post'] ?? []);
    $topicId = (int)($in['topic_id'] ?? 0);

    $title = trim(strip_tags((string)($p['title'] ?? '')));
    $content = sanitize_html((string)($p['content'] ?? ''));
    $words = word_count($content);
    $errs = [];
    if (mb_strlen($title) < 10) $errs[] = 'titre trop court';
    if ($words < max(400, (int)($s['min_words'] * 0.6))) $errs[] = "trop court ($words mots)";
    if (substr_count(strtolower($content), '<h2') < 3) $errs[] = 'moins de 3 H2';
    if (preg_match('/en tant qu.(ia|intelligence artificielle|assistant)|as an ai|je ne peux pas|\[(insérer|insert)|lorem ipsum|mise à jour de mes connaissances/iu', $content . $title)) $errs[] = 'formule IA / placeholder';
    $sig = topic_sig($title);
    foreach ($db->query("SELECT title FROM posts WHERE status='publish' ORDER BY published_at DESC LIMIT 3000")->fetchAll(PDO::FETCH_COLUMN) as $o) {
        if (similar_sig($sig, topic_sig($o)) >= 0.8) { $errs[] = 'doublon de « ' . $o . ' »'; break; }
    }
    if ($errs) {
        api_fail(['host' => $s['host'], 'topic_id' => $topicId, 'reason' => implode(', ', $errs)]);
        return ['ok' => false, 'errors' => $errs];
    }

    $slug = slugify((string)($p['slug'] ?? '') ?: $title, 70);
    $base = $slug; $i = 2;
    $chk = $db->prepare('SELECT 1 FROM posts WHERE slug=?');
    while (true) { $chk->execute([$slug]); if (!$chk->fetchColumn()) break; $slug = $base . '-' . $i++; }
    $date = now();
    $path = build_path($s, $slug, $date);

    $img = (string)($p['image'] ?? '');
    if ($img !== '' && preg_match('#^https?://#', $img)) $img = fetch_image($s, $img, $slug) ?: '';

    $faq = array_values(array_filter(array_map(fn($f) => ['q' => trim(strip_tags((string)($f['q'] ?? ''))), 'a' => trim(strip_tags((string)($f['a'] ?? '')))], (array)($p['faq'] ?? [])), fn($f) => $f['q'] !== '' && $f['a'] !== ''));
    $products = array_values(array_filter(array_map(fn($x) => ['q' => trim(strip_tags((string)($x['q'] ?? ''))), 'label' => trim(strip_tags((string)($x['label'] ?? ''))), 'why' => trim(strip_tags((string)($x['why'] ?? '')))], (array)($p['products'] ?? [])), fn($x) => $x['q'] !== ''));
    $cat = ensure_category($db, (string)($p['category'] ?? ''));
    $excerpt = trim(strip_tags((string)($p['excerpt'] ?? ''))) ?: mb_strimwidth(trim(strip_tags($content)), 0, 220, '…');

    $db->prepare('INSERT INTO posts(slug,path,title,excerpt,content,meta_title,meta_desc,category,tags,image,image_alt,image_credit,faq,products,keyword,words,status,published_at,updated_at,source,provider)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $slug, $path, $title, $excerpt, $content,
        mb_substr(trim(strip_tags((string)($p['meta_title'] ?? ''))) ?: $title, 0, 70), mb_substr(trim(strip_tags((string)($p['meta_desc'] ?? ''))), 0, 170),
        $cat, json_encode(array_slice((array)($p['tags'] ?? []), 0, 8), JSON_UNESCAPED_UNICODE), $img, mb_substr(strip_tags((string)($p['image_alt'] ?? $title)), 0, 150),
        mb_substr(strip_tags((string)($p['image_credit'] ?? '')), 0, 150), json_encode($faq, JSON_UNESCAPED_UNICODE), json_encode($products, JSON_UNESCAPED_UNICODE),
        mb_substr(trim((string)($p['keyword'] ?? '')), 0, 80), $words, 'publish', $date, $date, 'ai', mb_substr((string)($p['provider'] ?? ''), 0, 60),
    ]);
    $id = (int)$db->lastInsertId();
    if ($topicId) $db->prepare("UPDATE topics SET status='done', post_id=?, updated_at=? WHERE id=?")->execute([$id, now(), $topicId]);
    registry()->prepare('UPDATE sites SET last_post_at=? WHERE id=?')->execute([$date, $s['id']]);
    cache_clear($s['host']);
    $url = 'https://' . $s['host'] . $path;
    indexnow_ping($s, [$url, 'https://' . $s['host'] . '/']);
    flog($s['host'], 'post', "$title ($words mots, " . ($p['provider'] ?? '?') . ')');
    return ['ok' => true, 'id' => $id, 'url' => $url, 'words' => $words];
}

// Télécharge l'image (Pexels/Pixabay interdisent le hotlink permanent), redimensionne en WebP 1200px.
function fetch_image(array $s, string $url, string $slug): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => 'Mozilla/5.0 (fabrique)']);
    $bin = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!$bin || $code !== 200 || !function_exists('imagecreatefromstring')) return null;
    $im = @imagecreatefromstring($bin);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $nw = min(1200, $w); $nh = (int)round($h * $nw / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $dir = cfg('public_dir') . '/_m/' . $s['id'];
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . '/' . $slug . '.webp';
    $ok = imagewebp($dst, $file, 78);
    imagedestroy($im); imagedestroy($dst);
    return $ok ? '/_m/' . $s['id'] . '/' . $slug . '.webp' : null;
}

function api_fail(array $in): array
{
    $s = need_site($in);
    $db = site_db($s['host']);
    $id = (int)($in['topic_id'] ?? 0);
    if ($id) {
        $db->prepare("UPDATE topics SET attempts=attempts+1, status=CASE WHEN attempts+1>=3 THEN 'rejected' ELSE 'queued' END, note=?, updated_at=? WHERE id=?")
            ->execute([mb_substr((string)($in['reason'] ?? ''), 0, 300), now(), $id]);
    }
    flog($s['host'], 'fail', (string)($in['reason'] ?? ''));
    return ['ok' => true];
}

function api_stats(): array
{
    $out = [];
    foreach (registry()->query("SELECT * FROM sites WHERE status<>'deleted' ORDER BY host") as $s) $out[] = site_stats($s);
    return $out;
}

function site_stats(array $s): array
{
    $db = site_db($s['host']);
    $q = fn($sql) => $db->query($sql)->fetchColumn();
    return [
        'host' => $s['host'], 'status' => $s['status'], 'gen' => (int)$s['gen'], 'per_day' => (float)$s['per_day'],
        'posts' => (int)$q("SELECT COUNT(*) FROM posts WHERE status='publish'"),
        'posts_7d' => (int)$q("SELECT COUNT(*) FROM posts WHERE status='publish' AND published_at>=datetime('now','-7 days')"),
        'queued' => (int)$q("SELECT COUNT(*) FROM topics WHERE status='queued'"),
        'rejected' => (int)$q("SELECT COUNT(*) FROM topics WHERE status='rejected'"),
        'pv_7d' => (int)$q("SELECT COALESCE(SUM(pv),0) FROM stats WHERE day>=date('now','-7 days')"),
        'pv_30d' => (int)$q("SELECT COALESCE(SUM(pv),0) FROM stats WHERE day>=date('now','-30 days')"),
        'last_post' => $s['last_post_at'], 'next_gen' => $s['next_gen_at'],
    ];
}

// Article existant (pour l'enrichissement à partir de Search Console).
function api_post(array $in): array
{
    $s = need_site($in);
    $st = site_db($s['host'])->prepare("SELECT title, keyword, content, faq, updated_at FROM posts WHERE path=? AND status='publish'");
    $st->execute([(string)($in['path'] ?? '')]);
    $p = $st->fetch();
    if (!$p) throw new RuntimeException('article inconnu');
    preg_match_all('#<h2[^>]*>(.*?)</h2>#is', (string)$p['content'], $m);
    return ['title' => $p['title'], 'keyword' => $p['keyword'], 'h2' => array_map('strip_tags', $m[1]), 'text' => mb_substr(trim(strip_tags((string)$p['content'])), 0, 6000),
        'faq' => json_decode((string)$p['faq'], true) ?: [], 'updated_at' => $p['updated_at'], 'niche' => $s['niche']];
}

// Ajoute une ou plusieurs sections et des questions FAQ à un article existant, met à jour la date et relance l'indexation.
function api_enrich(array $in): array
{
    $s = need_site($in);
    $db = site_db($s['host']);
    $path = (string)($in['path'] ?? '');
    $st = $db->prepare("SELECT id, content, faq FROM posts WHERE path=? AND status='publish'");
    $st->execute([$path]);
    $p = $st->fetch();
    if (!$p) throw new RuntimeException('article inconnu');
    $add = sanitize_html((string)($in['html'] ?? ''));
    if (word_count($add) < 150 || stripos($add, '<h2') === false) throw new RuntimeException('complément trop court ou sans H2');
    if (preg_match('/en tant qu.(ia|intelligence artificielle|assistant)|as an ai|je ne peux pas|\[(insérer|insert)|lorem ipsum/iu', $add)) throw new RuntimeException('formule IA / placeholder');
    $content = (string)$p['content'];
    // avant la conclusion si elle existe, sinon à la fin
    if (preg_match('#<h2[^>]*>\s*(en )?(conclusion|pour conclure|en résumé|le mot de la fin)#iu', $content, $m, PREG_OFFSET_CAPTURE)) $content = substr($content, 0, $m[0][1]) . $add . substr($content, $m[0][1]);
    else $content .= $add;
    $faq = json_decode((string)$p['faq'], true) ?: [];
    $known = array_map(fn($f) => mb_strtolower($f['q']), $faq);
    foreach ((array)($in['faq'] ?? []) as $f) {
        $q = trim(strip_tags((string)($f['q'] ?? ''))); $a = trim(strip_tags((string)($f['a'] ?? '')));
        if ($q !== '' && $a !== '' && !in_array(mb_strtolower($q), $known, true)) { $faq[] = ['q' => $q, 'a' => $a]; $known[] = mb_strtolower($q); }
    }
    $db->prepare('UPDATE posts SET content=?, faq=?, words=?, updated_at=? WHERE id=?')->execute([$content, json_encode(array_slice($faq, 0, 12), JSON_UNESCAPED_UNICODE), word_count($content), now(), $p['id']]);
    cache_clear($s['host']);
    indexnow_ping($s, ['https://' . $s['host'] . $path]);
    flog($s['host'], 'enrich', $path . ' +' . word_count($add) . ' mots (' . implode(', ', array_slice((array)($in['queries'] ?? []), 0, 5)) . ')');
    return ['ok' => true, 'words' => word_count($content)];
}
