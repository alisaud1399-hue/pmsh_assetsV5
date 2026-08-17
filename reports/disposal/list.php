<?php
/**
 * reports/disposal/list.php — سجل التخلص الكامل
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.disposal.list');

$can_export  = can('reports.disposal.list', 'export');
$excel_mode  = report_excel_mode_active('reports.disposal.list');
$print_mode  = report_print_mode_active('reports.disposal.list');
$print_charts = report_print_charts_mode_active('reports.disposal.list');

$rtl = is_rtl();
$page_title = $rtl?'سجل التخلص':'Disposal Log';
$active_nav = 'reports.disposal';
$breadcrumb = [
    ['name'=>$rtl?'تقارير التخلص':'Disposal Reports','url'=>BASE_URL.'/reports/disposal/'],
    ['name'=>$rtl?'السجل':'Log'],
];

global $pdo;

$rows = $pdo->query("
    SELECT d.id, d.disposal_type, d.reason, d.disposal_date, d.disposal_value,
           d.committee_reference, d.decision_doc_number, d.created_at,
           a.tag_number, a.description,
           d.committee_chairman,
           u.username AS created_by
    FROM asset_disposals d
    LEFT JOIN assets a ON a.id = d.asset_id
    LEFT JOIN users u ON u.id = d.created_by
    ORDER BY d.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$total_value = array_sum(array_column($rows, 'disposal_value'));
$avg_value = $total > 0 ? $total_value / $total : 0;

$TYPE_AR = ['scrap'=>'تكهين','destroy'=>'إتلاف','sell'=>'بيع','transfer_out'=>'نقل خارجي'];
$TYPE_COLOR = ['scrap'=>'#f59e0b','destroy'=>'#dc2626','sell'=>'#16a34a','transfer_out'=>'#0ea5e9'];
$REASON_AR = ['obsolete'=>'قديم','damaged_beyond_repair'=>'تالف','end_of_life'=>'انتهى عمره','lost'=>'مفقود','replaced'=>'مُستبدل','other'=>'آخر'];

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
        .hero { background:linear-gradient(135deg, #1e1b4b, #5b21b6); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(124,58,237,.18); }
        .hero-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hero h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:13px; opacity:.92; margin:0; }
        .hero .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:10px; margin-bottom:14px; }
        .stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:13px 14px; }
        .stat .l { font-size:11px; color:#64748b; font-weight:700; }
        .stat .v { font-size:24px; font-weight:900; color:#0f172a; margin-top:2px; }
        .stat.ok .v { color:#16a34a; }
        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#7c3aed; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        table { width:100%; border-collapse:collapse; font-size:12px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:8px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .tg { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#7c3aed; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
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
        <div class="hero-ico"><i class="fa-solid fa-trash-can"></i></div>
        <div>
            <h1><?= $rtl?'سجل التخلص':'Disposal Log' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.disposal.list') ?>
            </div>
            <p><?= $rtl?'كل عمليات التخلص: النوع، السبب، التاريخ، القيمة، اللجنة، الوثائق':'All disposals: type, reason, date, value, committee, documents' ?></p>
        </div>
        <div class="v"><?= $total ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي العمليات':'Total Operations' ?></div>
            <div class="v"><?= $total ?></div>
        </div>
        <div class="stat ok">
            <div class="l"><?= $rtl?'القيمة الإجمالية':'Total Value' ?></div>
            <div class="v"><?= number_format($total_value, 0) ?></div>
            <div style="font-size:11px;color:#94a3b8;font-weight:700">SAR</div>
        </div>
        <div class="stat">
            <div class="l"><?= $rtl?'متوسط القيمة':'Avg Value' ?></div>
            <div class="v"><?= number_format($avg_value, 0) ?></div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-table ic"></i>
            <?= $rtl?'قائمة عمليات التخلص':'Disposal List' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty"><?= $rtl?'لا توجد عمليات':'No disposals yet' ?></div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'التاج':'Tag' ?></th>
                    <th><?= $rtl?'النوع':'Type' ?></th>
                    <th><?= $rtl?'السبب':'Reason' ?></th>
                    <th><?= $rtl?'التاريخ':'Date' ?></th>
                    <th><?= $rtl?'القيمة (SAR)':'Value' ?></th>
                    <th><?= $rtl?'مرجع اللجنة':'Committee' ?></th>
                    <th><?= $rtl?'الوثيقة':'Doc #' ?></th>
                    <th><?= $rtl?'الرئيس':'Chairman' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $tcol = $TYPE_COLOR[$r['disposal_type']] ?? '#475569';
            ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="tg"><?= e($r['tag_number'] ?? '—') ?></span></td>
                    <td><span class="pill" style="background:<?= $tcol ?>22;color:<?= $tcol ?>"><?= e($TYPE_AR[$r['disposal_type']] ?? $r['disposal_type']) ?></span></td>
                    <td><?= e($REASON_AR[$r['reason']] ?? $r['reason']) ?></td>
                    <td><?= $r['disposal_date'] ? date('d/m/Y', strtotime($r['disposal_date'])) : '—' ?></td>
                    <td><strong><?= $r['disposal_value'] ? number_format((float)$r['disposal_value']) : '—' ?></strong></td>
                    <td><?= e($r['committee_reference'] ?? '—') ?></td>
                    <td><?= e($r['decision_doc_number'] ?? '—') ?></td>
                    <td><?= e($r['committee_chairman'] ?? '—') ?></td>
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
