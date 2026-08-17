<?php
/**
 * commissioning/print.php — طباعة شهادة التركيب والتشغيل (مطابقة للنموذج الرسمي 100% بناءً على الصور المرفقة)
 */
require_once dirname(__DIR__) . '/config.php';
require_login();

$id=(int)($_GET['id']??0);
if(!$id) die('ID required');
$s=$pdo->prepare("SELECT cc.*, rm.minute_number, rm.maintenance_type, rm.standing_committee_id, rm.doc_number, rm.doc_date, rm.doc_date_hijri, d.name AS dept_name
    FROM commissioning_certificates cc
    LEFT JOIN receiving_minutes rm ON rm.id=cc.receiving_minute_id
    LEFT JOIN departments d ON d.id=cc.department_id
    WHERE cc.id=? LIMIT 1");
$s->execute([$id]); $cc=$s->fetch();
if(!$cc) die('Not found');

$warehouse_keeper_uid = (int)get_setting('warehouse_keeper_user_id', 0);
$warehouse_keeper_name = null;
if($warehouse_keeper_uid){
    $wk=$pdo->prepare("SELECT full_name FROM users WHERE id=?"); $wk->execute([$warehouse_keeper_uid]); $warehouse_keeper_name=$wk->fetchColumn();
}

$sigs=['رئيس'=>null,'عضو فني'=>null];
if($cc['standing_committee_id']){
    $sm=$pdo->prepare("SELECT scm.role, u.full_name FROM standing_committee_members scm JOIN users u ON u.id=scm.user_id WHERE scm.committee_id=? ORDER BY scm.sort_order");
    $sm->execute([$cc['standing_committee_id']]);
    foreach($sm->fetchAll() as $r) $sigs[$r['role']]=$r['full_name'];
}
$recv_name=null;
if($cc['department_id']){
    $cur=$cc['department_id']; $hops=0;
    while($cur && $hops<5){
        $d=$pdo->prepare("SELECT manager_id,parent_id FROM departments WHERE id=?"); $d->execute([$cur]); $row=$d->fetch();
        if(!$row) break;
        if($row['manager_id']){ $u=$pdo->prepare("SELECT full_name FROM users WHERE id=?"); $u->execute([$row['manager_id']]); $recv_name=$u->fetchColumn(); break; }
        $cur=$row['parent_id']; $hops++;
    }
}

$hospital='مستشفى الأمير مشاري بن سعود';
$logo_url='https://upload.wikimedia.org/wikipedia/ar/thumb/f/fe/Saudi_Ministry_of_Health_Logo.svg/960px-Saudi_Ministry_of_Health_Logo.svg.png';

$sig_rows = [
    ['right_role'=>'مدير إدارة التجهيزات والإحلال بالمنطقة','right_name'=>$cc['regional_equipment_mgr_name'],
     'left_role'=>'رئيس القسم المستلم للجهاز','left_name'=>$recv_name],
    ['right_role'=>'رئيس قسم الصيانة بالموقع / المنطقة','right_name'=>$sigs['عضو فني'],
     'left_role'=>'أمين المستودع بالموقع','left_name'=>$warehouse_keeper_name],
    ['right_role'=>'مندوب الشركة الموردة','right_name'=>$cc['representative_name'],
     'left_role'=>'مدير المستشفى / المركز','left_name'=>$sigs['رئيس']],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>شهادة تشغيل <?= e($cc['certificate_number']??'') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Tajawal', 'Arial', sans-serif;}
body{background:#fff;padding:6px;font-size:12.5px;color:#000;}
.print-btn{text-align:center;margin-bottom:8px;display:flex;justify-content:center;gap:8px}
.print-btn button{padding:5px 14px;border:none;border-radius:6px;font-family:'Tajawal';font-size:12.5px;font-weight:700;cursor:pointer}
.sheet{max-width:740px;margin:0 auto;min-height:970px;display:flex;flex-direction:column}

.topbar{display:table;width:100%;table-layout:fixed;margin-bottom:8px}
.topbar .cell{display:table-cell;vertical-align:middle}
.topbar .cell-right{text-align:center;width:30%;font-size:13px;font-weight:bold;line-height:1.6}
.topbar .cell-mid{text-align:center;width:40%;}
.topbar .cell-mid img{height:65px}
.topbar .cell-left{text-align:right;width:30%;font-size:13px;font-weight:bold;padding-right:15px}

.header-field { display: flex; align-items: baseline; margin-bottom: 5px; }
.header-field .label { display: inline-block; width: 65px; text-align: right; }
.header-field .colon { margin: 0 4px; }
.header-field .dots { flex: 1; border-bottom: 1.5px dotted #000; position: relative; top: -3px; }

.frame{border:1.5px solid #000;border-radius:18px;padding:12px 18px;flex:1;display:flex;flex-direction:column;margin-top:2px}

.title{font-size:19px;font-weight:900;text-align:center;text-decoration:underline;margin-bottom:14px}
.intro-line{font-size:13.5px;line-height:2.0;font-weight:700;margin-bottom:4px;display:flex;flex-wrap:wrap;align-items:baseline}
.intro-line.nowrap { white-space: nowrap; flex-wrap: nowrap; }
.intro-line .fill{border-bottom:1px dotted #000;display:inline-block;margin:0 4px}
.intro-block{margin-bottom:10px}

.date-gap { display: inline-block; width: 22px; text-align: center; }
.date-gap-yr { display: inline-block; width: 32px; text-align: center; }

table.items{width:100%;border-collapse:collapse;margin-bottom:12px}
table.items th,table.items td{border:1px solid #000;padding:5px;text-align:center;font-size:12px}
table.items th{font-weight:bold;background:#fff}

.clause{font-size:13.5px;line-height:1.9;margin-bottom:8px;font-weight:700}
.box{display:inline-block;width:13px;height:13px;border:1px solid #000;margin-right:8px;margin-left:20px;vertical-align:middle}

table.sigs{width:100%;border-collapse:collapse;margin-top:auto}
table.sigs td.sig-col{padding:4px 0;vertical-align:top;width:45%}
table.sigs td.spacer{width:10%}

.sig-role{font-weight:900;font-size:13.5px;margin-bottom:8px;text-align:right}
.sig-line{display:flex;align-items:baseline;font-size:13px;font-weight:700;margin-bottom:6px}
.sig-line .lbl{width:45px;text-align:right}
.sig-line .colon{margin:0 4px}
.sig-line .val{flex:1;border-bottom:1.5px dotted #000;min-height:16px;text-align:center}
.sig-line .val-date{flex:1;min-height:16px;text-align:right}

.stamp{margin-top:12px;font-size:13.5px;font-weight:bold}
.note{margin-top:8px;font-size:11.5px;line-height:1.5;font-weight:bold;border-top:1px dashed #000;padding-top:6px}

@media print{
    body{padding:0}
    .print-btn{display:none}
    @page{margin:0.4cm 0.6cm;size:A4 portrait}
}
</style>
</head>
<body>
<div class="print-btn">
  <button onclick="window.print()" style="background:#2563eb;color:#fff">🖨️ طباعة</button>
  <button onclick="window.close()" style="background:#f1f5f9;color:#475569">✕ إغلاق</button>
</div>

<div class="sheet">

  <div class="topbar">
    <div class="cell cell-right">
      <div>المملكة العربية السعودية</div>
      <div>وزارة الصحة</div>
    </div>
    <div class="cell cell-mid">
      <img src="<?= $logo_url ?>" alt="شعار وزارة الصحة" onerror="this.style.display='none'">
    </div>
    <div class="cell cell-left">
      <div class="header-field">
        <span class="label">الـرقـــــــم</span><span class="colon">:</span><span class="dots"></span>
      </div>
      <div class="header-field">
        <span class="label">التـاريـــــخ</span><span class="colon">:</span><span class="dots"></span>
      </div>
      <div class="header-field">
        <span class="label">المشفوعات</span><span class="colon">:</span><span class="dots"></span>
      </div>
    </div>
  </div>

  <div class="frame">

    <div class="title">شهادة توريد وتركيب وتشغيل</div>

    <div class="intro-block">
      <div class="intro-line">
        تشهد إدارة مستشفى / مركز صحي : <b><?= e($hospital) ?></b>
        <span class="fill" style="flex:1;min-width:40px"></span>
      </div>
      <div class="intro-line nowrap">
        أنه في يوم ( <span class="fill" style="width:85px;text-align:center"></span> ) 
        بتاريخ <span class="date-gap"></span> / <span class="date-gap"></span> / <span class="date-gap-yr"></span> ١٤هـ 
        الموافق : <span class="date-gap"></span> / <span class="date-gap"></span> / <span class="date-gap-yr"></span> ٢٠م.
      </div>
      <div class="intro-line">
        قام مندوب شركة / مؤسسة <span class="fill" style="width:170px"></span> 
        السيد / <b><?= e($cc['representative_name']??'') ?></b>
        <span class="fill" style="flex:1;min-width:40px"></span>
      </div>
      <div class="intro-line">بتوريد وتركيب جهاز / الأجهزة / الموضحة أدناه :</div>
      <div class="intro-line nowrap">
        بموجب التعميد / العقد رقم ( <b><?= e($cc['doc_number']??'') ?></b> ) 
        بتاريخ <b><?= e($cc['doc_date_hijri']??'') ?></b>
        الموافق <b class="eng-num"><?= e($cc['doc_date']??'') ?></b>م.
      </div>
    </div>

    <table class="items">
      <thead><tr>
        <th style="width:30px">م</th>
        <th>اسم البند</th>
        <th style="width:50px">العدد</th>
        <th>الشركة الصانعة</th>
        <th>الموديل</th>
        <th>الرقم التسلسلي</th>
      </tr></thead>
      <tbody>
        <?php for($i=1;$i<=4;$i++): ?>
        <tr>
          <td><?= $i ?></td>
          <?php if($i==1): ?>
          <td style="font-weight:bold"><?= e($cc['device_description']??'') ?></td>
          <td><?= e($cc['quantity']??'') ?></td>
          <td><?= e($cc['manufacturer_name']??'') ?></td>
          <td><?= e($cc['model_number']??'') ?></td>
          <td style="font-family:monospace" dir="ltr"><?= e($cc['serial_number']??'') ?></td>
          <?php else: ?>
          <td></td><td></td><td></td><td></td><td></td>
          <?php endif; ?>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <div class="clause">
      1 - وبعد مراجعة ومطابقة الشروط والمواصفات الفنية اتضح ان ( الجهاز / النظام) مطابق للشروط والمواصفات الفنية
      <br>
      &nbsp;&nbsp; مطابق <span class="box" style="background:<?= ($cc['spec_match']??0)?'#000':'#fff' ?>"></span>
      &nbsp;&nbsp; غير مطابق <span class="box" style="background:<?= !($cc['spec_match']??0)?'#000':'#fff' ?>"></span>
    </div>
    <div class="clause">
      ٢ - تم إستلام عدد ( <b style="display:inline-block;width:20px;text-align:center"><?= e($cc['operations_catalogs_count']??'') ?></b> ) من كتالوجات التشغيل وعدد ( <b style="display:inline-block;width:20px;text-align:center"><?= e($cc['maintenance_catalogs_count']??'') ?></b> ) من كتالوجات الخدمة وقطع الغيار وعدد ( <b style="display:inline-block;width:20px;text-align:center"><?= e($cc['cd_count']??0) ?></b> ) CD
    </div>
    
    <div class="clause">
      ٣ - تبدأ فترة الضمان المجانية اعتبارا من تاريخ تشغيل الجهاز / النظام في 
      <b><?= e($cc['warranty_start_hijri']??'') ?></b>
      الموافق <b class="eng-num"><?= e($cc['warranty_start']??'') ?></b>م.
      ولمدة ( <span style="display:inline-block;width:25px;text-align:center"><?= e($cc['warranty_years']??'') ?></span> )سنة
    </div>

    <div style="text-align:center;font-weight:900;font-size:14px;margin:8px 0">وعليه جرى التوقيع ، ،</div>

    <table class="sigs" dir="rtl">
      <?php foreach($sig_rows as $row): ?>
      <tr dir="rtl">
        <td class="sig-col" dir="rtl">
          <div class="sig-role"><?= e($row['right_role']) ?></div>
          <div class="sig-line"><span class="lbl">الاسم</span><span class="colon">:</span><span class="val"><?= e($row['right_name']??'') ?></span></div>
          <div class="sig-line"><span class="lbl">التوقيع</span><span class="colon">:</span><span class="val"></span></div>
          <div class="sig-line"><span class="lbl">التاريخ</span><span class="colon">:</span><span class="val-date">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ١٤هـ</span></div>
        </td>
        <td class="spacer"></td>
        <td class="sig-col" dir="rtl">
          <div class="sig-role"><?= e($row['left_role']) ?></div>
          <div class="sig-line"><span class="lbl">الاسم</span><span class="colon">:</span><span class="val"><?= e($row['left_name']??'') ?></span></div>
          <div class="sig-line"><span class="lbl">التوقيع</span><span class="colon">:</span><span class="val"></span></div>
          <div class="sig-line"><span class="lbl">التاريخ</span><span class="colon">:</span><span class="val-date">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; / &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ١٤هـ</span></div>
          <?php if($row['left_role'] === 'مدير المستشفى / المركز'): ?>
             <div class="stamp">ختم المستشفى / المركز.</div>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <div class="note">
      ملاحظة : يتم صرف مستحقات المقاول لقاء التوريد والتركيب فقط وتحجز قيمة الصيانة إذا كانت لها مبالغ منفصلة وكذلك قيمة الضمان حتى نهاية فترة الضمان والصيانة.
    </div>

  </div>
</div>
</body>
</html>