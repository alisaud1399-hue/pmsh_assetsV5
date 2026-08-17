<?php
/**
 * receiving/sign.php — معالجة التوقيع / الرفض
 */
require_once dirname(__DIR__) . '/config.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    flash('danger', is_rtl()?'طلب غير صالح':'Invalid request');
    header('Location: ' . BASE_URL . '/receiving/index.php'); exit;
}

$rtl         = is_rtl();
$approval_id = (int)($_POST['approval_id'] ?? 0);
$minute_id   = (int)($_POST['minute_id']   ?? 0);
$action      = $_POST['action'] ?? '';
$notes       = trim($_POST['notes'] ?? '');
$uid         = (int)current_user()['id'];

if (!$approval_id || !$minute_id || !in_array($action,['approve','reject'])) {
    flash('danger', $rtl?'بيانات غير صحيحة':'Invalid data');
    header('Location: '.BASE_URL.'/receiving/index.php'); exit;
}

// التحقق أن هذا التوقيع للمستخدم الحالي
$ap = $pdo->prepare("SELECT * FROM document_approvals WHERE id=? AND user_id=? AND status='pending' LIMIT 1");
$ap->execute([$approval_id, $uid]); $approval = $ap->fetch();
if (!$approval) {
    flash('danger', $rtl?'لا تملك صلاحية التوقيع على هذا المحضر':'Not authorized to sign this minute');
    header('Location: '.BASE_URL.'/receiving/view.php?id='.$minute_id); exit;
}

// تأكد أن جميع من قبله وقّعوا
$prev = $pdo->prepare("SELECT COUNT(*) FROM document_approvals WHERE doc_type='receiving_minute' AND doc_id=? AND sequence_no<? AND status!='approved'");
$prev->execute([$minute_id, $approval['sequence_no']]);
if ((int)$prev->fetchColumn() > 0) {
    flash('danger', $rtl?'يجب أن يوقع من قبلك أولاً':'Previous members must sign first');
    header('Location: '.BASE_URL.'/receiving/view.php?id='.$minute_id); exit;
}

// تنفيذ الإجراء
$new_status = $action === 'approve' ? 'approved' : 'rejected';
$pdo->prepare("UPDATE document_approvals SET status=?,notes=?,approved_at=NOW() WHERE id=?")
    ->execute([$new_status, $notes?:null, $approval_id]);

log_activity($action,'receiving','ApprovalID:'.$approval_id.' MinuteID:'.$minute_id);

if ($action === 'reject') {
    // رفض → المحضر مرفوض
    $pdo->prepare("UPDATE receiving_minutes SET status='rejected' WHERE id=?")->execute([$minute_id]);
    flash('danger', $rtl?'تم رفض المحضر':'Minute rejected');
} else {
    // تحقق هل اكتمل كل التوقيعات
    $remaining = $pdo->prepare("SELECT COUNT(*) FROM document_approvals WHERE doc_type='receiving_minute' AND doc_id=? AND status='pending'");
    $remaining->execute([$minute_id]);
    if ((int)$remaining->fetchColumn() === 0) {
        $pdo->prepare("UPDATE receiving_minutes SET status='completed',completed_at=NOW() WHERE id=?")->execute([$minute_id]);
        flash('success', $rtl?'اكتمل المحضر — جميع الأعضاء وقّعوا ✅':'Minute completed — all members signed ✅');
    } else {
        flash('success', $rtl?'تم توقيعك بنجاح، في انتظار الأعضاء التاليين':'Signed successfully, awaiting next members');
    }
}

header('Location: '.BASE_URL.'/receiving/view.php?id='.$minute_id); exit;
