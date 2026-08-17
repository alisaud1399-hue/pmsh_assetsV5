<?php
/**
 * disposal/api/save.php — حفظ قرار تخلص جديد
 * ─────────────────────────────────────────────────────────────
 *  POST: asset_id, disposal_type, reason, reason_notes,
 *        committee_reference, committee_date, committee_chairman,
 *        committee_members, decision_doc_number, disposal_date,
 *        disposal_value, notes, attachments[]
 *
 *  Effect: assets.status = 'disposed'
 */
require_once dirname(__DIR__, 2) . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.0 405 Method Not Allowed');
    exit(is_rtl()?'طريقة غير مسموحة':'Method not allowed');
}

if (!can('disposal.index', 'create')) {
    $_SESSION['flash'] = ['danger', is_rtl()?'⛔ لا تملك صلاحية التسجيل':'⛔ No create permission'];
    header('Location:'.BASE_URL.'/disposal/index.php?tab=records');
    exit;
}

if (!verify_csrf()) {
    $_SESSION['flash'] = ['danger', is_rtl()?'⚠️ جلسة منتهية، أعد المحاولة':'⚠️ Session expired, retry'];
    header('Location:'.BASE_URL.'/disposal/index.php?tab=records');
    exit;
}

$rtl = is_rtl();

// ═══════════════ التحقق من المدخلات ═══════════════
$asset_id   = (int)($_POST['asset_id'] ?? 0);
$type       = $_POST['disposal_type'] ?? '';
$reason     = $_POST['reason'] ?? '';
$reason_notes = trim($_POST['reason_notes'] ?? '');
$cref       = trim($_POST['committee_reference'] ?? '');
$cdate      = $_POST['committee_date'] ?? null;
$chairman   = trim($_POST['committee_chairman'] ?? '');
$members    = trim($_POST['committee_members'] ?? '');
$doc_num    = trim($_POST['decision_doc_number'] ?? '');
$exec_date  = $_POST['disposal_date'] ?? '';
$value      = (float)($_POST['disposal_value'] ?? 0);
$notes      = trim($_POST['notes'] ?? '');

$errors = [];
if ($asset_id <= 0)         $errors[] = $rtl?'⚠️ يجب اختيار أصل':'⚠️ Asset required';
if (!in_array($type, ['scrap','destroy','sell','transfer_out'], true)) $errors[] = $rtl?'⚠️ نوع التخلص غير صالح':'⚠️ Invalid type';
if (!in_array($reason, ['obsolete','damaged_beyond_repair','end_of_life','lost','replaced','other'], true)) $errors[] = $rtl?'⚠️ السبب غير صالح':'⚠️ Invalid reason';
if (!$exec_date)            $errors[] = $rtl?'⚠️ تاريخ التنفيذ مطلوب':'⚠️ Execution date required';
if ($type === 'sell' && $value <= 0) $errors[] = $rtl?'⚠️ قيمة البيع مطلوبة لحالة البيع':'⚠️ Sale value required for sell';

if ($errors) {
    $_SESSION['flash'] = ['danger', implode(' • ', $errors)];
    header('Location:'.BASE_URL.'/disposal/index.php?tab=new');
    exit;
}

// ═══════════════ التحقق من الأصل ═══════════════
$st = $pdo->prepare("SELECT id, tag_number, description, status FROM assets WHERE id=?");
$st->execute([$asset_id]);
$asset = $st->fetch(PDO::FETCH_ASSOC);

if (!$asset) {
    $_SESSION['flash'] = ['danger', $rtl?'⛔ الأصل غير موجود':'⛔ Asset not found'];
    header('Location:'.BASE_URL.'/disposal/index.php?tab=new');
    exit;
}

if (in_array($asset['status'], ['disposed','returned_to_supplier'], true)) {
    $_SESSION['flash'] = ['warning', $rtl?'⚠️ هذا الأصل مُتخلَّص منه مسبقاً':'⚠️ This asset is already disposed'];
    header('Location:'.BASE_URL.'/disposal/index.php?tab=records');
    exit;
}

// ═══════════════ معالجة المرفقات ═══════════════
$attachments_meta = [];
if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
    $upload_dir = dirname(__DIR__, 2) . '/uploads/disposal/' . date('Y/m') . '/';
    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
    $count = count($_FILES['attachments']['name']);
    for ($i = 0; $i < min($count, 5); $i++) {
        if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $orig = $_FILES['attachments']['name'][$i];
        $tmp  = $_FILES['attachments']['tmp_name'][$i];
        $size = (int)$_FILES['attachments']['size'][$i];
        $type_mime = $_FILES['attachments']['type'][$i] ?? '';
        // فحص بسيط للحجم (5MB)
        if ($size > 5 * 1024 * 1024) continue;
        // اسم فريد
        $ext = pathinfo($orig, PATHINFO_EXTENSION);
        $safe_name = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
        $final_name = $safe_name . '_' . substr(uniqid('', true), -6) . ($ext ? '.'.$ext : '');
        $dest_path  = 'disposal/' . date('Y/m') . '/' . $final_name;
        $dest_full  = dirname(__DIR__, 2) . '/uploads/' . $dest_path;
        if (move_uploaded_file($tmp, $dest_full)) {
            $attachments_meta[] = [
                'name' => $orig,
                'path' => $dest_path,
                'size' => $size,
                'type' => $type_mime,
            ];
        }
    }
}

$attachments_json = !empty($attachments_meta) ? json_encode($attachments_meta, JSON_UNESCAPED_UNICODE) : null;

// ═══════════════ الحفظ ═══════════════
try {
    $pdo->beginTransaction();

    $pdo->prepare("
        INSERT INTO asset_disposals
        (asset_id, disposal_type, reason, reason_notes,
         committee_reference, committee_date, committee_chairman, committee_members,
         decision_doc_number, disposal_date, disposal_value,
         attachments, notes, created_by)
        VALUES (?,?,?,?, ?,?,?,?, ?,?,?, ?,?,?)
    ")->execute([
        $asset_id, $type, $reason, $reason_notes ?: null,
        $cref ?: null, ($cdate ?: null) ?: null, $chairman ?: null, $members ?: null,
        $doc_num ?: null, $exec_date, $value > 0 ? $value : null,
        $attachments_json, $notes ?: null, (int)user_id()
    ]);

    $new_id = (int)$pdo->lastInsertId();

    // تحديث حالة الأصل
    $pdo->prepare("UPDATE assets SET status='disposed' WHERE id=?")->execute([$asset_id]);

    $pdo->commit();

    $_SESSION['flash'] = ['success', $rtl
        ? "✅ تم تسجيل قرار التخلص بنجاح (#$new_id). الأصل الآن بحالة (مُستبعَد) ومخفي من القوائم النشطة."
        : "✅ Disposal recorded successfully (#$new_id). Asset is now DISPOSED and hidden from active lists."];

    header('Location:'.BASE_URL.'/disposal/index.php?tab=view&id='.$new_id);
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('disposal save error: '.$e->getMessage());
    $_SESSION['flash'] = ['danger', '⛔ '.($rtl?'خطأ في الحفظ':'Save error: ').$e->getMessage()];
    header('Location:'.BASE_URL.'/disposal/index.php?tab=new');
    exit;
}
