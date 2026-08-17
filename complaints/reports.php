<?php
/**
 * complaints/reports.php — تقارير البلاغات المفصلة
 * ─────────────────────────────────────────────────────────────
 * مؤشرات أداء + رسوم بيانية + جداول تحليلية + جدول تفصيلي + تصدير CSV
 * القياس الزمني = صافي وقت المعالجة (بعد خصم فترات الإيقاف:
 * الشركة المتعاقدة / لجنة المتابعة) — نفس مصدر العدّاد الحي وبطاقة الحصيلة.
 * النطاق: الأدمن/التنفيذي يرى الكل، وفريق الصيانة يرى نوعه فقط.
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('complaints.reports');

$can_export = can('complaints.reports', 'export');

/* ── نطاق الفريق ── */
$force_type = null;
if (!can_see_all()) {
    $my_dept_id = (int)(current_user()['department_id'] ?? 0);
    if ($my_dept_id) {
        $dc = $pdo->prepare("SELECT dept_category FROM departments WHERE id=?");
        $dc->execute([$my_dept_id]);
        $cat = (string)($dc->fetchColumn() ?: '');
        if (str_starts_with($cat, 'maintenance_')) {
            $force_type = substr($cat, strlen('maintenance_'));
        }
    }
}

/* ── الفلاتر ── */
$f_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$f_to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = date('Y-m-d');

/* ── الفترة السابقة (للمقارنة: نفس المدة قبل الفترة الحالية) ── */
$_period_days = (int) round((strtotime($f_to) - strtotime($f_from)) / 86400);
$prev_from    = date('Y-m-d', strtotime($f_from . " -" . ($_period_days + 1) . " days"));
$prev_to      = date('Y-m-d', strtotime($f_from . " -1 day"));

$TYPES = ['medical' => 'طبي', 'it' => 'تقنية معلومات', 'general' => 'صيانة عامة'];
$PRIOS = ['normal' => 'عادي', 'urgent' => 'عاجل', 'critical' => 'طارئ'];
$STATS = ['open' => 'مفتوح', 'acknowledged' => 'مستلَم', 'in_progress' => 'قيد المعالجة',
    'stalled' => 'متعثر', 'escalated' => 'مُصعَّد', 'resolved' => 'محلول',
    'closed' => 'مغلق', 'cancelled' => 'ملغى', 'rejected' => 'مرفوض'];

$f_type = isset($TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$f_prio = isset($PRIOS[$_GET['prio'] ?? '']) ? $_GET['prio'] : '';
$f_stat = isset($STATS[$_GET['stat'] ?? '']) ? $_GET['stat'] : '';
$f_dept = (int)($_GET['dept'] ?? 0);
if ($force_type !== null) $f_type = $force_type;

/* ── بناء الشرط الموحد ── */
$W = "c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
$P = [$f_from, $f_to];
if ($f_type) { $W .= " AND c.request_type = ?"; $P[] = $f_type; }
if ($f_prio) { $W .= " AND c.priority = ?";     $P[] = $f_prio; }
if ($f_stat) { $W .= " AND c.status = ?";       $P[] = $f_stat; }
if ($f_dept) { $W .= " AND c.dept_id = ?";      $P[] = $f_dept; }

/* ── شرط الفترة السابقة (نفس الفلاتر) ── */
$PW = "c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
$PP = [$prev_from, $prev_to];
if ($f_type) { $PW .= " AND c.request_type = ?"; $PP[] = $f_type; }
if ($f_prio) { $PW .= " AND c.priority = ?";     $PP[] = $f_prio; }
if ($f_stat) { $PW .= " AND c.status = ?";       $PP[] = $f_stat; }
if ($f_dept) { $PW .= " AND c.dept_id = ?";      $PP[] = $f_dept; }

/* صافي ثواني المعالجة (للمحلول/المغلق) */
$NET = "GREATEST(0, TIMESTAMPDIFF(SECOND, c.created_at,
        COALESCE(c.closed_at, c.resolved_at)) - COALESCE(c.sla_paused_seconds_total,0))";

function fmt_dur(?int $s): string {
    if ($s === null) return '—';
    $s = max(0, $s);
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    $o = [];
    if ($d) $o[] = $d . 'ي';
    if ($h) $o[] = $h . 'س';
    $o[] = $m . 'د';
    return implode(' ', $o);
}

/* ── تصدير CSV ── */
if (($_GET['export'] ?? '') === 'csv') {
    if (!$can_export) { http_response_code(403); die('لا تملك صلاحية التصدير'); }
    $rows = $pdo->prepare(
        "SELECT c.request_number, c.request_type, c.priority, c.status,
                c.created_at, c.resolved_at, c.closed_at,
                d.name AS dept_name, a.description AS asset_desc, a.tag_number,
                c.service_rating,
                CASE WHEN c.status IN ('resolved','closed') THEN $NET END AS net_sec,
                COALESCE(c.sla_paused_seconds_total,0) AS paused_sec,
                (c.sla_breach_detected_at IS NOT NULL) AS breached
         FROM complaints c
         LEFT JOIN departments d ON d.id = c.dept_id
         LEFT JOIN assets a ON a.id = c.asset_id
         WHERE $W ORDER BY c.id DESC");
    $rows->execute($P);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="complaints_report_'
        . $f_from . '_' . $f_to . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM لعرض العربية في Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['رقم البلاغ', 'النوع', 'الأولوية', 'الحالة', 'القسم', 'الجهاز',
        'التاج', 'تاريخ الرفع', 'تاريخ الحل', 'صافي المعالجة (دقائق)',
        'الإيقاف (دقائق)', 'تجاوز المهلة', 'التقييم']);
    global $TYPES, $PRIOS, $STATS;
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['request_number'], $TYPES[$r['request_type']] ?? $r['request_type'],
            $PRIOS[$r['priority']] ?? $r['priority'], $STATS[$r['status']] ?? $r['status'],
            $r['dept_name'], $r['asset_desc'], $r['tag_number'],
            $r['created_at'], $r['resolved_at'] ?: $r['closed_at'],
            $r['net_sec'] !== null ? round($r['net_sec'] / 60) : '',
            round($r['paused_sec'] / 60),
            $r['breached'] ? 'نعم' : 'لا',
            $r['service_rating'] ?: '',
        ]);
    }
    fclose($out); exit;
}

