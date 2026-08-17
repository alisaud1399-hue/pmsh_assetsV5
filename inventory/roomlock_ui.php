<?php if (!defined('ROOMLOCK_UI')): define('ROOMLOCK_UI', 1);
$rl_manual    = $is_admin || get_setting('inv_manual_picker','0') === '1';
$rl_qr_req    = get_setting('inv_qr_required_for_lock','1') === '1';
$rl_audio     = get_setting('inv_audio_cue','1') === '1';
$rl_vibrate   = get_setting('inv_vibration','1') === '1';
$rl_max_susp  = (int)get_setting('inv_max_suspend_count','3');
?>
<style>
.rl-exit-opt{width:100%;text-align:start;padding:14px;border-radius:14px;border:1.5px solid var(--line);background:#fff;cursor:pointer;margin-bottom:10px;font-family:'Tajawal';transition:.2s}
.rl-exit-opt:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.06)}
.rl-exit-opt .t{font-weight:900;font-size:14px}
.rl-exit-opt .s{font-size:11.5px;color:var(--muted);margin-top:3px}
.rl-lockbar{background:linear-gradient(135deg,#065f46,#10b981);color:#fff;border-radius:12px;padding:8px 12px;font-size:12px;font-weight:800;margin-bottom:10px;display:flex;gap:8px;align-items:center}
.rl-lockbar i{font-size:14px}
</style>

<!-- ورقة الخروج من الغرفة -->
<div class="modal" id="rlExitModal" onclick="if(event.target===this)this.classList.remove('show')">
<div class="sheet">
<h4 style="margin:0 0 14px;color:var(--navy);font-weight:900"><i class="fa-solid fa-door-open"></i> <?= $is_ar?'مغادرة الغرفة':'Leaving Room' ?></h4>
<button class="rl-exit-opt" style="border-color:#bbf7d0" onclick="rlComplete()">
  <div class="t" style="color:#15803d"><i class="fa-solid fa-lock"></i> <?= $is_ar?'إقفال نهائي وإنهاء الغرفة':'Finalize & Lock Room' ?></div>
  <div class="s"><?= $is_ar?'أُقرّ بأنني جردت كافة الموجودات فعلياً — تُقفل الغرفة على الجميع':'I confirm I audited everything present — room locks for all' ?></div>
</button>
<button class="rl-exit-opt" onclick="rlSuspend()">
  <div class="t" style="color:#b45309"><i class="fa-solid fa-pause"></i> <?= $is_ar?'استكمال فيما بعد':'Resume Later' ?></div>
  <div class="s"><?= $is_ar?'تبقى الغرفة مفتوحة باسمك، ويمكنك أو يمكن لغيرك (بموافقتك) المتابعة':'Room stays open under your name' ?></div>
</button>
<button class="rl-exit-opt" onclick="document.getElementById('rlExitModal').classList.remove('show')">
  <div class="t" style="color:#64748b"><i class="fa-solid fa-xmark"></i> <?= $is_ar?'إلغاء (البقاء)':'Cancel (stay)' ?></div>
</button>
</div>
</div>

<!-- نافذة التسلّم -->
<div class="modal" id="rlTakeoverModal">
<div class="sheet">
<h4 style="margin:0 0 10px;color:#b45309;font-weight:900"><i class="fa-solid fa-user-lock"></i> <?= $is_ar?'الغرفة قيد الجرد حالياً':'Room In Progress' ?></h4>
<div id="rlTakeoverInfo" style="font-size:13px;color:#334155;font-weight:700;margin-bottom:14px"></div>
<div style="display:flex;gap:10px">
<button class="btn btn-g" style="flex:2" onclick="rlDoTakeover()"><i class="fa-solid fa-right-to-bracket"></i> <?= $is_ar?'نعم، استكمال الجرد':'Yes, Take Over' ?></button>
<button class="btn btn-o" style="flex:1" onclick="rlCancelTakeover()"><?= $is_ar?'تراجع':'Cancel' ?></button>
</div>
</div>
</div>

<script>
const RL = {
  manual: <?= $rl_manual?'true':'false' ?>,
  qrRequired: <?= $rl_qr_req?'true':'false' ?>,
  audioCue: <?= $rl_audio?'true':'false' ?>,
  vibrate: <?= $rl_vibrate?'true':'false' ?>,
  maxSuspend: <?= $rl_max_susp ?>,
  suspendCount: 0,
  room: null,
  pendingRoom: null
};
window.__origOpenRoom = openRoom;
window.__origGoBack = goBack;
window.__origLoadLocations = loadLocations;

/* إخفاء المنتقي اليدوي إن لم يكن مفعّلاً */
loadLocations = async function(){ await __origLoadLocations(); /* picker لا يحتاج to toggle القديم — فقط الإعدادات */ };

/* زر مسح QR الغرفة: إن لم يكن موجوداً (scan.php الجديد يحتويه) */
(function(){
  if($('rlScanBtn')) return;  // الزر موجود في scan.php
  const wrap = document.querySelector('#scrLoc .wrap');
  if(!wrap) return;
  const b = document.createElement('button');
  b.id = 'rlScanBtn';
  b.className='btn btn-g'; b.style.width='100%'; b.style.marginBottom='12px';
  b.innerHTML='<i class="fa-solid fa-qrcode"></i> '+(window.IS_AR?'مسح QR الغرفة':'Scan Room QR');
  b.onclick = rlScanRoom;
  wrap.prepend(b);
})();

let rlScanner=null;
function rlStopCam(){ if(rlScanner){ const s=rlScanner; rlScanner=null; s.stop().then(()=>s.clear()).catch(()=>{}); } const b=$('rlCamBox'); if(b) b.style.display='none'; }
function rlScanRoom(){
  let box=$('rlCamBox');
  if(!box){
    // ابحث عن مكان الإدراج: داخل qr-scan-card (الجديد) أو قبل .picker-section
    const cam = document.createElement('div');
    cam.id = 'rlCamBox';
    cam.className = 'qr-cam';
    cam.style.cssText='display:none;margin-bottom:18px';
    cam.innerHTML='<div id="rlQr"></div>';
    const anchor = $('rlScanBtn') ? $('rlScanBtn').closest('.qr-scan-card') : document.querySelector('#scrLoc .wrap');
    if(anchor) anchor.appendChild(cam);
    box = cam;
  }
  if(box.style.display==='block'){
    rlStopCam();
    const btn = $('rlScanBtn');
    if(btn) btn.classList.remove('scanning');
    return;
  }
  box.style.display='block';
  const btn = $('rlScanBtn');
  if(btn) btn.classList.add('scanning');
  rlScanner=new Html5Qrcode('rlQr');
  rlScanner.start({facingMode:'environment'},{fps:10,qrbox:{width:230,height:140}}, async txt=>{
    rlStopCam();
    if(btn) btn.classList.remove('scanning');
    await rlOpenByCode(txt);
  }, ()=>{}).catch(()=>{
    if(btn) btn.classList.remove('scanning');
    toast(window.IS_AR?'⚠️ تعذّر فتح الكاميرا':'⚠️ Cannot open camera');
  });
}
async function rlOpenByCode(txt){
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'resolve',session_id:SID,room_id:1,code:txt})});
    const j=await r.json();
    if(!j.ok){ beep(false); toast(window.IS_AR?'⚠️ رمز غرفة غير معروف':'⚠️ Unknown room code'); return; }
    beep(true); openRoom(j.room_id);
  }catch(e){ toast('⚠️ '+(window.IS_AR?'فشل الاتصال':'Connection failed')); }
}

