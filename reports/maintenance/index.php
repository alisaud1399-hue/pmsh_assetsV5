<?php
/**
 * reports/maintenance/index.php — مركز تقارير الصيانة
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.maintenance');

$can_export  = can('reports.maintenance', 'export');
$excel_mode  = report_excel_mode_active('reports.maintenance');
$print_mode  = report_print_mode_active('reports.maintenance');
$print_charts = report_print_charts_mode_active('reports.maintenance');

$rtl = is_rtl();
$page_title = $rtl?'مركز تقارير الصيانة':'Maintenance Reports Hub';
$active_nav = 'reports.maintenance';

global $pdo;

// 4 KPIs
$total_wo = (int)$pdo->query("SELECT COUNT(*) FROM complaint_work_orders")->fetchColumn();
$active_wo = (int)$pdo->query("SELECT COUNT(*) FROM complaint_work_orders WHERE status IN ('draft','sent_to_contractor','in_progress','pending_manager_approval')")->fetchColumn();
$completed_wo = (int)$pdo->query("SELECT COUNT(*) FROM complaint_work_orders WHERE status='completed'")->fetchColumn();
$pm_schedules = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1")->fetchColumn();
$pm_overdue = (int)$pdo->query("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due < CURDATE()")->fetchColumn();
$completion_rate = $total_wo > 0 ? round($completed_wo / $total_wo * 100, 1) : 0;

// متوسط وقت الإصلاح
$mttr = $pdo->query("
    SELECT AVG(DATEDIFF(COALESCE(actual_completion_date, CURDATE()), wo_date)) AS avg_days
    FROM complaint_work_orders
    WHERE wo_date IS NOT NULL
")->fetchColumn();
$avg_days = $mttr !== null ? round((float)$mttr, 1) : 0;

// Recent WO
$recent = $pdo->query("
    SELECT w.id, w.wo_number, w.wo_date, w.status, w.wo_type, w.final_status,
           c.request_number, c.priority,
           w.contractor_name
    FROM complaint_work_orders w
    LEFT JOIN complaints c ON c.id = w.complaint_id
    ORDER BY w.id DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// WO type distribution
$type_dist = $pdo->query("
    SELECT wo_type, COUNT(*) AS n
    FROM complaint_work_orders
    GROUP BY wo_type
")->fetchAll(PDO::FETCH_ASSOC);

$cards = [
    ['code'=>'maintenance.overview',     'icon'=>'chart-pie',      'title_ar'=>'ملخص الصيانة',          'title_en'=>'Maintenance Overview',     'desc_ar'=>'شامل: أوامر عمل + PM + MTTR + تفصيل حسب النوع والحالة',  'desc_en'=>'All: work orders + PM + MTTR + breakdown by type/status',  'kpi'=>"$total_wo WOs · $completion_rate% complete", 'color'=>'#0891b2'],
    ['code'=>'maintenance.work_orders', 'icon'=>'screwdriver-wrench','title_ar'=>'أوامر العمل',         'title_en'=>'Work Orders',             'desc_ar'=>'كل أوامر العمل: الحالة، المقاول، المهندس، ساعات العمل، البنود','desc_en'=>'All WOs: status, contractor, engineer, work hours, items',   'kpi'=>"$active_wo active · $completed_wo done", 'color'=>'#0ea5e9'],
    ['code'=>'maintenance.wo_by_status','icon'=>'list-check',    'title_ar'=>'حسب الحالة',           'title_en'=>'By Status',              'desc_ar'=>'توزيع أوامر العمل حسب الحالة (مسودة/مرسلة/قيد التنفيذ/مكتملة/ملغية) + الحالة النهائية', 'desc_en'=>'WO distribution by status + final status', 'kpi'=>"$total_wo total", 'color'=>'#7c3aed'],
    ['code'=>'maintenance.pm_schedules', 'icon'=>'calendar-check','title_ar'=>'الصيانة الوقائية (PM)', 'title_en'=>'Preventive Maintenance',  'desc_ar'=>'جداول PM: الدورة، آخر/قادم تنفيذ، المقاول، المتأخرة',         'desc_en'=>'PM schedules: cycle, last/next, contractor, overdue',         'kpi'=>"$pm_schedules active · $pm_overdue overdue", 'color'=>'#16a34a'],
    ['code'=>'maintenance.mttr',        'icon'=>'clock',         'title_ar'=>'متوسط وقت الإصلاح (MTTR)', 'title_en'=>'MTTR',                'desc_ar'=>'MTTR: من تاريخ فتح البلاغ حتى إكمال أمر العمل — حسب النوع/القسم/الأسبوع', 'desc_en'=>'MTTR: complaint open → WO complete — by type/dept/week',    'kpi'=>"$avg_days days avg", 'color'=>'#f59e0b'],
    ['code'=>'maintenance.contractors', 'icon'=>'building',      'title_ar'=>'شركات الصيانة',         'title_en'=>'Contractors',            'desc_ar'=>'شركات الصيانة: عدد الأوامر، نسبة الإنجاز، متوسط الأيام، التقييم',  'desc_en'=>'Contractors: WO count, completion rate, avg days, rating',     'kpi'=>"$total_wo WOs total", 'color'=>'#dc2626'],
];

$visible = array_values(array_filter($cards, fn($c) => can($c['code'], 'view')));

$STATUS_AR = ['draft'=>'مسودة','sent_to_contractor'=>'مرسلة للمقاول','in_progress'=>'قيد التنفيذ','pending_manager_approval'=>'بانتظار موافقة المدير','completed'=>'مكتملة','rejected_by_manager'=>'مرفوضة من المدير','cancelled'=>'ملغاة'];
$STATUS_COLOR = ['draft'=>'#94a3b8','sent_to_contractor'=>'#0ea5e9','in_progress'=>'#7c3aed','pending_manager_approval'=>'#f59e0b','completed'=>'#16a34a','rejected_by_manager'=>'#dc2626','cancelled'=>'#475569'];
$FINAL_AR = ['completed'=>'منجزة','working_need_parts'=>'قيد العمل (تحتاج قطع)','need_secondary_parts'=>'تحتاج قطع ثانوية','need_agent'=>'تحتاج وكيل','pending'=>'معلقة'];
$TYPE_AR = ['medical'=>'طبية','general'=>'عامة','it'=>'تقنية'];
$PRIORITY_COLOR = ['critical'=>'#dc2626','urgent'=>'#f59e0b','normal'=>'#1565C0'];

/* === Index/Hub Export === */
if ($print_mode) {
    $t = $rtl ? $page_title : $page_title;
    $s = $rtl ? 'قائمة بكل التقارير الفرعية' : 'List of all sub-reports';
    report_print_head($t, $s, ['التاريخ'=>date('Y-m-d'),'المستخدم'=>user_name()?:'-','المستشفى'=>get_setting('hospital_name','PMSH')]);
    $h_name = $rtl ? 'اسم التقرير' : 'Report Name';
    $h_desc = $rtl ? 'الوصف' : 'Description';
    $h_kpi = $rtl ? 'المؤشر' : 'KPI';
    $h_avail = $rtl ? 'متاح' : 'Available';
    echo '<table><thead><tr><th>'.htmlspecialchars($h_name).'</th><th>'.htmlspecialchars($h_desc).'</th><th>'.htmlspecialchars($h_kpi).'</th><th>'.htmlspecialchars($h_avail).'</th></tr></thead><tbody>';
    foreach ($reports as $r) {
        $avail = !empty($r['available']) ? ($rtl?'نعم':'Yes') : ($rtl?'قريباً':'Soon');
        $name = $rtl ? ($r['title_ar'] ?? '') : ($r['title_en'] ?? '');
        $desc = $rtl ? ($r['desc_ar'] ?? '') : ($r['desc_en'] ?? '');
        echo '<tr><td>'.htmlspecialchars($name).'</td><td>'.htmlspecialchars($desc).'</td><td>'.htmlspecialchars($r['kpi'] ?? '').'</td><td>'.htmlspecialchars($avail).'</td></tr>';
    }
    echo '</tbody></table>';
    report_print_foot();
}

