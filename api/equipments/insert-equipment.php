<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

// Required fields
$requiredFields = ['name', 'asset_code', 'updated_by'];
foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        echo json_encode(["status" => "error", "message" => "Missing field: $field"]);
        exit;
    }
}

try {
    $dbh->beginTransaction();

    // ----------- Insert equipment หลัก -----------  
    $allFields = [
        'name',
        'brand',
        'asset_code',
        'model',
        'serial_number',
        'import_type_id',
        'subcategory_id',
        'location_department_id',
        'location_details',
        'main_equipment_id',
        'manufacturer_company_id',
        'supplier_company_id',
        'maintainer_company_id',
        'spec',
        'production_year',
        'price',
        'contract',
        'start_date',
        'end_date',
        'warranty_duration_days',
        'warranty_condition',
        'group_user_id',
        'group_responsible_id',
        'user_id',
        'updated_by',
        'record_status',
        'status',
        'active',
        'first_register'
    ];

    $cols = [];
    $placeholders = [];
    $values = [];
    foreach ($allFields as $f) {
        $cols[] = $f;
        $placeholders[] = ":$f";
        $values[":$f"] = $input[$f] ?? null; // ใส่ค่า null หากไม่ได้ส่ง
    }

    $cols[] = 'updated_at';
    $placeholders[] = 'NOW()';

    $sql = "INSERT INTO equipments (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $dbh->prepare($sql);
    foreach ($values as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $equipmentId = $dbh->lastInsertId();

    // ----------- Insert child equipments -----------  
    if (!empty($input['child_equipments']) && is_array($input['child_equipments'])) {
        foreach ($input['child_equipments'] as $child) {
            $child['main_equipment_id'] = $equipmentId;
            $child['updated_by'] = $input['updated_by'];

            $childCols = [];
            $childPlaceholders = [];
            $childValues = [];
            foreach ($child as $k => $v) {
                $childCols[] = $k;
                $childPlaceholders[] = ":$k";
                $childValues[":$k"] = $v;
            }
            $childCols[] = 'updated_at';
            $childPlaceholders[] = 'NOW()';

            $childSql = "INSERT INTO equipments (" . implode(',', $childCols) . ") VALUES (" . implode(',', $childPlaceholders) . ")";
            $childStmt = $dbh->prepare($childSql);
            foreach ($childValues as $k => $v) $childStmt->bindValue($k, $v);
            $childStmt->execute();
        }
    }

    // ----------- Insert spare parts -----------  
    if (!empty($input['spare_parts']) && is_array($input['spare_parts'])) {
        foreach ($input['spare_parts'] as $spare) {
            $spare['equipment_id'] = $equipmentId;
            $spare['updated_by'] = $input['updated_by'];

            $cols = [];
            $placeholders = [];
            $values = [];
            foreach ($spare as $k => $v) {
                $cols[] = $k;
                $placeholders[] = ":$k";
                $values[":$k"] = $v;
            }
            $cols[] = 'updated_at';
            $placeholders[] = 'NOW()';

            $sql = "INSERT INTO spare_parts (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $dbh->prepare($sql);
            foreach ($values as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
        }
    }

    // ----------- Insert files -----------
    if (!empty($input['files']) && is_array($input['files'])) {
        foreach ($input['files'] as $file) {
            $file['equipment_id'] = $equipmentId;
            // Don't assign updated_by here if it's not needed in the database table

            $cols = [];
            $placeholders = [];
            $values = [];
            // Loop over the specific columns you want to insert
            foreach ($file as $k => $v) {
                $cols[] = $k;
                $placeholders[] = ":$k";
                $values[":$k"] = $v;
            }

            $cols[] = 'updated_at';
            $placeholders[] = 'NOW()';

            $sql = "INSERT INTO file_equip (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
            $stmt = $dbh->prepare($sql);
            foreach ($values as $k => $v) $stmt->bindValue($k, $v);
            $stmt->execute();
        }
    }

    $dbh->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Insert all data successfully",
        "equipment_id" => $equipmentId
    ]);
} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
