<?php
/**
 * reports/helpdesk/by_assignee.php — حسب المعالج
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.helpdesk.by_assignee');

$can_export  = can('reports.helpdesk.by_assignee', 'export');
$excel_mode  = report_excel_mode_active('reports.helpdesk.by_assignee');
$print_mode  = report_print_mode_active('reports.helpdesk.by_assignee');
$print_charts = report_print_charts_mode_active('reports.helpdesk.by_assignee');

$rtl = is_rtl();
$page_title = $rtl?'التذاكر حسب المعالج':'Tickets by Assignee';
$active_nav = 'reports.helpdesk';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التذاكر':'Helpdesk Reports','url'=>BASE_URL.'/reports/helpdesk/'],
    ['name'=>$rtl?'حسب المعالج':'By Assignee'],
];

global $pdo;

$rows = $pdo->query("
    SELECT u.id, u.username, u.full_name,
           COUNT(t.id) AS total,
           SUM(t.status IN ('new','in_review','awaiting_user')) AS open_n,
           SUM(t.status='closed') AS closed_n,
           SUM(t.priority='critical' AND t.status != 'closed') AS critical_n,
           SUM(t.sla_breached=1) AS breached,
           AVG(TIMESTAMPDIFF(MINUTE, t.created_at, COALESCE(t.resolved_at, t.closed_at))) / 60 AS avg_hrs
    FROM users u
    INNER JOIN helpdesk_tickets t ON t.assigned_to = u.id
    WHERE u.is_active=1
    GROUP BY u.id, u.username, u.full_name
    HAVING total > 0
    ORDER BY open_n DESC, total DESC
")->fetchAll(PDO::FETCH_ASSOC);

$grand_total = array_sum(array_column($rows, 'total'));
$grand_open = array_sum(array_column($rows, 'open_n'));
$max_open = max(1, max(array_column($rows, 'open_n') ?: [1]));

// recent for each assignee
$assignee_ids = array_column($rows, 'id');
$recent_by = [];
if ($assignee_ids) {
    $in = implode(',', array_map('intval', $assignee_ids));
    $r = $pdo->query("
        SELECT t.id, t.ticket_number, t.priority, t.status, t.assigned_to, t.title, t.created_at
        FROM helpdesk_tickets t
        WHERE t.assigned_to IN ($in)
        ORDER BY t.id DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($r as $rr) $recent_by[$rr['assigned_to']][] = $rr;
}

$PRIORITY_AR = ['critical'=>'حرجة','high'=>'عالية','medium'=>'متوسطة','low'=>'منخفضة'];
$PRIORITY_COLOR = ['critical'=>'#dc2626','high'=>'#f59e0b','medium'=>'#0ea5e9','low'=>'#1565C0'];
$STATUS_AR = ['new'=>'جديدة','in_review'=>'قيد المراجعة','awaiting_user'=>'بانتظار','closed'=>'مغلقة'];
$STATUS_COLOR = ['new'=>'#0ea5e9','in_review'=>'#7c3aed','awaiting_user'=>'#f59e0b','closed'=>'#16a34a'];

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
        .hero { background:linear-gradient(135deg, #78350f, #d97706); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(217,119,6,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .team-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(360px, 1fr)); gap:12px; }
        .tm { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .tm-h { display:flex; align-items:center; gap:10px; padding:12px 16px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .tm-avatar { width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg, #78350f, #d97706); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:14px; }
        .tm-name { font-size:13.5px; font-weight:900; color:#0f172a; }
        .tm-uname { font-size:11px; color:#94a3b8; font-weight:700; }
        .tm-h .badge { margin-inline-start:auto; background:#d9770622; color:#d97706; font-size:11px; font-weight:800; padding:3px 8px; border-radius:5px; }
        .tm-bar { height:5px; background:#f1f5f9; }
        .tm-bar > div { height:100%; background:linear-gradient(90deg, #f59e0b, #d97706); }
        .tm-body { padding:12px 16px; }
        .tm-stats { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-bottom:10px; }
        .tm-stats .mini { background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:6px 8px; text-align:center; }
        .tm-stats .mini .v { font-size:16px; font-weight:900; color:#0f172a; }
        .tm-stats .mini .l { font-size:10px; color:#64748b; font-weight:700; }
        .tm-recent { border-top:1px dashed #e2e8f0; padding-top:8px; margin-top:6px; }
        .tm-recent .r { display:flex; align-items:center; gap:6px; padding:4px 0; font-size:11.5px; }
        .tm-recent .r .n { color:#0f172a; font-weight:800; min-width:90px; }
        .tm-recent .r .d { color:#94a3b8; font-weight:700; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/helpdesk/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-user-gear"></i></div>
        <div>
            <h1><?= $rtl?'التذاكر حسب المعالج':'Tickets by Assignee' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.helpdesk.by_assignee') ?>
            </div>
            <p><?= $rtl?'حمل العمل لكل معالج: مفتوحة + مغلقة + حرجة + تجاوزات + متوسط الحل':'Per-assignee workload: open + closed + critical + breaches + avg resolution' ?></p>
        </div>
        <div class="v"><?= $grand_total ?></div>
    </div>

    <?php if (!$rows): ?>
        <div class="tm"><div class="empty"><?= $rtl?'لا يوجد معالجون بعد':'No assignees yet' ?></div></div>
    <?php else: ?>
        <div class="team-grid">
            <?php foreach ($rows as $r):
                $initials = mb_strtoupper(mb_substr($r['full_name'] ?? $r['username'], 0, 1, 'UTF-8'), 'UTF-8');
                $recent_list = $recent_by[$r['id']] ?? [];
                $load_pct = $max_open > 0 ? round($r['open_n'] / $max_open * 100) : 0;
            ?>
                <div class="tm">
                    <div class="tm-h">
                        <div class="tm-avatar"><?= e($initials) ?></div>
                        <div>
                            <div class="tm-name"><?= e($r['full_name'] ?: $r['username']) ?></div>
                            <div class="tm-uname">@<?= e($r['username']) ?></div>
                        </div>
                        <span class="badge"><?= (int)$r['open_n'] ?> <?= $rtl?'مفتوح':'open' ?></span>
                    </div>
                    <div class="tm-bar"><div style="width:<?= $load_pct ?>%"></div></div>
                    <div class="tm-body">
                        <div class="tm-stats">
                            <div class="mini"><div class="v"><?= (int)$r['total'] ?></div><div class="l"><?= $rtl?'إجمالي':'Total' ?></div></div>
                            <div class="mini"><div class="v" style="color:#0ea5e9"><?= (int)$r['open_n'] ?></div><div class="l"><?= $rtl?'مفتوح':'Open' ?></div></div>
                            <div class="mini"><div class="v" style="color:#16a34a"><?= (int)$r['closed_n'] ?></div><div class="l"><?= $rtl?'منجز':'Done' ?></div></div>
                        </div>

                        <?php if ((int)$r['critical_n'] > 0): ?>
                            <div style="margin-bottom:8px">
                                <span style="background:#fef2f2;color:#dc2626;padding:2px 7px;border-radius:5px;font-size:10.5px;font-weight:800">
                                    <i class="fa-solid fa-fire"></i> <?= (int)$r['critical_n'] ?> <?= $rtl?'حرجة مفتوحة':'critical open' ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ((int)$r['breached'] > 0): ?>
                            <div style="margin-bottom:8px">
                                <span style="background:#fffbeb;color:#d97706;padding:2px 7px;border-radius:5px;font-size:10.5px;font-weight:800">
                                    <i class="fa-solid fa-clock"></i> <?= (int)$r['breached'] ?> <?= $rtl?'تجاوز SLA':'SLA breached' ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if ($r['avg_hrs'] !== null): ?>
                            <div style="font-size:11.5px;color:#94a3b8;margin-bottom:8px">
                                <i class="fa-regular fa-clock"></i>
                                <?= $rtl?'متوسط الحل: ':'Avg resolution: '?><?= round((float)$r['avg_hrs'], 1) ?>h
                            </div>
                        <?php endif; ?>

                        <?php if ($recent_list): ?>
                            <div class="tm-recent">
                                <?php foreach (array_slice($recent_list, 0, 3) as $rr):
                                    $pcol = $PRIORITY_COLOR[$rr['priority']] ?? '#475569';
                                ?>
                                    <div class="r">
                                        <span class="n"><?= e($rr['ticket_number']) ?></span>
                                        <span class="pill" style="background:<?= $pcol ?>22;color:<?= $pcol ?>"><?= e($PRIORITY_AR[$rr['priority']] ?? $rr['priority']) ?></span>
                                        <span class="d"><?= e(truncate($rr['title'] ?? '', 30)) ?></span>
                                    </div>
                                <?php endforeach; ?>
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
