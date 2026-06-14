<?php
/** auth.php — login / logout / état / changement mdp pour les adhérents. */
require __DIR__.'/_api.php';
start_session('adherent');
$action = $_GET['action'] ?? 'me';

if ($action==='login' && $_SERVER['REQUEST_METHOD']==='POST') {
  $b=jbody();
  $email=trim((string)($b['email']??'')); $pwd=(string)($b['pwd']??'');
  $rl='adhlogin:'.client_ip();
  if (rate_limited($rl)) jsend(['ok'=>false,'err'=>'Trop de tentatives, réessayez plus tard.'],429);
  $st=db()->prepare('SELECT * FROM adherents WHERE email=? AND actif=1'); $st->execute([$email]);
  $a=$st->fetch();
  if ($a && $a['password_hash'] && password_verify($pwd,$a['password_hash'])) {
    rate_reset($rl); session_regenerate_id(true);
    $_SESSION['aid']=(int)$a['id']; $_SESSION['scope']='adherent';
    journal($email,'login-adherent');
    jsend(['ok'=>true,'nom'=>$a['display_name'],'disciplines'=>adherent_disciplines((int)$a['id'])]);
  }
  rate_hit($rl);
  jsend(['ok'=>false,'err'=>'E-mail ou mot de passe incorrect.'],401);
}
if ($action==='logout') { $_SESSION=[]; session_destroy(); jsend(['ok'=>true]); }
if ($action==='change-pwd' && $_SERVER['REQUEST_METHOD']==='POST') {
  $a=adherent_current(); if(!$a) jsend(['ok'=>false],401);
  $b=jbody(); $old=(string)($b['old']??''); $new=(string)($b['new']??'');
  if (!password_verify($old,$a['password_hash'])) jsend(['ok'=>false,'err'=>'Ancien mot de passe incorrect.'],400);
  if (strlen($new)<8) jsend(['ok'=>false,'err'=>'Le nouveau mot de passe doit faire au moins 8 caractères.'],400);
  db()->prepare('UPDATE adherents SET password_hash=?, modifie=datetime("now") WHERE id=?')
     ->execute([password_hash($new,PASSWORD_DEFAULT),(int)$a['id']]);
  journal($a['email'],'change-pwd');
  jsend(['ok'=>true]);
}
$a=adherent_current();
if (!$a) jsend(['ok'=>false,'auth'=>false]);
jsend(['ok'=>true,'auth'=>true,'nom'=>$a['display_name'],'email'=>$a['email'],'disciplines'=>adherent_disciplines((int)$a['id'])]);
