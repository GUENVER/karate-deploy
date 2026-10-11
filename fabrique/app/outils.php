<?php
// Outils visiteurs : suggestions de recherche (métiers, villes) et simulateur salaire brut → net.
declare(strict_types=1);

// /_ac?t=q|l&s=… : suggestions JSON (10 max), jamais en cache HTML, exclu du robots.txt par /_ (voir out_robots)
function ac_endpoint(array $site): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Robots-Tag: noindex');
    $s = trim(mb_substr((string)($_GET['s'] ?? ''), 0, 40)); $t = (string)($_GET['t'] ?? 'q');
    if (mb_strlen($s) < 2) { echo '[]'; exit; }
    $db = jobs_db($site['host']); $p = slugify($s, 40);
    $out = [];
    try {
        if ($t === 'l') {
            $st = $db->prepare("SELECT name FROM job_cities WHERE slug LIKE ? ORDER BY n DESC LIMIT 8"); $st->execute([$p . '%']);
            $out = $st->fetchAll(PDO::FETCH_COLUMN);
            foreach (JOB_REGIONS as [$rs, $rn]) if (str_starts_with($rs, $p) && count($out) < 10) $out[] = $rn;
        } else {
            $st = $db->prepare("SELECT name FROM job_metiers WHERE slug LIKE ? OR slug LIKE ? ORDER BY (slug LIKE ?) DESC, n DESC LIMIT 10"); $st->execute([$p . '%', '%-' . $p . '%', $p . '%']);
            $out = $st->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Throwable $e) {}
    echo json_encode(array_values(array_unique($out)), JSON_UNESCAPED_UNICODE);
    exit;
}

// Script commun : relie chaque champ métier / lieu des formulaires de recherche à une liste de suggestions.
function ac_script(): string
{
    return '<script>(function(){var n=0;document.querySelectorAll(\'form[action="/chercher/"] input[name="q"],form[action="/chercher/"] input[name="l"]\').forEach(function(i){var t=i.name==="l"?"l":"q",id="ac"+(n++),d=document.createElement("datalist");d.id=id;document.body.appendChild(d);i.setAttribute("list",id);i.setAttribute("autocomplete","off");var w,last="";i.addEventListener("input",function(){var v=i.value.trim();if(v.length<2||v===last)return;last=v;clearTimeout(w);w=setTimeout(function(){fetch("/_ac?t="+t+"&s="+encodeURIComponent(v)).then(function(r){return r.ok?r.json():[]}).then(function(a){d.innerHTML="";a.forEach(function(x){var o=document.createElement("option");o.value=x;d.appendChild(o)})}).catch(function(){})},150)})})})();</script>';
}

