<?php
/**
* reports/risk/distribution.php — مركز تحليل المخاطر (Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* • توزيع الأصول عبر مستويات المخاطرة + فجوة التمويل
* • محاور: أعلى الأصول خطورة / الفئات / الأقسام / التوصيات / خطة الاستبدال
* • تصدير ماسي: Excel غني / PDF رسمي موقّع / لوحة مؤشرات A4
* • نظام التقارير المحفوظة الموحد (module = risk)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/risk_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';
page_guard('reports.risk', 'view');

if (isset($_GET['apply_saved'])) {
    sr_apply_saved($pdo, (int)$_GET['apply_saved'], (int)current_user()['id']);
}

$rtl = is_rtl();
$can_see_all = can_see_all();
$can_export  = can('reports.risk', 'export');
$user_dept_id = (int)(current_user()['department_id'] ?? 0);
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$BAND_COLOR = ['critical'=>'#dc2626','high'=>'#f97316','medium'=>'#eab308','low'=>'#22c55e','unscored'=>'#94a3b8'];
$BAND_LABEL = ['critical'=>'حرج','high'=>'مرتفع','medium'=>'متوسط','low'=>'منخفض','unscored'=>'غير مُقيَّم'];
$CRIT_AR = ['A'=>'A — حرج جداً','B'=>'B — متوسط','C'=>'C — منخفض'];

/* ═══ الفلاتر ═══ */
$view_mode = $_GET['view'] ?? 'executive';
$f = [
    'cat'  => trim($_GET['cat_level1'] ?? ''),
    'crit' => trim($_GET['criticality'] ?? ''),
    'dept' => (int)($_GET['department_id'] ?? 0),
    'band' => trim($_GET['band'] ?? ''),
    'repl' => trim($_GET['repl'] ?? ''),
];
if (!array_key_exists($f['band'], $BAND_LABEL)) $f['band'] = '';
if ($f['repl'] !== '0' && $f['repl'] !== '1') $f['repl'] = '';
$has_filters = array_filter($f) !== [];

$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;

/* ═══ بناء الاستعلام ═══ */
$where = ["1=1"]; $params = [];
if (!$can_see_all) { $where[] = '(a.custodian_dept_id = :d OR a.department_id = :d)'; $params['d'] = $user_dept_id; }
if ($f['cat'])  { $where[] = 'a.cat_level1 = :cat';  $params['cat'] = $f['cat']; }
if ($f['crit']) { $where[] = 'a.criticality_class = :crit'; $params['crit'] = $f['crit']; }
if ($f['dept']) { $where[] = 'a.department_id = :dept'; $params['dept'] = $f['dept']; }
if ($f['band']) { $where[] = 'a.risk_band = :band'; $params['band'] = $f['band']; }
if ($f['repl'] === '1') { $where[] = 'a.in_replacement_plan = 1'; }
if ($f['repl'] === '0') { $where[] = 'a.in_replacement_plan = 0'; }

$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 20000;
$sql = "SELECT a.id, a.tag_number, a.description, a.cat_level1, a.criticality_class, a.status,
a.health_score, a.risk_band, a.total_risk_score, a.funding_gap, a.breakdowns_12m, a.maintenance_cost_ytd,
a.downtime_impact, a.in_replacement_plan, a.expected_replacement_date, a.recommended_action,
a.date_placed_in_service, a.manufacturer_name, a.model_number, d.name AS dept_name
FROM assets a LEFT JOIN departments d ON d.id = a.department_id
WHERE " . implode(' AND ', $where) . " ORDER BY a.total_risk_score DESC LIMIT $row_cap";
$st = $pdo->prepare($sql); $st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);

