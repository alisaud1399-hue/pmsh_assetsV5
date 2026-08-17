<?php
/**
 * helpdesk/index.php — Phase 8: Index with Tabs + Filters + Pagination
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/helpdesk_helpers.php';

page_guard('helpdesk', 'view');
global $pdo;
$user_id = (int) current_user()['id'];

$STATUS_AR = [
    'new' => 'جديدة', 'in_review' => 'قيد المراجعة',
    'awaiting_user' => 'بانتظار المستخدم', 'closed' => 'مغلقة',
];
$PRIORITY_AR = [
    'low' => 'منخفضة', 'medium' => 'متوسطة',
    'high' => 'عالية', 'critical' => 'حرجة',
];
$PRIORITY_COLOR = [
    'low' => '#16a34a', 'medium' => '#0ea5e9',
    'high' => '#f59e0b', 'critical' => '#dc2626',
];

// ─── Filters ───
$tab = $_GET['tab'] ?? 'all';        // all | open | new | in_review | awaiting_user | closed | mine | assigned
$cat = (int)($_GET['cat'] ?? 0);
$pri = $_GET['pri'] ?? '';            // '', 'low','medium','high','critical'
$search = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

// ─── Build WHERE ───
$where = []; $params = [];
$mine_only = ($tab === 'mine');
$assigned_to_me = ($tab === 'assigned');

// Phase 10: Data Scope — فلتر حسب الدور (admin/exec → الكل، manager → قسمه، employee → تذاكيره)
$scope = helpdesk_data_scope($pdo, $user_id);
$where[] = $scope['where'];
$params = array_merge($params, $scope['params']);

if ($tab === 'open') {
    $where[] = "t.status IN ('new','in_review','awaiting_user')";
} elseif ($tab === 'closed') {
    $where[] = "t.status = 'closed'";
} elseif (in_array($tab, ['new','in_review','awaiting_user'], true)) {
    $where[] = "t.status = ?";
    $params[] = $tab;
} elseif ($mine_only) {
    $where[] = "t.created_by = ?";
    $params[] = $user_id;
} elseif ($assigned_to_me) {
    $where[] = "t.assigned_to = ?";
    $params[] = $user_id;
}

if ($cat > 0) {
    $where[] = "(t.category_id = ? OR t.subcategory_id = ?)";
    $params[] = $cat; $params[] = $cat;
}
if (in_array($pri, ['low','medium','high','critical'], true)) {
    $where[] = "t.priority = ?";
    $params[] = $pri;
}
if ($search !== '') {
    $where[] = "(t.title LIKE ? OR t.ticket_number LIKE ? OR t.description LIKE ?)";
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $search) . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ─── Count per tab (KPIs) ───
$count_q = "SELECT COUNT(*) FROM helpdesk_tickets t $where_sql";
$cs = $pdo->prepare($count_q); $cs->execute($params);
$total_filtered = (int)$cs->fetchColumn();

// ─── KPIs (إحصائيات سريعة لكل tab) — scope-aware ───
$scope_where = $scope['where'];
$scope_params = $scope['params'];
$kpi_q = function(string $extra_where = '') use ($pdo, $scope_where, $scope_params) {
    $sql = "SELECT COUNT(*) FROM helpdesk_tickets t WHERE $scope_where" . ($extra_where ? " AND $extra_where" : '');
    $s = $pdo->prepare($sql);
    $s->execute($scope_params);
    return (int)$s->fetchColumn();
};
$kpis = [
    'all'      => $kpi_q(),
    'open'     => $kpi_q("t.status IN ('new','in_review','awaiting_user')"),
    'new'      => $kpi_q("t.status = 'new'"),
    'in_review'=> $kpi_q("t.status = 'in_review'"),
    'awaiting' => $kpi_q("t.status = 'awaiting_user'"),
    'closed'   => $kpi_q("t.status = 'closed'"),
    'mine'     => 0, 'assigned' => 0,
];
$m = $pdo->prepare("SELECT COUNT(*) FROM helpdesk_tickets WHERE created_by=?"); $m->execute([$user_id]); $kpis['mine'] = (int)$m->fetchColumn();
$a = $pdo->prepare("SELECT COUNT(*) FROM helpdesk_tickets WHERE assigned_to=?"); $a->execute([$user_id]); $kpis['assigned'] = (int)$a->fetchColumn();

// ─── Fetch tickets ───
$sql = "
    SELECT t.id, t.ticket_number, t.title, t.priority, t.status, t.created_at, t.message_count, t.assigned_to,
           c.name_ar AS category_name_ar, c.name_en AS category_name_en, c.icon AS category_icon, c.color AS category_color,
           cu.full_name AS creator_name,
           au.full_name AS assignee_name
    FROM helpdesk_tickets t
    JOIN helpdesk_categories c ON c.id = t.category_id
    LEFT JOIN users cu ON cu.id = t.created_by
    LEFT JOIN users au ON au.id = t.assigned_to
    $where_sql
    ORDER BY
      CASE t.priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END,
      t.last_message_at DESC, t.id DESC
    LIMIT $per_page OFFSET $offset
";
$ts = $pdo->prepare($sql);
$ts->execute($params);
$tickets = $ts->fetchAll(PDO::FETCH_ASSOC);

// Total pages
$total_pages = (int)ceil($total_filtered / $per_page);

// Categories dropdown
$cats = $pdo->query("
    SELECT id, name_ar, name_en, icon, color, parent_id FROM helpdesk_categories
    WHERE is_active = 1
    ORDER BY parent_id, sort_order
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'نظام التذاكر الذكي';
$active_nav = 'helpdesk';
$flash_msgs = get_flash();

// helper: build URL with current filters
function tab_url($new_tab, $extra = []) {
    $params = $_GET;
    $params['tab'] = $new_tab;
    $params['p'] = 1;
    $params = array_merge($params, $extra);
    return '?' . http_build_query($params);
}
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
        :root { --primary:#4338ca; --border:#e2e8f0; --bg:#f8fafc; --text-main:#0f172a; --text-2:#475569; --text-3:#94a3b8; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Tajawal', sans-serif; background:var(--bg); color:var(--text-main); }
        .container { max-width: 1280px; margin: 0 auto; padding: 16px 20px; }

        .hd-hero { background: linear-gradient(135deg, #4338ca, #7c3aed); color:#fff; border-radius: 16px; padding: 22px 26px; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(67,56,202,0.18); display:flex; align-items:center; gap:18px; }
        .hd-hero .hd-ico { width:56px; height:56px; background:rgba(255,255,255,0.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:24px; }
        .hd-hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hd-hero p { font-size:13px; opacity:0.9; margin:0; }
        .hd-hero .hd-actions { margin-inline-start:auto; display:flex; gap:8px; }
        .hd-btn { padding:10px 18px; background:rgba(255,255,255,0.2); color:#fff; border:1px solid rgba(255,255,255,0.3); border-radius:10px; font-weight:800; font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; transition: all 0.2s; font-family:'Tajawal'; }
        .hd-btn:hover { background:rgba(255,255,255,0.3); }
        .hd-btn.pri { background:#fff; color:var(--primary); }
        .hd-btn.pri:hover { background:#f1f5f9; }

        /* Tabs (Phase 8) */
        .tabs { display:flex; flex-wrap:wrap; gap:6px; background:#fff; border:1px solid var(--border); border-radius:12px; padding:6px; margin-bottom:12px; overflow-x:auto; }
        .tab { padding:7px 14px; border-radius:8px; color:var(--text-2); font-size:12.5px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; transition:all 0.15s; }
        .tab:hover { background:#f1f5f9; }
        .tab.active { background:var(--primary); color:#fff; }
        .tab .badge { background:rgba(0,0,0,0.08); padding:1px 7px; border-radius:99px; font-size:10.5px; font-weight:800; }
        .tab.active .badge { background:rgba(255,255,255,0.25); color:#fff; }

        /* Filter bar (Phase 8) */
        .filters { background:#fff; border:1px solid var(--border); border-radius:12px; padding:12px 14px; margin-bottom:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .filter-group { display:flex; align-items:center; gap:5px; }
        .filter-group label { font-size:11.5px; color:var(--text-3); font-weight:800; }
        .filter-group select, .filter-group input { padding:6px 10px; border:1.5px solid #cbd5e1; border-radius:7px; font-size:12px; font-family:'Tajawal'; min-width:90px; }
        .filter-group input[type="text"] { min-width:200px; }
        .filter-btn { padding:6px 14px; background:var(--primary); color:#fff; border:0; border-radius:7px; font-weight:800; font-size:12px; cursor:pointer; font-family:'Tajawal'; }
        .filter-clear { padding:6px 12px; background:#f1f5f9; color:var(--text-2); border:0; border-radius:7px; font-weight:800; font-size:12px; cursor:pointer; font-family:'Tajawal'; text-decoration:none; }
        .filter-clear:hover { background:#e2e8f0; }

        /* Section */
        .sec { background:#fff; border:1px solid var(--border); border-radius:12px; padding:0; margin-bottom:14px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:14px 18px; display:flex; align-items:center; gap:8px; border-bottom:1px solid var(--border); background:#f8fafc; }
        .sec-h .ic { color:var(--primary); }
        .sec-h .ct { background:var(--primary); color:#fff; font-size:11px; padding:2px 9px; border-radius:99px; margin-inline-start:auto; }
        .sec-h .total { color:var(--text-3); font-weight:700; font-size:11.5px; }

        .tk-table { width:100%; border-collapse:collapse; font-size:12.5px; }
        .tk-table th { text-align:start; padding:10px 12px; color:var(--text-3); font-weight:800; font-size:11px; text-transform:uppercase; border-bottom:1px solid var(--border); background:#fff; }
        .tk-table td { padding:11px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .tk-table tr:hover td { background:#fafbff; }
        .tk-num { font-family:'Inter', monospace; font-size:11.5px; color:var(--text-2); font-weight:700; }
        .tk-title { font-weight:800; color:var(--text-main); text-decoration:none; }
        .tk-title:hover { color:var(--primary); }
        .tk-empty { text-align:center; padding:40px; color:var(--text-3); font-size:13px; }

        .prio { display:inline-flex; align-items:center; gap:4px; font-weight:800; font-size:11px; }
        .prio .dot { width:8px; height:8px; border-radius:50%; }

        .st { display:inline-block; padding:3px 10px; border-radius:6px; font-weight:800; font-size:10.5px; }
        .st-new { background:#dbeafe; color:#1e40af; }
        .st-in_review { background:#e0e7ff; color:#3730a3; }
        .st-awaiting_user { background:#fed7aa; color:#9a3412; }
        .st-closed { background:#d1fae5; color:#065f46; }

        .cat-pill { display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:800; padding:3px 9px; border-radius:6px; }
        .cat-pill .ci { font-size:12px; }

        .empty-state { text-align:center; padding:50px 20px; color:var(--text-3); }
        .empty-state i { font-size:48px; margin-bottom:14px; opacity:0.4; }
        .empty-state h3 { font-size:16px; color:var(--text-2); margin-bottom:6px; font-weight:800; }
        .empty-state p { font-size:13px; }

        .info-banner { background:linear-gradient(135deg,#dbeafe,#e0e7ff); border:1px solid #93c5fd; border-inline-start:4px solid #4338ca; padding:12px 16px; border-radius:10px; margin-bottom:12px; font-size:13px; color:#1e3a8a; }
        .info-banner i { color:#4338ca; margin-inline-end:6px; }

        /* Pagination */
        .pagination { display:flex; gap:4px; justify-content:center; padding:12px 14px; border-top:1px solid var(--border); background:#f8fafc; }
        .page-btn { min-width:34px; height:34px; padding:0 10px; border:1px solid var(--border); background:#fff; color:var(--text-2); border-radius:7px; font-weight:800; font-size:12px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; }
        .page-btn:hover { background:#f1f5f9; }
        .page-btn.active { background:var(--primary); color:#fff; border-color:var(--primary); }
        .page-btn:disabled { opacity:0.4; cursor:not-allowed; }

        .unread-dot { display:inline-block; width:7px; height:7px; background:#dc2626; border-radius:50%; margin-inline-start:4px; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <?php foreach ($flash_msgs as $fm): ?>
        <div class="info-banner" style="background:<?= $fm['type']==='success'?'#dcfce7':'#fee2e2' ?>;border-color:<?= $fm['type']==='success'?'#86efac':'#fca5a5' ?>;color:<?= $fm['type']==='success'?'#14532d':'#7f1d1d' ?>;">
            <i class="fa-solid fa-<?= $fm['type']==='success'?'check':'circle-exclamation' ?>"></i>
            <?= e($fm['message']) ?>
        </div>
    <?php endforeach; ?>

    <div class="hd-hero">
        <div class="hd-ico"><i class="fa-solid fa-ticket"></i></div>
        <div>
            <h1>نظام التذاكر الذكي</h1>
            <p>حوكمة النظام — أخطاء، صلاحيات، ميزات، استكمال بيانات + KB</p>
        </div>
        <div class="hd-actions">
            <?php if (can('helpdesk', 'create')): ?>
                <a href="<?= BASE_URL ?>/helpdesk/new.php" class="hd-btn pri">
                    <i class="fa-solid fa-plus"></i> تذكرة جديدة
                </a>
            <?php endif; ?>
            <?php if (is_admin()): ?>
                <a href="<?= BASE_URL ?>/admin/helpdesk_dashboard.php" class="hd-btn">
                    <i class="fa-solid fa-sliders"></i> لوحة الإدارة
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs (Phase 8) -->
    <div class="tabs">
        <a href="<?= tab_url('all') ?>" class="tab <?= $tab==='all'?'active':'' ?>">
            <i class="fa-solid fa-layer-group"></i> الكل <span class="badge"><?= $kpis['all'] ?></span>
        </a>
        <a href="<?= tab_url('open') ?>" class="tab <?= $tab==='open'?'active':'' ?>">
            <i class="fa-solid fa-fire"></i> مفتوحة <span class="badge"><?= $kpis['open'] ?></span>
        </a>
        <a href="<?= tab_url('new') ?>" class="tab <?= $tab==='new'?'active':'' ?>">
            <i class="fa-solid fa-sparkles"></i> جديدة <span class="badge"><?= $kpis['new'] ?></span>
        </a>
        <a href="<?= tab_url('in_review') ?>" class="tab <?= $tab==='in_review'?'active':'' ?>">
            <i class="fa-solid fa-magnifying-glass"></i> قيد المراجعة <span class="badge"><?= $kpis['in_review'] ?></span>
        </a>
        <a href="<?= tab_url('awaiting_user') ?>" class="tab <?= $tab==='awaiting_user'?'active':'' ?>">
            <i class="fa-solid fa-hourglass-half"></i> بانتظار <span class="badge"><?= $kpis['awaiting'] ?></span>
        </a>
        <a href="<?= tab_url('closed') ?>" class="tab <?= $tab==='closed'?'active':'' ?>">
            <i class="fa-solid fa-check"></i> مغلقة <span class="badge"><?= $kpis['closed'] ?></span>
        </a>
        <a href="<?= tab_url('mine') ?>" class="tab <?= $tab==='mine'?'active':'' ?>">
            <i class="fa-solid fa-user"></i> تذاكري <span class="badge"><?= $kpis['mine'] ?></span>
        </a>
        <a href="<?= tab_url('assigned') ?>" class="tab <?= $tab==='assigned'?'active':'' ?>">
            <i class="fa-solid fa-user-shield"></i> معينة لي <span class="badge"><?= $kpis['assigned'] ?></span>
        </a>
    </div>

    <!-- Filters (Phase 8) -->
    <form class="filters" method="get">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <div class="filter-group">
            <label><i class="fa-solid fa-magnifying-glass"></i></label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="ابحث في العنوان/الرقم/الوصف">
        </div>
        <div class="filter-group">
            <label>التصنيف:</label>
            <select name="cat">
                <option value="0">— الكل —</option>
                <?php foreach ($cats as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>>
                        <?= $c['parent_id']?'&nbsp;&nbsp;↳ ':'' ?><?= e(helpdesk_t($c['name_ar'] ?? '', $c['name_en'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>الأولوية:</label>
            <select name="pri">
                <option value="">— الكل —</option>
                <option value="critical" <?= $pri==='critical'?'selected':'' ?>>🔴 حرجة</option>
                <option value="high" <?= $pri==='high'?'selected':'' ?>>🟠 عالية</option>
                <option value="medium" <?= $pri==='medium'?'selected':'' ?>>🔵 متوسطة</option>
                <option value="low" <?= $pri==='low'?'selected':'' ?>>🟢 منخفضة</option>
            </select>
        </div>
        <button type="submit" class="filter-btn">
            <i class="fa-solid fa-filter"></i> فلتر
        </button>
        <?php if ($search || $cat || $pri): ?>
            <a href="?tab=<?= e($tab) ?>" class="filter-clear">
                <i class="fa-solid fa-xmark"></i> مسح
            </a>
        <?php endif; ?>
    </form>

    <!-- Tickets table -->
    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-list ic"></i>
            <?php
            $tab_titles = [
                'all' => 'كل التذاكر', 'open' => 'المفتوحة', 'new' => 'الجديدة',
                'in_review' => 'قيد المراجعة', 'awaiting_user' => 'بانتظار المستخدم',
                'closed' => 'المغلقة', 'mine' => 'تذاكري', 'assigned' => 'المعينة لي',
            ];
            echo e($tab_titles[$tab] ?? 'التذاكر');
            ?>
            <span class="total"><?= $total_filtered ?> نتيجة</span>
            <span class="ct">صفحة <?= $page ?> / <?= max(1, $total_pages) ?></span>
        </div>

        <?php if (!$tickets): ?>
            <div class="empty-state">
                <i class="fa-solid fa-inbox"></i>
                <h3>لا توجد تذاكر تطابق الفلاتر</h3>
                <p>جرّب تعديل الفلاتر أو أنشئ تذكرة جديدة</p>
            </div>
        <?php else: ?>
            <table class="tk-table">
                <thead>
                    <tr>
                        <th style="width:130px">الرقم</th>
                        <th>العنوان</th>
                        <th style="width:160px">التصنيف</th>
                        <th style="width:90px">الأولوية</th>
                        <th style="width:110px">الحالة</th>
                        <th style="width:140px">المنشئ</th>
                        <th style="width:140px">المعالج</th>
                        <th style="width:120px">آخر نشاط</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td><span class="tk-num"><?= e($t['ticket_number']) ?></span></td>
                        <td>
                            <a href="<?= BASE_URL ?>/helpdesk/view.php?id=<?= (int)$t['id'] ?>" class="tk-title">
                                <?= e(mb_substr($t['title'], 0, 55, 'UTF-8')) ?>
                            </a>
                            <?php if ((int)$t['message_count'] > 0): ?>
                                <span style="background:#f1f5f9;color:var(--text-2);padding:1px 6px;border-radius:99px;font-size:10px;font-weight:700;margin-inline-start:4px">
                                    <i class="fa-regular fa-comment"></i> <?= (int)$t['message_count'] ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="cat-pill" style="background:<?= e($t['category_color']) ?>22;color:<?= e($t['category_color']) ?>">
                                <i class="fa-solid <?= e($t['category_icon']) ?> ci"></i>
                                <?= e(helpdesk_t($t['category_name_ar'] ?? '', $t['category_name_en'] ?? '')) ?>
                            </span>
                        </td>
                        <td>
                            <span class="prio" style="color:<?= $PRIORITY_COLOR[$t['priority']] ?>">
                                <span class="dot" style="background:<?= $PRIORITY_COLOR[$t['priority']] ?>"></span>
                                <?= e($PRIORITY_AR[$t['priority']]) ?>
                            </span>
                        </td>
                        <td><span class="st st-<?= e($t['status']) ?>"><?= e($STATUS_AR[$t['status']]) ?></span></td>
                        <td><?= e($t['creator_name'] ?? '—') ?></td>
                        <td><?= e($t['assignee_name'] ?? '—') ?></td>
                        <td><span class="tk-num"><?= e(date('Y-m-d H:i', strtotime($t['created_at']))) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php
                    $base_url = '?' . http_build_query(array_merge($_GET, ['p' => '__P__']));
                    $make_url = fn($p) => str_replace('__P__', (string)$p, $base_url);
                    ?>
                    <a href="<?= $make_url(max(1, $page-1)) ?>" class="page-btn" <?= $page<=1?'disabled':'' ?>>
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                    <?php
                    $start_p = max(1, $page - 2);
                    $end_p = min($total_pages, $page + 2);
                    if ($start_p > 1) {
                        echo '<a href="' . $make_url(1) . '" class="page-btn">1</a>';
                        if ($start_p > 2) echo '<span class="page-btn" disabled>…</span>';
                    }
                    for ($p = $start_p; $p <= $end_p; $p++) {
                        echo '<a href="' . $make_url($p) . '" class="page-btn ' . ($p===$page?'active':'') . '">' . $p . '</a>';
                    }
                    if ($end_p < $total_pages) {
                        if ($end_p < $total_pages - 1) echo '<span class="page-btn" disabled>…</span>';
                        echo '<a href="' . $make_url($total_pages) . '" class="page-btn">' . $total_pages . '</a>';
                    }
                    ?>
                    <a href="<?= $make_url(min($total_pages, $page+1)) ?>" class="page-btn" <?= $page>=$total_pages?'disabled':'' ?>>
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
</main>
</div>
</body>
</html>
