<?php
// Décodeur d'annonce (règles, sans IA) : ce que l'annonce dit et ne dit pas, exigé / souhaité, vocabulaire, questions à poser.
// Chaque élément affiché cite le passage de l'annonce dont il provient. Aucune déduction sur les intentions du recruteur.
declare(strict_types=1);

const JD_GLOSS = [
    '~\bCACES\b~u' => ['CACES', 'Certificat d\'aptitude à la conduite en sécurité d\'engins (chariots, nacelles, grues…). La lettre R suivie d\'un numéro indique la famille d\'engins (R489 : chariots élévateurs).'],
    '~\bSST\b~u' => ['SST', 'Sauveteur secouriste du travail : formation aux premiers secours en entreprise.'],
    '~habilitations? électriques?|\bB0\b|\bH0V\b|\bBR\b|\bBS\b~u' => ['Habilitation électrique', 'Autorisation délivrée par l\'employeur, après formation, pour travailler sur ou près d\'installations électriques (B0, BS, BR, B1V, B2V…).'],
    '~\b[32]\s?x\s?8\b|\b5\s?x\s?8\b~iu' => ['2x8 / 3x8', 'Travail en équipes qui se relaient sur 24 h : matin, après-midi et, en 3x8, nuit, souvent en alternance d\'une semaine à l\'autre.'],
    '~astreintes?~iu' => ['Astreinte', 'Période pendant laquelle le salarié doit rester joignable pour intervenir si besoin ; elle donne lieu à compensation.'],
    '~\bRTT\b~u' => ['RTT', 'Jours de réduction du temps de travail, accordés quand l\'horaire dépasse 35 h par semaine.'],
    '~13e mois|13ème mois|treizième mois~iu' => ['13e mois', 'Prime annuelle équivalente à un mois de salaire supplémentaire, versée en une ou plusieurs fois.'],
    '~\bCSE\b~u' => ['CSE', 'Comité social et économique : instance représentant le personnel, qui gère souvent des avantages (chèques-vacances, billetterie…).'],
    '~intéressement~iu' => ['Intéressement', 'Prime collective liée aux résultats ou aux performances de l\'entreprise, selon un accord.'],
    '~participation (?:aux|des) (?:résultats|bénéfices)|intéressement et participation|participation et intéressement~iu' => ['Participation', 'Part des bénéfices redistribuée aux salariés, obligatoire dans les entreprises d\'au moins 50 salariés.'],
    '~\bHCR\b~u' => ['HCR', 'Convention collective des hôtels, cafés, restaurants.'],
    '~\bSyntec\b~iu' => ['Syntec', 'Convention collective des bureaux d\'études, du conseil et du numérique.'],
    '~convention collective~iu' => ['Convention collective', 'Accord de branche qui fixe salaires minimaux, primes, congés et conditions de travail du secteur.'],
    '~\bETP\b~u' => ['ETP', 'Équivalent temps plein : 1 ETP correspond à un poste à temps plein.'],
    '~\bSMIC\b~u' => ['SMIC', 'Salaire minimum légal en France, fixé par l\'État et revalorisé au moins une fois par an.'],
    '~\bbrut\b~iu' => ['Brut', 'Salaire avant cotisations sociales ; le net versé est plus faible (dans le privé, environ 22 % de moins).'],
    '~\bEHPAD\b~u' => ['EHPAD', 'Établissement d\'hébergement pour personnes âgées dépendantes.'],
    '~\bSSIAD\b~u' => ['SSIAD', 'Service de soins infirmiers à domicile.'],
    '~\bSAAD\b|\bSAD\b~u' => ['SAAD / SAD', 'Service d\'aide (et de soins) à domicile.'],
    '~\bDEAS\b~u' => ['DEAS', 'Diplôme d\'État d\'aide-soignant.'],
    '~\bDEAES\b~u' => ['DEAES', 'Diplôme d\'État d\'accompagnant éducatif et social.'],
    '~\bIDE\b~u' => ['IDE', 'Infirmier diplômé d\'État.'],
    '~\bASH\b~u' => ['ASH', 'Agent de service hospitalier : entretien des locaux et aide au quotidien en établissement de santé.'],
    '~\bAES\b~u' => ['AES', 'Accompagnant éducatif et social.'],
    '~\bAMP\b~u' => ['AMP', 'Aide médico-psychologique (ancien diplôme intégré au DEAES).'],
    '~\bBAFA\b~u' => ['BAFA', 'Brevet d\'aptitude aux fonctions d\'animateur en accueil collectif de mineurs.'],
    '~\bBPJEPS\b~u' => ['BPJEPS', 'Brevet professionnel de la jeunesse, de l\'éducation populaire et du sport.'],
    '~\bATSEM\b~u' => ['ATSEM', 'Agent territorial spécialisé des écoles maternelles.'],
    '~\bFIMO\b|\bFCO\b~u' => ['FIMO / FCO', 'Formations obligatoires des conducteurs routiers professionnels (initiale, puis continue tous les 5 ans).'],
    '~\bADR\b~u' => ['ADR', 'Réglementation du transport routier de marchandises dangereuses, avec formation spécifique du conducteur.'],
    '~permis\s+C\s?E\b|permis\s+EC\b|\bSPL\b~iu' => ['Permis CE / SPL', 'Permis poids lourd avec remorque (semi-remorque, « super poids lourd »).'],
    '~permis\s+C\b(?!\s?E)|\bPL\b~iu' => ['Permis C / PL', 'Permis poids lourd (véhicule de plus de 3,5 t).'],
    '~permis\s+D\b~iu' => ['Permis D', 'Permis transport en commun de personnes (car, bus).'],
    '~\bVL\b~u' => ['VL', 'Véhicule léger (permis B).'],
    '~\bERP\b~u' => ['ERP', 'Logiciel de gestion intégré (SAP, Sage, Cegid…) ou, dans le bâtiment, établissement recevant du public.'],
    '~\bCRM\b~u' => ['CRM', 'Logiciel de gestion de la relation client.'],
    '~\bKPI\b~u' => ['KPI', 'Indicateurs de performance suivis par l\'équipe.'],
    '~\bB2B\b~u' => ['B2B', 'Vente ou services aux entreprises (et non aux particuliers).'],
    '~\bB2C\b~u' => ['B2C', 'Vente ou services aux particuliers.'],
    '~\bHACCP\b~u' => ['HACCP', 'Méthode d\'hygiène et de sécurité alimentaire ; une formation est souvent demandée en restauration.'],
    '~\bGMS\b~u' => ['GMS', 'Grandes et moyennes surfaces (supermarchés, hypermarchés).'],
    '~\bWMS\b~u' => ['WMS', 'Logiciel de gestion d\'entrepôt.'],
    '~préparation de commandes|préparateur de commandes|picking~iu' => ['Préparation de commandes', 'Prélever les articles en entrepôt selon les commandes, souvent avec un terminal ou une commande vocale.'],
    '~\bCQP\b~u' => ['CQP', 'Certificat de qualification professionnelle, délivré par une branche professionnelle.'],
    '~\bVAE\b~u' => ['VAE', 'Validation des acquis de l\'expérience : obtenir un diplôme grâce à son expérience.'],
    '~\bCPF\b~u' => ['CPF', 'Compte personnel de formation, utilisable pour financer une formation.'],
    '~\bPOEI\b~u' => ['POEI', 'Préparation opérationnelle à l\'emploi individuelle : formation financée avant une embauche.'],
    '~\bAFPR\b~u' => ['AFPR', 'Action de formation préalable au recrutement, financée par France Travail.'],
    '~\bCDI[- ]?I\b|CDI intérimaire~iu' => ['CDI intérimaire', 'CDI signé avec une agence d\'intérim, qui enchaîne les missions et paie entre deux missions.'],
    '~\bmission d.intérim|\bintérim\b~iu' => ['Intérim', 'Contrat de mission via une agence de travail temporaire, avec indemnité de fin de mission (10 %) et congés payés (10 %).'],
    '~période d.essai~iu' => ['Période d\'essai', 'Début du contrat pendant lequel chacun peut y mettre fin plus facilement ; sa durée maximale est fixée par la loi et la convention collective.'],
    '~\bcontractuel(le)?s?\b~iu' => ['Contractuel', 'Agent public recruté par contrat, sans concours.'],
    '~ouverts? aux titulaires|titulaires? (?:et|ou) (?:aux )?contractuels|fonctionnaires? titulaires?~iu' => ['Titulaire', 'Dans la fonction publique : fonctionnaire ayant réussi un concours et été titularisé.'],
    '~catégorie\s+[ABC]\b~iu' => ['Catégories A, B, C', 'Niveaux de la fonction publique : A (conception, encadrement), B (application, rédaction), C (exécution).'],
    '~\bRIFSEEP\b|\bIFSE\b|\bCIA\b~u' => ['RIFSEEP', 'Régime indemnitaire de la fonction publique : IFSE (part fixe liée au poste) et CIA (part variable).'],
    '~\bNBI\b~u' => ['NBI', 'Nouvelle bonification indiciaire : points d\'indice supplémentaires liés à certaines fonctions publiques.'],
    '~\bCNAS\b~u' => ['CNAS', 'Comité national d\'action sociale : prestations sociales et loisirs pour les agents territoriaux.'],
    '~\bprime\s+de\s+précarité|indemnité de fin de contrat~iu' => ['Prime de précarité', 'Indemnité de fin de CDD égale en général à 10 % des salaires bruts.'],
    '~travail posté~iu' => ['Travail posté', 'Organisation en équipes successives sur un même poste (2x8, 3x8…).'],
    '~horaires? (?:en )?coupure|coupure~iu' => ['Horaires en coupure', 'Journée de travail interrompue par une longue pause (par exemple 10 h-14 h puis 18 h-22 h).'],
    '~polyvalen(t|te|ce)~iu' => ['Polyvalence', 'Le poste regroupe plusieurs types de tâches ; demandez lesquelles occupent l\'essentiel du temps.'],
    '~\bmanutention\b|port de charges~iu' => ['Manutention', 'Déplacement manuel de charges ; demandez le poids et la fréquence.'],
    '~\bEPI\b~u' => ['EPI', 'Équipements de protection individuelle (chaussures de sécurité, gants…), fournis par l\'employeur.'],
    '~\bQSE\b|\bQHSE\b~u' => ['QSE / QHSE', 'Qualité, (hygiène), sécurité, environnement.'],
    '~\bTMS\b~u' => ['TMS', 'Troubles musculo-squelettiques (douleurs liées aux gestes répétitifs ou postures).'],
    '~\bpack office\b~iu' => ['Pack Office', 'Logiciels Word, Excel, PowerPoint, Outlook.'],
    '~\bSaaS\b~u' => ['SaaS', 'Logiciel utilisé en ligne par abonnement.'],
    '~\bfull[- ]stack\b~iu' => ['Full stack', 'Développement de la partie visible (front-end) et de la partie serveur (back-end) d\'une application.'],
    '~\bdevops\b~iu' => ['DevOps', 'Pratiques qui rapprochent développement et exploitation (déploiement, automatisation, supervision).'],
    '~\bscrum\b|\bagile\b~iu' => ['Méthode agile', 'Organisation du travail par courtes itérations avec points d\'équipe réguliers.'],
];
const JD_MUST = '~\b(obligatoire|exig[ée]e?s?|indispensable|imp[ée]rati(f|ve)|requise?s?|(?:est|sont|serait|seront) n[ée]cessaires?|doit être titulaire|être titulaire|titulaire (du|d.un|de la|des)|impérativement)\b~iu';
const JD_NICE = '~\b(souhait[ée]e?s?|souhaitable|appr[ée]ci[ée]e?s?|serait un plus|est un plus|un plus|idéalement|de préférence|si possible|bienvenue?s?|apprécierons)\b~iu';
const JD_QUALIF = '~permis\s+(?:[A-D]\s?E?|B)\b|\bCACES\b[^.;\n]{0,25}|\bR\s?4[0-9]{2}\b|habilitations? électriques?|\bSST\b|\bFIMO\b|\bFCO\b|\bADR\b|\bBAFA\b|\bBPJEPS\b|\bDEAS\b|\bDEAES\b|diplôme d.[ée]tat[^.;\n]{0,40}|\bBTS\b[^.;\n]{0,30}|\bBUT\b|\bDUT\b|\bCAP\b[^.;\n]{0,30}|\bBEP\b|\bbac\s?(?:\+\s?\d|pro)?\b[^.;\n]{0,25}|licence|master|\bHACCP\b|carte professionnelle|\bCQP\b[^.;\n]{0,30}|anglais(?: courant| professionnel| technique)?~iu';
const JD_BENEF = '~mutuelle|tickets?[- ]restaurants?|titres?[- ]restaurants?|carte (?:déjeuner|swile)|prime[s]? (?:de |d.)?[\p{L}]+|13e mois|13ème mois|treizième mois|\bRTT\b|\bCSE\b|comité d.entreprise|intéressement|participation aux bénéfices|véhicule de (?:service|fonction)|logement (?:fourni|possible|de fonction)|paniers? repas|indemnités? kilométriques?|chèques?[- ]vacances|prise en charge (?:à \d+ ?% )?(?:du |des )?(?:transport|abonnement|titre de transport)|tenue fournie|plan d.épargne|\bPEE\b|\bCNAS\b|\bNBI\b|\bRIFSEEP\b~iu';

