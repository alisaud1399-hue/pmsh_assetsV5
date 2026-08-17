<?php
/**
 * committees/sign.php — توقيع العضو على اللجنة
 */
require_once dirname(__DIR__) . '/config.php';
require_login();

$rtl = is_rtl();
$uid = (int)current_user()['id'];
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location:'.BASE_URL.'/committees/index.php'); exit; }

$s=$pdo->prepare("SELECT c.*,ct.name AS type_name,u.full_name AS creator_name
    FROM committees c LEFT JOIN committee_types ct ON ct.id=c.committee_type_id
    LEFT JOIN users u ON u.id=c.created_by WHERE c.id=? LIMIT 1");
$s->execute([$id]); $committee=$s->fetch();
if (!$committee||$committee['status']!=='active') {
    flash('warning',$rtl?'هذه اللجنة ليست في مرحلة التوقيع':'Not in signing stage');
    header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
}

// جميع الأعضاء
$all_members=$pdo->prepare("SELECT cm.*,u.full_name,u.job_title FROM committee_members cm LEFT JOIN users u ON u.id=cm.user_id WHERE cm.committee_id=? ORDER BY cm.sort_order");
$all_members->execute([$id]); $all_members=$all_members->fetchAll();

$attachments=$pdo->prepare("SELECT * FROM committee_attachments WHERE committee_id=? ORDER BY id");
$attachments->execute([$id]); $attachments=$attachments->fetchAll();

// التحقق أن المستخدم عضو وأن دوره الحالي
$my_member=null; $my_seq=null;
foreach($all_members as $i=>$m) {
    if ((int)$m['user_id']===$uid) { $my_member=$m; $my_seq=$i; break; }
}
if (!$my_member) {
    flash('danger',$rtl?'لست عضواً في هذه اللجنة':'You are not a member');
    header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
}

// التحقق من الدور الحالي في committee_actions (هل وقّع سابقاً؟)
$my_action=$pdo->prepare("SELECT * FROM committee_actions WHERE committee_id=? AND user_id=? AND action IN ('member_approved','member_rejected') LIMIT 1");
$my_action->execute([$id,$uid]); $my_prev=$my_action->fetch();

if ($my_prev) {
    flash('info',$rtl?'قدّمت موافقتك مسبقاً على هذه اللجنة':'You have already responded');
    header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
}

// التحقق أن جميع من قبله في الترتيب وقّعوا
for ($i=0;$i<$my_seq;$i++) {
    $prev=$all_members[$i];
    $done=$pdo->prepare("SELECT COUNT(*) FROM committee_actions WHERE committee_id=? AND user_id=? AND action IN ('member_approved','member_rejected')");
    $done->execute([$id,(int)$prev['user_id']]);
    if ((int)$done->fetchColumn()===0) {
        flash('warning',$rtl?'لم يأتِ دورك بعد، في انتظار الأعضاء السابقين':'Not your turn yet');
        header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
    }
}

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST'&&verify_csrf()) {
    $action = $_POST['action']??'';
    $notes  = trim($_POST['notes']??'');
    if(!in_array($action,['approve','reject'])) { flash('danger','Invalid'); header('Location:'.$_SERVER['REQUEST_URI']); exit; }
    if($action==='reject'&&!$notes) { flash('danger',$rtl?'يجب ذكر سبب الرفض':'Rejection reason required'); header('Location:'.$_SERVER['REQUEST_URI']); exit; }

    $db_action = $action==='approve'?'member_approved':'member_rejected';
    $pdo->prepare("INSERT INTO committee_actions (committee_id,user_id,action,notes) VALUES (?,?,?,?)")
        ->execute([$id,$uid,$db_action,$notes?:null]);

    if ($action==='reject') {
        // رفض عضو = اللجنة معلّقة برسالة
        $pdo->prepare("UPDATE committees SET status='returned',return_reason=? WHERE id=?")->execute([$notes,$id]);
        $req_id=(int)($committee['created_by']??$committee['requested_by']??0);
        notify_committee_returned($id,$committee['name'],$req_id,$notes);
        notify_role('admin','committee_returned','رفض عضو في اللجنة',"رفض «{$my_member['full_name']}» الانضمام للجنة «{$committee['name']}»: $notes",BASE_URL.'/committees/view.php?id='.$id);
        flash('danger',$rtl?'تم تسجيل رفضك — أُعيدت اللجنة للمنشئ':'Rejection recorded, committee returned');
    } else {
        // موافقة — هل انتهى الجميع؟
        $next_idx = $my_seq + 1;
        if ($next_idx < count($all_members)) {
            // أشعر التالي
            $next=$all_members[$next_idx];
            notify_member_sign_request($id,$committee['name'],(int)$next['user_id'],$next_idx+1);
            flash('success',$rtl?'تم تسجيل موافقتك ✅ — أُرسل إشعار للعضو التالي':'Approval recorded, next member notified');
        } else {
            // اكتمل الجميع
            $pdo->prepare("UPDATE committees SET status='completed',completed_at=NOW() WHERE id=?")->execute([$id]);
            $req_id=(int)($committee['created_by']??$committee['requested_by']??0);
            notify_member_completed($id,$committee['name'],$req_id);
            notify_role('admin','committee_completed','اكتملت لجنة',"اكتملت موافقات لجنة «{$committee['name']}»",BASE_URL.'/committees/view.php?id='.$id);
            flash('success',$rtl?'اكتملت جميع الموافقات ✅ — المحضر جاهز للطباعة':'All approvals complete ✅ — Minute ready for print');
        }
    }
    header('Location:'.BASE_URL.'/committees/view.php?id='.$id); exit;
}

