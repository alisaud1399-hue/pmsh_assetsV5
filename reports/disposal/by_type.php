<?php
/**
 * reports/disposal/by_type.php — حسب النوع
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.disposal.by_type');

$can_export  = can('reports.disposal.by_type', 'export');
$excel_mode  = report_excel_mode_active('reports.disposal.by_type');
$print_mode  = report_print_mode_active('reports.disposal.by_type');
$print_charts = report_print_charts_mode_active('reports.disposal.by_type');

$rtl = is_rtl();
$page_title = $rtl?'حسب نوع التخلص':'By Disposal Type';
$active_nav = 'reports.disposal';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التخلص':'Disposal Reports','url'=>BASE_URL.'/reports/disposal/'],
    ['name'=>$rtl?'حسب النوع':'By Type'],
];

global $pdo;

$rows = $pdo->query("
    SELECT disposal_type, COUNT(*) AS n,
           COALESCE(SUM(disposal_value),0) AS total_val,
           COALESCE(AVG(disposal_value),0) AS avg_val,
           MIN(disposal_date) AS first_date,
           MAX(disposal_date) AS last_date
    FROM asset_disposals
    GROUP BY disposal_type
    ORDER BY n DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total_n = array_sum(array_column($rows, 'n'));
$total_val = array_sum(array_column($rows, 'total_val'));
$max_n = max(1, max(array_column($rows, 'n') ?: [1]));

$TYPE_AR = ['scrap'=>'تكهين','destroy'=>'إتلاف','sell'=>'بيع','transfer_out'=>'نقل خارجي'];
$TYPE_COLOR = ['scrap'=>'#f59e0b','destroy'=>'#dc2626','sell'=>'#16a34a','transfer_out'=>'#0ea5e9'];
$TYPE_DESC = [
    'scrap'=>'إخراج الأصل من الخدمة (تكهين) — الأجهزة اللي ما لها قيمة مادية',
    'destroy'=>'إتلاف فعلي للأصل — للأجهزة الحساسة أو اللي ما تنباع',
    'sell'=>'بيع الأصل — له قيمة متبقية (مزاد، سوق، إلخ)',
    'transfer_out'=>'نقل الأصل خارج المستشفى (لجهة أخرى) — بدون بيع',
];

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
        .type-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(380px, 1fr)); gap:12px; }
        .type-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .type-h { display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1.5px solid #f1f5f9; }
        .type-dot { width:12px; height:12px; border-radius:3px; }
        .type-name { font-size:15px; font-weight:900; color:#0f172a; flex:1; }
        .type-pct { background:#7c3aed22; color:#7c3aed; padding:3px 8px; border-radius:5px; font-size:11px; font-weight:800; }
        .type-bar { height:6px; background:#f1f5f9; }
        .type-bar > div { height:100%; }
        .type-body { padding:14px 16px; }
        .type-desc { font-size:12px; color:#64748b; line-height:1.6; padding:8px 0; border-bottom:1px dashed #e2e8f0; }
        .type-stats { display:grid; grid-template-columns:repeat(2, 1fr); gap:8px; margin-top:8px; }
        .type-stat { background:#f8fafc; padding:6px 8px; border-radius:6px; text-align:center; }
        .type-stat .v { font-size:16px; font-weight:900; color:#0f172a; }
        .type-stat .l { font-size:10px; color:#64748b; font-weight:700; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/disposal/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-list-check"></i></div>
        <div>
            <h1><?= $rtl?'حسب نوع التخلص':'By Disposal Type' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.disposal.by_type') ?>
            </div>
            <p><?= $rtl?'التوزيع التفصيلي: تكهين / إتلاف / بيع / نقل خارجي — مع القيم والتواريخ':'Detailed: scrap / destroy / sell / transfer-out — with values + dates' ?></p>
        </div>
        <div class="v"><?= $total_n ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="sec"><div class="empty"><?= $rtl?'لا توجد عمليات':'No disposals yet' ?></div></div>
    <?php else: ?>
        <div class="type-grid">
            <?php foreach ($rows as $r):
                $col = $TYPE_COLOR[$r['disposal_type']] ?? '#475569';
                $pct = round($r['n'] / $max_n * 100);
                $total_pct = $total_n > 0 ? round($r['n'] / $total_n * 100, 1) : 0;
            ?>
                <div class="type-card">
                    <div class="type-h">
                        <div class="type-dot" style="background:<?= $col ?>"></div>
                        <div class="type-name"><?= e($TYPE_AR[$r['disposal_type']] ?? $r['disposal_type']) ?></div>
                        <span class="type-pct"><?= $total_pct ?>%</span>
                    </div>
                    <div class="type-bar"><div style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                    <div class="type-body">
                        <div class="type-desc"><?= e($TYPE_DESC[$r['disposal_type']] ?? '') ?></div>
                        <div class="type-stats">
                            <div class="type-stat">
                                <div class="v"><?= (int)$r['n'] ?></div>
                                <div class="l"><?= $rtl?'عملية':'ops' ?></div>
                            </div>
                            <div class="type-stat">
                                <div class="v" style="color:#16a34a"><?= number_format((float)$r['total_val'], 0) ?></div>
                                <div class="l"><?= $rtl?'قيمة إجمالية':'total SAR' ?></div>
                            </div>
                            <div class="type-stat">
                                <div class="v"><?= number_format((float)$r['avg_val'], 0) ?></div>
                                <div class="l"><?= $rtl?'متوسط':'avg SAR' ?></div>
                            </div>
                            <div class="type-stat">
                                <div class="v" style="font-size:11.5px"><?= $r['first_date'] ? date('m/Y', strtotime($r['first_date'])) : '—' ?> → <?= $r['last_date'] ? date('m/Y', strtotime($r['last_date'])) : '—' ?></div>
                                <div class="l"><?= $rtl?'الفترة':'period' ?></div>
                            </div>
                        </div>
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
