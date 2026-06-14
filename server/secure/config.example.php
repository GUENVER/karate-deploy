<?php
/**
 * config.php — Configuration centrale (HORS docroot). Ne jamais committer les vrais secrets.
 * Copier en /home5/guenver/_lishan_secure/config.php et renseigner les valeurs reelles.
 */
return [
  'access_db' => '/home5/guenver/_lishan_data/access.db',
  'crm_db'    => '/home5/guenver/organisation.lishan.fr/app/data/adherents.db',
  'sso_secret'  => 'REMPLACER_PAR_LE_SECRET_SSO',
  'sync_secret' => 'REMPLACER_PAR_LE_SECRET_SYNC',
  'session_lifetime_admin'    => 60 * 60 * 8,
  'session_lifetime_adherent' => 60 * 60 * 24 * 35,
  'uploads_dir' => '/home5/guenver/dev.adherents.lishan.fr/medias',
  'uploads_url' => '/medias',
  'saison_defaut' => '2025/2026',
];
