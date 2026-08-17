<?php
/**
 * reports/inventory/reaudit.php — طلبات إعادة الجرد
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.inventory.reaudit');

$can_export  = can('reports.inventory.reaudit', 'export');
$excel_mode  = report_excel_mode_active('reports.inventory.reaudit');
$print_mode  = report_print_mode_active('reports.inventory.reaudit');
$print_charts = report_print_charts_mode_active('reports.inventory.reaudit');

$rtl = is_rtl();
$page_title = $rtl?'طلبات إعادة الجرد':'Re-Audit Requests';
$active_nav = 'reports.inventory';
$breadcrumb = [
    ['name'=>$rtl?'تقارير الجرد':'Inventory Reports','url'=>BASE_URL.'/reports/inventory/'],
    ['name'=>$rtl?'إعادة الجرد':'Re-Audit'],
];

global $pdo;

$rows = $pdo->query("
    SELECT r.id, r.status, r.reason, r.decided_at, r.decision_note, r.created_at,
           a.action, a.scanned_tag, a.scanned_serial,
           s.title AS session_title, s.session_code,
           u_req.username AS requested_by,
           u_dec.username AS decided_by,
           au.scanned_tag AS orig_tag
    FROM inventory_reaudit_requests r
    LEFT JOIN inventory_audits a ON a.id = r.audit_id
    LEFT JOIN inventory_audits au ON au.id = r.audit_id
    LEFT JOIN inventory_sessions s ON s.id = r.session_id
    LEFT JOIN users u_req ON u_req.id = r.requested_by
    LEFT JOIN users u_dec ON u_dec.id = r.decided_by
    ORDER BY r.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
$pending = count(array_filter($rows, fn($r) => $r['status'] === 'pending'));
$approved = count(array_filter($rows, fn($r) => $r['status'] === 'approved'));
$rejected = count(array_filter($rows, fn($r) => $r['status'] === 'rejected'));

$STATUS_AR = ['pending'=>'معلقة','approved'=>'معتمدة','rejected'=>'مرفوضة'];
$STATUS_COLOR = ['pending'=>'#f59e0b','approved'=>'#16a34a','rejected'=>'#dc2626'];
$ACTION_AR = ['confirmed'=>'مؤكد','location_changed'=>'تغيّر موقع','custody_changed'=>'تغيّر عهدة','condition_damaged'=>'تالف','missing'=>'مفقود','missing_disposed_previously'=>'مفقود (تخلص سابق)','missing_under_investigation'=>'مفقود (تحت التحقيق)','surplus'=>'زائد','surplus_registered'=>'زائد (مسجّل)','reaudit_pending'=>'بانتظار إعادة الجرد'];

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
        .hero { background:linear-gradient(135deg, #4c1d95, #7c3aed); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(124,58,237,.18); }
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
        .stat.bad { background:#fef2f2; border-color:#fecaca; } .stat.bad .v { color:#dc2626; }

        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#7c3aed; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }

        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:10.5px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
        tr:hover td { background:#fafbff; }
        tr.pending td { background:#fffbeb; }
        tr.pending:hover td { background:#fef3c7; }
        .pill { display:inline-block; padding:2px 7px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .tg { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#7c3aed; }
        .empty { padding:30px; text-align:center; color:#94a3b8; }
        .empty i { font-size:32px; margin-bottom:8px; display:block; color:#16a34a; }
        .reason { background:#f8fafc; border-inline-start:3px solid #7c3aed; padding:4px 8px; border-radius:0 4px 4px 0; font-size:11.5px; color:#475569; max-width:280px; }
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
        <div class="hero-ico"><i class="fa-solid fa-rotate"></i></div>
        <div>
            <h1><?= $rtl?'طلبات إعادة الجرد':'Re-Audit Requests' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.inventory.reaudit') ?>
            </div>
            <p><?= $rtl?'الطلبات اللي رفعها أعضاء الجرد لإعادة فحص أصل + القرار (معلق/معتمد/مرفوض) + السبب':'Requests raised by inventory members to re-examine an asset + decision (pending/approved/rejected) + reason' ?></p>
        </div>
        <div class="v"><?= $total ?></div>
    </div>

    <div class="stats">
        <div class="stat">
            <div class="l"><?= $rtl?'إجمالي الطلبات':'Total Requests' ?></div>
            <div class="v"><?= $total ?></div>
        </div>
        <div class="stat warn">
            <div class="l"><?= $rtl?'معلقة':'Pending' ?></div>
            <div class="v"><?= $pending ?></div>
        </div>
        <div class="stat ok">
            <div class="l"><?= $rtl?'معتمدة':'Approved' ?></div>
            <div class="v"><?= $approved ?></div>
        </div>
        <div class="stat bad">
            <div class="l"><?= $rtl?'مرفوضة':'Rejected' ?></div>
            <div class="v"><?= $rejected ?></div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-list ic"></i>
            <?= $rtl?'قائمة الطلبات':'Requests List' ?>
        </div>
        <?php if (!$rows): ?>
            <div class="empty">
                <i class="fa-solid fa-circle-check"></i>
                <?= $rtl?'لا توجد طلبات إعادة جرد':'No re-audit requests' ?>
            </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'التاج/السيريال':'Tag/Serial' ?></th>
                    <th><?= $rtl?'الإجراء الأصلي':'Orig. Action' ?></th>
                    <th><?= $rtl?'السبب':'Reason' ?></th>
                    <th><?= $rtl?'الحالة':'Status' ?></th>
                    <th><?= $rtl?'طلبها':'Requested by' ?></th>
                    <th><?= $rtl?'قرار':'Decided by' ?></th>
                    <th><?= $rtl?'الوقت':'Time' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $scol = $STATUS_COLOR[$r['status']] ?? '#475569';
                $code = $r['orig_tag'] ?: $r['scanned_tag'] ?: $r['scanned_serial'] ?: '—';
                $rowCls = $r['status'] === 'pending' ? 'pending' : '';
            ?>
                <tr class="<?= $rowCls ?>">
                    <td><?= (int)$r['id'] ?></td>
                    <td><span class="tg"><?= e($code) ?></span></td>
                    <td><?= e($ACTION_AR[$r['action']] ?? $r['action']) ?></td>
                    <td><div class="reason"><?= e($r['reason'] ?? '—') ?></div></td>
                    <td><span class="pill" style="background:<?= $scol ?>22;color:<?= $scol ?>"><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></span></td>
                    <td><?= e($r['requested_by'] ?? '—') ?></td>
                    <td>
                        <?= e($r['decided_by'] ?? '—') ?>
                        <?php if ($r['decision_note']): ?>
                            <div style="font-size:10.5px;color:#94a3b8;margin-top:2px"><?= e($r['decision_note']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><?= date('d/m H:i', strtotime($r['created_at'])) ?></div>
                        <?php if ($r['decided_at']): ?>
                            <div style="font-size:10.5px;color:#94a3b8"><?= $rtl?'قرار: ':'decided: ' ?><?= date('d/m H:i', strtotime($r['decided_at'])) ?></div>
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
