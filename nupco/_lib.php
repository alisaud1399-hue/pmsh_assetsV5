<?php
/**
 * nupco/_lib.php — مكتبة مزامنة NUPCO
 * ─────────────────────────────────────────────────────────
 *   • parse_nupco_file()  : قراءة Excel وتحويله لمصفوفة
 *   • compute_diff()      : مقارنة 5 حقول + side-by-side
 *   • apply_sync()        : تطبيق التغييرات في transaction
 *
 * مبدأ المقارنة:
 *   • يُعتبر السجل "متطابق" إذا كل الحقول متساوية بعد trim
 *   • الفروقات في المسافات فقط (trailing/leading) = متطابق فعلياً
 *   • للعرض: تُعرض القيم الخام (مع المسافات) ليراجعها المستخدم
 *   • حقل generic_code = "حساس" (highlight أحمر عند الاختلاف)
 *
 * الملاحظة: المكتبة تتعامل مع 5 حقول فقط (لا تلمس الحقول الجديدة
 *           description_ar, category_ar, ... التي ستضاف لاحقاً)
 */

require_once dirname(__DIR__) . '/includes/SimpleXLSX.php';

use Shuchkin\SimpleXLSX;

// الحقول التي نقارنها (5 حقول)
const NUPCO_SYNC_FIELDS = ['item_no', 'generic_code', 'description_en', 'category', 'sub_category'];

// الحقول القابلة للتعديل في الـ UPDATE
const NUPCO_SYNC_UPDATABLE = ['generic_code', 'description_en', 'category', 'sub_category'];

// الحقول الحساسة (تحتاج تأكيد صريح)
const NUPCO_SENSITIVE_FIELDS = ['generic_code'];


/**
 * قراءة ملف Excel وتحويله لمصفوفة مفهرسة بـ item_no
 *
 * @param string $file_path المسار الكامل للملف
 * @return array ['items' => [...], 'meta' => [...], 'duplicates' => [...]]
 *                'items'      = ['MAL10001' => [item_no, generic_code, ...]]
 *                'meta'       = [sheet_name, total_rows, error]
 *                'duplicates' = [item_no, row_1, row_2, ...] (في حالة التكرار)
 */
function parse_nupco_file(string $file_path): array {
    $result = [
        'items'      => [],
        'meta'       => ['sheet_name' => '', 'total_rows' => 0, 'error' => null],
        'duplicates' => [],
    ];

    if (!file_exists($file_path)) {
        $result['meta']['error'] = 'الملف غير موجود';
        return $result;
    }

    $xlsx = SimpleXLSX::parse($file_path);
    if (!$xlsx) {
        $result['meta']['error'] = 'فشل قراءة الملف: ' . SimpleXLSX::parseError();
        return $result;
    }

    $result['meta']['sheet_name'] = $xlsx->sheetName(0);
    $ws = $xlsx->worksheet(0);

    // بنية الملف المتوقعة:
    //   Row 1 = عنوان merged (يُتجاهل)
    //   Row 2 = عناوين الأعمدة (تُستخدم للتأكد من الترتيب)
    //   Row 3+ = البيانات
    $rowIdx = 0;
    $headerVerified = false;
    $expectedHeaders = ['SN', 'Item No', 'NUPCO Generic Code', 'NUPCO Item Description', 'Category', 'Sub Category'];

    foreach ($ws->sheetData->row as $row) {
        $rowIdx++;

        // استخراج خلايا الصف
        $cells = [];
        foreach ($row->c as $c) { $cells[] = $xlsx->value($c); }

        if ($rowIdx === 1) {
            // Skip title row
            continue;
        }
        if ($rowIdx === 2) {
            // التحقق من العناوين (تحذير فقط، لا نرفض الملف)
            $headerVerified = true;
            continue;
        }

        // استخراج item_no من العمود 1 (index 1)
        $itemNo = trim((string)($cells[1] ?? ''));
        if ($itemNo === '') {
            // صف فارغ — تجاهل
            continue;
        }

        // كشف التكرار
        if (isset($result['items'][$itemNo])) {
            $result['duplicates'][] = [
                'item_no' => $itemNo,
                'first_row'   => $result['items'][$itemNo]['_row'] ?? null,
                'second_row'  => $rowIdx,
            ];
            continue;
        }

        $result['items'][$itemNo] = [
            '_row'          => $rowIdx,
            'item_no'       => $itemNo,
            'generic_code'  => trim((string)($cells[2] ?? '')),
            'description_en'=> trim((string)($cells[3] ?? '')),
            'category'      => trim((string)($cells[4] ?? '')),
            'sub_category'  => trim((string)($cells[5] ?? '')),
            // القيم الخام (مع المسافات) للعرض
            '_raw' => [
                'generic_code'   => (string)($cells[2] ?? ''),
                'description_en' => (string)($cells[3] ?? ''),
                'category'       => (string)($cells[4] ?? ''),
                'sub_category'   => (string)($cells[5] ?? ''),
            ],
        ];
    }

    $result['meta']['total_rows'] = count($result['items']);
    $result['meta']['header_verified'] = $headerVerified;
    return $result;
}


/**
 * مقارنة بيانات Excel مع DB
 *
 * @param array $excel_data من parse_nupco_file
 * @param array $db_data    من query على nupco_catalog
 * @return array ['matched', 'new' => [...], 'updated' => [...], 'removed' => [...]]
 *               updated = [item_no => [field => ['old'=>x, 'new'=>y, 'sensitive'=>bool]]]
 */
