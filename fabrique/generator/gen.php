<?php
// Générateur de contenus de la fabrique — tourne sur le hub (clés IA et images restent sur le hub).
// Récupère les tâches (/_api/jobs), écrit avec les IA gratuites en rotation, publie (/_api/ingest).
// Usage : php gen.php [--max=4] [--time=230] [--host=x.y.z]
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && !defined('FAB_GEN_WEB')) exit;

define('MCP_LIB_MODE', true);
require '/home3/guenver/mcp.guenver.com/mcp.php';
$CFG = require '/home3/guenver/mcp.guenver.com/config.php';
$G = require __DIR__ . '/gen-config.php';

$opt = getopt('', ['max::', 'time::', 'host::']);
$max = (int)($opt['max'] ?? 4);
$deadline = time() + (int)($opt['time'] ?? 230);

$lock = fopen(__DIR__ . '/gen.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) { echo date('c') . " déjà en cours\n"; exit; }

function logx(string $m): void { echo date('c') . ' ' . $m . "\n"; }

function fab(string $route, ?array $body = null, array $q = [])
{
    global $G;
    $ch = curl_init(rtrim($G['api_base'], '/') . '/_api/' . $route . ($q ? '?' . http_build_query($q) : ''));
    $h = ['X-Fabrique-Token: ' . $G['api_token'], 'Content-Type: application/json'];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $h, CURLOPT_USERAGENT => 'fabrique-gen']);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode((string)$r, true);
    if ($code !== 200 || !is_array($j)) throw new RuntimeException("fabrique $route HTTP $code " . substr((string)$r, 0, 200));
    return $j;
}

// Rotation des fournisseurs gratuits ; en cas d'échec, la chaîne de secours du hub prend le relais.
function llm(string $prompt, string $system, int $maxTok = 8000): array
{
    global $CFG, $G;
    $st = @json_decode((string)@file_get_contents(__DIR__ . '/state.json'), true) ?: ['i' => 0];
    $provs = $G['providers'];
    $p = $provs[$st['i'] % count($provs)];
    $st['i']++;
    @file_put_contents(__DIR__ . '/state.json', json_encode($st));
    $tries = [[$p, $G['models'][$p] ?? null], [null, null]];
    $last = null;
    foreach ($tries as [$prov, $model]) {
        try {
            $o = ['max_tokens' => $maxTok];
            if ($prov) $o['provider'] = $prov;
            if ($model) $o['model'] = $model;
            $r = ai_llm_request($prompt, $system, $o, $CFG);
            if (trim((string)$r['text']) !== '') return ['text' => $r['text'], 'provider' => ($prov ?: 'chaine') . ':' . ($r['model'] ?? '')];
        } catch (Throwable $e) { $last = $e; logx("  LLM $prov échec : " . substr($e->getMessage(), 0, 160)); }
    }
    throw new RuntimeException('LLM indisponible : ' . ($last ? $last->getMessage() : 'réponse vide'));
}

function suggest(string $q): array
{
    $u = 'https://suggestqueries.google.com/complete/search?client=firefox&hl=fr&gl=fr&ie=utf-8&oe=utf-8&q=' . rawurlencode($q);
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return array_slice((array)($r[1] ?? []), 0, 10);
}

