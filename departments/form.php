<?php
/**
 * departments/form.php — إضافة / تعديل إدارة
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('departments.index');

$rtl  = is_rtl();
$id   = (int)($_GET['id'] ?? 0);
$edit = $id > 0;

// 🌟 الإصلاح هنا: تعريف متغير الصلاحية الذي تعتمد عليه الحقول
$can_edit = $edit ? can('departments.index','edit') : can('departments.index','create');

if ($edit  && !$can_edit) { flash('danger',$rtl?'غير مصرح':'Unauthorized'); header('Location:'.BASE_URL.'/departments/index.php'); exit; }
if (!$edit && !$can_edit) { flash('danger',$rtl?'غير مصرح':'Unauthorized'); header('Location:'.BASE_URL.'/departments/index.php'); exit; }

$dept = [];
if ($edit) {
    $s = $pdo->prepare("SELECT * FROM departments WHERE id=? LIMIT 1");
    $s->execute([$id]); $dept = $s->fetch();
    if (!$dept) { flash('danger',$rtl?'غير موجود':'Not found'); header('Location:'.BASE_URL.'/departments/index.php'); exit; }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = $rtl?'طلب غير صالح':'Invalid request'; }
    else {
        $code      = strtoupper(trim($_POST['code']       ?? ''));
        $name      = trim($_POST['name']                  ?? '');
        $name_en   = trim($_POST['name_en']               ?? '');
        $type      = in_array($_POST['type']??'',['department','section']) ? $_POST['type'] : 'department';
        $parent_id = (int)($_POST['parent_id']            ?? 0) ?: null;
        $mgr_id    = (int)($_POST['manager_id']           ?? 0) ?: null;
        $location  = trim($_POST['location']              ?? '');
        $sort      = (int)($_POST['sort_order']           ?? 0);
        $active    = isset($_POST['is_active']) ? 1 : 0;

        if (!$code) $errors[] = $rtl?'الرمز مطلوب':'Code required';
        if (!$name) $errors[] = $rtl?'الاسم مطلوب':'Name required';
        if ($parent_id && $parent_id === $id) $errors[] = $rtl?'لا يمكن أن تتبع الإدارة لنفسها':'Cannot be own parent';

        if (empty($errors)) {
            if ($edit) {
                $pdo->prepare("UPDATE departments SET code=?,name=?,name_en=?,type=?,parent_id=?,manager_id=?,location=?,sort_order=?,is_active=? WHERE id=?")
                    ->execute([$code,$name,$name_en?:null,$type,$parent_id,$mgr_id,$location?:null,$sort,$active,$id]);
                log_activity('edit','departments.index',"ID:$id");
                flash('success',$rtl?'تم التحديث':'Updated');
            } else {
                $pdo->prepare("INSERT INTO departments (code,name,name_en,type,parent_id,manager_id,location,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$code,$name,$name_en?:null,$type,$parent_id,$mgr_id,$location?:null,$sort,$active]);
                log_activity('create','departments.index',"Dept:$name");
                flash('success',$rtl?'تمت الإضافة':'Added');
            }
            header('Location:'.BASE_URL.'/departments/index.php'); exit;
        }
    }
}

$all_depts = $pdo->query("SELECT id,name,name_en FROM departments".($edit?" WHERE id!=$id":'')." ORDER BY sort_order,name")->fetchAll();
$all_users = $pdo->query("SELECT id,full_name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

$page_title = $edit?($rtl?'تعديل إدارة':'Edit Department'):($rtl?'إضافة إدارة':'Add Department');
$active_nav = 'departments.index';
$breadcrumb = [['name'=>$rtl?'الإدارات':'Departments','url'=>BASE_URL.'/departments/index.php'],['name'=>$page_title]];
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.form-card{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);padding:28px;max-width:680px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:600px){.form-grid{grid-template-columns:1fr}}
.form-grid .full{grid-column:1/-1}
.fg{display:flex;flex-direction:column;gap:5px}
.fg label{font-size:12.5px;font-weight:700;color:#334155}
.fg input,.fg select{height:40px;padding-inline:12px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Tajawal',sans-serif;font-size:13.5px;outline:none;transition:border-color .2s;color:#0f172a;background:#fff;width:100%;box-sizing:border-box}
.fg input:focus,.fg select:focus{border-color:#1565C0}
.fg .hint{font-size:11px;color:#94a3b8}
input[type=checkbox]{width:18px;height:18px;cursor:pointer;accent-color:#1565C0}
.toggle-row{display:flex;align-items:center;gap:10px}
.toggle-row label{font-size:13px;font-weight:600;color:#334155;cursor:pointer}
.form-actions{display:flex;gap:10px;margin-top:20px;padding-top:20px;border-top:1px solid #f1f5f9}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php if($errors): ?>
<div class="alert alert-danger" style="margin-bottom:16px">
  <i class="fa-solid fa-circle-exclamation"></i>
  <ul style="margin:0;padding-inline-start:16px">
    <?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<div class="form-card">
  <h2 style="font-size:16px;font-weight:700;margin:0 0 20px;color:#0f172a;display:flex;align-items:center;gap:8px">
    <i class="fa-solid fa-sitemap" style="color:#1565C0"></i>
    <?= e($page_title) ?>
  </h2>

  <form method="POST" action="">
    <?= csrf_input() ?>
    <div class="form-grid">

      <div class="fg">
        <label><?= $rtl?'رمز الإدارة *':'Code *' ?></label>
        <input type="text" name="code" value="<?= e($_POST['code']??$dept['code']??'') ?>"
          placeholder="ASSETS, MED_MAINT..."
          style="text-transform:uppercase;font-family:'Inter',monospace;letter-spacing:.05em"
          <?= ro_attr() ?> required maxlength="20">
        <span class="hint"><?= $rtl?'حروف وأرقام وشرطة سفلية':'Letters, numbers, underscore' ?></span>
      </div>

      <div class="fg">
        <label><?= $rtl?'النوع':'Type' ?></label>
        <select name="type" <?= dis_attr() ?>>
          <option value="department" <?= (($_POST['type']??$dept['type']??'department')==='department')?'selected':'' ?>>
            <?= $rtl?'إدارة':'Department' ?>
          </option>
          <option value="section" <?= (($_POST['type']??$dept['type']??'')==='section')?'selected':'' ?>>
            <?= $rtl?'قسم':'Section' ?>
          </option>
        </select>
      </div>

      <div class="fg full">
        <label><?= $rtl?'الاسم بالعربية *':'Arabic Name *' ?></label>
        <input type="text" name="name" value="<?= e($_POST['name']??$dept['name']??'') ?>"
          <?= ro_attr() ?> required>
      </div>

      <div class="fg full">
        <label><?= $rtl?'الاسم بالإنجليزية':'English Name' ?></label>
        <input type="text" name="name_en" value="<?= e($_POST['name_en']??$dept['name_en']??'') ?>"
          <?= ro_attr() ?>>
      </div>

      <div class="fg">
        <label><?= $rtl?'تابع لـ (اختياري)':'Parent Dept (optional)' ?></label>
        <select name="parent_id" <?= dis_attr() ?>>
          <option value=""><?= $rtl?'— إدارة رئيسية —':'— Top Level —' ?></option>
          <?php foreach($all_depts as $pd): ?>
          <option value="<?= $pd['id'] ?>" <?= (($_POST['parent_id']??$dept['parent_id']??'')==$pd['id'])?'selected':'' ?>>
            <?= e($rtl?$pd['name']:($pd['name_en']?:$pd['name'])) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label><?= $rtl?'المدير (اختياري)':'Manager (optional)' ?></label>
        <select name="manager_id" <?= dis_attr() ?>>
          <option value=""><?= $rtl?'— بدون مدير —':'— None —' ?></option>
          <?php foreach($all_users as $u): ?>
          <option value="<?= $u['id'] ?>" <?= (($_POST['manager_id']??$dept['manager_id']??'')==$u['id'])?'selected':'' ?>>
            <?= e($u['full_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="fg">
        <label><?= $rtl?'الموقع / البناية':'Location / Building' ?></label>
        <input type="text" name="location" value="<?= e($_POST['location']??$dept['location']??'') ?>"
          placeholder="<?= $rtl?'المبنى الرئيسي، الدور الثاني...':'Main Building, 2nd Floor...' ?>"
          <?= ro_attr() ?>>
      </div>

      <div class="fg">
        <label><?= $rtl?'الترتيب':'Sort Order' ?></label>
        <input type="number" name="sort_order" min="0" max="999"
          value="<?= e($_POST['sort_order']??$dept['sort_order']??0) ?>"
          <?= ro_attr() ?>>
      </div>

      <div class="fg full" style="justify-content:flex-start;flex-direction:row;align-items:center;padding-top:4px">
        <div class="toggle-row">
          <input type="checkbox" name="is_active" id="is_active" value="1"
            <?= (($_POST['is_active']??$dept['is_active']??1)?'checked':'') ?> <?= dis_attr() ?>>
          <label for="is_active"><?= $rtl?'الإدارة نشطة':'Active' ?></label>
        </div>
      </div>

    </div>

    <div class="form-actions">
      <?php if($can_edit): ?>
      <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-<?= $edit?'floppy-disk':'plus' ?>"></i>
        <?= $edit?($rtl?'حفظ التعديلات':'Save Changes'):($rtl?'إضافة الإدارة':'Add Department') ?>
      </button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/departments/index.php" class="btn btn-outline">
        <?= $rtl?($can_edit?'إلغاء':'رجوع'):($can_edit?'Cancel':'Back') ?>
      </a>
    </div>
  </form>
</div>

</main>
</div>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body>
</html>