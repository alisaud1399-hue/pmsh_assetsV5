<?php
/**
 * reports/complaints/index.php — مركز تقارير البلاغات (Complaints Reports Hub)
 * ──────────────────────────────────────────────────────────────────
 *   • صفحة تجميعية لكل تقارير البلاغات (نفس النمط البصري لمراكز: NUPCO / الأصول / العهدة / المخاطر)
 *   • لون مميز: برتقالي/أحمر (Orange-Red) — يختلف عن باقي المراكز
 *   • تظهر فقط التقارير اللي المستخدم عنده صلاحية عليها
 *   • 4 KPIs سريعة + 5 بطاقات تقارير
 *   • نشاط حديث: آخر البلاغات
 *
 *   المعيار: التقارير = استعراض فقط. الإجراء (إغلاق/تحويل) في صفحات منفصلة
 *   مثل /complaints/view.php?id=X
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.complaints');

$can_export  = can('reports.complaints', 'export');
$excel_mode  = report_excel_mode_active('reports.complaints');
$print_mode  = report_print_mode_active('reports.complaints');
$print_charts = report_print_charts_mode_active('reports.complaints');

$rtl = is_rtl();
$page_title = $rtl ? 'تقارير البلاغات' : 'Complaints Reports';
$page_icon  = 'fa-bell';
$active_nav = 'reports.complaints';
$breadcrumb = [
    ['name' => $rtl ? 'تقارير البلاغات' : 'Complaints Reports'],
];

// ═══ الإحصائيات السريعة (KPIs) ═══
$stats = [];
$stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn();
$stats['open']  = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status NOT IN ('closed','cancelled','rejected','resolved')")->fetchColumn();
$stats['stalled'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status='stalled'")->fetchColumn();
$stats['escalated'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status='escalated'")->fetchColumn();
$stats['resolved'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status IN ('resolved','closed')")->fetchColumn();
$stats['sla_breached'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE sla_breach_detected_at IS NOT NULL")->fetchColumn();
$stats['critical'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE priority='critical' AND status NOT IN ('closed','cancelled','rejected')")->fetchColumn();
$stats['urgent'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE priority='urgent' AND status NOT IN ('closed','cancelled','rejected')")->fetchColumn();
$stats['avg_resolution_days'] = (float)$pdo->query("
    SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, created_at, COALESCE(resolved_at, closed_at)) / 24.0), 0)
    FROM complaints
    WHERE resolved_at IS NOT NULL OR closed_at IS NOT NULL
")->fetchColumn();

// ═══ قائمة التقارير (Feature Cards) ═══
$reports = [
    [
        'id'          => 'overview',
        'icon'        => 'fa-chart-pie',
        'color'       => '#ea580c',
        'title_ar'    => 'ملخص البلاغات',
        'title_en'    => 'Complaints Overview',
        'desc_ar'     => 'نظرة شاملة على كل البلاغات مع KPIs ومؤشرات',
        'desc_en'     => 'Complete view with KPIs and indicators',
        'permission'  => 'reports.complaints.overview',
        'available'   => true,
        'kpi'         => $stats['total'] . ' ' . ($rtl ? 'بلاغ' : 'total'),
    ],
    [
        'id'          => 'by_status',
        'icon'        => 'fa-list-check',
        'color'       => '#dc2626',
        'title_ar'    => 'حسب الحالة',
        'title_en'    => 'By Status',
        'desc_ar'     => 'توزيع البلاغات على كل حالة + متوسط الوقت',
        'desc_en'     => 'Distribution by status + average time',
        'permission'  => 'reports.complaints.by_status',
        'available'   => true,
        'kpi'         => $stats['open'] . ' ' . ($rtl ? 'مفتوح' : 'open'),
    ],
    [
        'id'          => 'by_department',
        'icon'        => 'fa-building',
        'color'       => '#7c3aed',
        'title_ar'    => 'حسب القسم',
        'title_en'    => 'By Department',
        'desc_ar'     => 'البلاغات الواردة من كل قسم + SLA لكل قسم',
        'desc_en'     => 'Complaints by department + SLA per dept',
        'permission'  => 'reports.complaints.by_department',
        'available'   => true,
        'kpi'         => $stats['total'] . ' ' . ($rtl ? 'بلاغ' : 'complaints'),
    ],
    [
        'id'          => 'sla_breaches',
        'icon'        => 'fa-circle-exclamation',
        'color'       => '#f59e0b',
        'title_ar'    => 'تجاوزات SLA',
        'title_en'    => 'SLA Breaches',
        'desc_ar'     => 'البلاغات اللي تجاوزت الوقت المحدد + تحليل',
        'desc_en'     => 'Complaints exceeding SLA + analysis',
        'permission'  => 'reports.complaints.sla_breaches',
        'available'   => true,
        'kpi'         => $stats['sla_breached'] . ' ' . ($rtl ? 'تجاوز' : 'breached'),
    ],
    [
        'id'          => 'by_assignee',
        'icon'        => 'fa-user-shield',
        'color'       => '#0d9488',
        'title_ar'    => 'حسب المعالج',
        'title_en'    => 'By Assignee',
        'desc_ar'     => 'أداء كل معالج: عدد + متوسط + تصنيف',
        'desc_en'     => 'Per-assignee: count + avg time + resolution',
        'permission'  => 'reports.complaints.by_assignee',
        'available'   => true,
        'kpi'         => $stats['total'] . ' ' . ($rtl ? 'معالج' : 'assignees'),
    ],
    [
        'id'          => 'mttr',
        'icon'        => 'fa-clock-rotate-left',
        'color'       => '#1565C0',
        'title_ar'    => 'متوسط وقت الحل (MTTR)',
        'title_en'    => 'Mean Time To Resolve',
        'desc_ar'     => 'متوسط الوقت من الفتح إلى الإغلاق/الحل',
        'desc_en'     => 'Average time from open to close/resolve',
        'permission'  => 'reports.complaints.mttr',
        'available'   => true,
        'kpi'         => round($stats['avg_resolution_days'], 1) . ' ' . ($rtl ? 'يوم' : 'days'),
    ],
];

// فلترة التقارير حسب الصلاحيات
$visible_reports = array_values(array_filter($reports, function($r) {
    return can($r['permission'], 'view');
}));

// ═══ آخر البلاغات (Recent) ═══
$recent = $pdo->query("
    SELECT c.id, c.request_number, c.description, c.status, c.priority, c.request_type, c.created_at,
           u1.full_name AS requester_name,
           u2.full_name AS assignee_name,
           d.name AS dept_name,
           DATEDIFF(NOW(), c.created_at) AS age_days
    FROM complaints c
    LEFT JOIN users u1 ON u1.id = c.requested_by
    LEFT JOIN users u2 ON u2.id = c.acknowledged_by
    LEFT JOIN departments d ON d.id = c.dept_id
    ORDER BY c.id DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ═══ التوزيع حسب الحالة (دائري بسيط) ═══
$by_status = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM complaints
    GROUP BY status
    ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);
$max_status_cnt = max(1, max(array_column($by_status, 'cnt')));

$STATUS_AR = [
    'open' => 'مفتوحة', 'acknowledged' => 'مستلمة', 'in_progress' => 'قيد المعالجة',
    'stalled' => 'متوقفة', 'escalated' => 'متصاعدة', 'resolved' => 'محلولة',
    'closed' => 'مغلقة', 'cancelled' => 'ملغاة', 'rejected' => 'مرفوضة',
];
$STATUS_COLOR = [
    'open' => '#1565C0', 'acknowledged' => '#0ea5e9', 'in_progress' => '#7c3aed',
    'stalled' => '#d97706', 'escalated' => '#dc2626', 'resolved' => '#16a34a',
    'closed' => '#475569', 'cancelled' => '#94a3b8', 'rejected' => '#7f1d1d',
];

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
    <meta charset="UTF-8">
    <title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        /* ═══ Complaints Hub: mp- prefix ═══ */
        .mp-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }
        .mp-hero {
            background: linear-gradient(135deg, #ea580c, #dc2626, #b91c1c);
            color: #fff;
            border-radius: 18px;
            padding: 28px 32px;
            margin-bottom: 18px;
            box-shadow: 0 10px 30px rgba(220,38,38,.18);
            display: flex; align-items: center; gap: 26px;
            position: relative; overflow: hidden;
        }
        .mp-hero::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(255,255,255,.12) 0%, transparent 50%);
            pointer-events: none;
        }
        .mp-hero-ico {
            width: 70px; height: 70px;
            background: rgba(255,255,255,.18);
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px;
            flex-shrink: 0;
            backdrop-filter: blur(4px);
        }
        .mp-hero h1 { font-size: 26px; font-weight: 900; margin: 0 0 6px; }
        .mp-hero p { font-size: 14px; opacity: .92; margin: 0; line-height: 1.5; }
        .mp-hero .mp-stats { display: flex; gap: 12px; margin-inline-start: auto; }
        .mp-hero-stat { background: rgba(255,255,255,.18); padding: 10px 18px; border-radius: 12px; text-align: center; backdrop-filter: blur(4px); }
        .mp-hero-stat .v { font-size: 22px; font-weight: 900; line-height: 1; }
        .mp-hero-stat .l { font-size: 11px; opacity: .9; margin-top: 2px; }

        .mp-kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
        .mp-kpi {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 18px;
            display: flex; align-items: center; gap: 12px;
        }
        .mp-kpi-ico {
            width: 40px; height: 40px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }
        .mp-kpi .lbl { font-size: 11px; color: #94a3b8; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; }
        .mp-kpi .val { font-size: 22px; font-weight: 900; color: #0f172a; line-height: 1.1; margin-top: 1px; }
        .mp-kpi .sub { font-size: 11px; color: #64748b; margin-top: 2px; }

        .mp-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 18px; }
        @media(max-width: 1024px) { .mp-cards { grid-template-columns: 1fr 1fr; } }
        @media(max-width: 700px) { .mp-cards { grid-template-columns: 1fr; } }
        .mp-card {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px 20px;
            text-decoration: none;
            color: #0f172a;
            transition: all .15s;
            display: flex; flex-direction: column; gap: 8px;
            position: relative;
            overflow: hidden;
        }
        .mp-card::before {
            content: '';
            position: absolute;
            top: 0; inset-inline-end: 0;
            width: 6px; height: 100%;
            background: var(--accent, #ea580c);
            opacity: .85;
        }
        .mp-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,0,0,.08); border-color: #cbd5e1; }
        .mp-card.disabled { opacity: .55; pointer-events: none; }
        .mp-card-h { display: flex; align-items: center; gap: 10px; }
        .mp-card-ico {
            width: 38px; height: 38px;
            background: color-mix(in srgb, var(--accent, #ea580c) 14%, transparent);
            color: var(--accent, #ea580c);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .mp-card-title { font-size: 15px; font-weight: 800; margin: 0; }
        .mp-card-desc { font-size: 12px; color: #64748b; line-height: 1.5; min-height: 36px; }
        .mp-card-foot { display: flex; align-items: center; gap: 6px; padding-top: 6px; border-top: 1px solid #f1f5f9; }
        .mp-card-kpi { font-size: 11px; color: #475569; font-weight: 800; background: #f8fafc; padding: 3px 9px; border-radius: 99px; }
        .mp-card-arrow { margin-inline-start: auto; color: #94a3b8; font-size: 12px; }

        .mp-empty { background: #fff; border: 1.5px dashed #e2e8f0; border-radius: 14px; padding: 50px 20px; text-align: center; color: #94a3b8; }
        .mp-empty i { font-size: 42px; margin-bottom: 12px; opacity: .5; }
        .mp-empty h3 { color: #475569; font-size: 15px; margin: 0 0 4px; font-weight: 800; }

        .mp-section {
            background: #fff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 0;
            margin-bottom: 14px;
            overflow: hidden;
        }
        .mp-section-h {
            font-size: 14px; font-weight: 900;
            padding: 12px 18px;
            display: flex; align-items: center; gap: 8px;
            border-bottom: 1.5px solid #f1f5f9;
            background: #fef3c7;
            color: #92400e;
        }
        .mp-section-h .ct { background: rgba(146,64,14,.15); font-size: 10.5px; padding: 2px 8px; border-radius: 99px; font-weight: 700; color: #92400e; }

        .mp-recent { padding: 0; }
        .mp-recent-item {
            padding: 10px 18px;
            display: flex; align-items: center; gap: 12px;
            border-bottom: 1px dashed #f1f5f9;
            font-size: 12.5px;
        }
        .mp-recent-item:last-child { border-bottom: none; }
        .mp-recent-item:hover { background: #fef3c7; }
        .mp-recent-item .num { font-family: 'Inter', monospace; font-size: 11px; font-weight: 700; color: var(--primary); min-width: 110px; }
        .mp-recent-item .ttl { flex: 1; min-width: 0; }
        .mp-recent-item .ttl .t { font-weight: 800; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .mp-recent-item .ttl .m { font-size: 10.5px; color: #94a3b8; }
        .mp-recent-item .age { font-size: 11px; color: #64748b; }

        .mp-st-pill {
            display: inline-block;
            font-size: 10.5px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 5px;
        }
        .mp-prio {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10.5px; font-weight: 800;
            padding: 1px 7px; border-radius: 5px;
        }
        .mp-prio-critical { background: #fee2e2; color: #991b1b; }
        .mp-prio-urgent   { background: #fed7aa; color: #9a3412; }
        .mp-prio-normal  { background: #e0f2fe; color: #075985; }

        .mp-status-row { display: flex; flex-direction: column; gap: 6px; padding: 12px 18px; }
        .mp-status-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; }
        .mp-status-item .d { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .mp-status-item .n { flex: 1; color: #334155; font-weight: 700; }
        .mp-status-item .v { color: #475569; font-weight: 800; min-width: 30px; text-align: end; }
        .mp-status-item .bar { flex: 2; height: 6px; background: #f1f5f9; border-radius: 99px; overflow: hidden; }
        .mp-status-item .bar > div { height: 100%; border-radius: 99px; }

        .mp-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media(max-width: 900px) { .mp-row-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="mp-wrap">

    <!-- Hero -->
    <div class="mp-hero">
        <div class="mp-hero-ico"><i class="fa-solid fa-bell"></i></div>
        <div style="flex:1">
            <h1><?= $rtl?'تقارير البلاغات':'Complaints Reports' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.complaints') ?>
            </div>
            <p><?= $rtl?'رؤية شاملة لأداء البلاغات، توزيعها، تجاوزات SLA، وأداء المعالجين':'Comprehensive view of complaint performance, distribution, SLA breaches, and assignee metrics' ?></p>
        </div>
        <div class="mp-stats">
            <div class="mp-hero-stat">
                <div class="v"><?= number_format($stats['total']) ?></div>
                <div class="l"><?= $rtl?'إجمالي':'Total' ?></div>
            </div>
            <div class="mp-hero-stat">
                <div class="v"><?= number_format($stats['open']) ?></div>
                <div class="l"><?= $rtl?'مفتوحة':'Open' ?></div>
            </div>
            <div class="mp-hero-stat">
                <div class="v"><?= number_format($stats['sla_breached']) ?></div>
                <div class="l"><?= $rtl?'تجاوز SLA':'SLA' ?></div>
            </div>
        </div>
    </div>

    <!-- 4 KPIs -->
    <div class="mp-kpi-row">
        <div class="mp-kpi">
            <div class="mp-kpi-ico" style="background:#dbeafe;color:#1565C0"><i class="fa-solid fa-folder-open"></i></div>
            <div>
                <div class="lbl"><?= $rtl?'إجمالي البلاغات':'Total Complaints' ?></div>
                <div class="val"><?= number_format($stats['total']) ?></div>
                <div class="sub"><?= $rtl?'منذ الإطلاق':'Since launch' ?></div>
            </div>
        </div>
        <div class="mp-kpi">
            <div class="mp-kpi-ico" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-spinner"></i></div>
            <div>
                <div class="lbl"><?= $rtl?'مفتوحة حالياً':'Currently Open' ?></div>
                <div class="val"><?= number_format($stats['open']) ?></div>
                <div class="sub"><?= $stats['critical'] ?> <?= $rtl?'حرجة':'critical' ?> · <?= $stats['urgent'] ?> <?= $rtl?'عاجلة':'urgent' ?></div>
            </div>
        </div>
        <div class="mp-kpi">
            <div class="mp-kpi-ico" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-circle-exclamation"></i></div>
            <div>
                <div class="lbl"><?= $rtl?'متصاعدة':'Escalated' ?></div>
                <div class="val"><?= number_format($stats['escalated']) ?></div>
                <div class="sub"><?= $stats['stalled'] ?> <?= $rtl?'متوقفة':'stalled' ?></div>
            </div>
        </div>
        <div class="mp-kpi">
            <div class="mp-kpi-ico" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="lbl"><?= $rtl?'محلولة':'Resolved' ?></div>
                <div class="val"><?= number_format($stats['resolved']) ?></div>
                <div class="sub"><?= $rtl?'متوسط':'Avg' ?>: <?= round($stats['avg_resolution_days'], 1) ?> <?= $rtl?'يوم':'days' ?></div>
            </div>
        </div>
    </div>

    <!-- Reports Cards -->
    <?php if (empty($visible_reports)): ?>
        <div class="mp-empty">
            <i class="fa-solid fa-lock"></i>
            <h3><?= $rtl?'لا توجد تقارير متاحة':'No reports available' ?></h3>
            <p><?= $rtl?'تواصل مع مدير النظام لمنحك الصلاحيات':'Contact admin to grant you permissions' ?></p>
        </div>
    <?php else: ?>
        <div class="mp-cards">
            <?php foreach ($visible_reports as $r): ?>
                <a href="<?= e($r['id']) ?>.php" class="mp-card" style="--accent: <?= e($r['color']) ?>">
                    <div class="mp-card-h">
                        <div class="mp-card-ico"><i class="fa-solid <?= e($r['icon']) ?>"></i></div>
                        <h3 class="mp-card-title"><?= e($rtl ? $r['title_ar'] : $r['title_en']) ?></h3>
                    </div>
                    <p class="mp-card-desc"><?= e($rtl ? $r['desc_ar'] : $r['desc_en']) ?></p>
                    <div class="mp-card-foot">
                        <span class="mp-card-kpi"><?= e($r['kpi']) ?></span>
                        <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?> mp-card-arrow"></i>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Recent + Status Distribution -->
    <div class="mp-row-2">
        <div class="mp-section">
            <div class="mp-section-h">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <?= $rtl?'آخر البلاغات':'Recent Complaints' ?>
                <span class="ct"><?= count($recent) ?></span>
            </div>
            <div class="mp-recent">
                <?php if (!$recent): ?>
                    <div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px"><?= $rtl?'لا توجد بلاغات':'No complaints' ?></div>
                <?php else: foreach ($recent as $c):
                    $prio_class = 'mp-prio-' . ($c['priority'] ?? 'normal');
                    $st_color = $STATUS_COLOR[$c['status']] ?? '#475569';
                ?>
                    <a href="<?= BASE_URL ?>/complaints/view.php?id=<?= (int)$c['id'] ?>" class="mp-recent-item" style="text-decoration:none;color:inherit">
                        <span class="num"><?= e($c['request_number']) ?></span>
                        <div class="ttl">
                            <div class="t"><?= e(mb_substr($c['description'] ?? '—', 0, 50, 'UTF-8')) ?></div>
                            <div class="m">
                                <i class="fa-solid fa-user"></i> <?= e($c['requester_name'] ?? '—') ?>
                                · <?= e($c['dept_name'] ?? '—') ?>
                            </div>
                        </div>
                        <span class="mp-st-pill" style="background:<?= $st_color ?>22;color:<?= $st_color ?>">
                            <?= e($STATUS_AR[$c['status']] ?? $c['status']) ?>
                        </span>
                        <span class="mp-prio <?= $prio_class ?>">
                            <?= e($c['priority'] ?? 'normal') ?>
                        </span>
                        <span class="age"><?= (int)$c['age_days'] ?>d</span>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="mp-section">
            <div class="mp-section-h">
                <i class="fa-solid fa-chart-bar"></i>
                <?= $rtl?'التوزيع حسب الحالة':'Distribution by Status' ?>
                <span class="ct"><?= count($by_status) ?></span>
            </div>
            <div class="mp-status-row">
                <?php if (!$by_status): ?>
                    <div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px">—</div>
                <?php else: foreach ($by_status as $s):
                    $color = $STATUS_COLOR[$s['status']] ?? '#475569';
                    $pct = round((int)$s['cnt'] / $max_status_cnt * 100);
                ?>
                    <div class="mp-status-item">
                        <span class="d" style="background:<?= $color ?>"></span>
                        <span class="n"><?= e($STATUS_AR[$s['status']] ?? $s['status']) ?></span>
                        <div class="bar"><div style="width:<?= $pct ?>%;background:<?= $color ?>"></div></div>
                        <span class="v"><?= (int)$s['cnt'] ?></span>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

</div>
</main>
</div>
</body>
</html>
