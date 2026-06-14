<?php
/**
 * sync.php — Moteur de synchronisation CRM Organisation -> base d'accès dev.adherents.
 * Règles : éligible si statut ∈ {Adhérent, Pré-inscription} ET saison courante ∈ saison[].
 * Droits = disciplines cochées -> familles via mapping_discipline. Override admin préservé.
 * Inéligible -> compte désactivé (jamais supprimé).
 * Usage : php sync.php  |  php sync.php <crm_id>
 */
declare(strict_types=1);

const CRM_DB    = '/home5/guenver/organisation.lishan.fr/app/data/adherents.db';
const ACCESS_DB = '/home5/guenver/_lishan_data/access.db';

function pdo(string $path, bool $ro=false): PDO {
  $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  if (!$ro) $pdo->exec('PRAGMA foreign_keys = ON');
  return $pdo;
}
function randPwd(int $n=12): string {
  $a='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
  $s=''; for($i=0;$i<$n;$i++) $s.=$a[random_int(0,strlen($a)-1)];
  return $s;
}
function jarr($s): array { $v=json_decode($s?:'[]',true); return is_array($v)?$v:[]; }
function estEligible(array $statuts, array $saisons, string $saisonCourante): bool {
  $statStr = implode(',', $statuts);
  $okStatut = (strpos($statStr,'Adhérent')!==false && strpos($statStr,'Ancien')===false)
           || strpos($statStr,'inscription')!==false;
  return $okStatut && in_array($saisonCourante, $saisons, true);
}

function syncAll(?int $onlyCrmId=null): array {
  $crm = pdo(CRM_DB, true);
  $db  = pdo(ACCESS_DB);
  $saisonCourante = $db->query("SELECT valeur FROM reglages WHERE cle='saison_courante'")->fetchColumn() ?: '2025/2026';

  $map = [];
  foreach ($db->query('SELECT crm_label, famille_slug FROM mapping_discipline') as $r) $map[$r['crm_label']] = $r['famille_slug'];
  $discId = [];
  foreach ($db->query('SELECT id, slug FROM disciplines') as $r) $discId[$r['slug']] = (int)$r['id'];

  $sql = 'SELECT id,nom,email,saison,discipline,statut,wp_id FROM adherents';
  if ($onlyCrmId) $sql .= ' WHERE id='.(int)$onlyCrmId;
  $stmt = $crm->query($sql);

  $upAdh = $db->prepare(
    'INSERT INTO adherents (crm_id,wp_id,email,login,display_name,password_hash,actif,saison)
     VALUES (?,?,?,?,?,?,1,?)
     ON CONFLICT(email) DO UPDATE SET crm_id=excluded.crm_id, wp_id=excluded.wp_id,
       display_name=excluded.display_name, actif=1, saison=excluded.saison, modifie=datetime("now")'
  );
  $desactiver = $db->prepare('UPDATE adherents SET actif=0, modifie=datetime("now") WHERE email=?');
  $getAdh = $db->prepare('SELECT id FROM adherents WHERE email=?');
  $delCrmAcc = $db->prepare("DELETE FROM acces WHERE adherent_id=? AND source='crm'");
  $insAcc = $db->prepare("INSERT OR IGNORE INTO acces (adherent_id,discipline_id,source) VALUES (?,?,'crm')");

  $stats = ['vus'=>0,'crees'=>0,'maj'=>0,'desactives'=>0,'ineligibles'=>0,'nouveaux_pwd'=>[]];

  foreach ($stmt as $row) {
    $stats['vus']++;
    $email = trim((string)$row['email']);
    if ($email==='' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    $statuts = jarr($row['statut']); $saisons = jarr($row['saison']); $disc = jarr($row['discipline']);

    if (!estEligible($statuts, $saisons, $saisonCourante)) {
      $getAdh->execute([$email]);
      if ($getAdh->fetchColumn()) { $desactiver->execute([$email]); $stats['desactives']++; }
      else $stats['ineligibles']++;
      continue;
    }
    $getAdh->execute([$email]); $exists = $getAdh->fetchColumn();
    $pwdHash = '';
    if (!$exists) {
      $pwd = randPwd(); $pwdHash = password_hash($pwd, PASSWORD_DEFAULT);
      $stats['nouveaux_pwd'][$email] = $pwd; $stats['crees']++;
    } else { $stats['maj']++; }

    if ($exists) {
      $db->prepare('UPDATE adherents SET crm_id=?, wp_id=?, display_name=?, actif=1, saison=?, modifie=datetime("now") WHERE email=?')
         ->execute([(int)$row['id'],(int)$row['wp_id'],$row['nom'],$saisonCourante,$email]);
    } else {
      $upAdh->execute([(int)$row['id'],(int)$row['wp_id'],$email,$email,$row['nom'],$pwdHash,$saisonCourante]);
    }
    $getAdh->execute([$email]); $adhId = (int)$getAdh->fetchColumn();

    $delCrmAcc->execute([$adhId]);
    $familles = [];
    foreach ($disc as $d) { if (isset($map[$d])) $familles[$map[$d]] = true; }
    foreach (array_keys($familles) as $slug) { if (isset($discId[$slug])) $insAcc->execute([$adhId, $discId[$slug]]); }
  }

  $db->prepare("INSERT INTO journal (acteur,action,cible,detail) VALUES ('crm-sync',?,?,?)")
     ->execute([$onlyCrmId?'sync-un':'sync-all', $onlyCrmId?(string)$onlyCrmId:'*', json_encode(array_diff_key($stats,['nouveaux_pwd'=>1]), JSON_UNESCAPED_UNICODE)]);
  return $stats;
}

if (PHP_SAPI === 'cli' && realpath($argv[0])===realpath(__FILE__)) {
  $only = isset($argv[1]) ? (int)$argv[1] : null;
  $s = syncAll($only);
  $s2 = $s; $s2['nouveaux_pwd'] = count($s['nouveaux_pwd']).' nouveau(x) compte(s)';
  echo json_encode($s2, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), "\n";
}
