<?php
/**
 * reports/custody/custody_log.php — سجل نقل العهدة (Custody Transfer Log)
 * ──────────────────────────────────────────────────────────────────
 *   • تاريخ كامل لكل عمليات نقل العهدة (من → إلى) + السبب + رقم القرار + المُنفّذ
 *   • فلاتر: تاريخ، قسم، موظف، أصل
 *   • استعراضي بحت
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.custody.custody_log');

$can_export  = can('reports.custody.custody_log', 'export');
$excel_mode  = report_excel_mode_active('reports.custody.custody_log');
$print_mode  = report_print_mode_active('reports.custody.custody_log');
$print_charts = report_print_charts_mode_active('reports.custody.custody_log');

$rtl = is_rtl();
$active_nav = 'reports.custody';
$page_title = $rtl ? 'سجل نقل العهدة' : 'Custody Transfer Log';

// ═══ فلاتر ═══
$f_from    = $_GET['from'] ?? '';
$f_to      = $_GET['to'] ?? '';
$f_dept    = (int)($_GET['dept'] ?? 0);
$f_user    = (int)($_GET['user'] ?? 0);
$f_search  = trim($_GET['q'] ?? '');

// ═══ فلترة حسب قسم المستخدم (لسجل النقل: حسب to_dept_id) ═══
$my_dept_id = 0;
$is_see_all = can_see_all();
if (!$is_see_all) {
    $my_dept_id = (int)(current_user()['department_id'] ?? 0);
}

// ═══ الإحصائيات ═══
$log_stats_sql = "SELECT COUNT(*) AS total, COUNT(DISTINCT asset_id) AS unique_assets,
    COUNT(DISTINCT to_dept_id) AS unique_depts, COUNT(DISTINCT created_by) AS unique_creators,
    MIN(custody_date) AS first_date, MAX(custody_date) AS last_date
    FROM asset_custody_log" . (!$is_see_all && $my_dept_id ? " WHERE to_dept_id = " . (int)$my_dept_id : "");
$log_stats = $pdo->query($log_stats_sql)->fetch(PDO::FETCH_ASSOC);

// ═══ الأقسام + الموظفون للفلاتر ═══
$depts_sql = "SELECT id, name FROM departments
    WHERE id IN (SELECT DISTINCT to_dept_id FROM asset_custody_log WHERE to_dept_id IS NOT NULL)"
    . (!$is_see_all && $my_dept_id ? " AND id = " . (int)$my_dept_id : "")
    . " ORDER BY name";
$depts = $pdo->query($depts_sql)->fetchAll(PDO::FETCH_ASSOC);

$users_in_log = $pdo->query("
    SELECT u.id, u.full_name, u.username
    FROM users u
    WHERE u.id IN (
        SELECT to_user_id FROM asset_custody_log WHERE to_user_id IS NOT NULL
        UNION
        SELECT from_user_id FROM asset_custody_log WHERE from_user_id IS NOT NULL
        UNION
        SELECT created_by FROM asset_custody_log WHERE created_by IS NOT NULL
    )
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

// ═══ القائمة ═══
$where = "WHERE 1=1";
$params = [];

// فلترة حسب قسم المستخدم
if (!$is_see_all && $my_dept_id) {
    $where .= " AND acl.to_dept_id = ?";
    $params[] = $my_dept_id;
}

if ($f_from) { $where .= " AND acl.custody_date >= ?"; $params[] = $f_from; }
if ($f_to)   { $where .= " AND acl.custody_date <= ?"; $params[] = $f_to; }
if ($f_dept) { $where .= " AND (acl.from_dept_id = ? OR acl.to_dept_id = ?)"; array_push($params, $f_dept, $f_dept); }
if ($f_user) { $where .= " AND (acl.from_user_id = ? OR acl.to_user_id = ? OR acl.created_by = ?)"; array_push($params, $f_user, $f_user, $f_user); }
if ($f_search !== '') {
    $where .= " AND (a.tag_number LIKE ? OR a.description LIKE ? OR acl.reason LIKE ? OR acl.decision_ref LIKE ?)";
    $like = '%' . $f_search . '%';
    array_push($params, $like, $like, $like, $like);
}

$rows = $pdo->prepare("
    SELECT acl.*,
           a.tag_number, a.description, a.criticality_class, a.asset_type,
           u_to.full_name AS to_user_name,
           d_to.name AS to_dept_name,
           u_from.full_name AS from_user_name,
           d_from.name AS from_dept_name,
           u_cb.full_name AS created_by_name
    FROM asset_custody_log acl
    LEFT JOIN assets a ON a.id = acl.asset_id
    LEFT JOIN users u_to ON u_to.id = acl.to_user_id
    LEFT JOIN departments d_to ON d_to.id = acl.to_dept_id
    LEFT JOIN users u_from ON u_from.id = acl.from_user_id
    LEFT JOIN departments d_from ON d_from.id = acl.from_dept_id
    LEFT JOIN users u_cb ON u_cb.id = acl.created_by
    $where
    ORDER BY acl.id DESC
    LIMIT 500
");
$rows->execute($params);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

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
.cl-wrap { max-width: 1500px; margin: 0 auto; padding: 14px; }
.cl-back { font-size: 12.5px; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 10px; font-weight: 600; }
.cl-back:hover { color: #475569; }
.cl-hero {
  background: linear-gradient(135deg, #1e293b 0%, #475569 50%, #94a3b8 100%);
  color: #fff;
  border-radius: 18px;
  padding: 24px 28px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 18px;
  box-shadow: 0 10px 30px rgba(71,85,105,.25);
  position: relative; overflow: hidden;
}
.cl-hero::before { content: ''; position: absolute; top: -40px; right: -40px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(255,255,255,.10), transparent 70%); border-radius: 50%; }
.cl-hero-ico { width: 60px; height: 60px; border-radius: 14px; background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.30); display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; }
.cl-hero h2 { margin: 0; font-size: 20px; font-weight: 800; }
.cl-hero p { margin: 4px 0 0; font-size: 13px; opacity: .88; line-height: 1.6; }

.cl-grand { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.cl-grand-stat { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
.cl-grand-stat-ico { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.cl-grand-stat-num { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1; }
.cl-grand-stat-lbl { font-size: 11.5px; color: #64748b; margin-top: 3px; font-weight: 600; }

.cl-fltbar { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px; display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr auto; gap: 8px; align-items: end; }
.cl-fltbar label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px; }
.cl-fltbar input, .cl-fltbar select { padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 7px; font-size: 13px; background: #fff; width: 100%; font-family: inherit; }
.cl-fltbar button { padding: 8px 16px; border-radius: 7px; border: none; background: #475569; color: #fff; font-weight: 600; cursor: pointer; font-size: 13px; }
.cl-fltbar a { background: #f1f5f9; color: #475569; padding: 8px 12px; border-radius: 7px; font-weight: 600; font-size: 13px; text-decoration: none; text-align: center; }

.cl-tbl { width: 100%; border-collapse: collapse; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; font-size: 13px; }
.cl-tbl th { background: #f8fafc; padding: 9px 12px; font-weight: 700; color: #475569; font-size: 11.5px; text-align: right; border-bottom: 1.5px solid #e2e8f0; white-space: nowrap; }
.cl-tbl td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.cl-tbl tr:hover { background: #fafbfc; }
.cl-tbl .tag { font-family: monospace; font-size: 12px; color: #475569; background: #f1f5f9; padding: 2px 7px; border-radius: 4px; display: inline-block; font-weight: 700; }
.cl-crit { display: inline-flex; padding: 2px 8px; border-radius: 5px; font-size: 11px; font-weight: 800; letter-spacing: .5px; }
.cl-crit.A { background: #fef2f2; color: #dc2626; }
.cl-crit.B { background: #fef3c7; color: #d97706; }
.cl-crit.C { background: #ecfeff; color: #0891b2; }
.cl-flow { display: inline-flex; align-items: center; gap: 4px; }
.cl-flow .arrow { color: #94a3b8; font-size: 12px; }
.cl-flow .from { color: #64748b; font-size: 12px; }
.cl-flow .to { color: #475569; font-size: 12.5px; font-weight: 700; }

.cl-empty { text-align: center; padding: 60px 16px; color: #94a3b8; background: #fff; border-radius: 12px; border: 1.5px solid #e2e8f0; }
.cl-empty i { font-size: 48px; display: block; margin-bottom: 12px; color: #16a34a; }

@media (max-width: 1100px) { .cl-grand { grid-template-columns: 1fr; } .cl-fltbar { grid-template-columns: 1fr 1fr; } }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="cl-wrap">

  <a href="<?= BASE_URL ?>/reports/custody/index.php" class="cl-back">
    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i>
    <?= $rtl?'العودة إلى مركز تقارير العهدة':'Back to Custody Reports Hub' ?>
  </a>

  <div class="cl-hero">
    <div class="cl-hero-ico"><i class="fa-solid fa-clock-rotate-left"></i></div>
    <div style="flex:1">
      <h2><?= $rtl?'سجل نقل العهدة':'Custody Transfer Log' ?></h2>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.custody.custody_log') ?>
            </div>
      <p><?= $rtl?'تاريخ كامل لكل عمليات نقل العهدة — من → إلى، السبب، رقم القرار، المُنفّذ. للتراجع/الإلغاء اتصل بمدير النظام (هذا التقرير للعرض فقط).':'Full history of every custody transfer — from → to, reason, decision ref, executor. To reverse/cancel, contact system admin (this is a display-only report).' ?></p>
    </div>
  </div>

  <div class="cl-grand">
    <div class="cl-grand-stat">
      <div class="cl-grand-stat-ico" style="background:#f1f5f9;color:#475569"><i class="fa-solid fa-clock-rotate-left"></i></div>
      <div><div class="cl-grand-stat-num"><?= number_format($log_stats['total'] ?? 0) ?></div><div class="cl-grand-stat-lbl"><?= $rtl?'إجمالي العمليات':'Total Transfers' ?></div></div>
    </div>
    <div class="cl-grand-stat">
      <div class="cl-grand-stat-ico" style="background:#cffafe;color:#0891b2"><i class="fa-solid fa-boxes-stacked"></i></div>
      <div><div class="cl-grand-stat-num"><?= number_format($log_stats['unique_assets'] ?? 0) ?></div><div class="cl-grand-stat-lbl"><?= $rtl?'أصول فريدة':'Unique Assets' ?></div></div>
    </div>
    <div class="cl-grand-stat">
      <div class="cl-grand-stat-ico" style="background:#ccfbf1;color:#0d9488"><i class="fa-solid fa-building"></i></div>
      <div><div class="cl-grand-stat-num"><?= number_format($log_stats['unique_depts'] ?? 0) ?></div><div class="cl-grand-stat-lbl"><?= $rtl?'أقسام مستلمة':'Recipient Depts' ?></div></div>
    </div>
  </div>

  <form method="get" class="cl-fltbar">
    <div>
      <label><i class="fa-solid fa-magnifying-glass"></i> <?= $rtl?'بحث':'Search' ?></label>
      <input type="text" name="q" value="<?= e($f_search) ?>" placeholder="<?= $rtl?'تاج / اسم / سبب / قرار':'Tag / Name / Reason / Decision' ?>">
    </div>
    <div>
      <label><?= $rtl?'من تاريخ':'From date' ?></label>
      <input type="date" name="from" value="<?= e($f_from) ?>">
    </div>
    <div>
      <label><?= $rtl?'إلى تاريخ':'To date' ?></label>
      <input type="date" name="to" value="<?= e($f_to) ?>">
    </div>
    <div>
      <label><?= $rtl?'القسم':'Department' ?></label>
      <select name="dept">
        <option value=""><?= $rtl?'— الكل —':'— All —' ?></option>
        <?php foreach ($depts as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= $f_dept===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:6px">
      <button type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
      <a href="?"><?= $rtl?'مسح':'Reset' ?></a>
    </div>
  </form>

  <?php if (empty($rows)): ?>
    <div class="cl-empty">
      <i class="fa-solid fa-circle-check"></i>
      <h3><?= $rtl?'لا توجد عمليات نقل':'No transfers found' ?></h3>
      <p><?= $rtl?'لم يتم تسجيل أي عملية نقل عهدة بعد':'No custody transfers have been recorded yet' ?></p>
    </div>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="cl-tbl">
    <thead><tr>
      <th>#</th>
      <th><?= $rtl?'التاريخ':'Date' ?></th>
      <th><?= $rtl?'الأصل':'Asset' ?></th>
      <th><?= $rtl?'الحساسية':'Crit' ?></th>
      <th><?= $rtl?'من → إلى':'From → To' ?></th>
      <th><?= $rtl?'السبب':'Reason' ?></th>
      <th><?= $rtl?'رقم القرار':'Decision Ref' ?></th>
      <th><?= $rtl?'المُنفّذ':'Executor' ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r):
      $crit = $r['criticality_class'] ?: 'C';
      $from_str = $r['from_user_name'] ?: ($r['from_dept_name'] ?: ($r['from_type'] ?: '—'));
      $to_str   = $r['to_user_name']   ?: ($r['to_dept_name']   ?: '—');
    ?>
      <tr>
        <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
        <td style="font-size:12.5px;color:#0f172a;font-weight:600"><?= e($r['custody_date']) ?></td>
        <td>
          <span class="tag"><?= e($r['tag_number'] ?: '—') ?></span>
          <div style="font-size:11.5px;color:#475569;margin-top:3px"><?= e(truncate($r['description'] ?? '', 35)) ?></div>
        </td>
        <td><span class="cl-crit <?= e($crit) ?>"><?= e($crit) ?></span></td>
        <td>
          <div class="cl-flow">
            <span class="from"><?= e($from_str) ?></span>
            <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?> arrow"></i>
            <span class="to"><?= e($to_str) ?></span>
          </div>
        </td>
        <td style="font-size:12px;color:#475569"><?= e(truncate($r['reason'] ?? '—', 35)) ?></td>
        <td style="font-family:monospace;font-size:11.5px;color:#475569"><?= e($r['decision_ref'] ?: '—') ?></td>
        <td style="font-size:12px;color:#475569"><?= e($r['created_by_name'] ?: '—') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="margin-top:8px;font-size:11.5px;color:#64748b;text-align:center">
    <?= $rtl?'عرض آخر 500 عملية. الترتيب: الأحدث أولاً.':'Showing last 500. Sorted: newest first.' ?>
  </p>
  <?php endif; ?>
</div><!-- /.cl-wrap -->
</main>
</div><!-- /.main-area -->
</body>
</html>
