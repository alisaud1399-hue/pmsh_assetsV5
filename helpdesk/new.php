<?php
/**
 * helpdesk/new.php — Wizard 3 خطوات
 *
 * Step 1: اختر الجد (grandparent category)
 * Step 2: اختر الأب (sub-category) — إن وُجد
 * Step 3: املأ التفاصيل + form fields الديناميكية
 *
 * URL pattern:
 *   new.php                      → Step 1
 *   new.php?cat=N                → Step 2 (أبناء الجد N)
 *   new.php?cat=N&sub=M          → Step 3 (form fields للـ leaf M)
 *
 * الصفحة صغيرة ومركزة في كل خطوة (لا clutter).
 */
require_once dirname(__DIR__) . '/config.php';
require_once BASE_PATH . '/includes/helpdesk_helpers.php';
require_once BASE_PATH . '/includes/helpdesk_form_renderer.php';

page_guard('helpdesk', 'create');
global $pdo;
$user_id = (int) current_user()['id'];
$user_lang = current_lang();

$error = null;
$cat_id = (int)($_GET['cat'] ?? $_POST['category_id'] ?? 0);
$sub_id = (int)($_GET['sub'] ?? $_POST['subcategory_id'] ?? 0);

// تحديد الـ step
if ($cat_id > 0 && $sub_id > 0) {
    $step = 3;
} elseif ($cat_id > 0) {
    $step = 2;
} else {
    $step = 1;
}

$grandparent = null;
$subcategories = [];
$leaf = null;
$leaf_fields = [];
$breadcrumb = [];

if ($cat_id > 0) {
    $grandparent = helpdesk_get_category_by_id($pdo, $cat_id);
    if (!$grandparent) {
        flash('error', 'تصنيف غير موجود');
        header('Location: ' . BASE_URL . '/helpdesk/new.php');
        exit;
    }
    $breadcrumb[] = $grandparent;
    $subcategories = $pdo->prepare("SELECT * FROM helpdesk_categories WHERE parent_id = ? AND is_active = 1 ORDER BY sort_order, name_ar");
    $subcategories->execute([$cat_id]);
    $subcategories = $subcategories->fetchAll(PDO::FETCH_ASSOC);

    // إذا ما في أبناء، الجد نفسه هو الـ leaf (يستخدم form fields الخاصة به)
    if (!$subcategories) {
        $leaf = $grandparent;
        $leaf_fields = helpdesk_get_form_fields($pdo, $cat_id);
    } else {
        // في أبناء — لو المستخدم اختار sub، حدد الـ leaf
        if ($sub_id > 0) {
            $leaf = helpdesk_get_category_by_id($pdo, $sub_id);
            if ($leaf) {
                $breadcrumb[] = $leaf;
                $leaf_fields = helpdesk_get_form_fields($pdo, $sub_id);
            }
        }
    }
}

