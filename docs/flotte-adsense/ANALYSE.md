# Flotte AdSense — audit et projet « fabrique à sites »

Audit du 25/09/2026. Sources : compte cPanel WEBNORMANDIE (o2switch, 109.234.161.10), hub NORMANDYWEB (109.234.161.13), API REST WordPress publique, AWStats, export des 33 workflows n8n du VPS OVH.

---

## 1. État des lieux

| Site | Hébergement | Moteur | Posts publiés | Rythme | Visites / uniques (août 2026) | AdSense |
|---|---|---|---|---|---|---|
| tutoriels.decouverte.org | WEBNORMANDIE | WP 7.1.2 + Elementor | **11 700** (4 549 en 2025, 7 151 entre le 1/01 et le 12/02/2026) | arrêté le 12/02/2026 | 290 / **89** | Auto ads, pub-8104956615440701 |
| tech.decouverte.org | WEBNORMANDIE | WP 7.1.2 | 312 | arrêté le 12/02/2026 | 549 / 208 | Auto ads |
| decouverte.org | WEBNORMANDIE | WP 7.1.2 | 809 | arrêté le 12/02/2026 | 833 / 300 | Auto ads + ads.txt OK |
| recruteur.eu | WEBNORMANDIE (public_html/recruteur) | WP (version masquée), ~120 plugins | **25 381** (ID max 4,8 M) | **actif** : plusieurs posts par jour (autoblog interne) | 28 754 / 1 284 → **surtout des robots** | 6 blocs manuels + ads.txt OK |
| normandie.me | WEBNORMANDIE | WP 7.1.2 | 2 (« test », 2024) | inactif | 383 / 83 | Auto ads |
| art-martial.org | WEBNORMANDIE (public_html/art-martial) | vide (.htaccess seul) | 0 | — | — | — |
| medecine-integrative.net | Hub NORMANDYWEB | vide (« Index of / ») | 0 | — | — | — |
| sante-integrative.net | Hub NORMANDYWEB | vide (« Index of / ») | 0 | — | — | — |

Autres sous-domaines de decouverte.org présents sur le compte : securite, bretagne, thes-chinois, noren-japonais, equisalmar, freebox, emploi, vin-bio, caensport.

**Aucun de ces sites n'est dans Google Search Console** : on n'a aucune donnée d'indexation ni de clics.

### Constats clés

1. **Le trafic réel est quasi nul.** Pour environ 38 000 articles au total, on compte à peine 700 visiteurs uniques humains par mois sur toute la flotte. Au RPM display français (2 à 8 € pour 1 000 pages vues), cela représente **quelques euros par mois**.
2. **tutoriels.decouverte.org est pénalisé de fait.** Sur ses 100 derniers articles, **96 ont un slug suffixé -N**, donc sont des doublons de sujets déjà traités (ex. `comment-creer-signature-email-professionnelle-gmail-7`). Le site a publié 170 articles par jour, certains sur des sujets hors cible comme le remboursement d'impôt fédéral américain. C'est exactement ce que vise la politique Google *scaled content abuse* (mars 2024) : 11 700 pages pour 89 visiteurs.
3. **tech et decouverte.org sont plus propres.** On y trouve 13 à 15 % de doublons et des articles de 1 900 à 2 700 mots, mais le trafic reste marginal.
4. **Toute la production n8n est à l'arrêt depuis le 12/02/2026**, sauf un workflow actif (« Otpimisation … SANTE INTEGRATIVE.NET ») qui publie vers **sante-integrative.net, où aucun WordPress n'est installé**. Il échoue donc très probablement à chaque exécution. Le workflow Telegram actif lit le flux RSS de ce même site vide.

### recruteur.eu (« vérolé ? »)

Ce qui a été vérifié (lecture seule) :
- `index.php` et `.htaccess` sont sains (règles iThemes Security, PHP bloqué dans uploads, plugins et themes).
- La page d'accueil ne charge aucun script externe injecté (seuls le domaine lui-même, googlesyndication et googletagmanager apparaissent). La réponse est identique avec un referer Google et avec un user-agent Googlebot, donc pas de cloaking détecté.
- Le fichier `1oh6zvH7tU_UKO9MS3tBG` à la racine est un fichier de cookies libcurl vide, laissé par un plugin d'import. Il est inoffensif.

