<?php
/**
 * nupco/index.php — مركز NUPCو (NUPCO Hub)
 * ─────────────────────────────────────────────────────────
 *   • صفحة تجميعية لكل خدمات NUPCO
 *   • الكتالوج الموحَّد (Migration 031): الطبية + غير الطبية في جدول واحد
 *   • شعار NUPCO الرسمي من موقعهم
 *   • روابط: مزامنة + ترجمة + تحديثات الأصول + التصنيف الجماعي الموحَّد
 *
 *   التصميم: Hero مع الشعار + إحصائيات سريعة + بطاقات أنيقة
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';

$can_view = can('nupco.hub', 'view');
if (!$can_view) {
    http_response_code(403);
    die('⛔ لا تملك صلاحية الوصول');
}

$rtl = is_rtl();
$page_title = $rtl ? 'مركز NUPCO' : 'NUPCO Hub';
$active_nav = 'nupco.hub';
$breadcrumb = [
    ['name' => $rtl ? 'الإدارة' : 'Administration', 'url' => '#'],
    ['name' => $rtl ? 'مركز NUPCO' : 'NUPCO Hub'],
];
$flash_msgs = get_flash();

// ═══ إحصائيات سريعة ═══
$stats = [];
$stats['catalog_total'] = (int)$pdo->query("SELECT COUNT(*) FROM nupco_catalog")->fetchColumn();
$stats['catalog_medical'] = (int)$pdo->query("SELECT COUNT(*) FROM nupco_catalog WHERE code_type='medical'")->fetchColumn();
$stats['catalog_non_medical'] = (int)$pdo->query("SELECT COUNT(*) FROM nupco_catalog WHERE code_type='non_medical'")->fetchColumn();
$stats['desc_translated'] = (int)$pdo->query("SELECT COUNT(*) FROM nupco_catalog WHERE description_ar IS NOT NULL AND TRIM(description_ar) != ''")->fetchColumn();
$stats['desc_pending'] = $stats['catalog_total'] - $stats['desc_translated'];

$row = $pdo->query("SELECT MAX(translated_at) AS last_at FROM nupco_catalog")->fetch(PDO::FETCH_ASSOC);
$stats['last_translation'] = $row['last_at'];

// عدد الأصول بدون item_code
$stats['assets_unlinked'] = (int)$pdo->query("
    SELECT COUNT(*) FROM assets
    WHERE (generic_code IS NULL OR TRIM(generic_code) = '')
      AND status NOT IN ('disposed', 'returned_to_supplier')
")->fetchColumn();
$stats['desc_groups_pending'] = (int)$pdo->query("
    SELECT COUNT(DISTINCT UPPER(TRIM(description))) FROM assets
    WHERE (generic_code IS NULL OR TRIM(generic_code) = '')
      AND description IS NOT NULL AND description != ''
      AND status NOT IN ('disposed', 'returned_to_supplier')
")->fetchColumn();

// عدد المجموعات حسب الطبيعة (يحتاج subquery)
$stats['groups_pending_medical'] = (int)$pdo->query("
    SELECT COUNT(DISTINCT UPPER(TRIM(description))) FROM assets
    WHERE (generic_code IS NULL OR TRIM(generic_code) = '')
      AND description IS NOT NULL AND description != ''
      AND cat_level1 = 'معدات طبية'
      AND status NOT IN ('disposed', 'returned_to_supplier')
")->fetchColumn();
$stats['groups_pending_non_medical'] = $stats['desc_groups_pending'] - $stats['groups_pending_medical'];

// عدد الأصول غير المصنّفة لكل نوع
$stats['assets_pending_medical'] = (int)$pdo->query("
    SELECT COUNT(*) FROM assets
    WHERE (generic_code IS NULL OR TRIM(generic_code) = '')
      AND cat_level1 = 'معدات طبية'
      AND status NOT IN ('disposed', 'returned_to_supplier')
")->fetchColumn();
$stats['assets_pending_non_medical'] = $stats['assets_unlinked'] - $stats['assets_pending_medical'];

// عدد نتائج المطابقة (tier1, tier2, tier3)
$match_stats = $pdo->query("
    SELECT tier, status, COUNT(*) c
    FROM nupco_match_results
    GROUP BY tier, status
")->fetchAll(PDO::FETCH_ASSOC);
$match_count = [1 => 0, 2 => 0, 3 => 0, 'applied' => 0, 'pending' => 0];
foreach ($match_stats as $m) {
    $match_count[$m['tier']] = ($match_count[$m['tier']] ?? 0) + $m['c'];
    if ($m['status'] === 'applied') $match_count['applied']++;
}

// عدد مرات المزامنة
$sync_count = (int)$pdo->query("SELECT COUNT(*) FROM nupco_sync_log")->fetchColumn();
$last_sync = $pdo->query("SELECT file_name, sync_date AS synced_at, rows_in_file AS rows_total FROM nupco_sync_log WHERE status='applied' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// أصل غير طبي تمت إضافته مؤخراً (آخر 5)
$recent_nonmed = $pdo->query("
    SELECT item_no, description_en, asset_category, created_at
    FROM nupco_catalog
    WHERE code_type = 'non_medical'
    ORDER BY id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ═══ حسابات التقدم ═══
$pct_translated = $stats['catalog_total'] > 0 ? round(100 * $stats['desc_translated'] / $stats['catalog_total'], 1) : 0;
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body { font-family: 'Tajawal', 'Inter', system-ui, sans-serif !important; }
.nh-wrap { max-width: 1300px; margin: 0 auto; padding: 24px 20px; }

/* ═══ HERO ═══ */
.nh-hero {
  background:
    radial-gradient(ellipse at top right, rgba(255,140,40,.08), transparent 60%),
    radial-gradient(ellipse at bottom left, rgba(14,165,233,.08), transparent 60%),
    linear-gradient(135deg, #fffbeb 0%, #fefce8 30%, #ecfeff 100%);
  border: 1.5px solid #fed7aa;
  border-radius: 24px;
  padding: 36px 40px;
  margin-bottom: 22px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 20px rgba(255,140,40,.06);
}
.nh-hero::before {
  content: '';
  position: absolute;
  top: -50px;
  right: -50px;
  width: 200px;
  height: 200px;
  background: radial-gradient(circle, rgba(255,140,40,.15) 0%, transparent 70%);
  border-radius: 50%;
}
.nh-hero-content {
  display: flex;
  align-items: center;
  gap: 28px;
  position: relative;
  z-index: 1;
}
.nh-hero-logo {
  width: 110px;
  height: 80px;
  flex-shrink: 0;
  filter: drop-shadow(0 4px 12px rgba(255,140,40,.25));
  transition: transform .3s ease;
}
.nh-hero-logo:hover { transform: scale(1.05) rotate(-3deg); }
.nh-hero-text { flex: 1; min-width: 0; }
.nh-hero h1 {
  margin: 0 0 6px;
  font-size: 32px;
  font-weight: 800;
  color: #1e293b;
  font-family: 'Tajawal', sans-serif;
  letter-spacing: -.5px;
}
.nh-hero .nh-subtitle {
  font-size: 15px;
  color: #0891b2;
  font-weight: 700;
  font-family: 'Inter', sans-serif;
  letter-spacing: .3px;
  margin: 0 0 10px;
}
.nh-hero p {
  margin: 0;
  font-size: 14px;
  color: #475569;
  line-height: 1.7;
  font-family: 'Tajawal', sans-serif;
  max-width: 700px;
}
.nh-hero-meta {
  display: flex;
  gap: 16px;
  margin-top: 14px;
  font-size: 12px;
  color: #64748b;
  font-family: 'Inter', sans-serif;
}
.nh-hero-meta span { display: inline-flex; align-items: center; gap: 5px; }
.nh-hero-meta i { color: #f59e0b; }

/* ═══ STATS ROW ═══ */
.nh-stats {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 22px;
}
@media (max-width: 920px) { .nh-stats { grid-template-columns: repeat(2, 1fr); } }

.nh-stat {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  padding: 16px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
  transition: all .2s;
}
.nh-stat:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.08); }
.nh-stat-ico {
  width: 50px; height: 50px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.nh-stat .v { font-size: 24px; font-weight: 800; line-height: 1; font-family: 'Tajawal', sans-serif; }
.nh-stat .l { font-size: 12px; color: #64748b; font-weight: 600; margin-top: 3px; font-family: 'Tajawal', sans-serif; }
.nh-stat .pct { font-size: 11px; color: #94a3b8; margin-top: 2px; font-family: 'Inter', sans-serif; }

.nh-stat.c1 .nh-stat-ico { background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1d4ed8; }
.nh-stat.c1 .v { color: #1d4ed8; }
.nh-stat.c2 .nh-stat-ico { background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #047857; }
.nh-stat.c2 .v { color: #047857; }
.nh-stat.c3 .nh-stat-ico { background: linear-gradient(135deg, #fed7aa, #fdba74); color: #c2410c; }
.nh-stat.c3 .v { color: #c2410c; }
.nh-stat.c4 .nh-stat-ico { background: linear-gradient(135deg, #e9d5ff, #c4b5fd); color: #6d28d9; }
.nh-stat.c4 .v { color: #6d28d9; }

/* ═══ FEATURE CARDS ═══ */
.nh-section-title {
  font-size: 18px;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 14px;
  font-family: 'Tajawal', sans-serif;
  display: flex;
  align-items: center;
  gap: 10px;
}
.nh-section-title i { color: #f59e0b; }

.nh-cards {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
  margin-bottom: 22px;
}
@media (max-width: 920px) { .nh-cards { grid-template-columns: 1fr; } }

.nh-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 18px;
  overflow: hidden;
  text-decoration: none;
  color: inherit;
  display: flex;
  flex-direction: column;
  transition: all .25s ease;
  position: relative;
}
.nh-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 28px rgba(0,0,0,.12);
  border-color: transparent;
}
.nh-card-head {
  padding: 22px 24px 16px;
  display: flex;
  align-items: flex-start;
  gap: 16px;
  position: relative;
}
.nh-card-ico {
  width: 58px;
  height: 58px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  flex-shrink: 0;
  color: #fff;
  box-shadow: 0 4px 12px rgba(0,0,0,.12);
}
.nh-card.sync  .nh-card-ico { background: linear-gradient(135deg, #0e7490, #155e75); }
.nh-card.trans  .nh-card-ico { background: linear-gradient(135deg, #0891b2, #0e7490); }
.nh-card.update .nh-card-ico { background: linear-gradient(135deg, #7c3aed, #5b21b6); }

.nh-card-titles { flex: 1; min-width: 0; padding-top: 2px; }
.nh-card h3 {
  margin: 0;
  font-size: 17px;
  font-weight: 800;
  color: #0f172a;
  font-family: 'Tajawal', sans-serif;
}
.nh-card .en-title {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
  font-family: 'Inter', sans-serif;
  margin-top: 2px;
  letter-spacing: .2px;
}
.nh-card-body { padding: 0 24px 18px; flex: 1; }
.nh-card p {
  margin: 0 0 14px;
  font-size: 13px;
  color: #475569;
  line-height: 1.7;
  font-family: 'Tajawal', sans-serif;
}
.nh-card-meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.nh-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  font-family: 'Tajawal', sans-serif;
}
.nh-pill.ok { background: #dcfce7; color: #15803d; }
.nh-pill.warn { background: #fef3c7; color: #b45309; }
.nh-pill.info { background: #dbeafe; color: #1d4ed8; }
.nh-pill.gray { background: #f1f5f9; color: #475569; }

.nh-card-foot {
  padding: 14px 24px;
  background: #f8fafc;
  border-top: 1.5px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12.5px;
  font-weight: 700;
  color: #0f172a;
  font-family: 'Tajawal', sans-serif;
  transition: background .2s;
}
.nh-card:hover .nh-card-foot { background: #f1f5f9; }
.nh-card-foot .arrow {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #0891b2;
  font-family: 'Inter', sans-serif;
  transition: transform .2s;
}
.nh-card:hover .nh-card-foot .arrow { transform: translateX(-4px); }

/* ═══ RECENT ACTIVITY ═══ */
.nh-activity {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 14px;
  padding: 18px 22px;
  margin-bottom: 22px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04);
}
.nh-activity h3 {
  margin: 0 0 12px;
  font-size: 15px;
  font-weight: 800;
  color: #0f172a;
  font-family: 'Tajawal', sans-serif;
  display: flex; align-items: center; gap: 8px;
}
.nh-activity h3 i { color: #0891b2; }
.nh-act-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
@media (max-width: 700px) { .nh-act-list { grid-template-columns: 1fr; } }
.nh-act-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  background: #f8fafc;
  border-radius: 8px;
  border-inline-start: 3px solid #cbd5e1;
  font-size: 12.5px;
  color: #475569;
  font-family: 'Tajawal', sans-serif;
}
.nh-act-item.sync   { border-inline-start-color: #0e7490; }
.nh-act-item.trans  { border-inline-start-color: #0891b2; }
.nh-act-item.match  { border-inline-start-color: #7c3aed; }
.nh-act-item i { font-size: 14px; color: #94a3b8; flex-shrink: 0; }
.nh-act-item .meta { color: #94a3b8; font-size: 11px; margin-inline-start: auto; font-family: 'Inter', sans-serif; flex-shrink: 0; }
.nh-empty { text-align: center; color: #94a3b8; padding: 14px; font-size: 12.5px; font-family: 'Tajawal', sans-serif; }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>

<div class="main-area" id="mainArea">

  <?php include BASE_PATH . '/includes/topbar.php'; ?>

  <main class="page-content">
  <div class="nh-wrap">

    <!-- ═══ HERO ═══ -->
    <div class="nh-hero">
      <div class="nh-hero-content">
        <img src="<?= BASE_URL ?>/images/nupco-logo.png" alt="NUPCO" class="nh-hero-logo">
        <div class="nh-hero-text">
          <h1>مركز NUPCO · الكتالوج الموحَّد</h1>
          <div class="nh-subtitle">NUPCO Center · Unified Catalog (Medical + Non-Medical)</div>
          <p>
            المنصة الموحدة لإدارة <strong>NUPCO</strong> الطبي وكتالوج الأصول غير الطبية (IT/HVAC/ELEC/INFRA/...) في جدول واحد.
            مزامنة، ترجمة، مطابقة، وتصنيف جماعي — كل العمليات تتم هنا مع سجل تدقيق كامل.
          </p>
          <div class="nh-hero-meta">
            <span><i class="fa-solid fa-database"></i> <?= number_format($stats['catalog_total']) ?> صنف</span>
            <span><i class="fa-solid fa-stethoscope"></i> <?= number_format($stats['catalog_medical']) ?> جهاز طبي</span>
            <span><i class="fa-solid fa-screwdriver-wrench"></i> <?= number_format($stats['catalog_non_medical']) ?> غير طبي</span>
            <span><i class="fa-solid fa-language"></i> <?= $pct_translated ?>% مترجم</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ QUICK STATS ═══ -->
    <div class="nh-stats">
      <div class="nh-stat c1">
        <div class="nh-stat-ico"><i class="fa-solid fa-database"></i></div>
        <div>
          <div class="v"><?= number_format($stats['catalog_total']) ?></div>
          <div class="l">إجمالي الكتالوج</div>
          <div class="pct"><?= number_format($stats['catalog_medical']) ?> طبي · <?= number_format($stats['catalog_non_medical']) ?> غير طبي</div>
        </div>
      </div>
      <div class="nh-stat c2">
        <div class="nh-stat-ico"><i class="fa-solid fa-circle-check"></i></div>
        <div>
          <div class="v"><?= number_format($stats['desc_translated']) ?></div>
          <div class="l">الوصف مترجم</div>
          <div class="pct"><?= $pct_translated ?>% من الإجمالي</div>
        </div>
      </div>
      <div class="nh-stat c3">
        <div class="nh-stat-ico"><i class="fa-solid fa-stethoscope"></i></div>
        <div>
          <div class="v"><?= number_format($stats['assets_pending_medical']) ?></div>
          <div class="l">أصول طبية بالانتظار</div>
          <div class="pct"><?= number_format($stats['groups_pending_medical']) ?> مجموعة</div>
        </div>
      </div>
      <div class="nh-stat c4">
        <div class="nh-stat-ico"><i class="fa-solid fa-screwdriver-wrench"></i></div>
        <div>
          <div class="v"><?= number_format($stats['assets_pending_non_medical']) ?></div>
          <div class="l">أصول غير طبية بالانتظار</div>
          <div class="pct"><?= number_format($stats['groups_pending_non_medical']) ?> مجموعة</div>
        </div>
      </div>
    </div>

    <!-- ═══ FEATURE CARDS ═══ -->
    <h2 class="nh-section-title"><i class="fa-solid fa-bolt"></i> خدمات NUPCO</h2>

    <div class="nh-cards">
      <!-- مزامنة الكتالوج -->
      <a href="<?= BASE_URL ?>/nupco/sync.php" class="nh-card sync">
        <div class="nh-card-head">
          <div class="nh-card-ico"><i class="fa-solid fa-arrows-rotate"></i></div>
          <div class="nh-card-titles">
            <h3>مزامنة الكتالوج</h3>
            <div class="en-title">NUPCO Catalog Sync</div>
          </div>
        </div>
        <div class="nh-card-body">
          <p>رفع ملف Excel الرسمي من NUPCO ومقارنته مع الكتالوج المحلي. إضافة الأصناف الجديدة وتحديث الفئات والأوصاف.</p>
          <div class="nh-card-meta">
            <span class="nh-pill info"><i class="fa-solid fa-file-excel"></i> Excel Upload</span>
            <span class="nh-pill gray"><i class="fa-solid fa-shield-halved"></i> Audit Log</span>
          </div>
        </div>
        <div class="nh-card-foot">
          <span>
            <?php if ($last_sync): ?>
              <i class="fa-solid fa-clock-rotate-left" style="color:#0e7490;margin-inline-end:5px"></i>
              آخر مزامنة: <?= e(mb_substr($last_sync['synced_at'], 0, 16)) ?>
            <?php else: ?>
              <i class="fa-solid fa-circle-exclamation" style="color:#d97706;margin-inline-end:5px"></i>
              لم تتم مزامنة بعد
            <?php endif; ?>
          </span>
          <span class="arrow">فتح <i class="fa-solid fa-arrow-left"></i></span>
        </div>
      </a>

      <!-- ترجمة الكتالوج -->
      <a href="<?= BASE_URL ?>/nupco/translate_ar.php" class="nh-card trans">
        <div class="nh-card-head">
          <div class="nh-card-ico"><i class="fa-solid fa-language"></i></div>
          <div class="nh-card-titles">
            <h3>ترجمة الكتالوج</h3>
            <div class="en-title">NUPCO Translation</div>
          </div>
        </div>
        <div class="nh-card-body">
          <p>ترجمة أوصاف وفئات NUPCO إلى العربية عبر Groq AI. معالجة بالدفعات مع فلاتر متقدمة (حالة، فترة، بحث).</p>
          <div class="nh-card-meta">
            <span class="nh-pill ok"><i class="fa-solid fa-robot"></i> Groq llama-3.3</span>
            <span class="nh-pill info"><?= $pct_translated ?>% إنجاز</span>
          </div>
        </div>
        <div class="nh-card-foot">
          <span>
            <i class="fa-solid fa-circle-check" style="color:#16a34a;margin-inline-end:5px"></i>
            <?= number_format($stats['desc_translated']) ?> من <?= number_format($stats['catalog_total']) ?>
          </span>
          <span class="arrow">فتح <i class="fa-solid fa-arrow-left"></i></span>
        </div>
      </a>

      <!-- تحديثات الأصول -->
      <a href="<?= BASE_URL ?>/assets/update_from_nupco.php" class="nh-card update">
        <div class="nh-card-head">
          <div class="nh-card-ico"><i class="fa-solid fa-bullseye"></i></div>
          <div class="nh-card-titles">
            <h3>تحديثات الأصول</h3>
            <div class="en-title">Asset Matching</div>
          </div>
        </div>
        <div class="nh-card-body">
          <p>مطابقة الأصول الطبية مع NUPCO بـ 3 مستويات ثقة. اعتماد جماعي (100%) أو مراجعة فردية (70-99%) أو بحث يدوي (&lt;70%).</p>
          <div class="nh-card-meta">
            <span class="nh-pill warn"><i class="fa-solid fa-circle-check"></i> 100%</span>
            <span class="nh-pill warn"><i class="fa-solid fa-circle-exclamation"></i> 70-99%</span>
            <span class="nh-pill warn"><i class="fa-solid fa-circle-xmark"></i> &lt;70%</span>
          </div>
        </div>
        <div class="nh-card-foot">
          <span>
            <i class="fa-solid fa-link-slash" style="color:#7c3aed;margin-inline-end:5px"></i>
            <?= number_format($stats['assets_unlinked']) ?> أصل بدون رقم صنف
          </span>
          <span class="arrow">فتح <i class="fa-solid fa-arrow-left"></i></span>
        </div>
      </a>

      <!-- منضدة التصنيف الجماعي (الكتالوج الموحَّد) -->
      <a href="<?= BASE_URL ?>/assets/classify_groups.php" class="nh-card update">
        <div class="nh-card-head">
          <div class="nh-card-ico"><i class="fa-solid fa-layer-group"></i></div>
          <div class="nh-card-titles">
            <h3>الكتالوج الموحَّد (التصنيف الجماعي)</h3>
            <div class="en-title">Unified Catalog · Bulk Classification</div>
          </div>
        </div>
        <div class="nh-card-body">
          <p>مصدر واحد للأرقام الطبية وغير الطبية. طبية: بحث NUPCO أو إضافة صنف جديد. غير طبية: إضافة تلقائية بـ MANUAL-XXX-NNNNN. قرار واحد لكل وصف مميز يُصنِّف كل أصوله دفعة واحدة.</p>
          <div class="nh-card-meta">
            <span class="nh-pill ok"><i class="fa-solid fa-stethoscope"></i> <?= number_format($stats['groups_pending_medical']) ?> طبي</span>
            <span class="nh-pill info"><i class="fa-solid fa-screwdriver-wrench"></i> <?= number_format($stats['groups_pending_non_medical']) ?> غير طبي</span>
          </div>
        </div>
        <div class="nh-card-foot">
          <span>
            <i class="fa-solid fa-list-check" style="color:#7c3aed;margin-inline-end:5px"></i>
            <?= number_format($stats['desc_groups_pending']) ?> مجموعة بانتظار التصنيف
          </span>
          <span class="arrow">فتح <i class="fa-solid fa-arrow-left"></i></span>
        </div>
      </a>
    </div>

    <!-- ═══ RECENT ACTIVITY ═══ -->
    <div class="nh-activity">
      <h3><i class="fa-solid fa-clock-rotate-left"></i> آخر النشاطات</h3>
      <div class="nh-act-list">
        <?php
          $acts = [];

          if ($last_sync) {
            $acts[] = ['type'=>'sync', 'icon'=>'fa-arrows-rotate', 'text'=>'مزامنة: ' . e(mb_substr($last_sync['file_name'], 0, 35)) . ' (' . number_format((int)$last_sync['rows_total']) . ' صف)', 'time'=>$last_sync['synced_at']];
          }

          if (!empty($recent_nonmed)) {
            $last = $recent_nonmed[0];
            $acts[] = ['type'=>'trans', 'icon'=>'fa-screwdriver-wrench', 'text'=>'إضافة غير طبية: ' . e(mb_substr($last['description_en'], 0, 30)) . ' (' . e($last['item_no']) . ')', 'time'=>$last['created_at']];
          }

          if ($stats['last_translation']) {
            $acts[] = ['type'=>'trans', 'icon'=>'fa-language', 'text'=>'آخر ترجمة في الكتالوج', 'time'=>$stats['last_translation']];
          }

          $last_match = $pdo->query("SELECT created_at, status, COUNT(*) c FROM nupco_match_results WHERE created_at IS NOT NULL GROUP BY DATE(created_at), status ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
          if ($last_match) {
            $statusAr = $last_match['status'] === 'applied' ? 'معتمدة' : ($last_match['status'] === 'skipped' ? 'متجاوز عنها' : 'معلقة');
            $acts[] = ['type'=>'match', 'icon'=>'fa-bullseye', 'text'=>"مطابقات {$statusAr}: " . number_format((int)$last_match['c']) . ' صف', 'time'=>$last_match['created_at']];
          }

          $acts = array_slice($acts, 0, 4);
        ?>

        <?php if (empty($acts)): ?>
          <div class="nh-empty"><i class="fa-solid fa-inbox"></i> لا توجد نشاطات بعد. ابدأ من البطاقات أعلاه.</div>
        <?php else: ?>
          <?php foreach ($acts as $a): ?>
            <div class="nh-act-item <?= $a['type'] ?>">
              <i class="fa-solid <?= $a['icon'] ?>"></i>
              <span><?= $a['text'] ?></span>
              <span class="meta"><?= e(mb_substr($a['time'], 0, 16)) ?></span>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══ آخر الأصول غير الطبية المضافة ═══ -->
    <?php if (!empty($recent_nonmed)): ?>
    <div class="nh-activity">
      <h3><i class="fa-solid fa-screwdriver-wrench" style="color:#7c3aed"></i> آخر الأصول غير الطبية المُضافة للكتالوج</h3>
      <div class="nh-act-list">
        <?php foreach ($recent_nonmed as $r): ?>
          <div class="nh-act-item" style="border-inline-start-color:#7c3aed;">
            <i class="fa-solid fa-tag" style="color:#7c3aed"></i>
            <span><strong style="font-family:monospace;color:#7c3aed"><?= e($r['item_no']) ?></strong> · <?= e(mb_substr($r['description_en'], 0, 40)) ?><?= !empty($r['asset_category']) ? ' <span style="color:#94a3b8">[' . e($r['asset_category']) . ']</span>' : '' ?></span>
            <span class="meta"><?= e(mb_substr($r['created_at'], 0, 16)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
  </main>
</div><!-- /.main-area -->

</body>
</html>