$cats = $pdo->query("SELECT DISTINCT cat_level1 FROM assets WHERE cat_level1 IS NOT NULL AND cat_level1 != '' ORDER BY cat_level1")->fetchAll(PDO::FETCH_COLUMN);
$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ═══ التجميع الشامل ═══ */
$total = count($results);
$band_cnt = ['critical'=>0,'high'=>0,'medium'=>0,'low'=>0,'unscored'=>0];
$funding = ['critical'=>0,'high'=>0,'medium'=>0,'low'=>0];
$score_sum = 0; $score_n = 0; $total_funding = 0;
$repl_cnt = 0; $crit_not_repl = 0;
$cat_risk = []; $dept_risk = []; $action_cnt = [];
foreach ($results as $r) {
    $b = $r['risk_band'] ?: 'unscored';
    $band_cnt[$b]++;
    if (isset($funding[$b])) { $funding[$b] += (float)$r['funding_gap']; $total_funding += (float)$r['funding_gap']; }
    $sc = (float)$r['total_risk_score'];
    if ($sc > 0) { $score_sum += $sc; $score_n++; }
    if ($r['in_replacement_plan']) $repl_cnt++;
    if ($b === 'critical' && !$r['in_replacement_plan']) $crit_not_repl++;

    $c1 = $r['cat_level1'] ?: 'غير مصنف';
    if (!isset($cat_risk[$c1])) $cat_risk[$c1] = ['cnt'=>0,'risk'=>0,'funding'=>0];
    $cat_risk[$c1]['cnt']++;
    if (in_array($b, ['critical','high'], true)) $cat_risk[$c1]['risk']++;
    $cat_risk[$c1]['funding'] += (float)$r['funding_gap'];

    $dn = $r['dept_name'] ?: 'بدون قسم';
    if (!isset($dept_risk[$dn])) $dept_risk[$dn] = ['cnt'=>0,'risk'=>0];
    $dept_risk[$dn]['cnt']++;
    if (in_array($b, ['critical','high'], true)) $dept_risk[$dn]['risk']++;

    if (!empty($r['recommended_action'])) $action_cnt[$r['recommended_action']] = ($action_cnt[$r['recommended_action']] ?? 0) + 1;
}
$avg_score = $score_n > 0 ? round($score_sum / $score_n, 1) : 0;
$top_assets = array_slice($results, 0, 10); // مرتبة مسبقاً تنازلياً حسب الدرجة
arsort($action_cnt); $action_top = array_slice($action_cnt, 0, 5, true);
$dept_risk_arr = $dept_risk; usort_arr: ;
$dept_sorted = [];
foreach ($dept_risk as $n => $v) $dept_sorted[] = ['name'=>$n, 'cnt'=>$v['cnt'], 'risk'=>$v['risk']];
usort($dept_sorted, function($a,$b){ return $b['risk'] <=> $a['risk']; });
$dept_sorted = array_slice($dept_sorted, 0, 6);
$cat_sorted = [];
foreach ($cat_risk as $n => $v) $cat_sorted[] = ['name'=>$n, 'cnt'=>$v['cnt'], 'risk'=>$v['risk'], 'funding'=>$v['funding']];
usort($cat_sorted, function($a,$b){ return $b['risk'] <=> $a['risk']; });
$cat_sorted = array_slice($cat_sorted, 0, 6);

/* ═══ تنبيهات الذكاء ═══ */
$ai = [];
if ($crit_not_repl > 0) $ai[] = "⚠️ $crit_not_repl أصل حرج غير مدرج بخطة الاستبدال — أولوية قصوى";
if ($total_funding > 0) $ai[] = "💰 فجوة تمويل إجمالية " . number_format($total_funding, 0) . " ر.س";
if ($band_cnt['critical'] > 0) $ai[] = "⚡ {$band_cnt['critical']} أصل بمستوى مخاطرة حرج";
$ai_class = empty($ai) ? 'ai-success' : (count($ai) >= 2 ? 'ai-danger' : 'ai-warning');
$ai_icon = empty($ai) ? 'fa-check-circle' : (count($ai) >= 2 ? 'fa-triangle-exclamation' : 'fa-bell');
$ai_msg = empty($ai) ? '✨ لا توجد مخاطر حرجة ضمن النطاق.' : implode(' | ', $ai);

