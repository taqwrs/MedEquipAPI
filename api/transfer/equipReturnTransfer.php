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
    if (!isset($input['transfer_id']) || empty($input['transfer_id'])) {
        echo json_encode(["status" => "error", "message" => "Missing required field: transfer_id"]);
        exit;
    }
    
    // ค้นหา transfer record
    $getTransfer = $dbh->prepare("
        SELECT et.transfer_id, et.transfer_type, et.equipment_id, et.old_subcategory_id, et.new_subcategory_id,
               e.asset_code, e.active
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
    
    // ตรวจสอบว่า equipment ยัง active อยู่
    if ($transfer['active'] != 1) {
        echo json_encode(["status" => "error", "message" => "Equipment return only allowed for active equipment"]);
        exit;
    }
    
    // ตรวจสอบว่ามี old_subcategory_id และ new_subcategory_id
    if (!$transfer['old_subcategory_id'] || !$transfer['new_subcategory_id']) {
        echo json_encode(["status" => "error", "message" => "Invalid transfer data: missing subcategory information"]);
        exit;
    }

    // เริ่ม transaction
    $dbh->beginTransaction();
    
    // ค้นหา group_user ที่สร้างขึ้นสำหรับการโอนย้ายชั่วคราวนี้
    $tempGroupPattern = "ผู้ใช้งานโอนย้ายชั่วคราว(" . $transfer['asset_code'] . ")";
    $getTempGroup = $dbh->prepare("
        SELECT gu.group_user_id
        FROM group_user gu
        INNER JOIN relation_group rg ON gu.group_user_id = rg.group_user_id
        WHERE rg.subcategory_id = :new_subcategory_id 
        AND gu.group_name = :group_name 
        AND gu.type = 'ผู้ใช้งาน'
    ");
    $getTempGroup->bindParam(':new_subcategory_id', $transfer['new_subcategory_id'], PDO::PARAM_INT);
    $getTempGroup->bindParam(':group_name', $tempGroupPattern);
    $getTempGroup->execute();
    $tempGroups = $getTempGroup->fetchAll(PDO::FETCH_ASSOC);
    
    // 1. คืน equipment กลับไป old_subcategory_id
    $returnEquipment = $dbh->prepare("
        UPDATE equipments 
        SET subcategory_id = :old_subcategory_id 
        WHERE equipment_id = :equipment_id
    ");
    $returnEquipment->bindParam(':old_subcategory_id', $transfer['old_subcategory_id'], PDO::PARAM_INT);
    $returnEquipment->bindParam(':equipment_id', $transfer['equipment_id'], PDO::PARAM_INT);
    
    if (!$returnEquipment->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to return equipment to original subcategory"]);
        exit;
    }
    
    // 2. ลบ relation_user ของ group_user ที่สร้างขึ้นสำหรับการโอนย้ายชั่วคราว
    foreach ($tempGroups as $tempGroup) {
        $deleteRelationUser = $dbh->prepare("DELETE FROM relation_user WHERE group_user_id = :group_user_id");
        $deleteRelationUser->bindParam(':group_user_id', $tempGroup['group_user_id'], PDO::PARAM_INT);
        $deleteRelationUser->execute();
    }
    
    // 3. ลบ relation_group ทั้งหมดที่เกี่ยวข้องกับ new_subcategory_id
    $deleteRelationGroup = $dbh->prepare("DELETE FROM relation_group WHERE subcategory_id = :new_subcategory_id");
    $deleteRelationGroup->bindParam(':new_subcategory_id', $transfer['new_subcategory_id'], PDO::PARAM_INT);
    
    if (!$deleteRelationGroup->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to delete relation_group records"]);
        exit;
    }
    
    // 4. ลบ group_user ที่สร้างขึ้นสำหรับการโอนย้ายชั่วคราว
    foreach ($tempGroups as $tempGroup) {
        $deleteGroupUser = $dbh->prepare("DELETE FROM group_user WHERE group_user_id = :group_user_id");
        $deleteGroupUser->bindParam(':group_user_id', $tempGroup['group_user_id'], PDO::PARAM_INT);
        
        if (!$deleteGroupUser->execute()) {
            $dbh->rollBack();
            echo json_encode(["status" => "error", "message" => "Failed to delete temporary group_user"]);
            exit;
        }
    }
    
    // 5. ลบ subcategory ที่สร้างขึ้นสำหรับการโอนย้ายชั่วคราว
    $deleteSubcategory = $dbh->prepare("DELETE FROM equipment_subcategories WHERE subcategory_id = :new_subcategory_id");
    $deleteSubcategory->bindParam(':new_subcategory_id', $transfer['new_subcategory_id'], PDO::PARAM_INT);
    
    if (!$deleteSubcategory->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to delete temporary subcategory"]);
        exit;
    }
    
    // 6. อัปเดต equipment_transfers เพื่อทำเครื่องหมายว่าได้ทำการโอนคืนแล้ว
    // เพิ่มคอลัมน์ returned_date และ returned_by หากต้องการติดตาม
    $updateTransfer = $dbh->prepare("
        UPDATE equipment_transfers 
        SET reason = CONCAT(IFNULL(reason, ''), ' [RETURNED: ', NOW(), ']')
        WHERE transfer_id = :transfer_id
    ");
    $updateTransfer->bindParam(':transfer_id', $input['transfer_id'], PDO::PARAM_INT);
    $updateTransfer->execute();
    
    // รีเซ็ต location กลับเป็นค่าเดิม (ถ้าต้องการ)
    if (isset($input['reset_location']) && $input['reset_location'] === true) {
        $resetLocation = $dbh->prepare("
            UPDATE equipments 
            SET location_department_id = NULL, location_details = NULL
            WHERE equipment_id = :equipment_id
        ");
        $resetLocation->bindParam(':equipment_id', $transfer['equipment_id'], PDO::PARAM_INT);
        $resetLocation->execute();
    }

    $dbh->commit();
    
    echo json_encode([
        "status" => "ok", 
        "message" => "Equipment returned successfully",
        "transfer_id" => (int)$input['transfer_id'],
        "equipment_id" => (int)$transfer['equipment_id'],
        "returned_to_subcategory_id" => (int)$transfer['old_subcategory_id'],
        "deleted_subcategory_id" => (int)$transfer['new_subcategory_id'],
        "deleted_temp_groups" => count($tempGroups),
        "asset_code" => $transfer['asset_code']
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>