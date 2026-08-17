<?php
/**
 * reports/custody/by_employee.php — العهدة حسب الموظف
 * ──────────────────────────────────────────────────────────────────
 *   • كل موظف + الأصول اللي تحت عهدته + قيمة إجمالية + تنبيهات
 *   • تفاعلي: النقر على الموظف = تفاصيله
 *   • استعراضي بحت — لا أزرار إجراء
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.custody.by_employee');

$can_export  = can('reports.custody.by_employee', 'export');
$excel_mode  = report_excel_mode_active('reports.custody.by_employee');
$print_mode  = report_print_mode_active('reports.custody.by_employee');
$print_charts = report_print_charts_mode_active('reports.custody.by_employee');

$rtl = is_rtl();
$active_nav = 'reports.custody';
$page_title = $rtl ? 'العهد حسب الموظف' : 'Custody by Employee';

// ═══ فلاتر ═══
$f_user = (int)($_GET['user'] ?? 0);
$f_dept = (int)($_GET['dept'] ?? 0);
$f_search = trim($_GET['q'] ?? '');

// فلترة حسب قسم المستخدم
$scope = data_scope('custody', 'a');

// ═══ ملخص حسب الموظف ═══
// نستخدم derived table عشان نحسب effective_dept_id قبل الـ JOIN النهائي
// (تجنّب COALESCE في ON clause لأنه قد يفشل في بعض بيئات MariaDB)
$emp_stats_stmt = $pdo->prepare("
    SELECT
        u.id, u.full_name, u.username,
        d.name AS dept_name,
        COUNT(a.id) AS asset_count,
        SUM(CASE WHEN a.criticality_class='A' THEN 1 ELSE 0 END) AS crit_A,
        SUM(CASE WHEN a.criticality_class='B' THEN 1 ELSE 0 END) AS crit_B,
        SUM(CASE WHEN a.criticality_class='C' THEN 1 ELSE 0 END) AS crit_C,
        COALESCE(SUM(a.cost), 0) AS total_cost,
        MIN(a.custody_date) AS oldest_custody,
        MAX(a.custody_date) AS newest_custody
    FROM users u
    INNER JOIN (
        SELECT a.*, COALESCE(a.custodian_dept_id, _u.department_id) AS effective_dept_id
        FROM assets a
        LEFT JOIN users _u ON _u.id = a.custodian_user_id
        WHERE a.status='active' AND a.custodian_user_id IS NOT NULL AND " . $scope['where'] . "
    ) a ON a.custodian_user_id = u.id
    LEFT JOIN departments d ON d.id = a.effective_dept_id
    GROUP BY u.id, u.full_name, u.username, d.name
    ORDER BY asset_count DESC, u.full_name
");
$emp_stats_stmt->execute($scope['params']);
$emp_stats = $emp_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

$grand_total_emp_assets = array_sum(array_column($emp_stats, 'asset_count'));
$grand_total_emp_cost = array_sum(array_column($emp_stats, 'total_cost'));

// ═══ الأقسام للفلتر ═══
$depts = $pdo->query("
    SELECT id, name FROM departments
    WHERE id IN (SELECT DISTINCT COALESCE(custodian_dept_id, (SELECT department_id FROM users WHERE id=custodian_user_id)) FROM assets WHERE status='active' AND custodian_user_id IS NOT NULL)
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// ═══ التفاصيل لموظف محدد ═══
$selected_user = null;
$user_assets = [];

if ($f_user) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.full_name, u.username, u.email, u.department_id, d.name AS dept_name
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.id = ?
    ");
    $stmt->execute([$f_user]);
    $selected_user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_user) {
        // حارس النطاق: لو المستخدم الحالي لا يرى كل العهدة، نتأكد أن
        // الموظف المختار ينتمي لقسمه قبل عرض التفاصيل.
        $can_view_user = can_see_all_from_db();
        if (!$can_view_user) {
            $my_dept = (int)(current_user()['department_id'] ?? 0);
            $their_dept = (int)($selected_user['department_id'] ?? 0);
            $can_view_user = ($my_dept > 0 && $my_dept === $their_dept);
        }

        if ($can_view_user) {
            $where = "WHERE eff.status='active' AND eff.custodian_user_id = ?";
            $params = [$f_user];
            if ($f_search !== '') {
                $where .= " AND (eff.tag_number LIKE ? OR eff.description LIKE ? OR eff.manufacturer_name LIKE ? OR eff.model_number LIKE ? OR eff.serial_number LIKE ?)";
                $like = '%' . $f_search . '%';
                array_push($params, $like, $like, $like, $like, $like);
            }
            // derived table: نحسب effective_dept_id هنا بدل JOIN ON
            $stmt = $pdo->prepare("
                SELECT eff.id, eff.tag_number, eff.description, eff.description_ar, eff.criticality_class,
                       eff.asset_type, eff.manufacturer_name, eff.model_number, eff.cost, eff.custody_date,
                       eff.warranty_expiry, eff.loc_building, eff.loc_floor, eff.loc_room,
                       d.name AS dept_name
                FROM (
                    SELECT a.id, a.tag_number, a.description, a.description_ar, a.criticality_class,
                           a.asset_type, a.manufacturer_name, a.model_number, a.cost, a.custody_date,
                           a.warranty_expiry, a.loc_building, a.loc_floor, a.loc_room,
                           a.status, a.custodian_user_id, a.serial_number,
                           COALESCE(a.custodian_dept_id, _u.department_id) AS effective_dept_id
                    FROM assets a
                    LEFT JOIN users _u ON _u.id = a.custodian_user_id
                ) eff
                LEFT JOIN departments d ON d.id = eff.effective_dept_id
                $where
            ");
            $stmt->execute($params);
            $user_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Apply search if needed (already done in SQL; safety net for partial match)
            if ($f_search !== '') {
                $user_assets = array_filter($user_assets, function($a) use ($f_search) {
                    $haystack = ($a['tag_number'] ?? '') . ' ' . ($a['description'] ?? '') . ' ' . ($a['manufacturer_name'] ?? '') . ' ' . ($a['model_number'] ?? '') . ' ' . ($a['serial_number'] ?? '');
                    return stripos($haystack, $f_search) !== false;
                });
            }
        } else {
            // الموظف خارج نطاق المستخدم الحالي — نخفي التفاصيل بالكامل
            $user_assets = [];
        }
    }
}

/* === Detail Report Export === */
if ($print_mode) {
    $t = $rtl ? $page_title : $page_title;
    report_print_head($t, '', ['التاريخ'=>date('Y-m-d'),'المستخدم'=>user_name()?:'-','المستشفى'=>get_setting('hospital_name','PMSH')]);
    echo '<p style="text-align:center;color:#64748b;padding:14px">'.htmlspecialchars($rtl?'هذا التقرير يستخدم جداول تفاعلية. للاطلاع على البيانات افتح الصفحة في النظام.':'This report uses interactive tables.').'</p>';
    report_print_foot();
}

