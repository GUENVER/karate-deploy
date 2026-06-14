<?php
require __DIR__.'/_boot.php';
$u = require_owner();
$d = db();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $act=$_POST['action']??'';
  if ($act==='create') {
    $email=trim((string)$_POST['email']); $nom=trim((string)$_POST['nom']);
    $role=in_array($_POST['role']??'',['owner','editor'],true)?$_POST['role']:'editor';
    $a='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';$p='';for($i=0;$i<12;$i++)$p.=$a[random_int(0,strlen($a)-1)];
    try {
      $d->prepare('INSERT INTO admins (email,nom,password_hash,role) VALUES (?,?,?,?)')
        ->execute([$email,$nom,password_hash($p,PASSWORD_DEFAULT),$role]);
      journal($u['email'],'admin-create',$email,$role);
      flash("Compte créé : $email / mot de passe : $p ($role)");
    } catch(Exception $e){ flash('Erreur : e-mail déjà utilisé ?'); }
  }
  if ($act==='delete') {
    $id=(int)$_POST['id'];
    if ($id!==(int)$u['id']) { $d->prepare('DELETE FROM admins WHERE id=?')->execute([$id]); journal($u['email'],'admin-delete',(string)$id); flash('Compte supprimé.'); }
  }
  if ($act==='reset') {
    $id=(int)$_POST['id'];
    $a='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';$p='';for($i=0;$i<12;$i++)$p.=$a[random_int(0,strlen($a)-1)];
    $d->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($p,PASSWORD_DEFAULT),$id]);
    $em=$d->query('SELECT email FROM admins WHERE id='.$id)->fetchColumn();
    flash("Nouveau mot de passe pour $em : $p");
  }
  header('Location: comptes.php'); exit;
}
$admins=$d->query('SELECT * FROM admins ORDER BY role,email')->fetchAll();
admin_header('Comptes administrateurs',$u);
if($f=flash())echo '<div class="flash">'.h($f).'</div>';
?>
<h1>Comptes administrateurs</h1>
<div class="panel">
  <h2 style="margin-top:0">Nouveau compte</h2>
  <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
    <div class="row" style="align-items:flex-end">
      <div style="flex:2;min-width:220px"><label>E-mail</label><input type="email" name="email" required></div>
      <div style="flex:1;min-width:160px"><label>Nom</label><input name="nom"></div>
      <div style="flex:1;min-width:140px"><label>Rôle</label>
        <select name="role"><option value="editor">Éditeur</option><option value="owner">Propriétaire</option></select></div>
      <button class="btn primary">Créer</button>
    </div>
  </form>
</div>
<div class="panel" style="padding:0">
<table><thead><tr><th>E-mail</th><th>Nom</th><th>Rôle</th><th></th></tr></thead><tbody>
<?php foreach($admins as $a): ?>
  <tr><td><?=h($a['email'])?></td><td><?=h($a['nom'])?></td><td><span class="chip"><?=h($a['role'])?></span></td>
  <td style="white-space:nowrap">
    <form method="post" style="display:inline"><?=csrf_field()?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn sm">Mot de passe</button></form>
    <?php if((int)$a['id']!==(int)$u['id']): ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Supprimer ce compte ?')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$a['id']?>"><button class="btn sm danger">Suppr.</button></form>
    <?php endif; ?>
  </td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php admin_footer();
