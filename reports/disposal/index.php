<?php
/**
 * reports/disposal/index.php — مركز تقارير التخلص
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.disposal');

$can_export  = can('reports.disposal', 'export');
$excel_mode  = report_excel_mode_active('reports.disposal');
$print_mode  = report_print_mode_active('reports.disposal');
$print_charts = report_print_charts_mode_active('reports.disposal');

$rtl = is_rtl();
$page_title = $rtl?'مركز تقارير التخلص':'Disposal Reports Hub';
$active_nav = 'reports.disposal';

global $pdo;

// KPIs
$total_disposals = (int)$pdo->query("SELECT COUNT(*) FROM asset_disposals")->fetchColumn();
$total_known = (int)$pdo->query("SELECT COUNT(*) FROM known_disposals")->fetchColumn();
$total_value = $pdo->query("SELECT COALESCE(SUM(disposal_value),0) FROM asset_disposals")->fetchColumn();

// حسب النوع
$by_type = $pdo->query("SELECT disposal_type, COUNT(*) AS n FROM asset_disposals GROUP BY disposal_type")->fetchAll(PDO::FETCH_ASSOC);
$type_map = array_column($by_type, 'n', 'disposal_type');
$scrap = $type_map['scrap'] ?? 0;
$destroy = $type_map['destroy'] ?? 0;
$sell = $type_map['sell'] ?? 0;
$transfer = $type_map['transfer_out'] ?? 0;

// حسب السبب
$by_reason = $pdo->query("SELECT reason, COUNT(*) AS n FROM asset_disposals GROUP BY reason")->fetchAll(PDO::FETCH_ASSOC);
$reason_map = array_column($by_reason, 'n', 'reason');
$obsolete = $reason_map['obsolete'] ?? 0;
$damaged = $reason_map['damaged_beyond_repair'] ?? 0;
$eol = $reason_map['end_of_life'] ?? 0;
$lost = $reason_map['lost'] ?? 0;
$replaced = $reason_map['replaced'] ?? 0;
$other_r = $reason_map['other'] ?? 0;

// recent
$recent = $pdo->query("
    SELECT d.id, d.disposal_type, d.reason, d.disposal_date, d.disposal_value,
           a.tag_number, a.description,
           u.username AS created_by
    FROM asset_disposals d
    LEFT JOIN assets a ON a.id = d.asset_id
    LEFT JOIN users u ON u.id = d.created_by
    ORDER BY d.id DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

$cards = [
    ['code'=>'disposal.overview',     'icon'=>'chart-pie',     'title_ar'=>'ملخص التخلص',          'title_en'=>'Disposal Overview',     'desc_ar'=>'شامل: عدد عمليات التخلص + القيمة الإجمالية + التوزيع',    'desc_en'=>'All disposals: count + total value + distribution',          'kpi'=>"$total_disposals disposals · ".number_format((float)$total_value)." SAR", 'color'=>'#7c3aed'],
    ['code'=>'disposal.list',         'icon'=>'trash-can',     'title_ar'=>'سجل التخلص',           'title_en'=>'Disposal Log',          'desc_ar'=>'كل عمليات التخلص: النوع، السبب، التاريخ، القيمة، المرجع', 'desc_en'=>'Every disposal: type, reason, date, value, reference',          'kpi'=>"$total_disposals records", 'color'=>'#dc2626'],
    ['code'=>'disposal.by_type',      'icon'=>'list-check',    'title_ar'=>'حسب النوع',            'title_en'=>'By Type',               'desc_ar'=>'تكهين / إتلاف / بيع / نقل خارجي — تفصيل وقيم',            'desc_en'=>'Scrap / destroy / sell / transfer-out — breakdown + values',  'kpi'=>"scrap=$scrap · destroy=$destroy · sell=$sell · transfer=$transfer", 'color'=>'#f59e0b'],
    ['code'=>'disposal.by_reason',    'icon'=>'circle-exclamation','title_ar'=>'حسب السبب',       'title_en'=>'By Reason',             'desc_ar'=>'قديم / تالف / انتهى عمره / مفقود / مُستبدل / آخر',          'desc_en'=>'Obsolete / damaged / EOL / lost / replaced / other',           'kpi'=>"obsolete=$obsolete · damaged=$damaged · eol=$eol · lost=$lost", 'color'=>'#7f1d1d'],
    ['code'=>'disposal.known',        'icon'=>'clock-rotate-left','title_ar'=>'التخلصات التاريخية','title_en'=>'Historical (Pre-System)','desc_ar'=>'التخلصات اللي صارت قبل النظام (ورقي) — للمرجعية فقط',     'desc_en'=>'Pre-system disposals (paper-based) — for reference only',       'kpi'=>"$total_known historical", 'color'=>'#475569'],
];

$visible = array_values(array_filter($cards, fn($c) => can($c['code'], 'view')));

$TYPE_AR = ['scrap'=>'تكهين','destroy'=>'إتلاف','sell'=>'بيع','transfer_out'=>'نقل خارجي'];
$TYPE_COLOR = ['scrap'=>'#f59e0b','destroy'=>'#dc2626','sell'=>'#16a34a','transfer_out'=>'#0ea5e9'];
$REASON_AR = ['obsolete'=>'قديم','damaged_beyond_repair'=>'تالف','end_of_life'=>'انتهى عمره','lost'=>'مفقود','replaced'=>'مُستبدل','other'=>'آخر'];
$REASON_COLOR = ['obsolete'=>'#94a3b8','damaged_beyond_repair'=>'#dc2626','end_of_life'=>'#7f1d1d','lost'=>'#f59e0b','replaced'=>'#0ea5e9','other'=>'#64748b'];

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
        .ds-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }
        .ds-hero { background:linear-gradient(135deg, #1e1b4b 0%, #5b21b6 50%, #7c3aed 100%); color:#fff; border-radius:18px; padding:26px 32px; margin-bottom:16px; display:flex; align-items:center; gap:20px; box-shadow:0 10px 30px rgba(124,58,237,.25); position:relative; overflow:hidden; }
        .ds-hero::before { content:''; position:absolute; top:-50%; right:-10%; width:300px; height:300px; background:radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%); }
        .ds-hero-ico { width:64px; height:64px; background:rgba(255,255,255,.18); border-radius:16px; display:flex; align-items:center; justify-content:center; font-size:28px; }
        .ds-hero h1 { font-size:24px; font-weight:900; margin:0 0 4px; }
        .ds-hero p { font-size:13px; opacity:.92; margin:0; }
        .ds-hero .ds-v { margin-inline-start:auto; font-size:36px; font-weight:900; opacity:.95; }

        .ds-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:18px; }
        .ds-stat { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; padding:14px 16px; border-top:4px solid; }
        .ds-stat .ds-l { font-size:11.5px; color:#64748b; font-weight:700; }
        .ds-stat .ds-v { font-size:26px; font-weight:900; color:#0f172a; margin-top:2px; }
        .ds-stat .ds-s { font-size:11px; color:#94a3b8; font-weight:700; margin-top:2px; }
        .ds-stat.purple { border-top-color:#7c3aed; }
        .ds-stat.red    { border-top-color:#dc2626; }
        .ds-stat.amber  { border-top-color:#f59e0b; }
        .ds-stat.green  { border-top-color:#16a34a; }

        .ds-sec-title { font-size:15px; font-weight:900; color:#0f172a; margin:20px 0 10px; display:flex; align-items:center; gap:8px; }
        .ds-sec-title i { color:#7c3aed; }

        .ds-cards { display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:12px; }
        .ds-card { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; transition:transform .2s, box-shadow .2s; }
        .ds-card:hover { transform:translateY(-3px); box-shadow:0 12px 24px rgba(15,23,42,.08); }
        .ds-card-h { display:flex; align-items:center; gap:10px; padding:14px 16px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .ds-card-ico { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; color:#fff; }
        .ds-card-t { font-size:14px; font-weight:900; color:#0f172a; margin:0; }
        .ds-card-e { font-size:11px; color:#94a3b8; font-weight:700; }
        .ds-card-b { padding:12px 16px; }
        .ds-card-d { font-size:12.5px; color:#475569; line-height:1.6; margin-bottom:8px; }
        .ds-card-k { background:#f5f3ff; border:1px dashed #c4b5fd; border-radius:6px; padding:6px 10px; font-size:11.5px; color:#6d28d9; font-weight:800; text-align:center; }
        .ds-card-f { padding:10px 16px; border-top:1.5px solid #f1f5f9; }
        .ds-card-f a { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; background:#7c3aed; color:#fff; border-radius:8px; text-decoration:none; font-weight:800; font-size:12px; }
        .ds-card-f a:hover { background:#6d28d9; }

        .ds-recent { background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; overflow:hidden; }
        .ds-recent-h { padding:13px 18px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; display:flex; align-items:center; gap:8px; }
        .ds-recent-h h3 { font-size:14px; font-weight:900; margin:0; }
        .ds-recent-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        .ds-item { display:flex; align-items:center; gap:10px; padding:10px 18px; border-bottom:1px solid #f1f5f9; font-size:12.5px; }
        .ds-item:last-child { border-bottom:0; }
        .ds-item .tg { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#7c3aed; min-width:90px; }
        .ds-item .st { font-size:10.5px; font-weight:800; padding:2px 7px; border-radius:5px; }
        .ds-item .desc { color:#64748b; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ds-item .vl { color:#0f172a; font-weight:800; min-width:80px; text-align:end; }
        .ds-empty { padding:30px; text-align:center; color:#94a3b8; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="ds-wrap">

    <div class="ds-hero">
        <div class="ds-hero-ico"><i class="fa-solid fa-trash-can"></i></div>
        <div>
            <h1><?= $rtl?'مركز تقارير التخلص':'Disposal Reports Hub' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.disposal') ?>
            </div>
            <p><?= $rtl?'كل ما يخص التخلص من الأصول: تكهين، إتلاف، بيع، نقل خارجي + الأسباب والقيم':'Asset disposal: scrap, destroy, sell, transfer-out + reasons + values' ?></p>
        </div>
        <div class="ds-v"><?= $total_disposals + $total_known ?></div>
    </div>

    <div class="ds-stats">
        <div class="ds-stat purple">
            <div class="ds-l"><?= $rtl?'عمليات التخلص':'Disposal Operations' ?></div>
            <div class="ds-v"><?= $total_disposals ?></div>
            <div class="ds-s"><?= $rtl?'عبر النظام':'via system' ?></div>
        </div>
        <div class="ds-stat red">
            <div class="ds-l"><?= $rtl?'تكهين':'Scrap' ?></div>
            <div class="ds-v"><?= $scrap ?></div>
        </div>
        <div class="ds-stat green">
            <div class="ds-l"><?= $rtl?'بيع':'Sell' ?></div>
            <div class="ds-v"><?= $sell ?></div>
        </div>
        <div class="ds-stat amber">
            <div class="ds-l"><?= $rtl?'القيمة الإجمالية':'Total Value' ?></div>
            <div class="ds-v"><?= number_format((float)$total_value) ?></div>
            <div class="ds-s">SAR</div>
        </div>
    </div>

    <?php if (!$visible): ?>
        <div class="ds-recent" style="padding:40px;text-align:center;color:#94a3b8">
            <i class="fa-solid fa-lock" style="font-size:32px;display:block;margin-bottom:8px"></i>
            <?= $rtl?'لا توجد تقارير متاحة لك':'No reports available for you' ?>
        </div>
    <?php else: ?>

    <div class="ds-sec-title"><i class="fa-solid fa-th-large"></i> <?= $rtl?'تقارير التخلص':'Disposal Reports' ?></div>
    <div class="ds-cards">
        <?php foreach ($visible as $c): ?>
            <div class="ds-card">
                <div class="ds-card-h">
                    <div class="ds-card-ico" style="background:<?= $c['color'] ?>"><i class="fa-solid fa-<?= $c['icon'] ?>"></i></div>
                    <div>
                        <h3 class="ds-card-t"><?= $rtl?$c['title_ar']:$c['title_en'] ?></h3>
                        <div class="ds-card-e"><?= $rtl?$c['title_en']:$c['title_ar'] ?></div>
                    </div>
                </div>
                <div class="ds-card-b">
                    <div class="ds-card-d"><?= $rtl?$c['desc_ar']:$c['desc_en'] ?></div>
                    <div class="ds-card-k"><?= $c['kpi'] ?></div>
                </div>
                <div class="ds-card-f">
                    <a href="<?= BASE_URL ?>/reports/disposal/<?= str_replace('disposal.', '', $c['code']) ?>.php">
                        <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i>
                        <?= $rtl?'فتح التقرير':'Open Report' ?>
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="ds-sec-title"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'آخر عمليات التخلص':'Recent Disposals' ?></div>
    <div class="ds-recent">
        <div class="ds-recent-h">
            <h3><?= $rtl?'آخر 8 عمليات':'Last 8 Operations' ?></h3>
            <span class="ct"><?= count($recent) ?> <?= $rtl?'عملية':'ops' ?></span>
        </div>
        <?php if (!$recent): ?>
            <div class="ds-empty"><?= $rtl?'لا توجد عمليات بعد':'No disposals yet' ?></div>
        <?php else: foreach ($recent as $r):
            $tcol = $TYPE_COLOR[$r['disposal_type']] ?? '#475569';
            $rcol = $REASON_COLOR[$r['reason']] ?? '#475569';
        ?>
            <div class="ds-item">
                <span class="tg"><?= e($r['tag_number'] ?? '—') ?></span>
                <span class="st" style="background:<?= $tcol ?>22;color:<?= $tcol ?>"><?= e($TYPE_AR[$r['disposal_type']] ?? $r['disposal_type']) ?></span>
                <span class="st" style="background:<?= $rcol ?>22;color:<?= $rcol ?>"><?= e($REASON_AR[$r['reason']] ?? $r['reason']) ?></span>
                <span class="desc"><?= e(truncate($r['description'] ?? '', 35)) ?></span>
                <span class="vl"><?= $r['disposal_value'] ? number_format((float)$r['disposal_value']).' SAR' : '—' ?></span>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php endif; ?>
</div>
</main>
</div>
</body>
</html>
