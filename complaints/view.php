<?php
/**
 * complaints/view.php — عرض البلاغ (واجهة Bento Box) + دورة الحالة الكاملة
 * مع التعديلات الذكية:
 * 1. إخفاء أزرار الإنشاء/التصعيد عند وجود أمر عمل نشط
 * 2. Modal الإنشاء السريع مع التوجيه الذكي
 * 3. الإنشاء المباشر بحالة sent_to_contractor وتجميد SLA
 * 4. إصلاح نافذة FDA وزر الترجمة
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_lib.php';
page_guard('complaints.index');

if (!can_see_all() && !can('complaints.index', 'edit') && !can('complaints.index', 'approve') && !can('complaints.index', 'manage')) {
    header('Location: ' . BASE_URL . '/complaints/my.php?id=' . (int) ($_GET['id'] ?? 0));
    exit;
}

$u_data = current_user();
$uid = is_array($u_data) ? (int)($u_data['id'] ?? 0) : (int)$u_data;
$id = (int) ($_GET['id'] ?? 0);
if (!$id) { header('Location: ' . BASE_URL . '/complaints/index.php'); exit; }

$errors = [];

function logTl($pdo, $cid, $type, $label, $old, $new, $uid) {
    try {
        $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,?,?,?,?,?)")
            ->execute([$cid, $type, $label, $old, $new, $uid]);
    } catch (Exception $e) {}
}

function notify_sys($pdo, $target_uid, $type, $title, $body, $cid, $link = null) {
    try {
        if (!$target_uid) return;
        $link = $link ?? (BASE_URL . '/complaints/view.php?id=' . $cid);
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$target_uid, $type, $title, $body, $link, 'complaint', $cid]);
    } catch (Exception $e) {}
}

function notify_dept_and_requester($pdo, $c, $type, $title, $body) {
    $link = BASE_URL . '/complaints/my.php?id=' . $c['id'];
    $targets = users_with_permission($pdo, 'complaints.my', 'manage', (int) $c['dept_id']);
    $targets[] = $c['requested_by'];
    foreach (array_unique(array_filter($targets)) as $t) {
        notify_sys($pdo, $t, $type, $title, $body, $c['id'], $link);
    }
}

// ── معالج POST العام ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verify_csrf()) {
        $errors[] = 'خطأ في الجلسة (CSRF). يرجى التحديث والمحاولة.';
    } else {
        $s = $pdo->prepare("SELECT * FROM complaints WHERE id=?");
        $s->execute([$id]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) { $errors[] = 'البلاغ غير موجود.'; }
        else {
            $st = $c['status'] ?? 'open';
            $can_edit = can('complaints.index', 'edit');
            $can_approve = can('complaints.index', 'approve');
            $can_manage = can('complaints.index', 'manage');
            $is_owner = ($c['requested_by'] == $uid);
            $allowed = $can_edit || $can_approve || $can_manage;
            
            if (!$allowed) { $errors[] = 'غير مصرح لك بهذا الإجراء.'; }
            else {
                if ($action === 'acknowledge' && $st === 'open' && $can_edit) {
                    $pdo->prepare("UPDATE complaints SET status='acknowledged', acknowledged_by=?, acknowledged_at=NOW() WHERE id=?")->execute([$uid, $id]);
                    logTl($pdo, $id, 'acknowledged', 'استلام البلاغ وبدء المعاينة', $st, 'acknowledged', $uid);
                    notify_dept_and_requester($pdo, $c, 'info', 'تم استلام بلاغك', 'مهندس الصيانة قام باستلام البلاغ للتو.');
                    flash('success', 'تم استلام البلاغ.');
                } elseif ($action === 'start' && in_array($st, ['acknowledged', 'stalled', 'escalated']) && $can_edit) {
                    $pdo->prepare("UPDATE complaints SET status='in_progress', started_by=?, started_at=NOW() WHERE id=?")->execute([$uid, $id]);
                    logTl($pdo, $id, 'started', 'بدء العمل الميداني', $st, 'in_progress', $uid);
                    notify_dept_and_requester($pdo, $c, 'info', 'جاري العمل الميداني', 'فريق الصيانة يعمل الآن على إصلاح العطل.');
                    flash('success', 'تم بدء العمل بنجاح.');
                } elseif ($action === 'stall' && $st === 'in_progress' && $can_edit) {
                    $reason = trim($_POST['reason'] ?? '');
                    if (!$reason) { $errors[] = 'يجب كتابة سبب التعثّر.'; }
                    else {
                        $pdo->prepare("UPDATE complaints SET status='stalled', stalled_by=?, stalled_at=NOW(), stall_reason=? WHERE id=?")->execute([$uid, $reason, $id]);
                        logTl($pdo, $id, 'stalled', 'تعثّر العمل: ' . $reason, $st, 'stalled', $uid);
                        notify_dept_and_requester($pdo, $c, 'warning', 'تعثّر العمل على بلاغك', $reason);
                        flash('warning', 'تم تسجيل تعثّر البلاغ.');
                    }
                } elseif ($action === 'escalate' && in_array($st, ['open', 'acknowledged', 'in_progress', 'stalled']) && $can_manage) {
                    $note = trim($_POST['note'] ?? '');
                    $pdo->prepare("UPDATE complaints SET status='escalated', escalated_by=?, escalated_at=NOW(), escalation_note=?,
                        sla_paused_at = COALESCE(sla_paused_at, NOW()),
                        sla_pause_reason = 'لدى لجنة المتابعة (مُصعَّد)'
                        WHERE id=?")->execute([$uid, $note, $id]);
                    logTl($pdo, $id, 'escalated', 'تصعيد يدوي' . ($note ? ': ' . $note : ''), $st, 'escalated', $uid);
                    logTl($pdo, $id, 'sla_paused', '⏸️ أُوقف احتساب وقت المعالجة — البلاغ لدى لجنة المتابعة', 'escalated', 'escalated', $uid);
                    notify_sys($pdo, $uid, 'warning', 'تم تصعيد البلاغ', $note ?: 'تصعيد يدوي من فريق الصيانة.', BASE_URL . '/complaints/escalation.php?id=' . $id);
                    flash('warning', 'تم تصعيد البلاغ.');
                } elseif ($action === 'resolve' && in_array($st, ['in_progress', 'stalled', 'escalated']) && $can_approve) {
                    $notes = trim($_POST['notes'] ?? '');
                    if (!$notes) { $errors[] = 'يجب كتابة تقرير الإصلاح.'; }
                    else {
                        $pdo->prepare("UPDATE complaints SET status='resolved', resolved_by=?, resolved_at=NOW(), resolution_notes=?,
                            sla_paused_seconds_total = sla_paused_seconds_total
                                + IF(sla_paused_at IS NULL, 0, TIMESTAMPDIFF(SECOND, sla_paused_at, NOW())),
                            sla_paused_at = NULL, sla_pause_reason = NULL
                            WHERE id=?")->execute([$uid, $notes, $id]);
                        if (!empty($c['asset_id'])) {
                            $pdo->prepare("UPDATE assets SET status='active', last_maintenance_date=NOW() WHERE id=?")->execute([$c['asset_id']]);
                        }
                        logTl($pdo, $id, 'resolved', 'تم تقديم الحل: ' . $notes, $st, 'resolved', $uid);
                        notify_dept_and_requester($pdo, $c, 'success', 'تم تقديم حل لبلاغك', $notes);
                        flash('success', 'تم تقديم الحل — بانتظار تأكيد المُبلِّغ.');
                    }
                } elseif ($action === 'reject' && $st === 'resolved' && $can_manage) {
                    $note = trim($_POST['note'] ?? '');
                    if (!$note) { $errors[] = 'يجب كتابة سبب رفض البلاغ.'; }
                    else {
                        $pdo->prepare("UPDATE complaints SET status='rejected', rejected_by=?, rejected_at=NOW(), rejection_note=? WHERE id=?")->execute([$uid, $note, $id]);
                        logTl($pdo, $id, 'rejected', 'رفض فريق الصيانة البلاغ: ' . $note, $st, 'rejected', $uid);
                        notify_dept_and_requester($pdo, $c, 'danger', 'تم رفض بلاغك', $note);
                        flash('info', 'تم رفض البلاغ.');
                    }
                } elseif ($action === 'reprioritize' && !in_array($st, ['closed', 'cancelled', 'rejected']) && $can_manage) {
                    $newPriority = $_POST['new_priority'] ?? '';
                    $reason = trim($_POST['priority_reason'] ?? '');
                    if (!in_array($newPriority, ['normal', 'urgent', 'critical'])) { $errors[] = 'أولوية غير صالحة.'; }
                    elseif ($newPriority === $c['priority']) { $errors[] = 'هذه الأولوية الحالية فعلاً، لم تتغيّر.'; }
                    elseif (!$reason) { $errors[] = 'يجب كتابة سبب إعادة التصنيف.'; }
                    else {
                        $origLabel = ['normal' => 'عادي', 'urgent' => 'عاجل', 'critical' => 'طارئ'];
                        $pdo->prepare("UPDATE complaints SET priority=?, priority_original=COALESCE(priority_original, ?), priority_changed_by=?, priority_changed_at=NOW(), priority_change_reason=? WHERE id=?")
                            ->execute([$newPriority, $c['priority'], $uid, $reason, $id]);
                        logTl($pdo, $id, 'reprioritized', 'أُعيد تصنيف الأولوية من "' . $origLabel[$c['priority']] . '" إلى "' . $origLabel[$newPriority] . '": ' . $reason, $st, $st, $uid);
                        notify_dept_and_requester($pdo, $c, 'warning', 'تغيّرت أولوية بلاغك', 'أعاد فريق الصيانة تصنيف أولوية بلاغك إلى "' . $origLabel[$newPriority] . '": ' . $reason);
                        flash('success', 'تم تحديث الأولوية بنجاح.');
                    }
                } elseif ($action === 'close' && !in_array($st, ['closed', 'cancelled', 'rejected']) && $can_manage) {
                    $pdo->prepare("UPDATE complaints SET status='closed', closed_by=?, closed_at=NOW() WHERE id=?")->execute([$uid, $id]);
                    logTl($pdo, $id, 'closed', 'إغلاق إداري مباشر', $st, 'closed', $uid);
                    flash('info', 'تم إغلاق البلاغ إدارياً.');
                } elseif ($action === 'add_attachment' && !in_array($st, ['closed', 'cancelled', 'rejected', 'escalated', 'resolved']) && ($can_edit || $can_approve || $can_manage)) {
                    $added = 0;
                    if (!empty($_FILES['new_attachments']['name'][0])) {
                        $updir = BASE_PATH . '/uploads/complaints/' . $id . '/';
                        if (!is_dir($updir)) mkdir($updir, 0755, true);
                        $att_stmt = $pdo->prepare("INSERT INTO complaint_attachments (complaint_id, file_name, file_path, file_size, file_type, uploaded_by) VALUES (?,?,?,?,?,?)");
                        foreach ($_FILES['new_attachments']['name'] as $i => $fname) {
                            if ($i >= 5 || !$fname || $_FILES['new_attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
                            $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx', 'xls', 'xlsx'])) continue;
                            $safe = 'eng_' . time() . '_' . $i . '_' . rand(100, 999) . '.' . $ext;
                            if (move_uploaded_file($_FILES['new_attachments']['tmp_name'][$i], $updir . $safe)) {
                                $att_stmt->execute([$id, $fname, 'complaints/' . $id . '/' . $safe, $_FILES['new_attachments']['size'][$i], $_FILES['new_attachments']['type'][$i], $uid]);
                                logTl($pdo, $id, 'attachment_added', 'أضاف مرفقاً: ' . $fname, $st, $st, $uid);
                                $added++;
                            }
                        }
                    }
                    if ($added) { flash('success', 'تم إضافة ' . $added . ' مرفق(ات) بنجاح.'); }
                    else { $errors[] = 'لم يُرفَع أي ملف صالح.'; }
                } else {
                    $errors[] = 'لا يمكن تنفيذ هذا الإجراء في الحالة الحالية، أو لا تملك الصلاحية اللازمة له.';
                }
            }
        }
    }
    if (!$errors) { header('Location: ' . BASE_URL . '/complaints/view.php?id=' . $id); exit; }
}

// ── معالج إنشاء أمر العمل من النافذة المنبثقة (Smart Logic) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_wo_action'] ?? '') === 'create_wo') {
    if (verify_csrf() && can('complaints.index', 'manage')) {
        $cid_wo = (int) ($_POST['wo_complaint_id'] ?? 0);
        $mgr_note = trim($_POST['wo_manager_note'] ?? '');
        $wo_type  = $_POST['wo_type'] ?? ($c['request_type'] ?? 'medical');

        // التحقق من وجود أمر عمل نشط
        $wo_check = $pdo->prepare("SELECT id FROM complaint_work_orders WHERE complaint_id = ? AND status NOT IN ('completed', 'cancelled', 'rejected_by_manager') LIMIT 1");
        $wo_check->execute([$cid_wo]);
        if ($wo_check->fetchColumn()) {
            flash('danger', 'يوجد بالفعل أمر عمل نشط لهذا البلاغ.');
        } else {
            // التوجيه الذكي حسب نوع أمر العمل
            $svc_map = ['medical' => 'medical_maintenance', 'it' => 'it', 'general' => 'general_maintenance'];
            $target_svc = $svc_map[$wo_type] ?? 'general_maintenance';

            $con_stmt = $pdo->prepare("SELECT id, name, contact_person, is_internal FROM contractors WHERE service_type = ? AND is_active=1 LIMIT 1");
            $con_stmt->execute([$target_svc]);
            $auto_con = $con_stmt->fetch();

            // ── التوجيه الداخلي: تحقق الموظف المنفِّذ ──
            $assigned_uid = null; $assigned_emp = null;
            if ($auto_con && !empty($auto_con['is_internal'])) {
                $assigned_uid = (int)($_POST['assigned_user_id'] ?? 0);
                if ($assigned_uid) {
                    $aq = $pdo->prepare(
                        "SELECT u.id, u.full_name FROM users u
                         JOIN departments d ON d.id = u.department_id
                         WHERE u.id=? AND u.is_active=1 AND d.dept_category=?");
                    $aq->execute([$assigned_uid, 'maintenance_' . $wo_type]);
                    $assigned_emp = $aq->fetch();
                }
            }

            if (!$auto_con) {
                flash('danger', 'لا توجد شركة متعاقدة نشطة لـ "' . $target_svc . '". أضف شركة من شاشة المقاولين أولاً.');
            } elseif (!empty($auto_con['is_internal']) && !$assigned_emp) {
                flash('danger', 'التوجيه الداخلي يتطلب اختيار الموظف المنفِّذ من قائمة القسم.');
            } else {
                $pdo->beginTransaction();
                try {
                    // توليد رقم أمر العمل
                    $yr = date('Y');
                    $seq = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(wo_number,'/',-1) AS UNSIGNED)),0)+1 FROM complaint_work_orders WHERE wo_number LIKE 'WO/{$yr}/%'")->fetchColumn();
                    $wo_number = sprintf('WO/%s/%04d', $yr, $seq);

                    // ── تحضير الحقول الديناميكية حسب نوع أمر العمل ───────────────
                    $dyn = [];
                    if ($wo_type === 'general') {
                        $dyn = [
                            'general_work_nature'              => $_POST['g_work_nature']              ?? null,
                            'general_location_detail'          => $_POST['g_location_detail']          ?? null,
                            'general_affected_systems'         => $_POST['g_affected_systems']         ?? null,
                            'general_safety_measures'          => $_POST['g_safety_measures']          ?? null,
                            'general_tools_used'               => $_POST['g_tools_used']               ?? null,
                            'general_materials_used'           => $_POST['g_materials_used']           ?? null,
                            'general_work_scope'               => $_POST['g_work_scope']               ?? null,
                            'general_estimated_duration_hours' => is_numeric($_POST['g_est_hours'] ?? null) ? (float)$_POST['g_est_hours'] : null,
                            'general_team_size'                => is_numeric($_POST['g_team_size'] ?? null) ? (int)$_POST['g_team_size'] : null,
                            'general_permit_required'          => isset($_POST['g_permit_required']) ? 1 : 0,
                            'general_permit_type'              => $_POST['g_permit_type']              ?? null,
                            'general_building'                 => $_POST['g_building']                 ?? null,
                            'general_floor'                    => $_POST['g_floor']                    ?? null,
                            'general_root_cause'               => $_POST['g_root_cause']               ?? null,
                            'general_recommendation'           => $_POST['g_recommendation']           ?? null,
                        ];
                    } elseif ($wo_type === 'it') {
                        $dyn = [
                            'it_work_category'         => $_POST['i_category']          ?? null,
                            'it_asset_type'            => $_POST['i_asset_type']        ?? null,
                            'it_asset_tag'             => $_POST['i_asset_tag']         ?? null,
                            'it_ip_address'            => $_POST['i_ip']                ?? null,
                            'it_mac_address'           => $_POST['i_mac']               ?? null,
                            'it_os_info'               => $_POST['i_os']                ?? null,
                            'it_software_affected'     => $_POST['i_software']          ?? null,
                            'it_security_incident'     => isset($_POST['i_security']) ? 1 : 0,
                            'it_incident_severity'     => $_POST['i_severity']          ?? null,
                            'it_data_backup_done'      => isset($_POST['i_backup']) ? 1 : 0,
                            'it_data_loss'             => isset($_POST['i_data_loss']) ? 1 : 0,
                            'it_user_impact_count'     => is_numeric($_POST['i_user_count'] ?? null) ? (int)$_POST['i_user_count'] : null,
                            'it_downtime_hours'        => is_numeric($_POST['i_downtime'] ?? null) ? (float)$_POST['i_downtime'] : null,
                            'it_remote_session'        => isset($_POST['i_remote']) ? 1 : 0,
                            'it_change_request_ref'    => $_POST['i_cr_ref']            ?? null,
                            'it_root_cause_category'   => $_POST['i_root_category']     ?? null,
                            'it_preventive_action'     => $_POST['i_preventive']        ?? null,
                            'it_kb_article'            => $_POST['i_kb']                ?? null,
                        ];
                    }

                    // ── بناء استعلام الإدخال الديناميكي ───────────────────────────
                    $base_cols = ['wo_number','complaint_id','contractor_id','contractor_name','engineer_name',
                                  'assigned_user_id',
                                  'wo_date','manager_instructions','wo_type','status','created_by','sent_to_contractor_at'];
                    $base_vals = [$wo_number, $cid_wo, $auto_con['id'], $auto_con['name'],
                                  $assigned_emp ? $assigned_emp['full_name'] : $auto_con['contact_person'],
                                  $assigned_emp ? (int)$assigned_emp['id'] : null,
                                  date('Y-m-d'), $mgr_note,
                                  $wo_type, 'sent_to_contractor', $uid, date('Y-m-d H:i:s')];

                    $cols = $base_cols;
                    $placeholders = array_fill(0, count($base_cols), '?');
                    $vals = $base_vals;

                    foreach ($dyn as $k => $v) {
                        if ($v !== null && $v !== '') {
                            $cols[] = $k;
                            $placeholders[] = '?';
                            $vals[] = $v;
                        }
                    }

                    $sql = "INSERT INTO complaint_work_orders (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $placeholders) . ")";
                    $pdo->prepare($sql)->execute($vals);
                    $wo_id = (int) $pdo->lastInsertId();

                    // تحديث حالة البلاغ وتجميد SLA
                    $pdo->prepare("UPDATE complaints SET status='in_progress', sla_paused_at=NOW(), sla_pause_reason=? WHERE id=?")
                        ->execute(['بانتظار الشركة: ' . $auto_con['name'], $cid_wo]);

                    // تسجيل في Timeline
                    $type_labels = ['medical'=>'طبي','general'=>'صيانة عامة','it'=>'تقنية معلومات'];
                    $log_msg = 'أُنشئ أمر عمل (' . ($type_labels[$wo_type] ?? $wo_type) . '): ' . $wo_number . ' وأُرسل إلى ' . $auto_con['name']
                        . ($assigned_emp ? ' — المنفِّذ: ' . $assigned_emp['full_name'] : '');
                    logTl($pdo, $cid_wo, 'wo_created', $log_msg, $c['status'], 'in_progress', $uid);

                    // إشعار الشركة المتعاقدة
                    $con_users = $pdo->prepare("SELECT id FROM users WHERE contractor_id=? AND is_active=1");
                    $con_users->execute([$auto_con['id']]);
                    foreach ($con_users->fetchAll(PDO::FETCH_COLUMN) as $cuid) {
                        notify_sys($pdo, $cuid, 'info', 'أمر عمل جديد: ' . $wo_number,
                            $mgr_note ?: 'تم إسناد أمر عمل جديد إليكم.',
                            $cid_wo, BASE_URL . '/complaints/wo_view.php?id=' . $wo_id);
                    }
                    // إشعار الموظف المنفِّذ (التوجيه الداخلي) —
                    // إلا إذا عيّن المنشئ نفسه (لا معنى لإشعار الذات)
                    if ($assigned_emp && (int)$assigned_emp['id'] !== $uid) {
                        notify_sys($pdo, (int)$assigned_emp['id'], 'info',
                            'أُسند إليك أمر عمل: ' . $wo_number,
                            $mgr_note ?: 'كُلِّفت بتنفيذ أمر عمل جديد.',
                            $cid_wo, BASE_URL . '/complaints/wo_view.php?id=' . $wo_id);
                    }

                    $pdo->commit();
                    flash('success', "تم إنشاء {$wo_number} وإرساله إلى {$auto_con['name']} فوراً.");
                    header('Location: ' . BASE_URL . '/complaints/wo_view.php?id=' . $wo_id);
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    flash('danger', 'خطأ: ' . $e->getMessage());
                }
            }
        }
    }
    header('Location: ' . BASE_URL . '/complaints/view.php?id=' . $id);
    exit;
}

// ── جلب بيانات البلاغ ─────────────────────────────────────────────────────────
$s = $pdo->prepare("SELECT c.*, a.description AS asset_desc, a.tag_number, a.manufacturer_name, a.model_number, a.serial_number,
    a.date_placed_in_service, a.warranty_expiry, a.health_score, a.status AS asset_status, a.asset_number,
    d.name AS dept_name, u.full_name AS requester_name
    FROM complaints c
    LEFT JOIN assets a ON a.id = c.asset_id
    LEFT JOIN departments d ON d.id = c.dept_id
    LEFT JOIN users u ON u.id = c.requested_by
    WHERE c.id = ?");
$s->execute([$id]);
$c = $s->fetch(PDO::FETCH_ASSOC);
if (!$c) { header('Location: ' . BASE_URL . '/complaints/index.php'); exit; }

// ── التحقق من وجود أمر عمل نشط (Smart Logic) ─────────────────────────────────
$wo_check = $pdo->prepare("SELECT id, wo_number, status, contractor_name FROM complaint_work_orders 
    WHERE complaint_id = ? AND status NOT IN ('completed', 'cancelled', 'rejected_by_manager') 
    ORDER BY created_at DESC LIMIT 1");
$wo_check->execute([$id]);
$active_wo = $wo_check->fetch(PDO::FETCH_ASSOC);

$st = $c['status'] ?? 'open';
$can_edit = can('complaints.index', 'edit');
$can_approve = can('complaints.index', 'approve');
$can_manage = can('complaints.index', 'manage');
$is_owner = ($c['requested_by'] == $uid);

if (in_array($c['status'], ['escalated','resolved']) && !can_see_all()) {
    $can_edit = false; $can_approve = false; $can_manage = false;
}

// تجميد صلاحيات المدير عند وجود أمر عمل نشط
if ($active_wo && !can_see_all()) {
    $can_edit = false; $can_approve = false; $can_manage = false;
}

if (($can_edit || $can_approve || $can_manage) && !$is_owner && $c['status'] === 'open') {
    $already = $pdo->prepare("SELECT COUNT(*) FROM complaint_timeline WHERE complaint_id=? AND action_type='viewed' AND actor_id=?");
    $already->execute([$id, $uid]);
    if ($already->fetchColumn() == 0) {
        logTl($pdo, $id, 'viewed', 'تمت المعاينة الأولية من قبل المهندس', 'open', 'open', $uid);
    }
}

$STATUS_AR = [
    'open' => ['مفتوح', '#ef4444', 'fa-envelope-open-text'],
    'acknowledged' => ['مستلم', '#f59e0b', 'fa-handshake'],
    'in_progress' => ['جاري العمل', '#2563eb', 'fa-spinner'],
    'stalled' => ['متعثّر', '#d97706', 'fa-pause'],
    'escalated' => ['مُصعَّد', '#7c3aed', 'fa-arrow-up'],
    'resolved' => ['مُحلُول', '#16a34a', 'fa-check-circle'],
    'rejected' => ['مرفوض', '#dc2626', 'fa-ban'],
    'closed' => ['مغلق', '#64748b', 'fa-lock'],
];
$st_info = $STATUS_AR[$st] ?? ['—', '#94a3b8', 'fa-circle'];

$ACTION_ICONS = [
    'created' => 'fa-plus', 'acknowledged' => 'fa-handshake', 'started' => 'fa-play',
    'stalled' => 'fa-pause', 'escalated' => 'fa-arrow-up', 'resolved' => 'fa-check-circle',
    'resolution_rejected' => 'fa-rotate-left', 'resolved' => 'fa-wrench',
    'stalled' => 'fa-pause', 'closed' => 'fa-lock', 'acknowledged' => 'fa-handshake',
    'started' => 'fa-play', 'viewed' => 'fa-eye', 'wo_created' => 'fa-clipboard-list',
];

$t = $pdo->prepare("SELECT t.*, u.full_name AS actor_name FROM complaint_timeline t LEFT JOIN users u ON u.id=t.actor_id WHERE t.complaint_id=? ORDER BY t.created_at ASC");
$t->execute([$id]);
$timeline = $t->fetchAll(PDO::FETCH_ASSOC);

$at = $pdo->prepare("SELECT * FROM complaint_attachments WHERE complaint_id=?");
$at->execute([$id]);
$attachments = $at->fetchAll(PDO::FETCH_ASSOC);

$prev_complaints = []; $prev_total = 0;
if (!empty($c['asset_id'])) {
    $pc = $pdo->prepare("SELECT id, request_number, status, created_at, description FROM complaints WHERE asset_id=? AND id != ? ORDER BY created_at DESC LIMIT 5");
    $pc->execute([$c['asset_id'], $id]);
    $prev_complaints = $pc->fetchAll(PDO::FETCH_ASSOC);
    $ptc = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE asset_id=? AND id != ?");
    $ptc->execute([$c['asset_id'], $id]);
    $prev_total = (int) $ptc->fetchColumn();
}

$asset_age_txt = null;
if (!empty($c['date_placed_in_service'])) {
    $svc = new DateTime($c['date_placed_in_service']);
    $diff = $svc->diff(new DateTime());
    $asset_age_txt = $diff->y > 0 ? ($diff->y . ' سنة' . ($diff->m ? ' و' . $diff->m . ' شهر' : '')) : ($diff->m . ' شهر');
}

$warranty_info = null;
if (!empty($c['warranty_expiry'])) {
    $exp = new DateTime($c['warranty_expiry']);
    $now = new DateTime();
    $warranty_info = $exp > $now
        ? ['active' => true, 'label' => 'ساري حتى ' . $exp->format('Y-m-d'), 'days' => $now->diff($exp)->days]
        : ['active' => false, 'label' => 'منتهٍ منذ ' . $exp->format('Y-m-d'), 'days' => 0];
}

$wo_status_colors = ['draft'=>'#64748b','sent_to_contractor'=>'#d97706','in_progress'=>'#2563eb','pending_manager_approval'=>'#7c3aed','completed'=>'#16a34a','rejected_by_manager'=>'#dc2626','cancelled'=>'#94a3b8'];
$wo_status_labels = ['draft'=>'مسودة','sent_to_contractor'=>'أُرسل','in_progress'=>'جاري','pending_manager_approval'=>'بانتظار الاعتماد','completed'=>'مكتمل','rejected_by_manager'=>'مرفوض','cancelled'=>'مُلغى'];

$wo_stmt = $pdo->prepare("SELECT id, wo_number, status, contractor_name FROM complaint_work_orders WHERE complaint_id=? ORDER BY created_at DESC LIMIT 5");
$wo_stmt->execute([$c['id']]);
$wo_rows = $wo_stmt->fetchAll();

// جلب التذاكر المرتبطة بهذا البلاغ — تم تعطيل هذه الميزة بقرار المستخدم
// التذاكر لا تربط بالبلاغات بشكل مباشر
$tk_rows = [];

$page_title = 'تتبع البلاغ ' . $c['request_number'];
$active_nav = 'complaints.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#2563eb; }
        body { background: var(--bg); font-family:'Tajawal',sans-serif; }
        .eng { font-family:'Inter',sans-serif; }
        .wrap { max-width: 1700px; margin: 0 auto; padding: 22px; }
        .L-grid { display:grid; grid-template-columns: 360px 1fr; gap:20px; align-items:start; }
        @media(max-width:1100px){ .L-grid{ grid-template-columns:1fr; } }
        .L-shape { position:sticky; top:18px; border-radius:22px; overflow:hidden; box-shadow:0 10px 28px rgba(37,99,235,.1); border:1px solid #bfdbfe; }
        .L-head { background:linear-gradient(135deg,#dbeafe,#eff6ff); padding:18px 22px; border-bottom:1px solid #bfdbfe; }
        .L-head-top { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
        .L-num { font-size:18px; font-weight:900; color:#1e3a8a; }

/* ── العدّاد الحي ── */
.lvt{display:inline-flex;align-items:center;gap:6px;border-radius:50px;
  padding:4px 12px;font-weight:900;font-size:12px;border:1.5px solid transparent}
