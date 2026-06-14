<?php
/**
 * _acces_sync.php — Proxy serveur : signe et relaie l'appel de sync vers dev.adherents.
 * Appelé en same-origin par l'overlay (_acces_lishan.php). Vérifie la session CRM.
 *
 * ⚠ SYNC_SECRET doit être identique à sync_secret côté dev.adherents (config.php).
 *    Ne jamais committer le vrai secret : renseigner la vraie valeur sur le serveur.
 */
require_once __DIR__.'/auth.php';
header('Content-Type: application/json; charset=utf-8');
if (!is_auth()) { http_response_code(403); echo json_encode(['ok'=>false,'err'=>'Non authentifié.']); exit; }

const SYNC_SECRET = 'REMPLACER_PAR_LE_SECRET_SYNC';
const TARGET = 'https://www.dev.adherents.lishan.fr/api/crm-sync.php';

$scope = ($_POST['scope'] ?? 'all') === 'one' ? 'one' : 'all';
$crmId = (int)($_POST['crm_id'] ?? 0);
$ts    = (string)time();
$sig   = hash_hmac('sha256', "$ts.$scope.$crmId", SYNC_SECRET);

$url = TARGET . '?ts=' . urlencode($ts) . '&sig=' . urlencode($sig) . '&scope=' . $scope . '&crm_id=' . $crmId;

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 30,
  CURLOPT_SSL_VERIFYPEER => true,
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($res === false) { http_response_code(502); echo json_encode(['ok'=>false,'err'=>'Connexion impossible: '.$err]); exit; }
http_response_code($code ?: 200);
echo $res;