// Submit في Step 3
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf() && $step === 3 && $leaf) {
    $data = [
        'category_id'    => (int)($_POST['category_id'] ?? $cat_id),
        'subcategory_id' => (int)($_POST['subcategory_id'] ?? $sub_id),
        'title'          => trim($_POST['title'] ?? ''),
        'description'    => trim($_POST['description'] ?? ''),
        'priority'       => $_POST['priority'] ?? 'medium',
        'language'       => $user_lang,
    ];

    // Phase 5: Smart Context Capture
    $ctx_url = trim((string)($_POST['ctx_url'] ?? ''));
    if ($ctx_url !== '') {
        $data['context'] = [
            'url'         => $ctx_url,
            'page_title'  => trim((string)($_POST['ctx_page_title'] ?? '')),
            'user_agent'  => trim((string)($_POST['ctx_ua'] ?? '')),
            'perms_json'  => (string)($_POST['ctx_perms'] ?? '{}'),
            'client_ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
    }

    // جمع form values
    $form_values = $_POST['fields'] ?? [];

    // Server-side validation للحقول المطلوبة
    foreach ($leaf_fields as $f) {
        if ($f['is_required']) {
            $val = $form_values[$f['field_key']] ?? '';
            if (empty($val) || (is_array($val) && count($val) === 0)) {
                $error = "حقل مطلوب: {$f['field_label_ar']}";
                break;
            }
        }
    }

    if (!$error) {
        $r = helpdesk_create_ticket($pdo, $data, $user_id, $form_values);
        if ($r['ok']) {
            flash('success', "تم إنشاء التذكرة {$r['ticket_number']} بنجاح");
            header('Location: ' . BASE_URL . '/helpdesk/view.php?id=' . $r['id']);
            exit;
        } else {
            $error = $r['error'];
        }
    }
}

// جلب الأجداد للـ Step 1
$all_cats = helpdesk_get_categories($pdo);
// فقط الـ roots (parent_id IS NULL)
$grandparents = array_filter($all_cats, fn($c) => $c['parent_id'] === null);

$page_title = 'تذكرة جديدة';
$active_nav = 'helpdesk';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title><?= e($page_title) ?> — نظام التذاكر</title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --primary:#4338ca; --primary-light:#eef2ff; --border:#e2e8f0; --bg:#f8fafc; --text-main:#0f172a; --text-2:#475569; --text-3:#94a3b8; }
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Tajawal', sans-serif; background:var(--bg); color:var(--text-main); }
        .container { max-width: 800px; margin: 0 auto; padding: 24px 20px; }

        .back { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; background:#fff; color:var(--text-2); border:1px solid var(--border); border-radius:8px; text-decoration:none; font-weight:700; font-size:12.5px; margin-bottom:12px; }
        .back:hover { background:#f1f5f9; }

        /* ──── Step Indicator ──── */
        .steps { display:flex; align-items:center; justify-content:center; gap:0; margin-bottom:18px; }
        .step-item { display:flex; align-items:center; gap:8px; padding:6px 12px; border-radius:99px; font-size:12px; font-weight:800; color:var(--text-3); }
        .step-item .sn { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; background:#e2e8f0; color:var(--text-2); }
        .step-item.active { color:var(--primary); }
        .step-item.active .sn { background:var(--primary); color:#fff; }
        .step-item.done { color:#16a34a; }
        .step-item.done .sn { background:#16a34a; color:#fff; }
        .step-line { width:24px; height:1.5px; background:#e2e8f0; }
        .step-line.done { background:#16a34a; }

        /* ──── Hero (دائماً صغير) ──── */
        .hero { background:linear-gradient(135deg, #4338ca, #7c3aed); color:#fff; border-radius:14px; padding:16px 22px; margin-bottom:16px; }
        .hero h1 { font-size:18px; font-weight:900; margin:0 0 4px; }
        .hero p { font-size:12.5px; opacity:0.9; margin:0; }

        /* ──── Step Content ──── */
        .step-content { background:#fff; border:1px solid var(--border); border-radius:14px; padding:22px 26px; }

        /* breadcrumb */
        .crumb { display:flex; align-items:center; gap:6px; font-size:12px; color:var(--text-3); margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid var(--border); }
        .crumb a { color:var(--primary); text-decoration:none; font-weight:700; }
        .crumb i { font-size:9px; }

        /* Step 1: 8 grandparent cards in 2x4 grid */
        .gp-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:10px; }
        .gp-card { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1.5px solid #e2e8f0; border-radius:10px; text-decoration:none; color:var(--text-main); transition:all 0.15s; background:#fff; }
        .gp-card:hover { border-color:var(--primary); background:#fafbff; transform:translateY(-1px); box-shadow:0 4px 12px rgba(67,56,202,0.08); }
        .gp-card .gi { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .gp-card .gt { font-weight:800; font-size:13px; }
        .gp-card .gd { font-size:10.5px; color:var(--text-3); }

        /* Step 2: sub-list */
        .sub-list { list-style:none; padding:0; margin:0; }
        .sub-item { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1.5px solid #e2e8f0; border-radius:10px; margin-bottom:8px; text-decoration:none; color:var(--text-main); transition:all 0.15s; background:#fff; }
        .sub-item:hover { border-color:var(--primary); background:#fafbff; }
        .sub-item .si { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0; }
        .sub-item .sn { font-weight:800; font-size:13.5px; flex:1; }
        .sub-item .sd { font-size:11px; color:var(--text-3); line-height:1.4; }
        .sub-item .sa { color:var(--primary); }

        /* Step 3: form */
        .form-row { margin-bottom:14px; }

        /* ── Title Locked (Auto-generated) ── */
        .form-row.title-locked { background:linear-gradient(135deg, #f8fafc, #eef2ff); border:1.5px solid #c7d2fe; border-radius:10px; padding:12px 14px; }
        .form-row.title-locked label { color:var(--primary); }
        .form-row.title-locked .lock-ic { font-size:11px; background:var(--primary); color:#fff; padding:2px 6px; border-radius:4px; margin-inline-start:6px; }
        .title-display { display:flex; align-items:center; gap:10px; padding:10px 14px; background:#fff; border:1.5px solid #e2e8f0; border-radius:8px; }
        .title-display .title-text { font-weight:800; font-size:15px; color:var(--text-main); flex:1; }
        .form-row.title-locked .help { color:var(--primary); margin-top:8px; }
        .form-row label { display:block; font-size:13px; font-weight:800; color:var(--text-main); margin-bottom:5px; }
        .form-row label .req { color:#dc2626; }
        .form-row .help { font-size:11px; color:var(--text-3); margin-top:3px; }
        .form-row input[type="text"],
        .form-row input[type="number"],
        .form-row input[type="date"],
        .form-row input[type="datetime"],
        .form-row select,
        .form-row textarea {
            width:100%; padding:9px 11px; border:1.5px solid #cbd5e1; border-radius:8px; font-size:13px; font-family:'Tajawal';
            box-sizing:border-box;
        }
        .form-row textarea { min-height:80px; resize:vertical; }
        .form-row input:focus, .form-row select:focus, .form-row textarea:focus { outline:none; border-color:var(--primary); }

        .prio-pills { display:flex; gap:6px; flex-wrap:wrap; }
        .prio-pill { display:inline-flex; align-items:center; gap:5px; padding:6px 11px; border:1.5px solid #cbd5e1; border-radius:8px; cursor:pointer; font-weight:800; font-size:12px; }
        .prio-pill input { display:none; }
        .prio-pill .dot { width:9px; height:9px; border-radius:50%; }
        .prio-pill.low { color:#16a34a; } .prio-pill.low .dot { background:#16a34a; }
        .prio-pill.medium { color:#0ea5e9; } .prio-pill.medium .dot { background:#0ea5e9; }
        .prio-pill.high { color:#f59e0b; } .prio-pill.high .dot { background:#f59e0b; }
        .prio-pill.critical { color:#dc2626; } .prio-pill.critical .dot { background:#dc2626; }
        .prio-pill:has(input:checked) { background:var(--primary-light); border-color:var(--primary); }

        .multi-grid { display:flex; flex-wrap:wrap; gap:6px; }
        .multi-pill { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border:1.5px solid #cbd5e1; border-radius:6px; cursor:pointer; font-weight:800; font-size:11.5px; }
        .multi-pill input { margin:0; }
        .multi-pill:has(input:checked) { background:var(--primary-light); border-color:var(--primary); color:var(--primary); }

        .form-actions { display:flex; gap:10px; padding-top:14px; border-top:1px solid var(--border); margin-top:18px; }
        .btn { padding:10px 20px; border:0; border-radius:10px; font-weight:800; font-size:13px; cursor:pointer; font-family:'Tajawal'; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
        .btn.pri { background:var(--primary); color:#fff; }
        .btn.pri:hover { background:#312e81; }
        .btn.sec { background:#fff; color:var(--text-2); border:1.5px solid #cbd5e1; }
        .btn.sec:hover { background:#f1f5f9; }

        .error-banner { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:10px; margin-bottom:12px; font-size:12.5px; font-weight:700; }
        .error-banner i { margin-inline-end:6px; }

        .empty { text-align:center; padding:20px; color:var(--text-3); font-size:12.5px; }

        .kb-hint { background:var(--primary-light); border-inline-start:3px solid var(--primary); padding:8px 12px; border-radius:6px; font-size:11.5px; color:#1e3a8a; margin-top:10px; }
        .kb-hint a { color:var(--primary); font-weight:800; text-decoration:underline; margin-inline-start:4px; }

        .form-section-h { font-size:12px; color:var(--text-2); font-weight:800; text-transform:uppercase; letter-spacing:0.4px; margin:18px 0 8px; padding-bottom:6px; border-bottom:1px solid var(--border); }
        .form-section-h:first-child { margin-top:0; }
    </style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="container">

    <a href="<?= BASE_URL ?>/helpdesk/index.php" class="back">
        <i class="fa-solid fa-arrow-right"></i> العودة للقائمة
    </a>

    <!-- Step Indicator -->
    <div class="steps">
        <div class="step-item <?= $step >= 1 ? ($step>1?'done':'active') : '' ?>">
            <span class="sn"><?= $step>1 ? '✓' : '1' ?></span>
            <span>التصنيف الرئيسي</span>
        </div>
        <div class="step-line <?= $step>1?'done':'' ?>"></div>
        <div class="step-item <?= $step >= 2 ? ($step>2?'done':'active') : '' ?>">
            <span class="sn"><?= $step>2 ? '✓' : '2' ?></span>
            <span>الخدمة</span>
        </div>
        <div class="step-line <?= $step>2?'done':'' ?>"></div>
        <div class="step-item <?= $step >= 3 ? 'active' : '' ?>">
            <span class="sn">3</span>
            <span>التفاصيل</span>
        </div>
    </div>

    <!-- Hero (دائماً صغير) -->
    <div class="hero">
        <h1><?= $step===1 ? 'تذكرة جديدة' : ($step===2 ? e($grandparent['name_ar']) : e($leaf['name_ar'])) ?></h1>
        <p>
            <?= $step===1 ? 'اختر التصنيف الرئيسي لطلبك' : '' ?>
            <?= $step===2 ? 'اختر الخدمة المطلوبة' : '' ?>
            <?= $step===3 ? 'املأ التفاصيل وأرسل التذكرة' : '' ?>
        </p>
    </div>

    <?php foreach ($flash_msgs as $fm): ?>
        <div class="error-banner" style="background:<?= $fm['type']==='success'?'#dcfce7':'#fee2e2' ?>;border-color:<?= $fm['type']==='success'?'#86efac':'#fca5a5' ?>;color:<?= $fm['type']==='success'?'#14532d':'#7f1d1d' ?>;">
            <i class="fa-solid fa-<?= $fm['type']==='success'?'check':'circle-exclamation' ?>"></i>
            <?= e($fm['message']) ?>
        </div>
    <?php endforeach; ?>

    <?php if ($error): ?>
        <div class="error-banner"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
    <?php endif; ?>

    <!-- ──── Step Content ──── -->
    <div class="step-content">

        <?php if ($step === 1): ?>
            <!-- ═══ STEP 1: اختر الجد ═══ -->
            <div class="form-section-h">اختر التصنيف الرئيسي لطلبك</div>
            <div class="gp-grid">
                <?php foreach ($grandparents as $gp): ?>
                    <?php
                    // عدد الأبناء
                    $child_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM helpdesk_categories WHERE parent_id = ? AND is_active = 1");
                    $child_count_stmt->execute([$gp['id']]);
                    $child_count = (int)$child_count_stmt->fetchColumn();
                    ?>
                    <a href="?cat=<?= (int)$gp['id'] ?>" class="gp-card">
                        <div class="gi" style="background:<?= e($gp['color']) ?>22;color:<?= e($gp['color']) ?>">
                            <i class="fa-solid <?= e($gp['icon']) ?>"></i>
                        </div>
                        <div style="flex:1;min-width:0">
                            <div class="gt"><?= e($gp['name_ar']) ?></div>
                            <div class="gd">
                                <?php if ($child_count > 0): ?>
                                    <i class="fa-solid fa-layer-group"></i> <?= $child_count ?> خدمة فرعية
                                <?php else: ?>
                                    <i class="fa-solid fa-circle"></i> خدمة مباشرة
                                <?php endif; ?>
                            </div>
                        </div>
                        <i class="fa-solid fa-chevron-left" style="color:var(--text-3);font-size:11px"></i>
                    </a>
                <?php endforeach; ?>
            </div>

        <?php elseif ($step === 2): ?>
            <!-- ═══ STEP 2: اختر الأب ═══ -->
            <div class="crumb">
                <a href="<?= BASE_URL ?>/helpdesk/new.php">التصنيف الرئيسي</a>
                <i class="fa-solid fa-chevron-left"></i>
                <span><?= e($grandparent['name_ar']) ?></span>
            </div>
            <div class="form-section-h">اختر الخدمة المطلوبة</div>

            <?php if ($subcategories): ?>
                <ul class="sub-list">
                    <?php foreach ($subcategories as $sub): ?>
                        <li>
                            <a href="?cat=<?= (int)$cat_id ?>&sub=<?= (int)$sub['id'] ?>" class="sub-item">
                                <div class="si" style="background:<?= e($sub['color']) ?>22;color:<?= e($sub['color']) ?>">
                                    <i class="fa-solid <?= e($sub['icon']) ?>"></i>
                                </div>
                                <div style="flex:1;min-width:0">
                                    <div class="sn"><?= e($sub['name_ar']) ?></div>
                                    <?php if (!empty($sub['description_ar'])): ?>
                                        <div class="sd"><?= e(mb_substr($sub['description_ar'], 0, 100, 'UTF-8')) ?></div>
                                    <?php endif; ?>
                                </div>
                                <i class="fa-solid fa-chevron-left sa"></i>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="empty">لا توجد خدمات فرعية — يمكنك المتابعة مباشرة</div>
                <a href="?cat=<?= (int)$cat_id ?>&sub=<?= (int)$cat_id ?>" class="btn pri" style="margin-top:10px">
                    المتابعة للتفاصيل <i class="fa-solid fa-arrow-left"></i>
                </a>
            <?php endif; ?>

        <?php elseif ($step === 3): ?>
            <!-- ═══ STEP 3: التفاصيل + Form Fields ═══ -->
            <div class="crumb">
                <a href="<?= BASE_URL ?>/helpdesk/new.php">التصنيف الرئيسي</a>
                <i class="fa-solid fa-chevron-left"></i>
                <?php if ($leaf['parent_id']): ?>
                    <a href="?cat=<?= (int)$leaf['parent_id'] ?>"><?= e($breadcrumb[0]['name_ar'] ?? '') ?></a>
                    <i class="fa-solid fa-chevron-left"></i>
                <?php endif; ?>
                <span><?= e($leaf['name_ar']) ?></span>
            </div>

            <form method="post" id="newTicketForm">
                <?= csrf_input() ?>
                <input type="hidden" name="category_id" value="<?= (int)($leaf['id'] ?? $cat_id) ?>">
                <input type="hidden" name="subcategory_id" value="<?= (int)($leaf['parent_id'] ?? 0) ?>">

                <!-- Phase 5: Smart Context Capture (hidden, filled by JS) -->
                <input type="hidden" name="ctx_url" id="ctx_url" value="">
                <input type="hidden" name="ctx_referrer" id="ctx_referrer" value="">
                <input type="hidden" name="ctx_page_title" id="ctx_page_title" value="">
                <input type="hidden" name="ctx_ua" id="ctx_ua" value="">
                <input type="hidden" name="ctx_perms" id="ctx_perms" value="">
                <input type="hidden" name="ctx_screen" id="ctx_screen" value="">
                <input type="hidden" name="ctx_lang" id="ctx_lang" value="">

                <div class="form-section-h">1. التفاصيل</div>
                <?php
                $is_other = ($leaf['slug'] ?? '') === 'other';
                $auto_title = $is_other ? '' : e($leaf['name_ar']);
                $prefilled_title = $_POST['title'] ?? $auto_title;
                ?>
                <?php if ($is_other): ?>
                    <div class="form-row">
                        <label>العنوان <span class="req">*</span></label>
                        <input type="text" name="title" required maxlength="200" value="<?= e($prefilled_title) ?>" placeholder="ملخص قصير لطلبك (5-10 كلمات)">
                        <div class="help">سيظهر في قائمة التذاكر وعنوان الإشعار</div>
                    </div>
                <?php else: ?>
                    <div class="form-row title-locked">
                        <label>العنوان <span class="lock-ic" title="ينشأ تلقائياً من التصنيف"><i class="fa-solid fa-lock"></i></span></label>
                        <div class="title-display">
                            <i class="fa-solid <?= e($leaf['icon']) ?>" style="color:<?= e($leaf['color']) ?>"></i>
                            <span class="title-text"><?= e($leaf['name_ar']) ?></span>
                            <input type="hidden" name="title" value="<?= e($leaf['name_ar']) ?>">
                        </div>
                        <div class="help"><i class="fa-solid fa-info-circle"></i> العنوان ينشأ تلقائياً من التصنيف (لتوحيد العناوين). المعالج يرى التصنيف + التفاصيل في الوصف.</div>
                    </div>
                <?php endif; ?>
                <div class="form-row">
                    <label>الوصف <span class="req">*</span></label>
                    <textarea name="description" required maxlength="5000" placeholder="اشرح بالتفصيل... (الخطوات، النتيجة المتوقعة، النتيجة الفعلية، أي خطأ)"><?= e($_POST['description'] ?? '') ?></textarea>
                    <div class="help">سيظهر كأول رسالة في محادثة التذكرة. المعالج يعتمد على هذا الوصف لفهم طلبك.</div>
                </div>

                <?php if ($leaf_fields): ?>
                    <div class="form-section-h">2. حقول خاصة (<?= e($leaf['name_ar']) ?>)</div>
                    <?php
                    $pre_filled = $_POST['fields'] ?? [];
                    foreach ($leaf_fields as $f) {
                        echo helpdesk_render_form_field($f, $pre_filled);
                    }
                    ?>
                <?php endif; ?>

                <div class="form-section-h">3. الأولوية</div>
                <div class="form-row">
                    <div class="prio-pills">
                        <?php $prio = $_POST['priority'] ?? 'medium'; ?>
                        <label class="prio-pill low"><input type="radio" name="priority" value="low" <?= $prio==='low'?'checked':'' ?>><span class="dot"></span>منخفضة</label>
                        <label class="prio-pill medium"><input type="radio" name="priority" value="medium" <?= $prio==='medium'?'checked':'' ?>><span class="dot"></span>متوسطة</label>
                        <label class="prio-pill high"><input type="radio" name="priority" value="high" <?= $prio==='high'?'checked':'' ?>><span class="dot"></span>عالية</label>
                        <label class="prio-pill critical"><input type="radio" name="priority" value="critical" <?= $prio==='critical'?'checked':'' ?>><span class="dot"></span>حرجة</label>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn pri">
                        <i class="fa-solid fa-paper-plane"></i> إرسال التذكرة
                    </button>
                    <a href="?cat=<?= (int)$leaf['parent_id'] ?>&sub=<?= (int)$leaf['parent_id'] ?>" class="btn sec">
                        <i class="fa-solid fa-arrow-right"></i> رجوع
                    </a>
                </div>
            </form>
        <?php endif; ?>

    </div>

</div>
</main>
</div>

<!-- Phase 5: Smart Context Capture (JS) -->
<script>
(function() {
    // ملء الحقول المخفية بالسياق الفعلي
    const f = document.getElementById('newTicketForm');
    if (!f) return;

    const ctxUrl = document.getElementById('ctx_url');
    const ctxRef = document.getElementById('ctx_referrer');
    const ctxTitle = document.getElementById('ctx_page_title');
    const ctxUA = document.getElementById('ctx_ua');
    const ctxPerms = document.getElementById('ctx_perms');
    const ctxScreen = document.getElementById('ctx_screen');
    const ctxLang = document.getElementById('ctx_lang');

    if (ctxUrl) ctxUrl.value = window.location.href;
    if (ctxRef) ctxRef.value = document.referrer || '';
    if (ctxTitle) ctxTitle.value = document.title || '';
    if (ctxUA) ctxUA.value = navigator.userAgent || '';
    if (ctxScreen) ctxScreen.value = (screen.width || 0) + 'x' + (screen.height || 0) + '@' + (window.devicePixelRatio || 1);
    if (ctxLang) ctxLang.value = navigator.language || navigator.userLanguage || '';

    // جلب الصلاحيات من السيرفر (لحظة الإرسال لتكون طازجة)
    if (ctxPerms) {
        fetch('<?= BASE_URL ?>/api/helpdesk_sidebar.php?action=my_perms', {
            credentials: 'same-origin',
            cache: 'no-store'
        })
        .then(r => r.json())
        .then(data => { ctxPerms.value = JSON.stringify(data || {}); })
        .catch(() => { ctxPerms.value = '{}'; });
    }
})();
</script>
</body>
</html>
