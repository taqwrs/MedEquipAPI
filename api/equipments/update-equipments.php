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
    $childs = !empty($_POST['child_equipments']) ? json_decode($_POST['child_equipments'], true) : [];
    if (!is_array($childs))
        $childs = [];
    foreach ($childs as $child_id) {
        $sql = "UPDATE equipments 
            SET main_equipment_id=:main_id, updated_by=:updated_by, updated_at=NOW() 
            WHERE equipment_id=:child_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([
            ':main_id' => $equipment_id,
            ':updated_by' => $updated_by,
            ':child_id' => $child_id
        ]);
    }

    // --- Spare Parts ---
    $spares = !empty($_POST['spare_parts']) ? json_decode($_POST['spare_parts'], true) : [];
    if (!is_array($spares))
        $spares = [];
    foreach ($spares as $spare_id) {
        $sql = "UPDATE spare_parts 
            SET equipment_id=:main_id, updated_by=:updated_by, updated_at=NOW() 
            WHERE spare_part_id=:spare_id";
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
                $stmt = $dbh->prepare("INSERT INTO file_equip 
                    (equipment_id,file_equip_name,equip_url,equip_type_name,upload_at) 
                    VALUES (:eid,:name,:url,:type,NOW())");
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

            $sql = "INSERT INTO file_equip 
                (file_equip_name,equip_url,equip_type_name,equipment_id,upload_at) 
                VALUES (:file_equip_name,:equip_url,:equip_type_name,:equipment_id,NOW())";
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
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
