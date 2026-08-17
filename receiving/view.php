<?php
/**
 * receiving/view.php — لوحة القيادة التنفيذية لمحضر الاستلام (RBAC & Sticky Totals)
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('receiving.view');

$rtl = is_rtl();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { flash('danger',$rtl?'غير محدد':'Not specified'); header('Location:'.BASE_URL.'/receiving/index.php'); exit; }

$uid = (int)(current_user()['id'] ?? 0);

/* 🔒 الحاجز الحقيقي — receiving.view أعلاه فقط "بوابة عامة"؛ هذا هو
   ما يحسم هل *هذا المحضر بعينه* يخصّ المستخدم فعلاً. view_all (نطاق
   المدير التنفيذي والأدمن عبره is_admin) تتجاوز الفحص بالكامل. */
if (!can('receiving.view', 'view_all')) {
    $rel_q = $pdo->prepare("
        SELECT
            EXISTS(SELECT 1 FROM receiving_minutes rm2
                    WHERE rm2.id=? AND rm2.created_by=?) AS is_creator,
            EXISTS(SELECT 1 FROM receiving_minutes rm3
                    JOIN committee_members cm ON cm.committee_id=rm3.committee_id
                    WHERE rm3.id=? AND cm.user_id=?) AS is_committee,
            EXISTS(SELECT 1 FROM document_approvals da
                    WHERE da.doc_type='receiving_minute' AND da.doc_id=? AND da.user_id=?) AS is_approver,
            EXISTS(SELECT 1 FROM receiving_minute_items rmi
                    JOIN departments d ON d.id=rmi.department_id
                    WHERE rmi.minute_id=? AND d.manager_id=?) AS is_dept_manager,
            EXISTS(SELECT 1 FROM receiving_minute_items rmi2
                    WHERE rmi2.minute_id=? AND rmi2.receiver_user_id=?) AS is_receiver
    ");
    $rel_q->execute([$id,$uid, $id,$uid, $id,$uid, $id,$uid, $id,$uid]);
    $rel = $rel_q->fetch(PDO::FETCH_ASSOC) ?: [];
    $is_relevant = array_filter($rel, fn($v) => (int)$v === 1) !== [];

    /* فرق الصيانة الثلاثة: صلة عبر نوع الجهاز الموزَّع في المحضر —
       نفس منطق is_my_maintenance_type() تماماً بلا تكرار جوهر المنطق */
    if (!$is_relevant) {
        $types_q = $pdo->prepare("
            SELECT DISTINCT asset_type FROM receiving_minute_items
            WHERE minute_id=? AND department_id IS NOT NULL
              AND (parent_item_id IS NULL OR parent_item_id=0)
        ");
        $types_q->execute([$id]);
        foreach ($types_q->fetchAll(PDO::FETCH_COLUMN) as $t) {
            if (is_my_maintenance_type($t)) { $is_relevant = true; break; }
        }
    }

    if (!$is_relevant) abort(403);
}

/* توثيق "اطّلاع" حقيقي — يُسجَّل بمجرد وصول المستخدم لهذه الصفحة
   بالذات (لا نقرة منفصلة من قائمة الجرس)، حتى لو فتحها برابط مباشر.
   لا يمسّ حالة الاعتماد إطلاقاً — سجل اطّلاع بحت، منفصل تماماً. */
$pdo->prepare("
    UPDATE notifications SET is_read=1, read_at=NOW()
    WHERE user_id=? AND related_type='receiving_minute' AND related_id=? AND is_read=0
")->execute([$uid, $id]);

// جلب بيانات المحضر الأساسية
$s = $pdo->prepare("SELECT rm.*, sc.name AS committee_name, u.full_name AS creator_name
    FROM receiving_minutes rm
    LEFT JOIN standing_committees sc ON sc.id=rm.standing_committee_id
    LEFT JOIN users u ON u.id=rm.created_by
    WHERE rm.id=? LIMIT 1");
$s->execute([$id]); $minute = $s->fetch();
$att=$pdo->prepare("SELECT * FROM receiving_minute_attachments WHERE minute_id=? ORDER BY id");
$att->execute([$id]); $attachments=$att->fetchAll();
if (!$minute) { flash('danger',$rtl?'غير موجود':'Not found'); header('Location:'.BASE_URL.'/receiving/index.php'); exit; }

// ✅ FIX 2026-08-04 (Plan A): جلب الأجهزة الرئيسية (rmi) لشهادات التشغيل — 1 شهادة لكل جهاز
$recv_rmis=$pdo->prepare("
    SELECT rmi.id AS rmi_id, rmi.department_id AS dept_id, rmi.description,
           rmi.manufacturer_name, rmi.model_number, rmi.sequence_no,
           d.name AS dept_name,
           rmi.asset_type AS rep_asset_type,
           (SELECT cc.id FROM commissioning_certificates cc WHERE cc.receiving_minute_item_id=rmi.id LIMIT 1) AS cert_id,
           (SELECT cc.status FROM commissioning_certificates cc WHERE cc.receiving_minute_item_id=rmi.id LIMIT 1) AS cert_status
    FROM receiving_minute_items rmi
    JOIN departments d ON d.id=rmi.department_id
    WHERE rmi.minute_id=? AND rmi.is_main_device=1
      AND (rmi.parent_item_id IS NULL OR rmi.parent_item_id=0)
    ORDER BY d.name, rmi.sequence_no
");
$recv_rmis->execute([$id]); $recv_rmis=$recv_rmis->fetchAll();

// للـ backward compat — قسم $recv_depts لاستخدامه في الـ nav
$recv_depts=$pdo->prepare("
    SELECT DISTINCT d.id, d.name,
        (SELECT cc.id FROM commissioning_certificates cc WHERE cc.receiving_minute_id=? AND cc.department_id=d.id LIMIT 1) AS cert_id,
        (SELECT cc.status FROM commissioning_certificates cc WHERE cc.receiving_minute_id=? AND cc.department_id=d.id LIMIT 1) AS cert_status,
        (SELECT rmi2.asset_type FROM receiving_minute_items rmi2
          WHERE rmi2.minute_id=? AND rmi2.department_id=d.id
            AND (rmi2.parent_item_id IS NULL OR rmi2.parent_item_id=0)
          LIMIT 1) AS rep_asset_type
    FROM receiving_minute_items rmi
    JOIN departments d ON d.id=rmi.department_id
    WHERE rmi.minute_id=? AND rmi.department_id IS NOT NULL
");
$recv_depts->execute([$id,$id,$id,$id]); $recv_depts=$recv_depts->fetchAll();

// ── جلب الأجهزة والملاحقات ودمجها ──
$mains_raw=$pdo->prepare("SELECT * FROM receiving_minute_items WHERE minute_id=? AND (parent_item_id IS NULL OR parent_item_id=0) ORDER BY sequence_no,id");
$mains_raw->execute([$id]); $mains_raw=$mains_raw->fetchAll();

$grouped=[];
foreach($mains_raw as $mi){
    $key = mb_strtolower(trim(($mi['description']??'').'|'.($mi['manufacturer_name']??'').'|'.($mi['model_number']??'').'|'.($mi['unit']??'').'|'.($mi['department_id']??'')));
    if(!isset($grouped[$key])){ $grouped[$key]=$mi; $grouped[$key]['orig_ids']=[$mi['id']]; }
    else { 
        $grouped[$key]['quantity']+=$mi['quantity']; 
        $grouped[$key]['vat_amount']+=$mi['vat_amount']; 
        $grouped[$key]['total_price']+=$mi['total_price']; 
        $grouped[$key]['orig_ids'][]=$mi['id']; 
    }
}

$items=[]; $subtotal=0; $vat_total=0;
foreach($grouped as $g){
    $g['dept_name']=null; $g['recv_name']=null;
    if($g['department_id']){
        $cur=$g['department_id']; $hops=0;
        while($cur && $hops<5){
            $d=$pdo->prepare("SELECT name,manager_id,parent_id FROM departments WHERE id=?"); $d->execute([$cur]); $row=$d->fetch();
            if(!$row) break;
            if($hops===0) $g['dept_name']=$row['name'];
            $cur=$row['parent_id']; $hops++;
        }
    }
    $items[]=$g;
    $subtotal += ($g['unit_price'] * $g['quantity']);
    $vat_total += $g['vat_amount'];

    // الملحقات
    $acc_grouped=[];
    foreach($g['orig_ids'] as $oid){
        $a=$pdo->prepare("SELECT * FROM receiving_minute_items WHERE parent_item_id=? ORDER BY sequence_no"); $a->execute([$oid]);
        foreach($a->fetchAll() as $ac){
            if(($ac['item_code']??'') === 'WARRANTY') continue; // الضمان الأساسي: يدخل في الحساب فقط، لا يُعرض كبند مستقل
            $akey=mb_strtolower(trim($ac['description']??''));
            if(!isset($acc_grouped[$akey])) $acc_grouped[$akey]=$ac;
            else {
                $acc_grouped[$akey]['quantity']+=$ac['quantity'];
                $acc_grouped[$akey]['vat_amount']+=$ac['vat_amount'];
                $acc_grouped[$akey]['total_price']+=$ac['total_price'];
            }
        }
    }
    foreach($acc_grouped as $ac){ 
        $ac['dept_name']=null; 
        $items[]=$ac; 
        $subtotal += ($ac['unit_price'] * $ac['quantity']);
        $vat_total += $ac['vat_amount'];
    }
}
$grand_total = $subtotal + $vat_total;

// 🔒 الصلاحيات الحقيقية المأخوذة من مسار (RBAC) الخاص بك:
$can_commission_role = can('installation.form', 'create'); // الدور الأساسي فقط
$can_print_minute = can('receiving.form', 'print') || can('receiving.index', 'print'); 
$can_distribute   = can('receiving.distribution', 'create') || can('receiving.distribution', 'view'); 

$status_cfg=['draft'=>['ar'=>'مسودة','en'=>'Draft','c'=>'#64748b','b'=>'#f1f5f9','i'=>'fa-pencil'],'sent'=>['ar'=>'قيد التوقيع','en'=>'Signing','c'=>'#d97706','b'=>'#fffbeb','i'=>'fa-pen-fancy'],'approved'=>['ar'=>'معتمد','en'=>'Approved','c'=>'#16a34a','b'=>'#f0fdf4','i'=>'fa-circle-check'],'rejected'=>['ar'=>'مرفوض','en'=>'Rejected','c'=>'#dc2626','b'=>'#fef2f2','i'=>'fa-circle-xmark']];
$sc=$status_cfg[$minute['status']]??$status_cfg['draft'];

$page_title=$rtl?'تفاصيل المحضر':'Minute Details';
$active_nav='receiving.index';
$breadcrumb=[['name'=>$rtl?'المحاضر':'Minutes','url'=>BASE_URL.'/receiving/index.php'],['name'=>e($minute['minute_number']??'#'.$id)]];
$flash_msgs=get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($minute['minute_number']) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body { background-color: #f8fafc; font-family: 'Tajawal', sans-serif; }
.eng-num { font-family: 'Inter', sans-serif !important; direction: ltr !important; unicode-bidi: embed; }
.rv-layout{display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start; padding: 20px 40px;}
@media(max-width:1024px){.rv-layout{grid-template-columns:1fr; padding: 20px;}}

/* Hero Section */
.rv-hero{
    background:linear-gradient(135deg,#1e3a8a 0%, #2563eb 100%);
    color:#fff; border-radius:16px; padding:25px 30px; margin-bottom:20px; 
    box-shadow: 0 10px 30px -5px rgba(30,58,138,0.3); 
    display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 20px;
    position: relative; overflow: hidden;
}
.rv-hero::before { content: ''; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 60%); pointer-events: none; }

/* Cards & Tables */
.rv-card{background:#fff;border-radius:16px;border: 1px solid #e2e8f0;box-shadow:0 10px 25px -5px rgba(0,0,0,.03);overflow:hidden;margin-bottom:20px; position:relative;}
.rv-ch{padding:16px 20px;border-bottom:1px solid #e2e8f0;font-size:15px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:10px}
.rv-ch i{font-size:16px}
.smart-table { width:100%; border-collapse:collapse; }
.smart-table th { font-size:12px; font-weight:800; color:#475569; padding:14px 10px; background:#f8fafc; border-bottom:2px solid #e2e8f0; text-align:center; white-space:nowrap; }
.smart-table td { padding:14px 10px; font-size:13px; border-bottom:1px solid #f1f5f9; vertical-align:middle; text-align:center; }
.smart-table tr.main-row:hover { background: #f8fafc; }
.smart-table tr.acc-row td { background: #fafaf9; color: #475569; border-bottom: 1px dashed #e2e8f0; }

/* Financial Summary Boxes (Sticky Position) */
.fin-summary { 
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; 
    padding: 20px; background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(10px);
    border-top: 1px solid #e2e8f0; 
    position: sticky; bottom: 0; z-index: 20;
    box-shadow: 0 -10px 20px -5px rgba(0,0,0,0.05);
}
.fin-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 15px; text-align: center; transition: 0.3s; }
.fin-box:hover { background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.fin-box.total { background: linear-gradient(135deg, #10b981, #059669); color: #fff; border: none; box-shadow: 0 4px 15px rgba(16,185,129,0.3); }

/* Timeline */
.sig-timeline{padding:20px}
.sig-step{display:flex;align-items:flex-start;gap:15px;margin-bottom:20px;position:relative}
.sig-step:last-child{margin-bottom:0}
.sig-step::before{content:'';position:absolute;inset-inline-start:21px;top:45px;width:2px;height:calc(100% - 15px);background:#e2e8f0}
.sig-step:last-child::before{display:none}
.sig-dot{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;border:3px solid transparent; z-index: 2; background: #fff;}
.sig-dot.done{background:#f0fdf4;border-color:#10b981;color:#10b981}
.sig-dot.pending{background:#f8fafc;border-color:#cbd5e1;color:#94a3b8}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content" style="padding:0;">

<div class="rv-layout">
  <div>
    <div class="rv-hero">
      <div style="display:flex;align-items:center;gap:18px; position:relative; z-index:5; flex: 1; min-width:300px;">
        <div style="width:60px;height:60px;background:rgba(255,255,255,.2);border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0; backdrop-filter:blur(5px);">
          <i class="fa-solid fa-file-signature"></i>
        </div>
        <div>
          <div style="font-size:13px;color:#bfdbfe;margin-bottom:4px; font-weight:700;"><?= $rtl?'محضر استلام رقم':'Receiving Minute No.' ?></div>
          <div style="font-size:24px;font-weight:900;font-family:'Inter'; letter-spacing:1px; margin-bottom:8px;"><?= e($minute['minute_number']??'—') ?></div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <span style="background:<?= $sc['b'] ?>;color:<?= $sc['c'] ?>;padding:6px 14px;border-radius:50px;font-size:12px;font-weight:800;display:flex;align-items:center;gap:6px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
              <i class="fa-solid <?= $sc['i'] ?>"></i><?= $rtl?e($sc['ar']):e($sc['en']) ?>
            </span>
            <span style="background:rgba(255,255,255,.15);padding:6px 14px;border-radius:50px;font-size:12px;font-weight:700; backdrop-filter:blur(5px);">
              <i class="fa-solid fa-users" style="margin-right:5px;"></i> <?= e($minute['committee_name']??'—') ?>
            </span>
          </div>
        </div>
      </div>
      
      <div style="text-align:center; background:rgba(0,0,0,0.15); padding:10px 20px; border-radius:12px; border:1px solid rgba(255,255,255,0.1); position:relative; z-index:5;">
          <span style="display:block; font-size:12px; color:#93c5fd; font-weight:700; margin-bottom:4px;"><?= $rtl?'القيمة الإجمالية للمحضر':'Total Value (SAR)' ?></span>
          <div class="eng-num" style="font-size:24px; font-weight:900; color:#ffffff; text-shadow:0 2px 5px rgba(0,0,0,0.2);"><?= number_format($grand_total, 2) ?> SAR</div>
      </div>

      <div style="display:flex;gap:10px;flex-wrap:wrap; flex:1; min-width:250px; justify-content:flex-end; position:relative; z-index:5;">
        <?php if($minute['status']==='approved'): ?>
            <?php if($can_print_minute): ?>
            <a href="<?= BASE_URL ?>/receiving/print.php?id=<?= $id ?>" target="_blank" class="btn" style="background:#fff; color:#1e3a8a; font-weight:800; padding:10px 20px; border-radius:8px; border:none; box-shadow:0 4px 12px rgba(0,0,0,0.1);"><i class="fa-solid fa-print"></i> طباعة المحضر</a>
            <?php endif; ?>
            
            <?php if($can_distribute): ?>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if($minute['status']==='draft'&&can('receiving.form','edit')): ?>
        <a href="<?= BASE_URL ?>/receiving/form.php?id=<?= $id ?>" class="btn" style="background:#fff; color:#1e3a8a; font-weight:800; padding:10px 20px; border-radius:8px; border:none;"><i class="fa-solid fa-pen"></i> تعديل المحضر</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="rv-card" style="display: flex; flex-direction: column; max-height: 800px;">
      <div class="rv-ch" style="background:#f8fafc; border-bottom:2px solid #e2e8f0; flex-shrink:0;"><i class="fa-solid fa-boxes-stacked" style="color:#1e40af"></i> تفاصيل الأصول المستلمة والملحقات</div>
      
      <div style="overflow-y:auto; overflow-x:auto; flex:1; scrollbar-width: thin;">
        <table class="smart-table">
          <thead style="position: sticky; top: 0; z-index: 10;">
            <tr>
              <th style="width:40px;">#</th>
              <th style="text-align:right;">رقم التصنيف</th>
              <th style="text-align:right;">الوصف (البيان)</th>
              <th style="text-align:right;">الشركة المصنعة</th>
              <th>الكمية</th>
              <th>سعر الوحدة</th>
              <th>الضريبة</th>
              <th>الإجمالي</th>
              <th>توجيه القسم</th>
            </tr>
          </thead>
          <tbody>
          <?php if(empty($items)): ?>
          <tr><td colspan="9" style="text-align:center;padding:30px;color:#94a3b8;font-weight:bold;">لا توجد أصناف مدرجة في هذا المحضر.</td></tr>
          <?php else: foreach($items as $i=>$it):
              $is_main=(bool)($it['is_main_device']??1);
              $row_tot = ($it['unit_price'] * $it['quantity']) + $it['vat_amount'];
          ?>
          <tr class="<?= $is_main ? 'main-row' : 'acc-row' ?>">
            <td style="color:#94a3b8; font-weight:bold; text-align:center;"><?= $is_main ? ($i+1) : '-' ?></td>
            <td style="text-align:right; font-weight:<?= $is_main?'800':'600' ?>; color:<?= $is_main?'#1e40af':'#64748b' ?>;">
                <span class="eng-num"><?= e($it['generic_code'] ?: $it['item_code'] ?? '—') ?></span>
            </td>
            <td style="text-align:right; font-weight:<?= $is_main?'800':'600' ?>; color:#0f172a;">
                <?php if(!$is_main): ?><i class="fa-solid fa-turn-up fa-rotate-90" style="color:#cbd5e1; margin-left:5px;"></i><?php endif; ?>
                <?= e($it['description']) ?>
            </td>
            <td style="text-align:right; font-size:12px; color:#475569; font-weight:600;"><?= e(trim(($it['manufacturer_name']??'').' '.($it['model_number']??'')))?:'—' ?></td>
            <td style="font-weight:800; color:#10b981; text-align:center;">
                <span class="eng-num"><?= $it['quantity'] ?></span> <span style="font-size:10px; color:#64748b; font-family:'Tajawal';"><?= e($it['unit']??'') ?></span>
            </td>
            <td class="eng-num" style="color:#475569; text-align:center;"><?= number_format($it['unit_price'], 2) ?></td>
            <td class="eng-num" style="color:#ef4444; font-size:11.5px; text-align:center;"><?= number_format($it['vat_amount'], 2) ?></td>
            <td class="eng-num" style="font-weight:900; color:#0f172a; text-align:center;"><?= number_format($row_tot, 2) ?></td>
            <td style="text-align:center;">
              <?php if($is_main && $it['dept_name']): ?>
              <span style="font-size:11.5px;color:#16a34a;font-weight:800; background:#f0fdf4; padding:4px 8px; border-radius:6px; border:1px solid #bbf7d0; display:inline-block;"><i class="fa-solid fa-sitemap"></i> <?= e($it['dept_name']) ?></span>
              <?php else: ?><span style="color:#cbd5e1;">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      
      <div class="fin-summary">
          <div class="fin-box">
              <div style="font-size:12px; color:#64748b; font-weight:800; margin-bottom:5px;">القيمة الصافية (Subtotal)</div>
              <div class="eng-num" style="font-size:20px; font-weight:900; color:#1e3a8a;"><?= number_format($subtotal, 2) ?></div>
          </div>
          <div class="fin-box" style="background:#fff5f5; border-color:#fecdd3;">
              <div style="font-size:12px; color:#e11d48; font-weight:800; margin-bottom:5px;">إجمالي الضريبة (VAT 15%)</div>
              <div class="eng-num" style="font-size:20px; font-weight:900; color:#e11d48;"><?= number_format($vat_total, 2) ?></div>
          </div>
          <div class="fin-box total">
              <div style="font-size:12px; color:#d1fae5; font-weight:800; margin-bottom:5px;">الإجمالي المعتمد (Grand Total)</div>
              <div class="eng-num" style="font-size:24px; font-weight:900; color:#ffffff;"><?= number_format($grand_total, 2) ?></div>
          </div>
      </div>
    </div>

    <?php if($minute['status']==='approved' && !empty($recv_rmis)): ?>
    <div class="rv-card">
      <div class="rv-ch" style="background:#fffbeb; border-bottom:2px solid #fde68a; color:#92400e;"><i class="fa-solid fa-tools" style="color:#d97706"></i> الاعتماد الفني: شهادات التركيب والتشغيل <span style="font-size:12px; color:#92400e; background:#fef3c7; padding:2px 8px; border-radius:6px; margin-right:10px;">(1 شهادة لكل جهاز)</span></div>
      <div style="padding:20px;display:grid;grid-template-columns:repeat(auto-fill, minmax(320px, 1fr));gap:15px">
        <?php foreach($recv_rmis as $rr):
            $cs=$rr['cert_status']; $cs_cfg=['draft'=>['مسودة','#64748b','#f1f5f9'],'sent'=>['مطبوعة، بانتظار الرفع','#d97706','#fffbeb'],'approved'=>['معتمدة ✓','#16a34a','#f0fdf4']];
        ?>
        <div style="display:flex; flex-direction:column; justify-content:space-between; padding:15px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; box-shadow:0 4px 6px rgba(0,0,0,0.02);">
          <div style="display:flex;align-items:start;gap:10px;color:#1e293b; margin-bottom:12px;">
              <div style="width:36px;height:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#64748b;flex-shrink:0;"><i class="fa-solid fa-microchip"></i></div>
              <div style="flex:1;">
                  <div style="font-size:11px;color:#64748b;font-weight:700;margin-bottom:2px;"><?= e($rr['dept_name']) ?> — جهاز #<?= (int)$rr['sequence_no'] ?></div>
                  <div style="font-size:13px;font-weight:800;line-height:1.3;"><?= e(truncate($rr['description'], 60)) ?></div>
                  <div style="font-size:10px;color:#94a3b8;margin-top:2px;"><?= e($rr['manufacturer_name'] ?? '—') ?> <?= $rr['model_number'] ? '· '.e($rr['model_number']) : '' ?></div>
              </div>
          </div>

          <?php if($rr['cert_id']): ?>
          <a href="<?= BASE_URL ?>/installation/form.php?id=<?= $rr['cert_id'] ?>" style="text-align:center; padding:10px; border-radius:8px; font-weight:bold; font-size:13px; text-decoration:none; background:<?= $cs_cfg[$cs][2] ?>; color:<?= $cs_cfg[$cs][1] ?>; border:1px solid <?= $cs_cfg[$cs][1] ?>; opacity:0.8;">الشهادة: <?= $cs_cfg[$cs][0] ?? $cs ?></a>

          <?php else: ?>
              <?php $can_commission = $can_commission_role && is_my_maintenance_type($rr['rep_asset_type'] ?: 'medical');
                    if($can_commission): // 🔒 شرط الـ RBAC الصارم + مطابقة فريق الصيانة لنوع الجهاز ?>
              <a href="<?= BASE_URL ?>/installation/form.php?rmi_id=<?= $rr['rmi_id'] ?>" style="text-align:center; padding:10px; border-radius:8px; font-weight:bold; font-size:13px; text-decoration:none; background:#2563eb; color:#fff; box-shadow:0 4px 10px rgba(37,99,235,0.2); transition:0.3s;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'"><i class="fa-solid fa-play"></i> إصدار شهادة هذا الجهاز</a>
              <?php else: // ⛔ المستفيد بدون صلاحيات ?>
              <div style="text-align:center; padding:10px; border-radius:8px; font-weight:bold; font-size:13px; background:#f8fafc; color:#94a3b8; border:1px dashed #cbd5e1;"><i class="fa-solid fa-hourglass-half"></i> بانتظار فريق الصيانة</div>
              <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="rv-card">
        <div class="rv-ch" style="background:#f8fafc;"><i class="fa-solid fa-receipt" style="color:#1e40af"></i> المرجع التوثيقي الإداري</div>
        <div style="padding:16px;">
            <div style="padding:10px 0; border-bottom:1px dashed #e2e8f0;">
                <div style="font-size:11px; color:#64748b; font-weight:700; margin-bottom:4px;">الشركة الموردة</div>
                <div style="font-size:14px; font-weight:900; color:#0f172a;"><i class="fa-solid fa-truck-fast" style="color:#10b981; font-size:12px; margin-left:5px;"></i> <?= e($minute['supplier_name']?:'—') ?></div>
            </div>
            <div style="padding:10px 0; border-bottom:1px dashed #e2e8f0; display:flex; justify-content:space-between;">
                <div><div style="font-size:11px; color:#64748b; font-weight:700; margin-bottom:4px;">نوع المستند</div><div style="font-size:13px; font-weight:800; color:#1e3a8a;"><?= e($minute['doc_type']?:'—') ?></div></div>
                <div style="text-align:left;"><div style="font-size:11px; color:#64748b; font-weight:700; margin-bottom:4px;">رقم المرجع</div><div style="font-size:13px; font-weight:900; color:#1e40af;" class="eng-num"><?= e($minute['doc_number']?:'—') ?></div></div>
            </div>
            <div style="padding:10px 0; border-bottom:1px dashed #e2e8f0; display:flex; justify-content:space-between;">
                <div><div style="font-size:11px; color:#64748b; font-weight:700; margin-bottom:4px;">تاريخ المستند</div><div style="font-size:13px; font-weight:800;" class="eng-num"><?= e($minute['doc_date']?:'—') ?></div></div>
                <div style="text-align:left;"><div style="font-size:11px; color:#64748b; font-weight:700; margin-bottom:4px;">تاريخ الاستلام</div><div style="font-size:13px; font-weight:900; color:#047857;" class="eng-num"><?= e($minute['receipt_date']?:'—') ?></div></div>
            </div>
            <div style="padding:10px 0;">
                <div style="font-size:11px; color:#64748b; font-weight:700; margin-bottom:4px;">منشئ المحضر في النظام</div>
                <div style="font-size:13px; font-weight:800; color:#334155;"><i class="fa-solid fa-user-pen" style="color:#94a3b8; font-size:11px; margin-left:5px;"></i> <?= e($minute['creator_name']??'—') ?></div>
            </div>
        </div>
    </div>

    <div class="rv-card">
      <div class="rv-ch" style="background:#f8fafc;"><i class="fa-solid fa-shoe-prints" style="color:#10b981"></i> مسار التنبيهات — من اطّلع؟</div>
      <div class="sig-timeline">
        <?php
        $notifs=$pdo->prepare("SELECT n.*, u.full_name FROM notifications n LEFT JOIN users u ON u.id=n.user_id WHERE n.related_type='receiving_minute' AND n.related_id=? ORDER BY n.created_at");
        $notifs->execute([$id]); $notifs=$notifs->fetchAll();
        if(empty($notifs)):
        ?>
        <div style="text-align:center;padding:20px;color:#94a3b8;font-size:13px; font-weight:bold;">لا توجد تنبيهات مسجلة لهذا المحضر.</div>
        <?php else: foreach($notifs as $nf): ?>
        <div class="sig-step">
          <div class="sig-dot <?= $nf['is_read']?'done':'pending' ?>">
            <?php if($nf['is_read']): ?><i class="fa-solid fa-check"></i><?php else: ?><i class="fa-solid fa-envelope"></i><?php endif; ?>
          </div>
          <div style="flex:1;">
            <div style="font-size:13.5px;font-weight:800;color:#0f172a"><?= e($nf['full_name']??'—') ?></div>
            <div style="font-size:11.5px;color:#64748b;margin-top:2px"><?= e($nf['title']) ?></div>
            <?php if(!$nf['is_read']): ?>
            <div style="font-size:11px;color:#d97706;font-weight:700;margin-top:5px; background:#fffbeb; padding:2px 8px; border-radius:4px; display:inline-block;">لم يفتح المحضر بعد</div>
            <?php else: ?>
            <div style="font-size:11px;color:#10b981;font-weight:700;margin-top:5px; background:#f0fdf4; padding:2px 8px; border-radius:4px; display:inline-block;" class="eng-num">Seen: <?= substr($nf['read_at'],0,16) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <?php if(!empty($minute['notes']) || !empty($attachments)): ?>
    <div class="rv-card">
      <div class="rv-ch" style="background:#f8fafc;"><i class="fa-solid fa-paperclip" style="color:#475569"></i> ملاحظات ومرفقات</div>
      <div style="padding:16px">
        <?php if(!empty($minute['notes'])): ?>
        <div style="font-size:13px;color:#334155;font-weight:600; line-height:1.7;background:#fffbeb;border-left:3px solid #fcd34d;border-radius:6px;padding:12px;<?= !empty($attachments)?'margin-bottom:15px':'' ?>"><?= nl2br(e($minute['notes'])) ?></div>
        <?php endif; ?>
        
        <?php foreach($attachments as $a):
            $ext=strtolower(pathinfo($a['file_name'],PATHINFO_EXTENSION));
            $icon=in_array($ext,['pdf'])?'fa-file-pdf':(in_array($ext,['jpg','jpeg','png','gif'])?'fa-file-image':(in_array($ext,['doc','docx'])?'fa-file-word':'fa-file'));
            $color=in_array($ext,['pdf'])?'#ef4444':(in_array($ext,['jpg','jpeg','png','gif'])?'#10b981':'#3b82f6');
        ?>
        <a href="<?= BASE_URL ?>/uploads/<?= e($a['file_path']) ?>" target="_blank" style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:8px;text-decoration:none;color:#1e293b;transition:.2s; background:#f8fafc;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
          <i class="fa-solid <?= $icon ?>" style="font-size:22px;color:<?= $color ?>;"></i>
          <span style="font-size:12px;font-weight:700;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" class="eng-num"><?= e($a['file_name']) ?></span>
          <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:11px;color:#94a3b8"></i>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

</main></div>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>