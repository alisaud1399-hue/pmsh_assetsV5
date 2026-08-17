<?php
/**
* reports/complaints/overview.php — مركز دراسة وتحليل البلاغات (Diamond Edition)
* ─────────────────────────────────────────────────────────────────
* • محاور: أداء • مقاولين • موثوقية • أقسام • زمن • مالي
* • مقارنة فترتين + تنبيهات ذكاء موسعة + تصدير ماسي
* • يكتشف أعمدة complaints تلقائياً — لا ينكسر أبداً
* • نظام التقارير المحفوظة الموحد
*/
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/saved_reports.php';
page_guard('reports.complaints.overview');

if (isset($_GET['apply_saved'])) {
    sr_apply_saved($pdo, (int)$_GET['apply_saved'], (int)current_user()['id']);
}

$rtl = is_rtl();
$can_see_all = can_see_all();
$can_export  = can('reports.complaints.overview', 'export');
$hospital = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$cluster  = get_setting('health_cluster', 'تجمع الباحة الصحي');
$logo_fs_path = BASE_PATH . '/logo.png';
$logo_src = file_exists($logo_fs_path) ? BASE_URL . '/logo.png?v=' . filemtime($logo_fs_path) : '';

$STATUS_AR = ['open'=>'مفتوحة','acknowledged'=>'مستلمة','in_progress'=>'قيد المعالجة','stalled'=>'متوقفة','escalated'=>'متصاعدة','resolved'=>'محلولة','closed'=>'مغلقة','cancelled'=>'ملغاة','rejected'=>'مرفوضة'];
$STATUS_COLORS = [
    'open'=>['#1565C0','#dbeafe'],
    'acknowledged'=>['#0ea5e9','#e0f2fe'],
    'in_progress'=>['#7c3aed','#f5f3ff'],
    'stalled'=>['#d97706','#fef3c7'],
    'escalated'=>['#dc2626','#fee2e2'],
    'resolved'=>['#16a34a','#dcfce7'],
    'closed'=>['#475569','#f1f5f9'],
    'cancelled'=>['#94a3b8','#f8fafc'],
    'rejected'=>['#7f1d1d','#fef2f2'],
];
$PRIORITY_AR = ['critical'=>'حرجة','urgent'=>'عاجلة','normal'=>'عادية'];
$PRIORITY_COLORS = ['critical'=>['#dc2626','#fee2e2'],'urgent'=>['#f59e0b','#fef3c7'],'normal'=>['#1565C0','#dbeafe']];
$TYPE_AR = ['medical'=>'طبية','it'=>'تقنية','general'=>'عامة'];
$CLOSED = ['closed','resolved'];

