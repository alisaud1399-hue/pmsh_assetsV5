<?php
/**
 * reports/inventory/audits.php — سجل عمليات الجرد
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.inventory.audits');

$can_export  = can('reports.inventory.audits', 'export');
$excel_mode  = report_excel_mode_active('reports.inventory.audits');
$print_mode  = report_print_mode_active('reports.inventory.audits');
$print_charts = report_print_charts_mode_active('reports.inventory.audits');

$rtl = is_rtl();
$page_title = $rtl?'سجل عمليات الجرد':'Audit Log';
$active_nav = 'reports.inventory';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الجرد':'Inventory Reports','url'=>BASE_URL.'/reports/inventory/'],
    ['name'=>$rtl?'سجل العمليات':'Audit Log'],
];

global $pdo;

$rows = $pdo->query("
    SELECT a.id, a.action, a.scanned_tag, a.scanned_serial, a.scan_method, a.match_method,
           a.condition_notes, a.audited_at,
           s.title AS session_title, s.session_code,
           u.username AS audited_by
    FROM inventory_audits a
    LEFT JOIN inventory_sessions s ON s.id = a.session_id
    LEFT JOIN users u ON u.id = a.audited_by
    ORDER BY a.id DESC
    LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$confirmed = count(array_filter($rows, fn($r) => $r['action'] === 'confirmed'));
$match_rate = $total > 0 ? round($confirmed / $total * 100, 1) : 0;

$ACTION_AR = ['confirmed'=>'مؤكد','location_changed'=>'تغيّر موقع','custody_changed'=>'تغيّر عهدة','condition_damaged'=>'تالف','missing'=>'مفقود','missing_disposed_previously'=>'مفقود (تخلص سابق)','missing_under_investigation'=>'مفقود (تحت التحقيق)','surplus'=>'زائد (غير مسجّل)','surplus_registered'=>'زائد (تم التسجيل)','reaudit_pending'=>'بانتظار إعادة الجرد'];
$ACTION_COLOR = ['confirmed'=>'#16a34a','location_changed'=>'#0ea5e9','custody_changed'=>'#7c3aed','condition_damaged'=>'#d97706','missing'=>'#dc2626','missing_disposed_previously'=>'#7f1d1d','missing_under_investigation'=>'#f59e0b','surplus'=>'#f59e0b','surplus_registered'=>'#16a34a','reaudit_pending'=>'#7c3aed'];
$METHOD_AR = ['camera'=>'كاميرا','manual'=>'يدوي','barcode_scanner'=>'قارئ باركود'];
$MATCH_AR = ['tag'=>'تاج','serial'=>'سيريال','manual_search'=>'بحث يدوي','not_found'=>'غير موجود'];

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
        .stat.ok .v { color:#16a34a; }

        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#0d9488; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }

        table { width:100%; border-collapse:collapse; font-size:12px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .tg { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#0d9488; font-size:11.5px; }
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
        <div class="hero-ico"><i class="fa-solid fa-check-double"></i></div>
        <div>
            <h1><?= $rtl?'سجل عمليات الجرد':'Audit Log' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.inventory.audits') ?>
            </div>
            <p><?= $rtl?'كل عمليات المسح: الإجراء، طريقة المسح، طريقة المطابقة، المستخدم، الجلسة':'Every scan: action, scan method, match method, user, session' ?></p>
        </div>
        <div class="v"><?= $total ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي العمليات':'Total Audits' ?></div>
            <div class="v"><?= $total ?></div>
        </div>
        <div class="stat ok">
            <div class="l"><?= $rtl?'مؤكدة':'Confirmed' ?></div>
            <div class="v"><?= $confirmed ?></div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'معدل المطابقة':'Match Rate' ?></div>
            <div class="v"><?= $match_rate ?>%</div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-list ic"></i>
            <?= $rtl?'آخر 200 عملية':'Last 200 Audits' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty"><?= $rtl?'لا توجد عمليات':'No audits yet' ?></div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'التاج/السيريال':'Tag/Serial' ?></th>
                    <th><?= $rtl?'الإجراء':'Action' ?></th>
                    <th><?= $rtl?'طريقة المسح':'Scan' ?></th>
                    <th><?= $rtl?'طريقة المطابقة':'Match' ?></th>
                    <th><?= $rtl?'الجلسة':'Session' ?></th>
                    <th><?= $rtl?'المستخدم':'User' ?></th>
                    <th><?= $rtl?'الوقت':'Time' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $acol = $ACTION_COLOR[$r['action']] ?? '#475569';
                $code = $r['scanned_tag'] ?: $r['scanned_serial'] ?: '—';
            ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="tg"><?= e($code) ?></span></td>
                    <td><span class="pill" style="background:<?= $acol ?>22;color:<?= $acol ?>"><?= e($ACTION_AR[$r['action']] ?? $r['action']) ?></span></td>
                    <td><span class="pill" style="background:#47556922;color:#475569"><?= e($METHOD_AR[$r['scan_method']] ?? $r['scan_method']) ?></span></td>
                    <td><?= e($MATCH_AR[$r['match_method']] ?? $r['match_method']) ?></td>
                    <td><?= e($r['session_title'] ?? '—') ?></td>
                    <td><?= e($r['audited_by'] ?? '—') ?></td>
                    <td><?= date('d/m H:i', strtotime($r['audited_at'])) ?></td>
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
