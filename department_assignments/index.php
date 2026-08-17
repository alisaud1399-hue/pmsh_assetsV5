<?php
/**
 * department_assignments/index.php
 * شاشة تكليفات رؤساء الأقسام — مصدر الحقيقة الوحيد لمن يرأس أي قسم الآن
 * كل تعيين هنا يُحدِّث departments.manager_id فوراً + يسجَّل تاريخياً
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('dept_assignments');

$rtl = is_rtl();
$uid = (int)current_user()['id'];
$can_edit = can('dept_assignments','edit');

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && verify_csrf() && $can_edit) {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $new_uid = (int)($_POST['user_id'] ?? 0);
        $atype   = ($_POST['assignment_type'] ?? 'permanent') === 'temporary' ? 'temporary' : 'permanent';
        $sdate   = $_POST['start_date'] ?? date('Y-m-d');
        $edate   = trim($_POST['end_date'] ?? '') ?: null;
        $dref    = trim($_POST['decision_ref'] ?? '') ?: null;

        if ($dept_id && $new_uid) {
            $cur = $pdo->prepare("SELECT manager_id FROM departments WHERE id=?");
            $cur->execute([$dept_id]);
            $prev_manager = (int)($cur->fetchColumn() ?: 0) ?: null;

            $pdo->prepare("UPDATE department_manager_assignments
                           SET status='ended', ended_at=NOW()
                           WHERE department_id=? AND status='active'")
                ->execute([$dept_id]);

            $pdo->prepare("INSERT INTO department_manager_assignments
                           (department_id,user_id,assignment_type,start_date,end_date,decision_ref,previous_manager_id,status,created_by)
                           VALUES (?,?,?,?,?,?,?,'active',?)")
                ->execute([$dept_id,$new_uid,$atype,$sdate,$edate,$dref,$prev_manager,$uid]);

            $pdo->prepare("UPDATE departments SET manager_id=? WHERE id=?")->execute([$new_uid,$dept_id]);

            flash('success', $rtl?'✅ تم تثبيت التكليف بنجاح':'✅ Assignment saved');
        }
    } elseif ($action === 'end_assignment') {
        $aid = (int)($_POST['assignment_id'] ?? 0);
        $row = $pdo->prepare("SELECT * FROM department_manager_assignments WHERE id=? AND status='active'");
        $row->execute([$aid]); $row = $row->fetch();
        if ($row) {
            $pdo->prepare("UPDATE department_manager_assignments SET status='ended', ended_at=NOW() WHERE id=?")->execute([$aid]);
            $pdo->prepare("UPDATE departments SET manager_id=? WHERE id=?")
                ->execute([$row['previous_manager_id'] ?: null, $row['department_id']]);
            flash('success', $rtl?'✅ تم إنهاء التكليف':'✅ Assignment ended');
        }
    }
    header('Location:'.$_SERVER['REQUEST_URI']); exit;
}

// ── بيانات الفلتر العلوي (الإدارات الرئيسية فقط) ───────────────
$main_depts = $pdo->query("SELECT id,name FROM departments WHERE level=1 AND is_active=1 ORDER BY name")->fetchAll();
$sel_main = (int)($_GET['main_dept'] ?? 0);

// ── الأقسام التابعة (الجد + أبناؤه + أحفاده — 3 مستويات كحد أقصى فعلياً) ───
$rows = [];
if ($sel_main) {
    $rows = $pdo->prepare("
        SELECT d.id, d.name, d.parent_id, d.level, d.manager_id,
               u.full_name AS manager_name, u.job_title AS manager_job,
               (d.level - (SELECT MIN(level) FROM departments WHERE id=?)) AS depth
        FROM departments d
        LEFT JOIN users u ON u.id = d.manager_id
        WHERE d.id = ?
           OR d.parent_id = ?
           OR d.parent_id IN (SELECT id FROM departments WHERE parent_id = ?)
        ORDER BY d.level, d.sort_order, d.name
    ");
    $rows->execute([$sel_main, $sel_main, $sel_main, $sel_main]);
    $rows = $rows->fetchAll();
}

$active_assignments = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $aq = $pdo->prepare("SELECT * FROM department_manager_assignments WHERE department_id IN ($in) AND status='active'");
    $aq->execute($ids);
    foreach ($aq->fetchAll() as $a) { $active_assignments[(int)$a['department_id']] = $a; }
}

$all_users = $pdo->query("SELECT id,full_name,job_title FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

$page_title = $rtl ? 'تكليفات رؤساء الأقسام' : 'Department Assignments';
$page_icon  = 'fa-user-tie';
$active_nav = 'dept_assignments';
$flash_msgs = get_flash();
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
.da-wrap{max-width:1100px;margin:0 auto}
.da-filter{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:16px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.da-filter select{flex:1;max-width:380px;padding:9px 12px;border:1.5px solid #cbd5e1;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:13.5px}
.da-table{width:100%;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #e2e8f0;border-collapse:collapse;box-shadow:0 1px 4px rgba(0,0,0,.05)}
.da-table th{background:#f8fafc;padding:10px 14px;font-size:11.5px;font-weight:700;color:#64748b;text-align:start;border-bottom:1px solid #e2e8f0}
.da-table td{padding:10px 14px;font-size:13px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.da-table tr:last-child td{border-bottom:none}
.da-table tr:hover{background:#f8fafc}
.depth-0{font-weight:700;color:#0f172a}
.depth-1{padding-inline-start:26px!important;color:#334155}
.depth-2{padding-inline-start:46px!important;color:#64748b;font-size:12.5px}
.da-badge{font-size:10px;padding:2px 8px;border-radius:50px;font-weight:600}
.badge-perm{background:#dcfce7;color:#16a34a}
.badge-temp{background:#fef9c3;color:#92400e}
.badge-none{background:#f1f5f9;color:#94a3b8}
.btn-mini{font-size:11.5px;padding:5px 11px;border-radius:7px;border:1px solid #cbd5e1;background:#fff;cursor:pointer;font-family:'Tajawal';white-space:nowrap}
.btn-mini.primary{background:#0070C0;color:#fff;border-color:#0070C0}
.btn-mini.danger{background:#fef2f2;color:#dc2626;border-color:#fecaca}
.da-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;z-index:1000}
.da-modal-overlay.open{display:flex}
.da-modal{background:#fff;border-radius:14px;width:440px;max-width:92vw;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.da-modal h3{font-size:15px;margin:0 0 14px;color:#0f172a}
.da-modal label{font-size:12px;font-weight:600;color:#64748b;display:block;margin-bottom:5px;margin-top:10px}
.da-modal select,.da-modal input{width:100%;padding:8px 10px;border:1.5px solid #cbd5e1;border-radius:8px;font-family:'Tajawal';font-size:13px}
.da-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}
.empty-state{text-align:center;padding:50px 20px;color:#94a3b8;background:#fff;border-radius:14px;border:1px solid #e2e8f0}
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

<div class="da-wrap">

  <div class="da-filter">
    <i class="fa-solid fa-filter" style="color:#94a3b8"></i>
    <select onchange="location.href='?main_dept='+this.value">
      <option value=""><?= $rtl?'— اختر الإدارة الرئيسية —':'— Select Main Department —' ?></option>
      <?php foreach($main_depts as $d): ?>
      <option value="<?= $d['id'] ?>" <?= $sel_main==$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <span style="font-size:11.5px;color:#94a3b8;margin-inline-start:auto"><i class="fa-solid fa-circle-info"></i> <?= $rtl?'المصدر الوحيد لتحديد رئيس كل قسم في النظام':'Single source of truth for department heads' ?></span>
  </div>

  <?php if(!$sel_main): ?>
  <div class="empty-state">
    <i class="fa-solid fa-sitemap" style="font-size:40px;opacity:.25;display:block;margin-bottom:10px"></i>
    <?= $rtl?'اختر إدارة رئيسية من الأعلى لعرض أقسامها':'Select a main department above' ?>
  </div>
  <?php else: ?>

  <table class="da-table">
    <thead><tr>
      <th><?= $rtl?'القسم':'Department' ?></th>
      <th><?= $rtl?'الرئيس الحالي':'Current Head' ?></th>
      <th><?= $rtl?'نوع التكليف':'Type' ?></th>
      <th><?= $rtl?'منذ':'Since' ?></th>
      <?php if($can_edit): ?><th></th><?php endif; ?>
    </tr></thead>
    <tbody>
      <?php foreach($rows as $r):
        $asn = $active_assignments[(int)$r['id']] ?? null;
      ?>
      <tr>
        <td class="depth-<?= $r['depth'] ?>"><?= e($r['name']) ?></td>
        <td>
          <?php if($r['manager_id']): ?>
            <i class="fa-solid fa-user-circle" style="color:#94a3b8;font-size:12px"></i>
            <?= e($r['manager_name']) ?>
            <?php if($r['manager_job']): ?><span style="color:#94a3b8;font-size:11.5px"> — <?= e($r['manager_job']) ?></span><?php endif; ?>
          <?php else: ?>
            <span style="color:#cbd5e1"><?= $rtl?'غير معيّن':'Unassigned' ?></span>
          <?php endif; ?>
        </td>
        <td>
          <?php if($asn): ?>
            <span class="da-badge <?= $asn['assignment_type']==='temporary'?'badge-temp':'badge-perm' ?>">
              <?= $asn['assignment_type']==='temporary' ? ($rtl?'مؤقت':'Temporary') : ($rtl?'دائم':'Permanent') ?>
            </span>
          <?php else: ?>
            <span class="da-badge badge-none">—</span>
          <?php endif; ?>
        </td>
        <td style="font-family:Inter;font-size:11.5px;color:#64748b"><?= $asn ? e($asn['start_date']) : '—' ?></td>
        <?php if($can_edit): ?>
        <td style="text-align:end;white-space:nowrap">
          <button class="btn-mini primary" onclick='openAssignModal(<?= $r["id"] ?>,<?= json_encode($r["name"],JSON_UNESCAPED_UNICODE) ?>,<?= $r["manager_id"]?:"null" ?>)'>
            <i class="fa-solid fa-user-pen"></i> <?= $r['manager_id'] ? ($rtl?'تغيير':'Change') : ($rtl?'تعيين':'Assign') ?>
          </button>
          <?php if($asn && $asn['assignment_type']==='temporary'): ?>
          <form method="POST" style="display:inline-block;margin-inline-start:5px" onsubmit="return confirm('<?= $rtl?'إنهاء التكليف وإرجاع الرئيس الأصلي؟':'End assignment?' ?>')">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="end_assignment">
            <input type="hidden" name="assignment_id" value="<?= $asn['id'] ?>">
            <button type="submit" class="btn-mini danger"><i class="fa-solid fa-rotate-left"></i> <?= $rtl?'إنهاء':'End' ?></button>
          </form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<!-- Modal: تعيين/تغيير رئيس -->
<div class="da-modal-overlay" id="daModal">
  <div class="da-modal">
    <h3 id="daModalTitle"><?= $rtl?'تعيين رئيس قسم':'Assign Department Head' ?></h3>
    <form method="POST">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="assign">
      <input type="hidden" name="department_id" id="daDeptId">

      <label><?= $rtl?'الموظف':'Employee' ?></label>
      <select name="user_id" id="daUserId" required>
        <option value=""><?= $rtl?'— اختر —':'— Select —' ?></option>
        <?php foreach($all_users as $u): ?>
        <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?><?= $u['job_title']?' — '.e($u['job_title']):'' ?></option>
        <?php endforeach; ?>
      </select>

      <label><?= $rtl?'نوع التكليف':'Assignment Type' ?></label>
      <select name="assignment_type" id="daType" onchange="document.getElementById('daEndDateRow').style.display=this.value==='temporary'?'':'none'">
        <option value="permanent"><?= $rtl?'دائم':'Permanent' ?></option>
        <option value="temporary"><?= $rtl?'مؤقت (إجازة / تكليف مرحلي)':'Temporary' ?></option>
      </select>

      <label><?= $rtl?'تاريخ البداية':'Start Date' ?></label>
      <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" required>

      <div id="daEndDateRow" style="display:none">
        <label><?= $rtl?'تاريخ الانتهاء المتوقع (اختياري)':'Expected End (optional)' ?></label>
        <input type="date" name="end_date">
      </div>

      <label><?= $rtl?'مرجع القرار الإداري (اختياري)':'Decision Reference (optional)' ?></label>
      <input type="text" name="decision_ref" placeholder="<?= $rtl?'رقم القرار أو ملاحظة':'Decision # or note' ?>">

      <div class="da-modal-actions">
        <button type="button" class="btn-mini" onclick="closeAssignModal()"><?= $rtl?'إلغاء':'Cancel' ?></button>
        <button type="submit" class="btn-mini primary"><i class="fa-solid fa-check"></i> <?= $rtl?'تثبيت':'Save' ?></button>
      </div>
    </form>
  </div>
</div>

<script>
function openAssignModal(deptId, deptName, currentManagerId){
    document.getElementById('daModalTitle').textContent = '<?= $rtl?"تعيين رئيس — ":"Assign — " ?>' + deptName;
    document.getElementById('daDeptId').value = deptId;
    document.getElementById('daUserId').value = currentManagerId || '';
    document.getElementById('daModal').classList.add('open');
}
function closeAssignModal(){ document.getElementById('daModal').classList.remove('open'); }
document.addEventListener('click', e => { if(e.target.id==='daModal') closeAssignModal(); });
document.addEventListener('keydown', e => { if(e.key==='Escape') closeAssignModal(); });
</script>

</main>
</div>
</body>
</html>