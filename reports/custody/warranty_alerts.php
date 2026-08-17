<?php
/**
 * reports/custody/warranty_alerts.php — تنبيهات الضمان
 * ──────────────────────────────────────────────────────────────────
 *   • الأصول تحت العهدة اللي ضمانها قارب ينتهي (30/60/90 يوم) أو انتهى
 *   • استعراضي بحت — ترتيب حسب الاستعجال
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';
page_guard('reports.custody.warranty_alerts');

$can_export  = can('reports.custody.warranty_alerts', 'export');
$excel_mode  = report_excel_mode_active('reports.custody.warranty_alerts');
$print_mode  = report_print_mode_active('reports.custody.warranty_alerts');
$print_charts = report_print_charts_mode_active('reports.custody.warranty_alerts');

$rtl = is_rtl();
$active_nav = 'reports.custody';
$page_title = $rtl ? 'تنبيهات الضمان' : 'Warranty Alerts';

// ═══ فلاتر ═══
$f_window = $_GET['window'] ?? 'all'; // all | 30 | 60 | 90 | expired
$f_search = trim($_GET['q'] ?? '');

// فلترة حسب قسم المستخدم
$scope = data_scope('custody', 'a');

// ═══ الإحصائيات ═══
$alerts_stats_stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN warranty_expiry < CURDATE() THEN 1 ELSE 0 END) AS expired,
        SUM(CASE WHEN warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS in_30d,
        SUM(CASE WHEN warranty_expiry BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 1 ELSE 0 END) AS in_60d,
        SUM(CASE WHEN warranty_expiry BETWEEN DATE_ADD(CURDATE(), INTERVAL 61 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 1 ELSE 0 END) AS in_90d,
        SUM(CASE WHEN warranty_expiry BETWEEN DATE_ADD(CURDATE(), INTERVAL 91 DAY) AND DATE_ADD(CURDATE(), INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS in_180d,
        SUM(CASE WHEN warranty_expiry > DATE_ADD(CURDATE(), INTERVAL 180 DAY) THEN 1 ELSE 0 END) AS in_long
    FROM assets a
    WHERE a.status='active' AND a.custodian_user_id IS NOT NULL AND a.warranty_expiry IS NOT NULL AND " . $scope['where']
);
$alerts_stats_stmt->execute($scope['params']);
$alerts_stats = $alerts_stats_stmt->fetch(PDO::FETCH_ASSOC);

// ═══ القائمة (مع فلاتر) ═══
$where = "WHERE a.status='active' AND a.custodian_user_id IS NOT NULL AND a.warranty_expiry IS NOT NULL AND " . $scope['where'];
$params = $scope['params'];

switch ($f_window) {
    case 'expired':  $where .= " AND a.warranty_expiry < CURDATE()"; break;
    case '30':       $where .= " AND a.warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; break;
    case '60':       $where .= " AND a.warranty_expiry BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)"; break;
    case '90':       $where .= " AND a.warranty_expiry BETWEEN DATE_ADD(CURDATE(), INTERVAL 61 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)"; break;
    case '180':      $where .= " AND a.warranty_expiry BETWEEN DATE_ADD(CURDATE(), INTERVAL 91 DAY) AND DATE_ADD(CURDATE(), INTERVAL 180 DAY)"; break;
    // 'all' — كل اللي عنده warranty_expiry
}

if ($f_search !== '') {
    $where .= " AND (a.tag_number LIKE ? OR a.description LIKE ? OR a.manufacturer_name LIKE ? OR a.model_number LIKE ? OR u.full_name LIKE ?)";
    $like = '%' . $f_search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$rows = $pdo->prepare("
    SELECT a.id, a.tag_number, a.description, a.description_ar, a.criticality_class,
           a.asset_type, a.manufacturer_name, a.model_number, a.warranty_expiry, a.cost,
           a.loc_building, a.loc_floor, a.loc_room,
           DATEDIFF(a.warranty_expiry, CURDATE()) AS days_left,
           u.full_name AS custodian_name, u.username AS custodian_username,
           (SELECT name FROM departments WHERE id = COALESCE(a.custodian_dept_id, u.department_id)) AS dept_name
    FROM assets a
    LEFT JOIN users u ON u.id = a.custodian_user_id
    $where
    ORDER BY a.warranty_expiry ASC
    LIMIT 500
");
$rows->execute($params);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

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
.wa-wrap { max-width: 1400px; margin: 0 auto; padding: 14px; }
.wa-back { font-size: 12.5px; color: #475569; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; margin-bottom: 10px; font-weight: 600; }
.wa-back:hover { color: #d97706; }
.wa-hero {
  background: linear-gradient(135deg, #78350f 0%, #d97706 50%, #fbbf24 100%);
  color: #fff;
  border-radius: 18px;
  padding: 24px 28px;
  margin-bottom: 16px;
  display: flex; align-items: center; gap: 18px;
  box-shadow: 0 10px 30px rgba(217,119,6,.25);
  position: relative; overflow: hidden;
}
.wa-hero::before { content: ''; position: absolute; top: -40px; right: -40px; width: 180px; height: 180px; background: radial-gradient(circle, rgba(255,255,255,.10), transparent 70%); border-radius: 50%; }
.wa-hero-ico { width: 60px; height: 60px; border-radius: 14px; background: rgba(255,255,255,.18); border: 2px solid rgba(255,255,255,.30); display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; animation: waShake 1.5s ease-in-out infinite; }
@keyframes waShake { 0%, 100% { transform: rotate(0); } 25% { transform: rotate(-10deg); } 75% { transform: rotate(10deg); } }
.wa-hero h2 { margin: 0; font-size: 20px; font-weight: 800; }
.wa-hero p { margin: 4px 0 0; font-size: 13px; opacity: .88; line-height: 1.6; }

.wa-grand { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-bottom: 16px; }
.wa-grand-stat { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; gap: 10px; transition: all 0.2s; }
.wa-grand-stat:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.04); }
.wa-grand-stat-ico { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.wa-grand-stat-num { font-size: 20px; font-weight: 800; color: #0f172a; line-height: 1; }
.wa-grand-stat-lbl { font-size: 11px; color: #64748b; margin-top: 3px; font-weight: 600; }
.wa-grand-stat.expired { border-color: #fca5a5; background: #fef2f2; }
.wa-grand-stat.urgent { border-color: #fde68a; background: #fffbeb; }

.wa-fltbar { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; margin-bottom: 12px; display: grid; grid-template-columns: 2fr auto; gap: 8px; align-items: end; }
.wa-fltbar label { display: block; font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 4px; }
.wa-fltbar input, .wa-fltbar select { padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 7px; font-size: 13px; background: #fff; width: 100%; font-family: inherit; }
.wa-fltbar button { padding: 8px 16px; border-radius: 7px; border: none; background: #d97706; color: #fff; font-weight: 600; cursor: pointer; font-size: 13px; }
.wa-fltbar a { background: #f1f5f9; color: #475569; padding: 8px 12px; border-radius: 7px; font-weight: 600; font-size: 13px; text-decoration: none; }

.wa-tbl { width: 100%; border-collapse: collapse; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; font-size: 13px; }
.wa-tbl th { background: #f8fafc; padding: 9px 12px; font-weight: 700; color: #475569; font-size: 11.5px; text-align: right; border-bottom: 1.5px solid #e2e8f0; }
.wa-tbl td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; }
.wa-tbl tr:hover { background: #fafbfc; }
.wa-tbl tr.row-expired { background: #fef2f2; }
.wa-tbl tr.row-expired:hover { background: #fee2e2; }
.wa-tbl tr.row-urgent { background: #fffbeb; }
.wa-tbl tr.row-urgent:hover { background: #fef3c7; }
.wa-tbl .tag { font-family: monospace; font-size: 12px; color: #d97706; background: #fef3c7; padding: 2px 7px; border-radius: 4px; display: inline-block; font-weight: 700; }
.wa-crit { display: inline-flex; padding: 2px 8px; border-radius: 5px; font-size: 11px; font-weight: 800; letter-spacing: .5px; }
.wa-crit.A { background: #fef2f2; color: #dc2626; }
.wa-crit.B { background: #fef3c7; color: #d97706; }
.wa-crit.C { background: #ecfeff; color: #0891b2; }
.wa-urgency { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 700; }
.wa-urgency.expired { background: #dc2626; color: #fff; }
.wa-urgency.urgent  { background: #f59e0b; color: #fff; }
.wa-urgency.warning { background: #fef3c7; color: #92400e; }
.wa-urgency.normal  { background: #ecfeff; color: #0891b2; }

.wa-empty { text-align: center; padding: 60px 16px; color: #94a3b8; background: #fff; border-radius: 12px; border: 1.5px solid #e2e8f0; }
.wa-empty i { font-size: 48px; display: block; margin-bottom: 12px; color: #16a34a; }
.wa-info-banner { background: #fffbeb; border: 1.5px solid #fde68a; border-radius: 10px; padding: 10px 14px; margin-bottom: 12px; font-size: 12.5px; color: #78350f; display: flex; align-items: center; gap: 8px; }
.wa-info-banner i { color: #d97706; }

@media (max-width: 1100px) { .wa-grand { grid-template-columns: repeat(2, 1fr); } .wa-fltbar { grid-template-columns: 1fr; } }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="wa-wrap">

  <a href="<?= BASE_URL ?>/reports/custody/index.php" class="wa-back">
    <i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i>
    <?= $rtl?'العودة إلى مركز تقارير العهدة':'Back to Custody Reports Hub' ?>
  </a>

  <div class="wa-hero">
    <div class="wa-hero-ico"><i class="fa-solid fa-bell"></i></div>
    <div style="flex:1">
      <h2><?= $rtl?'تنبيهات الضمان':'Warranty Alerts' ?></h2>
            <div style="margin-top:8px">
                <?= report_export_buttons('reports.custody.warranty_alerts') ?>
            </div>
      <p><?= $rtl?'الأصول تحت العهدة اللي ضمانها قارب ينتهي (30/60/90/180 يوم) أو انتهى — ترتيب حسب الاستعجال. يوصى بالتخطيط لتجديد الضمان أو الإحلال قبل انتهاء الفترة.':'Assets under custody with warranty expiring (30/60/90/180 days) or expired — sorted by urgency. Plan warranty renewal or replacement before expiry.' ?></p>
    </div>
  </div>

  <div class="wa-grand">
    <a href="?window=expired" class="wa-grand-stat expired" style="text-decoration:none;color:inherit">
      <div class="wa-grand-stat-ico" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-circle-exclamation"></i></div>
      <div>
        <div class="wa-grand-stat-num" style="color:#dc2626"><?= (int)($alerts_stats['expired'] ?? 0) ?></div>
        <div class="wa-grand-stat-lbl"><?= $rtl?'منتهي':'Expired' ?></div>
      </div>
    </a>
    <a href="?window=30" class="wa-grand-stat urgent" style="text-decoration:none;color:inherit">
      <div class="wa-grand-stat-ico" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-triangle-exclamation"></i></div>
      <div>
        <div class="wa-grand-stat-num" style="color:#d97706"><?= (int)($alerts_stats['in_30d'] ?? 0) ?></div>
        <div class="wa-grand-stat-lbl"><?= $rtl?'30 يوم أو أقل':'≤ 30 days' ?></div>
      </div>
    </a>
    <a href="?window=60" class="wa-grand-stat" style="text-decoration:none;color:inherit">
      <div class="wa-grand-stat-ico" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-clock"></i></div>
      <div>
        <div class="wa-grand-stat-num"><?= (int)($alerts_stats['in_60d'] ?? 0) ?></div>
        <div class="wa-grand-stat-lbl"><?= $rtl?'60 يوم':'31-60 days' ?></div>
      </div>
    </a>
    <a href="?window=90" class="wa-grand-stat" style="text-decoration:none;color:inherit">
      <div class="wa-grand-stat-ico" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-calendar"></i></div>
      <div>
        <div class="wa-grand-stat-num"><?= (int)($alerts_stats['in_90d'] ?? 0) ?></div>
        <div class="wa-grand-stat-lbl"><?= $rtl?'90 يوم':'61-90 days' ?></div>
      </div>
    </a>
    <a href="?window=all" class="wa-grand-stat" style="text-decoration:none;color:inherit">
      <div class="wa-grand-stat-ico" style="background:#d1fae5;color:#059669"><i class="fa-solid fa-list"></i></div>
      <div>
        <div class="wa-grand-stat-num"><?= (int)($alerts_stats['in_long'] ?? 0) + (int)($alerts_stats['in_180d'] ?? 0) + (int)($alerts_stats['in_90d'] ?? 0) + (int)($alerts_stats['in_60d'] ?? 0) + (int)($alerts_stats['in_30d'] ?? 0) + (int)($alerts_stats['expired'] ?? 0) ?></div>
        <div class="wa-grand-stat-lbl"><?= $rtl?'الكل (لها ضمان)':'All (w/ warranty)' ?></div>
      </div>
    </a>
  </div>

  <form method="get" class="wa-fltbar">
    <div>
      <label><?= $rtl?'النافذة':'Window' ?></label>
      <select name="window">
        <option value="all"     <?= $f_window==='all'?'selected':'' ?>><?= $rtl?'الكل (لها ضمان مسجل)':'All (has warranty)' ?></option>
        <option value="expired" <?= $f_window==='expired'?'selected':'' ?>><?= $rtl?'منتهي فقط':'Expired only' ?></option>
        <option value="30"      <?= $f_window==='30'?'selected':'' ?>><?= $rtl?'30 يوم أو أقل':'≤ 30 days' ?></option>
        <option value="60"      <?= $f_window==='60'?'selected':'' ?>><?= $rtl?'31-60 يوم':'31-60 days' ?></option>
        <option value="90"      <?= $f_window==='90'?'selected':'' ?>><?= $rtl?'61-90 يوم':'61-90 days' ?></option>
        <option value="180"     <?= $f_window==='180'?'selected':'' ?>><?= $rtl?'91-180 يوم':'91-180 days' ?></option>
      </select>
    </div>
    <div style="display:flex;gap:6px">
      <button type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
      <a href="?"><?= $rtl?'مسح':'Reset' ?></a>
    </div>
  </form>

  <?php if ($f_window === 'all' && empty($rows)): ?>
  <div class="wa-empty">
    <i class="fa-solid fa-circle-check"></i>
    <h3><?= $rtl?'لا توجد أصول تحت العهدة بضمان مسجل':'No assets with registered warranty' ?></h3>
    <p><?= $rtl?'ابدأ بتسجيل تواريخ الضمان من "نقل العهد" أو من ملف الجهاز.':'Start registering warranty dates from "Custody Transfer" or the device file.' ?></p>
  </div>
  <?php elseif (empty($rows)): ?>
  <div class="wa-empty">
    <i class="fa-solid fa-shield-check"></i>
    <h3><?= $rtl?'ممتاز — لا توجد تنبيهات في هذه النافذة':'Excellent — no alerts in this window' ?></h3>
  </div>
  <?php else: ?>

  <div class="wa-info-banner">
    <i class="fa-solid fa-bell"></i>
    <span><?= $rtl?"يوجد " . count($rows) . " أصل يستحق المتابعة":"There are " . count($rows) . " assets requiring attention" ?> — <?= $rtl?'الترتيب: الأقرب انتهاءً أولاً':'Sorted: soonest expiry first' ?></span>
  </div>

  <div style="overflow-x:auto">
  <table class="wa-tbl">
    <thead><tr>
      <th>#</th>
      <th><?= $rtl?'الاستعجال':'Urgency' ?></th>
      <th><?= $rtl?'التاج':'Tag' ?></th>
      <th><?= $rtl?'الاسم':'Name' ?></th>
      <th><?= $rtl?'المصنع / الموديل':'Mfr / Model' ?></th>
      <th><?= $rtl?'الحساسية':'Crit' ?></th>
      <th><?= $rtl?'المستلم':'Custodian' ?></th>
      <th><?= $rtl?'القسم':'Dept' ?></th>
      <th><?= $rtl?'انتهاء الضمان':'Warranty Ends' ?></th>
      <th><?= $rtl?'القيمة':'Value' ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r):
      $days = (int)$r['days_left'];
      if ($days < 0) {
        $row_cls = 'row-expired'; $urg_cls = 'expired'; $urg_text = $rtl?"منتهي ({$days} يوم)":"Expired ({$days}d)";
      } elseif ($days <= 30) {
        $row_cls = 'row-urgent';  $urg_cls = 'urgent';  $urg_text = $rtl?"متبقي {$days} يوم":"{$days}d left";
      } elseif ($days <= 90) {
        $row_cls = '';             $urg_cls = 'warning'; $urg_text = $rtl?"متبقي {$days} يوم":"{$days}d left";
      } else {
        $row_cls = '';             $urg_cls = 'normal';  $urg_text = $rtl?"متبقي {$days} يوم":"{$days}d left";
      }
      $crit = $r['criticality_class'] ?: 'C';
    ?>
      <tr class="<?= $row_cls ?>">
        <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
        <td><span class="wa-urgency <?= $urg_cls ?>"><?= e($urg_text) ?></span></td>
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
        <td><span class="wa-crit <?= e($crit) ?>"><?= e($crit) ?></span></td>
        <td style="font-size:12px">
          <div style="font-weight:600"><?= e($r['custodian_name'] ?: '—') ?></div>
          <?php if ($r['custodian_username']): ?><div style="color:#94a3b8;font-size:10.5px;font-family:monospace">@<?= e($r['custodian_username']) ?></div><?php endif; ?>
        </td>
        <td style="font-size:12px"><?= e($r['dept_name'] ?: '—') ?></td>
        <td>
          <div style="color:<?= $days<0?'#dc2626':($days<=30?'#d97706':'#0f172a') ?>;font-weight:700"><?= e($r['warranty_expiry']) ?></div>
        </td>
        <td style="font-family:monospace;font-size:12.5px;color:#0f172a;font-weight:700"><?= $r['cost'] ? number_format($r['cost'], 0) . ' <span style="color:#94a3b8;font-size:10.5px">SAR</span>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="margin-top:8px;font-size:11.5px;color:#64748b;text-align:center">
    <?= $rtl?'عرض أول 500 أصل. الترتيب: الأقرب انتهاءً أولاً.':'Showing first 500. Sorted: soonest expiry first.' ?>
  </p>
  <?php endif; ?>
</div><!-- /.wa-wrap -->
</main>
</div><!-- /.main-area -->
</body>
</html>
