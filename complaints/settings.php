<?php
/**
 * complaints/settings.php — إعدادات البلاغات والتصعيد (مفتاح التفعيل + ساعات المهلة)
 * صفحة مستقلة خاصة بهذا الموديول فقط — لا تتعارض مع settings/index.php الرئيسية.
 */
require_once dirname(__DIR__) . '/config.php';

if (!can_see_all()) {
    die('<h3 style="text-align:center;padding:50px;font-family:Tajawal,sans-serif">هذه الصفحة محصورة بالإدارة العليا فقط.</h3>');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = 'خطأ في الجلسة (CSRF).';
    } else {
        $enabled = isset($_POST['auto_escalation_enabled']) ? '1' : '0';
        $hNormal = max(1, (int) ($_POST['hours_normal'] ?? 4));
        $hUrgent = max(1, (int) ($_POST['hours_urgent'] ?? 2));
        $hCritical = max(1, (int) ($_POST['hours_critical'] ?? 1));

        // set_setting = upsert: تُنشئ المفاتيح إن غابت بدل تجاهلها بصمت
        set_setting('auto_escalation_enabled', $enabled);
        set_setting('escalation_hours_normal', (string)$hNormal);
        set_setting('escalation_hours_urgent', (string)$hUrgent);
        set_setting('escalation_hours_critical', (string)$hCritical);
        set_setting('complaint_ai_suggestions',
            isset($_POST['complaint_ai_suggestions']) ? '1' : '0');
        set_setting('complaint_ai_dup_check',
            isset($_POST['complaint_ai_dup_check']) ? '1' : '0');

        flash('success', 'تم حفظ إعدادات التصعيد بنجاح.');
        header('Location: ' . BASE_URL . '/complaints/settings.php');
        exit;
    }
}

$rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('auto_escalation_enabled','escalation_hours_normal','escalation_hours_urgent','escalation_hours_critical','complaint_ai_suggestions','complaint_ai_dup_check')")->fetchAll(PDO::FETCH_KEY_PAIR);
$aiSugg = ($rows['complaint_ai_suggestions'] ?? '1') === '1';
$aiDup  = ($rows['complaint_ai_dup_check'] ?? '1') === '1';
$enabled = ($rows['auto_escalation_enabled'] ?? '1') === '1';
$hNormal = (int) ($rows['escalation_hours_normal'] ?? 4);
$hUrgent = (int) ($rows['escalation_hours_urgent'] ?? 2);
$hCritical = (int) ($rows['escalation_hours_critical'] ?? 1);

