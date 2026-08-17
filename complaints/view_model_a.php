<?php
/**
 * complaints/view.php — عرض البلاغ (واجهة Bento Box) + دورة الحالة الكاملة
 * استعدت 4 إجراءات كانت غائبة (تعثّر/تصعيد/رفض البلاغ/إغلاق إداري)، وأعدت دقة
 * الصلاحيات (edit/approve/manage كل لمكانه)، وصححت التنبيهات لتصل لكل معتمَدي
 * القسم المُبلِّغ لا للمُرسِل وحده، وأضفت بطاقة "بلاغات سابقة على هذا الجهاز".
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_lib.php';
page_guard('complaints.index');

$u_data = current_user();
$uid = is_array($u_data) ? (int)($u_data['id'] ?? 0) : (int)$u_data;

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/complaints/index.php'); exit; }

$errors = [];

function logTl($pdo, $cid, $type, $label, $old, $new, $uid) {
    try {
        $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,?,?,?,?,?)")
            ->execute([$cid, $type, $label, $old, $new, $uid]);
    } catch (Exception $e) {}
}

function notify_sys($pdo, $target_uid, $type, $title, $body, $cid) {
    try {
        if (!$target_uid) return;
        $link = BASE_URL . '/complaints/view.php?id=' . $cid;
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$target_uid, $type, $title, $body, $link, 'complaint', $cid]);
    } catch (Exception $e) {}
}

/** يُشعِر المُبلِّغ نفسه + كل معتمَدي قسمه دفعة واحدة بلا تكرار (إصلاح اليوم) */
function notify_dept_and_requester($pdo, $c, $type, $title, $body) {
    $targets = users_with_permission($pdo, 'complaints.my', 'manage', (int) $c['dept_id']);
    $targets[] = $c['requested_by'];
    foreach (array_unique(array_filter($targets)) as $t) {
        notify_sys($pdo, $t, $type, $title, $body, $c['id']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verify_csrf()) {
        $errors[] = 'خطأ في الجلسة (CSRF). يرجى التحديث والمحاولة.';
    } else {
        $s = $pdo->prepare("SELECT * FROM complaints WHERE id=?");
        $s->execute([$id]);
        $c = $s->fetch(PDO::FETCH_ASSOC);

        if (!$c) {
            $errors[] = 'البلاغ غير موجود.';
        } else {
            $st = $c['status'] ?? 'open';
            $can_edit = can('complaints.index', 'edit');
            $can_approve = can('complaints.index', 'approve');
            $can_manage = can('complaints.index', 'manage');
            $allowed = $can_edit || $can_approve || $can_manage;

            if (!$allowed) {
                $errors[] = 'غير مصرح لك بهذا الإجراء.';
            } else {
                if ($action === 'acknowledge' && $st === 'open' && $can_edit) {
                    $pdo->prepare("UPDATE complaints SET status='acknowledged', acknowledged_by=?, acknowledged_at=NOW() WHERE id=?")->execute([$uid, $id]);
                    logTl($pdo, $id, 'acknowledged', 'استلام البلاغ وبدء المعاينة', $st, 'acknowledged', $uid);
                    notify_dept_and_requester($pdo, $c, 'info', 'تم استلام بلاغك', 'مهندس الصيانة قام باستلام البلاغ للتو.');
                    flash('success', 'تم استلام البلاغ بنجاح.');

                } elseif ($action === 'start' && in_array($st, ['acknowledged', 'stalled', 'escalated']) && $can_edit) {
                    $pdo->prepare("UPDATE complaints SET status='in_progress', started_by=COALESCE(started_by,?), started_at=COALESCE(started_at,NOW()) WHERE id=?")->execute([$uid, $id]);
                    if (!empty($c['asset_id'])) { $pdo->prepare("UPDATE assets SET status='under_maintenance' WHERE id=?")->execute([$c['asset_id']]); }
                    logTl($pdo, $id, 'started', 'بدء العمل الميداني على الجهاز', $st, 'in_progress', $uid);
                    notify_dept_and_requester($pdo, $c, 'info', 'جاري العمل الميداني', 'فريق الصيانة يعمل الآن على إصلاح العطل.');
                    flash('success', 'تم بدء العمل بنجاح.');

                } elseif ($action === 'stall' && $st === 'in_progress' && $can_edit) {
                    $reason = trim($_POST['reason'] ?? '');
                    if (!$reason) { $errors[] = 'يجب كتابة سبب التعثّر.'; }
                    else {
                        $pdo->prepare("UPDATE complaints SET status='stalled', stalled_by=?, stalled_at=NOW(), stall_reason=? WHERE id=?")->execute([$uid, $reason, $id]);
                        logTl($pdo, $id, 'stalled', 'تعثّر العمل: ' . $reason, $st, 'stalled', $uid);
                        notify_dept_and_requester($pdo, $c, 'warning', 'تعثّر العمل على بلاغك', $reason);
                        flash('warning', 'تم تسجيل تعثّر البلاغ.');
                    }

                } elseif ($action === 'escalate' && in_array($st, ['open', 'acknowledged', 'in_progress', 'stalled']) && $can_manage) {
                    $note = trim($_POST['note'] ?? '');
                    $pdo->prepare("UPDATE complaints SET status='escalated', escalated_by=?, escalated_at=NOW(), escalation_note=? WHERE id=?")->execute([$uid, $note, $id]);
                    logTl($pdo, $id, 'escalated', 'تصعيد يدوي' . ($note ? ': ' . $note : ''), $st, 'escalated', $uid);
                    notify_dept_and_requester($pdo, $c, 'warning', 'تم تصعيد بلاغك', $note ?: 'يتابعه الآن مستوى أعلى من فريق الصيانة.');
                    flash('warning', 'تم تصعيد البلاغ.');

                } elseif ($action === 'resolve' && in_array($st, ['in_progress', 'stalled', 'escalated']) && $can_approve) {
                    $notes = trim($_POST['notes'] ?? '');
                    if (!$notes) { $errors[] = 'يجب كتابة تقرير الإصلاح.'; }
                    else {
                        $pdo->prepare("UPDATE complaints SET status='resolved', resolved_by=?, resolved_at=NOW(), resolution_notes=? WHERE id=?")->execute([$uid, $notes, $id]);
                        if (!empty($c['asset_id'])) { $pdo->prepare("UPDATE assets SET status='active', last_maintenance_date=NOW() WHERE id=?")->execute([$c['asset_id']]); }
                        logTl($pdo, $id, 'resolved', 'تم إصلاح العطل فنياً، بانتظار تأكيد القسم', $st, 'resolved', $uid);
                        notify_dept_and_requester($pdo, $c, 'success', 'تم حل مشكلتك', 'يرجى الدخول لتأكيد الحل وتقييم الخدمة.');
                        flash('success', 'تم الإعلان عن الحل، بانتظار تأكيد القسم.');
                    }

                } elseif ($action === 'reject' && in_array($st, ['open', 'acknowledged']) && $can_manage) {
                    $note = trim($_POST['note'] ?? '');
                    if (!$note) { $errors[] = 'يجب كتابة سبب رفض البلاغ.'; }
                    else {
                        $pdo->prepare("UPDATE complaints SET status='rejected', rejected_by=?, rejected_at=NOW(), rejection_note=? WHERE id=?")->execute([$uid, $note, $id]);
                        logTl($pdo, $id, 'rejected', 'رفض فريق الصيانة البلاغ: ' . $note, $st, 'rejected', $uid);
                        notify_dept_and_requester($pdo, $c, 'danger', 'تم رفض بلاغك', $note);
                        flash('info', 'تم رفض البلاغ.');
                    }

                } elseif ($action === 'close' && !in_array($st, ['closed', 'cancelled', 'rejected']) && $can_manage) {
                    $pdo->prepare("UPDATE complaints SET status='closed', closed_by=?, closed_at=NOW() WHERE id=?")->execute([$uid, $id]);
                    logTl($pdo, $id, 'closed', 'إغلاق إداري مباشر', $st, 'closed', $uid);
                    flash('info', 'تم إغلاق البلاغ إدارياً.');

                } elseif ($action === 'add_attachment' && !in_array($st, ['closed', 'cancelled', 'rejected']) && ($can_edit || $can_approve || $can_manage)) {
                    $added = 0;
                    if (!empty($_FILES['new_attachments']['name'][0])) {
                        $updir = BASE_PATH . '/uploads/complaints/' . $id . '/';
                        if (!is_dir($updir)) mkdir($updir, 0755, true);
                        $att_stmt = $pdo->prepare("INSERT INTO complaint_attachments (complaint_id, file_name, file_path, file_size, file_type, uploaded_by) VALUES (?,?,?,?,?,?)");
                        foreach ($_FILES['new_attachments']['name'] as $i => $fname) {
                            if ($i >= 5 || !$fname || $_FILES['new_attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'])) continue;
                            $safe = 'eng_' . time() . '_' . $i . '_' . rand(100, 999) . '.' . $ext;
                            if (move_uploaded_file($_FILES['new_attachments']['tmp_name'][$i], $updir . $safe)) {
                                $att_stmt->execute([$id, $fname, 'complaints/' . $id . '/' . $safe, $_FILES['new_attachments']['size'][$i], $_FILES['new_attachments']['type'][$i], $uid]);
                                logTl($pdo, $id, 'attachment_added', 'أضاف مرفقاً: ' . $fname, $st, $st, $uid);
                                $added++;
                            }
                        }
                    }
                    if ($added) { flash('success', 'تم إضافة ' . $added . ' مرفق(ات) بنجاح.'); }
                    else { $errors[] = 'لم يُرفَع أي ملف صالح.'; }

                } else {
                    $errors[] = 'لا يمكن تنفيذ هذا الإجراء في الحالة الحالية، أو لا تملك الصلاحية اللازمة له.';
                }
                if (!$errors) { header('Location: ' . BASE_URL . '/complaints/view.php?id=' . $id); exit; }
            }
        }
    }
}

