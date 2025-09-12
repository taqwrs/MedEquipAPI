<?php
include "../config/jwt.php";

// กำหนด path สำหรับบันทึกไฟล์ที่อัปโหลด
// **สำคัญ:** แก้ไข path นี้ให้ตรงกับ server ของคุณ
$uploadDirectory = 'C:\xampp\htdocs\back_equip\uploads';
// กำหนด URL สำหรับไฟล์ที่บันทึก
$baseUrl = 'http://localhost/back_equip/uploads/';

// ตรวจสอบว่า request ที่ส่งมาเป็น JSON หรือไม่
// ถ้าเป็น JSON จะใช้ $input แต่ถ้ามีการอัปโหลดไฟล์จะใช้ $_POST และ $_FILES
$isJsonRequest = (strpos(strtolower(getenv("CONTENT_TYPE")), 'application/json') !== false);

if ($isJsonRequest) {
    $input = json_decode(file_get_contents('php://input'), true); // <- ต้องมี true
} else {
    $input = $_POST;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

// ตรวจสอบ field ที่จำเป็น
$requiredFields = ['name', 'asset_code'];
foreach ($requiredFields as $f) {
    if (empty($input[$f])) {
        echo json_encode(["status" => "error", "message" => "Missing field: $f"]);
        exit;
    }
}

try {
    $dbh->beginTransaction();
    $userId = $decoded->user_id ?? null;

    // ตรวจสอบ foreign key
    $fkChecks = [
        'equipment_id' => ['equipments', 'equipment_id'],
        'manufacturer_company_id' => ['companies', 'company_id'],
        'supplier_company_id' => ['companies', 'company_id'],
        'maintainer_company_id' => ['companies', 'company_id'],
        'spare_subcate_id' => ['spare_subcategories', 'spare_subcategory_id'],
        'import_type_id' => ['import_types', 'import_type_id'],
        'location_department_id' => ['departments', 'department_id'],
    ];

    foreach ($fkChecks as $field => $check) {
        $table = $check[0];
        $pk = $check[1];
        if (isset($input[$field]) && !is_null($input[$field])) {
            $stmt = $dbh->prepare("SELECT COUNT(*) FROM `$table` WHERE `$pk`=:val");
            $stmt->execute([':val' => $input[$field]]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception("Invalid FK value for $field");
            }
        }
    }

    // คำนวณ warranty_duration_days
    if (!empty($input['start_date']) && !empty($input['end_date'])) {
        $start_date = new DateTime($input['start_date']);
        $end_date = new DateTime($input['end_date']);
        $interval = $start_date->diff($end_date);
        $input['warranty_duration_days'] = $interval->days;
    } else {
        $input['warranty_duration_days'] = null;
    }

    // ตรวจ record_status
    $validStatuses = ['draft', 'complete'];
    if (empty($input['record_status'])) {
        $input['record_status'] = 'complete'; // default
    } elseif (!in_array($input['record_status'], $validStatuses)) {
        throw new Exception("Invalid record_status, allowed values: draft, complete");
    }

    // สร้าง array ของ field ทั้งหมด
    // ----------------- ARRAY ของฟิลด์ทั้งหมด -----------------
    $allFields = [
        'name',
        'brand',
        'asset_code',
        'model',
        'serial_number',
        'spec',
        'import_type_id',
        'spare_subcate_id',
        'location_department_id',
        'location_details',
        'production_year',
        'price',
        'contract',
        'start_date',
        'end_date',
        'warranty_duration_days',
        'warranty_condition',
        'maintainer_company_id',
        'supplier_company_id',
        'manufacturer_company_id',
        'record_status',
        'status',
        'active',
        'user_id',
        'updated_by',
        // 👇 เพิ่ม first_register
        'first_register'
    ];

    $cols = [];
    $placeholders = [];
    $values = [];

    foreach ($allFields as $f) {
        if ($f === 'first_register') {
            $cols[] = $f;
            $placeholders[] = 'NOW()';
        } elseif ($f === 'active' && !isset($input['active'])) {
            $cols[] = $f;
            $placeholders[] = ":$f";
            $values[":$f"] = 1;
        } elseif ($f === 'status' && !isset($input['status'])) {
            $cols[] = $f;
            $placeholders[] = ":$f";
            $values[":$f"] = 'ใช้งาน';
        } elseif ($f === 'user_id' || $f === 'updated_by') {
            $cols[] = $f;
            $placeholders[] = ":$f";
            // ใช้ค่าจาก payload ก่อน fallback ไป JWT
            $values[":$f"] = $input[$f] ?? $userId;
        } else {
            $cols[] = $f;
            $placeholders[] = ":$f";
            $values[":$f"] = $input[$f] ?? null;
        }
    }

    // 👇 updated_at ก็ให้ NOW() เช่นเดิม
    $cols[] = 'updated_at';
    $placeholders[] = 'NOW()';

    $sql = "INSERT INTO spare_parts (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $dbh->prepare($sql);

    foreach ($values as $k => $v) {
        $stmt->bindValue($k, $v);
    }

    $stmt->execute();
    $sparePartId = $dbh->lastInsertId();

    // บันทึกไฟล์ที่เกี่ยวข้อง
    $fileTypes = [
        'contractFiles' => 'เอกสารสัญญา',
        'warrantyFiles' => 'เอกสาร Warranty',
        'manualFiles' => 'คู่มือ',
        'spareImages' => 'รูปภาพอะไหล่',
    ];

    foreach ($fileTypes as $payloadKey => $dbTypeName) {
        // กรณีมีการอัปโหลดไฟล์จริงจากเครื่อง
        if (isset($_FILES[$payloadKey])) {
            foreach ($_FILES[$payloadKey]['name'] as $index => $fileName) {
                if ($_FILES[$payloadKey]['error'][$index] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES[$payloadKey]['tmp_name'][$index];
                    $newFileName = uniqid() . '-' . basename($fileName);
                    $destination = $uploadDirectory . $newFileName;

                    if (move_uploaded_file($tmpName, $destination)) {
                        $fileUrl = $baseUrl . $newFileName;
                        $stmt = $dbh->prepare("INSERT INTO file_spare (spare_part_id, file_spare_name, spare_url, spare_type_name) VALUES (:spare_id, :file_name, :spare_url, :type_name)");
                        $stmt->execute([
                            ':spare_id' => $sparePartId,
                            ':file_name' => $fileName,
                            ':spare_url' => $fileUrl,
                            ':type_name' => $dbTypeName
                        ]);
                    }
                }
            }
        }
    }

    // กรณีเป็นการส่ง URL จากภายนอก (ผ่าน JSON Payload)
    if (isset($input['files']) && is_array($input['files'])) {
        $fileSql = "INSERT INTO file_spare (spare_part_id, spare_url, spare_type_name, file_spare_name) VALUES (:spare_part_id, :spare_url, :spare_type_name, :file_spare_name)";
        $fileStmt = $dbh->prepare($fileSql);
        foreach ($input['files'] as $file) {
            $fileStmt->bindValue(':spare_part_id', $sparePartId);
            $fileStmt->bindValue(':spare_url', $file['spare_url'] ?? '');
            $fileStmt->bindValue(':spare_type_name', $file['spare_type_name'] ?? '');
            $fileStmt->bindValue(':file_spare_name', $file['file_spare_name'] ?? '');
            $fileStmt->execute();
        }
    }

    $dbh->commit();
    echo json_encode([
        "status" => "success",
        "message" => "Spare part created successfully.",
        "id" => $sparePartId,
        "record_status" => $input['record_status']
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>