$title_parts = [];
if ($f['cat']) $title_parts[] = $f['cat'];
if ($f['band']) $title_parts[] = 'مستوى ' . $BAND_LABEL[$f['band']];
if ($f['crit']) $title_parts[] = 'فئة ' . $f['crit'];
$report_title = 'تقرير المخاطر' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* ═══ 1. Excel غني ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Risk_Register_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:12px}th{background:#7f1d1d;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:8px;text-align:center}td{border:1px solid #cbd5e1;padding:6px;text-align:center;vertical-align:middle}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="16" style="font-size:16px;background:#b91c1c;padding:14px">سجل المخاطر المعتمد - <?= e($report_title) ?></th></tr>
<tr><th>Tag</th><th>الوصف</th><th>الفئة</th><th>القسم</th><th>مستوى الخطر</th><th>الدرجة</th><th>الحساسية</th><th>الصحة</th><th>أعطال 12ش</th><th>صيانة سنوية</th><th>فجوة التمويل</th><th>أثر التوقف</th><th>خطة استبدال</th><th>تاريخ الاستبدال</th><th>التوصية</th><th>المصنع/الموديل</th></tr>
</thead><tbody>
<?php foreach ($results as $r): $b = $r['risk_band'] ?: 'unscored'; ?>
<tr><td><?= e($r['tag_number']) ?></td><td><?= e($r['description']) ?></td><td><?= e($r['cat_level1']) ?></td><td><?= e($r['dept_name'] ?? '') ?></td>
<td><?= e($BAND_LABEL[$b]) ?></td><td><?= e($r['total_risk_score']) ?></td><td><?= e($r['criticality_class']) ?></td><td><?= e($r['health_score']) ?>%</td>
<td><?= (int)$r['breakdowns_12m'] ?></td><td><?= e(number_format((float)$r['maintenance_cost_ytd'],0)) ?></td><td><?= e(number_format((float)$r['funding_gap'],0)) ?></td><td><?= e($r['downtime_impact']) ?></td>
<td><?= $r['in_replacement_plan'] ? 'مدرج' : 'غير مدرج' ?></td><td><?= e($r['expected_replacement_date'] ?? '') ?></td><td><?= e($r['recommended_action'] ?? '') ?></td><td><?= e($r['manufacturer_name'] ?? '') ?> / <?= e($r['model_number'] ?? '') ?></td></tr>
<?php endforeach; ?>
</tbody></table></body></html>
<?php exit;
}