if ($print_charts) {
    $t = $rtl ? $page_title : $page_title;
    report_print_charts_head($t, []);
    echo '<div class="pc-section"><p style="text-align:center;color:#64748b;padding:14px">'.htmlspecialchars($rtl?'لا توجد رسوم بيانية في هذا التقرير.':'No charts in this report.').'</p></div>';
    report_print_charts_foot();
}

if ($excel_mode) {
    $rows = [];
    report_export_excel('report_'.date('Y-m-d').'.csv', ['Item','Value'], $rows, $page_title);
}?>
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
.be-wrap { max-width: 1400px; margin: 0 auto; padding: 14px; }
.be-back { font-size: 12.5px; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 10px; font-weight: 600; }
.be-back:hover { color: #7c3aed; }
.be-hero {
  background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 50%, #a78bfa 100%);
  color: #fff;
  border-radius: 18px;
  padding: 24px 28px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 18px;
  box-shadow: 0 10px 30px rgba(124,58,237,.25);
  position: relative; overflow: hidden;
}
.be-hero::before { content: ''; position: absolute; top: -40px; right: -40px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(255,255,255,.10), transparent 70%); border-radius: 50%; }
.be-hero-ico { width: 60px; height: 60px; border-radius: 14px; background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.30); display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; }
.be-hero h2 { margin: 0; font-size: 20px; font-weight: 800; }
.be-hero p { margin: 4px 0 0; font-size: 13px; opacity: .88; line-height: 1.6; }

