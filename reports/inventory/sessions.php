<?php
/**
 * reports/inventory/sessions.php — جلسات الجرد
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.inventory.sessions');

$can_export  = can('reports.inventory.sessions', 'export');
$excel_mode  = report_excel_mode_active('reports.inventory.sessions');
$print_mode  = report_print_mode_active('reports.inventory.sessions');
$print_charts = report_print_charts_mode_active('reports.inventory.sessions');

$rtl = is_rtl();
$page_title = $rtl?'جلسات الجرد':'Inventory Sessions';
$active_nav = 'reports.inventory';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الجرد':'Inventory Reports','url'=>BASE_URL.'/reports/inventory/'],
    ['name'=>$rtl?'الجلسات':'Sessions'],
];

global $pdo;

$rows = $pdo->query("
    SELECT s.id, s.session_code, s.title, s.status, s.scope_type, s.start_date, s.end_date, s.operating_mode,
           (SELECT COUNT(*) FROM inventory_session_members WHERE session_id = s.id) AS members_n,
           (SELECT COUNT(*) FROM inventory_audits WHERE session_id = s.id) AS audit_n,
           (SELECT username FROM users WHERE id = s.created_by) AS created_by
    FROM inventory_sessions s
    ORDER BY s.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$active = count(array_filter($rows, fn($r) => in_array($r['status'], ['planning','active','review'])));
$completed = count(array_filter($rows, fn($r) => $r['status'] === 'completed'));
$total_audits = array_sum(array_column($rows, 'audit_n'));

$SESSION_AR = ['planning'=>'مخططة','active'=>'نشطة','review'=>'مراجعة','completed'=>'مكتملة','cancelled'=>'ملغاة'];
$SESSION_COLOR = ['planning'=>'#0ea5e9','active'=>'#16a34a','review'=>'#f59e0b','completed'=>'#475569','cancelled'=>'#94a3b8'];
$SCOPE_AR = ['all'=>'الكل','department'=>'قسم','asset_type'=>'نوع أصل','building'=>'مبنى','custom'=>'مخصص'];
$MODE_AR = ['collaborative'=>'تعاوني','split_by_member'=>'منفصل لكل عضو'];

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
        .hero { background:linear-gradient(135deg, #134e4a, #0d9488); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(13,148,136,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }

        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:13px 14px; }
        .stat .l { font-size:11px; color:#64748b; font-weight:700; }
        .stat .v { font-size:22px; font-weight:900; color:#0f172a; margin-top:2px; }
        .stat .s { font-size:11px; color:#94a3b8; font-weight:700; }
        .stat.ok { background:#f0fdf4; border-color:#bbf7d0; } .stat.ok .v { color:#16a34a; }
        .stat.info { background:#eff6ff; border-color:#bfdbfe; } .stat.info .v { color:#0ea5e9; }

        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#0d9488; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }

        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:11px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .code { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#0d9488; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/inventory/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-clipboard-list"></i></div>
        <div>
            <h1><?= $rtl?'جلسات الجرد':'Inventory Sessions' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.inventory.sessions') ?>
            </div>
            <p><?= $rtl?'كل جلسات الجرد: الحالة، النطاق، الأعضاء، عدد العمليات، التواريخ':'All inventory sessions: status, scope, members, audit count, dates' ?></p>
        </div>
        <div class="v"><?= $total ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي الجلسات':'Total Sessions' ?></div>
            <div class="v"><?= $total ?></div>
        </div>
        <div class="stat info">
            <div class="l"><?= $rtl?'النشطة':'Active' ?></div>
            <div class="v"><?= $active ?></div>
            <div class="s"><?= $rtl?'قيد التنفيذ':'in progress' ?></div>
        </div>
        <div class="stat ok">
            <div class="l"><?= $rtl?'المكتملة':'Completed' ?></div>
            <div class="v"><?= $completed ?></div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي العمليات':'Total Scans' ?></div>
            <div class="v"><?= number_format($total_audits) ?></div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-table ic"></i>
            <?= $rtl?'قائمة الجلسات':'Sessions List' ?>
            <span class="ct"><?= $total ?> <?= $rtl?'جلسة':'sessions' ?></span>
        </div>
        <?php if (!$rows): ?>
            <div class="empty"><?= $rtl?'لا توجد جلسات':'No sessions yet' ?></div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'الرمز':'Code' ?></th>
                    <th><?= $rtl?'العنوان':'Title' ?></th>
                    <th><?= $rtl?'الحالة':'Status' ?></th>
                    <th><?= $rtl?'النطاق':'Scope' ?></th>
                    <th><?= $rtl?'النمط':'Mode' ?></th>
                    <th><?= $rtl?'الأعضاء':'Members' ?></th>
                    <th><?= $rtl?'العمليات':'Audits' ?></th>
                    <th><?= $rtl?'البداية':'Start' ?></th>
                    <th><?= $rtl?'النهاية':'End' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $scol = $SESSION_COLOR[$r['status']] ?? '#475569';
            ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="code"><?= e($r['session_code']) ?></span></td>
                    <td><strong><?= e($r['title']) ?></strong></td>
                    <td><span class="pill" style="background:<?= $scol ?>22;color:<?= $scol ?>"><?= e($SESSION_AR[$r['status']] ?? $r['status']) ?></span></td>
                    <td><?= e($SCOPE_AR[$r['scope_type']] ?? $r['scope_type']) ?></td>
                    <td><span class="pill" style="background:#0d948822;color:#0d9488"><?= e($MODE_AR[$r['operating_mode']] ?? $r['operating_mode']) ?></span></td>
                    <td><?= (int)$r['members_n'] ?></td>
                    <td><strong><?= (int)$r['audit_n'] ?></strong></td>
                    <td><?= $r['start_date'] ? date('d/m/Y', strtotime($r['start_date'])) : '—' ?></td>
                    <td><?= $r['end_date'] ? date('d/m/Y', strtotime($r['end_date'])) : '—' ?></td>
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
