<?php
require_once dirname(__DIR__) . '/config.php';
page_guard('complaints.index');

$can_all = can_see_all();
$my_dept_id = (int) (current_user()['department_id'] ?? 0);
$my_team_type = null;

if (!$can_all && $my_dept_id) {
    $dc = $pdo->prepare("SELECT dept_category FROM departments WHERE id=?");
    $dc->execute([$my_dept_id]);
    $cat = $dc->fetchColumn();
    if ($cat && str_starts_with((string) $cat, 'maintenance_')) {
        // فقط فرق الصيانة الثلاثة تُعامَل كـ"فريق" — أي قسم سريري آخر (clinical)
        // يبقى $my_team_type=null عمداً، ليسقط على فرع dept_id أدناه بدل مطابقة
        // request_type='clinical' التي لا تطابق أي بلاغ إطلاقاً (هذا كان سبب الصفر).
        $my_team_type = substr($cat, strlen('maintenance_')); // medical, it, general
    }
}

// توجيه ذكي: من ليس عضو فريق صيانة (مُبلِّغ/مدير قسم سريري) يُحوَّل تلقائياً
// لصفحته الصحيحة "بلاغاتي" بدل عرض لوحة فريق الصيانة التي لا تخصّه — بنفس رابط
// الشريط الجانبي الواحد، بلا أي تعديل على التصميم أو إضافة روابط جديدة.
if (!$can_all && !$my_team_type) {
    // لجنة المتابعة جهة ثالثة مستقلة، لها صفحتها الخاصة لا "بلاغاتي"
    $myDeptCat = $pdo->prepare("SELECT dept_category FROM departments WHERE id=?");
    $myDeptCat->execute([$my_dept_id]);
    if ($myDeptCat->fetchColumn() === 'escalation_committee') {
        header('Location: ' . BASE_URL . '/complaints/escalation.php');
        exit;
    }
    header('Location: ' . BASE_URL . '/complaints/my.php');
    exit;
}

// 🎯 فلتر الأرشيف
$show_archived = isset($_GET['archived']) && can('complaints.index', 'manage');

if ($show_archived) {
    $where = "c.status IN ('closed','cancelled','rejected')";
} else {
    $where = "c.status NOT IN ('closed','cancelled','rejected')";
}

$params = [];
if (!$can_all) {
    if ($my_team_type) {
        $where .= " AND c.request_type=?";
        $params[] = $my_team_type;
    } else {
        // 🔧 إصلاح: الموظف العادي يرى بلاغات قسمه + بلاغاته الشخصية
        $where .= " AND (c.dept_id=? OR c.requested_by=?)";
        $params[] = $my_dept_id;
        $params[] = current_user()['id'];
    }
}

$rows = $pdo->prepare("SELECT c.*, a.description AS asset_desc, d.name AS dept_name 
                       FROM complaints c 
                       LEFT JOIN assets a ON a.id = c.asset_id 
                       LEFT JOIN departments d ON d.id = c.dept_id 
                       WHERE $where 
                       ORDER BY c.escalation_due_at ASC");
$rows->execute($params);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);

// البلاغات المُغلَقة حديثاً (آخر 7 أيام) من نطاق هذا الفريق
$closed_params = [];
$closed_sql = "SELECT c.id, c.request_number, c.priority, c.status, c.closed_at,
                      c.service_rating, a.description AS asset_desc, d.name AS dept_name
               FROM complaints c
               LEFT JOIN assets a ON a.id=c.asset_id
               LEFT JOIN departments d ON d.id=c.dept_id
               WHERE c.status IN ('closed','cancelled','rejected')
                 AND c.closed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
if (!$can_all && $my_team_type) {
    $closed_sql .= " AND request_type=?";
    $closed_params[] = $my_team_type;
}
$closed_sql .= " ORDER BY c.closed_at DESC LIMIT 20";
$cs = $pdo->prepare($closed_sql);
$cs->execute($closed_params);
$closed_rows = $cs->fetchAll(PDO::FETCH_ASSOC);

