<?php
/**
 * reports/custody/index.php — مركز تقارير العهدة (Custody Reports Hub)
 * ──────────────────────────────────────────────────────────────────
 *   • صفحة تجميعية لكل تقارير العهدة (نفس النمط البصري لمركز NUPCو وتقارير الأصول)
 *   • لون مميز: أخضر زمرّدي (Emerald) — يختلف عن تدرج أصول (Indigo)
 *   • تظهر فقط التقارير اللي المستخدم عنده صلاحية عليها
 *   • 4 KPIs سريعة + 6 بطاقات تقارير
 *   • نشاط حديث: آخر عمليات نقل العهدة
 *
 *   المعيار: التقارير = استعراض فقط. الإجراء (نقل/تعديل) في صفحات منفصلة
 *   مثل /assets/custody_transfer.php و /assets/form.php?id=X
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.custody');

$can_export  = can('reports.custody', 'export');
$excel_mode  = report_excel_mode_active('reports.custody');
$print_mode  = report_print_mode_active('reports.custody');
$print_charts = report_print_charts_mode_active('reports.custody');

$rtl = is_rtl();
$page_title = $rtl ? 'تقارير العهدة' : 'Custody Reports';
$page_icon  = 'fa-handshake';
$active_nav = 'reports.custody';
// الـ hub هو نقطة الدخول لمجموعة التقارير الفرعية
$breadcrumb = [
    ['name' => $rtl ? 'تقارير العهدة' : 'Custody Reports'],
];

// ═══ الإحصائيات السريعة (KPIs) ═══
$stats = [];
$stats['total_under_custody'] = (int)$pdo->query("
    SELECT COUNT(*) FROM assets
    WHERE status='active' AND custodian_user_id IS NOT NULL
")->fetchColumn();

$stats['active_custodians'] = (int)$pdo->query("
    SELECT COUNT(DISTINCT custodian_user_id) FROM assets
    WHERE status='active' AND custodian_user_id IS NOT NULL
")->fetchColumn();

// عدد الأقسام اللي فعلاً عندها عهدة (يستخدم user.department_id كـ fallback)
$stats['depts_with_custody'] = (int)$pdo->query("
    SELECT COUNT(DISTINCT COALESCE(a.custodian_dept_id, u.department_id))
    FROM assets a
    LEFT JOIN users u ON u.id = a.custodian_user_id
    WHERE a.status='active' AND a.custodian_user_id IS NOT NULL
      AND COALESCE(a.custodian_dept_id, u.department_id) IS NOT NULL
")->fetchColumn();

$stats['no_custodian'] = (int)$pdo->query("
    SELECT COUNT(*) FROM assets
    WHERE status='active' AND custodian_user_id IS NULL
")->fetchColumn();

$stats['crit_A'] = (int)$pdo->query("
    SELECT COUNT(*) FROM assets
    WHERE status='active' AND criticality_class='A' AND custodian_user_id IS NOT NULL
")->fetchColumn();

$stats['total_custody_logs'] = (int)$pdo->query("
    SELECT COUNT(*) FROM asset_custody_log
")->fetchColumn();

$stats['warranty_30d'] = (int)$pdo->query("
    SELECT COUNT(*) FROM assets
    WHERE status='active'
      AND custodian_user_id IS NOT NULL
      AND warranty_expiry IS NOT NULL
      AND warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
")->fetchColumn();

$stats['warranty_expired'] = (int)$pdo->query("
    SELECT COUNT(*) FROM assets
    WHERE status='active'
      AND custodian_user_id IS NOT NULL
      AND warranty_expiry IS NOT NULL
      AND warranty_expiry < CURDATE()
")->fetchColumn();

// ═══ قائمة التقارير (Feature Cards) ═══
$reports = [
    [
        'id'          => 'overview',
        'icon'        => 'fa-handshake',
        'color'       => '#059669',
        'gradient'    => 'linear-gradient(135deg, #064e3b, #059669, #10b981)',
        'title_ar'    => 'ملخص العهدة',
        'title_en'    => 'Custody Overview',
        'desc_ar'     => 'إحصائيات شاملة + توزيع حسب القسم/المستلم + سجل آخر عمليات النقل + فلاتر متعددة + تصدير',
        'desc_en'     => 'Full stats + by department/recipient breakdown + recent transfers + filters + export',
        'permission'  => 'reports.custody.overview',
        'kpi_label_ar' => 'تحت العهدة',
        'kpi_value'   => number_format($stats['total_under_custody']),
        'kpi_color'   => '#059669',
        'href'        => 'overview.php',
        'available'   => true,
    ],
    [
        'id'          => 'by_department',
        'icon'        => 'fa-building',
        'color'       => '#0d9488',
        'gradient'    => 'linear-gradient(135deg, #134e4a, #0d9488, #14b8a6)',
        'title_ar'    => 'عهد قسمي',
        'title_en'    => 'By Department',
        'desc_ar'     => 'توزيع الأصول على الأقسام + مقارنة بين الإدارات + نصيب كل قسم من الإجمالي',
        'desc_en'     => 'Asset distribution per department + inter-dept comparison',
        'permission'  => 'reports.custody.by_department',
        'kpi_label_ar' => 'أقسام لها عهدة',
        'kpi_value'   => number_format($stats['depts_with_custody']),
        'kpi_color'   => '#0d9488',
        'href'        => 'by_department.php',
        'available'   => true,
    ],
    [
        'id'          => 'no_custodian',
        'icon'        => 'fa-triangle-exclamation',
        'color'       => '#dc2626',
        'gradient'    => 'linear-gradient(135deg, #7f1d1d, #dc2626, #f87171)',
        'title_ar'    => 'أصول بدون مستلم',
        'title_en'    => 'No Custodian',
        'desc_ar'     => 'الأصول النشطة اللي ما اتسجل لها مستلم بعد — تحتاج تعيين من "نقل العهد"',
        'desc_en'     => 'Active assets with no custodian assigned — needs assignment from "Custody Transfer"',
        'permission'  => 'reports.custody.no_custodian',
        'kpi_label_ar' => 'أصول بدون مستلم',
        'kpi_value'   => number_format($stats['no_custodian']),
        'kpi_color'   => '#dc2626',
        'href'        => '../../assets/index.php?status=active&no_custodian=1',
        'available'   => true,
        'external'    => true,
    ],
    [
        'id'          => 'by_employee',
        'icon'        => 'fa-user-tie',
        'color'       => '#7c3aed',
        'gradient'    => 'linear-gradient(135deg, #4c1d95, #7c3aed, #a78bfa)',
        'title_ar'    => 'عهد الموظفين',
        'title_en'    => 'By Employee',
        'desc_ar'     => 'كل موظف + الأصول اللي تحت عهدته + قيمة إجمالية + تنبيهات (ضمان/موقع/حرج)',
        'desc_en'     => 'Each employee + assets under their custody + total value + alerts',
        'permission'  => 'reports.custody.by_employee',
        'kpi_label_ar' => 'مستلمين نشطين',
        'kpi_value'   => number_format($stats['active_custodians']),
        'kpi_color'   => '#7c3aed',
        'href'        => 'by_employee.php',
        'available'   => true,
    ],
    [
        'id'          => 'by_criticality',
        'icon'        => 'fa-shield-halved',
        'color'       => '#dc2626',
        'gradient'    => 'linear-gradient(135deg, #7f1d1d, #dc2626, #f87171)',
        'title_ar'    => 'حسب الحساسية (A/B/C)',
        'title_en'    => 'By Criticality (A/B/C)',
        'desc_ar'     => 'الأصول الحرجة تحت العهدة + المسؤول + موقعها الحالي + ربط بفريق الصيانة',
        'desc_en'     => 'Critical assets under custody + assignee + location + maintenance team',
        'permission'  => 'reports.custody.by_criticality',
        'kpi_label_ar' => 'أصول حرجة (A)',
        'kpi_value'   => number_format($stats['crit_A']),
        'kpi_color'   => '#dc2626',
        'href'        => 'by_criticality.php',
        'available'   => true,
    ],
    [
        'id'          => 'warranty_alerts',
        'icon'        => 'fa-bell',
        'color'       => '#d97706',
        'gradient'    => 'linear-gradient(135deg, #78350f, #d97706, #fbbf24)',
        'title_ar'    => 'تنبيهات الضمان',
        'title_en'    => 'Warranty Alerts',
        'desc_ar'     => 'الأصول تحت العهدة اللي ضمانها قارب ينتهي (30/60/90 يوم) أو انتهى',
        'desc_en'     => 'Assets under custody with warranty expiring (30/60/90 days) or expired',
        'permission'  => 'reports.custody.warranty_alerts',
        'kpi_label_ar' => 'قارب ينتهي (30 يوم)',
        'kpi_value'   => number_format($stats['warranty_30d']),
        'kpi_color'   => '#d97706',
        'href'        => 'warranty_alerts.php',
        'available'   => true,
    ],
    [
        'id'          => 'custody_log',
        'icon'        => 'fa-clock-rotate-left',
        'color'       => '#475569',
        'gradient'    => 'linear-gradient(135deg, #1e293b, #475569, #94a3b8)',
        'title_ar'    => 'سجل نقل العهدة',
        'title_en'    => 'Custody Transfer Log',
        'desc_ar'     => 'تاريخ كامل لكل عمليات نقل العهدة (من → إلى) + السبب + رقم القرار + المُنفّذ',
        'desc_en'     => 'Full history of custody transfers (from → to) + reason + decision ref + executor',
        'permission'  => 'reports.custody.custody_log',
        'kpi_label_ar' => 'عمليات نقل',
        'kpi_value'   => number_format($stats['total_custody_logs']),
        'kpi_color'   => '#475569',
        'href'        => 'custody_log.php',
        'available'   => true,
    ],
];

// ═══ فلترة التقارير حسب الصلاحيات (Strategy B: إخفاء) ═══
$visible_reports = array_filter($reports, function($r) {
    return can($r['permission'], 'view');
});

// ═══ آخر عمليات نقل العهدة (Recent Activity) ═══
$recent_transfers = $pdo->query("
    SELECT acl.id, acl.custody_date, acl.reason, acl.created_at,
           a.tag_number, a.description, a.criticality_class,
           u_to.full_name AS to_user, d_to.name AS to_dept,
           u_from.full_name AS from_user, d_from.name AS from_dept,
           cb.full_name AS created_by_name
    FROM asset_custody_log acl
    INNER JOIN assets a ON a.id = acl.asset_id
    LEFT JOIN users u_to ON u_to.id = acl.to_user_id
    LEFT JOIN departments d_to ON d_to.id = acl.to_dept_id
    LEFT JOIN users u_from ON u_from.id = acl.from_user_id
    LEFT JOIN departments d_from ON d_from.id = acl.from_dept_id
    LEFT JOIN users cb ON cb.id = acl.created_by
    WHERE a.status NOT IN ('disposed','returned_to_supplier')
    ORDER BY acl.id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ═══ دوال مساعدة ═══
// truncate() موجودة في config.php — لا نعيد تعريفها
function time_ago($datetime, $rtl=true) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)        return $rtl ? 'الآن' : 'just now';
    if ($diff < 3600)      return floor($diff/60) . ' ' . ($rtl?'دقيقة':'min') . ' ' . ($rtl?'مضت':'ago');
    if ($diff < 86400)     return floor($diff/3600) . ' ' . ($rtl?'ساعة':'hr') . ' ' . ($rtl?'مضت':'ago');
    if ($diff < 604800)    return floor($diff/86400) . ' ' . ($rtl?'يوم':'day') . ' ' . ($rtl?'مضى':'ago');
    return date('Y-m-d', strtotime($datetime));
}

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
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root {
  --accent: #059669;
  --accent-light: #10b981;
  --accent-dark: #064e3b;
  --accent-bg: #ecfdf5;
}
/* ═══ Container ═══ */
.ch-wrap { max-width: 1280px; margin: 0 auto; padding: 18px; }

