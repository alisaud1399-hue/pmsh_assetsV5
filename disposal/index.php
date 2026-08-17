<?php
/**
 * disposal/index.php — إدارة التخلص من الأصول (نموذج 9+10)
 * ─────────────────────────────────────────────────────────────────
 *   • تكهين | إتلاف | بيع | نقل خارجي
 *   • أثر: assets.status = 'disposed' (مخفي من القوائم الحية)
 *   • تقرير مخصص + طباعة A4
 *
 *   Tab: dashboard | records | new
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('disposal.index');

$rtl  = is_rtl();
$active_nav = 'disposal.index';
$page_title = $rtl?'إدارة التخلص':'Asset Disposal';
$breadcrumb = [['name'=>$rtl?'التخلص':'Disposal','url'=>BASE_URL.'/disposal/index.php']];

$uid  = (int)user_id();
$can_create = can('disposal.index', 'create');
$can_edit   = can('disposal.index', 'edit');
$can_delete = can('disposal.index', 'delete');
$can_print  = can('disposal.index', 'print');
$can_export = can('disposal.index', 'export');
$can_see_all = can_see_all();

$tab = in_array($_GET['tab'] ?? '', ['dashboard', 'records', 'new', 'view'], true)
    ? $_GET['tab'] : 'dashboard';

// ═══════════════ فلاتر ═══════════════
$f_from   = $_GET['from']   ?? date('Y-01-01');
$f_to     = $_GET['to']     ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = date('Y-m-d');

$f_type   = $_GET['type']   ?? '';   // scrap|destroy|sell|transfer_out
$f_reason = $_GET['reason'] ?? '';   // obsolete|damaged_beyond_repair|end_of_life|lost|replaced|other
$f_q      = trim($_GET['q'] ?? '');  // بحث بالتاج/الاسم/السيريال/المرجع

$where = "WHERE d.disposal_date BETWEEN ? AND ?";
$params = [$f_from, $f_to];
if ($f_type && in_array($f_type, ['scrap','destroy','sell','transfer_out'], true)) {
    $where .= " AND d.disposal_type = ?";
    $params[] = $f_type;
}
if ($f_reason && in_array($f_reason, ['obsolete','damaged_beyond_repair','end_of_life','lost','replaced','other'], true)) {
    $where .= " AND d.reason = ?";
    $params[] = $f_reason;
}
if ($f_q !== '') {
    $where .= " AND (a.tag_number LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ? OR d.committee_reference LIKE ?)";
    $like = '%'.$f_q.'%';
    array_push($params, $like, $like, $like, $like);
}

// ═══════════════ KPIs (دائماً من كل الأصول المُتخلص منها) ═══════════════
$total_disposals = (int)$pdo->query("SELECT COUNT(*) FROM asset_disposals")->fetchColumn();
$this_month      = (int)$pdo->query("SELECT COUNT(*) FROM asset_disposals
                                     WHERE disposal_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                                       AND disposal_date <  DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)")->fetchColumn();
$this_year       = (int)$pdo->query("SELECT COUNT(*) FROM asset_disposals
                                     WHERE disposal_date >= DATE_FORMAT(CURDATE(), '%Y-01-01')")->fetchColumn();
$total_value     = (float)$pdo->query("SELECT COALESCE(SUM(d.disposal_value),0) FROM asset_disposals d")->fetchColumn();

// تفصيل حسب النوع
$by_type = [];
foreach ($pdo->query("SELECT disposal_type, COUNT(*) c, COALESCE(SUM(a.cost),0) total_cost
                      FROM asset_disposals d
                      LEFT JOIN assets a ON a.id = d.asset_id
                      GROUP BY disposal_type")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $by_type[$r['disposal_type']] = $r;
}

// ═══════════════ السجلات ═══════════════
$records = [];
$st = $pdo->prepare("
    SELECT d.*,
           a.tag_number, a.description, a.description_ar, a.serial_number,
           a.manufacturer_name, a.model_number, a.criticality_class, a.cost,
           a.loc_building, a.loc_floor, a.loc_room,
           a.custodian_user_id, a.custodian_dept_id, a.custody_date,
           u.full_name AS cu_name,
           cd.name AS cd_name,
           c.name AS committee_name, c.committee_type_id, c.status AS committee_status,
           cb.full_name AS created_by_name
    FROM asset_disposals d
    LEFT JOIN assets a       ON a.id  = d.asset_id
    LEFT JOIN users u        ON u.id  = a.custodian_user_id
    LEFT JOIN departments cd ON cd.id = a.custodian_dept_id
    LEFT JOIN committees c   ON c.id  = d.committee_id
    LEFT JOIN users cb       ON cb.id = d.created_by
    $where
    ORDER BY d.disposal_date DESC, d.id DESC
    LIMIT 500
");
$st->execute($params);
$records = $st->fetchAll(PDO::FETCH_ASSOC);

// ═══════════════ البحث المتقدم للأصل (AJAX-like عبر GET) ═══════════════
$asset_search = null;
if (!empty($_GET['asset_q'])) {
    $q = trim($_GET['asset_q']);
    if (mb_strlen($q) >= 2) {
        $st = $pdo->prepare("
            SELECT a.id, a.tag_number, a.asset_number, a.description, a.description_ar, a.serial_number,
                   a.manufacturer_name, a.model_number, a.cost, a.status, a.custodian_user_id, a.custodian_dept_id,
                   u.full_name AS cu_name, cdp.name AS cd_name
            FROM assets a
            LEFT JOIN users u        ON u.id  = a.custodian_user_id
            LEFT JOIN departments cdp ON cdp.id = a.custodian_dept_id
            WHERE (a.tag_number LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ? OR a.asset_number LIKE ?)
              AND a.status NOT IN ('disposed','returned_to_supplier')
            ORDER BY a.tag_number LIMIT 12
        ");
        $like = '%'.$q.'%';
        $st->execute([$like, $like, $like, $like]);
        $asset_search = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ═══════════════ حذف ═══════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!$can_delete) { flash('danger', $rtl?'⛔ لا تملك صلاحية الحذف':'⛔ No delete permission'); header('Location:'.BASE_URL.'/disposal/index.php'); exit; }
    if (!verify_csrf()) { flash('danger', $rtl?'طلب غير صالح':'Invalid request'); header('Location:'.BASE_URL.'/disposal/index.php'); exit; }
    $del_id = (int)($_POST['id'] ?? 0);
    if ($del_id > 0) {
        $row = $pdo->prepare("SELECT asset_id FROM asset_disposals WHERE id=?");
        $row->execute([$del_id]);
        $asset_id = (int)$row->fetchColumn();
        if ($asset_id) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM asset_disposals WHERE id=?")->execute([$del_id]);
                // إرجاع الأصل للحالة "نشط" — فقط إذا ما كان عنده سجل تخلص آخر
                $cnt = (int)$pdo->query("SELECT COUNT(*) FROM asset_disposals WHERE asset_id=".$asset_id)->fetchColumn();
                if ($cnt === 0) {
                    $pdo->prepare("UPDATE assets SET status='active' WHERE id=? AND status='disposed'")->execute([$asset_id]);
                }
                $pdo->commit();
                flash('success', $rtl?'✅ تم حذف قرار التخلص وإرجاع الأصل للنشط':'✅ Disposal record deleted and asset reactivated');
            } catch (Exception $e) {
                $pdo->rollBack();
                flash('danger', '⛔ '.$e->getMessage());
            }
        }
    }
    header('Location:'.BASE_URL.'/disposal/index.php?tab=records'); exit;
}

// ═══════════════ الإعدادات ═══════════════
$type_cfg = [
    'scrap'        => ['ar'=>'تكهين',         'en'=>'Scrap',         'icon'=>'fa-recycle',          'color'=>'#475569', 'bg'=>'#f1f5f9'],
    'destroy'      => ['ar'=>'إتلاف',         'en'=>'Destroy',       'icon'=>'fa-fire',            'color'=>'#dc2626', 'bg'=>'#fef2f2'],
    'sell'         => ['ar'=>'بيع',           'en'=>'Sell',          'icon'=>'fa-hand-holding-dollar','color'=>'#16a34a', 'bg'=>'#f0fdf4'],
    'transfer_out' => ['ar'=>'نقل خارجي',    'en'=>'External Transfer','icon'=>'fa-truck-ramp-box',  'color'=>'#1565C0', 'bg'=>'#E3F2FD'],
];
$reason_cfg = [
    'obsolete'              => ['ar'=>'قديم / مُستبدل بتقنية أحدث',   'en'=>'Obsolete / Replaced'],
    'damaged_beyond_repair' => ['ar'=>'تالف ولا يمكن إصلاحه',         'en'=>'Damaged beyond repair'],
    'end_of_life'           => ['ar'=>'انتهى عمره الافتراضي',         'en'=>'End of useful life'],
    'lost'                  => ['ar'=>'مفقود (بعد البحث والتحقيق)',    'en'=>'Lost (after search)'],
    'replaced'              => ['ar'=>'مُستبدل بآخر جديد',             'en'=>'Replaced with new'],
    'other'                 => ['ar'=>'سبب آخر',                      'en'=>'Other'],
];

?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title ?? ($rtl?'إدارة التخلص':'Asset Disposal')) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>

.dsp-wrap{max-width:1320px;margin:0 auto;padding:14px}
.dsp-tabs{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;border-bottom:2px solid #e2e8f0;padding-bottom:0}
.dsp-tab{padding:10px 16px;font-size:13px;font-weight:600;color:#64748b;cursor:pointer;border-radius:8px 8px 0 0;background:transparent;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.dsp-tab:hover{background:#f8fafc;color:#0f172a}
.dsp-tab.active{color:#dc2626;background:#fef2f2;border-bottom-color:#dc2626}
.dsp-tab .badge{background:#dc2626;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:700}

.dsp-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.dsp-kpi{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 3px rgba(0,0,0,.03)}
.dsp-kpi-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.dsp-kpi-num{font-size:24px;font-weight:800;color:#0f172a;line-height:1}
.dsp-kpi-lbl{font-size:12px;color:#64748b;margin-top:4px}
.dsp-kpi-sub{font-size:11px;color:#94a3b8;margin-top:2px}

.dsp-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;margin-bottom:14px;box-shadow:0 1px 3px rgba(0,0,0,.03)}
.dsp-card-h{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center}
.dsp-card-h h3{margin:0;font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px}
.dsp-card-b{padding:14px 16px}

.dsp-fltbar{display:grid;grid-template-columns:1.5fr 1fr 1fr 1.2fr auto;gap:8px;align-items:end}
.dsp-fltbar label{display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:4px}
.dsp-fltbar input,.dsp-fltbar select{padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;background:#fff;width:100%;font-family:inherit}
.dsp-fltbar input:focus,.dsp-fltbar select:focus{outline:none;border-color:#dc2626;box-shadow:0 0 0 3px #fef2f2}
.dsp-fltbar button{padding:8px 14px;border-radius:7px;font-size:13px;font-weight:600;border:none;cursor:pointer;background:#dc2626;color:#fff}
.dsp-fltbar button.ghost{background:#f1f5f9;color:#475569}

.dsp-tbl{width:100%;border-collapse:collapse;font-size:13px}
.dsp-tbl th{background:#f8fafc;padding:9px 10px;font-weight:700;color:#475569;font-size:11.5px;text-align:right;border-bottom:1.5px solid #e2e8f0;white-space:nowrap}
.dsp-tbl td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.dsp-tbl tr:hover{background:#fafbfc}
.dsp-tbl .tag{font-family:'Inter',monospace;font-size:12px;color:#1565C0;background:#E3F2FD;padding:2px 7px;border-radius:4px;display:inline-block}

.dsp-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:6px;font-size:11.5px;font-weight:700}
.dsp-type-cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:10px 0}
.dsp-type-card{border:2px solid #e2e8f0;border-radius:10px;padding:12px;cursor:pointer;text-align:center;background:#fff;transition:all .15s}
.dsp-type-card:hover{border-color:#94a3b8;background:#f8fafc}
.dsp-type-card.selected{border-color:#dc2626;background:#fef2f2;box-shadow:0 0 0 3px rgba(220,38,38,.1)}
.dsp-type-card i{font-size:24px;display:block;margin-bottom:6px}
.dsp-type-card-name{font-size:13px;font-weight:700;color:#0f172a}
.dsp-type-card-sub{font-size:10.5px;color:#64748b;margin-top:2px}

.dsp-form-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.dsp-fld{display:flex;flex-direction:column;gap:4px}
.dsp-fld label{font-size:11.5px;font-weight:700;color:#475569}
.dsp-fld label .req{color:#dc2626;margin-right:2px}
.dsp-fld input,.dsp-fld select,.dsp-fld textarea{padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:13px;background:#fff;font-family:inherit}
.dsp-fld input:focus,.dsp-fld select:focus,.dsp-fld textarea:focus{outline:none;border-color:#dc2626;box-shadow:0 0 0 3px #fef2f2}
.dsp-fld .hint{font-size:10.5px;color:#94a3b8;margin-top:2px}

.dsp-asset-result{background:#fff;border:1.5px solid #bbf7d0;border-radius:10px;padding:10px 14px;display:flex;align-items:center;gap:12px;margin-top:6px}
.dsp-asset-result.empty{background:#fef2f2;border-color:#fecaca;color:#dc2626}
.dsp-asset-result .x{cursor:pointer;color:#dc2626;margin-right:auto;font-size:16px;background:none;border:none}
.dsp-search-row{display:flex;gap:8px}
.dsp-search-row input{flex:1;padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13.5px}
.dsp-search-row button{padding:9px 16px;border-radius:8px;background:#1565C0;color:#fff;border:none;font-weight:600;cursor:pointer;font-size:13px}
.dsp-suggest{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;margin-top:6px;max-height:280px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.08)}
.dsp-sug-item{padding:10px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;display:flex;align-items:center;gap:10px}
.dsp-sug-item:hover{background:#f0fdf4}

.dsp-mem-chip{display:inline-flex;align-items:center;gap:5px;background:#E3F2FD;color:#1565C0;padding:4px 9px;border-radius:14px;font-size:12px;font-weight:600;margin:2px}
.dsp-mem-chip .x{cursor:pointer;color:#dc2626;background:none;border:none;font-size:14px;padding:0 0 0 4px}

.dsp-empty{text-align:center;padding:48px 16px;color:#94a3b8}
.dsp-empty i{font-size:48px;display:block;margin-bottom:12px;opacity:.4}
.dsp-empty h3{margin:0 0 6px;font-size:15px;color:#475569}

.dsp-btn{padding:9px 16px;border-radius:8px;font-weight:600;font-size:13px;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .15s}
.dsp-btn.primary{background:#dc2626;color:#fff}
.dsp-btn.primary:hover{background:#b91c1c}
.dsp-btn.secondary{background:#f1f5f9;color:#475569}
.dsp-btn.outline{background:#fff;color:#475569;border:1.5px solid #e2e8f0}
.dsp-btn.outline:hover{background:#f8fafc}
.dsp-btn.danger{background:#fff;color:#dc2626;border:1.5px solid #fecaca}
.dsp-btn.danger:hover{background:#fef2f2}
.dsp-btn.sm{padding:5px 10px;font-size:11.5px}

@media (max-width: 920px){
  .dsp-kpis{grid-template-columns:repeat(2,1fr)}
  .dsp-form-grid{grid-template-columns:1fr 1fr}
  .dsp-type-cards{grid-template-columns:repeat(2,1fr)}
  .dsp-fltbar{grid-template-columns:1fr 1fr}
}
@media (max-width: 600px){
  .dsp-kpis{grid-template-columns:1fr}
  .dsp-form-grid{grid-template-columns:1fr}
}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<div class="dsp-wrap">
  <!-- العنوان -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
    <div>
      <h1 style="margin:0;font-size:22px;color:#0f172a"><i class="fa-solid fa-trash-can" style="color:#dc2626"></i> <?= $rtl?'إدارة التخلص':'Asset Disposal' ?></h1>
      <div style="font-size:12px;color:#64748b;margin-top:2px"><?= $rtl?'نموذج 9+10 — تكهين، إتلاف، بيع، نقل خارجي':'Form 9+10 — Scrap, Destroy, Sell, External Transfer' ?></div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php if ($can_create): ?>
        <a href="?tab=new" class="dsp-btn primary"><i class="fa-solid fa-plus"></i> <?= $rtl?'تسجيل تخلص جديد':'New Disposal' ?></a>
      <?php endif; ?>
      <?php if ($can_print): ?>
        <a href="<?= BASE_URL ?>/disposal/print.php" target="_blank" class="dsp-btn outline"><i class="fa-solid fa-print"></i> <?= $rtl?'طباعة السجل':'Print Register' ?></a>
      <?php endif; ?>
    </div>
  </div>

  <!-- التبويبات -->
  <div class="dsp-tabs">
    <a class="dsp-tab <?= $tab==='dashboard'?'active':'' ?>" href="?tab=dashboard"><i class="fa-solid fa-chart-pie"></i> <?= $rtl?'لوحة المؤشرات':'Dashboard' ?></a>
    <a class="dsp-tab <?= $tab==='records'?'active':'' ?>" href="?tab=records"><i class="fa-solid fa-list"></i> <?= $rtl?'السجل':'Records' ?> <span class="badge"><?= number_format(count($records)) ?></span></a>
    <?php if ($can_create): ?>
    <a class="dsp-tab <?= $tab==='new'?'active':'' ?>" href="?tab=new"><i class="fa-solid fa-plus"></i> <?= $rtl?'تسجيل جديد':'New' ?></a>
    <?php endif; ?>
  </div>

  <!-- ═══════════════ DASHBOARD ═══════════════ -->
  <?php if ($tab === 'dashboard'): ?>
  <div class="dsp-kpis">
    <div class="dsp-kpi">
      <div class="dsp-kpi-icon" style="background:#fef2f2;color:#dc2626"><i class="fa-solid fa-trash-can"></i></div>
      <div><div class="dsp-kpi-num"><?= number_format($total_disposals) ?></div><div class="dsp-kpi-lbl"><?= $rtl?'إجمالي قرارات التخلص':'Total Disposal Decisions' ?></div><div class="dsp-kpi-sub"><?= $rtl?'منذ بداية النظام':'Since system start' ?></div></div>
    </div>
    <div class="dsp-kpi">
      <div class="dsp-kpi-icon" style="background:#fff7ed;color:#ea580c"><i class="fa-solid fa-calendar-day"></i></div>
      <div><div class="dsp-kpi-num"><?= number_format($this_month) ?></div><div class="dsp-kpi-lbl"><?= $rtl?'هذا الشهر':'This Month' ?></div><div class="dsp-kpi-sub"><?= $rtl?date('F Y'):date('F Y') ?></div></div>
    </div>
    <div class="dsp-kpi">
      <div class="dsp-kpi-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa-solid fa-calendar"></i></div>
      <div><div class="dsp-kpi-num"><?= number_format($this_year) ?></div><div class="dsp-kpi-lbl"><?= $rtl?'هذا العام':'This Year' ?></div><div class="dsp-kpi-sub"><?= date('Y') ?></div></div>
    </div>
    <div class="dsp-kpi">
      <div class="dsp-kpi-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa-solid fa-coins"></i></div>
      <div><div class="dsp-kpi-num"><?= number_format($total_value, 0) ?></div><div class="dsp-kpi-lbl"><?= $rtl?'قيمة المبيعات':'Sales Value' ?></div><div class="dsp-kpi-sub"><?= $rtl?'ر.س':'SAR' ?></div></div>
    </div>
  </div>

  <!-- تفصيل حسب النوع -->
  <div class="dsp-card">
    <div class="dsp-card-h"><h3><i class="fa-solid fa-chart-pie" style="color:#dc2626"></i> <?= $rtl?'توزيع حالات التخلص':'Disposal Breakdown' ?></h3></div>
    <div class="dsp-card-b">
      <div class="dsp-type-cards">
        <?php foreach ($type_cfg as $k => $cfg):
          $cnt = (int)($by_type[$k]['c'] ?? 0);
          $val = (float)($by_type[$k]['total_cost'] ?? 0);
          $pct = $total_disposals > 0 ? round(($cnt/$total_disposals)*100, 1) : 0;
        ?>
        <div class="dsp-type-card" style="cursor:default;background:<?= $cfg['bg'] ?>">
          <i class="fa-solid <?= $cfg['icon'] ?>" style="color:<?= $cfg['color'] ?>"></i>
          <div class="dsp-type-card-name" style="color:<?= $cfg['color'] ?>"><?= $cfg['ar'] ?></div>
          <div class="dsp-type-card-sub"><?= $cfg['en'] ?></div>
          <div style="font-size:18px;font-weight:800;color:#0f172a;margin-top:8px"><?= number_format($cnt) ?></div>
          <div style="font-size:11px;color:#64748b"><?= $pct ?>% • <?= number_format($val, 0) ?> <?= $rtl?'ر.س':'SAR' ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- أحدث القرارات -->
  <div class="dsp-card">
    <div class="dsp-card-h">
      <h3><i class="fa-solid fa-clock-rotate-left" style="color:#1565C0"></i> <?= $rtl?'أحدث قرارات التخلص':'Recent Disposals' ?></h3>
      <a href="?tab=records" class="dsp-btn outline sm"><?= $rtl?'عرض الكل':'View All' ?> <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i></a>
    </div>
    <div class="dsp-card-b" style="padding:0">
      <?php if (empty($records)): ?>
        <div class="dsp-empty">
          <i class="fa-solid fa-trash-can"></i>
          <h3><?= $rtl?'لا توجد قرارات تخلص مسجلة بعد':'No disposal records yet' ?></h3>
          <p><?= $rtl?'ابدأ بتسجيل أول قرار تخلص من الأصول':'Start by recording the first asset disposal decision' ?></p>
          <?php if ($can_create): ?>
            <a href="?tab=new" class="dsp-btn primary" style="margin-top:12px"><i class="fa-solid fa-plus"></i> <?= $rtl?'تسجيل جديد':'New Disposal' ?></a>
          <?php endif; ?>
        </div>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="dsp-tbl">
          <thead><tr>
            <th>#</th>
            <th><?= $rtl?'الأصل':'Asset' ?></th>
            <th><?= $rtl?'نوع التخلص':'Type' ?></th>
            <th><?= $rtl?'السبب':'Reason' ?></th>
            <th><?= $rtl?'تاريخ التنفيذ':'Disposal Date' ?></th>
            <th><?= $rtl?'مرجع اللجنة':'Committee Ref' ?></th>
            <th><?= $rtl?'القيمة':'Value' ?></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach (array_slice($records, 0, 10) as $i => $r):
            $tc = $type_cfg[$r['disposal_type']] ?? ['ar'=>$r['disposal_type'],'color'=>'#64748b','bg'=>'#f1f5f9','icon'=>'fa-circle'];
            $rc = $reason_cfg[$r['reason']] ?? ['ar'=>$r['reason']];
          ?>
            <tr>
              <td style="text-align:center;color:#94a3b8"><?= $i+1 ?></td>
              <td>
                <div class="tag"><?= e($r['tag_number']) ?></div>
                <div style="font-size:12px;font-weight:600;color:#0f172a;margin-top:3px"><?= e(truncate($r['description'] ?? '', 50)) ?></div>
                <div style="font-size:11px;color:#64748b"><?= e($r['manufacturer_name']) ?> / <?= e($r['model_number'] ?? '—') ?></div>
              </td>
              <td><span class="dsp-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>"><i class="fa-solid <?= $tc['icon'] ?>"></i> <?= $tc['ar'] ?></span></td>
              <td style="font-size:12px"><?= $rc['ar'] ?></td>
              <td style="font-size:12px;font-weight:600;color:#475569"><?= $r['disposal_date'] ?></td>
              <td style="font-size:11.5px;color:#475569"><?= e($r['committee_reference'] ?: '—') ?></td>
              <td style="font-size:12px;font-weight:700;color:<?= $r['disposal_value']>0?'#16a34a':'#94a3b8' ?>"><?= $r['disposal_value'] > 0 ? number_format($r['disposal_value'], 0).' '.($rtl?'ر.س':'SAR') : '—' ?></td>
              <td><a href="?tab=view&id=<?= $r['id'] ?>" class="dsp-btn outline sm"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════ RECORDS ═══════════════ -->
  <?php elseif ($tab === 'records'): ?>
  <div class="dsp-card">
    <div class="dsp-card-b">
      <form method="get" class="dsp-fltbar">
        <input type="hidden" name="tab" value="records">
        <div>
          <label><i class="fa-solid fa-magnifying-glass"></i> <?= $rtl?'بحث':'Search' ?></label>
          <input type="text" name="q" value="<?= e($f_q) ?>" placeholder="<?= $rtl?'تاج / اسم / سيريال / مرجع':'Tag / Name / Serial / Ref' ?>">
        </div>
        <div>
          <label><?= $rtl?'من تاريخ':'From' ?></label>
          <input type="date" name="from" value="<?= e($f_from) ?>">
        </div>
        <div>
          <label><?= $rtl?'إلى تاريخ':'To' ?></label>
          <input type="date" name="to" value="<?= e($f_to) ?>">
        </div>
        <div>
          <label><?= $rtl?'نوع التخلص':'Type' ?></label>
          <select name="type">
            <option value=""><?= $rtl?'— الكل —':'— All —' ?></option>
            <?php foreach ($type_cfg as $k=>$cfg): ?>
              <option value="<?= $k ?>" <?= $f_type===$k?'selected':'' ?>><?= $cfg['ar'] ?> (<?= $cfg['en'] ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:6px">
          <button type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
          <a href="?tab=records" class="dsp-btn ghost" style="background:#f1f5f9;color:#475569;padding:8px 12px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none"><i class="fa-solid fa-rotate-right"></i></a>
        </div>
      </form>
    </div>
  </div>

  <div class="dsp-card">
    <div class="dsp-card-h">
      <h3><i class="fa-solid fa-list" style="color:#1565C0"></i> <?= $rtl?'سجل قرارات التخلص':'Disposal Register' ?> <span style="font-weight:400;color:#64748b">(<?= count($records) ?>)</span></h3>
      <?php if ($can_export): ?>
        <a href="?tab=records&format=excel&<?= http_build_query(array_filter(['q'=>$f_q,'from'=>$f_from,'to'=>$f_to,'type'=>$f_type,'reason'=>$f_reason])) ?>" class="dsp-btn outline sm"><i class="fa-solid fa-file-excel" style="color:#16a34a"></i> <?= $rtl?'تصدير':'Export' ?></a>
      <?php endif; ?>
    </div>
    <div class="dsp-card-b" style="padding:0">
      <?php if (empty($records)): ?>
        <div class="dsp-empty">
          <i class="fa-solid fa-folder-open"></i>
          <h3><?= $rtl?'لا توجد سجلات تطابق الفلاتر':'No records match the filters' ?></h3>
        </div>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="dsp-tbl">
          <thead><tr>
            <th>#</th>
            <th><?= $rtl?'التاج':'Tag' ?></th>
            <th><?= $rtl?'اسم الأصل':'Asset Name' ?></th>
            <th><?= $rtl?'النوع':'Type' ?></th>
            <th><?= $rtl?'السبب':'Reason' ?></th>
            <th><?= $rtl?'التاريخ':'Date' ?></th>
            <th><?= $rtl?'مرجع اللجنة':'Committee' ?></th>
            <th><?= $rtl?'سجل بواسطة':'By' ?></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($records as $i => $r):
            $tc = $type_cfg[$r['disposal_type']] ?? ['ar'=>$r['disposal_type'],'color'=>'#64748b','bg'=>'#f1f5f9','icon'=>'fa-circle'];
            $rc = $reason_cfg[$r['reason']] ?? ['ar'=>$r['reason']];
          ?>
            <tr>
              <td style="text-align:center;color:#94a3b8;font-size:11.5px"><?= $i+1 ?></td>
              <td><span class="tag"><?= e($r['tag_number']) ?></span></td>
              <td>
                <div style="font-weight:600;color:#0f172a"><?= e(truncate($r['description'] ?? '', 45)) ?></div>
                <div style="font-size:11px;color:#64748b"><?= e($r['manufacturer_name'] ?? '') ?> / <?= e($r['model_number'] ?? '—') ?></div>
              </td>
              <td><span class="dsp-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>"><i class="fa-solid <?= $tc['icon'] ?>"></i> <?= $tc['ar'] ?></span></td>
              <td style="font-size:12px"><?= $rc['ar'] ?></td>
              <td style="font-size:12px;font-weight:600"><?= $r['disposal_date'] ?></td>
              <td style="font-size:11.5px;color:#475569;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['committee_reference'] ?: '—') ?></td>
              <td style="font-size:11.5px;color:#475569"><?= e($r['created_by_name']) ?></td>
              <td>
                <a href="?tab=view&id=<?= $r['id'] ?>" class="dsp-btn outline sm" title="<?= $rtl?'عرض':'View' ?>"><i class="fa-solid fa-eye"></i></a>
                <?php if ($can_print): ?>
                  <a href="<?= BASE_URL ?>/disposal/print.php?id=<?= $r['id'] ?>" target="_blank" class="dsp-btn outline sm" title="<?= $rtl?'طباعة':'Print' ?>"><i class="fa-solid fa-print"></i></a>
                <?php endif; ?>
                <?php if ($can_delete): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('<?= $rtl?'⚠️ حذف قرار التخلص وإرجاع الأصل للحالة النشطة؟':'⚠️ Delete this disposal and reactivate the asset?' ?>')">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button type="submit" class="dsp-btn danger sm" title="<?= $rtl?'حذف':'Delete' ?>"><i class="fa-solid fa-trash"></i></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════ NEW ═══════════════ -->
  <?php elseif ($tab === 'new'): ?>
    <?php if (!$can_create): ?>
      <div class="dsp-empty"><i class="fa-solid fa-lock"></i><h3><?= $rtl?'لا تملك صلاحية التسجيل':'No create permission' ?></h3></div>
    <?php else:
      // جلب الأصول المرشحة (نشطة فقط، غير مُتخلص منها)
      $prefill_asset = null;
      if (!empty($_GET['asset_id'])) {
        $st = $pdo->prepare("SELECT * FROM assets WHERE id=? AND status NOT IN ('disposed','returned_to_supplier')");
        $st->execute([(int)$_GET['asset_id']]);
        $prefill_asset = $st->fetch(PDO::FETCH_ASSOC);
      }
    ?>
    <form id="dspForm" method="post" action="<?= BASE_URL ?>/disposal/api/save.php" enctype="multipart/form-data">
      <?= csrf_input() ?>
      <input type="hidden" name="asset_id" id="dspAssetId" value="<?= e($prefill_asset['id'] ?? '') ?>">

      <!-- 1. الأصل -->
      <div class="dsp-card">
        <div class="dsp-card-h"><h3><i class="fa-solid fa-magnifying-glass" style="color:#1565C0"></i> 1. <?= $rtl?'تحديد الأصل':'Identify Asset' ?> <span style="color:#dc2626">*</span></h3></div>
        <div class="dsp-card-b">
          <div class="dsp-search-row">
            <input type="text" id="dspAssetSearch" placeholder="<?= $rtl?'ابحث بالتاج أو السيريال أو الاسم أو رقم الأصل...':'Search by tag, serial, name or asset number...' ?>" autocomplete="off">
            <button type="button" onclick="dspSearch()"><i class="fa-solid fa-magnifying-glass"></i> <?= $rtl?'بحث':'Search' ?></button>
          </div>
          <div id="dspAssetBox">
            <?php if ($prefill_asset): ?>
              <div class="dsp-asset-result">
                <i class="fa-solid fa-box" style="color:#16a34a;font-size:18px"></i>
                <div>
                  <div style="font-weight:700;color:#0f172a"><?= e($prefill_asset['description']) ?></div>
                  <div style="font-size:12px;color:#475569">
                    <span class="tag" style="font-family:monospace;background:#E3F2FD;color:#1565C0;padding:1px 6px;border-radius:4px;font-size:11px"><?= e($prefill_asset['tag_number']) ?></span>
                    • <?= e($prefill_asset['serial_number'] ?: ($rtl?'بدون سيريال':'No serial')) ?>
                    • <?= e($prefill_asset['manufacturer_name'] ?? '—') ?>
                    • <?= number_format((float)$prefill_asset['cost'], 0) ?> <?= $rtl?'ر.س':'SAR' ?>
                  </div>
                </div>
                <button type="button" class="x" onclick="dspClearAsset()">✕</button>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- 2. نوع التخلص -->
      <div class="dsp-card">
        <div class="dsp-card-h"><h3><i class="fa-solid fa-list-check" style="color:#dc2626"></i> 2. <?= $rtl?'نوع قرار التخلص':'Disposal Decision Type' ?> <span style="color:#dc2626">*</span></h3></div>
        <div class="dsp-card-b">
          <div class="dsp-type-cards" id="dspTypeCards">
            <?php foreach ($type_cfg as $k => $cfg): ?>
              <div class="dsp-type-card" data-type="<?= $k ?>" onclick="dspSelectType('<?= $k ?>')">
                <i class="fa-solid <?= $cfg['icon'] ?>" style="color:<?= $cfg['color'] ?>"></i>
                <div class="dsp-type-card-name"><?= $cfg['ar'] ?></div>
                <div class="dsp-type-card-sub"><?= $cfg['en'] ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="disposal_type" id="dspType" required>

          <div class="dsp-form-grid" style="margin-top:14px">
            <div class="dsp-fld" style="grid-column:span 2">
              <label><?= $rtl?'سبب التخلص':'Reason' ?> <span class="req">*</span></label>
              <select name="reason" required>
                <option value=""><?= $rtl?'— اختر السبب —':'— Select reason —' ?></option>
                <?php foreach ($reason_cfg as $k=>$cfg): ?>
                  <option value="<?= $k ?>"><?= $cfg['ar'] ?> (<?= $cfg['en'] ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="dsp-fld" style="grid-column:span 2">
              <label><?= $rtl?'تفاصيل إضافية عن السبب':'Reason Details' ?></label>
              <input type="text" name="reason_notes" maxlength="500" placeholder="<?= $rtl?'اختياري - سياق إضافي':'Optional - extra context' ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- 3. اللجنة -->
      <div class="dsp-card">
        <div class="dsp-card-h"><h3><i class="fa-solid fa-users" style="color:#7B1FA2"></i> 3. <?= $rtl?'مرجع اللجنة':'Committee Reference' ?></h3></div>
        <div class="dsp-card-b">
          <div class="dsp-form-grid">
            <div class="dsp-fld" style="grid-column:span 2">
              <label><?= $rtl?'رقم قرار / محضر اللجنة':'Committee Decision No.' ?></label>
              <input type="text" name="committee_reference" placeholder="<?= $rtl?'مثال: ق-2026/15':'e.g. Q-2026/15' ?>">
            </div>
            <div class="dsp-fld">
              <label><?= $rtl?'تاريخ قرار اللجنة':'Committee Date' ?></label>
              <input type="date" name="committee_date" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="dsp-fld">
              <label><?= $rtl?'رئيس اللجنة':'Committee Chairman' ?></label>
              <input type="text" name="committee_chairman" placeholder="<?= $rtl?'الاسم الكامل':'Full name' ?>">
            </div>

            <div class="dsp-fld" style="grid-column:span 4">
              <label><?= $rtl?'أعضاء اللجنة (نص حر)':'Committee Members (free text)' ?></label>
              <textarea name="committee_members" rows="2" placeholder="<?= $rtl?'مثال: م. أحمد رئيساً، د. سامي عضواً، م. خالد عضواً...':'e.g. Eng. Ahmed (chair), Dr. Sami (member), Eng. Khaled (member)...' ?>"></textarea>
              <div class="hint"><?= $rtl?'في حال كانت اللجنة خارجية أو بدون سجل في النظام':'If the committee is external or not in the system' ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- 4. التوثيق والتنفيذ -->
      <div class="dsp-card">
        <div class="dsp-card-h"><h3><i class="fa-solid fa-file-circle-check" style="color:#16a34a"></i> 4. <?= $rtl?'التوثيق والتنفيذ':'Documentation & Execution' ?></h3></div>
        <div class="dsp-card-b">
          <div class="dsp-form-grid">
            <div class="dsp-fld">
              <label><?= $rtl?'تاريخ التنفيذ':'Execution Date' ?> <span class="req">*</span></label>
              <input type="date" name="disposal_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="dsp-fld">
              <label><?= $rtl?'رقم وثيقة القرار':'Decision Doc No.' ?></label>
              <input type="text" name="decision_doc_number" placeholder="<?= $rtl?'اختياري':'Optional' ?>">
            </div>
            <div class="dsp-fld" id="dspValueFld" style="grid-column:span 2;display:none">
              <label><?= $rtl?'قيمة البيع':'Sale Value' ?> <span class="req">*</span></label>
              <input type="number" step="0.01" min="0" name="disposal_value" id="dspValueInput" placeholder="0.00">
              <div class="hint"><?= $rtl?'يظهر فقط عند اختيار "بيع"':'Only shown when "Sell" is selected' ?></div>
            </div>
            <div class="dsp-fld" style="grid-column:span 4">
              <label><?= $rtl?'ملاحظات':'Notes' ?></label>
              <textarea name="notes" rows="2" placeholder="<?= $rtl?'أي ملاحظات إضافية...':'Any additional notes...' ?>"></textarea>
            </div>
            <div class="dsp-fld" style="grid-column:span 4">
              <label><?= $rtl?'المرفقات':'Attachments' ?></label>
              <input type="file" name="attachments[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png" style="padding:6px">
              <div class="hint"><?= $rtl?'PDF، Word، Excel، صور (حد أقصى 5 ملفات)':'PDF, Word, Excel, Images (max 5 files)' ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- أزرار -->
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:6px;flex-wrap:wrap">
        <a href="?tab=records" class="dsp-btn outline"><?= $rtl?'إلغاء':'Cancel' ?></a>
        <button type="submit" class="dsp-btn primary" id="dspSubmitBtn"><i class="fa-solid fa-check"></i> <?= $rtl?'تأكيد التسجيل':'Confirm Disposal' ?></button>
      </div>
    </form>

    <script>
    const _R = <?= $rtl?'true':'false' ?>;
    function dspSearch(){
      const q = document.getElementById('dspAssetSearch').value.trim();
      if(q.length < 2){ alert(_R?'اكتب حرفين على الأقل':'Type at least 2 characters'); return; }
      const url = new URL(window.location.href);
      url.searchParams.set('tab','new'); url.searchParams.set('asset_q',q);
      window.location.href = url.toString();
    }
    function dspClearAsset(){
      document.getElementById('dspAssetId').value = '';
      document.getElementById('dspAssetBox').innerHTML = '';
      const url = new URL(window.location.href);
      url.searchParams.delete('asset_q'); url.searchParams.delete('asset_id');
      window.location.href = url.toString();
    }
    function dspPickAsset(id, tag, name, serial, mfr, cost){
      document.getElementById('dspAssetId').value = id;
      document.getElementById('dspAssetBox').innerHTML = `
        <div class="dsp-asset-result">
          <i class="fa-solid fa-box" style="color:#16a34a;font-size:18px"></i>
          <div>
            <div style="font-weight:700;color:#0f172a">${name}</div>
            <div style="font-size:12px;color:#475569">
              <span class="tag" style="font-family:monospace;background:#E3F2FD;color:#1565C0;padding:1px 6px;border-radius:4px;font-size:11px">${tag}</span>
              • ${serial||(_R?'بدون سيريال':'No serial')} • ${mfr||'—'} • ${parseFloat(cost||0).toLocaleString()} ${_R?'ر.س':'SAR'}
            </div>
          </div>
          <button type="button" class="x" onclick="dspClearAsset()">✕</button>
        </div>`;
    }
    function dspSelectType(k){
      document.getElementById('dspType').value = k;
      document.querySelectorAll('.dsp-type-card').forEach(c=>c.classList.remove('selected'));
      document.querySelector('.dsp-type-card[data-type="'+k+'"]').classList.add('selected');
      // إظهار/إخفاء حقل قيمة البيع
      const vf = document.getElementById('dspValueFld');
      const vi = document.getElementById('dspValueInput');
      if(k === 'sell'){ vf.style.display = 'flex'; vi.required = true; }
      else { vf.style.display = 'none'; vi.required = false; vi.value = ''; }
    }

    // البحث عبر Enter
    document.getElementById('dspAssetSearch').addEventListener('keydown', function(e){
      if(e.key === 'Enter'){ e.preventDefault(); dspSearch(); }
    });

    // التحقق قبل الإرسال
    document.getElementById('dspForm').addEventListener('submit', function(e){
      if(!document.getElementById('dspAssetId').value){ e.preventDefault(); alert(_R?'⚠️ اختر أصلاً أولاً':'⚠️ Pick an asset first'); return; }
      if(!document.getElementById('dspType').value){ e.preventDefault(); alert(_R?'⚠️ اختر نوع التخلص':'⚠️ Pick disposal type'); return; }
      if(!confirm(_R?'⚠️ هل أنت متأكد؟ سيتم إيقاف الأصل نهائياً عن العمل':'⚠️ Confirm? The asset will be permanently deactivated')) e.preventDefault();
    });
    </script>
    <?php endif; ?>

  <!-- ═══════════════ عرض نتائج البحث ═══════════════ -->
  <?php if (!empty($asset_search)): ?>
  <div class="dsp-card" style="margin-top:10px">
    <div class="dsp-card-h"><h3><i class="fa-solid fa-magnifying-glass" style="color:#1565C0"></i> <?= $rtl?'نتائج البحث':'Search Results' ?> (<?= count($asset_search) ?>)</h3></div>
    <div class="dsp-card-b" style="padding:0">
      <div class="dsp-suggest">
        <?php foreach ($asset_search as $a): ?>
          <div class="dsp-sug-item" onclick='dspPickAsset(<?= $a['id'] ?>, <?= json_encode($a['tag_number']) ?>, <?= json_encode($a['description']) ?>, <?= json_encode($a['serial_number']) ?>, <?= json_encode($a['manufacturer_name']) ?>, <?= (float)$a['cost'] ?>)'>
            <i class="fa-solid fa-box" style="color:#1565C0;font-size:18px"></i>
            <div style="flex:1">
              <div style="font-weight:700;color:#0f172a;font-size:13px"><?= e($a['description']) ?></div>
              <div style="font-size:11.5px;color:#64748b">
                <span class="tag" style="font-family:monospace;background:#E3F2FD;color:#1565C0;padding:1px 6px;border-radius:4px;font-size:11px"><?= e($a['tag_number']) ?></span>
                • <?= e($a['serial_number'] ?: '—') ?>
                • <?= e($a['manufacturer_name'] ?: '—') ?>
                • <?= e($a['cu_name'] ?: ($a['cd_name'] ?: ($rtl?'بدون عهدة':'No custody'))) ?>
              </div>
            </div>
            <span style="font-size:11.5px;font-weight:700;color:#16a34a"><?= number_format((float)$a['cost'], 0) ?> <?= $rtl?'ر.س':'SAR' ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════ VIEW (تفاصيل) ═══════════════ -->
  <?php elseif ($tab === 'view'):
    $view_id = (int)($_GET['id'] ?? 0);
    $rec = null;
    if ($view_id) {
        $st = $pdo->prepare("
            SELECT d.*, a.*, a.id AS asset_id_main,
                   u.full_name AS cu_name, cd.name AS cd_name,
                   sec.name AS sec_name,
                   cb.full_name AS created_by_name
            FROM asset_disposals d
            JOIN assets a       ON a.id = d.asset_id
            LEFT JOIN users u   ON u.id = a.custodian_user_id
            LEFT JOIN departments cd ON cd.id = a.custodian_dept_id
            LEFT JOIN departments sec ON sec.id = a.custodian_section_id
            LEFT JOIN users cb  ON cb.id = d.created_by
            WHERE d.id = ?
        ");
        $st->execute([$view_id]);
        $rec = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$rec):
  ?>
    <div class="dsp-empty"><i class="fa-solid fa-circle-xmark"></i><h3><?= $rtl?'القرار غير موجود':'Record not found' ?></h3><a href="?tab=records" class="dsp-btn outline"><?= $rtl?'العودة':'Back' ?></a></div>
  <?php else:
        $tc = $type_cfg[$rec['disposal_type']] ?? ['ar'=>$rec['disposal_type']];
        $rc = $reason_cfg[$rec['reason']] ?? ['ar'=>$rec['reason']];
        $atts = $rec['attachments'] ? json_decode($rec['attachments'], true) : [];
  ?>
    <div class="dsp-card">
      <div class="dsp-card-h">
        <h3>
          <span class="dsp-badge" style="background:<?= $tc['bg'] ?? '#f1f5f9' ?>;color:<?= $tc['color'] ?? '#475569' ?>"><i class="fa-solid <?= $tc['icon'] ?? 'fa-circle' ?>"></i> <?= $tc['ar'] ?></span>
          <?= $rtl?'تفاصيل قرار التخلص':'Disposal Details' ?>
        </h3>
        <div style="display:flex;gap:6px">
          <?php if ($can_print): ?>
            <a href="<?= BASE_URL ?>/disposal/print.php?id=<?= $rec['id'] ?>" target="_blank" class="dsp-btn primary sm"><i class="fa-solid fa-print"></i> <?= $rtl?'طباعة A4':'Print A4' ?></a>
          <?php endif; ?>
          <a href="?tab=records" class="dsp-btn outline sm"><?= $rtl?'عودة':'Back' ?></a>
        </div>
      </div>
      <div class="dsp-card-b">
        <div class="dsp-form-grid">
          <div class="dsp-fld"><label><?= $rtl?'الأصل':'Asset' ?></label><div><b><?= e($rec['description']) ?></b><br><span class="tag"><?= e($rec['tag_number']) ?></span> • <?= e($rec['serial_number'] ?: '—') ?></div></div>
          <div class="dsp-fld"><label><?= $rtl?'النوع':'Type' ?></label><div><span class="dsp-badge" style="background:<?= $tc['bg'] ?? '#f1f5f9' ?>;color:<?= $tc['color'] ?? '#475569' ?>"><i class="fa-solid <?= $tc['icon'] ?? 'fa-circle' ?>"></i> <?= $tc['ar'] ?></span></div></div>
          <div class="dsp-fld"><label><?= $rtl?'السبب':'Reason' ?></label><div><?= $rc['ar'] ?><br><small style="color:#64748b"><?= $rc['en'] ?></small></div></div>
          <div class="dsp-fld"><label><?= $rtl?'تاريخ التنفيذ':'Execution Date' ?></label><div style="font-weight:700;color:#0f172a"><?= $rec['disposal_date'] ?></div></div>
          <div class="dsp-fld"><label><?= $rtl?'مرجع اللجنة':'Committee Ref' ?></label><div><?= e($rec['committee_reference'] ?: '—') ?></div></div>
          <div class="dsp-fld"><label><?= $rtl?'تاريخ قرار اللجنة':'Committee Date' ?></label><div><?= $rec['committee_date'] ?: '—' ?></div></div>
          <div class="dsp-fld"><label><?= $rtl?'رئيس اللجنة':'Chairman' ?></label><div><?= e($rec['committee_chairman'] ?: '—') ?></div></div>
          <div class="dsp-fld"><label><?= $rtl?'رقم وثيقة القرار':'Decision Doc' ?></label><div><?= e($rec['decision_doc_number'] ?: '—') ?></div></div>
          <div class="dsp-fld" style="grid-column:span 2"><label><?= $rtl?'أعضاء اللجنة':'Members' ?></label><div style="font-size:12px;color:#475569;white-space:pre-wrap"><?= e($rec['committee_members'] ?: '—') ?></div></div>
          <div class="dsp-fld" style="grid-column:span 2"><label><?= $rtl?'تفاصيل السبب':'Reason Details' ?></label><div style="font-size:12.5px;color:#475569"><?= e($rec['reason_notes'] ?: '—') ?></div></div>
          <div class="dsp-fld" style="grid-column:span 2"><label><?= $rtl?'ملاحظات':'Notes' ?></label><div style="font-size:12.5px;color:#475569;white-space:pre-wrap"><?= e($rec['notes'] ?: '—') ?></div></div>
          <div class="dsp-fld"><label><?= $rtl?'سجل بواسطة':'Recorded By' ?></label><div style="font-size:12.5px"><?= e($rec['created_by_name']) ?> • <?= $rec['created_at'] ?></div></div>
          <?php if ($rec['disposal_value'] > 0): ?>
          <div class="dsp-fld"><label><?= $rtl?'قيمة البيع':'Sale Value' ?></label><div style="font-weight:700;color:#16a34a"><?= number_format($rec['disposal_value'], 2) ?> <?= $rtl?'ر.س':'SAR' ?></div></div>
          <?php endif; ?>
        </div>

        <?php if (!empty($atts)): ?>
        <div style="margin-top:14px">
          <label style="font-size:11.5px;font-weight:700;color:#475569"><?= $rtl?'المرفقات':'Attachments' ?></label>
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px">
            <?php foreach ($atts as $att): ?>
              <a href="<?= BASE_URL ?>/uploads/<?= e($att['path']) ?>" target="_blank" class="dsp-btn outline sm"><i class="fa-solid fa-paperclip"></i> <?= e($att['name']) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php
    endif;
  endif;
  ?>
</div>

</main>
</div>
</body>
</html>
<?php // no footer.php - using inline close ?>