function jd_sentences(string $t): array
{
    $t = preg_replace('/[•▪●►✓✔➢➤\x{2022}]/u', "\n", $t);
    $t = preg_replace('/(?<=[.!?;:])-\s?|(?<=\p{Ll})(?=\p{Lu}\p{Ll}{2,}\s)/u', "\n", $t); // « bureau.- Effectuer », « du posteMissions »
    $parts = preg_split('/(?<=[.!?;])\s+(?=[\p{Lu}0-9«-])|\s[-–]\s(?=\p{Lu})|\n+/u', $t) ?: [];
    return array_values(array_filter(array_map(fn($s) => trim($s, " \t-–*:"), $parts), fn($s) => mb_strlen($s) >= 4));
}
function jd_q(string $s, int $max = 200): string { return '« ' . h(mb_strimwidth(trim($s), 0, $max, '…')) . ' »'; }
// Passage autour de la correspondance (phrase entière si elle est courte).
function jd_win(string $s, string $re, int $max = 180): string
{
    if (mb_strlen($s) <= $max || !preg_match($re, $s, $m, PREG_OFFSET_CAPTURE)) return $s;
    $pos = mb_strlen(substr($s, 0, $m[0][1])); $from = max(0, $pos - 70);
    $w = mb_substr($s, $from, $max);
    if ($from > 0) $w = '…' . preg_replace('/^\S*\s/u', '', $w);
    return preg_replace('/\s\S*$/u', '', $w) . '…';
}
function jd_first(array $sent, string $re): string { foreach ($sent as $s) if (preg_match($re, $s)) return jd_win($s, $re); return ''; }

