<?php
/**
* reports/disposal/overview.php — مركز تحليل التخلص (Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* • محاور: الأنواع+القيم / الأسباب / الأقسام / الاتجاه / السجل التفصيلي
* • تصدير ماسي: Excel غني / PDF رسمي موقّع / لوحة مؤشرات A4
* • نظام التقارير المحفوظة الموحد (module = disposals)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';
page_guard('reports.disposal.overview');

$rtl = is_rtl();
$can_export = can('reports.disposal.overview', 'export');
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$TYPE_AR = ['scrap'=>'تكهين','destroy'=>'إتلاف','sell'=>'بيع','transfer_out'=>'نقل خارجي'];
$TYPE_COLOR = ['scrap'=>'#f59e0b','destroy'=>'#dc2626','sell'=>'#16a34a','transfer_out'=>'#0ea5e9'];
$REASON_AR = ['obsolete'=>'قديم','damaged_beyond_repair'=>'تالف','end_of_life'=>'انتهى عمره','lost'=>'مفقود','replaced'=>'مُستبدل','other'=>'آخر'];
$REASON_COLOR = ['obsolete'=>'#94a3b8','damaged_beyond_repair'=>'#dc2626','end_of_life'=>'#7f1d1d','lost'=>'#f59e0b','replaced'=>'#0ea5e9','other'=>'#64748b'];

/* ═══ الفلاتر ═══ */
$view_mode = $_GET['view'] ?? 'executive';
function valid_date(string $v): string {
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
$f = [
    'type' => trim($_GET['type'] ?? ''),
    'reason' => trim($_GET['reason'] ?? ''),
    'dept' => (int)($_GET['dept'] ?? 0),
    'from' => valid_date(trim($_GET['from'] ?? '')),
    'to' => valid_date(trim($_GET['to'] ?? '')),
    'q' => trim($_GET['q'] ?? ''),
];
if ($f['type'] !== '' && !array_key_exists($f['type'], $TYPE_AR)) $f['type'] = '';
if ($f['reason'] !== '' && !array_key_exists($f['reason'], $REASON_AR)) $f['reason'] = '';
$has_filters = array_filter($f) !== [];

$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;

/* ═══ بناء الاستعلام ═══ */
$where = ["1=1"]; $params = [];
if ($f['type'] !== '') { $where[] = 'd.disposal_type = :ftp'; $params['ftp'] = $f['type']; }
if ($f['reason'] !== '') { $where[] = 'd.reason = :frs'; $params['frs'] = $f['reason']; }
if ($f['dept']) { $where[] = 'a.department_id = :fdept'; $params['fdept'] = $f['dept']; }
if ($f['from']) { $where[] = 'DATE(d.disposal_date) >= :ffrom'; $params['ffrom'] = $f['from']; }
if ($f['to']) { $where[] = 'DATE(d.disposal_date) <= :fto'; $params['fto'] = $f['to']; }
if ($f['q'] !== '') {
    $where[] = "(a.tag_number LIKE :q OR a.description LIKE :q)";
    $params['q'] = '%' . $f['q'] . '%';
}

$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 20000;
$sql = "SELECT d.id, d.disposal_type, d.reason, d.disposal_date, d.disposal_value, d.asset_id,
a.tag_number, a.description AS asset_desc, a.cat_level1, a.department_id, a.criticality_class,
dp.name AS dept_name
FROM asset_disposals d
LEFT JOIN assets a ON a.id = d.asset_id
LEFT JOIN departments dp ON dp.id = a.department_id
WHERE " . implode(' AND ', $where) . " ORDER BY d.id DESC LIMIT $row_cap";
$st = $pdo->prepare($sql); $st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);

$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ═══ مؤشرات عامة ═══ */
$g_known = (int)$pdo->query("SELECT COUNT(*) FROM known_disposals")->fetchColumn();

