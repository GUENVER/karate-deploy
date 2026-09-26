<?php
// Interface d'administration unique : sites (ajout / pause / suppression), sujets, articles, réglages.
declare(strict_types=1);

require_once __DIR__ . '/api.php';

function admin_main(string $path): void
{
    session_name('fab_admin');
    session_set_cookie_params(['httponly' => true, 'secure' => is_https(), 'samesite' => 'Lax', 'path' => '/_admin']);
    session_start();
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    $route = trim(substr($path, 7), '/');

    if ($route === 'logout') { session_destroy(); header('Location: /_admin/'); return; }
    if (empty($_SESSION['ok'])) { admin_login(); return; }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); echo 'CSRF'; return; }

    switch (true) {
        case $route === '': admin_dashboard(); return;
        case $route === 'new': admin_site_form(null); return;
        case $route === 'settings': admin_settings(); return;
        case $route === 'log': admin_log(); return;
        case $route === 'niches': admin_niches(); return;
        case (bool)preg_match('#^site/([a-z0-9.\-]+)(?:/(edit|delete|topics|toggle|run))?$#', $route, $m):
            $s = site_by_host($m[1]);
            if (!$s) { admin_page('Introuvable', '<p>Site inconnu.</p>'); return; }
            match ($m[2] ?? '') {
                'edit' => admin_site_form($s),
                'delete' => admin_site_delete($s),
                'topics' => admin_topics_add($s),
                'toggle' => admin_toggle($s),
                'run' => admin_run_now($s),
                default => admin_site($s),
            };
            return;
    }
    http_response_code(404);
    admin_page('404', '<p>Page inconnue.</p>');
}

function is_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }

function csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function admin_login(): void
{
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        usleep(400000);
        if (password_verify((string)($_POST['p'] ?? ''), (string)cfg('admin_pass_hash'))) {
            session_regenerate_id(true);
            $_SESSION['ok'] = 1;
            setcookie('fab_admin', '1', ['expires' => time() + 86400 * 30, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
            header('Location: /_admin/'); return;
        }
        $err = '<p class="err">Mot de passe incorrect.</p>';
    }
    admin_page('Connexion', $err . '<form method="post" class="box" style="max-width:360px"><label>Mot de passe<input type="password" name="p" autofocus></label><button>Entrer</button></form>', false);
}

function admin_page(string $title, string $body, bool $nav = true): void
{
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($title) . ' · Fabrique</title><style>
body{margin:0;font:15px/1.5 system-ui,sans-serif;background:#f4f5f7;color:#1d2327}a{color:#0b5cad}.top{background:#1d2327;color:#fff;padding:10px 20px;display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.top a{color:#fff;text-decoration:none}.top b{margin-right:auto}.w{max-width:1250px;margin:0 auto;padding:20px}h1{font-size:1.4rem}
table{border-collapse:collapse;width:100%;background:#fff;font-size:.9rem}th,td{padding:7px 9px;border-bottom:1px solid #e3e5e8;text-align:left;vertical-align:top}th{background:#fafbfc;font-weight:600}
.box{background:#fff;border:1px solid #e3e5e8;border-radius:10px;padding:18px;margin:14px 0}label{display:block;margin:10px 0;font-weight:600}input,textarea,select{width:100%;padding:8px;border:1px solid #cfd3d8;border-radius:6px;font:inherit;margin-top:4px;font-weight:400}
textarea{min-height:90px}button,.btn{background:#0b6e4f;color:#fff;border:0;border-radius:6px;padding:8px 14px;cursor:pointer;font:inherit;text-decoration:none;display:inline-block}.btn.g{background:#6c757d}.btn.r,button.r{background:#b32d2e}
.err{color:#b32d2e;font-weight:600}.ok{color:#0b6e4f;font-weight:600}.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}.tag{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.78rem;background:#e7f3ee;color:#0b6e4f}.tag.off{background:#fbeaea;color:#b32d2e}
.kpi{display:flex;gap:14px;flex-wrap:wrap}.kpi div{background:#fff;border:1px solid #e3e5e8;border-radius:10px;padding:12px 18px}.kpi b{display:block;font-size:1.5rem}.inline{display:inline}.inline button{padding:4px 10px;font-size:.85rem}
</style></head><body>' . ($nav ? '<div class="top"><b>🏭 Fabrique à sites</b><a href="/_admin/">Sites</a><a href="/_admin/new">+ Ajouter un site</a><a href="/_admin/niches">Idées de niches</a><a href="/_admin/log">Journal</a><a href="/_admin/settings">Réglages</a><a href="/_admin/logout">Quitter</a></div>' : '')
        . '<div class="w"><h1>' . h($title) . '</h1>' . $body . '</div></body></html>';
}

function admin_dashboard(): void
{
    $rows = ''; $tot = ['posts' => 0, 'posts_7d' => 0, 'pv_7d' => 0, 'pv_30d' => 0, 'queued' => 0];
    // revenus AdSense poussés chaque jour par le hub (clé = domaine AdSense, avec ou sans www)
    $ads = json_decode((string)setting('adsense_report', ''), true) ?: [];
    $earn = fn(array $rep, string $host) => array_sum(array_map(fn($r) => in_array(preg_replace('/^www\./', '', $r['domain']), [preg_replace('/^www\./', '', $host)], true) ? (float)$r['earnings'] : 0, $rep['sites'] ?? []));
    foreach (registry()->query("SELECT * FROM sites WHERE status<>'deleted' ORDER BY host") as $s) {
        $st = site_stats($s);
        foreach ($tot as $k => $v) $tot[$k] += $st[$k];
        $rows .= '<tr><td><a href="/_admin/site/' . h($s['host']) . '"><b>' . h($s['host']) . '</b></a><br><small>' . h($s['name']) . '</small></td>'
            . '<td>' . ($s['status'] === 'active' ? '<span class="tag">en ligne</span>' : '<span class="tag off">' . h($s['status']) . '</span>') . ' ' . ($s['gen'] ? '<span class="tag">IA ' . (float)$s['per_day'] . '/j</span>' : '<span class="tag off">IA off</span>') . '</td>'
            . '<td>' . $st['posts'] . ' <small>(+' . $st['posts_7d'] . ' /7j)</small></td><td>' . $st['queued'] . '</td><td>' . $st['pv_7d'] . '</td><td>' . $st['pv_30d'] . '</td>'
            . '<td>' . number_format($earn($ads['d7'] ?? [], $s['host']), 2, ',', ' ') . ' €</td><td>' . number_format($earn($ads['d30'] ?? [], $s['host']), 2, ',', ' ') . ' €</td>'
            . '<td><small>' . h((string)$st['last_post']) . '</small></td>'
            . '<td><a href="https://' . h($s['host']) . '/" target="_blank">voir</a> · <a href="/_admin/site/' . h($s['host']) . '/edit">modifier</a></td></tr>';
    }
    // fabriques distantes (config 'remotes' : [['api_base'=>…, 'token'=>…]]) : sites hébergés sur un autre compte (ex. santé sur le hub)
    foreach ((array)cfg('remotes', []) as $rm) {
        $ch = curl_init(rtrim($rm['api_base'], '/') . '/_api/stats');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => ['X-Fabrique-Token: ' . $rm['token']]]);
        $list = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $adm = rtrim($rm['api_base'], '/') . '/_admin';
        if (!is_array($list) || isset($list['error'])) { $rows .= '<tr><td colspan="10"><span class="tag off">fabrique distante injoignable</span> ' . h($rm['api_base']) . '</td></tr>'; continue; }
        foreach ($list as $st) {
            foreach ($tot as $k => $v) $tot[$k] += (int)($st[$k] ?? 0);
            $rows .= '<tr><td><a href="' . h($adm) . '/site/' . h($st['host']) . '" target="_blank"><b>' . h($st['host']) . '</b></a><br><small>fabrique du hub ↗</small></td>'
                . '<td>' . ($st['status'] === 'active' ? '<span class="tag">en ligne</span>' : '<span class="tag off">' . h($st['status']) . '</span>') . ' ' . ($st['gen'] ? '<span class="tag">IA ' . (float)$st['per_day'] . '/j</span>' : '<span class="tag off">IA off</span>') . '</td>'
                . '<td>' . (int)$st['posts'] . ' <small>(+' . (int)$st['posts_7d'] . ' /7j)</small></td><td>' . (int)$st['queued'] . '</td><td>' . (int)$st['pv_7d'] . '</td><td>' . (int)$st['pv_30d'] . '</td>'
                . '<td>' . number_format($earn($ads['d7'] ?? [], $st['host']), 2, ',', ' ') . ' €</td><td>' . number_format($earn($ads['d30'] ?? [], $st['host']), 2, ',', ' ') . ' €</td>'
                . '<td><small>' . h((string)$st['last_post']) . '</small></td>'
                . '<td><a href="https://' . h($st['host']) . '/" target="_blank">voir</a> · <a href="' . h($adm) . '/site/' . h($st['host']) . '/edit" target="_blank">modifier</a></td></tr>';
        }
    }
    $kpi = '<div class="kpi"><div><b>' . $tot['posts'] . '</b>articles</div><div><b>' . $tot['posts_7d'] . '</b>publiés /7j</div><div><b>' . $tot['queued'] . '</b>sujets en file</div><div><b>' . $tot['pv_7d'] . '</b>pages vues /7j</div><div><b>' . $tot['pv_30d'] . '</b>pages vues /30j</div>'
        . '<div><b>' . number_format((float)($ads['d7']['total'] ?? 0), 2, ',', ' ') . ' €</b>AdSense /7j (compte)</div><div><b>' . number_format((float)($ads['d30']['total'] ?? 0), 2, ',', ' ') . ' €</b>AdSense /30j (compte)</div></div>'
        . (isset($ads['at']) ? '<p><small>Revenus AdSense estimés, mis à jour le ' . h($ads['at']) . ' (tous domaines du compte, y compris hors fabrique).</small></p>' : '<p><small>Revenus AdSense : en attente de la première synchronisation du hub.</small></p>');
    admin_page('Sites', $kpi . '<div class="box" style="padding:0;overflow:auto"><table><tr><th>Site</th><th>État</th><th>Articles</th><th>File</th><th>PV 7j</th><th>PV 30j</th><th>AdSense 7j</th><th>AdSense 30j</th><th>Dernier article</th><th></th></tr>' . $rows . '</table></div>'
        . '<p><a class="btn" href="/_admin/new">+ Ajouter un site</a></p>');
}

function admin_site_form(?array $s): void
{
    $msg = '';
    $roots = (array)cfg('cpanel_domains', []);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $f = $_POST;
        $f['sub'] = strtolower((string)preg_replace('/\..*$/', '', trim((string)($f['sub'] ?? '')))); // « jardin.decouverte.org » → « jardin »
        $host = $s['host'] ?? strtolower(trim(($f['sub'] ?? '') !== '' ? $f['sub'] . '.' . $f['root'] : ($f['host'] ?? '')));
        $host = preg_replace('/[^a-z0-9.\-]/', '', $host);
        $data = [
            'name' => trim($f['name'] ?? ''), 'tagline' => trim($f['tagline'] ?? ''), 'niche' => trim($f['niche'] ?? ''),
            'color' => $f['color'] ?? '#0b6e4f', 'per_day' => max(0, (float)($f['per_day'] ?? 3)), 'min_words' => max(300, (int)($f['min_words'] ?? 1200)),
            'seeds' => trim($f['seeds'] ?? ''), 'adsense' => isset($f['adsense']) ? 1 : 0, 'amazon' => isset($f['amazon']) ? 1 : 0,
            'gen' => isset($f['gen']) ? 1 : 0, 'ymyl' => isset($f['ymyl']) ? 1 : 0, 'jobs' => isset($f['jobs']) ? 1 : 0, 'fuel' => isset($f['fuel']) ? 1 : 0, 'ev' => isset($f['ev']) ? 1 : 0, 'permalink' => trim($f['permalink'] ?? '/%slug%/') ?: '/%slug%/', 'lang' => 'fr',
        ];
        if (!preg_match('/^[a-z0-9\-]+(\.[a-z0-9\-]+)+$/', $host) || $data['name'] === '') $msg = '<p class="err">Hôte ou nom invalide.</p>';
        elseif ($s) {
            $sets = implode(',', array_map(fn($k) => "$k=:$k", array_keys($data)));
            registry()->prepare("UPDATE sites SET $sets WHERE id=:id")->execute($data + ['id' => $s['id']]);
            cache_clear($s['host']);
            header('Location: /_admin/site/' . $s['host']); return;
        } elseif (site_by_host($host)) $msg = '<p class="err">Ce site existe déjà.</p>';
        else {
            $cols = array_keys($data);
            registry()->prepare('INSERT INTO sites(host,' . implode(',', $cols) . ',status,created_at,next_gen_at) VALUES(:host,:' . implode(',:', $cols) . ",'active',:c,:c2)")
                ->execute($data + ['host' => $host, 'c' => now(), 'c2' => now()]);
            $db = site_db($host);
            foreach (array_filter(array_map('trim', explode(',', (string)($f['categories'] ?? '')))) as $c) ensure_category($db, $c);
            $cp = '';
            if (($f['sub'] ?? '') !== '' && in_array($f['root'] ?? '', $roots, true)) $cp = cpanel_add_subdomain($f['sub'], $f['root']);
            flog($host, 'site', 'création' . ($cp ? " ; cPanel : $cp" : ''));
            header('Location: /_admin/site/' . $host . '?created=1'); return;
        }
    }
    $v = fn($k, $d = '') => h((string)($_POST[$k] ?? $s[$k] ?? $d));
    $ck = fn($k, $d = 1) => (int)($_POST ? isset($_POST[$k]) : ($s[$k] ?? $d)) ? 'checked' : '';
    $hostField = $s ? '<p>Hôte : <b>' . h($s['host']) . '</b></p>' : '<div class="row"><label>Sous-domaine<input name="sub" placeholder="jardin" value="' . $v('sub') . '"></label><label>Domaine<select name="root">'
        . implode('', array_map(fn($r) => '<option' . (($_POST['root'] ?? '') === $r ? ' selected' : '') . '>' . h($r) . '</option>', $roots)) . '</select></label>'
        . '<label>…ou hôte complet déjà pointé<input name="host" placeholder="www.exemple.fr" value="' . $v('host') . '"></label></div>';
    admin_page($s ? 'Modifier ' . $s['host'] : 'Ajouter un site', $msg . '<form method="post" class="box">' . csrf() . $hostField
        . '<div class="row"><label>Nom du site<input name="name" value="' . $v('name') . '" placeholder="Jardin Facile"></label><label>Accroche<input name="tagline" value="' . $v('tagline') . '" placeholder="Le potager sans prise de tête"></label><label>Couleur<input type="color" name="color" value="' . $v('color', '#0b6e4f') . '"></label></div>'
        . '<label>Thématique / ligne éditoriale (utilisée par l\'IA)<textarea name="niche" placeholder="Jardinage et potager pour débutants en France : calendrier des semis, entretien, maladies, outils…">' . $v('niche') . '</textarea></label>'
        . '<label>Mots-clés graines (un par ligne : l\'IA et Google Suggest partent de là)<textarea name="seeds" placeholder="potager débutant&#10;quand planter tomates&#10;compost maison">' . $v('seeds') . '</textarea></label>'
        . ($s ? '' : '<label>Rubriques (séparées par des virgules, optionnel : l\'IA en crée sinon)<input name="categories" value="' . $v('categories') . '" placeholder="Potager, Fleurs, Outils, Calendrier"></label>')
        . '<div class="row"><label>Articles IA par jour<input type="number" step="0.5" min="0" name="per_day" value="' . $v('per_day', '3') . '"></label><label>Longueur cible (mots)<input type="number" name="min_words" value="' . $v('min_words', '1200') . '"></label><label>Permaliens<input name="permalink" value="' . $v('permalink', '/%slug%/') . '"></label></div>'
        . '<p><label class="inline"><input type="checkbox" name="gen" ' . $ck('gen') . ' style="width:auto"> Génération IA automatique</label> &nbsp; <label class="inline"><input type="checkbox" name="adsense" ' . $ck('adsense') . ' style="width:auto"> AdSense</label> &nbsp; <label class="inline"><input type="checkbox" name="amazon" ' . $ck('amazon') . ' style="width:auto"> Affiliation Amazon</label> &nbsp; <label class="inline"><input type="checkbox" name="fuel" ' . $ck('fuel', 0) . ' style="width:auto"> Prix des carburants</label> &nbsp; <label class="inline"><input type="checkbox" name="ev" ' . $ck('ev', 0) . ' style="width:auto"> Bornes de recharge</label> &nbsp; <label class="inline"><input type="checkbox" name="jobs" ' . $ck('jobs', 0) . ' style="width:auto"> Agrégateur d\'offres d\'emploi</label> &nbsp; <label class="inline"><input type="checkbox" name="ymyl" ' . $ck('ymyl', 0) . ' style="width:auto"> Thème sensible (santé/argent : ton prudent, sources officielles)</label></p>'
        . '<button>' . ($s ? 'Enregistrer' : 'Créer le site') . '</button></form>');
}

function admin_site(array $s): void
{
    $db = site_db($s['host']);
    $st = site_stats($s);
    $msg = isset($_GET['created']) ? '<p class="ok">Site créé. La génération démarre au prochain passage du générateur (≤ 10 min). Le certificat SSL peut prendre quelques minutes.</p>' : '';
    if (isset($_GET['ran'])) $msg = '<p class="ok">Le site est prioritaire : un article sera écrit au prochain passage du générateur.</p>';
    $posts = $db->query("SELECT id,path,title,words,provider,source,published_at,views FROM posts WHERE status='publish' ORDER BY published_at DESC LIMIT 30")->fetchAll();
    $topics = $db->query("SELECT keyword,title,category,status,attempts,note FROM topics WHERE status IN ('queued','writing','rejected') ORDER BY status='rejected', priority DESC, id LIMIT 60")->fetchAll();
    $kpi = '<div class="kpi"><div><b>' . $st['posts'] . '</b>articles</div><div><b>' . $st['posts_7d'] . '</b>publiés /7j</div><div><b>' . $st['queued'] . '</b>sujets en file</div><div><b>' . $st['pv_7d'] . '</b>PV /7j</div><div><b>' . $st['pv_30d'] . '</b>PV /30j</div></div>';
    $act = '<p><a class="btn" href="https://' . h($s['host']) . '/" target="_blank">Voir le site</a> <a class="btn g" href="/_admin/site/' . h($s['host']) . '/edit">Modifier</a> '
        . '<form class="inline" method="post" action="/_admin/site/' . h($s['host']) . '/toggle">' . csrf() . '<button class="g">' . ($s['status'] === 'active' ? 'Mettre en pause' : 'Remettre en ligne') . '</button></form> '
        . '<form class="inline" method="post" action="/_admin/site/' . h($s['host']) . '/run">' . csrf() . '<button>Écrire un article maintenant</button></form> '
        . '<a class="btn r" href="/_admin/site/' . h($s['host']) . '/delete">Supprimer</a></p>';
    $pt = implode('', array_map(fn($p) => '<tr><td><a href="https://' . h($s['host'] . $p['path']) . '" target="_blank">' . h($p['title']) . '</a></td><td>' . (int)$p['words'] . '</td><td>' . h($p['provider'] ?: $p['source']) . '</td><td>' . (int)$p['views'] . '</td><td><small>' . h($p['published_at']) . '</small></td></tr>', $posts));
    $tt = implode('', array_map(fn($t) => '<tr><td>' . h($t['keyword']) . '<br><small>' . h($t['title']) . '</small></td><td>' . h($t['category']) . '</td><td>' . h($t['status']) . ($t['attempts'] ? ' (' . $t['attempts'] . ')' : '') . '<br><small>' . h($t['note']) . '</small></td></tr>', $topics));
    admin_page($s['name'] . ' — ' . $s['host'], $msg . $kpi . $act
        . '<div class="box" style="padding:0;overflow:auto"><table><tr><th>Derniers articles</th><th>Mots</th><th>IA</th><th>Vues</th><th>Date</th></tr>' . $pt . '</table></div>'
        . '<form method="post" action="/_admin/site/' . h($s['host']) . '/topics" class="box">' . csrf() . '<label>Ajouter des sujets (un mot-clé par ligne, prioritaires)<textarea name="topics"></textarea></label><button>Ajouter à la file</button></form>'
        . '<div class="box" style="padding:0;overflow:auto"><table><tr><th>Sujets en file</th><th>Rubrique</th><th>État</th></tr>' . $tt . '</table></div>');
}

function admin_topics_add(array $s): void
{
    $lines = array_filter(array_map('trim', explode("\n", (string)($_POST['topics'] ?? ''))));
    $r = api_backlog(['host' => $s['host'], 'topics' => array_map(fn($k) => ['keyword' => $k, 'priority' => 90], $lines)]);
    header('Location: /_admin/site/' . $s['host']);
}

function admin_toggle(array $s): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        registry()->prepare('UPDATE sites SET status=? WHERE id=?')->execute([$s['status'] === 'active' ? 'paused' : 'active', $s['id']]);
        cache_clear($s['host']);
    }
    header('Location: /_admin/site/' . $s['host']);
}

function admin_run_now(array $s): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') registry()->prepare('UPDATE sites SET next_gen_at=? WHERE id=?')->execute([gmdate('Y-m-d H:i:s', time() - 1), $s['id']]);
    header('Location: /_admin/site/' . $s['host'] . '?ran=1');
}

function admin_site_delete(array $s): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === $s['host']) {
        $note = '';
        if (!empty($_POST['cpanel'])) {
            foreach ((array)cfg('cpanel_domains', []) as $root) {
                if (str_ends_with($s['host'], '.' . $root)) { $note = cpanel_del_subdomain($s['host']); break; }
            }
        }
        registry()->prepare("UPDATE sites SET status='deleted', gen=0, host=? WHERE id=?")->execute([$s['host'] . '.deleted-' . time(), $s['id']]);
        registry()->prepare('DELETE FROM aliases WHERE host=?')->execute([$s['host']]);
        $f = cfg('data_dir') . '/sites/' . $s['host'] . '.sqlite';
        if (is_file($f)) @rename($f, $f . '.deleted-' . date('Ymd-His'));
        cache_clear($s['host']);
        flog($s['host'], 'site', 'suppression' . ($note ? " ; cPanel : $note" : ''));
        header('Location: /_admin/'); return;
    }
    admin_page('Supprimer ' . $s['host'], '<form method="post" class="box">' . csrf() . '<p>La base est archivée (renommée), pas effacée. Tapez <b>' . h($s['host']) . '</b> pour confirmer.</p><input name="confirm">'
        . '<p><label class="inline"><input type="checkbox" name="cpanel" value="1" style="width:auto"> Supprimer aussi le sous-domaine cPanel</label></p><button class="r">Supprimer</button></form>');
}

