<?php
/**
 * maintenance/pm_schedules.php — جداول الصيانة الوقائية (PM Schedules)
 *
 * - List: filters (overdue / due_soon / upcoming / all) + executor (internal/external)
 * - Add/Edit: asset + template + assigned user/contractor + next_due
 * - Execute link → pm_execute.php?id=X
 * - History (per schedule): all pm_executions
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('pm.schedules');

$rtl = is_rtl();
$page_title = $rtl ? 'جداول الصيانة الوقائية' : 'PM Schedules';
$active_nav = 'pm.schedules';

global $pdo;
$can_edit = can('pm.schedules', 'edit');
$can_apply = can('pm.schedules', 'apply');
$can_delete = can('pm.schedules', 'edit'); // delete uses edit perm (sufficient for housekeeping)

$action = $_GET['action'] ?? 'list';
$id = (int)($_GET['id'] ?? 0);

// ===== Actions =====

// Delete
if ($action === 'delete' && $id && $can_delete) {
    try {
        $pdo->beginTransaction();
        // Delete executions + items first (FK CASCADE handles items)
        $pdo->prepare("DELETE FROM pm_executions WHERE schedule_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM pm_schedules WHERE id = ?")->execute([$id]);
        $pdo->commit();
        flash('success', $rtl ? 'تم حذف الجدول' : 'Schedule deleted');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'خطأ: ' . $e->getMessage());
    }
    redirect('/maintenance/pm_schedules.php');
}

// Toggle active
if ($action === 'toggle' && $id && $can_edit) {
    $pdo->prepare("UPDATE pm_schedules SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
    flash('success', $rtl ? 'تم تحديث الحالة' : 'Status updated');
    redirect('/maintenance/pm_schedules.php');
}

// Save (create or update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $edit_id = (int)($_POST['id'] ?? 0);
    $template_id = (int)($_POST['template_id'] ?? 0);
    // FIX 2026-07-31: accept either tag_number or asset_number (alphanumeric)
    // Field name kept as asset_id (hidden) for backwards compat
    $asset_ref = trim($_POST['asset_ref'] ?? '');
    $asset_id = 0;
    if ($asset_ref !== '') {
        // Try exact match on tag_number OR asset_number
        $find = $pdo->prepare("SELECT id FROM assets WHERE tag_number = ? OR asset_number = ? LIMIT 1");
        $find->execute([$asset_ref, $asset_ref]);
        $asset_id = (int)($find->fetchColumn() ?: 0);
        if (!$asset_id) {
            // Try partial match if no exact
            $find = $pdo->prepare("SELECT id FROM assets WHERE tag_number LIKE ? OR asset_number LIKE ? OR description LIKE ? ORDER BY id LIMIT 1");
            $find->execute(['%'.$asset_ref.'%', '%'.$asset_ref.'%', '%'.$asset_ref.'%']);
            $asset_id = (int)($find->fetchColumn() ?: 0);
        }
    }
    $is_external = !empty($_POST['is_external']) ? 1 : 0;
    $assigned_to_user_id = (int)($_POST['assigned_to_user_id'] ?? 0);
    $contractor_id = (int)($_POST['contractor_id'] ?? 0);
    $next_due = $_POST['next_due'] ?? '';
    $estimated_hours = (float)($_POST['estimated_hours'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    // Load template for cycle_days
    $tpl = $pdo->prepare("SELECT cycle_days, estimated_hours, internal_or_external FROM pm_templates WHERE id = ?");
    $tpl->execute([$template_id]);
    $tpl = $tpl->fetch(PDO::FETCH_ASSOC);
    if (!$tpl) {
        flash('error', $rtl ? 'القالب غير موجود' : 'Template not found');
        redirect('/maintenance/pm_schedules.php?action=edit&id=' . $edit_id);
    }

    if (!$asset_id || !$template_id || !$next_due) {
        flash('error', $rtl ? 'الرجاء تعبئة الحقول المطلوبة' : 'Please fill required fields');
        redirect('/maintenance/pm_schedules.php?action=edit&id=' . $edit_id);
    }

    // Determine executor: is_external flag and which IDs to save
    $save_user = $is_external ? null : ($assigned_to_user_id ?: null);
    $save_contractor = $is_external ? ($contractor_id ?: null) : null;
    if (!$estimated_hours) $estimated_hours = (float)$tpl['estimated_hours'];

    try {
        $pdo->beginTransaction();
        if ($edit_id) {
            $stmt = $pdo->prepare("
                UPDATE pm_schedules SET
                    asset_id=?, template_id=?, is_external=?,
                    assigned_to_user_id=?, contractor_id=?,
                    next_due=?, estimated_hours=?, notes=?, is_active=1
                WHERE id=?
            ");
            $stmt->execute([$asset_id, $template_id, $is_external,
                $save_user, $save_contractor,
                $next_due, $estimated_hours, $notes, $edit_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pm_schedules (asset_id, template_id, is_external,
                    assigned_to_user_id, contractor_id, cycle_days, next_due,
                    estimated_hours, notes, is_active, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
            ");
            $stmt->execute([$asset_id, $template_id, $is_external,
                $save_user, $save_contractor,
                (int)$tpl['cycle_days'], $next_due,
                $estimated_hours, $notes, user_id()]);
            $edit_id = (int)$pdo->lastInsertId();
        }
        $pdo->commit();
        log_activity('pm.schedule_saved', "pm_schedule:$edit_id", json_encode([
            'asset_id' => $asset_id, 'template_id' => $template_id,
            'is_external' => $is_external, 'next_due' => $next_due,
        ]));
        flash('success', $edit_id ? ($rtl ? 'تم تحديث الجدول' : 'Schedule updated') : ($rtl ? 'تم إضافة الجدول' : 'Schedule created'));
        redirect('/maintenance/pm_schedules.php');
    } catch (Exception $e) {
        $pdo->rollBack();
        flash('error', 'خطأ: ' . $e->getMessage());
        redirect('/maintenance/pm_schedules.php?action=edit&id=' . $edit_id);
    }
}

// ===== Load data for edit =====
$sch = null;
$executions = [];
if (($action === 'edit' || $action === 'history') && $id) {
    $stmt = $pdo->prepare("
        SELECT s.*, a.tag_number, a.description, a.manufacturer_name, a.model_number, a.asset_type,
               t.name_ar AS tpl_name, t.name_en AS tpl_name_en, t.cycle_days AS tpl_cycle, t.code AS tpl_code,
               u.full_name AS user_name, c.name AS contractor_name
        FROM pm_schedules s
        INNER JOIN assets a ON a.id = s.asset_id
        LEFT JOIN pm_templates t ON t.id = s.template_id
        LEFT JOIN users u ON u.id = s.assigned_to_user_id
        LEFT JOIN contractors c ON c.id = s.contractor_id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $sch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sch) { flash('error', $rtl ? 'الجدول غير موجود' : 'Schedule not found'); redirect('/maintenance/pm_schedules.php'); }

    // Load executions for history
    $exec_stmt = $pdo->prepare("
        SELECT e.*, u.full_name AS user_name
        FROM pm_executions e
        LEFT JOIN users u ON u.id = e.performed_by_user_id
        WHERE e.schedule_id = ?
        ORDER BY e.completed_date DESC, e.id DESC
    ");
    $exec_stmt->execute([$id]);
    $executions = $exec_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ===== List =====
$filter = $_GET['filter'] ?? 'all'; // all, overdue, due_soon, upcoming, inactive
$executor = $_GET['executor'] ?? 'all'; // all, internal, external
$template_f = (int)($_GET['template'] ?? 0);
$q = trim($_GET['q'] ?? '');

$where = ['1=1'];
$params = [];
if ($filter === 'active') $where[] = 's.is_active = 1';
elseif ($filter === 'inactive') $where[] = 's.is_active = 0';
elseif ($filter === 'overdue') $where[] = 's.is_active = 1 AND s.next_due < CURDATE()';
elseif ($filter === 'due_soon') $where[] = 's.is_active = 1 AND s.next_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
elseif ($filter === 'upcoming') $where[] = 's.is_active = 1 AND s.next_due > DATE_ADD(CURDATE(), INTERVAL 7 DAY)';
else $where[] = 's.is_active = 1';

if ($executor === 'internal') $where[] = 's.is_external = 0';
elseif ($executor === 'external') $where[] = 's.is_external = 1';

if ($template_f) {
    $where[] = 's.template_id = ?';
    $params[] = $template_f;
}

if ($q !== '') {
    $where[] = '(a.tag_number LIKE ? OR a.description LIKE ? OR a.manufacturer_name LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

$where_sql = implode(' AND ', $where);

$sql = "
    SELECT s.*, a.tag_number, a.description, a.manufacturer_name, a.asset_type,
           t.name_ar AS tpl_name, t.name_en AS tpl_name_en, t.code AS tpl_code,
           t.cycle_days AS tpl_cycle, t.estimated_hours AS tpl_hours, t.internal_or_external AS tpl_exec,
           u.full_name AS user_name, c.name AS contractor_name,
           DATEDIFF(s.next_due, CURDATE()) AS days_diff
    FROM pm_schedules s
    INNER JOIN assets a ON a.id = s.asset_id
    LEFT JOIN pm_templates t ON t.id = s.template_id
    LEFT JOIN users u ON u.id = s.assigned_to_user_id
    LEFT JOIN contractors c ON c.id = s.contractor_id
    WHERE $where_sql
    ORDER BY
        CASE WHEN s.next_due < CURDATE() THEN 0
             WHEN s.next_due <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1
             ELSE 2 END,
        s.next_due ASC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// KPIs
$kpis = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN is_active=1 AND next_due < CURDATE() THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN is_active=1 AND next_due BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due_soon,
        SUM(CASE WHEN is_external=1 AND is_active=1 THEN 1 ELSE 0 END) AS external,
        SUM(CASE WHEN is_external=0 AND is_active=1 THEN 1 ELSE 0 END) AS internal
    FROM pm_schedules
")->fetch(PDO::FETCH_ASSOC);

// For form: assets + templates + users + contractors
$templates = $pdo->query("SELECT id, code, name_ar, name_en, cycle_days, estimated_hours, internal_or_external, asset_type FROM pm_templates WHERE is_active=1 ORDER BY name_ar")->fetchAll(PDO::FETCH_ASSOC);
$maintainers = $pdo->query("
    SELECT u.id, u.full_name
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE u.is_active=1 AND r.name IN ('admin','dept_manager','site_manager','executive')
    ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);
$contractors = $pdo->query("SELECT id, name, service_type, is_internal FROM contractors WHERE is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$EXEC_LABELS = ['internal' => $rtl?'داخلي':'Internal', 'external' => $rtl?'خارجي':'External', 'both' => $rtl?'كلاهما':'Both'];

function status_badge(int $days_diff, bool $is_active, bool $rtl): array {
    if (!$is_active) return ['class' => 'inactive', 'label' => $rtl?'متوقف':'Inactive'];
    if ($days_diff < 0) {
        $abs_d = abs($days_diff);
        return ['class' => 'overdue', 'label' => $rtl?($abs_d.' يوم تأخير'):($abs_d.'d late')];
    }
    if ($days_diff <= 7) return ['class' => 'soon', 'label' => $rtl?('خلال '.$days_diff.' يوم'):('in '.$days_diff.'d')];
    return ['class' => 'ok', 'label' => $rtl?('خلال '.$days_diff.' يوم'):('in '.$days_diff.'d')];
}
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
    <meta charset="UTF-8">
    <title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        body { font-family:'Tajawal',sans-serif; background:#f8fafc; }
        .container { max-width: 1400px; margin: 0 auto; padding: 18px; }
        .hdr { background:linear-gradient(135deg,#0f3460,#1a5276); color:#fff; border-radius:16px; padding:22px 28px; margin-bottom:14px; display:flex; align-items:center; gap:18px; box-shadow:0 8px 24px rgba(15,52,96,.18); }
        .hdr-ico { width:60px; height:60px; background:rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:26px; }
        .hdr h1 { font-size:22px; font-weight:900; margin:0 0 4px; }
        .hdr p { font-size:13px; opacity:.92; margin:0; }
        .hdr .v { margin-inline-start:auto; font-size:32px; font-weight:900; opacity:.95; }
        .kpis { display:grid; grid-template-columns:repeat(5, 1fr); gap:10px; margin-bottom:14px; }
        .kpi { background:#fff; border-radius:12px; padding:14px 16px; border:1.5px solid #e2e8f0; display:flex; align-items:center; gap:10px; }
        .kpi .ico { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .kpi .val { font-size:22px; font-weight:900; color:#0f172a; line-height:1; }
        .kpi .lbl { font-size:11.5px; color:#64748b; font-weight:700; }
        .topbar { display:flex; align-items:center; gap:10px; margin-bottom:12px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:8px; font-weight:700; font-size:12.5px; text-decoration:none; cursor:pointer; border:none; }
        .btn-primary { background:#0f3460; color:#fff; }
        .btn-primary:hover { background:#1a5276; }
        .btn-ghost { background:#fff; color:#475569; border:1px solid #e2e8f0; }
        .btn-ghost:hover { background:#f1f5f9; }
        .btn-success { background:#16a34a; color:#fff; }
        .btn-success:hover { background:#15803d; }
        .btn-warn { background:#f59e0b; color:#fff; }
        .btn-danger { background:#dc2626; color:#fff; }
        .btn-sm { padding:4px 10px; font-size:11.5px; }
        .flash { padding:10px 16px; border-radius:8px; margin-bottom:12px; font-size:13px; }
        .flash-success { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .flash-error { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        .tabs { display:flex; gap:6px; background:#fff; padding:8px; border-radius:12px; border:1.5px solid #e2e8f0; margin-bottom:12px; flex-wrap:wrap; }
        .tab { padding:8px 16px; border-radius:8px; font-weight:700; font-size:12.5px; color:#475569; text-decoration:none; background:#f8fafc; display:flex; align-items:center; gap:6px; }
        .tab.active { background:#0f3460; color:#fff; }
        .tab:hover:not(.active) { background:#e2e8f0; }
        .tab .ct { background:rgba(0,0,0,.12); padding:1px 7px; border-radius:99px; font-size:11px; font-weight:800; }
        .tab.active .ct { background:rgba(255,255,255,.25); }

        .sec { background:#fff; border:1.5px solid #e2e8f0; border-radius:12px; overflow:hidden; margin-bottom:12px; }
        .sec-h { font-size:14px; font-weight:900; padding:13px 18px; display:flex; align-items:center; gap:8px; border-bottom:1.5px solid #f1f5f9; background:#f8fafc; }
        .sec-h .ic { color:#0f3460; }
        .sec-h .ct { margin-inline-start:auto; font-size:11.5px; color:#94a3b8; font-weight:700; }
        table { width:100%; border-collapse:collapse; font-size:12.5px; }
        th { text-align:start; padding:9px 12px; color:#94a3b8; font-weight:800; font-size:11px; text-transform:uppercase; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
        td { padding:10px 12px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        tr:hover td { background:#fafbff; }
        tr.row-overdue td { background:#fef2f2; }
        tr.row-overdue:hover td { background:#fee2e2; }
        tr.row-soon td { background:#fffbeb; }
        tr.row-soon:hover td { background:#fef3c7; }

        .pill { display:inline-block; padding:2px 8px; border-radius:5px; font-size:10.5px; font-weight:800; }
        .pill.overdue { background:#dc262622; color:#dc2626; }
        .pill.soon { background:#f59e0b22; color:#b45309; }
        .pill.ok { background:#16a34a22; color:#15803d; }
        .pill.inactive { background:#94a3b822; color:#475569; }
        .pill.internal { background:#7c3aed22; color:#6d28d9; }
        .pill.external { background:#0891b222; color:#0e7490; }

        .empty { padding:30px; text-align:center; color:#94a3b8; }
        .code { font-family:'IBM Plex Mono',monospace; font-weight:800; color:#0f3460; }

        /* Edit form */
        .form-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:12px; padding:18px; }
        .field { display:flex; flex-direction:column; gap:4px; }
        .field.full { grid-column:1 / span 2; }
        .field label { font-size:12px; font-weight:800; color:#475569; }
        .field .req { color:#dc2626; }
        .field input, .field select, .field textarea { padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-family:inherit; font-size:13px; }
        .field input:focus, .field select:focus, .field textarea:focus { outline:none; border-color:#0f3460; }
        .form-actions { display:flex; gap:8px; padding:14px 18px; border-top:1.5px solid #f1f5f9; background:#f8fafc; }
        .info-banner { background:#eef2ff; border:1.5px solid #c7d2fe; color:#3730a3; padding:12px 16px; border-radius:10px; margin-bottom:12px; font-size:12.5px; display:flex; align-items:center; gap:8px; }

        /* History */
        .exec-row { padding:12px 18px; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .exec-row:last-child { border-bottom:0; }
        .exec-date { font-weight:800; color:#0f172a; }
        .exec-status { padding:3px 9px; border-radius:5px; font-size:11px; font-weight:800; }
        .exec-status.completed { background:#16a34a22; color:#15803d; }
        .exec-status.partial { background:#f59e0b22; color:#b45309; }

        @media (max-width: 900px) {
            .kpis { grid-template-columns:repeat(2, 1fr); }
            .form-grid { grid-template-columns:1fr; }
            .field.full { grid-column:1; }
        }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <div class="hdr">
        <div class="hdr-ico"><i class="fa-solid fa-calendar-check"></i></div>
        <div>
            <h1><?= $rtl?'جداول الصيانة الوقائية':'PM Schedules' ?></h1>
            <p><?= $rtl?'ربط الأجهزة بالقوالب + تعيين المنفذ + تتبع المواعيد':'Bind assets to templates, assign executors, track due dates' ?></p>
        </div>
        <div class="v"><?= (int)$kpis['total'] ?></div>
    </div>

    <?php if ($f = get_flash()): ?>
        <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endif; ?>

    <?php if ($action === 'list' || $action === 'history'): ?>

        <!-- KPIs -->
        <div class="kpis">
            <div class="kpi">
                <div class="ico" style="background:#0f346022;color:#0f3460"><i class="fa-solid fa-list"></i></div>
                <div><div class="val"><?= (int)$kpis['total'] ?></div><div class="lbl"><?= $rtl?'إجمالي':'Total' ?></div></div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:#dc262622;color:#dc2626"><i class="fa-solid fa-circle-exclamation"></i></div>
                <div><div class="val"><?= (int)$kpis['overdue'] ?></div><div class="lbl"><?= $rtl?'متأخر':'Overdue' ?></div></div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:#f59e0b22;color:#b45309"><i class="fa-solid fa-bell"></i></div>
                <div><div class="val"><?= (int)$kpis['due_soon'] ?></div><div class="lbl"><?= $rtl?'خلال 7 أيام':'Due in 7d' ?></div></div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:#7c3aed22;color:#6d28d9"><i class="fa-solid fa-user-gear"></i></div>
                <div><div class="val"><?= (int)$kpis['internal'] ?></div><div class="lbl"><?= $rtl?'داخلي':'Internal' ?></div></div>
            </div>
            <div class="kpi">
                <div class="ico" style="background:#0891b222;color:#0e7490"><i class="fa-solid fa-truck"></i></div>
                <div><div class="val"><?= (int)$kpis['external'] ?></div><div class="lbl"><?= $rtl?'خارجي':'External' ?></div></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <?php
            $tab_defs = [
                'all' => [$rtl?'الكل':'All', 'fa-list', (int)$kpis['total']],
                'overdue' => [$rtl?'متأخر':'Overdue', 'fa-circle-exclamation', (int)$kpis['overdue']],
                'due_soon' => [$rtl?'خلال 7 أيام':'Due in 7d', 'fa-bell', (int)$kpis['due_soon']],
                'upcoming' => [$rtl?'قادمة':'Upcoming', 'fa-calendar', 0],
                'inactive' => [$rtl?'متوقفة':'Inactive', 'fa-pause', 0],
            ];
            $qs_base = http_build_query(array_filter([
                'executor' => $executor !== 'all' ? $executor : null,
                'template' => $template_f ?: null,
                'q' => $q ?: null,
            ]));
            foreach ($tab_defs as $k => [$lbl, $icon, $ct]):
                $active = $filter === $k ? 'active' : '';
                $qs = 'filter=' . $k . ($qs_base ? '&' . $qs_base : '');
            ?>
                <a href="?<?= e($qs) ?>" class="tab <?= $active ?>">
                    <i class="fa-solid <?= $icon ?>"></i> <?= $lbl ?>
                    <?php if ($ct > 0): ?><span class="ct"><?= $ct ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Filters -->
        <form method="get" class="topbar" style="background:#fff;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:12px;margin-bottom:12px">
            <input type="hidden" name="filter" value="<?= e($filter) ?>">
            <input type="hidden" name="executor" value="<?= e($executor) ?>">
            <div style="display:flex;align-items:center;gap:8px;flex:1;flex-wrap:wrap">
                <div class="field" style="min-width:200px;flex:1">
                    <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= $rtl?'بحث: tag / اسم / مصنع':'Search: tag / name / manufacturer' ?>" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px">
                </div>
                <div class="field" style="min-width:200px">
                    <select name="template" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px">
                        <option value="0"><?= $rtl?'كل القوالب':'All templates' ?></option>
                        <?php foreach ($templates as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $template_f===(int)$t['id']?'selected':'' ?>>
                                <?= e($t['name_ar']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" style="min-width:140px">
                    <select name="executor" style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px">
                        <option value="all"><?= $rtl?'كل المنفذين':'All executors' ?></option>
                        <option value="internal" <?= $executor==='internal'?'selected':'' ?>><?= $rtl?'داخلي':'Internal' ?></option>
                        <option value="external" <?= $executor==='external'?'selected':'' ?>><?= $rtl?'خارجي':'External' ?></option>
                    </select>
                </div>
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
                <?php if ($can_edit): ?>
                    <a href="<?= BASE_URL ?>/maintenance/pm_schedules.php?action=edit" class="btn btn-success"><i class="fa-solid fa-plus"></i> <?= $rtl?'جدول جديد':'New Schedule' ?></a>
                <?php endif; ?>
            </div>
        </form>

        <!-- History view (per schedule) -->
        <?php if ($action === 'history' && $sch): ?>
            <div class="sec">
                <div class="sec-h">
                    <i class="fa-solid fa-clock-rotate-left ic"></i>
                    <?= $rtl?'سجل التنفيذ لـ ':'Execution history for: ' ?>
                    <span class="code" style="margin-inline-start:6px"><?= e($sch['tag_number']) ?></span>
                    <a href="<?= BASE_URL ?>/maintenance/pm_schedules.php?schedule=<?= (int)$sch['id'] ?>" class="btn btn-ghost btn-sm" style="margin-inline-start:auto"><?= $rtl?'رجوع':'Back' ?></a>
                </div>
                <?php if (!$executions): ?>
                    <div class="empty"><?= $rtl?'لا توجد تنفيذات سابقة':'No executions yet' ?></div>
                <?php else: foreach ($executions as $ex): ?>
                    <div class="exec-row">
                        <span class="exec-date"><?= e($ex['completed_date'] ?? '—') ?></span>
                        <span class="exec-status <?= e($ex['status']) ?>"><?= e($ex['status']) ?></span>
                        <span style="font-size:11.5px;color:#64748b"><?= e($ex['user_name'] ?? $ex['performed_by_contractor'] ?? '—') ?></span>
                        <span style="font-size:11.5px;color:#94a3b8"><?= (float)$ex['hours_spent'] ?>h</span>
                        <?php if ($ex['notes']): ?>
                            <span style="font-size:11.5px;color:#475569;flex:1;min-width:200px"><?= e(truncate($ex['notes'], 80)) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        <?php endif; ?>

        <!-- List -->
        <div class="sec">
            <div class="sec-h">
                <i class="fa-solid fa-table ic"></i>
                <?= $rtl?'الجداول':'Schedules' ?>
                <span class="ct"><?= count($rows) ?> <?= $rtl?'صف':'rows' ?></span>
            </div>
            <?php if (!$rows): ?>
                <div class="empty">
                    <?= $rtl?'لا توجد جداول. ابدأ بإنشاء أول جدول صيانة وقائية.':'No schedules yet. Create your first PM schedule to begin.' ?>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th><?= $rtl?'الجهاز':'Asset' ?></th>
                            <th><?= $rtl?'القالب':'Template' ?></th>
                            <th><?= $rtl?'المنفذ':'Executor' ?></th>
                            <th><?= $rtl?'الموعد':'Next Due' ?></th>
                            <th><?= $rtl?'آخر تنفيذ':'Last' ?></th>
                            <th><?= $rtl?'العدد':'Count' ?></th>
                            <th><?= $rtl?'الحالة':'Status' ?></th>
                            <th><?= $rtl?'إجراءات':'Actions' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $sb = status_badge((int)$r['days_diff'], (bool)$r['is_active'], $rtl);
                        $row_cls = $r['days_diff'] < 0 ? 'row-overdue' : ($r['days_diff'] <= 7 ? 'row-soon' : '');
                    ?>
                        <tr class="<?= $row_cls ?>">
                            <td>
                                <span class="code"><?= e($r['tag_number']) ?></span>
                                <div style="font-size:11px;color:#64748b;margin-top:2px"><?= e(truncate($r['description'] ?? '', 50)) ?></div>
                                <div style="font-size:10.5px;color:#94a3b8"><?= e($r['manufacturer'] ?? '') ?></div>
                            </td>
                            <td>
                                <strong><?= e($r['tpl_name'] ?? '—') ?></strong>
                                <div style="font-size:10.5px;color:#94a3b8"><?= (int)$r['tpl_cycle'] ?>d · <?= (float)$r['tpl_hours'] ?>h</div>
                            </td>
                            <td>
                                <?php if ($r['is_external']): ?>
                                    <span class="pill external"><i class="fa-solid fa-truck"></i> <?= e(truncate($r['contractor_name'] ?? '—', 25)) ?></span>
                                <?php else: ?>
                                    <span class="pill internal"><i class="fa-solid fa-user-gear"></i> <?= e(truncate($r['user_name'] ?? '—', 25)) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><strong style="color:<?= $r['days_diff']<0?'#dc2626':($r['days_diff']<=7?'#b45309':'#15803d') ?>"><?= e($r['next_due']) ?></strong></td>
                            <td style="font-size:11.5px;color:#64748b"><?= e($r['last_completed'] ?? $rtl?'لم ينفذ بعد':'Never') ?></td>
                            <td><strong><?= (int)$r['pm_count'] ?></strong></td>
                            <td><span class="pill <?= $sb['class'] ?>"><?= e($sb['label']) ?></span></td>
                            <td>
                                <?php if ($can_apply): ?>
                                    <a href="<?= BASE_URL ?>/maintenance/pm_execute.php?id=<?= (int)$r['id'] ?>" class="btn btn-success btn-sm" title="<?= $rtl?'تنفيذ':'Execute' ?>"><i class="fa-solid fa-play"></i></a>
                                <?php endif; ?>
                                <a href="?action=history&id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" title="<?= $rtl?'السجل':'History' ?>"><i class="fa-solid fa-clock-rotate-left"></i></a>
                                <?php if ($can_edit): ?>
                                    <a href="?action=edit&id=<?= (int)$r['id'] ?>" class="btn btn-ghost btn-sm" title="<?= $rtl?'تعديل':'Edit' ?>"><i class="fa-solid fa-edit"></i></a>
                                    <a href="?action=toggle&id=<?= (int)$r['id'] ?>" class="btn btn-warn btn-sm" title="<?= $rtl?'إيقاف/تشغيل':'Toggle' ?>" onclick="return confirm('<?= $rtl?'تأكيد تغيير الحالة':'Toggle?' ?>')"><i class="fa-solid fa-power-off"></i></a>
                                <?php endif; ?>
                                <?php if ($can_delete): ?>
                                    <a href="?action=delete&id=<?= (int)$r['id'] ?>" class="btn btn-danger btn-sm" title="<?= $rtl?'حذف':'Delete' ?>" onclick="return confirm('<?= $rtl?'تأكيد الحذف؟ سيُحذف كل سجل التنفيذ':'Delete? All execution history will be removed.' ?>')"><i class="fa-solid fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    <?php endif; ?>

    <?php if ($action === 'edit'): ?>

        <?php
        // For new schedules, load default asset from query
        $default_asset_id = (int)($_GET['asset'] ?? 0);
        $default_tpl_id = (int)($_GET['template'] ?? 0);
        $today = date('Y-m-d');
        $next_due_default = $sch['next_due'] ?? date('Y-m-d', strtotime('+90 days'));
        $is_external_def = $sch ? (int)$sch['is_external'] : 0;
        ?>

        <a href="<?= BASE_URL ?>/maintenance/pm_schedules.php" class="btn btn-ghost" style="margin-bottom:12px"><i class="fa-solid fa-arrow-<?= $rtl?'right':'left' ?>"></i> <?= $rtl?'رجوع':'Back' ?></a>

        <form method="post" action="">
            <input type="hidden" name="id" value="<?= (int)($sch['id'] ?? 0) ?>">
            <div class="sec">
                <div class="sec-h">
                    <i class="fa-solid fa-edit ic"></i>
                    <?= $sch ? ($rtl?'تعديل الجدول':'Edit Schedule') : ($rtl?'جدول جديد':'New Schedule') ?>
                </div>

                <div class="info-banner">
                    <i class="fa-solid fa-circle-info"></i>
                    <div>
                        <?= $rtl?'اختر الجهاز + القالب. القالب يحدد الدورة (يوم) والوقت المقدر. الموعد التالي يُحسب تلقائياً بعد كل تنفيذ.':'Pick asset + template. Template defines cycle (days) and est. hours. Next due auto-calculates after each execution.' ?>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="field full">
                        <label><?= $rtl?'الجهاز':'Asset' ?> <small style="color:#94a3b8;font-weight:400">(<?= $rtl?'tag أو asset#':'tag or asset#' ?>)</small> <span class="req">*</span></label>
                        <input type="text" name="asset_ref" id="asset_ref" required
                               value="<?= e($sch['tag_number'] ?? ($sch['asset_number'] ?? '')) ?>"
                               placeholder="<?= $rtl?'BHC002000001 أو 679714':'BHC002000001 or 679714' ?>"
                               autocomplete="off"
                               style="padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:13px">
                        <input type="hidden" name="asset_id" id="asset_id_hidden" value="<?= (int)($sch['asset_id'] ?? 0) ?>">
                        <div id="asset_info" style="margin-top:6px;font-size:11.5px;color:#475569;padding:6px 10px;background:#f8fafc;border-radius:6px;display:none"></div>
                        <small style="color:#94a3b8;font-size:11px"><?= $rtl?'يكتب tag أو asset# — تظهر بيانات الجهاز تلقائياً':'Type tag or asset# — device info appears automatically' ?></small>
                    </div>

                    <div class="field full">
                        <label><?= $rtl?'القالب':'Template' ?> <span class="req">*</span></label>
                        <select name="template_id" required>
                            <option value=""><?= $rtl?'اختر القالب':'Select template' ?></option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= ((int)($sch['template_id'] ?? $default_tpl_id)===(int)$t['id'])?'selected':'' ?>>
                                    <?= e($t['name_ar']) ?> · <?= (int)$t['cycle_days'] ?>d · <?= (float)$t['estimated_hours'] ?>h · <?= $EXEC_LABELS[$t['internal_or_external']] ?? $t['internal_or_external'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field full">
                        <label style="display:flex;align-items:center;gap:6px;padding:4px 0">
                            <input type="checkbox" name="is_external" value="1" id="is_external" <?= $is_external_def?'checked':'' ?> style="width:auto">
                            <span><?= $rtl?'تنفيذ خارجي (مقاول)':'External contractor' ?></span>
                        </label>
                    </div>

                    <div class="field" id="userField" style="<?= $is_external_def?'display:none':'' ?>">
                        <label><?= $rtl?'المنفذ (موظف)':'Assigned User' ?></label>
                        <select name="assigned_to_user_id">
                            <option value=""><?= $rtl?'اختر موظف الصيانة':'Select maintenance staff' ?></option>
                            <?php foreach ($maintainers as $m): ?>
                                <option value="<?= (int)$m['id'] ?>" <?= ((int)($sch['assigned_to_user_id'] ?? 0)===(int)$m['id'])?'selected':'' ?>>
                                    <?= e($m['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field" id="contractorField" style="<?= $is_external_def?'':'display:none' ?>">
                        <label><?= $rtl?'المقاول':'Contractor' ?></label>
                        <div style="display:flex;gap:6px">
                            <select name="contractor_id" id="contractor_id" style="flex:1">
                                <option value=""><?= $rtl?'اختر المقاول':'Select contractor' ?></option>
                                <?php foreach ($contractors as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= ((int)($sch['contractor_id'] ?? 0)===(int)$c['id'])?'selected':'' ?>>
                                        <?= e($c['name']) ?> (<?= e($c['service_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="add_contractor_btn" class="btn btn-ghost" onclick="addContractorInline()" title="<?= $rtl?'إضافة مقاول جديد':'Add new contractor' ?>" style="padding:8px 12px;white-space:nowrap">
                                <i class="fa-solid fa-plus"></i> <?= $rtl?'جديد':'New' ?>
                            </button>
                        </div>
                        <div id="add_contractor_form" style="display:none;margin-top:8px;padding:10px;background:#eff6ff;border:1.5px solid #bfdbfe;border-radius:8px">
                            <div style="display:grid;grid-template-columns:1fr 1fr 110px;gap:6px;margin-bottom:6px">
                                <input type="text" id="new_contractor_name" placeholder="<?= $rtl?'اسم المقاول':'Contractor name' ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
                                <input type="text" id="new_contractor_type" placeholder="<?= $rtl?'نوع الخدمة':'Service type' ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px" value="<?= $rtl?'صيانة وقائية':'Preventive Maintenance' ?>">
                                <input type="text" id="new_contractor_phone" placeholder="<?= $rtl?'هاتف':'Phone' ?>" style="padding:6px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:12px">
                            </div>
                            <div style="display:flex;gap:6px">
                                <button type="button" class="btn btn-success btn-sm" onclick="saveContractorInline()"><i class="fa-solid fa-check"></i> <?= $rtl?'حفظ':'Save' ?></button>
                                <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('add_contractor_form').style.display='none'"><?= $rtl?'إلغاء':'Cancel' ?></button>
                                <span id="new_contractor_msg" style="font-size:11.5px;align-self:center"></span>
                            </div>
                        </div>
                    </div>

                    <div class="field">
                        <label><?= $rtl?'الموعد القادم':'Next Due' ?> <span class="req">*</span></label>
                        <input type="date" name="next_due" required value="<?= e($next_due_default) ?>" min="<?= e($today) ?>">
                    </div>

                    <div class="field">
                        <label><?= $rtl?'الوقت المقدر (ساعة)':'Est. Hours' ?></label>
                        <input type="number" name="estimated_hours" step="0.1" min="0"
                               value="<?= e($sch['estimated_hours'] ?? '') ?>">
                    </div>

                    <div class="field full">
                        <label><?= $rtl?'ملاحظات':'Notes' ?></label>
                        <textarea name="notes" rows="2" placeholder="<?= $rtl?'أي ملاحظات إضافية':'Any additional notes' ?>"><?= e($sch['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save"></i> <?= $rtl?'حفظ':'Save' ?></button>
                <a href="<?= BASE_URL ?>/maintenance/pm_schedules.php" class="btn btn-ghost"><?= $rtl?'إلغاء':'Cancel' ?></a>
            </div>
        </form>

        <script>
        document.getElementById('is_external').addEventListener('change', function() {
            const userField = document.getElementById('userField');
            const contractorField = document.getElementById('contractorField');
            if (this.checked) {
                userField.style.display = 'none';
                contractorField.style.display = '';
            } else {
                userField.style.display = '';
                contractorField.style.display = 'none';
            }
        });

        // FIX 2026-07-31: Add new contractor inline
        const JS_BASE = "<?= e(BASE_URL) ?>";
        function addContractorInline() {
            document.getElementById('add_contractor_form').style.display = 'block';
            document.getElementById('new_contractor_name').focus();
        }
        function saveContractorInline() {
            const name = document.getElementById('new_contractor_name').value.trim();
            const type = document.getElementById('new_contractor_type').value.trim();
            const phone = document.getElementById('new_contractor_phone').value.trim();
            const msg = document.getElementById('new_contractor_msg');
            if (!name) { msg.style.color = '#dc2626'; msg.textContent = 'الاسم مطلوب'; return; }
            msg.style.color = '#475569'; msg.textContent = '... جاري الحفظ';
            const fd = new FormData();
            fd.append('name', name);
            fd.append('service_type', type || 'general');
            fd.append('phone', phone);
            fetch(JS_BASE + '/api/contractor_add.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data && data.id) {
                        const opt = document.createElement('option');
                        opt.value = data.id; opt.textContent = data.name + ' (' + (data.service_type || '') + ')';
                        opt.selected = true;
                        document.getElementById('contractor_id').appendChild(opt);
                        msg.style.color = '#16a34a'; msg.textContent = '✓ تم الحفظ';
                        document.getElementById('new_contractor_name').value = '';
                        document.getElementById('new_contractor_phone').value = '';
                        setTimeout(() => { document.getElementById('add_contractor_form').style.display = 'none'; msg.textContent = ''; }, 1500);
                    } else {
                        msg.style.color = '#dc2626'; msg.textContent = '⚠ ' + (data && data.error || 'فشل');
                    }
                })
                .catch(e => { msg.style.color = '#dc2626'; msg.textContent = '⚠ ' + e.message; });
        }

        // FIX 2026-07-31: Auto-fill device info on tag/asset# change
        (function() {
            const input = document.getElementById('asset_ref');
            const info = document.getElementById('asset_info');
            const hidden = document.getElementById('asset_id_hidden');
            if (!input || !info || !hidden) return;
            let t;
            input.addEventListener('input', function() {
                clearTimeout(t);
                const q = input.value.trim();
                if (q.length < 2) { info.style.display = 'none'; hidden.value = '0'; return; }
                t = setTimeout(() => {
                    fetch(JS_BASE + '/api/asset_lookup.php?q=' + encodeURIComponent(q))
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.id) {
                                hidden.value = data.id;
                                info.style.display = 'block';
                                info.innerHTML = '<b style="color:#0f3460">' + (data.tag_number || '—') + '</b> · ' +
                                    '<span style="color:#475569">' + (data.description || '').substring(0, 80) + '</span><br>' +
                                    '<small style="color:#64748b">asset#: ' + (data.asset_number || '—') + ' · ' +
                                    'status: ' + (data.status || '—') + ' · ' +
                                    'custodian: ' + (data.custodian || '—') + '</small>';
                            } else {
                                info.style.display = 'block';
                                info.innerHTML = '<span style="color:#dc2626">⚠ ' + (data && data.error || 'لم يُعثر على الأصل') + '</span>';
                                hidden.value = '0';
                            }
                        })
                        .catch(e => {
                            info.style.display = 'block';
                            info.innerHTML = '<span style="color:#dc2626">⚠ خطأ: ' + e.message + '</span>';
                        });
                }, 300);
            });
            // Trigger initial load if value exists
            if (input.value.trim().length >= 2) input.dispatchEvent(new Event('input'));
        })();
        </script>

    <?php endif; ?>

</div>
</main>
</div>
</body>
</html>
