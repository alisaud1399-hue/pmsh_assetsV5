<?php
/**
 * helpdesk/view.php — عرض التذكرة (Phase 1: بسيط)
 * Phase 7-8: full conversation + AJAX
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/helpdesk_helpers.php';

page_guard('helpdesk', 'view');
global $pdo;
$user_id = (int) current_user()['id'];

$ticket_id = (int)($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    flash('error', 'تذكرة غير موجودة');
    header('Location: ' . BASE_URL . '/helpdesk/index.php');
    exit;
}

// جلب التذكرة
$tk_stmt = $pdo->prepare("
    SELECT t.*,
           c.name_ar AS category_name_ar, c.name_en AS category_name_en, c.icon AS category_icon, c.color AS category_color, c.slug AS category_slug,
           cu.full_name AS creator_name, cu.username AS creator_username,
           au.full_name AS assignee_name, au.username AS assignee_username
    FROM helpdesk_tickets t
    JOIN helpdesk_categories c ON c.id = t.category_id
    LEFT JOIN users cu ON cu.id = t.created_by
    LEFT JOIN users au ON au.id = t.assigned_to
    WHERE t.id = ?
");
$tk_stmt->execute([$ticket_id]);
$tk = $tk_stmt->fetch(PDO::FETCH_ASSOC);
if (!$tk) {
    flash('error', 'تذكرة غير موجودة');
    header('Location: ' . BASE_URL . '/helpdesk/index.php');
    exit;
}

// الرؤية: هل المستخدم يستطيع رؤية هذه التذكرة؟
// Phase 10: Data Scope (نفس منطق index.php)
$can_see_all = function_exists('is_admin') && is_admin();
if (!$can_see_all) {
    $stmt = $pdo->prepare("
        SELECT 1 FROM user_roles ur
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE ur.user_id = ? AND r.name IN ('executive','admin') LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $can_see_all = (bool)$stmt->fetchColumn();
}
$can_manage = $can_see_all;
$can_respond = $can_see_all || helpdesk_can_handle_category($user_id, (int)$tk['category_id'], 'respond');
$is_creator = (int)$tk['created_by'] === $user_id;
$is_assignee = (int)$tk['assigned_to'] === $user_id;

// هل المستخدم يستطيع رؤية التذكرة؟ (بنفس scope الـ index)
$can_view = $can_see_all || $is_creator || $is_assignee;
if (!$can_view) {
    // تحقق من scope القسم/الأقسام الفرعية
    $scope = helpdesk_data_scope($pdo, $user_id);
    $check = $pdo->prepare("SELECT id FROM helpdesk_tickets t WHERE t.id = ? AND " . $scope['where']);
    $check->execute(array_merge([$ticket_id], $scope['params']));
    $can_view = (bool)$check->fetchColumn();
}

if (!$can_view) {
    page_guard_deny('helpdesk', 'view');
    exit;
}

// هل المستخدم مشترك؟
$sub_stmt = $pdo->prepare("SELECT 1 FROM helpdesk_subscribers WHERE ticket_id=? AND user_id=? AND unsubscribed_at IS NULL");
$sub_stmt->execute([$ticket_id, $user_id]);
$is_subscribed = (bool) $sub_stmt->fetchColumn();

// الرسائل
$msg_stmt = $pdo->prepare("
    SELECT m.*, u.full_name AS sender_name, u.username AS sender_username
    FROM helpdesk_messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.ticket_id = ?
    ORDER BY m.created_at ASC, m.id ASC
");
$msg_stmt->execute([$ticket_id]);
$messages = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);

// Phase 1: لا read tracking (helpdesk_reads غير موجود بعد)
// Phase 7: نضيف reads table + tracking

// السجل
$events_stmt = $pdo->prepare("
    SELECT e.*, u.full_name AS actor_name
    FROM helpdesk_events e
    JOIN users u ON u.id = e.user_id
    WHERE e.ticket_id = ?
    ORDER BY e.created_at DESC
    LIMIT 30
");
$events_stmt->execute([$ticket_id]);
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Phase 5: Smart Context Capture
$ctx = function_exists('helpdesk_get_context') ? helpdesk_get_context($pdo, $ticket_id) : null;

$STATUS_AR = [
    'new' => 'جديدة', 'in_review' => 'قيد المراجعة',
    'awaiting_user' => 'بانتظار ردك', 'closed' => 'مغلقة',
];
$PRIORITY_AR = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'critical' => 'حرجة'];
$PRIORITY_COLOR = ['low' => '#16a34a', 'medium' => '#0ea5e9', 'high' => '#f59e0b', 'critical' => '#dc2626'];
$EV_AR = [
    'created' => 'إنشاء', 'assigned' => 'تعيين', 'status_changed' => 'تغيير حالة',
    'reply' => 'رد', 'closed' => 'إغلاق', 'reopened' => 'إعادة فتح',
    'escalated' => 'تصعيد', 'replied_from_kb' => 'رد من KB',
    'priority_changed' => 'تغيير أولوية', 'subscribed' => 'اشتراك', 'unsubscribed' => 'إلغاء اشتراك',
    'sla_breached' => 'تجاوز SLA', 'attachment_uploaded' => 'رفع مرفق',
];

$page_title = helpdesk_t($tk['title'], '');
$active_nav = 'helpdesk';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= e($tk['title']) ?> — <?= e($tk['ticket_number']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --primary:#4338ca; --border:#e2e8f0; --bg:#f8fafc; --text-main:#0f172a; --text-2:#475569; --text-3:#94a3b8; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Tajawal', sans-serif; background:var(--bg); color:var(--text-main); }
        .container { max-width: 1200px; margin: 0 auto; padding: 16px 20px; }

        .back { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:#fff; color:var(--text-2); border:1px solid var(--border); border-radius:8px; text-decoration:none; font-weight:700; font-size:12.5px; margin-bottom:12px; }
        .back:hover { background:#f1f5f9; }

        .tk-head { background:#fff; border:1px solid var(--border); border-radius:14px; padding:20px 24px; margin-bottom:14px; }
        .tk-head .row1 { display:flex; align-items:flex-start; gap:14px; flex-wrap:wrap; }
        .tk-num { font-family:'Inter', monospace; font-size:11.5px; color:var(--text-3); font-weight:700; padding:3px 8px; background:#f1f5f9; border-radius:5px; }
        .tk-title { font-size:18px; font-weight:800; color:var(--text-main); line-height:1.4; flex:1; }
        .tk-cat { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:6px; font-size:11.5px; font-weight:800; }
        .tk-prio { display:inline-flex; align-items:center; gap:5px; font-weight:800; font-size:12px; }
        .tk-prio .dot { width:10px; height:10px; border-radius:50%; }
        .tk-status { display:inline-block; padding:3px 11px; border-radius:6px; font-weight:800; font-size:11.5px; color:#fff; }
        .tk-status.new { background:#1565C0; }
        .tk-status.in_review { background:#7c3aed; }
        .tk-status.awaiting_user { background:#f59e0b; }
        .tk-status.closed { background:#16a34a; }

        .tk-meta { display:flex; flex-wrap:wrap; gap:14px; margin-top:10px; font-size:12px; color:var(--text-2); padding-top:10px; border-top:1px solid var(--border); }
        .tk-meta .m { display:inline-flex; align-items:center; gap:5px; }
        .tk-meta .m i { color:var(--primary); }

        .body { display:grid; grid-template-columns: 1fr 280px; gap:14px; }
        @media (max-width: 1024px) { .body { grid-template-columns: 1fr; } }

        .conv { background:#fff; border:1px solid var(--border); border-radius:14px; padding:0; }
        .conv-h { padding:14px 18px; border-bottom:1px solid var(--border); font-weight:800; font-size:14px; display:flex; align-items:center; gap:8px; }
        .conv-h .ct { background:var(--primary); color:#fff; padding:1px 9px; border-radius:99px; font-size:11px; margin-inline-start:auto; }
        .msg-list { padding:14px 18px; max-height:700px; overflow-y:auto; }
        .msg { display:flex; gap:10px; margin-bottom:16px; padding-bottom:14px; border-bottom:1px dashed #f1f5f9; }
        .msg:last-child { border-bottom:0; margin-bottom:0; padding-bottom:0; }
        .msg-avatar { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#4338ca,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; flex-shrink:0; }
        .msg-name { font-weight:800; font-size:12.5px; }
        .msg-time { font-size:10.5px; color:var(--text-3); margin-inline-start:auto; }
        .msg-text { font-size:13px; line-height:1.6; color:var(--text-main); margin-top:4px; white-space:pre-wrap; word-wrap:break-word; }
        .msg.is-internal { background:#fffbeb; padding:10px 12px; border-radius:8px; border-inline-start:3px solid #f59e0b; }
        .msg.is-internal .msg-text { color:#92400e; }

        .side { display:flex; flex-direction:column; gap:12px; }
        .side-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:14px 16px; }
        .side-h { font-size:11.5px; color:var(--text-3); font-weight:800; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid var(--border); }

        .audit-item { font-size:11.5px; padding:5px 0; color:var(--text-2); border-bottom:1px dashed #f1f5f9; }
        .audit-item:last-child { border-bottom:0; }
        .audit-item .ai { color:var(--primary); }
        .audit-item .at { font-size:10px; color:var(--text-3); }

        .banner { padding:11px 16px; border-radius:8px; margin-bottom:12px; font-weight:700; font-size:12.5px; }
        .banner-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
        .banner-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

        .reply-box { background:#f8fafc; padding:14px 18px; border-top:1px solid var(--border); }
        .reply-box textarea { width:100%; min-height:80px; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:'Tajawal'; resize:vertical; box-sizing:border-box; }
        .reply-box textarea:focus { outline:none; border-color: var(--primary); }
        .reply-box .row { display:flex; align-items:center; gap:10px; margin-top:10px; }
        .reply-box .btn { padding:9px 18px; background:var(--primary); color:#fff; border:0; border-radius:8px; font-weight:800; font-size:12.5px; cursor:pointer; font-family:'Tajawal'; }
        .reply-box .btn:hover { background:#312e81; }
        .reply-box .btn:disabled { opacity:0.6; cursor:not-allowed; }

        .phase-note { background:linear-gradient(135deg,#fef3c7,#fed7aa); border:1px solid #fbbf24; border-inline-start:4px solid #d97706; padding:12px 16px; border-radius:10px; margin-bottom:12px; font-size:12.5px; color:#92400e; }
        .phase-note i { color:#d97706; margin-inline-end:6px; }

        /* ──── Phase 7: Action bar ──── */
        .action-bar { display:flex; flex-wrap:wrap; gap:8px; padding:10px 18px; border-bottom:1px solid var(--border); background:#f8fafc; }
        .act-pill { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border:1.5px solid #cbd5e1; border-radius:99px; background:#fff; font-size:12px; font-weight:700; color:var(--text-2); cursor:pointer; font-family:'Tajawal'; transition:all 0.15s; }
        .act-pill:hover { background:var(--primary-light); border-color:var(--primary); color:var(--primary); }
        .act-pill.active { background:var(--primary); border-color:var(--primary); color:#fff; }
        .act-pill.closed { background:#16a34a; border-color:#16a34a; color:#fff; }
        .act-pill.closed:hover { background:#15803d; }
        .act-pill i { font-size:11px; }
        .act-divider { width:1px; background:#cbd5e1; margin:0 2px; }

        /* ──── Modal صغير (assign) ──── */
        .mini-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center; padding:20px; }
        .mini-modal.open { display:flex; }
        .mini-modal-content { background:#fff; border-radius:14px; padding:18px 20px; max-width:420px; width:100%; box-shadow:0 20px 60px rgba(0,0,0,0.3); }
        .mini-modal h3 { font-size:14px; font-weight:800; margin:0 0 10px; display:flex; align-items:center; gap:6px; }
        .mini-modal select, .mini-modal input { width:100%; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:'Tajawal'; margin-bottom:12px; }
        .mini-modal .m-actions { display:flex; gap:8px; justify-content:flex-end; }
        .mini-modal .m-btn { padding:8px 16px; border:0; border-radius:8px; font-weight:800; font-size:12.5px; cursor:pointer; font-family:'Tajawal'; }
        .mini-modal .m-btn.pri { background:var(--primary); color:#fff; }
        .mini-modal .m-btn.sec { background:#f1f5f9; color:var(--text-2); }

        /* ──── Reply feedback ──── */
        .reply-toast { position:fixed; bottom:24px; inset-inline-end:24px; padding:10px 16px; background:var(--primary); color:#fff; border-radius:8px; font-size:13px; font-weight:700; box-shadow:0 4px 20px rgba(67,56,202,0.3); z-index:2000; opacity:0; transform:translateY(10px); transition:all 0.2s; pointer-events:none; }
        .reply-toast.show { opacity:1; transform:translateY(0); }
        .reply-toast.error { background:#dc2626; box-shadow:0 4px 20px rgba(220,38,38,0.3); }
        .reply-toast.success { background:#16a34a; }

        .live-dot { display:inline-block; width:6px; height:6px; background:#16a34a; border-radius:50%; margin-inline-end:4px; animation:pulse 1.5s infinite; }
        @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <a href="<?= BASE_URL ?>/helpdesk/index.php" class="back">
        <i class="fa-solid fa-arrow-right"></i> العودة للقائمة
    </a>

    <?php foreach ($flash_msgs as $fm): ?>
        <div class="banner banner-<?= e($fm['type']) ?>"><?= e($fm['message']) ?></div>
    <?php endforeach; ?>

    <div class="phase-note">
        <i class="fa-solid fa-circle-check" style="color:#16a34a"></i>
        <strong>Phase 7:</strong> الردود AJAX + تغيير الحالة + تعيين + اشتراك جاهزة. <span class="live-dot"></span> البث المباشر مفعّل كل 30 ثانية.
    </div>

    <div class="tk-head">
        <div class="row1">
            <span class="tk-num"><?= e($tk['ticket_number']) ?></span>
            <span class="tk-cat" style="background:<?= e($tk['category_color']) ?>22;color:<?= e($tk['category_color']) ?>">
                <i class="fa-solid <?= e($tk['category_icon']) ?>"></i>
                <?= e(helpdesk_t($tk['category_name_ar'] ?? $tk['category_name'], $tk['category_name_en'] ?? '')) ?>
            </span>
            <span class="tk-prio" style="color:<?= $PRIORITY_COLOR[$tk['priority']] ?>">
                <span class="dot" style="background:<?= $PRIORITY_COLOR[$tk['priority']] ?>"></span>
                <?= e($PRIORITY_AR[$tk['priority']]) ?>
            </span>
            <span class="tk-status <?= e($tk['status']) ?>"><?= e($STATUS_AR[$tk['status']]) ?></span>
        </div>
        <h1 class="tk-title" style="margin-top:10px"><?= e(helpdesk_t($tk['title'], '')) ?></h1>
        <div class="tk-meta">
            <span class="m"><i class="fa-solid fa-user"></i> <?= e($tk['creator_name'] ?? '—') ?></span>
            <?php if ($tk['assignee_name']): ?>
                <span class="m"><i class="fa-solid fa-user-shield"></i> المعالج: <?= e($tk['assignee_name']) ?></span>
            <?php endif; ?>
            <span class="m"><i class="fa-solid fa-calendar-day"></i> <?= e(date('Y-m-d H:i', strtotime($tk['created_at']))) ?></span>
            <span class="m"><i class="fa-solid fa-comments"></i> <?= (int)$tk['message_count'] ?> رسالة</span>
        </div>
    </div>

    <div class="body">
        <div class="conv">
            <?php
            $can_change_status = $can_manage && $tk['status'] !== 'closed';
            ?>
            <div class="action-bar">
                <?php if ($can_change_status): ?>
                    <button type="button" class="act-pill <?= $tk['status']==='in_review'?'active':'' ?>" data-act="status" data-status="in_review">
                        <i class="fa-solid fa-magnifying-glass"></i> قيد المراجعة
                    </button>
                    <button type="button" class="act-pill <?= $tk['status']==='awaiting_user'?'active':'' ?>" data-act="status" data-status="awaiting_user">
                        <i class="fa-solid fa-hourglass-half"></i> بانتظار المستخدم
                    </button>
                    <?php if ($can_manage): ?>
                        <button type="button" class="act-pill closed" data-act="status" data-status="closed">
                            <i class="fa-solid fa-check"></i> إغلاق
                        </button>
                    <?php endif; ?>
                    <span class="act-divider"></span>
                <?php endif; ?>

                <?php if ($can_manage): ?>
                    <button type="button" class="act-pill" data-act="assign">
                        <i class="fa-solid fa-user-plus"></i> تعيين
                    </button>
                <?php endif; ?>

                <button type="button" class="act-pill <?= $is_subscribed?'active':'' ?>" data-act="subscribe" data-state="<?= $is_subscribed?'1':'0' ?>">
                    <i class="fa-<?= $is_subscribed?'solid':'regular' ?> fa-bell"></i>
                    <?= $is_subscribed ? 'مشترك' : 'اشتراك' ?>
                </button>
            </div>

            <div class="conv-h">
                <i class="fa-regular fa-comments"></i> المحادثة
                <span class="ct" id="msgCount"><?= count($messages) ?></span>
            </div>
            <div class="msg-list">
                <?php if (!$messages): ?>
                    <p style="text-align:center;color:var(--text-3);padding:30px">لا توجد رسائل</p>
                <?php else: foreach ($messages as $m):
                    $initials = mb_substr($m['sender_name'] ?? '؟', 0, 1, 'UTF-8');
                    $is_internal = (bool)$m['is_internal_note'];
                ?>
                    <div class="msg <?= $is_internal?'is-internal':'' ?>">
                        <div class="msg-avatar"><?= e($initials) ?></div>
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;align-items:center;gap:8px">
                                <span class="msg-name"><?= e($m['sender_name']) ?></span>
                                <?php if ($is_internal): ?>
                                    <span style="background:#f59e0b;color:#fff;padding:1px 6px;border-radius:4px;font-size:9.5px;font-weight:800">ملاحظة داخلية</span>
                                <?php endif; ?>
                                <span class="msg-time" title="<?= e($m['created_at']) ?>"><?= e(date('Y-m-d H:i', strtotime($m['created_at']))) ?></span>
                            </div>
                            <div class="msg-text"><?= nl2br(e($m['message'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
            <div class="reply-box">
                <form id="replyForm">
                    <?= csrf_input() ?>
                    <textarea name="message" id="replyMessage" required minlength="2" maxlength="5000" placeholder="اكتب ردك... (Ctrl+Enter للإرسال)"></textarea>
                    <div class="row">
                        <?php if ($can_manage): ?>
                        <label style="font-size:12px;display:inline-flex;align-items:center;gap:5px">
                            <input type="checkbox" name="is_internal_note" id="replyInternal" value="1"> ملاحظة داخلية
                        </label>
                        <?php endif; ?>
                        <button type="submit" class="btn" id="replyBtn" style="margin-inline-start:auto" disabled>
                            <i class="fa-solid fa-paper-plane"></i> <span id="replyBtnTxt">إرسال</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="side">
            <div class="side-card">
                <div class="side-h">معلومات التذكرة</div>
                <div style="font-size:12px;line-height:1.7">
                    <div><strong>التصنيف:</strong> <?= e(helpdesk_t($tk['category_name_ar'] ?? '', $tk['category_name_en'] ?? '')) ?></div>
                    <div><strong>الأولوية:</strong> <?= e($PRIORITY_AR[$tk['priority']]) ?></div>
                    <div><strong>الحالة:</strong> <?= e($STATUS_AR[$tk['status']]) ?></div>
                    <div><strong>المُنشئ:</strong> <?= e($tk['creator_name'] ?? '—') ?></div>
                    <div><strong>المعالج:</strong> <?= e($tk['assignee_name'] ?? '— (لم يُعيَّن)') ?></div>
                    <div><strong>اللغة:</strong> <?= e(strtoupper($tk['language'])) ?></div>
                    <?php if ($tk['related_type']): ?>
                        <div><strong>مرتبط بـ:</strong> <?= e($tk['related_type']) ?>#<?= e($tk['related_id']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($ctx && !empty($ctx['url'])): ?>
            <div class="side-card">
                <div class="side-h"><i class="fa-solid fa-map-pin"></i> السياق وقت الإرسال</div>
                <div style="font-size:11.5px;line-height:1.6">
                    <?php if (!empty($ctx['url'])): ?>
                        <div style="margin-bottom:6px">
                            <i class="fa-solid fa-link" style="color:var(--primary);margin-inline-end:4px"></i>
                            <a href="<?= e($ctx['url']) ?>" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:none;word-break:break-all">
                                <?= e(mb_substr(parse_url($ctx['url'], PHP_URL_PATH) ?: $ctx['url'], -40, null, 'UTF-8')) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($ctx['page_title'])): ?>
                        <div style="color:var(--text-3);margin-bottom:6px">
                            <i class="fa-solid fa-file-lines" style="margin-inline-end:4px"></i>
                            <?= e(mb_substr($ctx['page_title'], 0, 50, 'UTF-8')) ?>
                        </div>
                    <?php endif; ?>
                    <?php
                    $perms = json_decode($ctx['permissions_snapshot_json'] ?? '{}', true) ?: [];
                    $perms_summary = [];
                    if (!empty($perms['username'])) $perms_summary[] = $perms['username'];
                    if (!empty($perms['is_admin'])) $perms_summary[] = 'admin';
                    elseif (!empty($perms['role'])) $perms_summary[] = $perms['role'];
                    if (!empty($perms['department_id'])) $perms_summary[] = 'قسم #' . $perms['department_id'];
                    ?>
                    <?php if ($perms_summary): ?>
                        <div style="color:var(--text-3);margin-bottom:4px">
                            <i class="fa-solid fa-user-shield" style="margin-inline-end:4px"></i>
                            <?= e(implode(' · ', $perms_summary)) ?>
                        </div>
                    <?php endif; ?>
                    <?php
                    $ua = $ctx['user_agent'] ?? '';
                    $browser = '';
                    if (preg_match('/(Chrome|Firefox|Safari|Edge|OPR)[\/\s]?([\d.]+)/', $ua, $m)) $browser = $m[1] . ' ' . ($m[2] ?? '');
                    elseif (preg_match('/MSIE\s?([\d.]+)/', $ua, $m)) $browser = 'IE ' . $m[1];
                    if ($browser): ?>
                        <div style="color:var(--text-3);margin-bottom:4px">
                            <i class="fa-solid fa-globe" style="margin-inline-end:4px"></i>
                            <?= e($browser) ?>
                        </div>
                    <?php endif; ?>
                    <div style="color:var(--text-3);font-size:10.5px;margin-top:6px">
                        <i class="fa-regular fa-clock" style="margin-inline-end:4px"></i>
                        <?= e(date('Y-m-d H:i:s', strtotime($ctx['created_at']))) ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($events): ?>
            <div class="side-card">
                <div class="side-h">السجل (<?= count($events) ?>)</div>
                <?php foreach ($events as $ev):
                    $ev_label = $EV_AR[$ev['event_type']] ?? $ev['event_type'];
                ?>
                    <div class="audit-item">
                        <i class="fa-solid fa-clock-rotate-left ai"></i>
                        <strong><?= e($ev_label) ?></strong> — <?= e($ev['actor_name']) ?>
                        <div class="at"><?= e(date('Y-m-d H:i', strtotime($ev['created_at']))) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>
</main>
</div>

<!-- Assign Modal -->
<div class="mini-modal" id="assignModal" role="dialog" aria-labelledby="assignTitle">
    <div class="mini-modal-content">
        <h3 id="assignTitle"><i class="fa-solid fa-user-plus" style="color:var(--primary)"></i> تعيين معالج</h3>
        <select id="assignSelect">
            <option value="0">— بدون تعيين —</option>
            <?php
            // جلب المستخدمين اللي عندهم صلاحية respond على هذا التصنيف
            $users_stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.username
                FROM users u
                WHERE u.is_active = 1
                ORDER BY u.full_name
                LIMIT 200
            ");
            $users_stmt->execute();
            $candidates = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
            $current_assignee = (int)($tk['assigned_to'] ?? 0);
            foreach ($candidates as $u):
            ?>
                <option value="<?= (int)$u['id'] ?>" <?= ((int)$u['id'] === $current_assignee)?'selected':'' ?>>
                    <?= e($u['full_name']) ?> (@<?= e($u['username']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
        <div class="m-actions">
            <button class="m-btn sec" data-close>إلغاء</button>
            <button class="m-btn pri" id="assignSave"><i class="fa-solid fa-check"></i> تعيين</button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="reply-toast" id="toast"></div>

<script>
(function() {
    const TICKET_ID = <?= (int)$ticket_id ?>;
    const API = '<?= BASE_URL ?>/api/helpdesk_action.php';

    // ─── Toast helper ───
    const toast = document.getElementById('toast');
    function showToast(msg, type='success', ms=2500) {
        toast.className = 'reply-toast show ' + type;
        toast.innerHTML = '<i class="fa-solid fa-' + (type==='error'?'circle-xmark':'check') + '"></i> ' + msg;
        clearTimeout(toast._t);
        toast._t = setTimeout(() => toast.classList.remove('show'), ms);
    }

    // ─── AJAX helper ───
    async function callAPI(action, body={}) {
        body.action = action;
        body.id = TICKET_ID;
        const fd = new FormData();
        for (const k in body) fd.append(k, body[k]);
        const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
        return r.json();
    }

    // ─── 1) Reply (AJAX) ───
    const form = document.getElementById('replyForm');
    const replyMsg = document.getElementById('replyMessage');
    const replyBtn = document.getElementById('replyBtn');
    const replyBtnTxt = document.getElementById('replyBtnTxt');
    const replyInternal = document.getElementById('replyInternal');

    replyMsg.addEventListener('input', () => {
        replyBtn.disabled = replyMsg.value.trim().length < 2;
    });
    replyMsg.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            e.preventDefault();
            if (!replyBtn.disabled) form.dispatchEvent(new Event('submit'));
        }
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const msg = replyMsg.value.trim();
        if (msg.length < 2) return;
        replyBtn.disabled = true;
        replyBtnTxt.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري...';
        const res = await callAPI('reply', {
            message: msg,
            is_internal_note: replyInternal?.checked ? '1' : ''
        });
        replyBtnTxt.textContent = 'إرسال';
        if (res.ok) {
            replyMsg.value = '';
            if (replyInternal) replyInternal.checked = false;
            showToast('تم إرسال الرد (' + (res.data.notified || 0) + ' إشعار)', 'success');
            // إضافة الرسالة فوراً للـ DOM
            const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            const initial = esc(msg.charAt(0));
            const isInt = replyInternal?.checked;
            const now = new Date().toISOString().slice(0,16).replace('T',' ');
            const html = `<div class="msg ${isInt?'is-internal':''}">
                <div class="msg-avatar">${initial}</div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;gap:8px">
                        <span class="msg-name">أنا الآن</span>
                        ${isInt?'<span style="background:#f59e0b;color:#fff;padding:1px 6px;border-radius:4px;font-size:9.5px;font-weight:800">ملاحظة داخلية</span>':''}
                        <span class="msg-time">${now}</span>
                    </div>
                    <div class="msg-text">${esc(msg).replace(/\n/g,'<br>')}</div>
                </div>
            </div>`;
            const list = document.querySelector('.msg-list');
            const empty = list.querySelector('p');
            if (empty) empty.remove();
            list.insertAdjacentHTML('beforeend', html);
            list.scrollTop = list.scrollHeight;
            // update count
            const ct = document.getElementById('msgCount');
            if (ct) ct.textContent = (parseInt(ct.textContent || 0, 10) + 1);
        } else {
            showToast(res.error || 'فشل الإرسال', 'error', 4000);
        }
        replyBtn.disabled = replyMsg.value.trim().length < 2;
    });

    // ─── 2) Status change ───
    document.querySelectorAll('[data-act="status"]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const newStatus = btn.dataset.status;
            const res = await callAPI('status', { status: newStatus });
            if (res.ok) {
                showToast('تم تغيير الحالة إلى: ' + (newStatus === 'closed' ? 'مغلقة' : newStatus === 'awaiting_user' ? 'بانتظار المستخدم' : 'قيد المراجعة'));
                setTimeout(() => location.reload(), 800);
            } else {
                showToast(res.error || 'فشل التغيير', 'error', 4000);
            }
        });
    });

    // ─── 3) Assign modal ───
    const assignModal = document.getElementById('assignModal');
    document.querySelectorAll('[data-act="assign"]').forEach(btn => {
        btn.addEventListener('click', () => assignModal.classList.add('open'));
    });
    document.querySelectorAll('[data-close]').forEach(btn => {
        btn.addEventListener('click', () => assignModal.classList.remove('open'));
    });
    assignModal.addEventListener('click', (e) => {
        if (e.target === assignModal) assignModal.classList.remove('open');
    });
    document.getElementById('assignSave').addEventListener('click', async () => {
        const sel = document.getElementById('assignSelect');
        const res = await callAPI('assign', { assignee_id: sel.value });
        if (res.ok) {
            showToast('تم التعيين: ' + (res.data.assignee_name || 'بدون معالج'));
            assignModal.classList.remove('open');
            setTimeout(() => location.reload(), 600);
        } else {
            showToast(res.error || 'فشل التعيين', 'error', 4000);
        }
    });

    // ─── 4) Subscribe toggle ───
    document.querySelectorAll('[data-act="subscribe"]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const isSub = btn.dataset.state === '1';
            const res = await callAPI('subscribe', { op: isSub ? 'off' : 'on', bell: '1' });
            if (res.ok) {
                btn.dataset.state = res.data.subscribed ? '1' : '0';
                btn.classList.toggle('active', res.data.subscribed);
                const icon = btn.querySelector('i');
                if (icon) {
                    icon.className = res.data.subscribed ? 'fa-solid fa-bell' : 'fa-regular fa-bell';
                }
                btn.innerHTML = (icon ? icon.outerHTML : '') + ' ' + (res.data.subscribed ? 'مشترك' : 'اشتراك');
                showToast(res.data.subscribed ? 'تم الاشتراك' : 'تم إلغاء الاشتراك');
            } else {
                showToast(res.error || 'فشل', 'error');
            }
        });
    });

    // ─── 5) Polling للرسائل الجديدة (30 ثانية) ───
    let lastMsgId = <?= !empty($messages) ? (int)end($messages)['id'] : 0 ?>;
    setInterval(async () => {
        try {
            const r = await fetch(API + '?action=list_messages&id=' + TICKET_ID + '&after_id=' + lastMsgId, { credentials: 'same-origin' });
            const data = await r.json();
            if (data.ok && data.data.messages.length) {
                const list = document.querySelector('.msg-list');
                const empty = list.querySelector('p');
                if (empty) empty.remove();
                const esc = (s) => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
                data.data.messages.forEach(m => {
                    const isInt = parseInt(m.is_internal_note) === 1;
                    const initial = esc((m.sender_name || '؟').charAt(0));
                    const html = `<div class="msg ${isInt?'is-internal':''}">
                        <div class="msg-avatar">${initial}</div>
                        <div style="flex:1;min-width:0">
                            <div style="display:flex;align-items:center;gap:8px">
                                <span class="msg-name">${esc(m.sender_name)}</span>
                                ${isInt?'<span style="background:#f59e0b;color:#fff;padding:1px 6px;border-radius:4px;font-size:9.5px;font-weight:800">ملاحظة داخلية</span>':''}
                                <span class="msg-time">${(m.created_at || '').slice(0,16).replace('T',' ')}</span>
                            </div>
                            <div class="msg-text">${esc(m.message).replace(/\n/g,'<br>')}</div>
                        </div>
                    </div>`;
                    list.insertAdjacentHTML('beforeend', html);
                });
                lastMsgId = data.data.last_id;
                const ct = document.getElementById('msgCount');
                if (ct) ct.textContent = (parseInt(ct.textContent || 0, 10) + data.data.messages.length);
                // show toast if new from others
                const others = data.data.messages.filter(m => parseInt(m.user_id) !== <?= (int)$user_id ?>);
                if (others.length) {
                    showToast(others.length + ' رسالة جديدة', 'success', 1500);
                    list.scrollTop = list.scrollHeight;
                }
            }
        } catch(e) { /* silent */ }
    }, 30000);
})();
</script>
</body>
</html>
