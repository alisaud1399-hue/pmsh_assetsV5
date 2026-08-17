<?php
/**
 * settings/index.php — إعدادات النظام (Admin فقط)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
page_guard('settings.index');
if (!is_admin()) {
    flash('danger', is_rtl()?'المديرون فقط':'Admins only');
    header('Location: ' . BASE_URL . '/dashboard.php'); exit;
}

$rtl = is_rtl();

// ── جداول القوائم القابلة للإدارة ──────────────────────────
$lookup_tables = [
    'supply_types'        => ['ar'=>'أنواع التوريد',         'en'=>'Supply Types',         'icon'=>'fa-truck'],
    'committee_types'     => ['ar'=>'أنواع اللجان',          'en'=>'Committee Types',       'icon'=>'fa-users-gear'],
    'receiving_doc_types' => ['ar'=>'مصادر توريد المحاضر',   'en'=>'Receiving Doc Types',   'icon'=>'fa-file-contract'],
];


// ── جلب الأدوار وصلاحيات سير العمل ──────────────────────────
$all_roles = $pdo->query("SELECT id,name,display_name FROM roles WHERE is_active=1 ORDER BY sort_order")->fetchAll();

// تعريف ميزات سير العمل — نجلب ppid مباشرة من DB
$workflow_features = [];
$wf_raw = [
    'committees' => [
        'label' => $rtl?'وحدة اللجان':'Committees Module',
        'icon'  => 'fa-users-gear',
        'items' => [
            ['page'=>'committees.index','action'=>'create',          'ar'=>'إنشاء لجنة جديدة',            'en'=>'Create committee'],
            ['page'=>'committees.index','action'=>'direct_activate', 'ar'=>'تفعيل مباشر بدون اعتماد ⚡', 'en'=>'Direct activation'],
            ['page'=>'committees.index','action'=>'edit',            'ar'=>'تعديل اللجنة',                'en'=>'Edit committee'],
            ['page'=>'committees.approve','action'=>'view',          'ar'=>'اعتماد/رفض اللجان (تنفيذي)', 'en'=>'Approve committees'],
        ],
    ],
    'receiving' => [
        'label' => $rtl?'وحدة محاضر الاستلام':'Receiving Module',
        'icon'  => 'fa-truck-ramp-box',
        'items' => [
            ['page'=>'receiving.index','action'=>'create', 'ar'=>'إنشاء محضر استلام', 'en'=>'Create minute'],
            ['page'=>'receiving.index','action'=>'edit',   'ar'=>'تعديل المحضر',     'en'=>'Edit minute'],
            ['page'=>'receiving.index','action'=>'approve','ar'=>'التوقيع والاعتماد', 'en'=>'Sign & approve'],
        ],
    ],
];

// جلب page_permission_id من DB لكل ميزة (بالاسم الفعلي من الجدول)
foreach ($wf_raw as $mod => $mdata) {
    $features_resolved = [];
    foreach ($mdata['items'] as $feat) {
        // بحث بالكود أولاً
        $s=$pdo->prepare("SELECT pp.id FROM page_permissions pp JOIN pages p ON p.id=pp.page_id WHERE p.code=? AND pp.action=? LIMIT 1");
        $s->execute([$feat['page'],$feat['action']]); $ppid=(int)$s->fetchColumn();
        // إذا لم يُوجد — ابحث بالـ action فقط عبر صفحات الـ module المشابه
        if(!$ppid){
            $mod_key=explode('.',$feat['page'])[0]; // committees or receiving
            $s2=$pdo->prepare("SELECT pp.id FROM page_permissions pp JOIN pages p ON p.id=pp.page_id WHERE p.code LIKE ? AND pp.action=? LIMIT 1");
            $s2->execute([$mod_key.'%',$feat['action']]); $ppid=(int)$s2->fetchColumn();
        }
        // إذا لا يزال غير موجود — أنشئه
        if(!$ppid){
            $pg=$pdo->prepare("SELECT id FROM pages WHERE code=? OR code LIKE ? LIMIT 1");
            $pg->execute([$feat['page'],explode('.',$feat['page'])[0].'%']); $pg_id=(int)$pg->fetchColumn();
            if($pg_id){
                $pdo->prepare("INSERT IGNORE INTO page_permissions (page_id,action,display_name,display_en,is_active) VALUES(?,?,?,?,1)")
                    ->execute([$pg_id,$feat['action'],$feat['ar'],$feat['en']]);
                $s3=$pdo->prepare("SELECT id FROM page_permissions WHERE page_id=? AND action=? LIMIT 1");
                $s3->execute([$pg_id,$feat['action']]); $ppid=(int)$s3->fetchColumn();
            }
        }
        $features_resolved[]=$feat+['ppid'=>$ppid];
    }
    $workflow_features[$mod]=['label'=>$mdata['label'],'icon'=>$mdata['icon'],'features'=>$features_resolved];
}

// جلب الصلاحيات الحالية لكل دور
$current_perms_by_ppid = [];
$sp=$pdo->query("SELECT role_id, page_permission_id FROM role_permissions")->fetchAll();
foreach($sp as $row) $current_perms_by_ppid[$row['page_permission_id']][$row['role_id']]=true;
// legacy lookup by code+action
$current_perms = [];
$sp2=$pdo->query("SELECT r.name AS role_name, p.code AS page_code, pp.action FROM role_permissions rp INNER JOIN roles r ON r.id=rp.role_id INNER JOIN page_permissions pp ON pp.id=rp.page_permission_id INNER JOIN pages p ON p.id=pp.page_id")->fetchAll();
foreach($sp2 as $row) $current_perms[$row['role_name']][$row['page_code']][$row['action']]=true;

// ── POST: CRUD على القوائم ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';
    $table  = $_POST['tbl']    ?? '';

    // ── توجيه إجراءات التصنيفات/المواقع لملف القسم المستقل ──
    if (in_array($action, ['cat_save','cat_delete','loc_save','loc_delete'], true)) {
        define('PMSH_SETTINGS_SECTION', true);
        require_once dirname(__FILE__) . '/_sections/lookup_admin.php';
        exit;
    }

    // منح/سحب صلاحية خاصة لمستخدم محدد
    if ($action === 'grant_user_perm') {
        $target_uid = (int)($_POST['target_user_id'] ?? 0);
        $page_code  = $_POST['page_code']   ?? '';
        $perm_act   = $_POST['perm_action'] ?? '';
        $grant      = (int)($_POST['grant'] ?? 1);
        $reason     = trim($_POST['reason'] ?? '');
        if ($target_uid && $page_code && $perm_act) {
            $ppid=$pdo->prepare("SELECT pp.id FROM page_permissions pp INNER JOIN pages p ON p.id=pp.page_id WHERE p.code=? AND pp.action=? LIMIT 1");
            $ppid->execute([$page_code,$perm_act]); $ppid=(int)$ppid->fetchColumn();
            if ($ppid) {
                if ($grant) {
                    $pdo->prepare("INSERT INTO user_permission_overrides (user_id,page_permission_id,granted,reason,granted_by) VALUES(?,?,1,?,?) ON DUPLICATE KEY UPDATE granted=1,reason=?,granted_by=?")
                        ->execute([$target_uid,$ppid,$reason?:null,user_id(),$reason?:null,user_id()]);
                    flash('success',$rtl?'تم منح الصلاحية للمستخدم ✅':'Permission granted to user ✅');
                } else {
                    $pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id=? AND page_permission_id=?")->execute([$target_uid,$ppid]);
                    flash('success',$rtl?'تم سحب الصلاحية من المستخدم':'Permission revoked from user');
                }
            }
        }
        header('Location:'.$_SERVER['REQUEST_URI'].'#workflow'); exit;
    }

    // معالجة حفظ الصلاحيات
    if ($action === 'save_workflow') {
        $ppid        = (int)($_POST['ppid']        ?? 0);
        $roles_on    = $_POST['roles_on']           ?? [];

        if ($ppid) {
            // دائماً أبقِ Admin مُضمَّناً
            $admin_id=(int)$pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn();
            if($admin_id && !in_array((string)$admin_id,array_map('strval',$roles_on)))
                $roles_on[]=$admin_id;

            $pdo->prepare("DELETE FROM role_permissions WHERE page_permission_id=?")->execute([$ppid]);
            $si=$pdo->prepare("INSERT IGNORE INTO role_permissions (role_id,page_permission_id) VALUES(?,?)");
            foreach($roles_on as $rid) if((int)$rid) $si->execute([(int)$rid,$ppid]);
            flash('success',$rtl?'✅ تم حفظ الصلاحيات بنجاح':'✅ Permissions saved');
        } else {
            flash('danger',$rtl?'لم يتم العثور على الصلاحية — تأكد من تشغيل migrations':'Permission not found in DB');
        }
        header('Location:'.$_SERVER['REQUEST_URI'].'#workflow'); exit;
    }

    // ── اللجان الثابتة (قبل فحص الجداول) ──────────────────────
    if(in_array($action,['sc_create','sc_add_member','sc_del_member'])){
        if($action==='sc_create'){
            $sct=$_POST['sc_type']??'';
            $scn=trim($_POST['sc_name']??'');
            $scd=$_POST['sc_start_date']??date('Y-m-d');
            if($sct&&$scn){
                $pdo->prepare("UPDATE standing_committees SET end_date=DATE_SUB(?,INTERVAL 1 DAY) WHERE maintenance_type=? AND end_date IS NULL")->execute([$scd,$sct]);
                $pdo->prepare("INSERT INTO standing_committees (maintenance_type,name,start_date,created_by) VALUES(?,?,?,?)")->execute([$sct,$scn,$scd,$uid]);
                $scid=(int)$pdo->lastInsertId();
                $roles_in=$_POST['sc_roles']??[];
                $users_in=$_POST['sc_users']??[];
                $si=$pdo->prepare("INSERT IGNORE INTO standing_committee_members (committee_id,user_id,role,sort_order) VALUES(?,?,?,?)");
                foreach($users_in as $i=>$mu) if((int)$mu) $si->execute([$scid,(int)$mu,$roles_in[$i]??'عضو',$i]);
                flash('success',$rtl?'✅ تم إنشاء اللجنة الثابتة':'✅ Standing committee created');
            }
        } elseif($action==='sc_add_member'){
            $scid=(int)($_POST['sc_id']??0);
            $muid=(int)($_POST['m_user']??0);
            $mrole=$_POST['m_role']??'عضو';
            if($scid&&$muid){
                $cnt=(int)$pdo->prepare("SELECT COUNT(*) FROM standing_committee_members WHERE committee_id=?")->execute([$scid]);
                $pdo->prepare("INSERT IGNORE INTO standing_committee_members (committee_id,user_id,role,sort_order) VALUES(?,?,?,99)")->execute([$scid,$muid,$mrole]);
                flash('success',$rtl?'✅ تمت إضافة العضو':'✅ Member added');
            }
        } elseif($action==='sc_del_member'){
            $memid=(int)($_POST['mem_id']??0);
            if($memid) $pdo->prepare("DELETE FROM standing_committee_members WHERE id=?")->execute([$memid]);
            flash('success',$rtl?'تم حذف العضو':'Member removed');
        }
        header('Location:'.$_SERVER['REQUEST_URI'].'#standing'); exit;
    }

    // ── حفظ الإعدادات العامة (شامل رفع الشعار) — قبل فحص الجداول ──
    if ($action === 'save_settings') {
        $settings_map = [
            'hospital_name'      => trim($_POST['hospital_name']       ?? ''),
            'hospital_name_en'   => trim($_POST['hospital_name_en']    ?? ''),
            'hospital_phone'     => trim($_POST['hospital_phone']       ?? ''),
            'hospital_email'     => trim($_POST['hospital_email']       ?? ''),
            'items_per_page'     => (string)(int)($_POST['items_per_page'] ?? 50),
            'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
        ];
        foreach ($settings_map as $key => $val) {
            $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$key, $val, $val]);
        }
        // ── رفع الشعار (logo upload) ──
        if (!empty($_FILES['hospital_logo']['name']) && $_FILES['hospital_logo']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['hospital_logo'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
            if (in_array($ext, $allowed) && $f['size'] <= 2 * 1024 * 1024) {
                $upload_dir = BASE_PATH . '/uploads/branding/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $new_name = 'hospital_logo_' . time() . '.' . $ext;
                $target = $upload_dir . $new_name;
                if (move_uploaded_file($f['tmp_name'], $target)) {
                    $logo_url = '/uploads/branding/' . $new_name;
                    $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES ('hospital_logo',?) ON DUPLICATE KEY UPDATE setting_value=?")
                        ->execute([$logo_url, $logo_url]);
                }
            }
        }
        // ── حذف الشعار إذا طلب المستخدم ──
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            $old = $sys['hospital_logo'] ?? '';
            if ($old && str_starts_with($old, '/uploads/branding/')) {
                @unlink(BASE_PATH . $old);
            }
            $pdo->prepare("DELETE FROM system_settings WHERE setting_key='hospital_logo'")->execute();
        }
        flash('success', $rtl?'تم حفظ الإعدادات':'Settings saved');
        header('Location: ' . $_SERVER['REQUEST_URI'] . '#general'); exit;
    }

    // التحقق من أن الجدول مسموح به

    if (!array_key_exists($table, $lookup_tables)) {
        flash('danger', $rtl?'جدول غير مسموح':'Invalid table');
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    if ($action === 'add') {
        $name    = trim($_POST['name']    ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        if ($name) {
            $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM `$table`")->fetchColumn();
            $pdo->prepare("INSERT INTO `$table` (name, name_en, sort_order, is_active) VALUES (?,?,?,1)")
                ->execute([$name, $name_en ?: null, $max + 1]);
            flash('success', $rtl?'تمت الإضافة':'Added successfully');
        }
    } elseif ($action === 'edit') {
        $id      = (int)$_POST['id'];
        $name    = trim($_POST['name']    ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $sort    = (int)$_POST['sort_order'];
        $active  = isset($_POST['is_active']) ? 1 : 0;
        if ($name && $id) {
            if ($table === 'committee_types') {
                $req_approval = isset($_POST['requires_approval']) ? 1 : 0;
                $pdo->prepare("UPDATE `$table` SET name=?,name_en=?,sort_order=?,is_active=?,requires_approval=? WHERE id=?")
                    ->execute([$name, $name_en ?: null, $sort, $active, $req_approval, $id]);
            } else {
                $pdo->prepare("UPDATE `$table` SET name=?,name_en=?,sort_order=?,is_active=? WHERE id=?")
                    ->execute([$name, $name_en ?: null, $sort, $active, $id]);
            }
            flash('success', $rtl?'تم التعديل':'Updated');
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id) {
            $pdo->prepare("DELETE FROM `$table` WHERE id=?")->execute([$id]);
            flash('success', $rtl?'تم الحذف':'Deleted');
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI'] . '#' . $table); exit;
}


// ── اللجان الثابتة ────────────────────────────────────────────
$sc_types = ['medical'=>'صيانة طبية','general'=>'صيانة عامة','it'=>'تقنية المعلومات'];
$sc_data  = [];
foreach(array_keys($sc_types) as $sct){
    $q=$pdo->prepare("SELECT sc.id,sc.name,sc.start_date,sc.end_date FROM standing_committees sc WHERE sc.maintenance_type=? ORDER BY sc.id DESC LIMIT 1");
    $q->execute([$sct]); $sc=$q->fetch();
    $mems=[];
    if($sc){
        $mq=$pdo->prepare("SELECT scm.id,scm.role,scm.sort_order,u.id AS uid,u.full_name AS uname FROM standing_committee_members scm INNER JOIN users u ON u.id=scm.user_id WHERE scm.committee_id=? ORDER BY scm.sort_order,scm.role");
        $mq->execute([$sc['id']]); $mems=$mq->fetchAll();
    }
    $sc_data[$sct]=['committee'=>$sc,'members'=>$mems];
}
// قائمة المستخدمين لإضافة أعضاء
$all_users_list=$pdo->query("SELECT id,full_name AS name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

// ── جلب البيانات ────────────────────────────────────────────
$data = [];
foreach (array_keys($lookup_tables) as $tbl) {
    $data[$tbl] = $pdo->query("SELECT * FROM `$tbl` ORDER BY sort_order, id")->fetchAll();
}

// إعدادات النظام
$sys = [];
foreach ($pdo->query("SELECT setting_key AS key_name, setting_value AS value FROM system_settings")->fetchAll() as $row) {
    $sys[$row['key_name']] = $row['value'];
}

$page_title = $rtl ? 'إعدادات النظام' : 'System Settings';
$page_icon  = 'fa-gear';
$active_nav = 'settings.index';
$breadcrumb = [];
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.s-layout{display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start}
@media(max-width:900px){.s-layout{grid-template-columns:1fr}}
/* الشريط الجانبي */
.s-nav{background:#fff;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;position:sticky;top:16px}
.s-nav-head{padding:14px 16px;font-size:12.5px;font-weight:700;color:#64748b;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:6px}
.s-nav-item{display:flex;align-items:center;gap:9px;padding:11px 16px;font-size:13px;color:#475569;cursor:pointer;transition:.15s;border-bottom:1px solid #f8fafc;text-decoration:none;font-weight:500}
.s-nav-item:hover,.s-nav-item.on{background:#eff6ff;color:#1565C0}
.s-nav-item i{font-size:13px;width:16px;text-align:center;color:#94a3b8}
.s-nav-item.on i{color:#1565C0}
/* البطاقات */
.s-card{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;margin-bottom:14px}
.s-card:last-child{margin-bottom:0}
.s-card-head{padding:14px 18px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;gap:9px;justify-content:space-between}
.s-card-title{font-size:13.5px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px}
.s-card-title i{color:#1565C0;font-size:13px}
/* جدول القوائم */
.lt-table{width:100%;border-collapse:collapse}
.lt-table th{font-size:11px;font-weight:700;color:#64748b;padding:9px 14px;text-align:inherit;background:#f8fafc;border-bottom:1px solid #f1f5f9}
.lt-table td{padding:9px 14px;font-size:13px;border-bottom:1px solid #f8fafc;vertical-align:middle}
.lt-table tr:last-child td{border-bottom:none}
.lt-table tr:hover td{background:#fafafa}
/* نموذج الإضافة السريع */
.add-row{display:flex;gap:8px;padding:12px 14px;border-top:1px solid #f1f5f9;background:#f8fafc;align-items:center;flex-wrap:wrap}
.add-row input{flex:1;min-width:140px;height:36px;padding-inline:11px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:'Tajawal',sans-serif;font-size:13px;outline:none;transition:border-color .2s}
.add-row input:focus{border-color:#1565C0}
/* Modal التعديل */
.lt-modal{display:none;position:fixed;inset:0;z-index:900;background:rgba(15,23,42,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:16px}
.lt-modal.open{display:flex}
.lt-modal-box{background:#fff;border-radius:16px;width:440px;max-width:96vw;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.18)}
.lm-title{font-size:15px;font-weight:700;margin-bottom:16px;color:#0f172a;display:flex;align-items:center;gap:7px}
.lm-grid{display:flex;flex-direction:column;gap:11px}
.lmg{display:flex;flex-direction:column;gap:4px}
.lmg label{font-size:12px;font-weight:700;color:#475569}
.lmi{height:38px;padding-inline:11px;border:1.5px solid #e2e8f0;border-radius:9px;font-family:'Tajawal',sans-serif;font-size:13.5px;outline:none;transition:border-color .2s;width:100%;box-sizing:border-box}
.lmi:focus{border-color:#1565C0}
/* إعدادات عامة */
.sg-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:18px}
@media(max-width:600px){.sg-grid{grid-template-columns:1fr}}
.sg-grid .full{grid-column:1/-1}
.sgg{display:flex;flex-direction:column;gap:4px}
.sgg label{font-size:12px;font-weight:700;color:#475569}
.sgi{height:38px;padding-inline:11px;border:1.5px solid #e2e8f0;border-radius:9px;font-family:'Tajawal',sans-serif;font-size:13.5px;outline:none;transition:border-color .2s;width:100%;box-sizing:border-box}
.sgi:focus{border-color:#1565C0}
.badge-cnt{font-size:11px;font-weight:700;background:#e0f2fe;color:#0369a1;border-radius:50px;padding:1px 8px;margin-inline-start:auto}
/* badges */
.ab{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;border:1.5px solid;cursor:pointer;font-size:11px;transition:all .15s;background:#fff}
.ab-edit{color:#d97706;border-color:#fde68a}.ab-edit:hover{background:#d97706;color:#fff;border-color:#d97706}
.ab-del{color:#dc2626;border-color:#fecaca}.ab-del:hover{background:#dc2626;color:#fff;border-color:#dc2626}
.status-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php foreach($flash_msgs as $fm): ?>
<div class="alert alert-<?= e($fm['type']) ?>" style="margin-bottom:12px">
  <i class="fa-solid fa-circle-<?= $fm['type']==='success'?'check':'exclamation' ?>"></i>
  <span><?= e($fm['message']) ?></span>
</div>
<?php endforeach; ?>

<div class="s-layout">

  <!-- ── الشريط الجانبي ── -->
  <div class="s-nav">
    <div class="s-nav-head"><i class="fa-solid fa-gear"></i><?= $rtl?'أقسام الإعدادات':'Settings Sections' ?></div>
    <a href="#general" class="s-nav-item on">
      <i class="fa-solid fa-hospital"></i><?= $rtl?'إعدادات المستشفى':'Hospital Settings' ?>
    </a>
    <a href="#workflow" class="s-nav-item">
      <i class="fa-solid fa-gears"></i><?= $rtl?'إعدادات سير العمل':'Workflow Settings' ?>
    </a>
    <a href="#standing" class="s-nav-item">
      <i class="fa-solid fa-users-gear"></i><?= $rtl?'اللجان الثابتة':'Standing Committees' ?>
    </a>
    <a href="#categories" class="s-nav-item">
      <i class="fa-solid fa-folder-tree"></i><?= $rtl?'التصنيفات':'Categories' ?>
      <?php
        $c_missing = (int)$pdo->query("SELECT COUNT(*) FROM item_categories WHERE name_en IS NULL OR name_en=''")->fetchColumn();
        if ($c_missing > 0): ?>
      <span class="badge-cnt" style="background:#fef3c7;color:#92400e"><?= $c_missing ?></span>
      <?php endif; ?>
    </a>
    <a href="#locations" class="s-nav-item">
      <i class="fa-solid fa-location-dot"></i><?= $rtl?'المواقع':'Locations' ?>
      <?php
        $l_missing = (int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE name_en IS NULL OR name_en=''")->fetchColumn();
        if ($l_missing > 0): ?>
      <span class="badge-cnt" style="background:#fef3c7;color:#92400e"><?= $l_missing ?></span>
      <?php endif; ?>
    </a>
    <?php foreach($lookup_tables as $tbl=>$info): ?>
    <a href="#<?= $tbl ?>" class="s-nav-item">
      <i class="fa-solid <?= $info['icon'] ?>"></i>
      <?= $rtl?e($info['ar']):e($info['en']) ?>
      <span class="badge-cnt"><?= count($data[$tbl]) ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- ── المحتوى ── -->
  <div>

    <!-- ══ إعدادات المستشفى ══ -->
    <div class="s-card" id="general">
      <div class="s-card-head">
        <div class="s-card-title"><i class="fa-solid fa-hospital"></i><?= $rtl?'إعدادات المستشفى العامة':'Hospital General Settings' ?></div>
      </div>
      <form method="POST" action="" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="save_settings">
        <input type="hidden" name="tbl"    value="system_settings">
        <div class="sg-grid">
          <div class="sgg">
            <label><i class="fa-solid fa-hospital" style="font-size:10px;color:#94a3b8"></i> <?= $rtl?'اسم المستشفى (عربي)':'Hospital Name (AR)' ?></label>
            <input type="text" name="hospital_name" class="sgi" value="<?= e($sys['hospital_name']??'') ?>">
          </div>
          <div class="sgg">
            <label><i class="fa-solid fa-hospital" style="font-size:10px;color:#94a3b8"></i> <?= $rtl?'Hospital Name (EN)':'Hospital Name (EN)' ?></label>
            <input type="text" name="hospital_name_en" class="sgi" value="<?= e($sys['hospital_name_en']??'') ?>">
          </div>
          <div class="sgg">
            <label><i class="fa-solid fa-phone" style="font-size:10px;color:#94a3b8"></i> <?= $rtl?'رقم الهاتف':'Phone' ?></label>
            <input type="tel" name="hospital_phone" class="sgi" value="<?= e($sys['hospital_phone']??'') ?>">
          </div>
          <div class="sgg">
            <label><i class="fa-solid fa-envelope" style="font-size:10px;color:#94a3b8"></i> <?= $rtl?'البريد الإلكتروني':'Email' ?></label>
            <input type="email" name="hospital_email" class="sgi" value="<?= e($sys['hospital_email']??'') ?>">
          </div>
          <div class="sgg">
            <label><i class="fa-solid fa-list-ol" style="font-size:10px;color:#94a3b8"></i> <?= $rtl?'عناصر الصفحة (pagination)':'Items per page' ?></label>
            <input type="number" name="items_per_page" class="sgi" min="10" max="200" value="<?= e($sys['items_per_page']??50) ?>">
          </div>
          <div class="sgg" style="justify-content:flex-end;flex-direction:row;align-items:center;gap:10px;padding-top:8px">
            <input type="checkbox" id="allow_reg" name="allow_registration" <?= ($sys['allow_registration']??'1')==='1'?'checked':'' ?>>
            <label for="allow_reg" style="font-size:13px;font-weight:600;cursor:pointer;color:#334155"><?= $rtl?'السماح بالتسجيل الذاتي':'Allow self-registration' ?></label>
          </div>
        </div>

        <!-- ── شعار المستشفى (يظهر في كل التقارير والطباعة) ── -->
        <div style="padding:14px 18px;border-top:1.5px solid #f1f5f9;background:linear-gradient(135deg,#f8fafc,#eef2ff)">
          <div style="font-size:12.5px;font-weight:800;color:#1e3a8a;margin-bottom:8px;display:flex;align-items:center;gap:6px">
            <i class="fa-solid fa-image" style="color:#1565C0"></i>
            <?= $rtl?'شعار المستشفى (يظهر في التقارير والطباعة)':'Hospital Logo (shown in reports & print)' ?>
          </div>
          <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
            <?php if (!empty($sys['hospital_logo'])): ?>
              <div style="background:#fff;padding:6px;border:1.5px solid #e2e8f0;border-radius:10px;display:flex;align-items:center;gap:8px">
                <img src="<?= BASE_URL . e($sys['hospital_logo']) ?>" alt="logo" style="height:48px;width:auto;max-width:120px;object-fit:contain">
              </div>
              <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:#dc2626;cursor:pointer;font-weight:700">
                <input type="checkbox" name="remove_logo" value="1">
                <i class="fa-solid fa-trash-can"></i>
                <?= $rtl?'حذف الشعار الحالي':'Remove current logo' ?>
              </label>
            <?php else: ?>
              <div style="width:80px;height:48px;background:#fff;border:1.5px dashed #cbd5e1;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:18px">
                <i class="fa-solid fa-image"></i>
              </div>
            <?php endif; ?>
            <input type="file" name="hospital_logo" accept="image/png,image/jpeg,image/svg+xml,image/webp" style="font-size:12px;flex:1;min-width:200px">
            <span style="font-size:10.5px;color:#94a3b8;font-weight:700">PNG/JPG/SVG · حد 2MB</span>
          </div>
        </div>
        <div style="padding:0 18px 16px;display:flex;justify-content:flex-end">
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk"></i><?= $rtl?'حفظ الإعدادات':'Save Settings' ?>
          </button>
        </div>
      </form>
    </div>


    <!-- ══ إعدادات سير العمل ══ -->
    <div class="s-card" id="workflow">
      <div class="s-card-head">
        <div class="s-card-title"><i class="fa-solid fa-gears"></i><?= $rtl?'إعدادات سير العمل':'Workflow Settings' ?></div>
      </div>
      <div style="padding:16px 18px">
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:11px 14px;margin-bottom:16px;font-size:12.5px;color:#92400e;line-height:1.7">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <?= $rtl?'هذه الإعدادات تتحكم في سلوك الإجراءات لكل الأدوار. التغييرات تسري فوراً.':'These settings control workflow behavior per role. Changes take effect immediately.' ?>
        </div>

        <?php foreach($workflow_features as $mod=>$mdata): ?>
        <div style="margin-bottom:20px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
          <div style="padding:11px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:13px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:7px">
            <i class="fa-solid <?= $mdata['icon'] ?>" style="color:#1565C0"></i>
            <?= $mdata['label'] ?>
          </div>
          <?php foreach($mdata['features'] as $feat):
            $ppid_feat = $feat['ppid'] ?? 0;
            $flabel = $rtl ? $feat['ar'] : $feat['en'];
          ?>
          <div style="padding:12px 16px;border-bottom:1px solid #f8fafc;display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
            <div style="min-width:200px;flex:1">
              <div style="font-size:13px;font-weight:600;color:#0f172a"><?= e($flabel) ?></div>
              <div style="font-size:11px;color:#94a3b8;margin-top:2px">
                <?php if(!$ppid_feat): ?>
                <span style="color:#dc2626"><i class="fa-solid fa-triangle-exclamation"></i> غير موجودة في DB — شغّل migration 009</span>
                <?php else: ?>
                <span style="color:#16a34a"><i class="fa-solid fa-circle-check"></i> ppid: <?= $ppid_feat ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php if($ppid_feat): ?>
            <form method="POST" style="flex:2">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="save_workflow">
              <input type="hidden" name="ppid" value="<?= $ppid_feat ?>">
              <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
                <?php foreach($all_roles as $role):
                  $has = !empty($current_perms_by_ppid[$ppid_feat][$role['id']]);
                  $isDirect = $feat['action']==='direct_activate';
                  $isAdmin  = $role['name']==='admin';
                  $bgc = $has ? ($isDirect?'#fef9c3':'#dbeafe') : '#f8fafc';
                  $bdc = $has ? ($isDirect?'#fde68a':'#bfdbfe') : '#e2e8f0';
                  $txc = $has ? ($isDirect?'#92400e':'#1565C0') : '#94a3b8';
                ?>
                <label style="display:flex;align-items:center;gap:5px;cursor:<?= $isAdmin?'not-allowed':'pointer' ?>;background:<?= $bgc ?>;border-radius:50px;padding:4px 10px;border:1px solid <?= $bdc ?>;transition:.15s">
                  <input type="checkbox" name="roles_on[]" value="<?= $role['id'] ?>"
                    <?= $has?'checked':'' ?> <?= $isAdmin?'checked disabled':'' ?>
                    style="width:15px;height:15px;accent-color:<?= $isDirect?'#d97706':'#1565C0' ?>">
                  <span style="font-size:12px;font-weight:600;color:<?= $txc ?>">
                    <?= e($role['display_name']??$role['name']) ?>
                  </span>
                </label>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-inline-start:auto">
                  <i class="fa-solid fa-floppy-disk"></i><?= $rtl?'حفظ':'Save' ?>
                </button>
              </div>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <!-- ── صلاحيات خاصة لمستخدمين بعينهم ── -->
        <div style="margin-top:6px;border:2px solid #e0e7ff;border-radius:12px;overflow:hidden">
          <div style="padding:11px 16px;background:#eef2ff;border-bottom:1px solid #e0e7ff;font-size:13px;font-weight:700;color:#3730a3;display:flex;align-items:center;gap:7px">
            <i class="fa-solid fa-user-shield" style="color:#4f46e5"></i>
            <?= $rtl?'صلاحيات خاصة لمستخدمين بعينهم':'User-Specific Permission Grants' ?>
            <span style="font-size:11px;font-weight:400;color:#6366f1;margin-inline-start:4px"><?= $rtl?'(تتجاوز صلاحيات الدور)':'(overrides role permissions)' ?></span>
          </div>
          <div style="padding:14px 16px">
            <!-- منح صلاحية -->
            <form method="POST" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:14px;margin-bottom:14px">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="grant_user_perm">
              <input type="hidden" name="grant" value="1">
              <div style="font-size:12.5px;font-weight:700;color:#3730a3;margin-bottom:10px">
                <i class="fa-solid fa-plus-circle"></i> <?= $rtl?'منح صلاحية خاصة لمستخدم':'Grant Special Permission to User' ?>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:8px;align-items:end">
                <div>
                  <label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:3px"><?= $rtl?'المستخدم':'User' ?></label>
                  <select name="target_user_id" class="rfi" required>
                    <option value=""><?= $rtl?'— اختر مستخدم —':'— Select User —' ?></option>
                    <?php
                    $all_users=$pdo->query("SELECT u.id,u.full_name,r.display_name AS role_name FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id AND ur.is_primary=1 LEFT JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 ORDER BY u.full_name")->fetchAll();
                    foreach($all_users as $u):
                    ?>
                    <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?> — <?= e($u['role_name']??'—') ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:3px"><?= $rtl?'الصلاحية':'Permission' ?></label>
                  <select name="page_code" id="grantPageCode" class="rfi" required onchange="document.getElementById('grantAction').value=this.options[this.selectedIndex].dataset.action">
                    <option value=""><?= $rtl?'— اختر —':'— Select —' ?></option>
                    <?php foreach($workflow_features as $mod=>$mdata): ?>
                    <?php foreach($mdata['features'] as $feat): ?>
                    <option value="<?= e($feat['page']) ?>" data-action="<?= e($feat['action']) ?>">
                      <?= e($rtl?$feat['ar']:$feat['en']) ?>
                    </option>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="perm_action" id="grantAction">
                </div>
                <div>
                  <label style="font-size:11px;font-weight:700;color:#475569;display:block;margin-bottom:3px"><?= $rtl?'سبب المنح':'Reason' ?></label>
                  <input type="text" name="reason" class="rfi" placeholder="<?= $rtl?'اختياري...':'Optional...' ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="height:37px"><i class="fa-solid fa-plus"></i><?= $rtl?'منح':'Grant' ?></button>
              </div>
            </form>
            <!-- الصلاحيات الممنوحة حالياً -->
            <?php
            $granted=$pdo->query("
                SELECT upo.*,u.full_name,p.code AS page_code,pp.action,g.full_name AS gbn
                FROM user_permission_overrides upo
                INNER JOIN users u ON u.id=upo.user_id
                INNER JOIN page_permissions pp ON pp.id=upo.page_permission_id
                INNER JOIN pages p ON p.id=pp.page_id
                LEFT JOIN users g ON g.id=upo.granted_by
                WHERE upo.granted=1 ORDER BY upo.granted_at DESC
            ")->fetchAll();
            ?>
            <?php if($granted): ?>
            <div style="font-size:12px;font-weight:700;color:#475569;margin-bottom:7px"><?= $rtl?'الصلاحيات الممنوحة حالياً:':'Current Grants:' ?></div>
            <?php foreach($granted as $g):
              $fl='';
              foreach($workflow_features as $m) foreach($m['features'] as $f) if($f['page']===$g['page_code']&&$f['action']===$g['action']) $fl=$rtl?$f['ar']:$f['en'];
            ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:#fff;border:1px solid #e0e7ff;border-radius:8px;font-size:12.5px;margin-bottom:5px">
              <i class="fa-solid fa-user-check" style="color:#4f46e5;flex-shrink:0"></i>
              <span style="font-weight:700;color:#0f172a;flex:1"><?= e($g['full_name']) ?></span>
              <span style="background:#eef2ff;color:#3730a3;padding:2px 9px;border-radius:50px;font-size:11px;font-weight:600"><?= e($fl?:$g['page_code'].'/'.$g['action']) ?></span>
              <?php if($g['reason']): ?><span style="color:#94a3b8;font-size:11px"><?= e($g['reason']) ?></span><?php endif; ?>
              <span style="color:#94a3b8;font-size:10.5px;font-family:Inter"><?= substr($g['granted_at'],0,10) ?></span>
              <form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl?'سحب هذه الصلاحية؟':'Revoke?' ?>')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="grant_user_perm">
                <input type="hidden" name="grant" value="0">
                <input type="hidden" name="target_user_id" value="<?= $g['user_id'] ?>">
                <input type="hidden" name="page_code" value="<?= $g['page_code'] ?>">
                <input type="hidden" name="perm_action" value="<?= $g['action'] ?>">
                <button type="submit" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;font-family:'Tajawal'"><i class="fa-solid fa-xmark"></i><?= $rtl?'سحب':'Revoke' ?></button>
              </form>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="text-align:center;padding:14px;color:#94a3b8;font-size:12.5px">
              <i class="fa-solid fa-user-slash" style="font-size:20px;display:block;margin-bottom:5px;opacity:.3"></i>
              <?= $rtl?'لا توجد صلاحيات خاصة حالياً':'No user-specific grants yet' ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /workflow body -->
    </div><!-- /workflow card -->

    <!-- ══ اللجان الثابتة ══ -->
    <div class="s-card" id="standing">
      <div class="s-card-head">
        <div class="s-card-title"><i class="fa-solid fa-users-gear"></i><?= $rtl?'اللجان الثابتة حسب نوع الاستلام':'Standing Committees' ?></div>
      </div>
      <div style="padding:14px 16px">
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:10px 13px;margin-bottom:14px;font-size:12px;color:#92400e">
          <i class="fa-solid fa-circle-info"></i>
          <?= $rtl?'عند إنشاء لجنة جديدة تُغلق السابقة تلقائياً بتاريخ يوم قبل البداية الجديدة. السجل التاريخي يُحفظ كاملاً.':'Creating a new committee auto-closes the previous one.' ?>
        </div>

        <?php
        $scIcons=['medical'=>'fa-heart-pulse','general'=>'fa-screwdriver-wrench','it'=>'fa-laptop'];
        $scColors=['medical'=>'#1565C0','general'=>'#16a34a','it'=>'#7c3aed'];
        $scBg=['medical'=>'#eff6ff','general'=>'#f0fdf4','it'=>'#f5f3ff'];
        foreach($sc_types as $sct=>$scLabel):
          $sd=$sc_data[$sct];
          $comm=$sd['committee']; $mems=$sd['members'];
        ?>
        <div style="border:1.5px solid var(--color-border-tertiary);border-radius:12px;margin-bottom:14px;overflow:hidden">
          <!-- رأس النوع -->
          <div style="padding:10px 14px;background:<?= $scBg[$sct] ?>;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:8px">
              <i class="fa-solid <?= $scIcons[$sct] ?>" style="color:<?= $scColors[$sct] ?>;font-size:16px"></i>
              <span style="font-size:13px;font-weight:600;color:<?= $scColors[$sct] ?>"><?= $scLabel ?></span>
              <?php if($comm&&!$comm['end_date']): ?>
              <span style="background:<?= $scColors[$sct] ?>;color:#fff;font-size:10px;padding:2px 8px;border-radius:50px">نشطة منذ <?= $comm['start_date'] ?></span>
              <?php else: ?>
              <span style="background:#f1f5f9;color:#94a3b8;font-size:10px;padding:2px 8px;border-radius:50px">لا توجد لجنة ثابتة</span>
              <?php endif; ?>
            </div>
            <button type="button" onclick="openScCreate('<?= $sct ?>','<?= $scLabel ?>')" class="btn btn-primary btn-sm">
              <i class="fa-solid fa-plus"></i> <?= $rtl?'لجنة جديدة':'New Committee' ?>
            </button>
          </div>

          <!-- الأعضاء الحاليون -->
          <div style="padding:10px 14px">
            <?php if($comm&&$mems): ?>
            <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
              <?php foreach($mems as $m): ?>
              <div style="display:flex;align-items:center;gap:8px;padding:6px 10px;background:var(--color-background-secondary);border-radius:8px">
                <i class="fa-solid fa-user-circle" style="color:#94a3b8;font-size:15px"></i>
                <span style="flex:1;font-size:12.5px;font-weight:500"><?= e($m['uname']) ?></span>
                <span style="font-size:10.5px;padding:2px 8px;background:<?= $scBg[$sct] ?>;color:<?= $scColors[$sct] ?>;border-radius:50px"><?= e($m['role']) ?></span>
                <form method="POST" style="margin:0">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="sc_del_member">
                  <input type="hidden" name="mem_id" value="<?= $m['id']??'' ?>">
                  <button type="submit" class="btn" style="padding:3px 8px;background:#fef2f2;color:#dc2626;font-size:10px;border:1px solid #fecaca" onclick="return confirm('حذف <?= e($m['uname']) ?>؟')">
                    <i class="fa-solid fa-xmark"></i>
                  </button>
                </form>
              </div>
              <?php endforeach; ?>
            </div>
            <!-- إضافة عضو للجنة الحالية -->
            <?php if($comm): ?>
            <form method="POST" style="display:flex;gap:8px;align-items:center">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="sc_add_member">
              <input type="hidden" name="sc_id" value="<?= $comm['id'] ?>">
              <select name="m_user" class="fi" style="flex:2;font-size:12px">
                <option value=""><?= $rtl?'— اختر عضواً —':'— Select member —' ?></option>
                <?php foreach($all_users_list as $u): ?>
                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="m_role" class="fi" style="flex:1;font-size:12px">
                <option value="رئيس">رئيس</option>
                <option value="عضو فني">عضو فني</option>
                <option value="عضو" selected>عضو</option>
                <option value="أمين مستودع">أمين مستودع</option>
              </select>
              <button type="submit" class="btn btn-primary" style="white-space:nowrap;font-size:12px">
                <i class="fa-solid fa-user-plus"></i> إضافة
              </button>
            </form>
            <?php endif; ?>
            <?php else: ?>
            <div style="text-align:center;padding:14px;color:#94a3b8;font-size:12.5px">
              <i class="fa-solid fa-users" style="font-size:22px;display:block;margin-bottom:6px;opacity:.3"></i>
              <?= $rtl?'لا توجد لجنة ثابتة — أنشئ لجنة جديدة':'No standing committee — create one' ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- modal إنشاء لجنة جديدة -->
    <div id="scModal" class="lt-modal" style="display:none">
      <div class="lt-modal-box" style="max-width:480px">
        <div class="lt-modal-head">
          <span id="scModalTitle">إنشاء لجنة ثابتة</span>
          <button type="button" onclick="document.getElementById('scModal').style.display='none'" style="background:none;border:none;font-size:18px;cursor:pointer;color:#94a3b8">×</button>
        </div>
        <form method="POST">
          <?= csrf_input() ?>
          <input type="hidden" name="action" value="sc_create">
          <input type="hidden" name="sc_type" id="scModalType">
          <div class="lm-grid" style="padding:14px">
            <div class="fg">
              <label style="font-size:12px;font-weight:600;color:#64748b">مسمى اللجنة<span class="req">*</span></label>
              <input type="text" name="sc_name" class="fi" required placeholder="مثال: لجنة استلام الأجهزة الطبية 2025">
            </div>
            <div class="fg">
              <label style="font-size:12px;font-weight:600;color:#64748b">تاريخ البداية<span class="req">*</span></label>
              <input type="date" name="sc_start_date" class="fi" required value="<?= date('Y-m-d') ?>">
            </div>
            <!-- أعضاء أوليون -->
            <div style="font-size:12px;font-weight:600;color:#64748b;margin-top:6px">أعضاء اللجنة (يمكن إضافة المزيد لاحقاً)</div>
            <div id="scMembersAdd" style="display:flex;flex-direction:column;gap:7px">
              <div class="sc-mem-row" style="display:flex;gap:7px;align-items:center">
                <select name="sc_users[]" class="fi" style="flex:2;font-size:12px">
                  <option value="">— اختر —</option>
                  <?php foreach($all_users_list as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="sc_roles[]" class="fi" style="flex:1;font-size:12px">
                  <option value="رئيس">رئيس</option>
                  <option value="عضو فني">عضو فني</option>
                  <option value="عضو" selected>عضو</option>
                  <option value="أمين مستودع">أمين مستودع</option>
                </select>
              </div>
            </div>
            <button type="button" onclick="addScMemberRow()" style="background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:7px;padding:6px;font-size:12px;cursor:pointer;color:#64748b;font-family:'Tajawal';width:100%;margin-top:4px">
              <i class="fa-solid fa-plus"></i> إضافة عضو آخر
            </button>
          </div>
          <div style="display:flex;gap:10px;justify-content:flex-end;padding:0 14px 14px">
            <button type="button" onclick="document.getElementById('scModal').style.display='none'" class="btn btn-outline">إلغاء</button>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> إنشاء اللجنة</button>
          </div>
        </form>
      </div>
    </div>

    <!-- ══ جداول القوائم ══ -->
    <?php foreach($lookup_tables as $tbl => $info): ?>
    <div class="s-card" id="<?= $tbl ?>">
      <div class="s-card-head">
        <div class="s-card-title">
          <i class="fa-solid <?= $info['icon'] ?>"></i>
          <?= $rtl?e($info['ar']):e($info['en']) ?>
          <span style="font-size:11.5px;color:#94a3b8;font-weight:400">(<?= count($data[$tbl]) ?> <?= $rtl?'عنصر':'items' ?>)</span>
        </div>
      </div>
      <table class="lt-table">
        <thead>
          <tr>
            <th>#</th>
            <th><?= $rtl?'الاسم بالعربية':'Arabic Name' ?></th>
            <th><?= $rtl?'الاسم بالإنجليزية':'English Name' ?></th>
            <th><?= $rtl?'الترتيب':'Sort' ?></th>
            <th><?= $rtl?'الحالة':'Status' ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php if(empty($data[$tbl])): ?>
        <tr><td colspan="6" style="text-align:center;padding:28px;color:#94a3b8;font-size:13px">
          <?= $rtl?'لا توجد عناصر بعد':'No items yet' ?>
        </td></tr>
        <?php else: foreach($data[$tbl] as $row): ?>
        <tr>
          <td style="color:#94a3b8;font-size:12px"><?= $row['id'] ?></td>
          <td style="font-weight:600;color:#0f172a"><?= e($row['name']) ?></td>
          <td style="color:#64748b"><?= e($row['name_en']??'—') ?></td>
          <td style="font-family:'Inter',monospace;color:#64748b"><?= $row['sort_order'] ?></td>
          <td>
            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;color:<?= $row['is_active']?'#16a34a':'#94a3b8' ?>">
              <span class="status-dot" style="background:<?= $row['is_active']?'#16a34a':'#94a3b8' ?>"></span>
              <?= $row['is_active']?($rtl?'نشط':'Active'):($rtl?'معطّل':'Inactive') ?>
            </span>
          </td>
          <td>
            <div style="display:flex;gap:5px">
              <button class="ab ab-edit" title="<?= $rtl?'تعديل':'Edit' ?>"
                onclick="openLtEdit('<?= $tbl ?>',<?= $row['id'] ?>,<?= json_encode($row['name']) ?>,<?= json_encode($row['name_en']??'') ?>,<?= $row['sort_order'] ?>,<?= $row['is_active'] ?>,<?= $tbl==='committee_types'?(int)($row['requires_approval']??1):1 ?>)">
                <i class="fa-solid fa-pen"></i>
              </button>
              <form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl?'حذف هذا العنصر؟':'Delete this item?' ?>')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="tbl"    value="<?= $tbl ?>">
                <input type="hidden" name="id"     value="<?= $row['id'] ?>">
                <button type="submit" class="ab ab-del" title="<?= $rtl?'حذف':'Delete' ?>">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
      <!-- إضافة سريعة -->
      <form method="POST" class="add-row">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="tbl"    value="<?= $tbl ?>">
        <input type="text" name="name"    placeholder="<?= $rtl?'الاسم بالعربية *':'Arabic Name *' ?>" required>
        <input type="text" name="name_en" placeholder="<?= $rtl?'الاسم بالإنجليزية':'English Name' ?>">
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="fa-solid fa-plus"></i><?= $rtl?'إضافة':'Add' ?>
        </button>
      </form>
    </div>
    <?php endforeach; ?>

    <!-- ══ إعدادات الذكاء الاصطناعي (AI Provider Settings) ══ -->
    <?php
      $ai_provider = ai_provider();
      $ai_model    = ai_model();
      $ai_base_url = ai_base_url();
      $has_db_key  = (string)get_setting('groq_api_key', '') !== '';
      $masked_key  = $has_db_key ? '••••••••••••' . substr(ai_key(), -4) : '';
    ?>
    <div class="s-card" id="ai-settings" style="border-left:4px solid #7c3aed;margin-bottom:18px">
      <div class="s-card-head">
        <div class="s-card-title">
          <i class="fa-solid fa-robot" style="color:#7c3aed"></i>
          إعدادات الذكاء الاصطناعي (AI Provider)
          <span style="background:#ede9fe;color:#6d28d9;padding:2px 8px;border-radius:99px;font-size:10.5px;margin-right:8px;font-weight:900">
            <?= strtoupper($ai_provider) ?>
          </span>
        </div>
      </div>

      <div style="padding:14px 18px;background:#faf5ff;border-bottom:1px solid #e9d5ff;font-size:12px;color:#6d28d9">
        <i class="fa-solid fa-circle-info"></i>
        المفتاح يُشفّر في DB (AES-256). الكل يقرأ من نفس المصدر — تغيير واحد هنا = تحديث للكل.
        <?php if (!$has_db_key): ?>
        <br><strong style="color:#92400e">⚠ حالياً يُستخدم المفتاح من config.php (fallback).</strong>
        <?php else: ?>
        <br>✅ المفتاح يُقرأ من DB (مشفّر).
        <?php endif; ?>
      </div>

      <form id="aiSettingsForm" style="padding:18px">
        <?= csrf_input() ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="fg">
            <label>المزود</label>
            <select name="provider" id="aiProvider" class="mfi" onchange="onProviderChange()">
              <option value="groq"     <?= $ai_provider==='groq'?'selected':'' ?>>Groq (الأسرع، مجاني)</option>
              <option value="openai"   <?= $ai_provider==='openai'?'selected':'' ?>>OpenAI (الأعلى جودة)</option>
              <option value="deepseek" <?= $ai_provider==='deepseek'?'selected':'' ?>>DeepSeek (الأرخص)</option>
              <option value="custom"   <?= $ai_provider==='custom'?'selected':'' ?>>مخصص (OpenAI-compatible)</option>
            </select>
          </div>

          <div class="fg">
            <label>Model</label>
            <input type="text" name="model" id="aiModel" class="mfi" value="<?= e($ai_model) ?>"
                   placeholder="مثل: llama-3.3-70b-versatile">
            <div class="help" id="aiModelHelp"></div>
          </div>

          <div class="fg full">
            <label>API Key</label>
            <div style="display:flex;gap:6px;align-items:center">
              <input type="password" name="api_key" id="aiKey" class="mfi" style="flex:1" dir="ltr"
                     placeholder="<?= $has_db_key ? 'اتركه فارغاً للإبقاء على المفتاح الحالي' : 'sk-... أو gsk-...' ?>"
                     value="">
              <?php if ($has_db_key): ?>
              <button type="button" onclick="toggleShowKey()" class="s-btn s-btn-outline" style="height:42px" title="إظهار آخر 4 حروف من المفتاح">
                <i class="fa-solid fa-eye"></i> <?= e($masked_key) ?>
              </button>
              <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#dc2626;cursor:pointer">
                <input type="checkbox" name="clear_key" value="1"> احذف من DB
              </label>
              <?php endif; ?>
            </div>
            <div class="help">
              <i class="fa-solid fa-lock" style="color:#10b981"></i>
              يُشفّر بـ AES-256 قبل الحفظ. لا أحد يرى المفتاح كاملاً في الواجهة.
            </div>
          </div>

          <div class="fg full">
            <label>Base URL</label>
            <input type="text" name="base_url" id="aiBaseUrl" class="mfi" value="<?= e($ai_base_url) ?>"
                   dir="ltr" placeholder="https://api.groq.com/openai/v1">
          </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:18px;align-items:center">
          <button type="button" class="s-btn s-btn-primary" onclick="saveAISettings()" id="aiSaveBtn">
            <i class="fa-solid fa-floppy-disk"></i> حفظ وتطبيق
          </button>
          <button type="button" class="s-btn s-btn-outline" onclick="testAI()">
            <i class="fa-solid fa-bolt"></i> اختبار الاتصال
          </button>
          <span id="aiStatus" class="eng" style="font-size:11.5px;margin-inline-start:auto"></span>
        </div>
      </form>
    </div>

    <script>
    const AI_PROVIDER_DEFAULTS = <?= json_encode([
        'groq' => ['model'=>'llama-3.3-70b-versatile','base_url'=>'https://api.groq.com/openai/v1'],
        'openai' => ['model'=>'gpt-4o-mini','base_url'=>'https://api.openai.com/v1'],
        'deepseek' => ['model'=>'deepseek-chat','base_url'=>'https://api.deepseek.com/v1'],
        'custom' => ['model'=>'','base_url'=>''],
    ], JSON_UNESCAPED_UNICODE) ?>;

    function onProviderChange() {
      const p = document.getElementById('aiProvider').value;
      const def = AI_PROVIDER_DEFAULTS[p];
      if (def && !document.getElementById('aiModel').value.trim()) {
        document.getElementById('aiModel').value = def.model;
      }
      if (def && !document.getElementById('aiBaseUrl').value.trim()) {
        document.getElementById('aiBaseUrl').value = def.base_url;
      }
    }

    function toggleShowKey() {
      const inp = document.getElementById('aiKey');
      inp.type = inp.type === 'password' ? 'text' : 'password';
    }

    async function saveAISettings() {
      const btn = document.getElementById('aiSaveBtn');
      const status = document.getElementById('aiStatus');
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> جاري...';
      status.textContent = '';
      status.style.color = '#475569';

      const fd = new FormData(document.getElementById('aiSettingsForm'));
      try {
        const r = await fetch('api/save_ai_settings.php', { method:'POST', body: fd });
        const d = await r.json();
        if (d.ok) {
          status.textContent = '✅ ' + (d.msg || 'تم الحفظ');
          status.style.color = '#10b981';
          setTimeout(() => location.reload(), 1200);
        } else {
          status.textContent = '⚠ ' + (d.msg || 'فشل') + (d.detail ? ' — ' + d.detail : '');
          status.style.color = '#dc2626';
        }
      } catch (e) {
        status.textContent = 'Network: ' + e.message;
        status.style.color = '#dc2626';
      } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ وتطبيق';
      }
    }

    async function testAI() {
      const status = document.getElementById('aiStatus');
      status.textContent = '🔄 جاري الاختبار...';
      status.style.color = '#2563eb';
      // للاختبار نحفظ أولاً ثم نختبر
      await saveAISettings();
    }
    </script>

    <!-- ══ أقسام إدارة التصنيفات والمواقع (تُدرج من ملف مستقل) ══ -->
    <?php
      if (!defined('PMSH_SETTINGS_SECTION')) define('PMSH_SETTINGS_SECTION', true);
      require_once dirname(__FILE__) . '/_sections/lookup_admin.php';
    ?>

  </div>
</div>

<!-- ══ Modal تعديل عنصر ══ -->
<div class="lt-modal" id="ltModal" onclick="if(event.target===this)closeLtModal()">
  <div class="lt-modal-box">
    <div class="lm-title"><i class="fa-solid fa-pen" style="color:#1565C0"></i><?= $rtl?'تعديل العنصر':'Edit Item' ?></div>
    <form method="POST">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="tbl"    id="lmTbl">
      <input type="hidden" name="id"     id="lmId">
      <div class="lm-grid">
        <div class="lmg">
          <label><?= $rtl?'الاسم بالعربية *':'Arabic Name *' ?></label>
          <input type="text" name="name" id="lmName" class="lmi" required>
        </div>
        <div class="lmg">
          <label><?= $rtl?'الاسم بالإنجليزية':'English Name' ?></label>
          <input type="text" name="name_en" id="lmNameEn" class="lmi">
        </div>
        <div class="lmg">
          <label><?= $rtl?'الترتيب':'Sort Order' ?></label>
          <input type="number" name="sort_order" id="lmSort" class="lmi" min="0">
        </div>
        <div style="display:flex;align-items:center;gap:9px;padding-top:4px">
          <input type="checkbox" name="is_active" id="lmActive" style="width:18px;height:18px;accent-color:#1565C0">
          <label for="lmActive" style="font-size:13px;font-weight:600;cursor:pointer;color:#334155"><?= $rtl?'نشط':'Active' ?></label>
        </div>
        <!-- يظهر فقط لأنواع اللجان -->
        <div id="reqApprovalRow" style="display:none;align-items:center;gap:9px;padding-top:4px;background:#fffbeb;border-radius:8px;padding:9px 11px;border:1px solid #fde68a">
          <input type="checkbox" name="requires_approval" id="lmReqApproval" style="width:18px;height:18px;accent-color:#d97706" checked>
          <div>
            <label for="lmReqApproval" style="font-size:13px;font-weight:600;cursor:pointer;color:#92400e;display:block"><?= $rtl?'يحتاج اعتماد المدير التنفيذي':'Requires Executive Approval' ?></label>
            <div style="font-size:11px;color:#78350f"><?= $rtl?'إذا كان محدداً: اللجنة تمر بخطوة الاعتماد. إذا لم يكن: تُفعَّل مباشرة.':'Checked = needs approval, Unchecked = direct activation' ?></div>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
        <button type="button" class="btn btn-outline" onclick="closeLtModal()"><?= $rtl?'إلغاء':'Cancel' ?></button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i><?= $rtl?'حفظ':'Save' ?></button>
      </div>
    </form>
  </div>
</div>

<?php include BASE_PATH.'/includes/perm_modal.php'; ?>

<script>
// تفعيل الشريط الجانبي
document.querySelectorAll('.s-nav-item').forEach(a=>{
    a.addEventListener('click',function(){
        document.querySelectorAll('.s-nav-item').forEach(b=>b.classList.remove('on'));
        this.classList.add('on');
    });
});
// تفعيل أول عنصر نشط حسب hash
if(location.hash){
    const target=document.querySelector('.s-nav-item[href="'+location.hash+'"]');
    if(target){document.querySelectorAll('.s-nav-item').forEach(b=>b.classList.remove('on'));target.classList.add('on');}
}

// Modal التعديل
function openScCreate(type,label){
    document.getElementById('scModalType').value=type;
    document.getElementById('scModalTitle').textContent='إنشاء لجنة ثابتة — '+label;
    document.getElementById('scModal').style.display='flex';
}
function addScMemberRow(){
    const c=document.getElementById('scMembersAdd');
    const f=c.querySelector('.sc-mem-row');
    if(!f)return;
    const cl=f.cloneNode(true);
    cl.querySelectorAll('select').forEach(s=>s.selectedIndex=0);
    c.appendChild(cl);
}
document.addEventListener('click',e=>{
    const m=document.getElementById('scModal');
    if(m&&e.target===m)m.style.display='none';
});

function openLtEdit(tbl,id,name,nameEn,sort,active,reqApproval){
    document.getElementById('lmTbl').value    = tbl;
    document.getElementById('lmId').value     = id;
    document.getElementById('lmName').value   = name;
    document.getElementById('lmNameEn').value = nameEn;
    document.getElementById('lmSort').value   = sort;
    document.getElementById('lmActive').checked = !!active;
    // صف requires_approval لأنواع اللجان فقط
    const reqRow=document.getElementById('reqApprovalRow');
    if(tbl==='committee_types'){
        reqRow.style.display='flex';
        document.getElementById('lmReqApproval').checked = (reqApproval===undefined||reqApproval===null||reqApproval==1);
    } else {reqRow.style.display='none';}
    document.getElementById('ltModal').classList.add('open');
}
function closeLtModal(){document.getElementById('ltModal').classList.remove('open');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closeLtModal();});
</script>
</body>
</html>