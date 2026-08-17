<?php
/**
 * reports/helpdesk/by_status.php — حسب الحالة
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.helpdesk.by_status');

$can_export  = can('reports.helpdesk.by_status', 'export');
$excel_mode  = report_excel_mode_active('reports.helpdesk.by_status');
$print_mode  = report_print_mode_active('reports.helpdesk.by_status');
$print_charts = report_print_charts_mode_active('reports.helpdesk.by_status');

$rtl = is_rtl();
$page_title = $rtl?'التذاكر حسب الحالة':'Tickets by Status';
$active_nav = 'reports.helpdesk';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التذاكر':'Helpdesk Reports','url'=>BASE_URL.'/reports/helpdesk/'],
    ['name'=>$rtl?'حسب الحالة':'By Status'],
];

global $pdo;

$rows = $pdo->query("
    SELECT status, priority, COUNT(*) AS cnt,
           AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(resolved_at, closed_at))) / 60 AS avg_hrs
    FROM helpdesk_tickets
    GROUP BY status, priority
    ORDER BY status, FIELD(priority,'critical','high','medium','low')
")->fetchAll(PDO::FETCH_ASSOC);

$totals = [];
foreach ($rows as $r) $totals[$r['status']] = ($totals[$r['status']] ?? 0) + (int)$r['cnt'];
arsort($totals);
$max_total = max(1, max($totals));

$STATUS_AR = ['new'=>'جديدة','in_review'=>'قيد المراجعة','awaiting_user'=>'بانتظار','closed'=>'مغلقة'];
$STATUS_COLOR = ['new'=>'#0ea5e9','in_review'=>'#7c3aed','awaiting_user'=>'#f59e0b','closed'=>'#16a34a'];
$PRIORITY_AR = ['critical'=>'حرجة','high'=>'عالية','medium'=>'متوسطة','low'=>'منخفضة'];
$PRIORITY_COLOR = ['critical'=>'#dc2626','high'=>'#f59e0b','medium'=>'#0ea5e9','low'=>'#1565C0'];

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
        .hero { background:linear-gradient(135deg, #4c1d95, #7c3aed); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(124,58,237,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:12px; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#7c3aed; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        .st-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px; padding:14px 18px; }
        .st-card { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px; padding:12px 14px; }
        .st-card .h { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:800; }
        .st-card .h .d { width:10px; height:10px; border-radius:50%; }
        .st-card .num { font-size:24px; font-weight:900; margin-top:4px; color:#0f172a; }
        .st-card .bar { height:5px; background:#e2e8f0; border-radius:99px; margin-top:6px; overflow:hidden; }
        .st-card .bar > div { height:100%; }
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:11px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .pill { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10.5px; font-weight:800; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/helpdesk/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-list-check"></i></div>
        <div>
            <h1><?= $rtl?'التذاكر حسب الحالة':'Tickets by Status' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.helpdesk.by_status') ?>
            </div>
            <p><?= $rtl?'التوزيع: حالة × أولوية + متوسط الأوقات':'Distribution: status × priority + avg times' ?></p>
        </div>
        <div class="v"><?= array_sum($totals) ?></div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-chart-pie ic"></i>
            <?= $rtl?'ملخص حسب الحالة':'Summary by Status' ?>
        </div>
        <div class="st-cards">
            <?php foreach ($totals as $st => $cnt):
                $col = $STATUS_COLOR[$st] ?? '#475569';
                $pct = round($cnt / $max_total * 100);
            ?>
                <div class="st-card">
                    <div class="h"><span class="d" style="background:<?= $col ?>"></span> <?= e($STATUS_AR[$st] ?? $st) ?></div>
                    <div class="num"><?= $cnt ?></div>
                    <div class="bar"><div style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-table ic"></i>
            <?= $rtl?'التفصيل حسب الحالة/الأولوية':'Detail by Status/Priority' ?>
            <span class="ct"><?= count($rows) ?> <?= $rtl?'صف':'rows' ?></span>
        </div>
        <table>
            <thead>
                <tr>
                    <th><?= $rtl?'الحالة':'Status' ?></th>
                    <th><?= $rtl?'الأولوية':'Priority' ?></th>
                    <th><?= $rtl?'العدد':'Count' ?></th>
                    <th><?= $rtl?'متوسط الحل':'Avg Resolution' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="4" style="text-align:center;padding:20px;color:#94a3b8">—</td></tr>
            <?php else: foreach ($rows as $r):
                $scol = $STATUS_COLOR[$r['status']] ?? '#475569';
                $pcol = $PRIORITY_COLOR[$r['priority']] ?? '#475569';
            ?>
                <tr>
                    <td><span class="pill" style="background:<?= $scol ?>22;color:<?= $scol ?>"><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></span></td>
                    <td><span class="pill" style="background:<?= $pcol ?>22;color:<?= $pcol ?>"><?= e($PRIORITY_AR[$r['priority']] ?? $r['priority']) ?></span></td>
                    <td><strong><?= (int)$r['cnt'] ?></strong></td>
                    <td><?= $r['avg_hrs'] !== null ? round((float)$r['avg_hrs'], 1).'h' : '—' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</main>
</div>
</body>
</html>
