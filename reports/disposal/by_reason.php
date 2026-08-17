<?php
/**
 * reports/disposal/by_reason.php — حسب السبب
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.disposal.by_reason');

$can_export  = can('reports.disposal.by_reason', 'export');
$excel_mode  = report_excel_mode_active('reports.disposal.by_reason');
$print_mode  = report_print_mode_active('reports.disposal.by_reason');
$print_charts = report_print_charts_mode_active('reports.disposal.by_reason');

$rtl = is_rtl();
$page_title = $rtl?'حسب سبب التخلص':'By Disposal Reason';
$active_nav = 'reports.disposal';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التخلص':'Disposal Reports','url'=>BASE_URL.'/reports/disposal/'],
    ['name'=>$rtl?'حسب السبب':'By Reason'],
];

global $pdo;

$rows = $pdo->query("
    SELECT reason, COUNT(*) AS n,
           COALESCE(SUM(disposal_value),0) AS total_val,
           GROUP_CONCAT(DISTINCT disposal_type) AS types
    FROM asset_disposals
    GROUP BY reason
    ORDER BY n DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total_n = array_sum(array_column($rows, 'n'));
$max_n = max(1, max(array_column($rows, 'n') ?: [1]));

$REASON_AR = ['obsolete'=>'قديم','damaged_beyond_repair'=>'تالف (لا يصلح)','end_of_life'=>'انتهى عمره','lost'=>'مفقود','replaced'=>'مُستبدل','other'=>'آخر'];
$REASON_COLOR = ['obsolete'=>'#94a3b8','damaged_beyond_repair'=>'#dc2626','end_of_life'=>'#7f1d1d','lost'=>'#f59e0b','replaced'=>'#0ea5e9','other'=>'#64748b'];
$TYPE_AR = ['scrap'=>'تكهين','destroy'=>'إتلاف','sell'=>'بيع','transfer_out'=>'نقل خارجي'];

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
        .reason-list { display:flex; flex-direction:column; gap:10px; }
        .reason-row { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:14px 16px; }
        .reason-h { display:flex; align-items:center; gap:10px; }
        .reason-dot { width:12px; height:12px; border-radius:3px; }
        .reason-name { font-size:14px; font-weight:900; color:#0f172a; flex:1; }
        .reason-num { font-size:18px; font-weight:900; color:#0f172a; }
        .reason-bar { height:5px; background:#f1f5f9; border-radius:99px; margin:8px 0; overflow:hidden; }
        .reason-bar > div { height:100%; }
        .reason-body { display:flex; align-items:center; gap:12px; font-size:12px; color:#64748b; }
        .reason-body .val { color:#16a34a; font-weight:800; }
        .reason-body .types { display:flex; gap:4px; flex-wrap:wrap; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
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
        <div class="hero-ico"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div>
            <h1><?= $rtl?'حسب سبب التخلص':'By Disposal Reason' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.disposal.by_reason') ?>
            </div>
            <p><?= $rtl?'توزيع الأسباب: قديم / تالف / انتهى عمره / مفقود / مُستبدل / آخر — مع القيم وأنواع التخلص':'Distribution by reason: obsolete / damaged / EOL / lost / replaced / other — with values + disposal types' ?></p>
        </div>
        <div class="v"><?= $total_n ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="sec"><div class="empty"><?= $rtl?'لا توجد عمليات':'No disposals yet' ?></div></div>
    <?php else: ?>
        <div class="reason-list">
            <?php foreach ($rows as $r):
                $col = $REASON_COLOR[$r['reason']] ?? '#475569';
                $pct = round($r['n'] / $max_n * 100);
                $types = array_filter(explode(',', $r['types'] ?? ''));
                $type_color = ['scrap'=>'#f59e0b','destroy'=>'#dc2626','sell'=>'#16a34a','transfer_out'=>'#0ea5e9'];
            ?>
                <div class="reason-row">
                    <div class="reason-h">
                        <div class="reason-dot" style="background:<?= $col ?>"></div>
                        <div class="reason-name"><?= e($REASON_AR[$r['reason']] ?? $r['reason']) ?></div>
                        <div class="reason-num"><?= (int)$r['n'] ?></div>
                    </div>
                    <div class="reason-bar"><div style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                    <div class="reason-body">
                        <span><?= $total_n > 0 ? round($r['n'] / $total_n * 100, 1) : 0 ?>% <?= $rtl?'من الإجمالي':'of total' ?></span>
                        <span class="val"><?= number_format((float)$r['total_val'], 0) ?> SAR</span>
                        <span class="types">
                            <?php foreach ($types as $t):
                                $tc = $type_color[$t] ?? '#475569';
                            ?>
                                <span class="pill" style="background:<?= $tc ?>22;color:<?= $tc ?>"><?= e($TYPE_AR[$t] ?? $t) ?></span>
                            <?php endforeach; ?>
                        </span>
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
