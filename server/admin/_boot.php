<?php
/** _boot.php — bootstrap commun à toutes les pages admin. */
declare(strict_types=1);
require_once '/home5/guenver/_lishan_secure/lib.php';
start_session('admin');

function admin_header(string $titre, ?array $u=null): void {
  $sc = h(saison_courante());
  ?><!doctype html><html lang="fr"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($titre)?> — Admin Lishan</title>
<link rel="stylesheet" href="assets/admin.css">
</head><body>
<header class="topbar">
  <div class="brand">Lishan <span>· administration</span></div>
  <?php if($u): ?>
  <nav>
    <a href="index.php">Tableau de bord</a>
    <a href="adherents.php">Adhérents</a>
    <a href="ressources.php">Ressources</a>
    <?php if($u['role']==='owner'): ?><a href="comptes.php">Comptes</a><a href="reglages.php">Réglages</a><?php endif; ?>
  </nav>
  <div class="session">
    <span class="saison">Saison <?=$sc?></span>
    <span class="who"><?=h($u['email'])?> · <?=h($u['role'])?></span>
    <a class="logout" href="logout.php">Quitter</a>
  </div>
  <?php endif; ?>
</header>
<main class="wrap"><?php
}
function admin_footer(): void { ?></main></body></html><?php }

function flash(string $msg=null) {
  if ($msg !== null) { $_SESSION['flash'] = $msg; return; }
  $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}
