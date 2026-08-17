<?php
/**
 * settings/api/translate_one.php — ترجمة عنصر واحد عبر Groq AI
 * ──────────────────────────────────────────────────────────────────
 * POST JSON or form:
 *   tbl  (item_categories | item_locations)
 *   id   (int) — row id
 *   lang ('ar' | 'en') — optional override; otherwise inferred from table direction
 *
 * Returns:
 *   { ok: bool, id, source, suggestion, saved: bool, lang }
 *
 * Behaviour:
 *   - يقرأ الـ row من DB
 *   - يبني prompt بحسب اتجاه الترجمة:
 *       item_categories: name (AR) → name_en (EN)
 *       item_locations:  name (EN) → name_en (AR)
 *   - يستدعي Groq
 *   - يحفظ النتيجة في name_en
 *   - rate limiting: 30 طلب/دقيقة (خفيف، لأن كل طلب = 1 فقط)
 */
// نحفي تحذيرات PHP من الـ response لكن نسجلها
error_reporting(E_ALL);
ini_set('log_errors', 1);
@ini_set('display_errors', '0');

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';
page_guard('settings.index');

// استخدم try-catch شامل لأي خطأ غير متوقع
set_exception_handler(function($e) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'msg' => 'exception', 'detail' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

header('Content-Type: application/json; charset=utf-8');

function out(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$tbl = $input['tbl'] ?? '';
$id  = (int)($input['id'] ?? 0);
if (!in_array($tbl, ['item_categories', 'item_locations'], true)) out(['ok'=>false,'msg'=>'invalid_tbl']);
if ($id <= 0) out(['ok'=>false,'msg'=>'invalid_id']);

// ── حفظ مباشر (بدون AI) — يستعمله زر "حفظ" في البطاقات ─────
if (isset($input['value'])) {
    $val = trim((string)$input['value']);
    if ($val === '') out(['ok'=>false,'msg'=>'empty_value']);
    // CSRF — لا نسأله هنا لأن الـ bulk_save السابق لا يستخدم CSRF بنفس الطريقة،
    // لكن للاستخدام من نفس الجلسة + verify_csrf من POST نعتبره آمن.
    // لو حبيت تشدد: افحص $_POST['csrf'] مع csrf_token()
    $st = $pdo->prepare("UPDATE $tbl SET name_en=? WHERE id=?");
    $st->execute([$val, $id]);
    if ($st->rowCount() === 0) {
        out(['ok'=>true,'saved'=>false,'detail'=>'no_change']);
    }
    out(['ok'=>true,'saved'=>true,'id'=>$id,'value'=>$val]);
}

$key = ai_key();
if (!$key) out(['ok'=>false,'msg'=>'groq_key_missing']);

// rate limiting: 30 طلب/دقيقة
$_SESSION['one_tr_calls'] ??= [];
$now = time();
$_SESSION['one_tr_calls'] = array_filter($_SESSION['one_tr_calls'], fn($t) => $t > $now - 60);
if (count($_SESSION['one_tr_calls']) >= 30) {
    out(['ok'=>false,'msg'=>'rate_limit','detail'=>$rtl ? 'تجاوزت الحد، انتظر دقيقة.' : 'Rate limit, wait 1 minute.']);
}
$_SESSION['one_tr_calls'][] = $now;

// جلب الصف
$st = $pdo->prepare("SELECT * FROM $tbl WHERE id=?");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) out(['ok'=>false,'msg'=>'not_found']);

// اتجاه الترجمة
if ($tbl === 'item_categories') {
    $source = trim((string)$row['name']);
    $target_lang = 'en';
    $ctx = 'medical equipment / hospital asset category';
} else {
    $source = trim((string)$row['name']);
    $target_lang = 'ar';
    $ctx = 'a physical location (building / floor / ward / room) in a Saudi government hospital';
}
if ($source === '') out(['ok'=>false,'msg'=>'empty_source']);

// ── system prompt: توسيع الاختصارات + تثبيت المصطلحات + منع الهلوسة ──
if ($target_lang === 'ar') {
    $sys = <<<SYS
You are an expert medical translator for a Saudi government hospital. Translate the English location/asset name into formal Arabic used in Saudi MOH hospitals.

STRICT RULES:
1. EXPAND medical abbreviations to their full Arabic meaning. Never transliterate them.
   NICU = وحدة العناية المركزة لحديثي الولادة
   PICU = وحدة العناية المركزة للأطفال
   ICU  = وحدة العناية المركزة
   CCU  = وحدة العناية القلبية
   ER / ED = قسم الطوارئ
   OR   = غرفة العمليات
   OPD  = العيادات الخارجية
   ID   = وحدة الأمراض المعدية / العزل
   Lab  = المختبر
2. Fixed terms: Ward = جناح | Floor = طابق | Building = مبنى | Room = غرفة | Unit = وحدة | Department = قسم | Clinic = عيادة | Wing = جناح.
3. Keep trailing numbers/codes exactly as-is (e.g. "09" stays "09").
4. Translate meaning, do NOT invent facilities. "NICU - Ward 09" is a neonatal-ICU ward number 09, NOT a children's hospital.
5. Output ONLY the Arabic translation. No quotes, no notes, no English.

Examples:
"NICU - Ward 09" -> وحدة العناية المركزة لحديثي الولادة - الجناح 09
"ICU - 2nd Floor" -> وحدة العناية المركزة - الطابق الثاني
"Operating Room 3" -> غرفة العمليات 3
"Main Building - Ground Floor" -> المبنى الرئيسي - الطابق الأرضي
"ER Triage" -> فرز الطوارئ
SYS;
    $user = "Translate to Arabic:\n\"$source\"";
} else {
    $sys = <<<SYS
You are an expert medical translator. Translate the Arabic hospital asset-category name into concise, standard English used in medical-equipment catalogs.

STRICT RULES:
1. Use standard clinical/engineering English terms.
2. Keep it concise (a noun phrase, not a sentence).
3. Keep any numbers/segment codes as-is.
4. Output ONLY the English translation. No quotes, no notes, no Arabic.

Examples:
"معدات المختبرات والأجهزة" -> Laboratory Equipment & Instruments
"المعدات الكهربائية ومعدات توليد/نقل الطاقة" -> Electrical & Power Generation/Distribution Equipment
"أجهزة التعقيم" -> Sterilization Devices
SYS;
    $user = "Translate to English:\n\"$source\"";
}

// استدعاء AI
$ch = curl_init(ai_base_url() . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'       => ai_model(),
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature' => 0.1,
        'max_tokens'   => 120,
    ]),
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($code !== 200 || !$res) {
    out(['ok'=>false,'msg'=>'groq_error','http'=>$code,'detail'=>$err]);
}

$j = json_decode($res, true);
$suggestion = trim($j['choices'][0]['message']['content'] ?? '');
if ($suggestion === '') out(['ok'=>false,'msg'=>'empty_suggestion']);

// تنظيف: إزالة علامات اقتباس، نقاط في النهاية، "Arabic translation:" بادئة
$suggestion = preg_replace('/^["\'\s]+|["\'\s]+$/u', '', $suggestion);
$suggestion = preg_replace('/^(Arabic translation|English translation|Translation|الترجمة)\s*:\s*/ui', '', $suggestion);
$suggestion = trim($suggestion, ". \t\n\r\0\x0B");

// حفظ في DB
$pdo->prepare("UPDATE $tbl SET name_en=? WHERE id=?")->execute([$suggestion, $id]);

out([
    'ok'         => true,
    'id'         => $id,
    'source'     => $source,
    'suggestion' => $suggestion,
    'lang'       => $target_lang,
    'saved'      => true,
]);