/* ── 1) المؤشرات ── */
$k = $pdo->prepare(
    "SELECT COUNT(*) total,
        SUM(c.status IN ('resolved','closed')) done,
        SUM(c.status = 'escalated') escalated,
        SUM(c.sla_breach_detected_at IS NOT NULL) breached,
        AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_net,
        AVG(CASE WHEN c.service_rating > 0 THEN c.service_rating END) avg_rate,
        SUM(c.service_rating > 0) rated_cnt
     FROM complaints c WHERE $W");
$k->execute($P);
$K = $k->fetch();
$done_rate  = $K['total'] ? round($K['done'] * 100 / $K['total']) : 0;
$breach_rate = $K['total'] ? round($K['breached'] * 100 / $K['total']) : 0;

/* ── 1b) مؤشرات الفترة السابقة (للمقارنة) ── */
$kp = $pdo->prepare(
    "SELECT COUNT(*) total,
        SUM(c.status IN ('resolved','closed')) done,
        SUM(c.sla_breach_detected_at IS NOT NULL) breached,
        AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_net,
        AVG(CASE WHEN c.service_rating > 0 THEN c.service_rating END) avg_rate
     FROM complaints c WHERE $PW");
$kp->execute($PP);
$KP = $kp->fetch() ?: ['total'=>0,'done'=>0,'breached'=>0,'avg_net'=>null,'avg_rate'=>null];
$prev_done_rate  = $KP['total'] ? round($KP['done'] * 100 / $KP['total']) : 0;
$prev_breach_rate = $KP['total'] ? round($KP['breached'] * 100 / $KP['total']) : 0;

/* ── دالة مساعدة: حساب نسبة التغيير + سهم ── */
function trend_arrow($curr, $prev, $lower_is_better = false): array {
    if (!$prev && !$curr) {
        return ['pct' => 0, 'arrow' => '=', 'color' => '#94a3b8', 'sign' => ''];
    }
    if (!$prev) {
        return ['pct' => 100, 'arrow' => '↑', 'color' => '#16a34a', 'sign' => '+'];
    }
    if (!$curr) {
        return ['pct' => 100, 'arrow' => '↓', 'color' => $lower_is_better ? '#16a34a' : '#dc2626', 'sign' => '-'];
    }
    $pct = round(($curr - $prev) * 100 / $prev);
    $is_better = $lower_is_better ? ($curr < $prev) : ($curr > $prev);
    $arrow = $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '=');
    $color = $is_better ? '#16a34a' : ($pct == 0 ? '#94a3b8' : '#dc2626');
    return ['pct' => abs($pct), 'arrow' => $arrow, 'color' => $color, 'sign' => $pct >= 0 ? '+' : '-'];
}

/* ── 2) حسب الحالة ── */
$byStat = $pdo->prepare(
    "SELECT c.status, COUNT(*) n FROM complaints c WHERE $W GROUP BY c.status");
$byStat->execute($P);
$byStat = $byStat->fetchAll(PDO::FETCH_KEY_PAIR);

/* ── 3) حسب النوع ── */
$byType = $pdo->prepare(
    "SELECT c.request_type, COUNT(*) n FROM complaints c WHERE $W GROUP BY c.request_type");
$byType->execute($P);
$byType = $byType->fetchAll(PDO::FETCH_KEY_PAIR);

/* ── 4) شهري: مرفوع/محلول ── */
$monthly = $pdo->prepare(
    "SELECT DATE_FORMAT(c.created_at, '%Y-%m') ym,
            COUNT(*) created_n,
            SUM(c.status IN ('resolved','closed')) done_n
     FROM complaints c WHERE $W
     GROUP BY ym ORDER BY ym");
$monthly->execute($P);
$monthly = $monthly->fetchAll();

/* ── 5) أعلى الأقسام ── */
$topDepts = $pdo->prepare(
    "SELECT d.name, COUNT(*) n,
            AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_net
     FROM complaints c JOIN departments d ON d.id = c.dept_id
     WHERE $W GROUP BY d.id, d.name ORDER BY n DESC LIMIT 10");
$topDepts->execute($P);
$topDepts = $topDepts->fetchAll();

/* ── 6) أكثر الأجهزة تعطلاً ── */
$topAssets = $pdo->prepare(
    "SELECT a.description, a.tag_number, COUNT(*) n
     FROM complaints c JOIN assets a ON a.id = c.asset_id
     WHERE $W GROUP BY a.id ORDER BY n DESC LIMIT 10");
$topAssets->execute($P);
$topAssets = $topAssets->fetchAll();

/* ── 7) أبطأ البلاغات ── */
$slowest = $pdo->prepare(
    "SELECT c.id, c.request_number, c.description, d.name dept_name, $NET net_sec
     FROM complaints c LEFT JOIN departments d ON d.id = c.dept_id
     WHERE $W AND c.status IN ('resolved','closed')
     ORDER BY net_sec DESC LIMIT 5");
$slowest->execute($P);
$slowest = $slowest->fetchAll();

/* ── 8) أداء المهندسين (أعلى 10 من حلّوا بلاغات) ── */
$engineers = $pdo->prepare(
    "SELECT u.id, u.full_name,
            COUNT(c.id) total,
            SUM(c.status IN ('resolved','closed')) resolved,
            AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_sec,
            AVG(CASE WHEN c.service_rating > 0 THEN c.service_rating END) avg_rating,
            SUM(c.sla_breach_detected_at IS NOT NULL) breached
     FROM complaints c
     INNER JOIN users u ON u.id = c.resolved_by
     WHERE $W
     GROUP BY u.id, u.full_name
     HAVING total > 0
     ORDER BY resolved DESC, total DESC
     LIMIT 10");
$engineers->execute($P);
$engineers = $engineers->fetchAll();

/* ── 9) حسب فئة الحساسية (A/B/C) للأجهزة ── */
$byCriticality = $pdo->prepare(
    "SELECT COALESCE(a.criticality_class, '_none') AS cls,
            COUNT(c.id) total,
            SUM(c.status IN ('resolved','closed')) done,
            AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_sec,
            SUM(c.sla_breach_detected_at IS NOT NULL) breached
     FROM complaints c
     LEFT JOIN assets a ON a.id = c.asset_id
     WHERE $W
     GROUP BY cls
     ORDER BY FIELD(cls, 'A', 'B', 'C', '_none')");
$byCriticality->execute($P);
$byCriticality = $byCriticality->fetchAll();

/* ── 10) تحليل التقادم (البلاغات المفتوحة حسب العمر) ── */
$aging = $pdo->prepare(
    "SELECT
        SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) <= 24)   AS fresh,
        SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) BETWEEN 25 AND 72)  AS week,
        SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) BETWEEN 73 AND 168) AS medium,
        SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) > 168) AS old,
        SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND c.escalation_due_at < NOW() AND c.escalation_due_at IS NOT NULL) AS overdue
     FROM complaints c WHERE $W");
