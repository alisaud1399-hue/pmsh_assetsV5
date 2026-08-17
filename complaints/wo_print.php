<?php
/**
 * complaints/wo_print.php — نموذج طباعة أمر العمل مطابق لنموذج المشرق للخدمات الفنية
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('work_orders.view');

$id = (int) ($_GET['id'] ?? 0);
if (!$id) die('معرف أمر العمل مفقود');

$s = $pdo->prepare("
    SELECT wo.*, c.request_number, c.priority, c.description AS complaint_desc,
           a.description AS asset_desc, a.tag_number, a.manufacturer_name,
           a.model_number, a.serial_number, a.loc_building, a.loc_room,
           d.name AS dept_name, u.full_name AS requester_name,
           cr.full_name AS creator_name
    FROM complaint_work_orders wo
    JOIN complaints c ON c.id=wo.complaint_id
    LEFT JOIN assets a ON a.id=c.asset_id
    LEFT JOIN departments d ON d.id=c.dept_id
    LEFT JOIN users u ON u.id=c.requested_by
    LEFT JOIN users cr ON cr.id=wo.created_by
    WHERE wo.id=?
");
$s->execute([$id]);
$wo = $s->fetch();
if (!$wo) die('أمر العمل غير موجود');

// جلب قطع الغيار من جدول work_order_items
$items_q = $pdo->prepare("SELECT * FROM work_order_items WHERE work_order_id=? ORDER BY id");
$items_q->execute([$id]);
$items = $items_q->fetchAll(PDO::FETCH_ASSOC);

// أخذ أول 8 قطع فقط كحد أقصى لمنع تدمير تخطيط الصفحة
$items_to_print = array_slice($items, 0, 8);

// نضمن وجود 8 صفوف على الأقل للحفاظ على ارتفاع وشكل جدول الطباعة وملء الصفحة
while (count($items_to_print) < 8) {
    $items_to_print[] = [
        'description' => '',
        'part_number' => '',
        'quantity'    => '',
        'remarks'     => ''
    ];
}

$chk = fn($v) => $v ? '✓' : '';
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');

// أوامر تقنية المعلومات: قالب داخلي مختصر باسم المستشفى — لا قالب المشرق
if (($wo['wo_type'] ?? '') === 'it') {
    require __DIR__ . '/wo_print_it.php';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>أمر الإصلاح <?= e($wo['wo_number']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&family=Inter:wght@700;900&display=swap');

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Tajawal', Arial, sans-serif;
    font-size: 9.5px;
    color: #000;
    background: #525659;
    direction: rtl;
}

/* إعدادات صفحة الطباعة A4 */
@page {
    size: A4 portrait;
    margin: 0;
}

/* حاوية الصفحة - الارتفاع الموزون والآمن */
.page {
    width: 210mm;
    height: 285mm; 
    margin: 10mm auto; 
    padding: 6mm 8mm; 
    position: relative;
    background: #fff;
    box-shadow: 0 0 15px rgba(0,0,0,0.3);
    display: flex;
    flex-direction: column; 
    gap: 0;
}

