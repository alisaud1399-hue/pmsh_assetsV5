<?php
/**
 * includes/session_controls.php — تحكم الإدارة في أعضاء جلسات الجرد
 */
if (!defined('PMSH_SESSION_CONTROLS')) {
define('PMSH_SESSION_CONTROLS', true);

function smc_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS session_member_controls (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        suspended TINYINT(1) NOT NULL DEFAULT 0,
        blocked_rooms TEXT NULL,
        note VARCHAR(255) NULL,
        updated_by INT UNSIGNED NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_smc (session_id,user_id), KEY idx_smc (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function smc_get(PDO $pdo,int $sid,int $uid): array {
    smc_schema($pdo);
    $st=$pdo->prepare("SELECT * FROM session_member_controls WHERE session_id=? AND user_id=?");
    $st->execute([$sid,$uid]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row) return ['suspended'=>0,'blocked_rooms'=>[],'note'=>''];
    return ['suspended'=>(int)$row['suspended'],'blocked_rooms'=>json_decode($row['blocked_rooms']??'[]',true)?:[],'note'=>$row['note']??''];
}
function smc_is_suspended(PDO $pdo,int $sid,int $uid): bool { return (bool)smc_get($pdo,$sid,$uid)['suspended']; }
function smc_is_room_blocked(PDO $pdo,int $sid,int $uid,int $room_id): bool { return in_array($room_id, smc_get($pdo,$sid,$uid)['blocked_rooms'], true); }
function smc_save(PDO $pdo,int $sid,int $uid,array $d,int $actor): void {
    smc_schema($pdo); $cur=smc_get($pdo,$sid,$uid);
    $susp=(int)($d['suspended']??$cur['suspended']);
    $blk=$d['blocked_rooms']??$cur['blocked_rooms'];
    $note=$d['note']??($cur['note']??null);
    $pdo->prepare("INSERT INTO session_member_controls (session_id,user_id,suspended,blocked_rooms,note,updated_by) VALUES(?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE suspended=VALUES(suspended),blocked_rooms=VALUES(blocked_rooms),note=VALUES(note),updated_by=VALUES(updated_by)")
        ->execute([$sid,$uid,$susp,json_encode(array_values(array_map('intval',$blk))),$note,$actor]);
}
/* إخراج إجباري: إغلاق كل أقفال العضو النشطة */
function smc_force_release_locks(PDO $pdo,int $sid,int $uid,int $actor,string $note): int {
    $st=$pdo->prepare("SELECT id,room_id FROM room_inventory_locks WHERE session_id=? AND locked_by=? AND status='active'");
    $st->execute([$sid,$uid]); $locks=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($locks as $L){
        $pdo->prepare("UPDATE room_inventory_locks SET status='superseded', note=? WHERE id=?")->execute([$note,$L['id']]);
        $pdo->prepare("INSERT INTO room_lock_events (lock_id,session_id,room_id,actor_id,event_type,note) VALUES(?,?,?,?,?,?)")
            ->execute([$L['id'],$sid,$L['room_id'],$actor,'force_exited',$note]);
    }
    return count($locks);
}
function smc_extend_lock(PDO $pdo,int $sid,int $uid,int $min): int {
    $st=$pdo->prepare("UPDATE room_inventory_locks SET expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE session_id=? AND locked_by=? AND status='active'");
    $st->execute([$min,$sid,$uid]); return $st->rowCount();
}
}