function admin_settings(): void
{
    $keys = ['adsense_pub' => 'Éditeur AdSense (ca-pub-…)', 'adsense_slot_in_article' => 'ID de bloc AdSense « in-article » (optionnel, sinon annonces automatiques seules)',
        'amazon_tag' => 'Identifiant partenaire Amazon (ex. monsite-21)', 'ga4_id' => 'Google Analytics 4 (G-…, optionnel)', 'contact_email' => 'E-mail de contact affiché ({domaine} = domaine du site, ex. contact@{domaine})',
        'editor_name' => 'Nom de l\'éditeur (mentions légales ; vide = éditeur anonyme)', 'ads_txt_extra' => 'Lignes ads.txt supplémentaires (autres régies)',
        'ft_client_id' => 'Offres d\'emploi — France Travail : identifiant client (francetravail.io)', 'ft_client_secret' => 'Offres d\'emploi — France Travail : clé secrète',
        'adzuna_app_id' => 'Offres d\'emploi — Adzuna : app_id (developer.adzuna.com)', 'adzuna_app_key' => 'Offres d\'emploi — Adzuna : app_key'];
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($keys as $k => $_) setting_set($k, trim((string)($_POST[$k] ?? '')));
        foreach (registry()->query("SELECT host FROM sites")->fetchAll(PDO::FETCH_COLUMN) as $h) cache_clear($h);
        $msg = '<p class="ok">Enregistré.</p>';
    }
    $f = '';
    foreach ($keys as $k => $l) $f .= '<label>' . h($l) . ($k === 'ads_txt_extra' ? '<textarea name="' . $k . '">' . h(setting($k, '')) . '</textarea>' : '<input name="' . $k . '" value="' . h(setting($k, $k === 'adsense_pub' ? 'ca-pub-8104956615440701' : '')) . '">') . '</label>';
    admin_page('Réglages', $msg . '<form method="post" class="box">' . csrf() . $f . '<button>Enregistrer</button></form>');
}

