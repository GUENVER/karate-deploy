<?php
// Déclencheur web du générateur (appelé par n8n ou une routine) : lance gen.php en tâche de fond.
// À déposer dans un docroot du hub ; le jeton est lu dans ~/fabrique-gen/gen-config.php (clé tick_token).
$G = require '/home3/guenver/fabrique-gen/gen-config.php';
if (empty($G['tick_token']) || !hash_equals($G['tick_token'], (string)($_GET['k'] ?? ''))) { http_response_code(403); exit('forbidden'); }
$max = max(1, min(6, (int)($_GET['max'] ?? 2)));
exec('/usr/bin/timeout 280 /usr/local/bin/php /home3/guenver/fabrique-gen/gen.php --max=' . $max . ' >> /home3/guenver/fabrique-gen/gen.log 2>&1 &');
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'started' => date('c'), 'max' => $max]);
