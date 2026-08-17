<?php
/**
 * reports/maintenance/contractors.php — شركات الصيانة
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.maintenance.contractors');

$can_export  = can('reports.maintenance.contractors', 'export');
$excel_mode  = report_excel_mode_active('reports.maintenance.contractors');
$print_mode  = report_print_mode_active('reports.maintenance.contractors');
$print_charts = report_print_charts_mode_active('reports.maintenance.contractors');

$rtl = is_rtl();
$page_title = $rtl?'شركات الصيانة':'Maintenance Contractors';
$active_nav = 'reports.maintenance';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الصيانة':'Maintenance Reports','url'=>BASE_URL.'/reports/maintenance/'],
    ['name'=>$rtl?'الشركات':'Contractors'],
];

global $pdo;

// نجمع حسب المقاول من complaint_work_orders (لا يوجد جدول منفصل)
$rows = $pdo->query("
    SELECT contractor_name,
           COUNT(*) AS total,
           SUM(status='completed') AS done,
           SUM(status IN ('draft','sent_to_contractor','in_progress','pending_manager_approval')) AS active,
           SUM(work_hours_total) AS total_hours,
           AVG(work_hours_total) AS avg_hours,
           AVG(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS avg_days
    FROM complaint_work_orders
    WHERE contractor_name IS NOT NULL AND contractor_name != ''
    GROUP BY contractor_name
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

// المقاولون من maintenance_companies (إذا موجود)
$companies = [];
try {
    $companies = $pdo->query("SELECT id, name, phone, email FROM maintenance_companies ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $companies = [];
}

$total_wo = array_sum(array_column($rows, 'total'));
$total_contractors = count($rows);

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
        .hero { background:linear-gradient(135deg, #7f1d1d, #dc2626); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(220,38,38,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:13px 14px; }
        .stat .l { font-size:11px; color:#64748b; font-weight:700; }
        .stat .v { font-size:24px; font-weight:900; color:#0f172a; margin-top:2px; }
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:12px; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#dc2626; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        .ct-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:12px; padding:14px 18px; }
        .ct-card { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .ct-h { display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1.5px solid #e2e8f0; background:#fff; }
        .ct-avatar { width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg, #7f1d1d, #dc2626); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:15px; }
        .ct-name { font-size:13.5px; font-weight:900; color:#0f172a; flex:1; }
        .ct-total { font-size:11px; font-weight:800; background:#dc262622; color:#dc2626; padding:3px 8px; border-radius:5px; }
        .ct-body { padding:12px 16px; }
        .ct-stat { display:flex; justify-content:space-between; padding:4px 0; font-size:12.5px; border-bottom:1px dashed #e2e8f0; }
        .ct-stat:last-child { border-bottom:0; }
        .ct-stat .l { color:#64748b; font-weight:700; }
        .ct-stat .v { color:#0f172a; font-weight:800; }
        .progress { height:6px; background:#f1f5f9; border-radius:99px; overflow:hidden; margin-top:6px; }
        .progress > div { height:100%; }
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
        <div class="hero-ico"><i class="fa-solid fa-building"></i></div>
        <div>
            <h1><?= $rtl?'شركات الصيانة':'Maintenance Contractors' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.maintenance.contractors') ?>
            </div>
            <p><?= $rtl?'شركات الصيانة: عدد الأوامر، نسبة الإنجاز، متوسط الأيام، إجمالي الساعات':'Contractors: WO count, completion rate, avg days, total hours' ?></p>
        </div>
        <div class="v"><?= $total_contractors ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="l"><?= $rtl?'عدد المقاولين':'Contractors' ?></div>
            <div class="v"><?= $total_contractors ?></div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي الأوامر':'Total WOs' ?></div>
            <div class="v"><?= $total_wo ?></div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-list ic"></i>
            <?= $rtl?'بطاقات المقاولين':'Contractor Cards' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty"><?= $rtl?'لا يوجد مقاولون بعد':'No contractors yet' ?></div>
        <?php else: ?>
        <div class="ct-grid">
            <?php foreach ($rows as $r):
                $completion = $r['total'] > 0 ? round($r['done'] / $r['total'] * 100) : 0;
                $initials = mb_strtoupper(mb_substr($r['contractor_name'], 0, 2, 'UTF-8'), 'UTF-8');
            ?>
                <div class="ct-card">
                    <div class="ct-h">
                        <div class="ct-avatar"><?= e($initials) ?></div>
                        <div class="ct-name"><?= e($r['contractor_name']) ?></div>
                        <span class="ct-total"><?= (int)$r['total'] ?> <?= $rtl?'أمر':'WOs' ?></span>
                    </div>
                    <div class="ct-body">
                        <div class="ct-stat">
                            <span class="l"><?= $rtl?'مكتملة':'Completed' ?></span>
                            <span class="v" style="color:#16a34a"><?= (int)$r['done'] ?></span>
                        </div>
                        <div class="ct-stat">
                            <span class="l"><?= $rtl?'نشطة':'Active' ?></span>
                            <span class="v" style="color:#0ea5e9"><?= (int)$r['active'] ?></span>
                        </div>
                        <div class="ct-stat">
                            <span class="l"><?= $rtl?'متوسط الأيام':'Avg Days' ?></span>
                            <span class="v"><?= $r['avg_days'] !== null ? round((float)$r['avg_days'], 1).'d' : '—' ?></span>
                        </div>
                        <div class="ct-stat">
                            <span class="l"><?= $rtl?'إجمالي الساعات':'Total Hours' ?></span>
                            <span class="v"><?= $r['total_hours'] ? round((float)$r['total_hours'], 1).'h' : '—' ?></span>
                        </div>
                        <div style="margin-top:8px">
                            <div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;font-weight:700">
                                <span><?= $rtl?'نسبة الإنجاز':'Completion' ?></span>
                                <span><?= $completion ?>%</span>
                            </div>
                            <div class="progress"><div style="width:<?= $completion ?>%;background:linear-gradient(90deg, #16a34a, #22c55e)"></div></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</main>
</div>
</body>
</html>
