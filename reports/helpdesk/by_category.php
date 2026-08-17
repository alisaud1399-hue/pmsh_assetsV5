<?php
/**
 * reports/helpdesk/by_category.php — حسب التصنيف
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.helpdesk.by_category');

$can_export  = can('reports.helpdesk.by_category', 'export');
$excel_mode  = report_excel_mode_active('reports.helpdesk.by_category');
$print_mode  = report_print_mode_active('reports.helpdesk.by_category');
$print_charts = report_print_charts_mode_active('reports.helpdesk.by_category');

$rtl = is_rtl();
$page_title = $rtl?'التذاكر حسب التصنيف':'Tickets by Category';
$active_nav = 'reports.helpdesk';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التذاكر':'Helpdesk Reports','url'=>BASE_URL.'/reports/helpdesk/'],
    ['name'=>$rtl?'حسب التصنيف':'By Category'],
];

global $pdo;

$rows = $pdo->query("
    SELECT c.id, c.name_ar, c.icon,
           COUNT(t.id) AS total,
           SUM(t.status IN ('new','in_review','awaiting_user')) AS open_n,
           SUM(t.status='closed') AS closed_n,
           SUM(t.sla_breached=1) AS breached,
           AVG(TIMESTAMPDIFF(MINUTE, t.created_at, COALESCE(t.resolved_at, t.closed_at))) / 60 AS avg_hrs
    FROM helpdesk_categories c
    LEFT JOIN helpdesk_tickets t ON t.category_id = c.id
    WHERE c.is_active=1
    GROUP BY c.id, c.name_ar, c.icon
    HAVING total > 0
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

$grand_total = array_sum(array_column($rows, 'total'));
$max_total = max(1, max(array_column($rows, 'total') ?: [1]));

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
        .hero { background:linear-gradient(135deg, #14532d, #16a34a); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(22,163,74,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .cat-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:12px; }
        .cat-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .cat-h { display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .cat-ico { width:40px; height:40px; border-radius:10px; background:linear-gradient(135deg, #14532d, #16a34a); color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; }
        .cat-name { font-size:14px; font-weight:900; color:#0f172a; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .cat-pct { background:#16a34a22; color:#16a34a; padding:3px 8px; border-radius:5px; font-size:11px; font-weight:800; }
        .cat-bar { height:5px; background:#f1f5f9; }
        .cat-bar > div { height:100%; background:linear-gradient(90deg, #16a34a, #22c55e); }
        .cat-body { padding:12px 16px; }
        .cat-stats { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; }
        .cat-stats .mini { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:6px 8px; text-align:center; }
        .cat-stats .mini .v { font-size:16px; font-weight:900; color:#0f172a; }
        .cat-stats .mini .l { font-size:10px; color:#64748b; font-weight:700; }
        .cat-stats .mini .v.bad { color:#dc2626; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
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
        <div class="hero-ico"><i class="fa-solid fa-sitemap"></i></div>
        <div>
            <h1><?= $rtl?'التذاكر حسب التصنيف':'Tickets by Category' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.helpdesk.by_category') ?>
            </div>
            <p><?= $rtl?'توزيع التذاكر على التصنيفات + مفتوحة + مغلقة + تجاوزات SLA + متوسط الحل':'Per-category distribution + open + closed + SLA breaches + avg resolution' ?></p>
        </div>
        <div class="v"><?= $grand_total ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="cat-card"><div class="empty"><?= $rtl?'لا توجد تذاكر بعد':'No tickets yet' ?></div></div>
    <?php else: ?>
        <div class="cat-grid">
            <?php foreach ($rows as $r):
                $pct = round($r['total'] / $max_total * 100);
                $total_pct = $grand_total > 0 ? round($r['total'] / $grand_total * 100, 1) : 0;
                $icon = $r['icon'] ?: 'tag';
            ?>
                <div class="cat-card">
                    <div class="cat-h">
                        <div class="cat-ico"><i class="fa-solid fa-<?= e($icon) ?>"></i></div>
                        <div class="cat-name"><?= e($r['name']) ?></div>
                        <span class="cat-pct"><?= $total_pct ?>%</span>
                    </div>
                    <div class="cat-bar"><div style="width:<?= $pct ?>%"></div></div>
                    <div class="cat-body">
                        <div class="cat-stats">
                            <div class="mini"><div class="v"><?= (int)$r['total'] ?></div><div class="l"><?= $rtl?'إجمالي':'Total' ?></div></div>
                            <div class="mini"><div class="v" style="color:#0ea5e9"><?= (int)$r['open_n'] ?></div><div class="l"><?= $rtl?'مفتوحة':'Open' ?></div></div>
                            <div class="mini"><div class="v" style="color:#16a34a"><?= (int)$r['closed_n'] ?></div><div class="l"><?= $rtl?'مغلقة':'Closed' ?></div></div>
                        </div>
                        <?php if ((int)$r['breached'] > 0): ?>
                            <div style="margin-top:8px">
                                <span style="background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:5px;font-size:10.5px;font-weight:800">
                                    <i class="fa-solid fa-fire"></i> <?= (int)$r['breached'] ?> <?= $rtl?'تجاوز SLA':'SLA breached' ?>
                                </span>
                            </div>
                        <?php endif; ?>
                        <?php if ($r['avg_hrs'] !== null): ?>
                            <div style="margin-top:8px;font-size:11px;color:#94a3b8">
                                <i class="fa-regular fa-clock"></i>
                                <?= $rtl?'متوسط الحل: ':'Avg resolution: '?><?= round((float)$r['avg_hrs'], 1) ?>h
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</main>
</div>
</body>
</html>
