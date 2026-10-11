<?php
// Robot @LeRecruteurOffresBot : abonnement en message privé par département (+ métier), /chercher, mode inline,
// messages éditoriaux des chaînes régionales, boutons de partage. Données : data/alerts.sqlite (tables tg_subs, ev).
declare(strict_types=1);

const TG_BOT_USER = 'LeRecruteurOffresBot';

function tgb_db(): PDO
{
    $db = alerts_db();
    static $ok = false;
    if (!$ok) {
        $db->exec("CREATE TABLE IF NOT EXISTS tg_subs(chat_id INTEGER PRIMARY KEY, dept TEXT DEFAULT '', kw TEXT DEFAULT '', state TEXT DEFAULT 'dept',
            status TEXT DEFAULT 'active', created_at TEXT, last_sent_at TEXT, next_at TEXT, sent INTEGER DEFAULT 0);
            CREATE INDEX IF NOT EXISTS ix_tgsubs_due ON tg_subs(status, next_at);");
        $ok = true;
    }
    return $db;
}

// Départements : code => nom (DEPTS si chargé, sinon liste minimale vide)
function tgb_depts(): array { return defined('DEPTS') ? DEPTS : []; }
function tgb_dept_find(string $s): string
{
    $s = trim($s);
    $c = strtoupper(preg_replace('/\s+/', '', $s));
    if ($c === '2A' || $c === '2B' || preg_match('/^(97[1-6]|0?[1-9]|[1-8]\d|9[0-5])$/', $c)) { $c = strlen($c) === 1 ? '0' . $c : $c; if (isset(tgb_depts()[$c])) return $c; }
    $sl = slugify($s);
    foreach (tgb_depts() as $code => $name) if (slugify($name) === $sl) return (string)$code;
    foreach (tgb_depts() as $code => $name) if ($sl !== '' && str_starts_with(slugify($name), $sl) && strlen($sl) >= 4) return (string)$code;
    return '';
}
function tgb_dept_name(string $c): string { return (string)(tgb_depts()[$c] ?? $c); }
function tgb_postal_prefix(string $c): string { return in_array($c, ['2A', '2B'], true) ? '20' : $c; }
function tgb_dept_of_postal(string $p): string
{
    $p = trim($p); if (strlen($p) < 2) return '';
    if (str_starts_with($p, '97')) return substr($p, 0, 3);
    if (str_starts_with($p, '20')) return ((int)$p < 20200) ? '2A' : '2B';
    return substr($p, 0, 2);
}
function tgb_start_link(string $dept, string $mslug = ''): string
{
    $p = $dept !== '' ? 'd_' . $dept . ($mslug !== '' && strlen($mslug) <= 56 && preg_match('/^[a-z0-9-]+$/', $mslug) ? '__' . $mslug : '') : '';
    return 'https://t.me/' . TG_BOT_USER . ($p !== '' ? '?start=' . $p : '');
}

// Offres d'un département (+ mot-clé), ouvertes et indexables, depuis $since.
function tgb_offers(array $site, string $dept, string $kw, ?string $since, int $limit = 8): array
{
    $db = jobs_db($site['host']);
    $w = "status='open' AND " . JOB_INDEXABLE_SQL . " AND postal LIKE ?"; $a = [tgb_postal_prefix($dept) . '%'];
    if ($since) { $w .= ' AND created_at > ?'; $a[] = $since; }
    foreach (array_slice(array_filter(explode(' ', $kw), fn($x) => mb_strlen($x) >= 3), 0, 3) as $k) { $w .= ' AND title LIKE ?'; $a[] = '%' . $k . '%'; }
    $st = $db->prepare("SELECT COUNT(*) FROM jobs WHERE $w"); $st->execute($a); $n = (int)$st->fetchColumn();
    if (!$n) return [0, []];
    $st = $db->prepare("SELECT slug,title,company,city,contract_label,salary FROM jobs WHERE $w ORDER BY (salary<>'') DESC, created_at DESC LIMIT $limit"); $st->execute($a);
    return [$n, $st->fetchAll()];
}
function tgb_list(array $site, array $jobs, string $utm): string
{
    $o = ''; $base = 'https://' . $site['host'];
    foreach ($jobs as $j) {
        $meta = implode(' · ', array_filter([$j['city'], $j['contract_label'], $j['salary'] ? preg_replace('/\s+sur\s+\d+.*$/u', '', (string)$j['salary']) : '']));
        $o .= "\n• <a href=\"" . h($base . '/offre/' . $j['slug'] . '/?' . $utm) . '">' . h($j['title']) . '</a>' . ($j['company'] ? ' — ' . h($j['company']) : '') . ($meta ? "\n   <i>" . h($meta) . '</i>' : '');
    }
    return $o;
}
function tgb_send(int|string $chat, string $text, array $extra = []): array
{
    return tg_bot('sendMessage', ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000), 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]] + $extra);
}

