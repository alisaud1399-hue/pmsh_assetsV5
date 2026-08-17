<?php
/**
 * index.php — صفحة الهبوط الثنائية (عربي / إنجليزي)
 * المستخدم المسجل يُوجَّه تلقائياً إلى dashboard.php
 */
require_once __DIR__ . '/config.php';

// إن كان مسجلاً → إلى لوحة التحكم
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$h_name_ar = get_setting('hospital_name', 'مستشفى الأمير مشاري بن سعود');
$h_name_en = 'Prince Meshari bin Saud Hospital';
$cluster_ar = get_setting('health_cluster', 'تجمع الباحة الصحي');
$cluster_en  = 'Al-Baha Health Cluster';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($h_name_ar) ?> — منصة إدارة الأصول</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800;900&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
  --dark-1:  #03080f;
  --dark-2:  #050d1a;
  --dark-3:  #0a1a30;
  --dark-4:  #0f2545;
  --mid:     #1a3a6b;
  --teal:    #00ACC1;
  --teal-br: #00D4E8;
  --teal-glow: rgba(0,172,193,.22);
  --blue:    #1565C0;
  --blue-br: #2196F3;
  --white:   #FFFFFF;
  --off-white: rgba(255,255,255,.88);
  --muted:   rgba(255,255,255,.50);
  --faint:   rgba(255,255,255,.22);
  --faint2:  rgba(255,255,255,.08);
  --border-glow: rgba(0,172,193,.28);
  --light-bg: #f1f5f9;
  --card-bg:  #ffffff;
  --text-1:   #0f172a;
  --text-2:   #334155;
  --text-3:   #64748b;
  --r-md: 12px;
  --r-lg: 18px;
  --r-xl: 24px;
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body {
  font-family: 'Tajawal', sans-serif;
  background: var(--dark-1);
  color: var(--white);
  overflow-x: hidden;
  direction: rtl;
}
.en { font-family: 'Inter', sans-serif; direction: ltr; unicode-bidi: embed; }
img { max-width:100%; display:block; }

/* ═══════════════════════════════════════════════
   SPLASH SCREEN (شاشة الانترو)
═══════════════════════════════════════════════ */
.splash-screen {
  position: fixed;
  inset: 0;
  background: linear-gradient(-45deg, var(--dark-1), var(--dark-3), var(--mid), var(--dark-4));
  background-size: 400% 400%;
  animation: gradMove 5s ease infinite;
  z-index: 999999;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  transition: opacity 0.8s ease, visibility 0.8s ease, transform 0.8s ease;
}
.splash-screen.hidden {
  opacity: 0;
  visibility: hidden;
  transform: scale(1.05); /* تكبير خفيف عند الاختفاء */
  pointer-events: none;
}
.splash-center {
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
}

/* حركة الشعار */
.splash-logo-pop {
  animation: popIn 0.8s cubic-bezier(0.175, 0.885, 0.32, 1.275) both;
}
@keyframes popIn {
  0% { transform: scale(0); opacity: 0; }
  100% { transform: scale(1); opacity: 1; }
}
.splash-logo-wrapper {
  width: 110px; height: 110px;
  margin: 0 auto 24px;
  background: var(--faint2);
  border: 2px solid var(--border-glow);
  border-radius: 28px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 10px 40px rgba(0,172,193,0.3);
  position: relative;
}
.splash-logo-wrapper::before {
  content: ''; position: absolute; inset: -8px; border-radius: 36px;
  border: 1px dashed var(--teal); animation: spin 10s linear infinite; opacity: 0.6;
}
.splash-logo-img { width: 75px; height: 75px; object-fit: contain; position: relative; z-index: 2; }

/* تأثير الكيبورد (السطور) */
.splash-typing-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  margin-bottom: 30px;
  min-height: 120px; /* لحجز المساحة حتى لا تقفز العناصر أثناء الكتابة */
}
.sp-line {
  position: relative;
  display: inline-block;
  opacity: 0; /* مخفي قبل بدء الكتابة */
  margin: 0;
}
.sp-line::after {
  content: '|';
  position: absolute;
  margin-right: 4px;
  margin-left: 4px;
  opacity: 0;
  color: var(--teal);
  animation: blink 0.7s infinite;
}
.sp-line.typing-active { opacity: 1; }
.sp-line.typing-active::after { opacity: 1; }
.sp-line.typing-done { opacity: 1; }
.sp-line.typing-done::after { display: none; } /* إخفاء المؤشر عند الانتهاء من السطر */

@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }

/* تنسيقات نصوص شاشة التحميل */
.sp-title-ar { font-size: 24px; font-weight: 800; color: var(--white); margin-bottom: 4px; min-height: 30px; }
.sp-title-en { font-size: 14px; font-family: 'Inter', sans-serif; color: var(--muted); letter-spacing: 0.05em; min-height: 20px; }
.sp-gap { height: 16px; width: 100%; }
.sp-highlight { font-size: 22px; font-weight: 900; color: var(--teal-br); margin-bottom: 4px; min-height: 28px; }
.sp-sub-ar { font-size: 16px; font-weight: 500; color: var(--off-white); min-height: 22px; }

