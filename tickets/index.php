<?php
/**
 * tickets/index.php — قائمة التذاكر
 * Pattern: 3 tabs (مفتوحة / مغلوقة / أرشيف) + filter bar + table
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('tickets', 'view');

$can_create = can('tickets', 'create');
$can_manage = can('tickets', 'manage');
$can_admin  = can('tickets', 'admin');

global $pdo;
$my_id = (int) current_user()['id'];

// ── Tab: open | closed | archived ─────────────────────────
$tab = $_GET['tab'] ?? 'open';
if (!in_array($tab, ['open', 'closed', 'archived'], true)) $tab = 'open';

// خريطة التبويبات → قيم status في DB
$STATUS_GROUPS = [
    'open'     => ['open', 'assigned', 'in_progress', 'awaiting', 'resolved'],
    'closed'   => ['closed'],
    'archived' => ['archived'],
];

// ── Filters ──────────────────────────────────────────────
$q       = trim($_GET['q'] ?? '');
$status  = $_GET['status'] ?? '';
$type    = $_GET['type'] ?? '';
$pri     = $_GET['priority'] ?? '';
$mine    = isset($_GET['mine']) ? 1 : 0;

$where = [];
$params = [];

// Filter by tab (status group)
$placeholders = implode(',', array_fill(0, count($STATUS_GROUPS[$tab]), '?'));
$where[] = "t.status IN ($placeholders)";
foreach ($STATUS_GROUPS[$tab] as $s) $params[] = $s;

// Search
if ($q !== '') {
    $where[] = "(t.title LIKE ? OR t.ticket_number LIKE ? OR t.description LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

// Status (only if valid and in current tab group)
if ($status && in_array($status, $STATUS_GROUPS[$tab], true)) {
    $where[] = "t.status = ?";
    $params[] = $status;
}
if ($type && in_array($type, ['support','maintenance','asset','complaint','general'], true)) {
    $where[] = "t.ticket_type = ?";
    $params[] = $type;
}
if ($pri && in_array($pri, ['low','medium','high','critical'], true)) {
    $where[] = "t.priority = ?";
    $params[] = $pri;
}

// Mine filter: فقط التذاكر اللي أنا مشترك فيها أو مُنشئها أو مُعيَّن لي
if ($mine) {
    $where[] = "(t.created_by = ? OR t.assigned_to = ? OR EXISTS (SELECT 1 FROM ticket_subscribers ts WHERE ts.ticket_id = t.id AND ts.user_id = ? AND ts.unsubscribed_at IS NULL))";
    array_push($params, $my_id, $my_id, $my_id);
}

// Visibility: إذا visibility=restricted، فقط المنشئ والمعيَّن
$can_see_all_tickets = $can_admin;
if (!$can_see_all_tickets) {
    $where[] = "(t.visibility = 'public'
                 OR (t.visibility = 'internal' AND EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.department_id = (SELECT created_dept FROM users WHERE id = t.created_by)))
                 OR (t.visibility = 'restricted' AND (t.created_by = ? OR t.assigned_to = ?)))";
    array_push($params, $my_id, $my_id, $my_id);
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Counts per tab (KPIs) ─────────────────────────────────
$counts = ['open' => 0, 'closed' => 0, 'archived' => 0, 'unread_mine' => 0];
foreach ($STATUS_GROUPS as $key => $statuses) {
    $csql = "SELECT COUNT(*) FROM tickets t WHERE t.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
    $cstmt = $pdo->prepare($csql);
    $cstmt->execute($statuses);
    $counts[$key] = (int) $cstmt->fetchColumn();
}

// Unread for current user (in open tab)
$usql = "SELECT COUNT(*) FROM ticket_reads r
         JOIN tickets t ON t.id = r.ticket_id
         WHERE r.user_id = ? AND t.message_count > 0
           AND (r.last_read_message_id IS NULL OR r.last_read_message_id < t.message_count)
           AND t.status IN ($placeholders)";
$ustmt = $pdo->prepare($usql);
$ustmt->execute(array_merge([$my_id], $STATUS_GROUPS['open']));
$counts['unread_mine'] = (int) $ustmt->fetchColumn();

// ── Main query ───────────────────────────────────────────
$sql = "
    SELECT
        t.*,
        cu.full_name     AS creator_name,
        au.full_name     AS assignee_name,
        du.name          AS dept_name,
        COALESCE(sr.cnt, 0) AS subscriber_cnt,
        COALESCE(mc.cnt, 0) AS last_msg_id
    FROM tickets t
    LEFT JOIN users cu        ON cu.id = t.created_by
    LEFT JOIN users au        ON au.id = t.assigned_to
    LEFT JOIN departments du  ON du.id = t.department_id
    LEFT JOIN (
        SELECT ticket_id, COUNT(*) AS cnt
        FROM ticket_subscribers
        WHERE unsubscribed_at IS NULL
        GROUP BY ticket_id
    ) sr ON sr.ticket_id = t.id
    LEFT JOIN (
        SELECT ticket_id, MAX(id) AS cnt
        FROM ticket_messages
        GROUP BY ticket_id
    ) mc ON mc.ticket_id = t.id
    $where_sql
    ORDER BY
        FIELD(t.priority,'critical','high','medium','low'),
        t.updated_at DESC
    LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// ── Lookup maps (display) ───────────────────────────────
$STATUS_AR = [
    'open'        => 'مفتوحة',
    'assigned'    => 'معيَّنة',
    'in_progress' => 'جاري العمل',
    'awaiting'    => 'بانتظار رد',
    'resolved'    => 'تم الحل',
    'closed'      => 'مغلقة',
    'archived'    => 'مؤرشفة',
];
$PRIORITY_AR = [
    'low'      => 'منخفضة',
    'medium'   => 'متوسطة',
    'high'     => 'عالية',
    'critical' => 'حرجة',
];
$PRIORITY_COLOR = [
    'low'      => '#16a34a',
    'medium'   => '#0ea5e9',
    'high'     => '#f59e0b',
    'critical' => '#dc2626',
];
$TYPE_AR = [
    'support'     => 'دعم فني',
    'maintenance' => 'صيانة',
    'asset'       => 'أصل طبي',
    'complaint'   => 'بلاغ',
    'general'     => 'عام',
];
$TYPE_ICON = [
    'support'     => 'fa-headset',
    'maintenance' => 'fa-screwdriver-wrench',
    'asset'       => 'fa-boxes-stacked',
    'complaint'   => 'fa-bell',
    'general'     => 'fa-circle-info',
];

$page_title = 'نظام التذاكر — Support Tickets';
$active_nav = 'tickets';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --primary:#1565C0; --primary-light:#e3f2fd; --border:#e2e8f0; --bg:#f8fafc; --text-main:#0f172a; --text-2:#475569; --text-3:#94a3b8; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Tajawal', sans-serif; background:var(--bg); color:var(--text-main); }
        .container { max-width: 1400px; margin: 0 auto; padding: 18px 20px; }

        /* Hero */
        .tk-hero {
            background: linear-gradient(135deg, #1e293b 0%, #312e81 50%, #4338ca 100%);
            color: #fff; border-radius: 18px; padding: 22px 28px; margin-bottom: 18px;
            box-shadow: 0 10px 30px rgba(30,41,59,0.25);
            display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
        }
        .tk-hero-ico {
            width: 60px; height: 60px; background: rgba(255,255,255,0.15);
            border-radius: 14px; display: flex; align-items: center; justify-content: center;
            font-size: 26px; flex-shrink: 0;
        }
        .tk-hero-text { flex: 1; min-width: 250px; }
        .tk-hero h1 { margin: 0 0 4px; font-size: 22px; font-weight: 800; }
        .tk-hero p  { margin: 0; opacity: 0.85; font-size: 13px; }
        .tk-hero .btn {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff; color: #4338ca; padding: 10px 18px; border-radius: 10px;
            text-decoration: none; font-weight: 800; font-size: 13px;
            transition: transform 0.15s;
        }
        .tk-hero .btn:hover { transform: translateY(-2px); }
        .tk-hero .btn.secondary { background: rgba(255,255,255,0.15); color: #fff; border: 1px solid rgba(255,255,255,0.3); }

        /* KPIs */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px; }
        .kpi { background: #fff; border-radius: 12px; border: 1px solid var(--border); padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
        .kpi-ico { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .kpi-val { font-size: 22px; font-weight: 900; color: var(--text-main); line-height: 1.1; }
        .kpi-lbl { font-size: 11.5px; color: var(--text-3); font-weight: 700; }
        .kpi.open     .kpi-ico { background: #dbeafe; color: #1565C0; }
        .kpi.closed   .kpi-ico { background: #d1fae5; color: #059669; }
        .kpi.archived .kpi-ico { background: #f1f5f9; color: #64748b; }
        .kpi.unread   .kpi-ico { background: #fef3c7; color: #d97706; }

        /* Tabs */
        .tabs { display: flex; gap: 4px; margin-bottom: 14px; border-bottom: 2px solid var(--border); }
        .tab {
            padding: 10px 18px; text-decoration: none; color: var(--text-2); font-weight: 700;
            border-bottom: 3px solid transparent; transition: 0.15s; font-size: 13px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .tab:hover { color: var(--primary); }
        .tab.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab .count { background: var(--border); padding: 1px 7px; border-radius: 10px; font-size: 11px; }
        .tab.active .count { background: var(--primary); color: #fff; }

        /* Filter bar */
        .filters { background: #fff; border-radius: 12px; border: 1px solid var(--border); padding: 12px 16px; margin-bottom: 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .filters .fld { display: flex; flex-direction: column; gap: 2px; }
        .filters label { font-size: 10.5px; color: var(--text-3); font-weight: 700; }
        .filters input, .filters select { padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 7px; font-size: 12.5px; min-width: 120px; font-family: 'Tajawal', sans-serif; }
        .filters input[type="text"] { min-width: 220px; }
        .filters input:focus, .filters select:focus { outline: 2px solid var(--primary); outline-offset: -1px; }
        .filters .chk { display: flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 600; }
        .filters .chk input { min-width: auto; }
        .filters .btn-row { display: flex; gap: 6px; margin-inline-start: auto; }
        .filters button { padding: 7px 14px; border: 0; border-radius: 7px; cursor: pointer; font-weight: 700; font-size: 12.5px; font-family: 'Tajawal', sans-serif; }
        .filters .btn-pri { background: var(--primary); color: #fff; }
        .filters .btn-sec { background: #e2e8f0; color: var(--text-2); text-decoration: none; display: inline-block; line-height: 1.4; }

        /* Table */
        .tk-table { background: #fff; border-radius: 12px; border: 1px solid var(--border); overflow: hidden; }
        .tk-table table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        .tk-table th { background: #f8fafc; color: var(--text-2); padding: 11px 10px; text-align: start; font-weight: 800; border-bottom: 1.5px solid var(--border); white-space: nowrap; font-size: 11.5px; }
        .tk-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .tk-table tr:hover td { background: #f8fafc; }
        .tk-table tr.row-critical { background: rgba(220, 38, 38, 0.04); }
        .tk-table tr.row-critical:hover td { background: rgba(220, 38, 38, 0.08); }
        .tk-table tr.unread { background: rgba(245, 158, 11, 0.05); }
        .tk-table tr.unread:hover td { background: rgba(245, 158, 11, 0.08); }

        .tk-link { color: var(--primary); font-weight: 800; text-decoration: none; }
        .tk-link:hover { text-decoration: underline; }
        .tk-num { color: var(--text-3); font-size: 11px; font-weight: 700; font-family: 'Inter', monospace; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 5px; font-weight: 800; font-size: 10.5px; color: #fff; }
        .badge.status-open        { background: #1565C0; }
        .badge.status-assigned    { background: #7c3aed; }
        .badge.status-in_progress { background: #0ea5e9; }
        .badge.status-awaiting    { background: #f59e0b; }
        .badge.status-resolved    { background: #16a34a; }
        .badge.status-closed      { background: #64748b; }
        .badge.status-archived    { background: #94a3b8; }

        .prio { display: inline-flex; align-items: center; gap: 4px; font-weight: 800; font-size: 11px; }
        .prio-dot { width: 8px; height: 8px; border-radius: 50%; }

        .tk-meta { font-size: 11.5px; color: var(--text-3); display: flex; align-items: center; gap: 4px; }
        .tk-meta .i { color: var(--primary); }
        .tk-unread-dot { width: 8px; height: 8px; border-radius: 50%; background: #f59e0b; display: inline-block; margin-inline-start: 4px; }

        .empty { text-align: center; padding: 60px 20px; color: var(--text-3); }
        .empty i { font-size: 48px; opacity: 0.3; display: block; margin-bottom: 12px; }
        .empty p { font-size: 14px; font-weight: 700; }

        .flash-msg { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-weight: 700; font-size: 13px; }
        .flash-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .flash-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <div class="tk-hero">
        <div class="tk-hero-ico"><i class="fa-solid fa-ticket"></i></div>
        <div class="tk-hero-text">
            <h1>نظام التذاكر</h1>
            <p>إدارة التذاكر مع بث جماعي + تأكيد قراءة + تتبع المحادثات</p>
        </div>
        <?php if ($can_create): ?>
        <a href="<?= BASE_URL ?>/tickets/new.php" class="btn">
            <i class="fa-solid fa-circle-plus"></i> تذكرة جديدة
        </a>
        <?php endif; ?>
    </div>

    <div class="kpi-grid">
        <div class="kpi open">
            <div class="kpi-ico"><i class="fa-solid fa-inbox"></i></div>
            <div><div class="kpi-val"><?= number_format($counts['open']) ?></div><div class="kpi-lbl">تذكرة مفتوحة</div></div>
        </div>
        <div class="kpi closed">
            <div class="kpi-ico"><i class="fa-solid fa-circle-check"></i></div>
            <div><div class="kpi-val"><?= number_format($counts['closed']) ?></div><div class="kpi-lbl">مغلقة</div></div>
        </div>
        <div class="kpi archived">
            <div class="kpi-ico"><i class="fa-solid fa-box-archive"></i></div>
            <div><div class="kpi-val"><?= number_format($counts['archived']) ?></div><div class="kpi-lbl">مؤرشفة</div></div>
        </div>
        <div class="kpi unread">
            <div class="kpi-ico"><i class="fa-solid fa-bell"></i></div>
            <div><div class="kpi-val"><?= number_format($counts['unread_mine']) ?></div><div class="kpi-lbl">لم تقرأها بعد</div></div>
        </div>
    </div>

    <div class="tabs">
        <a href="?tab=open<?= $q?'&q='.urlencode($q):'' ?>" class="tab <?= $tab==='open'?'active':'' ?>">
            <i class="fa-solid fa-inbox"></i> مفتوحة
            <span class="count"><?= $counts['open'] ?></span>
        </a>
        <a href="?tab=closed<?= $q?'&q='.urlencode($q):'' ?>" class="tab <?= $tab==='closed'?'active':'' ?>">
            <i class="fa-solid fa-circle-check"></i> مغلقة
            <span class="count"><?= $counts['closed'] ?></span>
        </a>
        <a href="?tab=archived<?= $q?'&q='.urlencode($q):'' ?>" class="tab <?= $tab==='archived'?'active':'' ?>">
            <i class="fa-solid fa-box-archive"></i> أرشيف
            <span class="count"><?= $counts['archived'] ?></span>
        </a>
    </div>

    <form class="filters" method="get">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="fld">
            <label>بحث</label>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="رقم/عنوان/وصف">
        </div>
        <div class="fld">
            <label>الحالة</label>
            <select name="status">
                <option value="">الكل</option>
                <?php foreach ($STATUS_GROUPS[$tab] as $s): ?>
                    <option value="<?= e($s) ?>" <?= $status===$s?'selected':'' ?>><?= e($STATUS_AR[$s]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fld">
            <label>النوع</label>
            <select name="type">
                <option value="">الكل</option>
                <?php foreach ($TYPE_AR as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $type===$k?'selected':'' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fld">
            <label>الأولوية</label>
            <select name="priority">
                <option value="">الكل</option>
                <?php foreach ($PRIORITY_AR as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $pri===$k?'selected':'' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="chk">
            <input type="checkbox" name="mine" value="1" <?= $mine?'checked':'' ?>>
            تذاكري فقط
        </label>
        <div class="btn-row">
            <button type="submit" class="btn-pri"><i class="fa-solid fa-magnifying-glass"></i> تطبيق</button>
            <a href="?tab=<?= e($tab) ?>" class="btn-sec">مسح</a>
        </div>
    </form>

    <?php foreach ($flash_msgs as $fm): ?>
        <div class="flash-msg flash-<?= e($fm['type']) ?>"><?= e($fm['message']) ?></div>
    <?php endforeach; ?>

    <div class="tk-table">
        <?php if (!$tickets): ?>
            <div class="empty">
                <i class="fa-solid fa-inbox"></i>
                <p>لا توجد تذاكر في هذا التبويب<?= $q ? ' تطابق بحثك' : '' ?>.</p>
                <?php if ($can_create): ?>
                    <a href="<?= BASE_URL ?>/tickets/new.php" style="display:inline-block;margin-top:14px;background:var(--primary);color:#fff;padding:8px 18px;border-radius:10px;text-decoration:none;font-weight:800">
                        <i class="fa-solid fa-circle-plus"></i> أنشئ أول تذكرة
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>الرقم</th>
                        <th>العنوان / النوع</th>
                        <th>الحالة</th>
                        <th>الأولوية</th>
                        <th>المُنشئ</th>
                        <th>المُعيَّن</th>
                        <th>الرسائل</th>
                        <th>آخر تحديث</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $t):
                    $is_unread = (int)$t['subscriber_cnt'] > 0;  // مبسط — تفصيل في view
                    $row_cls = $t['priority']==='critical' ? 'row-critical' : ($is_unread ? 'unread' : '');
                ?>
                    <tr class="<?= $row_cls ?>">
                        <td>
                            <a href="<?= BASE_URL ?>/tickets/view.php?id=<?= (int)$t['id'] ?>" class="tk-num"><?= e($t['ticket_number']) ?></a>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/tickets/view.php?id=<?= (int)$t['id'] ?>" class="tk-link"><?= e($t['title']) ?></a>
                            <div style="margin-top:3px;font-size:11px;color:var(--text-3);display:flex;align-items:center;gap:5px">
                                <i class="fa-solid <?= e($TYPE_ICON[$t['ticket_type']] ?? 'fa-circle') ?>"></i>
                                <?= e($TYPE_AR[$t['ticket_type']] ?? $t['ticket_type']) ?>
                                <?php if ($t['related_type']): ?>
                                    <span>•</span>
                                    <span><?= e($t['related_type']) ?>#<?= (int)$t['related_id'] ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><span class="badge status-<?= e($t['status']) ?>"><?= e($STATUS_AR[$t['status']] ?? $t['status']) ?></span></td>
                        <td>
                            <span class="prio" style="color:<?= $PRIORITY_COLOR[$t['priority']] ?? '#0f172a' ?>">
                                <span class="prio-dot" style="background:<?= $PRIORITY_COLOR[$t['priority']] ?? '#0f172a' ?>"></span>
                                <?= e($PRIORITY_AR[$t['priority']] ?? $t['priority']) ?>
                            </span>
                        </td>
                        <td><span class="tk-meta"><i class="fa-solid fa-user i"></i> <?= e($t['creator_name'] ?? '—') ?></span></td>
                        <td>
                            <?php if ($t['assignee_name']): ?>
                                <span class="tk-meta"><i class="fa-solid fa-user-check i"></i> <?= e($t['assignee_name']) ?></span>
                            <?php else: ?>
                                <span class="tk-meta" style="color:var(--text-3)">— غير مُعيَّن —</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="tk-meta">
                                <i class="fa-regular fa-comment i"></i> <?= (int)$t['message_count'] ?>
                                <?php if ((int)$t['subscriber_cnt'] > 1): ?>
                                    <span title="مشتركون">(<?= (int)$t['subscriber_cnt'] ?> 👥)</span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="tk-meta" title="<?= e($t['updated_at']) ?>">
                                <?= e(human_time_diff(strtotime($t['updated_at']))) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <p style="text-align:center;color:var(--text-3);font-size:11.5px;margin-top:20px;padding:10px">
        <i class="fa-solid fa-circle-info"></i>
        يعرض <?= count($tickets) ?> تذكرة (حد أقصى 200). للحصول على نتائج أكثر، استخدم المرشحات أعلاه.
    </p>

</div>
</main>
</div><!-- /.main-area -->
</body>
</html>
