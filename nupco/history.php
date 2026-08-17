<?php
/**
 * nupco/history.php — Redirect إلى الصفحة الموحدة
 * ─────────────────────────────────────────────────────────
 *   تم دمج شاشة السجل في nupco/sync.php تحت التبويب "السجل".
 *   هذا الملف يحوّل (redirect) للحفاظ على الروابط القديمة.
 *
 *   في الإنتاج: يستبدل بـ 301 redirect من .htaccess
 */
require_once dirname(__DIR__) . '/config.php';
$url = BASE_URL . '/nupco/sync.php?tab=history';
header('Location: ' . $url, true, 302);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>جاري التحويل...</title>
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($url, ENT_QUOTES) ?>">
</head>
<body style="font-family: 'Tajawal', sans-serif; padding: 40px; text-align: center; color: #475569">
    <p>جاري التحويل إلى <a href="<?= htmlspecialchars($url, ENT_QUOTES) ?>" style="color:#1565C0">سجل مزامنات NUPCO</a>...</p>
</body>
</html>
