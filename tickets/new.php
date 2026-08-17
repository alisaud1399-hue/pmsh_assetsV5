<?php
/**
 * tickets/new.php — إنشاء تذكرة جديدة
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/ticket_helpers.php';

page_guard('tickets', 'create');

global $pdo;
$user = current_user();
$user_id = (int) $user['id'];

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $data = [
        'title'            => trim($_POST['title'] ?? ''),
        'description'      => trim($_POST['description'] ?? ''),
        'ticket_type'      => $_POST['ticket_type'] ?? 'support',
        'priority'         => $_POST['priority'] ?? 'medium',
        'visibility'       => $_POST['visibility'] ?? 'public',
        'assigned_to'      => $_POST['assigned_to'] ?? null,
        'department_id'    => $_POST['department_id'] ?? null,
        'related_type'     => $_POST['related_type'] ?? null,
        'related_id'       => $_POST['related_id'] ?? null,
        'due_date'         => $_POST['due_date'] ?? null,
    ];

    $result = ticket_create($pdo, $data, $user_id);
    if ($result['ok']) {
        header('Location: ' . BASE_URL . '/tickets/view.php?id=' . $result['id']);
        exit;
    } else {
        $flash = ['type' => 'error', 'message' => $result['error']];
    }
}

// تحميل المستخدمين المحتملين (للـ assigned_to dropdown)
$users_list = $pdo->query("SELECT id, full_name, username FROM users WHERE is_active = 1 ORDER BY full_name LIMIT 200")->fetchAll();
$depts_list = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

// لو جاي من complaint/asset، related_type و related_id تكون من GET
$pre_related = [];
$pre_title = '';
$pre_description = '';
$pre_priority = 'medium';
$pre_ticket_type = 'support';
$pre_dept_id = null;
$pre_assignee = null;
$pre_from_label = '';

if (!empty($_GET['related_type']) && !empty($_GET['related_id'])) {
    $pre_related = [
        'type' => $_GET['related_type'],
        'id'   => (int) $_GET['related_id'],
    ];

    if ($pre_related['type'] === 'complaint') {
        $c = $pdo->prepare("
            SELECT id, request_number, description, priority, dept_id, requested_by, request_type
            FROM complaints WHERE id = ?
        ");
        $c->execute([$pre_related['id']]);
        $crow = $c->fetch();
        if ($crow) {
            $desc_snippet = mb_substr($crow['description'] ?? '', 0, 80, 'UTF-8');
            $pre_title = 'متابعة البلاغ: ' . $desc_snippet;
            $pre_description = "📎 مرتبط بالبلاغ #{$crow['request_number']}\n\n" . ($crow['description'] ?? '');
            $pre_from_label = "البلاغ #{$crow['request_number']}";
            // خريطة أولوية البلاغ → أولوية التذكرة
            $prio_map = ['normal' => 'medium', 'urgent' => 'high', 'critical' => 'critical', 'low' => 'low'];
            $pre_priority = $prio_map[$crow['priority']] ?? 'medium';
            $pre_ticket_type = 'complaint';
            $pre_dept_id = $crow['dept_id'] ?? null;
            $pre_assignee = $crow['requested_by'] ?? null;
        }
    } elseif ($pre_related['type'] === 'asset') {
        $a = $pdo->prepare("SELECT id, tag_number, description FROM assets WHERE id = ?");
        $a->execute([$pre_related['id']]);
        $arow = $a->fetch();
        if ($arow) {
            $pre_title = 'صيانة: ' . ($arow['description'] ?? '');
            $pre_description = "📎 مرتبط بالأصل #{$arow['tag_number']}";
            $pre_from_label = "الأصل #{$arow['tag_number']}";
            $pre_ticket_type = 'maintenance';
        }
    }
}

$page_title = 'تذكرة جديدة — نظام التذاكر';
$active_nav = 'tickets';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --primary:#1565C0; --border:#e2e8f0; --bg:#f8fafc; --text-main:#0f172a; --text-2:#475569; --text-3:#94a3b8; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Tajawal', sans-serif; background:var(--bg); color:var(--text-main); }
        .container { max-width: 900px; margin: 0 auto; padding: 18px 20px; }

        .back { display: inline-flex; align-items: center; gap: 5px; padding: 6px 14px; background: #fff; color: var(--text-2); border: 1px solid var(--border); border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 12.5px; margin-bottom: 14px; }
        .back:hover { background: #f1f5f9; }

        .form-hero {
            background: linear-gradient(135deg, #1e293b 0%, #312e81 50%, #4338ca 100%);
            color: #fff; border-radius: 16px; padding: 18px 24px; margin-bottom: 16px;
            display: flex; align-items: center; gap: 16px; box-shadow: 0 8px 24px rgba(30,41,59,0.2);
        }
        .form-hero-ico { width: 50px; height: 50px; background: rgba(255,255,255,0.15); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; }
        .form-hero h1 { margin: 0; font-size: 20px; font-weight: 800; }
        .form-hero p  { margin: 4px 0 0; opacity: 0.85; font-size: 12.5px; }

        .form-card { background: #fff; border-radius: 14px; border: 1px solid var(--border); padding: 22px 24px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-grid .full { grid-column: 1 / -1; }
        .fld { display: flex; flex-direction: column; gap: 5px; }
        .fld label { font-size: 12px; color: var(--text-2); font-weight: 800; display: flex; align-items: center; gap: 4px; }
        .fld label .req { color: #dc2626; }
        .fld label .hint { font-weight: 600; color: var(--text-3); font-size: 10.5px; }
        .fld input, .fld select, .fld textarea {
            padding: 9px 12px; border: 1.5px solid #cbd5e1; border-radius: 8px;
            font-size: 13px; font-family: 'Tajawal', sans-serif; background: #fff;
            transition: border-color 0.15s;
        }
        .fld input:focus, .fld select:focus, .fld textarea:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(21,101,192,0.15);
        }
        .fld textarea { min-height: 120px; resize: vertical; line-height: 1.5; }
        .fld .help { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }

        .prio-pills { display: flex; gap: 6px; flex-wrap: wrap; }
        .prio-pill {
            flex: 1; min-width: 80px; padding: 8px 10px; border: 1.5px solid #cbd5e1;
            border-radius: 8px; background: #fff; cursor: pointer; text-align: center;
            font-size: 11.5px; font-weight: 800; transition: 0.15s; user-select: none;
        }
        .prio-pill .dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-inline-end: 5px; vertical-align: middle; }
        .prio-pill.low      .dot { background: #16a34a; }
        .prio-pill.medium   .dot { background: #0ea5e9; }
        .prio-pill.high     .dot { background: #f59e0b; }
        .prio-pill.critical .dot { background: #dc2626; }
        .prio-pill input { display: none; }
        .prio-pill.selected { border-color: var(--primary); background: #e3f2fd; }

        .form-actions { display: flex; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border); }
        .btn { padding: 11px 24px; border: 0; border-radius: 10px; cursor: pointer; font-weight: 800; font-size: 13.5px; font-family: 'Tajawal', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
        .btn-pri { background: linear-gradient(135deg, #1e293b 0%, #312e81 50%, #4338ca 100%); color: #fff; }
        .btn-pri:hover { transform: translateY(-1px); }
        .btn-sec { background: #e2e8f0; color: var(--text-2); text-decoration: none; }

        .flash-msg { padding: 12px 16px; border-radius: 10px; margin-bottom: 14px; font-weight: 700; font-size: 13px; }
        .flash-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <a href="<?= BASE_URL ?>/tickets/index.php" class="back">
        <i class="fa-solid fa-arrow-right"></i> العودة للقائمة
    </a>

    <div class="form-hero">
        <div class="form-hero-ico"><i class="fa-solid fa-ticket"></i></div>
        <div>
            <h1>تذكرة جديدة</h1>
            <p>سيتم إشعار المسؤول تلقائياً (broadcast bell) عند تعيينه</p>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="flash-msg flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <form class="form-card" method="post">
        <?= csrf_input() ?>

        <div class="form-grid">
            <div class="fld full">
                <label>العنوان <span class="req">*</span></label>
                <input type="text" name="title" required maxlength="200" placeholder="ملخص قصير للمشكلة" value="<?= e($_POST['title'] ?? $pre_title) ?>">
            </div>

            <div class="fld">
                <label>نوع التذكرة <span class="req">*</span></label>
                <select name="ticket_type" required>
                    <option value="support"     <?= ($_POST['ticket_type'] ?? $pre_ticket_type)==='support'?'selected':'' ?>>🎧 دعم فني</option>
                    <option value="maintenance" <?= ($_POST['ticket_type'] ?? $pre_ticket_type)==='maintenance'?'selected':'' ?>>🔧 صيانة</option>
                    <option value="asset"       <?= ($_POST['ticket_type'] ?? $pre_ticket_type)==='asset'?'selected':'' ?>>📦 مرتبط بأصل</option>
                    <option value="complaint"   <?= ($_POST['ticket_type'] ?? $pre_ticket_type)==='complaint'?'selected':'' ?>>🔔 من بلاغ</option>
                    <option value="general"     <?= ($_POST['ticket_type'] ?? $pre_ticket_type)==='general'?'selected':'' ?>>ℹ️ عام</option>
                </select>
            </div>

            <div class="fld">
                <label>الأولوية <span class="req">*</span></label>
                <div class="prio-pills" id="prioPills">
                    <?php $prio = $_POST['priority'] ?? $pre_priority; ?>
                    <label class="prio-pill low <?= $prio==='low'?'selected':'' ?>" data-prio="low">
                        <input type="radio" name="priority" value="low" <?= $prio==='low'?'checked':'' ?>>
                        <span class="dot"></span>منخفضة
                    </label>
                    <label class="prio-pill medium <?= $prio==='medium'?'selected':'' ?>" data-prio="medium">
                        <input type="radio" name="priority" value="medium" <?= $prio==='medium'?'checked':'' ?>>
                        <span class="dot"></span>متوسطة
                    </label>
                    <label class="prio-pill high <?= $prio==='high'?'selected':'' ?>" data-prio="high">
                        <input type="radio" name="priority" value="high" <?= $prio==='high'?'checked':'' ?>>
                        <span class="dot"></span>عالية
                    </label>
                    <label class="prio-pill critical <?= $prio==='critical'?'selected':'' ?>" data-prio="critical">
                        <input type="radio" name="priority" value="critical" <?= $prio==='critical'?'checked':'' ?>>
                        <span class="dot"></span>حرجة
                    </label>
                </div>
            </div>

            <div class="fld full">
                <label>الوصف التفصيلي <span class="req">*</span></label>
                <textarea name="description" required maxlength="5000" placeholder="اشرح المشكلة أو الطلب بالتفصيل..."><?= e($_POST['description'] ?? $pre_description) ?></textarea>
                <div class="help">سيظهر هذا كن أول رسالة في محادثة التذكرة</div>
            </div>

            <div class="fld">
                <label>المسؤول (تعيين)</label>
                <select name="assigned_to">
                    <option value="">— اتركها بدون تعيين —</option>
                    <?php foreach ($users_list as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= (int)($_POST['assigned_to'] ?? 0)===(int)$u['id']?'selected':'' ?>>
                            <?= e($u['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="help">سيحصل على إشعار جرس تلقائياً</div>
            </div>

            <div class="fld">
                <label>القسم المعني</label>
                <select name="department_id">
                    <option value="">— الكل —</option>
                    <?php foreach ($depts_list as $d): ?>
                        <option value="<?= (int)$d['id'] ?>" <?= (int)($_POST['department_id'] ?? 0)===(int)$d['id']?'selected':'' ?>>
                            <?= e($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fld">
                <label>الرؤية</label>
                <select name="visibility">
                    <option value="public"     <?= ($_POST['visibility'] ?? 'public')==='public'?'selected':'' ?>>عامة (كل من عنده صلاحية)</option>
                    <option value="internal"   <?= ($_POST['visibility'] ?? '')==='internal'?'selected':'' ?>>داخلية (القسم فقط)</option>
                    <option value="restricted" <?= ($_POST['visibility'] ?? '')==='restricted'?'selected':'' ?>>مقيّدة (المُنشئ + المُعيَّن فقط)</option>
                </select>
            </div>

            <div class="fld">
                <label>تاريخ الاستحقاق</label>
                <input type="date" name="due_date" value="<?= e($_POST['due_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>">
            </div>

            <?php if ($pre_related): ?>
                <input type="hidden" name="related_type" value="<?= e($pre_related['type']) ?>">
                <input type="hidden" name="related_id" value="<?= (int)$pre_related['id'] ?>">
                <div class="fld full" style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);padding:14px 16px;border-radius:10px;color:#0c4a6e;font-size:12.5px;border-inline-start:4px solid #0284c7">
                    <i class="fa-solid fa-link" style="color:#0284c7;font-size:14px"></i>
                    <strong>قادم من <?= e($pre_from_label) ?></strong>
                    <?php if ($pre_related['type'] === 'complaint'): ?>
                        — تم تعبئة العنوان والوصف والأولوية تلقائياً. يمكنك تعديلها قبل الإرسال.
                    <?php else: ?>
                        — تم ربط التذكرة بهذا الأصل.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-pri">
                <i class="fa-solid fa-paper-plane"></i> إرسال التذكرة
            </button>
            <a href="<?= BASE_URL ?>/tickets/index.php" class="btn btn-sec">
                إلغاء
            </a>
        </div>
    </form>

</div>
</main>
</div>

<script>
document.querySelectorAll('.prio-pill input').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.prio-pill').forEach(function(p) { p.classList.remove('selected'); });
        this.closest('.prio-pill').classList.add('selected');
    });
});
</script>
</body>
</html>
