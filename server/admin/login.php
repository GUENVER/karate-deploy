<?php
require __DIR__.'/_boot.php';
if (admin_user()) { header('Location: index.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $email = trim((string)($_POST['email'] ?? ''));
  $pwd   = (string)($_POST['pwd'] ?? '');
  $rlKey = 'login:'.client_ip();
  if (rate_limited($rlKey)) {
    $err = 'Trop de tentatives. Réessayez dans 15 minutes.';
  } else {
    $st = db()->prepare('SELECT * FROM admins WHERE email=? AND actif=1');
    $st->execute([$email]); $a = $st->fetch();
    if ($a && password_verify($pwd, $a['password_hash'])) {
      rate_reset($rlKey); session_regenerate_id(true);
      $_SESSION['uid'] = (int)$a['id']; $_SESSION['scope'] = 'admin';
      journal($a['email'],'login-admin');
      header('Location: index.php'); exit;
    }
    rate_hit($rlKey); $err = 'Identifiants incorrects.';
  }
}
?><!doctype html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion — Admin Lishan</title><link rel="stylesheet" href="assets/admin.css">
</head><body>
<form class="login-box" method="post" autocomplete="off">
  <h1>Administration Lishan</h1>
  <?php if($err): ?><div class="err"><?=h($err)?></div><?php endif; ?>
  <?=csrf_field()?>
  <label>Adresse e-mail</label>
  <input type="email" name="email" required autofocus value="<?=h($_POST['email']??'')?>">
  <label>Mot de passe</label>
  <input type="password" name="pwd" required>
  <div class="row" style="margin-top:18px">
    <button class="btn primary" style="width:100%;justify-content:center">Se connecter</button>
  </div>
</form>
</body></html>
