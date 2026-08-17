<?php
/**
* complaints/my.php — بلاغاتي: قائمة + تفصيل (id) + تأكيد/رفض الحل/إلغاء
* (النسخة الماسية 2025: Zero-Scroll Master-Detail UI - Pro Edition)
*/
require_once dirname(__DIR__) . '/config.php';
page_guard('complaints.my');
$u_data = current_user();
$uid = is_array($u_data) ? (int)($u_data['id'] ?? 0) : (int)$u_data;
$my_dept_id = (int) (current_user()['department_id'] ?? 0);
$is_handler = can('complaints.my', 'manage');
$can_act = $is_handler || can('complaints.my', 'edit');
$id = (int) ($_GET['id'] ?? 0);
$errors = [];

// 1. مصفوفات الحالات
$STATUS_AR = [
    'open' => ['مفتوح', '#ef4444', 'fa-envelope-open-text'],
    'acknowledged' => ['قيد المراجعة', '#d97706', 'fa-handshake-simple'],
    'in_progress' => ['جاري العمل', '#2563eb', 'fa-person-digging'],
    'stalled' => ['متعثر', '#7c3aed', 'fa-pause-circle'],
    'escalated' => ['مُصعَّد للإدارة', '#991b1b', 'fa-angles-up'],
    'resolved' => ['بانتظار تأكيدك', '#059669', 'fa-clipboard-check'],
    'closed' => ['مُغلَق نهائياً', '#0f766e', 'fa-lock'],
    'cancelled' => ['مُلغى', '#64748b', 'fa-ban'],
    'rejected' => ['مرفوض', '#991b1b', 'fa-circle-xmark']
];
$PRI_AR = [
    'normal' => ['عادي', '#10b981', 'fa-check-circle'],
    'urgent' => ['عاجل', '#f59e0b', 'fa-triangle-exclamation'],
    'critical' => ['طوارئ', '#ef4444', 'fa-radiation']
];

// 2. دالة التنبيهات
function notify_sys($pdo, $target_uid, $type, $title, $body, $cid) {
    try {
        if (!$target_uid) return;
        $link = BASE_URL . '/complaints/view.php?id=' . $cid;
        $pdo->prepare("INSERT INTO notifications (user_id, type, title, body, link, related_type, related_id) VALUES (?,?,?,?,?,?,?)")
            ->execute([$target_uid, $type, $title, $body, $link, 'complaint', $cid]);
    } catch (Exception $e) { }
}

// 3. معالجة العمليات (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = (int) ($_POST['complaint_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if (!$can_act) {
        $errors[] = 'غير مصرح لك بهذا الإجراء.';
    } elseif (!verify_csrf()) {
        $errors[] = 'خطأ في الجلسة (CSRF).';
    } else {
        if ($is_handler) {
            $s = $pdo->prepare("SELECT * FROM complaints WHERE id=? AND dept_id=?");
            $s->execute([$cid, $my_dept_id]);
        } else {
            $s = $pdo->prepare("SELECT * FROM complaints WHERE id=? AND requested_by=?");
            $s->execute([$cid, $uid]);
        }
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) {
            $errors[] = 'البلاغ غير موجود أو لا تملك صلاحية الوصول له.';
        } else {
            if ($action === 'confirm' && $c['status'] === 'resolved') {
                $rating = max(1, min(5, (int) ($_POST['rating'] ?? 5)));
                $comment = trim($_POST['comment'] ?? '');
                $pdo->prepare("UPDATE complaints SET status='closed', confirmed_by=?, confirmed_at=NOW(), service_rating=?, service_comment=?, closed_by=?, closed_at=NOW() WHERE id=?")
                    ->execute([$uid, $rating, $comment, $uid, $cid]);
                $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'confirmed','أكّد القسم الحل وأقفل البلاغ','resolved','closed',?)")
                    ->execute([$cid, $uid]);
                
                if (!empty($c['resolved_by'])) {
                    notify_sys($pdo, $c['resolved_by'], 'success',
                        '✅ تم تأكيد الحل وإغلاق البلاغ',
                        'أكّد القسم حل البلاغ #' . $c['request_number'] . ($rating ? ' — التقييم: ' . $rating . '/5' : ''),
                        $cid);
                }
                flash('success', 'تم تأكيد الحل وإقفال البلاغ. شكراً لتقييمك!');
                
            } elseif ($action === 'reject_resolution' && $c['status'] === 'resolved') {
                $reason = trim($_POST['reason'] ?? '');
                if (!$reason) {
                    $errors[] = 'يجب كتابة سبب رفض الحل.';
                } else {
                    $pdo->prepare("UPDATE complaints SET status='in_progress', resolution_rejected_reason=? WHERE id=?")->execute([$reason, $cid]);
                    $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'resolution_rejected',?,'resolved','in_progress',?)")
                        ->execute([$cid, 'رفض المُبلّغ الحل المقترح: ' . $reason, $uid]);
                    if (!empty($c['resolved_by'])) {
                        notify_sys($pdo, $c['resolved_by'], 'warning', '⚠️ رفض الحل المقترح', 'رفض المُبلّغ إغلاق البلاغ #' . $c['request_number'] . ' بسبب: ' . $reason, $cid);
                    }
                    flash('warning', 'تم إعادة البلاغ لفريق الصيانة لمراجعته.');
                }
            } elseif ($action === 'cancel' && in_array($c['status'], ['open', 'acknowledged'])) {
                $reason = trim($_POST['reason'] ?? '');
                $pdo->prepare("UPDATE complaints SET status='cancelled', cancelled_by=?, cancelled_at=NOW(), cancellation_reason=? WHERE id=?")
                    ->execute([$uid, $reason, $cid]);
                $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'cancelled',?,?,'cancelled',?)")
                    ->execute([$cid, 'تراجع المُبلّغ عن البلاغ وإلغاؤه' . ($reason ? ': ' . $reason : ''), $c['status'], $uid]);
                flash('info', 'تم التراجع وإلغاء البلاغ بنجاح.');
            } else {
                $errors[] = 'لا يمكن تنفيذ هذا الإجراء في الحالة الحالية للبلاغ.';
            }
            if (!$errors) { header('Location: ' . BASE_URL . '/complaints/my.php?id=' . $cid); exit; }
        }
    }
}

