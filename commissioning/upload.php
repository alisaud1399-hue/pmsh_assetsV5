<?php
/**
 * commissioning/upload.php — رفع صورة الشهادة موقَّعة + اعتماد فوري (البوابة الثانية)
 * + نقل تلقائي للبيانات إلى assets (سد الفجوة الحرجة)
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('commissioning');

$uid = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD']!=='POST' || !verify_csrf()) { flash('danger','طلب غير صالح'); header('Location:'.BASE_URL.'/commissioning/index.php'); exit; }

$cert_id=(int)($_POST['cert_id']??0);
$s=$pdo->prepare("SELECT * FROM commissioning_certificates WHERE id=?"); $s->execute([$cert_id]); $cert=$s->fetch();
if(!$cert){ flash('danger','غير موجود'); header('Location:'.BASE_URL.'/commissioning/index.php'); exit; }

if(empty($_FILES['signed_cert']['name']) || $_FILES['signed_cert']['error']){
    flash('danger','يجب اختيار ملف صحيح');
    header('Location:'.BASE_URL.'/commissioning/form.php?id='.$cert_id); exit;
}

$upd=BASE_PATH.'/uploads/commissioning/';
if(!is_dir($upd)) mkdir($upd,0755,true);
$ext=strtolower(pathinfo($_FILES['signed_cert']['name'],PATHINFO_EXTENSION));
$safe='cc_'.$cert_id.'_'.time().'.'.$ext;
$file_uploaded = false;
$attach_path = null;

// محاولة رفع الملف (اختياري — الاعتماد ممكن بدون ملف موقع)
if (!empty($_FILES['signed_cert']['tmp_name']) &&
    is_uploaded_file($_FILES['signed_cert']['tmp_name']) &&
    move_uploaded_file($_FILES['signed_cert']['tmp_name'], $upd.$safe)) {
    $file_uploaded = true;
    $attach_path = 'commissioning/'.$safe;
}

// 1) تحديث حالة الشهادة إلى "معتمدة" (سواء أُرفق ملف أم لا)
$pdo->prepare("UPDATE commissioning_certificates
               SET attachment_path=?, status='approved', approved_at=NOW() WHERE id=?")
    ->execute([$attach_path, $cert_id]);

$mn=$pdo->prepare("SELECT * FROM receiving_minutes WHERE id=?");
$mn->execute([$cert['receiving_minute_id']]);
$minute=$mn->fetch();
$view_link=BASE_URL.'/commissioning/form.php?id='.$cert_id;

// ════════════════════════════════════════════════════════════════
// نقل تلقائي للبيانات إلى assets (سد الفجوة الحرجة)
// يحدث دائماً بعد الاعتماد — حتى لو لم يُرفق ملف موقع
// ════════════════════════════════════════════════════════════════
$transferResult = transferCertificateToAssets($pdo, $cert_id, $uid);
if ($transferResult['ok']) {
    $asset_id = $transferResult['asset_id'];
    // إشعار لمدير الأصول (إضافة tag/asset_number)
    $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link,related_type,related_id)
        SELECT u.id, 'asset_new', 'أصل جديد يحتاج tag/asset#',
            CONCAT('تم إنشاء سجل لأصل جديد من شهادة تشغيل رقم ', ?, ' — يرجى إضافة tag_number و asset_number.'),
            ?, 'asset', ?
        FROM users u
        INNER JOIN user_roles ur ON ur.user_id = u.id
        INNER JOIN roles r ON r.id = ur.role_id
        WHERE r.name = 'admin' AND u.is_active = 1
        ORDER BY ur.is_primary DESC
        LIMIT 1")
        ->execute([$cert['certificate_number'], BASE_URL.'/assets/form.php?id='.$asset_id, $asset_id]);
} else {
    error_log('transferCertificateToAssets failed for cert_id=' . $cert_id . ': ' . ($transferResult['error'] ?? 'unknown'));
}

if($minute['standing_committee_id']){
    $mgr=$pdo->prepare("SELECT user_id FROM standing_committee_members WHERE committee_id=? AND role='رئيس' LIMIT 1");
    $mgr->execute([$minute['standing_committee_id']]); $mgr_uid=$mgr->fetchColumn();
    if($mgr_uid) $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link,related_type,related_id) VALUES (?,?,?,?,?,?,?)")
        ->execute([$mgr_uid,'commissioning_approved','اعتمدت شهادة تشغيل','اعتُمدت شهادة التشغيل رقم '.$cert['certificate_number'].' — جارٍ التركيب والتشغيل حالياً.',$view_link,'commissioning_certificate',$cert_id]);
}
if($cert['department_id']){
    $cur=$cert['department_id']; $hops=0;
    while($cur && $hops<5){
        $d=$pdo->prepare("SELECT manager_id,parent_id FROM departments WHERE id=?"); $d->execute([$cur]); $row=$d->fetch();
        if(!$row) break;
        if($row['manager_id']){
            $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link,related_type,related_id) VALUES (?,?,?,?,?,?,?)")
                ->execute([$row['manager_id'],'commissioning_approved','اعتمدت شهادة تشغيل لجهازك','اعتُمدت شهادة التشغيل رقم '.$cert['certificate_number'].'.',$view_link,'commissioning_certificate',$cert_id]);
            break;
        }
        $cur=$row['parent_id']; $hops++;
    }
}

// رسالة flash مناسبة
$file_msg = $file_uploaded ? 'مرفق التوقيع محفوظ' : 'الاعتماد بدون مرفق توقيع (يُنصح بإرفاقه لاحقاً)';
if ($transferResult['ok']) {
    $has_full_id = !empty($cert['tag_number']) && !empty($cert['asset_number']);
    $detail = $has_full_id
        ? 'الأصل #' . $transferResult['asset_id'] . ' — تم التحقق تلقائياً (tag + asset#)'
        : 'الأصل #' . $transferResult['asset_id'] . ' — بانتظار إكمال tag/asset#';
    flash('success', 'تم اعتماد الشهادة ونقلها لسجل الأصول بنجاح ✅ (' . $detail . '). ' . $file_msg);
} else {
    flash('danger', 'تم اعتماد الشهادة ❌ — تنبيه: فشل النقل التلقائي لـ assets: ' . ($transferResult['error'] ?? 'unknown'));
}

header('Location:'.BASE_URL.'/commissioning/form.php?id='.$cert_id); exit;
