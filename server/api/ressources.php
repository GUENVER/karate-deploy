<?php
/** ressources.php — liste des ressources visibles par l'adhérent connecté. */
require __DIR__.'/_api.php';
$a = adherent_current();
if (!$a) jsend(['ok'=>false,'auth'=>false],401);
$slugs = adherent_disciplines((int)$a['id']);
if (!$slugs) jsend(['ok'=>true,'ressources'=>[],'disciplines'=>[]]);

$in = implode(',', array_fill(0, count($slugs), '?'));
$sql = "SELECT DISTINCT r.id,r.type,r.titre,r.description,r.youtube_id,r.pdf_path,r.order_index
        FROM ressources r
        JOIN ressource_discipline rd ON rd.ressource_id=r.id
        JOIN disciplines di ON di.id=rd.discipline_id
        WHERE r.publie=1 AND di.slug IN ($in)
        ORDER BY r.type, r.order_index, r.titre";
$st=db()->prepare($sql); $st->execute($slugs); $rows=$st->fetchAll();

$ids = array_column($rows,'id'); $discByRes=[];
if ($ids) {
  $in2=implode(',',array_fill(0,count($ids),'?'));
  $q=db()->prepare("SELECT rd.ressource_id, di.slug, di.nom FROM ressource_discipline rd JOIN disciplines di ON di.id=rd.discipline_id WHERE rd.ressource_id IN ($in2)");
  $q->execute($ids);
  foreach($q as $r) $discByRes[(int)$r['ressource_id']][]=['slug'=>$r['slug'],'nom'=>$r['nom']];
}
foreach($rows as &$r){ $r['disciplines']=$discByRes[(int)$r['id']]??[]; $r['id']=(int)$r['id']; }
jsend(['ok'=>true,'nom'=>$a['display_name'],'disciplines'=>$slugs,'ressources'=>$rows]);
