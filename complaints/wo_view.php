<?php
/**
 * complaints/wo_view.php — أمر العمل (إعادة بناء كاملة)
 * الشركة المتعاقدة: تضيف تحديثات + تعبئ النموذج الرسمي (متضمن قطع الغيار) + تسلّم
 * مدير الصيانة: يتابع + يعتمد بتوقيعه
 * الجميع: يرى السجل الزمني الموحَّد وجدول القطع المغلق للقراءة فقط
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('work_orders.view');

if (!function_exists('notify_sys')) {
    function notify_sys($pdo, $target_uid, $type, $title, $body, $cid, $link = null) {
        try {
            if (!$target_uid) return;
            $link = $link ?? (BASE_URL . '/complaints/wo_view.php?id=' . $cid);
            $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$target_uid, $type, $title, $body, $link, 'complaint', $cid]);
        } catch (Exception $e) {}
    }
}

$uid = (int) current_user()['id'];
$can_manage    = can('work_orders.view','approve');
$_cu           = current_user();
$is_contractor = !empty($_cu['contractor_id'])
    || (($_cu['primary_role']['name'] ?? '') === 'company_employee');

$id = (int) ($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); die('معرف أمر العمل مفقود'); }

function loadWO($pdo, $id): array|false {
    $s = $pdo->prepare("
        SELECT wo.*,
               c.id AS c_id, c.request_number, c.description AS complaint_desc,
               c.status AS complaint_status, c.priority, c.created_at AS c_created,
               c.requested_by, c.sla_paused_at, c.sla_paused_seconds_total,
               c.asset_id, c.request_type,
               a.description AS asset_desc, a.tag_number, a.manufacturer_name,
               a.model_number, a.serial_number, a.criticality_class,
               a.date_placed_in_service, a.asset_number,
               d.name AS dept_name,
               u.full_name AS requester_name,
               con.contract_end AS con_contract_end, con.name AS con_name_official,
               con.is_internal AS con_is_internal,
               au.full_name AS assigned_name
        FROM complaint_work_orders wo
        JOIN complaints c ON c.id = wo.complaint_id
        LEFT JOIN assets a ON a.id = c.asset_id
        LEFT JOIN departments d ON d.id = c.dept_id
        LEFT JOIN users u ON u.id = c.requested_by
        LEFT JOIN contractors con ON con.id = wo.contractor_id
        LEFT JOIN users au ON au.id = wo.assigned_user_id
        WHERE wo.id = ?
    ");
    $s->execute([$id]);
    return $s->fetch();
}

$wo = loadWO($pdo, $id);
if (!$wo) { http_response_code(404); die('أمر العمل غير موجود'); }

// التوجيه الداخلي: الموظف المعيَّن يملك على أمره نفس حقوق الشركة
// (تحديثات، النموذج الرسمي، القطع، التسليم) — دون أي حقوق إدارية
if (!$is_contractor && $uid && (int)($wo['assigned_user_id'] ?? 0) === $uid) {
    $is_contractor = true;
}
$cid = (int) $wo['c_id'];
// حالة أمر العمل - إذا كان مغلقاً أو بانتظار الاعتماد يُمنع التعديل
$locked = in_array($wo['status'], ['completed','cancelled','rejected_by_manager']);
$is_it  = (($wo['wo_type'] ?? '') === 'it'); // نموذج تنفيذ مختصر لتقنية المعلومات

// ── معالجة POST ────────────────────────────────────────────────
/**
 * حفظ حقول النموذج الرسمي + قطع الغيار من \$_POST
 * تُستدعى من save_form (مسودة) ومن submit_final (التسليم) —
 * إصلاح جذري: كان التسليم يحفظ التوقيع فقط وتضيع البيانات.
 */
