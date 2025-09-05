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

try {
    $dbh->beginTransaction();

    // ดึงข้อมูลปัจจุบัน
    $stmt = $dbh->prepare("SELECT * FROM calibration_plans WHERE plan_id=:plan_id");
    $stmt->execute([':plan_id'=>$input['plan_id']]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$current){
        echo json_encode(["status"=>"error","message"=>"Plan not found"]);
        exit;
    }

    // field ที่อนุญาตให้อัปเดต
    $fields = [
        'plan_name','user_id','group_user_id','company_id',
        'frequency_number','frequency_unit','frequency_type',
        'start_waranty','start_date','end_date','cost_type',
        'price','type_cal','is_active'
    ];
    $updateData = [];
    foreach($fields as $f){
        $updateData[$f] = array_key_exists($f, $input) ? $input[$f] : $current[$f];
    }

    // ✅ กรณี soft delete (ส่งมาแค่ is_active)
    if (isset($input['is_active']) && count($input) === 2) {
        $stmt = $dbh->prepare("UPDATE calibration_plans SET is_active=:is_active WHERE plan_id=:plan_id");
        $stmt->execute([
            ':is_active' => $input['is_active'],
            ':plan_id'   => $input['plan_id']
        ]);
        $dbh->commit();
        echo json_encode(["status"=>"success","message"=>"Soft delete success"]);
        exit;
    }

    // --- validation และคำนวณ interval_count เฉพาะกรณี update ข้อมูลจริง ---
    $allowed_type_cal = ['ภายใน','ภายนอก'];
    $allowed_cost_type = ['แยกรายรอบ','รวมตลอดทั้งสัญญา'];
    $allowed_frequency_unit = [1,2,3,4];

    if(!in_array($updateData['type_cal'],$allowed_type_cal)){
        echo json_encode(["status"=>"error","message"=>"Invalid type_cal"]); exit;
    }
    if(!in_array($updateData['cost_type'],$allowed_cost_type)){
        echo json_encode(["status"=>"error","message"=>"Invalid cost_type"]); exit;
    }
    if(!in_array((int)$updateData['frequency_unit'],$allowed_frequency_unit)){
        echo json_encode(["status"=>"error","message"=>"Invalid frequency_unit"]); exit;
    }

    // คำนวณ interval_count
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

    // UPDATE เต็ม
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
