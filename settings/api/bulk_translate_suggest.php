<?php
/**
 * settings/api/bulk_translate_suggest.php — ترجمة فورية لمجموعة صفوف عبر Groq
 * ──────────────────────────────────────────────────────────────────
 * POST: { tbl: 'item_categories'|'item_locations', limit: 10, offset: 0 }
 *   - يجلب كل الصفوف اللي target field عندها فارغ
 *   - يترجمها دفعة (لحد limit) عبر Groq
 *   - يرجع JSON: { results: [{id, source, target, suggestion}], has_more: bool, total: int }
 *
 * ملاحظة: rate limiting صارم (10 طلبات/دقيقة) لأن كل طلب = N استدعاءات Groq
 * الصلاحية: settings.index (admin)
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/_utils.php';
page_guard('settings.index');
header('Content-Type: application/json; charset=utf-8');

function out(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$tbl = $_POST['tbl'] ?? '';
if (!in_array($tbl, ['item_categories', 'item_locations'])) {
    out(['ok' => false, 'msg' => 'Invalid table']);
}

$limit  = max(1, min(20, (int)($_POST['limit'] ?? 10))); // max 20 per call to avoid rate limit
$offset = max(0, (int)($_POST['offset'] ?? 0));

$key = ai_key();
if (!$key) out(['ok' => false, 'msg' => 'GROQ key missing']);

// rate limit: 10 calls/دقيقة
$_SESSION['bulk_sug_calls'] ??= [];
$now = time();
$_SESSION['bulk_sug_calls'] = array_filter($_SESSION['bulk_sug_calls'], fn($t) => $t > $now - 60);
if (count($_SESSION['bulk_sug_calls']) >= 10) {
    out(['ok' => false, 'msg' => 'تجاوزت حد الترجمات الجماعية (10 طلبات/دقيقة). انتظر دقيقة.']);
}
$_SESSION['bulk_sug_calls'][] = $now;

// ── نحدد أعمدة المصدر والهدف حسب الجدول ─────────────────────
$source_col = $tbl === 'item_categories' ? 'name'    : 'name';    // للتصنيفات: العربي (name) → EN
$target_col = $tbl === 'item_categories' ? 'name_en' : 'name_en'; // للمواقع:    EN (name) → AR (name_en)
$ctx        = $tbl === 'item_categories' ? 'category' : 'location';
$target_lang = $tbl === 'item_categories' ? 'en' : 'ar';

// ── عدّ الإجمالي بدون ترجمة ──────────────────────────────────
$total_stmt = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE $target_col IS NULL OR $target_col = ''");
$total_stmt->execute();
$total = (int)$total_stmt->fetchColumn();

if ($total === 0) {
    out(['ok' => true, 'results' => [], 'has_more' => false, 'total' => 0, 'translated' => 0]);
}

// ── جلب دفعة من الصفوف الفاضية ──────────────────────────────
$rows_stmt = $pdo->prepare("SELECT id, $source_col AS source FROM $tbl WHERE $target_col IS NULL OR $target_col = '' ORDER BY id LIMIT ? OFFSET ?");
$rows_stmt->bindValue(1, $limit, PDO::PARAM_INT);
$rows_stmt->bindValue(2, $offset, PDO::PARAM_INT);
$rows_stmt->execute();
$rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── دالة استدعاء Groq واحدة ─────────────────────────────────
function call_groq_bulk(string $text, string $ctx, string $target_lang, string $key): ?string {
    if ($target_lang === 'en') {
        $sys = <<<SYS
You are an expert medical translator. Translate the Arabic hospital asset-category / location name into concise standard English (medical-equipment catalog style). Keep numbers/codes as-is. Output ONLY the English translation — no quotes, no notes, no Arabic.
Examples:
"معدات المختبرات والأجهزة" -> Laboratory Equipment & Instruments
"وحدة العناية المركزة لحديثي الولادة" -> Neonatal Intensive Care Unit (NICU)
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
"Main Building - Ground Floor" -> المبنى الرئيسي - الطابق الأرضي
SYS;
        $prompt = "Translate to Arabic:\n\"$text\"";
    }

    $ch = curl_init(ai_base_url() . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => ai_model(),
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => 120,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return null;
    $data = json_decode($res, true);
    $out = trim($data['choices'][0]['message']['content'] ?? '');
    $out = trim($out, " \t\n\r\0\x0B\"'`");
    $out = preg_replace('/^(English|Translation|Output|Result|العربي|الإنجليزي|ترجمة):\s*/i', '', $out);
    $out = trim($out, " \t\n\r\0\x0B\"'`");
    return $out !== '' ? $out : null;
}

$results = [];
$translated = 0;
foreach ($rows as $r) {
    $src = trim($r['source']);
    if ($src === '') {
        $results[] = ['id' => (int)$r['id'], 'source' => $src, 'suggestion' => null, 'error' => 'empty source'];
        continue;
    }
    $sug = call_groq_bulk($src, $ctx, $target_lang, $key);
    if ($sug) {
        $results[] = ['id' => (int)$r['id'], 'source' => $src, 'suggestion' => $sug];
        $translated++;
    } else {
        $results[] = ['id' => (int)$r['id'], 'source' => $src, 'suggestion' => null, 'error' => 'groq failed'];
    }
    usleep(150000); // 150ms بين كل استدعاء (Rate-limit friendly)
}

out([
    'ok' => true,
    'results' => $results,
    'translated' => $translated,
    'has_more' => ($offset + $limit) < $total,
    'next_offset' => $offset + $limit,
    'total' => $total,
    'remaining' => max(0, $total - ($offset + $limit)),
]);