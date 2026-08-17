<?php
/**
 * complaints/escalation.php — لوحة لجنة المتابعة (الفريق الرابع)
 * مراقبة عامة (قراءة فقط) لكل البلاغات + تصرّف كامل بالأربعة مسارات على المُصعَّد فقط.
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_lib.php';
page_guard('complaints.escalation');

// هذه الدوال معرَّفة في view.php لا _lib.php — نعرِّفها هنا صراحة لتجنّب الخطأ
if (!function_exists('notify_sys')) {
    function notify_sys($pdo, $target_uid, $type, $title, $body, $cid, $link = null) {
        try {
            if (!$target_uid) return;
            $link = $link ?? (BASE_URL . '/complaints/view.php?id=' . $cid);
            $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$target_uid, $type, $title, $body, $link, 'complaint', $cid]);
        } catch (Exception $e) {}
    }
}
if (!function_exists('notify_dept_and_requester')) {
    function notify_dept_and_requester($pdo, $c, $type, $title, $body) {
        $link = BASE_URL . '/complaints/my.php?id=' . $c['id'];
        $targets = users_with_permission($pdo, 'complaints.my', 'manage', (int) $c['dept_id']);
        $targets[] = $c['requested_by'];
        foreach (array_unique(array_filter($targets)) as $t) {
            notify_sys($pdo, $t, $type, $title, $body, $c['id'], $link);
        }
    }
}

$uid = (int) current_user()['id'];
$can_manage = can('complaints.escalation', 'manage');
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

function logTlEsc($pdo, $cid, $type, $label, $old, $new, $uid) {
    try {
        $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,?,?,?,?,?)")
            ->execute([$cid, $type, $label, $old, $new, $uid]);
    } catch (Exception $e) {}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage) {
    $cid = (int) ($_POST['complaint_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if (!verify_csrf()) {
        $errors[] = 'خطأ في الجلسة (CSRF).';
    } else {
        $s = $pdo->prepare("SELECT * FROM complaints WHERE id=? AND status='escalated'");
        $s->execute([$cid]);
        $c = $s->fetch();
        if (!$c) {
            $errors[] = 'البلاغ غير موجود، أو ليس في حالة "مُصعَّد" حالياً.';
        } else {
            if ($action === 'resolve_by_committee') {
                $notes = trim($_POST['notes'] ?? '');
                if (!$notes) { $errors[] = 'يجب كتابة ملاحظات الحل.'; }
                else {
                    $pdo->prepare("UPDATE complaints SET status='resolved', resolved_by=?, resolved_at=NOW(), resolution_notes=?,
                        sla_paused_seconds_total = sla_paused_seconds_total
                            + IF(sla_paused_at IS NULL, 0, TIMESTAMPDIFF(SECOND, sla_paused_at, NOW())),
                        sla_paused_at = NULL, sla_pause_reason = NULL WHERE id=?")->execute([$uid, $notes, $cid]);
                    if (!empty($c['asset_id'])) { $pdo->prepare("UPDATE assets SET status='active', last_maintenance_date=NOW() WHERE id=?")->execute([$c['asset_id']]); }
                    logTlEsc($pdo, $cid, 'resolved', 'تم الحل من قبل لجنة المتابعة: ' . $notes, 'escalated', 'resolved', $uid);
                    notify_dept_and_requester($pdo, $c, 'success', 'تم حل مشكلتك من لجنة المتابعة', 'يرجى الدخول لتأكيد الحل وتقييم الخدمة.');
                    $teamUsers = $pdo->prepare("SELECT u.id FROM users u JOIN departments d ON d.id=u.department_id WHERE d.dept_category=? AND u.is_active=1");
                    $teamUsers->execute(['maintenance_' . $c['request_type']]);
                    notify_many($teamUsers->fetchAll(PDO::FETCH_COLUMN), 'info', 'لجنة المتابعة حلّت بلاغاً مُصعَّداً', 'بلاغ ' . $c['request_number'] . ' تم حله من قبل لجنة المتابعة.', BASE_URL . '/complaints/view.php?id=' . $cid);
                    flash('success', 'تم تسجيل الحل، بانتظار تأكيد المُبلِّغ.');
                }

            } elseif ($action === 'unresolvable') {
                $reason = trim($_POST['reason'] ?? '');
                if (!$reason) { $errors[] = 'يجب كتابة أسباب تعذّر الحل.'; }
                else {
                    $pdo->prepare("UPDATE complaints SET status='closed', closed_by=?, closed_at=NOW(), unresolvable_by=?, unresolvable_at=NOW(), unresolvable_reason=?,
                        sla_paused_seconds_total = sla_paused_seconds_total
                            + IF(sla_paused_at IS NULL, 0, TIMESTAMPDIFF(SECOND, sla_paused_at, NOW())),
                        sla_paused_at = NULL, sla_pause_reason = NULL WHERE id=?")
                        ->execute([$uid, $uid, $reason, $cid]);
                    logTlEsc($pdo, $cid, 'unresolvable', 'تعذّر الحل نهائياً (لجنة المتابعة): ' . $reason, 'escalated', 'closed', $uid);
                    notify_dept_and_requester($pdo, $c, 'danger', 'تعذّر حل بلاغك', $reason);
                    $teamUsers = $pdo->prepare("SELECT u.id FROM users u JOIN departments d ON d.id=u.department_id WHERE d.dept_category=? AND u.is_active=1");
                    $teamUsers->execute(['maintenance_' . $c['request_type']]);
                    notify_many($teamUsers->fetchAll(PDO::FETCH_COLUMN), 'info', 'إغلاق بلاغ مُصعَّد (تعذّر الحل)', 'بلاغ ' . $c['request_number'] . ': ' . $reason, BASE_URL . '/complaints/view.php?id=' . $cid);
                    flash('info', 'تم إغلاق البلاغ بتصنيف "تعذّر الحل".');
                }

            } elseif ($action === 'return_to_maintenance') {
                $note = trim($_POST['return_note'] ?? '');
                if (!$note) { $errors[] = 'يجب كتابة التوجيهات للصيانة.'; }
                else {
                    $hKey = 'escalation_hours_' . $c['priority'];
                    $hStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
                    $hStmt->execute([$hKey]);
                    $hours = (int) ($hStmt->fetchColumn() ?: 4);
                    $pdo->prepare("
                        UPDATE complaints SET status='in_progress',
                            returned_by_committee_by=?, returned_by_committee_at=NOW(), returned_by_committee_note=?,
                            escalation_due_at=DATE_ADD(NOW(), INTERVAL ? HOUR), escalation_notified=0,
                            sla_breach_detected_at=NULL,
                            sla_paused_seconds_total = sla_paused_seconds_total
                                + IF(sla_paused_at IS NULL, 0, TIMESTAMPDIFF(SECOND, sla_paused_at, NOW())),
                            sla_paused_at = NULL, sla_pause_reason = NULL
                        WHERE id=?
                    ")->execute([$uid, $note, $hours, $cid]);
                    logTlEsc($pdo, $cid, 'returned_by_committee', 'معاد من لجنة المتابعة لفريق الصيانة: ' . $note, 'escalated', 'in_progress', $uid);
                    logTlEsc($pdo, $cid, 'sla_resumed', '▶️ استؤنف احتساب وقت المعالجة — عاد البلاغ لفريق الصيانة', 'in_progress', 'in_progress', $uid);
                    $teamUsers = $pdo->prepare("SELECT u.id FROM users u JOIN departments d ON d.id=u.department_id WHERE d.dept_category=? AND u.is_active=1");
                    $teamUsers->execute(['maintenance_' . $c['request_type']]);
                    notify_many($teamUsers->fetchAll(PDO::FETCH_COLUMN), 'warning', 'بلاغ معاد من لجنة المتابعة', $note, BASE_URL . '/complaints/view.php?id=' . $cid);
                    notify_dept_and_requester($pdo, $c, 'info', 'بلاغك قيد المعالجة مجدداً', 'راجعته لجنة المتابعة وأعادته لفريق الصيانة لاستكمال المعالجة.');
                    flash('success', 'تم إعادة البلاغ لفريق الصيانة مع توجيهاتك.');
                }

            } elseif ($action === 'escalate_executive') {
                $note = trim($_POST['exec_note'] ?? '');
                $pdo->prepare("UPDATE complaints SET executive_escalated_by=?, executive_escalated_at=NOW(), executive_escalation_note=? WHERE id=?")
                    ->execute([$uid, $note, $cid]);
                logTlEsc($pdo, $cid, 'executive_escalated', 'صُعِّد للإدارة التنفيذية' . ($note ? ': ' . $note : ''), 'escalated', 'escalated', $uid);
                $execUsers = $pdo->prepare("SELECT id FROM users WHERE is_admin=1 AND is_active=1");
                $execUsers->execute();
                notify_many($execUsers->fetchAll(PDO::FETCH_COLUMN), 'danger', 'بلاغ يحتاج قراراً تنفيذياً: ' . $c['request_number'], $note ?: 'رأت لجنة المتابعة أن هذا البلاغ يتجاوز صلاحيتها.', BASE_URL . '/complaints/escalation.php?id=' . $cid);
                flash('warning', 'تم تصعيد البلاغ للإدارة التنفيذية.');

            } else {
                $errors[] = 'إجراء غير معروف.';
            }
            if (!$errors) { header('Location: ' . BASE_URL . '/complaints/escalation.php?id=' . $cid); exit; }
        }
    }
}

// ---------- إحصائيات عامة (للوحة الرئيسية) ----------
$stats = $pdo->query("
    SELECT
        SUM(status='escalated') AS escalated_count,
        SUM(sla_breach_detected_at IS NOT NULL AND status NOT IN ('escalated','closed','cancelled','rejected')) AS breached_not_escalated,
        SUM(status NOT IN ('escalated','closed','cancelled','rejected')) AS total_active_excl_esc,
        SUM(status IN ('closed') AND DATE(closed_at)=CURDATE()) AS closed_today
    FROM complaints
")->fetch();

// البلاغات المُعادة من اللجنة وما زالت لم تُغلَق بعد
$returnedList = $pdo->query("
    SELECT c.id, c.request_number, c.priority, c.status, c.returned_by_committee_at,
           c.returned_by_committee_note, c.resolution_rejected_reason, c.request_type,
           d.name AS dept_name, a.description AS asset_desc
    FROM complaints c
    LEFT JOIN departments d ON d.id=c.dept_id
    LEFT JOIN assets a ON a.id=c.asset_id
    WHERE (c.returned_by_committee_at IS NOT NULL OR c.resolution_rejected_reason IS NOT NULL)
      AND c.status NOT IN ('closed','cancelled','rejected','resolved','escalated')
    ORDER BY GREATEST(
        COALESCE(c.returned_by_committee_at, '1970-01-01'),
        COALESCE(c.updated_at, '1970-01-01')
    ) DESC
")->fetchAll();

$detail = null;
if ($id) {
    $s = $pdo->prepare("
        SELECT c.*, a.description AS asset_desc, a.tag_number, d.name AS dept_name, u.full_name AS requester_name
        FROM complaints c LEFT JOIN assets a ON a.id=c.asset_id LEFT JOIN departments d ON d.id=c.dept_id
        LEFT JOIN users u ON u.id=c.requested_by WHERE c.id=?
    ");
    $s->execute([$id]);
    $detail = $s->fetch();
    if ($detail) {
        $t = $pdo->prepare("SELECT t.*, u.full_name AS actor_name FROM complaint_timeline t LEFT JOIN users u ON u.id=t.actor_id WHERE t.complaint_id=? ORDER BY t.created_at ASC");
        $t->execute([$id]);
        $timeline = $t->fetchAll();
    }
} else {
    $escalatedList = $pdo->query("
        SELECT c.id, c.request_number, c.priority, c.escalated_at, c.dept_id, d.name AS dept_name, a.description AS asset_desc
        FROM complaints c LEFT JOIN departments d ON d.id=c.dept_id LEFT JOIN assets a ON a.id=c.asset_id
        WHERE c.status='escalated' ORDER BY c.escalated_at ASC
    ")->fetchAll();

    $monitorList = $pdo->query("
        SELECT c.id, c.request_number, c.priority, c.status, c.created_at, c.closed_at, c.sla_breach_detected_at, c.request_type, d.name AS dept_name
        FROM complaints c LEFT JOIN departments d ON d.id=c.dept_id
        WHERE c.status != 'escalated'
        ORDER BY c.created_at DESC LIMIT 150
    ")->fetchAll();
}

$STATUS_AR = COMPLAINT_STATUS_AR;
$PRI_AR = COMPLAINT_PRIORITY_AR;
$page_title = $detail ? 'بلاغ ' . $detail['request_number'] . ' — لجنة المتابعة' : 'لوحة لجنة المتابعة';
$active_nav = 'complaints.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#7c3aed; }
body { background: var(--bg); font-family:'Tajawal',sans-serif; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width: 1300px; margin: 0 auto; padding: 22px; }
.h-banner { background:linear-gradient(135deg,#3b0764,#581c87); border-radius:24px; padding:24px 30px; color:#fff; display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; box-shadow:0 18px 36px rgba(88,28,135,.2); margin-bottom:20px; }
.h-banner h1 { font-size:21px; font-weight:900; margin:0 0 5px; display:flex; align-items:center; gap:10px; }
.h-banner p { font-size:12px; color:#e9d5ff; margin:0; }

.stat-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:20px; }
.stat-card { background:#fff; border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.stat-top { padding:8px 14px; font-size:11px; font-weight:800; color:#fff; }
.stat-num { padding:12px 14px; font-size:24px; font-weight:900; }

.bento { background:var(--card); border-radius:20px; box-shadow:0 4px 18px rgba(0,0,0,.04); border:1px solid var(--border); padding:22px; margin-bottom:18px; }
.bento-h { font-size:14.5px; font-weight:900; margin:0 0 16px; display:flex; align-items:center; gap:9px; }
.bento-h i { color:var(--primary) }

.esc-card { display:flex; align-items:center; gap:14px; background:#fef2f2; border:1px solid #fecaca; border-radius:14px; padding:14px 18px; margin-bottom:10px; text-decoration:none; transition:.2s; }
.esc-card:hover { transform:translateY(-2px); box-shadow:0 8px 18px rgba(220,38,38,.12); }
.mon-row { display:flex; align-items:center; gap:12px; padding:10px 6px; border-bottom:1px solid var(--border); font-size:12.5px; }
.mon-row:last-child { border-bottom:none; }
.bdg { font-size:10.5px; font-weight:900; padding:4px 11px; border-radius:99px; white-space:nowrap; }
.breach-tag { background:#fef2f2; color:#b91c1c; font-size:10px; font-weight:900; padding:3px 9px; border-radius:99px; }

.problem-box { background:#f8fafc; border:1px solid var(--border); border-right:5px solid var(--primary); padding:18px; border-radius:14px; font-size:14px; font-weight:700; line-height:1.8; color:#334155; margin-bottom:16px; }
.act-btn { padding:13px; border-radius:13px; border:none; font-size:13px; font-weight:900; color:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:.2s; width:100%; margin-bottom:9px; }
.act-btn:hover { transform:translateY(-2px); }
.act-box { display:none; margin-top:10px; }
.act-box textarea { width:100%; border:2px solid var(--border); border-radius:12px; padding:11px; font-family:'Tajawal'; margin-bottom:9px; outline:none; font-size:13px; }
.tl-item { display:flex; gap:12px; margin-bottom:16px; position:relative; }
.tl-item::after { content:''; position:absolute; left:15px; top:32px; bottom:-16px; width:1.5px; background:var(--border); }
.tl-item:last-child::after { display:none; }
.tl-icon { width:32px; height:32px; border-radius:50%; background:#f8fafc; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:11px; flex-shrink:0; z-index:1; }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<?php foreach ($flash_msgs as $fm): $fc=['success'=>'#10b981','warning'=>'#f59e0b','info'=>'#3b82f6','danger'=>'#ef4444'][$fm['type']]??'#3b82f6'; ?>
<div style="background:#fff;border:1px solid <?= $fc ?>55;border-right:4px solid <?= $fc ?>;padding:13px 18px;border-radius:12px;margin-bottom:16px;font-weight:800;font-size:13px"><?= e($fm['message']) ?></div>
<?php endforeach; ?>
<?php if ($errors): foreach ($errors as $er): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:13px 18px;border-radius:12px;margin-bottom:16px;font-weight:800;font-size:13px"><i class="fa-solid fa-circle-exclamation"></i> <?= e($er) ?></div>
<?php endforeach; endif; ?>

<?php if ($id && !$detail): ?>
    <div style="text-align:center;padding:60px;color:#94a3b8">البلاغ غير موجود.</div>

<?php elseif ($detail): $st = $STATUS_AR[$detail['status']]; $pr = $PRI_AR[$detail['priority']]; ?>

    <div class="h-banner">
        <div><h1 class="eng"><i class="fa-solid fa-user-shield" style="color:#facc15"></i> #<?= e($detail['request_number']) ?></h1>
        <p><?= e($detail['dept_name'] ?? '—') ?> · <?= e($detail['requester_name'] ?? '—') ?> · <span style="color:<?= $pr[1] ?>;font-weight:900"><?= e($pr[0]) ?></span></p></div>
        <span class="bdg" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>;font-size:12.5px"><?= e($st[0]) ?></span>
    </div>

    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-quote-right"></i> تفاصيل البلاغ</div>
        <div style="font-size:12px;color:var(--muted);font-weight:800;margin-bottom:8px"><?= e($detail['asset_desc'] ?? $detail['location_description'] ?? 'بلاغ عام') ?></div>
        <div class="problem-box"><?= nl2br(e($detail['description'])) ?></div>
        <?php if($detail['escalation_note']): ?><div style="font-size:12.5px;color:#7f1d1d;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:12px;font-weight:700"><i class="fa-solid fa-arrow-up"></i> ملاحظة التصعيد: <?= e($detail['escalation_note']) ?></div><?php endif; ?>
    </div>

    <?php if ($can_manage && $detail['status'] === 'escalated'): ?>
    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-gavel"></i> إجراءات اللجنة</div>
        <button type="button" class="act-btn" style="background:linear-gradient(135deg,#16a34a,#22c55e)" onclick="toggleBox('resBox')"><i class="fa-solid fa-circle-check"></i> حل البلاغ</button>
        <button type="button" class="act-btn" style="background:linear-gradient(135deg,#64748b,#475569)" onclick="toggleBox('unrBox')"><i class="fa-solid fa-ban"></i> تعذّر الحل نهائياً</button>
        <button type="button" class="act-btn" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)" onclick="toggleBox('retBox')"><i class="fa-solid fa-rotate-left"></i> إعادة لفريق الصيانة</button>
        <button type="button" class="act-btn" style="background:linear-gradient(135deg,#b91c1c,#7f1d1d)" onclick="toggleBox('execBox')"><i class="fa-solid fa-building-shield"></i> تصعيد للإدارة التنفيذية</button>

        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="complaint_id" value="<?= $detail['id'] ?>">

            <div class="act-box" id="resBox">
                <input type="hidden" name="action" value="resolve_by_committee">
                <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">ملاحظات الحل <span style="color:#dc2626">*</span></label>
                <textarea name="notes" rows="3"></textarea>
                <button type="submit" class="act-btn" style="background:#16a34a">تأكيد الحل</button>
            </div>
        </form>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="complaint_id" value="<?= $detail['id'] ?>">
            <div class="act-box" id="unrBox">
                <input type="hidden" name="action" value="unresolvable">
                <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">أسباب تعذّر الحل <span style="color:#dc2626">*</span></label>
                <textarea name="reason" rows="3"></textarea>
                <button type="submit" class="act-btn" style="background:#475569">تأكيد الإغلاق</button>
            </div>
        </form>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="complaint_id" value="<?= $detail['id'] ?>">
            <div class="act-box" id="retBox">
                <input type="hidden" name="action" value="return_to_maintenance">
                <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">توجيهات لفريق الصيانة <span style="color:#dc2626">*</span></label>
                <textarea name="return_note" rows="3" placeholder="ما الذي يجب أن يختلف هذه المرة؟"></textarea>
                <button type="submit" class="act-btn" style="background:#2563eb">تأكيد الإعادة (تُجدَّد المهلة كاملة)</button>
            </div>
        </form>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="complaint_id" value="<?= $detail['id'] ?>">
            <div class="act-box" id="execBox">
                <input type="hidden" name="action" value="escalate_executive">
                <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">ملاحظة (اختياري)</label>
                <textarea name="exec_note" rows="3"></textarea>
                <button type="submit" class="act-btn" style="background:#b91c1c">تأكيد التصعيد التنفيذي</button>
            </div>
        </form>
    </div>
    <?php elseif ($detail['status'] !== 'escalated'): ?>
    <div class="bento" style="text-align:center;color:var(--muted);font-weight:800;padding:20px">
        <i class="fa-solid fa-eye" style="font-size:22px;display:block;margin-bottom:8px;color:#a78bfa"></i>
        وضع المراقبة فقط — هذا البلاغ ليس مُصعَّداً حالياً، لا إجراء متاح للجنة عليه.
    </div>
    <?php endif; ?>

    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-list-check"></i> السجل الزمني الكامل</div>
        <?php foreach ($timeline as $tl): ?>
        <div class="tl-item"><div class="tl-icon"><i class="fa-solid fa-check"></i></div><div><div style="font-size:13px;font-weight:800"><?= e($tl['action_label']) ?></div><div style="font-size:11px;color:var(--muted);font-weight:700"><?= e($tl['actor_name']??'—') ?> · <span class="eng"><?= date('d/m H:i', strtotime($tl['created_at'])) ?></span></div></div></div>
        <?php endforeach; ?>
    </div>

    <a href="<?= BASE_URL ?>/complaints/escalation.php" style="color:var(--primary);font-weight:800;font-size:13px;text-decoration:none"><i class="fa-solid fa-arrow-right"></i> العودة للوحة</a>

<?php else: ?>

    <div class="h-banner">
        <div><h1><i class="fa-solid fa-user-shield" style="color:#facc15"></i> لوحة لجنة المتابعة</h1>
        <p>مراقبة شاملة لكل البلاغات، وتصرّف كامل في المُصعَّد منها فقط</p></div>
    </div>

    <div class="stat-grid">
        <div class="stat-card"><div class="stat-top" style="background:#b91c1c"><i class="fa-solid fa-arrow-up"></i> بانتظار اللجنة</div><div class="stat-num" style="color:#dc2626"><?= (int)$stats['escalated_count'] ?></div></div>
        <div class="stat-card"><div class="stat-top" style="background:#7c3aed"><i class="fa-solid fa-rotate-left"></i> معادة للصيانة</div><div class="stat-num" style="color:#7c3aed"><?= count($returnedList) ?></div></div>
        <div class="stat-card"><div class="stat-top" style="background:#475569"><i class="fa-solid fa-list"></i> نشطة (غير مُصعَّدة)</div><div class="stat-num"><?= (int)$stats['total_active_excl_esc'] ?></div></div>
        <div class="stat-card"><div class="stat-top" style="background:#15803d"><i class="fa-solid fa-circle-check"></i> أُغلقت اليوم</div><div class="stat-num" style="color:#16a34a"><?= (int)$stats['closed_today'] ?></div></div>
    </div>

    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-fire"></i> بانتظار اللجنة الآن (<?= count($escalatedList) ?>)</div>
        <?php if (!$escalatedList): ?>
        <div style="text-align:center;color:var(--muted);font-weight:700;padding:20px">لا توجد بلاغات مُصعَّدة حالياً.</div>
        <?php else: foreach ($escalatedList as $r): $pr = $PRI_AR[$r['priority']]; ?>
        <a href="<?= BASE_URL ?>/complaints/escalation.php?id=<?= $r['id'] ?>" class="esc-card">
            <i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:18px"></i>
            <div style="flex:1">
                <div style="font-size:13.5px;font-weight:800"><?= e(mb_substr($r['asset_desc'] ?? $r['dept_name'] ?? '—', 0, 50)) ?></div>
                <div style="font-size:11px;color:var(--muted)"><span class="eng"><?= e($r['request_number']) ?></span> · <?= e($r['dept_name'] ?? '') ?> · مُصعَّد منذ <span class="eng"><?= date('Y-m-d H:i', strtotime($r['escalated_at'])) ?></span></div>
            </div>
            <span class="bdg" style="background:<?= $pr[1] ?>22;color:<?= $pr[1] ?>"><?= e($pr[0]) ?></span>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-rotate-left" style="color:#7c3aed"></i> معادة للصيانة ومتابَعة (<?= count($returnedList) ?>)</div>
        <?php if (!$returnedList): ?>
        <div style="text-align:center;color:var(--muted);font-weight:700;padding:18px">لا توجد بلاغات معادة حالياً.</div>
        <?php else: foreach ($returnedList as $r): $pr = $PRI_AR[$r['priority']]; $st = $STATUS_AR[$r['status']];
            // تمييز بصري: من أعاد البلاغ للصيانة هذه المرة؟
            $is_rejected = !empty($r['resolution_rejected_reason']);
            $card_border = $is_rejected ? '#d97706' : '#7c3aed';
            $card_bg     = $is_rejected ? '#fffbeb' : '#faf5ff';
            $card_bcolor = $is_rejected ? '#fde68a' : '#ddd6fe';
            $card_icon   = $is_rejected ? 'fa-x' : 'fa-rotate-left';
            $card_label  = $is_rejected ? 'رُفِض الحل من القسم — عاد للصيانة' : 'معاد من لجنة المتابعة';
            $card_text   = $is_rejected ? '#92400e' : '#3b0764';
        ?>
        <a href="<?= BASE_URL ?>/complaints/escalation.php?id=<?= $r['id'] ?>" style="display:flex;align-items:flex-start;gap:12px;background:<?= $card_bg ?>;border:1px solid <?= $card_bcolor ?>;border-right:4px solid <?= $card_border ?>;border-radius:12px;padding:13px 16px;margin-bottom:9px;text-decoration:none;transition:.2s">
            <i class="fa-solid <?= $card_icon ?>" style="color:<?= $card_border ?>;margin-top:2px;flex-shrink:0"></i>
            <div style="flex:1;min-width:0">
                <div style="font-size:11px;font-weight:900;color:<?= $card_border ?>;margin-bottom:4px">
                    <i class="fa-solid <?= $card_icon ?>" style="font-size:9px"></i> <?= $card_label ?>
                </div>
                <div style="font-size:13px;font-weight:800;color:<?= $card_text ?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr($r['asset_desc'] ?? $r['dept_name'] ?? '—', 0, 50)) ?></div>
                <div style="font-size:11px;color:#7c3aed;font-weight:700;margin-top:3px">
                    <span class="eng"><?= e($r['request_number']) ?></span> · <?= e($r['dept_name'] ?? '') ?>
                    <?php if ($is_rejected): ?>
                    · <span style="color:#d97706">رفض: <?= e(mb_substr($r['resolution_rejected_reason'], 0, 40)) ?></span>
                    <?php else: ?>
                    · أُعيدت <span class="eng"><?= date('Y-m-d H:i', strtotime($r['returned_by_committee_at'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <span class="bdg" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>"><?= e($st[0]) ?></span>
        </a>
        <?php endforeach; endif; ?>
    </div>

    <div class="bento">
        <div class="bento-h" style="justify-content:space-between">
            <span><i class="fa-solid fa-eye"></i> سجل البلاغات الشامل (آخر 150، مفتوحة ومُغلَقة)</span>
            <input type="text" id="monSearch" onkeyup="filterMon()" placeholder="بحث بالرقم أو القسم..." style="border:1px solid var(--border);border-radius:10px;padding:7px 12px;font-size:12px;font-family:'Tajawal';width:220px">
        </div>
        <div id="monList">
            <?php foreach ($monitorList as $r): $st = $STATUS_AR[$r['status']]; $pr = $PRI_AR[$r['priority']];
                $searchKey = mb_strtolower($r['request_number'] . ' ' . ($r['dept_name'] ?? ''));
            ?>
            <a href="<?= BASE_URL ?>/complaints/escalation.php?id=<?= $r['id'] ?>" class="mon-row" data-search="<?= e($searchKey) ?>" style="text-decoration:none;color:inherit">
                <span style="width:8px;height:8px;border-radius:50%;background:<?= $pr[1] ?>;flex-shrink:0"></span>
                <span class="eng" style="color:var(--muted);min-width:90px"><?= e($r['request_number']) ?></span>
                <span style="flex:1"><?= e($r['dept_name'] ?? '') ?> · <?= e(['medical'=>'طبية','it'=>'IT','general'=>'عامة'][$r['request_type']] ?? '') ?></span>
                <?php if ($r['sla_breach_detected_at']): ?><span class="breach-tag">تجاوز المهلة</span><?php endif; ?>
                <span class="bdg" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>"><?= e($st[0]) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

<?php endif; ?>

</div></main>
</div>
<script>
function toggleBox(id) { document.querySelectorAll('.act-box').forEach(b=>{if(b.id!==id)b.style.display='none';}); const b=document.getElementById(id); b.style.display=b.style.display==='block'?'none':'block'; }
function filterMon() {
    const q = document.getElementById('monSearch').value.toLowerCase();
    document.querySelectorAll('#monList .mon-row').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? 'flex' : 'none';
    });
}
</script>
</body>
</html>