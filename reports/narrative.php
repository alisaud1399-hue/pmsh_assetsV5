<?php
/**
* reports/narrative.php — التقرير السردي الذكي (نهائي: كتابة حية + صوت + مؤشرات شاملة)
*/
ini_set('display_errors',1); error_reporting(E_ALL);
require_once dirname(__DIR__) . '/config.php';
page_guard('reports.index');
$rtl = is_rtl();
$hospital = get_setting('hospital_name','مستشفى الأمير مشاري بن سعود');

function q($pdo,$sql){ try{ return $pdo->query($sql)->fetchColumn(); }catch(Throwable $e){ return 0; } }

/* ═══ جمع المؤشرات ═══ */
$m = [];
$m['assets']   = (int)q($pdo,"SELECT COUNT(*) FROM assets WHERE status NOT IN ('disposed','returned_to_supplier')");
$m['critical'] = (int)q($pdo,"SELECT COUNT(*) FROM assets WHERE risk_band='critical'");
$m['health']   = (int)round((float)q($pdo,"SELECT AVG(health_score) FROM assets"));
$m['gap']      = (float)q($pdo,"SELECT SUM(funding_gap) FROM assets WHERE funding_gap>0");
$m['c_open']   = (int)q($pdo,"SELECT COUNT(*) FROM complaints WHERE status NOT IN ('closed','cancelled','rejected','resolved')");
$m['c_crit']   = (int)q($pdo,"SELECT COUNT(*) FROM complaints WHERE priority='critical' AND status NOT IN ('closed','cancelled','rejected','resolved')");
$m['sla']      = (int)round((float)q($pdo,"SELECT 100*SUM(sla_breach_detected_at IS NOT NULL)/COUNT(*) FROM complaints"));
$m['wo_act']   = (int)q($pdo,"SELECT COUNT(*) FROM complaint_work_orders WHERE status IN ('sent_to_contractor','in_progress','pending_manager_approval')");
$m['mttr']     = (int)round((float)q($pdo,"SELECT AVG(DATEDIFF(actual_completion_date,wo_date)) FROM complaint_work_orders WHERE status='completed' AND actual_completion_date IS NOT NULL"));
$m['pm_late']  = (int)q($pdo,"SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due < CURDATE()");
$m['inv']      = (int)round((float)q($pdo,"SELECT 100*SUM(action='confirmed')/COUNT(*) FROM inventory_audits"));
$m['disp']     = (float)q($pdo,"SELECT SUM(disposal_value) FROM asset_disposals");

/* مؤشرات العهدة والجرد والاستلام */
$m['cust_dept']  = (int)q($pdo,"SELECT COUNT(*) FROM assets WHERE custodian_dept_id IS NOT NULL AND status NOT IN ('disposed','returned_to_supplier')");
$m['dept_with']  = (int)q($pdo,"SELECT COUNT(DISTINCT custodian_dept_id) FROM assets WHERE custodian_dept_id IS NOT NULL");
$m['dept_total'] = (int)q($pdo,"SELECT COUNT(*) FROM departments");
$m['dept_without']= max(0, $m['dept_total'] - $m['dept_with']);
$m['no_emp']     = (int)q($pdo,"SELECT COUNT(*) FROM assets WHERE (custodian_name IS NULL OR custodian_name='') AND status NOT IN ('disposed','returned_to_supplier')");
$m['inv_open']   = (int)q($pdo,"SELECT COUNT(*) FROM inventory_sessions WHERE status IN ('planning','active','review')");
$m['received']   = (int)q($pdo,"SELECT COUNT(*) FROM commissioning_certificates WHERE asset_id IS NOT NULL");

/* ═══ تأليف السرد ═══ */
$P = [];
$P[] = "يضم السجل الوطني للأصول لدى {$hospital} حالياً {$m['assets']} أصلاً نشطاً بمتوسط صحة عام يبلغ {$m['health']} بالمئة"
     . ($m['critical']>0 ? "، منها {$m['critical']} أصلاً مصنّفاً ضمن نطاق الخطورة الحرجة ويستوجب متابعة لصيقة." : "، ولا توجد أصول ضمن النطاق الحرج حالياً، وهو مؤشر إيجابي على استقرار الأسطول.");
$P[] = "على صعيد التشغيل، توجد {$m['c_open']} بلاغات مفتوحة"
     . ($m['c_crit']>0 ? " بينها {$m['c_crit']} بلاغات حرجة تتطلب تدخلاً فورياً،" : " دون أي بلاغات حرجة،")
     . " فيما يلتزم ".(100-$m['sla'])." بالمئة من البلاغات باتفاقية مستوى الخدمة. وتسير {$m['wo_act']} أمر عمل حالياً بمتوسط زمن إصلاح يبلغ {$m['mttr']} يوماً.";
