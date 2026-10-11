<?php
// Introductions rédigées des pages communes (module bzh), à partir des seules données publiées.
// php cli/bzh_intros.php export [--all]  -> fiches de faits dans data/bzh/intros/<insee>.txt (communes indexables sans intro)
// php cli/bzh_intros.php import          -> lit les <insee>.html produits par l'IA (ai_batch), contrôle et enregistre
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/bzh.php';
$db = bzh_db();
$dir = cfg('data_dir') . '/bzh/intros';
@mkdir($dir, 0750, true);
$cmd = $argv[1] ?? 'export';

if ($cmd === 'export') {
    $all = in_array('--all', $argv, true); $n = 0;
    $t = bzh_today();
    foreach ($db->query('SELECT * FROM communes ORDER BY pop DESC')->fetchAll() as $c) {
        if (!$all && !empty($c['intro'])) continue;
        $s = bzh_commune_stats($c['insee']);
        if (!bzh_commune_indexable($c, $s)) continue;
        $f = ["Commune : {$c['nom']} (" . bzh_dn($c['dept']) . ', Bretagne)', 'Population : ' . (int)$c['pop'] . ' habitants', 'Superficie : ' . $c['surface'] . ' km²', 'Intercommunalité : ' . $c['epci']];
        $q = function (string $k, int $n, string $order = 'rank DESC') use ($db, $c, $t) {
            $st = $db->prepare("SELECT name, sub, data, d1, d2 FROM items WHERE insee=? AND kind=?" . ($k === 'evenement' ? ' AND d2>=?' : '') . " ORDER BY $order LIMIT $n");
            $st->execute($k === 'evenement' ? [$c['insee'], $k, $t] : [$c['insee'], $k]); return $st->fetchAll();
        };
        $lab = ['lieu' => 'Lieux à visiter', 'monument' => 'Monuments historiques', 'plage' => 'Sites de baignade surveillés', 'rando' => 'Randonnées et circuits', 'festival' => 'Festivals', 'patrimoine' => 'Patrimoine inventorié (exemples)'];
        foreach ($lab as $k => $l) {
            $rows = $q($k, $k === 'patrimoine' ? 6 : 8);
            if (!$rows) continue;
            $f[] = "$l (" . ($s[$k] ?? count($rows)) . ' au total) : ' . implode(' ; ', array_map(function ($r) use ($k) {
                $d = bzh_data($r);
                $x = match ($k) { 'monument' => $d['siecle'] ?? '', 'plage' => 'qualité de l\'eau ' . mb_strtolower((string)($d['qualite'] ?? '')), 'festival' => trim(($d['discipline'] ?? '') . ', ' . ($d['periode'] ?? ''), ', '), 'patrimoine' => $d['datation'] ?? '', default => $r['sub'] };
                return $r['name'] . ($x ? " ($x)" : '');
            }, $rows));
        }
        if (!empty($s['evenement'])) { $ev = $q('evenement', 3, 'd1'); $f[] = 'Événements à venir : ' . $s['evenement'] . ', par exemple ' . implode(' ; ', array_map(fn($r) => $r['name'] . ' (' . mb_strtolower($r['sub']) . ', ' . bzh_period($r['d1'], $r['d2']) . ')', $ev)); }
        if (!empty($s['hebergement'])) { $st = $db->prepare("SELECT sub, COUNT(*) n FROM items WHERE insee=? AND kind='hebergement' GROUP BY sub ORDER BY n DESC"); $st->execute([$c['insee']]); $f[] = 'Hébergements référencés : ' . implode(', ', array_map(fn($r) => mb_strtolower($r['sub']) . ' ' . $r['n'], $st->fetchAll())); }
        if (!empty($s['restaurant'])) $f[] = 'Restaurants référencés : ' . $s['restaurant'];
        if ($c['lat']) {
            $nb = $db->prepare('SELECT nom, lat, lon FROM communes WHERE insee<>? ORDER BY ((lat-?)*(lat-?)+((lon-?)*0.67)*((lon-?)*0.67)) LIMIT 5');
            $nb->execute([$c['insee'], $c['lat'], $c['lat'], $c['lon'], $c['lon']]);
            $f[] = 'Communes voisines : ' . implode(', ', array_map(fn($r) => $r['nom'] . ' (' . round(bzh_km((float)$c['lat'], (float)$c['lon'], (float)$r['lat'], (float)$r['lon'])) . ' km)', $nb->fetchAll()));
        }
        file_put_contents("$dir/{$c['insee']}.txt", implode("\n", $f) . "\n");
        $n++;
    }
    echo "$n fiches exportées\n";
} elseif ($cmd === 'import') {
    // rapatrie les introductions rédigées sur le hub (dossier public dont le nom est gardé dans intros_token)
    $tok = trim((string)@file_get_contents(cfg('data_dir') . '/bzh/intros_token'));
    $get = function (string $u): string { $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20]); $r = (string)curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return $c === 200 ? $r : ''; };
    if ($tok !== '') {
        $have = array_flip($db->query("SELECT insee FROM communes WHERE intro IS NOT NULL AND intro<>''")->fetchAll(PDO::FETCH_COLUMN));
        foreach (json_decode($get("https://guenver.com/_depot/files/bzhx-$tok/index.json"), true) ?: [] as $insee) {
            if (isset($have[$insee]) || !preg_match('/^\d[\dAB]\d{3}$/', (string)$insee) || is_file("$dir/$insee.html.rejet")) continue;
            if (($h = $get("https://guenver.com/_depot/files/bzhx-$tok/$insee.html")) !== '') file_put_contents("$dir/$insee.html", $h);
        }
    }
    $n = 0; $bad = 0;
    foreach (glob("$dir/*.html") as $f) {
        $insee = basename($f, '.html');
        $h = trim((string)file_get_contents($f));
        $h = preg_replace('/^```(html)?|```$/m', '', $h);
        $h = strip_tags($h, '<p><strong><em>');
        $h = preg_replace('/<(p|strong|em)\s[^>]*>/i', '<$1>', $h);
        $w = str_word_count(strip_tags($h), 0, 'àâäéèêëîïôöùûüçœÀÂÉÈÊÎÔÛÇ\'-');
        if ($w < 70 || $w > 400 || !str_contains($h, '<p>') || preg_match('/découverte bretagne|en tant qu|je ne peux|désolé/iu', $h)) { $bad++; rename($f, "$f.rejet"); continue; }
        $db->prepare('UPDATE communes SET intro=?, intro_at=? WHERE insee=?')->execute([trim($h), now(), $insee]);
        if ($c = bzh_commune($insee)) @unlink(cache_key(BZH_HOST, bzh_com_url($c)));
        @unlink($f); @unlink("$dir/$insee.txt");
        $n++;
    }
    echo "$n introductions importées, $bad rejetées\n";
}
