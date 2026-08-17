<?php
/**
 * inventory/api/verified_rooms.php — الغرف الموثّقة فقط (لمنتقي scan)
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$sid = (int)($_GET['session_id'] ?? 0);

$rooms = $pdo->query("SELECT r.id AS room_id,
  COALESCE(NULLIF(r.name_en,''), r.name) AS name,
  COALESCE(NULLIF(r.name,''), r.name_en) AS name_en,
  r.location_code,
  f.id AS floor_id, COALESCE(NULLIF(f.name_en,''), f.name) AS floor, COALESCE(NULLIF(f.name,''), f.name_en) AS floor_en,
  b.id AS building_id, COALESCE(NULLIF(b.name_en,''), b.name) AS building, COALESCE(NULLIF(b.name,''), b.name_en) AS building_en,
  (SELECT COUNT(*) FROM assets a WHERE a.location_id=r.id AND a.status NOT IN ('disposed','returned_to_supplier')) AS total,
  0 AS done
  FROM item_locations r
  JOIN item_locations f ON f.id=r.parent_id
  JOIN item_locations b ON b.id=f.parent_id
  WHERE r.location_type='room' AND r.is_active=1
    AND r.dept_id IS NOT NULL AND r.parse_status='verified'
  ORDER BY b.name, f.name, r.name")->fetchAll(PDO::FETCH_ASSOC);

if ($sid) {
    $dq = $pdo->prepare("SELECT a2.location_id, COUNT(DISTINCT ia.asset_id) d
        FROM inventory_audits ia JOIN assets a2 ON a2.id=ia.asset_id
        WHERE ia.session_id=? AND ia.action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously')
        GROUP BY a2.location_id");
    $dq->execute([$sid]);
    $done = []; foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $r) $done[(int)$r['location_id']] = (int)$r['d'];
    foreach ($rooms as &$r) $r['done'] = $done[(int)$r['room_id']] ?? 0;
}
echo json_encode(['ok'=>true,'rooms'=>$rooms]);