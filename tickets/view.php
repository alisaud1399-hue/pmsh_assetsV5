<?php
/**
 * tickets/view.php — عرض التذكرة + المحادثة + الرد
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';

page_guard('tickets', 'view');

global $pdo;
$user_id = (int) current_user()['id'];

$ticket_id = (int)($_GET['id'] ?? 0);
if ($ticket_id <= 0) {
    set_flash('error', 'تذكرة غير موجودة');
    header('Location: ' . BASE_URL . '/tickets/index.php');
    exit;
}

// جلب التذكرة
$ticket = $pdo->prepare("
    SELECT t.*,
           cu.full_name AS creator_name, cu.username AS creator_username,
           au.full_name AS assignee_name, au.username AS assignee_username,
           du.name AS dept_name
    FROM tickets t
    LEFT JOIN users cu        ON cu.id = t.created_by
    LEFT JOIN users au        ON au.id = t.assigned_to
    LEFT JOIN departments du  ON du.id = t.department_id
    WHERE t.id = ?
");
$ticket->execute([$ticket_id]);
$tk = $ticket->fetch();
if (!$tk) {
    set_flash('error', 'تذكرة غير موجودة');
    header('Location: ' . BASE_URL . '/tickets/index.php');
    exit;
}

// التحقق من رؤية التذكرة (visibility)
$can_see_all_tickets = can('tickets', 'admin');
if (!$can_see_all_tickets && $tk['visibility'] === 'restricted'
    && (int)$tk['created_by'] !== $user_id && (int)$tk['assigned_to'] !== $user_id) {
    page_guard_deny('tickets', 'view');
    exit;
}

// هل أنا مشترك؟
$sub = $pdo->prepare("SELECT 1 FROM ticket_subscribers WHERE ticket_id=? AND user_id=? AND unsubscribed_at IS NULL");
$sub->execute([$ticket_id, $user_id]);
$is_subscribed = (bool) $sub->fetchColumn();

// هل قرأ آخر رسالة؟
$read = $pdo->prepare("SELECT last_read_message_id FROM ticket_reads WHERE ticket_id=? AND user_id=?");
$read->execute([$ticket_id, $user_id]);
$last_read = (int)($read->fetchColumn() ?: 0);

// تحديث قراءة المستخدم الحالي عند فتح الصفحة
$max_msg = (int)$pdo->prepare("SELECT MAX(id) FROM ticket_messages WHERE ticket_id = ?");
$max_msg->execute([$ticket_id]);
$max_id = (int)$max_msg->fetchColumn();
if ($max_id > $last_read) {
    ticket_mark_read($pdo, $ticket_id, $user_id, $max_id);
    $last_read = $max_id;
}

// جلب الرسائل
$msgs = $pdo->prepare("
    SELECT m.*, u.full_name AS sender_name, u.username AS sender_username
    FROM ticket_messages m
    JOIN users u ON u.id = m.user_id
    WHERE m.ticket_id = ?
    ORDER BY m.created_at ASC, m.id ASC
");
$msgs->execute([$ticket_id]);
$messages = $msgs->fetchAll();

// جلب المشتركين
$subs = $pdo->prepare("
    SELECT s.*, u.full_name, u.username
    FROM ticket_subscribers s
    JOIN users u ON u.id = s.user_id
    WHERE s.ticket_id = ? AND s.unsubscribed_at IS NULL
    ORDER BY s.added_at ASC
");
$subs->execute([$ticket_id]);
$subscribers = $subs->fetchAll();

// جلب سجل الأحداث
$events = $pdo->prepare("
    SELECT e.*, u.full_name AS actor_name
    FROM ticket_events e
    JOIN users u ON u.id = e.user_id
    WHERE e.ticket_id = ?
    ORDER BY e.created_at DESC
    LIMIT 30
");
$events->execute([$ticket_id]);
$event_list = $events->fetchAll();

// ── معالجة POST ─────────────────────────────────────────
$can_respond = can('tickets', 'respond');
$can_manage  = can('tickets', 'manage');
$can_admin   = can('tickets', 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reply' && $can_respond) {
        $message = trim($_POST['message'] ?? '');
        $is_internal = !empty($_POST['is_internal_note']) ? 1 : 0;
        $reply_to = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
        $r = ticket_add_message($pdo, $ticket_id, $user_id, $message, $is_internal, $reply_to);
        if ($r['ok']) {
            set_flash('success', "تم إرسال رسالتك (بث ل {$r['broadcast']} مشترك)");
        } else {
            set_flash('error', $r['error']);
        }
        header('Location: ' . BASE_URL . '/tickets/view.php?id=' . $ticket_id);
        exit;

    } elseif ($action === 'change_status' && $can_manage) {
        $new_status = $_POST['new_status'] ?? '';
        $note = $_POST['note'] ?? null;
        $r = ticket_change_status($pdo, $ticket_id, $new_status, $user_id, $note);
        if ($r['ok']) set_flash('success', 'تم تغيير الحالة');
        else set_flash('error', $r['error']);
        header('Location: ' . BASE_URL . '/tickets/view.php?id=' . $ticket_id);
        exit;

    } elseif ($action === 'assign' && $can_manage) {
        $new_assignee = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $r = ticket_assign($pdo, $ticket_id, $new_assignee, $user_id);
        if ($r['ok']) set_flash('success', 'تم تعيين المسؤول');
        else set_flash('error', $r['error']);
        header('Location: ' . BASE_URL . '/tickets/view.php?id=' . $ticket_id);
        exit;

    } elseif ($action === 'subscribe' && !$is_subscribed) {
        ticket_subscribe($pdo, $ticket_id, $user_id, 'manual', true);
        set_flash('success', 'تم الاشتراك في التذكرة');
        header('Location: ' . BASE_URL . '/tickets/view.php?id=' . $ticket_id);
        exit;

    } elseif ($action === 'unsubscribe' && $is_subscribed) {
        ticket_unsubscribe($pdo, $ticket_id, $user_id);
        set_flash('success', 'تم إلغاء الاشتراك');
        header('Location: ' . BASE_URL . '/tickets/view.php?id=' . $ticket_id);
        exit;
    }
}

// Lookup for assign dropdown
$users_list = $pdo->query("SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name LIMIT 200")->fetchAll();

$STATUS_AR = [
    'open'        => 'مفتوحة', 'assigned'    => 'معيَّنة', 'in_progress' => 'جاري العمل',
    'awaiting'    => 'بانتظار رد', 'resolved'    => 'تم الحل', 'closed'      => 'مغلقة', 'archived'    => 'مؤرشفة',
];
$PRIORITY_AR = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية', 'critical' => 'حرجة'];
$PRIORITY_COLOR = ['low' => '#16a34a', 'medium' => '#0ea5e9', 'high' => '#f59e0b', 'critical' => '#dc2626'];
$TYPE_AR = ['support' => 'دعم فني', 'maintenance' => 'صيانة', 'asset' => 'أصل', 'complaint' => 'بلاغ', 'general' => 'عام'];

$page_title = $tk['title'];
$active_nav = 'tickets';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --primary:#1565C0; --border:#e2e8f0; --bg:#f8fafc; --text-main:#0f172a; --text-2:#475569; --text-3:#94a3b8; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Tajawal', sans-serif; background:var(--bg); color:var(--text-main); }
        .container { max-width: 1200px; margin: 0 auto; padding: 16px 20px; }

        .back { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; background: #fff; color: var(--text-2); border: 1px solid var(--border); border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 12.5px; margin-bottom: 12px; }
        .back:hover { background: #f1f5f9; }

        /* Header */
        .tk-head {
            background: #fff; border-radius: 14px; border: 1px solid var(--border);
            padding: 18px 22px; margin-bottom: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.04);
        }
        .tk-head-row { display: flex; align-items: flex-start; gap: 14px; }
        .tk-head-num { font-family: 'Inter', monospace; font-size: 11.5px; color: var(--text-3); font-weight: 700; padding: 2px 8px; background: #f1f5f9; border-radius: 5px; }
        .tk-head-title { flex: 1; font-size: 18px; font-weight: 800; color: var(--text-main); line-height: 1.4; }
        .tk-head-meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 10px; font-size: 12px; color: var(--text-2); }
        .tk-head-meta span { display: inline-flex; align-items: center; gap: 5px; }
        .tk-head-meta i { color: var(--primary); }

        .badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-weight: 800; font-size: 11.5px; color: #fff; }
        .badge.status-open        { background: #1565C0; }
        .badge.status-assigned    { background: #7c3aed; }
        .badge.status-in_progress { background: #0ea5e9; }
        .badge.status-awaiting    { background: #f59e0b; }
        .badge.status-resolved    { background: #16a34a; }
        .badge.status-closed      { background: #64748b; }
        .badge.status-archived    { background: #94a3b8; }

        .prio { display: inline-flex; align-items: center; gap: 5px; font-weight: 800; font-size: 12px; }
        .prio .dot { width: 10px; height: 10px; border-radius: 50%; }

        .type-tag { display: inline-block; padding: 2px 9px; background: #eef2ff; color: #4338ca; border-radius: 6px; font-weight: 700; font-size: 11px; }

        /* Action bar */
        .tk-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border); }
        .btn-act { padding: 7px 14px; border: 1.5px solid #cbd5e1; background: #fff; color: var(--text-2); border-radius: 8px; font-weight: 700; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; font-family: 'Tajawal', sans-serif; }
        .btn-act:hover { background: #f1f5f9; }
        .btn-act.primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-act.danger  { color: #dc2626; border-color: #fecaca; background: #fef2f2; }
        .btn-act.warning { color: #d97706; border-color: #fed7aa; background: #fffbeb; }

        /* Body */
        .tk-body { display: grid; grid-template-columns: 1fr 280px; gap: 12px; }
        @media (max-width: 1024px) { .tk-body { grid-template-columns: 1fr; } }

        /* Conversation */
        .tk-conv { background: #fff; border-radius: 14px; border: 1px solid var(--border); padding: 0; }
        .conv-h { padding: 14px 18px; border-bottom: 1px solid var(--border); font-weight: 800; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .conv-h .count { background: var(--primary); color: #fff; padding: 1px 9px; border-radius: 12px; font-size: 11px; }
        .msg-list { padding: 14px 18px; max-height: 700px; overflow-y: auto; }
        .msg { display: flex; gap: 10px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px dashed #f1f5f9; }
        .msg:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
        .msg-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #1565C0, #4338ca); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; flex-shrink: 0; }
        .msg-body { flex: 1; min-width: 0; }
        .msg-head { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
        .msg-name { font-weight: 800; font-size: 12.5px; }
        .msg-time { font-size: 10.5px; color: var(--text-3); }
        .msg-text { font-size: 13px; line-height: 1.6; color: var(--text-main); white-space: pre-wrap; word-wrap: break-word; }
        .msg.is-internal { background: #fffbeb; padding: 10px 12px; border-radius: 8px; border-inline-start: 3px solid #f59e0b; }
        .msg.is-internal .msg-text { font-size: 12.5px; color: #92400e; }
        .msg-note-badge { background: #f59e0b; color: #fff; padding: 1px 6px; border-radius: 4px; font-size: 9.5px; font-weight: 800; margin-inline-start: 6px; }

        /* Reply form */
        .reply-box { background: #f8fafc; padding: 14px 18px; border-top: 1px solid var(--border); }
        .reply-box textarea { width: 100%; min-height: 80px; padding: 10px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 13px; font-family: 'Tajawal', sans-serif; resize: vertical; box-sizing: border-box; }
        .reply-box textarea:focus { outline: none; border-color: var(--primary); }
        .reply-box .row { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .reply-box label.chk { font-size: 12px; display: inline-flex; align-items: center; gap: 5px; cursor: pointer; }
        .reply-box .btn { padding: 8px 18px; background: var(--primary); color: #fff; border: 0; border-radius: 8px; font-weight: 800; font-size: 12.5px; cursor: pointer; font-family: 'Tajawal', sans-serif; }
        .reply-box .btn:hover { background: #003c8f; }

        /* Sidebar */
        .tk-side { display: flex; flex-direction: column; gap: 12px; }
        .side-card { background: #fff; border-radius: 12px; border: 1px solid var(--border); padding: 14px 16px; }
        .side-h { font-size: 12px; color: var(--text-3); font-weight: 800; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .side-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 12.5px; }
        .side-row .lbl { color: var(--text-3); font-size: 11px; font-weight: 700; min-width: 70px; }
        .sub-item { display: flex; align-items: center; gap: 7px; padding: 4px 0; font-size: 12px; }
        .sub-avatar { width: 22px; height: 22px; border-radius: 50%; background: linear-gradient(135deg, #1565C0, #4338ca); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 10px; flex-shrink: 0; }
        .sub-via { font-size: 9.5px; color: var(--text-3); }

        .flash-msg { padding: 11px 16px; border-radius: 8px; margin-bottom: 12px; font-weight: 700; font-size: 12.5px; }
        .flash-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .flash-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .locked-banner { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 12px 16px; border-radius: 10px; margin-bottom: 12px; font-size: 12.5px; }

        .audit-item { display: flex; gap: 8px; font-size: 11.5px; padding: 5px 0; color: var(--text-2); border-bottom: 1px dashed #f1f5f9; }
        .audit-item:last-child { border-bottom: 0; }
        .audit-ico { color: var(--primary); }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <a href="<?= BASE_URL ?>/tickets/index.php" class="back">
        <i class="fa-solid fa-arrow-right"></i> العودة للقائمة
    </a>

    <?php foreach ($flash_msgs as $fm): ?>
        <div class="flash-msg flash-<?= e($fm['type']) ?>"><?= e($fm['message']) ?></div>
    <?php endforeach; ?>

    <?php if (in_array($tk['status'], ['closed', 'archived'])): ?>
        <div class="locked-banner">
            <i class="fa-solid fa-lock"></i>
            هذه التذكرة <strong><?= e($STATUS_AR[$tk['status']]) ?></strong>
            <?= $tk['closed_at'] ? ' في ' . e($tk['closed_at']) : '' ?>
            — لا يمكن إضافة ردود جديدة<?= $tk['status']==='archived' ? ' (مؤرشفة)' : '' ?>.
        </div>
    <?php endif; ?>

    <div class="tk-head">
        <div class="tk-head-row">
            <div>
                <span class="tk-head-num"><?= e($tk['ticket_number']) ?></span>
                <span class="type-tag"><?= e($TYPE_AR[$tk['ticket_type']] ?? $tk['ticket_type']) ?></span>
                <span class="badge status-<?= e($tk['status']) ?>"><?= e($STATUS_AR[$tk['status']]) ?></span>
                <span class="prio" style="color:<?= $PRIORITY_COLOR[$tk['priority']] ?>;margin-inline-start:6px">
                    <span class="dot" style="background:<?= $PRIORITY_COLOR[$tk['priority']] ?>"></span>
                    <?= e($PRIORITY_AR[$tk['priority']]) ?>
                </span>
            </div>
        </div>
        <h1 class="tk-head-title" style="margin-top:8px"><?= e($tk['title']) ?></h1>
        <div class="tk-head-meta">
            <span><i class="fa-solid fa-user"></i> <?= e($tk['creator_name']) ?></span>
            <span><i class="fa-regular fa-clock"></i> <?= e(human_time_diff(strtotime($tk['created_at']))) ?></span>
            <?php if ($tk['assignee_name']): ?>
                <span><i class="fa-solid fa-user-check"></i> <?= e($tk['assignee_name']) ?></span>
            <?php endif; ?>
            <?php if ($tk['dept_name']): ?>
                <span><i class="fa-solid fa-building"></i> <?= e($tk['dept_name']) ?></span>
            <?php endif; ?>
            <?php if ($tk['due_date']): ?>
                <span style="color:<?= strtotime($tk['due_date']) < time() ? '#dc2626' : 'var(--text-2)' ?>">
                    <i class="fa-regular fa-calendar"></i> استحقاق: <?= e($tk['due_date']) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="tk-actions">
            <?php if ($can_manage && !in_array($tk['status'], ['closed', 'archived'])): ?>
                <form method="post" class="tk-ajax-form" data-endpoint="/api/tickets_change_status.php" style="display:inline-flex;gap:6px;align-items:center">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="ticket_id" value="<?= (int)$tk['id'] ?>">
                    <select name="new_status" style="padding:6px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:11.5px;font-family:'Tajawal'">
                        <?php foreach ($STATUS_AR as $k => $lbl): if ($k === $tk['status']) continue; ?>
                            <option value="<?= e($k) ?>">→ <?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-act">تغيير الحالة</button>
                </form>

                <form method="post" class="tk-ajax-form" data-endpoint="/api/tickets_assign.php" style="display:inline-flex;gap:6px;align-items:center">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="ticket_id" value="<?= (int)$tk['id'] ?>">
                    <select name="assigned_to" style="padding:6px 10px;border:1.5px solid #cbd5e1;border-radius:6px;font-size:11.5px;font-family:'Tajawal'">
                        <option value="">— بدون تعيين —</option>
                        <?php foreach ($users_list as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)$tk['assigned_to']===(int)$u['id']?'selected':'' ?>>
                                <?= e($u['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-act">تعيين</button>
                </form>
            <?php endif; ?>

            <?php if ($is_subscribed): ?>
                <form method="post" class="tk-ajax-form" data-endpoint="/api/tickets_subscribe.php" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="unsubscribe">
                    <input type="hidden" name="ticket_id" value="<?= (int)$tk['id'] ?>">
                    <button type="submit" class="btn-act danger">
                        <i class="fa-solid fa-bell-slash"></i> إلغاء الاشتراك
                    </button>
                </form>
            <?php elseif ($can_respond): ?>
                <form method="post" class="tk-ajax-form" data-endpoint="/api/tickets_subscribe.php" style="display:inline">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="subscribe">
                    <input type="hidden" name="ticket_id" value="<?= (int)$tk['id'] ?>">
                    <button type="submit" class="btn-act warning">
                        <i class="fa-solid fa-bell"></i> اشترك (broadcast)
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="tk-body">

        <!-- Conversation -->
        <div class="tk-conv">
            <div class="conv-h">
                <i class="fa-regular fa-comments"></i> المحادثة
                <span class="count"><?= count($messages) ?></span>
            </div>

            <div class="msg-list">
                <?php if (!$messages): ?>
                    <p style="text-align:center;color:var(--text-3);padding:30px">لا توجد رسائل بعد</p>
                <?php else: foreach ($messages as $m):
                    $initials = mb_substr($m['sender_name'] ?? '?', 0, 1, 'UTF-8');
                    $is_internal = (bool)$m['is_internal_note'];
                ?>
                    <div class="msg <?= $is_internal?'is-internal':'' ?>" id="msg-<?= (int)$m['id'] ?>">
                        <div class="msg-avatar"><?= e($initials) ?></div>
                        <div class="msg-body">
                            <div class="msg-head">
                                <span class="msg-name"><?= e($m['sender_name']) ?></span>
                                <?php if ($is_internal): ?>
                                    <span class="msg-note-badge">ملاحظة داخلية</span>
                                <?php endif; ?>
                                <span class="msg-time" title="<?= e($m['created_at']) ?>">
                                    <?= e(human_time_diff(strtotime($m['created_at']))) ?>
                                </span>
                            </div>
                            <div class="msg-text"><?= nl2br(e($m['message'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <?php if ($can_respond && !in_array($tk['status'], ['closed', 'archived'])): ?>
                <form class="reply-box" method="post" id="tkReplyForm">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="ticket_id" value="<?= (int)$tk['id'] ?>">
                    <textarea name="message" id="tkReplyMsg" required placeholder="اكتب ردك هنا... (سيتم بث إشعار لكل المشتركين تلقائياً)"></textarea>
                    <div class="row">
                        <label class="chk">
                            <input type="checkbox" name="is_internal_note" value="1" id="tkReplyInternal">
                            <span>ملاحظة داخلية (لا بث للمشتركين)</span>
                        </label>
                        <button type="submit" class="btn" id="tkReplyBtn" style="margin-inline-start:auto">
                            <i class="fa-solid fa-paper-plane"></i> إرسال
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="tk-side">

            <div class="side-card">
                <div class="side-h">المشتركون (<?= count($subscribers) ?>)</div>
                <?php if (!$subscribers): ?>
                    <p style="font-size:12px;color:var(--text-3)">لا يوجد مشتركون</p>
                <?php else: foreach ($subscribers as $s):
                    $ini = mb_substr($s['full_name'] ?? '?', 0, 1, 'UTF-8');
                    $via = $s['added_via'];
                    $via_ar = ['auto_creator'=>'منشئ','auto_assignee'=>'تعيين','auto_replier'=>'رد','manual'=>'يدوي'][$via] ?? $via;
                ?>
                    <div class="sub-item">
                        <div class="sub-avatar"><?= e($ini) ?></div>
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:700;font-size:11.5px"><?= e($s['full_name']) ?></div>
                            <div class="sub-via"><?= e($via_ar) ?> • <?= e(human_time_diff(strtotime($s['added_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <?php if ($event_list): ?>
            <div class="side-card">
                <div class="side-h">سجل التغييرات</div>
                <?php foreach ($event_list as $ev):
                    $ev_ar = ['created'=>'إنشاء','assigned'=>'تعيين','status_changed'=>'تغيير حالة','reply'=>'رد','closed'=>'إغلاق','reopened'=>'إعادة فتح','resolved'=>'حل','subscribed'=>'اشتراك','unsubscribed'=>'إلغاء اشتراك'][$ev['event_type']] ?? $ev['event_type'];
                ?>
                    <div class="audit-item">
                        <i class="fa-solid fa-clock-rotate-left audit-ico"></i>
                        <div style="flex:1;min-width:0">
                            <div><strong><?= e($ev_ar) ?></strong> — <?= e($ev['actor_name']) ?></div>
                            <div style="font-size:10px;color:var(--text-3)"><?= e(human_time_diff(strtotime($ev['created_at']))) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>

    </div>

</div>
</main>
</div>

<script>
(function() {
    'use strict';
    const ticketId = <?= (int)$tk['id'] ?>;
    const csrfToken = '<?= e($_SESSION['csrf_token'] ?? '') ?>';
    const TICKET_BASE = <?= json_encode(BASE_URL . '/api/') ?>;
    const currentUserId = <?= (int)$user_id ?>;
    let lastMessageId = <?= (int)($messages ? end($messages)['id'] : 0) ?>;
    let lastEventId = 0; // events polled from server

    function escapeHtml(s) {
        if (s == null) return '';
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function showToast(msg, type) {
        const t = document.createElement('div');
        t.className = 'tk-toast tk-toast-' + (type || 'info');
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(() => t.classList.add('show'));
        setTimeout(() => {
            t.classList.remove('show');
            setTimeout(() => t.remove(), 350);
        }, 3500);
    }

    // ── AJAX submit for header forms (status / assign / subscribe) ──
    document.querySelectorAll('form.tk-ajax-form').forEach(form => {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const endpoint = form.getAttribute('data-endpoint');
            if (!endpoint) return;
            const btn = form.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }
            try {
                const fd = new FormData(form);
                const r = await fetch(TICKET_BASE + endpoint.replace(/^\/api\//, ''), {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                });
                const data = await r.json();
                if (!data.ok) {
                    showToast(data.error || 'فشل العملية', 'error');
                } else {
                    let label = '';
                    if (endpoint.includes('change_status')) {
                        label = 'تم التغيير إلى: ' + (data.new_status_ar || data.new_status);
                    } else if (endpoint.includes('assign')) {
                        label = data.assigned_name
                            ? 'تم التعيين إلى: ' + data.assigned_name
                            : 'تم إلغاء التعيين';
                    } else if (endpoint.includes('subscribe')) {
                        label = 'تم تحديث الاشتراك';
                    }
                    showToast(label, 'success');
                    // Reload to reflect state changes
                    setTimeout(() => location.reload(), 700);
                }
            } catch (err) {
                showToast('خطأ: ' + err.message, 'error');
            } finally {
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
            }
        });
    });

    // ── AJAX reply ──
    const replyForm = document.getElementById('tkReplyForm');
    if (replyForm) {
        replyForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const msgEl = document.getElementById('tkReplyMsg');
            const msg = (msgEl.value || '').trim();
            if (!msg) return;
            const isInternal = document.getElementById('tkReplyInternal').checked ? 1 : 0;
            const btn = document.getElementById('tkReplyBtn');
            btn.disabled = true; btn.style.opacity = '0.6';
            try {
                const fd = new FormData();
                fd.append('ticket_id', ticketId);
                fd.append('message', msg);
                fd.append('is_internal_note', isInternal);
                const r = await fetch(TICKET_BASE + 'tickets_send_message.php', {
                    method: 'POST', body: fd, credentials: 'same-origin'
                });
                const data = await r.json();
                if (!data.ok) {
                    showToast(data.error || 'فشل الإرسال', 'error');
                } else {
                    // Append new message to thread
                    const list = document.querySelector('.msg-list');
                    if (list) {
                        const m = data.message;
                        const div = document.createElement('div');
                        div.className = m.html_class;
                        div.id = 'msg-' + m.id;
                        div.innerHTML = `
                            <div class="msg-avatar">${escapeHtml(m.sender_initial)}</div>
                            <div class="msg-body">
                                <div class="msg-head">
                                    <span class="msg-name">${escapeHtml(m.sender_name)}</span>
                                    ${m.is_internal ? '<span class="msg-note-badge">ملاحظة داخلية</span>' : ''}
                                    <span class="msg-time" title="${escapeHtml(m.created_at)}">الآن</span>
                                </div>
                                <div class="msg-text">${escapeHtml(m.message).replace(/\n/g, '<br>')}</div>
                            </div>`;
                        list.appendChild(div);
                        div.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    lastMessageId = m.id;
                    msgEl.value = '';
                    document.getElementById('tkReplyInternal').checked = false;
                    showToast(`تم الإرسال (بث لـ ${data.broadcast_count} مشترك)`, 'success');
                    // Update count
                    const countEl = document.querySelector('.conv-h .count');
                    if (countEl) {
                        const n = parseInt(countEl.textContent || '0', 10) + 1;
                        countEl.textContent = n;
                    }
                }
            } catch (err) {
                showToast('خطأ: ' + err.message, 'error');
            } finally {
                btn.disabled = false; btn.style.opacity = '1';
                msgEl.focus();
            }
        });
    }

    // ── Polling for new messages (every 15s) ──
    let polling = false;
    async function poll() {
        if (polling) return;
        polling = true;
        try {
            const r = await fetch(TICKET_BASE + 'tickets_poll.php?ticket_id=' + ticketId
                + '&last_message_id=' + lastMessageId
                + '&last_event_id=' + lastEventId,
                { credentials: 'same-origin' });
            const data = await r.json();
            if (!data.ok) return;

            if (data.new_messages && data.new_messages.length > 0) {
                const list = document.querySelector('.msg-list');
                if (list) {
                    data.new_messages.forEach(m => {
                        if (document.getElementById('msg-' + m.id)) return; // already there
                        const isInt = m.is_internal_note == 1;
                        const div = document.createElement('div');
                        div.className = isInt ? 'msg is-internal' : 'msg';
                        div.id = 'msg-' + m.id;
                        const init = (m.sender_name || '?').charAt(0);
                        div.innerHTML = `
                            <div class="msg-avatar">${escapeHtml(init)}</div>
                            <div class="msg-body">
                                <div class="msg-head">
                                    <span class="msg-name">${escapeHtml(m.sender_name)}</span>
                                    ${isInt ? '<span class="msg-note-badge">ملاحظة داخلية</span>' : ''}
                                    <span class="msg-time" title="${escapeHtml(m.created_at)}">الآن</span>
                                </div>
                                <div class="msg-text">${escapeHtml(m.message).replace(/\n/g, '<br>')}</div>
                            </div>`;
                        list.appendChild(div);
                        lastMessageId = Math.max(lastMessageId, m.id);
                    });
                }
            }
            if (data.new_events && data.new_events.length > 0) {
                showToast(`+${data.new_events.length} تحديث جديد على التذكرة`, 'info');
            }
        } catch (e) { /* silent */ }
        finally { polling = false; }
    }
    // Start polling every 15s
    setInterval(poll, 15000);
})();
</script>

<style>
.tk-toast {
    position: fixed; bottom: 24px; inset-inline-end: 24px;
    background: #1e293b; color: #fff; padding: 12px 18px; border-radius: 10px;
    font-family: 'Tajawal', sans-serif; font-weight: 700; font-size: 13px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2); z-index: 9999;
    opacity: 0; transform: translateY(20px); transition: all 0.3s ease;
    max-width: 360px;
}
.tk-toast.show { opacity: 1; transform: translateY(0); }
.tk-toast-success { background: #16a34a; }
.tk-toast-error   { background: #dc2626; }
.tk-toast-info    { background: #1565C0; }
</style>

</body>
</html>
