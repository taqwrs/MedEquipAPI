<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method !!!"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

if(!isset($input['plan_id'])){
    echo json_encode(["status"=>"error","message"=>"Missing field: plan_id"]);
    exit;
}

$required_fields = ['plan_name','user_id','group_user_id','company_id','frequency_number','frequency_unit','frequency_type','start_waranty','start_date','end_date','cost_type','price','type_cal'];
foreach($required_fields as $field){
    if(!isset($input[$field])){
        echo json_encode(["status"=>"error","message"=>"Missing field: $field"]);
        exit;
    }
}

$allowed_type_cal = ['ภายใน','ภายนอก'];
$allowed_cost_type = ['แยกรายรอบ','รวมตลอดทั้งสัญญา'];
$allowed_frequency_unit = [1,2,3,4];

if(!in_array($input['type_cal'],$allowed_type_cal)){
    echo json_encode(["status"=>"error","message"=>"Invalid type_cal"]);
    exit;
}
if(!in_array($input['cost_type'],$allowed_cost_type)){
    echo json_encode(["status"=>"error","message"=>"Invalid cost_type"]);
    exit;
}
if(!in_array((int)$input['frequency_unit'],$allowed_frequency_unit)){
    echo json_encode(["status"=>"error","message"=>"Invalid frequency_unit"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $startDate = new DateTime($input['start_date']);
    $endDate   = new DateTime($input['end_date']);
    $intervalNumber = (int)$input['frequency_number'];
    $intervalUnit = (int)$input['frequency_unit'];

    $intervalCount = 0;
    $tempDate = clone $startDate;
    while($tempDate <= $endDate){
        $intervalCount++;
        switch($intervalUnit){
            case 1: $tempDate->add(new DateInterval('P'.$intervalNumber.'D')); break;
            case 2: $tempDate->add(new DateInterval('P'.($intervalNumber*7).'D')); break;
            case 3: $tempDate->add(new DateInterval('P'.$intervalNumber.'M')); break;
            case 4: $tempDate->add(new DateInterval('P'.$intervalNumber.'Y')); break;
        }
    }

    $stmt = $dbh->prepare("UPDATE calibration_plans SET
        plan_name=:plan_name, 
        user_id=:user_id,
        group_user_id=:group_user_id,
        company_id=:company_id,
        frequency_number=:frequency_number,
        frequency_unit=:frequency_unit,
        frequency_type=:frequency_type,
        interval_count=:interval_count,
        start_waranty=:start_waranty,
        start_date=:start_date,
        end_date=:end_date,
        cost_type=:cost_type,
        price=:price,
        type_cal=:type_cal,
        is_active=:is_active
        WHERE plan_id=:plan_id
    ");
    $stmt->execute([
        ':plan_id'=>$input['plan_id'],
        ':plan_name'=>$input['plan_name'],
        ':user_id'=>$input['user_id'],
        ':group_user_id'=>$input['group_user_id'],
        ':company_id'=>$input['company_id'],
        ':frequency_number'=>$intervalNumber,
        ':frequency_unit'=>$intervalUnit,
        ':frequency_type'=>$input['frequency_type'],
        ':interval_count'=>$intervalCount,
        ':start_waranty'=>$input['start_waranty'],
        ':start_date'=>$input['start_date'],
        ':end_date'=>$input['end_date'],
        ':cost_type'=>$input['cost_type'],
        ':price'=>$input['price'],
        ':type_cal'=>$input['type_cal'],
        ':is_active'=>$input['is_active'] ?? 1
    ]);

    $delDetails = $dbh->prepare("DELETE FROM details_calibration_plans WHERE plan_id=:plan_id");
    $delDetails->execute([':plan_id'=>$input['plan_id']]);

    $detailsStmt = $dbh->prepare("INSERT INTO details_calibration_plans (plan_id,start_date) VALUES (:plan_id,:start_date)");
    $scheduledDate = clone $startDate;
    for($i=1;$i<=$intervalCount;$i++){
        $detailsStmt->execute([
            ':plan_id'=>$input['plan_id'],
            ':start_date'=>$scheduledDate->format('Y-m-d')
        ]);
        switch($intervalUnit){
            case 1: $scheduledDate->add(new DateInterval('P'.$intervalNumber.'D')); break;
            case 2: $scheduledDate->add(new DateInterval('P'.($intervalNumber*7).'D')); break;
            case 3: $scheduledDate->add(new DateInterval('P'.$intervalNumber.'M')); break;
            case 4: $scheduledDate->add(new DateInterval('P'.$intervalNumber.'Y')); break;
        }
    }

    if(!empty($input['files']) && is_array($input['files'])){
        $uploadDir = __DIR__."/../uploads/files_cal/";
        if(!is_dir($uploadDir)) mkdir($uploadDir,0777,true);

        $fileStmt = $dbh->prepare("INSERT INTO file_cal (plan_id,file_cal_name,file_cal_url,cal_type_name) VALUES (:plan_id,:file_cal_name,:file_cal_url,:cal_type_name)");

        foreach($input['files'] as $file){
            if(empty($file['name']) || empty($file['base64'])) continue;
            $newName = uniqid().'_'.basename($file['name']);
            $targetPath = $uploadDir.$newName;
            $data = base64_decode($file['base64']);
            if(file_put_contents($targetPath,$data) !== false){
                $fileStmt->execute([
                    ':plan_id'=>$input['plan_id'],
                    ':file_cal_name'=>$file['name'],
                    ':file_cal_url'=>"/uploads/files_cal/".$newName,
                    ':cal_type_name'=>$file['type_name'] ?? 'ไม่ระบุ'
                ]);
            }
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","plan_id"=>$input['plan_id']]);

}catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
