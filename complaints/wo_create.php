<?php
/**
 * complaints/wo_create.php — إنشاء أمر عمل من داخل البلاغ
 * يُنشئه مدير الصيانة فقط، مرتبط بالبلاغ، يوقف ساعة المهلة (SLA pause)
 * عند الإرسال للمقاول.
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('complaints.index');

$uid   = (int) current_user()['id'];
$can_manage = can('complaints.index','manage');
if (!$can_manage) {
    flash('danger','لا تملك صلاحية إنشاء أوامر العمل.');
    header('Location: ' . BASE_URL . '/complaints/index.php'); exit;
}

$cid = (int) ($_GET['complaint_id'] ?? 0);
if (!$cid) { http_response_code(400); die('معرف البلاغ مفقود'); }

$c = $pdo->prepare("
    SELECT c.*, a.description AS asset_desc, a.tag_number, a.manufacturer_name, a.model_number,
           a.serial_number, d.name AS dept_name
    FROM complaints c
    LEFT JOIN assets a ON a.id=c.asset_id
    LEFT JOIN departments d ON d.id=c.dept_id
    WHERE c.id=?
");
$c->execute([$cid]);
$complaint = $c->fetch();
if (!$complaint) { http_response_code(404); die('البلاغ غير موجود'); }

if (!in_array($complaint['status'], ['open','acknowledged','in_progress','stalled','escalated'])) {
    flash('warning','لا يمكن إنشاء أمر عمل لبلاغ في حالة: ' . $complaint['status']);
    header('Location: ' . BASE_URL . '/complaints/view.php?id=' . $cid); exit;
}

// تحديد نوع أمر العمل حسب نوع البلاغ
$wo_type = match($complaint['request_type'] ?? 'medical') {
    'medical'  => 'medical',
    'it'       => 'it',
    default    => 'general',
};
$wo_type_label = ['medical'=>'طبي (نموذج المشرق)','general'=>'صيانة عامة','it'=>'تقنية المعلومات'][$wo_type];
$wo_type_color = ['medical'=>'#0891b2','general'=>'#16a34a','it'=>'#7c3aed'][$wo_type];

// جلب أوامر العمل السابقة لهذا البلاغ
$prev_wo = $pdo->prepare("SELECT id, wo_number, status, created_at FROM complaint_work_orders WHERE complaint_id=? ORDER BY created_at DESC");
$prev_wo->execute([$cid]);
$prev_wo = $prev_wo->fetchAll();

// جلب المقاولين الفعّالين
// خريطة: نوع أمر العمل → نوع خدمة الشركة في جدول contractors
$wo_svc_map = [
    'medical' => 'medical_maintenance',
    'general' => 'general_maintenance',
    'it'      => 'it',
];
$matched_svc_type = $wo_svc_map[$wo_type] ?? null;

// جلب الشركات المطابقة لنوع أمر العمل أولاً، ثم الباقي كاحتياط
$contractors_matched = [];
$contractors_other   = [];
if ($matched_svc_type) {
    $s1 = $pdo->prepare("SELECT id, name, contract_number, service_type, contact_person, phone FROM contractors WHERE is_active=1 AND service_type=? ORDER BY name");
    $s1->execute([$matched_svc_type]);
    $contractors_matched = $s1->fetchAll();
    $s2 = $pdo->prepare("SELECT id, name, contract_number, service_type, contact_person, phone FROM contractors WHERE is_active=1 AND service_type!=? ORDER BY name");
    $s2->execute([$matched_svc_type]);
    $contractors_other = $s2->fetchAll();
} else {
    $contractors_other = $pdo->query("SELECT id, name, contract_number, service_type, contact_person, phone FROM contractors WHERE is_active=1 ORDER BY name")->fetchAll();
}
$contractors = array_merge($contractors_matched, $contractors_other);

// اختيار مسبق تلقائي إن كانت هناك شركة واحدة مطابقة فقط
$auto_selected_contractor = count($contractors_matched) === 1 ? $contractors_matched[0] : null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { $errors[] = 'خطأ CSRF.'; }
    else {
        $contractor_id   = (int) ($_POST['contractor_id'] ?? 0) ?: null;
        $contractor_name = trim($_POST['contractor_name'] ?? '');
        $engineer_name   = trim($_POST['engineer_name'] ?? '');
        $wo_date         = $_POST['wo_date'] ?? date('Y-m-d');
        $exp_date        = $_POST['expected_completion_date'] ?: null;
        $svc_desc        = trim($_POST['service_description'] ?? '');
        $services = [
            'service_power_supply'       => !empty($_POST['service_power_supply']),
            'service_electronic'         => !empty($_POST['service_electronic']),
            'service_chemical'           => !empty($_POST['service_chemical']),
            'service_planned_maintenance'=> !empty($_POST['service_planned_maintenance']),
            'service_calibration'        => !empty($_POST['service_calibration']),
            'service_equipment_fault'    => !empty($_POST['service_equipment_fault']),
            'service_spare_parts_required'=> !empty($_POST['service_spare_parts_required']),
            'service_rescreening'        => !empty($_POST['service_rescreening']),
            'service_spare_parts_stock'  => !empty($_POST['service_spare_parts_stock']),
        ];

        if (!$contractor_name) $errors[] = 'اسم الشركة المقاولة إلزامي.';
        if (!$svc_desc)        $errors[] = 'وصف العمل المطلوب إلزامي.';

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                // توليد رقم أمر العمل
                $yr  = date('Y');
                $seq = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(wo_number,'/',-1) AS UNSIGNED)),0)+1 FROM complaint_work_orders WHERE wo_number LIKE 'WO/{$yr}/%'")->fetchColumn();
                $wo_number = sprintf('WO/%s/%04d', $yr, $seq);

                $ins = $pdo->prepare("
                    INSERT INTO complaint_work_orders
                        (wo_number, complaint_id, contractor_id, contractor_name, engineer_name,
                         wo_date, expected_completion_date, service_description,
                         service_power_supply, service_electronic, service_chemical,
                         service_planned_maintenance, service_calibration, service_equipment_fault,
                         service_spare_parts_required, service_rescreening, service_spare_parts_stock,
                         wo_type, status, created_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft',?)
                ");
                $ins->execute([
                    $wo_number, $cid, $contractor_id, $contractor_name, $engineer_name,
                    $wo_date, $exp_date, $svc_desc,
                    $services['service_power_supply']        ? 1:0,
                    $services['service_electronic']          ? 1:0,
                    $services['service_chemical']            ? 1:0,
                    $services['service_planned_maintenance'] ? 1:0,
                    $services['service_calibration']         ? 1:0,
                    $services['service_equipment_fault']     ? 1:0,
                    $services['service_spare_parts_required']? 1:0,
                    $services['service_rescreening']         ? 1:0,
                    $services['service_spare_parts_stock']   ? 1:0,
                    $wo_type,
                    $uid,
                ]);
                $wo_id = (int) $pdo->lastInsertId();

                // تسجيل في سجل البلاغ
                $pdo->prepare("INSERT INTO complaint_timeline (complaint_id, action_type, action_label, old_status, new_status, actor_id) VALUES (?,'wo_created',?,?,?,?)")
                    ->execute([$cid, 'تم إنشاء أمر عمل: ' . $wo_number, $complaint['status'], $complaint['status'], $uid]);

                $pdo->commit();
                flash('success', 'تم إنشاء أمر العمل ' . $wo_number . ' بنجاح.');
                header('Location: ' . BASE_URL . '/complaints/wo_view.php?id=' . $wo_id); exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = 'فشل الحفظ: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'إنشاء أمر عمل — بلاغ ' . $complaint['request_number'];
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&family=Inter:wght@700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--bg:#f1f5f9;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;--primary:#2563eb}
body{background:var(--bg);font-family:'Tajawal',sans-serif}
.eng{font-family:'Inter',sans-serif}
.wrap{max-width:920px;margin:0 auto;padding:22px}
.h-banner{background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:22px;padding:22px 28px;color:#fff;margin-bottom:20px}
.h-banner h1{font-size:18px;font-weight:900;margin:0 0 6px;display:flex;align-items:center;gap:10px}
.h-banner p{font-size:12px;color:#94a3b8;margin:0}
.bento{background:var(--card);border-radius:18px;box-shadow:0 4px 16px rgba(0,0,0,.04);border:1px solid var(--border);padding:22px;margin-bottom:16px}
.bento-h{font-size:14px;font-weight:900;margin:0 0 16px;display:flex;align-items:center;gap:8px;color:var(--text)}
.bento-h i{color:var(--primary)}
.frow{display:grid;gap:14px;margin-bottom:14px}
.frow-2{grid-template-columns:1fr 1fr}
.frow-3{grid-template-columns:1fr 1fr 1fr}
label{font-size:12px;font-weight:800;color:var(--muted);display:block;margin-bottom:5px}
input,select,textarea{width:100%;border:2px solid var(--border);border-radius:10px;padding:10px 13px;font-family:'Tajawal';font-size:13px;outline:none;color:var(--text);background:#fff}
input:focus,select:focus,textarea:focus{border-color:var(--primary)}
.svc-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}
.svc-item{display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:10px 13px;cursor:pointer;transition:.2s;font-size:12.5px;font-weight:700}
.svc-item:hover{border-color:var(--primary);background:#eff6ff}
.svc-item input[type=checkbox]{width:16px;height:16px;cursor:pointer;flex-shrink:0}
.prev-wo{display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:9px 13px;text-decoration:none;margin-bottom:8px;font-size:12px}
.btn-primary{background:linear-gradient(135deg,var(--primary),#1d4ed8);color:#fff;border:none;padding:14px 28px;border-radius:12px;font-size:14px;font-weight:900;cursor:pointer;display:flex;align-items:center;gap:8px}
.flash{padding:12px 16px;border-radius:11px;margin-bottom:14px;font-weight:800;font-size:13px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="wrap">

<?php foreach ($flash_msgs as $fm): $fc=['success'=>'#10b981','warning'=>'#f59e0b','danger'=>'#ef4444'][$fm['type']]??'#3b82f6'; ?>
<div class="flash" style="background:#fff;border:1px solid <?=$fc?>44;border-right:4px solid <?=$fc?>"><?=e($fm['message'])?></div>
<?php endforeach; ?>
<?php foreach ($errors as $er): ?>
<div class="flash" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca"><i class="fa-solid fa-exclamation-circle"></i> <?=e($er)?></div>
<?php endforeach; ?>

<div class="h-banner">
    <h1><i class="fa-solid fa-clipboard-list" style="color:#fbbf24"></i> إنشاء أمر عمل</h1>
    <p>بلاغ: <span class="eng"><?=e($complaint['request_number'])?></span> · <?=e($complaint['asset_desc'] ?? $complaint['location_description'] ?? '—')?> · <?=e($complaint['dept_name'] ?? '—')?></p>
</div>

<div style="background:<?=$wo_type_color?>22;border:1px solid <?=$wo_type_color?>55;border-right:4px solid <?=$wo_type_color?>;border-radius:14px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;gap:12px">
    <i class="fa-solid fa-circle-info" style="color:<?=$wo_type_color?>;font-size:18px"></i>
    <div>
        <div style="font-size:13px;font-weight:900;color:<?=$wo_type_color?>">نوع أمر العمل: <?=e($wo_type_label)?></div>
        <div style="font-size:11.5px;color:var(--muted);font-weight:700;margin-top:2px">
            <?php if ($wo_type==='medical'): ?>نموذج شركة الصيانة الطبية المتعاقدة — يشمل خانات الخدمات الطبية وساعات العمل التفصيلية.
            <?php elseif ($wo_type==='general'): ?>نموذج الصيانة العامة — قيد البناء حتى وصول النموذج الرسمي. يمكن الاستخدام بالحقول الأساسية الآن.
            <?php else: ?>نموذج تقنية المعلومات — يُصمَّم وفق المعايير العالمية (ITIL). متاح بالحقول الأساسية الآن.
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($prev_wo): ?>
<div class="bento">
    <div class="bento-h"><i class="fa-solid fa-clock-rotate-left"></i> أوامر عمل سابقة على هذا البلاغ</div>
    <?php foreach ($prev_wo as $pw):
        $ws=['draft'=>['مسودة','#64748b'],'sent_to_contractor'=>['أُرسل للمقاول','#d97706'],'in_progress'=>['جاري','#2563eb'],'pending_manager_approval'=>['بانتظار الاعتماد','#7c3aed'],'completed'=>['مكتمل','#16a34a'],'rejected_by_manager'=>['مرفوض','#dc2626'],'cancelled'=>['مُلغى','#94a3b8']][$pw['status']]??['—','#94a3b8'];
    ?>
    <a href="<?=BASE_URL?>/complaints/wo_view.php?id=<?=$pw['id']?>" class="prev-wo">
        <i class="fa-solid fa-file-lines" style="color:var(--primary)"></i>
        <span class="eng" style="font-weight:800"><?=e($pw['wo_number'])?></span>
        <span style="flex:1;color:var(--muted)"><?=e(date('Y-m-d', strtotime($pw['created_at'])))?></span>
        <span style="background:<?=$ws[1]?>22;color:<?=$ws[1]?>;font-size:11px;font-weight:900;padding:3px 10px;border-radius:99px"><?=e($ws[0])?></span>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
<?= csrf_input() ?>

<div class="bento">
    <div class="bento-h"><i class="fa-solid fa-building"></i> بيانات الشركة المقاولة</div>
    <div class="frow frow-2">
        <div>
            <label>اختر من قائمة شركات الصيانة المتعاقدة</label>
            <select id="contractorSel" onchange="fillContractor(this)">
                <option value="">— إدخال يدوي —</option>
                <?php if ($contractors_matched): ?>
                <optgroup label="✅ مطابقة لنوع أمر العمل (<?= e($wo_type_label) ?>)">
                <?php foreach ($contractors_matched as $con): ?>
                <option value="<?=e($con['id'])?>"
                    data-name="<?=e($con['name'])?>"
                    data-contact="<?=e($con['contact_person']??'')?>"
                    data-phone="<?=e($con['phone']??'')?>"
                    <?= $auto_selected_contractor && $auto_selected_contractor['id']==$con['id'] ? 'selected' : '' ?>>
                    <?=e($con['name'])?><?=$con['contract_number']?' ('.$con['contract_number'].')':''?>
                </option>
                <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if ($contractors_other): ?>
                <optgroup label="— شركات أخرى —">
                <?php foreach ($contractors_other as $con): ?>
                <option value="<?=e($con['id'])?>"
                    data-name="<?=e($con['name'])?>"
                    data-contact="<?=e($con['contact_person']??'')?>"
                    data-phone="<?=e($con['phone']??'')?>">
                    <?=e($con['name'])?><?=$con['contract_number']?' ('.$con['contract_number'].')':''?>
                </option>
                <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
            </select>
            <?php if ($auto_selected_contractor): ?>
            <div style="font-size:10.5px;color:#0891b2;font-weight:700;margin-top:4px">
                <i class="fa-solid fa-circle-check"></i> تم اختيار <strong><?= e($auto_selected_contractor['name']) ?></strong> تلقائياً كالشركة الوحيدة المتعاقدة لهذا النوع.
            </div>
            <?php endif; ?>
        </div>
        <div>
            <label>اسم الشركة المقاولة <span style="color:#dc2626">*</span></label>
            <input type="text" name="contractor_name" id="contractorName" value="<?=e($_POST['contractor_name']??'')?>" required>
            <input type="hidden" name="contractor_id" id="contractorId">
        </div>
    </div>
    <div class="frow frow-3">
        <div>
            <label>اسم المهندس / الفني المنفِّذ</label>
            <input type="text" name="engineer_name" id="engineerName" value="<?=e($_POST['engineer_name']?? '')?>">
        </div>
        <div>
            <label>تاريخ أمر العمل <span style="color:#dc2626">*</span></label>
            <input type="date" name="wo_date" value="<?=e($_POST['wo_date']??date('Y-m-d'))?>" required>
        </div>
        <div>
            <label>التاريخ المتوقَّع للانتهاء</label>
            <input type="date" name="expected_completion_date" value="<?=e($_POST['expected_completion_date']?? '')?>">
        </div>
    </div>
</div>

<?php if ($wo_type === 'medical'): ?>
<div class="bento">
    <div class="bento-h"><i class="fa-solid fa-list-check"></i> نوع الخدمة المطلوبة (طبي)</div>
    <div class="svc-grid">
        <?php $svc_labels = [
            'service_power_supply'        => 'مصدر الطاقة',
            'service_electronic'          => 'إلكترونيات',
            'service_chemical'            => 'كيميائي',
            'service_planned_maintenance' => 'صيانة دورية',
            'service_calibration'         => 'معايرة / كاليبريشن',
            'service_equipment_fault'     => 'عطل معدّات',
            'service_spare_parts_required'=> 'قطع غيار مطلوبة',
            'service_rescreening'         => 'إعادة فحص',
            'service_spare_parts_stock'   => 'قطع غيار مستودع',
        ]; ?>
        <?php foreach ($svc_labels as $key => $label): ?>
        <label class="svc-item">
            <input type="checkbox" name="<?=e($key)?>" <?=!empty($_POST[$key])?'checked':''?>>
            <?=e($label)?>
        </label>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; // end medical services ?>

<?php if ($wo_type !== 'medical'): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:14px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:flex-start;gap:12px">
    <i class="fa-solid fa-circle-check" style="color:#16a34a;font-size:20px;flex-shrink:0;margin-top:2px"></i>
    <div>
        <div style="font-size:13px;font-weight:900;color:#15803d">النموذج المخصَّص قيد البناء</div>
        <div style="font-size:12px;color:#166534;font-weight:700;margin-top:4px;line-height:1.6">
            <?php if ($wo_type==='general'): ?>
            سيُضاف نموذج الصيانة العامة فور وصول الوثيقة الرسمية. يمكنك الآن إنشاء أمر عمل بالحقول الأساسية وسيُستكمَل لاحقاً.
            <?php else: ?>
            يُصمَّم نموذج IT وفق معايير ITIL الدولية. يمكنك الآن إنشاء أمر عمل بالحقول الأساسية.
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="bento">
    <div class="bento-h"><i class="fa-solid fa-pen-to-square"></i> تفاصيل العمل</div>
    <div style="margin-bottom:14px">
        <label>وصف العطل والعمل المطلوب <span style="color:#dc2626">*</span></label>
        <textarea name="service_description" rows="4" required><?=e($_POST['service_description']??$complaint['description']??'')?></textarea>
    </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center">
    <a href="<?=BASE_URL?>/complaints/view.php?id=<?=$cid?>" style="color:var(--muted);font-weight:800;font-size:13px;text-decoration:none"><i class="fa-solid fa-arrow-right"></i> العودة للبلاغ</a>
    <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> حفظ أمر العمل</button>
</div>

</form>
</div></main>
</div>
<script>
function fillContractor(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('contractorId').value   = opt.value || '';
    document.getElementById('contractorName').value  = opt.dataset.name || '';
    document.getElementById('engineerName').value    = opt.dataset.contact || '';
}
// تفعيل الاختيار المسبق التلقائي عند تحميل الصفحة
window.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('contractorSel');
    if (sel && sel.value) fillContractor(sel);
});
</script>
</body>
</html>