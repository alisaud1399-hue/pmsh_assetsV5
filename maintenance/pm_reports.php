<?php
/**
 * maintenance/pm_reports.php — تقارير الصيانة الدورية
 *
 * صفحة شاملة تجمع:
 *   - KPIs: إجمالي، داخلي/خارجي، مستعجل
 *   - 5 بطاقات حسب الـ schedule (overdue/7d/8-30d/31-90d/>90d)
 *   - bar chart حسب النوع (CSS gradients)
 *   - split visual داخلي vs خارجي + top 5 متعاقدين
 *   - top 5 أصول عندها أكثر PMs
 *   - جدول كامل (500 صف) + فلاتر + بحث
 *   - Print A4 + Export CSV
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('pm.schedules');

$rtl = is_rtl();
$BASE = BASE_URL;
$now = date('Y-m-d');

// Filters
$f_type = $_GET['type'] ?? '';
$f_exec = $_GET['exec'] ?? ''; // internal|external|all
$f_due = $_GET['due'] ?? '';   // overdue|7d|30d|90d|all
$f_q = trim($_GET['q'] ?? '');

// Build WHERE
$where = ['1=1'];
$params = [];
if ($f_type !== '') { $where[] = 'ps.pm_type = ?'; $params[] = $f_type; }
if ($f_exec === 'internal') { $where[] = 'ps.assigned_to_user_id IS NOT NULL'; }
elseif ($f_exec === 'external') { $where[] = 'ps.contractor_id IS NOT NULL'; }
elseif ($f_exec === 'pending') { $where[] = 'ps.assigned_to_user_id IS NULL AND ps.contractor_id IS NULL'; }
if ($f_due === 'overdue') { $where[] = 'ps.next_due < ?'; $params[] = $now; }
elseif ($f_due === '7d') { $where[] = 'ps.next_due BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)'; $params[] = $now; $params[] = $now; }
elseif ($f_due === '30d') { $where[] = 'ps.next_due BETWEEN ? AND DATE_ADD(?, INTERVAL 30 DAY)'; $params[] = $now; $params[] = $now; }
elseif ($f_due === '90d') { $where[] = 'ps.next_due BETWEEN ? AND DATE_ADD(?, INTERVAL 90 DAY)'; $params[] = $now; $params[] = $now; }
elseif ($f_due === 'future') { $where[] = 'ps.next_due > DATE_ADD(?, INTERVAL 90 DAY)'; $params[] = $now; }
if ($f_q !== '') { $where[] = '(a.tag_number LIKE ? OR a.description LIKE ? OR ps.notes LIKE ? OR u.full_name LIKE ? OR c.name LIKE ?)'; $params[] = "%$f_q%"; $params[] = "%$f_q%"; $params[] = "%$f_q%"; $params[] = "%$f_q%"; $params[] = "%$f_q%"; }
$where_sql = implode(' AND ', $where);

// KPIs
$total_pms = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1")->fetchColumn();
$internal_count = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND assigned_to_user_id IS NOT NULL")->fetchColumn();
$external_count = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND contractor_id IS NOT NULL")->fetchColumn();
$pending_count = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND assigned_to_user_id IS NULL AND contractor_id IS NULL")->fetchColumn();
$overdue = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due < '$now'")->fetchColumn();
$due_7d = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due BETWEEN '$now' AND DATE_ADD('$now', INTERVAL 7 DAY)")->fetchColumn();
$due_30d = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due BETWEEN DATE_ADD('$now', INTERVAL 8 DAY) AND DATE_ADD('$now', INTERVAL 30 DAY)")->fetchColumn();
$due_90d = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due BETWEEN DATE_ADD('$now', INTERVAL 31 DAY) AND DATE_ADD('$now', INTERVAL 90 DAY)")->fetchColumn();
$future = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due > DATE_ADD('$now', INTERVAL 90 DAY)")->fetchColumn();

// By type
$by_type = $pdo->query("SELECT pm_type, COUNT(*) AS cnt FROM pm_schedules WHERE is_active=1 GROUP BY pm_type ORDER BY cnt DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
$max_type = $by_type ? max(array_column($by_type, 'cnt')) : 1;

// Top 5 assets
$top_assets = $pdo->query("
    SELECT a.tag_number, a.description, COUNT(*) AS cnt
    FROM pm_schedules ps
    INNER JOIN assets a ON a.id = ps.asset_id
    WHERE ps.is_active=1
    GROUP BY a.id
    ORDER BY cnt DESC, a.tag_number
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Top 5 contractors
$top_contractors = $pdo->query("
    SELECT c.name, COUNT(*) AS cnt
    FROM pm_schedules ps
    INNER JOIN committees c ON c.id = ps.contractor_id
    WHERE ps.is_active=1 AND ps.contractor_id IS NOT NULL
    GROUP BY c.id
    ORDER BY cnt DESC, c.name
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Main table query
$sql = "SELECT ps.id, ps.asset_id, ps.pm_type, ps.next_due, ps.cycle_days,
               ps.is_external, ps.contractor_id, ps.assigned_to_user_id, ps.notes, ps.created_at,
               a.tag_number, a.description AS asset_desc,
               u.full_name AS user_name,
               c.name AS contractor_name
        FROM pm_schedules ps
        LEFT JOIN assets a ON a.id = ps.asset_id
        LEFT JOIN users u ON u.id = ps.assigned_to_user_id
        LEFT JOIN committees c ON c.id = ps.contractor_id
        WHERE $where_sql
        ORDER BY ps.next_due ASC
        LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=pm_reports_' . date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    // BOM for Arabic in Excel
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['PM ID', 'تاج', 'الجهاز', 'النوع', 'المنفذ', 'المتعاقد', 'الموعد القادم', 'الأيام المتبقية', 'ملاحظات']);
    foreach ($pms as $p) {
        $days = (strtotime($p['next_due']) - strtotime($now)) / 86400;
        fputcsv($out, [
            $p['id'],
            $p['tag_number'] ?? '—',
            $p['asset_desc'] ?? '—',
            $p['pm_type'],
            $p['user_name'] ?? ($p['is_external'] ? '—' : '—'),
            $p['contractor_name'] ?? '—',
            $p['next_due'],
            $days < 0 ? 'منتهي ' . abs((int)$days) . ' يوم' : (int)$days . ' يوم',
            $p['notes'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$page_title = 'تقارير الصيانة الدورية';
$active_nav = 'pm.reports';
$breadcrumb = [['name' => $page_title]];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root {
  --ind-500: #6366f1; --ind-600: #4f46e5; --ind-700: #4338ca;
  --violet-500: #8b5cf6; --violet-600: #7c3aed;
  --pink-500: #ec4899;
  --emerald: #10b981; --emerald-d: #059669;
  --amber-500: #f59e0b; --amber-600: #d97706;
  --rose-500: #f43f5e; --rose-600: #e11d48;
  --cyan-500: #06b6d4; --sky-500: #0ea5e9;
  --slate-50: #f8fafc; --slate-100: #f1f5f9; --slate-200: #e2e8f0;
  --slate-300: #cbd5e1; --slate-400: #94a3b8; --slate-500: #64748b;
  --slate-600: #475569; --slate-700: #334155; --slate-800: #1e293b; --slate-900: #0f172a;
  --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06);
  --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.06);
  --shadow-lg: 0 10px 25px -5px rgba(99, 102, 241, 0.12);
  --shadow-xl: 0 25px 50px -12px rgba(99, 102, 241, 0.18);
}
* { font-family: 'Tajawal', -apple-system, sans-serif !important; box-sizing: border-box; }
html, body {
  background:
    radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.06) 0px, transparent 50%),
    radial-gradient(at 100% 0%, rgba(6, 182, 212, 0.05) 0px, transparent 50%),
    radial-gradient(at 50% 100%, rgba(139, 92, 246, 0.05) 0px, transparent 50%),
    linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  min-height: 100vh;
}
.pmq-wrap { max-width: 1320px; margin: 0 auto; }

/* ═══ Hero ═══ */
.pr-hero {
  background: linear-gradient(135deg, #0891b2 0%, #6366f1 50%, #8b5cf6 100%);
  background-size: 200% 200%;
  animation: heroShift 8s ease-in-out infinite;
  border-radius: 20px;
  padding: 16px 22px;
  color: #fff;
  position: relative; overflow: hidden;
  box-shadow: var(--shadow-xl);
  margin-bottom: 14px;
  border: 1px solid rgba(255, 255, 255, 0.15);
}
@keyframes heroShift { 0%, 100% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } }
.pr-hero::before {
  content: ''; position: absolute; inset: 0;
  background:
    radial-gradient(circle at 15% 50%, rgba(255,255,255,0.22) 0%, transparent 40%),
    radial-gradient(circle at 85% 30%, rgba(255,255,255,0.15) 0%, transparent 35%);
  pointer-events: none;
}
.pr-hero-row { display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; flex-wrap: wrap; }
.pr-hero-ico {
  width: 48px; height: 48px; border-radius: 14px;
  background: linear-gradient(135deg, rgba(255,255,255,0.3), rgba(255,255,255,0.1));
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; flex-shrink: 0;
  box-shadow: 0 4px 14px rgba(0,0,0,0.15);
}
.pr-hero h1 { margin: 0; font-size: 19px; font-weight: 900; letter-spacing: -0.3px; }
.pr-hero p { margin: 2px 0 0; font-size: 11.5px; opacity: 0.92; line-height: 1.4; }
.pr-hero-stat {
  background: linear-gradient(135deg, rgba(255,255,255,0.25), rgba(255,255,255,0.1));
  padding: 6px 14px; border-radius: 12px;
  font-size: 11px; font-weight: 800;
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.3);
  display: flex; align-items: center; gap: 5px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.pr-hero-stat b { font-size: 15px; font-weight: 900; }

