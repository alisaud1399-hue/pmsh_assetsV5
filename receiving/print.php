<?php
/**
 * receiving/print.php — طباعة محضر الاستلام (نموذج رقم 3) — (النسخة الآمنة - RBAC)
 */
require_once dirname(__DIR__) . '/config.php';
require_login();
// 🔒 التحقق الصارم من الصلاحيات لطباعة المحضر
$can_print = can('receiving.form', 'print') || can('receiving.index', 'print');

if (!$can_print) {
    die('<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@700&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head><body style="background:#f8fafc; padding:40px; font-family:\'Tajawal\',sans-serif;"><div style="text-align:center; padding:50px 30px; background:#fff; border:1px solid #e2e8f0; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.05); max-width:500px; margin:0 auto;"><div style="width:70px; height:70px; background:#fef2f2; color:#ef4444; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:30px; margin:0 auto 20px;"><i class="fa-solid fa-lock"></i></div><h2 style="color:#0f172a; margin-bottom:10px;">صلاحيات غير كافية</h2><p style="color:#64748b; font-size:14px; line-height:1.6; margin-bottom:20px;">حسب الإجراء المتبع، لا تمتلك الصلاحية لاستعراض أو طباعة هذا المحضر.</p><button onclick="window.close()" style="background:#f1f5f9; color:#475569; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;">إغلاق النافذة</button></div></body></html>');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('ID required');

$s=$pdo->prepare("SELECT * FROM receiving_minutes WHERE id=? LIMIT 1");
$s->execute([$id]); $rn=$s->fetch();
if(!$rn) die('Not found');

$items=$pdo->prepare("SELECT * FROM receiving_minute_items WHERE minute_id=? AND (parent_item_id IS NULL OR parent_item_id=0) ORDER BY sequence_no,id");
$items->execute([$id]); $main_items=$items->fetchAll();

// ── دمج الأجهزة الرئيسية المتطابقة (نفس الوصف+الشركة+الموديل+الوحدة) في صف واحد ──
$grouped=[]; 
foreach($main_items as $mi){
    $key = mb_strtolower(trim(($mi['description']??'').'|'.($mi['manufacturer_name']??'').'|'.($mi['model_number']??'').'|'.($mi['unit']??'')));
    if(!isset($grouped[$key])){
        $grouped[$key]=$mi; $grouped[$key]['orig_ids']=[$mi['id']]; $grouped[$key]['serials']=[];
    } else {
        $grouped[$key]['quantity']+=$mi['quantity'];
        $grouped[$key]['vat_amount']+=$mi['vat_amount'];
        $grouped[$key]['total_price']+=$mi['total_price'];
        $grouped[$key]['orig_ids'][]=$mi['id'];
    }
}
// جلب وضم السيريالات لكل مجموعة
foreach($grouped as $key=>&$g){
    foreach($g['orig_ids'] as $oid){
        $sr=$pdo->prepare("SELECT serial_number FROM receiving_item_serials WHERE item_id=? AND serial_number IS NOT NULL AND serial_number!=''");
        $sr->execute([$oid]); foreach($sr->fetchAll(PDO::FETCH_COLUMN) as $sn) $g['serials'][]=$sn;
    }
    $g['serial_nos']=implode(', ',$g['serials']);
}
unset($g);

// ── دمج الملحقات المتطابقة (نفس الوصف) التابعة لنفس المجموعة المُدمجة ──
$all_items=[];
foreach($grouped as $g){
    $all_items[]=$g;
    $acc_grouped=[];
    foreach($g['orig_ids'] as $oid){
        $a=$pdo->prepare("SELECT * FROM receiving_minute_items WHERE parent_item_id=? ORDER BY sequence_no");
        $a->execute([$oid]);
        foreach($a->fetchAll() as $ac){
            if(($ac['item_code']??'') === 'WARRANTY') continue; // الضمان الأساسي: لا يُكتب في النموذج الرسمي، فقط الإضافي/التمديد
            $akey = mb_strtolower(trim($ac['description']??''));
            if(!isset($acc_grouped[$akey])) $acc_grouped[$akey]=$ac;
            else {
                $acc_grouped[$akey]['quantity']+=$ac['quantity'];
                $acc_grouped[$akey]['vat_amount']+=$ac['vat_amount'];
                $acc_grouped[$akey]['total_price']+=$ac['total_price'];
            }
        }
    }
    foreach($acc_grouped as $ac) $all_items[]=$ac;
}

// أعضاء اللجنة الثابتة الفعّالة لهذا المحضر
$sigs=[]; $main_dept_id=null; $recv_count=0;
if($rn['standing_committee_id']){
    $sm=$pdo->prepare("
        SELECT scm.role, u.full_name, u.job_title
        FROM standing_committee_members scm
        JOIN users u ON u.id=scm.user_id
        WHERE scm.committee_id=?
        ORDER BY scm.sort_order
    ");
    $sm->execute([$rn['standing_committee_id']]); $sigs=$sm->fetchAll();
}

// عدد الأقسام المستلمة الفريدة
$dq=$pdo->prepare("SELECT DISTINCT department_id, MAX(id) as did FROM receiving_minute_items WHERE minute_id=? AND department_id IS NOT NULL GROUP BY department_id");
$dq->execute([$id]); $depts=$dq->fetchAll();
$recv_count=count($depts);
$recv_name=null; $recv_job=null;
if($recv_count===1){
    // ✅ الأولوية: المستلم الفعلي من receiving_minute_items (receiver_name + receiver_title)
    // fallback: رئيس القسم (إذا ما تم تحديد receiver)
    $rr=$pdo->prepare("SELECT receiver_name, receiver_title, receiver_user_id
        FROM receiving_minute_items
        WHERE minute_id=? AND department_id=?
        ORDER BY (receiver_user_id IS NOT NULL) DESC, id ASC LIMIT 1");
    $rr->execute([$id, $depts[0]['department_id']]);
    $actual = $rr->fetch();
    if($actual && !empty($actual['receiver_name'])){
        $recv_name = $actual['receiver_name'];
        $recv_job  = $actual['receiver_title'] ?: null;
    } else {
        // fallback: dept manager
        $dd=$pdo->prepare("SELECT u.full_name,u.job_title FROM departments d LEFT JOIN users u ON u.id=d.manager_id WHERE d.id=?");
        $dd->execute([$depts[0]['department_id']]); $r=$dd->fetch();
        $recv_name=$r['full_name']??null; $recv_job=$r['job_title']??null;
    }
}

$hospital  = get_setting('hospital_name','مستشفى الأمير مشاري بن سعود');
$role_labels=['رئيس'=>'الرئيس المسؤول','عضو فني'=>'العضو الفني'];
$mtype_labels=['medical'=>'صيانة طبية','general'=>'صيانة عامة','it'=>'تقنية المعلومات'];

$subtotal=0; $vat_total=0;
foreach($all_items as $it){ $subtotal+=(float)($it['unit_price']??0)*(float)($it['quantity']??0); $vat_total+=(float)($it['vat_amount']??0); }
$grand_total=$subtotal+$vat_total;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>محضر استلام <?= e($rn['minute_number']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Tajawal',sans-serif}
body{background:#fff;padding:12px;font-size:12px;color:#000}
.print-btn{text-align:center;margin-bottom:10px;display:flex;justify-content:center;gap:8px}
.print-btn button{padding:7px 18px;border:none;border-radius:6px;font-family:'Tajawal';font-size:13px;font-weight:700;cursor:pointer}
.page{border:2px solid #000;max-width:1200px;margin:0 auto}
.header{display:grid;grid-template-columns:220px 1fr 220px;border-bottom:2px solid #000}
.header-right,.header-left{padding:8px 10px;font-size:11px;line-height:1.9}
.header-center{border-right:1px solid #000;border-left:1px solid #000;padding:8px;text-align:center}
.form-title{font-size:22px;font-weight:900;margin:4px 0}
.form-sub{font-size:11px}
.receipt-num-box{border:2px solid #000;display:inline-block;padding:3px 16px;font-size:15px;font-weight:800;margin-top:4px;font-family:monospace}
.supplier-bar{padding:6px 10px;border-bottom:1px solid #000;font-size:12px;display:flex;gap:20px;flex-wrap:wrap}
.supplier-bar .lbl{font-weight:700}
table{width:100%;border-collapse:collapse}
thead th{border:1px solid #000;padding:5px 4px;text-align:center;font-size:11px;font-weight:800;background:#f0f0f0;line-height:1.3}
tbody td{border:1px solid #000;padding:4px 5px;font-size:11px;vertical-align:middle}
tbody td.c{text-align:center}
tbody td.bold{font-weight:900;font-size:12px}
tr.empty-row td{height:22px}
tr.total-row{background:#f5f5f5}
tr.total-row td{font-weight:900;font-size:12px}
tr.sub-row td{background:#fafafa;font-style:italic}
.sigs-section{border-top:2px solid #000}
.sigs-title{text-align:center;font-size:11px;font-weight:700;padding:4px;border-bottom:1px solid #000;background:#f8f8f8}
.sig-box{padding:8px 10px;border-left:1px solid #000;min-height:75px}
.sig-box:last-child{border-left:none}
.sig-role{font-size:11px;font-weight:800;margin-bottom:4px;border-bottom:1px dotted #ccc;padding-bottom:3px}
.sig-name{font-size:11px;font-weight:700;color:#1a1a8c;min-height:18px}
.sig-dept{font-size:10px;color:#555;margin-top:2px}
.sig-area{border-top:1px dotted #ccc;margin-top:8px;padding-top:4px;min-height:24px}
.sig-fields{display:flex;justify-content:space-between;font-size:9px;color:#555;margin-top:5px;border-top:1px dotted #eee;padding-top:2px}
.footer-bar{padding:4px 10px;border-top:1px solid #000;font-size:10px;text-align:center;color:#555;background:#f8f8f8}
@media print{body{padding:2px}.print-btn{display:none!important}@page{margin:0.6cm;size:A4 landscape}}
</style>
</head>
<body>
<div class="print-btn">
  <button onclick="window.print()" style="background:#2563eb;color:#fff">🖨️ طباعة</button>
  <button onclick="window.close()" style="background:#f1f5f9;color:#475569">✕ إغلاق</button>
</div>

<div class="page">
  <div class="header">
    <div class="header-right">
      <div style="font-weight:900;font-size:12px">المملكة العربية السعودية</div>
      <div>وزارة المالية</div>
      <div style="margin-top:3px;font-weight:700">وزارة الصحة</div>
      <div>تجمع الباحة الصحي</div>
      <div style="font-weight:700"><?= e($hospital) ?></div>
    </div>
    <div class="header-center">
      <div class="form-sub">نموذج رقم (3) — مدخل بيانات داخلي</div>
      <div class="form-title">محضر استلام</div>
      <div class="form-sub" style="margin-bottom:4px">Received Voucher</div>
      <div class="receipt-num-box"><?= e($rn['minute_number']) ?></div>
    </div>
    <div class="header-left" style="text-align:right">
      <div><span style="font-weight:700;font-size:10px">جهة الصيانة:</span> <?= e($mtype_labels[$rn['maintenance_type']??'']??'—') ?></div>
      <div><span style="font-weight:700;font-size:10px">تاريخ الاستلام:</span> <strong><?= e($rn['receipt_date']??substr($rn['created_at']??'',0,10)) ?></strong></div>
      <div><span style="font-weight:700;font-size:10px">عدد الصفحات:</span> <?= e($rn['pages_count']??1) ?></div>
    </div>
  </div>

  <div class="supplier-bar">
    <div><span class="lbl">المـورد:</span> <?= e($rn['supplier_name']??'—') ?></div>
    <?php if($rn['doc_type']): ?><div><span class="lbl">مصدر التوريد:</span> <?= e($rn['doc_type']) ?></div><?php endif; ?>
    <?php if($rn['doc_number']): ?><div><span class="lbl">رقم المستند:</span> <?= e($rn['doc_number']) ?></div><?php endif; ?>
    <?php if($rn['doc_date']): ?><div><span class="lbl">تاريخه:</span> <?= e($rn['doc_date']) ?></div><?php endif; ?>
  </div>

  <table>
    <thead><tr>
      <th style="width:26px">م</th>
      <th style="width:65px">رقم الصنف</th>
      <th>اسم الصنف ووصفه</th>
      <th style="width:90px">الشركة / الموديل</th>
      <th style="width:48px">الوحدة</th>
      <th style="width:42px">الكمية</th>
      <th style="width:75px">سعر الوحدة</th>
      <th style="width:65px">الضريبة</th>
      <th style="width:80px">مجموع القيمة</th>
      <th style="width:70px">ملاحظات</th>
    </tr></thead>
    <tbody>
    <?php
    $min_rows=10; $shown=0;
    foreach($all_items as $item):
        $shown++;
        $is_main=(bool)($item['is_main_device']??1);
        $up=(float)($item['unit_price']??0);
        $qty=(float)($item['quantity']??0);
        $vat=(float)($item['vat_amount']??0);
        $tp=(float)($item['total_price']??($up*$qty+$vat));
    ?>
    <tr class="<?= $is_main?'':'sub-row' ?>">
      <td class="c"><?= $shown ?></td>
      <td style="font-size:10px"><?= e($item['item_code']??'') ?></td>
      <td class="<?= $is_main?'bold':'' ?>"><?= e($item['description']) ?></td>
      <td style="font-size:9.5px"><?= e(trim(($item['manufacturer_name']??'').' '.($item['model_number']??''))) ?></td>
      <td class="c"><?= e($item['unit']??'') ?></td>
      <td class="c bold"><?= number_format($qty,($qty==(int)$qty?0:2)) ?></td>
      <td class="c" style="font-family:monospace;font-size:10px"><?= $up>0?number_format($up,2):'' ?></td>
      <td class="c" style="font-family:monospace;font-size:10px"><?= $vat>0?number_format($vat,2):'' ?></td>
      <td class="c bold" style="font-family:monospace;font-size:11px"><?= $tp>0?number_format($tp,2):'' ?></td>
      <td style="font-size:10px"><?= e($item['notes']??'') ?></td>
    </tr>
    <?php endforeach; ?>
    <?php for($e=0;$e<max(0,$min_rows-$shown);$e++): ?>
    <tr class="empty-row"><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
    <?php endfor; ?>
    <tr class="total-row">
      <td colspan="6" style="text-align:center;font-size:12px">القيمة الإجمالية</td>
      <td class="c" style="font-family:monospace"><?= number_format($subtotal,2) ?></td>
      <td class="c" style="font-family:monospace"><?= number_format($vat_total,2) ?></td>
      <td class="c bold" style="font-family:monospace;font-size:12px"><?= number_format($grand_total,2) ?></td>
      <td style="text-align:center;font-size:9.5px">ريال سعودي</td>
    </tr>
    </tbody>
  </table>

  <div class="sigs-section">
    <div class="sigs-title">التوقيعات والاعتمادات — اللجنة الفعّالة وقت الاستلام</div>
    <?php
    $sig_cols = $sigs;
    if($recv_count===1 && $recv_name) $sig_cols[]=['role'=>'مستلم','full_name'=>$recv_name,'job_title'=>$recv_job];
    $cols=count($sig_cols)?:1;
    ?>
    <div style="display:grid;grid-template-columns:<?= implode(' ',array_fill(0,$cols,'1fr')) ?>">
      <?php if($sig_cols): foreach($sig_cols as $sig): ?>
      <div class="sig-box">
        <div class="sig-role"><?= e($role_labels[$sig['role']]??$sig['role']) ?></div>
        <div class="sig-name"><?= e($sig['full_name']??'—') ?></div>
        <div class="sig-dept"><?= e($sig['job_title']??'') ?></div>
        <div class="sig-area"></div>
        <div class="sig-fields"><span>الاسم</span><span>التوقيع</span><span>التاريخ</span></div>
      </div>
      <?php endforeach; else: ?>
      <div class="sig-box" style="text-align:center;color:#999">لا توجد لجنة مرتبطة بهذا المحضر</div>
      <?php endif; ?>
    </div>
    <?php if($recv_count>1): ?>
    <div style="padding:8px 10px;border-top:1px solid #000;font-size:11px;font-weight:700;background:#fffbeb">
      ⚠️ هذا الجهاز موزَّع على <?= $recv_count ?> أقسام — توقيع المستلمين موجود في بيان التوزيع المرفق برقم منفصل، لا في هذا المحضر.
    </div>
    <?php endif; ?>
  </div>

  <?php if($rn['notes']): ?>
  <div style="padding:5px 10px;border-top:1px solid #000;font-size:11px"><strong>ملاحظات:</strong> <?= e($rn['notes']) ?></div>
  <?php endif; ?>

  <div class="footer-bar">نظام إدارة الأصول والصيانة — <?= e($hospital) ?> | تجمع الباحة الصحي | مدخل بيانات داخلي — لا يُعتد به رسمياً حتى تفعيل النظام الإلكتروني الحكومي</div>
</div>
</body>
</html>