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

    // ------------------------------
    // ตรวจสอบว่ามีผลลัพธ์จริงใน maintenance_result หรือไม่
    // ถ้า result ยังไม่มีค่า → สามารถแก้ไขรอบได้
    // ------------------------------
    $stmtCheck = $dbh->prepare("
        SELECT COUNT(*) 
        FROM maintenance_result mr
        INNER JOIN details_maintenance_plans dmp 
            ON mr.details_ma_id = dmp.details_ma_id
        WHERE dmp.plan_id = :plan_id
          AND mr.result IS NOT NULL
    ");
    $stmtCheck->execute([':plan_id'=>$input['plan_id']]);
    $hasResult = $stmtCheck->fetchColumn() > 0;

    // ------------------------------
    // เตรียมข้อมูลสำหรับ update
    // ------------------------------
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

    // ถ้ามีผลลัพธ์แล้ว บางฟิลด์ที่กระทบรอบต้องไม่แก้ไข
    if($hasResult){
        $restrictedFields = ['frequency_number','frequency_unit','frequency_type','start_date','end_date'];
        foreach($restrictedFields as $rf){
            $updateData[$rf] = $current[$rf]; // คืนค่าเดิม
        }
    }

    // ------------------------------
    // กรณี Soft Delete (เปิด/ปิด plan)
    // ------------------------------
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

    // ------------------------------
    // ตรวจสอบค่าที่ถูกต้อง
    // ------------------------------
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

    // ------------------------------
    // คำนวณจำนวนรอบและวันที่รอบ (เฉพาะ plan ยังไม่เริ่มใช้งาน)
    // ------------------------------
    $roundDates = [];
    $intervalCount = 0;
    if(!$hasResult){
        if($updateData['frequency_type'] === 'รอบเดียว'){
            $intervalCount = 1;
            $roundDates[] = $updateData['start_date'];
        } else {
            $startDate = new DateTime($updateData['start_date']);
            $endDate   = new DateTime($updateData['end_date']);
            $intervalNumber = (int)$updateData['frequency_number'];
            $intervalUnit = (int)$updateData['frequency_unit'];

            $tempDate = clone $startDate;
            while($tempDate <= $endDate){
                $intervalCount++;
                $roundDates[] = $tempDate->format('Y-m-d');
                switch($intervalUnit){
                    case 1: $tempDate->add(new DateInterval('P'.$intervalNumber.'D')); break;
                    case 2: $tempDate->add(new DateInterval('P'.($intervalNumber*7).'D')); break;
                    case 3: $tempDate->add(new DateInterval('P'.$intervalNumber.'M')); break;
                    case 4: $tempDate->add(new DateInterval('P'.$intervalNumber.'Y')); break;
                }
            }
        }
    } else {
        // Plan เริ่มใช้งานแล้ว → ใช้ intervalCount เดิม
        $intervalCount = $current['interval_count'];
    }

    // ------------------------------
    // Update plan
    // ------------------------------
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

    // ------------------------------
    // อัปเดตรอบ details_maintenance_plans เฉพาะ plan ที่ยังไม่เริ่มใช้งาน
    // ------------------------------
    if(!$hasResult){
        // ลบรอบเก่า
        $stmtDel = $dbh->prepare("DELETE FROM details_maintenance_plans WHERE plan_id=:plan_id");
        $stmtDel->execute([':plan_id'=>$input['plan_id']]);

        // สร้างรอบใหม่
        $stmtIns = $dbh->prepare("INSERT INTO details_maintenance_plans (plan_id, start_date) VALUES (:plan_id, :start_date)");
        foreach($roundDates as $rd){
            $stmtIns->execute([
                ':plan_id' => $input['plan_id'],
                ':start_date' => $rd
            ]);
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Plan updated"]);

}catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