$s = $pdo->prepare("
    SELECT c.*,
           a.description AS asset_desc, a.tag_number, a.manufacturer_name, a.model_number, a.serial_number,
           a.date_placed_in_service, a.warranty_expiry, a.health_score, a.status AS asset_status,
           d.name AS dept_name,
           u.full_name AS requester_name, u.phone AS requester_phone
    FROM complaints c
    LEFT JOIN assets a ON a.id = c.asset_id
    LEFT JOIN departments d ON d.id = c.dept_id
    LEFT JOIN users u ON u.id = c.requested_by
    WHERE c.id = ?
");
$s->execute([$id]);
$c = $s->fetch(PDO::FETCH_ASSOC);

if (!$c) die('<h3 style="text-align:center;padding:50px;">البلاغ غير موجود.</h3>');

$can_edit = can('complaints.index', 'edit');
$can_approve = can('complaints.index', 'approve');
$can_manage = can('complaints.index', 'manage');
$is_owner = ($c['requested_by'] == $uid);

if (($can_edit || $can_approve || $can_manage) && !$is_owner && $c['status'] === 'open') {
    $already = $pdo->prepare("SELECT COUNT(*) FROM complaint_timeline WHERE complaint_id=? AND action_type='viewed' AND actor_id=?");
    $already->execute([$id, $uid]);
    if ($already->fetchColumn() == 0) {
        logTl($pdo, $id, 'viewed', 'تمت المعاينة الأولية من قبل المهندس', 'open', 'open', $uid);
    }
}

$t = $pdo->prepare("SELECT t.*, u.full_name AS actor_name FROM complaint_timeline t LEFT JOIN users u ON u.id=t.actor_id WHERE t.complaint_id=? ORDER BY t.created_at ASC");
$t->execute([$id]);
$timeline = $t->fetchAll(PDO::FETCH_ASSOC);

$at = $pdo->prepare("SELECT * FROM complaint_attachments WHERE complaint_id=?");
$at->execute([$id]);
$attachments = $at->fetchAll(PDO::FETCH_ASSOC);

// بلاغات سابقة على نفس الجهاز (جديد)
$prev_complaints = []; $prev_total = 0;
if (!empty($c['asset_id'])) {
    $pc = $pdo->prepare("SELECT id, request_number, status, created_at, description FROM complaints WHERE asset_id=? AND id != ? ORDER BY created_at DESC LIMIT 5");
    $pc->execute([$c['asset_id'], $id]);
    $prev_complaints = $pc->fetchAll(PDO::FETCH_ASSOC);
    $ptc = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE asset_id=? AND id != ?");
    $ptc->execute([$c['asset_id'], $id]);
    $prev_total = (int) $ptc->fetchColumn();
}

// حساب عمر الجهاز وحالة الضمان (لبطاقة ملف الجهاز)
$asset_age_txt = null;
if (!empty($c['date_placed_in_service'])) {
    $svc = new DateTime($c['date_placed_in_service']);
    $diff = $svc->diff(new DateTime());
    $asset_age_txt = $diff->y > 0 ? ($diff->y . ' سنة' . ($diff->m ? ' و' . $diff->m . ' شهر' : '')) : ($diff->m . ' شهر');
}
$warranty_info = null;
if (!empty($c['warranty_expiry'])) {
    $exp = new DateTime($c['warranty_expiry']);
    $now = new DateTime();
    $warranty_info = $exp > $now
        ? ['active' => true, 'label' => 'ساري حتى ' . $exp->format('Y-m-d'), 'days' => $now->diff($exp)->days]
        : ['active' => false, 'label' => 'منتهٍ منذ ' . $exp->format('Y-m-d'), 'days' => 0];
}

$STATUS_AR = [
    'open' => ['مفتوح', '#ef4444', 'fa-envelope-open-text'],
    'acknowledged' => ['مستلم', '#f59e0b', 'fa-handshake'],
    'in_progress' => ['جاري العمل', '#3b82f6', 'fa-person-digging'],
    'stalled' => ['متعثر', '#d97706', 'fa-pause'],
    'escalated' => ['مُصعَّد', '#dc2626', 'fa-arrow-up'],
    'resolved' => ['بانتظار التأكيد', '#10b981', 'fa-clipboard-check'],
    'closed' => ['مُغلَق نهائياً', '#0f766e', 'fa-lock-keyhole'],
    'cancelled' => ['مُلغى', '#94a3b8', 'fa-ban'],
    'rejected' => ['مرفوض', '#b91c1c', 'fa-circle-xmark'],
];
$st_info = $STATUS_AR[$c['status']] ?? ['مجهول', '#64748b', 'fa-circle-question'];
$csrf_token = csrf_token();

$page_title = 'تتبع البلاغ ' . $c['request_number'];
$active_nav = 'complaints.index';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> - نموذج أ</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#2563eb; }
body { background: var(--bg); font-family:'Tajawal',sans-serif; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width: 1500px; margin: 0 auto; padding: 22px; }

.act-bar { background:linear-gradient(135deg,#0f172a,#1e293b); border-radius:20px; padding:16px 22px; color:#fff; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; position:sticky; top:14px; z-index:50; box-shadow:0 14px 30px rgba(15,23,42,.2); margin-bottom:16px; }
.ab-id { font-size:17px; font-weight:900; display:flex; align-items:center; gap:10px; }
.status-pill { padding:6px 18px; border-radius:99px; font-weight:900; font-size:12.5px; background:var(--pc); box-shadow:0 0 16px var(--pc)66; }
.ab-btns { display:flex; gap:8px; flex-wrap:wrap; }
.act-btn { padding:10px 16px; border-radius:11px; border:none; font-size:12.5px; font-weight:900; color:#fff; cursor:pointer; display:flex; align-items:center; gap:7px; transition:.2s; }
.act-btn:hover { transform:translateY(-2px); }

.tabs { display:flex; gap:6px; background:#e2e8f0; padding:5px; border-radius:14px; margin-bottom:16px; }
.tab { flex:1; text-align:center; padding:11px; border-radius:10px; font-size:13px; font-weight:900; color:var(--muted); cursor:pointer; border:none; background:transparent; display:flex; align-items:center; justify-content:center; gap:7px; }
.tab.active { background:#fff; color:var(--primary); box-shadow:0 3px 8px rgba(0,0,0,.06); }

.panel { display:none; }
.panel.active { display:block; }
.bento { background:var(--card); border-radius:20px; box-shadow:0 4px 18px rgba(0,0,0,.04); border:1px solid var(--border); padding:24px; margin-bottom:16px; }
.problem-box { background:#f8fafc; border:1px solid var(--border); border-right:5px solid var(--primary); padding:20px; border-radius:14px; font-size:15px; font-weight:700; line-height:1.85; color:#334155; }
.note-box { margin-top:14px; padding:16px 18px; border-radius:14px; font-size:13px; font-weight:700; line-height:1.7; }
.act-box { display:none; margin-top:14px; border-top:1px dashed var(--border); padding-top:14px; max-width:480px; }
.act-box textarea { width:100%; border:2px solid var(--border); border-radius:12px; padding:12px; font-family:'Tajawal'; margin-bottom:10px; outline:none; font-size:13px; }

.profile-row { display:flex; align-items:center; gap:10px; padding:11px 0; border-bottom:1px dashed var(--border); font-size:13px; }
.profile-row:last-child { border-bottom:none; }
.profile-row i { color:#9333ea; width:18px; text-align:center; }
.profile-lbl { color:var(--muted); font-weight:800; min-width:100px; }
.profile-val { color:var(--text); font-weight:900; flex:1; }
.warranty-chip { padding:5px 12px; border-radius:99px; font-size:11.5px; font-weight:900; }
.prev-item { display:flex; align-items:center; gap:9px; background:#f8fafc; border:1px solid var(--border); border-radius:10px; padding:9px 12px; text-decoration:none; margin-bottom:7px; }
.prev-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.fda-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; }
.fda-kpi { background:#fff; border:1px solid #bae6fd; border-radius:14px; padding:18px; text-align:center; }
.fda-kpi .v { font-size:28px; font-weight:900; }
.att-chip { display:flex; align-items:center; gap:10px; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:10px 14px; text-decoration:none; margin-bottom:8px; }
.upload-area { border:2px dashed #cbd5e1; border-radius:14px; padding:18px; text-align:center; cursor:pointer; position:relative; margin-top:12px; }
.upload-area input { position:absolute; inset:0; opacity:0; cursor:pointer; }
.file-pre { display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800; padding:5px 10px; border-radius:8px; margin:4px 4px 0 0; }
.tl-item { display:flex; gap:14px; margin-bottom:20px; position:relative; }
.tl-item::after { content:''; position:absolute; left:18px; top:38px; bottom:-20px; width:2px; background:var(--border); }
.tl-item:last-child::after { display:none; }
.tl-icon { width:38px; height:38px; border-radius:50%; background:#f8fafc; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; color:var(--muted); z-index:2; flex-shrink:0; font-size:13px; }
.tl-item.tl-active .tl-icon { background:#eff6ff; border-color:var(--primary); color:var(--primary); }
.tl-info h4 { margin:0 0 4px; font-size:13px; font-weight:900; }
.tl-info p { margin:0; font-size:11px; font-weight:700; color:var(--muted); }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<?php foreach (get_flash() as $fm): $ffc=['success'=>'#10b981','warning'=>'#f59e0b','info'=>'#3b82f6','danger'=>'#ef4444'][$fm['type']]??'#3b82f6'; ?>
<div style="background:#fff;border:1px solid <?= $ffc ?>55;border-right:4px solid <?= $ffc ?>;padding:13px 18px;border-radius:12px;margin-bottom:16px;font-weight:800;font-size:13px"><?= e($fm['message']) ?></div>
<?php endforeach; ?>
<?php if ($errors): foreach ($errors as $er): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:13px 18px;border-radius:12px;margin-bottom:16px;font-weight:800;font-size:13px"><i class="fa-solid fa-circle-exclamation"></i> <?= e($er) ?></div>
<?php endforeach; endif; ?>

<div class="act-bar">
    <div class="ab-id eng"><i class="fa-solid fa-bolt" style="color:#fbbf24"></i> #<?= e($c['request_number']) ?> <span class="status-pill" style="--pc:<?= $st_info[1] ?>"><i class="fa-solid <?= $st_info[2] ?>"></i> <?= e($st_info[0]) ?></span></div>
    <?php if (!in_array($c['status'], ['closed','cancelled','rejected'])): ?>
    <form method="POST" id="actForm" class="ab-btns">
        <?= csrf_input() ?>
        <input type="hidden" name="action" id="actField" value="">
        <?php if ($c['status']==='open' && $can_edit): ?><button type="button" class="act-btn" style="background:#2563eb" onclick="doAct('acknowledge')"><i class="fa-solid fa-handshake"></i> استلام</button><?php endif; ?>
        <?php if (in_array($c['status'],['acknowledged','stalled','escalated']) && $can_edit): ?><button type="button" class="act-btn" style="background:#2563eb" onclick="doAct('start')"><i class="fa-solid fa-play"></i> بدء العمل</button><?php endif; ?>
        <?php if ($c['status']==='in_progress' && $can_edit): ?><button type="button" class="act-btn" style="background:#d97706" onclick="gotoTabAndBox('tab-diag','stallBox')"><i class="fa-solid fa-pause"></i> تعثّر</button><?php endif; ?>
        <?php if (in_array($c['status'],['open','acknowledged','in_progress','stalled']) && $can_manage): ?><button type="button" class="act-btn" style="background:#dc2626" onclick="gotoTabAndBox('tab-diag','escBox')"><i class="fa-solid fa-arrow-up"></i> تصعيد</button><?php endif; ?>
        <?php if (in_array($c['status'],['in_progress','stalled','escalated']) && $can_approve): ?><button type="button" class="act-btn" style="background:#16a34a" onclick="gotoTabAndBox('tab-diag','resBox')"><i class="fa-solid fa-circle-check"></i> حل البلاغ</button><?php endif; ?>
        <?php if (in_array($c['status'],['open','acknowledged']) && $can_manage): ?><button type="button" class="act-btn" style="background:#64748b" onclick="gotoTabAndBox('tab-diag','rejBox')"><i class="fa-solid fa-ban"></i> رفض</button><?php endif; ?>
        <?php if ($can_manage): ?><button type="button" class="act-btn" style="background:#334155" onclick="if(confirm('إغلاق إداري مباشر دون دورة تأكيد. متأكد؟')) doAct('close')"><i class="fa-solid fa-lock"></i> إغلاق</button><?php endif; ?>
    </form>
    <?php endif; ?>
</div>

<div class="tabs">
    <button type="button" class="tab active" onclick="showTab('tab-diag')"><i class="fa-solid fa-quote-right"></i> التشخيص</button>
    <button type="button" class="tab" onclick="showTab('tab-asset')"><i class="fa-solid fa-id-card"></i> ملف الجهاز</button>
    <button type="button" class="tab" onclick="showTab('tab-fda')"><i class="fa-solid fa-globe"></i> FDA</button>
    <button type="button" class="tab" onclick="showTab('tab-tl')"><i class="fa-solid fa-list-check"></i> السجل (<?= count($timeline) ?>)</button>
</div>

<div class="panel active" id="tab-diag">
    <div class="bento">
        <div class="problem-box"><?= nl2br(e($c['description'])) ?></div>
        <?php if(!empty($c['stall_reason']) && $c['status']!=='in_progress'): ?><div class="note-box" style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412"><strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-pause"></i> سبب التعثّر السابق:</strong><?= nl2br(e($c['stall_reason'])) ?></div><?php endif; ?>
        <?php if(!empty($c['escalation_note'])): ?><div class="note-box" style="background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d"><strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-arrow-up"></i> ملاحظة التصعيد:</strong><?= nl2br(e($c['escalation_note'])) ?></div><?php endif; ?>
        <?php if(!empty($c['resolution_notes'])): ?><div class="note-box" style="background:#f0fdf4;border:1px solid #a7f3d0;color:#065f46"><strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-wrench"></i> تقرير الإصلاح:</strong><?= nl2br(e($c['resolution_notes'])) ?></div><?php endif; ?>

        <?php if (!in_array($c['status'], ['closed','cancelled','rejected'])): ?>
        <div class="act-box" id="stallBox"><label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">سبب التعثّر <span style="color:#dc2626">*</span></label><textarea name="reason" rows="2" form="actForm"></textarea><button type="button" class="act-btn" style="background:#d97706" onclick="doAct('stall')">تأكيد</button></div>
        <div class="act-box" id="escBox"><label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">ملاحظة التصعيد</label><textarea name="note" rows="2" form="actForm"></textarea><button type="button" class="act-btn" style="background:#dc2626" onclick="doAct('escalate')">تأكيد</button></div>
        <div class="act-box" id="resBox"><label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">تقرير الإصلاح <span style="color:#dc2626">*</span></label><textarea name="notes" rows="3" form="actForm"></textarea><button type="button" class="act-btn" style="background:#16a34a" onclick="doAct('resolve')">حفظ واعتماد</button></div>
        <div class="act-box" id="rejBox"><label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">سبب الرفض <span style="color:#dc2626">*</span></label><textarea name="note" rows="2" form="actForm"></textarea><button type="button" class="act-btn" style="background:#64748b" onclick="doAct('reject')">تأكيد</button></div>
        <?php endif; ?>
    </div>

    <div class="bento">
        <h3 style="font-size:14px;font-weight:900;margin:0 0 14px;display:flex;align-items:center;gap:8px"><i class="fa-solid fa-paperclip" style="color:#d97706"></i> المرفقات (<?= count($attachments) ?>)</h3>
        <?php foreach ($attachments as $att): ?>
        <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank" class="att-chip"><i class="fa-solid fa-file" style="color:#d97706"></i> <span style="font-size:12.5px;font-weight:800;color:#78350f"><?= e($att['file_name']) ?></span></a>
        <?php endforeach; ?>
        <?php if (!in_array($c['status'], ['closed','cancelled','rejected'])): ?>
        <form method="POST" enctype="multipart/form-data" id="attForm">
            <?= csrf_input() ?><input type="hidden" name="action" value="add_attachment">
            <div class="upload-area" onclick="document.getElementById('newAtt').click()">
                <input type="file" id="newAtt" name="new_attachments[]" multiple onchange="showAttPreview(this.files)">
                <i class="fa-solid fa-cloud-arrow-up" style="color:#d97706"></i> <span style="font-size:11.5px;font-weight:800;color:#92400e">إضافة مرفق</span>
            </div>
            <div id="attPre"></div>
            <button type="submit" class="act-btn" style="background:#d97706;margin-top:10px;display:none" id="attSubmit">رفع</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="panel" id="tab-asset">
    <div class="bento">
        <?php if ($c['asset_id']): ?>
        <div class="profile-row"><i class="fa-solid fa-tag"></i><span class="profile-lbl">التاج نمبر</span><span class="profile-val eng"><?= e($c['tag_number'] ?: '—') ?></span></div>
        <div class="profile-row"><i class="fa-solid fa-industry"></i><span class="profile-lbl">الشركة/الموديل</span><span class="profile-val"><?= e(trim(($c['manufacturer_name']??'').' / '.($c['model_number']??''), ' /') ?: '—') ?></span></div>
        <div class="profile-row"><i class="fa-solid fa-calendar-check"></i><span class="profile-lbl">بداية الخدمة</span><span class="profile-val eng"><?= e($c['date_placed_in_service'] ?? '—') ?></span></div>
        <div class="profile-row"><i class="fa-solid fa-hourglass-half"></i><span class="profile-lbl">عمر الجهاز</span><span class="profile-val eng"><?= e($asset_age_txt ?? '—') ?></span></div>
        <div class="profile-row"><i class="fa-solid fa-shield-halved"></i><span class="profile-lbl">الضمان</span><span class="warranty-chip" style="background:<?= $warranty_info ? ($warranty_info['active']?'#dcfce7':'#fee2e2') : '#f1f5f9' ?>;color:<?= $warranty_info ? ($warranty_info['active']?'#15803d':'#b91c1c') : '#64748b' ?>"><?= $warranty_info ? e($warranty_info['label']) : 'بلا ضمان' ?></span></div>
        <?php endif; ?>
    </div>
    <div class="bento">
        <h3 style="font-size:14px;font-weight:900;margin:0 0 14px">سجل البلاغات على هذا الجهاز (<?= $prev_total ?>)</h3>
        <?php foreach ($prev_complaints as $pcm): $pst = $STATUS_AR[$pcm['status']] ?? ['—','#94a3b8','fa-circle']; ?>
        <a href="<?= BASE_URL ?>/complaints/view.php?id=<?= $pcm['id'] ?>" class="prev-item"><span class="prev-dot" style="background:<?= $pst[1] ?>"></span><span style="flex:1;font-size:12px;font-weight:700"><?= e(mb_substr($pcm['description'],0,60)) ?></span></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="panel" id="tab-fda">
    <div class="bento">
        <div id="fdaLoading" style="font-size:13px;font-weight:800;color:#0e7490"><i class="fa-solid fa-circle-notch fa-spin"></i> جاري الاستدعاء...</div>
        <div class="fda-grid" id="fdaWrap" style="display:none"></div>
    </div>
</div>

<div class="panel" id="tab-tl">
    <div class="bento">
        <?php foreach ($timeline as $idx => $tl): $isLast=$idx===count($timeline)-1; $icon=match($tl['action_type']){'attachment_added'=>'fa-paperclip','escalated'=>'fa-arrow-up','rejected','resolution_rejected'=>'fa-rotate-left','resolved'=>'fa-wrench','stalled'=>'fa-pause','closed'=>'fa-lock',default=>'fa-check'}; ?>
        <div class="tl-item <?= $isLast?'tl-active':'' ?>"><div class="tl-icon"><i class="fa-solid <?= $icon ?>"></i></div><div class="tl-info"><h4><?= e($tl['action_label']) ?></h4><p><?= e($tl['actor_name'] ?? 'النظام') ?> · <span class="eng"><?= date('d/m H:i', strtotime($tl['created_at'])) ?></span></p></div></div>
        <?php endforeach; ?>
    </div>
</div>

</div></main>
</div>
<script>
const BASE_URL = '<?= BASE_URL ?>';
function showTab(id) { document.querySelectorAll('.panel').forEach(p=>p.classList.remove('active')); document.querySelectorAll('.tab').forEach(t=>t.classList.remove('active')); document.getElementById(id).classList.add('active'); document.querySelector(`.tab[onclick="showTab('${id}')"]`).classList.add('active'); }
function gotoTabAndBox(tabId, boxId) { showTab(tabId); document.querySelectorAll('.act-box').forEach(b=>{if(b.id!==boxId)b.style.display='none';}); document.getElementById(boxId).style.display='block'; document.getElementById(boxId).scrollIntoView({behavior:'smooth'}); }
function doAct(action){ document.getElementById('actField').value=action; document.getElementById('actForm').submit(); }
function showAttPreview(files){ const pre=document.getElementById('attPre'); pre.innerHTML=''; Array.from(files).slice(0,5).forEach(f=>{const c=document.createElement('span');c.className='file-pre';c.innerHTML='<i class="fa-solid fa-file"></i> '+f.name;pre.appendChild(c);}); document.getElementById('attSubmit').style.display=files.length?'flex':'none'; }
document.addEventListener('DOMContentLoaded', async () => {
    const assetId = "<?= $c['asset_id'] ?? '' ?>"; if (!assetId || assetId==="0") return;
    const load=document.getElementById('fdaLoading'), wrap=document.getElementById('fdaWrap');
    try {
        let fd=new FormData(); fd.append('asset_id', assetId);
        let r=await fetch(BASE_URL+'/api/complaint_fault_suggestions.php',{method:'POST',body:fd}); let d=await r.json();
        load.style.display='none';
        if (d.fda_stats && d.fda_stats.total>0) {
            let html=`<div class="fda-kpi"><div class="v eng" style="color:#0e7490">${d.fda_stats.total.toLocaleString()}</div><div style="font-size:11px;font-weight:800;color:#0e7490">بلاغ مسجَّل</div></div>`;
            if (!d.fda_stats.is_fallback && d.fda_stats.malfunction!==null) {
                html+=`<div class="fda-kpi"><div class="v eng" style="color:#dc2626">${d.fda_stats.malfunction.toLocaleString()}</div><div style="font-size:11px;font-weight:800;color:#b91c1c">عطل مصنعي</div></div><div class="fda-kpi"><div class="v eng" style="color:#d97706">${d.fda_stats.injury_death.toLocaleString()}</div><div style="font-size:11px;font-weight:800;color:#b45309">تحذير خطورة</div></div>`;
            } else { html+=`<div class="fda-kpi" style="grid-column:span 2;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#0f766e">مسجَّل ضمن قواعد السلامة</div>`; }
            wrap.innerHTML=html; wrap.style.display='grid';
        } else { load.textContent='لا توجد بيانات FDA مطابقة لهذا الجهاز.'; }
    } catch(e) { load.textContent='تعذّر الاتصال بقاعدة FDA.'; }
});
</script>
</body>
</html>