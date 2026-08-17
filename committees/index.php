<?php
/**
 * committees/index.php — قائمة اللجان
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('committees.index');

$rtl        = is_rtl();
$can_create = can('committees.index','create');

// ── POST: إغلاق أو حذف ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';
    $cid    = (int)($_POST['id'] ?? 0);
    if ($action === 'close' && $cid) {
        $pdo->prepare("UPDATE committees SET status='closed' WHERE id=?")->execute([$cid]);
        flash('success', $rtl?'تم إغلاق اللجنة':'Committee closed');
    } elseif ($action === 'delete' && $cid && is_admin()) {
        $pdo->prepare("DELETE FROM committee_members WHERE committee_id=?")->execute([$cid]);
        $pdo->prepare("DELETE FROM committees WHERE id=?")->execute([$cid]);
        flash('success', $rtl?'تم حذف اللجنة':'Committee deleted');
    }
    header('Location: '.$_SERVER['REQUEST_URI']); exit;
}

// ── فلاتر ─────────────────────────────────────────────────────
$f_type   = (int)($_GET['type']   ?? 0);
$f_status = trim($_GET['status']  ?? '');
$page     = max(1,(int)($_GET['p'] ?? 1));
$per      = 20;

$scope  = data_scope('committee', 'c');
$where  = [$scope['where']]; $params = $scope['params'];
if ($f_type)   { $where[] = 'c.committee_type_id=?'; $params[] = $f_type; }
if ($f_status) { $where[] = 'c.status=?';             $params[] = $f_status; }
$wh = implode(' AND ',$where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM committees c WHERE $wh");
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1,(int)ceil($total/$per));
$page  = min($page,$pages);
$off   = ($page-1)*$per;

$rows = $pdo->prepare("
    SELECT c.*, ct.name AS type_name, ct.name_en AS type_name_en,
           u.full_name AS creator_name,
           (SELECT COUNT(*) FROM committee_members cm WHERE cm.committee_id=c.id) AS member_cnt
    FROM committees c
    LEFT JOIN committee_types ct ON ct.id=c.committee_type_id
    LEFT JOIN users u ON u.id=c.created_by
    WHERE $wh ORDER BY c.id DESC LIMIT $per OFFSET $off
");
$rows->execute($params); $committees = $rows->fetchAll();

// إحصاءات
$sr = $pdo->query("SELECT COUNT(*) AS total,
    SUM(status='draft') AS draft, SUM(status='active') AS active,
    SUM(status='closed') AS closed FROM committees")->fetch();
$types = $pdo->query("SELECT id,name,name_en FROM committee_types WHERE is_active=1 ORDER BY sort_order")->fetchAll();

$status_cfg = [
    'draft'  => ['ar'=>'مسودة',   'en'=>'Draft',  'c'=>'#64748b','b'=>'#f1f5f9','i'=>'fa-pencil'],
    'active' => ['ar'=>'نشطة',    'en'=>'Active', 'c'=>'#16a34a','b'=>'#f0fdf4','i'=>'fa-circle-check'],
    'closed' => ['ar'=>'منتهية',  'en'=>'Closed', 'c'=>'#94a3b8','b'=>'#f8fafc','i'=>'fa-lock'],
];

function page_url_c(int $p): string { $q=$_GET;$q['p']=$p;return '?'.http_build_query($q); }

$page_title=$rtl?'اللجان':'Committees';
$page_icon='fa-users-gear'; $active_nav='committees.index'; $breadcrumb=[];
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
.c-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
@media(max-width:800px){.c-stats{grid-template-columns:repeat(2,1fr)}}
.c-card{background:#fff;border-radius:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden}
.c-head{padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:7px}
.c-ht{font-size:13px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:6px}
.fi-bar{background:#fff;border-radius:12px;padding:11px 16px;display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;box-shadow:0 1px 4px rgba(0,0,0,.05);align-items:center}
.fi-sel{height:36px;padding:0 10px;border:1.5px solid #e2e8f0;border-radius:9px;font-family:'Tajawal',sans-serif;font-size:13px;background:#fff;outline:none;cursor:pointer}
.fi-sel:focus{border-color:#1565C0}
table{width:100%;border-collapse:collapse}
thead th{font-size:11px;font-weight:700;color:#64748b;padding:9px 14px;background:#f8fafc;border-bottom:1px solid #f1f5f9;text-align:inherit;white-space:nowrap}
tbody td{padding:10px 14px;font-size:13px;border-bottom:1px solid #f8fafc;vertical-align:middle}
tbody tr:hover td{background:#fafafa}
tbody tr:last-child td{border-bottom:none}
.s-pill{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;border-radius:50px;padding:2px 9px}
.mb-wrap{display:flex;gap:-6px}
.mb-av{width:26px;height:26px;border-radius:50%;background:linear-gradient(135deg,#1d4ed8,#7c3aed);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;border:2px solid #fff;margin-inline-end:-6px}
.ab{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:7px;border:1.5px solid;cursor:pointer;font-size:11px;transition:.15s;background:#fff;text-decoration:none}
.ab-v{color:#1565C0;border-color:#bfdbfe}.ab-v:hover{background:#1565C0;color:#fff}
.ab-e{color:#d97706;border-color:#fde68a}.ab-e:hover{background:#d97706;color:#fff}
.ab-c{color:#64748b;border-color:#e2e8f0}.ab-c:hover{background:#64748b;color:#fff}
.ab-d{color:#dc2626;border-color:#fecaca}.ab-d:hover{background:#dc2626;color:#fff}
.pag{display:flex;justify-content:space-between;align-items:center;padding:11px 16px;border-top:1px solid #f1f5f9;flex-wrap:wrap;gap:7px}
.pb{height:30px;min-width:30px;border-radius:7px;border:1.5px solid #e2e8f0;background:#fff;font-size:12px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;color:#334155;padding:0 6px;transition:all .15s}
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

<!-- ══ إحصاءات ══ -->
<div class="c-stats">
<?php $sc=[
  ['v'=>$sr['total']??0, 'ar'=>'إجمالي اللجان','en'=>'Total','ico'=>'fa-users-gear','c'=>'#1565C0','bg'=>'#E3F2FD'],
  ['v'=>$sr['draft']??0, 'ar'=>'مسودة',         'en'=>'Draft','ico'=>'fa-pencil',    'c'=>'#64748b','bg'=>'#F1F5F9'],
  ['v'=>$sr['active']??0,'ar'=>'نشطة',           'en'=>'Active','ico'=>'fa-circle-check','c'=>'#16a34a','bg'=>'#F0FDF4'],
  ['v'=>$sr['closed']??0,'ar'=>'منتهية',          'en'=>'Closed','ico'=>'fa-lock',    'c'=>'#94a3b8','bg'=>'#F8FAFC'],
];
foreach($sc as $s): ?>
<div class="stat-card" style="cursor:default">
  <div class="stat-ico" style="background:<?= $s['bg'] ?>"><i class="fa-solid <?= $s['ico'] ?>" style="color:<?= $s['c'] ?>"></i></div>
  <div><div class="stat-lbl"><?= $rtl?e($s['ar']):e($s['en']) ?></div><div class="stat-val"><?= number_format($s['v']) ?></div></div>
</div>
<?php endforeach; ?>
</div>

<!-- ══ فلاتر ══ -->
<form class="fi-bar" method="GET">
  <select name="type" class="fi-sel" onchange="this.form.submit()">
    <option value=""><?= $rtl?'كل الأنواع':'All Types' ?></option>
    <?php foreach($types as $t): ?>
    <option value="<?= $t['id'] ?>" <?= $f_type===$t['id']?'selected':'' ?>><?= e($rtl?$t['name']:($t['name_en']?:$t['name'])) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status" class="fi-sel" onchange="this.form.submit()">
    <option value=""><?= $rtl?'كل الحالات':'All Statuses' ?></option>
    <?php foreach($status_cfg as $sv=>$sl): ?>
    <option value="<?= $sv ?>" <?= $f_status===$sv?'selected':'' ?>><?= $rtl?e($sl['ar']):e($sl['en']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if($f_type||$f_status): ?>
  <a href="<?= BASE_URL ?>/committees/index.php" style="font-size:12.5px;color:#64748b;text-decoration:none;display:flex;align-items:center;gap:4px">
    <i class="fa-solid fa-xmark"></i><?= $rtl?'مسح':'Clear' ?>
  </a>
  <?php endif; ?>
  <?php if($can_create): ?>
  <a href="<?= BASE_URL ?>/committees/form.php" class="btn btn-primary btn-sm" style="margin-inline-start:auto">
    <i class="fa-solid fa-plus"></i><?= $rtl?'لجنة جديدة':'New Committee' ?>
  </a>
  <?php endif; ?>
</form>

<!-- ══ الجدول ══ -->
<div class="c-card">
  <div class="c-head">
    <div class="c-ht"><i class="fa-solid fa-users-gear" style="color:#1565C0"></i><?= $rtl?'قائمة اللجان':'Committees' ?>
      <span style="font-size:11.5px;color:#94a3b8;font-weight:400">(<?= number_format($total) ?>)</span>
    </div>
  </div>
  <table>
    <thead><tr>
      <th>#</th>
      <th><?= $rtl?'مسمى اللجنة':'Committee Name' ?></th>
      <th><?= $rtl?'النوع':'Type' ?></th>
      <th><?= $rtl?'الأعضاء':'Members' ?></th>
      <th><?= $rtl?'المنشئ':'Created By' ?></th>
      <th><?= $rtl?'التاريخ':'Date' ?></th>
      <th><?= $rtl?'الحالة':'Status' ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php if(empty($committees)): ?>
    <tr><td colspan="8" style="text-align:center;padding:48px;color:#94a3b8">
      <i class="fa-solid fa-users-gear" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3"></i>
      <?= $rtl?'لا توجد لجان بعد':'No committees yet' ?>
    </td></tr>
    <?php else: foreach($committees as $c):
      [$sc,$sb,$si,$sar,$sen]=[($status_cfg[$c['status']]['c']??'#64748b'),($status_cfg[$c['status']]['b']??'#f1f5f9'),($status_cfg[$c['status']]['i']??'fa-circle'),($status_cfg[$c['status']]['ar']??$c['status']),($status_cfg[$c['status']]['en']??$c['status'])];
      $tname=$rtl?($c['type_name']??'—'):($c['type_name_en']?:$c['type_name']??'—');
    ?>
    <tr onclick="location.href='<?= BASE_URL ?>/committees/view.php?id=<?= $c['id'] ?>'" style="cursor:pointer">
      <td style="color:#94a3b8;font-size:12px"><?= $c['id'] ?></td>
      <td>
        <div style="font-weight:700;color:#0f172a"><?= e($c['name']) ?></div>
        <?php if($c['purpose']): ?><div style="font-size:11px;color:#64748b;margin-top:1px"><?= e(mb_substr($c['purpose'],0,50)) ?><?= mb_strlen($c['purpose'])>50?'...':'' ?></div><?php endif; ?>
      </td>
      <td><span style="font-size:12px;color:#475569"><?= e($tname) ?></span></td>
      <td>
        <span style="font-size:12px;font-weight:700;color:#1565C0;background:#E3F2FD;padding:2px 9px;border-radius:50px">
          <i class="fa-solid fa-users" style="font-size:9px"></i> <?= $c['member_cnt'] ?>
        </span>
      </td>
      <td style="font-size:12px;color:#475569"><?= e($c['creator_name']??'—') ?></td>
      <td style="font-size:12px;color:#64748b;font-family:'Inter'"><?= substr($c['created_at']??'',0,10) ?></td>
      <td><span class="s-pill" style="color:<?= $sc ?>;background:<?= $sb ?>"><i class="fa-solid <?= $si ?>" style="font-size:9px"></i><?= $rtl?$sar:$sen ?></span></td>
      <td onclick="event.stopPropagation()">
        <div style="display:flex;gap:4px">
          <a href="<?= BASE_URL ?>/committees/view.php?id=<?= $c['id'] ?>" class="ab ab-v" title="<?= $rtl?'عرض':'View' ?>"><i class="fa-solid fa-eye"></i></a>
          <?php if($c['status']==='requested'&&(can('committees.approve','view')||is_admin())): ?>
          <a href="<?= BASE_URL ?>/committees/approve.php?id=<?= $c['id'] ?>" class="ab" style="color:#16a34a;border-color:#bbf7d0" title="<?= $rtl?'اعتماد':'Approve' ?>"><i class="fa-solid fa-gavel"></i></a>
          <?php elseif(in_array($c['status'],['draft','returned'])&&(int)($c['created_by']??0)===(int)current_user()['id']): ?>
          <a href="<?= BASE_URL ?>/committees/form.php?id=<?= $c['id'] ?>" class="ab ab-e" title="<?= $rtl?'تعديل':'Edit' ?>"><i class="fa-solid fa-pen"></i></a>
          <?php endif; ?>
          <?php if($c['status']!=='closed'&&$c['status']!=='rejected'&&is_admin()): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl?'إغلاق اللجنة؟':'Close?' ?>')">
            <?= csrf_input() ?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="ab ab-c" title="<?= $rtl?'إغلاق':'Close' ?>"><i class="fa-solid fa-lock"></i></button>
          </form>
          <?php endif; ?>
          <?php if(is_admin()): ?>
          <form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl?'حذف نهائياً؟':'Delete?' ?>')">
            <?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button type="submit" class="ab ab-d" title="<?= $rtl?'حذف':'Delete' ?>"><i class="fa-solid fa-trash"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  <?php if($pages>1): ?>
  <div class="pag">
    <div style="font-size:12px;color:#64748b"><?= number_format(($off+1)) ?> - <?= number_format(min($off+$per,$total)) ?> <?= $rtl?'من':'of' ?> <?= number_format($total) ?></div>
    <div style="display:flex;gap:3px">
      <a href="<?= page_url_c(1) ?>" class="pb <?= $page===1?'dis':'' ?>"><i class="fa-solid fa-angles-<?= $rtl?'right':'left' ?>"></i></a>
      <a href="<?= page_url_c($page-1) ?>" class="pb <?= $page===1?'dis':'' ?>"><i class="fa-solid fa-angle-<?= $rtl?'right':'left' ?>"></i></a>
      <?php for($pp=max(1,$page-2);$pp<=min($pages,$page+2);$pp++) echo "<a href='".page_url_c($pp)."' class='pb ".($pp===$page?'on':'')."'>$pp</a>"; ?>
      <a href="<?= page_url_c($page+1) ?>" class="pb <?= $page===$pages?'dis':'' ?>"><i class="fa-solid fa-angle-<?= $rtl?'left':'right' ?>"></i></a>
      <a href="<?= page_url_c($pages) ?>" class="pb <?= $page===$pages?'dis':'' ?>"><i class="fa-solid fa-angles-<?= $rtl?'left':'right' ?>"></i></a>
    </div>
  </div>
  <?php endif; ?>
</div>

</main></div>
<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
</body></html>