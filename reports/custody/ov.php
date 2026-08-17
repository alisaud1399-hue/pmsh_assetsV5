<?php
/**
* reports/custody/overview.php — مُولّد تقارير العهدة v3 (Final)
* ──────────────────────────────────────────────────────────────────
*   • المنطق: عهدة شخصية فقط (لا dept/shared)
*   • البيانات تظهر دائماً — الفلاتر تُضيّق فقط
*   • 3 أوضاع تصدير: Excel / PDF رسمي / Dashboard A4
*   • 10 فلاتر ذكية + AI Alerts + 4 رسوم بيانية
*   • نظام التقارير المحفوظة الموحد
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
// ⛔ معطّل مؤقتاً لاختبار العزل:
// require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';

page_guard('reports.custody.overview');

// ✅ تطبيق تقرير محفوظ إذا طُلب (بعد page_guard لضمان الصلاحيات)
if (isset($_GET['apply_saved'])) {
    sr_apply_saved($pdo, (int)$_GET['apply_saved'], (int)current_user()['id']);
}

$rtl          = is_rtl();
$can_see_all  = can_see_all();
$can_export   = can('reports.custody.overview', 'export');
$user_dept_id = (int)(current_user()['department_id'] ?? 0);
$active_nav   = 'reports.custody';

$hospital     = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster      = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src     = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$ASSET_TYPE_AR = ['medical'=>'طبي','it'=>'تقنية معلومات','infrastructure'=>'بنية تحتية','hvac'=>'تكييف وتهوية','transport'=>'نقل','furniture'=>'أثاث','other'=>'أخرى'];
$CRIT_AR       = ['A'=>'A — حرج جداً','B'=>'B — متوسط','C'=>'C — منخفض'];
$STATUS_AR     = ['active'=>'نشط','under_maintenance'=>'صيانة','inactive'=>'خارج الخدمة','pending_commissioning'=>'بانتظار التشغيل','pending_receipt'=>'بانتظار الاستلام'];
$WARR_AR       = ['expired'=>'منتهٍ','soon'=>'ينتهي خلال 90 يوماً','valid'=>'ساري'];

/* ═══ قراءة الفلاتر ونمط العرض ═══ */
$view_mode = $_GET['view'] ?? 'executive';

function valid_date(string $v): string {
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}

$f = [
    'dept'           => (int)($_GET['dept'] ?? 0),
    'custodian_user' => (int)($_GET['custodian_user'] ?? 0),
    'asset_type'     => trim($_GET['asset_type'] ?? ''),
    'criticality'    => trim($_GET['criticality'] ?? ''),
    'status'         => trim($_GET['status'] ?? ''),
    'manufacturer'   => trim($_GET['manufacturer'] ?? ''),
    'model'          => trim($_GET['model'] ?? ''),
    'building'       => trim($_GET['building'] ?? ''),
    'floor'          => trim($_GET['floor'] ?? ''),
    'warranty'       => trim($_GET['warranty'] ?? ''),
    'q'              => trim($_GET['q'] ?? ''),
    'custody_from'   => valid_date(trim($_GET['custody_from'] ?? '')),
    'custody_to'     => valid_date(trim($_GET['custody_to'] ?? '')),
    'age_months'     => (int)($_GET['age_months'] ?? 0),
    'with_custodian' => !isset($_GET['with_custodian']) || $_GET['with_custodian'] !== '0',
];
$has_filters = array_filter(array_diff_key($f, ['with_custodian'=>0])) !== [];

$print_mode        = isset($_GET['print'])        && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode        = isset($_GET['excel'])        && $can_export;

/* ═══ بناء الاستعلام ═══ */
$scope  = data_scope('custody', 'a');
$where  = ["a.status = 'active'"];
$params = [];
/* ═ توحيد المعاملات: حوّل أي ? موضعية من data_scope إلى :مسماة (يمنع HY093) ═ */
$__sw = (string)($scope['where'] ?? '1=1');
$__sp = (array)($scope['params'] ?? []);
if (strpos($__sw, '?') !== false) {
    $__named = []; $__i = 0;
    $__sw = preg_replace_callback('/\?/', function () use (&$__i, $__sp, &$__named) {
        $__named['scope' . $__i] = $__sp[$__i] ?? null; $__i++;
        return ':scope' . ($__i - 1);
    }, $__sw);
    $params = $__named;
} else {
    foreach ($__sp as $k => $v) $params[ltrim((string)$k, ':')] = $v;
}
$where[] = $__sw;

if (!$can_see_all) {
    $where[] = '(a.custodian_dept_id = :d OR a.department_id = :d)';
    $params['d'] = $user_dept_id;
}
if ($f['with_custodian']) $where[] = 'a.custodian_user_id IS NOT NULL';
if ($f['dept'])           { $where[] = 'a.custodian_dept_id = :dept';  $params['dept']  = $f['dept']; }
if ($f['custodian_user']) { $where[] = 'a.custodian_user_id = :cuser'; $params['cuser'] = $f['custodian_user']; }
if ($f['asset_type'])     { $where[] = 'a.asset_type = :atype';        $params['atype'] = $f['asset_type']; }
if ($f['criticality'])    { $where[] = 'a.criticality_class = :crit';  $params['crit']  = $f['criticality']; }
if ($f['status'])         { $where[] = 'a.status = :st';               $params['st']    = $f['status']; }
if ($f['manufacturer'])   { $where[] = 'a.manufacturer_name = :manf';  $params['manf']  = $f['manufacturer']; }
if ($f['model'])          { $where[] = 'a.model_number = :mdl';        $params['mdl']   = $f['model']; }
if ($f['building'])       { $where[] = 'a.loc_building = :bld';        $params['bld']   = $f['building']; }
if ($f['floor'])          { $where[] = 'a.loc_floor = :flr';           $params['flr']   = $f['floor']; }
if ($f['custody_from'])   { $where[] = 'a.custody_date >= :cfrom';     $params['cfrom'] = $f['custody_from']; }
if ($f['custody_to'])     { $where[] = 'a.custody_date <= :cto';       $params['cto']   = $f['custody_to']; }
if ($f['age_months'] > 0) {
    $where[] = 'a.custody_date IS NOT NULL AND a.custody_date <= DATE_SUB(CURDATE(), INTERVAL :age MONTH)';
    $params['age'] = $f['age_months'];
}
if ($f['warranty'] === 'expired')   { $where[] = 'a.warranty_expiry IS NOT NULL AND a.warranty_expiry < CURDATE()'; }
elseif ($f['warranty'] === 'soon')  { $where[] = 'a.warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)'; }
elseif ($f['warranty'] === 'valid') { $where[] = 'a.warranty_expiry IS NOT NULL AND a.warranty_expiry > DATE_ADD(CURDATE(), INTERVAL 90 DAY)'; }
if ($f['q'] !== '') {
    $where[] = "(a.tag_number LIKE :q OR a.description LIKE :q OR a.description_ar LIKE :q OR a.serial_number LIKE :q OR a.manufacturer_name LIKE :q OR u.full_name LIKE :q)";
    $params['q'] = '%' . $f['q'] . '%';
}

/* ✅ البيانات تُجلب دائماً */
$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 1000;
$sql = "SELECT
    a.id, a.tag_number, a.serial_number, a.description, a.description_ar,
    a.asset_type, a.criticality_class, a.status, a.health_score,
    a.manufacturer_name, a.model_number,
    a.cost, a.original_cost, a.warranty_expiry, a.warranty_type,
    a.loc_building, a.loc_floor, a.loc_room,
    a.custodian_user_id, a.custodian_dept_id,
    a.custodian_name, a.custodian_dept_name, a.custody_date, a.custody_notes,
    DATEDIFF(CURDATE(), a.custody_date) AS custody_days,
    d.name AS dept_name,
    u.full_name AS custodian_full_name, u.username AS custodian_username
    FROM assets a
    LEFT JOIN departments d ON d.id = a.custodian_dept_id
    LEFT JOIN users u ON u.id = a.custodian_user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        CASE WHEN a.criticality_class='A' THEN 1 WHEN a.criticality_class='B' THEN 2 ELSE 3 END,
        a.custody_date DESC, a.tag_number ASC
    LIMIT $row_cap";

