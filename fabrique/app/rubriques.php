<?php
// Rubriques alternance : formations en apprentissage, entreprises qui recrutent en alternance, missions locales.
// Données La bonne alternance (tables lba_*), déjà importées par cli/lba_ref.php.
declare(strict_types=1);

const LBA_NIVEAUX = [3 => 'CAP, BEP', 4 => 'Bac', 5 => 'Bac+2 (BTS, DUT)', 6 => 'Bac+3 (licence, BUT)', 7 => 'Bac+5 (master, ingénieur)'];

function rub_nf(int $n): string { return number_format($n, 0, ',', ' '); }

// Résultats lourds mis en cache 6 h (tables de plusieurs centaines de milliers de lignes).
function rub_cached(array $site, string $key, callable $fn)
{
    $f = cfg('data_dir') . '/cache/' . $site['host'] . '.rub-' . md5($key) . '.json';
    if (is_file($f) && filemtime($f) > time() - 86400 && ($d = json_decode((string)file_get_contents($f), true)) !== null) return $d;
    $d = $fn();
    @file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $d;
}

function rub_region_counts(array $site, string $table): array
{
    return rub_cached($site, "regions-$table", function () use ($site, $table) {
        $db = jobs_db($site['host']); lba_tables($db);
        $o = []; foreach ($db->query("SELECT region, COUNT(*) n FROM $table GROUP BY region") as $r) if (isset(JOB_REGIONS[$r['region']])) $o[$r['region']] = (int)$r['n'];
        arsort($o); return $o;
    });
}

function rub_region_chips(array $counts, string $prefix): string
{
    return count_chips(array_map(fn($c, $n) => [JOB_REGIONS[$c][1], $n, $prefix . JOB_REGIONS[$c][0] . '/'], array_keys($counts), $counts));
}

function rub_links(string $cur): string
{
    $l = ['/alternance/' => 'Offres en alternance', '/formation-alternance/' => 'Formations en alternance', '/entreprises-alternance/' => 'Entreprises qui recrutent en alternance', '/mission-locale/' => 'Missions locales'];
    unset($l[$cur]);
    return '<h2>Aller plus loin</h2><div class="cnt">' . implode('', array_map(fn($u, $t) => '<a href="' . $u . '">' . h($t) . '</a>', array_keys($l), $l)) . '</div>';
}

function rub_css(): string
{
    return '<style>.rl{list-style:none;padding:0;margin:18px 0}.rl li{border:1px solid var(--b);border-radius:12px;padding:14px 18px;margin:10px 0;background:#fff}'
        . '.rl h3{margin:0 0 4px;font-size:1.05rem;line-height:1.35}.rl p{margin:2px 0;color:var(--m);font-size:.9rem}.rl .bt{display:inline-block;margin-top:8px;font-size:.88rem;font-weight:600}'
        . '.rl .lv{display:inline-block;background:var(--s);border-radius:6px;padding:1px 8px;font-size:.78rem;color:var(--t);margin-right:6px}</style>';
}

