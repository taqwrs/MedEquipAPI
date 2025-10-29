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

    // ตรวจสอบข้อมูลที่จำเป็น
    $required = ['transfer_id', 'recipient_user_id'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || empty($input[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing required field: $field"]);
            exit;
        }
    }

    // ตรวจสอบว่า recipient_user_id มีอยู่จริง
    $checkUser = $dbh->prepare("SELECT ID FROM users WHERE ID = :user_id");
    $checkUser->bindParam(':user_id', $input['recipient_user_id'], PDO::PARAM_INT);
    $checkUser->execute();
    
    if ($checkUser->rowCount() == 0) {
        echo json_encode(["status" => "error", "message" => "recipient_user_id not found"]);
        exit;
    }

    // ค้นหา transfer record
    $getTransfer = $dbh->prepare("
        SELECT et.transfer_id, et.transfer_type, et.equipment_id, et.status,
               et.from_department_id, et.to_department_id, et.transfer_date, 
               et.reason, et.transfer_user_id, et.location_department_id, 
               et.location_details, et.old_equip_location_details,
               et.old_location_department_id, et.now_subcategory_id,
               e.asset_code
        FROM equipment_transfers et
        LEFT JOIN equipments e ON et.equipment_id = e.equipment_id
        WHERE et.transfer_id = :transfer_id
    ");
    $getTransfer->bindParam(':transfer_id', $input['transfer_id'], PDO::PARAM_INT);
    $getTransfer->execute();

    $transfer = $getTransfer->fetch(PDO::FETCH_ASSOC);

    if (!$transfer) {
        echo json_encode(["status" => "error", "message" => "Transfer record not found"]);
        exit;
    }

    // ตรวจสอบว่าเป็นการโอนย้ายชั่วคราวเท่านั้น
    if ($transfer['transfer_type'] !== 'โอนย้ายชั่วคราว') {
        echo json_encode(["status" => "error", "message" => "Return is only allowed for temporary transfers (โอนย้ายชั่วคราว)"]);
        exit;
    }

    // ตรวจสอบว่ายังไม่ได้โอนคืน (status = 0)
    if ($transfer['status'] == 1) {
        echo json_encode(["status" => "error", "message" => "Equipment has already been returned"]);
        exit;
    }

    $now = date('Y-m-d H:i:s');

    // เริ่ม transaction
    $dbh->beginTransaction();

    // อัปเดต equipment_transfers: เปลี่ยน status = 1, บันทึก returned_date และ recipient_user_id
    $updateTransfer = $dbh->prepare("
        UPDATE equipment_transfers 
        SET status = 1,
            returned_date = :returned_date,
            recipient_user_id = :recipient_user_id
        WHERE transfer_id = :transfer_id
    ");
    $updateTransfer->bindParam(':returned_date', $now);
    $updateTransfer->bindParam(':recipient_user_id', $input['recipient_user_id'], PDO::PARAM_INT);
    $updateTransfer->bindParam(':transfer_id', $input['transfer_id'], PDO::PARAM_INT);

    if (!$updateTransfer->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to update transfer status"]);
        exit;
    }

    // Log การ UPDATE ลงตาราง equipment_transfers
    $log->insertLog(
        $input['recipient_user_id'],
        'equipment_transfers',
        'UPDATE',
        [
            'status' => 0,
            'returned_date' => null
        ],
        [
            'status' => 1,
            'returned_date' => $now,
            'recipient_user_id' => $input['recipient_user_id']
        ]
    );

    // คืนค่า location กลับไปที่เดิม (from_department_id และ old_equip_location_details)
    $updateEquipLocation = $dbh->prepare("
        UPDATE equipments 
        SET location_department_id = :location_department_id,
            location_details = :location_details,
            updated_by = :updated_by,
            updated_at = :updated_at
        WHERE equipment_id = :equipment_id
    ");
    $updateEquipLocation->bindParam(':location_department_id', $transfer['from_department_id'], PDO::PARAM_INT);
    $updateEquipLocation->bindParam(':location_details', $transfer['old_equip_location_details']);
    $updateEquipLocation->bindParam(':updated_by', $input['recipient_user_id'], PDO::PARAM_INT);
    $updateEquipLocation->bindParam(':updated_at', $now);
    $updateEquipLocation->bindParam(':equipment_id', $transfer['equipment_id'], PDO::PARAM_INT);

    if (!$updateEquipLocation->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to update equipment location"]);
        exit;
    }

    // Log การ UPDATE ลงตาราง equipments
    $log->insertLog(
        $input['recipient_user_id'],
        'equipments',
        'UPDATE',
        [
            'location_department_id' => $transfer['location_department_id'],
            'location_details' => $transfer['location_details']
        ],
        [
            'location_department_id' => $transfer['from_department_id'],
            'location_details' => $transfer['old_equip_location_details'],
            'updated_by' => $input['recipient_user_id'],
            'updated_at' => $now
        ]
    );

    // บันทึกลง history_transfer
    $insertHistory = $dbh->prepare("
        INSERT INTO history_transfer (
            transfer_id, transfer_type, equipment_id, from_department_id, to_department_id,
            transfer_date, reason, transfer_user_id, recipient_user_id,
            trans_location_department_id, trans_location_details,
            now_equip_location_department_id, now_equip_location_details,
            returned_date, old_location_department_id, now_subcategory_id,
            old_equip_location_details, status_transfer, updated_at
        ) VALUES (
            :transfer_id, :transfer_type, :equipment_id, :from_department_id, :to_department_id,
            :transfer_date, :reason, :transfer_user_id, :recipient_user_id,
            :trans_location_department_id, :trans_location_details,
            :now_equip_location_department_id, :now_equip_location_details,
            :returned_date, :old_location_department_id, :now_subcategory_id,
            :old_equip_location_details, :status_transfer, NOW()
        )
    ");

    $insertHistory->bindParam(':transfer_id', $transfer['transfer_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':transfer_type', $transfer['transfer_type']);
    $insertHistory->bindParam(':equipment_id', $transfer['equipment_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':from_department_id', $transfer['from_department_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':to_department_id', $transfer['to_department_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':transfer_date', $transfer['transfer_date']);
    $insertHistory->bindParam(':reason', $transfer['reason']);
    $insertHistory->bindParam(':transfer_user_id', $transfer['transfer_user_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':recipient_user_id', $input['recipient_user_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':trans_location_department_id', $transfer['location_department_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':trans_location_details', $transfer['location_details']);
    $insertHistory->bindParam(':now_equip_location_department_id', $transfer['from_department_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':now_equip_location_details', $transfer['old_equip_location_details']);
    $insertHistory->bindParam(':returned_date', $now);
    $insertHistory->bindParam(':old_location_department_id', $transfer['old_location_department_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':now_subcategory_id', $transfer['now_subcategory_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':old_equip_location_details', $transfer['old_equip_location_details']);
    $insertHistory->bindValue(':status_transfer', 1, PDO::PARAM_INT);

    if (!$insertHistory->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to insert history record"]);
        exit;
    }

    $dbh->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Equipment returned successfully",
        "transfer_id" => (int)$input['transfer_id'],
        "equipment_id" => (int)$transfer['equipment_id'],
        "asset_code" => $transfer['asset_code'],
        "returned_date" => $now,
        "returned_by" => (int)$input['recipient_user_id'],
        "returned_to_department_id" => (int)$transfer['from_department_id'],
        "returned_to_location_details" => $transfer['old_equip_location_details'],
        "transfer_status" => 1,
        "now_subcategory_id" => (int)$transfer['now_subcategory_id']
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>