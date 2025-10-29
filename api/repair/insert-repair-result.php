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

    $logModel = new LogModel($dbh);

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

    // ดึงข้อมูล repair เดิมก่อนอัปเดต (รวม equipment_id)
    $stmtOldRepair = $dbh->prepare("SELECT * FROM repair WHERE repair_id = :repair_id");
    $stmtOldRepair->execute([':repair_id' => $repair_id]);
    $oldRepairData = $stmtOldRepair->fetch(PDO::FETCH_ASSOC);

    if (!$oldRepairData) {
        throw new Exception("ไม่พบข้อมูลการซ่อม");
    }

    $equipment_id = $oldRepairData['equipment_id'];

    // ดึงข้อมูล equipment เดิมก่อนอัปเดต 
    $oldEquipmentData = null;
    if ($status === 'ซ่อมเสร็จ' && $equipment_id) {
        $stmtOldEquip = $dbh->prepare("SELECT * FROM equipments WHERE equipment_id = :equipment_id");
        $stmtOldEquip->execute([':equipment_id' => $equipment_id]);
        $oldEquipmentData = $stmtOldEquip->fetch(PDO::FETCH_ASSOC);
    }

    // ดึงอะไหล่เก่าที่เชื่อมกับเครื่องมือนี้ (status = 'in_use')
    $stmtOldSpares = $dbh->prepare("
        SELECT spare_part_id, name, asset_code, equipment_id, status 
        FROM spare_parts 
        WHERE equipment_id = :equipment_id 
        AND status = 'ใช้งาน'
    ");
    $stmtOldSpares->execute([':equipment_id' => $equipment_id]);
    $oldSpareParts = $stmtOldSpares->fetchAll(PDO::FETCH_ASSOC);

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

    // เปลี่ยนสถานะอะไหล่เก่าเป็น "เสีย"
    $damagedSpareParts = [];
    if (!empty($oldSpareParts)) {
        $stmt_damage_spare = $dbh->prepare("
            UPDATE spare_parts 
            SET status = 'เสีย',
                updated_by = :user_id
            WHERE spare_part_id = :spare_part_id
        ");

        foreach ($oldSpareParts as $oldSpare) {
            $stmt_damage_spare->execute([
                ':spare_part_id' => $oldSpare['spare_part_id'],
                ':user_id' => $user_id
            ]);

            $newDamagedSpareData = [
                'spare_part_id' => $oldSpare['spare_part_id'],
                'equipment_id' => $oldSpare['equipment_id'],
                'status' => 'เสีย',
                'updated_by' => $user_id,
                'reason' => 'เปลี่ยนสถานะเป็นเสียเนื่องจากมีการเปลี่ยนอะไหล่ใหม่ repair_id: ' . $repair_id,
                'repair_result_id' => $repair_result_id
            ];

            $damagedSpareParts[] = $newDamagedSpareData;

            // บันทึก log การเปลี่ยนสถานะอะไหล่เก่าเป็น "เสีย"
            $logModel->insertLog(
                $user_id,
                'spare_parts',
                'UPDATE',
                $oldSpare,
                $newDamagedSpareData
            );
        }
    }

    // INSERT spare_parts_used และ UPDATE spare_parts ใหม่
    $usedSpareParts = [];
    $updatedSpareParts = [];
    
    if (!empty($spareParts)) {
        $stmt_spare = $dbh->prepare("
            INSERT INTO spare_parts_used (repair_result_id, spare_part_id)
            VALUES (:repair_result_id, :spare_part_id)
        ");
        
        // ดึงข้อมูลอะไหล่เดิมก่อนอัปเดต
        $stmt_get_spare = $dbh->prepare("SELECT * FROM spare_parts WHERE spare_part_id = :spare_part_id");
        
        // อัปเดต spare_parts ใหม่
        $stmt_update_spare = $dbh->prepare("
            UPDATE spare_parts 
            SET equipment_id = :equipment_id,
                status = 'ใช้งาน',
                updated_by = :user_id
            WHERE spare_part_id = :spare_part_id
        ");

        foreach ($spareParts as $spare_id) {
            if ($spare_id !== "") {
                // ดึงข้อมูลอะไหล่เดิม
                $stmt_get_spare->execute([':spare_part_id' => $spare_id]);
                $oldSpareData = $stmt_get_spare->fetch(PDO::FETCH_ASSOC);

                // INSERT ลง spare_parts_used
                $stmt_spare->execute([
                    ':repair_result_id' => $repair_result_id,
                    ':spare_part_id'    => $spare_id
                ]);
                
                $usedSpareParts[] = [
                    'spare_parts_used_id' => $dbh->lastInsertId(),
                    'repair_result_id'    => $repair_result_id,
                    'spare_part_id'       => $spare_id
                ];

                // UPDATE equipment_id และ status ในตาราง spare_parts
                $stmt_update_spare->execute([
                    ':equipment_id' => $equipment_id,
                    ':spare_part_id' => $spare_id,
                    ':user_id' => $user_id
                ]);


                $newSpareData = [
                    'spare_part_id' => $spare_id,
                    'equipment_id' => $equipment_id,
                    'status' => 'ใช้งาน',
                    'updated_by' => $user_id,
                    'reason' => 'ใช้อะไหล่ใหม่ในการซ่อม repair_id: ' . $repair_id,
                    'repair_result_id' => $repair_result_id
                ];

                $updatedSpareParts[] = $newSpareData;


                $logModel->insertLog(
                    $user_id,
                    'spare_parts',
                    'UPDATE',
                    $oldSpareData,
                    $newSpareData
                );
            }
        }


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


    $stmtUpdate = $dbh->prepare("
        UPDATE repair 
        SET status = 'เสร็จสิ้น' 
        WHERE repair_id = :repair_id
    ");
    $stmtUpdate->execute([':repair_id' => $repair_id]);


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


    if ($status === 'ซ่อมเสร็จ') {
        $stmtEquip = $dbh->prepare("
            UPDATE equipments 
            SET status = 'ใช้งาน'
            WHERE equipment_id = :equipment_id
        ");
        $stmtEquip->execute([':equipment_id' => $equipment_id]);

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
        "repair_result_id" => $repair_result_id,
        "equipment_id" => $equipment_id,
        "old_spare_parts_damaged" => count($damagedSpareParts),
        "damaged_spare_parts" => $damagedSpareParts,
        "new_spare_parts_updated" => count($updatedSpareParts),
        "updated_spare_parts" => $updatedSpareParts
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