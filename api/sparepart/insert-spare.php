<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ตรวจสอบ JWT Token และดึง user_id
if (!is_object($payload) || !isset($payload->user_id)) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized. Invalid or expired token."]);
    exit;
}
$userId = $payload->user_id;

$input = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["status" => "error", "message" => "Invalid POST request"]);
    exit;
}

try {
    $dbh->beginTransaction();

    // FK Check Optional - ตรวจสอบเฉพาะค่าที่ส่งมาจาก input และมีอยู่จริงในตาราง
    $fkChecks = [
        'equipment_id' => ['equipments', 'equipment_id'],
        'manufacturer_company_id' => ['companies', 'company_id'],
        'supplier_company_id' => ['companies', 'company_id'],
        'maintainer_company_id' => ['companies', 'company_id'],
        'spare_subcate_id' => ['spare_subcategories', 'spare_subcate_id'],
        'import_type_id' => ['import_types', 'import_type_id'],
        'location_department_id' => ['departments', 'department_id'],
    ];

    foreach ($fkChecks as $f => $check) {
        if (isset($input[$f]) && !is_null($input[$f])) {
            $table = $check[0];
            $pk = $check[1];
            $stmt = $dbh->prepare("SELECT COUNT(*) FROM `$table` WHERE `$pk`=:val");
            $stmt->execute([':val' => $input[$f]]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception("Invalid FK value for field: $f");
            }
        }
    }

    // คำสั่ง SQL ที่ถูกต้อง
    $sql = "INSERT INTO spare_parts (
        name, asset_code, import_type_id, spare_subcate_id, location_department_id,
        location_details, production_year, price, contract, start_date, end_date,
        warranty_condition, maintainer_company_id, supplier_company_id, manufacturer_company_id,
        record_status, status, active, user_id, updated_by
    ) VALUES (
        :name, :asset_code, :import_type_id, :spare_subcate_id, :location_department_id,
        :location_details, :production_year, :price, :contract, :start_date, :end_date,
        :warranty_condition, :maintainer_company_id, :supplier_company_id, :manufacturer_company_id,
        :record_status, :status, :active, :user_id, :updated_by
    )";

    $stmt = $dbh->prepare($sql);
    
    // สร้าง Array เฉพาะข้อมูลที่จะใช้ INSERT
    $bindParams = [
        ':name' => $input['name'] ?? null,
        ':asset_code' => $input['asset_code'] ?? null,
        ':import_type_id' => $input['import_type_id'] ?? null,
        ':spare_subcate_id' => $input['spare_subcate_id'] ?? null,
        ':location_department_id' => $input['location_department_id'] ?? null,
        ':location_details' => $input['location_details'] ?? null,
        ':production_year' => $input['production_year'] ?? null,
        ':price' => $input['price'] ?? null,
        ':contract' => $input['contract'] ?? null,
        ':start_date' => $input['start_date'] ?? null,
        ':end_date' => $input['end_date'] ?? null,
        ':warranty_condition' => $input['warranty_condition'] ?? null,
        ':maintainer_company_id' => $input['maintainer_company_id'] ?? null,
        ':supplier_company_id' => $input['supplier_company_id'] ?? null,
        ':manufacturer_company_id' => $input['manufacturer_company_id'] ?? null,
        ':record_status' => 'complete',
        ':status' => $input['status'] ?? 'in_use',
        ':active' => 1,
        ':user_id' => $userId, // ใช้ค่าจาก JWT
        ':updated_by' => $userId, // ใช้ค่าจาก JWT
    ];

    foreach ($bindParams as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    
    $stmt->execute();
    $sparePartId = $dbh->lastInsertId();

    if (!empty($input['files']) && is_array($input['files'])) {
        $fileSql = "INSERT INTO file_spare (spare_part_id, spare_url, spare_type_name, file_spare_name) VALUES (:spare_part_id, :spare_url, :spare_type_name, :file_spare_name)";
        $fileStmt = $dbh->prepare($fileSql);
        foreach ($input['files'] as $file) {
            $fileStmt->bindValue(':spare_part_id', $sparePartId);
            $fileStmt->bindValue(':spare_url', $file['spare_url']);
            $fileStmt->bindValue(':spare_type_name', $file['spare_type_name']);
            $fileStmt->bindValue(':file_spare_name', $file['file_spare_name'] ?? '');
            $fileStmt->execute();
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Spare part created successfully.", "id" => $sparePartId]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>