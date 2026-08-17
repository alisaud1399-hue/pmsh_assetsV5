<?php
/**
 * inventory/api/room_assets.php
 * جلب الأجهزة داخل غرفة معينة مع حالة الجرد
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$session_id = (int)($_REQUEST['session_id'] ?? 0);
$room_id    = (int)($_REQUEST['room_id'] ?? 0);
if ($session_id <= 0 || $room_id <= 0) {
    json_response(['ok' => false, 'error' => 'بيانات الجلسة أو الغرفة مفقودة']);
}

// التحقق من الجلسة
$st = $pdo->prepare("SELECT id, status FROM inventory_sessions WHERE id=?");
$st->execute([$session_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session || !in_array($session['status'], ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'الجلسة غير نشطة']);
}

// جلب تفاصيل الغرفة
$rq = $pdo->prepare("
    SELECT r.id, COALESCE(NULLIF(r.name_en,''), r.name) AS room,
           f.id AS floor_id, COALESCE(NULLIF(f.name_en,''), f.name) AS floor,
           b.id AS building_id, COALESCE(NULLIF(b.name_en,''), b.name) AS building
    FROM item_locations r
    LEFT JOIN item_locations f ON f.id = r.parent_id
    LEFT JOIN item_locations b ON b.id = f.parent_id
    WHERE r.id = ? AND r.location_type = 'room'
");
$rq->execute([$room_id]);
$room = $rq->fetch(PDO::FETCH_ASSOC);
if (!$room) json_response(['ok' => false, 'error' => 'لم يتم العثور على الغرفة']);

$req_dept_id = (int)($_REQUEST['dept_id'] ?? 0);

try {
    // استعلام محصن ضد الأخطاء والتكرار يجلب أحدث حركة جرد واسم الموظف
    $aq = $pdo->prepare("
        SELECT a.id, a.description, a.description_ar, a.en_name, a.tag_number, a.serial_number,
               a.asset_type, a.criticality_class, a.status, a.health_score,
               a.manufacturer_name, a.model_number,
               a.loc_building, a.loc_floor, a.loc_room,
               (a.custodian_dept_id = ?) AS custody_match,
               EXISTS(SELECT 1 FROM custody_ai_suggestions s WHERE s.asset_id = a.id AND s.status IN ('pending','accepted') AND s.suggested_dept_id = ?) AS ai_match,
               ia.action AS last_action, ia.audited_at, 
               
               u.full_name AS auditor_name -- ⚠️ تعديل هنا: غير كلمة username إلى اسم العمود الفعلي لديك مثل full_name إذا لزم الأمر

        FROM assets a
        LEFT JOIN (
            SELECT i1.asset_id, i1.action, i1.audited_at, i1.audited_by
            FROM inventory_audits i1
            INNER JOIN (
                SELECT asset_id, MAX(id) as max_id 
                FROM inventory_audits 
                WHERE session_id = ? 
                GROUP BY asset_id
            ) i2 ON i1.id = i2.max_id
        ) ia ON ia.asset_id = a.id
        LEFT JOIN users u ON u.id = ia.audited_by
        WHERE a.location_id = ? AND a.status NOT IN ('disposed','returned_to_supplier')
        ORDER BY a.criticality_class, a.description
    ");
    $aq->execute([$req_dept_id, $req_dept_id, $session_id, $room_id]);
    $assets = $aq->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    json_response(['ok' => false, 'error' => 'خطأ في قاعدة البيانات: ' . $e->getMessage()]);
    exit;
}

$done_actions = ['confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously'];
$out = []; $done_count = 0;

foreach ($assets as $a) {
    $is_done = $a['last_action'] !== null && in_array($a['last_action'], $done_actions, true);
    if ($is_done) $done_count++;

    $out[] = [
        'id' => (int)$a['id'], 
        'name' => $a['en_name'] ?: $a['description'], 
        'name_ar' => $a['description_ar'],
        'tag' => $a['tag_number'], 
        'serial' => $a['serial_number'], 
        'crit' => $a['criticality_class'] ?: 'C',
        'status' => $a['status'], 
        'health' => $a['health_score'] !== null ? (int)$a['health_score'] : null,
        'chips' => array_values(array_filter([$a['manufacturer_name'] ? 'الشركة: ' . $a['manufacturer_name'] : null, $a['model_number'] ? 'الموديل: ' . $a['model_number'] : null])),
        'done' => $is_done, 
        'last_action' => $a['last_action'],
        'auditor' => $a['auditor_name'] ?? 'مستخدم', // في حال لم يجد اسم الموظف
        'audited_at' => $a['audited_at'] ? date('Y-m-d h:i A', strtotime($a['audited_at'])) : null,
        'is_target' => $req_dept_id > 0 ? ($a['custody_match'] || $a['ai_match']) : true,
    ];
}

json_response([
    'ok' => true, 
    'room' => [
        'id' => (int)$room['id'], 
        'name' => $room['room'], 
        'floor' => $room['floor'], 
        'building' => $room['building'], 
        'total' => count($out), 
        'done' => $done_count
    ], 
    'assets' => $out
]);