/* اعتراض فتح الغرفة: قفل/تسلّم */
openRoom = async function(roomId){
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'checkin',session_id:SID,room_id:roomId})});
    const j=await r.json();
    if(!j.ok){
      beep(false);
      if(j.error==='room_completed'){ alert((window.IS_AR?'🔒 هذه الغرفة أُقفلت بعد إتمام جردها بواسطة ':'🔒 Room completed & locked by ')+(j.by||'')+(window.IS_AR?' — يلزم استثناء من مدير الأصول لإعادة فتحها.':' — admin exception required.')); return; }
      if(j.error==='has_other_lock'){ alert((window.IS_AR?'⚠️ لديك غرفة أخرى مفتوحة باسمك: ':'⚠️ You have another open room: ')+(j.room||'')+(window.IS_AR?' — أنهِها أو علّقها أولاً.':' — finish or suspend it first.')); return; }
      if(j.error==='needs_takeover'){ RL.pendingRoom=roomId; $('rlTakeoverInfo').innerHTML=(window.IS_AR?'فُتحت هذه الغرفة للجرد من <b>':'Opened by <b>')+(j.by||'')+'</b> '+(window.IS_AR?'— هل تريد استكمال الجرد مكانه؟':'— take over?'); $('rlTakeoverModal').classList.add('show'); return; }
      alert('⚠️ '+(j.error||'')); return;
    }
    RL.room=roomId;
    __origOpenRoom(roomId);
    rlShowLockBar();
  }catch(e){ alert('⚠️ '+(window.IS_AR?'فشل الاتصال':'Connection failed')); }
};
async function rlDoTakeover(){
  $('rlTakeoverModal').classList.remove('show');
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'takeover',session_id:SID,room_id:RL.pendingRoom})});
    const j=await r.json();
    if(!j.ok){ alert('⚠️ '+(j.error||'')); return; }
    RL.room=RL.pendingRoom; beep(true); __origOpenRoom(RL.room); rlShowLockBar();
  }catch(e){ alert('⚠️'); }
}
function rlCancelTakeover(){ $('rlTakeoverModal').classList.remove('show'); RL.pendingRoom=null; }

