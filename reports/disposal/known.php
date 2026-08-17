<?php
/**
 * reports/disposal/known.php — التخلصات التاريخية (قبل النظام)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.disposal.known');

$can_export  = can('reports.disposal.known', 'export');
$excel_mode  = report_excel_mode_active('reports.disposal.known');
$print_mode  = report_print_mode_active('reports.disposal.known');
$print_charts = report_print_charts_mode_active('reports.disposal.known');

$rtl = is_rtl();
$page_title = $rtl?'التخلصات التاريخية':'Historical Disposals';
$active_nav = 'reports.disposal';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التخلص':'Disposal Reports','url'=>BASE_URL.'/reports/disposal/'],
    ['name'=>$rtl?'التاريخية':'Historical'],
];

global $pdo;

$rows = $pdo->query("
    SELECT k.id, k.tag_number, k.reference_doc, k.disposal_date, k.reason, k.notes, k.created_at,
           a.description,
           u.username AS created_by
    FROM known_disposals k
    LEFT JOIN assets a ON a.id = k.asset_id
    LEFT JOIN users u ON u.id = k.created_by
    ORDER BY k.disposal_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);

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
        .hero { background:linear-gradient(135deg, #1e293b, #475569); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(30,41,59,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#475569; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .tg { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#475569; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
        .banner { background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px 16px; margin-bottom:14px; display:flex; gap:10px; align-items:center; font-size:12.5px; color:#92400e; }
        .banner i { color:#d97706; font-size:18px; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">
    <a href="<?= BASE_URL ?>/reports/disposal/index.php" class="back"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'العودة للمركز':'Back to Hub' ?></a>

    <div class="hero">
        <div class="hero-ico"><i class="fa-solid fa-clock-rotate-left"></i></div>
        <div>
            <h1><?= $rtl?'التخلصات التاريخية':'Historical Disposals' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.disposal.known') ?>
            </div>
            <p><?= $rtl?'التخلصات اللي صارت قبل النظام (ورقي) — للمرجعية والتوثيق':'Pre-system disposals (paper-based) — for reference and documentation' ?></p>
        </div>
        <div class="v"><?= $total ?></div>
    </div>

    <div class="banner">
        <i class="fa-solid fa-circle-info"></i>
        <div>
            <?= $rtl?'هذه السجلات لأغراض مرجعية فقط. لا يتم تحديث جدول الأصول بناءً عليها. للتخلصات الحالية، استخدم نموذج 9+10 في إدارة الأصول.':'These records are for reference only. Assets table is not updated based on them. For current disposals, use Form 9+10 in Asset Management.' ?>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-list ic"></i>
            <?= $rtl?'قائمة التخلصات التاريخية':'Historical Disposal List' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty"><?= $rtl?'لا توجد سجلات تاريخية':'No historical records' ?></div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'التاج':'Tag' ?></th>
                    <th><?= $rtl?'الوصف':'Description' ?></th>
                    <th><?= $rtl?'تاريخ التخلص':'Disposal Date' ?></th>
                    <th><?= $rtl?'المرجع':'Reference' ?></th>
                    <th><?= $rtl?'السبب':'Reason' ?></th>
                    <th><?= $rtl?'ملاحظات':'Notes' ?></th>
                    <th><?= $rtl?'سُجّل بواسطة':'Recorded by' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="tg"><?= e($r['tag_number'] ?? '—') ?></span></td>
                    <td><?= e(truncate($r['description'] ?? '', 40)) ?></td>
                    <td><?= $r['disposal_date'] ? date('d/m/Y', strtotime($r['disposal_date'])) : '—' ?></td>
                    <td><?= e($r['reference_doc'] ?? '—') ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['reason'] ?? '') ?>"><?= e($r['reason'] ?? '—') ?></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['notes'] ?? '') ?>"><?= e($r['notes'] ?? '—') ?></td>
                    <td><?= e($r['created_by'] ?? '—') ?></td>
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