/* شريط التحميل */
.splash-progress {
  width: 220px; height: 4px; background: rgba(255,255,255,0.1);
  border-radius: 10px; margin: 0 auto; overflow: hidden; position: relative;
  animation: fadeUp 0.8s ease 0.5s both;
}
.splash-progress-bar {
  position: absolute; top: 0; left: 0; bottom: 0; width: 0%;
  background: linear-gradient(90deg, var(--teal), var(--blue-br));
  border-radius: 10px;
  /* مدة امتلاء الشريط حوالي 4.5 ثواني */
  animation: loadProgress 4.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
}
@keyframes loadProgress {
  0% { width: 0%; }
  30% { width: 45%; }
  60% { width: 70%; }
  90% { width: 95%; }
  100% { width: 100%; }
}

/* ═══════════════════════════════════════════════
   NAVBAR
═══════════════════════════════════════════════ */
.navbar {
  position: fixed;
  top: 0; left: 0; right: 0;
  height: 66px;
  background: rgba(5,13,26,.80);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border-bottom: 1px solid var(--faint);
  z-index: 1000;
  display: flex;
  align-items: center;
  padding: 0 5%;
  gap: 16px;
}
.nav-brand {
  display: flex; align-items: center; gap: 11px;
  text-decoration: none;
}
.nav-logo-box {
  width: 38px; height: 38px;
  background: linear-gradient(135deg, var(--blue), var(--teal));
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 4px 14px rgba(0,172,193,.4);
}
.nav-logo-box i { font-size: 17px; color: var(--white); }
.nav-name-ar { font-size: 13.5px; font-weight: 700; color: var(--white); line-height: 1.2; }
.nav-name-en { font-size: 10.5px; color: var(--muted); margin-top: 2px; }

/* System name block — far left (inline-end in RTL) */
.nav-sys { margin-inline-start: auto; display: flex; flex-direction: column; align-items: flex-end; justify-content: center; }
.ns-ar { font-size: 15px; font-weight: 800; color: var(--white); line-height: 1.2; margin-bottom: 2px; }
.ns-en { font-size: 11px; font-weight: 500; color: var(--teal-br); font-family:'Inter',sans-serif; letter-spacing: 0.04em; }

/* ═══════════════════════════════════════════════
   HERO
═══════════════════════════════════════════════ */
.hero {
  min-height: 100vh;
  background: linear-gradient(-45deg, var(--dark-1), var(--dark-3), var(--mid), var(--dark-4));
  background-size: 400% 400%;
  animation: gradMove 22s ease infinite;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 90px 5% 60px;
  text-align: center;
}
@keyframes gradMove {
  0%,100% { background-position: 0% 50%; }
  50%      { background-position: 100% 50%; }
}

/* Decorative background elements */
.hero-bg-circle {
  position: absolute; border-radius: 50%;
  background: radial-gradient(circle, var(--teal-glow), transparent 65%);
  animation: breathe 7s ease-in-out infinite;
  pointer-events: none;
}
.hero-bg-circle:nth-child(1) { width:700px;height:700px;top:-180px;right:-180px;animation-delay:0s; }
.hero-bg-circle:nth-child(2) { width:500px;height:500px;bottom:-100px;left:-100px;animation-delay:3s; }
.hero-bg-circle:nth-child(3) { width:300px;height:300px;top:50%;left:10%;transform:translateY(-50%);opacity:.5;animation-delay:5s; }
@keyframes breathe {
  0%,100%{ opacity:.7; transform:scale(1); }
  50%    { opacity:1; transform:scale(1.08); }
}

/* Grid texture overlay */
.hero-grid {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px);
  background-size: 50px 50px;
  pointer-events: none;
}

/* الأيقونات العائمة */
.floating-icon {
  position: absolute;
  font-size: 26vw;
  color: var(--white);
  opacity: 0.025;
  pointer-events: none;
  z-index: 0;
  animation: floatAnim 15s ease-in-out infinite;
}
.floating-icon.right-side { right: -5%; top: 15%; animation-delay: 0s; }
.floating-icon.left-side { left: -5%; bottom: 10%; animation-delay: -7s; }
@keyframes floatAnim {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-40px) rotate(8deg); }
}

/* Logo */
.hero-logo {
  width: 130px; height: 130px;
  background: var(--faint2);
  border: 2px solid var(--border-glow);
  border-radius: 32px;
  display: flex; align-items: center; justify-content: center;
  margin: 0 auto 32px;
  position: relative;
  z-index: 1;
  animation: fadeUp .6s ease .1s both;
}
.hero-logo::before {
  content:'';
  position:absolute; inset:-10px;
  border-radius:42px;
  border:1px dashed rgba(0,172,193,.22);
  animation:spin 25s linear infinite;
}
.hero-logo::after {
  content:'';
  position:absolute; inset:-20px;
  border-radius:52px;
  border:1px dashed rgba(0,172,193,.1);
  animation:spin 40s linear infinite reverse;
}
@keyframes spin { to { transform:rotate(360deg); } }
.hero-logo-img { width: 90px; height: 90px; object-fit: contain; border-radius: 0; position: relative; z-index: 2; }

