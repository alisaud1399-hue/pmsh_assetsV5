<?php
/**
 * installation/index.php — قائمة محاضر التركيب والتشغيل
 * تصميم بلوحة 3 أعمدة حسب الحالة (جديدة/بانتظار الرفع/معتمدة) + بطاقات إحصائية
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('installation.index');

/* نطاق الفريق الفني — كل فريق صيانة يرى فقط شهادات نوع جهازه؛
   admin/executive يرون الكل عبر can_see_all() داخل data_scope() نفسها. */
$scope = data_scope('installation_certificate', 'cc');

$rows_q = $pdo->prepare("
    SELECT cc.*, rm.minute_number, d.name AS dept_name,
        rmi.description AS live_device_description
    FROM commissioning_certificates cc
    LEFT JOIN receiving_minutes rm ON rm.id = cc.receiving_minute_id
    LEFT JOIN departments d ON d.id = cc.department_id
    LEFT JOIN receiving_minute_items rmi ON rmi.id = cc.receiving_minute_item_id
    WHERE {$scope['where']}
    ORDER BY cc.created_at DESC
");
$rows_q->execute($scope['params']);
$rows = $rows_q->fetchAll();

$by_status = ['draft' => [], 'sent' => [], 'approved' => []];
foreach ($rows as $r) {
    $st = $r['status'] ?? 'draft';
    if (!isset($by_status[$st])) $st = 'draft';
    $by_status[$st][] = $r;
}

/* ═══ محاضر مُوزَّعة على أقسام، ولم تُصدَر لها أي شهادة إطلاقاً بعد ═══
   (لا سجل في commissioning_certificates على الإطلاق — لا حتى مسودة).
   هذا ما كان عمود "جديدة" يفترض عرضه، وكان يعرض بالخطأ مسودات موجودة
   فعلاً بدل المحاضر الحقيقية التي تنتظر البدء. */
/* نفس نطاق الفريق الفني، لكن مباشرة على rmi.asset_type — هنا لا يوجد
   بعد أي سجل شهادة لنربط عبره كما في data_scope('installation_certificate') */
$pending_where = '1=0';
$pending_params = [];
if (can_see_all()) {
    $pending_where = '1=1';
} else {
    $my_dept_id = (int)(current_user()['department_id'] ?? 0);
    if ($my_dept_id) {
        $dc = $pdo->prepare("SELECT dept_category FROM departments WHERE id=?");
        $dc->execute([$my_dept_id]);
        $cat = $dc->fetchColumn();
        if ($cat && str_starts_with((string)$cat, 'maintenance_')) {
            // فريق صيانة — يرى البنود بحسب نوعه (medical/it/general)
            $team_type = substr((string)$cat, strlen('maintenance_'));
            if (in_array($team_type, ['medical', 'it'], true)) {
                $pending_where = 'rmi.asset_type = ?';
                $pending_params = [$team_type];
            } else {
                $pending_where = "rmi.asset_type NOT IN ('medical','it')";
            }
        } else {
            // قسم إكلينيكي/تشغيلي (صيدلية/أشعة/مختبر) — يرى البنود الموزعة على قسمه
            $pending_where = 'rmi.department_id = ?';
            $pending_params = [$my_dept_id];
        }
    }
}

// ✅ FIX 2026-08-04 (Plan A): قائمة الأجهزة الرئيسية التي لا تزال بلا شهادة
//    - نستخدم rmi_id (الجهاز) كوحدة أساسية بدل (minute, dept)
//    - NOT EXISTS يفحص أن لا توجد شهادة مرتبطة بهذا الـ rmi_id
$pending_q = $pdo->prepare("
    SELECT rmi.id AS rmi_id, rmi.minute_id, rmi.department_id,
        rm.minute_number, d.name AS dept_name,
        rmi.description AS live_device_description,
        rm.receipt_date
    FROM receiving_minute_items rmi
    JOIN receiving_minutes rm ON rm.id = rmi.minute_id
    JOIN departments d ON d.id = rmi.department_id
    WHERE rmi.department_id IS NOT NULL
      AND (rmi.parent_item_id IS NULL OR rmi.parent_item_id = 0)
      AND ($pending_where)
      AND NOT EXISTS (
          SELECT 1 FROM commissioning_certificates cc
          WHERE cc.receiving_minute_item_id = rmi.id
      )
    ORDER BY rm.receipt_date DESC
");
$pending_q->execute($pending_params);
$pending_new = $pending_q->fetchAll();

/* دمج: محاضر لم تُبدأ إطلاقاً + شهادات بدأت وبقيت مسودة — كلاهما
   "عمل لم يكتمل بعد"، بروابط صحيحة لكل حالة على حدة */
$new_bucket = [];
foreach ($pending_new as $p) {
    $new_bucket[] = [
        'is_fresh'    => true,
        'link'        => BASE_URL . '/installation/form.php?rmi_id=' . $p['rmi_id'],
        'device'      => $p['live_device_description'],
        'dept_name'   => $p['dept_name'],
        'cert_number' => null,
        'sort_ts'     => $p['receipt_date'],
    ];
}
foreach ($by_status['draft'] as $r) {
    $new_bucket[] = [
        'is_fresh'    => false,
        'link'        => BASE_URL . '/installation/form.php?id=' . $r['id'],
        'device'      => $r['live_device_description'] ?: ($r['device_description'] ?? null),
        'dept_name'   => $r['dept_name'],
        'cert_number' => $r['certificate_number'] ?? null,
        'sort_ts'     => $r['created_at'] ?? null,
    ];
}
usort($new_bucket, fn($a, $b) => strcmp((string)($b['sort_ts'] ?? ''), (string)($a['sort_ts'] ?? '')));

$total = count($rows) + count($pending_new);

function days_since($ts) {
    if (!$ts) return null;
    $d = (int) floor((time() - strtotime($ts)) / 86400);
    return max(0, $d);
}

$page_title  = 'محاضر التركيب والتشغيل';
$active_nav  = 'installation.index';
$breadcrumb  = [['name' => $page_title]];
$flash_msgs  = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:20px}
.stat-card{background:#fff;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px;border:1px solid #e2e8f0;box-shadow:0 2px 10px rgba(15,23,42,.04);transition:.2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(15,23,42,.08)}
.stat-ico{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:19px;flex-shrink:0}
.stat-num{font-family:'Inter',sans-serif;font-size:23px;font-weight:700;color:#0f172a;line-height:1.1}
.stat-lbl{font-size:12.5px;color:#64748b;font-weight:700;margin-top:3px}
.board{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
@media(max-width:1024px){.board{grid-template-columns:1fr}.stat-grid{grid-template-columns:repeat(2,1fr)}}
.col-head{padding:13px 16px;border-radius:14px 14px 0 0;display:flex;align-items:center;justify-content:space-between;color:#fff}
.col-head .t{font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px}
.col-head .n{font-family:'Inter';font-size:13px;font-weight:700;background:rgba(255,255,255,.25);padding:2px 10px;border-radius:99px}
.col-body{border-radius:0 0 14px 14px;padding:12px;min-height:170px;display:flex;flex-direction:column;gap:9px}
.col-gray .col-head{background:linear-gradient(135deg,#475569,#64748b)}
.col-gray .col-body{background:#f8fafc;border:1px solid #e2e8f0;border-top:none}
.col-amber .col-head{background:linear-gradient(135deg,#b45309,#f59e0b)}
.col-amber .col-body{background:#fffbeb;border:1px solid #fde68a;border-top:none}
.col-green .col-head{background:linear-gradient(135deg,#047857,#22c55e)}
.col-green .col-body{background:#f0fdf4;border:1px solid #bbf7d0;border-top:none}
.empty-col{flex:1;display:flex;align-items:center;justify-content:center;text-align:center;color:#94a3b8;font-size:12.5px;font-weight:600;padding:20px 10px}
.empty-col i{font-size:24px;display:block;margin-bottom:8px;opacity:.6}
.item-card{display:block;background:#fff;border-radius:10px;padding:11px 13px;text-decoration:none;box-shadow:0 1px 3px rgba(15,23,42,.05);transition:.2s;border-right:4px solid transparent}
.item-card:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(15,23,42,.1)}
.col-gray .item-card{border-right-color:#94a3b8}
.col-amber .item-card{border-right-color:#f59e0b}
.col-green .item-card{border-right-color:#22c55e}
.item-dev{font-size:13.5px;font-weight:800;color:#0f172a;margin-bottom:5px}
.item-meta{display:flex;justify-content:space-between;align-items:center;font-size:11.5px;color:#64748b;font-weight:600}
.item-cert{font-family:'Inter';color:#1565C0;font-weight:700}
.item-days{font-size:10.5px;color:#94a3b8;margin-top:6px;display:flex;align-items:center;gap:4px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<?php foreach ($flash_msgs as $fm): ?><div class="alert alert-<?= $fm['type'] ?>" style="margin-bottom:12px"><?= e($fm['message']) ?></div><?php endforeach; ?>

<div style="margin-bottom:20px">
    <h2 style="font-size:22px;font-weight:900;color:#0f172a;margin-bottom:4px">محاضر التركيب والتشغيل</h2>
    <div style="font-size:13px;color:#64748b;font-weight:600">متابعة دورة حياة محاضر التركيب من الإنشاء وحتى الاعتماد النهائي</div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-ico" style="background:linear-gradient(135deg,#1565C0,#2563eb)"><i class="fa-solid fa-layer-group"></i></div>
        <div><div class="stat-num"><?= $total ?></div><div class="stat-lbl">إجمالي المحاضر</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-ico" style="background:linear-gradient(135deg,#475569,#64748b)"><i class="fa-solid fa-file-circle-plus"></i></div>
        <div><div class="stat-num"><?= count($new_bucket) ?></div><div class="stat-lbl">جديدة</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-ico" style="background:linear-gradient(135deg,#b45309,#f59e0b)"><i class="fa-solid fa-print"></i></div>
        <div><div class="stat-num"><?= count($by_status['sent']) ?></div><div class="stat-lbl">بانتظار الرفع</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-ico" style="background:linear-gradient(135deg,#047857,#22c55e)"><i class="fa-solid fa-circle-check"></i></div>
        <div><div class="stat-num"><?= count($by_status['approved']) ?></div><div class="stat-lbl">معتمدة</div></div>
    </div>
</div>

<div class="board">

    <div class="col-gray">
        <div class="col-head"><span class="t"><i class="fa-solid fa-file-circle-plus"></i> جديدة</span><span class="n"><?= count($new_bucket) ?></span></div>
        <div class="col-body">
            <?php if (empty($new_bucket)): ?>
                <div class="empty-col"><i class="fa-solid fa-file-circle-plus"></i>لا توجد محاضر جديدة حالياً</div>
            <?php else: foreach ($new_bucket as $p): ?>
                <a href="<?= e($p['link']) ?>" class="item-card">
                    <div class="item-dev"><?= e($p['device'] ?: '—') ?></div>
                    <div class="item-meta">
                        <span><?= e($p['dept_name'] ?? '—') ?></span>
                        <span class="item-cert"><?= $p['is_fresh'] ? 'لم يبدأ بعد' : e($p['cert_number'] ?? '—') ?></span>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="col-amber">
        <div class="col-head"><span class="t"><i class="fa-solid fa-print"></i> بانتظار الرفع</span><span class="n"><?= count($by_status['sent']) ?></span></div>
        <div class="col-body">
            <?php if (empty($by_status['sent'])): ?>
                <div class="empty-col"><i class="fa-solid fa-print"></i>لا توجد محاضر مطبوعة بانتظار رفع التوقيع</div>
            <?php else: foreach ($by_status['sent'] as $r): $dys = days_since($r['sent_at'] ?? $r['created_at']); ?>
                <a href="<?= BASE_URL ?>/installation/form.php?id=<?= $r['id'] ?>" class="item-card">
                    <div class="item-dev"><?= e($r['live_device_description'] ?: $r['device_description'] ?: '—') ?></div>
                    <div class="item-meta"><span><?= e($r['dept_name'] ?? '—') ?></span><span class="item-cert"><?= e($r['certificate_number'] ?? '—') ?></span></div>
                    <?php if ($dys !== null): ?><div class="item-days"><i class="fa-regular fa-clock"></i> منذ <?= $dys ?> <?= $dys == 1 ? 'يوم' : 'أيام' ?></div><?php endif; ?>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="col-green">
        <div class="col-head"><span class="t"><i class="fa-solid fa-circle-check"></i> معتمدة</span><span class="n"><?= count($by_status['approved']) ?></span></div>
        <div class="col-body">
            <?php if (empty($by_status['approved'])): ?>
                <div class="empty-col"><i class="fa-solid fa-circle-check"></i>لم تُعتمد أي شهادة بعد</div>
            <?php else: foreach ($by_status['approved'] as $r): $dys = days_since($r['approved_at'] ?? $r['updated_at']); ?>
                <a href="<?= BASE_URL ?>/installation/form.php?id=<?= $r['id'] ?>" class="item-card">
                    <div class="item-dev"><?= e($r['live_device_description'] ?: $r['device_description'] ?: '—') ?></div>
                    <div class="item-meta"><span><?= e($r['dept_name'] ?? '—') ?></span><span class="item-cert"><?= e($r['certificate_number'] ?? '—') ?></span></div>
                    <?php if ($dys !== null): ?><div class="item-days"><i class="fa-regular fa-clock"></i> منذ <?= $dys ?> <?= $dys == 1 ? 'يوم' : 'أيام' ?></div><?php endif; ?>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>

</div>

</main></div>
</body>
</html>