/* ═══ التجميع الشامل ═══ */
$total = count($results);
$total_value = 0; $year_cnt = 0; $year_value = 0; $lost = 0; $sell_value = 0; $max_single = 0;
$type_agg = []; $reason_cnt = []; $month_cnt = []; $dept_agg = [];
$cur_year = date('Y');
foreach ($results as $r) {
    $v = (float)$r['disposal_value'];
    $total_value += $v;
    if ($v > $max_single) $max_single = $v;
    $tp = $r['disposal_type'];
    if (!isset($type_agg[$tp])) $type_agg[$tp] = ['n'=>0,'val'=>0];
    $type_agg[$tp]['n']++; $type_agg[$tp]['val'] += $v;
    if ($tp === 'sell') $sell_value += $v;
    $rs = $r['reason'];
    $reason_cnt[$rs] = ($reason_cnt[$rs] ?? 0) + 1;
    if ($rs === 'lost') $lost++;
    if (!empty($r['disposal_date'])) {
        $ym = substr($r['disposal_date'], 0, 7);
        $month_cnt[$ym] = ($month_cnt[$ym] ?? 0) + 1;
        if (substr($r['disposal_date'], 0, 4) === $cur_year) { $year_cnt++; $year_value += $v; }
    }
    $dn = $r['dept_name'] ?: 'بدون قسم';
    if (!isset($dept_agg[$dn])) $dept_agg[$dn] = ['n'=>0,'val'=>0];
    $dept_agg[$dn]['n']++; $dept_agg[$dn]['val'] += $v;
}
$avg_value = $total > 0 ? round($total_value / $total) : 0;
ksort($month_cnt);

$type_sorted = [];
foreach ($type_agg as $k => $v) $type_sorted[] = ['type'=>$k] + $v;
usort($type_sorted, function($a,$b){ return $b['n'] <=> $a['n']; });

$dept_sorted = [];
foreach ($dept_agg as $n => $v) $dept_sorted[] = ['name'=>$n] + $v;
usort($dept_sorted, function($a,$b){ return $b['n'] <=> $a['n']; });
$dept_sorted = array_slice($dept_sorted, 0, 8);

$reason_sorted = $reason_cnt; arsort($reason_sorted); $reason_sorted = array_slice($reason_sorted, 0, 6, true);

/* ═══ تنبيهات الذكاء ═══ */
$ai = [];
if ($lost > 0) $ai[] = "🔴 $lost أصل مُتخلص منه بسبب الفقد — يتطلب محاضر تحقيق";
if ($sell_value > 0) $ai[] = "💰 إيرادات بيع محققة " . number_format($sell_value, 0) . " ر.س";
if ($year_cnt > 0) $ai[] = "📅 $year_cnt عملية تخلّص خلال $cur_year بقيمة " . number_format($year_value, 0) . " ر.س";
$ai_class = empty($ai) ? 'ai-success' : (count($ai) >= 2 && $lost > 0 ? 'ai-danger' : 'ai-warning');
$ai_icon = empty($ai) ? 'fa-check-circle' : ($lost > 0 ? 'fa-triangle-exclamation' : 'fa-bell');
$ai_msg = empty($ai) ? '✨ لا توجد ملاحظات على عمليات التخلص.' : implode(' | ', $ai);

$title_parts = [];
if ($f['type']) $title_parts[] = $TYPE_AR[$f['type']];
if ($f['reason']) $title_parts[] = $REASON_AR[$f['reason']];
$report_title = 'تقرير التخلص' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* ═══ 1. Excel غني ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Disposal_Register_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:12px}th{background:#4c1d95;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:8px;text-align:center}td{border:1px solid #cbd5e1;padding:6px;text-align:center;vertical-align:middle}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="9" style="font-size:16px;background:#7c3aed;padding:14px">سجل التخلص المعتمد - <?= e($report_title) ?></th></tr>
<tr><th>التاريخ</th><th>النوع</th><th>السبب</th><th>Tag الأصل</th><th>الوصف</th><th>الفئة</th><th>القسم</th><th>الحساسية</th><th>القيمة (ر.س)</th></tr>
</thead><tbody>
<?php foreach ($results as $r): ?>
<tr><td><?= e($r['disposal_date']) ?></td><td><?= e($TYPE_AR[$r['disposal_type']] ?? $r['disposal_type']) ?></td><td><?= e($REASON_AR[$r['reason']] ?? $r['reason']) ?></td>
<td><?= e($r['tag_number'] ?? '') ?></td><td><?= e($r['asset_desc'] ?? '') ?></td><td><?= e($r['cat_level1'] ?? '') ?></td><td><?= e($r['dept_name'] ?? '') ?></td><td><?= e($r['criticality_class'] ?? '') ?></td><td><?= e(number_format((float)$r['disposal_value'],0)) ?></td></tr>
<?php endforeach; ?>
</tbody></table></body></html>
<?php exit;
}

