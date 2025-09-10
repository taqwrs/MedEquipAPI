<?php
include "../config/jwt.php";

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
    
    // ตรวจสอบข้อมูลที่จำเป็น
    $required = ['transfer_type', 'equipment_id', 'transfer_date'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit;
        }
    }
    
    // ตรวจสอบว่า equipment_id มีอยู่และ active = 1
    $checkEquipment = $dbh->prepare("
        SELECT e.equipment_id, e.asset_code, e.subcategory_id, e.active, 
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
    
    // สร้าง equipment transfer record
    $sql = "INSERT INTO equipment_transfers (
        transfer_type, equipment_id, from_department_id, to_department_id, transfer_date, reason, 
        transfer_user_id, recipient_user_id, location_department_id, location_details
    ) VALUES (
        :transfer_type, :equipment_id, :from_department_id, :to_department_id, :transfer_date, :reason, 
        :transfer_user_id, :recipient_user_id, :location_department_id, :location_details
    )";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':transfer_type', $input['transfer_type']);
    $stmt->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $stmt->bindParam(':from_department_id', $from_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':to_department_id', $to_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':transfer_date', $input['transfer_date']);
    $stmt->bindParam(':reason', $reason);
    $stmt->bindParam(':transfer_user_id', $transfer_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':recipient_user_id', $recipient_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_department_id', $location_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_details', $location_details);
    
    if (!$stmt->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to create equipment transfer"]);
        exit;
    }
    
    $transfer_id = $dbh->lastInsertId();

    // === การจัดการตาม transfer_type ===
    
    if ($input['transfer_type'] === 'โอนย้ายถาวร') {
        
        // สร้าง subcategory ใหม่ทันที
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
        
        // อัปเดต equipment ให้ไป subcategory ใหม่ทันที
        $updateEquipmentSub = $dbh->prepare("UPDATE equipments SET subcategory_id = :subcategory_id WHERE equipment_id = :equipment_id");
        $updateEquipmentSub->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
        $updateEquipmentSub->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
        $updateEquipmentSub->execute();
        
        // สร้าง group_user "ผู้ดูแลหลัก"
        $mainGroupName = "ผู้ดูแล(" . $equipment['asset_code'] . ")";
        $createMainGroup = $dbh->prepare("INSERT INTO group_user (group_name, type) VALUES (:group_name, 'ผู้ดูแลหลัก')");
        $createMainGroup->bindParam(':group_name', $mainGroupName);
        $createMainGroup->execute();
        $main_group_id = $dbh->lastInsertId();
        
        // เพิ่ม relation_group
        $insertRelationGroup = $dbh->prepare("INSERT INTO relation_group (group_user_id, subcategory_id) VALUES (:group_user_id, :subcategory_id)");
        $insertRelationGroup->bindParam(':group_user_id', $main_group_id, PDO::PARAM_INT);
        $insertRelationGroup->bindParam(':subcategory_id', $new_subcategory_id, PDO::PARAM_INT);
        $insertRelationGroup->execute();
        
        // เพิ่ม relation_user สำหรับผู้ดูแล
        if ($recipient_user_id) {
            $insertRelationUser = $dbh->prepare("INSERT INTO relation_user (group_user_id, u_id) VALUES (:group_user_id, :u_id)");
            $insertRelationUser->bindParam(':group_user_id', $main_group_id, PDO::PARAM_INT);
            $insertRelationUser->bindParam(':u_id', $recipient_user_id, PDO::PARAM_INT);
            $insertRelationUser->execute();
        }
        
    } elseif ($input['transfer_type'] === 'โอนย้ายชั่วคราว') {
        
        $newTempSubcategoryName = $equipment['subcategory_name'] . "(โอนย้ายชั่วคราว)";
        $createTempSubcategory = $dbh->prepare("
            INSERT INTO equipment_subcategories (category_id, name, type) 
            VALUES (:category_id, :name, :type)
        ");
        $createTempSubcategory->bindParam(':category_id', $equipment['category_id'], PDO::PARAM_INT);
        $createTempSubcategory->bindParam(':name', $newTempSubcategoryName);
        $createTempSubcategory->bindParam(':type', $equipment['subcategory_type']);
        $createTempSubcategory->execute();
        $new_temp_subcategory_id = $dbh->lastInsertId();
        
        $tempGroupName = "ผู้ใช้งานโอนย้ายชั่วคราว(" . $equipment['asset_code'] . ")";
        $createTempGroup = $dbh->prepare("INSERT INTO group_user (group_name, type) VALUES (:group_name, 'ผู้ใช้งาน')");
        $createTempGroup->bindParam(':group_name', $tempGroupName);
        $createTempGroup->execute();
        $temp_group_id = $dbh->lastInsertId();
        
        // เพิ่ม relation_group
        $insertTempRelationGroup = $dbh->prepare("INSERT INTO relation_group (group_user_id, subcategory_id) VALUES (:group_user_id, :subcategory_id)");
        $insertTempRelationGroup->bindParam(':group_user_id', $temp_group_id, PDO::PARAM_INT);
        $insertTempRelationGroup->bindParam(':subcategory_id', $new_temp_subcategory_id, PDO::PARAM_INT);
        $insertTempRelationGroup->execute();
        
        // เพิ่ม relation_user
        if ($recipient_user_id) {
            $insertTempRelationUser = $dbh->prepare("INSERT INTO relation_user (group_user_id, u_id) VALUES (:group_user_id, :u_id)");
            $insertTempRelationUser->bindParam(':group_user_id', $temp_group_id, PDO::PARAM_INT);
            $insertTempRelationUser->bindParam(':u_id', $recipient_user_id, PDO::PARAM_INT);
            $insertTempRelationUser->execute();
        }
    }

    // อัปเดต location ของ equipment
    $updateEquipLocation = $dbh->prepare("
        UPDATE equipments 
        SET location_department_id = :location_department_id, 
            location_details = :location_details 
        WHERE equipment_id = :equipment_id
    ");
    $updateEquipLocation->bindParam(':location_department_id', $location_dept_id, PDO::PARAM_INT);
    $updateEquipLocation->bindParam(':location_details', $location_details);
    $updateEquipLocation->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $updateEquipLocation->execute();

    $dbh->commit();
    
    echo json_encode([
        "status" => "ok", 
        "message" => "Equipment transfer created successfully",
        "transfer_id" => (int)$transfer_id,
        "transfer_type" => $input['transfer_type'],
        "new_subcategory_id" => isset($new_subcategory_id) ? (int)$new_subcategory_id : (isset($new_temp_subcategory_id) ? (int)$new_temp_subcategory_id : null),
        "new_group_id" => isset($main_group_id) ? (int)$main_group_id : (isset($temp_group_id) ? (int)$temp_group_id : null)
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
