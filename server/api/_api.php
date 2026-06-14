<?php
/** _api.php — bootstrap commun API : JSON, session adhérent. */
declare(strict_types=1);
require_once '/home5/guenver/_lishan_secure/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function jsend($data, int $code=200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function jbody(): array { $r=json_decode(file_get_contents('php://input'),true); return is_array($r)?$r:[]; }

function adherent_current(): ?array {
  start_session('adherent');
  if (empty($_SESSION['aid']) || ($_SESSION['scope']??'')!=='adherent') return null;
  $st=db()->prepare('SELECT * FROM adherents WHERE id=? AND actif=1'); $st->execute([$_SESSION['aid']]);
  return $st->fetch() ?: null;
}
function adherent_disciplines(int $aid): array {
  $st=db()->prepare('SELECT di.slug FROM acces a JOIN disciplines di ON di.id=a.discipline_id WHERE a.adherent_id=?');
  $st->execute([$aid]);
  return array_column($st->fetchAll(), 'slug');
}
