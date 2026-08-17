<?php
/**
* reports/assets.php — تقارير الأصول (النسخة الماسية المتكاملة - Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* - نظام الوجهين (تنفيذي مدمج / تفصيلي مكدس).
* - طباعة منفصلة (وثيقة جداول رسمية / لوحة مؤشرات A4).
* - تصدير Excel مطابق للوزارة.
* - تغطية 100% لبيانات (المالية، الاستبدال، الضمان، التحقق، الصيانة).
* - ✅ نظام التقارير المحفوظة الموحد (حفظ / مشاركة / نسخ رابط / تطبيق).
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';

page_guard('reports.assets');

// ✅ تطبيق تقرير محفوظ إذا طُلب (بعد page_guard لضمان الصلاحيات)
if (isset($_GET['apply_saved'])) {
    sr_apply_saved($pdo, (int)$_GET['apply_saved'], (int)current_user()['id']);
}

$rtl = is_rtl();
$can_see_all = can_see_all();
$can_export  = can('reports.assets', 'export');
$user_dept_id = (int)(current_user()['department_id'] ?? 0);
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';
$ASSET_TYPE_AR = ['medical'=>'طبي','it'=>'تقنية معلومات','infrastructure'=>'بنية تحتية','hvac'=>'تكييف وتهوية','transport'=>'نقل','furniture'=>'أثاث','other'=>'أخرى'];
$CRIT_AR = ['A'=>'A — حرج جداً','B'=>'B — متوسط','C'=>'C — منخفض'];
$WARR_AR = ['expired'=>'منتهٍ','soon'=>'ينتهي خلال 90 يوماً','valid'=>'ساري'];
$STATUS_AR = ['active'=>'نشط','under_maintenance'=>'صيانة','inactive'=>'خارج الخدمة','pending_commissioning'=>'بانتظار التشغيل',
'pending_receipt'=>'بانتظار الاستلام','pending_govt_registration'=>'بانتظار التسجيل','disposed'=>'متلف','transferred'=>'منقول','returned_to_supplier'=>'مرتجَع'];
$COMPLETENESS_AR = ['complete'=>'كامل','partial'=>'جزئي','minimal'=>'ناقص'];
$VERIFIED_AR = ['verified'=>'مُعتمد (متحقق منه)','unverified'=>'غير معتمد / قيد المراجعة'];
/* ═══ قراءة الفلاتر ونمط العرض ═══ */
$view_mode = $_GET['view'] ?? 'executive';
/* حارس دفاعي: أي قيمة تاريخ لا تطابق صيغة YYYY-MM-DD حقيقية تُهمَل بدل أن تُفسِد الاستعلام صامتاً
(يحمي من تلف مدخلات المتصفح — إكمال تلقائي مشوَّه أو أي محتوى غير متوقَّع) */
if (!function_exists('valid_date')) {
function valid_date(string $v): string {
if ($v === '') return '';
$d = DateTime::createFromFormat('Y-m-d', $v);
return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
}
$f = [
'asset_type' => trim($_GET['asset_type'] ?? ''),
'c1' => trim($_GET['c1'] ?? ''), 'c2' => trim($_GET['c2'] ?? ''), 'c3' => trim($_GET['c3'] ?? ''),
'criticality' => trim($_GET['criticality'] ?? ''),
'status' => trim($_GET['status'] ?? ''),
'manufacturer' => trim($_GET['manufacturer'] ?? ''),
'model' => trim($_GET['model'] ?? ''),
'completeness' => trim($_GET['completeness'] ?? ''),
'verified' => trim($_GET['verified'] ?? ''),
'svc_from' => valid_date(trim($_GET['svc_from'] ?? '')), 'svc_to' => valid_date(trim($_GET['svc_to'] ?? '')),
'reg_from' => valid_date(trim($_GET['reg_from'] ?? '')), 'reg_to' => valid_date(trim($_GET['reg_to'] ?? '')),
'warranty' => trim($_GET['warranty'] ?? ''),
];
$has_filters = array_filter($f) !== [];
$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;
/* ═══ بناء الاستعلام الشامل ═══ */
$where = ["status NOT IN ('disposed','returned_to_supplier')"];
$params = [];
if (!$can_see_all) { $where[] = '(custodian_dept_id = :d OR department_id = :d OR prediction_department_id = :d)'; $params['d'] = $user_dept_id; }
if ($f['asset_type']) { $where[] = 'asset_type = :atype'; $params['atype'] = $f['asset_type']; }
if ($f['c1']) { $where[] = 'cat_level1 = :c1'; $params['c1'] = $f['c1']; }
if ($f['c2']) { $where[] = 'cat_level2 = :c2'; $params['c2'] = $f['c2']; }
if ($f['c3']) { $where[] = 'cat_level3 = :c3'; $params['c3'] = $f['c3']; }
if ($f['criticality']) { $where[] = 'criticality_class = :crit'; $params['crit'] = $f['criticality']; }
if ($f['status']) { $where[] = 'status = :st'; $params['st'] = $f['status']; }
if ($f['manufacturer']) { $where[] = 'manufacturer_name = :manf'; $params['manf'] = $f['manufacturer']; }
if ($f['model']) { $where[] = 'model_number = :mdl'; $params['mdl'] = $f['model']; }
if ($f['completeness']) { $where[] = 'data_completeness = :comp'; $params['comp'] = $f['completeness']; }
if ($f['verified'] === 'verified') { $where[] = "verified_status NOT LIKE '%لم يتم%'"; }
elseif ($f['verified'] === 'unverified') { $where[] = "verified_status LIKE '%لم يتم%'"; }
if ($f['svc_from']) { $where[] = 'date_placed_in_service >= :svc_from'; $params['svc_from'] = $f['svc_from']; }
if ($f['svc_to'])   { $where[] = 'date_placed_in_service <= :svc_to';   $params['svc_to'] = $f['svc_to']; }
if ($f['reg_from']) { $where[] = 'DATE(created_at) >= :reg_from'; $params['reg_from'] = $f['reg_from']; }
if ($f['reg_to'])   { $where[] = 'DATE(created_at) <= :reg_to';   $params['reg_to'] = $f['reg_to']; }
if ($f['warranty'] === 'expired') { $where[] = 'warranty_expiry IS NOT NULL AND warranty_expiry < CURDATE()'; }
elseif ($f['warranty'] === 'soon') { $where[] = 'warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)'; }
elseif ($f['warranty'] === 'valid') { $where[] = 'warranty_expiry IS NOT NULL AND warranty_expiry > DATE_ADD(CURDATE(), INTERVAL 90 DAY)'; }
$results = [];
if ($has_filters || $excel_mode || $print_mode || $print_charts_mode) {
$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 1000;
$sql = "SELECT id, tag_number, serial_number, description, asset_type, cat_level1, cat_level2, cat_level3,
criticality_class, status, health_score, date_placed_in_service, warranty_expiry,
loc_building, loc_floor, loc_room,
custodian_type, custody_date, custodian_name, custodian_dept_name,
in_replacement_plan, expected_replacement_date, useful_life_years, life_in_months,
cost, original_cost, net_book_value, accumulated_depreciation, depreciation_method,
data_completeness, verified_status, completion_priority,
manufacturer_name, model_number, warranty_type, last_maintenance_date, total_maintenance_cost, maintenance_team,
created_at
FROM assets WHERE " . implode(' AND ', $where) . " ORDER BY tag_number LIMIT $row_cap";
$st = $pdo->prepare($sql);
$st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);
}
$title_parts = [];
if ($f['asset_type']) $title_parts[] = $ASSET_TYPE_AR[$f['asset_type']] ?? $f['asset_type'];
if ($f['c1']) $title_parts[] = $f['c1'];
if ($f['c2']) $title_parts[] = $f['c2'];
if ($f['c3']) $title_parts[] = $f['c3'];
if ($f['criticality']) $title_parts[] = 'فئة ' . $f['criticality'];
if ($f['manufacturer']) $title_parts[] = $f['manufacturer'];
$report_title = 'تقرير الأصول' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');
/* ═══ 1. تصدير Excel المتوافق مع الوزارة ═══ */
if ($excel_mode) {
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename=MOH_Detailed_Asset_Register_' . date('Ymd_Hi') . '.xls');
echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta http-equiv="Content-type" content="text/html;charset=utf-8" />
<style>table { border-collapse: collapse; font-family: sans-serif; font-size: 13px; } th { background-color: #0f172a; color: #ffffff; font-weight: bold; border: 1px solid #cbd5e1; padding: 10px; text-align: center; } td { border: 1px solid #cbd5e1; padding: 8px; text-align: center; vertical-align: middle; } .tag { mso-number-format: "\@"; font-weight: bold; color: #d97706; } </style></head>
<body dir="rtl">
<table><thead>
<tr><th colspan="27" style="font-size:16px; background-color:#0369a1; padding: 15px;">السجل التفصيلي الشامل للأصول - <?= e($report_title) ?></th></tr>
<tr>
<th style="background:#1e293b">Tag Number</th><th style="background:#1e293b">Serial Number</th><th style="background:#1e293b">Description</th>
<th style="background:#8b5cf6">Manufacturer</th><th style="background:#8b5cf6">Model</th>
<th style="background:#334155">Type</th><th style="background:#334155">L1 Category</th><th style="background:#334155">L2 Category</th><th style="background:#334155">L3 Category</th><th style="background:#334155">Criticality</th>
<th style="background:#475569">Status</th><th style="background:#475569">Health Score</th><th style="background:#475569">Data Completeness</th><th style="background:#475569">Verification</th>
<th style="background:#0284c7">Building</th><th style="background:#0284c7">Floor</th><th style="background:#0284c7">Room</th>
<th style="background:#0d9488">Custodian Type</th><th style="background:#0d9488">Custodian Name</th><th style="background:#0d9488">Custody Date</th>
<th style="background:#b45309">Service Date</th><th style="background:#b45309">Warranty Expiry</th><th style="background:#b45309">Life (Months)</th>
<th style="background:#16a34a">Original Cost</th><th style="background:#16a34a">Net Book Value</th><th style="background:#16a34a">Accumulated Depr.</th>
<th style="background:#7c3aed">Last Maintenance</th>
</tr>
</thead><tbody>
<?php foreach($results as $r): ?>
<tr>
<td class="tag"><?= e($r['tag_number']) ?></td><td><?= e($r['serial_number']) ?></td><td><?= e($r['description']) ?></td>
<td><?= e($r['manufacturer_name']) ?></td><td><?= e($r['model_number']) ?></td>
<td><?= e($ASSET_TYPE_AR[$r['asset_type']] ?? $r['asset_type']) ?></td><td><?= e($r['cat_level1']) ?></td><td><?= e($r['cat_level2']) ?></td><td><?= e($r['cat_level3']) ?></td><td><?= e($r['criticality_class']) ?></td>
<td><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></td><td><?= e($r['health_score']) ?>%</td><td><?= e($COMPLETENESS_AR[$r['data_completeness']] ?? $r['data_completeness']) ?></td><td><?= e($r['verified_status']) ?></td>
<td><?= e($r['loc_building']) ?></td><td><?= e($r['loc_floor']) ?></td><td><?= e($r['loc_room']) ?></td>
<td><?= e($r['custodian_type']) ?></td><td><?= e($r['custodian_name'] ?: $r['custodian_dept_name']) ?></td><td><?= e($r['custody_date']) ?></td>
<td><?= e($r['date_placed_in_service']) ?></td><td><?= e($r['warranty_expiry']) ?></td><td><?= e($r['life_in_months']) ?></td>
<td><?= e($r['original_cost']) ?></td><td><?= e($r['net_book_value']) ?></td><td><?= e($r['accumulated_depreciation']) ?></td>
<td><?= e($r['last_maintenance_date']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></body></html>
<?php exit;
}
/* ═══ 2. معالجة البيانات للوحة التنفيذية (KPIs & Charts) ═══ */
$ai_crit_count = 0; $ai_low_health = 0; $active_count = 0; $total_health = 0;
$chart_categories = []; $chart_status = []; $chart_timeline = [];
$cat_level_to_chart = 'cat_level1'; $c_label = '(رئيسية)';
if ($f['c2']) { $cat_level_to_chart = 'cat_level3'; $c_label = '(الأنواع التفصيلية)'; }
elseif ($f['c1']) { $cat_level_to_chart = 'cat_level2'; $c_label = '(الفئات الفرعية)'; }
foreach ($results as $r) {
if ($r['criticality_class'] === 'A') $ai_crit_count++;
if ((int)$r['health_score'] < 50) $ai_low_health++;
if (in_array($r['status'], ['active', 'under_maintenance'])) $active_count++;
$total_health += (int)$r['health_score'];
$c_val = $r[$cat_level_to_chart] ?: 'غير محدد';
$chart_categories[$c_val] = ($chart_categories[$c_val] ?? 0) + 1;
$s = $STATUS_AR[$r['status']] ?? $r['status'];
$chart_status[$s] = ($chart_status[$s] ?? 0) + 1;
if (!empty($r['date_placed_in_service']) && preg_match('/^(\d{4})/', $r['date_placed_in_service'], $m)) {
$year = $m[1]; $chart_timeline[$year] = ($chart_timeline[$year] ?? 0) + 1;
}
}
ksort($chart_timeline);
$total_assets = count($results);
$avg_health = $total_assets > 0 ? round($total_health / $total_assets) : 0;
$active_rate = $total_assets > 0 ? round(($active_count / $total_assets) * 100) : 0;
$ai_class = "ai-success"; $ai_icon = "fa-check-circle"; $ai_msg = "✨ تقرير تنفيذي: مؤشرات الأصول مستقرة ضمن نطاق البحث.";
if ($ai_crit_count > 0 && $ai_low_health > 0) { $ai_class = "ai-danger"; $ai_icon = "fa-triangle-exclamation"; $ai_msg = "⚠️ تنبيه ذكي: اكتشف النظام $ai_crit_count أصل حرج (A)، منها أصول بصحة متدنية! يُنصح بجدولة صيانة طارئة."; }
elseif ($ai_crit_count > 0) { $ai_class = "ai-warning"; $ai_icon = "fa-bell"; $ai_msg = "⚡ ملاحظة: يتضمن التقرير $ai_crit_count أصل من الفئة الحساسة (A)."; }
$dept_clause_cat = $can_see_all ? '' : ' AND (custodian_dept_id = :d OR department_id = :d OR prediction_department_id = :d)';
$cat_rows = $pdo->prepare("SELECT DISTINCT cat_level1, cat_level2, cat_level3 FROM assets WHERE cat_level1 IS NOT NULL AND cat_level1 != '' AND status NOT IN ('disposed','returned_to_supplier') $dept_clause_cat ORDER BY cat_level1, cat_level2, cat_level3");
$cat_rows->execute($can_see_all ? [] : ['d' => $user_dept_id]);
$cat_tree = [];
foreach ($cat_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
if (!$r['cat_level2']) continue;
$cat_tree[$r['cat_level1']][$r['cat_level2']] = $cat_tree[$r['cat_level1']][$r['cat_level2']] ?? [];
if ($r['cat_level3']) $cat_tree[$r['cat_level1']][$r['cat_level2']][] = $r['cat_level3'];
}
foreach ($cat_tree as $l1 => $l2s) foreach ($l2s as $l2 => $l3s) $cat_tree[$l1][$l2] = array_values(array_unique($l3s));
$manf_rows = $pdo->prepare("SELECT DISTINCT manufacturer_name, model_number FROM assets WHERE manufacturer_name IS NOT NULL AND manufacturer_name != '' AND status NOT IN ('disposed','returned_to_supplier') $dept_clause_cat ORDER BY manufacturer_name, model_number");
$manf_rows->execute($can_see_all ? [] : ['d' => $user_dept_id]);
$manf_tree = [];
foreach ($manf_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
$m = trim($r['manufacturer_name']); $md = trim($r['model_number'] ?? '');
if (!isset($manf_tree[$m])) $manf_tree[$m] = [];
if ($md && !in_array($md, $manf_tree[$m])) $manf_tree[$m][] = $md;
}
$CRIT_COLORS = ['A'=>['#fff1f2','#be123c','#f43f5e'], 'B'=>['#fffbeb','#b45309','#f59e0b'], 'C'=>['#f0fdf4','#15803d','#10b981']];
$STATUS_COLORS = ['active'=>['#10b981','#ecfdf5'],'under_maintenance'=>['#3b82f6','#eff6ff'],'inactive'=>['#64748b','#f8fafc'],
'pending_commissioning'=>['#f59e0b','#fffbeb'],'pending_receipt'=>['#0284c7','#e0f2fe'],'pending_govt_registration'=>['#ea580c','#ffedd5']];
/* =========================================================================
3. أ) الطباعة القياسية للجدول (Standard PDF Print Mode) - بدون رسوم
========================================================================= */
if ($print_mode) {
$ROWS_PER_PAGE = 8;
$pages = array_chunk($results, $ROWS_PER_PAGE, true);
$total_pages = max(1, count($pages));
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>الوثيقة الرسمية - <?= e($report_title) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap');
@page { size: landscape; margin: 12mm 10mm 12mm; }
*{ box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
body { font-family: 'Tajawal', sans-serif !important; color: #1e293b; margin: 0; background: #fff; }
.print-page { page-break-after: always; }
.print-page:last-child { page-break-after: auto; }
.print-header { background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 100%); border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px 18px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;}
.ph-right { display: flex; align-items: center; gap: 12px; text-align: right; border-left: 1px solid #cbd5e1; padding-left: 18px; } .ph-h1 { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 2px;} .ph-h2 { font-size: 11px; color: #475569; font-weight: 700;}
.ph-logo { height: 46px; width: auto; object-fit: contain; flex-shrink: 0; }
.ph-center { flex: 1; text-align: center; padding: 0 16px; } .ph-title { font-size: 16px; font-weight: 800; color: #0369a1; }
.ph-left { text-align: left; font-size: 10px; color: #475569; } .ph-left strong { color: #0f172a; font-size: 11px; display: block; margin-top: 2px; }
.ph-pagebadge { background: #0369a1; color: #fff; padding: 3px 10px; border-radius: 4px; font-size: 9px; font-weight: 800; display: inline-block; margin-bottom: 4px; }
table.data-table { width: 100%; border-collapse: collapse; font-size: 10px; border: 1.5px solid #cbd5e1; }
table.data-table th { background: #f1f5f9; color: #1e293b; padding: 8px; text-align: right; font-size: 10.5px; font-weight: 900; border: 1px solid #cbd5e1; }
table.data-table td { padding: 6px 8px; border: 1px solid #e2e8f0; vertical-align: middle; text-align: right; color: #334155; font-weight: 500; }
table.data-table tbody tr:nth-child(even) td { background: #fafaf9; }
.t-desc { font-weight: 800; font-size: 11px; color: #0f172a; margin-bottom: 2px; } .t-tag { display: inline-block; background: #f8fafc; padding: 2px 6px; border-radius: 4px; font-size: 9px; color: #475569; font-family: monospace; border: 1px solid #e2e8f0; }
.p-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: 800; border: 1px solid rgba(0,0,0,0.05); } .p-crit { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 4px; font-weight: 800; font-size: 10px; }
.p-bar-wrap { width: 45px; height: 4px; background: #e2e8f0; border-radius: 2px; margin: 4px 0 0; overflow: hidden;} .p-bar-fill { height: 100%; border-radius: 2px; }
.print-footer { display: flex; justify-content: space-around; padding: 14px 10px 4px; border-top: 1.5px solid #cbd5e1; }
.sign-box { text-align: center; width: 25%; } .sign-box .title { font-size: 11px; font-weight: 800; color: #1e293b; margin-bottom: 22px; } .sign-box .line { border-bottom: 1px dashed #94a3b8; margin: 0 15px 6px; } .sign-box .hint { font-size: 9px; color: #64748b; }
</style></head>
<body onload="setTimeout(()=>window.print(), 500)">
<?php foreach ($pages as $pageIdx => $pageRows): $pageNum = $pageIdx + 1; ?>
<div class="print-page">
<table class="data-table">
<thead>
<tr><th colspan="7" style="padding:0; border:none; background:none;">
<div class="print-header">
<div class="ph-right"><?php if ($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title">سجل الأصول المعتمد - <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pageNum ?> من <?= $total_pages ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — عدد السجلات: <strong><?= count($results) ?></strong></div></div>
</div>
</th></tr>
<tr>
<th style="width:20px; text-align:center;">#</th>
<th style="width:170px;">البيانات الأساسية</th>
<th style="width:140px;">التصنيف</th>
<th style="width:140px;">الموقع والعهدة</th>
<th style="width:100px;">دورة الحياة</th>
<th style="width:100px;">المالية</th>
<th style="width:90px;">الحالة والصحة</th>
</tr>
</thead>
<tbody>
<?php foreach ($pageRows as $i => $r):
$cc = $r['criticality_class'] ?? 'C'; $ccol = $CRIT_COLORS[$cc] ?? ['#f1f5f9','#64748b'];
$sc = $STATUS_COLORS[$r['status']] ?? ['#64748b','#f8fafc']; $sar = $STATUS_AR[$r['status']] ?? $r['status'];
$hs = (int)($r['health_score'] ?? 0); $hc = $hs>=75?'#10b981':($hs>=50?'#f59e0b':'#e11d48');
$path_str = e($r['cat_level1']) . " / " . e($r['cat_level3']);
?>
<tr>
<td style="font-weight:800; color:#94a3b8; text-align:center;"><?= $i+1 ?></td>
<td><div class="t-desc"><?= e($r['description'] ?: '—') ?></div><div class="t-tag"><?= e($r['tag_number']) ?></div><br><span style="font-size:8px;color:#64748b">SN: <?= e($r['serial_number']?:'N/A') ?></span><br><span style="font-size:8px;color:#8b5cf6">Mfg: <?= e($r['manufacturer_name']?:'—') ?> (<?= e($r['model_number']?:'—') ?>)</span></td>
<td><div style="font-size:9.5px;color:#0f172a; font-weight:800"><?= $path_str ?></div><span style="font-size:8px;color:#64748b">النوع: <?= e($ASSET_TYPE_AR[$r['asset_type']] ?? $r['asset_type']) ?></span></td>
<td><div style="font-size:9.5px;color:#0f172a; font-weight:800"><?= e($r['loc_building']) ?> / <?= e($r['loc_room']) ?></div><span style="font-size:8px;color:#64748b"><?= e($r['custodian_name'] ?: 'بدون عهدة') ?> (حساسية <?= e($cc) ?>)</span></td>
<td><div style="font-size:9px;color:#0f172a;">خدمة: <?= e($r['date_placed_in_service']?:'—') ?></div><span style="font-size:8px;color:#64748b">ضمان: <?= e($r['warranty_expiry']?:'—') ?></span></td>
<td><div style="font-size:9px;color:#0f172a; font-family:monospace">تكلفة: <?= e($r['original_cost']) ?></div><span style="font-size:8px;color:#64748b; font-family:monospace">صافي: <?= e($r['net_book_value']) ?></span></td>
<td><span class="p-badge" style="background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><?= e($sar) ?></span><div class="p-bar-wrap"><div class="p-bar-fill" style="width:<?= $hs ?>%;background:<?= $hc ?>"></div></div><div style="font-size:8px; font-weight:800; color:<?= $hc ?>; margin-top:2px;"><?= $hs ?>%</div></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="7" style="border:none; padding:0;">
<div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">الاسم والتوقيع</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد والتوقيع</div></div>
</div>
</td></tr></tfoot>
</table>
</div>
<?php endforeach; ?>
</body></html>
<?php exit;
}
/* ═══ 3. ب) طباعة لوحة المؤشرات (Dashboard Single A4 Print Mode) ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة المؤشرات - <?= e($report_title) ?></title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page { size: A4 landscape; margin: 0; }
*{ box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
body {
font-family: 'Tajawal', sans-serif !important; color: #1e293b;
margin: 0; padding: 0; background: #fff; font-weight: 500;
}
.a4-dashboard-container {
width: 297mm;
height: 209mm;
padding: 10mm;
margin: 0 auto;
display: flex;
flex-direction: column;
overflow: hidden;
page-break-after: avoid;
background: #fff;
}
.print-header { background: #0f172a; color: #fff; border-radius: 10px; padding: 12px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-shrink: 0;}
.ph-right { display: flex; align-items: center; gap: 14px; text-align: right; border-left: 1px solid rgba(255,255,255,0.2); padding-left: 20px; } .ph-h1 { font-size: 18px; font-weight: 900; color: #fff; margin-bottom: 4px;} .ph-h2 { font-size: 12px; color: #94a3b8; font-weight: 700;}
.ph-logo { height: 52px; width: auto; object-fit: contain; flex-shrink: 0; }
.ph-center { flex: 1; text-align: center; padding: 0 20px; } .ph-title { font-size: 18px; font-weight: 900; color: #38bdf8; }
.ph-left { text-align: left; font-size: 11px; color: #cbd5e1; } .ph-left strong { color: #fff; font-size: 13px; display: block; margin-top: 4px; }
.print-kpi-row { display: flex; justify-content: space-between; gap: 15px; margin-bottom: 15px; flex-shrink: 0; }
.print-kpi-box { flex: 1; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px 15px; text-align: center; background: #f8fafc; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
.print-kpi-val { font-size: 26px; font-weight: 900; color: #0f172a; margin-bottom: 2px; }
.print-kpi-lbl { font-size: 12px; font-weight: 800; color: #64748b; }
.print-charts-container { display: flex; flex-direction: column; gap: 15px; flex: 1; min-height: 0; }
.print-charts-row { display: flex; justify-content: space-between; gap: 15px; flex: 1; min-height: 0; }
.print-chart-box { flex: 1; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px; position: relative; background: #fff; display: flex; flex-direction: column; }
.print-chart-title { font-size: 13px; font-weight: 900; color: #1e293b; margin-bottom: 5px; flex-shrink: 0; text-align: center;}
.chart-render-area { flex: 1; min-height: 0; position: relative; }
.print-footer { text-align: center; font-size: 10px; color: #94a3b8; margin-top: 10px; border-top: 1px dashed #cbd5e1; padding-top: 5px; flex-shrink: 0;}
</style></head>
<body onload="setTimeout(()=>window.print(), 1500)">
<div class="a4-dashboard-container">
<div class="print-header">
<div class="ph-right"><?php if ($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title"><?= e($report_title) ?></div></div>
<div class="ph-left"><div>تاريخ التقرير:</div><strong><?= date('Y-m-d H:i') ?></strong><div style="margin-top:4px;">عدد السجلات: <strong><?= number_format($total_assets) ?></strong></div></div>
</div>
<div class="print-kpi-row">
<div class="print-kpi-box"><div class="print-kpi-val"><?= number_format($total_assets) ?></div><div class="print-kpi-lbl">إجمالي الأصول المفحوصة</div></div>
<div class="print-kpi-box"><div class="print-kpi-val text-green" style="color:#10b981"><?= $avg_health ?>%</div><div class="print-kpi-lbl">متوسط الصحة العام</div></div>
<div class="print-kpi-box"><div class="print-kpi-val" style="color:#d97706"><?= $active_rate ?>%</div><div class="print-kpi-lbl">نسبة الأصول النشطة</div></div>
<div class="print-kpi-box"><div class="print-kpi-val text-red" style="color:#e11d48"><?= number_format($ai_crit_count) ?></div><div class="print-kpi-lbl">أصول حرجة (فئة A)</div></div>
</div>
<div class="print-charts-container">
<div class="print-charts-row">
<div class="print-chart-box" style="flex:1.2">
<div class="print-chart-title">توزيع التصنيفات <?= $c_label ?></div>
<div class="chart-render-area" id="pChartCats"></div>
</div>
<div class="print-chart-box" style="flex:1">
<div class="print-chart-title">مؤشر الصحة العام للأصول</div>
<div class="chart-render-area" id="pChartHealth"></div>
</div>
</div>
<div class="print-charts-row">
<div class="print-chart-box" style="flex:1">
<div class="print-chart-title">توزيع الحالات التشغيلية</div>
<div class="chart-render-area" id="pChartStatus"></div>
</div>
<div class="print-chart-box" style="flex:1.2">
<div class="print-chart-title">خط الزمن: سنوات دخول الخدمة</div>
<div class="chart-render-area" id="pChartTimeline"></div>
</div>
</div>
</div>
<div class="print-footer">وثيقة تحليلية مستخرجة من نظام إدارة الأصول الطبية | تم الطباعة بواسطة: <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
<?php if (!empty($chart_categories)): ?>
new ApexCharts(document.querySelector("#pChartCats"), {
series: <?= json_encode(array_values($chart_categories)) ?>, labels: <?= json_encode(array_keys($chart_categories)) ?>,
chart: { type: 'donut', height: '100%', fontFamily: 'Tajawal', animations: { enabled: false } }, colors: ['#0ea5e9', '#8b5cf6', '#f59e0b', '#10b981', '#f43f5e', '#64748b'], plotOptions: { pie: { donut: { size: '60%' } } }, dataLabels: { enabled: true, style: { fontSize: '10px' } }, legend: { position: 'right', fontSize: '11px', fontWeight: 800 }
}).render();
<?php endif; ?>
<?php if (!empty($chart_status)): ?>
new ApexCharts(document.querySelector("#pChartStatus"), {
series: [{ name: 'الأصول', data: <?= json_encode(array_values($chart_status)) ?> }], chart: { type: 'bar', height: '100%', toolbar: { show: false }, fontFamily: 'Tajawal', animations: { enabled: false } }, xaxis: { categories: <?= json_encode(array_keys($chart_status)) ?>, labels: { style: { fontWeight: 800, fontSize:'11px' } } }, colors: ['#f59e0b'], plotOptions: { bar: { borderRadius: 4, columnWidth: '40%', distributed: true } }, dataLabels: { enabled: true, style: { fontSize: '12px' } }, legend: { show: false }
}).render();
<?php endif; ?>
<?php if (!empty($chart_timeline)): ?>
new ApexCharts(document.querySelector("#pChartTimeline"), {
series: [{ name: 'الأصول', data: <?= json_encode(array_values($chart_timeline)) ?> }], chart: { type: 'area', height: '100%', toolbar: { show: false }, fontFamily: 'Tajawal', animations: { enabled: false } }, xaxis: { categories: <?= json_encode(array_keys($chart_timeline)) ?>, labels: { style: { fontWeight: 800, fontSize:'11px' } } }, colors: ['#8b5cf6'], dataLabels: { enabled: true, style: { fontSize: '10px' } }, legend: { show: false }, stroke: { curve: 'smooth', width: 2 }
}).render();
<?php endif; ?>
new ApexCharts(document.querySelector("#pChartHealth"), {
series: [<?= $avg_health ?>], chart: { type: 'radialBar', height: '100%', fontFamily: 'Tajawal', animations: { enabled: false } }, plotOptions: { radialBar: { hollow: { size: '65%' }, track: { background: '#f1f5f9', strokeWidth: '100%' }, dataLabels: { show: true, name: { show: false }, value: { offsetY: 8, color: '<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>', fontSize: '30px', fontWeight: 900, formatter: function (val) { return val + "%" } } } }}, fill: { colors: ['<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>'] }, stroke: { lineCap: 'round' }
}).render();
});
</script>
</body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>التقرير التنفيذي للأصول</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --primary: #0ea5e9; --bg-main: #f0f4f8; --text-main: #0f172a; --text-muted: #64748b; --radius: 16px; }
body { font-family: 'Tajawal', sans-serif; background: var(--bg-main); color: var(--text-main); overflow-x: hidden;}
.wrap { max-width: 1400px; margin: 0 auto; padding: 20px; }
.view-toggles { display: flex; gap: 10px; margin-bottom: 20px; background: #fff; padding: 6px; border-radius: 99px; width: fit-content; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;}
.toggle-btn { padding: 10px 24px; border-radius: 99px; font-size: 13.5px; font-weight: 800; color: var(--text-muted); text-decoration: none; display: flex; align-items: center; gap: 8px; transition: 0.3s;}
.toggle-btn:hover { background: #f8fafc; color: var(--text-main); }
.toggle-btn.active { background: var(--text-main); color: #fff; box-shadow: 0 4px 10px rgba(15,23,42,0.2); }
.header-hero { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-radius: var(--radius); padding: 20px 28px; margin-bottom: 20px; color: #fff; display: flex; justify-content: space-between; align-items: center;}
.hero-title { font-size: 20px; font-weight: 900; margin: 0 0 4px 0;}
.grp { background: #fff; border: 1px solid #e2e8f0; border-radius: var(--radius); margin-bottom: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border-right: 4px solid var(--primary); }
.grp summary { padding: 14px 20px; cursor: pointer; font-weight: 900; font-size: 13.5px; display: flex; align-items: center; gap: 10px; }
.grp-body { padding: 0 20px 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.fld { display: flex; flex-direction: column; gap: 4px; text-align: right; } .fld label { font-size: 11.5px; font-weight: 800; color: var(--text-muted); }
.fld select, .fld input { background: #fff; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 12.5px; font-family: 'Tajawal'; font-weight: 500; }
.act-bar { background: #fff; border-radius: 100px; padding: 10px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 24px; border: 1px solid #e2e8f0; }
.btn-apply { background: var(--primary); color: #fff; border: none; border-radius: 99px; padding: 10px 24px; font-weight: 900; font-size: 13px; cursor: pointer; }
.btn-export { background: #fff; color: var(--text-main); border: 1.5px solid #cbd5e1; border-radius: 99px; padding: 8px 18px; font-weight: 800; font-size: 12px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: 0.2s;}
.btn-export:hover { background: #f8fafc; }
.btn-excel { border-color: #10b981; color: #10b981; } .btn-excel:hover { background: #ecfdf5; }
.btn-charts { border-color: #8b5cf6; color: #8b5cf6; } .btn-charts:hover { background: #f5f3ff; }
.pbtn { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 11px; font-weight: 700; cursor: pointer; color: #475569; flex: 1; text-align: center; }
.pbtn:hover { background: #e0f2fe; color: #0369a1; border-color: #0369a1; }
.clear-card { font-size: 11px; font-weight: 700; color: #94a3b8; display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; cursor: pointer; }
.clear-card:hover { background: #fef2f2; color: #ef4444; }
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 20px; }
.kpi-card { background: #fff; border-radius: var(--radius); padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 16px; transition: transform 0.2s;}
.kpi-card:hover { transform: translateY(-3px); }
.kpi-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
.kpi-info { flex: 1; text-align: right; } .kpi-title { font-size: 13px; font-weight: 800; color: var(--text-muted); margin-bottom: 4px; } .kpi-val { font-size: 22px; font-weight: 900; color: var(--text-main); line-height: 1; }
.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 24px; }
.chart-card { background: #fff; border-radius: var(--radius); padding: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0; display: flex; flex-direction: column;}
.chart-title { font-weight: 900; font-size: 14px; color: var(--text-main); margin-bottom: 10px; display: flex; align-items: center; justify-content: flex-start; gap: 8px; padding-bottom: 8px; border-bottom: 1px dashed #e2e8f0;}
.master-table { width: 100%; border-collapse: separate; border-spacing: 0 6px; background: transparent; }
.master-table th { background: transparent; color: #64748b; padding: 6px 14px; text-align: right; font-size: 12px; font-weight: 900; }
.master-table td { background: #fff; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; border-top: 1px solid #f1f5f9; vertical-align: top; text-align: right; }
.master-table td:first-child { border-right: 1px solid #f1f5f9; border-radius: 0 10px 10px 0; }
.master-table td:last-child { border-left: 1px solid #f1f5f9; border-radius: 10px 0 0 10px; }
.master-table tr:hover td { box-shadow: 0 6px 15px rgba(0,0,0,0.03); transform: scale(1.001); transition: 0.2s; }
.detailed-table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
.detailed-table th { background: #f8fafc; color: #334155; padding: 10px 14px; text-align: right; font-size: 12px; font-weight: 900; border-bottom: 2px solid #e2e8f0; border-left: 1px solid #e2e8f0;}
.detailed-table th:last-child { border-left: none; }
.detailed-table td { background: #fff; padding: 12px 14px; vertical-align: top; text-align: right; border-bottom: 1px solid #e2e8f0; border-left: 1px dashed #f1f5f9;}
.detailed-table td:last-child { border-left: none; }
.detailed-table tr:hover td { background: #f8fafc; box-shadow: inset 3px 0 0 var(--primary); }
.info-stack { display: flex; flex-direction: column; gap: 6px; text-align: right; }
.info-stack .title { font-size: 13.5px; font-weight: 900; color: #0369a1; }
.info-stack .tag-sn { font-size: 11px; font-family: monospace; color: #d97706; font-weight: 800; }
.info-stack .meta { font-size: 11.5px; color: #475569; font-weight: 700; }
.badge-stack { padding: 4px 8px; border-radius: 6px; font-size: 10.5px; font-weight: 800; display: inline-flex; align-items: center; gap: 4px; width: fit-content;}
.h-bar-container { display: flex; align-items: center; justify-content: flex-start; gap: 8px; margin-top: 8px; } .h-bar-num { font-size: 11px; font-weight: 900; width: 25px; text-align: left;} .h-bar-bg { flex: 1; height: 5px; background: #f1f5f9; border-radius: 99px; overflow: hidden; } .h-bar-fill { height: 100%; border-radius: 99px; transition: width 1.5s ease; }
.info-row { display: flex; align-items: center; justify-content: flex-start; gap: 8px; font-size: 11px; font-weight: 800; margin-bottom: 4px; } .info-row.tag { color: #d97706; }  .info-row.desc { color: #0369a1; font-size: 12.5px; font-weight: 900; }  .info-row.model { color: #7c3aed; font-size: 10px; }
.cat-row { display: flex; align-items: center; justify-content: flex-start; gap: 8px; font-size: 11.5px; font-weight: 900; color: #0f172a; margin-bottom: 4px; } .cat-row.sub { font-size: 10.5px; color: #64748b; font-weight: 700; } .cat-badge { display: inline-flex; align-items: center; gap: 4px; background: #e0f2fe; color: #0284c7; padding: 4px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; margin-top: 2px;}
.loc-row { display: flex; align-items: center; justify-content: flex-start; gap: 8px; font-size: 11.5px; font-weight: 900; color: #0f172a; margin-bottom: 4px; } .loc-row.room { font-size: 10.5px; color: #64748b; font-weight: 700; } .loc-row.date { color: #3b82f6; font-size: 10px; }
.stat-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 99px; font-size: 11px; font-weight: 900; margin-bottom: 8px; } .cust-row { display: flex; align-items: center; justify-content: flex-start; gap: 8px; font-size: 10.5px; font-weight: 800; color: #0f172a; }
.hlth-row { display: flex; justify-content: space-between; font-size: 10.5px; font-weight: 800; color: #94a3b8; margin-bottom: 4px; } .hlth-val { font-weight: 900; }
</style>
</head>
<body class="app-layout">
<?php $__f_backup = $f ?? []; include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH . '/includes/topbar.php'; $f = $__f_backup; ?>
<main class="page-content"><div class="wrap">
<div class="view-toggles">
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة القيادة التنفيذية</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> السجل التفصيلي الشامل (مكدس)</a>
</div>
<div class="header-hero">
<div>
<h1 class="hero-title"><i class="fa-solid <?= $view_mode==='executive'?'fa-satellite-dish':'fa-server' ?>" style="color:#38bdf8; margin-left:8px;"></i> <?= $view_mode==='executive'?'التقرير التنفيذي للأصول':'السجل المالي والفني الشامل' ?></h1>
<div style="color:#cbd5e1; font-size:13px; font-weight:500;">
<?= $view_mode==='executive' ? 'لوحة قيادة تفاعلية مدمجة للتحليل واتخاذ القرارات السريعة' : 'عرض جدولي مكدس يضم كافة البيانات التشغيلية والمالية بدقة مريحة للعين' ?>
</div>
</div>
<div style="text-align:left; font-size:11px; color:#94a3b8;">تاريخ التقرير<br><strong style="font-size:15px; color:#fff;"><?= date('Y-m-d') ?></strong></div>
</div>

<?php
// ═══ شريط التقارير المحفوظة (خارج الـ form الرئيسي — يمنع تداخل الـ forms) ═══
$sr_module   = 'assets';
$sr_filters  = $f;
$sr_view     = $view_mode;
$sr_base_url = BASE_URL;
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary); background:#e0f2fe; padding:6px; border-radius:6px;"></i> الفلاتر الذكية <span class="clear-card" onclick="event.preventDefault(); clearGroup('classify')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>النوع العام</label><select name="asset_type" id="atype"><option value="">— الكل —</option><?php foreach ($ASSET_TYPE_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['asset_type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>المجموعة الرئيسية</label><select id="c1"><option value="">— الكل —</option></select></div>
<div class="fld"><label>الفئة الفرعية</label><select id="c2" disabled><option value="">— الكل —</option></select></div>
<div class="fld"><label>الصنف</label><select id="c3" disabled><option value="">— الكل —</option></select></div>
<div class="fld"><label>الأهمية (Criticality)</label><select name="criticality" id="crit"><option value="">— الكل —</option><?php foreach ($CRIT_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['criticality']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>الحالة التشغيلية</label><select name="status" id="stat"><option value="">— الكل —</option><?php foreach ($STATUS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<input type="hidden" name="c1" id="c1h" value="<?= e($f['c1']) ?>"><input type="hidden" name="c2" id="c2h" value="<?= e($f['c2']) ?>"><input type="hidden" name="c3" id="c3h" value="<?= e($f['c3']) ?>">
</div>
</details>
<div style="display:flex; gap:16px; align-items:flex-start;">
<details class="grp" open style="flex:1; margin-bottom:0;">
<summary><i class="fa-solid fa-industry" style="color:#8b5cf6; background:#f5f3ff; padding:6px; border-radius:6px;"></i> المصنِّع والموديل <span class="clear-card" onclick="event.preventDefault(); clearGroup('manf')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>الشركة المصنِّعة</label><select id="manf"><option value="">— الكل —</option></select></div>
<div class="fld"><label>الموديل (مرتبط بالشركة)</label><select id="mdl" <?= empty($f['manufacturer'])?'disabled':'' ?>><option value="">— الكل —</option></select></div>
<input type="hidden" name="manufacturer" id="manfh" value="<?= e($f['manufacturer']) ?>"><input type="hidden" name="model" id="mdlh" value="<?= e($f['model']) ?>">
</div>
</details>
<details class="grp" open style="flex:1; margin-bottom:0;">
<summary><i class="fa-solid fa-clipboard-check" style="color:#0d9488; background:#f0fdfa; padding:6px; border-radius:6px;"></i> جودة البيانات والتحقق <span class="clear-card" onclick="event.preventDefault(); clearGroup('quality')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>اكتمال البيانات</label><select name="completeness"><option value="">— الكل —</option><?php foreach ($COMPLETENESS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['completeness']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>حالة التحقق</label><select name="verified"><option value="">— الكل —</option><?php foreach ($VERIFIED_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['verified']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
</div>
</details>
</div>
<details class="grp" open>
<summary><i class="fa-solid fa-calendar-days" style="color:#d97706; background:#fffbeb; padding:6px; border-radius:6px;"></i> التواريخ ودورة الحياة <span class="clear-card" onclick="event.preventDefault(); clearGroup('dates')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>تاريخ الخدمة من</label><input type="date" name="svc_from" id="svcFrom" value="<?= e($f['svc_from']) ?>"></div>
<div class="fld"><label>إلى</label><input type="date" name="svc_to" id="svcTo" value="<?= e($f['svc_to']) ?>"></div>
<div class="fld"><label>اختصار سريع (خدمة)</label>
<div style="display:flex;gap:6px;">
<div class="pbtn" onclick="quickRange('svc',3)">3 أشهر</div>
<div class="pbtn" onclick="quickRange('svc',6)">6 أشهر</div>
<div class="pbtn" onclick="quickRange('svc',12)">سنة</div>
</div>
</div>
<div class="fld"><label>حالة الضمان</label><select name="warranty"><option value="">— الكل —</option><?php foreach ($WARR_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['warranty']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>تاريخ التسجيل بالنظام من</label><input type="date" name="reg_from" id="regFrom" value="<?= e($f['reg_from']) ?>"></div>
<div class="fld"><label>إلى</label><input type="date" name="reg_to" id="regTo" value="<?= e($f['reg_to']) ?>"></div>
<div class="fld"><label>اختصار سريع (تسجيل)</label>
<div style="display:flex;gap:6px;">
<div class="pbtn" onclick="quickRange('reg',3)">3 أشهر</div>
<div class="pbtn" onclick="quickRange('reg',6)">6 أشهر</div>
<div class="pbtn" onclick="quickRange('reg',12)">سنة</div>
</div>
</div>
</div>
</details>
<div class="act-bar">
<div style="display:flex; gap:10px;">
<button type="submit" class="btn-apply"><i class="fa-solid fa-bolt"></i> تحديث التقرير</button>
<a href="?view=<?= e($view_mode) ?>" class="btn-export" style="border-color:#ef4444; color:#ef4444;"><i class="fa-solid fa-xmark"></i> مسح كل الفلاتر</a>
</div>
<?php if ($can_export && $has_filters && $results): ?>
<div style="display:flex; gap:10px;">
<a class="btn-export btn-excel" href="?excel=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-file-excel"></i> تصدير Excel</a>
<a class="btn-export" href="?print=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-print"></i> طباعة الجداول (PDF)</a>
<a class="btn-export btn-charts" href="?print_charts=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-chart-pie"></i> طباعة لوحة المؤشرات (PDF)</a>
</div>
<?php endif; ?>
</div>
</form>
<?php if ($has_filters && $results): ?>
<!-- === 1. عرض لوحة القيادة التنفيذية === -->
<?php if ($view_mode === 'executive'): ?>
<div class="kpi-grid">
<div class="kpi-card"><div class="kpi-icon" style="background:#e0f2fe; color:#0284c7;"><i class="fa-solid fa-boxes-stacked"></i></div><div class="kpi-info"><div class="kpi-title">إجمالي الأصول</div><div class="kpi-val"><?= number_format($total_assets) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#ecfdf5; color:#10b981;"><i class="fa-solid fa-heart-pulse"></i></div><div class="kpi-info"><div class="kpi-title">متوسط الصحة العام</div><div class="kpi-val"><?= $avg_health ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7; color:#d97706;"><i class="fa-solid fa-bolt"></i></div><div class="kpi-info"><div class="kpi-title">نسبة الأصول الفعّالة</div><div class="kpi-val"><?= $active_rate ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef2f2; color:#e11d48;"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="kpi-info"><div class="kpi-title">أصول حرجة (فئة A)</div><div class="kpi-val"><?= number_format($ai_crit_count) ?></div></div></div>
</div>
<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> توزيع التصنيفات <?= $c_label ?></div><div id="chartCategories" style="flex:1; min-height:200px;"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#8b5cf6"></i> خط الزمن: دخول الخدمة</div><div id="chartTimeline" style="flex:1; min-height:200px;"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-column" style="color:#f59e0b"></i> توزيع الحالات التشغيلية</div><div id="chartStatus" style="flex:1; min-height:200px;"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-gauge-high" style="color:#10b981"></i> مؤشر الصحة الإجمالي</div><div id="chartHealth" style="flex:1; min-height:200px; display:flex; align-items:center; justify-content:center;"></div></div>
</div>
<table class="master-table">
<thead><tr>
<th>البيانات الأساسية <i class="fa-solid fa-tags" style="margin-right:4px;"></i></th>
<th>التصنيف <i class="fa-solid fa-sitemap" style="margin-right:4px;"></i></th>
<th>الموقع <i class="fa-solid fa-location-dot" style="margin-right:4px;"></i></th>
<th>الحالة والعهدة <i class="fa-solid fa-info-circle" style="margin-right:4px;"></i></th>
<th>الفئة والصحة <i class="fa-solid fa-heart-pulse" style="margin-right:4px;"></i></th>
</tr></thead>
<tbody>
<?php foreach ($results as $r):
$cc = $r['criticality_class'] ?? 'C'; $ccol = $CRIT_COLORS[$cc] ?? ['#f1f5f9','#64748b'];
$sc = $STATUS_COLORS[$r['status']] ?? ['#ecfdf5','#10b981']; $sar = $STATUS_AR[$r['status']] ?? $r['status'];
$hs = (int)($r['health_score'] ?? 0); $hc = $hs>=75?'#10b981':($hs>=50?'#f59e0b':'#ef4444');
$is_verified = ($r['verified_status'] && strpos($r['verified_status'], 'لم يتم') === false);
$v_color = $is_verified ? '#10b981' : '#f59e0b';
$v_text = $is_verified ? 'معتمد' : 'قيد المراجعة';
?>
<tr>
<td>
<div class="info-row desc"><i class="fa-solid fa-cube" style="color:#0369a1;"></i> <a href="<?= BASE_URL ?>/assets/device_dossier.php?id=<?= (int)$r['id'] ?>" style="color:inherit;text-decoration:none;"><?= e($r['description']) ?></a></div>
<div class="info-row tag"><i class="fa-solid fa-tag" style="color:#d97706;"></i> <?= e($r['tag_number']) ?></div>
<div class="info-row model"><i class="fa-solid fa-industry" style="color:#7c3aed;"></i> <?= e($r['manufacturer_name'] ?: 'غير محدد') ?><?= $r['model_number'] ? ' — ' . e($r['model_number']) : '' ?></div>
</td>
<td>
<div class="cat-row"><i class="fa-solid fa-folder" style="color:#0369a1;"></i> <?= e($r['cat_level1'] ?: 'غير مصنف') ?></div>
<div class="cat-row sub"><i class="fa-solid fa-chevron-left" style="font-size:8px; color:#cbd5e1;"></i> <?= e($r['cat_level2'] ?: '—') ?> <i class="fa-solid fa-chevron-left" style="font-size:8px; color:#cbd5e1;"></i> <?= e($r['cat_level3'] ?: '—') ?></div>
<div style="text-align:right"><span class="cat-badge"><i class="fa-solid fa-layer-group"></i> <?= e($ASSET_TYPE_AR[$r['asset_type']] ?? 'أخرى') ?></span></div>
</td>
<td>
<div class="loc-row"><i class="fa-solid fa-building" style="color:#0d9488;"></i> <?= e($r['loc_building'] ?: 'غير محدد') ?></div>
<div class="loc-row room"><i class="fa-solid fa-door-open" style="color:#94a3b8;"></i> <?= e($r['loc_room'] ?: '—') ?></div>
<div class="loc-row date"><i class="fa-regular fa-calendar" style="color:#3b82f6;"></i> الخدمة: <?= e($r['date_placed_in_service'] ?: '—') ?></div>
</td>
<td>
<div class="stat-badge" style="background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><i class="fa-solid fa-circle" style="font-size:8px;"></i> <?= e($sar) ?></div>
<div class="cust-row"><i class="fa-solid fa-users" style="color:#0d9488;"></i> <?= e($r['custodian_name'] ?: $r['custodian_dept_name'] ?: 'بدون عهدة') ?></div>
</td>
<td>
<div class="hlth-row"><span>الفئة:</span> <span class="hlth-val" style="color:<?= $ccol[1] ?>"><?= e($cc) ?></span></div>
<div class="hlth-row"><span>التدقيق:</span> <span class="hlth-val" style="color:<?= $v_color ?>"><?= $v_text ?></span></div>
<div class="h-bar-container">
<div class="h-bar-num" style="color:<?= $hc ?>"><?= $hs ?>%</div>
<div class="h-bar-bg" dir="ltr"><div class="h-bar-fill" style="background:<?= $hc ?>; width:0%" data-width="<?= $hs ?>%"></div></div>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<!-- === 2. عرض السجل التفصيلي المكدس (بدون سكرول) === -->
<?php else: ?>
<div style="margin-bottom: 15px; font-weight:800; color:var(--text-main);">
إجمالي السجلات المستخرجة: <span style="background:var(--primary); color:#fff; padding:2px 8px; border-radius:10px;"><?= count($results) ?></span>
</div>
<div style="background:#fff; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.03); border:1px solid #e2e8f0; overflow:hidden;">
<table class="detailed-table">
<thead>
<tr>
<th style="width:30px">#</th>
<th style="width:23%"><i class="fa-solid fa-tags" style="color:#0ea5e9; margin-left:4px"></i> البيانات الأساسية (النوع)</th>
<th style="width:20%"><i class="fa-solid fa-sitemap" style="color:#8b5cf6; margin-left:4px"></i> التصنيف الهرمي</th>
<th style="width:17%"><i class="fa-solid fa-location-dot" style="color:#f59e0b; margin-left:4px"></i> الموقع والعهدة (والفئة)</th>
<th style="width:14%"><i class="fa-solid fa-timeline" style="color:#10b981; margin-left:4px"></i> دورة الحياة والضمان</th>
<th style="width:12%"><i class="fa-solid fa-sack-dollar" style="color:#f43f5e; margin-left:4px"></i> البيانات المالية</th>
<th style="width:14%"><i class="fa-solid fa-clipboard-check" style="color:#64748b; margin-left:4px"></i> الحالة والجودة والصيانة</th>
</tr>
</thead>
<tbody>
<?php foreach ($results as $i => $r):
$cc = $r['criticality_class'] ?? 'C'; $ccol = $CRIT_COLORS[$cc] ?? ['#f1f5f9','#64748b'];
$sc = $STATUS_COLORS[$r['status']] ?? ['#ecfdf5','#10b981']; $sar = $STATUS_AR[$r['status']] ?? $r['status'];
$c_cmp = $COMPLETENESS_AR[$r['data_completeness']] ?? $r['data_completeness'];
$cmp_col = $r['data_completeness']=='complete'?'#16a34a':($r['data_completeness']=='partial'?'#d97706':'#dc2626');
$cmp_bg = $r['data_completeness']=='complete'?'#f0fdf4':($r['data_completeness']=='partial'?'#fffbeb':'#fef2f2');
$hs = (int)($r['health_score'] ?? 0); $hc = $hs>=75?'#10b981':($hs>=50?'#f59e0b':'#ef4444');
$repl_status = $r['in_replacement_plan'] ? 'مدرج بالخطة' : 'غير مدرج';
$repl_color = $r['in_replacement_plan'] ? '#10b981' : '#64748b';
?>
<tr>
<td style="font-weight:900; color:#94a3b8; text-align:center"><?= $i+1 ?></td>
<td>
<div class="info-stack">
<a href="<?= BASE_URL ?>/assets/device_dossier.php?id=<?= (int)$r['id'] ?>" class="title" style="text-decoration:none"><?= e($r['description']) ?></a>
<div class="tag-sn">TAG: <?= e($r['tag_number']) ?> &nbsp;|&nbsp; SN: <?= e($r['serial_number'] ?: 'N/A') ?></div>
<div class="meta"><i class="fa-solid fa-cube"></i> النوع: <?= e($ASSET_TYPE_AR[$r['asset_type']] ?? $r['asset_type']) ?></div>
<div class="meta" style="color:#7c3aed"><i class="fa-solid fa-industry"></i> <?= e($r['manufacturer_name'] ?: 'غير محدد') ?><?= $r['model_number'] ? ' — ' . e($r['model_number']) : '' ?></div>
</div>
</td>
<td>
<div class="info-stack">
<div style="font-size:12px; font-weight:800; color:#1e293b;"><i class="fa-regular fa-folder-open"></i> <?= e($r['cat_level1'] ?: 'غير مصنف') ?></div>
<div class="meta"><i class="fa-solid fa-chevron-left" style="font-size:8px"></i> <?= e($r['cat_level2'] ?: '—') ?></div>
<div class="meta"><i class="fa-solid fa-chevron-left" style="font-size:8px"></i> <?= e($r['cat_level3'] ?: '—') ?></div>
</div>
</td>
<td>
<div class="info-stack">
<div style="font-size:12px; font-weight:800; color:#1e293b;"><i class="fa-solid fa-building"></i> <?= e($r['loc_building']) ?> / <?= e($r['loc_room']) ?></div>
<div class="meta"><i class="fa-solid fa-user-tie"></i> <?= e($r['custodian_name'] ?: $r['custodian_dept_name'] ?: 'بدون عهدة') ?> (<?= e($r['custodian_type']=='personal'?'شخصية':($r['custodian_type']=='dept'?'قسم':'مشتركة')) ?>)</div>
<div><span class="badge-stack" style="background:<?= $ccol[0] ?>; color:<?= $ccol[1] ?>"><i class="fa-solid fa-bolt"></i> فئة الحساسية: <?= e($cc) ?></span></div>
</div>
</td>
<td>
<div class="info-stack">
<div class="meta"><i class="fa-solid fa-play"></i> خدمة: <strong style="color:#0f172a"><?= e($r['date_placed_in_service'] ?: '—') ?></strong></div>
<div class="meta"><i class="fa-solid fa-shield"></i> ضمان: <strong style="color:#0f172a"><?= e($r['warranty_expiry'] ?: '—') ?></strong> (<?= e($r['warranty_type'] ?: '—') ?>)</div>
<div class="meta"><i class="fa-solid fa-rotate"></i> استبدال: <strong style="color:<?= $repl_color ?>"><?= $repl_status ?></strong> (<?= e($r['expected_replacement_date'] ?: '—') ?>)</div>
</div>
</td>
<td>
<div class="info-stack" style="font-family:monospace; font-size:11.5px;">
<div style="color:#475569">تكلفة: <strong><?= e(number_format((float)$r['original_cost'], 2)) ?></strong></div>
<div style="color:#0369a1">صافي: <strong><?= e(number_format((float)$r['net_book_value'], 2)) ?></strong></div>
<div style="color:#e11d48">إهلاك: <strong><?= e(number_format((float)$r['accumulated_depreciation'], 2)) ?></strong></div>
<div style="color:#8b5cf6; font-size:10px; margin-top:4px; border-top:1px dashed #e2e8f0; padding-top:4px;">الصيانة: <strong><?= e(number_format((float)$r['total_maintenance_cost'], 2)) ?></strong></div>
</div>
</td>
<td>
<div class="info-stack">
<div><span class="badge-stack" style="background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><?= e($sar) ?></span></div>
<div class="h-bar-container"><div class="h-bar-num" style="color:<?= $hc ?>"><?= $hs ?>%</div><div class="h-bar-bg" dir="ltr"><div class="h-bar-fill" style="background:<?= $hc ?>; width:0%" data-width="<?= $hs ?>%"></div></div></div>
<div class="meta" style="margin-top:2px;"><i class="fa-solid fa-database" style="color:<?= $cmp_col ?>"></i> البيانات: <strong style="color:<?= $cmp_col ?>"><?= e($c_cmp) ?></strong></div>
<div class="meta"><i class="fa-solid fa-shield-halved" style="color:#0ea5e9"></i> حالة التدقيق: <strong style="color:#0ea5e9"><?= e($r['verified_status'] ?: '—') ?></strong></div>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php endif; ?>
</div></main></div></body>
<script>
setTimeout(() => { document.querySelectorAll('.h-bar-fill').forEach(bar => { bar.style.width = bar.getAttribute('data-width'); }); }, 100);
const CAT_TREE = <?= json_encode($cat_tree, JSON_UNESCAPED_UNICODE) ?>;
const $c1=document.getElementById('c1'), $c2=document.getElementById('c2'), $c3=document.getElementById('c3');
Object.keys(CAT_TREE).forEach(l1=>{ const o=document.createElement('option'); o.value=l1; o.textContent=l1; if(l1===<?= json_encode($f['c1'], JSON_UNESCAPED_UNICODE) ?>) o.selected=true; $c1.appendChild(o); });
function fillLevel(sel, keys, pre){ sel.innerHTML='<option value="">— الكل —</option>'; keys.forEach(k=>{ const o=document.createElement('option'); o.value=k; o.textContent=k; if(k===pre)o.selected=true; sel.appendChild(o); }); sel.disabled = keys.length===0; }
$c1.addEventListener('change',()=>{ fillLevel($c2, $c1.value?Object.keys(CAT_TREE[$c1.value]||{}):[]); fillLevel($c3,[]); syncHidden(); });
$c2.addEventListener('change',()=>{ fillLevel($c3, ($c1.value&&$c2.value)?(CAT_TREE[$c1.value][$c2.value]||[]):[]); syncHidden(); });
$c3.addEventListener('change', syncHidden);
function syncHidden(){ document.getElementById('c1h').value=$c1.value; document.getElementById('c2h').value=$c2.value; document.getElementById('c3h').value=$c3.value; }
<?php if ($f['c1']): ?>fillLevel($c2, Object.keys(CAT_TREE[<?= json_encode($f['c1'], JSON_UNESCAPED_UNICODE) ?>]||{}), <?= json_encode($f['c2'], JSON_UNESCAPED_UNICODE) ?>);<?php endif; ?>
<?php if ($f['c1'] && $f['c2']): ?>fillLevel($c3, (CAT_TREE[<?= json_encode($f['c1'], JSON_UNESCAPED_UNICODE) ?>]||{})[<?= json_encode($f['c2'], JSON_UNESCAPED_UNICODE) ?>]||[], <?= json_encode($f['c3'], JSON_UNESCAPED_UNICODE) ?>);<?php endif; ?>
/* ═══ المصنِّع ← الموديل ═══ */
const MANF_TREE = <?= json_encode($manf_tree, JSON_UNESCAPED_UNICODE) ?>;
const $manf=document.getElementById('manf'), $mdl=document.getElementById('mdl');
Object.keys(MANF_TREE).forEach(m=>{ const o=document.createElement('option'); o.value=m; o.textContent=m; if(m===<?= json_encode($f['manufacturer'], JSON_UNESCAPED_UNICODE) ?>) o.selected=true; $manf.appendChild(o); });
$manf.addEventListener('change',()=>{
fillLevel($mdl, $manf.value?(MANF_TREE[$manf.value]||[]):[]);
document.getElementById('manfh').value = $manf.value;
document.getElementById('mdlh').value = '';
});
$mdl.addEventListener('change',()=>{ document.getElementById('mdlh').value = $mdl.value; });
<?php if ($f['manufacturer']): ?>fillLevel($mdl, MANF_TREE[<?= json_encode($f['manufacturer'], JSON_UNESCAPED_UNICODE) ?>]||[], <?= json_encode($f['model'], JSON_UNESCAPED_UNICODE) ?>);<?php endif; ?>
/* ═══ اختصارات التاريخ السريعة ═══ */
function quickRange(prefix, months){
const to = new Date(); const from = new Date(); from.setMonth(from.getMonth()-months);
document.getElementById(prefix==='svc'?'svcTo':'regTo').value = to.toISOString().slice(0,10);
document.getElementById(prefix==='svc'?'svcFrom':'regFrom').value = from.toISOString().slice(0,10);
}
/* ═══ مسح فلاتر بطاقة واحدة فقط ═══ */
function clearGroup(name){
if (name === 'classify') {
document.getElementById('atype').value = '';
document.getElementById('crit').value = '';
document.getElementById('stat').value = '';
$c1.value = ''; fillLevel($c2, []); fillLevel($c3, []);
syncHidden();
} else if (name === 'manf') {
$manf.value = ''; fillLevel($mdl, []);
document.getElementById('manfh').value = '';
document.getElementById('mdlh').value = '';
} else if (name === 'quality') {
document.querySelector('select[name="completeness"]').value = '';
document.querySelector('select[name="verified"]').value = '';
} else if (name === 'dates') {
document.getElementById('svcFrom').value = '';
document.getElementById('svcTo').value = '';
document.getElementById('regFrom').value = '';
document.getElementById('regTo').value = '';
document.querySelector('select[name="warranty"]').value = '';
}
}
/* الرسوم البيانية التنفيذية للويب */
<?php if ($view_mode === 'executive' && $has_filters && $results): ?>
document.addEventListener("DOMContentLoaded", function() {
<?php if (!empty($chart_categories)): ?>
new ApexCharts(document.querySelector("#chartCategories"), {
series: <?= json_encode(array_values($chart_categories)) ?>, labels: <?= json_encode(array_keys($chart_categories)) ?>,
chart: { type: 'donut', height: '100%', fontFamily: 'Tajawal' }, colors: ['#0ea5e9', '#8b5cf6', '#f59e0b', '#10b981', '#f43f5e', '#64748b'],
plotOptions: { pie: { donut: { size: '65%' } } }, dataLabels: { enabled: false }, legend: { position: 'bottom', fontSize: '11px', fontWeight: 700 }
}).render();
<?php endif; ?>
<?php if (!empty($chart_timeline)): ?>
new ApexCharts(document.querySelector("#chartTimeline"), {
series: [{ name: 'دخول الخدمة', data: <?= json_encode(array_values($chart_timeline)) ?> }],
chart: { type: 'area', height: '100%', toolbar: { show: false }, fontFamily: 'Tajawal', zoom: {enabled: false} },
xaxis: { categories: <?= json_encode(array_keys($chart_timeline)) ?>, labels: { style: { fontWeight: 700, fontSize:'10px' } } },
stroke: { curve: 'smooth', width: 3 }, colors: ['#8b5cf6'],
fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.7, opacityTo: 0.1, stops: [0, 90, 100] } }, dataLabels: { enabled: false }
}).render();
<?php endif; ?>
<?php if (!empty($chart_status)): ?>
new ApexCharts(document.querySelector("#chartStatus"), {
series: [{ name: 'الأصول', data: <?= json_encode(array_values($chart_status)) ?> }],
chart: { type: 'bar', height: '100%', toolbar: { show: false }, fontFamily: 'Tajawal' },
xaxis: { categories: <?= json_encode(array_keys($chart_status)) ?>, labels: { style: { fontWeight: 700, fontSize:'10px' } } },
colors: ['#f59e0b'], plotOptions: { bar: { borderRadius: 4, columnWidth: '40%', distributed: true } },
dataLabels: { enabled: true, style: { fontSize: '10px' } }, legend: { show: false }
}).render();
<?php endif; ?>
new ApexCharts(document.querySelector("#chartHealth"), {
series: [<?= $avg_health ?>], chart: { type: 'radialBar', height: '120%', fontFamily: 'Tajawal' },
plotOptions: { radialBar: { hollow: { size: '65%', dropShadow: { enabled: true, top: 3, left: 0, blur: 4, opacity: 0.1 } }, track: { background: '#f1f5f9', strokeWidth: '100%', margin: 0 },
dataLabels: { show: true, name: { offsetY: 20, show: true, color: '#64748b', fontSize: '12px', fontWeight:700 }, value: { offsetY: -10, color: '<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>', fontSize: '26px', fontWeight: 900, show: true, formatter: function (val) { return val + "%" } } }
}}, fill: { colors: ['<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>'] }, stroke: { lineCap: 'round' }, labels: ['متوسط الصحة']
}).render();
});
<?php endif; ?>
</script>
</html>