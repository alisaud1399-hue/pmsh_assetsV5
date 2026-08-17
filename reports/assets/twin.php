<?php
/**
 * reports/assets/twin.php — الخريطة الرقمية للمستشفى (Digital Twin) — v3
 * ثيم فاتح عصري 2026 + ألوان زاهية + تحذيرات دقة البيانات
 */
require_once dirname(__DIR__, 2) . '/config.php';
page_guard('reports.assets', 'view');
$rtl = is_rtl();

/* ── بحث دبّوس (AJAX) ── */
if (isset($_GET['pin'])) {
    $like = '%' . trim((string)$_GET['pin']) . '%';
    $st = $pdo->prepare("SELECT tag_number tag, serial_number serial, description dsc,
        loc_building b, loc_floor fl, loc_room r
        FROM assets
        WHERE (tag_number LIKE ? OR serial_number LIKE ?) AND loc_building IS NOT NULL AND loc_building != ''
        LIMIT 5");
    $st->execute([$like, $like]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ── اكتشاف أعمدة القيمة/العمر/الاكتمال ── */
$cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assets'")->fetchAll(PDO::FETCH_COLUMN);
$val_col = in_array('net_book_value', $cols) ? 'net_book_value' : (in_array('original_cost', $cols) ? 'original_cost' : null);
$has_age = in_array('date_placed_in_service', $cols);
$has_completeness = in_array('data_completeness', $cols);

/* ── توحيد الأسماء: المصدر name_en (عربي) + دمج المكرر (عربي/إنجليزي) ── */
$extra = ($val_col ? "COALESCE(SUM(a.$val_col),0) val," : "0 val,")
       . ($has_age ? "COALESCE(AVG(TIMESTAMPDIFF(YEAR, a.date_placed_in_service, CURDATE())),0) age," : "0 age,")
       . ($has_completeness ? "SUM(a.data_completeness='complete') complete," : "0 complete,");

$floors = $pdo->query("SELECT COALESCE(bb.name_en, a.loc_building) b, COALESCE(ff.name_en, a.loc_floor) fl, COUNT(*) c,
    COALESCE(AVG(a.health_score),0) h,
    SUM(a.risk_band='critical') crit, SUM(a.risk_band IN ('critical','high')) risk,
    SUM(a.status='active') act, SUM(a.verified_status='تم التحقق') ver,
    $extra COALESCE(AVG(a.data_completeness_pct),0) comp_pct
    FROM assets a
    LEFT JOIN item_locations bb ON bb.location_type='building' AND (bb.name = a.loc_building OR bb.name_en = a.loc_building)
    LEFT JOIN item_locations ff ON ff.location_type='floor'   AND (ff.name = a.loc_floor   OR ff.name_en = a.loc_floor)
    WHERE a.loc_building IS NOT NULL AND a.loc_building != ''
  AND a.loc_building NOT IN ('0','N/A','NA','-','—')
  AND a.loc_building NOT REGEXP '^[0-9]+$'
    GROUP BY b, fl")->fetchAll(PDO::FETCH_ASSOC);

$rooms = $pdo->query("SELECT COALESCE(bb.name_en, a.loc_building) b, COALESCE(ff.name_en, a.loc_floor) fl, a.loc_room r, COUNT(*) c,
    COALESCE(AVG(a.health_score),0) h,
    SUM(a.risk_band='critical') crit, SUM(a.risk_band IN ('critical','high')) risk,
    SUM(a.verified_status='تم التحقق') ver,
    $extra COALESCE(AVG(a.data_completeness_pct),0) comp_pct
    FROM assets a
    LEFT JOIN item_locations bb ON bb.location_type='building' AND (bb.name = a.loc_building OR bb.name_en = a.loc_building)
    LEFT JOIN item_locations ff ON ff.location_type='floor'   AND (ff.name = a.loc_floor   OR ff.name_en = a.loc_floor)
    WHERE a.loc_building IS NOT NULL AND a.loc_building != ''
  AND a.loc_building NOT IN ('0','N/A','NA','-','—')
  AND a.loc_building NOT REGEXP '^[0-9]+$' AND a.loc_room IS NOT NULL AND a.loc_room != ''
    GROUP BY b, fl, a.loc_room")->fetchAll(PDO::FETCH_ASSOC);

$J = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG;
$page_title = $rtl ? 'الخريطة الرقمية' : 'Digital Twin';
$page_icon  = 'fa-cubes';
$active_nav = 'reports.assets.twin';
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Tajawal',sans-serif;background:linear-gradient(135deg,#f8fafc 0%,#eef2ff 50%,#f0f9ff 100%);color:#1e293b;min-height:100vh;overflow-x:hidden}
body::before{content:'';position:fixed;inset:0;pointer-events:none;
background:radial-gradient(900px 500px at 80% -10%,rgba(99,102,241,.08),transparent 60%),
radial-gradient(700px 400px at 10% 110%,rgba(16,185,129,.06),transparent 60%)}
.main-area{background:transparent}
.page-content{background:transparent}
.tw-wrap{max-width:1300px;margin:0 auto;padding:26px 22px;position:relative}
.tw-head{display:flex;align-items:center;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.tw-logo{width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;
font-size:24px;color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);
box-shadow:0 8px 24px rgba(99,102,241,.3)}
.tw-head h1{font-size:26px;font-weight:900;color:#0f172a}
.tw-head p{font-size:13px;color:#64748b}
.tw-kpis{display:flex;gap:12px;margin-inline-start:auto;flex-wrap:wrap}
.tw-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:14px;
padding:10px 18px;text-align:center;min-width:90px;box-shadow:0 2px 8px rgba(0,0,0,.04)}
.tw-kpi b{font-size:22px;font-weight:900;display:block}
.tw-kpi span{font-size:11px;color:#64748b;font-weight:700}
.tw-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
.layers{display:flex;gap:8px;flex-wrap:wrap}
.layer{padding:9px 16px;border-radius:12px;border:1.5px solid #e2e8f0;background:#fff;
cursor:pointer;font-weight:700;font-size:13px;transition:.2s;display:flex;gap:7px;align-items:center;
box-shadow:0 1px 3px rgba(0,0,0,.04)}
.layer:hover{border-color:#6366f1;transform:translateY(-1px);box-shadow:0 4px 12px rgba(99,102,241,.15)}
.layer.on{background:linear-gradient(135deg,#6366f1,#8b5cf6);border-color:transparent;color:#fff;
box-shadow:0 6px 20px rgba(99,102,241,.35)}
.pinbox{display:flex;gap:8px;margin-inline-start:auto}
.pinbox input{background:#fff;border:1.5px solid #e2e8f0;border-radius:12px;
padding:9px 14px;color:#1e293b;font-family:inherit;font-size:13px;width:230px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.pinbox input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.pinbox button{background:linear-gradient(135deg,#6366f1,#8b5cf6);border:none;border-radius:12px;color:#fff;
padding:9px 16px;cursor:pointer;font-family:inherit;font-weight:800;box-shadow:0 4px 14px rgba(99,102,241,.3)}
.tw-pills{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:26px}
.tw-pill{padding:10px 18px;border-radius:12px;border:1.5px solid #e2e8f0;background:#fff;
cursor:pointer;font-weight:700;font-size:14px;transition:.2s;display:flex;align-items:center;gap:10px;
box-shadow:0 1px 3px rgba(0,0,0,.04)}
.tw-pill:hover{border-color:#6366f1;transform:translateY(-2px);box-shadow:0 6px 16px rgba(99,102,241,.15)}
.tw-pill.sel{background:linear-gradient(135deg,#6366f1,#8b5cf6);border-color:transparent;color:#fff;
box-shadow:0 8px 24px rgba(99,102,241,.35)}
.tw-pill .dot{width:8px;height:8px;border-radius:50%}
.tw-pill small{color:#64748b;font-weight:700}
.tw-pill.sel small{color:rgba(255,255,255,.85)}
.tw-main{display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start}
@media(max-width:900px){.tw-main{grid-template-columns:1fr}}
.tw-tower{background:#fff;border:1.5px solid #e2e8f0;border-radius:20px;
padding:30px;position:relative;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.06)}
.tw-tower::after{content:'';position:absolute;left:0;right:0;height:60px;top:-60px;
background:linear-gradient(180deg,transparent,rgba(99,102,241,.1),transparent);
animation:twScan 5s linear infinite;pointer-events:none}
@keyframes twScan{to{top:110%}}
.tw-floors{display:flex;flex-direction:column-reverse;gap:6px}
.tw-floor{border-radius:10px;padding:12px 16px;display:flex;align-items:center;gap:14px;
cursor:pointer;transition:.2s;position:relative;border:1.5px solid transparent;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.tw-floor:hover{transform:scale(1.02);border-color:#6366f1;box-shadow:0 6px 20px rgba(99,102,241,.15)}
.tw-floor .fl{font-weight:900;font-size:14px;min-width:110px;color:#0f172a}
.tw-floor .cnt{font-size:12px;color:#64748b;font-weight:700;min-width:70px}
.tw-floor .bar{flex:1;height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden}
.tw-floor .bar i{display:block;height:100%;border-radius:99px;transition:width .3s}
.tw-floor .h{font-weight:900;font-size:13px;min-width:70px;text-align:end}
.tw-floor.crit{animation:twPulse 1.6s infinite}
@keyframes twPulse{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.2)}50%{box-shadow:0 0 18px 2px rgba(239,68,68,.35)}}
.tw-panel{background:#fff;border:1.5px solid #e2e8f0;border-radius:20px;
padding:24px;position:sticky;top:20px;max-height:86vh;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.06)}
.tw-panel::-webkit-scrollbar{width:6px}
.tw-panel::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:10px}
.tw-panel h3{font-size:17px;font-weight:900;margin-bottom:6px;display:flex;gap:10px;align-items:center;color:#0f172a}
.tw-panel .sub{font-size:12px;color:#64748b;margin-bottom:14px}
.tw-stat{display:flex;justify-content:space-between;align-items:center;padding:9px 0;
border-bottom:1px dashed #e2e8f0;font-size:13px}
.tw-stat b{font-weight:900;font-size:15px}
.rooms{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px}
.room{border-radius:10px;padding:10px 12px;cursor:pointer;transition:.2s;border:1.5px solid transparent;position:relative;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.room:hover{transform:translateY(-2px);border-color:#6366f1;box-shadow:0 6px 16px rgba(99,102,241,.15)}
.room .rn{font-weight:800;font-size:12px;line-height:1.5;min-height:36px;word-break:break-word;color:#0f172a}
.room .rc{font-size:11px;font-weight:700;color:#64748b;margin-top:4px}
.room.sel{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.15)}
.room.pin{animation:twPulse 1.2s infinite;border-color:#f59e0b}
.chip{margin-top:12px;background:#fef3c7;border:1.5px solid #fde68a;
border-radius:12px;padding:10px 14px;font-size:12px;font-weight:700;color:#92400e}
.tw-empty{padding:50px;text-align:center;color:#94a3b8}
.tw-legend{display:flex;gap:16px;margin-top:18px;flex-wrap:wrap;font-size:11.5px;color:#64748b}
.tw-legend span{display:flex;align-items:center;gap:6px}
.tw-legend i{width:12px;height:12px;border-radius:4px}
.trust-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;font-size:10.5px;font-weight:800;margin-inline-start:8px}
.trust-high{background:#dcfce7;color:#166534;border:1px solid #86efac}
.trust-med{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
.trust-low{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.tw-back{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #e2e8f0;
border-radius:12px;padding:9px 18px;font-weight:800;font-size:13px;color:#4f46e5;text-decoration:none;
box-shadow:0 1px 3px rgba(0,0,0,.04);transition:.2s;margin-bottom:16px}
.tw-back:hover{border-color:#6366f1;transform:translateY(-2px);box-shadow:0 8px 20px rgba(99,102,241,.18);color:#4338ca}
.tw-back i{font-size:12px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content">
<div class="tw-wrap">
<a class="tw-back" href="<?= BASE_URL ?>/reports/assets/index.php">
<i class="fa-solid fa-arrow-right"></i> العودة لمركز تقارير الأصول
</a>
<div class="tw-head">
<div class="tw-logo"><i class="fa-solid fa-cubes"></i></div>
<div><h1>الخريطة الرقمية للمستشفى</h1><p>تصوير حي لصحة الأصول ومخاطرها وتغطية الجرد عبر المباني والطوابق والغرف</p></div>
<div class="tw-kpis" id="kpis"></div>
</div>
<div class="tw-bar">
<div class="layers" id="layers"></div>
<div class="pinbox">
<input id="pinQ" placeholder="🔍 تاج / سيريال → دبّوس الموقع">
<button onclick="doPin()"><i class="fa-solid fa-location-crosshairs"></i></button>
</div>
</div>
<div class="tw-pills" id="pills"></div>
<div class="tw-main">
<div class="tw-tower"><div class="tw-floors" id="floors"></div></div>
<div class="tw-panel" id="panel"></div>
</div>
<div class="tw-legend" id="legend"></div>
</div>
</main>
</div>
<script>
const BASE=<?= json_encode(BASE_URL) ?>;
const FLOORS=<?= json_encode($floors, $J) ?>;
const ROOMS=<?= json_encode($rooms, $J) ?>;
const HAS_VALUE=<?= $val_col?'true':'false' ?>, HAS_AGE=<?= $has_age?'true':'false' ?>, HAS_COMP=<?= $has_completeness?'true':'false' ?>;
const MAXVAL=Math.max(1,...FLOORS.map(f=>+f.val));
let layer='health', curB=null, curF=null, pinR=null, selR=null;
const LAYERS=[
{id:'health', ic:'❤️', lb:'الصحة'},
{id:'risk',   ic:'⚠️', lb:'المخاطر'},
{id:'coverage',ic:'📋',lb:'تغطية الجرد'},
...(HAS_VALUE?[{id:'value', ic:'💰', lb:'القيمة'}]:[]),
...(HAS_AGE?[{id:'age', ic:'🕰️', lb:'العمر'}]:[]),
];
function metric(o){
switch(layer){
case 'health':   return +o.h;
case 'risk':     return +o.crit;
case 'coverage': return +o.c? (+o.ver/+o.c*100):0;
case 'value':    return +o.val;
case 'age':      return +o.age;
}
}
function colorFor(o){
switch(layer){
case 'health':{const v=+o.h;return v>=75?'#10b981':v>=50?'#f59e0b':'#ef4444';}
case 'risk':   return +o.crit>0?'#ef4444':(+o.risk>0?'#f59e0b':'#10b981');
case 'coverage':{const v=+o.c?(+o.ver/+o.c*100):0;return v>=80?'#10b981':v>=40?'#f59e0b':'#ef4444';}
case 'value':{const t=+o.val/MAXVAL;return t>=.5?'#8b5cf6':t>=.15?'#6366f1':'#06b6d4';}
case 'age':{const v=+o.age;return v>=15?'#ef4444':v>=8?'#f59e0b':'#10b981';}
}
}
function barW(o){
switch(layer){
case 'health':   return Math.min(100,+o.h);
case 'coverage': return +o.c?(+o.ver/+o.c*100):0;
case 'risk':     return +o.c?(+o.crit/+o.c*100):0;
case 'value':    return +o.val/MAXVAL*100;
case 'age':      return Math.min(100,+o.age/20*100);
}
}
function mText(o){
switch(layer){
case 'health':   return Math.round(+o.h)+'%';
case 'risk':     return o.crit+' حرج';
case 'coverage': return Math.round(+o.c?(+o.ver/+o.c*100):0)+'%';
case 'value':    return Math.round(+o.val).toLocaleString('en');
case 'age':      return Math.round(+o.age)+' س';
}
}
function hex(c,a){const n=parseInt(c.slice(1),16);return `rgba(${n>>16&255},${n>>8&255},${n&255},${a})`;}
function sortArr(a){
const d=(layer==='health'||layer==='coverage')?1:-1;
return a.slice().sort((x,y)=>(metric(x)-metric(y))*d);
}
function buildings(){
const m={};
FLOORS.forEach(f=>{
if(!m[f.b]) m[f.b]={b:f.b,c:0,crit:0,risk:0,ver:0,val:0,hW:0,ageW:0,compW:0};
const o=m[f.b];
o.c+=+f.c;o.crit+=+f.crit;o.risk+=+f.risk;o.ver+=+f.ver;o.val+=+f.val;o.hW+=+f.h*+f.c;o.ageW+=+f.age*+f.c;o.compW+=+f.comp_pct*+f.c;
});
Object.values(m).forEach(o=>{o.h=o.c?o.hW/o.c:0;o.age=o.c?o.ageW/o.c:0;o.comp_pct=o.c?o.compW/o.c:0;});
return Object.values(m);
}
function trustBadge(pct){
if(pct>=80) return '<span class="trust-badge trust-high"><i class="fa-solid fa-shield-check"></i> موثوق</span>';
if(pct>=50) return '<span class="trust-badge trust-med"><i class="fa-solid fa-triangle-exclamation"></i> متوسط</span>';
return '<span class="trust-badge trust-low"><i class="fa-solid fa-circle-exclamation"></i> ضعيف</span>';
}
function renderKpis(){
const B=buildings();
const c=B.reduce((a,o)=>a+o.c,0), cr=B.reduce((a,o)=>a+o.crit,0), v=B.reduce((a,o)=>a+o.ver,0);
const avgComp=B.reduce((a,o)=>a+o.comp_pct*o.c,0)/(c||1);
document.getElementById('kpis').innerHTML=`
<div class="tw-kpi"><b style="color:#6366f1">${B.length}</b><span>مبنى</span></div>
<div class="tw-kpi"><b>${c.toLocaleString('en')}</b><span>أصل مُموضع</span></div>
<div class="tw-kpi"><b style="color:${cr>0?'#ef4444':'#10b981'}">${cr}</b><span>حرج</span></div>
<div class="tw-kpi"><b style="color:#f59e0b">${c?Math.round(v/c*100):0}%</b><span>تغطية</span></div>`;
}
function renderLayers(){
document.getElementById('layers').innerHTML=LAYERS.map(l=>
`<div class="layer ${l.id===layer?'on':''}" onclick="setLayer('${l.id}')">${l.ic} ${l.lb}</div>`).join('');
const L={
health:'<span><i style="background:#10b981"></i>≥75 جيد</span><span><i style="background:#f59e0b"></i>50-74 متوسط</span><span><i style="background:#ef4444"></i>&lt;50 ضعيف</span>',
risk:'<span><i style="background:#ef4444"></i>حرج</span><span><i style="background:#f59e0b"></i>عالٍ</span><span><i style="background:#10b981"></i>سليم</span>',
coverage:'<span><i style="background:#10b981"></i>≥80%</span><span><i style="background:#f59e0b"></i>40-79%</span><span><i style="background:#ef4444"></i>&lt;40%</span>',
value:'<span><i style="background:#06b6d4"></i>منخفض</span><span><i style="background:#6366f1"></i>متوسط</span><span><i style="background:#8b5cf6"></i>مرتفع</span>',
age:'<span><i style="background:#10b981"></i>&lt;8 سنوات</span><span><i style="background:#f59e0b"></i>8-14</span><span><i style="background:#ef4444"></i>≥15 سنة</span>'};
document.getElementById('legend').innerHTML=L[layer];
}
function renderPills(){
const B=sortArr(buildings());
if(!curB) curB=B[0]?.b||null;
document.getElementById('pills').innerHTML=B.map(o=>
`<div class="tw-pill ${o.b===curB?'sel':''}" onclick="selB('${o.b.replace(/'/g,"\\'")}')">
<span class="dot" style="background:${colorFor(o)}"></span>${o.b}<small>${o.c} أصل</small></div>`).join('');
}
function renderTower(){
const fs=sortArr(FLOORS.filter(f=>f.b===curB));
document.getElementById('floors').innerHTML=fs.map(f=>{const c=colorFor(f);
return `<div class="tw-floor ${f.crit>0&&layer==='risk'?'crit':''}" onclick="selF('${f.fl.replace(/'/g,"\\'")}') "
style="background:linear-gradient(90deg,${hex(c,.15)},${hex(c,.05)})">
<span class="fl" style="color:${c}">${f.fl}</span>
<span class="cnt">${f.c} أصل</span>
<span class="bar"><i style="width:${barW(f)}%;background:${c}"></i></span>
<span class="h" style="color:${c}">${mText(f)}</span></div>`;}).join('')
||'<div class="tw-empty">لا بيانات</div>';
}
function renderPanel(){
const el=document.getElementById('panel');
if(!curF){el.innerHTML='<div class="tw-empty"><i class="fa-solid fa-hand-pointer" style="font-size:34px;display:block;margin-bottom:10px"></i>اختر طابقاً لعرض غرفه</div>';return;}
const f=FLOORS.find(x=>x.b===curB&&x.fl===curF); if(!f){el.innerHTML='<div class="tw-empty">لا بيانات</div>';return;}
const rs=sortArr(ROOMS.filter(r=>r.b===curB&&r.fl===curF));
el.innerHTML=`<h3><i class="fa-solid fa-layer-group" style="color:${colorFor(f)}"></i>${curB} — ${curF}</h3>
<div class="sub">${rs.length} غرفة • ${f.c} أصل ${HAS_COMP?trustBadge(+f.comp_pct):''}</div>
<div class="tw-stat"><span>إجمالي الأصول</span><b>${f.c}</b></div>
<div class="tw-stat"><span>نشطة</span><b style="color:#06b6d4">${f.act}</b></div>
<div class="tw-stat"><span>حرجة/عالية</span><b style="color:${f.risk>0?'#ef4444':'#10b981'}">${f.risk}</b></div>
<div class="tw-stat"><span>تم التحقق</span><b style="color:#f59e0b">${f.ver} (${f.c?Math.round(f.ver/f.c*100):0}%)</b></div>
<div class="rooms">${rs.map(r=>{const c=colorFor(r);
return `<div class="room ${selR===r.r?'sel':''} ${pinR===r.r?'pin':''}" style="background:${hex(c,.12)}"
onclick="selRoom('${r.r.replace(/'/g,"\\'")}') ">
<div class="rn">${r.r}</div><div class="rc">${r.c} أصل • ${mText(r)}</div></div>`;}).join('')||'<div class="tw-empty">لا غرف</div>'}</div>
<div id="roomDetail"></div>`;
if(pinR){const t=el.querySelector('.room.pin'); if(t) t.scrollIntoView({block:'center'});}
if(selR) showRoomDetail();
}
function showRoomDetail(){
const r=ROOMS.find(x=>x.b===curB&&x.fl===curF&&x.r===selR); if(!r)return;
document.getElementById('roomDetail').innerHTML=`
<div class="tw-stat" style="margin-top:12px"><span>متوسط الصحة</span><b style="color:${colorFor({...r,h:r.h})}">${Math.round(r.h)}%</b></div>
<div class="tw-stat"><span>حرجة/عالية</span><b>${r.crit}/${r.risk}</b></div>
<div class="tw-stat"><span>تم التحقق</span><b>${r.ver}/${r.c}</b></div>
${HAS_COMP?`<div class="tw-stat"><span>اكتمال البيانات</span><b>${Math.round(r.comp_pct)}%</b></div>`:''}
${HAS_VALUE?`<div class="tw-stat"><span>القيمة الدفترية</span><b>${Math.round(r.val).toLocaleString('en')}</b></div>`:''}
${HAS_AGE?`<div class="tw-stat"><span>متوسط العمر</span><b>${Math.round(r.age)} سنة</b></div>`:''}`;
}
function setLayer(l){layer=l;pinR=null;renderLayers();renderPills();renderTower();renderPanel();}
function selB(b){curB=b;curF=null;selR=null;pinR=null;renderPills();renderTower();renderPanel();}
function selF(f){curF=f;selR=null;renderTower();renderPanel();}
function selRoom(r){selR=(selR===r?null:r);renderPanel();}
async function doPin(){
const q=document.getElementById('pinQ').value.trim(); if(!q)return;
const r=await fetch(BASE+'/reports/assets/twin.php?pin='+encodeURIComponent(q));
const list=await r.json();
if(!list.length){alert('لا يوجد أصل مطابق');return;}
const a=list[0];
curB=a.b; curF=a.fl; pinR=a.r; selR=a.r;
renderPills();renderTower();renderPanel();
const d=document.getElementById('roomDetail');
if(d) d.insertAdjacentHTML('afterbegin',`<div class="chip">📌 ${a.tag||a.serial} — ${a.dsc||''}</div>`);
}
document.getElementById('pinQ').addEventListener('keydown',e=>{if(e.key==='Enter')doPin();});
renderKpis();renderLayers();renderPills();renderTower();renderPanel();
</script>
</body>
</html>