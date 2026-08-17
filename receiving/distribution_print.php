<?php
/**
 * receiving/distribution_print.php — طباعة بيان التوزيع (v4)
 * ─────────────────────────────────────────────────────────────
 * صفحة بيضاء بلا ترويسة/تذييل — تُطبع على الورق الرسمي للمستشفى.
 * المصدر: بنود محضر الاستلام (خطوة التوزيع لدى الإمداد).
 * بنية الجدول مطابقة للنموذج الورقي الأصلي بأعمدته الثمانية.
 */
require_once dirname(__DIR__) . '/config.php';
require_login();
page_guard('receiving.index');

$mid = (int)($_GET['minute_id'] ?? 0);
if (!$mid) die('minute_id مطلوب');

$s = $pdo->prepare("SELECT * FROM receiving_minutes WHERE id = ? LIMIT 1");
$s->execute([$mid]);
$rn = $s->fetch();
if (!$rn) die('المحضر غير موجود');

/* بنود التوزيع مباشرة من المحضر */
$rows = $pdo->prepare("
    SELECT rmi.description           AS device_en,
           rmi.description_ar        AS device_ar,
           rmi.model_number,
           rmi.manufacturer_name,
           rmi.quantity,
           rmi.department_id,
           d.name                    AS department_name,
           u.full_name               AS receiver_name,
           (SELECT cc.serial_number FROM commissioning_certificates cc
             WHERE cc.receiving_minute_id = rmi.minute_id
               AND cc.department_id = rmi.department_id
             ORDER BY cc.id DESC LIMIT 1) AS serial_numbers
    FROM receiving_minute_items rmi
    LEFT JOIN departments d ON d.id = rmi.department_id
    LEFT JOIN users u ON u.id = d.manager_id
    WHERE rmi.minute_id = ? AND rmi.is_main_device = 1
      AND rmi.department_id IS NOT NULL
    ORDER BY rmi.description, rmi.sequence_no");
$rows->execute([$mid]);
$rows = $rows->fetchAll();
if (!$rows) die('لم تُحدد أقسام التوزيع في خطوات هذا المحضر — راجع خطوة التوزيع لدى الإمداد.');

/* شهادات التشغيل الصادرة على هذا البيان */
$certs = $pdo->prepare("
    SELECT cc.certificate_number, cc.status, d.name AS dept_name
    FROM commissioning_certificates cc
    LEFT JOIN departments d ON d.id = cc.department_id
    WHERE cc.receiving_minute_id = ? ORDER BY cc.id");
$certs->execute([$mid]);
$certs = $certs->fetchAll();

/* تجميع حسب الجهاز (لدمج الموديل/المصنع)، وداخله حسب القسم (لدمج التوقيع) */
$device_groups = [];
$total_units = 0;
foreach ($rows as $r) {
    $key = $r['device_en'] . '|' . $r['model_number'] . '|' . $r['manufacturer_name'];
    $device_groups[$key]['info']    = $r;
    $device_groups[$key]['allocs'][] = $r;
    $total_units += max(1, (int)$r['quantity']);
}

/* عنوان الجهاز في الترويسة: يعرض الاسم الوحيد، أو أول جهاز رئيسي إن تعددت */
$dev_names = array_values(array_unique(array_map(
    fn($g) => trim($g['info']['device_ar'] ?: $g['info']['device_en']), $device_groups)));
$dev_names_en = array_values(array_unique(array_map(
    fn($g) => trim($g['info']['device_en']), $device_groups)));

$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$doc_line = 'بـ' . $hospital . ' ببلجرشي';
if (!empty($rn['doc_number'])) {
    $doc_line .= ' لتعميد ' . ($rn['doc_type'] ?: 'نوبكو')
        . ' رقم ' . $rn['doc_number'];
}

$ops_name = (string)($pdo->query("
    SELECT u.full_name FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.name = 'executive' AND u.is_active = 1
    ORDER BY ur.is_primary DESC, u.id LIMIT 1")->fetchColumn() ?: '');
if ($ops_name === '') $ops_name = get_setting('ops_assistant_name', '');

$CERT_ST = ['draft' => 'مسودة', 'pending_approval' => 'بانتظار الاعتماد',
    'approved' => 'معتمدة', 'closed' => 'معتمدة ومقفلة'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>بيان توزيع — <?= e($rn['minute_number']) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;900&family=Inter:wght@700&display=swap');
@page { size: A4 portrait; margin: 0; }
body { font-family: 'Tajawal', sans-serif; background: #525659; margin: 0; }
.eng { font-family: 'Inter', sans-serif; direction: ltr; }
.page { width: 210mm; min-height: 296.4mm; margin: 10mm auto;
    padding: 38mm 15mm 30mm; background: #fff;
    box-shadow: 0 0 15px rgba(0,0,0,.3); box-sizing: border-box;
    display: flex; flex-direction: column; }
.page > * { flex-shrink: 0; }

.title { text-align: center; }
.title .t1 { font-size: 18pt; font-weight: 900; color: #0f172a; }
.title .t2 { font-size: 13pt; font-weight: 800; color: #1e293b;
    margin-top: 3mm; }
.title .t2 .dash { color: #94a3b8; margin: 0 2mm; }
.title .t2 .eng { font-size: 11.5pt; color: #475569; }

.subline { text-align: center; font-size: 11.5pt; font-weight: 800;
    color: #1e293b; margin: 4mm 0 7mm; line-height: 1.9; }

table.dist { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
table.dist th { background: #334155; color: #fff; font-size: 9.5pt;
    font-weight: 900; padding: 2.6mm 2mm; border: .8px solid #334155; }
table.dist td { border: .8px solid #64748b; font-size: 10pt; font-weight: 700;
    padding: 2.4mm 2mm; text-align: center; color: #111;
    vertical-align: middle; }
table.dist td.sig-cell { min-width: 32mm; height: 12mm; }
table.dist td.dev-cell { font-weight: 800; }

.bottom-box { border: 1px solid #cbd5e1; background: #f8fafc;
    border-radius: 2.5mm; padding: 3mm 4.5mm; margin-bottom: 6mm; }
.bottom-box .ref { font-size: 9.5pt; font-weight: 900; color: #1e293b;
    margin-bottom: 1.5mm; }
.bottom-box .ref .eng { font-weight: 800; }
.bottom-box .certs { font-size: 8.5pt; font-weight: 700; color: #334155;
    line-height: 1.9; }
.bottom-box .certs b { font-weight: 900; }
.bottom-box .chip { display: inline-block; background: #fff;
    border: 1px solid #94a3b8; border-radius: 99px; padding: .4mm 3mm;
    margin: .5mm 0 0 2mm; font-size: 8pt; }

.foot { margin-top: auto; display: flex; justify-content: flex-end;
    padding-top: 6mm; }
.ops-sig { width: 82mm; text-align: center; }
.ops-sig .t { font-size: 11pt; font-weight: 900; color: #0f172a;
    line-height: 2; }
.ops-sig .n { font-size: 11.5pt; font-weight: 800; color: #1e293b;
    margin-top: 10mm; }
.ops-sig .line { border-bottom: 1.3px dotted #475569; margin-top: 9mm; }
.ops-sig .lbl { font-size: 8pt; color: #64748b; margin-top: 1.5mm; }

.print-bar { text-align: center; padding: 10px; }
.print-bar button { font-family: 'Tajawal'; font-size: 14px; font-weight: 800;
    background: #334155; color: #fff; border: none; border-radius: 9px;
    padding: 10px 26px; cursor: pointer; }
@media print { body { background: #fff; } .page { margin: 0; box-shadow: none; }
    .print-bar { display: none; } }
</style>
</head>
<body>
<div class="print-bar"><button onclick="window.print()">🖨️ طباعة البيان</button></div>
<div class="page">

    <div class="title">
        <div class="t1">بيان توزيع</div>
        <div class="t2">جهاز<span class="dash">-</span><?= e(implode('، ', $dev_names)) ?>
            <span class="eng">(<?= e(implode(', ', $dev_names_en)) ?>)</span></div>
    </div>

    <div class="subline"><?= e($doc_line) ?></div>

    <table class="dist">
        <tr>
            <th style="width:8mm">م</th>
            <th>اسم الجهاز</th>
            <th style="width:28mm">الرقم التسلسلي</th>
            <th style="width:20mm">الموديل</th>
            <th style="width:18mm">المصنع</th>
            <th style="width:26mm">القسم</th>
            <th style="width:30mm">اسم المستلم</th>
            <th style="width:28mm">التوقيع</th>
        </tr>
        <?php $i = 0; foreach ($device_groups as $g):
            $dev_label = $g['info']['device_ar'] ?: $g['info']['device_en'];
            $dm = $g['allocs']; $dev_total_rows = 0;
            foreach ($dm as $a) $dev_total_rows += max(1, (int)$a['quantity'],
                count(preg_split('/[\r\n,؛;]+/u', (string)$a['serial_numbers'], -1, PREG_SPLIT_NO_EMPTY)));
            $dev_first = true;
            foreach ($dm as $a):
                $serials = array_map('trim', preg_split('/[\r\n,؛;]+/u',
                    (string)$a['serial_numbers'], -1, PREG_SPLIT_NO_EMPTY));
                $qty = max(1, (int)$a['quantity'], count($serials));
                while (count($serials) < $qty) $serials[] = '';
                $n = count($serials); $first = true;
                foreach ($serials as $sn): $i++; ?>
        <tr>
            <td class="eng"><?= $i ?></td>
            <td class="dev-cell"><?= e($dev_label) ?></td>
            <td class="eng"><?= e($sn ?: '') ?>&nbsp;</td>
            <?php if ($dev_first): ?>
            <td rowspan="<?= $dev_total_rows ?>" class="eng"><?= e($a['model_number'] ?: '—') ?></td>
            <td rowspan="<?= $dev_total_rows ?>" class="eng"><?= e($a['manufacturer_name'] ?: '—') ?></td>
            <?php $dev_first = false; endif; ?>
            <?php if ($first): ?>
            <td rowspan="<?= $n ?>" style="font-weight:900"><?= e($a['department_name'] ?: '—') ?></td>
            <td rowspan="<?= $n ?>"><?= e($a['receiver_name'] ?: '') ?>&nbsp;</td>
            <td rowspan="<?= $n ?>" class="sig-cell">&nbsp;</td>
            <?php $first = false; endif; ?>
        </tr>
        <?php endforeach; endforeach; endforeach; ?>
    </table>

    <div class="bottom-box">
        <div class="ref">محضر الاستلام رقم: <span class="eng"><?= e($rn['minute_number']) ?></span>
            &nbsp;—&nbsp; بتاريخ: <span class="eng"><?= e($rn['receipt_date'] ?: '—') ?></span></div>
        <?php if ($certs): ?>
        <div class="certs"><b>شهادات التركيب والتشغيل الصادرة على هذا البيان:</b><br>
            <?php foreach ($certs as $c): ?>
            <span class="chip"><?= e($c['dept_name'] ?: '—') ?> —
                <span class="eng"><?= e($c['certificate_number']) ?></span>
                (<?= e($CERT_ST[$c['status']] ?? $c['status']) ?>)</span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="foot">
        <div class="ops-sig">
            <div class="t">مساعد المدير للخدمات الإدارية والتشغيل<br>
                بـ<?= e($hospital) ?> ببلجرشي</div>
            <div class="n"><?= e($ops_name) ?>&nbsp;</div>
            <div class="line"></div>
            <div class="lbl">الاسم والتوقيع</div>
        </div>
    </div>

</div>
</body>
</html>