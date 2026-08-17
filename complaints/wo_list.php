<?php
/**
 * complaints/wo_list.php — قائمة أوامر العمل للشركة المتعاقدة
 * يرى MT01 فقط الأوامر المسندة لشركته
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('work_orders.index');

$_cu = current_user();
$uid = (int) $_cu['id'];
$is_contractor = !empty($_cu['contractor_id'])
    || (($_cu['primary_role']['name'] ?? '') === 'company_employee');
$can_manage = can('work_orders.index', 'view') && !$is_contractor;

// نطاق الرؤية:
//   الأدمن/التنفيذي → الكل | الشركة → أوامرها فقط
//   فريق صيانة (طبي/IT/عام) → أوامر نوعه فقط
//   غير ذلك (بصلاحية الصفحة) → المعيَّن له شخصياً فقط
$my_team_type = null;
$my_dept_id = (int)($_cu['department_id'] ?? 0);
if ($my_dept_id) {
    $dc = $pdo->prepare("SELECT dept_category FROM departments WHERE id = ?");
    $dc->execute([$my_dept_id]);
    $cat = (string)($dc->fetchColumn() ?: '');
    if (str_starts_with($cat, 'maintenance_')) {
        $my_team_type = substr($cat, strlen('maintenance_')); // medical | it | general
    }
}

$params = [];
$where  = "1=1";
if ($is_contractor && !empty($_cu['contractor_id'])) {
    $where = "wo.contractor_id = ?";
    $params[] = (int) $_cu['contractor_id'];
} elseif (can_see_all()) {
    // الكل
} elseif ($my_team_type !== null) {
    $where = "wo.wo_type = ?";
    $params[] = $my_team_type;
} elseif ($can_manage) {
    // يملك صلاحية الصفحة لكنه ليس فريق صيانة: أوامره المعيَّنة فقط
    $where = "wo.assigned_user_id = ?";
    $params[] = $uid;
} else {
    http_response_code(403); die('غير مصرح');
}

$WO_STATUS = [
    'sent_to_contractor'       => ['أُرسل — بانتظار الاستلام', '#d97706', '#fffbeb'],
    'in_progress'              => ['جاري العمل',               '#2563eb', '#eff6ff'],
    'pending_manager_approval' => ['بانتظار الاعتماد',         '#7c3aed', '#f5f3ff'],
    'completed'                => ['مكتمل ومعتمَد',            '#16a34a', '#f0fdf4'],
    'rejected_by_manager'      => ['مرفوض',                    '#dc2626', '#fef2f2'],
    'cancelled'                => ['مُلغى',                    '#94a3b8', '#f8fafc'],
];

$filter = $_GET['status'] ?? 'active';
$status_sql = $filter === 'active'
    ? "AND wo.status IN ('sent_to_contractor','in_progress','pending_manager_approval')"
    : ($filter === 'done' ? "AND wo.status IN ('completed','rejected_by_manager','cancelled')" : "");

$stmt = $pdo->prepare("
    SELECT wo.id, wo.wo_number, wo.status, wo.wo_date, wo.contractor_name,
           wo.sent_to_contractor_at, wo.actual_completion_date,
           c.request_number, c.priority, c.description AS complaint_desc,
           a.description AS asset_desc, a.tag_number, a.criticality_class,
           d.name AS dept_name
    FROM complaint_work_orders wo
    JOIN complaints c ON c.id = wo.complaint_id
    LEFT JOIN assets a ON a.id = c.asset_id
    LEFT JOIN departments d ON d.id = c.dept_id
    WHERE $where $status_sql
    ORDER BY
        FIELD(wo.status,'sent_to_contractor','in_progress','pending_manager_approval','completed') ASC,
        wo.wo_date DESC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$page_title = 'أوامر العمل';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&family=Inter:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--bg:#f1f5f9;--card:#fff;--border:#e2e8f0;--muted:#64748b;--text:#0f172a}
body{background:var(--bg);font-family:'Tajawal',sans-serif}
.eng{font-family:'Inter',sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:22px}
.h-banner{background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:20px;padding:20px 26px;color:#fff;margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.wo-card{background:var(--card);border-radius:16px;border:1px solid var(--border);margin-bottom:12px;overflow:hidden;text-decoration:none;display:block;transition:.2s}
.wo-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);transform:translateY(-2px)}
.wo-card.urgent{border-right:4px solid #dc2626}
.wo-head{padding:14px 18px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--border)}
.wo-body{padding:12px 18px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.wo-body-item{font-size:12px;color:var(--muted);font-weight:700}
.wo-body-item strong{color:var(--text);display:block;font-size:13px}
.status-pill{padding:4px 12px;border-radius:99px;font-size:11px;font-weight:800;white-space:nowrap}
.filter-tabs{display:flex;gap:8px;margin-bottom:18px}
.tab{padding:8px 18px;border-radius:10px;border:1px solid var(--border);font-size:12.5px;font-weight:800;cursor:pointer;text-decoration:none;color:var(--muted);background:var(--card)}
.tab.active{background:#1e3a8a;color:#fff;border-color:#1e3a8a}
.empty{text-align:center;padding:60px;color:var(--muted);font-weight:700}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<?php foreach ($flash_msgs as $fm): $fc=['success'=>'#10b981','warning'=>'#f59e0b','danger'=>'#ef4444'][$fm['type']]??'#3b82f6'; ?>
<div style="background:#fff;border:1px solid <?=$fc?>44;border-right:4px solid <?=$fc?>;padding:12px 16px;border-radius:11px;margin-bottom:14px;font-weight:800;font-size:13px"><?=e($fm['message'])?></div>
<?php endforeach; ?>

<div class="h-banner">
    <div>
        <h1 style="font-size:18px;font-weight:900;margin:0 0 4px"><i class="fa-solid fa-clipboard-list" style="color:#fbbf24"></i> أوامر العمل</h1>
        <div style="font-size:12px;color:#94a3b8"><?= $is_contractor ? e($_cu['full_name'] ?? '') . ' — ' . (e($rows ? $rows[0]['contractor_name'] : '')) : 'جميع الشركات' ?></div>
    </div>
    <span style="background:rgba(255,255,255,.15);padding:7px 16px;border-radius:99px;font-size:13px;font-weight:800"><?= count($rows) ?> أمر عمل</span>
</div>

<div class="filter-tabs">
    <a href="?status=active" class="tab <?= $filter==='active'?'active':'' ?>"><i class="fa-solid fa-clock"></i> النشطة</a>
    <a href="?status=done"   class="tab <?= $filter==='done'?'active':'' ?>"><i class="fa-solid fa-check"></i> المنجزة</a>
    <a href="?status=all"    class="tab <?= $filter==='all'?'active':'' ?>"><i class="fa-solid fa-list"></i> الكل</a>
</div>

<?php if (!$rows): ?>
<div class="empty"><i class="fa-solid fa-clipboard-list" style="font-size:36px;display:block;margin-bottom:12px;color:#cbd5e1"></i>لا توجد أوامر عمل في هذه القائمة.</div>
<?php endif; ?>

<?php foreach ($rows as $wo):
    $ws = $WO_STATUS[$wo['status']] ?? ['—','#94a3b8','#f8fafc'];
    $is_critical = ($wo['criticality_class'] ?? '') === 'A';
    $pri_color = ['normal'=>'#16a34a','urgent'=>'#d97706','critical'=>'#dc2626'][$wo['priority']] ?? '#64748b';
    $pri_label = ['normal'=>'عادي','urgent'=>'عاجل','critical'=>'طارئ'][$wo['priority']] ?? '—';
?>
<a href="<?= BASE_URL ?>/complaints/wo_view.php?id=<?= $wo['id'] ?>" class="wo-card <?= $is_critical?'urgent':'' ?>">
    <div class="wo-head">
        <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <span class="eng" style="font-size:15px;font-weight:900;color:#1e3a8a"><?= e($wo['wo_number']) ?></span>
                <?php if ($is_critical): ?>
                <span style="background:#fef2f2;color:#dc2626;font-size:10px;font-weight:900;padding:2px 8px;border-radius:99px"><i class="fa-solid fa-triangle-exclamation"></i> فئة A</span>
                <?php endif; ?>
                <span style="background:<?=$pri_color?>22;color:<?=$pri_color?>;font-size:10px;font-weight:900;padding:2px 8px;border-radius:99px"><?= $pri_label ?></span>
            </div>
            <div style="font-size:12px;color:var(--muted);font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                <?= e(mb_substr($wo['asset_desc'] ?? $wo['complaint_desc'] ?? '—', 0, 60)) ?>
            </div>
        </div>
        <span class="status-pill" style="background:<?=$ws[2]?>;color:<?=$ws[1]?>"><?= $ws[0] ?></span>
    </div>
    <div class="wo-body">
        <div class="wo-body-item"><strong><?= e($wo['tag_number'] ?? '—') ?></strong>التاج</div>
        <div class="wo-body-item"><strong><?= e($wo['dept_name'] ?? '—') ?></strong>القسم</div>
        <div class="wo-body-item"><strong><?= e($wo['request_number']) ?></strong>رقم البلاغ</div>
        <div class="wo-body-item"><strong class="eng"><?= e($wo['wo_date'] ?? '—') ?></strong>تاريخ الأمر</div>
    </div>
</a>
<?php endforeach; ?>

</div></main>
</div>
</body>
</html>