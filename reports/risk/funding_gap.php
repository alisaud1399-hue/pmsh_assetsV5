<?php
/**
 * Funding Gap Report — assets in Critical + High bands with replacement cost
 * Helps prioritize which assets to replace first.
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
$filter_band = $_GET['band'] ?? 'critical';
$filter_crit = $_GET['criticality'] ?? '';

$where = "a.risk_band IN ('critical','high')";
$params = [];
if ($filter_band === 'critical') $where = "a.risk_band = 'critical'";
elseif ($filter_band === 'high') $where = "a.risk_band = 'high'";
if ($filter_crit) { $where .= " AND a.criticality_class = ?"; $params[] = $filter_crit; }

$sql = "SELECT a.id, a.tag_number, a.description, a.cat_level1, a.criticality_class,
               a.condition_status, a.operational_pressure, a.downtime_impact,
               a.utilization_rate, a.breakdowns_12m, a.maintenance_cost_ytd,
               a.total_risk_score, a.risk_band, a.funding_gap, a.cost,
               a.date_placed_in_service, a.last_computed_at,
               d.name AS dept_name
        FROM assets a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE $where
        ORDER BY a.funding_gap DESC, a.total_risk_score DESC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_gap = array_sum(array_column($rows, 'funding_gap'));
$total_cost = array_sum(array_column($rows, 'cost'));
$rtl = is_rtl();

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
<html lang="ar" dir="<?= lang_dir() ?>">
<head>
<meta charset="UTF-8">
<title>فجوة التمويل — Funding Gap</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
/* 🌟 التوحيد السحري للخطوط 🌟 */
body, button, input, select, textarea, th, td, h1, h2, h3, h4, p, div, span, a, strong { 
    font-family: 'Tajawal', sans-serif !important; 
}
[class^="fa-"], [class*=" fa-"] {
    font-family: "Font Awesome 6 Free", "Font Awesome 6 Brands", "Font Awesome 6 Solid" !important;
}