if ($print_charts) {
    $t = $rtl ? $page_title : $page_title;
    $kpis_arr = [];
    if (!empty($stats)) {
        $kpis_arr = [
            ['v'=>number_format($stats['total'] ?? 0),'l'=>$rtl?'إجمالي':'Total'],
            ['v'=>number_format($stats['open'] ?? $stats['active'] ?? 0),'l'=>$rtl?'مفتوح':'Open'],
            ['v'=>number_format($stats['closed'] ?? $stats['resolved'] ?? 0),'l'=>$rtl?'مغلق':'Closed'],
            ['v'=>number_format($stats['critical'] ?? $stats['criticality_A'] ?? 0),'l'=>$rtl?'حرج':'Critical'],
        ];
    }
    report_print_charts_head($t, $kpis_arr);
    echo '<div class="pc-section"><h3>'.htmlspecialchars($rtl?'التقارير الفرعية':'Sub-reports').'</h3>';
    $items = [];
    foreach ($reports as $r) {
        $items[] = ['name'=>$rtl ? ($r['title_ar'] ?? '') : ($r['title_en'] ?? ''), 'value'=>(int)preg_replace('/\D/', '', $r['kpi'] ?? '0')];
    }
    report_print_bar_chart($items);
    echo '</div>';
    report_print_charts_foot();
}

