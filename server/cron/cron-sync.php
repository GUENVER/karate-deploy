<?php
/** cron-sync.php — Exécuté par le cron horaire. Synchro complète CRM -> accès. */
declare(strict_types=1);
require_once '/home5/guenver/_lishan_data/sync.php';
$res = syncAll(null);
$res['nouveaux_pwd'] = count($res['nouveaux_pwd']);
fwrite(STDOUT, date('c').' '.json_encode($res, JSON_UNESCAPED_UNICODE)."\n");
