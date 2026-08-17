<?php
/**
 * reports/custody/by_department.php — تقرير العهدة حسب القسم
 * ──────────────────────────────────────────────────────────────────
 *   • كل قسم + عدد الأصول تحت عهدته + عدد المستلمين + القيمة الإجمالية
 *   • فلتر: قسم محدد (يعرض تفاصيله)
 *   • استعراضي بحت — لا أزرار إجراء
 *   • الإجراء (نقل/تعديل) في /assets/custody_transfer.php
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.custody.by_department');

$can_export  = can('reports.custody.by_department', 'export');
$excel_mode  = report_excel_mode_active('reports.custody.by_department');
$print_mode  = report_print_mode_active('reports.custody.by_department');
$print_charts = report_print_charts_mode_active('reports.custody.by_department');

$rtl = is_rtl();
$active_nav = 'reports.custody';
$page_title = $rtl ? 'العهد حسب القسم' : 'Custody by Department';

// ═══ فلاتر ═══
$f_dept   = (int)($_GET['dept'] ?? 0);
$f_search = trim($_GET['q'] ?? '');

// فلترة حسب قسم المستخدم
$scope = data_scope('custody', 'a');

// ═══ ملخص حسب القسم (دائماً) ═══
// نستخدم effective_dept_id = COALESCE(asset.custodian_dept_id, user.department_id)
// عشان نضمن إن الأصول اللي عندها مستخدم بس بدون dept_id تظهر في قسمه
$dept_stats_stmt = $pdo->prepare("
    SELECT
        d.id, d.name,
        COUNT(a.id) AS asset_count,
        COUNT(DISTINCT a.custodian_user_id) AS custodian_count,
        SUM(CASE WHEN a.criticality_class='A' THEN 1 ELSE 0 END) AS crit_A,
        SUM(CASE WHEN a.criticality_class='B' THEN 1 ELSE 0 END) AS crit_B,
        SUM(CASE WHEN a.criticality_class='C' THEN 1 ELSE 0 END) AS crit_C,
        COALESCE(SUM(a.cost), 0) AS total_cost
    FROM departments d
    INNER JOIN (
        SELECT a.id, a.custodian_user_id, a.custodian_dept_id, a.criticality_class, a.cost,
               COALESCE(a.custodian_dept_id, u.department_id) AS effective_dept_id
        FROM assets a
        LEFT JOIN users u ON u.id = a.custodian_user_id
        WHERE a.status='active' AND a.custodian_user_id IS NOT NULL AND " . $scope['where'] . "
    ) a ON a.effective_dept_id = d.id
    GROUP BY d.id, d.name
    HAVING asset_count > 0
    ORDER BY asset_count DESC, d.name
");
$dept_stats_stmt->execute($scope['params']);
$dept_stats = $dept_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

$grand_total_assets = array_sum(array_column($dept_stats, 'asset_count'));
$grand_total_custodians = 0;
$grand_total_cost = 0;
foreach ($dept_stats as $d) {
    $grand_total_custodians += (int)$d['custodian_count'];
    $grand_total_cost += (float)$d['total_cost'];
}

// ═══ التفاصيل لقسم محدد ═══
$selected_dept = null;
$dept_assets = [];
$dept_custodians = [];

if ($f_dept) {
    $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE id = ?");
    $stmt->execute([$f_dept]);
    $selected_dept = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selected_dept) {
        // حارس النطاق: لو المستخدم الحالي لا يرى كل العهدة، نتأكد أن
        // القسم المختار = قسمه قبل عرض التفاصيل.
        $can_view_dept = can_see_all_from_db();
        if (!$can_view_dept) {
            $my_dept = (int)(current_user()['department_id'] ?? 0);
            $can_view_dept = ($my_dept > 0 && $my_dept === (int)$f_dept);
        }
    }

    if ($selected_dept && $can_view_dept) {
        // الأصول — نستخدم effective_dept_id نفسه
        $where = "WHERE a.status='active' AND eff.dept_id = ? AND a.custodian_user_id IS NOT NULL";
        $params = [$f_dept];
        if ($f_search !== '') {
            $where .= " AND (a.tag_number LIKE ? OR a.description LIKE ? OR u.full_name LIKE ? OR a.manufacturer_name LIKE ?)";
            $like = '%' . $f_search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $stmt = $pdo->prepare("
            SELECT a.id, a.tag_number, a.description, a.description_ar, a.criticality_class,
                   a.asset_type, a.manufacturer_name, a.model_number, a.cost, a.custody_date,
                   a.loc_building, a.loc_floor, a.loc_room,
                   u.full_name AS custodian_name, u.username AS custodian_username
            FROM assets a
            LEFT JOIN users u ON u.id = a.custodian_user_id
            INNER JOIN (
                SELECT id, COALESCE(custodian_dept_id, (SELECT department_id FROM users WHERE id=custodian_user_id)) AS dept_id
                FROM assets
            ) eff ON eff.id = a.id
            $where
            ORDER BY a.criticality_class ASC, a.tag_number ASC
            LIMIT 500
        ");
        $stmt->execute($params);
        $dept_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // المستلمين (مجمعين) — نضم من user.department_id كـ fallback
        $stmt = $pdo->prepare("
            SELECT u.id, u.full_name, u.username,
                   COUNT(a.id) AS asset_count,
                   COALESCE(SUM(a.cost), 0) AS total_cost
            FROM users u
            INNER JOIN assets a ON a.custodian_user_id = u.id
            WHERE a.status='active'
              AND (a.custodian_dept_id = ? OR (a.custodian_dept_id IS NULL AND u.department_id = ?))
            GROUP BY u.id, u.full_name, u.username
            ORDER BY asset_count DESC
        ");
        $stmt->execute([$f_dept, $f_dept]);
        $dept_custodians = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
.bd-wrap { max-width: 1400px; margin: 0 auto; padding: 14px; }
.bd-back { font-size: 12.5px; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 10px; font-weight: 600; }
.bd-back:hover { color: #0d9488; }
.bd-hero {
  background: linear-gradient(135deg, #134e4a 0%, #0d9488 50%, #14b8a6 100%);
  color: #fff;
  border-radius: 18px;
  padding: 24px 28px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 18px;
  box-shadow: 0 10px 30px rgba(13,148,136,.25);
  position: relative; overflow: hidden;
}
.bd-hero::before { content: ''; position: absolute; top: -40px; right: -40px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(255,255,255,.10), transparent 70%); border-radius: 50%; }
.bd-hero-ico { width: 60px; height: 60px; border-radius: 14px; background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.30); display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; }
.bd-hero h2 { margin: 0; font-size: 20px; font-weight: 800; }
.bd-hero p { margin: 4px 0 0; font-size: 13px; opacity: .88; line-height: 1.6; }

.bd-grand { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.bd-grand-stat { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
.bd-grand-stat-ico { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.bd-grand-stat-num { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1; }
.bd-grand-stat-lbl { font-size: 11.5px; color: #64748b; margin-top: 3px; font-weight: 600; }

/* Department grid (cards) */
.bd-dept-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; margin-bottom: 24px; }
.bd-dept-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 14px 16px;
  text-decoration: none; color: inherit;
  display: flex; flex-direction: column; gap: 10px;
  transition: all 0.2s ease;
  position: relative;
  overflow: hidden;
}
.bd-dept-card:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(13,148,136,.12); border-color: #14b8a6; }
.bd-dept-card.selected { border-color: #0d9488; background: #f0fdfa; box-shadow: 0 0 0 3px rgba(13,148,136,.10); }
.bd-dept-card .bd-dept-name { font-size: 14px; font-weight: 800; color: #0f172a; }
.bd-dept-card .bd-dept-stats { display: flex; gap: 8px; flex-wrap: wrap; }
.bd-dept-pill { background: #f0fdfa; color: #0d9488; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; }
.bd-dept-pill.crit-A { background: #fef2f2; color: #dc2626; }
.bd-dept-pill.crit-B { background: #fef3c7; color: #d97706; }
.bd-dept-card .bd-dept-cost { font-size: 12px; color: #64748b; font-weight: 600; }
.bd-dept-card .bd-dept-arrow { position: absolute; top: 14px; inset-inline-end: 14px; color: #94a3b8; font-size: 14px; }

/* Detail view */
.bd-detail { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
.bd-detail-h { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.bd-detail-h i { color: #0d9488; }

.bd-custodians { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; margin-bottom: 16px; }
.bd-cust-card { background: #f0fdfa; border: 1.5px solid #99f6e4; border-radius: 10px; padding: 10px 12px; display: flex; align-items: center; gap: 10px; }
.bd-cust-avatar { width: 36px; height: 36px; border-radius: 8px; background: linear-gradient(135deg, #0d9488, #14b8a6); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; flex-shrink: 0; }
.bd-cust-info { flex: 1; min-width: 0; }
.bd-cust-name { font-size: 12.5px; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bd-cust-meta { font-size: 11px; color: #64748b; }

.bd-fltbar { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px; display: grid; grid-template-columns: 2fr auto; gap: 8px; align-items: end; }
.bd-fltbar label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px; }
.bd-fltbar input { padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 7px; font-size: 13px; background: #fff; width: 100%; font-family: inherit; }
.bd-fltbar button { padding: 8px 16px; border-radius: 7px; border: none; background: #0d9488; color: #fff; font-weight: 600; cursor: pointer; font-size: 13px; }
.bd-fltbar a { background: #f1f5f9; color: #475569; padding: 8px 12px; border-radius: 7px; font-weight: 600; font-size: 13px; text-decoration: none; }

.bd-tbl { width: 100%; border-collapse: collapse; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; font-size: 13px; }
.bd-tbl th { background: #f8fafc; padding: 9px 12px; font-weight: 700; color: #475569; font-size: 11.5px; text-align: right; border-bottom: 1.5px solid #e2e8f0; }
.bd-tbl td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; }
.bd-tbl tr:hover { background: #fafbfc; }
.bd-tbl .tag { font-family: monospace; font-size: 12px; color: #0d9488; background: #ccfbf1; padding: 2px 7px; border-radius: 4px; display: inline-block; font-weight: 700; }
.bd-crit { display: inline-flex; padding: 2px 8px; border-radius: 5px; font-size: 11px; font-weight: 800; letter-spacing: .5px; }
.bd-crit.A { background: #fef2f2; color: #dc2626; }
.bd-crit.B { background: #fef3c7; color: #d97706; }
.bd-crit.C { background: #ecfeff; color: #0891b2; }

.bd-empty { text-align: center; padding: 60px 16px; color: #94a3b8; background: #fff; border-radius: 12px; border: 1.5px solid #e2e8f0; }
.bd-empty i { font-size: 48px; display: block; margin-bottom: 12px; color: #cbd5e1; }

@media (max-width: 1100px) { .bd-grand { grid-template-columns: 1fr; } }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="bd-wrap">

  <a href="<?= BASE_URL ?>/reports/custody/index.php" class="bd-back">
    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i>
    <?= $rtl?'العودة إلى مركز تقارير العهدة':'Back to Custody Reports Hub' ?>
  </a>

  <div class="bd-hero">
    <div class="bd-hero-ico"><i class="fa-solid fa-building"></i></div>
    <div style="flex:1">
      <h2><?= $rtl?'العهد حسب القسم':'Custody by Department' ?></h2>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.custody.by_department') ?>
            </div>
      <p><?= $rtl?'تقرير استعراضي يوضح توزيع الأصول على الأقسام — اختر قسماً لعرض تفاصيله (الأصول + المستلمين). لتعديل العهدة استخدم "نقل العهد" في تبويب "دورة الأصل".':'Display report showing asset distribution by department — click a department to see its details (assets + custodians). To edit custody, use "Custody Transfer" in the "Asset Cycle" tab.' ?></p>
    </div>
  </div>

  <div class="bd-grand">
    <div class="bd-grand-stat">
      <div class="bd-grand-stat-ico" style="background:#cffafe;color:#0891b2"><i class="fa-solid fa-building"></i></div>
      <div><div class="bd-grand-stat-num"><?= count($dept_stats) ?></div><div class="bd-grand-stat-lbl"><?= $rtl?'أقسام لها عهدة':'Departments w/ Custody' ?></div></div>
    </div>
    <div class="bd-grand-stat">
      <div class="bd-grand-stat-ico" style="background:#ccfbf1;color:#0d9488"><i class="fa-solid fa-handshake"></i></div>
      <div><div class="bd-grand-stat-num"><?= number_format($grand_total_assets) ?></div><div class="bd-grand-stat-lbl"><?= $rtl?'إجمالي الأصول':'Total Assets' ?></div></div>
    </div>
    <div class="bd-grand-stat">
      <div class="bd-grand-stat-ico" style="background:#d1fae5;color:#059669"><i class="fa-solid fa-coins"></i></div>
      <div><div class="bd-grand-stat-num"><?= number_format($grand_total_cost, 0) ?></div><div class="bd-grand-stat-lbl"><?= $rtl?'قيمة إجمالية (ر.س)':'Total Value (SAR)' ?></div></div>
    </div>
  </div>

  <h3 class="bd-detail-h" style="margin-top:20px"><i class="fa-solid fa-sitemap"></i> <?= $rtl?'ملخص حسب القسم':'Summary by Department' ?></h3>

  <?php if (empty($dept_stats)): ?>
    <div class="bd-empty">
      <i class="fa-solid fa-building-circle-xmark"></i>
      <h3><?= $rtl?'لا توجد أقسام لها عهدة':'No departments have custody' ?></h3>
      <p><?= $rtl?'ابدأ بنقل بعض الأصول من "نقل العهد" في تبويب "دورة الأصل".':'Start by transferring some assets from "Custody Transfer" in the "Asset Cycle" tab.' ?></p>
    </div>
  <?php else: ?>
  <div class="bd-dept-grid">
    <?php foreach ($dept_stats as $d):
      $is_selected = ($f_dept === (int)$d['id']);
    ?>
    <a href="?dept=<?= (int)$d['id'] ?>" class="bd-dept-card <?= $is_selected?'selected':'' ?>">
      <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?> bd-dept-arrow"></i>
      <div class="bd-dept-name"><?= e($d['name']) ?></div>
      <div class="bd-dept-stats">
        <span class="bd-dept-pill"><i class="fa-solid fa-handshake"></i> <?= number_format($d['asset_count']) ?> <?= $rtl?'أصل':'assets' ?></span>
        <span class="bd-dept-pill"><i class="fa-solid fa-user-tie"></i> <?= number_format($d['custodian_count']) ?> <?= $rtl?'مستلم':'cust.' ?></span>
        <?php if ($d['crit_A'] > 0): ?><span class="bd-dept-pill crit-A">A: <?= (int)$d['crit_A'] ?></span><?php endif; ?>
        <?php if ($d['crit_B'] > 0): ?><span class="bd-dept-pill crit-B">B: <?= (int)$d['crit_B'] ?></span><?php endif; ?>
      </div>
      <div class="bd-dept-cost">
        <i class="fa-solid fa-coins" style="color:#94a3b8"></i>
        <?= number_format($d['total_cost'], 0) ?> <?= $rtl?'ر.س':'SAR' ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($selected_dept): ?>
  <div class="bd-detail">
    <h3 class="bd-detail-h">
      <i class="fa-solid fa-magnifying-glass"></i>
      <?= $rtl?"تفاصيل قسم: {$selected_dept['name']}":"Department Detail: {$selected_dept['name']}" ?>
      <a href="?dept=<?= (int)$selected_dept['id'] ?>" style="margin-inline-start:auto;font-size:11px;color:#94a3b8;text-decoration:none"><i class="fa-solid fa-xmark"></i> <?= $rtl?'إغلاق':'Close' ?></a>
    </h3>

    <?php if (!empty($dept_custodians)): ?>
    <h4 style="font-size:13px;font-weight:800;color:#0f172a;margin:0 0 10px"><i class="fa-solid fa-user-tie" style="color:#0d9488"></i> <?= $rtl?'المستلمين':'Custodians' ?> (<?= count($dept_custodians) ?>)</h4>
    <div class="bd-custodians">
      <?php foreach ($dept_custodians as $c):
        $initial = mb_substr($c['full_name'], 0, 1);
      ?>
      <div class="bd-cust-card">
        <div class="bd-cust-avatar"><?= e($initial) ?></div>
        <div class="bd-cust-info">
          <div class="bd-cust-name"><?= e($c['full_name']) ?></div>
          <div class="bd-cust-meta"><strong><?= (int)$c['asset_count'] ?></strong> <?= $rtl?'أصل · ':'assets · ' ?><?= number_format($c['total_cost'], 0) ?> SAR</div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="get" class="bd-fltbar">
      <input type="hidden" name="dept" value="<?= (int)$selected_dept['id'] ?>">
      <div>
        <label><i class="fa-solid fa-magnifying-glass"></i> <?= $rtl?'بحث في أصول القسم':'Search in dept. assets' ?></label>
        <input type="text" name="q" value="<?= e($f_search) ?>" placeholder="<?= $rtl?'تاج / اسم / مصنع / مستلم':'Tag / Name / Mfr / Custodian' ?>">
      </div>
      <div style="display:flex;gap:6px">
        <button type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
        <a href="?dept=<?= (int)$selected_dept['id'] ?>"><?= $rtl?'مسح':'Reset' ?></a>
      </div>
    </form>

    <?php if (empty($dept_assets)): ?>
      <div class="bd-empty">
        <i class="fa-solid fa-circle-check"></i>
        <h3><?= $rtl?'لا توجد أصول لهذا القسم':'No assets in this department' ?></h3>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="bd-tbl">
      <thead><tr>
        <th>#</th>
        <th><?= $rtl?'الحساسية':'Crit' ?></th>
        <th><?= $rtl?'التاج':'Tag' ?></th>
        <th><?= $rtl?'الاسم':'Name' ?></th>
        <th><?= $rtl?'المصنع / الموديل':'Mfr / Model' ?></th>
        <th><?= $rtl?'النوع':'Type' ?></th>
        <th><?= $rtl?'المستلم':'Custodian' ?></th>
        <th><?= $rtl?'الموقع':'Location' ?></th>
        <th><?= $rtl?'القيمة':'Value' ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($dept_assets as $i => $r):
        $crit = $r['criticality_class'] ?: 'C';
      ?>
        <tr>
          <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
          <td><span class="bd-crit <?= e($crit) ?>"><?= e($crit) ?></span></td>
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
          <td style="font-size:11.5px;color:#475569"><?= e($r['asset_type']) ?></td>
          <td style="font-size:12px">
            <div style="font-weight:600"><?= e($r['custodian_name'] ?: '—') ?></div>
            <?php if ($r['custodian_username']): ?>
              <div style="color:#94a3b8;font-size:10.5px;font-family:monospace">@<?= e($r['custodian_username']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:#64748b">
            <?= e($r['loc_building']) ?>
            <?php if ($r['loc_floor']): ?>/ <?= e($r['loc_floor']) ?><?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:12.5px;color:#0f172a;font-weight:700"><?= $r['cost'] ? number_format($r['cost'], 0) . ' <span style="color:#94a3b8;font-size:10.5px">SAR</span>' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p style="margin-top:8px;font-size:11.5px;color:#64748b;text-align:center">
      <?= $rtl?'عرض أول 500 أصل. الترتيب: A→B→C، ثم التاج.':'Showing first 500. Sorted: A→B→C, then tag.' ?>
    </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /.bd-wrap -->
</main>
</div><!-- /.main-area -->
</body>
</html>
