<?php
/**
* reports/receiving/index.php — مركز تقارير الاستلام والتشغيل (Receiving Hub)
* ─────────────────────────────────────────────────────────────────
*   • نقطة الدخول الموحدة لتقارير دورة التوريد
*   • 4 KPIs سريعة + 6 بطاقات تقارير + آخر النشاط
*   • نفس نمط hub الأصول بألوان ذهبية (طبيعة التوريد)
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.receiving');

$rtl = is_rtl();
$page_title = $rtl ? 'تقارير الاستلام والتشغيل' : 'Receiving & Commissioning Reports';
$page_icon  = 'fa-truck-ramp-box';
$active_nav = 'reports.receiving';

$breadcrumb = [
    ['name' => $rtl ? 'تقارير الاستلام والتشغيل' : 'Receiving Reports'],
];

// ═══ الإحصائيات السريعة ═══
$stats = [];
$stats['total_po'] = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders")->fetchColumn();
$stats['completed_po'] = (int)$pdo->query("SELECT COUNT(*) FROM purchase_orders WHERE status='completed'")->fetchColumn();
$stats['total_rm'] = (int)$pdo->query("SELECT COUNT(*) FROM receiving_minutes")->fetchColumn();
$stats['approved_rm'] = (int)$pdo->query("SELECT COUNT(*) FROM receiving_minutes WHERE status='approved'")->fetchColumn();
$stats['pending_rm'] = (int)$pdo->query("SELECT COUNT(*) FROM receiving_minutes WHERE status='sent_to_supplier'")->fetchColumn();
$stats['total_cc'] = (int)$pdo->query("SELECT COUNT(*) FROM commissioning_certificates")->fetchColumn();
$stats['approved_cc'] = (int)$pdo->query("SELECT COUNT(*) FROM commissioning_certificates WHERE status='approved'")->fetchColumn();
$stats['transferred_cc'] = (int)$pdo->query("SELECT COUNT(*) FROM commissioning_certificates WHERE status='approved' AND transferred_at IS NOT NULL")->fetchColumn();
$stats['po_value'] = (float)$pdo->query("SELECT COALESCE(SUM(total_value),0) FROM purchase_orders WHERE status='completed'")->fetchColumn();

// Lead Time (استلام → تشغيل)
$lead = $pdo->query("
    SELECT AVG(DATEDIFF(cc.date_gregorian, rm.receipt_date)) AS avg_lead
    FROM commissioning_certificates cc
    INNER JOIN receiving_minutes rm ON rm.id = cc.receiving_minute_id
    WHERE cc.status = 'approved'
      AND cc.date_gregorian IS NOT NULL
      AND rm.receipt_date IS NOT NULL
      AND cc.date_gregorian >= rm.receipt_date
")->fetchColumn();
$stats['avg_lead'] = $lead !== null && $lead !== false ? round((float)$lead, 1) : 0;

// ═══ قائمة التقارير ═══
$reports = [
    [
        'id'          => 'overview',
        'icon'        => 'fa-chart-pie',
        'color'       => '#a16207',
        'gradient'    => 'linear-gradient(135deg, #713f12, #a16207, #ca8a04)',
        'title_ar'    => 'ملخص الاستلام والتشغيل',
        'title_en'    => 'Receiving & Commissioning Overview',
        'desc_ar'     => 'نظرة شاملة: أوامر الشراء ← محاضر الاستلام ← شهادات التشغيل ← Lead Time، مع فلاتر متعددة وتصدير Excel/PDF',
        'desc_en'     => 'Full cycle view: POs → Receiving → Commissioning → Lead Time, with filters & Excel/PDF export',
        'permission'  => 'reports.receiving.overview',
        'kpi_label_ar'=> 'Lead Time (يوم)',
        'kpi_value'   => number_format($stats['avg_lead'], 1),
        'kpi_color'   => '#a16207',
        'href'        => 'overview.php',
        'available'   => true,
    ],
    [
        'id'          => 'purchase_orders',
        'icon'        => 'fa-file-invoice-dollar',
        'color'       => '#ca8a04',
        'gradient'    => 'linear-gradient(135deg, #78350f, #ca8a04)',
        'title_ar'    => 'أوامر الشراء',
        'title_en'    => 'Purchase Orders',
        'desc_ar'     => 'سجل تفصيلي لأوامر الشراء + الموردين + القيم + الحالات + اتجاه 12 شهر',
        'desc_en'     => 'Detailed PO register + suppliers + values + statuses + 12-month trend',
        'permission'  => 'reports.receiving',
        'kpi_label_ar'=> 'أوامر شراء',
        'kpi_value'   => number_format($stats['total_po']),
        'kpi_color'   => '#ca8a04',
        'href'        => '#',
        'available'   => false,
        'coming_soon' => true,
    ],
    [
        'id'          => 'receiving_minutes',
        'icon'        => 'fa-clipboard-check',
        'color'       => '#0891b2',
        'gradient'    => 'linear-gradient(135deg, #164e63, #0891b2)',
        'title_ar'    => 'محاضر الاستلام',
        'title_en'    => 'Receiving Minutes',
        'desc_ar'     => 'كل محاضر الاستلام + البنود + الموردين + القيم + لجان الاستلام',
        'desc_en'     => 'All receiving minutes + items + suppliers + values + committees',
        'permission'  => 'reports.receiving',
        'kpi_label_ar'=> 'محاضر معتمدة',
        'kpi_value'   => number_format($stats['approved_rm']),
        'kpi_color'   => '#0891b2',
        'href'        => '#',
        'available'   => false,
        'coming_soon' => true,
    ],
    [
        'id'          => 'commissioning',
        'icon'        => 'fa-gears',
        'color'       => '#7c3aed',
        'gradient'    => 'linear-gradient(135deg, #4c1d95, #7c3aed)',
        'title_ar'    => 'شهادات التشغيل',
        'title_en'    => 'Commissioning Certificates',
        'desc_ar'     => 'شهادات التشغيل + الضمان + المطابقة للمواصفات + حالة النقل للأصول',
        'desc_en'     => 'Certificates + warranty + spec compliance + transfer to assets status',
        'permission'  => 'reports.receiving',
        'kpi_label_ar'=> 'شهادات معتمدة',
        'kpi_value'   => number_format($stats['approved_cc']),
        'kpi_color'   => '#7c3aed',
        'href'        => '#',
        'available'   => false,
        'coming_soon' => true,
    ],
    [
        'id'          => 'suppliers',
        'icon'        => 'fa-handshake',
        'color'       => '#059669',
        'gradient'    => 'linear-gradient(135deg, #064e3b, #059669)',
        'title_ar'    => 'أداء الموردين',
        'title_en'    => 'Supplier Performance',
        'desc_ar'     => 'Leaderboard الموردين + تقييم الأداء (تسليم / جودة / زمن) + العقود النشطة',
        'desc_en'     => 'Supplier leaderboard + performance rating (delivery/quality/time) + active contracts',
        'permission'  => 'reports.receiving',
        'kpi_label_ar'=> 'قيمة المشتريات',
        'kpi_value'   => number_format($stats['po_value'] / 1000, 0) . 'k',
        'kpi_color'   => '#059669',
        'href'        => '#',
        'available'   => false,
        'coming_soon' => true,
    ],
    [
        'id'          => 'lead_time',
        'icon'        => 'fa-stopwatch',
        'color'       => '#dc2626',
        'gradient'    => 'linear-gradient(135deg, #7f1d1d, #dc2626)',
        'title_ar'    => 'تحليل Lead Time',
        'title_en'    => 'Lead Time Analysis',
        'desc_ar'     => 'تحليل زمن الاستلام→التشغيل حسب المورد/القسم/الفئة + الاختناقات',
        'desc_en'     => 'Receipt-to-commissioning time by supplier/dept/category + bottlenecks',
        'permission'  => 'reports.receiving',
        'kpi_label_ar'=> 'متوسط Lead',
        'kpi_value'   => $stats['avg_lead'] . ' يوم',
        'kpi_color'   => '#dc2626',
        'href'        => '#',
        'available'   => false,
        'coming_soon' => true,
    ],
];

// فلترة التقارير حسب الصلاحيات
$visible_reports = [];
foreach ($reports as $r) {
    if (can($r['permission'], 'view')) {
        $visible_reports[] = $r;
    }
}

// آخر النشاط
$recent_rm = $pdo->query("
    SELECT 'rm' AS kind, id, minute_number AS num, supplier_name AS supplier, receipt_date AS date, status
    FROM receiving_minutes
    WHERE receipt_date IS NOT NULL
    ORDER BY receipt_date DESC
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

$recent_cc = $pdo->query("
    SELECT 'cc' AS kind, id, certificate_number AS num, supplier_name AS supplier, date_gregorian AS date, status
    FROM commissioning_certificates
    WHERE date_gregorian IS NOT NULL
    ORDER BY date_gregorian DESC
    LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC);

$recent = array_merge($recent_rm, $recent_cc);
usort($recent, function($a, $b) {
    return strtotime($b['date'] ?? '1970-01-01') <=> strtotime($a['date'] ?? '1970-01-01');
});
$recent = array_slice($recent, 0, 6);

$RM_STATUS_AR = ['draft'=>'مسودة','sent_to_supplier'=>'مرسلة','approved'=>'معتمدة','rejected'=>'مرفوضة','cancelled'=>'ملغاة'];
$RM_STATUS_COLOR = ['draft'=>'#94a3b8','sent_to_supplier'=>'#0ea5e9','approved'=>'#16a34a','rejected'=>'#dc2626','cancelled'=>'#475569'];
$CC_STATUS_AR = ['draft'=>'مسودة','sent_to_supplier'=>'مرسلة','approved'=>'معتمدة','rejected'=>'مرفوضة'];
$CC_STATUS_COLOR = ['draft'=>'#94a3b8','sent_to_supplier'=>'#0ea5e9','approved'=>'#16a34a','rejected'=>'#dc2626'];
?>
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
    --primary: #a16207;
    --accent: #ca8a04;
    --dark: #422006;
    --bg-soft: #fffbeb;
    --shadow-sm: 0 2px 4px rgba(113,63,18,.05);
    --shadow-md: 0 4px 14px rgba(113,63,18,.08);
    --shadow-lg: 0 12px 28px rgba(113,63,18,.15);
}
body, button, input, select, textarea { font-family:'Tajawal',sans-serif; }
.rh-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }

.rh-hero {
    position: relative;
    background: linear-gradient(135deg, #422006 0%, #713f12 40%, #a16207 80%, #ca8a04 100%);
    color: #fff;
    border-radius: 22px;
    padding: 28px 32px;
    margin-bottom: 22px;
    box-shadow: 0 12px 32px rgba(161,98,7,.30);
    overflow: hidden;
}
.rh-hero::before {
    content: '';
    position: absolute; top: -60px; right: -60px;
    width: 240px; height: 240px;
    background: radial-gradient(circle, rgba(202,138,4,.25) 0%, transparent 70%);
    border-radius: 50%;
}
.rh-hero-content { display: flex; align-items: center; gap: 26px; position: relative; z-index: 1; }
.rh-hero-ico {
    width: 90px; height: 90px;
    background: rgba(255,255,255,.15);
    border: 2px solid rgba(255,255,255,.25);
    border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    font-size: 40px; color: #fff;
    flex-shrink: 0;
    box-shadow: 0 8px 20px rgba(0,0,0,.20);
}
.rh-hero-text { flex: 1; min-width: 0; }
.rh-hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 800; letter-spacing: -.5px; }
.rh-hero .rh-subtitle { font-size: 14px; opacity: .88; font-weight: 500; line-height: 1.6; }
.rh-hero .rh-counter {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.3);
    padding: 5px 12px; border-radius: 20px;
    font-size: 12.5px; font-weight: 700; margin-top: 8px;
}
.rh-flow {
    display: flex; align-items: center; gap: 6px;
    margin-top: 10px; font-size: 11px; font-weight: 700;
    opacity: .85;
}
.rh-flow span {
    background: rgba(255,255,255,.12);
    padding: 3px 9px; border-radius: 6px;
}
.rh-flow i { font-size: 9px; color: #fcd34d; }

.rh-stats {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 14px; margin-bottom: 22px;
}
@media (max-width: 920px) { .rh-stats { grid-template-columns: repeat(2, 1fr); } }
.rh-stat {
    background: #fff; border: 1.5px solid #fef3c7; border-radius: 14px;
    padding: 16px 18px; display: flex; align-items: center; gap: 14px;
    box-shadow: var(--shadow-sm);
    transition: all .2s;
    position: relative;
    overflow: hidden;
}
.rh-stat::before {
    content: '';
    position: absolute; top: 0; right: 0;
    width: 4px; height: 100%;
    background: var(--stat-color, #a16207);
}
.rh-stat:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); border-color: var(--stat-color, #a16207); }
.rh-stat-ico {
    width: 46px; height: 46px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.rh-stat-val { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1; }
.rh-stat-lbl { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 600; }
.rh-stat-sub { font-size: 10.5px; color: #94a3b8; margin-top: 2px; font-weight: 600; }

.rh-section-title {
    font-size: 17px; font-weight: 800; color: #0f172a; margin: 0 0 14px;
    display: flex; align-items: center; gap: 10px; font-family: 'Tajawal', sans-serif;
}
.rh-section-title i { color: var(--primary); }

.rh-cards {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 16px; margin-bottom: 22px;
}
@media (max-width: 1100px) { .rh-cards { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 700px)  { .rh-cards { grid-template-columns: 1fr; } }
.rh-card {
    position: relative;
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 18px;
    text-decoration: none; color: inherit;
    display: flex; flex-direction: column;
    overflow: hidden;
    transition: all .25s ease;
    box-shadow: var(--shadow-sm);
}
.rh-card:hover {
    transform: translateY(-3px); box-shadow: var(--shadow-md);
    border-color: var(--accent, #a16207);
}
.rh-card.disabled { cursor: default; opacity: 0.65; }
.rh-card.disabled:hover { transform: none; box-shadow: var(--shadow-sm); border-color: #e2e8f0; }

.rh-card-head {
    padding: 18px 18px 12px;
    background: var(--gradient, linear-gradient(135deg, #713f12, #a16207));
    color: #fff; position: relative;
    min-height: 60px;
    display: flex; align-items: center; gap: 12px;
}
.rh-card-head::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; bottom: 0;
    background: radial-gradient(circle at top right, rgba(255,255,255,.15), transparent 60%);
}
.rh-card-ico {
    width: 44px; height: 44px; border-radius: 10px;
    background: rgba(255,255,255,.22);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
    position: relative; z-index: 1;
}
.rh-card-title {
    font-size: 15px; font-weight: 800; line-height: 1.3; flex: 1;
    position: relative; z-index: 1;
}
.rh-card-body { padding: 14px 18px; flex: 1; }
.rh-card-desc {
    font-size: 12.5px; color: #475569; line-height: 1.55;
    margin-bottom: 12px; min-height: 50px;
}
.rh-card-kpi {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px; background: #fffbeb; border-radius: 8px;
    font-size: 12px; color: #713f12; font-weight: 700;
    border: 1px solid #fef3c7;
}
.rh-card-kpi .v { font-size: 18px; font-weight: 800; color: var(--accent); }
.rh-card-foot {
    padding: 10px 18px 14px;
    border-top: 1px solid #f1f5f9;
    display: flex; align-items: center; gap: 8px;
    font-size: 12.5px; font-weight: 700;
}
.rh-card-foot .open { color: var(--accent); margin-inline-start: auto; }
.rh-card-foot .soon {
    background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 10px; font-size: 11px;
    font-weight: 800;
}
.rh-card-foot i { color: var(--accent); }

.rh-recent {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 18px;
    overflow: hidden; box-shadow: var(--shadow-sm);
}
.rh-recent-head {
    padding: 14px 18px; border-bottom: 1px solid #f1f5f9;
    background: linear-gradient(135deg, #fffbeb, #fff);
    display: flex; align-items: center; gap: 10px;
    font-weight: 800; color: #0f172a; font-size: 14.5px;
}
.rh-recent-head i { color: var(--primary); }
.rh-recent-list { padding: 4px 0; }
.rh-recent-item {
    padding: 10px 18px; display: flex; align-items: center; gap: 12px;
    border-bottom: 1px solid #f8fafc;
    transition: background .15s;
    text-decoration: none; color: inherit;
}
.rh-recent-item:hover { background: #fffbeb; }
.rh-recent-item:last-child { border-bottom: none; }
.rh-kind-badge {
    font-family: 'Inter', sans-serif; font-size: 10px; font-weight: 800;
    padding: 3px 9px; border-radius: 6px;
    text-transform: uppercase; letter-spacing: .5px;
    flex-shrink: 0; min-width: 54px; text-align: center;
}
.rh-kind-badge.rm { background: #cffafe; color: #0e7490; }
.rh-kind-badge.cc { background: #f3e8ff; color: #7c3aed; }
.rh-num { font-family: 'Inter', monospace; font-size: 12px; font-weight: 700; color: #713f12;
    background: #fef3c7; padding: 3px 8px; border-radius: 6px; flex-shrink: 0; min-width: 80px; text-align: center; }
.rh-supplier { flex: 1; font-size: 13px; color: #334155; font-weight: 600;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rh-status {
    font-size: 10.5px; font-weight: 800; padding: 3px 8px; border-radius: 6px;
    flex-shrink: 0;
}
.rh-date { font-size: 11px; color: #94a3b8; font-weight: 700; flex-shrink: 0; min-width: 85px; text-align: end; }

.rh-pipeline {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1.5px solid #fcd34d;
    border-radius: 14px;
    padding: 14px 18px;
    margin-bottom: 22px;
    display: flex; align-items: center; gap: 16px;
    flex-wrap: wrap;
}
.rh-pipeline-ico {
    width: 42px; height: 42px; border-radius: 10px;
    background: #fcd34d; color: #713f12;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.rh-pipeline-text { flex: 1; font-size: 12.5px; color: #713f12; font-weight: 600; line-height: 1.6; }
.rh-pipeline-text strong { color: #422006; font-weight: 800; }
.rh-pipeline-steps {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 800; color: #713f12;
}
.rh-pipeline-steps .step {
    background: #fff; border: 1px solid #fcd34d;
    padding: 4px 10px; border-radius: 6px;
}
.rh-pipeline-steps .step.done { background: #dcfce7; border-color: #86efac; color: #15803d; }
.rh-pipeline-steps i.arrow { font-size: 9px; color: #a16207; }

.rh-empty {
    text-align: center; padding: 60px 20px; color: #94a3b8;
    background: #fff; border: 1.5px dashed #fef3c7; border-radius: 18px;
}
.rh-empty i { font-size: 48px; margin-bottom: 12px; color: #fcd34d; }
.rh-empty h3 { color: #475569; margin: 0 0 6px; font-size: 16px; }
.rh-empty p { font-size: 13px; margin: 0; }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="rh-wrap">

<section class="rh-hero">
    <div class="rh-hero-content">
        <div class="rh-hero-ico"><i class="fa-solid fa-truck-ramp-box"></i></div>
        <div class="rh-hero-text">
            <h1><?= $rtl ? 'تقارير الاستلام والتشغيل' : 'Receiving & Commissioning Reports' ?></h1>
            <div class="rh-subtitle"><?= $rtl ? 'مركز موحَّد لدورة التوريد كاملة — من أمر الشراء حتى تشغيل الأصل' : 'Unified hub for the full procurement cycle — from PO to commissioning' ?></div>
            <div class="rh-flow">
                <span><i class="fa-solid fa-file-invoice"></i> <?= $rtl?'أمر شراء':'PO' ?></span>
                <i class="fa-solid fa-arrow-left"></i>
                <span><i class="fa-solid fa-truck"></i> <?= $rtl?'استلام':'Receipt' ?></span>
                <i class="fa-solid fa-arrow-left"></i>
                <span><i class="fa-solid fa-gears"></i> <?= $rtl?'تشغيل':'Commission' ?></span>
                <i class="fa-solid fa-arrow-left"></i>
                <span><i class="fa-solid fa-box"></i> <?= $rtl?'أصل':'Asset' ?></span>
            </div>
            <div class="rh-counter">
                <i class="fa-solid fa-chart-bar"></i>
                <?= $rtl ? 'يتوفر' : 'Available' ?>: <strong><?= count($visible_reports) ?></strong> <?= $rtl ? 'تقرير' : 'reports' ?>
            </div>
        </div>
    </div>
</section>

<div class="rh-pipeline">
    <div class="rh-pipeline-ico"><i class="fa-solid fa-route"></i></div>
    <div class="rh-pipeline-text">
        <?= $rtl ? '<strong>دورة التوريد الذكية:</strong> كل أصل يبدأ بأمر شراء، يمر بمحضر استلام، ثم شهادة تشغيل، وأخيراً يُسجَّل في سجل الأصول.' : '<strong>Smart Procurement Cycle:</strong> Every asset starts with a PO, goes through a receiving minute, then a commissioning certificate, and finally registers in the assets ledger.' ?>
    </div>
    <div class="rh-pipeline-steps">
        <span class="step done"><?= number_format($stats['completed_po']) ?> <?= $rtl?'أمر':'PO' ?></span>
        <i class="fa-solid fa-arrow-left arrow"></i>
        <span class="step done"><?= number_format($stats['approved_rm']) ?> <?= $rtl?'استلام':'Receipt' ?></span>
        <i class="fa-solid fa-arrow-left arrow"></i>
        <span class="step done"><?= number_format($stats['approved_cc']) ?> <?= $rtl?'تشغيل':'Comm' ?></span>
        <i class="fa-solid fa-arrow-left arrow"></i>
        <span class="step done"><?= number_format($stats['transferred_cc']) ?> <?= $rtl?'أصل':'Asset' ?></span>
    </div>
</div>

<div class="rh-stats">
    <div class="rh-stat" style="--stat-color:#a16207">
        <div class="rh-stat-ico" style="background:#fef3c7; color:#a16207"><i class="fa-solid fa-file-invoice-dollar"></i></div>
        <div>
            <div class="rh-stat-val"><?= number_format($stats['total_po']) ?></div>
            <div class="rh-stat-lbl"><?= $rtl ? 'أوامر شراء' : 'Purchase Orders' ?></div>
            <div class="rh-stat-sub"><?= number_format($stats['completed_po']) ?> <?= $rtl ? 'مكتملة' : 'completed' ?></div>
        </div>
    </div>
    <div class="rh-stat" style="--stat-color:#0891b2">
        <div class="rh-stat-ico" style="background:#cffafe; color:#0891b2"><i class="fa-solid fa-clipboard-check"></i></div>
        <div>
            <div class="rh-stat-val"><?= number_format($stats['approved_rm']) ?></div>
            <div class="rh-stat-lbl"><?= $rtl ? 'محاضر استلام معتمدة' : 'Approved Receipts' ?></div>
            <div class="rh-stat-sub"><?= number_format($stats['pending_rm']) ?> <?= $rtl ? 'بانتظار المورد' : 'awaiting supplier' ?></div>
        </div>
    </div>
    <div class="rh-stat" style="--stat-color:#7c3aed">
        <div class="rh-stat-ico" style="background:#f3e8ff; color:#7c3aed"><i class="fa-solid fa-gears"></i></div>
        <div>
            <div class="rh-stat-val"><?= number_format($stats['approved_cc']) ?></div>
            <div class="rh-stat-lbl"><?= $rtl ? 'شهادات تشغيل' : 'Commissioning Certs' ?></div>
            <div class="rh-stat-sub"><?= number_format($stats['transferred_cc']) ?> <?= $rtl ? 'منقولة للأصول' : 'transferred' ?></div>
        </div>
    </div>
    <div class="rh-stat" style="--stat-color:#dc2626">
        <div class="rh-stat-ico" style="background:#fee2e2; color:#dc2626"><i class="fa-solid fa-stopwatch"></i></div>
        <div>
            <div class="rh-stat-val"><?= $stats['avg_lead'] ?> <span style="font-size:13px;color:#64748b"><?= $rtl ? 'يوم' : 'days' ?></span></div>
            <div class="rh-stat-lbl"><?= $rtl ? 'متوسط Lead Time' : 'Avg Lead Time' ?></div>
            <div class="rh-stat-sub"><?= $rtl ? 'استلام → تشغيل' : 'receipt → commission' ?></div>
        </div>
    </div>
</div>

<h2 class="rh-section-title">
    <i class="fa-solid fa-table-list"></i>
    <?= $rtl ? 'التقارير المتاحة' : 'Available Reports' ?>
</h2>

<?php if (empty($visible_reports)): ?>
    <div class="rh-empty">
        <i class="fa-solid fa-lock"></i>
        <h3><?= $rtl ? 'لا توجد تقارير متاحة لك' : 'No reports available for you' ?></h3>
        <p><?= $rtl ? 'تواصل مع مدير النظام لمنحك الصلاحيات اللازمة' : 'Contact your system admin to grant the required permissions' ?></p>
    </div>
<?php else: ?>
    <div class="rh-cards">
    <?php foreach ($visible_reports as $r):
        $is_disabled = !empty($r['coming_soon']) || empty($r['available']);
        $tag  = $is_disabled ? 'div' : 'a';
        $href = $is_disabled ? '' : ' href="' . e($r['href']) . '"';
    ?>
        <<?= $tag ?><?= $href ?>
            class="rh-card <?= $is_disabled ? 'disabled' : '' ?>"
            style="--accent: <?= e($r['color']) ?>; --gradient: <?= e($r['gradient']) ?>"
        >
            <div class="rh-card-head">
                <div class="rh-card-ico"><i class="fa-solid <?= e($r['icon']) ?>"></i></div>
                <div class="rh-card-title"><?= $rtl ? e($r['title_ar']) : e($r['title_en']) ?></div>
            </div>
            <div class="rh-card-body">
                <div class="rh-card-desc"><?= $rtl ? e($r['desc_ar']) : e($r['desc_en']) ?></div>
                <div class="rh-card-kpi">
                    <i class="fa-solid fa-chart-line" style="color: <?= e($r['kpi_color']) ?>"></i>
                    <span class="v" style="color: <?= e($r['kpi_color']) ?>"><?= e($r['kpi_value']) ?></span>
                    <span><?= e($r['kpi_label_ar']) ?></span>
                </div>
            </div>
            <div class="rh-card-foot">
                <?php if (!empty($r['coming_soon'])): ?>
                    <span class="soon"><i class="fa-solid fa-clock"></i> <?= $rtl ? 'قريباً' : 'Coming soon' ?></span>
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

<?php if (!empty($recent)): ?>
    <h2 class="rh-section-title" style="margin-top: 28px">
        <i class="fa-solid fa-clock-rotate-left"></i>
        <?= $rtl ? 'آخر نشاط في دورة التوريد' : 'Recent Procurement Activity' ?>
    </h2>
    <div class="rh-recent">
        <div class="rh-recent-head">
            <i class="fa-solid fa-timeline"></i>
            <?= $rtl ? 'آخر العمليات عبر المحاضر والشهادات' : 'Latest operations across minutes & certificates' ?>
        </div>
        <div class="rh-recent-list">
            <?php foreach ($recent as $r):
                $is_rm = $r['kind'] === 'rm';
                $ar = $is_rm ? $RM_STATUS_AR : $CC_STATUS_AR;
                $colors = $is_rm ? $RM_STATUS_COLOR : $CC_STATUS_COLOR;
                $st = $r['status'];
                $st_color = $colors[$st] ?? '#475569';
                $st_label = $ar[$st] ?? $st;
                $date = !empty($r['date']) ? date('Y-m-d', strtotime($r['date'])) : '—';
                $time_ago = !empty($r['date']) ? time() - strtotime($r['date']) : 0;
                if ($time_ago < 86400) $ago = $rtl ? 'اليوم' : 'today';
                elseif ($time_ago < 604800) $ago = floor($time_ago/86400) . ' ' . ($rtl ? 'يوم' : 'd');
                else $ago = floor($time_ago/604800) . ' ' . ($rtl ? 'أسبوع' : 'w');
            ?>
                <div class="rh-recent-item">
                    <span class="rh-kind-badge <?= $r['kind'] ?>">
                        <?= $is_rm ? ($rtl?'استلام':'Receipt') : ($rtl?'تشغيل':'Comm') ?>
                    </span>
                    <span class="rh-num"><?= e($r['num'] ?? '—') ?></span>
                    <div class="rh-supplier"><?= e($r['supplier'] ?: '—') ?></div>
                    <span class="rh-status" style="background:<?= $st_color ?>22; color:<?= $st_color ?>"><?= e($st_label) ?></span>
                    <span class="rh-date" title="<?= e($date) ?>"><?= $date ?> · <span style="color:#a16207"><?= $ago ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

</div></main>
</div>
</body>
</html>