/* ═══ Section cards ═══ */
.pr-section {
  background: #fff; border-radius: 18px;
  padding: 16px 18px;
  box-shadow: var(--shadow-md);
  border: 1px solid var(--slate-200);
  margin-bottom: 14px;
  position: relative;
  overflow: hidden;
}
.pr-section-head {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 12px; padding-bottom: 10px;
  border-bottom: 1px solid var(--slate-100);
}
.pr-section-head h2 { margin: 0; font-size: 14px; font-weight: 900; color: var(--slate-800); }
.pr-section-head .badge {
  background: linear-gradient(135deg, var(--ind-500), var(--violet-500));
  color: #fff; padding: 3px 10px; border-radius: 99px;
  font-size: 10px; font-weight: 800;
  box-shadow: 0 2px 6px rgba(99, 102, 241, 0.3);
}

/* ═══ Schedule urgency grid (5 cards) ═══ */
.pr-urgency { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; }
@media (max-width: 900px) { .pr-urgency { grid-template-columns: repeat(2, 1fr); } }
.pr-u {
  border-radius: 14px;
  padding: 14px 12px;
  position: relative;
  overflow: hidden;
  color: #fff;
  cursor: pointer;
  transition: all 0.25s ease;
  border: 1px solid transparent;
}
.pr-u:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(0,0,0,0.15); }
.pr-u::before {
  content: ''; position: absolute;
  top: 0; right: 0; bottom: 0; left: 0;
  background: radial-gradient(circle at 80% 0%, rgba(255,255,255,0.2) 0%, transparent 50%);
  pointer-events: none;
}
.pr-u .lbl { font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px; opacity: 0.95; }
.pr-u .num { font-size: 30px; font-weight: 900; margin: 6px 0 2px; line-height: 1; }
.pr-u .sub { font-size: 10px; opacity: 0.85; }
.pr-u.red    { background: linear-gradient(135deg, #ef4444, #dc2626); }
.pr-u.orange { background: linear-gradient(135deg, #f97316, #ea580c); }
.pr-u.amber  { background: linear-gradient(135deg, #f59e0b, #d97706); }
.pr-u.sky    { background: linear-gradient(135deg, #0ea5e9, #0284c7); }
.pr-u.slate  { background: linear-gradient(135deg, #64748b, #475569); }
.pr-u.on { outline: 3px solid #fff; outline-offset: 2px; box-shadow: 0 0 0 5px rgba(99,102,241,0.4); }

/* ═══ Two-column layout ═══ */
.pr-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 900px) { .pr-cols { grid-template-columns: 1fr; } }

/* ═══ Bar chart (by type) ═══ */
.pr-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
.pr-bar .name { font-size: 11.5px; font-weight: 700; color: var(--slate-700); min-width: 110px; max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pr-bar .track { flex: 1; height: 18px; background: var(--slate-100); border-radius: 99px; overflow: hidden; position: relative; }
.pr-bar .fill {
  height: 100%;
  background: linear-gradient(90deg, var(--ind-500), var(--violet-500));
  border-radius: 99px;
  display: flex; align-items: center; justify-content: flex-end;
  padding: 0 8px;
  font-size: 10.5px; font-weight: 800; color: #fff;
  min-width: 28px;
  transition: width 0.6s ease;
  box-shadow: 0 2px 6px rgba(99, 102, 241, 0.2);
}
.pr-bar:nth-child(2) .fill { background: linear-gradient(90deg, #ec4899, #f43f5e); }
.pr-bar:nth-child(3) .fill { background: linear-gradient(90deg, #f59e0b, #ea580c); }
.pr-bar:nth-child(4) .fill { background: linear-gradient(90deg, #10b981, #059669); }
.pr-bar:nth-child(5) .fill { background: linear-gradient(90deg, #06b6d4, #0891b2); }
.pr-bar:nth-child(6) .fill { background: linear-gradient(90deg, #8b5cf6, #7c3aed); }
.pr-bar:nth-child(7) .fill { background: linear-gradient(90deg, #0ea5e9, #0284c7); }
.pr-bar:nth-child(8) .fill { background: linear-gradient(90deg, #64748b, #475569); }

/* ═══ Internal/External split ═══ */
.pr-split {
  display: flex; height: 60px;
  border-radius: 12px; overflow: hidden;
  margin-bottom: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.pr-split .seg {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  color: #fff; font-weight: 800;
  position: relative;
  transition: flex 0.5s ease;
}
.pr-split .seg .n { font-size: 22px; line-height: 1; }
.pr-split .seg .l { font-size: 10px; opacity: 0.9; margin-top: 3px; }
.pr-split .internal { background: linear-gradient(135deg, var(--ind-500), var(--ind-700)); }
.pr-split .external { background: linear-gradient(135deg, var(--amber-500), var(--amber-600)); }
.pr-split .pending  { background: linear-gradient(135deg, var(--slate-400), var(--slate-500)); }
.pr-split .seg:not(:last-child) { border-left: 2px solid rgba(255,255,255,0.4); }

.pr-top-list { display: flex; flex-direction: column; gap: 6px; }
.pr-top-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 10px;
  background: var(--slate-50);
  border: 1px solid var(--slate-100);
  transition: all 0.15s ease;
}
.pr-top-item:hover { background: #fff; border-color: var(--ind-500); transform: translateX(-3px); }
.pr-top-item .rank {
  width: 26px; height: 26px;
  border-radius: 8px;
  background: linear-gradient(135deg, var(--ind-500), var(--violet-500));
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 900;
  box-shadow: 0 2px 4px rgba(99, 102, 241, 0.3);
  flex-shrink: 0;
}
.pr-top-item .info { flex: 1; min-width: 0; }
.pr-top-item .name { font-size: 12px; font-weight: 800; color: var(--slate-800); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pr-top-item .meta { font-size: 10px; color: var(--slate-500); }
.pr-top-item .cnt {
  background: linear-gradient(135deg, var(--amber-500), var(--amber-600));
  color: #fff;
  padding: 3px 9px; border-radius: 99px;
  font-size: 10.5px; font-weight: 800;
  box-shadow: 0 2px 4px rgba(245, 158, 11, 0.3);
}

/* ═══ Filter bar ═══ */
.pr-filters {
  display: flex; gap: 8px; flex-wrap: wrap; align-items: center;
  margin-bottom: 10px;
}
.pr-filters input, .pr-filters select {
  padding: 8px 12px;
  border: 1.5px solid var(--slate-200);
  border-radius: 10px;
  font-size: 12px; font-weight: 700;
  background: #fff; color: var(--slate-800);
  font-family: inherit;
  transition: all 0.15s ease;
}
.pr-filters input:focus, .pr-filters select:focus {
  border-color: var(--ind-500);
  outline: none;
  box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
}
.pr-filters .q { flex: 1; min-width: 200px; }
.pr-filters .btn-clear {
  padding: 8px 14px; border-radius: 10px; border: none;
  background: var(--slate-100); color: var(--slate-700);
  font-weight: 800; font-size: 11.5px; cursor: pointer;
  display: inline-flex; align-items: center; gap: 5px;
  transition: all 0.15s ease; text-decoration: none;
}
.pr-filters .btn-clear:hover { background: var(--slate-200); }

/* ═══ Table ═══ */
.pr-tbl { overflow-x: auto; }
.pr-tbl table { width: 100%; border-collapse: collapse; font-size: 12px; }
.pr-tbl th {
  background: linear-gradient(135deg, var(--slate-50), #f1f5f9);
  color: var(--slate-600);
  font-size: 10.5px; font-weight: 800;
  text-align: start; padding: 10px 12px;
  border-bottom: 1.5px solid var(--slate-200);
  text-transform: uppercase;
  letter-spacing: 0.3px;
  position: sticky; top: 0;
}
.pr-tbl td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--slate-100);
  vertical-align: middle;
}
.pr-tbl tbody tr { transition: all 0.15s ease; }
.pr-tbl tbody tr:hover { background: linear-gradient(90deg, rgba(99,102,241,0.04), transparent); }
.pr-tbl .tag {
  font-family: 'Courier New', monospace;
  font-weight: 800; color: var(--ind-700);
  font-size: 11.5px;
}
.pr-tbl .urg-pill {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 3px 8px; border-radius: 99px;
  font-size: 10.5px; font-weight: 800;
}
.pr-tbl .urg-overdue  { background: #fef2f2; color: #dc2626; }
.pr-tbl .urg-7d      { background: #fff7ed; color: #ea580c; }
.pr-tbl .urg-30d     { background: #fef3c7; color: #d97706; }
.pr-tbl .urg-90d     { background: #dbeafe; color: #2563eb; }
.pr-tbl .urg-future  { background: var(--slate-100); color: var(--slate-600); }
.pr-tbl .exec-pill {
  display: inline-flex; align-items: center; gap: 3px;
  padding: 2px 7px; border-radius: 5px;
  font-size: 10px; font-weight: 800;
}
.pr-tbl .exec-int { background: var(--ind-50); color: var(--ind-700); }
.pr-tbl .exec-ext { background: #fef3c7; color: #b45309; }
.pr-tbl .exec-pend { background: var(--slate-100); color: var(--slate-500); }

/* ═══ Buttons ═══ */
.pr-actions { display: flex; gap: 6px; }
.pr-btn {
  padding: 6px 12px; border-radius: 9px;
  border: none; font-weight: 800; font-size: 11px;
  cursor: pointer; display: inline-flex; align-items: center; gap: 5px;
  transition: all 0.15s ease; text-decoration: none;
  font-family: inherit;
}
.pr-btn-primary { background: linear-gradient(135deg, var(--ind-500), var(--violet-500)); color: #fff; box-shadow: 0 2px 6px rgba(99,102,241,0.3); }
.pr-btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(99,102,241,0.4); }
.pr-btn-ghost { background: var(--slate-100); color: var(--slate-700); }
.pr-btn-ghost:hover { background: var(--slate-200); }

.pr-empty { padding: 30px 14px; text-align: center; color: var(--slate-400); }
.pr-empty i { display: block; font-size: 28px; margin-bottom: 8px; }

/* ═══ Print ═══ */
@media print {
  .pr-hero, .pr-urgency, .pr-cols, .pr-filters, .pr-actions, .sidebar, .topbar, .no-print { display: none !important; }
  .pr-section { box-shadow: none; border: 1px solid #ccc; }
  .pr-tbl table { font-size: 10px; }
  .pr-tbl th { background: #f0f0f0 !important; color: #000 !important; }
  body { background: #fff !important; }
}
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="pmq-wrap">
<?php foreach (get_flash() as $fm): ?><div class="alert alert-<?= e($fm['type']) ?>" style="margin-bottom:10px;font-size:12.5px"><?= e($fm['message']) ?></div><?php endforeach; ?>

<!-- ═══ Hero ═══ -->
<div class="pr-hero">
  <div class="pr-hero-row">
    <div class="pr-hero-ico"><i class="fa-solid fa-chart-pie"></i></div>
    <div style="flex:1; min-width:0">
      <h1>تقارير الصيانة الدورية</h1>
      <p>إحصائيات + جدول شامل لـ <?= $total_pms ?> PM نشط — <?= $overdue > 0 ? "⚠️ $overdue منتهي" : ($due_7d > 0 ? "$due_7d مستعجل خلال 7 أيام" : 'كل شيء تحت السيطرة') ?></p>
    </div>
    <div class="pr-hero-stat"><i class="fa-solid fa-list-check"></i><span><b><?= $total_pms ?></b> نشط</span></div>
    <div class="pr-hero-stat" style="background:linear-gradient(135deg,rgba(245,158,11,0.3),rgba(217,119,6,0.1))"><i class="fa-solid fa-triangle-exclamation"></i><span><b><?= $due_7d ?></b> مستعجل</span></div>
  </div>
</div>

<!-- ═══ Schedule urgency (5 cards) ═══ -->
<div class="pr-section">
  <div class="pr-section-head">
    <h2><i class="fa-solid fa-calendar-week" style="color:var(--ind-500)"></i> المواعيد حسب الاستعجال</h2>
    <span class="badge">5 نوافذ زمنية</span>
    <div style="margin-inline-start:auto;display:flex;gap:6px" class="no-print">
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="pr-btn pr-btn-ghost"><i class="fa-solid fa-file-csv"></i> CSV</a>
      <button onclick="window.print()" class="pr-btn pr-btn-primary"><i class="fa-solid fa-print"></i> طباعة A4</button>
    </div>
  </div>
  <div class="pr-urgency">
    <a href="?due=overdue" class="pr-u red <?= $f_due==='overdue'?'on':'' ?>">
      <div class="lbl">منتهي</div>
      <div class="num"><?= $overdue ?></div>
      <div class="sub">الماضي</div>
    </a>
    <a href="?due=7d" class="pr-u orange <?= $f_due==='7d'?'on':'' ?>">
      <div class="lbl">7 أيام</div>
      <div class="num"><?= $due_7d ?></div>
      <div class="sub">مستعجل</div>
    </a>
    <a href="?due=30d" class="pr-u amber <?= $f_due==='30d'?'on':'' ?>">
      <div class="lbl">8-30 يوم</div>
      <div class="num"><?= $due_30d ?></div>
      <div class="sub">قريب</div>
    </a>
    <a href="?due=90d" class="pr-u sky <?= $f_due==='90d'?'on':'' ?>">
      <div class="lbl">31-90 يوم</div>
      <div class="num"><?= $due_90d ?></div>
      <div class="sub">متوسط</div>
    </a>
    <a href="?due=future" class="pr-u slate <?= $f_due==='future'?'on':'' ?>">
      <div class="lbl">+90 يوم</div>
      <div class="num"><?= $future ?></div>
      <div class="sub">بعيد</div>
    </a>
  </div>
</div>

<!-- ═══ By Type + Internal/External ═══ -->
<div class="pr-cols">
  <div class="pr-section">
    <div class="pr-section-head">
      <h2><i class="fa-solid fa-chart-bar" style="color:var(--violet-500)"></i> حسب النوع</h2>
      <span class="badge" style="background:linear-gradient(135deg,var(--violet-500),var(--pink-500))"><?= count($by_type) ?> نوع</span>
    </div>
    <?php foreach ($by_type as $bt):
      $pct = $max_type > 0 ? round(($bt['cnt'] / $max_type) * 100) : 0;
    ?>
      <div class="pr-bar">
        <div class="name"><?= e($bt['pm_type']) ?></div>
        <div class="track"><div class="fill" style="width:<?= max($pct, 8) ?>%"><?= $bt['cnt'] ?></div></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="pr-section">
    <div class="pr-section-head">
      <h2><i class="fa-solid fa-people-group" style="color:var(--amber-500)"></i> المنفّذ</h2>
      <span class="badge" style="background:linear-gradient(135deg,var(--amber-500),var(--rose-500))"><?= $total_pms ?> PM</span>
    </div>
    <?php
      $total = max($total_pms, 1);
      $int_pct = round(($internal_count / $total) * 100);
      $ext_pct = round(($external_count / $total) * 100);
      $pen_pct = round(($pending_count / $total) * 100);
    ?>
    <div class="pr-split">
      <div class="seg internal" style="flex:<?= max($int_pct, 8) ?>"><div class="n"><?= $internal_count ?></div><div class="l">داخلي (<?= $int_pct ?>%)</div></div>
      <div class="seg external" style="flex:<?= max($ext_pct, 8) ?>"><div class="n"><?= $external_count ?></div><div class="l">خارجي (<?= $ext_pct ?>%)</div></div>
      <?php if ($pending_count > 0): ?>
      <div class="seg pending" style="flex:<?= max($pen_pct, 8) ?>"><div class="n"><?= $pending_count ?></div><div class="l">معلّق (<?= $pen_pct ?>%)</div></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($top_contractors)): ?>
      <div style="font-size:11px;font-weight:800;color:var(--slate-500);margin:12px 0 6px;text-transform:uppercase"><i class="fa-solid fa-trophy" style="color:var(--amber-500)"></i> أكثر المتعاقدين</div>
      <?php foreach ($top_contractors as $i => $tc): ?>
        <div class="pr-top-item">
          <div class="rank"><?= $i+1 ?></div>
          <div class="info">
            <div class="name"><?= e($tc['name']) ?></div>
            <div class="meta">متعاقد خارجي</div>
          </div>
          <div class="cnt"><?= $tc['cnt'] ?></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ Top 5 Assets ═══ -->
<?php if (!empty($top_assets)): ?>
<div class="pr-section">
  <div class="pr-section-head">
    <h2><i class="fa-solid fa-star" style="color:var(--amber-500)"></i> أكثر الأصول PMs</h2>
    <span class="badge" style="background:linear-gradient(135deg,var(--amber-500),var(--rose-500))">TOP 5</span>
  </div>
  <div class="pr-top-list">
    <?php foreach ($top_assets as $i => $ta): ?>
      <div class="pr-top-item">
        <div class="rank"><?= $i+1 ?></div>
        <div class="info">
          <div class="name"><?= e($ta['description'] ?: '—') ?></div>
          <div class="meta">تاج: <b style="color:var(--ind-700)"><?= e($ta['tag_number']) ?></b></div>
        </div>
        <div class="cnt"><?= $ta['cnt'] ?> PM</div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- ═══ Filters ═══ -->
<div class="pr-section no-print">
  <form method="get" class="pr-filters">
    <input type="text" name="q" value="<?= e($f_q) ?>" placeholder="🔍 بحث: تاج، جهاز، ملاحظات، منفّذ، متعاقد..." class="q">
    <select name="type">
      <option value="">كل الأنواع</option>
      <?php foreach ($by_type as $bt): ?>
        <option value="<?= e($bt['pm_type']) ?>" <?= $f_type===$bt['pm_type']?'selected':'' ?>><?= e($bt['pm_type']) ?> (<?= $bt['cnt'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <select name="exec">
      <option value="">كل المنفّذين</option>
      <option value="internal" <?= $f_exec==='internal'?'selected':'' ?>>داخلي فقط</option>
      <option value="external" <?= $f_exec==='external'?'selected':'' ?>>خارجي فقط</option>
      <option value="pending" <?= $f_exec==='pending'?'selected':'' ?>>معلّق</option>
    </select>
    <select name="due">
      <option value="">كل المواعيد</option>
      <option value="overdue" <?= $f_due==='overdue'?'selected':'' ?>>منتهي</option>
      <option value="7d" <?= $f_due==='7d'?'selected':'' ?>>7 أيام</option>
      <option value="30d" <?= $f_due==='30d'?'selected':'' ?>>8-30 يوم</option>
      <option value="90d" <?= $f_due==='90d'?'selected':'' ?>>31-90 يوم</option>
      <option value="future" <?= $f_due==='future'?'selected':'' ?>>+90 يوم</option>
    </select>
    <button type="submit" class="pr-btn pr-btn-primary"><i class="fa-solid fa-filter"></i> تطبيق</button>
    <a href="?" class="pr-btn pr-btn-ghost"><i class="fa-solid fa-rotate-left"></i> مسح</a>
  </form>
</div>

<!-- ═══ Table ═══ -->
<div class="pr-section">
  <div class="pr-section-head">
    <h2><i class="fa-solid fa-table-list" style="color:var(--cyan-500)"></i> جدول PMs (<?= count($pms) ?>)</h2>
    <span class="badge" style="background:linear-gradient(135deg,var(--cyan-500),var(--sky-500))"><?= count($pms) ?> صف</span>
  </div>
  <?php if (empty($pms)): ?>
    <div class="pr-empty">
      <i class="fa-solid fa-inbox"></i>
      <p>لا توجد PMs تطابق الفلاتر</p>
    </div>
  <?php else: ?>
  <div class="pr-tbl">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>تاج</th>
          <th>الجهاز</th>
          <th>النوع</th>
          <th>المنفّذ</th>
          <th>الموعد</th>
          <th>الاستعجال</th>
          <th>ملاحظات</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pms as $p):
          $days = (strtotime($p['next_due']) - strtotime($now)) / 86400;
          if ($days < 0) {
            $urg = '<span class="urg-pill urg-overdue"><i class="fa-solid fa-circle-exclamation"></i> منتهي ' . abs((int)$days) . ' يوم</span>';
          } elseif ($days <= 7) {
            $urg = '<span class="urg-pill urg-7d"><i class="fa-solid fa-triangle-exclamation"></i> ' . (int)$days . ' يوم</span>';
          } elseif ($days <= 30) {
            $urg = '<span class="urg-pill urg-30d"><i class="fa-regular fa-clock"></i> ' . (int)$days . ' يوم</span>';
          } elseif ($days <= 90) {
            $urg = '<span class="urg-pill urg-90d"><i class="fa-regular fa-calendar"></i> ' . (int)$days . ' يوم</span>';
          } else {
            $urg = '<span class="urg-pill urg-future">' . (int)$days . ' يوم</span>';
          }
          if ($p['is_external'] && $p['contractor_name']) {
            $exec = '<span class="exec-pill exec-ext"><i class="fa-solid fa-truck"></i> ' . e($p['contractor_name']) . '</span>';
          } elseif ($p['user_name']) {
            $exec = '<span class="exec-pill exec-int"><i class="fa-solid fa-user"></i> ' . e($p['user_name']) . '</span>';
          } else {
            $exec = '<span class="exec-pill exec-pend"><i class="fa-solid fa-pause"></i> معلّق</span>';
          }
        ?>
        <tr>
          <td><b style="color:var(--ind-700)">#<?= $p['id'] ?></b></td>
          <td class="tag"><?= e($p['tag_number'] ?? '—') ?></td>
          <td><?= e($p['asset_desc'] ?? '—') ?></td>
          <td><?= e($p['pm_type']) ?></td>
          <td><?= $exec ?></td>
          <td style="font-family:monospace;font-size:11px"><?= e($p['next_due']) ?></td>
          <td><?= $urg ?></td>
          <td style="font-size:11px;color:var(--slate-500);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($p['notes'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

</div>
</main>
</div>

</body>
</html>
