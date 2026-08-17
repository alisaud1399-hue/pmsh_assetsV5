<?php
/**
 * nupco/translate_ar.php — ترجمة كتالوج NUPCO إلى العربية
 * ─────────────────────────────────────────────────────────
 *   • يستخدم Groq API (llama-3.3-70b) للترجمة
 *   • 3 حقول: description_ar, category_ar, sub_category_ar
 *   • معالجة بالدفعات (50 صف/طلب) مع retry
 *   • عرض التقدم + الإحصائيات
 *   • معالجة في الخلفية (background-friendly) — PHP set_time_limit
 *
 *   GET  ?action=stats   → JSON للإحصائيات
 *   POST ?action=run     → تشغيل دفعة (batch)
 *      params: fields (desc,cat,subcat), batch_size, offset
 *   POST ?action=run_all → تشغيل كل الدفعات
 *
 *   الصلاحيات: nupco.translate_ar (view + apply)
 */

if (basename($_SERVER['SCRIPT_NAME']) === basename(__FILE__)) {
    // Direct call: requires config (auth + helpers included via config)
    require_once dirname(__DIR__) . '/config.php';
    require_once dirname(__DIR__) . '/includes/_utils.php';
}

if (!function_exists('is_rtl')) {
    function is_rtl(): bool { return true; }
}

$rtl = is_rtl();
$page_title = $rtl ? 'ترجمة كتالوج NUPCO' : 'Translate NUPCO Catalog';

// ── الصلاحيات ──
$can_view = can('nupco.translate_ar', 'view');
$can_run  = can('nupco.translate_ar', 'apply');

if (!$can_view) {
    http_response_code(403);
    echo $rtl ? '⛔ لا تملك صلاحية الوصول لهذه الصفحة' : 'Access denied';
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'page';
header('Content-Type: text/html; charset=utf-8');


// ════════════════════════════════════════════════════════════════
// Helpers: استدعاء Groq للترجمة
// ════════════════════════════════════════════════════════════════

/**
 * ترجمة دفعة من النصوص عبر Groq
 *   - prompt: 3 أسطر "EN: ...\nAR: ..." لكل صف
 *   - نطلب من Groq إرجاع JSON array بنفس الترتيب
 */
function groq_translate_batch(array $items, string $field_name, string $model, string $key): array {
    // items = [['id'=>N, 'src'=>"..."], ...]
    // نبني prompt: قائمة مرقّمة + نظام
    $lines = [];
    foreach ($items as $i => $it) {
        $src = trim((string)$it['src']);
        if ($src === '') {
            $items[$i]['_skip'] = true;
            continue;
        }
        $lines[] = ($i + 1) . '. ' . $src;
    }
    if (empty($lines)) return ['ok' => true, 'translations' => []];

    $system = <<<SYS
You are a medical equipment terminology translator for a Saudi Arabian hospital asset management system.
Translate the user's English medical/technical terms to formal Arabic.
Preserve:
- Numerical values (5 → ٥ or 5)
- Units (mm, kg, V, etc.) — keep in English
- Brand names (keep in English)
- Material codes (GMDN, UNSPSC, etc.) — keep in English
- Medical standards (ISO, CE, FDA) — keep in English

CRITICAL output format (you MUST follow this exact JSON shape):
{
  "translations": ["translation_1", "translation_2", ...]
}
- The 'translations' key MUST contain an array of EXACTLY the same count as input.
- Array order MUST match the input numbering.
- Each element is a string (Arabic translation). Empty string if untranslatable.
- Do NOT include numbering, explanations, or markdown — ONLY the JSON object.
SYS;

    $user = "Translate these to Arabic (return JSON object with 'translations' array, same order, same count):\n\n" . implode("\n", $lines);

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.1,
        'max_tokens' => count($items) * 100,
        'response_format' => ['type' => 'json_object'],
    ];

    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        return ['ok' => false, 'error' => "HTTP $code: $err", 'body' => substr((string)$resp, 0, 500)];
    }

    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode($content, true);

    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'Groq returned non-JSON', 'body' => substr($content, 0, 500)];
    }

    // استخراج المصفوفة — النموذج قد يرجّع:
    // 1. {"translations": [...]} — الشكل المطلوب
    // 2. {"0": "trans1", "1": "trans2", ...} — مفاتيح رقمية نصية
    // 3. ["trans1", "trans2", ...] — مصفوفة مباشرة
    if (isset($parsed['translations']) && is_array($parsed['translations'])) {
        $arr = $parsed['translations'];
    } else {
        // هل هي مصفوفة مفاتيحها رقمية؟ (0, 1, 2, ...)
        $isNumeric = !empty($parsed) && array_keys($parsed) === range(0, count($parsed) - 1);
        if ($isNumeric) {
            $arr = $parsed;
        } else {
            // بحث عن أول قيمة هي مصفوفة
            $arr = null;
            foreach ($parsed as $v) {
                if (is_array($v)) { $arr = $v; break; }
            }
            if ($arr === null) {
                return ['ok' => false, 'error' => 'Groq returned unexpected JSON shape', 'body' => substr($content, 0, 500)];
            }
        }
    }

    // ربط النتائج بالـ IDs
    $out = [];
    $i = 0;
    foreach ($items as $it) {
        if (!empty($it['_skip'])) {
            $out[$it['id']] = null;
            continue;
        }
        $out[$it['id']] = $arr[$i] ?? null;
        $i++;
    }
    return ['ok' => true, 'translations' => $out];
}