function do_backlog(array $site): void
{
    $seeds = $site['seeds'] ?: [$site['niche']];
    shuffle($seeds);
    $sug = [];
    foreach (array_slice($seeds, 0, 6) as $s) {
        foreach (array_merge([$s], ['comment ' . $s, $s . ' pourquoi']) as $q) $sug = array_merge($sug, suggest(mb_substr($q, 0, 60)));
        usleep(300000);
    }
    $sug = array_values(array_unique($sug));
    $cats = implode(', ', array_column($site['categories'], 'name')) ?: '(à créer : 5 à 8 rubriques larges)';
    $existing = implode("\n", array_slice($site['existing'] ?? [], -250));
    $prompt = "Site : {$site['name']} — thématique : {$site['niche']}\nRubriques existantes : $cats\n\n"
        . "Requêtes réellement tapées dans Google (Google Suggest) :\n" . implode("\n", $sug) . "\n\n"
        . "Sujets DÉJÀ traités (ne pas reproposer, même reformulés) :\n$existing\n\n"
        . "Propose 30 nouveaux sujets d'articles distincts, à forte probabilité de trafic Google en France : requêtes longue traîne à intention informationnelle "
        . "(comment, pourquoi, quel, combien, quand, liste, comparatif, erreurs à éviter, calendrier, idées), faible concurrence, contenu durable (pas d'actualité). "
        . "Varie les rubriques. Chaque sujet doit pouvoir faire un article complet et utile.\n"
        . "Réponds UNIQUEMENT par un tableau JSON : [{\"keyword\":\"requête cible exacte\",\"title\":\"titre SEO accrocheur (<65 car.)\",\"category\":\"rubrique\",\"intent\":\"info|comparatif|liste|tuto\",\"cluster\":\"groupe thématique\",\"priority\":1-100}]";
    $r = llm($prompt, 'Tu es un expert SEO francophone spécialisé en recherche de mots-clés. Tu réponds en JSON valide uniquement.', 6000);
    $txt = $r['text'];
    $a = strpos($txt, '['); $b = strrpos($txt, ']');
    $topics = ($a !== false && $b > $a) ? json_decode(substr($txt, $a, $b - $a + 1), true) : null;
    if (!is_array($topics)) throw new RuntimeException('backlog : JSON illisible');
    $res = fab('backlog', ['host' => $site['host'], 'topics' => $topics]);
    logx("  backlog {$site['host']} : +{$res['added']} ({$res['duplicates']} doublons) via {$r['provider']}");
}

function section(string $txt, string $name): string
{
    return preg_match('/===\s*' . $name . '\s*===\s*(.*?)(?====\s*[A-Z_]+\s*===|\z)/s', $txt, $m) ? trim($m[1]) : '';
}

