<?php
/**
 * reports/complaints/by_department.php — البلاغات حسب القسم
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.complaints.by_department');

$can_export  = can('reports.complaints.by_department', 'export');
$excel_mode  = report_excel_mode_active('reports.complaints.by_department');
$print_mode  = report_print_mode_active('reports.complaints.by_department');
$print_charts = report_print_charts_mode_active('reports.complaints.by_department');

$rtl = is_rtl();
$page_title = $rtl?'البلاغات حسب القسم':'Complaints by Department';
$active_nav = 'reports.complaints';
$breadcrumb = [
    ['name'=>$rtl?'تقارير البلاغات':'Complaints Reports','url'=>BASE_URL.'/reports/complaints/'],
    ['name'=>$rtl?'حسب القسم':'By Department'],
];

// كل قسم + إحصاءات
$rows = $pdo->query("
    SELECT d.id, d.name AS dept_name,
           COUNT(c.id) AS total,
           SUM(c.status IN ('open','acknowledged','in_progress','stalled','escalated')) AS open_cnt,
           SUM(c.status = 'escalated') AS esc_cnt,
           SUM(c.status IN ('resolved','closed')) AS done_cnt,
           SUM(c.priority = 'critical') AS crit_cnt,
           AVG(TIMESTAMPDIFF(HOUR, c.created_at, COALESCE(c.resolved_at, c.closed_at))) AS avg_hrs
    FROM departments d
    LEFT JOIN complaints c ON c.dept_id = d.id
    GROUP BY d.id, d.name
    HAVING total > 0
    ORDER BY total DESC, open_cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

$grand_total = array_sum(array_column($rows, 'total'));
$grand_open = array_sum(array_column($rows, 'open_cnt'));
$grand_done = array_sum(array_column($rows, 'done_cnt'));
$max_total = max(1, max(array_column($rows, 'total')));

// تفصيل: داخل كل قسم، كم حالة لكل priority
$dept_ids = array_column($rows, 'id');
$details = [];
if ($dept_ids) {
    $in = implode(',', array_map('intval', $dept_ids));
    $det = $pdo->query("
        SELECT dept_id, status, priority, COUNT(*) AS cnt
        FROM complaints WHERE dept_id IN ($in)
        GROUP BY dept_id, status, priority
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($det as $d) {
        $details[$d['dept_id']][$d['status']][$d['priority']] = (int)$d['cnt'];
    }
}

$STATUS_AR = ['open'=>'مفتوحة','acknowledged'=>'مستلمة','in_progress'=>'قيد المعالجة','stalled'=>'متوقفة','escalated'=>'متصاعدة','resolved'=>'محلولة','closed'=>'مغلقة','cancelled'=>'ملغاة','rejected'=>'مرفوضة'];
$STATUS_COLOR = ['open'=>'#1565C0','acknowledged'=>'#0ea5e9','in_progress'=>'#7c3aed','stalled'=>'#d97706','escalated'=>'#dc2626','resolved'=>'#16a34a','closed'=>'#475569','cancelled'=>'#94a3b8','rejected'=>'#7f1d1d'];
$PRIORITY_AR = ['critical'=>'حرجة','urgent'=>'عاجلة','normal'=>'عادية'];

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
    <meta charset="UTF-8"><title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        body { font-family:'Tajawal',sans-serif; background:#f8fafc; }
        .container { max-width: 1280px; margin: 0 auto; padding: 18px; }
        .back { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:#fff; color:#475569; border:1px solid #e2e8f0; border-radius:8px; text-decoration:none; font-weight:700; font-size:12.5px; margin-bottom:12px; }
        .back:hover { background:#f1f5f9; }
        .hero { background:linear-gradient(135deg, #ea580c, #dc2626); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(220,38,38,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }

        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:14px 16px; }
        .stat .lbl { font-size:11.5px; color:#64748b; font-weight:700; }
        .stat .val { font-size:24px; font-weight:900; color:#0f172a; margin-top:2px; }
        .stat .val .u { font-size:13px; color:#94a3b8; font-weight:700; margin-inline-start:4px; }

        .dept-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(380px, 1fr)); gap:12px; }
        .dept { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .dept-h { display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .dept-h .nm { font-size:14px; font-weight:900; color:#0f172a; }
        .dept-h .badge { background:#ea580c22; color:#ea580c; font-size:11px; font-weight:800; padding:2px 7px; border-radius:5px; }
        .dept-h .pct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }

        .dept-bar { height:6px; background:#f1f5f9; }
        .dept-bar > div { height:100%; background:linear-gradient(90deg, #ea580c, #dc2626); transition:width .3s; }

        .dept-body { padding:12px 16px; }
        .dept-row { display:flex; align-items:center; gap:6px; padding:5px 0; font-size:12.5px; }
        .dept-row .lbl { color:#475569; font-weight:700; min-width:90px; }
        .dept-row .dots { display:flex; gap:3px; flex:1; }
        .dept-row .dot { width:11px; height:11px; border-radius:3px; }
        .dept-row .num { color:#0f172a; font-weight:800; min-width:24px; text-align:end; }

        .empty { background:#fff; border:1.5px dashed #cbd5e1; border-radius:12px; padding:40px; text-align:center; color:#94a3b8; }
        .pill { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10.5px; font-weight:800; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/complaints/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-building"></i></div>
        <div>
            <h1><?= $rtl?'البلاغات حسب القسم':'Complaints by Department' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.complaints.by_department') ?>
            </div>
            <p><?= $rtl?'توزيع البلاغات على الأقسام: مفتوحة/متصاعدة/مغلقة + تفصيل الأولوية لكل قسم':'Per-department distribution: open/escalated/closed + priority breakdown' ?></p>
        </div>
        <div class="v"><?= count($rows) ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="lbl"><?= $rtl?'إجمالي البلاغات':'Total Complaints' ?></div>
            <div class="val"><?= number_format($grand_total) ?></div>
        </div>
        <div class="stat">
            <div class="lbl"><?= $rtl?'المفتوحة':'Open' ?></div>
            <div class="val"><?= number_format($grand_open) ?><span class="u"><?= $grand_total?round($grand_open/$grand_total*100,0):0 ?>%</span></div>
        </div>
        <div class="stat">
            <div class="lbl"><?= $rtl?'المغلقة':'Closed' ?></div>
            <div class="val"><?= number_format($grand_done) ?><span class="u"><?= $grand_total?round($grand_done/$grand_total*100,0):0 ?>%</span></div>
        </div>
        <div class="stat">
            <div class="lbl"><?= $rtl?'الأقسام المشاركة':'Active Departments' ?></div>
            <div class="val"><?= count($rows) ?></div>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="empty">
            <i class="fa-solid fa-folder-open" style="font-size:32px;margin-bottom:8px;display:block"></i>
            <?= $rtl?'لا توجد بلاغات حتى الآن':'No complaints recorded yet' ?>
        </div>
    <?php else: ?>
        <div class="dept-grid">
            <?php foreach ($rows as $r):
                $pct = round($r['total'] / $max_total * 100);
                $det = $details[$r['id']] ?? [];
            ?>
                <div class="dept">
                    <div class="dept-h">
                        <span class="nm"><?= e($r['dept_name']) ?></span>
                        <span class="badge"><?= (int)$r['total'] ?> <?= $rtl?'بلاغ':'complaint' ?></span>
                        <span class="pct"><?= $pct ?>%</span>
                    </div>
                    <div class="dept-bar"><div style="width:<?= $pct ?>%"></div></div>
                    <div class="dept-body">
                        <?php
                        // عرض أهم الحالات فقط
                        $top = [];
                        foreach ($det as $st => $by_pr) {
                            $cnt = array_sum($by_pr);
                            if ($cnt > 0) $top[$st] = $cnt;
                        }
                        arsort($top);
                        $top = array_slice($top, 0, 4, true);
                        foreach ($top as $st => $cnt):
                            $color = $STATUS_COLOR[$st] ?? '#475569';
                        ?>
                            <div class="dept-row">
                                <span class="lbl"><?= e($STATUS_AR[$st] ?? $st) ?></span>
                                <span class="dot" style="background:<?= $color ?>"></span>
                                <span class="num"><?= $cnt ?></span>
                            </div>
                        <?php endforeach; ?>

                        <?php if ((int)$r['crit_cnt'] > 0): ?>
                            <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0">
                                <span class="pill" style="background:#dc262622;color:#dc2626">
                                    <i class="fa-solid fa-fire"></i> <?= (int)$r['crit_cnt'] ?> <?= $rtl?'حرجة':'critical' ?>
                                </span>
                                <?php if ((int)$r['esc_cnt'] > 0): ?>
                                    <span class="pill" style="background:#f59e0b22;color:#f59e0b;margin-inline-start:4px">
                                        <i class="fa-solid fa-arrow-up"></i> <?= (int)$r['esc_cnt'] ?> <?= $rtl?'متصاعدة':'escalated' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($r['avg_hrs'] !== null): ?>
                        <div style="margin-top:8px;font-size:11px;color:#94a3b8">
                            <i class="fa-regular fa-clock"></i>
                            <?= $rtl?'متوسط الحل: ':'Avg resolve: ' ?><?= round((float)$r['avg_hrs'], 0) ?>h
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</main>
</div>
</body>
</html>
