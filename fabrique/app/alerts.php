<?php
// Alertes e-mail et chaînes Telegram (sites avec agrégateur d'emploi, ex. recruteur.eu).
// - inscription sans confirmation (choix de l'éditeur), protections anti-abus : piège à robots, jeton JS, 3 inscriptions/IP/jour,
//   domaine vérifié (MX), adresses jetables refusées ; désinscription en un clic (lien + en-têtes List-Unsubscribe RFC 8058) ;
// - envois PLAFONNÉS : alerts_day_max (150) et alerts_hour_max (30) dans les réglages, interrupteur alerts_on (1/0) ;
// - Telegram : une chaîne par région (réglage tg_channels JSON {"normandie":"@...", "outre-mer":"@..."}), jeton tg_bot_token.
declare(strict_types=1);

const ALERT_DISPOSABLE = ['yopmail.com', 'yopmail.fr', 'mailinator.com', 'guerrillamail.com', 'trashmail.com', '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'jetable.org', 'getnada.com', 'sharklasers.com', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com'];
const TG_OUTREMER = ['01', '02', '03', '04', '06'];

function alerts_db(): PDO
{
    static $db = null;
    if ($db) return $db;
    $f = cfg('data_dir') . '/alerts.sqlite'; $new = !is_file($f);
    $db = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=8000');
    if ($new) $db->exec('PRAGMA journal_mode=WAL');
    $db->exec("CREATE TABLE IF NOT EXISTS subs(id INTEGER PRIMARY KEY, email TEXT UNIQUE, region TEXT, kw TEXT DEFAULT '', freq TEXT DEFAULT 'w',
            token TEXT UNIQUE, status TEXT DEFAULT 'active', created_at TEXT, ip TEXT, last_sent_at TEXT, next_at TEXT, sent INTEGER DEFAULT 0, ended_at TEXT);
        CREATE INDEX IF NOT EXISTS ix_subs_due ON subs(status, next_at);
        CREATE TABLE IF NOT EXISTS ev(day TEXT, kind TEXT, n INTEGER, PRIMARY KEY(day, kind));
        CREATE TABLE IF NOT EXISTS ip_hits(ip TEXT, day TEXT, n INTEGER, PRIMARY KEY(ip, day));
        CREATE TABLE IF NOT EXISTS tg_seen(chan TEXT, job_id TEXT, at TEXT, PRIMARY KEY(chan, job_id));
        CREATE TABLE IF NOT EXISTS tg_posts(id INTEGER PRIMARY KEY, chan TEXT, at TEXT, n INTEGER, msg_id INTEGER, ok INTEGER);");
    return $db;
}
function alerts_ev(string $kind, int $n = 1): void
{
    try { alerts_db()->prepare('INSERT INTO ev(day,kind,n) VALUES(?,?,?) ON CONFLICT(day,kind) DO UPDATE SET n=n+excluded.n')->execute([date('Y-m-d'), $kind, $n]); } catch (Throwable $e) {}
}
function alerts_on(): bool { return setting('alerts_on', '1') === '1'; }

// clé Telegram d'une région (slug de région, « outre-mer » pour les DROM)
function tg_key(string $reg): string { return in_array($reg, TG_OUTREMER, true) ? 'outre-mer' : (JOB_REGIONS[$reg][0] ?? ''); }
function tg_channels(): array { $j = json_decode((string)setting('tg_channels', ''), true); return is_array($j) ? $j : []; }

