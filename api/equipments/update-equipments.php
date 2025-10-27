<?php
include "../config/jwt.php";
include "../config/LogModel.php";

// ==================== Helper Functions ====================

/**
 * คำนวณระยะเวลา Warranty (จำนวนวัน)
 */
function calculateWarrantyDays($startDate, $endDate) {
    if (empty($startDate) || empty($endDate)) {
        return null;
    }

    $start = new DateTime($startDate);
    $end = new DateTime($endDate);

    if ($end < $start) {
        throw new Exception("วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น");
    }

    return $start->diff($end)->days;
}

/**
 * ตรวจสอบ Asset Code ซ้ำ
 */
function validateAssetCode($dbh, $assetCode, $equipmentId, $currentAssetCode) {
    if (empty($assetCode) || $assetCode === $currentAssetCode) {
        return true;
    }

    $stmt = $dbh->prepare(
        "SELECT COUNT(*) as cnt FROM equipments 
         WHERE asset_code = :asset AND equipment_id != :id"
    );
    $stmt->execute([':asset' => $assetCode, ':id' => $equipmentId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['cnt'] > 0) {
        throw new Exception("รหัสทรัพย์สินนี้มีอยู่แล้ว: " . $assetCode);
    }

    return true;
}

/**
 * ดึงข้อมูลอุปกรณ์เดิม
 */
function getOldEquipmentData($dbh, $equipmentId) {
    $stmt = $dbh->prepare("SELECT * FROM equipments WHERE equipment_id = :id");
    $stmt->execute([':id' => $equipmentId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * ดึง ID ของอุปกรณ์ย่อยที่ผูกกับอุปกรณ์หลัก
 */
function getChildEquipments($dbh, $mainEquipmentId) {
    $stmt = $dbh->prepare(
        "SELECT equipment_id FROM equipments WHERE main_equipment_id = :main_id"
    );
    $stmt->execute([':main_id' => $mainEquipmentId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

/**
 * ดึง ID ของอะไหล่ที่ผูกกับอุปกรณ์
 */
function getSpareParts($dbh, $equipmentId) {
    $stmt = $dbh->prepare(
        "SELECT spare_part_id FROM spare_parts WHERE equipment_id = :main_id"
    );
    $stmt->execute([':main_id' => $equipmentId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
}

/**
 * สร้าง SET clause และ params สำหรับ UPDATE
 */
function buildUpdateParams($fields, $postData, $oldEquipment, $equipmentId) {
    $setParts = [];
    $params = [':equipment_id' => $equipmentId];
    $oldData = ['equipment_id' => $equipmentId];
    $newData = ['equipment_id' => $equipmentId];

    foreach ($fields as $field) {
        $newValue = array_key_exists($field, $postData) ? $postData[$field] : null;

        // เปลี่ยนสถานะจาก draft เป็น complete
        if ($field === 'record_status' && $oldEquipment['record_status'] === 'draft') {
            $newValue = 'complete';
        }

        $setParts[] = "$field = :$field";
        $params[":$field"] = $newValue;

        $oldValue = $oldEquipment[$field] ?? null;
        if ($oldValue != $newValue) {
            $oldData[$field] = $oldValue;
            $newData[$field] = $newValue;
        }
    }

    return [$setParts, $params, $oldData, $newData];
}

/**
 * อัปเดตข้อมูลหลักของอุปกรณ์
 */
function updateMainEquipment($dbh, $equipmentId, $setParts, $params, $updatedBy) {
    if (empty($setParts)) {
        return;
    }

    $setParts[] = "updated_by = :updated_by";
    $setParts[] = "updated_at = NOW()";
    $params[':updated_by'] = $updatedBy;

    $sql = "UPDATE equipments SET " . implode(', ', $setParts) .
        " WHERE equipment_id = :equipment_id";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
}

/**
 * จัดการการผูกอุปกรณ์ย่อย
 */
function manageChildEquipments($dbh, $log, $equipmentId, $oldChilds, $newChilds, $updatedBy, $userId) {
    $newChilds = is_array($newChilds) ? $newChilds : [];

    $removedChilds = array_diff($oldChilds, $newChilds);
    $addedChilds = array_diff($newChilds, $oldChilds);

    // ลบความสัมพันธ์เก่า
    foreach ($removedChilds as $childId) {
        $stmt = $dbh->prepare(
            "UPDATE equipments SET main_equipment_id = NULL, 
             updated_by = :updated_by, updated_at = NOW() 
             WHERE equipment_id = :cid"
        );
        $stmt->execute([':updated_by' => $updatedBy, ':cid' => $childId]);

        $log->insertLog($userId, "equipments", "UPDATE", 
            ["equipment_id" => $childId, "main_equipments" => $equipmentId],
            ["equipment_id" => $childId, "main_equipments" => null]
        );
    }

    // เพิ่มความสัมพันธ์ใหม่
    foreach ($addedChilds as $childId) {
        if ($childId == $equipmentId) {
            throw new Exception("อุปกรณ์หลักไม่สามารถเป็นอุปกรณ์ย่อยของตัวเองได้");
        }

        // ดึงค่า main_equipment_id เก่าของ child ก่อน update
        $stmtOld = $dbh->prepare("SELECT main_equipment_id FROM equipments WHERE equipment_id = :cid");
        $stmtOld->execute([':cid' => $childId]);
        $oldParent = $stmtOld->fetchColumn(); // ใช้สำหรับ log

        // update main_equipment_id
        $stmt = $dbh->prepare(
            "UPDATE equipments SET main_equipment_id = :main_id, 
         updated_by = :updated_by, updated_at = NOW() 
         WHERE equipment_id = :child_id"
        );
        $stmt->execute([
            ':main_id' => $equipmentId,
            ':updated_by' => $updatedBy,
            ':child_id' => $childId
        ]);

        // log การเปลี่ยนแปลง
        $log->insertLog($userId, "equipments", "UPDATE",
            ["equipment_id" => $childId, "main_equipments" => $oldParent],
            ["equipment_id" => $childId, "main_equipments" => $equipmentId]
        ); 
    }
}

/**
 * จัดการการผูกอะไหล่
 */
function manageSpareParts($dbh, $log, $equipmentId, $oldSpares, $newSpares, $updatedBy, $userId) {
    $newSpares = is_array($newSpares) ? $newSpares : [];

    $removedSpares = array_diff($oldSpares, $newSpares);
    $addedSpares = array_diff($newSpares, $oldSpares);

    // ลบความสัมพันธ์เก่า
    foreach ($removedSpares as $spareId) {
        $stmt = $dbh->prepare(
            "UPDATE spare_parts SET equipment_id = NULL, 
             updated_by = :updated_by, updated_at = NOW() 
             WHERE spare_part_id = :sid"
        );
        $stmt->execute([':updated_by' => $updatedBy, ':sid' => $spareId]);

                $log->insertLog($userId, "spare_parts", "UPDATE",
            ["spare_part_id" => $spareId, "equipment_id" => $equipmentId],
            ["spare_part_id" => $spareId, "equipment_id" => null]
        );
    }

    // เพิ่มความสัมพันธ์ใหม่
    foreach ($addedSpares as $spareId) {
        $stmtOld = $dbh->prepare(
            "SELECT equipment_id FROM spare_parts WHERE spare_part_id = :sid"
        );
        $stmtOld->execute([':sid' => $spareId]);
        $oldEquipId = $stmtOld->fetchColumn();

        $stmt = $dbh->prepare(
            "UPDATE spare_parts SET equipment_id = :main_id, 
             updated_by = :updated_by, updated_at = NOW() 
             WHERE spare_part_id = :sid"
        );
        $stmt->execute([
            ':main_id' => $equipmentId,
            ':updated_by' => $updatedBy,
            ':sid' => $spareId
        ]);

                $log->insertLog($userId, "spare_parts", "UPDATE",
            ["spare_part_id" => $spareId, "equipment_id" => $oldEquipId],
            ["spare_part_id" => $spareId, "equipment_id" => $equipmentId]
        );
    }
}

// ==================== Main Process ====================

// ตรวจสอบ HTTP Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST only"]);
    exit;
}

// ตรวจสอบ Parameters
$equipmentId = $_POST['equipment_id'] ?? null;
if (!$equipmentId) {
    echo json_encode(["status" => "error", "message" => "equipment_id required"]);
    exit;
}

$updatedBy = $_POST['updated_by'] ?? ($decoded->user_id ?? null);
if (!$updatedBy) {
    echo json_encode(["status" => "error", "message" => "updated_by required"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $userId = $decoded->data->ID ?? null;

    if (!$userId) {
        throw new Exception("User ID not found");
    }

    // ดึงข้อมูลเก่า
    $oldEquipment = getOldEquipmentData($dbh, $equipmentId);
    $oldChilds = getChildEquipments($dbh, $equipmentId);
    $oldSpares = getSpareParts($dbh, $equipmentId);

    // กำหนด Fields ที่สามารถอัปเดตได้
     $fields = [
        'name', 'asset_code', 'serial_number', 'brand', 'model',
        'import_type_id', 'subcategory_id', 'location_department_id',
        'manufacturer_company_id', 'supplier_company_id', 'maintainer_company_id',
        'user_id', 'status', 'record_status', 'details', 'first_register',
        'location_details', 'spec', 'production_year', 'price', 'contract',
        'start_date', 'end_date', 'warranty_condition'
    ];

    // สร้าง Update Parameters
    list($setParts, $params, $oldData, $newData) = buildUpdateParams(
                $fields, $_POST, $oldEquipment, $equipmentId
    );

    // คำนวณ Warranty Duration
    $warrantyDays = calculateWarrantyDays(
        $_POST['start_date'] ?? null,
        $_POST['end_date'] ?? null
    );

    $setParts[] = "warranty_duration_days = :warranty_duration_days";
    $params[':warranty_duration_days'] = $warrantyDays;

    $oldWarranty = $oldEquipment['warranty_duration_days'] ?? null;
    if ($oldWarranty != $warrantyDays) {
        $oldData['warranty_duration_days'] = $oldWarranty;
        $newData['warranty_duration_days'] = $warrantyDays;
    }

    // ตรวจสอบ Asset Code ซ้ำ
    validateAssetCode(
        $dbh,
        $_POST['asset_code'] ?? null,
        $equipmentId,
        $oldEquipment['asset_code'] ?? null
    );

    // อัปเดตข้อมูลหลัก
    updateMainEquipment($dbh, $equipmentId, $setParts, $params, $updatedBy);

    // จัดการอุปกรณ์ย่อย
    $newChilds = !empty($_POST['child_equipments'])
        ? json_decode($_POST['child_equipments'], true)
        : [];
    manageChildEquipments($dbh, $log, $equipmentId, $oldChilds, $newChilds, $updatedBy, $userId);

    // จัดการอะไหล่
    $newSpares = !empty($_POST['spare_parts'])
        ? json_decode($_POST['spare_parts'], true)
        : [];
    manageSpareParts($dbh, $log, $equipmentId, $oldSpares, $newSpares, $updatedBy, $userId);

    // บันทึก Log สำหรับอุปกรณ์หลัก (ถ้ามีการเปลี่ยนแปลง)
    if ($oldData !== $newData && count($oldData) > 1) {
        $log->insertLog($userId, "equipments", "UPDATE", $oldData, $newData);
    }

    $dbh->commit();
    echo json_encode([
        "status" => "success",
        "message" => "Update successfully",
        "equipment_id" => $equipmentId
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}