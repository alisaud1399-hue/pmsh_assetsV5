<?php
/**
 * inventory/api/room_lock.php — إدارة أقفال الغرف (تسجيل وصول / تسلّم / تعليق / إقفال)
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = trim($input['action'] ?? '');
$session_id = (int)($input['session_id'] ?? 0);
$room_id = (int)($input['room_id'] ?? 0);
$me = (int)(current_user()['id'] ?? 0);
if (!$me) json_response(['ok'=>false,'error'=>'no_user'], 401);
if (!$session_id) json_response(['ok'=>false,'error'=>'missing']);
/* بعض الأكشن (resolve) لا تحتاج room_id — التحقق لاحقاً لكل حالة */

$ss = $pdo->prepare("SELECT status FROM inventory_sessions WHERE id=?");
$ss->execute([$session_id]);
$sess_status = $ss->fetchColumn();
if (!$sess_status) json_response(['ok'=>false,'error'=>'no_session']);
if ($sess_status !== 'active') json_response(['ok'=>false,'error'=>'session_not_active','status'=>$sess_status]);
if (!inv_session_guard($session_id)) json_response(['ok'=>false,'error'=>'not_member'], 403);

$mode = get_setting('inv_parallel_mode','off');
$par_rooms = json_decode(get_setting('inv_parallel_rooms','[]'), true) ?: [];
$parallel = ($mode==='all') || ($mode==='selected' && in_array($room_id, array_map('intval',$par_rooms), true));
/* إعدادات الجرد الجديدة (المرجع: inventory/settings.php) */
$allow_takeover      = get_setting('inv_allow_takeover','1') === '1';
$require_oath        = get_setting('inv_require_oath_complete','1') === '1';
$lock_timeout_min    = (int)get_setting('inv_lock_timeout_min','30');
$max_suspend_count   = (int)get_setting('inv_max_suspend_count','3');
$max_locks_per_user  = (int)get_setting('inv_max_locks_per_user','1');
$block_undoc         = get_setting('inv_block_audit_undocumented_room','1') === '1';
$dept_required       = get_setting('inv_dept_required_before_lock','1') === '1';

