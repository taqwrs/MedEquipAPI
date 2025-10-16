<?php
include "../config/jwt.php";
include "../config/LogModel.php";
//การโอนคืน เฉพาะการโอนย้ายประเภท ชั่วคราว 

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
    if (!isset($input['transfer_id']) || empty($input['transfer_id'])) {
        echo json_encode(["status" => "error", "message" => "Missing required field: transfer_id"]);
        exit;
    }

    // ค้นหา transfer record พร้อมข้อมูลที่เกี่ยวข้อง
    $getTransfer = $dbh->prepare("
        SELECT et.transfer_id, et.transfer_type, et.equipment_id, et.old_subcategory_id, et.new_subcategory_id,
               et.from_department_id, et.to_department_id, et.transfer_date, et.reason, 
               et.transfer_user_id, et.recipient_user_id, et.location_department_id, et.location_details,
               et.old_location_department_id, et.status,
               e.asset_code, e.active, e.subcategory_id as current_subcategory_id
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

    // ตรวจสอบว่าเป็นการโอนย้ายชั่วคราวเท่านั้น (ข้อ 2.7)
    if ($transfer['transfer_type'] !== 'โอนย้ายชั่วคราว') {
        echo json_encode(["status" => "error", "message" => "Return is only allowed for temporary transfers (โอนย้ายชั่วคราว)"]);
        exit;
    }

    // ตรวจสอบว่า equipment ยัง active อยู่
    if ($transfer['active'] != 1) {
        echo json_encode(["status" => "error", "message" => "Equipment return only allowed for active equipment"]);
        exit;
    }

    // ตรวจสอบว่ายังไม่ได้โอนคืน (status = 0)
    if ($transfer['status'] == 1) {
        echo json_encode(["status" => "error", "message" => "Equipment has already been returned"]);
        exit;
    }

    // ตรวจสอบว่ามี old_subcategory_id และ new_subcategory_id
    if (!$transfer['old_subcategory_id'] || !$transfer['new_subcategory_id']) {
        echo json_encode(["status" => "error", "message" => "Invalid transfer data: missing subcategory information"]);
        exit;
    }

    // ข้อ 2.7.7 & 2.7.11: ดึงข้อมูลจาก history_transfer ที่ status_transfer = 0
    $getHistory = $dbh->prepare("
        SELECT trans_location_department_id, trans_location_details, 
               old_location_department_id, old_equip_location_details
        FROM history_transfer 
        WHERE transfer_id = :transfer_id AND status_transfer = 0
        ORDER BY history_transfer_id DESC
        LIMIT 1
    ");
    $getHistory->bindParam(':transfer_id', $input['transfer_id'], PDO::PARAM_INT);
    $getHistory->execute();
    $historyData = $getHistory->fetch(PDO::FETCH_ASSOC);

    // เริ่ม transaction
    $dbh->beginTransaction();

    // ข้อ 2.7.1: คืน equipment กลับไป old_subcategory_id
    $returnEquipment = $dbh->prepare("
        UPDATE equipments 
        SET subcategory_id = :old_subcategory_id 
        WHERE equipment_id = :equipment_id
    ");
    $returnEquipment->bindParam(':old_subcategory_id', $transfer['old_subcategory_id'], PDO::PARAM_INT);
    $returnEquipment->bindParam(':equipment_id', $transfer['equipment_id'], PDO::PARAM_INT);

    // LogModel: ก่อน execute
    $oldData = ['subcategory_id' => $transfer['current_subcategory_id']];
    $newData = ['subcategory_id' => $transfer['old_subcategory_id']];
    $log->insertLog($transfer['recipient_user_id'], 'equipments', 'UPDATE', $oldData, $newData);

    if (!$returnEquipment->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to return equipment to original subcategory"]);
        exit;
    }

    // ค้นหา group_user ที่สร้างขึ้นสำหรับการโอนย้ายชั่วคราวนี้ (ข้อ 2.7.2)
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

    // ข้อ 2.7.4: ลบข้อมูลใน relation_user และ relation_group
    foreach ($tempGroups as $tempGroup) {
        // LogModel: ก่อนลบ relation_user
        $oldRelationUser = $dbh->query("SELECT * FROM relation_user WHERE group_user_id = {$tempGroup['group_user_id']}")->fetchAll(PDO::FETCH_ASSOC);
        $log->insertLog($transfer['recipient_user_id'], 'relation_user', 'DELETE', $oldRelationUser, null);

        // ลบ relation_user
        $deleteRelationUser = $dbh->prepare("DELETE FROM relation_user WHERE group_user_id = :group_user_id");
        $deleteRelationUser->bindParam(':group_user_id', $tempGroup['group_user_id'], PDO::PARAM_INT);
        $deleteRelationUser->execute();
    }

    // LogModel: ก่อนลบ relation_group
    $oldRelationGroup = $dbh->query("SELECT * FROM relation_group WHERE subcategory_id = {$transfer['new_subcategory_id']}")->fetchAll(PDO::FETCH_ASSOC);
    $log->insertLog($transfer['recipient_user_id'], 'relation_group', 'DELETE', $oldRelationGroup, null);

    // ลบ relation_group ที่เกี่ยวข้องกับ new_subcategory_id
    $deleteRelationGroup = $dbh->prepare("DELETE FROM relation_group WHERE subcategory_id = :new_subcategory_id");
    $deleteRelationGroup->bindParam(':new_subcategory_id', $transfer['new_subcategory_id'], PDO::PARAM_INT);

    if (!$deleteRelationGroup->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to delete relation_group records"]);
        exit;
    }

    // ข้อ 2.7.2: ลบ group_user ที่สร้างขึ้นสำหรับการโอนย้ายชั่วคราว
    foreach ($tempGroups as $tempGroup) {
        // LogModel: ก่อนลบ group_user
        $oldGroupUser = $dbh->query("SELECT * FROM group_user WHERE group_user_id = {$tempGroup['group_user_id']}")->fetch(PDO::FETCH_ASSOC);
        $log->insertLog($transfer['recipient_user_id'], 'group_user', 'DELETE', $oldGroupUser, null);

        $deleteGroupUser = $dbh->prepare("DELETE FROM group_user WHERE group_user_id = :group_user_id");
        $deleteGroupUser->bindParam(':group_user_id', $tempGroup['group_user_id'], PDO::PARAM_INT);

        if (!$deleteGroupUser->execute()) {
            $dbh->rollBack();
            echo json_encode(["status" => "error", "message" => "Failed to delete temporary group_user"]);
            exit;
        }
    }

    // LogModel: ก่อนลบ equipment_subcategories
    $oldSubcategory = $dbh->query("SELECT * FROM equipment_subcategories WHERE subcategory_id = {$transfer['new_subcategory_id']}")->fetch(PDO::FETCH_ASSOC);
    $log->insertLog($transfer['recipient_user_id'], 'equipment_subcategories', 'DELETE', $oldSubcategory, null);

    // ข้อ 2.7.3: ลบ subcategory ที่สร้างขึ้นสำหรับการโอนย้ายชั่วคราว
    $deleteSubcategory = $dbh->prepare("DELETE FROM equipment_subcategories WHERE subcategory_id = :new_subcategory_id");
    $deleteSubcategory->bindParam(':new_subcategory_id', $transfer['new_subcategory_id'], PDO::PARAM_INT);

    if (!$deleteSubcategory->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to delete temporary subcategory"]);
        exit;
    }

    // ข้อ2.7.5 - 2.7.7: อัปเดต location จาก old_location_department_id และ old_equip_location_details ใน history_transfer
    $updateTransferSQL = "
        UPDATE equipment_transfers 
        SET status = 1,
            returned_date = NOW(),
            now_subcategory_id = :old_subcategory_id";

    // แก้ไข: ใช้ old_location_department_id และ old_equip_location_details จาก history_transfer
    if ($historyData && $historyData['old_location_department_id']) {
        $updateTransferSQL .= ",
            location_department_id = :old_location_department_id,
            location_details = :old_equip_location_details";
    }

    $updateTransferSQL .= " WHERE transfer_id = :transfer_id";

    $updateTransfer = $dbh->prepare($updateTransferSQL);
    $updateTransfer->bindParam(':old_subcategory_id', $transfer['old_subcategory_id'], PDO::PARAM_INT);
    $updateTransfer->bindParam(':transfer_id', $input['transfer_id'], PDO::PARAM_INT);

    if ($historyData && $historyData['old_location_department_id']) {
        $updateTransfer->bindParam(':old_location_department_id', $historyData['old_location_department_id'], PDO::PARAM_INT);
        $updateTransfer->bindParam(':old_equip_location_details', $historyData['old_equip_location_details']);
    }

    if (!$updateTransfer->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to update transfer status"]);
        exit;
    }

    // ดึง returned_date และข้อมูลที่อัปเดตแล้วจากการทำข้อ 2.7.7
    $getUpdatedTransfer = $dbh->prepare("
        SELECT returned_date, location_department_id, location_details, now_subcategory_id 
        FROM equipment_transfers 
        WHERE transfer_id = :transfer_id
    ");
    $getUpdatedTransfer->bindParam(':transfer_id', $input['transfer_id'], PDO::PARAM_INT);
    $getUpdatedTransfer->execute();
    $updatedTransfer = $getUpdatedTransfer->fetch(PDO::FETCH_ASSOC);

    // ข้อ 2.7.8: อัปเดต location_details กับ location_department_id ใน equipment table
    // ใช้ข้อมูลจาก location_department_id และ location_details ใน equipment_transfers ที่อัปเดตแล้วในข้อ 2.7.7
    if ($historyData && $historyData['old_location_department_id']) {
        $updateEquipLocation = $dbh->prepare("
            UPDATE equipments 
            SET location_department_id = :location_department_id,
                location_details = :location_details
            WHERE equipment_id = :equipment_id
        ");
        $updateEquipLocation->bindParam(':location_department_id', $updatedTransfer['location_department_id'], PDO::PARAM_INT);
        $updateEquipLocation->bindParam(':location_details', $updatedTransfer['location_details']);
        $updateEquipLocation->bindParam(':equipment_id', $transfer['equipment_id'], PDO::PARAM_INT);

        if (!$updateEquipLocation->execute()) {
            $dbh->rollBack();
            echo json_encode(["status" => "error", "message" => "Failed to update equipment location"]);
            exit;
        }
    }

    // ข้อ 2.7.9, 2.7.10: อัปเดต updated_by และ updated_at ใน equipment table
    $updateEquipment = $dbh->prepare("
        UPDATE equipments 
        SET updated_by = :recipient_user_id,
            updated_at = :returned_date
        WHERE equipment_id = :equipment_id
    ");

    // LogModel: ก่อน execute
    $oldData = $dbh->query("SELECT updated_by, updated_at FROM equipments WHERE equipment_id = {$transfer['equipment_id']}")->fetch(PDO::FETCH_ASSOC);
    $newData = ['updated_by' => $transfer['recipient_user_id'], 'updated_at' => $updatedTransfer['returned_date']];
    $log->insertLog($transfer['recipient_user_id'], 'equipments', 'UPDATE', $oldData, $newData);

    $updateEquipment->bindParam(':recipient_user_id', $transfer['recipient_user_id'], PDO::PARAM_INT);
    $updateEquipment->bindParam(':returned_date', $updatedTransfer['returned_date']);
    $updateEquipment->bindParam(':equipment_id', $transfer['equipment_id'], PDO::PARAM_INT);

    if (!$updateEquipment->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to update equipment updated_by and updated_at"]);
        exit;
    }

    // ข้อ 2.7.11: Insert ข้อมูลใหม่ลง history_transfer
    $insertHistory = $dbh->prepare("
        INSERT INTO history_transfer (
            transfer_id, transfer_type, equipment_id, from_department_id, to_department_id,
            transfer_date, reason, transfer_user_id, recipient_user_id, 
            trans_location_department_id, trans_location_details,
            old_subcategory_id, new_subcategory_id, 
            now_equip_location_department_id, now_equip_location_details,
            returned_date, old_location_department_id, now_subcategory_id,
            old_equip_location_details, status_transfer, updated_at
        ) VALUES (
            :transfer_id, :transfer_type, :equipment_id, :from_department_id, :to_department_id,
            :transfer_date, :reason, :transfer_user_id, :recipient_user_id,
            :trans_location_department_id, :trans_location_details,
            :old_subcategory_id, :new_subcategory_id,
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
    $insertHistory->bindParam(':recipient_user_id', $transfer['recipient_user_id'], PDO::PARAM_INT);

    // ข้อ 2.7.11: ดึงข้อมูลจาก history_transfer ที่ transfer_id เดียวกันและ status_transfer = 0
    $transLocDeptId = $historyData ? $historyData['trans_location_department_id'] : null;
    $transLocDetails = $historyData ? $historyData['trans_location_details'] : null;
    $oldEquipLocDetails = $historyData ? $historyData['old_equip_location_details'] : null;

    $insertHistory->bindParam(':trans_location_department_id', $transLocDeptId, PDO::PARAM_INT);
    $insertHistory->bindParam(':trans_location_details', $transLocDetails);
    $insertHistory->bindParam(':old_subcategory_id', $transfer['old_subcategory_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':new_subcategory_id', $transfer['new_subcategory_id'], PDO::PARAM_INT);

    // ข้อ 2.7.11: now_equip_location_department_id และ now_equip_location_details ดึงจากข้อ 2.7.7 เมื่อทำเสร็จ
    $insertHistory->bindParam(':now_equip_location_department_id', $updatedTransfer['location_department_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':now_equip_location_details', $updatedTransfer['location_details']);

    $insertHistory->bindParam(':returned_date', $updatedTransfer['returned_date']);
    $insertHistory->bindParam(':old_location_department_id', $transfer['old_location_department_id'], PDO::PARAM_INT);
    $insertHistory->bindParam(':now_subcategory_id', $updatedTransfer['now_subcategory_id'], PDO::PARAM_INT);

    // ข้อ 2.7.11: old_equip_location_details ดึงจาก history_transfer ที่ transfer_id เดียวกันและ status_transfer = 0
    $insertHistory->bindParam(':old_equip_location_details', $oldEquipLocDetails);
    $insertHistory->bindValue(':status_transfer', 1, PDO::PARAM_INT); // status = 1 เพราะเป็นการโอนคืน

    if (!$insertHistory->execute()) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "Failed to insert history record"]);
        exit;
    }

    $dbh->commit();

    echo json_encode([
        "status" => "ok",
        "message" => "Equipment returned successfully",
        "transfer_id" => (int) $input['transfer_id'],
        "equipment_id" => (int) $transfer['equipment_id'],
        "returned_to_subcategory_id" => (int) $transfer['old_subcategory_id'],
        "deleted_subcategory_id" => (int) $transfer['new_subcategory_id'],
        "deleted_temp_groups" => count($tempGroups),
        "asset_code" => $transfer['asset_code'],
        "returned_date" => $updatedTransfer['returned_date'],
        "updated_location_department_id" => $updatedTransfer['location_department_id'],
        "updated_location_details" => $updatedTransfer['location_details'],
        "transfer_status" => 1, // โอนคืนแล้ว
        "now_subcategory_id" => (int) $updatedTransfer['now_subcategory_id']
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>