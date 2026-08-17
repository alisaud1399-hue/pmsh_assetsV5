// <?php
/**
 * settings/api/suggest_translation.php — اقتراح ترجمة عبر Groq AI
 * ──────────────────────────────────────────────────────────────
 * الاستخدام من الواجهة:
 *   fetch('settings/api/suggest_translation.php', {
 *     method: 'POST',
 *     body: new FormData()  // + 'text' + 'target' (ar|en) + 'context' (category|location)
 *   })
 *
 * الصلاحية: admin فقط (لحماية الـ API key والـ rate limit)
 * الحد: 60 طلب/دقيقة لكل مستخدم (محمي بـ simple throttle في session)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';
page_guard('settings.index');
header('Content-Type: application/json; charset=utf-8');

function out(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// rate limiting بسيط عبر session
$_SESSION['sug_calls'] ??= [];
$now = time();
$_SESSION['sug_calls'] = array_filter($_SESSION['sug_calls'], fn($t) => $t > $now - 60);
if (count($_SESSION['sug_calls']) >= 30) {
    out(['ok' => false, 'msg' => 'تجاوزت حد الطلبات (30/دقيقة). حاول بعد دقيقة.']);
}
$_SESSION['sug_calls'][] = $now;

$text   = trim($_POST['text'] ?? '');
$target = $_POST['target'] ?? 'en';
$ctx    = $_POST['context'] ?? 'category'; // category | location | generic

if ($text === '') out(['ok' => false, 'msg' => 'النص فارغ']);
if (!in_array($target, ['ar', 'en'])) out(['ok' => false, 'msg' => 'لغة غير مدعومة']);
if (mb_strlen($text) > 200) out(['ok' => false, 'msg' => 'النص طويل جداً (200 حرف كحد أقصى)']);

$key = ai_key();
if (!$key) out(['ok' => false, 'msg' => 'مفتاح Groq غير معرّف']);

// ── بناء الـ prompt حسب اللغة والسياق (توسيع الاختصارات + تثبيت المصطلحات) ──
if ($target === 'en') {
    $sys = <<<SYS
You are an expert medical translator. Translate the Arabic hospital asset-category / location name into concise standard English (medical-equipment catalog style). Keep numbers/codes as-is. Output ONLY the English translation — no quotes, no notes, no Arabic.
Examples:
"معدات المختبرات والأجهزة" -> Laboratory Equipment & Instruments
"وحدة العناية المركزة لحديثي الولادة - الجناح 09" -> Neonatal Intensive Care Unit (NICU) - Ward 09
SYS;
    $prompt = "Translate to English:\n\"$text\"";
} else {
    $sys = <<<SYS
You are an expert medical translator for a Saudi government (MOH) hospital. Translate the English name into formal Arabic.

STRICT RULES:
1. EXPAND medical abbreviations, never transliterate:
   NICU=وحدة العناية المركزة لحديثي الولادة | PICU=وحدة العناية المركزة للأطفال | ICU=وحدة العناية المركزة | CCU=وحدة العناية القلبية | ER/ED=قسم الطوارئ | OR=غرفة العمليات | OPD=العيادات الخارجية | Lab=المختبر
2. Fixed terms: Ward=جناح | Floor=طابق | Building=مبنى | Room=غرفة | Unit=وحدة | Department=قسم | Clinic=عيادة | Wing=جناح.
3. Keep trailing numbers/codes exactly (e.g. "09" stays "09").
4. Translate meaning; do NOT invent facilities. "NICU - Ward 09" = a neonatal-ICU ward no. 09, NOT a children's hospital.
5. Output ONLY Arabic. No quotes, no notes, no English.
Examples:
"NICU - Ward 09" -> وحدة العناية المركزة لحديثي الولادة - الجناح 09
"ICU - 2nd Floor" -> وحدة العناية المركزة - الطابق الثاني
"Operating Room 3" -> غرفة العمليات 3
SYS;
    $prompt = "Translate to Arabic:\n\"$text\"";
}

// ── استدعاء Groq API ───────────────────────────────────────────
$ch = curl_init(ai_base_url() . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_POSTFIELDS     => json_encode([
        'model'       => ai_model(),
        'messages'    => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $prompt],
        ],
        'temperature' => 0.1,
        'max_tokens'  => 120,
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ],
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) out(['ok' => false, 'msg' => 'cURL: ' . $err]);
if ($code !== 200) {
    out(['ok' => false, 'msg' => "Groq HTTP $code: " . mb_substr((string)$res, 0, 200)]);
}

$data = json_decode($res, true);
$suggestion = trim($data['choices'][0]['message']['content'] ?? '');

// تنظيف الـ output: إزالة علامات اقتباس، أسطر زائدة
$suggestion = trim($suggestion, " \t\n\r\0\x0B\"'`");
$suggestion = preg_replace('/^(English|Translation|Translation:|Output|Result|العربي|الإنجليزي|ترجمة):\s*/i', '', $suggestion);
$suggestion = trim($suggestion, " \t\n\r\0\x0B\"'`");

if ($suggestion === '') out(['ok' => false, 'msg' => 'Groq لم يُعط ترجمة']);

out(['ok' => true, 'suggestion' => $suggestion, 'src' => 'groq:llama-3.3-70b']);