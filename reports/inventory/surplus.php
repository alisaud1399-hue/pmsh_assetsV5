<?php
/**
 * reports/inventory/surplus.php — الأصول الزائدة في الجرد
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.inventory.surplus');

$can_export  = can('reports.inventory.surplus', 'export');
$excel_mode  = report_excel_mode_active('reports.inventory.surplus');
$print_mode  = report_print_mode_active('reports.inventory.surplus');
$print_charts = report_print_charts_mode_active('reports.inventory.surplus');

$rtl = is_rtl();
$page_title = $rtl?'الأصول الزائدة في الجرد':'Surplus Assets';
$active_nav = 'reports.inventory';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الجرد':'Inventory Reports','url'=>BASE_URL.'/reports/inventory/'],
    ['name'=>$rtl?'الزائد':'Surplus'],
];

global $pdo;

$rows = $pdo->query("
    SELECT a.id, a.action, a.scanned_tag, a.scanned_serial, a.match_method,
           a.condition_notes, a.audited_at, a.session_id, a.photo_path,
           s.title AS session_title, s.session_code,
           u.username AS audited_by
    FROM inventory_audits a
    LEFT JOIN inventory_sessions s ON s.id = a.session_id
    LEFT JOIN users u ON u.id = a.audited_by
    WHERE a.action LIKE 'surplus%'
    ORDER BY a.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$registered = count(array_filter($rows, fn($r) => $r['action'] === 'surplus_registered'));
$unregistered = count(array_filter($rows, fn($r) => $r['action'] === 'surplus'));
$with_photo = count(array_filter($rows, fn($r) => !empty($r['photo_path'])));

$ACTION_AR = ['surplus'=>'زائد (غير مسجّل)','surplus_registered'=>'زائد (تم التسجيل)'];
$ACTION_COLOR = ['surplus'=>'#f59e0b','surplus_registered'=>'#16a34a'];

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

        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:13px 14px; }
        .stat .l { font-size:11px; color:#64748b; font-weight:700; }
        .stat .v { font-size:24px; font-weight:900; color:#0f172a; margin-top:2px; }
        .stat.warn { background:#fffbeb; border-color:#fde68a; } .stat.warn .v { color:#d97706; }
        .stat.ok { background:#f0fdf4; border-color:#bbf7d0; } .stat.ok .v { color:#16a34a; }

        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#d97706; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }

        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .tg { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#d97706; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
        .empty i { font-size:32px; margin-bottom:8px; display:block; color:#16a34a; }
        .photo { color:#0ea5e9; font-size:11px; }
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
        <div class="hero-ico"><i class="fa-solid fa-plus-circle"></i></div>
        <div>
            <h1><?= $rtl?'الأصول الزائدة في الجرد':'Surplus Assets' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.inventory.surplus') ?>
            </div>
            <p><?= $rtl?'الأصول اللي انمسحت بدون وجود سجل في الأصول: بانتظار التسجيل أو تم تسجيلها':'Assets scanned without a record in assets: pending registration or registered' ?></p>
        </div>
        <div class="v"><?= $total ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي الزائد':'Total Surplus' ?></div>
            <div class="v"><?= $total ?></div>
        </div>
        <div class="stat warn">
            <div class="l"><?= $rtl?'بانتظار التسجيل':'Pending' ?></div>
            <div class="v"><?= $unregistered ?></div>
        </div>
        <div class="stat ok">
            <div class="l"><?= $rtl?'تم التسجيل':'Registered' ?></div>
            <div class="v"><?= $registered ?></div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'مع صورة':'With Photo' ?></div>
            <div class="v" style="color:#0ea5e9"><?= $with_photo ?></div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-list ic"></i>
            <?= $rtl?'قائمة الزائد':'Surplus List' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty">
                <i class="fa-solid fa-circle-check"></i>
                <?= $rtl?'ممتاز! لا توجد أصول زائدة':'Excellent! No surplus assets' ?>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'التاج/السيريال':'Tag/Serial' ?></th>
                    <th><?= $rtl?'الحالة':'Status' ?></th>
                    <th><?= $rtl?'صورة':'Photo' ?></th>
                    <th><?= $rtl?'الجلسة':'Session' ?></th>
                    <th><?= $rtl?'المستخدم':'User' ?></th>
                    <th><?= $rtl?'ملاحظات':'Notes' ?></th>
                    <th><?= $rtl?'الوقت':'Time' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $acol = $ACTION_COLOR[$r['action']] ?? '#d97706';
                $code = $r['scanned_tag'] ?: $r['scanned_serial'] ?: '—';
            ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="tg"><?= e($code) ?></span></td>
                    <td><span class="pill" style="background:<?= $acol ?>22;color:<?= $acol ?>"><?= e($ACTION_AR[$r['action']] ?? $r['action']) ?></span></td>
                    <td>
                        <?php if (!empty($r['photo_path'])): ?>
                            <a href="<?= BASE_URL ?>/<?= e($r['photo_path']) ?>" target="_blank" class="photo"><i class="fa-solid fa-image"></i> <?= $rtl?'عرض':'view' ?></a>
                        <?php else: ?>
                            <span style="color:#cbd5e1">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['session_title'] ?? '—') ?></td>
                    <td><?= e($r['audited_by'] ?? '—') ?></td>
                    <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['condition_notes'] ?? '') ?>"><?= e($r['condition_notes'] ?? '—') ?></td>
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
