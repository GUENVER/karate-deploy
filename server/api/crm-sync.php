<?php
/**
 * crm-sync.php — Pont temps réel appelé par le CRM Organisation. Sécurisé par HMAC.
 * sig = hash_hmac('sha256', "$ts.$scope.$crm_id", sync_secret). Jeton valable 120 s.
 */
require __DIR__.'/_api.php';
$ts=(string)($_GET['ts']??''); $sig=(string)($_GET['sig']??'');
$scope=($_GET['scope']??'all')==='one'?'one':'all'; $crmId=(int)($_GET['crm_id']??0);

if ($ts==='' || $sig==='' || abs(time()-(int)$ts) > 120) jsend(['ok'=>false,'err'=>'Jeton expiré.'],403);
$expected = hash_hmac('sha256', "$ts.$scope.$crmId", (string)cfg('sync_secret'));
if (!hash_equals($expected, $sig)) jsend(['ok'=>false,'err'=>'Signature invalide.'],403);

require_once '/home5/guenver/_lishan_data/sync.php';
$res = syncAll($scope==='one' ? $crmId : null);
$res['nouveaux_pwd'] = count($res['nouveaux_pwd'] ?? []);
journal('crm-bouton', $scope==='one'?'sync-one':'sync-all', (string)$crmId, 'créés='.$res['crees']);
jsend(['ok'=>true,'scope'=>$scope,'result'=>$res]);