$P[] = "في الجانب الوقائي، رصد النظام {$m['pm_late']} خطة صيانة وقائية تجاوزت موعد استحقاقها"
     . ($m['pm_late']>0 ? " وهو ما يرفع احتمال الأعطال المفاجئة ويستدعي إعادة جدولة عاجلة." : "، ما يعكس انضباطاً جيداً في برنامج الصيانة الوقائية.")
     . " وبلغت دقة المطابقة في آخر دورات الجرد {$m['inv']} بالمئة.";
$P[] = "مالياً، تُقدَّر فجوة التمويل المطلوبة لمعالجة الأصول عالية المخاطر بـ ".number_format($m['gap'],0)." ريال،"
     . " فيما بلغت القيمة المستردة من عمليات التخلص ".number_format($m['disp'],0)." ريال.";
$P[] = "وفيما يخص العُهدة والتوزيع، تم إسناد {$m['cust_dept']} أصلاً إلى {$m['dept_with']} قسماً،"
     . ($m['dept_without']>0 ? " بينما لا تزال {$m['dept_without']} قسماً دون أي عهدة مسجّلة،" : " وجميع الأقسام لديها عهدة مسجّلة،")
     . " ويوجد {$m['no_emp']} أصلاً لم يُوزَّع عهدةً على موظف بعد. وعلى صعيد الجرد توجد {$m['inv_open']} جلسة مفتوحة حالياً،"
     . " فيما بلغ إجمالي الأصول المستلمة والمُشغّلة عبر النظام {$m['received']} أصلاً.";

