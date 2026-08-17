<?php
/**
 * inventory/api/reaudit_request.php
 * إنشاء طلب إعادة جرد لأصل جُرِد مسبقاً في جلسة — يذهب لصاحب صلاحية
 * الاعتماد للبت فيه في صفحة الجلسة.
 */
require_once dirname(__DIR__, 2) . '/config.php';
header('Content-Type: application/json; charset=utf-8');
require_login();

$body       = json_decode(file_get_contents('php://input'), true) ?: [];
$session_id = (int)($body['session_id'] ?? 0);
$asset_id   = (int)($body['asset_id'] ?? 0);
$reason     = trim($body['reason'] ?? '');
$user_id    = (int)($_SESSION['user_id'] ?? 0);

if ($session_id <= 0 || $asset_id <= 0) json_response(['ok' => false, 'error' => 'بيانات ناقصة']);
if ($reason === '') json_response(['ok' => false, 'error' => 'سبب الطلب إلزامي']);

// الجلسة يجب أن تكون حية
$st = $pdo->prepare("SELECT id, status FROM inventory_sessions WHERE id=?");
$st->execute([$session_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session || !in_array($session['status'], ['active', 'review'], true)) {
    json_response(['ok' => false, 'error' => 'الجلسة غير نشطة']);
}

// آخر سجل جرد "منجَز" لهذا الأصل في هذه الجلسة — هو المطعون فيه
$aq = $pdo->prepare("
    SELECT id, action FROM inventory_audits
    WHERE session_id=? AND asset_id=?
      AND action IN ('confirmed','location_changed','custody_changed','condition_damaged','missing','missing_disposed_previously','missing_under_investigation')
    ORDER BY id DESC LIMIT 1
");
$aq->execute([$session_id, $asset_id]);
$audit = $aq->fetch(PDO::FETCH_ASSOC);
if (!$audit) json_response(['ok' => false, 'error' => 'لا يوجد سجل جرد منجَز لهذا الأصل في الجلسة']);

// منع تكرار طلب معلَّق لنفس الأصل في نفس الجلسة
$dq = $pdo->prepare("SELECT id FROM inventory_reaudit_requests WHERE session_id=? AND asset_id=? AND status='pending' LIMIT 1");
$dq->execute([$session_id, $asset_id]);
if ($dq->fetch()) json_response(['ok' => false, 'error' => 'يوجد طلب معلَّق مسبقاً لهذا الأصل — بانتظار البت فيه']);

$ins = $pdo->prepare("
    INSERT INTO inventory_reaudit_requests (session_id, asset_id, audit_id, requested_by, reason)
    VALUES (?, ?, ?, ?, ?)
");
$ins->execute([$session_id, $asset_id, (int)$audit['id'], $user_id, $reason]);

json_response(['ok' => true, 'request_id' => (int)$pdo->lastInsertId()]);