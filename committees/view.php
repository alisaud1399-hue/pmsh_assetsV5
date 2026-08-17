<?php
/**
 * committees/view.php — تفاصيل اللجنة
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('committees.index');

$rtl = is_rtl();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { flash('danger',$rtl?'غير محدد':'Not specified'); header('Location:'.BASE_URL.'/committees/index.php'); exit; }

// جلب اللجنة
$s = $pdo->prepare("
    SELECT c.*, ct.name AS type_name, ct.name_en AS type_name_en,
           u.full_name AS creator_name
    FROM committees c
    LEFT JOIN committee_types ct ON ct.id=c.committee_type_id
    LEFT JOIN users u ON u.id=c.created_by
    WHERE c.id=? LIMIT 1");
$s->execute([$id]); $c = $s->fetch();
if (!$c) { flash('danger',$rtl?'غير موجود':'Not found'); header('Location:'.BASE_URL.'/committees/index.php'); exit; }

// الأعضاء
$members = $pdo->prepare("
    SELECT cm.*, u.full_name, u.username, u.email,
           d.name AS dept_name, d.name_en AS dept_name_en
    FROM committee_members cm
    LEFT JOIN users       u ON u.id=cm.user_id
    LEFT JOIN departments d ON d.id=u.department_id
    WHERE cm.committee_id=? ORDER BY cm.sort_order");
$members->execute([$id]); $members = $members->fetchAll();

$attachments = $pdo->prepare("SELECT * FROM committee_attachments WHERE committee_id=? ORDER BY id");
$attachments->execute([$id]); $attachments = $attachments->fetchAll();
$minutes_count = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM receiving_minutes WHERE committee_id=?");
    $s->execute([$id]); $minutes_count = (int)$s->fetchColumn();
} catch(Exception $e) { $minutes_count = 0; }

$status_cfg = [
    'draft'  => ['ar'=>'مسودة',  'en'=>'Draft',  'c'=>'#64748b','b'=>'#f1f5f9','i'=>'fa-pencil'],
    'active' => ['ar'=>'نشطة',   'en'=>'Active', 'c'=>'#16a34a','b'=>'#f0fdf4','i'=>'fa-circle-check'],
    'closed' => ['ar'=>'منتهية', 'en'=>'Closed', 'c'=>'#94a3b8','b'=>'#f8fafc','i'=>'fa-lock'],
];
$role_cfg = [
    'manager'   => ['ar'=>'رئيس اللجنة','en'=>'Manager',          'c'=>'#1565C0','b'=>'#E3F2FD','i'=>'fa-user-tie'],
    'technical' => ['ar'=>'عضو فني',     'en'=>'Technical Member', 'c'=>'#7B1FA2','b'=>'#F3E5F5','i'=>'fa-screwdriver-wrench'],
    'receiver'  => ['ar'=>'مستلم',       'en'=>'Receiver',         'c'=>'#16a34a','b'=>'#F0FDF4','i'=>'fa-hand-holding'],
    'other'     => ['ar'=>'عضو آخر',     'en'=>'Other Member',     'c'=>'#64748b','b'=>'#F8FAFC','i'=>'fa-user'],
];
[$stc,$stb,$sti,$star,$sten] = array_values($status_cfg[$c['status']] ?? $status_cfg['draft']);
$tname = $rtl?($c['type_name']??'—'):($c['type_name_en']?:$c['type_name']??'—');
$can_edit    = can('committees.index','edit') && $c['status'] !== 'closed';
$can_approve = (can('committees.approve','view') || is_admin())
               && $c['status'] === 'requested';
$uid_cur     = (int)current_user()['id'];

$page_title = $rtl?'تفاصيل اللجنة':'Committee Details';
$active_nav = 'committees.index';
$breadcrumb = [
    ['name'=>$rtl?'اللجان':'Committees','url'=>BASE_URL.'/committees/index.php'],
    ['name'=>e(mb_substr($c['name'],0,40))]
];
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($c['name']) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.cv-layout{display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start}
@media(max-width:1024px){.cv-layout{grid-template-columns:1fr}}
.cv-hero{background:linear-gradient(135deg,#1e3a8a,#1565C0);color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:14px}
.cv-hero-top{display:flex;align-items:flex-start;gap:14px;margin-bottom:14px}
.cv-ico{width:52px;height:52px;background:rgba(255,255,255,.15);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0}
.cv-tags{display:flex;gap:7px;flex-wrap:wrap}
.cv-tag{background:rgba(255,255,255,.15);border-radius:50px;padding:4px 12px;font-size:11.5px;font-weight:600;display:flex;align-items:center;gap:5px}
.cv-acts{display:flex;gap:7px;margin-inline-start:auto;flex-shrink:0}
.cv-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:9px;padding:7px 14px;font-size:12.5px;font-weight:600;cursor:pointer;font-family:'Tajawal',sans-serif;text-decoration:none;display:flex;align-items:center;gap:5px;transition:.15s}
.cv-btn:hover{background:rgba(255,255,255,.25)}
/* بطاقات */
.cv-card{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;margin-bottom:14px}
.cv-card:last-child{margin-bottom:0}
.cv-ch{padding:13px 18px;border-bottom:1px solid #f1f5f9;font-size:13.5px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px}
.cv-ch i{color:#1565C0;font-size:13px}
/* شجرة الأعضاء */
.mb-tree{padding:16px 18px}
.mb-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f8fafc;position:relative}
.mb-item:last-child{border-bottom:none}
.mb-connector{position:absolute;inset-inline-start:18px;top:100%;width:2px;height:100%;background:#f1f5f9}
.mb-item:last-child .mb-connector{display:none}
.mb-badge{font-size:11px;font-weight:800;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#fff}
.mb-av{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;flex-shrink:0}
.mb-det{flex:1;min-width:0}
.mb-nm{font-size:13.5px;font-weight:700;color:#0f172a}
.mb-dept{font-size:11.5px;color:#64748b;margin-top:1px}
.mb-role-tag{display:inline-flex;align-items:center;gap:3px;font-size:10.5px;font-weight:700;border-radius:5px;padding:2px 8px;margin-top:3px}
/* جانبي */
.sv-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f8fafc;font-size:13px}
.sv-row:last-child{border-bottom:none}
.sv-key{color:#64748b;font-size:12px}
.sv-val{font-weight:700;color:#0f172a;text-align:end}
.stat-big{text-align:center;padding:16px;background:linear-gradient(135deg,#eff6ff,#f5f3ff);border-radius:12px;margin:12px 14px}
@keyframes pulse-green{0%,100%{box-shadow:0 0 0 0 rgba(22,163,74,.4)}50%{box-shadow:0 0 0 8px rgba(22,163,74,0)}}
.stat-big .l{font-size:12px;color:#64748b;margin-top:3px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php foreach($flash_msgs as $fm): ?>
<div class="alert alert-<?= e($fm['type']) ?>" style="margin-bottom:12px">
  <i class="fa-solid fa-circle-<?= $fm['type']==='success'?'check':'exclamation' ?>"></i>
  <span><?= e($fm['message']) ?></span>
</div>
<?php endforeach; ?>

<!-- ══ Hero ══ -->
<div class="cv-hero">
  <div class="cv-hero-top">
    <div class="cv-ico"><i class="fa-solid fa-users-gear"></i></div>
    <div style="flex:1;min-width:0">
      <div style="font-size:18px;font-weight:800;line-height:1.4"><?= e($c['name']) ?></div>
      <?php if($c['purpose']): ?>
      <div style="font-size:12.5px;opacity:.8;margin-top:4px"><?= e($c['purpose']) ?></div>
      <?php endif; ?>
    </div>
    <div class="cv-acts">
      <?php if($can_approve): ?>
      <a href="<?= BASE_URL ?>/committees/approve.php?id=<?= $id ?>"
         style="background:#16a34a;border:1px solid #16a34a;color:#fff;border-radius:9px;padding:8px 16px;font-size:13px;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:6px;animation:pulse-green 2s infinite">
        <i class="fa-solid fa-gavel"></i><?= $rtl?'اعتماد / رفض الطلب':'Approve / Reject' ?>
      </a>
      <?php endif; ?>
      <?php if($can_edit): ?>
      <a href="<?= BASE_URL ?>/committees/form.php?id=<?= $id ?>" class="cv-btn">
        <i class="fa-solid fa-pen"></i><?= $rtl?'تعديل':'Edit' ?>
      </a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/committees/index.php" class="cv-btn">
        <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i><?= $rtl?'رجوع':'Back' ?>
      </a>
    </div>
  </div>
  <div class="cv-tags">
    <span class="cv-tag" style="background:<?= $stb ?>;color:<?= $stc ?>">
      <i class="fa-solid <?= $sti ?>" style="font-size:9px"></i><?= $rtl?$star:$sten ?>
    </span>
    <?php if($tname&&$tname!=='—'): ?>
    <span class="cv-tag"><i class="fa-solid fa-tag" style="font-size:9px"></i><?= e($tname) ?></span>
    <?php endif; ?>
    <span class="cv-tag"><i class="fa-solid fa-users" style="font-size:9px"></i><?= count($members) ?> <?= $rtl?'عضو':'members' ?></span>
    <span class="cv-tag"><i class="fa-solid fa-calendar" style="font-size:9px"></i><?= substr($c['created_at']??'',0,10) ?></span>
  </div>
</div>

<div class="cv-layout">

  <!-- ── العمود الرئيسي: الأعضاء ── -->
  <div>
    <div class="cv-card">
      <div class="cv-ch"><i class="fa-solid fa-users"></i>
        <?= $rtl?'أعضاء اللجنة بالترتيب التسلسلي':'Committee Members (in signature order)' ?>
      </div>
      <div class="mb-tree">
        <?php if(empty($members)): ?>
        <div style="text-align:center;padding:32px;color:#94a3b8">
          <i class="fa-solid fa-users" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>
          <?= $rtl?'لا يوجد أعضاء':'No members' ?>
        </div>
        <?php else: foreach($members as $i=>$m):
          $rc=$role_cfg[$m['role']]??$role_cfg['other'];
          $initials=mb_substr($m['full_name']??'?',0,1,'UTF-8');
          $dname=$rtl?($m['dept_name']??''):($m['dept_name_en']?:$m['dept_name']??'');
        ?>
        <div class="mb-item">
          <div class="mb-badge" style="background:<?= $rc['c'] ?>"><?= $m['sort_order']??($i+1) ?></div>
          <div class="mb-av"><?= $initials ?></div>
          <div class="mb-det">
            <div class="mb-nm"><?= e($m['full_name']??'—') ?></div>
            <?php if($dname): ?><div class="mb-dept"><i class="fa-solid fa-building" style="font-size:9px"></i> <?= e($dname) ?></div><?php endif; ?>
            <span class="mb-role-tag" style="background:<?= $rc['b'] ?>;color:<?= $rc['c'] ?>">
              <i class="fa-solid <?= $rc['i'] ?>" style="font-size:9px"></i>
              <?= $rtl?e($rc['ar']):e($rc['en']) ?>
            </span>
          </div>
          <?php if(!empty($m['email'])): ?>
          <a href="mailto:<?= e($m['email']) ?>" style="color:#94a3b8;font-size:13px" title="<?= e($m['email']) ?>">
            <i class="fa-solid fa-envelope"></i>
          </a>
          <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <?php if($minutes_count > 0): ?>
    <div class="cv-card">
      <div class="cv-ch"><i class="fa-solid fa-file-lines"></i><?= $rtl?'محاضر الاستلام المرتبطة':'Related Receiving Minutes' ?></div>
      <div style="padding:16px 18px">
        <a href="<?= BASE_URL ?>/receiving/index.php?committee_id=<?= $id ?>" style="font-size:13.5px;color:#1565C0;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:7px">
          <i class="fa-solid fa-arrow-up-right-from-square"></i>
          <?= $rtl?"عرض $minutes_count محضر استلام":"View $minutes_count receiving minutes" ?>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- المرفقات -->
    <?php if(!empty($attachments)): ?>
    <div class="cv-card">
      <div class="cv-ch">
        <i class="fa-solid fa-paperclip"></i>
        <?= $rtl?'المرفقات':'Attachments' ?>
        <span style="font-size:11.5px;color:#94a3b8;font-weight:400">(<?= count($attachments) ?>)</span>
      </div>
      <div style="padding:12px 16px;display:flex;flex-direction:column;gap:7px">
        <?php foreach($attachments as $att):
          $ext=strtolower(pathinfo($att['file_name'],PATHINFO_EXTENSION));
          $ico_map=['pdf'=>'fa-file-pdf','doc'=>'fa-file-word','docx'=>'fa-file-word','xls'=>'fa-file-excel','xlsx'=>'fa-file-excel','jpg'=>'fa-file-image','jpeg'=>'fa-file-image','png'=>'fa-file-image'];
          $clr_map=['pdf'=>'#dc2626','doc'=>'#1565C0','docx'=>'#1565C0','xls'=>'#16a34a','xlsx'=>'#16a34a'];
          $ico=$ico_map[$ext]??'fa-file'; $clr=$clr_map[$ext]??'#64748b';
        ?>
        <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank"
           style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;text-decoration:none;color:#334155;transition:.15s"
           onmouseover="this.style.borderColor='#1565C0';this.style.background='#eff6ff'"
           onmouseout="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
          <i class="fa-solid <?= $ico ?>" style="color:<?= $clr ?>;font-size:22px;flex-shrink:0"></i>
          <div style="flex:1;min-width:0">
            <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($att['file_name']) ?></div>
            <?php if($att['file_size']): ?><div style="font-size:11px;color:#94a3b8"><?= round($att['file_size']/1024) ?> KB</div><?php endif; ?>
          </div>
          <i class="fa-solid fa-download" style="color:#1565C0;font-size:13px;flex-shrink:0"></i>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── العمود الجانبي ── -->
  <div>
    <!-- إحصاء الأعضاء -->
    <div class="cv-card">
      <div class="stat-big">
        <div class="n"><?= count($members) ?></div>
        <div class="l"><?= $rtl?'عضو في اللجنة':'Committee Members' ?></div>
      </div>
      <div style="padding:8px 14px 14px">
        <?php
        $roles_count = array_count_values(array_column($members,'role'));
        foreach($role_cfg as $rv=>$rl):
          if(!isset($roles_count[$rv])) continue;
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid #f8fafc;font-size:12.5px">
          <span style="display:flex;align-items:center;gap:6px;color:<?= $rl['c'] ?>">
            <i class="fa-solid <?= $rl['i'] ?>" style="font-size:10px"></i>
            <?= $rtl?e($rl['ar']):e($rl['en']) ?>
          </span>
          <strong style="color:<?= $rl['c'] ?>"><?= $roles_count[$rv] ?></strong>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- بيانات اللجنة -->
    <div class="cv-card">
      <div class="cv-ch"><i class="fa-solid fa-circle-info"></i><?= $rtl?'معلومات اللجنة':'Committee Info' ?></div>
      <div style="padding:8px 14px 14px">
        <div class="sv-row">
          <span class="sv-key"><?= $rtl?'الرقم':'ID' ?></span>
          <span class="sv-val" style="font-family:'Inter';color:#94a3b8">#<?= $c['id'] ?></span>
        </div>
        <div class="sv-row">
          <span class="sv-key"><?= $rtl?'النوع':'Type' ?></span>
          <span class="sv-val"><?= e($tname) ?></span>
        </div>
        <div class="sv-row">
          <span class="sv-key"><?= $rtl?'الحالة':'Status' ?></span>
          <span class="sv-val" style="color:<?= $stc ?>"><?= $rtl?$star:$sten ?></span>
        </div>
        <div class="sv-row">
          <span class="sv-key"><?= $rtl?'المنشئ':'Created By' ?></span>
          <span class="sv-val"><?= e($c['creator_name']??'—') ?></span>
        </div>
        <div class="sv-row">
          <span class="sv-key"><?= $rtl?'تاريخ الإنشاء':'Created At' ?></span>
          <span class="sv-val" style="font-family:'Inter';font-size:12px"><?= substr($c['created_at']??'',0,16) ?></span>
        </div>
        <?php if($minutes_count): ?>
        <div class="sv-row">
          <span class="sv-key"><?= $rtl?'المحاضر':'Minutes' ?></span>
          <span class="sv-val" style="color:#1565C0"><?= $minutes_count ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- إجراءات -->
    <?php if($can_approve||$can_edit||$c['status']==='active'): ?>
    <div class="cv-card">
      <div class="cv-ch"><i class="fa-solid fa-bolt"></i><?= $rtl?'إجراءات':'Actions' ?></div>
      <div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px">
        <?php if($can_approve): ?>
        <a href="<?= BASE_URL ?>/committees/approve.php?id=<?= $id ?>"
           class="btn btn-primary" style="justify-content:center;background:#16a34a;border-color:#16a34a">
          <i class="fa-solid fa-gavel"></i><?= $rtl?'اعتماد / رفض الطلب':'Approve / Reject' ?>
        </a>
        <?php endif; ?>
        <?php if($c['status']==='active'&&(int)($c['created_by']??$c['requested_by']??0)===$uid_cur): ?>
        <a href="<?= BASE_URL ?>/receiving/form.php?committee_id=<?= $id ?>"
           class="btn btn-primary" style="justify-content:center">
          <i class="fa-solid fa-plus"></i><?= $rtl?'إنشاء محضر استلام':'Create Receiving Minute' ?>
        </a>
        <?php endif; ?>
        <?php if($can_edit): ?>
        <a href="<?= BASE_URL ?>/committees/form.php?id=<?= $id ?>" class="btn btn-outline" style="justify-content:center">
          <i class="fa-solid fa-pen"></i><?= $rtl?'تعديل اللجنة':'Edit Committee' ?>
        </a>
        <?php endif; ?>
        <?php if($c['status']!=='closed'&&$c['status']!=='rejected'&&is_admin()): ?>
        <form method="POST" action="<?= BASE_URL ?>/committees/index.php"
              onsubmit="return confirm('<?= $rtl?'إغلاق اللجنة؟':'Close?' ?>')">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="close">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="btn" style="width:100%;background:#f1f5f9;color:#64748b;justify-content:center">
            <i class="fa-solid fa-lock"></i><?= $rtl?'إغلاق اللجنة':'Close Committee' ?>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

</main></div>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>