/* ═══ التوصيات الذكية ═══ */
$R = [];
if($m['c_crit']>0)   $R[] = ['fa-bolt','#ef4444',"معالجة فورية للبلاغات الحرجة المفتوحة ({$m['c_crit']}) وتصعيدها للجنة المختصة."];
if($m['pm_late']>0)  $R[] = ['fa-calendar-xmark','#f59e0b',"إعادة جدولة {$m['pm_late']} خطة صيانة وقائية متأخرة خلال الأسبوع الجاري."];
if($m['critical']>0) $R[] = ['fa-triangle-exclamation','#ef4444',"إدراج الأصول الحرجة ({$m['critical']}) ضمن خطة الاستبدال وربطها بميزانية فجوة التمويل."];
if($m['sla']>=20)    $R[] = ['fa-stopwatch','#f59e0b',"مراجعة توزيع أحمال فرق الصيانة؛ فمعدل تجاوز اتفاقية الخدمة بلغ {$m['sla']} بالمئة."];
if($m['no_emp']>0)   $R[] = ['fa-handshake','#f59e0b',"تسوية عهدة {$m['no_emp']} أصلاً غير مسندة لموظف وتوثيقها في السجل."];
if($m['dept_without']>0)$R[] = ['fa-building','#0ea5e9',"مراجعة {$m['dept_without']} قسماً دون عهدة وتحديد مسؤولي الأصول لها."];
if(empty($R))        $R[] = ['fa-check-double','#22c55e',"المؤشرات ضمن النطاق الصحي — يُوصى بالاستمرار في برنامج الصيانة الوقائية الحالي."];
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><title>الملخص التنفيذي الذكي — <?= e($hospital) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Tajawal',sans-serif;background:#0b1220;color:#e2e8f0;padding:40px 16px}
.nr-wrap{max-width:900px;margin:0 auto}
.nr-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.nr-btn{padding:10px 18px;border-radius:12px;border:1px solid rgba(148,163,184,.25);
  background:rgba(255,255,255,.06);color:#e2e8f0;font-weight:800;cursor:pointer;font-family:'Tajawal';transition:.2s;font-size:13px}
.nr-btn:hover{background:rgba(56,189,248,.15);border-color:#38bdf8}
.nr-btn.voice{border-color:rgba(34,197,94,.5);color:#4ade80}
.nr-btn.voice:hover{background:rgba(34,197,94,.15)}
#nr-status{font-size:12px;color:#64748b;font-weight:700}
.nr-doc{background:#fdfdfb;color:#1e293b;border-radius:20px;overflow:hidden;box-shadow:0 30px 80px rgba(2,8,20,.5)}
.nr-head{background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;padding:34px 40px;position:relative}
.nr-head::after{content:'';position:absolute;inset-inline:0;bottom:0;height:4px;background:linear-gradient(90deg,#0ea5e9,#22c55e,#f59e0b)}
.nr-head h1{font-size:26px;font-weight:900}
.nr-head p{font-size:13px;opacity:.8;margin-top:6px}
.nr-body{padding:36px 44px}
.nr-meta{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:#64748b;margin-bottom:26px;padding-bottom:18px;border-bottom:2px solid #e2e8f0}
.nr-meta b{color:#0f172a}
.nr-p{font-size:15.5px;line-height:2.1;color:#334155;margin-bottom:18px;text-align:justify;min-height:2.1em}
.nr-p b{color:#0f172a;background:linear-gradient(transparent 60%,#fef08a 60%);padding:0 3px}
.caret{display:inline-block;width:2px;height:1.05em;background:#0ea5e9;margin-inline-start:2px;vertical-align:middle;animation:blink 1s infinite}
@keyframes blink{50%{opacity:0}}
.nr-sec{font-size:18px;font-weight:900;color:#0f172a;margin:28px 0 14px;display:flex;gap:10px;align-items:center}
.nr-sec i{color:#0ea5e9}
.nr-hidden{opacity:0;transform:translateY(12px);transition:.7s}
.nr-hidden.show{opacity:1;transform:none}
.nr-rec{display:flex;gap:12px;align-items:flex-start;padding:13px 16px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;margin-bottom:10px;font-size:14px;line-height:1.8;color:#334155}
.nr-rec i{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:14px}
.nr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:24px 0}
.nr-kpi{background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:14px;text-align:center}
.nr-kpi b{font-size:24px;font-weight:900;color:#0f172a;display:block}
.nr-kpi span{font-size:11px;color:#64748b;font-weight:700}
.nr-foot{padding:20px 44px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;display:flex;justify-content:space-between}
@media print{body{background:#fff;padding:0}.nr-actions{display:none}.nr-doc{box-shadow:none;border-radius:0}.caret{display:none}}
</style>
</head>
<body>
<div class="nr-wrap">
  <div class="nr-actions">
    <span id="nr-status"></span>
    <button class="nr-btn voice" id="btnPlay"><i class="fa-solid fa-volume-high"></i> سرد صوتي</button>
    <button class="nr-btn voice" id="btnPause"><i class="fa-solid fa-pause"></i> إيقاف مؤقت</button>
    <button class="nr-btn voice" id="btnStop"><i class="fa-solid fa-stop"></i> إيقاف</button>
    <button class="nr-btn" id="btnSkip"><i class="fa-solid fa-forward"></i> إكمال الكتابة</button>
    <button class="nr-btn" onclick="location.reload()"><i class="fa-solid fa-rotate"></i> تحديث</button>
    <button class="nr-btn" onclick="copyText()"><i class="fa-solid fa-copy"></i> نسخ</button>
    <button class="nr-btn" onclick="finalizeAll();window.print()"><i class="fa-solid fa-print"></i> طباعة</button>
  </div>

  <div class="nr-doc" id="nrDoc">
    <div class="nr-head">
      <h1><i class="fa-solid fa-feather-pointed"></i> الملخص التنفيذي الذكي</h1>
      <p><?= e($hospital) ?> — إدارة الأصول والإبداع</p>
    </div>
    <div class="nr-body">
      <div class="nr-meta">
        <span>تاريخ الإصدار: <b><?= date('Y/m/d') ?></b></span>
        <span>أُعِدَّ آلياً بواسطة: <b>محرك السرد الذكي</b></span>
        <span>التصنيف: <b>داخلي</b></span>
      </div>

      <div class="nr-kpis">
        <div class="nr-kpi"><b data-count="<?= $m['assets'] ?>">0</b><span>أصل نشط</span></div>
        <div class="nr-kpi"><b data-count="<?= $m['health'] ?>">0</b><span>متوسط الصحة %</span></div>
        <div class="nr-kpi"><b data-count="<?= $m['c_open'] ?>">0</b><span>بلاغ مفتوح</span></div>
        <div class="nr-kpi"><b data-count="<?= $m['pm_late'] ?>">0</b><span>صيانة متأخرة</span></div>
      </div>
      <div class="nr-kpis">
        <div class="nr-kpi"><b data-count="<?= $m['cust_dept'] ?>">0</b><span>عهدة على الأقسام</span></div>
        <div class="nr-kpi"><b data-count="<?= $m['dept_without'] ?>">0</b><span>أقسام بدون عهدة</span></div>
        <div class="nr-kpi"><b data-count="<?= $m['no_emp'] ?>">0</b><span>أصول بدون عهدة موظف</span></div>
        <div class="nr-kpi"><b data-count="<?= $m['inv_open'] ?>">0</b><span>جلسات جرد مفتوحة</span></div>
        <div class="nr-kpi"><b data-count="<?= $m['received'] ?>">0</b><span>أصول مستلمة عبر النظام</span></div>
      </div>

      <div class="nr-sec"><i class="fa-solid fa-file-lines"></i> التحليل التنفيذي</div>
      <?php foreach($P as $p): $plain=trim(strip_tags($p)); ?>
      <p class="nr-p" data-plain="<?= e($plain) ?>" data-rich="<?= e($p) ?>"></p>
      <?php endforeach; ?>

      <div class="nr-sec"><i class="fa-solid fa-lightbulb"></i> التوصيات</div>
      <div id="nrRecs" class="nr-hidden">
        <?php foreach($R as $r): ?>
        <div class="nr-rec"><i style="background:<?= $r[1] ?>22;color:<?= $r[1] ?>" class="fa-solid <?= $r[0] ?>"></i><span><?= $r[2] ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="nr-foot"><span>وثيقة مُولَّدة آلياً من بيانات النظام اللحظية</span><span><?= date('Y/m/d H:i') ?></span></div>
  </div>
</div>

<script>
const REC_TEXTS=<?= json_encode(array_map(fn($r)=>$r[2],$R), JSON_UNESCAPED_UNICODE) ?>;
const paras=[...document.querySelectorAll('.nr-p')];
let pi=0, skipFlag=false;

/* ═══ الكتابة الحية ═══ */
function typeNext(){
  if(skipFlag){ finalizeAll(); return; }
  if(pi>=paras.length){ finishTyping(); return; }
  const p=paras[pi], text=p.dataset.plain; let i=0;
  const caret=document.createElement('span'); caret.className='caret'; p.appendChild(caret);
  (function step(){
    if(skipFlag){ finalizeAll(); return; }
    i++;
    caret.before(document.createTextNode(text[i-1]));
    if(i<text.length){ setTimeout(step,6); }
    else { p.innerHTML=p.dataset.rich; pi++; setTimeout(typeNext,300); }
  })();
}
function finishTyping(){ document.getElementById('nrRecs').classList.add('show'); }
function finalizeAll(){
  skipFlag=true;
  paras.forEach(p=>p.innerHTML=p.dataset.rich);
  document.getElementById('nrRecs').classList.add('show');
}
document.getElementById('btnSkip').onclick=finalizeAll;
window.onbeforeprint=finalizeAll;

/* ═══ العدّادات ═══ */
document.querySelectorAll('[data-count]').forEach(el=>{
  const t=+el.dataset.count,t0=performance.now();
  (function tick(n){const p=Math.min(1,(n-t0)/1200),e2=1-Math.pow(1-p,3);
    el.textContent=Math.round(t*e2);if(p<1)requestAnimationFrame(tick);})(t0);
});

/* ═══ القارئ الصوتي ═══ */
let arVoice=null;
function pickVoice(){ const vs=speechSynthesis.getVoices();
  arVoice = vs.find(v=>v.lang && v.lang.toLowerCase().startsWith('ar')) || null; }
if('speechSynthesis' in window){ pickVoice(); speechSynthesis.onvoiceschanged=pickVoice; }
function setStatus(s){ document.getElementById('nr-status').textContent=s; }
function chunkText(t,n){ const w=t.split(/\s+/),out=[];let cur='';
  w.forEach(x=>{ if((cur+' '+x).length>n){out.push(cur);cur=x;}else cur=cur?cur+' '+x:x; });
  if(cur)out.push(cur); return out; }
function buildSpeech(){
  let txt='الملخص التنفيذي الذكي. ';
  paras.forEach(p=>txt+=p.dataset.plain+' ');
  txt+='التوصيات: '+REC_TEXTS.join(' ');
  return txt;
}
document.getElementById('btnPlay').onclick=()=>{
  if(!('speechSynthesis' in window)){ setStatus('متصفحك لا يدعم الصوت'); return; }
  if(speechSynthesis.paused){ speechSynthesis.resume(); setStatus('متابعة السرد...'); return; }
  if(speechSynthesis.speaking) return;
  speechSynthesis.cancel();
  const parts=chunkText(buildSpeech(),170);
  parts.forEach((c,i)=>{ const u=new SpeechSynthesisUtterance(c);
    u.lang='ar-SA'; u.rate=1; if(arVoice)u.voice=arVoice;
    if(i===parts.length-1) u.onend=()=>setStatus('انتهى السرد ✔');
    speechSynthesis.speak(u); });
  setStatus('جارٍ السرد الصوتي...');
};
document.getElementById('btnPause').onclick=()=>{
  if(speechSynthesis.speaking && !speechSynthesis.paused){ speechSynthesis.pause(); setStatus('متوقف مؤقتاً'); }
};
document.getElementById('btnStop').onclick=()=>{ speechSynthesis.cancel(); setStatus('تم الإيقاف'); };

/* ═══ نسخ ═══ */
function copyText(){ finalizeAll();
  navigator.clipboard.writeText(document.getElementById('nrDoc').innerText).then(()=>alert('تم النسخ ✔')); }

typeNext();
</script>
</body>
</html>