/* ═══ 2. PDF رسمي ═══ */
if ($print_mode) {
    $disp = array_slice($results, 0, 1000);
    $ROWS = 10; $pages = array_chunk($disp, $ROWS, true); $tp = max(1, count($pages));
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>الوثيقة الرسمية - <?= e($report_title) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:landscape;margin:12mm 10mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;color:#1e293b;margin:0}
.print-page{page-break-after:always}.print-page:last-child{page-break-after:auto}
.print-header{background:linear-gradient(135deg,#f8fafc,#ede9fe);border:1px solid #cbd5e1;border-radius:10px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.ph-right{display:flex;align-items:center;gap:12px;border-left:1px solid #cbd5e1;padding-left:18px}.ph-h1{font-size:16px;font-weight:800}.ph-h2{font-size:11px;color:#475569}
.ph-logo{height:46px;object-fit:contain}.ph-center{flex:1;text-align:center}.ph-title{font-size:16px;font-weight:800;color:#7c3aed}.ph-left{text-align:left;font-size:10px;color:#475569}
.ph-pagebadge{background:#7c3aed;color:#fff;padding:3px 10px;border-radius:4px;font-size:9px;font-weight:800;display:inline-block;margin-bottom:4px}
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
<tr><th colspan="7" style="padding:0;border:none;background:none"><div class="print-header">
<div class="ph-right"><?php if($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title">سجل التخلص المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pn ?> من <?= $tp ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — السجلات: <strong><?= $total ?></strong></div></div>
</div></th></tr>
<tr><th>#</th><th>الأصل</th><th>الفئة / القسم</th><th>النوع</th><th>السبب</th><th>التاريخ</th><th>القيمة</th></tr></thead><tbody>
<?php foreach ($pr as $i => $r): $tc = $TYPE_COLOR[$r['disposal_type']] ?? '#475569'; $rc = $REASON_COLOR[$r['reason']] ?? '#475569'; ?>
<tr><td style="text-align:center"><?= $i+1 ?></td>
<td><strong><?= e($r['tag_number'] ?: '—') ?></strong><br><small><?= e(mb_strimwidth($r['asset_desc'] ?? '', 0, 40, '...')) ?></small></td>
<td><?= e($r['cat_level1'] ?: '—') ?><br><small><?= e($r['dept_name'] ?: '—') ?></small></td>
<td><span class="p-badge" style="background:<?= $tc ?>"><?= e($TYPE_AR[$r['disposal_type']] ?? '') ?></span></td>
<td><span class="p-badge" style="background:<?= $rc ?>"><?= e($REASON_AR[$r['reason']] ?? '') ?></span></td>
<td style="font-size:9px"><?= e($r['disposal_date'] ? date('Y-m-d', strtotime($r['disposal_date'])) : '—') ?></td>
<td style="font-family:monospace;font-weight:800"><?= e(number_format((float)$r['disposal_value'],0)) ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="7" style="border:none;padding:0"><div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">التوقيع</div></div>
<div class="sign-box"><div class="title">لجنة التخلص</div><div class="line"></div><div class="hint">المراجعة</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد</div></div>
</div></td></tr></tfoot></table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. لوحة A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات التخلص</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:A4 landscape;margin:0}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;margin:0}
.a4{width:297mm;height:209mm;padding:10mm;margin:0 auto;display:flex;flex-direction:column;overflow:hidden}
.hd{background:#4c1d95;color:#fff;border-radius:10px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.krow{display:flex;gap:12px;margin-bottom:12px}.kbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center;background:#f8fafc}
.kval{font-size:22px;font-weight:900}.klbl{font-size:11px;font-weight:800;color:#64748b}
.cwrap{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0}.crow{display:flex;gap:12px;flex:1;min-height:0}
.cbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;display:flex;flex-direction:column}.ct{font-size:12px;font-weight:900;text-align:center;margin-bottom:4px}.ca{flex:1;min-height:0}
.ft{text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;border-top:1px dashed #cbd5e1;padding-top:4px}
</style></head><body onload="setTimeout(()=>window.print(),1500)">
<div class="a4">
<div class="hd"><div style="font-size:18px;font-weight:900"><?= e($hospital) ?></div><div style="font-size:16px;font-weight:900;color:#c4b5fd"><?= e($report_title) ?></div><div style="font-size:11px"><?= date('Y-m-d') ?></div></div>
<div class="krow">
<div class="kbox"><div class="kval"><?= number_format($total) ?></div><div class="klbl">عمليات بالنظام</div></div>
<div class="kbox"><div class="kval" style="color:#16a34a"><?= number_format($total_value,0) ?></div><div class="klbl">قيمة إجمالية (ر.س)</div></div>
<div class="kbox"><div class="kval"><?= number_format($avg_value,0) ?></div><div class="klbl">متوسط القيمة</div></div>
<div class="kbox"><div class="kval" style="color:#475569"><?= $g_known ?></div><div class="klbl">تاريخية (ورقي)</div></div>
</div>
<div class="cwrap">
<div class="crow">
<div class="cbox" style="flex:1.2"><div class="ct">التوزيع حسب النوع</div><div class="ca" id="pType"></div></div>
<div class="cbox"><div class="ct">التوثيق بالنظام</div><div class="ca" id="pDoc"></div></div>
</div>
<div class="crow">
<div class="cbox"><div class="ct">حسب السبب</div><div class="ca" id="pReason"></div></div>
<div class="cbox" style="flex:1.2"><div class="ct">الاتجاه الشهري</div><div class="ca" id="pMo"></div></div>
</div>
</div>
<div class="ft">وثيقة تحليلية | <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded",function(){
<?php if(!empty($type_agg)): ?>new ApexCharts(document.querySelector("#pType"),{series:<?= json_encode(array_column($type_sorted,'n')) ?>,labels:<?= json_encode(array_map(fn($t)=>$TYPE_AR[$t['type']]??$t['type'],$type_sorted),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:<?= json_encode(array_map(fn($t)=>$TYPE_COLOR[$t['type']]??'#475569',$type_sorted)) ?>,plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();<?php endif; ?>
<?php $doc_rate = ($total+$g_known)>0 ? round($total/($total+$g_known)*100) : 0; ?>
new ApexCharts(document.querySelector("#pDoc"),{series:[<?= $doc_rate ?>],chart:{type:'radialBar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},plotOptions:{radialBar:{hollow:{size:'65%'},dataLabels:{show:true,name:{show:false},value:{offsetY:8,fontSize:'26px',fontWeight:900,color:'#7c3aed',formatter:v=>v+'%'}}}},fill:{colors:['#7c3aed']}}).render();
<?php if(!empty($reason_sorted)): ?>new ApexCharts(document.querySelector("#pReason"),{series:[{data:<?= json_encode(array_values($reason_sorted)) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_map(fn($k)=>$REASON_AR[$k]??$k,array_keys($reason_sorted)),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'9px'}}},colors:['#7c3aed'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#pMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#7c3aed'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
</script></body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مركز تحليل التخلص — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--primary:#7c3aed;--bg:#f8fafc;--border:#e2e8f0;--tm:#0f172a;--t2:#475569;--t3:#94a3b8;--radius:16px}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--tm)}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.view-toggles{display:flex;gap:10px;margin-bottom:16px;background:#fff;padding:6px;border-radius:99px;width:fit-content;border:1px solid var(--border)}
.toggle-btn{padding:10px 24px;border-radius:99px;font-size:13.5px;font-weight:800;color:var(--t2);text-decoration:none;display:flex;align-items:center;gap:8px}
.toggle-btn.active{background:var(--primary);color:#fff}
.header-hero{background:linear-gradient(135deg,#1e1b4b,#5b21b6,#7c3aed);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center}
.ai-banner{border-radius:12px;padding:12px 18px;margin-bottom:16px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:10px;border:1.5px solid}
.ai-success{background:#ecfdf5;border-color:#6ee7b7;color:#065f46}.ai-warning{background:#fffbeb;border-color:#fcd34d;color:#92400e}.ai-danger{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.grp{background:#fff;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;border-right:4px solid var(--primary)}
.grp summary{padding:14px 20px;cursor:pointer;font-weight:900;font-size:13.5px;display:flex;align-items:center;gap:10px;list-style:none}
.grp-body{padding:0 20px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.fld{display:flex;flex-direction:column;gap:4px}.fld label{font-size:11.5px;font-weight:800;color:var(--t3)}
.fld select,.fld input{border:1.5px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:12.5px;font-family:'Tajawal'}
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
.axis-h{padding:14px 18px;font-weight:900;font-size:15px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border);background:linear-gradient(90deg,#f5f3ff,#fff)}
.axis-h i{color:var(--primary)}
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{background:#f8fafc;padding:8px 10px;text-align:right;font-size:10.5px;font-weight:900;color:var(--t2);border-bottom:2px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
.tbl tr:hover td{background:#f5f3ff}
.badge{display:inline-flex;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:800;gap:4px;align-items:center;color:#fff}
.bar-bg{height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-top:4px}.bar-bg>div{height:100%;border-radius:99px}
.empty{text-align:center;padding:50px;color:var(--t3);background:#fff;border-radius:var(--radius);border:1px solid var(--border)}
</style></head>
<body class="app-layout">
<?php $__f_backup = $f ?? []; include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH . '/includes/topbar.php'; $f = $__f_backup; ?>
<main class="page-content"><div class="wrap">

<div class="view-toggles">
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة تحليل التخلص</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> سجل التخلص التفصيلي</a>
</div>

<div class="header-hero">
<div><h1 style="font-size:20px;font-weight:900;margin:0"><i class="fa-solid fa-trash-can" style="margin-left:8px;color:#c4b5fd"></i> مركز تحليل التخلص</h1>
<div style="color:#ddd6fe;font-size:13px;margin-top:4px">تكهين، إتلاف، بيع، نقل — مع القيم والأسباب والأقسام</div></div>
<div style="text-align:left;font-size:11px;color:#ddd6fe">تاريخ التقرير<br><strong style="font-size:15px;color:#fff"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($results): ?><div class="ai-banner <?= $ai_class ?>"><i class="fa-solid <?= $ai_icon ?>"></i><span><?= e($ai_msg) ?></span></div><?php endif; ?>

<?php
$sr_module = 'disposals'; $sr_filters = $f; $sr_view = $view_mode; $sr_base_url = BASE_URL;
$sr_share_url = BASE_URL . '/reports/disposal/overview.php?' . http_build_query(array_filter($f));
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:#f5f3ff;padding:6px;border-radius:6px"></i> فلاتر الدراسة <i class="fa-solid fa-chevron-down" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>بحث (تاج/وصف)</label><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="ابحث عن أصل..."></div>
<div class="fld"><label>النوع</label><select name="type"><option value="">— الكل —</option><?php foreach($TYPE_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>السبب</label><select name="reason"><option value="">— الكل —</option><?php foreach($REASON_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['reason']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>القسم</label><select name="dept"><option value="">— الكل —</option><?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $f['dept']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>من</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
<div class="fld"><label>إلى</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
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
<div class="kpi-card"><div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-trash-can"></i></div><div><div class="kpi-title">عمليات بالنظام</div><div class="kpi-val"><?= number_format($total) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-sack-dollar"></i></div><div><div class="kpi-title">قيمة إجمالية</div><div class="kpi-val"><?= number_format($total_value,0) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-scale-balanced"></i></div><div><div class="kpi-title">متوسط القيمة</div><div class="kpi-val"><?= number_format($avg_value,0) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-clock-rotate-left"></i></div><div><div class="kpi-title">تاريخية (ورقي)</div><div class="kpi-val"><?= $g_known ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-magnifying-glass"></i></div><div><div class="kpi-title">مفقود</div><div class="kpi-val"><?= $lost ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-hand-holding-dollar"></i></div><div><div class="kpi-title">إيرادات بيع</div><div class="kpi-val"><?= number_format($sell_value,0) ?></div></div></div>
</div>

<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> التوزيع حسب النوع</div><div id="chType" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-column" style="color:#7c3aed"></i> حسب السبب</div><div id="chReason" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#a78bfa"></i> الاتجاه الشهري</div><div id="chMo" style="min-height:220px"></div></div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-list-check"></i> الأنواع مع القيم</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>النوع</th><th>عمليات</th><th>النسبة</th><th>قيمة إجمالية</th><th>متوسط القيمة</th></tr></thead><tbody>
<?php foreach ($type_sorted as $t): $pct = $total>0?round($t['n']/$total*100):0; $tc=$TYPE_COLOR[$t['type']]??'#475569'; ?>
<tr><td><span class="badge" style="background:<?= $tc ?>"><?= e($TYPE_AR[$t['type']] ?? $t['type']) ?></span></td><td><strong><?= $t['n'] ?></strong></td>
<td style="min-width:120px"><?= $pct ?>%<div class="bar-bg"><div style="width:<?= $pct ?>%;background:<?= $tc ?>"></div></div></td>
<td style="font-family:monospace;font-weight:800"><?= number_format($t['val'],0) ?></td><td style="font-family:monospace"><?= number_format($t['n']>0?$t['val']/$t['n']:0,0) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-building"></i> التخلص حسب الأقسام</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>القسم</th><th>عمليات</th><th>قيمة إجمالية</th></tr></thead><tbody>
<?php foreach ($dept_sorted as $d): ?>
<tr><td style="font-weight:800"><?= e($d['name']) ?></td><td><strong><?= $d['n'] ?></strong></td><td style="font-family:monospace"><?= number_format($d['val'],0) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>

<?php else: ?>
<div style="margin-bottom:12px;font-weight:900">السجلات: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px"><?= $total ?></span></div>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow-x:auto">
<table class="tbl"><thead><tr><th>#</th><th>الأصل</th><th>الفئة / القسم</th><th>النوع</th><th>السبب</th><th>التاريخ</th><th>القيمة</th></tr></thead><tbody>
<?php foreach (array_slice($results, 0, 500) as $i => $r): $tc=$TYPE_COLOR[$r['disposal_type']]??'#475569'; $rc=$REASON_COLOR[$r['reason']]??'#475569'; ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td>
<td><div style="font-weight:800;color:#c2410c"><?= e($r['tag_number'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($r['asset_desc'] ?? '', 0, 40, '...')) ?></div></td>
<td style="font-size:11px"><?= e($r['cat_level1'] ?: '—') ?><br><?= e($r['dept_name'] ?: '—') ?></td>
<td><span class="badge" style="background:<?= $tc ?>"><?= e($TYPE_AR[$r['disposal_type']] ?? '') ?></span></td>
<td><span class="badge" style="background:<?= $rc ?>"><?= e($REASON_AR[$r['reason']] ?? '') ?></span></td>
<td style="font-size:11px"><?= e($r['disposal_date'] ? date('Y-m-d', strtotime($r['disposal_date'])) : '—') ?></td>
<td style="font-family:monospace;font-weight:800"><?= number_format((float)$r['disposal_value'],0) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty"><i class="fa-solid fa-trash-can" style="font-size:44px;color:var(--primary);display:block;margin-bottom:10px"></i><h3>لا توجد عمليات مطابقة</h3><p>عدّل الفلاتر أو امسحها.</p></div>
<?php endif; ?>

</div></main></div>
<script>
<?php if ($view_mode==='executive' && $results): ?>
document.addEventListener("DOMContentLoaded",function(){
const FF='Tajawal';
<?php if(!empty($type_agg)): ?>new ApexCharts(document.querySelector("#chType"),{series:<?= json_encode(array_column($type_sorted,'n')) ?>,labels:<?= json_encode(array_map(fn($t)=>$TYPE_AR[$t['type']]??$t['type'],$type_sorted),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:<?= json_encode(array_map(fn($t)=>$TYPE_COLOR[$t['type']]??'#475569',$type_sorted)) ?>,plotOptions:{pie:{donut:{size:'62%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();<?php endif; ?>
<?php if(!empty($reason_sorted)): ?>new ApexCharts(document.querySelector("#chReason"),{series:[{data:<?= json_encode(array_values($reason_sorted)) ?>}],chart:{type:'bar',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_map(fn($k)=>$REASON_AR[$k]??$k,array_keys($reason_sorted)),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#7c3aed'],plotOptions:{bar:{borderRadius:4,distributed:true}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#chMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>},colors:['#7c3aed'],stroke:{curve:'smooth',width:3},fill:{type:'gradient',gradient:{opacityFrom:.6,opacityTo:.05}},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
<?php endif; ?>
</script>
</body></html>