function save_wo_form_fields(PDO $pdo, int $id): void {
    // حفظ بيانات أمر العمل الأساسية
    $pdo->prepare("UPDATE complaint_work_orders SET
        service_power_supply=?, service_electronic=?, service_chemical=?,
        service_planned_maintenance=?, service_calibration=?, service_equipment_fault=?,
        service_spare_parts_required=?, service_rescreening=?, service_spare_parts_stock=?,
        service_description=?, follow_up_notes=?, final_status=?,
        work_hours_day1=?, work_hours_day2=?, work_hours_day3=?,
        work_hours_total=?, work_completed=?,
        contractor_signed_name=?, actual_completion_date=?
        WHERE id=?")
    ->execute([
        !empty($_POST['s_power']),   !empty($_POST['s_elec']),  !empty($_POST['s_chem']),
        !empty($_POST['s_planned']), !empty($_POST['s_calib']), !empty($_POST['s_fault']),
        !empty($_POST['s_parts']),   !empty($_POST['s_rescreen']), !empty($_POST['s_stock']),
        trim($_POST['service_description'] ?? ''),
        trim($_POST['follow_up_notes'] ?? ''),
        $_POST['final_status'] ?? 'pending',
        (float)($_POST['h1']??0), (float)($_POST['h2']??0), (float)($_POST['h3']??0),
        (float)($_POST['h1']??0)+(float)($_POST['h2']??0)+(float)($_POST['h3']??0),
        !empty($_POST['work_completed']),
        trim($_POST['contractor_signed_name'] ?? ''),
        $_POST['actual_completion_date'] ?: null,
        $id,
    ]);

    // حفظ بيانات قطع الغيار (حذف القديم وإدخال الجديد لتجنب التكرار)
    $pdo->prepare("DELETE FROM work_order_items WHERE work_order_id=?")->execute([$id]);
    if (!empty($_POST['part_desc']) && is_array($_POST['part_desc'])) {
        $ins = $pdo->prepare("INSERT INTO work_order_items (work_order_id, description, part_number, quantity, remarks) VALUES (?, ?, ?, ?, ?)");
        foreach ($_POST['part_desc'] as $i => $desc) {
            if (trim($desc) === '') continue; // تجاهل الحقول الفارغة
            $pn  = trim($_POST['part_number'][$i] ?? '');
            $qty = max(1, (int)($_POST['part_qty'][$i] ?? 1));
            $rem = trim($_POST['part_remarks'][$i] ?? '');
            $ins->execute([$id, trim($desc), $pn, $qty, $rem]);
        }
    }

}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { flash('danger','خطأ CSRF.'); header("Location: ?id=$id"); exit; }
    $action = $_POST['action'] ?? '';

    // تحديث الحالة (تحديثات الشركة المتعاقدة)
    if ($action === 'post_update' && $is_contractor && !$locked) {
        $selected = array_filter((array)($_POST['update_chips'] ?? []));
        $note     = trim($_POST['update_note'] ?? '');
        if ($selected || $note) {
            $chip_labels = [
                'started'          => 'بدأ العمل الميداني فعلياً',
                'spare_parts'      => 'جاري توفير قطع غيار',
                'warranty'         => 'يغطيه الضمان — جاري التواصل مع الشركة',
                'external_vendor'  => 'جاري التواصل مع وكيل خارجي لتأمين القطعة',
                'part_unavailable' => 'القطعة غير متوفرة محلياً — البحث مستمر',
                'admin_approval'   => 'بانتظار موافقة إدارية على التكلفة',
                'completed_work'   => 'تم إنجاز العمل — جاهز للتسليم',
            ];
            $labels = array_map(fn($k) => $chip_labels[$k] ?? $k, $selected);
            $full_label = implode(' + ', $labels);
            if ($note) $full_label .= ($full_label ? ' — ' : '') . $note;

            $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'wo_update',?,?,?,?)")
                ->execute([$cid, $full_label, $wo['complaint_status'], $wo['complaint_status'], $uid]);

            $mgr_q = $pdo->prepare("SELECT u.id FROM users u JOIN departments d ON d.id=u.department_id WHERE d.dept_category=? AND u.is_active=1");
            $mgr_q->execute(['maintenance_' . ($wo['request_type'] ?? 'medical')]);
            foreach ($mgr_q->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                notify_sys($pdo, $mid, 'info', 'تحديث على أمر العمل ' . $wo['wo_number'], $full_label, $cid, BASE_URL . '/complaints/wo_view.php?id=' . $id);
            }
            notify_sys($pdo, $wo['requested_by'], 'info', 'تحديث على بلاغك ' . $wo['request_number'], $full_label, $cid, BASE_URL . '/complaints/my.php?id=' . $cid);

            flash('success', 'تم نشر التحديث وإشعار المعنيين.');
        }

    // حفظ النموذج الرسمي (مسودة — لا يُقفَل) + حفظ قطع الغيار
    } elseif ($action === 'save_form' && $is_contractor && !$locked && $wo['status'] !== 'pending_manager_approval') {
        try {
            $pdo->beginTransaction();

            save_wo_form_fields($pdo, $id);
            $pdo->commit();
            flash('success', 'تم حفظ النموذج وقطع الغيار — يمكنك متابعة التعديل حتى التسليم.');
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('danger', 'حدث خطأ أثناء حفظ النموذج: ' . $e->getMessage());
        }

    // تسليم النهائي (قفل + إشعار)
    } elseif ($action === 'submit_final' && $is_contractor && !$locked && $wo['status'] !== 'pending_manager_approval') {
        $con_sig  = trim($_POST['contractor_signature'] ?? '');
        $pdo->beginTransaction();
        try {
            save_wo_form_fields($pdo, $id); // حفظ كامل البيانات قبل الإقفال
            $pdo->prepare("UPDATE complaint_work_orders SET status='pending_manager_approval', contractor_signature=? WHERE id=?")->execute([$con_sig, $id]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('danger', 'تعذر التسليم: ' . $ex->getMessage());
            header("Location: ?id=$id"); exit;
        }
        $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'wo_submitted','الشركة المتعاقدة: تم تسليم أمر العمل للاعتماد',?,?,?)")
            ->execute([$cid, $wo['complaint_status'], $wo['complaint_status'], $uid]);
        
        $mgr_q = $pdo->prepare("SELECT u.id FROM users u JOIN departments d ON d.id=u.department_id WHERE d.dept_category=? AND u.is_active=1");
        $mgr_q->execute(['maintenance_' . ($wo['request_type'] ?? 'medical')]);
        foreach ($mgr_q->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            notify_sys($pdo, $mid, 'success', 'أمر العمل ' . $wo['wo_number'] . ' جاهز للاعتماد', 'أرسلت الشركة المتعاقدة أمر العمل — يرجى المراجعة والاعتماد.', $cid, BASE_URL . '/complaints/wo_view.php?id=' . $id);
        }
        flash('success', 'تم التسليم — بانتظار اعتماد مدير الصيانة.');

    // اعتماد المدير
    } elseif ($action === 'approve' && $can_manage && $wo['status'] === 'pending_manager_approval') {
        $mgr_sig = trim($_POST['manager_signature'] ?? '');
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE complaint_work_orders SET status='completed', approved_by=?, approved_at=NOW(), manager_signature=? WHERE id=?")->execute([$uid, $mgr_sig, $id]);
            if ($wo['sla_paused_at']) {
                $paused = (int)(time() - strtotime($wo['sla_paused_at']));
                $total  = $wo['sla_paused_seconds_total'] + $paused;
                $pdo->prepare("UPDATE complaints SET sla_paused_at=NULL, sla_paused_seconds_total=? WHERE id=?")->execute([$total, $cid]);
            }
            $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'wo_approved','مدير الصيانة: اعتمد أمر العمل — ساعة المهلة استُؤنفت',?,?,?)")
                ->execute([$cid, $wo['complaint_status'], $wo['complaint_status'], $uid]);
            $pdo->commit();
            flash('success', 'تم الاعتماد — ساعة المهلة تعمل مجدداً.');

            // Hook: recompute risk score for the related asset (work order completed)
            // Get the asset_id from the complaint
            $cid_for_risk = (int)$cid;
            if ($cid_for_risk > 0) {
                $asset_for_risk = $pdo->prepare("SELECT asset_id FROM complaints WHERE id = ?");
                $asset_for_risk->execute([$cid_for_risk]);
                $aid = (int)$asset_for_risk->fetchColumn();
                if ($aid > 0) {
                    @require_once BASE_PATH . '/includes/risk_helpers.php';
                    if (function_exists('compute_risk_score')) {
                        compute_risk_score($pdo, $aid, true);
                    }
                }
            }
        } catch (Exception $e) { $pdo->rollBack(); flash('danger', 'خطأ: ' . $e->getMessage()); }

    // رفض المدير
    } elseif ($action === 'reject' && $can_manage && $wo['status'] === 'pending_manager_approval') {
        $note = trim($_POST['rejection_note'] ?? '');
        if ($note) {
            $pdo->prepare("UPDATE complaint_work_orders SET status='rejected_by_manager', rejection_note=? WHERE id=?")->execute([$note, $id]);
            $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'wo_rejected',?,?,?,?)")
                ->execute([$cid, 'رُفض أمر العمل: ' . $note, $wo['complaint_status'], $wo['complaint_status'], $uid]);
            flash('warning', 'تم الرفض.');
        }
    }

    $wo = loadWO($pdo, $id);
    header("Location: ?id=$id"); exit;
}

// ── جلب قطع الغيار ──────────────────────────────────────────────
$items_q = $pdo->prepare("SELECT * FROM work_order_items WHERE work_order_id = ?");
$items_q->execute([$id]);
$wo_items = $items_q->fetchAll(PDO::FETCH_ASSOC);

// ── البيانات المساعدة ──────────────────────────────────────────
$asset_age = '—';
if (!empty($wo['date_placed_in_service'])) {
    $days = (int)((time() - strtotime($wo['date_placed_in_service'])) / 86400);
    $asset_age = $days > 365 ? round($days/365,1) . ' سنة' : $days . ' يوم';
}

$prev_count = 0;
if ($wo['asset_id']) {
    $pq = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE asset_id=? AND id!=?");
    $pq->execute([$wo['asset_id'], $cid]);
    $prev_count = (int) $pq->fetchColumn();
}

$warranty_info = null;
if ($wo['asset_id']) {
    $wq = $pdo->prepare("SELECT * FROM commissioning_certificates WHERE serial_number=? AND status='active' LIMIT 1");
    $wq->execute([$wo['serial_number'] ?? '']);
    $cert = $wq->fetch();
    if ($cert && !empty($cert['warranty_end'])) {
        $days_left = (int)ceil((strtotime($cert['warranty_end']) - time()) / 86400);
        $warranty_info = [
            'active'   => $days_left > 0,
            'days'     => $days_left,
            'end_date' => $cert['warranty_end'],
            'company'  => $cert['supplier_name'] ?? '—',
        ];
    }
}