.lvt .lvt-d{width:8px;height:8px;border-radius:50%}
.lvt .lvt-v{font-family:'Inter',monospace;direction:ltr;letter-spacing:.5px}
.lvt .lvt-dd{font-family:'Tajawal',sans-serif;font-size:.85em}
.lvt .lvt-dd:empty{display:none}
.lvt-run{background:#f0fdf4;border-color:#bbf7d0;color:#166534}
.lvt-run .lvt-d{background:#16a34a;animation:lvtp 1.2s ease-in-out infinite}
.lvt-pause{background:#fffbeb;border-color:#fde68a;color:#92400e}
.lvt-pause .lvt-d{background:#f59e0b}
.lvt-done{background:#f1f5f9;border-color:#e2e8f0;color:#475569}
.lvt-done .lvt-d{background:#64748b}
@keyframes lvtp{0%,100%{box-shadow:0 0 0 0 rgba(22,163,74,.5)}
  50%{box-shadow:0 0 0 5px rgba(22,163,74,0)}}

        .L-sub { display:flex; flex-wrap:wrap; gap:10px; font-size:11px; font-weight:800; color:#1e40af; }
        .L-sub span { display:flex; align-items:center; gap:5px; }
        .status-pill { padding:6px 16px; border-radius:99px; font-weight:900; font-size:11.5px; background:var(--pc); color:#fff; box-shadow:0 0 14px var(--pc)66; white-space:nowrap; }
        .profile-row { display:flex; align-items:center; gap:9px; padding:9px 0; border-bottom:1px dashed var(--border); font-size:12.5px; }
        .profile-row:last-child { border-bottom:none; }
        .profile-row i { color:#2563eb; width:16px; text-align:center; }
        .profile-lbl { color:#475569; font-weight:800; min-width:92px; }
        .profile-val { color:var(--text); font-weight:900; flex:1; }
        .warranty-chip { padding:4px 11px; border-radius:99px; font-size:10.5px; font-weight:900; }
        .side-cards { padding:16px; background:#f8fafc; display:flex; flex-direction:column; gap:12px; }
        .s-card { border-radius:14px; overflow:hidden; border:1px solid var(--border); background:#fff; }
        .s-head { padding:9px 14px; font-size:11.5px; font-weight:900; color:#fff; display:flex; align-items:center; gap:7px; }
        .s-body { padding:13px 14px; }
        .bento { background:var(--card); border-radius:18px; box-shadow:0 4px 16px rgba(0,0,0,.04); border:1px solid var(--border); padding:22px; margin-bottom:16px; }
        .bento-h { font-size:14px; font-weight:900; margin:0 0 16px; display:flex; align-items:center; gap:8px; color:var(--text); }
        .bento-h i { color:var(--primary); }
        .problem-box { background:#f8fafc; border:1px solid var(--border); border-right:5px solid var(--primary); padding:20px; border-radius:14px; font-size:15px; font-weight:700; line-height:1.85; color:#334155; }
        .note-box { margin-top:14px; padding:16px 18px; border-radius:14px; font-size:13px; font-weight:700; line-height:1.7; }
        .act-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:10px; }
        .act-btn { padding:13px; border-radius:13px; border:none; font-size:13px; font-weight:900; color:#fff; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:.2s; }
        .act-btn:hover { transform:translateY(-2px); box-shadow:0 8px 16px rgba(0,0,0,.12); }
        .act-box { display:none; margin-top:14px; border-top:1px dashed var(--border); padding-top:14px; max-width:520px; }
        .act-box textarea { width:100%; border:2px solid var(--border); border-radius:12px; padding:12px; font-family:'Tajawal'; margin-bottom:10px; outline:none; font-size:13px; }
        .att-chip { display:flex; align-items:center; gap:10px; background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:10px 14px; text-decoration:none; margin-bottom:8px; }
        .upload-area { border:2px dashed #cbd5e1; border-radius:14px; padding:16px; text-align:center; cursor:pointer; position:relative; margin-top:12px; max-width:420px; }
        .file-pre { display:inline-flex; align-items:center; gap:6px; background:#eff6ff; color:#1d4ed8; font-size:11px; font-weight:800; padding:5px 10px; border-radius:8px; margin:4px 4px 0 0; }
        .tl-mini { display:flex; gap:9px; margin-bottom:11px; position:relative; }
        .tl-mini::after { content:''; position:absolute; left:11px; top:22px; bottom:-11px; width:1.5px; background:var(--border); }
        .tl-mini:last-child::after { display:none; }
        .tl-mini-dot { width:22px; height:22px; border-radius:50%; background:#f8fafc; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:9px; flex-shrink:0; z-index:1; }
        .tl-mini.last .tl-mini-dot { background:#eff6ff; border-color:var(--primary); color:var(--primary); }
        .tl-mini-txt h5 { margin:0 0 2px; font-size:11px; font-weight:900; color:var(--text); }
        .tl-mini-txt p { margin:0; font-size:9.5px; font-weight:700; color:var(--muted); }
        .prev-item { display:flex; align-items:center; gap:8px; padding:7px 0; border-bottom:1px dashed var(--border); text-decoration:none; }
        .prev-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
        .fda-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; }
        .fda-kpi { background:#fff; border:1px solid #bae6fd; border-radius:10px; padding:9px; text-align:center; }
        .fda-kpi .v { font-size:16px; font-weight:900; }
        .fda-kpi .l { font-size:8.5px; font-weight:800; margin-top:2px; }
        .btn-translate { background: #fff; color: #d97706; border: 1px solid #fcd34d; padding: 6px 14px; border-radius: 8px; font-size: 11.5px; font-weight: 900; font-family: 'Tajawal', sans-serif; cursor: pointer; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; }
        .btn-translate:hover { background: #fef3c7; transform: translateY(-1px); }
        .translation-text { direction: rtl; text-align: right; font-family: 'Tajawal', sans-serif; font-size: 13px; font-weight: 800; color: #9a3412; width: 100%; line-height: 1.6; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
    <?php include BASE_PATH . '/includes/topbar.php'; ?>
    <main class="page-content">
        <div class="wrap">
            <?php foreach (get_flash() as $fm):
                $ffc = ['success'=>'#10b981','warning'=>'#f59e0b','info'=>'#3b82f6','danger'=>'#ef4444'][$fm['type']] ?? '#3b82f6'; ?>
                <div style="background:#fff;border:1px solid <?= $ffc ?>55;border-right:4px solid <?= $ffc ?>;padding:13px 18px;border-radius:12px;margin-bottom:16px;font-weight:800;font-size:13px"><?= e($fm['message']) ?></div>
            <?php endforeach; ?>
            <?php if ($errors): foreach ($errors as $er): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:13px 18px;border-radius:12px;margin-bottom:16px;font-weight:800;font-size:13px"><i class="fa-solid fa-circle-exclamation"></i> <?= e($er) ?></div>
            <?php endforeach; endif; ?>

            <div class="L-grid">
                <div class="L-shape">
                    <div class="L-head">
                        <div class="L-head-top">
                            <span class="L-num eng">#<?= e($c['request_number']) ?></span>
                            <span class="status-pill" style="--pc:<?= $st_info[1] ?>"><i class="fa-solid <?= $st_info[2] ?>"></i> <?= e($st_info[0]) ?></span>
<?php
    // بيانات العدّاد الحي: بداية / إيقافات متراكمة / إيقاف جارٍ / نهاية
    $_lv_end = ($c['status'] === 'resolved' || $c['status'] === 'closed')
        ? strtotime($c['closed_at'] ?: $c['resolved_at'] ?: 'now') : 0;
    $_lv_lbl = $_lv_end ? 'صافي وقت المعالجة'
        : (!empty($c['sla_paused_at']) ? 'متوقف — ' . e($c['sla_pause_reason'] ?: 'خارج مسؤولية الفريق')
        : 'وقت المعالجة الجاري');
?>
                            <span class="lvt" title="<?= $_lv_lbl ?>"
                                data-s="<?= strtotime($c['created_at']) ?>"
                                data-p="<?= (int)($c['sla_paused_seconds_total'] ?? 0) ?>"
                                data-pa="<?= !empty($c['sla_paused_at']) && !$_lv_end ? strtotime($c['sla_paused_at']) : 0 ?>"
                                data-e="<?= $_lv_end ?>">
                                <span class="lvt-d"></span>
                                <span class="lvt-dd"></span><span class="lvt-v">—</span>
                                <?php if (!$_lv_end && !empty($c['sla_paused_at'])): ?><span>⏸</span>
                                <?php elseif ($_lv_end): ?><span>✓</span><?php endif; ?>
                            </span>
                        </div>
                        <div class="L-sub">
                            <span><i class="fa-solid fa-hospital-user"></i> <?= e($c['dept_name'] ?? '—') ?></span>
                            <span><i class="fa-solid fa-user-nurse"></i> <?= e($c['requester_name'] ?? '—') ?></span>
                            <span class="eng"><i class="fa-solid fa-calendar-day"></i> <?= date('Y-m-d H:i', strtotime($c['created_at'])) ?></span>
                        </div>
                    </div>
                    <div class="side-cards">
                        <?php if ($c['asset_id']): ?>
                            <div class="s-card">
                                <div class="s-head" style="background:linear-gradient(135deg,#1d4ed8,#2563eb)"><i class="fa-solid fa-id-card"></i> ملف الجهاز</div>
                                <div class="s-body">
                                    <div class="profile-row"><i class="fa-solid fa-microchip"></i><span class="profile-lbl">الجهاز</span><span class="profile-val" style="font-size:11.5px"><?= e($c['asset_desc']) ?></span></div>
                                    <div class="profile-row"><i class="fa-solid fa-hashtag"></i><span class="profile-lbl">رقم الأصل</span><span class="profile-val eng"><?= e($c['asset_number'] ?: '—') ?></span></div>
                                    <div class="profile-row"><i class="fa-solid fa-tag"></i><span class="profile-lbl">التاج نمبر</span><span class="profile-val eng"><?= e($c['tag_number'] ?: '—') ?></span></div>
                                    <div class="profile-row"><i class="fa-solid fa-industry"></i><span class="profile-lbl">الشركة/الموديل</span><span class="profile-val" style="font-size:11.5px"><?= e(trim(($c['manufacturer_name'] ?? '').' / '.($c['model_number'] ?? ''), ' /') ?: '—') ?></span></div>
                                    <div class="profile-row"><i class="fa-solid fa-hourglass-half"></i><span class="profile-lbl">العمر</span><span class="profile-val eng"><?= e($asset_age_txt ?? '—') ?></span></div>
                                    <div class="profile-row"><i class="fa-solid fa-shield-halved"></i><span class="profile-lbl">الضمان</span>
                                        <?php $wbg = $warranty_info ? ($warranty_info['active'] ? '#dcfce7' : '#fee2e2') : '#fff'; $wfg = $warranty_info ? ($warranty_info['active'] ? '#15803d' : '#b91c1c') : '#64748b'; $wlbl = $warranty_info ? e($warranty_info['label']) : 'بلا ضمان'; ?>
                                        <span class="warranty-chip" style="background:<?= $wbg ?>;color:<?= $wfg ?>"><?= $wlbl ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="s-card">
                                <div class="s-head" style="background:linear-gradient(135deg,#1d4ed8,#2563eb)"><i class="fa-solid fa-building"></i> الموقع</div>
                                <div class="s-body" style="font-size:12px;color:#475569;font-weight:700"><?= e($c['location_description'] ?? 'بلاغ صيانة عامة') ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!$active_wo): ?>
                            <?php if (!in_array($c['status'], ['closed', 'cancelled', 'rejected']) && $can_manage): ?>
                                <button type="button" onclick="document.getElementById('woModal').style.display='flex'" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:13px;border-radius:13px;background:linear-gradient(135deg,#0e7490,#0891b2);color:#fff;font-size:13px;font-weight:900;border:none;cursor:pointer;width:100%">
                                    <i class="fa-solid fa-clipboard-list"></i> إنشاء أمر عمل
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div style="background:#eff6ff;border:1px solid #bae6fd;border-right:4px solid #0284c7;border-radius:14px;padding:14px;display:flex;align-items:center;gap:12px">
                                <i class="fa-solid fa-circle-info" style="font-size:20px;color:#0284c7;flex-shrink:0"></i>
                                <div style="flex:1">
                                    <div style="font-size:12px;font-weight:900;color:#0c4a6e">يوجد أمر عمل نشط</div>
                                    <div style="font-size:10px;color:#0369a1;font-weight:700;margin-top:2px">
                                        <span class="eng"><?= e($active_wo['wo_number']) ?></span> · <?= e($active_wo['contractor_name']) ?>
                                    </div>
                                </div>
                                <a href="<?= BASE_URL ?>/complaints/wo_view.php?id=<?= $active_wo['id'] ?>" style="background:#0284c7;color:#fff;padding:6px 12px;border-radius:8px;font-size:10px;font-weight:900;text-decoration:none">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> عرض
                                </a>
                            </div>
                        <?php endif; ?>

                        <?php if ($c['asset_id']): ?>
                            <div class="s-card">
                                <div class="s-head" style="background:linear-gradient(135deg,#0e7490,#0891b2)"><i class="fa-solid fa-clipboard-list"></i> أوامر العمل</div>
                                <div class="s-body">
                                    <?php if ($wo_rows): foreach ($wo_rows as $woi):
                                        $woc = $wo_status_colors[$woi['status']] ?? '#94a3b8';
                                        $wol = $wo_status_labels[$woi['status']] ?? '—'; ?>
                                        <a href="<?= BASE_URL ?>/complaints/wo_view.php?id=<?= $woi['id'] ?>" style="display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:8px 11px;text-decoration:none;margin-bottom:6px">
                                            <i class="fa-solid fa-file-lines" style="color:#0e7490;font-size:13px"></i>
                                            <span style="flex:1;font-size:11.5px;font-weight:800;color:#0f172a;font-family:'Inter',sans-serif"><?= e($woi['wo_number']) ?></span>
                                            <span style="background:<?= $woc ?>22;color:<?= $woc ?>;font-size:9.5px;font-weight:900;padding:2px 8px;border-radius:99px"><?= e($wol) ?></span>
                                        </a>
                                    <?php endforeach; else: ?>
                                        <div style="font-size:11.5px;color:var(--muted);font-weight:700">لا توجد أوامر عمل.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="s-card">
                                <div class="s-head" style="background:linear-gradient(135deg,#0e7490,#0891b2)"><i class="fa-solid fa-globe"></i> تقارير FDA</div>
                                <div class="s-body">
                                    <div id="fdaLoading" style="font-size:11.5px;font-weight:800;color:#0e7490"><i class="fa-solid fa-circle-notch fa-spin"></i> جاري الاستدعاء...</div>
                                    <div class="fda-grid" id="fdaWrap" style="display:none"></div>
                                    <button type="button" id="fdaDetailsBtn" onclick="openFdaModal()" style="display:none;width:100%;margin-top:10px;background:#0e7490;color:#fff;border:none;padding:9px;border-radius:10px;font-size:11.5px;font-weight:900;cursor:pointer"><i class="fa-solid fa-up-right-and-down-left-from-center"></i> عرض التقارير التفصيلية</button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="s-card">
                            <div class="s-head" style="background:linear-gradient(135deg,#047857,#059669)"><i class="fa-solid fa-timeline"></i> آخر تحرّكات</div>
                            <div class="s-body">
                                <?php foreach (array_slice(array_reverse($timeline), 0, 4) as $idx => $tl): ?>
                                    <div class="tl-mini <?= $idx === 0 ? 'last' : '' ?>">
                                        <div class="tl-mini-dot"><i class="fa-solid fa-check"></i></div>
                                        <div class="tl-mini-txt">
                                            <h5><?= e(mb_substr($tl['action_label'], 0, 38)) ?></h5>
                                            <p><?= e($tl['actor_name'] ?? '—') ?> · <span class="eng"><?= date('d/m H:i', strtotime($tl['created_at'])) ?></span></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (count($timeline) > 4): ?>
                                    <div style="font-size:10px;color:var(--muted);font-weight:800;text-align:center;margin-top:4px">+<?= count($timeline) - 4 ?> حدثاً أقدم (السجل الكامل أدناه)</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($c['asset_id']): ?>
                            <div class="s-card">
                                <div class="s-head" style="background:linear-gradient(135deg,#475569,#64748b)"><i class="fa-solid fa-clock-rotate-left"></i> السجل التاريخي (<?= $prev_total ?>)</div>
                                <div class="s-body">
                                    <?php if ($prev_complaints): foreach ($prev_complaints as $pcm):
                                        $pst = $STATUS_AR[$pcm['status']] ?? ['—', '#94a3b8', 'fa-circle']; ?>
                                        <a href="<?= BASE_URL ?>/complaints/view.php?id=<?= $pcm['id'] ?>" class="prev-item">
                                            <span class="prev-dot" style="background:<?= $pst[1] ?>"></span>
                                            <span style="flex:1;font-size:10.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr($pcm['description'], 0, 32)) ?></span>
                                        </a>
                                    <?php endforeach; else: ?>
                                        <div style="font-size:10.5px;color:var(--muted);font-weight:700">أول بلاغ على هذا الجهاز.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="bento">
                        <div class="bento-h"><i class="fa-solid fa-quote-right"></i> التشخيص الفني</div>
                        <div class="problem-box"><?= nl2br(e($c['description'])) ?></div>
                        <?php if (!empty($c['stall_reason']) && $c['status'] !== 'in_progress'): ?>
                            <div class="note-box" style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412">
                                <strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-pause"></i> سبب التعثّر السابق:</strong><?= nl2br(e($c['stall_reason'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['escalation_note'])): ?>
                            <div class="note-box" style="background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d">
                                <strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-arrow-up"></i> ملاحظة التصعيد:</strong><?= nl2br(e($c['escalation_note'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['resolution_rejected_reason'])): ?>
                            <div class="note-box" style="background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d">
                                <strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-rotate-left"></i> رفض القسم الحل السابق:</strong><?= nl2br(e($c['resolution_rejected_reason'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['resolution_notes'])): ?>
                            <div class="note-box" style="background:#f0fdf4;border:1px solid #a7f3d0;color:#065f46">
                                <strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-wrench"></i> تقرير الإصلاح:</strong><?= nl2br(e($c['resolution_notes'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['rejection_note'])): ?>
                            <div class="note-box" style="background:#f1f5f9;border:1px solid #cbd5e1;color:#334155">
                                <strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-ban"></i> سبب رفض البلاغ:</strong><?= nl2br(e($c['rejection_note'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($c['priority_changed_at'])):
                            $origLabel = ['normal' => 'عادي', 'urgent' => 'عاجل', 'critical' => 'طارئ']; ?>
                            <div class="note-box" style="background:#f5f3ff;border:1px solid #ddd6fe;color:#5b21b6">
                                <strong style="display:block;margin-bottom:6px"><i class="fa-solid fa-gauge-high"></i> أُعيد تصنيف الأولوية من "<?= e($origLabel[$c['priority_original']] ?? '—') ?>":</strong><?= nl2br(e($c['priority_change_reason'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="bento">
                        <div class="bento-h"><i class="fa-solid fa-paperclip" style="color:#d97706"></i> المرفقات (<?= count($attachments) ?>)</div>
                        <?php foreach ($attachments as $att): ?>
                            <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank" class="att-chip">
                                <i class="fa-solid fa-file" style="color:#d97706"></i>
                                <span style="font-size:12.5px;font-weight:800;color:#78350f"><?= e($att['file_name']) ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (!in_array($c['status'], ['closed', 'cancelled', 'rejected', 'escalated', 'resolved']) && ($can_edit || $can_approve || $can_manage)): ?>
                            <form method="POST" enctype="multipart/form-data" style="margin-top:14px">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="add_attachment">
                                <div class="upload-area" onclick="document.getElementById('attInput').click()">
                                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:24px;color:var(--muted)"></i>
                                    <div style="font-size:12px;font-weight:800;color:var(--muted);margin-top:6px">اضغط لرفع مرفقات (حد أقصى 5)</div>
                                    <input type="file" id="attInput" name="new_attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx" style="display:none" onchange="showAttPreview(this.files)">
                                </div>
                                <div id="attPre" style="margin-top:10px"></div>
                                <button type="submit" class="act-btn" style="background:#d97706;margin-top:10px"><i class="fa-solid fa-upload"></i> رفع المرفقات</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (!$active_wo): ?>
                        <div class="bento">
                            <div class="bento-h"><i class="fa-solid fa-gears"></i> الإجراءات المتاحة</div>
                            <form method="POST" id="actForm"><?= csrf_input() ?><input type="hidden" name="action" id="actField" value="">
                                <div class="act-grid">
                                    <?php if ($c['status'] === 'open' && $can_edit): ?>
                                        <button type="button" class="act-btn" style="background:#2563eb" onclick="doAct('acknowledge')"><i class="fa-solid fa-handshake"></i> استلام</button>
                                    <?php endif; ?>
                                    <?php if (in_array($c['status'], ['acknowledged', 'stalled', 'escalated']) && $can_edit): ?>
                                        <button type="button" class="act-btn" style="background:#16a34a" onclick="doAct('start')"><i class="fa-solid fa-play"></i> بدء العمل</button>
                                    <?php endif; ?>
                                    <?php if ($c['status'] === 'in_progress' && $can_edit): ?>
                                        <button type="button" class="act-btn" style="background:#d97706" onclick="toggleBox('stallBox')"><i class="fa-solid fa-pause"></i> تعثّر</button>
                                    <?php endif; ?>
                                    <?php if (in_array($c['status'], ['open', 'acknowledged', 'in_progress', 'stalled']) && $can_manage): ?>
                                        <button type="button" class="act-btn" style="background:#fff;color:#b91c1c;border:2px solid #fecaca" onclick="toggleBox('escBox')"><i class="fa-solid fa-arrow-up"></i> تصعيد</button>
                                    <?php endif; ?>
                                    <?php if (in_array($c['status'], ['in_progress', 'stalled', 'escalated']) && $can_approve): ?>
                                        <button type="button" class="act-btn" style="background:#16a34a" onclick="toggleBox('resBox')"><i class="fa-solid fa-circle-check"></i> حل البلاغ</button>
                                    <?php endif; ?>
                                    <?php if ($c['status'] === 'resolved' && $can_manage): ?>
                                        <button type="button" class="act-btn" style="background:#dc2626" onclick="toggleBox('rejBox')"><i class="fa-solid fa-ban"></i> رفض الحل</button>
                                    <?php endif; ?>
                                    <?php if (!in_array($c['status'], ['closed', 'cancelled', 'rejected']) && $can_manage): ?>
                                        <button type="button" class="act-btn" style="background:#64748b" onclick="toggleBox('prioBox')"><i class="fa-solid fa-gauge-high"></i> إعادة تصنيف</button>
                                    <?php endif; ?>
                                    <?php if (!in_array($c['status'], ['closed', 'cancelled', 'rejected']) && $can_manage): ?>
                                        <button type="button" class="act-btn" style="background:#475569" onclick="doAct('close')"><i class="fa-solid fa-lock"></i> إغلاق إداري</button>
                                    <?php endif; ?>
                                </div>
                                <div class="act-box" id="stallBox">
                                    <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">سبب التعثّر <span style="color:#dc2626">*</span></label>
                                    <textarea name="reason" rows="2"></textarea>
                                    <button type="button" class="act-btn" style="background:#d97706" onclick="doAct('stall')">تأكيد</button>
                                </div>
                                <div class="act-box" id="escBox">
                                    <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">ملاحظة التصعيد</label>
                                    <textarea name="note" rows="2"></textarea>
                                    <button type="button" class="act-btn" style="background:#7c3aed" onclick="doAct('escalate')">تأكيد التصعيد</button>
                                </div>
                                <div class="act-box" id="resBox">
                                    <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">تقرير الإصلاح <span style="color:#dc2626">*</span></label>
                                    <textarea name="notes" rows="3"></textarea>
                                    <button type="button" class="act-btn" style="background:#16a34a" onclick="doAct('resolve')">تأكيد الحل</button>
                                </div>
                                <div class="act-box" id="rejBox">
                                    <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">سبب الرفض <span style="color:#dc2626">*</span></label>
                                    <textarea name="note" rows="2"></textarea>
                                    <button type="button" class="act-btn" style="background:#64748b" onclick="doAct('reject')">تأكيد</button>
                                </div>
                                <div class="act-box" id="prioBox">
                                    <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">الأولوية الجديدة</label>
                                    <select name="new_priority" style="margin-bottom:8px">
                                        <option value="normal">عادي</option>
                                        <option value="urgent">عاجل</option>
                                        <option value="critical">طارئ</option>
                                    </select>
                                    <label style="font-size:12px;font-weight:900;display:block;margin-bottom:6px">سبب التغيير <span style="color:#dc2626">*</span></label>
                                    <textarea name="priority_reason" rows="2"></textarea>
                                    <button type="button" class="act-btn" style="background:#7c3aed" onclick="doAct('reprioritize')">حفظ الأولوية الجديدة</button>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-right:4px solid #16a34a;border-radius:14px;padding:18px;display:flex;align-items:center;gap:14px;margin-bottom:16px">
                            <i class="fa-solid fa-circle-check" style="font-size:24px;color:#16a34a;flex-shrink:0"></i>
                            <div>
                                <div style="font-size:13.5px;font-weight:900;color:#14532d">أمر العمل نشط لدى الشركة المتعاقدة</div>
                                <div style="font-size:12px;color:#166534;font-weight:700;margin-top:4px">جميع الإجراءات موقّتة حتى انتهاء أمر العمل <span class="eng"><?= e($active_wo['wo_number']) ?></span></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($c['status'], ['resolved', 'closed'])):
                        $end_ts   = strtotime($c['closed_at'] ?: $c['resolved_at'] ?: 'now');
                        $gross    = max(0, $end_ts - strtotime($c['created_at']));
                        $paused   = (int)($c['sla_paused_seconds_total'] ?? 0);
                        $net      = max(0, $gross - $paused);
                        $fmt_dur  = function (int $s): string {
                            $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600);
                            $m = intdiv($s % 3600, 60);
                            $out = [];
                            if ($d) $out[] = $d . ' يوم';
                            if ($h) $out[] = $h . ' ساعة';
                            if ($m || !$out) $out[] = $m . ' دقيقة';
                            return implode(' و', $out);
                        };
                        $rate = (int)($c['service_rating'] ?? 0);
                    ?>
                    <div class="bento">
                        <div class="bento-h" style="background:linear-gradient(135deg,#065f46,#059669)">
                            <i class="fa-solid fa-gauge-high"></i> حصيلة البلاغ — الأداء والتقييم</div>
                        <div class="bento-b">
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px">
                                <div style="background:#f8fafc;border:1px solid var(--border);border-radius:11px;padding:11px;text-align:center">
                                    <div style="font-size:10.5px;font-weight:800;color:var(--muted)">المدة الكلية (من الفتح للحل)</div>
                                    <div style="font-size:15px;font-weight:900;color:#0f172a;margin-top:4px"><?= e($fmt_dur($gross)) ?></div>
                                </div>
                                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:11px;padding:11px;text-align:center">
                                    <div style="font-size:10.5px;font-weight:800;color:#92400e">وقت خارج مسؤولية الفريق ⏸️</div>
                                    <div style="font-size:15px;font-weight:900;color:#92400e;margin-top:4px"><?= $paused ? e($fmt_dur($paused)) : '—' ?></div>
                                    <div style="font-size:9.5px;color:#a16207;margin-top:2px">لدى الشركة أو لجنة المتابعة</div>
                                </div>
                                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:11px;padding:11px;text-align:center">
                                    <div style="font-size:10.5px;font-weight:800;color:#166534">صافي وقت المعالجة ⚡</div>
                                    <div style="font-size:15px;font-weight:900;color:#166534;margin-top:4px"><?= e($fmt_dur($net)) ?></div>
                                </div>
                            </div>
                            <?php if ($c['status'] === 'closed'): ?>
                            <div style="display:flex;align-items:center;gap:12px;background:#f8fafc;border:1px solid var(--border);border-radius:11px;padding:11px 14px">
                                <div style="font-size:11.5px;font-weight:800;color:var(--muted)">تقييم المستفيد للخدمة:</div>
                                <?php if ($rate): ?>
                                <div style="font-size:16px;letter-spacing:2px">
                                    <?php for ($si = 1; $si <= 5; $si++): ?><span style="color:<?= $si <= $rate ? '#f59e0b' : '#e2e8f0' ?>">★</span><?php endfor; ?>
                                </div>
                                <div style="font-size:12px;font-weight:900;color:#b45309"><?= $rate ?> / 5</div>
                                <?php if (!empty($c['service_comment'])): ?>
                                <div style="font-size:12px;color:#475569;font-weight:700;border-right:2px solid #e2e8f0;padding-right:12px">"<?= e($c['service_comment']) ?>"</div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div style="font-size:12px;color:var(--muted)">أُغلق دون تقييم (إغلاق إداري أو من اللجنة)</div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="bento">
                        <div class="bento-h"><i class="fa-solid fa-list-check"></i> سجل حركة البلاغ الكامل</div>
                        <?php foreach ($timeline as $idx => $tl):
                            $isLast = $idx === count($timeline) - 1;
                            $icon = $ACTION_ICONS[$tl['action_type']] ?? 'fa-check'; ?>
                            <div class="tl-mini <?= $isLast ? 'last' : '' ?>" style="margin-bottom:18px">
                                <div class="tl-mini-dot" style="width:32px;height:32px;font-size:12px"><i class="fa-solid <?= $icon ?>"></i></div>
                                <div class="tl-mini-txt">
                                    <h5 style="font-size:13px"><?= e($tl['action_label']) ?></h5>
                                    <p style="font-size:11px"><?= e($tl['actor_name'] ?? 'النظام') ?> · <span class="eng"><?= date('d/m H:i', strtotime($tl['created_at'])) ?></span></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="woModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:16px;width:92%;max-width:640px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,0.3);overflow:hidden">
        <?php
        $svc_map_preview = ['medical' => 'medical_maintenance', 'it' => 'it', 'general' => 'general_maintenance'];
        $cur_req_type = $c['request_type'] ?? 'medical';
        $modal_colors = [
            'medical' => ['#0e7490','#0891b2','طبي','fa-stethoscope'],
            'general' => ['#16a34a','#15803d','صيانة عامة','fa-screwdriver-wrench'],
            'it'      => ['#7c3aed','#6d28d9','تقنية المعلومات','fa-laptop-code'],
        ];
        $mc = $modal_colors[$cur_req_type] ?? $modal_colors['medical'];
        ?>
        <div style="background:linear-gradient(135deg,<?= $mc[0] ?>,<?= $mc[1] ?>);padding:14px 18px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">
            <span style="font-weight:900;font-size:14px"><i class="fa-solid <?= $mc[3] ?>"></i> إنشاء أمر عمل — <?= $mc[2] ?></span>
            <button onclick="document.getElementById('woModal').style.display='none'" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer">✕</button>
        </div>
        <form method="POST" style="padding:18px;overflow-y:auto;flex:1">
            <?= csrf_input() ?>
            <input type="hidden" name="_wo_action" value="create_wo">
            <input type="hidden" name="wo_complaint_id" value="<?= $c['id'] ?>">
            <input type="hidden" name="wo_type" value="<?= e($cur_req_type) ?>">

            <div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;padding:11px 13px;margin-bottom:14px;font-size:12px;color:#0f766e;font-weight:700">
                <i class="fa-solid fa-robot"></i> سيتم توجيه الأمر تلقائياً إلى:
                <strong>
                <?php
                $preview_con = $pdo->prepare("SELECT name, is_internal FROM contractors WHERE service_type = ? AND is_active=1 LIMIT 1");
                $preview_con->execute([$svc_map_preview[$cur_req_type] ?? 'general_maintenance']);
                $pc = $preview_con->fetch();
                echo $pc ? e($pc['name']) : '⚠️ لا توجد شركة نشطة';
                ?>
                </strong>
                <?php if ($pc && !empty($pc['is_internal'])):
                    $emp_q = $pdo->prepare(
                        "SELECT u.id, u.full_name FROM users u
                         JOIN departments d ON d.id = u.department_id
                         WHERE u.is_active=1 AND d.dept_category=?
                         ORDER BY u.full_name");
                    $emp_q->execute(['maintenance_' . $cur_req_type]);
                    $emp_list = $emp_q->fetchAll();
                ?>
                <div style="margin-top:9px">
                    <label style="font-size:11px;font-weight:800;color:#166534;display:block;margin-bottom:4px">
                        الموظف المنفِّذ <span style="color:#dc2626">*</span>
                        <span style="color:#64748b;font-weight:600">— توجيه داخلي: يُسند الأمر لموظف من القسم</span></label>
                    <select name="assigned_user_id" required
                        style="width:100%;border:1.5px solid #bbf7d0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12.5px;background:#fff">
                        <option value="">— اختر الموظف —</option>
                        <?php foreach ($emp_list as $emp): ?>
                        <option value="<?= (int)$emp['id'] ?>"><?= e($emp['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($cur_req_type === 'general'): ?>
                <!-- ═══ نموذج الصيانة العامة ═══ -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">طبيعة العمل <span style="color:#dc2626">*</span></label>
                        <select name="g_work_nature" required style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12.5px">
                            <option value="">— اختر —</option>
                            <option value="corrective">صيانة تصحيحية (إصلاح عطل)</option>
                            <option value="preventive">صيانة وقائية / دورية</option>
                            <option value="emergency">صيانة طارئة</option>
                            <option value="improvement">تحسين / تطوير</option>
                            <option value="new_installation">تركيب جديد</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">نطاق العمل</label>
                        <select name="g_work_scope" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12.5px">
                            <option value="">—</option>
                            <option value="minor">بسيط (Minor)</option>
                            <option value="major">رئيسي (Major)</option>
                            <option value="overhaul">صيانة شاملة (Overhaul)</option>
                            <option value="replacement">استبدال كامل</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">المبنى</label>
                        <input type="text" name="g_building" placeholder="مثال: المبنى الرئيسي" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">الطابق</label>
                        <input type="text" name="g_floor" placeholder="الأرضي / الأول..." style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">تفاصيل الموقع</label>
                        <input type="text" name="g_location_detail" placeholder="غرفة / ممر" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                    </div>
                </div>

                <div style="margin-bottom:10px">
                    <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">الأنظمة المتأثرة (يمكن اختيار أكثر من واحد)</label>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">
                        <?php foreach (['كهرباء','سباكة','تكييف','إضاءة','أبواب/نوافذ','أثاث','أرضيات','دهانات','شبكات','حريق','أمن','مصاعد'] as $sys): ?>
                        <label style="display:flex;align-items:center;gap:5px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:6px 9px;font-size:11.5px;font-weight:700;cursor:pointer">
                            <input type="checkbox" name="g_affected_systems[]" value="<?= e($sys) ?>" style="width:14px;height:14px"> <?= $sys ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">المدة المقدرة (ساعة)</label>
                        <input type="number" name="g_est_hours" step="0.5" min="0" placeholder="0" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">عدد الفريق</label>
                        <input type="number" name="g_team_size" min="1" placeholder="1" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">يتطلب تصريح؟</label>
                        <div style="display:flex;gap:12px;padding-top:6px">
                            <label style="display:flex;align-items:center;gap:4px;font-size:12px;font-weight:700"><input type="checkbox" name="g_permit_required" value="1" style="width:14px;height:14px"> نعم</label>
                            <input type="text" name="g_permit_type" placeholder="نوع التصريح" style="flex:1;border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 9px;font-size:11.5px">
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:10px">
                    <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">إجراءات السلامة المتخذة</label>
                    <textarea name="g_safety_measures" rows="2" placeholder="مثال: فصل التيار الكهربائي، وضع لافتات تحذيرية، ارتداء معدات الحماية..." style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px"></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">الأدوات المستخدمة</label>
                        <textarea name="g_tools_used" rows="2" placeholder="مثال: مفكات، مثقاب، جهاز قياس..." style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px"></textarea>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">المواد المستخدمة</label>
                        <textarea name="g_materials_used" rows="2" placeholder="مثال: أسلاك، مفاتيح، أنابيب..." style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px"></textarea>
                    </div>
                </div>

                <div style="margin-bottom:10px">
                    <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">السبب الجذري (Root Cause)</label>
                    <textarea name="g_root_cause" rows="2" placeholder="ما الذي تسبب في هذا العطل؟" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px"></textarea>
                </div>

                <div style="margin-bottom:10px">
                    <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">التوصيات</label>
                    <textarea name="g_recommendation" rows="2" placeholder="إجراءات وقائية مقترحة لمنع تكرار المشكلة" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px"></textarea>
                </div>

            <?php elseif ($cur_req_type === 'it'): ?>
                <!-- ═══ نموذج تقنية المعلومات ═══ -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">تصنيف العمل <span style="color:#dc2626">*</span></label>
                        <select name="i_category" required style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12.5px">
                            <option value="">— اختر —</option>
                            <option value="hardware">أجهزة (Hardware)</option>
                            <option value="software">برمجيات (Software)</option>
                            <option value="network">شبكات (Network)</option>
                            <option value="security">أمن سيبراني (Security)</option>
                            <option value="user_support">دعم مستخدمين (Helpdesk)</option>
                            <option value="server">خوادم / بنية تحتية</option>
                            <option value="printer">طابعات / ملحقات</option>
                            <option value="telephony">اتصالات / هاتف IP</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">نوع الأصل التقني</label>
                        <input type="text" name="i_asset_type" placeholder="PC / Laptop / Server / Switch..." style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                    </div>
                </div>

                <div style="margin-bottom:10px">
                    <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">نظام التشغيل والإصدار</label>
                    <input type="text" name="i_os" placeholder="Windows 11 Pro 23H2 / Ubuntu 22.04..." style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                </div>

                <div style="margin-bottom:10px">
                    <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">البرامج المتأثرة</label>
                    <textarea name="i_software" rows="2" placeholder="قائمة البرامج التي تأثرت بالمشكلة" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px"></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:10px">
                    <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:7px 9px;cursor:pointer">
                        <input type="checkbox" name="i_backup" value="1" style="width:14px;height:14px"> تم نسخ احتياطي
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:7px 9px;cursor:pointer">
                        <input type="checkbox" name="i_data_loss" value="1" style="width:14px;height:14px;color:#dc2626"> فقدان بيانات
                    </label>
                    <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;padding:7px 9px;cursor:pointer">
                        <input type="checkbox" name="i_remote" value="1" style="width:14px;height:14px"> حل عن بُعد
                    </label>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">مرجع طلب التغيير (CR)</label>
                        <input type="text" name="i_cr_ref" placeholder="CR-2024-001" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-size:12px">
                    </div>
                    <div>
                        <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">تصنيف السبب الجذري</label>
                        <select name="i_root_category" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px">
                            <option value="">—</option>
                            <option value="human_error">خطأ بشري</option>
                            <option value="hardware_failure">عطل مادي</option>
                            <option value="software_bug">خطأ برمجي</option>
                            <option value="configuration">خطأ إعداد</option>
                            <option value="external_attack">هجوم خارجي</option>
                            <option value="capacity">استنفاد سعة</option>
                            <option value="third_party">طرف ثالث</option>
                            <option value="unknown">غير محدد</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:10px">
                    <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">إجراء وقائي</label>
                    <textarea name="i_preventive" rows="2" placeholder="ما يجب فعله لمنع تكرار المشكلة" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12px"></textarea>
                </div>


            <?php else: ?>
                <!-- ═══ النموذج الطبي (افتراضي) ═══ -->
                <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px;margin-bottom:12px;font-size:12px;color:#0c4a6e;font-weight:700">
                    <i class="fa-solid fa-stethoscope"></i> أمر عمل طبي — سيتم إرسال ملاحظاتك للشركة المتعاقدة فوراً.
                </div>
            <?php endif; ?>

            <label style="font-size:11px;font-weight:800;color:#64748b;display:block;margin-bottom:4px">ملاحظات وتوجيهات المدير الفني <?php if ($cur_req_type === 'it'): ?><span style="color:#dc2626">*</span><?php else: ?><span style="color:#94a3b8;font-weight:500">(اختياري)</span><?php endif; ?></label>
            <textarea name="wo_manager_note" rows="2" <?= $cur_req_type === 'it' ? 'required' : '' ?> placeholder="<?= $cur_req_type === 'it' ? 'وصف المطلوب من المنفِّذ — إلزامي لأوامر تقنية المعلومات' : 'ملاحظات إضافية تظهر في وصف أمر العمل' ?>" style="width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 10px;font-family:Tajawal;font-size:12.5px;outline:none;resize:vertical"></textarea>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px">
                <button type="button" onclick="document.getElementById('woModal').style.display='none'" style="background:#f1f5f9;color:#475569;padding:10px 18px;border-radius:10px;border:none;font-weight:800;cursor:pointer">إلغاء</button>
                <button type="submit" style="background:linear-gradient(135deg,<?= $mc[0] ?>,<?= $mc[1] ?>);color:#fff;padding:10px 20px;border-radius:10px;border:none;font-weight:800;cursor:pointer" <?= !$pc ? 'disabled style="opacity:0.5;cursor:not-allowed"' : '' ?>>
                    <i class="fa-solid fa-paper-plane"></i> إنشاء وإرسال فوراً
                </button>
            </div>
        </form>
    </div>
</div>

<div id="fdaModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#fff;border-radius:16px;width:95%;max-width:900px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,0.3);overflow:hidden">
        <div style="background:linear-gradient(135deg,#0e7490,#0891b2);padding:16px 24px;color:#fff;display:flex;justify-content:space-between;align-items:center">
            <span style="font-weight:900;font-size:14px"><i class="fa-solid fa-globe"></i> تقارير سلامة الجهاز (FDA)</span>
            <button onclick="document.getElementById('fdaModal').style.display='none'" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer">✕</button>
        </div>
        
        <div id="fdaModalKpi" style="display:flex;gap:8px;padding:12px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0;"></div>

        <div style="padding:14px 24px;border-bottom:1px solid #e2e8f0;display:flex;gap:10px;flex-shrink:0">
            <button type="button" class="fda-tab" data-tab="ov" onclick="fdaTab('ov')" style="flex:1;text-align:center;padding:9px;border-radius:9px;font-size:12px;font-weight:900;border:none;cursor:pointer;background:#0e7490;color:#fff">نظرة عامة</button>
            <button type="button" class="fda-tab" data-tab="mf" onclick="fdaTab('mf')" style="flex:1;text-align:center;padding:9px;border-radius:9px;font-size:12px;font-weight:900;border:none;cursor:pointer;background:transparent;color:#64748b">تقارير الأعطال</button>
            <button type="button" class="fda-tab" data-tab="inj" onclick="fdaTab('inj')" style="flex:1;text-align:center;padding:9px;border-radius:9px;font-size:12px;font-weight:900;border:none;cursor:pointer;background:transparent;color:#64748b">تحذيرات الخطورة</button>
        </div>
        <div class="fda-modal-scroll" style="padding:16px 24px 20px;flex:1;min-height:0;overflow-y:auto">
            <div class="fda-tab-pane" id="fdaPane-ov"></div>
            <div class="fda-tab-pane" id="fdaPane-mf" style="display:none"></div>
            <div class="fda-tab-pane" id="fdaPane-inj" style="display:none"></div>
        </div>
        <div style="padding:14px 24px;border-top:1px solid #e2e8f0;display:flex;gap:10px;flex-shrink:0">
            <button type="button" onclick="printFdaReport()" style="background:#0e7490;color:#fff;border:none;padding:9px 18px;border-radius:10px;font-size:12px;font-weight:900;cursor:pointer"><i class="fa-solid fa-print"></i> طباعة التقرير</button>
            <button type="button" onclick="document.getElementById('fdaModal').style.display='none'" style="background:#f1f5f9;color:#475569;border:none;padding:9px 18px;border-radius:10px;font-size:12px;font-weight:900;cursor:pointer">إغلاق</button>
        </div>
    </div>
</div>

<script>
var BASE_URL = '<?= BASE_URL ?>';
var REQUEST_NUMBER = '<?= e($c['request_number'] ?? '') ?>';

/* ── العدّاد الحي لصافي وقت المعالجة ── */
(function(){
  function fmtT(s){s=Math.max(0,Math.floor(s));
    const d=Math.floor(s/86400),h=Math.floor(s%86400/3600),
          m=Math.floor(s%3600/60),x=s%60,p=n=>String(n).padStart(2,'0');
    return {d:d, t:p(h)+':'+p(m)+':'+p(x)};}
  function tick(){
    document.querySelectorAll('.lvt').forEach(el=>{
      const st=+el.dataset.s, pt=+el.dataset.p||0,
            pa=+el.dataset.pa||0, en=+el.dataset.e||0;
      let ref, mode;
      if(en){ref=en;mode='done';}
      else if(pa){ref=pa;mode='pause';}
      else{ref=Math.floor(Date.now()/1000);mode='run';}
      const f=fmtT(ref-st-pt);
      el.querySelector('.lvt-v').textContent=f.t;
      const dd=el.querySelector('.lvt-dd');
      if(dd) dd.textContent = f.d ? (f.d===1?'يوم':f.d+' أيام') : '';
      el.classList.remove('lvt-run','lvt-pause','lvt-done');
      el.classList.add('lvt-'+mode);
    });
  }
  tick(); setInterval(tick,1000);
})();

var ASSET_DESC = '<?= e($c['asset_desc'] ?? '') ?>';
var TAG_NUMBER = '<?= e($c['tag_number'] ?? '') ?>';
var fdaSummary = null, fdaDetail = null, fdaDetailLoading = false;

function toggleBox(id) {
    document.querySelectorAll('.act-box').forEach(function(b) { if (b.id !== id) b.style.display = 'none'; });
    var b = document.getElementById(id);
    b.style.display = b.style.display === 'block' ? 'none' : 'block';
}
function doAct(action) { document.getElementById('actField').value = action; document.getElementById('actForm').submit(); }
function showAttPreview(files) {
    var pre = document.getElementById('attPre'); pre.innerHTML = '';
    Array.from(files).slice(0, 5).forEach(function(f) {
        pre.innerHTML += '<span class="file-pre"><i class="fa-solid fa-file"></i> ' + f.name + '</span>';
    });
}
function fdaTab(tab) {
    document.querySelectorAll('.fda-tab').forEach(t => { t.style.background='transparent'; t.style.color='#64748b'; });
    document.querySelector('.fda-tab[data-tab="'+tab+'"]').style.background='#0e7490';
    document.querySelector('.fda-tab[data-tab="'+tab+'"]').style.color='#fff';
    document.querySelectorAll('.fda-tab-pane').forEach(p => p.style.display='none');
    document.getElementById('fdaPane-'+tab).style.display='block';
}
async function openFdaModal() {
    document.getElementById('fdaModal').style.display = 'flex';
    
    // التأكد من وجود العنصر أولاً لتجنب توقف السكربت
    var kpiEl = document.getElementById('fdaModalKpi');
    if (kpiEl) {
        kpiEl.innerHTML = fdaSummary ?
            '<div class="fda-kpi"><div class="v eng" style="color:#0e7490">' + fdaSummary.total.toLocaleString() + '</div><div class="l" style="color:#0e7490">إجمالي البلاغات</div></div>' +
            '<div class="fda-kpi"><div class="v eng" style="color:#dc2626">' + (fdaSummary.malfunction != null ? fdaSummary.malfunction.toLocaleString() : '—') + '</div><div class="l" style="color:#b91c1c">أعطال مصنعية</div></div>' : '';
    }

    if (!fdaDetail && !fdaDetailLoading) {
        fdaDetailLoading = true;
        
        // تعريف المتغيرات قبل إسناد القيم لها
        var ovPane = document.getElementById('fdaPane-ov');
        var mfPane = document.getElementById('fdaPane-mf');
        var injPane = document.getElementById('fdaPane-inj');
        
        ovPane.innerHTML = mfPane.innerHTML = injPane.innerHTML = '<div style="text-align:center;padding:40px"><i class="fa-solid fa-circle-notch fa-spin" style="color:#0e7490;font-size:20px"></i><div style="font-size:11.5px;color:#64748b;font-weight:700;margin-top:8px">جاري جلب التقارير الفعلية من FDA...</div></div>';
        
        try {
            var fd = new FormData(); fd.append('asset_id', '<?= $c['asset_id'] ?? '' ?>');
            var r = await fetch(BASE_URL + '/api/complaint_fda_details.php', { method: 'POST', body: fd });
            fdaDetail = await r.json();
        } catch(e) { fdaDetail = null; }
        fdaDetailLoading = false;
        renderFdaPanes();
    }
}
function renderFdaPanes() {
    var ovPane = document.getElementById('fdaPane-ov');
    var mfPane = document.getElementById('fdaPane-mf');
    var injPane = document.getElementById('fdaPane-inj');
    if (fdaDetail) {
        ovPane.innerHTML = '<div style="font-size:13px;font-weight:700;color:#0f172a">تم جلب البيانات بنجاح. انتقل إلى تبويب "تقارير الأعطال" أو "تحذيرات الخطورة" لعرض التفاصيل.</div>';
        mfPane.innerHTML = renderFdaList(fdaDetail.malfunctions || [], 'لا توجد أعطال مصنعية مسجَّلة لهذا الجهاز.');
        injPane.innerHTML = renderFdaList(fdaDetail.injuries || [], 'لا توجد تحذيرات خطورة مسجَّلة لهذا الجهاز.');
    }
}
function renderFdaList(list, emptyMsg) {
    if (!list || list.length === 0) return '<div style="text-align:center;padding:30px;color:var(--muted);font-weight:700">' + emptyMsg + '</div>';
    return list.map(function(ev) {
        var uniqueId = 'trans-' + Math.random().toString(36).substr(2, 9);
        
        // تنظيف النص جذرياً من أي رموز وفواصل سطرية قد تكسر دالة onclick
        var safeText = (ev.narrative || '')
            .replace(/[\r\n]+/g, ' ')      // إزالة النزول لسطر جديد
            .replace(/\\/g, '\\\\')        // الهروب العكسي
            .replace(/'/g, "\\'")          // حماية الاقتباس المفرد
            .replace(/"/g, '&quot;');      // حماية الاقتباس المزدوج
            
        return '<div style="background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:12px">' +
            '<div style="font-size:10px;font-weight:800;color:#64748b">' + (ev.event_type || '—') + '</div>' +
            '<div style="font-size:11.5px;color:#000;font-weight:700;line-height:1.6;margin:6px 0" dir="ltr">' + (ev.narrative || '—') + '</div>' +
            '<div id="' + uniqueId + '"><button type="button" class="btn-translate" onclick="translateFdaReport(this, \'' + safeText + '\', \'' + uniqueId + '\')"><i class="fa-solid fa-language"></i> عرض الترجمة العربية</button></div></div>';
    }).join('');
}
async function translateFdaReport(btn, textToTranslate, boxId) {
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> جاري الترجمة...'; btn.disabled = true;
    try {
        var url = "https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=ar&dt=t&q=" + encodeURIComponent(textToTranslate);
        var response = await fetch(url);
        var data = await response.json();
        var translatedText = data[0].map(function(item) { return item[0]; }).join('');
        document.getElementById(boxId).innerHTML = '<div class="translation-text">' + translatedText + '</div>';
    } catch (e) { btn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> فشلت الترجمة'; btn.disabled = false; }
}
function printFdaReport() {
    var w = window.open('', '_blank');
    w.document.write('<!DOCTYPE html><html dir="rtl"><head><meta charset="UTF-8"><title>تقرير FDA</title><style>body{font-family:Tajawal,Arial,sans-serif;padding:30px;color:#0f172a}h1{font-size:18px}h2{font-size:14px;border-bottom:2px solid #0e7490;padding-bottom:6px;margin-top:24px}</style></head><body>');
    w.document.write('<h1>تقرير سلامة الجهاز العالمي (FDA) — بلاغ ' + REQUEST_NUMBER + '</h1>');
    w.document.write('<p>الجهاز: ' + ASSET_DESC + ' &nbsp; التاج: ' + TAG_NUMBER + '</p>');
    w.document.write('<h2>الأعطال المصنعية</h2>' + (fdaDetail ? renderFdaList(fdaDetail.malfunctions || [], 'لا توجد.').replace(/<button[\s\S]*?<\/button>/g, '') : ''));
    w.document.write('<h2>تحذيرات الخطورة</h2>' + (fdaDetail ? renderFdaList(fdaDetail.injuries || [], 'لا توجد.').replace(/<button[\s\S]*?<\/button>/g, '') : ''));
    w.document.write('</body></html>'); w.document.close();
    setTimeout(function() { w.print(); }, 400);
}

// تحميل ملخص FDA عند فتح الصفحة
(async function() {
    if ('<?= $c['asset_id'] ?? '' ?>') {
        try {
            var fd = new FormData(); fd.append('asset_id', '<?= $c['asset_id'] ?? '' ?>');
            var r = await fetch(BASE_URL + '/api/complaint_fda_summary.php', { method: 'POST', body: fd });
            fdaSummary = await r.json();
            document.getElementById('fdaLoading').style.display = 'none';
            document.getElementById('fdaWrap').style.display = 'grid';
            document.getElementById('fdaWrap').innerHTML =
                '<div class="fda-kpi"><div class="v eng" style="color:#0e7490">' + fdaSummary.total.toLocaleString() + '</div><div class="l" style="color:#0e7490">إجمالي البلاغات</div></div>' +
                '<div class="fda-kpi"><div class="v eng" style="color:#dc2626">' + (fdaSummary.malfunction != null ? fdaSummary.malfunction.toLocaleString() : '—') + '</div><div class="l" style="color:#b91c1c">أعطال</div></div>' +
                '<div class="fda-kpi"><div class="v eng" style="color:#d97706">' + (fdaSummary.injury_death != null ? fdaSummary.injury_death.toLocaleString() : '—') + '</div><div class="l" style="color:#b45309">خطورة</div></div>';
            document.getElementById('fdaDetailsBtn').style.display = 'block';
        } catch(e) { document.getElementById('fdaLoading').innerHTML = '<span style="color:#dc2626">فشل الاتصال بـ FDA</span>'; }
    }
})();
</script>
</body>
</html>