function rlShowLockBar(){
  const head=document.querySelector('#scrRoom .roomhead');
  if(head && !head.querySelector('.rl-lockbar')){
    const d=document.createElement('div'); d.className='rl-lockbar';
    d.innerHTML='<i class="fa-solid fa-lock"></i> '+(window.IS_AR?'غرفة مقفلة لك — سجّل كل الموجود فعلياً':'Room locked to you — audit everything present');
    head.prepend(d);
  }
}

/* اعتراض الرجوع من الغرفة → ورقة الخروج */
goBack = function(){
  if(screen==='room' && RL.room){ $('rlExitModal').classList.add('show'); return; }
  if(screen==='device' && !curRoom){ /* من البحث الشامل */ }
  __origGoBack();
};
async function rlComplete(){
  if(!confirm(window.IS_AR?'تأكيد الإقفال النهائي؟ لا يمكن إعادة فتح الغرفة إلا باستثناء من مدير الأصول.':'Confirm final lock?')) return;
  $('rlExitModal').classList.remove('show');
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'complete',session_id:SID,room_id:RL.room,oath:1})});
    const j=await r.json();
    if(!j.ok){ alert('⚠️ '+(j.error||'')); return; }
    beep(true); toast(window.IS_AR?'🔒 أُقفلت الغرفة — تم الإتمام':'🔒 Room locked — done');
    RL.room=null; __origGoBack();
  }catch(e){ alert('⚠️'); }
}
async function rlSuspend(){
  $('rlExitModal').classList.remove('show');
  try{
    await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'suspend',session_id:SID,room_id:RL.room})});
    toast(window.IS_AR?'⏸️ علّقت الغرفة — تستأنف لاحقاً':'⏸️ Suspended — resume later');
    RL.room=null; __origGoBack();
  }catch(e){ alert('⚠️'); }
}
</script>
<?php endif; ?>