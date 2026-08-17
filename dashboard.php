<?php
/**
 * dashboard.php — لوحة التحكم الرئيسية
 * pmsh_assets · مستشفى الأمير مشاري بن سعود
 */
require_once __DIR__ . '/config.php';
page_guard('dashboard');

$lang = current_lang();
$rtl  = is_rtl();
$user = current_user();

// ── تهيئة اللغة الافتراضية ────────────────────────────────────
if (!isset($_SESSION['lang'])) {
    set_lang('ar');
    $lang = 'ar';
    $rtl  = true;
}

// ── جلب الإحصاءات بأمان ──────────────────────────────────────
function _sc(string $sql): ?int {
    global $pdo;
    try { return (int)$pdo->query($sql)->fetchColumn(); }
    catch (PDOException $e) { return null; }
}

// ── الإحصائيات الحية (مُصفَّاة حسب صلاحية المستخدم) ──────────
$_cur         = current_user();
$_user_roles  = array_column($_cur['roles'] ?? [], 'name');
$_uid         = user_id();
$see_all      = is_admin() || in_array('executive', $_user_roles) || can_see_all_from_db();

$stats = [];

// الأصول: الكل إلا إذا كان مدير قسم (يعرض قسمه فقط)
if (can('assets.index', 'view')) {
    if ($see_all) {
        $stats['assets'] = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
    } else {
        $my_dept = (int)(current_user()['department_id'] ?? 0);
        $stats['assets'] = $my_dept
            ? (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active' AND (custodian_dept_id = $my_dept OR custodian_user_id IN (SELECT id FROM users WHERE department_id = $my_dept))")->fetchColumn()
            : 0;
    }
}

// البلاغات
if (can('complaints.index', 'view')) {
    if ($see_all) {
        $stats['complaints'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status NOT IN ('closed','rejected','cancelled')")->fetchColumn();
    } else {
        $my_dept = (int)(current_user()['department_id'] ?? 0);
        $stats['complaints'] = $my_dept
            ? (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status NOT IN ('closed','rejected','cancelled') AND dept_id = $my_dept")->fetchColumn()
            : 0;
    }
}

// أوامر العمل (آمن — لو الجدول غير موجود)
if (can('work_orders.index', 'view')) {
    $wo_table_exists = (bool)$pdo->query("SHOW TABLES LIKE 'work_orders'")->fetchColumn();
    if ($wo_table_exists) {
        if ($see_all) {
            $stats['work_orders'] = (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE status NOT IN ('closed','cancelled')")->fetchColumn();
        } else {
            $my_dept = (int)(current_user()['department_id'] ?? 0);
            $stats['work_orders'] = $my_dept
                ? (int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE status NOT IN ('closed','cancelled') AND dept_id = $my_dept")->fetchColumn()
                : 0;
        }
    }
}

// المستخدمون (admin/executive فقط)
if ($see_all && can('users.index', 'view')) {
    $stats['users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
}

// الأقسام (admin/executive فقط)
if ($see_all && can('departments.index', 'view')) {
    $stats['departments'] = (int)$pdo->query("SELECT COUNT(*) FROM departments WHERE is_active=1")->fetchColumn();
}

// إحصائيات إضافية (مفيدة جداً في اللوحة)
if (can('complaints.index', 'view')) {
    if ($see_all) {
        $stats['overdue_complaints'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status NOT IN ('closed','resolved','rejected','cancelled') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    } else {
        $my_dept = (int)(current_user()['department_id'] ?? 0);
        $stats['overdue_complaints'] = $my_dept
            ? (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status NOT IN ('closed','resolved','rejected','cancelled') AND dept_id = $my_dept AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn()
            : 0;
    }
}

if (can('assets.index', 'view')) {
    $stats['pending_custody'] = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active' AND custodian_user_id IS NULL")->fetchColumn();
}

$stats['my_custody'] = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active' AND custodian_user_id = " . (int)$_uid)->fetchColumn();
$stats['my_open_tasks'] = (int)$pdo->query("SELECT COUNT(*) FROM user_tasks WHERE completed = 0 AND (owner_id = " . (int)$_uid . " OR EXISTS(SELECT 1 FROM task_shares WHERE task_shares.task_id = user_tasks.id AND task_shares.user_id = " . (int)$_uid . "))")->fetchColumn();
$stats['unread_notifications'] = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . (int)$_uid . " AND is_read = 0")->fetchColumn();

// ── آخر الأنشطة (مُصفَّاة حسب الصلاحية) ──────────────────────
if ($see_all) {
    $activities = $pdo->query("
        SELECT al.action, al.target, al.created_at, u.full_name, u.full_name_en
        FROM activity_log al
        LEFT JOIN users u ON u.id = al.user_id
        ORDER BY al.created_at DESC LIMIT 10
    ")->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT al.action, al.target, al.created_at, u.full_name, u.full_name_en
        FROM activity_log al
        LEFT JOIN users u ON u.id = al.user_id
        WHERE al.user_id = ?
        ORDER BY al.created_at DESC LIMIT 10
    ");
    $stmt->execute([user_id()]);
    $activities = $stmt->fetchAll();
}

// ── الإشعارات ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 5");
$stmt->execute([user_id()]);
$notifs = $stmt->fetchAll();

// ── الإجراءات السريعة ─────────────────────────────────────────
$quick = [];
if (can('complaints.form',    'create')) $quick[] = ['ar'=>'رفع بلاغ جديد',       'en'=>'New Complaint',         'icon'=>'fa-bell',            'url'=>'/complaints/form.php',    'c'=>'#E65100','bg'=>'#FFF3E0'];
if (can('assets.form',        'create')) $quick[] = ['ar'=>'إضافة أصل',             'en'=>'Add Asset',             'icon'=>'fa-box-open',         'url'=>'/assets/form.php',        'c'=>'#1565C0','bg'=>'#E3F2FD'];
if (can('receiving.form',     'create')) $quick[] = ['ar'=>'محضر استلام جديد',     'en'=>'New Receiving Minute',  'icon'=>'fa-truck-ramp-box',   'url'=>'/receiving/form.php',     'c'=>'#00838F','bg'=>'#E0F7FA'];
if (can('work_orders.form',   'create')) $quick[] = ['ar'=>'إنشاء أمر عمل',         'en'=>'Create Work Order',     'icon'=>'fa-clipboard-list',   'url'=>'/work_orders/form.php',   'c'=>'#7B1FA2','bg'=>'#F3E5F5'];
if (can('installation.custody','create'))$quick[] = ['ar'=>'توزيع عهدة',             'en'=>'Custody Assignment',    'icon'=>'fa-handshake',        'url'=>'/installation/custody.php','c'=>'#2E7D32','bg'=>'#E8F5E9'];
if (can('users.form',         'create')) $quick[] = ['ar'=>'إضافة مستخدم',           'en'=>'Add User',              'icon'=>'fa-user-plus',        'url'=>'/users/form.php',         'c'=>'#C62828','bg'=>'#FFEBEE'];

// ── التحية حسب الوقت ──────────────────────────────────────────
$h = (int)date('H');
$greet = $h >= 5 && $h < 12
    ? ['ar'=>'صباح الخير',  'en'=>'Good morning']
    : ($h < 17
        ? ['ar'=>'مساء الخير',  'en'=>'Good afternoon']
        : ['ar'=>'مساء الخير',  'en'=>'Good evening']);

// ── التاريخ ───────────────────────────────────────────────────
$days_ar   = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
$months_ar = ['','يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
$today_str = $rtl
    ? $days_ar[(int)date('w')] . '، ' . date('j') . ' ' . $months_ar[(int)date('n')] . ' ' . date('Y')
    : date('l, F j, Y');

// ── ترجمة النشاط ──────────────────────────────────────────────
function _act_label(string $action, string $lang): string {
    $ar = ['login_success'=>'سجّل دخوله','logout'=>'سجّل خروجه','unauthorized_access'=>'محاولة وصول غير مصرّح','create'=>'أضاف سجلاً','edit'=>'عدّل سجلاً','delete'=>'حذف سجلاً','approve'=>'اعتمد طلباً'];
    $en = ['login_success'=>'signed in','logout'=>'signed out','unauthorized_access'=>'attempted unauthorized access','create'=>'created a record','edit'=>'edited a record','delete'=>'deleted a record','approve'=>'approved a request'];
    $map = $lang === 'ar' ? $ar : $en;
    foreach ($map as $k => $v) { if (str_contains($action, $k)) return $v; }
    return $action;
}
function _act_style(string $action): array {
    return match(true) {
        str_contains($action,'login')   => ['fa-right-to-bracket','#1565C0','#E3F2FD'],
        str_contains($action,'logout')  => ['fa-right-from-bracket','#64748b','#F1F5F9'],
        str_contains($action,'create')  => ['fa-plus',             '#16a34a','#F0FDF4'],
        str_contains($action,'edit')    => ['fa-pen',              '#E65100','#FFF3E0'],
        str_contains($action,'delete')  => ['fa-trash',            '#dc2626','#FEF2F2'],
        str_contains($action,'approve') => ['fa-check',            '#16a34a','#F0FDF4'],
        str_contains($action,'unauth')  => ['fa-ban',              '#dc2626','#FEF2F2'],
        default                          => ['fa-circle-dot',       '#7B1FA2','#F3E5F5'],
    };
}
function _time_ago(string $dt, string $lang): string {
    $d = max(0, time() - strtotime($dt));
    if ($d < 60)    return $lang==='ar' ? 'الآن' : 'just now';
    if ($d < 3600)  return $lang==='ar' ? 'منذ '.floor($d/60).' دقيقة' : floor($d/60).'m ago';
    if ($d < 86400) return $lang==='ar' ? 'منذ '.floor($d/3600).' ساعة' : floor($d/3600).'h ago';
    return $lang==='ar' ? 'منذ '.floor($d/86400).' يوم' : floor($d/86400).'d ago';
}

$page_title = __('nav.dashboard');
$page_icon  = 'fa-gauge-high';
$active_nav = 'dashboard';
$breadcrumb = [];
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= _e('nav.dashboard') ?> — <?= e(get_setting('hospital_name', 'PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
/* ─── Welcome Banner ──────────────────────────────── */
.wb {
  background:linear-gradient(-45deg,#040f1c,#0a1a30,#163560,#0d2550);
  background-size:400% 400%;
  animation:gbg 20s ease infinite;
  border-radius:20px;
  padding:24px 32px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:18px;
  position:relative;
  overflow:hidden;
}
@keyframes gbg{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}
.wb::before{content:'';position:absolute;width:600px;height:600px;border-radius:50%;background:radial-gradient(circle,rgba(0,172,193,.1),transparent 60%);top:-200px;right:-100px;pointer-events:none}
.wb::after{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.02) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.02) 1px,transparent 1px);background-size:40px 40px;pointer-events:none}
.wb-l{position:relative;z-index:1}
.wb-greet{font-size:21px;font-weight:800;color:#fff;margin-bottom:4px}
.wb-greet .hi{color:#5bc4d4}
.wb-date{font-size:12.5px;color:rgba(255,255,255,.5);margin-bottom:12px;display:flex;align-items:center;gap:5px}
.wb-tags{display:flex;gap:7px;flex-wrap:wrap}
.wb-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.14);border-radius:50px;padding:3px 11px;color:rgba(255,255,255,.78)}
.wb-tag.tl{border-color:rgba(0,172,193,.3);background:rgba(0,172,193,.1);color:#5bc4d4}
.wb-r{position:relative;z-index:1;text-align:center;flex-shrink:0}
.wb-circle{width:82px;height:82px;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.12);border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center}
.wb-num{font-size:26px;font-weight:800;color:#fff;line-height:1}
.wb-lbl{font-size:9.5px;color:rgba(255,255,255,.45);margin-top:2px}
.wb-sub{font-size:11px;color:rgba(255,255,255,.38);margin-top:6px}

/* ─── Stats Grid ─────────────────────────────────── */
.sg{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:18px}
@media(max-width:1280px){.sg{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px) {.sg{grid-template-columns:repeat(2,1fr)}}

/* ─── Dashboard 2-col body ───────────────────────── */
.db{display:grid;grid-template-columns:1fr 310px;gap:16px}
@media(max-width:1024px){.db{grid-template-columns:1fr}}

/* ─── Info Card ──────────────────────────────────── */
.ic{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);display:flex;flex-direction:column;overflow:hidden;margin-bottom:14px}
.ic:last-child{margin-bottom:0}
.ic-hd{padding:15px 18px 0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.ic-ht{font-size:13.5px;font-weight:600;color:#0f172a;display:flex;align-items:center;gap:7px}
.ic-ht i{color:#1565C0;font-size:13px}
.ic-bd{padding:10px 18px 16px;flex:1}
.ic-ft{display:flex;align-items:center;justify-content:center;gap:5px;padding:10px;border-top:1px solid #f1f5f9;font-size:12px;font-weight:500;color:#1565C0;text-decoration:none;transition:all .17s}
.ic-ft:hover{background:#e3f2fd}
.ic-ft i{font-size:11px}

/* ─── Modules Grid ───────────────────────────────── */
.mg{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:14px}
@media(max-width:768px){.mg{grid-template-columns:repeat(2,1fr)}}
.mc{display:flex;flex-direction:column;align-items:center;gap:7px;padding:18px 10px;border-radius:14px;border:1.5px solid #e2e8f0;background:#fff;text-decoration:none;color:#0f172a;transition:all .17s;text-align:center}
.mc:hover{border-color:#bfdbfe;background:#eff6ff;transform:translateY(-2px)}
.mc-ico{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px}
.mc-name{font-size:12.5px;font-weight:600}
.mc-sub{font-size:10.5px;color:#64748b}
.mc-lock{font-size:10px;color:#94a3b8;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:50px;padding:2px 7px;display:inline-flex;align-items:center;gap:3px}

/* ─── Language Toggle ────────────────────────────── */
.lang-bar{display:flex;align-items:center;gap:5px;background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.14);border-radius:50px;padding:3px;position:relative;z-index:1}
.lang-bar button{padding:3px 11px;border-radius:50px;font-size:11px;font-weight:600;cursor:pointer;border:none;background:transparent;color:rgba(255,255,255,.55);transition:all .2s;font-family:'Tajawal',sans-serif}
.lang-bar button.on{background:var(--teal,#00ACC1);color:#fff}

/* ─── Animations ─────────────────────────────────── */
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.au1{animation:fadeUp .35s ease .05s both}
.au2{animation:fadeUp .35s ease .12s both}
.au3{animation:fadeUp .35s ease .2s both}
.au4{animation:fadeUp .35s ease .28s both}
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>

<div class="main-area" id="mainArea">

  <?php include BASE_PATH . '/includes/topbar.php'; ?>

  <main class="page-content">

    <!-- ═══ بانر الترحيب ═══════════════════════════════ -->
    <div class="wb au1">
      <div class="wb-l">
        <div class="wb-greet">
          <?= e($greet[$lang]) ?>,&nbsp;
          <span class="hi"><?= e(explode(' ', ($rtl ? $user['full_name'] : ($user['full_name_en'] ?: $user['full_name'])))[0]) ?>!</span>
        </div>
        <div class="wb-date">
          <i class="fa-regular fa-calendar" style="font-size:11px" aria-hidden="true"></i>
          <?= e($today_str) ?>
        </div>
        <div class="wb-tags">
          <span class="wb-tag tl">
            <i class="fa-solid fa-circle" style="font-size:5px" aria-hidden="true"></i>
            <?= $rtl ? 'متصل' : 'Online' ?>
          </span>
          <span class="wb-tag">
            <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
            <?= e($rtl
                ? ($user['primary_role']['display_name'] ?? 'مستخدم')
                : ($user['primary_role']['display_en']   ?? $user['primary_role']['display_name'] ?? 'User')) ?>
          </span>
          <?php if (!empty($user['employee_id'])): ?>
          <span class="wb-tag">
            <i class="fa-solid fa-id-badge" aria-hidden="true"></i>
            <?= e($user['employee_id']) ?>
          </span>
          <?php endif; ?>
          <?php if ($user['is_admin']): ?>
          <span class="wb-tag" style="border-color:rgba(124,58,237,.3);background:rgba(124,58,237,.1);color:#a78bfa">
            <i class="fa-solid fa-crown" aria-hidden="true"></i>
            <?= $rtl ? 'أدمن' : 'Admin' ?>
          </span>
          <?php endif; ?>
        </div>
      </div>
      <div class="wb-r">
        <div class="wb-circle" style="margin:0 auto" aria-label="<?= $rtl ? 'عدد الأنشطة' : 'Activities count' ?>">
          <div class="wb-num"><?= count($activities) ?></div>
          <div class="wb-lbl"><?= $rtl ? 'نشاط' : 'Activity' ?></div>
        </div>
        <div class="wb-sub"><?= $rtl ? 'اليوم' : 'Today' ?></div>
      </div>
    </div>

    <!-- ═══ الإحصاءات الحية (مُصفَّاة حسب الصلاحية) ══════════════════════════════════ -->
    <?php
    // نبني مصفوفة الإحصائيات بشكل شرطي — كل بطاقة تظهر فقط إذا عند المستخدم صلاحية
    $sc = [];
    if (isset($stats['assets']))        $sc[] = ['ar'=>'الأصول',         'en'=>'Assets',         'val'=>$stats['assets'],         'sub'=>$rtl?'إجمالي نشط':'active total',     'ico'=>'fa-boxes-stacked',  'c'=>'#1565C0','bg'=>'#E3F2FD','url'=>'/assets/index.php',         'code'=>'assets.index'];
    if (isset($stats['complaints']))    $sc[] = ['ar'=>'البلاغات المفتوحة','en'=>'Open Complaints','val'=>$stats['complaints'],     'sub'=>$rtl?'قيد المعالجة':'in progress',     'ico'=>'fa-bell',           'c'=>'#E65100','bg'=>'#FFF3E0','url'=>'/complaints/index.php',      'code'=>'complaints.index'];
    if (isset($stats['overdue_complaints']) && $stats['overdue_complaints'] > 0) $sc[] = ['ar'=>'بلاغات متعثرة', 'en'=>'Overdue',     'val'=>$stats['overdue_complaints'],'sub'=>$rtl?'أكثر من 7 أيام':'>7 days',       'ico'=>'fa-triangle-exclamation','c'=>'#dc2626','bg'=>'#FEF2F2','url'=>'/complaints/index.php?status=overdue','code'=>'complaints.index','alert'=>true];
    if (isset($stats['work_orders']))   $sc[] = ['ar'=>'أوامر العمل',    'en'=>'Work Orders',    'val'=>$stats['work_orders'],    'sub'=>$rtl?'قيد التنفيذ':'in progress',       'ico'=>'fa-clipboard-list', 'c'=>'#7B1FA2','bg'=>'#F3E5F5','url'=>'/work_orders/index.php',     'code'=>'work_orders.index'];
    if (isset($stats['pending_custody']) && $stats['pending_custody'] > 0) $sc[] = ['ar'=>'أصول تنتظر العهدة','en'=>'Pending Custody','val'=>$stats['pending_custody'],'sub'=>$rtl?'بدون مستلم':'no custodian',     'ico'=>'fa-handshake',       'c'=>'#d97706','bg'=>'#FEF3C7','url'=>'/assets/custody_transfer.php','code'=>'custody_transfer'];
    if (isset($stats['my_custody']))    $sc[] = ['ar'=>'عهدي',           'en'=>'My Custody',     'val'=>$stats['my_custody'],     'sub'=>$rtl?'تحت عهدتك':'under you',           'ico'=>'fa-user-shield',     'c'=>'#0d9488','bg'=>'#CCFBF1','url'=>'/profile.php?tab=custody',   'code'=>''];
    if (isset($stats['my_open_tasks'])) $sc[] = ['ar'=>'مهامي المفتوحة','en'=>'My Open Tasks','val'=>$stats['my_open_tasks'],'sub'=>$rtl?'شخصية + مشاركة':'personal+shared','ico'=>'fa-list-check',     'c'=>'#7c3aed','bg'=>'#F3E8FF','url'=>'/profile.php?tab=tasks',      'code'=>''];
    if (isset($stats['users']))         $sc[] = ['ar'=>'المستخدمون',     'en'=>'Users',          'val'=>$stats['users'],          'sub'=>$rtl?'نشط':'active',                   'ico'=>'fa-users',           'c'=>'#00838F','bg'=>'#E0F7FA','url'=>'/users/index.php',           'code'=>'users.index'];
    if (isset($stats['departments']))   $sc[] = ['ar'=>'الإدارات',       'en'=>'Departments',    'val'=>$stats['departments'],    'sub'=>$rtl?'قسم رئيسي + فرعي':'main + sub',    'ico'=>'fa-sitemap',         'c'=>'#2E7D32','bg'=>'#E8F5E9','url'=>'/departments/index.php',     'code'=>'departments.index'];
    ?>
    <div class="sg au2" role="list" aria-label="<?= $rtl ? 'ملخص الإحصاءات' : 'Statistics summary' ?>">
      <?php foreach ($sc as $s): ?>
      <?php $cv = $s['code'] === '' || can($s['code'],'view'); ?>
      <?php $is_alert = !empty($s['alert']); ?>
      <a href="<?= $cv && $s['url'] ? BASE_URL . e($s['url']) : '#' ?>"
         class="stat-card<?= $is_alert?' stat-alert':'' ?>"
         style="<?= !$cv ? 'opacity:.55;cursor:default' : '' ?>"
         role="listitem">
        <div class="stat-ico" style="background:<?= $s['bg'] ?>" aria-hidden="true">
          <i class="fa-solid <?= $s['ico'] ?>" style="color:<?= $s['c'] ?>"></i>
        </div>
        <div>
          <div class="stat-lbl"><?= $rtl ? e($s['ar']) : e($s['en']) ?></div>
          <div class="stat-val"><?= number_format($s['val']) ?></div>
          <div class="stat-sub" style="color:<?= $is_alert ? '#dc2626' : '#94a3b8' ?>">
            <?= e($s['sub'] ?? '') ?>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- ═══ جسم الداشبورد ═════════════════════════════ -->
    <div class="db au3">

      <!-- ── العمود الرئيسي ──────────────────────────── -->
      <div>

        <!-- سجل النشاط -->
        <div class="ic">
          <div class="ic-hd">
            <div class="ic-ht">
              <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
              <?= $rtl ? 'آخر الأنشطة' : 'Recent Activity' ?>
            </div>
            <span style="font-size:11px;color:#94a3b8">
              <?= $rtl ? 'آخر 10 أنشطة' : 'Last 10 activities' ?>
            </span>
          </div>
          <div class="ic-bd">
            <?php if (empty($activities)): ?>
              <div class="empty-state">
                <i class="fa-regular fa-clock" aria-hidden="true"></i>
                <p><?= $rtl ? 'لا توجد أنشطة مسجلة بعد' : 'No activities recorded yet' ?></p>
              </div>
            <?php else: ?>
              <div class="act-list" role="list">
                <?php foreach ($activities as $a):
                  [$ico,$clr,$bg] = _act_style($a['action']);
                  $uname = $rtl ? ($a['full_name'] ?? 'النظام') : ($a['full_name_en'] ?: ($a['full_name'] ?? 'System'));
                ?>
                <div class="act-item" role="listitem">
                  <div class="act-ico" style="background:<?= $bg ?>" aria-hidden="true">
                    <i class="fa-solid <?= $ico ?>" style="color:<?= $clr ?>"></i>
                  </div>
                  <div style="flex:1;min-width:0">
                    <div class="act-txt">
                      <strong><?= e($uname) ?></strong>
                      <?= e(_act_label($a['action'], $lang)) ?>
                      <?php if ($a['target']): ?>
                        <span style="color:#94a3b8;font-size:11.5px">(<?= e($a['target']) ?>)</span>
                      <?php endif; ?>
                    </div>
                    <div class="act-meta">
                      <span><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= e(_time_ago($a['created_at'], $lang)) ?></span>
                      <span><?= date('h:i A', strtotime($a['created_at'])) ?></span>
                    </div>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <a href="<?= BASE_URL ?>/activity_log.php" class="ic-ft">
            <i class="fa-solid fa-<?= $rtl ? 'arrow-left' : 'arrow-right' ?>" aria-hidden="true"></i>
            <?= $rtl ? 'سجل النشاط الكامل' : 'Full activity log' ?>
          </a>
        </div>

        <!-- وحدات النظام -->
        <div class="ic">
          <div class="ic-hd">
            <div class="ic-ht">
              <i class="fa-solid fa-th-large" aria-hidden="true"></i>
              <?= $rtl ? 'وحدات النظام' : 'System Modules' ?>
            </div>
          </div>
          <div class="ic-bd">
            <?php
            $mods = [
              ['code'=>'assets.index',       'ar'=>'الأصول',          'en'=>'Assets',          'ico'=>'fa-boxes-stacked',    'c'=>'#1565C0','bg'=>'#E3F2FD','url'=>'/assets/index.php'],
              ['code'=>'receiving.index',    'ar'=>'الاستلام',        'en'=>'Receiving',        'ico'=>'fa-truck-ramp-box',   'c'=>'#00838F','bg'=>'#E0F7FA','url'=>'/receiving/index.php'],
              ['code'=>'installation.index', 'ar'=>'التركيب',         'en'=>'Installation',     'ico'=>'fa-screwdriver-wrench','c'=>'#7B1FA2','bg'=>'#F3E5F5','url'=>'/installation/index.php'],
              ['code'=>'complaints.index',   'ar'=>'البلاغات',        'en'=>'Complaints',       'ico'=>'fa-bell',             'c'=>'#E65100','bg'=>'#FFF3E0','url'=>'/complaints/index.php'],
              ['code'=>'work_orders.index',  'ar'=>'أوامر العمل',     'en'=>'Work Orders',      'ico'=>'fa-clipboard-list',   'c'=>'#C62828','bg'=>'#FFEBEE','url'=>'/work_orders/index.php'],
              ['code'=>'reports.assets',     'ar'=>'تقارير الأصول',   'en'=>'Asset Reports',    'ico'=>'fa-chart-pie',        'c'=>'#2E7D32','bg'=>'#E8F5E9','url'=>'/reports/assets/index.php'],
            ];
            ?>
            <div class="mg">
              <?php foreach ($mods as $m): ?>
              <?php $acc = can($m['code'],'view'); ?>
              <a href="<?= $acc ? BASE_URL . e($m['url']) : '#' ?>"
                 class="mc"
                 style="<?= !$acc ? 'opacity:.45' : '' ?>"
                 <?= !$acc ? 'aria-disabled="true" tabindex="-1"' : '' ?>>
                <div class="mc-ico" style="background:<?= $m['bg'] ?>" aria-hidden="true">
                  <i class="fa-solid <?= $m['ico'] ?>" style="color:<?= $m['c'] ?>"></i>
                </div>
                <div class="mc-name"><?= $rtl ? e($m['ar']) : e($m['en']) ?></div>
                <?php if (!$acc): ?>
                  <span class="mc-lock"><i class="fa-solid fa-lock" style="font-size:9px"></i><?= $rtl ? 'محدود' : 'Limited' ?></span>
                <?php endif; ?>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div><!-- /main col -->

      <!-- ── العمود الجانبي ──────────────────────────── -->
      <div>

        <!-- إجراءات سريعة -->
        <?php if (!empty($quick)): ?>
        <div class="ic">
          <div class="ic-hd">
            <div class="ic-ht">
              <i class="fa-solid fa-bolt" aria-hidden="true"></i>
              <?= $rtl ? 'إجراءات سريعة' : 'Quick Actions' ?>
            </div>
          </div>
          <div class="ic-bd">
            <div class="qa-list" role="list">
              <?php foreach ($quick as $q): ?>
              <a href="<?= BASE_URL . e($q['url']) ?>" class="qa-btn" role="listitem">
                <div class="qa-ico" style="background:<?= $q['bg'] ?>" aria-hidden="true">
                  <i class="fa-solid <?= $q['icon'] ?>" style="color:<?= $q['c'] ?>"></i>
                </div>
                <span class="qa-label"><?= $rtl ? e($q['ar']) : e($q['en']) ?></span>
                <i class="fa-solid fa-chevron-<?= $rtl ? 'left' : 'right' ?> qa-arr" aria-hidden="true"></i>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- الإشعارات -->
        <div class="ic">
          <div class="ic-hd">
            <div class="ic-ht">
              <i class="fa-regular fa-bell" aria-hidden="true"></i>
              <?= $rtl ? 'الإشعارات' : 'Notifications' ?>
              <?php if (!empty($notifs)): ?>
              <span style="font-size:10px;background:#dc2626;color:#fff;border-radius:50px;padding:1px 6px;font-weight:700"><?= count($notifs) ?></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="ic-bd">
            <?php if (empty($notifs)): ?>
              <div class="empty-state">
                <i class="fa-regular fa-bell-slash" aria-hidden="true"></i>
                <p><?= $rtl ? 'لا توجد إشعارات جديدة' : 'No new notifications' ?></p>
              </div>
            <?php else: ?>
              <?php foreach ($notifs as $n): ?>
              <div class="notif-item" role="article">
                <div class="notif-dot" aria-hidden="true"></div>
                <div>
                  <div class="notif-body"><?= e($rtl ? $n['title'] : ($n['title_en'] ?: $n['title'])) ?></div>
                  <div class="notif-time"><?= e(_time_ago($n['created_at'], $lang)) ?></div>
                </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <a href="<?= BASE_URL ?>/notifications.php" class="ic-ft">
            <i class="fa-solid fa-<?= $rtl ? 'arrow-left' : 'arrow-right' ?>" aria-hidden="true"></i>
            <?= $rtl ? 'كل الإشعارات' : 'All notifications' ?>
          </a>
        </div>

        <!-- بطاقة النظام -->
        <div class="ic">
          <div class="ic-hd">
            <div class="ic-ht">
              <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
              <?= $rtl ? 'معلومات النظام' : 'System Info' ?>
            </div>
          </div>
          <div class="ic-bd">
            <?php
            $si = [
              ['ico'=>'fa-hospital',        'ar'=>'المستشفى', 'en'=>'Hospital', 'v'=> get_setting('hospital_name','—')],
              ['ico'=>'fa-building-medical','ar'=>'التجمع',   'en'=>'Cluster',  'v'=> get_setting('health_cluster','—')],
              ['ico'=>'fa-code-branch',     'ar'=>'الإصدار',  'en'=>'Version',  'v'=> APP_VERSION],
              ['ico'=>'fa-clock',           'ar'=>'آخر دخول', 'en'=>'Last login','v'=> date('h:i A · d/m/Y')],
            ];
            ?>
            <?php foreach ($si as $i => $s): ?>
            <div style="display:flex;align-items:center;gap:9px;padding:7px 0;<?= $i < count($si)-1 ? 'border-bottom:1px solid #f1f5f9' : '' ?>">
              <div style="width:28px;height:28px;background:#f1f5f9;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fa-solid <?= $s['ico'] ?>" style="font-size:12px;color:#94a3b8" aria-hidden="true"></i>
              </div>
              <div>
                <div style="font-size:10.5px;color:#94a3b8"><?= $rtl ? e($s['ar']) : e($s['en']) ?></div>
                <div style="font-size:12.5px;color:#0f172a;font-weight:500"><?= e($s['v']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div><!-- /side col -->
    </div><!-- /dash-body -->

    <!-- ═══ 5 Widgets للوحة التحكم (2026-07-24) ═══ -->
    <?php include BASE_PATH . '/includes/dashboard_widgets.php'; ?>

  </main>
</div>


</body>
</html>