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

    // สร้าง instance ของ LogModel
    $logModel = new LogModel($dbh);

    // ดึง plan ปัจจุบัน
    $stmt = $dbh->prepare("SELECT * FROM calibration_plans WHERE plan_id=:plan_id");
    $stmt->execute([':plan_id' => $input['plan_id']]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        echo json_encode(["status" => "error", "message" => "Plan not found"]);
        exit;
    }

    // ตรวจสอบว่า plan มีผลลัพธ์ใน calibration_result หรือไม่
    $stmtCheckResult = $dbh->prepare("
        SELECT COUNT(*) 
        FROM calibration_result cr
        INNER JOIN details_calibration_plans dcp
            ON cr.details_cal_id = dcp.details_cal_id
        WHERE dcp.plan_id = :plan_id
          AND cr.result IS NOT NULL
    ");
    $stmtCheckResult->execute([':plan_id' => $input['plan_id']]);
    $hasResult = $stmtCheckResult->fetchColumn() > 0;

    // ตรวจสอบว่ามีการเปลี่ยนแปลง restricted fields หรือไม่
    $restrictedFieldsChanged = false;
    if ($hasResult) {
        $restrictedFields = [
            'frequency_number' => 'ความถี่ (จำนวน)',
            'frequency_unit' => 'หน่วยความถี่',
            'frequency_type' => 'ประเภทความถี่',
            'start_date' => 'วันที่เริ่มต้น',
            'end_date' => 'วันที่สิ้นสุด'
        ];

        $changedFields = [];
        foreach ($restrictedFields as $field => $fieldName) {
            if (array_key_exists($field, $input) && $input[$field] != $current[$field]) {
                $changedFields[] = $fieldName;
                $restrictedFieldsChanged = true;
            }
        }

        if ($restrictedFieldsChanged) {
            $dbh->rollBack();
            echo json_encode([
                "status" => "error",
                "message" => "ไม่สามารถแก้ไข " . implode(', ', $changedFields) . " ได้ เนื่องจากแผนนี้มีการบันทึกผลแล้ว กรุณาสร้างแผนใหม่",
                "has_result" => true,
                "changed_fields" => $changedFields
            ]);
            exit;
        }
    }

    $fields = [
        'plan_name',
        'user_id',
        'group_user_id',
        'company_id',
        'frequency_number',
        'frequency_unit',
        'frequency_type',
        'start_waranty',
        'start_date',
        'end_date',
        'cost_type',
        'price',
        'type_cal',
        'is_active'
    ];

    $updateData = [];
    foreach ($fields as $f) {
        $updateData[$f] = array_key_exists($f, $input) ? $input[$f] : $current[$f];
    }

    // รับ user_id จาก input หรือใช้จาก current
    $acting_user_id = $input['acting_user_id'] ?? $updateData['user_id'];

    if (isset($input['action']) && $input['action'] === 'deactivate') {
        // บันทึก log สำหรับการ deactivate
        $logModel->insertLog(
            $acting_user_id,
            'calibration_plans',
            'UPDATE',
            ['plan_id' => $input['plan_id'], 'is_active' => $current['is_active']],
            ['plan_id' => $input['plan_id'], 'is_active' => 0]
        );

        $stmt = $dbh->prepare("UPDATE calibration_plans SET is_active=0 WHERE plan_id=:plan_id");
        $stmt->execute([':plan_id' => $input['plan_id']]);
        
        $dbh->commit();
        echo json_encode(["status" => "success", "message" => "Soft delete success"]);
        exit;
    }

    $allowed_type_cal = ['ภายใน', 'ภายนอก'];
    $allowed_cost_type = ['แยกรายรอบ', 'รวมตลอดทั้งสัญญา'];
    $allowed_frequency_unit = [1, 2, 3, 4];

    if (!in_array($updateData['type_cal'], $allowed_type_cal)) {
        echo json_encode(["status" => "error", "message" => "Invalid type_cal"]);
        exit;
    }
    if (!in_array($updateData['cost_type'], $allowed_cost_type)) {
        echo json_encode(["status" => "error", "message" => "Invalid cost_type"]);
        exit;
    }
    if (!in_array((int)$updateData['frequency_unit'], $allowed_frequency_unit)) {
        echo json_encode(["status" => "error", "message" => "Invalid frequency_unit"]);
        exit;
    }

    // คำนวณ interval_count เฉพาะเมื่อไม่มีผลลัพธ์ หรือมีการเปลี่ยน restricted fields
    if (!$hasResult || $restrictedFieldsChanged) {
        $startDate = new DateTime($updateData['start_date']);
        $endDate   = new DateTime($updateData['end_date']);
        if ($endDate < $startDate) {
            echo json_encode(["status" => "error", "message" => "วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น"]);
            exit;
        }
        $intervalNumber = (int)$updateData['frequency_number'];
        $intervalUnit = (int)$updateData['frequency_unit'];

        $intervalCount = 0;
        $roundDates = [];
        $tempDate = clone $startDate;
        while ($tempDate <= $endDate) {
            $intervalCount++;
            $roundDates[] = $tempDate->format('Y-m-d');
            switch ($intervalUnit) {
                case 1:
                    $tempDate->add(new DateInterval('P' . $intervalNumber . 'D'));
                    break;
                case 2:
                    $tempDate->add(new DateInterval('P' . ($intervalNumber * 7) . 'D'));
                    break;
                case 3:
                    $tempDate->add(new DateInterval('P' . $intervalNumber . 'M'));
                    break;
                case 4:
                    $tempDate->add(new DateInterval('P' . $intervalNumber . 'Y'));
                    break;
            }
        }
    } else {
        // Plan มีผลลัพธ์แล้วและไม่มีการแก้ไข restricted fields → ใช้ค่าเดิม
        $intervalCount = $current['interval_count'];
    }

    // เตรียมข้อมูลเก่าสำหรับ log (เฉพาะฟิลด์ที่เปลี่ยน)
    $oldData = [];
    $newData = [];
    $hasChanges = false;

    foreach ($fields as $f) {
        if ($updateData[$f] != $current[$f]) {
            $oldData[$f] = $current[$f];
            $newData[$f] = $updateData[$f];
            $hasChanges = true;
        }
    }

    if ($intervalCount != $current['interval_count']) {
        $oldData['interval_count'] = $current['interval_count'];
        $newData['interval_count'] = $intervalCount;
        $hasChanges = true;
    }

    // Update plan
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
        ':interval_count' => $intervalCount,
        ':plan_id' => $input['plan_id']
    ]));

    if ($hasChanges) {
        $oldData['plan_id'] = $input['plan_id'];
        $newData['plan_id'] = $input['plan_id'];
        
        $logModel->insertLog(
            $acting_user_id,
            'calibration_plans',
            'UPDATE',
            $oldData,
            $newData
        );
    }

    // ลบและเพิ่ม details_calibration_plans ใหม่ เฉพาะเมื่อ:
    // 1. ไม่มีผลลัพธ์ หรือ
    // 2. มีการเปลี่ยน restricted fields (แต่กรณีนี้จะถูกบล็อกไว้แล้วด้านบน)
    if (!$hasResult) {
        $stmtOldDetails = $dbh->prepare("SELECT * FROM details_calibration_plans WHERE plan_id=:plan_id");
        $stmtOldDetails->execute([':plan_id' => $input['plan_id']]);
        $oldDetails = $stmtOldDetails->fetchAll(PDO::FETCH_ASSOC);

        $stmtDel = $dbh->prepare("DELETE FROM details_calibration_plans WHERE plan_id=:plan_id");
        $stmtDel->execute([':plan_id' => $input['plan_id']]);

        if (!empty($oldDetails)) {
            $logModel->insertLog(
                $acting_user_id,
                'details_calibration_plans',
                'DELETE',
                ['plan_id' => $input['plan_id'], 'deleted_count' => count($oldDetails), 'details' => $oldDetails],
                null
            );
        }

        $stmtIns = $dbh->prepare("INSERT INTO details_calibration_plans (plan_id, start_date) VALUES (:plan_id, :start_date)");
        $newDetails = [];
        
        foreach ($roundDates as $rd) {
            $stmtIns->execute([
                ':plan_id' => $input['plan_id'],
                ':start_date' => $rd
            ]);
            
            $newDetails[] = [
                'details_cal_id' => $dbh->lastInsertId(),
                'plan_id' => $input['plan_id'],
                'start_date' => $rd
            ];
        }
        
        if (!empty($newDetails)) {
            $logModel->insertLog(
                $acting_user_id,
                'details_calibration_plans',
                'INSERT',
                null,
                ['plan_id' => $input['plan_id'], 'inserted_count' => count($newDetails), 'details' => $newDetails]
            );
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Plan updated successfully"]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}