/* System name */
.hero-pill {
  display: inline-flex; align-items: center; gap: 7px;
  background: rgba(0,172,193,.12);
  border: 1px solid rgba(0,172,193,.28);
  border-radius: 50px;
  padding: 6px 18px;
  margin-bottom: 20px;
  position: relative; z-index:1;
  animation: fadeUp .55s ease .2s both;
}
.hero-pill-dot { width:7px;height:7px;border-radius:50%;background:var(--teal);animation:pls 2s ease infinite; }
@keyframes pls { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.8)} }
.hero-pill span { font-size:12px;color:rgba(255,255,255,.75);letter-spacing:.04em; }
.hero-name-ar {
  font-size: clamp(28px, 5vw, 50px);
  font-weight: 900;
  color: var(--white);
  line-height: 1.15;
  margin-bottom: 8px;
  position: relative; z-index:1;
  animation: fadeUp .55s ease .3s both;
  background: linear-gradient(135deg, #fff 30%, rgba(0,212,232,.85));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.hero-name-en {
  font-size: clamp(14px, 2.5vw, 22px);
  font-weight: 300;
  color: var(--muted);
  letter-spacing: .06em;
  margin-bottom: 28px;
  position: relative; z-index:1;
  animation: fadeUp .55s ease .38s both;
  font-family:'Inter',sans-serif;
}
.hero-hospital {
  display: flex; align-items: center; justify-content: center;
  gap: 14px; flex-wrap: wrap;
  margin-bottom: 42px;
  position: relative; z-index:1;
  animation: fadeUp .55s ease .46s both;
}
.hero-h-pill {
  background: var(--faint2);
  border: 1px solid var(--faint);
  border-radius: 10px;
  padding: 10px 22px;
  text-align: center;
}
.hero-h-pill .h-ar { font-size: 14px; font-weight: 600; color: var(--off-white); }
.hero-h-pill .h-en { font-size: 11px; color: var(--muted); font-family:'Inter',sans-serif; margin-top:2px; }
.hero-sep { color:var(--faint);font-size:18px; }

/* CTA */
.hero-cta {
  display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
  position: relative; z-index:1;
  animation: fadeUp .55s ease .54s both;
}
.btn-cta-primary {
  display: inline-flex; align-items: center; gap: 10px;
  background: linear-gradient(135deg, var(--blue), var(--teal-br));
  color: var(--white);
  padding: 16px 40px;
  border-radius: 14px;
  font-size: 17px; font-weight: 700;
  text-decoration: none;
  box-shadow: 0 10px 36px rgba(0,172,193,.4);
  transition: transform .2s, box-shadow .2s;
}
.btn-cta-primary:hover { transform:translateY(-3px); box-shadow:0 20px 50px rgba(0,172,193,.55); }
.btn-cta-primary i { font-size:18px; }
.btn-cta-outline {
  display: inline-flex; align-items: center; gap: 10px;
  background: var(--faint2);
  border: 1.5px solid var(--faint);
  color: var(--off-white);
  padding: 16px 32px;
  border-radius: 14px;
  font-size: 15px; font-weight: 500;
  text-decoration: none;
  transition: all .2s;
}
.btn-cta-outline:hover { background:var(--faint); border-color:rgba(255,255,255,.3); }

/* Scroll indicator */
.scroll-indicator {
  position: absolute; bottom: 28px; left: 50%; transform: translateX(-50%);
  display: flex; flex-direction: column; align-items: center; gap: 5px;
  color: var(--faint); font-size: 11px;
  animation: bobble 2.5s ease infinite;
  z-index: 1;
}
@keyframes bobble {
  0%,100%{ transform:translateX(-50%) translateY(0); }
  50%     { transform:translateX(-50%) translateY(8px); }
}
.scroll-indicator i { font-size: 18px; }

/* Feature tags */
.hero-tags {
  display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
  margin-top: 36px;
  position: relative; z-index:1;
  animation: fadeUp .55s ease .62s both;
}
.hero-tag {
  display: inline-flex; align-items: center; gap: 10px;
  background: rgba(255,255,255,.04);
  border: 1px solid rgba(255,255,255,.1);
  border-radius: 14px;
  padding: 8px 18px;
  transition: background .2s, border-color .2s;
}
.hero-tag:hover {
  background: rgba(255,255,255,.07);
  border-color: rgba(0,172,193,.3);
}
.hero-tag i { font-size: 18px; color: var(--teal); }
.tag-txt { display: flex; flex-direction: column; align-items: flex-start; }
.tag-ar { font-size: 13px; font-weight: 600; color: var(--off-white); line-height: 1.2; }
.tag-en { font-size: 10.5px; color: rgba(255,255,255,.45); font-family: 'Inter', sans-serif; margin-top: 3px; letter-spacing: 0.02em; }

/* ═══════════════════════════════════════════════
   FEATURES SECTION
═══════════════════════════════════════════════ */
.features-section {
  background: var(--light-bg);
  padding: 90px 5%;
}
.section-header {
  text-align: center;
  margin-bottom: 56px;
}
.section-kicker {
  display: inline-flex; align-items: center; gap: 7px;
  font-size: 12px; font-weight: 600;
  color: var(--blue);
  letter-spacing: .08em;
  text-transform: uppercase;
  background: rgba(21,101,192,.08);
  border: 1px solid rgba(21,101,192,.18);
  border-radius: 50px;
  padding: 5px 14px;
  margin-bottom: 14px;
}
.section-title-ar { font-size: 32px; font-weight: 800; color: var(--text-1); margin-bottom: 5px; }
.section-title-en { font-size: 16px; color: var(--text-3); font-family:'Inter',sans-serif; font-weight:400; }
.modules-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  max-width: 1100px;
  margin: 0 auto;
}
.module-card {
  background: var(--card-bg);
  border-radius: 20px;
  padding: 30px 26px;
  border: 1.5px solid #e2e8f0;
  transition: transform .2s, box-shadow .2s, border-color .2s;
  display: flex; flex-direction: column; align-items: flex-start;
  text-align: right;
}
.module-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 20px 50px rgba(0,0,0,.08);
  border-color: #bfdbfe;
}
.module-card-icon {
  width: 58px; height: 58px;
  border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  margin-bottom: 18px;
  font-size: 26px;
}
.module-card-name-ar { font-size: 16px; font-weight: 700; color: var(--text-1); margin-bottom: 3px; }
.module-card-name-en { font-size: 12px; color: var(--text-3); font-family:'Inter',sans-serif; margin-bottom: 10px; }
.module-card-desc-ar { font-size: 13px; color: var(--text-2); line-height: 1.65; margin-bottom: 4px; }
.module-card-desc-en { font-size: 11.5px; color: var(--text-3); font-family:'Inter',sans-serif; line-height: 1.5; }

