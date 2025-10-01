<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
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

try {
    $dbh->beginTransaction();

    // ดึงข้อมูล plan ปัจจุบัน
    $stmt = $dbh->prepare("SELECT * FROM maintenance_plans WHERE plan_id=:plan_id");
    $stmt->execute([':plan_id'=>$input['plan_id']]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$current){
        echo json_encode(["status"=>"error","message"=>"Plan not found"]);
        exit;
    }

    $fields = [
        'plan_name','user_id','group_user_id','company_id',
        'frequency_number','frequency_unit','frequency_type',
        'start_waranty','start_date','end_date','cost_type',
        'price','type_ma','contract','is_active'
    ];
    $updateData = [];
    foreach($fields as $f){
        if(array_key_exists($f, $input)){
            // ถ้าเป็นวันที่ และค่าเป็น empty string ให้เป็น NULL
            if(in_array($f, ['start_waranty','start_date','end_date']) && $input[$f] === ''){
                $updateData[$f] = null;
            } else {
                $updateData[$f] = $input[$f];
            }
        } else {
            $updateData[$f] = $current[$f];
        }
    }
    
    // กรณี Soft Delete (เปิด/ปิด plan)
    if (isset($input['is_active']) && count($input) === 2) {
        $stmt = $dbh->prepare("UPDATE maintenance_plans SET is_active=:is_active WHERE plan_id=:plan_id");
        $stmt->execute([
            ':is_active' => $input['is_active'],
            ':plan_id'   => $input['plan_id']
        ]);
        $dbh->commit();
        echo json_encode(["status"=>"success","message"=>"Soft delete success"]);
        exit;
    }

    // ตรวจสอบค่าที่ถูกต้อง
    $allowed_type_ma = ['ภายใน','ภายนอก'];
    $allowed_cost_type = ['แยกรายรอบ','รวมตลอดทั้งสัญญา'];
    $allowed_frequency_unit = [1,2,3,4];

    if(!in_array($updateData['type_ma'],$allowed_type_ma)){
        echo json_encode(["status"=>"error","message"=>"Invalid type_ma"]); exit;
    }
    if(!in_array($updateData['cost_type'],$allowed_cost_type)){
        echo json_encode(["status"=>"error","message"=>"Invalid cost_type"]); exit;
    }
    if(!in_array((int)$updateData['frequency_unit'],$allowed_frequency_unit)){
        echo json_encode(["status"=>"error","message"=>"Invalid frequency_unit"]); exit;
    }

    // คำนวณจำนวนรอบ
    $startDate = new DateTime($updateData['start_date']);
    $endDate   = new DateTime($updateData['end_date']);
    $intervalNumber = (int)$updateData['frequency_number'];
    $intervalUnit = (int)$updateData['frequency_unit'];

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

    // Update plan
    $stmt = $dbh->prepare("UPDATE maintenance_plans SET
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
        contract=:contract,
        price=:price,
        type_ma=:type_ma,
        is_active=:is_active
        WHERE plan_id=:plan_id
    ");
    $stmt->execute(array_merge($updateData, [
        ':interval_count'=>$intervalCount,
        ':plan_id'=>$input['plan_id']
    ]));

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Plan updated"]);

}catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
?>
