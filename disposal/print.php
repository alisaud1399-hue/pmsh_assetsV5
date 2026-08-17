<?php
/**
 * disposal/print.php — طباعة A4 لمحضر التخلص (نموذج 9+10)
 * ─────────────────────────────────────────────────────────────────
 *   • layout رسمي (Header + بيانات الأصل + قرار التخلص + أعضاء اللجنة + التواقيع)
 *   • بدون A4 background image — نطبع النصوص فقط (سريع + متوافق مع كل المتصفحات)
 *   • طباعة تلقائية ?autoprint=1
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('disposal.index', 'print');

$rtl = is_rtl();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) {
    // ═══ طباعة سجل كامل (يعرض كل القرارات في صفحة واحدة) ═══
    $rows = $pdo->query("
        SELECT d.*, a.tag_number, a.description, a.serial_number, a.manufacturer_name, a.model_number
        FROM asset_disposals d
        JOIN assets a ON a.id = d.asset_id
        ORDER BY d.disposal_date DESC, d.id DESC
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);
    $print_mode = 'register';
} else {
    $st = $pdo->prepare("
        SELECT d.*, a.*, a.id AS asset_id_main,
               u.full_name AS cu_name, cd.name AS cd_name,
               cb.full_name AS created_by_name
        FROM asset_disposals d
        JOIN assets a       ON a.id = d.asset_id
        LEFT JOIN users u   ON u.id = a.custodian_user_id
        LEFT JOIN departments cd ON cd.id = a.custodian_dept_id
        LEFT JOIN users cb  ON cb.id = d.created_by
        WHERE d.id = ?
    ");
    $st->execute([$id]);
    $rec = $st->fetch(PDO::FETCH_ASSOC);
    if (!$rec) { flash('danger', $rtl?'السجل غير موجود':'Not found'); header('Location:'.BASE_URL.'/disposal/index.php'); exit; }
    $atts = $rec['attachments'] ? json_decode($rec['attachments'], true) : [];
    $print_mode = 'single';
}

$type_cfg = [
    'scrap'        => ['ar'=>'تكهين',         'en'=>'Scrap',         'icon'=>'♻️'],
    'destroy'      => ['ar'=>'إتلاف',         'en'=>'Destroy',       'icon'=>'🔥'],
    'sell'         => ['ar'=>'بيع',           'en'=>'Sell',          'icon'=>'💰'],
    'transfer_out' => ['ar'=>'نقل خارجي',     'en'=>'External Trf.', 'icon'=>'📤'],
];
$reason_cfg = [
    'obsolete'              => 'قديم / مُستبدل',
    'damaged_beyond_repair' => 'تالف / لا يُصلَح',
    'end_of_life'           => 'انتهى عمره الافتراضي',
    'lost'                  => 'مفقود',
    'replaced'              => 'مُستبدل',
    'other'                 => 'سبب آخر',
];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title><?= $rtl?'محضر التخلص — نموذج 9+10':'Disposal Record' ?></title>
<style>
* { box-sizing: border-box; }
body { font-family: 'Tajawal','Cairo','Segoe UI',Tahoma,sans-serif; margin: 0; padding: 0; background: #f1f5f9; color: #0f172a; }
.page { width: 210mm; min-height: 297mm; margin: 12mm auto; background: #fff; padding: 14mm 16mm; box-shadow: 0 4px 20px rgba(0,0,0,.08); position: relative; }
@media print {
  body { background: #fff; }
  .page { box-shadow: none; margin: 0; padding: 10mm 12mm; }
  .no-print { display: none !important; }
}
.hdr { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px double #0f172a; padding-bottom: 10px; margin-bottom: 14px; }
.hdr .logo { width: 70px; height: 70px; border: 2px solid #0f172a; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; text-align: center; line-height: 1.2; }
.hdr .title { text-align: center; flex: 1; }
.hdr .title h1 { margin: 0 0 4px; font-size: 18px; font-weight: 800; }
.hdr .title .sub { font-size: 12.5px; color: #475569; }
.hdr .ref { font-size: 11.5px; text-align: left; }
.hdr .ref b { color: #0f172a; }

.section { margin-bottom: 12px; }
.section h3 { font-size: 13px; font-weight: 800; color: #fff; background: #0f172a; padding: 5px 12px; border-radius: 4px; margin: 0 0 8px; display: inline-block; }
.tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
.tbl th, .tbl td { border: 1.2px solid #0f172a; padding: 6px 9px; text-align: right; vertical-align: top; }
.tbl th { background: #f1f5f9; font-weight: 700; width: 25%; font-size: 11.5px; }
.tbl td { background: #fff; }

.type-stamp { display: inline-block; padding: 4px 14px; border: 2.5px solid #0f172a; border-radius: 4px; font-weight: 800; font-size: 14px; background: #fef3c7; }

.sig { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-top: 18px; page-break-inside: avoid; }
.sig-box { border: 1.5px solid #0f172a; border-radius: 6px; padding: 8px 10px; min-height: 88px; }
.sig-box .role { font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 6px; }
.sig-box .line { border-bottom: 1.5px solid #0f172a; height: 30px; margin-bottom: 4px; }
.sig-box .nm { font-size: 10.5px; color: #64748b; }

.footer { position: absolute; bottom: 8mm; left: 16mm; right: 16mm; text-align: center; font-size: 10.5px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 5px; }
@media print { .footer { position: fixed; } }

.print-bar { position: fixed; top: 10px; left: 10px; display: flex; gap: 6px; z-index: 9999; }
.print-bar button { padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 12px; cursor: pointer; border: none; }
.print-bar .p { background: #0f172a; color: #fff; }
.print-bar .b { background: #e2e8f0; color: #475569; }

.reg-tbl { width: 100%; border-collapse: collapse; font-size: 11.5px; margin-top: 6px; }
.reg-tbl th, .reg-tbl td { border: 1px solid #475569; padding: 5px 7px; text-align: right; }
.reg-tbl th { background: #f1f5f9; font-weight: 700; }
.reg-tbl .tag { font-family: 'Courier New', monospace; }
</style>
</head>
<body>

<div class="print-bar no-print">
  <button class="p" onclick="window.print()"><i class="fa-solid fa-print"></i> طباعة</button>
  <button class="b" onclick="window.close()">إغلاق</button>
</div>

<?php if ($print_mode === 'single'): ?>
<?php $tc = $type_cfg[$rec['disposal_type']]; ?>
<div class="page">
  <!-- ═══ Header ═══ -->
  <div class="hdr">
    <div class="logo">شعار<br>المستشفى</div>
    <div class="title">
      <h1>محضر قرار التخلص من أصل</h1>
      <div class="sub">Asset Disposal Decision Record &nbsp;·&nbsp; نموذج 9 + 10</div>
    </div>
    <div class="ref">
      <div><b>رقم القرار:</b> #<?= str_pad($rec['id'], 6, '0', STR_PAD_LEFT) ?></div>
      <div><b>التاريخ:</b> <?= $rec['disposal_date'] ?></div>
      <?php if ($rec['committee_reference']): ?>
      <div><b>مرجع اللجنة:</b> <?= e($rec['committee_reference']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══ القسم 1: نوع القرار ═══ -->
  <div class="section" style="text-align:center">
    <div class="type-stamp"><?= $tc['icon'] ?> <?= $tc['ar'] ?> &nbsp;·&nbsp; <?= $tc['en'] ?></div>
  </div>

  <!-- ═══ القسم 2: بيانات الأصل ═══ -->
  <div class="section">
    <h3>أولاً: بيانات الأصل</h3>
    <table class="tbl">
      <tr>
        <th>الاسم / الوصف</th>
        <td colspan="3"><b><?= e($rec['description']) ?></b><?= $rec['description_ar'] ? ' &nbsp;·&nbsp; '.e($rec['description_ar']) : '' ?></td>
      </tr>
      <tr>
        <th>رقم التاج</th>
        <td><b style="font-family:monospace"><?= e($rec['tag_number']) ?></b></td>
        <th>السيريال</th>
        <td><?= e($rec['serial_number'] ?: '—') ?></td>
      </tr>
      <tr>
        <th>الشركة المصنعة</th>
        <td><?= e($rec['manufacturer_name'] ?: '—') ?></td>
        <th>الموديل</th>
        <td><?= e($rec['model_number'] ?: '—') ?></td>
      </tr>
      <tr>
        <th>الموقع</th>
        <td colspan="3"><?= e(trim(($rec['loc_building'] ?? '').' / '.($rec['loc_floor'] ?? '').' / '.($rec['loc_room'] ?? ''), ' /')) ?: '—' ?></td>
      </tr>
      <tr>
        <th>المسؤول السابق</th>
        <td><?= e($rec['cu_name'] ?: '—') ?></td>
        <th>القسم</th>
        <td><?= e($rec['cd_name'] ?: '—') ?></td>
      </tr>
      <tr>
        <th>تاريخ العهدة</th>
        <td><?= e($rec['custody_date'] ?: '—') ?></td>
        <th>التكلفة</th>
        <td><b><?= number_format((float)$rec['cost'], 2) ?> ر.س</b></td>
      </tr>
    </table>
  </div>

  <!-- ═══ القسم 3: قرار التخلص ═══ -->
  <div class="section">
    <h3>ثانياً: قرار التخلص</h3>
    <table class="tbl">
      <tr>
        <th>نوع القرار</th>
        <td colspan="3"><b><?= $tc['ar'] ?> (<?= $tc['en'] ?>)</b></td>
      </tr>
      <tr>
        <th>السبب</th>
        <td><?= $reason_cfg[$rec['reason']] ?? $rec['reason'] ?></td>
        <th>تاريخ التنفيذ</th>
        <td><b><?= $rec['disposal_date'] ?></b></td>
      </tr>
      <?php if ($rec['reason_notes']): ?>
      <tr>
        <th>تفاصيل السبب</th>
        <td colspan="3"><?= e($rec['reason_notes']) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($rec['disposal_value'] > 0): ?>
      <tr>
        <th>قيمة البيع</th>
        <td colspan="3"><b style="color:#16a34a"><?= number_format($rec['disposal_value'], 2) ?> ر.س</b></td>
      </tr>
      <?php endif; ?>
      <?php if ($rec['notes']): ?>
      <tr>
        <th>ملاحظات</th>
        <td colspan="3"><?= e($rec['notes']) ?></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- ═══ القسم 4: اللجنة ═══ -->
  <div class="section">
    <h3>ثالثاً: قرار اللجنة</h3>
    <table class="tbl">
      <tr>
        <th>رقم محضر اللجنة</th>
        <td><?= e($rec['committee_reference'] ?: '—') ?></td>
        <th>تاريخ المحضر</th>
        <td><?= e($rec['committee_date'] ?: '—') ?></td>
      </tr>
      <tr>
        <th>رئيس اللجنة</th>
        <td colspan="3"><?= e($rec['committee_chairman'] ?: '—') ?></td>
      </tr>
      <tr>
        <th>أعضاء اللجنة</th>
        <td colspan="3" style="white-space:pre-wrap;min-height:48px"><?= e($rec['committee_members'] ?: '—') ?></td>
      </tr>
      <?php if ($rec['decision_doc_number']): ?>
      <tr>
        <th>رقم وثيقة القرار الرسمي</th>
        <td colspan="3"><?= e($rec['decision_doc_number']) ?></td>
      </tr>
      <?php endif; ?>
    </table>
  </div>

  <!-- ═══ القسم 5: التدقيق ═══ -->
  <div class="section">
    <h3>رابعاً: التدقيق</h3>
    <table class="tbl">
      <tr>
        <th>سجل بواسطة</th>
        <td><?= e($rec['created_by_name']) ?></td>
        <th>تاريخ التسجيل</th>
        <td><?= e($rec['created_at']) ?></td>
      </tr>
    </table>
  </div>

  <!-- ═══ التوقيعات ═══ -->
  <div class="sig">
    <div class="sig-box">
      <div class="role">رئيس اللجنة</div>
      <div class="line"></div>
      <div class="nm">الاسم: <?= e($rec['committee_chairman'] ?: '________') ?></div>
      <div class="nm">التاريخ: ____/____/________</div>
    </div>
    <div class="sig-box">
      <div class="role">عضو اللجنة (1)</div>
      <div class="line"></div>
      <div class="nm">الاسم: ________________</div>
      <div class="nm">التاريخ: ____/____/________</div>
    </div>
    <div class="sig-box">
      <div class="role">عضو اللجنة (2)</div>
      <div class="line"></div>
      <div class="nm">الاسم: ________________</div>
      <div class="nm">التاريخ: ____/____/________</div>
    </div>
    <div class="sig-box">
      <div class="role">مدير إدارة الأصول</div>
      <div class="line"></div>
      <div class="nm">الاسم: ________________</div>
      <div class="nm">التاريخ: ____/____/________</div>
    </div>
  </div>

  <div class="footer">
    أُنشئ آلياً من نظام إدارة الأصول PMSH &nbsp;·&nbsp; <?= date('Y-m-d H:i') ?> &nbsp;·&nbsp; رقم القرار: <?= str_pad($rec['id'], 6, '0', STR_PAD_LEFT) ?>
  </div>
</div>

<?php else: ?>
<!-- ═══ سجل كامل ═══ -->
<div class="page">
  <div class="hdr">
    <div class="logo">شعار<br>المستشفى</div>
    <div class="title">
      <h1>سجل قرارات التخلص من الأصول</h1>
      <div class="sub">Asset Disposal Register &nbsp;·&nbsp; نموذج 9 + 10</div>
    </div>
    <div class="ref">
      <div><b>عدد السجلات:</b> <?= count($rows) ?></div>
      <div><b>تاريخ الطباعة:</b> <?= date('Y-m-d') ?></div>
    </div>
  </div>

  <table class="reg-tbl">
    <thead>
      <tr>
        <th>#</th>
        <th>التاريخ</th>
        <th>النوع</th>
        <th>التاج</th>
        <th>اسم الأصل</th>
        <th>السبب</th>
        <th>مرجع اللجنة</th>
        <th>القرار #</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $i => $r): $tc = $type_cfg[$r['disposal_type']]; ?>
      <tr>
        <td><?= $i+1 ?></td>
        <td><?= $r['disposal_date'] ?></td>
        <td><?= $tc['icon'] ?> <?= $tc['ar'] ?></td>
        <td class="tag"><?= e($r['tag_number']) ?></td>
        <td><?= e(truncate($r['description'] ?? '', 40)) ?></td>
        <td><?= $reason_cfg[$r['reason']] ?? $r['reason'] ?></td>
        <td><?= e($r['committee_reference'] ?: '—') ?></td>
        <td class="tag">#<?= str_pad($r['id'], 6, '0', STR_PAD_LEFT) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sig" style="margin-top:32px">
    <div class="sig-box">
      <div class="role">مدير إدارة الأصول</div>
      <div class="line"></div>
      <div class="nm">الاسم / التاريخ</div>
    </div>
    <div class="sig-box">
      <div class="role">مراقب المخزون</div>
      <div class="line"></div>
      <div class="nm">الاسم / التاريخ</div>
    </div>
    <div class="sig-box">
      <div class="role">المدير التنفيذي</div>
      <div class="line"></div>
      <div class="nm">الاسم / التاريخ</div>
    </div>
    <div class="sig-box">
      <div class="role">مدير عام المستشفى</div>
      <div class="line"></div>
      <div class="nm">الاسم / التاريخ</div>
    </div>
  </div>

  <div class="footer">
    أُنشئ آلياً من نظام إدارة الأصول PMSH &nbsp;·&nbsp; <?= date('Y-m-d H:i') ?>
  </div>
</div>
<?php endif; ?>

<?php if (($_GET['autoprint'] ?? '') === '1'): ?>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
<?php endif; ?>

</body>
</html>
