<?php
/**
 * reports/complaints/sla_breaches.php — تجاوزات SLA
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.complaints.sla_breaches');

$can_export  = can('reports.complaints.sla_breaches', 'export');
$excel_mode  = report_excel_mode_active('reports.complaints.sla_breaches');
$print_mode  = report_print_mode_active('reports.complaints.sla_breaches');
$print_charts = report_print_charts_mode_active('reports.complaints.sla_breaches');

$rtl = is_rtl();
$page_title = $rtl?'تجاوزات SLA':'SLA Breaches';
$active_nav = 'reports.complaints';
$breadcrumb = [
    ['name'=>$rtl?'تقارير البلاغات':'Complaints Reports','url'=>BASE_URL.'/reports/complaints/'],
    ['name'=>$rtl?'تجاوزات SLA':'SLA Breaches'],
];

// SLA targets (hours) per priority — افتراض افتراضي
$SLA_TARGETS = ['critical'=>4, 'urgent'=>24, 'normal'=>72];

// البلاغات اللي تجاوزت SLA (لا تزال مفتوحة + مر عليها وقت أكثر من الـ target)
$breaches = $pdo->query("
    SELECT c.id, c.request_number, c.priority, c.status, c.dept_id,
           c.created_at, c.escalation_due_at, c.sla_breach_detected_at,
           d.name AS dept_name,
           u.username AS requested_by_name,
           TIMESTAMPDIFF(HOUR, c.created_at, NOW()) AS hours_open,
           (SELECT name FROM users WHERE id = c.escalated_by) AS escalated_by_name
    FROM complaints c
    LEFT JOIN departments d ON d.id = c.dept_id
    LEFT JOIN users u ON u.id = c.requested_by
    WHERE c.status IN ('open','acknowledged','in_progress','stalled','escalated')
    ORDER BY c.priority = 'critical' DESC, hours_open DESC
")->fetchAll(PDO::FETCH_ASSOC);

// فلترة حسب target
$breach_rows = [];
$at_risk = [];
foreach ($breaches as $b) {
    $target = $SLA_TARGETS[$b['priority']] ?? 72;
    if ((int)$b['hours_open'] > $target) {
        $b['target'] = $target;
        $b['overrun_pct'] = round(((int)$b['hours_open'] - $target) / $target * 100);
        $breach_rows[] = $b;
    } elseif ((int)$b['hours_open'] > $target * 0.75) {
        $b['target'] = $target;
        $b['overrun_pct'] = 0;
        $at_risk[] = $b;
    }
}

// إحصاءات
$total_breach = count($breach_rows);
$total_at_risk = count($at_risk);
$total_open = count($breaches);
$compliance_rate = $total_open > 0 ? round((1 - $total_breach / $total_open) * 100, 1) : 100;

$PRIORITY_AR = ['critical'=>'حرجة','urgent'=>'عاجلة','normal'=>'عادية'];
$PRIORITY_COLOR = ['critical'=>'#dc2626','urgent'=>'#f59e0b','normal'=>'#1565C0'];
$STATUS_AR = ['open'=>'مفتوحة','acknowledged'=>'مستلمة','in_progress'=>'قيد المعالجة','stalled'=>'متوقفة','escalated'=>'متصاعدة'];

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
        .stat.danger { background:#fef2f2; border-color:#fecaca; }
        .stat.danger .val { color:#dc2626; }
        .stat.warn { background:#fffbeb; border-color:#fde68a; }
        .stat.warn .val { color:#d97706; }
        .stat.ok { background:#f0fdf4; border-color:#bbf7d0; }
        .stat.ok .val { color:#16a34a; }

        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:12px; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#dc2626; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }

        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:11px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; }
        tr:hover td { background:#fafbff; }
        tr.critical td { background:#fef2f2; }
        tr.critical:hover td { background:#fee2e2; }
        tr.warn-row td { background:#fffbeb; }
        tr.warn-row:hover td { background:#fef3c7; }

        .pill { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .overrun { background:#dc2626; color:#fff; padding:1px 6px; border-radius:99px; font-size:10px; font-weight:800; }
        .empty { background:#fff; border:1.5px dashed #cbd5e1; border-radius:12px; padding:40px; text-align:center; color:#94a3b8; }
        .empty i { font-size:32px; margin-bottom:8px; display:block; color:#16a34a; }
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
        <div class="hero-ico"><i class="fa-solid fa-stopwatch"></i></div>
        <div>
            <h1><?= $rtl?'تجاوزات SLA':'SLA Breaches' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.complaints.sla_breaches') ?>
            </div>
            <p><?= $rtl?'البلاغات اللي تجاوزت الوقت المستهدف + اللي على وشك التجاوز':'Complaints exceeding target time + at-risk of breach' ?></p>
        </div>
        <div class="v"><?= $total_breach ?></div>
    </div>

    <div class="stats">
        <div class="stat <?= $total_breach>0?'danger':'ok' ?>">
            <div class="lbl"><?= $rtl?'تجاوزت الوقت':'Breached' ?></div>
            <div class="val"><?= $total_breach ?><span style="font-size:13px;color:#94a3b8;margin-inline-start:4px"><?= $rtl?'من':'of' ?> <?= $total_open ?></span></div>
        </div>
        <div class="stat warn">
            <div class="lbl"><?= $rtl?'على وشك التجاوز':'At Risk (>75%)' ?></div>
            <div class="val"><?= $total_at_risk ?></div>
        </div>
        <div class="stat ok">
            <div class="lbl"><?= $rtl?'معدل الالتزام':'Compliance Rate' ?></div>
            <div class="val"><?= $compliance_rate ?>%</div>
        </div>
        <div class="stat">
            <div class="lbl"><?= $rtl?'إجمالي المفتوح':'Total Open' ?></div>
            <div class="val"><?= $total_open ?></div>
        </div>
    </div>

    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-fire ic"></i>
            <?= $rtl?'البلاغات المتجاوزة':'Breached Complaints' ?>
            <span class="ct"><?= $total_breach ?> <?= $rtl?'تجاوز':'breach' ?> · <?= $rtl?'حدود: حرج 4س، عاجل 24س، عادي 72س':'Targets: crit 4h, urg 24h, norm 72h' ?></span>
        </div>
        <?php if (!$breach_rows): ?>
            <div class="empty">
                <i class="fa-solid fa-circle-check"></i>
                <?= $rtl?'ممتاز! لا توجد تجاوزات SLA حاليًا':'Excellent! No SLA breaches right now' ?>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?= $rtl?'الرقم':'Number' ?></th>
                        <th><?= $rtl?'القسم':'Dept' ?></th>
                        <th><?= $rtl?'الأولوية':'Priority' ?></th>
                        <th><?= $rtl?'الحالة':'Status' ?></th>
                        <th><?= $rtl?'منذ':'Open For' ?></th>
                        <th><?= $rtl?'الحد':'Target' ?></th>
                        <th><?= $rtl?'التجاوز':'Overrun' ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($breach_rows as $b):
                    $pcol = $PRIORITY_COLOR[$b['priority']] ?? '#475569';
                    $rowCls = $b['priority'] === 'critical' ? 'critical' : ($b['overrun_pct'] > 100 ? 'critical' : '');
                ?>
                    <tr class="<?= $rowCls ?>">
                        <td><?= (int)$b['id'] ?></td>
                        <td><strong><?= e($b['request_number']) ?></strong></td>
                        <td><?= e($b['dept_name'] ?? '—') ?></td>
                        <td><span class="pill" style="background:<?= $pcol ?>22;color:<?= $pcol ?>"><?= e($PRIORITY_AR[$b['priority']] ?? $b['priority']) ?></span></td>
                        <td><span class="pill" style="background:#64748b22;color:#64748b"><?= e($STATUS_AR[$b['status']] ?? $b['status']) ?></span></td>
                        <td><strong><?= (int)$b['hours_open'] ?>h</strong></td>
                        <td><?= (int)$b['target'] ?>h</td>
                        <td><span class="overrun">+<?= $b['overrun_pct'] ?>%</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($at_risk): ?>
    <div class="sec">
        <div class="sec-h">
            <i class="fa-solid fa-triangle-exclamation ic" style="color:#d97706"></i>
            <?= $rtl?'على وشك التجاوز (75%+)':'At Risk (>75% of target)' ?>
            <span class="ct"><?= $total_at_risk ?> <?= $rtl?'بلاغ':'ticket' ?></span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= $rtl?'الرقم':'Number' ?></th>
                    <th><?= $rtl?'القسم':'Dept' ?></th>
                    <th><?= $rtl?'الأولوية':'Priority' ?></th>
                    <th><?= $rtl?'منذ':'Open For' ?></th>
                    <th><?= $rtl?'الحد':'Target' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($at_risk as $b):
                $pcol = $PRIORITY_COLOR[$b['priority']] ?? '#475569';
            ?>
                <tr class="warn-row">
                    <td><?= (int)$b['id'] ?></td>
                    <td><strong><?= e($b['request_number']) ?></strong></td>
                    <td><?= e($b['dept_name'] ?? '—') ?></td>
                    <td><span class="pill" style="background:<?= $pcol ?>22;color:<?= $pcol ?>"><?= e($PRIORITY_AR[$b['priority']] ?? $b['priority']) ?></span></td>
                    <td><?= (int)$b['hours_open'] ?>h / <?= (int)$b['target'] ?>h</td>
                    <td><?= (int)$b['target'] ?>h</td>
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
