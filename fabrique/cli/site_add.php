<?php
// Ajout de sites en ligne de commande (même logique que l'admin).
// Usage : php cli/site_add.php sites.json   (tableau d'objets : host, name, tagline, niche, seeds[], categories[], per_day, color, sub/root pour créer le sous-domaine, alias[], permalink, gen)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require dirname(__DIR__) . '/app/core.php';
require dirname(__DIR__) . '/app/admin.php';

$list = json_decode((string)file_get_contents($argv[1] ?? 'php://stdin'), true) ?: exit("JSON invalide\n");
foreach ($list as $s) {
    $host = strtolower($s['host']);
    if (site_by_host($host)) { echo "= $host existe déjà\n"; continue; }
    registry()->prepare("INSERT INTO sites(host,name,tagline,niche,color,per_day,min_words,seeds,adsense,amazon,gen,ymyl,permalink,lang,status,created_at,next_gen_at)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'fr','active',?,?)")->execute([
        $host, $s['name'], $s['tagline'] ?? '', $s['niche'] ?? '', $s['color'] ?? '#0b6e4f', (float)($s['per_day'] ?? 3), (int)($s['min_words'] ?? 1200),
        implode("\n", $s['seeds'] ?? []), (int)($s['adsense'] ?? 1), (int)($s['amazon'] ?? 1), (int)($s['gen'] ?? 1), (int)($s['ymyl'] ?? 0),
        $s['permalink'] ?? '/%slug%/', now(), now(),
    ]);
    $db = site_db($host);
    foreach ($s['categories'] ?? [] as $c) ensure_category($db, $c);
    foreach ($s['alias'] ?? [] as $a) registry()->prepare('INSERT OR REPLACE INTO aliases(alias,host) VALUES(?,?)')->execute([strtolower($a), $host]);
    $cp = !empty($s['sub']) ? cpanel_add_subdomain($s['sub'], $s['root']) : '';
    flog($host, 'site', 'création CLI' . ($cp ? " ; cPanel : $cp" : ''));
    echo "+ $host" . ($cp ? " ($cp)" : '') . "\n";
}