.fg-wrap { max-width: 100%; margin: 0; padding: 16px 20px; box-sizing: border-box; }
.fg-hero {
    background: linear-gradient(135deg, #1e293b 0%, #7c2d12 50%, #b91c1c 100%);
    color: white; border-radius: 14px; padding: 18px 24px; margin-bottom: 16px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.10);
}
.fg-hero h1 { margin: 0 0 3px 0; font-size: 19px; font-weight: 700; }
.fg-hero p { margin: 0; opacity: 0.85; font-size: 12.5px; }
.fg-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
@media (max-width: 700px) { .fg-stats { grid-template-columns: 1fr; } }
.fg-stat { background: white; border-radius: 10px; padding: 12px 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; border-top: 3px solid #f97316; }
.fg-stat .v { font-size: 22px; font-weight: 800; color: #1e293b; margin: 3px 0; }
.fg-stat .l { font-size: 10.5px; color: #64748b; font-weight: 600; }
.fg-filters { background: white; border-radius: 10px; padding: 10px 16px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
.fg-filters form { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
.fg-filters .fld { display: flex; flex-direction: column; gap: 3px; }
.fg-filters label { font-size: 10.5px; color: #64748b; font-weight: 600; }
.fg-filters select { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 5px; font-size: 12px; }
.fg-filters button { padding: 7px 14px; background: #1e293b; color: white; border: 0; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 12px; }
.fg-table { background: white; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; overflow: auto; }
.fg-table table { width: 100%; border-collapse: collapse; font-size: 12px; }
.fg-table th { background: #f8fafc; color: #475569; padding: 8px 10px; text-align: start; font-weight: 700; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
.fg-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }
.fg-table tr:hover td { background: #f8fafc; }
.fg-band { display: inline-block; padding: 2px 7px; border-radius: 4px; font-weight: 700; font-size: 10px; color: white; }
.fg-band.critical { background: #dc2626; }
.fg-band.high { background: #f97316; }
.fg-crit { display: inline-block; padding: 1px 5px; border-radius: 3px; font-weight: 700; font-size: 9.5px; color: white; }
.fg-crit.A { background: #dc2626; }
.fg-crit.B { background: #f59e0b; }
.fg-crit.C { background: #64748b; }
.fg-back { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: #f1f5f9; color: #475569; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; }
.fg-back:hover { background: #e2e8f0; }
.fg-guide-link { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: linear-gradient(135deg, #7c2d12, #b91c1c); color: white; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; margin-inline-start: 6px; }
.fg-guide-link:hover { opacity: 0.9; }
.fg-money { font-weight: 700; color: #dc2626; }
.fg-num { text-align: end; }
.fg-empty { padding: 40px; text-align: center; color: #94a3b8; }
</style>
</head>
<body class="app-layout">
<?php
$page_title = 'فجوة التمويل — Funding Gap';
$page_icon  = 'fa-sack-dollar';
include BASE_PATH . '/includes/sidebar.php';
?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="fg-wrap">

<a href="<?= BASE_URL ?>/reports/risk/index.php" class="fg-back">
    <i class="fa-solid fa-arrow-<?= $rtl ? 'right' : 'left' ?>"></i> العودة لمركز تقارير المخاطر
</a>
<a href="<?= BASE_URL ?>/presentations/risk_score_guide.html" target="_blank" class="fg-guide-link">
    <i class="fa-solid fa-book-open"></i> شرح تفصيلي
</a>

<div class="fg-hero">
    <h1><i class="fa-solid fa-sack-dollar"></i> فجوة التمويل</h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.risk') ?>
            </div>
    <p>الأصول الحرجة والمرتفعة الخطورة مع تكاليف الإحلال التقديرية. لاتخاذ قرارات الإحلال.</p>
</div>

<div class="fg-filters">
    <form method="get">
        <div class="fld"><label>المستوى</label>
            <select name="band">
                <option value="critical" <?= $filter_band === 'critical' ? 'selected' : '' ?>>Critical فقط</option>
                <option value="high" <?= $filter_band === 'high' ? 'selected' : '' ?>>High فقط</option>
                <option value="" <?= $filter_band === '' ? 'selected' : '' ?>>الكل (Critical + High)</option>
            </select>
        </div>
        <div class="fld"><label>الحساسية</label>
            <select name="criticality">
                <option value="">الكل</option>
                <option value="A" <?= $filter_crit === 'A' ? 'selected' : '' ?>>A</option>
                <option value="B" <?= $filter_crit === 'B' ? 'selected' : '' ?>>B</option>
                <option value="C" <?= $filter_crit === 'C' ? 'selected' : '' ?>>C</option>
            </select>
        </div>
        <button type="submit">تطبيق</button>
    </form>
</div>

<div class="fg-stats">
    <div class="fg-stat">
        <div class="l">عدد الأصول المؤهلة للإحلال</div>
        <div class="v"><?= count($rows) ?></div>
    </div>
    <div class="fg-stat" style="border-top-color:#dc2626">
        <div class="l">فجوة التمويل (Funding Gap)</div>
        <div class="v" style="color:#dc2626"><?= number_format($total_gap, 0) ?> <span style="font-size:13px">ر.س</span></div>
    </div>
    <div class="fg-stat" style="border-top-color:#3b82f6">
        <div class="l">إجمالي تكلفة الإحلال</div>
        <div class="v" style="color:#3b82f6"><?= number_format($total_cost, 0) ?> <span style="font-size:13px">ر.س</span></div>
    </div>
</div>

<div class="fg-table">
<?php if (empty($rows)): ?>
<div class="fg-empty">
    <i class="fa-solid fa-check-circle" style="font-size:48px;color:#22c55e"></i>
    <p style="margin-top:12px;font-size:14px">لا توجد أصول في Critical/High حالياً — أو البيانات تحتاج تقييم</p>
    <p style="font-size:12px;margin-top:8px"><a href="<?= BASE_URL ?>/assets/risk_assessment.php">قيّم الأصول</a> لملء البيانات</p>
</div>
<?php else: ?>
<table>
<thead>
<tr>
    <th>Tag</th>
    <th>الوصف</th>
    <th>الفئة</th>
    <th>القسم</th>
    <th>الحساسية</th>
    <th>العمر</th>
    <th>المستوى</th>
    <th>السكور</th>
    <th>الحالة</th>
    <th>الضغط</th>
    <th>البلاغات</th>
    <th>صيانة YTD</th>
    <th>التكلفة</th>
    <th>فجوة التمويل</th>
    <th>الإجراء الموصى</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $a):
    $age = $a['date_placed_in_service'] ? (int)date('Y') - (int)date('Y', strtotime($a['date_placed_in_service'])) : null;
?>
<tr>
    <td dir="ltr"><strong><?= htmlspecialchars($a['tag_number'] ?: '—') ?></strong></td>
    <td title="<?= htmlspecialchars($a['description']) ?>"><?= htmlspecialchars(truncate($a['description'], 40)) ?></td>
    <td><?= htmlspecialchars($a['cat_level1'] ?: '—') ?></td>
    <td><?= htmlspecialchars($a['dept_name'] ?: '—') ?></td>
    <td><span class="fg-crit <?= $a['criticality_class'] ?: 'C' ?>"><?= $a['criticality_class'] ?: 'C' ?></span></td>
    <td><?= $age !== null ? $age . ' سنة' : '—' ?></td>
    <td><span class="fg-band <?= $a['risk_band'] ?>"><?= $a['risk_band'] === 'critical' ? 'حرج' : 'مرتفع' ?></span></td>
    <td><strong><?= number_format($a['total_risk_score'], 0) ?></strong></td>
    <td><?= htmlspecialchars($a['condition_status'] ?: '—') ?></td>
    <td><?= htmlspecialchars($a['operational_pressure'] ?: '—') ?></td>
    <td><?= (int)$a['breakdowns_12m'] ?></td>
    <td><?= $a['maintenance_cost_ytd'] > 0 ? number_format($a['maintenance_cost_ytd'], 0) : '—' ?></td>
    <td class="fg-num"><?= number_format($a['cost'], 0) ?></td>
    <td class="fg-num fg-money"><?= number_format($a['funding_gap'], 0) ?></td>
    <td style="font-size:11px"><?= htmlspecialchars(truncate($a['recommended_action'] ?? '', 50)) ?></td>
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