function admin_niches(): void
{
    $r = json_decode((string)setting('niche_report', ''), true);
    if (!$r) { admin_page('Idées de niches', '<p>Aucune analyse pour le moment : la recherche automatique tourne le 1er de chaque mois sur le hub.</p>'); return; }
    $roots = (array)cfg('cpanel_domains', []);
    $o = '<p>Analyse du ' . h($r['at']) . ' — ' . (int)$r['candidats'] . ' niches évaluées (demande Google Suggest, concurrence de la 1re page, données publiques, revenu estimé). Les 5 meilleures :</p>';
    foreach ($r['top'] ?? [] as $c) {
        $m = $c['mesures'] ?? [];
        $hidden = '';
        foreach (['sub' => $c['sub'] ?? '', 'root' => $roots[0] ?? '', 'name' => $c['name'] ?? '', 'tagline' => $c['tagline'] ?? '', 'niche' => $c['niche'] ?? '',
            'seeds' => implode("\n", (array)($c['seeds'] ?? [])), 'categories' => implode(', ', (array)($c['categories'] ?? [])), 'per_day' => '3', 'min_words' => '1200', 'gen' => '1', 'adsense' => '1', 'amazon' => '1'] as $k => $v)
            $hidden .= '<input type="hidden" name="' . $k . '" value="' . h((string)$v) . '">';
        $o .= '<div class="box"><h2 style="margin:0">' . h($c['name'] ?? '') . ' <span class="tag">score ' . (int)$c['score'] . '/100</span></h2>'
            . '<p><b>' . h($c['tagline'] ?? '') . '</b> — ' . h($c['niche'] ?? '') . '</p><p><small>' . h($c['pourquoi'] ?? '') . '</small></p>'
            . '<p><small>Demande : ' . (int)($m['suggestions'] ?? 0) . ' suggestions Google · 1re page : ' . (int)($m['sites_autorite'] ?? 0) . ' sites d\'autorité / ' . (int)($m['forums_ugc'] ?? 0) . ' forums sur ' . (int)($m['resultats_analyses'] ?? 0)
            . ' · données publiques : ' . (int)($m['jeux_datagouv'] ?? 0) . ' jeux' . (!empty($c['donnees']) ? ' (' . h($c['donnees']) . ')' : '') . ' · revenu estimé ' . h((string)($m['rpm'] ?? '?')) . ' € / 1000 pages</small></p>'
            . '<p><small>Graines : ' . h(implode(', ', (array)($c['seeds'] ?? []))) . '</small></p>'
            . '<form method="post" action="/_admin/new">' . csrf() . $hidden . '<button>Créer ' . h(($c['sub'] ?? '') . '.' . ($roots[0] ?? '')) . '</button></form></div>';
    }
    if (!empty($r['autres'])) $o .= '<div class="box"><b>Autres idées :</b> ' . implode(', ', array_map(fn($c) => h($c['name']) . ' (' . (int)$c['score'] . ')', $r['autres'])) . '</div>';
    admin_page('Idées de niches', $o);
}