$page_title = 'إعدادات البلاغات والتصعيد';
$active_nav = 'complaints.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root { --bg:#f1f5f9; --card:#fff; --text:#0f172a; --muted:#64748b; --border:#e2e8f0; --primary:#2563eb; }
body { background: var(--bg); font-family:'Tajawal',sans-serif; }
.eng { font-family:'Inter',sans-serif; }
.wrap { max-width: 760px; margin: 0 auto; padding: 22px; }
.h-banner { background:linear-gradient(135deg,#0f172a,#1e293b); border-radius:22px; padding:22px 28px; color:#fff; margin-bottom:20px; }
.h-banner h1 { font-size:19px; font-weight:900; margin:0 0 6px; display:flex; align-items:center; gap:10px; }
.h-banner p { font-size:12.5px; color:#cbd5e1; margin:0; }
.bento { background:var(--card); border-radius:20px; box-shadow:0 4px 18px rgba(0,0,0,.04); border:1px solid var(--border); padding:24px; margin-bottom:18px; }
.bento-h { font-size:14.5px; font-weight:900; margin:0 0 18px; display:flex; align-items:center; gap:9px; }
.bento-h i { color:var(--primary) }

.toggle-row { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; background:#f8fafc; border-radius:14px; margin-bottom:8px; }
.toggle-row h4 { margin:0 0 4px; font-size:13.5px; font-weight:900; color:var(--text); }
.toggle-row p { margin:0; font-size:11.5px; color:var(--muted); font-weight:600; line-height:1.6; max-width:420px; }
.switch { position:relative; width:50px; height:28px; flex-shrink:0; }
.switch input { opacity:0; width:0; height:0; }
.slider { position:absolute; cursor:pointer; inset:0; background:#cbd5e1; border-radius:99px; transition:.3s; }
.slider:before { content:''; position:absolute; height:22px; width:22px; right:3px; bottom:3px; background:#fff; border-radius:50%; transition:.3s; }
input:checked + .slider { background:#16a34a; }
input:checked + .slider:before { transform:translateX(-22px); }

.hours-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; margin-top:8px; }
.hour-box { background:#f8fafc; border:1px solid var(--border); border-radius:14px; padding:16px; text-align:center; }
.hour-box label { display:block; font-size:12px; font-weight:900; color:var(--muted); margin-bottom:10px; }
.hour-box input { width:70px; text-align:center; border:2px solid var(--border); border-radius:10px; padding:8px; font-size:18px; font-weight:900; font-family:'Inter'; color:var(--text); }
.hour-box.critical { border-color:#fecaca; background:#fef2f2; }
.hour-box.urgent { border-color:#fed7aa; background:#fffbeb; }
.hour-box.normal { border-color:#bbf7d0; background:#f0fdf4; }

.note { background:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:14px 16px; font-size:12px; color:#1e40af; font-weight:700; line-height:1.7; margin-top:16px; }
.btn-save { width:100%; background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff; border:none; padding:14px; border-radius:13px; font-size:14px; font-weight:900; cursor:pointer; margin-top:8px; }
.flash-msg { background:#fff; border-radius:12px; padding:13px 18px; margin-bottom:16px; font-weight:800; font-size:13px; }
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<?php foreach ($flash_msgs as $fm): $fc=['success'=>'#10b981','warning'=>'#f59e0b','info'=>'#3b82f6','danger'=>'#ef4444'][$fm['type']]??'#3b82f6'; ?>
<div class="flash-msg" style="border:1px solid <?= $fc ?>55;border-right:4px solid <?= $fc ?>;color:#0f172a"><?= e($fm['message']) ?></div>
<?php endforeach; ?>
<?php if ($errors): foreach ($errors as $er): ?>
<div class="flash-msg" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca"><i class="fa-solid fa-circle-exclamation"></i> <?= e($er) ?></div>
<?php endforeach; endif; ?>

<div class="h-banner">
    <h1><i class="fa-solid fa-sliders" style="color:#fbbf24"></i> إعدادات البلاغات والتصعيد</h1>
    <p>التحكم الكامل في آلية التصعيد التلقائي — التفعيل، والمهل الزمنية لكل أولوية.</p>
</div>

<form method="POST">
    <?= csrf_input() ?>

    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-power-off"></i> التصعيد التلقائي الفعلي</div>
        <div class="toggle-row">
            <div>
                <h4>تفعيل التصعيد التلقائي</h4>
                <p>عند التعطيل: لا يتغيّر أي بلاغ لحالة "مُصعَّد"، ولا تصل أي تنبيهات للجنة المتابعة. <b>رصد تجاوز المهلة نفسه يبقى يعمل دائماً</b> للتقارير، بصرف النظر عن هذا المفتاح.</p>
            </div>
            <label class="switch">
                <input type="checkbox" name="auto_escalation_enabled" <?= $enabled ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-clock"></i> ساعات المهلة قبل التصعيد</div>
        <div class="hours-grid">
            <div class="hour-box normal">
                <label>عادي</label>
                <input type="number" name="hours_normal" value="<?= $hNormal ?>" min="1" max="72">
            </div>
            <div class="hour-box urgent">
                <label>عاجل</label>
                <input type="number" name="hours_urgent" value="<?= $hUrgent ?>" min="1" max="72">
            </div>
            <div class="hour-box critical">
                <label>طارئ</label>
                <input type="number" name="hours_critical" value="<?= $hCritical ?>" min="1" max="72">
            </div>
        </div>
        <div class="note">
            <i class="fa-solid fa-circle-info"></i> هذه القيم تُطبَّق فقط على البلاغات الجديدة من لحظة الحفظ — البلاغات الموجودة فعلاً تحتفظ بمهلتها المحسوبة وقت إصدارها.
        </div>
    </div>

    <div class="bento">
        <div class="bento-h"><i class="fa-solid fa-robot"></i> مزايا الذكاء الاصطناعي (Groq)</div>
        <div class="toggle-row">
            <div>
                <h4>اقتراحات الأعطال الذكية</h4>
                <p>عند اختيار الجهاز في رفع البلاغ، يقترح النظام الأعطال الشائعة لهذا النوع من واقع السجل ومن تقارير FDA. تعطيله يوقف الاقتراحات فقط — رفع البلاغ يعمل طبيعياً.</p>
            </div>
            <label class="switch">
                <input type="checkbox" name="complaint_ai_suggestions" <?= $aiSugg ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
        <div class="toggle-row">
            <div>
                <h4>فحص تشابه الوصف الذكي</h4>
                <p>مقارنة وصف البلاغ الجديد بالبلاغات السابقة على نفس الجهاز لتنبيه المستخدم للتشابه. <b>منع البلاغ المزدوج على جهاز له بلاغ حي يبقى يعمل دائماً</b> — هذا المفتاح يخص التحليل النصي الذكي فقط.</p>
            </div>
            <label class="switch">
                <input type="checkbox" name="complaint_ai_dup_check" <?= $aiDup ? 'checked' : '' ?>>
                <span class="slider"></span>
            </label>
        </div>
    </div>

    <button type="submit" class="btn-save"><i class="fa-solid fa-floppy-disk"></i> حفظ الإعدادات</button>
</form>

</div></main>
</div>
</body>
</html>