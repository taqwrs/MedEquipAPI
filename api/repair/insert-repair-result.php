<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // สร้าง instance ของ LogModel
    $logModel = new LogModel($dbh);
    
    // รับข้อมูล JSON
    $input = json_decode(file_get_contents("php://input"), true);
    
    $repair_id      = $input['repair_id'] ?? null;
    $performed_date = $input['performed_date'] ?? null;
    $solution       = $input['solution'] ?? null;
    $cost           = $input['cost'] ?? null;
    $status         = $input['status'] ?? null; 
    $remark         = $input['remark'] ?? null;
    $spareParts     = $input['spareParts'] ?? [];
    $next_action    = $input['next_action'] ?? null;

    if (!$repair_id || !$performed_date || !$solution || $cost === null || !$status) {
        throw new Exception("ข้อมูลไม่ครบถ้วน");
    }

    $user_id = $input['user_id'] ?? null;
    if (!$user_id) throw new Exception("ไม่พบ user_id");

    if ($status === "ซ่อมไม่ได้" && !$next_action) {
        throw new Exception("กรุณาเลือกการดำเนินการต่อไป (next_action)");
    }

    $dbh->beginTransaction();

    // ดึงข้อมูล repair เดิมก่อนอัปเดต
    $stmtOldRepair = $dbh->prepare("SELECT * FROM repair WHERE repair_id = :repair_id");
    $stmtOldRepair->execute([':repair_id' => $repair_id]);
    $oldRepairData = $stmtOldRepair->fetch(PDO::FETCH_ASSOC);

    // ดึงข้อมูล equipment เดิมก่อนอัปเดต (ถ้ามี)
    $oldEquipmentData = null;
    if ($status === 'ซ่อมเสร็จ' && $oldRepairData) {
        $stmtOldEquip = $dbh->prepare("SELECT * FROM equipments WHERE equipment_id = :equipment_id");
        $stmtOldEquip->execute([':equipment_id' => $oldRepairData['equipment_id']]);
        $oldEquipmentData = $stmtOldEquip->fetch(PDO::FETCH_ASSOC);
    }

    // INSERT repair_result
    $stmt = $dbh->prepare("
        INSERT INTO repair_result
        (repair_id, user_id, performed_date, solution, cost, status, remark, next_action)
        VALUES (:repair_id, :user_id, :performed_date, :solution, :cost, :status, :remark, :next_action)
    ");
    $stmt->execute([
        ':repair_id'      => $repair_id,
        ':user_id'        => $user_id,
        ':performed_date' => $performed_date,
        ':solution'       => $solution,
        ':cost'           => $cost,
        ':status'         => $status,
        ':remark'         => $remark,
        ':next_action'    => $status === "ซ่อมไม่ได้" ? $next_action : null
    ]);
    $repair_result_id = $dbh->lastInsertId();

    // เก็บข้อมูล repair_result ที่สร้างขึ้น
    $newRepairResultData = [
        'repair_result_id' => $repair_result_id,
        'repair_id'        => $repair_id,
        'user_id'          => $user_id,
        'performed_date'   => $performed_date,
        'solution'         => $solution,
        'cost'             => $cost,
        'status'           => $status,
        'remark'           => $remark,
        'next_action'      => $status === "ซ่อมไม่ได้" ? $next_action : null
    ];

    // บันทึก log สำหรับการ INSERT repair_result
    $logModel->insertLog(
        $user_id,
        'repair_result',
        'INSERT',
        null,
        $newRepairResultData
    );

    // INSERT spare_parts_used
    $usedSpareParts = [];
    if (!empty($spareParts)) {
        $stmt_spare = $dbh->prepare("
            INSERT INTO spare_parts_used (repair_result_id, spare_part_id)
            VALUES (:repair_result_id, :spare_part_id)
        ");
        foreach ($spareParts as $spare_id) {
            if ($spare_id !== "") {
                $stmt_spare->execute([
                    ':repair_result_id' => $repair_result_id,
                    ':spare_part_id'    => $spare_id
                ]);
                $usedSpareParts[] = [
                    'spare_parts_used_id' => $dbh->lastInsertId(),
                    'repair_result_id'    => $repair_result_id,
                    'spare_part_id'       => $spare_id
                ];
            }
        }

        // บันทึก log สำหรับการ INSERT spare_parts_used
        if (!empty($usedSpareParts)) {
            $logModel->insertLog(
                $user_id,
                'spare_parts_used',
                'INSERT',
                null,
                [
                    'repair_result_id' => $repair_result_id,
                    'spare_parts'      => $usedSpareParts
                ]
            );
        }
    }

    // UPDATE repair status
    $stmtUpdate = $dbh->prepare("
        UPDATE repair 
        SET status = 'เสร็จสิ้น' 
        WHERE repair_id = :repair_id
    ");
    $stmtUpdate->execute([':repair_id' => $repair_id]);

    // บันทึก log สำหรับการ UPDATE repair
    $newRepairData = [
        'repair_id' => $repair_id,
        'status'    => 'เสร็จสิ้น',
        'reason'    => 'บันทึกผลการซ่อมเรียบร้อย',
        'repair_result_id' => $repair_result_id
    ];

    $logModel->insertLog(
        $user_id,
        'repair',
        'UPDATE',
        $oldRepairData,
        $newRepairData
    );

    // UPDATE equipments status (ถ้าซ่อมเสร็จ)
    if ($status === 'ซ่อมเสร็จ') {
        $stmtEquip = $dbh->prepare("
            UPDATE equipments 
            SET status = 'ใช้งาน'
            WHERE equipment_id = (
                SELECT equipment_id FROM repair WHERE repair_id = :repair_id
            )
        ");
        $stmtEquip->execute([':repair_id' => $repair_id]);

        // บันทึก log สำหรับการ UPDATE equipments
        if ($oldEquipmentData) {
            $newEquipmentData = [
                'equipment_id' => $oldEquipmentData['equipment_id'],
                'status'       => 'ใช้งาน',
                'reason'       => 'เปลี่ยนสถานะเนื่องจากซ่อมเสร็จ',
                'repair_id'    => $repair_id,
                'repair_result_id' => $repair_result_id
            ];

            $logModel->insertLog(
                $user_id,
                'equipments',
                'UPDATE',
                $oldEquipmentData,
                $newEquipmentData
            );
        }
    }

    $dbh->commit();

    echo json_encode([
        "status" => "success",
        "message" => "บันทึกผลการซ่อมเรียบร้อย",
        "repair_result_id" => $repair_result_id
    ]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) 
        $dbh->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>