/* ═══ 2. PDF رسمي ═══ */
if ($print_mode) {
    $disp = array_slice($results, 0, 1000);
    $ROWS = 8; $pages = array_chunk($disp, $ROWS, true); $tp = max(1, count($pages));
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>الوثيقة الرسمية - <?= e($report_title) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:landscape;margin:12mm 10mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;color:#1e293b;margin:0}
.print-page{page-break-after:always}.print-page:last-child{page-break-after:auto}
.print-header{background:linear-gradient(135deg,#f8fafc,#fee2e2);border:1px solid #cbd5e1;border-radius:10px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.ph-right{display:flex;align-items:center;gap:12px;border-left:1px solid #cbd5e1;padding-left:18px}.ph-h1{font-size:16px;font-weight:800}.ph-h2{font-size:11px;color:#475569}
.ph-logo{height:46px;object-fit:contain}.ph-center{flex:1;text-align:center}.ph-title{font-size:16px;font-weight:800;color:#b91c1c}.ph-left{text-align:left;font-size:10px;color:#475569}
.ph-pagebadge{background:#b91c1c;color:#fff;padding:3px 10px;border-radius:4px;font-size:9px;font-weight:800;display:inline-block;margin-bottom:4px}
table.data-table{width:100%;border-collapse:collapse;font-size:9.5px;border:1.5px solid #cbd5e1}
table.data-table th{background:#f1f5f9;padding:7px;text-align:right;font-weight:900;border:1px solid #cbd5e1}
table.data-table td{padding:5px 7px;border:1px solid #e2e8f0;text-align:right;vertical-align:middle}
table.data-table tbody tr:nth-child(even) td{background:#fafaf9}
.p-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:8.5px;font-weight:800;color:#fff}
.print-footer{display:flex;justify-content:space-around;padding:12px 10px 4px;border-top:1.5px solid #cbd5e1}
.sign-box{text-align:center;width:30%}.sign-box .title{font-size:11px;font-weight:800;margin-bottom:20px}.sign-box .line{border-bottom:1px dashed #94a3b8;margin:0 15px 6px}.sign-box .hint{font-size:9px;color:#64748b}
</style></head><body onload="setTimeout(()=>window.print(),500)">
<?php foreach ($pages as $pi => $pr): $pn = $pi + 1; ?>
<div class="print-page"><table class="data-table"><thead>
<tr><th colspan="8" style="padding:0;border:none;background:none"><div class="print-header">
<div class="ph-right"><?php if($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title">سجل المخاطر المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pn ?> من <?= $tp ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — السجلات: <strong><?= $total ?></strong></div></div>
</div></th></tr>
<tr><th>#</th><th>الأصل</th><th>الفئة / القسم</th><th>مستوى الخطر</th><th>الدرجة</th><th>فجوة التمويل</th><th>أعطال 12ش</th><th>التوصية / الاستبدال</th></tr></thead><tbody>
<?php foreach ($pr as $i => $r): $b = $r['risk_band'] ?: 'unscored'; ?>
<tr><td style="text-align:center"><?= $i+1 ?></td>
<td><strong><?= e($r['description'] ?: '—') ?></strong><br><small><?= e($r['tag_number']) ?> | <?= e($r['manufacturer_name'] ?? '') ?></small></td>
<td><?= e($r['cat_level1'] ?: '—') ?><br><small><?= e($r['dept_name'] ?: '—') ?></small></td>
<td><span class="p-badge" style="background:<?= $BAND_COLOR[$b] ?>"><?= e($BAND_LABEL[$b]) ?></span></td>
<td style="font-weight:900"><?= e($r['total_risk_score']) ?></td>
<td style="font-family:monospace"><?= e(number_format((float)$r['funding_gap'],0)) ?></td>
<td><?= (int)$r['breakdowns_12m'] ?></td>
<td><small><?= e(mb_strimwidth($r['recommended_action'] ?? '—', 0, 40, '...')) ?></small><br><small><?= $r['in_replacement_plan'] ? 'مدرج بالخطة' : 'غير مدرج' ?></small></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="8" style="border:none;padding:0"><div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">التوقيع</div></div>
<div class="sign-box"><div class="title">لجنة تقييم المخاطر</div><div class="line"></div><div class="hint">المراجعة</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد</div></div>
</div></td></tr></tfoot></table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. لوحة A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات المخاطر</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:A4 landscape;margin:0}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;margin:0}
.a4{width:297mm;height:209mm;padding:10mm;margin:0 auto;display:flex;flex-direction:column;overflow:hidden}
.hd{background:#7f1d1d;color:#fff;border-radius:10px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.krow{display:flex;gap:12px;margin-bottom:12px}.kbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center;background:#f8fafc}
.kval{font-size:22px;font-weight:900}.klbl{font-size:11px;font-weight:800;color:#64748b}
.cwrap{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0}.crow{display:flex;gap:12px;flex:1;min-height:0}
.cbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;display:flex;flex-direction:column}.ct{font-size:12px;font-weight:900;text-align:center;margin-bottom:4px}.ca{flex:1;min-height:0}
.ft{text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;border-top:1px dashed #cbd5e1;padding-top:4px}
</style></head><body onload="setTimeout(()=>window.print(),1500)">
<div class="a4">
<div class="hd"><div style="font-size:18px;font-weight:900"><?= e($hospital) ?></div><div style="font-size:16px;font-weight:900;color:#fca5a5"><?= e($report_title) ?></div><div style="font-size:11px"><?= date('Y-m-d') ?></div></div>
<div class="krow">
<div class="kbox"><div class="kval"><?= number_format($total) ?></div><div class="klbl">أصول مُقيّمة</div></div>
<div class="kbox"><div class="kval" style="color:#dc2626"><?= $band_cnt['critical'] ?></div><div class="klbl">حرج</div></div>
<div class="kbox"><div class="kval" style="color:#f97316"><?= $band_cnt['high'] ?></div><div class="klbl">مرتفع</div></div>
<div class="kbox"><div class="kval" style="color:#b91c1c"><?= number_format($total_funding,0) ?></div><div class="klbl">فجوة التمويل (ر.س)</div></div>
<div class="kbox"><div class="kval"><?= $avg_score ?></div><div class="klbl">متوسط الدرجة</div></div>
</div>
<div class="cwrap">
<div class="crow">
<div class="cbox" style="flex:1.2"><div class="ct">توزيع مستويات المخاطرة</div><div class="ca" id="pBand"></div></div>
<div class="cbox"><div class="ct">فجوة التمويل حسب المستوى</div><div class="ca" id="pFund"></div></div>
</div>
<div class="crow">
<div class="cbox"><div class="ct">أعلى الأقسام خطورة</div><div class="ca" id="pDept"></div></div>
<div class="cbox" style="flex:1.2"><div class="ct">الفئات الأكثر خطورة</div><div class="ca" id="pCat"></div></div>
</div>
</div>
<div class="ft">وثيقة تحليلية | <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded",function(){
new ApexCharts(document.querySelector("#pBand"),{series:<?= json_encode(array_values($band_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$BAND_LABEL[$k],array_keys($band_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:<?= json_encode(array_values($BAND_COLOR)) ?>,plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();
new ApexCharts(document.querySelector("#pFund"),{series:[{data:<?= json_encode(array_map(fn($v)=>round($v),array_values($funding))) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_map(fn($k)=>$BAND_LABEL[$k],array_keys($funding)),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#b91c1c'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();
<?php if(!empty($dept_sorted)): ?>new ApexCharts(document.querySelector("#pDept"),{series:[{data:<?= json_encode(array_column($dept_sorted,'risk')) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_column($dept_sorted,'name'),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'9px'}}},colors:['#f97316'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($cat_sorted)): ?>new ApexCharts(document.querySelector("#pCat"),{series:[{data:<?= json_encode(array_column($cat_sorted,'risk')) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_column($cat_sorted,'name'),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'9px'}}},colors:['#dc2626'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
});
</script></body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مركز تحليل المخاطر — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--primary:#b91c1c;--bg:#f8fafc;--border:#e2e8f0;--tm:#0f172a;--t2:#475569;--t3:#94a3b8;--radius:16px}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--tm)}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.view-toggles{display:flex;gap:10px;margin-bottom:16px;background:#fff;padding:6px;border-radius:99px;width:fit-content;border:1px solid var(--border)}
.toggle-btn{padding:10px 24px;border-radius:99px;font-size:13.5px;font-weight:800;color:var(--t2);text-decoration:none;display:flex;align-items:center;gap:8px}
.toggle-btn.active{background:var(--primary);color:#fff}
.header-hero{background:linear-gradient(135deg,#1e293b,#7c2d12,#b91c1c);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center}
.ai-banner{border-radius:12px;padding:12px 18px;margin-bottom:16px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:10px;border:1.5px solid}
.ai-success{background:#ecfdf5;border-color:#6ee7b7;color:#065f46}.ai-warning{background:#fffbeb;border-color:#fcd34d;color:#92400e}.ai-danger{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.grp{background:#fff;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;border-right:4px solid var(--primary)}
.grp summary{padding:14px 20px;cursor:pointer;font-weight:900;font-size:13.5px;display:flex;align-items:center;gap:10px;list-style:none}
.grp-body{padding:0 20px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.fld{display:flex;flex-direction:column;gap:4px}.fld label{font-size:11.5px;font-weight:800;color:var(--t3)}
.fld select{border:1.5px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:12.5px;font-family:'Tajawal'}
.act-bar{background:#fff;border-radius:100px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border:1px solid var(--border);flex-wrap:wrap;gap:8px}
.btn-apply{background:var(--primary);color:#fff;border:none;border-radius:99px;padding:10px 24px;font-weight:900;cursor:pointer;font-family:'Tajawal'}
.btn-export{background:#fff;border:1.5px solid #cbd5e1;border-radius:99px;padding:8px 18px;font-weight:800;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;color:var(--tm)}
.btn-excel{border-color:#10b981;color:#10b981}.btn-charts{border-color:#8b5cf6;color:#8b5cf6}.btn-print{border-color:#0ea5e9;color:#0ea5e9}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border-radius:var(--radius);padding:16px;border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.kpi-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.kpi-val{font-size:20px;font-weight:900}.kpi-title{font-size:11.5px;font-weight:800;color:var(--t3)}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:20px}
.chart-card{background:#fff;border-radius:var(--radius);padding:16px;border:1px solid var(--border)}
.chart-title{font-weight:900;font-size:14px;margin-bottom:10px;display:flex;gap:8px;align-items:center;border-bottom:1px dashed var(--border);padding-bottom:8px}
.axis-sec{background:#fff;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;overflow:hidden}
.axis-h{padding:14px 18px;font-weight:900;font-size:15px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border);background:linear-gradient(90deg,#fef2f2,#fff)}
.axis-h i{color:var(--primary)}
.axis-body{padding:16px 18px}
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{background:#f8fafc;padding:8px 10px;text-align:right;font-size:10.5px;font-weight:900;color:var(--t2);border-bottom:2px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
.tbl tr:hover td{background:#fef2f2}
.badge{display:inline-flex;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:800;gap:4px;align-items:center;color:#fff}
.score-bar{height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-top:4px}.score-bar>div{height:100%;border-radius:99px}
.empty{text-align:center;padding:50px;color:var(--t3);background:#fff;border-radius:var(--radius);border:1px solid var(--border)}
</style></head>
<body class="app-layout">
<?php $__f_backup = $f ?? []; include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH . '/includes/topbar.php'; $f = $__f_backup; ?>
<main class="page-content"><div class="wrap">

<div class="view-toggles">
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة تحليل المخاطر</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> سجل المخاطر التفصيلي</a>
</div>

<div class="header-hero">
<div><h1 style="font-size:20px;font-weight:900;margin:0"><i class="fa-solid fa-triangle-exclamation" style="margin-left:8px;color:#fca5a5"></i> مركز تحليل المخاطر</h1>
<div style="color:#fed7aa;font-size:13px;margin-top:4px">توزيع الأصول عبر مستويات المخاطرة + فجوة التمويل + التوصيات</div></div>
<div style="text-align:left;font-size:11px;color:#fed7aa">تاريخ التقرير<br><strong style="font-size:15px;color:#fff"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($results): ?><div class="ai-banner <?= $ai_class ?>"><i class="fa-solid <?= $ai_icon ?>"></i><span><?= e($ai_msg) ?></span></div><?php endif; ?>

<?php
$sr_module = 'risk'; $sr_filters = $f; $sr_view = $view_mode; $sr_base_url = BASE_URL;
$sr_share_url = BASE_URL . '/reports/risk/distribution.php?' . http_build_query(array_filter($f));
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:#fee2e2;padding:6px;border-radius:6px"></i> فلاتر الدراسة <i class="fa-solid fa-chevron-down" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>الفئة</label><select name="cat_level1"><option value="">— الكل —</option><?php foreach($cats as $c): ?><option value="<?= e($c) ?>" <?= $f['cat']===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>مستوى المخاطرة</label><select name="band"><option value="">— الكل —</option><?php foreach($BAND_LABEL as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['band']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>الحساسية</label><select name="criticality"><option value="">— الكل —</option><?php foreach($CRIT_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['crit']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>القسم</label><select name="department_id"><option value="">— الكل —</option><?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $f['dept']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>خطة الاستبدال</label><select name="repl"><option value="">— الكل —</option><option value="1" <?= $f['repl']==='1'?'selected':'' ?>>مدرج بالخطة</option><option value="0" <?= $f['repl']==='0'?'selected':'' ?>>غير مدرج</option></select></div>
</div>
</details>
<div class="act-bar">
<div style="display:flex;gap:10px;flex-wrap:wrap">
<button type="submit" class="btn-apply"><i class="fa-solid fa-bolt"></i> تحديث الدراسة</button>
<a href="?view=<?= e($view_mode) ?>" class="btn-export" style="border-color:#ef4444;color:#ef4444"><i class="fa-solid fa-xmark"></i> مسح</a>
</div>
<?php if ($can_export && $results): ?>
<div style="display:flex;gap:10px;flex-wrap:wrap">
<a class="btn-export btn-excel" href="?excel=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-file-excel"></i> Excel</a>
<a class="btn-export btn-print" href="?print=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-print"></i> PDF رسمي</a>
<a class="btn-export btn-charts" href="?print_charts=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-chart-pie"></i> لوحة مؤشرات</a>
</div>
<?php endif; ?>
</div>
</form>

<?php if ($results): ?>
<?php if ($view_mode === 'executive'): ?>
<div class="kpi-grid">
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-boxes-stacked"></i></div><div><div class="kpi-title">أصول مُقيّمة</div><div class="kpi-val"><?= number_format($total) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dc2626;color:#fff"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="kpi-title">حرج</div><div class="kpi-val"><?= $band_cnt['critical'] ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#f97316;color:#fff"><i class="fa-solid fa-arrow-trend-up"></i></div><div><div class="kpi-title">مرتفع</div><div class="kpi-val"><?= $band_cnt['high'] ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#b45309"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="kpi-title">فجوة التمويل</div><div class="kpi-val"><?= number_format($total_funding,0) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#f1f5f9;color:#475569"><i class="fa-solid fa-gauge-high"></i></div><div><div class="kpi-title">متوسط الدرجة</div><div class="kpi-val"><?= $avg_score ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-rotate"></i></div><div><div class="kpi-title">بخطة الاستبدال</div><div class="kpi-val"><?= $repl_cnt ?></div></div></div>
</div>

<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> توزيع مستويات المخاطرة</div><div id="chBand" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-sack-dollar" style="color:#b91c1c"></i> فجوة التمويل حسب المستوى</div><div id="chFund" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-building" style="color:#f97316"></i> أعلى الأقسام خطورة</div><div id="chDept" style="min-height:220px"></div></div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-list-ol"></i> أعلى الأصول خطورة (حسب الدرجة)</div>
<div class="axis-body" style="padding:0">
<table class="tbl"><thead><tr><th>الأصل</th><th>الفئة / القسم</th><th>المستوى</th><th>الدرجة</th><th>فجوة التمويل</th><th>أعطال 12ش</th><th>التوصية</th></tr></thead><tbody>
<?php foreach ($top_assets as $r): $b = $r['risk_band'] ?: 'unscored'; $pct = min(100, (float)$r['total_risk_score']); ?>
<tr><td><div style="font-weight:800"><?= e($r['description'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e($r['tag_number']) ?></div></td>
<td style="font-size:11px"><?= e($r['cat_level1'] ?: '—') ?><br><?= e($r['dept_name'] ?: '—') ?></td>
<td><span class="badge" style="background:<?= $BAND_COLOR[$b] ?>"><?= e($BAND_LABEL[$b]) ?></span></td>
<td><strong><?= e($r['total_risk_score']) ?></strong><div class="score-bar"><div style="width:<?= $pct ?>%;background:<?= $BAND_COLOR[$b] ?>"></div></div></td>
<td style="font-family:monospace"><?= e(number_format((float)$r['funding_gap'],0)) ?></td>
<td><?= (int)$r['breakdowns_12m'] ?></td>
<td style="font-size:11px"><?= e(mb_strimwidth($r['recommended_action'] ?? '—', 0, 40, '...')) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-lightbulb"></i> التوصيات الأكثر تكراراً</div>
<div class="axis-body" style="display:flex;gap:10px;flex-wrap:wrap">
<?php foreach ($action_top as $a => $c): ?>
<span class="badge" style="background:#475569"><?= e(mb_strimwidth($a,0,50,'...')) ?> × <?= $c ?></span>
<?php endforeach; ?>
</div>
</div>

<?php else: ?>
<div style="margin-bottom:12px;font-weight:900">السجلات: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px"><?= $total ?></span></div>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow-x:auto">
<table class="tbl"><thead><tr><th>#</th><th>الأصل</th><th>الفئة / القسم</th><th>المستوى</th><th>الدرجة</th><th>الحساسية/الصحة</th><th>فجوة التمويل</th><th>الاستبدال</th><th>التوصية</th></tr></thead><tbody>
<?php foreach (array_slice($results, 0, 500) as $i => $r): $b = $r['risk_band'] ?: 'unscored'; ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td>
<td><div style="font-weight:800"><?= e($r['description'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e($r['tag_number']) ?> | <?= e($r['manufacturer_name'] ?? '') ?></div></td>
<td style="font-size:11px"><?= e($r['cat_level1'] ?: '—') ?><br><?= e($r['dept_name'] ?: '—') ?></td>
<td><span class="badge" style="background:<?= $BAND_COLOR[$b] ?>"><?= e($BAND_LABEL[$b]) ?></span></td>
<td><strong><?= e($r['total_risk_score']) ?></strong></td>
<td style="font-size:11px"><?= e($r['criticality_class']) ?> / <?= e($r['health_score']) ?>%</td>
<td style="font-family:monospace"><?= e(number_format((float)$r['funding_gap'],0)) ?></td>
<td><?= $r['in_replacement_plan'] ? '<span class="badge" style="background:#16a34a">مدرج</span>' : '<span class="badge" style="background:#94a3b8">غير مدرج</span>' ?></td>
<td style="font-size:11px"><?= e(mb_strimwidth($r['recommended_action'] ?? '—', 0, 40, '...')) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty"><i class="fa-solid fa-shield-halved" style="font-size:44px;color:var(--primary);display:block;margin-bottom:10px"></i><h3>لا توجد أصول مطابقة</h3><p>عدّل الفلاتر أو امسحها.</p></div>
<?php endif; ?>

</div></main></div>
<script>
<?php if ($view_mode==='executive' && $results): ?>
document.addEventListener("DOMContentLoaded",function(){
const FF='Tajawal';
new ApexCharts(document.querySelector("#chBand"),{series:<?= json_encode(array_values($band_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$BAND_LABEL[$k],array_keys($band_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:<?= json_encode(array_values($BAND_COLOR)) ?>,plotOptions:{pie:{donut:{size:'62%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();
new ApexCharts(document.querySelector("#chFund"),{series:[{name:'ر.س',data:<?= json_encode(array_map(fn($v)=>round($v),array_values($funding))) ?>}],chart:{type:'bar',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_map(fn($k)=>$BAND_LABEL[$k],array_keys($funding)),JSON_UNESCAPED_UNICODE) ?>},colors:['#b91c1c'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();
<?php if(!empty($dept_sorted)): ?>new ApexCharts(document.querySelector("#chDept"),{series:[{name:'أصول حرجة/مرتفعة',data:<?= json_encode(array_column($dept_sorted,'risk')) ?>}],chart:{type:'bar',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_column($dept_sorted,'name'),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#f97316'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
});
<?php endif; ?>
</script>
</body></html>