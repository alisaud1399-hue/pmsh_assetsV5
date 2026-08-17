<?php
/**
 * reports/maintenance/work_orders.php — كل أوامر العمل
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.maintenance.work_orders');

$can_export  = can('reports.maintenance.work_orders', 'export');
$excel_mode  = report_excel_mode_active('reports.maintenance.work_orders');
$print_mode  = report_print_mode_active('reports.maintenance.work_orders');
$print_charts = report_print_charts_mode_active('reports.maintenance.work_orders');

$rtl = is_rtl();
$page_title = $rtl?'أوامر العمل':'Work Orders';
$active_nav = 'reports.maintenance';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الصيانة':'Maintenance Reports','url'=>BASE_URL.'/reports/maintenance/'],
    ['name'=>$rtl?'أوامر العمل':'Work Orders'],
];

global $pdo;

$rows = $pdo->query("
    SELECT w.id, w.wo_number, w.wo_date, w.expected_completion_date, w.actual_completion_date,
           w.status, w.wo_type, w.final_status, w.contractor_name, w.engineer_name,
           w.work_hours_total, w.work_completed,
           c.request_number, c.priority
    FROM complaint_work_orders w
    LEFT JOIN complaints c ON c.id = w.complaint_id
    ORDER BY w.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$completed = count(array_filter($rows, fn($r) => $r['status'] === 'completed'));

$STATUS_AR = ['draft'=>'مسودة','sent_to_contractor'=>'مرسلة','in_progress'=>'قيد التنفيذ','pending_manager_approval'=>'بانتظار موافقة','completed'=>'مكتملة','rejected_by_manager'=>'مرفوضة','cancelled'=>'ملغاة'];
$STATUS_COLOR = ['draft'=>'#94a3b8','sent_to_contractor'=>'#0ea5e9','in_progress'=>'#7c3aed','pending_manager_approval'=>'#f59e0b','completed'=>'#16a34a','rejected_by_manager'=>'#dc2626','cancelled'=>'#475569'];
$FINAL_AR = ['completed'=>'منجزة','working_need_parts'=>'تحتاج قطع','need_secondary_parts'=>'قطع ثانوية','need_agent'=>'وكيل','pending'=>'معلقة'];
$TYPE_AR = ['medical'=>'طبية','general'=>'عامة','it'=>'تقنية'];

/* === Detail Report Export === */
if ($print_mode) {
    $t = $rtl ? $page_title : $page_title;
    report_print_head($t, '', ['التاريخ'=>date('Y-m-d'),'المستخدم'=>user_name()?:'-','المستشفى'=>get_setting('hospital_name','PMSH')]);
    echo '<p style="text-align:center;color:#64748b;padding:14px">'.htmlspecialchars($rtl?'هذا التقرير يستخدم جداول تفاعلية. للاطلاع على البيانات افتح الصفحة في النظام.':'This report uses interactive tables.').'</p>';
    report_print_foot();
}

if ($print_charts) {
    $t = $rtl ? $page_title : $page_title;
    report_print_charts_head($t, []);
    echo '<div class="pc-section"><p style="text-align:center;color:#64748b;padding:14px">'.htmlspecialchars($rtl?'لا توجد رسوم بيانية في هذا التقرير.':'No charts in this report.').'</p></div>';
    report_print_charts_foot();
}

if ($excel_mode) {
    $rows = [];
    report_export_excel('report_'.date('Y-m-d').'.csv', ['Item','Value'], $rows, $page_title);
}?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
    <meta charset="UTF-8"><title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        body { font-family:'Tajawal',sans-serif; background:#f8fafc; }
        .container { max-width: 1280px; margin: 0 auto; padding: 18px; }
        .back { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:#fff; color:#475569; border:1px solid #e2e8f0; border-radius:8px; text-decoration:none; font-weight:700; font-size:12.5px; margin-bottom:12px; }
        .back:hover { background:#f1f5f9; }
        .hero { background:linear-gradient(135deg, #0c4a6e, #0891b2); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(8,145,178,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:13px 14px; }
        .stat .l { font-size:11px; color:#64748b; font-weight:700; }
        .stat .v { font-size:22px; font-weight:900; color:#0f172a; margin-top:2px; }
        .stat.ok { background:#f0fdf4; border-color:#bbf7d0; } .stat.ok .v { color:#16a34a; }
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#0891b2; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .wo { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#0891b2; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/maintenance/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-screwdriver-wrench"></i></div>
        <div>
            <h1><?= $rtl?'أوامر العمل':'Work Orders' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.maintenance.work_orders') ?>
            </div>
            <p><?= $rtl?'كل أوامر العمل: الحالة، المقاول، المهندس، ساعات العمل، التاريخ المتوقع/الفعلي':'All work orders: status, contractor, engineer, work hours, expected/actual dates' ?></p>
        </div>
        <div class="v"><?= $total ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي أوامر العمل':'Total WOs' ?></div>
            <div class="v"><?= $total ?></div>
        </div>
        <div class="stat ok">
            <div class="l"><?= $rtl?'المكتملة':'Completed' ?></div>
            <div class="v"><?= $completed ?></div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'معدل الإنجاز':'Completion Rate' ?></div>
            <div class="v"><?= $total > 0 ? round($completed / $total * 100, 1) : 0 ?>%</div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-table ic"></i>
            <?= $rtl?'قائمة أوامر العمل':'Work Orders List' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty"><?= $rtl?'لا توجد أوامر عمل':'No work orders yet' ?></div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'رقم WO':'WO #' ?></th>
                    <th><?= $rtl?'البلاغ':'Complaint' ?></th>
                    <th><?= $rtl?'النوع':'Type' ?></th>
                    <th><?= $rtl?'الحالة':'Status' ?></th>
                    <th><?= $rtl?'الحالة النهائية':'Final' ?></th>
                    <th><?= $rtl?'المقاول':'Contractor' ?></th>
                    <th><?= $rtl?'المهندس':'Engineer' ?></th>
                    <th><?= $rtl?'الساعات':'Hours' ?></th>
                    <th><?= $rtl?'تاريخ':'Date' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $scol = $STATUS_COLOR[$r['status']] ?? '#475569';
            ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="wo"><?= e($r['wo_number']) ?></span></td>
                    <td><?= e($r['request_number'] ?? '—') ?></td>
                    <td><span class="pill" style="background:#0891b222;color:#0891b2"><?= e($TYPE_AR[$r['wo_type']] ?? $r['wo_type']) ?></span></td>
                    <td><span class="pill" style="background:<?= $scol ?>22;color:<?= $scol ?>"><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></span></td>
                    <td><?= e($FINAL_AR[$r['final_status']] ?? $r['final_status'] ?? '—') ?></td>
                    <td><?= e($r['contractor_name'] ?? '—') ?></td>
                    <td><?= e($r['engineer_name'] ?? '—') ?></td>
                    <td><?= $r['work_hours_total'] ? round((float)$r['work_hours_total'], 1).'h' : '—' ?></td>
                    <td><?= $r['wo_date'] ? date('d/m', strtotime($r['wo_date'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</main>
</div>
</body>
</html>