// 4. جلب القائمة (Master List)
$my_list = [];
$list_active = [];
$list_closed = [];
if ($is_handler) {
    $l = $pdo->prepare("
        SELECT c.id, c.request_number, c.request_type, c.priority, c.status, c.description, c.created_at,
               c.sla_paused_at, c.sla_paused_seconds_total, c.sla_pause_reason, c.resolved_at, c.closed_at,
               c.sla_paused_at, c.sla_paused_seconds_total, c.sla_pause_reason, c.resolved_at, c.closed_at,
        a.description AS asset_desc, d.name AS dept_name, u.full_name AS requester_name
        FROM complaints c
        LEFT JOIN assets a ON a.id=c.asset_id
        LEFT JOIN departments d ON d.id=c.dept_id
        LEFT JOIN users u ON u.id=c.requested_by
        WHERE c.dept_id=? ORDER BY c.created_at DESC LIMIT 150
    ");
    $l->execute([$my_dept_id]);
} else {
    $l = $pdo->prepare("
        SELECT c.id, c.request_number, c.request_type, c.priority, c.status, c.description, c.created_at,
        a.description AS asset_desc, d.name AS dept_name, u.full_name AS requester_name
        FROM complaints c
        LEFT JOIN assets a ON a.id=c.asset_id
        LEFT JOIN departments d ON d.id=c.dept_id
        LEFT JOIN users u ON u.id=c.requested_by
        WHERE c.requested_by=? ORDER BY c.created_at DESC LIMIT 150
    ");
    $l->execute([$uid]);
}
$my_list = $l->fetchAll(PDO::FETCH_ASSOC);

foreach($my_list as $ml) {
    if(in_array($ml['status'], ['closed','cancelled','rejected'])) {
        $list_closed[] = $ml;
    } else {
        $list_active[] = $ml;
    }
}
$stat_total = count($my_list);
$stat_active = count($list_active);
$stat_closed = count($list_closed);

// 5. جلب التفاصيل (Detail Pane)
$detail = null; $timeline = []; $attachments = [];
if ($id) {
    if ($is_handler) {
        $s = $pdo->prepare("
            SELECT c.*, a.description AS asset_desc, a.tag_number, a.manufacturer_name, a.model_number,
            d.name AS dept_name, u.full_name AS requester_name
            FROM complaints c
            LEFT JOIN assets a ON a.id=c.asset_id
            LEFT JOIN departments d ON d.id=c.dept_id
            LEFT JOIN users u ON u.id=c.requested_by
            WHERE c.id=? AND c.dept_id=?
        ");
        $s->execute([$id, $my_dept_id]);
    } else {
        $s = $pdo->prepare("
            SELECT c.*, a.description AS asset_desc, a.tag_number, a.manufacturer_name, a.model_number,
            d.name AS dept_name, u.full_name AS requester_name
            FROM complaints c
            LEFT JOIN assets a ON a.id=c.asset_id
            LEFT JOIN departments d ON d.id=c.dept_id
            LEFT JOIN users u ON u.id=c.requested_by
            WHERE c.id=? AND c.requested_by=?
        ");
        $s->execute([$id, $uid]);
    }
    $detail = $s->fetch(PDO::FETCH_ASSOC);
    if ($detail) {
        $t = $pdo->prepare("SELECT t.*, u.full_name AS actor_name FROM complaint_timeline t LEFT JOIN users u ON u.id=t.actor_id WHERE t.complaint_id=? ORDER BY t.created_at ASC");
        $t->execute([$id]);
        $timeline = $t->fetchAll(PDO::FETCH_ASSOC);
        $at = $pdo->prepare("SELECT * FROM complaint_attachments WHERE complaint_id=?");
        $at->execute([$id]);
        $attachments = $at->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $errors[] = "البلاغ غير موجود أو لا تملك صلاحية الوصول إليه.";
    }
}

$page_title = $is_handler ? 'المركز الإداري - بلاغات قسمي' : 'لوحة تحكم - بلاغاتي';
$active_nav = 'complaints.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@600;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
/* 🌟 ZERO-SCROLL MASTER-DETAIL UI - PRO EDITION 🌟 */
:root { --bg: #f4f7fb; --surface: #ffffff; --text: #0f172a; --muted: #64748b; --border: #e2e8f0; --primary: #2563eb; }
body.app-layout { overflow: hidden; background: var(--bg); font-family: 'Tajawal', sans-serif; -webkit-font-smoothing: antialiased; }
.eng { font-family: 'Inter', sans-serif; }
.main-area { height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
.z-wrapper { height: calc(100vh - 90px); display: flex; gap: 20px; width: 100%; max-width: 1450px; margin: 0 auto; padding: 20px; overflow: hidden; }

/* 📱 Master Pane */
.m-pane { width: 400px; display: flex; flex-direction: column; background: var(--surface); border-radius: 24px; box-shadow: 0 10px 40px rgba(15,23,42,0.04); border: 1px solid var(--border); overflow: hidden; flex-shrink: 0; }
.m-header { padding: 24px; border-bottom: 1px solid var(--border); background: #fff; z-index: 10; }
.mh-title { font-size: 18px; font-weight: 900; margin: 0 0 16px 0; color: var(--text); display: flex; align-items: center; justify-content: space-between; }
.btn-new { background: linear-gradient(135deg, var(--primary), #1d4ed8); color: #fff; padding: 8px 16px; border-radius: 12px; font-size: 12.5px; font-weight: 800; text-decoration: none; box-shadow: 0 4px 12px rgba(37,99,235,0.25); transition: 0.3s cubic-bezier(0.4,0,0.2,1); }
.btn-new:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(37,99,235,0.35); }
.btn-new:active { transform: translateY(0); }

/* Stats Cards */
.mh-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 16px; }
.mhs-box { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 12px; text-align: center; transition: 0.2s; }
.mhs-box:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.03); }
.mhs-lbl { font-size: 11px; font-weight: 800; color: var(--muted); margin-bottom: 4px; }
.mhs-val { font-size: 20px; font-weight: 900; color: var(--text); }
.mhs-box.blue { background: linear-gradient(135deg, #eff6ff, #dbeafe); border-color: #bfdbfe; }
.mhs-box.blue .mhs-lbl { color: #1e40af; }
.mhs-box.blue .mhs-val { color: #1d4ed8; }
.mhs-box.gray { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border-color: #cbd5e1; }

/* Master Tabs */
.m-tabs { display: flex; gap: 6px; background: #f1f5f9; padding: 5px; border-radius: 14px; }
.tab-btn { flex: 1; padding: 10px; border: none; background: transparent; color: var(--muted); font-size: 13px; font-weight: 800; border-radius: 10px; cursor: pointer; transition: 0.3s; }
.tab-btn.active { background: #fff; color: var(--primary); box-shadow: 0 4px 10px rgba(0,0,0,0.05); }

/* Master List Area */
.m-list { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 12px; background: #f8fafc; scroll-behavior: smooth; }
.m-list::-webkit-scrollbar { width: 6px; }
.m-list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }

.m-card { display: block; text-decoration: none; background: #fff; border: 1px solid var(--border); border-radius: 18px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.02); transition: all 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden; }
.m-card::before { content: ''; position: absolute; top: 0; bottom: 0; right: 0; width: 4px; background: var(--pc); opacity: 0; transition: 0.3s; }
.m-card:hover { transform: translateY(-3px); border-color: #cbd5e1; box-shadow: 0 12px 24px rgba(0,0,0,0.06); }
.m-card:hover::before { opacity: 1; }
.m-card.active { background: #eff6ff; border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
.m-card.active::before { opacity: 1; background: var(--primary); }

.mc-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.mc-num { font-size: 14px; font-weight: 900; color: var(--text); }
.mc-date { font-size: 11.5px; font-weight: 700; color: var(--muted); background: #f1f5f9; padding: 2px 8px; border-radius: 6px; }
.mc-title { font-size: 14.5px; font-weight: 800; color: var(--text); margin-bottom: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mc-foot { display: flex; justify-content: space-between; align-items: center; }
.mc-status { padding: 5px 12px; border-radius: 99px; font-size: 11.5px; font-weight: 900; display: inline-flex; align-items: center; gap: 6px; }

/* 🌟 Pulse Effect for Active Status */
.status-pulse::after {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: currentColor;
    display: inline-block;
    animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    margin-right: 4px;
}
@keyframes pulse-ring {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.5); }
}

/* 💻 Detail Pane */
.d-pane { flex: 1; display: flex; flex-direction: column; background: var(--surface); border-radius: 24px; box-shadow: 0 10px 40px rgba(15,23,42,0.04); border: 1px solid var(--border); overflow: hidden; }
.d-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #fff; z-index: 10; }
.dh-left { display: flex; align-items: center; gap: 14px; }
.dh-back { display: none; background: #f1f5f9; color: #475569; width: 36px; height: 36px; border-radius: 10px; text-align: center; line-height: 36px; text-decoration: none; font-size: 16px; }
.dh-title { font-size: 22px; font-weight: 900; margin: 0; color: var(--text); display: flex; align-items: center; gap: 10px; }
.dh-status { padding: 8px 20px; border-radius: 14px; font-size: 14px; font-weight: 900; display: flex; align-items: center; gap: 8px; border: 1px solid; backdrop-filter: blur(4px); }
.d-body { flex: 1; overflow-y: auto; padding: 24px; background: #f8fafc; scroll-behavior: smooth; }
.d-body::-webkit-scrollbar { width: 6px; }
.d-body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
.d-grid { display: grid; grid-template-columns: 1.6fr 1fr; gap: 24px; align-items: start; }
@media (max-width: 1150px) { .d-grid { grid-template-columns: 1fr; } }

/* Bento Cards & Animations */
.bento { background: #fff; border-radius: 20px; border: 1px solid var(--border); padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); animation: fadeInUp 0.5s ease-out forwards; }
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}
.bento-title { font-size: 16px; font-weight: 900; color: var(--text); display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
.bento-title i { color: var(--primary); font-size: 20px; background: #eff6ff; padding: 8px; border-radius: 10px; }

/* Asset Info */
.asset-inner-card { background: linear-gradient(135deg, #f8fafc, #f1f5f9); border: 1px solid #e2e8f0; border-radius: 20px; padding: 24px; }
.aic-top { display: flex; gap: 16px; margin-bottom: 20px; align-items: center; }
.aic-icon { width: 60px; height: 60px; background: #fff; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 26px; color: var(--primary); box-shadow: 0 4px 15px rgba(37,99,235,0.1); border: 1px solid #e2e8f0; flex-shrink: 0; }
.aic-system { font-size: 11.5px; color: var(--primary); font-weight: 900; margin-bottom: 6px; letter-spacing: 0.5px; }
.aic-title { font-size: 16px; font-weight: 900; color: var(--text); margin: 0; line-height: 1.4; }
.aic-bottom { display: flex; gap: 10px; flex-wrap: wrap; }
.aic-tag { background: #fff; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 10px; font-size: 12px; font-weight: 800; color: var(--text); display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
.aic-tag i { color: var(--primary); opacity: 0.8; }

/* Text Blocks */
.desc-txt { background: #f8fafc; border: 1px solid var(--border); border-right: 4px solid var(--primary); padding: 18px; border-radius: 14px; font-size: 14.5px; font-weight: 700; line-height: 1.9; color: var(--text); }
.resolve-txt { margin-top: 16px; background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 1px solid #bbf7d0; border-left: 4px solid #10b981; padding: 18px; border-radius: 14px; }
.stall-txt { margin-top: 16px; background: linear-gradient(135deg, #f5f3ff, #ede9fe); border: 1px solid #ddd6fe; border-left: 4px solid #8b5cf6; padding: 18px; border-radius: 14px; }

/* Action Box */
.act-bento { border: 2px solid #10b981; background: linear-gradient(135deg, #f0fdf4, #fff); box-shadow: 0 10px 30px rgba(16,185,129,0.08); position: sticky; top: 0; }

/* 🌟 Enhanced Star Rating */
.stars { display: flex; flex-direction: row-reverse; justify-content: center; gap: 12px; margin-bottom: 20px; background: #fff; padding: 20px; border-radius: 16px; border: 1px solid #bbf7d0; }
.stars i { font-size: 36px; color: #e2e8f0; cursor: pointer; transition: all 0.2s cubic-bezier(0.4,0,0.2,1); }
.stars i:hover,
.stars i:hover ~ i,
.stars i.active { color: #f59e0b; transform: scale(1.15); filter: drop-shadow(0 4px 10px rgba(245,158,11,0.4)); }

.btn-green, .btn-red { width: 100%; padding: 14px; border-radius: 14px; color: #fff; font-size: 15px; font-weight: 900; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.btn-green { background: linear-gradient(135deg, #10b981, #059669); }
.btn-green:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16,185,129,0.3); }
.btn-red { background: linear-gradient(135deg, #ef4444, #dc2626); }
.btn-red:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(239,68,68,0.3); }
.btn-green:active, .btn-red:active { transform: translateY(0); }

/* 🌟 Pro Timeline (Connected Line) */
.tl-wrap { display: flex; flex-direction: column; gap: 16px; position: relative; padding-right: 24px; }
.tl-wrap::before {
    content: '';
    position: absolute;
    right: 23px; 
    top: 40px;
    bottom: 20px;
    width: 2px;
    background: linear-gradient(to bottom, #cbd5e1, transparent);
    z-index: 0;
}
.tl-card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 16px; display: flex; gap: 16px; align-items: center; box-shadow: 0 2px 8px rgba(0,0,0,0.02); transition: 0.3s; position: relative; z-index: 1; }
.tl-card.active { border-color: var(--primary); background: linear-gradient(135deg, #eff6ff, #fff); box-shadow: 0 4px 15px rgba(37,99,235,0.08); }
.tl-icon-box { width: 48px; height: 48px; border-radius: 14px; background: #f8fafc; border: 2px solid #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 18px; color: var(--muted); flex-shrink: 0; transition: 0.3s; z-index: 2; }
.tl-card.active .tl-icon-box { background: var(--primary); border-color: var(--primary); color: #fff; box-shadow: 0 4px 15px rgba(37,99,235,0.3); }
.tl-txt h4 { margin: 0 0 4px 0; font-size: 14px; font-weight: 900; color: var(--text); }
.tl-txt p { margin: 0; font-size: 12px; font-weight: 700; color: var(--muted); display: flex; align-items: center; gap: 6px; }

/* Alerts */
.alert { padding: 16px 20px; border-radius: 14px; margin-bottom: 20px; font-size: 14px; font-weight: 800; display: flex; align-items: center; gap: 10px; animation: fadeInUp 0.4s ease-out; }

/* Responsive */
@media (max-width: 992px) {
    .main-area { height: auto; overflow: visible; }
    .z-wrapper { height: auto; display: block; padding: 15px; overflow: visible; }
    .m-pane, .d-pane { width: 100%; height: auto; border-radius: 20px; margin-bottom: 20px; }
    .m-pane { display: <?= $id ? 'none' : 'flex' ?>; max-height: calc(100vh - 100px); }
    .d-pane { display: <?= $id ? 'flex' : 'none' ?>; }
    .dh-back { display: block; }
}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="z-wrapper">
    <div class="m-pane">
        <div class="m-header">
            <div class="mh-title">
                <span><i class="fa-solid fa-inbox" style="color:var(--primary); margin-left:6px;"></i> <?= $is_handler ? 'المركز الإداري للقسم' : 'قائمة بلاغاتي' ?></span>
                <a href="create.php" class="btn-new"><i class="fa-solid fa-plus"></i> جديد</a>
            </div>
            <div class="mh-stats">
                <div class="mhs-box">
                    <div class="mhs-lbl">إجمالي</div>
                    <div class="mhs-val eng"><?= $stat_total ?></div>
                </div>
                <div class="mhs-box blue">
                    <div class="mhs-lbl">النشطة</div>
                    <div class="mhs-val eng"><?= $stat_active ?></div>
                </div>
                <div class="mhs-box gray">
                    <div class="mhs-lbl">المغلقة</div>
                    <div class="mhs-val eng"><?= $stat_closed ?></div>
                </div>
            </div>
            <div class="m-tabs">
                <button type="button" class="tab-btn active" onclick="switchList('active')">النشطة</button>
                <button type="button" class="tab-btn" onclick="switchList('closed')">المغلقة / المرفوضة</button>
            </div>
        </div>
        
        <div class="m-list" id="list-active">
            <?php if (!$list_active): ?>
                <div style="text-align:center; padding:40px 10px; color:var(--muted);">
                    <i class="fa-solid fa-check-circle" style="font-size:36px; margin-bottom:12px; color:#cbd5e1; display:block;"></i>
                    <h4 style="margin:0 0 6px 0; font-weight:900;">لا توجد بلاغات نشطة</h4>
                </div>
            <?php else: foreach ($list_active as $r): 
                $st = $STATUS_AR[$r['status']] ?? ['مجهول','#64748b']; 
                $pr = $PRI_AR[$r['priority']] ?? ['','#64748b']; 
                $pulseClass = in_array($r['status'], ['open', 'in_progress', 'acknowledged']) ? 'status-pulse' : '';
            ?>
                <a href="my.php?id=<?= $r['id'] ?>" class="m-card <?= $id === $r['id'] ? 'active' : '' ?>" style="--pc:<?= $pr[1] ?>">
                    <div class="mc-head">
                        <span class="mc-num eng">#<?= e($r['request_number']) ?></span>
                        <span class="mc-date eng"><?= e(date('d M', strtotime($r['created_at']))) ?></span>
                    </div>
                    <div class="mc-title"><?= e(mb_substr($r['description'], 0, 60)) ?></div>
                    <?php
                        $_le = ($r['status'] === 'resolved' || $r['status'] === 'closed')
                            ? strtotime($r['closed_at'] ?: $r['resolved_at'] ?: 'now') : 0;
                    ?>
                    <div style="margin:4px 0 2px">
                        <span class="lvt" style="font-size:10.5px;padding:2px 9px"
                            data-s="<?= strtotime($r['created_at']) ?>"
                            data-p="<?= (int)($r['sla_paused_seconds_total'] ?? 0) ?>"
                            data-pa="<?= !empty($r['sla_paused_at']) && !$_le ? strtotime($r['sla_paused_at']) : 0 ?>"
                            data-e="<?= $_le ?>">
                            <span class="lvt-d" style="width:6px;height:6px"></span>
                            <span class="lvt-dd"></span><span class="lvt-v">—</span>
                            <?php if (!$_le && !empty($r['sla_paused_at'])): ?><span>⏸</span><?php endif; ?>
                        </span>
                    </div>
                    <div class="mc-foot">
                        <div class="mc-status <?= $pulseClass ?>" style="background:<?= $st[1] ?>15; color:<?= $st[1] ?>"><?= $st[0] ?></div>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>

        <div class="m-list" id="list-closed" style="display:none;">
            <?php if (!$list_closed): ?>
                <div style="text-align:center; padding:40px 10px; color:var(--muted);">
                    <i class="fa-solid fa-folder-open" style="font-size:36px; margin-bottom:12px; color:#cbd5e1; display:block;"></i>
                    <h4 style="margin:0 0 6px 0; font-weight:900;">لا توجد بلاغات مغلقة</h4>
                </div>
            <?php else: foreach ($list_closed as $r): 
                $st = $STATUS_AR[$r['status']] ?? ['مجهول','#64748b']; 
                $pr = $PRI_AR[$r['priority']] ?? ['','#64748b']; 
            ?>
                <a href="my.php?id=<?= $r['id'] ?>" class="m-card <?= $id === $r['id'] ? 'active' : '' ?>" style="--pc:<?= $pr[1] ?>">
                    <div class="mc-head">
                        <span class="mc-num eng">#<?= e($r['request_number']) ?></span>
                        <span class="mc-date eng"><?= e(date('d M', strtotime($r['created_at']))) ?></span>
                    </div>
                    <div class="mc-title"><?= e(mb_substr($r['description'], 0, 60)) ?></div>
                    <div class="mc-foot">
                        <div class="mc-status" style="background:<?= $st[1] ?>15; color:<?= $st[1] ?>"><?= $st[0] ?></div>
                    </div>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="d-pane">
        <?php if ($errors): foreach ($errors as $er): ?>
            <div class="alert" style="background:#fef2f2; color:#b91c1c; border-bottom:1px solid #fecaca;"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($er) ?></div>
        <?php endforeach; endif; ?>
        
        <?php foreach ($flash_msgs as $fm): $fc = ['success'=>'#10b981','warning'=>'#f59e0b','info'=>'#3b82f6','danger'=>'#ef4444'][$fm['type']] ?? '#3b82f6'; ?>
            <div class="alert" style="background:#fff; color:var(--text); border-bottom:1px solid <?= $fc ?>40; border-right:4px solid <?= $fc ?>;"><i class="fa-solid fa-circle-info" style="color:<?= $fc ?>;"></i> <?= e($fm['message']) ?></div>
        <?php endforeach; ?>

        <?php if (!$id || !$detail): ?>
            <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#f8fafc; padding:20px;">
                <i class="fa-solid fa-layer-group" style="font-size:70px; color:#e2e8f0; margin-bottom:20px;"></i>
                <h3 style="margin:0 0 8px 0; color:var(--muted); font-weight:900;">حدد بلاغاً من القائمة الجانبية</h3>
                <p style="margin:0; font-size:14px; font-weight:700; color:#94a3b8;">لعرض التفاصيل واتخاذ الإجراءات المطلوبة.</p>
            </div>
        <?php else: 
            $st = $STATUS_AR[$detail['status']] ?? ['مجهول', '#64748b', 'fa-question']; 
            $pr = $PRI_AR[$detail['priority']] ?? ['مجهول', '#64748b']; 
            $pulseClassDetail = in_array($detail['status'], ['open', 'in_progress', 'acknowledged']) ? 'status-pulse' : '';
        ?>
            <div class="d-header">
                <div class="dh-left">
                    <a href="my.php" class="dh-back"><i class="fa-solid fa-arrow-right"></i></a>
                    <h2 class="dh-title eng"><i class="fa-solid fa-hashtag" style="color:var(--primary); font-size:20px;"></i> <?= e($detail['request_number']) ?></h2>
                </div>
                <div class="dh-status <?= $pulseClassDetail ?>" style="background:<?= $st[1] ?>15; color:<?= $st[1] ?>; border-color:<?= $st[1] ?>40;">
                    <i class="fa-solid <?= $st[2] ?>"></i> <?= $st[0] ?>
                </div>
            </div>
            <div class="d-body">
                <div class="d-grid">
                    <div>
                        <div class="bento">
                            <div class="bento-title"><i class="fa-solid fa-microchip"></i> تفاصيل الأصل والبيانات</div>
                            <div class="asset-inner-card">
                                <div class="aic-top">
                                    <div class="aic-icon"><i class="fa-solid <?= !empty($detail['asset_id']) ? 'fa-heart-pulse' : 'fa-building' ?>"></i></div>
                                    <div>
                                        <div class="aic-system">الرقم المرجعي (TAG): <span class="eng"><?= e($detail['tag_number'] ?? 'غير متوفر') ?></span></div>
                                        <h3 class="aic-title"><?= e($detail['asset_desc'] ?? $detail['location_description'] ?? 'بلاغ صيانة عامة (مرافق)') ?></h3>
                                    </div>
                                </div>
                                <div class="aic-bottom">
                                    <?php if(!empty($detail['manufacturer_name'])): ?><div class="aic-tag"><i class="fa-solid fa-industry"></i> الشركة: <?= e($detail['manufacturer_name']) ?></div><?php endif; ?>
                                    <?php if(!empty($detail['model_number'])): ?><div class="aic-tag eng"><i class="fa-solid fa-gear"></i> Model: <?= e($detail['model_number']) ?></div><?php endif; ?>
                                    <div class="aic-tag" style="background:#eff6ff; border-color:#bfdbfe;"><i class="fa-solid fa-user-pen"></i> المُبلّغ: <?= e($detail['requester_name'] ?? '—') ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="bento">
                            <div class="bento-title"><i class="fa-solid fa-quote-right"></i> وصف العطل</div>
                            <div class="desc-txt"><?= nl2br(e($detail['description'])) ?></div>
                            
                            <?php if(!empty($detail['resolution_notes'])): ?>
                                <div class="resolve-txt">
                                    <strong style="color:#065f46; font-size:13.5px; display:flex; align-items:center; gap:8px; margin-bottom:8px;"><i class="fa-solid fa-wrench"></i> التقرير الفني من المهندس:</strong>
                                    <div style="font-size:14.5px; font-weight:800; color:#166534; line-height:1.7;">
                                        <?= nl2br(e($detail['resolution_notes'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($detail['stall_reason']) && $detail['status'] !== 'in_progress'): ?>
                                <div class="stall-txt">
                                    <strong style="color:#4c1d95; font-size:13.5px; display:flex; align-items:center; gap:8px; margin-bottom:8px;"><i class="fa-solid fa-pause-circle"></i> سبب تعثّر المعالجة:</strong>
                                    <div style="font-size:14.5px; font-weight:800; color:#5b21b6; line-height:1.7;">
                                        <?= nl2br(e($detail['stall_reason'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($attachments): ?>
                            <div class="bento">
                                <div class="bento-title"><i class="fa-solid fa-paperclip"></i> المرفقات (<?= count($attachments) ?>)</div>
                                <div style="display:flex; gap:12px; flex-wrap:wrap;">
                                    <?php foreach ($attachments as $att): ?>
                                        <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank" style="background:#f8fafc; border:1px solid #cbd5e1; padding:10px 16px; border-radius:12px; font-size:13px; font-weight:800; color:var(--primary); text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:0.2s;"><i class="fa-solid fa-download"></i> <?= e($att['file_name']) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <?php if ($can_act && $detail['status'] === 'resolved'): ?>
                            <div class="bento act-bento">
                                <h3 style="margin:0 0 10px 0; font-size:16px; font-weight:900; color:#065f46;"><i class="fa-solid fa-clipboard-check"></i> مطلوب تأكيدك لإغلاق البلاغ</h3>
                                <p style="font-size:13px; font-weight:700; color:#166534; margin-bottom:20px; line-height:1.6;">الرجاء التأكد من عمل الجهاز وتقييم الخدمة لإغلاق الدورة المستندية.</p>
                                <form method="POST" id="confirmForm">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="complaint_id" value="<?= $detail['id'] ?>">
                                    <input type="hidden" name="action" id="cfAction" value="confirm">
                                    
                                    <div class="stars" id="starBox">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fa-solid fa-star <?= $i <= 5 ? 'active' : '' ?>" data-v="<?= $i ?>" onclick="setRating(<?= $i ?>)"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <input type="hidden" name="rating" id="ratingVal" value="5">
                                    
                                    <textarea name="comment" rows="2" placeholder="شكر للمهندس أو ملاحظة إضافية (اختياري)" style="width:100%; border:1px solid #bbf7d0; border-radius:12px; padding:12px; font-family:'Tajawal'; font-size:14px; font-weight:700; margin-bottom:16px; outline:none; resize:vertical;"></textarea>
                                    
                                    <button type="button" onclick="document.getElementById('cfAction').value='confirm';document.getElementById('confirmForm').submit()" class="btn-green"><i class="fa-solid fa-check-double"></i> تأكيد وإغلاق نهائي</button>
                                    
                                    <div style="text-align:center; margin-top:16px;">
                                        <button type="button" onclick="document.getElementById('rejBox').style.display='block';this.style.display='none'" style="background:none; border:none; color:#ef4444; font-size:13px; font-weight:900; cursor:pointer; text-decoration:underline;">الجهاز لا يزال معطلاً؟ (رفض الحل)</button>
                                    </div>

                                    <div id="rejBox" style="display:none; margin-top:20px; border-top:1px dashed #bbf7d0; padding-top:20px;">
                                        <label style="font-size:13px; font-weight:900; color:#b91c1c; display:block; margin-bottom:8px;">وضح سبب الإعادة للصيانة <span style="color:#ef4444">*</span></label>
                                        <textarea name="reason" rows="2" style="width:100%; border:1px solid #fca5a5; border-radius:12px; padding:12px; font-family:'Tajawal'; font-size:14px; font-weight:700; margin-bottom:12px; outline:none; resize:vertical;"></textarea>
                                        <button type="button" onclick="document.getElementById('cfAction').value='reject_resolution';document.getElementById('confirmForm').submit()" class="btn-red"><i class="fa-solid fa-rotate-left"></i> إرجاع للفريق الفني</button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if ($can_act && in_array($detail['status'], ['open', 'acknowledged'])): ?>
                            <div class="bento" style="text-align:center;">
                                <button type="button" onclick="document.getElementById('cancelBox').style.display='block';this.style.display='none'" style="background:none; border:none; color:var(--muted); font-size:14px; font-weight:900; cursor:pointer;"><i class="fa-solid fa-ban"></i> هل تم حله داخلياً؟ (إلغاء البلاغ)</button>
                                <form method="POST" id="cancelBox" style="display:none; text-align:right; margin-top:16px;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="complaint_id" value="<?= $detail['id'] ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <label style="font-size:13px; font-weight:900; color:#b91c1c; display:block; margin-bottom:8px;">سبب التراجع (اختياري)</label>
                                    <textarea name="reason" rows="2" style="width:100%; border:1px solid #fecaca; background:#fef2f2; border-radius:12px; padding:12px; font-family:'Tajawal'; font-size:14px; font-weight:700; margin-bottom:12px; outline:none; resize:vertical;"></textarea>
                                    <button type="submit" class="btn-red"><i class="fa-solid fa-trash"></i> إلغاء البلاغ</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <div class="bento" style="padding:24px;">
                            <div class="bento-title" style="margin-bottom:20px;"><i class="fa-solid fa-bars-staggered"></i> المسار الزمني</div>
                            <div class="tl-wrap">
                                <?php foreach ($timeline as $idx => $tl): $isLast = $idx === count($timeline)-1; ?>
                                    <div class="tl-card <?= $isLast ? 'active' : '' ?>">
                                        <div class="tl-icon-box">
                                            <i class="fa-solid <?= $isLast ? 'fa-flag-checkered' : 'fa-check' ?>"></i>
                                        </div>
                                        <div class="tl-txt">
                                            <h4><?= e($tl['action_label']) ?></h4>
                                            <p>
                                                <i class="fa-solid fa-user-tag" style="opacity:0.6;"></i> 
                                                <?= e($tl['actor_name'] ?? 'النظام') ?> &bull; 
                                                <span class="eng"><?= date('d/m/Y - h:i A', strtotime($tl['created_at'])) ?></span>
                                            </p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</main>
</div>

<script>
// وظيفة التبديل بين قوائم (النشطة / المغلقة)
function switchList(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelector(`.tab-btn[onclick="switchList('${tab}')"]`).classList.add('active');
    document.getElementById('list-active').style.display = tab === 'active' ? 'flex' : 'none';
    document.getElementById('list-closed').style.display = tab === 'closed' ? 'flex' : 'none';
}

// وظيفة تقييم النجوم
function setRating(v) {
    document.getElementById('ratingVal').value = v;
    document.querySelectorAll('#starBox i').forEach(s => {
        if (parseInt(s.dataset.v) <= v) { s.classList.add('active'); }
        else { s.classList.remove('active'); }
    });
}

// تفعيل الرابط في السايدبار
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.sidebar a').forEach(a => {
        if (a.href.includes('complaints/my.php')) a.classList.add('active');
        else if (a.href.includes('complaints/index.php') && window.location.href.includes('my.php')) a.classList.remove('active');
    });
});
</script>
<style>
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
</style>
<script>
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
</script>
</body>
</html>