if ($excel_mode) {
    $rows = [];
    $h_name = $rtl ? 'اسم التقرير' : 'Report Name';
    $h_desc = $rtl ? 'الوصف' : 'Description';
    $h_kpi = $rtl ? 'المؤشر' : 'KPI';
    $h_avail = $rtl ? 'متاح' : 'Available';
    foreach ($reports as $r) {
        $avail = !empty($r['available']) ? ($rtl?'نعم':'Yes') : ($rtl?'قريباً':'Soon');
        $rows[] = [$h_name=>($rtl ? ($r['title_ar'] ?? '') : ($r['title_en'] ?? '')), $h_desc=>($rtl ? ($r['desc_ar'] ?? '') : ($r['desc_en'] ?? '')), $h_kpi=>($r['kpi'] ?? ''), $h_avail=>$avail];
    }
    report_export_excel('reports_hub_'.date('Y-m-d').'.csv', [$h_name, $h_desc, $h_kpi, $h_avail], $rows, $page_title);
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
        .mn-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }
        .mn-hero { background:linear-gradient(135deg, #0c4a6e 0%, #0891b2 50%, #06b6d4 100%); color:#fff; border-radius:18px; padding:26px 32px; margin-bottom:16px; display:flex; align-items:center; gap:20px; box-shadow:0 10px 30px rgba(8,145,178,.25); position:relative; overflow:hidden; }
        .mn-hero::before { content:''; position:absolute; top:-50%; right:-10%; width:300px; height:300px; background:radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%); }
        .mn-hero-ico { width:64px; height:64px; background:rgba(255,255,255,.18); border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:28px; }
        .mn-hero h1 { font-size:24px; font-weight:900; margin:0 0 4px; }
        .mn-hero p { font-size:13px; opacity:.92; margin:0; }
        .mn-hero .mn-v { margin-inline-start:auto; font-size:36px; font-weight:900; opacity:.95; }

        .mn-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:18px; }
        .mn-stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:14px 16px; border-top:4px solid; }
        .mn-stat .mn-l { font-size:11.5px; color:#64748b; font-weight:700; }
        .mn-stat .mn-v { font-size:26px; font-weight:900; color:#0f172a; margin-top:2px; }
        .mn-stat .mn-s { font-size:11px; color:#94a3b8; font-weight:700; margin-top:2px; }
        .mn-stat.cyan  { border-top-color:#0891b2; }
        .mn-stat.blue  { border-top-color:#0ea5e9; }
        .mn-stat.green { border-top-color:#16a34a; }
        .mn-stat.amber { border-top-color:#f59e0b; }

        .mn-sec-title { font-size:15px; font-weight:900; color:#0f172a; margin:20px 0 10px; display:flex; align-items:center; gap:8px; }
        .mn-sec-title i { color:#0891b2; }

        .mn-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:12px; }
        .mn-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; transition:transform .2s, box-shadow .2s; }
        .mn-card:hover { transform:translateY(-3px); box-shadow:0 12px 24px rgba(15,23,42,.08); }
        .mn-card-h { display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .mn-card-ico { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; }
        .mn-card-t { font-size:14px; font-weight:900; color:#0f172a; margin:0; }
        .mn-card-e { font-size:11px; color:#94a3b8; font-weight:700; }
        .mn-card-b { padding:12px 16px; }
        .mn-card-d { font-size:12.5px; color:#475569; line-height:1.6; margin-bottom:8px; }
        .mn-card-k { background:#ecfeff; border:1px dashed #67e8f9; border-radius:6px; padding:6px 10px; font-size:11.5px; color:#0e7490; font-weight:800; text-align:center; }
        .mn-card-f { padding:10px 16px; border-top:1.5px solid #f1f5f9; }
        .mn-card-f a { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; background:#0891b2; color:#fff; border-radius:8px; text-decoration:none; font-weight:800; font-size:12px; }
        .mn-card-f a:hover { background:#0e7490; }

        .mn-recent { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; }
        .mn-recent-h { padding:13px 18px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; display:flex; align-items:center; gap:8px; }
        .mn-recent-h h3 { font-size:14px; font-weight:900; margin:0; }
        .mn-recent-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        .mn-item { display:flex; align-items:center; gap:10px; padding:10px 18px; border-bottom:1px solid #f1f5f9; font-size:12.5px; }
        .mn-item:last-child { border-bottom:0; }
        .mn-item .wo { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#0891b2; min-width:90px; }
        .mn-item .st { font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:5px; }
        .mn-item .sc { color:#64748b; flex:1; }
        .mn-item .us { margin-inline-start:auto; color:#94a3b8; font-size:11.5px; font-weight:700; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="mn-wrap">

    <div class="mn-hero">
        <div class="mn-hero-ico"><i class="fa-solid fa-screwdriver-wrench"></i></div>
        <div>
            <h1><?= $rtl?'مركز تقارير الصيانة':'Maintenance Reports Hub' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.maintenance') ?>
            </div>
            <p><?= $rtl?'كل ما يخص الصيانة: أوامر العمل، PM، MTTR، شركات الصيانة':'Maintenance operations: work orders, PM, MTTR, contractors' ?></p>
        </div>
        <div class="mn-v"><?= $total_wo ?></div>
    </div>

    <div class="mn-stats">
        <div class="mn-stat cyan">
            <div class="mn-l"><?= $rtl?'أوامر العمل':'Work Orders' ?></div>
            <div class="mn-v"><?= $total_wo ?></div>
            <div class="mn-s"><?= $completion_rate ?>% <?= $rtl?'مكتمل':'complete' ?></div>
        </div>
        <div class="mn-stat blue">
            <div class="mn-l"><?= $rtl?'النشطة':'Active' ?></div>
            <div class="mn-v"><?= $active_wo ?></div>
            <div class="mn-s"><?= $rtl?'قيد التنفيذ':'in progress' ?></div>
        </div>
        <div class="mn-stat green">
            <div class="mn-l"><?= $rtl?'المكتملة':'Completed' ?></div>
            <div class="mn-v"><?= $completed_wo ?></div>
        </div>
        <div class="mn-stat amber">
            <div class="mn-l"><?= $rtl?'PM متأخرة':'PM Overdue' ?></div>
            <div class="mn-v"><?= $pm_overdue ?></div>
            <div class="mn-s"><?= $rtl?'من ':'of '?><?= $pm_schedules ?> <?= $rtl?'نشطة':'active' ?></div>
        </div>
    </div>

    <?php if (!$visible): ?>
        <div class="mn-recent" style="padding:40px;text-align:center;color:#94a3b8">
            <i class="fa-solid fa-lock" style="font-size:32px;display:block;margin-bottom:8px"></i>
            <?= $rtl?'لا توجد تقارير متاحة لك':'No reports available for you' ?>
        </div>
    <?php else: ?>

    <div class="mn-sec-title"><i class="fa-solid fa-th-large"></i> <?= $rtl?'تقارير الصيانة':'Maintenance Reports' ?></div>
    <div class="mn-cards">
        <?php foreach ($visible as $c): ?>
            <div class="mn-card">
                <div class="mn-card-h">
                    <div class="mn-card-ico" style="background:<?= $c['color'] ?>"><i class="fa-solid fa-<?= $c['icon'] ?>"></i></div>
                    <div>
                        <h3 class="mn-card-t"><?= $rtl?$c['title_ar']:$c['title_en'] ?></h3>
                        <div class="mn-card-e"><?= $rtl?$c['title_en']:$c['title_ar'] ?></div>
                    </div>
                </div>
                <div class="mn-card-b">
                    <div class="mn-card-d"><?= $rtl?$c['desc_ar']:$c['desc_en'] ?></div>
                    <div class="mn-card-k"><?= $c['kpi'] ?></div>
                </div>
                <div class="mn-card-f">
                    <a href="<?= BASE_URL ?>/reports/maintenance/<?= str_replace('maintenance.', '', $c['code']) ?>.php">
                        <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i>
                        <?= $rtl?'فتح التقرير':'Open Report' ?>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mn-sec-title"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'آخر أوامر العمل':'Recent Work Orders' ?></div>
    <div class="mn-recent">
        <div class="mn-recent-h">
            <h3><?= $rtl?'آخر 8 أوامر عمل':'Last 8 Work Orders' ?></h3>
            <span class="ct"><?= count($recent) ?> <?= $rtl?'أمر':'orders' ?></span>
        </div>
        <?php if (!$recent): ?>
            <div style="padding:30px;text-align:center;color:#94a3b8"><?= $rtl?'لا توجد أوامر عمل':'No work orders yet' ?></div>
        <?php else: foreach ($recent as $r):
            $scol = $STATUS_COLOR[$r['status']] ?? '#475569';
            $pcol = $PRIORITY_COLOR[$r['priority'] ?? 'normal'] ?? '#475569';
        ?>
            <div class="mn-item">
                <span class="wo"><?= e($r['wo_number']) ?></span>
                <span class="st" style="background:<?= $scol ?>22;color:<?= $scol ?>"><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></span>
                <?php if ($r['priority']): ?>
                    <span class="st" style="background:<?= $pcol ?>22;color:<?= $pcol ?>"><?= e($r['priority']) ?></span>
                <?php endif; ?>
                <span class="sc"><?= e($r['contractor_name'] ?? '—') ?></span>
                <span class="us"><?= $r['wo_date'] ? date('d/m', strtotime($r['wo_date'])) : '—' ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($type_dist): ?>
    <div class="mn-sec-title"><i class="fa-solid fa-chart-pie"></i> <?= $rtl?'توزيع حسب النوع':'Distribution by Type' ?></div>
    <div class="mn-recent" style="padding:18px">
        <?php
        $max_t = max(array_column($type_dist, 'n'));
        $type_color = ['medical'=>'#dc2626','general'=>'#0891b2','it'=>'#7c3aed'];
        foreach ($type_dist as $t):
            $col = $type_color[$t['wo_type']] ?? '#475569';
            $pct = round($t['n'] / $max_t * 100);
        ?>
            <div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12.5px">
                <span style="color:#475569;font-weight:700;min-width:90px"><?= e($TYPE_AR[$t['wo_type']] ?? $t['wo_type']) ?></span>
                <span style="width:11px;height:11px;border-radius:3px;background:<?= $col ?>"></span>
                <span style="flex:1;height:18px;background:#f1f5f9;border-radius:4px;overflow:hidden"><span style="display:block;height:100%;width:<?= $pct ?>%;background:<?= $col ?>"></span></span>
                <span style="min-width:32px;text-align:end;font-weight:800;color:#0f172a"><?= (int)$t['n'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>
</main>
</div>
</body>
</html>
