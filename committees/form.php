<?php
/**
 * committees/form.php — إنشاء / تعديل طلب تشكيل لجنة
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('committees.index');

$rtl = is_rtl();
$uid = (int)current_user()['id'];
$id  = (int)($_GET['id'] ?? 0);
$edit = $id > 0;

if (!can('committees.index','create') && !can('committees.index','edit')) {
    flash('danger',$rtl?'غير مصرح':'Unauthorized');
    header('Location:'.BASE_URL.'/committees/index.php'); exit;
}

$c = []; $existing_members = []; $existing_assets = []; $existing_attachments = [];
if ($edit) {
    $s=$pdo->prepare("SELECT * FROM committees WHERE id=? LIMIT 1");
    $s->execute([$id]); $c=$s->fetch();
    if (!$c) { flash('danger',$rtl?'غير موجود':'Not found'); header('Location:'.BASE_URL.'/committees/index.php'); exit; }
    if ((int)($c['created_by']??$c['requested_by']??0)!==$uid && !is_admin()) {
        flash('danger',$rtl?'غير مصرح':'Unauthorized'); header('Location:'.BASE_URL.'/committees/index.php'); exit;
    }
    if (!in_array($c['status'],['draft','returned'])) {
        flash('warning',$rtl?'لا يمكن تعديل هذا الطلب الآن':'Cannot edit at this stage');
        header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
    }
    $s=$pdo->prepare("SELECT cm.*,u.full_name FROM committee_members cm LEFT JOIN users u ON u.id=cm.user_id WHERE cm.committee_id=? ORDER BY cm.sort_order");
    $s->execute([$id]); $existing_members=$s->fetchAll();
    $s=$pdo->prepare("SELECT ca.*,a.description,a.tag_number,a.asset_number,a.manufacturer_name FROM committee_assets ca LEFT JOIN assets a ON a.id=ca.asset_id WHERE ca.committee_id=?");
    $s->execute([$id]); $existing_assets=$s->fetchAll();
    $s=$pdo->prepare("SELECT * FROM committee_attachments WHERE committee_id=? ORDER BY id");
    $s->execute([$id]); $existing_attachments=$s->fetchAll();
}

// منع إنشاء طلب جديد إذا يوجد طلب قيد الاعتماد
if (!$edit) {
    $pend=$pdo->prepare("SELECT COUNT(*) FROM committees WHERE (created_by=? OR requested_by=?) AND status='requested'");
    $pend->execute([$uid,$uid]);
    if ((int)$pend->fetchColumn()>0) {
        flash('warning',$rtl?'لديك طلب قيد الاعتماد. أنتظر البت فيه أولاً.':'You have a pending request.');
        header('Location:'.BASE_URL.'/committees/index.php'); exit;
    }
}

// الأنواع التي تستوجب أصولاً (مطابقة الاسم)
function type_requires_assets(string $name): bool {
    $keywords = ['تكهين','تكهّن','إتلاف','اتلاف','نقل'];
    foreach ($keywords as $k) if (mb_strpos($name,$k)!==false) return true;
    return false;
}

// ── POST ──────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf()) { $errors[]=$rtl?'طلب غير صالح':'Invalid'; }
    else {
        $f_name    = trim($_POST['name']               ?? '');
        $f_type_id = (int)($_POST['committee_type_id'] ?? 0) ?: null;
        $f_purpose = trim($_POST['purpose']             ?? '');
        $f_action  = $_POST['form_action']              ?? 'draft';
        $members   = array_values($_POST['members']     ?? []);
        $asset_ids = array_filter(array_map('intval', $_POST['asset_ids'] ?? []));

        if (!$f_name)    $errors[]=$rtl?'مسمى اللجنة مطلوب ⚠️':'Committee name required';
        if (!$f_type_id) $errors[]=$rtl?'نوع اللجنة مطلوب ⚠️':'Committee type required';
        if (!$f_purpose) $errors[]=$rtl?'مهام اللجنة مطلوبة ⚠️':'Committee tasks required';
        if (empty($members)) $errors[]=$rtl?'يجب إضافة عضو واحد على الأقل':'Add at least one member';
        $has_mgr=false;
        foreach($members as $m) if(($m['role']??'')==='manager'){$has_mgr=true;break;}
        if(!$has_mgr) $errors[]=$rtl?'يجب تحديد رئيس للجنة':'Set a committee manager';

        // التحقق من الأصول للأنواع التي تستوجبها
        if ($f_type_id && $f_action==='submit') {
            $tn=$pdo->prepare("SELECT name FROM committee_types WHERE id=? LIMIT 1");
            $tn->execute([$f_type_id]); $tname_val=(string)$tn->fetchColumn();
            if (type_requires_assets($tname_val) && empty($asset_ids))
                $errors[]=$rtl?'⚠️ هذا النوع من اللجان يستلزم إضافة الأصول المعنية بالقرار':'Assets are required for this committee type';
        }

        if (empty($errors)) {
            // هل التفعيل مباشر؟
            $is_direct = $can_direct && isset($_POST['direct_activate']);
            $type_req  = (int)($type_approval_map[$f_type_id] ?? 1);
            if ($f_action === 'submit' && ($is_direct || $type_req === 0)) {
                $status = 'active'; // تفعيل مباشر بدون اعتماد
            } else {
                $status = $f_action === 'submit' ? 'requested' : 'draft';
            }
            if ($edit) {
                $pdo->prepare("UPDATE committees SET name=?,committee_type_id=?,purpose=?,status=? WHERE id=?")
                    ->execute([$f_name,$f_type_id,$f_purpose?:null,$status,$id]);
                $pdo->prepare("DELETE FROM committee_members WHERE committee_id=?")->execute([$id]);
                $pdo->prepare("DELETE FROM committee_assets  WHERE committee_id=?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO committees (name,committee_type_id,purpose,status,created_by,requested_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$f_name,$f_type_id,$f_purpose?:null,$status,$uid,$uid]);
                $id=(int)$pdo->lastInsertId();
            }
            $sm=$pdo->prepare("INSERT INTO committee_members (committee_id,user_id,role,sort_order) VALUES (?,?,?,?)");
            foreach($members as $i=>$m){$mu=(int)($m['user_id']??0);if($mu)$sm->execute([$id,$mu,$m['role']??'receiver',$i+1]);}
            if(!empty($asset_ids)){$sa=$pdo->prepare("INSERT IGNORE INTO committee_assets (committee_id,asset_id) VALUES (?,?)");foreach($asset_ids as $aid)$sa->execute([$id,$aid]);}
            // مرفقات
            if(!empty($_FILES['attachments']['name'][0])){
                $upd=BASE_PATH.'/uploads/committees/'.$id.'/';
                if(!is_dir($upd))mkdir($upd,0755,true);
                $si=$pdo->prepare("INSERT INTO committee_attachments (committee_id,file_name,file_path,file_size,file_type,uploaded_by) VALUES (?,?,?,?,?,?)");
                foreach($_FILES['attachments']['name'] as $fi=>$fn){
                    if(!$fn||$_FILES['attachments']['error'][$fi])continue;
                    $ext=strtolower(pathinfo($fn,PATHINFO_EXTENSION));
                    $safe=time().'_'.$fi.'.'.$ext;
                    if(move_uploaded_file($_FILES['attachments']['tmp_name'][$fi],$upd.$safe))
                        $si->execute([$id,$fn,'committees/'.$id.'/'.$safe,$_FILES['attachments']['size'][$fi],$_FILES['attachments']['type'][$fi],$uid]);
                }
            }
            $pdo->prepare("INSERT INTO committee_actions (committee_id,user_id,action,old_status,new_status) VALUES (?,?,?,?,?)")
                ->execute([$id,$uid,$edit?'updated':'created',$c['status']??null,$status]);
            if($f_action==='submit'){
                if($status === 'active') {
                    // تفعيل مباشر — أشعر الأعضاء مباشرة
                    foreach($members as $m){
                        $mu=(int)($m['user_id']??0);
                        if($mu) notify($mu,'committee_approved',
                            'تم تشكيلك عضواً في لجنة جديدة',
                            "تمت إضافتك في لجنة «{$f_name}» — ستصلك إشعارات عند بدء إجراءات التسليم",
                            BASE_URL.'/committees/view.php?id='.$id);
                    }
                    flash('success',$rtl?'✅ تم تشكيل اللجنة وتفعيلها مباشرة — أُرسلت إشعارات للأعضاء':'Committee activated directly ✅');
                } else {
                    notify_committee_submitted($id,$f_name,$uid);
                    flash('success',$rtl?'تم إرسال طلب اللجنة للاعتماد 📤':'Sent for approval');
                }
            } else {
                flash('success',$edit?($rtl?'تم الحفظ':'Saved'):($rtl?'تم حفظ المسودة':'Draft saved'));
            }
            header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
        }
    }
}

$types = $pdo->query("SELECT id,name,name_en,COALESCE(requires_approval,1) AS requires_approval FROM committee_types WHERE is_active=1 ORDER BY sort_order")->fetchAll();

// صلاحية التفعيل المباشر
$can_direct      = can('committees.index','direct_activate');
$type_approval_map = [];
foreach($types as $t) $type_approval_map[(int)$t['id']] = (int)($t['requires_approval']??1);
$users = $pdo->query("SELECT id,full_name,username,job_title FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

// بناء خريطة الأنواع التي تستوجب أصولاً للـ JS
$asset_required_ids = [];
foreach($types as $t) if(type_requires_assets($t['name'])) $asset_required_ids[]=$t['id'];

$p = empty($_POST)?$c:$_POST;
$role_cfg=['manager'=>['ar'=>'رئيس اللجنة','en'=>'Manager','c'=>'#1565C0','b'=>'#E3F2FD','i'=>'fa-user-tie'],'technical'=>['ar'=>'عضو فني','en'=>'Technical','c'=>'#7B1FA2','b'=>'#F3E5F5','i'=>'fa-screwdriver-wrench'],'receiver'=>['ar'=>'مستلم','en'=>'Receiver','c'=>'#16a34a','b'=>'#F0FDF4','i'=>'fa-hand-holding'],'other'=>['ar'=>'عضو آخر','en'=>'Other','c'=>'#64748b','b'=>'#F8FAFC','i'=>'fa-user']];
$is_return=($c['status']??'')==='returned';
$page_title=$edit?($rtl?'تعديل طلب اللجنة':'Edit Request'):($rtl?'طلب تشكيل لجنة جديدة':'New Committee Request');
$active_nav='committees.index';
$breadcrumb=[['name'=>$rtl?'اللجان':'Committees','url'=>BASE_URL.'/committees/index.php'],['name'=>$page_title]];
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
.fl{display:grid;grid-template-columns:1fr 380px;gap:16px;align-items:start}
@media(max-width:1100px){.fl{grid-template-columns:1fr}}
.fc{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;margin-bottom:14px}
.fch{padding:13px 18px;border-bottom:1px solid #f1f5f9;font-size:13.5px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px;justify-content:space-between}
.fch i{color:#1565C0}
.fb{padding:18px}
.fg{display:flex;flex-direction:column;gap:4px;margin-bottom:13px}
.fg:last-child{margin-bottom:0}
.fg label{font-size:12px;font-weight:700;color:#475569;display:flex;align-items:center;gap:4px}
.req{color:#dc2626;font-size:14px;line-height:1}
.fi{height:40px;padding-inline:12px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Tajawal',sans-serif;font-size:13.5px;outline:none;transition:.2s;color:#0f172a;background:#fff;width:100%;box-sizing:border-box}
.fi:focus{border-color:#1565C0;box-shadow:0 0 0 3px rgba(21,101,192,.08)}
.fi.err{border-color:#dc2626}
textarea.fi{height:90px;padding-top:10px;resize:vertical}
/* سبب الإعادة */
.return-box{background:#fffbeb;border:2px solid #fcd34d;border-radius:12px;padding:13px 16px;margin-bottom:14px}
/* الأعضاء */
.mb-row{display:flex;align-items:center;gap:8px;padding:9px 12px;border-radius:10px;background:#f8fafc;margin-bottom:6px;border:1px solid #e2e8f0}
.mb-ord{width:24px;height:24px;border-radius:50%;background:#1565C0;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.mb-del{width:28px;height:28px;border-radius:7px;border:1.5px solid #fecaca;color:#dc2626;background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;transition:.15s}
.mb-del:hover{background:#dc2626;color:#fff}
.add-form{background:#f8fafc;border-radius:10px;padding:12px;border:1px dashed #e2e8f0}
.add-form select{height:36px;padding-inline:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:13px;background:#fff;outline:none;width:100%;box-sizing:border-box;transition:.2s}
.add-form select:focus{border-color:#1565C0}
/* بحث الأصول */
.asset-search-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:10px}
.asset-search-row{display:flex;gap:7px;margin-bottom:8px}
.as-inp{height:36px;padding-inline:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:13px;background:#fff;outline:none;flex:1;transition:.2s}
.as-inp:focus{border-color:#1565C0}
.as-sel{height:36px;padding-inline:8px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:12.5px;background:#fff;outline:none;width:130px}
.as-results{max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;background:#fff}
.as-result-item{display:flex;align-items:center;gap:8px;padding:8px 11px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:.12s;font-size:12.5px}
.as-result-item:last-child{border-bottom:none}
.as-result-item:hover{background:#eff6ff}
.as-add-btn{width:26px;height:26px;border-radius:6px;background:#1565C0;color:#fff;border:none;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
/* chips الأصول */
.asset-chips{min-height:32px;display:flex;flex-wrap:wrap;gap:5px;margin-bottom:8px}
.asset-chip{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:50px;padding:3px 10px;font-size:12px;font-weight:600;color:#16a34a}
.asset-chip .rc{background:none;border:none;cursor:pointer;color:#dc2626;font-size:11px;padding:0;line-height:1;font-weight:700}
/* رسالة خطأ الأصول */
.asset-error{display:none;background:#fef2f2;border:1.5px solid #fecaca;border-radius:9px;padding:10px 13px;font-size:12.5px;color:#dc2626;margin-top:8px;align-items:center;gap:7px}
/* مرفقات */
.att-item{display:flex;align-items:center;gap:8px;padding:7px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:5px;font-size:12.5px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php if($errors): ?>
<div class="alert alert-danger" style="margin-bottom:14px">
  <i class="fa-solid fa-circle-exclamation"></i>
  <ul style="margin:4px 0 0;padding-inline-start:18px">
    <?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php foreach($flash_msgs as $fm): ?><div class="alert alert-<?= $fm['type'] ?>" style="margin-bottom:12px"><i class="fa-solid fa-circle-<?= $fm['type']==='success'?'check':'exclamation' ?>"></i> <?= e($fm['message']) ?></div><?php endforeach; ?>

<?php if($is_return&&!empty($c['return_reason'])): ?>
<div class="return-box">
  <div style="font-size:13px;font-weight:700;color:#92400e;margin-bottom:4px"><i class="fa-solid fa-rotate-left"></i> <?= $rtl?'سبب الإعادة للتصحيح:':'Returned for:' ?></div>
  <div style="font-size:13px;color:#78350f"><?= e($c['return_reason']) ?></div>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="cfForm">
<?= csrf_input() ?>
<input type="hidden" name="form_action" id="fAction" value="draft">
<div id="assetHidden"></div>

<div class="fl">
<div>

<!-- ══ بيانات اللجنة ══ -->
<div class="fc">
  <div class="fch"><span><i class="fa-solid fa-users-gear"></i><?= $rtl?'بيانات اللجنة':'Committee Details' ?></span></div>
  <div class="fb">
    <div class="fg">
      <label><i class="fa-solid fa-signature" style="font-size:10px;color:#94a3b8"></i><?= $rtl?'مسمى اللجنة':'Committee Name' ?><span class="req">*</span></label>
      <input type="text" name="name" class="fi <?= in_array($rtl?'مسمى اللجنة مطلوب ⚠️':'Committee name required',$errors)?'err':'' ?>"
        required value="<?= e($p['name']??'') ?>"
        placeholder="<?= $rtl?'مثال: لجنة استلام الأجهزة الطبية — Q1-2026':'e.g. Medical Equipment Receiving Committee' ?>">
    </div>

    <div class="fg">
      <label><i class="fa-solid fa-tag" style="font-size:10px;color:#94a3b8"></i><?= $rtl?'نوع اللجنة':'Committee Type' ?><span class="req">*</span></label>
      <input type="hidden" name="committee_type_id" class="fi" id="selType">
      <!-- نوع اللجنة مع data-requires-approval -->
      <select name="committee_type_id" class="fi <?= in_array($rtl?'نوع اللجنة مطلوب ⚠️':'Committee type required',$errors)?'err':'' ?>"
        id="selType" onchange="onTypeChange()" required>
        <option value=""><?= $rtl?'— اختر نوع اللجنة —':'— Select Type —' ?></option>
        <?php foreach($types as $t): ?>
        <option value="<?= $t['id'] ?>"
          data-req="<?= (int)$t['requires_approval'] ?>"
          <?= (($p['committee_type_id']??$c['committee_type_id']??'')==$t['id'])?'selected':'' ?>>
          <?= e($rtl?$t['name']:($t['name_en']?:$t['name'])) ?>
          <?php if(!(int)$t['requires_approval']): ?> ⚡<?php endif; ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="fg">
      <label><i class="fa-solid fa-list-check" style="font-size:10px;color:#94a3b8"></i><?= $rtl?'مهام اللجنة':'Committee Tasks' ?><span class="req">*</span></label>
      <textarea name="purpose" class="fi <?= in_array($rtl?'مهام اللجنة مطلوبة ⚠️':'Committee tasks required',$errors)?'err':'' ?>"
        required placeholder="<?= $rtl?'اكتب مهام اللجنة ودورها بشكل موجز...':'Describe the committee tasks and objectives...' ?>"><?= e($p['purpose']??$c['purpose']??'') ?></textarea>
    </div>
  </div>
</div>

<!-- ══ الأصول المعنية (تظهر فقط لتكهين/إتلاف/نقل) ══ -->
<div class="fc" id="assetsSection" style="display:none">
  <div class="fch">
    <span><i class="fa-solid fa-boxes-stacked"></i><?= $rtl?'الأصول المعنية بالقرار':'Assets Involved' ?></span>
    <span style="font-size:11px;color:#dc2626;background:#fef2f2;border-radius:5px;padding:1px 8px;font-weight:600">
      <i class="fa-solid fa-star" style="font-size:8px"></i> <?= $rtl?'مطلوب لهذا النوع':'Required' ?>
    </span>
  </div>
  <div class="fb">
    <!-- chips الأصول المختارة -->
    <div class="asset-chips" id="assetChips">
      <?php foreach($existing_assets as $ea): ?>
      <span class="asset-chip" id="chip_<?= $ea['asset_id'] ?>">
        <i class="fa-solid fa-tag" style="font-size:9px"></i>
        <?= e(mb_substr($ea['description']??'',0,35)) ?> [<?= e($ea['tag_number']??'') ?>]
        <button type="button" class="rc" onclick="removeAsset(<?= $ea['asset_id'] ?>,'<?= addslashes(mb_substr($ea['description']??'',0,35)) ?>')">✕</button>
      </span>
      <?php endforeach; ?>
    </div>

    <!-- رسالة الخطأ -->
    <div class="asset-error" id="assetError">
      <i class="fa-solid fa-triangle-exclamation"></i>
      <?= $rtl?'يجب إضافة الأصول المعنية بأعمال هذه اللجنة قبل الإرسال':'Assets must be added for this committee type before sending' ?>
    </div>

    <!-- صندوق البحث -->
    <div class="asset-search-box">
      <div style="font-size:11.5px;font-weight:700;color:#475569;margin-bottom:8px"><i class="fa-solid fa-magnifying-glass" style="font-size:10px;color:#94a3b8"></i> <?= $rtl?'ابحث عن أصل وأضفه:':'Search and add an asset:' ?></div>
      <div class="asset-search-row">
        <input type="text" id="assetQ" class="as-inp"
          placeholder="<?= $rtl?'وصف، رقم Tag، رقم تسلسلي...':'Description, Tag No., Serial...' ?>"
          onkeydown="if(event.key==='Enter'){event.preventDefault();searchAssets();}">
        <select id="assetMaint" class="as-sel">
          <option value=""><?= $rtl?'كل الأنواع':'All Types' ?></option>
          <option value="medical"><?= $rtl?'طبية':'Medical' ?></option>
          <option value="it"><?= $rtl?'تقنية معلومات':'IT' ?></option>
          <option value="general"><?= $rtl?'صيانة عامة':'General' ?></option>
        </select>
        <button type="button" onclick="searchAssets()" class="btn btn-primary btn-sm">
          <i class="fa-solid fa-magnifying-glass"></i><?= $rtl?'بحث':'Search' ?>
        </button>
      </div>
      <div id="assetResults" style="display:none" class="as-results"></div>
      <div id="assetLoading" style="display:none;text-align:center;padding:10px;color:#94a3b8;font-size:12px">
        <i class="fa-solid fa-circle-notch fa-spin"></i> <?= $rtl?'جاري البحث...':'Searching...' ?>
      </div>
    </div>
  </div>
</div>

<!-- ══ المرفقات ══ -->
<div class="fc">
  <div class="fch"><span><i class="fa-solid fa-paperclip"></i><?= $rtl?'المرفقات (اختياري)':'Attachments (optional)' ?></span></div>
  <div class="fb">
    <?php foreach($existing_attachments as $att): ?>
    <div class="att-item">
      <i class="fa-solid fa-file" style="color:#1565C0;font-size:13px"></i>
      <span style="flex:1"><?= e($att['file_name']) ?></span>
      <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank" style="color:#1565C0;font-size:12px"><i class="fa-solid fa-eye"></i></a>
    </div>
    <?php endforeach; ?>
    <div style="border:2px dashed #e2e8f0;border-radius:10px;padding:16px;text-align:center">
      <i class="fa-solid fa-cloud-arrow-up" style="font-size:26px;color:#94a3b8;display:block;margin-bottom:8px"></i>
      <input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="font-family:'Tajawal';font-size:13px;width:100%">
      <div style="font-size:11px;color:#94a3b8;margin-top:5px"><?= $rtl?'PDF، Word، Excel، صور':'PDF, Word, Excel, Images' ?></div>
    </div>
  </div>
</div>

</div><!-- main col -->

<!-- ══ العمود الجانبي ══ -->
<div>

<!-- الأعضاء -->
<div class="fc">
  <div class="fch" style="justify-content:space-between">
    <span><i class="fa-solid fa-users"></i><?= $rtl?'أعضاء اللجنة':'Members' ?><span class="req">*</span></span>
    <span id="mbCount" style="font-size:12px;color:#64748b;font-weight:400">0 <?= $rtl?'عضو':'members' ?></span>
  </div>
  <div id="mbList" style="padding:8px 12px;min-height:50px"></div>
  <div id="mbHidden"></div>
  <div style="padding:10px 14px;border-top:1px solid #f1f5f9">
    <div style="font-size:11.5px;font-weight:700;color:#475569;margin-bottom:7px"><?= $rtl?'➕ إضافة عضو':'➕ Add Member' ?></div>
    <div class="add-form">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:7px">
        <div>
          <div style="font-size:10.5px;font-weight:700;color:#64748b;margin-bottom:4px"><?= $rtl?'الموظف':'User' ?></div>
          <select id="selUser">
            <option value=""><?= $rtl?'— اختر —':'— Select —' ?></option>
            <?php foreach($users as $u): ?>
            <option value="<?= $u['id'] ?>" data-name="<?= e($u['full_name']) ?>"><?= e($u['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div style="font-size:10.5px;font-weight:700;color:#64748b;margin-bottom:4px"><?= $rtl?'الدور':'Role' ?></div>
          <select id="selRole">
            <?php foreach($role_cfg as $rv=>$rl): ?>
            <option value="<?= $rv ?>"><?= $rtl?e($rl['ar']):e($rl['en']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="button" onclick="addMember()" class="btn btn-primary btn-sm" style="width:100%">
        <i class="fa-solid fa-plus"></i><?= $rtl?'إضافة للقائمة':'Add to List' ?>
      </button>
    </div>
    <div style="font-size:11px;color:#94a3b8;margin-top:7px;display:flex;align-items:center;gap:4px">
      <i class="fa-solid fa-info-circle" style="font-size:10px"></i>
      <?= $rtl?'الترتيب يحدد تسلسل الإشعارات والتوقيعات':'Order determines notification sequence' ?>
    </div>
  </div>
</div>

<!-- الإجراءات -->
<div class="fc">
  <div class="fch"><i class="fa-solid fa-paper-plane"></i><?= $rtl?'الإجراءات':'Actions' ?></div>
  <div class="fb">
    <?php if($can_direct): ?>
    <div style="margin-bottom:10px;padding:11px 13px;background:#f0fdf4;border:2px solid #bbf7d0;border-radius:10px">
      <label style="display:flex;align-items:flex-start;gap:9px;cursor:pointer">
        <input type="checkbox" name="direct_activate" id="directChk" style="width:18px;height:18px;accent-color:#16a34a;margin-top:2px;flex-shrink:0" onchange="onDirectChange()">
        <div>
          <div style="font-size:13px;font-weight:700;color:#16a34a">⚡ <?= $rtl?'تفعيل مباشر بدون اعتماد':'Direct Activation' ?></div>
          <div style="font-size:11px;color:#64748b;margin-top:2px;line-height:1.5"><?= $rtl?'اللجنة تُفعَّل فوراً بدون اعتماد المدير التنفيذي':'Activate immediately without executive approval' ?></div>
        </div>
      </label>
    </div>
    <?php endif; ?>

    <button type="button" onclick="doSubmit('draft')" class="btn" style="width:100%;background:#f1f5f9;color:#475569;justify-content:center;margin-bottom:9px">
      <i class="fa-solid fa-floppy-disk"></i><?= $rtl?'حفظ كمسودة':'Save as Draft' ?>
    </button>

    <?php if($can_direct): ?>
    <button type="button" onclick="doSubmit('submit')" id="btnDirect"
      class="btn" style="width:100%;justify-content:center;background:#16a34a;border:1.5px solid #16a34a;color:#fff;display:none;margin-bottom:9px">
      <i class="fa-solid fa-bolt"></i><?= $rtl?'⚡ تفعيل مباشر الآن':'⚡ Activate Directly Now' ?>
    </button>
    <button type="button" onclick="doSubmit('submit')" id="btnApproval"
      class="btn btn-primary" style="width:100%;justify-content:center">
      <i class="fa-solid fa-paper-plane"></i><?= $rtl?'إرسال للمدير التنفيذي 📤':'Send for Approval' ?>
    </button>
    <?php else: ?>
    <button type="button" onclick="doSubmit('submit')" class="btn btn-primary" style="width:100%;justify-content:center">
      <i class="fa-solid fa-paper-plane"></i><?= $is_return?($rtl?'إعادة الإرسال ↩':'Resubmit'):($rtl?'إرسال للمدير التنفيذي 📤':'Send for Approval') ?>
    </button>
    <?php endif; ?>

    <div id="submitHint" style="margin-top:10px;background:#eff6ff;border-radius:9px;padding:10px 12px;font-size:12px;color:#1d4ed8;line-height:1.7">
      <i class="fa-solid fa-circle-info"></i>
      <?= $rtl?'بعد الإرسال لن تستطيع التعديل.':'After sending you cannot edit.' ?>
    </div>
    <a href="<?= BASE_URL ?>/committees/index.php" class="btn btn-outline" style="width:100%;justify-content:center;margin-top:8px">
      <?= $rtl?'إلغاء':'Cancel' ?>
    </a>
  </div>
</div>

</div><!-- sidebar -->
</div><!-- fl -->
</form>

</main></div>

<script>
const _R   = <?= $rtl?'true':'false' ?>;
const _RLS = <?= json_encode($role_cfg,JSON_UNESCAPED_UNICODE) ?>;
const _ASSET_REQ_IDS = <?= json_encode($asset_required_ids) ?>;
const _BASE = '<?= BASE_URL ?>';

// ════════════ الأعضاء ════════════
let _members = <?= json_encode(array_map(fn($m)=>['user_id'=>$m['user_id'],'name'=>$m['full_name'],'role'=>$m['role']],$existing_members),JSON_UNESCAPED_UNICODE) ?>;

function renderMembers(){
    const list=document.getElementById('mbList'),hid=document.getElementById('mbHidden');
    document.getElementById('mbCount').textContent=_members.length+(_R?' عضو':' members');
    hid.innerHTML='';
    if(!_members.length){list.innerHTML='<div style="text-align:center;padding:18px;color:#94a3b8;font-size:13px"><i class="fa-solid fa-user-plus" style="font-size:22px;display:block;margin-bottom:6px;opacity:.3"></i>'+(_R?'لم يُضف أعضاء':'No members yet')+'</div>';return;}
    let h='';
    _members.forEach(function(m,i){
        const r=_RLS[m.role]||_RLS.other;
        h+='<div class="mb-row"><div class="mb-ord">'+(i+1)+'</div>'
            +'<div style="flex:1;min-width:0"><div style="font-size:13px;font-weight:700;color:#0f172a">'+(m.name||'—')+'</div>'
            +'<span style="display:inline-flex;align-items:center;gap:3px;font-size:10.5px;font-weight:700;border-radius:4px;padding:1px 7px;background:'+r.b+';color:'+r.c+'"><i class="fa-solid '+r.i+'" style="font-size:9px"></i>'+(_R?r.ar:r.en)+'</span></div>'
            +'<button type="button" class="mb-del" onclick="removeMember('+i+')"><i class="fa-solid fa-xmark"></i></button></div>';
        hid.innerHTML+='<input type="hidden" name="members['+i+'][user_id]" value="'+m.user_id+'">'
            +'<input type="hidden" name="members['+i+'][role]" value="'+m.role+'">'
            +'<input type="hidden" name="members['+i+'][sort_order]" value="'+(i+1)+'">';
    });
    list.innerHTML=h;
}
function addMember(){
    const su=document.getElementById('selUser'),sr=document.getElementById('selRole');
    const uid=su.value,name=su.options[su.selectedIndex].dataset.name||'',role=sr.value;
    if(!uid){alert(_R?'اختر موظفاً':'Select a user');return;}
    if(_members.some(function(m){return m.user_id==uid;})){alert(_R?'مضاف مسبقاً':'Already added');return;}
    _members.push({user_id:uid,name:name,role:role});
    su.value='';renderMembers();
}
function removeMember(i){_members.splice(i,1);renderMembers();}

// ════════════ الأصول ════════════
let _assets = <?= json_encode(array_map(fn($a)=>['id'=>$a['asset_id'],'desc'=>mb_substr($a['description']??'',0,40),'tag'=>$a['tag_number']??''],$existing_assets),JSON_UNESCAPED_UNICODE) ?>;

function renderAssetHidden(){
    document.getElementById('assetHidden').innerHTML=
        _assets.map(function(a){return '<input type="hidden" name="asset_ids[]" value="'+a.id+'">';}).join('');
}

function addAsset(id,desc,tag){
    if(_assets.some(function(a){return a.id==id;})){alert(_R?'مضاف مسبقاً':'Already added');return;}
    _assets.push({id:id,desc:desc,tag:tag});
    const chips=document.getElementById('assetChips');
    const chip=document.createElement('span');chip.className='asset-chip';chip.id='chip_'+id;
    chip.innerHTML='<i class="fa-solid fa-tag" style="font-size:9px"></i>'+esc(desc)+(tag?' ['+esc(tag)+']':'')
        +' <button type="button" class="rc" onclick="removeAsset('+id+',\''+esc(desc)+'\')">✕</button>';
    chips.appendChild(chip);
    renderAssetHidden();
    document.getElementById('assetError').style.display='none';
    // إخفاء النتائج بعد الإضافة
    const item=document.querySelector('.as-result-item[data-id="'+id+'"]');
    if(item)item.style.opacity='.4';
}

function removeAsset(id){
    _assets=_assets.filter(function(a){return a.id!=id;});
    const chip=document.getElementById('chip_'+id);if(chip)chip.remove();
    renderAssetHidden();
}

async function searchAssets(){
    const q=document.getElementById('assetQ').value.trim();
    const maint=document.getElementById('assetMaint').value;
    if(!q&&!maint){alert(_R?'اكتب كلمة بحث أو اختر النوع':'Enter search term or select type');return;}
    document.getElementById('assetLoading').style.display='block';
    document.getElementById('assetResults').style.display='none';
    try{
        const res=await fetch(_BASE+'/api/search_assets.php?q='+encodeURIComponent(q)+'&maint='+maint+'&limit=15');
        const data=await res.json();
        const box=document.getElementById('assetResults');
        if(!data.length){box.innerHTML='<div style="text-align:center;padding:14px;color:#94a3b8;font-size:12.5px">'+(_R?'لا نتائج — جرّب كلمة أخرى':'No results — try another keyword')+'</div>';}
        else{
            box.innerHTML=data.map(function(a){
                const added=_assets.some(function(x){return x.id==a.id;});
                return '<div class="as-result-item" data-id="'+a.id+'" onclick="addAsset('+a.id+',\''+esc(a.description||'')+'\',\''+esc(a.tag_number||'')+'\')">'
                    +'<button type="button" class="as-add-btn" '+(added?'style="background:#16a34a"':'')+'><i class="fa-solid fa-'+(added?'check':'plus')+'"></i></button>'
                    +'<div><div style="font-size:12.5px;font-weight:600;color:#0f172a">'+esc(a.description||'—')+'</div>'
                    +'<div style="font-size:11px;color:#64748b">'+(a.tag_number?'Tag: '+a.tag_number+' · ':'')+(a.manufacturer_name||'')+(a.loc_building?' · '+a.loc_building:'')+'</div></div>'
                    +'</div>';
            }).join('');
        }
        box.style.display='block';
    }catch(e){document.getElementById('assetResults').innerHTML='<div style="padding:10px;color:#dc2626;font-size:12px">خطأ في البحث</div>';document.getElementById('assetResults').style.display='block';}
    document.getElementById('assetLoading').style.display='none';
}

// ════════════ نوع اللجنة → إظهار/إخفاء الأصول ════════════
function onDirectChange(){
    const chk=document.getElementById('directChk');
    const btnD=document.getElementById('btnDirect');
    const btnA=document.getElementById('btnApproval');
    const hint=document.getElementById('submitHint');
    if(!chk)return;
    if(chk.checked){
        if(btnD)btnD.style.display='flex';
        if(btnA)btnA.style.display='none';
        if(hint){hint.style.background='#f0fdf4';hint.style.color='#16a34a';hint.innerHTML='<i class="fa-solid fa-circle-check"></i> '+(_R?'اللجنة ستُفعَّل فوراً — سيُشعَر الأعضاء مباشرة':'Committee will activate immediately');}
    } else {
        if(btnD)btnD.style.display='none';
        if(btnA)btnA.style.display='flex';
        if(hint){hint.style.background='#eff6ff';hint.style.color='#1d4ed8';hint.innerHTML='<i class="fa-solid fa-circle-info"></i> '+(_R?'بعد الإرسال لن تستطيع التعديل.':'After sending you cannot edit.');}
    }
}

const _TYPE_APPROVAL = <?= json_encode($type_approval_map) ?>;

function onTypeChange(){
    const sel=document.getElementById('selType');
    const val=parseInt(sel.value)||0;
    const opt=sel.options[sel.selectedIndex];
    const reqApproval=parseInt(opt?.dataset?.req??1);
    const sec=document.getElementById('assetsSection');
    if(_ASSET_REQ_IDS.includes(val)){sec.style.display='';}
    else{sec.style.display='';}
    // تحديث خيار التفعيل المباشر
    updateDirectBox(val,reqApproval);
}

function updateDirectBox(typeId,reqApproval){
    if(!_CAN_DIRECT) return;
    const box=document.getElementById('directBox');
    const chk=document.getElementById('directChk');
    const label=document.getElementById('submitLabel');
    const hint=document.getElementById('submitHint');
    if(!box) return;
    if(reqApproval===0){
        // النوع لا يحتاج اعتماد → اخفِ الصندوق وفعّل مباشرة
        box.style.display='none';
        chk.checked=true;
        if(label) label.textContent=_R?'⚡ إرسال وتفعيل مباشر':'⚡ Send & Activate Directly';
        if(hint){hint.style.background='#f0fdf4';hint.style.color='#16a34a';hint.innerHTML='<i class="fa-solid fa-circle-check"></i> '+(_R?'هذا النوع من اللجان يُفعَّل مباشرة بدون اعتماد المدير التنفيذي':'This type activates directly without executive approval');}
    } else {
        box.style.display='';
        const isDirect=chk&&chk.checked;
        if(label) label.textContent=isDirect?(_R?'⚡ إرسال وتفعيل مباشر':'⚡ Send & Activate Directly'):(_R?'إرسال للمدير التنفيذي 📤':'Send for Executive Approval');
        if(hint){hint.style.background=isDirect?'#f0fdf4':'#eff6ff';hint.style.color=isDirect?'#16a34a':'#1d4ed8';hint.innerHTML='<i class="fa-solid fa-circle-'+(isDirect?'check':'info')+'"></i> '+(_R?(isDirect?'اللجنة ستُفعَّل مباشرة — سيُشعَر الأعضاء فوراً':'بعد الإرسال سيصل الطلب للمدير التنفيذي'):(isDirect?'Committee activates directly — members notified immediately':'Request goes to Executive Director'));}
    }
}

document.addEventListener('DOMContentLoaded',function(){
    const chk=document.getElementById('directChk');
    if(chk) chk.addEventListener('change',function(){
        const sel=document.getElementById('selType');
        const opt=sel?.options[sel.selectedIndex];
        updateDirectBox(parseInt(sel?.value)||0,parseInt(opt?.dataset?.req??1));
    });
    // تشغيل عند التحميل
    const sel=document.getElementById('selType');
    if(sel&&sel.value){
        const opt=sel.options[sel.selectedIndex];
        updateDirectBox(parseInt(sel.value)||0,parseInt(opt?.dataset?.req??1));
    }
});

// ════════════ الإرسال ════════════
function doSubmit(action){
    if(action==='submit'){
        if(!_members.length){alert(_R?'يجب إضافة أعضاء اللجنة':'Add committee members');return;}
        if(!_members.some(function(m){return m.role==='manager';})){alert(_R?'يجب تحديد رئيس للجنة':'Set a manager');return;}
        // التحقق من الأصول
        const sel=document.getElementById('selType');
        const val=parseInt(sel.value)||0;
        if(_ASSET_REQ_IDS.includes(val)&&!_assets.length){
            const errBox=document.getElementById('assetError');
            errBox.style.display='flex';
            errBox.scrollIntoView({behavior:'smooth',block:'center'});
            return;
        }
        if(!confirm(_R?'إرسال الطلب للمدير التنفيذي؟':'Send to Executive Director?'))return;
    }
    document.getElementById('fAction').value=action;
    renderAssetHidden();
    document.getElementById('cfForm').submit();
}

function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,"&#39;");}

// init
renderMembers();renderAssetHidden();onTypeChange();
</script>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>