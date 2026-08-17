<?php
/**
 * reports/inventory/index.php — مركز تقارير الجرد
 * Hub with 4 KPIs + 6 feature cards + recent activity
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.inventory');

$can_export  = can('reports.inventory', 'export');
$excel_mode  = report_excel_mode_active('reports.inventory');
$print_mode  = report_print_mode_active('reports.inventory');
$print_charts = report_print_charts_mode_active('reports.inventory');

$rtl = is_rtl();
$page_title = $rtl?'مركز تقارير الجرد':'Inventory Reports Hub';
$active_nav = 'reports.inventory';

global $pdo;

// 4 KPIs
$total_sessions = (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions")->fetchColumn();
$active_sessions = (int)$pdo->query("SELECT COUNT(*) FROM inventory_sessions WHERE status IN ('planning','active','review')")->fetchColumn();
$total_audits = (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits")->fetchColumn();
$missing = (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE action IN ('missing','missing_disposed_previously','missing_under_investigation')")->fetchColumn();
$surplus = (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE action IN ('surplus','surplus_registered')")->fetchColumn();
$reaudit_pending = (int)$pdo->query("SELECT COUNT(*) FROM inventory_reaudit_requests WHERE status='pending'")->fetchColumn();
$confirmed = (int)$pdo->query("SELECT COUNT(*) FROM inventory_audits WHERE action='confirmed'")->fetchColumn();
$match_rate = $total_audits > 0 ? round($confirmed / $total_audits * 100, 1) : 0;

// Recent activity (آخر 10 audits)
$recent = $pdo->query("
    SELECT a.id, a.action, a.scanned_tag, a.audited_at, a.session_id,
           s.title AS session_title, s.session_code,
           u.username AS audited_by
    FROM inventory_audits a
    LEFT JOIN inventory_sessions s ON s.id = a.session_id
    LEFT JOIN users u ON u.id = a.audited_by
    ORDER BY a.audited_at DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Session distribution
$session_stats = $pdo->query("
    SELECT status, COUNT(*) AS n
    FROM inventory_sessions
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

// Cards
$cards = [
    ['code'=>'inventory.overview',  'icon'=>'chart-pie',  'title_ar'=>'ملخص الجرد',          'title_en'=>'Inventory Overview',     'desc_ar'=>'شامل: جلسات + عمليات + نسب المطابقة + تفصيل حسب القسم',     'desc_en'=>'All sessions + audits + match rate + per-department breakdown',  'kpi'=>"$total_sessions sessions · $total_audits audits", 'color'=>'#0d9488'],
    ['code'=>'inventory.sessions',  'icon'=>'clipboard-list','title_ar'=>'الجلسات',           'title_en'=>'Sessions',              'desc_ar'=>'كل جلسات الجرد: مخططة/نشطة/قيد المراجعة/مكتملة + الأعضاء + النطاق', 'desc_en'=>'All sessions by status + members + scope + dates',                  'kpi'=>"$active_sessions active · $total_sessions total", 'color'=>'#0ea5e9'],
    ['code'=>'inventory.audits',    'icon'=>'check-double','title_ar'=>'سجل العمليات',      'title_en'=>'Audit Log',             'desc_ar'=>'كل عمليات المسح: مؤكد/تغيّر موقع/تغيّر عهدة/تالف + الصور',     'desc_en'=>'Every scan: confirmed/location changed/custody changed/damaged + photos','kpi'=>"$total_audits audits · $match_rate% match", 'color'=>'#16a34a'],
    ['code'=>'inventory.missing',   'icon'=>'triangle-exclamation','title_ar'=>'الأصول المفقودة', 'title_en'=>'Missing Assets',     'desc_ar'=>'الأصول اللي ما انمسحت في الجرد + تفصيل الأسباب + محقق سابقاً', 'desc_en'=>'Assets not scanned + reasons + previously disposed / under investigation', 'kpi'=>"$missing missing", 'color'=>'#dc2626'],
    ['code'=>'inventory.surplus',   'icon'=>'plus-circle', 'title_ar'=>'الأصول الزائدة',   'title_en'=>'Surplus Assets',        'desc_ar'=>'الأصول اللي انمسحت بدون وجود سجل في الأصول + إجراء التسجيل',     'desc_en'=>'Scanned but not in assets + registration action',                  'kpi'=>"$surplus surplus", 'color'=>'#f59e0b'],
    ['code'=>'inventory.reaudit',   'icon'=>'rotate',      'title_ar'=>'طلبات إعادة الجرد', 'title_en'=>'Re-Audit Requests',     'desc_ar'=>'الطلبات المعلقة + المعتمدة + المرفوضة + السبب والقرار',         'desc_en'=>'Pending/approved/rejected re-audit requests + reason + decision',   'kpi'=>"$reaudit_pending pending", 'color'=>'#7c3aed'],
];

$visible = array_values(array_filter($cards, fn($c) => can($c['code'], 'view')));

$SESSION_AR = ['planning'=>'مخططة','active'=>'نشطة','review'=>'مراجعة','completed'=>'مكتملة','cancelled'=>'ملغاة'];
$SESSION_COLOR = ['planning'=>'#0ea5e9','active'=>'#16a34a','review'=>'#f59e0b','completed'=>'#475569','cancelled'=>'#94a3b8'];
$ACTION_AR = ['confirmed'=>'مؤكد','location_changed'=>'تغيّر موقع','custody_changed'=>'تغيّر عهدة','condition_damaged'=>'تالف','missing'=>'مفقود','missing_disposed_previously'=>'مفقود (تخلص سابق)','missing_under_investigation'=>'مفقود (تحت التحقيق)','surplus'=>'زائد (غير مسجّل)','surplus_registered'=>'زائد (تم التسجيل)','reaudit_pending'=>'بانتظار إعادة الجرد'];
$ACTION_COLOR = ['confirmed'=>'#16a34a','location_changed'=>'#0ea5e9','custody_changed'=>'#7c3aed','condition_damaged'=>'#d97706','missing'=>'#dc2626','missing_disposed_previously'=>'#7f1d1d','missing_under_investigation'=>'#f59e0b','surplus'=>'#f59e0b','surplus_registered'=>'#16a34a','reaudit_pending'=>'#7c3aed'];

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
        .iv-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }
        .iv-hero { background:linear-gradient(135deg, #134e4a 0%, #0d9488 50%, #14b8a6 100%); color:#fff; border-radius:18px; padding:26px 32px; margin-bottom:16px; display:flex; align-items:center; gap:20px; box-shadow:0 10px 30px rgba(13,148,136,.25); position:relative; overflow:hidden; }
        .iv-hero::before { content:''; position:absolute; top:-50%; right:-10%; width:300px; height:300px; background:radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%); }
        .iv-hero-ico { width:64px; height:64px; background:rgba(255,255,255,.18); border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:28px; }
        .iv-hero h1 { font-size:24px; font-weight:900; margin:0 0 4px; }
        .iv-hero p { font-size:13px; opacity:.92; margin:0; }
        .iv-hero .iv-v { margin-inline-start:auto; font-size:36px; font-weight:900; opacity:.95; }

        .iv-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:18px; }
        .iv-stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:14px 16px; border-top:4px solid; }
        .iv-stat .iv-l { font-size:11.5px; color:#64748b; font-weight:700; }
        .iv-stat .iv-v { font-size:26px; font-weight:900; color:#0f172a; margin-top:2px; }
        .iv-stat .iv-s { font-size:11px; color:#94a3b8; font-weight:700; margin-top:2px; }
        .iv-stat.teal  { border-top-color:#0d9488; }
        .iv-stat.blue  { border-top-color:#0ea5e9; }
        .iv-stat.green { border-top-color:#16a34a; }
        .iv-stat.red   { border-top-color:#dc2626; }

        .iv-sec-title { font-size:15px; font-weight:900; color:#0f172a; margin:20px 0 10px; display:flex; align-items:center; gap:8px; }
        .iv-sec-title i { color:#0d9488; }

        .iv-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:12px; }
        .iv-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; transition:transform .2s, box-shadow .2s; }
        .iv-card:hover { transform:translateY(-3px); box-shadow:0 12px 24px rgba(15,23,42,.08); }
        .iv-card-h { display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .iv-card-ico { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; }
        .iv-card-t { font-size:14px; font-weight:900; color:#0f172a; margin:0; }
        .iv-card-e { font-size:11px; color:#94a3b8; font-weight:700; }
        .iv-card-b { padding:12px 16px; }
        .iv-card-d { font-size:12.5px; color:#475569; line-height:1.6; margin-bottom:8px; }
        .iv-card-k { background:#f0fdfa; border:1px dashed #5eead4; border-radius:6px; padding:6px 10px; font-size:11.5px; color:#0f766e; font-weight:800; text-align:center; }
        .iv-card-f { padding:10px 16px; border-top:1.5px solid #f1f5f9; display:flex; align-items:center; }
        .iv-card-f a { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; background:#0d9488; color:#fff; border-radius:8px; text-decoration:none; font-weight:800; font-size:12px; transition:background .2s; }
        .iv-card-f a:hover { background:#0f766e; }
        .iv-card-f .soon { color:#94a3b8; font-weight:700; font-size:11.5px; }

        .iv-recent { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; }
        .iv-recent-h { padding:13px 18px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; display:flex; align-items:center; gap:8px; }
        .iv-recent-h h3 { font-size:14px; font-weight:900; margin:0; }
        .iv-recent-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        .iv-item { display:flex; align-items:center; gap:10px; padding:10px 18px; border-bottom:1px solid #f1f5f9; font-size:12.5px; }
        .iv-item:last-child { border-bottom:0; }
        .iv-item .ic { font-size:10px; color:#cbd5e1; }
        .iv-item .tg { font-weight:800; color:#0f172a; min-width:120px; }
        .iv-item .st { font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:5px; }
        .iv-item .sc { color:#64748b; }
        .iv-item .us { margin-inline-start:auto; color:#94a3b8; font-size:11.5px; font-weight:700; }
        .iv-empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="iv-wrap">

    <div class="iv-hero">
        <div class="iv-hero-ico"><i class="fa-solid fa-barcode"></i></div>
        <div>
            <h1><?= $rtl?'مركز تقارير الجرد':'Inventory Reports Hub' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.inventory') ?>
            </div>
            <p><?= $rtl?'كل ما يخص عمليات الجرد: جلسات، مسحات، أصول مفقودة/زائدة، طلبات إعادة الجرد':'Inventory operations: sessions, scans, missing/surplus assets, re-audit requests' ?></p>
        </div>
        <div class="iv-v"><?= $total_sessions ?></div>
    </div>

    <div class="iv-stats">
        <div class="iv-stat teal">
            <div class="iv-l"><?= $rtl?'جلسات الجرد':'Inventory Sessions' ?></div>
            <div class="iv-v"><?= $total_sessions ?></div>
            <div class="iv-s"><?= $active_sessions ?> <?= $rtl?'نشطة':'active' ?></div>
        </div>
        <div class="iv-stat blue">
            <div class="iv-l"><?= $rtl?'إجمالي عمليات المسح':'Total Scans' ?></div>
            <div class="iv-v"><?= number_format($total_audits) ?></div>
            <div class="iv-s"><?= $rtl?'معدل المطابقة: ':'Match rate: ' ?><?= $match_rate ?>%</div>
        </div>
        <div class="iv-stat red">
            <div class="iv-l"><?= $rtl?'أصول مفقودة':'Missing' ?></div>
            <div class="iv-v"><?= $missing ?></div>
            <div class="iv-s"><?= $rtl?'تحت التحقيق/سابق':'under inv./prev.' ?></div>
        </div>
        <div class="iv-stat green">
            <div class="iv-l"><?= $rtl?'أصول زائدة':'Surplus' ?></div>
            <div class="iv-v"><?= $surplus ?></div>
            <div class="iv-s"><?= $reaudit_pending ?> <?= $rtl?'طلب إعادة':'re-audit pend' ?></div>
        </div>
    </div>

    <?php if (!$visible): ?>
        <div class="iv-recent" style="padding:40px;text-align:center;color:#94a3b8">
            <i class="fa-solid fa-lock" style="font-size:32px;display:block;margin-bottom:8px"></i>
            <?= $rtl?'لا توجد تقارير متاحة لك':'No reports available for you' ?>
        </div>
    <?php else: ?>

    <div class="iv-sec-title"><i class="fa-solid fa-th-large"></i> <?= $rtl?'تقارير الجرد':'Inventory Reports' ?></div>
    <div class="iv-cards">
        <?php foreach ($visible as $c): ?>
            <div class="iv-card">
                <div class="iv-card-h">
                    <div class="iv-card-ico" style="background:<?= $c['color'] ?>"><i class="fa-solid fa-<?= $c['icon'] ?>"></i></div>
                    <div>
                        <h3 class="iv-card-t"><?= $rtl?$c['title_ar']:$c['title_en'] ?></h3>
                        <div class="iv-card-e"><?= $rtl?$c['title_en']:$c['title_ar'] ?></div>
                    </div>
                </div>
                <div class="iv-card-b">
                    <div class="iv-card-d"><?= $rtl?$c['desc_ar']:$c['desc_en'] ?></div>
                    <div class="iv-card-k"><?= $c['kpi'] ?></div>
                </div>
                <div class="iv-card-f">
                    <a href="<?= BASE_URL ?>/reports/inventory/<?= str_replace('inventory.', '', $c['code']) ?>.php">
                        <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i>
                        <?= $rtl?'فتح التقرير':'Open Report' ?>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="iv-sec-title"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'آخر عمليات الجرد':'Recent Audits' ?></div>
    <div class="iv-recent">
        <div class="iv-recent-h">
            <h3><?= $rtl?'آخر 8 عمليات مسح':'Last 8 Scans' ?></h3>
            <span class="ct"><?= count($recent) ?> <?= $rtl?'عملية':'ops' ?></span>
        </div>
        <?php if (!$recent): ?>
            <div class="iv-empty"><?= $rtl?'لا توجد عمليات بعد':'No audits yet' ?></div>
        <?php else: foreach ($recent as $r):
            $acol = $ACTION_COLOR[$r['action']] ?? '#475569';
            $aar = $ACTION_AR[$r['action']] ?? $r['action'];
        ?>
            <div class="iv-item">
                <i class="fa-solid fa-circle ic"></i>
                <span class="tg"><?= e($r['scanned_tag'] ?: '—') ?></span>
                <span class="st" style="background:<?= $acol ?>22;color:<?= $acol ?>"><?= e($aar) ?></span>
                <span class="sc"><?= e($r['session_title'] ?? '—') ?></span>
                <span class="us"><?= e($r['audited_by'] ?? '—') ?> · <?= date('d/m H:i', strtotime($r['audited_at'])) ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php if ($session_stats): ?>
    <div class="iv-sec-title"><i class="fa-solid fa-chart-pie"></i> <?= $rtl?'توزيع الجلسات':'Sessions Distribution' ?></div>
    <div class="iv-recent" style="padding:18px">
        <?php
        $max_st = max(array_column($session_stats, 'n'));
        foreach ($session_stats as $s):
            $col = $SESSION_COLOR[$s['status']] ?? '#475569';
            $pct = round($s['n'] / $max_st * 100);
        ?>
            <div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12.5px">
                <span style="color:#475569;font-weight:700;min-width:90px"><?= e($SESSION_AR[$s['status']] ?? $s['status']) ?></span>
                <span style="width:11px;height:11px;border-radius:3px;background:<?= $col ?>"></span>
                <span style="flex:1;height:18px;background:#f1f5f9;border-radius:4px;overflow:hidden"><span style="display:block;height:100%;width:<?= $pct ?>%;background:<?= $col ?>"></span></span>
                <span style="min-width:32px;text-align:end;font-weight:800;color:#0f172a"><?= (int)$s['n'] ?></span>
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
