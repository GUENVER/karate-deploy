<?php
/** sync-saison.php — bouton "synchroniser la saison" depuis l'admin. */
require __DIR__.'/_boot.php';
$u = require_admin();
if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Location: adherents.php'); exit; }
csrf_check();
require_once '/home5/guenver/_lishan_data/sync.php';
$s = syncAll(null);
journal($u['email'],'sync-saison','*','créés='.$s['crees'].' maj='.$s['maj'].' désactivés='.$s['desactives']);
flash("Saison synchronisée : {$s['crees']} compte(s) créé(s), {$s['maj']} mis à jour, {$s['desactives']} désactivé(s).");
header('Location: adherents.php'); exit;