// Encadré « Recevoir les nouvelles offres » (e-mail + Telegram) : pages régions, villes et fiches.
function alerts_box(array $site, string $reg, string $dept = ''): string
{
    if (empty($site['jobs'])) return '';
    $opts = '';
    foreach (JOB_REGIONS as $code => [, $name]) $opts .= '<option value="' . h((string)$code) . '"' . ((string)$code === $reg ? ' selected' : '') . '>' . h($name) . '</option>';
    $tg = '';
    $ch = tg_channels()[tg_key($reg)] ?? '';
    $lk = (json_decode((string)setting('tg_links', ''), true) ?: [])[tg_key($reg)] ?? (str_starts_with((string)$ch, '@') ? 'https://t.me/' . ltrim($ch, '@') : '');
    if ($ch !== '' && $lk !== '') $tg = '<p style="margin:12px 0 0"><a href="' . h($lk) . '" target="_blank" rel="nofollow noopener" style="font-weight:600">📲 Suivre les offres ' . h(in_array($reg, TG_OUTREMER, true) ? 'Outre-mer' : (JOB_REGIONS[$reg][1] ?? '')) . ' sur Telegram</a></p>';
    if (function_exists('tgb_dept_button')) $tg = ($dept !== '' ? tgb_dept_button($dept) : (function_exists('tgb_dept_select') ? tgb_dept_select($reg) : '')) . $tg;
    if (!alerts_on()) return $tg ? '<div class="box">' . $tg . '</div>' : '';
    $in = 'width:100%;box-sizing:border-box;margin:0 0 8px;padding:9px 10px;border:1px solid var(--b);border-radius:8px;font:inherit';
    return '<div class="box" id="alerte"><h4 style="margin-top:0">Recevoir les nouvelles offres</h4>'
        . '<form method="post" action="/alerte/" onsubmit="this.k.value=\'ok\'+Date.now()">'
        . '<input type="email" name="email" required maxlength="120" placeholder="Votre adresse e-mail" autocomplete="email" style="' . $in . '">'
        . '<select name="r" aria-label="Région" style="' . $in . '">' . $opts . '</select>'
        . '<input type="text" name="kw" maxlength="60" placeholder="Métier (facultatif), ex. cariste" style="' . $in . '">'
        . '<select name="f" aria-label="Fréquence" style="' . $in . '"><option value="w">Une fois par semaine</option><option value="d">Chaque jour</option></select>'
        . '<input type="hidden" name="k" value=""><input type="text" name="site_web" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px">'
        . '<button type="submit" style="width:100%;padding:10px;border:0;border-radius:8px;background:var(--c);color:#fff;font:inherit;font-weight:600;cursor:pointer">Recevoir les offres par e-mail</button>'
        . '<p style="font-size:12px;opacity:.75;margin:8px 0 0">Gratuit. Votre adresse sert uniquement à vous envoyer ces offres. Désinscription en un clic dans chaque e-mail.</p></form>'
        . $tg . '</div>';
}

function alerts_page(array $site, string $title, string $msg): string
{
    return layout($site, ['title' => $title . ' | ' . $site['name'], 'desc' => $title, 'robots' => 'noindex,nofollow', 'noads' => true],
        '<div style="max-width:640px;margin:40px auto"><h1>' . h($title) . '</h1>' . $msg . '<p><a href="/offres-emploi/">Voir les offres d\'emploi</a></p></div>');
}

