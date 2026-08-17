<?php
/**
 * inventory/create.php — إنشاء / تعديل جلسة جرد شامل
 * 1) بيانات الجلسة: عنوان، نطاق، تواريخ، ملاحظات
 * 2) تعيين أعضاء اللجنة وأدوارهم (leader / member / observer)
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.create');

$rtl   = is_rtl();
$id    = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$edit  = $id > 0;

if ($edit  && !can('inventory.create', 'edit'))   { abort(403); }
if (!$edit && !can('inventory.create', 'create')) { abort(403); }

$errors = [];
$success = '';
$session = null;
$members = [];

// ── معالجة POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = $rtl ? 'خطأ في الجلسة (CSRF).' : 'Session error (CSRF).';
    } else {
        $title       = trim($_POST['title']       ?? '');
        $scope_type  = $_POST['scope_type']       ?? '';
        $scope_value = $_POST['scope_value']      ?? '';
        $start_date  = $_POST['start_date']       ?? '';
        $end_date    = $_POST['end_date']         ?? '';
        $status      = $_POST['status']           ?? 'planning';
// 🔒 حماية: جلسة جديدة لا تبدأ «نشطة» أبداً — التفعيل فقط عبر زر «تفعيل» في صفحة الجلسة
if (!$edit) $status = 'planning';
if ($edit) {
    $cur_st = $pdo->prepare("SELECT status FROM inventory_sessions WHERE id=?");
    $cur_st->execute([$id]);
    $status = $cur_st->fetchColumn() ?: $status; // الحالة تُدار عبر سير العمل فقط، لا من هذا النموذج
}
        $notes       = trim($_POST['notes']       ?? '');
        $decision_no       = trim($_POST['decision_no']       ?? '');
        $decision_date     = $_POST['decision_date']          ?? '';
        $decision_made_by  = trim($_POST['decision_made_by']  ?? '');
        $custom_tasks_json  = null; // مبني أدناه من members[]

        // رفع ملف القرار (اختياري)
        $decision_doc_path = null;
        if (!empty($_FILES['decision_doc']) && $_FILES['decision_doc']['error'] === UPLOAD_ERR_OK) {
            $up = $_FILES['decision_doc'];
            $allowed = ['application/pdf','image/jpeg','image/png'];
            if (!in_array($up['type'], $allowed, true)) {
                $errors[] = $rtl ? 'نوع ملف القرار غير مدعوم (PDF/JPG/PNG فقط).' : 'Unsupported decision file type.';
            } elseif ($up['size'] > 8 * 1024 * 1024) {
                $errors[] = $rtl ? 'حجم ملف القرار يتجاوز 8 MB.' : 'Decision file too big (max 8MB).';
            } else {
                $dir = __DIR__ . '/../uploads/decisions';
                if (!is_dir($dir)) @mkdir($dir, 0755, true);
                $ext = pathinfo($up['name'], PATHINFO_EXTENSION) ?: 'pdf';
                $fname = sprintf('decision-%s-%d-%s.%s',
                    preg_replace('/[^a-z0-9]/i', '', $session_code ?? 'temp'),
                    time(),
                    bin2hex(random_bytes(3)),
                    strtolower($ext)
                );
                $dest = $dir . '/' . $fname;
                if (move_uploaded_file($up['tmp_name'], $dest)) {
                    $decision_doc_path = 'uploads/decisions/' . $fname;
                } else {
                    $errors[] = $rtl ? 'تعذّر حفظ ملف القرار.' : 'Could not save decision file.';
                }
            }
        }

        // التحقق
        if (!$title) $errors[] = $rtl ? 'عنوان الجلسة مطلوب.' : 'Title is required.';
        if (!in_array($scope_type, ['all','department','asset_type','building','custom'])) {
            $errors[] = $rtl ? 'نوع النطاق غير صحيح.' : 'Invalid scope type.';
        }
        if (!$start_date) $errors[] = $rtl ? 'تاريخ البدء مطلوب.' : 'Start date is required.';
        if ($end_date && $end_date < $start_date) {
            $errors[] = $rtl ? 'تاريخ النهاية يجب أن يكون بعد البداية.' : 'End date must be after start date.';
        }
        if (!in_array($status, ['planning','active','review','completed','cancelled'])) {
            $errors[] = $rtl ? 'الحالة غير صحيحة.' : 'Invalid status.';
        }

        // scope_value: اجعله JSON array
        $scope_json = null;
        if ($scope_value !== '' && $scope_type !== 'all') {
            $vals = array_filter(array_map('trim', explode(',', $scope_value)));
            if (!$vals) {
                $errors[] = $rtl ? 'قيمة النطاق مطلوبة للنوع المحدد.' : 'Scope value is required for this scope type.';
            } else {
                $scope_json = json_encode(array_values($vals), JSON_UNESCAPED_UNICODE);
            }
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                if ($edit) {
                    // Update existing session
                    if ($decision_doc_path) {
                        $pdo->prepare("
                            UPDATE inventory_sessions SET
                              title = ?, scope_type = ?, scope_value = ?,
                              start_date = ?, end_date = ?, status = ?, notes = ?,
                              decision_no = ?, decision_date = ?, decision_made_by = ?, decision_doc_path = ?
                            WHERE id = ?
                        ")->execute([$title, $scope_type, $scope_json, $start_date, $end_date ?: null, $status, $notes ?: null, $decision_no ?: null, $decision_date ?: null, $decision_made_by ?: null, $decision_doc_path, $id]);
                    } else {
                        $pdo->prepare("
                            UPDATE inventory_sessions SET
                              title = ?, scope_type = ?, scope_value = ?,
                              start_date = ?, end_date = ?, status = ?, notes = ?,
                              decision_no = ?, decision_date = ?, decision_made_by = ?
                            WHERE id = ?
                        ")->execute([$title, $scope_type, $scope_json, $start_date, $end_date ?: null, $status, $notes ?: null, $decision_no ?: null, $decision_date ?: null, $decision_made_by ?: null, $id]);
                    }
                    $session_id = $id;
                    $success_msg = $rtl ? 'تم تحديث الجلسة بنجاح.' : 'Session updated.';
                } else {
                    // رمز الجلسة التلقائي: INV/YYYY/NNN
                    $yr = date('Y');
                    $seq = (int)$pdo->query("SELECT COUNT(*)+1 FROM inventory_sessions WHERE YEAR(created_at)=$yr")->fetchColumn();
                    $session_code = "INV/$yr/" . str_pad($seq, 3, '0', STR_PAD_LEFT);

                    $pdo->prepare("
                        INSERT INTO inventory_sessions
                          (session_code, title, scope_type, scope_value, start_date, end_date, status, notes,
                           decision_no, decision_date, decision_made_by, decision_doc_path, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $session_code, $title, $scope_type, $scope_json, $start_date, $end_date ?: null, $status, $notes ?: null,
                        $decision_no ?: null, $decision_date ?: null, $decision_made_by ?: null, $decision_doc_path,
                        current_user()['id']
                    ]);
                    $session_id = (int)$pdo->lastInsertId();
                    $success_msg = $rtl ? "تم إنشاء الجلسة $session_code بنجاح." : "Session $session_code created.";
                }

                // تحديث الأعضاء (إن تم إرسالهم)
                if ($edit && isset($_POST['members'])) {
                    $pdo->prepare("DELETE FROM inventory_session_members WHERE session_id=?")->execute([$session_id]);
                }
                if (isset($_POST['members']) && is_array($_POST['members'])) {
                    $ins = $pdo->prepare("
                        INSERT INTO inventory_session_members (session_id, user_id, role, assigned_scope, custom_tasks)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    foreach ($_POST['members'] as $m) {
                        $uid = (int)($m['user_id'] ?? 0);
                        if (!$uid) continue;
                        $role = in_array($m['role'] ?? '', ['leader','member','observer']) ? $m['role'] : 'member';
                        $ascope = !empty($m['assigned_scope']) ? json_encode(array_filter(array_map('trim', explode(',', $m['assigned_scope'])))) : null;

                        // المهام: من role المعتمد + من خانة "مهام إضافية"
                        $tasks = [];
                        if (!empty($m['task_code'])) {
                            $tasks[] = $m['task_code']; // من المكتبة
                        }
                        if (!empty($m['custom_tasks'])) {
                            // تقسيم بـ الأسطر
                            $extra = array_filter(array_map('trim', preg_split('/\r?\n/', $m['custom_tasks'])));
                            foreach ($extra as $e) {
                                if ($e !== '') $tasks[] = ['free_text' => $e];
                            }
                        }
                        $tasks_json = $tasks ? json_encode($tasks, JSON_UNESCAPED_UNICODE) : null;
                        $ins->execute([$session_id, $uid, $role, $ascope, $tasks_json]);
                    }
                }

                $pdo->commit();
				// ── تنبيه أعضاء اللجنة المختارين (جلسة جديدة فقط) ──
if (!$edit) {
    $actor = (int)(current_user()['id'] ?? 0);
    $mem = $pdo->prepare("SELECT m.user_id, u.full_name FROM inventory_session_members m
                          LEFT JOIN users u ON u.id = m.user_id WHERE m.session_id=?");
    $mem->execute([$session_id]);
    $ins_n = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                            VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)");
    foreach ($mem->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $muid = (int)$row['user_id'];
        if ($muid === $actor) continue; // لا تُنبّه منشئ الجلسة
        $ins_n->execute([
            $muid,
            '📋 اختياركم ضمن لجنة الجرد',
            'عزيزي ' . ($row['full_name'] ?? '') . '، تم اختياركم من ضمن الأعضاء لجلسة الجرد رقم '
            . $session_code . ' وسيتم إبلاغكم بموعد البدء في تنفيذها.',
            BASE_URL . '/inventory/session.php?id=' . $session_id,
            $session_id
        ]);
    }
}
                flash('success', $success_msg);
                header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $session_id);
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $rtl ? 'حدث خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage();
            }
        }
    }
}

// ── جلب بيانات الجلسة في وضع التعديل ──────────────────────────
if ($edit) {
    $st = $pdo->prepare("SELECT * FROM inventory_sessions WHERE id=?");
    $st->execute([$id]);
    $session = $st->fetch(PDO::FETCH_ASSOC);
    if (!$session) abort(404);

    $sm = $pdo->prepare("SELECT * FROM inventory_session_members WHERE session_id=? ORDER BY FIELD(role,'leader','member','observer'), user_id");
    $sm->execute([$id]);
    $members = $sm->fetchAll(PDO::FETCH_ASSOC);
}

// ── قوائم للاختيار ─────────────────────────────────────────────
// المستخدمون المرشحون للجنة: أي مستخدم نشط بإدارة صيانة أو تنفيذي أو admin
$candidate_users = $pdo->query("
    SELECT u.id, u.full_name, d.name AS dept_name, u.department_id
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.is_active = 1
    ORDER BY u.full_name
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

// الإدارات حسب نوع النطاق
$depts = $pdo->query("SELECT id, name FROM departments WHERE is_active=1 ORDER BY name")->fetchAll();

// مكتبة المهام المعتمدة للجان الجرد (مع fallback لو الـ migration لم تُطبَّق بعد)
try {
    $task_library = $pdo->query("SELECT code, name_ar, name_en FROM task_library WHERE is_active=1 ORDER BY sort_order, name_ar")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $task_library = [];
}
$asset_types = [
    'medical' => $rtl ? 'طبي' : 'Medical',
    'it'      => $rtl ? 'تقنية معلومات' : 'IT',
    'infrastructure' => $rtl ? 'بنية تحتية' : 'Infrastructure',
    'hvac'    => $rtl ? 'تكييف' : 'HVAC',
    'transport' => $rtl ? 'مركبات' : 'Transport',
    'furniture' => $rtl ? 'أثاث' : 'Furniture',
    'other'   => $rtl ? 'أخرى' : 'Other',
];
$buildings = $pdo->query("SELECT id, name FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll();

$page_title = $edit ? ($rtl ? 'تعديل جلسة جرد' : 'Edit Inventory Session') : ($rtl ? 'جلسة جرد جديدة' : 'New Inventory Session');
$active_nav = 'inventory.index';

$SCOPE_LABELS = [
    'all'         => $rtl ? 'كل أصول المستشفى' : 'All hospital assets',
    'department'  => $rtl ? 'إدارة محددة (مثل الأشعة، الطوارئ)' : 'Specific department (e.g., Radiology, ER)',
    'asset_type'  => $rtl ? 'نوع أصل (طبي، IT، أثاث...)' : 'Asset type (medical, IT, furniture...)',
    'building'    => $rtl ? 'مبنى محدد' : 'Specific building',
    'custom'      => $rtl ? 'نطاق مخصص (قائمة أصول)' : 'Custom scope (asset list)',
];
?>
<!DOCTYPE html>
<html lang="<?= e($lang ?? 'ar') ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#2563eb; }
body { background:var(--bg); font-family:'Tajawal',sans-serif; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width:1100px; margin:0 auto; padding:22px; }
.h-banner { background:linear-gradient(135deg,#0f172a,#1e293b); border-radius:22px; padding:22px 28px; color:#fff; margin-bottom:18px; }
.h-banner h1 { font-size:19px; font-weight:900; margin:0; display:flex; align-items:center; gap:10px; }
.h-banner p { font-size:12.5px; color:#cbd5e1; margin:6px 0 0; }

.bento { background:var(--card); border-radius:18px; border:1px solid var(--border); padding:24px; margin-bottom:16px; box-shadow:0 4px 18px rgba(0,0,0,.03); }
.bento-h { font-size:14.5px; font-weight:900; margin:0 0 18px; display:flex; align-items:center; gap:9px; color:var(--text); }
.bento-h i { color:var(--primary); background:#eff6ff; padding:7px; border-radius:9px; }

.grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.grid .full { grid-column:1 / -1; }
.fg { display:flex; flex-direction:column; gap:6px; }
.fg label { font-size:12px; font-weight:900; color:#475569; }
.rfi { height:46px; padding:0 14px; border:1.5px solid var(--border); border-radius:11px; font-family:'Tajawal'; font-size:13.5px; outline:none; transition:.2s; color:var(--text); background:#fff; width:100%; box-sizing:border-box; }
.rfi:focus { border-color:var(--primary); box-shadow:0 0 0 4px rgba(37,99,235,.1); }
textarea.rfi { height:auto; padding:14px; resize:vertical; min-height:90px; }
.help { font-size:11.5px; color:var(--muted); font-weight:600; margin-top:4px; }

.scope-info { background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:14px 16px; font-size:12.5px; color:#1e40af; font-weight:700; line-height:1.7; }
.scope-info i { margin-left:6px; }

.member-row { background:#f8fafc; border:1px solid var(--border); border-radius:12px; padding:12px; margin-bottom:8px; display:grid; grid-template-columns:1.5fr 1fr 1.2fr 1.2fr auto; gap:10px; align-items:end; }
.member-row .fg label { font-size:10.5px; }
.member-row select.rfi, .member-row input.rfi { height:40px; font-size:12.5px; }
.btn-del-row { background:#fee2e2; border:1px solid #fecaca; color:#dc2626; padding:8px 12px; border-radius:9px; cursor:pointer; font-family:'Tajawal'; font-weight:800; font-size:11.5px; height:40px; }

.btn-row { display:flex; gap:10px; margin-top:8px; }
.btn-add { background:#f1f5f9; border:1.5px dashed #cbd5e1; color:#475569; padding:10px 18px; border-radius:11px; cursor:pointer; font-family:'Tajawal'; font-weight:800; font-size:12.5px; width:100%; }
.btn-add:hover { background:#e2e8f0; border-color:#94a3b8; }

.btn-save { background:linear-gradient(135deg,#10b981,#059669); color:#fff; border:none; padding:14px 32px; border-radius:12px; font-family:'Tajawal'; font-size:14px; font-weight:900; cursor:pointer; box-shadow:0 4px 12px rgba(16,185,129,.25); }
.btn-save:hover { transform:translateY(-1px); }
.btn-cancel { background:#f1f5f9; color:#475569; border:1px solid var(--border); padding:14px 24px; border-radius:12px; font-family:'Tajawal'; font-size:13px; font-weight:800; text-decoration:none; }

.errs { background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:14px 18px; margin-bottom:14px; color:#b91c1c; font-weight:700; font-size:13px; }
.errs ul { margin:0; padding-right:18px; }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<div class="h-banner">
    <h1><i class="fa-solid fa-clipboard-check" style="color:#fbbf24"></i> <?= e($page_title) ?></h1>
    <p><?= $edit ? ($rtl ? 'تعديل بيانات جلسة موجودة وتحديث قائمة اللجنة.' : 'Edit session details and committee.') : ($rtl ? 'إنشاء جلسة جديدة لتدقيق الأصول ميدانياً. النظام سيولّد رمز الجلسة تلقائياً.' : 'Create new session for field audits. Code auto-generated.') ?></p>
</div>

<?php if ($errors): ?>
<div class="errs"><ul><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" id="sessionForm">
<?= csrf_input() ?>
<input type="hidden" name="id" value="<?= $id ?>">

<!-- بيانات الجلسة -->
<div class="bento">
    <div class="bento-h"><i class="fa-solid fa-circle-info"></i> <?= $rtl ? 'بيانات الجلسة' : 'Session Details' ?></div>

    <div class="grid">
        <div class="fg full">
            <label><?= $rtl ? 'عنوان الجلسة *' : 'Title *' ?></label>
            <input type="text" name="title" class="rfi" required maxlength="200"
                value="<?= e($session['title'] ?? $_POST['title'] ?? '') ?>"
                placeholder="<?= $rtl ? 'مثل: جرد قسم الأشعة - يوليو 2026' : 'e.g., Radiology inventory - July 2026' ?>">
            <div class="help"><?= $rtl ? 'عنوان وصفي واضح يساعد في التمييز بين الجلسات.' : 'A clear descriptive title.' ?></div>
        </div>

        <div class="fg">
            <label><?= $rtl ? 'تاريخ البدء *' : 'Start Date *' ?></label>
            <input type="date" name="start_date" class="rfi" required
                value="<?= e($session['start_date'] ?? $_POST['start_date'] ?? date('Y-m-d')) ?>">
        </div>

        <div class="fg">
            <label><?= $rtl ? 'تاريخ النهاية (اختياري)' : 'End Date (optional)' ?></label>
            <input type="date" name="end_date" class="rfi"
                value="<?= e($session['end_date'] ?? $_POST['end_date'] ?? '') ?>">
            <div class="help"><?= $rtl ? 'اتركه فارغاً إذا لم يُحدد.' : 'Leave empty if open-ended.' ?></div>
        </div>

        <div class="fg">
            <label><?= $rtl ? 'الحالة' : 'Status' ?></label>
            <select name="status" class="rfi">
                <?php foreach (['planning'=>$rtl?'تحت التخطيط':'Planning', 'active'=>$rtl?'نشطة':'Active', 'review'=>$rtl?'قيد المراجعة':'Under Review', 'completed'=>$rtl?'مكتملة':'Completed', 'cancelled'=>$rtl?'ملغاة':'Cancelled'] as $k=>$l): ?>
                <option value="<?= $k ?>" <?= ($session['status'] ?? $_POST['status'] ?? 'planning') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fg">
            <label><?= $rtl ? 'نوع النطاق *' : 'Scope Type *' ?></label>
            <select name="scope_type" class="rfi" id="scopeType" onchange="updateScopeUI()">
                <?php foreach ($SCOPE_LABELS as $k=>$l): ?>
                <option value="<?= $k ?>" <?= ($session['scope_type'] ?? $_POST['scope_type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="fg full" id="scopeValueBox" style="display:none">
            <label id="scopeValueLabel"><?= $rtl ? 'قيمة النطاق' : 'Scope Value' ?></label>
            <input type="text" name="scope_value" class="rfi" id="scopeValueInp"
                value="<?= e(is_array($j=json_decode($session['scope_value']??'[]',true)) ? implode(', ', $j) : ($_POST['scope_value'] ?? '')) ?>"
                placeholder="">
            <div class="help" id="scopeValueHelp"></div>
        </div>

        <div class="fg full">
            <label><?= $rtl ? 'ملاحظات' : 'Notes' ?></label>
            <textarea name="notes" class="rfi" rows="3"
                placeholder="<?= $rtl ? 'ملاحظات للجنة، تنبيهات، إلخ.' : 'Notes for the committee, alerts, etc.' ?>"><?= e($session['notes'] ?? $_POST['notes'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- قرار تشكيل اللجنة (اختياري) -->
<div class="bento">
    <h3 class="bento-h">
        <i class="fa-solid fa-file-signature"></i>
        <?= $rtl ? 'قرار تشكيل اللجنة (اختياري)' : 'Committee Decision (optional)' ?>
    </h3>
    <div class="grid">
        <div class="fg">
            <label><?= $rtl ? 'رقم القرار' : 'Decision Number' ?></label>
            <input type="text" name="decision_no" class="rfi" maxlength="50"
                value="<?= e($session['decision_no'] ?? $_POST['decision_no'] ?? '') ?>"
                placeholder="<?= $rtl ? 'قرار رقم 123/2026' : 'Decision No. 123/2026' ?>">
            <div class="help"><?= $rtl ? 'رقم قرار تشكيل اللجنة الصادر من الإدارة.' : 'Reference number of the official committee decision.' ?></div>
        </div>
        <div class="fg">
            <label><?= $rtl ? 'تاريخ القرار' : 'Decision Date' ?></label>
            <input type="date" name="decision_date" class="rfi"
                value="<?= e($session['decision_date'] ?? $_POST['decision_date'] ?? '') ?>">
        </div>
        <div class="fg full">
            <label><?= $rtl ? 'صادر القرار' : 'Decision Issued By' ?></label>
            <input type="text" name="decision_made_by" class="rfi" maxlength="200"
                value="<?= e($session['decision_made_by'] ?? $_POST['decision_made_by'] ?? '') ?>"
                placeholder="<?= $rtl ? 'مثل: المدير التنفيذي للشؤون الإدارية' : 'e.g., Executive Director of Administrative Affairs' ?>">
        </div>
        <div class="fg full">
            <label><?= $rtl ? 'نسخة من القرار (PDF/JPG/PNG)' : 'Decision Document (PDF/JPG/PNG)' ?></label>
            <?php if (!empty($session['decision_doc_path'])): ?>
            <div style="background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:10px 14px; margin-bottom:8px; font-size:12.5px; color:#15803d; font-weight:800">
                <i class="fa-solid fa-paperclip"></i>
                <?= $rtl ? 'مرفق سابقاً:' : 'Already attached:' ?>
                <a href="<?= BASE_URL ?>/<?= e($session['decision_doc_path']) ?>" target="_blank" style="color:#15803d; text-decoration:underline; margin-inline-start:6px;">
                    <?= e(basename($session['decision_doc_path'])) ?>
                </a>
            </div>
            <?php endif; ?>
            <input type="file" name="decision_doc" class="rfi" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            <div class="help"><?= $rtl ? 'اختياري — يمكن إضافة القرار لاحقاً من صفحة تفاصيل الجلسة.' : 'Optional — can be added later from the session details page.' ?></div>
        </div>
    </div>
</div>

<!-- أعضاء اللجنة -->
<div class="bento">
    <div class="bento-h"><i class="fa-solid fa-users"></i> <?= $rtl ? 'أعضاء اللجنة' : 'Committee Members' ?></div>
    <div id="membersBox">
        <?php
        $existing_members = !empty($members) ? $members : [['user_id'=>'','role'=>'leader','assigned_scope'=>'','custom_tasks'=>'','task_code'=>'']];
        foreach ($existing_members as $i => $m):
            $ascope = is_array($j=json_decode($m['assigned_scope']??'[]',true)) ? implode(', ', $j) : '';
            // تفكيك custom_tasks (JSON) لعرض النص الحر
            $ctext = '';
            $selected_task = '';
            $tasks_decoded = json_decode($m['custom_tasks']??'[]', true);
            if (is_array($tasks_decoded)) {
                $texts = [];
                foreach ($tasks_decoded as $t) {
                    if (is_array($t) && isset($t['free_text'])) {
                        $texts[] = $t['free_text'];
                    } elseif (is_string($t)) {
                        $selected_task = $t;
                    }
                }
                $ctext = implode("\n", $texts);
            }
        ?>
        <div class="member-row">
            <div class="fg">
                <label><?= $rtl ? 'العضو' : 'Member' ?></label>
                <select name="members[<?= $i ?>][user_id]" class="rfi" required>
                    <option value=""><?= $rtl ? '— اختر —' : '— Select —' ?></option>
                    <?php foreach ($candidate_users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$m['user_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['full_name']) ?><?= $u['dept_name'] ? ' (' . e($u['dept_name']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><?= $rtl ? 'الدور' : 'Role' ?></label>
                <select name="members[<?= $i ?>][role]" class="rfi">
                    <option value="leader"   <?= ($m['role'] ?? '') === 'leader'   ? 'selected' : '' ?>><?= $rtl ? 'رئيس' : 'Leader' ?></option>
                    <option value="member"   <?= ($m['role'] ?? '') === 'member'   ? 'selected' : '' ?>><?= $rtl ? 'عضو' : 'Member' ?></option>
                    <option value="observer" <?= ($m['role'] ?? '') === 'observer' ? 'selected' : '' ?>><?= $rtl ? 'مراقب' : 'Observer' ?></option>
                </select>
            </div>
            <div class="fg">
                <label><?= $rtl ? 'مهمة من المكتبة' : 'Task (library)' ?></label>
                <select name="members[<?= $i ?>][task_code]" class="rfi">
                    <option value=""><?= $rtl ? '— بدون —' : '— None —' ?></option>
                    <?php foreach ($task_library as $tl): ?>
                    <option value="<?= e($tl['code']) ?>" <?= $selected_task === $tl['code'] ? 'selected' : '' ?>>
                        <?= e($rtl ? $tl['name_ar'] : $tl['name_en']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label><?= $rtl ? 'نطاق (اختياري)' : 'Scope (optional)' ?></label>
                <input type="text" name="members[<?= $i ?>][assigned_scope]" class="rfi"
                    value="<?= e($ascope) ?>"
                    placeholder="<?= $rtl ? 'IDs أو مبانٍ' : 'IDs or buildings' ?>">
            </div>
            <div class="fg full" style="grid-column:1 / -1">
                <label><?= $rtl ? 'مهام إضافية حرة (سطر لكل مهمة)' : 'Custom tasks (one per line)' ?></label>
                <textarea name="members[<?= $i ?>][custom_tasks]" class="rfi" rows="2"
                    placeholder="<?= $rtl ? 'مثل: مراجعة قسم الأشعة بالكامل / معايرة الأجهزة المخبرية' : 'e.g., Review radiology dept entirely / calibrate lab devices' ?>"
                    style="height:auto; min-height:60px; font-size:12.5px"><?= e($ctext) ?></textarea>
            </div>
            <button type="button" class="btn-del-row" onclick="this.closest('.member-row').remove()"><i class="fa-solid fa-trash"></i></button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn-add" onclick="addMemberRow()"><i class="fa-solid fa-plus"></i> <?= $rtl ? 'إضافة عضو' : 'Add Member' ?></button>
</div>

<div class="btn-row" style="justify-content:flex-end">
    <a href="<?= BASE_URL ?>/inventory/index.php" class="btn-cancel"><?= $rtl ? 'إلغاء' : 'Cancel' ?></a>
    <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> <?= $rtl ? 'حفظ الجلسة' : 'Save Session' ?></button>
</div>

</form>

</div></main>
</div>

<script>
const RTL = <?= $rtl ? 'true' : 'false' ?>;
const STRINGS = {
    member: <?= json_encode($rtl ? 'العضو' : 'Member', JSON_UNESCAPED_UNICODE) ?>,
    role: <?= json_encode($rtl ? 'الدور' : 'Role', JSON_UNESCAPED_UNICODE) ?>,
    scopeOpt: <?= json_encode($rtl ? 'نطاق (اختياري)' : 'Scope (optional)', JSON_UNESCAPED_UNICODE) ?>,
    taskOpt: <?= json_encode($rtl ? 'مهمة من المكتبة' : 'Task (library)', JSON_UNESCAPED_UNICODE) ?>,
    taskNone: <?= json_encode($rtl ? '— بدون —' : '— None —', JSON_UNESCAPED_UNICODE) ?>,
    customOpt: <?= json_encode($rtl ? 'مهام إضافية (سطر لكل واحدة)' : 'Custom tasks (one per line)', JSON_UNESCAPED_UNICODE) ?>,
    customPh: <?= json_encode($rtl ? 'مثل: مراجعة قسم الأشعة بالكامل' : 'e.g., Review radiology dept entirely', JSON_UNESCAPED_UNICODE) ?>,
    leader: <?= json_encode($rtl ? 'رئيس' : 'Leader', JSON_UNESCAPED_UNICODE) ?>,
    member2: <?= json_encode($rtl ? 'عضو' : 'Member', JSON_UNESCAPED_UNICODE) ?>,
    observer: <?= json_encode($rtl ? 'مراقب' : 'Observer', JSON_UNESCAPED_UNICODE) ?>,
    select: <?= json_encode($rtl ? '— اختر —' : '— Select —', JSON_UNESCAPED_UNICODE) ?>,
    scopePh: <?= json_encode($rtl ? 'IDs أو مبانٍ (مفصولة بفواصل)' : 'IDs or buildings (comma-separated)', JSON_UNESCAPED_UNICODE) ?>,
};
const CANDIDATES = <?= json_encode(array_map(fn($u) => ['id'=>(int)$u['id'], 'name'=>$u['full_name'], 'dept'=>$u['dept_name'] ?? ''], $candidate_users), JSON_UNESCAPED_UNICODE) ?>;
const TASK_LIB = <?= json_encode(array_map(fn($t) => ['code'=>$t['code'], 'name'=>$rtl ? $t['name_ar'] : $t['name_en']], $task_library), JSON_UNESCAPED_UNICODE) ?>;

function buildMemberRow(idx) {
    let opts = `<option value="">${STRINGS.select}</option>`;
    for (const u of CANDIDATES) {
        opts += `<option value="${u.id}">${escapeHtml(u.name)}${u.dept?' ('+escapeHtml(u.dept)+')':''}</option>`;
    }
    let taskOpts = `<option value="">${STRINGS.taskNone}</option>`;
    for (const t of TASK_LIB) {
        taskOpts += `<option value="${escapeHtml(t.code)}">${escapeHtml(t.name)}</option>`;
    }
    return `
    <div class="member-row">
        <div class="fg"><label>${STRINGS.member}</label>
            <select name="members[${idx}][user_id]" class="rfi" required>${opts}</select></div>
        <div class="fg"><label>${STRINGS.role}</label>
            <select name="members[${idx}][role]" class="rfi">
                <option value="leader">${STRINGS.leader}</option>
                <option value="member" selected>${STRINGS.member2}</option>
                <option value="observer">${STRINGS.observer}</option>
            </select></div>
        <div class="fg"><label>${STRINGS.taskOpt}</label>
            <select name="members[${idx}][task_code]" class="rfi">${taskOpts}</select></div>
        <div class="fg"><label>${STRINGS.scopeOpt}</label>
            <input type="text" name="members[${idx}][assigned_scope]" class="rfi" placeholder="${STRINGS.scopePh}"></div>
        <button type="button" class="btn-del-row" onclick="this.closest('.member-row').remove()"><i class="fa-solid fa-trash"></i></button>
        <div class="fg full" style="grid-column:1 / -1"><label>${STRINGS.customOpt}</label>
            <textarea name="members[${idx}][custom_tasks]" class="rfi" rows="2" placeholder="${STRINGS.customPh}" style="height:auto; min-height:60px; font-size:12.5px"></textarea>
        </div>
    </div>`;
}

let memberIdx = <?= count($existing_members) ?>;
function addMemberRow() {
    document.getElementById('membersBox').insertAdjacentHTML('beforeend', buildMemberRow(memberIdx++));
}

function updateScopeUI() {
    const t = document.getElementById('scopeType').value;
    const box = document.getElementById('scopeValueBox');
    const lbl = document.getElementById('scopeValueLabel');
    const inp = document.getElementById('scopeValueInp');
    const help = document.getElementById('scopeValueHelp');
    if (t === 'all') {
        box.style.display = 'none';
        inp.value = '';
        return;
    }
    box.style.display = '';
    const hints = {
        department:  { lbl: <?= json_encode($rtl?'IDs الإدارات (مفصولة بفواصل)':'Department IDs (comma-separated)', JSON_UNESCAPED_UNICODE) ?>, ph: '1, 12, 43', hlp: <?= json_encode($rtl?'مثل: 1=الإدارة الطبية، 43=المختبر، 52=الأشعة':'e.g., 1=Medical, 43=Lab, 52=Radiology', JSON_UNESCAPED_UNICODE) ?> },
        asset_type:  { lbl: <?= json_encode($rtl?'أنواع الأصول':'Asset Types', JSON_UNESCAPED_UNICODE) ?>, ph: 'medical, it, furniture', hlp: <?= json_encode($rtl?'القيم المتاحة: medical, it, infrastructure, hvac, transport, furniture, other':'Available: medical, it, infrastructure, hvac, transport, furniture, other', JSON_UNESCAPED_UNICODE) ?> },
        building:    { lbl: <?= json_encode($rtl?'IDs المباني':'Building IDs', JSON_UNESCAPED_UNICODE) ?>, ph: '1, 2, 5', hlp: <?= json_encode($rtl?'مثل: 1=المبنى الرئيسي، 2=العيادات الخارجية':'e.g., 1=Main, 2=OPD', JSON_UNESCAPED_UNICODE) ?> },
        custom:      { lsl: <?= json_encode($rtl?'IDs الأصول (مفصولة بفواصل)':'Asset IDs (comma-separated)', JSON_UNESCAPED_UNICODE) ?>, ph: '101, 102, 250', hlp: <?= json_encode($rtl?'قائمة أصول محددة مسبقاً':'Pre-defined asset list', JSON_UNESCAPED_UNICODE) ?> },
    }[t] || {};
    if (hints.lbl) lbl.textContent = hints.lbl;
    if (hints.ph)  inp.placeholder  = hints.ph;
    if (hints.hlp) help.textContent  = hints.hlp;
}

function escapeHtml(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

document.addEventListener('DOMContentLoaded', updateScopeUI);
</script>
</body>
</html>