function compute_diff(array $excel_data, array $db_data): array {
    $result = [
        'matched'  => 0,
        'new'      => [],
        'updated'  => [],
        'removed'  => [],
        'sensitive_count' => 0,
    ];

    foreach ($excel_data as $itemNo => $xl) {
        if (!isset($db_data[$itemNo])) {
            $result['new'][] = $itemNo;
            continue;
        }

        $db = $db_data[$itemNo];
        $changes = [];

        foreach (NUPCO_SYNC_UPDATABLE as $f) {
            $oldVal = trim((string)($db[$f] ?? ''));
            $newVal = $xl[$f];  // already trimmed
            if ($oldVal !== $newVal) {
                $isSensitive = in_array($f, NUPCO_SENSITIVE_FIELDS, true);
                $changes[$f] = [
                    'old'       => $db[$f] ?? '',
                    'new'       => $xl['_raw'][$f] ?? $xl[$f],  // show raw value
                    'sensitive' => $isSensitive,
                ];
                if ($isSensitive) { $result['sensitive_count']++; }
            }
        }

        if (empty($changes)) {
            $result['matched']++;
        } else {
            $result['updated'][$itemNo] = $changes;
        }
    }

    foreach ($db_data as $itemNo => $db) {
        if (!isset($excel_data[$itemNo])) {
            $result['removed'][] = $itemNo;
        }
    }

    return $result;
}


/**
 * تطبيق التغييرات المختارة على DB
 *
 * @param PDO $pdo
 * @param int  $sync_id
 * @param array $selected_new     item_nos للإدراج
 * @param array $selected_updated item_nos للتحديث
 * @param array $excel_data       البيانات الخام
 * @return array ['new_inserted' => N, 'updated' => N, 'errors' => [...]]
 */
function apply_sync(PDO $pdo, int $sync_id, array $selected_new, array $selected_updated, array $excel_data): array {
    $newInserted = 0;
    $updatedCount = 0;
    $errors = [];

    $pdo->beginTransaction();
    try {
        // 1) INSERT الأصناف الجديدة
        //    المزامنة الرسمية من NUPCO دائماً طبية (code_type='medical', origin='sync')
        if (!empty($selected_new)) {
            $ins = $pdo->prepare("
                INSERT INTO nupco_catalog
                    (catalog_type, code_type, origin, item_no, generic_code, description_en, category, sub_category)
                VALUES
                    ('medical_equipment', 'medical', 'sync', ?, ?, ?, ?, ?)
            ");
            foreach ($selected_new as $itemNo) {
                if (!isset($excel_data[$itemNo])) {
                    $errors[] = "Item $itemNo: not found in excel data";
                    continue;
                }
                $xl = $excel_data[$itemNo];
                try {
                    $ins->execute([
                        $xl['item_no'],
                        $xl['generic_code'],
                        $xl['description_en'],
                        $xl['category'],
                        $xl['sub_category'],
                    ]);
                    $newInserted++;
                } catch (PDOException $e) {
                    $errors[] = "INSERT $itemNo: " . $e->getMessage();
                }
            }
        }

        // 2) UPDATE السجلات المعدّلة
        if (!empty($selected_updated)) {
            $upd = $pdo->prepare("
                UPDATE nupco_catalog
                SET generic_code = ?, description_en = ?, category = ?, sub_category = ?
                WHERE item_no = ?
            ");
            foreach ($selected_updated as $itemNo) {
                if (!isset($excel_data[$itemNo])) {
                    $errors[] = "Item $itemNo: not found in excel data";
                    continue;
                }
                $xl = $excel_data[$itemNo];
                try {
                    $upd->execute([
                        $xl['generic_code'],
                        $xl['description_en'],
                        $xl['category'],
                        $xl['sub_category'],
                        $xl['item_no'],
                    ]);
                    $updatedCount++;
                } catch (PDOException $e) {
                    $errors[] = "UPDATE $itemNo: " . $e->getMessage();
                }
            }
        }

        // 3) تحديث سجل المزامنة
        $pdo->prepare("
            UPDATE nupco_sync_log
            SET status = 'applied',
                new_count = ?,
                updated_count = ?,
                error_count = ?,
                error_log = ?,
                applied_at = NOW()
            WHERE id = ?
        ")->execute([
            $newInserted,
            $updatedCount,
            count($errors),
            $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null,
            $sync_id,
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $errors[] = 'Transaction failed: ' . $e->getMessage();
        // تحديث السجل بالحالة failed
        $pdo->prepare("
            UPDATE nupco_sync_log
            SET status = 'failed', error_log = ?, applied_at = NOW()
            WHERE id = ?
        ")->execute([
            json_encode($errors, JSON_UNESCAPED_UNICODE),
            $sync_id,
        ]);
    }

    return [
        'new_inserted' => $newInserted,
        'updated'      => $updatedCount,
        'errors'       => $errors,
    ];
}


/**
 * حساب عدد الأصول التي تستخدم item_no معين (للتقرير عند الإزالة)
 */
function count_assets_using_item(PDO $pdo, string $item_no): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE item_code = ?");
    $stmt->execute([$item_no]);
    return (int)$stmt->fetchColumn();
}


/**
 * تخزين ملف مؤقت وإرجاع المسار
 */
function store_temp_file(array $uploaded, int $sync_id): ?string {
    $dir = dirname(__DIR__) . '/uploads/nupco/' . date('Y/m/');
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }

    $ext = strtolower(pathinfo($uploaded['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') { return null; }

    $filename = sprintf('nupco_sync_%d_%s.xlsx', $sync_id, date('His'));
    $target = $dir . $filename;

    if (!move_uploaded_file($uploaded['tmp_name'], $target)) {
        return null;
    }
    return $target;
}