// /alerte/ (inscription), /alerte/stop/ (désinscription : GET = bouton, POST = désinscription, compatible « un clic »)
function alerts_route(array $site, string $path): ?string
{
    header('X-Robots-Tag: noindex, nofollow'); header('Cache-Control: no-store');
    $db = alerts_db();
    if ($path === '/alerte/stop/') {
        $t = preg_replace('/[^a-f0-9]/', '', (string)($_REQUEST['t'] ?? ''));
        $st = $db->prepare('SELECT * FROM subs WHERE token=?'); $st->execute([$t]); $s = $t !== '' ? $st->fetch() : null;
        if (!$s) return alerts_page($site, 'Lien de désinscription invalide', '<p>Ce lien n\'est plus valable. Si vous recevez encore nos e-mails, répondez simplement « stop » à l\'un d\'eux.</p>');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($s['status'] === 'active') { $db->prepare("UPDATE subs SET status='unsub', ended_at=? WHERE id=?")->execute([now(), $s['id']]); alerts_ev('unsub'); }
            return alerts_page($site, 'Vous êtes désinscrit', '<p>Vous ne recevrez plus nos alertes à l\'adresse ' . h($s['email']) . '.</p>');
        }
        if ($s['status'] !== 'active') return alerts_page($site, 'Vous êtes déjà désinscrit', '<p>Aucune alerte n\'est plus envoyée à ' . h($s['email']) . '.</p>');
        return alerts_page($site, 'Se désinscrire des alertes', '<p>Ne plus recevoir les offres d\'emploi à l\'adresse ' . h($s['email']) . ' ?</p><form method="post"><input type="hidden" name="t" value="' . h($t) . '"><button type="submit" style="padding:10px 18px;border:0;border-radius:8px;background:var(--c);color:#fff;font:inherit;cursor:pointer">Me désinscrire</button></form>');
    }
    if ($path !== '/alerte/' || $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /offres-emploi/', true, 302); return null; }
    if (!alerts_on()) return alerts_page($site, 'Alertes momentanément indisponibles', '<p>Les inscriptions sont suspendues. Revenez dans quelques jours.</p>');
    $ok = fn() => alerts_page($site, 'C\'est noté !', '<p>Vous recevrez les nouvelles offres correspondant à votre recherche. Chaque e-mail contient un lien pour vous désinscrire en un clic.</p><p style="font-size:14px;opacity:.8">Pensez à vérifier vos indésirables et à ajouter alertes@' . h(preg_replace('/^www\./', '', $site['host'])) . ' à vos contacts.</p>');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? ''); $day = date('Y-m-d');
    // robots : champ piège rempli ou jeton JavaScript absent -> on répond « ok » sans rien enregistrer
    if ((string)($_POST['site_web'] ?? '') !== '' || !str_starts_with((string)($_POST['k'] ?? ''), 'ok') || is_bot()) { alerts_ev('rejet_robot'); return $ok(); }
    $db->prepare('INSERT INTO ip_hits(ip,day,n) VALUES(?,?,1) ON CONFLICT(ip,day) DO UPDATE SET n=n+1')->execute([$ip, $day]);
    $st = $db->prepare('SELECT n FROM ip_hits WHERE ip=? AND day=?'); $st->execute([$ip, $day]);
    if ((int)$st->fetchColumn() > 3) { alerts_ev('rejet_ip'); return alerts_page($site, 'Trop de demandes', '<p>Trop d\'inscriptions depuis votre connexion aujourd\'hui. Réessayez demain.</p>'); }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $dom = substr(strrchr($email, '@') ?: '', 1);
    if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || in_array($dom, ALERT_DISPOSABLE, true) || !(checkdnsrr($dom, 'MX') || checkdnsrr($dom, 'A'))) {
        alerts_ev('rejet_adresse');
        return alerts_page($site, 'Adresse e-mail non valide', '<p>Cette adresse ne peut pas recevoir nos alertes. Vérifiez-la et réessayez.</p><p><a href="javascript:history.back()">Revenir</a></p>');
    }
    $reg = (string)($_POST['r'] ?? ''); if (!isset(JOB_REGIONS[$reg])) $reg = '28';
    $kw = trim(preg_replace('/[^\p{L}\p{N} \'-]/u', ' ', mb_substr((string)($_POST['kw'] ?? ''), 0, 60)));
    $kw = preg_replace('/\s+/', ' ', $kw);
    $freq = ($_POST['f'] ?? 'w') === 'd' ? 'd' : 'w';
    $st = $db->prepare('SELECT id, status FROM subs WHERE email=?'); $st->execute([$email]); $old = $st->fetch();
    if ($old) {
        $db->prepare("UPDATE subs SET region=?, kw=?, freq=?, status='active', ended_at=NULL, next_at=COALESCE(next_at, ?) WHERE id=?")->execute([$reg, $kw, $freq, now(), $old['id']]);
        alerts_ev($old['status'] === 'active' ? 'modif' : 'reinscription');
    } else {
        $db->prepare("INSERT INTO subs(email,region,kw,freq,token,status,created_at,ip,next_at) VALUES(?,?,?,?,?,'active',?,?,?)")
            ->execute([$email, $reg, $kw, $freq, bin2hex(random_bytes(16)), now(), $ip, now()]);
        alerts_ev('inscription');
    }
    return $ok();
}

// Offres nouvelles pour un abonné (ouvertes, indexables, de sa région, mot-clé dans le titre).
function alerts_offers(array $site, array $s, int $limit = 10): array
{
    $db = jobs_db($site['host']);
    $since = $s['last_sent_at'] ?: gmdate('Y-m-d H:i:s', time() - ($s['freq'] === 'd' ? 2 : 7) * 86400);
    $w = "status='open' AND " . JOB_INDEXABLE_SQL . " AND region=? AND created_at > ?"; $a = [$s['region'], $since];
    foreach (array_slice(array_filter(explode(' ', (string)$s['kw']), fn($x) => mb_strlen($x) >= 3), 0, 3) as $k) { $w .= ' AND title LIKE ?'; $a[] = '%' . $k . '%'; }
    $st = $db->prepare("SELECT COUNT(*) FROM jobs WHERE $w"); $st->execute($a); $n = (int)$st->fetchColumn();
    if (!$n) return [0, []];
    $st = $db->prepare("SELECT slug,title,company,city,contract_label,salary,created_at FROM jobs WHERE $w ORDER BY (salary<>'') DESC, created_at DESC LIMIT $limit"); $st->execute($a);
    return [$n, $st->fetchAll()];
}

