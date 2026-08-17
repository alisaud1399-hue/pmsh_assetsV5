<?php
/**
* reports/inventory/overview.php — مركز تحليل الجرد (Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* • محاور: إجراءات المسح / أداء الأقسام / leaderboard الجامعين / الجلسات / الاستثناءات
* • تصدير ماسي: Excel غني / PDF رسمي موقّع / لوحة مؤشرات A4
* • يكتشف أعمدة inventory_audits تلقائياً — لا ينكسر أبداً
* • نظام التقارير المحفوظة الموحد (module = inventory)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';
page_guard('reports.inventory.overview');

if (isset($_GET['apply_saved'])) {
    sr_apply_saved($pdo, (int)$_GET['apply_saved'], (int)current_user()['id']);
}

$rtl = is_rtl();
$can_export = can('reports.inventory.overview', 'export');
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$SESSION_AR = ['planning'=>'مخططة','active'=>'نشطة','review'=>'مراجعة','completed'=>'مكتملة','cancelled'=>'ملغاة'];
$SESSION_COLOR = ['planning'=>'#0ea5e9','active'=>'#16a34a','review'=>'#f59e0b','completed'=>'#475569','cancelled'=>'#94a3b8'];
$ACTION_AR = ['confirmed'=>'مؤكد','location_changed'=>'تغيّر موقع','custody_changed'=>'تغيّر عهدة','condition_damaged'=>'تالف','missing'=>'مفقود','missing_disposed_previously'=>'مفقود (تخلص سابق)','missing_under_investigation'=>'مفقود (تحقيق)','surplus'=>'زائد (غير مسجّل)','surplus_registered'=>'زائد (تم التسجيل)','reaudit_pending'=>'بانتظار إعادة الجرد'];
$ACTION_COLOR = ['confirmed'=>'#16a34a','location_changed'=>'#0ea5e9','custody_changed'=>'#7c3aed','condition_damaged'=>'#d97706','missing'=>'#dc2626','missing_disposed_previously'=>'#7f1d1d','missing_under_investigation'=>'#f59e0b','surplus'=>'#f59e0b','surplus_registered'=>'#16a34a','reaudit_pending'=>'#7c3aed'];

/* ═══ اكتشاف أعمدة inventory_audits تلقائياً ═══ */
$aud_cols = array_column($pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_audits'")->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
$HAS = function(string $c) use ($aud_cols) { return in_array($c, $aud_cols, true); };
$has_asset = $HAS('asset_id');

/* ═══ الفلاتر ═══ */
$view_mode = $_GET['view'] ?? 'executive';
function valid_date(string $v): string {
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
$f = [
    'session' => (int)($_GET['session'] ?? 0),
    'action'  => trim($_GET['action'] ?? ''),
    'dept'    => (int)($_GET['dept'] ?? 0),
    'from'    => valid_date(trim($_GET['from'] ?? '')),
    'to'      => valid_date(trim($_GET['to'] ?? '')),
];
if ($f['action'] !== '' && !array_key_exists($f['action'], $ACTION_AR)) $f['action'] = '';
$has_filters = array_filter($f) !== [];

$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;

/* ═══ بناء الاستعلام ═══ */
$where = ["1=1"]; $params = [];
if ($f['session']) { $where[] = 'a.session_id = :fsess'; $params['fsess'] = $f['session']; }
if ($f['action'] !== '') { $where[] = 'a.action = :fact'; $params['fact'] = $f['action']; }
if ($f['dept']) { $where[] = 'u.department_id = :fdept'; $params['fdept'] = $f['dept']; }
if ($f['from']) { $where[] = 'DATE(a.audited_at) >= :ffrom'; $params['ffrom'] = $f['from']; }
if ($f['to']) { $where[] = 'DATE(a.audited_at) <= :fto'; $params['fto'] = $f['to']; }

$select = "a.id, a.session_id, a.audited_by, a.audited_at, a.action,
s.session_code, s.title AS session_title, s.status AS session_status,
u.full_name AS auditor_name, d.name AS dept_name";
if ($has_asset) $select .= ", ax.tag_number AS asset_tag, ax.description AS asset_desc, ax.loc_room AS asset_room";
$joins = " LEFT JOIN inventory_sessions s ON s.id = a.session_id
 LEFT JOIN users u ON u.id = a.audited_by
 LEFT JOIN departments d ON d.id = u.department_id";
if ($has_asset) $joins .= " LEFT JOIN assets ax ON ax.id = a.asset_id";

$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 20000;
$sql = "SELECT $select FROM inventory_audits a $joins WHERE " . implode(' AND ', $where) . " ORDER BY a.audited_at DESC LIMIT $row_cap";
$st = $pdo->prepare($sql); $st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);

/* قوائم الفلاتر */
$sessions = $pdo->query("SELECT id, session_code, title FROM inventory_sessions ORDER BY id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
$depts = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ═══ مؤشرات عامة (غير مرتبطة بالفلاتر) ═══ */
$g_sessions = (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions")->fetchColumn();
$g_active = (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions WHERE status IN ('planning','active','review')")->fetchColumn();
$g_reaudit = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reaudit_requests WHERE status='pending'")->fetchColumn();

/* ═══ التجميع الشامل ═══ */
$total_audits = count($results);
$confirmed = 0; $missing = 0; $surplus = 0; $damaged = 0; $changed = 0;
$group_cnt = []; $month_cnt = []; $dept_agg = []; $sess_agg = []; $auditor_agg = []; $exceptions = [];
foreach ($results as $r) {
    $act = $r['action'];
    if ($act === 'confirmed') { $confirmed++; $g = 'confirmed'; }
    elseif (strpos($act, 'missing') === 0) { $missing++; $g = 'missing'; }
    elseif (strpos($act, 'surplus') === 0) { $surplus++; $g = 'surplus'; }
    elseif ($act === 'condition_damaged') { $damaged++; $g = 'damaged'; }
    else { $changed++; $g = 'changed'; }
    $group_cnt[$g] = ($group_cnt[$g] ?? 0) + 1;

    if (!empty($r['audited_at'])) {
        $ym = substr($r['audited_at'], 0, 7);
        $month_cnt[$ym] = ($month_cnt[$ym] ?? 0) + 1;
    }
    $dn = $r['dept_name'] ?: 'بدون قسم';
    if (!isset($dept_agg[$dn])) $dept_agg[$dn] = ['audits'=>0,'confirmed'=>0,'missing'=>0];
    $dept_agg[$dn]['audits']++;
    if ($act === 'confirmed') $dept_agg[$dn]['confirmed']++;
    if (strpos($act, 'missing') === 0) $dept_agg[$dn]['missing']++;

    $sk = $r['session_id'];
    if (!isset($sess_agg[$sk])) $sess_agg[$sk] = ['code'=>$r['session_code']??'—','title'=>$r['session_title']??'—','status'=>$r['session_status']??'','audits'=>0,'confirmed'=>0,'missing'=>0];
    $sess_agg[$sk]['audits']++;
    if ($act === 'confirmed') $sess_agg[$sk]['confirmed']++;
    if (strpos($act, 'missing') === 0) $sess_agg[$sk]['missing']++;

    $an = $r['auditor_name'] ?: 'غير محدد';
    if (!isset($auditor_agg[$an])) $auditor_agg[$an] = ['total'=>0,'confirmed'=>0,'missing'=>0];
    $auditor_agg[$an]['total']++;
    if ($act === 'confirmed') $auditor_agg[$an]['confirmed']++;
    if (strpos($act, 'missing') === 0) $auditor_agg[$an]['missing']++;

    if ((strpos($act, 'missing') === 0 || strpos($act, 'surplus') === 0) && count($exceptions) < 12) {
        $exceptions[] = $r;
    }
}
$match_rate = $total_audits > 0 ? round($confirmed / $total_audits * 100, 1) : 0;
ksort($month_cnt);

$GROUP_AR = ['confirmed'=>'مؤكد','missing'=>'مفقود','surplus'=>'زائد','damaged'=>'تالف','changed'=>'تغيّرات'];
$GROUP_COLOR = ['confirmed'=>'#16a34a','missing'=>'#dc2626','surplus'=>'#f59e0b','damaged'=>'#d97706','changed'=>'#0ea5e9'];

$dept_sorted = [];
foreach ($dept_agg as $n => $v) $dept_sorted[] = ['name'=>$n] + $v + ['rate' => $v['audits']>0 ? round($v['confirmed']/$v['audits']*100) : 0];
usort($dept_sorted, function($a,$b){ return $b['audits'] <=> $a['audits']; });
$dept_sorted = array_slice($dept_sorted, 0, 8);

$sess_sorted = array_values($sess_agg);
usort($sess_sorted, function($a,$b){ return $b['audits'] <=> $a['audits']; });
$sess_sorted = array_slice($sess_sorted, 0, 6);

$auditor_sorted = [];
foreach ($auditor_agg as $n => $v) $auditor_sorted[] = ['name'=>$n] + $v + ['rate' => $v['total']>0 ? round($v['confirmed']/$v['total']*100) : 0];
usort($auditor_sorted, function($a,$b){ return $b['total'] <=> $a['total']; });
$auditor_sorted = array_slice($auditor_sorted, 0, 6);

/* ═══ تنبيهات الذكاء ═══ */
$ai = [];
if ($total_audits > 0 && $match_rate < 90) $ai[] = "⚠️ معدل المطابقة $match_rate% دون الهدف (90%) — راجع الفروقات";
if ($missing > 0) $ai[] = "🔴 $missing أصل مفقود يحتاج تحقيقاً أو إجراء التخلص";
if ($surplus > 0) $ai[] = "🟡 $surplus أصل زائد غير مسجّل — يجب إدراجه بالسجل";
if ($g_reaudit > 0) $ai[] = "🔄 $g_reaudit طلب إعادة جرد معلّق";
$ai_class = empty($ai) ? 'ai-success' : (count($ai) >= 2 ? 'ai-danger' : 'ai-warning');
$ai_icon = empty($ai) ? 'fa-check-circle' : (count($ai) >= 2 ? 'fa-triangle-exclamation' : 'fa-bell');
$ai_msg = empty($ai) ? '✨ مؤشرات الجرد ضمن النطاق الصحي.' : implode(' | ', $ai);

$title_parts = [];
if ($f['session']) { foreach ($sessions as $s) if ((int)$s['id'] === $f['session']) $title_parts[] = $s['session_code']; }
if ($f['action'] !== '') $title_parts[] = $ACTION_AR[$f['action']];
$report_title = 'تقرير الجرد' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* ═══ 1. Excel غني ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Inventory_Register_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:12px}th{background:#134e4a;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:8px;text-align:center}td{border:1px solid #cbd5e1;padding:6px;text-align:center;vertical-align:middle}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="9" style="font-size:16px;background:#0d9488;padding:14px">سجل الجرد التحليلي - <?= e($report_title) ?></th></tr>
<tr><th>الجلسة</th><th>عنوان الجلسة</th><th>الجامع</th><th>القسم</th><th>الإجراء</th><th>Tag الأصل</th><th>وصف الأصل</th><th>الموقع</th><th>تاريخ المسح</th></tr>
</thead><tbody>
<?php foreach ($results as $r): ?>
<tr><td><?= e($r['session_code'] ?? '') ?></td><td><?= e($r['session_title'] ?? '') ?></td><td><?= e($r['auditor_name'] ?? '') ?></td><td><?= e($r['dept_name'] ?? '') ?></td>
<td><?= e($ACTION_AR[$r['action']] ?? $r['action']) ?></td><td><?= e($r['asset_tag'] ?? '') ?></td><td><?= e($r['asset_desc'] ?? '') ?></td><td><?= e($r['asset_room'] ?? '') ?></td><td><?= e($r['audited_at']) ?></td></tr>
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
.print-header{background:linear-gradient(135deg,#f8fafc,#ccfbf1);border:1px solid #cbd5e1;border-radius:10px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.ph-right{display:flex;align-items:center;gap:12px;border-left:1px solid #cbd5e1;padding-left:18px}.ph-h1{font-size:16px;font-weight:800}.ph-h2{font-size:11px;color:#475569}
.ph-logo{height:46px;object-fit:contain}.ph-center{flex:1;text-align:center}.ph-title{font-size:16px;font-weight:800;color:#0d9488}.ph-left{text-align:left;font-size:10px;color:#475569}
.ph-pagebadge{background:#0d9488;color:#fff;padding:3px 10px;border-radius:4px;font-size:9px;font-weight:800;display:inline-block;margin-bottom:4px}
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
<div class="ph-center"><div class="ph-title">سجل الجرد المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pn ?> من <?= $tp ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — السجلات: <strong><?= $total_audits ?></strong></div></div>
</div></th></tr>
<tr><th>#</th><th>الجلسة</th><th>الجامع / القسم</th><th>الإجراء</th><th>الأصل</th><th>الموقع</th><th>تاريخ المسح</th></tr></thead><tbody>
<?php foreach ($pr as $i => $r): $ac = $ACTION_COLOR[$r['action']] ?? '#475569'; ?>
<tr><td style="text-align:center"><?= $i+1 ?></td>
<td><strong><?= e($r['session_code'] ?: '—') ?></strong><br><small><?= e(mb_strimwidth($r['session_title'] ?? '', 0, 30, '...')) ?></small></td>
<td><?= e($r['auditor_name'] ?: '—') ?><br><small><?= e($r['dept_name'] ?: '—') ?></small></td>
<td><span class="p-badge" style="background:<?= $ac ?>"><?= e($ACTION_AR[$r['action']] ?? $r['action']) ?></span></td>
<td><?= e($r['asset_tag'] ?: '—') ?><br><small><?= e(mb_strimwidth($r['asset_desc'] ?? '', 0, 30, '...')) ?></small></td>
<td style="font-size:9px"><?= e($r['asset_room'] ?: '—') ?></td>
<td style="font-size:9px"><?= e($r['audited_at'] ? date('Y-m-d H:i', strtotime($r['audited_at'])) : '—') ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="7" style="border:none;padding:0"><div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">التوقيع</div></div>
<div class="sign-box"><div class="title">مسؤول الجرد</div><div class="line"></div><div class="hint">المراجعة</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد</div></div>
</div></td></tr></tfoot></table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. لوحة A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات الجرد</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:A4 landscape;margin:0}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;margin:0}
.a4{width:297mm;height:209mm;padding:10mm;margin:0 auto;display:flex;flex-direction:column;overflow:hidden}
.hd{background:#134e4a;color:#fff;border-radius:10px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.krow{display:flex;gap:12px;margin-bottom:12px}.kbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center;background:#f8fafc}
.kval{font-size:22px;font-weight:900}.klbl{font-size:11px;font-weight:800;color:#64748b}
.cwrap{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0}.crow{display:flex;gap:12px;flex:1;min-height:0}
.cbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;display:flex;flex-direction:column}.ct{font-size:12px;font-weight:900;text-align:center;margin-bottom:4px}.ca{flex:1;min-height:0}
.ft{text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;border-top:1px dashed #cbd5e1;padding-top:4px}
</style></head><body onload="setTimeout(()=>window.print(),1500)">
<div class="a4">
<div class="hd"><div style="font-size:18px;font-weight:900"><?= e($hospital) ?></div><div style="font-size:16px;font-weight:900;color:#5eead4"><?= e($report_title) ?></div><div style="font-size:11px"><?= date('Y-m-d') ?></div></div>
<div class="krow">
<div class="kbox"><div class="kval"><?= $g_sessions ?></div><div class="klbl">جلسات</div></div>
<div class="kbox"><div class="kval"><?= number_format($total_audits) ?></div><div class="klbl">عمليات مسح</div></div>
<div class="kbox"><div class="kval" style="color:<?= $match_rate>=90?'#16a34a':'#dc2626' ?>"><?= $match_rate ?>%</div><div class="klbl">معدل المطابقة</div></div>
<div class="kbox"><div class="kval" style="color:#dc2626"><?= $missing ?></div><div class="klbl">مفقود</div></div>
<div class="kbox"><div class="kval" style="color:#f59e0b"><?= $surplus ?></div><div class="klbl">زائد</div></div>
</div>
<div class="cwrap">
<div class="crow">
<div class="cbox" style="flex:1.2"><div class="ct">توزيع الإجراءات</div><div class="ca" id="pAct"></div></div>
<div class="cbox"><div class="ct">معدل المطابقة</div><div class="ca" id="pMatch"></div></div>
</div>
<div class="crow">
<div class="cbox"><div class="ct">أداء الأقسام (مطابقة %)</div><div class="ca" id="pDept"></div></div>
<div class="cbox" style="flex:1.2"><div class="ct">اتجاه المسحات الشهري</div><div class="ca" id="pMo"></div></div>
</div>
</div>
<div class="ft">وثيقة تحليلية | <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded",function(){
<?php if(!empty($group_cnt)): ?>new ApexCharts(document.querySelector("#pAct"),{series:<?= json_encode(array_values($group_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$GROUP_AR[$k]??$k,array_keys($group_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:<?= json_encode(array_values($GROUP_COLOR)) ?>,plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();<?php endif; ?>
new ApexCharts(document.querySelector("#pMatch"),{series:[<?= $match_rate ?>],chart:{type:'radialBar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},plotOptions:{radialBar:{hollow:{size:'65%'},dataLabels:{show:true,name:{show:false},value:{offsetY:8,fontSize:'26px',fontWeight:900,color:'<?= $match_rate>=90?'#16a34a':'#dc2626' ?>',formatter:v=>v+'%'}}}},fill:{colors:['<?= $match_rate>=90?'#16a34a':'#dc2626' ?>']}}).render();
<?php if(!empty($dept_sorted)): ?>new ApexCharts(document.querySelector("#pDept"),{series:[{data:<?= json_encode(array_column($dept_sorted,'rate')) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_column($dept_sorted,'name'),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'9px'}}},colors:['#0d9488'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#pMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#0d9488'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
</script></body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مركز تحليل الجرد — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--primary:#0d9488;--bg:#f8fafc;--border:#e2e8f0;--tm:#0f172a;--t2:#475569;--t3:#94a3b8;--radius:16px}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--tm)}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.view-toggles{display:flex;gap:10px;margin-bottom:16px;background:#fff;padding:6px;border-radius:99px;width:fit-content;border:1px solid var(--border)}
.toggle-btn{padding:10px 24px;border-radius:99px;font-size:13.5px;font-weight:800;color:var(--t2);text-decoration:none;display:flex;align-items:center;gap:8px}
.toggle-btn.active{background:var(--primary);color:#fff}
.header-hero{background:linear-gradient(135deg,#134e4a,#0d9488);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center}
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
.axis-h{padding:14px 18px;font-weight:900;font-size:15px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border);background:linear-gradient(90deg,#f0fdfa,#fff)}
.axis-h i{color:var(--primary)}
.axis-body{padding:16px 18px}
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{background:#f8fafc;padding:8px 10px;text-align:right;font-size:10.5px;font-weight:900;color:var(--t2);border-bottom:2px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
.tbl tr:hover td{background:#f0fdfa}
.badge{display:inline-flex;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:800;gap:4px;align-items:center;color:#fff}
.bar-bg{height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-top:4px}.bar-bg>div{height:100%;border-radius:99px}
.empty{text-align:center;padding:50px;color:var(--t3);background:#fff;border-radius:var(--radius);border:1px solid var(--border)}
</style></head>
<body class="app-layout">
<?php $__f_backup = $f ?? []; include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH . '/includes/topbar.php'; $f = $__f_backup; ?>
<main class="page-content"><div class="wrap">

<div class="view-toggles">
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة تحليل الجرد</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> سجل المسح التفصيلي</a>
</div>

<div class="header-hero">
<div><h1 style="font-size:20px;font-weight:900;margin:0"><i class="fa-solid fa-clipboard-list" style="margin-left:8px;color:#5eead4"></i> مركز تحليل الجرد</h1>
<div style="color:#ccfbf1;font-size:13px;margin-top:4px">جلسات، عمليات مسح، مطابقة، مفقود/زائد، أداء الأقسام والجامعين</div></div>
<div style="text-align:left;font-size:11px;color:#ccfbf1">تاريخ التقرير<br><strong style="font-size:15px;color:#fff"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($results): ?><div class="ai-banner <?= $ai_class ?>"><i class="fa-solid <?= $ai_icon ?>"></i><span><?= e($ai_msg) ?></span></div><?php endif; ?>

<?php
$sr_module = 'inventory'; $sr_filters = $f; $sr_view = $view_mode; $sr_base_url = BASE_URL;
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:#ccfbf1;padding:6px;border-radius:6px"></i> فلاتر الدراسة <i class="fa-solid fa-chevron-down" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>الجلسة</label><select name="session"><option value="">— الكل —</option><?php foreach($sessions as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $f['session']===(int)$s['id']?'selected':'' ?>><?= e($s['session_code']) ?> — <?= e(mb_strimwidth($s['title'],0,30,'...')) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>الإجراء</label><select name="action"><option value="">— الكل —</option><?php foreach($ACTION_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['action']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
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
<div class="kpi-card"><div class="kpi-icon" style="background:#ccfbf1;color:#0d9488"><i class="fa-solid fa-calendar-check"></i></div><div><div class="kpi-title">جلسات</div><div class="kpi-val"><?= $g_sessions ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-barcode"></i></div><div><div class="kpi-title">عمليات مسح</div><div class="kpi-val"><?= number_format($total_audits) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:<?= $match_rate>=90?'#dcfce7':'#fee2e2' ?>;color:<?= $match_rate>=90?'#16a34a':'#dc2626' ?>"><i class="fa-solid fa-circle-check"></i></div><div><div class="kpi-title">معدل المطابقة</div><div class="kpi-val"><?= $match_rate ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-circle-xmark"></i></div><div><div class="kpi-title">مفقود</div><div class="kpi-val"><?= $missing ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-plus"></i></div><div><div class="kpi-title">زائد</div><div class="kpi-val"><?= $surplus ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-rotate"></i></div><div><div class="kpi-title">إعادة جرد معلّقة</div><div class="kpi-val"><?= $g_reaudit ?></div></div></div>
</div>

<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> توزيع الإجراءات</div><div id="chAct" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#0d9488"></i> اتجاه المسحات الشهري</div><div id="chMo" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-gauge-high" style="color:#16a34a"></i> معدل المطابقة</div><div id="chMatch" style="min-height:220px;display:flex;align-items:center;justify-content:center"></div></div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-building"></i> أداء الأقسام</div>
<div class="axis-body" style="padding:0">
<table class="tbl"><thead><tr><th>القسم</th><th>عمليات</th><th>مؤكد</th><th>مفقود</th><th>مطابقة</th></tr></thead><tbody>
<?php foreach ($dept_sorted as $d): $rc = $d['rate']>=90?'#16a34a':($d['rate']>=70?'#d97706':'#dc2626'); ?>
<tr><td style="font-weight:800"><?= e($d['name']) ?></td><td><?= $d['audits'] ?></td><td><?= $d['confirmed'] ?></td><td><?= $d['missing'] ?></td>
<td><strong style="color:<?= $rc ?>"><?= $d['rate'] ?>%</strong><div class="bar-bg"><div style="width:<?= $d['rate'] ?>%;background:<?= $rc ?>"></div></div></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-user-shield"></i> لوحة الجامعين (Leaderboard)</div>
<div class="axis-body" style="padding:0">
<table class="tbl"><thead><tr><th>#</th><th>الجامع</th><th>عمليات</th><th>مؤكد</th><th>مفقود</th><th>مطابقة</th></tr></thead><tbody>
<?php foreach ($auditor_sorted as $i => $a): $rc = $a['rate']>=90?'#16a34a':($a['rate']>=70?'#d97706':'#dc2626'); ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td><td style="font-weight:800"><?= e($a['name']) ?></td><td><?= $a['total'] ?></td><td><?= $a['confirmed'] ?></td><td><?= $a['missing'] ?></td><td><strong style="color:<?= $rc ?>"><?= $a['rate'] ?>%</strong></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
</div>

<?php if ($exceptions): ?>
<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-triangle-exclamation"></i> الاستثناءات (مفقود / زائد)</div>
<div class="axis-body" style="padding:0">
<table class="tbl"><thead><tr><th>الإجراء</th><th>الأصل</th><th>الجلسة</th><th>الجامع</th><th>التاريخ</th></tr></thead><tbody>
<?php foreach ($exceptions as $r): $ac = $ACTION_COLOR[$r['action']] ?? '#475569'; ?>
<tr><td><span class="badge" style="background:<?= $ac ?>"><?= e($ACTION_AR[$r['action']] ?? $r['action']) ?></span></td>
<td><div style="font-weight:800;color:#c2410c"><?= e($r['asset_tag'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($r['asset_desc'] ?? '', 0, 40, '...')) ?></div></td>
<td style="font-size:11px"><?= e($r['session_code'] ?: '—') ?></td><td style="font-size:11px"><?= e($r['auditor_name'] ?: '—') ?></td>
<td style="font-size:11px"><?= e($r['audited_at'] ? date('Y-m-d', strtotime($r['audited_at'])) : '—') ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
</div>
<?php endif; ?>

<?php else: ?>
<div style="margin-bottom:12px;font-weight:900">السجلات: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px"><?= $total_audits ?></span></div>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow-x:auto">
<table class="tbl"><thead><tr><th>#</th><th>الجلسة</th><th>الجامع / القسم</th><th>الإجراء</th><th>الأصل</th><th>الموقع</th><th>التاريخ</th></tr></thead><tbody>
<?php foreach (array_slice($results, 0, 500) as $i => $r): $ac = $ACTION_COLOR[$r['action']] ?? '#475569'; ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td>
<td><div style="font-weight:800"><?= e($r['session_code'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($r['session_title'] ?? '', 0, 30, '...')) ?></div></td>
<td style="font-size:11px"><?= e($r['auditor_name'] ?: '—') ?><br><?= e($r['dept_name'] ?: '—') ?></td>
<td><span class="badge" style="background:<?= $ac ?>"><?= e($ACTION_AR[$r['action']] ?? $r['action']) ?></span></td>
<td><div style="font-weight:800;color:#c2410c"><?= e($r['asset_tag'] ?: '—') ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($r['asset_desc'] ?? '', 0, 30, '...')) ?></div></td>
<td style="font-size:11px"><?= e($r['asset_room'] ?: '—') ?></td>
<td style="font-size:11px"><?= e($r['audited_at'] ? date('Y-m-d H:i', strtotime($r['audited_at'])) : '—') ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty"><i class="fa-solid fa-clipboard-list" style="font-size:44px;color:var(--primary);display:block;margin-bottom:10px"></i><h3>لا توجد عمليات مسح مطابقة</h3><p>عدّل الفلاتر أو امسحها.</p></div>
<?php endif; ?>

</div></main></div>
<script>
<?php if ($view_mode==='executive' && $results): ?>
document.addEventListener("DOMContentLoaded",function(){
const FF='Tajawal';
<?php if(!empty($group_cnt)): ?>new ApexCharts(document.querySelector("#chAct"),{series:<?= json_encode(array_values($group_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$GROUP_AR[$k]??$k,array_keys($group_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:<?= json_encode(array_values($GROUP_COLOR)) ?>,plotOptions:{pie:{donut:{size:'62%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#chMo"),{series:[{name:'مسحات',data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>},colors:['#0d9488'],stroke:{curve:'smooth',width:3},fill:{type:'gradient',gradient:{opacityFrom:.6,opacityTo:.05}},dataLabels:{enabled:false}}).render();<?php endif; ?>
new ApexCharts(document.querySelector("#chMatch"),{series:[<?= $match_rate ?>],chart:{type:'radialBar',height:'120%',fontFamily:FF},plotOptions:{radialBar:{hollow:{size:'65%'},track:{background:'#f1f5f9',strokeWidth:'100%'},dataLabels:{show:true,name:{offsetY:20,show:true,color:'#64748b',fontSize:'12px',fontWeight:700},value:{offsetY:-10,color:'<?= $match_rate>=90?'#16a34a':'#dc2626' ?>',fontSize:'26px',fontWeight:900,formatter:v=>v+'%'}}}},fill:{colors:['<?= $match_rate>=90?'#16a34a':'#dc2626' ?>']},stroke:{lineCap:'round'},labels:['مطابقة']}).render();
});
<?php endif; ?>
</script>
</body></html>