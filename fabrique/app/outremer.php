<?php
// Rubrique Outre-mer : page centrale qui regroupe, pour chaque territoire, les offres, l'emploi public, l'alternance, les villes et les employeurs.
declare(strict_types=1);

const OM_REGIONS = ['04', '01', '02', '03', '06']; // La Réunion, Guadeloupe, Martinique, Guyane, Mayotte

function page_outremer(array $site): string
{
    $db = jobs_db($site['host']); $nf = fn($x) => number_format((float)$x, 0, ',', ' ');
    $blocks = ''; $total = 0; $faqN = [];
    foreach (OM_REGIONS as $code) {
        [$rs, $rn] = JOB_REGIONS[$code];
        $q = fn(string $w) => (int)$db->query("SELECT COUNT(*) FROM jobs WHERE status='open' AND region='$code' AND $w")->fetchColumn();
        $n = $q('1'); if (!$n) continue; $total += $n; $faqN[] = $rn . ' (' . $nf($n) . ')';
        $cdi = $q("contract='cdi'"); $pub = $q("id LIKE 'csp-%'"); $alt = $q("contract='alternance'");
        $cities = $db->query("SELECT slug, name, n FROM job_cities WHERE region='$code' ORDER BY n DESC LIMIT 8")->fetchAll();
        $emp = $db->query("SELECT company, COUNT(*) n FROM jobs WHERE status='open' AND region='$code' AND company<>'' AND id NOT LIKE 'csp-%' GROUP BY company ORDER BY n DESC LIMIT 8")->fetchAll();
        $latest = $db->query("SELECT * FROM jobs WHERE status='open' AND region='$code' ORDER BY created_at DESC LIMIT 4")->fetchAll();
        $blocks .= '<section style="margin:26px 0"><h2 id="' . $rs . '">Emploi ' . (in_array($code, ['04', '06'], true) ? 'à ' : 'en ') . h($rn) . ' : ' . $nf($n) . ' offres</h2>'
            . '<p><a href="/offres-emploi/' . $rs . '/"><strong>Toutes les offres</strong></a> (' . $nf($n) . ') · <a href="/emploi-cdi/' . $rs . '/">CDI</a> (' . $nf($cdi) . ')'
            . ($pub ? ' · <a href="/emploi-public/' . $rs . '/">Emploi public</a> (' . $nf($pub) . ')' : '') . ($alt ? ' · <a href="/alternance/' . $rs . '/">Alternance</a> (' . $nf($alt) . ')' : '') . '</p>'
            . ($cities ? '<p><b>Villes :</b> ' . city_links($cities) . '</p>' : '')
            . ($emp ? '<p><b>Employeurs qui recrutent :</b> ' . implode(', ', array_map(fn($e) => (company_is_real((string)$e['company']) ? '<a href="/recruteur/' . h(company_slug((string)$e['company'])) . '/">' . h(company_display((string)$e['company'])) . '</a>' : h((string)$e['company'])) . ' (' . (int)$e['n'] . ')', $emp)) . '</p>' : '')
            . ($latest ? job_grid($site, $latest, false) : '') . '</section>';
        if ($code === OM_REGIONS[0]) $blocks .= job_ad($site);
    }
    $faq = [['Combien d\'offres d\'emploi en Outre-mer ?', 'Au ' . date_fr(now()) . ', ' . $site['name'] . ' recense ' . $nf($total) . ' offres ouvertes : ' . implode(', ', $faqN) . '. Les listes sont mises à jour plusieurs fois par jour.'],
        ['Quelles offres dans la fonction publique en Outre-mer ?', 'Les postes de l\'État, des collectivités et des hôpitaux publiés sur Choisir le service public sont regroupés dans la rubrique emploi public de chaque territoire.'],
        ['Peut-on postuler depuis la métropole ?', 'Oui : la candidature se fait sur le site d\'origine de chaque offre. Certains employeurs précisent les conditions d\'installation (logement, prise en charge du déménagement) dans l\'annonce.']];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/offres-emploi/">Offres d\'emploi</a> › Outre-mer</p>'
        . '<h1>Emploi en Outre-mer : ' . $nf($total) . ' offres</h1>'
        . '<p class="lead">Offres d\'emploi à La Réunion, en Guadeloupe, en Martinique, en Guyane et à Mayotte : CDI, CDD, emploi public et alternance, mises à jour plusieurs fois par jour.</p>'
        . '<p>' . implode(' · ', array_map(fn($c) => '<a href="#' . JOB_REGIONS[$c][0] . '">' . h(JOB_REGIONS[$c][1]) . '</a>', OM_REGIONS)) . '</p>'
        . $blocks . job_ad($site)
        . '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq)) . jobs_disclaimer();
    return layout($site, ['title' => 'Emploi Outre-mer : ' . $nf($total) . ' offres à La Réunion, Guadeloupe, Martinique, Guyane, Mayotte | ' . $site['name'],
        'desc' => 'Offres d\'emploi en Outre-mer : La Réunion, Guadeloupe, Martinique, Guyane, Mayotte. CDI, CDD, emploi public, alternance, employeurs qui recrutent.',
        'canonical' => 'https://' . $site['host'] . '/emploi-outre-mer/',
        'schema' => [breadcrumbs($site, [['Offres d\'emploi', '/offres-emploi/'], ['Outre-mer', '/emploi-outre-mer/']]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f[1]]], $faq)]]], $body);
}