function alerts_mail(array $site, array $s, int $n, array $jobs): bool
{
    $dom = preg_replace('/^www\./', '', $site['host']); $from = 'alertes@' . $dom; $base = 'https://' . $site['host'];
    $utm = 'utm_source=email&utm_medium=alerte&utm_campaign=' . ($s['freq'] === 'd' ? 'quotidien' : 'hebdo');
    $rname = JOB_REGIONS[$s['region']][1] ?? ''; $rslug = JOB_REGIONS[$s['region']][0] ?? '';
    $what = $s['kw'] !== '' ? '« ' . $s['kw'] . ' » en ' . $rname : 'en ' . $rname;
    $subject = $n . ' nouvelle' . ($n > 1 ? 's' : '') . ' offre' . ($n > 1 ? 's' : '') . ' ' . $what;
    $stop = $base . '/alerte/stop/?t=' . $s['token'];
    $more = $s['kw'] !== '' ? $base . '/chercher/?q=' . rawurlencode($s['kw']) . '&l=' . rawurlencode($rname) . '&' . $utm : $base . '/offres-emploi/' . $rslug . '/?' . $utm;
    $txt = "Bonjour,\n\n$subject sur " . $site['name'] . " :\n\n"; $html = '';
    foreach ($jobs as $j) {
        $u = $base . '/offre/' . $j['slug'] . '/?' . $utm;
        $meta = implode(' · ', array_filter([$j['city'], $j['contract_label'], $j['salary']]));
        $txt .= '- ' . $j['title'] . ($j['company'] ? ' — ' . $j['company'] : '') . "\n  " . $meta . "\n  " . $u . "\n\n";
        $html .= '<tr><td style="padding:12px 0;border-bottom:1px solid #e5e7eb"><a href="' . h($u) . '" style="font-size:16px;font-weight:600;color:#1d4ed8;text-decoration:none">' . h($j['title']) . '</a><br><span style="color:#374151">' . h($j['company']) . '</span><br><span style="color:#6b7280;font-size:14px">' . h($meta) . '</span></td></tr>';
    }
    $tgk = tg_key((string)$s['region']); $tgl = (json_decode((string)setting('tg_links', ''), true) ?: [])[$tgk] ?? '';
    $txt .= ($n > count($jobs) ? 'Voir les ' . $n . " offres : $more\n\n" : "Toutes les offres : $more\n\n") . ($tgl ? "Suivez les offres de votre région sur Telegram : $tgl\n\n" : '') . "Se désinscrire : $stop\n";
    $html = '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif"><table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:20px 10px">'
        . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:10px;padding:20px"><tr><td>'
        . '<p style="margin:0 0 4px;font-size:13px;color:#6b7280">' . h($site['name']) . '</p><h1 style="margin:0 0 12px;font-size:20px;color:#111827">' . h($subject) . '</h1>'
        . '<table width="100%" cellpadding="0" cellspacing="0">' . $html . '</table>'
        . '<p style="margin:18px 0"><a href="' . h($more) . '" style="display:inline-block;background:#1d4ed8;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600">' . ($n > count($jobs) ? 'Voir les ' . $n . ' offres' : 'Voir toutes les offres') . '</a></p>'
        . ($tgl ? '<p style="margin:0 0 14px;font-size:14px">📲 <a href="' . h($tgl) . '" style="color:#1d4ed8">Suivez les offres de votre région sur Telegram</a></p>' : '')
        . '<p style="font-size:12px;color:#6b7280">Vous recevez cet e-mail car vous vous êtes inscrit aux alertes de ' . h($site['name']) . ' (' . ($s['freq'] === 'd' ? 'chaque jour' : 'chaque semaine') . '). <a href="' . h($stop) . '" style="color:#6b7280">Se désinscrire</a></p>'
        . '</td></tr></table></td></tr></table></body></html>';
    $b = 'b' . bin2hex(random_bytes(8));
    $headers = 'From: ' . mb_encode_mimeheader($site['name'], 'UTF-8') . " <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n"
        . "List-Unsubscribe: <$stop>, <mailto:$from?subject=stop>\r\nList-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(12)) . "@$dom>\r\nX-Auto-Response-Suppress: All\r\nPrecedence: bulk";
    $body = "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($txt))
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b--\r\n";
    return @mail($s['email'], mb_encode_mimeheader($subject, 'UTF-8'), $body, $headers, '-f' . $from);
}

