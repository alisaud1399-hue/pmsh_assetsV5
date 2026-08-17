<?php
/**
 * installation/form.php — نموذج محضر التركيب والتشغيل
 * تصميم: بطاقة "بيانات المحضر" مرجعية للقراءة فقط (مصدرها محضر الاستلام مباشرة وبشكل حي
 * على كل تحميل صفحة) + بطاقة "بيانات الجهاز الفنية" و"التوثيق" يُدخلها فريق الصيانة فقط.
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('installation.form');

function gregorianToHijri($ymd){
    if(!$ymd) return null;
    [$y,$m,$d]=array_map('intval',explode('-',$ymd));
    $jd = (int) floor((1461 * ($y + 4800 + floor(($m - 14) / 12))) / 4)
        + (int) floor((367 * ($m - 2 - 12 * floor(($m - 14) / 12))) / 12)
        - (int) floor((3 * floor(($y + 4900 + floor(($m - 14) / 12)) / 100)) / 4)
        + $d - 32075;
    $l = $jd - 1948440 + 10632;
    $n = (int) floor(($l - 1) / 10631);
    $l = $l - 10631 * $n + 354;
    $j = (int) floor((10985 - $l) / 5316) * (int) floor((50 * $l) / 17719)
        + (int) floor($l / 5670) * (int) floor((43 * $l) / 18199);
    $l = $l - (int) floor((30 - $j) / 15) * (int) floor((17719 * $j) / 50)
        - (int) floor($j / 16) * (int) floor((18199 * $j) / 43) + 29;
    $hm = (int) floor((24 * $l) / 709);
    $hd = (int) ($l - floor((709 * $hm) / 24));
    $hy = (int) (30 * $n + $j - 30);
    $months=['محرم','صفر','ربيع الأول','ربيع الآخر','جمادى الأولى','جمادى الآخرة','رجب','شعبان','رمضان','شوال','ذو القعدة','ذو الحجة'];
    return $hd.' '.$months[$hm-1].' '.$hy.'هـ';
}

/**
 * تُستدعى مرة واحدة فقط عند أول اعتماد لشهادة تركيب/تشغيل (action=submit).
 *
 * ✅ FIX 2026-08-04 (Plan A): شهادة واحدة = جهاز واحد = أصل واحد.
 *    بدل ما كانت الشهادة تغطي كل أجهزة القسم (وتنشئ N أصل بنفس tag/serial) —
 *    الآن كل شهادة مرتبطة بـ rmi_id (جهاز واحد) وتُنشئ أصل واحد فقط.
 *
 * تُثبِّت العهدة مباشرة على الشخص الذي حُدِّد وقت الاستلام
 * (receiving_minute_items.receiver_user_id) — لا وسيط ولا "قسم" كهدف
 * للعهدة، تماماً كما في custody_transfer.php.
 */
function create_assets_from_commissioning(
    PDO $pdo, int $rmi_id, string $cert_num,
    array $certFields, int $actor_id
): int {
    // جلب بيانات الشهادة (للحقول الجديدة: criticality, location, category, date)
    $cs = $pdo->prepare("SELECT criticality_class, loc_building, loc_floor, loc_room, location_id, tag_number, asset_number, cat_level1, cat_level2, cat_level3, date_placed_in_service, description_ar, receiving_minute_id, department_id FROM commissioning_certificates WHERE certificate_number=? LIMIT 1");
    $cs->execute([$cert_num]);
    $cert_extras = $cs->fetch(PDO::FETCH_ASSOC) ?: [];

    // جلب الـ rmi الواحد (مش كل rmi في القسم)
    $items = $pdo->prepare(
        "SELECT * FROM receiving_minute_items WHERE id = ? LIMIT 1"
    );
    $items->execute([$rmi_id]);
    $row = $items->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;
    $rows = [$row]; // wrap in array so we can keep the same loop structure for serials

    $dept_id = (int)$row['department_id'];
    $deptQ = $pdo->prepare("SELECT name FROM departments WHERE id=?");
    $deptQ->execute([$dept_id]);
    $dept_name = $deptQ->fetchColumn() ?: null;

    $created = 0;
    $first_aid = null; // لربط الشهادة بالأصل
    foreach ($rows as $item) {
        /* منع التكرار: هذا الصنف من هذا المحضر أُنشئ له أصل من قبل
           (إعادة حفظ شهادة سبق اعتمادها) */
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM assets
             WHERE receipt_note_id = ? AND item_code <=> ?"
        );
        $chk->execute([$minute_id, $item['item_code']]);
        if ((int)$chk->fetchColumn() > 0) continue;

        $ser = $pdo->prepare(
            "SELECT serial_number, warranty_type, warranty_years, warranty_expiry
             FROM receiving_item_serials WHERE item_id = ? ORDER BY seq_no"
        );
        $ser->execute([$item['id']]);
        $serials = $ser->fetchAll(PDO::FETCH_ASSOC);

        $qty = max(1, (int)($item['quantity'] ?? 1));
        $units = $serials ?: array_map(function ($i) use ($certFields) {
            return [
                'serial_number'   => $i === 0 ? ($certFields['serial_number'] ?? null) : null,
                'warranty_type'   => null,
                'warranty_years'  => null,
                'warranty_expiry' => $certFields['warranty_end'] ?? null,
            ];
        }, range(0, $qty - 1));

        foreach ($units as $u) {
            $mfr   = $certFields['manufacturer_name'] ?: ($item['manufacturer_name'] ?: null);
            $model = $certFields['model_number']      ?: ($item['model_number']      ?: null);
            $filled = count(array_filter(
                [$item['description'], $mfr, $model, $u['serial_number']],
                fn($v) => $v !== null && $v !== ''
            ));

            /* ✅ FIX 2026-08-04: السعر + lookup الفئة الهرمية */
            $cost = !empty($item['total_price']) ? (float)$item['total_price'] : null;
            $original_cost = $cost;

            $category_id = null; $cat_seg1 = null; $cat_seg2 = null; $cat_seg3 = null;
            if (!empty($cert_extras['cat_level1'])) {
                $cs2 = $pdo->prepare("SELECT id, segment FROM item_categories WHERE name=? AND level=1 LIMIT 1");
                $cs2->execute([$cert_extras['cat_level1']]);
                $row = $cs2->fetch(PDO::FETCH_ASSOC);
                if ($row) { $category_id = (int)$row['id']; $cat_seg1 = (int)$row['segment']; }
            }
            if ($category_id && !empty($cert_extras['cat_level2'])) {
                $cs2 = $pdo->prepare("SELECT id, segment FROM item_categories WHERE parent_id=? AND name=? LIMIT 1");
                $cs2->execute([$category_id, $cert_extras['cat_level2']]);
                $row = $cs2->fetch(PDO::FETCH_ASSOC);
                if ($row) $cat_seg2 = (int)$row['segment'];
            }
            if ($cat_seg2 && !empty($cert_extras['cat_level3'])) {
                $cs2 = $pdo->prepare("SELECT id, segment FROM item_categories
                                       WHERE parent_id=(SELECT id FROM item_categories
                                                        WHERE parent_id=? AND name=?)
                                         AND name=? LIMIT 1");
                $cs2->execute([$category_id, $cert_extras['cat_level2'], $cert_extras['cat_level3']]);
                $row = $cs2->fetch(PDO::FETCH_ASSOC);
                if ($row) $cat_seg3 = (int)$row['segment'];
            }

            /* ✅ FIX 2026-08-04: data_completeness logic = نفس commissioning
               مبني على tag_number + asset_number (مو على count of fields) */
            $asset_number_val = trim($cert_extras['asset_number'] ?? '');
            $tag_for_check    = trim($cert_extras['tag_number'] ?? '');
            $has_full_id      = ($tag_for_check !== '' && $asset_number_val !== '');
            $completeness     = $has_full_id ? 'complete' : 'partial';
            $verified_status  = $has_full_id ? 'تم التحقق' : 'لم يتم التحقق بعد';
            $verified_at      = $has_full_id ? date('Y-m-d H:i:s') : null;
            $verified_by      = $has_full_id ? $actor_id : null;
            $priority_val     = $has_full_id ? null : 'medium';
            $due_at_val       = $has_full_id ? null : date('Y-m-d', strtotime('+30 days'));

            /* القاعدة الذهبية: لا عهدة شخصية بلا شخص فعلي. إن لم يُحدَّد
               مستلم وقت الاستلام، تبقى العهدة (النوع/الشخص/القسم) غير
               مُثبَّتة عمداً — الأصل يُنشأ وبياناته تكتمل، لكنه يظهر
               تلقائياً في تبويب "النقل اليدوي" بشاشة نقل العهد بدل
               تثبيت "شخصية" فارغة تكسر الافتراض في كل شاشة أخرى. */
            $has_recipient   = !empty($item['receiver_user_id']);
            $custodian_type  = $has_recipient ? 'personal' : null;
            $custodian_uid   = $has_recipient ? (int)$item['receiver_user_id'] : null;
            $custodian_uname = $has_recipient ? $item['receiver_name'] : null;
            $custodian_dept  = $has_recipient ? $dept_id   : null;
            $custodian_dname = $has_recipient ? $dept_name : null;
            $custody_date_val   = $has_recipient ? date('Y-m-d') : null;
            $custody_reason_val = $has_recipient
                ? 'استلام أصل جديد — شهادة تركيب وتشغيل رقم ' . $cert_num
                : null;

            $pdo->prepare(
                "INSERT INTO assets
                    (description, description_ar, asset_type, criticality_class,
                     item_code, generic_code, unit, nupco_category, nupco_sub_category,
                     manufacturer_name, model_number, serial_number, tag_number, asset_number,
                     department_id,
                     custodian_type, custodian_user_id, custodian_name,
                     custodian_dept_id, custodian_dept_name,
                     custody_date, custody_reason,
                     loc_building, loc_floor, loc_room, location_id,
                     cat_level1, cat_level2, cat_level3,
                     category_id, cat_seg1, cat_seg2, cat_seg3,
                     status, date_placed_in_service,
                     warranty_expiry, warranty_type, warranty_years,
                     cost, original_cost,
                     verified_status, verified_at, verified_by,
                     receipt_note_id, data_completeness, incomplete_data,
                     completion_priority, completion_due_at, completion_notes,
                     created_by, created_at)
                 VALUES (?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?, ?,?,?, ?,?, ?,?,
                         ?,?,?,?, ?,?,?, ?,?,?, ?, 'pending_govt_registration',?,
                         ?,?,?, ?,?,?, ?,?,?, ?,?,?, ?, ?, ?, NOW())"
            )->execute([
                $item['description'],
                $cert_extras['description_ar'] ?? $item['description_ar'],
                $item['asset_type'] ?: 'medical',
                $cert_extras['criticality_class'] ?? $item['criticality_class'] ?: 'C',
                $item['item_code'], $item['generic_code'],
                $item['unit'] ?: 'جهاز',
                $item['category'] ?? null, $item['sub_category'] ?? null,
                $mfr, $model, $u['serial_number'],
                $cert_extras['tag_number'] ?? null, $asset_number_val ?: null,
                $dept_id,
                $custodian_type, $custodian_uid, $custodian_uname,
                $custodian_dept, $custodian_dname,
                $custody_date_val, $custody_reason_val,
                $cert_extras['loc_building'] ?? null,
                $cert_extras['loc_floor'] ?? null,
                $cert_extras['loc_room'] ?? null,
                $cert_extras['location_id'] ?: null,
                $cert_extras['cat_level1'] ?? null,
                $cert_extras['cat_level2'] ?? null,
                $cert_extras['cat_level3'] ?? null,
                $category_id, $cat_seg1, $cat_seg2, $cat_seg3,
                $cert_extras['date_placed_in_service'] ?: ($certFields['warranty_start'] ?: date('Y-m-d')),
                $u['warranty_expiry'], $u['warranty_type'], $u['warranty_years'],
                $cost, $original_cost,
                $verified_status, $verified_at, $verified_by,
                $minute_id, $completeness, $completeness === 'complete' ? 0 : 1,
                $priority_val, $due_at_val,
                'منشأ من شهادة تركيب #' . $cert_num
                    . ($cert_extras['tag_number'] ? '' : ' — بانتظار تاج/رقم أصل من موارد'),
                $actor_id,
            ]);
            $aid = (int)$pdo->lastInsertId();
            if ($first_aid === null) $first_aid = $aid;
            refresh_asset_completion($pdo, $aid, 'installation.form (اعتماد شهادة #' . $cert_num . ')');

            /* سجل العهد الابتدائي — فقط إن وُجد مستلم فعلي؛ لا قيد لعهدة
               لم تُثبَّت أصلاً (يطابق نفس القاعدة أعلاه تماماً) */
            if ($has_recipient) {
                $pdo->prepare(
                    "INSERT INTO asset_custody_log
                        (asset_id, from_type, from_user_id, from_dept_id,
                         to_type, to_user_id, to_dept_id,
                         custody_date, reason, created_by)
                     VALUES (?, NULL, NULL, NULL, 'personal', ?, ?, CURDATE(), ?, ?)"
                )->execute([
                    $aid, $custodian_uid, $dept_id,
                    'عهدة ابتدائية عند اعتماد شهادة التركيب رقم ' . $cert_num,
                    $actor_id,
                ]);
            }

            $created++;
        }
    }

    // ═══ ربط الشهادة بالأصل المُنشأ (Source of Truth للطباعة + الجرد) ═══
    if ($first_aid) {
        // ✅ FIX 2026-08-04: أيضاً نحفظ receiving_minute_item_id (خطة A)
        $pdo->prepare("UPDATE commissioning_certificates SET asset_id=?, receiving_minute_item_id=?, transferred_at=NOW() WHERE certificate_number=?")
            ->execute([$first_aid, $rmi_id, $cert_num]);
    }

    return $created;
}

