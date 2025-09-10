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
    
    // ตรวจสอบว่า equipment_id มีอยู่ในตาราง equipments หรือไม่
    $checkEquipment = $dbh->prepare("SELECT equipment_id FROM equipments WHERE equipment_id = :equipment_id");
    $checkEquipment->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $checkEquipment->execute();
    
    if ($checkEquipment->rowCount() == 0) {
        echo json_encode(["status" => "error", "message" => "Equipment not found"]);
        exit;
    }
    
    // ตรวจสอบ departments หากมีการระบุมา
    if (isset($input['from_department_id']) && !empty($input['from_department_id'])) {
        $checkDept = $dbh->prepare("SELECT department_id FROM departments WHERE department_id = :dept_id");
        $checkDept->bindParam(':dept_id', $input['from_department_id'], PDO::PARAM_INT);
        $checkDept->execute();
        
        if ($checkDept->rowCount() == 0) {
            echo json_encode(["status" => "error", "message" => "From department not found"]);
            exit;
        }
    }
    
    if (isset($input['to_department_id']) && !empty($input['to_department_id'])) {
        $checkDept = $dbh->prepare("SELECT department_id FROM departments WHERE department_id = :dept_id");
        $checkDept->bindParam(':dept_id', $input['to_department_id'], PDO::PARAM_INT);
        $checkDept->execute();
        
        if ($checkDept->rowCount() == 0) {
            echo json_encode(["status" => "error", "message" => "To department not found"]);
            exit;
        }
    }
    
    // ตรวจสอบ users หากมีการระบุมา
    if (isset($input['transfer_user_id']) && !empty($input['transfer_user_id'])) {
        $checkUser = $dbh->prepare("SELECT ID FROM users WHERE ID = :user_id");
        $checkUser->bindParam(':user_id', $input['transfer_user_id'], PDO::PARAM_INT);
        $checkUser->execute();
        
        if ($checkUser->rowCount() == 0) {
            echo json_encode(["status" => "error", "message" => "Transfer user not found"]);
            exit;
        }
    }
    
    if (isset($input['recipient_user_id']) && !empty($input['recipient_user_id'])) {
        $checkUser = $dbh->prepare("SELECT ID FROM users WHERE ID = :user_id");
        $checkUser->bindParam(':user_id', $input['recipient_user_id'], PDO::PARAM_INT);
        $checkUser->execute();
        
        if ($checkUser->rowCount() == 0) {
            echo json_encode(["status" => "error", "message" => "Recipient user not found"]);
            exit;
        }
    }
    
    $sql = "INSERT INTO equipment_transfers (
        transfer_type, 
        equipment_id, 
        from_department_id, 
        to_department_id, 
        transfer_date, 
        reason, 
        transfer_user_id, 
        recipient_user_id, 
        location_department_id, 
        location_details
    ) VALUES (
        :transfer_type, 
        :equipment_id, 
        :from_department_id, 
        :to_department_id, 
        :transfer_date, 
        :reason, 
        :transfer_user_id, 
        :recipient_user_id, 
        :location_department_id, 
        :location_details
    )";
    
    $stmt = $dbh->prepare($sql);
    
    $stmt->bindParam(':transfer_type', $input['transfer_type']);
    $stmt->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    
    // จัดการ NULL values
    $from_dept_id = isset($input['from_department_id']) && !empty($input['from_department_id']) ? $input['from_department_id'] : null;
    $to_dept_id = isset($input['to_department_id']) && !empty($input['to_department_id']) ? $input['to_department_id'] : null;
    $transfer_user_id = isset($input['transfer_user_id']) && !empty($input['transfer_user_id']) ? $input['transfer_user_id'] : null;
    $recipient_user_id = isset($input['recipient_user_id']) && !empty($input['recipient_user_id']) ? $input['recipient_user_id'] : null;
    $location_dept_id = isset($input['location_department_id']) && !empty($input['location_department_id']) ? $input['location_department_id'] : null;
    $reason = isset($input['reason']) ? $input['reason'] : null;
    $location_details = isset($input['location_details']) ? $input['location_details'] : null;
    
    $stmt->bindParam(':from_department_id', $from_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':to_department_id', $to_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':transfer_date', $input['transfer_date']);
    $stmt->bindParam(':reason', $reason);
    $stmt->bindParam(':transfer_user_id', $transfer_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':recipient_user_id', $recipient_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_department_id', $location_dept_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_details', $location_details);
    
    if ($stmt->execute()) {
        $transfer_id = $dbh->lastInsertId();

        // ✅ UPDATE ตารางหลัก equipments ด้วย location ล่าสุด
        $updateEquip = $dbh->prepare("UPDATE equipments 
                                      SET location_department_id = :location_department_id, 
                                          location_details = :location_details 
                                      WHERE equipment_id = :equipment_id");
        $updateEquip->bindParam(':location_department_id', $location_dept_id, PDO::PARAM_INT);
        $updateEquip->bindParam(':location_details', $location_details);
        $updateEquip->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
        $updateEquip->execute();

        echo json_encode([
            "status" => "ok", 
            "message" => "Equipment transfer created successfully and equipment updated",
            "transfer_id" => (int)$transfer_id
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to create equipment transfer"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
