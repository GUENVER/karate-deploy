<?php
/**
 * lib.php — Fonctions communes : config, PDO, sécurité, CSRF, sessions, accès.
 * Inclus par /admin et /api. Aucune sortie directe.
 */
declare(strict_types=1);

const CONFIG_PATH = '/home5/guenver/_lishan_secure/config.php';

function cfg(string $key=null) {
  static $c = null;
  if ($c === null) $c = require CONFIG_PATH;
  return $key === null ? $c : ($c[$key] ?? null);
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $pdo = new PDO('sqlite:'.cfg('access_db'), null, null, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
  }
  return $pdo;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

function client_ip(): string {
  return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
}

function journal(string $acteur, string $action, string $cible='', string $detail=''): void {
  db()->prepare('INSERT INTO journal (acteur,action,cible,detail,ip) VALUES (?,?,?,?,?)')
      ->execute([$acteur,$action,$cible,$detail,client_ip()]);
}

function start_session(string $scope='admin'): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $life = $scope === 'adherent' ? cfg('session_lifetime_adherent') : cfg('session_lifetime_admin');
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (($_SERVER['SERVER_PORT']??'')==443);
  session_name($scope === 'adherent' ? 'lsh_adh' : 'lsh_adm');
  ini_set('session.gc_maxlifetime', (string)$life);
  ini_set('session.use_strict_mode', '1');
  session_set_cookie_params([
    'lifetime'=>$life,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax',
  ]);
  session_start();
  if (!empty($_SESSION['uid']) && isset($_COOKIE[session_name()])) {
    setcookie(session_name(), session_id(), [
      'expires'=>time()+$life,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax',
    ]);
  }
}

function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_field(): string {
  return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
}
function csrf_check(): void {
  $t = $_POST['csrf'] ?? '';
  if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
    http_response_code(403); exit('CSRF token invalide.');
  }
}

function admin_user(): ?array {
  if (empty($_SESSION['uid']) || ($_SESSION['scope']??'')!=='admin') return null;
  $st = db()->prepare('SELECT * FROM admins WHERE id=? AND actif=1');
  $st->execute([$_SESSION['uid']]);
  return $st->fetch() ?: null;
}
function require_admin(): array {
  $u = admin_user();
  if (!$u) { header('Location: login.php'); exit; }
  return $u;
}
function require_owner(): array {
  $u = require_admin();
  if ($u['role'] !== 'owner') { http_response_code(403); exit('Réservé au propriétaire.'); }
  return $u;
}

function rate_limited(string $key): bool {
  $f = sys_get_temp_dir().'/lsh_rl_'.md5($key);
  $n = is_file($f) ? (int)file_get_contents($f) : 0;
  $tooOld = is_file($f) && (time()-filemtime($f) > 900);
  if ($tooOld) { @unlink($f); $n = 0; }
  return $n >= 8;
}
function rate_hit(string $key): void {
  $f = sys_get_temp_dir().'/lsh_rl_'.md5($key);
  $n = is_file($f) ? (int)file_get_contents($f) : 0;
  file_put_contents($f, (string)($n+1));
}
function rate_reset(string $key): void { @unlink(sys_get_temp_dir().'/lsh_rl_'.md5($key)); }

function disciplines_all(): array {
  return db()->query('SELECT * FROM disciplines ORDER BY order_index')->fetchAll();
}
function saison_courante(): string {
  return db()->query("SELECT valeur FROM reglages WHERE cle='saison_courante'")->fetchColumn() ?: cfg('saison_defaut');
}