// ---------- Formations en alternance ----------
function page_formations(array $site, ?array $reg, int $page): string
{
    $db = jobs_db($site['host']); lba_tables($db);
    $counts = rub_region_counts($site, 'lba_formations');
    $base = '/formation-alternance/' . ($reg ? $reg['slug'] . '/' : '');
    $crumbs = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/alternance/">Alternance</a> › ' . ($reg ? '<a href="/formation-alternance/">Formations en alternance</a> › ' . h($reg['name']) : 'Formations en alternance') . '</p>';
    $niv = (int)($_GET['niveau'] ?? 0); if (!isset(LBA_NIVEAUX[$niv])) $niv = 0;
    if (!$reg) {
        $total = array_sum($counts);
        if ($total < 1) return '';
        $top = rub_cached($site, 'form-top', fn() => $db->query("SELECT title, MIN(niveau) niveau, COUNT(*) n FROM lba_formations GROUP BY title ORDER BY n DESC LIMIT 40")->fetchAll());
        $body = $crumbs . '<h1>Formations en alternance : ' . rub_nf($total) . ' formations en apprentissage</h1>'
            . '<p class="lead">Trouvez une formation en apprentissage près de chez vous : CAP, bac pro, BTS, BUT, licence, master ou titre professionnel. L\'alternance permet d\'obtenir un diplôme gratuitement, en étant salarié d\'une entreprise qui vous forme au métier.</p>'
            . '<h2>Formations en alternance par région</h2>' . rub_region_chips($counts, '/formation-alternance/')
            . '<h2>Les formations en alternance les plus proposées</h2><ul>' . implode('', array_map(fn($r) => '<li>' . h($r['title']) . (isset(LBA_NIVEAUX[(int)$r['niveau']]) ? ' — <small>' . h(LBA_NIVEAUX[(int)$r['niveau']]) . '</small>' : '') . ' : ' . rub_nf((int)$r['n']) . ' établissements</li>', $top)) . '</ul>'
            . '<h2>Comment trouver une formation en apprentissage ?</h2><p>Choisissez d\'abord le diplôme visé, puis un centre de formation (CFA) proche de chez vous. L\'inscription définitive se fait une fois un contrat d\'apprentissage signé avec un employeur : consultez en parallèle les <a href="/alternance/">offres en alternance</a> et les <a href="/entreprises-alternance/">entreprises qui recrutent des alternants</a>. Les <a href="/mission-locale/">missions locales</a> accompagnent gratuitement les 16-25 ans dans ces démarches.</p>'
            . rub_links('/formation-alternance/') . '<p class="disc">Source : catalogue national des formations en apprentissage (Réseau des Carif-Oref, ONISEP), via La bonne alternance.</p>';
        return layout($site, ['title' => 'Formations en alternance : ' . rub_nf($total) . ' formations en apprentissage par région | ' . $site['name'],
            'desc' => rub_nf($total) . ' formations en apprentissage en France : CAP, bac pro, BTS, licence, master. Trouvez votre formation en alternance par région.',
            'canonical' => 'https://' . $site['host'] . '/formation-alternance/', 'schema' => [breadcrumbs($site, [['Alternance', '/alternance/'], ['Formations en alternance', '/formation-alternance/']])]], rub_css() . $body);
    }
    $w = 'region=?' . ($niv ? ' AND niveau=?' : ''); $args = $niv ? [$reg['code'], $niv] : [$reg['code']];
    $st = $db->prepare("SELECT COUNT(*) FROM lba_formations WHERE $w"); $st->execute($args); $total = (int)$st->fetchColumn();
    if ($total < 1) return '';
    $per = 40; $pages = (int)ceil($total / $per); if ($page > $pages) return '';
    $st = $db->prepare("SELECT * FROM lba_formations WHERE $w ORDER BY cname, niveau, title LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute($args);
    $rows = $st->fetchAll();
    $byN = rub_cached($site, 'form-niv-' . $reg['code'], function () use ($db, $reg) { $s = $db->prepare('SELECT niveau, COUNT(*) n FROM lba_formations WHERE region=? GROUP BY niveau'); $s->execute([$reg['code']]); return $s->fetchAll(PDO::FETCH_KEY_PAIR); });
    $chips = count_chips(array_values(array_filter(array_map(fn($k, $l) => !empty($byN[$k]) ? [$l, $byN[$k], $base . '?niveau=' . $k] : null, array_keys(LBA_NIVEAUX), LBA_NIVEAUX))));
    $li = '';
    foreach ($rows as $r) {
        $lv = LBA_NIVEAUX[(int)$r['niveau']] ?? '';
        $li .= '<li><h3>' . h($r['title']) . '</h3><p>' . ($lv ? '<span class="lv">' . h($lv) . '</span>' : '') . h($r['organisme']) . ' — ' . h($r['cname'] ?: $r['city']) . ($r['postal'] ? ' (' . h($r['postal']) . ')' : '') . '</p>'
            . (($r['duree'] || $r['debut']) ? '<p>' . ($r['duree'] ? 'Durée : ' . h($r['duree']) : '') . ($r['debut'] && preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$r['debut']) && substr((string)$r['debut'], 0, 10) >= gmdate('Y-m-d') ? ($r['duree'] ? ' · ' : '') . 'Rentrée : ' . h(date_fr(substr((string)$r['debut'], 0, 10))) : '') . '</p>' : '')
            . ($r['url'] ? '<a class="bt" href="' . h($r['url']) . '" rel="nofollow noopener" target="_blank">Voir la fiche formation ›</a>' : '') . '</li>';
    }
    $t = 'Formations en alternance en ' . $reg['name'] . ($niv ? ' — ' . LBA_NIVEAUX[$niv] : '');
    $body = $crumbs . '<h1>' . h($t) . ' : ' . rub_nf($total) . ' formations</h1>'
        . '<p class="lead">' . rub_nf($total) . ' formations en apprentissage en ' . h($reg['name']) . ', classées par ville. Filtrez par niveau de diplôme, puis consultez la fiche de chaque formation.</p>'
        . '<h2>Par niveau de diplôme</h2>' . $chips . '<ul class="rl">' . $li . '</ul>'
        . ($niv ? preg_replace('#href="([^"?]+)"#', 'href="$1?niveau=' . $niv . '"', pager($base, $page, $pages)) : pager($base, $page, $pages)) . (count($counts) > 1 ? '<h2>Autres régions</h2>' . rub_region_chips(array_diff_key($counts, [$reg['code'] => 1]), '/formation-alternance/') : '')
        . rub_links('/formation-alternance/') . '<p class="disc">Source : catalogue national des formations en apprentissage (Réseau des Carif-Oref, ONISEP), via La bonne alternance.</p>';
    return layout($site, ['title' => $t . ' : ' . rub_nf($total) . ' formations' . ($page > 1 ? " — page $page" : '') . ' | ' . $site['name'],
        'desc' => rub_nf($total) . ' formations en apprentissage en ' . $reg['name'] . ' : CAP, bac pro, BTS, licence, master. Durée, rentrée et centre de formation.',
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''), 'robots' => $niv ? 'noindex,follow' : null,
        'schema' => [breadcrumbs($site, [['Alternance', '/alternance/'], ['Formations en alternance', '/formation-alternance/'], [$reg['name'], $base]])]], rub_css() . $body);
}

