<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "post method!!!"]);
    exit;
}

try {
    // ตรวจสอบ Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            "status" => "error",
            "message" => "Method not allowed, please use POST"
        ]);
        exit;
    }

    // รับข้อมูลจาก JSON Body
    $input = json_decode(file_get_contents('php://input'), true);

    // ตรวจสอบข้อมูลที่จำเป็น
    if (empty($input['equipment_id'])) {
        echo json_encode([
            "status" => "error",
            "message" => "equipment_id is required"
        ]);
        exit;
    }

    $equipment_id = $input['equipment_id'];

    // ฟิลด์ที่อนุญาตให้แก้ไข
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
        'group_user_id',
        'group_responsible_id',
        'user_id',
        'status',
        'first_register'
    ];

    // สร้าง dynamic SET สำหรับ SQL
    $setParts = [];
    $params = [];

    foreach ($fields as $field) {
        if (isset($input[$field])) {
            $setParts[] = "$field = :$field";
            $params[":$field"] = $input[$field];
        }
    }

    if (empty($setParts)) {
        echo json_encode([
            "status" => "error",
            "message" => "No valid fields provided for update"
        ]);
        exit;
    }

    // เพิ่ม updated_by และ updated_at อัตโนมัติ
    $setParts[] = "updated_by = :updated_by";
    $setParts[] = "updated_at = NOW()";
    $params[":updated_by"] = $input['updated_by'] ?? 1; // default admin = 1

    // สร้าง SQL
    $sql = "UPDATE equipments SET " . implode(', ', $setParts) . " WHERE equipment_id = :equipment_id";
    $params[':equipment_id'] = $equipment_id;

    $stmt = $dbh->prepare($sql);

    if ($stmt->execute($params)) {
        echo json_encode([
            "status" => "success",
            "message" => "Update successful",
            "updated_id" => $equipment_id
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Failed to update data"
        ]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
