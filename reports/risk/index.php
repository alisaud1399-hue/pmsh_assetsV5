<?php
/**
 * Risk Reports Hub — landing page for all risk-related reports
 * Pattern: 4 sub-reports (distribution, funding_gap, data_quality, old_assets)
 *          + Recent activity + distribution overview
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/report_helpers.php';
require_once __DIR__ . '/../../includes/risk_helpers.php';

page_guard('reports.risk', 'view');

$can_export  = can('reports.risk', 'export');
$excel_mode  = report_excel_mode_active('reports.risk');
$print_mode  = report_print_mode_active('reports.risk');
$print_charts = report_print_charts_mode_active('reports.risk');

global $pdo;
$dist = risk_get_distribution($pdo);
$dq = risk_get_data_quality($pdo);
$top_critical = risk_get_top($pdo, 5);
$old_assets = risk_get_old_assets($pdo, null, 5);

$total_funding = $dist['total_funding'];
$critical_count = $dist['bands']['critical'];
$high_count = $dist['bands']['high'];
$medium_count = $dist['bands']['medium'];
$low_count = $dist['bands']['low'];
$unscored_count = $dist['bands']['unscored'];
$total_count = $dist['total'];

$reports = [
    [
        'title' => 'توزيع المخاطر',
        'title_en' => 'Risk Distribution',
        'desc' => 'نظرة شاملة على توزيع الأصول حسب مستوى المخاطرة (Critical/High/Medium/Low).',
        'icon' => 'fa-chart-pie',
        'color' => '#dc2626',
        'url' => 'distribution.php',
        'kpi' => "$critical_count / $high_count",
        'kpi_label' => 'Critical / High',
    ],
    [
        'title' => 'فجوة التمويل',
        'title_en' => 'Funding Gap',
        'desc' => 'الأصول الحرجة والمرتفعة الخطورة مع تكاليف الاستبدال التقديرية. لاتخاذ قرارات الإحلال.',
        'icon' => 'fa-sack-dollar',
        'color' => '#f97316',
        'url' => 'funding_gap.php',
        'kpi' => number_format($total_funding, 0),
        'kpi_label' => 'ر.س (مجموع Critical+High)',
    ],
    [
        'title' => 'جودة البيانات',
        'title_en' => 'Data Quality',
        'desc' => 'نسبة اكتمال حقول تقييم المخاطر لكل أصل. تساعد على تحديد الأصول التي تحتاج تقييم.',
        'icon' => 'fa-clipboard-check',
        'color' => '#0ea5e9',
        'url' => 'data_quality.php',
        'kpi' => $dq['complete_100'] . '/' . $total_count,
        'kpi_label' => '100% / المجموع',
    ],
    [
        'title' => 'الأصول المتقادمة',
        'title_en' => 'Old Assets',
        'desc' => 'الأصول التي تجاوزت عمرها الافتراضي (useful_life_years). مرشحة للإحلال.',
        'icon' => 'fa-hourglass-end',
        'color' => '#7c3aed',
        'url' => 'old_assets.php',
        'kpi' => count($old_assets) . '+',
        'kpi_label' => 'عبر العمر الافتراضي',
    ],
];

$rtl = is_rtl();

/* === Index/Hub Export === */
if ($print_mode) {
    $t = $rtl ? $page_title : $page_title;
    $s = $rtl ? 'قائمة بكل التقارير الفرعية' : 'List of all sub-reports';
    report_print_head($t, $s, ['التاريخ'=>date('Y-m-d'),'المستخدم'=>user_name()?:'-','المستشفى'=>get_setting('hospital_name','PMSH')]);
    $h_name = $rtl ? 'اسم التقرير' : 'Report Name';
    $h_desc = $rtl ? 'الوصف' : 'Description';
    $h_kpi = $rtl ? 'المؤشر' : 'KPI';
    $h_avail = $rtl ? 'متاح' : 'Available';
    echo '<table><thead><tr><th>'.htmlspecialchars($h_name).'</th><th>'.htmlspecialchars($h_desc).'</th><th>'.htmlspecialchars($h_kpi).'</th><th>'.htmlspecialchars($h_avail).'</th></tr></thead><tbody>';
    foreach ($reports as $r) {
        $avail = !empty($r['available']) ? ($rtl?'نعم':'Yes') : ($rtl?'قريباً':'Soon');
        $name = $rtl ? ($r['title_ar'] ?? '') : ($r['title_en'] ?? '');
        $desc = $rtl ? ($r['desc_ar'] ?? '') : ($r['desc_en'] ?? '');
        echo '<tr><td>'.htmlspecialchars($name).'</td><td>'.htmlspecialchars($desc).'</td><td>'.htmlspecialchars($r['kpi'] ?? '').'</td><td>'.htmlspecialchars($avail).'</td></tr>';
    }
    echo '</tbody></table>';
    report_print_foot();
}