$aging->execute($P);
$aging = $aging->fetch();
$aging_open_total = (int)($aging['fresh'] ?? 0) + (int)($aging['week'] ?? 0) + (int)($aging['medium'] ?? 0) + (int)($aging['old'] ?? 0);

/* ── 11) الأصول الأكثر تكراراً (≥2 بلاغات) ── */
$recurring = $pdo->prepare(
    "SELECT a.id, a.tag_number, a.description,
            COUNT(c.id) total,
            SUM(c.status IN ('resolved','closed')) done,
            MAX(c.created_at) last_complaint_at,
            a.criticality_class
     FROM complaints c
     INNER JOIN assets a ON a.id = c.asset_id
     WHERE $W AND c.asset_id IS NOT NULL
     GROUP BY a.id
     HAVING total >= 2
     ORDER BY total DESC
     LIMIT 10");
$recurring->execute($P);
$recurring = $recurring->fetchAll();

/* ── 8) الجدول التفصيلي ── */
$detail = $pdo->prepare(
    "SELECT c.id, c.request_number, c.request_type, c.priority, c.status,
            c.created_at, c.service_rating,
            (c.sla_breach_detected_at IS NOT NULL) breached,
            d.name dept_name, a.description asset_desc,
            CASE WHEN c.status IN ('resolved','closed') THEN $NET END net_sec
     FROM complaints c
     LEFT JOIN departments d ON d.id = c.dept_id
     LEFT JOIN assets a ON a.id = c.asset_id
     WHERE $W ORDER BY c.id DESC LIMIT 200");
$detail->execute($P);
$detail = $detail->fetchAll();

/* قائمة الأقسام للفلتر */
$dept_list = $pdo->query(
    "SELECT DISTINCT d.id, d.name FROM complaints c
     JOIN departments d ON d.id = c.dept_id ORDER BY d.name")->fetchAll();

$flash_msgs = get_flash();
$page_title = 'تقارير البلاغات المفصلة';

$ST_COLORS = ['open' => '#64748b', 'acknowledged' => '#0ea5e9', 'in_progress' => '#2563eb',
    'stalled' => '#f59e0b', 'escalated' => '#dc2626', 'resolved' => '#10b981',
    'closed' => '#16a34a', 'cancelled' => '#94a3b8', 'rejected' => '#78716c'];

function qurl(array $extra = []): string {
    return '?' . http_build_query(array_merge($_GET, $extra));
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; }
body { background:var(--bg); font-family:'Tajawal',sans-serif; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width:1500px; margin:0 auto; padding:22px; }
.h-banner { background:linear-gradient(135deg,#0e7490,#0891b2); border-radius:20px;
    padding:20px 26px; color:#fff; margin-bottom:16px; display:flex;
    justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
.h-banner h1 { font-size:18px; font-weight:900; margin:0; }
.h-banner p { font-size:12px; color:#cffafe; margin:4px 0 0; }
.hb-btn { background:rgba(255,255,255,.15); border:1.5px solid rgba(255,255,255,.35);
    color:#fff; padding:9px 16px; border-radius:10px; font-family:'Tajawal';
    font-size:12.5px; font-weight:800; cursor:pointer; text-decoration:none; }
.filters { background:var(--card); border-radius:16px; border:1px solid var(--border);
    padding:14px 18px; margin-bottom:16px; display:flex; gap:10px; align-items:end;
    flex-wrap:wrap; }
.filters label { font-size:10.5px; font-weight:800; color:var(--muted);
    display:block; margin-bottom:4px; }
.filters input, .filters select { border:1.5px solid var(--border); border-radius:9px;
    padding:8px 10px; font-family:'Tajawal'; font-size:12.5px; background:#fff; }
.btn-f { background:#0e7490; color:#fff; border:none; border-radius:9px;
    padding:9px 18px; font-family:'Tajawal'; font-size:12.5px; font-weight:800;
    cursor:pointer; }
.kpis { display:grid; grid-template-columns:repeat(6,1fr); gap:12px; margin-bottom:16px; }
@media(max-width:1100px){ .kpis{ grid-template-columns:repeat(3,1fr); } }
.kpi { background:var(--card); border:1px solid var(--border); border-radius:15px;
    padding:14px 16px; }
.kpi .v { font-size:21px; font-weight:900; color:var(--text); }
.kpi .l { font-size:10.5px; font-weight:800; color:var(--muted); margin-top:3px; }
.kpi .s { font-size:10px; color:#94a3b8; margin-top:2px; }
.grid2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
@media(max-width:1000px){ .grid2{ grid-template-columns:1fr; } }
.bento { background:var(--card); border-radius:16px; border:1px solid var(--border);
    padding:18px; }
.bento h3 { font-size:13px; font-weight:900; margin:0 0 14px; color:var(--text);
    display:flex; align-items:center; gap:8px; }
.bento h3 i { color:#0e7490; }
table.rt { width:100%; border-collapse:collapse; }
table.rt th { font-size:10.5px; font-weight:800; color:var(--muted);
    padding:8px 10px; text-align:right; background:#f8fafc;
    border-bottom:1px solid var(--border); }
table.rt td { font-size:12px; padding:8px 10px; border-bottom:1px solid #f8fafc;
    color:#334155; }
table.rt tr:hover td { background:#fafafa; }
.pill { display:inline-block; font-size:10.5px; font-weight:800; border-radius:50px;
    padding:2px 10px; }
.bar-bg { background:#f1f5f9; border-radius:6px; height:8px; overflow:hidden; }
.bar-fg { height:100%; background:linear-gradient(90deg,#0e7490,#06b6d4); }
@media print { .filters,.hb-btn,.sidebar,.topbar { display:none !important; }
    body { background:#fff; } .bento,.kpi { box-shadow:none; } }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<div class="h-banner">
    <div>
        <h1><i class="fa-solid fa-chart-line"></i> تقارير البلاغات المفصلة</h1>
        <p>الفترة: <span class="eng"><?= e($f_from) ?> ← <?= e($f_to) ?></span>
           <?= $force_type ? ' · نطاقك: ' . e($TYPES[$force_type]) : '' ?>
           · القياس الزمني = صافي وقت المعالجة بعد خصم فترات الإيقاف</p>
    </div>
    <div style="display:flex;gap:8px">
        <?php if ($can_export): ?>
        <a class="hb-btn" href="<?= e(BASE_URL . '/complaints/api/export_report_pdf.php?' . http_build_query(array_diff_key($_GET, ['export' => '']))) ?>" target="_blank" style="background:linear-gradient(135deg,#dc2626,#991b1b)">
            <i class="fa-solid fa-file-pdf"></i> تصدير PDF</a>
        <a class="hb-btn" href="<?= e(qurl(['export' => 'csv'])) ?>" style="background:rgba(255,255,255,.1)">
            <i class="fa-solid fa-file-csv"></i> CSV</a>
        <?php endif; ?>
        <a class="hb-btn" href="#" onclick="window.print();return false">
            <i class="fa-solid fa-print"></i> طباعة</a>
    </div>
</div>

<form class="filters" method="GET">
    <div><label>من تاريخ</label>
        <input type="date" name="from" value="<?= e($f_from) ?>"></div>
    <div><label>إلى تاريخ</label>
        <input type="date" name="to" value="<?= e($f_to) ?>"></div>
    <?php if ($force_type === null): ?>
    <div><label>النوع</label>
        <select name="type"><option value="">الكل</option>
        <?php foreach ($TYPES as $tk => $tl): ?>
        <option value="<?= e($tk) ?>" <?= $f_type === $tk ? 'selected' : '' ?>><?= e($tl) ?></option>
        <?php endforeach; ?></select></div>
    <?php endif; ?>
    <div><label>الأولوية</label>
        <select name="prio"><option value="">الكل</option>
        <?php foreach ($PRIOS as $pk => $pl): ?>
        <option value="<?= e($pk) ?>" <?= $f_prio === $pk ? 'selected' : '' ?>><?= e($pl) ?></option>
        <?php endforeach; ?></select></div>
    <div><label>الحالة</label>
        <select name="stat"><option value="">الكل</option>
        <?php foreach ($STATS as $sk => $sl): ?>
        <option value="<?= e($sk) ?>" <?= $f_stat === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
        <?php endforeach; ?></select></div>
    <div><label>القسم المبلِّغ</label>
        <select name="dept"><option value="0">الكل</option>
        <?php foreach ($dept_list as $d): ?>
        <option value="<?= (int)$d['id'] ?>" <?= $f_dept === (int)$d['id'] ? 'selected' : '' ?>>
            <?= e($d['name']) ?></option>
        <?php endforeach; ?></select></div>
    <button class="btn-f"><i class="fa-solid fa-filter"></i> تطبيق</button>
    <a href="reports.php" style="font-size:11.5px;font-weight:800;color:#dc2626;
        text-decoration:none;padding:9px 4px">إلغاء التصفية</a>
</form>

<?php
// مؤشرات المقارنة
$t_total = trend_arrow((int)$K['total'], (int)$KP['total']);
$t_done  = trend_arrow($done_rate, $prev_done_rate);
$t_breach= trend_arrow($breach_rate, $prev_breach_rate, true);
$t_avg   = trend_arrow($K['avg_net'] !== null ? (int)$K['avg_net'] : 0, $KP['avg_net'] !== null ? (int)$KP['avg_net'] : 0, true);
$t_rate  = trend_arrow((float)($K['avg_rate'] ?? 0), (float)($KP['avg_rate'] ?? 0));
?>

<!-- بطاقة مقارنة سريعة -->
<div style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #bae6fd;border-radius:14px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:18px;flex-wrap:wrap">
    <div style="flex:1;min-width:240px">
        <div style="font-size:13px;font-weight:900;color:#0c4a6e;display:flex;align-items:center;gap:6px">
            <i class="fa-solid fa-arrows-left-right"></i>
            مقارنة بالفترة السابقة
        </div>
        <div style="font-size:11.5px;color:#475569;margin-top:3px">
            الحالية: <span class="eng"><b><?= e($f_from) ?></b> ← <b><?= e($f_to) ?></b></span>
            &nbsp;|&nbsp; السابقة: <span class="eng"><b><?= e($prev_from) ?></b> ← <b><?= e($prev_to) ?></b></span>
            &nbsp;(<span class="eng"><?= $_period_days ?></span> يوم)
        </div>
    </div>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
        <div style="text-align:center;min-width:90px">
            <div style="font-size:10px;font-weight:800;color:#64748b">إجمالي</div>
            <div style="font-size:18px;font-weight:900;font-family:'Inter';color:<?= e($t_total['color']) ?>">
                <?= $t_total['arrow'] ?> <?= e($t_total['sign']) ?><?= (int)$t_total['pct'] ?>%
            </div>
            <div style="font-size:10px;color:#94a3b8"><span class="eng"><?= (int)$KP['total'] ?></span> → <span class="eng"><?= (int)$K['total'] ?></span></div>
        </div>
        <div style="text-align:center;min-width:90px">
            <div style="font-size:10px;font-weight:800;color:#64748b">نسبة الحل</div>
            <div style="font-size:18px;font-weight:900;font-family:'Inter';color:<?= e($t_done['color']) ?>">
                <?= $t_done['arrow'] ?> <?= e($t_done['sign']) ?><?= (int)$t_done['pct'] ?>%
            </div>
            <div style="font-size:10px;color:#94a3b8"><span class="eng"><?= $prev_done_rate ?>%</span> → <span class="eng"><?= $done_rate ?>%</span></div>
        </div>
        <div style="text-align:center;min-width:90px">
            <div style="font-size:10px;font-weight:800;color:#64748b">تجاوز المهلة</div>
            <div style="font-size:18px;font-weight:900;font-family:'Inter';color:<?= e($t_breach['color']) ?>">
                <?= $t_breach['arrow'] ?> <?= e($t_breach['sign']) ?><?= (int)$t_breach['pct'] ?>%
            </div>
            <div style="font-size:10px;color:#94a3b8"><span class="eng"><?= $prev_breach_rate ?>%</span> → <span class="eng"><?= $breach_rate ?>%</span></div>
        </div>
    </div>
</div>

<div class="kpis">
    <div class="kpi"><div class="v eng"><?= (int)$K['total'] ?></div>
        <div class="l">إجمالي البلاغات</div>
        <div class="s eng" style="color:<?= e($t_total['color']) ?>">
            <?= $t_total['arrow'] ?> <?= e($t_total['sign']) ?><?= (int)$t_total['pct'] ?>% <?= (isset($rtl)&&$rtl)?'مقارنة':'vs prev' ?>
        </div></div>
    <div class="kpi"><div class="v eng" style="color:#16a34a"><?= $done_rate ?>%</div>
        <div class="l">نسبة الحل</div>
        <div class="s eng" style="color:<?= e($t_done['color']) ?>">
            <?= $t_done['arrow'] ?> <?= e($t_done['sign']) ?><?= (int)$t_done['pct'] ?>% <?= (isset($rtl)&&$rtl)?'مقارنة':'vs prev' ?>
        </div></div>
    <div class="kpi"><div class="v" style="color:#0e7490">
        <?= fmt_dur($K['avg_net'] !== null ? (int)$K['avg_net'] : null) ?></div>
        <div class="l">متوسط صافي زمن الحل</div>
        <div class="s eng" style="color:<?= e($t_avg['color']) ?>">
            <?= $t_avg['arrow'] ?> <?= e($t_avg['sign']) ?><?= (int)$t_avg['pct'] ?>% <?= (isset($rtl)&&$rtl)?'أسرع/أبطأ':'faster/slower' ?>
        </div></div>
    <div class="kpi"><div class="v eng" style="color:#dc2626"><?= (int)$K['escalated'] ?></div>
        <div class="l">مُصعَّد حالياً</div></div>
    <div class="kpi"><div class="v eng" style="color:#b45309"><?= $breach_rate ?>%</div>
        <div class="l">نسبة تجاوز المهلة</div>
        <div class="s eng" style="color:<?= e($t_breach['color']) ?>">
            <?= $t_breach['arrow'] ?> <?= e($t_breach['sign']) ?><?= (int)$t_breach['pct'] ?>% <?= (isset($rtl)&&$rtl)?'مقارنة':'vs prev' ?>
        </div></div>
    <div class="kpi"><div class="v" style="color:#f59e0b">
        <?= $K['avg_rate'] ? '★ ' . number_format((float)$K['avg_rate'], 1) : '—' ?></div>
        <div class="l">متوسط تقييم المستفيدين</div>
        <div class="s eng" style="color:<?= e($t_rate['color']) ?>">
            <?= $t_rate['arrow'] ?> <?= e($t_rate['sign']) ?><?= (int)$t_rate['pct'] ?>%
        </div></div>
</div>

<div class="grid2">
    <div class="bento"><h3><i class="fa-solid fa-chart-pie"></i> توزيع الحالات</h3>
        <div style="max-height:260px"><canvas id="chStatus"></canvas></div></div>
    <div class="bento"><h3><i class="fa-solid fa-chart-column"></i> البلاغات شهرياً: مرفوعة مقابل محلولة</h3>
        <div style="max-height:260px"><canvas id="chMonthly"></canvas></div></div>
</div>

<div class="grid2">
    <div class="bento">
        <h3><i class="fa-solid fa-hospital"></i> أعلى الأقسام رفعاً للبلاغات</h3>
        <table class="rt"><tr><th>القسم</th><th>العدد</th>
            <th style="width:32%">النسبة</th><th>متوسط الحل</th></tr>
        <?php $mx = $topDepts ? (int)$topDepts[0]['n'] : 1;
        foreach ($topDepts as $r): ?>
        <tr><td><b><?= e($r['name']) ?></b></td>
            <td class="eng"><b><?= (int)$r['n'] ?></b></td>
            <td><div class="bar-bg"><div class="bar-fg"
                style="width:<?= round($r['n'] * 100 / $mx) ?>%"></div></div></td>
            <td><?= fmt_dur($r['avg_net'] !== null ? (int)$r['avg_net'] : null) ?></td></tr>
        <?php endforeach; if (!$topDepts): ?>
        <tr><td colspan="4" style="color:var(--muted)">لا بيانات في الفترة</td></tr>
        <?php endif; ?></table>
    </div>
    <div class="bento">
        <h3><i class="fa-solid fa-triangle-exclamation"></i> أكثر الأجهزة تعطلاً</h3>
        <table class="rt"><tr><th>الجهاز</th><th>التاج</th><th>عدد البلاغات</th></tr>
        <?php foreach ($topAssets as $r): ?>
        <tr><td><b><?= e(truncate($r['description'], 42)) ?></b></td>
            <td class="eng"><?= e($r['tag_number'] ?: '—') ?></td>
            <td><span class="pill" style="background:#fef2f2;color:#dc2626">
                <?= (int)$r['n'] ?> بلاغ</span></td></tr>
        <?php endforeach; if (!$topAssets): ?>
        <tr><td colspan="3" style="color:var(--muted)">لا بيانات في الفترة</td></tr>
        <?php endif; ?></table>
        <?php if ($slowest): ?>
        <h3 style="margin-top:18px"><i class="fa-solid fa-hourglass-half"></i> أبطأ البلاغات حلاً</h3>
        <table class="rt"><tr><th>البلاغ</th><th>القسم</th><th>صافي المدة</th></tr>
        <?php foreach ($slowest as $r): ?>
        <tr><td><a href="view.php?id=<?= (int)$r['id'] ?>" class="eng"
            style="color:#0e7490;font-weight:800;text-decoration:none">
            <?= e($r['request_number']) ?></a></td>
            <td><?= e($r['dept_name'] ?: '—') ?></td>
            <td><b style="color:#b45309"><?= fmt_dur((int)$r['net_sec']) ?></b></td></tr>
        <?php endforeach; ?></table>
        <?php endif; ?>
    </div>
</div>

<!-- ═══ قسم جديد: أداء المهندسين ═══ -->
<div class="bento" style="border-right:4px solid #7c3aed">
    <h3><i class="fa-solid fa-user-gear" style="color:#7c3aed"></i> أداء المهندسين والفرق
        <span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:99px;font-size:10.5px;margin-right:8px">
            <?= count($engineers) ?> مهندس
        </span>
    </h3>
    <div style="overflow-x:auto">
    <table class="rt">
        <tr>
            <th>المهندس</th>
            <th>إجمالي</th>
            <th>محلول</th>
            <th>نسبة الحل</th>
            <th>متوسط زمن الحل</th>
            <th>تجاوز المهلة</th>
            <th>متوسط التقييم</th>
        </tr>
        <?php if (!$engineers): ?>
        <tr><td colspan="7" style="color:var(--muted);text-align:center;padding:18px">لا بيانات في هذه الفترة</td></tr>
        <?php else: foreach ($engineers as $e):
            $r_done = $e['total'] ? round($e['resolved'] * 100 / $e['total']) : 0;
            $rating = $e['avg_rating'] ? '★ ' . number_format((float)$e['avg_rating'], 1) : '—';
            $rating_color = $e['avg_rating'] >= 4 ? '#16a34a' : ($e['avg_rating'] >= 3 ? '#f59e0b' : '#dc2626');
        ?>
        <tr>
            <td><b style="color:#0f172a"><?= e($e['full_name']) ?></b></td>
            <td class="eng"><b><?= (int)$e['total'] ?></b></td>
            <td class="eng"><?= (int)$e['resolved'] ?></td>
            <td class="eng"><b style="color:<?= $r_done>=80?'#16a34a':($r_done>=50?'#f59e0b':'#dc2626') ?>"><?= $r_done ?>%</b></td>
            <td><?= fmt_dur($e['avg_sec'] !== null ? (int)$e['avg_sec'] : null) ?></td>
            <td class="eng"><?= (int)$e['breached'] ?> (<?= $e['total']?round($e['breached']*100/$e['total']):0 ?>%)</td>
            <td><b style="color:<?= e($rating_color) ?>"><?= e($rating) ?></b></td>
        </tr>
        <?php endforeach; endif; ?>
    </table>
    </div>
</div>

<!-- ═══ قسم جديد: حسب فئة الحساسية ═══ -->
<div class="bento">
    <h3><i class="fa-solid fa-shield-halved" style="color:#dc2626"></i> البلاغات حسب فئة حساسية الجهاز
        <span style="font-size:10.5px;color:var(--muted);font-weight:700;margin-right:8px">
            A = حرج | B = عالي | C = عادي
        </span>
    </h3>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
        <?php
        $CLS_META = [
            'A' => ['ar'=>'فئة A (حرج)','color'=>'#dc2626','bg'=>'#fee2e2'],
            'B' => ['ar'=>'فئة B (عالي)','color'=>'#f59e0b','bg'=>'#fef3c7'],
            'C' => ['ar'=>'فئة C (عادي)','color'=>'#10b981','bg'=>'#dcfce7'],
            '_none' => ['ar'=>'بدون فئة','color'=>'#94a3b8','bg'=>'#f1f5f9'],
        ];
        $byC_indexed = [];
        foreach ($byCriticality as $r) $byC_indexed[$r['cls']] = $r;
        foreach (['A','B','C','_none'] as $cls):
            $r = $byC_indexed[$cls] ?? null;
            $m = $CLS_META[$cls];
        ?>
        <div style="background:<?= e($m['bg']) ?>;border:1px solid <?= e($m['color']) ?>33;border-radius:12px;padding:12px 14px">
            <div style="font-size:11px;font-weight:800;color:<?= e($m['color']) ?>"><?= e($m['ar']) ?></div>
            <div style="font-size:22px;font-weight:900;color:<?= e($m['color']) ?>;font-family:'Inter';margin-top:4px">
                <?= $r ? (int)$r['total'] : 0 ?>
            </div>
            <div style="font-size:10.5px;color:#64748b;margin-top:2px">
                <?= $r ? (int)$r['done'] : 0 ?> محلول |
                <?= $r && $r['avg_sec'] !== null ? fmt_dur((int)$r['avg_sec']) : '—' ?> متوسط
            </div>
            <div style="font-size:10px;color:#94a3b8;margin-top:2px">
                <?= $r ? (int)$r['breached'] : 0 ?> تجاوز
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ═══ قسم جديد: تحليل التقادم ═══ -->
<div class="bento" style="border-right:4px solid #f59e0b">
    <h3><i class="fa-solid fa-hourglass-half" style="color:#f59e0b"></i> تحليل التقادم (البلاغات المفتوحة حالياً)
        <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;font-size:10.5px;margin-right:8px">
            <?= $aging_open_total ?> مفتوح
        </span>
    </h3>
    <?php if ($aging_open_total === 0): ?>
        <div style="padding:30px;text-align:center;color:#16a34a;font-weight:800">
            <i class="fa-solid fa-circle-check" style="font-size:32px;display:block;margin-bottom:6px"></i>
            ممتاز! لا توجد بلاغات مفتوحة متأخرة.
        </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px">
        <?php
        $aging_buckets = [
            ['label' => '≤ 24 ساعة', 'count' => (int)$aging['fresh'],   'color' => '#10b981', 'bg' => '#dcfce7', 'sub' => 'طازج'],
            ['label' => '1-3 أيام',  'count' => (int)$aging['week'],   'color' => '#0ea5e9', 'bg' => '#e0f2fe', 'sub' => 'حديث'],
            ['label' => '3-7 أيام',  'count' => (int)$aging['medium'], 'color' => '#f59e0b', 'bg' => '#fef3c7', 'sub' => 'متوسط'],
            ['label' => '> 7 أيام',  'count' => (int)$aging['old'],    'color' => '#dc2626', 'bg' => '#fee2e2', 'sub' => 'متأخر ⚠'],
            ['label' => 'تجاوز SLA', 'count' => (int)$aging['overdue'],'color' => '#7c2d12', 'bg' => '#fed7aa', 'sub' => 'فوري!'],
        ];
        foreach ($aging_buckets as $b): ?>
        <div style="background:<?= e($b['bg']) ?>;border:1px solid <?= e($b['color']) ?>33;border-radius:12px;padding:14px 12px;text-align:center">
            <div style="font-size:10.5px;font-weight:800;color:<?= e($b['color']) ?>;text-transform:uppercase"><?= e($b['sub']) ?></div>
            <div style="font-size:28px;font-weight:900;color:<?= e($b['color']) ?>;font-family:'Inter';margin:6px 0"><?= (int)$b['count'] ?></div>
            <div style="font-size:10.5px;color:#64748b"><?= e($b['label']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ═══ قسم جديد: الأصول المتكررة ═══ -->
<?php if ($recurring): ?>
<div class="bento" style="border-right:4px solid #ea580c">
    <h3><i class="fa-solid fa-repeat" style="color:#ea580c"></i> الأصول الأكثر تكراراً في الإبلاغ
        <span style="font-size:10.5px;color:var(--muted);font-weight:700;margin-right:8px">
            هذه الأصول تحتاج لتدخل جذري (صيانة وقائية أو استبدال)
        </span>
    </h3>
    <table class="rt">
        <tr>
            <th>الجهاز</th>
            <th>التاج</th>
            <th>عدد البلاغات</th>
            <th>محلول</th>
            <th>آخر بلاغ</th>
            <th>الحساسية</th>
        </tr>
        <?php foreach ($recurring as $r):
            $cls = $r['criticality_class'] ?: '_none';
            $cls_color = ['A'=>'#dc2626','B'=>'#f59e0b','C'=>'#10b981'][$cls] ?? '#94a3b8';
        ?>
        <tr>
            <td><a href="<?= BASE_URL ?>/assets/view.php?id=<?= (int)$r['id'] ?>" style="color:#0f172a;font-weight:800;text-decoration:none">
                <?= e(truncate($r['description'] ?: '—', 50)) ?></a></td>
            <td class="eng" style="color:#0e7490"><?= e($r['tag_number'] ?: '—') ?></td>
            <td><span style="background:#fff7ed;color:#9a3412;padding:3px 10px;border-radius:99px;font-weight:900;font-family:'Inter'"><?= (int)$r['total'] ?></span></td>
            <td class="eng"><?= (int)$r['done'] ?></td>
            <td class="eng" style="font-size:11px;color:#64748b"><?= e(date('Y-m-d', strtotime($r['last_complaint_at']))) ?></td>
            <td><span style="background:<?= e($cls_color) ?>22;color:<?= e($cls_color) ?>;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:800"><?= e($cls) ?></span></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endif; ?>

<div class="bento">
    <h3><i class="fa-solid fa-table-list"></i> الجدول التفصيلي
        <span style="font-size:10.5px;color:var(--muted);font-weight:700">
            (أحدث <?= count($detail) ?> بلاغاً ضمن الفلاتر — التصدير يشمل الكل)</span></h3>
    <div style="overflow-x:auto">
    <table class="rt">
        <tr><th>رقم البلاغ</th><th>النوع</th><th>القسم</th><th>الجهاز</th>
            <th>الأولوية</th><th>الحالة</th><th>تاريخ الرفع</th>
            <th>صافي المدة</th><th>المهلة</th><th>التقييم</th></tr>
        <?php foreach ($detail as $r):
            $sc = $ST_COLORS[$r['status']] ?? '#64748b'; ?>
        <tr>
            <td><a href="view.php?id=<?= (int)$r['id'] ?>" class="eng"
                style="color:#0e7490;font-weight:800;text-decoration:none">
                <?= e($r['request_number']) ?></a></td>
            <td><?= e($TYPES[$r['request_type']] ?? $r['request_type']) ?></td>
            <td><?= e($r['dept_name'] ?: '—') ?></td>
            <td><?= e(truncate($r['asset_desc'] ?: 'موقع', 32)) ?></td>
            <td><?= e($PRIOS[$r['priority']] ?? $r['priority']) ?></td>
            <td><span class="pill" style="background:<?= $sc ?>18;color:<?= $sc ?>">
                <?= e($STATS[$r['status']] ?? $r['status']) ?></span></td>
            <td class="eng"><?= e(date('Y-m-d H:i', strtotime($r['created_at']))) ?></td>
            <td><?= fmt_dur($r['net_sec'] !== null ? (int)$r['net_sec'] : null) ?></td>
            <td><?= $r['breached']
                ? '<span class="pill" style="background:#fef2f2;color:#dc2626">تجاوز</span>'
                : '<span class="pill" style="background:#f0fdf4;color:#16a34a">ملتزم</span>' ?></td>
            <td><?= $r['service_rating']
                ? '<b style="color:#f59e0b">★ ' . (int)$r['service_rating'] . '</b>' : '—' ?></td>
        </tr>
        <?php endforeach; if (!$detail): ?>
        <tr><td colspan="10" style="color:var(--muted);text-align:center;padding:24px">
            لا بلاغات مطابقة للفلاتر في هذه الفترة</td></tr>
        <?php endif; ?>
    </table>
    </div>
</div>

</div></main>
</div>

<script>
Chart.defaults.font.family = 'Tajawal';
Chart.defaults.font.weight = 700;

new Chart(document.getElementById('chStatus'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_map(
            fn($s) => $STATS[$s] ?? $s, array_keys($byStat)), JSON_UNESCAPED_UNICODE) ?>,
        datasets: [{
            data: <?= json_encode(array_values(array_map('intval', $byStat))) ?>,
            backgroundColor: <?= json_encode(array_map(
                fn($s) => $ST_COLORS[$s] ?? '#64748b', array_keys($byStat))) ?>,
            borderWidth: 2, borderColor: '#fff'
        }]
    },
    options: { plugins: { legend: { position: 'left', rtl: true,
        labels: { boxWidth: 12, font: { size: 11 } } } } }
});

new Chart(document.getElementById('chMonthly'), {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($monthly, 'ym')) ?>,
        datasets: [
            { label: 'مرفوعة', data: <?= json_encode(array_map(
                'intval', array_column($monthly, 'created_n'))) ?>,
              backgroundColor: '#0e749088', borderRadius: 6 },
            { label: 'محلولة', data: <?= json_encode(array_map(
                'intval', array_column($monthly, 'done_n'))) ?>,
              backgroundColor: '#16a34a88', borderRadius: 6 }
        ]
    },
    options: { plugins: { legend: { rtl: true } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>
</body>
</html>