$id = (int)($_GET['id'] ?? 0);
$minute_id = (int)($_GET['minute_id'] ?? 0);
$dept_id = (int)($_GET['department_id'] ?? 0);
$rmi_id = (int)($_GET['rmi_id'] ?? 0);  // ✅ FIX 2026-08-04: Plan A — rmi_id للجهاز الواحد
$edit = $id > 0;

$cert = []; $device = []; $minute = [];
$dept_name = '';

// جلب الشهادة إذا كان موجودة
if ($edit) {
    $s = $pdo->prepare("SELECT * FROM commissioning_certificates WHERE id=?");
    $s->execute([$id]);
    $cert = $s->fetch() ?: [];
    if (!$cert) { flash('danger', 'الشهادة غير موجودة'); header('Location: '.BASE_URL); exit; }
    $minute_id = $cert['receiving_minute_id'];
    $dept_id = $cert['department_id'];
    // ✅ FIX 2026-08-04: استرجع rmi_id من الشهادة (العمود الجديد)
    $rmi_id = (int)($cert['receiving_minute_item_id'] ?? 0);
    if ($rmi_id === 0) { die('خطأ: الشهادة لا ترتبط بجهاز (receiving_minute_item_id مفقود). أعد إصدار الشهادة.'); }
} else {
    // ✅ FIX 2026-08-04: rmi_id إلزامي (خطة A - 1 شهادة لكل جهاز)
    if (!$rmi_id) { die('خطأ: مطلوب رقم الجهاز (rmi_id) لإصدار الشهادة.'); }
    // استرجع dept_id من الـ rmi (للتوافق البصري)
    $rs = $pdo->prepare("SELECT minute_id, department_id FROM receiving_minute_items WHERE id=?");
    $rs->execute([$rmi_id]);
    $row = $rs->fetch(PDO::FETCH_ASSOC);
    if (!$row) { die('خطأ: الجهاز غير موجود.'); }
    $minute_id = $row['minute_id'];
    $dept_id = $row['department_id'];
}

// جلب بيانات محضر الاستلام الأصلي
$s = $pdo->prepare("SELECT * FROM receiving_minutes WHERE id=?");
$s->execute([$minute_id]);
$minute = $s->fetch() ?: [];
if (!$minute) die('خطأ: المحضر المذكور غير موجود.');

// 🔒 الحاجز الحقيقي: هل فريق صيانة المستخدم الحالي يطابق نوع هذا الجهاز؟
// نفس منطق is_my_maintenance_type() الذي يحجب الزر في receiving/view.php —
// هنا مُطبَّق فعلياً على كل GET/POST لهذه الصفحة، لا اعتماداً على إخفاء
// الزر وحده. لا استثناء حتى لصاحب صلاحية إنشاء/تعديل عامة أخرى.
$rep_type_q = $pdo->prepare(
    "SELECT asset_type FROM receiving_minute_items
     WHERE minute_id=? AND department_id=?
       AND (parent_item_id IS NULL OR parent_item_id=0)
     LIMIT 1"
);
$rep_type_q->execute([$minute_id, $dept_id]);
$rep_asset_type = $rep_type_q->fetchColumn() ?: 'medical';
if (!is_my_maintenance_type($rep_asset_type)) {
    abort(403);
}

// جلب مرفقات المحضر الأصلي
$att_s = $pdo->prepare("SELECT * FROM receiving_minute_attachments WHERE minute_id=?");
$att_s->execute([$minute_id]);
$minute_attachments = $att_s->fetchAll();

// جلب اسم القسم
$s = $pdo->prepare("SELECT name FROM departments WHERE id=?");
$s->execute([$dept_id]);
$dept_name = $s->fetchColumn();

// ═══ تحميل المواقع من item_locations (مصدر موحّد للنظام) ═══
// ترتيب هرمي: building (parent=NULL) → floor (parent=building) → room (parent=floor)
$locations_by_type = ['building'=>[], 'floor'=>[], 'room'=>[]];
$loc_st = $pdo->query("SELECT id, parent_id, name, name_en, location_type FROM item_locations WHERE is_active=1 ORDER BY location_type, name");
while ($r = $loc_st->fetch(PDO::FETCH_ASSOC)) {
    $locations_by_type[$r['location_type']][] = $r;
}
// بناء قاموس: parent_id → children (للقوائم المتتابعة)
$loc_children = [];
foreach ($locations_by_type as $type => $rows) {
    foreach ($rows as $r) {
        if ($r['parent_id']) {
            $loc_children[(int)$r['parent_id']][] = $r;
        }
    }
}
$loc_by_id = [];
foreach ($locations_by_type as $type => $rows) {
    foreach ($rows as $r) $loc_by_id[(int)$r['id']] = $r;
}
// اسم موقع بالـ id (للعرض)
function loc_label_by_id($id, $loc_by_id) {
    if (!$id) return null;
    $r = $loc_by_id[(int)$id] ?? null;
    return $r ? ($r['name_en'] ?: $r['name']) : null;
}

// ═══ تحميل الفئات من item_categories (هرمية 3 مستويات) ═══
$cats_by_level = [1=>[], 2=>[], 3=>[]];
$cat_st = $pdo->query("SELECT id, name, name_en, level, parent_id, asset_type FROM item_categories WHERE is_active=1 ORDER BY level, sort_order, name");
while ($r = $cat_st->fetch(PDO::FETCH_ASSOC)) {
    $cats_by_level[(int)$r['level']][] = $r;
}
$cat_by_id = [];
foreach ($cats_by_level as $lv => $rows) {
    foreach ($rows as $r) $cat_by_id[(int)$r['id']] = $r;
}
// اسم فئة بالمسار الكامل (L1 → L2 → L3) للعرض في القوائم
function cat_full_path($id, $cat_by_id) {
    if (!$id) return null;
    $parts = [];
    $cur_id = (int)$id;
    while ($cur_id && isset($cat_by_id[$cur_id])) {
        $r = $cat_by_id[$cur_id];
        array_unshift($parts, $r['name']); // العربي
        $cur_id = (int)($r['parent_id'] ?? 0);
    }
    return $parts ? implode(' › ', $parts) : null;
}

