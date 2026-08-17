<?php
/**
 * nupco/sync.php — مزامنة كتالوج NUPCO (4 خطوات)
 * ─────────────────────────────────────────────────────────
 *   ① رفع الملف      (POST multipart)
 *   ② مراجعة الفروقات (preview)
 *   ③ تطبيق التغييرات (POST مع التحديد)
 *   ④ النتيجة        (done)
 *
 *   • يُسمح فقط للمدير (admin) بـ apply
 *   • المدير التنفيذي + المدير يقدرون يقرأون (view)
 *   • السجل الكامل في nupco/sync_history
 */

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/_lib.php';

$rtl        = is_rtl();
$uid        = (int) user_id();
$can_view   = can('nupco.sync', 'view');
$can_apply  = can('nupco.sync', 'apply');
$can_log    = can('nupco.history', 'log');

if (!$can_view) {
    http_response_code(403);
    die($rtl ? '⛔ لا تملك صلاحية الوصول لهذه الصفحة' : '⛔ No access permission');
}

$step = $_GET['step'] ?? 'upload';
if (!in_array($step, ['upload', 'preview', 'done'], true)) { $step = 'upload'; }

// ═══════════════════════════════════════════════════════════════
// التبويبات الرئيسية: sync (الإجراء) | history (السجل)
// ═══════════════════════════════════════════════════════════════
$tab = $_GET['tab'] ?? 'sync';
if (!in_array($tab, ['sync', 'history'], true)) { $tab = 'sync'; }

// التحقق من الصلاحية حسب التاب
if ($tab === 'history' && !$can_log) {
    http_response_code(403);
    die($rtl ? '⛔ لا تملك صلاحية عرض السجل' : '⛔ No log access permission');
}

$sync_id  = (int)($_GET['id'] ?? 0);
$error    = null;
$success  = null;

// ═══════════════════════════════════════════════════════════════
// POST handlers
// ═══════════════════════════════════════════════════════════════

// POST: استلام ملف (step=upload)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!verify_csrf()) {
        $error = $rtl ? 'الجلسة منتهية — أعد المحاولة' : 'Session expired';
    } elseif (empty($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
        $error = $rtl ? 'لم يتم استلام الملف' : 'No file received';
    } else {
        $file = $_FILES['xlsx'];
        // التحقق من الامتداد
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            $error = $rtl ? 'يجب أن يكون الملف بصيغة xlsx' : 'Only .xlsx files are accepted';
        } elseif ($file['size'] > 20 * 1024 * 1024) {
            $error = $rtl ? 'حجم الملف يتجاوز 20 ميجابايت' : 'File too large (max 20MB)';
        } else {
            // حساب hash
            $hash = hash_file('sha256', $file['tmp_name']);

            // التحقق من التكرار (soft check — مع خيار التأكيد من المستخدم)
            $force = (int)($_POST['force'] ?? 0);
            $dup = $pdo->prepare("
                SELECT id, file_name, sync_date, status, new_count, updated_count, not_in_excel_count
                FROM nupco_sync_log
                WHERE file_hash = ?
                ORDER BY sync_date DESC LIMIT 1
            ");
            $dup->execute([$hash]);
            $existing = $dup->fetch(PDO::FETCH_ASSOC);

            if ($existing && !$force) {
                // تنظيف الملفات المؤقتة القديمة (> يوم)
                $temp_dir = dirname(__DIR__) . '/uploads/nupco/temp/';
                if (!is_dir($temp_dir)) { @mkdir($temp_dir, 0755, true); }
                foreach (glob($temp_dir . 'pending_*.xlsx') ?: [] as $old) {
                    if (filemtime($old) < time() - 86400) { @unlink($old); }
                }

                // حفظ الملف في temp + عرض شاشة التأكيد
                $temp_filename = 'pending_' . substr(md5(session_id() . microtime(true)), 0, 12) . '.xlsx';
                $temp_path = $temp_dir . $temp_filename;
                if (move_uploaded_file($file['tmp_name'], $temp_path)) {
                    $_SESSION['nupco_pending_upload'] = [
                        'file_path'  => $temp_path,
                        'file_name'  => $file['name'],
                        'file_size'  => (int)$file['size'],
                        'hash'       => $hash,
                        'existing'   => $existing,
                        'created_at' => time(),
                    ];
                    $show_confirm = true;
                } else {
                    $error = $rtl ? 'فشل تخزين الملف المؤقت' : 'Failed to store temp file';
                }
            } else {
                // إنشاء سجل أولي
                $pdo->prepare("
                    INSERT INTO nupco_sync_log (file_name, file_size, file_hash, status, synced_by)
                    VALUES (?, ?, ?, 'uploaded', ?)
                ")->execute([$file['name'], (int)$file['size'], $hash, $uid]);
                $new_sync_id = (int)$pdo->lastInsertId();

                // تخزين الملف
                $stored = store_temp_file($file, $new_sync_id);
                if (!$stored) {
                    $pdo->prepare("UPDATE nupco_sync_log SET status='failed', error_log=? WHERE id=?")
                        ->execute([json_encode(['Failed to store file'], JSON_UNESCAPED_UNICODE), $new_sync_id]);
                    $error = $rtl ? 'فشل تخزين الملف' : 'Failed to store file';
                } else {
                    // محاولة التحليل
                    $parsed = parse_nupco_file($stored);
                    if (!empty($parsed['meta']['error'])) {
                        $pdo->prepare("UPDATE nupco_sync_log SET status='failed', error_log=? WHERE id=?")
                            ->execute([json_encode([$parsed['meta']['error']], JSON_UNESCAPED_UNICODE), $new_sync_id]);
                        @unlink($stored);
                        $error = $rtl ? 'فشل قراءة الملف: ' . $parsed['meta']['error'] : 'Parse error: ' . $parsed['meta']['error'];
                    } else {
                        // حفظ عدد الصفوف
                        $pdo->prepare("UPDATE nupco_sync_log SET sheet_name=?, rows_in_file=?, status='previewed' WHERE id=?")
                            ->execute([$parsed['meta']['sheet_name'], $parsed['meta']['total_rows'], $new_sync_id]);
                        // تخزين في session للتطبيق اللاحق
                        $_SESSION['nupco_sync_' . $new_sync_id] = [
                            'file_path' => $stored,
                            'parsed'    => $parsed,
                        ];
                        header('Location: ?step=preview&id=' . $new_sync_id);
                        exit;
                    }
                }
            }
        }
    }
}

// POST: تأكيد رفع ملف مكرر (force=1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_upload') {
    if (!verify_csrf()) {
        $error = $rtl ? 'الجلسة منتهية — أعد المحاولة' : 'Session expired';
    } else {
        $pending = $_SESSION['nupco_pending_upload'] ?? null;
        if (!$pending || !file_exists($pending['file_path'])) {
            $error = $rtl ? 'انتهت صلاحية الملف المؤقت — أعد رفعه' : 'Temp file expired — re-upload';
            unset($_SESSION['nupco_pending_upload']);
        } else {
            // إنشاء سجل مزامنة جديد (مع نفس الـ hash)
            $pdo->prepare("
                INSERT INTO nupco_sync_log (file_name, file_size, file_hash, status, synced_by)
                VALUES (?, ?, ?, 'uploaded', ?)
            ")->execute([$pending['file_name'], (int)$pending['file_size'], $pending['hash'], $uid]);
            $new_sync_id = (int)$pdo->lastInsertId();

            // نقل الملف من temp إلى المكان النهائي
            $target_dir = dirname(__DIR__) . '/uploads/nupco/' . date('Y/m/');
            if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
            $target_path = $target_dir . 'nupco_sync_' . $new_sync_id . '_' . date('His') . '.xlsx';
            @rename($pending['file_path'], $target_path);

            // تحليل الملف
            $parsed = parse_nupco_file($target_path);
            if (!empty($parsed['meta']['error'])) {
                $pdo->prepare("UPDATE nupco_sync_log SET status='failed', error_log=? WHERE id=?")
                    ->execute([json_encode([$parsed['meta']['error']], JSON_UNESCAPED_UNICODE), $new_sync_id]);
                @unlink($target_path);
                unset($_SESSION['nupco_pending_upload']);
                $error = $rtl ? 'فشل قراءة الملف' : 'Parse error';
            } else {
                $pdo->prepare("UPDATE nupco_sync_log SET sheet_name=?, rows_in_file=?, status='previewed' WHERE id=?")
                    ->execute([$parsed['meta']['sheet_name'], $parsed['meta']['total_rows'], $new_sync_id]);
                $_SESSION['nupco_sync_' . $new_sync_id] = [
                    'file_path' => $target_path,
                    'parsed'    => $parsed,
                ];
                unset($_SESSION['nupco_pending_upload']);
                header('Location: ?step=preview&id=' . $new_sync_id);
                exit;
            }
        }
    }
}

// POST: إلغاء الرفع المؤقت (مسح ملف temp)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_upload') {
    $pending = $_SESSION['nupco_pending_upload'] ?? null;
    if ($pending && file_exists($pending['file_path'])) {
        @unlink($pending['file_path']);
    }
    unset($_SESSION['nupco_pending_upload']);
    header('Location: ?tab=sync');
    exit;
}

