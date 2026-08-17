<?php
/**
 * profile.php — صفحة الملف الشخصي الشاملة
 * ─────────────────────────────────────────────────────────────
 *   6 تبويبات:
 *     1) data      — البيانات الشخصية (تعديل ذاتي)
 *     2) password  — تغيير كلمة المرور
 *     3) team      — فريقي (للمدراء فقط)
 *     4) activity  — نشاطي (سجل أعمالي)
 *     5) custody   — عهدي (الأصول اللي عليّ)
 *     6) prefs     — التفضيلات (المظهر/اللغة/التنبيهات)
 *
 *   القواعد:
 *     - حقول للقراءة فقط: username, full_name (الاسم الأساسي)
 *     - باقي الحقول قابلة للتعديل الذاتي
 *     - تبويب team يظهر فقط لمن عنده دور مدير (dept_manager/section_manager/site_manager)
 *     - تبويب custody يحترم data_scope (لو admin/executive يعرض الكل)
 */
require_once __DIR__ . '/config.php';
page_guard('profile', 'view');

$rtl         = is_rtl();
$uid         = user_id();
$active_nav  = 'profile';
$page_title  = $rtl ? 'الملف الشخصي' : 'My Profile';
$page_icon   = 'fa-user-circle';

// ── التبويبات ───────────────────────────────────────────────
$allowed_tabs = ['data', 'password', 'team', 'activity', 'custody', 'prefs'];
$tab = $_GET['tab'] ?? 'data';
if (!in_array($tab, $allowed_tabs, true)) $tab = 'data';

// ── تحديد ما إذا كان المستخدم مديراً (يستحق تبويب "فريقي") ──
$is_manager = false;
$manager_scopes = [];
$my_dept = (int)(current_user()['department_id'] ?? 0);

$role_check = $pdo->prepare("
    SELECT r.name
    FROM user_roles ur
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
");
$role_check->execute([$uid]);
$user_roles = array_column($role_check->fetchAll(PDO::FETCH_ASSOC), 'name');
$is_manager = (bool)array_intersect($user_roles, ['dept_manager', 'section_manager', 'site_manager', 'admin', 'executive']);

// ── رسائل الفلاش ─────────────────────────────────────────────
$flashes = $_SESSION['flashes'] ?? [];
unset($_SESSION['flashes']);

// ══════════════════════════════════════════════════════════════
// POST Handlers
// ══════════════════════════════════════════════════════════════
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf()) {
        flash('error', $rtl ? 'الجلسة منتهية. حدّث الصفحة وحاول مرة أخرى.' : 'Session expired. Refresh and retry.');
        header('Location: ?tab=' . urlencode($tab));
        exit;
    }

    $action = $_POST['action'] ?? '';

    // 1) تحديث البيانات الشخصية
    if ($action === 'update_data') {
        page_guard('profile', 'edit');
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $job_ar   = trim($_POST['job_title'] ?? '');
        $job_en   = trim($_POST['job_title_en'] ?? '');
        $name_en  = trim($_POST['full_name_en'] ?? '');

        // تحقق من البريد
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', $rtl ? 'البريد الإلكتروني غير صحيح.' : 'Invalid email.');
            header('Location: ?tab=data');
            exit;
        }

        $pdo->prepare("
            UPDATE users SET
                email = ?, phone = ?,
                job_title = ?, job_title_en = ?,
                full_name_en = ?
            WHERE id = ?
        ")->execute([$email ?: null, $phone ?: null, $job_ar ?: null, $job_en ?: null, $name_en ?: null, $uid]);

        // تحديث $_SESSION
        $_SESSION['user']['email'] = $email;
        $_SESSION['user']['phone'] = $phone;
        $_SESSION['user']['job_title'] = $job_ar;

        log_activity('profile_update', 'user', "تحديث البيانات الشخصية", $uid);
        flash('success', $rtl ? '✅ تم تحديث البيانات بنجاح.' : '✅ Profile updated.');
        header('Location: ?tab=data');
        exit;
    }

    // 2) تغيير كلمة المرور
    if ($action === 'change_password') {
        page_guard('profile', 'edit');
        $cur = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (strlen($new) < 8) {
            flash('error', $rtl ? 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.' : 'Password must be at least 8 chars.');
            header('Location: ?tab=password');
            exit;
        }
        if ($new !== $confirm) {
            flash('error', $rtl ? 'كلمة المرور الجديدة وتأكيدها غير متطابقتين.' : 'New password and confirmation do not match.');
            header('Location: ?tab=password');
            exit;
        }

        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($cur, $hash)) {
            flash('error', $rtl ? 'كلمة المرور الحالية غير صحيحة.' : 'Current password is incorrect.');
            header('Location: ?tab=password');
            exit;
        }

        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $pdo->prepare("
            UPDATE users SET
                password_hash = ?,
                password_changed_at = NOW(),
                must_change_password = 0
            WHERE id = ?
        ")->execute([$new_hash, $uid]);

        log_activity('password_change', 'user', "تغيير كلمة المرور", $uid);
        flash('success', $rtl ? '🔒 تم تغيير كلمة المرور بنجاح.' : '🔒 Password changed successfully.');
        header('Location: ?tab=password');
        exit;
    }

    // 3) تحديث التفضيلات
    if ($action === 'update_prefs') {
        page_guard('profile', 'edit');
        $theme         = in_array($_POST['theme'] ?? 'light', ['light', 'dark', 'auto'], true) ? $_POST['theme'] : 'light';
        $language      = in_array($_POST['language'] ?? 'ar', ['ar', 'en'], true) ? $_POST['language'] : 'ar';
        $timezone      = trim($_POST['timezone'] ?? 'Asia/Riyadh');
        $date_format   = in_array($_POST['date_format'] ?? 'hijri', ['hijri', 'gregorian', 'both'], true) ? $_POST['date_format'] : 'hijri';
        $notify_email  = isset($_POST['notify_email']) ? 1 : 0;
        $notify_browser = isset($_POST['notify_browser']) ? 1 : 0;
        $notify_sound  = isset($_POST['notify_sound']) ? 1 : 0;
        $items_per_page = max(5, min(200, (int)($_POST['items_per_page'] ?? 25)));

        $pdo->prepare("
            INSERT INTO user_settings
                (user_id, theme, language, timezone, date_format,
                 notify_email, notify_browser, notify_sound, items_per_page)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                theme = VALUES(theme),
                language = VALUES(language),
                timezone = VALUES(timezone),
                date_format = VALUES(date_format),
                notify_email = VALUES(notify_email),
                notify_browser = VALUES(notify_browser),
                notify_sound = VALUES(notify_sound),
                items_per_page = VALUES(items_per_page)
        ")->execute([$uid, $theme, $language, $timezone, $date_format,
                     $notify_email, $notify_browser, $notify_sound, $items_per_page]);

        // تحديث $_SESSION
        $_SESSION['user']['language'] = $language;

        log_activity('prefs_update', 'user', "تحديث التفضيلات", $uid);
        flash('success', $rtl ? '🎨 تم تحديث التفضيلات.' : '🎨 Preferences updated.');
        header('Location: ?tab=prefs');
        exit;
    }
}

