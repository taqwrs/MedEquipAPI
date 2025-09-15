<?php
include "../config/jwt.php";

$uploadDirectory = 'C:/xampp/htdocs/back_equip/uploads/';
$baseUrl = 'http://localhost/back_equip/uploads/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    // --- user_id จาก JWT หรือ fallback เป็น 1 ---
    $userId = $decoded->user_id ?? 1;

    $requiredFields = ['name','asset_code'];
    $allFields = [
        'name','brand','asset_code','model','serial_number','spec',
        'import_type_id','subcategory_id','location_department_id','location_details',
        'manufacturer_company_id','supplier_company_id','maintainer_company_id',
        'production_year','price','contract','start_date','end_date','warranty_duration_days',
        'warranty_condition','user_id','updated_by','record_status','status','active','first_register'
    ];

    $relations = [
        'child_equipments'=>['table'=>'equipments','fk'=>'main_equipment_id','idField'=>'equipment_id'],
        'spare_parts'=>['table'=>'spare_parts','fk'=>'equipment_id','idField'=>'spare_part_id']
    ];

    $fileTypes = [
        'contractFiles'=>'เอกสารสัญญา',
        'warrantyFiles'=>'เอกสาร Warranty',
        'manualFiles'=>'คู่มือ',
        'deviceImages'=>'รูปภาพเครื่อง'
    ];

    // --- ตรวจ required ---
    foreach($requiredFields as $f){
        if(empty($_POST[$f])) throw new Exception("Missing field: $f");
    }

    // --- คำนวณ warranty ---
    if(!empty($_POST['start_date']) && !empty($_POST['end_date'])){
        $start=new DateTime($_POST['start_date']);
        $end=new DateTime($_POST['end_date']);
        $_POST['warranty_duration_days']=$start->diff($end)->days;
    } else $_POST['warranty_duration_days']=null;

    // --- เตรียม insert ---
    $cols=[]; $placeholders=[]; $values=[];
    foreach($allFields as $f){
        if($f==='first_register'){ $cols[]=$f; $placeholders[]='NOW()'; continue;}
        $cols[]=$f; $placeholders[]=":$f";
        if($f==='active' && !isset($_POST['active'])) $values[":$f"]=1;
        elseif($f==='status' && !isset($_POST['status'])) $values[":$f"]='ใช้งาน';
        elseif($f==='record_status' && !isset($_POST['record_status'])) $values[":$f"]='complete';
        elseif($f==='user_id'||$f==='updated_by') $values[":$f"]=$_POST[$f] ?? $userId;
        else $values[":$f"]=$_POST[$f] ?? null;
    }
    $cols[]='updated_at'; $placeholders[]='NOW()';

    // --- Execute insert ---
    $sql="INSERT INTO equipments(".implode(',',$cols).") VALUES(".implode(',',$placeholders).")";
    $stmt=$dbh->prepare($sql);
    foreach($values as $k=>$v) $stmt->bindValue($k,$v);
    $stmt->execute();
    $equipId = $dbh->lastInsertId();

    // --- Relations ---
    foreach($relations as $relKey=>$relConfig){
        if(!empty($_POST[$relKey])){
            $arr = $_POST[$relKey];
            if(is_string($arr)) $arr=json_decode($arr,true);
            if(is_array($arr) && isset($arr[0]) && !is_array($arr[0])){
                $arr = array_map(fn($id)=>[$relConfig['idField']=>$id], $arr);
            }
            foreach($arr as $item){
                if(!empty($item[$relConfig['idField']])){
                    $stmt=$dbh->prepare("UPDATE {$relConfig['table']} 
                                         SET {$relConfig['fk']}=:main, updated_by=:updated_by, updated_at=NOW() 
                                         WHERE {$relConfig['idField']}=:id");
                    $stmt->execute([':main'=>$equipId,':id'=>$item[$relConfig['idField']],':updated_by'=>$userId]);
                }
            }
        }
    }

    // --- Files: upload และ URL ---
    foreach($fileTypes as $payloadKey=>$typeName){
        if(isset($_FILES[$payloadKey])){
            foreach($_FILES[$payloadKey]['name'] as $i=>$name){
                if($_FILES[$payloadKey]['error'][$i]===UPLOAD_ERR_OK){
                    $newName=uniqid().'-'.basename($name);
                    $dest=$uploadDirectory.$newName;
                    if(move_uploaded_file($_FILES[$payloadKey]['tmp_name'][$i],$dest)){
                        $stmt=$dbh->prepare("INSERT INTO file_equip 
                            (equipment_id,file_equip_name,equip_url,equip_type_name,upload_at) 
                            VALUES (:id,:name,:url,:type,NOW())");
                        $stmt->execute([':id'=>$equipId,':name'=>$name,':url'=>$baseUrl.$newName,':type'=>$typeName]);
                    }
                }
            }
        }

        $urlField = $payloadKey.'Urls';
        if(!empty($_POST[$urlField])){
            $urls = $_POST[$urlField];
            if(is_string($urls)) $urls=json_decode($urls,true);
            if(is_array($urls)){
                foreach($urls as $url){
                    $stmt=$dbh->prepare("INSERT INTO file_equip 
                        (equipment_id,file_equip_name,equip_url,equip_type_name,upload_at) 
                        VALUES (:id,:name,:url,:type,NOW())");
                    $stmt->execute([':id'=>$equipId,':name'=>basename($url),':url'=>$url,':type'=>$typeName]);
                }
            }
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Equipment inserted","id"=>$equipId]);
}catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
