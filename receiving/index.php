<?php
/**
 * receiving/index.php — قائمة محاضر الاستلام (مُحدّث لإظهار المحاضر للمشاركين والصلاحيات الدقيقة)
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('receiving.index');

$rtl        = is_rtl();
$uid        = (int)current_user()['id'];
$can_create = can('receiving.index','create') || can('receiving.form','create');

$f_status  = trim($_GET['status']   ?? '');
$f_comm    = (int)($_GET['committee']?? 0);
$f_type    = trim($_GET['doctype']  ?? '');
$page      = max(1,(int)($_GET['p'] ?? 1));
$per       = 20;

// صمام تجاوز إداري: صلاحية "عرض الكل" (view_all) تُمنح من شاشة الأدوار
// بلا أي تعديل كود — منفصلة عن can_see_all() المقصورة على أدمن/تنفيذي.
$view_all = can('receiving.index', 'view_all');
if ($view_all) {
    $custom_w = '1=1';
    $params   = [];
} else {
    $scope  = data_scope('receiving_minute', 'rm');
    $base_w = !empty($scope['where']) ? $scope['where'] : '1=0';
    $params = isset($scope['params']) && is_array($scope['params']) ? $scope['params'] : [];

    $custom_w = "($base_w
        OR EXISTS (SELECT 1 FROM document_approvals da WHERE da.doc_type='receiving_minute' AND da.doc_id=rm.id AND da.user_id=$uid)
        OR EXISTS (SELECT 1 FROM receiving_minute_items rmi JOIN departments d ON d.id=rmi.department_id WHERE rmi.minute_id=rm.id AND d.manager_id=$uid)
        OR EXISTS (SELECT 1 FROM receiving_minute_items rmi2 WHERE rmi2.minute_id=rm.id AND rmi2.receiver_user_id=$uid)
    )";
}

$where = [$custom_w]; 

if ($f_status) { $where[] = 'rm.status=?';          $params[] = $f_status; }
if ($f_comm)   { $where[] = 'rm.committee_id=?';    $params[] = $f_comm; }
if ($f_type)   { $where[] = 'rm.doc_type=?';        $params[] = $f_type; }

$wh = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM receiving_minutes rm WHERE $wh");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1,(int)ceil($total/$per));
$page  = min($page,$pages);
$off   = ($page-1)*$per;

$rows = $pdo->prepare("
    SELECT rm.*,
           sc.name AS committee_name,
           u.full_name AS creator_name,
           (SELECT COUNT(*) FROM receiving_minute_items rmi WHERE rmi.minute_id=rm.id) AS item_cnt,
           (SELECT COUNT(*) FROM document_approvals da WHERE da.doc_type='receiving_minute' AND da.doc_id=rm.id AND da.status='approved') AS signed_cnt,
           (SELECT COUNT(*) FROM document_approvals da WHERE da.doc_type='receiving_minute' AND da.doc_id=rm.id) AS total_sigs
    FROM receiving_minutes rm
    LEFT JOIN standing_committees sc ON sc.id=rm.standing_committee_id
    LEFT JOIN users u ON u.id=rm.created_by
    WHERE $wh ORDER BY rm.id DESC LIMIT $per OFFSET $off");
$rows->execute($params); $minutes = $rows->fetchAll();

$stat_sql = "SELECT COUNT(*) total,
    SUM(status='draft') draft, SUM(status='sent') sent,
    SUM(status='approved') approved, SUM(status='rejected') rejected
    FROM receiving_minutes rm WHERE $custom_w";
$st_stmt = $pdo->prepare($stat_sql);
$st_stmt->execute(isset($scope['params']) && is_array($scope['params']) ? $scope['params'] : []);
$sr = $st_stmt->fetch();

$committees = $pdo->query("SELECT id,name FROM standing_committees ORDER BY name")->fetchAll();

$status_cfg = [
    'draft'     =>['ar'=>'مسودة',      'en'=>'Draft',     'c'=>'#64748b','b'=>'#f1f5f9','i'=>'fa-pencil'],
    'sent'      =>['ar'=>'قيد التوقيع','en'=>'Signing',   'c'=>'#d97706','b'=>'#fffbeb','i'=>'fa-pen-fancy'],
    'approved'  =>['ar'=>'مكتمل',      'en'=>'Completed', 'c'=>'#16a34a','b'=>'#f0fdf4','i'=>'fa-circle-check'],
    'rejected'  =>['ar'=>'مرفوض',      'en'=>'Rejected',  'c'=>'#dc2626','b'=>'#fef2f2','i'=>'fa-circle-xmark'],
];
$doc_types = [
    'purchase'           =>['ar'=>'شراء',       'en'=>'Purchase'],
    'donation'           =>['ar'=>'تبرع',        'en'=>'Donation'],
    'transfer'           =>['ar'=>'نقل',          'en'=>'Transfer'],
    'maintenance_return' =>['ar'=>'إعادة صيانة','en'=>'Maint. Return'],
];

function purl(int $p): string { $q=$_GET;$q['p']=$p;return '?'.http_build_query($q); }

$page_title=$rtl?'محاضر الاستلام':'Receiving Minutes';
$page_icon='fa-file-lines'; $active_nav='receiving.index'; $breadcrumb=[];
$flash_msgs=get_flash();
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
.r-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:14px}
@media(max-width:900px){.r-stats{grid-template-columns:repeat(3,1fr)}}
.r-card{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden}
.r-head{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:7px}
.r-ht{font-size:13px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:6px}
.fi-bar{background:#fff;border-radius:12px;padding:10px 14px;display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.05);align-items:center}
.fi-sel{height:35px;padding:0 10px;border:1.5px solid #e2e8f0;border-radius:9px;font-family:'Tajawal',sans-serif;font-size:13px;background:#fff;outline:none}
.fi-sel:focus{border-color:#1565C0}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;font-weight:700;color:#64748b;padding:9px 14px;background:#f8fafc;border-bottom:1px solid #f1f5f9;text-align:inherit;white-space:nowrap}
tbody td{padding:10px 14px;font-size:13px;border-bottom:1px solid #f8fafc;vertical-align:middle}
tbody tr:hover td{background:#fafafa}
tbody tr:last-child td{border-bottom:none}
.s-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;border-radius:50px;padding:2px 10px}
.sig-bar{display:flex;align-items:center;gap:5px;margin-top:4px}
.sig-track{flex:1;height:5px;background:#f1f5f9;border-radius:3px;overflow:hidden}
.sig-fill{height:100%;background:#16a34a;border-radius:3px;transition:width .3s}
.sig-txt{font-size:10px;color:#64748b;white-space:nowrap}
.ab{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;border:1.5px solid;cursor:pointer;font-size:11px;transition:.15s;background:#fff;text-decoration:none}
.ab-v{color:#1565C0;border-color:#bfdbfe}.ab-v:hover{background:#1565C0;color:#fff}
.ab-p{color:#d97706;border-color:#fde68a}.ab-p:hover{background:#d97706;color:#fff}
.ab-pr{color:#7B1FA2;border-color:#e9d5ff}.ab-pr:hover{background:#7B1FA2;color:#fff}
.pag{display:flex;justify-content:space-between;align-items:center;padding:11px 16px;border-top:1px solid #f1f5f9}
.pb{height:30px;min-width:30px;border-radius:7px;border:1.5px solid #e2e8f0;background:#fff;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#334155;padding:0 6px;transition:.15s}
.pb:hover{background:#f1f5f9}.pb.on{background:#1565C0;color:#fff;border-color:#1565C0}.pb.dis{opacity:.4;pointer-events:none}
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

<div class="r-stats">
<?php $sc=[
  ['v'=>$sr['total'],     'ar'=>'المحاضر المرتبطة', 'en'=>'Related Minutes', 'ico'=>'fa-file-lines',    'c'=>'#1565C0','bg'=>'#E3F2FD'],
  ['v'=>$sr['draft'],     'ar'=>'مسودة',           'en'=>'Draft',           'ico'=>'fa-pencil',         'c'=>'#64748b','bg'=>'#F1F5F9'],
  ['v'=>$sr['sent'],      'ar'=>'قيد التوقيع',     'en'=>'Signing',         'ico'=>'fa-pen-fancy',      'c'=>'#d97706','bg'=>'#FFFBEB'],
  ['v'=>$sr['approved'],  'ar'=>'مكتملة',          'en'=>'Completed',       'ico'=>'fa-circle-check',   'c'=>'#16a34a','bg'=>'#F0FDF4'],
  ['v'=>$sr['rejected'],  'ar'=>'مرفوضة',          'en'=>'Rejected',        'ico'=>'fa-circle-xmark',   'c'=>'#dc2626','bg'=>'#FEF2F2'],
];
foreach($sc as $s): ?>
<div class="stat-card" style="cursor:default">
  <div class="stat-ico" style="background:<?= $s['bg'] ?>"><i class="fa-solid <?= $s['ico'] ?>" style="color:<?= $s['c'] ?>"></i></div>
  <div><div class="stat-lbl"><?= $rtl?e($s['ar']):e($s['en']) ?></div><div class="stat-val"><?= number_format($s['v']??0) ?></div></div>
</div>
<?php endforeach; ?>
</div>

<form class="fi-bar" method="GET">
  <select name="status" class="fi-sel" onchange="this.form.submit()">
    <option value=""><?= $rtl?'كل الحالات':'All Statuses' ?></option>
    <?php foreach($status_cfg as $sv=>$sl): ?>
    <option value="<?= $sv ?>" <?= $f_status===$sv?'selected':'' ?>><?= $rtl?e($sl['ar']):e($sl['en']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="committee" class="fi-sel" onchange="this.form.submit()">
    <option value=""><?= $rtl?'كل اللجان':'All Committees' ?></option>
    <?php foreach($committees as $cm): ?>
    <option value="<?= $cm['id'] ?>" <?= $f_comm===$cm['id']?'selected':'' ?>><?= e($cm['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="doctype" class="fi-sel" onchange="this.form.submit()">
    <option value=""><?= $rtl?'كل الأنواع':'All Types' ?></option>
    <?php foreach($doc_types as $dv=>$dl): ?>
    <option value="<?= $dv ?>" <?= $f_type===$dv?'selected':'' ?>><?= $rtl?e($dl['ar']):e($dl['en']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if($f_status||$f_comm||$f_type): ?>
  <a href="<?= BASE_URL ?>/receiving/index.php" style="font-size:12.5px;color:#64748b;text-decoration:none;display:flex;align-items:center;gap:4px">
    <i class="fa-solid fa-xmark"></i><?= $rtl?'مسح':'Clear' ?>
  </a>
  <?php endif; ?>
  <?php if($can_create): ?>
  <a href="<?= BASE_URL ?>/receiving/form.php" class="btn btn-primary btn-sm" style="margin-inline-start:auto">
    <i class="fa-solid fa-plus"></i><?= $rtl?'محضر جديد':'New Minute' ?>
  </a>
  <?php endif; ?>
</form>

<div class="r-card">
  <div class="r-head">
    <div class="r-ht"><i class="fa-solid fa-file-lines" style="color:#1565C0"></i>
      <?= $rtl?'محاضر الاستلام':'Receiving Minutes' ?>
      <span style="font-size:11.5px;color:#94a3b8;font-weight:400">(<?= number_format($total) ?>)</span>
    </div>
  </div>
  <div style="overflow-x:auto;">
      <table>
        <thead><tr>
          <th><?= $rtl?'رقم المحضر':'Minute No.' ?></th>
          <th><?= $rtl?'اللجنة':'Committee' ?></th>
          <th><?= $rtl?'النوع':'Type' ?></th>
          <th><?= $rtl?'المورد / المرجع':'Supplier / Ref.' ?></th>
          <th><?= $rtl?'الأصناف':'Items' ?></th>
          <th><?= $rtl?'التوقيعات':'Signatures' ?></th>
          <th><?= $rtl?'التاريخ':'Date' ?></th>
          <th><?= $rtl?'الحالة':'Status' ?></th>
          <th></th>
        </tr></thead>
        <tbody>
        <?php if(empty($minutes)): ?>
        <tr><td colspan="9" style="text-align:center;padding:48px;color:#94a3b8">
          <i class="fa-solid fa-file-lines" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3"></i>
          <?= $rtl?'لا توجد محاضر مرتبطة بك بعد':'No related minutes yet' ?>
        </td></tr>
        <?php else: foreach($minutes as $m):
          $sc=$status_cfg[$m['status']]??$status_cfg['draft'];
          $dt=$doc_types[$m['doc_type']]??['ar'=>$m['doc_type'],'en'=>$m['doc_type']];
          $sig_pct=$m['total_sigs']>0?round($m['signed_cnt']/$m['total_sigs']*100):0;
        ?>
        <tr onclick="location.href='<?= BASE_URL ?>/receiving/view.php?id=<?= $m['id'] ?>'" style="cursor:pointer">
          <td>
            <div style="font-weight:800;color:#1565C0;font-family:'Inter'"><?= e($m['minute_number']??'—') ?></div>
            <div style="font-size:11px;color:#94a3b8">#<?= $m['id'] ?></div>
          </td>
          <td style="font-size:12.5px;color:#334155"><?= e($m['committee_name']??'—') ?></td>
          <td><span style="font-size:12px;background:#f1f5f9;padding:2px 8px;border-radius:5px;color:#475569"><?= $rtl?e($dt['ar']):e($dt['en']) ?></span></td>
          <td>
            <div style="font-size:12.5px;color:#334155"><?= e($m['supplier_name']??'—') ?></div>
            <?php if($m['doc_number']): ?><div style="font-size:11px;color:#94a3b8">PO: <?= e($m['doc_number']) ?></div><?php endif; ?>
          </td>
          <td style="text-align:center">
            <span style="font-size:12.5px;font-weight:700;color:#7B1FA2;background:#F3E5F5;padding:2px 9px;border-radius:50px"><?= $m['item_cnt'] ?></span>
          </td>
          <td style="min-width:110px">
            <div class="sig-bar">
              <div class="sig-track"><div class="sig-fill" style="width:<?= $sig_pct ?>%"></div></div>
              <span class="sig-txt"><?= $m['signed_cnt'] ?>/<?= $m['total_sigs'] ?></span>
            </div>
          </td>
          <td style="font-size:11.5px;color:#64748b;font-family:'Inter'"><?= substr($m['created_at']??'',0,10) ?></td>
          <td><span class="s-pill" style="color:<?= $sc['c'] ?>;background:<?= $sc['b'] ?>"><i class="fa-solid <?= $sc['i'] ?>" style="font-size:9px"></i><?= $rtl?e($sc['ar']):e($sc['en']) ?></span></td>
          <td onclick="event.stopPropagation()">
            <div style="display:flex;gap:4px">
              <a href="<?= BASE_URL ?>/receiving/view.php?id=<?= $m['id'] ?>" class="ab ab-v"><i class="fa-solid fa-eye"></i></a>
              <?php if($m['status']==='draft'&&can('receiving.form','edit')): ?>
              <a href="<?= BASE_URL ?>/receiving/form.php?id=<?= $m['id'] ?>" class="ab ab-p"><i class="fa-solid fa-pen"></i></a>
              <?php endif; ?>
              <?php if($m['status']==='approved' && (can('receiving.form', 'print') || can('receiving.index', 'print'))): ?>
              <a href="<?= BASE_URL ?>/receiving/print.php?id=<?= $m['id'] ?>" target="_blank" class="ab ab-pr"><i class="fa-solid fa-print"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
  </div>
  <?php if($pages>1): ?>
  <div class="pag">
    <div style="font-size:12px;color:#64748b"><?= number_format($off+1) ?> - <?= number_format(min($off+$per,$total)) ?> <?= $rtl?'من':'of' ?> <?= number_format($total) ?></div>
    <div style="display:flex;gap:3px">
      <a href="<?= purl(1) ?>" class="pb <?= $page===1?'dis':'' ?>"><i class="fa-solid fa-angles-<?= $rtl?'right':'left' ?>"></i></a>
      <a href="<?= purl($page-1) ?>" class="pb <?= $page===1?'dis':'' ?>"><i class="fa-solid fa-angle-<?= $rtl?'right':'left' ?>"></i></a>
      <?php for($pp=max(1,$page-2);$pp<=min($pages,$page+2);$pp++) echo "<a href='".purl($pp)."' class='pb ".($pp===$page?'on':'')."'>$pp</a>"; ?>
      <a href="<?= purl($page+1) ?>" class="pb <?= $page===$pages?'dis':'' ?>"><i class="fa-solid fa-angle-<?= $rtl?'left':'right' ?>"></i></a>
      <a href="<?= purl($pages) ?>" class="pb <?= $page===$pages?'dis':'' ?>"><i class="fa-solid fa-angles-<?= $rtl?'left':'right' ?>"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>

</main></div>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>