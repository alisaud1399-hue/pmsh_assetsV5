<?php
/**
 * receiving/distribution.php — إنشاء / تعديل بيان التوزيع (النسخة الآمنة - RBAC)
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('receiving.index');

$rtl = is_rtl();
$uid = (int)current_user()['id'];
$mid = (int)($_GET['minute_id'] ?? 0); // من view.php
$did = (int)($_GET['id']        ?? 0); // تعديل موجود

// 🔒 التحقق الصارم من صلاحية إدارة بيان التوزيع (RBAC)
$can_distribute = can('receiving.distribution', 'create') || can('receiving.distribution', 'edit');

if (!$mid && !$did) { flash('danger','ID required'); header('Location:'.BASE_URL.'/receiving/index.php'); exit; }

// جلب المحضر
if ($did) {
    $s=$pdo->prepare("SELECT * FROM distribution_items WHERE id=? LIMIT 1");
    $s->execute([$did]); $dist=$s->fetch();
    $mid = $dist['minute_id'] ?? 0;
}
$s=$pdo->prepare("SELECT rm.*,c.name AS committee_name,u.full_name AS creator_name FROM receiving_minutes rm LEFT JOIN committees c ON c.id=rm.committee_id LEFT JOIN users u ON u.id=rm.created_by WHERE rm.id=? LIMIT 1");
$s->execute([$mid]); $minute=$s->fetch();
if (!$minute) { flash('danger','Not found'); header('Location:'.BASE_URL.'/receiving/index.php'); exit; }
if ($minute['status']!=='approved') { flash('warning',$rtl?'المحضر لم يكتمل بعد':'Minute not completed'); header('Location:'.BASE_URL.'/receiving/view.php?id='.$mid); exit; }

// أصناف المحضر (للاستدعاء التلقائي) — مع السيريالات والضمان من receiving_item_serials
$items=$pdo->prepare("
    SELECT 
        rmi.*,
        GROUP_CONCAT(ris.serial_number SEPARATOR ' / ') AS serial_numbers,
        MAX(ris.warranty_years) AS warranty_years,
        MAX(ris.warranty_type) AS warranty_type,
        MAX(ris.warranty_expiry) AS warranty_expiry
    FROM receiving_minute_items rmi
    LEFT JOIN receiving_item_serials ris ON ris.item_id = rmi.id
    WHERE rmi.minute_id=? AND rmi.is_main_device=1
    GROUP BY rmi.id
    ORDER BY rmi.sequence_no
");
$items->execute([$mid]); $items=$items->fetchAll();

// سجلات التوزيع الحالية
$dept_list=$pdo->query("SELECT id,name FROM departments WHERE level=1 ORDER BY name")->fetchAll();

$existing=$pdo->prepare("SELECT * FROM distribution_items WHERE minute_id=? ORDER BY sequence_no");
$existing->execute([$mid]); $existing=$existing->fetchAll();

// ── POST ──────────────────────────────────────────────────────
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST' && verify_csrf()) {
    
    // ⛔ منع معالجة البيانات إذا لم يكن يملك الصلاحية (حماية السيرفر)
    if (!$can_distribute) {
        flash('danger', $rtl ? 'عذراً، لا تملك الصلاحية لإجراء بيان التوزيع.' : 'Permission denied to manage distribution.');
        header('Location:'.BASE_URL.'/receiving/distribution.php?minute_id='.$mid);
        exit;
    }

    $rows = $_POST['rows'] ?? [];
    $valid=[];
    foreach($rows as $r) {
        $dev=trim($r['device_name']??'');
        $qty=(float)($r['quantity']??0);
        // Smart Receiver Picker: receiver_user_id=0 = إدخال يدوي
        $r_uid = (int)($r['receiver_user_id'] ?? 0);
        if($r_uid < 0) $r_uid = 0;
        $r_name = trim($r['receiver_name'] ?? '');
        // إذا اختار مستخدم من القائمة، تأكد أن الاسم موجود؛ وإلا فاضي
        if($r_uid > 0 && $r_name === '') $r_uid = 0; // مستخدم بدون اسم = باطل
        if($r_uid === 0) $r_name = $r_name ?: null;
        if($dev&&$qty>0) $valid[]=[
            'device_name'     =>$dev,
            'model_number'    =>trim($r['model_number']??'')?:null,
            'serial_numbers'  =>trim($r['serial_numbers']??'')?:null,
            'quantity'        =>$qty,
            'department_id'   =>(int)($r['department_id']??0)?:null,
            'receiver_user_id'=> $r_uid > 0 ? $r_uid : null,
            'receiver_name'   => $r_name,
            'receiver_title'  =>trim($r['receiver_title']??'')?:null,
        ];
    }
    if(empty($valid)) $errors[]=$rtl?'يجب إضافة صف واحد على الأقل':'Add at least one row';

    if(empty($errors)) {
        // اسم القسم يُقرأ موثوقاً من جدول الإدارات (لا من إدخال حر)
        $dn=$pdo->prepare("SELECT name FROM departments WHERE id=?");
        $pdo->prepare("DELETE FROM distribution_items WHERE minute_id=?")->execute([$mid]);
        $si=$pdo->prepare("INSERT INTO distribution_items
            (minute_id,sequence_no,device_name,model_number,serial_numbers,
             quantity,department_id,department_name,
             receiver_user_id,receiver_name,receiver_title)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        foreach($valid as $i=>$r) {
            $dept_name=null;
            if($r['department_id']){ $dn->execute([$r['department_id']]); $dept_name=$dn->fetchColumn()?:null; }
            $si->execute([$mid,$i+1,$r['device_name'],$r['model_number'],
                $r['serial_numbers'],$r['quantity'],$r['department_id'],
                $dept_name,
                $r['receiver_user_id'],$r['receiver_name'],$r['receiver_title']]);
        }
        
        log_activity('create', 'receiving.distribution', "Distributed minute ID: $mid");
        flash('success',$rtl?'تم حفظ بيان التوزيع ✅':'Distribution saved');
        header('Location:'.BASE_URL.'/receiving/distribution_print.php?minute_id='.$mid);
        exit;
    }
}

// إذا لا توجد سجلات → نوّلد مقترحاً من الأصناف (مع السيريالات من receiving_item_serials)
if(empty($existing)&&!empty($items)) {
    foreach($items as $it) {
        $existing[]=[
            'device_name'=>$it['description'],
            'model_number'=>$it['model_number']??'',
            'serial_numbers'=>$it['serial_numbers']??'',  // ✅ الآن من JOIN صحيح
            'quantity'=>$it['quantity'],
            'department_id'=>0,
            'receiver_name'=>'',
            'warranty_years'=>$it['warranty_years']??null,  // جديد
            'warranty_type'=>$it['warranty_type']??null,    // جديد
            'warranty_expiry'=>$it['warranty_expiry']??null, // جديد
        ];
    }
}
if(empty($existing)) $existing[]=['device_name'=>'','model_number'=>'','serial_numbers'=>'','quantity'=>1,'department_id'=>0,'receiver_name'=>''];

$page_title=$rtl?'بيان التوزيع':'Distribution Statement';
$active_nav='receiving.index';
$breadcrumb=[['name'=>$rtl?'المحاضر':'Receiving','url'=>BASE_URL.'/receiving/index.php'],['name'=>$rtl?'المحضر':'Minute','url'=>BASE_URL.'/receiving/view.php?id='.$mid],['name'=>$page_title]];
$flash_msgs=get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.dc{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;margin-bottom:14px}
.dch{padding:13px 18px;border-bottom:1px solid #f1f5f9;font-size:13.5px;font-weight:700;color:#0f172a;display:flex;align-items:center;justify-content:space-between;gap:7px}
.dch i{color:#1565C0}
.info-strip{display:flex;gap:20px;padding:11px 18px;background:#f8fafc;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;font-size:13px}
.info-strip .lbl{font-size:11px;font-weight:700;color:#64748b;display:block;margin-bottom:1px}
.dt-wrap{overflow-x:auto}
.dt{width:100%;border-collapse:collapse;min-width:860px}
.dt th{font-size:11px;font-weight:700;color:#64748b;padding:8px;background:#f8fafc;border:1px solid #e2e8f0;text-align:center;white-space:nowrap}
.dt td{border:1px solid #e2e8f0;padding:4px 5px;vertical-align:middle}
.di{height:34px;padding-inline:8px;border:1.5px solid transparent;border-radius:7px;font-family:'Tajawal',sans-serif;font-size:12.5px;background:transparent;width:100%;box-sizing:border-box;outline:none;transition:.15s}
.di:focus{border-color:#1565C0;background:#fff}
.di-sn{height:54px;resize:none;padding:6px 8px;border:1.5px solid transparent;border-radius:7px;font-family:'Courier New',monospace;font-size:11.5px;background:transparent;width:100%;box-sizing:border-box;outline:none;transition:.15s;line-height:1.5}
.di-sn:focus{border-color:#1565C0;background:#fff}
.dd-btn{width:26px;height:26px;border-radius:6px;border:1.5px solid #fecaca;background:#fff;color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:10px;transition:.15s;margin:auto}
.dd-btn:hover{background:#dc2626;color:#fff}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php if($errors): ?>
<div class="alert alert-danger" style="margin-bottom:14px"><i class="fa-solid fa-circle-exclamation"></i> <?= e($errors[0]) ?></div>
<?php endif; ?>
<?php foreach($flash_msgs as $fm): ?><div class="alert alert-<?= $fm['type'] ?>" style="margin-bottom:12px"><?= e($fm['message']) ?></div><?php endforeach; ?>


<?php if (!$can_distribute): ?>

<div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px; text-align:center; background:#fff; border-radius:16px; box-shadow:0 10px 25px rgba(0,0,0,0.03); border:1px solid #e2e8f0; margin-top:20px;">
    <div style="width:80px; height:80px; background:#fef2f2; color:#ef4444; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:36px; margin-bottom:20px; box-shadow:0 4px 15px rgba(239,68,68,0.2);">
        <i class="fa-solid fa-shield-halved"></i>
    </div>
    <h2 style="color:#0f172a; font-weight:900; margin-bottom:10px; font-size:22px;"><?= $rtl ? 'صلاحيات غير كافية' : 'Access Denied' ?></h2>
    <p style="color:#64748b; font-size:14px; max-width:480px; line-height:1.6; margin-bottom:25px;">
        <?= $rtl ? 'عذراً، حسب الإجراء المتبع، يتم تعبئة بيان التوزيع بواسطة <strong>رئيس القسم المعني</strong> أو فريق الإمداد المختص بعد إصدار شهادات التشغيل.<br>إذا كنت تعتقد أن هذا خطأ، يرجى مراجعة مدير النظام (Admin) لمنحك الصلاحية.' : 'You do not have the required permissions to manage distribution.' ?>
    </p>
    <a href="<?= BASE_URL ?>/receiving/view.php?id=<?= $mid ?>" class="btn btn-outline" style="border-radius:10px; font-weight:bold; padding:10px 24px;">
        <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl ? 'العودة لصفحة المحضر' : 'Back to Minute' ?>
    </a>
</div>

<?php else: ?>
<form method="POST" id="distForm">
<?= csrf_input() ?>

<div class="dc">
  <div class="dch">
    <span><i class="fa-solid fa-file-contract"></i><?= $rtl?'بيان توزيع المحضر':'Distribution for Minute' ?></span>
    <a href="<?= BASE_URL ?>/receiving/view.php?id=<?= $mid ?>" class="btn btn-outline btn-sm">
      <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i><?= $rtl?'رجوع':'Back' ?>
    </a>
  </div>
  <div class="info-strip">
    <div><span class="lbl"><?= $rtl?'رقم المحضر':'Minute No.' ?></span><strong><?= e($minute['minute_number']) ?></strong></div>
    <div><span class="lbl"><?= $rtl?'اللجنة':'Committee' ?></span><?= e($minute['committee_name']??'—') ?></div>
    <div><span class="lbl"><?= $rtl?'المورد':'Supplier' ?></span><?= e($minute['supplier_name']??'—') ?></div>
    <div><span class="lbl"><?= $rtl?'تاريخ الاستلام':'Receipt Date' ?></span><?= e($minute['receipt_date']??substr($minute['created_at']??'',0,10)) ?></div>
  </div>
</div>

<div class="dc">
  <div class="dch">
    <span><i class="fa-solid fa-arrows-split-up-and-left"></i><?= $rtl?'توزيع الأجهزة على الأقسام':'Device Distribution by Department' ?></span>
    <button type="button" onclick="addDistRow()" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-plus"></i><?= $rtl?'إضافة صف':'Add Row' ?>
    </button>
  </div>
  <div class="dt-wrap">
    <table class="dt">
      <thead><tr>
        <th style="width:30px">م</th>
        <th style="min-width:160px"><?= $rtl?'اسم الجهاز':'Device Name' ?></th>
        <th style="width:100px"><?= $rtl?'الموديل':'Model' ?></th>
        <th style="width:130px"><?= $rtl?'الأرقام التسلسلية (كل رقم في سطر)':'Serial Numbers' ?></th>
        <th style="width:55px"><?= $rtl?'العدد':'Qty' ?></th>
        <th style="width:140px"><?= $rtl?'القسم':'Department' ?></th>
        <th style="width:120px"><?= $rtl?'المستلم':'Receiver' ?></th>
        <th style="width:30px"></th>
      </tr></thead>
      <tbody id="distBody">
      <?php foreach($existing as $i=>$r): ?>
      <tr id="dr_<?= $i ?>">
        <td style="text-align:center;color:#94a3b8;font-size:11px" class="dr-num"><?= $i+1 ?></td>
        <td>

          <input type="text" name="rows[<?= $i ?>][device_name]" class="di" required value="<?= e($r['device_name']??'') ?>">
        </td>
        <td><input type="text" name="rows[<?= $i ?>][model_number]" class="di" value="<?= e($r['model_number']??'') ?>"></td>
        <td><textarea name="rows[<?= $i ?>][serial_numbers]" class="di-sn" placeholder="SN001&#10;SN002&#10;SN003"><?= e($r['serial_numbers']??'') ?></textarea></td>
        <td><input type="number" name="rows[<?= $i ?>][quantity]" class="di" value="<?= e($r['quantity']??1) ?>" min="0.01" step="any" style="width:50px"></td>
        <td><select name="rows[<?= $i ?>][department_id]" class="di" required onchange="fetchUsersForRow(this, <?= $i ?>)">
            <option value="">— اختر القسم —</option>
            <?php foreach($dept_list as $dp): ?>
            <option value="<?= (int)$dp['id'] ?>" <?= (int)($r['department_id']??0)===(int)$dp['id']?'selected':'' ?>><?= e($dp['name']) ?></option>
            <?php endforeach; ?>
        </select></td>
        <td>
          <select name="rows[<?= $i ?>][receiver_user_id]" id="recSel_<?= $i ?>" class="di" onchange="syncReceiver(<?= $i ?>)" style="min-width:160px">
            <option value="">— اختر المستلم —</option>
            <?php if (!empty($r['receiver_name'])): ?>
            <option value="0" data-name="<?= e($r['receiver_name']) ?>" data-title="<?= e($r['receiver_title'] ?? '') ?>" selected>
              👤 <?= e($r['receiver_name']) ?>
            </option>
            <?php endif; ?>
          </select>
          <input type="hidden" name="rows[<?= $i ?>][receiver_name]" id="recName_<?= $i ?>" value="<?= e($r['receiver_name']??'') ?>">
          <input type="hidden" name="rows[<?= $i ?>][receiver_title]" id="recTitle_<?= $i ?>" value="<?= e($r['receiver_title']??'') ?>">
        </td>
        <td><button type="button" class="dd-btn" onclick="delDistRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div style="padding:12px 18px;border-top:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center">
    <div style="font-size:12.5px;color:#64748b">
      <?= $rtl?'تأكد أن مجموع العدد يساوي إجمالي الكمية في المحضر:':'Verify total matches the minute quantity:' ?>
      <strong style="color:#1565C0"><?= number_format(array_sum(array_column($items,'quantity'))) ?> <?= $rtl?'وحدة':'units' ?></strong>
    </div>
    <div style="font-size:14px;font-weight:700;color:#0f172a">
      <?= $rtl?'مجموع التوزيع:':'Distribution total:' ?>
      <span id="distTotal" style="color:#1565C0;font-family:Inter">0</span>
    </div>
  </div>
</div>

<div style="display:flex;gap:10px;align-items:center">
  <button type="submit" class="btn btn-primary">
    <i class="fa-solid fa-floppy-disk"></i><?= $rtl?'حفظ وطباعة البيان':'Save & Print' ?>
  </button>
  <a href="<?= BASE_URL ?>/receiving/distribution_print.php?minute_id=<?= $mid ?>" target="_blank" class="btn btn-outline">
    <i class="fa-solid fa-print"></i><?= $rtl?'طباعة بدون حفظ':'Print without saving' ?>
  </a>
</div>
</form>

<?php endif; ?>

</main></div>

<script>
const _RTL=<?= $rtl?'true':'false' ?>;
const DEPT_OPTS=`<option value="">— اختر القسم —</option><?php foreach($dept_list as $dp): ?><option value="<?= (int)$dp['id'] ?>"><?= e($dp['name']) ?></option><?php endforeach; ?>`;
let _di=<?= count($existing) ?>;

function addDistRow(){
    const b=document.getElementById('distBody');
    if(!b) return; // الحماية إذا كانت الصفحة محجوبة
    const i=_di++;
    const tr=document.createElement('tr');tr.id='dr_'+i;
    tr.innerHTML=`
        <td style="text-align:center;color:#94a3b8;font-size:11px" class="dr-num">${b.children.length+1}</td>
        <td><input type="text" name="rows[${i}][device_name]" class="di" required></td>
        <td><input type="text" name="rows[${i}][model_number]" class="di"></td>
        <td><textarea name="rows[${i}][serial_numbers]" class="di-sn" placeholder="SN001&#10;SN002"></textarea></td>
        <td><input type="number" name="rows[${i}][quantity]" class="di" value="1" min="0.01" step="any" style="width:50px" oninput="calcTotal()"></td>
        <td><select name="rows[${i}][department_id]" class="di" required onchange="fetchUsersForRow(this, ${i})">${DEPT_OPTS}</select></td>
        <td>
            <select name="rows[${i}][receiver_user_id]" id="recSel_${i}" class="di" onchange="syncReceiver(${i})" style="min-width:160px">
                <option value="">— اختر المستلم —</option>
            </select>
            <input type="hidden" name="rows[${i}][receiver_name]" id="recName_${i}" value="">
            <input type="hidden" name="rows[${i}][receiver_title]" id="recTitle_${i}" value="">
        </td>
        <td><button type="button" class="dd-btn" onclick="delDistRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    b.appendChild(tr);
    reIndexDist(); tr.querySelector('.di').focus();
}
function delDistRow(btn){
    const b=document.getElementById('distBody');
    if(b.children.length<=1){alert(_RTL?'يجب إبقاء صف واحد':'Keep at least one row');return;}
    btn.closest('tr').remove(); reIndexDist(); calcTotal();
}
function reIndexDist(){
    document.querySelectorAll('#distBody tr').forEach(function(tr,i){
        const n=tr.querySelector('.dr-num'); if(n)n.textContent=i+1;
    });
}
function calcTotal(){
    let s=0;
    document.querySelectorAll('#distBody input[name*="[quantity]"]').forEach(function(inp){s+=parseFloat(inp.value)||0;});
    const dt = document.getElementById('distTotal');
    if(dt) dt.textContent=s%1===0?s:s.toFixed(2);
}

// ════════════════════════════════════════════════════════════
// Smart Receiver Picker
// ════════════════════════════════════════════════════════════
async function fetchUsersForRow(deptSelect, rowIdx){
    const deptId = deptSelect.value;
    const sel = document.getElementById('recSel_'+rowIdx);
    const hName = document.getElementById('recName_'+rowIdx);
    const hTitle = document.getElementById('recTitle_'+rowIdx);
    if(!sel) return;
    // إعادة تهيئة
    sel.innerHTML = '<option value="">— اختر المستلم —</option>';
    hName.value = ''; hTitle.value = '';
    if(!deptId) return;
    sel.innerHTML = '<option value="">⏳ ' + (_RTL?'جاري التحميل...':'Loading...') + '</option>';
    try {
        const r = await fetch('<?= BASE_URL ?>/api/department_users.php?dept_id='+deptId, {credentials:'same-origin'});
        const j = await r.json();
        if(!j.ok){ sel.innerHTML = '<option value="">⚠️ '+(j.error||'error')+'</option>'; return; }
        const opt0 = '<option value="">— اختر المستلم —</option>';
        let headOpts = '';
        let userOpts = '';
        if(j.manager){
            const m = j.manager;
            headOpts = `<option value="${m.id}" data-name="${escAttr(m.full_name)}" data-title="${escAttr(m.job_title||'')}" selected>👔 ${escAttr(m.full_name)} — ${escAttr(m.job_title||'رئيس القسم')}${j.inherited_from?' ('+escAttr(j.inherited_from)+')':''}</option>`;
        }
        (j.users||[]).forEach(u=>{
            if(j.manager && u.id===j.manager.id) return; // متجنب التكرار
            const marker = u.is_head ? '⭐ ' : '👤 ';
            userOpts += `<option value="${u.id}" data-name="${escAttr(u.full_name)}" data-title="${escAttr(u.job_title||'')}">${marker}${escAttr(u.full_name)} — ${escAttr(u.job_title||'')}</option>`;
        });
        // إضافة خيار "إدخال يدوي" (آخر) للتوافق العكسي
        const manualOpt = '<option value="0">✍️ ' + (_RTL?'إدخال يدوي':'Manual entry') + '</option>';
        sel.innerHTML = opt0 + (headOpts?('<optgroup label="' + (_RTL?'— رئيس القسم —':'— Department head —') + '">'+headOpts+'</optgroup>'):'') +
                        (userOpts?('<optgroup label="' + (_RTL?'— موظفون آخرون —':'— Other staff —') + '">'+userOpts+'</optgroup>'):'') +
                        manualOpt;
        // تحديث الـ hidden fields بالاختيار الافتراضي
        syncReceiver(rowIdx);
    } catch(e){
        sel.innerHTML = '<option value="">⚠️ ' + (_RTL?'فشل الاتصال':'Network error') + '</option>';
    }
}
function syncReceiver(rowIdx){
    const sel = document.getElementById('recSel_'+rowIdx);
    const hName = document.getElementById('recName_'+rowIdx);
    const hTitle = document.getElementById('recTitle_'+rowIdx);
    if(!sel) return;
    const opt = sel.options[sel.selectedIndex];
    if(opt && opt.value && opt.value!=='0'){
        hName.value  = opt.dataset.name  || '';
        hTitle.value = opt.dataset.title || '';
    } else if(opt && opt.value==='0'){
        // إدخال يدوي: أبقِ الاسم الموجود، امسح user_id
        hTitle.value = '';
    } else {
        hName.value = ''; hTitle.value = '';
    }
}
function escAttr(s){ return String(s||'').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
// قوائم الأصناف للاستدعاء السريع
const _ITEMS=<?= json_encode(array_map(fn($it)=>['id'=>$it['id'],'desc'=>$it['description'],'sn'=>$it['serial_nos']??'','qty'=>$it['quantity']],$items),JSON_UNESCAPED_UNICODE) ?>;
calcTotal();
document.querySelectorAll('#distBody input[name*="[quantity]"]').forEach(function(inp){inp.addEventListener('input',calcTotal);});
// عند تحميل الصفحة: أي صف عنده department_id محفوظ، نحمّل المستخدمين له
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('#distBody tr').forEach(function(tr){
        const deptSel = tr.querySelector('select[name*="[department_id]"]');
        const recSel  = tr.querySelector('select[name*="[receiver_user_id]"]');
        if(deptSel && recSel){
            const rid = recSel.id.replace('recSel_','');
            if(deptSel.value){
                // حمّل المستخدمين (سيتم ملء الـ select مع الحفاظ على الاختيار الموجود إن وُجد)
                fetchUsersForRow(deptSel, rid).then(()=>{
                    // إن كان receiver_name موجود مسبقاً (وضع تعديل) ولا يوجد user_id
                    const existingName = document.getElementById('recName_'+rid).value;
                    const existingUid  = recSel.value;
                    if(!existingUid && existingName){
                        // خيار "إدخال يدوي" مع الاسم
                        recSel.innerHTML += `<option value="0" selected>👤 ${escAttr(existingName)} (محفوظ)</option>`;
                    }
                });
            }
        }
    });
});
</script>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>