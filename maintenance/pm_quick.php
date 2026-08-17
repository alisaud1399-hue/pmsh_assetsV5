<?php
/**
 * maintenance/pm_quick.php — v7: Living 2026 Design
 *
 * 3 بطاقات فقط:
 *   - 🎨 Hero (animated gradient + floating shapes)
 *   - 💎 بطاقة اختيار الجهاز (بحث ذكي + شركة/موديل، بخلفية ملوّنة متدرّجة)
 *   - 📋 النموذج
 *
 * الهدف: حيوية + روح + شخصية 2026، بدون ازدحام بصري.
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('pm.schedules');

$rtl = is_rtl();
$BASE = BASE_URL;
$flash = get_flash();

$contractors = $pdo->query("
    SELECT DISTINCT c.id, c.name FROM committees c
    INNER JOIN pm_schedules ps ON ps.contractor_id = c.id
    WHERE ps.contractor_id IS NOT NULL
    ORDER BY c.name LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);
if (empty($contractors)) {
    $contractors = $pdo->query("SELECT id, name FROM committees WHERE status='active' ORDER BY name LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
}

$internal_users = $pdo->query("
    SELECT u.id, u.full_name, d.name AS dept_name
    FROM users u LEFT JOIN departments d ON d.id=u.department_id
    WHERE u.is_active=1 ORDER BY u.full_name
")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'إضافة PM سريع';
$active_nav = 'pm.schedules';
$breadcrumb = [
    ['name' => $page_title],
];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
/* ═══ Living 2026 Tokens ═══ */
:root {
  --ind-50: #eef2ff; --ind-100: #e0e7ff; --ind-400: #818cf8;
  --ind-500: #6366f1; --ind-600: #4f46e5; --ind-700: #4338ca;
  --violet-500: #8b5cf6; --violet-600: #7c3aed;
  --pink-500: #ec4899; --pink-600: #db2777;
  --amber-500: #f59e0b; --amber-600: #d97706;
  --emerald: #10b981; --emerald-d: #059669;
  --sky-500: #0ea5e9;
  --slate-50: #f8fafc; --slate-100: #f1f5f9; --slate-200: #e2e8f0;
  --slate-300: #cbd5e1; --slate-400: #94a3b8; --slate-500: #64748b;
  --slate-600: #475569; --slate-700: #334155; --slate-800: #1e293b; --slate-900: #0f172a;
  --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.06), 0 1px 2px rgba(15, 23, 42, 0.04);
  --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.06), 0 2px 4px -2px rgba(99, 102, 241, 0.06);
  --shadow-lg: 0 10px 25px -5px rgba(99, 102, 241, 0.15), 0 8px 10px -6px rgba(99, 102, 241, 0.08);
  --shadow-xl: 0 25px 50px -12px rgba(99, 102, 241, 0.25);
  --shadow-glow-indigo: 0 0 0 4px rgba(99, 102, 241, 0.15), 0 4px 14px rgba(99, 102, 241, 0.25);
  --shadow-glow-emerald: 0 0 0 4px rgba(16, 185, 129, 0.15), 0 4px 14px rgba(16, 185, 129, 0.3);
  --shadow-glow-amber: 0 0 0 4px rgba(245, 158, 11, 0.15), 0 4px 14px rgba(245, 158, 11, 0.25);
}
* { font-family: 'Tajawal', -apple-system, sans-serif !important; box-sizing: border-box; }
html, body {
  background:
    radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.06) 0px, transparent 50%),
    radial-gradient(at 100% 0%, rgba(236, 72, 153, 0.05) 0px, transparent 50%),
    radial-gradient(at 50% 100%, rgba(139, 92, 246, 0.05) 0px, transparent 50%),
    linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  min-height: 100vh;
}

/* ═══ Hero — Living Gradient + Floating Shapes ═══ */
.pq-hero {
  background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 40%, #ec4899 100%);
  background-size: 200% 200%;
  animation: heroShift 8s ease-in-out infinite;
  border-radius: 20px;
  padding: 16px 22px;
  color: #fff;
  position: relative; overflow: hidden;
  box-shadow: var(--shadow-xl);
  margin-bottom: 14px;
  border: 1px solid rgba(255, 255, 255, 0.15);
}
@keyframes heroShift {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
.pq-hero::before {
  content: '';
  position: absolute; inset: 0;
  background:
    radial-gradient(circle at 15% 50%, rgba(255,255,255,0.25) 0%, transparent 40%),
    radial-gradient(circle at 85% 30%, rgba(255,255,255,0.15) 0%, transparent 35%),
    radial-gradient(circle at 50% 80%, rgba(255,200,255,0.1) 0%, transparent 40%);
  pointer-events: none;
}
.pq-hero::after {
  content: '';
  position: absolute;
  bottom: -50px; right: -50px;
  width: 200px; height: 200px;
  background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
  border-radius: 50%;
  animation: float 6s ease-in-out infinite;
  pointer-events: none;
}
@keyframes float {
  0%, 100% { transform: translate(0,0) scale(1); }
  50% { transform: translate(-15px,-20px) scale(1.1); }
}
.pq-hero-row { display: flex; align-items: center; gap: 14px; position: relative; z-index: 1; }
.pq-hero-ico {
  width: 48px; height: 48px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(255,255,255,0.3), rgba(255,255,255,0.1));
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; flex-shrink: 0;
  box-shadow: 0 4px 14px rgba(0,0,0,0.15);
  animation: iconPulse 3s ease-in-out infinite;
}
@keyframes iconPulse {
  0%, 100% { transform: scale(1); box-shadow: 0 4px 14px rgba(0,0,0,0.15); }
  50% { transform: scale(1.05); box-shadow: 0 4px 20px rgba(255,255,255,0.3); }
}
.pq-hero h1 { margin: 0; font-size: 19px; font-weight: 900; letter-spacing: -0.3px; }
.pq-hero p { margin: 2px 0 0; font-size: 11.5px; opacity: 0.92; line-height: 1.4; }
.pq-hero-stat {
  background: linear-gradient(135deg, rgba(255,255,255,0.25), rgba(255,255,255,0.1));
  padding: 6px 14px;
  border-radius: 12px;
  font-size: 11px; font-weight: 800;
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255,255,255,0.3);
  display: flex; align-items: center; gap: 5px;
  position: relative;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.pq-hero-stat .dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #fbbf24;
  box-shadow: 0 0 8px #fbbf24;
  animation: blink 1.4s ease-in-out infinite;
}
@keyframes blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
.pq-hero-stat b { font-size: 15px; font-weight: 900; }

