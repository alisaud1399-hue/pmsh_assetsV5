<?php
/**
 * commissioning/form.php — نموذج شهادة التركيب والتشغيل 
 * (ترتيب البطاقات جنباً إلى جنب + دقة سحب بيانات القسم بالـ SUM + دورة الرفع والاعتماد)
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/assets/includes/_utils.php';  // لإستخدام asset_status_label() و compute_asset_completion()
require_login();

function gregorianToHijri($ymd){
    if(!$ymd) return null;
    [$y,$m,$d]=array_map('intval',explode('-',$ymd));
    if(function_exists('GregorianToJD') && function_exists('jdtoislamic')){
        $jd=GregorianToJD($m,$d,$y);
        $islamic=jdtoislamic($jd);
        [$hm,$hd,$hy]=array_map('intval',explode('/',$islamic));
        $months=['محرم','صفر','ربيع الأول','ربيع الآخر','جمادى الأولى','جمادى الآخرة','رجب','شعبان','رمضان','شوال','ذو القعدة','ذو الحجة'];
        return $hd.' '.$months[$hm-1].' '.$hy.'هـ';
    }
    return null;
}

$id = (int)($_GET['id'] ?? 0);
$minute_id = (int)($_GET['minute_id'] ?? 0);
$dept_id = (int)($_GET['department_id'] ?? 0);
$rmi_id = (int)($_GET['rmi_id'] ?? 0);  // ✅ FIX 2026-08-04 (Plan A): rmi_id للجهاز
$edit = $id > 0;

$cert = []; $device = []; $minute = [];
$dept_name = '';

// جلب الشهادة إذا كانت موجودة
if ($edit) {
    $s = $pdo->prepare("SELECT * FROM commissioning_certificates WHERE id=?");
    $s->execute([$id]);
    $cert = $s->fetch();
    if (!$cert) { flash('danger', 'الشهادة غير موجودة'); header('Location: '.BASE_URL); exit; }
    $minute_id = $cert['receiving_minute_id'];
    $dept_id = $cert['department_id'];
    $rmi_id = (int)($cert['receiving_minute_item_id'] ?? 0);
    if ($rmi_id === 0) { die('خطأ: الشهادة لا ترتبط بجهاز.'); }
} else {
    if (!$rmi_id) { die('خطأ: مطلوب رقم الجهاز (rmi_id) لإصدار الشهادة.'); }
    $rs = $pdo->prepare("SELECT minute_id, department_id FROM receiving_minute_items WHERE id=?");
    $rs->execute([$rmi_id]);
    $row = $rs->fetch(PDO::FETCH_ASSOC);
    if (!$row) { die('خطأ: الجهاز غير موجود.'); }
    $minute_id = $row['minute_id'];
    $dept_id = $row['department_id'];
}

// جلب بيانات المحضر
$s = $pdo->prepare("SELECT * FROM receiving_minutes WHERE id=?");
$s->execute([$minute_id]);
$minute = $s->fetch();
if (!$minute) die('خطأ: المحضر المذكور غير موجود.');

// جلب مرفقات المحضر الأصلي
$att_s = $pdo->prepare("SELECT * FROM receiving_minute_attachments WHERE minute_id=?");
$att_s->execute([$minute_id]);
$minute_attachments = $att_s->fetchAll();

// جلب اسم القسم
$s = $pdo->prepare("SELECT name FROM departments WHERE id=?");
$s->execute([$dept_id]);
$dept_name = $s->fetchColumn();

// 💡 جلب بيانات الجهاز الدقيقة للقسم، وتجميع (SUM) الكتالوجات والكميات لتلافي تجزئتها
$s = $pdo->prepare("
    SELECT
        MAX(rmi.description) AS description,
        MAX(rmi.manufacturer_name) AS manufacturer_name,
        MAX(rmi.model_number) AS model_number,
        SUM(rmi.quantity) AS quantity,
        SUM(rmi.manuals_operation) AS manuals_operation,
        SUM(rmi.manuals_maintenance) AS manuals_maintenance,
        SUM(rmi.cd_count) AS cd_count,
        GROUP_CONCAT(ris.serial_number SEPARATOR ' / ') AS serial_number,
        MAX(ris.warranty_years) AS warranty_years,
        MAX(rmi.item_code) AS item_code,
        MAX(rmi.generic_code) AS generic_code
    FROM receiving_minute_items rmi
    LEFT JOIN receiving_item_serials ris ON ris.item_id = rmi.id AND ris.seq_no = 1
    WHERE rmi.minute_id=? AND rmi.department_id=? AND (rmi.parent_item_id IS NULL OR rmi.parent_item_id=0)
");
$s->execute([$minute_id, $dept_id]);
$device = $s->fetch();

// ── قاموس الشركات ──
$mfrs_raw = $pdo->query("
    SELECT m.id AS mfr_id, m.name AS mfr_name, md.model_number 
    FROM manufacturers m 
    LEFT JOIN manufacturer_models md ON m.id = md.manufacturer_id
    ORDER BY m.name, md.model_number
")->fetchAll(PDO::FETCH_ASSOC);

$mfr_dict = [];
foreach($mfrs_raw as $r) {
    $m = trim($r['mfr_name']); $mod = trim($r['model_number']);
    if($m === '') continue;
    if(!isset($mfr_dict[$m])) $mfr_dict[$m] = [];
    if($mod !== '' && !in_array($mod, $mfr_dict[$m])) $mfr_dict[$m][] = $mod;
}

// ── معالجة الحفظ (POST) ──
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'خطأ في الجلسة (CSRF)'; }
    else {
        $rep_name = trim($_POST['representative_name'] ?? '');
        $reg_mgr = trim($_POST['regional_equipment_mgr_name'] ?? '');
        $spec_match = (int)($_POST['spec_match'] ?? 1);
        $op_cats = (int)($_POST['operations_catalogs_count'] ?? 0);
        $maint_cats = (int)($_POST['maintenance_catalogs_count'] ?? 0);
        $cd_count = (int)($_POST['cd_count'] ?? 0);
        $w_start = $_POST['warranty_start'] ?? date('Y-m-d');
        $w_start_hijri = gregorianToHijri($w_start);
        $w_years = (float)($_POST['warranty_years'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $action = $_POST['form_action'] ?? 'draft'; // draft, print, submit
        $status = $action === 'submit' ? 'approved' : 'draft';

        $dev_desc = $_POST['device_description'] ?? $device['description'] ?? '';
        $qty = $_POST['quantity'] ?? $device['quantity'] ?? 1;
        $mfr = $_POST['manufacturer_name'] ?? $device['manufacturer_name'] ?? '';
        $model = $_POST['model_number'] ?? $device['model_number'] ?? '';
        $sn = $_POST['serial_number'] ?? $device['serial_number'] ?? '';
        // ✅ FIX 2026-08-03: قراءة item_code + generic_code + asset_number من POST
        $item_code = trim($_POST['item_code'] ?? '') ?: null;
        $generic_code = trim($_POST['generic_code'] ?? '') ?: null;
        $asset_number = trim($_POST['asset_number'] ?? '') ?: null;

        if (empty($rep_name)) $errors[] = 'يجب إدخال اسم مندوب الشركة الموردة.';
        if (empty($reg_mgr)) $errors[] = 'يجب إدخال اسم مدير التجهيزات بالمنطقة.';

        // عند الاعتماد (submit) — التحقق من الحقول الإلزامية الجديدة
        if ($action === 'submit') {
            if (empty($_POST['criticality_class'])) $errors[] = 'يجب تحديد فئة الحساسية (A/B/C) قبل الاعتماد.';
            if (empty($_POST['loc_building'])) $errors[] = 'يجب تحديد المبنى قبل الاعتماد.';
            if (empty($_POST['cat_level1'])) $errors[] = 'يجب تحديد الفئة الرئيسية (L1) قبل الاعتماد.';
            if (empty($_POST['date_placed_in_service'])) $errors[] = 'يجب تحديد تاريخ بدء التشغيل الفعلي قبل الاعتماد.';
        }

        // معالجة رفع الملف المرفق (الشهادة الموقعة)
        $signed_file = $cert['signed_attachment'] ?? null;
        if (!empty($_FILES['signed_copy']['name']) && $_FILES['signed_copy']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['signed_copy']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                $upload_dir = BASE_PATH . '/uploads/commissioning/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $safe_name = 'cc_signed_' . time() . '_' . rand(100,999) . '.' . $ext;
                if (move_uploaded_file($_FILES['signed_copy']['tmp_name'], $upload_dir . $safe_name)) {
                    $signed_file = 'commissioning/' . $safe_name;
                }
            } else {
                $errors[] = 'صيغة الملف المرفق غير مدعومة. يرجى رفع ملف PDF أو صورة.';
            }
        }

        if ($action === 'submit' && empty($signed_file)) {
            $errors[] = 'لا يمكن اعتماد الشهادة نهائياً بدون إرفاق النسخة الموقعة والمختومة.';
        }

        if (empty($errors)) {
            if ($edit) {
                $stmt = $pdo->prepare("UPDATE commissioning_certificates SET
                    device_description=?, quantity=?, manufacturer_name=?, model_number=?, serial_number=?,
                    item_code=?, generic_code=?, asset_number=?,
                    representative_name=?, regional_equipment_mgr_name=?, spec_match=?,
                    operations_catalogs_count=?, maintenance_catalogs_count=?, cd_count=?,
                    warranty_start=?, warranty_start_hijri=?, warranty_years=?, notes=?, signed_attachment=?, status=?,
                    criticality_class=?, loc_building=?, loc_floor=?, loc_room=?,
                    cat_level1=?, cat_level2=?, cat_level3=?, date_placed_in_service=?, description_ar=?
                    WHERE id=?");
                $stmt->execute([
                    $dev_desc, $qty, $mfr, $model, $sn, $item_code, $generic_code, $asset_number,
                    $rep_name, $reg_mgr, $spec_match,
                    $op_cats, $maint_cats, $cd_count, $w_start, $w_start_hijri, $w_years, $notes, $signed_file, $status,
                    $_POST['criticality_class'] ?? null,
                    $_POST['loc_building'] ?? null,
                    $_POST['loc_floor'] ?? null,
                    $_POST['loc_room'] ?? null,
                    $_POST['cat_level1'] ?? null,
                    $_POST['cat_level2'] ?? null,
                    $_POST['cat_level3'] ?? null,
                    $_POST['date_placed_in_service'] ?: $w_start,
                    $_POST['description_ar'] ?? null,
                    $id
                ]);
            } else {
                $yr = date('Y');
                $seq = $pdo->query("SELECT COUNT(*)+1 FROM commissioning_certificates WHERE YEAR(created_at)=$yr")->fetchColumn();
                $cert_num = 'CC/' . $yr . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("INSERT INTO commissioning_certificates
                    (certificate_number, receiving_minute_id, receiving_minute_item_id, department_id,
                    device_description, quantity, manufacturer_name, model_number, serial_number,
                    item_code, generic_code, asset_number,
                    representative_name, regional_equipment_mgr_name, spec_match,
                    operations_catalogs_count, maintenance_catalogs_count, cd_count,
                    warranty_start, warranty_start_hijri, warranty_years, notes, signed_attachment, status, created_by,
                    criticality_class, loc_building, loc_floor, loc_room,
                    cat_level1, cat_level2, cat_level3, date_placed_in_service, description_ar)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $cert_num, $minute_id, $rmi_id, $dept_id, $dev_desc, $qty, $mfr, $model, $sn,
                    $item_code, $generic_code, $asset_number,
                    $rep_name, $reg_mgr, $spec_match, $op_cats, $maint_cats, $cd_count,
                    $w_start, $w_start_hijri, $w_years, $notes, $signed_file, $status, current_user()['id'],
                    $_POST['criticality_class'] ?? null,
                    $_POST['loc_building'] ?? null,
                    $_POST['loc_floor'] ?? null,
                    $_POST['loc_room'] ?? null,
                    $_POST['cat_level1'] ?? null,
                    $_POST['cat_level2'] ?? null,
                    $_POST['cat_level3'] ?? null,
                    $_POST['date_placed_in_service'] ?: $w_start,
                    $_POST['description_ar'] ?? null
                ]);
                $id = $pdo->lastInsertId();
            }
            
            if ($action === 'submit') {
                // نقل تلقائي للأصول (سد الفجوة الحرجة) — يحدث فوراً بعد الاعتماد
                $transferResult = transferCertificateToAssets($pdo, $id, user_id());
                if ($transferResult['ok']) {
                    flash('success', 'تم إقفال واعتماد شهادة التشغيل بنجاح! ✅ (الأصل #' . $transferResult['asset_id'] . ')');
                } else {
                    flash('danger', 'تم اعتماد الشهادة ❌ — تنبيه: فشل النقل التلقائي لـ assets: ' . ($transferResult['error'] ?? 'unknown'));
                }
                header('Location: ' . BASE_URL . '/receiving/view.php?id=' . $minute_id);
                exit;
            } elseif ($action === 'print') {
                flash('success', 'تم حفظ البيانات. جاري فتح صفحة الطباعة...');
                header('Location: ' . BASE_URL . '/commissioning/form.php?id=' . $id . '&print=1');
                exit;
            } else {
                flash('success', 'تم حفظ المسودة بنجاح.');
                header('Location: ' . BASE_URL . '/commissioning/form.php?id=' . $id);
                exit;
            }
        }
    }
}

$p = empty($_POST) ? $cert : $_POST;
$is_approved = ($p['status'] ?? '') === 'approved';
$page_title = $edit ? ($is_approved ? 'عرض شهادة التشغيل المعتمدة' : 'تجهيز شهادة التشغيل') : 'إصدار شهادة تشغيل جديدة';
$active_nav = 'receiving.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.dashboard-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap:18px; margin-bottom:18px; }
@media(max-width:1024px){ .dashboard-grid { grid-template-columns: 1fr; } }
.fc { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.03); overflow:hidden; border:1px solid #e2e8f0; display:flex; flex-direction:column; height: 100%; }
.fch-colored { background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #ffffff; padding: 14px 18px; font-size: 15px; font-weight: 800; display: flex; align-items: center; gap: 10px; }
.fch-colored.green { background: linear-gradient(135deg, #047857, #16a34a); }
.fch-colored.amber { background: linear-gradient(135deg, #b45309, #d97706); }
.fch-colored.purple { background: linear-gradient(135deg, #6d28d9, #9333ea); }
.fch-colored.dark { background: linear-gradient(135deg, #0f172a, #334155); }
.fb { padding:20px; flex:1; }
.gen-label { font-size:12px; font-weight:800; color:#475569; margin-bottom:6px; display:block; }
.rfi { height:38px; padding-inline:12px; border:1.5px solid #e2e8f0; border-radius:6px; font-family:'Tajawal',sans-serif; font-size:13.5px; font-weight:700; width:100%; box-sizing:border-box; color:#0f172a; transition:.2s; }
.rfi:focus { border-color:#1565C0; box-shadow: 0 0 0 3px rgba(21,101,192,0.1); outline:none;}
.rfi.readonly, .rfi[readonly] { background:#f8fafc; color:#64748b; border-color:#e2e8f0; cursor:not-allowed; }
.eng-num { font-family:'Inter', sans-serif; direction:ltr; text-align:center; }
input[type="date"].eng-num { text-align: right; }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px; }
.grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:16px; }
.match-options { display:flex; gap:12px; margin-bottom:16px; }
.match-option { flex:1; display:flex; align-items:center; justify-content:center; gap:8px; padding:10px; border:1.5px solid #e2e8f0; border-radius:8px; cursor:pointer; background:#fff; transition:.2s; font-weight:800; font-size:13.5px; }
.match-option input { display:none; }
.match-option.ok.active { background:#f0fdf4; border-color:#16a34a; color:#16a34a; box-shadow:0 2px 4px rgba(22,163,74,0.1); }
.match-option.no.active { background:#fef2f2; border-color:#dc2626; color:#dc2626; box-shadow:0 2px 4px rgba(220,38,38,0.1); }
.bottom-action-bar { position: sticky; bottom: 0; background: linear-gradient(135deg,#0f172a,#1e293b); color:#fff; border-top: 3px solid #1565C0; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; z-index: 1000; box-shadow: 0 -4px 15px rgba(0,0,0,0.15); margin: 18px -32px -28px -32px; }
.req { color:#dc2626; }
.upload-area { border:2px dashed #cbd5e1; border-radius:10px; padding:20px; text-align:center; background:#f8fafc; cursor:pointer; transition:.2s; position:relative; }
.upload-area:hover { border-color:#9333ea; background:#faf5ff; }
.upload-area input[type="file"] { position:absolute; top:0; left:0; width:100%; height:100%; opacity:0; cursor:pointer; }
.file-name-display { font-size:13px; font-weight:700; color:#1565C0; margin-top:10px; word-break:break-all; }
</style>
</head>
<body class="app-layout">
<datalist id="mfrList_gen"></datalist>
<datalist id="modelList_gen"></datalist>

<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php if($errors): ?>
<div class="alert alert-danger" style="margin-bottom:14px">
  <ul style="margin:0;padding-inline-start:16px"><?php foreach($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<?php foreach($flash_msgs as $fm): ?><div class="alert alert-<?= $fm['type'] ?>" style="margin-bottom:12px"><?= e($fm['message']) ?></div><?php endforeach; ?>

<?php if($is_approved): ?>
<div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#16a34a; padding:16px; border-radius:12px; margin-bottom:18px; display:flex; align-items:center; gap:12px;">
    <i class="fa-solid fa-lock" style="font-size:24px;"></i>
    <div style="flex:1">
        <div style="font-weight:900; font-size:15px">الشهادة مقفلة ومعتمدة نهائياً</div>
        <div style="font-size:12.5px; font-weight:600">لا يمكن التعديل عليها. تم إرفاق النسخة الموقعة بالأسفل.</div>
    </div>
    <?php if (!empty($p['asset_id']) && !empty($p['transferred_at'])): ?>
    <a href="<?= BASE_URL ?>/assets/form.php?id=<?= (int)$p['asset_id'] ?>" class="dsp-btn" style="background:#ea580c;color:#fff;padding:8px 14px;border-radius:8px;font-weight:700;font-size:12.5px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;white-space:nowrap">
        <i class="fa-solid fa-box"></i> عرض الأصل #<?= (int)$p['asset_id'] ?>
        <span style="background:#fff;color:#ea580c;padding:1px 6px;border-radius:4px;font-size:10.5px;font-weight:800"><?= e(asset_status_label('pending_govt_registration', $rtl)) ?></span>
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px">
    <div>
        <h2 style="font-size:22px;font-weight:900;color:#0f172a;margin-bottom:4px"><?= e($page_title) ?></h2>
        <div style="font-size:13px;color:#64748b;font-weight:600">
            <i class="fa-solid fa-sitemap"></i> القسم المستلم: <span style="color:#1565C0"><?= e($dept_name) ?></span> | 
            <i class="fa-solid fa-file-contract"></i> محضر رقم: <span style="color:#1565C0" class="eng-num"><?= e($minute['minute_number']) ?></span>
        </div>
    </div>
    <a href="<?= BASE_URL ?>/receiving/view.php?id=<?= $minute_id ?>" class="btn" style="background:#fff; border:1px solid #cbd5e1; color:#475569; padding:8px 16px; border-radius:6px; font-weight:800; text-decoration:none; font-size:13px;">
        <i class="fa-solid fa-arrow-right"></i> العودة للمحضر
    </a>
</div>

<form method="POST" id="ccForm" enctype="multipart/form-data">
<?= csrf_input() ?>
<input type="hidden" name="form_action" id="fAction" value="draft">

<div class="dashboard-grid">

    <div class="fc">
        <div class="fch-colored"><i class="fa-solid fa-desktop" style="color:#93c5fd"></i> 1. بيانات الجهاز</div>
        <div class="fb">
            <div style="margin-bottom:16px">
                <label class="gen-label">اسم الجهاز <span class="req">*</span></label>
                <input type="text" name="device_description" class="rfi" value="<?= e($p['device_description'] ?? $device['description'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> required>
            </div>
            <div class="grid-2">
                <div>
                    <label class="gen-label">الشركة الصانعة <span class="req">*</span></label>
                    <input list="mfrList_gen" id="gMfr" name="manufacturer_name" class="rfi" value="<?= e($p['manufacturer_name'] ?? $device['manufacturer_name'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> oninput="updateGenModelDatalist(this.value)" onchange="updateGenModelDatalist(this.value)" required>
                </div>
                <div>
                    <label class="gen-label">الموديل <span class="req">*</span></label>
                    <input list="modelList_gen" id="gModel" name="model_number" class="rfi" value="<?= e($p['model_number'] ?? $device['model_number'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> onfocus="updateGenModelDatalist(document.getElementById('gMfr').value)" required>
                </div>
            </div>
            <div class="grid-2" style="margin-bottom:0">
                <div>
                    <label class="gen-label" style="color:#1565C0">الكمية <span class="req">*</span></label>
                    <input type="number" name="quantity" class="rfi eng-num" style="border-color:#1565C0;color:#1565C0;background:#eff6ff" value="<?= e($p['quantity'] ?? $device['quantity'] ?? 1) ?>" <?= $is_approved?'readonly':'' ?> min="1" required>
                </div>
                <div>
                    <label class="gen-label">الرقم التسلسلي (S/N)</label>
                    <input type="text" name="serial_number" class="rfi eng-num" value="<?= e($p['serial_number'] ?? $device['serial_number'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="S/N...">
                </div>
            </div>
            <div class="grid-2">
                <div>
                    <label class="gen-label">رقم الصنف (NUPCO/يدوي) <i class="fa-solid fa-circle-info" style="color:#1e40af" title="يبدأ بحرف M (MAL...) — يتم ملؤه تلقائياً من محضر الاستلام"></i></label>
                    <input type="text" name="item_code" class="rfi eng-num" value="<?= e($p['item_code'] ?? $device['item_code'] ?? '') ?>" readonly style="background:#f1f5f9" placeholder="— يتم جلبه تلقائياً —">
                </div>
                <div>
                    <label class="gen-label">الرمز العام (GMDN) <i class="fa-solid fa-circle-info" style="color:#92400e" title="يبدأ بـ 4 (مثال: 4200...) — مُعرّف NUPCO للنظام الفدرالي"></i></label>
                    <input type="text" name="generic_code" class="rfi eng-num" value="<?= e($p['generic_code'] ?? $device['generic_code'] ?? '') ?>" readonly style="background:#f1f5f9" placeholder="— يتم جلبه تلقائياً —">
                </div>
            </div>
            <div class="grid-2">
                <div>
                    <label class="gen-label">رقم الأصل (موارد) <i class="fa-solid fa-circle-info" style="color:#1e3a8a" title="رقم موارد الحكومي — يُدخل من قبل إدارة الأصول بعد الاعتماد"></i></label>
                    <input type="text" name="asset_number" class="rfi eng-num" value="<?= e($p['asset_number'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: 4000256789 (اختياري)">
                </div>
                <div>
                    <label class="gen-label">التاج (Tag Number) <i class="fa-solid fa-circle-info" style="color:#059669" title="الملصق التعريفي المطبوع على الجهاز"></i></label>
                    <input type="text" name="tag_number" class="rfi eng-num" value="<?= e($p['tag_number'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: BHC100200 (اختياري)">
                </div>
            </div>
        </div>
    </div>

    <div class="fc">
        <div class="fch-colored amber"><i class="fa-solid fa-calendar-check" style="color:#fde68a"></i> 2. تواريخ التشغيل والضمان</div>
        <div class="fb">
            <div class="grid-2">
                <div>
                    <label class="gen-label">تاريخ التشغيل (ميلادي) <span class="req">*</span></label>
                    <input type="date" id="gregDateInp" name="warranty_start" class="rfi eng-num" value="<?= e($p['warranty_start'] ?? date('Y-m-d')) ?>" <?= $is_approved?'readonly':'' ?> onchange="updateHijriAndDay()" required>
                </div>
                <div>
                    <label class="gen-label" style="color:#16a34a">مدة الضمان (سنوات) <span class="req">*</span></label>
                    <input type="number" name="warranty_years" class="rfi eng-num" style="border-color:#bbf7d0;font-size:15px;color:#16a34a" value="<?= e($p['warranty_years'] ?? $device['warranty_years'] ?? 1) ?>" <?= $is_approved?'readonly':'' ?> step="0.5" min="0" required>
                </div>
            </div>
            <div class="grid-2" style="margin-bottom:0">
                <div>
                    <label class="gen-label">التاريخ الهجري الموازي</label>
                    <input type="text" id="hijriDateOut" class="rfi readonly eng-num" tabindex="-1" readonly>
                </div>
                <div>
                    <label class="gen-label">يوم التشغيل</label>
                    <input type="text" id="dayNameOut" class="rfi readonly" tabindex="-1" readonly style="text-align:center">
                </div>
            </div>
        </div>
    </div>

    <div class="fc">
        <div class="fch-colored green"><i class="fa-solid fa-clipboard-check" style="color:#bbf7d0"></i> 3. الفحص الفني والمرفقات</div>
        <div class="fb">
            <label class="gen-label" style="font-size:13px; margin-bottom:8px">نتيجة مطابقة الجهاز للشروط والمواصفات:</label>
            <div class="match-options" style="<?= $is_approved ? 'pointer-events:none; opacity:0.8' : '' ?>">
                <label class="match-option ok <?= ($p['spec_match']??1)==1?'active':'' ?>" id="optMatchOk">
                    <input type="radio" name="spec_match" value="1" <?= ($p['spec_match']??1)==1?'checked':'' ?> onchange="updateMatchUI()"> 
                    <i class="fa-solid fa-check-circle" style="font-size:18px"></i> مطابق فنياً
                </label>
                <label class="match-option no <?= ($p['spec_match']??1)==0?'active':'' ?>" id="optMatchNo">
                    <input type="radio" name="spec_match" value="0" <?= ($p['spec_match']??1)==0?'checked':'' ?> onchange="updateMatchUI()"> 
                    <i class="fa-solid fa-times-circle" style="font-size:18px"></i> غير مطابق
                </label>
            </div>

            <label class="gen-label" style="font-size:13px; margin-bottom:8px">الكتالوجات والأدلة المستلمة مع الجهاز:</label>
            <div class="grid-3" style="margin-bottom:0">
                <div><label class="gen-label" style="color:#7c3aed">كتالوجات التشغيل</label><input type="number" name="operations_catalogs_count" class="rfi eng-num" style="border-color:#ddd6fe;" value="<?= e($p['operations_catalogs_count'] ?? $device['manuals_operation'] ?? 0) ?>" <?= $is_approved?'readonly':'' ?> min="0"></div>
                <div><label class="gen-label" style="color:#0891b2">كتالوجات الصيانة</label><input type="number" name="maintenance_catalogs_count" class="rfi eng-num" style="border-color:#a5f3fc;" value="<?= e($p['maintenance_catalogs_count'] ?? $device['manuals_maintenance'] ?? 0) ?>" <?= $is_approved?'readonly':'' ?> min="0"></div>
                <div><label class="gen-label" style="color:#ea580c">أقراص (CD)</label><input type="number" name="cd_count" class="rfi eng-num" style="border-color:#fed7aa;" value="<?= e($p['cd_count'] ?? $device['cd_count'] ?? 0) ?>" <?= $is_approved?'readonly':'' ?> min="0"></div>
            </div>
        </div>
    </div>

</div>

<!-- ═══ 4. تصنيف الأصل والموقع والحساسية (إلزامي قبل الاعتماد) ═══ -->
<div class="fc" style="grid-column: 1 / -1; border: 2px solid #f59e0b; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.15); margin-bottom:18px; background: linear-gradient(135deg, #fffbeb 0%, #ffffff 100%);">
    <div class="fch-colored" style="background: linear-gradient(135deg, #b45309, #d97706);">
        <i class="fa-solid fa-shield-halved" style="color:#fef3c7"></i> 4. تصنيف الأصل والموقع والحساسية <span style="background:#fef3c7;color:#92400e;font-size:10.5px;padding:2px 8px;border-radius:4px;margin-inline-start:8px;font-weight:800">إلزامي للاعتماد</span>
    </div>
    <div class="fb">
        <div style="font-size:12px; color:#92400e; font-weight:700; margin-bottom:14px; padding:8px 12px; background:#fef3c7; border-radius:6px">
            <i class="fa-solid fa-info-circle"></i> هذه البيانات تنتقل تلقائياً إلى <strong>سجل الأصول</strong> عند اعتماد الشهادة. العهدة تُسحب من <strong>محضر الاستلام</strong> (الموجود مسبقاً).
        </div>

        <!-- حساسية الجهاز -->
        <div style="margin-bottom:16px">
            <label class="gen-label">فئة الحساسية (A/B/C) <span class="req">*</span></label>
            <div class="match-options" style="<?= $is_approved ? 'pointer-events:none; opacity:0.85' : '' ?>">
                <label class="match-option <?= ($p['criticality_class']??'')==='A'?'active':'' ?>" style="border-color:<?= ($p['criticality_class']??'')==='A'?'#dc2626':'#e2e8f0' ?>;background:<?= ($p['criticality_class']??'')==='A'?'#fef2f2':'#fff' ?>;color:<?= ($p['criticality_class']??'')==='A'?'#dc2626':'#475569' ?>">
                    <input type="radio" name="criticality_class" value="A" <?= ($p['criticality_class']??'')==='A'?'checked':'' ?> required>
                    <i class="fa-solid fa-circle-exclamation" style="font-size:18px;color:#dc2626"></i>
                    <div>
                        <div style="font-size:14px;font-weight:900">A — حرج</div>
                        <div style="font-size:10.5px;font-weight:600;opacity:.8">أجهزة إنقاذ الحياة / دعم حيوي</div>
                    </div>
                </label>
                <label class="match-option <?= ($p['criticality_class']??'')==='B'?'active':'' ?>" style="border-color:<?= ($p['criticality_class']??'')==='B'?'#d97706':'#e2e8f0' ?>;background:<?= ($p['criticality_class']??'')==='B'?'#fffbeb':'#fff' ?>;color:<?= ($p['criticality_class']??'')==='B'?'#d97706':'#475569' ?>">
                    <input type="radio" name="criticality_class" value="B" <?= ($p['criticality_class']??'')==='B'?'checked':'' ?>>
                    <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;color:#d97706"></i>
                    <div>
                        <div style="font-size:14px;font-weight:900">B — مهم</div>
                        <div style="font-size:10.5px;font-weight:600;opacity:.8">أجهزة تشخيص / علاج رئيسي</div>
                    </div>
                </label>
                <label class="match-option <?= ($p['criticality_class']??'')==='C'?'active':'' ?>" style="border-color:<?= ($p['criticality_class']??'')==='C'?'#16a34a':'#e2e8f0' ?>;background:<?= ($p['criticality_class']??'')==='C'?'#f0fdf4':'#fff' ?>;color:<?= ($p['criticality_class']??'')==='C'?'#16a34a':'#475569' ?>">
                    <input type="radio" name="criticality_class" value="C" <?= ($p['criticality_class']??'')==='C'?'checked':'' ?>>
                    <i class="fa-solid fa-circle-info" style="font-size:18px;color:#16a34a"></i>
                    <div>
                        <div style="font-size:14px;font-weight:900">C — عادي</div>
                        <div style="font-size:10.5px;font-weight:600;opacity:.8">أجهزة دعم / غير حرجة</div>
                    </div>
                </label>
            </div>
        </div>

        <!-- الموقع -->
        <div style="margin-bottom:16px">
            <label class="gen-label"><i class="fa-solid fa-map-location-dot" style="color:#0e7490"></i> الموقع الفعلي للأصل <span class="req">*</span></label>
            <div class="grid-3" style="margin-bottom:0">
                <div>
                    <label class="gen-label" style="color:#0e7490">المبنى</label>
                    <input type="text" name="loc_building" class="rfi" value="<?= e($p['loc_building'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: المبنى الرئيسي" required>
                </div>
                <div>
                    <label class="gen-label" style="color:#0e7490">الطابق</label>
                    <input type="text" name="loc_floor" class="rfi" value="<?= e($p['loc_floor'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: الطابق الثاني">
                </div>
                <div>
                    <label class="gen-label" style="color:#0e7490">الغرفة / الوحدة</label>
                    <input type="text" name="loc_room" class="rfi" value="<?= e($p['loc_room'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: غرفة 201">
                </div>
            </div>
        </div>

        <!-- الفئة -->
        <div style="margin-bottom:16px">
            <label class="gen-label"><i class="fa-solid fa-sitemap" style="color:#7c3aed"></i> الفئة (L1 / L2 / L3) <span class="req">*</span></label>
            <div class="grid-3" style="margin-bottom:0">
                <div>
                    <label class="gen-label" style="color:#7c3aed">الفئة الرئيسية (L1) <span class="req">*</span></label>
                    <input type="text" name="cat_level1" list="catL1List" class="rfi" value="<?= e($p['cat_level1'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: أجهزة طبية" required>
                </div>
                <div>
                    <label class="gen-label" style="color:#7c3aed">الفئة الفرعية (L2)</label>
                    <input type="text" name="cat_level2" list="catL2List" class="rfi" value="<?= e($p['cat_level2'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: أجهزة مراقبة">
                </div>
                <div>
                    <label class="gen-label" style="color:#7c3aed">الفئة الفرعية (L3)</label>
                    <input type="text" name="cat_level3" list="catL3List" class="rfi" value="<?= e($p['cat_level3'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: Patient Monitor">
                </div>
            </div>
        </div>

        <!-- تاريخ التشغيل + الاسم العربي -->
        <div class="grid-2" style="margin-bottom:0">
            <div>
                <label class="gen-label"><i class="fa-solid fa-calendar-check" style="color:#16a34a"></i> تاريخ بدء التشغيل الفعلي <span class="req">*</span></label>
                <input type="date" name="date_placed_in_service" class="rfi eng-num" value="<?= e($p['date_placed_in_service'] ?? $p['warranty_start'] ?? date('Y-m-d')) ?>" <?= $is_approved?'readonly':'' ?> required>
                <div style="font-size:10.5px;color:#64748b;margin-top:3px"><i class="fa-solid fa-circle-info"></i> افتراضياً = تاريخ بدء الضمان. عدّل لو مختلف.</div>
            </div>
            <div>
                <label class="gen-label"><i class="fa-solid fa-language" style="color:#0891b2"></i> الاسم بالعربية (اختياري — للـ Form 8)</label>
                <input type="text" name="description_ar" dir="rtl" class="rfi" value="<?= e($p['description_ar'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: جهاز مراقبة المريض">
            </div>
        </div>

        <!-- ملاحظة عن tag/asset_number -->
        <div style="margin-top:14px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:11.5px;color:#1e40af">
            <i class="fa-solid fa-tag"></i> <strong>ملاحظة:</strong> <code>tag_number</code> و <code>asset_number</code> (رقم موارد) لا يُملآن هنا — تُضاف لاحقاً من <strong>إدارة الأصول</strong> بعد رفع البيانات على نظام موارد.
        </div>
    </div>
</div>

<datalist id="catL1List">
    <?php foreach($pdo->query("SELECT DISTINCT name FROM item_categories WHERE level=1 AND name IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $opt): ?>
        <option value="<?= e($opt) ?>">
    <?php endforeach; ?>
</datalist>
<datalist id="catL2List">
    <?php foreach($pdo->query("SELECT DISTINCT name FROM item_categories WHERE level=2 AND name IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $opt): ?>
        <option value="<?= e($opt) ?>">
    <?php endforeach; ?>
</datalist>
<datalist id="catL3List">
    <?php foreach($pdo->query("SELECT DISTINCT name FROM item_categories WHERE level=3 AND name IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $opt): ?>
        <option value="<?= e($opt) ?>">
    <?php endforeach; ?>
</datalist>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap:18px; margin-bottom:18px;">

    <div class="fc" style="margin-bottom:0">
        <div class="fch-colored dark"><i class="fa-solid fa-user-tie" style="color:#cbd5e1"></i> 5. المندوبون والملاحظات الإضافية</div>
        <div class="fb">
            <div style="margin-bottom:16px">
                <label class="gen-label">اسم مندوب الشركة (الفني المُركِّب) <span class="req">*</span></label>
                <input type="text" name="representative_name" class="rfi" value="<?= e($p['representative_name'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: م. أحمد محمد" required>
            </div>
            <div style="margin-bottom:16px">
                <label class="gen-label">مدير إدارة التجهيزات بالمنطقة <span class="req">*</span></label>
                <input type="text" name="regional_equipment_mgr_name" class="rfi" value="<?= e($p['regional_equipment_mgr_name'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="الاسم ثلاثياً..." required>
            </div>
            <div style="margin-bottom:0">
                <label class="gen-label">ملاحظات إضافية تُطبع على الشهادة (اختياري)</label>
                <textarea name="notes" class="rfi" style="height:60px; resize:vertical; padding:10px;" <?= $is_approved?'readonly':'' ?>><?= e($p['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="fc" style="margin-bottom:0; background:#f8fafc; border-color:#cbd5e1;">
        <div class="fch-colored" style="background: linear-gradient(135deg, #64748b, #475569);"><i class="fa-solid fa-box-open" style="color:#f1f5f9"></i> بيانات ومرفقات المحضر الأصلي كمرجع</div>
        <div class="fb" style="display:flex; flex-direction:column; gap:16px;">
            <div>
                <div style="font-size:12px; color:#475569; font-weight:800; margin-bottom:6px"><i class="fa-solid fa-comment-dots"></i> الملاحظات المدونة في المحضر:</div>
                <div style="background:#fff; border:1px solid #e2e8f0; padding:10px; border-radius:8px; font-size:12.5px; color:#334155; min-height:80px;">
                    <?= !empty($minute['notes']) ? nl2br(e($minute['notes'])) : '<span style="color:#94a3b8">لا توجد ملاحظات مدونة.</span>' ?>
                </div>
            </div>
            <div>
                <div style="font-size:12px; color:#475569; font-weight:800; margin-bottom:6px"><i class="fa-solid fa-paperclip"></i> مرفقات المحضر للإطلاع (إن وجدت):</div>
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <?php if(empty($minute_attachments)): ?>
                        <div style="font-size:12px; color:#94a3b8; padding:10px; background:#fff; border-radius:8px; border:1px solid #e2e8f0; text-align:center;">لا توجد مرفقات.</div>
                    <?php else: foreach($minute_attachments as $att): ?>
                        <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank" style="display:flex; align-items:center; gap:8px; padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; text-decoration:none; color:#1565C0; font-size:12.5px; font-weight:700; background:#fff; transition:.2s">
                            <i class="fa-solid fa-file-pdf" style="color:#dc2626; font-size:16px"></i> <?= e($att['file_name']) ?>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="fc" style="grid-column: 1 / -1; border-color:#9333ea; box-shadow: 0 4px 15px rgba(147, 51, 234, 0.1);">
    <div class="fch-colored purple"><i class="fa-solid fa-file-signature" style="color:#d8b4fe"></i> 6. إرفاق الشهادة الموقعة والاعتماد النهائي</div>
    <div class="fb">
        <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap">
            
            <div style="flex:1; min-width:300px; background:#faf5ff; padding:15px; border-radius:8px; border:1px solid #e9d5ff;">
                <h4 style="color:#7e22ce; font-weight:900; margin-bottom:8px; font-size:14px;"><i class="fa-solid fa-list-ol"></i> خطوات الاعتماد المطلوبة:</h4>
                <ol style="margin-inline-start:20px; font-size:13px; color:#4c1d95; line-height:1.8; font-weight:700">
                    <li>تأكد من إدخال كافة البيانات في الأعلى بدقة واضغط (حفظ البيانات).</li>
                    <li>اضغط على زر (طباعة الشهادة) واحصل على توقيع المندوب ومدير القسم ومدير المستشفى والأختام الحية.</li>
                    <li>قم بعمل مسح ضوئي (Scan) للشهادة الموقعة بالكامل.</li>
                    <li>ارفع الملف الممسوح هنا واضغط (اعتماد الشهادة وإقفالها).</li>
                </ol>
            </div>

            <div style="flex:1; min-width:300px;">
                <?php if(!empty($p['signed_attachment'])): ?>
                    <div style="background:#f0fdf4; border:2px solid #22c55e; border-radius:10px; padding:20px; text-align:center;">
                        <i class="fa-solid fa-circle-check" style="font-size:36px; color:#16a34a; margin-bottom:10px;"></i>
                        <div style="font-weight:900; color:#16a34a; font-size:15px; margin-bottom:10px;">تم إرفاق النسخة الموقعة والمختومة بنجاح</div>
                        <a href="<?= BASE_URL ?>/uploads/<?= e($p['signed_attachment']) ?>" target="_blank" class="btn" style="display:inline-block; background:#16a34a; color:#fff; padding:6px 14px; text-decoration:none; border-radius:6px; font-size:12.5px;">
                            <i class="fa-solid fa-file-pdf"></i> عرض الملف المرفق
                        </a>
                    </div>
                    <input type="hidden" id="hasSignedFile" value="1">
                <?php else: ?>
                    <div class="upload-area" onclick="document.getElementById('signedCopy').click()">
                        <input type="file" id="signedCopy" name="signed_copy" accept=".pdf,image/jpeg,image/png,image/jpg" onchange="showFileName(this)">
                        <i class="fa-solid fa-cloud-arrow-up" style="font-size:36px; color:#9333ea; margin-bottom:10px;"></i>
                        <div style="font-weight:800; color:#6b21a8; font-size:14px;">اضغط هنا لاختيار ملف الشهادة الموقعة</div>
                        <div style="font-size:11.5px; color:#94a3b8; margin-top:5px;">الصيغ المقبولة: PDF, JPG, PNG</div>
                        <div id="fileNameDisplay" class="file-name-display"></div>
                    </div>
                    <input type="hidden" id="hasSignedFile" value="0">
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php if(!$is_approved): ?>
<div class="bottom-action-bar">
    <div style="font-size:13.5px;font-weight:600"><i class="fa-solid fa-info-circle"></i> يمكنك حفظ البيانات، ثم طباعتها لتوقيعها، والعودة لاحقاً للرفع والاعتماد.</div>
    <div style="display:flex; gap:10px; align-items:center;">
        
        <button type="button" onclick="doSave('draft')" class="btn" style="background:transparent; color:#93c5fd; font-size:13.5px; font-weight:800; border:1px solid #3b82f6; padding:8px 20px; border-radius:8px; cursor:pointer">
            <i class="fa-solid fa-floppy-disk"></i> حفظ البيانات مؤقتاً
        </button>

        <?php if($id > 0): ?>
        <button type="button" onclick="doSave('print')" class="btn" style="background:#f59e0b; color:#fff; font-size:13.5px; font-weight:800; border:none; padding:8px 20px; border-radius:8px; cursor:pointer; box-shadow:0 2px 6px rgba(245,158,11,0.3)">
            <i class="fa-solid fa-print"></i> حفظ وطباعة الشهادة
        </button>
        <?php endif; ?>

        <div style="width:1px; height:30px; background:rgba(255,255,255,0.2); margin:0 5px;"></div>

        <button type="button" onclick="doSave('submit')" class="btn btn-primary" style="font-size:14px;font-weight:800;box-shadow:0 4px 10px rgba(147,51,234,0.4);padding:8px 24px;background:#9333ea;color:#fff;border:none;border-radius:8px;cursor:pointer">
            <i class="fa-solid fa-lock"></i> اعتماد الشهادة وإقفالها
        </button>
    </div>
</div>
<?php endif; ?>
</form>

</main>
</div>

<script>
const _MFR_DICT = <?= json_encode($mfr_dict, JSON_UNESCAPED_UNICODE) ?>;

window.addEventListener('DOMContentLoaded', () => {
    const genMfrDl = document.getElementById('mfrList_gen');
    if(genMfrDl) {
        Object.keys(_MFR_DICT).forEach(mfr => {
            const opt = document.createElement('option');
            opt.value = mfr; genMfrDl.appendChild(opt);
        });
    }
    updateMatchUI();
    updateHijriAndDay();

    <?php if(isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.open('<?= BASE_URL ?>/commissioning/print.php?id=<?= $id ?>', '_blank');
    <?php endif; ?>
});

function updateGenModelDatalist(mfrName) {
    const dl = document.getElementById('modelList_gen');
    if(!dl) return; 
    dl.innerHTML = ''; 
    const searchKey = (mfrName || '').trim().toLowerCase();
    let models = [];
    for (let key in _MFR_DICT) {
        if (key.trim().toLowerCase() === searchKey) {
            models = _MFR_DICT[key]; break;
        }
    }
    models.forEach(m => { 
        if(m) { const opt = document.createElement('option'); opt.value = m; dl.appendChild(opt); }
    });
}

function updateMatchUI() {
    const checked = document.querySelector('input[name="spec_match"]:checked');
    if(!checked) return;
    const val = checked.value;
    const okLbl = document.getElementById('optMatchOk');
    const noLbl = document.getElementById('optMatchNo');
    
    if (val === "1") {
        okLbl.classList.add('active'); noLbl.classList.remove('active');
    } else {
        noLbl.classList.add('active'); okLbl.classList.remove('active');
    }
}

function updateHijriAndDay() {
    const gregDateStr = document.getElementById('gregDateInp').value;
    if (!gregDateStr) {
        document.getElementById('hijriDateOut').value = '';
        document.getElementById('dayNameOut').value = '';
        return;
    }
    const dateObj = new Date(gregDateStr);
    const daysArr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    document.getElementById('dayNameOut').value = daysArr[dateObj.getDay()];

    try {
        const options = { day: 'numeric', month: 'long', year: 'numeric', calendar: 'islamic-umalqura' };
        const hijriFormatter = new Intl.DateTimeFormat('ar-SA', options);
        document.getElementById('hijriDateOut').value = hijriFormatter.format(dateObj);
    } catch (e) {
        document.getElementById('hijriDateOut').value = 'غير مدعوم';
    }
}

function showFileName(input) {
    const display = document.getElementById('fileNameDisplay');
    if(input.files && input.files[0]) {
        display.innerHTML = '<i class="fa-solid fa-file-check"></i> تم اختيار: ' + input.files[0].name;
    } else {
        display.innerHTML = '';
    }
}

function doSave(action) {
    if(action === 'submit') {
        const fileInput = document.getElementById('signedCopy');
        const hasFile = document.getElementById('hasSignedFile').value === '1';
        
        if (!hasFile && (!fileInput || !fileInput.value)) {
            alert('❌ عذراً، لا يمكن الاعتماد النهائي بدون رفع النسخة الموقعة والمختومة أولاً!');
            return;
        }

        if(!confirm('هل تم استكمال جميع التواقيع والأختام المطلوبة على النسخة الورقية المرفقة؟\n\nبمجرد الضغط على (موافق)، سيتم إقفال الشهادة ولن تتمكن من تعديلها مجدداً.')) return;
    }
    
    document.getElementById('fAction').value = action;
    document.getElementById('ccForm').submit();
}
</script>
</body>
</html>