function jd_analyse(array $j, string $desc): array
{
    $sent = jd_sentences($desc);
    $txt = $j['title'] . "\n" . $desc;
    $a = ['said' => [], 'missing' => [], 'must' => [], 'nice' => [], 'benef' => [], 'gloss' => [], 'q' => []];
    // 1. ce que l'annonce dit (champ structuré de la source, sinon phrase du texte)
    $salTxt = jd_first($sent, '~\d[\d \x{A0}\x{202F}]*(?:[.,]\d+)?\s?(?:€|euros?|k€)~iu');
    if ((string)$j['salary'] !== '') $a['said']['Salaire'] = [h((string)$j['salary']), 'champ « salaire » de l\'annonce'];
    elseif ($salTxt !== '' && preg_match('~salaire|rémunération|brut|net|/\s?(?:mois|an|h)|par (?:mois|an|heure)|taux horaire~iu', $salTxt)) $a['said']['Salaire'] = [jd_q($salTxt), 'texte de l\'annonce'];
    else $a['missing'][] = 'Salaire';
    $ct = (string)($j['contract_label'] ?: (JOB_CONTRACTS[$j['contract']] ?? ''));
    $dur = jd_first($sent, '~(?:durée|contrat|mission)[^.]{0,40}\b\d+\s?(?:mois|semaines?|jours?)\b|\b\d+\s?(?:mois|semaines?)\b[^.]{0,20}(?:renouvelable|minimum)~iu');
    if ($ct !== '') $a['said']['Contrat'] = [h($ct) . ($dur !== '' && in_array($j['contract'], ['cdd', 'interim'], true) ? ' — ' . jd_q($dur, 140) : ''), $dur !== '' ? 'champ « contrat » et texte' : 'champ « contrat »'];
    if (in_array($j['contract'], ['cdd', 'interim'], true) && $dur === '' && !preg_match('~\d+\s?(?:Mois|Jours?|Semaines?)~iu', $ct)) $a['missing'][] = 'Durée du contrat';
    $hor = jd_first($sent, '~\b\d{1,2}\s?h\s?\d{0,2}\s?(?:à|-|–)\s?\d{1,2}\s?h|du lundi au (?:vendredi|samedi|dimanche)|horaires?\s+(?:de journée|de nuit|décalés|variables|fixes|en coupure|en équipe)|\b[235]\s?x\s?8\b|travail de nuit|de nuit|week-?ends?|samedis?|dimanches?|astreintes?|travail posté~iu');
    $wt = (string)$j['worktime'];
    if ($wt !== '' || $hor !== '') $a['said']['Horaires'] = [trim(h($wt) . ($hor !== '' ? ($wt !== '' ? ' — ' : '') . jd_q($hor, 160) : '')), $hor !== '' ? 'texte de l\'annonce' : 'champ « durée du travail »'];
    if ($hor === '') $a['missing'][] = $wt !== '' ? 'Horaires précis et jours travaillés' : 'Horaires et jours travaillés';
    $loc = trim((string)$j['city'] . ' ' . (string)$j['postal']);
    $dep = jd_first($sent, '~déplacements?|mobilité géographique|itinérant|tournées?|grands? déplacements?~iu');
    if ($loc !== '' || $dep !== '') $a['said']['Lieu'] = [h($loc) . ($dep !== '' ? ($loc !== '' ? ' — ' : '') . jd_q($dep, 160) : ''), $dep !== '' ? 'champ « lieu » et texte' : 'champ « lieu »'];
    $tele = jd_first($sent, JX_TELE_POS);
    if ($tele !== '') $a['said']['Télétravail'] = [jd_q($tele, 160), 'texte de l\'annonce'];
    else $a['missing'][] = 'Télétravail';
    $exp = (string)$j['experience']; $expT = jd_first($sent, '~\b\d+\s?(?:ans?|années?)\s+(?:d.)?exp[ée]rience|exp[ée]rience\s+(?:de|d.au moins|minimum)\s+\d+|d[ée]butant~iu');
    if ($exp !== '' || $expT !== '') $a['said']['Expérience'] = [trim(h($exp) . ($expT !== '' ? ($exp !== '' ? ' — ' : '') . jd_q($expT, 160) : '')), $expT !== '' ? 'texte de l\'annonce' : 'champ « expérience »'];
    else $a['missing'][] = 'Expérience attendue';
    $start = jd_first($sent, '~dès que possible|\basap\b|prise de poste|poste à pourvoir|démarrage|début (?:de (?:la )?mission|du contrat)|à partir du \d|à compter du~iu');
    if ($start !== '') $a['said']['Prise de poste'] = [jd_q($start, 140), 'texte de l\'annonce'];
    else $a['missing'][] = 'Date de prise de poste';
    // 2. exigé / souhaité (phrase entière citée)
    foreach ($sent as $s) {
        if (preg_match(JD_NICE, $s) && !preg_match(JD_MUST, $s)) { if (count($a['nice']) < 6) $a['nice'][] = jd_win($s, JD_NICE, 240); }
        elseif (preg_match(JD_MUST, $s)) { if (count($a['must']) < 6) $a['must'][] = jd_win($s, JD_MUST, 240); }
        elseif (preg_match(JD_QUALIF, $s) && preg_match('~\b(vous (?:êtes|avez|possédez|disposez)|titulaire|détenteur|maîtrise|maîtrisez|niveau|formation)\b~iu', $s)) { if (count($a['must']) < 6) $a['must'][] = jd_win($s, JD_QUALIF, 240); }
    }
    // 3. avantages explicitement cités
    if (preg_match_all(JD_BENEF, $desc, $m)) { $seen = []; foreach ($m[0] as $b) { $k = mb_strtolower(trim($b)); if (isset($seen[$k]) || mb_strlen($k) < 3) continue; $seen[$k] = 1; $a['benef'][] = trim($b); if (count($a['benef']) >= 8) break; } }
    // 4. vocabulaire
    foreach (JD_GLOSS as $re => $g) if (preg_match($re, $txt)) { $a['gloss'][$g[0]] = $g[1]; if (count($a['gloss']) >= 8) break; }
    // 5. questions à poser, déduites des manques et des points sensibles cités
    $miss = array_flip($a['missing']);
    if (isset($miss['Salaire'])) $a['q'][] = 'Quel est le salaire brut mensuel proposé (et les primes éventuelles) ?';
    if (isset($miss['Horaires et jours travaillés']) || isset($miss['Horaires précis et jours travaillés'])) $a['q'][] = 'Quels sont les horaires et les jours travaillés (week-ends, soirées) ?';
    if (isset($miss['Durée du contrat'])) $a['q'][] = $j['contract'] === 'interim' ? 'Quelle est la durée prévue de la mission ?' : 'Quelle est la durée du contrat, et est-il renouvelable ?';
    if (isset($miss['Date de prise de poste'])) $a['q'][] = 'À quelle date le poste est-il à pourvoir ?';
    if (isset($miss['Télétravail']) && preg_match('~informatique|développeur|comptab|gestionnaire|assistant|chargé|analyste|consultant|commercial sédentaire|administrati~iu', $txt)) $a['q'][] = 'Le télétravail est-il possible, et combien de jours ?';
    if (preg_match('~week-?ends?|samedis?|dimanches?|de nuit|astreintes?|[235]\s?x\s?8~iu', $txt)) $a['q'][] = 'À quelle fréquence travaille-t-on le week-end, la nuit ou d\'astreinte, et comment est-ce compensé ?';
    if ($dep !== '') $a['q'][] = 'Quelle part du temps se passe en déplacement, et comment les frais sont-ils pris en charge ?';
    if (preg_match('~\bprimes?\b|variable|commission~iu', $desc)) $a['q'][] = 'Comment la partie variable ou les primes sont-elles calculées ?';
    if ($j['contract'] === 'interim' || preg_match('~pour (?:le compte de )?(?:son|notre) client~iu', $desc)) $a['q'][] = 'Chez quelle entreprise s\'effectue le poste, et une embauche est-elle envisagée ensuite ?';
    $a['q'][] = 'Quelles sont les prochaines étapes du recrutement ?';
    $a['q'] = array_slice(array_values(array_unique($a['q'])), 0, 6);
    return $a;
}

