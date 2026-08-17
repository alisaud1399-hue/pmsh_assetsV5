<?php
/**
 * reports/assets/index.php — مركز تقارير الأصول (Assets Reports Hub)
 * ──────────────────────────────────────────────────────────────────
 *   • صفحة تجميعية لكل تقارير الأصول (مثل مركز NUPCو)
 *   • تظهر فقط التقارير اللي المستخدم عنده صلاحية عليها
 *   • 4 KPIs سريعة + 6 بطاقات تقارير
 *   • نشاط حديث: آخر الأصول المُضافة / المُحدَّثة
 *
 *   البنية: كل بطاقة تقرير تتحقق من صلاحية منفصلة
 *   (can('reports.assets.overview', 'view') مثلاً) لتفاصيل أدق.
 *   افتراضياً: أي صلاحية reports.assets.* تكفي.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.assets');

$can_export  = can('reports.assets', 'export');
$excel_mode  = report_excel_mode_active('reports.assets');
$print_mode  = report_print_mode_active('reports.assets');
$print_charts = report_print_charts_mode_active('reports.assets');

$rtl = is_rtl();
$page_title = $rtl ? 'تقارير الأصول' : 'Asset Reports';
$page_icon  = 'fa-chart-pie';
$active_nav = 'reports.assets';
// الـ hub هو نقطة الدخول لمجموعة التقارير (ما في صفحة "reports/index.php" منفصلة)
$breadcrumb = [
    ['name' => $rtl ? 'تقارير الأصول' : 'Asset Reports'],
];

// ═══ الإحصائيات السريعة (KPIs) ═══
$stats = [];
$stats['total']         = (int)$pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
$stats['active']        = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='active'")->fetchColumn();
$stats['pending']       = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='pending_govt_registration'")->fetchColumn();
$stats['without_tag']   = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE tag_number IS NULL OR TRIM(tag_number) = ''")->fetchColumn();
$stats['criticality_A'] = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE criticality_class='A'")->fetchColumn();
$stats['incomplete']    = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE data_completeness IN ('partial','minimal')")->fetchColumn();
$stats['nupco_matched']  = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE item_code IS NOT NULL AND TRIM(item_code) != '' AND item_code NOT LIKE 'WC%'")->fetchColumn();
$stats['disposed']      = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE status='disposed'")->fetchColumn();

// ═══ قائمة التقارير (Feature Cards) ═══
$reports = [
    [
        'id'          => 'overview',
        'icon'        => 'fa-chart-pie',
        'color'       => '#4f46e5',
        'gradient'    => 'linear-gradient(135deg, #4f46e5, #7c3aed)',
        'title_ar'    => 'ملخص الأصول',
        'title_en'    => 'Asset Overview',
        'desc_ar'     => 'إحصائيات شاملة + فلاتر متعددة (نوع/قسم/حساسية/موقع) + تصدير Excel وPDF',
        'desc_en'     => 'Full stats + multi-filter (type/dept/criticality/location) + Excel/PDF export',
        'permission'  => 'reports.assets',
        'kpi_label_ar' => 'إجمالي الأصول',
        'kpi_value'   => number_format($stats['total']),
        'kpi_color'   => '#4f46e5',
        'href'        => 'overview.php',
        'available'   => true,
    ],
    [
    'id'          => 'twin',
    'icon'        => 'fa-cubes',
    'color'       => '#06b6d4',
    'gradient'    => 'linear-gradient(135deg, #06b6d4, #3b82f6)',
    'title_ar'    => 'الخريطة الرقمية',
    'title_en'    => 'Digital Twin',
    'desc_ar'     => 'تصوير حي لصحة الأصول ومخاطرها وتغطية الجرد عبر المباني والطوابق والغرف — مع بحث دبّوس',
    'desc_en'     => 'Live visualization of asset health, risk, and audit coverage across buildings/floors/rooms — with pin search',
    'permission'  => 'reports.assets',
    'kpi_label_ar' => 'مباني مُموضَعة',
    'kpi_value'   => (int)$pdo->query("SELECT COUNT(DISTINCT loc_building) FROM assets WHERE loc_building IS NOT NULL AND loc_building != ''")->fetchColumn(),
    'kpi_color'   => '#06b6d4',
    'href'        => 'twin.php',
    'available'   => true,
    ],
    [
        'id'          => 'by_criticality',
        'icon'        => 'fa-shield-halved',
        'color'       => '#dc2626',
        'gradient'    => 'linear-gradient(135deg, #dc2626, #f97316)',
        'title_ar'    => 'حسب الحساسية (A/B/C)',
        'title_en'    => 'By Criticality (A/B/C)',
        'desc_ar'     => 'تصنيف الأصول حسب الحرجة + ربط بفريق الصيانة المسؤول (طبية/عامة/IT)',
        'desc_en'     => 'Asset criticality classification + assigned maintenance team',
        'permission'  => 'reports.assets',
        'kpi_label_ar' => 'أصول A (حرج)',
        'kpi_value'   => number_format($stats['criticality_A']),
        'kpi_color'   => '#dc2626',
        'href'        => '#',
        'available'   => false,
        'coming_soon' => true,
    ],
    [
        'id'          => 'completeness',
        'icon'        => 'fa-circle-check',
        'color'       => '#16a34a',
        'gradient'    => 'linear-gradient(135deg, #16a34a, #4ade80)',
        'title_ar'    => 'اكتمال البيانات',
        'title_en'    => 'Data Completeness',
        'desc_ar'     => 'نسبة اكتمال كل أصل (تاج/سيريال/موقع/فئة/...) + قائمة الأولويات',
        'desc_en'     => 'Per-asset completeness score (tag/serial/location/category) + priority list',
        'permission'  => 'reports.assets',
        'kpi_label_ar' => 'بيانات ناقصة',
        'kpi_value'   => number_format($stats['incomplete']),
        'kpi_color'   => '#16a34a',
        'href'        => '../../assets/unclassified_items.php',
        'available'   => true,
        'external'    => true,
    ],
    [
        'id'          => 'no_tag',
        'icon'        => 'fa-tags',
        'color'       => '#f59e0b',
        'gradient'    => 'linear-gradient(135deg, #f59e0b, #fbbf24)',
        'title_ar'    => 'أصول بدون تاج/رقم أصل',
        'title_en'    => 'Assets Without Tag/Asset#',
        'desc_ar'     => 'الأصول اللي ما تم تسجيل tag_number أو asset_number لها — تحتاج إكمال من موارد',
        'desc_en'     => 'Assets without tag_number or asset_number — need MOWAREF registration',
        'permission'  => 'reports.assets',
        'kpi_label_ar' => 'بدون تاج/رقم',
        'kpi_value'   => number_format($stats['without_tag']),
        'kpi_color'   => '#f59e0b',
        'href'        => '../../assets/pending_registration.php',
        'available'   => true,
        'external'    => true,
    ],
    [
        'id'          => 'nupco_status',
        'icon'        => 'fa-link',
        'color'       => '#7c3aed',
        'gradient'    => 'linear-gradient(135deg, #7c3aed, #a855f7)',
        'title_ar'    => 'حالة الربط مع NUPCO',
        'title_en'    => 'NUPCO Match Status',
        'desc_ar'     => 'نسبة الأصول المربوطة بـ NUPCO (item_code) + الأصول اللي تحتاج مطابقة',
        'desc_en'     => 'NUPCO match ratio (item_code) + assets needing match',
        'permission'  => 'reports.assets',
        'kpi_label_ar' => 'مربوط بـ NUPCO',
        'kpi_value'   => number_format($stats['nupco_matched']),
        'kpi_color'   => '#7c3aed',
        'href'        => '../../assets/update_from_nupco.php',
        'available'   => true,
        'external'    => true,
    ],
    [
        'id'          => 'by_department',
        'icon'        => 'fa-building',
        'color'       => '#0891b2',
        'gradient'    => 'linear-gradient(135deg, #0891b2, #06b6d4)',
        'title_ar'    => 'حسب القسم',
        'title_en'    => 'By Department',
        'desc_ar'     => 'توزيع الأصول على الأقسام + مقارنة بين الإدارات + نصيب كل قسم من الإجمالي',
        'desc_en'     => 'Asset distribution per department + inter-dept comparison',
        'permission'  => 'reports.assets',
        'kpi_label_ar' => 'عدد الأقسام',
        'kpi_value'   => (int)$pdo->query("SELECT COUNT(DISTINCT custodian_dept_id) FROM assets WHERE custodian_dept_id IS NOT NULL")->fetchColumn(),
        'kpi_color'   => '#0891b2',
        'href'        => '#',
        'available'   => false, // مخطط مستقبلي
        'coming_soon' => true,
    ],
    // ملاحظة: 'سجل التخلص' (disposal) كان موجود هنا، لكنه إجراء وليس تقرير —
    // موجود الآن في تبويب 'دورة الأصل' في السايدبار (disposal/index.php).
];

// ═══ فلترة التقارير حسب الصلاحيات ═══
$visible_reports = [];
foreach ($reports as $r) {
    if (can($r['permission'], 'view')) {
        $visible_reports[] = $r;
    }
}

// ═══ النشاط الحديث: آخر 5 أصول مُضافة أو مُعدَّلة ═══
$recent = $pdo->query("
    SELECT a.id, a.description, a.tag_number, a.status, a.updated_at, a.criticality_class,
           d.name AS dept_name
    FROM assets a
    LEFT JOIN departments d ON d.id = a.custodian_dept_id
    ORDER BY a.updated_at DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$STATUS_AR = [
    'active' => 'نشط', 'under_maintenance' => 'صيانة', 'inactive' => 'متوقف',
    'pending_commissioning' => 'بانتظار التشغيل', 'pending_receipt' => 'بانتظار الاستلام',
    'pending_govt_registration' => 'بانتظار التسجيل', 'disposed' => 'مُستبعَد',
    'transferred' => 'محوَّل', 'returned_to_supplier' => 'مُرتجع',
];
$CRIT_AR = ['A' => 'حرج', 'B' => 'مهم', 'C' => 'عادي'];

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
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root {
  --bg-soft: #f8fafc;
  --shadow-sm: 0 2px 4px rgba(15,23,42,.05);
  --shadow-md: 0 4px 14px rgba(15,23,42,.08);
  --shadow-lg: 0 12px 28px rgba(15,23,42,.12);
}
body, button, input, select, textarea { font-family:'Tajawal',sans-serif; }
.ah-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }

/* ═══ HERO ═══ */
.ah-hero {
  position: relative;
  background: linear-gradient(135deg, #1e293b 0%, #4f46e5 50%, #7c3aed 100%);
  color: #fff;
  border-radius: 22px;
  padding: 28px 32px;
  margin-bottom: 22px;
  box-shadow: 0 12px 32px rgba(79, 70, 229, 0.25);
  overflow: hidden;
}
.ah-hero::before {
  content: '';
  position: absolute; top: -50px; right: -50px;
  width: 220px; height: 220px;
  background: radial-gradient(circle, rgba(255,255,255,.1) 0%, transparent 70%);
  border-radius: 50%;
}
.ah-hero-content { display: flex; align-items: center; gap: 26px; position: relative; z-index: 1; }
.ah-hero-ico {
  width: 90px; height: 90px;
  background: rgba(255,255,255,.15);
  border: 2px solid rgba(255,255,255,.25);
  border-radius: 18px;
  display: flex; align-items: center; justify-content: center;
  font-size: 40px; color: #fff;
  flex-shrink: 0;
  box-shadow: 0 8px 20px rgba(0,0,0,.15);
}
.ah-hero-text { flex: 1; min-width: 0; }
.ah-hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 800; letter-spacing: -.5px; }
.ah-hero .ah-subtitle { font-size: 14px; opacity: .85; font-weight: 500; }
.ah-hero .ah-counter {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,.18);
  border: 1px solid rgba(255,255,255,.3);
  padding: 5px 12px; border-radius: 20px;
  font-size: 12.5px; font-weight: 700; margin-top: 8px;
}