// ---------- Entreprises qui recrutent en alternance ----------
function page_entreprises_alt(array $site, ?array $reg, int $page): string
{
    $db = jobs_db($site['host']); lba_tables($db);
    $counts = rub_region_counts($site, 'lba_recruteurs');
    $base = '/entreprises-alternance/' . ($reg ? $reg['slug'] . '/' : '');
    $crumbs = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/alternance/">Alternance</a> › ' . ($reg ? '<a href="/entreprises-alternance/">Entreprises qui recrutent en alternance</a> › ' . h($reg['name']) : 'Entreprises qui recrutent en alternance') . '</p>';
    $src = '<p class="disc">Source : La bonne alternance (algorithme de prédiction des recrutements en alternance, ministère du Travail). Le lien « Candidater » permet d\'envoyer une candidature spontanée.</p>';
    if (!$reg) {
        $total = array_sum($counts);
        if ($total < 1) return '';
        $body = $crumbs . '<h1>Entreprises qui recrutent en alternance : ' . rub_nf($total) . ' employeurs</h1>'
            . '<p class="lead">Ces entreprises sont identifiées comme susceptibles de recruter un apprenti ou un contrat de professionnalisation dans les prochains mois, même si elles n\'ont pas publié d\'offre : une candidature spontanée a de vraies chances d\'aboutir.</p>'
            . '<h2>Par région</h2>' . rub_region_chips($counts, '/entreprises-alternance/')
            . '<h2>Pourquoi envoyer une candidature spontanée ?</h2><p>La majorité des contrats d\'alternance sont signés sans offre publiée. Ciblez les entreprises proches de votre centre de formation, présentez le diplôme préparé et le rythme d\'alternance, et relancez une semaine plus tard. Pas encore de formation ? Consultez les <a href="/formation-alternance/">formations en alternance</a>.</p>'
            . rub_links('/entreprises-alternance/') . $src;
        return layout($site, ['title' => 'Entreprises qui recrutent en alternance : ' . rub_nf($total) . ' employeurs par région | ' . $site['name'],
            'desc' => rub_nf($total) . ' entreprises susceptibles de recruter un alternant : apprentissage et contrat de professionnalisation. Candidature spontanée par région.',
            'canonical' => 'https://' . $site['host'] . '/entreprises-alternance/', 'schema' => [breadcrumbs($site, [['Alternance', '/alternance/'], ['Entreprises qui recrutent en alternance', '/entreprises-alternance/']])]], rub_css() . $body);
    }
    $total = $counts[$reg['code']] ?? 0;
    if ($total < 1) return '';
    $per = 50; $pages = (int)ceil($total / $per); if ($page > $pages) return '';
    $st = $db->prepare("SELECT * FROM lba_recruteurs WHERE region=? AND name<>'' ORDER BY cname, name LIMIT $per OFFSET " . (($page - 1) * $per)); $st->execute([$reg['code']]);
    $li = '';
    foreach ($st->fetchAll() as $r) {
        $li .= '<li><h3>' . h(mb_convert_case(mb_strtolower((string)$r['name']), MB_CASE_TITLE)) . '</h3><p>' . h($r['naf']) . '</p><p>' . h($r['cname'] ?: $r['city']) . ($r['postal'] ? ' (' . h($r['postal']) . ')' : '') . ($r['size'] && $r['size'] !== 'NN' ? ' · ' . h($r['size']) . ' salariés' : '') . '</p>'
            . ($r['url'] ? '<a class="bt" href="' . h($r['url']) . '" rel="nofollow noopener" target="_blank">Candidater ›</a>' : '') . '</li>';
    }
    $t = 'Entreprises qui recrutent en alternance en ' . $reg['name'];
    $body = $crumbs . '<h1>' . h($t) . ' : ' . rub_nf($total) . ' employeurs</h1>'
        . '<p class="lead">' . rub_nf($total) . ' entreprises de ' . h($reg['name']) . ' susceptibles de recruter un alternant, classées par ville. Envoyez une candidature spontanée en indiquant le diplôme préparé et votre rythme d\'alternance.</p>'
        . '<ul class="rl">' . $li . '</ul>' . pager($base, $page, $pages)
        . '<h2>Offres et formations en ' . h($reg['name']) . '</h2><div class="cnt"><a href="/alternance/' . $reg['slug'] . '/">Offres en alternance</a><a href="/formation-alternance/' . $reg['slug'] . '/">Formations en alternance</a></div>'
        . (count($counts) > 1 ? '<h2>Autres régions</h2>' . rub_region_chips(array_diff_key($counts, [$reg['code'] => 1]), '/entreprises-alternance/') : '') . rub_links('/entreprises-alternance/') . $src;
    return layout($site, ['title' => $t . ($page > 1 ? " — page $page" : '') . ' | ' . $site['name'],
        'desc' => rub_nf($total) . ' entreprises susceptibles de recruter un alternant en ' . $reg['name'] . ' : apprentissage, contrat de professionnalisation, candidature spontanée.',
        'canonical' => 'https://' . $site['host'] . $base . ($page > 1 ? "page/$page/" : ''),
        'schema' => [breadcrumbs($site, [['Alternance', '/alternance/'], ['Entreprises qui recrutent en alternance', '/entreprises-alternance/'], [$reg['name'], $base]])]], rub_css() . $body);
}

