<?php
/**
 * includes/_utils.php — دوال مساعدة مشتركة بين كل الوحدات
 *
 * يُستخدم لعرض التسميات ثنائية اللغة، مساعدات التواريخ،
 * وتنسيق المخرجات بشكل موحّد عبر النظام.
 *
 * ──────────────────────────────────────────────────────────────
 * لماذا هذا الملف منفصل؟
 * • assets/includes/_utils.php خاص بوحدة الأصول (دوال حسابية للأصول)
 * • هذا الملف = مشترك (تصنيفات، مواقع، إعدادات، تقارير...)
 * • كل صفحة تعمل require_once dirname(__DIR__) . '/includes/_utils.php';
 */

if (!defined('PMSH_INCLUDES_UTILS')) {

define('PMSH_INCLUDES_UTILS', true);

/**
 * display_name() — عرض اسم ثنائي اللغة من صف (category/location/...)
 *
 * الاستخدام:
 *   <td><?= e(display_name($cat)) ?></td>
 *   <td><?= e(display_name($cat, 'en')) ?></td>
 *
 * @param array  $row    الصف المطلوب (يجب أن يحوي name + name_en)
 * @param string|null $lang  'ar' | 'en' | null=تلقائي حسب لغة الواجهة
 * @return string  الاسم المعروض (مع fallback إذا كانت اللغة المطلوبة فارغة)
 */
function display_name(array $row, ?string $lang = null): string {
    if ($lang === null) {
        $lang = function_exists('is_rtl') && is_rtl() ? 'ar' : 'en';
    }
    // للأسماء ذات طبيعة "إنجليزية بشكل أساسي" (مثل item_locations الحالية)
    // نعكس المنطق عند طلب 'ar' ليرجع name_en (الذي يفترض أن يكون عربي)
    $primary   = $lang === 'en' ? 'name_en' : 'name';
    $fallback  = $lang === 'en' ? 'name'   : 'name_en';

    if (!empty($row[$primary]))   return $row[$primary];
    if (!empty($row[$fallback]))  return $row[$fallback];
    return '';
}

/**
 * display_bilingual() — عرض ثنائي اللغة جنباً إلى جنب
 *
 * الاستخدام في الجداول الإدارية:
 *   <td><?= e(display_bilingual($cat)) ?></td>
 *   → يعرض: "معدات طبية / Medical Equipment"
 *   → أو:     "معدات طبية / <span class='muted'>— غير مترجم —</span>"
 *
 * @param array $row
 * @param string $sep الفاصل (افتراضي " / ")
 * @return string
 */
function display_bilingual(array $row, string $sep = ' / '): string {
    $ar = $row['name']    ?? '';
    $en = $row['name_en'] ?? '';
    if ($ar && $en)  return $ar . $sep . $en;
    if ($ar)        return $ar . $sep . '<span style="color:#dc2626;font-style:italic">⚠ EN missing</span>';
    if ($en)        return '<span style="color:#dc2626;font-style:italic">⚠ AR missing</span>' . $sep . $en;
    return '<span style="color:#94a3b8">—</span>';
}

/**
 * tr_needs_translation() — هل هذا الصف بحاجة لترجمة؟
 *
 * الاستخدام في Bulk Translation Helper:
 *   if (tr_needs_translation($cat, 'en')) { ... اعرضه ... }
 */
function tr_needs_translation(array $row, string $lang): bool {
    $field = $lang === 'en' ? 'name_en' : 'name';
    return empty($row[$field]);
}

/**
 * safe_lang_label() — حماية ضد أسماء طويلة جداً في الواجهة
 */
function safe_lang_label(string $s, int $max = 60): string {
    $s = trim($s);
    if (mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max - 1) . '…';
}

/**
 * locale_flag() — علم صغير مرتبط باللغة (يستخدم في الواجهة لإظهار اللغة المتاحة)
 */
function locale_flag(string $lang): string {
    return $lang === 'en' ? '🇬🇧' : '🇸🇦';
}

// ════════════════════════════════════════════════════════════════
//  AI Provider Abstraction — كل الـ AI endpoints تستخدم هذه الدوال
// ════════════════════════════════════════════════════════════════

/**
 * سر تشفير AES-256-CBC لمفاتيح API المخزّنة في DB
 * مُشتقّ من APP_SECRET_SALT في config.php
 */
function ai_secret(): string {
    $salt = defined('APP_SECRET_SALT') ? APP_SECRET_SALT : 'pmsh-default-salt';
    return hash('sha256', 'pmsh-ai-keys-v1' . $salt, true); // 32 bytes for AES-256
}

/**
 * تشفير مفتاح API للحفظ في DB
 */
function ai_key_encrypt(string $plain): string {
    if ($plain === '') return '';
    $iv = substr(hash('sha256', random_bytes(16), true), 0, 16);
    $encrypted = openssl_encrypt($plain, 'AES-256-CBC', ai_secret(), OPENSSL_RAW_DATA, $iv);
    return 'enc1:' . base64_encode($iv . $encrypted);
}

/**
 * فك تشفير مفتاح API
 */
function ai_key_decrypt(string $stored): string {
    if ($stored === '') return '';
    // لو مو مشفّر (نص عادي قديم) نرجعه كما هو
    if (strpos($stored, 'enc1:') !== 0) {
        // heuristic: مفاتيح Groq تبدأ بـ gsk-، OpenAI بـ sk-، DeepSeek بـ sk-
        if (preg_match('/^(gsk|sk|sk-or|sk-deep)/', $stored)) return $stored;
        return ''; // مش مفتاح صحيح
    }
    $raw = base64_decode(substr($stored, 5), true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $decrypted = openssl_decrypt($cipher, 'AES-256-CBC', ai_secret(), OPENSSL_RAW_DATA, $iv);
    return $decrypted === false ? '' : $decrypted;
}

/**
 * إعدادات AI الموحدة: مفتاح + مزود + model + base_url
 * يقرأ من DB (admin settings) أولاً، ثم config.php كـ fallback
 * cached في static — يُستدعى مرة واحدة لكل request
 */
function ai_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [
        'provider' => get_setting('ai_provider', 'groq'),
        'api_key'  => '',
        'model'    => get_setting('ai_model', 'llama-3.3-70b-versatile'),
        'base_url' => get_setting('ai_base_url', 'https://api.groq.com/openai/v1'),
    ];

    // 1) جرّب من DB (المشفّر)
    $encrypted = (string)get_setting('groq_api_key', '');
    if ($encrypted !== '') {
        $cache['api_key'] = ai_key_decrypt($encrypted);
    }

    // 2) Fallback للـ config.php
    if ($cache['api_key'] === '' && defined('GROQ_API_KEY')) {
        $cache['api_key'] = GROQ_API_KEY;
    }
    if (empty($cache['model']) && defined('GROQ_MODEL') && GROQ_MODEL !== '') {
        $cache['model'] = GROQ_MODEL;
    }
    if (empty($cache['base_url']) && defined('GROQ_BASE_URL')) {
        $cache['base_url'] = GROQ_BASE_URL;
    }

    return $cache;
}

/** مفتاح API (مفكوك التشفير) — جاهز للاستخدام في cURL */
function ai_key(): string {
    return ai_settings()['api_key'];
}

/** اسم الموديل */
function ai_model(): string {
    return ai_settings()['model'];
}

/** base URL للـ API */
function ai_base_url(): string {
    return ai_settings()['base_url'];
}

/** اسم المزود ('groq', 'openai', 'deepseek', 'custom') */
function ai_provider(): string {
    return ai_settings()['provider'];
}

/**
 * يُرجع true لو الإعدادات جاهزة (مفتاح + base URL + model)
 */
function ai_is_ready(): bool {
    $s = ai_settings();
    return $s['api_key'] !== '' && $s['base_url'] !== '' && $s['model'] !== '';
}

/**
 * يُرجع الـ model + key + base URL المناسب بناءً على المزود المختار
 * (يستعمل defaults ذكية لو الـ DB فاضية)
 */
function ai_defaults_for_provider(string $provider): array {
    $defaults = [
        'groq' => [
            'base_url' => 'https://api.groq.com/openai/v1',
            'model'    => 'llama-3.3-70b-versatile',
        ],
        'openai' => [
            'base_url' => 'https://api.openai.com/v1',
            'model'    => 'gpt-4o-mini',
        ],
        'deepseek' => [
            'base_url' => 'https://api.deepseek.com/v1',
            'model'    => 'deepseek-chat',
        ],
        'custom' => [
            'base_url' => '',
            'model'    => '',
        ],
    ];
    return $defaults[$provider] ?? $defaults['groq'];
}

} // PMSH_INCLUDES_UTILS guard