// ---------- Webhook ----------
function tgb_webhook(array $site): void
{
    http_response_code(200); header('Content-Type: application/json');
    $sec = (string)setting('tg_webhook_secret', '');
    if ($sec === '' || !hash_equals($sec, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) { http_response_code(403); echo '{}'; return; }
    $u = json_decode((string)file_get_contents('php://input'), true) ?: [];
    try {
        if (isset($u['inline_query'])) tgb_inline($site, $u['inline_query']);
        elseif (isset($u['message']['text']) && ($u['message']['chat']['type'] ?? '') === 'private') tgb_message($site, $u['message']);
        elseif (isset($u['my_chat_member']) && ($u['my_chat_member']['chat']['type'] ?? '') === 'private' && ($u['my_chat_member']['new_chat_member']['status'] ?? '') === 'kicked') {
            tgb_db()->prepare("UPDATE tg_subs SET status='blocked' WHERE chat_id=?")->execute([(int)$u['my_chat_member']['chat']['id']]); alerts_ev('tgbot_bloque');
        }
    } catch (Throwable $e) { error_log('tgbot ' . $e->getMessage()); }
    echo '{}';
}

function tgb_help(): string
{
    return "Commandes :\n/departement — choisir ou changer de département\n/metier cariste — ne recevoir qu'un métier\n/tous — toutes les offres du département\n/chercher aide-soignante Caen — rechercher des offres maintenant\n/stop — ne plus recevoir les offres\n\nAstuce : dans n'importe quelle conversation, tapez @" . TG_BOT_USER . " cariste Caen pour partager une offre.";
}

function tgb_message(array $site, array $m): void
{
    $chat = (int)$m['chat']['id']; $t = trim((string)$m['text']);
    $db = tgb_db();
    $st = $db->prepare('SELECT * FROM tg_subs WHERE chat_id=?'); $st->execute([$chat]); $s = $st->fetch() ?: null;
    $save = function (array $f) use ($db, $chat, &$s) {
        if (!$s) { $db->prepare("INSERT INTO tg_subs(chat_id,created_at,next_at) VALUES(?,?,?)")->execute([$chat, now(), now()]); $s = ['chat_id' => $chat, 'dept' => '', 'kw' => '', 'state' => 'dept', 'status' => 'active']; alerts_ev('tgbot_inscription'); }
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
        $db->prepare("UPDATE tg_subs SET $sets WHERE chat_id=?")->execute([...array_values($f), $chat]);
        $s = array_merge($s, $f);
    };
    [$cmd, $arg] = array_pad(preg_split('/\s+/', $t, 2), 2, '');
    $cmd = strtolower(preg_replace('/@.*$/', '', $cmd));
    $kb = ['reply_markup' => ['keyboard' => [[['text' => '/chercher'], ['text' => '/metier']], [['text' => '/departement'], ['text' => '/stop']]], 'resize_keyboard' => true, 'is_persistent' => false]];
    $ask = fn() => tgb_send($chat, "Dans quel département cherchez-vous ?\nEnvoyez son numéro ou son nom, par exemple <b>14</b> ou <b>Calvados</b>.");
    $confirm = function () use ($chat, &$s, $kb) {
        tgb_send($chat, "✅ C'est noté : vous recevrez chaque matin les <b>nouvelles offres" . ($s['kw'] !== '' ? ' « ' . h($s['kw']) . ' »' : '') . '</b> – <b>' . h(tgb_dept_name($s['dept'])) . ' (' . h($s['dept']) . ")</b>, seulement s'il y en a.\n\n"
            . ($s['kw'] === '' ? "Pour un seul métier : /metier suivi du métier (ex. /metier cariste).\n" : "Toutes les offres du département : /tous\n") . 'Autres commandes : /aide', $kb);
    };
    switch ($cmd) {
        case '/start':
            $d = preg_match('/^d_([0-9]{2,3}|2A|2B)(?:__([a-z0-9-]{2,56}))?$/i', $arg, $x) ? tgb_dept_find($x[1]) : '';
            if ($d !== '') {
                $kw = '';
                if (!empty($x[2])) { // lien depuis une page métier : filtre sur l'intitulé du métier
                    try { $jdb = jobs_db($site['host']); $q = $jdb->prepare('SELECT name FROM job_metiers WHERE slug=?'); $q->execute([strtolower($x[2])]); $kw = mb_substr((string)$q->fetchColumn(), 0, 50); } catch (Throwable $e) { $kw = ''; }
                }
                $save(['dept' => $d, 'kw' => $kw, 'state' => 'ok', 'status' => 'active', 'next_at' => now()]); $confirm(); alerts_ev($kw !== '' ? 'tgbot_start_metier' : 'tgbot_start_site'); return;
            }
            $save(['state' => 'dept', 'status' => 'active']);
            tgb_send($chat, "Bonjour 👋 Je vous envoie chaque matin les nouvelles offres d'emploi de votre département, avec le lien pour postuler sur " . h($site['name']) . ".\n");
            $ask(); return;
        case '/departement': case '/département': $save(['state' => 'dept']); $ask(); return;
        case '/metier': case '/métier':
            if ($arg === '') { $save(['state' => 'kw']); tgb_send($chat, 'Quel métier ? Par exemple : cariste, aide-soignant, comptable.'); return; }
            $save(['kw' => mb_substr(trim(preg_replace('/[^\p{L}\p{N} \'-]/u', ' ', $arg)), 0, 50), 'state' => $s && $s['dept'] !== '' ? 'ok' : 'dept']);
            if ($s['dept'] === '') { $ask(); return; } $confirm(); return;
        case '/tous': $save(['kw' => '', 'state' => $s && $s['dept'] !== '' ? 'ok' : 'dept']); if ($s['dept'] === '') { $ask(); return; } $confirm(); return;
        case '/stop':
            if ($s && $s['status'] === 'active') { $save(['status' => 'unsub']); alerts_ev('tgbot_stop'); }
            tgb_send($chat, "Vous ne recevrez plus d'offres. Pour reprendre à tout moment : /start", ['reply_markup' => ['remove_keyboard' => true]]); return;
        case '/aide': case '/help': tgb_send($chat, tgb_help()); return;
        case '/chercher':
            if ($arg === '') { tgb_send($chat, 'Exemple : /chercher aide-soignante Caen'); return; }
            alerts_ev('tgbot_recherche');
            $r = tgb_search($site, $arg, 6);
            tgb_send($chat, $r ? '🔎 <b>' . h($arg) . "</b>\n" . tgb_list($site, $r, 'utm_source=telegram&utm_medium=bot&utm_campaign=recherche') . "\n\n👉 <a href=\"" . h('https://' . $site['host'] . '/chercher/?q=' . rawurlencode($arg)) . '">Plus de résultats sur le site</a>' : 'Aucune offre trouvée pour « ' . h($arg) . ' ». Essayez un autre métier ou une autre ville.');
            return;
    }
    // texte libre selon l'étape
    if (!$s || $s['state'] === 'dept') {
        $d = tgb_dept_find($t);
        if ($d === '') { tgb_send($chat, "Je n'ai pas reconnu ce département. Envoyez son numéro (ex. 14) ou son nom (ex. Calvados)."); return; }
        $save(['dept' => $d, 'state' => 'kw', 'next_at' => now()]);
        tgb_send($chat, 'Parfait : <b>' . h(tgb_dept_name($d)) . "</b>.\nUn métier en particulier ? Envoyez-le (ex. <b>cariste</b>), ou /tous pour toutes les offres du département.");
        return;
    }
    if ($s['state'] === 'kw') { $save(['kw' => mb_substr(trim(preg_replace('/[^\p{L}\p{N} \'-]/u', ' ', $t)), 0, 50), 'state' => 'ok']); $confirm(); return; }
    // déjà abonné : on traite le texte comme une recherche
    alerts_ev('tgbot_recherche');
    $r = tgb_search($site, $t, 6);
    tgb_send($chat, $r ? '🔎 <b>' . h($t) . "</b>\n" . tgb_list($site, $r, 'utm_source=telegram&utm_medium=bot&utm_campaign=recherche') : "Aucune offre trouvée. Exemple : /chercher cariste Caen\n" . '/aide pour les commandes');
}

// Recherche « métier lieu » : chaque mot doit apparaître dans le titre ou la ville (ou être un département).
function tgb_search(array $site, string $q, int $limit): array
{
    $db = jobs_db($site['host']);
    $w = "status='open' AND " . JOB_INDEXABLE_SQL; $a = [];
    $words = array_slice(array_filter(preg_split('/[\s,]+/u', mb_strtolower($q)), fn($x) => mb_strlen($x) >= 2), 0, 5);
    if (!$words) return [];
    foreach ($words as $x) {
        $d = preg_match('/^(\d{2,3}|2a|2b)$/', $x) ? tgb_dept_find($x) : '';
        if ($d !== '') { $w .= ' AND postal LIKE ?'; $a[] = tgb_postal_prefix($d) . '%'; continue; }
        $w .= ' AND (title LIKE ? OR city LIKE ?)'; $a[] = "%$x%"; $a[] = "%$x%";
    }
    $st = $db->prepare("SELECT slug,title,company,city,contract_label,salary FROM jobs WHERE $w ORDER BY created_at DESC LIMIT $limit"); $st->execute($a);
    return $st->fetchAll();
}

function tgb_inline(array $site, array $iq): void
{
    $q = trim((string)($iq['query'] ?? ''));
    $res = [];
    if (mb_strlen($q) >= 3) {
        alerts_ev('tgbot_inline');
        foreach (tgb_search($site, $q, 10) as $j) {
            $u = 'https://' . $site['host'] . '/offre/' . $j['slug'] . '/?utm_source=telegram&utm_medium=inline';
            $meta = implode(' · ', array_filter([$j['company'], $j['city'], $j['contract_label']]));
            $res[] = ['type' => 'article', 'id' => substr(md5($j['slug']), 0, 20), 'title' => mb_substr($j['title'], 0, 100), 'description' => mb_substr($meta, 0, 150), 'url' => $u,
                'input_message_content' => ['message_text' => '💼 <b>' . h($j['title']) . '</b>' . ($meta ? "\n" . h($meta) : '') . "\n" . h($u), 'parse_mode' => 'HTML']];
        }
    }
    tg_bot('answerInlineQuery', ['inline_query_id' => $iq['id'], 'results' => $res, 'cache_time' => 600,
        'button' => ['text' => 'Recevoir les offres de mon département', 'start_parameter' => 'inline']]);
}

// ---------- Récapitulatif quotidien en message privé ----------
function tgb_daily_run(array $site): array
{
    $db = tgb_db(); $r = ['sent' => 0, 'none' => 0, 'blocked' => 0, 'err' => 0];
    if (setting('tg_on', '1') !== '1') return $r + ['off' => true];
    $st = $db->prepare("SELECT * FROM tg_subs WHERE status='active' AND state='ok' AND dept<>'' AND next_at <= ? ORDER BY next_at LIMIT 2000"); $st->execute([now()]);
    $upd = $db->prepare('UPDATE tg_subs SET last_sent_at=?, next_at=?, sent=sent+1 WHERE chat_id=?');
    $skip = $db->prepare('UPDATE tg_subs SET next_at=? WHERE chat_id=?');
    $next = gmdate('Y-m-d H:i:s', time() + 86400 - 1800);
    foreach ($st->fetchAll() as $s) {
        [$n, $jobs] = tgb_offers($site, $s['dept'], (string)$s['kw'], $s['last_sent_at'] ?: gmdate('Y-m-d H:i:s', time() - 2 * 86400));
        if (!$n) { $skip->execute([$next, $s['chat_id']]); $r['none']++; continue; }
        $what = ($s['kw'] !== '' ? '« ' . h($s['kw']) . ' » ' : '') . '– ' . h(tgb_dept_name($s['dept'])) . ' (' . h($s['dept']) . ')';
        $more = 'https://' . $site['host'] . '/chercher/?' . http_build_query(['q' => $s['kw'], 'l' => tgb_dept_name($s['dept'])]) . '&utm_source=telegram&utm_medium=bot';
        $t = '🆕 <b>' . $n . ' nouvelle' . ($n > 1 ? 's offres' : ' offre') . ' ' . $what . '</b>' . tgb_list($site, $jobs, 'utm_source=telegram&utm_medium=bot&utm_campaign=quotidien')
            . ($n > count($jobs) ? "\n\n👉 <a href=\"" . h($more) . '">Voir les ' . $n . ' offres</a>' : '') . "\n\n<i>/metier pour filtrer · /stop pour arrêter</i>";
        $res = tgb_send((int)$s['chat_id'], $t);
        if (!empty($res['ok'])) { $upd->execute([now(), $next, $s['chat_id']]); $r['sent']++; alerts_ev('tgbot_envoi'); }
        elseif (($res['error_code'] ?? 0) === 403) { $db->prepare("UPDATE tg_subs SET status='blocked' WHERE chat_id=?")->execute([$s['chat_id']]); $r['blocked']++; alerts_ev('tgbot_bloque'); }
        elseif (($res['error_code'] ?? 0) === 429) { sleep((int)($res['parameters']['retry_after'] ?? 5)); $r['err']++; }
        else { $skip->execute([gmdate('Y-m-d H:i:s', time() + 3600), $s['chat_id']]); $r['err']++; }
        usleep(60000); // ~16 messages/s, sous la limite Telegram (30/s)
    }
    return $r;
}

// ---------- Messages éditoriaux (chaînes régionales) ----------
function tgb_editorial_run(array $site, string $kind): array
{
    $out = []; $jdb = jobs_db($site['host']); $base = 'https://' . $site['host'];
    $week = (int)date('W');
    foreach (tg_channels() as $key => $chat) {
        $regs = $key === 'outre-mer' ? TG_OUTREMER : array_keys(array_filter(JOB_REGIONS, fn($r) => $r[0] === $key));
        if (!$regs) continue;
        $name = $key === 'outre-mer' ? 'Outre-mer' : JOB_REGIONS[$regs[0]][1];
        $in = implode(',', array_fill(0, count($regs), '?'));
        $utm = 'utm_source=telegram&utm_medium=chaine&utm_campaign=edito_' . $key;
        $rlink = $key === 'outre-mer' ? $base . '/offres-emploi/?' . $utm : $base . '/offres-emploi/' . $key . '/?' . $utm;
        if ($kind === 'lundi') {
            $st = $jdb->prepare("SELECT m.slug, m.name, COUNT(*) n FROM jobs j JOIN job_metier_map mm ON mm.id=j.id JOIN job_metiers m ON m.slug=mm.mslug
                WHERE j.status='open' AND j.region IN ($in) AND j.created_at > datetime('now','-7 days') GROUP BY m.slug ORDER BY n DESC LIMIT 5");
            $st->execute($regs); $top = $st->fetchAll();
            if (!$top) { $out[$key] = 0; continue; }
            $sal = $jdb->prepare('SELECT med FROM job_metier_sal WHERE slug=?');
            $t = '📊 <b>Les métiers qui recrutent le plus cette semaine en ' . h($name) . "</b>\n";
            foreach ($top as $i => $m) {
                $sal->execute([$m['slug']]); $med = (float)$sal->fetchColumn();
                $t .= "\n" . ($i + 1) . '. <a href="' . h($base . '/emploi/' . $m['slug'] . '/?' . $utm) . '">' . h($m['name']) . '</a> — ' . (int)$m['n'] . ' nouvelles offres' . ($med > 0 ? ' · salaire médian ' . number_format($med, 0, ',', ' ') . ' € brut/mois' : '');
            }
            $st = $jdb->prepare("SELECT city, COUNT(*) n FROM jobs WHERE status='open' AND region IN ($in) AND city<>'' AND created_at > datetime('now','-7 days') GROUP BY city ORDER BY n DESC LIMIT 1");
            $st->execute($regs); $c = $st->fetch();
            if ($c) $t .= "\n\n🏙️ Ville qui recrute le plus : <b>" . h($c['city']) . '</b> (' . (int)$c['n'] . ' offres)';
            $t .= "\n\n👉 <a href=\"" . h($rlink) . '">Toutes les offres en ' . h($name) . "</a>\n📈 <a href=\"" . h($base . '/barometre-emploi/?' . $utm) . '">Baromètre de l\'emploi</a>';
        } else {
            $mode = $week % 4;
            if ($mode === 0) {
                $st = $jdb->prepare("SELECT m.slug, m.name, s.med, s.p25, s.p75, COUNT(*) n FROM jobs j JOIN job_metier_map mm ON mm.id=j.id JOIN job_metiers m ON m.slug=mm.mslug JOIN job_metier_sal s ON s.slug=m.slug
                    WHERE j.status='open' AND j.region IN ($in) GROUP BY m.slug ORDER BY n DESC LIMIT 1 OFFSET " . (intdiv($week, 4) % 10));
                $st->execute($regs); $m = $st->fetch();
                if (!$m) { $out[$key] = 0; continue; }
                $f = fn($v) => number_format((float)$v, 0, ',', ' ');
                $t = '💶 <b>Combien gagne un ' . h(mb_strtolower($m['name'])) . " ?</b>\n\nD'après les offres publiées : salaire médian <b>" . $f($m['med']) . ' € brut/mois</b> (la moitié des offres entre ' . $f($m['p25']) . ' et ' . $f($m['p75']) . " €).\n" . (int)$m['n'] . ' offres ouvertes en ' . h($name) . ".\n\n👉 <a href=\"" . h($base . '/salaire/' . $m['slug'] . '/?' . $utm) . '">Salaires détaillés</a> · <a href="' . h($base . '/emploi/' . $m['slug'] . '/?' . $utm) . '">Les offres</a>';
            } else {
                [$tag, $title, $path, $sql] = [
                    1 => ['🎓', 'Alternance', 'alternance', "contract='alternance'"],
                    2 => ['🏛️', 'Emploi public', 'emploi-public', "id LIKE 'csp-%'"],
                    3 => ['🙌', 'Sans diplôme exigé', 'emploi-sans-diplome', "id IN (SELECT id FROM job_intent_map WHERE intent='emploi-sans-diplome')"],
                ][$mode];
                try { $st = $jdb->prepare("SELECT COUNT(*) FROM jobs WHERE status='open' AND region IN ($in) AND $sql"); $st->execute($regs); $n = (int)$st->fetchColumn(); } catch (Throwable $e) { $n = 0; }
                if ($n < 3) { $out[$key] = 0; continue; }
                $st = $jdb->prepare("SELECT slug,title,company,city,contract_label,salary FROM jobs WHERE status='open' AND " . JOB_INDEXABLE_SQL . " AND region IN ($in) AND $sql ORDER BY created_at DESC LIMIT 4");
                try { $st->execute($regs); $jobs = $st->fetchAll(); } catch (Throwable $e) { $jobs = []; }
                $plink = $key === 'outre-mer' ? $base . '/' . $path . '/?' . $utm : $base . '/' . $path . '/' . $key . '/?' . $utm;
                $t = $tag . ' <b>' . $title . ' en ' . h($name) . ' : ' . number_format($n, 0, ',', ' ') . " offres ouvertes</b>\n" . tgb_list($site, $jobs, $utm) . "\n\n👉 <a href=\"" . h($plink) . '">Toutes les offres « ' . mb_strtolower($title) . ' »</a>';
            }
        }
        $r = tgb_send($chat, $t);
        $ok = !empty($r['ok']); alerts_ev($ok ? 'tg_edito' : 'tg_echec');
        $out[$key] = $ok ? 'ok' : ('erreur: ' . ($r['description'] ?? '?'));
        sleep(2);
    }
    return $out;
}

// ---------- Site : liens publics (balisage sameAs), boutons partage et département ----------
function tg_public_links(): array
{
    $l = json_decode((string)setting('tg_links', ''), true) ?: [];
    $o = array_values(array_filter($l, fn($u) => !str_contains((string)$u, '/+')));
    if (setting('tg_bot_token', '') !== '') $o[] = 'https://t.me/' . TG_BOT_USER;
    return $o;
}
function share_box(array $site, array $j): string
{
    $u = 'https://' . $site['host'] . '/offre/' . $j['slug'] . '/';
    $t = $j['title'] . ($j['city'] ? ' — ' . $j['city'] : '');
    $btn = 'display:inline-block;margin:4px 6px 0 0;padding:8px 12px;border-radius:8px;color:#fff;text-decoration:none;font-weight:600;font-size:14px';
    return '<div class="box"><h4 style="margin-top:0">Partager cette offre</h4>'
        . '<a rel="nofollow noopener" target="_blank" style="' . $btn . ';background:#229ED9" href="https://t.me/share/url?url=' . rawurlencode($u . '?utm_source=partage&utm_medium=telegram') . '&text=' . rawurlencode($t) . '">Telegram</a>'
        . '<a rel="nofollow noopener" target="_blank" style="' . $btn . ';background:#25D366" href="https://wa.me/?text=' . rawurlencode($t . ' ' . $u . '?utm_source=partage&utm_medium=whatsapp') . '">WhatsApp</a></div>';
}
function tgb_dept_button(string $dept, string $mslug = '', string $mname = ''): string
{
    if ($dept === '' || setting('tg_bot_token', '') === '' || !isset(tgb_depts()[$dept])) return '';
    $what = $mname !== '' ? mb_strtolower($mname) . ' – ' . tgb_dept_name($dept) : tgb_dept_name($dept);
    return '<p style="margin:10px 0 0"><a href="' . h(tgb_start_link($dept, $mname !== '' ? $mslug : '')) . '" target="_blank" rel="nofollow noopener" style="font-weight:600">🔔 Recevoir les offres ' . h($what) . ' sur Telegram</a></p>';
}

// Menu des départements d'une région (pages régions) : chaque choix ouvre le robot sur le bon département.
function tgb_dept_select(string $reg): string
{
    if ($reg === '' || setting('tg_bot_token', '') === '' || !defined('CSP_DEP_REG') || !isset(CSP_DEP_REG[$reg])) return '';
    $deps = array_values(array_filter(explode(' ', CSP_DEP_REG[$reg]), fn($d) => isset(tgb_depts()[$d])));
    if (!$deps) return '';
    if (count($deps) === 1) return tgb_dept_button($deps[0]);
    $opt = ''; $li = '';
    foreach ($deps as $d) { $u = h(tgb_start_link($d)); $n = h(tgb_dept_name($d) . ' (' . $d . ')'); $opt .= '<option value="' . $u . '">' . $n . '</option>'; $li .= '<li><a href="' . $u . '" target="_blank" rel="nofollow noopener">' . $n . '</a></li>'; }
    return '<div style="margin:12px 0 0"><label style="display:block;font-weight:600;margin:0 0 6px">🔔 Recevoir les offres de mon département sur Telegram</label>'
        . '<select aria-label="Choisir mon département" onchange="if(this.value){window.open(this.value,\'_blank\',\'noopener\');this.selectedIndex=0}" style="width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid var(--b);border-radius:8px;font:inherit"><option value="">Choisir mon département…</option>' . $opt . '</select>'
        . '<noscript><ul style="margin:6px 0 0;padding-left:18px">' . $li . '</ul></noscript></div>';
}