// ---------- Missions locales ----------
function rub_missions_by_dep(array $site): array
{
    return rub_cached($site, 'missions-dep', function () use ($site) {
        $db = jobs_db($site['host']); lba_tables($db);
        $o = []; foreach ($db->query('SELECT cp FROM lba_missions') as $r) { $d = dept_of((string)$r['cp']); if (isset(DEPTS[$d])) $o[$d] = ($o[$d] ?? 0) + 1; }
        ksort($o); return $o;
    });
}

function page_missions(array $site, ?string $dep): string
{
    $db = jobs_db($site['host']); lba_tables($db);
    $byDep = rub_missions_by_dep($site);
    if (!$byDep) return '';
    $crumbs = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/alternance/">Alternance</a> › ' . ($dep ? '<a href="/mission-locale/">Missions locales</a> › ' . h(DEPTS[$dep]) : 'Missions locales') . '</p>';
    $intro = 'Les missions locales accompagnent gratuitement les jeunes de 16 à 25 ans sortis du système scolaire : recherche d\'emploi ou d\'alternance, orientation, formation, Contrat d\'engagement jeune, mais aussi logement, santé et mobilité.';
    if (!$dep) {
        $total = array_sum($byDep);
        $body = $crumbs . '<h1>Missions locales : les ' . rub_nf($total) . ' adresses par département</h1><p class="lead">' . $intro . '</p>'
            . '<h2>Trouver sa mission locale</h2>' . count_chips(array_map(fn($d, $n) => [DEPTS[$d] . ' (' . $d . ')', $n, '/mission-locale/' . dept_slug((string)$d) . '/'], array_keys($byDep), $byDep))
            . rub_links('/mission-locale/') . '<p class="disc">Source : annuaire des missions locales, via La bonne alternance.</p>';
        return layout($site, ['title' => 'Missions locales : adresses et contacts par département | ' . $site['name'],
            'desc' => 'Annuaire des ' . rub_nf($total) . ' missions locales de France : adresse, téléphone, e-mail. Accompagnement gratuit des 16-25 ans vers l\'emploi et l\'alternance.',
            'canonical' => 'https://' . $site['host'] . '/mission-locale/', 'schema' => [breadcrumbs($site, [['Alternance', '/alternance/'], ['Missions locales', '/mission-locale/']])]], rub_css() . $body);
    }
    if (empty($byDep[$dep])) return '';
    $rows = array_values(array_filter($db->query('SELECT * FROM lba_missions ORDER BY ville, nom')->fetchAll(), fn($r) => dept_of((string)$r['cp']) === $dep));
    $li = '';
    foreach ($rows as $r) {
        $tel = trim((string)$r['tel']); $web = trim((string)$r['web']);
        if ($web !== '' && !preg_match('#^https?://#i', $web)) $web = 'https://' . $web;
        $li .= '<li><h3>Mission locale ' . h(mb_convert_case(mb_strtolower((string)$r['nom']), MB_CASE_TITLE)) . '</h3><p>' . h($r['adresse']) . ', ' . h($r['cp']) . ' ' . h(mb_convert_case(mb_strtolower((string)$r['ville']), MB_CASE_TITLE)) . '</p>'
            . '<p>' . ($tel ? 'Tél. <a href="tel:' . h(preg_replace('/[^0-9+]/', '', $tel)) . '">' . h($tel) . '</a>' : '') . ($r['email'] ? ($tel ? ' · ' : '') . '<a href="mailto:' . h($r['email']) . '">' . h($r['email']) . '</a>' : '') . '</p>'
            . ($web ? '<a class="bt" href="' . h($web) . '" rel="nofollow noopener" target="_blank">Site de la mission locale ›</a>' : '') . '</li>';
    }
    $name = DEPTS[$dep];
    $body = $crumbs . '<h1>Missions locales ' . h($name) . ' (' . $dep . ') : ' . count($rows) . ' adresse' . (count($rows) > 1 ? 's' : '') . '</h1><p class="lead">' . $intro . '</p>'
        . '<ul class="rl">' . $li . '</ul><h2>Autres départements</h2>'
        . count_chips(array_map(fn($d, $n) => [DEPTS[$d] . ' (' . $d . ')', $n, '/mission-locale/' . dept_slug((string)$d) . '/'], array_keys(array_diff_key($byDep, [$dep => 1])), array_diff_key($byDep, [$dep => 1])))
        . rub_links('/mission-locale/') . '<p class="disc">Source : annuaire des missions locales, via La bonne alternance.</p>';
    return layout($site, ['title' => 'Mission locale ' . $name . ' (' . $dep . ') : adresses et contacts | ' . $site['name'],
        'desc' => count($rows) . ' missions locales en ' . $name . ' : adresse, téléphone, e-mail. Accompagnement gratuit des 16-25 ans : emploi, alternance, formation.',
        'canonical' => 'https://' . $site['host'] . '/mission-locale/' . dept_slug($dep) . '/', 'schema' => [breadcrumbs($site, [['Alternance', '/alternance/'], ['Missions locales', '/mission-locale/'], [$name, '/mission-locale/' . dept_slug($dep) . '/']])]], rub_css() . $body);
}

// URL des rubriques pour le sitemap des pages.
function rub_sitemap_urls(array $site): array
{
    $u = ['/formation-alternance/', '/entreprises-alternance/', '/mission-locale/'];
    foreach (rub_region_counts($site, 'lba_formations') as $c => $n) $u[] = '/formation-alternance/' . JOB_REGIONS[$c][0] . '/';
    foreach (rub_region_counts($site, 'lba_recruteurs') as $c => $n) $u[] = '/entreprises-alternance/' . JOB_REGIONS[$c][0] . '/';
    foreach (rub_missions_by_dep($site) as $d => $n) $u[] = '/mission-locale/' . dept_slug((string)$d) . '/';
    return $u;
}
