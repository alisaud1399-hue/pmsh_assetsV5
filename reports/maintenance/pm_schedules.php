<?php
/**
 * reports/maintenance/pm_schedules.php — جداول الصيانة الوقائية
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.maintenance.pm_schedules');

$can_export  = can('reports.maintenance.pm_schedules', 'export');
$excel_mode  = report_excel_mode_active('reports.maintenance.pm_schedules');
$print_mode  = report_print_mode_active('reports.maintenance.pm_schedules');
$print_charts = report_print_charts_mode_active('reports.maintenance.pm_schedules');

$rtl = is_rtl();
$page_title = $rtl?'الصيانة الوقائية (PM)':'Preventive Maintenance';
$active_nav = 'reports.maintenance';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الصيانة':'Maintenance Reports','url'=>BASE_URL.'/reports/maintenance/'],
    ['name'=>$rtl?'الصيانة الوقائية':'PM Schedules'],
];

global $pdo;

$rows = $pdo->query("
    SELECT s.id, s.pm_type, s.cycle_days, s.last_completed, s.next_due, s.notify_lead_days, s.is_active, s.notes,
           a.tag_number, a.description,
           (SELECT full_name FROM users WHERE id = s.contractor_id LIMIT 1) AS contractor_name
    FROM pm_schedules s
    LEFT JOIN assets a ON a.id = s.asset_id
    ORDER BY s.is_active DESC, s.next_due ASC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$active = count(array_filter($rows, fn($r) => $r['is_active'] == 1));
$overdue = count(array_filter($rows, fn($r) => $r['is_active'] == 1 && $r['next_due'] && strtotime($r['next_due']) < strtotime('today')));
$due_soon = count(array_filter($rows, fn($r) => $r['is_active'] == 1 && $r['next_due'] && strtotime($r['next_due']) >= strtotime('today') && strtotime($r['next_due']) <= strtotime('+30 days')));

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
        .hero { background:linear-gradient(135deg, #14532d, #16a34a); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(22,163,74,.18); }
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
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#16a34a; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        tr.overdue td { background:#fef2f2; }
        tr.overdue:hover td { background:#fee2e2; }
        tr.due-soon td { background:#fffbeb; }
        tr.due-soon:hover td { background:#fef3c7; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .tg { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#16a34a; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/maintenance/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-calendar-check"></i></div>
        <div>
            <h1><?= $rtl?'الصيانة الوقائية (PM)':'Preventive Maintenance' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.maintenance.pm_schedules') ?>
            </div>
            <p><?= $rtl?'جداول الصيانة الوقائية: الدورة، آخر/قادم تنفيذ، المقاول، الأصول، التأخر':'PM schedules: cycle, last/next, contractor, assets, overdue tracking' ?></p>
        </div>
        <div class="v"><?= $active ?></div>
    </div>

    <div class="stats">
        <div class="stat ok">
            <div class="l"><?= $rtl?'نشطة':'Active' ?></div>
            <div class="v"><?= $active ?></div>
        </div>
        <div class="stat bad">
            <div class="l"><?= $rtl?'متأخرة':'Overdue' ?></div>
            <div class="v"><?= $overdue ?></div>
        </div>
        <div class="stat warn">
            <div class="l"><?= $rtl?'خلال 30 يوم':'Due in 30d' ?></div>
            <div class="v"><?= $due_soon ?></div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي':'Total' ?></div>
            <div class="v"><?= $total ?></div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-list ic"></i>
            <?= $rtl?'قائمة جداول PM':'PM Schedules List' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty"><?= $rtl?'لا توجد جداول PM':'No PM schedules yet' ?></div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'الأصل':'Asset' ?></th>
                    <th><?= $rtl?'نوع PM':'PM Type' ?></th>
                    <th><?= $rtl?'الدورة (يوم)':'Cycle (days)' ?></th>
                    <th><?= $rtl?'آخر تنفيذ':'Last Done' ?></th>
                    <th><?= $rtl?'القادم':'Next Due' ?></th>
                    <th><?= $rtl?'المقاول':'Contractor' ?></th>
                    <th><?= $rtl?'الحالة':'Status' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $isOverdue = $r['is_active'] && $r['next_due'] && strtotime($r['next_due']) < strtotime('today');
                $isDueSoon = $r['is_active'] && !$isOverdue && $r['next_due'] && strtotime($r['next_due']) <= strtotime('+30 days');
                $rowCls = $isOverdue ? 'overdue' : ($isDueSoon ? 'due-soon' : '');
            ?>
                <tr class="<?= $rowCls ?>">
                    <td><?= (int)$r['id'] ?></td>
                    <td>
                        <?php if ($r['tag_number']): ?>
                            <span class="tg"><?= e($r['tag_number']) ?></span>
                            <div style="font-size:10.5px;color:#94a3b8;margin-top:2px"><?= e(truncate($r['description'] ?? '', 40)) ?></div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><strong><?= e($r['pm_type'] ?? '—') ?></strong></td>
                    <td><?= (int)$r['cycle_days'] ?>d</td>
                    <td><?= $r['last_completed'] ? date('d/m/Y', strtotime($r['last_completed'])) : '—' ?></td>
                    <td>
                        <?= $r['next_due'] ? date('d/m/Y', strtotime($r['next_due'])) : '—' ?>
                        <?php if ($isOverdue): ?>
                            <span class="pill" style="background:#dc262622;color:#dc2626"><?= floor((time() - strtotime($r['next_due'])) / 86400) ?>d <?= $rtl?'متأخر':'late' ?></span>
                        <?php elseif ($isDueSoon): ?>
                            <span class="pill" style="background:#f59e0b22;color:#d97706"><?= floor((strtotime($r['next_due']) - time()) / 86400) ?>d</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['contractor_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($r['is_active']): ?>
                            <span class="pill" style="background:#16a34a22;color:#16a34a"><?= $rtl?'نشط':'Active' ?></span>
                        <?php else: ?>
                            <span class="pill" style="background:#94a3b822;color:#94a3b8"><?= $rtl?'متوقف':'Inactive' ?></span>
                        <?php endif; ?>
                    </td>
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