/* ═══ اكتشاف أعمدة complaints تلقائياً ═══ */
$comp_cols = array_column($pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='complaints'")->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME');
$HAS = function(string $c) use ($comp_cols) { return in_array($c, $comp_cols, true); };
$has_asset = $HAS('asset_id');
$has_dept = $HAS('department_id');
$has_contractor = $HAS('contractor_id');
$title_col = $HAS('title') ? 'title' : ($HAS('subject') ? 'subject' : ($HAS('description') ? 'description' : null));

/* ═══ الفلاتر ═══ */
$view_mode = $_GET['view'] ?? 'executive';
function valid_date(string $v): string {
    if ($v === '') return '';
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : '';
}
$f = [
    'q' => trim($_GET['q'] ?? ''),
    'type' => trim($_GET['type'] ?? ''),
    'priority' => trim($_GET['priority'] ?? ''),
    'status' => trim($_GET['status'] ?? ''),
    'dept' => (int)($_GET['dept'] ?? 0),
    'contractor' => (int)($_GET['contractor'] ?? 0),
    'from' => valid_date(trim($_GET['from'] ?? '')),
    'to' => valid_date(trim($_GET['to'] ?? '')),
];
if (!in_array($f['type'], array_keys($TYPE_AR), true)) $f['type'] = '';
if (!in_array($f['priority'], array_keys($PRIORITY_AR), true)) $f['priority'] = '';
if (!in_array($f['status'], array_keys($STATUS_AR), true)) $f['status'] = '';
$has_filters = array_filter($f) !== [];

$print_mode = isset($_GET['print']) && $can_export;
$print_charts_mode = isset($_GET['print_charts']) && $can_export;
$excel_mode = isset($_GET['excel']) && $can_export;

/* ═══ بناء الشروط ═══ */
$where = ["1=1"];
$params = [];
if ($f['type']) { $where[] = 'c.request_type = :ftype'; $params['ftype'] = $f['type']; }
if ($f['priority']) { $where[] = 'c.priority = :fprio'; $params['fprio'] = $f['priority']; }
if ($f['status']) { $where[] = 'c.status = :fst'; $params['fst'] = $f['status']; }
if ($has_dept && $f['dept']) { $where[] = 'c.department_id = :fdept'; $params['fdept'] = $f['dept']; }
if ($has_contractor && $f['contractor']) { $where[] = 'c.contractor_id = :fcon'; $params['fcon'] = $f['contractor']; }
if ($f['from']) { $where[] = 'DATE(c.created_at) >= :ffrom'; $params['ffrom'] = $f['from']; }
if ($f['to']) { $where[] = 'DATE(c.created_at) <= :fto'; $params['fto'] = $f['to']; }
if ($f['q'] !== '') {
    $q = '%' . $f['q'] . '%';
    $qcols = ['c.id'];
    if ($title_col) $qcols[] = "c.$title_col";
    if ($has_asset) { $qcols[] = 'a.tag_number'; $qcols[] = 'a.serial_number'; $qcols[] = 'a.description'; }
    $qcols[] = 'u.full_name';
    $qp = [];
    foreach ($qcols as $qi => $qc) {
        $qp[] = "$qc LIKE :q$qi";
        $params["q$qi"] = $q;
    }
    $where[] = '(' . implode(' OR ', $qp) . ')';
}

$select = "c.id, c.status, c.priority, c.request_type, c.created_at, c.acknowledged_at, c.resolved_at, c.closed_at, c.sla_breach_detected_at, c.acknowledged_by";
if ($title_col) $select .= ", c.$title_col AS c_title";
if ($has_asset) $select .= ", c.asset_id, a.tag_number, a.description AS asset_desc, a.manufacturer_name, a.model_number, a.loc_building, a.loc_room, a.custodian_name, a.criticality_class AS asset_crit, a.warranty_expiry AS asset_warranty, a.total_maintenance_cost";
if ($has_dept) $select .= ", c.department_id, d.name AS dept_name";
if ($has_contractor) $select .= ", c.contractor_id, ct.name AS contractor_name";
$select .= ", u.full_name AS assignee_name";

$joins = " LEFT JOIN users u ON u.id = c.acknowledged_by";
if ($has_asset) $joins .= " LEFT JOIN assets a ON a.id = c.asset_id";
if ($has_dept) $joins .= " LEFT JOIN departments d ON d.id = c.department_id";
if ($has_contractor) $joins .= " LEFT JOIN contractors ct ON ct.id = c.contractor_id";

$row_cap = ($print_mode || $print_charts_mode || $excel_mode) ? 10000 : 20000;
$sql = "SELECT $select FROM complaints c $joins WHERE " . implode(' AND ', $where) . " ORDER BY c.created_at DESC LIMIT $row_cap";
$st = $pdo->prepare($sql);
$st->execute($params);
$results = $st->fetchAll(PDO::FETCH_ASSOC);

/* قوائم الفلاتر */
$depts = $has_dept ? $pdo->query("SELECT DISTINCT d.id, d.name FROM departments d INNER JOIN complaints c ON c.department_id = d.id ORDER BY d.name")->fetchAll(PDO::FETCH_ASSOC) : [];
$contractors = $has_contractor ? $pdo->query("SELECT DISTINCT ct.id, ct.name FROM contractors ct INNER JOIN complaints c ON c.contractor_id = ct.id ORDER BY ct.name")->fetchAll(PDO::FETCH_ASSOC) : [];

/* ═══ التجميع الشامل (PHP) ═══ */
function hrs_between($a, $b) {
    if (!$a || !$b) return null;
    $d = (strtotime($b) - strtotime($a)) / 3600;
    return $d >= 0 ? $d : null;
}

$now = time();
$total = count($results);
$open = 0; $closed = 0; $critical_open = 0; $sla_breach = 0; $escalated = 0; $stalled = 0;
$status_cnt = [];
$month_cnt = [];
$res_by_prio = [];
$res_hours = [];
$first_resp = [];
$aging = ['0-7' => 0, '8-30' => 0, '31-90' => 0, '90+' => 0];
$sla_month = [];
$weekday = [];
$hours = [];
$asset_agg = [];
$mfr_cnt = [];
$dept_cnt = [];
$dept_crit = [];
$con_perf = [];
$downtime_med = 0;

foreach ($results as $r) {
    $is_closed = in_array($r['status'], $CLOSED, true);
    if ($is_closed) $closed++; else $open++;
    if ($r['priority'] === 'critical' && !$is_closed) $critical_open++;
    if (!empty($r['sla_breach_detected_at'])) $sla_breach++;
    if ($r['status'] === 'escalated') $escalated++;
    if ($r['status'] === 'stalled') $stalled++;

    $status_cnt[$r['status']] = ($status_cnt[$r['status']] ?? 0) + 1;

    if (!empty($r['created_at'])) {
        $ym = substr($r['created_at'], 0, 7);
        $month_cnt[$ym] = ($month_cnt[$ym] ?? 0) + 1;
        $sla_month[$ym]['t'] = ($sla_month[$ym]['t'] ?? 0) + 1;
        if (!empty($r['sla_breach_detected_at'])) $sla_month[$ym]['b'] = ($sla_month[$ym]['b'] ?? 0) + 1;
        $ts = strtotime($r['created_at']);
        $wd = (int)date('N', $ts);
        $weekday[$wd] = ($weekday[$wd] ?? 0) + 1;
        $hr = (int)date('G', $ts);
        $hours[$hr] = ($hours[$hr] ?? 0) + 1;
    }

    $rh = hrs_between($r['created_at'], $r['resolved_at'] ?: $r['closed_at']);
    if ($rh !== null) {
        $res_hours[] = $rh;
        $res_by_prio[$r['priority']][] = $rh;
    }
    $fr = hrs_between($r['created_at'], $r['acknowledged_at']);
    if ($fr !== null) $first_resp[] = $fr;

    if (!$is_closed && !empty($r['created_at'])) {
        $days = floor(($now - strtotime($r['created_at'])) / 86400);
        if ($days <= 7) $aging['0-7']++;
        elseif ($days <= 30) $aging['8-30']++;
        elseif ($days <= 90) $aging['31-90']++;
        else $aging['90+']++;
    }

    if ($has_asset && !empty($r['asset_id'])) {
        $k = $r['asset_id'];
        if (!isset($asset_agg[$k])) {
            $asset_agg[$k] = [
                'id' => $k,
                'tag' => $r['tag_number'] ?? '—',
                'desc' => $r['asset_desc'] ?? '—',
                'dept' => $r['dept_name'] ?? '',
                'mfr' => $r['manufacturer_name'] ?? '—',
                'cnt' => 0,
                'last90' => 0,
                'prior90' => 0,
                'maint' => (float)($r['total_maintenance_cost'] ?? 0),
                'warranty' => $r['asset_warranty'] ?? null,
                'crit' => $r['asset_crit'] ?? null,
                'last' => null,
            ];
        }
        $asset_agg[$k]['cnt']++;
        if (!empty($r['created_at'])) {
            $ago = ($now - strtotime($r['created_at'])) / 86400;
            if ($ago <= 90) $asset_agg[$k]['last90']++;
            elseif ($ago <= 180) $asset_agg[$k]['prior90']++;
            if (!$asset_agg[$k]['last'] || $r['created_at'] > $asset_agg[$k]['last']) {
                $asset_agg[$k]['last'] = $r['created_at'];
            }
        }
        if ($r['priority'] === 'critical' && $r['request_type'] === 'medical' && $rh !== null) {
            $downtime_med += $rh;
        }
    }

    if (!empty($r['manufacturer_name'])) {
        $mfr_cnt[$r['manufacturer_name']] = ($mfr_cnt[$r['manufacturer_name']] ?? 0) + 1;
    }
    if (!empty($r['dept_name'])) {
        $dept_cnt[$r['dept_name']] = ($dept_cnt[$r['dept_name']] ?? 0) + 1;
        if ($r['priority'] === 'critical' && !$is_closed) {
            $dept_crit[$r['dept_name']] = ($dept_crit[$r['dept_name']] ?? 0) + 1;
        }
    }

    $pk = $has_contractor ? ($r['contractor_name'] ?? null) : ($r['assignee_name'] ?? null);
    if ($pk) {
        if (!isset($con_perf[$pk])) {
            $con_perf[$pk] = ['name' => $pk, 'total' => 0, 'resolved' => 0, 'sla' => 0, 'esc' => 0, 'hours' => []];
        }
        $con_perf[$pk]['total']++;
        if ($is_closed) $con_perf[$pk]['resolved']++;
        if (!empty($r['sla_breach_detected_at'])) $con_perf[$pk]['sla']++;
        if ($r['status'] === 'escalated') $con_perf[$pk]['esc']++;
        if ($rh !== null) $con_perf[$pk]['hours'][] = $rh;
    }
}

$avg_res = $res_hours ? round(array_sum($res_hours) / count($res_hours), 1) : 0;
$avg_first = $first_resp ? round(array_sum($first_resp) / count($first_resp), 1) : 0;
$sla_rate = $total > 0 ? round((1 - $sla_breach / $total) * 100) : 100;

$avg_res_prio = [];
foreach ($res_by_prio as $p => $arr) {
    $avg_res_prio[$p] = round(array_sum($arr) / count($arr), 1);
}

ksort($month_cnt);
ksort($sla_month);
$sla_series = [];
$sla_series = [];
foreach ($sla_month as $ym => $v) {
    $b = $v['b'] ?? 0;   // شهر بدون تجاوزات = صفر (بدل تحذير Undefined array key)
    $sla_series[$ym] = $v['t'] > 0 ? round(($b / $v['t']) * 100) : 0;
}

/* أعلى الأصول أعطالاً + المتسارعة */
$top_assets = array_values($asset_agg);
usort($top_assets, function($a, $b) { return $b['cnt'] <=> $a['cnt']; });
$accel_assets = array_values(array_filter($asset_agg, function($a) {
    return $a['last90'] >= 2 && $a['last90'] > $a['prior90'];
}));
usort($accel_assets, function($a, $b) { return $b['last90'] <=> $a['last90']; });
$top_assets = array_slice($top_assets, 0, 8);
$accel_assets = array_slice($accel_assets, 0, 6);

arsort($mfr_cnt);
$mfr_top = array_slice($mfr_cnt, 0, 6, true);
arsort($dept_cnt);
$dept_top = array_slice($dept_cnt, 0, 6, true);
arsort($dept_crit);
$dept_crit_top = array_slice($dept_crit, 0, 5, true);

/* عدد أصول كل قسم (للعدالة) */
$dept_assets_cnt = [];
if ($has_dept) {
    foreach ($pdo->query("SELECT custodian_dept_id, COUNT(*) c FROM assets WHERE status NOT IN ('disposed','returned_to_supplier') AND custodian_dept_id IS NOT NULL GROUP BY custodian_dept_id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dept_assets_cnt[$r['custodian_dept_id']] = (int)$r['c'];
    }
}
$dept_names_id = [];
if ($has_dept) {
    foreach ($depts as $d) {
        $dept_names_id[$d['name']] = (int)$d['id'];
    }
}
$fairness = [];
foreach ($dept_cnt as $name => $cnt) {
    $aid = $dept_names_id[$name] ?? null;
    $ac = $aid ? ($dept_assets_cnt[$aid] ?? 0) : 0;
    $fairness[] = ['name' => $name, 'complaints' => $cnt, 'assets' => $ac, 'rate' => $ac > 0 ? round($cnt / $ac * 100, 1) : null];
}
usort($fairness, function($a, $b) { return ($b['rate'] ?? -1) <=> ($a['rate'] ?? -1); });
$fairness = array_slice($fairness, 0, 6);

/* بطاقة أداء المقاولين (درجة من 100) */
foreach ($con_perf as &$cp) {
    $rr = $cp['total'] > 0 ? $cp['resolved'] / $cp['total'] * 100 : 0;
    $sc_ = $cp['total'] > 0 ? (1 - $cp['sla'] / $cp['total']) * 100 : 100;
    $ah = $cp['hours'] ? array_sum($cp['hours']) / count($cp['hours']) : null;
    $speed = $ah === null ? 70 : ($ah <= 48 ? 100 : max(0, 100 - ($ah - 48) / 24 * 10));
    $cp['avg_h'] = $ah !== null ? round($ah, 1) : null;
    $cp['rate'] = round($rr);
    $cp['sla_c'] = round($sc_);
    $cp['score'] = round(0.5 * $rr + 0.3 * $sc_ + 0.2 * $speed);
}
unset($cp);
$con_perf = array_values($con_perf);
usort($con_perf, function($a, $b) { return $b['score'] <=> $a['score']; });
$con_leader = array_slice($con_perf, 0, 6);

/* المالي: أصول خارج الضمان عالية الصيانة */
$fin_assets = array_values(array_filter($asset_agg, function($a) {
    return $a['maint'] > 0 && ($a['warranty'] === null || $a['warranty'] < date('Y-m-d'));
}));
usort($fin_assets, function($a, $b) { return $b['maint'] <=> $a['maint']; });
$fin_assets = array_slice($fin_assets, 0, 6);
$warranty_complaints = count(array_filter($asset_agg, function($a) {
    return $a['warranty'] && $a['warranty'] >= date('Y-m-d');
}));
$open_critA = count(array_filter($asset_agg, function($a) { return $a['crit'] === 'A'; }));

/* ═══ مقارنة فترتين ═══ */
$where_nd = [];
$params_nd = [];
foreach ($where as $w) {
    if (strpos($w, ':ffrom') === false && strpos($w, ':fto') === false) {
        $where_nd[] = $w;
    }
}
$params_nd = $params;
unset($params_nd['ffrom'], $params_nd['fto']);

$cur_from = $f['from'] ?: date('Y-m-d', strtotime('-30 days'));
$cur_to = $f['to'] ?: date('Y-m-d');
$len = max(1, round((strtotime($cur_to) - strtotime($cur_from)) / 86400) + 1);
$prev_to = date('Y-m-d', strtotime($cur_from . ' -1 day'));
$prev_from = date('Y-m-d', strtotime($prev_to . ' -' . ($len - 1) . ' days'));

function period_stats2(PDO $pdo, array $wnd, array $pnd, string $from, string $to): array {
    $sql = "SELECT COUNT(*) t, SUM(status IN ('closed','resolved')) r, SUM(sla_breach_detected_at IS NOT NULL) b FROM complaints c WHERE " . implode(' AND ', $wnd) . " AND DATE(c.created_at) BETWEEN :pfrom AND :pto";
    $p = $pnd;
    $p['pfrom'] = $from;
    $p['pto'] = $to;
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['t' => (int)$r['t'], 'r' => (int)$r['r'], 'b' => (int)$r['b']];
}

$cur = period_stats2($pdo, $where_nd, $params_nd, $cur_from, $cur_to);
$prev = period_stats2($pdo, $where_nd, $params_nd, $prev_from, $prev_to);

$delta = function($a, $b) {
    if ($b <= 0) return $a > 0 ? 100 : 0;
    return round(($a - $b) / $b * 100);
};
$d_total = $delta($cur['t'], $prev['t']);
$d_res = $delta($cur['r'], $prev['r']);
$d_breach = $delta($cur['b'], $prev['b']);

/* ═══ تنبيهات الذكاء الموسعة ═══ */
$ai = [];
if ($sla_rate < 80 && $total > 0) $ai[] = "⏱️ التزام SLA منخفض ($sla_rate%) — راجع توزيع الأحمال";
if ($critical_open > 0) $ai[] = "⚡ $critical_open بلاغ حرج مفتوح يحتاج تدخلاً فورياً";
if ($aging['90+'] > 0) $ai[] = "🕐 {$aging['90+']} بلاغ متجاوز 90 يوماً — تصعيد إلزامي";
if (count($accel_assets) > 0) $ai[] = "🔁 أجهزة بتسارع أعطال (" . $accel_assets[0]['tag'] . ") — يُنصح بفحص جذري/استبدال";
if (count($fin_assets) > 0) $ai[] = "💰 أصول خارج الضمان بكلفة صيانة عالية — دراسة استبدالها أوفر";
if ($d_total > 20) $ai[] = "📈 ارتفاع البلاغات $d_total% عن الفترة السابقة — تحقق من سبب الزيادة";
$ai_class = empty($ai) ? 'ai-success' : (count($ai) >= 3 ? 'ai-danger' : 'ai-warning');
$ai_icon = empty($ai) ? 'fa-check-circle' : (count($ai) >= 3 ? 'fa-triangle-exclamation' : 'fa-bell');
$ai_msg = empty($ai) ? '✨ مؤشرات البلاغات ضمن النطاق الصحي.' : implode(' | ', $ai);

$title_parts = [];
if ($f['type']) $title_parts[] = $TYPE_AR[$f['type']];
if ($f['status']) $title_parts[] = $STATUS_AR[$f['status']];
if ($f['priority']) $title_parts[] = $PRIORITY_AR[$f['priority']];
$report_title = 'تقرير البلاغات' . ($title_parts ? ' — ' . implode(' / ', $title_parts) : ' — شامل');

/* ═══ 1. Excel غني ═══ */
if ($excel_mode) {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename=MOH_Complaints_Analytics_' . date('Ymd_Hi') . '.xls');
    echo "\xEF\xBB\xBF";
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
<head><meta http-equiv="Content-type" content="text/html;charset=utf-8"/>
<style>table{border-collapse:collapse;font-family:sans-serif;font-size:12px}th{background:#0f172a;color:#fff;font-weight:bold;border:1px solid #cbd5e1;padding:8px;text-align:center}td{border:1px solid #cbd5e1;padding:6px;text-align:center;vertical-align:middle}</style></head>
<body dir="rtl"><table><thead>
<tr><th colspan="18" style="font-size:16px;background:#7c2d12;padding:14px">سجل البلاغات التحليلي - <?= e($report_title) ?></th></tr>
<tr><th>ID</th><th>البلاغ</th><th>النوع</th><th>الأولوية</th><th>الحالة</th><th>القسم</th><th>Tag</th><th>الجهاز</th><th>المصنع</th><th>الموديل</th><th>الموقع</th><th>العهدة</th><th>المقاول/الفني</th><th>البلاغ</th><th>الحل</th><th>ساعات الحل</th><th>أول رد</th><th>SLA</th></tr></thead><tbody>
<?php foreach ($results as $r): $rh = hrs_between($r['created_at'], $r['resolved_at'] ?: $r['closed_at']); $fr = hrs_between($r['created_at'], $r['acknowledged_at']); ?>
<tr><td><?= (int)$r['id'] ?></td><td><?= e($r['c_title'] ?? '') ?></td><td><?= e($TYPE_AR[$r['request_type']] ?? '') ?></td><td><?= e($PRIORITY_AR[$r['priority']] ?? '') ?></td><td><?= e($STATUS_AR[$r['status']] ?? '') ?></td>
<td><?= e($r['dept_name'] ?? '') ?></td><td><?= e($r['tag_number'] ?? '') ?></td><td><?= e($r['asset_desc'] ?? '') ?></td><td><?= e($r['manufacturer_name'] ?? '') ?></td><td><?= e($r['model_number'] ?? '') ?></td><td><?= e(($r['loc_building']??'').' / '.($r['loc_room']??'')) ?></td><td><?= e($r['custodian_name'] ?? '') ?></td>
<td><?= e($r['contractor_name'] ?? $r['assignee_name'] ?? '') ?></td><td><?= e($r['created_at']) ?></td><td><?= e($r['resolved_at'] ?: $r['closed_at'] ?: '') ?></td><td><?= $rh!==null?round($rh,1):'' ?></td><td><?= $fr!==null?round($fr,1):'' ?></td><td><?= !empty($r['sla_breach_detected_at'])?'تجاوز':'ملتزم' ?></td></tr>
<?php endforeach; ?>
</tbody></table></body></html>
<?php exit;
}

/* ═══ 2. PDF رسمي ═══ */
if ($print_mode) {
    $disp = array_slice($results, 0, 1000);
    $ROWS = 10;
    $pages = array_chunk($disp, $ROWS, true);
    $tp = max(1, count($pages));
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>الوثيقة الرسمية - <?= e($report_title) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:landscape;margin:12mm 10mm}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;color:#1e293b;margin:0}
.print-page{page-break-after:always}.print-page:last-child{page-break-after:auto}
.print-header{background:linear-gradient(135deg,#f8fafc,#ffedd5);border:1px solid #cbd5e1;border-radius:10px;padding:12px 18px;display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}
.ph-right{display:flex;align-items:center;gap:12px;border-left:1px solid #cbd5e1;padding-left:18px}.ph-h1{font-size:16px;font-weight:800}.ph-h2{font-size:11px;color:#475569}
.ph-logo{height:46px;object-fit:contain}.ph-center{flex:1;text-align:center}.ph-title{font-size:16px;font-weight:800;color:#c2410c}.ph-left{text-align:left;font-size:10px;color:#475569}
.ph-pagebadge{background:#c2410c;color:#fff;padding:3px 10px;border-radius:4px;font-size:9px;font-weight:800;display:inline-block;margin-bottom:4px}
table.data-table{width:100%;border-collapse:collapse;font-size:9.5px;border:1.5px solid #cbd5e1}
table.data-table th{background:#f1f5f9;padding:7px;text-align:right;font-weight:900;border:1px solid #cbd5e1}
table.data-table td{padding:5px 7px;border:1px solid #e2e8f0;text-align:right;vertical-align:middle}
table.data-table tbody tr:nth-child(even) td{background:#fafaf9}
.p-badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:8.5px;font-weight:800}
.print-footer{display:flex;justify-content:space-around;padding:12px 10px 4px;border-top:1.5px solid #cbd5e1}
.sign-box{text-align:center;width:30%}.sign-box .title{font-size:11px;font-weight:800;margin-bottom:20px}.sign-box .line{border-bottom:1px dashed #94a3b8;margin:0 15px 6px}.sign-box .hint{font-size:9px;color:#64748b}
</style></head><body onload="setTimeout(()=>window.print(),500)">
<?php foreach ($pages as $pi => $pr): $pn = $pi + 1; ?>
<div class="print-page"><table class="data-table"><thead>
<tr><th colspan="8" style="padding:0;border:none;background:none"><div class="print-header">
<div class="ph-right"><?php if($logo_src): ?><img src="<?= e($logo_src) ?>" class="ph-logo"><?php endif; ?><div><div class="ph-h1"><?= e($hospital) ?></div><div class="ph-h2"><?= e($cluster) ?></div></div></div>
<div class="ph-center"><div class="ph-title">سجل البلاغات المعتمد — <?= e($report_title) ?></div></div>
<div class="ph-left"><div class="ph-pagebadge">صفحة <?= $pn ?> من <?= $tp ?></div><div>الإصدار: <strong><?= date('Y-m-d H:i') ?></strong> — السجلات: <strong><?= $total ?></strong></div></div>
</div></th></tr>
<tr><th>#</th><th>البلاغ</th><th>الأصل</th><th>القسم/الموقع</th><th>أولوية/حالة</th><th>المعالجة</th><th>التواريخ</th><th>الأداء</th></tr></thead><tbody>
<?php foreach ($pr as $i => $r): $sc=$STATUS_COLORS[$r['status']]??['#64748b','#f1f5f9']; $pc=$PRIORITY_COLORS[$r['priority']]??['#64748b','#f1f5f9']; $rh=hrs_between($r['created_at'],$r['resolved_at']?:$r['closed_at']); ?>
<tr><td style="text-align:center"><?= $i+1 ?></td>
<td><strong>#<?= (int)$r['id'] ?></strong><br><?= e(mb_strimwidth($r['c_title']??'—',0,40,'...')) ?></td>
<td><?= e($r['tag_number']?:'—') ?><br><small><?= e(mb_strimwidth($r['asset_desc']??'',0,25,'...')) ?></small></td>
<td><?= e($r['dept_name']?:'—') ?><br><small><?= e(($r['loc_building']??'').' / '.($r['loc_room']??'')) ?></small></td>
<td><span class="p-badge" style="background:<?= $pc[1] ?>;color:<?= $pc[0] ?>"><?= e($PRIORITY_AR[$r['priority']]??'') ?></span><br><span class="p-badge" style="background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><?= e($STATUS_AR[$r['status']]??'') ?></span></td>
<td><?= e($r['contractor_name']??$r['assignee_name']?:'—') ?></td>
<td><small><?= e($r['created_at']?date('m-d H:i',strtotime($r['created_at'])):'—') ?></small></td>
<td><?= $rh!==null?round($rh,1).' س':'—' ?> <small style="color:<?= !empty($r['sla_breach_detected_at'])?'#dc2626':'#16a34a' ?>"><?= !empty($r['sla_breach_detected_at'])?'تجاوز':'ملتزم' ?></small></td></tr>
<?php endforeach; ?>
</tbody><tfoot><tr><td colspan="8" style="border:none;padding:0"><div class="print-footer">
<div class="sign-box"><div class="title">مُعِد التقرير</div><div class="line"></div><div class="hint">التوقيع</div></div>
<div class="sign-box"><div class="title">مشرف الصيانة</div><div class="line"></div><div class="hint">المراجعة</div></div>
<div class="sign-box"><div class="title">مدير إدارة الأصول</div><div class="line"></div><div class="hint">الاعتماد</div></div>
</div></td></tr></tfoot></table></div>
<?php endforeach; ?>
</body></html>
<?php exit;
}

/* ═══ 3. لوحة A4 ═══ */
if ($print_charts_mode) {
?>
<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>لوحة مؤشرات البلاغات</title>
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800;900&display=swap');
@page{size:A4 landscape;margin:0}*{box-sizing:border-box;-webkit-print-color-adjust:exact!important}
body{font-family:'Tajawal',sans-serif;margin:0}
.a4{width:297mm;height:209mm;padding:10mm;margin:0 auto;display:flex;flex-direction:column;overflow:hidden}
.hd{background:#7c2d12;color:#fff;border-radius:10px;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.krow{display:flex;gap:12px;margin-bottom:12px}.kbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;text-align:center;background:#f8fafc}
.kval{font-size:22px;font-weight:900}.klbl{font-size:11px;font-weight:800;color:#64748b}
.cwrap{display:flex;flex-direction:column;gap:12px;flex:1;min-height:0}.crow{display:flex;gap:12px;flex:1;min-height:0}
.cbox{flex:1;border:1.5px solid #e2e8f0;border-radius:10px;padding:8px;display:flex;flex-direction:column}.ct{font-size:12px;font-weight:900;text-align:center;margin-bottom:4px}.ca{flex:1;min-height:0}
.ft{text-align:center;font-size:10px;color:#94a3b8;margin-top:8px;border-top:1px dashed #cbd5e1;padding-top:4px}
</style></head><body onload="setTimeout(()=>window.print(),1500)">
<div class="a4">
<div class="hd"><div style="font-size:18px;font-weight:900"><?= e($hospital) ?></div><div style="font-size:16px;font-weight:900;color:#fdba74"><?= e($report_title) ?></div><div style="font-size:11px"><?= date('Y-m-d') ?></div></div>
<div class="krow">
<div class="kbox"><div class="kval"><?= number_format($total) ?></div><div class="klbl">الإجمالي</div></div>
<div class="kbox"><div class="kval" style="color:#dc2626"><?= number_format($open) ?></div><div class="klbl">مفتوحة</div></div>
<div class="kbox"><div class="kval" style="color:<?= $sla_rate>=80?'#16a34a':'#dc2626' ?>"><?= $sla_rate ?>%</div><div class="klbl">التزام SLA</div></div>
<div class="kbox"><div class="kval" style="color:#d97706"><?= $avg_res ?>h</div><div class="klbl">متوسط الحل</div></div>
<div class="kbox"><div class="kval" style="color:#dc2626"><?= number_format($critical_open) ?></div><div class="klbl">حرجة مفتوحة</div></div>
</div>
<div class="cwrap">
<div class="crow">
<div class="cbox" style="flex:1.2"><div class="ct">توزيع الحالات</div><div class="ca" id="pSt"></div></div>
<div class="cbox"><div class="ct">اتجاه تجاوز SLA %</div><div class="ca" id="pSla"></div></div>
</div>
<div class="crow">
<div class="cbox"><div class="ct">أكثر الأجهزة بلاغات</div><div class="ca" id="pTop"></div></div>
<div class="cbox" style="flex:1.2"><div class="ct">الاتجاه الشهري</div><div class="ca" id="pMo"></div></div>
</div>
</div>
<div class="ft">وثيقة تحليلية | <?= e(current_user()['name'] ?? 'النظام') ?></div>
</div>
<script>
document.addEventListener("DOMContentLoaded",function(){
<?php if(!empty($status_cnt)): ?>new ApexCharts(document.querySelector("#pSt"),{series:<?= json_encode(array_values($status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$STATUS_AR[$k]??$k,array_keys($status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},colors:['#1565C0','#0ea5e9','#7c3aed','#d97706','#dc2626','#16a34a','#475569'],plotOptions:{pie:{donut:{size:'60%'}}},legend:{position:'right',fontSize:'10px'}}).render();<?php endif; ?>
<?php if(!empty($sla_series)): ?>new ApexCharts(document.querySelector("#pSla"),{series:[{data:<?= json_encode(array_values($sla_series)) ?>}],chart:{type:'line',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($sla_series)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#dc2626'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:true,style:{fontSize:'9px'}}}).render();<?php endif; ?>
<?php if(!empty($top_assets)): ?>new ApexCharts(document.querySelector("#pTop"),{series:[{data:<?= json_encode(array_column($top_assets,'cnt')) ?>}],chart:{type:'bar',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_column($top_assets,'tag'),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'9px'}}},colors:['#ea580c'],plotOptions:{bar:{distributed:true,borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#pMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',fontFamily:'Tajawal',animations:{enabled:false}},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>,labels:{style:{fontSize:'9px'}}},colors:['#ea580c'],stroke:{curve:'smooth',width:2},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
</script></body></html>
<?php exit;
}
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>مركز تحليل البلاغات — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
:root{--primary:#ea580c;--bg:#f8fafc;--border:#e2e8f0;--tm:#0f172a;--t2:#475569;--t3:#94a3b8;--radius:16px}
body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--tm)}
.wrap{max-width:1400px;margin:0 auto;padding:20px}
.view-toggles{display:flex;gap:10px;margin-bottom:16px;background:#fff;padding:6px;border-radius:99px;width:fit-content;border:1px solid var(--border)}
.toggle-btn{padding:10px 24px;border-radius:99px;font-size:13.5px;font-weight:800;color:var(--t2);text-decoration:none;display:flex;align-items:center;gap:8px}
.toggle-btn.active{background:var(--primary);color:#fff}
.header-hero{background:linear-gradient(135deg,#7c2d12,#ea580c);border-radius:var(--radius);padding:20px 28px;margin-bottom:16px;color:#fff;display:flex;justify-content:space-between;align-items:center}
.ai-banner{border-radius:12px;padding:12px 18px;margin-bottom:16px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:10px;border:1.5px solid}
.ai-success{background:#ecfdf5;border-color:#6ee7b7;color:#065f46}.ai-warning{background:#fffbeb;border-color:#fcd34d;color:#92400e}.ai-danger{background:#fef2f2;border-color:#fca5a5;color:#991b1b}
.grp{background:#fff;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;border-right:4px solid var(--primary)}
.grp summary{padding:14px 20px;cursor:pointer;font-weight:900;font-size:13.5px;display:flex;align-items:center;gap:10px;list-style:none}
.grp-body{padding:0 20px 20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
.fld{display:flex;flex-direction:column;gap:4px}.fld label{font-size:11.5px;font-weight:800;color:var(--t3)}
.fld select,.fld input{border:1.5px solid #cbd5e1;border-radius:8px;padding:8px 12px;font-size:12.5px;font-family:'Tajawal'}
.pbtn{background:#f1f5f9;border:1px solid var(--border);border-radius:8px;padding:8px;font-size:11px;font-weight:700;cursor:pointer;flex:1;text-align:center}
.act-bar{background:#fff;border-radius:100px;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;border:1px solid var(--border);flex-wrap:wrap;gap:8px}
.btn-apply{background:var(--primary);color:#fff;border:none;border-radius:99px;padding:10px 24px;font-weight:900;cursor:pointer;font-family:'Tajawal'}
.btn-export{background:#fff;border:1.5px solid #cbd5e1;border-radius:99px;padding:8px 18px;font-weight:800;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;color:var(--tm)}
.btn-excel{border-color:#10b981;color:#10b981}.btn-charts{border-color:#8b5cf6;color:#8b5cf6}.btn-print{border-color:#0ea5e9;color:#0ea5e9}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.kpi-card{background:#fff;border-radius:var(--radius);padding:16px;border:1px solid var(--border);display:flex;align-items:center;gap:12px}
.kpi-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.kpi-val{font-size:20px;font-weight:900}.kpi-title{font-size:11.5px;font-weight:800;color:var(--t3)}
.cmp-strip{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:20px;display:flex;gap:22px;flex-wrap:wrap;align-items:center}
.cmp-item{display:flex;align-items:center;gap:10px}
.cmp-l{font-size:11.5px;font-weight:800;color:var(--t3)}
.cmp-v{font-size:16px;font-weight:900}
.delta{font-size:11px;font-weight:900;padding:2px 8px;border-radius:99px}
.delta.up{background:#fee2e2;color:#dc2626}.delta.down{background:#dcfce7;color:#16a34a}
.dash-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;margin-bottom:20px}
.chart-card{background:#fff;border-radius:var(--radius);padding:16px;border:1px solid var(--border)}
.chart-title{font-weight:900;font-size:14px;margin-bottom:10px;display:flex;gap:8px;align-items:center;border-bottom:1px dashed var(--border);padding-bottom:8px}
.axis-sec{background:#fff;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px;overflow:hidden}
.axis-h{padding:14px 18px;font-weight:900;font-size:15px;display:flex;gap:10px;align-items:center;border-bottom:1px solid var(--border);background:linear-gradient(90deg,#fff7ed,#fff)}
.axis-h i{color:var(--primary)}
.axis-body{padding:16px 18px;display:grid;grid-template-columns:1.2fr 1fr;gap:16px}
@media(max-width:900px){.axis-body{grid-template-columns:1fr}}
.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl th{background:#f8fafc;padding:8px 10px;text-align:right;font-size:10.5px;font-weight:900;color:var(--t2);border-bottom:2px solid var(--border)}
.tbl td{padding:8px 10px;border-bottom:1px solid #f1f5f9;text-align:right;vertical-align:top}
.tbl tr:hover td{background:#fff7ed}
.badge{display:inline-flex;padding:3px 9px;border-radius:99px;font-size:10.5px;font-weight:800;gap:4px;align-items:center}
.aging-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.aging-card{border-radius:12px;padding:12px;text-align:center;border:1.5px solid}
.aging-v{font-size:22px;font-weight:900}.aging-l{font-size:10.5px;font-weight:800}
.score{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:10px;font-weight:900;font-size:13px;color:#fff}
.empty{text-align:center;padding:50px;color:var(--t3);background:#fff;border-radius:var(--radius);border:1px solid var(--border)}
</style></head>
<body class="app-layout">
<?php $__f_backup = $f ?? []; include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH . '/includes/topbar.php'; $f = $__f_backup; ?>
<main class="page-content"><div class="wrap">

<div class="view-toggles">
<a href="?view=executive&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='executive'?'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> لوحة التحليل الشاملة</a>
<a href="?view=detailed&<?= http_build_query($f) ?>" class="toggle-btn <?= $view_mode==='detailed'?'active':'' ?>"><i class="fa-solid fa-table-list"></i> سجل البلاغات التفصيلي</a>
</div>

<div class="header-hero">
<div><h1 style="font-size:20px;font-weight:900;margin:0"><i class="fa-solid fa-stethoscope" style="margin-left:8px;color:#fdba74"></i> مركز دراسة وتحليل البلاغات</h1>
<div style="color:#fed7aa;font-size:13px;margin-top:4px">أداء • مقاولون • موثوقية • أقسام • زمن • مالي — من كل الزوايا</div></div>
<div style="text-align:left;font-size:11px;color:#fed7aa">تاريخ التقرير<br><strong style="font-size:15px;color:#fff"><?= date('Y-m-d') ?></strong></div>
</div>

<?php if ($results): ?><div class="ai-banner <?= $ai_class ?>"><i class="fa-solid <?= $ai_icon ?>"></i><span><?= e($ai_msg) ?></span></div><?php endif; ?>

<?php
$sr_module = 'complaints'; $sr_filters = $f; $sr_view = $view_mode; $sr_base_url = BASE_URL;
include BASE_PATH . '/includes/saved_reports_bar.php';
?>

<form method="get" id="filtForm">
<input type="hidden" name="view" value="<?= e($view_mode) ?>">
<details class="grp" open>
<summary><i class="fa-solid fa-filter" style="color:var(--primary);background:#ffedd5;padding:6px;border-radius:6px"></i> فلاتر الدراسة <i class="fa-solid fa-chevron-down" style="margin-right:auto"></i></summary>
<div class="grp-body">
<div class="fld"><label>بحث</label><input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="ID/بلاغ/تاج/سيريال/فني"></div>
<div class="fld"><label>النوع</label><select name="type"><option value="">— الكل —</option><?php foreach($TYPE_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['type']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>الأولوية</label><select name="priority"><option value="">— الكل —</option><?php foreach($PRIORITY_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['priority']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<div class="fld"><label>الحالة</label><select name="status"><option value="">— الكل —</option><?php foreach($STATUS_AR as $k=>$v): ?><option value="<?= e($k) ?>" <?= $f['status']===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
<?php if($has_dept): ?><div class="fld"><label>القسم</label><select name="dept"><option value="">— الكل —</option><?php foreach($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $f['dept']===(int)$d['id']?'selected':'' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if($has_contractor): ?><div class="fld"><label>المقاول</label><select name="contractor"><option value="">— الكل —</option><?php foreach($contractors as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['contractor']===(int)$c['id']?'selected':'' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<div class="fld"><label>من</label><input type="date" name="from" id="cFrom" value="<?= e($f['from']) ?>"></div>
<div class="fld"><label>إلى</label><input type="date" name="to" id="cTo" value="<?= e($f['to']) ?>"></div>
<div class="fld"><label>اختصار</label><div style="display:flex;gap:6px"><div class="pbtn" onclick="qRange(1)">شهر</div><div class="pbtn" onclick="qRange(3)">3 أشهر</div><div class="pbtn" onclick="qRange(12)">سنة</div></div></div>
</div>
</details>
<div class="act-bar">
<div style="display:flex;gap:10px;flex-wrap:wrap">
<button type="submit" class="btn-apply"><i class="fa-solid fa-bolt"></i> تحديث الدراسة</button>
<a href="?view=<?= e($view_mode) ?>" class="btn-export" style="border-color:#ef4444;color:#ef4444"><i class="fa-solid fa-xmark"></i> مسح</a>
</div>
<?php if ($can_export && $results): ?>
<div style="display:flex;gap:10px;flex-wrap:wrap">
<a class="btn-export btn-excel" href="?excel=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-file-excel"></i> Excel</a>
<a class="btn-export btn-print" href="?print=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-print"></i> PDF رسمي</a>
<a class="btn-export btn-charts" href="?print_charts=1&view=<?= e($view_mode) ?>&<?= http_build_query($f) ?>" target="_blank"><i class="fa-solid fa-chart-pie"></i> لوحة مؤشرات</a>
</div>
<?php endif; ?>
</div>
</form>

<?php if ($results): ?>
<?php if ($view_mode === 'executive'): ?>

<div class="kpi-grid">
<div class="kpi-card"><div class="kpi-icon" style="background:#ffedd5;color:#ea580c"><i class="fa-solid fa-inbox"></i></div><div><div class="kpi-title">الإجمالي</div><div class="kpi-val"><?= number_format($total) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-door-open"></i></div><div><div class="kpi-title">مفتوحة</div><div class="kpi-val"><?= number_format($open) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div><div><div class="kpi-title">محلولة</div><div class="kpi-val"><?= number_format($closed) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:<?= $sla_rate>=80?'#dcfce7':'#fee2e2' ?>;color:<?= $sla_rate>=80?'#16a34a':'#dc2626' ?>"><i class="fa-solid fa-stopwatch"></i></div><div><div class="kpi-title">التزام SLA</div><div class="kpi-val"><?= $sla_rate ?>%</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-clock"></i></div><div><div class="kpi-title">متوسط الحل</div><div class="kpi-val"><?= $avg_res ?>h</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#dbeafe;color:#1565C0"><i class="fa-solid fa-bell"></i></div><div><div class="kpi-title">أول رد</div><div class="kpi-val"><?= $avg_first ?>h</div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-bolt"></i></div><div><div class="kpi-title">حرجة مفتوحة</div><div class="kpi-val"><?= number_format($critical_open) ?></div></div></div>
<div class="kpi-card"><div class="kpi-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa-solid fa-rotate"></i></div><div><div class="kpi-title">أجهزة متكررة</div><div class="kpi-val"><?= number_format(count(array_filter($asset_agg, fn($a)=>$a['cnt']>=2))) ?></div></div></div>
</div>

<div class="cmp-strip">
<strong style="font-size:13px"><i class="fa-solid fa-code-compare" style="color:var(--primary)"></i> مقارنة الفترات:</strong>
<div class="cmp-item"><span class="cmp-l">بلاغات (<?= e(substr($cur_from,5)) ?>→<?= e(substr($cur_to,5)) ?>)</span><span class="cmp-v"><?= $cur['t'] ?></span><span class="delta <?= $d_total>0?'up':'down' ?>"><?= $d_total>0?'+':'' ?><?= $d_total ?>%</span></div>
<div class="cmp-item"><span class="cmp-l">محلولة</span><span class="cmp-v"><?= $cur['r'] ?></span><span class="delta" style="<?= $d_res>=0?'background:#dcfce7;color:#16a34a':'background:#fee2e2;color:#dc2626' ?>"><?= $d_res>0?'+':'' ?><?= $d_res ?>%</span></div>
<div class="cmp-item"><span class="cmp-l">تجاوزات SLA</span><span class="cmp-v"><?= $cur['b'] ?></span><span class="delta" style="<?= $d_breach<=0?'background:#dcfce7;color:#16a34a':'background:#fee2e2;color:#dc2626' ?>"><?= $d_breach>0?'+':'' ?><?= $d_breach ?>%</span></div>
<span style="font-size:10.5px;color:var(--t3)">مقابل الفترة السابقة (<?= e($prev_from) ?> → <?= e($prev_to) ?>)</span>
</div>

<div class="dash-grid">
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-pie" style="color:var(--primary)"></i> توزيع الحالات</div><div id="chSt" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-line" style="color:#dc2626"></i> اتجاه تجاوز SLA %</div><div id="chSla" style="min-height:220px"></div></div>
<div class="chart-card"><div class="chart-title"><i class="fa-solid fa-chart-area" style="color:#f59e0b"></i> الاتجاه الشهري</div><div id="chMo" style="min-height:220px"></div></div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-gauge-high"></i> محور الأداء والسرعة</div>
<div class="axis-body">
<div><div class="chart-title">متوسط زمن الحل حسب الأولوية</div><div id="chPrio" style="min-height:200px"></div></div>
<div>
<div class="chart-title">تحليل عمر المتراكم</div>
<div class="aging-grid">
<div class="aging-card" style="background:#f0fdf4;border-color:#86efac;color:#15803d"><div class="aging-v"><?= $aging['0-7'] ?></div><div class="aging-l">0-7 يوم</div></div>
<div class="aging-card" style="background:#fffbeb;border-color:#fcd34d;color:#b45309"><div class="aging-v"><?= $aging['8-30'] ?></div><div class="aging-l">8-30 يوم</div></div>
<div class="aging-card" style="background:#fff7ed;border-color:#fdba74;color:#c2410c"><div class="aging-v"><?= $aging['31-90'] ?></div><div class="aging-l">31-90 يوم</div></div>
<div class="aging-card" style="background:#fef2f2;border-color:#fca5a5;color:#b91c1c"><div class="aging-v"><?= $aging['90+'] ?></div><div class="aging-l">+90 يوم</div></div>
</div>
</div>
</div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-screwdriver-wrench"></i> محور المقاولين (بطاقة أداء /100)</div>
<div class="axis-body">
<table class="tbl"><thead><tr><th>#</th><th><?= $has_contractor?'المقاول':'الفني' ?></th><th>إجمالي</th><th>% حل</th><th>SLA</th><th>متوسط</th><th>الدرجة</th></tr></thead><tbody>
<?php foreach ($con_leader as $i => $c): $sc_col = $c['score']>=75?'#16a34a':($c['score']>=50?'#d97706':'#dc2626'); ?>
<tr><td><?= $i+1 ?></td><td style="font-weight:800"><?= e($c['name']) ?></td><td><?= $c['total'] ?></td><td><?= $c['rate'] ?>%</td><td><?= $c['sla_c'] ?>%</td><td><?= $c['avg_h']!==null?$c['avg_h'].'h':'—' ?></td>
<td><span class="score" style="background:<?= $sc_col ?>"><?= $c['score'] ?></span></td></tr>
<?php endforeach; ?>
</tbody></table>
<div><div class="chart-title">مقارنة الدرجات</div><div id="chCon" style="min-height:220px"></div></div>
</div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-rotate"></i> محور الموثوقية</div>
<div class="axis-body">
<table class="tbl"><thead><tr><th>الجهاز</th><th>القسم</th><th>بلاغات</th><th>آخر بلاغ</th><th>مؤشر</th></tr></thead><tbody>
<?php foreach ($top_assets as $a): $acc = $a['last90']>=2 && $a['last90']>$a['prior90']; ?>
<tr><td><div style="font-weight:800;color:#c2410c"><?= e($a['tag']) ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($a['desc'],0,30,'...')) ?></div></td>
<td style="font-size:11px"><?= e($a['dept']) ?></td><td><strong><?= $a['cnt'] ?></strong></td><td style="font-size:11px"><?= e($a['last']?date('Y-m-d',strtotime($a['last'])):'') ?></td>
<td><?php if($acc): ?><span class="badge" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-arrow-trend-up"></i> يتسارع</span><?php else: ?><span class="badge" style="background:#f1f5f9;color:#64748b">مستقر</span><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<div>
<div class="chart-title">المصنِّعات الأكثر أعطالاً</div>
<div id="chMfr" style="min-height:180px"></div>
<div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap">
<span class="badge" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-shield"></i> بالضمان: <?= $warranty_complaints ?></span>
<span class="badge" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-bolt"></i> فئة A: <?= $open_critA ?></span>
</div>
</div>
</div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-building"></i> محور الأقسام (بلاغات/100 أصل)</div>
<div class="axis-body">
<table class="tbl"><thead><tr><th>القسم</th><th>بلاغات</th><th>أصول</th><th>المعدل/100</th></tr></thead><tbody>
<?php foreach ($fairness as $fr): $rc = $fr['rate']===null?'#64748b':($fr['rate']>10?'#dc2626':($fr['rate']>5?'#d97706':'#16a34a')); ?>
<tr><td style="font-weight:800"><?= e($fr['name']) ?></td><td><?= $fr['complaints'] ?></td><td><?= $fr['assets'] ?></td><td style="color:<?= $rc ?>;font-weight:900"><?= $fr['rate']===null?'—':$fr['rate'] ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<div><div class="chart-title">أكثر الأقسام إرسالاً</div><div id="chDept" style="min-height:200px"></div></div>
</div>
</div>

<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-calendar-days"></i> محور الزمن</div>
<div class="axis-body">
<div><div class="chart-title">حسب اليوم</div><div id="chWd" style="min-height:200px"></div></div>
<div><div class="chart-title">حسب الساعة (ذروة الضغط)</div><div id="chHr" style="min-height:200px"></div></div>
</div>
</div>

<?php if ($fin_assets || $downtime_med > 0): ?>
<div class="axis-sec">
<div class="axis-h"><i class="fa-solid fa-sack-dollar"></i> المحور المالي</div>
<div class="axis-body">
<table class="tbl"><thead><tr><th>الجهاز</th><th>بلاغات</th><th>كلفة صيانة</th><th>الضمان</th><th>توصية</th></tr></thead><tbody>
<?php foreach ($fin_assets as $fa): ?>
<tr><td><div style="font-weight:800;color:#c2410c"><?= e($fa['tag']) ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($fa['desc'],0,30,'...')) ?></div></td>
<td><?= $fa['cnt'] ?></td><td style="font-family:monospace;font-weight:800"><?= number_format($fa['maint'],0) ?></td><td><span class="badge" style="background:#fee2e2;color:#dc2626">منتهٍ</span></td>
<td><span class="badge" style="background:#fef3c7;color:#b45309"><i class="fa-solid fa-rotate"></i> دراسة استبدال</span></td></tr>
<?php endforeach; ?>
</tbody></table>
<div>
<div class="chart-title">ساعات توقف الأجهزة الطبية الحرجة</div>
<div style="font-size:34px;font-weight:900;color:#dc2626"><?= number_format($downtime_med,0) ?> <span style="font-size:14px">ساعة</span></div>
</div>
</div>
</div>
<?php endif; ?>

<?php endif; ?>

<div style="margin-bottom:12px;font-weight:900">البلاغات المطابقة: <span style="background:var(--primary);color:#fff;padding:2px 10px;border-radius:10px"><?= $total ?></span></div>
<div style="background:#fff;border-radius:12px;border:1px solid var(--border);overflow-x:auto">
<table class="tbl"><thead><tr><th>#</th><th>البلاغ</th><th>الأصل</th><th>القسم</th><th>أولوية</th><th>حالة</th><th>المعالجة</th><th>التواريخ</th><th>الأداء</th></tr></thead><tbody>
<?php foreach (array_slice($results, 0, $view_mode==='detailed'?500:200) as $r):
$sc=$STATUS_COLORS[$r['status']]??['#64748b','#f1f5f9']; $pc=$PRIORITY_COLORS[$r['priority']]??['#64748b','#f1f5f9'];
$rh=hrs_between($r['created_at'],$r['resolved_at']?:$r['closed_at']);
?>
<tr><td style="color:var(--t3);font-weight:900">#<?= (int)$r['id'] ?></td>
<td><div style="font-weight:800"><?= e(mb_strimwidth($r['c_title']??'—',0,45,'...')) ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e($TYPE_AR[$r['request_type']]??'') ?></div></td>
<td><?php if($r['tag_number']??''): ?><div style="font-weight:800;color:#c2410c"><?= e($r['tag_number']) ?></div><div style="font-size:10.5px;color:var(--t3)"><?= e(mb_strimwidth($r['asset_desc']??'',0,28,'...')) ?></div><?php else: ?>—<?php endif; ?></td>
<td><div><?= e($r['dept_name']??'—') ?></div></td>
<td><span class="badge" style="background:<?= $pc[1] ?>;color:<?= $pc[0] ?>"><?= e($PRIORITY_AR[$r['priority']]??'') ?></span></td>
<td><span class="badge" style="background:<?= $sc[1] ?>;color:<?= $sc[0] ?>"><?= e($STATUS_AR[$r['status']]??'') ?></span></td>
<td><?= e($r['contractor_name']??$r['assignee_name']?:'—') ?></td>
<td><div style="font-size:11px"><?= e($r['created_at']?date('m-d H:i',strtotime($r['created_at'])):'—') ?></div></td>
<td><div style="font-weight:800"><?= $rh!==null?round($rh,1).' س':'—' ?></div><div style="font-size:10px;color:<?= !empty($r['sla_breach_detected_at'])?'#dc2626':'#16a34a' ?>"><?= !empty($r['sla_breach_detected_at'])?'تجاوز SLA':'ملتزم' ?></div></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>

<?php else: ?>
<div class="empty"><i class="fa-solid fa-inbox" style="font-size:44px;color:var(--primary);display:block;margin-bottom:10px"></i><h3>لا توجد بلاغات مطابقة</h3><p>عدّل الفلاتر أو امسحها.</p></div>
<?php endif; ?>

</div></main></div>
<script>
function qRange(m){const to=new Date();const from=new Date();from.setMonth(from.getMonth()-m);document.getElementById('cTo').value=to.toISOString().slice(0,10);document.getElementById('cFrom').value=from.toISOString().slice(0,10);}
<?php if ($view_mode==='executive' && $results): ?>
document.addEventListener("DOMContentLoaded",function(){
const FF='Tajawal';
<?php if(!empty($status_cnt)): ?>new ApexCharts(document.querySelector("#chSt"),{series:<?= json_encode(array_values($status_cnt)) ?>,labels:<?= json_encode(array_map(fn($k)=>$STATUS_AR[$k]??$k,array_keys($status_cnt)),JSON_UNESCAPED_UNICODE) ?>,chart:{type:'donut',height:'100%',fontFamily:FF},colors:['#1565C0','#0ea5e9','#7c3aed','#d97706','#dc2626','#16a34a','#475569'],plotOptions:{pie:{donut:{size:'62%'}}},legend:{position:'bottom',fontSize:'11px'}}).render();<?php endif; ?>
<?php if(!empty($sla_series)): ?>new ApexCharts(document.querySelector("#chSla"),{series:[{name:'تجاوز %',data:<?= json_encode(array_values($sla_series)) ?>}],chart:{type:'line',height:'100%',fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($sla_series)) ?>},colors:['#dc2626'],stroke:{curve:'smooth',width:3},dataLabels:{enabled:false}}).render();<?php endif; ?>
<?php if(!empty($month_cnt)): ?>new ApexCharts(document.querySelector("#chMo"),{series:[{data:<?= json_encode(array_values($month_cnt)) ?>}],chart:{type:'area',height:'100%',fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($month_cnt)) ?>},colors:['#ea580c'],stroke:{curve:'smooth',width:3},fill:{type:'gradient',gradient:{opacityFrom:.6,opacityTo:.05}},dataLabels:{enabled:false}}).render();<?php endif; ?>
<?php if(!empty($avg_res_prio)): ?>new ApexCharts(document.querySelector("#chPrio"),{series:[{name:'ساعات',data:<?= json_encode(array_values($avg_res_prio)) ?>}],chart:{type:'bar',height:'100%',fontFamily:FF},xaxis:{categories:<?= json_encode(array_map(fn($k)=>$PRIORITY_AR[$k]??$k,array_keys($avg_res_prio)),JSON_UNESCAPED_UNICODE) ?>},colors:['#ea580c'],plotOptions:{bar:{borderRadius:5,columnWidth:'45%',distributed:true}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($con_leader)): ?>new ApexCharts(document.querySelector("#chCon"),{series:[{name:'الدرجة',data:<?= json_encode(array_column($con_leader,'score')) ?>}],chart:{type:'bar',height:'100%',fontFamily:FF},xaxis:{categories:<?= json_encode(array_column($con_leader,'name'),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#16a34a'],plotOptions:{bar:{borderRadius:5,columnWidth:'45%',distributed:true}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($mfr_top)): ?>new ApexCharts(document.querySelector("#chMfr"),{series:[{data:<?= json_encode(array_values($mfr_top)) ?>}],chart:{type:'bar',height:'100%',fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($mfr_top),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#7c3aed'],plotOptions:{bar:{borderRadius:4,distributed:true}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($dept_top)): ?>new ApexCharts(document.querySelector("#chDept"),{series:[{data:<?= json_encode(array_values($dept_top)) ?>}],chart:{type:'bar',height:'100%',fontFamily:FF},xaxis:{categories:<?= json_encode(array_keys($dept_top),JSON_UNESCAPED_UNICODE) ?>,labels:{style:{fontSize:'10px'}}},colors:['#0ea5e9'],plotOptions:{bar:{borderRadius:4,distributed:true}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($weekday)): ?>new ApexCharts(document.querySelector("#chWd"),{series:[{data:<?= json_encode([ $weekday[1]??0,$weekday[2]??0,$weekday[3]??0,$weekday[4]??0,$weekday[5]??0,$weekday[6]??0,$weekday[7]??0 ]) ?>}],chart:{type:'bar',height:'100%',fontFamily:FF},xaxis:{categories:['إثنين','ثلاثاء','أربعاء','خميس','جمعة','سبت','أحد']},colors:['#ea580c'],plotOptions:{bar:{borderRadius:4}},dataLabels:{enabled:true},legend:{show:false}}).render();<?php endif; ?>
<?php if(!empty($hours)): ?>new ApexCharts(document.querySelector("#chHr"),{series:[{data:<?= json_encode(array_map(fn($h)=>$hours[$h]??0,range(0,23))) ?>}],chart:{type:'heatmap',height:'100%',fontFamily:FF},colors:['#ea580c'],xaxis:{categories:<?= json_encode(array_map(fn($h)=>$h.':00',range(0,23))) ?>,labels:{style:{fontSize:'8px'}}},dataLabels:{enabled:false}}).render();<?php endif; ?>
});
<?php endif; ?>
</script>
</body></html>