/* ═══ HERO — تدرج أخضر زمرّدي مميز ═══ */
.ch-hero {
  position: relative;
  background: linear-gradient(135deg, #064e3b 0%, #059669 50%, #10b981 100%);
  color: #fff;
  border-radius: 22px;
  padding: 28px 32px;
  margin-bottom: 22px;
  box-shadow: 0 12px 32px rgba(5, 150, 105, 0.30);
  overflow: hidden;
}
.ch-hero::before {
  content: '';
  position: absolute; top: -60px; left: -60px;
  width: 240px; height: 240px;
  background: radial-gradient(circle, rgba(255,255,255,.10) 0%, transparent 70%);
  border-radius: 50%;
}
.ch-hero::after {
  content: '';
  position: absolute; bottom: -40px; right: 30%;
  width: 160px; height: 160px;
  background: radial-gradient(circle, rgba(255,255,255,.07) 0%, transparent 70%);
  border-radius: 50%;
}
.ch-hero-content { display: flex; align-items: center; gap: 26px; position: relative; z-index: 1; }
.ch-hero-ico {
  width: 90px; height: 90px;
  background: rgba(255,255,255,.18);
  border: 2px solid rgba(255,255,255,.30);
  border-radius: 20px;
  display: flex; align-items: center; justify-content: center;
  font-size: 40px; color: #fff;
  flex-shrink: 0;
  box-shadow: 0 8px 20px rgba(0,0,0,.18);
  animation: chPulse 3s ease-in-out infinite;
}
@keyframes chPulse {
  0%, 100% { transform: scale(1); box-shadow: 0 8px 20px rgba(0,0,0,.18); }
  50%      { transform: scale(1.05); box-shadow: 0 12px 28px rgba(0,0,0,.25); }
}
.ch-hero-text { flex: 1; min-width: 0; }
.ch-hero h1 { margin: 0 0 4px; font-size: 28px; font-weight: 800; letter-spacing: -.5px; }
.ch-hero .ch-subtitle { font-size: 14px; opacity: .88; font-weight: 500; margin-top: 2px; }
.ch-hero .ch-counter {
  display: inline-flex; align-items: center; gap: 6px;
  background: rgba(255,255,255,.20);
  border: 1px solid rgba(255,255,255,.35);
  padding: 5px 12px; border-radius: 20px;
  font-size: 12.5px; font-weight: 700; margin-top: 8px;
  backdrop-filter: blur(4px);
}

/* ═══ KPIs ═══ */
.ch-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 22px; }
.ch-stat {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  padding: 16px 18px;
  display: flex; align-items: center; gap: 14px;
  transition: all 0.2s ease;
}
.ch-stat:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,.06); }
.ch-stat-ico {
  width: 48px; height: 48px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex-shrink: 0;
}
.ch-stat-val { font-size: 24px; font-weight: 800; color: #0f172a; line-height: 1; }
.ch-stat-lbl { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 600; }

/* ═══ Section Title ═══ */
.ch-section-title {
  font-size: 16px; font-weight: 800; color: #0f172a;
  margin: 24px 0 14px;
  display: flex; align-items: center; gap: 8px;
}
.ch-section-title i { color: var(--accent); font-size: 14px; }

/* ═══ Feature Cards Grid ═══ */
.ch-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
.ch-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 16px;
  overflow: hidden;
  transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  text-decoration: none; color: inherit;
  display: flex; flex-direction: column;
  position: relative;
}
.ch-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(5, 150, 105, 0.15); border-color: var(--accent-light); }
.ch-card.coming-soon { opacity: .65; pointer-events: none; }
.ch-card-head {
  background: var(--card-gradient, linear-gradient(135deg, #059669, #10b981));
  color: #fff;
  padding: 14px 18px;
  display: flex; align-items: center; gap: 12px;
}
.ch-card-ico {
  width: 38px; height: 38px;
  background: rgba(255,255,255,.20);
  border: 1.5px solid rgba(255,255,255,.30);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}
.ch-card-title { font-size: 15px; font-weight: 800; flex: 1; min-width: 0; }
.ch-card-body { padding: 14px 18px; flex: 1; }
.ch-card-desc { font-size: 12.5px; color: #475569; line-height: 1.6; margin: 0 0 12px; }
.ch-card-kpi {
  display: flex; align-items: center; gap: 10px;
  background: var(--kpi-bg, #ecfdf5);
  padding: 8px 12px; border-radius: 10px;
  font-size: 12px;
}
.ch-card-kpi-lbl { color: var(--kpi-color, #059669); font-weight: 700; }
.ch-card-kpi-val { font-size: 18px; font-weight: 800; color: var(--kpi-color, #059669); margin-inline-start: auto; }
.ch-card-foot {
  padding: 10px 18px;
  border-top: 1px solid #f1f5f9;
  display: flex; align-items: center; gap: 6px;
  font-size: 12.5px; font-weight: 700;
  background: #fafbfc;
}
.ch-card-foot .open { color: var(--accent); margin-inline-start: auto; display: flex; align-items: center; gap: 4px; }
.ch-card-foot .soon {
  background: #fef3c7; color: #92400e; padding: 3px 10px; border-radius: 10px; font-size: 11px;
  margin-inline-start: auto;
}
.ch-card-foot i { color: var(--accent); }

/* ═══ Recent Activity ═══ */
.ch-recent {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 16px;
  padding: 18px 20px;
  margin-bottom: 24px;
}
.ch-recent-head {
  display: flex; align-items: center; gap: 10px;
  margin-bottom: 12px; padding-bottom: 10px;
  border-bottom: 1.5px solid #f1f5f9;
}
.ch-recent-head h3 { margin: 0; font-size: 14.5px; font-weight: 800; color: #0f172a; }
.ch-recent-head i { color: var(--accent); font-size: 14px; }
.ch-recent-list { display: flex; flex-direction: column; gap: 8px; }
.ch-recent-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 12px;
  background: #fafbfc;
  border: 1px solid #f1f5f9;
  border-radius: 10px;
  text-decoration: none; color: inherit;
  transition: all 0.2s ease;
}
.ch-recent-item:hover { background: #ecfdf5; border-color: var(--accent-light); transform: translateX(-2px); }
.ch-recent-icon {
  width: 36px; height: 36px;
  background: var(--accent-bg);
  color: var(--accent);
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px; flex-shrink: 0;
}
.ch-recent-content { flex: 1; min-width: 0; }
.ch-recent-tag { font-family: monospace; font-size: 12px; color: var(--accent); font-weight: 700; }
.ch-recent-desc { font-size: 12.5px; color: #475569; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ch-recent-dept { font-size: 11.5px; color: #64748b; }
.ch-recent-time { font-size: 11.5px; color: #94a3b8; white-space: nowrap; }
.ch-crit { display: inline-flex; padding: 2px 8px; border-radius: 5px; font-size: 11px; font-weight: 800; letter-spacing: .5px; }
.ch-crit.A { background: #fef2f2; color: #dc2626; }
.ch-crit.B { background: #fef3c7; color: #d97706; }
.ch-crit.C { background: #ecfeff; color: #0891b2; }

/* ═══ Empty State ═══ */
.ch-empty {
  text-align: center; padding: 60px 20px;
  background: #fff; border: 1.5px dashed #e2e8f0;
  border-radius: 16px;
}
.ch-empty i { font-size: 48px; color: #cbd5e1; margin-bottom: 12px; display: block; }
.ch-empty h3 { font-size: 18px; color: #475569; margin: 0 0 6px; }
.ch-empty p { font-size: 13px; color: #94a3b8; margin: 0; }

/* ═══ Responsive ═══ */
@media (max-width: 1100px) { .ch-stats { grid-template-columns: repeat(2, 1fr); } .ch-cards { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 720px)  { .ch-stats { grid-template-columns: 1fr; } .ch-cards { grid-template-columns: 1fr; } .ch-hero { padding: 22px 20px; } .ch-hero-ico { width: 70px; height: 70px; font-size: 32px; } }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>

<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="ch-wrap">

  <!-- ═══ HERO ═══ -->
  <section class="ch-hero">
    <div class="ch-hero-content">
      <div class="ch-hero-ico"><i class="fa-solid fa-handshake"></i></div>
      <div class="ch-hero-text">
        <h1><?= $rtl ? 'تقارير العهدة' : 'Custody Reports' ?></h1>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.custody') ?>
            </div>
        <div class="ch-subtitle"><?= $rtl ? 'مركز موحَّد لكل تقارير العهدة — مع صلاحيات دقيقة وفلاتر ذكية' : 'Unified hub for all custody reports — fine-grained permissions & smart filters' ?></div>
        <div class="ch-counter">
          <i class="fa-solid fa-chart-bar"></i>
          <?= $rtl ? 'يتوفر' : 'Available' ?>: <strong><?= count($visible_reports) ?></strong> <?= $rtl ? 'تقرير' : 'reports' ?>
          <?php if (count($visible_reports) < count($reports)): ?>
            <span style="opacity:.7;margin-inline-start:8px">/ <?= count($reports) ?> <?= $rtl?'إجمالي':'total' ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- ═══ 4 KPIs سريعة ═══ -->
  <div class="ch-stats">
    <div class="ch-stat">
      <div class="ch-stat-ico" style="background:#d1fae5;color:#059669"><i class="fa-solid fa-handshake"></i></div>
      <div>
        <div class="ch-stat-val"><?= number_format($stats['total_under_custody']) ?></div>
        <div class="ch-stat-lbl"><?= $rtl?'تحت العهدة':'Under Custody' ?></div>
      </div>
    </div>
    <div class="ch-stat">
      <div class="ch-stat-ico" style="background:#ccfbf1;color:#0d9488"><i class="fa-solid fa-user-tie"></i></div>
      <div>
        <div class="ch-stat-val"><?= number_format($stats['active_custodians']) ?></div>
        <div class="ch-stat-lbl"><?= $rtl?'مستلمين نشطين':'Active Custodians' ?></div>
      </div>
    </div>
    <div class="ch-stat">
      <div class="ch-stat-ico" style="background:#cffafe;color:#0891b2"><i class="fa-solid fa-building"></i></div>
      <div>
        <div class="ch-stat-val"><?= number_format($stats['depts_with_custody']) ?></div>
        <div class="ch-stat-lbl"><?= $rtl?'أقسام لها عهدة':'Departments w/ Custody' ?></div>
      </div>
    </div>
    <div class="ch-stat">
      <div class="ch-stat-ico" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div>
        <div class="ch-stat-val"><?= number_format($stats['no_custodian']) ?></div>
        <div class="ch-stat-lbl"><?= $rtl?'بدون مستلم (تحتاج تنبيه)':'No Custodian' ?></div>
      </div>
    </div>
  </div>

  <!-- ═══ Feature Cards ═══ -->
  <h2 class="ch-section-title">
    <i class="fa-solid fa-grid-2"></i>
    <?= $rtl ? 'التقارير المتاحة' : 'Available Reports' ?>
  </h2>

  <?php if (empty($visible_reports)): ?>
    <div class="ch-empty">
      <i class="fa-solid fa-lock"></i>
      <h3><?= $rtl ? 'لا توجد تقارير متاحة لك' : 'No reports available for you' ?></h3>
      <p><?= $rtl ? 'تواصل مع مدير النظام لمنحك الصلاحيات اللازمة' : 'Contact your system admin to grant you the required permissions' ?></p>
    </div>
  <?php else: ?>
  <div class="ch-cards">
    <?php foreach ($visible_reports as $r):
        $is_disabled = !empty($r['coming_soon']) || empty($r['available']);
        $tag = $is_disabled ? 'div' : 'a';
        $href = $is_disabled ? '' : ' href="' . e($r['href']) . '"';
        $kpi_bg = '#ecfdf5';
    ?>
    <<?= $tag ?><?= $href ?> class="ch-card <?= $is_disabled?'coming-soon':'' ?>" style="--card-gradient:<?= e($r['gradient']) ?>;--kpi-color:<?= e($r['color']) ?>;--kpi-bg:<?= e($kpi_bg) ?>">
      <div class="ch-card-head">
        <div class="ch-card-ico"><i class="fa-solid <?= e($r['icon']) ?>"></i></div>
        <div class="ch-card-title"><?= $rtl ? e($r['title_ar']) : e($r['title_en']) ?></div>
      </div>
      <div class="ch-card-body">
        <p class="ch-card-desc"><?= $rtl ? e($r['desc_ar']) : e($r['desc_en']) ?></p>
        <div class="ch-card-kpi">
          <span class="ch-card-kpi-lbl"><?= e($r['kpi_label_ar']) ?></span>
          <span class="ch-card-kpi-val"><?= e($r['kpi_value']) ?></span>
        </div>
      </div>
      <div class="ch-card-foot">
        <?php if (!empty($r['coming_soon'])): ?>
          <i class="fa-solid fa-clock"></i>
          <span style="color:#92400e"><?= $rtl?'قريباً':'Coming soon' ?></span>
          <span class="soon"><i class="fa-solid fa-hourglass-half"></i></span>
        <?php else: ?>
          <i class="fa-solid fa-file-invoice"></i>
          <span><?= $rtl?'تقرير كامل':'Full report' ?></span>
          <span class="open"><?= $rtl?'فتح':'Open' ?> <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i></span>
        <?php endif; ?>
      </div>
    </<?= $tag ?>>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ═══ آخر عمليات نقل العهدة (Recent Activity) ═══ -->
  <?php if (!empty($recent_transfers)): ?>
  <h2 class="ch-section-title">
    <i class="fa-solid fa-clock-rotate-left"></i>
    <?= $rtl ? 'آخر عمليات نقل العهدة' : 'Recent Custody Transfers' ?>
  </h2>
  <div class="ch-recent">
    <div class="ch-recent-list">
      <?php foreach ($recent_transfers as $r):
        $crit = $r['criticality_class'] ?: 'C';
        $ago = time_ago($r['created_at'], $rtl);
      ?>
      <a href="<?= BASE_URL ?>/assets/view.php?id=<?= (int)$r['id'] ?>" class="ch-recent-item">
        <div class="ch-recent-icon">
          <i class="fa-solid fa-arrow-right-arrow-left"></i>
        </div>
        <div class="ch-recent-content">
          <div>
            <span class="ch-recent-tag"><?= e($r['tag_number'] ?: '—') ?></span>
            <?php if ($r['criticality_class']): ?>
              <span class="ch-crit <?= e($crit) ?>"><?= e($crit) ?></span>
            <?php endif; ?>
          </div>
          <div class="ch-recent-desc"><?= e(truncate($r['description'] ?? '—', 60)) ?></div>
          <div class="ch-recent-dept">
            <i class="fa-solid fa-user" style="color:#94a3b8"></i>
            <?= e($r['to_user'] ?: $r['to_dept'] ?: '—') ?>
            <?php if ($r['reason']): ?>
              <span style="color:#94a3b8;margin-inline-start:6px">· <?= e(truncate($r['reason'], 40)) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <div class="ch-recent-time" title="<?= e($r['created_at']) ?>"><?= e($ago) ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div></main>
</div><!-- /.main-area -->

</body>
</html>