/* 🌟 رأس النموذج */
.header { display: flex; align-items: stretch; border: 2px solid #1a5276; border-bottom: none; }
.header-main { flex: 1; padding: 6px; display: flex; flex-direction: column; justify-content: center; align-items: center; }

/* 🌟 تصميم لوجو المشرق والأسماء */
.brand-container { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 6px; direction: ltr; }
.brand-ar { display: flex; flex-direction: column; text-align: right; color: #1e3a8a; }
.brand-name-ar { font-size: 26px; font-weight: 900; line-height: 0.9; letter-spacing: -0.5px; }
.brand-sub-ar { font-size: 11px; font-weight: 900; line-height: 1.2; }

/* رسم شعار المشرق باستخدام CSS ليطابق الأصل */
.brand-logo { 
    width: 32px; height: 32px; 
    background: #1e3a8a; 
    position: relative; 
    display: flex; align-items: center; justify-content: center; 
}
.brand-logo-crescent {
    width: 15px; height: 20px;
    border: 4.5px solid #fff;
    border-right: none;
    border-radius: 20px 0 0 20px;
    position: absolute;
    left: 4px;
}
.brand-logo-lines {
    position: absolute;
    left: 11px;
    top: 6px;
    display: flex;
    flex-direction: column;
    gap: 1.5px;
}
.brand-logo-lines span { background: #fff; height: 2.5px; }
.brand-logo-lines span:nth-child(1) { width: 14px; }
.brand-logo-lines span:nth-child(2) { width: 17px; }
.brand-logo-lines span:nth-child(3) { width: 15px; }
.brand-logo-lines span:nth-child(4) { width: 10px; }

.brand-en { display: flex; flex-direction: column; text-align: left; color: #1e3a8a; }
.brand-name-en { font-family: 'Inter', sans-serif; font-size: 19px; font-weight: 900; line-height: 0.9; letter-spacing: 0.5px; }
.brand-sub-en { font-family: 'Inter', sans-serif; font-size: 8px; font-weight: 900; letter-spacing: 1px; margin-top: 2px; }

.contract-title { font-size: 13px; font-weight: 900; color: #1a5276; }
.contract-subtitle { font-size: 10.5px; font-weight: 700; color: #333; margin-top: 2px; }

/* بيانات الميتا (يسار الترويسة) */
.header-meta { width: 48mm; border-right: 2px solid #1a5276; padding: 4px 6px; display: flex; flex-direction: column; justify-content: center; gap: 4px; }
.meta-row { display: flex; justify-content: space-between; font-size: 10px; border-bottom: 1px dotted #aaa; padding-bottom: 3px; }
.meta-row:last-child { border: none; }
.meta-label { color: #555; font-weight: 700; }
.meta-val { font-weight: 900; font-size: 10.5px; font-family: 'Inter', monospace; color: #b91c1c; }
.meta-val-text { font-weight: 900; font-size: 10px; color: #000; font-family: 'Tajawal'; }

/* قسم بيانات الجهاز */
.equip-table { width: 100%; border-collapse: collapse; border: 1px solid #1a5276; border-top: 2px solid #1a5276; }
.equip-table td { border: 1px solid #1a5276; padding: 3px 5px; height: 7mm; }
.eq-label { width: 15%; font-size: 8.5px; font-weight: 900; color: #1a5276; background: #f4f9fd; vertical-align: middle; }
.eq-en { font-size: 7px; color: #555; display: block; font-family: 'Inter', sans-serif; }
.eq-value { width: 35%; font-size: 10.5px; font-weight: 700; color: #000; vertical-align: middle; }

/* خانات الخدمة */
.svc-section { border: 1px solid #1a5276; border-top: none; display: grid; grid-template-columns: repeat(3, 1fr); }
.svc-header { grid-column: span 3; font-size: 8.5px; color: #1a5276; font-weight: 900; background: #d6eaf8; padding: 2px 5px; border-bottom: 1px solid #1a5276; }
.svc-col { padding: 4px 8px; }
.divider-v { border-right: 1px solid #1a5276; }
.svc-item { display: flex; align-items: center; gap: 6px; padding: 3px 0; font-size: 10px; font-weight: 700; }
.chk-box { width: 11px; height: 11px; border: 1px solid #333; display: inline-flex; align-items: center; justify-content: center; font-size: 9px; font-weight: 900; flex-shrink: 0; }

/* منطقة الأعمال المنجزة */
.service-done { border: 1px solid #1a5276; border-top: none; display: flex; flex-direction: column; }
.section-label { background: #d6eaf8; padding: 2px 5px; font-size: 8.5px; font-weight: 900; color: #1a5276; border-bottom: 1px solid #1a5276; }
.text-area {
    height: 54mm;
    padding: 0 6px;
    font-size: 11px;
    font-weight: 700;
    line-height: 25px; 
    background-image: repeating-linear-gradient(to bottom, transparent, transparent 24px, #a6acaf 24.5px, #a6acaf 25px);
    background-position: top;
    background-attachment: local;
    color: #111;
    overflow: hidden; /* إخفاء أي نصوص زائدة لتجنب تدمير الصفحة */
}

/* حالة الإنجاز */
.status-section { border: 1px solid #1a5276; border-top: none; display: flex; padding: 6px 8px; flex-wrap: wrap; gap: 12px; align-items: center; }
.status-item { display: flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; }

/* جدول قطع الغيار */
.parts-section { border: 1px solid #1a5276; border-top: none; }
.parts-table { width: 100%; border-collapse: collapse; }
.parts-table th { background: #d6eaf8; font-size: 8.5px; font-weight: 900; color: #1a5276; padding: 4px; text-align: center; border: 1px solid #1a5276; }
.parts-table td { font-size: 10.5px; padding: 3px 5px; border: 1px solid #bbb; height: 5.5mm; font-weight: 700; overflow: hidden; }

/* متابعة */
.followup-section { border: 1px solid #1a5276; border-top: none; flex-grow: 1; display: flex; flex-direction: column; }
.followup-area { flex-grow: 1; padding: 4px 6px; font-size: 10.5px; font-weight: 700; line-height: 1.6; min-height: 12mm; overflow: hidden; }

/* ساعات العمل */
.hours-section { border: 1px solid #1a5276; border-top: none; display: flex; align-items: stretch; }
.hours-computer { flex: 1; display: flex; align-items: center; gap: 8px; padding: 3px 8px; border-left: 1px solid #1a5276; }
.hours-grid { display: flex; gap: 0; }
.hour-cell { border-left: 1px solid #bbb; text-align: center; padding: 2px 10px; }
.hour-cell:first-child { border-left: none; }
.hour-cell .h-label { font-size: 8.5px; color: #555; font-weight: 700; font-family: 'Inter', sans-serif; }
.hour-cell .h-val { font-size: 12px; font-weight: 900; min-height: 5mm; border-bottom: 1px solid #333; }
.completed-box { padding: 3px 10px; display: flex; align-items: center; gap: 8px; border-right: 1px solid #1a5276; }
.c-item { display: flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; }

/* توقيعات */
.sigs-section { border: 1px solid #1a5276; border-top: none; display: grid; grid-template-columns: repeat(4, 1fr); }
.sig-col { padding: 4px 6px; border-left: 1px solid #1a5276; }
.sig-col:last-child { border-left: none; }
.sig-col-title { font-size: 9px; font-weight: 900; color: #1a5276; text-align: center; border-bottom: 1px solid #bbb; padding-bottom: 3px; margin-bottom: 5px; }
.sig-row { display: flex; align-items: center; gap: 4px; margin-bottom: 4px; min-height: 5mm; }
.sig-label { font-size: 8px; color: #555; font-weight: 700; min-width: 12mm; }
.sig-line { flex: 1; border-bottom: 1px solid #333; }
.sig-image { max-width: 100%; max-height: 11mm; display: block; margin: 1px auto; } 
.sig-box { min-height: 14mm; }

/* أزرار وعناصر غير مطبوعة */
.no-print { max-width: 210mm; margin: 15px auto; padding: 10px; display: flex; justify-content: center; background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
.print-btn { background: #1a5276; color: #fff; border: none; padding: 10px 30px; border-radius: 7px; font-family: 'Tajawal'; font-size: 14px; font-weight: 900; cursor: pointer; transition: background 0.2s; }
.print-btn:hover { background: #113852; }

@media print {
    body { background: #fff; margin: 0; padding: 0; }
    .no-print { display: none !important; }
    .page {
        overflow: hidden !important; /* ضمان عدم تجاوز أي محتوى لصفحة ثانية */
        margin: 0 !important;
        box-shadow: none !important;
        width: 210mm !important;
        height: 285mm !important; /* الاعتماد القطعي لارتفاع آمن */
        border: none;
        padding: 5mm 6mm !important;
        page-break-after: avoid;
        page-break-inside: avoid;
    }
}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<div class="no-print">
    <button class="print-btn" onclick="window.print()">🖨️ طباعة / حفظ PDF</button>
</div>

<div class="page">
    <!-- الترويسة -->
    <div class="header">
        <div class="header-main">
            <div class="brand-container">
                <div class="brand-en">
                    <span class="brand-name-en">ALMASHRIQ</span>
                    <span class="brand-sub-en">TECHNICAL SERVICES</span>
                </div>
                <div class="brand-logo">
                    <div class="brand-logo-crescent"></div>
                    <div class="brand-logo-lines">
                        <span></span><span></span><span></span><span></span>
                    </div>
                </div>
                <div class="brand-ar">
                    <span class="brand-name-ar">المشرق</span>
                    <span class="brand-sub-ar">للخدمات الفنية</span>
                </div>
            </div>
            <div class="contract-title">عقد صيانة وإصلاح الأجهزة الطبية</div>
            <div class="contract-subtitle">قسم الصيانة الطبية — <?= e($hospital) ?></div>
        </div>
        <div class="header-meta">
            <div class="meta-row"><span class="meta-label">أمر إصلاح رقم</span><span class="meta-val"><?= e($wo['wo_number']) ?></span></div>
            <div class="meta-row"><span class="meta-label">رقم البلاغ</span><span class="meta-val"><?= e($wo['request_number']) ?></span></div>
            <div class="meta-row"><span class="meta-label">تاريخ</span><span class="meta-val"><?= e($wo['wo_date']) ?></span></div>
            <div class="meta-row"><span class="meta-label">المهندس / الفني</span><span class="meta-val-text"><?= e($wo['engineer_name'] ?? '') ?></span></div>
        </div>
    </div>

    <!-- جدول بيانات الجهاز -->
    <table class="equip-table">
        <tr>
            <td class="eq-label">الجهاز <span class="eq-en">EQUIPMENT</span></td>
            <td class="eq-value"><?= e($wo['asset_desc'] ?? '') ?></td>
            <td class="eq-label">الموقع <span class="eq-en">LOCATION</span></td>
            <td class="eq-value"><?= e(trim(($wo['loc_building'] ?? '') . ' — ' . ($wo['loc_room'] ?? ''), ' —')) ?></td>
        </tr>
        <tr>
            <td class="eq-label">الصانع/الموديل <span class="eq-en">MODEL</span></td>
            <td class="eq-value"><?= e(($wo['manufacturer_name']??'') . ' / ' . ($wo['model_number']??'')) ?></td>
            <td class="eq-label">القسم <span class="eq-en">DEPARTMENT</span></td>
            <td class="eq-value"><?= e($wo['dept_name'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="eq-label">رقم التسلسل <span class="eq-en">SERIAL No.</span></td>
            <td class="eq-value eng"><?= e($wo['serial_number'] ?? '') ?></td>
            <td class="eq-label">طلب من <span class="eq-en">REQUESTED BY</span></td>
            <td class="eq-value"><?= e($wo['requester_name'] ?? '') ?></td>
        </tr>
        <tr>
            <td class="eq-label">الوكيل <span class="eq-en">AGENT</span></td>
            <td class="eq-value"></td>
            <td class="eq-label"></td>
            <td class="eq-value"></td>
        </tr>
    </table>

    <!-- خانات الخدمة -->
    <div class="svc-section">
        <div class="svc-header">نوع الخدمة — Type of Service</div>
        <div class="svc-col">
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_power_supply']) ?></span> مصدر الطاقة Power Supply</div>
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_planned_maintenance']) ?></span> صيانة دورية Planned Maintenance</div>
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_spare_parts_required']) ?></span> قطع غيار مطلوبة Spare Parts Required</div>
        </div>
        <div class="svc-col divider-v">
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_electronic']) ?></span> إلكترونيات Electronic</div>
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_calibration']) ?></span> معايرة Calibration</div>
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_rescreening']) ?></span> إعادة فحص Accessory / Rescreening</div>
        </div>
        <div class="svc-col divider-v">
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_chemical']) ?></span> كيميائي Chemical</div>
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_equipment_fault']) ?></span> عطل معدّات Equipment at Fault</div>
            <div class="svc-item"><span class="chk-box"><?= $chk($wo['service_spare_parts_stock']) ?></span> قطع مستودع Spare Parts For Stock</div>
        </div>
    </div>

    <!-- الأعمال المنجزة -->
    <div class="service-done">
        <div class="section-label">شرح الأعطال والأعمال المنجزة — SERVICE DONE</div>
        <div class="text-area"><?= e($wo['service_description'] ?? '') ?></div>
    </div>

    <div class="status-section">
        <?php
        $fs = $wo['final_status'] ?? 'pending';
        $statuses = [
            'completed'            => 'العمل مكتمل Completed',
            'working_need_parts'   => 'يعمل ولكن يحتاج إلى قطع الغيار التالية Working also need Spare Parts',
            'need_agent'           => 'يحتاج وكيل Agent Required',
            'pending'              => 'قيد المتابعة Pending',
        ];
        foreach ($statuses as $k => $label): ?>
        <div class="status-item">
            <span class="chk-box" style="width:12px;height:12px"><?= $fs===$k ? '✓' : '' ?></span>
            <span><?= $label ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- جدول قطع الغيار -->
    <div class="parts-section">
        <div class="section-label">وصف قطع الغيار المطلوبة — SPARE PARTS REQUIRED</div>
        <table class="parts-table">
            <thead>
                <tr>
                    <th style="width:42%">وصف قطع الغيار<br>DESCRIPTION</th>
                    <th style="width:22%">رقم القطعة<br>PART No.</th>
                    <th style="width:10%">الكمية<br>QTY</th>
                    <th style="width:26%">ملاحظات<br>REMARKS</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items_to_print as $item): ?>
                <tr>
                    <td><?= e($item['description'] ?? '') ?></td>
                    <td style="text-align:center;font-family:monospace" dir="ltr"><?= e($item['part_number'] ?? '') ?></td>
                    <td style="text-align:center"><?= e($item['quantity'] ?? '') ?></td>
                    <td><?= e($item['remarks'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- المتابعة -->
    <div class="followup-section">
        <div class="section-label">المتابعة — Follow Up</div>
        <div class="followup-area"><?= nl2br(e($wo['follow_up_notes'] ?? '')) ?></div>
    </div>

    <div class="hours-section">
        <div class="completed-box">
            <span style="font-size:9.5px;font-weight:900;color:#1a5276">هل تم إنجاز العمل؟</span>
            <div class="c-item"><span class="chk-box"><?= !empty($wo['work_completed']) ? '✓' : '' ?></span> نعم Yes</div>
            <div class="c-item"><span class="chk-box"><?= empty($wo['work_completed']) ? '✓' : '' ?></span> لا No</div>
        </div>
        <div class="hours-computer" style="border-right:none">
            <span style="font-size:9px;font-weight:900;color:#1a5276">ساعات العمل</span>
            <div class="hours-grid">
                <?php foreach([['1',$wo['work_hours_day1']??0],['2',$wo['work_hours_day2']??0],['3',$wo['work_hours_day3']??0],['TOTAL',$wo['work_hours_total']??0]] as [$l,$v]): ?>
                <div class="hour-cell">
                    <div class="h-label"><?= $l ?></div>
                    <div class="h-val"><?= (float)$v ?: '' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="padding:3px 10px;display:flex;align-items:center;font-size:9px;color:#555;border-right:1px solid #1a5276; font-weight:700;">لإستخدام الكمبيوتر<br>For Computer Use</div>
    </div>

    <div class="sigs-section">
        <?php
        $sigs = [
            ['مشرف الصيانة الطبية','M.O.H. Sup', 'manager', $wo['manager_signature']??null, null],
            ['مدير الموقع','Site Manager', null, null, null],
            ['المصلح','Repaired by', 'contractor', $wo['contractor_signature']??null, $wo['contractor_signed_name']??null],
            ['الدائرة المعنية','Department', null, null, null],
        ];
        foreach ($sigs as [$ar,$en,$type,$sig,$name]): ?>
        <div class="sig-col">
            <div class="sig-col-title"><?= $ar ?><br><span style="font-size:7px;font-weight:700;font-family:'Inter',sans-serif"><?= $en ?></span></div>
            <div class="sig-row"><span class="sig-label">الاسم<br>Name</span><span class="sig-line" style="font-size:10px;font-weight:700"><?= $name ? e($name) : '' ?></span></div>
            <div class="sig-row sig-box">
                <span class="sig-label">التوقيع<br>Sig.</span>
                <?php if ($sig): ?><img src="<?= e($sig) ?>" class="sig-image"><?php else: ?><span class="sig-line"></span><?php endif; ?>
            </div>
            <div class="sig-row"><span class="sig-label">التاريخ<br>Date</span><span class="sig-line eng" style="font-size:10px"><?= $type==='manager' && $wo['approved_at'] ? e(date('Y-m-d',strtotime($wo['approved_at']))) : '' ?></span></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>