// بيانات "بطاقة المحضر" — قراءة حيّة دائماً من محضر الاستلام عند كل تحميل صفحة،
// لا تُخزَّن أبداً على المحضر؛ أي تصحيح يجريه الإمداد على المحضر الأصلي ينعكس هنا فوراً.
$s = $pdo->prepare("
    SELECT id, item_code, generic_code, description, description_ar,
        manufacturer_name, model_number, quantity,
        manuals_operation, manuals_maintenance, cd_count
    FROM receiving_minute_items
    WHERE minute_id=? AND department_id=? AND (parent_item_id IS NULL OR parent_item_id=0)
    LIMIT 1
");
$s->execute([$minute_id, $dept_id]);
$device = $s->fetch() ?: [];

// سنوات الضمان الإجمالية لهذا الجهاز/القسم بالتحديد فقط — معزولة عبر parent_item_id
// الخاص بصف هذا القسم وحده (أساسي + كل الإضافات)، لا تُجمَع مطلقاً مع أقسام أخرى من نفس المحضر.
$device['warranty_years'] = 0;
if (!empty($device['id'])) {
    $ws = $pdo->prepare("
        SELECT COALESCE(SUM(quantity), 0) FROM receiving_minute_items
        WHERE parent_item_id = ? AND item_role = 'warranty'
    ");
    $ws->execute([$device['id']]);
    $device['warranty_years'] = (float) $ws->fetchColumn();
}

// السيريالات لا تزال تُدخَل من فريق الصيانة لاحقاً في هذه الصفحة، لا من محضر الاستلام
$device['serial_number'] = $device['serial_number'] ?? '';

// قاموس الشركات
$mfrs_raw = $pdo->query("
    SELECT m.id AS mfr_id, m.name AS mfr_name, md.model_number 
    FROM manufacturers m 
    LEFT JOIN manufacturer_models md ON m.id = md.manufacturer_id
    ORDER BY m.name, md.model_number
")->fetchAll(PDO::FETCH_ASSOC);

$mfr_dict = [];
foreach($mfrs_raw as $r) {
    $m = trim($r['mfr_name']); $mod = trim($r['model_number']);
    if($m === '') continue;
    if(!isset($mfr_dict[$m])) $mfr_dict[$m] = [];
    if($mod !== '' && !in_array($mod, $mfr_dict[$m])) $mfr_dict[$m][] = $mod;
}

// معالجة الحفظ (POST) — فريق الصيانة لا يُدخل سوى البيانات الفنية والتوثيق؛
// كل ما هو من المحضر (الاسم/الكمية/الضمان/الكتالوجات) لا يُحفظ هنا إطلاقاً.
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($cert['status'] ?? '') === 'approved') {
        $errors[] = 'هذا المحضر معتمد ومقفل نهائياً — لا يمكن تعديله عبر أي طلب، مهما كانت الصلاحية.';
    } elseif ($edit && !can('installation.form','edit')) {
        $errors[] = 'غير مصرح لك بتعديل هذا المحضر.';
    } elseif (!$edit && !can('installation.form','create')) {
        $errors[] = 'غير مصرح لك بإنشاء محضر تركيب جديد.';
    } elseif (!verify_csrf()) { $errors[] = 'خطأ في الجلسة (CSRF)'; }
    else {
        $mfr = trim($_POST['manufacturer_name'] ?? $device['manufacturer_name'] ?? '');
        $model = trim($_POST['model_number'] ?? $device['model_number'] ?? '');
        $sn = trim($_POST['serial_number'] ?? $device['serial_number'] ?? '');
        $tag_number = trim($_POST['tag_number'] ?? '') ?: null;
        $w_start = $_POST['warranty_start'] ?? date('Y-m-d');
        $spec_match = (int)($_POST['spec_match'] ?? 1);
        $rep_name = trim($_POST['representative_name'] ?? '');
        $exec_company = trim($_POST['executing_company_name'] ?? '');
        $reg_mgr = trim($_POST['regional_equipment_mgr_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $action = $_POST['form_action'] ?? 'draft'; // draft, print, submit

        if ($action === 'submit' && !can('installation.form','approve')) {
            $errors[] = 'غير مصرح لك باعتماد المحضر نهائياً — تحتاج صلاحية الاعتماد.';
        }

        $status = $action === 'submit' ? 'approved' : 'draft';
        if ($action === 'print') {
            $status = 'sent';
        } elseif ($action === 'draft') {
            // الحفظ المؤقت العادي لا يُرجع محضراً سابقاً "مطبوعاً" إلى الخلف
            $status = $cert['status'] ?? 'draft';
        }
        $sent_at = $cert['sent_at'] ?? null;
        if ($status === 'sent' && empty($sent_at)) { $sent_at = date('Y-m-d H:i:s'); }
        $approved_at = $cert['approved_at'] ?? null;
        if ($status === 'approved' && empty($approved_at)) { $approved_at = date('Y-m-d H:i:s'); }

        $w_start_hijri = gregorianToHijri($w_start);

        // تاريخ انتهاء الضمان = تاريخ التشغيل + إجمالي سنوات الضمان (تجميعي، من المحضر مباشرة)
        $years_total = (float)($device['warranty_years'] ?? 0);
        $warranty_end = null;
        if ($w_start && $years_total > 0) {
            try {
                $end_dt = new DateTime($w_start);
                $end_dt->modify('+' . (int) round($years_total * 12) . ' months');
                $warranty_end = $end_dt->format('Y-m-d');
            } catch (Exception $e) { $warranty_end = null; }
        }

        if (empty($mfr))      $errors[] = 'يجب إدخال الشركة الصانعة.';
        if (empty($model))    $errors[] = 'يجب إدخال الموديل.';
        if (empty($sn))       $errors[] = 'يجب إدخال الرقم التسلسلي للجهاز.';
        if (empty($w_start))  $errors[] = 'يجب إدخال تاريخ التشغيل.';
        if (empty($rep_name)) $errors[] = 'يجب إدخال اسم مندوب الشركة الموردة.';
        if (empty($exec_company)) $errors[] = 'يجب إدخال اسم الشركة/المؤسسة المنفِّذة (المقاول).';

        // ═══ الحقول الإلزامية الجديدة (تصنيف + موقع) — مطلوبة على المسودة والاعتماد
        // لأن المسودة هي اللي تنطبع للتوقيع، فلازم تكون كاملة قبل الطباعة
        if (empty($_POST['criticality_class'])) $errors[] = 'يجب تحديد فئة الحساسية (A/B/C) — إلزامي قبل الحفظ.';
        if (empty($_POST['loc_building'])) $errors[] = 'يجب تحديد المبنى (الموقع الفعلي) — إلزامي قبل الحفظ.';
        if (empty($_POST['loc_room']))     $errors[] = 'يجب تحديد الغرفة (الموقع الفعلي الدقيق) — إلزامي قبل الحفظ.';
        if (empty($_POST['location_id']))  $errors[] = 'تعذّر تحديد معرّف الموقع — أعد اختيار الغرفة من القائمة.';
        if (empty($_POST['cat_level1'])) $errors[] = 'يجب تحديد الفئة الرئيسية (L1) — إلزامي قبل الحفظ.';

        // معالجة رفع الملف المرفق (المحضر الموقّع)
        $signed_file = $cert['signed_attachment'] ?? null;
        if (!empty($_FILES['signed_copy']['name']) && $_FILES['signed_copy']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['signed_copy']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                $upload_dir = BASE_PATH . '/uploads/installation/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $safe_name = 'ic_signed_' . time() . '_' . rand(100,999) . '.' . $ext;
                if (move_uploaded_file($_FILES['signed_copy']['tmp_name'], $upload_dir . $safe_name)) {
                    $signed_file = 'installation/' . $safe_name;
                }
            } else {
                $errors[] = 'صيغة الملف المرفق غير مدعومة. يرجى رفع ملف PDF أو صورة.';
            }
        }

        if ($action === 'submit' && empty($signed_file)) {
            $errors[] = 'لا يمكن اعتماد المحضر نهائياً بدون إرفاق النسخة الموقعة والمختومة.';
        }

        if (empty($errors)) {
            // ═══ الحقول الجديدة (تصنيف + موقع) — فرعية (cat_l2/l3 + date_placed + desc_ar) تُسحب من المحضر تلقائياً ═══
            $criticality_class = $_POST['criticality_class'] ?? null;
            $loc_building      = trim($_POST['loc_building'] ?? '');
            $loc_floor         = trim($_POST['loc_floor'] ?? '');
            $loc_room          = trim($_POST['loc_room'] ?? '');
            $location_id       = (int)($_POST['location_id'] ?? 0) ?: null;
            $cat_level1        = trim($_POST['cat_level1'] ?? '');
            $cat_level2        = trim($_POST['cat_level2'] ?? '');
            $cat_level3        = trim($_POST['cat_level3'] ?? '');
            // date_placed و description_ar يأتيان من الشهادة/المحضر تلقائياً (مو حقول في الفورم)
            $date_placed       = $w_start; // = warranty_start (تاريخ التشغيل من بطاقة الجهاز الفنية)
            $description_ar    = $device['description_ar'] ?? null; // من بيانات المحضر

            if ($edit) {
                $stmt = $pdo->prepare("UPDATE commissioning_certificates SET
                    manufacturer_name=?, model_number=?, serial_number=?, tag_number=?,
                    representative_name=?, executing_company_name=?, regional_equipment_mgr_name=?, spec_match=?,
                    warranty_start=?, warranty_start_hijri=?, warranty_end=?, notes=?, signed_attachment=?,
                    status=?, sent_at=?, approved_at=?,
                    criticality_class=?, loc_building=?, loc_floor=?, loc_room=?, location_id=?,
                    cat_level1=?, cat_level2=?, cat_level3=?, date_placed_in_service=?, description_ar=?
                    WHERE id=?");
                $stmt->execute([
                    $mfr, $model, $sn, $tag_number, $rep_name, $exec_company, $reg_mgr, $spec_match,
                    $w_start, $w_start_hijri, $warranty_end, $notes, $signed_file,
                    $status, $sent_at, $approved_at,
                    $criticality_class, $loc_building, $loc_floor, $loc_room, $location_id,
                    $cat_level1, $cat_level2, $cat_level3, $date_placed, $description_ar,
                    $id
                ]);
            } else {
                $yr = date('Y');
                $seq = $pdo->query("SELECT COUNT(*)+1 FROM commissioning_certificates WHERE YEAR(created_at)=$yr")->fetchColumn();
                $cert_num = 'CC/' . $yr . '/' . str_pad($seq, 4, '0', STR_PAD_LEFT);

                $stmt = $pdo->prepare("INSERT INTO commissioning_certificates
                    (certificate_number, receiving_minute_id, receiving_minute_item_id, department_id,
                    manufacturer_name, model_number, serial_number, tag_number,
                    representative_name, executing_company_name, regional_equipment_mgr_name, spec_match,
                    warranty_start, warranty_start_hijri, warranty_end, notes, signed_attachment,
                    status, sent_at, approved_at, created_by,
                    criticality_class, loc_building, loc_floor, loc_room, location_id,
                    cat_level1, cat_level2, cat_level3, date_placed_in_service, description_ar)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $cert_num, $minute_id, $rmi_id, $dept_id, $mfr, $model, $sn, $tag_number,
                    $rep_name, $exec_company, $reg_mgr, $spec_match,
                    $w_start, $w_start_hijri, $warranty_end, $notes, $signed_file,
                    $status, $sent_at, $approved_at, current_user()['id'],
                    $criticality_class, $loc_building, $loc_floor, $loc_room, $location_id,
                    $cat_level1, $cat_level2, $cat_level3, $date_placed, $description_ar
                ]);
                $id = $pdo->lastInsertId();
            }

            if ($action === 'submit') {
                /* إنشاء الأصول وتثبيت العهدة — أول اعتماد فقط، لا عند
                   إعادة حفظ شهادة معتمدة سابقاً */
                if (($cert['status'] ?? '') !== 'approved') {
                    $n = create_assets_from_commissioning(
                        $pdo, $rmi_id,
                        $cert_num ?? ($cert['certificate_number'] ?? ''),
                        [
                            'manufacturer_name' => $mfr, 'model_number' => $model,
                            'serial_number' => $sn, 'warranty_start' => $w_start,
                            'warranty_end' => $warranty_end,
                        ],
                        current_user()['id']
                    );
                    flash('success', "تم إقفال واعتماد شهادة التركيب والتشغيل، وإنشاء $n أصلاً بعهدة مُثبَّتة.");
                } else {
                    flash('success', 'تم إقفال واعتماد شهادة التركيب والتشغيل بنجاح!');
                }
                header('Location: ' . BASE_URL . '/receiving/view.php?id=' . $minute_id);
                exit;
            } elseif ($action === 'print') {
                flash('success', 'تم حفظ البيانات. جاري فتح صفحة الطباعة...');
                header('Location: ' . BASE_URL . '/installation/form.php?id=' . $id . '&print=1');
                exit;
            } else {
                flash('success', 'تم حفظ المسودة بنجاح.');
                header('Location: ' . BASE_URL . '/installation/form.php?id=' . $id);
                exit;
            }
        }
    }
}

$p = empty($_POST) ? $cert : $_POST;
$is_approved = ($p['status'] ?? '') === 'approved';
$page_title = $edit ? ($is_approved ? 'عرض شهادة التركيب المعتمدة' : 'تجهيز شهادة التركيب والتشغيل') : 'إصدار شهادة تركيب وتشغيل جديدة';
$active_nav = 'installation.index';
$flash_msgs = get_flash();
$item_code_display = $device['item_code'] ?: ($device['generic_code'] ?? '');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.fc { background:#fff; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,.03); overflow:hidden; border:1px solid #e2e8f0; }
.fch-colored { background: linear-gradient(135deg, #1e3a8a, #2563eb); color: #ffffff; padding: 9px 14px; font-size: 13px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
.fch-colored.dark { background: linear-gradient(135deg, #0f172a, #334155); }
.fch-colored.purple { background: linear-gradient(135deg, #6d28d9, #9333ea); }
.fb { padding:14px; }
.gen-label { font-size:11.5px; font-weight:800; color:#475569; margin-bottom:4px; display:block; }
.rfi { height:32px; padding-inline:10px; border:1.5px solid #e2e8f0; border-radius:6px; font-family:'Tajawal',sans-serif; font-size:12.5px; font-weight:700; width:100%; box-sizing:border-box; color:#0f172a; transition:.2s; }
.rfi:focus { border-color:#1565C0; box-shadow: 0 0 0 3px rgba(21,101,192,0.1); outline:none;}
.rfi.readonly, .rfi[readonly] { background:#f8fafc; color:#64748b; border-color:#e2e8f0; cursor:not-allowed; }
.eng-num { font-family:'Inter', sans-serif; direction:ltr; text-align:center; }
input[type="date"].eng-num { text-align: right; }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
.grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:10px; }
.match-options { display:flex; gap:8px; }
.match-option { flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:6px 8px; border:1.5px solid #e2e8f0; border-radius:8px; cursor:pointer; background:#fff; transition:.2s; font-weight:800; font-size:12.5px; }
.match-option input { display:none; }
.match-option.ok.active { background:#f0fdf4; border-color:#16a34a; color:#16a34a; box-shadow:0 2px 4px rgba(22,163,74,0.1); }
.match-option.no.active { background:#fef2f2; border-color:#dc2626; color:#dc2626; box-shadow:0 2px 4px rgba(220,38,38,0.1); }
.bottom-action-bar { position: sticky; bottom: 0; background: linear-gradient(135deg,#0f172a,#1e293b); color:#fff; border-top: 3px solid #1565C0; padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; z-index: 1000; box-shadow: 0 -4px 15px rgba(0,0,0,0.15); margin: 18px -32px -28px -32px; }
.req { color:#dc2626; }
.upload-area { border:2px dashed #cbd5e1; border-radius:10px; padding:20px; text-align:center; background:#f8fafc; cursor:pointer; transition:.2s; position:relative; }
.upload-area:hover { border-color:#9333ea; background:#faf5ff; }
.upload-area input[type="file"] { position:absolute; top:0; left:0; width:100%; height:100%; opacity:0; cursor:pointer; }
.file-name-display { font-size:13px; font-weight:700; color:#1565C0; margin-top:10px; word-break:break-all; }
.page-layout { display:grid; grid-template-columns:360px 1fr; gap:18px; align-items:start; margin-bottom:18px; }
@media(max-width:1100px){ .page-layout { grid-template-columns:1fr; } }
.left-col > .fc + .fc { margin-top:18px; }
.minute-card { position:sticky; top:20px; }
.mc-head { background:linear-gradient(135deg,#1e3a8a,#3730a3); color:#fff; padding:11px 14px; border-radius:12px 12px 0 0; }
.mc-head .ttl { font-size:13.5px; font-weight:800; display:flex; align-items:center; gap:8px; }
.mc-head .sub { font-size:10.5px; font-weight:600; opacity:.85; margin-top:3px; display:flex; align-items:center; gap:5px; }
.mc-body { background:#fff; border:1px solid #e2e8f0; border-top:none; border-radius:0 0 12px 12px; padding:12px; }
.mc-row { margin-bottom:10px; }
.mc-row:last-child { margin-bottom:0; }
.mc-label { font-size:11.5px; color:#64748b; font-weight:700; margin-bottom:3px; display:block; }
.mc-value { font-size:14px; color:#0f172a; font-weight:800; }
.mc-divider { border-top:1px dashed #e2e8f0; margin:16px 0; }
.mc-stat-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
.mc-stat { background:#f8fafc; border-radius:8px; padding:9px; text-align:center; }
.mc-stat .n { font-family:'Inter'; font-size:18px; font-weight:700; }
.mc-stat .l { font-size:10px; color:#64748b; font-weight:700; margin-top:2px; }
.warranty-band { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 8px; display:flex; flex-direction:column; gap:8px; }
.warranty-band .yrs { font-size:14px; font-weight:800; color:#16a34a; white-space:nowrap; text-align:center; }
.wd-row { display:flex; justify-content:space-between; font-size:12px; color:#475569; font-weight:700; }
.wd-row span:last-child { font-family:'Inter'; color:#0f172a; }
.ref-box { background:#fff; border:1px solid #e2e8f0; padding:10px; border-radius:8px; font-size:12px; color:#334155; }
.mc-tile { border-radius:8px; padding:7px 10px; margin-bottom:7px; }
.mc-tile:last-child { margin-bottom:0; }
.mc-tile .tl { font-size:10px; font-weight:800; margin-bottom:2px; display:block; opacity:.85; }
.mc-tile .tv { font-size:12.5px; font-weight:800; }
.mc-tile-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px; }
.side-by-side { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
@media(max-width:1100px){ .side-by-side { grid-template-columns:1fr 1fr; } }
@media(max-width:700px){ .side-by-side { grid-template-columns:1fr; } }
.side-by-side .fc { height:100%; }
</style>
</head>
<body class="app-layout">
<datalist id="mfrList_gen"></datalist>
<datalist id="modelList_gen"></datalist>

<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php if($errors): ?>
<div id="errModal" style="position:fixed; inset:0; background:rgba(15,23,42,0.55); display:flex; align-items:center; justify-content:center; z-index:2000; padding:20px;">
    <div style="background:#fff; border-radius:16px; max-width:440px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">
        <div style="background:linear-gradient(135deg,#dc2626,#b91c1c); padding:24px; text-align:center; color:#fff;">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:40px; margin-bottom:10px;"></i>
            <div style="font-size:17px; font-weight:900;">تحقّق من البيانات قبل الحفظ</div>
            <div style="font-size:12.5px; font-weight:600; opacity:.9; margin-top:4px;">لم يُحفَظ المحضر بسبب النقاط التالية:</div>
        </div>
        <div style="padding:22px 24px;">
            <ul style="margin:0; padding:0; display:flex; flex-direction:column; gap:12px;">
                <?php foreach($errors as $e): ?>
                <li style="display:flex; align-items:flex-start; gap:10px; font-size:13.5px; font-weight:700; color:#334155;">
                    <i class="fa-solid fa-circle-exclamation" style="color:#dc2626; font-size:14px; margin-top:3px;"></i>
                    <span><?= e($e) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div style="padding:6px 24px 24px;">
            <button type="button" onclick="document.getElementById('errModal').remove()" style="width:100%; background:#0f172a; color:#fff; border:none; padding:12px; border-radius:10px; font-weight:800; font-size:14px; cursor:pointer;">
                <i class="fa-solid fa-pen"></i> فهمت، سأكمل التعديل
            </button>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
$fm_colors = ['success'=>['#16a34a','#15803d','fa-circle-check'], 'danger'=>['#dc2626','#b91c1c','fa-triangle-exclamation'], 'warning'=>['#d97706','#b45309','fa-triangle-exclamation'], 'info'=>['#2563eb','#1d4ed8','fa-circle-info']];
foreach($flash_msgs as $fm):
    $fc = $fm_colors[$fm['type']] ?? $fm_colors['info'];
?>
<div class="nice-flash-modal" style="position:fixed; inset:0; background:rgba(15,23,42,0.55); display:flex; align-items:center; justify-content:center; z-index:2500; padding:20px;">
    <div style="background:#fff; border-radius:16px; max-width:400px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">
        <div style="background:linear-gradient(135deg,<?= $fc[0] ?>,<?= $fc[1] ?>); padding:24px; text-align:center; color:#fff;">
            <i class="fa-solid <?= $fc[2] ?>" style="font-size:38px;"></i>
        </div>
        <div style="padding:22px 24px; font-size:14px; font-weight:800; color:#334155; text-align:center; line-height:1.6;"><?= e($fm['message']) ?></div>
        <div style="padding:6px 24px 22px;">
            <button type="button" onclick="this.closest('.nice-flash-modal').remove()" style="width:100%; background:#0f172a; color:#fff; border:none; padding:11px; border-radius:10px; font-weight:800; font-size:14px; cursor:pointer;">حسناً</button>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if($is_approved): ?>
<div style="background:#f0fdf4; border:1px solid #bbf7d0; color:#16a34a; padding:16px; border-radius:12px; margin-bottom:18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap">
    <i class="fa-solid fa-lock" style="font-size:24px; flex-shrink:0;"></i>
    <div style="flex:1; min-width:200px">
        <div style="font-weight:900; font-size:15px">المحضر مقفل ومعتمد نهائياً</div>
        <div style="font-size:12.5px; font-weight:600">لا يمكن التعديل عليه. تم إرفاق النسخة الموقعة بالأسفل.</div>
    </div>
    <?php if (!empty($cert['asset_id']) && !empty($cert['transferred_at'])): ?>
    <div style="background:#dc2626; color:#fff; padding:8px 14px; border-radius:8px; font-weight:700; font-size:12.5px; display:inline-flex; align-items:center; gap:6px; white-space:nowrap">
        <i class="fa-solid fa-circle-info"></i>
        <?= is_rtl() ? 'تم تسجيل الأصل رقم' : 'Asset registered as' ?> #<?= (int)$cert['asset_id'] ?>
        <span style="background:#fff; color:#dc2626; padding:1px 6px; border-radius:4px; font-size:10.5px; font-weight:800"><?= is_rtl()?'ينتظر التسجيل':'Pending' ?></span>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
/* دائرة اكتمال البيانات — نسختان بحسب حالة الشهادة:
   • معتمدة ومرتبطة بأصل حقيقي: نقرأ الأصل الفعلي مباشرة (نسبة حقيقية 100%).
   • مسودة/جديدة: تقدير أفضل جهد من حقول الشهادة الحالية — العهدة
     تبقى عمداً "غير معروفة" لأنها فعلاً لا تُحسَم إلا وقت الاعتماد،
     لا تخميناً كاذباً هنا. */
if (!empty($cert['asset_id'])) {
    $ring_source_q = $pdo->prepare("SELECT * FROM assets WHERE id=?");
    $ring_source_q->execute([(int)$cert['asset_id']]);
    $ring_asset = $ring_source_q->fetch(PDO::FETCH_ASSOC) ?: [];
    $cc_completion = compute_asset_completion($ring_asset);
} else {
    $cc_completion = compute_asset_completion([
        'description'           => $device['description'] ?? null,
        'description_ar'        => $_POST['description_ar'] ?? $cert['description_ar'] ?? $device['description_ar'] ?? null,
        'manufacturer_name'     => $_POST['manufacturer_name'] ?? $cert['manufacturer_name'] ?? $device['manufacturer_name'] ?? null,
        'model_number'          => $_POST['model_number'] ?? $cert['model_number'] ?? $device['model_number'] ?? null,
        'serial_number'         => $_POST['serial_number'] ?? $cert['serial_number'] ?? $device['serial_number'] ?? null,
        'item_code'             => $device['item_code'] ?? null,
        'location_id'           => $_POST['location_id'] ?? $cert['location_id'] ?? null,
        'cat_level1'             => $_POST['cat_level1'] ?? $cert['cat_level1'] ?? null,
        'cat_level2'             => $_POST['cat_level2'] ?? $cert['cat_level2'] ?? null,
        'cat_level3'             => $_POST['cat_level3'] ?? $cert['cat_level3'] ?? null,
        'criticality_class'      => $_POST['criticality_class'] ?? $cert['criticality_class'] ?? null,
        'date_placed_in_service' => $cert['date_placed_in_service'] ?? ($_POST['warranty_start'] ?? $cert['warranty_start'] ?? null),
        // العهدة تبقى غير معروفة عمداً قبل الاعتماد — لا تُحتسَب هنا لا سلباً ولا إيجاباً
        'custodian_user_id'      => null, 'custodian_dept_id' => null,
        'warranty_expiry'        => $device['warranty_expiry'] ?? null,
        'warranty_type'          => $device['warranty_type'] ?? null,
        'warranty_years'         => $device['warranty_years'] ?? null,
    ]);
}
$_ring_circ = 119.4;
$_ring_offset = $_ring_circ - ($_ring_circ * $cc_completion['percent'] / 100);
?>
<div style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:10px">
    <div>
        <h2 style="font-size:22px;font-weight:900;color:#0f172a;margin-bottom:4px"><?= e($page_title) ?></h2>
        <div style="font-size:13px;color:#64748b;font-weight:600">
            <i class="fa-solid fa-sitemap"></i> القسم المستلم: <span style="color:#1565C0"><?= e($dept_name) ?></span> | 
            <i class="fa-solid fa-file-contract"></i> محضر رقم: <span style="color:#1565C0" class="eng-num"><?= e($minute['minute_number']) ?></span>
        </div>
    </div>
    <div style="display:flex; align-items:center; gap:12px">
        <div style="width:46px;height:46px;position:relative" title="<?= is_rtl()?'نسبة اكتمال بيانات الأصل':'Asset data completeness' ?> — <?= e($cc_completion['tier']) ?>">
            <svg width="46" height="46" viewBox="0 0 46 46" style="transform:rotate(-90deg)">
                <circle cx="23" cy="23" r="19" fill="none" stroke="#e2e8f0" stroke-width="5"></circle>
                <circle cx="23" cy="23" r="19" fill="none" stroke="<?= e($cc_completion['color']) ?>"
                        stroke-width="5" stroke-linecap="round"
                        stroke-dasharray="<?= $_ring_circ ?>" stroke-dashoffset="<?= $_ring_offset ?>"></circle>
            </svg>
            <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#334155"><?= (int)$cc_completion['percent'] ?>%</div>
        </div>
        <a href="<?= BASE_URL ?>/receiving/view.php?id=<?= $minute_id ?>" class="btn" style="background:#fff; border:1px solid #cbd5e1; color:#475569; padding:8px 16px; border-radius:6px; font-weight:800; text-decoration:none; font-size:13px;">
            <i class="fa-solid fa-arrow-right"></i> العودة للمحضر
        </a>
    </div>
</div>

<form method="POST" id="ccForm" enctype="multipart/form-data">
<?= csrf_input() ?>
<input type="hidden" name="form_action" id="fAction" value="draft">

<div class="page-layout">

    <div class="minute-card">
        <div class="fc">
            <div class="mc-head">
                <div class="ttl"><i class="fa-solid fa-box-archive"></i> بيانات المحضر</div>
                <div class="sub"><i class="fa-solid fa-lock"></i> من محضر الاستلام — مرجعية، غير قابلة للتعديل</div>
            </div>
            <div class="mc-body">

                <div class="mc-tile" style="background:#eff6ff">
                    <span class="tl" style="color:#1d4ed8">رقم الصنف</span>
                    <div class="tv eng-num" style="color:#1e3a8a; text-align:right"><?= e($item_code_display ?: '—') ?></div>
                </div>

                <div class="mc-tile" style="background:#eef2ff">
                    <span class="tl" style="color:#4338ca">اسم الجهاز (إنجليزي)</span>
                    <div class="tv" style="color:#312e81"><?= e($device['description'] ?? '—') ?></div>
                </div>

                <div class="mc-tile" style="background:#eef2ff">
                    <span class="tl" style="color:#4338ca">اسم الجهاز (عربي)</span>
                    <div class="tv" style="color:#312e81"><?= e($device['description_ar'] ?: '—') ?></div>
                </div>

                <div class="mc-tile-row">
                    <div class="mc-tile" style="background:#f0fdf4; margin-bottom:0">
                        <span class="tl" style="color:#15803d">الكمية</span>
                        <div class="tv eng-num" style="color:#166534"><?= e($device['quantity'] ?? 1) ?></div>
                    </div>
                    <div class="mc-tile" style="background:#f8fafc; margin-bottom:0">
                        <span class="tl" style="color:#475569">تاريخ الاستلام</span>
                        <div class="tv eng-num" style="color:#1e293b; font-size:12.5px"><?= e($minute['receipt_date'] ?? '—') ?></div>
                    </div>
                </div>

                <div class="mc-divider"></div>

                <div class="mc-row">
                    <span class="mc-label"><i class="fa-solid fa-shield-halved" style="color:#16a34a"></i> الضمان</span>
                    <div class="warranty-band">
                        <div class="yrs eng-num"><?= e($device['warranty_years'] ?? 0) ?> سنوات إجمالاً</div>
                        <div class="wd-row"><span>يبدأ من</span><span id="warrStartOut" class="eng-num">—</span></div>
                        <div class="wd-row"><span>ينتهي في</span><span id="warrEndOut" class="eng-num">—</span></div>
                    </div>
                </div>

                <div class="mc-divider"></div>

                <div class="mc-row">
                    <span class="mc-label">الكتالوجات والأدلة المستلمة مع الجهاز</span>
                    <div class="mc-stat-grid">
                        <div class="mc-stat"><div class="n" style="color:#7c3aed"><?= e($device['manuals_operation'] ?? 0) ?></div><div class="l">تشغيل</div></div>
                        <div class="mc-stat"><div class="n" style="color:#0891b2"><?= e($device['manuals_maintenance'] ?? 0) ?></div><div class="l">صيانة</div></div>
                        <div class="mc-stat"><div class="n" style="color:#ea580c"><?= e($device['cd_count'] ?? 0) ?></div><div class="l">أقراص</div></div>
                    </div>
                </div>

                <div class="mc-divider"></div>

                <div class="mc-row">
                    <span class="mc-label"><i class="fa-solid fa-comment-dots"></i> ملاحظات المحضر</span>
                    <div class="ref-box"><?= !empty($minute['notes']) ? nl2br(e($minute['notes'])) : '<span style="color:#94a3b8">لا توجد ملاحظات.</span>' ?></div>
                </div>

                <?php if (!empty($minute_attachments)): ?>
                <div class="mc-row">
                    <span class="mc-label"><i class="fa-solid fa-paperclip"></i> مرفقات المحضر</span>
                    <div style="display:flex; flex-direction:column; gap:6px">
                        <?php foreach ($minute_attachments as $att): ?>
                            <a href="<?= BASE_URL ?>/uploads/<?= e($att['file_path']) ?>" target="_blank" style="display:flex; align-items:center; gap:6px; padding:7px 10px; border:1px solid #e2e8f0; border-radius:6px; text-decoration:none; color:#1565C0; font-size:11.5px; font-weight:700">
                                <i class="fa-solid fa-file-pdf" style="color:#dc2626"></i> <?= e($att['file_name']) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="left-col">

        <div class="side-by-side">

        <div class="fc">
            <div class="fch-colored"><i class="fa-solid fa-screwdriver-wrench" style="color:#93c5fd"></i> بيانات الجهاز الفنية</div>
            <div class="fb">
                <div class="grid-2">
                    <div>
                        <label class="gen-label">الصانعة <span class="req">*</span></label>
                        <input list="mfrList_gen" id="gMfr" name="manufacturer_name" class="rfi" value="<?= e($p['manufacturer_name'] ?? $device['manufacturer_name'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> oninput="updateGenModelDatalist(this.value)" onchange="updateGenModelDatalist(this.value)" required>
                    </div>
                    <div>
                        <label class="gen-label">الموديل <span class="req">*</span></label>
                        <input list="modelList_gen" id="gModel" name="model_number" class="rfi" value="<?= e($p['model_number'] ?? $device['model_number'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> onfocus="updateGenModelDatalist(document.getElementById('gMfr').value)" required>
                    </div>
                </div>
                <div class="grid-2">
                    <div>
                        <label class="gen-label">S/N <span class="req">*</span></label>
                        <input type="text" name="serial_number" id="ccSerialInput" class="rfi eng-num" value="<?= e($p['serial_number'] ?? $device['serial_number'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="S/N..." required autocomplete="off" data-dup-field="serial_number">
                    </div>
                    <div>
                        <label class="gen-label">تاريخ التشغيل <span class="req">*</span></label>
                        <input type="date" id="gregDateInp" name="warranty_start" class="rfi eng-num" value="<?= e($p['warranty_start'] ?? date('Y-m-d')) ?>" <?= $is_approved?'readonly':'' ?> onchange="updateHijriAndDay(); updateWarrantyDates();" required>
                    </div>
                </div>
                <div>
                    <label class="gen-label">
                        رقم التاج (tag_number)
                        <span style="color:#94a3b8;font-size:10.5px;font-weight:400">اختياري — يُستكمل لاحقاً مع رقم الأصل من موارد</span>
                    </label>
                    <input type="text" name="tag_number" id="ccTagInput" class="rfi eng-num" dir="ltr"
                           value="<?= e($p['tag_number'] ?? $cert['tag_number'] ?? '') ?>"
                           <?= $is_approved?'readonly':'' ?> placeholder="اتركه فارغاً إن لم يتوفر بعد" autocomplete="off" data-dup-field="tag_number">
                </div>
                <div class="grid-2">
                    <div>
                        <label class="gen-label">هجري</label>
                        <input type="text" id="hijriDateOut" class="rfi readonly eng-num" tabindex="-1" readonly>
                    </div>
                    <div>
                        <label class="gen-label">اليوم</label>
                        <input type="text" id="dayNameOut" class="rfi readonly" tabindex="-1" readonly style="text-align:center">
                    </div>
                </div>
                <label class="gen-label" style="margin-bottom:5px">المطابقة الفنية <span class="req">*</span></label>
                <div class="match-options" style="<?= $is_approved ? 'pointer-events:none; opacity:0.8' : '' ?>">
                    <label class="match-option ok <?= ($p['spec_match']??1)==1?'active':'' ?>" id="optMatchOk">
                        <input type="radio" name="spec_match" value="1" <?= ($p['spec_match']??1)==1?'checked':'' ?> onchange="updateMatchUI()">
                        <i class="fa-solid fa-check-circle" style="font-size:16px"></i> مطابق
                    </label>
                    <label class="match-option no <?= ($p['spec_match']??1)==0?'active':'' ?>" id="optMatchNo">
                        <input type="radio" name="spec_match" value="0" <?= ($p['spec_match']??1)==0?'checked':'' ?> onchange="updateMatchUI()">
                        <i class="fa-solid fa-times-circle" style="font-size:16px"></i> غير مطابق
                    </label>
                </div>
            </div>
        </div>

        <div class="fc">
            <div class="fch-colored dark"><i class="fa-solid fa-user-tie" style="color:#cbd5e1"></i> التوثيق</div>
            <div class="fb">
                <div style="margin-bottom:8px">
                    <label class="gen-label">المقاول (المؤسسة المنفِّذة) <span class="req">*</span></label>
                    <input type="text" name="executing_company_name" class="rfi" value="<?= e($p['executing_company_name'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: شركة الفا" required>
                </div>
                <div style="margin-bottom:8px">
                    <label class="gen-label">مندوب الشركة (الفني) <span class="req">*</span></label>
                    <input type="text" name="representative_name" class="rfi" value="<?= e($p['representative_name'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="مثال: م. أحمد محمد" required>
                </div>
                <div style="margin-bottom:8px">
                    <label class="gen-label">مدير تجهيزات المنطقة (اختياري)</label>
                    <input type="text" name="regional_equipment_mgr_name" class="rfi" value="<?= e($p['regional_equipment_mgr_name'] ?? '') ?>" <?= $is_approved?'readonly':'' ?> placeholder="الاسم ثلاثياً">
                </div>
                <div style="margin-bottom:0">
                    <label class="gen-label">ملاحظات (اختياري)</label>
                    <textarea name="notes" class="rfi" style="height:50px; resize:vertical; padding:8px;" <?= $is_approved?'readonly':'' ?>><?= e($p['notes'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="fc" style="border:1.5px solid #f59e0b; background:#fffbeb;">
            <div class="fch-colored" style="background: linear-gradient(135deg, #b45309, #d97706);">
                <i class="fa-solid fa-shield-halved" style="color:#fef3c7"></i> تصنيف الأصل وموقعه <span style="background:#fef3c7;color:#92400e;font-size:10.5px;padding:2px 8px;border-radius:4px;margin-inline-start:8px;font-weight:800">إلزامي</span>
            </div>
            <div class="fb" style="padding:12px">
                <!-- الحساسية: A/B/C بأحرف متوسطة -->
                <div style="margin-bottom:10px">
                    <label class="gen-label" style="margin-bottom:4px">فئة الحساسية <span class="req">*</span></label>
                    <div class="crit-group" style="display:flex; gap:5px; <?= $is_approved?'pointer-events:none;opacity:0.85':'' ?>">
                        <?php foreach (['A'=>'#dc2626','B'=>'#d97706','C'=>'#16a34a'] as $L=>$color): ?>
                            <label class="crit-opt" data-value="<?= $L ?>" data-color="<?= $color ?>" style="flex:1; cursor:pointer; border:2px solid <?= ($p['criticality_class']??'')===$L?$color:'#e2e8f0' ?>; border-radius:6px; padding:4px 0; text-align:center; background:<?= ($p['criticality_class']??'')===$L?$color:'#fff' ?>; color:<?= ($p['criticality_class']??'')===$L?'#fff':'#475569' ?>; font-size:18px; font-weight:900; font-family:Inter,sans-serif; transition:.15s">
                                <input type="radio" name="criticality_class" value="<?= $L ?>" style="display:none" <?= ($p['criticality_class']??'')===$L?'checked':'' ?> required>
                                <?= $L ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- الموقع: 3 selects (مبنى / طابق / غرفة) من item_locations — مع cascading -->
                <div style="margin-bottom:10px">
                    <label class="gen-label" style="margin-bottom:4px"><i class="fa-solid fa-map-location-dot" style="color:#0e7490"></i> الموقع <span class="req">*</span></label>
                    <select name="loc_building" class="rfi cascade-parent" data-child="loc_floor" data-grand="loc_room" <?= $is_approved?'disabled':'' ?> required style="margin-bottom:5px">
                        <option value="">— المبنى —</option>
                        <?php foreach ($locations_by_type['building'] as $loc):
                            $label = $loc['name_en'] ?: $loc['name'];
                            $sel = ($p['loc_building'] ?? '') === $label ? 'selected' : '';
                        ?>
                            <option value="<?= e($label) ?>" data-loc-id="<?= (int)$loc['id'] ?>" <?= $sel ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:5px">
                        <select name="loc_floor" class="rfi cascade-child" data-parent="loc_building" data-child="loc_room" data-locs='<?= e(json_encode(array_map(fn($l) => ['id'=>(int)$l['id'], 'value'=>($lbl=$l['name_en']?:$l['name']), 'label'=>(($pname=$loc_by_id[(int)$l['parent_id']]['name_en'] ?? '') ? "$pname › " : '').$lbl, 'parent_id'=>(int)$l['parent_id']], $locations_by_type['floor']), JSON_UNESCAPED_UNICODE)) ?>' <?= $is_approved?'disabled':'' ?>>
                            <option value="">— الطابق —</option>
                            <?php foreach ($locations_by_type['floor'] as $loc):
                                $parent_name = isset($loc_by_id[(int)$loc['parent_id']]) ? ($loc_by_id[(int)$loc['parent_id']]['name_en'] ?: $loc_by_id[(int)$loc['parent_id']]['name']) : '';
                                $label = $loc['name_en'] ?: $loc['name'];
                                $sel = ($p['loc_floor'] ?? '') === $label ? 'selected' : '';
                            ?>
                                <option value="<?= e($label) ?>" data-parent-id="<?= (int)$loc['parent_id'] ?>" <?= $sel ?>><?= e($parent_name ? "$parent_name › " : '').e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="loc_room" class="rfi cascade-child" data-parent="loc_floor" data-locs='<?= e(json_encode(array_map(fn($l) => ['id'=>(int)$l['id'], 'value'=>($lbl=$l['name_en']?:$l['name']), 'label'=>(($pname=$loc_by_id[(int)$l['parent_id']]['name_en'] ?? '') ? "$pname › " : '').$lbl, 'parent_id'=>(int)$l['parent_id']], $locations_by_type['room']), JSON_UNESCAPED_UNICODE)) ?>' <?= $is_approved?'disabled':'' ?>>
                            <option value="">— الغرفة —</option>
                            <?php foreach ($locations_by_type['room'] as $loc):
                                $parent_name = isset($loc_by_id[(int)$loc['parent_id']]) ? ($loc_by_id[(int)$loc['parent_id']]['name_en'] ?: $loc_by_id[(int)$loc['parent_id']]['name']) : '';
                                $label = $loc['name_en'] ?: $loc['name'];
                                $sel = ($p['loc_room'] ?? '') === $label ? 'selected' : '';
                            ?>
                                <option value="<?= e($label) ?>" data-parent-id="<?= (int)$loc['parent_id'] ?>" <?= $sel ?>><?= e($parent_name ? "$parent_name › " : '').e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="location_id" id="certLocationId"
                               value="<?= e($p['location_id'] ?? $cert['location_id'] ?? '') ?>">
                    </div>
                </div>

                <!-- الفئة: 3 selects (L1 / L2 / L3) من item_categories — مع cascading -->
                <div>
                    <label class="gen-label" style="margin-bottom:4px"><i class="fa-solid fa-sitemap" style="color:#7c3aed"></i> الفئة <span class="req">*</span></label>
                    <select name="cat_level1" class="rfi cascade-parent" data-child="cat_level2" data-grand="cat_level3" <?= $is_approved?'disabled':'' ?> required style="margin-bottom:5px">
                        <option value="">— الفئة الرئيسية (L1) —</option>
                        <?php foreach ($cats_by_level[1] as $cat):
                            $sel = ($p['cat_level1'] ?? '') === $cat['name'] ? 'selected' : '';
                        ?>
                            <option value="<?= e($cat['name']) ?>" data-cat-id="<?= (int)$cat['id'] ?>" <?= $sel ?>><?= e($cat['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:5px">
                        <select name="cat_level2" class="rfi cascade-child" data-parent="cat_level1" data-child="cat_level3" data-cats='<?= e(json_encode(array_map(fn($c) => ['id'=>(int)$c['id'], 'value'=>$c['name'], 'label'=>(($pname=$cat_by_id[(int)$c['parent_id']]['name'] ?? '') ? "$pname › " : '').$c['name'], 'parent_id'=>(int)$c['parent_id']], $cats_by_level[2]), JSON_UNESCAPED_UNICODE)) ?>' <?= $is_approved?'disabled':'' ?>>
                            <option value="">— L2 —</option>
                            <?php foreach ($cats_by_level[2] as $cat):
                                $parent_name = $cat_by_id[(int)$cat['parent_id']]['name'] ?? '';
                                $sel = ($p['cat_level2'] ?? '') === $cat['name'] ? 'selected' : '';
                            ?>
                                <option value="<?= e($cat['name']) ?>" data-parent-id="<?= (int)$cat['parent_id'] ?>" <?= $sel ?>><?= e($parent_name ? "$parent_name › " : '').e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="cat_level3" class="rfi cascade-child" data-parent="cat_level2" data-cats='<?= e(json_encode(array_map(fn($c) => (($p = $cat_by_id[(int)$c['parent_id']] ?? null) && ($g = $p ? ($cat_by_id[(int)$p['parent_id']]['name'] ?? '') : '')) ? ['id'=>(int)$c['id'], 'value'=>$c['name'], 'label'=>($g? "$g › " : '').($p['name']? $p['name'].' › ' : '').$c['name'], 'parent_id'=>(int)$c['parent_id']] : ['id'=>(int)$c['id'], 'value'=>$c['name'], 'label'=>$c['name'], 'parent_id'=>(int)$c['parent_id']], $cats_by_level[3]), JSON_UNESCAPED_UNICODE)) ?>' <?= $is_approved?'disabled':'' ?>>
                            <option value="">— L3 —</option>
                            <?php foreach ($cats_by_level[3] as $cat):
                                $parent = $cat_by_id[(int)$cat['parent_id']] ?? null;
                                $grand = $parent ? ($cat_by_id[(int)$parent['parent_id']]['name'] ?? '') : '';
                                $parent_name = $parent['name'] ?? '';
                                $sel = ($p['cat_level3'] ?? '') === $cat['name'] ? 'selected' : '';
                            ?>
                                <option value="<?= e($cat['name']) ?>" data-parent-id="<?= (int)$cat['parent_id'] ?>" <?= $sel ?>><?= e(($grand ? "$grand › " : '').($parent_name ? "$parent_name › " : '')).e($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        </div>

        <div class="fc" style="margin-top:18px; border-color:#9333ea; box-shadow: 0 4px 15px rgba(147, 51, 234, 0.1);">
            <div class="fch-colored purple"><i class="fa-solid fa-file-signature" style="color:#d8b4fe"></i> إرفاق المحضر الموقّع والاعتماد النهائي</div>
            <div class="fb">
                <div style="display:flex; gap:20px; align-items:center; flex-wrap:wrap">

                    <div style="flex:1; min-width:260px; background:#faf5ff; padding:15px; border-radius:8px; border:1px solid #e9d5ff;">
                        <h4 style="color:#7e22ce; font-weight:900; margin-bottom:8px; font-size:14px;"><i class="fa-solid fa-list-ol"></i> خطوات الاعتماد المطلوبة:</h4>
                        <ol style="margin-inline-start:20px; font-size:13px; color:#4c1d95; line-height:1.8; font-weight:700">
                            <li>تأكد من إدخال كافة البيانات في الأعلى بدقة واضغط (حفظ البيانات).</li>
                            <li>اضغط على زر (حفظ وطباعة الشهادة) واحصل على توقيع المندوب ومدير القسم ومدير المستشفى والأختام الحية — ويُطبع معها بيان التوزيع لتوقيع رؤساء الأقسام أثناء جولة التركيب.</li>
                            <li>قم بعمل مسح ضوئي (Scan) للمحضر الموقع بالكامل.</li>
                            <li>ارفع الملف الممسوح هنا واضغط (اعتماد المحضر وإقفاله).</li>
                        </ol>
                    </div>

                    <div style="flex:1; min-width:260px;">
                        <?php if(!empty($p['signed_attachment'])): ?>
                            <div style="background:#f0fdf4; border:2px solid #22c55e; border-radius:10px; padding:20px; text-align:center;">
                                <i class="fa-solid fa-circle-check" style="font-size:36px; color:#16a34a; margin-bottom:10px;"></i>
                                <div style="font-weight:900; color:#16a34a; font-size:15px; margin-bottom:10px;">تم إرفاق النسخة الموقعة والمختومة بنجاح</div>
                                <div style="display:flex; gap:8px; justify-content:center; flex-wrap:wrap;">
                                    <a href="<?= BASE_URL ?>/uploads/<?= e($p['signed_attachment']) ?>" target="_blank" class="btn" style="display:inline-block; background:#16a34a; color:#fff; padding:6px 14px; text-decoration:none; border-radius:6px; font-size:12.5px;">
                                        <i class="fa-solid fa-eye"></i> عرض الملف
                                    </a>
                                    <a href="<?= BASE_URL ?>/uploads/<?= e($p['signed_attachment']) ?>" download class="btn" style="display:inline-block; background:#0f172a; color:#fff; padding:6px 14px; text-decoration:none; border-radius:6px; font-size:12.5px;">
                                        <i class="fa-solid fa-download"></i> تحميل الملف
                                    </a>
                                </div>
                            </div>
                            <input type="hidden" id="hasSignedFile" value="1">
                        <?php else: ?>
                            <div class="upload-area">
                                <input type="file" id="signedCopy" name="signed_copy" accept=".pdf,image/jpeg,image/png,image/jpg" onchange="showFileName(this)">
                                <i class="fa-solid fa-cloud-arrow-up" style="font-size:36px; color:#9333ea; margin-bottom:10px;"></i>
                                <div style="font-weight:800; color:#6b21a8; font-size:14px;">اضغط هنا لاختيار ملف المحضر الموقّع</div>
                                <div style="font-size:11.5px; color:#94a3b8; margin-top:5px;">الصيغ المقبولة: PDF, JPG, PNG</div>
                                <div id="fileNameDisplay" class="file-name-display"></div>
                            </div>
                            <input type="hidden" id="hasSignedFile" value="0">
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>

    </div>

</div>


<?php if(!$is_approved): ?>
<div class="bottom-action-bar">
    <div style="font-size:13.5px;font-weight:600"><i class="fa-solid fa-info-circle"></i> يمكنك حفظ البيانات، ثم طباعتها لتوقيعها، والعودة لاحقاً للرفع والاعتماد.</div>
    <div style="display:flex; gap:10px; align-items:center;">

        <button type="button" onclick="doSave('draft')" class="btn" data-dup-block style="background:transparent; color:#93c5fd; font-size:13.5px; font-weight:800; border:1px solid #3b82f6; padding:8px 20px; border-radius:8px; cursor:pointer">
            <i class="fa-solid fa-floppy-disk"></i> حفظ البيانات مؤقتاً
        </button>

        <?php if($id > 0): ?>
        <button type="button" onclick="doSave('print')" class="btn" data-dup-block style="background:#f59e0b; color:#fff; font-size:13.5px; font-weight:800; border:none; padding:8px 20px; border-radius:8px; cursor:pointer; box-shadow:0 2px 6px rgba(245,158,11,0.3)">
            <i class="fa-solid fa-print"></i> حفظ وطباعة الشهادة
        </button>
        <?php endif; ?>

        <?php if($id > 0 && $minute_id): ?>
        <a href="<?= BASE_URL ?>/receiving/distribution_print.php?minute_id=<?= (int)$minute_id ?>"
           target="_blank" class="btn"
           style="background:#0F6E56; color:#fff; font-size:13.5px; font-weight:800; border:none; padding:8px 20px; border-radius:8px; cursor:pointer; text-decoration:none; box-shadow:0 2px 6px rgba(15,110,86,0.3)">
            <i class="fa-solid fa-sitemap"></i> طباعة بيان التوزيع
        </a>
        <?php endif; ?>

        <div style="width:1px; height:30px; background:rgba(255,255,255,0.2); margin:0 5px;"></div>

        <button type="button" onclick="doSave('submit')" class="btn btn-primary" data-dup-block style="font-size:14px;font-weight:800;box-shadow:0 4px 10px rgba(147,51,234,0.4);padding:8px 24px;background:#9333ea;color:#fff;border:none;border-radius:8px;cursor:pointer">
            <i class="fa-solid fa-lock"></i> اعتماد الشهادة وإقفالها
        </button>
    </div>
</div>
<?php endif; ?>
</form>

</main>
</div>

<script>
const _MFR_DICT = <?= json_encode($mfr_dict, JSON_UNESCAPED_UNICODE) ?>;
const TOTAL_WARRANTY_YEARS = <?= json_encode((float)($device['warranty_years'] ?? 0)) ?>;
const _CC_EXCLUDE_ID = <?= (int)($cert['asset_id'] ?? 0) ?>;
const _CC_IS_APPROVED = <?= $is_approved ? 'true' : 'false' ?>;
const _CC_BASE_URL = <?= json_encode(BASE_URL) ?>;

window.addEventListener('DOMContentLoaded', () => {
    const genMfrDl = document.getElementById('mfrList_gen');
    if(genMfrDl) {
        Object.keys(_MFR_DICT).forEach(mfr => {
            const opt = document.createElement('option');
            opt.value = mfr; genMfrDl.appendChild(opt);
        });
    }
    updateMatchUI();
    updateHijriAndDay();
    updateWarrantyDates();

    <?php if(isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.open('<?= BASE_URL ?>/installation/print.php?id=<?= $id ?>', '_blank');
    <?php endif; ?>
});

function updateGenModelDatalist(mfrName) {
    const dl = document.getElementById('modelList_gen');
    if(!dl) return; 
    dl.innerHTML = ''; 
    const searchKey = (mfrName || '').trim().toLowerCase();
    let models = [];
    for (let key in _MFR_DICT) {
        if (key.trim().toLowerCase() === searchKey) {
            models = _MFR_DICT[key]; break;
        }
    }
    models.forEach(m => { 
        if(m) { const opt = document.createElement('option'); opt.value = m; dl.appendChild(opt); }
    });
}

function updateMatchUI() {
    const checked = document.querySelector('input[name="spec_match"]:checked');
    if(!checked) return;
    const val = checked.value;
    const okLbl = document.getElementById('optMatchOk');
    const noLbl = document.getElementById('optMatchNo');
    
    if (val === "1") {
        okLbl.classList.add('active'); noLbl.classList.remove('active');
    } else {
        noLbl.classList.add('active'); okLbl.classList.remove('active');
    }
}

function updateHijriAndDay() {
    const gregDateStr = document.getElementById('gregDateInp').value;
    if (!gregDateStr) {
        document.getElementById('hijriDateOut').value = '';
        document.getElementById('dayNameOut').value = '';
        return;
    }
    const dateObj = new Date(gregDateStr);
    const daysArr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
    document.getElementById('dayNameOut').value = daysArr[dateObj.getDay()];

    try {
        const options = { day: 'numeric', month: 'long', year: 'numeric', calendar: 'islamic-umalqura' };
        const hijriFormatter = new Intl.DateTimeFormat('ar-SA', options);
        document.getElementById('hijriDateOut').value = hijriFormatter.format(dateObj);
    } catch (e) {
        document.getElementById('hijriDateOut').value = 'غير مدعوم';
    }
}

function updateWarrantyDates() {
    const startOut = document.getElementById('warrStartOut');
    const endOut = document.getElementById('warrEndOut');
    if (!startOut || !endOut) return;
    const startStr = document.getElementById('gregDateInp').value;
    if (!startStr) { startOut.textContent = '—'; endOut.textContent = '—'; return; }
    startOut.textContent = startStr;
    if (!TOTAL_WARRANTY_YEARS || TOTAL_WARRANTY_YEARS <= 0) { endOut.textContent = 'لا يوجد ضمان مسجَّل'; return; }
    const start = new Date(startStr);
    const totalMonths = Math.round(TOTAL_WARRANTY_YEARS * 12);
    const end = new Date(start);
    end.setMonth(end.getMonth() + totalMonths);
    endOut.textContent = end.toISOString().split('T')[0];
}

function showFileName(input) {
    const display = document.getElementById('fileNameDisplay');
    if(input.files && input.files[0]) {
        display.innerHTML = '<i class="fa-solid fa-file-check"></i> تم اختيار: ' + input.files[0].name;
    } else {
        display.innerHTML = '';
    }
}

function niceAlert(message, opts = {}) {
    const color = opts.color || '#dc2626', colorDark = opts.colorDark || '#b91c1c';
    const icon = opts.icon || 'fa-triangle-exclamation', title = opts.title || 'تنبيه';
    const onClose = opts.onClose || null;
    const div = document.createElement('div');
    div.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.55); display:flex; align-items:center; justify-content:center; z-index:3000; padding:20px;';
    div.innerHTML = `
        <div style="background:#fff; border-radius:16px; max-width:480px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">
            <div style="background:linear-gradient(135deg,${color},${colorDark}); padding:24px; text-align:center; color:#fff;">
                <i class="fa-solid ${icon}" style="font-size:38px; margin-bottom:10px;"></i>
                <div style="font-size:16px; font-weight:900;">${title}</div>
            </div>
            <div style="padding:20px 24px; font-size:13.5px; font-weight:700; color:#334155; text-align:center; line-height:1.7; white-space:pre-line;">${message}</div>
            <div style="padding:6px 24px 22px;">
                <button type="button" style="width:100%; background:#0f172a; color:#fff; border:none; padding:11px; border-radius:10px; font-weight:800; font-size:14px; cursor:pointer;">حسناً</button>
            </div>
        </div>`;
    div.querySelector('button').onclick = () => {
        div.remove();
        if (typeof onClose === 'function') onClose();
    };
    document.body.appendChild(div);
}

function niceConfirm(message, onConfirm, opts = {}) {
    const color = opts.color || '#9333ea', colorDark = opts.colorDark || '#7e22ce';
    const icon = opts.icon || 'fa-circle-question', title = opts.title || 'تأكيد الإجراء';
    const div = document.createElement('div');
    div.style.cssText = 'position:fixed; inset:0; background:rgba(15,23,42,0.55); display:flex; align-items:center; justify-content:center; z-index:3000; padding:20px;';
    div.innerHTML = `
        <div style="background:#fff; border-radius:16px; max-width:420px; width:100%; box-shadow:0 20px 50px rgba(0,0,0,0.3); overflow:hidden;">
            <div style="background:linear-gradient(135deg,${color},${colorDark}); padding:24px; text-align:center; color:#fff;">
                <i class="fa-solid ${icon}" style="font-size:38px; margin-bottom:10px;"></i>
                <div style="font-size:16px; font-weight:900;">${title}</div>
            </div>
            <div style="padding:20px 24px; font-size:13.5px; font-weight:700; color:#334155; text-align:center; line-height:1.7; white-space:pre-line;">${message}</div>
            <div style="padding:6px 24px 22px; display:flex; gap:10px;">
                <button type="button" class="ncBtnCancel" style="flex:1; background:#f1f5f9; color:#475569; border:none; padding:11px; border-radius:10px; font-weight:800; font-size:13.5px; cursor:pointer;">إلغاء</button>
                <button type="button" class="ncBtnOk" style="flex:1; background:${color}; color:#fff; border:none; padding:11px; border-radius:10px; font-weight:800; font-size:13.5px; cursor:pointer;">تأكيد</button>
            </div>
        </div>`;
    div.querySelector('.ncBtnCancel').onclick = () => div.remove();
    div.querySelector('.ncBtnOk').onclick = () => { div.remove(); onConfirm(); };
    document.body.appendChild(div);
}

function doSave(action) {
    // ── حاجز التكرار: امنع أي حفظ لو في tag/S/N مكررة ──
    if (window._ccDupGuard && !window._ccDupGuard()) return;

    if(action === 'submit') {
        const fileInput = document.getElementById('signedCopy');
        const hasFile = document.getElementById('hasSignedFile').value === '1';

        if (!hasFile && (!fileInput || !fileInput.value)) {
            niceAlert('لا يمكن الاعتماد النهائي بدون رفع النسخة الموقعة والمختومة أولاً.', {icon:'fa-file-circle-xmark', title:'مرفق ناقص'});
            return;
        }

        niceConfirm(
            'هل تم استكمال جميع التواقيع والأختام المطلوبة على النسخة الورقية المرفقة؟\n\nبمجرد الضغط على (تأكيد)، سيتم إقفال المحضر ولن تتمكن من تعديله مجدداً.',
            () => { document.getElementById('fAction').value = action; document.getElementById('ccForm').submit(); },
            {color:'#9333ea', colorDark:'#7e22ce', icon:'fa-lock', title:'تأكيد الاعتماد النهائي'}
        );
        return;
    }


    document.getElementById('fAction').value = action;
    document.getElementById('ccForm').submit();
}
</script>

<script>
/* ═══ منع تكرار tag_number / serial_number في شهادة التركيب ═══
   - على blur: استدعاء API
   - لو تكرار: modal popup (overlay) في وسط الشاشة
   - عند الإغلاق: مسح قيمة الحقل + إعادة التركيز
   - استثناء asset_id الخاص بهذه الشهادة (لتجنب "تكرار مع نفسه" لو معدّل) */
(function(){
    'use strict';
    if (_CC_IS_APPROVED) return;  // لا حاجة للفحص بعد الاعتماد (الحقول للقراءة فقط)

    let _ccDupTimers = {};
    let _ccDupState = { hasDup: false, count: 0 };

    function ccEscHtml(s) {
        if (s == null) return '';
        return String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    /* يعرض modal منبثق مع تفاصيل الأصل المكرر.
       عند الإغلاق: يمسح قيمة الحقل ويعيد التركيز إليه. */
    function ccShowDupModal(input, conflict, fieldLabel) {
        const custLine   = conflict.custodian_name
            ? '<div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #fecaca;">' +
              '<span style="color:#991b1b">المستلم</span><b>' + ccEscHtml(conflict.custodian_name) + '</b></div>' : '';
        const statusLine = conflict.status
            ? '<div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #fecaca;">' +
              '<span style="color:#991b1b">الحالة</span><b>' + ccEscHtml(conflict.status) + '</b></div>' : '';
        const deptLine   = conflict.dept_name
            ? '<div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #fecaca;">' +
              '<span style="color:#991b1b">القسم</span><b>' + ccEscHtml(conflict.dept_name) + '</b></div>' : '';
        const tagLine    = conflict.tag_number
            ? '<div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #fecaca;">' +
              '<span style="color:#991b1b">التاج</span><b>' + ccEscHtml(conflict.tag_number) + '</b></div>' : '';
        const assetLine  = conflict.asset_number
            ? '<div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #fecaca;">' +
              '<span style="color:#991b1b">رقم الأصل (موارد)</span><b>' + ccEscHtml(conflict.asset_number) + '</b></div>' : '';
        const serialLine = conflict.serial_number
            ? '<div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #fecaca;">' +
              '<span style="color:#991b1b">S/N</span><b>' + ccEscHtml(conflict.serial_number) + '</b></div>' : '';

        const viewLink = conflict.url
            ? '<a href="' + ccEscHtml(conflict.url) + '" target="_blank" ' +
              'style="display:inline-block; margin-top:12px; padding:6px 14px; background:#fff; ' +
              'color:#991b1b; border:1px solid #fca5a5; border-radius:6px; font-size:12px; ' +
              'font-weight:800; text-decoration:none;">' +
              '<i class="fa-solid fa-external-link-alt"></i> مشاهدة الأصل #' + conflict.id + '</a>'
            : '';

        const cardHtml =
            '<div style="text-align:right; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; ' +
            'padding:12px 14px; margin:6px 0;">' +
            '<div style="font-size:13.5px; font-weight:800; color:#991b1b; margin-bottom:8px;">' +
            '<i class="fa-solid fa-circle-exclamation"></i> هذا الرقم مسجّل على الأصل رقم ' +
            '<b>#' + conflict.id + '</b></div>' +
            (conflict.description ?
                '<div style="display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px dashed #fecaca;">' +
                '<span style="color:#991b1b">الجهاز</span><b>' + ccEscHtml(conflict.description) + '</b></div>' : '') +
            tagLine + assetLine + serialLine + deptLine + custLine + statusLine +
            '</div>' + viewLink;

        const msg = cardHtml +
            '<div style="margin-top:14px; padding:10px 12px; background:#fffbeb; border:1px solid #fde68a; ' +
            'border-radius:8px; font-size:12.5px; font-weight:700; color:#92400e; text-align:right;">' +
            '<i class="fa-solid fa-lightbulb"></i> أدخل رقماً مختلفاً لـ <b>' + ccEscHtml(fieldLabel) + '</b> ' +
            'أو امسح الحقل وأكمل البيانات لاحقاً.</div>';

        niceAlert(msg, {
            color: '#dc2626', colorDark: '#b91c1c',
            icon:  'fa-ban', title: '⛔ تكرار ممنوع في ' + fieldLabel,
            onClose: () => {
                input.value = '';
                input.focus();
            }
        });
    }

    function ccDupUpdate() {
        const dups = document.querySelectorAll('.rfi.is-dup').length;
        _ccDupState.hasDup = dups > 0;
        _ccDupState.count = dups;
        // تعطيل/تمكين أزرار الحفظ
        document.querySelectorAll('[data-dup-block]').forEach(btn => {
            if (dups > 0) {
                btn.setAttribute('disabled', 'disabled');
                btn.style.opacity = '0.55';
                btn.style.cursor = 'not-allowed';
            } else {
                btn.removeAttribute('disabled');
                btn.style.opacity = '';
                btn.style.cursor = '';
            }
        });
    }

    function ccDupCheck(input, fieldParam, fieldLabel) {
        const key = fieldParam;
        clearTimeout(_ccDupTimers[key]);
        const val = input.value.trim();
        input.classList.remove('is-dup');
        if (!val) { ccDupUpdate(); return; }

        _ccDupTimers[key] = setTimeout(function(){
            fetch(_CC_BASE_URL + '/assets/api/check_tag_number.php?field=' +
                  encodeURIComponent(fieldParam) + '&value=' + encodeURIComponent(val) +
                  '&exclude_id=' + _CC_EXCLUDE_ID)
                .then(r => r.json())
                .then(d => {
                    if (d.duplicate) {
                        input.classList.add('is-dup');
                        ccShowDupModal(input, d.conflict, fieldLabel);
                    }
                    ccDupUpdate();
                })
                .catch(err => {
                    console.error('ccDupCheck fetch error:', err);
                    ccDupUpdate();
                });
        }, 350); // debounce
    }

    function ccBindDup(inputId, fieldParam, fieldLabel) {
        const inp = document.getElementById(inputId);
        if (!inp) return;

        inp.addEventListener('blur', () => ccDupCheck(inp, fieldParam, fieldLabel));
        inp.addEventListener('input', () => {
            inp.classList.remove('is-dup');
            ccDupUpdate();
        });
    }

    // ربط الحقلين
    ccBindDup('ccSerialInput', 'serial_number', 'S/N');
    ccBindDup('ccTagInput',    'tag_number',    'تاج (tag_number)');

    // فحص أولي: لو الحقول لها قيم محفوظة (مثلاً عند فتح شهادة لتعديل)
    const initialSerial = document.getElementById('ccSerialInput')?.value.trim();
    const initialTag    = document.getElementById('ccTagInput')?.value.trim();
    if (initialSerial) ccDupCheck(document.getElementById('ccSerialInput'), 'serial_number', 'S/N');
    if (initialTag)    ccDupCheck(document.getElementById('ccTagInput'),    'tag_number',    'تاج (tag_number)');

    // تعريض الحالة العامة + حاجز الحفظ
    window._ccDupState = _ccDupState;
    window._ccDupGuard = function(){
        if (_ccDupState.hasDup) {
            const first = document.querySelector('.rfi.is-dup');
            if (first) {
                first.focus();
                first.scrollIntoView({behavior:'smooth', block:'center'});
            }
            niceAlert(
                '🚫 لا يمكن الحفظ — يوجد ' + _ccDupState.count +
                ' حقل(حقول) فيها تكرار.<br><br>' +
                'عدّل الرقم (أو امسح الحقل) ثم حاول مرة أخرى.',
                {color:'#dc2626', colorDark:'#b91c1c', icon:'fa-ban', title:'تكرار في tag/S/N'}
            );
            return false;
        }
        return true;
    };
})();
</script>

<script>
/* ═══ خريطة مباشرة: قيمة الغرفة ← معرّفها الرقمي الحقيقي ═══
   مصدر ثقة مستقل تماماً عن سلسلة filterChild/getOptData الهشة —
   لا اعتماد على data-loc-id على أي <option> إطلاقاً. */
const ROOM_ID_MAP = <?= json_encode(array_column(
    array_map(fn($l) => ['k' => ($l['name_en'] ?: $l['name']), 'v' => (int)$l['id']], $locations_by_type['room']),
    'v', 'k'
), JSON_UNESCAPED_UNICODE) ?>;

/* ═══ A) Criticality A/B/C buttons — click to select (visual update) ═══ */
(function() {
  const group = document.querySelector('.crit-group');
  if (!group) return;
  const opts = group.querySelectorAll('.crit-opt');
  opts.forEach(opt => {
    opt.addEventListener('click', function(e) {
      const val = this.dataset.value;
      const color = this.dataset.color;
      // uncheck all + reset visual
      opts.forEach(o => {
        o.querySelector('input').checked = false;
        o.style.borderColor = '#e2e8f0';
        o.style.background = '#fff';
        o.style.color = '#475569';
      });
      // check this one + apply visual
      const inp = this.querySelector('input');
      inp.checked = true;
      this.style.borderColor = color;
      this.style.background = color;
      this.style.color = '#fff';
    });
  });
})();

/* ═══ B) Cascading dropdowns (locations + categories) ═══
   Logic:
   - parent → rebuild child options filtered by selected parent's id
   - child → rebuild grandchild options filtered by selected child's id
   - if parent cleared → child & grandchild also cleared
   - if child cleared → grandchild also cleared
   - on page load, apply current selection (from PHP $p) */
(function() {
  function getOptData(select) {
    if (!select) return [];
    if (select.dataset.locs) {
      try { return JSON.parse(select.dataset.locs); } catch(e){ return []; }
    }
    if (select.dataset.cats) {
      try { return JSON.parse(select.dataset.cats); } catch(e){ return []; }
    }
    // Fallback: read from <option data-parent-id="...">
    return Array.from(select.options).filter(o => o.dataset.parentId !== undefined)
      .map(o => ({ value: o.value, label: o.textContent, parent_id: parseInt(o.dataset.parentId) || 0 }));
  }

  function filterChild(parentSel, childSel, currentValue) {
    if (!parentSel || !childSel) return;
    const allData = getOptData(childSel);
    const parentOpt = parentSel.options[parentSel.selectedIndex];
    const parentId = parentOpt ? parseInt(parentOpt.dataset.locId || parentOpt.dataset.catId || 0) : 0;
    const placeholder = childSel.options[0] ? childSel.options[0].textContent : '';
    // clear child options except placeholder
    childSel.innerHTML = '';
    const ph = document.createElement('option');
    ph.value = '';
    ph.textContent = placeholder;
    childSel.appendChild(ph);
    // filter & add
    // القائمة الفرعية لا تظهر شيئاً إطلاقاً قبل اختيار حقيقي من القائمة الأعلى —
    // لا "عرض الكل افتراضياً" مهما كان السبب (يشمل الموقع والتصنيف معاً)
    allData.filter(o => parentId > 0 && o.parent_id === parentId).forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.value;
      opt.textContent = o.label;
      opt.dataset.parentId = o.parent_id;
      if (o.id) opt.dataset.locId = o.id;
      if (o.value === currentValue) opt.selected = true;
      childSel.appendChild(opt);
    });
  }

  function wireCascade() {
    document.querySelectorAll('.cascade-parent').forEach(parentSel => {
      const childName = parentSel.dataset.child;
      const grandName = parentSel.dataset.grand;
      const childSel = parentSel.form ? parentSel.form.querySelector(`[name="${childName}"]`) : document.querySelector(`[name="${childName}"]`);
      const grandSel = grandName ? (parentSel.form ? parentSel.form.querySelector(`[name="${grandName}"]`) : document.querySelector(`[name="${grandName}"]`)) : null;

      if (!childSel) return;

      // Save current values before clearing
      const childValue = childSel.value;
      const grandValue = grandSel ? grandSel.value : '';

      parentSel.addEventListener('change', function() {
        filterChild(parentSel, childSel, ''); // clear child on parent change
        if (grandSel) {
          // clear grandchild
          const ph = grandSel.options[0] ? grandSel.options[0].textContent : '';
          grandSel.innerHTML = '';
          const opt = document.createElement('option');
          opt.value = '';
          opt.textContent = ph;
          grandSel.appendChild(opt);
        }
      });

      if (childSel.classList.contains('cascade-child')) {
        const childParentName = childSel.dataset.parent;
        const childParentSel = document.querySelector(`[name="${childParentName}"]`);
        childSel.addEventListener('change', function() {
          if (grandSel) filterChild(childSel, grandSel, '');
        });
      }
    });

    // Apply cascade on page load (filter children based on current selection)
    document.querySelectorAll('.cascade-parent').forEach(parentSel => {
      const childName = parentSel.dataset.child;
      const grandName = parentSel.dataset.grand;
      const childSel = document.querySelector(`[name="${childName}"]`);
      const grandSel = grandName ? document.querySelector(`[name="${grandName}"]`) : null;
      if (childSel) {
        filterChild(parentSel, childSel, childSel.value);
        // نفلتر الحفيد دائماً — حتى بلا قيمة للابن، فتُفرَّغ قائمته
        // بدل أن تبقى بقائمتها الثابتة الأصلية غير المفلترة (كل الغرف / كل L3)
        if (grandSel) {
          filterChild(childSel, grandSel, grandSel.value);
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wireCascade);
  } else {
    wireCascade();
  }

  /* ── مزامنة location_id الحقيقي مع اختيار الغرفة النهائية ──
     مصدر الحقيقة: ROOM_ID_MAP مباشرة (خريطة PHP→JS ثابتة وموثوقة)،
     لا data-loc-id على الخيار (كان يعتمد على سلسلة طويلة قابلة للكسر). */
  function syncCertLocationId() {
    const roomSel = document.querySelector('[name="loc_room"]');
    const hidden  = document.getElementById('certLocationId');
    if (!roomSel || !hidden) return;
    const rid = ROOM_ID_MAP[roomSel.value];
    hidden.value = rid ? rid : '';
  }
  document.addEventListener('change', function(e){
    if (e.target && e.target.name === 'loc_room') syncCertLocationId();
  });
  // مزامنة أولى بعد اكتمال استرجاع التسلسل عند فتح مسودة محفوظة
  setTimeout(syncCertLocationId, 0);
})();
</script>
</body>
</html>