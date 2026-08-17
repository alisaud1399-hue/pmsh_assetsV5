<?php
/**
 * maintenance/pm_execute.php — تسجيل تنفيذ PM (موبايل)
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('pm.execute');

$rtl = is_rtl();
$page_title = $rtl?'تسجيل PM':'Execute PM';
$active_nav = 'pm.schedules';

global $pdo;
$id = (int)($_GET['id'] ?? 0);
$can_apply = can('pm.schedules', 'apply');

if (!$id) { flash('error', 'معرف PM غير موجود'); redirect('/maintenance/pm_schedules.php'); }

// Load schedule + template
$stmt = $pdo->prepare("
    SELECT s.*, a.tag_number, a.description, a.manufacturer_name, a.model_number,
           t.id AS tpl_id, t.name_ar AS tpl_name, t.name_en AS tpl_name_en,
           t.cycle_days, t.estimated_hours, t.code AS tpl_code
    FROM pm_schedules s
    INNER JOIN assets a ON a.id = s.asset_id
    LEFT JOIN pm_templates t ON t.id = s.template_id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$sch = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sch) { flash('error', 'الجدول غير موجود'); redirect('/maintenance/pm_schedules.php'); }

// Load template items
$tpl_id = $sch['tpl_id'] ?? 0;
$items = [];
if ($tpl_id) {
    $stmt = $pdo->prepare("SELECT * FROM pm_template_items WHERE template_id = ? ORDER BY sort_order");
    $stmt->execute([$tpl_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Generic items if no template
    $items = [
        ['id'=>0, 'item_text_ar'=>'فحص بصري', 'item_text_en'=>'Visual inspection', 'category'=>'general', 'expected_result'=>'', 'is_critical'=>0, 'tool_required'=>''],
    ];
}

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_apply) {
    verify_csrf();
    $performed_by = (int)($_POST['performed_by'] ?? 0) ?: user_id();
    $is_external = !empty($_POST['is_external']) ? 1 : 0;
    $contractor = trim($_POST['contractor'] ?? '');
    $hours_spent = (float)($_POST['hours_spent'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $item_results = $_POST['items'] ?? [];
    $overall_status = 'completed';

    try {
        $pdo->beginTransaction();
        // Insert execution
        $stmt = $pdo->prepare("
            INSERT INTO pm_executions (schedule_id, asset_id, template_id, scheduled_date, completed_date,
                performed_by_user_id, performed_by_contractor, is_external, status, hours_spent, notes, created_at)
            VALUES (?, ?, ?, CURDATE(), CURDATE(), ?, ?, ?, 'completed', ?, ?, NOW())
        ");
        $stmt->execute([
            $id, $sch['asset_id'], $tpl_id ?: null,
            $performed_by, $is_external ? $contractor : null, $is_external,
            $hours_spent, $notes,
        ]);
        $execution_id = (int)$pdo->lastInsertId();

        // Insert items
        $item_stmt = $pdo->prepare("
            INSERT INTO pm_execution_items (execution_id, template_item_id, result, actual_value, notes, photo_path)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $any_fail = false;
        $any_warn = false;
        foreach ($item_results as $item_id => $r) {
            $result = $r['result'] ?? 'pass';
            $val = trim($r['value'] ?? '');
            $item_notes = trim($r['notes'] ?? '');
            $item_stmt->execute([$execution_id, (int)$item_id, $result, $val, $item_notes, null]);
            if ($result === 'fail') $any_fail = true;
            if ($result === 'warning') $any_warn = true;
        }
        if ($any_fail) $overall_status = 'partial';

        // Update schedule: last_completed = today, next_due = today + cycle_days, pm_count++
        $new_next_due = date('Y-m-d', strtotime("+{$sch['cycle_days']} days"));
        $pdo->prepare("
            UPDATE pm_schedules
            SET last_completed = CURDATE(),
                next_due = ?,
                pm_count = pm_count + 1,
                next_reminder_sent_at = NULL
            WHERE id = ?
        ")->execute([$new_next_due, $id]);

        // Log activity
        log_activity('pm.executed', "pm_schedule:$id", json_encode([
            'execution_id' => $execution_id,
            'asset_id' => $sch['asset_id'],
            'template' => $sch['tpl_code'] ?? 'none',
            'status' => $overall_status,
            'hours' => $hours_spent,
        ]));

        $pdo->commit();

        // Notify manager if any critical fail
        if ($any_fail) {
            $managers = $pdo->query("SELECT id FROM users WHERE is_active=1 AND id IN (SELECT user_id FROM user_roles WHERE role_id = 2) LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($managers as $mid) {
                send_notification($mid, 'pm.fail', "PM فاشل — {$sch['tag_number']}", "يوجد بنود فشلت في تنفيذ PM. راجع التفاصيل.", "/maintenance/pm_schedules.php?schedule=$id");
            }
        }

        flash('success', 'تم تسجيل تنفيذ PM بنجاح');
        redirect('/maintenance/pm_schedules.php?schedule=' . $id);
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'خطأ: ' . $e->getMessage());
        redirect("/maintenance/pm_execute.php?id=$id");
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> — <?= e($sch['tag_number']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        body { font-family:'Tajawal',sans-serif; background:#f0f4f8; padding:0; }
        .container { max-width: 720px; margin: 0 auto; padding: 12px; }
        .hdr { background:linear-gradient(135deg, #0f3460, #1a5276); color:#fff; border-radius:12px; padding:18px 22px; margin-bottom:12px; box-shadow:0 4px 16px rgba(15,52,96,.15); }
        .hdr h1 { font-size:18px; font-weight:900; margin:0 0 6px; }
        .hdr .meta { display:flex; gap:8px; flex-wrap:wrap; font-size:11.5px; }
        .hdr .meta .pill { background:rgba(255,255,255,.18); padding:3px 10px; border-radius:99px; }
        .hdr .close { float:right; color:#fff; font-size:18px; }

        .card { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); padding:14px 16px; margin-bottom:10px; }
        .card h2 { font-size:14px; font-weight:900; color:#0f3460; margin:0 0 10px; display:flex; align-items:center; gap:6px; }
        .card h2 .ic { color:#16a34a; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:8px; }
        .form-row.single { grid-template-columns:1fr; }
        .field { display:flex; flex-direction:column; gap:3px; }
        .field label { font-size:11.5px; font-weight:700; color:#475569; }
        .field input, .field select, .field textarea { padding:8px 10px; border:1.5px solid #e2e8f0; border-radius:7px; font-family:inherit; font-size:13px; }
        .field input:focus, .field select:focus, .field textarea:focus { outline:none; border-color:#0f3460; }

        .check-item { display:flex; align-items:center; gap:8px; padding:10px 12px; background:#f8fafc; border-radius:8px; margin-bottom:6px; border:1.5px solid #e2e8f0; flex-wrap:wrap; }
        .check-item.critical { border-color:#dc2626; background:#fef2f2; }
        .check-item .num { width:24px; height:24px; border-radius:50%; background:#0f3460; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:11px; flex-shrink:0; }
        .check-item.critical .num { background:#dc2626; }
        .check-item .txt { flex:1; min-width:160px; }
        .check-item .txt strong { display:block; font-size:12.5px; color:#0f172a; }
        .check-item .txt small { font-size:10.5px; color:#94a3b8; }
        .check-item .expected { font-size:10.5px; color:#0f3460; font-weight:700; padding:1px 6px; background:#eef2ff; border-radius:4px; }
        .check-item .result-btns { display:flex; gap:3px; flex-wrap:wrap; }
        .result-btns label { display:flex; align-items:center; gap:3px; padding:5px 10px; border:1.5px solid #e2e8f0; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; background:#fff; }
        .result-btns input[type=radio] { display:none; }
        .result-btns input:checked + span { color:#fff; }
        .result-btns label.pass:has(input:checked) { background:#16a34a; border-color:#16a34a; }
        .result-btns label.fail:has(input:checked) { background:#dc2626; border-color:#dc2626; }
        .result-btns label.warn:has(input:checked) { background:#f59e0b; border-color:#f59e0b; }
        .result-btns label.na:has(input:checked) { background:#94a3b8; border-color:#94a3b8; }
        .check-item .val { width:140px; }
        .check-item .notes { width:140px; }

        .actions { display:flex; gap:8px; padding:14px; background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.06); position:sticky; bottom:0; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:10px 18px; border-radius:8px; font-weight:800; font-size:13px; text-decoration:none; border:none; cursor:pointer; }
        .btn-primary { background:#16a34a; color:#fff; flex:1; justify-content:center; }
        .btn-primary:hover { background:#15803d; }
        .btn-ghost { background:#f1f5f9; color:#475569; }

        .flash { padding:10px 14px; border-radius:8px; margin-bottom:10px; font-size:13px; }
        .flash-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .flash-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        @media (max-width: 600px) {
            .form-row { grid-template-columns:1fr; }
            .check-item { flex-direction:column; align-items:stretch; }
        }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <?php if ($f = get_flash()): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endif; ?>

    <div class="hdr">
        <a href="<?= BASE_URL ?>/maintenance/pm_schedules.php" class="close" style="text-decoration:none"><i class="fa-solid fa-xmark"></i></a>
        <h1><i class="fa-solid fa-clipboard-check"></i> <?= $rtl?'تسجيل PM':'Execute PM' ?></h1>
        <div class="meta">
            <span class="pill"><i class="fa-solid fa-tag"></i> <?= e($sch['tag_number']) ?></span>
            <span class="pill"><?= e(truncate($sch['description'] ?? '', 40)) ?></span>
            <span class="pill"><i class="fa-solid fa-calendar"></i> <?= (int)$sch['cycle_days'] ?>d</span>
            <?php if ($sch['tpl_name']): ?>
                <span class="pill"><i class="fa-solid fa-list"></i> <?= e($sch['tpl_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" action="">
        <?= csrf_input() ?>
        <div class="card">
            <h2><i class="fa-solid fa-circle-info ic"></i> <?= $rtl?'معلومات التنفيذ':'Execution Info' ?></h2>
            <div class="form-row">
                <div class="field">
                    <label><?= $rtl?'المنفذ':'Performed By' ?></label>
                    <input type="text" value="<?= e(user_name()) ?>" readonly>
                </div>
                <div class="field">
                    <label><?= $rtl?'الوقت (ساعة)':'Hours Spent' ?></label>
                    <input type="number" name="hours_spent" step="0.1" min="0" value="<?= (float)$sch['estimated_hours'] ?>">
                </div>
            </div>
            <div class="form-row single">
                <div class="field">
                    <label>
                        <input type="checkbox" name="is_external" value="1" style="width:auto;margin-inline-end:6px">
                        <?= $rtl?'تنفيذ خارجي (مقاول)':'External contractor' ?>
                    </label>
                </div>
            </div>
            <div class="form-row single">
                <div class="field">
                    <label><?= $rtl?'اسم المقاول (لو خارجي)':'Contractor Name' ?></label>
                    <input type="text" name="contractor" placeholder="<?= $rtl?'مثل: شركة فيليبس':'e.g. Philips' ?>">
                </div>
            </div>
        </div>

        <div class="card">
            <h2><i class="fa-solid fa-tasks ic"></i> <?= $rtl?'بنود الفحص (Checklist)':'Checklist' ?> (<?= count($items) ?>)</h2>
            <?php foreach ($items as $i => $it): ?>
                <div class="check-item <?= $it['is_critical']?'critical':'' ?>">
                    <span class="num"><?= $i+1 ?></span>
                    <div class="txt">
                        <strong><?= e($it['item_text_ar']) ?></strong>
                        <?php if ($it['item_text_en']): ?>
                            <small><?= e($it['item_text_en']) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if ($it['expected_result']): ?>
                        <span class="expected"><?= e(truncate($it['expected_result'], 20)) ?></span>
                    <?php endif; ?>
                    <div class="result-btns">
                        <label class="pass"><input type="radio" name="items[<?= (int)$it['id'] ?>][result]" value="pass" checked><span><?= $rtl?'نجح':'✓' ?></span></label>
                        <label class="warn"><input type="radio" name="items[<?= (int)$it['id'] ?>][result]" value="warning"><span><?= $rtl?'تحذير':'!' ?></span></label>
                        <label class="fail"><input type="radio" name="items[<?= (int)$it['id'] ?>][result]" value="fail"><span><?= $rtl?'فشل':'✗' ?></span></label>
                        <label class="na"><input type="radio" name="items[<?= (int)$it['id'] ?>][result]" value="na"><span><?= $rtl?'لا':'–' ?></span></label>
                    </div>
                    <input type="text" class="val" name="items[<?= (int)$it['id'] ?>][value]" placeholder="<?= $rtl?'القيمة':'Value' ?>">
                    <input type="text" class="notes" name="items[<?= (int)$it['id'] ?>][notes]" placeholder="<?= $rtl?'ملاحظات':'Notes' ?>">
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <h2><i class="fa-solid fa-comment ic"></i> <?= $rtl?'ملاحظات عامة':'Overall Notes' ?></h2>
            <div class="field">
                <textarea name="notes" rows="3" placeholder="<?= $rtl?'أي ملاحظات إضافية...':'Any additional notes...' ?>"></textarea>
            </div>
        </div>

        <div class="actions">
            <a href="<?= BASE_URL ?>/maintenance/pm_schedules.php" class="btn btn-ghost"><i class="fa-solid fa-xmark"></i> <?= $rtl?'إلغاء':'Cancel' ?></a>
            <?php if ($can_apply): ?>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> <?= $rtl?'حفظ التنفيذ':'Save Execution' ?></button>
            <?php endif; ?>
        </div>
    </form>

</div>
</main>
</div>
</body>
</html>