if ($print_charts) {
    $t = $rtl ? $page_title : $page_title;
    $kpis_arr = [];
    if (!empty($stats)) {
        $kpis_arr = [
            ['v'=>number_format($stats['total'] ?? 0),'l'=>$rtl?'إجمالي':'Total'],
            ['v'=>number_format($stats['open'] ?? $stats['active'] ?? 0),'l'=>$rtl?'مفتوح':'Open'],
            ['v'=>number_format($stats['closed'] ?? $stats['resolved'] ?? 0),'l'=>$rtl?'مغلق':'Closed'],
            ['v'=>number_format($stats['critical'] ?? $stats['criticality_A'] ?? 0),'l'=>$rtl?'حرج':'Critical'],
        ];
    }
    report_print_charts_head($t, $kpis_arr);
    echo '<div class="pc-section"><h3>'.htmlspecialchars($rtl?'التقارير الفرعية':'Sub-reports').'</h3>';
    $items = [];
    foreach ($reports as $r) {
        $items[] = ['name'=>$rtl ? ($r['title_ar'] ?? '') : ($r['title_en'] ?? ''), 'value'=>(int)preg_replace('/\D/', '', $r['kpi'] ?? '0')];
    }
    report_print_bar_chart($items);
    echo '</div>';
    report_print_charts_foot();
}

if ($excel_mode) {
    $rows = [];
    $h_name = $rtl ? 'اسم التقرير' : 'Report Name';
    $h_desc = $rtl ? 'الوصف' : 'Description';
    $h_kpi = $rtl ? 'المؤشر' : 'KPI';
    $h_avail = $rtl ? 'متاح' : 'Available';
    foreach ($reports as $r) {
        $avail = !empty($r['available']) ? ($rtl?'نعم':'Yes') : ($rtl?'قريباً':'Soon');
        $rows[] = [$h_name=>($rtl ? ($r['title_ar'] ?? '') : ($r['title_en'] ?? '')), $h_desc=>($rtl ? ($r['desc_ar'] ?? '') : ($r['desc_en'] ?? '')), $h_kpi=>($r['kpi'] ?? ''), $h_avail=>$avail];
    }
    report_export_excel('reports_hub_'.date('Y-m-d').'.csv', [$h_name, $h_desc, $h_kpi, $h_avail], $rows, $page_title);
}?>
<!DOCTYPE html>
<html lang="ar" dir="<?= lang_dir() ?>">
<head>
<meta charset="UTF-8">
<title>مركز تقارير المخاطر — Risk Reports</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
/* 🌟 التعديل السحري لتوحيد الخطوط 🌟 */
body, button, input, select, textarea, th, td, h1, h2, h3, h4, p, div, span, a { 
    font-family: 'Tajawal', sans-serif !important; 
}
/* حماية أيقونات النظام من تغيير الخط */
[class^="fa-"], [class*=" fa-"] {
    font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", "Font Awesome 6 Solid" !important;
}