/* ═══ Filter card (single, with colored gradient bg) ═══ */
.pq-filter {
  position: relative;
  border-radius: 20px;
  padding: 18px 18px 14px;
  margin-bottom: 12px;
  background:
    linear-gradient(135deg, rgba(238, 242, 255, 0.7) 0%, rgba(252, 231, 243, 0.7) 50%, rgba(254, 243, 199, 0.5) 100%),
    linear-gradient(135deg, #ffffff 0%, #fafbff 100%);
  border: 1.5px solid rgba(99, 102, 241, 0.15);
  box-shadow:
    0 4px 14px rgba(99, 102, 241, 0.08),
    0 1px 0 rgba(255, 255, 255, 0.8) inset;
  overflow: visible;
  transition: all 0.3s ease;
  z-index: 5;
}
.pq-filter::before {
  content: '';
  position: absolute;
  top: -1px; left: 20px;
  width: 60px; height: 3px;
  background: linear-gradient(90deg, var(--ind-500), var(--violet-500), var(--pink-500));
  border-radius: 0 0 4px 4px;
  box-shadow: 0 2px 8px rgba(99, 102, 241, 0.4);
}
.pq-filter::after {
  content: '';
  position: absolute;
  top: 0; right: 0;
  width: 120px; height: 120px;
  background: radial-gradient(circle at top right, rgba(236, 72, 153, 0.08) 0%, transparent 70%);
  border-radius: 0 20px 0 0;
  pointer-events: none;
}
.pq-filter:hover {
  box-shadow:
    0 8px 24px rgba(99, 102, 241, 0.12),
    0 1px 0 rgba(255, 255, 255, 0.8) inset;
  transform: translateY(-1px);
}

/* Smart search (main, prominent) */
.pq-search-block { position: relative; }
.pq-search-label {
  font-size: 11px; font-weight: 800;
  color: var(--ind-700);
  margin-bottom: 6px;
  display: flex; align-items: center; gap: 6px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.pq-search-label i {
  color: var(--ind-500);
  font-size: 13px;
  filter: drop-shadow(0 1px 2px rgba(99, 102, 241, 0.3));
}
.pq-search-label .hint {
  font-size: 9.5px; color: var(--slate-500);
  font-weight: 700; margin-inline-start: auto;
  background: rgba(255, 255, 255, 0.6);
  padding: 2px 8px;
  border-radius: 6px;
  border: 1px solid rgba(99, 102, 241, 0.15);
}
.pq-search-wrap { position: relative; }
.pq-search-wrap input {
  width: 100%;
  padding: 13px 16px 13px 44px;
  border: 2px solid rgba(99, 102, 241, 0.2);
  border-radius: 14px;
  font-size: 14px;
  font-weight: 700;
  color: var(--slate-800);
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(8px);
  transition: all 0.2s ease;
  box-shadow: 0 2px 8px rgba(99, 102, 241, 0.05);
}
.pq-search-wrap input::placeholder { color: var(--slate-400); font-weight: 600; }
.pq-search-wrap input:hover {
  border-color: rgba(99, 102, 241, 0.4);
  box-shadow: 0 4px 12px rgba(99, 102, 241, 0.1);
}
.pq-search-wrap input:focus {
  border-color: var(--ind-500);
  outline: none;
  background: #fff;
  box-shadow: var(--shadow-glow-indigo);
  transform: translateY(-1px);
}
.pq-search-wrap i.abs {
  position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
  color: var(--ind-500); font-size: 15px;
  pointer-events: none;
  filter: drop-shadow(0 1px 2px rgba(99, 102, 241, 0.3));
}
.pq-search-wrap .clr {
  position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
  color: var(--slate-400); font-size: 12px; cursor: pointer;
  width: 24px; height: 24px; border-radius: 50%;
  display: none; align-items: center; justify-content: center;
  background: var(--slate-100);
  border: none;
  transition: all 0.15s ease;
}
.pq-search-wrap .clr:hover { color: #fff; background: #ef4444; transform: translateY(-50%) scale(1.1); }
.pq-search-wrap.has-val .clr { display: flex; }

/* Search results dropdown (smart, colorful) — anchored to filter card, not search input */
.pq-search-res {
  display: none;
  position: absolute;
  top: 100%;
  inset-inline-start: 0;
  inset-inline-end: 0;
  margin-top: 6px;
  background: #fff;
  border: 1.5px solid rgba(99, 102, 241, 0.2);
  border-radius: 14px;
  max-height: 380px;
  overflow-y: auto;
  z-index: 50;
  box-shadow: var(--shadow-xl);
  animation: dropIn 0.18s ease-out;
}
@keyframes dropIn {
  from { opacity: 0; transform: translateY(-8px); }
  to { opacity: 1; transform: translateY(0); }
}
.pq-search-res.on { display: block; }
.pq-sr-h {
  padding: 9px 14px;
  background: linear-gradient(135deg, var(--ind-50), #f5f3ff);
  border-bottom: 1px solid var(--slate-200);
  font-size: 10.5px; font-weight: 800; color: var(--ind-700);
  display: flex; align-items: center; gap: 5px;
  position: sticky; top: 0; z-index: 1;
}
.pq-sr-h .c {
  margin-inline-start: auto; font-size: 10px;
  background: linear-gradient(135deg, var(--ind-500), var(--violet-500));
  color: #fff; padding: 1px 8px; border-radius: 99px;
  font-weight: 800;
  box-shadow: 0 2px 4px rgba(99, 102, 241, 0.3);
}
.pq-sr {
  padding: 9px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--slate-100);
  display: flex; align-items: center; gap: 10px;
  transition: all 0.15s ease;
  position: relative;
}
.pq-sr:hover {
  background: linear-gradient(135deg, var(--ind-50) 0%, #f5f3ff 100%);
  transform: translateX(-3px);
}
.pq-sr:hover .i { transform: scale(1.1) rotate(-3deg); }
.pq-sr:last-child { border-bottom: none; }
.pq-sr .i {
  width: 32px; height: 32px;
  border-radius: 9px;
  background: linear-gradient(135deg, var(--ind-50), var(--ind-100));
  color: var(--ind-600);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; flex-shrink: 0;
  transition: all 0.2s ease;
  box-shadow: 0 2px 6px rgba(99, 102, 241, 0.15);
}
.pq-sr .info { flex: 1; min-width: 0; }
.pq-sr .name {
  font-size: 13px; font-weight: 800; color: var(--slate-800);
  margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  display: flex; align-items: center; gap: 6px;
}
.pq-sr .meta { font-size: 10.5px; color: var(--slate-500); font-weight: 600; }
.pq-sr .meta b { color: var(--ind-700); }
.pq-sr .crit {
  font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 5px;
  flex-shrink: 0;
  box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.pq-sr .crit-A { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #dc2626; }
.pq-sr .crit-B { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }
.pq-sr .crit-C { background: linear-gradient(135deg, #ecfeff, #cffafe); color: #0891b2; }
.pq-sr .match-ico {
  width: 28px; height: 28px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; flex-shrink: 0;
  color: #fff;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  transition: all 0.2s ease;
}
.pq-sr:hover .match-ico { transform: scale(1.1) rotate(3deg); }
.pq-sr-empty {
  padding: 28px 14px; text-align: center;
  color: var(--slate-400); font-size: 12px;
}
.pq-sr-empty i {
  display: block; font-size: 28px; margin-bottom: 8px;
  color: var(--slate-300);
  opacity: 0.6;
}
.pq-sr-loading {
  padding: 20px 14px; text-align: center;
  color: var(--ind-500); font-size: 12px;
}
.pq-sr-loading i { font-size: 18px; }

/* Divider between search and company/model */
.pq-divider {
  display: flex; align-items: center; gap: 10px;
  margin: 14px 0 10px;
  color: var(--slate-500); font-size: 10.5px; font-weight: 800;
  position: relative;
}
.pq-divider::before, .pq-divider::after {
  content: ''; flex: 1; height: 1.5px;
  background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.2), transparent);
}
.pq-divider i {
  color: var(--ind-500);
  font-size: 9px;
  background: rgba(99, 102, 241, 0.08);
  width: 22px; height: 22px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid rgba(99, 102, 241, 0.2);
}

/* Companies row */
.pq-cm-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pq-cm-cell label {
  display: flex; align-items: center; gap: 4px;
  font-size: 10.5px; font-weight: 800;
  color: var(--ind-700);
  margin-bottom: 5px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.pq-cm-cell label i {
  color: var(--ind-500);
  font-size: 11px;
  filter: drop-shadow(0 1px 1px rgba(99, 102, 241, 0.3));
}
.pq-cm-cell select {
  width: 100%;
  padding: 10px 32px 10px 12px;
  border: 1.5px solid rgba(99, 102, 241, 0.2);
  border-radius: 11px;
  font-size: 12.5px; font-weight: 700;
  color: var(--slate-800);
  background: rgba(255, 255, 255, 0.9);
  backdrop-filter: blur(6px);
  cursor: pointer;
  appearance: none;
  -webkit-appearance: none;
  transition: all 0.2s ease;
  box-shadow: 0 2px 6px rgba(99, 102, 241, 0.05);
}
.pq-cm-cell select:hover {
  border-color: rgba(99, 102, 241, 0.5);
  transform: translateY(-1px);
  box-shadow: 0 4px 10px rgba(99, 102, 241, 0.1);
}
.pq-cm-cell select:focus {
  border-color: var(--ind-500);
  outline: none;
  background: #fff;
  box-shadow: var(--shadow-glow-indigo);
}
.pq-cm-cell select:disabled {
  background: rgba(248, 250, 252, 0.7);
  color: var(--slate-400);
  cursor: not-allowed;
  border-style: dashed;
}
.pq-cm-cell { position: relative; }
.pq-cm-cell::after {
  content: '\f107';
  font-family: 'Font Awesome 6 Free'; font-weight: 900;
  position: absolute;
  bottom: 13px; inset-inline-end: 12px;
  color: var(--ind-500); font-size: 12px;
  pointer-events: none;
  transition: transform 0.2s ease;
}
.pq-cm-cell select:focus + ::after { transform: rotate(180deg); }

/* Selected asset card (small, horizontal, glowing) */
.pq-selected {
  display: none;
  background: linear-gradient(135deg, #10b981 0%, #059669 60%, #047857 100%);
  background-size: 200% 200%;
  animation: selectedGlow 4s ease-in-out infinite;
  color: #fff;
  border-radius: 14px;
  padding: 9px 14px;
  margin-bottom: 12px;
  align-items: center;
  gap: 10px;
  box-shadow: var(--shadow-glow-emerald);
  font-size: 12.5px;
  border: 1.5px solid rgba(255, 255, 255, 0.2);
  position: relative;
  overflow: hidden;
}
@keyframes selectedGlow {
  0%, 100% { background-position: 0% 50%; }
  50% { background-position: 100% 50%; }
}
.pq-selected::before {
  content: '';
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
  background: rgba(255, 255, 255, 0.4);
  box-shadow: 0 0 12px rgba(255, 255, 255, 0.6);
}
.pq-selected.on {
  display: flex;
  animation: popIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes popIn {
  0% { opacity: 0; transform: scale(0.9); }
  100% { opacity: 1; transform: scale(1); }
}
.pq-selected .chk {
  width: 26px; height: 26px;
  border-radius: 8px;
  background: rgba(255,255,255,0.3);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; flex-shrink: 0;
  box-shadow: 0 0 12px rgba(255,255,255,0.4);
}
.pq-selected .v {
  font-weight: 800; flex: 1; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  text-shadow: 0 1px 2px rgba(0,0,0,0.1);
}
.pq-selected .v b { font-weight: 900; }
.pq-selected .v .sep { opacity: 0.5; margin: 0 6px; }
.pq-selected .x {
  background: rgba(255,255,255,0.25);
  border: none; color: #fff;
  width: 26px; height: 26px;
  border-radius: 7px;
  cursor: pointer; font-size: 14px; font-weight: 800;
  transition: all 0.15s ease;
  display: flex; align-items: center; justify-content: center;
}
.pq-selected .x:hover { background: rgba(255,255,255,0.4); transform: rotate(90deg) scale(1.1); }
.pq-selected input { display: none; }

/* ═══ Form ═══ */
.pq-form {
  background: #fff;
  border-radius: 20px;
  padding: 18px 20px;
  box-shadow: var(--shadow-md);
  border: 1px solid var(--slate-200);
  position: relative;
  overflow: hidden;
}
.pq-form::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--ind-500), var(--violet-500), var(--pink-500), var(--amber-500));
  background-size: 200% 100%;
  animation: gradShift 6s linear infinite;
}
@keyframes gradShift {
  0% { background-position: 0% 50%; }
  100% { background-position: 200% 50%; }
}
.pq-fh {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 14px; padding-bottom: 10px;
  border-bottom: 1px solid var(--slate-100);
}
.pq-fh .b {
  background: linear-gradient(135deg, var(--ind-500), var(--violet-500));
  color: #fff;
  padding: 4px 11px; border-radius: 99px;
  font-size: 10px; font-weight: 800;
  box-shadow: 0 2px 6px rgba(99, 102, 241, 0.35);
  letter-spacing: 0.3px;
}
.pq-fh h3 { margin: 0; font-size: 14.5px; font-weight: 900; color: var(--slate-800); }
.pq-form-body { display: flex; flex-direction: column; gap: 10px; }
.pq-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }
@media (max-width: 600px) { .pq-row { grid-template-columns: 1fr 1fr; } }
.pq-field label {
  display: flex; align-items: center; gap: 4px;
  font-size: 10.5px; font-weight: 800;
  color: var(--slate-600);
  margin-bottom: 4px;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.pq-field label i { color: var(--ind-500); font-size: 11px; }
.pq-field input, .pq-field select, .pq-field textarea {
  width: 100%;
  padding: 9px 12px;
  border: 1.5px solid var(--slate-200);
  border-radius: 10px;
  font-size: 12.5px; font-weight: 700;
  background: #fff;
  color: var(--slate-800);
  transition: all 0.2s ease;
  font-family: inherit;
}
.pq-field input:hover, .pq-field select:hover, .pq-field textarea:hover {
  border-color: var(--slate-300);
}
.pq-field input:focus, .pq-field select:focus, .pq-field textarea:focus {
  border-color: var(--ind-500);
  outline: none;
  box-shadow: var(--shadow-glow-indigo);
  transform: translateY(-1px);
}
.pq-field textarea { min-height: 38px; resize: vertical; }
.pq-field .req { color: #ef4444; font-weight: 900; }

.pq-exec-tabs {
  display: flex; gap: 0;
  background: linear-gradient(135deg, var(--slate-50), var(--slate-100));
  padding: 4px; border-radius: 11px; margin-bottom: 6px;
  border: 1px solid var(--slate-200);
}
.pq-exec-tab {
  flex: 1; padding: 7px 11px; border-radius: 8px;
  border: none; background: transparent;
  color: var(--slate-500); font-weight: 800; font-size: 11.5px;
  cursor: pointer;
  transition: all 0.2s ease;
  display: flex; align-items: center; justify-content: center; gap: 4px;
}
.pq-exec-tab.on {
  background: #fff;
  color: var(--ind-700);
  box-shadow: 0 2px 8px rgba(99, 102, 241, 0.15);
  transform: translateY(-1px);
}
.pq-exec-pane { display: none; }
.pq-exec-pane.on { display: block; animation: fadeUp 0.2s ease-out; }
@keyframes fadeUp { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }
.pq-exec-flex { display: flex; gap: 5px; }

.pq-lead { display: flex; gap: 5px; }
.pq-lead-c {
  padding: 5px 11px; border: 1.5px solid var(--slate-200);
  border-radius: 7px; cursor: pointer;
  font-weight: 800; font-size: 11px; user-select: none;
  transition: all 0.15s ease;
  background: #fff;
}
.pq-lead-c:hover { border-color: var(--ind-500); color: var(--ind-600); }
.pq-lead-c.on {
  background: linear-gradient(135deg, var(--ind-500), var(--violet-500));
  color: #fff; border-color: transparent;
  box-shadow: 0 2px 6px rgba(99, 102, 241, 0.3);
  transform: scale(1.05);
}

.pq-actions {
  display: flex; gap: 8px;
  padding-top: 14px;
  border-top: 1px solid var(--slate-100);
  margin-top: 4px;
}
.pq-btn {
  padding: 10px 18px; border-radius: 11px;
  border: none; font-weight: 800; font-size: 12px;
  cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px;
  transition: all 0.2s ease;
  font-family: inherit;
}
.pq-btn-primary {
  background: linear-gradient(135deg, var(--ind-500), var(--violet-500));
  color: #fff;
  box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
  position: relative;
  overflow: hidden;
}
.pq-btn-primary::before {
  content: '';
  position: absolute;
  top: 0; left: -100%;
  width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
  transition: left 0.5s ease;
}
.pq-btn-primary:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(99, 102, 241, 0.45);
}
.pq-btn-primary:hover:not(:disabled)::before { left: 100%; }
.pq-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.pq-btn-ghost {
  background: var(--slate-100);
  color: var(--slate-700);
}
.pq-btn-ghost:hover { background: var(--slate-200); transform: translateY(-1px); }

details.pq-adv {
  background: linear-gradient(135deg, var(--slate-50), rgba(238, 242, 255, 0.3));
  border-radius: 11px; padding: 6px 11px;
  border: 1px solid var(--slate-200);
}
details.pq-adv summary {
  font-size: 10.5px; font-weight: 800;
  color: var(--slate-600); text-transform: uppercase;
  cursor: pointer; list-style: none;
  display: flex; align-items: center; gap: 5px; padding: 4px 0;
  letter-spacing: 0.3px;
}
details.pq-adv summary i { color: var(--ind-500); }
details.pq-adv summary::-webkit-details-marker { display: none; }
details.pq-adv summary::after {
  content: '\f078'; font-family: 'Font Awesome 6 Free'; font-weight: 900;
  font-size: 9px; margin-inline-start: auto;
  color: var(--slate-400);
  transition: transform 0.2s ease;
}
details.pq-adv[open] summary::after { transform: rotate(180deg); }
details.pq-adv .adv-body { display: flex; flex-direction: column; gap: 8px; padding-top: 8px; }

/* Modals */
.pq-modal-bg {
  display: none; position: fixed; inset: 0;
  background: rgba(15, 23, 42, 0.6);
  backdrop-filter: blur(8px);
  z-index: 200; align-items: center; justify-content: center;
  padding: 20px;
  animation: fadeIn 0.2s ease-out;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.pq-modal-bg.on { display: flex; }
.pq-modal {
  background: #fff; border-radius: 18px;
  max-width: 400px; width: 100%;
  box-shadow: var(--shadow-xl);
  overflow: hidden;
  animation: popIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.pq-modal-h {
  background: linear-gradient(135deg, var(--ind-500), var(--violet-500));
  color: #fff; padding: 14px 18px;
  display: flex; align-items: center; gap: 8px;
  position: relative;
  overflow: hidden;
}
.pq-modal-h::after {
  content: '';
  position: absolute;
  top: -50%; right: -30%;
  width: 200px; height: 200px;
  background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
  pointer-events: none;
}
.pq-modal-h h3 { margin: 0; font-size: 14px; font-weight: 900; flex: 1; }
.pq-modal-h .x {
  background: rgba(255,255,255,0.2); border: none; color: #fff;
  width: 26px; height: 26px; border-radius: 6px;
  cursor: pointer; font-size: 14px; font-weight: 800;
  display: flex; align-items: center; justify-content: center;
  transition: all 0.15s ease;
}
.pq-modal-h .x:hover { background: rgba(255,255,255,0.35); transform: rotate(90deg); }
.pq-modal-b { padding: 16px 18px; }
.pq-modal-f {
  background: var(--slate-50); padding: 12px 18px;
  display: flex; gap: 8px; justify-content: flex-end;
  border-top: 1px solid var(--slate-200);
}

/* Container max-width (slightly wider for better breathing) */
.pq-wrap { max-width: 880px; margin: 0 auto; }
</style>
</head>
<body class="app-layout">

<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="pq-wrap">
<?php foreach ($flash as $fm): ?><div class="alert alert-<?= e($fm['type']) ?>" style="margin-bottom:10px;font-size:12.5px"><?= e($fm['message']) ?></div><?php endforeach; ?>

<!-- ═══ Hero (Living 2026) ═══ -->
<div class="pq-hero">
  <div class="pq-hero-row">
    <div class="pq-hero-ico"><i class="fa-solid fa-bolt"></i></div>
    <div style="flex:1; min-width:0">
      <h1>إضافة PM سريع</h1>
      <p>ابحث بالتاج/السيريال/الوصف، أو اختر من الشركة/الموديل — اختار جهازك في خطوة</p>
    </div>
    <div class="pq-hero-stat">
      <span class="dot"></span>
      <span><b id="pqTotalPending">—</b> معلّقة</span>
    </div>
  </div>
</div>

<!-- ═══ Filter card (colorful gradient bg) ═══ -->
<div class="pq-filter">
  <!-- Smart search (main) -->
  <div class="pq-search-block">
    <div class="pq-search-label">
      <i class="fa-solid fa-magnifying-glass"></i> ابحث عن الجهاز
      <span class="hint">تاج • سيريال • وصف • NUPCO • رقم أصل</span>
    </div>
    <div class="pq-search-wrap" id="pqSrWrap">
      <i class="fa-solid fa-search abs"></i>
      <input type="text" id="pqSearchQ" placeholder="مثال: MX550 أو BHC1020 أو Philips" autocomplete="off" oninput="pqSearchTyping()">
      <button type="button" class="clr" onclick="pqClearSearch()">×</button>
    </div>
  </div>

  <div class="pq-divider">
    <i class="fa-solid fa-ellipsis-vertical"></i>
    <span>أو اختر من الشركة/الموديل</span>
  </div>

  <!-- Companies row -->
  <div class="pq-cm-row">
    <div class="pq-cm-cell">
      <label><i class="fa-solid fa-industry"></i> الشركة المصنّعة</label>
      <select id="pqMfrSel" onchange="pqPickMfr(this)">
        <option value="">— كل الشركات —</option>
        <option value="__add__">➕ إضافة شركة...</option>
      </select>
    </div>
    <div class="pq-cm-cell">
      <label><i class="fa-solid fa-microchip"></i> الموديل</label>
      <select id="pqModelSel" disabled onchange="pqPickModel(this)">
        <option value="">— اختر الشركة أول —</option>
      </select>
    </div>
  </div>

  <!-- Search results dropdown (positioned relative to filter card) -->
  <div class="pq-search-res" id="pqSearchRes"></div>
</div>

<!-- ═══ Selected asset (small horizontal banner) ═══ -->
<div class="pq-selected" id="pqSelBanner">
  <div class="chk"><i class="fa-solid fa-check"></i></div>
  <div class="v" id="pqSelName">—</div>
  <button type="button" class="x" onclick="pqReset()" title="إزالة">×</button>
  <input type="hidden" name="asset_id" id="pqAssetId" value="">
</div>

<!-- ═══ Form ═══ -->
<form class="pq-form" id="pqForm" autocomplete="off" onsubmit="return pqSubmit(event)">
  <div class="pq-fh">
    <span class="b">PM</span>
    <h3>تفاصيل الصيانة الدورية</h3>
  </div>

  <div class="pq-form-body">
    <div class="pq-row">
      <div class="pq-field">
        <label><i class="fa-regular fa-calendar"></i> التاريخ <span class="req">*</span></label>
        <input type="date" name="next_due" id="pqNextDue" required>
      </div>
      <div class="pq-field">
        <label><i class="fa-solid fa-screwdriver-wrench"></i> النوع <span class="req">*</span></label>
        <select name="pm_type" id="pqType" required>
          <option value="">—</option>
          <option value="كهربائي">كهربائي</option>
          <option value="ميكانيكي">ميكانيكي</option>
          <option value="معايرة">معايرة</option>
          <option value="تنظيف">تنظيف</option>
          <option value="فحص دوري">فحص دوري</option>
          <option value="تحديث برمجي">برمجي</option>
          <option value="اختبار سلامة">سلامة</option>
          <option value="آخر">آخر</option>
        </select>
      </div>
      <div class="pq-field">
        <label><i class="fa-regular fa-clock"></i> المدة (س)</label>
        <input type="number" name="estimated_hours" id="pqHours" min="0.5" step="0.5" placeholder="2">
      </div>
    </div>

    <div class="pq-exec-tabs">
      <button type="button" class="pq-exec-tab on" onclick="pqExecTab('internal', this)">
        <i class="fa-solid fa-house-medical"></i> داخلي
      </button>
      <button type="button" class="pq-exec-tab" onclick="pqExecTab('external', this)">
        <i class="fa-solid fa-truck"></i> خارجي
      </button>
    </div>
    <div class="pq-exec-pane on" data-pane="internal">
      <div class="pq-field">
        <select name="assigned_to_user_id" id="pqUserId">
          <option value="">— اختر المستخدم —</option>
          <?php foreach ($internal_users as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?><?= !empty($u['dept_name']) ? ' — ' . e($u['dept_name']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="pq-exec-pane" data-pane="external">
      <div class="pq-exec-flex">
        <select name="contractor_id" id="pqContractor" disabled style="flex:1">
          <option value="">— اختر الشركة —</option>
          <?php foreach ($contractors as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="pq-btn pq-btn-ghost" onclick="pqAddContractor()" style="padding:6px 10px" title="إضافة متعهد">
          <i class="fa-solid fa-plus"></i>
        </button>
      </div>
    </div>

    <details class="pq-adv">
      <summary><i class="fa-solid fa-sliders"></i> خيارات متقدمة</summary>
      <div class="adv-body">
        <div class="pq-field">
          <label><i class="fa-solid fa-bell"></i> تنبيهات قبل الموعد</label>
          <div class="pq-lead" id="pqLeadTimes">
            <div class="pq-lead-c" data-days="3" onclick="pqToggleLead(this)">3d</div>
            <div class="pq-lead-c on" data-days="7" onclick="pqToggleLead(this)">7d</div>
            <div class="pq-lead-c" data-days="14" onclick="pqToggleLead(this)">14d</div>
            <div class="pq-lead-c" data-days="30" onclick="pqToggleLead(this)">30d</div>
          </div>
          <input type="hidden" name="notify_lead_days" id="pqLeadInput" value="7">
        </div>
        <div class="pq-row">
          <div class="pq-field">
            <label style="display:flex;align-items:center;gap:4px">
              <input type="checkbox" name="is_recurring" id="pqRecurring" value="1" onchange="pqToggleCycle()" style="width:auto">
              تكرار
            </label>
          </div>
          <div class="pq-field">
            <label><i class="fa-solid fa-arrows-rotate"></i> كل (يوم)</label>
            <input type="number" name="cycle_days" id="pqCycleDays" min="1" max="3650" placeholder="90" disabled>
          </div>
          <div class="pq-field">
            <label><i class="fa-solid fa-flag"></i> الأولوية</label>
            <select name="priority" id="pqPriority">
              <option value="low">منخفضة</option>
              <option value="normal" selected>عادية</option>
              <option value="high">عالية</option>
              <option value="urgent">عاجلة</option>
            </select>
          </div>
        </div>
        <div class="pq-field">
          <label><i class="fa-regular fa-note-sticky"></i> ملاحظات</label>
          <textarea name="notes" id="pqNotes" placeholder="اختياري"></textarea>
        </div>
        <div class="pq-field">
          <label><i class="fa-solid fa-paperclip"></i> مرفقات</label>
          <input type="file" name="attachments[]" id="pqFiles" multiple accept="image/*,.pdf,.doc,.docx">
        </div>
      </div>
    </details>
  </div>

  <div class="pq-actions">
    <button type="submit" class="pq-btn pq-btn-primary" id="pqSaveBtn" disabled>
      <i class="fa-solid fa-floppy-disk"></i> حفظ PM
    </button>
    <button type="button" class="pq-btn pq-btn-ghost" onclick="pqSaveAndNew()">
      <i class="fa-solid fa-plus"></i> جديد
    </button>
  </div>
</form>

<!-- Modals (contractor + manufacturer) -->
<div class="pq-modal-bg" id="pqContractorModal" onclick="if(event.target===this) pqCloseContractorModal()">
  <div class="pq-modal">
    <div class="pq-modal-h"><i class="fa-solid fa-truck-fast"></i><h3>إضافة متعهد</h3><button type="button" class="x" onclick="pqCloseContractorModal()">×</button></div>
    <div class="pq-modal-b">
      <div class="pq-field" style="margin-bottom:10px">
        <label>اسم الشركة <span class="req">*</span></label>
        <input type="text" id="pqNewContractorName" placeholder="مثال: شركة الصيانة">
      </div>
      <div class="pq-field" style="margin-bottom:6px">
        <label>ملاحظات</label>
        <textarea id="pqNewContractorNotes" placeholder="تلفون، تخصص..." rows="2"></textarea>
      </div>
      <div id="pqContractorErr" style="display:none;color:#ef4444;background:#fef2f2;padding:6px 10px;border-radius:6px;font-size:11px;margin-top:6px"></div>
    </div>
    <div class="pq-modal-f">
      <button type="button" class="pq-btn pq-btn-ghost" onclick="pqCloseContractorModal()">إلغاء</button>
      <button type="button" class="pq-btn pq-btn-primary" id="pqSaveContractorBtn" onclick="pqSaveNewContractor()"><i class="fa-solid fa-floppy-disk"></i> حفظ</button>
    </div>
  </div>
</div>

<div class="pq-modal-bg" id="pqMfrModal" onclick="if(event.target===this) pqCloseMfrModal()">
  <div class="pq-modal">
    <div class="pq-modal-h"><i class="fa-solid fa-microchip"></i><h3>إضافة شركة</h3><button type="button" class="x" onclick="pqCloseMfrModal()">×</button></div>
    <div class="pq-modal-b">
      <div class="pq-field" style="margin-bottom:10px">
        <label>الاسم <span class="req">*</span></label>
        <input type="text" id="pqNewMfrName" placeholder="Philips" dir="ltr">
      </div>
      <div class="pq-field" style="margin-bottom:6px">
        <label>البلد</label>
        <input type="text" id="pqNewMfrCountry" placeholder="Germany">
      </div>
      <div id="pqMfrErr" style="display:none;color:#ef4444;background:#fef2f2;padding:6px 10px;border-radius:6px;font-size:11px;margin-top:6px"></div>
    </div>
    <div class="pq-modal-f">
      <button type="button" class="pq-btn pq-btn-ghost" onclick="pqCloseMfrModal()">إلغاء</button>
      <button type="button" class="pq-btn pq-btn-primary" id="pqSaveMfrBtn" onclick="pqSaveNewMfr()"><i class="fa-solid fa-floppy-disk"></i> حفظ</button>
    </div>
  </div>
</div>

</div>
</main>
</div>

<script>
const BASE = '<?= $BASE ?>';
let pqSelected = null;
let pqMfrId = null, pqMfrName = null, pqModelName = null;
let pqSearchTimer = null;
let pqSearchMinLen = 2;

document.addEventListener('DOMContentLoaded', () => {
  pqLoadMfrs();
  pqLoadTotalPending();
  pqDefaultDate();
});

function pqDefaultDate() {
  const d = new Date(); d.setDate(d.getDate() + 30);
  document.getElementById('pqNextDue').value = d.toISOString().slice(0, 10);
}

async function pqLoadTotalPending() {
  try {
    const r = await fetch(BASE + '/api/pm_total_pending.php');
    const d = await r.json();
    document.getElementById('pqTotalPending').textContent = d.total ?? 0;
  } catch (_) {}
}

/* ═══ Smart Search (NUPCO-style) ═══ */
function pqSearchTyping() {
  clearTimeout(pqSearchTimer);
  const q = document.getElementById('pqSearchQ').value.trim();
  const res = document.getElementById('pqSearchRes');
  const wrap = document.getElementById('pqSrWrap');
  wrap.classList.toggle('has-val', q.length > 0);
  if (q.length < pqSearchMinLen) {
    res.classList.remove('on');
    res.innerHTML = '';
    return;
  }
  // Show loading
  res.classList.add('on');
  res.innerHTML = '<div class="pq-sr-loading"><i class="fa-solid fa-spinner fa-spin"></i> جاري البحث...</div>';
  pqSearchTimer = setTimeout(async () => {
    try {
      const r = await fetch(BASE + '/api/pm_search_assets.php?q=' + encodeURIComponent(q));
      const d = await r.json();
      const items = d.items || [];
      if (!items.length) {
        res.innerHTML = '<div class="pq-sr-empty"><i class="fa-solid fa-magnifying-glass"></i>لا توجد أجهزة مطابقة لـ "' + escapeHtml(q) + '"</div>';
        return;
      }
      let html = '<div class="pq-sr-h"><i class="fa-solid fa-list"></i> النتائج <span class="c">' + items.length + '</span></div>';
      html += items.slice(0, 10).map(a => {
        const desc = a.description || '—';
        const tag = a.tag_number || '—';
        const assetNo = a.asset_number || '—';
        const mfr = a.manufacturer_name || '';
        const model = a.model_number || '';
        const dept = a.dept_name || '';
        const loc = a.loc_building || '';
        const crit = a.criticality_class
          ? `<span class="crit crit-${a.criticality_class}">${a.criticality_class}</span>`
          : '';
        const matchField = pqDetectMatch(q, a);
        const assetData = encodeURIComponent(JSON.stringify(a));
        return `<div class="pq-sr" onclick='pqPickSearchAsset(${a.id}, decodeURIComponent("${assetData.replace(/'/g, "%27")}"))'>
          <div class="i"><i class="fa-solid fa-cube"></i></div>
          <div class="info">
            <div class="name">${escapeHtml(desc)} ${crit}</div>
            <div class="meta">تاج: <b>${escapeHtml(tag)}</b> ${assetNo !== '—' ? '· رقم: <b>' + escapeHtml(assetNo) + '</b>' : ''} ${mfr ? '· ' + escapeHtml(mfr) : ''} ${model ? ' ' + escapeHtml(model) : ''} ${dept ? '· ' + escapeHtml(dept) : ''}</div>
          </div>
          <span class="match-ico" style="background:linear-gradient(135deg, ${matchField.color1}, ${matchField.color2})" title="${matchField.label}"><i class="fa-solid ${matchField.icon}"></i></span>
        </div>`;
      }).join('');
      if (items.length > 10) {
        html += '<div class="pq-sr-h" style="background:var(--slate-50);color:var(--slate-500)">يظهر أول 10 من ' + items.length + ' — حسّن البحث لنتائج أدق</div>';
      }
      res.innerHTML = html;
    } catch (e) {
      res.innerHTML = '<div class="pq-sr-empty"><i class="fa-solid fa-circle-exclamation"></i>خطأ في البحث</div>';
    }
  }, 220);
}

function pqDetectMatch(q, asset) {
  q = q.toLowerCase();
  if (asset.tag_number && asset.tag_number.toLowerCase().includes(q))
    return {icon: 'fa-tag', color1: '#10b981', color2: '#059669', label: 'تاج'};
  if (asset.asset_number && asset.asset_number.toLowerCase().includes(q))
    return {icon: 'fa-hashtag', color1: '#3b82f6', color2: '#2563eb', label: 'رقم أصل'};
  if (asset.serial_number && asset.serial_number.toLowerCase().includes(q))
    return {icon: 'fa-barcode', color1: '#8b5cf6', color2: '#7c3aed', label: 'سيريال'};
  if (asset.manufacturer_name && asset.manufacturer_name.toLowerCase().includes(q))
    return {icon: 'fa-industry', color1: '#f59e0b', color2: '#d97706', label: 'شركة'};
  if (asset.model_number && asset.model_number.toLowerCase().includes(q))
    return {icon: 'fa-microchip', color1: '#ec4899', color2: '#db2777', label: 'موديل'};
  if (asset.item_code && asset.item_code.toLowerCase().includes(q))
    return {icon: 'fa-barcode', color1: '#06b6d4', color2: '#0891b2', label: 'NUPCO'};
  return {icon: 'fa-cube', color1: '#94a3b8', color2: '#64748b', label: 'وصف'};
}

function pqClearSearch() {
  document.getElementById('pqSearchQ').value = '';
  document.getElementById('pqSearchRes').classList.remove('on');
  document.getElementById('pqSrWrap').classList.remove('has-val');
  document.getElementById('pqSearchQ').focus();
}

function escapeHtml(s) {
  if (!s) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function pqPickSearchAsset(id, assetData) {
  document.getElementById('pqSearchRes').classList.remove('on');
  // First try: use the data we already have from search
  let d = null;
  if (assetData) {
    try { d = JSON.parse(assetData); } catch (_) {}
  }
  // Fallback: API lookup by id (for single-result auto-pick or mfr/model)
  if (!d || !d.id) {
    try {
      const r = await fetch(BASE + '/api/asset_lookup.php?id=' + id);
      const j = await r.json();
      if (j && j.id) d = j;
    } catch (_) {}
  }
  if (!d || !d.id) {
    alert('تعذر تحديد الجهاز — أعد المحاولة');
    return;
  }
  pqSelected = d;
  document.getElementById('pqAssetId').value = id;
  document.getElementById('pqSelBanner').classList.add('on');
  document.getElementById('pqSelName').innerHTML = `<b>${escapeHtml(d.tag_number || '—')}</b><span class="sep">|</span>${escapeHtml(d.description || '—')}`;
  document.getElementById('pqSaveBtn').disabled = false;
  document.getElementById('pqSearchQ').value = '';
  document.getElementById('pqSrWrap').classList.remove('has-val');
}

document.addEventListener('click', e => {
  if (!e.target.closest('.pq-filter')) {
    document.getElementById('pqSearchRes').classList.remove('on');
  }
});

/* ═══ Companies (2 dropdowns) ═══ */
async function pqLoadMfrs() {
  const r = await fetch(BASE + '/api/pm_manufacturers.php');
  const d = await r.json();
  const sel = document.getElementById('pqMfrSel');
  const base = sel.innerHTML.split('➕')[0];
  sel.innerHTML = base + '<option value="__add__">➕ إضافة شركة...</option>';
  (d.items || []).forEach(m => {
    const opt = document.createElement('option');
    opt.value = m.id;
    opt.textContent = `${m.name_en || m.name} (${m.asset_count || 0})`;
    opt.dataset.name = m.name_en || m.name;
    sel.appendChild(opt);
  });
}

function pqPickMfr(sel) {
  const v = sel.value;
  if (v === '__add__') { pqAddMfr(); sel.value = ''; return; }
  const ms = document.getElementById('pqModelSel');
  ms.innerHTML = '<option value="">— اختر الشركة أول —</option>';
  ms.disabled = true;
  if (!v) {
    pqMfrId = null; pqMfrName = null; pqModelName = null;
    return;
  }
  pqMfrId = parseInt(v);
  pqMfrName = sel.options[sel.selectedIndex].dataset.name;
  fetch(BASE + '/api/pm_models.php?manufacturer_id=' + pqMfrId).then(r => r.json()).then(d => {
    ms.innerHTML = '<option value="">— كل الموديلات —</option>';
    (d.items || []).forEach(m => {
      const opt = document.createElement('option');
      opt.value = m.model_number;
      opt.textContent = `${m.model_number} (${m.asset_count || 0})`;
      ms.appendChild(opt);
    });
    ms.disabled = false;
    pqLoadAssetsByMfr(pqMfrName, '');
  });
}

function pqPickModel(sel) {
  if (!sel.value) { pqModelName = null; pqLoadAssetsByMfr(pqMfrName, ''); return; }
  pqModelName = sel.value;
  pqLoadAssetsByMfr(pqMfrName, sel.value);
}

async function pqLoadAssetsByMfr(mfr, model) {
  const filter = {manufacturer_name: mfr};
  if (model) filter.model_number = model;
  const r = await fetch(BASE + '/api/pm_search_assets.php?' + new URLSearchParams(filter));
  const d = await r.json();
  const items = d.items || [];
  if (items.length === 1) {
    pqPickSearchAsset(items[0].id);
  } else if (items.length > 1) {
    const res = document.getElementById('pqSearchRes');
    res.classList.add('on');
    res.innerHTML = '<div class="pq-sr-h"><i class="fa-solid fa-list"></i> النتائج <span class="c">' + items.length + '</span></div>' +
      items.slice(0, 10).map(a => {
        const desc = a.description || '—';
        const tag = a.tag_number || '—';
        const mfr2 = a.manufacturer_name || '';
        const model2 = a.model_number || '';
        const assetData = encodeURIComponent(JSON.stringify(a));
        return `<div class="pq-sr" onclick='pqPickSearchAsset(${a.id}, decodeURIComponent("${assetData.replace(/'/g, "%27")}"))'>
          <div class="i"><i class="fa-solid fa-cube"></i></div>
          <div class="info">
            <div class="name">${escapeHtml(desc)}</div>
            <div class="meta">تاج: <b>${escapeHtml(tag)}</b> · ${escapeHtml(mfr2)} ${escapeHtml(model2)}</div>
          </div>
        </div>`;
      }).join('');
  } else {
    const res = document.getElementById('pqSearchRes');
    res.classList.add('on');
    res.innerHTML = '<div class="pq-sr-empty"><i class="fa-solid fa-magnifying-glass"></i>لا توجد أجهزة لهذه الشركة</div>';
  }
}

function pqReset() {
  pqSelected = null;
  document.getElementById('pqAssetId').value = '';
  document.getElementById('pqSelBanner').classList.remove('on');
  document.getElementById('pqSaveBtn').disabled = true;
  document.getElementById('pqSearchQ').value = '';
  document.getElementById('pqSearchRes').classList.remove('on');
  document.getElementById('pqSrWrap').classList.remove('has-val');
  document.getElementById('pqMfrSel').value = '';
  document.getElementById('pqModelSel').innerHTML = '<option value="">— اختر الشركة أول —</option>';
  document.getElementById('pqModelSel').disabled = true;
  pqMfrId = null; pqMfrName = null; pqModelName = null;
}

function pqExecTab(name, el) {
  document.querySelectorAll('.pq-exec-tab').forEach(t => t.classList.remove('on'));
  el.classList.add('on');
  document.querySelectorAll('.pq-exec-pane').forEach(p => p.classList.remove('on'));
  document.querySelector('.pq-exec-pane[data-pane="' + name + '"]').classList.add('on');
  document.getElementById('pqUserId').disabled = (name !== 'internal');
  document.getElementById('pqUserId').required = (name === 'internal');
  document.getElementById('pqContractor').disabled = (name !== 'external');
  document.getElementById('pqContractor').required = (name === 'external');
}
function pqToggleLead(el) {
  el.classList.toggle('on');
  const days = [...document.querySelectorAll('.pq-lead-c.on')].map(c => c.dataset.days).join(',');
  document.getElementById('pqLeadInput').value = days || '7';
}
function pqToggleCycle() {
  const on = document.getElementById('pqRecurring').checked;
  document.getElementById('pqCycleDays').disabled = !on;
  document.getElementById('pqCycleDays').required = on;
  if (on && !document.getElementById('pqCycleDays').value) document.getElementById('pqCycleDays').value = 90;
}

async function pqSubmit(e, addAnother = false) {
  e.preventDefault();
  if (!pqSelected || !pqSelected.id) { alert('اختر جهاز أولاً'); return; }
  const btn = document.getElementById('pqSaveBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>...';
  const fd = new FormData(document.getElementById('pqForm'));
  // CRITICAL: asset_id input is OUTSIDE the form (in the green banner), so add it explicitly
  fd.set('asset_id', pqSelected.id);
  // is_recurring + cycle_days: ensure they're always sent (cycle_days input is disabled when not recurring)
  const recurringBox = document.getElementById('pqRecurring');
  const cycleInput = document.getElementById('pqCycleDays');
  if (recurringBox && recurringBox.checked) {
    fd.set('is_recurring', '1');
    if (cycleInput && cycleInput.value) fd.set('cycle_days', cycleInput.value);
  } else {
    fd.delete('is_recurring');
    fd.set('cycle_days', '0');
  }
  const internalActive = document.querySelector('.pq-exec-tab.on').textContent.includes('داخلي');
  if (internalActive) fd.set('contractor_id', '');
  else fd.set('assigned_to_user_id', '');
  try {
    const r = await fetch(BASE + '/api/pm_quick_save.php', {method: 'POST', body: fd});
    const d = await r.json();
    if (d.ok) {
      btn.innerHTML = '<i class="fa-solid fa-check"></i> تم — ' + (d.pm_id || '');
      btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
      setTimeout(() => {
        pqReset();
        pqDefaultDate();
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ PM';
        btn.style.background = '';
        // Show success toast
        pqToast('تم الحفظ بنجاح — PM #' + (d.pm_id || ''));
      }, 700);
    } else {
      alert(d.msg || 'فشل الحفظ');
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ PM';
    }
  } catch (e) {
    alert('خطأ في الاتصال: ' + e.message);
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ PM';
  }
}
function pqSaveAndNew(e) { pqSubmit(e, true); }

/* ═══ Toast (success notification) ═══ */
function pqToast(msg, type = 'success') {
  let t = document.getElementById('pqToast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'pqToast';
    t.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%) translateY(-80px);z-index:9999;padding:12px 20px;border-radius:12px;font-weight:800;font-size:13px;backdrop-filter:blur(12px);box-shadow:0 10px 30px rgba(0,0,0,0.2);transition:transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1),opacity 0.3s ease;opacity:0;';
    document.body.appendChild(t);
  }
  if (type === 'success') {
    t.style.background = 'linear-gradient(135deg, #10b981, #059669)';
    t.style.color = '#fff';
    t.style.border = '1px solid rgba(255,255,255,0.2)';
  } else {
    t.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
    t.style.color = '#fff';
    t.style.border = '1px solid rgba(255,255,255,0.2)';
  }
  t.innerHTML = '<i class="fa-solid fa-' + (type === 'success' ? 'check-circle' : 'circle-exclamation') + '"></i> ' + msg;
  setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(0)'; t.style.opacity = '1'; }, 10);
  setTimeout(() => { t.style.transform = 'translateX(-50%) translateY(-80px)'; t.style.opacity = '0'; }, 3200);
}

/* ═══ Modals (inline add) ═══ */
function pqAddContractor() {
  const ext = document.querySelector('.pq-exec-tab[onclick*="external"]');
  if (ext && !ext.classList.contains('on')) pqExecTab('external', ext);
  document.getElementById('pqContractorModal').classList.add('on');
  document.getElementById('pqNewContractorName').value = '';
  document.getElementById('pqNewContractorNotes').value = '';
  document.getElementById('pqContractorErr').style.display = 'none';
  setTimeout(() => document.getElementById('pqNewContractorName').focus(), 100);
}
function pqCloseContractorModal() { document.getElementById('pqContractorModal').classList.remove('on'); }
async function pqSaveNewContractor() {
  const name = document.getElementById('pqNewContractorName').value.trim();
  const notes = document.getElementById('pqNewContractorNotes').value.trim();
  const errEl = document.getElementById('pqContractorErr');
  errEl.style.display = 'none';
  if (!name) { errEl.textContent = 'الاسم مطلوب'; errEl.style.display = 'block'; return; }
  const btn = document.getElementById('pqSaveContractorBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>...';
  const fd = new FormData();
  fd.append('name', name); fd.append('notes', notes);
  try {
    const r = await fetch(BASE + '/api/pm_add_contractor.php', {method: 'POST', body: fd});
    const d = await r.json();
    if (d.ok) {
      const sel = document.getElementById('pqContractor');
      const opt = document.createElement('option');
      opt.value = d.id; opt.textContent = d.name; opt.selected = true;
      sel.appendChild(opt);
      pqCloseContractorModal();
      btn.innerHTML = '<i class="fa-solid fa-check"></i> تم';
      setTimeout(() => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ'; }, 1200);
    } else {
      errEl.textContent = d.msg || 'فشل';
      errEl.style.display = 'block';
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ';
    }
  } catch (e) {
    errEl.textContent = 'خطأ في الاتصال';
    errEl.style.display = 'block';
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ';
  }
}
document.getElementById('pqNewContractorName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); pqSaveNewContractor(); } });

function pqAddMfr() {
  document.getElementById('pqMfrModal').classList.add('on');
  document.getElementById('pqNewMfrName').value = '';
  document.getElementById('pqNewMfrCountry').value = '';
  document.getElementById('pqMfrErr').style.display = 'none';
  setTimeout(() => document.getElementById('pqNewMfrName').focus(), 100);
}
function pqCloseMfrModal() { document.getElementById('pqMfrModal').classList.remove('on'); }
async function pqSaveNewMfr() {
  const name = document.getElementById('pqNewMfrName').value.trim();
  const country = document.getElementById('pqNewMfrCountry').value.trim();
  const errEl = document.getElementById('pqMfrErr');
  errEl.style.display = 'none';
  if (!name) { errEl.textContent = 'الاسم مطلوب'; errEl.style.display = 'block'; return; }
  const btn = document.getElementById('pqSaveMfrBtn');
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>...';
  const fd = new FormData();
  fd.append('name', name); fd.append('country', country);
  try {
    const r = await fetch(BASE + '/api/manufacturers.php?action=create', {method: 'POST', body: fd});
    const d = await r.json();
    if (d.ok) {
      const sel = document.getElementById('pqMfrSel');
      const opt = document.createElement('option');
      opt.value = d.id; opt.textContent = d.name_en || d.name; opt.dataset.name = d.name_en || d.name; opt.selected = true;
      sel.appendChild(opt);
      pqCloseMfrModal();
      btn.innerHTML = '<i class="fa-solid fa-check"></i> تم';
      setTimeout(() => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ'; }, 1200);
      pqPickMfr(sel);
    } else {
      errEl.textContent = d.msg || 'فشل';
      errEl.style.display = 'block';
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ';
    }
  } catch (e) {
    errEl.textContent = 'خطأ في الاتصال';
    errEl.style.display = 'block';
    btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> حفظ';
  }
}
document.getElementById('pqNewMfrName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); pqSaveNewMfr(); } });
</script>

</body>
</html>