Ce qui pose problème :
- Le fichier **`error_log` fait 34,8 Go**. Il reçoit des warnings PHP à chaque requête (plugins obsolètes incompatibles avec PHP 8 : social-networks-auto-poster, superfly-menu, genesis-dambuster, connect-genesis-woocommerce). Il sature le disque et ralentit le site.
- Il y a **environ 120 plugins**, dont beaucoup de versions « pro » et des dossiers typiques des packs nulled (`Licensing`, `Extras`, `themeprice-updater`, `SEM-J`, `_DESACTIVES`). C'est le premier vecteur d'infection sur WordPress.
- Les plugins **wp-automatic et rss-autopilot** scrapent des médias. Exemple : 72 articles intitulés « Accès restreint – Le Monde » ont été publiés automatiquement. Cela pose un risque de droit d'auteur et de politique AdSense (contenu copié, pages sans valeur).
- En août, on relève 28 754 visites pour 1 284 IP distinctes : le trafic est essentiellement robotique. Les impressions publicitaires servies à des robots peuvent déclencher une **limitation du compte AdSense**, qui toucherait toute la flotte puisqu'elle partage le même éditeur.
- Le scan serveur complet (signatures dans wp-content, PHP dans uploads, fichiers modifiés récemment) **n'a pas pu aller au bout** : le shell WEBNORMANDIE est resté verrouillé pendant l'audit. Il reste à faire.

Verdict provisoire : pas d'injection visible côté public, mais le site est dans un **état critique**. Il n'est ni sauvable ni rentable en l'état. Recommandation : le geler, exporter les contenus utiles et le reconstruire.

### Sécurité n8n

- La **clé Pexels est en clair** dans les nœuds HTTP (tech, sante-integrative…), et la **clé Gemini est passée dans l'URL** (`?key=`). Les deux doivent passer dans les *Credentials* n8n, puis être renouvelées.
- Le fichier d'export temporaire créé pour l'audit a été supprimé du conteneur.

---

## 2. Réponses aux questions

### 2.1 Gérer AdSense via MCP ?

**Pas aujourd'hui.** Aucun connecteur AdSense n'est branché. Le connecteur Google Workspace du hub (`gws_api`) répond `403 insufficient scopes` sur `adsense.googleapis.com`. Le compte éditeur (pub-8104956615440701) est probablement lié à un Gmail personnel, et la délégation de domaine Workspace ne s'applique pas aux comptes Gmail.

**Faisable** avec l'API AdSense Management v2 et un OAuth utilisateur, sur le même modèle que l'accès Search Console déjà en place sur le VPS (`rank_gsc`) :
- lecture : revenus par site, par page et par jour, RPM, état d'approbation des sites, alertes et politiques ;
- écriture limitée : l'API ne permet pas d'ajouter un site ni de faire appel d'une sanction, ces actions se font dans l'interface web.

→ Il faut ajouter à l'outil OAuth du VPS le scope `adsense.readonly` et un endpoint « rapport AdSense ». Le tableau de bord de la fabrique le consommera.

### 2.2 Copie conforme en PHP/SQLite ?

**Oui, c'est simple, et les données sont accessibles** : l'API REST est publique sur les 4 WordPress et un accès fichiers/DB existe via le MCP.

- Export par site : posts, pages, catégories, tags, médias, meta Yoast (titre et description SEO), avec la structure de permaliens actuelle (tech utilise `/AAAA/MM/JJ/slug/`, les autres `/slug/`).
- Import dans **un fichier SQLite par site** (`sites/<domaine>/content.sqlite`) et images rapatriées en local (WebP).
- URLs strictement identiques, et 301 pour les flux, pages de catégories et pagination.
- **Il ne faut pas tout copier tel quel.** Sur tutoriels, il faut dédupliquer (11 700 → probablement 800 à 1 500 sujets uniques) en gardant la meilleure version et en redirigeant les doublons en 301. Migrer 10 000 doublons ne ferait que reporter la pénalité.