function page_simulateur(array $site): string
{
    $faq = [['Comment passer du salaire brut au salaire net ?', 'Le net s\'obtient en retirant les cotisations salariales du brut : environ 22 % pour un salarié non cadre du privé, 25 % pour un cadre, 15 % dans la fonction publique. Le simulateur applique ces taux moyens ; votre bulletin de paie peut légèrement différer selon la convention collective et la mutuelle.'],
        ['Quelle différence entre net avant et après impôt ?', 'Depuis le prélèvement à la source, l\'impôt sur le revenu est retenu directement sur la paie. Indiquez votre taux personnalisé (visible sur votre avis d\'imposition ou votre espace impots.gouv.fr) pour obtenir le net versé sur votre compte.'],
        ['Combien d\'heures dans un mois à temps plein ?', 'Un temps plein à 35 heures par semaine correspond à 151,67 heures par mois, base utilisée pour convertir un salaire horaire en salaire mensuel.']];
    $body = '<p class="crumbs" style="margin-top:24px"><a href="/">Accueil</a> › <a href="/salaire/">Salaires</a> › Simulateur brut net</p>'
        . '<h1>Simulateur salaire brut → net</h1>'
        . '<p class="lead">Convertissez un salaire brut en net (et inversement), par mois, par an ou à l\'heure, selon votre statut et votre taux de prélèvement à la source.</p>'
        . '<form id="sim" onsubmit="return false" style="display:grid;gap:12px;max-width:560px;padding:16px;border:1px solid var(--b);border-radius:12px;background:#fff">'
        . '<label>Salaire brut mensuel (€)<br><input id="sb" type="number" inputmode="decimal" min="0" step="10" value="2000" style="width:100%;padding:10px;font-size:1.1rem;border:1px solid var(--b);border-radius:8px"></label>'
        . '<label>ou salaire net mensuel (€)<br><input id="sn" type="number" inputmode="decimal" min="0" step="10" style="width:100%;padding:10px;font-size:1.1rem;border:1px solid var(--b);border-radius:8px"></label>'
        . '<label>Statut<br><select id="st" style="width:100%;padding:10px;border-radius:8px"><option value="0.22">Salarié non cadre (privé)</option><option value="0.25">Cadre (privé)</option><option value="0.15">Fonction publique</option><option value="0.10">Apprenti (estimation)</option></select></label>'
        . '<label>Temps de travail : <b id="tpv">100 %</b><br><input id="tp" type="range" min="10" max="100" step="5" value="100" style="width:100%"></label>'
        . '<label>Taux de prélèvement à la source (%)<br><input id="pas" type="number" min="0" max="45" step="0.1" value="0" style="width:100%;padding:10px;border:1px solid var(--b);border-radius:8px"></label>'
        . '</form>'
        . '<div id="res" class="kpi" style="margin:16px 0"></div>'
        . job_ad($site)
        . '<p><a href="/salaire/"><strong>Voir les salaires réels par métier et par région →</strong></a> · <a href="/offres-emploi/">Toutes les offres d\'emploi</a></p>'
        . '<h2>Questions fréquentes</h2>' . implode('', array_map(fn($f) => '<h3>' . h($f[0]) . '</h3><p>' . h($f[1]) . '</p>', $faq))
        . '<p class="disc">Estimation fondée sur des taux moyens de cotisations salariales ; seul le bulletin de paie fait foi.</p>'
        . '<script>(function(){var $=function(i){return document.getElementById(i)},f=function(x){return Math.round(x).toLocaleString("fr-FR")+" €"},src="b";'
        . 'function calc(){var r=parseFloat($("st").value),tp=$("tp").value/100,p=(parseFloat($("pas").value)||0)/100,b,n;$("tpv").textContent=$("tp").value+" %";'
        . 'if(src==="n"){n=parseFloat($("sn").value)||0;b=n/(1-r);$("sb").value=Math.round(b)}else{b=parseFloat($("sb").value)||0;n=b*(1-r);$("sn").value=Math.round(n)}'
        . 'var bt=b*tp,nt=n*tp,ap=nt*(1-p);$("res").innerHTML="<div><b>"+f(nt)+"</b>net / mois avant impôt</div><div><b>"+f(ap)+"</b>net / mois après impôt</div><div><b>"+f(bt*12)+"</b>brut / an</div><div><b>"+f(nt*12)+"</b>net / an</div><div><b>"+(b/151.67).toLocaleString("fr-FR",{maximumFractionDigits:2})+" €</b>brut / heure</div>"}'
        . '$("sb").addEventListener("input",function(){src="b";calc()});$("sn").addEventListener("input",function(){src="n";calc()});["st","tp","pas"].forEach(function(i){$(i).addEventListener("input",calc)});calc()})();</script>';
    return layout($site, ['title' => 'Simulateur salaire brut net ' . date('Y') . ' : mensuel, annuel, horaire | ' . $site['name'],
        'desc' => 'Calculez votre salaire net à partir du brut (et inversement) : non cadre, cadre, fonction publique, temps partiel, prélèvement à la source. Gratuit et instantané.',
        'canonical' => 'https://' . $site['host'] . '/simulateur-salaire-brut-net/',
        'schema' => [breadcrumbs($site, [['Salaires', '/salaire/'], ['Simulateur brut net', '/simulateur-salaire-brut-net/']]),
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]]], $body);
}
