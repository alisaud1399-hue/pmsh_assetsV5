<?php
/**
 * inventory/session.php — تفاصيل جلسة جرد + إحصاءات حية + سجل الفحوصات
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index'); // ← أولاً: تسجيل الدخول + الصلاحية

/* ═══ حارس العضوية: غير الأعضاء ممنوعون من الدخول ═══ */
$inv_session_id = (int)($_GET['id'] ?? 0);
if ($inv_session_id > 0 && !inv_session_guard($inv_session_id)) {
    log_activity('inventory.session.denied', 'session:' . $inv_session_id, 'user_not_member');
    flash('warning', 'أنت لست عضواً في لجنة الجرد لهذه الجلسة — لا يمكنك الاطلاع على تفاصيلها. تواصل مع مدير الأصول إن كان هذا خطأ.');
    redirect('/inventory/index.php');
}

$rtl   = is_rtl();
$id    = (int)($_GET['id'] ?? 0);
$start = microtime(true);
if (!$id) abort(404, $rtl ? 'جلسة غير موجودة' : 'Session not found');

// ══════════════════════════════════════════════════════════════════
// معالجة إجراءات سريعة على الحالة (POST)
// ══════════════════════════════════════════════════════════════════
if (is_post() && verify_csrf()) {

    // ── البت في طلب إعادة الجرد (موافقة/رفض) ──
    if (isset($_POST['reaudit_decision'])) {
        if (!can('inventory.validate', 'approve')) abort(403);
        $req_id   = (int)($_POST['request_id'] ?? 0);
        $decision = $_POST['reaudit_decision'] === 'approve' ? 'approved' : 'rejected';
        $rq = $pdo->prepare("SELECT * FROM inventory_reaudit_requests WHERE id=? AND session_id=? AND status='pending'");
        $rq->execute([$req_id, $id]);
        $req = $rq->fetch(PDO::FETCH_ASSOC);
        if (!$req) {
            flash('error', $rtl ? 'الطلب غير موجود أو سبق البت فيه.' : 'Request not found or already decided.');
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE inventory_reaudit_requests SET status=?, decided_by=?, decided_at=NOW() WHERE id=?")
                    ->execute([$decision, (int)$_SESSION['user_id'], $req_id]);
                if ($decision === 'approved') {
                    $pdo->prepare("UPDATE inventory_audits SET action='reaudit_pending' WHERE id=?")
                        ->execute([(int)$req['audit_id']]);
                }
                $pdo->commit();
                log_activity('inventory.reaudit.' . $decision, 'session:' . $id, 'asset:' . $req['asset_id']);
                flash('success', $decision === 'approved'
                    ? ($rtl ? 'تمت الموافقة — الأصل أصبح متاحاً لإعادة الجرد (مميَّز: معاد للجرد).' : 'Approved — asset is available for re-audit (marked: Re-audit).')
                    : ($rtl ? 'تم رفض الطلب.' : 'Request rejected.'));
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                flash('error', $rtl ? 'خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage());
            }
        }
        header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
        exit;
    }

    $action = $_POST['quick_action'] ?? '';
    $st = $pdo->prepare("SELECT status, session_code FROM inventory_sessions WHERE id=?");
    $st->execute([$id]);
    $current = $st->fetch(PDO::FETCH_ASSOC);
    if (!$current) abort(404);

    $transitions = [
        'start'    => ['active',    $rtl ? 'تم تفعيل الجلسة — ابدأ المسح الميداني.' : 'Session activated — start scanning.'],
        'pause'    => ['review',    $rtl ? 'تم إيقاف الجلسة للمراجعة.' : 'Session paused for review.'],
        'resume'   => ['active',    $rtl ? 'تم استئناف الجرد.' : 'Session resumed.'],
        'complete' => ['completed', $rtl ? 'تم إغلاق الجلسة — أرشيف الجرد مكتمل.' : 'Session closed — audit archived.'],
        'cancel'   => ['cancelled', $rtl ? 'تم إلغاء الجلسة.' : 'Session cancelled.'],
    ];

    if (isset($transitions[$action])) {
        $allowed = [
            'start'    => ['planning'],
            'pause'    => ['active'],
            'resume'   => ['review'],
            'complete' => ['review', 'active'],
            'cancel'   => ['planning', 'active', 'review'],
        ];
        if (in_array($current['status'], $allowed[$action], true)) {
            try {
                $new_status = $transitions[$action][0];
                $pdo->prepare("UPDATE inventory_sessions SET status=? WHERE id=?")->execute([$new_status, $id]);
                log_activity('inventory.session.' . $action, 'session:' . $id, 'status=' . $new_status);

                // ── ✅ تنبيه مباشر لأعضاء اللجنة حسب الحالة الجديدة (الموضع الصحيح) ──
                $notify_map = [
                    'start'    => ['🟢', 'تم تفعيل الجلسة — ابدأ المسح',      'الجلسة {code} أصبحت نشطة الآن. يمكنكم بدء المسح الميداني.'],
                    'pause'    => ['🟡', 'تم إيقاف الجلسة للمراجعة',          'الجلسة {code} موقوفة مؤقتاً للمراجعة — المسح الميداني متوقف حالياً.'],
                    'resume'   => ['🟢', 'تم استئناف الجلسة',                'الجلسة {code} عادت نشطة. يمكنكم متابعة المسح الميداني.'],
                    'complete' => ['🔵', 'تم إغلاق الجلسة',                  'الجلسة {code} أُغلقت واكتمل أرشيف الجرد. شكراً لجهودكم.'],
                    'cancel'   => ['🔴', 'تم إلغاء الجلسة',                  'الجلسة {code} أُلغيت — لا يلزم أي إجراء.'],
                ];
                if (isset($notify_map[$action])) {
                    [$ico, $ttl, $bdy] = $notify_map[$action];
                    $dest_link = in_array($action, ['start','resume'], true)
                        ? BASE_URL . '/inventory/scan.php?session=' . $id
                        : BASE_URL . '/inventory/session.php?id=' . $id;
                    $mem = $pdo->prepare("SELECT user_id FROM inventory_session_members WHERE session_id=?");
                    $mem->execute([$id]);
                    $ins_n = $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id)
                                            VALUES (?, 'info', ?, ?, ?, 'inventory_session', ?)");
                    $actor = (int)($_SESSION['user_id'] ?? 0);
                    foreach ($mem->fetchAll(PDO::FETCH_COLUMN) as $muid) {
                        $muid = (int)$muid;
                        if ($muid === $actor) continue; // لا تُنبّه منفِّذ الإجراء
                        $ins_n->execute([$muid, $ico . ' ' . $ttl, str_replace('{code}', $current['session_code'], $bdy), $dest_link, $id]);
                    }
                }

                flash('success', $transitions[$action][1]);
            } catch (Exception $e) {
                flash('error', $rtl ? 'خطأ: ' . $e->getMessage() : 'Error: ' . $e->getMessage());
            }
        } else {
            flash('error', $rtl ? 'لا يمكن تنفيذ هذا الإجراء من الحالة الحالية.' : 'Cannot perform this action from current status.');
        }
    }
    header('Location: ' . BASE_URL . '/inventory/session.php?id=' . $id);
    exit;
}