Volume à reprendre : environ 13 000 posts (hors recruteur), soit quelques heures d'export.

### 2.3 Contenus automatiques « ultra optimisés »

La base n8n existante est solide techniquement : choix de catégorie, sources RSS, Gemini 2.5 Flash, JSON structuré, images Pexels/Pixabay, schema.org Article, payload Yoast. Elle souffre en revanche de trois défauts qui expliquent l'échec :

1. **Pas de recherche de mots-clés.** Les sujets viennent de flux RSS d'actualité (howtogeek, 01net…). Ce sont des sujets éphémères, déjà couverts par des sites à forte autorité.
2. **Déduplication faible.** Elle repose sur une comparaison exacte du « thème », mémorisée dans `staticData` (300 entrées), d'où les `-7`.
3. **Volume au lieu de qualité.** On publie toutes les 5 minutes, sans relecture, sans maillage interne et sans mise à jour des anciens articles.

Pipeline proposé (dans n8n ou en PHP cron dans la fabrique) :

```
Backlog de sujets (SQLite)  ←  recherche mots-clés : Google Suggest / People Also Ask / GSC (requêtes en impressions sans clic)
      ↓  dédup sémantique (embeddings, sem_index) + vérif SERP (serp_check)
Brief (intention, H2, questions, entités, maillage vers articles existants)
      ↓
Rédaction LLM (2 passes : draft → critique/fact-check) + score qualité auto (longueur utile, lisibilité, répétitions)
      ↓  seuil non atteint → rejet
Publication cadencée : 1 à 3 articles/jour/site MAX, horaires irréguliers
      ↓
Boucle GSC à 30/60/90 jours : réécrire ce qui reçoit des impressions sans clics, fusionner ou supprimer ce qui n'a rien
```

### 2.4 Chances de succès, sans langue de bois

- **Une ferme de contenus 100 % IA sans supervision a très peu de chances de réussir en 2026.** Google détecte ces schémas à l'échelle du site, et AdSense refuse ou limite les sites « à faible valeur ». La flotte actuelle le démontre déjà : 38 000 articles pour environ 700 visiteurs par mois.
- **Pour que ça marche**, il faut des niches où vous avez une vraie légitimité, une IA qui assiste la rédaction sans la remplacer, peu d'articles mais utiles, et une mise à jour continue pilotée par GSC.
- **Ordre de grandeur réaliste** pour un site de niche bien tenu :
  - 50 000 pages vues par mois au bout de 12 à 18 mois ;
  - RPM de 5 à 10 € ;
  - soit **250 à 500 € par mois et par site**.
  - Il faudrait 5 à 10 sites comme ça pour un revenu significatif. Beaucoup n'y arrivent jamais.
- **Santé / médecine intégrative** : c'est une thématique YMYL (« Your Money or Your Life »). L'IA sans caution médicale y est lourdement filtrée par Google et encadrée par AdSense. Ce n'est à faire qu'avec votre expertise réelle (qi gong, tuina) signée en votre nom.

### 2.5 Autres régies

| Régie | Condition d'entrée | Intérêt |
|---|---|---|
| AdSense | aucune, mais validation de chaque site | base |
| Ezoic | pas de minimum | RPM ×1,5 à ×2 vs AdSense, script lourd |
| Journey by Mediavine | ~10 000 sessions/mois | RPM élevés |
| Raptive | 100 000 pages vues/mois | premium |
| Affiliation (Amazon, Awin, Effiliation) | aucune | souvent **> display** sur les tutos matériel et logiciels |
| Adsterra, Propeller… | aucune | à éviter (pubs agressives, nuit au SEO et à la marque) |

---

## 3. La « fabrique » : architecture proposée

Un seul code PHP 8 + SQLite, multi-sites par nom d'hôte, déployable sur n'importe quel compte cPanel de la flotte.

