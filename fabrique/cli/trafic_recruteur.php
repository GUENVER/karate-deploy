<?php
// Suivi des sources de trafic de recruteur.eu.
// collect : lit le log du jour et ajoute une ligne au CSV (cron quotidien 23:56).
// report  : envoie le bilan des 7 derniers jours vs les 7 précédents (cron lundi).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$LOG = '/home2/guenver/access-logs/recruteur.webnormandie.com-ssl_log';
$CSV = '/home2/guenver/fabrique/data/trafic_recruteur.csv';
$COLS = ['date','chatgpt','google','bing','autres_ia','ip_non_declarees','chatgpt_user','oai_searchbot','googlebot','bingbot'];
$mode = $argv[1] ?? 'collect';

if ($mode === 'collect') {
    $h = @fopen($LOG, 'r'); if (!$h) exit("log introuvable\n");
    $s = ['chatgpt'=>[], 'google'=>[], 'bing'=>[], 'autres_ia'=>[], 'ip_non_declarees'=>[]];
    $n = ['chatgpt_user'=>0, 'oai_searchbot'=>0, 'googlebot'=>0, 'bingbot'=>0];
    $date = '';
    while (($l = fgets($h)) !== false) {
        if (!preg_match('/^(\S+) \S+ \S+ \[([^:]+)[^\]]*\] "\S+ (\S+)[^"]*" \d+ \S+ "([^"]*)" "([^"]*)"/', $l, $m)) continue;
        [, $ip, $d, $url, $ref, $ua] = $m; $date = $d;
        if (stripos($ua, 'ChatGPT-User') !== false) { $n['chatgpt_user']++; continue; }
        if (stripos($ua, 'OAI-SearchBot') !== false) { $n['oai_searchbot']++; continue; }
        if (stripos($ua, 'Googlebot') !== false) { $n['googlebot']++; continue; }
        if (stripos($ua, 'bingbot') !== false) { $n['bingbot']++; continue; }
        if (preg_match('/bot|crawl|spider|slurp|curl|wget|python|headless|semalt/i', $ua . $ref)) continue;
        if (preg_match('/\.(css|js|png|jpe?g|svg|ico|webp|gif|woff2?)(\?|$)/i', $url)) continue;
        if (preg_match('/chatgpt\.com/i', $ref) || stripos($url, 'utm_source=chatgpt') !== false) $s['chatgpt'][$ip] = 1;
        elseif (preg_match('#^https?://(www\.)?google\.#i', $ref)) $s['google'][$ip] = 1;
        elseif (preg_match('/bing\.com|copilot/i', $ref)) $s['bing'][$ip] = 1;
        elseif (preg_match('/perplexity|claude\.ai|gemini|mistral/i', $ref . $url)) $s['autres_ia'][$ip] = 1;
        else $s['ip_non_declarees'][$ip] = 1;
    }
    fclose($h);
    $dt = DateTime::createFromFormat('d/M/Y', $date);
    $row = [$dt ? $dt->format('Y-m-d') : date('Y-m-d')];
    foreach ($s as $v) $row[] = count($v);
    foreach ($n as $v) $row[] = $v;
    $lines = is_file($CSV) ? file($CSV, FILE_IGNORE_NEW_LINES) : [implode(';', $COLS)];
    $lines = array_values(array_filter($lines, fn($x) => !str_starts_with($x, $row[0] . ';')));
    $lines[] = implode(';', $row);
    file_put_contents($CSV, implode("\n", $lines) . "\n");
    echo implode(';', $row), "\n";
    exit;
}

