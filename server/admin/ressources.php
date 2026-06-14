<?php
require __DIR__.'/_boot.php';
$u = require_admin();
$d = db();
$disc = disciplines_all();
$discById=[]; foreach($disc as $x)$discById[(int)$x['id']]=$x;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $act = $_POST['action'] ?? '';

  if ($act==='save') {
    $id    = (int)($_POST['id'] ?? 0);
    $type  = in_array($_POST['type']??'',['video','playlist','pdf'],true) ? $_POST['type'] : 'video';
    $titre = trim((string)($_POST['titre'] ?? ''));
    $desc  = trim((string)($_POST['description'] ?? ''));
    $yt    = trim((string)($_POST['youtube_id'] ?? ''));
    if (preg_match('~(?:list=|v=|youtu\.be/|/embed/)([A-Za-z0-9_-]{11,})~', $yt, $m)) $yt=$m[1];
    $publie= isset($_POST['publie'])?1:0;
    $dids  = array_map('intval', $_POST['disc'] ?? []);

    $pdfPath = $_POST['pdf_existant'] ?? '';
    if ($type==='pdf' && !empty($_FILES['pdf']['tmp_name']) && is_uploaded_file($_FILES['pdf']['tmp_name'])) {
      $dir = cfg('uploads_dir'); @mkdir($dir,0755,true);
      $safe = preg_replace('~[^A-Za-z0-9._-]~','_', $_FILES['pdf']['name']);
      $fn = date('Ymd_His').'_'.$safe;
      if (strtolower(pathinfo($fn,PATHINFO_EXTENSION))==='pdf' && move_uploaded_file($_FILES['pdf']['tmp_name'], "$dir/$fn")) {
        $pdfPath = cfg('uploads_url').'/'.$fn;
      }
    }

    if ($id) {
      $d->prepare('UPDATE ressources SET type=?,titre=?,description=?,youtube_id=?,pdf_path=?,publie=?,modifie=datetime("now") WHERE id=?')
        ->execute([$type,$titre,$desc,$yt,$pdfPath,$publie,$id]);
    } else {
      $d->prepare('INSERT INTO ressources (type,titre,description,youtube_id,pdf_path,publie) VALUES (?,?,?,?,?,?)')
        ->execute([$type,$titre,$desc,$yt,$pdfPath,$publie]);
      $id=(int)$d->lastInsertId();
    }
    $d->prepare('DELETE FROM ressource_discipline WHERE ressource_id=?')->execute([$id]);
    $ins=$d->prepare('INSERT OR IGNORE INTO ressource_discipline (ressource_id,discipline_id) VALUES (?,?)');
    foreach($dids as $did) $ins->execute([$id,$did]);
    journal($u['email'], $id?'res-save':'res-create', (string)$id, $titre);
    flash('Ressource enregistrée.');
    header('Location: ressources.php'); exit;
  }

  if ($act==='delete') {
    $id=(int)$_POST['id'];
    $d->prepare('DELETE FROM ressources WHERE id=?')->execute([$id]);
    journal($u['email'],'res-delete',(string)$id);
    flash('Ressource supprimée.');
    header('Location: ressources.php'); exit;
  }
}

$edit = null;
if (isset($_GET['edit'])) {
  $st=$d->prepare('SELECT * FROM ressources WHERE id=?'); $st->execute([(int)$_GET['edit']]); $edit=$st->fetch();
  $edit['_disc']=[]; foreach($d->query('SELECT discipline_id FROM ressource_discipline WHERE ressource_id='.(int)$_GET['edit']) as $r) $edit['_disc'][]=(int)$r['discipline_id'];
}

$typeFilter = $_GET['t'] ?? 'all';
$wsql = $typeFilter==='pdf' ? "WHERE type='pdf'" : ($typeFilter==='video' ? "WHERE type IN ('video','playlist')" : '');
$rows = $d->query("SELECT * FROM ressources $wsql ORDER BY type, order_index, titre LIMIT 600")->fetchAll();
$rdMap=[]; foreach($d->query('SELECT rd.ressource_id, di.slug FROM ressource_discipline rd JOIN disciplines di ON di.id=rd.discipline_id') as $r) $rdMap[(int)$r['ressource_id']][]=$r['slug'];

admin_header('Ressources', $u);
if ($f=flash()) echo '<div class="flash">'.h($f).'</div>';
?>
<h1>Ressources</h1>