// ═════════════════════════════════════════════════════════════════
// جلب بيانات الجلسة
// ══════════════════════════════════════════════════════════════════
$st = $pdo->prepare("SELECT s.*, u.full_name AS creator_name FROM inventory_sessions s LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?");
$st->execute([$id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) abort(404, $rtl ? 'الجلسة غير موجودة.' : 'Session not found.');
$scope_json_arr = json_decode($session['scope_value'] ?? '[]', true) ?: [];

// ══════════════════════════════════════════════════════════════════
// نطاق الأصول
// ══════════════════════════════════════════════════════════════════
function build_scope_where(string $type, array $values): array {
    $where = ["a.status = 'active'"];
    $params = [];
    switch ($type) {
        case 'all': break;
        case 'department':
            $where[] = 'a.department_id IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            $params = $values;
            break;
        case 'asset_type':
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where[] = "a.asset_type IN ($placeholders)";
            $params = array_values($values);
            break;
        case 'building':
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where[] = "(a.location_id IN ($placeholders) OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN ($placeholders)) OR a.location_id IN (SELECT id FROM item_locations WHERE parent_id IN (SELECT id FROM item_locations WHERE parent_id IN ($placeholders))))";
            $params = array_merge($values, $values, $values);
            break;
        case 'custom':
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where[] = "a.id IN ($placeholders)";
            $params = array_map('intval', $values);
            break;
    }
    return ['sql' => implode(' AND ', $where), 'params' => $params];
}

$scope_cond = build_scope_where($session['scope_type'], $scope_json_arr);
$expected_sql = "SELECT COUNT(*) FROM assets a WHERE " . $scope_cond['sql'];
$st = $pdo->prepare($expected_sql);
$st->execute($scope_cond['params']);
$expected_count = (int)$st->fetchColumn();

$expected_ids_sql = "SELECT id FROM assets a WHERE " . $scope_cond['sql'];
$st = $pdo->prepare($expected_ids_sql);
$st->execute($scope_cond['params']);
$expected_ids = array_map(fn($r) => (int)$r['id'], $st->fetchAll(PDO::FETCH_ASSOC));

$last_scan_at = $pdo->prepare("SELECT MAX(audited_at) FROM inventory_audits WHERE session_id=?");
$last_scan_at->execute([$id]);
$last_scan_at = $last_scan_at->fetchColumn();

// ══════════════════════════════════════════════════════════════════
// إحصاءات الفحص
// ══════════════════════════════════════════════════════════════════
$act_sql = "SELECT SUM(action IN ('confirmed','location_changed','custody_changed')) AS found, SUM(action = 'condition_damaged') AS damaged, SUM(action = 'missing') AS missing, SUM(action = 'surplus') AS surplus, SUM(action = 'location_changed') AS moved, SUM(action = 'custody_changed') AS custody_chg FROM inventory_audits WHERE session_id = ?";
$st = $pdo->prepare($act_sql);
$st->execute([$id]);
$act_stats = $st->fetch(PDO::FETCH_ASSOC);
$found    = (int)($act_stats['found'] ?? 0);
$damaged  = (int)($act_stats['damaged'] ?? 0);
$missing  = (int)($act_stats['missing'] ?? 0);
$surplus  = (int)($act_stats['surplus'] ?? 0);
$moved    = (int)($act_stats['moved'] ?? 0);
$cust_chg = (int)($act_stats['custody_chg'] ?? 0);
$pending  = max(0, $expected_count - $found - $missing);
$coverage = $expected_count > 0 ? round(($found + $missing) * 100 / max(1, $expected_count)) : 0;

// ══════════════════════════════════════════════════════════════════
// أعضاء اللجنة
// ══════════════════════════════════════════════════════════════════
$m_st = $pdo->prepare("SELECT m.*, u.full_name, u.email, d.name AS dept_name FROM inventory_session_members m LEFT JOIN users u ON u.id = m.user_id LEFT JOIN departments d ON d.id = u.department_id WHERE m.session_id = ? ORDER BY FIELD(m.role,'leader','member','observer'), u.full_name");
$m_st->execute([$id]);
$members = $m_st->fetchAll(PDO::FETCH_ASSOC);

$mem_act_sql = "SELECT audited_by, COUNT(*) AS cnt FROM inventory_audits WHERE session_id=? GROUP BY audited_by";
$m2 = $pdo->prepare($mem_act_sql);
$m2->execute([$id]);
$member_activity = [];
foreach ($m2->fetchAll(PDO::FETCH_ASSOC) as $r) $member_activity[(int)$r['audited_by']] = (int)$r['cnt'];

// ══════════════════════════════════════════════════════════════════
// فلاتر سجل الفحوصات
// ══════════════════════════════════════════════════════════════════
$ACTION_META = [
    'confirmed'                    => ['ar' => 'موجود',              'en' => 'Confirmed',         'color' => '#10b981', 'icon' => 'fa-check'],
    'location_changed'             => ['ar' => 'نقل موقع',           'en' => 'Location Changed',  'color' => '#3b82f6', 'icon' => 'fa-arrow-right-arrow-left'],
    'custody_changed'              => ['ar' => 'تغيّر عهدة',         'en' => 'Custody Changed',   'color' => '#8b5cf6', 'icon' => 'fa-user-tag'],
    'condition_damaged'            => ['ar' => 'تالف / معطّل',        'en' => 'Damaged',           'color' => '#f59e0b', 'icon' => 'fa-triangle-exclamation'],
    'missing'                      => ['ar' => 'مفقود',              'en' => 'Missing',           'color' => '#dc2626', 'icon' => 'fa-eye-slash'],
    'missing_disposed_previously'  => ['ar' => 'مُتلف سابقاً',       'en' => 'Disposed Previously', 'color' => '#4a4a4a', 'icon' => 'fa-trash-can'],
    'missing_under_investigation'  => ['ar' => 'قيد التحقيق',         'en' => 'Under Investigation', 'color' => '#a16207', 'icon' => 'fa-magnifying-glass'],
    'surplus'                      => ['ar' => 'زيادة (غير مسجّل)',  'en' => 'Surplus',           'color' => '#0891b2', 'icon' => 'fa-plus-circle'],
    'surplus_registered'           => ['ar' => 'زيادة (مسجّل جديد)', 'en' => 'Surplus Registered', 'color' => '#0d9488', 'icon' => 'fa-file-circle-plus'],
    'reaudit_pending'              => ['ar' => 'معاد للجرد',         'en' => 'Re-audit Pending',  'color' => '#ea580c', 'icon' => 'fa-rotate-left'],
];

$f_action = $_GET['f'] ?? '';
if ($f_action && !isset($ACTION_META[$f_action])) $f_action = '';
$page_n = max(1, (int)($_GET['p'] ?? 1));
$per = 20;
$aud_where = ['a.session_id = ?'];
$aud_params = [$id];
if ($f_action) {
    $aud_where[] = 'a.action = ?';
    $aud_params[] = $f_action;
}
$aud_where_sql = implode(' AND ', $aud_where);
$c = $pdo->prepare("SELECT COUNT(*) FROM inventory_audits a WHERE $aud_where_sql");
$c->execute($aud_params);
$total_audits = (int)$c->fetchColumn();
$total_pages  = max(1, (int)ceil($total_audits / $per));

$aud_sql = "SELECT a.*, u.full_name AS auditor_name, ass.tag_number AS asset_tag, ass.description AS asset_desc, ass.loc_building AS ass_building, ass.loc_floor AS ass_floor, ass.loc_room AS ass_room, loc_old.name AS old_loc_name, loc_new.name AS new_loc_name, dept_old.name AS old_custodian_dept_name, dept_new.name AS new_custodian_dept_name, dept_audit.name AS audit_dept_name FROM inventory_audits a LEFT JOIN users u ON u.id = a.audited_by LEFT JOIN assets ass ON ass.id = a.asset_id LEFT JOIN item_locations loc_old ON loc_old.id = a.old_location_id LEFT JOIN item_locations loc_new ON loc_new.id = a.new_location_id LEFT JOIN departments dept_old ON dept_old.id = a.old_custodian_dept_id LEFT JOIN departments dept_new ON dept_new.id = a.new_custodian_dept_id LEFT JOIN departments dept_audit ON dept_audit.id = ass.department_id WHERE $aud_where_sql ORDER BY a.audited_at DESC, a.id DESC LIMIT $per OFFSET " . (($page_n - 1) * $per);
$a_st = $pdo->prepare($aud_sql);
$a_st->execute($aud_params);
$audits = $a_st->fetchAll(PDO::FETCH_ASSOC);

$chip_counts_sql = "SELECT action, COUNT(*) AS cnt FROM inventory_audits WHERE session_id=? GROUP BY action";
$chip_st = $pdo->prepare($chip_counts_sql);
$chip_st->execute([$id]);
$chip_counts = [];
foreach ($chip_st->fetchAll(PDO::FETCH_ASSOC) as $r) $chip_counts[$r['action']] = (int)$r['cnt'];

// ══════════════════════════════════════════════════════════════════
// الأصول المعلّقة
// ══════════════════════════════════════════════════════════════════
$pending_assets = [];
if ($expected_ids) {
    $audited_ids_sql = "SELECT DISTINCT asset_id FROM inventory_audits WHERE session_id=? AND asset_id IS NOT NULL AND action IN ('confirmed','location_changed','custody_changed','condition_damaged')";
    $aud_p = $pdo->prepare($audited_ids_sql);
    $aud_p->execute([$id]);
    $audited_ids = array_map(fn($r) => (int)$r['asset_id'], $aud_p->fetchAll(PDO::FETCH_ASSOC));
    $pending_ids = array_diff($expected_ids, $audited_ids);
    if ($pending_ids) {
        $placeholders = implode(',', array_fill(0, count($pending_ids), '?'));
        $ppa = $pdo->prepare("SELECT a.id, a.tag_number, a.description, a.asset_type, a.criticality_class, loc.name AS loc_name, d.name AS dept_name FROM assets a LEFT JOIN item_locations loc ON loc.id = a.location_id LEFT JOIN departments d ON d.id = a.department_id WHERE a.id IN ($placeholders) ORDER BY loc.name, a.tag_number LIMIT 12");
        $ppa->execute(array_values($pending_ids));
        $pending_assets = $ppa->fetchAll(PDO::FETCH_ASSOC);
    }
}
$pending_more = $pending > 12 ? ($pending - 12) : 0;

// ══════════════════════════════════════════════════════════════════
// شارة الحالة + ترجمة النطاق
// ══════════════════════════════════════════════════════════════════
$STATUS_META = [
    'planning'  => ['ar' => 'تحت التخطيط',  'en' => 'Planning',   'color' => '#64748b', 'icon' => 'fa-pen-ruler'],
    'active'    => ['ar' => 'نشطة الآن',     'en' => 'Active',     'color' => '#10b981', 'icon' => 'fa-circle-play'],
    'review'    => ['ar' => 'قيد المراجعة',  'en' => 'Under Review','color' => '#f59e0b', 'icon' => 'fa-magnifying-glass'],
    'completed' => ['ar' => 'مكتملة',        'en' => 'Completed',  'color' => '#2563eb', 'icon' => 'fa-circle-check'],
    'cancelled' => ['ar' => 'ملغاة',         'en' => 'Cancelled',  'color' => '#dc2626', 'icon' => 'fa-circle-xmark'],
];
$SCOPE_LABELS = [
    'all'         => $rtl ? 'كل أصول المستشفى' : 'All hospital assets',
    'department'  => $rtl ? 'حسب الإدارة'      : 'By department',
    'asset_type'  => $rtl ? 'حسب نوع الأصل'    : 'By asset type',
    'building'    => $rtl ? 'حسب المبنى'        : 'By building',
    'custom'      => $rtl ? 'نطاق مخصص'         : 'Custom scope',
];
$sm = $STATUS_META[$session['status']] ?? $STATUS_META['planning'];
$scope_human = $SCOPE_LABELS[$session['scope_type']] ?? $session['scope_type'];
if ($session['scope_type'] !== 'all' && !empty($scope_json_arr)) {
    if ($session['scope_type'] === 'department') {
        $in = implode(',', array_map('intval', $scope_json_arr));
        $rows = $pdo->query("SELECT name FROM departments WHERE id IN ($in) ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $scope_human .= ' — ' . implode('، ', array_map('e', $rows));
    } elseif ($session['scope_type'] === 'building') {
        $in = implode(',', array_map('intval', $scope_json_arr));
        $rows = $pdo->query("SELECT name FROM item_locations WHERE id IN ($in) ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $scope_human .= ' — ' . implode('، ', array_map('e', $rows));
    } elseif ($session['scope_type'] === 'asset_type') {
        $types_map = ['medical' => $rtl ? 'طبي' : 'Medical', 'it' => $rtl ? 'تقنية معلومات' : 'IT', 'infrastructure' => $rtl ? 'بنية تحتية' : 'Infrastructure', 'hvac' => $rtl ? 'تكييف' : 'HVAC', 'transport' => $rtl ? 'مركبات' : 'Transport', 'furniture' => $rtl ? 'أثاث' : 'Furniture', 'other' => $rtl ? 'أخرى' : 'Other'];
        $scope_human .= ' — ' . implode('، ', array_map(fn($t) => $types_map[$t] ?? $t, $scope_json_arr));
    }
}

function time_ago(?string $dt, bool $rtl): string {
    if (!$dt) return '—';
    $diff = time() - strtotime($dt);
    if ($diff < 60)        return $rtl ? 'الآن' : 'now';
    if ($diff < 3600)      return floor($diff / 60) . ' ' . ($rtl ? 'د' : 'm');
    if ($diff < 86400)     return floor($diff / 3600) . ' ' . ($rtl ? 'س' : 'h');
    if ($diff < 86400*30)  return floor($diff / 86400) . ' ' . ($rtl ? 'ي' : 'd');
    return date('Y-m-d', strtotime($dt));
}

$can_edit   = can('inventory.create', 'edit') && $session['status'] === 'planning';
$can_scan   = can('inventory.scan', 'view');
$can_review = can('inventory.validate', 'approve');
$can_export = can('inventory.report', 'export');

// ── طلبات إعادة الجرد المعلَّقة لهذه الجلسة ──
$rr = $pdo->prepare("
    SELECT r.*, a.tag_number, a.description AS asset_desc, u.full_name AS requester_name
    FROM inventory_reaudit_requests r
    JOIN assets a ON a.id = r.asset_id
    LEFT JOIN users u ON u.id = r.requested_by
    WHERE r.session_id = ? AND r.status = 'pending'
    ORDER BY r.created_at ASC
");
$rr->execute([$id]);
$reaudit_requests = $rr->fetchAll(PDO::FETCH_ASSOC);

$page_title = $session['session_code'] . ' — ' . $session['title'];
$active_nav = 'inventory.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#2563eb; }
* { font-family: 'Tajawal', sans-serif; }
body { background:var(--bg); font-family:'Tajawal',sans-serif; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width:1400px; margin:0 auto; padding:22px; }
.h-banner { background:linear-gradient(135deg,#0f172a,#1e293b); border-radius:22px; padding:22px 28px; color:#fff; margin-bottom:18px; display:flex; justify-content:space-between; align-items:center; gap:18px; flex-wrap:wrap; }
.h-banner .left h1 { font-size:21px; font-weight:900; margin:0; display:flex; align-items:center; gap:10px; }
.h-banner .code { display:inline-block; background:#fbbf24; color:#0f172a; padding:3px 11px; border-radius:6px; font-size:13px; font-weight:900; margin-bottom:8px; }
.h-banner .badge { display:inline-flex; align-items:center; gap:6px; padding:6px 14px; border-radius:99px; font-size:12px; font-weight:900; color:#fff; margin-top:6px; }
.h-banner p { font-size:12.5px; color:#cbd5e1; margin:8px 0 0; }
.h-banner .actions { display:flex; gap:8px; flex-wrap:wrap; }
.btn-q { background:rgba(255,255,255,.08); color:#fff; border:1.5px solid rgba(255,255,255,.16); padding:10px 18px; border-radius:11px; font-family:'Tajawal'; font-size:12.5px; font-weight:800; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:7px; transition:.2s; }
.btn-q:hover { background:rgba(255,255,255,.14); transform:translateY(-1px); }
.btn-q.primary { background:#10b981; border-color:#10b981; color:#fff; }
.btn-q.primary:hover { background:#059669; }
.btn-q.warn    { background:#f59e0b; border-color:#f59e0b; color:#fff; }
.btn-q.warn:hover    { background:#d97706; }
.btn-q.danger  { background:#dc2626; border-color:#dc2626; }
.btn-q.danger:hover  { background:#b91c1c; }
.kpis { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-bottom:18px; }
@media (max-width:1100px){ .kpis { grid-template-columns:repeat(2,1fr); } }
@media (max-width:768px){
.kpis { grid-template-columns:repeat(2,1fr); gap:10px; }
.kpi { padding:14px 12px; }
.kpi .v { font-size:22px; }
.kpi .ic { width:32px; height:32px; font-size:14px; }
}
.kpi { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px 18px; position:relative; overflow:hidden; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; min-height:90px; }
.kpi .v { font-size:26px; font-weight:900; color:var(--text); margin:10px 0 6px; line-height:1; }
.kpi .v.eng { font-family:'Inter'; }
.kpi .l { font-size:11.5px; font-weight:800; color:var(--muted); margin-top:4px; line-height:1.3; max-width:100%; }
.kpi .s { font-size:10.5px; color:#94a3b8; margin-top:2px; line-height:1.2; }
.kpi .ic { position:absolute; top:12px; right:14px; width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:16px; }
[dir="ltr"] .kpi .ic { right: auto; left: 14px; }
.kpi .ic.blue  { background:#dbeafe; color:#2563eb; }
.kpi .ic.green { background:#d1fae5; color:#10b981; }
.kpi .ic.amber { background:#fef3c7; color:#f59e0b; }
.kpi .ic.red   { background:#fee2e2; color:#dc2626; }
.kpi .ic.purple{ background:#ede9fe; color:#7c3aed; }
.bento { background:var(--card); border-radius:18px; border:1px solid var(--border); padding:22px; margin-bottom:16px; }
.bento-h { font-size:14.5px; font-weight:900; margin:0 0 14px; display:flex; align-items:center; gap:9px; color:var(--text); }
.bento-h i { color:var(--primary); background:#eff6ff; padding:8px; border-radius:9px; font-size:13px; }
.bento-h .ttl-en { font-size:11px; color:#94a3b8; font-weight:600; margin-right:auto; }
.row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.row.two-thirds { grid-template-columns:2fr 1fr; }
@media (max-width:900px) { .row, .row.two-thirds { grid-template-columns:1fr; } }
.progress-big { background:linear-gradient(135deg,#eff6ff,#dbeafe); border:1px solid #bfdbfe; border-radius:16px; padding:18px; }
.progress-big .head { display:flex; justify-content:space-between; align-items:baseline; flex-wrap:wrap; gap:10px; margin-bottom:10px; }
.progress-big .label { font-size:13px; font-weight:900; color:#1e40af; }
.progress-big .pct { font-size:36px; font-weight:900; color:#1d4ed8; font-family:'Inter'; line-height:1; }
.progress-big .pct small { font-size:14px; color:#3b82f6; font-weight:700; }
.progress-big .bar { height:14px; background:#fff; border-radius:99px; overflow:hidden; border:1px solid #bfdbfe; margin-bottom:8px; }
.progress-big .bar .fg { height:100%; background:linear-gradient(90deg,#2563eb,#7c3aed); transition:width .5s; }
.progress-big .totals { font-size:11.5px; color:#475569; font-weight:700; }
.action-breakdown { display:flex; flex-direction:column; gap:10px; }
.act-row { display:grid; grid-template-columns:36px 1fr auto; align-items:center; gap:10px; padding:8px 12px; border-radius:10px; background:#f8fafc; border:1px solid #f1f5f9; }
.act-row .ic { width:36px; height:36px; border-radius:9px; display:flex; align-items:center; justify-content:center; color:#fff; }
.act-row .nm { font-size:12.5px; font-weight:900; color:#0f172a; }
.act-row .nm small { font-weight:600; color:#64748b; }
.act-row .ct { font-size:14px; font-weight:900; color:#0f172a; font-family:'Inter'; }
.scope-info { background:#f8fafc; border:1px solid var(--border); border-radius:12px; padding:16px 18px; }
.scope-info .l { font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.5px; }
.scope-info .v { font-size:15px; font-weight:900; color:#0f172a; margin-top:5px; line-height:1.6; }
.scope-info .meta { display:flex; gap:14px; margin-top:14px; flex-wrap:wrap; font-size:11.5px; color:#475569; font-weight:700; }
.scope-info .meta i { color:#94a3b8; margin-left:4px; }
.mem-card { display:flex; align-items:center; gap:10px; padding:9px 12px; border:1px solid var(--border); border-radius:11px; margin-bottom:7px; background:#fff; }
.mem-card .av { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; font-weight:900; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:13px; }
.mem-card .nm { flex:1; min-width:0; }
.mem-card .nm .name { font-size:13px; font-weight:900; color:#0f172a; line-height:1.3; }
.mem-card .nm .dept { font-size:10.5px; color:#64748b; font-weight:600; }
.mem-card .role { font-size:10px; font-weight:900; padding:3px 9px; border-radius:99px; color:#fff; text-transform:uppercase; letter-spacing:.5px; }
.mem-card .role.leader   { background:#2563eb; }
.mem-card .role.member   { background:#64748b; }
.mem-card .role.observer { background:#a8a29e; }
.mem-card .act { font-size:11.5px; color:#475569; font-weight:800; font-family:'Inter'; text-align:left; min-width:60px; }
.mem-card .act strong { color:#10b981; display:block; font-size:14px; }
.chips { display:flex; gap:7px; flex-wrap:wrap; margin-bottom:14px; }
.chip { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:99px; background:#fff; border:1.5px solid var(--border); color:#475569; font-family:'Tajawal'; font-size:11.5px; font-weight:800; text-decoration:none; transition:.2s; }
.chip:hover { border-color:#cbd5e1; background:#f8fafc; }
.chip.active { background:#0f172a; color:#fff; border-color:#0f172a; }
.chip .ct { font-family:'Inter'; font-size:11px; opacity:.85; }
.tbl { width:100%; border-collapse:separate; border-spacing:0; }
.tbl th { background:#f8fafc; padding:11px 14px; text-align:right; font-size:11px; font-weight:900; color:#475569; text-transform:uppercase; letter-spacing:.5px; border-bottom:1.5px solid var(--border); }
.tbl td { padding:14px; font-size:12.5px; color:#0f172a; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.tbl tr:hover td { background:#f8fafc; }
.tbl .badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:99px; color:#fff; font-size:11px; font-weight:800; }
.pagination { display:flex; justify-content:center; align-items:center; gap:6px; margin-top:18px; }
.pagination a, .pagination span { padding:7px 13px; border-radius:8px; background:#fff; border:1px solid var(--border); font-size:12px; font-weight:800; color:#475569; text-decoration:none; font-family:'Tajawal'; }
.pagination a:hover { background:#f1f5f9; }
.pagination .current { background:#0f172a; border-color:#0f172a; color:#fff; }
.pagination .disabled { opacity:.4; pointer-events:none; }
.pending-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:10px; }
.pending-card { background:#fffbeb; border:1px solid #fde68a; border-radius:12px; padding:12px 14px; }
.pending-card .tag { font-family:'Inter'; font-size:12px; font-weight:900; color:#92400e; background:#fef3c7; padding:2px 8px; border-radius:5px; display:inline-block; }
.pending-card .desc { font-size:11.5px; color:#78350f; font-weight:700; margin-top:6px; line-height:1.4; }
.pending-card .meta { font-size:10.5px; color:#a16207; font-weight:600; margin-top:6px; }
.pending-card .meta i { margin-left:3px; }
.empty-state { text-align:center; padding:40px 20px; color:#94a3b8; font-size:13px; font-weight:700; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:12px; }
.empty-state i { font-size:36px; display:block; margin-bottom:8px; color:#cbd5e1; }
.flash { background:var(--card); border-radius:12px; padding:13px 18px; margin-bottom:14px; font-weight:800; font-size:13px; border-right:4px solid #3b82f6; }
.flash.success { border-right-color:#10b981; color:#065f46; }
@media print { .h-banner .actions, .btn-q, .filters, .pagination { display:none !important; } }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">
<?php foreach ($flash_msgs as $fm): ?>
<div class="flash <?= e($fm['type']) ?>"><?= e($fm['message']) ?></div>
<?php endforeach; ?>

<div class="h-banner">
<div class="left">
<span class="code eng"><?= e($session['session_code']) ?></span>
<h1><i class="fa-solid fa-clipboard-check" style="color:#fbbf24"></i> <?= e($session['title']) ?></h1>
<span class="badge" style="background:<?= $sm['color'] ?>"><i class="fa-solid <?= $sm['icon'] ?>"></i> <?= $rtl ? $sm['ar'] : $sm['en'] ?></span>
<p><i class="fa-solid fa-calendar" style="color:#fbbf24"></i>
<?= $session['start_date'] ? date('Y-m-d', strtotime($session['start_date'])) : '—' ?> → <?= $session['end_date'] ? date('Y-m-d', strtotime($session['end_date'])) : ($rtl ? 'مفتوحة' : 'open') ?>
&nbsp;·&nbsp; <i class="fa-solid fa-user"></i> <?= e($session['creator_name'] ?? '—') ?></p>
<?php if (!empty($session['notes'])): ?>
<p style="margin-top:8px; padding:9px 13px; background:rgba(255,255,255,.06); border-radius:9px; border-right:3px solid #fbbf24"><i class="fa-solid fa-note-sticky"></i> <?= e($session['notes']) ?></p>
<?php endif; ?>
</div>
<div class="actions">
<?php if ($can_edit): ?>
<a class="btn-q" href="<?= BASE_URL ?>/inventory/create.php?id=<?= $id ?>"><i class="fa-solid fa-pen"></i> <?= $rtl ? 'تعديل' : 'Edit' ?></a>
<?php endif; ?>
<?php if ($can_scan && in_array($session['status'], ['active','review'])): ?>
<a class="btn-q primary" href="<?= BASE_URL ?>/inventory/scan.php?session=<?= $id ?>"><i class="fa-solid fa-qrcode"></i> <?= $rtl ? 'مسح ميداني' : 'Field Scan' ?></a>
<?php endif; ?>
<?php if (can('inventory.create', 'manage')): ?>
<?php if ($session['status'] === 'planning'): ?>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="quick_action" value="start"><button class="btn-q primary"><i class="fa-solid fa-play"></i> <?= $rtl ? 'تفعيل' : 'Activate' ?></button></form>
<?php elseif ($session['status'] === 'active'): ?>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="quick_action" value="pause"><button class="btn-q warn"><i class="fa-solid fa-pause"></i> <?= $rtl ? 'إيقاف للمراجعة' : 'Pause' ?></button></form>
<form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl ? 'إغلاق الجلسة؟ لن تستطيع التعديل لاحقاً.' : 'Close session? Cannot edit after.' ?>')"><?= csrf_input() ?><input type="hidden" name="quick_action" value="complete"><button class="btn-q primary"><i class="fa-solid fa-flag-checkered"></i> <?= $rtl ? 'إكمال' : 'Complete' ?></button></form>
<?php elseif ($session['status'] === 'review'): ?>
<form method="POST" style="display:inline"><?= csrf_input() ?><input type="hidden" name="quick_action" value="resume"><button class="btn-q primary"><i class="fa-solid fa-play"></i> <?= $rtl ? 'استئناف' : 'Resume' ?></button></form>
<form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl ? 'إغلاق الجلسة؟' : 'Close session?' ?>')"><?= csrf_input() ?><input type="hidden" name="quick_action" value="complete"><button class="btn-q primary"><i class="fa-solid fa-flag-checkered"></i> <?= $rtl ? 'إكمال' : 'Complete' ?></button></form>
<?php endif; ?>
<?php if (in_array($session['status'], ['planning','active','review'])): ?>
<form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl ? 'إلغاء الجلسة؟' : 'Cancel session?' ?>')"><?= csrf_input() ?><input type="hidden" name="quick_action" value="cancel"><button class="btn-q danger"><i class="fa-solid fa-ban"></i> <?= $rtl ? 'إلغاء' : 'Cancel' ?></button></form>
<?php endif; ?>
<?php endif; ?>
<a class="btn-q" href="<?= BASE_URL ?>/inventory/index.php"><i class="fa-solid fa-arrow-<?= $rtl ? 'left' : 'right' ?>"></i> <?= $rtl ? 'القائمة' : 'List' ?></a>
</div>
</div>

<div class="kpis">
<div class="kpi"><div class="ic blue"><i class="fa-solid fa-layer-group"></i></div><div class="v eng"><?= number_format($expected_count) ?></div><div class="l"><?= $rtl ? 'أصول متوقعة بالنطاق' : 'Expected in Scope' ?></div></div>
<div class="kpi"><div class="ic green"><i class="fa-solid fa-check-double"></i></div><div class="v eng"><?= number_format($found) ?></div><div class="l"><?= $rtl ? 'تم فحصها وتأكيدها' : 'Audited / Confirmed' ?></div><div class="s eng"><?= $coverage ?>% <?= $rtl ? 'تغطية' : 'coverage' ?></div></div>
<div class="kpi"><div class="ic amber"><i class="fa-solid fa-hourglass-half"></i></div><div class="v eng"><?= number_format($pending) ?></div><div class="l"><?= $rtl ? 'معلّقة لم تُفحص' : 'Pending' ?></div></div>
<div class="kpi"><div class="ic red"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="v eng"><?= number_format($missing + $damaged + $surplus + $moved + $cust_chg) ?></div><div class="l"><?= $rtl ? 'فروقات مكتشفة' : 'Discrepancies' ?></div><div class="s eng"><?= $missing ?> <?= $rtl ? 'مفقود' : 'missing' ?> · <?= $damaged + $surplus ?> <?= $rtl ? 'إضافي' : 'extra' ?></div></div>
<div class="kpi"><div class="ic purple"><i class="fa-solid fa-clock"></i></div><div class="v" style="font-size:18px; margin-top:6px"><?= e(time_ago($last_scan_at, $rtl)) ?></div><div class="l"><?= $rtl ? 'آخر فحص' : 'Last Scan' ?></div><?php if ($last_scan_at): ?><div class="s eng"><?= date('Y-m-d H:i', strtotime($last_scan_at)) ?></div><?php endif; ?></div>
</div>

<div class="row two-thirds">
<div class="bento">
<h3 class="bento-h"><i class="fa-solid fa-chart-pie"></i> <?= $rtl ? 'التغطية المرئية للجلسة' : 'Session Coverage' ?></h3>
<div class="progress-big">
<div class="head"><div class="label"><?= $rtl ? 'نسبة الأصول التي تم فحصها' : 'Assets audited' ?></div><div class="pct"><?= $coverage ?><small>%</small></div></div>
<div class="bar"><div class="fg" style="width:<?= $coverage ?>%"></div></div>
<div class="totals eng"><?= number_format($found + $missing) ?> <?= $rtl ? 'من' : 'of' ?> <?= number_format($expected_count) ?> <?= $rtl ? 'أصل في النطاق' : 'in scope' ?><?php if ($pending > 0): ?> · <span style="color:#b45309"><?= number_format($pending) ?> <?= $rtl ? 'معلّق' : 'pending' ?></span><?php endif; ?></div>
</div>
<div style="margin-top:18px">
<h4 style="font-size:12.5px; font-weight:900; margin:0 0 10px; color:#475569;"><?= $rtl ? 'توزيع الإحصاءات حسب الإجراء' : 'Action Breakdown' ?></h4>
<div class="action-breakdown">
<?php
$rows = [
    ['confirmed',         $found - $damaged - $moved - $cust_chg],
    ['location_changed',  $moved],
    ['custody_changed',   $cust_chg],
    ['condition_damaged', $damaged],
    ['missing',           $missing],
    ['surplus',           $surplus],
];
$total_act = array_sum(array_column($rows, 1));
foreach ($rows as [$act, $ct]):
    if ($ct <= 0 && $act !== 'confirmed') continue;
    $m = $ACTION_META[$act] ?? ['ar'=>$act,'en'=>$act,'color'=>'#64748b','icon'=>'fa-circle'];
    $pct = $total_act ? round($ct * 100 / max(1, $total_act)) : 0;
?>
<div class="act-row"><div class="ic" style="background:<?= $m['color'] ?>"><i class="fa-solid <?= $m['icon'] ?>"></i></div><div class="nm"><?= $rtl ? $m['ar'] : $m['en'] ?> <small>· <?= $pct ?>%</small></div><div class="ct"><?= number_format($ct) ?></div></div>
<?php endforeach; ?>
</div>
</div>
</div>
<div class="bento">
<h3 class="bento-h"><i class="fa-solid fa-bullseye"></i> <?= $rtl ? 'النطاق المعرّف للجلسة' : 'Session Scope' ?></h3>
<div class="scope-info">
<div class="l"><?= $rtl ? 'نوع النطاق' : 'Scope Type' ?></div>
<div class="v"><?= e($scope_human) ?></div>
<div class="meta"><span><i class="fa-solid fa-tag"></i> <?= e($session['session_code']) ?></span><span><i class="fa-solid fa-layer-group"></i> <?= number_format($expected_count) ?> <?= $rtl ? 'مُتوقع' : 'expected' ?></span><span><i class="fa-solid fa-clock-rotate-left"></i> <?= e(date('Y-m-d', strtotime($session['created_at']))) ?></span></div>
</div>
<?php if (!empty($session['decision_no']) || !empty($session['decision_made_by']) || !empty($session['decision_doc_path'])): $has_doc = !empty($session['decision_doc_path']); ?>
<div style="background:linear-gradient(135deg,#fef3c7,#fde68a); border:1px solid #fbbf24; border-radius:12px; padding:13px 16px; margin-top:14px; font-size:12.5px;">
<div style="font-weight:900; color:#92400e; font-size:12.5px; margin-bottom:6px;"><i class="fa-solid fa-file-signature"></i> <?= $rtl ? 'قرار تشكيل اللجنة' : 'Committee Decision' ?></div>
<div style="color:#78350f; line-height:1.7">
<?php if (!empty($session['decision_no'])): ?><strong><?= $rtl ? 'الرقم:' : 'No.:' ?></strong> <?= e($session['decision_no']) ?><?php endif; ?>
<?php if (!empty($session['decision_date'])): ?> &nbsp;·&nbsp; <strong><?= $rtl ? 'التاريخ:' : 'Date:' ?></strong> <?= e(date('Y-m-d', strtotime($session['decision_date']))) ?><?php endif; ?>
<?php if (!empty($session['decision_made_by'])): ?> &nbsp;·&nbsp; <strong><?= $rtl ? 'صادر من:' : 'Issued by:' ?></strong> <?= e($session['decision_made_by']) ?><?php endif; ?>
</div>
<?php if ($has_doc): ?>
<div style="margin-top:8px"><a href="<?= BASE_URL ?>/<?= e($session['decision_doc_path']) ?>" target="_blank" style="background:#92400e; color:#fff; padding:5px 12px; border-radius:7px; font-size:11.5px; font-weight:800; text-decoration:none; display:inline-flex; align-items:center; gap:5px;"><i class="fa-solid fa-paperclip"></i> <?= $rtl ? 'فتح ملف القرار' : 'Open decision file' ?> <span style="opacity:.7; font-size:10.5px;"><?= e(basename($session['decision_doc_path'])) ?></span></a></div>
<?php endif; ?>
</div>
<?php endif; ?>
<h4 style="font-size:12.5px; font-weight:900; margin:18px 0 8px; color:#475569;"><i class="fa-solid fa-users" style="color:#3b82f6"></i> <?= $rtl ? 'اللجنة' : 'Committee' ?> <span style="font-size:11px; color:#94a3b8">(<?= count($members) ?>)</span></h4>
<?php if (!$members): ?>
<div class="empty-state" style="padding:24px 14px; font-size:12px"><i class="fa-solid fa-user-slash"></i> <?= $rtl ? 'لم يُحدّد أعضاء للجلسة.' : 'No members assigned.' ?></div>
<?php else:
try { $task_names = []; foreach ($pdo->query("SELECT code, name_ar, name_en FROM task_library WHERE is_active=1") as $r) { $task_names[$r['code']] = $rtl ? $r['name_ar'] : $r['name_en']; } } catch (Exception $e) { $task_names = []; }
foreach ($members as $m):
$initials = mb_substr($m['full_name'] ?? 'U', 0, 1);
$cnt = $member_activity[(int)$m['user_id']] ?? 0;
$role_lbl = ['leader'=>$rtl?'رئيس':'Leader','member'=>$rtl?'عضو':'Member','observer'=>$rtl?'مراقب':'Observer'][$m['role']] ?? $m['role'];
$tasks_html = '';
$custom = json_decode($m['custom_tasks'] ?? '[]', true);
if (is_array($custom) && $custom) {
    $task_chips = [];
    foreach ($custom as $t) {
        if (is_array($t) && isset($t['free_text'])) { $task_chips[] = '<span style="display:inline-block;background:#fff;border:1px solid #cbd5e1;color:#475569;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;margin:2px">' . e($t['free_text']) . '</span>'; }
        elseif (is_string($t)) { $label = $task_names[$t] ?? $t; $task_chips[] = '<span style="display:inline-block;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:800;margin:2px"><i class="fa-solid fa-tag" style="margin-left:3px"></i> ' . e($label) . '</span>'; }
    }
    if ($task_chips) $tasks_html = '<div style="margin-top:6px; line-height:1.6">' . implode('', $task_chips) . '</div>';
}
?>
<div class="mem-card">
<div class="av eng"><?= e($initials) ?></div>
<div class="nm"><div class="name"><?= e($m['full_name']) ?></div><div class="dept"><?= e($m['dept_name'] ?? '—') ?></div><?= $tasks_html ?></div>
<div class="act"><strong><?= number_format($cnt) ?></strong><?= $rtl ? 'فحص' : 'scans' ?></div>
<div class="role <?= $m['role'] ?>"><?= $role_lbl ?></div>
</div>
<?php endforeach; endif; ?>
</div>
</div>

<?php if (!empty($reaudit_requests)): ?>
<div class="bento" style="border:1.5px solid #fcd34d; background:#fffbeb;">
<h3 class="bento-h" style="color:#b45309;"><i class="fa-solid fa-flag"></i> <?= $rtl ? 'طلبات إعادة الجرد' : 'Re-audit Requests' ?> <span class="ttl-en" style="background:#f59e0b; color:#fff;"><?= count($reaudit_requests) ?> <?= $rtl ? 'معلَّق' : 'pending' ?></span></h3>
<?php foreach ($reaudit_requests as $req): ?>
<div style="background:#fff; border:1px solid #fde68a; border-radius:12px; padding:14px; margin-bottom:10px;">
<div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; align-items:flex-start;">
<div style="flex:1; min-width:220px;">
<div style="font-weight:800; font-size:14px; color:#0f172a;"><?= e($req['asset_desc']) ?></div>
<div style="font-family:monospace; font-size:12px; color:#64748b; margin-top:2px;" dir="ltr"><?= e($req['tag_number']) ?></div>
<div style="font-size:12.5px; color:#334155; margin-top:8px; background:#f8fafc; border-radius:8px; padding:8px;"><i class="fa-solid fa-quote-right" style="color:#94a3b8; font-size:10px;"></i> <?= e($req['reason']) ?></div>
<div style="font-size:11.5px; color:#94a3b8; margin-top:6px;"><i class="fa-regular fa-user"></i> <?= e($req['requester_name'] ?? '—') ?> &nbsp;·&nbsp; <i class="fa-regular fa-clock"></i> <?= e($req['created_at']) ?></div>
</div>
<?php if ($can_review): ?>
<div style="display:flex; gap:8px; flex-shrink:0;">
<form method="post" onsubmit="return confirm('<?= $rtl ? 'الموافقة تُبطل سجل الجرد السابق وتعيد الأصل متاحاً — متأكد؟' : 'Approval invalidates the previous audit and reopens the asset — sure?' ?>')"><?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>"><button type="submit" name="reaudit_decision" value="approve" class="btn" style="background:#16a34a; color:#fff; border:none; padding:8px 14px; border-radius:8px; font-weight:800; font-size:12.5px; cursor:pointer;"><i class="fa-solid fa-check"></i> <?= $rtl ? 'موافقة' : 'Approve' ?></button></form>
<form method="post"><?= csrf_input() ?><input type="hidden" name="request_id" value="<?= (int)$req['id'] ?>"><button type="submit" name="reaudit_decision" value="reject" class="btn" style="background:#fff; color:#dc2626; border:1.5px solid #fca5a5; padding:8px 14px; border-radius:8px; font-weight:800; font-size:12.5px; cursor:pointer;"><i class="fa-solid fa-xmark"></i> <?= $rtl ? 'رفض' : 'Reject' ?></button></form>
</div>
<?php else: ?>
<span style="background:#fef3c7; color:#b45309; font-size:11.5px; font-weight:800; padding:6px 10px; border-radius:8px; flex-shrink:0;"><?= $rtl ? 'بانتظار الاعتماد' : 'Awaiting approval' ?></span>
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="bento">
<h3 class="bento-h"><i class="fa-solid fa-list-check"></i> <?= $rtl ? 'سجل الفحوصات' : 'Audit Log' ?> <span class="ttl-en"><?= number_format($total_audits) ?> <?= $rtl ? 'سجل' : 'records' ?></span></h3>
<div class="chips">
<a class="chip <?= !$f_action ? 'active' : '' ?>" href="?id=<?= $id ?>"><i class="fa-solid fa-layer-group"></i> <?= $rtl ? 'الكل' : 'All' ?> <span class="ct"><?= number_format(array_sum($chip_counts)) ?></span></a>
<?php foreach ($ACTION_META as $ak => $am): $ct = $chip_counts[$ak] ?? 0; ?>
<a class="chip <?= $f_action === $ak ? 'active' : '' ?>" href="?id=<?= $id ?>&f=<?= $ak ?>"><i class="fa-solid <?= $am['icon'] ?>"></i> <?= $rtl ? $am['ar'] : $am['en'] ?> <span class="ct"><?= number_format($ct) ?></span></a>
<?php endforeach; ?>
</div>
<?php if (!$audits): ?>
<div class="empty-state"><i class="fa-solid fa-qrcode"></i><?= $rtl ? 'لا توجد فحوصات بهذا الفلتر.' : 'No audits for this filter.' ?></div>
<?php else: ?>
<div style="overflow-x:auto">
<table class="tbl">
<thead><tr><th><?= $rtl ? 'الوقت' : 'Time' ?></th><th><?= $rtl ? 'الإجراء' : 'Action' ?></th><th><?= $rtl ? 'الأصل' : 'Asset' ?></th><th><?= $rtl ? 'تفاصيل التغيير' : 'Change Details' ?></th><th><?= $rtl ? 'الفاحص' : 'Auditor' ?></th></tr></thead>
<tbody>
<?php foreach ($audits as $a):
$am = $ACTION_META[$a['action']] ?? ['ar'=>$a['action'],'en'=>$a['action'],'color'=>'#64748b','icon'=>'fa-circle'];
$asset_label = $a['asset_id'] ? '<span class="eng">' . e($a['asset_tag'] ?: '—') . '</span> · ' . e(truncate($a['asset_desc'] ?? '', 50)) : '<span style="color:#0891b2"><i class="fa-solid fa-plus-circle"></i> ' . ($rtl ? 'غير مسجّل' : 'Surplus') . '</span>';
$change = '';
if ($a['action'] === 'location_changed') {
    $old_loc_full = trim(($a['ass_building'] ?? '') . ' / ' . ($a['ass_floor'] ?? '') . ' / ' . ($a['ass_room'] ?? ''), ' /');
    if (!$old_loc_full) $old_loc_full = $a['old_loc_name'] ?? '—';
    $new_loc_full = $a['new_loc_name'] ?? '—';
    $change = '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;"><i class="fa-solid fa-location-dot" style="color:#3b82f6"></i><span style="color:#64748b; text-decoration:line-through;">' . e($old_loc_full) . '</span><i class="fa-solid fa-arrow-left" style="color:#3b82f6"></i><strong style="color:#1e40af;">' . e($new_loc_full) . '</strong></div>';
} elseif ($a['action'] === 'custody_changed') {
    $old_cust = $a['old_custodian_dept_name'] ?? ($rtl ? 'بدون عهدة' : 'No custody');
    $new_cust = $a['new_custodian_dept_name'] ?? '—';
    $change = '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;"><i class="fa-solid fa-user-tag" style="color:#8b5cf6"></i><span style="color:#64748b; text-decoration:line-through;">' . e($old_cust) . '</span><i class="fa-solid fa-arrow-left" style="color:#8b5cf6"></i><strong style="color:#6d28d9;">' . e($new_cust) . '</strong></div>';
} elseif ($a['action'] === 'condition_damaged') {
    $parts = [];
    if (!empty($a['condition_notes'])) $parts[] = '<i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i> ' . e(truncate($a['condition_notes'], 80));
    if (!empty($a['audit_dept_name'])) $parts[] = '<i class="fa-solid fa-building" style="color:#64748b"></i> ' . e($a['audit_dept_name']);
    $change = $parts ? implode(' &nbsp;·&nbsp; ', $parts) : '<span style="color:#f59e0b"><i class="fa-solid fa-triangle-exclamation"></i> ' . ($rtl ? 'تالف/معطّل' : 'Damaged') . '</span>';
} elseif ($a['action'] === 'missing') {
    $parts = ['<span style="color:#dc2626"><i class="fa-solid fa-eye-slash"></i> ' . ($rtl ? 'غير موجود فعلياً' : 'Missing') . '</span>'];
    if (!empty($a['condition_notes'])) $parts[] = '<span style="color:#64748b; font-size:11px;">' . e(truncate($a['condition_notes'], 60)) . '</span>';
    $change = implode(' &nbsp;·&nbsp; ', $parts);
} elseif ($a['action'] === 'missing_disposed_previously') {
    $change = '<span style="color:#4a4a4a"><i class="fa-solid fa-trash-can"></i> ' . ($rtl ? 'مُتلف سابقاً' : 'Disposed previously') . '</span>';
} elseif ($a['action'] === 'missing_under_investigation') {
    $change = '<span style="color:#a16207"><i class="fa-solid fa-magnifying-glass"></i> ' . ($rtl ? 'قيد التحقيق' : 'Under investigation') . '</span>';
    if (!empty($a['condition_notes'])) $change .= ' &nbsp;·&nbsp; <span style="color:#64748b; font-size:11px;">' . e(truncate($a['condition_notes'], 60)) . '</span>';
} elseif ($a['action'] === 'surplus') {
    $change = '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;"><span style="color:#0891b2"><i class="fa-solid fa-plus-circle"></i> ' . ($rtl ? 'تاج: ' : 'Tag: ') . '<strong>' . e($a['scanned_tag'] ?? '—') . '</strong></span>' . ($a['scanned_serial'] ? '<span style="color:#64748b; font-size:11px;">SN: ' . e($a['scanned_serial']) . '</span>' : '') . '</div>';
} elseif ($a['action'] === 'surplus_registered') {
    $change = '<div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;"><span style="color:#0d9488"><i class="fa-solid fa-file-circle-plus"></i> ' . ($rtl ? 'تم تسجيل أصل جديد: ' : 'New asset: ') . '<strong>' . e($a['scanned_tag'] ?? '—') . '</strong></span>' . ($a['scanned_serial'] ? '<span style="color:#64748b; font-size:11px;">SN: ' . e($a['scanned_serial']) . '</span>' : '') . '</div>';
} elseif ($a['action'] === 'confirmed') {
    $parts = ['<span style="color:#10b981"><i class="fa-solid fa-check"></i> ' . ($rtl ? 'موجود' : 'Confirmed') . '</span>'];
    if (!empty($a['audit_dept_name'])) $parts[] = '<i class="fa-solid fa-building" style="color:#64748b"></i> ' . e($a['audit_dept_name']);
    if (!empty($a['condition_notes'])) $parts[] = '<span style="color:#64748b; font-size:11px;">' . e(truncate($a['condition_notes'], 60)) . '</span>';
    $change = implode(' &nbsp;·&nbsp; ', $parts);
}
?>
<tr>
<td><div style="font-weight:900; color:#0f172a"><?= e(time_ago($a['audited_at'], $rtl)) ?></div><div style="font-size:10.5px; color:#94a3b8" class="eng"><?= date('Y-m-d H:i', strtotime($a['audited_at'])) ?></div></td>
<td><span class="badge" style="background:<?= $am['color'] ?>"><i class="fa-solid <?= $am['icon'] ?>"></i> <?= $rtl ? $am['ar'] : $am['en'] ?></span></td>
<td><?= $asset_label ?></td>
<td><?= $change ?: '—' ?></td>
<td><div style="font-weight:800; color:#0f172a"><?= e($a['auditor_name'] ?? '—') ?></div><div style="font-size:10.5px; color:#94a3b8"><?= e($a['scan_method'] ?? '') ?></div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
<?php if ($total_pages > 1): ?>
<div class="pagination">
<?php $base = '?id=' . $id . ($f_action ? '&f=' . urlencode($f_action) : ''); $prev = max(1, $page_n - 1); $next = min($total_pages, $page_n + 1); ?>
<a class="<?= $page_n <= 1 ? 'disabled' : '' ?>" href="<?= $base ?>&p=<?= $prev ?>"><i class="fa-solid fa-chevron-<?= $rtl ? 'right' : 'left' ?>"></i></a>
<span class="current eng"><?= $page_n ?> / <?= $total_pages ?></span>
<a class="<?= $page_n >= $total_pages ? 'disabled' : '' ?>" href="<?= $base ?>&p=<?= $next ?>"><i class="fa-solid fa-chevron-<?= $rtl ? 'left' : 'right' ?>"></i></a>
</div>
<?php endif; ?>
</div>

<div class="bento">
<h3 class="bento-h"><i class="fa-solid fa-hourglass-half"></i> <?= $rtl ? 'أصول في النطاق لم تُفحص بعد' : 'Pending in Scope' ?> <span class="ttl-en"><?= number_format($pending) ?> <?= $rtl ? 'أصل' : 'assets' ?></span></h3>
<?php if (!$pending_assets): ?>
<div class="empty-state"><i class="fa-solid fa-party-horn"></i> <?= $rtl ? 'تمت تغطية كل الأصول المتوقعة.' : 'All expected assets audited.' ?></div>
<?php else: ?>
<div class="pending-grid">
<?php foreach ($pending_assets as $p): ?>
<div class="pending-card">
<span class="tag"><?= e($p['tag_number']) ?></span>
<div class="desc"><?= e(truncate($p['description'], 80)) ?></div>
<div class="meta"><?php if ($p['loc_name']): ?><i class="fa-solid fa-location-dot"></i> <?= e(truncate($p['loc_name'], 40)) ?> · <?php endif; ?><span class="eng"><?= e($p['asset_type'] ?? '') ?></span><?php if (($p['criticality_class'] ?? '') === 'A'): ?> · <span style="color:#dc2626"><strong>A</strong></span><?php endif; ?></div>
</div>
<?php endforeach; ?>
</div>
<?php if ($pending_more > 0): ?>
<div style="margin-top:12px; text-align:center; font-size:12px; color:#64748b; font-weight:700">+<?= number_format($pending_more) ?> <?= $rtl ? 'أصول إضافية معلّقة — تظهر في تقرير الجرد' : 'more pending — see inventory report' ?></div>
<?php endif; ?>
<?php endif; ?>
</div>
</div></main>
</div>
<?php if (file_exists(BASE_PATH.'/inventory/session_radar_ui.php')) include BASE_PATH.'/inventory/session_radar_ui.php'; ?>
</body>
</html>