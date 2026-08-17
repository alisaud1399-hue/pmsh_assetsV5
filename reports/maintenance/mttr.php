<?php
/**
 * reports/maintenance/mttr.php — متوسط وقت الإصلاح (MTTR)
 * من تاريخ wo_date إلى actual_completion_date
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.maintenance.mttr');

$can_export  = can('reports.maintenance.mttr', 'export');
$excel_mode  = report_excel_mode_active('reports.maintenance.mttr');
$print_mode  = report_print_mode_active('reports.maintenance.mttr');
$print_charts = report_print_charts_mode_active('reports.maintenance.mttr');

$rtl = is_rtl();
$page_title = $rtl?'متوسط وقت الإصلاح (MTTR)':'Mean Time To Repair (MTTR)';
$active_nav = 'reports.maintenance';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الصيانة':'Maintenance Reports','url'=>BASE_URL.'/reports/maintenance/'],
    ['name'=>$rtl?'MTTR':'MTTR'],
];

global $pdo;

// MTTR حسب النوع
$by_type = $pdo->query("
    SELECT wo_type, COUNT(*) AS n,
           AVG(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS avg_days,
           MIN(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS min_days,
           MAX(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS max_days,
           AVG(work_hours_total) AS avg_hours
    FROM complaint_work_orders
    WHERE wo_date IS NOT NULL
    GROUP BY wo_type
    ORDER BY n DESC
")->fetchAll(PDO::FETCH_ASSOC);

// MTTR حسب المقاول
$by_contractor = $pdo->query("
    SELECT contractor_name, COUNT(*) AS n,
           AVG(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS avg_days
    FROM complaint_work_orders
    WHERE contractor_name IS NOT NULL AND contractor_name != ''
    GROUP BY contractor_name
    ORDER BY n DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// MTTR حسب الأسبوع (آخر 12 أسبوع)
$by_week = $pdo->query("
    SELECT YEARWEEK(wo_date, 3) AS yw, MIN(DATE(wo_date)) AS week_start,
           COUNT(*) AS n,
           AVG(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS avg_days
    FROM complaint_work_orders
    WHERE wo_date IS NOT NULL AND wo_date >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
    GROUP BY YEARWEEK(wo_date, 3)
    ORDER BY yw ASC
")->fetchAll(PDO::FETCH_ASSOC);

// إحصاء شامل
$overall = $pdo->query("
    SELECT COUNT(*) AS n,
           AVG(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS avg_days,
           MIN(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS min_days,
           MAX(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS max_days
    FROM complaint_work_orders
    WHERE wo_date IS NOT NULL
")->fetch(PDO::FETCH_ASSOC);

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
        .hero { background:linear-gradient(135deg, #78350f, #d97706); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(217,119,6,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:13px 14px; }
        .stat .l { font-size:11px; color:#64748b; font-weight:700; }
        .stat .v { font-size:24px; font-weight:900; color:#0f172a; margin-top:2px; }
        .stat .s { font-size:11px; color:#94a3b8; font-weight:700; }
        .stat.ok .v { color:#16a34a; }
        .stat.bad .v { color:#dc2626; }
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:12px; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#d97706; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        .chart { padding:14px 18px; }
        .chart-row { display:flex; align-items:center; gap:8px; padding:5px 0; font-size:12.5px; }
        .chart-row .nm { min-width:140px; color:#475569; font-weight:700; }
        .chart-row .bar { flex:1; height:18px; background:#f1f5f9; border-radius:4px; overflow:hidden; }
        .chart-row .bar > div { height:100%; }
        .chart-row .num { min-width:48px; text-align:end; font-weight:800; color:#0f172a; }
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:11px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
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
        <div class="hero-ico"><i class="fa-solid fa-clock"></i></div>
        <div>
            <h1><?= $rtl?'متوسط وقت الإصلاح (MTTR)':'Mean Time To Repair (MTTR)' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.maintenance.mttr') ?>
            </div>
            <p><?= $rtl?'من تاريخ فتح أمر العمل حتى الإكمال — حسب النوع، المقاول، الأسبوع':'From WO open to completion — by type, contractor, week' ?></p>
        </div>
        <div class="v"><?= $overall && $overall['avg_days'] !== null ? round((float)$overall['avg_days'], 1).'d' : '—' ?></div>
    </div>

    <?php if (!$overall || !$overall['n']): ?>
        <div class="sec"><div class="empty"><?= $rtl?'لا توجد أوامر عمل لقياس MTTR بعد':'No work orders yet to measure MTTR' ?></div></div>
    <?php else: ?>
        <div class="stats">
            <div class="stat">
                <div class="l"><?= $rtl?'متوسط MTTR العام':'Overall MTTR' ?></div>
                <div class="v"><?= round((float)$overall['avg_days'], 1) ?>d</div>
                <div class="s"><?= $rtl?'من ':'from '?> <?= (int)$overall['n'] ?> <?= $rtl?'أمر':'orders' ?></div>
            </div>
            <div class="stat ok">
                <div class="l"><?= $rtl?'أسرع إصلاح':'Fastest' ?></div>
                <div class="v"><?= round((float)$overall['min_days'], 1) ?>d</div>
            </div>
            <div class="stat bad">
                <div class="l"><?= $rtl?'أبطأ إصلاح':'Slowest' ?></div>
                <div class="v"><?= round((float)$overall['max_days'], 1) ?>d</div>
            </div>
        </div>

        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-tags ic"></i>
                <?= $rtl?'MTTR حسب النوع':'MTTR by Type' ?>
            </div>
            <div class="chart">
                <?php
                $max_t = max(1, max(array_column($by_type, 'avg_days')));
                foreach ($by_type as $r):
                    $pct = round($r['avg_days'] / $max_t * 100);
                    $col = $r['avg_days'] <= 7 ? '#16a34a' : ($r['avg_days'] <= 30 ? '#f59e0b' : '#dc2626');
                ?>
                    <div class="chart-row">
                        <span class="nm"><?= e($TYPE_AR[$r['wo_type']] ?? $r['wo_type']) ?> <span style="color:#94a3b8;font-size:10.5px">(<?= (int)$r['n'] ?>)</span></span>
                        <span class="bar"><div style="width:<?= $pct ?>%;background:<?= $col ?>"></div></span>
                        <span class="num"><?= round((float)$r['avg_days'], 1) ?>d</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($by_contractor): ?>
        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-building ic"></i>
                <?= $rtl?'MTTR حسب المقاول':'MTTR by Contractor' ?>
                <span class="ct"><?= count($by_contractor) ?> <?= $rtl?'مقاول':'contractors' ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?= $rtl?'المقاول':'Contractor' ?></th>
                        <th><?= $rtl?'عدد الأوامر':'WOs' ?></th>
                        <th><?= $rtl?'MTTR':'MTTR' ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($by_contractor as $r): ?>
                    <tr>
                        <td><strong><?= e($r['contractor_name']) ?></strong></td>
                        <td><?= (int)$r['n'] ?></td>
                        <td><strong><?= round((float)$r['avg_days'], 1) ?>d</strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($by_week): ?>
        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-chart-line ic"></i>
                <?= $rtl?'اتجاه MTTR (12 أسبوع)':'MTTR Trend (12 weeks)' ?>
            </div>
            <div class="chart">
                <?php
                $max_w = max(1, max(array_column($by_week, 'avg_days')));
                foreach ($by_week as $w):
                    $pct = round($w['avg_days'] / $max_w * 100);
                    $col = $w['avg_days'] <= 7 ? '#16a34a' : ($w['avg_days'] <= 30 ? '#f59e0b' : '#dc2626');
                ?>
                    <div class="chart-row">
                        <span class="nm"><?= e(date('d/m', strtotime($w['week_start']))) ?> <span style="color:#94a3b8;font-size:10.5px">(<?= (int)$w['n'] ?>)</span></span>
                        <span class="bar"><div style="width:<?= $pct ?>%;background:<?= $col ?>"></div></span>
                        <span class="num"><?= round((float)$w['avg_days'], 1) ?>d</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</main>
</div>
</body>
</html>