// Passage d'envoi (cron horaire) : 7 h-20 h, plafond horaire ET journalier, jamais au-delà.
function alerts_send_run(array $site): array
{
    $r = ['sent' => 0, 'none' => 0, 'fail' => 0, 'left_day' => 0];
    if (!alerts_on()) return $r + ['off' => true];
    $h = (int)date('G'); if ($h < 7 || $h > 20) return $r + ['hors_horaire' => true];
    $db = alerts_db();
    $dayMax = max(0, (int)setting('alerts_day_max', '150')); $hourMax = max(0, (int)setting('alerts_hour_max', '30'));
    $st = $db->prepare("SELECT COALESCE(SUM(n),0) FROM ev WHERE day=? AND kind='envoi'"); $st->execute([date('Y-m-d')]); $today = (int)$st->fetchColumn();
    $budget = min($hourMax, $dayMax - $today);
    $r['left_day'] = max(0, $dayMax - $today);
    if ($budget <= 0) { alerts_ev('plafond_atteint'); return $r + ['plafond' => true]; }
    $due = $db->prepare("SELECT * FROM subs WHERE status='active' AND next_at <= ? ORDER BY next_at LIMIT 300"); $due->execute([now()]);
    $upd = $db->prepare('UPDATE subs SET last_sent_at=?, next_at=?, sent=sent+1 WHERE id=?');
    $skip = $db->prepare('UPDATE subs SET next_at=? WHERE id=?');
    foreach ($due->fetchAll() as $s) {
        if ($r['sent'] >= $budget) break;
        $next = gmdate('Y-m-d H:i:s', time() + ($s['freq'] === 'd' ? 86400 - 1800 : 7 * 86400 - 1800));
        [$n, $jobs] = alerts_offers($site, $s);
        if (!$n) { $skip->execute([gmdate('Y-m-d H:i:s', time() + 86400), $s['id']]); $r['none']++; continue; } // rien de neuf : on réessaie demain
        if (alerts_mail($site, $s, $n, $jobs)) { $upd->execute([now(), $next, $s['id']]); $r['sent']++; alerts_ev('envoi'); }
        else { $r['fail']++; alerts_ev('echec_envoi'); $skip->execute([gmdate('Y-m-d H:i:s', time() + 3600), $s['id']]); }
        usleep(1500000); // étalement : ~40 e-mails/minute au plus
    }
    if ($r['sent'] >= $budget) alerts_ev('plafond_atteint');
    return $r;
}

// Retours d'erreur (rebonds) reçus sur alertes@ : adresse en échec définitif -> abonnement arrêté ; « stop » par e-mail -> désinscription.
function alerts_bounces(array $site): array
{
    $dom = preg_replace('/^www\./', '', $site['host']);
    $dir = '/home2/guenver/mail/' . $dom . '/alertes';
    $r = ['bounce' => 0, 'stop' => 0, 'files' => 0];
    if (!is_dir($dir)) return $r + ['boite' => 'absente'];
    $db = alerts_db();
    foreach (array_merge(glob("$dir/new/*") ?: [], glob("$dir/cur/*") ?: []) as $f) {
        $r['files']++;
        $m = (string)@file_get_contents($f, false, null, 0, 200000);
        $hit = [];
        if (preg_match_all('/^X-Failed-Recipients:\s*(.+)$/mi', $m, $x)) foreach ($x[1] as $l) foreach (preg_split('/[,\s]+/', $l) as $e) $hit[] = strtolower(trim($e));
        if (preg_match_all('/^Final-Recipient:\s*rfc822;\s*(\S+)/mi', $m, $x) && preg_match('/^Status:\s*5\./mi', $m)) foreach ($x[1] as $e) $hit[] = strtolower(trim($e, '<> '));
        foreach (array_unique(array_filter($hit)) as $e) {
            $u = $db->prepare("UPDATE subs SET status='bounce', ended_at=? WHERE email=? AND status='active'"); $u->execute([now(), $e]);
            if ($u->rowCount()) { $r['bounce']++; alerts_ev('rebond'); }
        }
        if (!$hit && preg_match('/^Subject:.*\bstop\b/mi', $m) && preg_match('/^From:.*?<?([^\s<>@]+@[^\s<>]+)>?\s*$/mi', $m, $x)) {
            $u = $db->prepare("UPDATE subs SET status='unsub', ended_at=? WHERE email=? AND status='active'"); $u->execute([now(), strtolower($x[1])]);
            if ($u->rowCount()) { $r['stop']++; alerts_ev('unsub'); }
        }
        @unlink($f);
    }
    return $r;
}