$st = $pdo->prepare($sql);
$st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);

/* ═══ حساب KPIs والرسوم و AI ═══ */
$total_assets = count($results);
$total_cost = 0; $unique_custodians = []; $unique_depts = [];
$no_custodian_count = 0; $custody_days_total = 0; $custody_with_date = 0;
$old_custody_count = 0; $custodian_counts = []; $custodian_names = [];
$chart_depts = []; $chart_timeline = []; $chart_top_custodians = [];
$ai_crit_count = 0; $total_health = 0;

foreach ($results as $r) {
    $total_cost += (float)($r['cost'] ?? 0);
    $total_health += (int)($r['health_score'] ?? 0);
    if ($r['criticality_class'] === 'A') $ai_crit_count++;

    if (!empty($r['custodian_user_id'])) {
        $uid = $r['custodian_user_id'];
        $unique_custodians[$uid] = true;
        $custodian_counts[$uid] = ($custodian_counts[$uid] ?? 0) + 1;
        $custodian_names[$uid] = $r['custodian_full_name'] ?: ($r['custodian_name'] ?: $r['custodian_username']);
    } else {
        $no_custodian_count++;
    }
    if (!empty($r['custodian_dept_id'])) $unique_depts[$r['custodian_dept_id']] = true;

    if (!empty($r['custody_days'])) {
        $custody_days_total += (int)$r['custody_days'];
        $custody_with_date++;
        if ($r['custody_days'] > 730) $old_custody_count++;
    }

    $dept_name = $r['dept_name'] ?: ($r['custodian_dept_name'] ?: 'بدون قسم');
    $chart_depts[$dept_name] = ($chart_depts[$dept_name] ?? 0) + 1;

    if (!empty($r['custody_date']) && preg_match('/^(\d{4})/', $r['custody_date'], $m)) {
        $chart_timeline[$m[1]] = ($chart_timeline[$m[1]] ?? 0) + 1;
    }
}

/* أعلى 5 مستلمين */
arsort($custodian_counts);
$chart_top_custodians = array_slice($custodian_counts, 0, 5, true);
foreach ($chart_top_custodians as $uid => $cnt) {
    $chart_top_custodians[$custodian_names[$uid] ?? "مستخدم #$uid"] = $cnt;
    unset($chart_top_custodians[$uid]);
}

$unique_custodians = count($unique_custodians);
$unique_depts = count($unique_depts);
$avg_custody_months = $custody_with_date > 0 ? round(($custody_days_total / $custody_with_date) / 30) : 0;
$avg_health = $total_assets > 0 ? round($total_health / $total_assets) : 0;

$max_custodian_name = '—'; $max_custodian_count = 0;
if (!empty($custodian_counts)) {
    $max_custodian_count = reset($custodian_counts);
    $max_custodian_name = array_key_first($custodian_counts);
    $max_custodian_name = $custodian_names[array_key_first($custodian_counts)] ?? $max_custodian_name;
}

arsort($chart_depts);
$chart_depts = array_slice($chart_depts, 0, 8, true);
ksort($chart_timeline);

/* AI Alerts */
$ai_class = 'ai-success'; $ai_icon = 'fa-check-circle'; $ai_msg = '✨ تقرير العهدة: جميع المؤشرات ضمن النطاق الصحي.';
$ai_alerts = [];
if ($no_custodian_count > 0) $ai_alerts[] = "⚠️ $no_custodian_count أصل نشط بدون مستلم — يُنصح بتعيين عهدتهم";
if ($old_custody_count > 5) $ai_alerts[] = "🕐 $old_custody_count أصل بعهدة أكثر من سنتين — قد تحتاج مراجعة دورية";
if ($max_custodian_count > 50) $ai_alerts[] = "🔴 المستلم '$max_custodian_name' لديه $max_custodian_count أصل — عبء عالٍ";
if ($ai_crit_count > 0) $ai_alerts[] = "⚡ $ai_crit_count أصل من الفئة الحرجة (A) تحت العهدة";
if (!empty($ai_alerts)) {
    $ai_class = count($ai_alerts) >= 3 ? 'ai-danger' : 'ai-warning';
    $ai_icon  = count($ai_alerts) >= 3 ? 'fa-triangle-exclamation' : 'fa-bell';
    $ai_msg   = implode(' | ', $ai_alerts);
}