/* ═══ STATS GRID (4 KPIs) ═══ */
.ah-stats {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 14px; margin-bottom: 22px;
}
@media (max-width: 920px) { .ah-stats { grid-template-columns: repeat(2, 1fr); } }
.ah-stat {
  background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px;
  padding: 16px 18px; display: flex; align-items: center; gap: 14px;
  box-shadow: var(--shadow-sm);
  transition: all .2s;
}
.ah-stat:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.ah-stat-ico {
  width: 46px; height: 46px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
}
.ah-stat-val { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1; }
.ah-stat-lbl { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 600; }

/* ═══ SECTION TITLE ═══ */
.ah-section-title {
  font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 14px;
  display: flex; align-items: center; gap: 10px; font-family: 'Tajawal', sans-serif;
}
.ah-section-title i { color: #4f46e5; }

/* ═══ FEATURE CARDS ═══ */
.ah-cards {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 16px; margin-bottom: 22px;
}
@media (max-width: 1100px) { .ah-cards { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 700px)  { .ah-cards { grid-template-columns: 1fr; } }

.ah-card {
  position: relative;
  background: #fff; border: 1.5px solid #e2e8f0; border-radius: 18px;
  text-decoration: none; color: inherit;
  display: flex; flex-direction: column;
  overflow: hidden;
  transition: all .25s ease;
  box-shadow: var(--shadow-sm);
}
.ah-card:hover {
  transform: translateY(-3px); box-shadow: var(--shadow-md);
  border-color: var(--accent, #4f46e5);
}
.ah-card.disabled {
  cursor: default; opacity: 0.65;
}
.ah-card.disabled:hover {
  transform: none; box-shadow: var(--shadow-sm);
  border-color: #e2e8f0;
}
.ah-card-head {
  padding: 18px 18px 12px;
  background: var(--gradient, #4f46e5);
  color: #fff; position: relative;
  min-height: 60px;
  display: flex; align-items: center; gap: 12px;
}
.ah-card-ico {
  width: 44px; height: 44px; border-radius: 10px;
  background: rgba(255,255,255,.2);
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
}
.ah-card-title {
  font-size: 15px; font-weight: 800; line-height: 1.3; flex: 1;
}
.ah-card-body { padding: 14px 18px; flex: 1; }
.ah-card-desc {
  font-size: 12.5px; color: #475569; line-height: 1.55;
  margin-bottom: 12px; min-height: 50px;
}
.ah-card-kpi {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 10px; background: #f8fafc; border-radius: 8px;
  font-size: 12px; color: #64748b; font-weight: 700;
}
.ah-card-kpi .v { font-size: 18px; font-weight: 800; color: var(--accent); }
.ah-card-foot {
  padding: 10px 18px 14px;
  border-top: 1px solid #f1f5f9;
  display: flex; align-items: center; gap: 8px;
  font-size: 12.5px; font-weight: 700;
}
.ah-card-foot .open { color: var(--accent); margin-inline-start: auto; }
.ah-card-foot .soon {
  background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 10px; font-size: 11px;
}
.ah-card-foot i { color: var(--accent); }

/* ═══ RECENT ACTIVITY ═══ */
.ah-recent {
  background: #fff; border: 1.5px solid #e2e8f0; border-radius: 18px;
  overflow: hidden; box-shadow: var(--shadow-sm);
}
.ah-recent-head {
  padding: 14px 18px; border-bottom: 1px solid #f1f5f9;
  background: linear-gradient(135deg, #f8fafc, #fff);
  display: flex; align-items: center; gap: 10px;
  font-weight: 800; color: #0f172a; font-size: 14.5px;
}
.ah-recent-head i { color: #4f46e5; }
.ah-recent-list { padding: 4px 0; }
.ah-recent-item {
  padding: 10px 18px; display: flex; align-items: center; gap: 12px;
  border-bottom: 1px solid #f8fafc;
  transition: background .15s;
}
.ah-recent-item:hover { background: #f8fafc; }
.ah-recent-item:last-child { border-bottom: none; }
.ah-recent-tag {
  font-family: 'Inter', monospace; font-size: 12px;
  background: #e0e7ff; color: #4338ca;
  padding: 3px 8px; border-radius: 6px; font-weight: 700;
}
.ah-recent-desc { flex: 1; font-size: 13px; color: #334155; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ah-recent-dept { font-size: 11.5px; color: #64748b; font-weight: 600; }
.ah-recent-time { font-size: 11px; color: #94a3b8; }
.ah-crit {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%;
  font-weight: 800; font-size: 11px; color: #fff; font-family: 'Inter', sans-serif;
}
.ah-crit.A { background: #dc2626; }
.ah-crit.B { background: #d97706; }
.ah-crit.C { background: #16a34a; }

.ah-empty {
  text-align: center; padding: 60px 20px; color: #94a3b8;
  background: #fff; border: 1.5px dashed #e2e8f0; border-radius: 18px;
}
.ah-empty i { font-size: 48px; margin-bottom: 12px; color: #cbd5e1; }
.ah-empty h3 { color: #475569; margin: 0 0 6px; font-size: 16px; }
.ah-empty p { font-size: 13px; margin: 0; }
</style>
</head>
<body class="app-layout">

<?php include __DIR__ . '/../../includes/sidebar.php'; ?>

<div class="main-area">
<?php include __DIR__ . '/../../includes/topbar.php'; ?>
<main class="page-content"><div class="ah-wrap">

  <!-- ═══ HERO ═══ -->
  <section class="ah-hero">
    <div class="ah-hero-content">
      <div class="ah-hero-ico"><i class="fa-solid fa-chart-pie"></i></div>
      <div class="ah-hero-text">
        <h1><?= $rtl ? 'تقارير الأصول' : 'Asset Reports' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.assets') ?>
            </div>
        <div class="ah-subtitle"><?= $rtl ? 'مركز موحَّد لكل تقارير الأصول — مع صلاحيات دقيقة' : 'Unified hub for all asset reports — with fine-grained permissions' ?></div>
        <div class="ah-counter">
          <i class="fa-solid fa-chart-bar"></i>
          <?= $rtl ? 'يتوفر' : 'Available' ?>: <strong><?= count($visible_reports) ?></strong> <?= $rtl ? 'تقرير' : 'reports' ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ═══ 4 KPIs سريعة ═══ -->
  <div class="ah-stats">
    <div class="ah-stat">
      <div class="ah-stat-ico" style="background:#ede9fe; color:#7c3aed"><i class="fa-solid fa-boxes-stacked"></i></div>
      <div>
        <div class="ah-stat-val"><?= number_format($stats['total']) ?></div>
        <div class="ah-stat-lbl"><?= $rtl ? 'إجمالي الأصول' : 'Total assets' ?></div>
      </div>
    </div>
    <div class="ah-stat">
      <div class="ah-stat-ico" style="background:#dcfce7; color:#16a34a"><i class="fa-solid fa-circle-check"></i></div>
      <div>
        <div class="ah-stat-val"><?= number_format($stats['active']) ?></div>
        <div class="ah-stat-lbl"><?= $rtl ? 'نشط' : 'Active' ?></div>
      </div>
    </div>
    <div class="ah-stat">
      <div class="ah-stat-ico" style="background:#fef3c7; color:#d97706"><i class="fa-solid fa-hourglass-half"></i></div>
      <div>
        <div class="ah-stat-val"><?= number_format($stats['pending']) ?></div>
        <div class="ah-stat-lbl"><?= $rtl ? 'بانتظار التسجيل (موارد)' : 'Pending MOWAREF' ?></div>
      </div>
    </div>
    <div class="ah-stat">
      <div class="ah-stat-ico" style="background:#fee2e2; color:#dc2626"><i class="fa-solid fa-tags"></i></div>
      <div>
        <div class="ah-stat-val"><?= number_format($stats['without_tag']) ?></div>
        <div class="ah-stat-lbl"><?= $rtl ? 'بدون تاج/رقم أصل' : 'No tag/asset#' ?></div>
      </div>
    </div>
  </div>

  <!-- ═══ FEATURE CARDS (التقارير المتاحة) ═══ -->
  <h2 class="ah-section-title">
    <i class="fa-solid fa-table-list"></i>
    <?= $rtl ? 'التقارير المتاحة' : 'Available Reports' ?>
  </h2>

  <?php if (empty($visible_reports)): ?>
    <div class="ah-empty">
      <i class="fa-solid fa-lock"></i>
      <h3><?= $rtl ? 'لا توجد تقارير متاحة لك' : 'No reports available for you' ?></h3>
      <p><?= $rtl ? 'تواصل مع مدير النظام لمنحك الصلاحيات اللازمة' : 'Contact your system admin to grant you the required permissions' ?></p>
    </div>
  <?php else: ?>
  <div class="ah-cards">
    <?php foreach ($visible_reports as $r):
        $is_disabled = !empty($r['coming_soon']) || empty($r['available']);
        $tag = $is_disabled ? 'div' : 'a';
        $href = $is_disabled ? '' : ' href="' . e($r['href']) . '"';
    ?>
    <<?= $tag ?><?= $href ?>
      class="ah-card <?= $is_disabled ? 'disabled' : '' ?>"
      style="--accent: <?= e($r['color']) ?>; --gradient: <?= e($r['gradient']) ?>"
    >
      <div class="ah-card-head">
        <div class="ah-card-ico"><i class="fa-solid <?= e($r['icon']) ?>"></i></div>
        <div class="ah-card-title"><?= $rtl ? e($r['title_ar']) : e($r['title_en']) ?></div>
      </div>
      <div class="ah-card-body">
        <div class="ah-card-desc"><?= $rtl ? e($r['desc_ar']) : e($r['desc_en']) ?></div>
        <div class="ah-card-kpi">
          <i class="fa-solid fa-chart-line" style="color: <?= e($r['kpi_color']) ?>"></i>
          <span class="v" style="color: <?= e($r['kpi_color']) ?>"><?= e($r['kpi_value']) ?></span>
          <span><?= e($r['kpi_label_ar']) ?></span>
        </div>
      </div>
      <div class="ah-card-foot">
        <?php if (!empty($r['coming_soon'])): ?>
          <span class="soon"><i class="fa-solid fa-clock"></i> <?= $rtl ? 'قريباً' : 'Coming soon' ?></span>
        <?php elseif (!empty($r['external'])): ?>
          <i class="fa-solid fa-arrow-up-right-from-square"></i>
          <span><?= $rtl ? 'صفحة منفصلة' : 'Separate page' ?></span>
          <span class="open"><?= $rtl ? 'فتح' : 'Open' ?> <i class="fa-solid fa-arrow-left"></i></span>
        <?php else: ?>
          <i class="fa-solid fa-file-invoice"></i>
          <span><?= $rtl ? 'تقرير كامل' : 'Full report' ?></span>
          <span class="open"><?= $rtl ? 'فتح' : 'Open' ?> <i class="fa-solid fa-arrow-left"></i></span>
        <?php endif; ?>
      </div>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ═══ النشاط الحديث ═══ -->
  <?php if (!empty($recent)): ?>
  <h2 class="ah-section-title" style="margin-top: 28px">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <?= $rtl ? 'آخر نشاط على الأصول' : 'Recent Asset Activity' ?>
  </h2>
  <div class="ah-recent">
    <div class="ah-recent-head">
      <i class="fa-solid fa-pen-to-square"></i>
      <?= $rtl ? 'آخر 5 أصول تم تعديلها أو إضافتها' : 'Last 5 assets modified or added' ?>
    </div>
    <div class="ah-recent-list">
      <?php foreach ($recent as $r):
        $crit = $r['criticality_class'] ?: 'C';
        $status_label = $STATUS_AR[$r['status']] ?? $r['status'];
        $time_ago = time() - strtotime($r['updated_at']);
        if ($time_ago < 60) $ago = $rtl ? 'الآن' : 'just now';
        elseif ($time_ago < 3600) $ago = floor($time_ago/60) . ' ' . ($rtl ? 'د' : 'm');
        elseif ($time_ago < 86400) $ago = floor($time_ago/3600) . ' ' . ($rtl ? 'س' : 'h');
        else $ago = floor($time_ago/86400) . ' ' . ($rtl ? 'ي' : 'd');
      ?>
      <a href="<?= BASE_URL ?>/assets/view.php?id=<?= (int)$r['id'] ?>" class="ah-recent-item" style="text-decoration:none; color:inherit">
        <span class="ah-crit <?= e($crit) ?>"><?= e($crit) ?></span>
        <?php if (!empty($r['tag_number'])): ?>
          <span class="ah-recent-tag"><?= e($r['tag_number']) ?></span>
        <?php else: ?>
          <span class="ah-recent-tag" style="background:#fee2e2; color:#b91c1c"><?= $rtl ? 'بلا تاج' : 'no tag' ?></span>
        <?php endif; ?>
        <div class="ah-recent-desc"><?= e(truncate($r['description'] ?? '—', 50)) ?></div>
        <div class="ah-recent-dept"><?= e($r['dept_name'] ?? '—') ?></div>
        <div class="ah-recent-time" title="<?= e($r['updated_at']) ?>"><?= e($ago) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div></main>
</div><!-- /.main-area -->

<?php // لا يوجد includes/footer.php — يتم إغلاق الصفحة inline ?>
</body>
</html>
