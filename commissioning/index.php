<?php
/**
 * commissioning/index.php — قائمة شهادات التركيب والتشغيل
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('commissioning');
$rtl = is_rtl();

$rows=$pdo->query("
    SELECT cc.*, rm.minute_number, d.name AS dept_name
    FROM commissioning_certificates cc
    LEFT JOIN receiving_minutes rm ON rm.id=cc.receiving_minute_id
    LEFT JOIN departments d ON d.id=cc.department_id
    ORDER BY cc.created_at DESC
")->fetchAll();

$status_cfg=['draft'=>['ar'=>'مسودة','c'=>'#64748b','b'=>'#f1f5f9','i'=>'fa-pencil'],
             'sent'=>['ar'=>'مطبوعة — بانتظار الرفع','c'=>'#d97706','b'=>'#fffbeb','i'=>'fa-print'],
             'approved'=>['ar'=>'معتمدة','c'=>'#16a34a','b'=>'#f0fdf4','i'=>'fa-circle-check']];

$page_title='شهادات التركيب والتشغيل';
$active_nav='commissioning';
$breadcrumb=[['name'=>$page_title]];
$flash_msgs=get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.cc-table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.cc-table th{background:linear-gradient(135deg,#1565C0,#1e3a8a);color:#fff;padding:11px 14px;font-size:12.5px;font-weight:700;text-align:right}
.cc-table td{padding:11px 14px;border-bottom:1px solid #f1f5f9;font-size:13px}
.cc-table tr:hover{background:#f8fafc}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:99px;font-size:11.5px;font-weight:700}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<?php foreach($flash_msgs as $fm): ?><div class="alert alert-<?= $fm['type'] ?>" style="margin-bottom:12px"><?= e($fm['message']) ?></div><?php endforeach; ?>

<table class="cc-table">
  <thead><tr>
    <th>رقم الشهادة</th><th>المحضر المرتبط</th><th>القسم</th><th>الجهاز</th><th>السيريال</th><th>الحالة</th><th></th>
  </tr></thead>
  <tbody>
  <?php if(empty($rows)): ?>
  <tr><td colspan="7" style="text-align:center;padding:30px;color:#94a3b8">لا توجد شهادات بعد — تبدأ من صفحة عرض محضر استلام معتمد</td></tr>
  <?php else: foreach($rows as $r): $sc=$status_cfg[$r['status']]; ?>
  <tr>
    <td style="font-family:'Inter';font-weight:700;color:#1565C0"><?= e($r['certificate_number']) ?></td>
    <td><a href="<?= BASE_URL ?>/receiving/view.php?id=<?= $r['receiving_minute_id'] ?>" style="color:#1565C0;text-decoration:none"><?= e($r['minute_number']??'—') ?></a></td>
    <td><?= e($r['dept_name']??'—') ?></td>
    <td style="font-weight:600"><?= e($r['device_description']) ?></td>
    <td style="font-family:'Inter';font-size:12px"><?= e($r['serial_number']??'—') ?></td>
    <td><span class="status-pill" style="background:<?= $sc['b'] ?>;color:<?= $sc['c'] ?>"><i class="fa-solid <?= $sc['i'] ?>"></i> <?= $sc['ar'] ?></span></td>
    <td><a href="<?= BASE_URL ?>/commissioning/form.php?id=<?= $r['id'] ?>" class="btn" style="padding:6px 14px;font-size:12px;background:#eff6ff;color:#1565C0"><i class="fa-solid fa-eye"></i> فتح</a></td>
  </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

</main></div>
</body>
</html>