/* عنوان التقرير */
$title_parts = [];
if ($f['dept']) {
    $d = $pdo->prepare("SELECT name FROM departments WHERE id=?"); $d->execute([$f['dept']]);
    if ($dn = $d->fetchColumn()) $title_parts[] = $dn;
}
if ($f['asset_type']) $title_parts[] = $ASSET_TYPE_AR[$f['asset_type']] ?? $f['asset_type'];
if ($f['criticality']) $title_parts[] = 'فئة ' . $f['criticality'];
$report_title = 'تقرير العهدة' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* قوائم الفلاتر */
$depts = $pdo->query("SELECT id, name FROM departments WHERE id IN (SELECT DISTINCT custodian_dept_id FROM assets WHERE status='active' AND custodian_dept_id IS NOT NULL) ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$buildings = $pdo->query("SELECT DISTINCT loc_building FROM assets WHERE loc_building IS NOT NULL AND loc_building != '' AND status='active' ORDER BY loc_building")->fetchAll(PDO::FETCH_COLUMN);
$floors = $pdo->query("SELECT DISTINCT loc_floor FROM assets WHERE loc_floor IS NOT NULL AND loc_floor != '' AND status='active' ORDER BY loc_floor")->fetchAll(PDO::FETCH_COLUMN);
$custodians = $pdo->query("SELECT DISTINCT u.id, u.full_name FROM users u INNER JOIN assets a ON a.custodian_user_id = u.id WHERE a.status='active' ORDER BY u.full_name")->fetchAll(PDO::FETCH_ASSOC);

$manf_rows = $pdo->prepare("SELECT DISTINCT manufacturer_name, model_number FROM assets WHERE manufacturer_name IS NOT NULL AND manufacturer_name != '' AND status='active' ORDER BY manufacturer_name, model_number");
$manf_rows->execute();
$manf_tree = [];
foreach ($manf_rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $m = trim($r['manufacturer_name']); $md = trim($r['model_number'] ?? '');
    if (!isset($manf_tree[$m])) $manf_tree[$m] = [];
    if ($md && !in_array($md, $manf_tree[$m])) $manf_tree[$m][] = $md;
}

$CRIT_COLORS = ['A'=>['#fff1f2','#be123c','#f43f5e'],'B'=>['#fffbeb','#b45309','#f59e0b'],'C'=>['#f0fdf4','#15803d','#10b981']];
$STATUS_COLORS = ['active'=>['#10b981','#ecfdf5'],'under_maintenance'=>['#3b82f6','#eff6ff'],'inactive'=>['#64748b','#f8fafc'],'pending_commissioning'=>['#f59e0b','#fffbeb'],'pending_receipt'=>['#0284c7','#e0f2fe']];

/* ═══ 1. تصدير Excel ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Custody_Report_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:13px}th{background:#0f172a;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:10px;text-align:center}td{border:1px solid #cbd5e1;padding:8px;text-align:center;vertical-align:middle}.tag{mso-number-format:"\@";font-weight:bold;color:#059669}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="17" style="font-size:16px;background:#0369a1;padding:15px">سجل العهدة المعتمد - <?= e($report_title) ?></th></tr>
<tr>
<th style="background:#1e293b">Tag</th><th style="background:#1e293b">Serial</th><th style="background:#1e293b">Description</th><th style="background:#1e293b">Description AR</th>
<th style="background:#8b5cf6">Manufacturer</th><th style="background:#8b5cf6">Model</th>
<th style="background:#334155">Type</th><th style="background:#334155">Criticality</th><th style="background:#334155">Status</th>
<th style="background:#0284c7">Building</th><th style="background:#0284c7">Floor</th><th style="background:#0284c7">Room</th>
<th style="background:#0d9488">Custodian</th><th style="background:#0d9488">Department</th>
<th style="background:#b45309">Custody Date</th><th style="background:#b45309">Months</th>
<th style="background:#16a34a">Cost (SAR)</th>
</tr></thead><tbody>
<?php foreach($results as $r): $months = !empty($r['custody_days']) ? round($r['custody_days']/30) : ''; ?>
<tr>
<td class="tag"><?= e($r['tag_number']) ?></td><td><?= e($r['serial_number']) ?></td><td><?= e($r['description']) ?></td><td><?= e($r['description_ar']) ?></td>
<td><?= e($r['manufacturer_name']) ?></td><td><?= e($r['model_number']) ?></td>
<td><?= e($ASSET_TYPE_AR[$r['asset_type']] ?? $r['asset_type']) ?></td><td><?= e($r['criticality_class']) ?></td><td><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></td>
<td><?= e($r['loc_building']) ?></td><td><?= e($r['loc_floor']) ?></td><td><?= e($r['loc_room']) ?></td>
<td><?= e($r['custodian_full_name'] ?: $r['custodian_name']) ?></td><td><?= e($r['dept_name'] ?: $r['custodian_dept_name']) ?></td>
<td><?= e($r['custody_date']) ?></td><td><?= e($months) ?></td>
<td><?= e($r['cost']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></body></html>
<?php exit;
}

/* ═══ 2. طباعة PDF الرسمية ═══ */
if ($print_mode) {
    $ROWS_PER_PAGE = 8;
    $pages = array_chunk($results, $ROWS_PER_PAGE, true);
    $total_pages = max(1, count($pages));
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>الوثيقة الرسمية - <?= e($report_title) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:landscape;margin:12mm 10mm}
*{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;color:#1e293b;margin:0;background:#fff}
.print-page{page-break-after:always}.print-page:last-child{page-break-after:auto}
.print-header{background:linear-gradient(135deg,#f8fafc 0%,#ecfdf5 100%);border:1px solid #cbd5e1;border-radius:10px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.ph-right{display:flex;align-items:center;gap:12px;text-align:right;border-left:1px solid #cbd5e1;padding-left:18px}
.ph-h1{font-size:16px;font-weight:800;color:#0f172a}.ph-h2{font-size:11px;color:#475569;font-weight:700}
.ph-logo{height:46px;width:auto;object-fit:contain}
.ph-center{flex:1;text-align:center;padding:0 16px}.ph-title{font-size:16px;font-weight:800;color:#059669}
.ph-left{text-align:left;font-size:10px;color:#475569}
.ph-pagebadge{background:#059669;color:#fff;padding:3px 10px;border-radius:4px;font-size:9px;font-weight:800;display:inline-block;margin-bottom:4px}
table.data-table{width:100%;border-collapse:collapse;font-size:10px;border:1.5px solid #cbd5e1}
table.data-table th{background:#f1f5f9;padding:8px;text-align:right;font-weight:900;border:1px solid #cbd5e1}
table.data-table td{padding:6px 8px;border:1px solid #e2e8f0;vertical-align:middle;text-align:right}
table.data-table tbody tr:nth-child(even) td{background:#fafaf9}
.t-desc{font-weight:800;font-size:11px;color:#0f172a}.t-tag{display:inline-block;background:#f8fafc;padding:2px 6px;border-radius:4px;font-size:9px;color:#475569;font-family:monospace;border:1px solid #e2e8f0}
.p-crit{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:4px;font-weight:800;font-size:10px}
.print-footer{display:flex;justify-content:space-around;padding:14px 10px 4px;border-top:1.5px solid #cbd5e1}
.sign-box{text-align:center;width:30%}.sign-box .title{font-size:11px;font-weight:800;margin-bottom:22px}
.sign-box .line{border-bottom:1px dashed #94a3b8;margin:0 15px 6px}.sign-box .hint{font-size:9px;color:#64748b}
</style></head>
<body onload="setTimeout(()=>window.print(),500)">
<?php foreach ($pages as $pageIdx => $pageRows): $pageNum = $pageIdx + 1; ?>
<div class="print-page">
<table class="data-table">
<thead>
<tr><th colspan="8" style="padding:0;border:none;background:none">
<div class="print-header">
<div class="ph-right"><?php if($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title">سجل العهدة المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pageNum ?> من <?= $total_pages ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — السجلات: <strong><?= count($results) ?></strong></div></div>
</div></th></tr>
<tr>
<th style="width:20px">#</th><th style="width:180px">البيانات الأساسية</th><th style="width:100px">الموقع</th>
<th style="width:100px">المستلم</th><th style="width:80px">القسم</th>
<th style="width:80px">تاريخ العهدة</th><th style="width:60px">القيمة</th><th style="width:40px">الفئة</th>
</tr></thead><tbody>
<?php foreach ($pageRows as $i => $r):
$cc = $r['criticality_class'] ?? 'C'; $ccol = $CRIT_COLORS[$cc] ?? ['#f1f5f9','#64748b'];
$months = !empty($r['custody_days']) ? round($r['custody_days']/30) . ' شهر' : '—';
?>
<tr>
<td style="font-weight:800;color:#94a3b8;text-align:center"><?= $i+1 ?></td>
<td><div class="t-desc"><?= e($r['description'] ?: '—') ?></div><div class="t-tag"><?= e($r['tag_number']) ?></div><br><span style="font-size:8px;color:#64748b">SN: <?= e($r['serial_number']?:'N/A') ?> | <?= e($r['manufacturer_name']?:'—') ?></span></td>
<td style="font-size:9px"><?= e($r['loc_building']) ?> / <?= e($r['loc_floor']) ?> / <?= e($r['loc_room']) ?></td>
<td style="font-size:9.5px;font-weight:800"><?= e($r['custodian_full_name'] ?: $r['custodian_name'] ?: 'بدون') ?></td>
<td style="font-size:9px"><?= e($r['dept_name'] ?: $r['custodian_dept_name'] ?: '—') ?></td>
<td style="font-size:9px"><?= e($r['custody_date'] ?: '—') ?><br><small style="color:#64748b"><?= e($months) ?></small></td>
<td style="font-size:9.5px;font-family:monospace;font-weight:800"><?= e(number_format((float)$r['cost'],0)) ?></td>
<td style="text-align:center"><span class="p-crit" style="background:<?= $ccol[0] ?>;color:<?= $ccol[1] ?>"><?= e($cc) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr><td colspan="8" style="border:none;padding:0">
<div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">الاسم والتوقيع</div></div>
<div class="sign-box"><div class="title">أمين العهدة الرئيسي</div><div class="line"></div><div class="hint">المراجعة والتوقيع</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد والتوقيع</div></div>
</div></td></tr></tfoot>
</table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. طباعة لوحة المؤشرات A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات العهدة</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:A4 landscape;margin:0}
*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;color:#1e293b;margin:0;background:#fff}
.a4-dashboard-container{width:297mm;height:209mm;padding:10mm;margin:0 auto;display:flex;flex-direction:column;overflow:hidden;background:#fff}
.print-header{background:#064e3b;color:#fff;border-radius:10px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-shrink:0}
.ph-right{display:flex;align-items:center;gap:14px;text-align:right;border-left:1px solid rgba(255,255,255,.2);padding-left:20px}
.ph-h1{font-size:18px;font-weight:900;color:#fff}.ph-h2{font-size:12px;color:#a7f3d0;font-weight:700}
.ph-logo{height:52px}.ph-center{flex:1;text-align:center;padding:0 20px}.ph-title{font-size:18px;font-weight:900;color:#6ee7b7}
.ph-left{text-align:left;font-size:11px;color:#a7f3d0}
.print-kpi-row{display:flex;gap:12px;margin-bottom:12px;flex-shrink:0}
.print-kpi-box{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;text-align:center;background:#f8fafc}
.print-kpi-val{font-size:24px;font-weight:900;color:#0f172a}.print-kpi-lbl{font-size:11px;font-weight:800;color:#64748b}
.print-charts-container{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0}
.print-charts-row{display:flex;gap:12px;flex:1;min-height:0}
.print-chart-box{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;background:#fff;display:flex;flex-direction:column}
.print-chart-title{font-size:12px;font-weight:900;margin-bottom:4px;text-align:center;flex-shrink:0}
.chart-render-area{flex:1;min-height:0;position:relative}
.print-footer{text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;border-top:1px dashed #cbd5e1;padding-top:4px}
</style></head>
<body onload="setTimeout(()=>window.print(),1500)">
<div class="a4-dashboard-container">
<div class="print-header">
<div class="ph-right"><?php if($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title"><?= e($report_title) ?></div></div>
<div class="ph-left"><div>تاريخ التقرير:</div><strong><?= date('Y-m-d') ?></strong><div style="margin-top:4px">السجلات: <strong><?= number_format($total_assets) ?></strong></div></div>
</div>
<div class="print-kpi-row">
<div class="print-kpi-box"><div class="print-kpi-val"><?= number_format($total_assets) ?></div><div class="print-kpi-lbl">إجمالي تحت العهدة</div></div>
<div class="print-kpi-box"><div class="print-kpi-val" style="color:#10b981"><?= number_format($unique_custodians) ?></div><div class="print-kpi-lbl">مستلم نشط</div></div>
<div class="print-kpi-box"><div class="print-kpi-val" style="color:#d97706"><?= $avg_custody_months ?> شهر</div><div class="print-kpi-lbl">متوسط مدة العهدة</div></div>
<div class="print-kpi-box"><div class="print-kpi-val" style="color:#059669"><?= number_format($total_cost,0) ?></div><div class="print-kpi-lbl">القيمة الإجمالية (ر.س)</div></div>
</div>
<div class="print-charts-container">
<div class="print-charts-row">
<div class="print-chart-box" style="flex:1.2"><div class="print-chart-title">توزيع العهدة حسب الأقسام (Top 8)</div><div class="chart-render-area" id="pChartDepts"></div></div>
<div class="print-chart-box" style="flex:1"><div class="print-chart-title">متوسط صحة الأصول تحت العهدة</div><div class="chart-render-area" id="pChartHealth"></div></div>
</div>
<div class="print-charts-row">
<div class="print-chart-box" style="flex:1"><div class="print-chart-title">أعلى 5 مستلمين</div><div class="chart-render-area" id="pChartCust"></div></div>
<div class="print-chart-box" style="flex:1.2"><div class="print-chart-title">خط الزمن: سنوات استلام العهدة</div><div class="chart-render-area" id="pChartTimeline"></div></div>
</div>
</div>
<div class="print-footer">وثيقة تحليلية من نظام إدارة الأصول — إعداد: <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded", function() {
<?php if (!empty($chart_depts)): ?>
new ApexCharts(document.querySelector("#pChartDepts"),{series:<?= json_encode(array_values($chart_depts)) ?>,labels:<?= json_encode(array_keys($chart_depts),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:['#059669','#0ea5e9','#8b5cf6','#f59e0b','#f43f5e','#64748b','#ec4899','#14b8a6'],plotOptions:{pie:{donut:{size:'60%'}}},dataLabels:{enabled:true,style:{fontSize:'10px'}},legend:{position:'right',fontSize:'10px',fontWeight:800}}).render();
<?php endif; ?>
new ApexCharts(document.querySelector("#pChartHealth"),{series:[<?= $avg_health ?>],chart:{type:'radialBar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},plotOptions:{radialBar:{hollow:{size:'65%'},track:{background:'#f1f5f9'},dataLabels:{show:true,name:{show:false},value:{offsetY:8,color:'<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>',fontSize:'28px',fontWeight:900,formatter:function(v){return v+'%'}}}}},fill:{colors:['<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>']},stroke:{lineCap:'round'}}).render();
<?php if (!empty($chart_top_custodians)): ?>
new ApexCharts(document.querySelector("#pChartCust"),{series:[{name:'الأصول',data:<?= json_encode(array_values($chart_top_custodians)) ?>}],chart:{type:'bar',height:'100%',toolbar:{show:false},fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($chart_top_custodians),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontWeight:800,fontSize:'10px'},rotate:-45}},colors:['#059669'],plotOptions:{bar:{borderRadius:4,columnWidth:'45%',distributed:true}},dataLabels:{enabled:true,style:{fontSize:'12px'}},legend:{show:false}}).render();
<?php endif; ?>
<?php if (!empty($chart_timeline)): ?>
new ApexCharts(document.querySelector("#pChartTimeline"),{series:[{name:'العهدة',data:<?= json_encode(array_values($chart_timeline)) ?>}],chart:{type:'area',height:'100%',toolbar:{show:false},fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($chart_timeline)) ?>,labels:{style:{fontWeight:800,fontSize:'11px'}}},colors:['#8b5cf6'],dataLabels:{enabled:true,style:{fontSize:'10px'}},stroke:{curve:'smooth',width:2}}).render();
<?php endif; ?>
});
</script>
</body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مُولّد تقارير العهدة — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--primary:#059669;--primary-light:#ecfdf5;--bg-main:#f0f4f8;--text-main:#0f172a;--text-muted:#64748b;--radius:16px}
body{font-family:'Tajawal',sans-serif;background:var(--bg-main);color:var(--text-main);overflow-x:hidden}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.view-toggles{display:flex;gap:10px;margin-bottom:20px;background:#fff;padding:6px;border-radius:99px;width:fit-content;box-shadow:0 4px 15px rgba(0,0,0,.03);border:1px solid #e2e8f0}
.toggle-btn{padding:10px 24px;border-radius:99px;font-size:13.5px;font-weight:800;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:8px;transition:.3s}
.toggle-btn:hover{background:#f8fafc;color:var(--text-main)}
.toggle-btn.active{background:var(--primary);color:#fff;box-shadow:0 4px 10px rgba(5,150,105,.25)}
.header-hero{background:linear-gradient(135deg,#064e3b 0%,#059669 60%,#10b981 100%);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center;position:relative;overflow:hidden}
.header-hero::before{content:'';position:absolute;top:-40px;left:-40px;width:180px;height:180px;background:radial-gradient(circle,rgba(255,255,255,.10),transparent 70%);border-radius:50%}
.hero-title{font-size:20px;font-weight:900;margin:0 0 4px}
.ai-banner{border-radius:12px;padding:12px 18px;margin-bottom:16px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:10px;border:1.5px solid}
.ai-success{background:#ecfdf5;border-color:#6ee7b7;color:#065f46}
.ai-warning{background:#fffbeb;border-color:#fcd34d;color:#92400e}
.ai-danger{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.grp{background:#fff;border:1px solid #e2e8f0;border-radius:var(--radius);margin-bottom:14px;box-shadow:0 4px 15px rgba(0,0,0,.03);border-right:4px solid var(--primary)}
.grp summary{padding:14px 20px;cursor:pointer;font-weight:900;font-size:13.5px;display:flex;align-items:center;gap:10px;list-style:none}
.grp summary::-webkit-details-marker{display:none}
.grp-body{padding:0 20px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}
.fld{display:flex;flex-direction:column;gap:4px;text-align:right}
.fld label{font-size:11.5px;font-weight:800;color:var(--text-muted)}
.fld select,.fld input{background:#fff;border:1.5px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:12.5px;font-family:'Tajawal';font-weight:500;width:100%}
.clear-card{font-size:11px;font-weight:700;color:#94a3b8;display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:6px;cursor:pointer;margin-right:auto}
.clear-card:hover{background:#fef2f2;color:#ef4444}
.pbtn{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:11px;font-weight:700;cursor:pointer;color:#475569;flex:1;text-align:center}
.pbtn:hover{background:var(--primary-light);color:var(--primary);border-color:var(--primary)}
.act-bar{background:#fff;border-radius:100px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;box-shadow:0 4px 15px rgba(0,0,0,.03);margin-bottom:16px;border:1px solid #e2e8f0;flex-wrap:wrap;gap:8px}
.btn-apply{background:var(--primary);color:#fff;border:none;border-radius:99px;padding:10px 24px;font-weight:900;font-size:13px;cursor:pointer;font-family:'Tajawal'}
.btn-export{background:#fff;color:var(--text-main);border:1.5px solid #cbd5e1;border-radius:99px;padding:8px 18px;font-weight:800;font-size:12px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:.2s;font-family:'Tajawal'}
.btn-export:hover{background:#f8fafc}
.btn-excel{border-color:#10b981;color:#10b981}.btn-excel:hover{background:#ecfdf5}
.btn-charts{border-color:#8b5cf6;color:#8b5cf6}.btn-charts:hover{background:#f5f3ff}
.btn-print{border-color:#0ea5e9;color:#0ea5e9}.btn-print:hover{background:#e0f2fe}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px}
.kpi-card{background:#fff;border-radius:var(--radius);padding:20px;box-shadow:0 4px 15px rgba(0,0,0,.03);border:1px solid #e2e8f0;display:flex;align-items:center;gap:16px;transition:transform .2s}
.kpi-card:hover{transform:translateY(-3px)}
.kpi-icon{width:50px;height:50px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.kpi-info{flex:1;text-align:right}
.kpi-title{font-size:13px;font-weight:800;color:var(--text-muted);margin-bottom:4px}
.kpi-val{font-size:22px;font-weight:900;color:var(--text-main);line-height:1}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px;margin-bottom:24px}
.chart-card{background:#fff;border-radius:var(--radius);padding:16px;box-shadow:0 4px 15px rgba(0,0,0,.03);border:1px solid #e2e8f0;display:flex;flex-direction:column}
.chart-title{font-weight:900;font-size:14px;color:var(--text-main);margin-bottom:10px;display:flex;align-items:center;gap:8px;padding-bottom:8px;border-bottom:1px dashed #e2e8f0}
.master-table{width:100%;border-collapse:separate;border-spacing:0 6px;background:transparent}
.master-table th{background:transparent;color:#64748b;padding:6px 14px;text-align:right;font-size:12px;font-weight:900}
.master-table td{background:#fff;padding:10px 14px;border-bottom:1px solid #f1f5f9;border-top:1px solid #f1f5f9;vertical-align:top;text-align:right}
.master-table td:first-child{border-right:1px solid #f1f5f9;border-radius:0 10px 10px 0}
.master-table td:last-child{border-left:1px solid #f1f5f9;border-radius:10px 0 0 10px}
.master-table tr:hover td{box-shadow:0 6px 15px rgba(0,0,0,.03);transform:scale(1.001);transition:.2s}
.detailed-table{width:100%;border-collapse:separate;border-spacing:0 8px}
.detailed-table th{background:#f8fafc;color:#334155;padding:10px 14px;text-align:right;font-size:12px;font-weight:900;border-bottom:2px solid #e2e8f0}
.detailed-table td{background:#fff;padding:12px 14px;vertical-align:top;text-align:right;border-bottom:1px solid #e2e8f0}
.detailed-table tr:hover td{background:#f8fafc;box-shadow:inset 3px 0 0 var(--primary)}
.info-stack{display:flex;flex-direction:column;gap:6px;text-align:right}
.info-stack .title{font-size:13.5px;font-weight:900;color:#0369a1}
.info-stack .tag-sn{font-size:11px;font-family:monospace;color:#d97706;font-weight:800}
.info-stack .meta{font-size:11.5px;color:#475569;font-weight:700}
.badge-stack{padding:4px 8px;border-radius:6px;font-size:10.5px;font-weight:800;display:inline-flex;align-items:center;gap:4px;width:fit-content}
.h-bar-container{display:flex;align-items:center;gap:8px;margin-top:8px}
.h-bar-num{font-size:11px;font-weight:900;width:25px;text-align:left}
.h-bar-bg{flex:1;height:5px;background:#f1f5f9;border-radius:99px;overflow:hidden}
.h-bar-fill{height:100%;border-radius:99px;transition:width 1.5s ease}
.info-row{display:flex;align-items:center;gap:8px;font-size:11px;font-weight:800;margin-bottom:4px}
.info-row.tag{color:#d97706}.info-row.desc{color:#0369a1;font-size:12.5px;font-weight:900}.info-row.model{color:#7c3aed;font-size:10px}
.loc-row{display:flex;align-items:center;gap:8px;font-size:11.5px;font-weight:900;color:#0f172a;margin-bottom:4px}
.loc-row.room{font-size:10.5px;color:#64748b;font-weight:700}
.stat-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:99px;font-size:11px;font-weight:900;margin-bottom:8px}
.cust-row{display:flex;align-items:center;gap:8px;font-size:10.5px;font-weight:800;color:#0f172a}
.hlth-row{display:flex;justify-content:space-between;font-size:10.5px;font-weight:800;color:#94a3b8;margin-bottom:4px}
.hlth-val{font-weight:900}
.crit-badge{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:6px;font-weight:900;font-size:12px}
.empty-state{text-align:center;padding:60px 16px;color:#94a3b8;background:#fff;border-radius:var(--radius);border:1.5px solid #e2e8f0}
.empty-state i{font-size:48px;display:block;margin-bottom:12px;color:var(--primary)}
.back-link{font-size:12.5px;color:#475569;text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-bottom:12px;font-weight:600}
.back-link:hover{color:var(--primary)}
.checkbox-wrap{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:#475569;cursor:pointer}
.checkbox-wrap input{width:16px;height:16px;accent-color:var(--primary)}
@media(max-width:768px){.kpi-grid{grid-template-columns:1fr 1fr}.grp-body{grid-template-columns:1fr}.act-bar{border-radius:16px}}
</style>
</head>
<body<body class="standalone-report">
<style>
body.standalone-report{background:#f0f4f8}
body.standalone-report .page-content{padding:18px 4px}
body.standalone-report .wrap{max-width:1500px}
</style>
<main class="page-content"><div class="wrap">

<a href="<?= BASE_URL ?>/reports/custody/index.php" class="back-link">
    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i>
    <?= $rtl?'العودة إلى مركز تقارير العهدة':'Back to Custody Reports Hub' ?>
</a>

<div class="view-toggles">
    <a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة القيادة التنفيذية</a>
    <a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> السجل التفصيلي الشامل</a>
</div>

<div class="header-hero">
    <div>
        <h1 class="hero-title"><i class="fa-solid fa-handshake" style="color:#6ee7b7;margin-left:8px;"></i> <?= $view_mode==='executive'?'لوحة قيادة العهدة':'السجل التفصيلي للعهدة' ?></h1>
        <div style="color:#a7f3d0;font-size:13px;font-weight:500;">
            <?= $view_mode==='executive'?'تحليل ذكي لتوزيع العهد والمستلمين والأقسام':'عرض جدولي مكدس يضم كافة بيانات العهدة بدقة' ?>
        </div>
    </div>
    <div style="text-align:left;font-size:11px;color:#a7f3d0;">تاريخ التقرير<br><strong style="font-size:15px;color:#fff;"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($results): ?>
<div class="ai-banner <?= $ai_class ?>">
    <i class="fa-solid <?= $ai_icon ?>"></i>
    <span><?= e($ai_msg) ?></span>
</div>
<?php endif; ?>

<?php
// ═══ شريط التقارير المحفوظة (خارج الـ form الرئيسي) ═══
//$sr_module = 'custody';
//$sr_filters = $f;
//$sr_view = $view_mode;
//$sr_base_url = BASE_URL;
//include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<!-- ═══ الفلاتر ═══ -->
<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">

<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:var(--primary-light);padding:6px;border-radius:6px;"></i> الفلاتر الأساسية <span class="clear-card" onclick="event.preventDefault();clearGroup('basic')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
    <div class="fld"><label>القسم</label>
        <select name="dept" id="fDept"><option value="">— الكل —</option>
        <?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $f['dept']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="fld"><label>المستلم</label>
        <select name="custodian_user" id="fCustodian"><option value="">— الكل —</option>
        <?php foreach($custodians as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['custodian_user']===(int)$c['id']?'selected':'' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?>
        </select></div>
    <div class="fld"><label>نوع الأصل</label>
        <select name="asset_type" id="fAssetType"><option value="">— الكل —</option>
        <?php foreach($ASSET_TYPE_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['asset_type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
    <div class="fld"><label>الحساسية</label>
        <select name="criticality" id="fCrit"><option value="">— الكل —</option>
        <?php foreach($CRIT_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['criticality']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
    <div class="fld"><label>الحالة التشغيلية</label>
        <select name="status" id="fStatus"><option value="">— الكل —</option>
        <?php foreach($STATUS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
</div>
</details>

<div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap;">
<details class="grp" open style="flex:1;margin-bottom:0;min-width:300px;">
<summary><i class="fa-solid fa-industry" style="color:#8b5cf6;background:#f5f3ff;padding:6px;border-radius:6px;"></i> المصنِّع والموديل <span class="clear-card" onclick="event.preventDefault();clearGroup('manf')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
    <div class="fld"><label>الشركة المصنِّعة</label><select id="manf"><option value="">— الكل —</option></select></div>
    <div class="fld"><label>الموديل</label><select id="mdl" <?= empty($f['manufacturer'])?'disabled':'' ?>><option value="">— الكل —</option></select></div>
    <input type="hidden" name="manufacturer" id="manfh" value="<?= e($f['manufacturer']) ?>">
    <input type="hidden" name="model" id="mdlh" value="<?= e($f['model']) ?>">
</div>
</details>
<details class="grp" open style="flex:1;margin-bottom:0;min-width:300px;">
<summary><i class="fa-solid fa-location-dot" style="color:#0d9488;background:#f0fdfa;padding:6px;border-radius:6px;"></i> الموقع <span class="clear-card" onclick="event.preventDefault();clearGroup('location')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
    <div class="fld"><label>المبنى</label>
        <select name="building" id="fBuilding"><option value="">— الكل —</option>
        <?php foreach($buildings as $b): ?><option value="<?= e($b) ?>" <?= $f['building']===$b?'selected':'' ?>><?= e($b) ?></option><?php endforeach; ?>
        </select></div>
    <div class="fld"><label>الطابق</label>
        <select name="floor" id="fFloor"><option value="">— الكل —</option>
        <?php foreach($floors as $fl): ?><option value="<?= e($fl) ?>" <?= $f['floor']===$fl?'selected':'' ?>><?= e($fl) ?></option><?php endforeach; ?>
        </select></div>
</div>
</details>
</div>

<details class="grp" open style="margin-top:14px;">
<summary><i class="fa-solid fa-calendar-days" style="color:#d97706;background:#fffbeb;padding:6px;border-radius:6px;"></i> التواريخ والضمان <span class="clear-card" onclick="event.preventDefault();clearGroup('dates')"><i class="fa-solid fa-eraser"></i> مسح</span><i class="fa-solid fa-chevron-down chev" style="margin-right:auto"></i></summary>
<div class="grp-body">
    <div class="fld"><label>تاريخ العهدة من</label><input type="date" name="custody_from" id="custFrom" value="<?= e($f['custody_from']) ?>"></div>
    <div class="fld"><label>إلى</label><input type="date" name="custody_to" id="custTo" value="<?= e($f['custody_to']) ?>"></div>
    <div class="fld"><label>اختصار سريع</label>
        <div style="display:flex;gap:6px;">
            <div class="pbtn" onclick="quickRange(3)">3 أشهر</div>
            <div class="pbtn" onclick="quickRange(6)">6 أشهر</div>
            <div class="pbtn" onclick="quickRange(12)">سنة</div>
        </div></div>
    <div class="fld"><label>مدة العهدة (أقدم من)</label>
        <select name="age_months" id="fAge"><option value="">— الكل —</option>
        <option value="6" <?= $f['age_months']===6?'selected':'' ?>>أقدم من 6 أشهر</option>
        <option value="12" <?= $f['age_months']===12?'selected':'' ?>>أقدم من سنة</option>
        <option value="24" <?= $f['age_months']===24?'selected':'' ?>>أقدم من سنتين</option>
        <option value="36" <?= $f['age_months']===36?'selected':'' ?>>أقدم من 3 سنوات</option>
        </select></div>
    <div class="fld"><label>حالة الضمان</label>
        <select name="warranty" id="fWarranty"><option value="">— الكل —</option>
        <?php foreach($WARR_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['warranty']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select></div>
    <div class="fld"><label>بحث نصي</label><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="تاج / اسم / سيريال / مستلم..."></div>
    <div class="fld" style="justify-content:flex-end;">
        <label class="checkbox-wrap">
            <input type="checkbox" name="with_custodian" value="1" <?= $f['with_custodian']?'checked':'' ?>>
            فقط الأصول التي لها مستلم
        </label></div>
</div>
</details>

<div class="act-bar">
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" class="btn-apply"><i class="fa-solid fa-bolt"></i> تحديث التقرير</button>
        <a href="?view=<?= e($view_mode) ?>" class="btn-export" style="border-color:#ef4444;color:#ef4444;"><i class="fa-solid fa-xmark"></i> مسح الكل</a>
    </div>
    <?php if ($can_export && $results): ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn-export btn-excel" href="?excel=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-file-excel"></i> Excel</a>
        <a class="btn-export btn-print" href="?print=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-print"></i> PDF رسمي</a>
        <a class="btn-export btn-charts" href="?print_charts=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-chart-pie"></i> لوحة مؤشرات</a>
    </div>
    <?php endif; ?>
</div>
</form>

<?php if ($results): ?>

<!-- ═══ Executive View ═══ -->
<?php if ($view_mode === 'executive'): ?>
<div class="kpi-grid">
    <div class="kpi-card"><div class="kpi-icon" style="background:var(--primary-light);color:var(--primary);"><i class="fa-solid fa-handshake"></i></div><div class="kpi-info"><div class="kpi-title">إجمالي تحت العهدة</div><div class="kpi-val"><?= number_format($total_assets) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-icon" style="background:#ccfbf1;color:#0d9488;"><i class="fa-solid fa-user-tie"></i></div><div class="kpi-info"><div class="kpi-title">مستلمين نشطين</div><div class="kpi-val"><?= number_format($unique_custodians) ?></div></div></div>
    <div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#d97706;"><i class="fa-solid fa-clock"></i></div><div class="kpi-info"><div class="kpi-title">متوسط مدة العهدة</div><div class="kpi-val"><?= $avg_custody_months ?> <span style="font-size:13px;color:#94a3b8">شهر</span></div></div></div>
    <div class="kpi-card"><div class="kpi-icon" style="background:#e0f2fe;color:#0284c7;"><i class="fa-solid fa-coins"></i></div><div class="kpi-info"><div class="kpi-title">القيمة الإجمالية</div><div class="kpi-val"><?= number_format($total_cost,0) ?> <span style="font-size:13px;color:#94a3b8">ر.س</span></div></div></div>
</div>

<div class="dash-grid">
    <div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> توزيع الأقسام (Top 8)</div><div id="chartDepts" style="flex:1;min-height:200px;"></div></div>
    <div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-column" style="color:#8b5cf6"></i> أعلى 5 مستلمين</div><div id="chartCust" style="flex:1;min-height:200px;"></div></div>
    <div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#f59e0b"></i> خط الزمن: سنوات العهدة</div><div id="chartTimeline" style="flex:1;min-height:200px;"></div></div>
    <div class="chart-card"><div class="chart-title"><i class="fa-solid fa-gauge-high" style="color:#10b981"></i> متوسط صحة الأصول</div><div id="chartHealth" style="flex:1;min-height:200px;display:flex;align-items:center;justify-content:center;"></div></div>
</div>

<table class="master-table">
<thead><tr>
    <th>البيانات الأساسية <i class="fa-solid fa-tags" style="margin-right:4px;"></i></th>
    <th>الموقع <i class="fa-solid fa-location-dot" style="margin-right:4px;"></i></th>
    <th>المستلم والقسم <i class="fa-solid fa-users" style="margin-right:4px;"></i></th>
    <th>تاريخ العهدة والمدة <i class="fa-solid fa-calendar" style="margin-right:4px;"></i></th>
    <th>الفئة والقيمة <i class="fa-solid fa-coins" style="margin-right:4px;"></i></th>
</tr></thead>
<tbody>
<?php foreach($results as $r):
    $cc=$r['criticality_class']??'C';$ccol=$CRIT_COLORS[$cc]??['#f1f5f9','#64748b'];
    $sc=$STATUS_COLORS[$r['status']]??['#64748b','#f8fafc'];$sar=$STATUS_AR[$r['status']]??$r['status'];
    $hs=(int)($r['health_score']??0);$hc=$hs>=75?'#10b981':($hs>=50?'#f59e0b':'#ef4444');
    $months=!empty($r['custody_days'])?round($r['custody_days']/30):'—';
?>
<tr>
    <td>
        <div class="info-row desc"><i class="fa-solid fa-cube" style="color:#0369a1;"></i> <a href="<?= BASE_URL ?>/assets/device_dossier.php?id=<?= (int)$r['id'] ?>" style="color:inherit;text-decoration:none;"><?= e($r['description']?:'—') ?></a></div>
        <div class="info-row tag"><i class="fa-solid fa-tag" style="color:#d97706;"></i> <?= e($r['tag_number']) ?></div>
        <div class="info-row model"><i class="fa-solid fa-industry" style="color:#7c3aed;"></i> <?= e($r['manufacturer_name']?:'—') ?><?= $r['model_number']?' — '.e($r['model_number']):'' ?></div>
    </td>
    <td>
        <div class="loc-row"><i class="fa-solid fa-building" style="color:#0d9488;"></i> <?= e($r['loc_building']?:'—') ?></div>
        <div class="loc-row room"><i class="fa-solid fa-door-open" style="color:#94a3b8;"></i> <?= e($r['loc_floor']?:'—') ?> / <?= e($r['loc_room']?:'—') ?></div>
        <div><span class="stat-badge" style="background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><i class="fa-solid fa-circle" style="font-size:7px;"></i> <?= e($sar) ?></span></div>
    </td>
    <td>
        <div class="cust-row"><i class="fa-solid fa-user-tie" style="color:#0d9488;"></i> <?= e($r['custodian_full_name']?:$r['custodian_name']?:'بدون') ?></div>
        <div class="cust-row" style="color:#64748b;"><i class="fa-solid fa-building" style="color:#94a3b8;"></i> <?= e($r['dept_name']?:$r['custodian_dept_name']?:'—') ?></div>
    </td>
    <td>
        <div style="font-size:12px;font-weight:800;color:#0f172a;"><?= e($r['custody_date']?:'—') ?></div>
        <div style="font-size:10.5px;color:#64748b;margin-top:2px;">المدة: <strong style="color:<?= $months>24?'#ef4444':'#059669' ?>"><?= e($months) ?> شهر</strong></div>
    </td>
    <td>
        <div class="hlth-row"><span>الفئة:</span> <span class="crit-badge" style="background:<?= $ccol[0] ?>;color:<?= $ccol[1] ?>"><?= e($cc) ?></span></div>
        <div style="font-family:monospace;font-size:12px;font-weight:800;color:#0f172a;margin-top:4px;"><?= e(number_format((float)$r['cost'],0)) ?> <span style="font-size:10px;color:#94a3b8">ر.س</span></div>
        <div class="h-bar-container"><div class="h-bar-num" style="color:<?= $hc ?>"><?= $hs ?>%</div><div class="h-bar-bg" dir="ltr"><div class="h-bar-fill" style="background:<?= $hc ?>;width:0%" data-width="<?= $hs ?>%"></div></div></div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- ═══ Detailed View ═══ -->
<?php else: ?>
<div style="margin-bottom:15px;font-weight:800;color:var(--text-main);">
    إجمالي السجلات: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px;"><?= count($results) ?></span>
</div>
<div style="background:#fff;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,.03);border:1px solid #e2e8f0;overflow-x:auto;">
<table class="detailed-table">
<thead><tr>
    <th style="width:30px">#</th>
    <th style="width:22%"><i class="fa-solid fa-tags" style="color:#0ea5e9;margin-left:4px"></i> البيانات الأساسية</th>
    <th style="width:14%"><i class="fa-solid fa-location-dot" style="color:#f59e0b;margin-left:4px"></i> الموقع</th>
    <th style="width:16%"><i class="fa-solid fa-users" style="color:#8b5cf6;margin-left:4px"></i> المستلم والقسم</th>
    <th style="width:12%"><i class="fa-solid fa-calendar" style="color:#d97706;margin-left:4px"></i> تاريخ العهدة</th>
    <th style="width:12%"><i class="fa-solid fa-coins" style="color:#f43f5e;margin-left:4px"></i> القيمة والفئة</th>
    <th style="width:12%"><i class="fa-solid fa-shield" style="color:#64748b;margin-left:4px"></i> الضمان والصحة</th>
</tr></thead>
<tbody>
<?php foreach($results as $i=>$r):
    $cc=$r['criticality_class']??'C';$ccol=$CRIT_COLORS[$cc]??['#f1f5f9','#64748b'];
    $sc=$STATUS_COLORS[$r['status']]??['#64748b','#f8fafc'];$sar=$STATUS_AR[$r['status']]??$r['status'];
    $hs=(int)($r['health_score']??0);$hc=$hs>=75?'#10b981':($hs>=50?'#f59e0b':'#ef4444');
    $months=!empty($r['custody_days'])?round($r['custody_days']/30):'—';
    $warr_status='';
    if(!empty($r['warranty_expiry'])){$warr_status=$r['warranty_expiry']<date('Y-m-d')?'منتهٍ':(strtotime($r['warranty_expiry'])<=strtotime('+90 days')?'قريب':'ساري');}
    $warr_color=$warr_status==='منتهٍ'?'#ef4444':($warr_status==='قريب'?'#f59e0b':'#10b981');
?>
<tr>
    <td style="font-weight:900;color:#94a3b8;text-align:center"><?= $i+1 ?></td>
    <td>
        <div class="info-stack">
            <a href="<?= BASE_URL ?>/assets/device_dossier.php?id=<?= (int)$r['id'] ?>" class="title" style="text-decoration:none"><?= e($r['description']?:'—') ?></a>
            <div class="tag-sn">TAG: <?= e($r['tag_number']) ?> | SN: <?= e($r['serial_number']?:'N/A') ?></div>
            <div class="meta"><i class="fa-solid fa-cube"></i> <?= e($ASSET_TYPE_AR[$r['asset_type']]??$r['asset_type']) ?></div>
            <div class="meta" style="color:#7c3aed"><i class="fa-solid fa-industry"></i> <?= e($r['manufacturer_name']?:'—') ?><?= $r['model_number']?' — '.e($r['model_number']):'' ?></div>
        </div>
    </td>
    <td>
        <div class="info-stack">
            <div style="font-size:12px;font-weight:800;"><i class="fa-solid fa-building"></i> <?= e($r['loc_building']?:'—') ?></div>
            <div class="meta"><i class="fa-solid fa-door-open"></i> <?= e($r['loc_floor']?:'—') ?> / <?= e($r['loc_room']?:'—') ?></div>
            <div><span class="badge-stack" style="background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><?= e($sar) ?></span></div>
        </div>
    </td>
    <td>
        <div class="info-stack">
            <div style="font-size:12px;font-weight:800;"><i class="fa-solid fa-user-tie"></i> <?= e($r['custodian_full_name']?:$r['custodian_name']?:'بدون') ?></div>
            <div class="meta"><i class="fa-solid fa-building"></i> <?= e($r['dept_name']?:$r['custodian_dept_name']?:'—') ?></div>
            <?php if($r['custodian_username']): ?><div class="meta" style="font-family:monospace;font-size:10px;color:#94a3b8;">@<?= e($r['custodian_username']) ?></div><?php endif; ?>
        </div>
    </td>
    <td>
        <div class="info-stack">
            <div style="font-size:12px;font-weight:800;"><?= e($r['custody_date']?:'—') ?></div>
            <div class="meta">المدة: <strong style="color:<?= $months>24?'#ef4444':'#059669' ?>"><?= e($months) ?> شهر</strong></div>
            <?php if($r['custody_notes']): ?><div class="meta" style="font-size:10px;color:#94a3b8;"><i class="fa-solid fa-note-sticky"></i> <?= e(mb_strimwidth($r['custody_notes'],0,40,'...')) ?></div><?php endif; ?>
        </div>
    </td>
    <td>
        <div class="info-stack">
            <div style="font-family:monospace;font-size:13px;font-weight:900;"><?= e(number_format((float)$r['cost'],0)) ?> <span style="font-size:10px;color:#94a3b8">ر.س</span></div>
            <div><span class="crit-badge" style="background:<?= $ccol[0] ?>;color:<?= $ccol[1] ?>"><?= e($cc) ?></span></div>
        </div>
    </td>
    <td>
        <div class="info-stack">
            <?php if($warr_status): ?><div class="meta"><i class="fa-solid fa-shield" style="color:<?= $warr_color ?>"></i> الضمان: <strong style="color:<?= $warr_color ?>"><?= e($warr_status) ?></strong></div><?php endif; ?>
            <div class="h-bar-container"><div class="h-bar-num" style="color:<?= $hc ?>"><?= $hs ?>%</div><div class="h-bar-bg" dir="ltr"><div class="h-bar-fill" style="background:<?= $hc ?>;width:0%" data-width="<?= $hs ?>%"></div></div></div>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty-state">
    <i class="fa-solid fa-box-open"></i>
    <h3>لا توجد أصول تحت العهدة</h3>
    <p>لم يتم تعيين مستلمين بعد. ابدأ من "نقل العهد" في تبويب "دورة الأصل".</p>
</div>
<?php endif; ?>

</div></main>

<script>
setTimeout(()=>{document.querySelectorAll('.h-bar-fill').forEach(bar=>{bar.style.width=bar.getAttribute('data-width');});},100);

/* ═══ Manufacturer → Model cascading ═══ */
const MANF_TREE=<?= json_encode($manf_tree,JSON_UNESCAPED_UNICODE) ?>;
const $manf=document.getElementById('manf'),$mdl=document.getElementById('mdl');
function fillLevel(sel,keys,pre){sel.innerHTML='<option value="">— الكل —</option>';keys.forEach(k=>{const o=document.createElement('option');o.value=k;o.textContent=k;if(k===pre)o.selected=true;sel.appendChild(o);});sel.disabled=keys.length===0;}
Object.keys(MANF_TREE).forEach(m=>{const o=document.createElement('option');o.value=m;o.textContent=m;if(m===<?= json_encode($f['manufacturer'],JSON_UNESCAPED_UNICODE) ?>)o.selected=true;$manf.appendChild(o);});
$manf.addEventListener('change',()=>{fillLevel($mdl,$manf.value?(MANF_TREE[$manf.value]||[]):[]);document.getElementById('manfh').value=$manf.value;document.getElementById('mdlh').value='';});
$mdl.addEventListener('change',()=>{document.getElementById('mdlh').value=$mdl.value;});
<?php if($f['manufacturer']): ?>fillLevel($mdl,MANF_TREE[<?= json_encode($f['manufacturer'],JSON_UNESCAPED_UNICODE) ?>]||[],<?= json_encode($f['model'],JSON_UNESCAPED_UNICODE) ?>);<?php endif; ?>

/* ═══ Quick date range ═══ */
function quickRange(months){const to=new Date();const from=new Date();from.setMonth(from.getMonth()-months);document.getElementById('custTo').value=to.toISOString().slice(0,10);document.getElementById('custFrom').value=from.toISOString().slice(0,10);}

/* ═══ Clear filter groups ═══ */
function clearGroup(name){
    if(name==='basic'){document.getElementById('fDept').value='';document.getElementById('fCustodian').value='';document.getElementById('fAssetType').value='';document.getElementById('fCrit').value='';document.getElementById('fStatus').value='';}
    else if(name==='manf'){$manf.value='';fillLevel($mdl,[]);document.getElementById('manfh').value='';document.getElementById('mdlh').value='';}
    else if(name==='location'){document.getElementById('fBuilding').value='';document.getElementById('fFloor').value='';}
    else if(name==='dates'){document.getElementById('custFrom').value='';document.getElementById('custTo').value='';document.getElementById('fAge').value='';document.getElementById('fWarranty').value='';document.querySelector('input[name="q"]').value='';}
}

/* ═══ Charts ═══ */
<?php if($view_mode==='executive'&&$results): ?>
document.addEventListener("DOMContentLoaded",function(){
<?php if(!empty($chart_depts)): ?>
new ApexCharts(document.querySelector("#chartDepts"),{series:<?= json_encode(array_values($chart_depts)) ?>,labels:<?= json_encode(array_keys($chart_depts),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal'},colors:['#059669','#0ea5e9','#8b5cf6','#f59e0b','#f43f5e','#64748b','#ec4899','#14b8a6'],plotOptions:{pie:{donut:{size:'65%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();
<?php endif; ?>
<?php if(!empty($chart_top_custodians)): ?>
new ApexCharts(document.querySelector("#chartCust"),{series:[{name:'الأصول',data:<?= json_encode(array_values($chart_top_custodians)) ?>}],chart:{type:'bar',height:'100%',toolbar:{show:false},fontFamily:'Tajawal'},xaxis:{categories:<?= json_encode(array_keys($chart_top_custodians),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontWeight:700,fontSize:'10px'},rotate:-45}},colors:['#8b5cf6'],plotOptions:{bar:{borderRadius:4,columnWidth:'45%',distributed:true}},dataLabels:{enabled:true,style:{fontSize:'11px'}},legend:{show:false}}).render();
<?php endif; ?>
<?php if(!empty($chart_timeline)): ?>
new ApexCharts(document.querySelector("#chartTimeline"),{series:[{name:'العهدة',data:<?= json_encode(array_values($chart_timeline)) ?>}],chart:{type:'area',height:'100%',toolbar:{show:false},fontFamily:'Tajawal',zoom:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($chart_timeline)) ?>,labels:{style:{fontWeight:700,fontSize:'10px'}}},stroke:{curve:'smooth',width:3},colors:['#f59e0b'],fill:{type:'gradient',gradient:{shadeIntensity:1,opacityFrom:0.7,opacityTo:0.1,stops:[0,90,100]}},dataLabels:{enabled:false}}).render();
<?php endif; ?>
new ApexCharts(document.querySelector("#chartHealth"),{series:[<?= $avg_health ?>],chart:{type:'radialBar',height:'120%',fontFamily:'Tajawal'},plotOptions:{radialBar:{hollow:{size:'65%'},track:{background:'#f1f5f9',strokeWidth:'100%'},dataLabels:{show:true,name:{offsetY:20,show:true,color:'#64748b',fontSize:'12px',fontWeight:700},value:{offsetY:-10,color:'<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>',fontSize:'26px',fontWeight:900,formatter:function(val){return val+"%"}}}}},fill:{colors:['<?= $avg_health>=75?'#10b981':($avg_health>=50?'#f59e0b':'#ef4444') ?>']},stroke:{lineCap:'round'},labels:['متوسط الصحة']}).render();
});
<?php endif; ?>
</script>
</body></html>