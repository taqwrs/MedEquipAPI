<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$log = new LogModel($dbh);

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
        
    // ตรวจสอบ required fields
    $required = ['transfer_type', 'equipment_id', 'to_department_id', 'transfer_user_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit;
        }
    }

    // ตรวจสอบว่า equipment_id มีอยู่และ status = 1
    $checkEquipment = $dbh->prepare("
        SELECT e.equipment_id, e.asset_code, e.subcategory_id, e.status,
               e.location_department_id as old_location_department_id,
               e.location_details as old_location_details
        FROM equipments e 
        WHERE e.equipment_id = :equipment_id
    ");
    $checkEquipment->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $checkEquipment->execute();
    
    $equipment = $checkEquipment->fetch(PDO::FETCH_ASSOC);
    
    if (!$equipment) {
        echo json_encode(["status" => "error", "message" => "Equipment not found"]);
        exit;
    }
    

    
    // เก็บข้อมูลเดิมของ equipment ก่อนการโอนย้าย
    $old_location_department_id = $equipment['old_location_department_id'];
    $old_equip_location_details = $equipment['old_location_details'];
    $now_subcategory_id = $equipment['subcategory_id'];
    
    // ตรวจสอบ to_department_id ว่ามีอยู่จริง
    $checkDept = $dbh->prepare("SELECT department_id FROM departments WHERE department_id = :dept_id");
    $checkDept->bindParam(':dept_id', $input['to_department_id'], PDO::PARAM_INT);
    $checkDept->execute();
    
    if ($checkDept->rowCount() == 0) {
        echo json_encode(["status" => "error", "message" => "to_department_id not found"]);
        exit;
    }
    
    // ตรวจสอบ transfer_user_id
    $checkUser = $dbh->prepare("SELECT ID FROM users WHERE ID = :user_id");
    $checkUser->bindParam(':user_id', $input['transfer_user_id'], PDO::PARAM_INT);
    $checkUser->execute();
    
    if ($checkUser->rowCount() == 0) {
        echo json_encode(["status" => "error", "message" => "transfer_user_id not found"]);
        exit;
    }

    // เริ่ม transaction
    $dbh->beginTransaction();
    
    // เตรียมค่าตัวแปร
    $from_department_id = $old_location_department_id; // หน่วยต้นทาง = location_department_id เดิม
    $to_department_id = $input['to_department_id']; // หน่วยปลายทาง
    $transfer_user_id = $input['transfer_user_id'];
    $location_details = isset($input['location_details']) ? $input['location_details'] : null;
    $reason = isset($input['reason']) ? $input['reason'] : null;
    
    // กำหนดสถานะการโอนตาม transfer_type
    // 0 = ยังไม่คืน (โอนย้ายชั่วคราว), 1 = คืนแล้ว/ไม่ต้องคืน (โอนย้ายถาวร)
    // $status = ($input['transfer_type'] === 'โอนย้ายชั่วคราว') ? 0 : 1;

    // 0 = ยังไม่คืน (โอนย้ายชั่วคราว), 2 = โอนย้ายถาวร
    if ($input['transfer_type'] === 'โอนย้ายชั่วคราว') {
        $status = 0;
    } elseif ($input['transfer_type'] === 'โอนย้ายถาวร') {
        $status = 2;

            $updateDepJoin = $dbh->prepare("
            UPDATE equipments 
            SET dep_join = :dep_join,
                updated_by = :updated_by,
                updated_at = :updated_at
            WHERE equipment_id = :equipment_id
        ");
        $updateDepJoin->bindParam(':dep_join', $to_department_id, PDO::PARAM_INT);
        $updateDepJoin->bindParam(':updated_by', $transfer_user_id, PDO::PARAM_INT);
        $updateDepJoin->bindParam(':updated_at', $now);
        $updateDepJoin->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
        $updateDepJoin->execute();
    }

    // อัปเดต location_department_id และ location_details ในตาราง equipments
    $updateEquipLocation = $dbh->prepare("
        UPDATE equipments 
        SET location_department_id = :location_department_id, 
            location_details = :location_details,
            updated_by = :updated_by,
            updated_at = :updated_at
        WHERE equipment_id = :equipment_id
    ");
    $updateEquipLocation->bindParam(':location_department_id', $to_department_id, PDO::PARAM_INT);
    $updateEquipLocation->bindParam(':location_details', $location_details);
    $updateEquipLocation->bindParam(':updated_by', $transfer_user_id, PDO::PARAM_INT);
    $updateEquipLocation->bindParam(':updated_at', $now); 
    $updateEquipLocation->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $updateEquipLocation->execute();

    // สร้าง equipment transfer record
    $sql = "INSERT INTO equipment_transfers (
        transfer_type, equipment_id, from_department_id, to_department_id, 
        transfer_date, reason, transfer_user_id, location_department_id, 
        location_details, old_equip_location_details, status, 
        old_location_department_id, now_subcategory_id
    ) VALUES (
        :transfer_type, :equipment_id, :from_department_id, :to_department_id, 
        :transfer_date, :reason, :transfer_user_id, :location_department_id, 
        :location_details, :old_equip_location_details, :status, 
        :old_location_department_id, :now_subcategory_id
    )";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':transfer_type', $input['transfer_type']);
    $stmt->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $stmt->bindParam(':from_department_id', $from_department_id, PDO::PARAM_INT);
    $stmt->bindParam(':to_department_id', $to_department_id, PDO::PARAM_INT);
    $stmt->bindParam(':transfer_date', $transfer_date);
    $stmt->bindParam(':reason', $reason);
    $stmt->bindParam(':transfer_user_id', $transfer_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_department_id', $to_department_id, PDO::PARAM_INT);
    $stmt->bindParam(':location_details', $location_details);
    $stmt->bindParam(':old_equip_location_details', $old_equip_location_details);
    $stmt->bindParam(':status', $status, PDO::PARAM_INT);
    $stmt->bindParam(':old_location_department_id', $old_location_department_id, PDO::PARAM_INT);
    $stmt->bindParam(':now_subcategory_id', $now_subcategory_id, PDO::PARAM_INT);
    
    if (!$stmt->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to create equipment transfer"]);
        exit;
    }
    
    $transfer_id = $dbh->lastInsertId();

    // Log การ INSERT ลงตาราง equipment_transfers
    $log->insertLog(
        $transfer_user_id,
        'equipment_transfers',
        'INSERT',
        [
            'old_location_department_id' => $old_location_department_id,
            'old_equip_location_details' => $old_equip_location_details,
            'subcategory_id' => $now_subcategory_id
        ],
        [
            'transfer_id' => $transfer_id,
            'equipment_id' => $input['equipment_id'],
            'transfer_type' => $input['transfer_type'],
            'from_department_id' => $from_department_id,
            'to_department_id' => $to_department_id,
            'transfer_date' => $transfer_date,
            'reason' => $reason,
            'location_department_id' => $to_department_id,
            'location_details' => $location_details,
            'status' => $status
        ]
    );

    // บันทึกลง history_transfer
    $historySQL = "INSERT INTO history_transfer (
        transfer_id, transfer_type, equipment_id, from_department_id, to_department_id, 
        transfer_date, reason, transfer_user_id, 
        trans_location_department_id, trans_location_details,
        now_equip_location_department_id, now_equip_location_details,
        old_location_department_id, old_equip_location_details, 
        now_subcategory_id, status_transfer, updated_at
    ) VALUES (
        :transfer_id, :transfer_type, :equipment_id, :from_department_id, :to_department_id,
        :transfer_date, :reason, :transfer_user_id,
        :trans_location_department_id, :trans_location_details,
        :now_equip_location_department_id, :now_equip_location_details,
        :old_location_department_id, :old_equip_location_details, 
        :now_subcategory_id, :status_transfer, NOW()
    )";
    
    $historyStmt = $dbh->prepare($historySQL);
    $historyStmt->bindParam(':transfer_id', $transfer_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':transfer_type', $input['transfer_type']);
    $historyStmt->bindParam(':equipment_id', $input['equipment_id'], PDO::PARAM_INT);
    $historyStmt->bindParam(':from_department_id', $from_department_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':to_department_id', $to_department_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':transfer_date', $transfer_date);
    $historyStmt->bindParam(':reason', $reason);
    $historyStmt->bindParam(':transfer_user_id', $transfer_user_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':trans_location_department_id', $to_department_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':trans_location_details', $location_details);
    $historyStmt->bindParam(':now_equip_location_department_id', $to_department_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':now_equip_location_details', $location_details);
    $historyStmt->bindParam(':old_location_department_id', $old_location_department_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':old_equip_location_details', $old_equip_location_details);
    $historyStmt->bindParam(':now_subcategory_id', $now_subcategory_id, PDO::PARAM_INT);
    $historyStmt->bindParam(':status_transfer', $status, PDO::PARAM_INT);
    
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
        "from_department_id" => (int)$from_department_id,
        "to_department_id" => (int)$to_department_id,
        "status" => $status,
        "now_subcategory_id" => (int)$now_subcategory_id,
        "old_location_department_id" => (int)$old_location_department_id,
        "old_equip_location_details" => $old_equip_location_details,
        "now_location_department_id" => (int)$to_department_id,
        "now_location_details" => $location_details,
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