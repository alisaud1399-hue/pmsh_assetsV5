<?php
/**
 * complaints/wo_print_it.php — طباعة أمر عمل تقنية المعلومات (داخلي)
 * ─────────────────────────────────────────────────────────────
 * قالب مختصر بهوية المستشفى — يُضمَّن من wo_print.php حصراً
 * (بعد page_guard وجلب $wo و$items و$hospital هناك).
 */
if (!isset($wo, $hospital)) { http_response_code(403); die('وصول مباشر غير مسموح'); }

$real_items = array_values(array_filter($items ?? [],
    fn($i) => trim((string)($i['description'] ?? '')) !== ''));

$FS = ['completed' => 'تم الإصلاح', 'working_need_parts' => 'يعمل — بانتظار قطع غيار',
    'need_secondary_parts' => 'يحتاج قطعاً ثانوية', 'need_agent' => 'يحتاج جهة خارجية',
    'pending' => 'قيد المتابعة'];
$CAT = ['hardware' => 'عتاد Hardware', 'software' => 'برمجيات Software',
    'network' => 'شبكات Network', 'security' => 'أمن معلومات Security',
    'user_support' => 'دعم مستخدمين Support'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>أمر عمل <?= e($wo['wo_number']) ?> — تقنية المعلومات</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&family=Inter:wght@700;900&display=swap');
@page { size: A4 portrait; margin: 0; }
body { font-family: 'Tajawal', sans-serif; background: #525659; margin: 0; }
.eng { font-family: 'Inter', sans-serif; direction: ltr; }
.page { width: 210mm; min-height: 285mm; margin: 10mm auto; padding: 10mm 12mm;
    background: #fff; box-shadow: 0 0 15px rgba(0,0,0,.3);
    display: flex; flex-direction: column; box-sizing: border-box; }
.page > div { flex-shrink: 0; }
.page > .grow { flex-grow: 1; flex-shrink: 1; min-height: 12mm; }

.hdr { display: flex; justify-content: space-between; align-items: center;
    border-bottom: 3px solid #4527A0; padding-bottom: 5mm; margin-bottom: 5mm; }
.hdr-t { text-align: right; }
.hdr-t .h1 { font-size: 16px; font-weight: 900; color: #1a237e; }
.hdr-t .h2 { font-size: 12px; font-weight: 800; color: #4527A0; margin-top: 1mm; }
.hdr-m { text-align: left; font-size: 11px; font-weight: 700; color: #333; }
.hdr-m b { color: #4527A0; }

.sec-t { font-size: 11px; font-weight: 900; color: #fff; background: #4527A0;
    padding: 2.5px 10px; border-radius: 4px 4px 0 0; display: inline-block; }
.box { border: 1.2px solid #4527A0; border-radius: 0 6px 6px 6px;
    padding: 3mm 4mm; margin-bottom: 4mm; }
.kv-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2mm 5mm; }
.kv-grid.c3 { grid-template-columns: repeat(3, 1fr); }
.kv b { display: block; font-size: 8.5px; color: #666; font-weight: 800; }
.kv span { font-size: 11px; font-weight: 700; color: #111; }
.txt { font-size: 11px; font-weight: 700; line-height: 1.9; color: #111;
    min-height: 10mm; white-space: pre-wrap; }

table.parts { width: 100%; border-collapse: collapse; }
table.parts th { font-size: 9px; font-weight: 900; color: #4527A0;
    border-bottom: 1.5px solid #4527A0; padding: 1.5mm; text-align: right; }
table.parts td { font-size: 10px; font-weight: 700; border-bottom: 1px solid #ccc;
    padding: 1.5mm; }

.sigs { display: grid; grid-template-columns: repeat(3, 1fr); gap: 5mm;
    border-top: 2px solid #4527A0; padding-top: 4mm; margin-top: auto; }
.sig-c { text-align: center; }
.sig-c .t { font-size: 10px; font-weight: 900; color: #1a237e; margin-bottom: 2mm; }
.sig-c .n { font-size: 10.5px; font-weight: 700; min-height: 5mm;
    border-bottom: 1px solid #999; padding-bottom: 1mm; }
.sig-c .s { min-height: 14mm; border-bottom: 1px solid #333;
    display: flex; align-items: flex-end; justify-content: center; }
.sig-c .s img { max-height: 13mm; max-width: 90%; }
.sig-c .d { font-size: 9px; color: #555; margin-top: 1.5mm; min-height: 4mm; }

.print-bar { text-align: center; padding: 10px; }
.print-bar button { font-family: 'Tajawal'; font-size: 14px; font-weight: 800;
    background: #4527A0; color: #fff; border: none; border-radius: 9px;
    padding: 10px 26px; cursor: pointer; }
@media print { body { background: #fff; } .page { margin: 0; box-shadow: none; }
    .print-bar { display: none; } }
</style>
</head>
<body>
<div class="print-bar"><button onclick="window.print()">🖨️ طباعة</button></div>
<div class="page">

    <div class="hdr">
        <div class="hdr-t">
            <div class="h1"><?= e($hospital) ?></div>
            <div class="h2">إدارة تقنية المعلومات — أمر عمل داخلي
                <span class="eng" style="font-size:9px;color:#666">IT Internal Work Order</span></div>
        </div>
        <div class="hdr-m">
            <div>رقم الأمر: <b class="eng"><?= e($wo['wo_number']) ?></b></div>
            <div>التاريخ: <b class="eng"><?= e($wo['wo_date']) ?></b></div>
            <div>رقم البلاغ: <b class="eng"><?= e($wo['request_number'] ?? '—') ?></b></div>
        </div>
    </div>

    <div><span class="sec-t">بيانات الأمر والجهاز</span>
    <div class="box">
        <div class="kv-grid">
            <div class="kv"><b>تصنيف العمل</b>
                <span><?= e($CAT[$wo['it_work_category'] ?? ''] ?? ($wo['it_work_category'] ?: '—')) ?></span></div>
            <div class="kv"><b>المنفِّذ المعيَّن</b>
                <span><?= e($wo['engineer_name'] ?: '—') ?></span></div>
            <div class="kv"><b>القسم الطالب</b>
                <span><?= e($wo['dept_name'] ?? '—') ?></span></div>
            <div class="kv"><b>طالب الخدمة</b>
                <span><?= e($wo['requester_name'] ?? '—') ?></span></div>
            <div class="kv"><b>الجهاز</b>
                <span><?= e($wo['asset_desc'] ?: 'بلا جهاز محدد') ?></span></div>
            <div class="kv"><b>التاج</b>
                <span class="eng"><?= e($wo['tag_number'] ?: '—') ?></span></div>
            <div class="kv"><b>الرقم التسلسلي</b>
                <span class="eng"><?= e($wo['serial_number'] ?: '—') ?></span></div>
            <div class="kv"><b>نوع الأصل التقني</b>
                <span><?= e($wo['it_asset_type'] ?: '—') ?></span></div>
        </div>
    </div></div>

    <div><span class="sec-t">وصف العطل / المشكلة</span>
    <div class="box"><div class="txt"><?= e($wo['complaint_desc'] ?? '') ?></div></div></div>

    <?php if (!empty($wo['manager_instructions'])): ?>
    <div><span class="sec-t">توجيهات مدير تقنية المعلومات</span>
    <div class="box"><div class="txt" style="min-height:6mm"><?= e($wo['manager_instructions']) ?></div></div></div>
    <?php endif; ?>

    <div class="grow"><span class="sec-t">ما تم عمله</span>
    <div class="box" style="height:calc(100% - 6mm);box-sizing:border-box">
        <div class="txt"><?= e($wo['service_description'] ?? '') ?></div></div></div>

    <div><span class="sec-t">التوصيات</span>
    <div class="box"><div class="txt" style="min-height:7mm"><?= e($wo['follow_up_notes'] ?? '') ?></div></div></div>

    <?php if ($real_items): ?>
    <div><span class="sec-t">قطع الغيار المستخدمة / المطلوبة</span>
    <div class="box" style="padding:2mm 3mm">
        <table class="parts">
            <tr><th style="width:42%">الوصف</th><th style="width:22%">رقم القطعة</th>
                <th style="width:10%;text-align:center">الكمية</th><th>ملاحظات</th></tr>
            <?php foreach ($real_items as $it): ?>
            <tr><td><?= e($it['description']) ?></td>
                <td class="eng"><?= e($it['part_number'] ?: '—') ?></td>
                <td style="text-align:center;font-weight:900"><?= (int)$it['quantity'] ?></td>
                <td><?= e($it['remarks'] ?: '—') ?></td></tr>
            <?php endforeach; ?>
        </table>
    </div></div>
    <?php endif; ?>

    <div><span class="sec-t">النتيجة</span>
    <div class="box">
        <div class="kv-grid">
            <div class="kv"><b>الحالة</b>
                <span><?= e($FS[$wo['final_status'] ?? 'pending'] ?? '—') ?></span></div>
            <div class="kv"><b>ساعات العمل</b>
                <span class="eng"><?= e((float)($wo['work_hours_total'] ?? 0)) ?></span></div>
            <div class="kv"><b>تاريخ الإنجاز الفعلي</b>
                <span class="eng"><?= e($wo['actual_completion_date'] ?: '—') ?></span></div>
            <div class="kv"><b>أُنجز العمل كاملاً؟</b>
                <span><?= !empty($wo['work_completed']) ? '☑ نعم' : '☐ لا' ?></span></div>
        </div>
    </div></div>

    <div class="sigs">
        <div class="sig-c">
            <div class="t">المنفِّذ<br><span class="eng" style="font-size:8px;color:#777">Technician</span></div>
            <div class="n"><?= e($wo['contractor_signed_name'] ?: ($wo['engineer_name'] ?? '')) ?></div>
            <div class="s"><?php if (!empty($wo['contractor_signature'])): ?>
                <img src="<?= e($wo['contractor_signature']) ?>"><?php endif; ?></div>
            <div class="d">التاريخ:</div>
        </div>
        <div class="sig-c">
            <div class="t">مدير تقنية المعلومات<br><span class="eng" style="font-size:8px;color:#777">IT Manager</span></div>
            <div class="n"></div>
            <div class="s"><?php if (!empty($wo['manager_signature'])): ?>
                <img src="<?= e($wo['manager_signature']) ?>"><?php endif; ?></div>
            <div class="d">التاريخ: <span class="eng"><?= !empty($wo['approved_at'])
                ? e(date('Y-m-d', strtotime($wo['approved_at']))) : '' ?></span></div>
        </div>
        <div class="sig-c">
            <div class="t">طالب الخدمة<br><span class="eng" style="font-size:8px;color:#777">Requester</span></div>
            <div class="n"></div>
            <div class="s"></div>
            <div class="d">التاريخ:</div>
        </div>
    </div>

</div>
</body>
</html>