function rl_log($pdo,$lock_id,$session_id,$room_id,$actor,$type,$note=null){
    $pdo->prepare("INSERT INTO room_lock_events (lock_id,session_id,room_id,actor_id,event_type,note) VALUES (?,?,?,?,?,?)")
        ->execute([$lock_id,$session_id,$room_id,$actor,$type,$note]);
}
function rl_active_locks($pdo,$session_id,$room_id){
    $st=$pdo->prepare("SELECT l.*, u.full_name FROM room_inventory_locks l LEFT JOIN users u ON u.id=l.locked_by
        WHERE l.session_id=? AND l.room_id=? AND l.status='active'");
    $st->execute([$session_id,$room_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function rl_completed($pdo,$session_id,$room_id){
    $st=$pdo->prepare("SELECT l.*, u.full_name FROM room_inventory_locks l LEFT JOIN users u ON u.id=l.locked_by
        WHERE l.session_id=? AND l.room_id=? AND l.status='completed' LIMIT 1");
    $st->execute([$session_id,$room_id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}
function rl_my_other_active($pdo,$session_id,$me,$except_room){
    $st=$pdo->prepare("SELECT l.*, r.name rname, r.name_en rname_en FROM room_inventory_locks l
        LEFT JOIN item_locations r ON r.id=l.room_id
        WHERE l.session_id=? AND l.locked_by=? AND l.status='active' AND l.room_id<>?");
    $st->execute([$session_id,$me,$except_room]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

switch ($action) {

case 'resolve': { // تحويل كود QR الغرفة إلى id
    $code = trim($input['code'] ?? '');
    if ($code === '') json_response(['ok'=>false,'error'=>'no_code']);
    if (strpos($code,'code=') !== false) { $p = explode('code=',$code); $code = urldecode(end($p)); }
    $st = $pdo->prepare("SELECT id FROM item_locations WHERE location_code=? AND location_type='room' LIMIT 1");
    $st->execute([$code]);
    $id = $st->fetchColumn();
    if (!$id) json_response(['ok'=>false,'error'=>'room_not_found']);
    json_response(['ok'=>true,'room_id'=>(int)$id]);
}

case 'status': {
    $locks = rl_active_locks($pdo,$session_id,$room_id);
    $mine=null; $other=null;
    foreach ($locks as $L){ if ((int)$L['locked_by']===$me) $mine=$L; else $other=$other?:$L; }
    $done = rl_completed($pdo,$session_id,$room_id);
    json_response(['ok'=>true,'completed'=>(bool)$done,'completed_by'=>$done?($done['full_name']??''):null,
        'mine'=>(bool)$mine,'other'=>$other?['name'=>$other['full_name'],'at'=>$other['locked_at']]:null,'parallel'=>$parallel]);
}

case 'checkin': {
    $done = rl_completed($pdo,$session_id,$room_id);
    if ($done) json_response(['ok'=>false,'error'=>'room_completed','by'=>$done['full_name']??'']);

    /* قاعدة صارمة: الغرفة لازم تكون موثقة (dept + location_code) قبل الجرد */
    if ($block_undoc || $dept_required) {
        $st = $pdo->prepare("SELECT dept_id, dept_root_id, parse_status, location_code FROM item_locations WHERE id=? AND location_type='room'");
        $st->execute([$room_id]);
        $room = $st->fetch(PDO::FETCH_ASSOC);
        if (!$room) json_response(['ok'=>false,'error'=>'room_not_found']);
        if ($dept_required && (empty($room['dept_id']) || empty($room['dept_root_id']) || ($room['parse_status'] ?? '') !== 'verified')) {
            json_response(['ok'=>false,'error'=>'room_not_verified','msg'=>'الغرفة غير موثقة (بدون قسم مرتبط) — وثقها من إدارة المواقع أولاً']);
        }
        if ($block_undoc && empty($room['location_code'])) {
            json_response(['ok'=>false,'error'=>'room_undocumented','msg'=>'الغرفة بدون تكويد رقمي — أضف location_code من الترميز']);
        }
    }

    $locks = rl_active_locks($pdo,$session_id,$room_id);
    $mine=null; $other=null;
    foreach ($locks as $L){ if ((int)$L['locked_by']===$me) $mine=$L; else $other=$other?:$L; }
    if ($mine) {
        $pdo->prepare("UPDATE room_inventory_locks SET resumed_at=NOW() WHERE id=?")->execute([$mine['id']]);
        rl_log($pdo,$mine['id'],$session_id,$room_id,$me,'resumed');
        json_response(['ok'=>true,'result'=>'resumed','lock_id'=>(int)$mine['id']]);
    }
    $otherRoom = rl_my_other_active($pdo,$session_id,$me,$room_id);
    if ($otherRoom) json_response(['ok'=>false,'error'=>'has_other_lock','room'=>($otherRoom['rname_en']?:$otherRoom['rname'])]);
    if ($other && !$parallel) json_response(['ok'=>false,'error'=>'needs_takeover','by'=>$other['full_name']??'','at'=>$other['locked_at']]);

    /* قاعدة: الحد الأقصى للأقفال لكل موظف */
    if ($max_locks_per_user >= 1) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM room_inventory_locks WHERE session_id=? AND locked_by=? AND status='active'");
        $st->execute([$session_id, $me]);
        $cur_count = (int)$st->fetchColumn();
        if ($cur_count >= $max_locks_per_user) {
            json_response(['ok'=>false,'error'=>'max_locks_reached','limit'=>$max_locks_per_user,'msg'=>"تجاوزت الحد ($max_locks_per_user غرفة) — أنهِ أو علّق غرفة أولاً"]);
        }
    }

    /* حساب expiry_at بناءً على lock_timeout_min */
    $expiry_sql = $lock_timeout_min > 0 ? "DATE_ADD(NOW(), INTERVAL $lock_timeout_min MINUTE)" : "NULL";
    $pdo->prepare("INSERT INTO room_inventory_locks (session_id,room_id,locked_by,status,expires_at) VALUES (?,?,?,'active',$expiry_sql)")
        ->execute([$session_id,$room_id,$me]);
    $lid = (int)$pdo->lastInsertId();
    rl_log($pdo,$lid,$session_id,$room_id,$me,'opened',($parallel && $other)?'check-in مشترك':null);
    json_response(['ok'=>true,'result'=>'opened','lock_id'=>$lid,'expires_in_min'=>$lock_timeout_min]);
}

case 'takeover': {
    if (!$allow_takeover) json_response(['ok'=>false,'error'=>'takeover_disabled','msg'=>'الاستلام معطّل من إعدادات الجرد']);
    $locks = rl_active_locks($pdo,$session_id,$room_id);
    $other=null; $mine=null;
    foreach ($locks as $L){ if ((int)$L['locked_by']===$me) $mine=$L; else $other=$other?:$L; }
    $otherRoom = rl_my_other_active($pdo,$session_id,$me,$room_id);
    if ($otherRoom) json_response(['ok'=>false,'error'=>'has_other_lock','room'=>($otherRoom['rname_en']?:$otherRoom['rname'])]);
    try {
        $pdo->beginTransaction();
        if ($other) {
            $pdo->prepare("UPDATE room_inventory_locks SET status='superseded', note='سُحب القفل بواسطة موظف آخر' WHERE id=?")->execute([$other['id']]);
            rl_log($pdo,$other['id'],$session_id,$room_id,$me,'taken_over','من '.($other['full_name']??''));
        }
        if ($mine) {
            $pdo->prepare("UPDATE room_inventory_locks SET status='active', resumed_at=NOW() WHERE id=?")->execute([$mine['id']]);
            $lid = (int)$mine['id'];
        } else {
            $pdo->prepare("INSERT INTO room_inventory_locks (session_id,room_id,locked_by,status) VALUES (?,?,?,'active')")->execute([$session_id,$room_id,$me]);
            $lid = (int)$pdo->lastInsertId();
        }
        rl_log($pdo,$lid,$session_id,$room_id,$me,'resumed','تسلّم');
        $pdo->commit();
        json_response(['ok'=>true,'lock_id'=>$lid]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['ok'=>false,'error'=>'db']); }
}

case 'suspend': {
    $st=$pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND room_id=? AND locked_by=? AND status='active'");
    $st->execute([$session_id,$room_id,$me]); $lid=$st->fetchColumn();
    if (!$lid) json_response(['ok'=>false,'error'=>'no_lock']);
    rl_log($pdo,$lid,$session_id,$room_id,$me,'suspended');
    json_response(['ok'=>true]);
}

case 'complete': {
    $oath = !empty($input['oath']);
    if ($require_oath && !$oath) json_response(['ok'=>false,'error'=>'oath_required','msg'=>'يجب تأكيد الإقرار قبل الإقفال النهائي']);
    try {
        $pdo->beginTransaction();
        $st=$pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND room_id=? AND locked_by=? AND status='active'");
        $st->execute([$session_id,$room_id,$me]); $lid=$st->fetchColumn();
        if (!$lid) { $pdo->rollBack(); json_response(['ok'=>false,'error'=>'no_lock']); }
        $pdo->prepare("UPDATE room_inventory_locks SET status='completed', completion_oath=?, completed_at=NOW(), note='إقرار بإتمام الجرد' WHERE id=?")
            ->execute([$oath?1:0,$lid]);
        rl_log($pdo,$lid,$session_id,$room_id,$me,'completed');
        $others=$pdo->prepare("SELECT id FROM room_inventory_locks WHERE session_id=? AND room_id=? AND status='active' AND locked_by<>?");
        $others->execute([$session_id,$room_id,$me]);
        foreach ($others->fetchAll(PDO::FETCH_COLUMN) as $oid)
            $pdo->prepare("UPDATE room_inventory_locks SET status='superseded', note='أُقفلت الغرفة بواسطة موظف آخر' WHERE id=?")->execute([$oid]);
        $pdo->commit();
        json_response(['ok'=>true]);
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); json_response(['ok'=>false,'error'=>'db']); }
}

default: json_response(['ok'=>false,'error'=>'bad_action']);
}