/* ═══════════════════════════════════════════════
   STATS SECTION
═══════════════════════════════════════════════ */
.stats-section {
  padding: 90px 5%;
  background: linear-gradient(135deg, var(--dark-2), var(--dark-3));
  position: relative;
  overflow: hidden;
}
.stats-section::before {
  content:''; position:absolute; inset:0;
  background:
    radial-gradient(ellipse at 20% 60%, rgba(0,172,193,.12) 0%, transparent 55%),
    radial-gradient(ellipse at 80% 30%, rgba(21,101,192,.12) 0%, transparent 55%);
  pointer-events:none;
}
.stats-section::after {
  content:''; position:absolute; inset:0;
  background-image:
    linear-gradient(rgba(255,255,255,.02) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,255,255,.02) 1px, transparent 1px);
  background-size:40px 40px;
  pointer-events:none;
}
.stats-header {
  text-align: center; margin-bottom: 60px; position: relative; z-index:1;
}
.stats-title-ar { font-size: 30px; font-weight: 800; color: var(--white); margin-bottom: 5px; }
.stats-title-en { font-size: 15px; color: var(--muted); font-family:'Inter',sans-serif; }
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 2px;
  max-width: 960px;
  margin: 0 auto;
  position: relative; z-index:1;
  background: var(--faint);
  border-radius: 20px;
  overflow: hidden;
}
.stat-box {
  text-align: center;
  padding: 38px 20px;
  background: rgba(10,21,38,.7);
  position: relative;
}
.stat-num {
  font-size: 52px;
  font-weight: 900;
  color: var(--teal-br);
  line-height: 1;
  margin-bottom: 10px;
  font-family: 'Inter', sans-serif;
  letter-spacing: -.02em;
}
.stat-num sup { font-size: 24px; vertical-align: super; }
.stat-label-ar { font-size: 14px; font-weight: 600; color: var(--off-white); margin-bottom: 3px; }
.stat-label-en { font-size: 11px; color: var(--muted); font-family:'Inter',sans-serif; }
.stat-box::after {
  content: '';
  position: absolute;
  bottom: 0; left: 15%; right: 15%;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--teal), transparent);
  opacity: 0;
  transition: opacity .3s;
}
.stat-box:hover::after { opacity:1; }