function do_article(array $site, array $t): void
{
    $cats = implode(', ', array_column($site['categories'], 'name'));
    $recent = implode("\n", array_slice($site['recent_titles'] ?? [], 0, 25));
    $words = max(800, (int)$site['min_words']);
    $ymyl = $site['ymyl'] ? "\nSUJET SENSIBLE : ton prudent, aucune promesse de résultat, renvoie vers un professionnel quand c'est pertinent, cite des organismes officiels (HAS, ameli, service-public.fr…) sans inventer d'URL." : '';
    $year = date('Y');
    $prompt = "Site : {$site['name']} ({$site['niche']}). Année : $year.\nRequête Google ciblée : « {$t['keyword']} »\nTitre suggéré : {$t['title']}\nRubriques : $cats\n"
        . "Articles récents du site (ne pas les répéter) :\n$recent\n\n"
        . "Rédige l'article de référence en français qui répond MIEUX que les 10 premiers résultats Google à cette requête.\n"
        . "- $words à " . ($words + 600) . " mots, HTML simple : <h2>, <h3>, <p>, <ul>/<ol>, <table>, <strong>. Pas de <h1>, pas de CSS, pas de liens.\n"
        . "- 1er paragraphe : réponse directe et complète en 2-3 phrases (extrait optimisé pour la position zéro), qui contient la requête.\n"
        . "- 5 à 8 <h2> formulés comme les questions que se posent les internautes ; au moins une liste à étapes et un tableau récapitulatif.\n"
        . "- Concret, précis, actionnable : exemples, chiffres d'ordre de grandeur prudents, erreurs fréquentes, astuces. Aucun remplissage, aucune généralité creuse.\n"
        . "- N'invente JAMAIS de statistique précise, d'étude, de citation, de témoignage, ni de test personnel (« nous avons testé »). Pas de phrase du type « en tant qu'IA ».\n"
        . "- Termine par un <h2> de synthèse.$ymyl\n\n"
        . "Réponds EXACTEMENT dans ce format (sections obligatoires) :\n"
        . "===TITLE===\n(titre H1 accrocheur, 50-65 caractères, contient la requête)\n"
        . "===META_TITLE===\n(balise title, ≤ 60 caractères, incitative)\n"
        . "===META_DESC===\n(meta description 140-155 caractères, bénéfice + incitation au clic)\n"
        . "===SLUG===\n(slug court, 3-6 mots)\n"
        . "===CATEGORY===\n(une rubrique existante de préférence)\n"
        . "===EXCERPT===\n(résumé 1-2 phrases)\n"
        . "===KEYWORD===\n(expression-clé principale 2-5 mots, pour le maillage interne)\n"
        . "===IMAGE_QUERY===\n(3-4 mots EN ANGLAIS pour une photo d'illustration)\n"
        . "===CONTENT===\n(le HTML de l'article)\n"
        . "===FAQ===\n(4 à 6 lignes au format : Q: question ? || R: réponse de 2-3 phrases)\n"
        . "===PRODUCTS===\n(0 à 4 lignes au format : recherche Amazon précise | libellé court | en quoi c'est utile pour le lecteur. Uniquement des produits réellement utiles au sujet ; laisse vide si aucun n'a de sens)\n";
    $r = llm($prompt, "Tu es un rédacteur web expert en SEO et en {$site['niche']}. Tu écris un français naturel, clair et précis.", 12000);
    $x = $r['text'];
    $content = section($x, 'CONTENT');
    $content = preg_replace('/^```(?:html)?\s*|\s*```$/m', '', $content);
    if ($content === '') throw new RuntimeException('réponse sans CONTENT');
    $faq = [];
    foreach (preg_split('/\n+/', section($x, 'FAQ')) as $l) {
        if (preg_match('/Q\s*:\s*(.+?)\s*\|\|\s*R\s*:\s*(.+)/u', $l, $m)) $faq[] = ['q' => trim($m[1]), 'a' => trim($m[2])];
    }
    $products = [];
    foreach (preg_split('/\n+/', section($x, 'PRODUCTS')) as $l) {
        $pp = array_map('trim', explode('|', trim($l, "-* \t")));
        if (count($pp) >= 2 && mb_strlen($pp[0]) > 2 && !preg_match('/aucun|vide|n\/a/i', $pp[0])) $products[] = ['q' => $pp[0], 'label' => $pp[1], 'why' => $pp[2] ?? ''];
    }
    $imgq = section($x, 'IMAGE_QUERY') ?: $t['keyword'];
    [$img, $credit] = find_image($imgq);
    $post = [
        'title' => section($x, 'TITLE') ?: $t['title'], 'meta_title' => section($x, 'META_TITLE'), 'meta_desc' => section($x, 'META_DESC'),
        'slug' => section($x, 'SLUG'), 'category' => section($x, 'CATEGORY') ?: $t['category'], 'excerpt' => section($x, 'EXCERPT'),
        'keyword' => section($x, 'KEYWORD') ?: $t['keyword'], 'content' => $content, 'faq' => $faq, 'products' => $products,
        'image' => $img, 'image_alt' => section($x, 'TITLE') ?: $t['title'], 'image_credit' => $credit, 'provider' => $r['provider'],
    ];
    $payload = ['host' => $site['host'], 'topic_id' => $t['id'], 'post' => $post];
    try { $res = fab('ingest', $payload); }
    catch (Throwable $e) { outbox_put($payload); logx('  ⏸ publication différée (fabrique indisponible)'); return; }
    if (!empty($res['ok'])) logx("  ✔ {$res['url']} ({$res['words']} mots, {$r['provider']})");
    else logx('  ✘ rejeté : ' . implode(', ', $res['errors'] ?? []) . " ({$r['provider']})");
}