// Salaire de l'offre comparé aux offres du même métier dans la région (n ≥ 20, hors temps partiel, stages et alternance).
function jd_salary_cmp(PDO $db, array $j): string
{
    if (empty($j['e_sal']) || (int)($j['e_tps'] ?? 0) === 2 || in_array($j['contract'], ['stage', 'alternance'], true)) return '';
    $st = $db->prepare('SELECT mm.mslug, m.name FROM job_metier_map mm JOIN job_metiers m ON m.slug=mm.mslug WHERE mm.id=?'); $st->execute([$j['id']]);
    if (!($mt = $st->fetch())) return '';
    $st = $db->prepare('SELECT * FROM job_metier_reg_sal WHERE mslug=? AND region=?'); $st->execute([$mt['mslug'], $j['region']]); $s = $st->fetch(); $zone = JOB_REGIONS[$j['region']][1] ?? '';
    if (!$s) { $st = $db->prepare('SELECT n, med, p25, p75 FROM job_metier_sal WHERE slug=?'); $st->execute([$mt['mslug']]); $s = $st->fetch(); $zone = 'France'; if (!$s || (int)$s['n'] < 20) return ''; }
    $v = (float)$j['e_sal']; $f = fn($x) => number_format(round((float)$x / 10) * 10, 0, ',', ' ') . ' €';
    $pos = $v < $s['p25'] ? 'en dessous de la fourchette courante' : ($v > $s['p75'] ? 'au-dessus de la fourchette courante' : 'dans la fourchette courante');
    return '<p><strong>Salaire comparé :</strong> ' . $f($v) . ' brut par mois (équivalent mensuel), soit <strong>' . $pos . '</strong> des offres ' . h(metier_de(mb_strtolower($mt['name']))) . ' en ' . h($zone)
        . ' (médiane ' . $f($s['med']) . ', la moitié entre ' . $f($s['p25']) . ' et ' . $f($s['p75']) . ' ; ' . (int)$s['n'] . ' offres affichant un salaire, hors temps partiel, stages et alternance).</p>';
}