/* ═══════════════════════════════════════════════
   DUAL LANGUAGE ENTRY BUTTONS
═══════════════════════════════════════════════ */
.hero-entry {
  display:flex;align-items:center;justify-content:center;
  gap:10px;flex-wrap:wrap;
  position:relative;z-index:1;
  animation:fadeUp .55s ease .54s both;
}
.entry-card {
  display:flex;flex-direction:column;align-items:center;
  gap:9px;padding:22px 34px;border-radius:18px;
  text-decoration:none;color:#fff;
  border:1.5px solid rgba(255,255,255,.12);
  transition:transform .25s,box-shadow .25s;
  min-width:190px;
}
.entry-ar {
  background:linear-gradient(135deg,#1565C0,#003c8f);
  box-shadow:0 10px 32px rgba(21,101,192,.45);
}
.entry-en {
  background:linear-gradient(135deg,#00838F,#004D40);
  box-shadow:0 10px 32px rgba(0,131,143,.45);
}
.entry-card:hover{transform:translateY(-4px);box-shadow:0 20px 50px rgba(0,0,0,.3)}
.entry-badge{font-size:10.5px;font-weight:700;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.22);border-radius:50px;padding:3px 12px;color:rgba(255,255,255,.9);letter-spacing:.05em}
.entry-ico{font-size:28px;opacity:.9}
.entry-title{font-size:18px;font-weight:800;color:#fff;line-height:1}
.entry-sub{font-size:11px;color:rgba(255,255,255,.6)}
.entry-sep{display:flex;flex-direction:column;align-items:center;gap:5px;width:32px}
.entry-sep span:first-child,.entry-sep span:last-child{width:1px;height:22px;background:rgba(255,255,255,.15)}
.entry-sep .or{font-size:10.5px;color:rgba(255,255,255,.4)}
.security-strip {
  background: linear-gradient(135deg, var(--blue), #003c8f);
  padding: 36px 5%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 40px;
  flex-wrap: wrap;
}
.sec-item {
  display: flex; align-items: center; gap: 10px;
}
.sec-item i { font-size: 20px; color: rgba(255,255,255,.75); }
.sec-item-ar { font-size: 13.5px; font-weight: 600; color: var(--white); }
.sec-item-en { font-size: 11px; color: rgba(255,255,255,.6); font-family:'Inter',sans-serif; margin-top:1px; }
.sec-sep { width:1px; height:36px; background:rgba(255,255,255,.2); }

/* ═══════════════════════════════════════════════
   FOOTER
═══════════════════════════════════════════════ */
footer {
  background: var(--dark-1);
  border-top: 1px solid var(--faint);
  padding: 28px 5%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.footer-brand { display:flex; align-items:center; gap:9px; }
.footer-logo {
  width:32px;height:32px;
  background:linear-gradient(135deg,var(--blue),var(--teal));
  border-radius:8px;
  display:flex;align-items:center;justify-content:center;
}
.footer-logo i { font-size:14px;color:#fff; }
.footer-name-ar { font-size:13px;font-weight:600;color:var(--off-white);line-height:1.3; }
.footer-name-en { font-size:10.5px;color:var(--muted);font-family:'Inter',sans-serif; }
.footer-copy { font-size:12px;color:var(--faint); }
.footer-version {
  font-size:11px;color:var(--faint);
  background:var(--faint2);border:1px solid var(--faint);
  border-radius:50px;padding:3px 10px;
}

/* ═══════════════════════════════════════════════
   ANIMATIONS
═══════════════════════════════════════════════ */
@keyframes fadeUp {
  from { opacity:0; transform:translateY(24px); }
  to   { opacity:1; transform:translateY(0); }
}
.reveal {
  opacity: 0;
  transform: translateY(28px);
  transition: opacity .65s ease, transform .65s ease;
}
.reveal.visible { opacity:1; transform:translateY(0); }
.reveal { animation: revealFallback 0s 1.5s forwards; }
@keyframes revealFallback { to { opacity:1; transform:translateY(0); } }

/* ═══════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════ */
@media (max-width: 960px) {
  .modules-grid { grid-template-columns: repeat(2,1fr); }
  .stats-grid   { grid-template-columns: repeat(2,1fr); }
  .floating-icon { display: none; }
}
@media (max-width: 640px) {
  .navbar { padding: 0 16px; }
  .nav-brand .nav-name-en { display:none; }
  .modules-grid { grid-template-columns: 1fr; }
  .stats-grid   { grid-template-columns: 1fr 1fr; }
  .security-strip { gap:16px; }
  .sec-sep { display:none; }
  footer { flex-direction:column; text-align:center; }
  .hero-h-pill .h-en { display:none; }
  .hero-name-ar { font-size:clamp(24px,8vw,38px); }
  .hero-name-en { font-size:clamp(12px,3.5vw,18px); }
}
</style>
</head>
<body>

<div id="splashScreen" class="splash-screen">
  <div class="splash-center">
    <div class="splash-logo-pop">
      <div class="splash-logo-wrapper">
        <img src="<?= BASE_URL ?>/logo.png" alt="شعار النظام" class="splash-logo-img">
      </div>
    </div>
    
    <div class="splash-typing-container">
      <div id="spLine1" class="sp-line sp-title-ar" data-text="<?= e($h_name_ar) ?>"></div>
      <div id="spLine2" class="sp-line sp-title-en en" data-text="<?= e($h_name_en) ?>"></div>
      <div class="sp-gap"></div>
      <div id="spLine3" class="sp-line sp-highlight" data-text="إدارة الأصول"></div>
      <div id="spLine4" class="sp-line sp-sub-ar" data-text="نظام إدارة الأصول والبلاغات"></div>
    </div>
    
    <div class="splash-progress">
      <div class="splash-progress-bar"></div>
    </div>
  </div>
</div>

<nav class="navbar" role="banner">
  <a class="nav-brand" href="<?= BASE_URL ?>/">
    <div class="nav-logo-box" aria-hidden="true" style="background: transparent; box-shadow: none;">
      <img src="<?= BASE_URL ?>/logo.png" alt="شعار النظام" style="width: 32px; height: 32px; object-fit: contain;">
    </div>
    <div style="display: flex; flex-direction: column; align-items: center; justify-content: center;">
      <div class="nav-name-ar"><?= e($h_name_ar) ?></div>
      <div class="nav-name-en en"><?= e($h_name_en) ?></div>
    </div>
  </a>

  <div class="nav-sys" aria-label="اسم النظام">
    <span class="ns-ar">إدارة الأصول</span>
    <span class="ns-en en">Asset Management</span>
  </div>
</nav>

<section class="hero" id="hero" role="main" aria-label="صفحة الترحيب الرئيسية">
  
  <div class="hero-bg-circle" aria-hidden="true"></div>
  <div class="hero-bg-circle" aria-hidden="true"></div>
  <div class="hero-bg-circle" aria-hidden="true"></div>
  <div class="hero-grid"      aria-hidden="true"></div>

  <i class="fa-solid fa-boxes-stacked floating-icon right-side" aria-hidden="true"></i>
  <i class="fa-solid fa-shield-halved floating-icon left-side" aria-hidden="true"></i>

  <div class="hero-pill" aria-hidden="true">
    <span class="hero-pill-dot"></span>
    <span>دقة في التشغيل، كفاءة في الأداء · Operational Precision, Performance Efficiency</span>
  </div>

  <div class="hero-logo" role="img" aria-label="شعار المنصة">
    <img src="<?= BASE_URL ?>/logo.png" class="hero-logo-img" alt="شعار المنصة">
  </div>

  <h1 class="hero-name-ar" id="heroNameAr">منصة إدارة الأصول والبلاغات</h1>
  <p class="hero-name-en en" id="heroNameEn">Asset &amp; Complaint Management Platform</p>

  <div class="hero-hospital" aria-label="معلومات المستشفى" style="max-width: 440px; width: 100%; margin-left: auto; margin-right: auto;">
    <div class="hero-h-pill" style="width: 100%;">
      <div class="h-ar" style="font-size: 16px; font-weight: 700;"><?= e($h_name_ar) ?></div>
      <div class="h-en en" style="font-size: 13px; margin-top: 4px;"><?= e($h_name_en) ?></div>
    </div>
  </div>

  <div class="hero-entry" role="group" aria-label="اختر لغة الدخول — Choose login language">

    <a href="<?= BASE_URL ?>/auth/login.php?lang=ar"
       class="entry-card entry-ar"
       aria-label="دخول للمنصة بالعربية"
       style="direction:rtl">
      <span class="entry-badge">العربية · AR</span>
      <i class="fa-solid fa-door-open entry-ico" aria-hidden="true"></i>
      <span class="entry-title">دخول للمنصة</span>
    </a>

    <div class="entry-sep" aria-hidden="true">
      <span></span>
      <span class="or">أو</span>
      <span></span>
    </div>

    <a href="<?= BASE_URL ?>/auth/login.php?lang=en"
       class="entry-card entry-en"
       aria-label="Enter platform in English"
       style="direction:ltr;font-family:'Inter',sans-serif">
      <span class="entry-badge">English · EN</span>
      <i class="fa-solid fa-door-open entry-ico" aria-hidden="true"></i>
      <span class="entry-title">Enter Platform</span>
    </a>

  </div>

  <div class="hero-tags" aria-label="خصائص المنصة">
    <div class="hero-tag">
      <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
      <div class="tag-txt">
        <span class="tag-ar">صلاحيات متعددة المستويات</span>
        <span class="tag-en en">Multi-level permissions</span>
      </div>
    </div>
    
    <div class="hero-tag">
      <i class="fa-solid fa-language" aria-hidden="true"></i>
      <div class="tag-txt">
        <span class="tag-ar">ثنائية اللغة</span>
        <span class="tag-en en">Bilingual AR/EN</span>
      </div>
    </div>
    
    <div class="hero-tag">
      <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
      <div class="tag-txt">
        <span class="tag-ar">تقارير KPI / SLA</span>
        <span class="tag-en en">KPI / SLA Reports</span>
      </div>
    </div>
    
    <div class="hero-tag">
      <i class="fa-solid fa-mobile-screen" aria-hidden="true"></i>
      <div class="tag-txt">
        <span class="tag-ar">تجاوب كامل</span>
        <span class="tag-en en">Fully responsive</span>
      </div>
    </div>
  </div>

  <div class="scroll-indicator" aria-hidden="true">
    <span id="scrollTxt">اكتشف المزيد</span>
    <i class="fa-solid fa-chevron-down"></i>
  </div>
</section>

<section class="features-section" id="features" aria-label="وحدات النظام">
  <div class="section-header reveal">
    <div class="section-kicker">
      <i class="fa-solid fa-th-large" aria-hidden="true"></i>
      <span id="sectionKicker">وحدات النظام · System Modules</span>
    </div>
    <h2 class="section-title-ar" id="featTitleAr">منظومة متكاملة من الوحدات المترابطة</h2>
    <p class="section-title-en en" id="featTitleEn">An integrated suite of interconnected modules</p>
  </div>

  <?php
  $modules = [
    ['icon'=>'fa-boxes-stacked',     'color'=>'#1565C0','bg'=>'#E3F2FD',
     'ar'=>'الأصول الطبية', 'en'=>'Medical Assets',
     'dar'=>'إدارة سجلات الأصول ودورة حياتها من الاستلام حتى الإتلاف',
     'den'=>'Manage asset records and full lifecycle from acquisition to disposal'],
    ['icon'=>'fa-truck-ramp-box',    'color'=>'#00838F','bg'=>'#E0F7FA',
     'ar'=>'الاستلام',       'en'=>'Receiving',
     'dar'=>'محاضر استلام الأجهزة ولجان الفحص ومحاضر التوزيع',
     'den'=>'Equipment receiving minutes, inspection committees & distribution'],
    ['icon'=>'fa-screwdriver-wrench','color'=>'#7B1FA2','bg'=>'#F3E5F5',
     'ar'=>'التركيب والعهدة','en'=>'Installation & Custody',
     'dar'=>'محاضر تركيب الأجهزة وتشغيلها وتوزيع عهدها على الأقسام',
     'den'=>'Device installation, commissioning and departmental custody'],
    ['icon'=>'fa-bell',              'color'=>'#E65100','bg'=>'#FFF3E0',
     'ar'=>'البلاغات',       'en'=>'Fault Reports',
     'dar'=>'استقبال بلاغات الأعطال من الأقسام ومتابعة حالتها',
     'den'=>'Receive and track fault reports from departments'],
    ['icon'=>'fa-clipboard-list',    'color'=>'#C62828','bg'=>'#FFEBEE',
     'ar'=>'الصيانة التصحيحية','en'=>'Corrective Maintenance',
     'dar'=>'إنشاء أوامر العمل وإسنادها للشركات المتعاقدة وتتبعها',
     'den'=>'Create work orders, assign to contractors & track completion'],
    ['icon'=>'fa-chart-pie',         'color'=>'#2E7D32','bg'=>'#E8F5E9',
     'ar'=>'التقارير والإحصاءات','en'=>'Reports & Analytics',
     'dar'=>'لوحات KPI/SLA وتقارير الجرد والصيانة التحليلية',
     'den'=>'KPI/SLA dashboards, inventory & maintenance analytics'],
  ];
  ?>
  <div class="modules-grid" role="list">
    <?php foreach ($modules as $i => $m): ?>
    <div class="module-card reveal" style="animation-delay:<?= $i * .08 ?>s" role="listitem">
      <div class="module-card-icon" style="background:<?= $m['bg'] ?>" aria-hidden="true">
        <i class="fa-solid <?= $m['icon'] ?>" style="color:<?= $m['color'] ?>"></i>
      </div>
      <div class="module-card-name-ar"><?= e($m['ar']) ?></div>
      <div class="module-card-name-en en"><?= e($m['en']) ?></div>
      <p class="module-card-desc-ar"><?= e($m['dar']) ?></p>
      <p class="module-card-desc-en en"><?= e($m['den']) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="stats-section" id="stats" aria-label="إحصاءات المنصة">
  <div class="stats-header reveal">
    <h2 class="stats-title-ar">أرقام تعبّر عن الكفاءة</h2>
    <p class="stats-title-en en">Numbers that define efficiency</p>
  </div>
  <div class="stats-grid" role="list">
    <div class="stat-box reveal" role="listitem">
      <div class="stat-num" data-target="2800"><sup>+</sup>0</div>
      <div class="stat-label-ar">الأصول الطبية</div>
      <div class="stat-label-en en">Medical Assets</div>
    </div>
    <div class="stat-box reveal" role="listitem">
      <div class="stat-num" data-target="6">0</div>
      <div class="stat-label-ar">وحدات نظام</div>
      <div class="stat-label-en en">System Modules</div>
    </div>
    <div class="stat-box reveal" role="listitem">
      <div class="stat-num" data-target="6">0</div>
      <div class="stat-label-ar">مستويات صلاحيات</div>
      <div class="stat-label-en en">Permission Levels</div>
    </div>
    <div class="stat-box reveal" role="listitem">
      <div class="stat-num" data-target="2">0</div>
      <div class="stat-label-ar">لغة مدعومة</div>
      <div class="stat-label-en en">Supported Languages</div>
    </div>
  </div>
</section>

<div class="security-strip" role="complementary" aria-label="ميزات الأمان">
  <div class="sec-item">
    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
    <div><div class="sec-item-ar">حماية عالية المستوى</div><div class="sec-item-en en">Enterprise-grade security</div></div>
  </div>
  <div class="sec-sep" aria-hidden="true"></div>
  <div class="sec-item">
    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
    <div><div class="sec-item-ar">سجل نشاط كامل</div><div class="sec-item-en en">Full audit trail</div></div>
  </div>
  <div class="sec-sep" aria-hidden="true"></div>
  <div class="sec-item">
    <i class="fa-solid fa-lock" aria-hidden="true"></i>
    <div><div class="sec-item-ar">CSRF + Session binding</div><div class="sec-item-en en">CSRF + Session binding</div></div>
  </div>
  <div class="sec-sep" aria-hidden="true"></div>
  <div class="sec-item">
    <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
    <div><div class="sec-item-ar">صلاحيات دقيقة لكل إجراء</div><div class="sec-item-en en">Granular action permissions</div></div>
  </div>
</div>

<footer role="contentinfo">
  <div class="footer-brand">
    <div class="footer-logo" aria-hidden="true" style="background: transparent;">
      <img src="<?= BASE_URL ?>/logo.png" alt="شعار النظام" style="width: 24px; height: 24px; object-fit: contain;">
    </div>
    <div>
      <div class="footer-name-ar"><?= e($h_name_ar) ?></div>
      <div class="footer-name-en en"><?= e($h_name_en) ?></div>
    </div>
  </div>
  <div class="footer-copy" aria-label="حقوق النشر">
    &copy; <?= date('Y') ?> · منصة إدارة الأصول الطبية
  </div>
  <span class="footer-version"><?= e(APP_SHORT) ?> v<?= e(APP_VERSION) ?></span>
</footer>

<script>
(function(){
  'use strict';

  // ── Splash Screen & Typewriter ───────────────
  // التنفيذ المباشر لتلافي تأخير تحميل المتصفح
  const splash = document.getElementById('splashScreen');
  if (splash) {
    // منع التمرير (النزول) أثناء العرض
    document.body.style.overflow = 'hidden';

    // جمع السطور والنصوص من الـ data-text بأمان تام
    const typeTexts = [
      { el: document.getElementById('spLine1') },
      { el: document.getElementById('spLine2') },
      { el: document.getElementById('spLine3') },
      { el: document.getElementById('spLine4') }
    ];

    let currentLineIndex = 0;

    // دالة محاكاة الكتابة على الكيبورد
    function typeWriter(item, i, callback) {
      if (!item.el) {
        if (callback) callback();
        return;
      }
      
      const txt = item.el.getAttribute('data-text') || '';
      
      if (i === 0) {
        item.el.style.opacity = 1;
        item.el.classList.add('typing-active');
        item.el.innerHTML = '';
      }
      
      if (i < txt.length) {
        item.el.innerHTML += txt.charAt(i);
        // سرعة الكتابة: 25 ملي ثانية للحرف
        setTimeout(() => typeWriter(item, i + 1, callback), 25);
      } else {
        // انتهى السطر
        item.el.classList.remove('typing-active');
        item.el.classList.add('typing-done');
        // تأخير خفيف قبل السطر اللي بعده
        if (callback) setTimeout(callback, 80);
      }
    }

    // بدء تسلسل السطور
    function startTypingSequence() {
      if (currentLineIndex < typeTexts.length) {
         typeWriter(typeTexts[currentLineIndex], 0, () => {
            currentLineIndex++;
            startTypingSequence();
         });
      }
    }

    // ننتظر 600ms (حتى ينتهي الشعار من الانبثاق) ثم نبدأ الكتابة
    setTimeout(startTypingSequence, 600);

    // إغلاق الشاشة الإجباري والآمن بعد 5 ثواني
    setTimeout(() => {
      splash.classList.add('hidden');
      document.body.style.overflow = ''; // إعادة التمرير للصفحة
      // إزالة الشاشة نهائياً لتخفيف المتصفح بعد تلاشيها
      setTimeout(() => splash.remove(), 800); 
    }, 5000);
  }

  // ── Bilingual content map ───────────────────────────────────
  const T = {
    ar: {
      navLogin:  'تسجيل الدخول',
      cta:       'دخول إلى المنصة',
      explore:   'استكشاف الوحدات',
      scroll:    'اكتشف المزيد',
      kicker:    'وحدات النظام · System Modules',
      featTitle: 'منظومة متكاملة من الوحدات المترابطة',
      featSub:   'An integrated suite of interconnected modules',
    },
    en: {
      navLogin:  'Sign In',
      cta:       'Enter Platform',
      explore:   'Explore Modules',
      scroll:    'Discover more',
      kicker:    'System Modules · وحدات النظام',
      featTitle: 'An integrated suite of interconnected modules',
      featSub:   'منظومة متكاملة من الوحدات المترابطة',
    }
  };

  let currentLang = localStorage.getItem('pmsh_lang') || 'ar';

  window.switchLang = function(lang) {
    currentLang = lang;
    localStorage.setItem('pmsh_lang', lang);
    applyLang();
  };

  // دالة آمنة لتغيير النصوص بدون أخطاء
  function setTxt(id, text) {
      const el = document.getElementById(id);
      if (el) el.textContent = text;
  }

  function applyLang() {
    const t = T[currentLang];
    const isAr = currentLang === 'ar';

    setTxt('navLoginTxt', t.navLogin);
    
    const btnAr = document.getElementById('btnAr');
    const btnEn = document.getElementById('btnEn');
    if (btnAr) {
        btnAr.classList.toggle('on', isAr);
        btnAr.setAttribute('aria-pressed', String(isAr));
    }
    if (btnEn) {
        btnEn.classList.toggle('on', !isAr);
        btnEn.setAttribute('aria-pressed', String(!isAr));
    }

    // Hero
    setTxt('ctaBtnTxt', t.cta);
    setTxt('exploreBtnTxt', t.explore);
    setTxt('scrollTxt', t.scroll);

    // Features
    setTxt('sectionKicker', t.kicker);
    setTxt('featTitleAr', t.featTitle);
    setTxt('featTitleEn', t.featSub);

    // Direction
    document.documentElement.lang = currentLang;
    document.documentElement.dir  = isAr ? 'rtl' : 'ltr';
    document.body.style.direction  = isAr ? 'rtl' : 'ltr';
  }

  // Apply on load
  applyLang();

  // ── Intersection Observer for scroll reveals ───────────────
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add('visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.15 });

  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

  // ── Counter animation ──────────────────────────────────────
  function animateCounter(el, target, duration = 1800) {
    let start = null;
    const hasPlus = el.querySelector('sup') !== null;

    function step(ts) {
      if (!start) start = ts;
      const progress = Math.min((ts - start) / duration, 1);
      const eased = 1 - Math.pow(1 - progress, 3);
      const val = Math.round(eased * target);
      el.innerHTML = hasPlus ? '<sup>+</sup>' + val.toLocaleString() : val;
      if (progress < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  const statsObs = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const nums = entry.target.querySelectorAll('.stat-num[data-target]');
        nums.forEach(n => animateCounter(n, parseInt(n.dataset.target)));
        statsObs.unobserve(entry.target);
      }
    });
  }, { threshold: 0.3 });

  const statsGrid = document.querySelector('.stats-grid');
  if (statsGrid) statsObs.observe(statsGrid);

  // ── Smooth scroll for anchor links ────────────────────────
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const target = document.querySelector(a.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });

  // ── Navbar scroll effect ───────────────────────────────────
  const navbar = document.querySelector('.navbar');
  window.addEventListener('scroll', () => {
    navbar.style.background = window.scrollY > 60
      ? 'rgba(3,8,15,.95)'
      : 'rgba(5,13,26,.80)';
  }, { passive: true });

})();
</script>
</body>
</html>