if ($mode === 'report') {
    $rows = [];
    foreach (array_slice(file($CSV, FILE_IGNORE_NEW_LINES), 1) as $x) { $r = explode(';', $x); if (count($r) === count($COLS)) $rows[$r[0]] = array_combine($COLS, $r); }
    ksort($rows); $rows = array_values($rows);
    $cur = array_slice($rows, -7);
    $prev = array_slice($rows, 0, max(0, count($rows) - 7)); $prev = array_slice($prev, -7);
    $sum = fn($set, $k) => array_sum(array_column($set, $k));
    $lab = ['chatgpt'=>'Visiteurs ChatGPT','google'=>'Visiteurs Google','bing'=>'Visiteurs Bing/Copilot','autres_ia'=>'Visiteurs autres IA',
            'ip_non_declarees'=>'IP non déclarées (robots probables)','chatgpt_user'=>'Lectures ChatGPT-User','oai_searchbot'=>'Requêtes OAI-SearchBot',
            'googlebot'=>'Requêtes Googlebot','bingbot'=>'Requêtes Bingbot'];
    $b = "recruteur.eu — du " . ($cur[0]['date'] ?? '?') . " au " . (end($cur)['date'] ?? '?') . "\n(somme des visiteurs uniques quotidiens, évolution vs 7 jours précédents)\n\n";
    $alert = '';
    foreach ($lab as $k => $t) {
        $a = $sum($cur, $k); $p = $prev ? $sum($prev, $k) : 0;
        $v = $p ? sprintf('%+d %%', round(($a - $p) / $p * 100)) : 'n/a';
        $b .= str_pad($t, 40, '.') . ' ' . str_pad(number_format($a, 0, ',', ' '), 9, ' ', STR_PAD_LEFT) . "   ($v)\n";
        if ($k === 'chatgpt' && $p && $a < $p * 0.7) $alert = ' — ALERTE baisse ChatGPT';
    }
    $c = $sum($cur, 'chatgpt'); $g = $sum($cur, 'google');
    $b .= "\nPart ChatGPT / (ChatGPT + Google) : " . (($c + $g) ? round($c / ($c + $g) * 100) : 0) . " %\n\nDétail par jour :\n";
    foreach ($cur as $r) $b .= "{$r['date']}  ChatGPT {$r['chatgpt']}  Google {$r['google']}  OAI-Search {$r['oai_searchbot']}\n";
    // robots, erreurs et offres fermées (cli/robots_recruteur.php)
    $RB = '/home2/guenver/fabrique/data/robots_recruteur.csv';
    if (is_file($RB)) {
        $rl = file($RB, FILE_IGNORE_NEW_LINES); $rh = explode(';', (string)array_shift($rl));
        $rr = array_slice(array_values(array_filter(array_map(fn($x) => count(explode(';', $x)) === count($rh) ? array_combine($rh, explode(';', $x)) : null, $rl))), -7);
        $rs = fn($k) => array_sum(array_column($rr, $k));
        $b .= "\nRobots et erreurs (7 derniers jours) :\n"
            . "OAI-SearchBot " . $rs('oai') . " (fiches OK " . $rs('oai_offre_ok') . ", anciens sitemaps " . $rs('oai_wpsitemap') . ", erreurs " . $rs('oai_4xx') . ")\n"
            . "ChatGPT-User " . $rs('chatgpt_user') . " (erreurs " . $rs('chatgpt_user_4xx') . ")  ·  Perplexity " . $rs('perplexity') . "  ·  Claude " . $rs('claude') . "\n"
            . "Arrivées ChatGPT sur une offre : " . $rs('chatgpt_arrivees_offre') . ", dont offre marquée fermée : " . $rs('chatgpt_arrivees_offre_fermee') . "\n"
            . "IP hors listes officielles : OAI " . $rs('oai_ip_hors_liste') . ", ChatGPT-User " . $rs('chatgpt_user_ip_hors_liste') . ", Bing " . $rs('bingbot_ip_hors_liste') . ", Google " . $rs('googlebot_ip_hors_liste') . "\n"
            . "Erreurs 404 : " . $rs('err_404') . "  ·  erreurs 5xx : " . $rs('err_5xx') . "  ·  aperçus Meta refusés : " . $rs('meta_apercu_403') . "\n";
        if ($rs('err_5xx') > 200) $alert .= ' — ALERTE erreurs 5xx';
    }
    // pages vues des nouvelles rubriques (cli/rubriques_recruteur.php)
    if (is_file(__DIR__ . '/rubriques_recruteur.php')) { require_once __DIR__ . '/rubriques_recruteur.php'; $b .= rub_report(); }
    // alertes e-mail et Telegram (app/alerts.php)
    $AL = '/home2/guenver/fabrique/data/alerts.sqlite';
    if (is_file($AL)) {
        try {
            $ad = new PDO('sqlite:' . $AL); $ad->exec('PRAGMA busy_timeout=8000');
            $evs = []; foreach ($ad->query("SELECT kind, SUM(n) n FROM ev WHERE day >= date('now','-7 days') GROUP BY kind") as $e) $evs[$e['kind']] = (int)$e['n'];
            $act = (int)$ad->query("SELECT COUNT(*) FROM subs WHERE status='active'")->fetchColumn();
            $g = fn($k) => $evs[$k] ?? 0;
            $b .= "\nAlertes e-mail (7 jours) : {$g('inscription')} inscriptions (+{$g('reinscription')} réinscriptions), {$g('unsub')} désinscriptions, {$g('rebond')} adresses en erreur\n"
                . "Abonnés actifs : $act  ·  e-mails envoyés : {$g('envoi')} (échecs {$g('echec_envoi')}, plafond atteint {$g('plafond_atteint')} fois)  ·  inscriptions refusées : robots {$g('rejet_robot')}, adresses {$g('rejet_adresse')}, IP {$g('rejet_ip')}\n"
                . "Visites venues des e-mails : " . ($rs ?? fn() => 0)('visites_email') . "  ·  depuis Telegram : " . ($rs ?? fn() => 0)('visites_telegram') . "\n"
                . "Visites venues des boutons de partage (Telegram/WhatsApp) : " . ($rs ?? fn() => 0)('visites_partage') . "\n"
                . "Telegram chaînes : {$g('tg_publication')} récapitulatifs, {$g('tg_edito')} messages éditoriaux, {$g('tg_echec')} échecs";
            $tga = 0; try { $tga = (int)$ad->query("SELECT COUNT(*) FROM tg_subs WHERE status='active' AND state='ok'")->fetchColumn(); } catch (Throwable $e) {}
            $b .= "\nRobot @LeRecruteurOffresBot : $tga abonnés actifs, " . ($g('tgbot_inscription')) . " nouveaux (dont " . $g('tgbot_start_site') . " depuis le site), " . $g('tgbot_stop') . " arrêts, " . $g('tgbot_bloque') . " blocages, " . $g('tgbot_envoi') . " récapitulatifs envoyés, " . $g('tgbot_recherche') . " recherches, " . $g('tgbot_inline') . " recherches dans d'autres conversations\n";
            require_once '/home2/guenver/fabrique/app/core.php'; require_once '/home2/guenver/fabrique/app/jobs.php'; require_once '/home2/guenver/fabrique/app/alerts.php';
            $mem = tg_members(); if ($mem) $b .= ' · abonnés : ' . implode(', ', array_map(fn($k, $v) => "$k " . ($v ?? '?'), array_keys($mem), $mem)) . ' (total ' . array_sum(array_filter($mem)) . ')';
            $b .= "\n";
        } catch (Throwable $e) { $b .= "\nAlertes : lecture impossible (" . $e->getMessage() . ")\n"; }
    }
    $FV = '/home2/guenver/fabrique/data/ft_verify.log';
    if (is_file($FV)) { $fv = ['checked' => 0, 'reopened' => 0, 'closed_now' => 0, 'errors' => 0];
        foreach (file($FV, FILE_IGNORE_NEW_LINES) as $x) if (preg_match('/^(\S+) \S+ (\{.*\})$/', $x, $mm) && strtotime($mm[1]) > time() - 7 * 86400) { $j = json_decode($mm[2], true) ?: []; foreach ($fv as $k => $_) $fv[$k] += (int)($j[$k] ?? 0); }
        $b .= "Vérification France Travail : {$fv['checked']} offres vérifiées, {$fv['reopened']} rouvertes, {$fv['closed_now']} fermées (disparues), {$fv['errors']} erreurs API\n"; }
    @mail('e.guenver@gmail.com', '=?UTF-8?B?' . base64_encode('[recruteur.eu] Trafic hebdo' . $alert) . '?=', $b,
        "From: surveillance@guenver.com\r\nContent-Type: text/plain; charset=UTF-8");
    echo $b;
}
