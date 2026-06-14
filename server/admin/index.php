<?php
require __DIR__.'/_boot.php';
$u = require_admin();

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='sync') {
  csrf_check();
  require_once '/home5/guenver/_lishan_data/sync.php';
  $s = syncAll(null);
  journal($u['email'],'sync-manuelle','*', 'créés='.$s['crees'].' maj='.$s['maj'].' désactivés='.$s['desactives']);
  flash("Synchronisation effectuée : {$s['crees']} créé(s), {$s['maj']} mis à jour, {$s['desactives']} désactivé(s).");
  header('Location: index.php'); exit;
}

$d = db();
$nbAdh   = $d->query('SELECT COUNT(*) FROM adherents WHERE actif=1')->fetchColumn();
$nbInact = $d->query('SELECT COUNT(*) FROM adherents WHERE actif=0')->fetchColumn();
$nbSansAcc = $d->query('SELECT COUNT(*) FROM adherents WHERE actif=1 AND id NOT IN (SELECT adherent_id FROM acces)')->fetchColumn();
$nbVid = $d->query("SELECT COUNT(*) FROM ressources WHERE type IN ('video','playlist')")->fetchColumn();
$nbPdf = $d->query("SELECT COUNT(*) FROM ressources WHERE type='pdf'")->fetchColumn();
$parDisc = $d->query('SELECT di.nom, COUNT(a.adherent_id) n FROM disciplines di LEFT JOIN acces a ON a.discipline_id=di.id LEFT JOIN adherents ad ON ad.id=a.adherent_id AND ad.actif=1 GROUP BY di.id ORDER BY di.order_index')->fetchAll();
$lastSync = $d->query("SELECT cree FROM journal WHERE acteur='crm-sync' ORDER BY id DESC LIMIT 1")->fetchColumn();
$logs = $d->query('SELECT * FROM journal ORDER BY id DESC LIMIT 12')->fetchAll();

admin_header('Tableau de bord', $u);
if ($f=flash()) echo '<div class="flash">'.h($f).'</div>';
?>
<h1>Tableau de bord</h1>
<div class="grid cards">
  <div class="card"><div class="n"><?=$nbAdh?></div><div class="lbl">Adhérents actifs</div></div>
  <div class="card"><div class="n"><?=$nbVid?></div><div class="lbl">Vidéos / playlists</div></div>
  <div class="card"><div class="n"><?=$nbPdf?></div><div class="lbl">Documents PDF</div></div>
  <div class="card"><div class="n"><?=$nbSansAcc?></div><div class="lbl">Actifs sans accès</div></div>
  <div class="card"><div class="n"><?=$nbInact?></div><div class="lbl">Comptes désactivés</div></div>
</div>

<div class="panel" style="margin-top:22px">
  <div class="row" style="justify-content:space-between">
    <div>
      <h2 style="margin:0">Synchronisation CRM</h2>
      <p class="muted" style="margin:4px 0 0">Dernière synchro : <?= $lastSync ? h($lastSync) : 'jamais' ?>. Le cron horaire la relance automatiquement.</p>
    </div>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="sync">
      <button class="btn primary">Synchroniser maintenant</button>
    </form>
  </div>
</div>

<h2>Accès par discipline</h2>
<div class="panel">
  <table><thead><tr><th>Discipline</th><th>Adhérents avec accès</th></tr></thead><tbody>
  <?php foreach($parDisc as $r): ?>
    <tr><td><?=h($r['nom'])?></td><td><?=$r['n']?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>

<h2>Activité récente</h2>
<div class="panel">
  <table><thead><tr><th>Quand</th><th>Acteur</th><th>Action</th><th>Cible</th><th>Détail</th></tr></thead><tbody>
  <?php foreach($logs as $l): ?>
    <tr><td><?=h($l['cree'])?></td><td><?=h($l['acteur'])?></td><td><?=h($l['action'])?></td><td><?=h($l['cible'])?></td><td class="muted"><?=h($l['detail'])?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php admin_footer();