// ══════════════════════════════════════════════════════════════
// جلب البيانات حسب التبويب
// ══════════════════════════════════════════════════════════════

// معلومات المستخدم (مشتركة بين كل التبويبات)
$me_stmt = $pdo->prepare("
    SELECT u.*, d.name AS dept_name, d.parent_id AS dept_parent_id
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.id = ?
");
$me_stmt->execute([$uid]);
$me = $me_stmt->fetch(PDO::FETCH_ASSOC);

// التفضيلات
$prefs_stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$prefs_stmt->execute([$uid]);
$prefs = $prefs_stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'theme' => 'light', 'language' => 'ar', 'timezone' => 'Asia/Riyadh',
    'date_format' => 'hijri', 'notify_email' => 1, 'notify_browser' => 1,
    'notify_sound' => 1, 'items_per_page' => 25
];

// فريقي (للمدراء)
$my_team = [];
if ($is_manager && $tab === 'team') {
    // نحدد القسم النشط: لو مدير قسم رئيسي (parent_id IS NULL) → كل الفروع
    // لو مدير قسم فرعي → القسم الفرعي فقط
    $my_dept_row = $pdo->prepare("SELECT id, name, parent_id FROM departments WHERE id = ?");
    $my_dept_row->execute([$my_dept]);
    $my_dept_info = $my_dept_row->fetch(PDO::FETCH_ASSOC);

    if ($my_dept_info) {
        if ($my_dept_info['parent_id'] === null) {
            // قسم رئيسي: جلب كل الأقسام الفرعية
            $sub_dept_ids = $pdo->prepare("SELECT id FROM departments WHERE parent_id = ? OR id = ?");
            $sub_dept_ids->execute([$my_dept, $my_dept]);
            $sub_dept_ids = array_column($sub_dept_ids->fetchAll(PDO::FETCH_ASSOC), 'id');
        } else {
            // قسم فرعي: فقط القسم نفسه
            $sub_dept_ids = [$my_dept];
        }

        if (!empty($sub_dept_ids)) {
            $in = implode(',', array_fill(0, count($sub_dept_ids), '?'));
            $team_stmt = $pdo->prepare("
                SELECT u.id, u.full_name, u.full_name_en, u.username, u.email, u.phone,
                       u.job_title, u.last_login, u.is_active,
                       d.name AS dept_name,
                       GROUP_CONCAT(r.display_name SEPARATOR '، ') AS role_names
                FROM users u
                LEFT JOIN departments d ON d.id = u.department_id
                LEFT JOIN user_roles ur ON ur.user_id = u.id
                LEFT JOIN roles r ON r.id = ur.role_id
                WHERE u.department_id IN ($in) AND u.is_active = 1
                GROUP BY u.id
                ORDER BY u.full_name
            ");
            $team_stmt->execute($sub_dept_ids);
            $my_team = $team_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// نشاطي
$my_activity = [];
if ($tab === 'activity') {
    $act_stmt = $pdo->prepare("
        SELECT id, action, target, details, ip_address, created_at
        FROM activity_log
        WHERE user_id = ?
        ORDER BY id DESC
        LIMIT 50
    ");
    $act_stmt->execute([$uid]);
    $my_activity = $act_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// عهدي (الأصول اللي تحت عهدة المستخدم) — يحترم data_scope
$my_assets = [];
$my_assets_total_cost = 0;
$my_assets_critical = ['A' => 0, 'B' => 0, 'C' => 0];
if ($tab === 'custody') {
    $scope = data_scope('custody', 'a');
    $assets_stmt = $pdo->prepare("
        SELECT a.id, a.tag_number, a.asset_number, a.description, a.description_ar,
               a.criticality_class, a.cost, a.custody_date, a.serial_number,
               a.manufacturer_name, a.model_number, a.loc_building, a.loc_floor,
               d.name AS dept_name
        FROM assets a
        LEFT JOIN departments d ON d.id = a.custodian_dept_id
        WHERE a.status='active' AND " . $scope['where'] . "
        ORDER BY
            CASE WHEN a.criticality_class='A' THEN 1 WHEN a.criticality_class='B' THEN 2 ELSE 3 END,
            a.custody_date DESC
        LIMIT 100
    ");
    $assets_stmt->execute($scope['params']);
    $my_assets = $assets_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($my_assets as $a) {
        $my_assets_total_cost += (float)$a['cost'];
        $c = $a['criticality_class'] ?: 'C';
        $my_assets_critical[$c] = ($my_assets_critical[$c] ?? 0) + 1;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.pf-wrap { max-width: 1280px; margin: 0 auto; padding: 14px; }
.pf-back { font-size: 12.5px; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 10px; font-weight: 600; }
.pf-back:hover { color: #7c3aed; }

/* Hero */
.pf-hero {
  background: linear-gradient(135deg, #1e293b 0%, #4f46e5 50%, #7c3aed 100%);
  color: #fff;
  border-radius: 18px;
  padding: 22px 28px;
  margin-bottom: 14px;
  display: flex; align-items: center; gap: 18px;
  box-shadow: 0 10px 30px rgba(79,70,229,.25);
  position: relative; overflow: hidden;
}
.pf-hero::before { content:''; position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: radial-gradient(circle, rgba(255,255,255,.08), transparent 70%); border-radius: 50%; }
.pf-avatar {
  width: 78px; height: 78px;
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(255,255,255,.25), rgba(255,255,255,.10));
  border: 2.5px solid rgba(255,255,255,.35);
  display: flex; align-items: center; justify-content: center;
  font-size: 32px; font-weight: 800;
  flex-shrink: 0;
  text-shadow: 0 2px 4px rgba(0,0,0,.2);
}
.pf-hero-info { flex: 1; min-width: 0; }
.pf-hero-name { font-size: 22px; font-weight: 800; margin: 0 0 2px; }
.pf-hero-en { font-size: 13px; opacity: .85; font-family: 'Inter', sans-serif; }
.pf-hero-meta { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 8px; font-size: 12.5px; opacity: .92; }
.pf-hero-meta span { display: inline-flex; align-items: center; gap: 5px; }
.pf-hero-meta i { font-size: 11px; opacity: .8; }

/* Tabs */
.pf-tabs {
  display: flex; gap: 4px;
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 4px;
  margin-bottom: 14px;
  overflow-x: auto;
}
.pf-tab {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 16px;
  border-radius: 9px;
  font-size: 13px;
  font-weight: 700;
  color: #475569;
  text-decoration: none;
  white-space: nowrap;
  transition: all 0.15s;
}
.pf-tab i { font-size: 13px; }
.pf-tab:hover { background: #f1f5f9; color: #0f172a; }
.pf-tab.active {
  background: linear-gradient(135deg, #4f46e5, #7c3aed);
  color: #fff;
  box-shadow: 0 4px 12px rgba(124,58,237,.30);
}

/* Tab content */
.pf-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  padding: 22px 24px;
  margin-bottom: 14px;
}
.pf-card-h {
  display: flex; align-items: center; gap: 10px;
  font-size: 15px; font-weight: 800; color: #0f172a;
  margin-bottom: 16px; padding-bottom: 12px;
  border-bottom: 1.5px solid #f1f5f9;
}
.pf-card-h i { color: #7c3aed; font-size: 16px; }

/* Form */
.pf-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px 18px; }
.pf-field { display: flex; flex-direction: column; gap: 4px; }
.pf-field.full { grid-column: 1 / -1; }
.pf-label { font-size: 11.5px; font-weight: 700; color: #475569; }
.pf-label .req { color: #dc2626; }
.pf-input, .pf-select {
  padding: 9px 12px;
  border: 1.5px solid #e2e8f0;
  border-radius: 8px;
  font-size: 13.5px;
  background: #fff;
  font-family: inherit;
  color: #0f172a;
  width: 100%;
}
.pf-input:focus, .pf-select:focus {
  outline: none; border-color: #7c3aed;
  box-shadow: 0 0 0 3px rgba(124,58,237,.10);
}
.pf-input[readonly] { background: #f8fafc; color: #64748b; cursor: not-allowed; }
.pf-help { font-size: 11px; color: #94a3b8; margin-top: 2px; }

.pf-actions { display: flex; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #f1f5f9; }
.pf-btn {
  padding: 9px 22px;
  border-radius: 8px;
  border: none;
  font-size: 13.5px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px;
  font-family: inherit;
}
.pf-btn-primary {
  background: linear-gradient(135deg, #4f46e5, #7c3aed);
  color: #fff;
  box-shadow: 0 4px 12px rgba(124,58,237,.30);
}
.pf-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(124,58,237,.40); }

/* Flash messages */
.pf-flash {
  padding: 12px 16px;
  border-radius: 10px;
  margin-bottom: 14px;
  font-size: 13.5px;
  font-weight: 600;
  display: flex; align-items: center; gap: 8px;
}
.pf-flash-success { background: #ecfdf5; color: #065f46; border: 1.5px solid #6ee7b7; }
.pf-flash-error   { background: #fef2f2; color: #991b1b; border: 1.5px solid #fca5a5; }
.pf-flash-warning { background: #fffbeb; color: #92400e; border: 1.5px solid #fcd34d; }

/* Team table */
.pf-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.pf-tbl th { background: #f8fafc; padding: 10px 12px; text-align: right; font-weight: 700; color: #475569; font-size: 11.5px; border-bottom: 1.5px solid #e2e8f0; }
.pf-tbl td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; }
.pf-tbl tr:hover { background: #fafbfc; }
.pf-team-avatar {
  width: 32px; height: 32px; border-radius: 8px;
  background: linear-gradient(135deg, #4f46e5, #7c3aed);
  color: #fff; display: inline-flex; align-items: center; justify-content: center;
  font-weight: 800; font-size: 13px;
  margin-inline-end: 8px;
  flex-shrink: 0;
}
.pf-online { color: #16a34a; font-size: 11px; }
.pf-offline { color: #94a3b8; font-size: 11px; }
.pf-role-pill { display: inline-block; padding: 2px 8px; background: #f3e8ff; color: #7c3aed; border-radius: 5px; font-size: 10.5px; font-weight: 700; margin: 1px; }

/* Activity timeline */
.pf-timeline { list-style: none; padding: 0; margin: 0; }
.pf-timeline li {
  display: flex; gap: 12px;
  padding: 10px 0;
  border-bottom: 1px dashed #f1f5f9;
}
.pf-timeline li:last-child { border-bottom: none; }
.pf-timeline-dot {
  width: 30px; height: 30px;
  border-radius: 50%;
  background: #f3e8ff;
  color: #7c3aed;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; flex-shrink: 0;
}
.pf-timeline-dot.success { background: #d1fae5; color: #059669; }
.pf-timeline-dot.warning { background: #fef3c7; color: #d97706; }
.pf-timeline-dot.error   { background: #fee2e2; color: #dc2626; }
.pf-timeline-body { flex: 1; }
.pf-timeline-action { font-size: 13px; font-weight: 700; color: #0f172a; }
.pf-timeline-meta { font-size: 11.5px; color: #94a3b8; margin-top: 2px; }

/* Custody stats */
.pf-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
.pf-stat { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 14px; display: flex; align-items: center; gap: 10px; }
.pf-stat-ico { width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.pf-stat-num { font-size: 19px; font-weight: 800; color: #0f172a; line-height: 1; }
.pf-stat-lbl { font-size: 11px; color: #64748b; margin-top: 2px; font-weight: 600; }

.pf-crit { display: inline-flex; padding: 2px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 800; letter-spacing: .5px; }
.pf-crit.A { background: #fef2f2; color: #dc2626; }
.pf-crit.B { background: #fef3c7; color: #d97706; }
.pf-crit.C { background: #ecfeff; color: #0891b2; }

.pf-empty {
  text-align: center; padding: 50px 16px;
  color: #94a3b8; background: #fff;
  border-radius: 12px; border: 1.5px solid #e2e8f0;
}
.pf-empty i { font-size: 44px; display: block; margin-bottom: 10px; color: #cbd5e1; }

@media (max-width: 900px) { .pf-grid { grid-template-columns: 1fr; } .pf-stats { grid-template-columns: repeat(2, 1fr); } }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="pf-wrap">

  <div class="pf-hero">
    <div class="pf-avatar"><?= e(mb_substr($me['full_name'] ?? 'U', 0, 1)) ?></div>
    <div class="pf-hero-info">
      <h1 class="pf-hero-name"><?= e($me['full_name']) ?></h1>
      <?php if (!empty($me['full_name_en'])): ?>
        <div class="pf-hero-en"><?= e($me['full_name_en']) ?></div>
      <?php endif; ?>
      <div class="pf-hero-meta">
        <?php if (!empty($me['job_title'])): ?>
          <span><i class="fa-solid fa-briefcase"></i> <?= e($me['job_title']) ?></span>
        <?php endif; ?>
        <?php if (!empty($me['dept_name'])): ?>
          <span><i class="fa-solid fa-building"></i> <?= e($me['dept_name']) ?></span>
        <?php endif; ?>
        <span><i class="fa-solid fa-user-tag"></i> @<?= e($me['username']) ?></span>
        <?php foreach ($user_roles as $r): ?>
          <span><i class="fa-solid fa-shield-halved"></i> <?= e($r) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php foreach ($flashes as $f): ?>
    <div class="pf-flash pf-flash-<?= e($f['type']) ?>">
      <i class="fa-solid fa-<?= $f['type']==='success'?'circle-check':'circle-exclamation' ?>"></i>
      <?= e($f['message']) ?>
    </div>
  <?php endforeach; ?>

  <nav class="pf-tabs">
    <a href="?tab=data"      class="pf-tab <?= $tab==='data'?'active':'' ?>"><i class="fa-solid fa-id-card"></i> <?= $rtl?'البيانات':'Data' ?></a>
    <a href="?tab=password"  class="pf-tab <?= $tab==='password'?'active':'' ?>"><i class="fa-solid fa-lock"></i> <?= $rtl?'كلمة المرور':'Password' ?></a>
    <?php if ($is_manager): ?>
      <a href="?tab=team"   class="pf-tab <?= $tab==='team'?'active':'' ?>"><i class="fa-solid fa-people-group"></i> <?= $rtl?'فريقي':'My Team' ?> <span style="background:rgba(255,255,255,.2);padding:1px 7px;border-radius:9px;font-size:10.5px;margin-inline-start:3px"><?= count($my_team) ?></span></a>
    <?php endif; ?>
    <a href="?tab=custody"   class="pf-tab <?= $tab==='custody'?'active':'' ?>"><i class="fa-solid fa-handshake"></i> <?= $rtl?'عهدي':'My Custody' ?></a>
    <a href="?tab=activity"  class="pf-tab <?= $tab==='activity'?'active':'' ?>"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'نشاطي':'My Activity' ?></a>
    <a href="?tab=prefs"     class="pf-tab <?= $tab==='prefs'?'active':'' ?>"><i class="fa-solid fa-sliders"></i> <?= $rtl?'التفضيلات':'Preferences' ?></a>
  </nav>

  <?php if ($tab === 'data'): ?>
  <!-- ════════ التبويب 1: البيانات الشخصية ════════ -->
  <div class="pf-card">
    <h3 class="pf-card-h"><i class="fa-solid fa-id-card"></i> <?= $rtl?'البيانات الشخصية':'Personal Data' ?></h3>
    <form method="post">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="update_data">

      <div class="pf-grid">
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'اسم المستخدم (للتسجيل)':'Username' ?></label>
          <input type="text" class="pf-input" value="<?= e($me['username']) ?>" readonly>
          <span class="pf-help"><?= $rtl?'لا يمكن تغييره — تواصل مع مدير النظام':'Cannot be changed — contact admin' ?></span>
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'الاسم الكامل':'Full Name' ?></label>
          <input type="text" class="pf-input" value="<?= e($me['full_name']) ?>" readonly>
          <span class="pf-help"><?= $rtl?'يستخدم في كل الوثائق الرسمية — لتغييره تواصل مع الإدارة':'Used in all official docs — contact admin to change' ?></span>
        </div>

        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'الاسم بالإنجليزية':'Name in English' ?></label>
          <input type="text" name="full_name_en" class="pf-input" value="<?= e($me['full_name_en'] ?? '') ?>" dir="ltr" placeholder="John Smith">
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'الرقم الوظيفي':'Employee ID' ?></label>
          <input type="text" class="pf-input" value="<?= e($me['employee_id'] ?? '—') ?>" readonly>
        </div>

        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'البريد الإلكتروني':'Email' ?> <span class="req">*</span></label>
          <input type="email" name="email" class="pf-input" value="<?= e($me['email'] ?? '') ?>" dir="ltr" placeholder="user@hospital.sa">
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'رقم الجوال':'Phone' ?></label>
          <input type="tel" name="phone" class="pf-input" value="<?= e($me['phone'] ?? '') ?>" dir="ltr" placeholder="05xxxxxxxx">
        </div>

        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'المسمى الوظيفي (عربي)':'Job Title (AR)' ?></label>
          <input type="text" name="job_title" class="pf-input" value="<?= e($me['job_title'] ?? '') ?>" placeholder="مثال: فني صيانة">
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'المسمى الوظيفي (إنجليزي)':'Job Title (EN)' ?></label>
          <input type="text" name="job_title_en" class="pf-input" value="<?= e($me['job_title_en'] ?? '') ?>" dir="ltr" placeholder="e.g. Maintenance Technician">
        </div>

        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'القسم':'Department' ?></label>
          <input type="text" class="pf-input" value="<?= e($me['dept_name'] ?? '—') ?>" readonly>
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'تاريخ إنشاء الحساب':'Account Created' ?></label>
          <input type="text" class="pf-input" value="<?= e($me['created_at']) ?>" readonly>
        </div>
      </div>

      <div class="pf-actions">
        <button type="submit" class="pf-btn pf-btn-primary">
          <i class="fa-solid fa-floppy-disk"></i>
          <?= $rtl?'حفظ التغييرات':'Save Changes' ?>
        </button>
      </div>
    </form>
  </div>

  <?php elseif ($tab === 'password'): ?>
  <!-- ════════ التبويب 2: كلمة المرور ════════ -->
  <div class="pf-card">
    <h3 class="pf-card-h"><i class="fa-solid fa-lock"></i> <?= $rtl?'تغيير كلمة المرور':'Change Password' ?></h3>
    <form method="post">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="change_password">

      <div class="pf-grid">
        <div class="pf-field full">
          <label class="pf-label"><?= $rtl?'كلمة المرور الحالية':'Current Password' ?> <span class="req">*</span></label>
          <input type="password" name="current_password" class="pf-input" required autocomplete="current-password">
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'كلمة المرور الجديدة':'New Password' ?> <span class="req">*</span></label>
          <input type="password" name="new_password" class="pf-input" required minlength="8" autocomplete="new-password">
          <span class="pf-help"><?= $rtl?'8 أحرف على الأقل':'At least 8 characters' ?></span>
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'تأكيد كلمة المرور':'Confirm Password' ?> <span class="req">*</span></label>
          <input type="password" name="confirm_password" class="pf-input" required minlength="8" autocomplete="new-password">
        </div>
      </div>

      <div class="pf-actions">
        <button type="submit" class="pf-btn pf-btn-primary">
          <i class="fa-solid fa-shield-halved"></i>
          <?= $rtl?'تحديث كلمة المرور':'Update Password' ?>
        </button>
      </div>
    </form>

    <div style="margin-top:18px; padding:12px 14px; background:#fef3c7; border-radius:10px; font-size:12px; color:#92400e; display:flex; gap:8px; align-items:flex-start">
      <i class="fa-solid fa-lightbulb" style="margin-top:2px"></i>
      <div>
        <strong><?= $rtl?'نصيحة أمنية:':'Security tip:' ?></strong>
        <?= $rtl?'استخدم كلمة مرور قوية تحتوي أحرف كبيرة وصغيرة وأرقام ورموز. لا تشاركها مع أحد.':'Use a strong password with upper/lower case, numbers, and symbols. Do not share it with anyone.' ?>
      </div>
    </div>
  </div>

  <?php elseif ($tab === 'team' && $is_manager): ?>
  <!-- ════════ التبويب 3: فريقي ════════ -->
  <div class="pf-card">
    <h3 class="pf-card-h"><i class="fa-solid fa-people-group"></i> <?= $rtl?'الموظفون التابعون لي':'My Team Members' ?></h3>
    <p style="color:#64748b; font-size:12.5px; margin-bottom:14px">
      <?php if ($my_dept_info && $my_dept_info['parent_id'] === null): ?>
        <?= $rtl?'كل موظفي قسمك الرئيسي وجميع فروعه (بما فيهم رؤساء الفروع والموظفون).':'All staff in your main department and all its sub-departments (including sub-managers and employees).' ?>
      <?php else: ?>
        <?= $rtl?'موظفو قسمك الفرعي فقط.':'Staff in your sub-department only.' ?>
      <?php endif; ?>
    </p>

    <?php if (empty($my_team)): ?>
      <div class="pf-empty">
        <i class="fa-solid fa-user-slash"></i>
        <h3><?= $rtl?'لا يوجد موظفين تابعين لك':'No team members' ?></h3>
        <p><?= $rtl?'تأكد من إسناد موظفين لقسمك من صفحة المستخدمين.':'Make sure staff are assigned to your department from the Users page.' ?></p>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="pf-tbl">
      <thead><tr>
        <th>#</th>
        <th><?= $rtl?'الموظف':'Employee' ?></th>
        <th><?= $rtl?'القسم':'Department' ?></th>
        <th><?= $rtl?'الدور':'Role' ?></th>
        <th><?= $rtl?'الاتصال':'Contact' ?></th>
        <th><?= $rtl?'آخر دخول':'Last Login' ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($my_team as $i => $m):
        $initial = mb_substr($m['full_name'], 0, 1);
        $is_online = false;
        if ($m['last_login']) {
          $diff = (time() - strtotime($m['last_login'])) / 60;
          $is_online = $diff < 15;
        }
      ?>
      <tr>
        <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
        <td>
          <div style="display:flex; align-items:center">
            <div class="pf-team-avatar"><?= e($initial) ?></div>
            <div>
              <div style="font-weight:700;color:#0f172a"><?= e($m['full_name']) ?></div>
              <div style="font-size:11.5px;color:#94a3b8;font-family:monospace">@<?= e($m['username']) ?>
                <?php if ($m['id'] == $uid): ?><span style="color:#7c3aed">(<?= $rtl?'أنت':'You' ?>)</span><?php endif; ?>
              </div>
            </div>
          </div>
        </td>
        <td style="font-size:12.5px"><?= e($m['dept_name'] ?? '—') ?></td>
        <td><?= $m['role_names'] ? '<span class="pf-role-pill">'.e($m['role_names']).'</span>' : '—' ?></td>
        <td style="font-size:12px">
          <?php if ($m['email']): ?><div style="color:#475569;direction:ltr"><?= e($m['email']) ?></div><?php endif; ?>
          <?php if ($m['phone']): ?><div style="color:#94a3b8;direction:ltr"><?= e($m['phone']) ?></div><?php endif; ?>
        </td>
        <td style="font-size:11.5px">
          <?php if ($m['last_login']): ?>
            <div style="color:#475569"><?= date('Y-m-d H:i', strtotime($m['last_login'])) ?></div>
            <div class="<?= $is_online?'pf-online':'pf-offline' ?>">
              <i class="fa-solid fa-circle" style="font-size:7px"></i>
              <?= $is_online ? ($rtl?'متصل الآن':'Online now') : ($rtl?'آخر نشاط: '.human_time_diff(strtotime($m['last_login'])):'Last seen '.human_time_diff(strtotime($m['last_login']))) ?>
            </div>
          <?php else: ?>
            <span style="color:#94a3b8">—</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p style="margin-top:10px; font-size:11.5px; color:#64748b; text-align:center">
      <?= $rtl?'المجموع: '.count($my_team).' موظف':'Total: '.count($my_team).' members' ?>
    </p>
    <?php endif; ?>
  </div>

  <?php elseif ($tab === 'custody'): ?>
  <!-- ════════ التبويب 4: عهدي ════════ -->
  <div class="pf-stats">
    <div class="pf-stat">
      <div class="pf-stat-ico" style="background:#d1fae5;color:#059669"><i class="fa-solid fa-handshake"></i></div>
      <div><div class="pf-stat-num"><?= count($my_assets) ?></div><div class="pf-stat-lbl"><?= $rtl?'أصول تحت العهدة':'Assets' ?></div></div>
    </div>
    <div class="pf-stat">
      <div class="pf-stat-ico" style="background:#fef2f2;color:#dc2626"><i class="fa-solid fa-circle-exclamation"></i></div>
      <div><div class="pf-stat-num"><?= $my_assets_critical['A'] ?? 0 ?></div><div class="pf-stat-lbl"><?= $rtl?'حرجة (A)':'Critical (A)' ?></div></div>
    </div>
    <div class="pf-stat">
      <div class="pf-stat-ico" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div><div class="pf-stat-num"><?= $my_assets_critical['B'] ?? 0 ?></div><div class="pf-stat-lbl"><?= $rtl?'عالية (B)':'High (B)' ?></div></div>
    </div>
    <div class="pf-stat">
      <div class="pf-stat-ico" style="background:#ecfeff;color:#0891b2"><i class="fa-solid fa-coins"></i></div>
      <div><div class="pf-stat-num"><?= number_format($my_assets_total_cost, 0) ?></div><div class="pf-stat-lbl"><?= $rtl?'قيمة (ر.س)':'Value (SAR)' ?></div></div>
    </div>
  </div>

  <div class="pf-card">
    <h3 class="pf-card-h"><i class="fa-solid fa-handshake"></i> <?= $rtl?'الأصول تحت عهدة المستخدم الحالي':'Assets under current user' ?></h3>
    <?php if (empty($my_assets)): ?>
      <div class="pf-empty">
        <i class="fa-solid fa-circle-check"></i>
        <h3><?= $rtl?'لا توجد أصول تحت عهدتك':'No assets under your custody' ?></h3>
        <p><?= $rtl?'لم يتم تعيين عهدة بعد على هذا المستخدم.':'No custody assigned to this user yet.' ?></p>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="pf-tbl">
      <thead><tr>
        <th>#</th>
        <th><?= $rtl?'الحساسية':'Crit' ?></th>
        <th><?= $rtl?'التاج':'Tag' ?></th>
        <th><?= $rtl?'الاسم':'Name' ?></th>
        <th><?= $rtl?'المصنع':'Mfr' ?></th>
        <th><?= $rtl?'الموقع':'Location' ?></th>
        <th><?= $rtl?'تاريخ العهدة':'Custody Date' ?></th>
        <th><?= $rtl?'القيمة':'Value' ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($my_assets as $i => $a):
        $crit = $a['criticality_class'] ?: 'C';
      ?>
      <tr>
        <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
        <td><span class="pf-crit <?= e($crit) ?>"><?= e($crit) ?></span></td>
        <td style="font-family:monospace;font-size:12px;color:#7c3aed;font-weight:700"><?= e($a['tag_number'] ?: '—') ?></td>
        <td>
          <div style="font-weight:600"><?= e(truncate($a['description'] ?? '', 40)) ?></div>
          <?php if ($a['description_ar']): ?><div style="font-size:11.5px;color:#475569;direction:rtl"><?= e(truncate($a['description_ar'], 40)) ?></div><?php endif; ?>
        </td>
        <td style="font-size:12px"><?= e($a['manufacturer_name'] ?? '—') ?></td>
        <td style="font-size:11.5px;color:#64748b">
          <?= e($a['loc_building'] ?? '') ?>
          <?php if ($a['loc_floor']): ?>/ <?= e($a['loc_floor']) ?><?php endif; ?>
        </td>
        <td style="font-size:11.5px;color:#64748b"><?= $a['custody_date'] ? date('Y-m-d', strtotime($a['custody_date'])) : '—' ?></td>
        <td style="font-family:monospace;font-weight:700"><?= $a['cost'] ? number_format($a['cost'], 0) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <?php elseif ($tab === 'activity'): ?>
  <!-- ════════ التبويب 5: نشاطي ════════ -->
  <div class="pf-card">
    <h3 class="pf-card-h"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'آخر أعمالي في النظام':'My recent activity' ?></h3>
    <?php if (empty($my_activity)): ?>
      <div class="pf-empty">
        <i class="fa-solid fa-clock"></i>
        <h3><?= $rtl?'لا يوجد نشاط مسجل':'No activity yet' ?></h3>
      </div>
    <?php else: ?>
    <ul class="pf-timeline">
      <?php foreach ($my_activity as $act):
        $cls = 'default';
        if (in_array($act['action'], ['login_success'])) $cls = 'success';
        elseif (strpos($act['action'], 'unauthorized') !== false || strpos($act['action'], 'fail') !== false) $cls = 'error';
        elseif (strpos($act['action'], 'update') !== false || strpos($act['action'], 'approve') !== false) $cls = 'warning';
        $icon = match (true) {
          str_contains($act['action'], 'login')     => 'right-to-bracket',
          str_contains($act['action'], 'logout')    => 'right-from-bracket',
          str_contains($act['action'], 'custody')   => 'handshake',
          str_contains($act['action'], 'password')  => 'lock',
          str_contains($act['action'], 'profile')   => 'user',
          str_contains($act['action'], 'asset')     => 'box',
          str_contains($act['action'], 'unauthorized') => 'ban',
          default => 'circle'
        };
      ?>
      <li>
        <div class="pf-timeline-dot <?= $cls ?>"><i class="fa-solid fa-<?= $icon ?>"></i></div>
        <div class="pf-timeline-body">
          <div class="pf-timeline-action"><?= e($act['action']) ?><?php if ($act['target']): ?> <span style="color:#7c3aed">→ <?= e($act['target']) ?></span><?php endif; ?></div>
          <?php if ($act['details']): ?><div style="font-size:11.5px;color:#475569;margin-top:2px"><?= e(truncate($act['details'], 100)) ?></div><?php endif; ?>
          <div class="pf-timeline-meta">
            <i class="fa-regular fa-clock"></i> <?= e($act['created_at']) ?>
            <?php if ($act['ip_address']): ?>
              · <i class="fa-solid fa-network-wired"></i> <?= e($act['ip_address']) ?>
            <?php endif; ?>
          </div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <?php elseif ($tab === 'prefs'): ?>
  <!-- ════════ التبويب 6: التفضيلات ════════ -->
  <div class="pf-card">
    <h3 class="pf-card-h"><i class="fa-solid fa-sliders"></i> <?= $rtl?'التفضيلات':'Preferences' ?></h3>
    <form method="post">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="update_prefs">

      <h4 style="font-size:13px; font-weight:800; color:#475569; margin:8px 0 10px"><i class="fa-solid fa-palette" style="color:#7c3aed"></i> <?= $rtl?'المظهر واللغة':'Appearance & Language' ?></h4>
      <div class="pf-grid">
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'المظهر':'Theme' ?></label>
          <select name="theme" class="pf-select">
            <option value="light" <?= $prefs['theme']==='light'?'selected':'' ?>><?= $rtl?'فاتح':'Light' ?></option>
            <option value="dark" <?= $prefs['theme']==='dark'?'selected':'' ?>><?= $rtl?'داكن':'Dark' ?></option>
            <option value="auto" <?= $prefs['theme']==='auto'?'selected':'' ?>><?= $rtl?'تلقائي (حسب النظام)':'Auto (system)' ?></option>
          </select>
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'اللغة':'Language' ?></label>
          <select name="language" class="pf-select">
            <option value="ar" <?= $prefs['language']==='ar'?'selected':'' ?>>العربية</option>
            <option value="en" <?= $prefs['language']==='en'?'selected':'' ?>>English</option>
          </select>
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'المنطقة الزمنية':'Timezone' ?></label>
          <select name="timezone" class="pf-select">
            <option value="Asia/Riyadh" <?= $prefs['timezone']==='Asia/Riyadh'?'selected':'' ?>>Asia/Riyadh (GMT+3)</option>
            <option value="Asia/Dubai" <?= $prefs['timezone']==='Asia/Dubai'?'selected':'' ?>>Asia/Dubai (GMT+4)</option>
            <option value="Africa/Cairo" <?= $prefs['timezone']==='Africa/Cairo'?'selected':'' ?>>Africa/Cairo (GMT+2)</option>
            <option value="UTC" <?= $prefs['timezone']==='UTC'?'selected':'' ?>>UTC (GMT+0)</option>
          </select>
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'صيغة التاريخ':'Date Format' ?></label>
          <select name="date_format" class="pf-select">
            <option value="hijri" <?= $prefs['date_format']==='hijri'?'selected':'' ?>><?= $rtl?'هجري':'Hijri' ?></option>
            <option value="gregorian" <?= $prefs['date_format']==='gregorian'?'selected':'' ?>><?= $rtl?'ميلادي':'Gregorian' ?></option>
            <option value="both" <?= $prefs['date_format']==='both'?'selected':'' ?>><?= $rtl?'كلاهما':'Both' ?></option>
          </select>
        </div>
      </div>

      <h4 style="font-size:13px; font-weight:800; color:#475569; margin:20px 0 10px"><i class="fa-solid fa-bell" style="color:#7c3aed"></i> <?= $rtl?'الإشعارات':'Notifications' ?></h4>
      <div class="pf-grid">
        <div class="pf-field">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:9px 0">
            <input type="checkbox" name="notify_email" <?= $prefs['notify_email']?'checked':'' ?> style="width:18px; height:18px; accent-color:#7c3aed">
            <span><?= $rtl?'إشعارات البريد الإلكتروني':'Email notifications' ?></span>
          </label>
        </div>
        <div class="pf-field">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:9px 0">
            <input type="checkbox" name="notify_browser" <?= $prefs['notify_browser']?'checked':'' ?> style="width:18px; height:18px; accent-color:#7c3aed">
            <span><?= $rtl?'إشعارات المتصفح (في الموقع)':'Browser notifications (in-app)' ?></span>
          </label>
        </div>
        <div class="pf-field">
          <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:9px 0">
            <input type="checkbox" name="notify_sound" <?= $prefs['notify_sound']?'checked':'' ?> style="width:18px; height:18px; accent-color:#7c3aed">
            <span><?= $rtl?'صوت التنبيه':'Notification sound' ?></span>
          </label>
        </div>
        <div class="pf-field">
          <label class="pf-label"><?= $rtl?'عناصر في الصفحة':'Items per page' ?></label>
          <input type="number" name="items_per_page" class="pf-input" value="<?= e($prefs['items_per_page']) ?>" min="5" max="200" step="5">
        </div>
      </div>

      <div class="pf-actions">
        <button type="submit" class="pf-btn pf-btn-primary">
          <i class="fa-solid fa-floppy-disk"></i>
          <?= $rtl?'حفظ التفضيلات':'Save Preferences' ?>
        </button>
      </div>
    </form>
  </div>
  <?php endif; ?>

</div><!-- /.pf-wrap -->
</main>
</div><!-- /.main-area -->
</body>
</html>