function http_json(string $url, array $headers = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'Mozilla/5.0']);
    $r = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    return is_array($r) ? $r : null;
}

function find_image(string $q): array
{
    global $CFG;
    // clés du hub : chaîne ou tableau {key|access_key, enabled}
    $k = [];
    foreach ((array)($CFG['apis'] ?? []) as $name => $v) {
        if (is_array($v)) $v = !empty($v['enabled']) || !isset($v['enabled']) ? (string)($v['key'] ?? $v['access_key'] ?? '') : '';
        $k[$name] = (string)$v;
    }
    $q = trim(mb_substr($q, 0, 60));
    if (!empty($k['pexels'])) {
        $r = http_json('https://api.pexels.com/v1/search?per_page=15&orientation=landscape&query=' . rawurlencode($q), ['Authorization: ' . $k['pexels']]);
        if (!empty($r['photos'])) { $p = $r['photos'][array_rand($r['photos'])]; return [$p['src']['large2x'] ?? $p['src']['large'], 'Photo : ' . $p['photographer'] . ' / Pexels']; }
    }
    if (!empty($k['pixabay'])) {
        $r = http_json('https://pixabay.com/api/?image_type=photo&orientation=horizontal&per_page=15&safesearch=true&key=' . rawurlencode($k['pixabay']) . '&q=' . rawurlencode($q));
        if (!empty($r['hits'])) { $p = $r['hits'][array_rand($r['hits'])]; return [$p['largeImageURL'], 'Image : ' . $p['user'] . ' / Pixabay']; }
    }
    if (!empty($k['unsplash'])) {
        $r = http_json('https://api.unsplash.com/search/photos?per_page=15&orientation=landscape&query=' . rawurlencode($q), ['Authorization: Client-ID ' . $k['unsplash']]);
        if (!empty($r['results'])) { $p = $r['results'][array_rand($r['results'])]; return [$p['urls']['regular'], 'Photo : ' . $p['user']['name'] . ' / Unsplash']; }
    }
    return ['', ''];
}

// Articles rédigés mais non publiés (fabrique saturée) : conservés et republiés au passage suivant.
function outbox_put(array $payload): void
{
    $d = __DIR__ . '/outbox';
    if (!is_dir($d)) mkdir($d, 0700, true);
    file_put_contents($d . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json', json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function outbox_flush(): void
{
    foreach (array_slice(glob(__DIR__ . '/outbox/*.json') ?: [], 0, 10) as $f) {
        $payload = json_decode((string)file_get_contents($f), true);
        try { $res = fab('ingest', $payload); } catch (Throwable $e) { logx('outbox : fabrique toujours indisponible'); return; }
        @unlink($f);
        logx('outbox ' . (!empty($res['ok']) ? '✔ ' . $res['url'] : '✘ ' . implode(', ', $res['errors'] ?? [])));
    }
}

// --- boucle principale ---
outbox_flush();
$done = 0;
while ($done < $max && time() < $deadline - 60) {
    $jobs = fab('jobs', null, array_filter(['limit' => min(3, $max - $done), 'host' => $opt['host'] ?? null]))['jobs'] ?? [];
    if (!$jobs) break;
    foreach ($jobs as $j) {
        if (time() >= $deadline - 60) break 2;
        $site = $j['site'];
        try {
            logx("{$j['type']} {$site['host']}" . ($j['type'] === 'article' ? " « {$j['topic']['keyword']} »" : ''));
            if ($j['type'] === 'backlog') do_backlog($site);
            else do_article($site, $j['topic']);
        } catch (Throwable $e) {
            logx('  erreur : ' . substr($e->getMessage(), 0, 200));
            if ($j['type'] === 'article') { try { fab('fail', ['host' => $site['host'], 'topic_id' => $j['topic']['id'], 'reason' => substr($e->getMessage(), 0, 250)]); } catch (Throwable $e2) {} }
        }
        $done++;
    }
}
logx("fin : $done tâche(s)");