$role_ar=['manager'=>'رئيس اللجنة','technical'=>'عضو فني','receiver'=>'مستلم','other'=>'عضو'];
$page_title=$rtl?'موافقتك على اللجنة':'Your Committee Response';
$active_nav='committees.index';
$flash_msgs=get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<?php foreach($flash_msgs as $fm): ?><div class="alert alert-<?= $fm['type'] ?>" style="margin-bottom:12px"><?= e($fm['message']) ?></div><?php endforeach; ?>

<div style="max-width:640px;margin:0 auto">
  <!-- بطاقة اللجنة -->
  <div style="background:linear-gradient(135deg,#1e3a8a,#1565C0);color:#fff;border-radius:16px;padding:20px 24px;margin-bottom:14px">
    <div style="font-size:11px;opacity:.7;margin-bottom:4px"><?= $rtl?'مطلوب موافقتك على اللجنة رقم':'Your approval required for committee' ?></div>
    <div style="font-size:18px;font-weight:800"><?= e($committee['name']) ?></div>
    <div style="font-size:12px;opacity:.75;margin-top:4px">
      <i class="fa-solid fa-users-gear" style="font-size:10px"></i> <?= e($committee['type_name']??'—') ?> ·
      <?= $rtl?'دورك رقم':'Step' ?> <?= $my_seq+1 ?> <?= $rtl?'من':'of' ?> <?= count($all_members) ?>
    </div>
  </div>

  <!-- دورك -->
  <div style="background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:20px;margin-bottom:14px">
    <div style="font-size:13.5px;font-weight:700;color:#0f172a;margin-bottom:4px">
      <i class="fa-solid fa-user-tie" style="color:#1565C0"></i> <?= e($my_member['full_name']) ?>
    </div>
    <div style="font-size:12px;color:#64748b;margin-bottom:16px">
      <?= $role_ar[$my_member['role']]??$my_member['role'] ?> · <?= $rtl?'الخطوة':'Step' ?> <?= $my_seq+1 ?>
    </div>

    <?php if($committee['purpose']): ?>
    <div style="background:#f8fafc;border-radius:9px;padding:11px;margin-bottom:14px;font-size:13px;color:#334155">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;margin-bottom:4px"><?= $rtl?'مهام اللجنة:':'Tasks:' ?></div>
      <?= e($committee['purpose']) ?>
    </div>
    <?php endif; ?>

    <!-- المرفقات -->
    <?php if(!empty($attachments)): ?>
    <div style="background:#f8fafc;border-radius:9px;padding:11px;margin-bottom:14px">
      <div style="font-size:11px;font-weight:700;color:#94a3b8;margin-bottom:8px">
        <i class="fa-solid fa-paperclip" style="font-size:10px"></i>
        <?= $rtl?'المرفقات':'Attachments' ?> (<?= count($attachments) ?>)
      </div>
      <?php foreach($attachments as $att):
        $ext = strtolower(pathinfo($att['file_name'], PATHINFO_EXTENSION));
        $ico = in_array($ext,['pdf']) ? 'fa-file-pdf' : (in_array($ext,['doc','docx']) ? 'fa-file-word' : (in_array($ext,['xls','xlsx']) ? 'fa-file-excel' : (in_array($ext,['jpg','jpeg','png','gif']) ? 'fa-file-image' : 'fa-file')));
        $clr = $ext==='pdf'?'#dc2626':($ext==='docx'||$ext==='doc'?'#1565C0':($ext==='xlsx'||$ext==='xls'?'#16a34a':'#64748b'));
      ?>
      <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank"
         style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#334155;margin-bottom:6px;transition:.15s"
         onmouseover="this.style.borderColor='#1565C0'" onmouseout="this.style.borderColor='#e2e8f0'">
        <i class="fa-solid <?= $ico ?>" style="color:<?= $clr ?>;font-size:18px;flex-shrink:0"></i>
        <div style="flex:1;min-width:0">
          <div style="font-size:12.5px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($att['file_name']) ?></div>
          <?php if($att['file_size']): ?>
          <div style="font-size:10.5px;color:#94a3b8"><?= round($att['file_size']/1024) ?> KB</div>
          <?php endif; ?>
        </div>
        <i class="fa-solid fa-download" style="color:#1565C0;font-size:12px;flex-shrink:0"></i>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST">
      <?= csrf_input() ?>
      <textarea name="notes" id="notesField"
        style="width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px;font-family:'Tajawal';font-size:13px;resize:vertical;min-height:80px;outline:none;box-sizing:border-box;margin-bottom:12px"
        placeholder="<?= $rtl?'ملاحظات اختيارية (مطلوبة عند الرفض)...':'Notes (required if rejecting)...' ?>"></textarea>

      <div style="display:flex;gap:10px">
        <button type="submit" name="action" value="approve"
          onclick="return confirm(<?= $rtl?'"تأكيد الموافقة على اللجنة؟"':'"Confirm approval?"' ?>)"
          style="flex:1;padding:13px;background:#16a34a;color:#fff;border:none;border-radius:11px;font-family:'Tajawal';font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px">
          <i class="fa-solid fa-check-circle"></i><?= $rtl?'موافقة':'Approve' ?>
        </button>
        <button type="submit" name="action" value="reject"
          onclick="if(!document.getElementById('notesField').value.trim()){alert('<?= $rtl?'يجب ذكر سبب الرفض':'Rejection reason required' ?>');return false;}return confirm(<?= $rtl?'"تأكيد رفض الانضمام؟"':'"Confirm rejection?"' ?>)"
          style="flex:1;padding:13px;background:#dc2626;color:#fff;border:none;border-radius:11px;font-family:'Tajawal';font-size:14px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px">
          <i class="fa-solid fa-xmark-circle"></i><?= $rtl?'رفض مع السبب':'Reject with Reason' ?>
        </button>
      </div>
    </form>
  </div>

  <!-- ترتيب الأعضاء -->
  <div style="background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:16px">
    <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:10px"><i class="fa-solid fa-list-ol" style="color:#1565C0"></i> <?= $rtl?'ترتيب الأعضاء':'Members Order' ?></div>
    <?php foreach($all_members as $i=>$m):
      $done=$pdo->prepare("SELECT action FROM committee_actions WHERE committee_id=? AND user_id=? AND action IN ('member_approved','member_rejected') LIMIT 1");
      $done->execute([$id,(int)$m['user_id']]); $dv=$done->fetchColumn();
      $isCurrent=(int)$m['user_id']===$uid;
    ?>
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f8fafc">
      <div style="width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;background:<?= $dv==='member_approved'?'#f0fdf4':($dv==='member_rejected'?'#fef2f2':($isCurrent?'#fffbeb':'#f1f5f9')) ?>;color:<?= $dv==='member_approved'?'#16a34a':($dv==='member_rejected'?'#dc2626':($isCurrent?'#d97706':'#94a3b8')) ?>">
        <?php if($dv==='member_approved'): ?><i class="fa-solid fa-check"></i>
        <?php elseif($dv==='member_rejected'): ?><i class="fa-solid fa-xmark"></i>
        <?php elseif($isCurrent): ?><i class="fa-solid fa-pen"></i>
        <?php else: ?><?= $i+1 ?><?php endif; ?>
      </div>
      <div style="flex:1">
        <div style="font-size:13px;font-weight:<?= $isCurrent?'700':'500' ?>;color:<?= $isCurrent?'#0f172a':'#475569' ?>"><?= e($m['full_name']??'—') ?><?= $isCurrent?' ('.($rtl?'أنت':'You').')':'' ?></div>
        <div style="font-size:11px;color:#94a3b8"><?= $role_ar[$m['role']]??$m['role'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

</main></div>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>