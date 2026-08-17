<?php
/**
 * receiving/form.php — (الإصدار الماسي المُحدث: Accessories Dropdown & Custom Modal & Bug Fixes)
 * نظام أصول - الاستلام الذكي
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('receiving.form');

function gregorianToHijri($ymd){
    if(!$ymd) return null;
    [$y,$m,$d]=array_map('intval',explode('-',$ymd));
    $jd = (int) floor((1461 * ($y + 4800 + floor(($m - 14) / 12))) / 4)
        + (int) floor((367 * ($m - 2 - 12 * floor(($m - 14) / 12))) / 12)
        - (int) floor((3 * floor(($y + 4900 + floor(($m - 14) / 12)) / 100)) / 4)
        + $d - 32075;
    $l = $jd - 1948440 + 10632;
    $n = (int) floor(($l - 1) / 10631);
    $l = $l - 10631 * $n + 354;
    $j = (int) floor((10985 - $l) / 5316) * (int) floor((50 * $l) / 17719)
        + (int) floor($l / 5670) * (int) floor((43 * $l) / 18199);
    $l = $l - (int) floor((30 - $j) / 15) * (int) floor((17719 * $j) / 50)
        - (int) floor($j / 16) * (int) floor((18199 * $j) / 43) + 29;
    $hm = (int) floor((24 * $l) / 709);
    $hd = (int) ($l - floor((709 * $hm) / 24));
    $hy = (int) (30 * $n + $j - 30);
    $months=['محرم','صفر','ربيع الأول','ربيع الآخر','جمادى الأولى','جمادى الآخرة','رجب','شعبان','رمضان','شوال','ذو القعدة','ذو الحجة'];
    return $hd.' '.$months[$hm-1].' '.$hy.'هـ';
}

$rtl = is_rtl();
$uid = (int)current_user()['id'];
$id  = (int)($_GET['id'] ?? 0);
$edit = $id > 0;

if ($edit && !can('receiving.form','edit'))    { flash('danger','غير مصرح'); header('Location:'.BASE_URL.'/receiving/index.php'); exit; }
if (!$edit && !can('receiving.form','create')) { flash('danger','غير مصرح'); header('Location:'.BASE_URL.'/receiving/index.php'); exit; }

$minute=[]; $existing_devices=[]; $minute_attachments=[];
if ($edit) {
    $s=$pdo->prepare("SELECT * FROM receiving_minutes WHERE id=? AND status='draft' LIMIT 1");
    $s->execute([$id]); $minute=$s->fetch();
    if (!$minute) { flash('danger','غير متاح للتعديل'); header('Location:'.BASE_URL.'/receiving/index.php'); exit; }
}

$all_depts_r = $pdo->query("SELECT id,name,parent_id,level FROM departments WHERE is_active=1 ORDER BY level,sort_order,name")->fetchAll();
$rdepts_by_parent = [];
foreach ($all_depts_r as $d) { $rdepts_by_parent[(int)($d['parent_id'] ?? 0)][] = $d; }

$mfrs_raw = $pdo->query("SELECT m.id AS mfr_id, m.name AS mfr_name, md.model_number FROM manufacturers m LEFT JOIN manufacturer_models md ON m.id = md.manufacturer_id ORDER BY m.name, md.model_number")->fetchAll(PDO::FETCH_ASSOC);
$mfr_dict = [];
foreach($mfrs_raw as $r) {
    $m = trim($r['mfr_name']); $mod = trim($r['model_number']);
    if($m === '') continue;
    if(!isset($mfr_dict[$m])) $mfr_dict[$m] = [];
    if($mod !== '' && !in_array($mod, $mfr_dict[$m])) { $mfr_dict[$m][] = $mod; }
}

$units_raw = $pdo->query("SELECT DISTINCT unit FROM receiving_minute_items WHERE unit IS NOT NULL AND unit != ''")->fetchAll(PDO::FETCH_COLUMN);
$default_units = ['جهاز', 'طقم', 'قطعة', 'كتالوج تشغيل', 'كتالوج صيانة وقطع غيار', 'بطاقة ضمان', 'سنة ضمان', 'عقد ضمان'];
$units_dict = array_unique(array_merge($default_units, $units_raw));
$doc_types=$pdo->query("SELECT DISTINCT name FROM receiving_doc_types WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!verify_csrf()) { $errors[]='خطأ في رمز التوثيق (CSRF). يرجى تحديث الصفحة والمحاولة مجدداً.'; }
    else {
        try {
            $pdo->beginTransaction();

            $f_comm  = (int)($_POST['committee_id']??0)?:null;
            $f_mtype = trim($_POST['maintenance_type'] ?? '');
            $f_scid  = (int)($_POST['standing_committee_id'] ??0)?:null;
            $f_date  = $_POST['receipt_date'] ?? null;
            $f_date_hijri = null; $f_day_en = null; $f_day_ar = null;
            
            if ($f_date) {
                $f_date_hijri = gregorianToHijri($f_date);
                $timestamp = strtotime($f_date);
                $f_day_en  = date('l', $timestamp);
                $days_ar_map = ['Sunday'=>'الأحد', 'Monday'=>'الإثنين', 'Tuesday'=>'الثلاثاء', 'Wednesday'=>'الأربعاء', 'Thursday'=>'الخميس', 'Friday'=>'الجمعة', 'Saturday'=>'السبت'];
                $f_day_ar  = $days_ar_map[$f_day_en] ?? '';
            }

            $f_pages = max(1,(int)($_POST['pages_count']??1));
            $f_supp  = trim($_POST['supplier_name']   ?? '');
            $f_dtype = trim($_POST['doc_type']         ?? '');
            $f_dnum  = trim($_POST['doc_number']        ?? '');
            $f_ddate = $_POST['doc_date']              ?? null;
            $f_ddate_hijri = gregorianToHijri($f_ddate);
            $f_notes = trim($_POST['notes']             ?? '');
            $action  = $_POST['form_action']            ?? 'draft';
            $devices = $_POST['devices']                ?? [];

            $mtype_to_asset_type = ['medical'=>'medical','general'=>'infrastructure','it'=>'it'];
            $server_asset_type = $mtype_to_asset_type[$f_mtype] ?? 'other';

            if(!$f_mtype || !isset($mtype_to_asset_type[$f_mtype])) $errors[]='يجب تحديد نوع الأصل من البوابة الذكية.';
            
            $valid_devices=[];
            foreach($devices as $di => $dv) {
                $desc = trim($dv['description']??'');
                $desc_ar = trim($dv['description_ar']??'');
                $qty = max(1,(int)($dv['quantity']??1));
                if(!$desc || !$desc_ar) { $errors[]="الوصف الإنجليزي والعربي إلزامي. تم إسقاط جهاز لعدم اكتمال الوصف."; continue; }
                
                $up=(float)($dv['unit_price']??0);
                $vat=(float)($dv['vat_amount']??0);
                $dept_id=(int)($dv['department_id']??0)?:null;
                $sub_id=(int)($dv['sub_department_id']??0)?:null;
                $recv_uid=(int)($dv['receiver_user_id']??0)?:null;
                $recv_name=trim($dv['receiver_name']??'')?:null;
                $recv_title=trim($dv['receiver_title']??'')?:null;
                // التحقق: sub_department_id يتبع department_id (لا يمكن sub بدون main)
                if($sub_id && !$dept_id) $sub_id = null;
                // التحقق: receiver_user_id>0 فقط
                if($recv_uid && $recv_uid<=0) $recv_uid = null;

                $vd=[
                    'item_code'=>trim($dv['item_code']??'')?:null,
                    'generic_code'=>trim($dv['generic_code']??'')?:null,
                    'category'=>trim($dv['category']??'')?:null,
                    'sub_category'=>trim($dv['sub_category']??'')?:null,
                    'description'=>$desc,
                    'description_ar'=>$desc_ar,
                    'manufacturer_name'=>trim($dv['manufacturer_name']??'')?:null,
                    'model_number'=>trim($dv['model_number']??'')?:null,
                    'unit'=>trim($dv['unit']??'جهاز'),
                    'quantity'=>$qty,
                    'unit_price'=>$up,
                    'vat_amount'=>$vat,
                    'total_price'=>round(($up*$qty)+$vat, 2),
                    'asset_type'=>$server_asset_type,
                    'criticality_class'=>($f_mtype==='medical')?($dv['criticality_class']??'C'):'C',
                    'manuals_operation'=>(int)($dv['manuals_operation']??0),
                    'manuals_maintenance'=>(int)($dv['manuals_maintenance']??0),
                    'cd_count'=>(int)($dv['cd_count']??0),
                    'notes'=>trim($dv['notes']??'')?:null,
                    'department_id'=>$dept_id,
                    'sub_department_id'=>$sub_id,
                    'receiver_user_id'=>$recv_uid,
                    'receiver_name'=>$recv_name,
                    'receiver_title'=>$recv_title,
                    'group_tag'=>$dv['group_tag'] ?? $di,
                    'accessories'=>$dv['accessories']??[]
                ];
                if(!$dept_id && $action==='submit') $errors[]="يجب تحديد القسم المستلم للجهاز: [ $desc ] في خطوة التوزيع اللوجستي.";
                $valid_devices[]=$vd;
            }
            if(empty($valid_devices) && empty($errors)) $errors[]='جدول الأصول فارغ. يجب إدراج حزمة واحدة على الأقل وتوجيهها بشكل صحيح.';

            if(empty($errors)) {
                $grand=0;
                foreach($valid_devices as $dv) {
                    $grand+=$dv['total_price'];
                    foreach($dv['accessories'] as $ac) {
                        $grand+=round(((float)($ac['unit_price']??0)*(int)($ac['quantity']??0))+(float)($ac['vat_amount']??0),2);
                    }
                }

                $status=$action==='submit'?'approved':'draft';
                if ($edit) {
                    $pdo->prepare("UPDATE receiving_minutes SET committee_id=?,maintenance_type=?,standing_committee_id=?,receipt_date=?,receipt_date_hijri=?,receipt_day_ar=?,receipt_day_en=?,pages_count=?,supplier_name=?,doc_type=?,doc_number=?,doc_date=?,doc_date_hijri=?,notes=?,total_value=?,status=? WHERE id=?")
                        ->execute([$f_comm,$f_mtype,$f_scid,$f_date,$f_date_hijri,$f_day_ar,$f_day_en,$f_pages,$f_supp,$f_dtype,$f_dnum,$f_ddate?:null,$f_ddate_hijri,$f_notes?:null,$grand,$status,$id]);
                    $pdo->prepare("DELETE FROM receiving_item_serials WHERE item_id IN(SELECT id FROM receiving_minute_items WHERE minute_id=?)")->execute([$id]);
                    $pdo->prepare("DELETE FROM receiving_minute_items WHERE minute_id=?")->execute([$id]);
                } else {
                    $yr=date('Y');
                    $seq=$pdo->query("SELECT COUNT(*)+1 FROM receiving_minutes WHERE YEAR(created_at)=$yr")->fetchColumn();
                    $num='RM/'.$yr.'/'.str_pad($seq,4,'0',STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO receiving_minutes (minute_number,committee_id,maintenance_type,standing_committee_id,receipt_date,receipt_date_hijri,receipt_day_ar,receipt_day_en,pages_count,supplier_name,doc_type,doc_number,doc_date,doc_date_hijri,notes,total_value,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$num,$f_comm,$f_mtype,$f_scid,$f_date,$f_date_hijri,$f_day_ar,$f_day_en,$f_pages,$f_supp,$f_dtype,$f_dnum,$f_ddate?:null,$f_ddate_hijri,$f_notes?:null,$grand,$status,$uid]);
                    $id=(int)$pdo->lastInsertId();
                }

                $si=$pdo->prepare("INSERT INTO receiving_minute_items (minute_id,parent_item_id,item_role,sequence_no,item_code,generic_code,category,sub_category,description,description_ar,manufacturer_name,model_number,unit,quantity,unit_price,vat_amount,total_price,is_main_device,asset_type,criticality_class,notes,department_id,sub_department_id,receiver_user_id,receiver_name,receiver_title,manuals_operation,manuals_maintenance,cd_count) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $sa=$pdo->prepare("INSERT INTO receiving_minute_items (minute_id,parent_item_id,item_role,sequence_no,item_code,generic_code,description,description_ar,manufacturer_name,model_number,unit,quantity,unit_price,vat_amount,total_price,is_main_device,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?)");

                $group_first_id = [];
                foreach($valid_devices as $di=>$dv) {
                    $si->execute([$id,null,'main',$di+1,$dv['item_code'],$dv['generic_code'],$dv['category'],$dv['sub_category'],$dv['description'],$dv['description_ar'],$dv['manufacturer_name'],$dv['model_number'],$dv['unit'],$dv['quantity'],$dv['unit_price'],$dv['vat_amount'],$dv['total_price'],1,$dv['asset_type'],$dv['criticality_class'],$dv['notes'],$dv['department_id'],$dv['sub_department_id'],$dv['receiver_user_id'],$dv['receiver_name'],$dv['receiver_title'],$dv['manuals_operation'],$dv['manuals_maintenance'],$dv['cd_count']]);
                    $did=(int)$pdo->lastInsertId();
                    $gtag = $dv['group_tag'];
                    if (!isset($group_first_id[$gtag])) { $group_first_id[$gtag] = $did; }
                    $pdo->prepare("UPDATE receiving_minute_items SET device_group_id=? WHERE id=?")->execute([$group_first_id[$gtag], $did]);
                    foreach($dv['accessories'] as $ai=>$ac) {
                        $adesc=trim($ac['description']??'');
                        if(!$adesc) continue;
                        $atot=round(((float)($ac['unit_price']??0)*(int)($ac['quantity']??1))+(float)($ac['vat_amount']??0),2);
                        $sa->execute([$id,$did,$ac['role']??'accessory',$ai+1,$ac['item_code']??null,$ac['generic_code']??null,$adesc,$adesc,null,null,$ac['unit']??'—',(int)($ac['quantity']??1),(float)($ac['unit_price']??0),(float)($ac['vat_amount']??0),$atot,null]);
                    }
                }

                if(!empty($_FILES['minute_attachments']['name'][0])) {
                    $upd=BASE_PATH.'/uploads/minutes/'.$id.'/';
                    if(!is_dir($upd)) mkdir($upd,0755,true);
                    $sf=$pdo->prepare("INSERT INTO receiving_minute_attachments (minute_id,file_name,file_path,file_size,file_type,uploaded_by) VALUES(?,?,?,?,?,?)");
                    foreach($_FILES['minute_attachments']['name'] as $fi=>$fn) {
                        if(!$fn||$_FILES['minute_attachments']['error'][$fi]) continue;
                        $ext=strtolower(pathinfo($fn,PATHINFO_EXTENSION));
                        $safe=time().'_'.$fi.'.'.$ext;
                        if(move_uploaded_file($_FILES['minute_attachments']['tmp_name'][$fi],$upd.$safe))
                            $sf->execute([$id,$fn,'minutes/'.$id.'/'.$safe,$_FILES['minute_attachments']['size'][$fi],$_FILES['minute_attachments']['type'][$fi],$uid]);
                    }
                }

                $pdo->commit();

                if($action==='submit') {
                    $minute_num_display = $num ?? $pdo->query("SELECT minute_number FROM receiving_minutes WHERE id=$id")->fetchColumn();
                    $view_link = BASE_URL.'/receiving/view.php?id='.$id;

                    if($f_scid){
                        $c_mems=$pdo->prepare("SELECT user_id FROM standing_committee_members WHERE committee_id=?");
                        $c_mems->execute([$f_scid]);
                        foreach($c_mems->fetchAll(PDO::FETCH_COLUMN) as $c_uid){
                            $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link,related_type,related_id) VALUES (?,?,?,?,?,?,?)")
                                ->execute([$c_uid,'receiving_approved', 'محضر استلام جديد للاعتماد', 'تم إعداد محضر استلام رقم '.$minute_num_display.'. يرجى المراجعة.', $view_link,'receiving_minute',$id]);
                        }
                    }
                    // ✅ الإصلاح: أرسل للمستلم الفعلي من receiving_minute_items (وليس رئيس القسم تلقائياً)
                    $dept_ids = array_unique(array_filter(array_column($valid_devices, 'department_id')));
                    $notified = []; // تتبع من تم إخطاره لتجنب التكرار
                    foreach($dept_ids as $did) {
                        if(!$did) continue;
                        // اجلب كل أجهزة هذا القسم في المحضر
                        $items_for_dept = array_filter($valid_devices, fn($dv)=>($dv['department_id']??0)==$did);
                        // اجمع كل المستلمين الفعليين
                        $receivers_for_dept = [];
                        $fallback_manager = null;
                        foreach($items_for_dept as $dv) {
                            $ruid = $dv['receiver_user_id'] ?? null;
                            if($ruid){
                                $receivers_for_dept[$ruid] = $dv['receiver_name'] ?? '';
                            }
                        }
                        // fallback: إذا لا يوجد receiver_user_id، استخدم رئيس القسم
                        if(empty($receivers_for_dept)){
                            $dm = $pdo->prepare("SELECT d.manager_id, u.full_name, u.job_title FROM departments d LEFT JOIN users u ON u.id=d.manager_id WHERE d.id=?");
                            $dm->execute([$did]);
                            $mgr = $dm->fetch();
                            if($mgr && $mgr['manager_id']){
                                $receivers_for_dept[$mgr['manager_id']] = $mgr['full_name'];
                                $fallback_manager = $mgr;
                            }
                        }
                        foreach($receivers_for_dept as $ruid => $rname){
                            if(isset($notified[$ruid])) continue; // لا تكرر
                            $notified[$ruid] = true;
                            $body_msg = $fallback_manager
                                ? 'تم اعتماد محضر رقم '.$minute_num_display.' يتضمن أصولاً موجهة للقسم الذي تترأسه. (لم يُحدَّد مستلم فعلي — تم الإرسال لرئيس القسم).'
                                : 'تم اعتماد محضر رقم '.$minute_num_display.' يتضمن أصولاً موجهة لك. يرجى الاستلام والمتابعة.';
                            $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,link,related_type,related_id) VALUES (?,?,?,?,?,?,?)")
                                ->execute([$ruid,'asset_received', 'أصول جديدة موجهة لك', $body_msg, $view_link,'receiving_minute',$id]);
                        }
                    }
                    flash('success','تم اعتماد المحضر وترحيل البيانات والتنبيهات بنجاح ✅');
                } else {
                    flash('success',$edit?'تم حفظ التعديلات':'تم حفظ المسودة بنجاح');
                }
                header('Location:'.BASE_URL.'/receiving/view.php?id='.$id); exit;

            } else { $pdo->rollBack(); }
        } catch (Exception $e) { $pdo->rollBack(); error_log('receiving/form.php: '.$e->getMessage()); $errors[] = "حدث خطأ غير متوقع أثناء المعالجة بقاعدة البيانات. حاول مجدداً أو راجع الدعم الفني."; }
    }
}

$page_title='إنشاء محضر استلام';
$active_nav='receiving.index';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body { background-color: #f8fafc; color: #334155; font-family: 'Tajawal', sans-serif; }
.eng-num { font-family: 'Inter', Tahoma, sans-serif !important; direction: ltr !important; text-align: center; font-weight:700; }
input[type="date"].eng-num { text-align: right; }
.req { color: #ef4444; font-weight:900; margin-right: 4px; }

/* تأثير الاهتزاز والخطأ */
@keyframes shake { 0%, 100% {transform: translateX(0);} 25% {transform: translateX(-5px);} 75% {transform: translateX(5px);} }
.error-highlight { border: 2px solid #ef4444 !important; box-shadow: 0 0 10px rgba(239,68,68,0.3) !important; animation: shake 0.4s ease-in-out; background-color:#fef2f2 !important; }

/* شريط التتبع الإداري المطور (الملكي) */
.wizard-header { 
    display: flex; justify-content: space-between; align-items: center; 
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); 
    padding: 24px 35px; border-radius: 16px; border: 1px solid #1d4ed8; 
    box-shadow: 0 10px 30px -5px rgba(30,58,138,0.3); margin-bottom: 24px; position: relative; overflow: hidden; 
}
.wizard-step-ind { flex: 1; text-align: center; position: relative; z-index: 2; transition: 0.4s; color: #93c5fd; font-weight: 800; font-size: 13.5px; }
.wizard-step-ind.active { color: #ffffff; transform: scale(1.05); }
.wizard-step-ind.completed { color: #6ee7b7; }
.wizard-step-icon { 
    width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; 
    margin: 0 auto 10px auto; font-size: 16px; border: 2px solid rgba(255,255,255,0.2); transition: 0.4s; color: #bfdbfe;
}
.wizard-step-ind.active .wizard-step-icon { 
    background: #ffffff; color: #1e3a8a; border: none;
    box-shadow: 0 0 20px rgba(255,255,255,0.5); animation: activePulse 2s infinite ease-in-out; 
}
@keyframes activePulse { 0% { box-shadow: 0 0 0 0 rgba(255,255,255, 0.6); } 70% { box-shadow: 0 0 0 15px rgba(255,255,255, 0); } 100% { box-shadow: 0 0 0 0 rgba(255,255,255, 0); } }
.wizard-step-ind.completed .wizard-step-icon { background: #10b981; color: #ffffff; border: none; box-shadow: 0 0 12px rgba(16, 185, 129, 0.6); }
.wizard-progress-bar { position: absolute; top: 44px; left: 12.5%; right: 12.5%; height: 4px; background: rgba(255,255,255,0.1); z-index: 1; border-radius: 2px; }
.wizard-progress-fill { height: 100%; background: linear-gradient(90deg, #10b981, #34d399); width: 0%; transition: 0.6s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 0 10px rgba(16,185,129,0.5); }

/* البطاقات */
.step-card { position: absolute; top: 0; left: 0; width: 100%; opacity: 0; visibility: hidden; transform: translateY(15px); transition: all 0.4s ease; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 30px; box-sizing: border-box; }
.step-card.active { opacity: 1; visibility: visible; transform: translateY(0); position: relative; z-index: 10; }
.rfi { height: 38px; padding: 0 12px; background: #ffffff; border: 1.5px solid #cbd5e1; border-radius: 8px; font-family: 'Tajawal'; font-size: 13.5px; font-weight: 700; width: 100%; box-sizing: border-box; transition: 0.2s; }
.rfi:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12); outline: none; }
.rfi:read-only { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }

/* البوابة الذكية */
.gate-btn { background: linear-gradient(145deg, #ffffff, #f8fafc); border: 2px solid #e2e8f0; border-radius: 16px; padding: 30px 20px; font-size: 18px; font-weight: 900; color: #334155; cursor: pointer; transition: 0.3s; flex: 1; display: flex; flex-direction: column; align-items: center; gap: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
.gate-btn i { font-size: 40px; transition: 0.3s; }
.gate-med:hover { border-color: #10b981; color: #047857; background: linear-gradient(145deg, #f0fdf4, #ecfdf5); transform: translateY(-5px); box-shadow: 0 15px 30px rgba(16,185,129,0.15); }
.gate-med:hover i { color: #10b981; }
.gate-gen:hover { border-color: #f59e0b; color: #b45309; background: linear-gradient(145deg, #fffbeb, #fef3c7); transform: translateY(-5px); box-shadow: 0 15px 30px rgba(245,158,11,0.15); }
.gate-gen:hover i { color: #f59e0b; }
.gate-it:hover { border-color: #3b82f6; color: #1d4ed8; background: linear-gradient(145deg, #eff6ff, #dbeafe); transform: translateY(-5px); box-shadow: 0 15px 30px rgba(59,130,246,0.15); }
.gate-it:hover i { color: #3b82f6; }

/* بطاقة الأصل NUPCO */
.nupco-badge-card { background:linear-gradient(to left, #f0fdf4, #ecfdf5); border:1px solid #bbf7d0; border-radius:8px; padding:12px; margin-top:8px; display:none; grid-template-columns: 1fr 1fr 1fr; gap: 10px; text-align: center; }
.nupco-badge-card.active { display:grid; animation: fadeIn 0.4s ease; }
@keyframes fadeIn { from { opacity:0; transform:translateY(-5px); } to { opacity:1; transform:translateY(0); } }

/* التخطيط */
.split-layout { display: flex; gap: 24px; margin-top: 20px; align-items: stretch; }
.col-asset { flex: 0 0 32%; background: #ffffff; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; display: flex; flex-direction: column; transition: 0.3s; }
.asset-header-3d { background: linear-gradient(180deg, #475569 0%, #1e293b 100%); color: #ffffff; font-weight: 900; font-size: 14px; padding: 15px; text-align: center; border-bottom: 3px solid #0f172a; }
.col-asset.nupco-active { border: 2px solid #10b981; animation: breathingGlow 2.5s infinite ease-in-out; }
.mini-card-dark { background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; margin-bottom:12px; }
.col-supplier { flex: 1; }
.specs-wrapper { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
.spec-row { padding: 15px; border-radius: 8px; margin-bottom: 15px; border: 1px solid; }

/* القوائم والجدول */
.smart-table { width:100% !important; min-width:1250px !important; border-collapse:collapse; } 
.smart-table th { background:#f1f5f9; padding:12px 6px; font-size:12px; font-weight:800; border:1px solid #cbd5e1; text-align:center; }
.smart-table td { padding:6px; border:1px solid #e2e8f0; vertical-align:middle; }
.t-inp { width:100%; height:30px; border:1.5px solid #cbd5e1; border-radius:6px; padding:0 6px; font-size:12px; font-weight:700; }
.file-chip { display:inline-flex; align-items:center; gap:8px; background:#f1f5f9; border:1px solid #cbd5e1; padding:6px 12px; border-radius:20px; font-size:12px; font-weight:bold; color:#334155; margin:4px; }

/* النافذة المنبثقة للاعتماد النهائي */
.modal-overlay { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; backdrop-filter:blur(4px); align-items:center; justify-content:center; }
.modal-box { background:#fff; border-radius:16px; padding:30px; width:450px; max-width:90%; text-align:center; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); animation:scaleIn 0.3s ease; }
@keyframes scaleIn { from { transform:scale(0.9); opacity:0; } to { transform:scale(1); opacity:1; } }
</style>
</head>
<body class="app-layout">

<!-- نافذة الاعتماد المنبثقة -->
<div id="submitConfirmModal" class="modal-overlay">
    <div class="modal-box">
        <div style="width:70px; height:70px; background:#dcfce7; color:#10b981; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:30px; margin:0 auto 20px auto;"><i class="fa-solid fa-check-double"></i></div>
        <h3 style="color:#1e293b; font-weight:900; margin-top:0;">تأكيد اعتماد المحضر</h3>
        <p style="color:#64748b; font-size:14px; margin-bottom:25px;">هل تم التحقق من كافة البيانات (التوريد، التوزيع اللوجستي، المرفقات) وترغب فعلياً في الاعتماد النهائي وترحيل البيانات؟</p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button type="button" onclick="document.getElementById('submitConfirmModal').style.display='none'" style="background:#f1f5f9; color:#475569; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;">إلغاء ومراجعة</button>
            <button type="button" onclick="System.executeSubmit()" style="background:#10b981; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer; box-shadow:0 4px 12px rgba(16,185,129,0.3);">نعم، اعتمد المحضر</button>
        </div>
    </div>
</div>

<datalist id="modelList_gen"></datalist>
<datalist id="unitList_gen"><?php foreach($units_dict as $u): ?><option value="<?= e($u) ?>"></option><?php endforeach; ?></datalist>
<datalist id="mfrList_gen"><?php foreach(array_keys($mfr_dict) as $m): ?><option value="<?= e($m) ?>"></option><?php endforeach; ?></datalist>

<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content" style="padding: 20px 40px;">

<!-- صندوق عرض الأخطاء الإدارية -->
<?php if(!empty($errors)): ?>
<div style="background:#fef2f2; border:1px solid #f87171; color:#b91c1c; padding:18px; border-radius:8px; margin-bottom:20px; box-shadow:0 4px 12px rgba(239,68,68,0.1);">
    <strong style="font-size:15px;"><i class="fa-solid fa-triangle-exclamation"></i> تنبيه إداري: لم يتم اعتماد المحضر لوجود النواقص التالية:</strong>
    <ul style="margin-top:12px; margin-bottom:0; padding-inline-start:25px; font-weight:bold;">
        <?php foreach($errors as $err): ?> <li><?= e($err) ?></li> <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- شريط المراقبة الإداري العلوي -->
<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <h3 style="margin:0; color:#1e3a8a; font-weight:900;"><i class="fa-solid fa-file-signature"></i> إنشاء محضر استلام</h3>
    <button type="button" onclick="System.resetDeep()" style="background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; padding:8px 16px; border-radius:6px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-rotate-right"></i> تطهير البيانات والعودة للصفر</button>
</div>

<div class="wizard-header">
    <div class="wizard-progress-bar"><div class="wizard-progress-fill" id="wizardProgress"></div></div>
    <div class="wizard-step-ind active" id="ind-step-1"><div class="wizard-step-icon"><i class="fa-solid fa-boxes-packing"></i></div> 1. تكوين السلة</div>
    <div class="wizard-step-ind" id="ind-step-2"><div class="wizard-step-icon"><i class="fa-solid fa-puzzle-piece"></i></div> 2. الملحقات والضمان</div>
    <div class="wizard-step-ind" id="ind-step-3"><div class="wizard-step-icon"><i class="fa-solid fa-route"></i></div> 3. التوجيه اللوجستي</div>
    <div class="wizard-step-ind" id="ind-step-4"><div class="wizard-step-icon"><i class="fa-solid fa-fingerprint"></i></div> 4. المراجعة والاعتماد</div>
</div>

<form method="POST" enctype="multipart/form-data" id="rfForm">
<?= csrf_input() ?>
<input type="hidden" name="form_action" id="fAction" value="draft">
<input type="hidden" name="maintenance_type" id="hMaintType">
<input type="hidden" name="standing_committee_id" id="scId" value="">

<div class="wizard-body">

    <!-- الخطوة 1 -->
    <div class="step-card active" id="step-1">
        <div id="smartGateZone" style="text-align:center; padding: 20px 40px 40px 40px;">
           <h2 style="color: #1e3a8a; font-weight: 900; margin-bottom: 30px;"><i class="fa-solid fa-door-open"></i> البوابة الذكية: حدد تصنيف الأصل المراد توريده</h2>
           <div style="display:flex; justify-content:center; gap:25px; flex-wrap:wrap;">
              <button type="button" onclick="System.selectGate('medical')" class="gate-btn gate-med"><i class="fa-solid fa-stethoscope"></i> أصل طبي</button>
              <button type="button" onclick="System.selectGate('general')" class="gate-btn gate-gen"><i class="fa-solid fa-couch"></i> أصل عام / أثاث</button>
              <button type="button" onclick="System.selectGate('it')" class="gate-btn gate-it"><i class="fa-solid fa-computer"></i> أصل تقني (IT)</button>
           </div>
        </div>

        <div id="searchHeroZone" style="display:none; text-align:center; padding:45px 20px; background:#f8fafc; border-radius:12px; border:2px dashed #94a3b8; margin-bottom:20px;">
            <h2 style="color:#0f172a; font-weight:900; margin-bottom:15px;"><i class="fa-solid fa-satellite-dish"></i> محرك البحث الذكي <span style="background:#10b981; color:#fff; padding:4px 12px; border-radius:20px; font-size:13px;">NUPCO</span></h2>
            <div style="position:relative; max-width:600px; margin:0 auto;">
                <button type="button" onclick="System.startVoiceSearch()" style="position:absolute; top:8px; left:8px; width:38px; height:38px; border-radius:50%; background:#fff; border:1px solid #e2e8f0; color:#64748b; cursor:pointer; z-index:5;"><i class="fa-solid fa-microphone"></i></button>
                <input type="text" id="gSearchInput" class="rfi eng-num" placeholder="انطق أو اكتب رقم الصنف الطبي للبحث..." autocomplete="off" style="height:54px; border-radius:27px; text-align:center; font-size:16px; padding-left:55px;">
                <div id="nupcoDrop" style="position:absolute; top:100%; right:0; left:0; background:#fff; border:1px solid #cbd5e1; border-radius:12px; display:none; max-height:250px; overflow-y:auto; z-index:100; text-align:right; margin-top:8px; box-shadow:0 10px 25px rgba(0,0,0,0.1);"></div>
            </div>
        </div>

        <div id="combinedDataArea" style="display:none;">
            <div class="split-layout">
                <!-- 30% -->
                <div class="col-asset" id="assetCardZone">
                    <div class="asset-header-3d"><i class="fa-solid fa-microchip"></i> بطاقة الأصل | ASSET TAG</div>
                    <div style="padding: 20px; flex:1; display:flex; flex-direction:column;">
                        <input type="hidden" id="gGenericCode"><input type="hidden" id="gCategory"><input type="hidden" id="gSubCategory">
                        
                        <div class="mini-card-dark">
                            <label style="font-size:12px; font-weight:800; color:#475569; display:block; margin-bottom:6px;">رقم الصنف (Generic Code) <span class="req">*</span></label>
                            <input type="text" id="gItemCodeVisible" class="rfi eng-num" style="text-align:left; color:#1e40af;">
                            <div id="nupcoDataCard" class="nupco-badge-card">
                               <div><span style="font-size:10px; color:#065f46;">رقم التصنيف</span><br><span id="lblGenCode" style="font-weight:900; color:#047857; font-size:13px;" class="eng-num">---</span></div>
                               <div><span style="font-size:10px; color:#065f46;">التصنيف الرئيسي</span><br><span id="lblCat" style="font-weight:900; color:#047857; font-size:12px;">---</span></div>
                               <div><span style="font-size:10px; color:#065f46;">التصنيف الفرعي</span><br><span id="lblSubCat" style="font-weight:900; color:#047857; font-size:12px;">---</span></div>
                            </div>
                        </div>

                        <div class="mini-card-dark">
                            <label style="font-size:12px; font-weight:800; color:#475569; display:block; margin-bottom:6px;">الوصف المعتمد (EN) <span class="req">*</span></label>
                            <input type="text" id="gDescVisible" class="rfi eng-num" style="text-align:left;">
                            <label style="font-size:12px; font-weight:800; color:#475569; display:block; margin-top:8px; margin-bottom:6px;">المسمى العربي (AR) <span class="req">*</span></label>
                            <input type="text" id="gDescAr" class="rfi" placeholder="أدخل المسمى العربي...">
                        </div>
                        
                        <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:12px; text-align:center; margin-bottom:12px;">
                            <label style="font-size:12px; font-weight:800; color:#b45309;"><i class="fa-solid fa-users-gear"></i> جهة الفحص والمصادقة</label>
                            <div id="scMembers" style="font-size:11.5px; font-weight:bold; color:#78350f; margin-top:5px;">بانتظار التوجيه...</div>
                        </div>

                        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:12px;">
                            <label style="font-size:12px; font-weight:800; color:#1e40af;"><i class="fa-solid fa-truck"></i> بيانات التوريد</label>
                            <input type="text" id="inpSupplier" class="rfi" placeholder="الشركة الموردة *" style="margin-bottom:8px;">
                            <select id="inpDocType" class="rfi">
                                <option value="">— اختر نوع التعميد * —</option>
                                <?php foreach($doc_types as $dt): ?><option value="<?= e($dt) ?>"><?= e($dt) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="button" onclick="System.cancelCurrentDevice()" style="background:none; border:none; color:#ef4444; font-weight:bold; width:100%; margin-top:auto; padding-top:15px; cursor:pointer;"><i class="fa-solid fa-times"></i> إلغاء إدخال هذا الأصل</button>
                    </div>
                </div>

                <!-- 70% -->
                <div class="col-supplier">
                    <div class="specs-wrapper">
                        <div style="background:linear-gradient(90deg,#f1f5f9,#fff); border-right:4px solid #3b82f6; padding:10px 15px; font-weight:900; color:#1e3a8a; margin-bottom:15px; border-radius:6px;"><i class="fa-solid fa-list-check"></i> المواصفات الفنية والمالية والإدارية</div>
                        
                        <div class="spec-row" style="background:#f8fafc; border-color:#e2e8f0; display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; padding:12px;">
                            <div><label style="font-size:11.5px; font-weight:800;">تاريخ الاستلام <span class="req">*</span></label><input type="date" id="inpRecDate" class="rfi eng-num" onchange="System.updateDates()"></div>
                            <div><label style="font-size:11.5px; font-weight:800;">رقم المستند <span class="req">*</span></label><input type="text" id="inpDocNum" class="rfi eng-num"></div>
                            <div><label style="font-size:11.5px; font-weight:800;">تاريخ المستند <span class="req">*</span></label><input type="date" id="inpDocDate" class="rfi eng-num"></div>
                            <div><label style="font-size:11.5px; font-weight:800;">الصفحات</label><input type="number" id="inpPages" class="rfi eng-num" value="1" min="1"></div>
                        </div>
                        <input type="hidden" name="receipt_date" id="globalRecDate"><input type="hidden" name="doc_number" id="globalDocNum"><input type="hidden" name="doc_date" id="globalDocDate"><input type="hidden" name="pages_count" id="globalPages">
                        
                        <div class="spec-row" style="background:#f0fdf4; border-color:#bbf7d0; display:grid; grid-template-columns:1fr 1fr 2fr; gap:12px; padding:12px;">
                            <div><label style="font-size:11.5px; font-weight:800; color:#047857;">العدد <span class="req">*</span></label><input type="number" id="gQty" class="rfi eng-num" value="1" min="1" style="border-color:#10b981; font-size:15px;"></div>
                            <div><label style="font-size:11.5px; font-weight:800; color:#047857;">الوحدة <span class="req">*</span></label><input list="unitList_gen" id="gUnit" class="rfi" value="جهاز" style="text-align:center; border-color:#10b981;"></div>
                            <div><label style="font-size:11.5px; font-weight:800; color:#047857;">تكلفة الوحدة (SAR) <span class="req">*</span></label>
                                <div style="display:flex; align-items:center; border:1.5px solid #bbf7d0; background:#fff; border-radius:8px; padding-left:10px; height:38px;">
                                    <input type="number" id="gPrice" class="rfi eng-num" value="" step="0.01" style="border:none; outline:none; height:100%; width:100%; box-shadow:none;">
                                    <label style="font-size:12px; font-weight:900; color:#059669; white-space:nowrap; cursor:pointer;"><input type="checkbox" id="gVatCheck" checked> +15% ضريبة</label>
                                </div>
                            </div>
                        </div>

                        <div class="spec-row" style="background:#fff7ed; border-color:#fed7aa; display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; padding:12px;">
                            <div><label style="font-size:11.5px; font-weight:800; color:#9a3412;">الشركة المصنعة</label><input list="mfrList_gen" id="gMfr" class="rfi" onchange="System.filterModels()" style="border-color:#fed7aa;"></div>
                            <div><label style="font-size:11.5px; font-weight:800; color:#9a3412;">الموديل</label><input list="modelList_gen" id="gModel" class="rfi" style="border-color:#fed7aa;"></div>
                            <div><label style="font-size:11.5px; font-weight:800; color:#9a3412;">الضمان الأساسي (سنة)</label><input type="number" id="gBasicWarranty" class="rfi eng-num" value="1" min="0" style="border-color:#fed7aa;"></div>
                        </div>

                        <div class="spec-row" style="background:#f3e8ff; border-color:#e9d5ff; padding:12px;">
                            <label style="font-size:11.5px; font-weight:800; color:#7e22ce; display:block; margin-bottom:8px;"><i class="fa-solid fa-folder-tree"></i> المرفقات التزامنية للكتلة:</label>
                            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px;">
                                <div style="display:flex; align-items:center; gap:5px;"><span style="font-size:11px; font-weight:bold; color:#6b21a8;">تشغيل:</span><input type="number" id="gManualsOp" class="rfi eng-num" value="1" min="0" style="height:32px; padding:0 5px; border-color:#e9d5ff;"></div>
                                <div style="display:flex; align-items:center; gap:5px;"><span style="font-size:11px; font-weight:bold; color:#6b21a8;">صيانة:</span><input type="number" id="gManualsMaint" class="rfi eng-num" value="1" min="0" style="height:32px; padding:0 5px; border-color:#e9d5ff;"></div>
                                <div style="display:flex; align-items:center; gap:5px;"><span style="font-size:11px; font-weight:bold; color:#6b21a8;">أقراص:</span><input type="number" id="gCDs" class="rfi eng-num" value="1" min="0" style="height:32px; padding:0 5px; border-color:#e9d5ff;"></div>
                            </div>
                        </div>
                        <button type="button" onclick="System.addToBasket()" style="background:#1e40af; color:#fff; border:none; padding:12px; border-radius:8px; font-weight:bold; font-size:15px; cursor:pointer; width:100%; margin-top:auto;"><i class="fa-solid fa-cart-plus"></i> إدراج هذا الأصل في السلة</button>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="basketZone" style="margin-top:25px; border-top:2px dashed #cbd5e1; padding-top:20px; display:none;">
            <h4 style="color:#1e3a8a; font-weight:900; margin-top:0;"><i class="fa-solid fa-boxes-stacked"></i> سلة الأصول المتراكمة في المحضر</h4>
            <div id="basketItems" style="display:flex; flex-wrap:wrap; gap:12px;"></div>
        </div>
        <div id="wFoot1" style="display:none; justify-content:flex-end; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:20px;">
            <button type="button" onclick="System.goTo(2)" style="background:#1e40af; color:#fff; border:none; padding:12px 25px; border-radius:8px; font-weight:bold; cursor:pointer;">تأكيد السلة والانتقال للملحقات <i class="fa-solid fa-arrow-left"></i></button>
        </div>
    </div>

    <!-- الخطوة 2 -->
    <div class="step-card" id="step-2">
        <h3 style="color:#1e3a8a; font-weight:900; margin-top:0;"><i class="fa-solid fa-puzzle-piece"></i> تفصيل الملحقات والضمانات الفنية</h3>
        <p style="color:#64748b; font-size:13px; margin-bottom:20px;">أضف الملحقات والضمانات لكل جهاز. استخدم القائمة المنسدلة للبحث عن الأصناف بدقة.</p>
        <div id="step2Container"></div> 
        <div style="display:flex; justify-content:space-between; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:20px;">
            <button type="button" onclick="System.goTo(1)" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-arrow-right"></i> تراجع للسلة</button>
            <button type="button" onclick="System.generateMatrix()" style="background:#10b981; color:#fff; border:none; padding:10px 25px; border-radius:8px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-wand-magic-sparkles"></i> توليد المصفوفة <i class="fa-solid fa-arrow-left"></i></button>
        </div>
    </div>

    <!-- الخطوة 3 -->
    <div class="step-card" id="step-3">
        <h3 style="color:#1e3a8a; font-weight:900; margin-top:0;"><i class="fa-solid fa-route"></i> التوزيع اللوجستي للأقسام</h3>
        <div id="distBar" style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:15px; margin-bottom:18px; display:none;">
            <div style="font-size:14px; font-weight:800; color:#1e40af; margin-bottom:12px;">شريط التوزيع الذكي <span style="background:#fee2e2; color:#b91c1c; padding:2px 10px; border-radius:20px; font-size:12px; margin-right:10px;">غير موزع: <span id="unassignedCount" class="eng-num">0</span></span></div>
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr 90px 130px; gap:12px; align-items:end;">
                <div><label style="font-size:12px; font-weight:800;">المحطة</label><select id="distL1" class="rfi" onchange="System.fillDistL2()"></select></div>
                <div><label style="font-size:12px; font-weight:800;">القسم الفرعي</label><select id="distL2" class="rfi" style="display:none" onchange="System.fillDistL3()"></select></div>
                <div><label style="font-size:12px; font-weight:800;">الموظف</label><select id="distL3" class="rfi" style="display:none" onchange="System.fetchDistManager()"></select></div>
                <div><label style="font-size:12px; font-weight:800;">الكمية</label><input type="number" id="distQty" class="rfi eng-num" value="1" min="1" style="text-align:center;"></div>
                <button type="button" onclick="System.applyDistribution()" style="background:#1e40af; color:#fff; border:none; height:38px; border-radius:8px; font-weight:bold; cursor:pointer;">تطبيق التوزيع</button>
                <div id="distManagerName" style="grid-column: span 5; font-size:12px; font-weight:bold; color:#065f46;"></div>
            </div>
        </div>
        <div style="width:100%; overflow-x:auto; border:1px solid #cbd5e1; border-radius:8px; background:#fff;">
          <table class="smart-table" id="smartTable">
            <colgroup><col style="width: 40px;"><col style="width: 140px;"><col style="width: auto;"><col style="width: 140px;"><col style="width: 60px;"><col style="width: 110px;"><col style="width: 90px;"><col style="width: 110px;"><col style="width: 180px;"></colgroup>
            <thead><tr><th>#</th><th>رقم الصنف</th><th style="text-align:right;">الوصف (EN/AR)</th><th style="text-align:right">المصنع</th><th>الكمية</th><th>التكلفة</th><th>الضريبة</th><th>الإجمالي</th><th style="text-align:right">توجيه القسم</th></tr></thead>
          </table>
        </div>
        <div style="display:flex; justify-content:space-between; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:20px;">
            <button type="button" onclick="System.goTo(2)" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-arrow-right"></i> تراجع للملحقات</button>
            <button type="button" onclick="System.prepareReview()" style="background:#1e3a8a; color:#fff; border:none; padding:10px 25px; border-radius:8px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-eye"></i> المراجعة والاعتماد <i class="fa-solid fa-arrow-left"></i></button>
        </div>
    </div>

    <!-- الخطوة 4 -->
    <div class="step-card" id="step-4">
        <h3 style="color:#1e3a8a; font-weight:900; margin-top:0;"><i class="fa-solid fa-clipboard-check"></i> المراجعة النهائية والمصادقة</h3>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px;">
                <h4 style="color:#1e3a8a; font-weight:900; margin-top:0; border-bottom:1px solid #cbd5e1; padding-bottom:8px;">المرجع التوثيقي الإداري</h4>
                <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b; font-weight:bold;">المورد</span> <span id="rev_supplier" style="font-weight:900;">---</span></div>
                <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b; font-weight:bold;">نوع المستند</span> <span id="rev_doctype" style="font-weight:900;">---</span></div>
                <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b; font-weight:bold;">رقم المستند</span> <span id="rev_docnum" class="eng-num" style="font-weight:900; color:#1e40af;">---</span></div>
                <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b; font-weight:bold;">تاريخ المستند</span> <span id="rev_docdate" style="font-weight:900; font-size:12px;">---</span></div>
                <div style="display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px dashed #e2e8f0;"><span style="color:#64748b; font-weight:bold;">تاريخ الاستلام</span> <span id="rev_recdate" style="font-weight:900; font-size:12px;">---</span></div>
                <div style="display:flex; justify-content:space-between; padding:6px 0;"><span style="color:#64748b; font-weight:bold;">اللجنة المعتمدة</span> <span id="rev_committee" style="font-weight:900;">---</span></div>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:20px; display:flex; flex-direction:column;">
                <h4 style="color:#1e3a8a; font-weight:900; margin-top:0; border-bottom:1px solid #cbd5e1; padding-bottom:8px;">شجرة ملخص الكتلة المستلمة</h4>
                <div id="rev_details_list" style="flex:1; overflow-y:auto; max-height:220px; padding-right:5px;"></div>
            </div>
        </div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <div><label style="font-size:12px; font-weight:bold;">ملاحظات عامة</label><textarea name="notes" class="rfi" style="height:80px; resize:vertical; padding-top:10px;"></textarea></div>
            <div>
                <label style="font-size:12px; font-weight:bold;">مرفقات المحضر (PDF/JPG)</label>
                <input type="file" name="minute_attachments[]" multiple class="rfi" style="padding-top:6px;" onchange="System.updateFileChips(this)">
                <div id="fileChips" style="margin-top:8px;"></div>
            </div>
        </div>
        <div style="background:#fff; padding:25px; border-radius:12px; margin-top:30px; border:1px solid #cbd5e1; box-shadow:0 10px 25px rgba(0,0,0,0.03);">
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
                <div style="text-align:center; padding:15px; border:1px solid #e2e8f0; border-radius:8px;"><div style="font-size:12px; color:#64748b; font-weight:bold;">الصافي</div><div id="rev_subtotal" class="eng-num" style="font-size:22px; font-weight:900; color:#1e3a8a;">0.00</div></div>
                <div style="text-align:center; padding:15px; border:1px solid #fecdd3; background:#fff5f5; border-radius:8px;"><div style="font-size:12px; color:#e11d48; font-weight:bold;">الضريبة (15%)</div><div id="rev_vat" class="eng-num" style="font-size:22px; font-weight:900; color:#e11d48;">0.00</div></div>
                <div style="text-align:center; padding:15px; border:1px solid #6ee7b7; background:#f0fdf4; border-radius:8px;"><div style="font-size:12px; color:#047857; font-weight:bold;">الإجمالي (SAR)</div><div id="rev_grandtotal" class="eng-num" style="font-size:26px; font-weight:900; color:#047857;">0.00</div></div>
            </div>
            <div style="display:flex; justify-content:space-between; margin-top:25px; padding-top:20px; border-top:1px solid #cbd5e1;">
                <button type="button" onclick="System.goTo(3)" style="background:#f1f5f9; color:#475569; border:1px solid #cbd5e1; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-arrow-right"></i> فك الاندماج</button>
                <div style="display:flex; gap:12px;">
                    <button type="button" onclick="System.doSave('draft')" style="background:#64748b; color:#fff; border:none; padding:10px 20px; border-radius:8px; font-weight:bold; cursor:pointer;">حفظ كمسودة</button>
                    <button type="button" onclick="document.getElementById('submitConfirmModal').style.display='flex'" style="background:#10b981; color:#fff; border:none; padding:12px 30px; border-radius:8px; font-weight:900; font-size:15px; cursor:pointer; box-shadow:0 4px 15px rgba(16,185,129,0.3);"><i class="fa-solid fa-check-double"></i> اعتماد المحضر وترحيل البيانات</button>
                </div>
            </div>
        </div>
    </div>
</div>
<input type="hidden" name="supplier_name" id="hSupplier">
<input type="hidden" name="doc_type" id="hDocType">
</form>
</main></div>

<script>
function escapeHTML(str) { return str ? str.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;') : ''; }

const _BASE = '<?= BASE_URL ?>';
const _RDEPTS = <?= json_encode($rdepts_by_parent, JSON_UNESCAPED_UNICODE) ?>;
const _MFR = <?= json_encode($mfr_dict, JSON_UNESCAPED_UNICODE) ?>;

const System = {
    basket: [], currentStep: 1, globalAdminSet: false, accTimers: {}, 
    
    selectGate: function(type) {
        document.getElementById('hMaintType').value = type; document.getElementById('smartGateZone').style.display = 'none';
        this.fetchCommittee(type);
        type === 'medical' ? document.getElementById('searchHeroZone').style.display = 'block' : this.enableManualEntry();
    },

    resetDeep: function() {
        if(!confirm('سيتم مسح كافة الأجهزة. هل أنت متأكد؟')) return;
        this.basket = []; this.globalAdminSet = false;
        document.querySelectorAll('input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"])').forEach(i => i.value = '');
        document.getElementById('basketZone').style.display = 'none'; document.getElementById('combinedDataArea').style.display = 'none';
        document.getElementById('searchHeroZone').style.display = 'none'; document.getElementById('smartGateZone').style.display = 'block';
        document.getElementById('wFoot1').style.display = 'none'; document.getElementById('assetCardZone').classList.remove('nupco-active');
        document.querySelectorAll('.error-highlight').forEach(el => el.classList.remove('error-highlight')); this.goTo(1);
    },

    goTo: function(step) {
        if(step === 2 && this.basket.length === 0) { alert('السلة فارغة!'); return; }
        document.querySelectorAll('.step-card').forEach((el, i) => { el.classList.remove('active'); el.style.transform = (i+1 < step) ? 'translateY(-15px)' : 'translateY(15px)'; el.style.opacity = '0'; });
        const tgt = document.getElementById(`step-${step}`); tgt.style.transform = 'translateY(0)'; tgt.style.opacity = '1'; tgt.classList.add('active');
        document.querySelectorAll('.wizard-step-ind').forEach((el, i) => { el.classList.remove('active', 'completed'); if (i+1 < step) el.classList.add('completed'); if (i+1 === step) el.classList.add('active'); });
        document.getElementById('wizardProgress').style.width = `${((step - 1) / 3) * 100}%`; this.currentStep = step; window.scrollTo({top: 0, behavior: 'smooth'});
        if(step === 2) this.renderStep2();
    },

    startVoiceSearch: function() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) { alert("المتصفح لا يدعم البحث الصوتي."); return; }
        const rec = new SpeechRecognition(); rec.lang = 'ar-SA';
        rec.onstart = () => document.getElementById('gSearchInput').style.backgroundColor = '#fee2e2';
        rec.onend = () => document.getElementById('gSearchInput').style.backgroundColor = '#fff';
        rec.onresult = (e) => {
            let txt = e.results[0][0].transcript.replace(/[\u0660-\u0669]/g, d => d.charCodeAt(0) - 1632).replace(/\s+/g, '');
            document.getElementById('gSearchInput').value = txt.toUpperCase(); document.getElementById('gSearchInput').dispatchEvent(new Event('input'));
        }; rec.start();
    },

    enableManualEntry: function(code = '') {
        document.getElementById('combinedDataArea').style.display = 'block'; document.getElementById('searchHeroZone').style.display = 'none';
        const codeInp = document.getElementById('gItemCodeVisible');
        codeInp.removeAttribute('readonly'); codeInp.value = code; codeInp.style.backgroundColor = '#ffffff';
        // ✅ FIX 2026-08-03: في الإدخال اليدوي، الكود يدخل كـ item_code فقط (generic_code يبقى فاضي)
        document.getElementById('gGenericCode').value = '';
        document.getElementById('assetCardZone').classList.remove('nupco-active'); document.getElementById('nupcoDataCard').classList.remove('active');
    },

    fetchCommittee: async function(type) {
        const div = document.getElementById('scMembers'); div.innerHTML = '⏳ جاري التعرف...';
        try {
            const r = await fetch(_BASE+'/api/standing_committee.php?type='+type); const d = await r.json();
            if(!d.committee) { div.innerHTML='<span style="color:#ef4444;">[اللجنة غير مفعلة]</span>'; return; }
            document.getElementById('scId').value = d.committee.id;
            div.innerHTML = d.members.map(m=>`<div style="display:inline-block; margin:2px; background:#fff; padding:3px 6px; border-radius:4px; font-size:11px;">${m.name}</div>`).join('');
        } catch(e) { div.innerHTML = 'خطأ اتصال'; }
    },

    validateDeviceFields: function() {
        const reqFields = ['gItemCodeVisible', 'gDescVisible', 'gDescAr', 'inpRecDate', 'inpDocNum', 'inpDocDate', 'gQty', 'gUnit', 'gPrice', 'inpSupplier', 'inpDocType'];
        for(let id of reqFields) {
            let el = document.getElementById(id);
            if(!el || el.value.trim() === '') {
                el.classList.add('error-highlight'); el.scrollIntoView({behavior: 'smooth', block: 'center'});
                setTimeout(() => el.classList.remove('error-highlight'), 2000); return false;
            }
        } return true;
    },

    addToBasket: function() {
        if(!this.validateDeviceFields()) return;
        if(!this.globalAdminSet) {
            document.getElementById('hSupplier').value = document.getElementById('inpSupplier').value; document.getElementById('hDocType').value = document.getElementById('inpDocType').value;
            document.getElementById('globalRecDate').value = document.getElementById('inpRecDate').value; document.getElementById('globalDocNum').value = document.getElementById('inpDocNum').value;
            document.getElementById('globalDocDate').value = document.getElementById('inpDocDate').value; document.getElementById('globalPages').value = document.getElementById('inpPages').value;
            this.globalAdminSet = true;
        }

        this.basket.push({
            id: Date.now(), code: document.getElementById('gItemCodeVisible').value.trim(), gen_code: document.getElementById('gGenericCode').value,
            cat: document.getElementById('gCategory').value, sub_cat: document.getElementById('gSubCategory').value,
            descEn: document.getElementById('gDescVisible').value.trim(), descAr: document.getElementById('gDescAr').value.trim(), 
            qty: parseInt(document.getElementById('gQty').value) || 1, unit: document.getElementById('gUnit').value.trim(),
            price: parseFloat(document.getElementById('gPrice').value) || 0, hasVat: document.getElementById('gVatCheck').checked,
            mfr: document.getElementById('gMfr').value, model: document.getElementById('gModel').value, warranty: document.getElementById('gBasicWarranty').value,
            manOp: document.getElementById('gManualsOp').value, manMaint: document.getElementById('gManualsMaint').value, cds: document.getElementById('gCDs').value,
            accessories: [], warranties: []
        });
        
        this.renderBasket(); this.cancelCurrentDevice(); document.getElementById('wFoot1').style.display = 'flex';
    },

    renderBasket: function() {
        const zone = document.getElementById('basketZone'); const items = document.getElementById('basketItems');
        if(this.basket.length === 0) { zone.style.display = 'none'; document.getElementById('wFoot1').style.display = 'none'; return; }
        zone.style.display = 'block';
        items.innerHTML = this.basket.map((dev, i) => `<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:8px; padding:10px 15px; display:flex; align-items:center; gap:15px; flex: 1 1 300px;"><i class="fa-solid fa-check-circle" style="color:#10b981;"></i><div style="flex:1;"><div style="font-weight:900; font-size:13px; color:#1e3a8a;">${dev.descEn}</div><div style="font-size:11px; color:#64748b;">العدد: ${dev.qty} | ${dev.price} SAR</div></div><button type="button" onclick="System.editBasketItem(${i})" style="background:none; border:none; color:#f59e0b; cursor:pointer;" title="تعديل"><i class="fa-solid fa-pen"></i></button><button type="button" onclick="System.removeFromBasket(${i})" style="background:none; border:none; color:#ef4444; cursor:pointer;" title="حذف"><i class="fa-solid fa-trash"></i></button></div>`).join('');
    },

    removeFromBasket: function(i) { if(!confirm('حذف الأصل من السلة؟')) return; this.basket.splice(i, 1); if(this.basket.length===0) this.globalAdminSet=false; this.renderBasket(); },

    editBasketItem: function(i) {
        const dev = this.basket[i]; this.enableManualEntry(dev.code);
        // ✅ FIX 2026-08-03: عند تعديل عنصر، استعد الـ generic_code الصحيح
        if (dev.gen_code) {
            document.getElementById('gGenericCode').value = dev.gen_code;
        }
        document.getElementById('gDescVisible').value = dev.descEn; document.getElementById('gDescAr').value = dev.descAr;
        document.getElementById('gQty').value = dev.qty; document.getElementById('gUnit').value = dev.unit;
        document.getElementById('gPrice').value = dev.price; document.getElementById('gVatCheck').checked = dev.hasVat;
        document.getElementById('gMfr').value = dev.mfr; document.getElementById('gModel').value = dev.model; document.getElementById('gBasicWarranty').value = dev.warranty;
        document.getElementById('gManualsOp').value = dev.manOp; document.getElementById('gManualsMaint').value = dev.manMaint; document.getElementById('gCDs').value = dev.cds;
        if(document.getElementById('hMaintType').value === 'medical' && dev.cat) {
            document.getElementById('lblGenCode').innerText = dev.code + (dev.gen_code ? ` (GMDN: ${dev.gen_code})` : '');
            document.getElementById('lblCat').innerText = dev.cat; document.getElementById('lblSubCat').innerText = dev.sub_cat;
            document.getElementById('nupcoDataCard').classList.add('active');
        }
        this.basket.splice(i, 1); if(this.basket.length===0) this.globalAdminSet=false; this.renderBasket(); window.scrollTo({top: document.getElementById('combinedDataArea').offsetTop, behavior: 'smooth'});
    },

    cancelCurrentDevice: function() {
        document.getElementById('combinedDataArea').style.display = 'none';
        document.getElementById('hMaintType').value === 'medical' ? document.getElementById('searchHeroZone').style.display = 'block' : document.getElementById('smartGateZone').style.display = 'block';
        ['gItemCodeVisible','gSearchInput','gDescVisible','gDescAr','gPrice','gMfr','gModel'].forEach(id => { if(document.getElementById(id)) document.getElementById(id).value = ''; });
        document.getElementById('gQty').value = 1; document.getElementById('gBasicWarranty').value = 1;
        document.getElementById('gManualsOp').value = 1; document.getElementById('gManualsMaint').value = 1; document.getElementById('gCDs').value = 1;
        document.getElementById('assetCardZone').classList.remove('nupco-active'); document.getElementById('nupcoDataCard').classList.remove('active');
        document.querySelectorAll('.error-highlight').forEach(el => el.classList.remove('error-highlight'));
    },

    filterModels: function() { const mfr=document.getElementById('gMfr').value; const dl=document.getElementById('modelList_gen'); dl.innerHTML=''; if(_MFR[mfr]) _MFR[mfr].forEach(mod=>dl.innerHTML+=`<option value="${escapeHTML(mod)}"></option>`); },

    renderStep2: function() {
        document.getElementById('step2Container').innerHTML = this.basket.map((dev, i) => `
            <div style="border:1px solid #cbd5e1; border-radius:8px; margin-bottom:15px; background:#fff;">
                <div style="background:#f1f5f9; padding:12px; font-weight:900; color:#1e293b; display:flex; justify-content:space-between; align-items:center;">
                    <span><i class="fa-solid fa-microchip" style="color:#3b82f6;"></i> ${dev.descEn} <span style="background:#e2e8f0; color:#475569; padding:2px 8px; border-radius:12px; font-size:11px; margin-right:8px;" class="eng-num">${dev.code}</span>${dev.gen_code ? ` <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:12px; font-size:11px; margin-right:4px;" class="eng-num">GMDN: ${dev.gen_code}</span>` : ''}</span>
                    <div style="display:flex; gap:10px;">
                        <button type="button" onclick="System.addAcc(${i}, 'acc')" style="background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:4px 10px; font-size:11px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-plus" style="color:#3b82f6"></i> ملحق</button>
                        <button type="button" onclick="System.addAcc(${i}, 'warr')" style="background:#fff; border:1px solid #cbd5e1; border-radius:4px; padding:4px 10px; font-size:11px; font-weight:bold; cursor:pointer;"><i class="fa-solid fa-shield" style="color:#f59e0b"></i> ضمان إضافي</button>
                    </div>
                </div>
                <div id="accList_${i}" style="padding:15px;">${this.buildAccHtml(i)}</div>
            </div>`).join('');
    },

    addAcc: function(dIdx, type) {
        if(type === 'acc') { this.basket[dIdx].accessories.push({ code:'', gen_code:'', desc:'', unit:'قطعة', qty:1, price:0, hasVat:false }); }
        else {
            let count = this.basket[dIdx].warranties.length; let nextYear = count + 2;
            let ord = 'th'; if(nextYear===2) ord='nd'; else if(nextYear===3) ord='rd'; else if(nextYear===1) ord='st';
            this.basket[dIdx].warranties.push({ code:'', gen_code:'', desc:`تمديد ضمان - ${nextYear}${ord} Year`, unit:'عقد ضمان', qty:1, price:0, hasVat:false });
        }
        document.getElementById(`accList_${dIdx}`).innerHTML = this.buildAccHtml(dIdx);
    },

    buildAccHtml: function(d) {
        const dev = this.basket[d]; let html = '';
        dev.accessories.forEach((acc, ai) => {
            html += `<div style="display:flex; gap:8px; margin-bottom:10px; align-items:center;">
                <div style="position:relative; width:120px;">
                    <input type="text" id="accCode_${d}_${ai}" class="rfi eng-num" placeholder="رقم الصنف..." value="${acc.code}" oninput="System.searchNupcoAcc(${d}, ${ai}, this.value)" style="width:100%;">
                    <div id="accDrop_${d}_${ai}" style="position:absolute; top:100%; right:0; left:0; background:#fff; border:1px solid #cbd5e1; border-radius:8px; display:none; max-height:200px; overflow-y:auto; z-index:100; box-shadow:0 10px 25px rgba(0,0,0,0.1);"></div>
                </div>
                <input type="text" id="accDesc_${d}_${ai}" class="rfi" placeholder="البيان..." value="${acc.desc}" oninput="System.updAcc(${d}, 'accessories', ${ai}, 'desc', this.value)" style="flex:1;">
                <input type="number" class="rfi eng-num" style="width:70px;" value="${acc.qty}" oninput="System.updAcc(${d}, 'accessories', ${ai}, 'qty', this.value)">
                <input type="number" class="rfi eng-num" style="width:90px;" value="${acc.price}" placeholder="السعر" oninput="System.updAcc(${d}, 'accessories', ${ai}, 'price', this.value)">
                <label style="font-size:11px; font-weight:bold; color:#1e40af;"><input type="checkbox" ${acc.hasVat?'checked':''} onchange="System.updAcc(${d}, 'accessories', ${ai}, 'hasVat', this.checked)"> ضريبة</label>
                <button type="button" onclick="System.rmAcc(${d}, 'accessories', ${ai})" style="background:#fee2e2; color:#ef4444; border:none; width:30px; height:30px; border-radius:6px; cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
            </div>`;
        });
        dev.warranties.forEach((warr, wi) => {
            html += `<div style="display:flex; gap:8px; margin-bottom:10px; align-items:center; background:#fffbeb; padding:8px; border-radius:6px;">
                <i class="fa-solid fa-shield-halved" style="color:#f59e0b; font-size:16px;"></i>
                <input type="text" class="rfi eng-num" placeholder="رقم الصنف..." value="${warr.code}" oninput="System.updAcc(${d}, 'warranties', ${wi}, 'code', this.value)" style="width:120px; border-color:#fde68a;">
                <input type="text" class="rfi" value="${warr.desc}" oninput="System.updAcc(${d}, 'warranties', ${wi}, 'desc', this.value)" style="flex:1; border-color:#fde68a;">
                <input type="number" class="rfi eng-num" style="width:90px;" value="${warr.price}" placeholder="التكلفة" oninput="System.updAcc(${d}, 'warranties', ${wi}, 'price', this.value)">
                <label style="font-size:11px; font-weight:bold; color:#b45309;"><input type="checkbox" ${warr.hasVat?'checked':''} onchange="System.updAcc(${d}, 'warranties', ${wi}, 'hasVat', this.checked)"> ضريبة</label>
                <button type="button" onclick="System.rmAcc(${d}, 'warranties', ${wi})" style="background:#fee2e2; color:#ef4444; border:none; width:30px; height:30px; border-radius:6px; cursor:pointer;"><i class="fa-solid fa-trash"></i></button>
            </div>`;
        });
        return html || '<div style="text-align:center; font-size:12px; color:#94a3b8;">لا توجد إضافات لهذا الأصل.</div>';
    },

    searchNupcoAcc: function(dIdx, aIdx, query) {
        this.updAcc(dIdx, 'accessories', aIdx, 'code', query);
        const drop = document.getElementById(`accDrop_${dIdx}_${aIdx}`);
        if(document.getElementById('hMaintType').value !== 'medical' || query.length < 2) { drop.style.display='none'; return; }
        
        clearTimeout(this.accTimers[`${dIdx}_${aIdx}`]);
        this.accTimers[`${dIdx}_${aIdx}`] = setTimeout(async () => {
            drop.innerHTML = '<div style="padding:10px;text-align:center;color:#10b981;"><i class="fa-solid fa-spinner fa-spin"></i></div>'; drop.style.display='block';
            try {
                const r = await fetch(_BASE + '/api/nupco_catalog.php?q=' + encodeURIComponent(query));
                const d = await r.json(); drop.innerHTML = '';
                if(d.results && d.results.length > 0) {
                    d.results.forEach(item => {
                        // ✅ FIX 2026-08-03: افصل item_no عن generic_code
                        const itemNo = item.item_no || '';
                        const genCode = item.generic_code || '';
                        const mCode = itemNo || genCode;  // M-prefixed in item_code
                        const div = document.createElement('div');
                        div.style = 'padding:8px 12px; cursor:pointer; border-bottom:1px solid #f1f5f9; text-align:left; direction:ltr;';
                        div.innerHTML = `<div style="font-weight:bold; color:#1e3a8a; font-family:'Inter';">${escapeHTML(mCode)}${genCode ? ` <span style="color:#92400e; font-size:10px;">(${escapeHTML(genCode)})</span>` : ''}</div><div style="font-size:11px; color:#334155;">${escapeHTML(item.description_en)}</div>`;
                        div.onclick = () => {
                            document.getElementById(`accCode_${dIdx}_${aIdx}`).value = mCode;
                            document.getElementById(`accDesc_${dIdx}_${aIdx}`).value = item.description_en;
                            System.updAcc(dIdx, 'accessories', aIdx, 'code', mCode);
                            System.updAcc(dIdx, 'accessories', aIdx, 'gen_code', genCode);
                            System.updAcc(dIdx, 'accessories', aIdx, 'desc', item.description_en);
                            drop.style.display = 'none';
                        };
                        div.onmouseover = () => div.style.background = '#f0fdf4'; div.onmouseout = () => div.style.background = '#fff';
                        drop.appendChild(div);
                    });
                } else { drop.innerHTML = '<div style="padding:10px;text-align:center;color:#ef4444;font-size:11px;">لا توجد نتائج</div>'; }
            } catch(e) { drop.style.display='none'; }
        }, 300);
    },

    updAcc: function(d, t, i, f, v) { this.basket[d][t][i][f] = v; },
    rmAcc: function(d, t, i) { this.basket[d][t].splice(i, 1); document.getElementById(`accList_${d}`).innerHTML = this.buildAccHtml(d); },

    generateMatrix: function() {
        const table = document.getElementById('smartTable'); table.querySelectorAll('tbody.bundle-group').forEach(e => e.remove());
        let di = 0, html = '';
        this.basket.forEach((dev, groupIdx) => {
            const basicWarrYears = parseFloat(dev.warranty) || 0;
            const warrItems = [...dev.warranties];
            if (basicWarrYears > 0) {
                warrItems.unshift({ code: 'WARRANTY', desc: 'الضمان الأساسي', unit: 'سنة ضمان', qty: basicWarrYears, price: 0, hasVat: false });
            }
            const accCount = dev.accessories.length;
            const accs = [...dev.accessories, ...warrItems];
            let deptOpts = '<option value="">— اختر القسم —</option>';
            if(_RDEPTS[0]) _RDEPTS[0].forEach(d => { deptOpts += `<option value="${d.id}">${d.name}</option>`; });
            // توزيع عادل لكل كتالوج على الوحدات الفردية: القسمة الصحيحة لكل وحدة + الباقي يوزَّع
            // على الوحدات الأولى بمقدار وحدة واحدة إضافية، حتى لا يتكرر الإجمالي كاملاً على كل وحدة
            const distribute = (total, qty, idx) => {
                const t = parseInt(total) || 0; const base = Math.floor(t / qty); const rem = t % qty;
                return base + (idx < rem ? 1 : 0);
            };
            for(let i=0; i<dev.qty; i++) {
                const unitManOp = distribute(dev.manOp, dev.qty, i);
                const unitManMaint = distribute(dev.manMaint, dev.qty, i);
                const unitCds = distribute(dev.cds, dev.qty, i);
                html += `<tbody class="bundle-group" id="bundle_${di}"><tr style="background:#f0f9ff; border-top:2px solid #7dd3fc;">
                    <td style="text-align:center;font-weight:900;color:#1e40af;" class="eng-num">${i+1}</td>
                    <td style="padding:4px"><input type="text" name="devices[${di}][item_code]" class="t-inp eng-num" value="${escapeHTML(dev.code)}" readonly style="background:#f1f5f9; border:none; text-align:center;">
                    ${dev.gen_code ? `<div style="font-size:9px; color:#92400e; background:#fef3c7; padding:1px 4px; border-radius:3px; margin-top:2px; text-align:center;" class="eng-num">GMDN: ${escapeHTML(dev.gen_code)}</div>` : ''}
                    <input type="hidden" name="devices[${di}][generic_code]" value="${escapeHTML(dev.gen_code || '')}">
                    <input type="hidden" name="devices[${di}][description]" value="${escapeHTML(dev.descEn)}"><input type="hidden" name="devices[${di}][description_ar]" value="${escapeHTML(dev.descAr)}">
                    <input type="hidden" name="devices[${di}][category]" value="${escapeHTML(dev.cat)}"><input type="hidden" name="devices[${di}][sub_category]" value="${escapeHTML(dev.sub_cat)}">
                    <input type="hidden" name="devices[${di}][manufacturer_name]" value="${escapeHTML(dev.mfr)}"><input type="hidden" name="devices[${di}][model_number]" value="${escapeHTML(dev.model)}">
                    <input type="hidden" name="devices[${di}][quantity]" value="1">
                    <input type="hidden" name="devices[${di}][group_tag]" value="${groupIdx}">
                    <input type="hidden" name="devices[${di}][manuals_operation]" value="${unitManOp}">
                    <input type="hidden" name="devices[${di}][manuals_maintenance]" value="${unitManMaint}">
                    <input type="hidden" name="devices[${di}][cd_count]" value="${unitCds}"></td>
                    <td><div style="font-weight:800;">${escapeHTML(dev.descEn)}</div><div style="font-size:11px;color:#64748b;">${escapeHTML(dev.descAr)}</div></td>
                    <td><div style="font-size:12px; font-weight:bold; color:#475569;">${escapeHTML(dev.mfr)}</div></td>
                    <td style="text-align:center;font-weight:bold;">1</td>
                    <td><input type="number" name="devices[${di}][unit_price]" class="t-inp eng-num p-inp" value="${dev.price}" oninput="System.calcTable()"><input type="hidden" name="devices[${di}][vat_amount]" class="v-hid" value="0"><label style="font-size:10px;"><input type="checkbox" class="v-chk" ${dev.hasVat?'checked':''} onchange="System.calcTable()"> ضريبة</label></td>
                    <td style="text-align:center;color:#ef4444;font-size:12px;font-weight:bold;" class="r-vat">0</td><td style="text-align:center;color:#10b981;font-size:13px;font-weight:900;" class="r-tot">0</td>
                    <td style="padding:4px;"><input type="hidden" name="devices[${di}][department_id]" class="b-dept-id"><input type="hidden" name="devices[${di}][sub_department_id]" class="b-subdept-id"><input type="hidden" name="devices[${di}][receiver_user_id]" class="b-recvuid"><input type="hidden" name="devices[${di}][receiver_name]" class="b-recvname"><input type="hidden" name="devices[${di}][receiver_title]" class="b-recvtitle"><select class="t-sel dL1" onchange="System.route(${di})">${deptOpts}</select><div id="devRecv_${di}" style="font-size:10px; color:#ef4444; margin-top:2px; font-weight:bold; text-align:center; line-height:1.3;">معلق</div></td>
                </tr>`;
                accs.forEach((acc, ai) => {
                    const accRole = ai < accCount ? 'accessory' : 'warranty';
                    html += `<tr><td style="text-align:center;color:#64748b;font-size:11px">-${ai+1}</td>
                    <td style="padding:4px"><input type="hidden" name="devices[${di}][accessories][${ai}][role]" value="${accRole}"><input type="text" name="devices[${di}][accessories][${ai}][item_code]" class="t-inp eng-num" value="${escapeHTML(acc.code)}" readonly style="border:none; text-align:center;">
                    <input type="hidden" name="devices[${di}][accessories][${ai}][generic_code]" value="${escapeHTML(acc.gen_code || '')}">
                    </td>
                    <td style="padding-left:15px;">↳ <input type="text" name="devices[${di}][accessories][${ai}][description]" class="t-inp" value="${escapeHTML(acc.desc)}"></td><td></td>
                    <td><input type="number" name="devices[${di}][accessories][${ai}][quantity]" class="t-inp eng-num aq-inp" value="${acc.qty}" oninput="System.calcTable()"></td>
                    <td><input type="number" name="devices[${di}][accessories][${ai}][unit_price]" class="t-inp eng-num ap-inp" value="${acc.price}" oninput="System.calcTable()"><input type="hidden" name="devices[${di}][accessories][${ai}][vat_amount]" class="v-hid" value="0"><label style="font-size:10px;"><input type="checkbox" class="v-chk" ${acc.hasVat?'checked':''} onchange="System.calcTable()"> ضريبة</label></td>
                    <td style="text-align:center;color:#ef4444;font-size:11px;" class="r-vat">0</td><td style="text-align:center;color:#475569;font-size:12px;font-weight:bold;" class="r-tot">0</td><td></td></tr>`;
                });
                html += `</tbody>`; di++;
            }
        });
        table.innerHTML += html; this.calcTable(); this.updateDistBar(); this.goTo(3);
    },

    calcTable: function() {
        let sT=0, vT=0;
        document.querySelectorAll('.bundle-group').forEach(b => {
            const prc = parseFloat(b.querySelector('tr:first-child .p-inp').value)||0;
            const vt = b.querySelector('tr:first-child .v-chk').checked ? (prc*0.15):0;
            b.querySelector('tr:first-child .v-hid').value = vt.toFixed(2); b.querySelector('tr:first-child .r-vat').innerText = vt.toFixed(2); b.querySelector('tr:first-child .r-tot').innerText = (prc+vt).toFixed(2);
            sT+=prc; vT+=vt;
            b.querySelectorAll('tr:not(:first-child)').forEach(r => {
                const aq = parseFloat(r.querySelector('.aq-inp').value)||0, ap = parseFloat(r.querySelector('.ap-inp').value)||0;
                const avt = r.querySelector('.v-chk').checked ? (aq*ap*0.15):0;
                r.querySelector('.v-hid').value = avt.toFixed(2); r.querySelector('.r-vat').innerText = avt.toFixed(2); r.querySelector('.r-tot').innerText = ((aq*ap)+avt).toFixed(2);
                sT+=(aq*ap); vT+=avt;
            });
        });
        document.getElementById('rev_subtotal').innerText = sT.toFixed(2); document.getElementById('rev_vat').innerText = vT.toFixed(2); document.getElementById('rev_grandtotal').innerText = (sT+vT).toFixed(2);
    },

    fillDistL2: function() {
        const p = document.getElementById('distL1').value;
        const sel = document.getElementById('distL2');
        const l3 = document.getElementById('distL3');
        sel.innerHTML = '<option value="">— فرع —</option>';
        if(p && _RDEPTS[p]) _RDEPTS[p].forEach(d => sel.innerHTML += `<option value="${d.id}">${d.name}</option>`);
        sel.style.display = (p && _RDEPTS[p] && _RDEPTS[p].length) ? '' : 'none';
        // إعادة تهيئة L3
        l3.innerHTML = '<option value="">— الموظف —</option>';
        l3.style.display = 'none';
        this.fetchDistManager();
    },
    fillDistL3: async function() {
        const l2 = document.getElementById('distL2');
        const l3 = document.getElementById('distL3');
        const subId = l2.value;
        l3.innerHTML = '<option value="">— الموظف —</option>';
        if(!subId){ l3.style.display='none'; this.fetchDistManager(); return; }
        l3.style.display='';
        l3.innerHTML = '<option value="">⏳ تحميل...</option>';
        try {
            const r = await fetch(_BASE+'/api/department_users.php?dept_id='+subId);
            const j = await r.json();
            if(!j.ok){ l3.innerHTML = '<option value="">⚠️ خطأ</option>'; return; }
            let html = '<option value="">— الموظف —</option>';
            if(j.manager){
                const m = j.manager;
                html += `<option value="${m.id}" data-name="${escapeHTML(m.full_name)}" data-title="${escapeHTML(m.job_title||'')}" data-dept="${subId}" data-deptname="${escapeHTML(j.department.name)}" selected>👔 ${escapeHTML(m.full_name)} — ${escapeHTML(m.job_title||'رئيس الفرع')}</option>`;
            }
            (j.users||[]).forEach(u=>{
                if(j.manager && u.id===j.manager.id) return;
                const marker = u.is_head ? '⭐ ' : '👤 ';
                html += `<option value="${u.id}" data-name="${escapeHTML(u.full_name)}" data-title="${escapeHTML(u.job_title||'')}" data-dept="${subId}" data-deptname="${escapeHTML(j.department.name)}">${marker}${escapeHTML(u.full_name)} — ${escapeHTML(u.job_title||'')}</option>`;
            });
            html += '<option value="0" data-name="" data-title="" data-dept="0">✍️ إدخال يدوي</option>';
            l3.innerHTML = html;
        } catch(e) {
            l3.innerHTML = '<option value="">⚠️ فشل الاتصال</option>';
        }
        this.fetchDistManager();
    },
    fetchDistManager: async function() {
        const l1 = document.getElementById('distL1');
        const l2 = document.getElementById('distL2');
        const l3 = document.getElementById('distL3');
        const s = document.getElementById('distManagerName');
        const id = l2.value || l1.value;
        if(!id){ s.innerHTML=''; return; }
        // عرض فوري من اختيارات L3 (إن وُجدت)
        if(l2.value && l3.value){
            const opt = l3.options[l3.selectedIndex];
            if(opt && opt.value && opt.value!=='0'){
                s.innerHTML = `<i class="fa-solid fa-user-check"></i> ${escapeHTML(opt.dataset.name)} <span style="color:#64748b;">(${escapeHTML(opt.dataset.title||'')})</span> · <span style="color:#475569;">${escapeHTML(l2.options[l2.selectedIndex].text)} › ${escapeHTML(l1.options[l1.selectedIndex].text)}</span>`;
                s.style.color = '#065f46';
                return;
            }
        }
        // Fallback: L1 أو L2 بدون L3 → رئيس القسم (مع وراثة)
        try {
            const r = await fetch(_BASE+'/api/department_manager.php?dept_id='+id);
            const d = await r.json();
            if(d.manager){
                const lbl = l2.value ? `${escapeHTML(l2.options[l2.selectedIndex].text)} › ${escapeHTML(l1.options[l1.selectedIndex].text)}` : escapeHTML(l1.options[l1.selectedIndex].text);
                s.innerHTML = `<i class="fa-solid fa-user-check"></i> ${escapeHTML(d.manager.full_name)} <span style="color:#64748b;">(${escapeHTML(d.manager.job_title||'')})</span> · <span style="color:#475569;">${lbl}</span>`;
                s.style.color = '#065f46';
            } else {
                s.innerHTML = '<span style="color:#ef4444">القسم بدون مسؤول — اختر موظف من القائمة أعلاه</span>';
                s.style.color = '#ef4444';
            }
        } catch(e) {}
    },
    applyDistribution: function() {
        const l1 = document.getElementById('distL1');
        const l2 = document.getElementById('distL2');
        const l3 = document.getElementById('distL3');
        const id1 = l1.value;
        if(!id1){ alert('حدد المحطة'); return; }
        const id2 = l2.value || '';
        const id3 = l3.value || '';
        // Validate: if L2 set, L3 required
        if(id2 && !id3){
            const l2txt = l2.options[l2.selectedIndex]?.text || '';
            alert('حدد الموظف المستلم من ' + l2txt);
            return;
        }
        // L3 data
        let recvUid=null, recvName=null, recvTitle=null, subId=null;
        if(id2 && id3){
            const opt = l3.options[l3.selectedIndex];
            if(opt && opt.value && opt.value!=='0'){
                recvUid = parseInt(opt.value);
                recvName = opt.dataset.name || null;
                recvTitle = opt.dataset.title || null;
                subId = parseInt(id2);
            }
        }
        const qty = parseInt(document.getElementById('distQty').value)||1;
        let a=0;
        for(const b of document.querySelectorAll('.bundle-group')) {
            if(a>=qty) break;
            if(!b.querySelector('.b-dept-id').value) {
                b.querySelector('.dL1').value = id1;
                b.querySelector('.b-subdept-id').value = subId || '';
                b.querySelector('.b-recvuid').value = recvUid || '';
                b.querySelector('.b-recvname').value = recvName || '';
                b.querySelector('.b-recvtitle').value = recvTitle || '';
                this.route(b.id.replace('bundle_',''));
                a++;
            }
        }
        this.updateDistBar();
    },
    route: async function(di) {
        const b = document.getElementById(`bundle_${di}`);
        const id = b.querySelector('.dL1').value;
        const s = document.getElementById(`devRecv_${di}`);
        b.querySelector('.b-dept-id').value = id;
        if(!id) {
            s.innerHTML='<span style="color:#ef4444">معلق</span>';
            b.querySelector('.b-subdept-id').value = '';
            b.querySelector('.b-recvuid').value = '';
            b.querySelector('.b-recvname').value = '';
            b.querySelector('.b-recvtitle').value = '';
            this.updateDistBar();
            return;
        }
        // اعرض البيانات المحفوظة إن وُجدت (من Smart Bar)
        const savedUid = b.querySelector('.b-recvuid').value;
        const savedName = b.querySelector('.b-recvname').value;
        const savedTitle = b.querySelector('.b-recvtitle').value;
        const savedSubId = b.querySelector('.b-subdept-id').value;
        if(savedName){
            const deptSel = b.querySelector('.dL1');
            const deptTxt = deptSel.options[deptSel.selectedIndex]?.text || '';
            let chain = `<i class="fa-solid fa-link"></i> ${escapeHTML(savedName)} <span style="color:#64748b;">(${escapeHTML(savedTitle||'')})</span>`;
            if(savedSubId){
                // ابحث عن اسم القسم الفرعي
                try {
                    const subTxt = b.querySelector(`option[value="${savedSubId}"]`)?.text || '';
                    chain += `<br><span style="font-size:9px; color:#475569;">${escapeHTML(subTxt)} › ${escapeHTML(deptTxt)}</span>`;
                } catch(e){}
            }
            s.innerHTML = chain;
            s.style.color = '#10b981';
            this.updateDistBar();
            return;
        }
        // No saved data: ارجع للمدير (fallback) + اقترح sub-dept + user
        try {
            const r = await fetch(_BASE+'/api/department_manager.php?dept_id='+id);
            const d = await r.json();
            if(d.manager){
                s.innerHTML = `<i class="fa-solid fa-link"></i> ${escapeHTML(d.manager.full_name)}`;
                s.style.color = '#10b981';
                // حفظ تلقائي للمدير (لو ما اختار user)
                b.querySelector('.b-recvname').value = d.manager.full_name;
                b.querySelector('.b-recvtitle').value = d.manager.job_title || '';
                b.querySelector('.b-recvuid').value = d.manager.id;
            } else {
                s.innerHTML = '⚠️ بدون مسؤول — اختر من Smart Bar';
                s.style.color = '#f59e0b';
            }
        } catch(e) {}
        this.updateDistBar();
    },
    updateDistBar: function() { let u=0; document.querySelectorAll('.b-dept-id').forEach(i => { if(!i.value) u++; }); document.getElementById('unassignedCount').innerText = u; document.getElementById('distBar').style.display = document.querySelectorAll('.bundle-group').length ? 'block' : 'none'; document.getElementById('distQty').max = u; if(!document.getElementById('distL1').options.length && _RDEPTS[0]) { document.getElementById('distL1').innerHTML = '<option value="">— اختر المحطة —</option>'; _RDEPTS[0].forEach(d => document.getElementById('distL1').innerHTML += `<option value="${d.id}">${d.name}</option>`); } },

    formatDateArabic: function(dateStr) {
        if(!dateStr) return '---';
        try {
            const d = new Date(dateStr);
            const greg = d.toLocaleDateString('ar-SA', {weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'});
            const hijri = d.toLocaleDateString('ar-SA-u-ca-islamic', {year: 'numeric', month: 'long', day: 'numeric'});
            return `${greg} <span style="color:#10b981;">(${hijri})</span>`;
        } catch(e) { return dateStr; }
    },

    updateFileChips: function(input) {
        const zone = document.getElementById('fileChips'); zone.innerHTML = '';
        if(input.files && input.files.length > 0) { Array.from(input.files).forEach(f => { zone.innerHTML += `<div class="file-chip"><i class="fa-solid fa-file-pdf" style="color:#ef4444;"></i> ${escapeHTML(f.name)}</div>`; }); }
    },

    prepareReview: function() {
        let ok = true; document.querySelectorAll('.b-dept-id').forEach(s => { if(!s.value) { s.closest('td').classList.add('error-highlight'); ok = false; }});
        if(!ok) { alert('توجد أجهزة لم يتم توجيهها للأقسام!'); return; }
        
        document.getElementById('rev_supplier').innerText = document.getElementById('inpSupplier').value || '---';
        document.getElementById('rev_doctype').innerText = document.getElementById('inpDocType').value || '---';
        document.getElementById('rev_docnum').innerText = document.getElementById('inpDocNum').value || '---';
        document.getElementById('rev_docdate').innerHTML = this.formatDateArabic(document.getElementById('inpDocDate').value);
        document.getElementById('rev_recdate').innerHTML = this.formatDateArabic(document.getElementById('inpRecDate').value);
        
        const m = document.getElementById('hMaintType').value;
        document.getElementById('rev_committee').innerText = m === 'medical' ? 'الصيانة الطبية' : (m === 'general' ? 'الصيانة العامة' : 'تقنية المعلومات');
        
        let detHtml = '';
        this.basket.forEach(dev => {
            let acHtml = ''; const allAccs = [...dev.accessories, ...dev.warranties];
            if(allAccs.length > 0) {
                acHtml = `<div style="margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1;">`;
                allAccs.forEach(ac => { acHtml += `<div style="font-size:11px; color:#475569; padding:2px 0;">↳ [${escapeHTML(ac.code || '---')}] ${escapeHTML(ac.desc)} <span style="float:left; color:#1e40af; font-weight:bold;">العدد: ${ac.qty}</span></div>`; });
                acHtml += `</div>`;
            }
            detHtml += `<div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:10px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div><div style="font-weight:900; color:#1e3a8a; font-size:13px;">${escapeHTML(dev.descEn)}</div><div style="font-size:11px; font-family:'Inter'; font-weight:bold;"><span style="color:#1e40af;">${escapeHTML(dev.code || '---')}</span>${dev.gen_code ? ` <span style="color:#92400e; background:#fef3c7; padding:1px 4px; border-radius:3px; margin-right:4px;">GMDN: ${escapeHTML(dev.gen_code)}</span>` : ''}</div></div>
                    <div style="text-align:left;"><div style="font-weight:bold; color:#10b981; font-size:12px;">الكمية: ${dev.qty}</div><div style="font-weight:900; color:#0f172a; font-size:13px;" class="eng-num">${(dev.price * dev.qty).toFixed(2)} SAR</div></div>
                </div>${acHtml}</div>`;
        });
        document.getElementById('rev_details_list').innerHTML = detHtml; this.goTo(4);
    },

    doSave: function(action) { 
        if(action === 'submit') { document.getElementById('submitConfirmModal').style.display='flex'; } 
        else { document.getElementById('fAction').value = 'draft'; document.getElementById('rfForm').submit(); }
    },
    
    executeSubmit: function() {
        document.getElementById('submitConfirmModal').style.display='none';
        document.getElementById('fAction').value = 'submit'; 
        document.getElementById('rfForm').submit(); 
    }
};

document.getElementById('gQty').addEventListener('input', function() { 
    let q=parseInt(this.value); 
    if(!isNaN(q) && q > 0) { document.getElementById('gManualsOp').value=q; document.getElementById('gManualsMaint').value=q; document.getElementById('gCDs').value=q; }
    else { document.getElementById('gManualsOp').value=''; document.getElementById('gManualsMaint').value=''; document.getElementById('gCDs').value=''; }
});
let nupcoTimer;
document.getElementById('gSearchInput').addEventListener('input', function() {
    clearTimeout(nupcoTimer);
    nupcoTimer = setTimeout(async () => {
        const q = this.value.trim(); const drop = document.getElementById('nupcoDrop');
        if(q.length < 2) { drop.style.display='none'; return; }
        drop.innerHTML = '<div style="padding:15px; text-align:center; color:#10b981;"><i class="fa-solid fa-spinner fa-spin"></i> جاري البحث في الدليل...</div>'; drop.style.display='block';
        try {
            const r = await fetch(_BASE + '/api/nupco_catalog.php?q=' + encodeURIComponent(q)); const d = await r.json(); drop.innerHTML = '';
            if(d.results && d.results.length) {
                d.results.forEach(item => {
                    const div = document.createElement('div'); div.style = 'padding:12px 16px; cursor:pointer; border-bottom:1px solid #f1f5f9; text-align:right;';
                    // ✅ FIX 2026-08-03: افصل item_no (M) عن generic_code (4)
                    const itemNo = item.item_no || '';
                    const genCode = item.generic_code || '';
                    const displayMain = itemNo || genCode;
                    const displayGen = genCode ? `<span style="background:#fef3c7; color:#92400e; padding:1px 6px; border-radius:4px; font-size:10px; margin-right:4px;">GMDN: ${escapeHTML(genCode)}</span>` : '';
                    div.innerHTML = `<div style="font-weight:bold; color:#1e3a8a; font-family:'Inter';">${escapeHTML(displayMain)} ${displayGen}</div><div style="font-size:12px; color:#334155;">${escapeHTML(item.description_en)}</div>`;
                    div.onclick = () => {
                        // ✅ FIX: item_code = item_no (M-prefixed), generic_code = generic_code (4-prefixed)
                        const mainCode = itemNo;  // M-prefixed for item_code
                        document.getElementById('gItemCodeVisible').value = mainCode;
                        document.getElementById('gItemCodeVisible').setAttribute('readonly', true);
                        document.getElementById('gItemCodeVisible').style.backgroundColor = '#f1f5f9';
                        document.getElementById('gGenericCode').value = genCode;
                        document.getElementById('gCategory').value = item.category || ''; document.getElementById('gSubCategory').value = item.sub_category || '';
                        document.getElementById('gDescVisible').value = item.description_en; document.getElementById('gDescAr').value = '';

                        document.getElementById('lblGenCode').innerText = mainCode + (genCode ? ` (GMDN: ${genCode})` : '');
                        document.getElementById('lblCat').innerText = item.category || 'غير محدد';
                        document.getElementById('lblSubCat').innerText = item.sub_category || 'غير محدد';
                        
                        document.getElementById('combinedDataArea').style.display = 'block'; document.getElementById('searchHeroZone').style.display = 'none';
                        document.getElementById('assetCardZone').classList.add('nupco-active');
                        document.getElementById('nupcoDataCard').classList.add('active');
                        
                        if(!System.globalAdminSet) {
                            const supp = document.getElementById('inpSupplier'); if(supp){ supp.value='الشركة الوطنية للشراء الموحد (NUPCO)'; supp.style.borderColor='#10b981'; }
                            const doc = document.getElementById('inpDocType'); if(doc){ let f=false; Array.from(doc.options).forEach(o=>{if(o.value.includes('نوبكو')){doc.value=o.value; f=true;}}); if(!f){doc.add(new Option('تعميد نوبكو (NUPCO)','تعميد نوبكو (NUPCO)')); doc.value='تعميد نوبكو (NUPCO)';} doc.style.borderColor='#10b981'; }
                        }
                        drop.style.display='none';
                        document.getElementById('gSearchInput').value = ''; // تفريغ حقل البحث بعد الاختيار
                    };
                    div.onmouseover = () => div.style.background = '#f0fdf4'; div.onmouseout = () => div.style.background = '#fff';
                    drop.appendChild(div);
                });
            } else { 
                drop.innerHTML = `<div style="padding:15px; text-align:center;"><div style="color:#ef4444; font-weight:bold; margin-bottom:10px;">الصنف غير مدرج في الدليل</div><button type="button" onclick="System.enableManualEntry('${escapeHTML(q)}')" style="background:#1e3a8a; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-family:'Tajawal'; font-weight:bold;">إدخال هذا الصنف يدوياً</button></div>`; 
            }
        } catch(e) { drop.style.display='none'; }
    }, 400);
});
</script>
</body>
</html>