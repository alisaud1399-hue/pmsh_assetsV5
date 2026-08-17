<?php
/**
 * Data Quality Report — assets with incomplete risk data
 * Helps identify which assets need manual assessment.
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
$filter_compl = $_GET['completeness'] ?? '';
$filter_crit  = $_GET['criticality'] ?? '';

$where = "1=1"; $params = [];
if ($filter_compl === '100') $where = "a.data_completeness_pct = 100";
elseif ($filter_compl === '75') $where = "a.data_completeness_pct >= 75 AND a.data_completeness_pct < 100";
elseif ($filter_compl === '50') $where = "a.data_completeness_pct >= 50 AND a.data_completeness_pct < 75";
elseif ($filter_compl === '0') $where = "a.data_completeness_pct = 0";
if ($filter_crit) { $where .= " AND a.criticality_class = ?"; $params[] = $filter_crit; }

$sql = "SELECT a.id, a.tag_number, a.description, a.criticality_class,
               a.condition_status, a.utilization_rate, a.downtime_impact,
               a.operational_pressure, a.beneficiaries_count,
               a.data_completeness_pct, a.last_manual_assessment_at,
               a.last_computed_at, d.name AS dept_name
        FROM assets a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE $where
        ORDER BY a.data_completeness_pct ASC, a.id ASC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$dq = risk_get_data_quality($pdo);
$total = (int)$dq['total'];
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
<title>جودة البيانات — Risk Data Quality</title>
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

.dq-wrap { max-width: 100%; margin: 0; padding: 16px 20px; box-sizing: border-box; }
.dq-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0c4a6e 50%, #0369a1 100%);
    color: white; border-radius: 14px; padding: 18px 24px; margin-bottom: 16px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.10);
}
.dq-hero h1 { margin: 0 0 3px 0; font-size: 19px; font-weight: 700; }
.dq-hero p { margin: 0; opacity: 0.85; font-size: 12.5px; }
.dq-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; }
@media (max-width: 900px) { .dq-kpis { grid-template-columns: repeat(2, 1fr); } }
.dq-kpi { background: white; border-radius: 10px; padding: 12px 14px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; text-align: center; border-top: 3px solid #94a3b8; }
.dq-kpi.p100 { border-top-color: #22c55e; }
.dq-kpi.p75  { border-top-color: #3b82f6; }
.dq-kpi.p50  { border-top-color: #eab308; }
.dq-kpi.p0   { border-top-color: #dc2626; }
.dq-kpi .v { font-size: 22px; font-weight: 800; color: #1e293b; margin: 3px 0; }
.dq-kpi .l { font-size: 11px; color: #64748b; font-weight: 600; }
.dq-kpi .pct { font-size: 10px; color: #94a3b8; }
.dq-filters { background: white; border-radius: 10px; padding: 10px 16px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
.dq-filters form { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
.dq-filters .fld { display: flex; flex-direction: column; gap: 3px; }
.dq-filters label { font-size: 10.5px; color: #64748b; font-weight: 600; }
.dq-filters select { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 5px; font-size: 12px; }
.dq-filters button { padding: 7px 14px; background: #1e293b; color: white; border: 0; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 12px; }
.dq-table { background: white; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; overflow: auto; }
.dq-table table { width: 100%; border-collapse: collapse; font-size: 12px; }
.dq-table th { background: #f8fafc; color: #475569; padding: 8px 10px; text-align: start; font-weight: 700; border-bottom: 2px solid #e2e8f0; }
.dq-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }
.dq-table tr:hover td { background: #f8fafc; }
.dq-comp { display: inline-block; padding: 2px 8px; border-radius: 4px; font-weight: 700; font-size: 10.5px; }
.dq-comp.p100 { background: #dcfce7; color: #166534; }
.dq-comp.p75  { background: #dbeafe; color: #1e40af; }
.dq-comp.p50  { background: #fef3c7; color: #854d0e; }
.dq-comp.p0   { background: #fee2e2; color: #991b1b; }
.dq-field { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 9.5px; font-weight: 600; }
.dq-field.filled { background: #dcfce7; color: #166534; }
.dq-field.missing { background: #f1f5f9; color: #94a3b8; text-decoration: line-through; }
.dq-crit { display: inline-block; padding: 1px 5px; border-radius: 3px; font-weight: 700; font-size: 9.5px; color: white; }
.dq-crit.A { background: #dc2626; }
.dq-crit.B { background: #f59e0b; }
.dq-crit.C { background: #64748b; }
.dq-back { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: #f1f5f9; color: #475569; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; }
.dq-back:hover { background: #e2e8f0; }
.dq-guide-link { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: linear-gradient(135deg, #7c2d12, #b91c1c); color: white; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; margin-inline-start: 6px; }
.dq-guide-link:hover { opacity: 0.9; }
.dq-empty { padding: 40px; text-align: center; color: #94a3b8; }
.dq-action { color: #0ea5e9; text-decoration: none; font-size: 11.5px; font-weight: 600; }
</style>
</head>
<body class="app-layout">
<?php
$page_title = 'جودة البيانات — Risk Data Quality';
$page_icon  = 'fa-clipboard-check';
include BASE_PATH . '/includes/sidebar.php';
?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="dq-wrap">

<a href="<?= BASE_URL ?>/reports/risk/index.php" class="dq-back">
    <i class="fa-solid fa-arrow-<?= $rtl ? 'right' : 'left' ?>"></i> العودة لمركز تقارير المخاطر
</a>
<a href="<?= BASE_URL ?>/presentations/risk_score_guide.html" target="_blank" class="dq-guide-link">
    <i class="fa-solid fa-book-open"></i> شرح تفصيلي
</a>

<div class="dq-hero">
    <h1><i class="fa-solid fa-clipboard-check"></i> جودة بيانات تقييم المخاطر</h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.risk') ?>
            </div>
    <p>تعرّف على الأصول التي تحتاج تقييماً يدوياً لتحسين دقة Risk Score. 4 حقول يدوية (Condition, Utilization, Downtime, Pressure) = 100%.</p>
</div>

<div class="dq-kpis">
    <div class="dq-kpi p100">
        <i class="fa-solid fa-circle-check" style="color:#22c55e"></i>
        <div class="v"><?= number_format($dq['complete_100']) ?></div>
        <div class="l">اكتمال 100%</div>
        <div class="pct"><?= $total > 0 ? round($dq['complete_100'] / $total * 100, 1) : 0 ?>% من الإجمالي</div>
    </div>
    <div class="dq-kpi p75">
        <i class="fa-solid fa-circle-info" style="color:#3b82f6"></i>
        <div class="v"><?= number_format($dq['complete_75']) ?></div>
        <div class="l">≥ 75%</div>
        <div class="pct"><?= $total > 0 ? round($dq['complete_75'] / $total * 100, 1) : 0 ?>%</div>
    </div>
    <div class="dq-kpi p50">
        <i class="fa-solid fa-circle-exclamation" style="color:#eab308"></i>
        <div class="v"><?= number_format($dq['complete_50']) ?></div>
        <div class="l">≥ 50%</div>
        <div class="pct"><?= $total > 0 ? round($dq['complete_50'] / $total * 100, 1) : 0 ?>%</div>
    </div>
    <div class="dq-kpi p0">
        <i class="fa-solid fa-circle-xmark" style="color:#dc2626"></i>
        <div class="v"><?= number_format($dq['zero_data']) ?></div>
        <div class="l">فارغة (0%)</div>
        <div class="pct"><?= $total > 0 ? round($dq['zero_data'] / $total * 100, 1) : 0 ?>%</div>
    </div>
</div>

<div class="dq-filters">
    <form method="get">
        <div class="fld"><label>الاكتمال</label>
            <select name="completeness">
                <option value="">الكل</option>
                <option value="100" <?= $filter_compl === '100' ? 'selected' : '' ?>>100%</option>
                <option value="75" <?= $filter_compl === '75' ? 'selected' : '' ?>>75-99%</option>
                <option value="50" <?= $filter_compl === '50' ? 'selected' : '' ?>>50-74%</option>
                <option value="0" <?= $filter_compl === '0' ? 'selected' : '' ?>>0% (فارغة)</option>
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

<div class="dq-table">
<?php if (empty($rows)): ?>
<div class="dq-empty">
    <i class="fa-solid fa-check-double" style="font-size:48px;color:#22c55e"></i>
    <p style="margin-top:12px">لا توجد أصول في هذا الفلتر</p>
</div>
<?php else: ?>
<table>
<thead>
<tr>
    <th>Tag</th>
    <th>الوصف</th>
    <th>القسم</th>
    <th>الحساسية</th>
    <th>الحالة</th>
    <th>الاستخدام</th>
    <th>التوقف</th>
    <th>الضغط</th>
    <th>المستفيدين</th>
    <th>الاكتمال</th>
    <th>آخر تقييم</th>
    <th>إجراء</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $a):
    $cp = (int)$a['data_completeness_pct'];
    $cp_class = $cp === 100 ? 'p100' : ($cp >= 75 ? 'p75' : ($cp >= 50 ? 'p50' : 'p0'));
?>
<tr>
    <td dir="ltr"><strong><?= htmlspecialchars($a['tag_number'] ?: '—') ?></strong></td>
    <td title="<?= htmlspecialchars($a['description']) ?>"><?= htmlspecialchars(truncate($a['description'], 40)) ?></td>
    <td><?= htmlspecialchars($a['dept_name'] ?: '—') ?></td>
    <td><span class="dq-crit <?= $a['criticality_class'] ?: 'C' ?>"><?= $a['criticality_class'] ?: 'C' ?></span></td>
    <td><span class="dq-field <?= ($a['condition_status'] && $a['condition_status'] !== 'unknown') ? 'filled' : 'missing' ?>"><?= $a['condition_status'] ?: 'missing' ?></span></td>
    <td><span class="dq-field <?= $a['utilization_rate'] !== null ? 'filled' : 'missing' ?>"><?= $a['utilization_rate'] !== null ? number_format($a['utilization_rate'], 2) : 'missing' ?></span></td>
    <td><span class="dq-field <?= ($a['downtime_impact'] && $a['downtime_impact'] !== 'unknown') ? 'filled' : 'missing' ?>"><?= $a['downtime_impact'] ?: 'missing' ?></span></td>
    <td><span class="dq-field <?= ($a['operational_pressure'] && $a['operational_pressure'] !== 'unknown') ? 'filled' : 'missing' ?>"><?= $a['operational_pressure'] ?: 'missing' ?></span></td>
    <td><span class="dq-field <?= $a['beneficiaries_count'] !== null ? 'filled' : 'missing' ?>"><?= $a['beneficiaries_count'] !== null ? number_format($a['beneficiaries_count']) : '—' ?></span></td>
    <td><span class="dq-comp <?= $cp_class ?>"><?= $cp ?>%</span></td>
    <td style="font-size:11px;color:#64748b"><?= $a['last_manual_assessment_at'] ? date('Y-m-d', strtotime($a['last_manual_assessment_at'])) : 'لم يُقيَّم' ?></td>
    <td><a href="<?= BASE_URL ?>/assets/risk_assessment.php?band=&criticality=&cat_level1=&department_id=&completeness=<?= $cp < 100 ? '0' : '100' ?>" class="dq-action">قيّم</a></td>
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