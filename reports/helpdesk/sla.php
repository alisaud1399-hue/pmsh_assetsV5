<?php
/**
 * reports/helpdesk/sla.php — SLA والتصعيد
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.helpdesk.sla');

$can_export  = can('reports.helpdesk.sla', 'export');
$excel_mode  = report_excel_mode_active('reports.helpdesk.sla');
$print_mode  = report_print_mode_active('reports.helpdesk.sla');
$print_charts = report_print_charts_mode_active('reports.helpdesk.sla');

$rtl = is_rtl();
$page_title = $rtl?'SLA والتصعيد':'SLA & Escalation';
$active_nav = 'reports.helpdesk';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التذاكر':'Helpdesk Reports','url'=>BASE_URL.'/reports/helpdesk/'],
    ['name'=>$rtl?'SLA والتصعيد':'SLA & Escalation'],
];

global $pdo;

// SLA Breaches
$breaches = $pdo->query("
    SELECT t.id, t.ticket_number, t.title, t.priority, t.status, t.sla_breached, t.escalation_count,
           TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) / 60 AS hrs_open,
           c.name_ar AS cat_name
    FROM helpdesk_tickets t
    LEFT JOIN helpdesk_categories c ON c.id = t.category_id
    WHERE t.sla_breached=1
    ORDER BY FIELD(t.priority,'critical','high','medium','low'), hrs_open DESC
")->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$total_breached = count($breaches);
$total_tickets = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets")->fetchColumn();
$total_escalated = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE escalation_count > 0")->fetchColumn();
$max_escalation = (int)$pdo->query("SELECT COALESCE(MAX(escalation_count), 0) FROM helpdesk_tickets")->fetchColumn();

// متوسط الرد الأول
$first_resp = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) / 60 AS hrs
    FROM helpdesk_tickets WHERE first_response_at IS NOT NULL
")->fetchColumn();
$first_resp_hrs = $first_resp !== null ? round((float)$first_resp, 1) : 0;

// متوسط الحل
$resolution = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(resolved_at, closed_at))) / 60 AS hrs
    FROM helpdesk_tickets WHERE resolved_at IS NOT NULL OR closed_at IS NOT NULL
")->fetchColumn();
$resolution_hrs = $resolution !== null ? round((float)$resolution, 1) : 0;

// توزيع حسب عدد التصعيدات
$esc_dist = $pdo->query("
    SELECT escalation_count, COUNT(*) AS n
    FROM helpdesk_tickets
    WHERE escalation_count > 0
    GROUP BY escalation_count
    ORDER BY escalation_count
")->fetchAll(PDO::FETCH_ASSOC);

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
        .hero { background:linear-gradient(135deg, #7f1d1d, #dc2626); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(220,38,38,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:13px 14px; }
        .stat .l { font-size:11px; color:#64748b; font-weight:700; }
        .stat .v { font-size:24px; font-weight:900; color:#0f172a; margin-top:2px; }
        .stat.bad { background:#fef2f2; border-color:#fecaca; } .stat.bad .v { color:#dc2626; }
        .stat.warn { background:#fffbeb; border-color:#fde68a; } .stat.warn .v { color:#d97706; }
        .stat.ok { background:#f0fdf4; border-color:#bbf7d0; } .stat.ok .v { color:#16a34a; }
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:12px; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#dc2626; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        tr.critical-row td { background:#fef2f2; }
        tr.critical-row:hover td { background:#fee2e2; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
        .empty i { font-size:32px; margin-bottom:8px; display:block; color:#16a34a; }
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
        <div class="hero-ico"><i class="fa-solid fa-stopwatch"></i></div>
        <div>
            <h1><?= $rtl?'SLA والتصعيد':'SLA & Escalation' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.helpdesk.sla') ?>
            </div>
            <p><?= $rtl?'تجاوزات SLA + التصعيدات + متوسط الرد الأول + متوسط الحل + التوزيع':'SLA breaches + escalations + avg first response + avg resolution + distribution' ?></p>
        </div>
        <div class="v"><?= $total_breached ?></div>
    </div>

    <div class="stats">
        <div class="stat bad">
            <div class="l"><?= $rtl?'تجاوزات SLA':'SLA Breached' ?></div>
            <div class="v"><?= $total_breached ?></div>
            <div class="l"><?= $rtl?'من ':'of '?><?= $total_tickets ?> <?= $rtl?'تذكرة':'tickets' ?></div>
        </div>
        <div class="stat warn">
            <div class="l"><?= $rtl?'تذاكر مُصعّدة':'Escalated' ?></div>
            <div class="v"><?= $total_escalated ?></div>
            <div class="l"><?= $rtl?'أعلى تصعيد: ':'max esc: '?><?= $max_escalation ?></div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'متوسط الرد الأول':'Avg 1st Response' ?></div>
            <div class="v"><?= $first_resp_hrs ?>h</div>
        </div>
        <div class="stat ok">
            <div class="l"><?= $rtl?'متوسط الحل':'Avg Resolution' ?></div>
            <div class="v"><?= $resolution_hrs ?>h</div>
        </div>
    </div>

    <?php if ($esc_dist): ?>
    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-arrow-up ic"></i>
            <?= $rtl?'توزيع التصعيدات':'Escalation Distribution' ?>
        </div>
        <div style="padding:14px 18px">
            <?php
            $max_e = max(1, max(array_column($esc_dist, 'n')));
            foreach ($esc_dist as $e):
                $pct = round($e['n'] / $max_e * 100);
            ?>
                <div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12.5px">
                    <span style="color:#475569;font-weight:700;min-width:120px"><?= $rtl?'التصعيد: ':'Escalations: '?><?= (int)$e['escalation_count'] ?></span>
                    <span style="flex:1;height:18px;background:#f1f5f9;border-radius:4px;overflow:hidden"><span style="display:block;height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg, #d97706, #dc2626)"></span></span>
                    <span style="min-width:32px;text-align:end;font-weight:800;color:#0f172a"><?= (int)$e['n'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-fire ic"></i>
            <?= $rtl?'التذاكر اللي تجاوزت SLA':'SLA-Breached Tickets' ?>
        </div>
        <?php if (!$breaches): ?>
            <div class="empty">
                <i class="fa-solid fa-circle-check"></i>
                <?= $rtl?'ممتاز! لا توجد تجاوزات SLA حاليًا':'Excellent! No SLA breaches right now' ?>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'التذكرة':'Ticket' ?></th>
                    <th><?= $rtl?'الأولوية':'Priority' ?></th>
                    <th><?= $rtl?'الحالة':'Status' ?></th>
                    <th><?= $rtl?'التصنيف':'Category' ?></th>
                    <th><?= $rtl?'التصعيدات':'Esc' ?></th>
                    <th><?= $rtl?'منذ':'Open For' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($breaches as $r):
                $pcol = $PRIORITY_COLOR[$r['priority']] ?? '#475569';
                $scol = $STATUS_COLOR[$r['status']] ?? '#475569';
                $rowCls = $r['priority'] === 'critical' ? 'critical-row' : '';
            ?>
                <tr class="<?= $rowCls ?>">
                    <td><?= (int)$r['id'] ?></td>
                    <td>
                        <strong><?= e($r['ticket_number']) ?></strong>
                        <div style="font-size:10.5px;color:#94a3b8;margin-top:2px"><?= e(truncate($r['title'] ?? '', 40)) ?></div>
                    </td>
                    <td><span class="pill" style="background:<?= $pcol ?>22;color:<?= $pcol ?>"><?= e($PRIORITY_AR[$r['priority']] ?? $r['priority']) ?></span></td>
                    <td><span class="pill" style="background:<?= $scol ?>22;color:<?= $scol ?>"><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></span></td>
                    <td><?= e($r['cat_name'] ?? '—') ?></td>
                    <td><strong style="color:<?= (int)$r['escalation_count'] > 0 ? '#dc2626' : '#475569' ?>"><?= (int)$r['escalation_count'] ?></strong></td>
                    <td><strong><?= round((float)$r['hrs_open'], 1) ?>h</strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</main>
</div>
</body>
</html>
