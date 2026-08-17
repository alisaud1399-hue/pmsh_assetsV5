<?php
/**
 * inventory/api/session_radar.php — رادار مراقبة الجلسة (Admin)
 * GET ?session_id= → لقطة حية | POST {action} → أوامر تحكم
 */
require_once dirname(__DIR__,2).'/config.php';
require_once BASE_PATH.'/includes/session_controls.php';
header('Content-Type: application/json; charset=utf-8');
if (!(is_admin() || (function_exists('can') && can('inventory.create','manage')))) json_response(['ok'=>false,'error'=>'forbidden'],403);
$sid=(int)($_REQUEST['session_id']??0); $me=(int)(current_user()['id']??0);
if(!$sid) json_response(['ok'=>false,'error'=>'missing'],400);
smc_schema($pdo);
$input=json_decode(file_get_contents('php://input'),true)?:$_POST;
$action=$input['action']??'snapshot';

function smc_notify(PDO $pdo,int $uid,string $type,string $title,string $body,string $link,int $sid){
    $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link,related_type,related_id) VALUES(?,?,?,?,?,'session',?)")
        ->execute([$uid,$type,$title,$body,$link,$sid]);
}

if($action!=='snapshot'){
    $uid=(int)($input['user_id']??0); if(!$uid) json_response(['ok'=>false,'error'=>'no_user'],400);
    $link=BASE_URL."/inventory/scan.php?session=$sid";
    switch($action){
        case 'kick':
            $room_id=(int)($input['room_id']??0); $block=(int)($input['block']??0);
            $n=smc_force_release_locks($pdo,$sid,$uid,$me,'إخراج إجباري بواسطة الإدارة');
            if($block&&$room_id){ $c=smc_get($pdo,$sid,$uid); $c['blocked_rooms'][]=$room_id; smc_save($pdo,$sid,$uid,['blocked_rooms'=>array_values(array_unique($c['blocked_rooms']))],$me); }
            smc_notify($pdo,$uid,'warning','🚪 أُخرجت من الغرفة','تم إخراجك من غرفة الجرد بواسطة مدير الأصول.',$link,$sid);
            json_response(['ok'=>true,'released'=>$n]);
        case 'suspend':
            smc_save($pdo,$sid,$uid,['suspended'=>1,'note'=>$input['note']??null],$me);
            smc_force_release_locks($pdo,$sid,$uid,$me,'تعليق العضو — إغلاق أقفاله');
            smc_notify($pdo,$uid,'error','⛔ عُلّقت مشاركتك','قام مدير الأصول بتعليق مشاركتك في جلسة الجرد.',$link,$sid);
            json_response(['ok'=>true]);
        case 'unsuspend':
            smc_save($pdo,$sid,$uid,['suspended'=>0],$me);
            smc_notify($pdo,$uid,'success','✅ فُكّ تعليقك','يمكنك الآن متابعة الجرد.',$link,$sid);
            json_response(['ok'=>true]);
        case 'block_room':
            $room_id=(int)($input['room_id']??0); if(!$room_id) json_response(['ok'=>false],400);
            $c=smc_get($pdo,$sid,$uid); $c['blocked_rooms'][]=$room_id;
            smc_save($pdo,$sid,$uid,['blocked_rooms'=>array_values(array_unique($c['blocked_rooms']))],$me);
            json_response(['ok'=>true]);
        case 'unblock_room':
            $room_id=(int)($input['room_id']??0); $c=smc_get($pdo,$sid,$uid);
            smc_save($pdo,$sid,$uid,['blocked_rooms'=>array_values(array_filter($c['blocked_rooms'],fn($r)=>(int)$r!==$room_id))],$me);
            json_response(['ok'=>true]);
        case 'extend':
            $n=smc_extend_lock($pdo,$sid,$uid,(int)($input['minutes']??30));
            json_response(['ok'=>true,'extended'=>$n]);
        default: json_response(['ok'=>false,'error'=>'bad_action'],400);
    }
}

