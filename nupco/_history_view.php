<?php
/**
 * nupco/_history_view.php — عرض سجل المزامنات (مكوّن قابل للتضمين)
 * ─────────────────────────────────────────────────────────────────
 *   • يُستخدم داخل sync.php كـ tab (مع ?tab=history)
 *   • يحتوي على: فلاتر + KPIs + جدول السجلات
 *
 *   يجب أن يُعرَّف قبل include:
 *     - $pdo      (PDO)
 *     - $rtl      (bool)
 *     - $can_log  (bool) — صلاحية عرض السجل
 *
 *   يحدد المتغيرات قبل include (اختياري):
 *     - $f_status, $f_user, $f_date_from, $f_date_to
 */

if (!defined('NUPCO_HISTORY_VIEW_INCLUDED')) {
    define('NUPCO_HISTORY_VIEW_INCLUDED', true);
}

// ══════════════ فلاتر (إن لم تُمرَّر) ══════════════
$f_status   = $f_status   ?? ($_GET['status'] ?? '');
$f_user     = $f_user     ?? (int)($_GET['user'] ?? 0);
$f_date_from = $f_date_from ?? ($_GET['from'] ?? '');
$f_date_to   = $f_date_to   ?? ($_GET['to'] ?? '');

$where = "WHERE 1=1";
$params = [];
if ($f_status && in_array($f_status, ['uploaded','previewed','applied','failed','cancelled'], true)) {
    $where .= " AND l.status = ?"; $params[] = $f_status;
}
if ($f_user) { $where .= " AND l.synced_by = ?"; $params[] = $f_user; }
if ($f_date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_from)) {
    $where .= " AND DATE(l.sync_date) >= ?"; $params[] = $f_date_from;
}
if ($f_date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f_date_to)) {
    $where .= " AND DATE(l.sync_date) <= ?"; $params[] = $f_date_to;
}

$stmt = $pdo->prepare("
    SELECT l.*, u.full_name AS user_name
    FROM nupco_sync_log l
    LEFT JOIN users u ON u.id = l.synced_by
    $where
    ORDER BY l.sync_date DESC
    LIMIT 100
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// إحصائيات شاملة (لا تتأثر بالفلاتر)
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='applied') AS applied,
        SUM(status='failed') AS failed,
        SUM(applied_at IS NOT NULL) AS total_applied
    FROM nupco_sync_log
")->fetch(PDO::FETCH_ASSOC);

$status_meta = [
    'uploaded'  => ['ar' => 'مرفوع فقط',  'en' => 'Uploaded',  'color' => '#64748b', 'bg' => '#f1f5f9', 'ico' => 'fa-cloud-arrow-up'],
    'previewed' => ['ar' => 'تم العرض',   'en' => 'Previewed', 'color' => '#d97706', 'bg' => '#fffbeb', 'ico' => 'fa-eye'],
    'applied'   => ['ar' => 'تم التطبيق', 'en' => 'Applied',   'color' => '#16a34a', 'bg' => '#ecfdf5', 'ico' => 'fa-check'],
    'failed'    => ['ar' => 'فشل',         'en' => 'Failed',    'color' => '#dc2626', 'bg' => '#fef2f2', 'ico' => 'fa-circle-xmark'],
    'cancelled' => ['ar' => 'ملغى',        'en' => 'Cancelled', 'color' => '#94a3b8', 'bg' => '#f1f5f9', 'ico' => 'fa-ban'],
];
?>

