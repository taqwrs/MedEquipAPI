<?php
include "../config/jwt.php";
include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST only"]);
    exit;
}

$equipment_id = $_POST['equipment_id'] ?? null;
if (!$equipment_id) {
    echo json_encode(["status" => "error", "message" => "equipment_id required"]);
    exit;
}

$updated_by = $_POST['updated_by'] ?? ($decoded->user_id ?? null);
if (!$updated_by) {
    echo json_encode(["status" => "error", "message" => "updated_by required"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id)
        throw new Exception("User ID not found");

    // ------------------- ดึงข้อมูลเก่า -------------------
    $stmt = $dbh->prepare("SELECT * FROM equipments WHERE equipment_id=:id");
    $stmt->execute([':id' => $equipment_id]);
    $oldEquipment = $stmt->fetch(PDO::FETCH_ASSOC);

    // อุปกรณ์ย่อยที่เคยผูกกับอุปกรณ์นี้
    $stmt = $dbh->prepare("SELECT equipment_id FROM equipments WHERE main_equipment_id = :main_id");
    $stmt->execute([':main_id' => $equipment_id]);
    $oldChilds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // อะไหล่ที่เคยผูกกับอุปกรณ์นี้
    $stmt = $dbh->prepare("SELECT spare_part_id FROM spare_parts WHERE equipment_id = :main_id");
    $stmt->execute([':main_id' => $equipment_id]);
    $oldSpares = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // ------------------- Update ข้อมูลหลักของอุปกรณ์ -------------------
    $fields = [
        'name',
        'asset_code',
        'serial_number',
        'brand',
        'model',
        'import_type_id',
        'subcategory_id',
        'location_department_id',
        'manufacturer_company_id',
        'supplier_company_id',
        'maintainer_company_id',
        'user_id',
        'status',
        'record_status',
        'details',
        'first_register',
        'location_details',
        'spec',
        'production_year',
        'price',
        'contract',
        'start_date',
        'end_date',
        'warranty_condition'
    ];

    $setParts = [];
    $params = [':equipment_id' => $equipment_id];
    $old_data = ['equipment_id' => $equipment_id];
    $new_data = ['equipment_id' => $equipment_id];

    foreach ($fields as $f) {
        $newValue = array_key_exists($f, $_POST) ? $_POST[$f] : null;

        // เปลี่ยนสถานะจาก draft เป็น complete
        if ($f === 'record_status' && $oldEquipment['record_status'] === 'draft') {
            $newValue = 'complete';
        }

        $setParts[] = "$f = :$f";
        $params[":$f"] = $newValue;

        $oldVal = $oldEquipment[$f] ?? null;
        if ($oldVal != $newValue) {
            $old_data[$f] = $oldVal;
            $new_data[$f] = $newValue;
        }
    }

    // ----------- คำนวณ warranty_duration_days ----------- 
    if (!empty($_POST['start_date']) && !empty($_POST['end_date'])) {
        $start = new DateTime($_POST['start_date']);
        $end = new DateTime($_POST['end_date']);
        if ($end < $start) {
            throw new Exception("วันที่สิ้นสุดสัญญาต้องไม่น้อยกว่าวันที่เริ่มต้น");
        }
        $warranty_days = $start->diff($end)->days;
    } else {
        $warranty_days = null;
    }

    $setParts[] = "warranty_duration_days = :warranty_duration_days";
    $params[':warranty_duration_days'] = $warranty_days;

    $oldVal = $oldEquipment['warranty_duration_days'] ?? null;
    if ($oldVal != $warranty_days) {
        $old_data['warranty_duration_days'] = $oldVal;
        $new_data['warranty_duration_days'] = $warranty_days;
    }

    // ตรวจ asset_code ซ้ำ
    if (isset($_POST['asset_code']) && $_POST['asset_code'] !== '') {
        $newAsset = $_POST['asset_code'];
        $currentAsset = $oldEquipment['asset_code'] ?? null;
        if ($newAsset !== $currentAsset) {
            $stmtCheck = $dbh->prepare("SELECT COUNT(*) as cnt FROM equipments WHERE asset_code = :asset AND equipment_id != :id");
            $stmtCheck->execute([':asset' => $newAsset, ':id' => $equipment_id]);
            $exist = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($exist && $exist['cnt'] > 0) {
                throw new Exception("รหัสทรัพย์สินนี้มีอยู่แล้ว: " . $newAsset);
            }
        }
    }

    // อัปเดตข้อมูลหลัก
    if (!empty($setParts)) {
        $setParts[] = "updated_by=:updated_by";
        $setParts[] = "updated_at=NOW()";
        $params[':updated_by'] = $updated_by;

        $sql = "UPDATE equipments SET " . implode(',', $setParts) . " WHERE equipment_id=:equipment_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    // ------------------- จัดการการผูกอุปกรณ์ย่อย -------------------
    $childs = !empty($_POST['child_equipments']) ? json_decode($_POST['child_equipments'], true) : [];
    $childs = is_array($childs) ? $childs : [];

    $removedChilds = array_diff($oldChilds, $childs);
    $addedChilds = array_diff($childs, $oldChilds);

    // ลบความสัมพันธ์เก่า
    foreach ($removedChilds as $cid) {
        $stmt = $dbh->prepare("UPDATE equipments SET main_equipment_id=NULL, updated_by=:updated_by, updated_at=NOW() WHERE equipment_id=:cid");
        $stmt->execute([':updated_by' => $updated_by, ':cid' => $cid]);

        // Log ตอนลบความสัมพันธ์ (main_equipment_id ถูกลบ)
        $old_data_child = [
            "equipment_id" => $cid,
            "main_equipments" => $equipment_id // ก่อนลบ เคยมี main
        ];
        $new_data_child = [
            "equipment_id" => $cid,
            "main_equipments" => null // หลังลบ กลายเป็น null
        ];
        $log->insertLog($user_id, "equipments", "UPDATE", $old_data_child, $new_data_child);
    }

    // เพิ่มความสัมพันธ์ใหม่
    foreach ($addedChilds as $cid) {
        if ($cid == $equipment_id)
            throw new Exception("อุปกรณ์หลักไม่สามารถเป็นอุปกรณ์ย่อยของตัวเองได้");

        $stmt = $dbh->prepare("UPDATE equipments SET main_equipment_id=:main_id, updated_by=:updated_by, updated_at=NOW() WHERE equipment_id=:child_id");
        $stmt->execute([':main_id' => $equipment_id, ':updated_by' => $updated_by, ':child_id' => $cid]);

        // Log การเพิ่มความสัมพันธ์ใหม่
        $old_data_child = [
            "equipment_id" => $cid,
            "main_equipments" => null // เดิมยังไม่มี main
        ];
        $new_data_child = [
            "equipment_id" => $cid,
            "main_equipments" => $equipment_id // ใหม่ถูกผูกกับ main
        ];
        $log->insertLog($user_id, "equipments", "UPDATE", $old_data_child, $new_data_child);
    }

    // ------------------- จัดการการผูกอะไหล่ -------------------
    $spares = !empty($_POST['spare_parts']) ? json_decode($_POST['spare_parts'], true) : [];
    $spares = is_array($spares) ? $spares : [];

    $removedSpares = array_diff($oldSpares, $spares);
    $addedSpares = array_diff($spares, $oldSpares);

    // ลบความสัมพันธ์เก่า
    foreach ($removedSpares as $sid) {
        $stmt = $dbh->prepare("UPDATE spare_parts SET equipment_id=NULL, updated_by=:updated_by, updated_at=NOW() WHERE spare_part_id=:sid");
        $stmt->execute([':updated_by' => $updated_by, ':sid' => $sid]);

        // Log การถอดอะไหล่ทีละ ID
        $old_data_spare = [
            "spare_part_id" => $sid,
            "equipment_id" => $equipment_id
        ];
        $new_data_spare = [
            "spare_part_id" => $sid,
            "equipment_id" => null
        ];
        $log->insertLog($user_id, "spare_parts", "UPDATE", $old_data_spare, $new_data_spare);
    }

    // เพิ่มความสัมพันธ์ใหม่
    foreach ($addedSpares as $sid) {
        $stmt = $dbh->prepare("UPDATE spare_parts SET equipment_id=:main_id, updated_by=:updated_by, updated_at=NOW() WHERE spare_part_id=:sid");
        $stmt->execute([':main_id' => $equipment_id, ':updated_by' => $updated_by, ':sid' => $sid]);

        // Log การเพิ่มอะไหล่ทีละ ID
        $old_data_spare = [
            "spare_part_id" => $sid,
            "equipment_id" => null
        ];
        $new_data_spare = [
            "spare_part_id" => $sid,
            "equipment_id" => $equipment_id
        ];
        $log->insertLog($user_id, "spare_parts", "UPDATE", $old_data_spare, $new_data_spare);
    }

    // ------------------- Log สำหรับอุปกรณ์หลัก (ถ้ามีการเปลี่ยนแปลงทั่วไป) -------------------
    if ($old_data !== $new_data && count($old_data) > 1) {
        $log->insertLog($user_id, "equipments", "UPDATE", $old_data, $new_data);
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Update successfully", "equipment_id" => $equipment_id]);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