/* ── لقطة حية ── */
$members=$pdo->prepare("SELECT m.user_id, u.full_name FROM inventory_session_members m JOIN users u ON u.id=m.user_id WHERE m.session_id=?");
$members->execute([$sid]); $members=$members->fetchAll(PDO::FETCH_ASSOC);

$locks=$pdo->prepare("SELECT l.*, r.name rname, r.name_en rname_en FROM room_inventory_locks l LEFT JOIN item_locations r ON r.id=l.room_id WHERE l.session_id=? AND l.status='active'");
$locks->execute([$sid]); $lockByUser=[]; foreach($locks->fetchAll(PDO::FETCH_ASSOC) as $L) $lockByUser[(int)$L['locked_by']]=$L;

$done=$pdo->prepare("SELECT locked_by, COUNT(*) c FROM room_inventory_locks WHERE session_id=? AND status='completed' GROUP BY locked_by");
$done->execute([$sid]); $doneByUser=[]; foreach($done->fetchAll(PDO::FETCH_ASSOC) as $r) $doneByUser[(int)$r['locked_by']]=(int)$r['c'];

$aud=$pdo->prepare("SELECT audited_by, action, COUNT(*) c, MAX(audited_at) last_at FROM inventory_audits WHERE session_id=? GROUP BY audited_by, action");
$aud->execute([$sid]); $stats=[];
foreach($aud->fetchAll(PDO::FETCH_ASSOC) as $r){
    $u=(int)$r['audited_by'];
    $stats[$u]['total']=($stats[$u]['total']??0)+(int)$r['c'];
    $stats[$u][$r['action']]=($stats[$u][$r['action']]??0)+(int)$r['c'];
    $stats[$u]['last_at']=max($stats[$u]['last_at']??'',$r['last_at']);
}

$events=$pdo->prepare("SELECT e.event_type, e.note, e.created_at, u.full_name, r.name rname, r.name_en rname_en FROM room_lock_events e LEFT JOIN users u ON u.id=e.actor_id LEFT JOIN item_locations r ON r.id=e.room_id WHERE e.session_id=? ORDER BY e.id DESC LIMIT 20");
$events->execute([$sid]); $events=$events->fetchAll(PDO::FETCH_ASSOC);

$now=time(); $out=[];
foreach($members as $m){
    $u=(int)$m['user_id']; $ctl=smc_get($pdo,$sid,$u); $L=$lockByUser[$u]??null; $st=$stats[$u]??[];
    $last=$st['last_at']??null; $idle=$last?round(($now-strtotime($last))/60):null;
    $alerts=[];
    if($ctl['suspended']) $alerts[]='suspended';
    if($L && $L['expires_at'] && (strtotime($L['expires_at'])-$now)<300) $alerts[]='expiring';
    if($L && $idle!==null && $idle>10) $alerts[]='idle';
    $out[]=[
        'user_id'=>$u,'name'=>$m['full_name'],'suspended'=>(int)$ctl['suspended'],'blocked_rooms'=>$ctl['blocked_rooms'],
        'lock'=>$L?['room_id'=>(int)$L['room_id'],'room'=>$L['rname_en']?:$L['rname'],'since'=>$L['resumed_at']?:$L['locked_at'],'expires_at'=>$L['expires_at']]:null,
        'completed_rooms'=>$doneByUser[$u]??0,
        'stats'=>['total'=>$st['total']??0,'confirmed'=>$st['confirmed']??0,'missing'=>$st['missing']??0,'location_changed'=>$st['location_changed']??0,'custody_changed'=>$st['custody_changed']??0,'condition_damaged'=>$st['condition_damaged']??0],
        'last_at'=>$last,'idle_min'=>$idle,'alerts'=>$alerts,
    ];
}
json_response(['ok'=>true,'members'=>$out,'events'=>$events,'now'=>date('Y-m-d H:i:s')]);