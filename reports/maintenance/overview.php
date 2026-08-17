<?php
/**
* reports/maintenance/overview.php — مركز تحليل الصيانة (Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* • محاور: أوامر العمل / أداء المقاولين / الصيانة الوقائية MTTR / الاتجاه
* • تصدير ماسي: Excel غني / PDF رسمي موقّع / لوحة مؤشرات A4
* • يكتشف الأعمدة تلقائياً — لا ينكسر أبداً
* • نظام التقارير المحفوظة الموحد (module = maintenance)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';
page_guard('reports.maintenance.overview');

$rtl = is_rtl();
$can_export = can('reports.maintenance.overview', 'export');
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$STATUS_AR = ['draft'=>'مسودة','sent_to_contractor'=>'مرسلة للمقاول','in_progress'=>'قيد التنفيذ','pending_manager_approval'=>'بانتظار الموافقة','completed'=>'مكتملة','rejected_by_manager'=>'مرفوضة','cancelled'=>'ملغاة'];
$STATUS_COLOR = ['draft'=>'#94a3b8','sent_to_contractor'=>'#0ea5e9','in_progress'=>'#7c3aed','pending_manager_approval'=>'#f59e0b','completed'=>'#16a34a','rejected_by_manager'=>'#dc2626','cancelled'=>'#475569'];
$TYPE_AR = ['medical'=>'طبية','general'=>'عامة','it'=>'تقنية'];
$TYPE_COLOR = ['medical'=>'#dc2626','general'=>'#0891b2','it'=>'#7c3aed'];
$OPEN_STATUSES = ['draft','sent_to_contractor','in_progress','pending_manager_approval'];

/* ═══ اكتشاف الأعمدة تلقائياً ═══ */
$comp_cols = array_column($pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='complaints'")->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
$has_asset = in_array('asset_id', $comp_cols, true);
$pm_cols = array_column($pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pm_schedules'")->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
$pm_has_asset = in_array('asset_id', $pm_cols, true);
$pm_has_freq = in_array('frequency', $pm_cols, true);

/* ═══ الفلاتر ═══ */
$view_mode = $_GET['view'] ?? 'executive';
function valid_date(string $v): string {
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
$f = [
    'status' => trim($_GET['status'] ?? ''),
    'type' => trim($_GET['type'] ?? ''),
    'contractor' => trim($_GET['contractor'] ?? ''),
    'from' => valid_date(trim($_GET['from'] ?? '')),
    'to' => valid_date(trim($_GET['to'] ?? '')),
];
if ($f['status'] !== '' && !array_key_exists($f['status'], $STATUS_AR)) $f['status'] = '';
if ($f['type'] !== '' && !array_key_exists($f['type'], $TYPE_AR)) $f['type'] = '';
$has_filters = array_filter($f) !== [];

$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;

/* ═══ بناء الاستعلام ═══ */
$where = ["1=1"]; $params = [];
if ($f['status'] !== '') { $where[] = 'w.status = :fst'; $params['fst'] = $f['status']; }
if ($f['type'] !== '') { $where[] = 'w.wo_type = :ftp'; $params['ftp'] = $f['type']; }
if ($f['contractor'] !== '') { $where[] = 'w.contractor_name = :fcon'; $params['fcon'] = $f['contractor']; }
if ($f['from']) { $where[] = 'DATE(w.wo_date) >= :ffrom'; $params['ffrom'] = $f['from']; }
if ($f['to']) { $where[] = 'DATE(w.wo_date) <= :fto'; $params['fto'] = $f['to']; }

$select = "w.id, w.wo_number, w.wo_date, w.status, w.wo_type, w.contractor_name, w.work_hours_total, w.actual_completion_date, w.complaint_id, c.request_number";
$joins = " LEFT JOIN complaints c ON c.id = w.complaint_id";
if ($has_asset) { $select .= ", ax.tag_number AS asset_tag, ax.description AS asset_desc"; $joins .= " LEFT JOIN assets ax ON ax.id = c.asset_id"; }

$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 20000;
$sql = "SELECT $select FROM complaint_work_orders w $joins WHERE " . implode(' AND ', $where) . " ORDER BY w.id DESC LIMIT $row_cap";
$st = $pdo->prepare($sql); $st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);

$contractors = $pdo->query("SELECT DISTINCT contractor_name FROM complaint_work_orders WHERE contractor_name IS NOT NULL AND contractor_name != '' ORDER BY contractor_name")->fetchAll(PDO::FETCH_COLUMN);

/* ═══ مؤشرات PM (عامة) ═══ */
$pm_active = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1")->fetchColumn();
$pm_overdue = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due < CURDATE()")->fetchColumn();
$pm_overdue_list = [];
if ($pm_overdue > 0) {
    $sel = "s.id, s.next_due" . ($pm_has_freq ? ", s.frequency" : "");
    $j = ""; 
    if ($pm_has_asset) { $sel .= ", ax.tag_number AS pm_tag, ax.description AS pm_desc"; $j = " LEFT JOIN assets ax ON ax.id = s.asset_id"; }
    $pm_overdue_list = $pdo->query("SELECT $sel FROM pm_schedules s $j WHERE s.is_active=1 AND s.next_due < CURDATE() ORDER BY s.next_due ASC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
}

/* ═══ التجميع الشامل ═══ */
$now = time();
$total = count($results);
$completed = 0; $active = 0; $cancelled = 0; $total_hours = 0;
$mttr_days = []; $status_cnt = []; $type_cnt = []; $month_cnt = []; $con_agg = []; $asset_agg = []; $stale = 0;
foreach ($results as $r) {
    $stt = $r['status'];
    $status_cnt[$stt] = ($status_cnt[$stt] ?? 0) + 1;
    if ($stt === 'completed') $completed++;
    elseif (in_array($stt, $OPEN_STATUSES, true)) $active++;
    else $cancelled++;

    $tp = $r['wo_type'] ?: 'general';
    if (!isset($type_cnt[$tp])) $type_cnt[$tp] = ['n'=>0,'hrs'=>0];
    $type_cnt[$tp]['n']++;
    if ($r['work_hours_total']) { $type_cnt[$tp]['hrs'] += (float)$r['work_hours_total']; $total_hours += (float)$r['work_hours_total']; }

    if ($stt === 'completed' && $r['wo_date'] && $r['actual_completion_date']) {
        $d = (strtotime($r['actual_completion_date']) - strtotime($r['wo_date'])) / 86400;
        if ($d >= 0) $mttr_days[] = $d;
    }
    if (in_array($stt, $OPEN_STATUSES, true) && $r['wo_date']) {
        $age = ($now - strtotime($r['wo_date'])) / 86400;
        if ($age > 30) $stale++;
    }
    if (!empty($r['wo_date'])) {
        $ym = substr($r['wo_date'], 0, 7);
        $month_cnt[$ym] = ($month_cnt[$ym] ?? 0) + 1;
    }
    $cn = $r['contractor_name'] ?: 'داخلي / بدون مقاول';
    if (!isset($con_agg[$cn])) $con_agg[$cn] = ['total'=>0,'completed'=>0,'hours'=>0,'mttr'=>[]];
    $con_agg[$cn]['total']++;
    if ($stt === 'completed') $con_agg[$cn]['completed']++;
    if ($r['work_hours_total']) $con_agg[$cn]['hours'] += (float)$r['work_hours_total'];
    if ($stt === 'completed' && $r['wo_date'] && $r['actual_completion_date']) {
        $d = (strtotime($r['actual_completion_date']) - strtotime($r['wo_date'])) / 86400;
        if ($d >= 0) $con_agg[$cn]['mttr'][] = $d;
    }
    if ($has_asset && !empty($r['asset_tag'])) {
        $k = $r['asset_tag'];
        if (!isset($asset_agg[$k])) $asset_agg[$k] = ['tag'=>$k,'desc'=>$r['asset_desc']??'—','cnt'=>0,'hours'=>0];
        $asset_agg[$k]['cnt']++;
        if ($r['work_hours_total']) $asset_agg[$k]['hours'] += (float)$r['work_hours_total'];
    }
}
$completion_rate = $total > 0 ? round($completed / $total * 100, 1) : 0;
$mttr_avg = $mttr_days ? round(array_sum($mttr_days) / count($mttr_days), 1) : 0;
ksort($month_cnt);

$con_sorted = [];
foreach ($con_agg as $n => $v) {
    $con_sorted[] = ['name'=>$n,'total'=>$v['total'],'completed'=>$v['completed'],'hours'=>round($v['hours'],1),
        'rate'=>$v['total']>0?round($v['completed']/$v['total']*100):0,
        'mttr'=>$v['mttr']?round(array_sum($v['mttr'])/count($v['mttr']),1):null];
}
usort($con_sorted, function($a,$b){ return $b['total'] <=> $a['total']; });
$con_sorted = array_slice($con_sorted, 0, 6);

$asset_sorted = array_values($asset_agg);
usort($asset_sorted, function($a,$b){ return $b['cnt'] <=> $a['cnt']; });
$asset_sorted = array_slice($asset_sorted, 0, 6);

/* ═══ تنبيهات الذكاء ═══ */
$ai = [];
if ($pm_overdue > 0) $ai[] = "🔴 $pm_overdue خطة صيانة وقائية متأخرة — جدولة فورية مطلوبة";
if ($mttr_avg > 7) $ai[] = "⏱️ MTTR مرتفع ($mttr_avg يوم) — راجع أداء المقاولين";
if ($stale > 0) $ai[] = "🕐 $stale أمر عمل مفتوح لأكثر من 30 يوماً — تصعيد";
if ($total > 0 && $completion_rate < 70) $ai[] = "📉 نسبة الإنجاز $completion_rate% دون الهدف (70%)";
$ai_class = empty($ai) ? 'ai-success' : (count($ai) >= 2 ? 'ai-danger' : 'ai-warning');
$ai_icon = empty($ai) ? 'fa-check-circle' : (count($ai) >= 2 ? 'fa-triangle-exclamation' : 'fa-bell');
$ai_msg = empty($ai) ? '✨ مؤشرات الصيانة ضمن النطاق الصحي.' : implode(' | ', $ai);

$title_parts = [];
if ($f['type']) $title_parts[] = $TYPE_AR[$f['type']];
if ($f['status'] !== '') $title_parts[] = $STATUS_AR[$f['status']];
if ($f['contractor']) $title_parts[] = $f['contractor'];
$report_title = 'تقرير الصيانة' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* ═══ 1. Excel غني ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Maintenance_Register_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:12px}th{background:#164e63;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:8px;text-align:center}td{border:1px solid #cbd5e1;padding:6px;text-align:center;vertical-align:middle}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="11" style="font-size:16px;background:#0891b2;padding:14px">سجل الصيانة التحليلي - <?= e($report_title) ?></th></tr>
<tr><th>رقم الأمر</th><th>مرجع البلاغ</th><th>النوع</th><th>الحالة</th><th>المقاول</th><th>Tag الأصل</th><th>وصف الأصل</th><th>تاريخ الأمر</th><th>تاريخ الإنجاز</th><th>MTTR (يوم)</th><th>ساعات العمل</th></tr>
</thead><tbody>
<?php foreach ($results as $r): $m = ($r['status']==='completed' && $r['wo_date'] && $r['actual_completion_date']) ? round((strtotime($r['actual_completion_date'])-strtotime($r['wo_date']))/86400,1) : ''; ?>
<tr><td><?= e($r['wo_number']) ?></td><td><?= e($r['request_number'] ?? '') ?></td><td><?= e($TYPE_AR[$r['wo_type']] ?? $r['wo_type']) ?></td><td><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></td>
<td><?= e($r['contractor_name'] ?? '') ?></td><td><?= e($r['asset_tag'] ?? '') ?></td><td><?= e($r['asset_desc'] ?? '') ?></td>
<td><?= e($r['wo_date']) ?></td><td><?= e($r['actual_completion_date'] ?? '') ?></td><td><?= $m ?></td><td><?= e($r['work_hours_total'] ?? '') ?></td></tr>
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
.print-header{background:linear-gradient(135deg,#f8fafc,#cffafe);border:1px solid #cbd5e1;border-radius:10px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.ph-right{display:flex;align-items:center;gap:12px;border-left:1px solid #cbd5e1;padding-left:18px}.ph-h1{font-size:16px;font-weight:800}.ph-h2{font-size:11px;color:#475569}
.ph-logo{height:46px;object-fit:contain}.ph-center{flex:1;text-align:center}.ph-title{font-size:16px;font-weight:800;color:#0891b2}.ph-left{text-align:left;font-size:10px;color:#475569}
.ph-pagebadge{background:#0891b2;color:#fff;padding:3px 10px;border-radius:4px;font-size:9px;font-weight:800;display:inline-block;margin-bottom:4px}
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
<div class="ph-center"><div class="ph-title">سجل الصيانة المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pn ?> من <?= $tp ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — السجلات: <strong><?= $total ?></strong></div></div>
</div></th></tr>
<tr><th>#</th><th>الأمر / المرجع</th><th>الأصل</th><th>النوع / الحالة</th><th>المقاول</th><th>التواريخ</th><th>الأداء</th></tr></thead><tbody>
<?php foreach ($pr as $i => $r): $sc = $STATUS_COLOR[$r['status']] ?? '#475569'; $tc = $TYPE_COLOR[$r['wo_type']] ?? '#475569';
$m = ($r['status']==='completed' && $r['wo_date'] && $r['actual_completion_date']) ? round((strtotime($r['actual_completion_date'])-strtotime($r['wo_date']))/86400,1) : null; ?>
<tr><td style="text-align:center"><?= $i+1 ?></td>
<td><strong><?= e($r['wo_number'] ?: '—') ?></strong><br><small>بلاغ: <?= e($r['request_number'] ?: '—') ?></small></td>
<td><?= e($r['asset_tag'] ?: '—') ?><br><small><?= e(mb_strimwidth($r['asset_desc'] ?? '', 0, 30, '...')) ?></small></td>
<td><span class="p-badge" style="background:<?= $tc ?>"><?= e($TYPE_AR[$r['wo_type']] ?? '') ?></span><br><span class="p-badge" style="background:<?= $sc ?>"><?= e($STATUS_AR[$r['status']] ?? '') ?></span></td>
<td><?= e($r['contractor_name'] ?: 'داخلي') ?></td>
<td><small>أمر: <?= e($r['wo_date'] ? date('Y-m-d', strtotime($r['wo_date'])) : '—') ?></small><br><small>إنجاز: <?= e($r['actual_completion_date'] ? date('Y-m-d', strtotime($r['actual_completion_date'])) : '—') ?></small></td>
<td><?= $m !== null ? $m . ' يوم' : '—' ?><br><small><?= $r['work_hours_total'] ? round((float)$r['work_hours_total'],1) . ' س' : '—' ?></small></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="7" style="border:none;padding:0"><div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">التوقيع</div></div>
<div class="sign-box"><div class="title">مشرف الصيانة</div><div class="line"></div><div class="hint">المراجعة</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد</div></div>
</div></td></tr></tfoot></table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. لوحة A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات الصيانة</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:A4 landscape;margin:0}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;margin:0}
.a4{width:297mm;height:209mm;padding:10mm;margin:0 auto;display:flex;flex-direction:column;overflow:hidden}
.hd{background:#164e63;color:#fff;border-radius:10px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.krow{display:flex;gap:12px;margin-bottom:12px}.kbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center;background:#f8fafc}
.kval{font-size:22px;font-weight:900}.klbl{font-size:11px;font-weight:800;color:#64748b}
.cwrap{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0}.crow{display:flex;gap:12px;flex:1;min-height:0}
.cbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;display:flex;flex-direction:column}.ct{font-size:12px;font-weight:900;text-align:center;margin-bottom:4px}.ca{flex:1;min-height:0}
.ft{text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;border-top:1px dashed #cbd5e1;padding-top:4px}
</style></head><body onload="setTimeout(()=>window.print(),1500)">
<div class="a4">
<div class="hd"><div style="font-size:18px;font-weight:900"><?= e($hospital) ?></div><div style="font-size:16px;font-weight:900;color:#67e8f9"><?= e($report_title) ?></div><div style="font-size:11px"><?= date('Y-m-d') ?></div></div>
<div class="krow">
<div class="kbox"><div class="kval"><?= number_format($total) ?></div><div class="klbl">أوامر العمل</div></div>
<div class="kbox"><div class="kval" style="color:#16a34a"><?= $completion_rate ?>%</div><div class="klbl">نسبة الإنجاز</div></div>
<div class="kbox"><div class="kval" style="color:#d97706"><?= $mttr_avg ?></div><div class="klbl">MTTR (يوم)</div></div>
<div class="kbox"><div class="kval" style="color:#dc2626"><?= $pm_overdue ?></div><div class="klbl">PM متأخرة</div></div>
<div class="kbox"><div class="kval"><?= number_format($total_hours,0) ?></div><div class="klbl">ساعات عمل</div></div>
</div>
<div class="cwrap">
<div class="crow">
<div class="cbox" style="flex:1.2"><div class="ct">توزيع حالات أوامر العمل</div><div class="ca" id="pSt"></div></div>
<div class="cbox"><div class="ct">نسبة الإنجاز</div><div class="ca" id="pComp"></div></div>
</div>
<div class="crow">
<div class="cbox"><div class="ct">أوامر العمل حسب النوع</div><div class="ca" id="pType"></div></div>
<div class="cbox" style="flex:1.2"><div class="ct">الاتجاه الشهري</div><div class="ca" id="pMo"></div></div>
</div>
</div>
<div class="ft">وثيقة تحليلية | <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded",function(){
<?php if(!empty($status_cnt)): ?>new ApexCharts(document.querySelector("#pSt"),{series:<?= json_encode(array_values($status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$STATUS_AR[$k]??$k,array_keys($status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:<?= json_encode(array_values($STATUS_COLOR)) ?>,plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();<?php endif; ?>
new ApexCharts(document.querySelector("#pComp"),{series:[<?= $completion_rate ?>],chart:{type:'radialBar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},plotOptions:{radialBar:{hollow:{size:'65%'},dataLabels:{show:true,name:{show:false},value:{offsetY:8,fontSize:'26px',fontWeight:900,color:'<?= $completion_rate>=70?'#16a34a':'#dc2626' ?>',formatter:v=>v+'%'}}}},fill:{colors:['<?= $completion_rate>=70?'#16a34a':'#dc2626' ?>']}}).render();
<?php if(!empty($type_cnt)): ?>new ApexCharts(document.querySelector("#pType"),{series:[{data:<?= json_encode(array_column(array_values($type_cnt),'n')) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_map(fn($k)=>$TYPE_AR[$k]??$k,array_keys($type_cnt)),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#0891b2'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#pMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#0891b2'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
</script></body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مركز تحليل الصيانة — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--primary:#0891b2;--bg:#f8fafc;--border:#e2e8f0;--tm:#0f172a;--t2:#475569;--t3:#94a3b8;--radius:16px}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--tm)}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.view-toggles{display:flex;gap:10px;margin-bottom:16px;background:#fff;padding:6px;border-radius:99px;width:fit-content;border:1px solid var(--border)}
.toggle-btn{padding:10px 24px;border-radius:99px;font-size:13.5px;font-weight:800;color:var(--t2);text-decoration:none;display:flex;align-items:center;gap:8px}
.toggle-btn.active{background:var(--primary);color:#fff}
.header-hero{background:linear-gradient(135deg,#164e63,#0891b2);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center}
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
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border-radius:var(--radius);padding:16px;border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.kpi-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.kpi-val{font-size:20px;font-weight:900}.kpi-title{font-size:11.5px;font-weight:800;color:var(--t3)}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:20px}
.chart-card{background:#fff;border-radius:var(--radius);padding:16px;border:1px solid var(--border)}
.chart-title{font-weight:900;font-size:14px;margin-bottom:10px;display:flex;gap:8px;align-items:center;border-bottom:1px dashed var(--border);padding-bottom:8px}
.axis-sec{background:#fff;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;overflow:hidden}
.axis-h{padding:14px 18px;font-weight:900;font-size:15px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border);background:linear-gradient(90deg,#ecfeff,#fff)}
.axis-h i{color:var(--primary)}
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{background:#f8fafc;padding:8px 10px;text-align:right;font-size:10.5px;font-weight:900;color:var(--t2);border-bottom:2px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
.tbl tr:hover td{background:#ecfeff}
.badge{display:inline-flex;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:800;gap:4px;align-items:center;color:#fff}
.bar-bg{height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-top:4px}.bar-bg>div{height:100%;border-radius:99px}
.empty{text-align:center;padding:50px;color:var(--t3);background:#fff;border-radius:var(--radius);border:1px solid var(--border)}
</style></head>
<body class="app-layout">
<?php $__f_backup = $f ?? []; include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH . '/includes/topbar.php'; $f = $__f_backup; ?>
<main class="page-content"><div class="wrap">

<div class="view-toggles">
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة تحليل الصيانة</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> سجل أوامر العمل</a>
</div>

<div class="header-hero">
<div><h1 style="font-size:20px;font-weight:900;margin:0"><i class="fa-solid fa-screwdriver-wrench" style="margin-left:8px;color:#67e8f9"></i> مركز تحليل الصيانة</h1>
<div style="color:#cffafe;font-size:13px;margin-top:4px">أوامر العمل، أداء المقاولين، الصيانة الوقائية، MTTR، الاتجاه</div></div>
<div style="text-align:left;font-size:11px;color:#cffafe">تاريخ التقرير<br><strong style="font-size:15px;color:#fff"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($results): ?><div class="ai-banner <?= $ai_class ?>"><i class="fa-solid <?= $ai_icon ?>"></i><span><?= e($ai_msg) ?></span></div><?php endif; ?>

<?php
$sr_module = 'maintenance'; $sr_filters = $f; $sr_view = $view_mode; $sr_base_url = BASE_URL;
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:#cffafe;padding:6px;border-radius:6px"></i> فلاتر الدراسة <i class="fa-solid fa-chevron-down" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>الحالة</label><select name="status"><option value="">— الكل —</option><?php foreach($STATUS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>النوع</label><select name="type"><option value="">— الكل —</option><?php foreach($TYPE_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>المقاول</label><select name="contractor"><option value="">— الكل —</option><?php foreach($contractors as $c): ?><option value="<?= e($c) ?>" <?= $f['contractor']===$c?'selected':'' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
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
<div class="kpi-card"><div class="kpi-icon" style="background:#cffafe;color:#0891b2"><i class="fa-solid fa-file-signature"></i></div><div><div class="kpi-title">أوامر العمل</div><div class="kpi-val"><?= number_format($total) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-spinner"></i></div><div><div class="kpi-title">نشطة</div><div class="kpi-val"><?= $active ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div><div><div class="kpi-title">نسبة الإنجاز</div><div class="kpi-val"><?= $completion_rate ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-stopwatch"></i></div><div><div class="kpi-title">MTTR (يوم)</div><div class="kpi-val"><?= $mttr_avg ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-calendar-xmark"></i></div><div><div class="kpi-title">PM متأخرة</div><div class="kpi-val"><?= $pm_overdue ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-clock"></i></div><div><div class="kpi-title">ساعات عمل</div><div class="kpi-val"><?= number_format($total_hours,0) ?></div></div></div>
</div>

<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> توزيع الحالات</div><div id="chSt" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#0891b2"></i> الاتجاه الشهري</div><div id="chMo" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-gauge-high" style="color:#16a34a"></i> نسبة الإنجاز</div><div id="chComp" style="min-height:220px;display:flex;align-items:center;justify-content:center"></div></div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-handshake"></i> أداء المقاولين</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>#</th><th>المقاول</th><th>أوامر</th><th>مكتملة</th><th>% إنجاز</th><th>متوسط MTTR</th><th>ساعات</th></tr></thead><tbody>
<?php foreach ($con_sorted as $i => $c): $rc = $c['rate']>=80?'#16a34a':($c['rate']>=60?'#d97706':'#dc2626'); ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td><td style="font-weight:800"><?= e($c['name']) ?></td><td><?= $c['total'] ?></td><td><?= $c['completed'] ?></td>
<td><strong style="color:<?= $rc ?>"><?= $c['rate'] ?>%</strong><div class="bar-bg"><div style="width:<?= $c['rate'] ?>%;background:<?= $rc ?>"></div></div></td>
<td><?= $c['mttr'] !== null ? $c['mttr'] . ' يوم' : '—' ?></td><td><?= $c['hours'] ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>

<?php if ($pm_overdue_list): ?>
<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-calendar-xmark"></i> الصيانة الوقائية المتأخرة</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>الأصل</th><th>الوصف</th><th>التكرار</th><th>مستحقة منذ</th></tr></thead><tbody>
<?php foreach ($pm_overdue_list as $p): $late = round(($now - strtotime($p['next_due']))/86400); ?>
<tr><td style="font-weight:800;color:#c2410c"><?= e($p['pm_tag'] ?? '—') ?></td><td style="font-size:11px"><?= e(mb_strimwidth($p['pm_desc'] ?? '', 0, 50, '...')) ?></td>
<td style="font-size:11px"><?= e($p['frequency'] ?? '—') ?></td><td><span class="badge" style="background:#dc2626"><?= $late ?> يوم تأخير</span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php endif; ?>

<?php if ($asset_sorted): ?>
<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-rotate"></i> أكثر الأصول طلباً للصيانة</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>الأصل</th><th>الوصف</th><th>أوامر</th><th>ساعات</th></tr></thead><tbody>
<?php foreach ($asset_sorted as $a): ?>
<tr><td style="font-weight:800;color:#c2410c"><?= e($a['tag']) ?></td><td style="font-size:11px"><?= e(mb_strimwidth($a['desc'], 0, 50, '...')) ?></td><td><strong><?= $a['cnt'] ?></strong></td><td><?= round($a['hours'],1) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php endif; ?>

<?php else: ?>
<div style="margin-bottom:12px;font-weight:900">السجلات: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px"><?= $total ?></span></div>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow-x:auto">
<table class="tbl"><thead><tr><th>#</th><th>الأمر / المرجع</th><th>الأصل</th><th>النوع</th><th>الحالة</th><th>المقاول</th><th>التواريخ</th><th>الأداء</th></tr></thead><tbody>
<?php foreach (array_slice($results, 0, 500) as $i => $r): $sc = $STATUS_COLOR[$r['status']] ?? '#475569'; $tc = $TYPE_COLOR[$r['wo_type']] ?? '#475569';
$m = ($r['status']==='completed' && $r['wo_date'] && $r['actual_completion_date']) ? round((strtotime($r['actual_completion_date'])-strtotime($r['wo_date']))/86400,1) : null; ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td>
<td><div style="font-weight:800"><?= e($r['wo_number'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)">بلاغ: <?= e($r['request_number'] ?: '—') ?></div></td>
<td><div style="font-weight:800;color:#c2410c"><?= e($r['asset_tag'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($r['asset_desc'] ?? '', 0, 30, '...')) ?></div></td>
<td><span class="badge" style="background:<?= $tc ?>"><?= e($TYPE_AR[$r['wo_type']] ?? '') ?></span></td>
<td><span class="badge" style="background:<?= $sc ?>"><?= e($STATUS_AR[$r['status']] ?? '') ?></span></td>
<td style="font-size:11px"><?= e($r['contractor_name'] ?: 'داخلي') ?></td>
<td style="font-size:11px">أمر: <?= e($r['wo_date'] ? date('Y-m-d', strtotime($r['wo_date'])) : '—') ?><br>إنجاز: <?= e($r['actual_completion_date'] ? date('Y-m-d', strtotime($r['actual_completion_date'])) : '—') ?></td>
<td><?= $m !== null ? $m . ' يوم' : '—' ?><br><small style="color:var(--t3)"><?= $r['work_hours_total'] ? round((float)$r['work_hours_total'],1) . ' س' : '—' ?></small></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty"><i class="fa-solid fa-screwdriver-wrench" style="font-size:44px;color:var(--primary);display:block;margin-bottom:10px"></i><h3>لا توجد أوامر عمل مطابقة</h3><p>عدّل الفلاتر أو امسحها.</p></div>
<?php endif; ?>

</div></main></div>
<script>
<?php if ($view_mode==='executive' && $results): ?>
document.addEventListener("DOMContentLoaded",function(){
const FF='Tajawal';
<?php if(!empty($status_cnt)): ?>new ApexCharts(document.querySelector("#chSt"),{series:<?= json_encode(array_values($status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$STATUS_AR[$k]??$k,array_keys($status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:<?= json_encode(array_values($STATUS_COLOR)) ?>,plotOptions:{pie:{donut:{size:'62%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#chMo"),{series:[{name:'أوامر',data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>},colors:['#0891b2'],stroke:{curve:'smooth',width:3},fill:{type:'gradient',gradient:{opacityFrom:.6,opacityTo:.05}},dataLabels:{enabled:false}}).render();<?php endif; ?>
new ApexCharts(document.querySelector("#chComp"),{series:[<?= $completion_rate ?>],chart:{type:'radialBar',height:'120%',fontFamily:FF},plotOptions:{radialBar:{hollow:{size:'65%'},track:{background:'#f1f5f9',strokeWidth:'100%'},dataLabels:{show:true,name:{offsetY:20,show:true,color:'#64748b',fontSize:'12px',fontWeight:700},value:{offsetY:-10,color:'<?= $completion_rate>=70?'#16a34a':'#dc2626' ?>',fontSize:'26px',fontWeight:900,formatter:v=>v+'%'}}}},fill:{colors:['<?= $completion_rate>=70?'#16a34a':'#dc2626' ?>']},stroke:{lineCap:'round'},labels:['إنجاز']}).render();
});
<?php endif; ?>
</script>
</body></html>