function jd_html(array $site, PDO $db, array $j, string $desc): string
{
    if (!mod_on('decodeur') || mb_strlen($desc) < 120) return '';
    $a = jd_analyse($j, $desc);
    $rows = '';
    foreach ($a['said'] as $k => [$v, $src]) $rows .= '<tr><th scope="row">' . h($k) . '</th><td>' . $v . '<br><small style="opacity:.7">Source : ' . h($src) . '</small></td></tr>';
    foreach ($a['missing'] as $k) $rows .= '<tr><th scope="row">' . h($k) . '</th><td><span style="opacity:.75">Non précisé dans l\'annonce</span></td></tr>';
    $list = fn(array $xs) => '<ul>' . implode('', array_map(fn($s) => '<li>' . jd_q($s, 240) . '</li>', $xs)) . '</ul>';
    $o = '<section class="jdec" id="decodeur" style="margin:28px 0;padding:18px;border:1px solid var(--b);border-radius:12px"><h2 style="margin-top:0">Décodeur de l\'annonce</h2>'
        . '<p style="font-size:14px;opacity:.8">Lecture automatique du texte de l\'annonce : chaque point cite le passage dont il provient. Vérifiez auprès du recruteur avant de vous engager.</p>'
        . '<table style="width:100%;border-collapse:collapse" class="jdec-t">' . $rows . '</table>' . jd_salary_cmp($db, $j);
    if ($a['must']) $o .= '<h3>Ce que l\'annonce présente comme exigé</h3>' . $list($a['must']);
    if ($a['nice']) $o .= '<h3>Ce qui est seulement souhaité</h3>' . $list($a['nice']);
    if ($a['benef']) $o .= '<h3>Avantages cités dans l\'annonce</h3><p>' . h(implode(' · ', $a['benef'])) . '</p>';
    if ($a['q']) $o .= '<h3>Questions à poser au recruteur</h3><ul>' . implode('', array_map(fn($q) => '<li>' . h($q) . '</li>', $a['q'])) . '</ul>';
    if ($a['must']) $o .= '<p><strong>Pour votre candidature :</strong> reprenez dans votre CV et votre message chacun des points présentés comme exigés, avec un exemple concret ; signalez ceux que vous n\'avez pas encore (formation prévue, équivalence).</p>';
    if ($a['gloss']) $o .= '<details style="margin-top:12px"><summary style="cursor:pointer;font-weight:600">Vocabulaire de l\'annonce (' . count($a['gloss']) . ')</summary><dl style="margin:10px 0 0">' . implode('', array_map(fn($k, $v) => '<dt style="font-weight:600;margin-top:8px">' . h($k) . '</dt><dd style="margin:2px 0 0">' . h($v) . '</dd>', array_keys($a['gloss']), $a['gloss'])) . '</dl></details>';
    return $o . '<style>.jdec-t th,.jdec-t td{text-align:left;vertical-align:top;padding:8px 6px;border-top:1px solid var(--b)}.jdec-t th{width:34%;font-weight:600}.jdec h3{font-size:1.05em;margin:18px 0 6px}.jdec li{margin:4px 0}</style></section>';
}

// Ajouts au balisage JobPosting tirés de champs explicites uniquement.
function jd_schema(array $j, array $posting): array
{
    if (!mod_on('decodeur')) return $posting;
    if ((string)$j['worktime'] !== '') $posting['workHours'] = (string)$j['worktime'];
    if (preg_match('~(\d+)\s*An\(s\)~u', (string)$j['experience'], $m) || preg_match('~exp[ée]rience exig[ée]e de (\d+) an~iu', (string)$j['experience'], $m)) $posting['experienceRequirements'] = ['@type' => 'OccupationalExperienceRequirements', 'monthsOfExperience' => (int)$m[1] * 12];
    elseif (!empty($j['e_deb'])) $posting['experienceRequirements'] = 'Débutant accepté';
    if (!empty($j['e_rome'])) $posting['occupationalCategory'] = (string)$j['e_rome'];
    if (preg_match_all(JD_BENEF, (string)$j['description'], $m)) $posting['jobBenefits'] = implode(', ', array_slice(array_values(array_unique(array_map(fn($x) => mb_strtolower(trim($x)), $m[0]))), 0, 8));
    return $posting;
}