// POST: تطبيق (step=apply)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    if (!$can_apply) {
        http_response_code(403);
        die($rtl ? '⛔ لا تملك صلاحية التطبيق' : '⛔ No apply permission');
    }
    $apply_id = (int)($_POST['sync_id'] ?? 0);
    if ($apply_id <= 0) {
        $error = $rtl ? 'معرّف المزامنة غير صالح' : 'Invalid sync id';
    } else {
        $cache = $_SESSION['nupco_sync_' . $apply_id] ?? null;
        if (!$cache || !file_exists($cache['file_path'])) {
            $error = $rtl ? 'انتهت صلاحية الجلسة — أعد رفع الملف' : 'Session expired — please re-upload';
        } else {
            $selected_new = (array)($_POST['new'] ?? []);
            $selected_updated = (array)($_POST['updated'] ?? []);
            $result = apply_sync($pdo, $apply_id, $selected_new, $selected_updated, $cache['parsed']['items']);
            // حذف الملف المؤقت
            @unlink($cache['file_path']);
            unset($_SESSION['nupco_sync_' . $apply_id]);
            // إعادة توجيه للنتيجة
            header('Location: ?step=done&id=' . $apply_id);
            exit;
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// GET: قراءة بيانات للمعاينة
// ═══════════════════════════════════════════════════════════════
$sync_log = null;
$diff     = null;
$excel_data = null;
$db_data    = null;
$duplicates = [];
$has_assets_in_removed = [];  // item_no => count

if ($step === 'preview' || $step === 'done') {
    if ($sync_id <= 0) {
        header('Location: ?step=upload');
        exit;
    }
    $st = $pdo->prepare("SELECT * FROM nupco_sync_log WHERE id = ?");
    $st->execute([$sync_id]);
    $sync_log = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sync_log) {
        $error = $rtl ? 'سجل المزامنة غير موجود' : 'Sync log not found';
        $step = 'upload';
    } else {
        // تحميل البيانات من session (إن وجدت) أو من الملف
        $cache = $_SESSION['nupco_sync_' . $sync_id] ?? null;
        if ($cache && file_exists($cache['file_path'])) {
            $parsed = parse_nupco_file($cache['file_path']);
            $duplicates = $parsed['duplicates'] ?? [];
            $excel_data = $parsed['items'] ?? [];
        } elseif ($step === 'done') {
            // في خطوة done، الملف محذوف — نكتفي بعرض السجل
            $excel_data = [];
        } else {
            // الملف غير موجود في session — أعد الرفع
            $error = $rtl ? 'انتهت صلاحية الملف — أعد رفعه' : 'File expired — please re-upload';
            $step = 'upload';
        }

        if ($excel_data && $step === 'preview') {
            // تحميل DB
            $dbRows = $pdo->query("SELECT item_no, generic_code, description_en, category, sub_category FROM nupco_catalog")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dbRows as $r) { $db_data[$r['item_no']] = $r; }

            // المقارنة
            $diff = compute_diff($excel_data, $db_data);

            // حساب عدد الأصول للأصناف المحذوفة
            if (!empty($diff['removed'])) {
                $place = implode(',', array_fill(0, count($diff['removed']), '?'));
                $stmt = $pdo->prepare("SELECT item_code, COUNT(*) c FROM assets WHERE item_code IN ($place) GROUP BY item_code");
                $stmt->execute($diff['removed']);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $has_assets_in_removed[$r['item_code']] = (int)$r['c'];
                }
            }

            // تحديث الإحصائيات في السجل
            $pdo->prepare("
                UPDATE nupco_sync_log
                SET matched_count = ?, new_count = ?, updated_count = ?, not_in_excel_count = ?
                WHERE id = ?
            ")->execute([
                $diff['matched'],
                count($diff['new']),
                count($diff['updated']),
                count($diff['removed']),
                $sync_id,
            ]);
            $sync_log['matched_count'] = $diff['matched'];
            $sync_log['new_count'] = count($diff['new']);
            $sync_log['updated_count'] = count($diff['updated']);
            $sync_log['not_in_excel_count'] = count($diff['removed']);
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// View
// ═══════════════════════════════════════════════════════════════
$page_title = $rtl ? 'مزامنة كتالوج NUPCO' : 'NUPCO Catalog Sync';
$active_nav = 'nupco.sync';
$breadcrumb = [['name' => $rtl ? 'النبكو' : 'NUPCO', 'url' => '']];
$flash_msgs = get_flash();

// تحديد active_nav و page_title بناءً على التاب
$active_nav = $tab === 'history' ? 'nupco.history' : 'nupco.sync';
$page_title = ($tab === 'history')
    ? ($rtl ? 'سجل مزامنات NUPCO' : 'NUPCO Sync History')
    : ($rtl ? 'مزامنة كتالوج NUPCO' : 'NUPCO Catalog Sync');
$breadcrumb = [['name' => $rtl ? 'النبكو' : 'NUPCO', 'url' => '']];
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
:root { --primary:#1565C0; --primary-dark:#0d47a1; --success:#16a34a; --warning:#d97706; --danger:#dc2626; }
.np-wrap { max-width: 1400px; margin: 0 auto; padding: 18px 20px; }

/* ═══ شريط الخطوات ═══ */
.np-steps { display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 24px; padding: 0; list-style: none; }
.np-step { display: flex; align-items: center; gap: 10px; }
.np-step .np-num { width: 36px; height: 36px; border-radius: 50%; background: #e2e8f0; color: #64748b; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; transition: all .2s; }
.np-step .np-lbl { font-size: 13px; font-weight: 700; color: #64748b; }
.np-step.active .np-num { background: var(--primary); color: #fff; box-shadow: 0 4px 12px rgba(21,101,192,.3); }
.np-step.active .np-lbl { color: #0f172a; }
.np-step.done .np-num { background: var(--success); color: #fff; }
.np-step.done .np-lbl { color: var(--success); }
.np-step-sep { width: 60px; height: 2px; background: #e2e8f0; margin: 0 8px; }
.np-step.done + .np-step-sep, .np-step-sep.done { background: var(--success); }

/* ═══ رفع الملف ═══ */
.np-upload-zone {
    display: block;          /* override <label> default inline */
    width: 100%;
    box-sizing: border-box;
    border: 2.5px dashed #cbd5e1; border-radius: 18px; padding: 60px 30px; text-align: center;
    background: linear-gradient(135deg, #f8fafc 0%, #fff 100%); transition: all .25s; cursor: pointer;
    margin-bottom: 18px;
}
.np-upload-zone:hover, .np-upload-zone.dragging { border-color: var(--primary); background: linear-gradient(135deg, #eff6ff 0%, #f0f9ff 100%); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(21,101,192,.1); }
.np-upload-icon { display: block; font-size: 56px; color: #94a3b8; margin: 0 auto 14px; line-height: 1; }
.np-upload-title { font-size: 20px; font-weight: 800; color: #0f172a; margin-bottom: 6px; }
.np-upload-sub { font-size: 14px; color: #64748b; margin-bottom: 18px; }
.np-upload-hint { font-size: 12px; color: #94a3b8; margin-top: 16px; }
.np-upload-zone input[type=file] { display: none; }

/* ═══ KPI Cards ═══ */
.np-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 18px; }
.np-kpi { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 16px 20px; display: flex; align-items: center; gap: 14px; }
.np-kpi-ico { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
.np-kpi-val { font-size: 26px; font-weight: 800; line-height: 1; }
.np-kpi-lbl { font-size: 12px; font-weight: 700; color: #64748b; margin-top: 4px; }
.np-kpi.match .np-kpi-ico { background: #ecfdf5; color: var(--success); }
.np-kpi.match .np-kpi-val { color: var(--success); }
.np-kpi.new .np-kpi-ico { background: #f0fdf4; color: #16a34a; }
.np-kpi.new .np-kpi-val { color: #16a34a; }
.np-kpi.upd .np-kpi-ico { background: #fffbeb; color: var(--warning); }
.np-kpi.upd .np-kpi-val { color: var(--warning); }
.np-kpi.del .np-kpi-ico { background: #fef2f2; color: var(--danger); }
.np-kpi.del .np-kpi-val { color: var(--danger); }
@media (max-width: 920px) { .np-kpis { grid-template-columns: repeat(2, 1fr); } }

/* ═══ Tabs ═══ */
.np-tabs { display: flex; background: #fff; border-radius: 14px 14px 0 0; border: 1.5px solid #e2e8f0; border-bottom: none; overflow: hidden; }
.np-tab { padding: 14px 22px; background: #f8fafc; border: none; border-bottom: 3px solid transparent; font-size: 13.5px; font-weight: 700; color: #64748b; cursor: pointer; transition: all .15s; display: flex; align-items: center; gap: 8px; }
.np-tab:hover { background: #f1f5f9; color: #0f172a; }
.np-tab.active { background: #fff; color: var(--primary); border-bottom-color: var(--primary); }
.np-tab .badge { padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 800; }
.np-tab .badge.match { background: #ecfdf5; color: #166534; }
.np-tab .badge.new { background: #dcfce7; color: #166534; }
.np-tab .badge.upd { background: #fef3c7; color: #92400e; }
.np-tab .badge.del { background: #fee2e2; color: #991b1b; }

/* ═══ Tab body ═══ */
.np-tab-body { background: #fff; border: 1.5px solid #e2e8f0; border-top: none; border-radius: 0 0 14px 14px; padding: 0; max-height: 540px; overflow: auto; }
.np-empty { padding: 60px 20px; text-align: center; color: #94a3b8; }
.np-empty i { font-size: 48px; opacity: .3; display: block; margin-bottom: 12px; }

/* ═══ Tables ═══ */
table.np-t { width: 100%; border-collapse: collapse; font-size: 13px; }
.np-t th { background: #f8fafc; padding: 11px 14px; text-align: right; font-weight: 700; color: #475569; font-size: 11.5px; white-space: nowrap; position: sticky; top: 0; z-index: 1; border-bottom: 1.5px solid #e2e8f0; }
.np-t td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.np-t tr:hover td { background: #f8fafc; }
.np-t .item-no { font-family: 'Inter', monospace; font-weight: 700; color: #1565C0; background: #E3F2FD; padding: 2px 8px; border-radius: 4px; font-size: 12px; display: inline-block; }
.np-t .change { display: flex; align-items: center; gap: 8px; margin: 3px 0; font-size: 12px; }
.np-t .change .fld { min-width: 100px; color: #64748b; font-weight: 700; }
.np-t .change .old { color: #dc2626; text-decoration: line-through; background: #fee2e2; padding: 1px 6px; border-radius: 3px; font-size: 11.5px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.np-t .change .new { color: #15803d; background: #dcfce7; padding: 1px 6px; border-radius: 3px; font-size: 11.5px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.np-t .change .arrow { color: #cbd5e1; }
.np-t .change.sensitive .fld { color: var(--danger); font-weight: 900; }
.np-t .change.sensitive .old, .np-t .change.sensitive .new { background: #fef2f2; border: 1px solid #fca5a5; }
.np-t .asset-warn { color: var(--danger); font-size: 11px; font-weight: 800; background: #fee2e2; padding: 2px 6px; border-radius: 4px; }

/* ═══ حساس/عادي pills ═══ */
.np-pill { display: inline-block; padding: 2px 9px; border-radius: 12px; font-size: 10.5px; font-weight: 800; }
.np-pill.sens { background: #fef2f2; color: var(--danger); border: 1px solid #fca5a5; }

/* ═══ Action bar ═══ */
.np-actions { background: linear-gradient(135deg, #1e293b, #0f172a); color: #fff; border-radius: 14px; padding: 14px 20px; margin: 18px 0; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.np-actions .lbl { font-size: 13px; font-weight: 700; opacity: .85; }
.np-actions .cnt { font-size: 15px; font-weight: 800; color: #fbbf24; margin-inline-start: 4px; }
.np-actions .sel { font-size: 12px; opacity: .75; }
.np-btn { background: #fff; color: #0f172a; border: none; padding: 9px 16px; border-radius: 9px; font-family: 'Tajawal', sans-serif; font-weight: 800; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: .15s; }
.np-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.2); }
.np-btn.danger { background: var(--danger); color: #fff; }
.np-btn.warn { background: var(--warning); color: #fff; }
.np-btn.success { background: var(--success); color: #fff; }
.np-btn.outline { background: transparent; color: #fff; border: 1.5px solid rgba(255,255,255,.3); }
.np-btn.outline:hover { background: rgba(255,255,255,.1); }
.np-btn:disabled { opacity: .4; cursor: not-allowed; transform: none; box-shadow: none; }

/* ═══ Done page ═══ */
.np-done { background: linear-gradient(135deg, #f0fdf4, #fff); border: 1.5px solid #bbf7d0; border-radius: 18px; padding: 40px; text-align: center; max-width: 700px; margin: 0 auto; }
.np-done i.check { font-size: 72px; color: var(--success); margin-bottom: 16px; display: block; }
.np-done h2 { font-size: 22px; font-weight: 800; color: #0f172a; margin: 0 0 8px; }
.np-done p { color: #475569; margin: 0 0 24px; }
.np-done-stats { display: flex; justify-content: center; gap: 32px; margin: 24px 0; padding: 20px; background: #fff; border-radius: 14px; border: 1.5px solid #dcfce7; }
.np-done-stat .v { font-size: 28px; font-weight: 800; }
.np-done-stat .l { font-size: 11.5px; font-weight: 700; color: #64748b; }
.np-done-stat.added .v { color: var(--success); }
.np-done-stat.updated .v { color: var(--warning); }
.np-done-stat.removed .v { color: var(--danger); }

/* ═══ Banner / alert ═══ */
.np-alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 10px; }
.np-alert.error { background: #fee2e2; color: #991b1b; border: 1.5px solid #fecaca; }
.np-alert.warning { background: #fffbeb; color: #92400e; border: 1.5px solid #fde68a; }
.np-alert.success { background: #ecfdf5; color: #166534; border: 1.5px solid #bbf7d0; }

/* ═══ Responsive ═══ */
@media (max-width: 720px) {
    .np-kpis { grid-template-columns: 1fr 1fr; }
    .np-upload-zone { padding: 30px 16px; }
    .np-step .np-lbl { display: none; }
    .np-step-sep { width: 20px; }
}
    @media (max-width: 720px) {
        .np-kpis { grid-template-columns: 1fr 1fr; }
        .np-upload-zone { padding: 30px 16px; }
        .np-step .np-lbl { display: none; }
        .np-step-sep { width: 20px; }
    }

    /* ═══ Top-level tabs (sync | history) ═══ */
    .np-main-tabs { display: flex; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 5px; gap: 4px; margin-bottom: 18px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); width: fit-content; }
    .np-main-tabs li { list-style: none; }
    .np-main-tabs a { display: flex; align-items: center; gap: 7px; padding: 9px 18px; border-radius: 8px; font-size: 13px; font-weight: 700; color: #64748b; text-decoration: none; transition: all .15s; }
    .np-main-tabs a:hover { background: #f1f5f9; color: #0f172a; }
    .np-main-tabs li.active a { background: linear-gradient(135deg, #1565C0, #0d47a1); color: #fff; box-shadow: 0 4px 10px rgba(21,101,192,.25); }
</style>
</head>
<body class="app-layout">
    <?php include BASE_PATH . '/includes/sidebar.php'; ?>
    <div class="main-area" id="mainArea">
        <?php include BASE_PATH . '/includes/topbar.php'; ?>
        <main class="page-content">
            <div class="np-wrap">

                <!-- ═══ Top-level tabs (sync vs history) ═══ -->
                <ul class="np-main-tabs">
                    <li class="<?= $tab==='sync'?'active':'' ?>">
                        <a href="?tab=sync<?= $tab==='sync' && $step!=='upload' ? '&step='.$step.($sync_id?'&id='.$sync_id:'') : '' ?>">
                            <i class="fa-solid fa-arrows-rotate"></i>
                            <?= $rtl?'مزامنة جديدة':'New sync' ?>
                        </a>
                    </li>
                    <li class="<?= $tab==='history'?'active':'' ?>">
                        <a href="?tab=history">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            <?= $rtl?'سجل المزامنات':'History' ?>
                        </a>
                    </li>
                </ul>

    <?php if ($tab === 'sync'): ?>

    <?php foreach ($flash_msgs as $fm): ?>
        <div class="np-alert <?= e($fm['type']) ?>">
            <i class="fa-solid fa-circle-<?= $fm['type']==='success'?'check':'exclamation' ?>"></i>
            <span><?= e($fm['message']) ?></span>
        </div>
    <?php endforeach; ?>
    <?php if ($error): ?>
        <div class="np-alert error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- ═══ Stepper ═══ -->
    <ul class="np-steps">
        <li class="np-step <?= $step==='upload'?'active':'' ?> <?= in_array($step,['preview','done'])?'done':'' ?>">
            <div class="np-num"><?= in_array($step,['preview','done'])?'<i class="fa-solid fa-check"></i>':'1' ?></div>
            <div class="np-lbl"><?= $rtl?'رفع الملف':'Upload' ?></div>
        </li>
        <li class="np-step-sep <?= in_array($step,['preview','done'])?'done':'' ?>"></li>
        <li class="np-step <?= $step==='preview'?'active':'' ?> <?= $step==='done'?'done':'' ?>">
            <div class="np-num"><?= $step==='done'?'<i class="fa-solid fa-check"></i>':'2' ?></div>
            <div class="np-lbl"><?= $rtl?'مراجعة الفروقات':'Review Diff' ?></div>
        </li>
        <li class="np-step-sep <?= $step==='done'?'done':'' ?>"></li>
        <li class="np-step <?= $step==='done'?'active done':'' ?>">
            <div class="np-num">3</div>
            <div class="np-lbl"><?= $rtl?'النتيجة':'Result' ?></div>
        </li>
    </ul>

    <?php if ($step === 'upload'): ?>

    <?php if (!empty($show_confirm) && !empty($_SESSION['nupco_pending_upload'])): $pending = $_SESSION['nupco_pending_upload']; $existing = $pending['existing']; ?>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- ① UPLOAD — CONFIRM DUPLICATE                                    -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="np-alert warning" style="border-right: 6px solid #d97706; background: linear-gradient(135deg, #fffbeb, #fef3c7);">
        <i class="fa-solid fa-triangle-exclamation" style="color:#d97706; font-size:22px"></i>
        <div style="flex:1">
            <div style="font-size:15px; font-weight:800; color:#7c2d12; margin-bottom:6px;">
                <?= $rtl?'⚠️ هذا الملف تم رفعه مسبقاً':'⚠️ This file was already uploaded' ?>
            </div>
            <div style="font-size:12.5px; color:#7c2d12; line-height:1.7">
                <?= $rtl?'الملف الحالي: ':'Current file: ' ?>
                <strong><?= e($pending['file_name']) ?></strong>
                &nbsp;·&nbsp; <?= number_format($pending['file_size']/1024, 1) ?> KB
                &nbsp;·&nbsp; SHA-256: <code style="font-size:10.5px; background:#fef3c7; padding:1px 4px; border-radius:3px"><?= e(substr($pending['hash'], 0, 16)) ?>…</code>
            </div>
            <div style="margin-top:10px; padding:10px 12px; background:rgba(255,255,255,.6); border-radius:8px; font-size:12.5px; color:#7c2d12">
                <strong><?= $rtl?'السجل السابق:':'Previous record:' ?></strong>
                <a href="?tab=history" style="color:#1d4ed8; font-weight:700; text-decoration:underline">#<?= (int)$existing['id'] ?></a>
                &nbsp;·&nbsp; <?= e(date('Y-m-d H:i', strtotime($existing['sync_date']))) ?>
                &nbsp;·&nbsp; <span style="padding:2px 8px; background:<?= $existing['status']==='applied'?'#dcfce7':'#fef3c7' ?>; border-radius:10px; font-weight:700"><?= e($existing['status']) ?></span>
                <?php if (!empty($existing['new_count']) || !empty($existing['updated_count'])): ?>
                &nbsp;·&nbsp; <?= $rtl?'+'.(int)$existing['new_count'].' جديد, ~'.(int)$existing['updated_count'].' محدّث': '+'.(int)$existing['new_count'].' new, ~'.(int)$existing['updated_count'].' updated' ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; padding:22px; margin-top:14px">
        <div style="font-size:13.5px; color:#475569; line-height:1.7; margin-bottom:16px">
            <strong style="color:#0f172a"><?= $rtl?'ماذا تريد أن تفعل؟':'What do you want to do?' ?></strong>
            <ul style="margin:8px 0 0 0; padding-inline-start:22px">
                <li><strong style="color:#16a34a"><?= $rtl?'متابعة بالرفع الجديد':'Proceed with new upload' ?>:</strong> <?= $rtl?'سيتم إنشاء سجل مزامنة جديد (#'.($existing['id']+1).' تقريباً) وسيُحلّل الملف من جديد. إذا كان المحتوى نفسه، ستظهر 0 فروقات.':'A new sync record will be created and the file will be re-parsed. If the content is identical, you will see 0 differences.' ?></li>
                <li><strong style="color:#1d4ed8"><?= $rtl?'الذهاب للسجل':'Go to history' ?>:</strong> <?= $rtl?'يمكنك عرض نتيجة المزامنة السابقة في تبويب "السجل" ومراجعتها':'You can view the previous sync result in the History tab and review it' ?></li>
                <li><strong style="color:#dc2626"><?= $rtl?'إلغاء':'Cancel' ?>:</strong> <?= $rtl?'العودة لشاشة الرفع بدون أي تغيير':'Return to upload form without any changes' ?></li>
            </ul>
        </div>

        <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap">
            <form method="POST" style="display:inline">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="cancel_upload">
                <button type="submit" class="np-btn outline" style="background:transparent; color:#dc2626; border:1.5px solid #fca5a5">
                    <i class="fa-solid fa-xmark"></i> <?= $rtl?'إلغاء':'Cancel' ?>
                </button>
            </form>
            <a href="?tab=history" class="np-btn outline" style="background:transparent; color:#1d4ed8; border:1.5px solid #bfdbfe">
                <i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'عرض السجل السابق':'View previous' ?>
            </a>
            <form method="POST" style="display:inline">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="confirm_upload">
                <input type="hidden" name="force" value="1">
                <button type="submit" class="np-btn success">
                    <i class="fa-solid fa-check"></i>
                    <?= $rtl?'نعم، متابعة بالرفع الجديد':'Yes, proceed with new upload' ?>
                </button>
            </form>
        </div>
    </div>

    <?php else: // Normal upload form ?>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- ① UPLOAD                                                       -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="upload">

        <div class="np-alert" style="background:linear-gradient(135deg,#ecfeff,#fff);border:1.5px solid #67e8f9;color:#0e7490;">
            <i class="fa-solid fa-circle-info" style="color:#0891b2;font-size:18px"></i>
            <span style="flex:1">
                <strong style="display:block;margin-bottom:4px"><?= $rtl?'مصدر واحد — طبي وغير طبي':'Single source — medical & non-medical' ?></strong>
                <span style="font-size:12.5px">
                    <?= $rtl
                        ? 'هذه المزامنة تُدخل الأصناف الطبية فقط (من ملف NUPCO الرسمي). للأصناف غير الطبية (IT/HVAC/ELEC)، استخدم "منضدة التصنيف الجماعي" في تبويب الأصول → "إضافة كغير طبي".'
                        : 'This sync ingests medical items only (from official NUPCO file). For non-medical items (IT/HVAC/ELEC), use the "Bulk Classification Workbench" → "Add as non-medical" tab.' ?>
                </span>
            </span>
        </div>

        <label for="xlsxFile" class="np-upload-zone" id="uploadZone">
            <i class="fa-solid fa-cloud-arrow-up np-upload-icon"></i>
            <div class="np-upload-title"><?= $rtl?'اسحب وأفلت ملف NUPCO هنا أو اضغط للاختيار':'Drag & drop NUPCO file here, or click to browse' ?></div>
            <div class="np-upload-sub"><?= $rtl?'يجب أن يكون الملف بصيغة .xlsx (Excel 2007+)':'File must be .xlsx format (Excel 2007+)' ?></div>
            <div class="np-btn" style="display:inline-flex;pointer-events:none"><i class="fa-solid fa-folder-open"></i> <?= $rtl?'اختر ملف':'Browse file' ?></div>
            <input type="file" name="xlsx" id="xlsxFile" accept=".xlsx" required>
            <div class="np-upload-hint" id="fileNameHint"><?= $rtl?'الحد الأقصى: 20 ميجابايت':'Max: 20 MB' ?></div>
        </label>

        <div class="np-alert warning" style="margin-top:0">
            <i class="fa-solid fa-circle-info"></i>
            <span>
                <?= $rtl?'قبل المتابعة، تأكد من:' : 'Before proceeding, ensure:' ?>
                <ul style="margin: 6px 0 0 0; padding-inline-start: 22px; font-size: 12.5px; font-weight: 600;">
                    <li><?= $rtl?'الملف مأخوذ من المصدر الرسمي لـ NUPCO':'File is from official NUPCO source' ?></li>
                    <li><?= $rtl?'الورقة الأولى فقط سيتم قراءتها (Sheet 1)':'Only the first sheet will be read' ?></li>
                    <li><?= $rtl?'سيتم مقارنة 5 حقول فقط: item_no, generic_code, description_en, category, sub_category':'5 fields will be compared: item_no, generic_code, description_en, category, sub_category' ?></li>
                    <li><?= $rtl?'الفروقات في المسافات فقط (trailing/leading) لن تُعتبر تحديثاً':'Whitespace-only differences will NOT be treated as updates' ?></li>
                </ul>
            </span>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px">
            <a href="?tab=history" class="np-btn outline" style="background:#f1f5f9;color:#475569;border-color:#e2e8f0"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'سجل المزامنات':'Sync History' ?></a>
            <button type="submit" class="np-btn success" id="submitBtn"><i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>"></i> <?= $rtl?'رفع ومتابعة':'Upload & Continue' ?></button>
        </div>
    </form>

    <script>
    (function(){
        const zone = document.getElementById('uploadZone');
        const input = document.getElementById('xlsxFile');
        const hint = document.getElementById('fileNameHint');
        const form = document.getElementById('uploadForm');
        const submitBtn = document.getElementById('submitBtn');
        if (!zone) return;
        ['dragenter','dragover'].forEach(e => zone.addEventListener(e, function(ev){ ev.preventDefault(); zone.classList.add('dragging'); }));
        ['dragleave','drop'].forEach(e => zone.addEventListener(e, function(ev){ ev.preventDefault(); zone.classList.remove('dragging'); }));
        zone.addEventListener('drop', function(ev){ if (ev.dataTransfer.files.length) { input.files = ev.dataTransfer.files; updateHint(); } });
        input.addEventListener('change', updateHint);
        function updateHint(){
            if (input.files.length) {
                const f = input.files[0];
                const sizeMB = (f.size / 1024 / 1024).toFixed(2);
                hint.innerHTML = '<i class="fa-solid fa-file-excel" style="color:#16a34a"></i> <strong>' + f.name + '</strong> (' + sizeMB + ' MB)';
                hint.style.color = '#16a34a';
            }
        }
        form.addEventListener('submit', function(){
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> <?= $rtl?'جاري المعالجة...':'Processing...' ?>';
        });
    })();
    </script>

    <?php endif; // end confirm/normal upload ?>

    <?php elseif ($step === 'preview' && $diff): ?>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- ② PREVIEW                                                      -->
    <!-- ═══════════════════════════════════════════════════════════════ -->

    <!-- بطاقة معلومات الملف -->
    <div class="np-alert" style="background:#eff6ff;color:#1e3a8a;border:1.5px solid #bfdbfe">
        <i class="fa-solid fa-file-excel" style="color:#1565C0;font-size:18px"></i>
        <span style="flex:1">
            <strong><?= e($sync_log['file_name']) ?></strong>
            &nbsp;·&nbsp; <?= number_format($sync_log['file_size']/1024, 1) ?> KB
            &nbsp;·&nbsp; <?= $rtl?'الورقة: ':'Sheet: ' ?><?= e($sync_log['sheet_name']) ?>
            &nbsp;·&nbsp; <?= number_format($sync_log['rows_in_file']) ?> <?= $rtl?'صف':'rows' ?>
        </span>
        <span style="font-size:11px;color:#64748b">#<?= $sync_id ?> · <?= e(date('Y-m-d H:i', strtotime($sync_log['sync_date']))) ?></span>
    </div>

    <?php if (!empty($duplicates)): ?>
    <div class="np-alert error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span>
            <strong><?= $rtl?'⚠️ الملف يحتوي على أصناف مكررة — يجب إصلاحه عند المرسل قبل المتابعة':'File contains duplicate item_nos — fix at source before proceeding' ?></strong>
            <ul style="margin:6px 0 0 0;padding-inline-start:22px;font-size:12px">
                <?php foreach (array_slice($duplicates, 0, 5) as $d): ?>
                    <li>🔴 <code><?= e($d['item_no']) ?></code> — <?= $rtl?'صف':'rows' ?> <?= $d['first_row'] ?> & <?= $d['second_row'] ?></li>
                <?php endforeach; ?>
                <?php if (count($duplicates) > 5): ?><li>... <?= $rtl? 'و' . (count($duplicates)-5) . ' آخر': 'and ' . (count($duplicates)-5) . ' more' ?></li><?php endif; ?>
            </ul>
        </span>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="np-kpis">
        <div class="np-kpi match">
            <div class="np-kpi-ico"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="np-kpi-val"><?= number_format($diff['matched']) ?></div>
                <div class="np-kpi-lbl"><?= $rtl?'متطابق تماماً (بدون فروقات)':'Fully matched' ?></div>
            </div>
        </div>
        <div class="np-kpi new">
            <div class="np-kpi-ico"><i class="fa-solid fa-plus-circle"></i></div>
            <div>
                <div class="np-kpi-val"><?= number_format(count($diff['new'])) ?></div>
                <div class="np-kpi-lbl"><?= $rtl?'جديد للإضافة':'New to insert' ?></div>
            </div>
        </div>
        <div class="np-kpi upd">
            <div class="np-kpi-ico"><i class="fa-solid fa-pen-to-square"></i></div>
            <div>
                <div class="np-kpi-val"><?= number_format(count($diff['updated'])) ?></div>
                <div class="np-kpi-lbl"><?= $rtl?'محدّث للتطبيق':'To update' ?>
                    <?php if ($diff['sensitive_count'] > 0): ?>
                        <span class="np-pill sens" style="margin-inline-start:6px"><i class="fa-solid fa-triangle-exclamation"></i> <?= $diff['sensitive_count'] ?> <?= $rtl?'حساس':'sensitive' ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="np-kpi del">
            <div class="np-kpi-ico"><i class="fa-solid fa-ban"></i></div>
            <div>
                <div class="np-kpi-val"><?= number_format(count($diff['removed'])) ?></div>
                <div class="np-kpi-lbl"><?= $rtl?'غير متوفر في الملف':'Not in new file' ?></div>
            </div>
        </div>
    </div>

    <!-- Form التطبيق -->
    <form method="POST" id="applyForm">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="apply">
        <input type="hidden" name="sync_id" value="<?= $sync_id ?>">

        <!-- Tabs -->
        <div class="np-tabs">
            <button type="button" class="np-tab active" data-tab="new">
                <i class="fa-solid fa-plus" style="color:#16a34a"></i>
                <?= $rtl?'جديد':'New' ?>
                <span class="badge new"><?= count($diff['new']) ?></span>
            </button>
            <button type="button" class="np-tab" data-tab="updated">
                <i class="fa-solid fa-pen" style="color:#d97706"></i>
                <?= $rtl?'محدّث':'Updated' ?>
                <span class="badge upd"><?= count($diff['updated']) ?></span>
                <?php if ($diff['sensitive_count'] > 0): ?>
                    <span class="np-pill sens">🔴 <?= $diff['sensitive_count'] ?> <?= $rtl?'حساس':'sensitive' ?></span>
                <?php endif; ?>
            </button>
            <button type="button" class="np-tab" data-tab="removed">
                <i class="fa-solid fa-ban" style="color:#dc2626"></i>
                <?= $rtl?'غير متوفر':'Not in file' ?>
                <span class="badge del"><?= count($diff['removed']) ?></span>
            </button>
        </div>

        <div class="np-tab-body" id="tab-new">
            <?php if (empty($diff['new'])): ?>
                <div class="np-empty"><i class="fa-solid fa-circle-check"></i><h3><?= $rtl?'لا توجد أصناف جديدة':'No new items' ?></h3><p><?= $rtl?'كل الأصناف في الملف موجودة مسبقاً في النظام':'All items already exist in the system' ?></p></div>
            <?php else: ?>
            <table class="np-t">
                <thead><tr>
                    <th style="width:36px"><input type="checkbox" id="selAllNew" checked></th>
                    <th><?= $rtl?'رقم الصنف':'Item No' ?></th>
                    <th><?= $rtl?'Generic Code':'Generic Code' ?></th>
                    <th><?= $rtl?'الوصف':'Description' ?></th>
                    <th><?= $rtl?'الفئة / الفرعية':'Category / Sub' ?></th>
                </tr></thead>
                <tbody>
                <?php foreach (array_slice($diff['new'], 0, 500) as $itemNo): $xl = $excel_data[$itemNo]; ?>
                    <tr>
                        <td><input type="checkbox" name="new[]" value="<?= e($itemNo) ?>" class="chk-new" checked></td>
                        <td><span class="item-no"><?= e($itemNo) ?></span></td>
                        <td style="font-family:monospace;font-size:12px;color:#475569"><?= e($xl['generic_code'] ?: '—') ?></td>
                        <td><?= e(mb_strimwidth($xl['description_en'], 0, 60, '…')) ?></td>
                        <td style="font-size:12px;color:#475569"><?= e($xl['category']) ?> / <?= e($xl['sub_category']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($diff['new']) > 500): ?>
                <div style="padding:10px 14px;font-size:11.5px;color:#64748b;text-align:center;background:#f8fafc"><?= $rtl?'عرض أول 500 من '.count($diff['new']).' — المحدد منها 500 فقط':'Showing first 500 of '.count($diff['new']).' — only 500 will be applied' ?></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="np-tab-body" id="tab-updated" style="display:none">
            <?php if (empty($diff['updated'])): ?>
                <div class="np-empty"><i class="fa-solid fa-circle-check"></i><h3><?= $rtl?'لا توجد تحديثات':'No updates' ?></h3><p><?= $rtl?'كل السجلات متطابقة تماماً (مع تجاهل الفروقات في المسافات فقط)':'All records fully match (whitespace differences ignored)' ?></p></div>
            <?php else: ?>
            <table class="np-t">
                <thead><tr>
                    <th style="width:36px"><input type="checkbox" id="selAllUpd" checked></th>
                    <th><?= $rtl?'رقم الصنف':'Item No' ?></th>
                    <th><?= $rtl?'Generic Code':'Generic Code' ?></th>
                    <th><?= $rtl?'الوصف الحالي / الجديد':'Current / New description' ?></th>
                    <th><?= $rtl?'التغييرات':'Changes' ?></th>
                </tr></thead>
                <tbody>
                <?php $i=0; foreach ($diff['updated'] as $itemNo => $changes): $i++; $xl = $excel_data[$itemNo]; $hasSensitive = false; foreach ($changes as $c) { if ($c['sensitive']) $hasSensitive = true; } ?>
                    <tr style="<?= $hasSensitive?'background:#fffbfb':'' ?>">
                        <td><input type="checkbox" name="updated[]" value="<?= e($itemNo) ?>" class="chk-upd" checked></td>
                        <td>
                            <span class="item-no"><?= e($itemNo) ?></span>
                            <?php if ($hasSensitive): ?><div style="margin-top:3px"><span class="np-pill sens">🔴 <?= $rtl?'حساس':'sensitive' ?></span></div><?php endif; ?>
                        </td>
                        <td style="font-family:monospace;font-size:12px">
                            <?php if (isset($changes['generic_code'])): ?>
                                <span style="color:#dc2626;text-decoration:line-through"><?= e(mb_strimwidth($changes['generic_code']['old'],0,18,'…')) ?></span>
                                <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?>" style="color:#cbd5e1;font-size:10px;margin:0 4px"></i>
                                <span style="color:#15803d"><?= e(mb_strimwidth($changes['generic_code']['new'],0,18,'…')) ?></span>
                            <?php else: ?>
                                <span style="color:#475569"><?= e(mb_strimwidth($xl['generic_code'] ?: '—', 0, 18, '…')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:600;color:#0f172a"><?= e(mb_strimwidth($xl['description_en'],0,40,'…')) ?></div>
                        </td>
                        <td>
                            <?php foreach ($changes as $f => $v): ?>
                                <div class="change <?= $v['sensitive']?'sensitive':'' ?>">
                                    <span class="fld"><?= e($f) ?><?= $v['sensitive']?' 🔴':'' ?></span>
                                    <span class="old" title="<?= e($v['old']) ?>"><?= e(mb_strimwidth($v['old'] ?: '—', 0, 25, '…')) ?></span>
                                    <i class="fa-solid fa-arrow-<?= $rtl?'left':'right' ?> arrow"></i>
                                    <span class="new" title="<?= e($v['new']) ?>"><?= e(mb_strimwidth($v['new'] ?: '—', 0, 25, '…')) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="np-tab-body" id="tab-removed" style="display:none">
            <?php if (empty($diff['removed'])): ?>
                <div class="np-empty"><i class="fa-solid fa-circle-check"></i><h3><?= $rtl?'لا توجد أصناف محذوفة':'No removed items' ?></h3><p><?= $rtl?'كل الأصناف في DB موجودة في الملف الجديد':'All DB items are in the new file' ?></p></div>
            <?php else: ?>
            <table class="np-t">
                <thead><tr>
                    <th><?= $rtl?'رقم الصنف':'Item No' ?></th>
                    <th><?= $rtl?'الوصف (آخر قيمة في DB)':'Description (last in DB)' ?></th>
                    <th><?= $rtl?'الفئة':'Category' ?></th>
                    <th><?= $rtl?'الفئة الفرعية':'Sub Category' ?></th>
                    <th><?= $rtl?'مستخدم في أصول؟':'Used by assets?' ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($diff['removed'] as $itemNo): $db = $db_data[$itemNo] ?? []; $cnt = $has_assets_in_removed[$itemNo] ?? 0; ?>
                    <tr>
                        <td><span class="item-no"><?= e($itemNo) ?></span></td>
                        <td style="font-size:12px"><?= e(mb_strimwidth($db['description_en'] ?? '', 0, 50, '…')) ?></td>
                        <td style="font-size:12px;color:#475569"><?= e($db['category'] ?? '') ?></td>
                        <td style="font-size:12px;color:#475569"><?= e($db['sub_category'] ?? '') ?></td>
                        <td>
                            <?php if ($cnt > 0): ?>
                                <span class="asset-warn"><i class="fa-solid fa-triangle-exclamation"></i> <?= $rtl?'نعم':'Yes' ?> (<?= $cnt ?>)</span>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px"><?= $rtl?'لا':'No' ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="padding:10px 14px;font-size:11.5px;color:#92400e;background:#fffbeb;text-align:center">
                <i class="fa-solid fa-circle-info"></i>
                <?= $rtl?'هذه الأصناف لن تُحذف من DB — ستبقى مرجعاً للأصول. ستُسجَّل في السجل فقط للمراجعة لاحقاً.':'These items will NOT be deleted from DB — they remain as a reference for assets. They will only be logged for later review.' ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Action bar -->
        <div class="np-actions">
            <span class="lbl"><?= $rtl?'جاهز للتطبيق:':'Ready to apply:' ?></span>
            <span><i class="fa-solid fa-plus" style="color:#16a34a"></i> <span class="cnt" id="cntNew"><?= count($diff['new']) ?></span> <?= $rtl?'جديد':'new' ?></span>
            <span class="sel">·</span>
            <span><i class="fa-solid fa-pen" style="color:#fbbf24"></i> <span class="cnt" id="cntUpd"><?= count($diff['updated']) ?></span> <?= $rtl?'محدّث':'updated' ?></span>

            <?php if ($diff['sensitive_count'] > 0): ?>
                <span class="sel">·</span>
                <span style="color:#fca5a5"><i class="fa-solid fa-triangle-exclamation"></i> <span class="cnt" id="cntSens"><?= $diff['sensitive_count'] ?></span> <?= $rtl?'تعديلات في حقول حساسة (تحتاج تأكيد مزدوج)':'changes in sensitive fields (need double confirmation' ?></span>
            <?php endif; ?>

            <div style="flex:1"></div>
            <a href="?step=upload" class="np-btn outline"><i class="fa-solid fa-<?= $rtl?'arrow-right':'arrow-left' ?>"></i> <?= $rtl?'إلغاء':'Cancel' ?></a>
            <?php if ($can_apply): ?>
                <button type="submit" class="np-btn success" id="applyBtn">
                    <i class="fa-solid fa-check"></i>
                    <?= $rtl?'تطبيق المحدد':'Apply Selected' ?>
                </button>
            <?php else: ?>
                <button type="button" class="np-btn" disabled title="<?= $rtl?'لا تملك صلاحية التطبيق':'No apply permission' ?>">
                    <i class="fa-solid fa-lock"></i> <?= $rtl?'لا تملك صلاحية التطبيق':'No apply permission' ?>
                </button>
            <?php endif; ?>
        </div>
    </form>

    <?php
    // Pre-compute JS messages to avoid PHP/JS string-nesting issues
    $js_msg_sensitive = $rtl
        ? '⚠️ يحتوي التطبيق على '
        : '⚠️ Apply contains ';
    $js_msg_sensitive_suffix = $rtl
        ? ' تعديل في حقول حساسة (generic_code). متأكد؟'
        : ' change(s) in sensitive fields (generic_code). Confirm?';
    $js_msg_normal = $rtl
        ? 'تأكيد تطبيق التغييرات على الكتالوج؟'
        : 'Confirm applying changes to the catalog?';
    $js_msg_final = $rtl
        ? 'تأكيد نهائي — هذا الإجراء نهائي ولا يمكن التراجع عنه بسهولة'
        : 'Final confirmation — this is permanent and cannot be easily undone';
    $js_msg_applying = $rtl ? 'جاري التطبيق...' : 'Applying...';
    ?>
    <script>
    (function(){
        // Tabs
        document.querySelectorAll('.np-tab').forEach(function(tab){
            tab.addEventListener('click', function(){
                document.querySelectorAll('.np-tab').forEach(function(t){ t.classList.remove('active'); });
                document.querySelectorAll('.np-tab-body').forEach(function(b){ b.style.display = 'none'; });
                tab.classList.add('active');
                document.getElementById('tab-' + tab.dataset.tab).style.display = 'block';
            });
        });
        // Select-all
        function bindSelAll(masterId, slaveClass){
            var m = document.getElementById(masterId);
            if (!m) return;
            m.addEventListener('change', function(){
                var chks = document.querySelectorAll('.' + slaveClass);
                for (var i = 0; i < chks.length; i++) chks[i].checked = m.checked;
                updateCount();
            });
        }
        bindSelAll('selAllNew', 'chk-new');
        bindSelAll('selAllUpd', 'chk-upd');
        var chks = document.querySelectorAll('.chk-new, .chk-upd');
        for (var i = 0; i < chks.length; i++) chks[i].addEventListener('change', updateCount);
        function updateCount(){
            var n = document.querySelectorAll('.chk-new:checked').length;
            var u = document.querySelectorAll('.chk-upd:checked').length;
            var cn = document.getElementById('cntNew');
            var cu = document.getElementById('cntUpd');
            if (cn) cn.textContent = n;
            if (cu) cu.textContent = u;
        }
        // Confirm قبل التطبيق لو في حساس
        var form = document.getElementById('applyForm');
        var MSG_SENS_PRE = <?= json_encode($js_msg_sensitive, JSON_UNESCAPED_UNICODE) ?>;
        var MSG_SENS_SUF = <?= json_encode($js_msg_sensitive_suffix, JSON_UNESCAPED_UNICODE) ?>;
        var MSG_NORMAL = <?= json_encode($js_msg_normal, JSON_UNESCAPED_UNICODE) ?>;
        var MSG_FINAL = <?= json_encode($js_msg_final, JSON_UNESCAPED_UNICODE) ?>;
        var MSG_APPLYING = <?= json_encode($js_msg_applying, JSON_UNESCAPED_UNICODE) ?>;
        if (form) {
            form.addEventListener('submit', function(e){
                var sens = <?= (int)($diff['sensitive_count'] ?? 0) ?>;
                var msg = sens > 0
                    ? MSG_SENS_PRE + sens + MSG_SENS_SUF
                    : MSG_NORMAL;
                if (!confirm(msg)) { e.preventDefault(); return; }
                if (sens > 0 && !confirm(MSG_FINAL)) { e.preventDefault(); return; }
                var btn = document.getElementById('applyBtn');
                if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> ' + MSG_APPLYING; }
            });
        }
    })();
    </script>

    <?php elseif ($step === 'done' && $sync_log): ?>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- ④ DONE                                                          -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="np-done">
        <i class="fa-solid fa-circle-check check"></i>
        <h2><?= $rtl?'تمت المزامنة بنجاح!':'Sync completed successfully!' ?></h2>
        <p>
            <?= $rtl?'تم تطبيق التغييرات على كتالوج NUPCO بتاريخ':'Catalog was updated on' ?>
            <strong><?= e(date('Y-m-d H:i', strtotime($sync_log['applied_at'] ?? $sync_log['sync_date']))) ?></strong>
        </p>

        <div class="np-done-stats">
            <?php if ($sync_log['new_count'] > 0): ?>
            <div class="np-done-stat added">
                <div class="v">+<?= number_format($sync_log['new_count']) ?></div>
                <div class="l"><?= $rtl?'صنف جديد':'New' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sync_log['updated_count'] > 0): ?>
            <div class="np-done-stat updated">
                <div class="v">~<?= number_format($sync_log['updated_count']) ?></div>
                <div class="l"><?= $rtl?'محدّث':'Updated' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sync_log['not_in_excel_count'] > 0): ?>
            <div class="np-done-stat removed">
                <div class="v"><?= number_format($sync_log['not_in_excel_count']) ?></div>
                <div class="l"><?= $rtl?'غير متوفر':'Not in file' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($sync_log['error_count'] > 0): ?>
            <div class="np-done-stat" style="color:var(--danger)">
                <div class="v" style="color:var(--danger)"><?= number_format($sync_log['error_count']) ?></div>
                <div class="l"><?= $rtl?'أخطاء':'Errors' ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div style="display:flex;justify-content:center;gap:10px;margin-top:20px">
            <a href="?tab=sync" class="np-btn" style="background:#1565C0;color:#fff"><i class="fa-solid fa-upload"></i> <?= $rtl?'رفع ملف آخر':'Upload another' ?></a>
            <a href="?tab=history" class="np-btn outline" style="background:transparent;color:#475569;border:1.5px solid #cbd5e1"><i class="fa-solid fa-clock-rotate-left"></i> <?= $rtl?'عرض السجل':'View history' ?></a>
        </div>
    </div>

    <?php else: ?>

    <div class="np-alert error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?= $rtl?'حالة غير معروفة — أعد المحاولة':'Unknown state — try again' ?></span>
    </div>

    <?php endif; ?>

    <?php elseif ($tab === 'history'): ?>

    <?php include __DIR__ . '/_history_view.php'; ?>

    <?php endif; ?>

            </div>
        </main>
    </div>
</body>
</html>