// ════════════════════════════════════════════════════════════════
// API: stats
// ════════════════════════════════════════════════════════════════
if ($action === 'stats') {
    $row = $pdo->query("SELECT * FROM v_nupco_translation_status")->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($row ?: [], JSON_UNESCAPED_UNICODE);
    exit;
}


// ════════════════════════════════════════════════════════════════
// API: run (دفعة واحدة)
// ════════════════════════════════════════════════════════════════
if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$can_run) { echo json_encode(['ok' => false, 'error' => 'no_permission']); exit; }

    $fields = $_POST['fields'] ?? ['description'];
    if (!is_array($fields)) $fields = [$fields];
    $batch_size = max(1, min(50, (int)($_POST['batch_size'] ?? 30)));
    $offset     = max(0, (int)($_POST['offset'] ?? 0));

    $key = ai_key();
    $model = defined('GROQ_MODEL') ? GROQ_MODEL : 'llama-3.3-70b-versatile';
    if (!$key) { echo json_encode(['ok' => false, 'error' => 'groq_key_missing']); exit; }

    @set_time_limit(120);

    $result = ['ok' => true, 'translated' => 0, 'failed' => 0, 'skipped' => 0, 'details' => []];

    // لكل حقل مطلوب
    foreach ($fields as $field_short) {
        $db_field = "{$field_short}_ar";
        $src_field = $field_short === 'description' ? 'description_en' : $field_short;
        if (!in_array($db_field, ['description_ar','category_ar','sub_category_ar'], true)) continue;

        // جلب الدفعة (الصفوف التي لم تُترجم بعد)
        $sql = "SELECT id, `$src_field` AS src FROM nupco_catalog
                WHERE `$src_field` IS NOT NULL AND TRIM(`$src_field`) != ''
                  AND (`$db_field` IS NULL OR TRIM(`$db_field`) = '')
                ORDER BY id
                LIMIT $batch_size OFFSET $offset";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            $result['details'][$db_field] = ['count' => 0, 'msg' => 'no_pending'];
            continue;
        }

        $items = array_map(fn($r) => ['id' => (int)$r['id'], 'src' => $r['src']], $rows);
        $resp = groq_translate_batch($items, $db_field, $model, $key);

        if (!$resp['ok']) {
            $result['ok'] = false;
            $result['details'][$db_field] = ['error' => $resp['error'] ?? 'unknown'];
            continue;
        }

        $field_ok = 0; $field_fail = 0; $field_skip = 0;
        $pdo->beginTransaction();
        try {
            foreach ($resp['translations'] as $nupco_id => $translation) {
                if ($translation === null || $translation === '') {
                    $field_skip++;
                    continue;
                }
                // تنظيف: إزالة علامات اقتباس إذا وُجدت
                $translation = trim($translation, " \t\n\r\0\x0B\"'");
                if ($translation === '') {
                    $field_skip++;
                    continue;
                }
                $upd = $pdo->prepare("
                    UPDATE nupco_catalog
                    SET `$db_field` = ?, translated_at = NOW(), translated_by = ?
                    WHERE id = ?
                ");
                $upd->execute([$translation, "groq:$model", $nupco_id]);
                // log
                $pdo->prepare("
                    INSERT INTO nupco_translation_log (nupco_id, field_name, source_text, translated_text, model, status)
                    VALUES (?, ?, (SELECT `$src_field` FROM nupco_catalog WHERE id=?), ?, ?, 'ok')
                ")->execute([$nupco_id, $db_field, $nupco_id, $translation, $model]);
                $field_ok++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $result['ok'] = false;
            $result['details'][$db_field] = ['error' => 'db: ' . $e->getMessage()];
            continue;
        }

        $result['translated'] += $field_ok;
        $result['skipped'] += $field_skip;
        $result['details'][$db_field] = ['ok' => $field_ok, 'skipped' => $field_skip];
    }

    // إحصائيات محدّثة
    $row = $pdo->query("SELECT * FROM v_nupco_translation_status")->fetch(PDO::FETCH_ASSOC);
    $result['stats'] = $row;

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}


// ════════════════════════════════════════════════════════════════
// عرض الصفحة
// ════════════════════════════════════════════════════════════════

$stats = $pdo->query("SELECT * FROM v_nupco_translation_status")->fetch(PDO::FETCH_ASSOC) ?: [];

$has_key = (ai_key() !== '');
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body { font-family: 'Tajawal', 'Inter', system-ui, sans-serif !important; }
.tr-wrap { max-width: 1100px; margin: 24px auto; padding: 0 16px; }
.tr-hero { background: linear-gradient(135deg, #ecfeff 0%, #f0f9ff 50%, #e0f2fe 100%); border: 1.5px solid #bae6fd; border-radius: 16px; padding: 18px 24px; margin-bottom: 16px; display: flex; align-items: center; gap: 18px; box-shadow: 0 2px 8px rgba(14,116,144,.06); }
.tr-hero-icon { width: 60px; height: 60px; border-radius: 14px; background: linear-gradient(135deg, #0891b2, #0e7490); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 28px; flex-shrink: 0; box-shadow: 0 4px 12px rgba(8,145,178,.3); }
.tr-hero h1 { margin: 0; font-size: 22px; font-weight: 800; color: #0c4a6e; font-family: 'Tajawal', sans-serif; }
.tr-hero p { margin: 4px 0 0; font-size: 13.5px; color: #075985; line-height: 1.6; font-family: 'Tajawal', sans-serif; }
.tr-h1 { font-size: 22px; font-weight: 700; color: #0f172a; margin: 0 0 6px; }
.tr-sub { color: #64748b; font-size: 14px; margin: 0 0 24px; }
.tr-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.04); }
.tr-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
.tr-stat { padding: 16px 18px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; display: flex; align-items: center; gap: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04); transition: all .2s; }
.tr-stat:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.08); }
.tr-stat .v { font-size: 26px; font-weight: 800; color: #0f172a; font-family: 'Tajawal', sans-serif; }
.tr-stat .l { font-size: 12px; color: #64748b; margin-top: 4px; font-weight: 600; font-family: 'Tajawal', sans-serif; }
.tr-stat-ico { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
.tr-stat.ok    { border-right-color: #16a34a; }
.tr-stat.warn  { border-right-color: #f59e0b; }
.tr-stat.info  { border-right-color: #0ea5e9; }
.tr-progress { background: #f1f5f9; border-radius: 8px; height: 24px; overflow: hidden; position: relative; margin-top: 12px; }
.tr-progress .fill { background: linear-gradient(90deg, #0ea5e9, #16a34a); height: 100%; transition: width .4s; }
.tr-progress .lbl { position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%); font-size: 12px; font-weight: 600; color: #0f172a; }
.tr-controls { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 12px 0; }
.tr-controls label { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; background: #f1f5f9; border-radius: 6px; cursor: pointer; font-size: 13px; }
.tr-btn { padding: 10px 18px; background: #0e7490; color: #fff; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 13px; }
.tr-btn:hover { background: #155e75; }
.tr-btn:disabled { background: #94a3b8; cursor: not-allowed; }
.tr-btn.ghost { background: #fff; color: #0e7490; border: 1px solid #0e7490; }
.tr-log { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; font-family: 'Consolas', monospace; font-size: 12px; max-height: 300px; overflow-y: auto; white-space: pre-wrap; }
.tr-t { width: 100%; border-collapse: collapse; font-family: 'Tajawal', sans-serif; }
.tr-t th { background: #f1f5f9; color: #475569; font-weight: 700; font-size: 12px; padding: 10px 12px; text-align: right; border-bottom: 1.5px solid #e2e8f0; }
.tr-t td { padding: 10px 12px; border-bottom: 1px solid #f1f5f9; font-size: 13px; vertical-align: top; }
.tr-t tr:hover { background: #f8fafc; }
.tr-t tr:last-child td { border-bottom: none; }
.tr-item-no { font-family: 'Inter', monospace; font-weight: 700; color: #0891b2; background: #ecfeff; padding: 3px 8px; border-radius: 4px; font-size: 11.5px; display: inline-block; }
.tr-en { color: #475569; line-height: 1.5; }
.tr-ar { color: #0f172a; line-height: 1.6; font-weight: 500; }
.tr-ar.warn { color: #d97706; }
.tr-ar.warn i { color: #d97706; margin-inline-start: 4px; font-size: 11px; }
.tr-meta { font-family: 'Inter', monospace; font-size: 11px; color: #94a3b8; text-align: center; }
.tr-cat { font-family: 'Tajawal', sans-serif; font-size: 12px; color: #475569; }
.tr-filters { background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 10px 12px; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
.tr-fg { display: flex; flex-direction: column; gap: 3px; }
.tr-fg label { font-size: 10.5px; font-weight: 800; color: #64748b; font-family: 'Tajawal', sans-serif; }
.tr-fg select, .tr-fg input { height: 30px; padding: 0 9px; border: 1.5px solid #e2e8f0; border-radius: 6px; font-family: 'Tajawal', sans-serif; font-size: 12.5px; background: #fff; min-width: 110px; }
.tr-fg input { min-width: 160px; }
.tr-pager { display: flex; align-items: center; gap: 6px; padding: 12px 0 0; margin-top: 10px; border-top: 1.5px solid #f1f5f9; font-family: 'Tajawal', sans-serif; }
.tr-pg-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; padding: 0 8px; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 6px; color: #475569; text-decoration: none; font-size: 12px; transition: all .15s; }
.tr-pg-btn:hover:not(.disabled) { background: #0891b2; color: #fff; border-color: #0891b2; }
.tr-pg-btn.disabled { opacity: .4; cursor: not-allowed; pointer-events: none; }
.tr-pg-info { padding: 0 8px; font-size: 12.5px; color: #475569; font-weight: 600; }
.tr-pg-range { margin-inline-start: auto; font-size: 11.5px; color: #94a3b8; }
</style>
</head>
<body class="app-layout">

<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-area" id="mainArea">

  <?php include __DIR__ . '/../includes/topbar.php'; ?>

  <main class="page-content">
  <div class="tr-wrap">
    <div class="tr-hero">
        <div class="tr-hero-icon"><i class="fa-solid fa-language"></i></div>
        <div>
            <h1><?= htmlspecialchars($page_title) ?></h1>
            <p>ترجمة أوصاف وفئات NUPCO إلى العربية عبر Groq AI (llama-3.3-70b). تتم المعالجة على دفعات.</p>
        </div>
    </div>

    <?php if (!$has_key): ?>
      <div class="tr-card" style="border-color: #fecaca; background: #fef2f2;">
        <strong style="color: #b91c1c;">⚠️ مفتاح Groq غير معرّف</strong>
        <p style="margin: 8px 0 0; color: #7f1d1d; font-size: 13px;">
          اذهب إلى <a href="<?= BASE_URL ?>/settings/index.php?tab=ai">إعدادات الذكاء الاصطناعي</a> لإضافة المفتاح.
        </p>
      </div>
    <?php endif; ?>

    <div class="tr-card">
      <h3 style="margin: 0 0 12px; font-size: 15px; font-family: 'Tajawal', sans-serif; font-weight: 700; color: #0f172a;"><i class="fa-solid fa-chart-pie" style="color:#0891b2;margin-inline-end:8px"></i>الإحصائيات</h3>
      <div class="tr-stats" id="tr-stats">
        <?php
          $total = (int)($stats['total_rows'] ?? 0);
          $desc_done = (int)($stats['desc_translated'] ?? 0);
          $desc_pend = (int)($stats['desc_pending'] ?? 0);
          $pct = $total > 0 ? round(100 * $desc_done / $total, 1) : 0;
        ?>
        <div class="tr-stat info">
          <div class="tr-stat-ico" style="background:linear-gradient(135deg, #e0f2fe, #bae6fd); color:#0369a1;"><i class="fa-solid fa-database"></i></div>
          <div><div class="v"><?= number_format($total) ?></div><div class="l">إجمالي صفوف NUPCO</div></div>
        </div>
        <div class="tr-stat ok">
          <div class="tr-stat-ico" style="background:linear-gradient(135deg, #dcfce7, #bbf7d0); color:#16a34a;"><i class="fa-solid fa-circle-check"></i></div>
          <div><div class="v"><?= number_format($desc_done) ?></div><div class="l">الوصف مترجم</div></div>
        </div>
        <div class="tr-stat warn">
          <div class="tr-stat-ico" style="background:linear-gradient(135deg, #fef3c7, #fde68a); color:#d97706;"><i class="fa-solid fa-hourglass-half"></i></div>
          <div><div class="v"><?= number_format($desc_pend) ?></div><div class="l">الوصف بالانتظار</div></div>
        </div>
        <div class="tr-stat">
          <div class="tr-stat-ico" style="background:linear-gradient(135deg, #ede9fe, #ddd6fe); color:#7c3aed;"><i class="fa-solid fa-percent"></i></div>
          <div><div class="v"><?= $pct ?>%</div>
          <div class="l">نسبة الإنجاز</div>
        </div>
      </div>
      <div class="tr-progress">
        <div class="fill" id="tr-fill" style="width: <?= $pct ?>%"></div>
        <div class="lbl" id="tr-pct"><?= $pct ?>%</div>
      </div>
    </div>

    <div class="tr-card">
      <h3 style="margin: 0 0 12px; font-size: 15px; font-family: 'Tajawal', sans-serif; font-weight: 700; color: #0f172a;"><i class="fa-solid fa-sliders" style="color:#0891b2;margin-inline-end:8px"></i>التحكم</h3>
      <div class="tr-controls">
        <label><input type="checkbox" id="f-desc" checked> الوصف (description_ar)</label>
        <label><input type="checkbox" id="f-cat"> الفئة (category_ar)</label>
        <label><input type="checkbox" id="f-sub"> الفئة الفرعية (sub_category_ar)</label>
        <span style="border-right: 1px solid #cbd5e1; margin: 0 8px;"></span>
        <label>حجم الدفعة:
          <select id="batch-size" style="padding: 4px 8px; border-radius: 4px; border: 1px solid #cbd5e1;">
            <option value="10">10</option>
            <option value="20">20</option>
            <option value="30" selected>30</option>
            <option value="50">50</option>
          </select>
        </label>
        <button class="tr-btn" id="btn-run" <?= !$can_run || !$has_key ? 'disabled' : '' ?>>
          <i class="fa-solid fa-play"></i> تشغيل دفعة واحدة
        </button>
        <button class="tr-btn ghost" id="btn-run-all" <?= !$can_run || !$has_key ? 'disabled' : '' ?>>
          <i class="fa-solid fa-forward"></i> تشغيل كل الدفعات حتى الانتهاء
        </button>
      </div>
      <div class="tr-log" id="tr-log">// السجل سيظهر هنا...</div>
    </div>

    <?php
    // ═══ فلاتر التصفح ═══
    $f_field = $_GET['f_field'] ?? 'all';     // all | description | category | sub_category
    $f_state = $_GET['f_state'] ?? 'translated'; // translated | pending | suspicious
    $f_date  = $_GET['f_date']  ?? 'all';     // all | today | week | month
    $f_q     = trim($_GET['q'] ?? '');
    $f_per   = max(10, min(200, (int)($_GET['per'] ?? 50)));
    $f_page  = max(1, (int)($_GET['page'] ?? 1));
    $f_offset = ($f_page - 1) * $f_per;

    // بناء WHERE
    $where = [];
    $params = [];

    if ($f_field === 'description') {
      $where[] = "description_en IS NOT NULL AND TRIM(description_en) != ''";
      $where[] = "description_ar IS NOT NULL AND TRIM(description_ar) != ''";
    } elseif ($f_field === 'category') {
      $where[] = "category IS NOT NULL AND TRIM(category) != ''";
      $where[] = "category_ar IS NOT NULL AND TRIM(category_ar) != ''";
    } elseif ($f_field === 'sub_category') {
      $where[] = "sub_category IS NOT NULL AND TRIM(sub_category) != ''";
      $where[] = "sub_category_ar IS NOT NULL AND TRIM(sub_category_ar) != ''";
    } else {
      $where[] = "(description_ar IS NOT NULL AND TRIM(description_ar) != '')";
    }

    if ($f_state === 'pending') {
      $where = ["1=0"]; // placeholder — سنبني ديناميكياً حسب f_field
      if ($f_field === 'all' || $f_field === 'description') {
        $where[] = "(description_en IS NOT NULL AND TRIM(description_en) != '' AND (description_ar IS NULL OR TRIM(description_ar) = ''))";
      }
      if ($f_field === 'all' || $f_field === 'category') {
        $where[] = "(category IS NOT NULL AND TRIM(category) != '' AND (category_ar IS NULL OR TRIM(category_ar) = ''))";
      }
      if ($f_field === 'all' || $f_field === 'sub_category') {
        $where[] = "(sub_category IS NOT NULL AND TRIM(sub_category) != '' AND (sub_category_ar IS NULL OR TRIM(sub_category_ar) = ''))";
      }
    } elseif ($f_state === 'suspicious') {
      // ترجمة فيها حروف غير عربية (فارسية/صينية/لاتينية مختلطة)
      $where[] = "((description_ar REGEXP '[ؠ-ۿ]' AND description_ar NOT REGEXP '[ا-ي]')
                    OR (category_ar REGEXP '[ؠ-ۿ]' AND category_ar NOT REGEXP '[ا-ي]')
                    OR (sub_category_ar REGEXP '[ؠ-ۿ]' AND sub_category_ar NOT REGEXP '[ا-ي]'))";
    }

    if ($f_date === 'today') {
      $where[] = "DATE(translated_at) = CURDATE()";
    } elseif ($f_date === 'week') {
      $where[] = "translated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($f_date === 'month') {
      $where[] = "translated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    } elseif ($f_state !== 'pending') {
      $where[] = "translated_at IS NOT NULL";
    }

    if ($f_q !== '') {
      $where[] = "(item_no LIKE ? OR description_en LIKE ? OR description_ar LIKE ? OR category LIKE ? OR category_ar LIKE ?)";
      $like = '%' . $f_q . '%';
      array_push($params, $like, $like, $like, $like, $like);
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // عدّاد + نتائج
    $totalSql = "SELECT COUNT(*) FROM nupco_catalog $whereSql";
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total / $f_per));

    $sql = "SELECT id, item_no, description_en, description_ar, category, category_ar,
                   sub_category, sub_category_ar, translated_at
            FROM nupco_catalog
            $whereSql
            ORDER BY translated_at DESC, id DESC
            LIMIT $f_per OFFSET $f_offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // بناء query string للروابط
    $qs = http_build_query(array_filter([
      'f_field' => $f_field !== 'all' ? $f_field : null,
      'f_state' => $f_state !== 'translated' ? $f_state : null,
      'f_date'  => $f_date !== 'all' ? $f_date : null,
      'q'       => $f_q !== '' ? $f_q : null,
      'per'     => $f_per !== 50 ? $f_per : null,
    ]));
    $qsPrefix = $qs ? '?' . $qs . '&' : '?';
    ?>
    <div class="tr-card">
      <h3 style="margin: 0 0 12px; font-size: 15px; font-family: 'Tajawal', sans-serif; font-weight: 700; color: #0f172a;">
        <i class="fa-solid fa-clock-rotate-left" style="color:#0891b2;margin-inline-end:8px"></i>الترجمات
        <span style="font-size:11.5px;font-weight:600;color:#64748b;margin-inline-start:8px">
          (<?= number_format($total) ?> نتيجة)
        </span>
      </h3>

      <form method="GET" class="tr-filters">
        <div class="tr-fg">
          <label>الحقل</label>
          <select name="f_field">
            <option value="all" <?= $f_field==='all'?'selected':'' ?>>الكل</option>
            <option value="description" <?= $f_field==='description'?'selected':'' ?>>الوصف</option>
            <option value="category" <?= $f_field==='category'?'selected':'' ?>>الفئة</option>
            <option value="sub_category" <?= $f_field==='sub_category'?'selected':'' ?>>الفئة الفرعية</option>
          </select>
        </div>
        <div class="tr-fg">
          <label>الحالة</label>
          <select name="f_state">
            <option value="translated" <?= $f_state==='translated'?'selected':'' ?>>✅ مُترجَم</option>
            <option value="pending" <?= $f_state==='pending'?'selected':'' ?>>⏳ بالانتظار</option>
            <option value="suspicious" <?= $f_state==='suspicious'?'selected':'' ?>>⚠️ ترجمة مشكوك فيها</option>
            <option value="all" <?= $f_state==='all'?'selected':'' ?>>الكل</option>
          </select>
        </div>
        <div class="tr-fg">
          <label>الفترة</label>
          <select name="f_date">
            <option value="all" <?= $f_date==='all'?'selected':'' ?>>الكل</option>
            <option value="today" <?= $f_date==='today'?'selected':'' ?>>اليوم</option>
            <option value="week" <?= $f_date==='week'?'selected':'' ?>>هذا الأسبوع</option>
            <option value="month" <?= $f_date==='month'?'selected':'' ?>>هذا الشهر</option>
          </select>
        </div>
        <div class="tr-fg" style="flex:1;min-width:180px">
          <label>بحث</label>
          <input type="text" name="q" value="<?= e($f_q) ?>" placeholder="ابحث في item_no أو الوصف...">
        </div>
        <div class="tr-fg">
          <label>لكل صفحة</label>
          <select name="per">
            <option value="20" <?= $f_per===20?'selected':'' ?>>20</option>
            <option value="50" <?= $f_per===50?'selected':'' ?>>50</option>
            <option value="100" <?= $f_per===100?'selected':'' ?>>100</option>
            <option value="200" <?= $f_per===200?'selected':'' ?>>200</option>
          </select>
        </div>
        <button type="submit" class="tr-btn"><i class="fa-solid fa-filter"></i> تطبيق</button>
      </form>

      <?php if (empty($rows)): ?>
        <div style="text-align:center;padding:32px;color:#94a3b8;font-family:'Tajawal',sans-serif">
          <i class="fa-solid fa-inbox" style="font-size:36px;display:block;margin-bottom:10px;color:#cbd5e1"></i>
          <?php if ($f_state === 'pending'): ?>
            لا توجد عناصر بالانتظار حسب الفلاتر المحددة
          <?php elseif ($f_state === 'suspicious'): ?>
            ممتاز! لا توجد ترجمات مشكوك فيها 🎉
          <?php else: ?>
            لا توجد ترجمات. شغّل دفعة من الأعلى.
          <?php endif; ?>
        </div>
      <?php else: ?>
      <div style="overflow-x:auto">
        <table class="tr-t">
          <thead>
            <tr>
              <th style="width:130px">Item No</th>
              <th>الإنجليزية (EN)</th>
              <th>العربية (AR)</th>
              <th>الفئة</th>
              <th style="width:90px">الوقت</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $descAr = $r['description_ar'] ?? '';
              $warnScript = $descAr !== '' && (preg_match('/[ؠ-ۿ]/u', $descAr) && !preg_match('/[ا-ي]/u', $descAr));
            ?>
              <tr>
                <td>
                  <span class="tr-item-no"><?= e($r['item_no']) ?></span>
                </td>
                <td>
                  <div class="tr-en"><?= e(mb_substr($r['description_en'] ?? '—', 0, 70)) ?><?= mb_strlen($r['description_en'] ?? '') > 70 ? '…' : '' ?></div>
                </td>
                <td>
                  <div class="tr-ar <?= $warnScript ? 'warn' : '' ?>" dir="rtl">
                    <?= e(mb_substr($descAr ?: '—', 0, 70)) ?><?= mb_strlen($descAr) > 70 ? '…' : '' ?>
                    <?php if ($warnScript): ?>
                      <i class="fa-solid fa-triangle-exclamation" title="قد تحتوي على حروف غير عربية"></i>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="tr-cat"><?= e(mb_substr($r['category_ar'] ?? $r['category'] ?? '—', 0, 40)) ?></div>
                </td>
                <td>
                  <div class="tr-meta"><?= e($r['translated_at'] ? mb_substr($r['translated_at'], 11, 5) : '—') ?></div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
      <div class="tr-pager">
        <?php
          $base = $qsPrefix;
          $prev = max(1, $f_page - 1);
          $next = min($total_pages, $f_page + 1);
        ?>
        <a href="<?= $base ?>page=1" class="tr-pg-btn <?= $f_page===1?'disabled':'' ?>"><i class="fa-solid fa-angles-right"></i></a>
        <a href="<?= $base ?>page=<?= $prev ?>" class="tr-pg-btn <?= $f_page===1?'disabled':'' ?>"><i class="fa-solid fa-chevron-right"></i></a>
        <span class="tr-pg-info">صفحة <strong><?= $f_page ?></strong> من <strong><?= $total_pages ?></strong></span>
        <a href="<?= $base ?>page=<?= $next ?>" class="tr-pg-btn <?= $f_page===$total_pages?'disabled':'' ?>"><i class="fa-solid fa-chevron-left"></i></a>
        <a href="<?= $base ?>page=<?= $total_pages ?>" class="tr-pg-btn <?= $f_page===$total_pages?'disabled':'' ?>"><i class="fa-solid fa-angles-left"></i></a>
        <span class="tr-pg-range">
          عرض <?= number_format($f_offset + 1) ?>–<?= number_format(min($f_offset + $f_per, $total)) ?> من <?= number_format($total) ?>
        </span>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="tr-card">
      <h3 style="margin: 0 0 8px; font-size: 15px; font-family: 'Tajawal', sans-serif; font-weight: 700; color: #0f172a;"><i class="fa-solid fa-circle-info" style="color:#0891b2;margin-inline-end:8px"></i>ملاحظات</h3>
      <ul style="margin: 0; padding-right: 20px; color: #475569; font-size: 13px; line-height: 1.7;">
        <li>كل دفعة = 30 صف افتراضياً (لتقليل وقت الاستجابة)</li>
        <li>"تشغيل كل الدفعات" يستمر تلقائياً حتى انتهاء كل الصفوف في الانتظار</li>
        <li>كل ترجمة تُسجَّل في <code>nupco_translation_log</code> للمراجعة</li>
        <li>الموديل: <code>llama-3.3-70b-versatile</code> (الأفضل للترجمة الطبية حالياً)</li>
        <li>الترجمة تحافظ على: الأرقام، الوحدات، الأسماء التجارية، الأكواد</li>
      </ul>
    </div>
  </div>
</div>
  </main>
</div><!-- /.main-area -->

<script>
const log = (msg) => {
  const el = document.getElementById('tr-log');
  const ts = new Date().toLocaleTimeString('ar-SA');
  el.textContent += '\n[' + ts + '] ' + msg;
  el.scrollTop = el.scrollHeight;
};

const updateStats = async () => {
  try {
    const r = await fetch('?action=stats');
    const j = await r.json();
    const total = parseInt(j.total_rows || 0);
    const done = parseInt(j.desc_translated || 0);
    const pct = total > 0 ? (100 * done / total).toFixed(1) : 0;
    document.getElementById('tr-fill').style.width = pct + '%';
    document.getElementById('tr-pct').textContent = pct + '%';
    // تحديث البطاقات
    const cards = document.querySelectorAll('#tr-stats .tr-stat .v');
    if (cards.length >= 4) {
      cards[0].textContent = total.toLocaleString();
      cards[1].textContent = done.toLocaleString();
      cards[2].textContent = parseInt(j.desc_pending || 0).toLocaleString();
      cards[3].textContent = pct + '%';
    }
    return j;
  } catch (e) { return null; }
};

const getFields = () => {
  const f = [];
  if (document.getElementById('f-desc').checked) f.push('description');
  if (document.getElementById('f-cat').checked) f.push('category');
  if (document.getElementById('f-sub').checked) f.push('sub_category');
  return f;
};

const runBatch = async () => {
  const btn = document.getElementById('btn-run');
  const btnAll = document.getElementById('btn-run-all');
  btn.disabled = btnAll.disabled = true;
  const fields = getFields();
  const batchSize = parseInt(document.getElementById('batch-size').value);
  if (fields.length === 0) {
    log('⚠️ لم يتم اختيار أي حقل');
    btn.disabled = btnAll.disabled = false;
    return;
  }
  log('▶ تشغيل دفعة: ' + fields.join(', ') + ' (حجم=' + batchSize + ')');
  const fd = new FormData();
  fd.append('action', 'run');
  fields.forEach(f => fd.append('fields[]', f));
  fd.append('batch_size', batchSize);
  try {
    const r = await fetch('?action=run', { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { log('❌ خطأ: ' + (j.error || 'unknown')); }
    else {
      log('  ✓ تمت الترجمة: ' + (j.translated || 0) + ' | تم تخطيها: ' + (j.skipped || 0));
      if (j.details) {
        for (const k in j.details) {
          if (j.details[k].error) log('  ⚠️ ' + k + ': ' + j.details[k].error);
          else if (j.details[k].count === 0) log('  · ' + k + ': لا يوجد بالانتظار');
          else log('  · ' + k + ': ok=' + (j.details[k].ok||0) + ' skip=' + (j.details[k].skipped||0));
        }
      }
    }
    await updateStats();
  } catch (e) { log('❌ فشل الاتصال: ' + e.message); }
  btn.disabled = btnAll.disabled = false;
};

const runAll = async () => {
  const btnAll = document.getElementById('btn-run-all');
  if (!confirm('سيتم تشغيل كل الدفعات حتى الانتهاء. قد يستغرق وقتاً طويلاً. متابعة؟')) return;
  btnAll.disabled = true;
  let safety = 200;  // حد أمان: 200 دفعة × 30 = 6000 صف
  let empty_runs = 0;
  while (safety-- > 0) {
    const stats = await updateStats();
    const pending = parseInt(stats?.desc_pending || 0);
    if (pending === 0 && empty_runs > 0) {
      log('✅ انتهت كل الترجمات!');
      break;
    }
    if (pending === 0) { empty_runs++; continue; }
    empty_runs = 0;
    await runBatch();
    // راحة 1.5 ثانية لتفادي rate limit
    await new Promise(r => setTimeout(r, 1500));
  }
  if (safety <= 0) log('⚠️ وصلنا لحد الأمان (200 دفعة). أعد التشغيل للمتابعة.');
  btnAll.disabled = false;
};

document.getElementById('btn-run').addEventListener('click', runBatch);
document.getElementById('btn-run-all').addEventListener('click', runAll);
</script>
</body>
</html>
