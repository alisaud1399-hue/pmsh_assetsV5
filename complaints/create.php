<?php
/**
 * complaints/create.php — إصدار بلاغ جديد 
 * واجهة "الشاشة الواحدة" (Single-View Stepper) لمنع التمرير وتوفير تجربة مستخدم عالمية
 * متوافقة مع التحديثات الأمنية والأرقام الحقيقية لقاعدة FDA
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('complaints.create');

$uid = (int) current_user()['id'];
$my_dept_id = (int) (current_user()['department_id'] ?? 0);
$is_dept_user = $my_dept_id > 0;

$depts = $is_dept_user ? [] : $pdo->query("SELECT id, name FROM departments WHERE dept_category='clinical' ORDER BY name")->fetchAll();

$my_assets = [];
if ($my_dept_id) {
    $s = $pdo->prepare("
        SELECT id, description, en_name, manufacturer_name, model_number, asset_type, tag_number,
               asset_number, serial_number,
               date_placed_in_service, status
        FROM assets
        WHERE status = 'active'
          AND (custodian_dept_id = ? OR custodian_user_id = ?)
        ORDER BY description
    ");
    // ملاحظة: custodian_dept_id يُملأ دائماً (حتى مع العهدة الشخصية —
    // انظر apply_custody في custody_transfer.php)، فهذا الشرط يغطي
    // "أي زميل في نفس القسم" بلا حاجة للتحقق من custodian_type إطلاقاً.
    $s->execute([$my_dept_id, $uid]);
    $my_assets = $s->fetchAll();
}

// فلترة ذكية لأزرار نوع الصيانة بناءً على عهد المستخدم:
// - موظف بقسم بدون أجهزة طبية أصلاً → ما يظهر له زر "أجهزة طبية"
// - موظف عنده أجهزة IT بس → ما يظهر له زر "أجهزة طبية"
// - "صيانة عامة" دايماً متاحة (عطل مرفق/مبنى أمر مشترك بين كل الأقسام)
// - مدير بدون قسم → تظهر له كل الأزرار
$available_types = [];
$has_any_assets = count($my_assets) > 0;
if ($is_dept_user) {
    $has_medical = $has_it = false;
    foreach ($my_assets as $a) {
        if ($a['asset_type'] === 'medical') $has_medical = true;
        elseif ($a['asset_type'] === 'it') $has_it = true;
    }
    if ($has_medical) $available_types[] = 'medical';
    if ($has_it)      $available_types[] = 'it';
    $available_types[] = 'general'; // الصيانة العامة دايماً متاحة
} else {
    $available_types = ['medical', 'it', 'general'];
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can('complaints.create', 'create')) {
        $errors[] = 'غير مصرح لك بإنشاء بلاغ.';
    } elseif (!verify_csrf()) {
        $errors[] = 'خطأ في الجلسة (CSRF). يرجى تحديث الصفحة والمحاولة مجدداً.';
    } else {
        $request_type = $_POST['request_type'] ?? '';
        $general_type = $_POST['general_type'] ?: null;
        $asset_id = (int) ($_POST['asset_id'] ?? 0) ?: null;
        $location_description = trim($_POST['location_description'] ?? '');
        $dept_id = $is_dept_user ? $my_dept_id : (int) ($_POST['dept_id'] ?? 0);
        $priority = in_array($_POST['priority'] ?? '', ['normal', 'urgent', 'critical']) ? $_POST['priority'] : 'normal';
        $description = trim($_POST['description'] ?? '');

        $priority_auto_upgraded = false;
        // أمان: نوع البلاغ يُستنتَج من asset_type الحقيقي في قاعدة البيانات لا من قيمة المتصفح،
        // حتى لو حدث أي تلاعب أو خلل بالواجهة، يستحيل توجيه بلاغ لفريق صيانة خاطئ.
        if ($asset_id) {
            $av = $pdo->prepare("SELECT asset_type, status, criticality_class FROM assets WHERE id=?");
            $av->execute([$asset_id]);
            $aRow = $av->fetch();
            if (!$aRow) {
                $errors[] = 'الجهاز المحدَّد غير موجود.';
            } elseif ($aRow['status'] !== 'active') {
                $errors[] = 'هذا الجهاز غير فعّال حالياً (حالته: ' . e($aRow['status']) . ')، لا يمكن رفع بلاغ عليه.';
            } else {
                $request_type = in_array($aRow['asset_type'], ['medical', 'it']) ? $aRow['asset_type'] : 'general';

                // الطبقة الأولى من إعادة التصنيف: شبكة أمان تلقائية بلا أي تدخّل بشري —
                // جهاز الفئة A (الأعلى حساسية) لا يمكن أن يكون بلاغه أقل من "طارئ" إطلاقاً،
                // حتى لو اختار المُبلِّغ نفسه أولوية أقل، لأن خطورة الجهاز هي الحاكمة هنا.
                if ($aRow['criticality_class'] === 'A' && $priority !== 'critical') {
                    $priority = 'critical';
                    $priority_auto_upgraded = true;
                }
            }
        }

        $is_location = ($request_type === 'general' && $general_type === 'location');

        if (!in_array($request_type, ['medical', 'it', 'general'])) $errors[] = 'يجب تحديد نوع البلاغ.';
        if (!$dept_id) $errors[] = 'يجب تحديد القسم.';
        if (!$is_location && !$asset_id) $errors[] = 'يجب اختيار الجهاز المعطل.';
        if ($is_location && !$location_description) $errors[] = 'يجب تحديد الموقع.';
        if (empty($description)) $errors[] = 'يجب كتابة وصف العطل بالتفصيل.';
        if (!$is_location) $location_description = null;

        if ($asset_id && empty($errors)) {
            $dup = $pdo->prepare("SELECT id, request_number FROM complaints WHERE asset_id=? AND status IN ('open','acknowledged','in_progress','stalled','escalated','resolved') LIMIT 1");
            $dup->execute([$asset_id]);
            if ($d = $dup->fetch()) {
                $errors[] = 'يوجد بلاغ مفتوح لهذا الجهاز بالفعل (رقم ' . e($d['request_number']) . ') — راجع "بلاغاتي" لمتابعته بدل تكراره.';
            }
        }

        if (empty($errors)) {
            $yr = date('Y');
            $seq = $pdo->query("SELECT COUNT(*)+1 FROM complaints WHERE YEAR(created_at)=$yr")->fetchColumn();
            $request_number = 'CMP/' . $yr . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $hours = (float) get_setting('escalation_hours_' . $priority, $priority === 'critical' ? 1 : ($priority === 'urgent' ? 2 : 4));
            $escalation_due_at = date('Y-m-d H:i:s', strtotime("+{$hours} hours"));

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO complaints
                        (request_number, request_type, general_type, asset_id, location_description,
                         dept_id, priority, description, status, requested_by, escalation_due_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)
                ");
                $stmt->execute([
                    $request_number, $request_type, $general_type, $asset_id, $location_description,
                    $dept_id, $priority, $description, $uid, $escalation_due_at
                ]);
                $complaint_id = (int) $pdo->lastInsertId();

                if (!empty($_FILES['attachments']['name'][0])) {
                    $upload_dir = BASE_PATH . '/uploads/complaints/' . $complaint_id . '/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    $att_stmt = $pdo->prepare("INSERT INTO complaint_attachments (complaint_id, file_name, file_path, file_size, file_type, uploaded_by) VALUES (?,?,?,?,?,?)");
                    foreach ($_FILES['attachments']['name'] as $i => $fname) {
                        if ($i >= 5) break;
                        if (!$fname || $_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                        $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'])) continue;
                        $safe = 'att_' . time() . '_' . $i . '_' . rand(100, 999) . '.' . $ext;
                        if (move_uploaded_file($_FILES['attachments']['tmp_name'][$i], $upload_dir . $safe)) {
                            $att_stmt->execute([$complaint_id, $fname, 'complaints/' . $complaint_id . '/' . $safe, $_FILES['attachments']['size'][$i], $_FILES['attachments']['type'][$i], $uid]);
                        }
                    }
                }

                $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'created','تم تقديم البلاغ',NULL,'open',?)")
                    ->execute([$complaint_id, $uid]);

                if ($priority_auto_upgraded) {
                    $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'priority_auto_upgraded','رُفعت الأولوية تلقائياً إلى طارئ — الجهاز من الفئة الحرجة A','open','open',?)")
                        ->execute([$complaint_id, $uid]);
                }

                if ($asset_id && !empty($_POST['selected_fault_en'])) {
                    // Upsert: نزيد usage_count بدل إدخال صف مكرر لكل بلاغ جديد بنفس العطل
                    // (الفهرس الفريد uniq_asset_fault_en يحرس سلامة البيانات هنا)
                    $pdo->prepare("
                        INSERT INTO asset_fault_suggestions (asset_id, fault_text, fault_text_en, source, usage_count)
                        VALUES (?, ?, ?, 'history', 1)
                        ON DUPLICATE KEY UPDATE
                            fault_text  = VALUES(fault_text),
                            usage_count = usage_count + 1
                    ")->execute([
                        $asset_id,
                        mb_substr($description, 0, 200),
                        trim($_POST['selected_fault_en']),
                    ]);
                }

                // جلب فريق الصيانة المختص (بناءً على الفئة)
$team_col = 'maintenance_' . $request_type;
$team_users = $pdo->prepare("SELECT u.id FROM users u JOIN departments d ON d.id=u.department_id WHERE d.dept_category=? AND u.is_active=1");
$team_users->execute([$team_col]);

$ins_n = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id) VALUES (?, ?, ?, ?, ?, 'complaint', ?)");
foreach ($team_users->fetchAll(PDO::FETCH_COLUMN) as $team_uid) {
    if ($team_uid == $uid) continue;
    $ins_n->execute([
        $team_uid, 
        'info', 
        '🚨 بلاغ صيانة جديد', 
        'تم رفع بلاغ جديد رقم ' . $request_number . ' الأولوية: ' . (['normal'=>'عادي','urgent'=>'عاجل','critical'=>'طوارئ'][$priority] ?? 'عادي'), 
        BASE_URL . '/complaints/view.php?id=' . $complaint_id,
        $complaint_id
    ]);
}

                $pdo->commit();
                $successMsg = 'تم إرسال البلاغ بنجاح، رقمه: ' . $request_number;
                if ($priority_auto_upgraded) {
                    $successMsg .= ' (تم رفع الأولوية تلقائياً إلى "طارئ" لأن الجهاز من فئة الحساسية A)';
                }

                // Hook: recompute risk score for this asset (new complaint = new breakdown)
                if ($asset_id) {
                    @require_once BASE_PATH . '/includes/risk_helpers.php';
                    if (function_exists('compute_risk_score')) {
                        compute_risk_score($pdo, (int)$asset_id, true);
                    }
                }
                flash('success', $successMsg);
                header('Location: ' . BASE_URL . '/complaints/my.php?id=' . $complaint_id);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('complaints/create.php: ' . $e->getMessage());
                $errors[] = 'حدث خطأ غير متوقع أثناء حفظ البلاغ. حاول مجدداً.';
            }
        }
    }
}

$page_title = 'إصدار بلاغ جديد';
$active_nav = 'complaints.index';
$csrf = csrf_input();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
/* 🎨 Zero-Scroll Premium UI */
:root {
    --bg-main: #f1f5f9;
    --primary: #2563eb;
    --primary-glow: rgba(37, 99, 235, 0.15);
    --success: #10b981;
    --text-main: #0f172a;
    --text-muted: #64748b;
    --border: #e2e8f0;
}
* { font-family: 'Tajawal', sans-serif; box-sizing: border-box; }
.eng-num { font-family: 'Inter', sans-serif; }
.main-area { background-color: var(--bg-main); height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
.page-content { flex: 1; display: flex; align-items: center; justify-content: center; padding: 20px; overflow: hidden; }

/* 🌟 Master Card Container */
.master-card { width: 100%; max-width: 800px; background: #fff; border-radius: 24px; box-shadow: 0 20px 40px rgba(0,0,0,0.06); border: 1px solid #fff; display: flex; flex-direction: column; max-height: 90vh; overflow: hidden; position: relative; }

/* 🔴 الهيدر والشريط العلوي */
.mc-header { padding: 24px 30px; border-bottom: 1px solid var(--border); background: #fff; z-index: 10; }
.mc-title { font-size: 20px; font-weight: 900; color: var(--text-main); margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.mc-title i { color: var(--primary); }

.stepper { display: flex; align-items: center; justify-content: space-between; position: relative; }
.stepper::before { content: ''; position: absolute; top: 16px; left: 30px; right: 30px; height: 3px; background: var(--border); z-index: 1; border-radius: 2px; }
.step-item { position: relative; z-index: 2; display: flex; flex-direction: column; align-items: center; gap: 8px; width: 60px; }
.step-circle { width: 34px; height: 34px; border-radius: 50%; background: #fff; border: 2px solid var(--border); color: #94a3b8; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 14px; transition: 0.3s; }
.step-label { font-size: 11.5px; font-weight: 800; color: #94a3b8; transition: 0.3s; white-space: nowrap; }

.step-item.active .step-circle { border-color: var(--primary); background: var(--primary-glow); color: var(--primary); box-shadow: 0 0 0 4px #fff; }
.step-item.active .step-label { color: var(--primary); }
.step-item.done .step-circle { background: var(--success); border-color: var(--success); color: #fff; }

/* 🔵 منطقة المحتوى (متغيرة) */
.mc-body { padding: 30px; flex: 1; overflow-y: auto; background: #fafcff; position: relative; min-height: 380px; }
.mc-body::-webkit-scrollbar { width: 6px; }
.mc-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

.step-pane { display: none; animation: fadeInRight 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
.step-pane.active { display: block; }
@keyframes fadeInRight { from { opacity: 0; transform: translateX(15px); } to { opacity: 1; transform: translateX(0); } }

/* 🟢 أزرار التحكم السفلية */
.mc-footer { padding: 20px 30px; background: #fff; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; z-index: 10; }
.btn-nav { padding: 12px 24px; border-radius: 12px; font-size: 14px; font-weight: 900; cursor: pointer; border: none; transition: 0.3s; display: inline-flex; align-items: center; gap: 8px; }
.btn-back { background: #f1f5f9; color: #475569; }
.btn-back:hover { background: #e2e8f0; }
.btn-next { background: linear-gradient(135deg, var(--primary), #1d4ed8); color: #fff; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2); }
.btn-next:disabled { background: #cbd5e1; box-shadow: none; cursor: not-allowed; opacity: 0.7; }
.btn-submit { background: linear-gradient(135deg, var(--success), #059669); color: #fff; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25); display: none; }

/* 🎨 التنسيقات الداخلية للخطوات */
.pr-sel { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.pr-opt { border-radius: 16px; border: 2px solid var(--border); background: #fff; cursor: pointer; transition: 0.3s; overflow: hidden; text-align: center; }
.pr-opt input { display: none; }
.pr-opt .pr-top { padding: 12px; font-size: 13px; font-weight: 900; color: #fff; }
.pr-opt .pr-body { padding: 20px 10px; }
.pr-opt .pr-lbl { font-size: 15px; font-weight: 900; margin-bottom: 6px; color: var(--text-main); }
.pr-opt .pr-sub { font-size: 11px; color: var(--text-muted); font-weight: 700; }
.pr-opt[data-p="normal"] .pr-top { background: #94a3b8; }
.pr-opt[data-p="urgent"] .pr-top { background: #f59e0b; }
.pr-opt[data-p="critical"] .pr-top { background: #ef4444; }
.pr-opt.active { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,0.08); border-width: 2px; }
.pr-opt[data-p="normal"].active { border-color: var(--success); }
.pr-opt[data-p="normal"].active .pr-top { background: var(--success); }
.pr-opt[data-p="urgent"].active { border-color: #f59e0b; }
.pr-opt[data-p="critical"].active { border-color: #ef4444; }

.gen-label { font-size: 13px; font-weight: 900; color: #334155; margin-bottom: 8px; display: block; }
.rfi { height: 48px; padding: 0 16px; border: 2px solid var(--border); border-radius: 12px; font-family: 'Tajawal'; font-size: 14px; font-weight: 700; width: 100%; color: var(--text-main); background: #fff; transition: 0.3s; }
.rfi:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 4px var(--primary-glow); }
textarea.rfi { height: auto; padding: 16px; resize: vertical; }
.req { color: #ef4444; margin-right: 2px; }

.wz-type-btn { flex: 1; padding: 16px; border-radius: 14px; border: 2px solid var(--border); background: #fff; cursor: pointer; font-size: 13.5px; font-weight: 900; color: var(--text-muted); transition: 0.3s; display: flex; flex-direction: column; align-items: center; gap: 8px; }
.wz-type-btn i { font-size: 24px; }
.wz-type-btn.wz-sel { border-color: transparent; color: #fff; background: var(--primary); box-shadow: 0 8px 16px var(--primary-glow); transform: translateY(-2px); }

/* AI & FDA */
.fault-box { border: 1px solid #e9d5ff; border-radius: 14px; overflow: hidden; background: #faf5ff; margin-bottom: 16px; }
.fault-head { background: #8b5cf6; padding: 12px 16px; color: #fff; font-size: 13px; font-weight: 900; display: flex; align-items: center; gap: 8px; }
.fault-chips { display: flex; flex-wrap: wrap; gap: 8px; padding: 16px; }
.fault-chip { background: #fff; border: 1px solid #ddd6fe; color: #6d28d9; font-size: 12px; font-weight: 800; padding: 6px 14px; border-radius: 99px; cursor: pointer; transition: 0.2s; }
.fault-chip.picked { background: #8b5cf6; color: #fff; border-color: #8b5cf6; }

.fda-kpi-wrap { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 12px; }
.fda-kpi { border-radius: 12px; padding: 12px; text-align: center; border: 1px solid; }

.upload-area { border: 2px dashed #cbd5e1; border-radius: 14px; padding: 24px; text-align: center; cursor: pointer; background: #fff; transition: 0.3s; }
.upload-area:hover { border-color: var(--primary); background: var(--primary-glow); }
.file-chip { display: inline-flex; align-items: center; gap: 6px; background: #fff; border: 1px solid #bfdbfe; color: var(--primary); font-size: 12px; font-weight: 800; padding: 4px 12px; border-radius: 8px; margin: 8px 8px 0 0; }

.dev-warn { background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 14px; margin-bottom: 16px; display: flex; gap: 10px; }

.asset-info-card { background: var(--primary-glow); border: 1px solid #bfdbfe; border-radius: 12px; padding: 14px 16px; margin-top: 12px; display: flex; flex-direction: column; gap: 8px; }
.apk { border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #fff; }
.apk-top { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid #f1f5f9; background: #f8fafc; }
.apk-search { flex: 1; height: 34px; padding: 0 12px; border: 1.5px solid #e2e8f0; border-radius: 9px; font-family: 'Tajawal', sans-serif; font-size: 12.5px; outline: none; background: #fff; }
.apk-search:focus { border-color: #1565C0; }
.apk-count { font-size: 11.5px; font-weight: 800; color: #1565C0; background: var(--primary-glow); border-radius: 50px; padding: 4px 12px; white-space: nowrap; }
/* ارتفاع منطقة التمرير — عدّل القيمة التالية لتكبير/تصغير القائمة */
.apk-scroll { max-height: 120px; overflow-y: auto; }
.apk-tbl { width: 100%; border-collapse: collapse; }
.apk-tbl thead th { position: sticky; top: 0; z-index: 2; background: #eef2f7; color: #475569; font-size: 10.5px; font-weight: 800; padding: 6px 10px; text-align: inherit; white-space: nowrap; border-bottom: 1px solid #e2e8f0; }
.apk-tbl td { padding: 6px 10px; font-size: 11.5px; border-bottom: 1px solid #f8fafc; white-space: nowrap; color: #334155; }
.apk-row { cursor: pointer; transition: background .12s; }
.apk-row:hover td { background: #f1f5f9; }
.apk-row.apk-on td { background: var(--primary-glow); font-weight: 700; color: #0d47a1; }
.apk-desc { font-weight: 700; color: #0f172a; max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
.apk-empty { padding: 16px; text-align: center; color: #94a3b8; font-size: 12px; }
.aic-row { display: flex; align-items: center; gap: 10px; font-size: 12.5px; }
.aic-row i { color: var(--primary); width: 16px; text-align: center; }
.aic-lbl { color: #64748b; font-weight: 800; min-width: 90px; }
.aic-val { color: var(--text-main); font-weight: 900; flex: 1; }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">

<?php if ($errors): ?>
<div id="errModal" style="position:fixed;inset:0;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);display:flex;align-items:center;justify-content:center;z-index:2500;padding:20px;">
  <div style="background:#fff;border-radius:24px;max-width:440px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,0.2);overflow:hidden;animation:fadeInDown 0.4s ease;">
    <div style="background:linear-gradient(135deg,#dc2626,#991b1b);padding:24px;text-align:center;color:#fff;">
      <i class="fa-solid fa-triangle-exclamation" style="font-size:42px;margin-bottom:12px;"></i>
      <div style="font-size:18px;font-weight:900;">يرجى تصحيح الأخطاء التالية</div>
    </div>
    <div style="padding:24px;">
      <ul style="margin:0;padding:0;display:flex;flex-direction:column;gap:12px;list-style:none;">
        <?php foreach ($errors as $er): ?>
        <li style="display:flex;gap:10px;font-size:14px;font-weight:800;color:#334155;background:#fef2f2;padding:12px;border-radius:12px;"><i class="fa-solid fa-circle-exclamation" style="color:#dc2626;margin-top:2px;"></i><span><?= e($er) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div style="padding:10px 24px 24px;">
      <button type="button" onclick="document.getElementById('errModal').remove()" style="width:100%;background:#0f172a;color:#fff;border:none;padding:14px;border-radius:12px;font-weight:900;font-size:15px;cursor:pointer;transition:0.2s;">إغلاق والمحاولة مجدداً</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="master-card">
    <div class="mc-header">
        <div class="mc-title"><i class="fa-solid fa-file-signature"></i> إصدار بلاغ صيانة جديد</div>
        <div class="stepper">
            <div class="step-item active" id="si-1"><div class="step-circle">1</div><div class="step-label">الأولوية</div></div>
            <div class="step-item" id="si-2"><div class="step-circle">2</div><div class="step-label">الجهاز</div></div>
            <div class="step-item" id="si-3"><div class="step-circle"><i class="fa-solid fa-robot"></i></div><div class="step-label">التشخيص</div></div>
            <div class="step-item" id="si-4"><div class="step-circle"><i class="fa-solid fa-paper-plane"></i></div><div class="step-label">الإرسال</div></div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="wzForm" style="display:flex; flex-direction:column; flex:1; overflow:hidden;">
    <?= $csrf ?>
    <input type="hidden" name="priority" id="hzPr" value="">
    <input type="hidden" name="request_type" id="hzRt" value="">
    <input type="hidden" name="general_type" id="hzGt" value="">
    <input type="hidden" name="asset_id" id="hzAs" value="">
    <input type="hidden" name="dept_id" id="hzDp" value="<?= $is_dept_user ? $my_dept_id : '' ?>">
    <input type="hidden" name="selected_fault_en" id="hzFaultEn" value="">

    <div class="mc-body">
        
        <div class="step-pane active" id="pane-1">
            <div style="font-size:16px; font-weight:900; margin-bottom:20px; color:var(--text-main);">حدد درجة البلاغ:</div>
            <div class="pr-sel">
              <label class="pr-opt" data-p="normal" onclick="wzPriority('normal')">
                <input type="radio" name="_pr_vis">
                <div class="pr-top"><i class="fa-solid fa-circle-check"></i> عادي</div>
                <div class="pr-body"><div class="pr-lbl">صيانة دورية</div><div class="pr-sub">تصعيد بعد <?= e(get_setting('escalation_hours_normal', 4)) ?> س</div></div>
              </label>
              <label class="pr-opt" data-p="urgent" onclick="wzPriority('urgent')">
                <input type="radio" name="_pr_vis">
                <div class="pr-top"><i class="fa-solid fa-triangle-exclamation"></i> عاجل</div>
                <div class="pr-body"><div class="pr-lbl">مؤثر جزئياً</div><div class="pr-sub">تصعيد بعد <?= e(get_setting('escalation_hours_urgent', 2)) ?> س</div></div>
              </label>
              <label class="pr-opt" data-p="critical" onclick="wzPriority('critical')">
                <input type="radio" name="_pr_vis">
                <div class="pr-top"><span class="dot"></span> طوارئ</div>
                <div class="pr-body"><div class="pr-lbl">تعطل كامل</div><div class="pr-sub">تصعيد بعد <?= e(get_setting('escalation_hours_critical', 1)) ?> س</div></div>
              </label>
            </div>
        </div>

        <div class="step-pane" id="pane-2">
            <div id="deviceWarn" style="display:none" class="dev-warn">
              <i class="fa-solid fa-ban" style="color:#dc2626; font-size:20px;"></i>
              <div>
                <div style="font-size:14px; font-weight:900; color:#991b1b; margin-bottom:4px;">لا يمكن رفع البلاغ!</div>
                <div id="dwText" style="font-size:12.5px; color:#b91c1c; font-weight:700;"></div>
              </div>
            </div>

            <label class="gen-label">إلى أي قسم صيانة تريد توجيه البلاغ؟ <span class="req">*</span></label>
            <div style="display:flex; gap:10px; margin-bottom:20px">
              <?php
              $type_btns = [
                'medical' => ['icon' => 'fa-stethoscope',         'label' => 'أجهزة طبية'],
                'it'      => ['icon' => 'fa-laptop-code',          'label' => 'تقنية معلومات'],
                'general' => ['icon' => 'fa-screwdriver-wrench',   'label' => 'صيانة عامة'],
              ];
              foreach ($type_btns as $tk => $ti):
                  if (!in_array($tk, $available_types, true)) continue; // فلترة ذكية: نخفي الزر اللي ما عند المستخدم عهد فيه
              ?>
                <button type="button" class="wz-type-btn" data-t="<?= e($tk) ?>" onclick="wzType(this,'<?= e($tk) ?>')">
                  <i class="fa-solid <?= e($ti['icon']) ?>"></i><?= e($ti['label']) ?>
                </button>
              <?php endforeach; ?>
            </div>
            <?php if ($is_dept_user && count($available_types) === 1 && $available_types[0] === 'general'): ?>
              <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;padding:12px 14px;font-size:12.5px;color:#1e40af;font-weight:700;margin-bottom:16px;">
                <i class="fa-solid fa-circle-info"></i>
                لا توجد أجهزة طبية أو تقنية معلومات في عهدتك، لذا تظهر لك خيار الصيانة العامة فقط (للإبلاغ عن عطل في مرفق أو مبنى).
              </div>
            <?php endif; ?>

            <div id="wzGtypeBox" style="display:none; margin-bottom:20px">
              <label class="gen-label">نوع الصيانة العامة <span class="req">*</span></label>
              <select class="rfi" onchange="wzGType(this.value)">
                <option value="">-- يرجى الاختيار --</option>
                <option value="asset">عطل في أصل / جهاز</option>
                <option value="location">عطل في مرفق / مبنى</option>
              </select>
            </div>

            <div id="wzAssetSec" style="display:none">
              <label class="gen-label">الجهاز المعطل <?= $is_dept_user ? '(في عهدتك)' : '' ?> <span class="req">*</span></label>
              <div class="apk">
                <div class="apk-top">
                  <input type="text" class="apk-search" id="apkSearch"
                         placeholder="بحث: اسم الجهاز / التاج / السيريال / الموديل..."
                         oninput="filterAssetList()" autocomplete="off">
                  <span class="apk-count"><b id="apkCount" class="eng-num">0</b> جهاز</span>
                </div>
                <div class="apk-scroll">
                  <table class="apk-tbl">
                    <thead><tr>
                      <th>الجهاز</th><th>رقم الأصل</th><th>التاج</th>
                      <th>الشركة</th><th>الموديل</th><th>السيريال</th>
                    </tr></thead>
                    <tbody id="apkBody">
                    <?php foreach ($my_assets as $a):
                        $norm_type = in_array($a['asset_type'], ['medical', 'it']) ? $a['asset_type'] : 'general';
                        $srch = mb_strtolower(implode(' ', array_filter([
                            $a['description'], $a['en_name'], $a['asset_number'],
                            $a['tag_number'], $a['serial_number'],
                            $a['manufacturer_name'], $a['model_number'],
                        ])));
                    ?>
                    <tr class="apk-row" data-id="<?= $a['id'] ?>" data-type="<?= e($norm_type) ?>"
                        data-tag="<?= e($a['tag_number'] ?? '') ?>"
                        data-mfr="<?= e($a['manufacturer_name'] ?? '') ?>"
                        data-model="<?= e($a['model_number'] ?? '') ?>"
                        data-service="<?= e($a['date_placed_in_service'] ?? '') ?>"
                        data-search="<?= e($srch) ?>"
                        onclick="apkPick(this)">
                      <td class="apk-desc"><?= e($a['description']) ?></td>
                      <td class="eng-num"><?= e($a['asset_number'] ?: '—') ?></td>
                      <td class="eng-num"><?= e($a['tag_number'] ?: '—') ?></td>
                      <td><?= e($a['manufacturer_name'] ?: '—') ?></td>
                      <td class="eng-num"><?= e($a['model_number'] ?: '—') ?></td>
                      <td class="eng-num"><?= e($a['serial_number'] ?: '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                  <div id="apkEmpty" class="apk-empty" style="display:none">
                    لا توجد أجهزة مطابقة لبحثك في هذا النوع</div>
                </div>
              </div>
              <div id="assetInfoCard" style="display:none" class="asset-info-card">
                <div class="aic-row"><i class="fa-solid fa-tag"></i><span class="aic-lbl">التاج نمبر</span><span class="aic-val eng-num" id="aicTag">—</span></div>
                <div class="aic-row"><i class="fa-solid fa-industry"></i><span class="aic-lbl">الشركة والموديل</span><span class="aic-val" id="aicMfr">—</span></div>
                <div class="aic-row"><i class="fa-solid fa-calendar-check"></i><span class="aic-lbl">بداية الخدمة</span><span class="aic-val eng-num" id="aicService">—</span></div>
                <div class="aic-row"><i class="fa-solid fa-hourglass-half"></i><span class="aic-lbl">عمر الجهاز</span><span class="aic-val eng-num" id="aicAge">—</span></div>
              </div>
            </div>

            <div id="wzLocSec" style="display:none">
              <label class="gen-label">وصف الموقع <span class="req">*</span></label>
              <input type="text" class="rfi" id="wzLocInp" name="location_description" placeholder="مثال: الطابق الثاني - ممر الأشعة" oninput="validateStep2()">
            </div>

            <?php if (!$is_dept_user): ?>
            <div style="margin-top:20px">
              <label class="gen-label">القسم التابع له البلاغ <span class="req">*</span></label>
              <select class="rfi" id="deptSel" onchange="document.getElementById('hzDp').value=this.value; validateStep2();">
                <option value="">-- اختر القسم --</option>
                <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
        </div>

        <div class="step-pane" id="pane-3">
            <div class="fault-box" id="faultBox" style="display:none">
              <div class="fault-head"><i class="fa-solid fa-wand-magic-sparkles"></i> التشخيص الذكي للأعطال الشائعة</div>
              <div class="fault-chips" id="faultChips"></div>
            </div>

            <div id="fdaBox" style="display:none; border: 1px solid #bae6fd; border-radius: 14px; overflow: hidden; background: #fff;">
              <div style="background: #0ea5e9; padding: 12px 16px; color: #fff; font-size: 13px; font-weight: 900;"><i class="fa-solid fa-shield-halved"></i>سجل سلامة الجهاز وفق تقارير هيئة الغذاء والدواء الأمريكية (FDA MAUDE)</div>
              <div style="padding:16px;">
                <div class="fda-kpi-wrap" id="fdaKpis"></div>
              </div>
            </div>

            <div id="faultLoading" style="text-align:center; padding:40px 0; color:#94a3b8; font-weight:800; font-size:14px;"><i class="fa-solid fa-circle-notch fa-spin" style="font-size:28px; color:var(--primary); margin-bottom:12px; display:block;"></i> جاري فحص السجلات العالمية...</div>
            <div id="faultNone" style="display:none; text-align:center; padding:30px; background:#fff; border-radius:14px; border:1px dashed #cbd5e1; color:#64748b; font-weight:800; font-size:13px;">لا توجد اقتراحات. يمكنك وصف العطل برمجتك في الخطوة التالية.</div>
        </div>

        <div class="step-pane" id="pane-4">
            <label class="gen-label">وصف العطل بالتفصيل <span class="req">*</span></label>
            <textarea class="rfi" name="description" id="reqDesc" rows="4" placeholder="اشرح المشكلة بالتفصيل لمساعدة المهندس في الاستعداد..." required oninput="validateStep4()"></textarea>
            
            <div style="margin-top:20px">
              <label class="gen-label">المرفقات (اختياري)</label>
              <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                <input type="file" id="fileInput" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="showFiles(this.files)" onclick="event.stopPropagation()">
                <i class="fa-solid fa-cloud-arrow-up" style="font-size:28px;color:#94a3b8;margin-bottom:8px;display:block;"></i>
                <div style="font-size:13px;font-weight:900;color:#334155;">اضغط لرفع الصور أو الملفات</div>
              </div>
              <div id="filePreview"></div>
            </div>
        </div>

    </div>

    <div class="mc-footer">
        <button type="button" class="btn-nav btn-back" id="btnBack" onclick="navStep(-1)" style="visibility:hidden;"><i class="fa-solid fa-arrow-right"></i> رجوع</button>
        <button type="button" class="btn-nav btn-next" id="btnNext" onclick="navStep(1)" disabled>التالي <i class="fa-solid fa-arrow-left"></i></button>
        <button type="submit" class="btn-nav btn-submit" id="btnSubmit"><i class="fa-solid fa-paper-plane"></i> إرسال البلاغ</button>
    </div>
    
    </form>
</div>

</main>
</div>

<script>
const BASE = '<?= BASE_URL ?>';
const HAS_ANY_ASSETS = <?= $has_any_assets ? 'true' : 'false' ?>; // فلترة ذكية: الموظف اللي ما عنده أي عهد أصلاً ما يحتاج خطوة "عطل في أصل"
let currentStep = 1;
const totalSteps = 4;
let WZ = { priority: '', type: '', gtype: '', assetId: '', lastAutoFilledText: '' };

function updateUI() {
    // إخفاء/إظهار الخطوات
    for(let i=1; i<=totalSteps; i++){
        document.getElementById('pane-'+i).classList.remove('active');
        let si = document.getElementById('si-'+i);
        if(i < currentStep) { si.classList.add('done'); si.classList.remove('active'); si.querySelector('.step-circle').innerHTML = '<i class="fa-solid fa-check"></i>'; }
        else if(i === currentStep) { si.classList.add('active'); si.classList.remove('done'); }
        else { si.classList.remove('active', 'done'); }
    }
    document.getElementById('pane-'+currentStep).classList.add('active');

    // تحديث أزرار التنقل
    document.getElementById('btnBack').style.visibility = (currentStep === 1) ? 'hidden' : 'visible';
    
    if(currentStep === totalSteps) {
        document.getElementById('btnNext').style.display = 'none';
        document.getElementById('btnSubmit').style.display = 'inline-flex';
    } else {
        document.getElementById('btnNext').style.display = 'inline-flex';
        document.getElementById('btnSubmit').style.display = 'none';
    }
    
    validateCurrentStep();
}

function navStep(dir) {
    if(dir === 1 && currentStep === 2) { loadFaultSuggestions(); }
    currentStep += dir;
    updateUI();
}

function validateCurrentStep() {
    let btnNext = document.getElementById('btnNext');
    let btnSub = document.getElementById('btnSubmit');
    
    if(currentStep === 1) {
        btnNext.disabled = !WZ.priority;
    } else if(currentStep === 2) {
        btnNext.disabled = true;
        validateStep2();
    } else if(currentStep === 3) {
        btnNext.disabled = false; // دائماً مسموح يتجاوز التشخيص
    } else if(currentStep === 4) {
        validateStep4();
    }
}

function wzPriority(p) {
    WZ.priority = p;
    document.getElementById('hzPr').value = p;
    document.querySelectorAll('.pr-opt').forEach(el => el.classList.toggle('active', el.dataset.p === p));
    setTimeout(() => { if(currentStep===1) navStep(1); }, 300); // انتقال تلقائي ذكي وسلس
}


function resetStaleDescState() {
    // يمسح الوصف فقط إن لم يُعدّله المستخدم بنفسه عن النص الذي عبّأته الشريحة تلقائياً
    let desc = document.getElementById('reqDesc');
    if (desc && desc.value === WZ.lastAutoFilledText) {
        desc.value = '';
        document.getElementById('hzFaultEn').value = '';
    }
    WZ.lastAutoFilledText = '';
    document.querySelectorAll('.fault-chip.picked').forEach(c => c.classList.remove('picked'));
}

function wzType(btn, t) {
    resetStaleDescState();
    WZ.type = t;
    document.getElementById('hzRt').value = t;
    document.querySelectorAll('.wz-type-btn').forEach(b => b.classList.remove('wz-sel'));
    btn.classList.add('wz-sel');
    
    if (t === 'general') {
        document.getElementById('wzGtypeBox').style.display = 'block';
        document.getElementById('wzAssetSec').style.display = 'none';
        document.getElementById('wzLocSec').style.display = 'none';
        // فلترة ذكية: موظف بدون أي عهد → ما يظهر له اختيار "عطل في أصل"،
        // ندخله مباشرة على موقع المرفق (الحالة الوحيدة المتاحة له)
        if (!HAS_ANY_ASSETS) {
            wzGType('location');
        }
    } else {
        document.getElementById('wzGtypeBox').style.display = 'none';
        document.getElementById('wzAssetSec').style.display = 'block';
        document.getElementById('wzLocSec').style.display = 'none';
        
        document.getElementById('apkSearch').value = '';
        apkClearPick();
        filterAssetList();
    }
    validateStep2();
}

function wzGType(g) {
    WZ.gtype = g;
    document.getElementById('hzGt').value = g;
    if (g === 'location') {
        resetStaleDescState();
        document.getElementById('wzAssetSec').style.display = 'none';
        document.getElementById('wzLocSec').style.display = 'block';
    } else if (g === 'asset') {
        resetStaleDescState();
        document.getElementById('wzAssetSec').style.display = 'block';
        document.getElementById('wzLocSec').style.display = 'none';
        document.getElementById('apkSearch').value = '';
        apkClearPick();
        filterAssetList();
    } else {
        document.getElementById('wzAssetSec').style.display = 'none';
        document.getElementById('wzLocSec').style.display = 'none';
    }
    validateStep2();
}

/* ── قائمة الأجهزة الجدولية: فلترة (نوع + بحث) واختيار ── */
function filterAssetList() {
    const q = document.getElementById('apkSearch').value.trim().toLowerCase();
    let n = 0;
    document.querySelectorAll('.apk-row').forEach(tr => {
        const okType = !WZ.type || tr.dataset.type === WZ.type;
        const okText = !q || (tr.dataset.search || '').includes(q);
        const show = okType && okText;
        tr.style.display = show ? '' : 'none';
        if (show) n++;
    });
    document.getElementById('apkCount').textContent = n;
    document.getElementById('apkEmpty').style.display = n ? 'none' : '';
}
function apkClearPick() {
    document.querySelectorAll('.apk-row.apk-on').forEach(r => r.classList.remove('apk-on'));
    WZ.assetId = ''; document.getElementById('hzAs').value = '';
    document.getElementById('assetInfoCard').style.display = 'none';
    document.getElementById('deviceWarn').style.display = 'none';
}
document.addEventListener('DOMContentLoaded', filterAssetList);

function apkPick(tr) {
    document.querySelectorAll('.apk-row.apk-on').forEach(r => r.classList.remove('apk-on'));
    tr.classList.add('apk-on');
    wzDevice(tr.dataset.id);
}

async function wzDevice(assetId) {
    if (assetId !== WZ.assetId) resetStaleDescState();
    WZ.assetId = assetId;
    document.getElementById('hzAs').value = assetId;

    const card = document.getElementById('assetInfoCard');
    if (!assetId) { card.style.display = 'none'; validateStep2(); return; }
    const opt = document.querySelector('.apk-row[data-id="' + assetId + '"]');
    if (opt) {
        document.getElementById('aicTag').textContent = opt.dataset.tag || '—';
        let mfrModel = [opt.dataset.mfr, opt.dataset.model].filter(Boolean).join(' / ');
        document.getElementById('aicMfr').textContent = mfrModel || '—';
        if (opt.dataset.service) {
            const svc = new Date(opt.dataset.service);
            document.getElementById('aicService').textContent = svc.toLocaleDateString('en-GB');
            const years = ((new Date() - svc) / (1000 * 60 * 60 * 24 * 365.25));
            document.getElementById('aicAge').textContent = years < 1
                ? Math.round(years * 12) + ' شهر'
                : years.toFixed(1) + ' سنة';
        } else {
            document.getElementById('aicService').textContent = '—';
            document.getElementById('aicAge').textContent = '—';
        }
        card.style.display = 'flex';
    }
    validateStep2();
    if(!assetId) return;
    
    // فحص البلاغات المكررة بصمت
    try {
        let fd = new FormData(); fd.append('asset_id', assetId);
        let r = await fetch(BASE + '/api/complaint_duplicate_check.php', { method: 'POST', body: fd });
        let d = await r.json();
        if(d.asset_open) {
            let created = d.created_at ? new Date(d.created_at.replace(' ','T')).toLocaleString('ar-SA',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'}) : '';
            document.getElementById('dwText').innerHTML = `
                <div style="display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:6px;">
                    <span><b>رقم البلاغ:</b> <span class="eng-num">${d.request_number}</span></span>
                    <span><b>الحالة:</b> ${d.status_label || ''}</span>
                    ${created ? '<span><b>تاريخه:</b> <span class="eng-num">'+created+'</span></span>' : ''}
                </div>
                <a href="${BASE}/${d.link}" target="_blank" style="display:inline-block;margin-top:10px;background:#dc2626;color:#fff;padding:7px 16px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:800;">
                    <i class="fa-solid fa-eye"></i> عرض هذا البلاغ
                </a>`;
            document.getElementById('deviceWarn').style.display = 'flex';
            document.getElementById('btnNext').disabled = true;
        } else {
            document.getElementById('deviceWarn').style.display = 'none';
        }
    } catch(e){}
}

function validateStep2() {
    let isValid = false;
    let needsDept = <?= $is_dept_user ? 'false' : 'true' ?>;
    let hasDept = needsDept ? document.getElementById('deptSel').value !== '' : true;

    if(WZ.type === 'general' && WZ.gtype === 'location') {
        isValid = document.getElementById('wzLocInp').value.trim().length > 2;
    } else if(WZ.type && WZ.assetId) {
        isValid = true;
    }
    document.getElementById('btnNext').disabled = !(isValid && hasDept) || (document.getElementById('deviceWarn').style.display === 'flex');
}

function validateStep4() {
    let btnSub = document.getElementById('btnSubmit');
    btnSub.disabled = document.getElementById('reqDesc').value.trim().length < 5;
}

async function loadFaultSuggestions() {
    if (!WZ.assetId) { document.getElementById('faultNone').style.display = 'block'; document.getElementById('faultLoading').style.display = 'none'; return; }
    
    document.getElementById('faultLoading').style.display = 'block';
    document.getElementById('faultBox').style.display = 'none';
    document.getElementById('fdaBox').style.display = 'none';
    document.getElementById('faultNone').style.display = 'none';

    try {
        let fd = new FormData(); fd.append('asset_id', WZ.assetId);
        let r = await fetch(BASE + '/api/complaint_fault_suggestions.php', { method: 'POST', body: fd });
        let d = await r.json();
        
        document.getElementById('faultLoading').style.display = 'none';
        let hasData = false;

        // الذكاء الاصطناعي
        if (d.ai && d.ai.length) {
            hasData = true;
            let box = document.getElementById('faultChips');
            box.innerHTML = '';
            d.ai.forEach(f => {
                let ar = typeof f === 'string' ? f : f.ar;
                let en = typeof f === 'string' ? '' : (f.en || '');
                let chip = document.createElement('div');
                chip.className = 'fault-chip';
                chip.innerHTML = ar;
                chip.dataset.ar = ar; chip.dataset.en = en;
                chip.onclick = () => {
                    chip.classList.toggle('picked');
                    let picked = document.querySelectorAll('.fault-chip.picked');
                    let sar = [], sen = [];
                    picked.forEach(c => { sar.push(c.dataset.ar); if(c.dataset.en) sen.push(c.dataset.en); });
                    let joined = sar.join(' - ');
                    document.getElementById('reqDesc').value = joined;
                    document.getElementById('hzFaultEn').value = sen.join(', ');
                    WZ.lastAutoFilledText = joined;
                    validateStep4();
                };
                box.appendChild(chip);
            });
            document.getElementById('faultBox').style.display = 'block';
        }

        // تحديث وعرض أرقام الـ FDA بصدق وشفافية
        if (d.fda_stats && d.fda_stats.total > 0) {
            hasData = true;
            let kpiHTML = `
              <div class="fda-kpi" style="background:#f0f9ff; border-color:#bae6fd;">
                <div style="font-size:20px; font-weight:900; color:#0284c7;" class="eng-num">${d.fda_stats.total.toLocaleString()}</div>
                <div style="font-size:11px; color:#0369a1; font-weight:800; margin-top:2px;">إجمالي البلاغات</div>
              </div>`;

            // إذا كانت الأرقام حقيقية وليست خطة بديلة
            if (!d.fda_stats.is_fallback && d.fda_stats.malfunction !== null) {
                kpiHTML += `
                  <div class="fda-kpi" style="background:#fef2f2; border-color:#fecaca;">
                    <div style="font-size:20px; font-weight:900; color:#dc2626;" class="eng-num">${d.fda_stats.malfunction.toLocaleString()}</div>
                    <div style="font-size:11px; color:#b91c1c; font-weight:800; margin-top:2px;">أعطال مصنعية</div>
                  </div>
                  <div class="fda-kpi" style="background:#fffbeb; border-color:#fde68a;">
                    <div style="font-size:20px; font-weight:900; color:#d97706;" class="eng-num">${d.fda_stats.injury_death.toLocaleString()}</div>
                    <div style="font-size:11px; color:#b45309; font-weight:800; margin-top:2px;">تحذيرات خطورة</div>
                  </div>`;
            } else {
                // رسالة أمينة للمستخدم عند استخدام الخطة البديلة (Fallback)
                kpiHTML += `
                  <div class="fda-kpi" style="background:#f8fafc; border-color:#e2e8f0; grid-column: span 2; display:flex; align-items:center; justify-content:center;">
                    <div style="font-size:12.5px; font-weight:800; color:#64748b;"><i class="fa-solid fa-circle-info"></i> التصنيف الدقيق غير متوفر حالياً من السيرفر العالمي</div>
                  </div>`;
            }

            document.getElementById('fdaKpis').innerHTML = kpiHTML;
            document.getElementById('fdaBox').style.display = 'block';
        }

        if (!hasData) document.getElementById('faultNone').style.display = 'block';

    } catch (e) {
        document.getElementById('faultLoading').style.display = 'none';
        document.getElementById('faultNone').style.display = 'block';
    }
}

function showFiles(files) {
    let prev = document.getElementById('filePreview');
    prev.innerHTML = '';
    Array.from(files).slice(0, 5).forEach(f => {
        let chip = document.createElement('span');
        chip.className = 'file-chip';
        chip.innerHTML = '<i class="fa-solid fa-file-circle-check"></i> ' + f.name;
        prev.appendChild(chip);
    });
}
</script>
</body>
</html>