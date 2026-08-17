<?php
/**
 * reports/helpdesk/index.php — مركز تقارير التذاكر
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.helpdesk');

$can_export  = can('reports.helpdesk', 'export');
$excel_mode  = report_excel_mode_active('reports.helpdesk');
$print_mode  = report_print_mode_active('reports.helpdesk');
$print_charts = report_print_charts_mode_active('reports.helpdesk');

$rtl = is_rtl();
$page_title = $rtl?'مركز تقارير التذاكر':'Helpdesk Reports Hub';
$active_nav = 'reports.helpdesk';

global $pdo;

// KPIs
$total = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets")->fetchColumn();
$open = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE status IN ('new','in_review','awaiting_user')")->fetchColumn();
$in_review = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE status='in_review'")->fetchColumn();
$closed = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE status='closed'")->fetchColumn();
$critical = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE priority='critical' AND status != 'closed'")->fetchColumn();
$sla_breached = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_tickets WHERE sla_breached=1")->fetchColumn();
$total_messages = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_messages")->fetchColumn();
$total_categories = (int)$pdo->query("SELECT COUNT(*) FROM helpdesk_categories WHERE is_active=1")->fetchColumn();

$first_response = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) / 60 AS avg_hrs
    FROM helpdesk_tickets
    WHERE first_response_at IS NOT NULL
")->fetchColumn();
$first_response_hrs = $first_response !== null ? round((float)$first_response, 1) : 0;

$resolution = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(resolved_at, closed_at))) / 60 AS avg_hrs
    FROM helpdesk_tickets
    WHERE resolved_at IS NOT NULL OR closed_at IS NOT NULL
")->fetchColumn();
$resolution_hrs = $resolution !== null ? round((float)$resolution, 1) : 0;

// توزيع التصنيفات
$cat_dist = $pdo->query("
    SELECT c.id, c.name_ar, COUNT(t.id) AS n
    FROM helpdesk_categories c
    LEFT JOIN helpdesk_tickets t ON t.category_id = c.id
    WHERE c.is_active=1
    GROUP BY c.id, c.name_ar
    HAVING n > 0
    ORDER BY n DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Recent tickets
$recent = $pdo->query("
    SELECT t.id, t.ticket_number, t.title, t.priority, t.status, t.created_at, t.last_message_at,
           c.name_ar AS cat_name,
           u.username AS created_by
    FROM helpdesk_tickets t
    LEFT JOIN helpdesk_categories c ON c.id = t.category_id
    LEFT JOIN users u ON u.id = t.created_by
    ORDER BY t.id DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$cards = [
    ['code'=>'helpdesk.overview',     'icon'=>'chart-pie',  'title_ar'=>'ملخص التذاكر',          'title_en'=>'Ticket Overview',     'desc_ar'=>'شامل: عدد + مفتوحة + حالة + توزيع + اتجاه',     'desc_en'=>'All: count + open + status + distribution + trend',  'kpi'=>"$total tickets · $open open", 'color'=>'#0ea5e9'],
    ['code'=>'helpdesk.by_status',    'icon'=>'list-check', 'title_ar'=>'حسب الحالة',            'title_en'=>'By Status',           'desc_ar'=>'توزيع التذاكر حسب الحالة (جديدة/قيد المراجعة/بانتظار/مغلقة) + متوسط الأوقات', 'desc_en'=>'Distribution by status (new/in_review/awaiting/closed) + avg times', 'kpi'=>"$in_review in review · $closed closed", 'color'=>'#7c3aed'],
    ['code'=>'helpdesk.sla',          'icon'=>'stopwatch',  'title_ar'=>'SLA والتصعيد',          'title_en'=>'SLA & Escalation',    'desc_ar'=>'تجاوزات SLA + التصعيدات + متوسط وقت الرد الأول + متوسط الحل',     'desc_en'=>'SLA breaches + escalations + avg first response + avg resolution',  'kpi'=>"$sla_breached breached · ${first_response_hrs}h first", 'color'=>'#dc2626'],
    ['code'=>'helpdesk.by_category',  'icon'=>'sitemap',   'title_ar'=>'حسب التصنيف',           'title_en'=>'By Category',         'desc_ar'=>'توزيع التذاكر على التصنيفات الفرعية + الأوقات + SLA',              'desc_en'=>'Per-category distribution + times + SLA',                          'kpi'=>"$total_categories categories", 'color'=>'#16a34a'],
    ['code'=>'helpdesk.by_assignee',  'icon'=>'user-gear',  'title_ar'=>'حسب المعالج',           'title_en'=>'By Assignee',         'desc_ar'=>'حمل العمل: المعالج + عدد التذاكر + متوسط الحل + الحرجة',          'desc_en'=>'Workload: assignee + ticket count + avg resolution + critical',    'kpi'=>"$open open", 'color'=>'#f59e0b'],
];

$visible = array_values(array_filter($cards, fn($c) => can($c['code'], 'view')));

$STATUS_AR = ['new'=>'جديدة','in_review'=>'قيد المراجعة','awaiting_user'=>'بانتظار المستخدم','closed'=>'مغلقة'];
$STATUS_COLOR = ['new'=>'#0ea5e9','in_review'=>'#7c3aed','awaiting_user'=>'#f59e0b','closed'=>'#16a34a'];
$PRIORITY_AR = ['low'=>'منخفضة','medium'=>'متوسطة','high'=>'عالية','critical'=>'حرجة'];
$PRIORITY_COLOR = ['low'=>'#1565C0','medium'=>'#0ea5e9','high'=>'#f59e0b','critical'=>'#dc2626'];

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
        .hd-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }
        .hd-hero { background:linear-gradient(135deg, #0c4a6e 0%, #0e7490 50%, #0ea5e9 100%); color:#fff; border-radius:18px; padding:26px 32px; margin-bottom:16px; display:flex; align-items:center; gap:20px; box-shadow:0 10px 30px rgba(14,165,233,.25); position:relative; overflow:hidden; }
        .hd-hero::before { content:''; position:absolute; top:-50%; right:-10%; width:300px; height:300px; background:radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%); }
        .hd-hero-ico { width:64px; height:64px; background:rgba(255,255,255,.18); border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:28px; }
        .hd-hero h1 { font-size:24px; font-weight:900; margin:0 0 4px; }
        .hd-hero p { font-size:13px; opacity:.92; margin:0; }
        .hd-hero .hd-v { margin-inline-start:auto; font-size:36px; font-weight:900; opacity:.95; }

        .hd-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:18px; }
        .hd-stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:14px 16px; border-top:4px solid; }
        .hd-stat .hd-l { font-size:11.5px; color:#64748b; font-weight:700; }
        .hd-stat .hd-v { font-size:26px; font-weight:900; color:#0f172a; margin-top:2px; }
        .hd-stat .hd-s { font-size:11px; color:#94a3b8; font-weight:700; margin-top:2px; }
        .hd-stat.blue  { border-top-color:#0ea5e9; }
        .hd-stat.green { border-top-color:#16a34a; }
        .hd-stat.purple{ border-top-color:#7c3aed; }
        .hd-stat.red   { border-top-color:#dc2626; }

        .hd-sec-title { font-size:15px; font-weight:900; color:#0f172a; margin:20px 0 10px; display:flex; align-items:center; gap:8px; }
        .hd-sec-title i { color:#0ea5e9; }

        .hd-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:12px; }
        .hd-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; transition:transform .2s, box-shadow .2s; }
        .hd-card:hover { transform:translateY(-3px); box-shadow:0 12px 24px rgba(15,23,42,.08); }
        .hd-card-h { display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .hd-card-ico { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; }
        .hd-card-t { font-size:14px; font-weight:900; color:#0f172a; margin:0; }
        .hd-card-e { font-size:11px; color:#94a3b8; font-weight:700; }
        .hd-card-b { padding:12px 16px; }
        .hd-card-d { font-size:12.5px; color:#475569; line-height:1.6; margin-bottom:8px; }
        .hd-card-k { background:#eff6ff; border:1px dashed #93c5fd; border-radius:6px; padding:6px 10px; font-size:11.5px; color:#1e40af; font-weight:800; text-align:center; }
        .hd-card-f { padding:10px 16px; border-top:1.5px solid #f1f5f9; }
        .hd-card-f a { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; background:#0ea5e9; color:#fff; border-radius:8px; text-decoration:none; font-weight:800; font-size:12px; }
        .hd-card-f a:hover { background:#0284c7; }

        .hd-recent { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; }
        .hd-recent-h { padding:13px 18px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; display:flex; align-items:center; gap:8px; }
        .hd-recent-h h3 { font-size:14px; font-weight:900; margin:0; }
        .hd-recent-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        .hd-item { display:flex; align-items:center; gap:10px; padding:10px 18px; border-bottom:1px solid #f1f5f9; font-size:12.5px; }
        .hd-item:last-child { border-bottom:0; }
        .hd-item .tn { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#0ea5e9; min-width:110px; }
        .hd-item .tt { color:#0f172a; font-weight:800; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .hd-item .st { font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:5px; }
        .hd-item .pl { font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:5px; }
        .hd-item .us { margin-inline-start:auto; color:#94a3b8; font-size:11.5px; font-weight:700; }
        .hd-empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="hd-wrap">

    <div class="hd-hero">
        <div class="hd-hero-ico"><i class="fa-solid fa-headset"></i></div>
        <div>
            <h1><?= $rtl?'مركز تقارير التذاكر':'Helpdesk Reports Hub' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.helpdesk') ?>
            </div>
            <p><?= $rtl?'كل ما يخص نظام التذاكر: التذاكر، التصنيفات، SLA، التصعيدات، المعالجون':'Tickets: counts, status, SLA, escalations, assignees' ?></p>
        </div>
        <div class="hd-v"><?= $total ?></div>
    </div>

    <div class="hd-stats">
        <div class="hd-stat blue">
            <div class="hd-l"><?= $rtl?'إجمالي التذاكر':'Total Tickets' ?></div>
            <div class="hd-v"><?= $total ?></div>
            <div class="hd-s"><?= $total_messages ?> <?= $rtl?'رسالة':'messages' ?></div>
        </div>
        <div class="hd-stat purple">
            <div class="hd-l"><?= $rtl?'المفتوحة':'Open' ?></div>
            <div class="hd-v"><?= $open ?></div>
            <div class="hd-s"><?= $in_review ?> <?= $rtl?'قيد المراجعة':'in review' ?></div>
        </div>
        <div class="hd-stat green">
            <div class="hd-l"><?= $rtl?'المغلقة':'Closed' ?></div>
            <div class="hd-v"><?= $closed ?></div>
            <div class="hd-s"><?= $resolution_hrs ?>h <?= $rtl?'متوسط الحل':'avg resolution' ?></div>
        </div>
        <div class="hd-stat red">
            <div class="hd-l"><?= $rtl?'تجاوز SLA':'SLA Breached' ?></div>
            <div class="hd-v"><?= $sla_breached ?></div>
            <div class="hd-s"><?= $critical ?> <?= $rtl?'حرجة مفتوحة':'critical open' ?></div>
        </div>
    </div>

    <?php if (!$visible): ?>
        <div class="hd-recent" style="padding:40px;text-align:center;color:#94a3b8">
            <i class="fa-solid fa-lock" style="font-size:32px;display:block;margin-bottom:8px"></i>
            <?= $rtl?'لا توجد تقارير متاحة لك':'No reports available for you' ?>
        </div>
    <?php else: ?>

    <div class="hd-sec-title"><i class="fa-solid fa-th-large"></i> <?= $rtl?'تقارير التذاكر':'Helpdesk Reports' ?></div>
    <div class="hd-cards">
        <?php foreach ($visible as $c): ?>
            <div class="hd-card">
                <div class="hd-card-h">
                    <div class="hd-card-ico" style="background:<?= $c['color'] ?>"><i class="fa-solid fa-<?= $c['icon'] ?>"></i></div>
                    <div>
                        <h3 class="hd-card-t"><?= $rtl?$c['title_ar']:$c['title_en'] ?></h3>
                        <div class="hd-card-e"><?= $rtl?$c['title_en']:$c['title_ar'] ?></div>
                    </div>
                </div>
                <div class="hd-card-b">
                    <div class="hd-card-d"><?= $rtl?$c['desc_ar']:$c['desc_en'] ?></div>
                    <div class="hd-card-k"><?= $c['kpi'] ?></div>
                </div>
                <div class="hd-card-f">
                    <a href="<?= BASE_URL ?>/reports/helpdesk/<?= str_replace('helpdesk.', '', $c['code']) ?>.php">
                        <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i>
                        <?= $rtl?'فتح التقرير':'Open Report' ?>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="hd-sec-title"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'آخر التذاكر':'Recent Tickets' ?></div>
    <div class="hd-recent">
        <div class="hd-recent-h">
            <h3><?= $rtl?'آخر 8 تذاكر':'Last 8 Tickets' ?></h3>
            <span class="ct"><?= count($recent) ?> <?= $rtl?'تذكرة':'tickets' ?></span>
        </div>
        <?php if (!$recent): ?>
            <div class="hd-empty"><?= $rtl?'لا توجد تذاكر بعد':'No tickets yet' ?></div>
        <?php else: foreach ($recent as $r):
            $scol = $STATUS_COLOR[$r['status']] ?? '#475569';
            $pcol = $PRIORITY_COLOR[$r['priority'] ?? 'medium'] ?? '#475569';
        ?>
            <div class="hd-item">
                <span class="tn"><?= e($r['ticket_number']) ?></span>
                <span class="pl" style="background:<?= $pcol ?>22;color:<?= $pcol ?>"><?= e($PRIORITY_AR[$r['priority']] ?? $r['priority']) ?></span>
                <span class="st" style="background:<?= $scol ?>22;color:<?= $scol ?>"><?= e($STATUS_AR[$r['status']] ?? $r['status']) ?></span>
                <span class="tt"><?= e(truncate($r['title'] ?? '', 35)) ?></span>
                <span class="us"><?= e($r['created_by'] ?? '—') ?> · <?= date('d/m', strtotime($r['created_at'])) ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($cat_dist): ?>
    <div class="hd-sec-title"><i class="fa-solid fa-chart-bar"></i> <?= $rtl?'أكثر 5 تصنيفات':'Top 5 Categories' ?></div>
    <div class="hd-recent" style="padding:18px">
        <?php
        $max_c = max(array_column($cat_dist, 'n'));
        foreach ($cat_dist as $c):
            $pct = round($c['n'] / $max_c * 100);
        ?>
            <div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12.5px">
                <span style="color:#475569;font-weight:700;min-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['name']) ?></span>
                <span style="flex:1;height:18px;background:#f1f5f9;border-radius:4px;overflow:hidden"><span style="display:block;height:100%;width:<?= $pct ?>%;background:linear-gradient(90deg, #0ea5e9, #38bdf8)"></span></span>
                <span style="min-width:32px;text-align:end;font-weight:800;color:#0f172a"><?= (int)$c['n'] ?></span>
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
