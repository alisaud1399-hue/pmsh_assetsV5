<?php
/**
* reports/helpdesk/overview.php — مركز تحليل التذاكر (Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* • محاور: الحالات / الأولوية / الفئات / أداء الوكلاء / SLA / الاتجاه
* • تصدير ماسي: Excel غني / PDF رسمي موقّع / لوحة مؤشرات A4
* • يكتشف الأعمدة تلقائياً — لا ينكسر أبداً
* • نظام التقارير المحفوظة الموحد (module = helpdesk)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';
page_guard('reports.helpdesk.overview');

if (isset($_GET['apply_saved'])) {
    sr_apply_saved($pdo, (int)$_GET['apply_saved'], (int)current_user()['id']);
}

$rtl = is_rtl();
$can_export = can('reports.helpdesk.overview', 'export');
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$STATUS_AR = ['new'=>'جديدة','in_review'=>'قيد المراجعة','awaiting_user'=>'بانتظار المستخدم','closed'=>'مغلقة'];
$STATUS_COLOR = ['new'=>'#0ea5e9','in_review'=>'#7c3aed','awaiting_user'=>'#f59e0b','closed'=>'#16a34a'];
$PRIORITY_AR = ['critical'=>'حرجة','high'=>'عالية','medium'=>'متوسطة','low'=>'منخفضة'];
$PRIORITY_COLOR = ['critical'=>'#dc2626','high'=>'#f59e0b','medium'=>'#0ea5e9','low'=>'#1565C0'];

/* ═══ اكتشاف الأعمدة تلقائياً ═══ */
$t_cols = array_column($pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='helpdesk_tickets'")->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
$HAS = function(string $c) use ($t_cols) { return in_array($c, $t_cols, true); };
$has_assigned = $HAS('assigned_to');

/* ═══ الفلاتر ═══ */
$view_mode = $_GET['view'] ?? 'executive';
function valid_date(string $v): string {
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
$f = [
    'status' => trim($_GET['status'] ?? ''),
    'priority' => trim($_GET['priority'] ?? ''),
    'category' => (int)($_GET['category'] ?? 0),
    'from' => valid_date(trim($_GET['from'] ?? '')),
    'to' => valid_date(trim($_GET['to'] ?? '')),
    'q' => trim($_GET['q'] ?? ''),
];
if ($f['status'] !== '' && !array_key_exists($f['status'], $STATUS_AR)) $f['status'] = '';
if ($f['priority'] !== '' && !array_key_exists($f['priority'], $PRIORITY_AR)) $f['priority'] = '';
$has_filters = array_filter($f) !== [];

$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;

/* ═══ بناء الاستعلام ═══ */
$where = ["1=1"]; $params = [];
if ($f['status'] !== '') { $where[] = 't.status = :fst'; $params['fst'] = $f['status']; }
if ($f['priority'] !== '') { $where[] = 't.priority = :fpr'; $params['fpr'] = $f['priority']; }
if ($f['category']) { $where[] = 't.category_id = :fcat'; $params['fcat'] = $f['category']; }
if ($f['from']) { $where[] = 'DATE(t.created_at) >= :ffrom'; $params['ffrom'] = $f['from']; }
if ($f['to']) { $where[] = 'DATE(t.created_at) <= :fto'; $params['fto'] = $f['to']; }
if ($f['q'] !== '') {
    $where[] = "(t.ticket_number LIKE :q OR t.title LIKE :q)";
    $params['q'] = '%' . $f['q'] . '%';
}

$select = "t.id, t.ticket_number, t.title, t.priority, t.status, t.created_at, t.first_response_at, t.resolved_at, t.closed_at, t.sla_breached, c.name_ar AS cat_name";
$joins = " LEFT JOIN helpdesk_categories c ON c.id = t.category_id";
if ($has_assigned) { $select .= ", t.assigned_to, u.full_name AS agent_name"; $joins .= " LEFT JOIN users u ON u.id = t.assigned_to"; }

$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 20000;
$sql = "SELECT $select FROM helpdesk_tickets t $joins WHERE " . implode(' AND ', $where) . " ORDER BY t.id DESC LIMIT $row_cap";
$st = $pdo->prepare($sql); $st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT id, name_ar FROM helpdesk_categories ORDER BY name_ar")->fetchAll(PDO::FETCH_ASSOC);
$g_messages = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_messages")->fetchColumn();

/* ═══ التجميع الشامل ═══ */
$now = time();
$total = count($results);
$open = 0; $closed = 0; $critical_open = 0; $sla_cnt = 0; $stale_awaiting = 0;
$first_hours = []; $res_hours = [];
$status_cnt = []; $priority_cnt = []; $cat_agg = []; $month_cnt = []; $agent_agg = [];
foreach ($results as $r) {
    $stt = $r['status'];
    $status_cnt[$stt] = ($status_cnt[$stt] ?? 0) + 1;
    if ($stt === 'closed') $closed++; else $open++;
    if ($r['priority'] === 'critical' && $stt !== 'closed') $critical_open++;
    if (!empty($r['sla_breached'])) $sla_cnt++;
    if ($stt === 'awaiting_user' && $r['created_at']) {
        $age_h = ($now - strtotime($r['created_at'])) / 3600;
        if ($age_h > 72) $stale_awaiting++;
    }
    if ($r['created_at'] && $r['first_response_at']) {
        $h = (strtotime($r['first_response_at']) - strtotime($r['created_at'])) / 3600;
        if ($h >= 0) $first_hours[] = $h;
    }
    $end = $r['resolved_at'] ?: $r['closed_at'];
    if ($r['created_at'] && $end) {
        $h = (strtotime($end) - strtotime($r['created_at'])) / 3600;
        if ($h >= 0) $res_hours[] = $h;
    }
    $pr = $r['priority'];
    $priority_cnt[$pr] = ($priority_cnt[$pr] ?? 0) + 1;
    $cn = $r['cat_name'] ?: 'بدون تصنيف';
    if (!isset($cat_agg[$cn])) $cat_agg[$cn] = ['n'=>0,'closed'=>0,'sla'=>0];
    $cat_agg[$cn]['n']++;
    if ($stt === 'closed') $cat_agg[$cn]['closed']++;
    if (!empty($r['sla_breached'])) $cat_agg[$cn]['sla']++;
    if (!empty($r['created_at'])) {
        $ym = substr($r['created_at'], 0, 7);
        $month_cnt[$ym] = ($month_cnt[$ym] ?? 0) + 1;
    }
    if ($has_assigned && !empty($r['agent_name'])) {
        $an = $r['agent_name'];
        if (!isset($agent_agg[$an])) $agent_agg[$an] = ['n'=>0,'closed'=>0,'sla'=>0];
        $agent_agg[$an]['n']++;
        if ($stt === 'closed') $agent_agg[$an]['closed']++;
        if (!empty($r['sla_breached'])) $agent_agg[$an]['sla']++;
    }
}
$close_rate = $total > 0 ? round($closed / $total * 100, 1) : 0;
$sla_rate = $total > 0 ? round((1 - $sla_cnt / $total) * 100) : 100;
$avg_first = $first_hours ? round(array_sum($first_hours) / count($first_hours), 1) : 0;
$avg_res = $res_hours ? round(array_sum($res_hours) / count($res_hours), 1) : 0;
ksort($month_cnt);

$cat_sorted = [];
foreach ($cat_agg as $n => $v) $cat_sorted[] = ['name'=>$n] + $v + ['rate'=>$v['n']>0?round($v['closed']/$v['n']*100):0];
usort($cat_sorted, function($a,$b){ return $b['n'] <=> $a['n']; });
$cat_sorted = array_slice($cat_sorted, 0, 8);

$agent_sorted = [];
foreach ($agent_agg as $n => $v) $agent_sorted[] = ['name'=>$n] + $v + ['rate'=>$v['n']>0?round($v['closed']/$v['n']*100):0];
usort($agent_sorted, function($a,$b){ return $b['n'] <=> $a['n']; });
$agent_sorted = array_slice($agent_sorted, 0, 6);

/* ═══ تنبيهات الذكاء ═══ */
$ai = [];
if ($total > 0 && $sla_rate < 80) $ai[] = "⏱️ التزام SLA $sla_rate% دون الهدف (80%)";
if ($critical_open > 0) $ai[] = "⚡ $critical_open تذكرة حرجة مفتوحة — أولوية معالجة";
if ($stale_awaiting > 0) $ai[] = "🕐 $stale_awaiting تذكرة بانتظار المستخدم > 72 ساعة — تذكير مطلوب";
if ($avg_first > 24) $ai[] = "🐢 متوسط الرد الأول $avg_first ساعة — بطيء";
$ai_class = empty($ai) ? 'ai-success' : (count($ai) >= 2 ? 'ai-danger' : 'ai-warning');
$ai_icon = empty($ai) ? 'fa-check-circle' : (count($ai) >= 2 ? 'fa-triangle-exclamation' : 'fa-bell');
$ai_msg = empty($ai) ? '✨ مؤشرات التذاكر ضمن النطاق الصحي.' : implode(' | ', $ai);

$title_parts = [];
if ($f['priority']) $title_parts[] = $PRIORITY_AR[$f['priority']];
if ($f['status'] !== '') $title_parts[] = $STATUS_AR[$f['status']];
$report_title = 'تقرير التذاكر' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* ═══ 1. Excel غني ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Helpdesk_Register_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:12px}th{background:#164e63;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:8px;text-align:center}td{border:1px solid #cbd5e1;padding:6px;text-align:center;vertical-align:middle}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="10" style="font-size:16px;background:#0891b2;padding:14px">سجل التذاكر التحليلي - <?= e($report_title) ?></th></tr>
<tr><th>رقم التذكرة</th><th>العنوان</th><th>الفئة</th><th>الأولوية</th><th>الحالة</th><th>تاريخ الإنشاء</th><th>أول رد (س)</th><th>الحل (س)</th><th>SLA</th><?= $has_assigned?'<th>الوكيل</th>':'' ?></tr>
</thead><tbody>
<?php foreach ($results as $r):
$fh = ($r['created_at'] && $r['first_response_at']) ? round((strtotime($r['first_response_at'])-strtotime($r['created_at']))/3600,1) : '';
$rh = ($r['created_at'] && ($r['resolved_at']?:$r['closed_at'])) ? round((strtotime($r['resolved_at']?:$r['closed_at'])-strtotime($r['created_at']))/3600,1) : '';
?>
<tr><td><?= e($r['ticket_number']) ?></td><td><?= e($r['title']) ?></td><td><?= e($r['cat_name'] ?? '') ?></td><td><?= e($PRIORITY_AR[$r['priority']] ?? $r['priority']) ?></td><td><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></td>
<td><?= e($r['created_at']) ?></td><td><?= $fh ?></td><td><?= $rh ?></td><td><?= !empty($r['sla_breached'])?'تجاوز':'ملتزم' ?></td><?= $has_assigned?'<td>'.e($r['agent_name'] ?? '').'</td>':'' ?></tr>
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
<div class="ph-center"><div class="ph-title">سجل التذاكر المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pn ?> من <?= $tp ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — السجلات: <strong><?= $total ?></strong></div></div>
</div></th></tr>
<tr><th>#</th><th>التذكرة</th><th>الفئة</th><th>الأولوية / الحالة</th><th>الأوقات</th><th>SLA</th><?= $has_assigned?'<th>الوكيل</th>':'' ?></tr></thead><tbody>
<?php foreach ($pr as $i => $r): $sc=$STATUS_COLOR[$r['status']]??'#475569'; $pc=$PRIORITY_COLOR[$r['priority']]??'#475569';
$rh = ($r['created_at'] && ($r['resolved_at']?:$r['closed_at'])) ? round((strtotime($r['resolved_at']?:$r['closed_at'])-strtotime($r['created_at']))/3600,1) : null; ?>
<tr><td style="text-align:center"><?= $i+1 ?></td>
<td><strong><?= e($r['ticket_number']) ?></strong><br><small><?= e(mb_strimwidth($r['title'] ?? '', 0, 45, '...')) ?></small></td>
<td style="font-size:9px"><?= e($r['cat_name'] ?: '—') ?></td>
<td><span class="p-badge" style="background:<?= $pc ?>"><?= e($PRIORITY_AR[$r['priority']] ?? '') ?></span><br><span class="p-badge" style="background:<?= $sc ?>"><?= e($STATUS_AR[$r['status']] ?? '') ?></span></td>
<td><small>إنشاء: <?= e($r['created_at'] ? date('m-d H:i', strtotime($r['created_at'])) : '—') ?></small><br><small>حل: <?= $rh !== null ? $rh . ' س' : '—' ?></small></td>
<td><span class="p-badge" style="background:<?= !empty($r['sla_breached'])?'#dc2626':'#16a34a' ?>"><?= !empty($r['sla_breached'])?'تجاوز':'ملتزم' ?></span></td>
<?= $has_assigned?'<td style="font-size:9px">'.e($r['agent_name'] ?: '—').'</td>':'' ?></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="7" style="border:none;padding:0"><div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">التوقيع</div></div>
<div class="sign-box"><div class="title">مشرف Helpdesk</div><div class="line"></div><div class="hint">المراجعة</div></div>
<div class="sign-box"><div class="title">مدير الإدارة</div><div class="line"></div><div class="hint">الاعتماد</div></div>
</div></td></tr></tfoot></table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. لوحة A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات التذاكر</title>
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
<div class="kbox"><div class="kval"><?= number_format($total) ?></div><div class="klbl">تذاكر</div></div>
<div class="kbox"><div class="kval" style="color:#16a34a"><?= $close_rate ?>%</div><div class="klbl">نسبة الإغلاق</div></div>
<div class="kbox"><div class="kval" style="color:<?= $sla_rate>=80?'#16a34a':'#dc2626' ?>"><?= $sla_rate ?>%</div><div class="klbl">التزام SLA</div></div>
<div class="kbox"><div class="kval" style="color:#d97706"><?= $avg_first ?>h</div><div class="klbl">متوسط الرد الأول</div></div>
<div class="kbox"><div class="kval" style="color:#dc2626"><?= $critical_open ?></div><div class="klbl">حرجة مفتوحة</div></div>
</div>
<div class="cwrap">
<div class="crow">
<div class="cbox" style="flex:1.2"><div class="ct">توزيع الحالات</div><div class="ca" id="pSt"></div></div>
<div class="cbox"><div class="ct">التزام SLA</div><div class="ca" id="pSla"></div></div>
</div>
<div class="crow">
<div class="cbox"><div class="ct">حسب الأولوية</div><div class="ca" id="pPr"></div></div>
<div class="cbox" style="flex:1.2"><div class="ct">الاتجاه الشهري</div><div class="ca" id="pMo"></div></div>
</div>
</div>
<div class="ft">وثيقة تحليلية | <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded",function(){
<?php if(!empty($status_cnt)): ?>new ApexCharts(document.querySelector("#pSt"),{series:<?= json_encode(array_values($status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$STATUS_AR[$k]??$k,array_keys($status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:<?= json_encode(array_values($STATUS_COLOR)) ?>,plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();<?php endif; ?>
new ApexCharts(document.querySelector("#pSla"),{series:[<?= $sla_rate ?>],chart:{type:'radialBar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},plotOptions:{radialBar:{hollow:{size:'65%'},dataLabels:{show:true,name:{show:false},value:{offsetY:8,fontSize:'26px',fontWeight:900,color:'<?= $sla_rate>=80?'#16a34a':'#dc2626' ?>',formatter:v=>v+'%'}}}},fill:{colors:['<?= $sla_rate>=80?'#16a34a':'#dc2626' ?>']}}).render();
<?php if(!empty($priority_cnt)): ?>new ApexCharts(document.querySelector("#pPr"),{series:[{data:<?= json_encode(array_map(fn($k)=>$priority_cnt[$k]??0,array_keys($PRIORITY_AR))) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_values($PRIORITY_AR),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#0891b2'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#pMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#0891b2'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
</script></body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مركز تحليل التذاكر — <?= e(get_setting('hospital_name','PMSH')) ?></title>
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
.header-hero{background:linear-gradient(135deg,#164e63,#0e7490);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center}
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
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة تحليل التذاكر</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> سجل التذاكر التفصيلي</a>
</div>

<div class="header-hero">
<div><h1 style="font-size:20px;font-weight:900;margin:0"><i class="fa-solid fa-headset" style="margin-left:8px;color:#67e8f9"></i> مركز تحليل التذاكر</h1>
<div style="color:#cffafe;font-size:13px;margin-top:4px">حالات، أولوية، فئات، أداء وكلاء، SLA، اتجاه</div></div>
<div style="text-align:left;font-size:11px;color:#cffafe">تاريخ التقرير<br><strong style="font-size:15px;color:#fff"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($results): ?><div class="ai-banner <?= $ai_class ?>"><i class="fa-solid <?= $ai_icon ?>"></i><span><?= e($ai_msg) ?></span></div><?php endif; ?>

<?php
$sr_module = 'helpdesk'; $sr_filters = $f; $sr_view = $view_mode; $sr_base_url = BASE_URL;
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:#cffafe;padding:6px;border-radius:6px"></i> فلاتر الدراسة <i class="fa-solid fa-chevron-down" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>بحث (رقم/عنوان)</label><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="ابحث عن تذكرة..."></div>
<div class="fld"><label>الحالة</label><select name="status"><option value="">— الكل —</option><?php foreach($STATUS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>الأولوية</label><select name="priority"><option value="">— الكل —</option><?php foreach($PRIORITY_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['priority']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>الفئة</label><select name="category"><option value="">— الكل —</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['category']===(int)$c['id']?'selected':'' ?>><?= e($c['name_ar']) ?></option><?php endforeach; ?></select></div>
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
<div class="kpi-card"><div class="kpi-icon" style="background:#cffafe;color:#0891b2"><i class="fa-solid fa-ticket"></i></div><div><div class="kpi-title">تذاكر</div><div class="kpi-val"><?= number_format($total) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-door-open"></i></div><div><div class="kpi-title">مفتوحة</div><div class="kpi-val"><?= $open ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div><div><div class="kpi-title">نسبة الإغلاق</div><div class="kpi-val"><?= $close_rate ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:<?= $sla_rate>=80?'#dcfce7':'#fee2e2' ?>;color:<?= $sla_rate>=80?'#16a34a':'#dc2626' ?>"><i class="fa-solid fa-stopwatch"></i></div><div><div class="kpi-title">التزام SLA</div><div class="kpi-val"><?= $sla_rate ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-reply"></i></div><div><div class="kpi-title">متوسط الرد الأول</div><div class="kpi-val"><?= $avg_first ?>h</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-bolt"></i></div><div><div class="kpi-title">حرجة مفتوحة</div><div class="kpi-val"><?= $critical_open ?></div></div></div>
</div>

<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> توزيع الحالات</div><div id="chSt" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-column" style="color:#f59e0b"></i> حسب الأولوية</div><div id="chPr" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#0891b2"></i> الاتجاه الشهري</div><div id="chMo" style="min-height:220px"></div></div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-layer-group"></i> أداء الفئات</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>الفئة</th><th>تذاكر</th><th>مغلقة</th><th>% إغلاق</th><th>تجاوز SLA</th></tr></thead><tbody>
<?php foreach ($cat_sorted as $c): $rc = $c['rate']>=80?'#16a34a':($c['rate']>=60?'#d97706':'#dc2626'); ?>
<tr><td style="font-weight:800"><?= e($c['name']) ?></td><td><?= $c['n'] ?></td><td><?= $c['closed'] ?></td>
<td><strong style="color:<?= $rc ?>"><?= $c['rate'] ?>%</strong><div class="bar-bg"><div style="width:<?= $c['rate'] ?>%;background:<?= $rc ?>"></div></div></td>
<td><?= $c['sla'] ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>

<?php if ($agent_sorted): ?>
<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-user-headset"></i> أداء الوكلاء (Leaderboard)</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>#</th><th>الوكيل</th><th>تذاكر</th><th>مغلقة</th><th>% إغلاق</th><th>تجاوز SLA</th></tr></thead><tbody>
<?php foreach ($agent_sorted as $i => $a): $rc = $a['rate']>=80?'#16a34a':($a['rate']>=60?'#d97706':'#dc2626'); ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td><td style="font-weight:800"><?= e($a['name']) ?></td><td><?= $a['n'] ?></td><td><?= $a['closed'] ?></td>
<td><strong style="color:<?= $rc ?>"><?= $a['rate'] ?>%</strong></td><td><?= $a['sla'] ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php endif; ?>

<?php else: ?>
<div style="margin-bottom:12px;font-weight:900">السجلات: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px"><?= $total ?></span></div>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow-x:auto">
<table class="tbl"><thead><tr><th>#</th><th>التذكرة</th><th>الفئة</th><th>الأولوية</th><th>الحالة</th><th>الأوقات</th><th>SLA</th><?= $has_assigned?'<th>الوكيل</th>':'' ?></tr></thead><tbody>
<?php foreach (array_slice($results, 0, 500) as $i => $r): $sc=$STATUS_COLOR[$r['status']]??'#475569'; $pc=$PRIORITY_COLOR[$r['priority']]??'#475569';
$rh = ($r['created_at'] && ($r['resolved_at']?:$r['closed_at'])) ? round((strtotime($r['resolved_at']?:$r['closed_at'])-strtotime($r['created_at']))/3600,1) : null; ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td>
<td><div style="font-weight:800;color:#0891b2"><?= e($r['ticket_number']) ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($r['title'] ?? '', 0, 50, '...')) ?></div></td>
<td style="font-size:11px"><?= e($r['cat_name'] ?: '—') ?></td>
<td><span class="badge" style="background:<?= $pc ?>"><?= e($PRIORITY_AR[$r['priority']] ?? '') ?></span></td>
<td><span class="badge" style="background:<?= $sc ?>"><?= e($STATUS_AR[$r['status']] ?? '') ?></span></td>
<td style="font-size:11px">إنشاء: <?= e($r['created_at'] ? date('m-d H:i', strtotime($r['created_at'])) : '—') ?><br>حل: <?= $rh !== null ? $rh . ' س' : '—' ?></td>
<td><span class="badge" style="background:<?= !empty($r['sla_breached'])?'#dc2626':'#16a34a' ?>"><?= !empty($r['sla_breached'])?'تجاوز':'ملتزم' ?></span></td>
<?= $has_assigned?'<td style="font-size:11px">'.e($r['agent_name'] ?: '—').'</td>':'' ?></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty"><i class="fa-solid fa-headset" style="font-size:44px;color:var(--primary);display:block;margin-bottom:10px"></i><h3>لا توجد تذاكر مطابقة</h3><p>عدّل الفلاتر أو امسحها.</p></div>
<?php endif; ?>

</div></main></div>
<script>
<?php if ($view_mode==='executive' && $results): ?>
document.addEventListener("DOMContentLoaded",function(){
const FF='Tajawal';
<?php if(!empty($status_cnt)): ?>new ApexCharts(document.querySelector("#chSt"),{series:<?= json_encode(array_values($status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$STATUS_AR[$k]??$k,array_keys($status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:<?= json_encode(array_values($STATUS_COLOR)) ?>,plotOptions:{pie:{donut:{size:'62%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();<?php endif; ?>
<?php if(!empty($priority_cnt)): ?>new ApexCharts(document.querySelector("#chPr"),{series:[{data:<?= json_encode(array_map(fn($k)=>$priority_cnt[$k]??0,array_keys($PRIORITY_AR))) ?>}],chart:{type:'bar',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_values($PRIORITY_AR),JSON_UNESCAPED_UNICODE) ?>},colors:['#0891b2'],plotOptions:{bar:{borderRadius:4,distributed:true}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#chMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>},colors:['#0891b2'],stroke:{curve:'smooth',width:3},fill:{type:'gradient',gradient:{opacityFrom:.6,opacityTo:.05}},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
<?php endif; ?>
</script>
</body></html>