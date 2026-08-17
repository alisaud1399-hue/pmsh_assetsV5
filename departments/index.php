<?php
/**
 * departments/index.php — الهيكل التنظيمي
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('departments.index');

$rtl  = is_rtl();
$lang = current_lang();

$can_create = can('departments.index','create');
$can_edit   = can('departments.index','edit');
$can_delete = can('departments.index','delete');

// ── POST: حذف ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_id'])) {
    if (!verify_csrf() || !$can_delete) {
        flash('danger', $rtl?'غير مصرّح':'Unauthorized');
    } else {
        $did = (int)$_POST['delete_id'];
        // تحقق من عدم وجود أصول أو مستخدمين
        $has_assets = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE department_id=?")->execute([$did]) ?
            $pdo->prepare("SELECT COUNT(*) FROM assets WHERE department_id=?") : null;
        $s = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE department_id=?");
        $s->execute([$did]); $ac = (int)$s->fetchColumn();
        $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id=?");
        $s->execute([$did]); $uc = (int)$s->fetchColumn();
        if ($ac > 0 || $uc > 0) {
            flash('danger', $rtl
                ? "لا يمكن الحذف — يحتوي على $ac أصل و $uc مستخدم"
                : "Cannot delete — has $ac assets and $uc users");
        } else {
            $pdo->prepare("DELETE FROM departments WHERE id=?")->execute([$did]);
            log_activity('delete','departments.index',"Dept ID: $did");
            flash('success', $rtl?'تم حذف الإدارة':'Department deleted');
        }
    }
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

// ── بيانات ───────────────────────────────────────────────────
$depts = $pdo->query("
    SELECT d.*,
           p.name AS parent_name,
           u.full_name AS manager_name,
           (SELECT COUNT(*) FROM assets a WHERE a.department_id=d.id)   AS asset_cnt,
           (SELECT COUNT(*) FROM users  us WHERE us.department_id=d.id) AS user_cnt
    FROM departments d
    LEFT JOIN departments p ON p.id = d.parent_id
    LEFT JOIN users       u ON u.id = d.manager_id
    ORDER BY d.sort_order, d.id
")->fetchAll();

// إحصاءات
$total_depts  = count($depts);
$total_assets = array_sum(array_column($depts,'asset_cnt'));
$total_users  = array_sum(array_column($depts,'user_cnt'));
$active_cnt   = count(array_filter($depts,fn($d)=>$d['is_active']));

$page_title = $rtl ? 'الهيكل التنظيمي' : 'Departments';
$page_icon  = 'fa-sitemap';
$active_nav = 'departments.index';
$breadcrumb = [];
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
.d-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
@media(max-width:800px){.d-stats{grid-template-columns:repeat(2,1fr)}}
.d-card{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden}
.d-card-head{padding:13px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:8px}
.d-card-title{font-size:13px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:6px}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;font-weight:700;color:#64748b;padding:9px 14px;text-align:inherit;border-bottom:1px solid #f1f5f9;background:#f8fafc;white-space:nowrap}
tbody td{padding:11px 14px;font-size:13px;color:#334155;border-bottom:1px solid #f8fafc;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#f8fafc}
.dept-name{font-weight:700;color:#0f172a;display:flex;align-items:center;gap:6px}
.dept-code{font-size:11px;font-family:'Inter',monospace;background:#f1f5f9;padding:1px 7px;border-radius:4px;color:#475569;font-weight:600}
.parent-badge{font-size:10.5px;color:#64748b;display:flex;align-items:center;gap:4px;margin-top:2px}
.cnt-badge{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:700;border-radius:50px;padding:2px 10px}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}
.ab{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;border:1.5px solid;cursor:pointer;transition:all .15s;text-decoration:none;font-size:12px;background:#fff}
.ab-edit{color:#d97706;border-color:#fde68a}.ab-edit:hover{background:#d97706;color:#fff;border-color:#d97706}
.ab-del{color:#dc2626;border-color:#fecaca}.ab-del:hover{background:#dc2626;color:#fff;border-color:#dc2626}
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

<!-- ══ إحصاءات ══ -->
<div class="d-stats">
<?php $sc=[
  ['v'=>$total_depts, 'ar'=>'إجمالي الإدارات','en'=>'Total Depts',  'ico'=>'fa-sitemap',      'c'=>'#1565C0','bg'=>'#E3F2FD'],
  ['v'=>$active_cnt,  'ar'=>'نشطة',            'en'=>'Active',       'ico'=>'fa-circle-check', 'c'=>'#16a34a','bg'=>'#F0FDF4'],
  ['v'=>$total_assets,'ar'=>'إجمالي الأصول',   'en'=>'Total Assets', 'ico'=>'fa-boxes-stacked','c'=>'#7B1FA2','bg'=>'#F3E5F5'],
  ['v'=>$total_users, 'ar'=>'إجمالي الموظفين', 'en'=>'Total Users',  'ico'=>'fa-users',        'c'=>'#E65100','bg'=>'#FFF3E0'],
];
foreach($sc as $s): ?>
<div class="stat-card" style="cursor:default">
  <div class="stat-ico" style="background:<?= $s['bg'] ?>"><i class="fa-solid <?= $s['ico'] ?>" style="color:<?= $s['c'] ?>"></i></div>
  <div><div class="stat-lbl"><?= $rtl?e($s['ar']):e($s['en']) ?></div><div class="stat-val"><?= number_format($s['v']) ?></div></div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ الجدول ══ -->
<div class="d-card">
  <div class="d-card-head">
    <div class="d-card-title">
      <i class="fa-solid fa-sitemap" style="color:#1565C0"></i>
      <?= $rtl?'الإدارات والأقسام':'Departments & Sections' ?>
      <span style="font-size:11.5px;color:#94a3b8;font-weight:400">(<?= $total_depts ?> <?= $rtl?'إدارة':'depts' ?>)</span>
    </div>
    <?php if($can_create): ?>
    <a href="<?= BASE_URL ?>/departments/form.php" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-plus"></i><?= $rtl?'إضافة إدارة':'Add Department' ?>
    </a>
    <?php else: ?>
    <button onclick="showPermModal('إضافة إدارة','Add Department')" class="btn btn-primary btn-sm">
      <i class="fa-solid fa-plus"></i><?= $rtl?'إضافة':'Add' ?>
    </button>
    <?php endif; ?>
  </div>

  <div style="overflow-x:auto">
    <table>
      <thead><tr>
        <th><?= $rtl?'الإدارة / القسم':'Department / Section' ?></th>
        <th><?= $rtl?'التابع لـ':'Parent' ?></th>
        <th><?= $rtl?'التحويلة':'Ext.' ?></th>
        <th><?= $rtl?'البريد الإلكتروني':'Email' ?></th>
        <th><?= $rtl?'المدير':'Manager' ?></th>
        <th><?= $rtl?'الأصول':'Assets' ?></th>
        <th><?= $rtl?'الموظفون':'Users' ?></th>
        <th><?= $rtl?'الحالة':'Status' ?></th>
        <th></th>
      </tr></thead>
      <tbody>
      <?php if(empty($depts)): ?>
      <tr><td colspan="9" style="text-align:center;padding:48px;color:#94a3b8">
        <i class="fa-solid fa-sitemap" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3"></i>
        <?= $rtl?'لا توجد إدارات بعد':'No departments yet' ?>
      </td></tr>
      <?php else: foreach($depts as $d):
        $name    = $rtl ? $d['name'] : ($d['name_en'] ?: $d['name']);
        $isActive= (bool)$d['is_active'];
      ?>
      <tr>
        <!-- الاسم -->
        <td>
          <div class="dept-name">
            <?php if($d['parent_id']): ?>
            <span style="color:#cbd5e1;font-size:10px">└</span>
            <?php else: ?>
            <i class="fa-solid fa-building" style="color:#1565C0;font-size:11px"></i>
            <?php endif; ?>
            <?= e($name) ?>
            <span class="dept-code"><?= e($d['code']) ?></span>
          </div>
          <?php if($d['location']): ?>
          <div class="parent-badge">
            <span><i class="fa-solid fa-location-dot" style="font-size:9px"></i><?= e($d['location']) ?></span>
          </div>
          <?php endif; ?>
        </td>
        <!-- التابع -->
        <td>
          <?php if($d['parent_name']): ?>
          <span style="font-size:12px;color:#475569"><i class="fa-solid fa-turn-up fa-rotate-90" style="font-size:9px;color:#94a3b8"></i> <?= e($d['parent_name']) ?></span>
          <?php else: ?>
          <span style="font-size:11.5px;color:#94a3b8"><?= $rtl?'رئيسية':'Top level' ?></span>
          <?php endif; ?>
        </td>
        <!-- التحويلة -->
        <td>
          <?php if(!empty($d['phone'])): ?>
          <span style="font-size:12px;font-family:Inter;color:#334155;direction:ltr;display:inline-block"><i class="fa-solid fa-phone" style="font-size:9px;color:#94a3b8"></i> <?= e($d['phone']) ?></span>
          <?php else: ?>
          <span style="font-size:12px;color:#cbd5e1">—</span>
          <?php endif; ?>
        </td>
        <!-- البريد الإلكتروني -->
        <td>
          <?php if(!empty($d['email'])): ?>
          <span style="font-size:11.5px;font-family:Inter;color:#475569;direction:ltr;display:inline-block"><i class="fa-solid fa-envelope" style="font-size:9px;color:#94a3b8"></i> <?= e($d['email']) ?></span>
          <?php else: ?>
          <span style="font-size:12px;color:#cbd5e1">—</span>
          <?php endif; ?>
        </td>
        <!-- المدير -->
        <td>
          <?php if($d['manager_name']): ?>
          <span style="font-size:12.5px;font-weight:600;color:#334155"><i class="fa-solid fa-user-tie" style="font-size:10px;color:#64748b"></i> <?= e($d['manager_name']) ?></span>
          <?php else: ?>
          <span style="font-size:12px;color:#94a3b8">—</span>
          <?php endif; ?>
        </td>
        <!-- الأصول -->
        <td>
          <a href="<?= BASE_URL ?>/assets/index.php?dept=<?= $d['id'] ?>"
             class="cnt-badge" style="color:#7B1FA2;background:#F3E5F5;text-decoration:none">
            <i class="fa-solid fa-boxes-stacked" style="font-size:10px"></i>
            <?= number_format($d['asset_cnt']) ?>
          </a>
        </td>
        <!-- الموظفون -->
        <td>
          <span class="cnt-badge" style="color:#1565C0;background:#E3F2FD">
            <i class="fa-solid fa-users" style="font-size:10px"></i>
            <?= number_format($d['user_cnt']) ?>
          </span>
        </td>
        <!-- الحالة -->
        <td>
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:<?= $isActive?'#16a34a':'#94a3b8' ?>">
            <span class="status-dot" style="background:<?= $isActive?'#16a34a':'#94a3b8' ?>"></span>
            <?= $isActive?($rtl?'نشطة':'Active'):($rtl?'معطّلة':'Inactive') ?>
          </span>
        </td>
        <!-- إجراءات -->
        <td onclick="event.stopPropagation()">
          <div style="display:flex;gap:5px">
            <?php if($can_edit): ?>
            <a href="<?= BASE_URL ?>/departments/form.php?id=<?= $d['id'] ?>" class="ab ab-edit" title="<?= $rtl?'تعديل':'Edit' ?>">
              <i class="fa-solid fa-pen"></i>
            </a>
            <?php endif; ?>
            <?php if($can_delete && $d['asset_cnt']==0 && $d['user_cnt']==0): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl?'حذف هذه الإدارة؟':'Delete this department?' ?>')">
              <?= csrf_input() ?>
              <input type="hidden" name="delete_id" value="<?= $d['id'] ?>">
              <button type="submit" class="ab ab-del" title="<?= $rtl?'حذف':'Delete' ?>">
                <i class="fa-solid fa-trash"></i>
              </button>
            </form>
            <?php elseif($can_delete): ?>
            <button class="ab ab-del" style="opacity:.35;cursor:not-allowed"
              title="<?= $rtl?'لا يمكن الحذف — يحتوي على بيانات':'Cannot delete — has data' ?>">
              <i class="fa-solid fa-trash"></i>
            </button>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

</main>
</div>

<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body>
</html>