<style>
.hw-h1 { font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 4px; display:flex; align-items:center; gap: 10px; }
.hw-sub { font-size: 13px; color: #64748b; margin-bottom: 16px; }
.hw-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
@media (max-width: 920px) { .hw-stats { grid-template-columns: repeat(2, 1fr); } }
.hw-stat { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 12px 16px; display: flex; align-items: center; gap: 12px; }
.hw-stat-ico { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.hw-stat-val { font-size: 20px; font-weight: 800; line-height: 1; }
.hw-stat-lbl { font-size: 11px; font-weight: 700; color: #64748b; margin-top: 3px; }
.hw-stat.tot .hw-stat-ico { background: #eff6ff; color: #1565C0; } .hw-stat.tot .hw-stat-val { color: #1565C0; }
.hw-stat.ok .hw-stat-ico { background: #ecfdf5; color: #16a34a; } .hw-stat.ok .hw-stat-val { color: #16a34a; }
.hw-stat.fail .hw-stat-ico { background: #fef2f2; color: #dc2626; } .hw-stat.fail .hw-stat-val { color: #dc2626; }
.hw-stat.last .hw-stat-ico { background: #fffbeb; color: #d97706; } .hw-stat.last .hw-stat-val { color: #d97706; }

.hw-filterbar { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 10px 14px; margin-bottom: 12px; display: flex; gap: 8px; align-items: end; flex-wrap: wrap; }
.hw-fb-group { display: flex; flex-direction: column; gap: 3px; }
.hw-fb-group label { font-size: 10.5px; font-weight: 800; color: #64748b; }
.hw-fb-group select, .hw-fb-group input { height: 32px; padding: 0 9px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: 'Tajawal', sans-serif; font-size: 12.5px; background: #fff; min-width: 110px; }
.hw-btn { background: #1565C0; color: #fff; border: none; padding: 7px 14px; border-radius: 8px; font-family: 'Tajawal'; font-size: 12.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; height: 32px; text-decoration: none; }
.hw-btn.outline { background: transparent; color: #475569; border: 1.5px solid #e2e8f0; }

.hw-tbl { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
table.hw-t { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.hw-t th { background: #f8fafc; padding: 10px 12px; text-align: right; font-weight: 700; color: #475569; font-size: 11px; white-space: nowrap; border-bottom: 1.5px solid #e2e8f0; }
.hw-t td { padding: 9px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.hw-t tr:hover td { background: #f8fafc; }
.hw-t tr:last-child td { border-bottom: none; }
.hw-status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 12px; font-size: 10.5px; font-weight: 800; }
.hw-empty { padding: 40px 20px; text-align: center; color: #94a3b8; }
.hw-empty i { font-size: 36px; opacity: .3; display: block; margin-bottom: 8px; }
</style>

<!-- ═══ رأس الصفحة ═══ -->
<div style="display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:8px; flex-wrap:wrap">
    <div>
        <h1 class="hw-h1">
            <i class="fa-solid fa-clock-rotate-left" style="color:#0e7490"></i>
            <?= $rtl?'سجل مزامنات NUPCO':'NUPCO Sync History' ?>
        </h1>
        <div class="hw-sub"><?= $rtl?'كل عمليات مزامنة كتالوج NUPCO السابقة':'All previous NUPCO catalog sync operations' ?></div>
    </div>
    <a href="?tab=sync" class="hw-btn" style="background:#0e7490">
        <i class="fa-solid fa-plus"></i>
        <?= $rtl?'مزامنة جديدة':'New sync' ?>
    </a>
</div>

<!-- إحصائيات سريعة -->
<div class="hw-stats">
    <div class="hw-stat tot">
        <div class="hw-stat-ico"><i class="fa-solid fa-list"></i></div>
        <div><div class="hw-stat-val"><?= number_format((int)$stats['total']) ?></div><div class="hw-stat-lbl"><?= $rtl?'إجمالي المزامنات':'Total syncs' ?></div></div>
    </div>
    <div class="hw-stat ok">
        <div class="hw-stat-ico"><i class="fa-solid fa-circle-check"></i></div>
        <div><div class="hw-stat-val"><?= number_format((int)$stats['applied']) ?></div><div class="hw-stat-lbl"><?= $rtl?'تم تطبيقها':'Applied' ?></div></div>
    </div>
    <div class="hw-stat fail">
        <div class="hw-stat-ico"><i class="fa-solid fa-circle-xmark"></i></div>
        <div><div class="hw-stat-val"><?= number_format((int)$stats['failed']) ?></div><div class="hw-stat-lbl"><?= $rtl?'فشلت':'Failed' ?></div></div>
    </div>
    <div class="hw-stat last">
        <div class="hw-stat-ico"><i class="fa-solid fa-arrows-rotate"></i></div>
        <div><div class="hw-stat-val"><?= number_format((int)$stats['total_applied']) ?></div><div class="hw-stat-lbl"><?= $rtl?'مجموع السجلات المُحدّثة':'Total rows updated' ?></div></div>
    </div>
</div>

<!-- فلاتر -->
<form method="GET" class="hw-filterbar">
    <input type="hidden" name="tab" value="history">
    <div class="hw-fb-group">
        <label><?= $rtl?'الحالة':'Status' ?></label>
        <select name="status">
            <option value=""><?= $rtl?'الكل':'All' ?></option>
            <?php foreach ($status_meta as $k => $m): ?>
                <option value="<?= $k ?>" <?= $f_status===$k?'selected':'' ?>><?= $rtl?$m['ar']:$m['en'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="hw-fb-group">
        <label><?= $rtl?'من تاريخ':'From' ?></label>
        <input type="date" name="from" value="<?= e($f_date_from) ?>">
    </div>
    <div class="hw-fb-group">
        <label><?= $rtl?'إلى تاريخ':'To' ?></label>
        <input type="date" name="to" value="<?= e($f_date_to) ?>">
    </div>
    <button type="submit" class="hw-btn"><i class="fa-solid fa-filter"></i> <?= $rtl?'تطبيق':'Apply' ?></button>
    <a href="?tab=history" class="hw-btn outline"><i class="fa-solid fa-xmark"></i> <?= $rtl?'مسح':'Clear' ?></a>
</form>

<!-- جدول السجلات -->
<div class="hw-tbl">
    <?php if (empty($rows)): ?>
        <div class="hw-empty">
            <i class="fa-solid fa-inbox"></i>
            <h3><?= $rtl?'لا توجد مزامنات سابقة':'No sync history yet' ?></h3>
            <p><?= $rtl?'ابدأ أول مزامنة من زر "مزامنة جديدة"':'Start your first sync using the "New sync" button' ?></p>
        </div>
    <?php else: ?>
    <table class="hw-t">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th><?= $rtl?'الملف':'File' ?></th>
                <th><?= $rtl?'التاريخ':'Date' ?></th>
                <th><?= $rtl?'المستخدم':'User' ?></th>
                <th><?= $rtl?'الورقة':'Sheet' ?></th>
                <th style="text-align:center"><?= $rtl?'صفوف':'Rows' ?></th>
                <th style="text-align:center"><?= $rtl?'الحالة':'Status' ?></th>
                <th style="text-align:center"><?= $rtl?'جديد':'New' ?></th>
                <th style="text-align:center"><?= $rtl?'محدّث':'Updated' ?></th>
                <th style="text-align:center"><?= $rtl?'محذوف':'Removed' ?></th>
                <th style="text-align:center"><?= $rtl?'أخطاء':'Errors' ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $m = $status_meta[$r['status']] ?? $status_meta['uploaded'];
            ?>
            <tr>
                <td style="color:#94a3b8;font-weight:700">#<?= (int)$r['id'] ?></td>
                <td>
                    <div style="font-weight:700;color:#0f172a"><?= e(mb_strimwidth($r['file_name'], 0, 36, '…')) ?></div>
                    <div style="font-size:10px;color:#94a3b8"><?= number_format((int)$r['file_size']/1024, 1) ?> KB</div>
                </td>
                <td>
                    <div style="font-weight:600;color:#0f172a"><?= e(date('Y-m-d', strtotime($r['sync_date']))) ?></div>
                    <div style="font-size:10px;color:#64748b"><?= e(date('H:i', strtotime($r['sync_date']))) ?></div>
                </td>
                <td style="font-size:12px"><?= e($r['user_name'] ?? '—') ?></td>
                <td style="font-size:11px;color:#475569;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($r['sheet_name'] ?? '') ?>"><?= e($r['sheet_name'] ?? '—') ?></td>
                <td style="text-align:center;font-family:'Inter',monospace;font-weight:700;color:#0f172a"><?= number_format((int)$r['rows_in_file']) ?></td>
                <td style="text-align:center">
                    <span class="hw-status-pill" style="background:<?= $m['bg'] ?>;color:<?= $m['color'] ?>">
                        <i class="fa-solid <?= $m['ico'] ?>" style="font-size:9px"></i>
                        <?= $rtl?$m['ar']:$m['en'] ?>
                    </span>
                </td>
                <td style="text-align:center;font-weight:700;color:<?= $r['new_count']>0?'#16a34a':'#94a3b8' ?>"><?= $r['new_count']>0?'+'.number_format($r['new_count']):'—' ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $r['updated_count']>0?'#d97706':'#94a3b8' ?>"><?= $r['updated_count']>0?'~'.number_format($r['updated_count']):'—' ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $r['not_in_excel_count']>0?'#dc2626':'#94a3b8' ?>"><?= $r['not_in_excel_count']>0?number_format($r['not_in_excel_count']):'—' ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $r['error_count']>0?'#dc2626':'#94a3b8' ?>"><?= $r['error_count']>0?number_format($r['error_count']):'—' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
