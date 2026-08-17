<?php
/**
 * complaints/work_order_create.php — إنشاء أمر إصلاح (Work Order)
 * خاص بالصيانة الطبية فقط - مرتبط بالبلاغ
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('complaints.index'); // نستخدم نفس صلاحية عرض البلاغات للتحكم

$u_data = current_user();
$uid = (int)($u_data['id'] ?? 0);
$can_manage = can('complaints.index', 'manage');

if (!$can_manage) {
    die('<div style="text-align:center;padding:50px;color:#dc2626;font-family:Tajawal;"><h3>غير مصرح لك بإنشاء أمر إصلاح.</h3><a href="' . BASE_URL . '/complaints/index.php">العودة</a></div>');
}

$complaint_id = (int)($_GET['complaint_id'] ?? 0);
if (!$complaint_id) {
    header('Location: ' . BASE_URL . '/complaints/index.php'); exit;
}

// جلب بيانات البلاغ
$s = $pdo->prepare("SELECT c.*, a.description AS asset_desc, a.tag_number, a.manufacturer_name, a.model_number, a.serial_number, d.name AS dept_name FROM complaints c LEFT JOIN assets a ON a.id = c.asset_id LEFT JOIN departments d ON d.id = c.dept_id WHERE c.id = ?");
$s->execute([$complaint_id]);
$c = $s->fetch(PDO::FETCH_ASSOC);

if (!$c) { die('البلاغ غير موجود.'); }

// 🔒 حماية صارمة: الصيانة الطبية فقط
if ($c['request_type'] !== 'medical') {
    die('<div style="text-align:center;padding:50px;color:#dc2626;font-family:Tajawal;"><h3>أمر الإصلاح متاح فقط للبلاغات الطبية.</h3><a href="' . BASE_URL . '/complaints/view.php?id=' . $complaint_id . '">العودة للبلاغ</a></div>');
}

// التحقق من عدم وجود أمر عمل مفتوح
$wo_check = $pdo->prepare("SELECT id, status FROM complaint_work_orders WHERE complaint_id = ? AND status NOT IN ('completed', 'cancelled', 'rejected_by_manager') LIMIT 1");
$wo_check->execute([$complaint_id]);
if ($wo_check->fetch()) {
    flash('warning', 'يوجد أمر إصلاح نشط لهذا البلاغ بالفعل.');
    header('Location: ' . BASE_URL . '/complaints/view.php?id=' . $complaint_id); exit;
}

// جلب الشركات (افتراض وجود جدول contractors، إذا لم يوجد يمكن استخدام نص حر)
// $contractors = $pdo->query("SELECT id, name FROM contractors WHERE is_active = 1 ORDER BY name")->fetchAll();
// للتبسيط الآن سنستخدم نص حر للشركة كما في النموذج الورقي

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'خطأ في الجلسة.'; }
    else {
        $contractor_name = trim($_POST['contractor_name'] ?? '');
        $engineer_name = trim($_POST['engineer_name'] ?? '');
        $wo_date = $_POST['wo_date'] ?? date('Y-m-d');
        
        // أنواع الخدمة
        $services = ['power_supply', 'electronic', 'chemical', 'planned_maintenance', 'calibration', 'equipment_fault', 'spare_parts_required', 'rescreening', 'spare_parts_stock'];
        $svc_data = [];
        foreach ($services as $s) $svc_data[$s] = isset($_POST['service_' . $s]) ? 1 : 0;
        
        $service_description = trim($_POST['service_description'] ?? '');
        $follow_up_notes = trim($_POST['follow_up_notes'] ?? '');
        $final_status = $_POST['final_status'] ?? 'pending';
        
        // ساعات العمل
        $h1 = (float)($_POST['work_hours_day1'] ?? 0);
        $h2 = (float)($_POST['work_hours_day2'] ?? 0);
        $h3 = (float)($_POST['work_hours_day3'] ?? 0);
        $h_total = $h1 + $h2 + $h3;
        $work_completed = isset($_POST['work_completed']) ? 1 : 0;
        
        // قطع الغيار
        $parts_desc = $_POST['parts_description'] ?? [];
        $parts_no = $_POST['parts_number'] ?? [];
        $parts_qty = $_POST['parts_quantity'] ?? [];
        $parts_rem = $_POST['parts_remarks'] ?? [];
        
        if (!$contractor_name) $errors[] = 'اسم الشركة/الجهة المنفذة مطلوب.';
        if (!$wo_date) $errors[] = 'التاريخ مطلوب.';
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                // توليد رقم أمر العمل
                $yr = date('Y');
                $seq = $pdo->query("SELECT COUNT(*)+1 FROM complaint_work_orders WHERE YEAR(created_at)=$yr")->fetchColumn();
                $wo_number = 'WO/' . $yr . '/' . str_pad($seq, 3, '0', STR_PAD_LEFT);
                
                $ins = $pdo->prepare("INSERT INTO complaint_work_orders (wo_number, complaint_id, contractor_name, engineer_name, wo_date, service_power_supply, service_electronic, service_chemical, service_planned_maintenance, service_calibration, service_equipment_fault, service_spare_parts_required, service_rescreening, service_spare_parts_stock, service_description, follow_up_notes, final_status, work_hours_day1, work_hours_day2, work_hours_day3, work_hours_total, work_completed, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent_to_contractor', ?)");
                $ins->execute([$wo_number, $complaint_id, $contractor_name, $engineer_name, $wo_date, $svc_data['power_supply'], $svc_data['electronic'], $svc_data['chemical'], $svc_data['planned_maintenance'], $svc_data['calibration'], $svc_data['equipment_fault'], $svc_data['spare_parts_required'], $svc_data['rescreening'], $svc_data['spare_parts_stock'], $service_description, $follow_up_notes, $final_status, $h1, $h2, $h3, $h_total, $work_completed, $uid]);
                
                $wo_id = $pdo->lastInsertId();
                
                // حفظ قطع الغيار
                if (!empty($parts_desc)) {
                    $ins_p = $pdo->prepare("INSERT INTO work_order_items (work_order_id, description, part_number, quantity, remarks) VALUES (?, ?, ?, ?, ?)");
                    foreach ($parts_desc as $i => $desc) {
                        if (!empty(trim($desc))) {
                            $ins_p->execute([$wo_id, trim($desc), trim($parts_no[$i] ?? ''), (int)($parts_qty[$i] ?? 1), trim($parts_rem[$i] ?? '')]);
                        }
                    }
                }
                
                // تسجيل في Timeline وتنبيه
                logTl($pdo, $complaint_id, 'work_order_created', 'تم إنشاء أمر إصلاح رقم ' . $wo_number . ' لـ ' . $contractor_name, $c['status'], $c['status'], $uid);
                notify_sys($pdo, $c['requested_by'], 'info', ' تم إنشاء أمر إصلاح', 'تم تحويل بلاغك لأمر إصلاح رقم ' . $wo_number, $complaint_id);
                
                $pdo->commit();
                flash('success', 'تم إنشاء أمر الإصلاح بنجاح: ' . $wo_number);
                header('Location: ' . BASE_URL . '/complaints/view.php?id=' . $complaint_id); exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'حدث خطأ: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'إنشاء أمر إصلاح - ' . $c['request_number'];
$csrf_token = csrf_token();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #1565C0; --border: #e2e8f0; --bg: #f8fafc; --text: #0f172a; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Tajawal', sans-serif; }
        body { background: var(--bg); color: var(--text); padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .bento-card { background: white; border-radius: 20px; border: 1px solid var(--border); box-shadow: 0 4px 20px rgba(0,0,0,0.05); margin-bottom: 20px; overflow: hidden; }
        .bento-title { background: linear-gradient(135deg, #1e3a8a, #2563eb); color: white; padding: 16px 20px; font-size: 16px; font-weight: 900; display: flex; align-items: center; gap: 10px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; padding: 20px; }
        .form-group label { font-size: 13px; font-weight: 800; color: #475569; margin-bottom: 6px; display: block; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 14px; }
        .form-group input:focus, .form-group textarea:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(21,101,192,0.1); }
        .checkbox-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; padding: 16px 20px; }
        .checkbox-item { display: flex; align-items: center; gap: 8px; background: #f8fafc; padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border); cursor: pointer; }
        .checkbox-item:hover { background: #e0f2fe; }
        .checkbox-item input { width: 18px; height: 18px; accent-color: var(--primary); }
        .checkbox-item label { font-size: 13px; font-weight: 700; cursor: pointer; flex: 1; }
        .parts-table { width: 100%; border-collapse: collapse; }
        .parts-table th { background: #f1f5f9; padding: 12px; text-align: right; font-size: 13px; font-weight: 800; color: #475569; border-bottom: 2px solid var(--border); }
        .parts-table td { padding: 10px; border-bottom: 1px solid var(--border); }
        .parts-table input { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; }
        .btn-add { background: #10b981; color: white; border: none; padding: 10px 20px; border-radius: 10px; font-weight: 800; cursor: pointer; margin: 10px 20px; }
        .btn-submit { background: linear-gradient(135deg, #1565C0, #2563eb); color: white; border: none; padding: 14px; border-radius: 12px; font-weight: 900; font-size: 15px; cursor: pointer; width: 100%; margin-top: 20px; }
        .btn-cancel { background: #f1f5f9; color: #475569; border: none; padding: 14px; border-radius: 12px; font-weight: 800; font-size: 15px; cursor: pointer; width: 100%; margin-top: 10px; text-decoration: none; display: block; text-align: center; }
        .complaint-info { background: linear-gradient(135deg, #fef3c7, #fde68a); padding: 16px 20px; border-bottom: 2px solid #f59e0b; }
        .complaint-info h3 { font-size: 14px; font-weight: 900; color: #92400e; margin-bottom: 8px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px; }
        .info-grid div { background: white; padding: 8px 12px; border-radius: 8px; }
        .info-grid strong { color: #64748b; font-weight: 700; }
        .error-box { background: #fef2f2; border: 1px solid #fecaca; border-right: 4px solid #dc2626; padding: 14px; border-radius: 12px; margin: 16px 20px; color: #991b1b; }
    </style>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 style="font-size: 22px; font-weight: 900;"><i class="fa-solid fa-file-signature" style="color: var(--primary);"></i> إنشاء أمر إصلاح جديد</h1>
            <a href="<?= BASE_URL ?>/complaints/view.php?id=<?= $complaint_id ?>" style="background: #f1f5f9; color: #475569; padding: 10px 18px; border-radius: 10px; text-decoration: none; font-weight: 800;">العودة للبلاغ</a>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="error-box"><strong>️ توجد أخطاء:</strong><ul style="margin:8px 0 0 20px;"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <!-- معلومات البلاغ -->
            <div class="bento-card">
                <div class="complaint-info">
                    <h3><i class="fa-solid fa-link"></i> البلاغ المرتبط: <?= e($c['request_number']) ?></h3>
                    <div class="info-grid">
                        <div><strong>الجهاز:</strong> <?= e($c['asset_desc'] ?? '—') ?></div>
                        <div><strong>القسم:</strong> <?= e($c['dept_name'] ?? '—') ?></div>
                        <div><strong>الموديل:</strong> <?= e($c['model_number'] ?? '—') ?></div>
                        <div><strong>السيريال:</strong> <?= e($c['serial_number'] ?? '—') ?></div>
                    </div>
                </div>
            </div>

            <!-- البيانات الأساسية -->
            <div class="bento-card">
                <div class="bento-title"><i class="fa-solid fa-building"></i> البيانات الأساسية</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>الشركة/الجهة المنفذة <span style="color:#dc2626">*</span></label>
                        <input type="text" name="contractor_name" required placeholder="مثال: شركة المشرق">
                    </div>
                    <div class="form-group">
                        <label>المهندس/الفني المنفذ</label>
                        <input type="text" name="engineer_name" placeholder="اسم المهندس">
                    </div>
                    <div class="form-group">
                        <label>تاريخ أمر العمل <span style="color:#dc2626">*</span></label>
                        <input type="date" name="wo_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
            </div>

            <!-- نوع الخدمة -->
            <div class="bento-card">
                <div class="bento-title"><i class="fa-solid fa-screwdriver-wrench"></i> نوع الخدمة</div>
                <div class="checkbox-grid">
                    <?php
                    $labels = ['power_supply'=>'Power Supply', 'electronic'=>'Electronic', 'chemical'=>'Chemical', 'planned_maintenance'=>'Planned Maintenance', 'calibration'=>'Calibration', 'equipment_fault'=>'Equipment of Fault', 'spare_parts_required'=>'Spare Parts Required', 'rescreening'=>'Re-screening', 'spare_parts_stock'=>'Spare Parts For Stock'];
                    foreach ($labels as $k => $v): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="service_<?= $k ?>" id="s_<?= $k ?>" value="1">
                        <label for="s_<?= $k ?>"><?= $v ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- الخدمة المنجزة -->
            <div class="bento-card">
                <div class="bento-title"><i class="fa-solid fa-clipboard-list"></i> شرح الأعطال والأعمال المنجزة (Service Done)</div>
                <div style="padding: 20px;">
                    <textarea name="service_description" rows="4" placeholder="اكتب شرحاً تفصيلياً..."></textarea>
                </div>
            </div>

            <!-- قطع الغيار -->
            <div class="bento-card">
                <div class="bento-title"><i class="fa-solid fa-gears"></i> قطع الغيار المطلوبة (Spare Parts Required)</div>
                <table class="parts-table" id="partsTable">
                    <thead><tr><th>الوصف (Description)</th><th>Part No.</th><th>QTY</th><th>ملاحظات</th></tr></thead>
                    <tbody id="partsBody">
                        <tr>
                            <td><input type="text" name="parts_description[]" placeholder="وصف القطعة"></td>
                            <td><input type="text" name="parts_number[]" placeholder="Part No."></td>
                            <td><input type="number" name="parts_quantity[]" min="1" value="1"></td>
                            <td><input type="text" name="parts_remarks[]"></td>
                        </tr>
                    </tbody>
                </table>
                <button type="button" class="btn-add" onclick="addPart()"><i class="fa-solid fa-plus"></i> إضافة بند</button>
            </div>

            <!-- الحالة وساعات العمل -->
            <div class="bento-card">
                <div class="bento-title"><i class="fa-solid fa-flag-checkered"></i> الحالة وساعات العمل</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>الحالة النهائية (Status)</label>
                        <select name="final_status">
                            <option value="pending">قيد الانتظار</option>
                            <option value="completed">Completed (مكتمل)</option>
                            <option value="working_need_parts">Working also need Spare Parts</option>
                            <option value="need_secondary_parts">تحتاج قطع ثانوية</option>
                            <option value="need_agent">Agent Required</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>هل تم إنجاز العمل؟</label>
                        <select name="work_completed"><option value="0">لا (No)</option><option value="1">نعم (Yes)</option></select>
                    </div>
                    <div class="form-group"><label>ساعات يوم 1</label><input type="number" name="work_hours_day1" step="0.5" value="0" class="calc-h"></div>
                    <div class="form-group"><label>ساعات يوم 2</label><input type="number" name="work_hours_day2" step="0.5" value="0" class="calc-h"></div>
                    <div class="form-group"><label>ساعات يوم 3</label><input type="number" name="work_hours_day3" step="0.5" value="0" class="calc-h"></div>
                    <div class="form-group"><label>المجموع (Total)</label><input type="number" id="h_total" readonly value="0" style="background:#f0f9ff;font-weight:900;"></div>
                </div>
                <div style="padding: 0 20px 20px;">
                    <label style="font-size:13px;font-weight:800;color:#475569;display:block;margin-bottom:6px;">المتابعة (Follow Up)</label>
                    <textarea name="follow_up_notes" rows="2"></textarea>
                </div>
            </div>

            <button type="submit" class="btn-submit"><i class="fa-solid fa-check-circle"></i> إنشاء أمر الإصلاح وإرساله</button>
            <a href="<?= BASE_URL ?>/complaints/view.php?id=<?= $complaint_id ?>" class="btn-cancel">إلغاء</a>
        </form>
    </div>
    <script>
        function addPart() {
            const tbody = document.getElementById('partsBody');
            const row = document.createElement('tr');
            row.innerHTML = `<td><input type="text" name="parts_description[]"></td><td><input type="text" name="parts_number[]"></td><td><input type="number" name="parts_quantity[]" min="1" value="1"></td><td><input type="text" name="parts_remarks[]"></td>`;
            tbody.appendChild(row);
        }
        document.querySelectorAll('.calc-h').forEach(i => i.addEventListener('input', () => {
            let t = 0; document.querySelectorAll('.calc-h').forEach(x => t += parseFloat(x.value)||0);
            document.getElementById('h_total').value = t.toFixed(2);
        }));
    </script>
</body>
</html>