<div class="panel">
  <h2 style="margin-top:0"><?= $edit ? 'Modifier la ressource' : 'Ajouter une ressource' ?></h2>
  <form method="post" enctype="multipart/form-data">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=$edit['id']??0?>">
    <input type="hidden" name="pdf_existant" value="<?=h($edit['pdf_path']??'')?>">
    <div class="row" style="align-items:flex-start">
      <div style="flex:1;min-width:220px">
        <label>Type</label>
        <select name="type" id="type">
          <option value="video"<?=($edit['type']??'')==='video'?' selected':''?>>Vidéo YouTube</option>
          <option value="playlist"<?=($edit['type']??'')==='playlist'?' selected':''?>>Playlist YouTube</option>
          <option value="pdf"<?=($edit['type']??'')==='pdf'?' selected':''?>>Document PDF</option>
        </select>
      </div>
      <div style="flex:2;min-width:260px">
        <label>Titre</label>
        <input name="titre" required value="<?=h($edit['titre']??'')?>">
      </div>
    </div>
    <label>Description (optionnelle)</label>
    <input name="description" value="<?=h($edit['description']??'')?>">
    <div class="row" style="align-items:flex-start">
      <div style="flex:2;min-width:260px">
        <label>ID ou URL YouTube (vidéo / playlist)</label>
        <input name="youtube_id" placeholder="ex: PLILsE1H8J6U… ou une URL complète" value="<?=h($edit['youtube_id']??'')?>">
      </div>
      <div style="flex:1;min-width:220px">
        <label>Fichier PDF (si type PDF)</label>
        <input type="file" name="pdf" accept="application/pdf">
        <?php if(!empty($edit['pdf_path'])): ?><p class="muted" style="font-size:.75rem">Actuel : <?=h($edit['pdf_path'])?></p><?php endif; ?>
      </div>
    </div>
    <label>Disciplines autorisées</label>
    <div class="row">
      <?php foreach($disc as $di): $on=in_array((int)$di['id'], $edit['_disc']??[],true); ?>
        <label style="display:inline-flex;align-items:center;gap:6px;font-weight:500;margin:0">
          <input type="checkbox" name="disc[]" value="<?=$di['id']?>" style="width:auto"<?=$on?' checked':''?>> <?=h($di['nom'])?>
        </label>
      <?php endforeach; ?>
    </div>
    <label style="display:inline-flex;align-items:center;gap:8px;margin-top:14px">
      <input type="checkbox" name="publie" style="width:auto"<?=(!$edit||$edit['publie'])?' checked':''?>> Publié (visible par les adhérents)
    </label>
    <div class="row" style="margin-top:18px">
      <button class="btn primary">Enregistrer</button>
      <?php if($edit): ?><a class="btn" href="ressources.php">Annuler</a><?php endif; ?>
    </div>
  </form>
</div>

<form class="toolbar" method="get">
  <select name="t" onchange="this.form.submit()">
    <option value="all"<?=$typeFilter==='all'?' selected':''?>>Toutes</option>
    <option value="video"<?=$typeFilter==='video'?' selected':''?>>Vidéos / playlists</option>
    <option value="pdf"<?=$typeFilter==='pdf'?' selected':''?>>Documents PDF</option>
  </select>
  <span class="count"><?=count($rows)?> ressource(s)</span>
</form>

<div class="panel" style="padding:0;overflow-x:auto">
<table><thead><tr><th>Titre</th><th>Type</th><th>Disciplines</th><th>État</th><th></th></tr></thead><tbody>
<?php foreach($rows as $r): ?>
  <tr>
    <td><strong><?=h($r['titre'])?></strong><?php if($r['youtube_id']):?><br><span class="muted" style="font-size:.72rem"><?=h($r['youtube_id'])?></span><?php endif;?></td>
    <td><?=h($r['type'])?></td>
    <td><?php foreach(($rdMap[(int)$r['id']]??[]) as $s) echo '<span class="chip">'.h($s).'</span>'; ?></td>
    <td><?=$r['publie']?'<span class="chip">publié</span>':'<span class="chip off">masqué</span>'?></td>
    <td style="white-space:nowrap">
      <a class="btn sm" href="ressources.php?edit=<?=$r['id']?>">Modifier</a>
      <form method="post" style="display:inline" onsubmit="return confirm('Supprimer cette ressource ?')"><?=csrf_field()?>
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>">
        <button class="btn sm danger">Suppr.</button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php admin_footer();
