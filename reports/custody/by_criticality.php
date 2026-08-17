<?php
/**
 * reports/custody/by_criticality.php — العهدة حسب الحساسية (A/B/C)
 * ──────────────────────────────────────────────────────────────────
 *   • الأصول الحرجة تحت العهدة (A/B/C) + المسؤول + الموقع
 *   • استعراضي بحت — مع تنبيهات بصرية للأصول الحرجة (A)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.custody.by_criticality');

$can_export  = can('reports.custody.by_criticality', 'export');
$excel_mode  = report_excel_mode_active('reports.custody.by_criticality');
$print_mode  = report_print_mode_active('reports.custody.by_criticality');
$print_charts = report_print_charts_mode_active('reports.custody.by_criticality');

$rtl = is_rtl();
$active_nav = 'reports.custody';
$page_title = $rtl ? 'العهد حسب الحساسية' : 'Custody by Criticality';

// ═══ فلاتر ═══
$f_crit   = $_GET['crit'] ?? '';
$f_search = trim($_GET['q'] ?? '');

// فلترة حسب قسم المستخدم
$scope = data_scope('custody', 'a');

// ═══ ملخص حسب الحساسية ═══
$crit_stats_stmt = $pdo->prepare("
    SELECT
        a.criticality_class,
        COUNT(*) AS cnt,
        COALESCE(SUM(a.cost), 0) AS total_cost
    FROM assets a
    WHERE a.status='active' AND a.custodian_user_id IS NOT NULL AND " . $scope['where'] . "
    GROUP BY a.criticality_class
    ORDER BY a.criticality_class
");
$crit_stats_stmt->execute($scope['params']);
$crit_stats = $crit_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

$crit_map = [];
$total_under_custody = 0;
$total_cost = 0;
foreach ($crit_stats as $c) {
    $key = $c['criticality_class'] ?: 'C';
    $crit_map[$key] = $c;
    $total_under_custody += (int)$c['cnt'];
    $total_cost += (float)$c['total_cost'];
}
$grand_count = $total_under_custody;
$grand_cost = $total_cost;

// ═══ التفاصيل لمستوى حساسية محدد ═══
$selected_crit = null;
$crit_assets = [];

if ($f_crit && in_array($f_crit, ['A','B','C'], true)) {
    $selected_crit = $f_crit;
    $where = "WHERE eff.status='active' AND eff.criticality_class = ? AND eff.custodian_user_id IS NOT NULL";
    $params = [$f_crit];
    if ($f_search !== '') {
        $where .= " AND (eff.tag_number LIKE ? OR eff.description LIKE ? OR eff.manufacturer_name LIKE ? OR eff.model_number LIKE ? OR u.full_name LIKE ?)";
        $like = '%' . $f_search . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $stmt = $pdo->prepare("
        SELECT eff.id, eff.tag_number, eff.description, eff.description_ar, eff.criticality_class,
               eff.asset_type, eff.manufacturer_name, eff.model_number, eff.cost, eff.custody_date,
               eff.warranty_expiry, eff.loc_building, eff.loc_floor, eff.loc_room, eff.serial_number,
               u.full_name AS custodian_name, u.username AS custodian_username,
               d.name AS dept_name
        FROM (
            SELECT a.id, a.tag_number, a.description, a.description_ar, a.criticality_class,
                   a.asset_type, a.manufacturer_name, a.model_number, a.cost, a.custody_date,
                   a.warranty_expiry, a.loc_building, a.loc_floor, a.loc_room, a.serial_number,
                   a.status, a.custodian_user_id, a.criticality_class AS _crit_filter,
                   COALESCE(a.custodian_dept_id, _u.department_id) AS effective_dept_id
            FROM assets a
            LEFT JOIN users _u ON _u.id = a.custodian_user_id
        ) eff
        LEFT JOIN users u ON u.id = eff.custodian_user_id
        LEFT JOIN departments d ON d.id = eff.effective_dept_id
        $where
        ORDER BY eff.tag_number ASC
        LIMIT 500
    ");
    $stmt->execute($params);
    $crit_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$crit_labels = [
    'A' => ['name' => $rtl?'حرج (A)':'Critical (A)', 'desc' => $rtl?'أجهزة طبية حيوية — أعطالها تؤثر على سلامة الأرواح':'Critical medical devices — failures impact patient safety', 'gradient' => 'linear-gradient(135deg, #7f1d1d, #dc2626, #f87171)', 'color' => '#dc2626', 'bg' => '#fef2f2'],
    'B' => ['name' => $rtl?'عالي (B)':'High (B)',     'desc' => $rtl?'أجهزة مهمة — أعطالها تؤثر على سير العمل بشكل كبير':'Important devices — failures significantly impact operations', 'gradient' => 'linear-gradient(135deg, #78350f, #d97706, #fbbf24)', 'color' => '#d97706', 'bg' => '#fef3c7'],
    'C' => ['name' => $rtl?'منخفض (C)':'Low (C)',     'desc' => $rtl?'أجهزة عادية — أعطالها لها تأثير محدود':'Standard devices — failures have limited impact', 'gradient' => 'linear-gradient(135deg, #164e63, #0891b2, #67e8f9)', 'color' => '#0891b2', 'bg' => '#ecfeff'],
];

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
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.bc-wrap { max-width: 1400px; margin: 0 auto; padding: 14px; }
.bc-back { font-size: 12.5px; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 10px; font-weight: 600; }
.bc-back:hover { color: #dc2626; }
.bc-hero {
  background: linear-gradient(135deg, #7f1d1d 0%, #dc2626 50%, #f87171 100%);
  color: #fff;
  border-radius: 18px;
  padding: 24px 28px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 18px;
  box-shadow: 0 10px 30px rgba(220,38,38,.25);
  position: relative; overflow: hidden;
}
.bc-hero::before { content: ''; position: absolute; top: -40px; right: -40px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(255,255,255,.10), transparent 70%); border-radius: 50%; }
.bc-hero-ico { width: 60px; height: 60px; border-radius: 14px; background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.30); display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; }
.bc-hero h2 { margin: 0; font-size: 20px; font-weight: 800; }
.bc-hero p { margin: 4px 0 0; font-size: 13px; opacity: .88; line-height: 1.6; }

.bc-grand { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
.bc-grand-stat { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
.bc-grand-stat-ico { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.bc-grand-stat-num { font-size: 22px; font-weight: 800; color: #0f172a; line-height: 1; }
.bc-grand-stat-lbl { font-size: 11.5px; color: #64748b; margin-top: 3px; font-weight: 600; }

.bc-crit-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
.bc-crit-card {
  background: var(--card-bg, #fff);
  border: 1.5px solid #e2e8f0;
  border-radius: 12px;
  padding: 18px 20px;
  text-decoration: none; color: inherit;
  transition: all 0.2s ease;
  position: relative;
  overflow: hidden;
  display: flex; flex-direction: column; gap: 10px;
  cursor: pointer;
}
.bc-crit-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(0,0,0,.10); border-color: var(--card-color, #dc2626); }
.bc-crit-card.selected { box-shadow: 0 0 0 3px var(--card-color, #dc2626); }
.bc-crit-card .bc-crit-head { display: flex; align-items: center; gap: 12px; }
.bc-crit-card .bc-crit-letter { width: 48px; height: 48px; border-radius: 12px; background: var(--card-color, #dc2626); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 800; flex-shrink: 0; }
.bc-crit-card .bc-crit-name { font-size: 16px; font-weight: 800; color: #0f172a; }
.bc-crit-card .bc-crit-desc { font-size: 12px; color: #64748b; line-height: 1.5; }
.bc-crit-card .bc-crit-counts { display: flex; align-items: center; gap: 16px; padding-top: 10px; border-top: 1px dashed #e2e8f0; }
.bc-crit-card .bc-crit-count-val { font-size: 24px; font-weight: 800; color: var(--card-color, #dc2626); }
.bc-crit-card .bc-crit-count-lbl { font-size: 11px; color: #64748b; }
.bc-crit-card .bc-crit-cost { font-size: 13px; color: #64748b; font-weight: 600; margin-top: 4px; }

.bc-detail { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 18px 20px; margin-bottom: 16px; }
.bc-detail-h { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }

.bc-fltbar { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px; display: grid; grid-template-columns: 2fr auto; gap: 8px; align-items: end; }
.bc-fltbar label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px; }
.bc-fltbar input { padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 7px; font-size: 13px; background: #fff; width: 100%; font-family: inherit; }
.bc-fltbar button { padding: 8px 16px; border-radius: 7px; border: none; background: #dc2626; color: #fff; font-weight: 600; cursor: pointer; font-size: 13px; }
.bc-fltbar a { background: #f1f5f9; color: #475569; padding: 8px 12px; border-radius: 7px; font-weight: 600; font-size: 13px; text-decoration: none; }

.bc-tbl { width: 100%; border-collapse: collapse; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; font-size: 13px; }
.bc-tbl th { background: #f8fafc; padding: 9px 12px; font-weight: 700; color: #475569; font-size: 11.5px; text-align: right; border-bottom: 1.5px solid #e2e8f0; }
.bc-tbl td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; }
.bc-tbl tr:hover { background: #fafbfc; }
.bc-tbl .tag { font-family: monospace; font-size: 12px; color: #dc2626; background: #fee2e2; padding: 2px 7px; border-radius: 4px; display: inline-block; font-weight: 700; }
.bc-crit-pill { display: inline-flex; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 800; letter-spacing: .5px; }
.bc-crit-pill.A { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.bc-crit-pill.B { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
.bc-crit-pill.C { background: #ecfeff; color: #0891b2; border: 1px solid #a5f3fc; }

.bc-empty { text-align: center; padding: 60px 16px; color: #94a3b8; background: #fff; border-radius: 12px; border: 1.5px solid #e2e8f0; }
.bc-empty i { font-size: 48px; display: block; margin-bottom: 12px; color: #cbd5e1; }

@media (max-width: 1100px) { .bc-grand { grid-template-columns: 1fr; } .bc-crit-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="bc-wrap">

  <a href="<?= BASE_URL ?>/reports/custody/index.php" class="bc-back">
    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i>
    <?= $rtl?'العودة إلى مركز تقارير العهدة':'Back to Custody Reports Hub' ?>
  </a>

  <div class="bc-hero">
    <div class="bc-hero-ico"><i class="fa-solid fa-shield-halved"></i></div>
    <div style="flex:1">
      <h2><?= $rtl?'العهد حسب الحساسية (A/B/C)':'Custody by Criticality (A/B/C)' ?></h2>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.custody.by_criticality') ?>
            </div>
      <p><?= $rtl?'تصنيف الأصول تحت العهدة حسب مستوى الحرجة — اختر مستوى لعرض تفاصيله. الأصول الحرجة (A) تستحق أولوية في الصيانة والمتابعة.':'Classification of assets under custody by criticality level — click a level for details. Critical (A) assets deserve top priority for maintenance and monitoring.' ?></p>
    </div>
  </div>

  <div class="bc-grand">
    <div class="bc-grand-stat">
      <div class="bc-grand-stat-ico" style="background:#d1fae5;color:#059669"><i class="fa-solid fa-handshake"></i></div>
      <div><div class="bc-grand-stat-num"><?= number_format($grand_count) ?></div><div class="bc-grand-stat-lbl"><?= $rtl?'إجمالي تحت العهدة':'Total Under Custody' ?></div></div>
    </div>
    <div class="bc-grand-stat">
      <div class="bc-grand-stat-ico" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-coins"></i></div>
      <div><div class="bc-grand-stat-num"><?= number_format($grand_cost, 0) ?></div><div class="bc-grand-stat-lbl"><?= $rtl?'قيمة إجمالية (ر.س)':'Total Value (SAR)' ?></div></div>
    </div>
  </div>

  <h3 class="bc-detail-h" style="margin-top:20px"><i class="fa-solid fa-shield-halved"></i> <?= $rtl?'الملخص حسب الحساسية':'Summary by Criticality' ?></h3>

  <div class="bc-crit-grid">
    <?php foreach ($crit_labels as $key => $info):
      $cnt = (int)($crit_map[$key]['cnt'] ?? 0);
      $cost = (float)($crit_map[$key]['total_cost'] ?? 0);
      $pct = $grand_count > 0 ? round(($cnt / $grand_count) * 100, 1) : 0;
      $is_selected = ($selected_crit === $key);
    ?>
    <a href="?crit=<?= e($key) ?>" class="bc-crit-card <?= $is_selected?'selected':'' ?>" style="--card-color: <?= e($info['color']) ?>; --card-bg: <?= e($info['bg']) ?>">
      <div class="bc-crit-head">
        <div class="bc-crit-letter"><?= e($key) ?></div>
        <div style="flex:1; min-width: 0">
          <div class="bc-crit-name"><?= e($info['name']) ?></div>
          <div class="bc-crit-desc"><?= e($info['desc']) ?></div>
        </div>
      </div>
      <div class="bc-crit-counts">
        <div>
          <div class="bc-crit-count-val"><?= number_format($cnt) ?></div>
          <div class="bc-crit-count-lbl"><?= $rtl?'أصل ('.$pct.'%)':'assets ('.$pct.'%)' ?></div>
        </div>
        <div>
          <div class="bc-crit-cost"><?= number_format($cost, 0) ?> SAR</div>
          <div class="bc-crit-count-lbl"><?= $rtl?'قيمة إجمالية':'Total Value' ?></div>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if ($selected_crit): $info = $crit_labels[$selected_crit]; ?>
  <div class="bc-detail">
    <div class="bc-detail-h" style="color: <?= e($info['color']) ?>">
      <span class="bc-crit-pill <?= e($selected_crit) ?>"><?= e($selected_crit) ?></span>
      <?= e($info['name']) ?>
      — <?= count($crit_assets) ?> <?= $rtl?'أصل':'assets' ?>
      <a href="?crit=<?= e($selected_crit) ?>" style="margin-inline-start:auto;font-size:11px;color:#94a3b8;text-decoration:none"><i class="fa-solid fa-xmark"></i> <?= $rtl?'إغلاق':'Close' ?></a>
    </div>

    <form method="get" class="bc-fltbar">
      <input type="hidden" name="crit" value="<?= e($selected_crit) ?>">
      <div>
        <label><i class="fa-solid fa-magnifying-glass"></i> <?= $rtl?'بحث في أصول هذه الحساسية':'Search in this criticality' ?></label>
        <input type="text" name="q" value="<?= e($f_search) ?>" placeholder="<?= $rtl?'تاج / اسم / مصنع / مستلم':'Tag / Name / Mfr / Custodian' ?>">
      </div>
      <div style="display:flex;gap:6px">
        <button type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
        <a href="?crit=<?= e($selected_crit) ?>"><?= $rtl?'مسح':'Reset' ?></a>
      </div>
    </form>

    <?php if (empty($crit_assets)): ?>
      <div class="bc-empty">
        <i class="fa-solid fa-circle-check"></i>
        <h3><?= $rtl?'لا توجد أصول بهذا المستوى':'No assets at this level' ?></h3>
      </div>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="bc-tbl">
      <thead><tr>
        <th>#</th>
        <th><?= $rtl?'التاج':'Tag' ?></th>
        <th><?= $rtl?'الاسم':'Name' ?></th>
        <th><?= $rtl?'المصنع / الموديل':'Mfr / Model' ?></th>
        <th><?= $rtl?'السيريال':'Serial' ?></th>
        <th><?= $rtl?'الموقع':'Location' ?></th>
        <th><?= $rtl?'القسم':'Dept' ?></th>
        <th><?= $rtl?'المستلم':'Custodian' ?></th>
        <th><?= $rtl?'الضمان':'Warranty' ?></th>
        <th><?= $rtl?'القيمة':'Value' ?></th>
      </tr></thead>
      <tbody>
      <?php foreach ($crit_assets as $i => $r): ?>
        <tr>
          <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
          <td><span class="tag"><?= e($r['tag_number'] ?: '—') ?></span></td>
          <td>
            <div style="font-weight:600;color:#0f172a"><?= e(truncate($r['description'] ?? '', 40)) ?></div>
            <?php if ($r['description_ar']): ?>
              <div style="font-size:11.5px;color:#475569;direction:rtl"><?= e(truncate($r['description_ar'], 40)) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:12px">
            <?= e($r['manufacturer_name'] ?: '—') ?>
            <?php if ($r['model_number']): ?><div style="color:#64748b"><?= e($r['model_number']) ?></div><?php endif; ?>
          </td>
          <td style="font-family:monospace;font-size:11.5px;color:#475569"><?= e($r['serial_number'] ?: '—') ?></td>
          <td style="font-size:11.5px;color:#475569">
            <?= e($r['loc_building']) ?>
            <?php if ($r['loc_floor']): ?>/ <?= e($r['loc_floor']) ?><?php endif; ?>
          </td>
          <td style="font-size:12px"><?= e($r['dept_name'] ?: '—') ?></td>
          <td style="font-size:12px">
            <div style="font-weight:600"><?= e($r['custodian_name'] ?: '—') ?></div>
            <?php if ($r['custodian_username']): ?><div style="color:#94a3b8;font-size:10.5px;font-family:monospace">@<?= e($r['custodian_username']) ?></div><?php endif; ?>
          </td>
          <td style="font-size:11.5px;color:#64748b"><?= $r['warranty_expiry'] ? date('Y-m-d', strtotime($r['warranty_expiry'])) : '—' ?></td>
          <td style="font-family:monospace;font-size:12.5px;color:#0f172a;font-weight:700"><?= $r['cost'] ? number_format($r['cost'], 0) . ' <span style="color:#94a3b8;font-size:10.5px">SAR</span>' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p style="margin-top:8px;font-size:11.5px;color:#64748b;text-align:center">
      <?= $rtl?'عرض أول 500 أصل. الترتيب: التاج.':'Showing first 500. Sorted: tag.' ?>
    </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /.bc-wrap -->
</main>
</div><!-- /.main-area -->
</body>
</html>
