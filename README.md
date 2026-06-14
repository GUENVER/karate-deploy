# Espace adhérents Lishan

Front statique (Astro) + back-office PHP + synchronisation avec le CRM Organisation.
Accès aux ressources vidéo/PDF filtré par discipline et par saison.

## Architecture

- **Front** (`src/`) : Astro statique, PWA. Login adhérent puis espace ressources filtré.
- **Back-office** (`server/admin/`) : PHP/SQLite. Gestion accès + ressources + comptes.
- **API** (`server/api/`) : auth adhérent, ressources filtrées, pont HMAC temps réel (`crm-sync.php`).
- **Moteur de sync** (`server/db/sync.php`) : CRM Organisation vers base d'accès. Cron horaire + boutons.
- **Config/secrets** (`server/secure/`) : hors docroot. `config.example.php` = modèle à renseigner.

## Modèle d'accès

- Disciplines : qigong, taichi, kungfu, qigong_enfant (familles de ressources).
- Éligibilité sync : statut Adhérent/Pré-inscription **et** saison courante.
- Droits = disciplines cochées au CRM (saison en cours), routées vers les familles via mapping paramétrable.
- Override admin prioritaire sur le CRM. Inéligible vers compte désactivé (jamais supprimé).

## Déploiement

- Front : `npm run build` puis publier `dist/` dans le docroot de dev.adherents.
- PHP : déposer `server/` aux emplacements correspondants (admin/, api/, cron/ dans le docroot ; secure/ et db/ hors docroot).
- Base : `sqlite3 access.db < server/db/schema.sql` puis `schema_mapping.sql`, créer un owner, lancer `sync.php`.
- Cron : `0 * * * * php /chemin/cron/cron-sync.php`.
