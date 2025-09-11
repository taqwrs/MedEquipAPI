<?php
include "../config/jwt.php";

// path สำหรับบันทึกไฟล์
$uploadDirectory = 'C:/xampp/htdocs/back_equip/uploads/';
$baseUrl = 'http://localhost/back_equip/uploads/'; 

$isJsonRequest = (strpos(strtolower(getenv("CONTENT_TYPE")), 'application/json') !== false);

if ($isJsonRequest) {
    $input = json_decode(file_get_contents('php://input'), true);
} else {
    $input = $_POST;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

// field จำเป็น
$requiredFields = ['name', 'asset_code', 'updated_by'];
foreach ($requiredFields as $f) {
    if (empty($input[$f])) {
        echo json_encode(["status" => "error", "message" => "Missing field: $f"]);
        exit;
    }
}

// ตรวจ foreign key
$fkChecks = [
    'manufacturer_company_id' => ['companies', 'company_id'],
    'supplier_company_id' => ['companies', 'company_id'],
    'maintainer_company_id' => ['companies', 'company_id'],
    'location_department_id' => ['departments', 'department_id'],
    'subcategory_id' => ['equipment_subcategories', 'subcategory_id'],
    'import_type_id' => ['import_types', 'import_type_id']
];

try {
    $dbh->beginTransaction();

    foreach ($fkChecks as $field => $check) {
        $table = $check[0];
        $pk = $check[1];
        if (isset($input[$field])) {
            $stmt = $dbh->prepare("SELECT COUNT(*) FROM `$table` WHERE `$pk`=:val");
            $stmt->execute([':val' => $input[$field]]);
            if ($stmt->fetchColumn() == 0)
                throw new Exception("Invalid FK value for $field");
        }
    }

    // warranty_duration_days
    if (!empty($input['start_date']) && !empty($input['end_date'])) {
        $start_date = new DateTime($input['start_date']);
        $end_date = new DateTime($input['end_date']);
        $interval = $start_date->diff($end_date);
        $input['warranty_duration_days'] = $interval->days;
    } else {
        $input['warranty_duration_days'] = null;
    }

    // record_status
    $validStatuses = ['draft', 'complete'];
    if (empty($input['record_status'])) {
        $input['record_status'] = 'complete'; 
    } elseif (!in_array($input['record_status'], $validStatuses)) {
        throw new Exception("Invalid record_status, allowed values: draft, complete");
    }

    $allFields = [
        'name','brand','asset_code','model','serial_number',
        'import_type_id','subcategory_id','location_department_id','location_details',
        'manufacturer_company_id','supplier_company_id','maintainer_company_id',
        'spec','production_year','price','contract','start_date','end_date',
        'warranty_duration_days','warranty_condition','user_id','updated_by',
        'record_status','status','active','first_register'
    ];

    $cols = [];
    $placeholders = [];
    $values = [];
    foreach ($allFields as $f) {
        $cols[] = $f;
        $placeholders[] = ":$f";
        if ($f === 'active' && !isset($input['active'])) {
            $values[":$f"] = 1;
        } else {
            $values[":$f"] = $input[$f] ?? null;
        }
    }

    $cols[] = 'updated_at'; 
    $placeholders[] = 'NOW()';
    $sql = "INSERT INTO equipments (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
    $stmt = $dbh->prepare($sql);
    foreach ($values as $k => $v)
        $stmt->bindValue($k, $v);
    $stmt->execute();
    $equipment_id = $dbh->lastInsertId();

    // Child Equipments
    if (!empty($input['child_equipments']) && is_array($input['child_equipments'])) {
        foreach ($input['child_equipments'] as $child) {
            if (!empty($child['equipment_id'])) {
                $stmt = $dbh->prepare("UPDATE equipments SET main_equipment_id = :main_id WHERE equipment_id = :child_id");
                $stmt->execute([':main_id' => $equipment_id, ':child_id' => $child['equipment_id']]);
            }
        }
    }

    // Spare Parts
    if (!empty($input['spare_parts']) && is_array($input['spare_parts'])) {
        foreach ($input['spare_parts'] as $spare) {
            if (!empty($spare['spare_part_id'])) {
                $stmt = $dbh->prepare("UPDATE spare_parts SET equipment_id = :main_id WHERE spare_part_id = :spare_id");
                $stmt->execute([':main_id' => $equipment_id, ':spare_id' => $spare['spare_part_id']]);
            }
        }
    }

    // ------------------ Upload Files ------------------
    $fileTypes = [
        'contractFiles' => 'เอกสารสัญญา',
        'warrantyFiles' => 'เอกสาร Warranty',
        'manualFiles'   => 'คู่มือ',
        'deviceImages'  => 'รูปภาพเครื่อง',
    ];

    foreach ($fileTypes as $fieldKey => $typeName) {
        if (isset($_FILES[$fieldKey])) {
            // multiple files
            $fileArray = $_FILES[$fieldKey];
            for ($i = 0; $i < count($fileArray['name']); $i++) {
                if ($fileArray['error'][$i] === UPLOAD_ERR_OK) {
                    $originalName = basename($fileArray['name'][$i]);
                    $newFileName  = uniqid() . '-' . $originalName;
                    $destination  = $uploadDirectory . $newFileName;

                    if (move_uploaded_file($fileArray['tmp_name'][$i], $destination)) {
                        $fileUrl = $baseUrl . $newFileName;
                        $stmt = $dbh->prepare("INSERT INTO file_equip (file_equip_name, equipment_id, equip_url, equip_type_name, upload_at) VALUES (:file_name, :eq_id, :equip_url, :type_name, NOW())");
                        $stmt->execute([
                            ':file_name' => $originalName,
                            ':eq_id'     => $equipment_id,
                            ':equip_url' => $fileUrl,
                            ':type_name' => $typeName
                        ]);
                    }
                }
            }
        }
    }

    // ------------------ URL จากภายนอก ------------------
    if (!empty($input['deviceImageUrls']) && is_array($input['deviceImageUrls'])) {
        foreach ($input['deviceImageUrls'] as $url) {
            if (!empty($url)) {
                $stmt = $dbh->prepare("INSERT INTO file_equip (file_equip_name, equipment_id, equip_url, equip_type_name, upload_at) VALUES (:file_name, :eq_id, :equip_url, :type_name, NOW())");
                $stmt->execute([
                    ':file_name' => basename($url),
                    ':eq_id'     => $equipment_id,
                    ':equip_url' => $url,
                    ':type_name' => 'รูปภาพเครื่อง'
                ]);
            }
        }
    }

    $dbh->commit();
    echo json_encode([
        "status" => "success",
        "message" => "Insert successfully",
        "equipment_id" => $equipment_id,
        "record_status" => $input['record_status']
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
