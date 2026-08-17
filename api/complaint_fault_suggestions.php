<?php
/**
 * api/complaint_fault_suggestions.php — اقتراحات Groq AI وإحصاءات FDA
 * (تم تأمين الاتصال وإلغاء الأرقام التقديرية بناءً على المعايير الطبية)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
page_guard('complaints.create');
header('Content-Type: application/json; charset=utf-8');

function send(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

$asset_id = (int) ($_POST['asset_id'] ?? 0);
if (get_setting('complaint_ai_suggestions', '1') !== '1') {
    send(['ai' => [], 'fda_stats' => null, 'debug' => 'disabled']);
}
if ($asset_id < 1) send(['ai' => [], 'fda_stats' => null, 'debug' => 'No asset ID']);

$stmt = $pdo->prepare("SELECT description, en_name, manufacturer_name, model_number FROM assets WHERE id=? LIMIT 1");
$stmt->execute([$asset_id]);
$info = $stmt->fetch();
if (!$info) send(['ai' => [], 'fda_stats' => null, 'debug' => 'Asset not found']);

$desc = trim($info['description'] ?? '');
$en_name = trim($info['en_name'] ?? '');
$manuf = trim($info['manufacturer_name'] ?? '');
$model = trim($info['model_number'] ?? '');

$suggestions = [];
$debug_info = '';
$key = ai_key();

// 1. الذكاء الاصطناعي (مع إزالة سطر تعطيل SSL)
if ($key) {
    $d = $en_name ?: $desc;
    if ($manuf) $d .= ' - ' . $manuf;
    if ($model) $d .= ' (' . $model . ')';

    $prompt = "Medical/hospital device: $d\nList 5 common technical faults.\nFor each fault give:\n- ar: Arabic fault description, under 45 characters\n- en: 1-3 English words\nRespond JSON ONLY: {\"faults\":[{\"ar\":\"...\",\"en\":\"...\"}]}";

    $ch = curl_init(ai_base_url() . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        // تمت إزالة CURLOPT_SSL_VERIFYPEER => false 
        CURLOPT_POSTFIELDS => json_encode([
            'model' => ai_model(),
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.2,
            'max_tokens' => 300,
            'response_format' => ['type' => 'json_object'],
        ]),
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    ]);
    
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $res) {
        $data = json_decode($res, true);
        if (isset($data['choices'][0]['message']['content'])) {
            $txt = $data['choices'][0]['message']['content'];
            $r = json_decode(preg_replace('/```json|```/', '', $txt), true);
            $suggestions = $r['faults'] ?? [];
        }
    }
}

if (count($suggestions) < 3) {
    // الاحتياط: حتى لو Groq وقع (مفتاح انتهى، نت خرب، إلخ) ما نخلي المُبلِّغ بدون أي اقتراح
    // إن وُجد بلاغ واحد سابق على هذا الجهاز نعرضه بدل شاشة "لا توجد اقتراحات"
    $cq = $pdo->prepare("SELECT fault_text AS ar, fault_text_en AS en FROM asset_fault_suggestions WHERE asset_id=? ORDER BY usage_count DESC, created_at DESC LIMIT 8");
    $cq->execute([$asset_id]);
    $cached = $cq->fetchAll();
    if (count($cached) >= 1) {
        $suggestions = $cached;
    }
}

// 2. إحصاءات FDA (تصحيح منطق الخطة البديلة)
$fda_stats = ['total' => 0, 'malfunction' => null, 'injury_death' => null, 'is_fallback' => false];
$fda_debug = '';
$raw_parts = [];

if (!empty($manuf)) { $raw_parts[] = 'device.manufacturer_d_name:"' . trim($manuf) . '"'; }
if (!empty($model)) { $raw_parts[] = 'device.model_number:"' . trim($model) . '"'; }
if (empty($raw_parts) && !empty($en_name)) { $raw_parts[] = 'device.generic_name:"' . trim($en_name) . '"'; }

if (!empty($raw_parts)) {
    $raw_search = implode(' AND ', $raw_parts);
    $search_query = rawurlencode($raw_search);
    
    $fda_url = "https://api.fda.gov/device/event.json?search={$search_query}&count=event_type.exact";

    $ch_fda = curl_init($fda_url);
    curl_setopt_array($ch_fda, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        // تمت إزالة CURLOPT_SSL_VERIFYPEER => false 
    ]);
    $fda_res = curl_exec($ch_fda);
    $fda_code = curl_getinfo($ch_fda, CURLINFO_HTTP_CODE);
    
    if ($fda_code === 200 && $fda_res) {
        $fda_data = json_decode($fda_res, true);
        if (!empty($fda_data['results'])) {
            $fda_stats['malfunction'] = 0; // إعادة تعيينها للرقم الحقيقي
            $fda_stats['injury_death'] = 0;
            foreach ($fda_data['results'] as $term) {
                $count = (int) $term['count'];
                $fda_stats['total'] += $count;
                
                $t = strtolower($term['term']);
                if (strpos($t, 'malfunction') !== false) {
                    $fda_stats['malfunction'] += $count;
                } elseif (strpos($t, 'injury') !== false || strpos($t, 'death') !== false) {
                    $fda_stats['injury_death'] += $count;
                }
            }
        }
    } else {
        // الخطة البديلة: جلب العدد الإجمالي الحقيقي (بدون أي أرقام تقديرية)
        $fallback_url = "https://api.fda.gov/device/event.json?search={$search_query}&limit=1";
        curl_setopt($ch_fda, CURLOPT_URL, $fallback_url);
        $fb_res = curl_exec($ch_fda);
        $fb_code = curl_getinfo($ch_fda, CURLINFO_HTTP_CODE);
        
        if ($fb_code === 200 && $fb_res) {
            $fb_data = json_decode($fb_res, true);
            if (isset($fb_data['meta']['results']['total'])) {
                $fda_stats['total'] = (int)$fb_data['meta']['results']['total'];
                $fda_stats['is_fallback'] = true; // علامة للواجهة لعدم عرض التفاصيل
                $fda_debug = "Used Fallback. Primary Code: $fda_code";
            }
        } else {
            $fda_debug = "FDA completely failed. Fallback HTTP: $fb_code";
        }
    }
    curl_close($ch_fda);
}

send([
    'ai' => array_values($suggestions),
    'fda_stats' => $fda_stats['total'] > 0 ? $fda_stats : null,
    'debug' => $fda_debug
]);