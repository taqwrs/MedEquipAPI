<?php
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST only"]);
    exit;
}

$equipment_id = $_POST['equipment_id'] ?? null;
if (empty($equipment_id)) {
    echo json_encode(["status" => "error", "message" => "equipment_id required"]);
    exit;
}

$updated_by = $_POST['updated_by'] ?? ($decoded->user_id ?? null);
if (empty($updated_by)) {
    echo json_encode(["status" => "error", "message" => "updated_by required"]);
    exit;
}

try {
    $dbh->beginTransaction();

    // Update Equipment
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
        'first_register',
        'location_details',
        'spec',
        'production_year',
        'price',
        'contract',
        'start_date',
        'end_date',
        'warranty_duration_days',
        'warranty_condition',
        'updated_by'
    ];
    $setParts = [];
    $params = [':equipment_id' => $equipment_id];
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $setParts[] = "$f=:$f";
            $params[":$f"] = $_POST[$f];
        }
    }
    $setParts[] = "updated_at=NOW()";

    if (!empty($setParts)) {
        $sql = "UPDATE equipments SET " . implode(',', $setParts) . " WHERE equipment_id=:equipment_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    // --- Child Equipments ---
    // รับค่า childs จาก POST และตรวจสอบว่าเป็น array
    $childs = !empty($_POST['child_equipments']) ? json_decode($_POST['child_equipments'], true) : [];
    if (!is_array($childs)) {
        $childs = [];
    }

    // ดึงรายการอุปกรณ์ย่อยเดิมของอุปกรณ์หลักนี้
    $stmt = $dbh->prepare("SELECT equipment_id FROM equipments WHERE main_equipment_id = :main_id");
    $stmt->execute([':main_id' => $equipment_id]);
    $old_child_equipments = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // อัปเดตอุปกรณ์ย่อยที่ถูกนำออก: ตั้งค่า main_equipment_id เป็น NULL
    $removed_childs = array_diff($old_child_equipments, $childs);
    if (!empty($removed_childs)) {
        $placeholders = implode(',', array_fill(0, count($removed_childs), '?'));
        $sql = "UPDATE equipments SET main_equipment_id = NULL WHERE equipment_id IN ($placeholders)";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($removed_childs);
    }

    // อัปเดตอุปกรณ์ย่อยที่ถูกเพิ่มหรือย้าย: ตั้งค่า main_equipment_id ใหม่
    $added_childs = array_diff($childs, $old_child_equipments);
    foreach ($added_childs as $child_id) {
        // เงื่อนไข: อุปกรณ์หลักจะไปเป็นอุปกรณ์ย่อยของตัวเองไม่ได้
        if ($child_id == $equipment_id) {
            throw new Exception("อุปกรณ์หลักไม่สามารถเป็นอุปกรณ์ย่อยของตัวเองได้");
        }
        
        // ล้างความสัมพันธ์เดิมของอุปกรณ์ย่อยก่อน
        $sql = "UPDATE equipments SET main_equipment_id = :main_id, updated_by = :updated_by, updated_at = NOW() WHERE equipment_id = :child_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([
            ':main_id' => $equipment_id,
            ':updated_by' => $updated_by,
            ':child_id' => $child_id
        ]);
    }
    
    // --- Spare Parts ---
    // รับค่า spares จาก POST และตรวจสอบว่าเป็น array
    $spares = !empty($_POST['spare_parts']) ? json_decode($_POST['spare_parts'], true) : [];
    if (!is_array($spares)) {
        $spares = [];
    }

    // ดึงรายการอะไหล่เดิมของอุปกรณ์หลักนี้
    $stmt = $dbh->prepare("SELECT spare_part_id FROM spare_parts WHERE equipment_id = :main_id");
    $stmt->execute([':main_id' => $equipment_id]);
    $old_spare_parts = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // อัปเดตอะไหล่ที่ถูกนำออก: ตั้งค่า equipment_id เป็น NULL
    $removed_spares = array_diff($old_spare_parts, $spares);
    if (!empty($removed_spares)) {
        $placeholders = implode(',', array_fill(0, count($removed_spares), '?'));
        $sql = "UPDATE spare_parts SET equipment_id = NULL WHERE spare_part_id IN ($placeholders)";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($removed_spares);
    }
    
    // อัปเดตอะไหล่ที่ถูกเพิ่มหรือย้าย: ตั้งค่า equipment_id ใหม่
    $added_spares = array_diff($spares, $old_spare_parts);
    foreach ($added_spares as $spare_id) {
        $sql = "UPDATE spare_parts SET equipment_id = :main_id, updated_by = :updated_by, updated_at = NOW() WHERE spare_part_id = :spare_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([
            ':main_id' => $equipment_id,
            ':updated_by' => $updated_by,
            ':spare_id' => $spare_id
        ]);
    }

    // --- Files ---
    if (!empty($_POST['filesInfo'])) {
        $files = json_decode($_POST['filesInfo'], true) ?: [];
        foreach ($files as $file) {
            if (!empty($file['equip_url']) && !empty($file['equip_type_name'])) {
                $stmt = $dbh->prepare("INSERT INTO file_equip (equipment_id,file_equip_name,equip_url,equip_type_name,upload_at) VALUES (:eid,:name,:url,:type,NOW())");
                $stmt->execute([
                    ':eid' => $equipment_id,
                    ':name' => $file['file_equip_name'] ?? basename($file['equip_url']),
                    ':url' => $file['equip_url'],
                    ':type' => $file['equip_type_name']
                ]);
            }
        }
    }

    // --- Upload new files ---
    if (!empty($_FILES['file_equip'])) {
        foreach ($_FILES['file_equip']['name'] as $index => $name) {
            $tmp_name = $_FILES['file_equip']['tmp_name'][$index];
            $typeName = $_POST['equip_type_name'][$index] ?? 'ไฟล์แนบ';
            $uploadDir = 'uploads/';
            $fileName = uniqid('file_') . '_' . basename($name);
            move_uploaded_file($tmp_name, $uploadDir . $fileName);

            $sql = "INSERT INTO file_equip (file_equip_name,equip_url,equip_type_name,equipment_id,upload_at) VALUES (:file_equip_name,:equip_url,:equip_type_name,:equipment_id,NOW())";
            $stmt = $dbh->prepare($sql);
            $stmt->execute([
                ':file_equip_name' => $name,
                ':equip_url' => $uploadDir . $fileName,
                ':equip_type_name' => $typeName,
                ':equipment_id' => $equipment_id
            ]);
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Update successfully", "equipment_id" => $equipment_id]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}