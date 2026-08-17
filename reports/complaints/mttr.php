<?php
/**
 * reports/complaints/mttr.php — متوسط وقت الحل (MTTR)
 * MTTR = Mean Time To Resolve (created_at → resolved_at)
 * يحسب عبر priority + department + week + type
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.complaints.mttr');

$can_export  = can('reports.complaints.mttr', 'export');
$excel_mode  = report_excel_mode_active('reports.complaints.mttr');
$print_mode  = report_print_mode_active('reports.complaints.mttr');
$print_charts = report_print_charts_mode_active('reports.complaints.mttr');

$rtl = is_rtl();
$page_title = $rtl?'متوسط وقت الحل (MTTR)':'Mean Time To Resolve (MTTR)';
$active_nav = 'reports.complaints';
$breadcrumb = [
    ['name'=>$rtl?'تقارير البلاغات':'Complaints Reports','url'=>BASE_URL.'/reports/complaints/'],
    ['name'=>$rtl?'MTTR':'MTTR'],
];

// إجمالي MTTR
$overall = $pdo->query("
    SELECT
        COUNT(*) AS n,
        AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60 AS avg_hrs,
        MIN(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60 AS min_hrs,
        MAX(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60 AS max_hrs,
        AVG(CASE WHEN priority='critical' THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) / 60 AS avg_crit,
        AVG(CASE WHEN priority='urgent' THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) / 60 AS avg_urg,
        AVG(CASE WHEN priority='normal' THEN TIMESTAMPDIFF(MINUTE, created_at, resolved_at) END) / 60 AS avg_norm
    FROM complaints
    WHERE resolved_at IS NOT NULL
")->fetch(PDO::FETCH_ASSOC);

// MTTR حسب القسم
$by_dept = $pdo->query("
    SELECT d.id, d.name, COUNT(c.id) AS n,
           AVG(TIMESTAMPDIFF(MINUTE, c.created_at, c.resolved_at)) / 60 AS avg_hrs,
           MIN(TIMESTAMPDIFF(MINUTE, c.created_at, c.resolved_at)) / 60 AS min_hrs,
           MAX(TIMESTAMPDIFF(MINUTE, c.created_at, c.resolved_at)) / 60 AS max_hrs
    FROM complaints c
    INNER JOIN departments d ON d.id = c.dept_id
    WHERE c.resolved_at IS NOT NULL
    GROUP BY d.id, d.name
    HAVING n > 0
    ORDER BY avg_hrs ASC
")->fetchAll(PDO::FETCH_ASSOC);

// MTTR حسب الأولوية
$by_priority = $pdo->query("
    SELECT priority, COUNT(*) AS n,
           AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60 AS avg_hrs
    FROM complaints
    WHERE resolved_at IS NOT NULL
    GROUP BY priority
    ORDER BY FIELD(priority,'critical','urgent','normal')
")->fetchAll(PDO::FETCH_ASSOC);

// MTTR حسب الأسبوع (آخر 12 أسبوع)
$by_week = $pdo->query("
    SELECT YEARWEEK(created_at, 3) AS yw,
           MIN(DATE(created_at)) AS week_start,
           COUNT(*) AS n,
           AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60 AS avg_hrs
    FROM complaints
    WHERE resolved_at IS NOT NULL
      AND created_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
    GROUP BY YEARWEEK(created_at, 3)
    ORDER BY yw ASC
")->fetchAll(PDO::FETCH_ASSOC);

// MTTR حسب النوع
$by_type = $pdo->query("
    SELECT request_type, general_type, COUNT(*) AS n,
           AVG(TIMESTAMPDIFF(MINUTE, created_at, resolved_at)) / 60 AS avg_hrs
    FROM complaints
    WHERE resolved_at IS NOT NULL
    GROUP BY request_type, general_type
    ORDER BY n DESC
")->fetchAll(PDO::FETCH_ASSOC);

// قيم مرجعية (ساعات) — افتراضية للأهداف
$TARGETS = ['critical'=>4, 'urgent'=>24, 'normal'=>72];

$PRIORITY_AR = ['critical'=>'حرجة','urgent'=>'عاجلة','normal'=>'عادية'];
$PRIORITY_COLOR = ['critical'=>'#dc2626','urgent'=>'#f59e0b','normal'=>'#1565C0'];
$TYPE_AR = ['medical'=>'طبية','it'=>'تقنية','general'=>'عامة','asset'=>'أصل','location'=>'موقع'];

function fmt_hrs(?float $v): string {
    if ($v === null) return '—';
    if ($v < 1) return round($v * 60, 0) . 'm';
    if ($v < 48) return round($v, 1) . 'h';
    return round($v / 24, 1) . 'd';
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
        .stat .sub { font-size:11px; color:#94a3b8; font-weight:700; margin-top:2px; }
        .stat.crit { background:#fef2f2; border-color:#fecaca; }
        .stat.crit .val { color:#dc2626; }
        .stat.urg { background:#fffbeb; border-color:#fde68a; }
        .stat.urg .val { color:#d97706; }

        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:12px; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#ea580c; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }

        .grid-2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:12px; padding:14px 18px; }

        .chart { padding:14px 18px; }
        .chart-row { display:flex; align-items:center; gap:8px; padding:5px 0; font-size:12.5px; }
        .chart-row .nm { color:#475569; font-weight:700; min-width:120px; }
        .chart-row .bar { flex:1; height:18px; background:#f1f5f9; border-radius:4px; position:relative; overflow:hidden; }
        .chart-row .bar > div { height:100%; transition:width .4s; }
        .chart-row .num { color:#0f172a; font-weight:800; min-width:48px; text-align:end; }

        .priority-row { padding:14px 18px; }
        .pri-card { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px; padding:12px 14px; }
        .pri-card.ok { background:#f0fdf4; border-color:#bbf7d0; }
        .pri-card.warn { background:#fffbeb; border-color:#fde68a; }
        .pri-card.bad { background:#fef2f2; border-color:#fecaca; }
        .pri-card .h { display:flex; align-items:center; gap:6px; font-size:13px; font-weight:800; }
        .pri-card .v { font-size:22px; font-weight:900; margin-top:2px; color:#0f172a; }
        .pri-card .t { font-size:11px; color:#64748b; font-weight:700; }
        .pri-card .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10px; font-weight:800; margin-top:4px; }

        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:11px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .empty { background:#fff; border:1.5px dashed #cbd5e1; border-radius:12px; padding:40px; text-align:center; color:#94a3b8; }
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
        <div class="hero-ico"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div>
            <h1><?= $rtl?'متوسط وقت الحل (MTTR)':'Mean Time To Resolve (MTTR)' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.complaints.mttr') ?>
            </div>
            <p><?= $rtl?'مدة الحل من لحظة الاستلام حتى الإغلاق — حسب الأولوية، القسم، الأسبوع، النوع':'Resolution time from creation to closure — by priority, dept, week, type' ?></p>
        </div>
        <div class="v"><?= fmt_hrs((float)($overall['avg_hrs'] ?? 0)) ?></div>
    </div>

    <?php if (!$overall || !$overall['n']): ?>
        <div class="empty">
            <i class="fa-solid fa-hourglass" style="font-size:32px;margin-bottom:8px;display:block"></i>
            <?= $rtl?'لا توجد بلاغات محلولة لقياس MTTR بعد':'No resolved complaints yet to measure MTTR' ?>
        </div>
    <?php else: ?>
        <div class="stats">
            <div class="stat">
                <div class="lbl"><?= $rtl?'متوسط MTTR العام':'Overall MTTR' ?></div>
                <div class="val"><?= fmt_hrs((float)$overall['avg_hrs']) ?></div>
                <div class="sub"><?= $rtl?'من ':'from '?> <?= (int)$overall['n'] ?> <?= $rtl?'بلاغ':'complaints' ?></div>
            </div>
            <div class="stat">
                <div class="lbl"><?= $rtl?'أسرع حل':'Fastest' ?></div>
                <div class="val" style="color:#16a34a"><?= fmt_hrs((float)$overall['min_hrs']) ?></div>
            </div>
            <div class="stat">
                <div class="lbl"><?= $rtl?'أبطأ حل':'Slowest' ?></div>
                <div class="val" style="color:#dc2626"><?= fmt_hrs((float)$overall['max_hrs']) ?></div>
            </div>
            <div class="stat crit">
                <div class="lbl"><?= $rtl?'متوسط الحرجة':'Avg Critical' ?></div>
                <div class="val"><?= fmt_hrs((float)($overall['avg_crit'] ?? 0)) ?></div>
                <div class="sub"><?= $rtl?'هدف: ≤ 4h':'Target: ≤ 4h' ?></div>
            </div>
            <div class="stat urg">
                <div class="lbl"><?= $rtl?'متوسط العاجلة':'Avg Urgent' ?></div>
                <div class="val"><?= fmt_hrs((float)($overall['avg_urg'] ?? 0)) ?></div>
                <div class="sub"><?= $rtl?'هدف: ≤ 24h':'Target: ≤ 24h' ?></div>
            </div>
        </div>

        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-stopwatch ic"></i>
                <?= $rtl?'MTTR حسب القسم':'MTTR by Department' ?>
                <span class="ct"><?= count($by_dept) ?> <?= $rtl?'قسم':'depts' ?></span>
            </div>
            <div class="chart">
                <?php
                $max_avg = max(1, max(array_column($by_dept, 'avg_hrs')));
                foreach ($by_dept as $r):
                    $pct = round($r['avg_hrs'] / $max_avg * 100);
                    // لون: أخضر ≤ نصف max، برتقالي ≤ 0.8 max، أحمر > 0.8 max
                    if ($r['avg_hrs'] <= $max_avg * 0.5) $col = '#16a34a';
                    elseif ($r['avg_hrs'] <= $max_avg * 0.8) $col = '#f59e0b';
                    else $col = '#dc2626';
                ?>
                    <div class="chart-row">
                        <span class="nm"><?= e($r['name']) ?> <span style="color:#94a3b8;font-size:10.5px">(<?= (int)$r['n'] ?>)</span></span>
                        <span class="bar"><div style="width:<?= $pct ?>%;background:<?= $col ?>"></div></span>
                        <span class="num"><?= fmt_hrs((float)$r['avg_hrs']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-flag ic"></i>
                <?= $rtl?'MTTR حسب الأولوية':'MTTR by Priority' ?>
                <span class="ct"><?= $rtl?'مقارنة بالأهداف':'vs targets' ?></span>
            </div>
            <div class="priority-row">
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px">
                    <?php foreach ($by_priority as $p):
                        $pcol = $PRIORITY_COLOR[$p['priority']] ?? '#475569';
                        $target = $TARGETS[$p['priority']] ?? 72;
                        $actual = (float)$p['avg_hrs'];
                        $status = $actual <= $target ? 'ok' : ($actual <= $target * 2 ? 'warn' : 'bad');
                    ?>
                        <div class="pri-card <?= $status ?>">
                            <div class="h" style="color:<?= $pcol ?>"><?= e($PRIORITY_AR[$p['priority']] ?? $p['priority']) ?></div>
                            <div class="v"><?= fmt_hrs($actual) ?></div>
                            <div class="t"><?= $rtl?'هدف: ≤ ':'Target: ≤ '?><?= $target ?>h · <?= (int)$p['n'] ?> <?= $rtl?'بلاغ':'tickets' ?></div>
                            <div>
                                <?php if ($status === 'ok'): ?>
                                    <span class="pill" style="background:#16a34622;color:#16a34a"><i class="fa-solid fa-check"></i> <?= $rtl?'التزام':'on target' ?></span>
                                <?php elseif ($status === 'warn'): ?>
                                    <span class="pill" style="background:#f59e0b22;color:#d97706"><i class="fa-solid fa-triangle-exclamation"></i> <?= $rtl?'تجاوز طفيف':'slight over' ?></span>
                                <?php else: ?>
                                    <span class="pill" style="background:#dc262622;color:#dc2626"><i class="fa-solid fa-fire"></i> <?= $rtl?'تجاوز كبير':'major over' ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php if ($by_week): ?>
        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-chart-line ic"></i>
                <?= $rtl?'اتجاه MTTR (آخر 12 أسبوع)':'MTTR Trend (last 12 weeks)' ?>
                <span class="ct"><?= count($by_week) ?> <?= $rtl?'أسبوع':'weeks' ?></span>
            </div>
            <div class="chart">
                <?php
                $max_w = max(1, max(array_column($by_week, 'avg_hrs')));
                foreach ($by_week as $w):
                    $pct = round($w['avg_hrs'] / $max_w * 100);
                    $col = $w['avg_hrs'] <= 24 ? '#16a34a' : ($w['avg_hrs'] <= 72 ? '#f59e0b' : '#dc2626');
                ?>
                    <div class="chart-row">
                        <span class="nm"><?= e(date('d/m', strtotime($w['week_start']))) ?> <span style="color:#94a3b8;font-size:10.5px">(<?= (int)$w['n'] ?>)</span></span>
                        <span class="bar"><div style="width:<?= $pct ?>%;background:<?= $col ?>"></div></span>
                        <span class="num"><?= fmt_hrs((float)$w['avg_hrs']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-tags ic"></i>
                <?= $rtl?'MTTR حسب النوع':'MTTR by Type' ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?= $rtl?'النوع':'Type' ?></th>
                        <th><?= $rtl?'التفصيل':'Detail' ?></th>
                        <th><?= $rtl?'العدد':'Count' ?></th>
                        <th><?= $rtl?'MTTR':'MTTR' ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($by_type as $r): ?>
                    <tr>
                        <td><strong><?= e($TYPE_AR[$r['request_type']] ?? $r['request_type']) ?></strong></td>
                        <td><?= e($TYPE_AR[$r['general_type']] ?? $r['general_type'] ?? '—') ?></td>
                        <td><?= (int)$r['n'] ?></td>
                        <td><strong><?= fmt_hrs((float)$r['avg_hrs']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
</main>
</div>
</body>
</html>
