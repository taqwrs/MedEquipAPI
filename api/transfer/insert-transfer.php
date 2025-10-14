<?php
include "../config/jwt.php";
//การโอนย้าย 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'POST') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(["status" => "error", "message" => "Invalid JSON input"]);
        exit;
    }

    $now = date('Y-m-d H:i:s');

    // จัดการวันที่โอนย้าย: ใช้วันที่ที่เลือก + เวลาปัจจุบัน
    if (isset($input['transfer_date']) && !empty($input['transfer_date'])) {
        $selected_date = date('Y-m-d', strtotime($input['transfer_date']));
        $current_time = date('H:i:s');
        $transfer_date = $selected_date . ' ' . $current_time;
    } else {
        $transfer_date = $now;
    }
        
    $required = ['transfer_type', 'equipment_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit;
        }
    }
    // ตรวจสอบว่า equipment_id มีอยู่และ active = 1 พร้อมดึงข้อมูล location เดิม
    $checkEquipment = $dbh->prepare("
        SELECT e.equipment_id, e.asset_code, e.subcategory_id, e.active, 
               e.location_department_id as old_location_department_id,
               e.location_details as old_location_details,
               s.name as subcategory_name, s.category_id, s.type as subcategory_type
        FROM equipments e 
        LEFT JOIN equipment_subcategories s ON e.subcategory_id = s.subcategory_id
        WHERE e.equipment_id = :equipment_id
    ");
    $checkEquipment->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $checkEquipment->execute();
    
    $equipment = $checkEquipment->fetch(PDO::FETCH_ASSOC);
    
    if (!$equipment) {
        echo json_encode(["status" => "error", "message" => "Equipment not found"]);
        exit;
    }
    
    // ตรวจสอบ active = 1 เท่านั้น
    if ($equipment['active'] != 1) {
        echo json_encode(["status" => "error", "message" => "Equipment transfer only allowed for active equipment (active = 1)"]);
        exit;
    }
    
    // เก็บข้อมูลเดิมของ equipment ก่อนการโอนย้าย
    $old_location_department_id = $equipment['old_location_department_id'];
    $old_location_details = $equipment['old_location_details'];// *** ข้อมูล location_details เดิม ***
    
    // ตรวจสอบ departments หากมีการระบุมา
    $deptFields = ['from_department_id', 'to_department_id', 'location_department_id'];
    foreach ($deptFields as $field) {
        if (isset($input[$field]) && !empty($input[$field])) {
            $checkDept = $dbh->prepare("SELECT department_id FROM departments WHERE department_id = :dept_id");
            $checkDept->bindParam(':dept_id', $input[$field], PDO::PARAM_INT);
            $checkDept->execute();
            
            if ($checkDept->rowCount() == 0) {
                echo json_encode(["status" => "error", "message" => ucfirst(str_replace('_',' ',$field)) . " not found"]);
                exit;
            }
        }
    }
    
    // ตรวจสอบ users หากมีการระบุมา
    $userFields = ['transfer_user_id', 'recipient_user_id'];
    foreach ($userFields as $field) {
        if (isset($input[$field]) && !empty($input[$field])) {
            $checkUser = $dbh->prepare("SELECT ID FROM users WHERE ID = :user_id");
            $checkUser->bindParam(':user_id', $input[$field], PDO::PARAM_INT);
            $checkUser->execute();
            
            if ($checkUser->rowCount() == 0) {
                echo json_encode(["status" => "error", "message" => ucfirst(str_replace('_',' ',$field)) . " not found"]);
                exit;
            }
        }
    }

    // เริ่ม transaction
    $dbh->beginTransaction();
    
    // เตรียมค่าจัดการ NULL
    $from_dept_id = isset($input['from_department_id']) && !empty($input['from_department_id']) ? $input['from_department_id'] : null;
    $to_dept_id = isset($input['to_department_id']) && !empty($input['to_department_id']) ? $input['to_department_id'] : null;
    $transfer_user_id = isset($input['transfer_user_id']) && !empty($input['transfer_user_id']) ? $input['transfer_user_id'] : null;
    $recipient_user_id = isset($input['recipient_user_id']) && !empty($input['recipient_user_id']) ? $input['recipient_user_id'] : null;
    $location_dept_id = isset($input['location_department_id']) && !empty($input['location_department_id']) ? $input['location_department_id'] : null;
    $reason = isset($input['reason']) ? $input['reason'] : null;
    $location_details = isset($input['location_details']) ? $input['location_details'] : null;
    
    // เก็บ old_subcategory_id เพื่อใช้ในการบันทึกและโอนคืน
    $old_subcategory_id = $equipment['subcategory_id'];
    $new_subcategory_id = null;
    $new_group_id = null;

    // กำหนดสถานะการโอนตาม transfer_type
    $transfer_status = ($input['transfer_type'] === 'โอนย้ายชั่วคราว') ? 0 : 1;

    // === การจัดการตาม transfer_type ===
    
    if ($input['transfer_type'] === 'โอนย้ายถาวร') {
        
        // 1.2 สร้าง group_user ใหม่ (ผู้ดูแลหลัก)
        $newMainGroupName = "ผู้ดูแล(" . $equipment['asset_code'] . ")";
        $createMainGroup = $dbh->prepare("INSERT INTO group_user (group_name, type) VALUES (:group_name, 'ผู้ดูแลหลัก')");
        $createMainGroup->bindParam(':group_name', $newMainGroupName);
        $createMainGroup->execute();
        $new_group_id = $dbh->lastInsertId();
        
        // 1.3 สร้าง subcategory ใหม่
        $newSubcategoryName = $equipment['subcategory_name'] . "(โอนย้ายถาวร)";
        $createSubcategory = $dbh->prepare("
            INSERT INTO equipment_subcategories (category_id, name, type) 
            VALUES (:category_id, :name, :type)
        ");
        $createSubcategory->bindParam(':category_id', $equipment['category_id'], PDO::PARAM_INT);
        $createSubcategory->bindParam(':name', $newSubcategoryName);
        $createSubcategory->bindParam(':type', $equipment['subcategory_type']);
        $createSubcategory->execute();
        $new_subcategory_id = $dbh->lastInsertId();
        
        // 1.4 ดึง group_user ที่ type = ผู้ใช้งาน จาก subcategory_id เดิม
        $getOriginalUserGroups = $dbh->prepare("
            SELECT gu.group_user_id, gu.group_name, gu.type
            FROM group_user gu
            INNER JOIN relation_group rg ON gu.group_user_id = rg.group_user_id
            WHERE rg.subcategory_id = :subcategory_id AND gu.type = 'ผู้ใช้งาน'
        ");
        $getOriginalUserGroups->bindParam(':subcategory_id', $old_subcategory_id, PDO::PARAM_INT);
        $getOriginalUserGroups->execute();
        $originalUserGroups = $getOriginalUserGroups->fetchAll(PDO::FETCH_ASSOC);
        
        // 1.4 ผูก group_user ใหม่ (ผู้ดูแลหลัก) เข้ากับ subcategory ใหม่
        $insertMainRelationGroup = $dbh->prepare("INSERT INTO relation_group (group_user_id, subcategory_id) VALUES (:group_user_id, :subcategory_id)");
        $insertMainRelationGroup->bindParam(':group_user_id', $new_group_id, PDO::PARAM_INT);
        $insertMainRelationGroup->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
        $insertMainRelationGroup->execute();
        
        // 1.4 ผูก group_user เดิม (ผู้ใช้งาน) เข้ากับ subcategory ใหม่ด้วย (คัดลอก)
        foreach ($originalUserGroups as $userGroup) {
            $insertUserRelationGroup = $dbh->prepare("INSERT INTO relation_group (group_user_id, subcategory_id) VALUES (:group_user_id, :subcategory_id)");
            $insertUserRelationGroup->bindParam(':group_user_id', $userGroup['group_user_id'], PDO::PARAM_INT);
            $insertUserRelationGroup->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
            $insertUserRelationGroup->execute();
        }
        
        // 1.5 เพิ่ม relation_user สำหรับผู้ดูแลใหม่ (recipient_user_id)
        if ($recipient_user_id) {
            $insertRelationUser = $dbh->prepare("INSERT INTO relation_user (group_user_id, u_id) VALUES (:group_user_id, :u_id)");
            $insertRelationUser->bindParam(':group_user_id', $new_group_id, PDO::PARAM_INT);
            $insertRelationUser->bindParam(':u_id', $recipient_user_id, PDO::PARAM_INT);
            $insertRelationUser->execute();
        }
        
        // 1.1 อัปเดต equipment ให้ไป subcategory ใหม่ (ถอนจากเดิม)
        $updateEquipmentSub = $dbh->prepare("UPDATE equipments SET subcategory_id = :subcategory_id WHERE equipment_id = :equipment_id");
        $updateEquipmentSub->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
        $updateEquipmentSub->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
        $updateEquipmentSub->execute();
        
    } elseif ($input['transfer_type'] === 'โอนย้ายชั่วคราว') {
        
        // 2.2 สร้าง group_user ใหม่ (ผู้ใช้งาน)
        $tempGroupName = "ผู้ใช้งานโอนย้ายชั่วคราว(" . $equipment['asset_code'] . ")";
        $createTempGroup = $dbh->prepare("INSERT INTO group_user (group_name, type) VALUES (:group_name, 'ผู้ใช้งาน')");
        $createTempGroup->bindParam(':group_name', $tempGroupName);
        $createTempGroup->execute();
        $new_group_id = $dbh->lastInsertId();
        
        // 2.3 สร้าง subcategory ใหม่
        $newTempSubcategoryName = $equipment['subcategory_name'] . "(โอนย้ายชั่วคราว)";
        $createTempSubcategory = $dbh->prepare("
            INSERT INTO equipment_subcategories (category_id, name, type) 
            VALUES (:category_id, :name, :type)
        ");
        $createTempSubcategory->bindParam(':category_id', $equipment['category_id'], PDO::PARAM_INT);
        $createTempSubcategory->bindParam(':name', $newTempSubcategoryName);
        $createTempSubcategory->bindParam(':type', $equipment['subcategory_type']);
        $createTempSubcategory->execute();
        $new_subcategory_id = $dbh->lastInsertId();
        
        // 2.4 ดึง group_user ที่ type = ผู้ดูแลหลัก จาก subcategory_id เดิม
        $getOriginalAdminGroups = $dbh->prepare("
            SELECT gu.group_user_id, gu.group_name, gu.type
            FROM group_user gu
            INNER JOIN relation_group rg ON gu.group_user_id = rg.group_user_id
            WHERE rg.subcategory_id = :subcategory_id AND gu.type = 'ผู้ดูแลหลัก'
        ");
        $getOriginalAdminGroups->bindParam(':subcategory_id', $old_subcategory_id, PDO::PARAM_INT);
        $getOriginalAdminGroups->execute();
        $originalAdminGroups = $getOriginalAdminGroups->fetchAll(PDO::FETCH_ASSOC);
        
        // 2.4 ผูก group_user ใหม่ (ผู้ใช้งาน) เข้ากับ subcategory ใหม่
        $insertTempRelationGroup = $dbh->prepare("INSERT INTO relation_group (group_user_id, subcategory_id) VALUES (:group_user_id, :subcategory_id)");
        $insertTempRelationGroup->bindParam(':group_user_id', $new_group_id, PDO::PARAM_INT);
        $insertTempRelationGroup->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
        $insertTempRelationGroup->execute();
        
        // 2.4 ผูก group_user เดิม (ผู้ดูแลหลัก) เข้ากับ subcategory ใหม่ด้วย (คัดลอก)
        foreach ($originalAdminGroups as $adminGroup) {
            $insertAdminRelationGroup = $dbh->prepare("INSERT INTO relation_group (group_user_id, subcategory_id) VALUES (:group_user_id, :subcategory_id)");
            $insertAdminRelationGroup->bindParam(':group_user_id', $adminGroup['group_user_id'], PDO::PARAM_INT);
            $insertAdminRelationGroup->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
            $insertAdminRelationGroup->execute();
        }
        
        // 2.5 เพิ่ม relation_user สำหรับผู้ใช้งานชั่วคราว (recipient_user_id)
        if ($recipient_user_id) {
            $insertTempRelationUser = $dbh->prepare("INSERT INTO relation_user (group_user_id, u_id) VALUES (:group_user_id, :u_id)");
            $insertTempRelationUser->bindParam(':group_user_id', $new_group_id, PDO::PARAM_INT);
            $insertTempRelationUser->bindParam(':u_id', $recipient_user_id, PDO::PARAM_INT);
            $insertTempRelationUser->execute();
        }
        
        // 2.1 อัปเดต equipment ให้ไปยัง subcategory ใหม่
        $updateTempEquipmentSub = $dbh->prepare("UPDATE equipments SET subcategory_id = :subcategory_id WHERE equipment_id = :equipment_id");
        $updateTempEquipmentSub->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
        $updateTempEquipmentSub->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
        $updateTempEquipmentSub->execute();
    }

    // อัปเดต location ของ equipment และข้อมูลอื่นๆ ที่เกี่ยวข้อง
    $updateEquipLocation = $dbh->prepare("
        UPDATE equipments 
        SET location_department_id = :location_department_id, 
            location_details = :location_details,
            updated_by = :updated_by,
            updated_at = :updated_at
        WHERE equipment_id = :equipment_id
    ");
    $updateEquipLocation->bindParam(':location_department_id', $location_dept_id, PDO::PARAM_INT);
    $updateEquipLocation->bindParam(':location_details', $location_details);
    $updateEquipLocation->bindParam(':updated_by', $transfer_user_id, PDO::PARAM_INT);
    $updateEquipLocation->bindParam(':updated_at', $now); 
    $updateEquipLocation->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $updateEquipLocation->execute();

    // สร้าง equipment transfer record พร้อมกับข้อมูลครบถ้วน
    $sql = "INSERT INTO equipment_transfers (
        transfer_type, equipment_id, from_department_id, to_department_id, transfer_date, reason, 
        transfer_user_id, recipient_user_id, location_department_id, location_details,
        old_subcategory_id, new_subcategory_id, now_subcategory_id, status, old_location_department_id
    ) VALUES (
        :transfer_type, :equipment_id, :from_department_id, :to_department_id, :transfer_date, :reason, 
        :transfer_user_id, :recipient_user_id, :location_department_id, :location_details,
        :old_subcategory_id, :new_subcategory_id, :now_subcategory_id, :status, :old_location_department_id
    )";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':transfer_type', $input['transfer_type']);
    $stmt->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $stmt->bindParam(':from_department_id', $from_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':to_department_id', $to_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':transfer_date', $transfer_date);
    $stmt->bindParam(':reason', $reason);
    $stmt->bindParam(':transfer_user_id', $transfer_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':recipient_user_id', $recipient_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_department_id', $location_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_details', $location_details);
    $stmt->bindParam(':old_subcategory_id', $old_subcategory_id, PDO::PARAM_INT);
    $stmt->bindParam(':new_subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
    $stmt->bindParam(':now_subcategory_id', $new_subcategory_id, PDO::PARAM_INT);// ชนิดปัจจุบันหลังการโอน
    $stmt->bindParam(':status', $transfer_status, PDO::PARAM_INT);// 0=ชั่วคราว, 1=ถาวร
    $stmt->bindParam(':old_location_department_id', $old_location_department_id, PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to create equipment transfer"]);
        exit;
    }
    
    $transfer_id = $dbh->lastInsertId();

    // *** บันทึกลง history_transfer พร้อมกับ status_transfer ***
    $historySQL = "INSERT INTO history_transfer (
        transfer_id, transfer_type, equipment_id, from_department_id, to_department_id, 
        transfer_date, reason, transfer_user_id, recipient_user_id, 
        trans_location_department_id, trans_location_details,
        old_subcategory_id, new_subcategory_id, now_subcategory_id,
        now_equip_location_department_id, now_equip_location_details,
        old_location_department_id, old_equip_location_details, 
        status_transfer, updated_at
    ) VALUES (
        :transfer_id, :transfer_type, :equipment_id, :from_department_id, :to_department_id,
        :transfer_date, :reason, :transfer_user_id, :recipient_user_id,
        :trans_location_department_id, :trans_location_details,
        :old_subcategory_id, :new_subcategory_id, :now_subcategory_id,
        :now_equip_location_department_id, :now_equip_location_details,
        :old_location_department_id, :old_equip_location_details, 
        :status_transfer, NOW()
    )";
    
    $historyStmt = $dbh->prepare($historySQL);
    $historyStmt->bindParam(':transfer_id', $transfer_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':transfer_type', $input['transfer_type']);
    $historyStmt->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $historyStmt->bindParam(':from_department_id', $from_dept_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':to_department_id', $to_dept_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':transfer_date', $transfer_date);
    $historyStmt->bindParam(':reason', $reason);
    $historyStmt->bindParam(':transfer_user_id', $transfer_user_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':recipient_user_id', $recipient_user_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':trans_location_department_id', $location_dept_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':trans_location_details', $location_details);
    $historyStmt->bindParam(':old_subcategory_id', $old_subcategory_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':new_subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':now_subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':now_equip_location_department_id', $location_dept_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':now_equip_location_details', $location_details);
    $historyStmt->bindParam(':old_location_department_id', $old_location_department_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':old_equip_location_details', $old_location_details);
    $historyStmt->bindParam(':status_transfer', $transfer_status, PDO::PARAM_INT);
    
    if (!$historyStmt->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to create history transfer record"]);
        exit;
    }

    $dbh->commit();
    
    echo json_encode([
        "status" => "success", 
        "message" => "Equipment transfer created successfully",
         "transfer_date" => $transfer_date, 
        "transfer_id" => (int)$transfer_id,
        "transfer_type" => $input['transfer_type'],
        "status_transfer" => $transfer_status, // เพิ่มข้อมูลใน response
        "old_subcategory_id" => (int)$old_subcategory_id,
        "new_subcategory_id" => (int)$new_subcategory_id,
        "now_subcategory_id" => (int)$new_subcategory_id,
        "new_group_id" => (int)$new_group_id,
        "old_location_department_id" => (int)$old_location_department_id,
        "old_equip_location_details" => $old_location_details,
        "now_location_department_id" => (int)$location_dept_id,
        "equipment_moved" => true,
        "history_recorded" => true
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>