function admin_log(): void
{
    $rows = registry()->query('SELECT * FROM log ORDER BY id DESC LIMIT 200')->fetchAll();
    admin_page('Journal', '<div class="box" style="padding:0"><table><tr><th>Date (UTC)</th><th>Site</th><th>Type</th><th>Message</th></tr>'
        . implode('', array_map(fn($r) => '<tr><td><small>' . h($r['at']) . '</small></td><td>' . h($r['host']) . '</td><td>' . h($r['kind']) . '</td><td>' . h($r['msg']) . '</td></tr>', $rows)) . '</table></div>');
}

// --- cPanel (UAPI en ligne de commande, exécuté sous l'utilisateur du compte) ---
function uapi(string $module, string $func, array $args): array
{
    $cmd = 'uapi --output=json ' . escapeshellarg($module) . ' ' . escapeshellarg($func);
    foreach ($args as $k => $v) $cmd .= ' ' . escapeshellarg("$k=$v");
    $out = @shell_exec($cmd . ' 2>&1');
    $j = json_decode((string)$out, true);
    return $j['result'] ?? ['status' => 0, 'errors' => [trim((string)$out) ?: 'uapi indisponible']];
}

function cpanel_add_subdomain(string $sub, string $root): string
{
    $sub = preg_replace('/[^a-z0-9\-]/', '', strtolower($sub));
    $home = getenv('HOME') ?: dirname(FAB_ROOT);
    $rel = ltrim(substr(cfg('public_dir'), strlen(rtrim($home, '/'))), '/');
    $r = uapi('SubDomain', 'addsubdomain', ['domain' => $sub, 'rootdomain' => $root, 'dir' => $rel, 'disallowdot' => 0]);
    if (empty($r['status'])) return 'erreur ' . implode(' ', (array)($r['errors'] ?? []));
    // Pas d'AutoSSL chez o2switch : certificat Let's Encrypt émis en tâche de fond (délai d'activation du sous-domaine, puis 2e essai).
    $host = $sub . '.' . $root;
    $cmd = 'cd ' . escapeshellarg(FAB_ROOT) . ' && export HOME=' . escapeshellarg($home) . ' && (sleep 90; nice -n 19 bash cli/ssl.sh issue ' . escapeshellarg($host) . ' ' . escapeshellarg(cfg('public_dir'))
        . ' || (sleep 300; nice -n 19 bash cli/ssl.sh issue ' . escapeshellarg($host) . ' ' . escapeshellarg(cfg('public_dir')) . ')) >> data/ssl_new.log 2>&1';
    @shell_exec('nohup bash -c ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &');
    return 'sous-domaine créé, certificat HTTPS en cours (~2 min)';
}

function cpanel_del_subdomain(string $host): string
{
    // UAPI n'expose pas la suppression : API2 via cpapi2.
    $bin = is_executable('/usr/local/cpanel/bin/cpapi2') ? '/usr/local/cpanel/bin/cpapi2' : 'cpapi2';
    $out = @shell_exec($bin . ' --output=json SubDomain delsubdomain domain=' . escapeshellarg($host) . ' 2>&1');
    return str_contains((string)$out, '"result":1') ? 'sous-domaine supprimé' : 'suppression cPanel à vérifier';
}