.be-grand { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.be-grand-stat { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
.be-grand-stat-ico { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.be-grand-stat-num { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1; }
.be-grand-stat-lbl { font-size: 11.5px; color: #64748b; margin-top: 3px; font-weight: 600; }

.be-emp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 24px; }
.be-emp-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 14px 16px;
  text-decoration: none; color: inherit;
  display: flex; align-items: center; gap: 12px;
  transition: all 0.2s ease;
  position: relative;
  overflow: hidden;
}
.be-emp-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(124,58,237,.12); border-color: #a78bfa; }
.be-emp-card.selected { border-color: #7c3aed; background: #faf5ff; box-shadow: 0 0 0 3px rgba(124,58,237,.10); }
.be-emp-avatar { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #7c3aed, #a78bfa); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; flex-shrink: 0; }
.be-emp-info { flex: 1; min-width: 0; }
.be-emp-name { font-size: 13.5px; font-weight: 700; color: #0f172a; }
.be-emp-dept { font-size: 11px; color: #64748b; margin-top: 2px; }
.be-emp-meta { display: flex; gap: 6px; margin-top: 6px; flex-wrap: wrap; }
.be-emp-pill { background: #f3e8ff; color: #7c3aed; padding: 2px 7px; border-radius: 5px; font-size: 10.5px; font-weight: 700; }
.be-emp-pill.crit-A { background: #fef2f2; color: #dc2626; }
.be-emp-card .be-emp-arrow { position: absolute; top: 14px; inset-inline-end: 14px; color: #94a3b8; font-size: 14px; }

.be-detail { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
.be-detail-h { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.be-detail-h i { color: #7c3aed; }
.be-detail-user { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; padding-bottom: 14px; border-bottom: 1.5px solid #f1f5f9; }
.be-detail-user-avatar { width: 64px; height: 64px; border-radius: 14px; background: linear-gradient(135deg, #7c3aed, #a78bfa); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 800; flex-shrink: 0; }
.be-detail-user-name { font-size: 18px; font-weight: 800; color: #0f172a; }
.be-detail-user-dept { font-size: 12.5px; color: #64748b; margin-top: 4px; }
.be-detail-user-stats { display: flex; gap: 12px; margin-top: 8px; }

.be-fltbar { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px; display: grid; grid-template-columns: 2fr auto; gap: 8px; align-items: end; }
.be-fltbar label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px; }
.be-fltbar input { padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 7px; font-size: 13px; background: #fff; width: 100%; font-family: inherit; }
.be-fltbar button { padding: 8px 16px; border-radius: 7px; border: none; background: #7c3aed; color: #fff; font-weight: 600; cursor: pointer; font-size: 13px; }
.be-fltbar a { background: #f1f5f9; color: #475569; padding: 8px 12px; border-radius: 7px; font-weight: 600; font-size: 13px; text-decoration: none; }

.be-tbl { width: 100%; border-collapse: collapse; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; font-size: 13px; }
.be-tbl th { background: #f8fafc; padding: 9px 12px; font-weight: 700; color: #475569; font-size: 11.5px; text-align: right; border-bottom: 1.5px solid #e2e8f0; }
.be-tbl td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; }
.be-tbl tr:hover { background: #fafbfc; }
.be-tbl .tag { font-family: monospace; font-size: 12px; color: #7c3aed; background: #f3e8ff; padding: 2px 7px; border-radius: 4px; display: inline-block; font-weight: 700; }
.be-crit { display: inline-flex; padding: 2px 8px; border-radius: 5px; font-size: 11px; font-weight: 800; letter-spacing: .5px; }
.be-crit.A { background: #fef2f2; color: #dc2626; }
.be-crit.B { background: #fef3c7; color: #d97706; }
.be-crit.C { background: #ecfeff; color: #0891b2; }

.be-empty { text-align: center; padding: 60px 16px; color: #94a3b8; background: #fff; border-radius: 12px; border: 1.5px solid #e2e8f0; }
.be-empty i { font-size: 48px; display: block; margin-bottom: 12px; color: #cbd5e1; }

@media (max-width: 1100px) { .be-grand { grid-template-columns: 1fr; } }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="be-wrap">

  <a href="<?= BASE_URL ?>/reports/custody/index.php" class="be-back">
    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i>
    <?= $rtl?'العودة إلى مركز تقارير العهدة':'Back to Custody Reports Hub' ?>
  </a>

  <div class="be-hero">
    <div class="be-hero-ico"><i class="fa-solid fa-user-tie"></i></div>
    <div style="flex:1">
      <h2><?= $rtl?'العهد حسب الموظف':'Custody by Employee' ?></h2>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.custody.by_employee') ?>
            </div>
      <p><?= $rtl?'كل موظف نشط + الأصول اللي تحت عهدته + إحصائيات (العدد/القيمة/الحساسية) + تاريخ أقدم وأحدث عهدة. لاختيار موظف اضغط على بطاقته لعرض التفاصيل.':'Each active employee + assets under their custody + stats (count/value/criticality) + oldest/newest custody date. Click a card to see details.' ?></p>
    </div>
  </div>

  <div class="be-grand">
    <div class="be-grand-stat">
      <div class="be-grand-stat-ico" style="background:#f3e8ff;color:#7c3aed"><i class="fa-solid fa-users"></i></div>
      <div><div class="be-grand-stat-num"><?= count($emp_stats) ?></div><div class="be-grand-stat-lbl"><?= $rtl?'موظفين عندهم عهدة':'Employees w/ Custody' ?></div></div>
    </div>
    <div class="be-grand-stat">
      <div class="be-grand-stat-ico" style="background:#ccfbf1;color:#0d9488"><i class="fa-solid fa-handshake"></i></div>
      <div><div class="be-grand-stat-num"><?= number_format($grand_total_emp_assets) ?></div><div class="be-grand-stat-lbl"><?= $rtl?'إجمالي الأصول':'Total Assets' ?></div></div>
    </div>
    <div class="be-grand-stat">
      <div class="be-grand-stat-ico" style="background:#d1fae5;color:#059669"><i class="fa-solid fa-coins"></i></div>
      <div><div class="be-grand-stat-num"><?= number_format($grand_total_emp_cost, 0) ?></div><div class="be-grand-stat-lbl"><?= $rtl?'قيمة إجمالية (ر.س)':'Total Value (SAR)' ?></div></div>
    </div>
  </div>

  <h3 class="be-detail-h" style="margin-top:20px"><i class="fa-solid fa-user-tie"></i> <?= $rtl?'ملخص حسب الموظف':'Summary by Employee' ?></h3>

  <?php if (empty($emp_stats)): ?>
    <div class="be-empty">
      <i class="fa-solid fa-users-slash"></i>
      <h3><?= $rtl?'لا يوجد موظفين عندهم عهدة':'No employees have custody' ?></h3>
      <p><?= $rtl?'ابدأ بنقل بعض الأصول من "نقل العهد" في تبويب "دورة الأصل".':'Start by transferring some assets from "Custody Transfer" in the "Asset Cycle" tab.' ?></p>
    </div>
  <?php else: ?>
  <div class="be-emp-grid">
    <?php foreach ($emp_stats as $e):
      $is_selected = ($f_user === (int)$e['id']);
      $initial = mb_substr($e['full_name'], 0, 1);
    ?>
    <a href="?user=<?= (int)$e['id'] ?>" class="be-emp-card <?= $is_selected?'selected':'' ?>">
      <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?> be-emp-arrow"></i>
      <div class="be-emp-avatar"><?= e($initial) ?></div>
      <div class="be-emp-info">
        <div class="be-emp-name"><?= e($e['full_name']) ?></div>
        <div class="be-emp-dept"><?= e($e['dept_name'] ?: '—') ?></div>
        <div class="be-emp-meta">
          <span class="be-emp-pill"><i class="fa-solid fa-handshake"></i> <?= (int)$e['asset_count'] ?> <?= $rtl?'أصل':'assets' ?></span>
          <?php if ($e['crit_A'] > 0): ?><span class="be-emp-pill crit-A">A: <?= (int)$e['crit_A'] ?></span><?php endif; ?>
          <span class="be-emp-pill" style="background:#fef3c7;color:#d97706"><?= number_format($e['total_cost'], 0) ?> SAR</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($selected_user): ?>
  <div class="be-detail">
    <div class="be-detail-user">
      <div class="be-detail-user-avatar"><?= e(mb_substr($selected_user['full_name'], 0, 1)) ?></div>
      <div style="flex:1">
        <div class="be-detail-user-name"><?= e($selected_user['full_name']) ?> <?php if ($selected_user['username']): ?><span style="color:#94a3b8;font-size:13px;font-weight:500">@<?= e($selected_user['username']) ?></span><?php endif; ?></div>
        <div class="be-detail-user-dept"><i class="fa-solid fa-building" style="color:#94a3b8"></i> <?= e($selected_user['dept_name'] ?: '—') ?> <?php if ($selected_user['email']): ?><span style="color:#94a3b8;margin-inline-start:8px">· <?= e($selected_user['email']) ?></span><?php endif; ?></div>
      </div>
      <a href="?user=<?= (int)$selected_user['id'] ?>" style="font-size:11px;color:#94a3b8;text-decoration:none"><i class="fa-solid fa-xmark"></i> <?= $rtl?'إغلاق':'Close' ?></a>
    </div>

    <form method="get" class="be-fltbar">
      <input type="hidden" name="user" value="<?= (int)$selected_user['id'] ?>">
      <div>
        <label><i class="fa-solid fa-magnifying-glass"></i> <?= $rtl?'بحث في أصول الموظف':'Search in emp. assets' ?></label>
        <input type="text" name="q" value="<?= e($f_search) ?>" placeholder="<?= $rtl?'تاج / اسم / مصنع / موديل / سيريال':'Tag / Name / Mfr / Model / Serial' ?>">
      </div>
      <div style="display:flex;gap:6px">
        <button type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
        <a href="?user=<?= (int)$selected_user['id'] ?>"><?= $rtl?'مسح':'Reset' ?></a>
      </div>
    </form>

    <?php if (empty($user_assets)): ?>
      <div class="be-empty">
        <i class="fa-solid fa-circle-check"></i>
        <h3><?= $rtl?'لا توجد أصول لهذا الموظف':'No assets for this employee' ?></h3>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="be-tbl">
      <thead><tr>
        <th>#</th>
        <th><?= $rtl?'الحساسية':'Crit' ?></th>
        <th><?= $rtl?'التاج':'Tag' ?></th>
        <th><?= $rtl?'الاسم':'Name' ?></th>
        <th><?= $rtl?'المصنع / الموديل':'Mfr / Model' ?></th>
        <th><?= $rtl?'النوع':'Type' ?></th>
        <th><?= $rtl?'الموقع':'Location' ?></th>
        <th><?= $rtl?'تاريخ العهدة':'Custody' ?></th>
        <th><?= $rtl?'الضمان':'Warranty' ?></th>
        <th><?= $rtl?'القيمة':'Value' ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($user_assets as $i => $r):
        $crit = $r['criticality_class'] ?: 'C';
        $warranty_warn = '';
        if ($r['warranty_expiry']) {
          $days_left = (strtotime($r['warranty_expiry']) - time()) / 86400;
          if ($days_left < 0) $warranty_warn = 'expired';
          elseif ($days_left < 30) $warranty_warn = 'soon';
        }
      ?>
        <tr>
          <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
          <td><span class="be-crit <?= e($crit) ?>"><?= e($crit) ?></span></td>
          <td><span class="tag"><?= e($r['tag_number'] ?: '—') ?></span></td>
          <td>
            <div style="font-weight:600;color:#0f172a"><?= e(truncate($r['description'] ?? '', 38)) ?></div>
            <?php if ($r['description_ar']): ?>
              <div style="font-size:11.5px;color:#475569;direction:rtl"><?= e(truncate($r['description_ar'], 38)) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:12px">
            <?= e($r['manufacturer_name'] ?: '—') ?>
            <?php if ($r['model_number']): ?><div style="color:#64748b"><?= e($r['model_number']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:#64748b"><?= e($r['asset_type']) ?></td>
          <td style="font-size:11.5px;color:#475569">
            <?= e($r['loc_building']) ?>
            <?php if ($r['loc_floor']): ?>/ <?= e($r['loc_floor']) ?><?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:#64748b"><?= $r['custody_date'] ? date('Y-m-d', strtotime($r['custody_date'])) : '—' ?></td>
          <td style="font-size:11.5px">
            <?php if ($r['warranty_expiry']): ?>
              <div style="color:<?= $warranty_warn==='expired'?'#dc2626':($warranty_warn==='soon'?'#d97706':'#64748b') ?>">
                <?= date('Y-m-d', strtotime($r['warranty_expiry'])) ?>
              </div>
              <?php if ($warranty_warn==='expired'): ?><div style="color:#dc2626;font-size:10.5px"><i class="fa-solid fa-circle-exclamation"></i> <?= $rtl?'منتهي':'Expired' ?></div>
              <?php elseif ($warranty_warn==='soon'): ?><div style="color:#d97706;font-size:10.5px"><i class="fa-solid fa-clock"></i> <?= $rtl?'قارب ينتهي':'Expiring' ?></div>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:#94a3b8">—</span>
            <?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12.5px;color:#0f172a;font-weight:700"><?= $r['cost'] ? number_format($r['cost'], 0) . ' <span style="color:#94a3b8;font-size:10.5px">SAR</span>' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /.be-wrap -->
</main>
</div><!-- /.main-area -->
</body>
</html>