// 🔍 Debugging (يظهر فقط للأدمن)
if ($can_all && isset($_GET['debug'])) {
    echo "<pre style='background:#fff;padding:20px;border:2px solid #dc2626;margin:20px;'>";
    echo "=== DEBUG INFO ===\n";
    echo "User ID: " . current_user()['id'] . "\n";
    echo "User Dept ID: $my_dept_id\n";
    echo "Can All: " . ($can_all ? 'YES' : 'NO') . "\n";
    echo "Team Type: " . ($my_team_type ?? 'NULL') . "\n";
    echo "Where Clause: $where\n";
    echo "Params: " . implode(', ', $params) . "\n";
    echo "Total Rows: " . count($rows) . "\n";
    echo "\n=== ALL COMPLAINTS ===\n";
    $all = $pdo->query("SELECT id, request_number, dept_id, requested_by, status FROM complaints ORDER BY id DESC LIMIT 10")->fetchAll();
    print_r($all);
    echo "</pre>";
}

$STATUS_AR = ['open' => 'مفتوح', 'acknowledged' => 'تم الاستلام', 'in_progress' => 'جاري العمل', 'stalled' => 'متعثر', 'escalated' => 'مُصعَّد', 'resolved' => 'بانتظار تأكيد المستخدم'];
$PRI_COLOR = ['normal' => '#16a34a', 'urgent' => '#d97706', 'critical' => '#dc2626'];

$groups = ['new' => [], 'active' => [], 'escalated' => [], 'resolved' => []];
foreach ($rows as $r) {
    if ($r['status'] === 'open') $groups['new'][] = $r;
    elseif (in_array($r['status'], ['acknowledged', 'in_progress', 'stalled'])) $groups['active'][] = $r;
    elseif ($r['status'] === 'escalated') $groups['escalated'][] = $r;
    elseif ($r['status'] === 'resolved') $groups['resolved'][] = $r;
}

$closed_sql = "SELECT COUNT(*) FROM complaints WHERE closed_at >= CURDATE()";
$closed_params = [];
if (!$can_all) {
    if ($my_team_type) { $closed_sql .= " AND request_type=?"; $closed_params[] = $my_team_type; }
    else { $closed_sql .= " AND dept_id=?"; $closed_params[] = $my_dept_id; }
}
$closed_stmt = $pdo->prepare($closed_sql);
$closed_stmt->execute($closed_params);
$closed_today = (int) $closed_stmt->fetchColumn();

$stats = [
    'new' => count($groups['new']),
    'active' => count($groups['active']),
    'escalated' => count($groups['escalated']),
    'resolved' => count($groups['resolved']),
    'closed_today' => $closed_today,
    'total' => count($rows)
];

