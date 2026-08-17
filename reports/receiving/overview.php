<?php
/**
* reports/receiving/overview.php — مركز تحليل الاستلام والتشغيل (Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* • محاور: أوامر الشراء / محاضر الاستلام / شهادات التشغيل / الموردين / الأقسام / الاتجاه
* • تحليل فجوة الاستلام→التشغيل (Lead Time)
* • تصدير ماسي: Excel غني / PDF رسمي موقّع / لوحة مؤشرات A4
* • نظام التقارير المحفوظة الموحد (module = receiving)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';
page_guard('reports.receiving.overview');

if (isset($_GET['apply_saved'])) {
    sr_apply_saved($pdo, (int)$_GET['apply_saved'], (int)current_user()['id']);
}

$rtl = is_rtl();
$can_export = can('reports.receiving.overview', 'export');
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$PO_STATUS_AR = ['draft'=>'مسودة','pending'=>'معلّق','approved'=>'معتمد','rejected'=>'مرفوض','completed'=>'مكتمل','cancelled'=>'ملغى'];
$PO_STATUS_COLOR = ['draft'=>'#94a3b8','pending'=>'#f59e0b','approved'=>'#0ea5e9','rejected'=>'#dc2626','completed'=>'#16a34a','cancelled'=>'#475569'];
$RM_STATUS_AR = ['draft'=>'مسودة','sent_to_supplier'=>'مرسلة للمورد','approved'=>'معتمدة','rejected'=>'مرفوضة','cancelled'=>'ملغاة'];
$RM_STATUS_COLOR = ['draft'=>'#94a3b8','sent_to_supplier'=>'#0ea5e9','approved'=>'#16a34a','rejected'=>'#dc2626','cancelled'=>'#475569'];
$CC_STATUS_AR = ['draft'=>'مسودة','sent_to_supplier'=>'مرسلة','approved'=>'معتمدة','rejected'=>'مرفوضة'];
$CC_STATUS_COLOR = ['draft'=>'#94a3b8','sent_to_supplier'=>'#0ea5e9','approved'=>'#16a34a','rejected'=>'#dc2626'];

/* ═══ الفلاتر ═══ */
$view_mode = $_GET['view'] ?? 'executive';
function valid_date(string $v): string {
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
$f = [
    'po_status' => trim($_GET['po_status'] ?? ''),
    'rm_status' => trim($_GET['rm_status'] ?? ''),
    'supplier'  => trim($_GET['supplier'] ?? ''),
    'from'      => valid_date(trim($_GET['from'] ?? '')),
    'to'        => valid_date(trim($_GET['to'] ?? '')),
    'q'         => trim($_GET['q'] ?? ''),
];
if ($f['po_status'] !== '' && !array_key_exists($f['po_status'], $PO_STATUS_AR)) $f['po_status'] = '';
if ($f['rm_status'] !== '' && !array_key_exists($f['rm_status'], $RM_STATUS_AR)) $f['rm_status'] = '';
$has_filters = array_filter($f) !== [];

$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;

/* ═══ استعلامات شاملة (مستقلة عن الفلاتر للكشف العام) ═══ */
$g_po = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
$g_po_completed = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='completed'")->fetchColumn();
$g_po_value = (float)$pdo->query("SELECT COALESCE(SUM(total_value),0) FROM purchase_orders WHERE status='completed'")->fetchColumn();
$g_rm = (int)$pdo->query("SELECT COUNT(*) FROM receiving_minutes")->fetchColumn();
$g_rm_approved = (int)$pdo->query("SELECT COUNT(*) FROM receiving_minutes WHERE status='approved'")->fetchColumn();
$g_cc = (int)$pdo->query("SELECT COUNT(*) FROM commissioning_certificates")->fetchColumn();
$g_cc_approved = (int)$pdo->query("SELECT COUNT(*) FROM commissioning_certificates WHERE status='approved'")->fetchColumn();
$g_cc_transferred = (int)$pdo->query("SELECT COUNT(*) FROM commissioning_certificates WHERE status='approved' AND transferred_at IS NOT NULL")->fetchColumn();

/* ═══ بناء استعلام محاضر الاستلام (مع الفلاتر) ═══ */
$where = ["1=1"]; $params = [];
if ($f['rm_status'] !== '') { $where[] = 'm.status = :frm'; $params['frm'] = $f['rm_status']; }
if ($f['supplier'] !== '') { $where[] = 'm.supplier_name LIKE :fsup'; $params['fsup'] = '%' . $f['supplier'] . '%'; }
if ($f['from']) { $where[] = 'DATE(m.receipt_date) >= :ffrom'; $params['ffrom'] = $f['from']; }
if ($f['to']) { $where[] = 'DATE(m.receipt_date) <= :fto'; $params['fto'] = $f['to']; }
if ($f['q'] !== '') {
    $where[] = "(m.minute_number LIKE :q OR m.supplier_name LIKE :q OR m.doc_number LIKE :q)";
    $params['q'] = '%' . $f['q'] . '%';
}
$sql = "SELECT m.id, m.minute_number, m.supplier_name, m.doc_type, m.doc_number, m.receipt_date, m.total_value, m.status, m.created_at
        FROM receiving_minutes m WHERE " . implode(' AND ', $where) . " ORDER BY m.id DESC LIMIT 5000";
$st = $pdo->prepare($sql); $st->execute($params);
$rm_results = $st->fetchAll(PDO::FETCH_ASSOC);

/* استعلام أوامر الشراء (مع فلاتر) */
$po_where = ["1=1"]; $po_params = [];
if ($f['po_status'] !== '') { $po_where[] = 'po.status = :fpo'; $po_params['fpo'] = $f['po_status']; }
if ($f['supplier'] !== '') { $po_where[] = 'po.supplier_name LIKE :fsup'; $po_params['fsup'] = '%' . $f['supplier'] . '%'; }
if ($f['from']) { $po_where[] = 'DATE(po.po_date) >= :ffrom'; $po_params['ffrom'] = $f['from']; }
if ($f['to']) { $po_where[] = 'DATE(po.po_date) <= :fto'; $po_params['fto'] = $f['to']; }
if ($f['q'] !== '') {
    $po_where[] = "(po.po_number LIKE :q OR po.supplier_name LIKE :q)";
    $po_params['q'] = '%' . $f['q'] . '%';
}
$sql = "SELECT po.id, po.po_number, po.po_date, po.supplier_name, po.total_value, po.status, po.created_at
        FROM purchase_orders po WHERE " . implode(' AND ', $po_where) . " ORDER BY po.id DESC LIMIT 5000";
$st = $pdo->prepare($sql); $st->execute($po_params);
$po_results = $st->fetchAll(PDO::FETCH_ASSOC);

/* ═══ تحليل شهادات التشغيل (Lead Time + الضمان) ═══ */
$cc_rows = $pdo->query("SELECT id, certificate_number, receiving_minute_id, supplier_name, date_gregorian, status, warranty_start, warranty_end, transferred_at, created_at
                        FROM commissioning_certificates ORDER BY id DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC);

/* ═══ التجميع الشامل ═══ */
$total_po = count($po_results);
$total_rm = count($rm_results);
$po_value_sum = 0; $rm_value_sum = 0; $po_status_cnt = []; $rm_status_cnt = []; $month_po = []; $month_rm = [];
$sup_agg = []; $lead_times = [];
$warranty_expired = 0; $warranty_soon = 0;
foreach ($po_results as $r) {
    $v = (float)$r['total_value'];
    $po_value_sum += $v;
    $po_status_cnt[$r['status']] = ($po_status_cnt[$r['status']] ?? 0) + 1;
    if (!empty($r['po_date'])) {
        $ym = substr($r['po_date'], 0, 7);
        $month_po[$ym] = ($month_po[$ym] ?? 0) + 1;
    }
    $sn = $r['supplier_name'] ?: 'غير محدد';
    if (!isset($sup_agg[$sn])) $sup_agg[$sn] = ['po'=>0,'rm'=>0,'value'=>0];
    $sup_agg[$sn]['po']++;
    $sup_agg[$sn]['value'] += $v;
}
foreach ($rm_results as $r) {
    $v = (float)$r['total_value'];
    $rm_value_sum += $v;
    $rm_status_cnt[$r['status']] = ($rm_status_cnt[$r['status']] ?? 0) + 1;
    if (!empty($r['receipt_date'])) {
        $ym = substr($r['receipt_date'], 0, 7);
        $month_rm[$ym] = ($month_rm[$ym] ?? 0) + 1;
    }
    $sn = $r['supplier_name'] ?: 'غير محدد';
    if (!isset($sup_agg[$sn])) $sup_agg[$sn] = ['po'=>0,'rm'=>0,'value'=>0];
    $sup_agg[$sn]['rm']++;
    $sup_agg[$sn]['value'] += $v;
}
ksort($month_po); ksort($month_rm);

foreach ($cc_rows as $r) {
    if ($r['receiving_minute_id'] && $r['date_gregorian']) {
        // ابحث عن تاريخ الاستلام المطابق
        foreach ($rm_results as $rm) {
            if ($rm['id'] == $r['receiving_minute_id'] && !empty($rm['receipt_date'])) {
                $lead = (strtotime($r['date_gregorian']) - strtotime($rm['receipt_date'])) / 86400;
                if ($lead >= 0) $lead_times[] = $lead;
                break;
            }
        }
    }
    if ($r['warranty_end']) {
        $diff = (strtotime($r['warranty_end']) - time()) / 86400;
        if ($diff < 0) $warranty_expired++;
        elseif ($diff <= 90) $warranty_soon++;
    }
}
$avg_lead = $lead_times ? round(array_sum($lead_times) / count($lead_times), 1) : 0;
$completion_rate = $g_po > 0 ? round($g_po_completed / $g_po * 100, 1) : 0;
$commissioning_rate = $g_rm_approved > 0 ? round($g_cc_approved / $g_rm_approved * 100, 1) : 0;

$sup_sorted = [];
foreach ($sup_agg as $n => $v) $sup_sorted[] = ['name'=>$n] + $v;
usort($sup_sorted, function($a,$b){ return $b['value'] <=> $a['value']; });
$sup_sorted = array_slice($sup_sorted, 0, 8);

/* ═══ تنبيهات الذكاء ═══ */
$ai = [];
if ($avg_lead > 30) $ai[] = "⏱️ Lead Time مرتفع ($avg_lead يوم) — بطء في إجراءات التشغيل";
if ($warranty_expired > 0) $ai[] = "🔴 $warranty_expired شهادة تشغيل بضمان منتهٍ — فحص عاجل";
if ($warranty_soon > 0) $ai[] = "🟡 $warranty_soon شهادة بضمان ينتهي خلال 90 يوماً";
if ($commissioning_rate < 70) $ai[] = "📉 نسبة التشغيل $commissioning_rate% دون 70% — أصول مُستلَمة غير مُشغَّلة";
$ai_class = empty($ai) ? 'ai-success' : (count($ai) >= 2 ? 'ai-danger' : 'ai-warning');
$ai_icon = empty($ai) ? 'fa-check-circle' : (count($ai) >= 2 ? 'fa-triangle-exclamation' : 'fa-bell');
$ai_msg = empty($ai) ? '✨ مؤشرات الاستلام والتشغيل ضمن النطاق الصحي.' : implode(' | ', $ai);

$title_parts = [];
if ($f['supplier']) $title_parts[] = $f['supplier'];
$report_title = 'تقرير الاستلام والتشغيل' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* ═══ 1. Excel غني ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Receiving_Register_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:12px}th{background:#713f12;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:8px;text-align:center}td{border:1px solid #cbd5e1;padding:6px;text-align:center;vertical-align:middle}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="7" style="font-size:16px;background:#a16207;padding:14px">سجل الاستلام والتشغيل - <?= e($report_title) ?></th></tr>
<tr><th>النوع</th><th>الرقم</th><th>المورد</th><th>تاريخ الاستلام/الأمر</th><th>الحالة</th><th>القيمة (ر.س)</th><th>نوع المستند</th></tr>
</thead><tbody>
<?php foreach ($po_results as $r): ?>
<tr><td>أمر شراء</td><td><?= e($r['po_number']) ?></td><td><?= e($r['supplier_name']) ?></td><td><?= e($r['po_date']) ?></td><td><?= e($PO_STATUS_AR[$r['status']] ?? $r['status']) ?></td><td><?= e(number_format((float)$r['total_value'],0)) ?></td><td>—</td></tr>
<?php endforeach; ?>
<?php foreach ($rm_results as $r): ?>
<tr><td>محضر استلام</td><td><?= e($r['minute_number']) ?></td><td><?= e($r['supplier_name']) ?></td><td><?= e($r['receipt_date']) ?></td><td><?= e($RM_STATUS_AR[$r['status']] ?? $r['status']) ?></td><td><?= e(number_format((float)$r['total_value'],0)) ?></td><td><?= e($r['doc_type'] ?? '') ?></td></tr>
<?php endforeach; ?>
</tbody></table></body></html>
<?php exit;
}