```
fabrique/
  public/index.php          routeur : Host → site → SQLite du site
  core/                     rendu, cache HTML statique, sitemap, RSS, schema.org, ads.txt
  themes/<theme>/           2-3 gabarits légers (Core Web Vitals verts)
  sites/<domaine>/
      site.json             nom, thème, langue, catégories, emplacements pubs, régie, GA4
      content.sqlite        posts, pages, termes, médias, redirections, backlog sujets
  admin/                    interface unique
  jobs/                     import WP, génération, publication, rapport GSC/AdSense
```

**Interface unique (admin)** :
- Sites : ajouter (domaine ou sous-domaine, thème, niche), suspendre, supprimer, cloner.
- Contenus : backlog de sujets, file de validation, édition, dédoublonnage, 301.
- Pubs : emplacements par gabarit (haut d'article, milieu, fin, sidebar), régie par site, ads.txt généré.
- Pilotage : trafic (GA4/GSC), revenus AdSense par site, coût IA, articles « à retravailler ».
- Générateur : paramètres par site (ton, rythme, longueur, sources autorisées).

Ajouter un site consiste à créer le sous-domaine via l'API cPanel, puis `site.json`, puis la base SQLite, puis le SSL AutoSSL. Tout cela est automatisable avec les outils MCP existants.

---

## 4. Thématiques pour sous-domaines

Critères : pas de YMYL fort, contenu durable, requêtes de type « comment… », RPM correct et, idéalement, **votre légitimité**.

**Là où vous êtes crédible (priorité)** :
- `qigong.` / `taichi.` / `arts-martiaux.` (sur art-martial.org, actuellement vide) : techniques, histoire, styles, équipement (affiliation).
- `tuina.` / `automassage.` : bien-être non médical, signé.
- `normandie.me` : patrimoine, randonnées, villages, plages, agenda, gastronomie normande. Tourisme local, bon RPM l'été.
- `thes-chinois.decouverte.org` (existe déjà) : variétés, préparation, matériel (affiliation).

**Niches durables à bon RPM** :
- `emploi.` / recruteur.eu reconstruit : fiches métiers, salaires, modèles de lettres et CV, entretiens. C'est un des meilleurs RPM hors finance.
- `bureautique.` : Excel, Word, Google Sheets (formules, modèles téléchargeables). Il vaut mieux 300 tutos uniques que 11 700 doublons.
- `jardin.` / `potager.` : calendrier des semis par mois et par région.
- `bricolage.` / `maison.` : réparations, outillage (affiliation).
- `auto.` : entretien, voyants, pannes courantes.
- `animaux.` : races, alimentation, éducation.
- `recettes.` : cuisine normande et de saison.
- `demarches.` : démarches administratives. Très fort trafic, mais semi-YMYL : sources officielles obligatoires.

**À éviter** : santé et médecine sans caution, finance et crypto, actualité (rythme intenable, pas de longue traîne), et tout contenu scrapé.

Remarque : une pénalité touche un **site** (un nom d'hôte). Des sous-domaines séparés isolent en partie le risque, mais ils partagent la marque et le compte AdSense. Pour les niches importantes, un domaine dédié reste préférable.

---

## 5. Plan d'action

| Phase | Action | Durée |
|---|---|---|
| 0 — Urgences | Vider ou tourner l'error_log de recruteur (34,8 Go), désactiver wp-automatic et rss-autopilot, finir le scan malware, désactiver le workflow n8n qui publie vers sante-integrative.net (vide), sortir les clés API des nœuds n8n | 1 jour |
| 1 — Mesure | Ajouter les 8 domaines dans Search Console, brancher l'API AdSense (lecture) | 1 à 2 jours |
| 2 — Socle | Développer la fabrique (routeur, thème, admin, import WP → SQLite) et migrer decouverte.org en pilote | 1 à 2 semaines |
| 3 — Nettoyage | Migrer tech et tutoriels avec dédoublonnage et 301 ; reconstruire recruteur.eu sur les contenus RH propres uniquement | 1 semaine |
| 4 — Génération | Pipeline mots-clés → brief → rédaction → QA → publication (1 à 3 articles/jour/site) | 1 semaine |
| 5 — Expansion | Nouveaux sites (arts martiaux, Normandie, thé…) dès qu'un site pilote dépasse 5 000 visites/mois | continu |
