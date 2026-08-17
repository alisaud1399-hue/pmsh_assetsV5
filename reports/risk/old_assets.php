<?php
/**
 * Old Assets Report — assets past their useful life
 * These are the natural candidates for replacement.
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
$filter_crit = $_GET['criticality'] ?? '';
$filter_cat  = $_GET['cat_level1'] ?? '';

$where = "a.date_placed_in_service IS NOT NULL
          AND TIMESTAMPDIFF(YEAR, a.date_placed_in_service, NOW()) >= COALESCE(a.useful_life_years, 10)";
$params = [];
if ($filter_crit) { $where .= " AND a.criticality_class = ?"; $params[] = $filter_crit; }
if ($filter_cat)  { $where .= " AND a.cat_level1 = ?"; $params[] = $filter_cat; }

$sql = "SELECT a.id, a.tag_number, a.description, a.cat_level1, a.criticality_class,
               a.date_placed_in_service, a.useful_life_years,
               TIMESTAMPDIFF(YEAR, a.date_placed_in_service, NOW()) AS age_years,
               a.total_risk_score, a.risk_band, a.funding_gap, a.cost,
               d.name AS dept_name
        FROM assets a
        LEFT JOIN departments d ON d.id = a.department_id
        WHERE $where
        ORDER BY age_years DESC, a.cost DESC
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_cost = array_sum(array_column($rows, 'cost'));
$cats = $pdo->query("SELECT DISTINCT cat_level1 FROM assets WHERE cat_level1 IS NOT NULL AND cat_level1 != '' ORDER BY cat_level1")->fetchAll(PDO::FETCH_COLUMN);
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
<title>الأصول المتقادمة — Old Assets</title>
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

.oa-wrap { max-width: 100%; margin: 0; padding: 16px 20px; box-sizing: border-box; }
.oa-hero {
    background: linear-gradient(135deg, #1e293b 0%, #581c87 50%, #7c3aed 100%);
    color: white; border-radius: 14px; padding: 18px 24px; margin-bottom: 16px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.10);
}
.oa-hero h1 { margin: 0 0 3px 0; font-size: 19px; font-weight: 700; }
.oa-hero p { margin: 0; opacity: 0.85; font-size: 12.5px; }
.oa-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
@media (max-width: 700px) { .oa-stats { grid-template-columns: 1fr; } }
.oa-stat { background: white; border-radius: 10px; padding: 12px 16px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; border-top: 3px solid #7c3aed; }
.oa-stat .v { font-size: 22px; font-weight: 800; color: #1e293b; margin: 3px 0; }
.oa-stat .l { font-size: 10.5px; color: #64748b; font-weight: 600; }
.oa-filters { background: white; border-radius: 10px; padding: 10px 16px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; }
.oa-filters form { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
.oa-filters .fld { display: flex; flex-direction: column; gap: 3px; }
.oa-filters label { font-size: 10.5px; color: #64748b; font-weight: 600; }
.oa-filters select { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 5px; font-size: 12px; }
.oa-filters button { padding: 7px 14px; background: #1e293b; color: white; border: 0; border-radius: 5px; cursor: pointer; font-weight: 600; font-size: 12px; }
.oa-table { background: white; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; overflow: auto; }
.oa-table table { width: 100%; border-collapse: collapse; font-size: 12px; }
.oa-table th { background: #f8fafc; color: #475569; padding: 8px 10px; text-align: start; font-weight: 700; border-bottom: 2px solid #e2e8f0; }
.oa-table td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; }
.oa-table tr:hover td { background: #f8fafc; }
.oa-band { display: inline-block; padding: 2px 7px; border-radius: 4px; font-weight: 700; font-size: 10px; color: white; }
.oa-band.critical { background: #dc2626; }
.oa-band.high { background: #f97316; }
.oa-band.medium { background: #eab308; color: #0f172a; }
.oa-band.low { background: #22c55e; }
.oa-band.unscored { background: #cbd5e1; color: #475569; }
.oa-crit { display: inline-block; padding: 1px 5px; border-radius: 3px; font-weight: 700; font-size: 9.5px; color: white; }
.oa-crit.A { background: #dc2626; }
.oa-crit.B { background: #f59e0b; }
.oa-crit.C { background: #64748b; }
.oa-age { font-weight: 700; color: #7c3aed; }
.oa-over { color: #dc2626; font-weight: 700; }
.oa-num { text-align: end; }
.oa-back { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: #f1f5f9; color: #475569; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; }
.oa-back:hover { background: #e2e8f0; }
.oa-guide-link { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; background: linear-gradient(135deg, #7c2d12, #b91c1c); color: white; border-radius: 6px; text-decoration: none; font-size: 12px; font-weight: 600; margin-bottom: 10px; margin-inline-start: 6px; }
.oa-guide-link:hover { opacity: 0.9; }
.oa-empty { padding: 40px; text-align: center; color: #94a3b8; }
</style>
</head>
<body class="app-layout">
<?php
$page_title = 'الأصول المتقادمة — Old Assets';
$page_icon  = 'fa-hourglass-end';
include BASE_PATH . '/includes/sidebar.php';
?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="oa-wrap">

<a href="<?= BASE_URL ?>/reports/risk/index.php" class="oa-back">
    <i class="fa-solid fa-arrow-<?= $rtl ? 'right' : 'left' ?>"></i> العودة لمركز تقارير المخاطر
</a>
<a href="<?= BASE_URL ?>/presentations/risk_score_guide.html" target="_blank" class="oa-guide-link">
    <i class="fa-solid fa-book-open"></i> شرح تفصيلي
</a>

<div class="oa-hero">
    <h1><i class="fa-solid fa-hourglass-end"></i> الأصول المتقادمة</h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.risk') ?>
            </div>
    <p>الأصول التي تجاوزت عمرها الافتراضي (useful_life_years). مرشحة للإحلال. الترتيب حسب تجاوز العمر.</p>
</div>

<div class="oa-filters">
    <form method="get">
        <div class="fld"><label>الحساسية</label>
            <select name="criticality">
                <option value="">الكل</option>
                <option value="A" <?= $filter_crit === 'A' ? 'selected' : '' ?>>A</option>
                <option value="B" <?= $filter_crit === 'B' ? 'selected' : '' ?>>B</option>
                <option value="C" <?= $filter_crit === 'C' ? 'selected' : '' ?>>C</option>
            </select>
        </div>
        <div class="fld"><label>الفئة</label>
            <select name="cat_level1">
                <option value="">الكل</option>
                <?php foreach ($cats as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $filter_cat === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">تطبيق</button>
    </form>
</div>

<div class="oa-stats">
    <div class="oa-stat">
        <div class="l">عدد الأصول المتقادمة</div>
        <div class="v"><?= count($rows) ?></div>
    </div>
    <div class="oa-stat" style="border-top-color:#dc2626">
        <div class="l">إجمالي تكلفة الإحلال</div>
        <div class="v" style="color:#dc2626"><?= number_format($total_cost, 0) ?> <span style="font-size:13px">ر.س</span></div>
    </div>
    <div class="oa-stat" style="border-top-color:#3b82f6">
        <div class="l">متوسط العمر الحالي</div>
        <div class="v" style="color:#3b82f6">
            <?php
            $ages = array_column($rows, 'age_years');
            echo count($ages) > 0 ? round(array_sum($ages) / count($ages), 1) : 0;
            ?> <span style="font-size:13px">سنة</span>
        </div>
    </div>
</div>

<div class="oa-table">
<?php if (empty($rows)): ?>
<div class="oa-empty">
    <i class="fa-solid fa-check-circle" style="font-size:48px;color:#22c55e"></i>
    <p style="margin-top:12px">لا توجد أصول متقادمة حالياً في هذا الفلتر</p>
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
    <th>تاريخ التركيب</th>
    <th>العمر الحالي</th>
    <th>العمر الافتراضي</th>
    <th>التجاوز</th>
    <th>المستوى</th>
    <th>السكور</th>
    <th>التكلفة</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $a):
    $excess = $a['age_years'] - ($a['useful_life_years'] ?? 10);
?>
<tr>
    <td dir="ltr"><strong><?= htmlspecialchars($a['tag_number'] ?: '—') ?></strong></td>
    <td title="<?= htmlspecialchars($a['description']) ?>"><?= htmlspecialchars(truncate($a['description'], 40)) ?></td>
    <td><?= htmlspecialchars($a['cat_level1'] ?: '—') ?></td>
    <td><?= htmlspecialchars($a['dept_name'] ?: '—') ?></td>
    <td><span class="oa-crit <?= $a['criticality_class'] ?: 'C' ?>"><?= $a['criticality_class'] ?: 'C' ?></span></td>
    <td><?= $a['date_placed_in_service'] ? date('Y-m-d', strtotime($a['date_placed_in_service'])) : '—' ?></td>
    <td class="oa-age"><?= (int)$a['age_years'] ?> سنة</td>
    <td><?= $a['useful_life_years'] ?? '—' ?> سنة</td>
    <td class="oa-over">+<?= (int)$excess ?> سنة</td>
    <td><span class="oa-band <?= $a['risk_band'] ?>"><?= $a['risk_band'] === 'critical' ? 'حرج' : ($a['risk_band'] === 'high' ? 'مرتفع' : ($a['risk_band'] === 'medium' ? 'متوسط' : ($a['risk_band'] === 'low' ? 'منخفض' : 'غير مُقيَّم'))) ?></span></td>
    <td><?= $a['total_risk_score'] > 0 ? number_format($a['total_risk_score'], 0) : '—' ?></td>
    <td class="oa-num"><?= $a['cost'] > 0 ? number_format($a['cost'], 0) . ' <span style="font-size:10px">ر.س</span>' : '—' ?></td>
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