.rh-wrap { max-width: 100%; margin: 0; padding: 16px 20px; box-sizing: border-box; }
.rh-hero {
    background: linear-gradient(135deg, #1e293b 0%, #7c2d12 50%, #b91c1c 100%);
    color: white; border-radius: 14px; padding: 22px 26px; margin-bottom: 18px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.10);
    display: flex; align-items: center; gap: 20px; flex-wrap: wrap;
    position: relative; overflow: hidden;
}
.rh-hero::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 350px; height: 350px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
    pointer-events: none;
}
.rh-hero-ico {
    width: 64px; height: 64px; background: rgba(255,255,255,0.15); border-radius: 14px;
    display: flex; align-items: center; justify-content: center; font-size: 28px;
    position: relative; z-index: 1; flex-shrink: 0;
}
.rh-hero-text { flex: 1; min-width: 200px; position: relative; z-index: 1; }
.rh-hero h1 { margin: 0 0 4px 0; font-size: 20px; font-weight: 700; line-height: 1.3; }
.rh-hero p { margin: 0; opacity: 0.85; font-size: 12.5px; line-height: 1.5; }
.rh-hero .stats { display: flex; gap: 10px; position: relative; z-index: 1; }
.rh-hero .stat { text-align: center; background: rgba(255,255,255,0.12); padding: 8px 14px; border-radius: 8px; }
.rh-hero .stat-v { font-size: 18px; font-weight: 700; }
.rh-hero .stat-l { font-size: 10px; opacity: 0.85; }
.rh-kpis { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 18px; }
@media (max-width: 1100px) { .rh-kpis { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 700px) { .rh-kpis { grid-template-columns: repeat(2, 1fr); } }
.rh-kpi {
    background: white; border-radius: 10px; padding: 12px 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0;
    text-align: center; transition: transform 0.2s, box-shadow 0.2s;
}
.rh-kpi:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
.rh-kpi .kpi-v { font-size: 22px; font-weight: 700; margin: 4px 0 2px; line-height: 1.1; }
.rh-kpi .kpi-l { font-size: 11px; color: #64748b; font-weight: 600; }
.rh-kpi.critical { border-top: 3px solid #dc2626; }
.rh-kpi.critical .kpi-v { color: #dc2626; }
.rh-kpi.high { border-top: 3px solid #f97316; }
.rh-kpi.high .kpi-v { color: #f97316; }
.rh-kpi.medium { border-top: 3px solid #eab308; }
.rh-kpi.medium .kpi-v { color: #ca8a04; }
.rh-kpi.low { border-top: 3px solid #22c55e; }
.rh-kpi.low .kpi-v { color: #16a34a; }
.rh-kpi.unscored { border-top: 3px solid #94a3b8; }
.rh-kpi.unscored .kpi-v { color: #64748b; }
.rh-section-title {
    font-size: 16px; font-weight: 700; color: #1e293b; margin: 20px 0 12px;
    display: flex; align-items: center; gap: 8px;
}
.rh-section-title i { color: #7c2d12; }
.rh-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
@media (max-width: 800px) { .rh-cards { grid-template-columns: 1fr; } }
.rh-card {
    background: white; border-radius: 12px; padding: 16px 18px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0;
    display: flex; gap: 14px; align-items: start; transition: all 0.2s;
    text-decoration: none; color: inherit;
}
.rh-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); border-color: #cbd5e1; }
.rh-card-ico {
    width: 46px; height: 46px; border-radius: 10px; display: flex; align-items: center;
    justify-content: center; font-size: 20px; color: white; flex-shrink: 0;
}
.rh-card-body { flex: 1; }
.rh-card-title { font-size: 14px; font-weight: 700; color: #0f172a; margin-bottom: 1px; }
.rh-card-en { font-size: 10.5px; color: #94a3b8; font-weight: 600; margin-bottom: 4px; }
.rh-card-desc { font-size: 11.5px; color: #475569; line-height: 1.5; }
.rh-card-kpi { display: inline-block; background: #f1f5f9; padding: 3px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 700; color: #1e293b; margin-top: 6px; }
.rh-recent { background: white; border-radius: 10px; padding: 14px; margin-top: 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
.rh-recent-h { font-size: 13px; font-weight: 700; color: #1e293b; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
.rh-recent-item { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
.rh-recent-item:last-child { border-bottom: 0; }
.rh-band { display: inline-block; padding: 2px 7px; border-radius: 4px; font-weight: 700; font-size: 9.5px; color: white; min-width: 48px; text-align: center; }
.rh-band.critical { background: #dc2626; }
.rh-band.high { background: #f97316; }
.rh-band.medium { background: #eab308; color: #0f172a; }
.rh-band.low { background: #22c55e; }
.rh-band.unscored { background: #cbd5e1; color: #475569; }
.rh-back { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: #f1f5f9; color: #475569; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; }
.rh-back:hover { background: #e2e8f0; }
.rh-guide-link { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: linear-gradient(135deg, #7c2d12, #b91c1c); color: white; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; margin-inline-start: 6px; }
.rh-guide-link:hover { opacity: 0.9; }
.rh-link-all { display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; color: #7c2d12; text-decoration: none; font-weight: 600; font-size: 12px; }
.rh-link-all:hover { text-decoration: underline; }
</style>
</head>
<body class="app-layout">
<?php
$page_title = 'مركز تقارير المخاطر — Risk Reports';
$page_icon  = 'fa-triangle-exclamation';
include BASE_PATH . '/includes/sidebar.php';
?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="rh-wrap">

<a href="<?= BASE_URL ?>/dashboard.php" class="rh-back">
    <i class="fa-solid fa-arrow-<?= $rtl ? 'right' : 'left' ?>"></i> العودة للوحة التحكم
</a>
<a href="<?= BASE_URL ?>/presentations/risk_score_guide.html" target="_blank" class="rh-guide-link" title="شرح وافي لكل جزئية في النظام">
    <i class="fa-solid fa-book-open"></i> شرح تفصيلي (18 شريحة)
</a>

<div class="rh-hero">
    <div class="rh-hero-ico"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div>
        <h1>مركز تقارير المخاطر</h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.risk') ?>
            </div>
        <p>تقارير تقييم مخاطر الأصول (Risk Score) لاتخاذ قرارات الإحلال والصيانة الاستباقية. النظام يطبق 7 عوامل مرجحة (0-100 درجة) — مطابق لنموذج وزارة الصحة.</p>
    </div>
    <div class="stats">
        <div class="stat"><div class="stat-v"><?= number_format($total_count) ?></div><div class="stat-l">إجمالي الأصول</div></div>
        <div class="stat"><div class="stat-v"><?= number_format($total_funding, 0) ?></div><div class="stat-l">فجوة التمويل</div></div>
    </div>
</div>

<div class="rh-kpis">
    <div class="rh-kpi critical">
        <i class="fa-solid fa-circle-exclamation"></i>
        <div class="kpi-v"><?= number_format($critical_count) ?></div>
        <div class="kpi-l">حرج (Critical ≥ 70)</div>
    </div>
    <div class="rh-kpi high">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div class="kpi-v"><?= number_format($high_count) ?></div>
        <div class="kpi-l">مرتفع (High ≥ 50)</div>
    </div>
    <div class="rh-kpi medium">
        <i class="fa-solid fa-circle-info"></i>
        <div class="kpi-v"><?= number_format($medium_count) ?></div>
        <div class="kpi-l">متوسط (Medium ≥ 30)</div>
    </div>
    <div class="rh-kpi low">
        <i class="fa-solid fa-circle-check"></i>
        <div class="kpi-v"><?= number_format($low_count) ?></div>
        <div class="kpi-l">منخفض (Low)</div>
    </div>
    <div class="rh-kpi unscored">
        <i class="fa-solid fa-circle-question"></i>
        <div class="kpi-v"><?= number_format($unscored_count) ?></div>
        <div class="kpi-l">غير مُقيَّم</div>
    </div>
</div>

<div class="rh-section-title">
    <i class="fa-solid fa-chart-column"></i> التقارير الفرعية
</div>

<div class="rh-cards">
    <?php foreach ($reports as $r): ?>
    <a href="<?= htmlspecialchars($r['url']) ?>" class="rh-card">
        <div class="rh-card-ico" style="background:<?= $r['color'] ?>">
            <i class="fa-solid <?= $r['icon'] ?>"></i>
        </div>
        <div class="rh-card-body">
            <div class="rh-card-title"><?= $r['title'] ?></div>
            <div class="rh-card-en"><?= $r['title_en'] ?></div>
            <div class="rh-card-desc"><?= $r['desc'] ?></div>
            <span class="rh-card-kpi"><?= $r['kpi'] ?> — <?= $r['kpi_label'] ?></span>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php if (!empty($top_critical)): ?>
<div class="rh-recent">
    <div class="rh-recent-h"><i class="fa-solid fa-fire"></i> أعلى 5 أصول خطورة (Critical / High)</div>
    <?php foreach ($top_critical as $a): ?>
    <div class="rh-recent-item">
        <span class="rh-band <?= $a['risk_band'] ?>"><?= $a['risk_band'] === 'critical' ? 'حرج' : 'مرتفع' ?></span>
        <div style="flex:1">
            <div style="font-weight:600;color:#1e293b"><?= htmlspecialchars($a['tag_number'] ?: '—') ?> — <?= htmlspecialchars(truncate($a['description'], 60)) ?></div>
            <div style="font-size:11px;color:#64748b"><?= htmlspecialchars($a['cat_level1'] ?: '—') ?> · Score: <strong><?= number_format($a['total_risk_score'], 0) ?></strong> · Funding: <strong><?= number_format($a['funding_gap'], 0) ?></strong> ر.س</div>
        </div>
        <span style="font-size:11px;color:#94a3b8"><?= $a['last_computed_at'] ? date('Y-m-d', strtotime($a['last_computed_at'])) : '—' ?></span>
    </div>
    <?php endforeach; ?>
    <a href="funding_gap.php" class="rh-link-all">عرض كل الأصول الحرجة <i class="fa-solid fa-arrow-<?= $rtl ? 'left' : 'right' ?>"></i></a>
</div>
<?php endif; ?>

</div>
</main>
</div>
</body>
</html>