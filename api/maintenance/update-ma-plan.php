<?php
include "../config/jwt.php";
include "../config/LogModel.php";

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

if (!isset($input['plan_id'])) {
    echo json_encode(["status" => "error", "message" => "Missing field: plan_id"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) throw new Exception("User ID not found");

    // ดึงข้อมูล plan ปัจจุบัน
    $stmt = $dbh->prepare("SELECT * FROM maintenance_plans WHERE plan_id=:plan_id");
    $stmt->execute([':plan_id' => $input['plan_id']]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        echo json_encode(["status" => "error", "message" => "Plan not found"]);
        exit;
    }

    // ตรวจสอบว่ามีผลลัพธ์จริงใน maintenance_result
    $stmtCheck = $dbh->prepare("
        SELECT COUNT(*) 
        FROM maintenance_result mr
        INNER JOIN details_maintenance_plans dmp 
            ON mr.details_ma_id = dmp.details_ma_id
        WHERE dmp.plan_id = :plan_id
            AND mr.result IS NOT NULL
    ");
    $stmtCheck->execute([':plan_id' => $input['plan_id']]);
    $hasResult = $stmtCheck->fetchColumn() > 0;

    // เตรียมข้อมูล update
    $fields = [
        'plan_name','user_id','group_user_id','company_id','frequency_number',
        'frequency_unit','frequency_type','start_waranty','start_date','end_date',
        'cost_type','price','type_ma','contract','is_active'
    ];

    $updateData = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $input)) {
            $updateData[$f] = in_array($f, ['start_waranty','start_date','end_date']) && $input[$f]==='' 
                ? null 
                : $input[$f];
        } else {
            $updateData[$f] = $current[$f];
        }
    }

    // ------------------ Restricted Fields Flag ------------------
    $restrictedFieldsRetained = false;
    if ($hasResult) {
        $restrictedFields = ['frequency_number','frequency_unit','frequency_type','start_date','end_date'];
        foreach ($restrictedFields as $rf) {
            if (array_key_exists($rf, $input) && $input[$rf] !== $current[$rf]) {
                $restrictedFieldsRetained = true;
            }
            $updateData[$rf] = $current[$rf]; // บังคับคืนค่าเดิม
        }
    }

    // ------ Soft Delete ------
    if (isset($input['is_active']) && count($input) === 2) {
        $stmt = $dbh->prepare("UPDATE maintenance_plans SET is_active=:is_active WHERE plan_id=:plan_id");
        $stmt->execute([':is_active'=>$input['is_active'], ':plan_id'=>$input['plan_id']]);

        // Log Soft Delete
        $changed = $log->filterChangedFields($current, ['is_active'=>$input['is_active']]);
        if ($changed) {
            $changedWithPK = array_merge(['plan_id'=>$input['plan_id']], $changed);
            $log->insertLog($user_id, 'maintenance_plans','UPDATE',['plan_id'=>$current['plan_id']], $changedWithPK);
        }

        $dbh->commit();
        echo json_encode(["status"=>"success","message"=>"Soft delete success"]);
        exit;
    }

    // ------ Validate fields ------
    $allowed_type_ma = ['ภายใน','ภายนอก'];
    $allowed_cost_type = ['แยกรายรอบ','รวมตลอดทั้งสัญญา'];
    $allowed_frequency_unit = [1,2,3,4];

    if (!in_array($updateData['type_ma'],$allowed_type_ma))
        throw new Exception("Invalid type_ma");
    if (!in_array($updateData['cost_type'],$allowed_cost_type))
        throw new Exception("Invalid cost_type");
    if (!in_array((int)$updateData['frequency_unit'],$allowed_frequency_unit))
        throw new Exception("Invalid frequency_unit");

    // ------ คำนวณรอบ ------
    $roundDates = [];
    $intervalCount = 0;
    if (!$hasResult) {
        if ($updateData['frequency_type']==='รอบเดียว') {
            $intervalCount=1;
            $roundDates[] = $updateData['start_date'];
        } else {
            $startDate=new DateTime($updateData['start_date']);
            $endDate=new DateTime($updateData['end_date']);
            $intervalNumber=(int)$updateData['frequency_number'];
            $intervalUnit=(int)$updateData['frequency_unit'];
            if ($endDate<$startDate) throw new Exception("วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น");
            $tempDate=clone $startDate;
            while($tempDate<=$endDate){
                $intervalCount++;
                $roundDates[] = $tempDate->format('Y-m-d');
                switch($intervalUnit){
                    case 1:$tempDate->add(new DateInterval('P'.$intervalNumber.'D'));break;
                    case 2:$tempDate->add(new DateInterval('P'.($intervalNumber*7).'D'));break;
                    case 3:$tempDate->add(new DateInterval('P'.$intervalNumber.'M'));break;
                    case 4:$tempDate->add(new DateInterval('P'.$intervalNumber.'Y'));break;
                }
            }
        }
    } else {
        $intervalCount = $current['interval_count'];
    }

    // ------ ตรวจชื่อ plan ซ้ำ ------
    $stmtCheckName=$dbh->prepare("
        SELECT COUNT(*) FROM maintenance_plans 
        WHERE plan_name=:plan_name AND plan_id!=:plan_id
    ");
    $stmtCheckName->execute([':plan_name'=>$updateData['plan_name'], ':plan_id'=>$input['plan_id']]);
    if($stmtCheckName->fetchColumn()>0) throw new Exception("ชื่อแผนซ้ำ");

    // ------ Update plan ------
    $stmt=$dbh->prepare("UPDATE maintenance_plans SET
        plan_name=:plan_name,user_id=:user_id,group_user_id=:group_user_id,company_id=:company_id,
        frequency_number=:frequency_number,frequency_unit=:frequency_unit,frequency_type=:frequency_type,
        interval_count=:interval_count,start_waranty=:start_waranty,start_date=:start_date,end_date=:end_date,
        cost_type=:cost_type,contract=:contract,price=:price,type_ma=:type_ma,is_active=:is_active
        WHERE plan_id=:plan_id
    ");
    $stmt->execute(array_merge($updateData,[':interval_count'=>$intervalCount, ':plan_id'=>$input['plan_id']]));

    // ------ Log update maintenance_plans ------
    $changedFields = $log->filterChangedFields($current,$updateData);
    if($changedFields){
        $changedWithPK = array_merge(['plan_id'=>$input['plan_id']], $changedFields);
        $log->insertLog($user_id,'maintenance_plans','UPDATE',['plan_id'=>$current['plan_id']],$changedWithPK);
    }

    // ------ Update details_maintenance_plans (ใช้ UPDATE log ทั้งหมด) ------
    if(!$hasResult){
        // เก็บรอบเก่า
        $stmtOld = $dbh->prepare("SELECT start_date FROM details_maintenance_plans WHERE plan_id=:plan_id ORDER BY start_date ASC");
        $stmtOld->execute([':plan_id'=>$input['plan_id']]);
        $oldRounds = $stmtOld->fetchAll(PDO::FETCH_COLUMN);

        // ลบรอบเก่า
        $stmtDel = $dbh->prepare("DELETE FROM details_maintenance_plans WHERE plan_id=:plan_id");
        $stmtDel->execute([':plan_id'=>$input['plan_id']]);

        // สร้างรอบใหม่
        $stmtIns = $dbh->prepare("INSERT INTO details_maintenance_plans (plan_id,start_date) VALUES(:plan_id,:start_date)");
        foreach($roundDates as $rd){
            $stmtIns->execute([':plan_id'=>$input['plan_id'], ':start_date'=>$rd]);
        }

        // Log as UPDATE
        $log->insertLog(
            $user_id,
            'details_maintenance_plans',
            'UPDATE',
            ['plan_id'=>$input['plan_id'],'round_dates'=>$oldRounds],
            ['plan_id'=>$input['plan_id'],'round_dates'=>$roundDates]
        );
    }

    $dbh->commit();

    echo json_encode([
        "status"=>"success",
        "message"=>"Plan updated",
        "restricted_fields"=>$restrictedFieldsRetained
    ]);

} catch(Exception $e){
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