/* ═══ 2. PDF رسمي ═══ */
if ($print_mode) {
    $disp = array_merge(
        array_map(fn($r)=>['kind'=>'أمر شراء']+$r, $po_results),
        array_map(fn($r)=>['kind'=>'محضر استلام']+$r, $rm_results)
    );
    $disp = array_slice($disp, 0, 1000);
    $ROWS = 10; $pages = array_chunk($disp, $ROWS, true); $tp = max(1, count($pages));
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>الوثيقة الرسمية - <?= e($report_title) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:landscape;margin:12mm 10mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;color:#1e293b;margin:0}
.print-page{page-break-after:always}.print-page:last-child{page-break-after:auto}
.print-header{background:linear-gradient(135deg,#f8fafc,#fef3c7);border:1px solid #cbd5e1;border-radius:10px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.ph-right{display:flex;align-items:center;gap:12px;border-left:1px solid #cbd5e1;padding-left:18px}.ph-h1{font-size:16px;font-weight:800}.ph-h2{font-size:11px;color:#475569}
.ph-logo{height:46px;object-fit:contain}.ph-center{flex:1;text-align:center}.ph-title{font-size:16px;font-weight:800;color:#a16207}.ph-left{text-align:left;font-size:10px;color:#475569}
.ph-pagebadge{background:#a16207;color:#fff;padding:3px 10px;border-radius:4px;font-size:9px;font-weight:800;display:inline-block;margin-bottom:4px}
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
<tr><th colspan="6" style="padding:0;border:none;background:none"><div class="print-header">
<div class="ph-right"><?php if($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title">سجل الاستلام والتشغيل المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pn ?> من <?= $tp ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong></div></div>
</div></th></tr>
<tr><th>#</th><th>النوع</th><th>الرقم / المورد</th><th>التاريخ</th><th>الحالة</th><th>القيمة</th></tr></thead><tbody>
<?php foreach ($pr as $i => $r):
$sc = $r['kind']==='أمر شراء' ? ($PO_STATUS_COLOR[$r['status']] ?? '#475569') : ($RM_STATUS_COLOR[$r['status']] ?? '#475569');
$ar = $r['kind']==='أمر شراء' ? $PO_STATUS_AR : $RM_STATUS_AR;
$date = $r['kind']==='أمر شراء' ? $r['po_date'] : $r['receipt_date'];
$num = $r['kind']==='أمر شراء' ? $r['po_number'] : $r['minute_number'];
?>
<tr><td style="text-align:center"><?= $i+1 ?></td>
<td><span class="p-badge" style="background:<?= $r['kind']==='أمر شراء'?'#a16207':'#0ea5e9' ?>"><?= e($r['kind']) ?></span></td>
<td><strong><?= e($num) ?></strong><br><small><?= e($r['supplier_name'] ?? '') ?></small></td>
<td style="font-size:9px"><?= e($date ? date('Y-m-d', strtotime($date)) : '—') ?></td>
<td><span class="p-badge" style="background:<?= $sc ?>"><?= e($ar[$r['status']] ?? $r['status']) ?></span></td>
<td style="font-family:monospace"><?= e(number_format((float)$r['total_value'],0)) ?></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="6" style="border:none;padding:0"><div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">التوقيع</div></div>
<div class="sign-box"><div class="title">مسؤول الاستلام</div><div class="line"></div><div class="hint">المراجعة</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد</div></div>
</div></td></tr></tfoot></table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. لوحة A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات الاستلام والتشغيل</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:A4 landscape;margin:0}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;margin:0}
.a4{width:297mm;height:209mm;padding:10mm;margin:0 auto;display:flex;flex-direction:column;overflow:hidden}
.hd{background:#713f12;color:#fff;border-radius:10px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.krow{display:flex;gap:12px;margin-bottom:12px}.kbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center;background:#f8fafc}
.kval{font-size:22px;font-weight:900}.klbl{font-size:11px;font-weight:800;color:#64748b}
.cwrap{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0}.crow{display:flex;gap:12px;flex:1;min-height:0}
.cbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;display:flex;flex-direction:column}.ct{font-size:12px;font-weight:900;text-align:center;margin-bottom:4px}.ca{flex:1;min-height:0}
.ft{text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;border-top:1px dashed #cbd5e1;padding-top:4px}
</style></head><body onload="setTimeout(()=>window.print(),1500)">
<div class="a4">
<div class="hd"><div style="font-size:18px;font-weight:900"><?= e($hospital) ?></div><div style="font-size:16px;font-weight:900;color:#fcd34d"><?= e($report_title) ?></div><div style="font-size:11px"><?= date('Y-m-d') ?></div></div>
<div class="krow">
<div class="kbox"><div class="kval"><?= number_format($g_po) ?></div><div class="klbl">أوامر شراء</div></div>
<div class="kbox"><div class="kval"><?= number_format($g_rm) ?></div><div class="klbl">محاضر استلام</div></div>
<div class="kbox"><div class="kval"><?= number_format($g_cc) ?></div><div class="klbl">شهادات تشغيل</div></div>
<div class="kbox"><div class="kval" style="color:#16a34a"><?= $completion_rate ?>%</div><div class="klbl">إكمال الأوامر</div></div>
<div class="kbox"><div class="kval" style="color:#d97706"><?= $avg_lead ?> يوم</div><div class="klbl">Lead Time</div></div>
</div>
<div class="cwrap">
<div class="crow">
<div class="cbox" style="flex:1.2"><div class="ct">حالات أوامر الشراء</div><div class="ca" id="pPO"></div></div>
<div class="cbox"><div class="ct">حالات محاضر الاستلام</div><div class="ca" id="pRM"></div></div>
</div>
<div class="crow">
<div class="cbox"><div class="ct">اتجاه أوامر الشراء</div><div class="ca" id="pMoPO"></div></div>
<div class="cbox" style="flex:1.2"><div class="ct">اتجاه محاضر الاستلام</div><div class="ca" id="pMoRM"></div></div>
</div>
</div>
<div class="ft">وثيقة تحليلية | <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded",function(){
<?php if(!empty($po_status_cnt)): ?>new ApexCharts(document.querySelector("#pPO"),{series:<?= json_encode(array_values($po_status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$PO_STATUS_AR[$k]??$k,array_keys($po_status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:<?= json_encode(array_values($po_status_cnt)) ? json_encode(array_map(fn($k)=>$PO_STATUS_COLOR[$k]??'#475569',array_keys($po_status_cnt))) : '[]' ?>,plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();<?php endif; ?>
<?php if(!empty($rm_status_cnt)): ?>new ApexCharts(document.querySelector("#pRM"),{series:<?= json_encode(array_values($rm_status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$RM_STATUS_AR[$k]??$k,array_keys($rm_status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:<?= json_encode(array_map(fn($k)=>$RM_STATUS_COLOR[$k]??'#475569',array_keys($rm_status_cnt))) ?>,plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();<?php endif; ?>
<?php if(!empty($month_po)): ?>new ApexCharts(document.querySelector("#pMoPO"),{series:[{data:<?= json_encode(array_values($month_po)) ?>}],chart:{type:'area',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($month_po)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#a16207'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:false}}).render();<?php endif; ?>
<?php if(!empty($month_rm)): ?>new ApexCharts(document.querySelector("#pMoRM"),{series:[{data:<?= json_encode(array_values($month_rm)) ?>}],chart:{type:'area',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($month_rm)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#0ea5e9'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
</script></body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مركز تحليل الاستلام والتشغيل — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--primary:#a16207;--bg:#f8fafc;--border:#e2e8f0;--tm:#0f172a;--t2:#475569;--t3:#94a3b8;--radius:16px}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--tm)}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.view-toggles{display:flex;gap:10px;margin-bottom:16px;background:#fff;padding:6px;border-radius:99px;width:fit-content;border:1px solid var(--border)}
.toggle-btn{padding:10px 24px;border-radius:99px;font-size:13.5px;font-weight:800;color:var(--t2);text-decoration:none;display:flex;align-items:center;gap:8px}
.toggle-btn.active{background:var(--primary);color:#fff}
.header-hero{background:linear-gradient(135deg,#422006,#713f12,#a16207);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center}
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
.axis-h{padding:14px 18px;font-weight:900;font-size:15px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border);background:linear-gradient(90deg,#fef3c7,#fff)}
.axis-h i{color:var(--primary)}
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{background:#f8fafc;padding:8px 10px;text-align:right;font-size:10.5px;font-weight:900;color:var(--t2);border-bottom:2px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
.tbl tr:hover td{background:#fef3c7}
.badge{display:inline-flex;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:800;gap:4px;align-items:center;color:#fff}
.bar-bg{height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;margin-top:4px}.bar-bg>div{height:100%;border-radius:99px}
.empty{text-align:center;padding:50px;color:var(--t3);background:#fff;border-radius:var(--radius);border:1px solid var(--border)}
</style></head>
<body class="app-layout">
<?php $__f_backup = $f ?? []; include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH . '/includes/topbar.php'; $f = $__f_backup; ?>
<main class="page-content"><div class="wrap">

<div class="view-toggles">
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة تحليل الاستلام والتشغيل</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> سجل تفصيلي</a>
</div>

<div class="header-hero">
<div><h1 style="font-size:20px;font-weight:900;margin:0"><i class="fa-solid fa-truck-ramp-box" style="margin-left:8px;color:#fcd34d"></i> مركز تحليل الاستلام والتشغيل</h1>
<div style="color:#fef3c7;font-size:13px;margin-top:4px">أوامر الشراء ← محاضر الاستلام ← شهادات التشغيل ← نقل الأصول</div></div>
<div style="text-align:left;font-size:11px;color:#fef3c7">تاريخ التقرير<br><strong style="font-size:15px;color:#fff"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($po_results || $rm_results): ?><div class="ai-banner <?= $ai_class ?>"><i class="fa-solid <?= $ai_icon ?>"></i><span><?= e($ai_msg) ?></span></div><?php endif; ?>

<?php
$sr_module = 'receiving'; $sr_filters = $f; $sr_view = $view_mode; $sr_base_url = BASE_URL;
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:#fef3c7;padding:6px;border-radius:6px"></i> فلاتر الدراسة <i class="fa-solid fa-chevron-down" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>بحث (رقم/مورد)</label><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="ابحث..."></div>
<div class="fld"><label>حالة أمر الشراء</label><select name="po_status"><option value="">— الكل —</option><?php foreach($PO_STATUS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['po_status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>حالة محضر الاستلام</label><select name="rm_status"><option value="">— الكل —</option><?php foreach($RM_STATUS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['rm_status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>المورد</label><input type="text" name="supplier" value="<?= e($f['supplier']) ?>" placeholder="اسم المورد..."></div>
<div class="fld"><label>من</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
<div class="fld"><label>إلى</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
</div>
</details>
<div class="act-bar">
<div style="display:flex;gap:10px;flex-wrap:wrap">
<button type="submit" class="btn-apply"><i class="fa-solid fa-bolt"></i> تحديث الدراسة</button>
<a href="?view=<?= e($view_mode) ?>" class="btn-export" style="border-color:#ef4444;color:#ef4444"><i class="fa-solid fa-xmark"></i> مسح</a>
</div>
<?php if ($can_export && ($po_results || $rm_results)): ?>
<div style="display:flex;gap:10px;flex-wrap:wrap">
<a class="btn-export btn-excel" href="?excel=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-file-excel"></i> Excel</a>
<a class="btn-export btn-print" href="?print=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-print"></i> PDF رسمي</a>
<a class="btn-export btn-charts" href="?print_charts=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-chart-pie"></i> لوحة مؤشرات</a>
</div>
<?php endif; ?>
</div>
</form>

<?php if ($po_results || $rm_results): ?>
<?php if ($view_mode === 'executive'): ?>
<div class="kpi-grid">
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#a16207"><i class="fa-solid fa-file-invoice"></i></div><div><div class="kpi-title">أوامر الشراء</div><div class="kpi-val"><?= number_format($g_po) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div><div><div class="kpi-title">أوامر مكتملة</div><div class="kpi-val"><?= number_format($g_po_completed) ?></div><div class="kpi-title" style="color:<?= $completion_rate>=70?'#16a34a':'#dc2626' ?>"><?= $completion_rate ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-clipboard-check"></i></div><div><div class="kpi-title">محاضر الاستلام</div><div class="kpi-val"><?= number_format($g_rm) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-gears"></i></div><div><div class="kpi-title">شهادات التشغيل</div><div class="kpi-val"><?= number_format($g_cc) ?></div><div class="kpi-title"><?= $g_cc_approved ?> معتمدة</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-stopwatch"></i></div><div><div class="kpi-title">Lead Time (استلام→تشغيل)</div><div class="kpi-val"><?= $avg_lead ?> يوم</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-shield-exclamation"></i></div><div><div class="kpi-title">ضمان منتهٍ/ينتهي قريباً</div><div class="kpi-val"><?= $warranty_expired + $warranty_soon ?></div></div></div>
</div>

<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> حالات أوامر الشراء</div><div id="chPO" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:#0ea5e9"></i> حالات محاضر الاستلام</div><div id="chRM" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#a16207"></i> اتجاه محاضر الاستلام</div><div id="chMo" style="min-height:220px"></div></div>
</div>

<?php if ($sup_sorted): ?>
<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-handshake"></i> Leaderboard الموردين (أعلى قيمة)</div>
<div style="overflow-x:auto"><table class="tbl"><thead><tr><th>#</th><th>المورد</th><th>أوامر شراء</th><th>محاضر استلام</th><th>إجمالي القيمة</th></tr></thead><tbody>
<?php foreach ($sup_sorted as $i => $s): ?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td><td style="font-weight:800"><?= e($s['name']) ?></td><td><?= $s['po'] ?></td><td><?= $s['rm'] ?></td><td style="font-family:monospace;font-weight:900"><?= number_format($s['value'],0) ?> ر.س</td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php endif; ?>

<?php else: ?>
<div style="margin-bottom:12px;font-weight:900">السجلات: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px"><?= $total_po + $total_rm ?></span></div>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow-x:auto">
<table class="tbl"><thead><tr><th>#</th><th>النوع</th><th>الرقم</th><th>المورد</th><th>التاريخ</th><th>الحالة</th><th>القيمة</th></tr></thead><tbody>
<?php
$all = array_merge(
    array_map(fn($r)=>['kind'=>'أمر شراء']+$r, $po_results),
    array_map(fn($r)=>['kind'=>'محضر استلام']+$r, $rm_results)
);
$all = array_slice($all, 0, 500);
foreach ($all as $i => $r):
$sc = $r['kind']==='أمر شراء' ? ($PO_STATUS_COLOR[$r['status']] ?? '#475569') : ($RM_STATUS_COLOR[$r['status']] ?? '#475569');
$ar = $r['kind']==='أمر شراء' ? $PO_STATUS_AR : $RM_STATUS_AR;
$date = $r['kind']==='أمر شراء' ? $r['po_date'] : $r['receipt_date'];
$num = $r['kind']==='أمر شراء' ? $r['po_number'] : $r['minute_number'];
?>
<tr><td style="color:var(--t3);font-weight:900"><?= $i+1 ?></td>
<td><span class="badge" style="background:<?= $r['kind']==='أمر شراء'?'#a16207':'#0ea5e9' ?>"><?= e($r['kind']) ?></span></td>
<td><div style="font-weight:800"><?= e($num) ?></div></td>
<td style="font-size:11px"><?= e($r['supplier_name'] ?? '—') ?></td>
<td style="font-size:11px"><?= e($date ? date('Y-m-d', strtotime($date)) : '—') ?></td>
<td><span class="badge" style="background:<?= $sc ?>"><?= e($ar[$r['status']] ?? $r['status']) ?></span></td>
<td style="font-family:monospace"><?= e(number_format((float)$r['total_value'],0)) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php endif; ?>

<?php else: ?>
<div class="empty"><i class="fa-solid fa-truck-ramp-box" style="font-size:44px;color:var(--primary);display:block;margin-bottom:10px"></i><h3>لا توجد سجلات مطابقة</h3><p>عدّل الفلاتر أو امسحها.</p></div>
<?php endif; ?>

</div></main></div>
<script>
<?php if ($view_mode==='executive' && ($po_results || $rm_results)): ?>
document.addEventListener("DOMContentLoaded",function(){
const FF='Tajawal';
<?php if(!empty($po_status_cnt)): ?>new ApexCharts(document.querySelector("#chPO"),{series:<?= json_encode(array_values($po_status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$PO_STATUS_AR[$k]??$k,array_keys($po_status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:<?= json_encode(array_map(fn($k)=>$PO_STATUS_COLOR[$k]??'#475569',array_keys($po_status_cnt))) ?>,plotOptions:{pie:{donut:{size:'62%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();<?php endif; ?>
<?php if(!empty($rm_status_cnt)): ?>new ApexCharts(document.querySelector("#chRM"),{series:<?= json_encode(array_values($rm_status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$RM_STATUS_AR[$k]??$k,array_keys($rm_status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:<?= json_encode(array_map(fn($k)=>$RM_STATUS_COLOR[$k]??'#475569',array_keys($rm_status_cnt))) ?>,plotOptions:{pie:{donut:{size:'62%'}}},dataLabels:{enabled:false},legend:{position:'bottom',fontSize:'11px',fontWeight:700}}).render();<?php endif; ?>
<?php if(!empty($month_rm)): ?>new ApexCharts(document.querySelector("#chMo"),{series:[{name:'محاضر',data:<?= json_encode(array_values($month_rm)) ?>}],chart:{type:'area',height:'100%',toolbar:{show:false},fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($month_rm)) ?>},colors:['#a16207'],stroke:{curve:'smooth',width:3},fill:{type:'gradient',gradient:{opacityFrom:.6,opacityTo:.05}},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
<?php endif; ?>
</script>
</body></html>