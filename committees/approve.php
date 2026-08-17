<?php
/**
 * committees/approve.php — صفحة اعتماد المدير التنفيذي / Admin
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('committees.approve');

$rtl = is_rtl();
$uid = (int)current_user()['id'];
$id  = (int)($_GET['id'] ?? 0);

if (!$id) { flash('danger',$rtl?'غير محدد':'Not specified'); header('Location:'.BASE_URL.'/committees/index.php'); exit; }

$s=$pdo->prepare("SELECT c.*,ct.name AS type_name,u.full_name AS creator_name
    FROM committees c LEFT JOIN committee_types ct ON ct.id=c.committee_type_id
    LEFT JOIN users u ON u.id=c.created_by WHERE c.id=? LIMIT 1");
$s->execute([$id]); $committee=$s->fetch();
if (!$committee||$committee['status']!=='requested') {
    flash('warning',$rtl?'هذا الطلب لا يحتاج اعتماداً في الوقت الحالي':'Not pending approval');
    header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
}

$members=$pdo->prepare("SELECT cm.*,u.full_name,u.job_title FROM committee_members cm LEFT JOIN users u ON u.id=cm.user_id WHERE cm.committee_id=? ORDER BY cm.sort_order");
$members->execute([$id]); $members=$members->fetchAll();

$linked_assets=$pdo->prepare("SELECT ca.*,a.description,a.tag_number,a.asset_number,a.status AS ast_status FROM committee_assets ca LEFT JOIN assets a ON a.id=ca.asset_id WHERE ca.committee_id=?");
$linked_assets->execute([$id]); $linked_assets=$linked_assets->fetchAll();

$attachments=$pdo->prepare("SELECT * FROM committee_attachments WHERE committee_id=? ORDER BY id");
$attachments->execute([$id]); $attachments=$attachments->fetchAll();

$actions=$pdo->prepare("SELECT ca.*,u.full_name FROM committee_actions ca LEFT JOIN users u ON u.id=ca.user_id WHERE ca.committee_id=? ORDER BY ca.id");
$actions->execute([$id]); $actions=$actions->fetchAll();

// ── POST: الاعتماد / الإعادة / الرفض ────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST'&&verify_csrf()) {
    $decision = $_POST['decision'] ?? '';
    $reason   = trim($_POST['reason'] ?? '');
    $old_st   = $committee['status'];
    $cname    = $committee['name'];
    $req_id   = (int)($committee['created_by'] ?? $committee['requested_by'] ?? 0);

    if ($decision==='approve') {
        $pdo->prepare("UPDATE committees SET status='active',approved_by=?,approved_at=NOW() WHERE id=?")
            ->execute([$uid,$id]);
        $pdo->prepare("INSERT INTO committee_actions (committee_id,user_id,action,old_status,new_status,notes) VALUES (?,?,'approved',?,?,?)")
            ->execute([$id,$uid,$old_st,'active',$rtl?'تمت الموافقة':'Approved']);
        // إشعار المنشئ
        notify_committee_approved($id,$cname,$req_id);
        // إشعار العضو الأول تسلسلياً
        if(!empty($members)) {
            $first=$members[0];
            notify_member_sign_request($id,$cname,(int)$first['user_id'],1);
        }
        flash('success',$rtl?'تمت الموافقة على اللجنة وأُرسلت إشعارات الأعضاء ✅':'Committee approved & member notifications sent');

    } elseif ($decision==='return') {
        if (!$reason) { flash('danger',$rtl?'يجب ذكر سبب الإعادة':'Return reason required'); header('Location:'.$_SERVER['REQUEST_URI']); exit; }
        $rc = (int)$committee['return_count'] + 1;
        $pdo->prepare("UPDATE committees SET status='returned',return_reason=?,return_count=? WHERE id=?")->execute([$reason,$rc,$id]);
        $pdo->prepare("INSERT INTO committee_actions (committee_id,user_id,action,old_status,new_status,notes) VALUES (?,?,'returned',?,'returned',?)")
            ->execute([$id,$uid,$old_st,$reason]);
        notify_committee_returned($id,$cname,$req_id,$reason);
        flash('warning',$rtl?'تم إعادة الطلب للتصحيح':'Request returned for correction');

    } elseif ($decision==='reject') {
        if (!$reason) { flash('danger',$rtl?'يجب ذكر سبب الرفض':'Rejection reason required'); header('Location:'.$_SERVER['REQUEST_URI']); exit; }
        $pdo->prepare("UPDATE committees SET status='rejected',rejection_reason=?,approved_by=? WHERE id=?")->execute([$reason,$uid,$id]);
        $pdo->prepare("INSERT INTO committee_actions (committee_id,user_id,action,old_status,new_status,notes) VALUES (?,?,'rejected',?,'rejected',?)")
            ->execute([$id,$uid,$old_st,$reason]);
        notify_committee_rejected($id,$cname,$req_id,$reason);
        flash('danger',$rtl?'تم رفض طلب تشكيل اللجنة':'Committee request rejected');
    }
    header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
}

$role_ar=['manager'=>'رئيس اللجنة','technical'=>'عضو فني','receiver'=>'مستلم','other'=>'عضو'];
$page_title=$rtl?'اعتماد طلب اللجنة':'Approve Committee Request';
$active_nav='committees.index';
$breadcrumb=[['name'=>$rtl?'اللجان':'Committees','url'=>BASE_URL.'/committees/index.php'],['name'=>$page_title]];
$flash_msgs=get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.al{display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start}
@media(max-width:1024px){.al{grid-template-columns:1fr}}
.ac{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;margin-bottom:14px}
.ach{padding:13px 18px;border-bottom:1px solid #f1f5f9;font-size:13.5px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px}
.ach i{color:#1565C0;font-size:13px}
.acb{padding:18px}
.mb-item{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f8fafc}
.mb-item:last-child{border-bottom:none}
.mb-num{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
/* decision buttons */
.dec-btn{width:100%;padding:12px;border-radius:11px;border:2px solid transparent;cursor:pointer;font-family:'Tajawal',sans-serif;font-size:13.5px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;transition:.15s;margin-bottom:8px}
.dec-approve{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0}.dec-approve:hover{background:#16a34a;color:#fff}
.dec-return{background:#fffbeb;color:#d97706;border-color:#fde68a}.dec-return:hover{background:#d97706;color:#fff}
.dec-reject{background:#fef2f2;color:#dc2626;border-color:#fecaca}.dec-reject:hover{background:#dc2626;color:#fff}
.reason-box{display:none;margin-top:8px}
.reason-box textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:9px;padding:10px;font-family:'Tajawal';font-size:13px;resize:vertical;min-height:80px;outline:none;box-sizing:border-box}
.reason-box textarea:focus{border-color:#1565C0}
/* timeline actions */
.tl-item{display:flex;gap:10px;padding:9px 0;border-bottom:1px solid #f8fafc;font-size:12.5px}
.tl-dot{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;background:#f1f5f9;color:#64748b}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<?php foreach($flash_msgs as $fm): ?><div class="alert alert-<?= $fm['type'] ?>" style="margin-bottom:12px"><i class="fa-solid fa-circle-<?= $fm['type']==='success'?'check':'exclamation' ?>"></i> <?= e($fm['message']) ?></div><?php endforeach; ?>

<!-- Hero -->
<div style="background:linear-gradient(135deg,#1e3a8a,#1565C0);color:#fff;border-radius:16px;padding:18px 22px;margin-bottom:14px;display:flex;align-items:center;gap:14px">
  <div style="width:48px;height:48px;background:rgba(255,255,255,.15);border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0"><i class="fa-solid fa-users-gear"></i></div>
  <div style="flex:1">
    <div style="font-size:11px;opacity:.7"><?= $rtl?'طلب اعتماد لجنة':'Committee Approval Request' ?></div>
    <div style="font-size:18px;font-weight:800"><?= e($committee['name']) ?></div>
    <div style="font-size:12px;opacity:.75;margin-top:2px"><i class="fa-solid fa-user" style="font-size:10px"></i> <?= e($committee['creator_name']??'—') ?> · <?= e($committee['type_name']??'—') ?></div>
  </div>
  <a href="<?= BASE_URL ?>/committees/index.php" style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:9px;padding:7px 14px;font-size:12px;text-decoration:none;font-weight:600;display:flex;align-items:center;gap:5px">
    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i><?= $rtl?'رجوع':'Back' ?>
  </a>
</div>

<div class="al">
<div>

  <!-- الأعضاء -->
  <div class="ac">
    <div class="ach"><i class="fa-solid fa-users"></i><?= $rtl?'أعضاء اللجنة المقترحون':'Proposed Members' ?></div>
    <div class="acb">
      <?php if(empty($members)): ?>
      <div style="text-align:center;padding:20px;color:#94a3b8"><?= $rtl?'لا أعضاء':'No members' ?></div>
      <?php else: foreach($members as $m): ?>
      <div class="mb-item">
        <div class="mb-num"><?= $m['sort_order'] ?></div>
        <div style="flex:1">
          <div style="font-size:13.5px;font-weight:700;color:#0f172a"><?= e($m['full_name']??'—') ?></div>
          <?php if($m['job_title']): ?><div style="font-size:11.5px;color:#64748b"><?= e($m['job_title']) ?></div><?php endif; ?>
        </div>
        <span style="font-size:11px;font-weight:700;padding:2px 9px;border-radius:4px;background:#E3F2FD;color:#1565C0">
          <?= $role_ar[$m['role']]??$m['role'] ?>
        </span>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- الأصول المرتبطة -->
  <?php if(!empty($linked_assets)): ?>
  <div class="ac">
    <div class="ach"><i class="fa-solid fa-boxes-stacked"></i><?= $rtl?'الأصول المعنية':'Assets Involved' ?></div>
    <div class="acb">
      <?php foreach($linked_assets as $la): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid #f8fafc">
        <i class="fa-solid fa-tag" style="color:#7B1FA2;font-size:12px"></i>
        <div>
          <div style="font-size:13px;font-weight:600"><?= e($la['description']??'—') ?></div>
          <div style="font-size:11px;color:#64748b"><?= e($la['tag_number']??'') ?> · <?= e($la['asset_number']??'') ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- المرفقات -->
  <?php if(!empty($attachments)): ?>
  <div class="ac">
    <div class="ach"><i class="fa-solid fa-paperclip"></i><?= $rtl?'المرفقات':'Attachments' ?></div>
    <div class="acb">
      <?php foreach($attachments as $att): ?>
      <div style="display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid #f8fafc;font-size:13px">
        <i class="fa-solid fa-file" style="color:#1565C0"></i>
        <span style="flex:1"><?= e($att['file_name']) ?></span>
        <a href="<?= BASE_URL.'/uploads/'.$att['file_path'] ?>" target="_blank" style="color:#1565C0"><i class="fa-solid fa-download"></i></a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- الغرض -->
  <?php if($committee['purpose']): ?>
  <div class="ac">
    <div class="ach"><i class="fa-solid fa-bullseye"></i><?= $rtl?'الغرض والهدف':'Purpose' ?></div>
    <div class="acb" style="font-size:13.5px;color:#334155;line-height:1.7"><?= e($committee['purpose']) ?></div>
  </div>
  <?php endif; ?>

  <!-- سجل الإجراءات -->
  <?php if(!empty($actions)): ?>
  <div class="ac">
    <div class="ach"><i class="fa-solid fa-timeline"></i><?= $rtl?'سجل الإجراءات':'Action Log' ?></div>
    <div class="acb">
      <?php foreach($actions as $act):
        $aicons=['created'=>'fa-plus','submitted'=>'fa-paper-plane','updated'=>'fa-pen','returned'=>'fa-rotate-left','rejected'=>'fa-xmark','approved'=>'fa-check'];
        $acols=['created'=>'#1565C0','submitted'=>'#7B1FA2','updated'=>'#d97706','returned'=>'#d97706','rejected'=>'#dc2626','approved'=>'#16a34a'];
        $ai=$aicons[$act['action']]??'fa-circle'; $ac2=$acols[$act['action']]??'#64748b';
      ?>
      <div class="tl-item">
        <div class="tl-dot" style="background:<?= $ac2 ?>15;color:<?= $ac2 ?>"><i class="fa-solid <?= $ai ?>"></i></div>
        <div><div style="font-weight:600;color:#0f172a"><?= e($act['full_name']??'System') ?></div>
        <div style="color:#64748b"><?= e($act['action']) ?><?= $act['notes']?' · '.e(mb_substr($act['notes'],0,60)):'' ?></div>
        <div style="font-size:11px;color:#94a3b8;font-family:'Inter'"><?= substr($act['created_at']??'',0,16) ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- main col -->

<!-- قرار المدير التنفيذي -->
<div>
  <div class="ac">
    <div class="ach"><i class="fa-solid fa-gavel"></i><?= $rtl?'قرار المدير التنفيذي':'Executive Decision' ?></div>
    <div class="acb">
      <div style="background:#f0fdf4;border-radius:10px;padding:11px;margin-bottom:14px;font-size:12.5px;color:#166534">
        <i class="fa-solid fa-info-circle"></i>
        <?= $rtl?'بعد الاعتماد، لا يمكن التراجع. ستُرسَل الإشعارات للأعضاء تلقائياً.':'After approval, no changes can be made. Notifications sent automatically.' ?>
      </div>

      <form method="POST" id="decForm">
        <?= csrf_input() ?>
        <input type="hidden" name="decision" id="decisionField">

        <!-- اعتماد -->
        <button type="button" class="dec-btn dec-approve" onclick="doDecision('approve')">
          <i class="fa-solid fa-check-circle"></i><?= $rtl?'اعتماد اللجنة ✅':'Approve Committee ✅' ?>
        </button>

        <!-- إعادة للتصحيح -->
        <button type="button" class="dec-btn dec-return" onclick="showReason('return')">
          <i class="fa-solid fa-rotate-left"></i><?= $rtl?'إعادة للتصحيح 🔄':'Return for Correction 🔄' ?>
        </button>
        <div class="reason-box" id="returnBox">
          <textarea name="reason" id="returnReason" placeholder="<?= $rtl?'اذكر ما يحتاج تصحيحه...':'Describe what needs correction...' ?>"></textarea>
          <button type="button" class="btn" style="width:100%;background:#d97706;color:#fff;justify-content:center;margin-top:6px" onclick="submitReason('return','returnReason')">
            <i class="fa-solid fa-paper-plane"></i><?= $rtl?'إرسال للتصحيح':'Send for Correction' ?>
          </button>
        </div>

        <!-- رفض -->
        <button type="button" class="dec-btn dec-reject" onclick="showReason('reject')">
          <i class="fa-solid fa-xmark-circle"></i><?= $rtl?'رفض الطلب ❌':'Reject Request ❌' ?>
        </button>
        <div class="reason-box" id="rejectBox">
          <textarea name="reason_reject" id="rejectReason" placeholder="<?= $rtl?'اذكر سبب الرفض...':'State rejection reason...' ?>"></textarea>
          <button type="button" class="btn" style="width:100%;background:#dc2626;color:#fff;justify-content:center;margin-top:6px" onclick="submitReason('reject','rejectReason')">
            <i class="fa-solid fa-xmark"></i><?= $rtl?'تأكيد الرفض':'Confirm Rejection' ?>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

</div>
</main></div>
<script>
function doDecision(d){
    if(!confirm(<?= $rtl?'"هل تريد اعتماد هذه اللجنة؟ لا يمكن التراجع."':'"Approve this committee? This cannot be undone."' ?>))return;
    document.getElementById('decisionField').value=d;
    document.getElementById('decForm').submit();
}
function showReason(type){
    document.getElementById('returnBox').style.display=type==='return'?'block':'none';
    document.getElementById('rejectBox').style.display=type==='reject'?'block':'none';
}
function submitReason(decision,inputId){
    const val=document.getElementById(inputId).value.trim();
    if(!val){alert(<?= $rtl?'"يجب ذكر السبب"':'"Reason is required"' ?>);return;}
    if(!confirm(<?= $rtl?'"تأكيد الإجراء؟"':'"Confirm action?"' ?>))return;
    document.getElementById('decisionField').value=decision;
    // نقل القيمة لـ reason الموحد
    let r=document.createElement('input');r.type='hidden';r.name='reason';r.value=val;
    document.getElementById('decForm').appendChild(r);
    document.getElementById('decForm').submit();
}
</script>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>