// ---------- Telegram ----------
function tg_bot(string $method, array $p): array
{
    $t = (string)setting('tg_bot_token', ''); if ($t === '') return ['ok' => false, 'description' => 'pas de jeton'];
    $ch = curl_init("https://api.telegram.org/bot$t/$method");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($p, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
    return is_array($r) ? $r : ['ok' => false, 'description' => 'réponse illisible'];
}

// Récapitulatif par chaîne : offres des dernières 24 h jamais publiées sur la chaîne, les plus complètes d'abord.
function tg_post_run(array $site): array
{
    $out = [];
    if (setting('tg_on', '1') !== '1') return ['off' => true];
    $chans = tg_channels(); if (!$chans || setting('tg_bot_token', '') === '') return ['non_configure' => true];
    $jdb = jobs_db($site['host']); $adb = alerts_db(); $base = 'https://' . $site['host'];
    $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $moment = (int)date('G') < 11 ? 'ce matin' : ((int)date('G') < 16 ? 'ce midi' : 'ce soir');
    foreach ($chans as $key => $chat) {
        $regs = $key === 'outre-mer' ? TG_OUTREMER : array_keys(array_filter(JOB_REGIONS, fn($r) => $r[0] === $key));
        if (!$regs) continue;
        $in = implode(',', array_fill(0, count($regs), '?'));
        $st = $jdb->prepare("SELECT id,slug,title,company,city,contract_label,salary FROM jobs WHERE status='open' AND " . JOB_INDEXABLE_SQL . " AND region IN ($in)
            AND created_at > datetime('now','-1 day') ORDER BY (salary<>'') DESC, length(description) DESC, created_at DESC LIMIT 60");
        $st->execute($regs);
        $seen = $adb->prepare('SELECT 1 FROM tg_seen WHERE chan=? AND job_id=?');
        $pick = [];
        foreach ($st->fetchAll() as $j) { $seen->execute([$key, $j['id']]); if (!$seen->fetchColumn()) $pick[] = $j; if (count($pick) >= 6) break; }
        if (count($pick) < 2) { $out[$key] = 0; continue; }
        $name = $key === 'outre-mer' ? 'Outre-mer' : JOB_REGIONS[$regs[0]][1];
        $utm = 'utm_source=telegram&utm_medium=chaine&utm_campaign=' . $key;
        $t = '🆕 <b>Nouvelles offres d\'emploi ' . h($name) . '</b> — ' . (int)date('j') . ' ' . $mois[(int)date('n')] . ', ' . $moment . "\n";
        foreach ($pick as $j) {
            $meta = implode(' · ', array_filter([$j['city'], $j['contract_label'], $j['salary'] ? preg_replace('/\s+sur\s+\d+.*$/u', '', (string)$j['salary']) : '']));
            $t .= "\n• <a href=\"" . h($base . '/offre/' . $j['slug'] . '/?' . $utm) . '">' . h($j['title']) . '</a>' . ($j['company'] ? ' — ' . h($j['company']) : '') . ($meta ? "\n   <i>" . h($meta) . '</i>' : '');
        }
        $link = $key === 'outre-mer' ? $base . '/offres-emploi/?' . $utm : $base . '/offres-emploi/' . $key . '/?' . $utm;
        $t .= "\n\n👉 <a href=\"" . h($link) . '">Toutes les offres ' . h($name) . '</a>';
        $r = tg_bot('sendMessage', ['chat_id' => $chat, 'text' => mb_substr($t, 0, 4000), 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]]);
        $ok = !empty($r['ok']);
        $adb->prepare('INSERT INTO tg_posts(chan,at,n,msg_id,ok) VALUES(?,?,?,?,?)')->execute([$key, now(), count($pick), $r['result']['message_id'] ?? null, $ok ? 1 : 0]);
        if ($ok) { $ins = $adb->prepare('INSERT OR IGNORE INTO tg_seen(chan,job_id,at) VALUES(?,?,?)'); foreach ($pick as $j) $ins->execute([$key, $j['id'], now()]); alerts_ev('tg_publication'); }
        else alerts_ev('tg_echec');
        $out[$key] = $ok ? count($pick) : 'erreur: ' . ($r['description'] ?? '?');
        sleep(2);
    }
    $adb->exec("DELETE FROM tg_seen WHERE at < datetime('now','-10 days')");
    return $out;
}

// Abonnés par chaîne (pour le rapport).
function tg_members(): array
{
    $o = [];
    foreach (tg_channels() as $k => $c) { $r = tg_bot('getChatMemberCount', ['chat_id' => $c]); $o[$k] = !empty($r['ok']) ? (int)$r['result'] : null; }
    return $o;
}
