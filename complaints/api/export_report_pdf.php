<?php
/**
 * complaints/api/export_report_pdf.php — تصدير تقرير البلاغات كـ PDF
 * ──────────────────────────────────────────────────────────────────
 * نفس فلاتر reports.php بالضبط + يضيف شعار المستشفى وheader/footer.
 * يستخدم TCPDF (lib/tcpdf/).
 *
 * الصلاحية: complaints.reports.export
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/TCPDF-6.7.5/tcpdf.php';

if (!can('complaints.reports', 'export')) {
    http_response_code(403);
    die('لا تملك صلاحية التصدير');
}

$rtl  = is_rtl();
$lang = current_lang();

// ── فلاتر (نفس reports.php) ─────────────────────────────────
$f_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$f_to   = $_GET['to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_from)) $f_from = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_to))   $f_to   = date('Y-m-d');

$_period_days = (int) round((strtotime($f_to) - strtotime($f_from)) / 86400);
$prev_from    = date('Y-m-d', strtotime($f_from . " -" . ($_period_days + 1) . " days"));
$prev_to      = date('Y-m-d', strtotime($f_from . " -1 day"));

// نطاق الفريق (للأدوار غير الإدارية)
$force_type = null;
if (!can_see_all()) {
    $my_dept_id = (int)(current_user()['department_id'] ?? 0);
    if ($my_dept_id) {
        $dc = $pdo->prepare("SELECT dept_category FROM departments WHERE id=?");
        $dc->execute([$my_dept_id]);
        $cat = (string)($dc->fetchColumn() ?: '');
        if (str_starts_with($cat, 'maintenance_')) {
            $force_type = substr($cat, strlen('maintenance_'));
        }
    }
}

$TYPES = ['medical' => 'طبي', 'it' => 'تقنية معلومات', 'general' => 'صيانة عامة'];
$PRIOS = ['normal' => 'عادي', 'urgent' => 'عاجل', 'critical' => 'طارئ'];
$STATS = ['open' => 'مفتوح', 'acknowledged' => 'مستلَم', 'in_progress' => 'قيد المعالجة',
    'stalled' => 'متعثر', 'escalated' => 'مُصعَّد', 'resolved' => 'محلول',
    'closed' => 'مغلق', 'cancelled' => 'ملغى', 'rejected' => 'مرفوض'];
$ST_COLORS = ['open' => '#64748b', 'acknowledged' => '#0ea5e9', 'in_progress' => '#2563eb',
    'stalled' => '#f59e0b', 'escalated' => '#dc2626', 'resolved' => '#10b981',
    'closed' => '#16a34a', 'cancelled' => '#94a3b8', 'rejected' => '#78716c'];

$f_type = isset($TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$f_prio = isset($PRIOS[$_GET['prio'] ?? '']) ? $_GET['prio'] : '';
$f_stat = isset($STATS[$_GET['stat'] ?? '']) ? $_GET['stat'] : '';
$f_dept = (int)($_GET['dept'] ?? 0);
if ($force_type !== null) $f_type = $force_type;

$W = "c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY)";
$P = [$f_from, $f_to];
if ($f_type) { $W .= " AND c.request_type = ?"; $P[] = $f_type; }
if ($f_prio) { $W .= " AND c.priority = ?";     $P[] = $f_prio; }
if ($f_stat) { $W .= " AND c.status = ?";       $P[] = $f_stat; }
if ($f_dept) { $W .= " AND c.dept_id = ?";      $P[] = $f_dept; }

$NET = "GREATEST(0, TIMESTAMPDIFF(SECOND, c.created_at,
        COALESCE(c.closed_at, c.resolved_at)) - COALESCE(c.sla_paused_seconds_total,0))";

function fmt_dur_pdf(?int $s): string {
    if ($s === null) return '—';
    $s = max(0, $s);
    $d = intdiv($s, 86400); $h = intdiv($s % 86400, 3600); $m = intdiv($s % 3600, 60);
    $out = [];
    if ($d) $out[] = $d . 'ي';
    if ($h) $out[] = $h . 'س';
    $out[] = $m . 'د';
    return implode(' ', $out);
}

// ── استعلامات ────────────────────────────────────────────────
$k = $pdo->prepare("SELECT COUNT(*) total,
    SUM(c.status IN ('resolved','closed')) done,
    SUM(c.status = 'escalated') escalated,
    SUM(c.sla_breach_detected_at IS NOT NULL) breached,
    AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_net,
    AVG(CASE WHEN c.service_rating > 0 THEN c.service_rating END) avg_rate,
    SUM(c.service_rating > 0) rated_cnt
    FROM complaints c WHERE $W");
$k->execute($P); $K = $k->fetch();

$kp = $pdo->prepare("SELECT COUNT(*) total,
    SUM(c.status IN ('resolved','closed')) done,
    SUM(c.sla_breach_detected_at IS NOT NULL) breached
    FROM complaints c WHERE $W AND c.created_at >= ? AND c.created_at < DATE_ADD(?, INTERVAL 1 DAY)");
$kp->execute(array_merge($P, [$prev_from, $prev_to])); $KP = $kp->fetch() ?: [];

$done_rate = $K['total'] ? round($K['done'] * 100 / $K['total']) : 0;
$breach_rate = $K['total'] ? round($K['breached'] * 100 / $K['total']) : 0;
$prev_done_rate = $KP['total'] ? round($KP['done'] * 100 / $KP['total']) : 0;
$prev_breach_rate = $KP['total'] ? round($KP['breached'] * 100 / $KP['total']) : 0;

$byStat = $pdo->prepare("SELECT c.status, COUNT(*) n FROM complaints c WHERE $W GROUP BY c.status");
$byStat->execute($P); $byStat = $byStat->fetchAll(PDO::FETCH_KEY_PAIR);

$topDepts = $pdo->prepare("SELECT d.name, COUNT(*) n, AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_net
    FROM complaints c JOIN departments d ON d.id = c.dept_id WHERE $W GROUP BY d.id, d.name ORDER BY n DESC LIMIT 10");
$topDepts->execute($P); $topDepts = $topDepts->fetchAll();

$topAssets = $pdo->prepare("SELECT a.description, a.tag_number, COUNT(*) n
    FROM complaints c JOIN assets a ON a.id = c.asset_id WHERE $W GROUP BY a.id ORDER BY n DESC LIMIT 10");
$topAssets->execute($P); $topAssets = $topAssets->fetchAll();

$byCriticality = $pdo->prepare("SELECT COALESCE(a.criticality_class, '_none') cls,
    COUNT(c.id) total,
    SUM(c.status IN ('resolved','closed')) done,
    SUM(c.sla_breach_detected_at IS NOT NULL) breached
    FROM complaints c LEFT JOIN assets a ON a.id = c.asset_id
    WHERE $W GROUP BY cls ORDER BY FIELD(cls, 'A', 'B', 'C', '_none')");
$byCriticality->execute($P); $byCriticality = $byCriticality->fetchAll();

$aging = $pdo->prepare("SELECT
    SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) <= 24) AS fresh,
    SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) BETWEEN 25 AND 72) AS week,
    SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) BETWEEN 73 AND 168) AS medium,
    SUM(c.status NOT IN ('resolved','closed','cancelled','rejected') AND TIMESTAMPDIFF(HOUR, c.created_at, NOW()) > 168) AS old
    FROM complaints c WHERE $W");
$aging->execute($P); $aging = $aging->fetch();

$engineers = $pdo->prepare("SELECT u.id, u.full_name,
    COUNT(c.id) total,
    SUM(c.status IN ('resolved','closed')) resolved,
    AVG(CASE WHEN c.status IN ('resolved','closed') THEN $NET END) avg_sec,
    AVG(CASE WHEN c.service_rating > 0 THEN c.service_rating END) avg_rating,
    SUM(c.sla_breach_detected_at IS NOT NULL) breached
    FROM complaints c
    INNER JOIN users u ON u.id IN (c.acknowledged_by, c.resolved_by, c.closed_by)
    WHERE $W GROUP BY u.id, u.full_name HAVING total > 0 ORDER BY resolved DESC LIMIT 10");
$engineers->execute($P); $engineers = $engineers->fetchAll();

// ═══════════════════════════════════════════════════════════════
//  بناء PDF
// ═══════════════════════════════════════════════════════════════

$logo_path = BASE_PATH . '/logo.png';
$logo_data = '';
if (file_exists($logo_path)) {
    $logo_data = $logo_path;
}

// Header مخصص — معرف قبل الـ instance
class PMSH_PDF extends TCPDF {
    public $hospital_name = '';
    public $report_title  = '';
    public $period        = '';
    public function Header() {
        if ($this->page == 1) return; // الصفحة الأولى بدون header (فيها banner كبير)
        $this->SetY(8);
        $this->SetFont('freeserif','B', 9);
        $this->Cell(0, 5, $this->hospital_name . ' — ' . $this->report_title, 0, 1, 'R', false, '', 0, false, 'T', 'M');
        $this->SetFont('freeserif','', 8);
        $this->SetTextColor(100,116,139);
        $this->Cell(0, 4, $this->period, 0, 1, 'R', false, '', 0, false, 'T', 'M');
        $this->Line(10, 22, 287, 22);
    }
    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('freeserif','', 8);
        $this->SetTextColor(148,163,184);
        $this->Cell(0, 5, 'تاريخ الإصدار: ' . date('Y-m-d H:i') . ' | صفحة ' . $this->getAliasNumPage() . ' من ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new PMSH_PDF('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->setRTL(true);
$pdf->setFont('freeserif', '', 11);
$pdf->setCreator('PMSH Assets System');
$pdf->setAuthor(get_setting('hospital_name', 'PMSH'));
$pdf->setTitle('تقرير البلاغات ' . $f_from . ' — ' . $f_to);
$pdf->hospital_name = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$pdf->report_title  = 'تقرير البلاغات';
$pdf->period        = $f_from . ' — ' . $f_to;
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->setHeaderMargin(5);
$pdf->setFooterMargin(15);
$pdf->setMargins(12, 28, 12);
$pdf->setAutoPageBreak(true, 18);

$pdf->AddPage();

// ── Banner (الصفحة الأولى فقط) ──
$logo_ok = false;
if (file_exists($logo_path)) {
    // Try logo (PNG with alpha requires GD/Imagick — try anyway)
    $logo_ext = strtolower(pathinfo($logo_path, PATHINFO_EXTENSION));
    if ($logo_ext === 'png' && !extension_loaded('gd') && !extension_loaded('imagick')) {
        // GD/Imagick not available — try to remove alpha channel manually OR skip
        // Best: skip and log
        error_log('PDF export: logo skipped (PNG alpha requires GD/Imagick)');
    } else {
        try {
            $pdf->Image($logo_path, 12, 12, 25, 0, strtoupper($logo_ext), '', 'R', false, 300, '', false, false, 0, false, false, false);
            $logo_ok = true;
        } catch (Throwable $e) {
            error_log('PDF export: logo failed: ' . $e->getMessage());
        }
    }
}
$pdf->SetY(14);
$pdf->SetFont('freeserif','B', 18);
$pdf->Cell(0, 9, $pdf->hospital_name, 0, 1, 'R', false, '', 0, false, 'T', 'M');
$pdf->SetFont('freeserif','', 11);
$pdf->SetTextColor(71,85,105);
$pdf->Cell(0, 6, 'تقرير البلاغات — الفترة: ' . $f_from . ' ← ' . $f_to, 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);
$pdf->Cell(0, 5, 'مقارنة بالفترة السابقة: ' . $prev_from . ' ← ' . $prev_to, 0, 1, 'R');
if (!$logo_ok) {
    $pdf->SetFont('freeserif','I', 7);
    $pdf->SetTextColor(180,83,9);
    $pdf->Cell(0, 4, '⚠ الشعار غير معروض (PNG alpha يحتاج GD/Imagick). فعّل extension=gd في php.ini لعرضه.', 0, 1, 'L');
    $pdf->SetTextColor(0);
}
$pdf->Ln(4);

// ═══ KPIs ═══
$pdf->SetFont('freeserif','B', 13);
$pdf->SetTextColor(15,23,42);
$pdf->Cell(0, 7, 'المؤشرات الرئيسية', 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);

$kp_html = '
<table cellspacing="2" cellpadding="6" border="0" dir="rtl" style="width:100%">
<tr>
    <td style="background:#f1f5f9;border-radius:5px;width:16%"><b>إجمالي البلاغات</b><br><span style="font-size:18px;color:#0e7490">'.(int)$K['total'].'</span> '.($K['total']>0?'<span style="font-size:9px;color:#94a3b8"> | السابق: '.(int)$KP['total'].'</span>':'').'</td>
    <td style="background:#dcfce7;border-radius:5px;width:16%"><b>نسبة الحل</b><br><span style="font-size:18px;color:#16a34a">'.$done_rate.'%</span> '.($KP['total']>0?'<span style="font-size:9px;color:#94a3b8"> | السابق: '.$prev_done_rate.'%</span>':'').'</td>
    <td style="background:#cffafe;border-radius:5px;width:16%"><b>مُصعَّد</b><br><span style="font-size:18px;color:#dc2626">'.(int)$K['escalated'].'</span></td>
    <td style="background:#fee2e2;border-radius:5px;width:16%"><b>تجاوز المهلة</b><br><span style="font-size:18px;color:#b45309">'.$breach_rate.'%</span> '.($KP['total']>0?'<span style="font-size:9px;color:#94a3b8"> | السابق: '.$prev_breach_rate.'%</span>':'').'</td>
    <td style="background:#fef3c7;border-radius:5px;width:16%"><b>متوسط زمن الحل</b><br><span style="font-size:14px;color:#0e7490">'.fmt_dur_pdf($K['avg_net']!==null?(int)$K['avg_net']:null).'</span></td>
    <td style="background:#fef3c7;border-radius:5px;width:16%"><b>متوسط التقييم</b><br><span style="font-size:14px;color:#f59e0b">★ '.($K['avg_rate']?number_format((float)$K['avg_rate'],1):'—').'</span></td>
</tr>
</table>';
$pdf->writeHTML($kp_html, true, false, true, false, 'R');
$pdf->Ln(4);

// ═══ توزيع الحالات ═══
$pdf->SetFont('freeserif','B', 13);
$pdf->Cell(0, 7, 'توزيع البلاغات حسب الحالة', 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);

$stat_html = '<table cellspacing="0" cellpadding="4" border="1" dir="rtl" style="width:100%;border-color:#cbd5e1">';
$stat_html .= '<tr style="background:#f1f5f9"><th style="text-align:right">الحالة</th><th>العدد</th><th style="width:60%">النسبة</th></tr>';
$total = array_sum($byStat);
foreach ($STATS as $sk => $sl) {
    $n = (int)($byStat[$sk] ?? 0);
    if ($n === 0) continue;
    $pct = $total ? round($n * 100 / $total) : 0;
    $color = $ST_COLORS[$sk] ?? '#64748b';
    $stat_html .= '<tr><td style="text-align:right"><span style="color:'.$color.'">● </span> '.$sl.'</td><td style="text-align:center">'.$n.'</td>'
        . '<td><div style="background:'.$color.';height:8px;width:'.$pct.'%;border-radius:4px"></div></td></tr>';
}
$stat_html .= '</table>';
$pdf->writeHTML($stat_html, true, false, true, false, 'R');
$pdf->Ln(4);

// ═══ حسب فئة الحساسية ═══
$pdf->SetFont('freeserif','B', 13);
$pdf->Cell(0, 7, 'البلاغات حسب فئة حساسية الجهاز (A/B/C)', 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);

$cls_meta = ['A'=>['#dc2626','حرج'],'B'=>['#f59e0b','عالي'],'C'=>['#10b981','عادي'],'_none'=>['#94a3b8','بدون']];
$cls_html = '<table cellspacing="0" cellpadding="6" border="1" dir="rtl" style="width:100%;border-color:#cbd5e1">';
$cls_html .= '<tr style="background:#f1f5f9"><th style="text-align:right">الفئة</th><th>الوصف</th><th>العدد</th><th>محلول</th><th>تجاوز المهلة</th></tr>';
$byC = []; foreach ($byCriticality as $r) $byC[$r['cls']] = $r;
foreach (['A','B','C','_none'] as $c) {
    $r = $byC[$c] ?? null;
    $n = $r ? (int)$r['total'] : 0;
    $m = $cls_meta[$c];
    $cls_html .= '<tr><td style="text-align:right"><b style="color:'.$m[0].'">'.$c.'</b></td><td style="text-align:right">'.$m[1].'</td>'
        . '<td style="text-align:center"><b>'.$n.'</b></td>'
        . '<td style="text-align:center">'.($r?(int)$r['done']:0).'</td>'
        . '<td style="text-align:center">'.($r?(int)$r['breached']:0).'</td></tr>';
}
$cls_html .= '</table>';
$pdf->writeHTML($cls_html, true, false, true, false, 'R');
$pdf->Ln(4);

// ═══ أعلى الأقسام ═══
$pdf->SetFont('freeserif','B', 13);
$pdf->Cell(0, 7, 'أعلى الأقسام رفعاً للبلاغات', 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);

$dept_html = '<table cellspacing="0" cellpadding="4" border="1" dir="rtl" style="width:100%;border-color:#cbd5e1">';
$dept_html .= '<tr style="background:#f1f5f9"><th style="text-align:right;width:60%">القسم</th><th>العدد</th><th>متوسط الحل</th></tr>';
foreach ($topDepts as $r) {
    $dept_html .= '<tr><td style="text-align:right">'.e($r['name']).'</td><td style="text-align:center"><b>'.(int)$r['n'].'</b></td><td style="text-align:center">'.fmt_dur_pdf($r['avg_net']!==null?(int)$r['avg_net']:null).'</td></tr>';
}
$dept_html .= '</table>';
$pdf->writeHTML($dept_html, true, false, true, false, 'R');
$pdf->Ln(4);

// ═══ أكثر الأجهزة تعطلاً ═══
$pdf->SetFont('freeserif','B', 13);
$pdf->Cell(0, 7, 'أكثر الأجهزة تعطلاً', 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);

$ast_html = '<table cellspacing="0" cellpadding="4" border="1" dir="rtl" style="width:100%;border-color:#cbd5e1">';
$ast_html .= '<tr style="background:#f1f5f9"><th style="text-align:right;width:50%">الجهاز</th><th>التاج</th><th>العدد</th></tr>';
foreach ($topAssets as $r) {
    $ast_html .= '<tr><td style="text-align:right">'.e(mb_strimwidth($r['description']??'',0,60,'…')).'</td><td style="text-align:center">'.e($r['tag_number']??'—').'</td><td style="text-align:center"><b>'.(int)$r['n'].'</b></td></tr>';
}
$ast_html .= '</table>';
$pdf->writeHTML($ast_html, true, false, true, false, 'R');
$pdf->Ln(4);

// ═══ تحليل التقادم ═══
$pdf->SetFont('freeserif','B', 13);
$pdf->Cell(0, 7, 'تحليل التقادم (البلاغات المفتوحة حالياً)', 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);
$aging_html = '<table cellspacing="2" cellpadding="6" border="0" dir="rtl" style="width:100%">
<tr>
    <td style="background:#dcfce7;border-radius:5px;width:20%;text-align:center"><b>≤ 24 ساعة</b><br><span style="font-size:18px;color:#10b981">'.(int)$aging['fresh'].'</span></td>
    <td style="background:#e0f2fe;border-radius:5px;width:20%;text-align:center"><b>1-3 أيام</b><br><span style="font-size:18px;color:#0ea5e9">'.(int)$aging['week'].'</span></td>
    <td style="background:#fef3c7;border-radius:5px;width:20%;text-align:center"><b>3-7 أيام</b><br><span style="font-size:18px;color:#f59e0b">'.(int)$aging['medium'].'</span></td>
    <td style="background:#fee2e2;border-radius:5px;width:20%;text-align:center"><b>&gt; 7 أيام</b><br><span style="font-size:18px;color:#dc2626">'.(int)$aging['old'].'</span></td>
    <td style="background:#f1f5f9;border-radius:5px;width:20%;text-align:center"><b>تجاوز SLA</b><br><span style="font-size:14px;color:#64748b">⚠</span></td>
</tr>
</table>';
$pdf->writeHTML($aging_html, true, false, true, false, 'R');
$pdf->Ln(4);

// ═══ أداء المهندسين ═══
$pdf->SetFont('freeserif','B', 13);
$pdf->Cell(0, 7, 'أداء المهندسين والفرق (أعلى 10)', 0, 1, 'R');
$pdf->SetFont('freeserif','', 9);

$eng_html = '<table cellspacing="0" cellpadding="4" border="1" dir="rtl" style="width:100%;border-color:#cbd5e1">';
$eng_html .= '<tr style="background:#f1f5f9"><th style="text-align:right;width:30%">المهندس</th><th>إجمالي</th><th>محلول</th><th>نسبة الحل</th><th>متوسط الزمن</th><th>التقييم</th></tr>';
foreach ($engineers as $e) {
    $r_done = $e['total'] ? round($e['resolved'] * 100 / $e['total']) : 0;
    $rating = $e['avg_rating'] ? number_format((float)$e['avg_rating'], 1) : '—';
    $eng_html .= '<tr><td style="text-align:right"><b>'.e($e['full_name']).'</b></td><td style="text-align:center">'.(int)$e['total'].'</td><td style="text-align:center">'.(int)$e['resolved'].'</td><td style="text-align:center">'.$r_done.'%</td><td style="text-align:center">'.fmt_dur_pdf($e['avg_sec']!==null?(int)$e['avg_sec']:null).'</td><td style="text-align:center">★ '.$rating.'</td></tr>';
}
$eng_html .= '</table>';
$pdf->writeHTML($eng_html, true, false, true, false, 'R');
$pdf->Ln(6);

// ═══ Footer للتوقيع ═══
$pdf->Ln(8);
$pdf->SetFont('freeserif','', 10);
$pdf->SetTextColor(100,116,139);
$pdf->Cell(80, 6, 'مُعِد التقرير: ' . e(current_user()['full_name'] ?? '—'), 0, 0, 'R');
$pdf->Cell(80, 6, '', 0, 0, 'C');
$pdf->Cell(80, 6, 'التاريخ: ' . date('Y-m-d'), 0, 1, 'L');
$pdf->Ln(6);
$pdf->SetDrawColor(15,23,42);
$pdf->SetLineWidth(0.3);
$pdf->Cell(80, 14, 'توقيع مُعِد التقرير', 'T', 0, 'C');
$pdf->Cell(40, 14, '', 0, 0, 'C');
$pdf->Cell(80, 14, 'توقيع المدير المعتمد', 'T', 1, 'C');

// ── توليد ملف PDF ───────────────────────────────────────────
$fname = 'PMSH_Complaints_Report_' . $f_from . '_to_' . $f_to . '.pdf';
$pdf->Output($fname, 'D'); // D = force download
exit;