$tl_q = $pdo->prepare("
    SELECT t.*, u.full_name AS actor_name
    FROM complaint_timeline t
    LEFT JOIN users u ON u.id = t.actor_id
    WHERE t.complaint_id = ?
    ORDER BY t.created_at ASC
");
$tl_q->execute([$cid]);
$timeline = $tl_q->fetchAll();

$WO_STATUS = [
    'draft'                    => ['مسودة','#64748b','#f1f5f9'],
    'sent_to_contractor'       => ['أُرسل للمقاول','#d97706','#fffbeb'],
    'in_progress'              => ['جاري العمل','#2563eb','#eff6ff'],
    'pending_manager_approval' => ['بانتظار الاعتماد','#7c3aed','#f5f3ff'],
    'completed'                => ['مكتمل ومعتمَد','#16a34a','#f0fdf4'],
    'rejected_by_manager'      => ['مرفوض','#dc2626','#fef2f2'],
    'cancelled'                => ['مُلغى','#94a3b8','#f8fafc'],
];
$ws = $WO_STATUS[$wo['status']] ?? ['—','#94a3b8','#f8fafc'];
$SVC = [
    's_power'=>'مصدر الطاقة','s_elec'=>'إلكترونيات','s_chem'=>'كيميائي',
    's_planned'=>'صيانة دورية','s_calib'=>'معايرة','s_fault'=>'عطل معدّات',
    's_parts'=>'قطع غيار مطلوبة','s_rescreen'=>'إعادة فحص','s_stock'=>'قطع مستودع',
];
$SVC_KEYS = [
    's_power'=>'service_power_supply','s_elec'=>'service_electronic','s_chem'=>'service_chemical',
    's_planned'=>'service_planned_maintenance','s_calib'=>'service_calibration','s_fault'=>'service_equipment_fault',
    's_parts'=>'service_spare_parts_required','s_rescreen'=>'service_rescreening','s_stock'=>'service_spare_parts_stock',
];
$TL_ICONS = [
    'created'=>'🔔','acknowledged'=>'👁','in_progress'=>'⚙','wo_created'=>'📋',
    'wo_update'=>'📝','wo_submitted'=>'📬','wo_approved'=>'✅','wo_rejected'=>'❌',
    'escalated'=>'⬆','resolved'=>'🔒','default'=>'•',
];
$flash_msgs = get_flash();
$page_title = 'أمر العمل ' . $wo['wo_number'];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&family=Inter:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--bg:#f1f5f9;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;--primary:#0891b2}
body{background:var(--bg);font-family:'Tajawal',sans-serif}
.eng{font-family:'Inter',sans-serif}
.wrap{max-width:1120px;margin:0 auto;padding:22px}
.bento{background:var(--card);border-radius:18px;border:1px solid var(--border);margin-bottom:14px;overflow:hidden}
.bento-h{padding:12px 18px;font-size:13.5px;font-weight:900;color:#fff;display:flex;align-items:center;gap:8px}
.bento-b{padding:16px 18px}
.s-row{display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px dashed var(--border);font-size:12.5px}
.s-row:last-child{border-bottom:none}
.s-lbl{color:var(--muted);font-weight:800;min-width:80px;font-size:11.5px}
.s-val{font-weight:700;flex:1}
.chip{display:inline-flex;align-items:center;gap:5px;padding:8px 13px;border:1.5px solid var(--border);border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;transition:.2s;user-select:none;background:var(--card)}
.chip:hover{border-color:var(--primary);color:var(--primary)}
.chip.selected{border-color:var(--primary);background:#ecfeff;color:#0e7490}
.chip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:7px;margin-bottom:12px}
.act-btn{width:100%;padding:12px;border-radius:11px;border:none;font-size:13px;font-weight:900;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:9px;transition:.2s}
.act-btn:hover{transform:translateY(-2px)}
.act-btn:disabled{opacity:.45;cursor:not-allowed;transform:none}
.svc-check{display:flex;align-items:center;gap:7px;background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:9px 12px;font-size:12px;font-weight:700;cursor:pointer}
.tl-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px dashed var(--border)}
.tl-item:last-child{border-bottom:none}
.tl-icon{width:30px;height:30px;border-radius:50%;background:#f1f5f9;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0}
.sig-canvas{border:2px dashed var(--border);border-radius:10px;cursor:crosshair;touch-action:none;background:#fafafa}
.flash{padding:11px 16px;border-radius:10px;margin-bottom:12px;font-weight:800;font-size:13px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<?php foreach ($flash_msgs as $fm): $fc=['success'=>'#10b981','warning'=>'#f59e0b','danger'=>'#ef4444','info'=>'#3b82f6'][$fm['type']]??'#3b82f6'; ?>
<div class="flash" style="background:#fff;border:1px solid <?=$fc?>44;border-right:4px solid <?=$fc?>"><?=e($fm['message'])?></div>
<?php endforeach; ?>

<!-- رأس أمر العمل -->
<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#0f172a,#1e3a8a);justify-content:space-between;flex-wrap:wrap">
        <span class="eng" style="font-size:17px"><?=e($wo['wo_number'])?></span>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <?php if ($wo['sla_paused_at']): ?>
            <span style="background:rgba(251,191,36,.2);color:#fbbf24;padding:4px 11px;border-radius:99px;font-size:11px;font-weight:800"><i class="fa-solid fa-pause"></i> SLA مُجمَّد</span>
            <?php endif; ?>
            <span style="background:<?=$ws[2]?>;color:<?=$ws[1]?>;padding:5px 14px;border-radius:99px;font-size:12px;font-weight:900"><?=$ws[0]?></span>
            <a href="<?=BASE_URL?>/complaints/wo_print.php?id=<?=$id?>" target="_blank" style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:5px 12px;border-radius:8px;font-size:11.5px;font-weight:800;text-decoration:none"><i class="fa-solid fa-print"></i> طباعة</a>
            <a href="<?=BASE_URL?>/complaints/view.php?id=<?=$cid?>" style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:5px 12px;border-radius:8px;font-size:11.5px;font-weight:800;text-decoration:none"><i class="fa-solid fa-arrow-up-right-from-square"></i> البلاغ</a>
        </div>
    </div>
    <div class="bento-b">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;margin-bottom:12px">
            <div><div style="font-size:10.5px;color:var(--muted);font-weight:800">البلاغ</div><div style="font-weight:800;font-size:13px;font-family:'Inter'"><?=e($wo['request_number'])?></div></div>
            <div><div style="font-size:10.5px;color:var(--muted);font-weight:800">القسم</div><div style="font-weight:800;font-size:13px"><?=e($wo['dept_name']??'—')?></div></div>
            <div><div style="font-size:10.5px;color:var(--muted);font-weight:800">المُبلِّغ</div><div style="font-weight:800;font-size:13px"><?=e($wo['requester_name']??'—')?></div></div>
            <div><div style="font-size:10.5px;color:var(--muted);font-weight:800">الأولوية</div>
                <div style="font-weight:800;font-size:13px;color:<?=['normal'=>'#16a34a','urgent'=>'#d97706','critical'=>'#dc2626'][$wo['priority']]??'#64748b'?>">
                <?=['normal'=>'عادي','urgent'=>'عاجل','critical'=>'طارئ'][$wo['priority']]?></div></div>
            <div><div style="font-size:10.5px;color:var(--muted);font-weight:800">تاريخ البلاغ</div><div style="font-weight:800;font-size:12px;font-family:'Inter'"><?=e(date('Y-m-d H:i',strtotime($wo['c_created'])))?></div></div>
            <div><div style="font-size:10.5px;color:var(--muted);font-weight:800">بلاغات سابقة</div>
                <div style="font-weight:900;font-size:13px;color:<?=$prev_count>5?'#dc2626':($prev_count>2?'#d97706':'#16a34a')?>"><?=$prev_count?> بلاغ</div></div>
        </div>
        <div style="background:#f8fafc;border-right:3px solid #0891b2;border-radius:0 8px 8px 0;padding:11px 14px;font-size:13px;font-weight:700;color:#334155;margin-bottom:12px">
            <?=nl2br(e($wo['complaint_desc']))?>
        </div>
        <?php if (!empty($wo['manager_instructions'])): ?>
        <div style="font-size:11.5px;color:var(--muted);font-weight:700"><i class="fa-solid fa-note-sticky"></i> توجيهات المدير: <?=e($wo['manager_instructions'])?></div>
        <?php endif; ?>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
<div class="bento" style="margin-bottom:0">
    <div class="bento-h" style="background:linear-gradient(135deg,#475569,#64748b)"><i class="fa-solid fa-microchip"></i> بطاقة الجهاز</div>
    <div class="bento-b" style="padding:14px 16px">
        <div class="s-row"><span class="s-lbl">الجهاز</span><span class="s-val" style="font-size:12px"><?=e($wo['asset_desc']??'—')?></span></div>
        <div class="s-row"><span class="s-lbl">رقم الأصل</span><span class="s-val eng" style="font-size:12px;color:#64748b"><?=e($wo['asset_number']??'—')?></span></div>
        <div class="s-row"><span class="s-lbl">التاج</span><span class="s-val eng" style="font-size:13px;color:#0891b2;font-weight:900"><?=e($wo['tag_number']??'—')?></span></div>
        <div class="s-row"><span class="s-lbl">السيريال</span><span class="s-val eng" style="font-size:12px;font-weight:900"><?=e($wo['serial_number']??'—')?></span></div>
        <div class="s-row"><span class="s-lbl">الشركة</span><span class="s-val" style="font-size:12px"><?=e($wo['manufacturer_name']??'—')?></span></div>
        <div class="s-row"><span class="s-lbl">الموديل</span><span class="s-val eng" style="font-size:12px"><?=e($wo['model_number']??'—')?></span></div>
        <div class="s-row"><span class="s-lbl">العمر</span><span class="s-val"><?=$asset_age?></span></div>
        <div class="s-row"><span class="s-lbl">الفئة</span>
            <span class="s-val" style="color:<?=['A'=>'#dc2626','B'=>'#d97706','C'=>'#16a34a'][$wo['criticality_class']??'B']??'#64748b';?>;font-weight:900">
            فئة <?=e($wo['criticality_class']??'—')?></span></div>
    </div>
</div>
<div class="bento" style="margin-bottom:0">
    <div class="bento-h" style="background:linear-gradient(135deg,#047857,#059669)"><i class="fa-solid fa-shield-halved"></i> الضمان</div>
    <div class="bento-b" style="padding:14px 16px">
        <?php if ($warranty_info): ?>
        <div style="text-align:center;padding:10px 0">
            <div style="font-size:28px;margin-bottom:8px"><?=$warranty_info['active']?'🛡️':'⚠️'?></div>
            <div style="font-size:13px;font-weight:900;color:<?=$warranty_info['active']?'#15803d':'#b91c1c'?>">
                <?=$warranty_info['active']?'ساري':'منتهٍ'?>
            </div>
            <div style="font-size:11.5px;color:var(--muted);margin-top:4px"><?=e($warranty_info['company'])?></div>
            <div style="font-size:11px;font-family:'Inter';color:var(--muted);margin-top:2px"><?=e($warranty_info['end_date'])?></div>
            <?php if ($warranty_info['active']): ?>
            <div style="background:#fef9c3;border-radius:8px;padding:7px;margin-top:8px;font-size:11px;color:#78350f;font-weight:700">
                ⚠ قد يتطلب تدخل الوكيل مباشرة
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:20px;color:var(--muted)"><i class="fa-solid fa-shield-slash" style="font-size:24px;display:block;margin-bottom:8px"></i>لا يوجد ضمان مسجَّل</div>
        <?php endif; ?>
    </div>
</div>
<div class="bento" style="margin-bottom:0">
    <div class="bento-h" style="background:linear-gradient(135deg,#1e3a8a,#2563eb)"><i class="fa-solid fa-handshake"></i> الشركة المتعاقدة</div>
    <div class="bento-b" style="padding:14px 16px">
        <div class="s-row"><span class="s-lbl">الشركة</span><span class="s-val" style="font-size:12px"><?=e($wo['contractor_name']??'—')?><?php if(!empty($wo['con_is_internal'])): ?> <span style="font-size:10px;font-weight:900;color:#166534;background:#f0fdf4;border-radius:99px;padding:1px 7px">داخلي</span><?php endif; ?></span></div>
        <?php if(!empty($wo['assigned_name'])): ?>
        <div class="s-row"><span class="s-lbl">المنفِّذ المعيَّن</span><span class="s-val" style="font-size:12px;color:#166534;font-weight:800"><i class="fa-solid fa-user-gear" style="font-size:10px"></i> <?=e($wo['assigned_name'])?></span></div>
        <?php endif; ?>
        <div class="s-row"><span class="s-lbl">المهندس</span><span class="s-val" style="font-size:12px"><?=e($wo['engineer_name']??'—')?></span></div>
        <div class="s-row"><span class="s-lbl">تاريخ الأمر</span><span class="s-val eng" style="font-size:12px"><?=e($wo['wo_date']??'—')?></span></div>
        <?php if ($wo['con_contract_end']): $cdays=(int)ceil((strtotime($wo['con_contract_end'])-time())/86400); ?>
        <div class="s-row"><span class="s-lbl">العقد</span>
            <span style="font-size:11px;font-weight:800;color:<?=$cdays<30?'#dc2626':($cdays<90?'#d97706':'#16a34a')?>">
                <?=$cdays<0?'منتهٍ':($cdays<30?'ينتهي خلال '.$cdays.' يوم':'ساري')?>
            </span></div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if (($wo['criticality_class']??'') === 'A'): ?>
<div style="background:#fef2f2;border:1.5px solid #fca5a5;border-right:5px solid #dc2626;border-radius:14px;padding:14px 18px;margin-bottom:14px;display:flex;align-items:center;gap:12px">
    <i class="fa-solid fa-triangle-exclamation" style="font-size:22px;color:#dc2626;flex-shrink:0"></i>
    <div>
        <div style="font-size:13px;font-weight:900;color:#7f1d1d">⚠ جهاز فئة A — بالغ الأهمية</div>
        <div style="font-size:11.5px;color:#b91c1c;font-weight:700;margin-top:3px">يستوجب أقصى سرعة في التدخل. أي تأخير يؤثر مباشرة على سلامة المريض.</div>
    </div>
</div>
<?php endif; ?>

<?php if ($wo['asset_id']): ?>
<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#0e7490,#0891b2)"><i class="fa-solid fa-globe"></i> تقارير FDA
        <span id="fdaLoadingWo" style="margin-right:auto;font-size:10px;opacity:.8"><i class="fa-solid fa-circle-notch fa-spin"></i> جاري الاستدعاء...</span>
    </div>
    <div class="bento-b">
        <div id="fdaWrapWo" style="display:none;display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:10px"></div>
        <button type="button" id="fdaDetailsBtnWo" onclick="openFdaWo()" style="display:none;width:100%;background:none;border:1px solid #0891b2;color:#0891b2;padding:8px;border-radius:8px;font-family:'Tajawal';font-size:12px;font-weight:900;cursor:pointer">
            <i class="fa-solid fa-up-right-and-down-left-from-center"></i> عرض التقارير التفصيلية
        </button>
        <div id="fdaErrorWo" style="display:none;font-size:12px;color:#dc2626;font-weight:700"></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($wo['manager_instructions'])): ?>
<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#b45309,#d97706)">
        <i class="fa-solid fa-clipboard-list"></i> توجيهات مدير الصيانة عند إنشاء أمر العمل</div>
    <div class="bento-b" style="font-size:13px;line-height:1.9;color:#334155">
        <?= nl2br(e($wo['manager_instructions'])) ?></div>
</div>
<?php endif; ?>

<?php
/* العرض المقفل (للقراءة فقط): يظهر إذا كان مسلماً للاعتماد، أو مغلقاً، أو إذا فتحه الإداريون */
$svc_checked = [];
foreach ($SVC as $sk => $slbl) { if (!empty($wo[$SVC_KEYS[$sk]])) $svc_checked[] = $slbl; }
$has_form_data = $svc_checked
    || trim((string)($wo['service_description'] ?? '')) !== ''
    || trim((string)($wo['follow_up_notes'] ?? '')) !== ''
    || trim((string)($wo['contractor_signed_name'] ?? '')) !== ''
    || !empty($wo['work_completed'])
    || (float)($wo['work_hours_total'] ?? 0) > 0
    || !empty($wo_items);

$editable_form_visible = $is_contractor && !$locked && $wo['status'] !== 'pending_manager_approval';

if ($has_form_data && !$editable_form_visible):
    $FS_LBL = ['completed'=>'اكتمل','working_need_parts'=>'يعمل — بانتظار قطع','need_secondary_parts'=>'قطع ثانوية','need_agent'=>'يحتاج وكيل','pending'=>'قيد المتابعة'];
?>
<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#1e3a8a,#3730a3)">
        <i class="fa-solid fa-file-contract"></i> النموذج الرسمي — البيانات المسجلة</div>
    <div class="bento-b" style="font-size:13px">
        <div style="margin-bottom:12px"><b style="color:var(--muted);font-size:12px">نوع الخدمة المنجزة:</b>
            <?php if ($svc_checked): foreach ($svc_checked as $sl): ?>
                <span style="display:inline-block;background:#eef2ff;color:#3730a3;font-size:11.5px;font-weight:800;border-radius:99px;padding:3px 11px;margin:3px 0 0 5px"><?= e($sl) ?></span>
            <?php endforeach; else: ?> <span style="color:var(--muted)">—</span><?php endif; ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px">
            <div><b style="color:var(--muted);font-size:11px;display:block">ساعات اليوم 1</b><?= e((float)($wo['work_hours_day1'] ?? 0)) ?></div>
            <div><b style="color:var(--muted);font-size:11px;display:block">اليوم 2</b><?= e((float)($wo['work_hours_day2'] ?? 0)) ?></div>
            <div><b style="color:var(--muted);font-size:11px;display:block">اليوم 3</b><?= e((float)($wo['work_hours_day3'] ?? 0)) ?></div>
            <div><b style="color:var(--muted);font-size:11px;display:block">النتيجة</b><span style="font-weight:900;color:#1e3a8a;"><?= e($FS_LBL[$wo['final_status'] ?? 'pending'] ?? '—') ?></span></div>
        </div>
        <div style="margin-bottom:10px"><b style="color:var(--muted);font-size:12px;display:block;margin-bottom:3px"><?= $is_it ? 'وصف العطل وما تم عمله' : 'الأعمال المنجزة (SERVICE DONE)' ?></b>
            <div style="background:#f8fafc;border:1px solid var(--border);border-radius:9px;padding:10px;line-height:1.9"><?= $wo['service_description'] ? nl2br(e($wo['service_description'])) : '<span style="color:var(--muted)">—</span>' ?></div></div>
        
        <!-- جدول عرض قطع الغيار (القراءة فقط) -->
        <?php if (!empty($wo_items)): ?>
        <div style="margin-bottom:14px;">
            <b style="color:var(--muted);font-size:12px;display:block;margin-bottom:6px">قطع الغيار المستخدمة (SPARE PARTS)</b>
            <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:right;background:#f8fafc;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
                <tr style="background:#f1f5f9;border-bottom:2px solid var(--border);">
                    <th style="padding:8px;">الوصف</th>
                    <th style="padding:8px;width:160px;">رقم القطعة</th>
                    <th style="padding:8px;width:80px;text-align:center;">الكمية</th>
                    <th style="padding:8px;">ملاحظات</th>
                </tr>
                <?php foreach ($wo_items as $item): ?>
                <tr style="border-bottom:1px dashed var(--border);">
                    <td style="padding:8px;"><?= e($item['description']) ?></td>
                    <td style="padding:8px;text-align:left;" dir="ltr"><?= e($item['part_number']) ?: '<span style="color:var(--muted)">—</span>' ?></td>
                    <td style="padding:8px;text-align:center;font-weight:900;color:#0e7490"><?= e($item['quantity']) ?></td>
                    <td style="padding:8px;"><?= e($item['remarks']) ?: '<span style="color:var(--muted)">—</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <div style="margin-bottom:10px"><b style="color:var(--muted);font-size:12px;display:block;margin-bottom:3px"><?= $is_it ? 'التوصيات' : 'ملاحظات المتابعة (Follow Up)' ?></b>
            <div style="background:#f8fafc;border:1px solid var(--border);border-radius:9px;padding:10px;line-height:1.9"><?= $wo['follow_up_notes'] ? nl2br(e($wo['follow_up_notes'])) : '<span style="color:var(--muted)">—</span>' ?></div></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:14px;background:#f1f5f9;padding:12px;border-radius:8px;">
            <div><b style="color:var(--muted);font-size:11px;display:block">الفني المنفِّذ</b><?= e($wo['contractor_signed_name'] ?: '—') ?></div>
            <div><b style="color:var(--muted);font-size:11px;display:block">تاريخ الانتهاء الفعلي</b><span class="eng" style="font-size:12px;"><?= e($wo['actual_completion_date'] ?: '—') ?></span></div>
            <div><b style="color:var(--muted);font-size:11px;display:block">أُنجز العمل كاملاً؟</b><?= !empty($wo['work_completed']) ? '<span style="color:#16a34a;font-weight:900;">✅ نعم</span>' : '<span style="color:var(--muted);font-weight:900;">⬜ لا</span>' ?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$locked && $wo['status'] !== 'pending_manager_approval'): ?>
<?php if ($is_contractor): ?>
<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#0e7490,#0891b2)"><i class="fa-solid fa-bolt"></i> نشر تحديث على أمر العمل</div>
    <div class="bento-b">
        <form method="POST" id="updateForm">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="post_update">
            <div style="font-size:12px;font-weight:800;color:var(--muted);margin-bottom:8px">اختر الإجراء الحالي (يمكن اختيار أكثر من واحد)</div>
            <div class="chip-grid">
                <?php $chips = [
                    'started'=>'بدأ العمل الميداني','spare_parts'=>'جاري توفير قطع غيار',
                    'warranty'=>'يغطيه الضمان','external_vendor'=>'التواصل مع وكيل خارجي',
                    'part_unavailable'=>'القطعة غير متوفرة','admin_approval'=>'بانتظار موافقة إدارية',
                    'completed_work'=>'تم إنجاز العمل',
                ]; foreach ($chips as $k=>$v): ?>
                <label class="chip" id="chip_<?=$k?>">
                    <input type="checkbox" name="update_chips[]" value="<?=$k?>" style="display:none" onchange="toggleChip('<?=$k?>')">
                    <?=$v?>
                </label>
                <?php endforeach; ?>
            </div>
            <textarea name="update_note" id="updateNote" rows="2" placeholder="ملاحظة إضافية تفصيلية (اختياري)..."
                oninput="checkPublishBtn()"
                style="width:100%;border:2px solid var(--border);border-radius:10px;padding:10px;font-family:'Tajawal';font-size:13px;margin-bottom:10px;outline:none"></textarea>
            <button type="submit" id="publishBtn" disabled class="act-btn" style="background:#0891b2;opacity:.45">
                <i class="fa-solid fa-paper-plane"></i> نشر التحديث وإشعار المعنيين
            </button>
        </form>
    </div>
</div>

<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#1e3a8a,#3730a3)">
        <i class="fa-solid fa-file-contract"></i> <?= $is_it ? 'نموذج تنفيذ أمر العمل — تقنية المعلومات (مختصر)' : 'النموذج الرسمي لأمر العمل' ?>
        <span style="background:rgba(255,255,255,.15);font-size:10px;padding:2px 8px;border-radius:99px;margin-right:auto">محفوظ تلقائياً — مفتوح حتى التسليم</span>
    </div>
    <div class="bento-b">
        <form method="POST" id="woForm">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="save_form" id="woAction">
            <input type="hidden" name="contractor_signature" id="sigData">

            <?php if (!$is_it): ?>
            <div style="font-size:12px;font-weight:800;color:var(--muted);margin-bottom:8px">نوع الخدمة المنجزة</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:16px">
                <?php foreach ($SVC as $key => $label): ?>
                <label class="svc-check">
                    <input type="checkbox" name="<?=$key?>" value="1" style="width:15px;height:15px"
                        <?=!empty($wo[$SVC_KEYS[$key]])?'checked':''?>>
                    <?=$label?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($is_it): ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
                <div><label style="font-size:11px;font-weight:800;color:var(--muted);display:block;margin-bottom:4px">ساعات العمل</label>
                <input type="number" name="h1" step="0.5" min="0" value="<?=e((float)$wo['work_hours_day1'])?>" style="border:1.5px solid var(--border);border-radius:8px;padding:8px;font-size:14px;font-weight:900"></div>
                <input type="hidden" name="h2" value="0">
                <input type="hidden" name="h3" value="0">
            <?php else: ?>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:14px">
                <?php foreach ([['h1','ساعات اليوم 1','work_hours_day1'],['h2','اليوم 2','work_hours_day2'],['h3','اليوم 3','work_hours_day3']] as [$fn,$lbl,$db]): ?>
                <div><label style="font-size:11px;font-weight:800;color:var(--muted);display:block;margin-bottom:4px"><?=$lbl?></label>
                <input type="number" name="<?=$fn?>" step="0.5" min="0" value="<?=e((float)$wo[$db])?>" style="border:1.5px solid var(--border);border-radius:8px;padding:8px;font-size:14px;font-weight:900"></div>
                <?php endforeach; ?>

            <?php endif; ?>
                <div><label style="font-size:11px;font-weight:800;color:var(--muted);display:block;margin-bottom:4px">النتيجة</label>
                <select name="final_status" style="border:1.5px solid var(--border);border-radius:8px;padding:8px;font-family:'Tajawal'">
                    <?php foreach(['completed'=>'اكتمل','working_need_parts'=>'يعمل — بانتظار قطع','need_secondary_parts'=>'قطع ثانوية','need_agent'=>'يحتاج وكيل','pending'=>'قيد المتابعة'] as $v=>$l): ?>
                    <option value="<?=$v?>" <?=($wo['final_status']??'pending')===$v?'selected':''?>><?=$l?></option>
                    <?php endforeach; ?>
                </select></div>
            </div>

            <div style="margin-bottom:12px">
                <label style="font-size:12px;font-weight:800;color:var(--muted);display:block;margin-bottom:5px"><?= $is_it ? 'وصف العطل وما تم عمله' : 'الأعمال المنجزة' ?> <span style="color:#dc2626">*</span></label>
                <textarea name="service_description" rows="3" required style="width:100%;border:1.5px solid var(--border);border-radius:8px;padding:10px;font-family:'Tajawal';font-size:13px"><?=e($wo['service_description']??'')?></textarea>
            </div>
            
            <!-- جدول إدخال قطع الغيار الديناميكي -->
            <div style="margin-bottom:14px; background:#f8fafc; border:1px solid var(--border); border-radius:8px; padding:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <label style="font-size:12px; font-weight:800; color:var(--muted); margin:0;">قطع الغيار المطلوبة (SPARE PARTS REQUIRED)</label>
                    <button type="button" onclick="addPartRow()" style="background:#0891b2; color:#fff; border:none; border-radius:6px; padding:6px 12px; font-size:11px; cursor:pointer; font-weight:700;"><i class="fa-solid fa-plus"></i> إضافة قطعة</button>
                </div>
                
                <table style="width:100%; border-collapse:collapse; font-size:12px; text-align:right;" id="partsTable">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border);">
                            <th style="padding:6px;">وصف القطعة <span style="color:#dc2626">*</span></th>
                            <th style="padding:6px; width:160px;">رقم القطعة (Part No)</th>
                            <th style="padding:6px; width:80px; text-align:center;">الكمية</th>
                            <th style="padding:6px;">ملاحظات</th>
                            <th style="padding:6px; width:40px;"></th>
                        </tr>
                    </thead>
                    <tbody id="partsBody">
                        <?php if(!empty($wo_items)): foreach($wo_items as $item): ?>
                        <tr>
                            <td style="padding:4px;"><input type="text" name="part_desc[]" value="<?=e($item['description'])?>" required style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; font-family:'Tajawal'"></td>
                            <td style="padding:4px;"><input type="text" name="part_number[]" value="<?=e($item['part_number'])?>" style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; text-align:left;" dir="ltr"></td>
                            <td style="padding:4px;"><input type="number" name="part_qty[]" value="<?=e($item['quantity'])?>" min="1" required style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; text-align:center;"></td>
                            <td style="padding:4px;"><input type="text" name="part_remarks[]" value="<?=e($item['remarks'])?>" style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; font-family:'Tajawal'"></td>
                            <td style="padding:4px; text-align:center;"><button type="button" onclick="this.closest('tr').remove()" style="color:#dc2626; background:none; border:none; cursor:pointer;"><i class="fa-solid fa-trash"></i></button></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-bottom:14px">
                <label style="font-size:12px;font-weight:800;color:var(--muted);display:block;margin-bottom:5px"><?= $is_it ? 'التوصيات' : 'ملاحظات المتابعة (Follow Up)' ?></label>
                <textarea name="follow_up_notes" rows="2" style="width:100%;border:1.5px solid var(--border);border-radius:8px;padding:10px;font-family:'Tajawal';font-size:13px"><?=e($wo['follow_up_notes']??'')?></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;background:#f1f5f9;padding:12px;border-radius:8px;">
                <div>
                    <label style="font-size:12px;font-weight:800;color:var(--muted);display:block;margin-bottom:5px">اسم الفني المنفِّذ (للتوقيع)</label>
                    <input type="text" name="contractor_signed_name" value="<?=e($wo['contractor_signed_name']??'')?>" style="width:100%; border:1px solid var(--border);border-radius:6px;padding:8px;font-family:'Tajawal'">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:800;color:var(--muted);display:block;margin-bottom:5px">تاريخ الانتهاء الفعلي</label>
                    <input type="date" name="actual_completion_date" value="<?=e($wo['actual_completion_date']??'')?>" style="width:100%; border:1px solid var(--border);border-radius:6px;padding:8px">
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:13px;font-weight:900;cursor:pointer;background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:8px;color:#166534">
                <input type="checkbox" name="work_completed" value="1" style="width:18px;height:18px" <?=!empty($wo['work_completed'])?'checked':''?>>
                أقر بأنه تم إنجاز العمل بشكل كامل
            </label>

            <button type="submit" class="act-btn" style="background:#1e3a8a"><i class="fa-solid fa-floppy-disk"></i> حفظ النموذج كمسودة</button>
        </form>

        <hr style="border:none;border-top:1px dashed var(--border);margin:18px 0">
        <div style="font-size:13px;font-weight:900;color:#0f172a;margin-bottom:8px"><i class="fa-solid fa-signature" style="color:#d97706;"></i> توقيع الفني (للتسليم النهائي للإدارة)</div>
        <div style="font-size:11px;font-weight:700;color:var(--muted);margin-bottom:8px">ارسم توقيعك في المربع أدناه ثم اضغط تسليم لاعتماد مدير الصيانة.</div>
        <canvas id="sigCanvas" class="sig-canvas" width="400" height="100" style="display:block;margin-bottom:6px;width:100%;max-width:400px;background:#fff"></canvas>
        <button type="button" onclick="clearSig()" style="background:#f1f5f9;border:1px solid var(--border);border-radius:7px;padding:5px 12px;font-size:11.5px;font-weight:800;cursor:pointer;color:var(--muted);margin-bottom:12px">مسح وإعادة الرسم</button>
        <button type="button" class="act-btn" style="background:linear-gradient(135deg,#059669,#16a34a)" onclick="submitFinalWO()">
            <i class="fa-solid fa-paper-plane"></i> تسليم نهائي للاعتماد (يحفظ كل البيانات ويُقفل النموذج)
        </button>
    </div>
</div>
<?php endif; // is_contractor ?>
<?php endif; // not pending / not locked ?>

<?php if ($can_manage && $wo['status'] === 'pending_manager_approval'): ?>
<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#047857,#059669)"><i class="fa-solid fa-circle-check"></i> اعتماد أمر العمل</div>
    <div class="bento-b">
        <div style="background:#fefce8;border:1px solid #fef08a;border-radius:8px;padding:10px;font-size:12.5px;color:#854d0e;font-weight:800;margin-bottom:12px;">
            يرجى مراجعة النموذج وبيانات قطع الغيار أعلاه قبل الاعتماد.
        </div>
        <div style="font-size:12px;font-weight:800;color:var(--muted);margin-bottom:8px">توقيع مدير الصيانة (اعتماد نهائي)</div>
        <canvas id="mgrCanvas" class="sig-canvas" width="400" height="100" style="display:block;margin-bottom:6px;width:100%;max-width:400px;background:#fff"></canvas>
        <button type="button" onclick="clearMgrSig()" style="background:#f1f5f9;border:1px solid var(--border);border-radius:7px;padding:5px 12px;font-size:11.5px;font-weight:800;cursor:pointer;color:var(--muted);margin-bottom:12px">مسح وإعادة الرسم</button>
        <form method="POST" onsubmit="document.getElementById('mgrSigData').value=document.getElementById('mgrCanvas').toDataURL()">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="manager_signature" id="mgrSigData">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                <button type="submit" class="act-btn" style="background:#16a34a"><i class="fa-solid fa-circle-check"></i> اعتماد وإغلاق</button>
                <button type="button" onclick="document.getElementById('rejectPanel').style.display='block'" class="act-btn" style="background:#fff;color:#dc2626;border:2px solid #fecaca"><i class="fa-solid fa-x"></i> إعادة للشركة (رفض)</button>
            </div>
        </form>
        <div id="rejectPanel" style="display:none;margin-top:10px">
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="reject">
                <textarea name="rejection_note" rows="2" placeholder="اكتب سبب الرفض والملاحظات للشركة لإعادة العمل عليها..." style="width:100%;border:2px solid #fecaca;border-radius:8px;padding:10px;font-family:'Tajawal';margin-bottom:8px"></textarea>
                <button type="submit" class="act-btn" style="background:#dc2626">تأكيد الرفض</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($locked && !empty($wo['manager_signature'])): ?>
<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#3b0764,#7c3aed)"><i class="fa-solid fa-pen-nib"></i> التوقيعات المعتمدة</div>
    <div class="bento-b" style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
        <div style="text-align:center">
            <div style="font-size:11.5px;font-weight:800;color:var(--muted);margin-bottom:8px">توقيع الفني (الشركة المتعاقدة)</div>
            <?php if ($wo['contractor_signature']): ?>
            <img src="<?=e($wo['contractor_signature'])?>" style="max-width:100%;height:auto;border:1px solid var(--border);border-radius:8px">
            <div style="font-size:11px;font-weight:800;color:var(--text);margin-top:4px"><?=e($wo['contractor_signed_name']??'')?></div>
            <?php else: ?><span style="color:var(--muted);font-size:12px">—</span><?php endif; ?>
        </div>
        <div style="text-align:center">
            <div style="font-size:11.5px;font-weight:800;color:var(--muted);margin-bottom:8px">توقيع مدير الصيانة الطبية</div>
            <img src="<?=e($wo['manager_signature'])?>" style="max-width:100%;height:auto;border:1px solid var(--border);border-radius:8px">
            <div style="font-size:11px;font-weight:800;color:var(--muted);margin-top:4px" class="eng"><?=e(date('Y-m-d', strtotime($wo['approved_at']??'now')))?></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="bento">
    <div class="bento-h" style="background:linear-gradient(135deg,#334155,#475569)"><i class="fa-solid fa-list-check"></i> السجل الزمني الموحَّد</div>
    <div class="bento-b">
        <?php foreach ($timeline as $tl): $icon=$TL_ICONS[$tl['action_type']]??$TL_ICONS['default'];
            $isUpdate = $tl['action_type'] === 'wo_update'; ?>
        <div class="tl-item" style="<?=$isUpdate?'background:#f0fdf4;border-radius:10px;padding:8px 10px;margin:-4px;':''?>">
            <div class="tl-icon" style="<?=$isUpdate?'background:#dcfce7;border-color:#86efac;':''?>"><?=$icon?></div>
            <div style="flex:1">
                <div style="font-size:12.5px;font-weight:800;<?=$isUpdate?'color:#15803d':''?>"><?=e($tl['action_label'])?></div>
                <div style="font-size:11px;color:var(--muted);margin-top:2px"><?=e($tl['actor_name']??'النظام')?> · <span class="eng"><?=e(date('Y-m-d H:i',strtotime($tl['created_at'])))?></span></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div></main>
</div>

<script>
// التحكم بالجدول الديناميكي لقطع الغيار
function addPartRow() {
    const tbody = document.getElementById('partsBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="padding:4px;"><input type="text" name="part_desc[]" placeholder="اسم القطعة" required style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; font-family:'Tajawal'"></td>
        <td style="padding:4px;"><input type="text" name="part_number[]" placeholder="SN/PN" style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; text-align:left;" dir="ltr"></td>
        <td style="padding:4px;"><input type="number" name="part_qty[]" value="1" min="1" required style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; text-align:center;"></td>
        <td style="padding:4px;"><input type="text" name="part_remarks[]" placeholder="ملاحظات..." style="width:100%; border:1px solid var(--border); border-radius:6px; padding:6px; font-family:'Tajawal'"></td>
        <td style="padding:4px; text-align:center;"><button type="button" onclick="this.closest('tr').remove()" style="color:#dc2626; background:none; border:none; cursor:pointer;"><i class="fa-solid fa-trash"></i></button></td>
    `;
    tbody.appendChild(tr);
}

// لوحات التوقيع
function initCanvas(id){
    const c=document.getElementById(id); if(!c)return;
    let drawing=false,lx=0,ly=0;
    const ctx=c.getContext('2d'); ctx.strokeStyle='#0f172a'; ctx.lineWidth=2; ctx.lineCap='round';
    const pos=(e)=>{const r=c.getBoundingClientRect();const t=e.touches?e.touches[0]:e;return{x:(t.clientX-r.left)*(c.width/r.width),y:(t.clientY-r.top)*(c.height/r.height)}};
    c.addEventListener('mousedown',e=>{drawing=true;const p=pos(e);lx=p.x;ly=p.y});
    c.addEventListener('touchstart',e=>{e.preventDefault();drawing=true;const p=pos(e);lx=p.x;ly=p.y},{passive:false});
    c.addEventListener('mousemove',e=>{if(!drawing)return;const p=pos(e);ctx.beginPath();ctx.moveTo(lx,ly);ctx.lineTo(p.x,p.y);ctx.stroke();lx=p.x;ly=p.y});
    c.addEventListener('touchmove',e=>{e.preventDefault();if(!drawing)return;const p=pos(e);ctx.beginPath();ctx.moveTo(lx,ly);ctx.lineTo(p.x,p.y);ctx.stroke();lx=p.x;ly=p.y},{passive:false});
    c.addEventListener('mouseup',()=>drawing=false);
    c.addEventListener('touchend',()=>drawing=false);
}
function clearSig(){const c=document.getElementById('sigCanvas');if(c)c.getContext('2d').clearRect(0,0,c.width,c.height);}
function submitFinalWO(){
    if(!confirm('بعد التسليم لن يمكنك التعديل نهائياً على النموذج. هل أنت متأكد؟')) return;
    const c=document.getElementById('sigCanvas');
    document.getElementById('sigData').value = c ? c.toDataURL() : '';
    document.getElementById('woAction').value = 'submit_final';
    document.getElementById('woForm').submit();
}
function clearMgrSig(){const c=document.getElementById('mgrCanvas');if(c)c.getContext('2d').clearRect(0,0,c.width,c.height);}
initCanvas('sigCanvas'); initCanvas('mgrCanvas');

// Chips
function toggleChip(k){
    const inp=document.querySelector(`#chip_${k} input`);
    const label=document.getElementById(`chip_${k}`);
    const checked=inp.checked;
    label.classList.toggle('selected',checked);
    checkPublishBtn();
}
function checkPublishBtn(){
    const anyChip=[...document.querySelectorAll('.chip input')].some(i=>i.checked);
    const hasNote=document.getElementById('updateNote')?.value.trim().length>0;
    const btn=document.getElementById('publishBtn');
    if(btn){btn.disabled=!(anyChip||hasNote);btn.style.opacity=(anyChip||hasNote)?'1':'0.45';}
}

// FDA
var BASE_URL = '<?= BASE_URL ?>';
var WO_ASSET_ID = '<?= (int)($wo['asset_id']??0) ?>';
(async function(){
    if(!WO_ASSET_ID) return;
    try {
        var fd=new FormData(); fd.append('asset_id', WO_ASSET_ID);
        var r=await fetch(BASE_URL+'/api/complaint_fda_summary.php',{method:'POST',body:fd});
        var d=await r.json();
        document.getElementById('fdaLoadingWo').style.display='none';
        var w=document.getElementById('fdaWrapWo');
        w.style.display='grid';
        w.innerHTML=
            '<div style="background:#fff;border:1px solid #bae6fd;border-radius:10px;padding:9px;text-align:center"><div style="font-size:16px;font-weight:900;color:#0e7490">'+(d.total||0)+'</div><div style="font-size:9px;font-weight:800;color:#0e7490">إجمالي</div></div>'+
            '<div style="background:#fff;border:1px solid #bae6fd;border-radius:10px;padding:9px;text-align:center"><div style="font-size:16px;font-weight:900;color:#dc2626">'+(d.malfunction||0)+'</div><div style="font-size:9px;font-weight:800;color:#b91c1c">أعطال</div></div>'+
            '<div style="background:#fff;border:1px solid #bae6fd;border-radius:10px;padding:9px;text-align:center"><div style="font-size:16px;font-weight:900;color:#d97706">'+(d.injury_death||0)+'</div><div style="font-size:9px;font-weight:800;color:#b45309">خطورة</div></div>';
        if(d.total>0) document.getElementById('fdaDetailsBtnWo').style.display='block';
    } catch(e) {
        document.getElementById('fdaLoadingWo').style.display='none';
        document.getElementById('fdaErrorWo').style.display='block';
        document.getElementById('fdaErrorWo').textContent='تعذّر الاتصال بـ FDA';
    }
})();

function openFdaWo(){
    document.getElementById('fdaModalWo').style.display='flex';
    if(fdaDetailWo || fdaDetailLoadingWo) return;
    fdaDetailLoadingWo = true;
    ['ov','mf','inj'].forEach(t => document.getElementById('fdaPaneWo-'+t).innerHTML =
        '<div style="text-align:center;padding:30px"><i class="fa-solid fa-circle-notch fa-spin" style="color:#0e7490;font-size:18px"></i></div>');
    var fd=new FormData(); fd.append('asset_id', WO_ASSET_ID);
    fetch(BASE_URL+'/api/complaint_fda_details.php',{method:'POST',body:fd})
        .then(r=>r.json()).then(d=>{fdaDetailWo=d; renderFdaWo();})
        .catch(()=>{fdaDetailWo=null; renderFdaWo();});
}
function renderFdaWo(){
    fdaDetailLoadingWo=false;
    if(!fdaDetailWo){
        ['ov','mf','inj'].forEach(t => document.getElementById('fdaPaneWo-'+t).innerHTML=
            '<div style="color:#dc2626;font-weight:800;padding:20px;text-align:center">تعذّر جلب التقارير</div>');
        return;
    }
    var mf=fdaDetailWo.malfunctions||[], inj=fdaDetailWo.injuries||[];
    document.getElementById('fdaPaneWo-ov').innerHTML=
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px"><div class="fda-kpi"><div class="v eng" style="color:#dc2626">'+mf.length+'</div><div class="l">أعطال</div></div>'+
        '<div class="fda-kpi"><div class="v eng" style="color:#d97706">'+inj.length+'</div><div class="l">خطورة</div></div></div>';
    document.getElementById('fdaPaneWo-mf').innerHTML=renderFdaListWo(mf,'لا توجد أعطال مسجَّلة.');
    document.getElementById('fdaPaneWo-inj').innerHTML=renderFdaListWo(inj,'لا توجد تحذيرات مسجَّلة.');
}
function renderFdaListWo(list, empty){
    if(!list||!list.length) return '<div style="padding:20px;text-align:center;color:#64748b;font-weight:700">'+empty+'</div>';
    return list.map(ev=>{
        var uid='tr-'+Math.random().toString(36).substr(2,8);
        var safe=(ev.narrative||'').replace(/[\r\n]+/g,' ').replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'&quot;');
        return '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:9px">'+
            '<div style="font-size:10px;font-weight:800;color:#64748b">'+ev.event_type+'</div>'+
            '<div style="font-size:11.5px;font-weight:700;line-height:1.6;margin:6px 0" dir="ltr">'+ev.narrative+'</div>'+
            '<div id="'+uid+'"><button type="button" onclick="translateFdaWo(this,\''+safe+'\',\''+uid+'\')" style="background:#fff;color:#d97706;border:1px solid #fcd34d;padding:5px 12px;border-radius:7px;font-size:11px;font-weight:900;font-family:Tajawal;cursor:pointer"><i class="fa-solid fa-language"></i> ترجمة</button></div>'+
            '</div>';
    }).join('');
}
async function translateFdaWo(btn,text,boxId){
    btn.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> جاري...'; btn.disabled=true;
    try {
        var r=await fetch('https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=ar&dt=t&q='+encodeURIComponent(text));
        var d=await r.json();
        var tr=d[0].map(i=>i[0]).join('');
        document.getElementById(boxId).innerHTML='<div style="direction:rtl;text-align:right;font-family:Tajawal;font-size:13px;font-weight:800;color:#9a3412;line-height:1.6">'+tr+'</div>';
    } catch(e){ btn.innerHTML='فشلت الترجمة'; btn.disabled=false; }
}
function fdaTabWo(t){
    document.querySelectorAll('.fda-tab-wo').forEach(b=>{b.style.background='transparent';b.style.color='#64748b';});
    var btn=document.querySelector('.fda-tab-wo[data-tab="'+t+'"]');
    btn.style.background='#0e7490'; btn.style.color='#fff';
    document.querySelectorAll('.fda-pane-wo').forEach(p=>p.style.display='none');
    document.getElementById('fdaPaneWo-'+t).style.display='block';
}
var fdaDetailWo=null, fdaDetailLoadingWo=false;
</script>
<div id="fdaModalWo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:16px;width:95%;max-width:860px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.25);overflow:hidden">
        <div style="background:linear-gradient(135deg,#0e7490,#0891b2);padding:14px 20px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
            <span style="font-weight:900;font-size:14px"><i class="fa-solid fa-globe"></i> تقارير سلامة الجهاز (FDA)</span>
            <button onclick="document.getElementById('fdaModalWo').style.display='none'" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer">✕</button>
        </div>
        <div style="display:flex;gap:8px;padding:10px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0;flex-shrink:0">
            <button class="fda-tab-wo" data-tab="ov" onclick="fdaTabWo('ov')" style="flex:1;padding:8px;border-radius:8px;font-size:12px;font-weight:900;border:none;cursor:pointer;background:#0e7490;color:#fff">نظرة عامة</button>
            <button class="fda-tab-wo" data-tab="mf" onclick="fdaTabWo('mf')" style="flex:1;padding:8px;border-radius:8px;font-size:12px;font-weight:900;border:none;cursor:pointer;background:transparent;color:#64748b">أعطال</button>
            <button class="fda-tab-wo" data-tab="inj" onclick="fdaTabWo('inj')" style="flex:1;padding:8px;border-radius:8px;font-size:12px;font-weight:900;border:none;cursor:pointer;background:transparent;color:#64748b">خطورة</button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:16px 20px;min-height:0">
            <div class="fda-pane-wo" id="fdaPaneWo-ov"></div>
            <div class="fda-pane-wo" id="fdaPaneWo-mf" style="display:none"></div>
            <div class="fda-pane-wo" id="fdaPaneWo-inj" style="display:none"></div>
        </div>
    </div>
</div>
</body>
</html>