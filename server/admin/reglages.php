<?php
require __DIR__.'/_boot.php';
$u = require_owner();
$d = db();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  if (($_POST['action']??'')==='saison') {
    $s=trim((string)$_POST['saison']);
    $d->prepare("INSERT INTO reglages (cle,valeur) VALUES ('saison_courante',?) ON CONFLICT(cle) DO UPDATE SET valeur=excluded.valeur")->execute([$s]);
    journal($u['email'],'set-saison','',$s); flash("Saison courante : $s");
  }
  if (($_POST['action']??'')==='mapping') {
    $d->prepare('DELETE FROM mapping_discipline')->execute();
    $ins=$d->prepare('INSERT INTO mapping_discipline (crm_label,famille_slug) VALUES (?,?)');
    foreach (($_POST['label']??[]) as $i=>$lab) {
      $lab=trim((string)$lab); $fam=$_POST['fam'][$i]??'';
      if ($lab!=='' && $fam!=='') $ins->execute([$lab,$fam]);
    }
    journal($u['email'],'set-mapping'); flash('Correspondances enregistrées.');
  }
  header('Location: reglages.php'); exit;
}
$saison=saison_courante();
$map=$d->query('SELECT * FROM mapping_discipline ORDER BY crm_label')->fetchAll();
$disc=disciplines_all();
admin_header('Réglages',$u);
if($f=flash())echo '<div class="flash">'.h($f).'</div>';
?>
<h1>Réglages</h1>
<div class="panel">
  <h2 style="margin-top:0">Saison courante</h2>
  <p class="muted">Filtre central : seuls les adhérents de cette saison (statut Adhérent ou Pré-inscription) sont synchronisés.</p>
  <form method="post" class="row" style="align-items:flex-end"><?=csrf_field()?><input type="hidden" name="action" value="saison">
    <div style="min-width:200px"><label>Saison</label><input name="saison" value="<?=h($saison)?>"></div>
    <button class="btn primary">Enregistrer</button>
  </form>
</div>
<div class="panel">
  <h2 style="margin-top:0">Correspondance disciplines CRM vers familles de ressources</h2>
  <p class="muted">Chaque discipline du CRM donne accès à une famille de ressources.</p>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="mapping">
    <table><thead><tr><th>Libellé CRM</th><th>Famille</th></tr></thead><tbody>
    <?php foreach($map as $m): ?>
      <tr>
        <td><input name="label[]" value="<?=h($m['crm_label'])?>"></td>
        <td><select name="fam[]"><?php foreach($disc as $di): ?><option value="<?=h($di['slug'])?>"<?=$di['slug']===$m['famille_slug']?' selected':''?>><?=h($di['nom'])?></option><?php endforeach;?></select></td>
      </tr>
    <?php endforeach; ?>
      <tr><td><input name="label[]" placeholder="(nouvelle correspondance)"></td>
      <td><select name="fam[]"><option value="">—</option><?php foreach($disc as $di): ?><option value="<?=h($di['slug'])?>"><?=h($di['nom'])?></option><?php endforeach;?></select></td></tr>
    </tbody></table>
    <div style="margin-top:14px"><button class="btn primary">Enregistrer les correspondances</button></div>
  </form>
</div>
<?php admin_footer();
