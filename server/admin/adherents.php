<?php
require __DIR__.'/_boot.php';
$u = require_admin();
$d = db();
$disc = disciplines_all();
$discById = []; foreach($disc as $x) $discById[(int)$x['id']]=$x;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $act = $_POST['action'] ?? '';

  if ($act==='toggle') {
    $adhId = (int)$_POST['adherent_id'];
    $dId   = (int)$_POST['discipline_id'];
    $on    = $_POST['on']==='1';
    if ($on) {
      $d->prepare("INSERT OR REPLACE INTO acces (adherent_id,discipline_id,source) VALUES (?,?,'admin')")->execute([$adhId,$dId]);
    } else {
      $d->prepare('DELETE FROM acces WHERE adherent_id=? AND discipline_id=?')->execute([$adhId,$dId]);
    }
    $em = $d->query('SELECT email FROM adherents WHERE id='.$adhId)->fetchColumn();
    journal($u['email'], $on?'grant':'revoke', (string)$em, $discById[$dId]['slug']??'');
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
  }
  if ($act==='actif') {
    $adhId=(int)$_POST['adherent_id']; $v=$_POST['v']==='1'?1:0;
    $d->prepare('UPDATE adherents SET actif=?, modifie=datetime("now") WHERE id=?')->execute([$v,$adhId]);
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
  }
  if ($act==='reset_pwd') {
    $adhId=(int)$_POST['adherent_id'];
    $a='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'; $p='';
    for($i=0;$i<12;$i++)$p.=$a[random_int(0,strlen($a)-1)];
    $d->prepare('UPDATE adherents SET password_hash=?, modifie=datetime("now") WHERE id=?')->execute([password_hash($p,PASSWORD_DEFAULT),$adhId]);
    $em=$d->query('SELECT email FROM adherents WHERE id='.$adhId)->fetchColumn();
    journal($u['email'],'reset-pwd',(string)$em);
    flash("Nouveau mot de passe pour $em : $p");
    header('Location: adherents.php'); exit;
  }
}

$q = trim($_GET['q'] ?? '');
$filtre = $_GET['f'] ?? 'actifs';
$where=[]; $args=[];
if ($q!==''){ $where[]='(ad.email LIKE ? OR ad.display_name LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
if ($filtre==='actifs')   $where[]='ad.actif=1';
if ($filtre==='inactifs') $where[]='ad.actif=0';
if ($filtre==='sans-acces') $where[]='ad.actif=1 AND ad.id NOT IN (SELECT adherent_id FROM acces)';
$wsql = $where?('WHERE '.implode(' AND ',$where)):'';
$adhs = $d->prepare("SELECT ad.* FROM adherents ad $wsql ORDER BY ad.display_name LIMIT 500");
$adhs->execute($args); $adhs=$adhs->fetchAll();

$accByAdh=[];
foreach($d->query('SELECT adherent_id,discipline_id,source FROM acces') as $r)
  $accByAdh[(int)$r['adherent_id']][(int)$r['discipline_id']]=$r['source'];

admin_header('Adhérents', $u);
if ($f=flash()) echo '<div class="flash">'.h($f).'</div>';
?>
<h1>Adhérents <span class="count">(<?=count($adhs)?> affichés)</span></h1>

<div class="panel">
  <div class="row" style="justify-content:space-between">
    <div><strong>Donner accès à toute la saison <?=h(saison_courante())?></strong>
      <p class="muted" style="margin:4px 0 0">Synchronise tous les adhérents éligibles depuis le CRM (création + droits).</p></div>
    <form method="post" action="sync-saison.php"><?=csrf_field()?>
      <button class="btn ok">Synchroniser la saison</button></form>
  </div>
</div>

<form class="toolbar" method="get">
  <input type="search" name="q" placeholder="Rechercher email ou nom…" value="<?=h($q)?>">
  <select name="f" onchange="this.form.submit()">
    <option value="actifs"<?=$filtre==='actifs'?' selected':''?>>Actifs</option>
    <option value="sans-acces"<?=$filtre==='sans-acces'?' selected':''?>>Actifs sans accès</option>
    <option value="inactifs"<?=$filtre==='inactifs'?' selected':''?>>Désactivés</option>
    <option value="tous"<?=$filtre==='tous'?' selected':''?>>Tous</option>
  </select>
  <button class="btn">Filtrer</button>
</form>

<div class="panel" style="padding:0;overflow-x:auto">
<table>
<thead><tr><th>Adhérent</th><th>Accès (cliquer pour modifier)</th><th>État</th><th></th></tr></thead>
<tbody>
<?php foreach($adhs as $a): $aid=(int)$a['id']; $acc=$accByAdh[$aid]??[]; ?>
  <tr>
    <td>
      <strong><?=h($a['display_name']?:$a['email'])?></strong><br>
      <span class="muted"><?=h($a['email'])?></span>
    </td>
    <td>
      <div class="access-toggle">
      <?php foreach($disc as $di): $did=(int)$di['id']; $src=$acc[$did]??null; ?>
        <form method="post" style="display:inline">
          <?=csrf_field()?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="adherent_id" value="<?=$aid?>">
          <input type="hidden" name="discipline_id" value="<?=$did?>">
          <input type="hidden" name="on" value="<?=$src?'0':'1'?>">
          <button class="<?=$src?'on':''?>" title="<?=$src?('Accès ('.h($src).') — cliquer pour retirer'):'Cliquer pour donner accès'?>">
            <?=h($di['slug'])?><?= $src==='admin' ? ' ✎' : '' ?>
          </button>
        </form>
      <?php endforeach; ?>
      </div>
    </td>
    <td><?= $a['actif'] ? '<span class="chip">actif</span>' : '<span class="chip off">désactivé</span>' ?></td>
    <td style="white-space:nowrap">
      <form method="post" style="display:inline"><?=csrf_field()?>
        <input type="hidden" name="action" value="actif"><input type="hidden" name="adherent_id" value="<?=$aid?>">
        <input type="hidden" name="v" value="<?=$a['actif']?'0':'1'?>">
        <button class="btn sm"><?=$a['actif']?'Désactiver':'Activer'?></button>
      </form>
      <form method="post" style="display:inline" onsubmit="return confirm('Régénérer le mot de passe ?')"><?=csrf_field()?>
        <input type="hidden" name="action" value="reset_pwd"><input type="hidden" name="adherent_id" value="<?=$aid?>">
        <button class="btn sm">Mot de passe</button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="muted">✎ = accès forcé manuellement (override). Les autres viennent de la synchro CRM.</p>
<?php admin_footer();
