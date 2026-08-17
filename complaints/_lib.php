<?php
/**
 * complaints/_lib.php — مساعدات مشتركة لموديول البلاغات
 * يُستدعى من create.php / my.php / view.php / index.php لتفادي تكرار المنطق.
 */

/**
 * يُرجع معرّفات كل المستخدمين الذين يملكون صلاحية (page_code, action) فعلياً،
 * بنفس منطق can() تماماً (تجاوز المستخدم أولاً، ثم صلاحية الدور) — لكن لأي مستخدم،
 * لا المستخدم الحالي في الجلسة فقط. اختياري: حصرها بقسم معيّن.
 */
function users_with_permission(PDO $pdo, string $page_code, string $action, ?int $dept_id = null): array {
    $sql = "
        SELECT DISTINCT u.id
        FROM users u
        WHERE u.is_active = 1
    ";
    $params = [];
    if ($dept_id !== null) { $sql .= " AND u.department_id = ?"; $params[] = $dept_id; }

    $sql .= "
        AND (
            u.is_admin = 1
            OR EXISTS (
                SELECT 1 FROM user_permission_overrides upo
                JOIN page_permissions pp ON pp.id = upo.page_permission_id
                JOIN pages p ON p.id = pp.page_id
                WHERE upo.user_id = u.id AND p.code = ? AND pp.action = ?
                  AND upo.granted = 1
                  AND (upo.expires_at IS NULL OR upo.expires_at > NOW())
            )
            OR (
                EXISTS (
                    SELECT 1 FROM role_permissions rp
                    JOIN user_roles ur ON ur.role_id = rp.role_id
                    JOIN page_permissions pp ON pp.id = rp.page_permission_id
                    JOIN pages p ON p.id = pp.page_id
                    WHERE ur.user_id = u.id AND p.code = ? AND pp.action = ?
                      AND pp.is_active = 1
                      AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                )
                AND NOT EXISTS (
                    SELECT 1 FROM user_permission_overrides upo
                    JOIN page_permissions pp ON pp.id = upo.page_permission_id
                    JOIN pages p ON p.id = pp.page_id
                    WHERE upo.user_id = u.id AND p.code = ? AND pp.action = ?
                      AND upo.granted = 0
                      AND (upo.expires_at IS NULL OR upo.expires_at > NOW())
                )
            )
        )
    ";
    array_push($params, $page_code, $action, $page_code, $action, $page_code, $action);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ملاحظة: notify_many() موجودة فعلاً في includes/notify.php (مُحمَّلة مسبقاً ضمن config.php)
// بنفس التوقيع تماماً — لا حاجة لتعريفها هنا، فاستُخدمت مباشرة في my.php/view.php.

const COMPLAINT_STATUS_AR = [
    'open' => ['مفتوح', '#64748b', '#f1f5f9'], 'acknowledged' => ['تم الاستلام', '#1d4ed8', '#eff6ff'],
    'in_progress' => ['جاري العمل', '#1d4ed8', '#eff6ff'], 'stalled' => ['متعثر', '#b45309', '#fffbeb'],
    'escalated' => ['مُصعَّد', '#b91c1c', '#fef2f2'], 'resolved' => ['بانتظار تأكيد المستخدم', '#15803d', '#f0fdf4'],
    'closed' => ['مُغلَق', '#15803d', '#f0fdf4'], 'cancelled' => ['مُلغى', '#94a3b8', '#f8fafc'],
    'rejected' => ['مرفوض', '#dc2626', '#fef2f2'],
];
const COMPLAINT_PRIORITY_AR = [
    'normal' => ['عادي', '#16a34a'], 'urgent' => ['عاجل', '#d97706'], 'critical' => ['عاجل جداً', '#dc2626'],
];