$most_urgent = $rows ? $rows[0] : null;
$page_title = 'البلاغات';
$active_nav = 'complaints.index';
$flash_msgs = get_flash();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root { --primary: #1565C0; --border: #e2e8f0; --bg: #f8fafc; --text-main: #0f172a; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Tajawal', sans-serif; background: var(--bg); color: var(--text-main); padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border-radius: 16px; border: 1px solid var(--border); overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .stat-top { padding: 14px 16px; color: white; font-size: 13px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .stat-body { padding: 16px; text-align: center; }
        .stat-num { font-size: 32px; font-weight: 900; color: #0f172a; }
        .board { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
        .board-col { background: white; border-radius: 16px; border: 1px solid var(--border); overflow: hidden; }
        .col-h { padding: 14px 16px; color: white; font-size: 14px; font-weight: 900; display: flex; justify-content: space-between; align-items: center; }
        .col-b { padding: 12px; min-height: 200px; }
        .cmp-card { display: block; background: #f8fafc; border: 1px solid var(--border); border-right: 4px solid; border-radius: 10px; padding: 12px; margin-bottom: 10px; text-decoration: none; transition: 0.2s; }
        .cmp-card:hover { background: #e2e8f0; transform: translateY(-2px); }
        .cmp-title { font-size: 13px; font-weight: 700; color: #0f172a; margin-bottom: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .cmp-meta { display: flex; justify-content: space-between; align-items: center; font-size: 11px; color: #64748b; margin-top: 8px; }
        .cmp-pulse { width: 8px; height: 8px; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .empty-col { text-align: center; padding: 40px 20px; color: #94a3b8; font-size: 13px; font-weight: 700; }
        .flash-msg { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; font-weight: 700; font-size: 13px; }
        .flash-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    </style>
</head>
<body class="app-layout">
    <?php include BASE_PATH . '/includes/sidebar.php'; ?>
    <div class="main-area">
        <?php include BASE_PATH . '/includes/topbar.php'; ?>
        <main class="page-content">
            <div class="container">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
                    <div>
                        <h2 style="font-size:21px;font-weight:900;color:#0f172a">
                            لوحة البلاغات
                            <?php if ($can_all) echo ' — الإدارة العامة'; elseif ($my_team_type) echo ' — فريق ' . e(['medical'=>'الصيانة الطبية','it'=>'تقنية المعلومات','general'=>'الصيانة العامة'][$my_team_type]??''); else echo ' — بلاغات قسمي'; ?>
                        </h2>
                        <div style="font-size:12.5px;color:#64748b;font-weight:600">تتحدّث مؤشرات الإلحاح تلقائياً كل دقيقة</div>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                    <a href="<?= BASE_URL ?>/complaints/my.php" style="background:#fff;color:#1565C0;border:1.5px solid #bfdbfe;padding:9px 16px;border-radius:10px;text-decoration:none;font-weight:800;font-size:13px">
                        <i class="fa-solid fa-list-check"></i> بلاغاتي
                    </a>
                    <a href="<?= BASE_URL ?>/complaints/create.php" style="background:linear-gradient(135deg,#1565C0,#2563eb);color:#fff;padding:9px 18px;border-radius:10px;text-decoration:none;font-weight:800;font-size:13px">
                        <i class="fa-solid fa-circle-plus"></i> بلاغ جديد
                    </a>
                    </div>
                </div>

                <?php foreach ($flash_msgs as $fm): ?>
                <div class="flash-msg flash-<?= e($fm['type']) ?>"><?= e($fm['message']) ?></div>
                <?php endforeach; ?>

                <!-- Debug Button (للأدمن فقط) -->
                <?php if ($can_all): ?>
                <div style="margin-bottom:16px">
                    <a href="<?= BASE_URL ?>/complaints/index.php?debug=1" style="background:#dc2626;color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:800">
                        <i class="fa-solid fa-bug"></i> Debug Mode
                    </a>
                </div>
                <?php endif; ?>

                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-top" style="background:#475569"><i class="fa-solid fa-list-check"></i> إجمالي نشطة</div>
                        <div class="stat-body"><div class="stat-num"><?= count($rows) ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-top" style="background:#10b981"><i class="fa-solid fa-circle-plus"></i> جديدة</div>
                        <div class="stat-body"><div class="stat-num"><?= $stats['new'] ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-top" style="background:#3b82f6"><i class="fa-solid fa-screwdriver-wrench"></i> قيد المعالجة</div>
                        <div class="stat-body"><div class="stat-num"><?= $stats['active'] ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-top" style="background:#dc2626"><i class="fa-solid fa-triangle-exclamation"></i> مُصعَّدة</div>
                        <div class="stat-body"><div class="stat-num"><?= $stats['escalated'] ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-top" style="background:#f59e0b"><i class="fa-solid fa-clock"></i> بانتظار التأكيد</div>
                        <div class="stat-body"><div class="stat-num"><?= $stats['resolved'] ?></div></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-top" style="background:#0f766e"><i class="fa-solid fa-lock"></i> أُغلقت اليوم</div>
                        <div class="stat-body"><div class="stat-num"><?= $stats['closed_today'] ?></div></div>
                    </div>
                </div>

                <div class="board">
                    <?php
                    $cols = [
                        ['key' => 'new', 'label' => 'جديدة', 'icon' => 'fa-circle-plus', 'color' => 'linear-gradient(135deg,#475569,#64748b)', 'empty' => 'لا توجد بلاغات جديدة'],
                        ['key' => 'active', 'label' => 'قيد المعالجة', 'icon' => 'fa-screwdriver-wrench', 'color' => 'linear-gradient(135deg,#1e3a8a,#2563eb)', 'empty' => 'لا يوجد عمل جارٍ حالياً'],
                        ['key' => 'escalated', 'label' => 'مُصعَّدة', 'icon' => 'fa-triangle-exclamation', 'color' => 'linear-gradient(135deg,#b91c1c,#dc2626)', 'empty' => 'لا توجد بلاغات مُصعَّدة'],
                        ['key' => 'resolved', 'label' => 'بانتظار التأكيد', 'icon' => 'fa-clipboard-check', 'color' => 'linear-gradient(135deg,#047857,#22c55e)', 'empty' => 'لا توجد بلاغات بانتظار تأكيد القسم']
                    ];
                    foreach ($cols as $col):
                    ?>
                    <div class="board-col">
                        <div class="col-h" style="background:<?= $col['color'] ?>">
                            <span><i class="fa-solid <?= $col['icon'] ?>"></i> <?= e($col['label']) ?></span>
                            <span style="background:rgba(255,255,255,.25);padding:2px 9px;border-radius:99px;font-size:11px" class="eng-num"><?= count($groups[$col['key']] ?? []) ?></span>
                        </div>
                        <div class="col-b">
                            <?php if (empty($groups[$col['key']])): ?>
                                <div class="empty-col"><?= e($col['empty']) ?></div>
                            <?php else: ?>
                                <?php foreach ($groups[$col['key']] as $r): 
                                    $pc = $PRI_COLOR[$r['priority']];
                                    // أولوية الشارة: آخر سبب للعودة هو المعروض
                                    // 1) رفض المُبلِّغ للحل (أحدث دائماً إن وُجد)
                                    // 2) إعادة من لجنة المتابعة (تاريخي، إن لم يكن هناك رفض أحدث)
                                    $rejected_by_requester = !empty($r['resolution_rejected_reason']) && $r['status'] !== 'escalated';
                                    $is_returned = !empty($r['returned_by_committee_at']) && $r['status'] !== 'escalated' && !$rejected_by_requester;
                                    $badge_color  = $is_returned ? '#7c3aed' : ($rejected_by_requester ? '#d97706' : null);
                                    $badge_bg     = $is_returned ? '#faf5ff' : ($rejected_by_requester ? '#fffbeb' : null);
                                    $badge_border = $is_returned ? '#ddd6fe' : ($rejected_by_requester ? '#fde68a' : null);
                                    $has_badge    = $is_returned || $rejected_by_requester;
                                ?>
                                <a href="<?= BASE_URL ?>/complaints/view.php?id=<?= $r['id'] ?>" 
                                   class="cmp-card" 
                                   style="border-right-color:<?= $has_badge ? $badge_color : $pc ?>;<?= $has_badge ? "border-right-width:5px;background:{$badge_bg};border-color:{$badge_border};" : '' ?>">
                                    <?php if ($has_badge): ?>
                                    <div style="display:flex;align-items:center;gap:5px;margin-bottom:6px">
                                        <span style="background:<?= $badge_color ?>;color:#fff;font-size:9.5px;font-weight:900;padding:2px 8px;border-radius:99px;display:inline-flex;align-items:center;gap:4px">
                                            <i class="fa-solid fa-rotate-left"></i>
                                            <?= $rejected_by_requester ? 'رُفِض الحل من القسم' : 'معاد من لجنة المتابعة' ?>
                                        </span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="cmp-title"><?= e(mb_substr($r['description'], 0, 50)) ?></div>
                                    <div style="font-size:10.5px;color:#94a3b8;margin-bottom:5px"><?= e($r['asset_desc'] ?? $r['location_description'] ?? '—') ?> · <?= e($r['dept_name'] ?? '') ?></div>
                                    <div class="cmp-meta">
                                        <span class="eng-num"><?= e($r['request_number']) ?></span>
                                        <?php if (in_array($r['status'], ['open', 'acknowledged', 'in_progress', 'stalled'])): ?>
                                        <span><span class="cmp-pulse" style="background:<?= $is_returned ? '#7c3aed' : $pc ?>" data-due="<?= e($r['escalation_due_at']) ?>"></span><span class="cd-live eng-num" data-due="<?= e($r['escalation_due_at']) ?>">—</span></span>
                                        <?php else: ?>
                                        <span class="eng-num"><?= e(date('H:i', strtotime($r['created_at']))) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>

        <?php if ($closed_rows): ?>
        <div style="position:sticky;bottom:0;z-index:100;background:#fff;border-top:2px solid #e2e8f0;box-shadow:0 -4px 18px rgba(0,0,0,.06)">
            <div style="background:linear-gradient(135deg,#1e293b,#334155);padding:10px 22px;color:#fff;font-size:13px;font-weight:900;display:flex;align-items:center;justify-content:space-between;cursor:pointer" onclick="toggleClosed()">
                <span><i class="fa-solid fa-circle-check" style="color:#22d3ee;margin-left:8px"></i> مُغلَقة حديثاً (آخر 7 أيام — <?= count($closed_rows) ?>)</span>
                <i class="fa-solid fa-chevron-up" id="closedChev" style="transition:.3s"></i>
            </div>
            <div id="closedTable" style="overflow-x:auto;max-height:240px;overflow-y:auto">
                <table style="width:100%;border-collapse:collapse;font-size:12.5px">
                    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;position:sticky;top:0">
                        <th style="padding:9px 16px;text-align:right;font-weight:900;color:#64748b">رقم البلاغ</th>
                        <th style="padding:9px 16px;text-align:right;font-weight:900;color:#64748b">الجهاز/القسم</th>
                        <th style="padding:9px 16px;text-align:center;font-weight:900;color:#64748b">الأولوية</th>
                        <th style="padding:9px 16px;text-align:center;font-weight:900;color:#64748b">الحالة</th>
                        <th style="padding:9px 16px;text-align:center;font-weight:900;color:#64748b">التقييم</th>
                        <th style="padding:9px 16px;text-align:center;font-weight:900;color:#64748b">تاريخ الإغلاق</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($closed_rows as $cr):
                        $cpr_color = $PRI_COLOR[$cr['priority']] ?? '#64748b';
                        $cpr_label = ['normal'=>'عادي','urgent'=>'عاجل','critical'=>'طارئ'][$cr['priority']] ?? '—';
                        $cst_label = ['closed'=>'مُغلَق','cancelled'=>'مُلغى','rejected'=>'مرفوض'][$cr['status']] ?? '—';
                        $cst_color = ['closed'=>'#16a34a','cancelled'=>'#64748b','rejected'=>'#dc2626'][$cr['status']] ?? '#64748b';
                        $cst_bg    = ['closed'=>'#dcfce7','cancelled'=>'#f1f5f9','rejected'=>'#fee2e2'][$cr['status']] ?? '#f1f5f9';
                        $rowBg     = $cr['status']==='closed' ? '#f0fdf4' : ($cr['status']==='rejected' ? '#fef2f2' : '#fff');
                    ?>
                    <tr style="border-bottom:1px solid #f1f5f9;background:<?= $rowBg ?>">
                        <td style="padding:9px 16px"><a href="<?= BASE_URL ?>/complaints/view.php?id=<?= $cr['id'] ?>" style="font-weight:800;color:#2563eb;font-family:'Inter',sans-serif;text-decoration:none"><?= e($cr['request_number']) ?></a></td>
                        <td style="padding:9px 16px;color:#334155;font-weight:700"><?= e(mb_substr($cr['asset_desc'] ?? $cr['dept_name'] ?? '—', 0, 40)) ?></td>
                        <td style="padding:9px 16px;text-align:center"><span style="background:<?= $cpr_color ?>22;color:<?= $cpr_color ?>;font-size:11px;font-weight:900;padding:3px 10px;border-radius:99px"><?= e($cpr_label) ?></span></td>
                        <td style="padding:9px 16px;text-align:center"><span style="background:<?= $cst_bg ?>;color:<?= $cst_color ?>;font-size:11px;font-weight:900;padding:3px 10px;border-radius:99px"><?= e($cst_label) ?></span></td>
                        <td style="padding:9px 16px;text-align:center;color:#fbbf24">
                            <?php if ($cr['service_rating']): ?>
                            <?= str_repeat('★', (int)$cr['service_rating']) ?><span style="color:#e2e8f0"><?= str_repeat('★', 5-(int)$cr['service_rating']) ?></span>
                            <?php else: ?><span style="color:#cbd5e1">—</span><?php endif; ?>
                        </td>
                        <td style="padding:9px 16px;text-align:center;color:#64748b;font-family:'Inter',sans-serif;font-weight:700"><?= e(date('d/m H:i', strtotime($cr['closed_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- end main-area -->

    <script>
    function tickLive() {
        document.querySelectorAll('.cd-live').forEach(el => {
            const due = el.dataset.due;
            if (!due) return;
            const mins = (new Date(due.replace(' ', 'T')) - new Date()) / 60000;
            const urgency = Math.max(0, Math.min(1, 1 - (mins / 120)));
            el.style.opacity = 0.5 + urgency * 0.5;
            el.style.transform = 'scale(' + (0.9 + urgency * 0.6) + ')';
        });
    }
    tickLive();
    setInterval(tickLive, 20000);
    </script>

<script>
function toggleClosed() {
    const t = document.getElementById('closedTable');
    const c = document.getElementById('closedChev');
    if (t.style.display === 'none') { t.style.display='block'; c.style.transform='rotate(0deg)'; }
    else { t.style.display='none'; c.style.transform='rotate(180deg)'; }
}
</script>
</body>
</html>